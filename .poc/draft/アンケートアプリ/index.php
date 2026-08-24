<?php
/*
========================================================================
GUARD COMMENT — 固定名称一覧
※以下の名称は、今後の修正・再生成時も変更・削除禁止。
※既存データとの互換性維持のため、名称・キー・属性・取り得る値を
  勝手に変更・削除しないこと。
※業務上の識別ルールとシステム内部制御ルールは分離すること。
========================================================================

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
アンケート・顧客データ識別ルール
========================================================================

【業務ルール】
- アンケートは、それぞれ独立した業務単位として管理する。
- 同一メールアドレス・同一顧客に対して複数のアンケートを実施できる。
- 同一タイトルのアンケートを複数作成できる。
- 同一顧客が複数のアンケートに回答しても、それぞれ別回答として扱う。
- アンケート複製は元アンケートとは別アンケートとして扱う。

【システム内部制御ルール】
- アンケートの一意識別子は survey.id とする。
- 回答の所属アンケートは response.survey_id で識別する。
- メールアドレス、顧客ID、タイトルをアンケートの一意キーにしない。
- アンケートの重複排除をメールアドレスで行わない。
- 同一メールアドレス・同一顧客が複数アンケートに存在しても削除・統合しない。
- 一覧・回答・送信履歴・集計は survey.id / survey_id 単位で分離する。
- アンケート複製時は必ず新しい survey.id を発行する。
- 顧客の同一性判定とアンケートの同一性判定を混同しない。
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

function survey_default_data(): array {
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

function survey_read_data(): array {
    if (!is_file(SURVEY_STORAGE_FILE)) {
        return survey_default_data();
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);
    $data = json_decode((string)$raw, true);

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

function survey_write_data(array $data): bool {
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
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

function survey_id(): string {
    return 'sv_' . bin2hex(random_bytes(12));
}

function customer_id(): string {
    return 'cu_' . bin2hex(random_bytes(12));
}

function response_id(): string {
    return 'rs_' . bin2hex(random_bytes(12));
}

function group_id(): string {
    return 'gr_' . bin2hex(random_bytes(8));
}

function question_id(): string {
    return 'qu_' . bin2hex(random_bytes(8));
}

function survey_now(): string {
    return date('Y-m-d H:i:s');
}

function survey_json_response(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function survey_post_json(string $key): mixed {
    if (!isset($_POST[$key])) {
        return null;
    }

    $value = $_POST[$key];

    if (is_string($value)) {
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    return $value;
}

function survey_csrf(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function survey_verify_csrf(): void {
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals(survey_csrf(), $token)) {
        survey_json_response([
            'ok' => false,
            'message' => 'CSRFトークンが無効です。ページを再読み込みしてください。'
        ], 403);
    }
}

function get_safe_response_headers(): array {
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
        return is_array($headers) ? $headers : [];
    }

    global $http_response_header;
    return isset($http_response_header) && is_array($http_response_header)
        ? $http_response_header
        : [];
}

function kintone_build_url(string $domain, string $endpoint): string {
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain);
    $domain = rtrim($domain, '/');
    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload = null,
    array $config = []
): array {
    $method = strtoupper($method);

    $http_options = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 15
    ];

    if ($method !== 'GET' && $payload !== null) {
        $http_options['content'] = is_array($payload)
            ? json_encode($payload, JSON_UNESCAPED_UNICODE)
            : (string)$payload;
    }

    $context_options = [
        'http' => $http_options,
        'ssl' => [
            'verify_peer' => !empty($config['ssl_verify']),
            'verify_peer_name' => !empty($config['ssl_verify'])
        ]
    ];

    $proxy_host_port = trim((string)($config['proxy_host_port'] ?? ''));

    if ($proxy_host_port !== '') {
        $context_options['http']['proxy'] = 'tcp://' . $proxy_host_port;
        $context_options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($context_options);
    $response_body = @file_get_contents($url, false, $context);
    $response_headers = get_safe_response_headers();

    $status_code = 500;

    foreach ($response_headers as $header) {
        if (preg_match('/HTTP\/\d(?:\.\d)?\s+(\d+)/i', $header, $m)) {
            $status_code = (int)$m[1];
        }
    }

    $result_data = json_decode((string)$response_body, true);

    if ($status_code >= 200 && $status_code < 300) {
        return [
            'success' => true,
            'status' => $status_code,
            'data' => is_array($result_data) ? $result_data : []
        ];
    }

    $message = is_array($result_data)
        ? (string)($result_data['message'] ?? 'kintone API 通信エラーが発生しました。')
        : 'kintone APIからJSON形式ではない応答が返されました。';

    return [
        'success' => false,
        'status' => $status_code,
        'message' => $message,
        'raw' => $result_data,
        'body' => mb_substr((string)$response_body, 0, 2000)
    ];
}

function make_cybozu_auth_header(string $login_name, string $password): string {
    return 'X-Cybozu-Authorization: ' .
        base64_encode(trim($login_name) . ':' . trim($password));
}

function kintone_config(array $settings): array {
    return [
        'proxy_host_port' => trim((string)($settings['proxy'] ?? '')),
        'ssl_verify' => !empty($settings['ssl_verify'])
    ];
}

function kintone_headers(array $settings): array {
    return [
        'X-Cybozu-Authorization: ' .
            base64_encode(
                trim((string)($settings['login_name'] ?? '')) .
                ':' .
                trim((string)($settings['password'] ?? ''))
            ),
        'Content-Type: application/json',
        'Accept: application/json'
    ];
}

function kintone_request_from_settings(
    string $method,
    string $endpoint,
    array $settings,
    mixed $payload = null
): array {
    $url = kintone_build_url(
        (string)($settings['subdomain'] ?? ''),
        $endpoint
    );

    return kintone_api_request(
        $method,
        $url,
        kintone_headers($settings),
        $payload,
        kintone_config($settings)
    );
}

function survey_sanitize_question(array $q): array {
    $type = (string)($q['type'] ?? 'single');

    if (!in_array($type, ['single', 'multiple', 'text'], true)) {
        $type = 'single';
    }

    $options = $q['options'] ?? [];

    if (!is_array($options)) {
        $options = [];
    }

    $options = array_values(array_map(
        static fn($v): string => trim((string)$v),
        $options
    ));

    return [
        'id' => (string)($q['id'] ?? question_id()),
        'text' => trim((string)($q['text'] ?? '')),
        'type' => $type,
        'required' => !empty($q['required']),
        'options' => $options,
        'other_enabled' => !empty($q['other_enabled'])
    ];
}

function survey_sanitize_group(array $group): array {
    $questions = $group['questions'] ?? [];

    if (!is_array($questions)) {
        $questions = [];
    }

    return [
        'id' => (string)($group['id'] ?? group_id()),
        'name' => trim((string)($group['name'] ?? '')),
        'questions' => array_values(array_map(
            'survey_sanitize_question',
            $questions
        ))
    ];
}

function survey_sanitize(array $s): array {
    $groups = $s['groups'] ?? [];

    if (!is_array($groups)) {
        $groups = [];
    }

    $status = (string)($s['status'] ?? 'draft');

    if (!in_array($status, ['draft', 'active', 'ended'], true)) {
        $status = 'draft';
    }

    $numbering = (string)($s['numbering_mode'] ?? 'global');

    if (!in_array($numbering, ['global', 'group'], true)) {
        $numbering = 'global';
    }

    return [
        'id' => (string)($s['id'] ?? survey_id()),
        'title' => trim((string)($s['title'] ?? '')),
        'start_at' => (string)($s['start_at'] ?? ''),
        'end_at' => (string)($s['end_at'] ?? ''),
        'status' => $status,
        'created_at' => (string)($s['created_at'] ?? survey_now()),
        'updated_at' => survey_now(),
        'numbering_mode' => $numbering,
        'groups' => array_values(array_map('survey_sanitize_group', $groups)),
        'deleted' => !empty($s['deleted'])
    ];
}

function survey_find_index(array $data, string $id): int {
    foreach ($data['surveys'] as $i => $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

function survey_find(array $data, string $id): ?array {
    $i = survey_find_index($data, $id);
    return $i >= 0 ? $data['surveys'][$i] : null;
}

function survey_customer_for_survey(
    array $data,
    string $surveyId
): array {
    $ids = [];

    foreach ($data['responses'] as $response) {
        if (($response['survey_id'] ?? '') === $surveyId) {
            $cid = (string)($response['customer_id'] ?? '');
            if ($cid !== '') {
                $ids[$cid] = true;
            }
        }
    }

    $result = [];

    foreach ($data['customers'] as $customer) {
        if (isset($ids[(string)($customer['id'] ?? '')])) {
            $result[] = $customer;
        }
    }

    return $result;
}

function survey_csv(array $data, string $surveyId): never {
    $survey = survey_find($data, $surveyId);

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

    $filename = 'survey_' . $surveyId . '_responses.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' .
        $filename . '"'
    );

    $fp = fopen('php://output', 'wb');

    fwrite($fp, "\xEF\xBB\xBF");

    $header = [
        '回答ID',
        '回答日時',
        '顧客ID',
        '会社名',
        '氏名'
    ];

    foreach ($questions as $i => $question) {
        $header[] = '設問' . ($i + 1) . ' ' . $question['text'];
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

        $answers = $response['answers'] ?? [];

        foreach ($questions as $question) {
            $answer = $answers[$question['id']] ?? '';

            if (is_array($answer)) {
                $answer = implode('、', $answer);
            }

            $row[] = (string)$answer;
        }

        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}


/* =====================================================================
   API
   ===================================================================== */

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action !== '') {
    $data = survey_read_data();

    if ($action === 'csrf') {
        survey_json_response([
            'ok' => true,
            'csrf_token' => survey_csrf()
        ]);
    }

    if ($action === 'data') {
        survey_json_response([
            'ok' => true,
            'data' => $data,
            'csrf_token' => survey_csrf()
        ]);
    }

    if ($action === 'save_survey') {
        survey_verify_csrf();

        $survey = survey_post_json('survey_json');

        if (!is_array($survey)) {
            survey_json_response([
                'ok' => false,
                'message' => 'survey_json が不正です。'
            ], 400);
        }

        $survey = survey_sanitize($survey);

        $index = survey_find_index($data, $survey['id']);

        if ($index >= 0) {
            $survey['created_at'] =
                $data['surveys'][$index]['created_at'] ?? $survey['created_at'];

            $data['surveys'][$index] = $survey;
        } else {
            $data['surveys'][] = $survey;
        }

        if (!survey_write_data($data)) {
            survey_json_response([
                'ok' => false,
                'message' => 'データ保存に失敗しました。'
            ], 500);
        }

        survey_json_response([
            'ok' => true,
            'survey' => $survey
        ]);
    }

    if ($action === 'delete_survey') {
        survey_verify_csrf();

        $id = (string)($_POST['survey_id'] ?? '');
        $index = survey_find_index($data, $id);

        if ($index < 0) {
            survey_json_response([
                'ok' => false,
                'message' => 'アンケートが見つかりません。'
            ], 404);
        }

        $data['surveys'][$index]['deleted'] = true;
        $data['surveys'][$index]['updated_at'] = survey_now();

        survey_write_data($data);

        survey_json_response(['ok' => true]);
    }

    if ($action === 'duplicate_survey') {
        survey_verify_csrf();

        $id = (string)($_POST['survey_id'] ?? '');
        $survey = survey_find($data, $id);

        if ($survey === null) {
            survey_json_response([
                'ok' => false,
                'message' => 'アンケートが見つかりません。'
            ], 404);
        }

        /*
         * 重要:
         * 複製時は必ず新しい survey.id を発行する。
         * 顧客・メールアドレス・タイトルでは重複判定しない。
         */
        $copy = $survey;
        $copy['id'] = survey_id();
        $copy['title'] = $survey['title'] . '（複製）';
        $copy['status'] = 'draft';
        $copy['created_at'] = survey_now();
        $copy['updated_at'] = survey_now();
        $copy['deleted'] = false;

        foreach ($copy['groups'] as &$group) {
            $group['id'] = group_id();

            foreach ($group['questions'] as &$question) {
                $question['id'] = question_id();
            }
            unset($question);
        }
        unset($group);

        $data['surveys'][] = $copy;
        survey_write_data($data);

        survey_json_response([
            'ok' => true,
            'survey' => $copy
        ]);
    }

    if ($action === 'save_settings') {
        survey_verify_csrf();

        $settings = survey_post_json('settings_json');

        if (!is_array($settings)) {
            survey_json_response([
                'ok' => false,
                'message' => 'settings_json が不正です。'
            ], 400);
        }

        $settings['subdomain'] =
            trim((string)($settings['subdomain'] ?? ''));

        $settings['login_name'] =
            trim((string)($settings['login_name'] ?? ''));

        $settings['password'] =
            (string)($settings['password'] ?? '');

        $settings['app_id'] =
            trim((string)($settings['app_id'] ?? ''));

        $settings['ssl_verify'] =
            !empty($settings['ssl_verify']);

        $settings['proxy'] =
            trim((string)($settings['proxy'] ?? ''));

        if (!isset($settings['field_address'])) {
            $settings['field_address'] = [];
        }

        $data['settings'] = array_merge(
            survey_default_data()['settings'],
            $settings
        );

        survey_write_data($data);

        survey_json_response([
            'ok' => true,
            'settings' => $data['settings']
        ]);
    }

    if ($action === 'kintone_test') {
        survey_verify_csrf();

        $settings = survey_post_json('settings_json');

        if (!is_array($settings)) {
            $settings = $data['settings'];
        }

        $result = kintone_request_from_settings(
            'GET',
            '/k/v1/app.json?id=' .
            rawurlencode((string)($settings['app_id'] ?? '')),
            $settings
        );

        if ($result['success']) {
            survey_json_response([
                'ok' => true,
                'message' => 'kintoneへの接続に成功しました。',
                'status' => $result['status'],
                'data' => $result['data']
            ]);
        }

        survey_json_response([
            'ok' => false,
            'message' => $result['message'] ??
                'kintone接続に失敗しました。',
            'status' => $result['status'] ?? 500,
            'raw' => $result['raw'] ?? null,
            'body' => $result['body'] ?? ''
        ], 400);
    }

    if ($action === 'kintone_fields') {
        survey_verify_csrf();

        $settings = survey_post_json('settings_json');

        if (!is_array($settings)) {
            $settings = $data['settings'];
        }

        $appId = trim((string)(
            $_POST['app_id'] ??
            $settings['app_id'] ??
            ''
        ));

        if ($appId === '') {
            survey_json_response([
                'ok' => false,
                'message' => 'アプリIDを入力してください。'
            ], 400);
        }

        $result = kintone_request_from_settings(
            'GET',
            '/k/v1/app/form/fields.json?id=' . rawurlencode($appId),
            $settings
        );

        if (!$result['success']) {
            survey_json_response([
                'ok' => false,
                'message' => $result['message'] ??
                    '項目一覧の取得に失敗しました。',
                'status' => $result['status'] ?? 500,
                'raw' => $result['raw'] ?? null,
                'body' => $result['body'] ?? ''
            ], 400);
        }

        $properties = $result['data']['properties'] ?? null;

        if (!is_array($properties)) {
            survey_json_response([
                'ok' => false,
                'message' =>
                    'kintoneからJSON応答は取得できましたが、properties がありません。',
                'raw' => $result['data']
            ], 400);
        }

        $fields = [];

        foreach ($properties as $code => $field) {
            if (!is_array($field)) {
                continue;
            }

            $fields[] = [
                'code' => (string)$code,
                'label' => (string)($field['label'] ?? $code),
                'type' => (string)($field['type'] ?? '')
            ];
        }

        survey_json_response([
            'ok' => true,
            'fields' => $fields
        ]);
    }

    if ($action === 'kintone_records') {
        survey_verify_csrf();

        $settings = $data['settings'];

        $query = (string)($_POST['query'] ?? '');
        $fields = $_POST['fields'] ?? [];

        if (!is_array($fields)) {
            $fields = [];
        }

        $payload = [
            'app' => (string)($settings['app_id'] ?? ''),
            'query' => $query
        ];

        if ($fields !== []) {
            $payload['fields'] = array_values($fields);
        }

        $result = kintone_request_from_settings(
            'GET',
            '/k/v1/records.json?' .
            http_build_query($payload),
            $settings
        );

        if (!$result['success']) {
            survey_json_response([
                'ok' => false,
                'message' => $result['message'] ?? '顧客取得に失敗しました。',
                'raw' => $result['raw'] ?? null
            ], 400);
        }

        survey_json_response([
            'ok' => true,
            'records' => $result['data']['records'] ?? []
        ]);
    }

    if ($action === 'sync_customers') {
        survey_verify_csrf();

        $settings = $data['settings'];
        $fields = [];

        foreach ([
            'field_company',
            'field_name',
            'field_email',
            'field_department',
            'field_phone'
        ] as $key) {
            if (!empty($settings[$key])) {
                $fields[] = $settings[$key];
            }
        }

        if (is_array($settings['field_address'] ?? null)) {
            foreach ($settings['field_address'] as $code) {
                if ($code !== '') {
                    $fields[] = $code;
                }
            }
        }

        $fields = array_values(array_unique($fields));

        $payload = [
            'app' => (string)$settings['app_id'],
            'query' => '',
            'totalCount' => false
        ];

        if ($fields) {
            $payload['fields'] = $fields;
        }

        $result = kintone_request_from_settings(
            'GET',
            '/k/v1/records.json?' .
            http_build_query($payload),
            $settings
        );

        if (!$result['success']) {
            survey_json_response([
                'ok' => false,
                'message' => $result['message'] ?? '顧客同期に失敗しました。',
                'raw' => $result['raw'] ?? null
            ], 400);
        }

        $records = $result['data']['records'] ?? [];
        $map = [];

        foreach ($data['customers'] as $customer) {
            $email = strtolower(trim((string)($customer['email'] ?? '')));

            if ($email !== '') {
                $map[$email] = $customer;
            }
        }

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $read = static function (
                array $record,
                string $code
            ): string {
                if ($code === '') {
                    return '';
                }

                $v = $record[$code]['value'] ?? '';

                if (is_array($v)) {
                    $values = [];

                    foreach ($v as $item) {
                        if (is_array($item) && isset($item['value'])) {
                            $values[] = (string)$item['value'];
                        } else {
                            $values[] = (string)$item;
                        }
                    }

                    return implode(' ', $values);
                }

                return (string)$v;
            };

            $email = trim($read(
                $record,
                (string)$settings['field_email']
            ));

            if ($email === '') {
                continue;
            }

            $key = strtolower($email);

            $customer = $map[$key] ?? [
                'id' => customer_id(),
                'company' => '',
                'name' => '',
                'email' => $email,
                'department' => '',
                'phone' => '',
                'address' => '',
                'source' => 'kintone',
                'sent_at' => '',
                'send_count' => 0,
                'answer_status' => 'unanswered',
                'kintone_status' => 'registered'
            ];

            $customer['company'] = $read(
                $record,
                (string)$settings['field_company']
            );
            $customer['name'] = $read(
                $record,
                (string)$settings['field_name']
            );
            $customer['department'] = $read(
                $record,
                (string)$settings['field_department']
            );
            $customer['phone'] = $read(
                $record,
                (string)$settings['field_phone']
            );

            $addressParts = [];

            foreach ((array)$settings['field_address'] as $code) {
                $v = $read($record, (string)$code);

                if ($v !== '') {
                    $addressParts[] = $v;
                }
            }

            $customer['address'] = implode(' ', $addressParts);
            $customer['email'] = $email;
            $customer['source'] = 'kintone';
            $customer['kintone_status'] = 'registered';

            $map[$key] = $customer;
        }

        $data['customers'] = array_values($map);
        survey_write_data($data);

        survey_json_response([
            'ok' => true,
            'customers' => $data['customers'],
            'count' => count($data['customers'])
        ]);
    }

    if ($action === 'send_mail') {
        survey_verify_csrf();

        $surveyId = (string)($_POST['survey_id'] ?? '');
        $recipientIds = $_POST['recipient_ids'] ?? [];

        if (!is_array($recipientIds)) {
            $recipientIds = [];
        }

        $subject = (string)($_POST['mail_subject'] ?? '');
        $body = (string)($_POST['mail_body'] ?? '');
        $template = (string)($_POST['template_type'] ?? 'initial');

        if (!in_array($template, ['initial', 'reminder'], true)) {
            $template = 'initial';
        }

        $count = 0;
        $now = survey_now();

        foreach ($data['customers'] as &$customer) {
            if (!in_array($customer['id'] ?? '', $recipientIds, true)) {
                continue;
            }

            $customer['sent_at'] = $now;
            $customer['send_count'] =
                (int)($customer['send_count'] ?? 0) + 1;
            $customer['answer_status'] =
                $customer['answer_status'] ?? 'unanswered';

            $count++;
        }
        unset($customer);

        $data['mail_logs'][] = [
            'id' => 'ml_' . bin2hex(random_bytes(10)),
            'survey_id' => $surveyId,
            'sent_at' => $now,
            'template_type' => $template,
            'count' => $count,
            'subject' => $subject,
            'body' => $body,
            'recipient_ids' => array_values($recipientIds)
        ];

        survey_write_data($data);

        survey_json_response([
            'ok' => true,
            'count' => $count,
            'message' => $count . '件の送信対象を記録しました。'
        ]);
    }

    if ($action === 'mark_kintone_registered') {
        survey_verify_csrf();

        $id = (string)($_POST['customer_id'] ?? '');

        foreach ($data['customers'] as &$customer) {
            if (($customer['id'] ?? '') === $id) {
                $customer['kintone_status'] = 'registered';
                break;
            }
        }
        unset($customer);

        survey_write_data($data);

        survey_json_response(['ok' => true]);
    }

    if ($action === 'csv') {
        survey_csv(
            $data,
            (string)($_GET['survey_id'] ?? '')
        );
    }

    survey_json_response([
        'ok' => false,
        'message' => '未知のactionです。'
    ], 400);
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

<script>
'use strict';

window.App = {
    state: {
        data: {
            surveys: [],
            responses: [],
            customers: [],
            settings: {},
            mail_logs: []
        },
        csrf_token: '',
        page: 'surveys',
        surveyId: '',
        keyword: '',
        status_filter: 'all',
        sort: 'updated_desc',
        editing: null,
        selectedQuestions: {},
        responseFilter: '',
        customerFilter: '',
        preview: false,
        responseModal: false,
        responseDetail: null,
        kintoneFields: []
    },

    api: {},

    render: {},

    actions: {},

    utils: {},

    init: async function() {
        if (window.App._initialized) return;
        window.App._initialized = true;

        await App.api.load();
        App.render.app();
    }
};

window.App.api.load = async function() {
    const response = await fetch('?action=data', {
        credentials: 'same-origin'
    });

    const json = await response.json();

    if (!json.ok) {
        alert(json.message || 'データ取得に失敗しました。');
        return;
    }

    App.state.data = json.data;
    App.state.csrf_token = json.csrf_token;
};

window.App.api.post = async function(action, payload = {}) {
    const form = new FormData();

    form.append('action', action);
    form.append('csrf_token', App.state.csrf_token);

    Object.keys(payload).forEach(function(key) {
        let value = payload[key];

        if (typeof value === 'object') {
            value = JSON.stringify(value);
        }

        form.append(key, value ?? '');
    });

    const response = await fetch('', {
        method: 'POST',
        body: form,
        credentials: 'same-origin'
    });

    const json = await response.json();

    if (!json.ok) {
        throw new Error(json.message || '処理に失敗しました。');
    }

    return json;
};

window.App.utils.escape = function(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
};

window.App.utils.id = function(prefix) {
    return prefix + '_' +
        Date.now().toString(36) +
        '_' +
        Math.random().toString(36).slice(2, 9);
};

window.App.utils.date = function(value) {
    if (!value) return '未設定';

    const d = new Date(value.replace(' ', 'T'));

    if (Number.isNaN(d.getTime())) return App.utils.escape(value);

    return d.toLocaleString('ja-JP');
};

window.App.utils.statusLabel = function(status) {
    return {
        active: '公開中',
        draft: '下書き',
        ended: '終了'
    }[status] || status;
};

window.App.utils.statusClass = function(status) {
    return {
        active: 'bg-emerald-100 text-emerald-700',
        draft: 'bg-slate-100 text-slate-600',
        ended: 'bg-amber-100 text-amber-700'
    }[status] || 'bg-slate-100 text-slate-600';
};

window.App.utils.allQuestions = function(survey) {
    const result = [];

    (survey?.groups || []).forEach(function(group) {
        (group.questions || []).forEach(function(question) {
            result.push({
                ...question,
                groupId: group.id
            });
        });
    });

    return result;
};

window.App.utils.answerCount = function(surveyId) {
    return App.state.data.responses.filter(function(r) {
        return r.survey_id === surveyId;
    }).length;
};

window.App.render.app = function() {
    const el = document.getElementById('app');

    el.innerHTML = `
        <div class="min-h-screen">
            ${App.render.header()}
            <main class="max-w-7xl mx-auto p-5">
                <div id="page-content"></div>
            </main>
        </div>
        ${App.render.modals()}
    `;

    App.render.page();
};

window.App.render.header = function() {
    return `
        <header class="bg-white border-b border-slate-200 sticky top-0 z-30">
            <div class="max-w-7xl mx-auto px-5 py-4 flex flex-wrap gap-3 items-center justify-between">
                <div>
                    <div class="text-xl font-bold text-slate-900">
                        アンケート管理
                    </div>
                    <div class="text-xs text-slate-400">
                        Survey Management System
                    </div>
                </div>

                <nav class="flex gap-2">
                    <button
                        class="px-4 py-2 rounded-lg text-sm font-medium
                               ${App.state.page === 'surveys'
                                   ? 'bg-indigo-600 text-white'
                                   : 'bg-slate-100 text-slate-700'}"
                        onclick="App.actions.go('surveys')">
                        アンケート一覧
                    </button>

                    <button
                        class="px-4 py-2 rounded-lg text-sm font-medium
                               ${App.state.page === 'settings'
                                   ? 'bg-indigo-600 text-white'
                                   : 'bg-slate-100 text-slate-700'}"
                        onclick="App.actions.go('settings')">
                        キントーン連携設定
                    </button>

                    <button
                        class="px-4 py-2 rounded-lg text-sm bg-slate-100
                               text-slate-700"
                        onclick="App.actions.logout()">
                        ログアウト
                    </button>
                </nav>
            </div>
        </header>
    `;
};

window.App.render.page = function() {
    const el = document.getElementById('page-content');

    if (!el) return;

    if (App.state.page === 'surveys') {
        el.innerHTML = App.render.surveyList();
        return;
    }

    if (App.state.page === 'edit') {
        el.innerHTML = App.render.editor();
        App.actions.initSortables();
        return;
    }

    if (App.state.page === 'aggregate') {
        el.innerHTML = App.render.aggregate();
        return;
    }

    if (App.state.page === 'mail') {
        el.innerHTML = App.render.mail();
        return;
    }

    if (App.state.page === 'settings') {
        el.innerHTML = App.render.settings();
    }
};

window.App.render.surveyList = function() {
    let surveys = App.state.data.surveys.filter(function(s) {
        return !s.deleted;
    });

    const keyword = App.state.keyword.toLowerCase();

    if (keyword) {
        surveys = surveys.filter(function(s) {
            return String(s.title || '').toLowerCase().includes(keyword);
        });
    }

    if (App.state.status_filter !== 'all') {
        surveys = surveys.filter(function(s) {
            return s.status === App.state.status_filter;
        });
    }

    surveys.sort(function(a, b) {
        if (App.state.sort === 'updated_desc') {
            return String(b.updated_at).localeCompare(String(a.updated_at));
        }

        if (App.state.sort === 'updated_asc') {
            return String(a.updated_at).localeCompare(String(b.updated_at));
        }

        if (App.state.sort === 'answers_desc') {
            return App.utils.answerCount(b.id) -
                App.utils.answerCount(a.id);
        }

        if (App.state.sort === 'answers_asc') {
            return App.utils.answerCount(a.id) -
                App.utils.answerCount(b.id);
        }

        if (App.state.sort === 'start_desc') {
            return String(b.start_at).localeCompare(String(a.start_at));
        }

        return String(a.start_at).localeCompare(String(b.start_at));
    });

    return `
        <div class="flex items-center justify-between gap-3 mb-5">
            <div>
                <h1 class="text-2xl font-bold">アンケート一覧</h1>
                <p class="text-sm text-slate-500 mt-1">
                    アンケート単位で独立して管理します。
                </p>
            </div>

            <button
                class="px-5 py-3 rounded-xl bg-indigo-600 text-white
                       font-semibold shadow-sm hover:bg-indigo-700"
                onclick="App.actions.newSurvey()">
                ＋ 新規アンケート作成
            </button>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-4 mb-5
                    flex flex-wrap gap-3">
            <input
                class="border border-slate-300 rounded-lg px-3 py-2 w-72
                       focus:ring-2 focus:ring-indigo-200 outline-none"
                placeholder="タイトルを検索"
                value="${App.utils.escape(App.state.keyword)}"
                onkeydown="if(event.key==='Enter')App.actions.search(this.value)">

            <select
                class="border border-slate-300 rounded-lg px-3 py-2"
                onchange="App.actions.statusFilter(this.value)">
                <option value="all" ${App.state.status_filter === 'all' ? 'selected' : ''}>すべて</option>
                <option value="active" ${App.state.status_filter === 'active' ? 'selected' : ''}>公開中</option>
                <option value="draft" ${App.state.status_filter === 'draft' ? 'selected' : ''}>下書き</option>
                <option value="ended" ${App.state.status_filter === 'ended' ? 'selected' : ''}>終了</option>
            </select>

            <select
                class="border border-slate-300 rounded-lg px-3 py-2"
                onchange="App.actions.sort(this.value)">
                <option value="updated_desc">更新日：新しい順</option>
                <option value="updated_asc">更新日：古い順</option>
                <option value="answers_desc">回答数：多い順</option>
                <option value="answers_asc">回答数：少ない順</option>
                <option value="start_desc">開始日：新しい順</option>
                <option value="start_asc">開始日：古い順</option>
            </select>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-slate-600">
                    <tr>
                        <th class="text-left px-4 py-3">作成日 / 更新日</th>
                        <th class="text-left px-4 py-3">タイトル</th>
                        <th class="text-left px-4 py-3">期間</th>
                        <th class="text-left px-4 py-3">状態</th>
                        <th class="text-left px-4 py-3">回答数</th>
                        <th class="text-right px-4 py-3">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    ${
                        surveys.length
                        ? surveys.map(App.render.surveyRow).join('')
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
    `;
};

window.App.render.surveyRow = function(survey) {
    const status = survey.status;
    const count = App.utils.answerCount(survey.id);

    let buttons = `
        <button
            class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200"
            onclick="App.actions.editSurvey('${survey.id}')">
            確認・編集
        </button>
    `;

    if (status === 'active') {
        buttons += `
            <button
                class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700"
                onclick="App.actions.aggregate('${survey.id}')">
                集計
            </button>

            <button
                class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700"
                onclick="App.actions.mail('${survey.id}')">
                送信
            </button>

            <button
                class="px-3 py-1.5 rounded-lg bg-amber-50 text-amber-700"
                onclick="App.actions.stop('${survey.id}')">
                停止
            </button>
        `;
    }

    if (status === 'draft') {
        buttons += `
            <button
                class="px-3 py-1.5 rounded-lg bg-red-50 text-red-700"
                onclick="App.actions.deleteSurvey('${survey.id}')">
                削除
            </button>
        `;
    }

    if (status === 'ended') {
        buttons += `
            <button
                class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700"
                onclick="App.actions.aggregate('${survey.id}')">
                集計
            </button>
        `;
    }

    buttons += `
        <button
            class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200"
            onclick="App.actions.duplicate('${survey.id}')">
            複製
        </button>
    `;

    return `
        <tr class="hover:bg-slate-50">
            <td class="px-4 py-4 whitespace-nowrap">
                <div>${App.utils.date(survey.created_at)}</div>
                <div class="text-xs text-slate-400">
                    更新: ${App.utils.date(survey.updated_at)}
                </div>
            </td>

            <td class="px-4 py-4 font-bold">
                ${App.utils.escape(survey.title || '無題')}
            </td>

            <td class="px-4 py-4 whitespace-nowrap">
                ${App.utils.date(survey.start_at)}
                ～
                ${App.utils.date(survey.end_at)}
            </td>

            <td class="px-4 py-4">
                <span class="px-2.5 py-1 rounded-full text-xs font-bold
                    ${App.utils.statusClass(status)}">
                    ${App.utils.statusLabel(status)}
                </span>
            </td>

            <td class="px-4 py-4 font-semibold">
                ${count} 件
            </td>

            <td class="px-4 py-4">
                <div class="flex justify-end gap-1.5 flex-wrap">
                    ${buttons}
                </div>
            </td>
        </tr>
    `;
};

window.App.render.editor = function() {
    const survey = App.state.editing;

    if (!survey) return '';

    return `
        <div class="flex items-center justify-between mb-5">
            <div>
                <button
                    class="text-sm text-slate-500 hover:text-slate-800"
                    onclick="App.actions.go('surveys')">
                    ← アンケート一覧
                </button>

                <h1 class="text-2xl font-bold mt-2">
                    アンケート作成・編集
                </h1>
            </div>

            <div class="flex gap-2">
                <button
                    class="px-4 py-2 rounded-lg bg-slate-100"
                    onclick="App.actions.preview()">
                    プレビュー
                </button>

                <button
                    class="px-4 py-2 rounded-lg bg-indigo-600 text-white"
                    onclick="App.actions.saveSurvey()">
                    保存して一覧へ戻る
                </button>

                <button
                    class="px-4 py-2 rounded-lg bg-slate-100"
                    onclick="App.actions.cancelEdit()">
                    キャンセル
                </button>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-5 mb-5">
            <div class="grid md:grid-cols-2 gap-4">
                <label>
                    <span class="block text-sm font-semibold mb-1">タイトル</span>
                    <input
                        id="survey_title"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2"
                        value="${App.utils.escape(survey.title)}"
                        oninput="App.actions.editField('title',this.value)">
                </label>

                <label>
                    <span class="block text-sm font-semibold mb-1">ステータス</span>
                    <select
                        class="w-full border border-slate-300 rounded-lg px-3 py-2"
                        onchange="App.actions.editField('status',this.value)">
                        <option value="draft" ${survey.status === 'draft' ? 'selected' : ''}>下書き</option>
                        <option value="active" ${survey.status === 'active' ? 'selected' : ''}>公開中</option>
                        <option value="ended" ${survey.status === 'ended' ? 'selected' : ''}>終了</option>
                    </select>
                </label>

                <label>
                    <span class="block text-sm font-semibold mb-1">開始日時</span>
                    <input
                        id="survey_start_at"
                        type="datetime-local"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2"
                        value="${App.utils.escape((survey.start_at || '').replace(' ','T'))}"
                        oninput="App.actions.editField('start_at',this.value)">
                </label>

                <label>
                    <span class="block text-sm font-semibold mb-1">終了日時</span>
                    <input
                        id="survey_end_at"
                        type="datetime-local"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2"
                        value="${App.utils.escape((survey.end_at || '').replace(' ','T'))}"
                        oninput="App.actions.editField('end_at',this.value)">
                </label>

                <label>
                    <span class="block text-sm font-semibold mb-1">
                        質問番号
                    </span>
                    <select
                        id="survey_numbering_mode"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2"
                        onchange="App.actions.editField('numbering_mode',this.value);App.actions.renumber()">
                        <option value="global" ${survey.numbering_mode === 'global' ? 'selected' : ''}>
                            Q1, Q2, Q3...
                        </option>
                        <option value="group" ${survey.numbering_mode === 'group' ? 'selected' : ''}>
                            Q1-1, Q1-2...
                        </option>
                    </select>
                </label>
            </div>
        </div>

        <div id="question_editor" class="space-y-4">
            ${survey.groups.map(App.render.group).join('')}
        </div>

        <button
            class="mt-5 w-full py-3 rounded-xl border-2 border-dashed
                   border-indigo-300 text-indigo-600 hover:bg-indigo-50"
            onclick="App.actions.addGroup()">
            ＋ グループを追加
        </button>
    `;
};

window.App.render.group = function(group, groupIndex) {
    return `
        <section
            class="survey-group bg-white border border-slate-200 rounded-2xl
                   overflow-hidden"
            data-group-id="${App.utils.escape(group.id)}">

            <div
                class="group-handle bg-slate-100 px-4 py-3 flex items-center
                       gap-3 cursor-move">

                <span class="text-slate-400 text-xl">⠿</span>

                <input
                    class="flex-1 bg-transparent font-bold outline-none"
                    value="${App.utils.escape(group.name)}"
                    onchange="App.actions.renameGroup('${group.id}',this.value)">

                <button
                    class="text-red-500 text-sm"
                    onclick="App.actions.deleteGroup('${group.id}')">
                    グループ削除
                </button>
            </div>

            <div
                class="question-list p-4 space-y-3"
                data-group-id="${App.utils.escape(group.id)}">

                ${group.questions.map(function(q, qi) {
                    return App.render.question(q, group, qi, groupIndex);
                }).join('')}

                <button
                    class="w-full py-2 rounded-lg bg-slate-50
                           text-indigo-600 hover:bg-indigo-50"
                    onclick="App.actions.addQuestion('${group.id}')">
                    ＋ 質問を追加
                </button>
            </div>
        </section>
    `;
};

window.App.render.question = function(q, group, qi, gi) {
    const number = App.actions.questionNumber(group.id, qi);

    return `
        <div
            class="question-item border border-slate-200 rounded-xl p-4"
            data-question-id="${App.utils.escape(q.id)}">

            <div class="flex gap-3 items-start">
                <div class="question-handle cursor-move text-slate-400 text-xl">
                    ⠿
                </div>

                <div class="flex-1">
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <div class="font-bold text-indigo-600 question-number">
                            ${number}
                        </div>

                        <button
                            class="text-red-500 text-sm"
                            onclick="App.actions.deleteQuestion('${group.id}','${q.id}')">
                            削除
                        </button>
                    </div>

                    <input
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 mb-3"
                        placeholder="質問文"
                        value="${App.utils.escape(q.text)}"
                        onchange="App.actions.questionField('${group.id}','${q.id}','text',this.value)">

                    <div class="grid md:grid-cols-2 gap-3">
                        <select
                            class="border border-slate-300 rounded-lg px-3 py-2"
                            onchange="App.actions.questionField('${group.id}','${q.id}','type',this.value);App.render.page()">
                            <option value="single" ${q.type === 'single' ? 'selected' : ''}>
                                単一選択
                            </option>
                            <option value="multiple" ${q.type === 'multiple' ? 'selected' : ''}>
                                複数選択
                            </option>
                            <option value="text" ${q.type === 'text' ? 'selected' : ''}>
                                自由記述
                            </option>
                        </select>

                        <label class="flex items-center gap-2 px-3">
                            <input
                                type="checkbox"
                                ${q.required ? 'checked' : ''}
                                onchange="App.actions.questionField('${group.id}','${q.id}','required',this.checked)">
                            必須回答
                        </label>
                    </div>

                    ${
                        q.type !== 'text'
                        ? `
                            <div class="mt-3">
                                <div class="text-sm font-semibold mb-2">
                                    選択肢
                                </div>

                                <div class="space-y-2">
                                    ${q.options.map(function(option, oi) {
                                        return `
                                            <div class="flex gap-2">
                                                <input
                                                    class="flex-1 border border-slate-300 rounded-lg px-3 py-2"
                                                    value="${App.utils.escape(option)}"
                                                    onchange="App.actions.option('${group.id}','${q.id}',${oi},this.value)">
                                                <button
                                                    class="px-3 text-red-500"
                                                    onclick="App.actions.removeOption('${group.id}','${q.id}',${oi})">
                                                    ×
                                                </button>
                                            </div>
                                        `;
                                    }).join('')}
                                </div>

                                <button
                                    class="mt-2 text-sm text-indigo-600"
                                    onclick="App.actions.addOption('${group.id}','${q.id}')">
                                    ＋ 選択肢追加
                                </button>

                                <label class="flex items-center gap-2 mt-3 text-sm">
                                    <input
                                        type="checkbox"
                                        ${q.other_enabled ? 'checked' : ''}
                                        onchange="App.actions.questionField('${group.id}','${q.id}','other_enabled',this.checked)">
                                    「その他」を許可
                                </label>
                            </div>
                        `
                        : ''
                    }
                </div>
            </div>
        </div>
    `;
};

window.App.render.aggregate = function() {
    const survey = App.state.data.surveys.find(
        s => s.id === App.state.surveyId
    );

    if (!survey) return '<div>アンケートが見つかりません。</div>';

    const responses = App.state.data.responses.filter(
        r => r.survey_id === survey.id
    );

    const questions = App.utils.allQuestions(survey);
    const customers = App.state.data.customers.filter(function(c) {
        return responses.some(r => r.customer_id === c.id);
    });

    const answeredCustomers = customers.filter(
        c => c.answer_status === 'answered'
    );

    const sent = customers.filter(c => Number(c.send_count || 0) > 0);

    const rate = sent.length
        ? (answeredCustomers.length / sent.length * 100).toFixed(1)
        : '0.0';

    return `
        <div class="flex items-center justify-between mb-5">
            <div>
                <button
                    class="text-sm text-slate-500"
                    onclick="App.actions.go('surveys')">
                    ← アンケート一覧
                </button>

                <h1 class="text-2xl font-bold mt-2">
                    ${App.utils.escape(survey.title)}
                </h1>
            </div>

            <a
                class="px-4 py-2 rounded-lg bg-slate-800 text-white"
                href="?action=csv&survey_id=${encodeURIComponent(survey.id)}">
                CSV出力
            </a>
        </div>

        <div class="grid md:grid-cols-5 gap-3 mb-6">
            ${[
                ['送信対象者数', sent.length + ' 人'],
                ['回答数', responses.length + ' 件'],
                ['未登録顧客からの回答数',
                    responses.filter(r => !r.customer_id).length + ' 件'],
                ['未回答数', Math.max(0, sent.length - answeredCustomers.length) + ' 人'],
                ['回答率', rate + ' %']
            ].map(function(x) {
                return `
                    <div class="bg-white border border-slate-200 rounded-xl p-4">
                        <div class="text-xs text-slate-500">${x[0]}</div>
                        <div class="text-2xl font-bold mt-2">${x[1]}</div>
                    </div>
                `;
            }).join('')}
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-5 mb-5">
            <div class="flex justify-between items-center mb-4">
                <h2 class="font-bold">設問別集計</h2>

                <div class="flex gap-2">
                    <button
                        class="px-3 py-1.5 rounded-lg bg-slate-100"
                        onclick="App.actions.selectAllQuestions(true)">
                        全選択
                    </button>

                    <button
                        class="px-3 py-1.5 rounded-lg bg-slate-100"
                        onclick="App.actions.selectAllQuestions(false)">
                        全解除
                    </button>
                </div>
            </div>

            <div class="space-y-2 mb-6">
                ${questions.map(function(q, i) {
                    const checked =
                        App.state.selectedQuestions[q.id] !== false;

                    return `
                        <label class="flex gap-2 items-center text-sm">
                            <input
                                type="checkbox"
                                ${checked ? 'checked' : ''}
                                onchange="App.actions.toggleQuestion('${q.id}',this.checked)">
                            <span>
                                Q${i + 1}.
                                ${App.utils.escape(q.text)}
                            </span>
                            <span class="px-2 py-0.5 rounded bg-slate-100 text-xs">
                                ${q.type}
                            </span>
                        </label>
                    `;
                }).join('')}
            </div>

            ${
                responses.length
                ? questions.map(function(q) {
                    if (App.state.selectedQuestions[q.id] === false) {
                        return '';
                    }

                    return App.render.questionStats(q, responses);
                }).join('')
                : `
                    <div class="py-12 text-center text-slate-400">
                        現在、回答データはありません
                    </div>
                `
            }
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-5">
            <div class="flex flex-wrap justify-between gap-3 mb-4">
                <h2 class="font-bold">個別回答一覧</h2>

                <input
                    id="response_filter"
                    class="border border-slate-300 rounded-lg px-3 py-2"
                    placeholder="会社名・氏名で検索"
                    value="${App.utils.escape(App.state.responseFilter)}"
                    oninput="App.actions.responseFilter(this.value)">
            </div>

            <div id="response_table" class="overflow-x-auto">
                ${App.render.responseTable(responses)}
            </div>
        </div>
    `;
};

window.App.render.questionStats = function(q, responses) {
    if (q.type === 'text') {
        return `
            <div class="border-t border-slate-100 pt-4 mt-4">
                <div class="font-semibold mb-3">
                    ${App.utils.escape(q.text)}
                </div>

                <div class="space-y-2 max-h-72 overflow-auto">
                    ${responses.map(function(r) {
                        const value = r.answers?.[q.id] ?? '';

                        if (!value) return '';

                        return `
                            <div class="p-3 bg-slate-50 rounded-lg">
                                <div class="text-xs text-slate-500">
                                    ${App.utils.escape(r.company)}
                                    /
                                    ${App.utils.escape(r.name)}
                                </div>
                                <div class="mt-1">
                                    ${App.utils.escape(
                                        Array.isArray(value)
                                        ? value.join('、')
                                        : value
                                    )}
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
            </div>
        `;
    }

    const counts = {};

    q.options.forEach(function(o) {
        counts[o] = 0;
    });

    let other = 0;

    responses.forEach(function(r) {
        let value = r.answers?.[q.id];

        if (!Array.isArray(value)) {
            value = value ? [value] : [];
        }

        value.forEach(function(v) {
            if (Object.hasOwn(counts, v)) {
                counts[v]++;
            } else if (v) {
                other++;
            }
        });
    });

    const total = responses.length || 1;

    return `
        <div class="border-t border-slate-100 pt-4 mt-4">
            <div class="font-semibold mb-3">
                ${App.utils.escape(q.text)}
            </div>

            <div class="space-y-3">
                ${Object.entries(counts).map(function([label,count]) {
                    const percent = (count / total * 100);

                    return `
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span>${App.utils.escape(label)}</span>
                                <span>${count} 件 / ${percent.toFixed(1)}%</span>
                            </div>

                            <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                <div
                                    class="h-full bg-indigo-500"
                                    style="width:${percent}%"></div>
                            </div>
                        </div>
                    `;
                }).join('')}

                ${
                    q.other_enabled
                    ? `
                        <div class="text-sm text-slate-500">
                            その他自由記述: ${other} 件
                        </div>
                    `
                    : ''
                }
            </div>
        </div>
    `;
};

window.App.render.responseTable = function(responses) {
    const keyword = App.state.responseFilter.toLowerCase();

    const filtered = responses.filter(function(r) {
        return !keyword ||
            String(r.company || '').toLowerCase().includes(keyword) ||
            String(r.name || '').toLowerCase().includes(keyword);
    });

    return `
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-100">
                    <th class="text-left p-3">会社名</th>
                    <th class="text-left p-3">氏名</th>
                    <th class="text-left p-3">メール</th>
                    <th class="text-left p-3">回答日時</th>
                    <th class="text-right p-3">操作</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-slate-100">
                ${filtered.map(function(r) {
                    return `
                        <tr>
                            <td class="p-3">${App.utils.escape(r.company)}</td>
                            <td class="p-3">${App.utils.escape(r.name)}</td>
                            <td class="p-3">${App.utils.escape(r.email)}</td>
                            <td class="p-3">${App.utils.date(r.answered_at)}</td>
                            <td class="p-3 text-right">
                                <button
                                    class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700"
                                    onclick="App.actions.responseDetail('${r.id}')">
                                    全回答を表示
                                </button>
                            </td>
                        </tr>
                    `;
                }).join('')}
            </tbody>
        </table>
    `;
};

window.App.render.mail = function() {
    const survey = App.state.data.surveys.find(
        s => s.id === App.state.surveyId
    );

    if (!survey) return '';

    let customers = [...App.state.data.customers];

    const keyword = App.state.customerFilter.toLowerCase();

    if (keyword) {
        customers = customers.filter(function(c) {
            return [
                c.company,
                c.name,
                c.email,
                c.department
            ].some(v =>
                String(v || '').toLowerCase().includes(keyword)
            );
        });
    }

    return `
        <div class="mb-5">
            <button
                class="text-sm text-slate-500"
                onclick="App.actions.go('surveys')">
                ← アンケート一覧
            </button>

            <h1 class="text-2xl font-bold mt-2">
                顧客選択・送信・送信履歴
            </h1>

            <p class="text-sm text-slate-500">
                ${App.utils.escape(survey.title)}
            </p>
        </div>

        <div class="grid lg:grid-cols-3 gap-5">
            <div class="lg:col-span-2 bg-white border border-slate-200
                        rounded-2xl overflow-hidden">

                <div class="p-4 border-b flex flex-wrap gap-3">
                    <input
                        id="customer_filter"
                        class="border border-slate-300 rounded-lg px-3 py-2 flex-1"
                        placeholder="顧客名・メール・会社名で検索"
                        value="${App.utils.escape(App.state.customerFilter)}"
                        oninput="App.actions.customerFilter(this.value)">

                    <button
                        class="px-4 py-2 rounded-lg bg-slate-100"
                        onclick="App.actions.syncCustomers()">
                        顧客一覧更新
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="p-3">
                                    <input
                                        id="select_all"
                                        type="checkbox"
                                        onchange="App.actions.selectAllCustomers(this.checked)">
                                </th>
                                <th class="text-left p-3">会社名 / 氏名</th>
                                <th class="text-left p-3">メール</th>
                                <th class="text-left p-3">送信状況</th>
                                <th class="text-left p-3">kintone</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">
                            ${customers.map(function(c) {
                                const disabled = c.source === 'web';

                                return `
                                    <tr>
                                        <td class="p-3">
                                            <input
                                                type="checkbox"
                                                class="customer-check"
                                                data-id="${App.utils.escape(c.id)}"
                                                ${disabled ? 'disabled' : ''}>
                                        </td>

                                        <td class="p-3">
                                            <div class="font-bold">
                                                ${App.utils.escape(c.company)}
                                            </div>
                                            <div>
                                                ${App.utils.escape(c.name)}
                                            </div>
                                            <div class="text-xs text-slate-400">
                                                ${App.utils.escape(c.phone)}
                                            </div>
                                        </td>

                                        <td class="p-3">
                                            ${App.utils.escape(c.email)}
                                        </td>

                                        <td class="p-3">
                                            <div>
                                                最終:
                                                ${App.utils.date(c.sent_at)}
                                            </div>
                                            <div class="text-xs">
                                                ${Number(c.send_count || 0)}回 /
                                                ${c.answer_status === 'answered'
                                                    ? '回答済み'
                                                    : '未回答'}
                                            </div>
                                        </td>

                                        <td class="p-3">
                                            ${
                                                c.kintone_status === 'registered'
                                                ? '<span class="text-emerald-600">✓ 登録完了</span>'
                                                : `
                                                    <button
                                                        class="text-indigo-600"
                                                        onclick="App.actions.markRegistered('${c.id}')">
                                                        キントーン登録完了
                                                    </button>
                                                `
                                            }
                                        </td>
                                    </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5">
                <h2 class="font-bold mb-4">メール送信</h2>

                <label class="block text-sm font-semibold mb-1">
                    テンプレート
                </label>

                <select
                    id="template_type"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 mb-4">
                    <option value="initial">初回</option>
                    <option value="reminder">リマインド</option>
                </select>

                <label class="block text-sm font-semibold mb-1">
                    件名
                </label>

                <input
                    id="mail_subject"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 mb-4"
                    placeholder="アンケートのお願い">

                <label class="block text-sm font-semibold mb-1">
                    本文
                </label>

                <textarea
                    id="mail_body"
                    rows="10"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 mb-4"
                    placeholder="{顧客名} 様&#10;&#10;アンケートへのご協力をお願いいたします。&#10;{アンケートURL}"></textarea>

                <div class="bg-amber-50 text-amber-800 rounded-lg p-3 text-sm mb-4">
                    {顧客名} と {アンケートURL} を利用できます。
                </div>

                <button
                    class="w-full py-3 rounded-xl bg-indigo-600 text-white
                           font-bold"
                    onclick="App.actions.sendMail()">
                    一括送信実行
                </button>
            </div>
        </div>
    `;
};

window.App.render.settings = function() {
    const s = App.state.data.settings || {};

    const selected = function(code) {
        return App.state.kintoneFields.find(
            f => f.code === code
        );
    };

    return `
        <div class="mb-5">
            <h1 class="text-2xl font-bold">キントーン連携設定</h1>
            <p class="text-sm text-slate-500 mt-1">
                kintone / cybozu.com API設定
            </p>
        </div>

        <form id="settings_form"
              class="bg-white border border-slate-200 rounded-2xl p-5">

            <div class="grid md:grid-cols-2 gap-4">
                <label>
                    <span class="block text-sm font-semibold mb-1">
                        サブドメイン
                    </span>
                    <input
                        id="setting_subdomain"
                        class="w-full border rounded-lg px-3 py-2"
                        value="${App.utils.escape(s.subdomain || '')}"
                        placeholder="xxxx.cybozu.com または xxxx">
                </label>

                <label>
                    <span class="block text-sm font-semibold mb-1">
                        アプリID
                    </span>
                    <input
                        id="setting_app_id"
                        class="w-full border rounded-lg px-3 py-2"
                        value="${App.utils.escape(s.app_id || '')}">
                </label>

                <label>
                    <span class="block text-sm font-semibold mb-1">
                        ログイン名
                    </span>
                    <input
                        id="setting_login_name"
                        class="w-full border rounded-lg px-3 py-2"
                        value="${App.utils.escape(s.login_name || '')}">
                </label>

                <label>
                    <span class="block text-sm font-semibold mb-1">
                        パスワード
                    </span>
                    <input
                        id="setting_password"
                        type="password"
                        class="w-full border rounded-lg px-3 py-2"
                        value="${App.utils.escape(s.password || '')}">
                </label>

                <label>
                    <span class="block text-sm font-semibold mb-1">
                        Proxyサーバ
                    </span>
                    <input
                        id="setting_proxy"
                        class="w-full border rounded-lg px-3 py-2"
                        value="${App.utils.escape(s.proxy || '')}"
                        placeholder="host名:port番号">
                </label>

                <label class="flex items-center gap-2 mt-6">
                    <input
                        id="setting_ssl_verify"
                        type="checkbox"
                        ${s.ssl_verify ? 'checked' : ''}>
                    SSL証明書検証を行う
                </label>
            </div>

            <div class="mt-6 border-t pt-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="font-bold">項目マッピング</h2>

                    <button
                        type="button"
                        class="px-4 py-2 rounded-lg bg-slate-800 text-white"
                        onclick="App.actions.fetchKintoneFields()">
                        項目一覧を取得
                    </button>
                </div>

                <div id="field_message"
                     class="text-sm text-slate-500 mb-4"></div>

                ${App.render.mapping('field_company','会社名',s.field_company)}
                ${App.render.mapping('field_name','氏名',s.field_name)}
                ${App.render.mapping('field_email','メールアドレス',s.field_email)}
                ${App.render.mapping('field_department','部署名',s.field_department)}
                ${App.render.mapping('field_phone','電話番号',s.field_phone)}

                <div class="mt-3">
                    <div class="text-sm font-semibold mb-1">
                        住所（複数選択可）
                    </div>

                    <select
                        id="mapping_field_address"
                        multiple
                        class="w-full border rounded-lg px-3 py-2 min-h-32">
                        ${App.render.fieldOptions(s.field_address || [])}
                    </select>
                </div>
            </div>

            <div class="flex flex-wrap gap-3 mt-6">
                <button
                    type="button"
                    class="px-5 py-3 rounded-xl bg-indigo-600 text-white"
                    onclick="App.actions.testKintone()">
                    接続確認
                </button>

                <button
                    type="button"
                    class="px-5 py-3 rounded-xl bg-slate-800 text-white"
                    onclick="App.actions.saveSettings()">
                    設定を保存
                </button>
            </div>
        </form>
    `;
};

window.App.render.mapping = function(key, label, value) {
    return `
        <div class="grid md:grid-cols-3 gap-3 items-center mb-3">
            <div class="font-medium">${label}</div>

            <select
                id="mapping_${key}"
                class="md:col-span-2 border rounded-lg px-3 py-2">
                ${App.render.fieldOptions(value ? [value] : [])}
            </select>
        </div>
    `;
};

window.App.render.fieldOptions = function(selectedValues) {
    selectedValues = Array.isArray(selectedValues)
        ? selectedValues
        : [selectedValues];

    const options = [
        '<option value="">-- 未設定 --</option>'
    ];

    App.state.kintoneFields.forEach(function(field) {
        options.push(`
            <option
                value="${App.utils.escape(field.code)}"
                ${selectedValues.includes(field.code) ? 'selected' : ''}>
                ${App.utils.escape(field.label)}
                (${App.utils.escape(field.code)})
                ${field.type ? '[' + App.utils.escape(field.type) + ']' : ''}
            </option>
        `);
    });

    return options.join('');
};

window.App.render.modals = function() {
    return `
        <div
            id="preview_modal"
            class="hidden fixed inset-0 z-50 bg-black/40 p-4">
            <div class="bg-white max-w-3xl mx-auto mt-8 rounded-2xl
                        max-h-[90vh] overflow-auto">
                <div class="p-4 border-b flex justify-between">
                    <h2 class="font-bold">プレビュー</h2>
                    <button onclick="App.actions.closePreview()">×</button>
                </div>

                <div id="preview_content" class="p-6"></div>
            </div>
        </div>

        <div
            id="response_modal"
            class="hidden fixed inset-0 z-50 bg-black/40 p-4">
            <div class="bg-white max-w-3xl mx-auto mt-8 rounded-2xl
                        max-h-[90vh] overflow-auto">
                <div class="p-4 border-b flex justify-between">
                    <h2 class="font-bold">全回答</h2>
                    <button onclick="App.actions.closeResponse()">×</button>
                </div>

                <div id="response_detail" class="p-6"></div>
            </div>
        </div>
    `;
};

window.App.actions.go = function(page) {
    if (page === 'surveys') {
        App.state.page = 'surveys';
        App.state.surveyId = '';
        App.state.editing = null;
    } else {
        App.state.page = page;
    }

    App.render.app();
};

window.App.actions.newSurvey = function() {
    const now = new Date();
    const iso = new Date(now.getTime() - now.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 16);

    App.state.editing = {
        id: App.utils.id('sv'),
        title: '新しいアンケート',
        start_at: iso.replace('T', ' '),
        end_at: '',
        status: 'draft',
        created_at: '',
        updated_at: '',
        numbering_mode: 'global',
        groups: [{
            id: App.utils.id('gr'),
            name: 'グループ1',
            questions: []
        }],
        deleted: false
    };

    App.state.page = 'edit';
    App.render.app();
};

window.App.actions.editSurvey = function(id) {
    const survey = App.state.data.surveys.find(s => s.id === id);

    if (!survey) return;

    App.state.editing = JSON.parse(JSON.stringify(survey));
    App.state.surveyId = id;
    App.state.page = 'edit';

    App.render.app();
};

window.App.actions.editField = function(key, value) {
    if (!App.state.editing) return;

    App.state.editing[key] = value;
};

window.App.actions.saveSurvey = async function() {
    try {
        App.actions.renumber();

        const result = await App.api.post('save_survey', {
            survey_json: App.state.editing
        });

        const index = App.state.data.surveys.findIndex(
            s => s.id === result.survey.id
        );

        if (index >= 0) {
            App.state.data.surveys[index] = result.survey;
        } else {
            App.state.data.surveys.push(result.survey);
        }

        App.state.editing = null;
        App.state.page = 'surveys';

        App.render.app();

        alert('保存しました。');
    } catch (error) {
        alert(error.message);
    }
};

window.App.actions.cancelEdit = function() {
    if (confirm('変更を破棄して一覧へ戻りますか？')) {
        App.actions.go('surveys');
    }
};

window.App.actions.addGroup = function() {
    if (!App.state.editing) return;

    const number = App.state.editing.groups.length + 1;

    App.state.editing.groups.push({
        id: App.utils.id('gr'),
        name: 'グループ' + number,
        questions: []
    });

    App.render.page();
};

window.App.actions.deleteGroup = function(groupId) {
    if (!confirm('グループと内包する質問を削除しますか？')) return;

    App.state.editing.groups =
        App.state.editing.groups.filter(g => g.id !== groupId);

    if (!App.state.editing.groups.length) {
        App.actions.addGroup();
        return;
    }

    App.render.page();
};

window.App.actions.renameGroup = function(groupId, value) {
    const group = App.state.editing.groups.find(g => g.id === groupId);

    if (group) group.name = value;
};

window.App.actions.addQuestion = function(groupId) {
    const group = App.state.editing.groups.find(g => g.id === groupId);

    if (!group) return;

    group.questions.push({
        id: App.utils.id('qu'),
        text: '',
        type: 'single',
        required: false,
        options: ['選択肢1', '選択肢2'],
        other_enabled: false
    });

    App.render.page();
};

window.App.actions.deleteQuestion = function(groupId, questionId) {
    const group = App.state.editing.groups.find(g => g.id === groupId);

    if (!group) return;

    group.questions =
        group.questions.filter(q => q.id !== questionId);

    App.render.page();
};

window.App.actions.questionField = function(
    groupId,
    questionId,
    key,
    value
) {
    const group = App.state.editing.groups.find(g => g.id === groupId);
    const question = group?.questions.find(q => q.id === questionId);

    if (!question) return;

    question[key] = value;
};

window.App.actions.addOption = function(groupId, questionId) {
    const group = App.state.editing.groups.find(g => g.id === groupId);
    const question = group?.questions.find(q => q.id === questionId);

    if (!question) return;

    question.options.push('選択肢' + (question.options.length + 1));
    App.render.page();
};

window.App.actions.removeOption = function(groupId, questionId, index) {
    const group = App.state.editing.groups.find(g => g.id === groupId);
    const question = group?.questions.find(q => q.id === questionId);

    if (!question) return;

    question.options.splice(index, 1);
    App.render.page();
};

window.App.actions.option = function(
    groupId,
    questionId,
    index,
    value
) {
    const group = App.state.editing.groups.find(g => g.id === groupId);
    const question = group?.questions.find(q => q.id === questionId);

    if (question) {
        question.options[index] = value;
    }
};

window.App.actions.questionNumber = function(groupId, qi) {
    if (App.state.editing?.numbering_mode === 'group') {
        const gi = App.state.editing.groups.findIndex(
            g => g.id === groupId
        );

        return 'Q' + (gi + 1) + '-' + (qi + 1);
    }

    let n = 0;

    for (const group of App.state.editing.groups) {
        for (const q of group.questions) {
            n++;

            if (group.id === groupId && q === group.questions[qi]) {
                return 'Q' + n;
            }
        }
    }

    return 'Q' + (n + 1);
};

window.App.actions.renumber = function() {
    if (!App.state.editing) return;

    /*
     * 番号自体は保存データとして持たず、
     * groups/questions の現在順序から常時再計算する。
     */
    App.render.page();
};

window.App.actions.initSortables = function() {
    const editor = document.getElementById('question_editor');

    if (!editor || typeof Sortable === 'undefined') return;

    new Sortable(editor, {
        animation: 180,
        handle: '.group-handle',
        ghostClass: 'opacity-40',
        onEnd: function(event) {
            const groups = App.state.editing.groups;
            const moved = groups.splice(event.oldIndex, 1)[0];
            groups.splice(event.newIndex, 0, moved);
            App.render.page();
        }
    });

    document.querySelectorAll('.question-list').forEach(function(list) {
        new Sortable(list, {
            group: 'questions',
            animation: 180,
            handle: '.question-handle',
            ghostClass: 'opacity-40',
            filter: 'button',
            onEnd: function(event) {
                const fromGroup = App.state.editing.groups.find(
                    g => g.id === event.from.dataset.groupId
                );

                const toGroup = App.state.editing.groups.find(
                    g => g.id === event.to.dataset.groupId
                );

                if (!fromGroup || !toGroup) return;

                const question = fromGroup.questions.find(
                    q => q.id === event.item.dataset.questionId
                );

                if (!question) return;

                fromGroup.questions =
                    fromGroup.questions.filter(q => q.id !== question.id);

                let targetIndex = event.newIndex;

                if (event.from === event.to) {
                    targetIndex = Math.min(
                        targetIndex,
                        toGroup.questions.length
                    );
                }

                toGroup.questions.splice(targetIndex, 0, question);

                App.render.page();
            }
        });
    });
};

window.App.actions.preview = function() {
    const modal = document.getElementById('preview_modal');
    const content = document.getElementById('preview_content');

    if (!modal || !content) return;

    const survey = App.state.editing;

    content.innerHTML = `
        <div class="max-w-xl mx-auto">
            <h1 class="text-2xl font-bold mb-6">
                ${App.utils.escape(survey.title)}
            </h1>

            ${survey.groups.map(function(group, gi) {
                return `
                    <section class="mb-8">
                        <h2 class="font-bold text-lg mb-4">
                            ${App.utils.escape(group.name)}
                        </h2>

                        ${group.questions.map(function(q, qi) {
                            return `
                                <div class="mb-6">
                                    <div class="font-semibold mb-2">
                                        ${App.actions.questionNumber(group.id,qi)}
                                        ${App.utils.escape(q.text)}
                                        ${q.required
                                            ? '<span class="text-red-500">*</span>'
                                            : ''}
                                    </div>

                                    ${
                                        q.type === 'text'
                                        ? `
                                            <textarea
                                                class="w-full border rounded-lg p-3"
                                                rows="4"></textarea>
                                        `
                                        : q.options.map(function(o) {
                                            const input =
                                                q.type === 'multiple'
                                                ? 'checkbox'
                                                : 'radio';

                                            return `
                                                <label class="block p-2">
                                                    <input type="${input}">
                                                    ${App.utils.escape(o)}
                                                </label>
                                            `;
                                        }).join('')
                                    }
                                </div>
                            `;
                        }).join('')}
                    </section>
                `;
            }).join('')}

            <button
                class="w-full py-3 rounded-xl bg-indigo-600 text-white"
                onclick="alert('プレビューのため実送信は行いません。')">
                回答を送信
            </button>
        </div>
    `;

    modal.classList.remove('hidden');
};

window.App.actions.closePreview = function() {
    document.getElementById('preview_modal')?.classList.add('hidden');
};

window.App.actions.aggregate = function(id) {
    App.state.surveyId = id;
    App.state.page = 'aggregate';

    const survey = App.state.data.surveys.find(s => s.id === id);

    if (survey) {
        App.utils.allQuestions(survey).forEach(function(q) {
            if (!(q.id in App.state.selectedQuestions)) {
                App.state.selectedQuestions[q.id] = true;
            }
        });
    }

    App.render.app();
};

window.App.actions.toggleQuestion = function(id, checked) {
    App.state.selectedQuestions[id] = checked;
    App.render.page();
};

window.App.actions.selectAllQuestions = function(value) {
    const survey = App.state.data.surveys.find(
        s => s.id === App.state.surveyId
    );

    if (!survey) return;

    App.utils.allQuestions(survey).forEach(function(q) {
        App.state.selectedQuestions[q.id] = value;
    });

    App.render.page();
};

window.App.actions.responseFilter = function(value) {
    App.state.responseFilter = value;

    const survey = App.state.data.surveys.find(
        s => s.id === App.state.surveyId
    );

    if (!survey) return;

    const responses = App.state.data.responses.filter(
        r => r.survey_id === survey.id
    );

    const table = document.getElementById('response_table');

    if (table) {
        table.innerHTML = App.render.responseTable(responses);
    }
};

window.App.actions.responseDetail = function(id) {
    const response = App.state.data.responses.find(r => r.id === id);

    if (!response) return;

    const survey = App.state.data.surveys.find(
        s => s.id === response.survey_id
    );

    if (!survey) return;

    const questions = App.utils.allQuestions(survey);

    const content = document.getElementById('response_detail');

    content.innerHTML = `
        <div class="mb-5">
            <div class="font-bold text-lg">
                ${App.utils.escape(response.company)}
                /
                ${App.utils.escape(response.name)}
            </div>

            <div class="text-sm text-slate-500">
                ${App.utils.escape(response.email)}
                /
                ${App.utils.date(response.answered_at)}
            </div>
        </div>

        <div class="space-y-4">
            ${questions.map(function(q) {
                let value = response.answers?.[q.id] ?? '';

                if (Array.isArray(value)) {
                    value = value.join('、');
                }

                return `
                    <div class="border-b pb-4">
                        <div class="font-semibold">
                            ${App.utils.escape(q.text)}
                        </div>

                        <div class="mt-1 whitespace-pre-wrap">
                            ${App.utils.escape(value)}
                        </div>
                    </div>
                `;
            }).join('')}
        </div>
    `;

    document.getElementById('response_modal')
        ?.classList.remove('hidden');
};

window.App.actions.closeResponse = function() {
    document.getElementById('response_modal')?.classList.add('hidden');
};

window.App.actions.mail = function(id) {
    App.state.surveyId = id;
    App.state.page = 'mail';
    App.render.app();
};

window.App.actions.customerFilter = function(value) {
    App.state.customerFilter = value;
    App.render.page();
};

window.App.actions.selectAllCustomers = function(checked) {
    document.querySelectorAll('.customer-check').forEach(function(el) {
        if (!el.disabled) {
            el.checked = checked;
        }
    });
};

window.App.actions.sendMail = async function() {
    const ids = [...document.querySelectorAll('.customer-check:checked')]
        .map(el => el.dataset.id);

    if (!ids.length) {
        alert('送信対象を選択してください。');
        return;
    }

    const alreadySent = ids.filter(function(id) {
        const customer = App.state.data.customers.find(c => c.id === id);
        return Number(customer?.send_count || 0) > 0;
    });

    if (alreadySent.length &&
        !confirm(
            '既に送信済みの宛先が含まれています。再送しますか？'
        )) {
        return;
    }

    try {
        const result = await App.api.post('send_mail', {
            survey_id: App.state.surveyId,
            recipient_ids: ids,
            mail_subject:
                document.getElementById('mail_subject')?.value || '',
            mail_body:
                document.getElementById('mail_body')?.value || '',
            template_type:
                document.getElementById('template_type')?.value || 'initial'
        });

        await App.api.load();
        alert(result.message);
        App.render.page();
    } catch (error) {
        alert(error.message);
    }
};

window.App.actions.syncCustomers = async function() {
    try {
        const result = await App.api.post('sync_customers');

        App.state.data.customers = result.customers;

        App.render.page();

        alert(result.count + '件の顧客を取得しました。');
    } catch (error) {
        alert(error.message);
    }
};

window.App.actions.markRegistered = async function(id) {
    try {
        await App.api.post('mark_kintone_registered', {
            customer_id: id
        });

        await App.api.load();
        App.render.page();
    } catch (error) {
        alert(error.message);
    }
};

window.App.actions.stop = async function(id) {
    if (!confirm('このアンケートを停止しますか？')) return;

    const survey = App.state.data.surveys.find(s => s.id === id);

    if (!survey) return;

    const copy = JSON.parse(JSON.stringify(survey));
    copy.status = 'ended';

    try {
        await App.api.post('save_survey', {
            survey_json: copy
        });

        await App.api.load();
        App.render.page();
    } catch (error) {
        alert(error.message);
    }
};

window.App.actions.deleteSurvey = async function(id) {
    if (!confirm('このアンケートを削除しますか？')) return;

    try {
        await App.api.post('delete_survey', {
            survey_id: id
        });

        await App.api.load();
        App.render.page();
    } catch (error) {
        alert(error.message);
    }
};

window.App.actions.duplicate = async function(id) {
    try {
        await App.api.post('duplicate_survey', {
            survey_id: id
        });

        await App.api.load();
        App.render.page();
    } catch (error) {
        alert(error.message);
    }
};

window.App.actions.search = function(value) {
    App.state.keyword = value;
    App.render.page();
};

window.App.actions.statusFilter = function(value) {
    App.state.status_filter = value;
    App.render.page();
};

window.App.actions.sort = function(value) {
    App.state.sort = value;
    App.render.page();
};

window.App.actions.fetchKintoneFields = async function() {
    const message = document.getElementById('field_message');

    if (message) {
        message.textContent = '項目一覧を取得しています...';
    }

    const settings = App.actions.readSettings();

    try {
        /*
         * ここが kintone API の項目一覧取得本体。
         * GET /k/v1/app/form/fields.json をPHP経由で実行する。
         */
        const result = await App.api.post('kintone_fields', {
            app_id: settings.app_id,
            settings_json: settings
        });

        App.state.kintoneFields = result.fields || [];

        if (message) {
            message.textContent =
                App.state.kintoneFields.length +
                '件のフィールドを取得しました。';
        }

        App.render.page();
    } catch (error) {
        if (message) {
            message.textContent = error.message;
        }

        alert(error.message);
    }
};

window.App.actions.readSettings = function() {
    const current = App.state.data.settings || {};

    return {
        subdomain:
            document.getElementById('setting_subdomain')?.value ||
            current.subdomain ||
            '',

        app_id:
            document.getElementById('setting_app_id')?.value ||
            current.app_id ||
            '',

        login_name:
            document.getElementById('setting_login_name')?.value ||
            current.login_name ||
            '',

        password:
            document.getElementById('setting_password')?.value ??
            current.password ??
            '',

        proxy:
            document.getElementById('setting_proxy')?.value ||
            current.proxy ||
            '',

        ssl_verify:
            document.getElementById('setting_ssl_verify')?.checked ||
            false,

        field_company:
            document.getElementById('mapping_field_company')?.value ||
            current.field_company ||
            '',

        field_name:
            document.getElementById('mapping_field_name')?.value ||
            current.field_name ||
            '',

        field_email:
            document.getElementById('mapping_field_email')?.value ||
            current.field_email ||
            '',

        field_department:
            document.getElementById('mapping_field_department')?.value ||
            current.field_department ||
            '',

        field_phone:
            document.getElementById('mapping_field_phone')?.value ||
            current.field_phone ||
            '',

        field_address:
            [...(
                document.getElementById('mapping_field_address')
                ?.selectedOptions || []
            )].map(o => o.value).filter(Boolean)
    };
};

window.App.actions.saveSettings = async function() {
    try {
        const settings = App.actions.readSettings();

        await App.api.post('save_settings', {
            settings_json: settings
        });

        App.state.data.settings = settings;

        alert('設定を保存しました。');
    } catch (error) {
        alert(error.message);
    }
};

window.App.actions.testKintone = async function() {
    try {
        const settings = App.actions.readSettings();

        const result = await App.api.post('kintone_test', {
            settings_json: settings
        });

        alert(
            result.message +
            '\nHTTP Status: ' +
            (result.status || '')
        );
    } catch (error) {
        /*
         * 「JSON以外が返ってきた」問題を曖昧にせず、
         * PHP側でJSON解析結果と生レスポンスを分離して表示する。
         */
        alert(
            '接続確認に失敗しました。\n\n' +
            error.message
        );
    }
};

window.App.actions.logout = function() {
    alert('この単一ファイル版では管理画面セッションの認証処理を外部認証に委ねています。');
};


/*
 * ================================================================
 * 初期化
 * ================================================================
 *
 * document.readyState を確認することで、
 * script がDOM構築前後のどちらで評価されても1回だけ起動する。
 */
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        App.init();
    }, {once: true});
} else {
    App.init();
}
</script>

</body>
</html>
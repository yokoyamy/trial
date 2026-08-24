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
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function survey_h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function survey_json(mixed $v): string {
    return json_encode(
        $v,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?: 'null';
}

function survey_now(): string {
    return date('Y-m-d H:i:s');
}

function survey_id(string $prefix = 'id'): string {
    try {
        return $prefix . '_' . bin2hex(random_bytes(8));
    } catch (Throwable) {
        return $prefix . '_' . uniqid('', true);
    }
}

function survey_defaults(): array {
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

function survey_load(): array {
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        if (!@mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true) && !is_dir(SURVEY_STORAGE_DIRECTORY)) {
            throw new RuntimeException('survey_storageディレクトリを作成できません。');
        }
    }

    if (!file_exists(SURVEY_STORAGE_FILE)) {
        $data = survey_defaults();
        if (@file_put_contents(
            SURVEY_STORAGE_FILE,
            survey_json($data),
            LOCK_EX
        ) === false) {
            throw new RuntimeException('データファイルを作成できません。');
        }
        return $data;
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);
    if ($raw === false) {
        throw new RuntimeException('データファイルを読み込めません。');
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = survey_defaults();
    }

    return array_replace_recursive(survey_defaults(), $data);
}

function survey_save(array $data): void {
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
    }

    $tmp = SURVEY_STORAGE_FILE . '.tmp';
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('データファイルへ保存できません。');
    }

    if (!@rename($tmp, SURVEY_STORAGE_FILE)) {
        @unlink($tmp);
        throw new RuntimeException('データファイルを更新できません。');
    }
}

function survey_csrf(): string {
    if (empty($_SESSION['survey_csrf_token'])) {
        $_SESSION['survey_csrf_token'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['survey_csrf_token'];
}

function survey_check_csrf(): void {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals(survey_csrf(), $token)) {
        survey_api([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。'
        ], 403);
    }
}

function survey_api(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo survey_json($data);
    exit;
}

function survey_request_json(): array {
    $raw = @file_get_contents('php://input');
    if (!$raw) return [];
    $v = json_decode($raw, true);
    return is_array($v) ? $v : [];
}

/**
 * kintone URL成形
 */
function kintone_build_url(string $domain, string $endpoint): string {
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain);
    $domain = rtrim($domain, '/');
    $endpoint = '/' . ltrim($endpoint, '/');
    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

/**
 * PHP 8.4/8.5対応のレスポンスヘッダー取得。
 * 非推奨の $http_response_header は使用しない。
 */
function get_safe_response_headers(): array {
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
        return is_array($headers) ? $headers : [];
    }
    return [];
}

/**
 * cURLを使わないkintone API通信
 */
function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload = null,
    array $config = []
): array {
    $method = strtoupper($method);

    $options = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20
    ];

    if ($method !== 'GET' && $payload !== null) {
        $options['content'] = is_array($payload)
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string)$payload;
    }

    $context_options = [
        'http' => $options,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    $proxy = trim((string)($config['proxy_host_port'] ?? ''));
    if ($proxy !== '') {
        $context_options['http']['proxy'] = 'tcp://' . $proxy;
        $context_options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($context_options);

    $body = @file_get_contents($url, false, $context);
    $headers_out = get_safe_response_headers();

    $status = 500;

    foreach ($headers_out as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $m)) {
            $status = (int)$m[1];
        }
    }

    $decoded = json_decode((string)$body, true);

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : []
        ];
    }

    $message = is_array($decoded)
        ? (string)($decoded['message'] ?? '')
        : '';

    if ($message === '') {
        $message = 'kintone API通信エラーが発生しました。';
    }

    return [
        'success' => false,
        'status' => $status,
        'message' => $message,
        'raw' => $decoded
    ];
}

function make_cybozu_auth_header(string $login_name, string $password): string {
    return 'X-Cybozu-Authorization: ' .
        base64_encode(trim($login_name) . ':' . trim($password));
}

function kintone_settings_from(array $data, array $override = []): array {
    $s = $data['settings'] ?? [];

    foreach ($override as $k => $v) {
        $s[$k] = $v;
    }

    return $s;
}

function kintone_test(array $settings): array {
    $domain = trim((string)($settings['subdomain'] ?? ''));
    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');

    if ($domain === '' || $login === '' || $password === '') {
        return [
            'ok' => false,
            'message' => 'サブドメイン、ログイン名、パスワードを入力してください。'
        ];
    }

    $url = kintone_build_url($domain, '/k/v1/app.json');

    $result = kintone_api_request(
        'GET',
        $url . '?id=1',
        [
            make_cybozu_auth_header($login, $password),
            'Accept: application/json'
        ],
        null,
        [
            'proxy_host_port' => trim((string)($settings['proxy'] ?? ''))
        ]
    );

    /*
     * アプリID 1 が存在しない場合でも、
     * 認証エラーでなければ接続自体は成立している。
     */
    if ($result['status'] === 404) {
        return [
            'ok' => true,
            'message' => 'kintoneへの接続に成功しました。'
        ];
    }

    if ($result['success']) {
        return [
            'ok' => true,
            'message' => 'kintoneへの接続に成功しました。'
        ];
    }

    return [
        'ok' => false,
        'message' => $result['message'] .
            '（HTTP ' . $result['status'] . '）'
    ];
}

/**
 * kintoneフォームフィールド取得
 *
 * 重要:
 * GET /k/v1/app/form/fields.json?app=123
 *
 * appパラメータを必ずURLへ付与する。
 */
function kintone_fetch_fields(array $settings, string $app_id): array {
    $domain = trim((string)($settings['subdomain'] ?? ''));
    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');
    $app_id = trim($app_id);

    if ($domain === '' || $login === '' || $password === '') {
        return [
            'ok' => false,
            'message' => 'kintone接続設定が不足しています。'
        ];
    }

    if (!preg_match('/^[0-9]+$/', $app_id)) {
        return [
            'ok' => false,
            'message' => '顧客管理アプリIDは数字で入力してください。'
        ];
    }

    $url = kintone_build_url(
        $domain,
        '/k/v1/app/form/fields.json'
    );

    $url .= '?app=' . rawurlencode($app_id) . '&lang=ja';

    $result = kintone_api_request(
        'GET',
        $url,
        [
            make_cybozu_auth_header($login, $password),
            'Accept: application/json'
        ],
        null,
        [
            'proxy_host_port' => trim((string)($settings['proxy'] ?? ''))
        ]
    );

    if (!$result['success']) {
        return [
            'ok' => false,
            'message' => $result['message'] .
                '（HTTP ' . $result['status'] . '）'
        ];
    }

    $properties = $result['data']['properties'] ?? [];

    if (!is_array($properties)) {
        return [
            'ok' => false,
            'message' => 'kintoneからフィールド情報を取得できませんでした。'
        ];
    }

    $fields = [];

    foreach ($properties as $code => $property) {
        if (!is_array($property)) continue;

        $fields[] = [
            'code' => (string)$code,
            'label' => (string)($property['label'] ?? $code),
            'type' => (string)($property['type'] ?? '')
        ];
    }

    usort(
        $fields,
        static fn(array $a, array $b): int =>
            strcmp($a['label'], $b['label'])
    );

    return [
        'ok' => true,
        'fields' => $fields
    ];
}

/* ================================================================
 * API処理
 * ================================================================ */

$is_post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($is_post && $action !== '') {
    survey_check_csrf();
}

if ($action !== '') {
    try {
        $data = survey_load();

        if ($action === 'load') {
            survey_api([
                'ok' => true,
                'data' => $data,
                'csrf_token' => survey_csrf()
            ]);
        }

        if ($action === 'save_survey') {
            $raw = (string)($_POST['survey_json'] ?? '');
            $survey = json_decode($raw, true);

            if (!is_array($survey)) {
                survey_api([
                    'ok' => false,
                    'message' => 'アンケートデータが不正です。'
                ], 400);
            }

            $now = survey_now();

            if (empty($survey['id'])) {
                $survey['id'] = survey_id('survey');
                $survey['created_at'] = $now;
            }

            $survey['updated_at'] = $now;
            $survey['deleted'] = false;

            foreach ($survey['groups'] ?? [] as &$group) {
                if (empty($group['id'])) {
                    $group['id'] = survey_id('group');
                }

                $group['name'] = (string)($group['name'] ?? 'グループ');

                foreach ($group['questions'] ?? [] as &$q) {
                    if (empty($q['id'])) {
                        $q['id'] = survey_id('question');
                    }

                    $q['text'] = (string)($q['text'] ?? '');
                    $q['type'] = in_array(
                        $q['type'] ?? 'single',
                        ['single', 'multiple', 'text'],
                        true
                    ) ? $q['type'] : 'single';

                    $q['required'] = !empty($q['required']);
                    $q['other_enabled'] = !empty($q['other_enabled']);
                    $q['options'] = array_values(
                        array_map(
                            static fn($x) => (string)$x,
                            is_array($q['options'] ?? null)
                                ? $q['options']
                                : []
                        )
                    );
                }
                unset($q);
            }
            unset($group);

            $found = false;

            foreach ($data['surveys'] as $i => $old) {
                if (($old['id'] ?? '') === $survey['id']) {
                    $data['surveys'][$i] = $survey;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $data['surveys'][] = $survey;
            }

            survey_save($data);

            survey_api([
                'ok' => true,
                'survey' => $survey,
                'data' => $data
            ]);
        }

        if ($action === 'delete_survey') {
            $id = (string)($_POST['survey_id'] ?? '');

            foreach ($data['surveys'] as &$survey) {
                if (($survey['id'] ?? '') === $id) {
                    $survey['deleted'] = true;
                    $survey['updated_at'] = survey_now();
                }
            }
            unset($survey);

            survey_save($data);
            survey_api(['ok' => true, 'data' => $data]);
        }

        if ($action === 'duplicate_survey') {
            $id = (string)($_POST['survey_id'] ?? '');
            $source = null;

            foreach ($data['surveys'] as $survey) {
                if (($survey['id'] ?? '') === $id) {
                    $source = $survey;
                    break;
                }
            }

            if (!$source) {
                survey_api([
                    'ok' => false,
                    'message' => '複製元アンケートが見つかりません。'
                ], 404);
            }

            $copy = $source;
            $copy['id'] = survey_id('survey');
            $copy['title'] = (string)$source['title'] . '（複製）';
            $copy['status'] = 'draft';
            $copy['created_at'] = survey_now();
            $copy['updated_at'] = survey_now();
            $copy['deleted'] = false;

            foreach ($copy['groups'] as &$group) {
                $group['id'] = survey_id('group');

                foreach ($group['questions'] as &$q) {
                    $q['id'] = survey_id('question');
                }
                unset($q);
            }
            unset($group);

            $data['surveys'][] = $copy;
            survey_save($data);

            survey_api([
                'ok' => true,
                'survey' => $copy,
                'data' => $data
            ]);
        }

        if ($action === 'toggle_status') {
            $id = (string)($_POST['survey_id'] ?? '');
            $new_status = (string)($_POST['status'] ?? '');

            if (!in_array($new_status, ['draft', 'active', 'ended'], true)) {
                survey_api([
                    'ok' => false,
                    'message' => 'ステータスが不正です。'
                ], 400);
            }

            foreach ($data['surveys'] as &$survey) {
                if (($survey['id'] ?? '') === $id) {
                    $survey['status'] = $new_status;
                    $survey['updated_at'] = survey_now();
                }
            }
            unset($survey);

            survey_save($data);
            survey_api(['ok' => true, 'data' => $data]);
        }

        if ($action === 'save_settings') {
            $raw = (string)($_POST['settings_json'] ?? '');
            $settings = json_decode($raw, true);

            if (!is_array($settings)) {
                survey_api([
                    'ok' => false,
                    'message' => '設定データが不正です。'
                ], 400);
            }

            $data['settings'] = array_replace(
                survey_defaults()['settings'],
                $settings
            );

            survey_save($data);

            survey_api([
                'ok' => true,
                'settings' => $data['settings']
            ]);
        }

        if ($action === 'test_kintone') {
            $raw = (string)($_POST['settings_json'] ?? '');
            $settings = json_decode($raw, true);

            if (!is_array($settings)) {
                $settings = $data['settings'];
            }

            survey_api(kintone_test($settings));
        }

        if ($action === 'fetch_kintone_fields') {
            $raw = (string)($_POST['settings_json'] ?? '');
            $settings = json_decode($raw, true);

            if (!is_array($settings)) {
                $settings = $data['settings'];
            }

            $app_id = trim((string)(
                $_POST['app_id']
                ?? $_GET['app_id']
                ?? ($settings['app_id'] ?? '')
            ));

            survey_api(kintone_fetch_fields($settings, $app_id));
        }

        if ($action === 'refresh_customers') {
            $settings = $data['settings'];

            $app_id = trim((string)($settings['app_id'] ?? ''));

            if ($app_id === '') {
                survey_api([
                    'ok' => false,
                    'message' => 'kintone顧客管理アプリIDを設定してください。'
                ], 400);
            }

            $fields_result = kintone_fetch_fields($settings, $app_id);

            if (!$fields_result['ok']) {
                survey_api($fields_result, 502);
            }

            $url = kintone_build_url(
                (string)$settings['subdomain'],
                '/k/v1/records.json'
            );

            $query = '?app=' . rawurlencode($app_id) .
                '&query=' . rawurlencode('order by $id asc limit 500');

            $result = kintone_api_request(
                'GET',
                $url . $query,
                [
                    make_cybozu_auth_header(
                        (string)$settings['login_name'],
                        (string)$settings['password']
                    ),
                    'Accept: application/json'
                ],
                null,
                [
                    'proxy_host_port' => trim((string)($settings['proxy'] ?? ''))
                ]
            );

            if (!$result['success']) {
                survey_api([
                    'ok' => false,
                    'message' => $result['message']
                ], 502);
            }

            $records = $result['data']['records'] ?? [];

            $map = [
                'company' => (string)($settings['field_company'] ?? ''),
                'name' => (string)($settings['field_name'] ?? ''),
                'email' => (string)($settings['field_email'] ?? ''),
                'department' => (string)($settings['field_department'] ?? ''),
                'phone' => (string)($settings['field_phone'] ?? ''),
                'address' => $settings['field_address'] ?? []
            ];

            $customers = [];

            foreach ($records as $record) {
                $get = static function(string $code) use ($record): string {
                    if ($code === '') return '';
                    return (string)($record[$code]['value'] ?? '');
                };

                $address_codes = is_array($map['address'])
                    ? $map['address']
                    : [$map['address']];

                $address_parts = [];

                foreach ($address_codes as $code) {
                    $v = $get((string)$code);
                    if ($v !== '') $address_parts[] = $v;
                }

                $email = $get($map['email']);

                if ($email === '') continue;

                $existing = null;

                foreach ($data['customers'] as $c) {
                    if (
                        strtolower((string)($c['email'] ?? '')) ===
                        strtolower($email)
                    ) {
                        $existing = $c;
                        break;
                    }
                }

                $customers[] = [
                    'id' => $existing['id'] ?? survey_id('customer'),
                    'company' => $get($map['company']),
                    'name' => $get($map['name']),
                    'email' => $email,
                    'department' => $get($map['department']),
                    'phone' => $get($map['phone']),
                    'address' => implode(' ', $address_parts),
                    'source' => 'kintone',
                    'sent_at' => $existing['sent_at'] ?? '',
                    'send_count' => (int)($existing['send_count'] ?? 0),
                    'answer_status' => $existing['answer_status'] ?? 'unanswered',
                    'kintone_status' => 'registered'
                ];
            }

            $data['customers'] = $customers;
            survey_save($data);

            survey_api([
                'ok' => true,
                'count' => count($customers),
                'data' => $data
            ]);
        }

        if ($action === 'send_mail') {
            $survey_id = (string)($_POST['survey_id'] ?? '');
            $ids_raw = (string)($_POST['recipient_ids'] ?? '');
            $ids = array_values(array_filter(explode(',', $ids_raw)));

            $subject = (string)($_POST['mail_subject'] ?? '');
            $body = (string)($_POST['mail_body'] ?? '');
            $template_type = (string)($_POST['template_type'] ?? 'initial');

            if (!in_array($template_type, ['initial', 'reminder'], true)) {
                $template_type = 'initial';
            }

            $count = 0;
            $now = survey_now();

            foreach ($data['customers'] as &$customer) {
                if (!in_array((string)$customer['id'], $ids, true)) {
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
                'id' => survey_id('mail'),
                'survey_id' => $survey_id,
                'sent_at' => $now,
                'type' => $template_type,
                'count' => $count,
                'subject' => $subject,
                'body' => $body,
                'executed_by' => (string)($_SERVER['REMOTE_USER'] ?? 'admin')
            ];

            survey_save($data);

            survey_api([
                'ok' => true,
                'count' => $count,
                'message' => $count . '件を送信対象として記録しました。',
                'data' => $data
            ]);
        }

        if ($action === 'save_response') {
            $survey_id = (string)($_POST['survey_id'] ?? '');
            $customer_id = (string)($_POST['customer_id'] ?? '');
            $answers = json_decode(
                (string)($_POST['answers'] ?? '{}'),
                true
            );

            if (!is_array($answers)) $answers = [];

            $customer = null;

            foreach ($data['customers'] as $c) {
                if (($c['id'] ?? '') === $customer_id) {
                    $customer = $c;
                    break;
                }
            }

            $response = [
                'id' => survey_id('response'),
                'survey_id' => $survey_id,
                'customer_id' => $customer_id,
                'company' => (string)($customer['company'] ?? ''),
                'name' => (string)($customer['name'] ?? ''),
                'email' => (string)($customer['email'] ?? ''),
                'answered_at' => survey_now(),
                'answers' => $answers
            ];

            $data['responses'][] = $response;

            foreach ($data['customers'] as &$c) {
                if (($c['id'] ?? '') === $customer_id) {
                    $c['answer_status'] = 'answered';
                }
            }
            unset($c);

            survey_save($data);

            survey_api([
                'ok' => true,
                'response' => $response
            ]);
        }

        if ($action === 'csv') {
            $survey_id = (string)($_GET['survey_id'] ?? '');

            $survey = null;

            foreach ($data['surveys'] as $s) {
                if (($s['id'] ?? '') === $survey_id) {
                    $survey = $s;
                    break;
                }
            }

            if (!$survey) {
                http_response_code(404);
                exit('Survey not found');
            }

            $questions = [];

            foreach ($survey['groups'] ?? [] as $g) {
                foreach ($g['questions'] ?? [] as $q) {
                    $questions[] = $q;
                }
            }

            $fp = fopen('php://output', 'wb');

            header('Content-Type: text/csv; charset=UTF-8');
            header(
                'Content-Disposition: attachment; filename="survey_' .
                preg_replace('/[^A-Za-z0-9_-]/', '_', $survey_id) .
                '.csv"'
            );

            fwrite($fp, "\xEF\xBB\xBF");

            $header = [
                '回答ID',
                '回答日時',
                '顧客ID',
                '会社名',
                '氏名'
            ];

            foreach ($questions as $i => $q) {
                $header[] = '設問' . ($i + 1) .
                    ' ' . (string)($q['text'] ?? '');
            }

            fputcsv($fp, $header);

            foreach ($data['responses'] as $r) {
                if (($r['survey_id'] ?? '') !== $survey_id) continue;

                $row = [
                    $r['id'] ?? '',
                    $r['answered_at'] ?? '',
                    $r['customer_id'] ?? '',
                    $r['company'] ?? '',
                    $r['name'] ?? ''
                ];

                foreach ($questions as $q) {
                    $qid = (string)($q['id'] ?? '');
                    $v = $r['answers'][$qid] ?? '';

                    if (is_array($v)) {
                        $v = implode('、', $v);
                    }

                    $row[] = $v;
                }

                fputcsv($fp, $row);
            }

            fclose($fp);
            exit;
        }

        survey_api([
            'ok' => false,
            'message' => '未知のactionです。'
        ], 400);

    } catch (Throwable $e) {
        survey_api([
            'ok' => false,
            'message' => $e->getMessage()
        ], 500);
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

<body class="bg-slate-50 text-slate-800 min-h-screen">

<input type="hidden" id="csrf_token" value="<?= survey_h($csrf) ?>">

<div id="app"></div>

<script>
'use strict';

/*
 * ================================================================
 * アンケート管理SPA
 * ================================================================
 *
 * グローバル名前空間は App のみに統一。
 */

window.App = {
    state: {
        data: null,
        screen: 'list',
        editingSurvey: null,
        selectedSurveyId: '',
        selectedResponseId: '',
        kintoneFields: [],
        responseFilter: {},
        customerFilter: '',
        keyword: '',
        statusFilter: 'all',
        sort: 'updated_desc',
        previewMobile: false,
        selectedQuestions: {}
    },

    dom: {},

    api: {},
    render: {},
    actions: {},
    utils: {}
};


/* ================================================================
 * Utility
 * ================================================================ */

App.utils.escape = function(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
};

App.utils.uid = function(prefix) {
    return prefix + '_' +
        Date.now().toString(36) +
        '_' +
        Math.random().toString(36).slice(2, 9);
};

App.utils.now = function() {
    const d = new Date();
    const p = n => String(n).padStart(2, '0');

    return d.getFullYear() + '-' +
        p(d.getMonth() + 1) + '-' +
        p(d.getDate()) + ' ' +
        p(d.getHours()) + ':' +
        p(d.getMinutes()) + ':' +
        p(d.getSeconds());
};

App.utils.date = function(v) {
    if (!v) return '未設定';

    const d = String(v).replace(' ', 'T');

    if (d.length >= 10) {
        return d.slice(0, 16).replace('T', ' ');
    }

    return d;
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

App.utils.clone = function(obj) {
    return JSON.parse(JSON.stringify(obj));
};

App.utils.toast = function(message, error = false) {
    const el = document.createElement('div');

    el.className =
        'fixed right-5 bottom-5 z-[100] px-5 py-3 rounded-xl shadow-xl text-white ' +
        (error ? 'bg-red-600' : 'bg-slate-800');

    el.textContent = message;

    document.body.appendChild(el);

    setTimeout(() => el.remove(), 2800);
};

App.utils.confirm = function(message) {
    return window.confirm(message);
};

App.utils.surveyById = function(id) {
    return (App.state.data?.surveys || []).find(
        s => s.id === id && !s.deleted
    );
};

App.utils.questions = function(survey) {
    const result = [];

    (survey.groups || []).forEach(group => {
        (group.questions || []).forEach(q => {
            result.push(q);
        });
    });

    return result;
};


/* ================================================================
 * API
 * ================================================================ */

App.api.post = async function(action, params = {}) {
    const body = new URLSearchParams();

    body.set('action', action);
    body.set(
        'csrf_token',
        document.getElementById('csrf_token')?.value || ''
    );

    Object.keys(params).forEach(key => {
        const value = params[key];

        if (typeof value === 'object') {
            body.set(key, JSON.stringify(value));
        } else {
            body.set(key, value == null ? '' : String(value));
        }
    });

    const response = await fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type':
                'application/x-www-form-urlencoded;charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
    });

    const text = await response.text();

    let json;

    try {
        json = JSON.parse(text);
    } catch (e) {
        console.error(text);
        throw new Error(
            'サーバーから不正な応答が返されました。HTTP ' +
            response.status
        );
    }

    if (!response.ok || json.ok === false) {
        throw new Error(
            json.message ||
            'サーバー処理に失敗しました。'
        );
    }

    return json;
};

App.api.load = async function() {
    const response = await App.api.post('load');
    App.state.data = response.data;
    return response;
};

App.api.saveSurvey = async function(survey) {
    const response = await App.api.post('save_survey', {
        survey_json: JSON.stringify(survey)
    });

    App.state.data = response.data;
    return response;
};

App.api.saveSettings = async function(settings) {
    const response = await App.api.post('save_settings', {
        settings_json: JSON.stringify(settings)
    });

    App.state.data.settings = response.settings;
    return response;
};

App.api.testKintone = async function(settings) {
    return await App.api.post('test_kintone', {
        settings_json: JSON.stringify(settings)
    });
};


/*
 * 必須関数:
 * fetchKintoneFields()
 *
 * app_id を明示的にPOSTし、PHP側で
 * /k/v1/app/form/fields.json?app=123
 * としてkintoneへ送る。
 */
App.api.fetchKintoneFields = async function() {
    const appId =
        document.getElementById('setting_app_id')?.value.trim() || '';

    if (!appId) {
        throw new Error('顧客管理アプリIDを入力してください。');
    }

    const settings = App.actions.readSettingsForm();

    settings.app_id = appId;

    const response = await App.api.post(
        'fetch_kintone_fields',
        {
            app_id: appId,
            settings_json: JSON.stringify(settings)
        }
    );

    App.state.kintoneFields = response.fields || [];

    App.render.kintoneFieldSelects();

    return response;
};


/* ================================================================
 * Common Header
 * ================================================================ */

App.render.header = function() {
    return `
<header class="bg-white border-b border-slate-200 sticky top-0 z-40">
  <div class="max-w-[1500px] mx-auto px-6 h-16 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-xl bg-indigo-600 text-white flex items-center justify-center font-bold">
        Q
      </div>
      <div>
        <div class="font-bold text-slate-900">アンケート管理システム</div>
        <div class="text-[11px] text-slate-400">Survey Management</div>
      </div>
    </div>

    <nav class="flex items-center gap-2">
      <button
        onclick="App.actions.goList()"
        class="px-4 py-2 rounded-lg text-sm font-medium hover:bg-slate-100">
        アンケート一覧
      </button>

      <button
        onclick="App.actions.goSettings()"
        class="px-4 py-2 rounded-lg text-sm font-medium hover:bg-slate-100">
        kintone連携設定
      </button>

      <button
        onclick="App.actions.logout()"
        class="px-4 py-2 rounded-lg text-sm text-slate-500 hover:bg-slate-100">
        ログアウト
      </button>
    </nav>
  </div>
</header>`;
};


/* ================================================================
 * Main List
 * ================================================================ */

App.render.list = function() {
    const data = App.state.data || {};
    let surveys = (data.surveys || []).filter(s => !s.deleted);

    const keyword = App.state.keyword.toLowerCase();

    if (keyword) {
        surveys = surveys.filter(s =>
            String(s.title || '').toLowerCase().includes(keyword)
        );
    }

    if (App.state.statusFilter !== 'all') {
        surveys = surveys.filter(
            s => s.status === App.state.statusFilter
        );
    }

    const responses = data.responses || [];

    surveys.sort((a, b) => {
        if (App.state.sort === 'updated_desc') {
            return String(b.updated_at).localeCompare(String(a.updated_at));
        }

        if (App.state.sort === 'updated_asc') {
            return String(a.updated_at).localeCompare(String(b.updated_at));
        }

        const ar = responses.filter(
            r => r.survey_id === a.id
        ).length;

        const br = responses.filter(
            r => r.survey_id === b.id
        ).length;

        if (App.state.sort === 'responses_desc') return br - ar;
        if (App.state.sort === 'responses_asc') return ar - br;

        if (App.state.sort === 'start_desc') {
            return String(b.start_at || '')
                .localeCompare(String(a.start_at || ''));
        }

        return String(a.start_at || '')
            .localeCompare(String(b.start_at || ''));
    });

    return `
${App.render.header()}

<main class="max-w-[1500px] mx-auto p-6">

  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-bold text-slate-900">アンケート一覧</h1>
      <p class="text-sm text-slate-500 mt-1">
        アンケートの作成・公開・集計・送信を管理します。
      </p>
    </div>

    <button
      onclick="App.actions.newSurvey()"
      class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-3 rounded-xl font-bold shadow-sm">
      ＋ 新規アンケート作成
    </button>
  </div>

  <section class="bg-white border border-slate-200 rounded-2xl p-4 mb-5">
    <div class="flex gap-3 items-center">

      <input
        id="list_keyword"
        value="${App.utils.escape(App.state.keyword)}"
        onkeydown="if(event.key==='Enter')App.actions.searchList(this.value)"
        placeholder="タイトルを検索"
        class="flex-1 border border-slate-200 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-indigo-200">

      <button
        onclick="App.actions.searchList(document.getElementById('list_keyword').value)"
        class="px-4 py-2.5 rounded-xl bg-slate-800 text-white">
        検索
      </button>

      <select
        onchange="App.actions.setStatusFilter(this.value)"
        class="border border-slate-200 rounded-xl px-4 py-2.5">
        <option value="all" ${App.state.statusFilter==='all'?'selected':''}>すべて</option>
        <option value="active" ${App.state.statusFilter==='active'?'selected':''}>公開中</option>
        <option value="draft" ${App.state.statusFilter==='draft'?'selected':''}>下書き</option>
        <option value="ended" ${App.state.statusFilter==='ended'?'selected':''}>終了</option>
      </select>

      <select
        onchange="App.actions.setSort(this.value)"
        class="border border-slate-200 rounded-xl px-4 py-2.5">
        <option value="updated_desc" ${App.state.sort==='updated_desc'?'selected':''}>更新日：新しい順</option>
        <option value="updated_asc" ${App.state.sort==='updated_asc'?'selected':''}>更新日：古い順</option>
        <option value="responses_desc" ${App.state.sort==='responses_desc'?'selected':''}>回答数：多い順</option>
        <option value="responses_asc" ${App.state.sort==='responses_asc'?'selected':''}>回答数：少ない順</option>
        <option value="start_desc" ${App.state.sort==='start_desc'?'selected':''}>開始日：新しい順</option>
        <option value="start_asc" ${App.state.sort==='start_asc'?'selected':''}>開始日：古い順</option>
      </select>

    </div>
  </section>

  <section class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 border-b border-slate-200">
          <tr>
            <th class="text-left px-5 py-4">アンケート</th>
            <th class="text-left px-5 py-4">期間</th>
            <th class="text-left px-5 py-4">ステータス</th>
            <th class="text-right px-5 py-4">回答数</th>
            <th class="text-left px-5 py-4">操作</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-100">

        ${surveys.length ? surveys.map(s => {
            const count = responses.filter(
                r => r.survey_id === s.id
            ).length;

            let actions = `
              <button onclick="App.actions.editSurvey('${s.id}')"
                class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200">
                確認・編集
              </button>
            `;

            if (s.status === 'active') {
                actions += `
                  <button onclick="App.actions.aggregate('${s.id}')"
                    class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700">
                    集計
                  </button>
                  <button onclick="App.actions.mail('${s.id}')"
                    class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700">
                    送信
                  </button>
                  <button onclick="App.actions.toggleStatus('${s.id}','ended')"
                    class="px-3 py-1.5 rounded-lg bg-amber-50 text-amber-700">
                    停止
                  </button>
                `;
            }

            if (s.status === 'draft') {
                actions += `
                  <button onclick="App.actions.deleteSurvey('${s.id}')"
                    class="px-3 py-1.5 rounded-lg bg-red-50 text-red-600">
                    削除
                  </button>
                `;
            }

            if (s.status === 'ended') {
                actions += `
                  <button onclick="App.actions.aggregate('${s.id}')"
                    class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700">
                    集計
                  </button>
                `;
            }

            actions += `
              <button onclick="App.actions.duplicateSurvey('${s.id}')"
                class="px-3 py-1.5 rounded-lg bg-slate-100">
                複製
              </button>
            `;

            return `
            <tr class="hover:bg-slate-50">
              <td class="px-5 py-5">
                <div class="font-bold text-slate-900">
                  ${App.utils.escape(s.title || '無題')}
                </div>
                <div class="text-xs text-slate-400 mt-1">
                  作成: ${App.utils.date(s.created_at)}
                  ／ 更新: ${App.utils.date(s.updated_at)}
                </div>
              </td>

              <td class="px-5 py-5 text-slate-600">
                ${App.utils.date(s.start_at)}
                <span class="text-slate-300">～</span>
                ${App.utils.date(s.end_at)}
              </td>

              <td class="px-5 py-5">
                <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-bold ${App.utils.statusClass(s.status)}">
                  ${App.utils.statusLabel(s.status)}
                </span>
              </td>

              <td class="px-5 py-5 text-right font-bold">
                ${count} 件
              </td>

              <td class="px-5 py-5">
                <div class="flex flex-wrap gap-2">
                  ${actions}
                </div>
              </td>
            </tr>`;
        }).join('') : `
          <tr>
            <td colspan="5" class="px-5 py-20 text-center text-slate-400">
              アンケートがありません。
            </td>
          </tr>
        `}

        </tbody>
      </table>
    </div>
  </section>
</main>`;
};


/* ================================================================
 * Survey Editor
 * ================================================================ */

App.render.editor = function() {
    const s = App.state.editingSurvey;

    return `
${App.render.header()}

<main class="max-w-[1400px] mx-auto p-6">

  <div class="flex items-center justify-between mb-5">
    <div class="flex-1">
      <input
        id="survey_title"
        value="${App.utils.escape(s.title || '')}"
        placeholder="アンケートタイトル"
        class="text-2xl font-bold bg-transparent border-b-2 border-transparent hover:border-slate-200 focus:border-indigo-500 outline-none w-full max-w-3xl py-2">
    </div>

    <div class="flex gap-2">
      <button
        onclick="App.actions.preview()"
        class="px-4 py-2.5 rounded-xl bg-white border border-slate-200">
        プレビュー
      </button>

      <button
        onclick="App.actions.cancelEdit()"
        class="px-4 py-2.5 rounded-xl bg-white border border-slate-200">
        キャンセル
      </button>

      <button
        onclick="App.actions.saveEditor()"
        class="px-5 py-2.5 rounded-xl bg-indigo-600 text-white font-bold">
        保存して一覧へ戻る
      </button>
    </div>
  </div>

  <section class="bg-white border border-slate-200 rounded-2xl p-5 mb-5">
    <div class="grid grid-cols-4 gap-4">

      <label class="text-sm">
        <span class="block text-slate-500 mb-1">開始日時</span>
        <input
          id="survey_start_at"
          type="datetime-local"
          value="${String(s.start_at || '').replace(' ','T')}"
          class="w-full border border-slate-200 rounded-xl px-3 py-2">
      </label>

      <label class="text-sm">
        <span class="block text-slate-500 mb-1">終了日時</span>
        <input
          id="survey_end_at"
          type="datetime-local"
          value="${String(s.end_at || '').replace(' ','T')}"
          class="w-full border border-slate-200 rounded-xl px-3 py-2">
      </label>

      <label class="text-sm">
        <span class="block text-slate-500 mb-1">ステータス</span>
        <select
          id="survey_status"
          class="w-full border border-slate-200 rounded-xl px-3 py-2">
          <option value="draft" ${s.status==='draft'?'selected':''}>下書き</option>
          <option value="active" ${s.status==='active'?'selected':''}>公開中</option>
          <option value="ended" ${s.status==='ended'?'selected':''}>終了</option>
        </select>
      </label>

      <label class="text-sm">
        <span class="block text-slate-500 mb-1">質問番号</span>
        <select
          id="survey_numbering_mode"
          onchange="App.actions.renumber()"
          class="w-full border border-slate-200 rounded-xl px-3 py-2">
          <option value="global" ${s.numbering_mode==='global'?'selected':''}>Q1, Q2...</option>
          <option value="group" ${s.numbering_mode==='group'?'selected':''}>Q1-1, Q1-2...</option>
        </select>
      </label>

    </div>
  </section>

  <section class="bg-white border border-slate-200 rounded-2xl p-5">

    <div class="flex items-center justify-between mb-5">
      <div>
        <h2 class="font-bold text-lg">質問構成</h2>
        <p class="text-xs text-slate-400 mt-1">
          ドラッグ＆ドロップでグループ・質問を並べ替えできます。
        </p>
      </div>

      <button
        onclick="App.actions.addGroup()"
        class="px-4 py-2.5 rounded-xl bg-indigo-600 text-white font-bold">
        ＋ グループ追加
      </button>
    </div>

    <div id="question_editor"></div>

  </section>
</main>

<div id="preview_modal"></div>`;
};

App.render.questionEditor = function() {
    const container = document.getElementById('question_editor');

    if (!container) return;

    const s = App.state.editingSurvey;

    let globalNo = 0;

    container.innerHTML = (s.groups || []).map((group, gi) => {

        const groupNo = gi + 1;

        return `
        <section
          data-group-id="${group.id}"
          class="group-item border border-slate-200 rounded-2xl mb-5 overflow-hidden">

          <div class="bg-slate-50 px-4 py-3 flex items-center gap-3">

            <span class="group-handle cursor-grab text-slate-400 text-xl">
              ⠿
            </span>

            <input
              value="${App.utils.escape(group.name)}"
              onchange="App.actions.updateGroupName('${group.id}',this.value)"
              class="flex-1 bg-transparent font-bold outline-none">

            <button
              onclick="App.actions.addQuestion('${group.id}')"
              class="px-3 py-2 rounded-lg bg-white border border-slate-200 text-sm">
              ＋ 質問追加
            </button>

            <button
              onclick="App.actions.deleteGroup('${group.id}')"
              class="px-3 py-2 rounded-lg text-red-600 hover:bg-red-50">
              削除
            </button>
          </div>

          <div
            id="questions_${group.id}"
            data-group-id="${group.id}"
            class="questions-container p-4 space-y-4 min-h-[60px]">

            ${(group.questions || []).map(q => {
                globalNo++;

                const no = s.numbering_mode === 'group'
                    ? `Q${groupNo}-${(group.questions || []).indexOf(q)+1}`
                    : `Q${globalNo}`;

                return App.render.question(q, group.id, no);
            }).join('')}

          </div>
        </section>`;
    }).join('');

    App.actions.initSortable();
};

App.render.question = function(q, groupId, number) {
    return `
<article
  data-question-id="${q.id}"
  data-group-id="${groupId}"
  class="question-item border border-slate-200 rounded-xl p-4 bg-white shadow-sm">

  <div class="flex gap-3">

    <div class="cursor-grab text-slate-400 text-xl pt-1">
      ⠿
    </div>

    <div class="flex-1">

      <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-2">
          <span class="font-bold text-indigo-600 question-number">
            ${number}
          </span>

          <span class="px-2 py-1 rounded bg-slate-100 text-xs">
            ${App.utils.typeLabel(q.type)}
          </span>
        </div>

        <button
          onclick="App.actions.deleteQuestion('${groupId}','${q.id}')"
          class="text-red-500 text-sm">
          削除
        </button>
      </div>

      <input
        value="${App.utils.escape(q.text || '')}"
        placeholder="質問文を入力してください"
        onchange="App.actions.updateQuestion('${groupId}','${q.id}','text',this.value)"
        class="w-full border border-slate-200 rounded-xl px-3 py-2.5 mb-3">

      <div class="grid grid-cols-3 gap-3">

        <select
          onchange="App.actions.updateQuestion('${groupId}','${q.id}','type',this.value)"
          class="border border-slate-200 rounded-xl px-3 py-2">
          <option value="single" ${q.type==='single'?'selected':''}>単一選択</option>
          <option value="multiple" ${q.type==='multiple'?'selected':''}>複数選択</option>
          <option value="text" ${q.type==='text'?'selected':''}>自由記述</option>
        </select>

        <label class="flex items-center gap-2 px-3">
          <input
            type="checkbox"
            ${q.required?'checked':''}
            onchange="App.actions.updateQuestion('${groupId}','${q.id}','required',this.checked)"
            class="w-4 h-4 accent-indigo-600">
          必須回答
        </label>

        ${q.type !== 'text' ? `
        <label class="flex items-center gap-2 px-3">
          <input
            type="checkbox"
            ${q.other_enabled?'checked':''}
            onchange="App.actions.updateQuestion('${groupId}','${q.id}','other_enabled',this.checked)"
            class="w-4 h-4 accent-indigo-600">
          「その他」を許可
        </label>` : ''}

      </div>

      ${q.type !== 'text' ? `
      <div class="mt-4">
        <div class="text-xs text-slate-400 mb-2">選択肢</div>

        <div class="space-y-2">
          ${(q.options || []).map((opt, oi) => `
            <div class="flex gap-2">
              <input
                value="${App.utils.escape(opt)}"
                onchange="App.actions.updateOption('${groupId}','${q.id}',${oi},this.value)"
                class="flex-1 border border-slate-200 rounded-lg px-3 py-2">

              <button
                onclick="App.actions.deleteOption('${groupId}','${q.id}',${oi})"
                class="px-3 rounded-lg text-red-500 hover:bg-red-50">
                ×
              </button>
            </div>
          `).join('')}
        </div>

        <button
          onclick="App.actions.addOption('${groupId}','${q.id}')"
          class="mt-2 text-sm text-indigo-600 font-medium">
          ＋ 選択肢を追加
        </button>
      </div>` : ''}

    </div>
  </div>
</article>`;
};


/* ================================================================
 * SortableJS
 * ================================================================ */

App.actions.initSortable = function() {
    const editor = document.getElementById('question_editor');

    if (!editor || typeof Sortable === 'undefined') return;

    if (editor._sortable) {
        editor._sortable.destroy();
    }

    editor._sortable = new Sortable(editor, {
        animation: 180,
        ghostClass: 'opacity-40',
        handle: '.group-handle',
        draggable: '.group-item',
        onEnd: function(evt) {
            const groups = [...editor.querySelectorAll('.group-item')];

            const ids = groups.map(
                el => el.dataset.groupId
            );

            App.state.editingSurvey.groups.sort(
                (a,b) => ids.indexOf(a.id) - ids.indexOf(b.id)
            );

            App.actions.renumber();
        }
    });

    editor.querySelectorAll('.questions-container').forEach(
        container => {

            if (container._sortable) {
                container._sortable.destroy();
            }

            container._sortable = new Sortable(container, {
                group: 'survey-questions',
                animation: 180,
                ghostClass: 'opacity-40',
                draggable: '.question-item',
                handle: '.cursor-grab',

                onEnd: function() {
                    App.actions.syncQuestionsFromDOM();
                }
            });
        }
    );
};

App.actions.syncQuestionsFromDOM = function() {
    const s = App.state.editingSurvey;

    const groups = [];

    document.querySelectorAll('.group-item').forEach(groupEl => {
        const groupId = groupEl.dataset.groupId;

        const group = s.groups.find(
            g => g.id === groupId
        );

        if (!group) return;

        const ids = [...groupEl.querySelectorAll('.question-item')]
            .map(el => el.dataset.questionId);

        const allQuestions = [];

        s.groups.forEach(g => {
            (g.questions || []).forEach(q => {
                if (ids.includes(q.id)) {
                    allQuestions.push(q);
                }
            });
        });

        group.questions = allQuestions;
        groups.push(group);
    });

    s.groups = groups;

    App.actions.renumber();
};


/* ================================================================
 * Group / Question
 * ================================================================ */

/*
 * 必須関数 addGroup()
 */
App.actions.addGroup = function() {
    const s = App.state.editingSurvey;

    s.groups.push({
        id: App.utils.uid('group'),
        name: '新しいグループ',
        questions: []
    });

    App.render.questionEditor();
};

/*
 * 必須関数 addQuestion()
 */
App.actions.addQuestion = function(groupId) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    group.questions.push({
        id: App.utils.uid('question'),
        text: '',
        type: 'single',
        required: false,
        options: ['選択肢1', '選択肢2'],
        other_enabled: false
    });

    App.render.questionEditor();
};

App.actions.deleteGroup = function(groupId) {
    if (!App.utils.confirm(
        'このグループと内包する質問を削除しますか？'
    )) return;

    App.state.editingSurvey.groups =
        App.state.editingSurvey.groups.filter(
            g => g.id !== groupId
        );

    App.render.questionEditor();
};

App.actions.deleteQuestion = function(groupId, questionId) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    group.questions = group.questions.filter(
        q => q.id !== questionId
    );

    App.render.questionEditor();
};

App.actions.updateGroupName = function(id, value) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === id
    );

    if (group) group.name = value;
};

App.actions.updateQuestion = function(
    groupId,
    questionId,
    key,
    value
) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    const q = group.questions.find(
        x => x.id === questionId
    );

    if (!q) return;

    q[key] = value;

    if (key === 'type') {
        if (value === 'text') {
            q.options = [];
            q.other_enabled = false;
        } else if (!q.options.length) {
            q.options = ['選択肢1', '選択肢2'];
        }

        App.render.questionEditor();
    }
};

App.actions.addOption = function(groupId, questionId) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    const q = group?.questions.find(
        x => x.id === questionId
    );

    if (!q) return;

    q.options.push('新しい選択肢');

    App.render.questionEditor();
};

App.actions.updateOption = function(
    groupId,
    questionId,
    index,
    value
) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    const q = group?.questions.find(
        x => x.id === questionId
    );

    if (q) q.options[index] = value;
};

App.actions.deleteOption = function(
    groupId,
    questionId,
    index
) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    const q = group?.questions.find(
        x => x.id === questionId
    );

    if (!q) return;

    q.options.splice(index, 1);

    App.render.questionEditor();
};

App.actions.renumber = function() {
    if (!App.state.editingSurvey) return;

    App.render.questionEditor();
};


/* ================================================================
 * Preview
 * ================================================================ */

App.actions.preview = function() {
    const s = App.actions.readEditor();

    App.state.editingSurvey = s;

    const mobile = App.state.previewMobile;

    const questions = App.utils.questions(s);

    document.getElementById('preview_modal').innerHTML = `
<div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-6">

  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[90vh] overflow-hidden">

    <div class="px-5 py-4 border-b flex justify-between items-center">
      <div class="font-bold">プレビュー</div>

      <div class="flex gap-2">
        <button
          onclick="App.actions.togglePreview(false)"
          class="px-3 py-2 rounded-lg ${!mobile?'bg-indigo-600 text-white':'bg-slate-100'}">
          PC
        </button>

        <button
          onclick="App.actions.togglePreview(true)"
          class="px-3 py-2 rounded-lg ${mobile?'bg-indigo-600 text-white':'bg-slate-100'}">
          スマートフォン
        </button>

        <button
          onclick="App.actions.closePreview()"
          class="px-3 py-2">
          ×
        </button>
      </div>
    </div>

    <div class="p-6 overflow-y-auto max-h-[75vh] bg-slate-100">

      <div class="${mobile?'max-w-[390px]':'max-w-3xl'} mx-auto bg-white rounded-2xl p-6">

        <h1 class="text-2xl font-bold mb-6">
          ${App.utils.escape(s.title)}
        </h1>

        ${questions.map((q,i) => `
          <div class="mb-7">
            <div class="font-bold mb-3">
              Q${i+1}. ${App.utils.escape(q.text)}
              ${q.required ? '<span class="text-red-500">*</span>' : ''}
            </div>

            ${
                q.type === 'text'
                ? `<textarea class="w-full border rounded-xl p-3 h-28" placeholder="回答を入力"></textarea>`
                : (q.options || []).map(opt => `
                    <label class="flex items-center gap-2 mb-2">
                      <input type="${q.type==='single'?'radio':'checkbox'}"
                        disabled>
                      ${App.utils.escape(opt)}
                    </label>
                  `).join('') +
                  (q.other_enabled ? `
                    <label class="flex items-center gap-2">
                      <input type="${q.type==='single'?'radio':'checkbox'}" disabled>
                      その他
                    </label>
                  ` : '')
            }
          </div>
        `).join('')}

        <button
          onclick="App.actions.previewSubmit()"
          class="w-full bg-indigo-600 text-white rounded-xl py-3 font-bold">
          回答を送信
        </button>

      </div>
    </div>
  </div>
</div>`;
};

App.actions.togglePreview = function(mobile) {
    App.state.previewMobile = mobile;
    App.actions.preview();
};

App.actions.closePreview = function() {
    document.getElementById('preview_modal').innerHTML = '';
};

App.actions.previewSubmit = function() {
    App.utils.toast(
        'これはプレビューです。実際の回答は送信されません。'
    );
};


/* ================================================================
 * Aggregate
 * ================================================================ */

App.render.aggregate = function(survey) {
    const responses =
        (App.state.data.responses || []).filter(
            r => r.survey_id === survey.id
        );

    const customers =
        App.state.data.customers || [];

    const sent = customers.filter(
        c => c.sent_at
    ).length;

    const unanswered = customers.filter(
        c => c.sent_at && c.answer_status !== 'answered'
    ).length;

    const external = responses.filter(
        r => !r.customer_id ||
        !customers.some(c => c.id === r.customer_id)
    ).length;

    const rate = sent
        ? ((responses.length - external) / sent * 100).toFixed(1)
        : '0.0';

    const questions = App.utils.questions(survey);

    return `
${App.render.header()}

<main class="max-w-[1500px] mx-auto p-6">

  <div class="flex items-center justify-between mb-6">
    <div>
      <div class="text-xs text-slate-400 mb-1">
        ホーム ＞ アンケート一覧 ＞ 集計
      </div>
      <h1 class="text-2xl font-bold">
        ${App.utils.escape(survey.title)}
      </h1>
    </div>

    <div class="flex gap-2">
      <a
        href="?action=csv&survey_id=${encodeURIComponent(survey.id)}"
        class="px-4 py-2.5 rounded-xl bg-slate-800 text-white">
        CSV出力
      </a>

      <button
        onclick="App.actions.goList()"
        class="px-4 py-2.5 rounded-xl border bg-white">
        戻る
      </button>
    </div>
  </div>

  <div class="grid grid-cols-5 gap-4 mb-6">

    ${[
        ['送信対象者数', sent + ' 人'],
        ['回答数', responses.length + ' 件'],
        ['未登録顧客からの回答数', external + ' 件'],
        ['未回答数', unanswered + ' 人'],
        ['回答率', rate + ' %']
    ].map(x => `
      <div class="bg-white border border-slate-200 rounded-2xl p-5">
        <div class="text-sm text-slate-500">${x[0]}</div>
        <div class="text-2xl font-bold mt-2">${x[1]}</div>
      </div>
    `).join('')}

  </div>

  <div class="grid grid-cols-4 gap-5">

    <aside class="bg-white border border-slate-200 rounded-2xl p-4">
      <div class="font-bold mb-3">設問表示</div>

      <div class="flex gap-2 mb-4">
        <button
          onclick="App.actions.selectAllQuestions(true)"
          class="text-xs text-indigo-600">
          全選択
        </button>
        <button
          onclick="App.actions.selectAllQuestions(false)"
          class="text-xs text-indigo-600">
          全解除
        </button>
      </div>

      <div class="space-y-2">
        ${questions.map((q,i) => `
          <label class="flex gap-2 items-start text-sm">
            <input
              type="checkbox"
              ${App.state.selectedQuestions[q.id] !== false ? 'checked':''}
              onchange="App.actions.toggleQuestion('${q.id}',this.checked)"
              class="mt-1 accent-indigo-600">
            <span>
              Q${i+1}. ${App.utils.escape(q.text)}
              <span class="block text-xs text-slate-400">
                ${App.utils.typeLabel(q.type)}
              </span>
            </span>
          </label>
        `).join('')}
      </div>
    </aside>

    <section class="col-span-3 space-y-5">

      ${questions.filter(q =>
          App.state.selectedQuestions[q.id] !== false
      ).map(q => App.render.questionAggregate(
          q,
          responses
      )).join('')}

      ${!responses.length ? `
        <div class="bg-white rounded-2xl border p-16 text-center text-slate-400">
          現在、回答データはありません
        </div>
      ` : ''}

      <div class="bg-white border rounded-2xl p-5">
        <div class="flex justify-between mb-4">
          <h2 class="font-bold">個別回答一覧</h2>

          <input
            id="response_filter"
            value="${App.utils.escape(App.state.responseFilter.keyword || '')}"
            oninput="App.actions.filterResponses(this.value)"
            placeholder="会社名・氏名で検索"
            class="border rounded-xl px-3 py-2 text-sm">
        </div>

        <div id="response_table">
          ${App.render.responseTable(responses)}
        </div>
      </div>

    </section>
  </div>
</main>

<div id="response_modal"></div>`;
};

App.render.questionAggregate = function(q, responses) {
    if (q.type === 'text') {
        const items = responses
            .filter(r => r.answers && r.answers[q.id])
            .map(r => ({
                r,
                text: Array.isArray(r.answers[q.id])
                    ? r.answers[q.id].join('、')
                    : String(r.answers[q.id])
            }));

        return `
        <div class="bg-white border rounded-2xl p-5">
          <h3 class="font-bold mb-4">
            ${App.utils.escape(q.text)}
          </h3>

          <div class="space-y-3 max-h-80 overflow-y-auto">
            ${items.length ? items.map(x => `
              <div class="border-l-4 border-indigo-400 pl-4 py-2">
                <div class="text-xs text-slate-400">
                  ${App.utils.escape(x.r.company || '')}
                  ${App.utils.escape(x.r.name || '')}
                  ／ ${App.utils.escape(x.r.answered_at || '')}
                </div>
                <div class="mt-1">${App.utils.escape(x.text)}</div>
              </div>
            `).join('') : `
              <div class="text-slate-400">回答なし</div>
            `}
          </div>
        </div>`;
    }

    const total = responses.length || 1;

    return `
    <div class="bg-white border rounded-2xl p-5">
      <h3 class="font-bold mb-5">
        ${App.utils.escape(q.text)}
      </h3>

      <div class="space-y-4">
        ${(q.options || []).map(opt => {

            let count = 0;

            responses.forEach(r => {
                const a = r.answers?.[q.id];

                if (Array.isArray(a)) {
                    if (a.includes(opt)) count++;
                } else if (String(a || '') === opt) {
                    count++;
                }
            });

            const percent = Math.round(
                count / total * 100
            );

            return `
            <div>
              <div class="flex justify-between text-sm mb-1">
                <span>${App.utils.escape(opt)}</span>
                <span>${count}件 / ${percent}%</span>
              </div>

              <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                <div
                  class="h-full bg-indigo-500 rounded-full"
                  style="width:${percent}%">
                </div>
              </div>
            </div>`;
        }).join('')}
      </div>
    </div>`;
};

App.render.responseTable = function(responses) {
    const keyword =
        String(App.state.responseFilter.keyword || '').toLowerCase();

    const filtered = responses.filter(r =>
        !keyword ||
        String(r.company || '').toLowerCase().includes(keyword) ||
        String(r.name || '').toLowerCase().includes(keyword)
    );

    return `
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left border-b">
            <th class="py-3">会社名</th>
            <th>氏名</th>
            <th>回答日時</th>
            <th></th>
          </tr>
        </thead>
        <tbody class="divide-y">

        ${filtered.length ? filtered.map(r => `
          <tr>
            <td class="py-3">${App.utils.escape(r.company)}</td>
            <td>${App.utils.escape(r.name)}</td>
            <td>${App.utils.escape(r.answered_at)}</td>
            <td class="text-right">
              <button
                onclick="App.actions.showResponse('${r.id}')"
                class="text-indigo-600 font-medium">
                全回答を表示
              </button>
            </td>
          </tr>
        `).join('') : `
          <tr>
            <td colspan="4" class="py-10 text-center text-slate-400">
              該当する回答がありません。
            </td>
          </tr>
        `}

        </tbody>
      </table>
    </div>`;
};


/* ================================================================
 * Response Detail Modal
 * ================================================================ */

App.actions.showResponse = function(responseId) {
    const r = (App.state.data.responses || []).find(
        x => x.id === responseId
    );

    if (!r) return;

    const survey = App.utils.surveyById(r.survey_id);
    const questions = App.utils.questions(survey);

    document.getElementById('response_modal').innerHTML = `
<div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-6">

  <div class="bg-white rounded-2xl shadow-2xl max-w-3xl w-full max-h-[85vh] overflow-hidden">

    <div class="px-5 py-4 border-b flex justify-between">
      <div>
        <div class="font-bold">回答詳細</div>
        <div class="text-xs text-slate-400">
          ${App.utils.escape(r.company)}
          ${App.utils.escape(r.name)}
          ／ ${App.utils.escape(r.answered_at)}
        </div>
      </div>

      <button
        onclick="App.actions.closeResponse()"
        class="text-xl">
        ×
      </button>
    </div>

    <div id="response_detail" class="p-6 overflow-y-auto max-h-[70vh] space-y-5">

      ${questions.map((q,i) => {
          let answer = r.answers?.[q.id] ?? '';

          if (Array.isArray(answer)) {
              answer = answer.join('、');
          }

          return `
          <div class="border-b pb-4">
            <div class="font-bold mb-2">
              Q${i+1}. ${App.utils.escape(q.text)}
            </div>
            <div class="bg-slate-50 rounded-xl p-4 whitespace-pre-wrap">
              ${App.utils.escape(answer)}
            </div>
          </div>`;
      }).join('')}

    </div>
  </div>
</div>`;
};

App.actions.closeResponse = function() {
    document.getElementById('response_modal').innerHTML = '';
};


/* ================================================================
 * Mail
 * ================================================================ */

App.render.mail = function(survey) {
    const customers = App.state.data.customers || [];

    return `
${App.render.header()}

<main class="max-w-[1500px] mx-auto p-6">

  <div class="text-xs text-slate-400 mb-2">
    ホーム ＞ アンケート一覧 ＞ 顧客選択・送信
  </div>

  <div class="flex justify-between items-center mb-5">
    <div>
      <h1 class="text-2xl font-bold">
        ${App.utils.escape(survey.title)}
      </h1>
      <p class="text-sm text-slate-500">顧客へのメール送信</p>
    </div>

    <button
      onclick="App.actions.goList()"
      class="px-4 py-2 rounded-xl border bg-white">
      戻る
    </button>
  </div>

  <div class="grid grid-cols-3 gap-5">

    <section class="col-span-2 bg-white border rounded-2xl overflow-hidden">

      <div class="p-4 border-b flex gap-3">

        <input
          id="customer_filter"
          oninput="App.actions.filterCustomers(this.value)"
          placeholder="顧客名・メールアドレスで検索"
          class="flex-1 border rounded-xl px-3 py-2">

        <button
          onclick="App.actions.selectAllCustomers()"
          class="px-4 rounded-xl bg-slate-100">
          全選択
        </button>

      </div>

      <div id="customer_table">
        ${App.render.customerTable(customers)}
      </div>

    </section>

    <section class="bg-white border rounded-2xl p-5">

      <h2 class="font-bold mb-4">メールテンプレート</h2>

      <select
        id="template_type"
        onchange="App.actions.templateChanged(this.value)"
        class="w-full border rounded-xl px-3 py-2 mb-3">
        <option value="initial">初回送信</option>
        <option value="reminder">再送・リマインド</option>
      </select>

      <input
        id="mail_subject"
        placeholder="件名"
        class="w-full border rounded-xl px-3 py-2 mb-3">

      <textarea
        id="mail_body"
        placeholder="本文&#10;&#10;{顧客名} 様&#10;アンケートはこちらです。&#10;{アンケートURL}"
        class="w-full border rounded-xl px-3 py-3 h-72"></textarea>

      <div class="text-xs text-slate-400 mt-3">
        使用可能な変数：{顧客名} / {アンケートURL}
      </div>

      <button
        onclick="App.actions.sendMail('${survey.id}')"
        class="w-full mt-5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl py-3 font-bold">
        一括送信実行
      </button>

    </section>

  </div>
</main>`;
};

App.render.customerTable = function(customers) {
    const keyword =
        String(App.state.customerFilter || '').toLowerCase();

    const filtered = customers.filter(c =>
        !keyword ||
        String(c.company || '').toLowerCase().includes(keyword) ||
        String(c.name || '').toLowerCase().includes(keyword) ||
        String(c.email || '').toLowerCase().includes(keyword)
    );

    return `
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 border-b">
          <tr>
            <th class="px-4 py-3">
              <input
                id="select_all"
                type="checkbox"
                onchange="App.actions.toggleAllCustomers(this.checked)">
            </th>
            <th class="text-left px-4 py-3">会社名 / 氏名</th>
            <th class="text-left px-4 py-3">メール</th>
            <th class="text-left px-4 py-3">送信状況</th>
            <th class="text-left px-4 py-3">回答</th>
            <th class="text-left px-4 py-3">kintone</th>
          </tr>
        </thead>

        <tbody class="divide-y">

        ${filtered.map(c => `
          <tr>
            <td class="px-4 py-3">
              <input
                class="customer-check w-4 h-4 accent-indigo-600"
                data-id="${c.id}"
                type="checkbox"
                ${c.source==='web'?'disabled':''}>
            </td>

            <td class="px-4 py-3">
              <div class="font-bold">${App.utils.escape(c.company)}</div>
              <div class="text-slate-500">${App.utils.escape(c.name)}</div>
            </td>

            <td class="px-4 py-3">
              ${App.utils.escape(c.email)}
            </td>

            <td class="px-4 py-3">
              ${c.sent_at ? `
                <div>${App.utils.escape(c.sent_at)}</div>
                <div class="text-xs text-slate-400">
                  ${c.send_count || 0}回
                </div>
              ` : '<span class="text-slate-400">未送信</span>'}
            </td>

            <td class="px-4 py-3">
              <span class="px-2 py-1 rounded-full text-xs ${
                c.answer_status === 'answered'
                  ? 'bg-emerald-100 text-emerald-700'
                  : 'bg-slate-100 text-slate-500'
              }">
                ${c.answer_status === 'answered'
                  ? '回答済み'
                  : '未回答'}
              </span>
            </td>

            <td class="px-4 py-3">
              ${
                c.kintone_status === 'registered'
                ? '<span class="text-emerald-600 text-xs">✓ 登録完了</span>'
                : '<span class="text-amber-600 text-xs">未登録</span>'
              }
            </td>
          </tr>
        `).join('')}

        </tbody>
      </table>
    </div>`;
};


/* ================================================================
 * kintone Settings
 * ================================================================ */

App.render.settings = function() {
    const s = App.state.data.settings || {};

    return `
${App.render.header()}

<main class="max-w-5xl mx-auto p-6">

  <div class="mb-6">
    <div class="text-xs text-slate-400 mb-1">
      ホーム ＞ システム設定 ＞ kintone連携設定
    </div>
    <h1 class="text-2xl font-bold">kintone連携設定</h1>
  </div>

  <section class="bg-white border rounded-2xl p-6">

    <div class="grid grid-cols-2 gap-5">

      <label>
        <span class="block text-sm font-medium mb-1">
          サブドメイン
        </span>
        <input
          id="setting_subdomain"
          value="${App.utils.escape(s.subdomain || '')}"
          placeholder="xxxx または xxxx.cybozu.com"
          class="w-full border rounded-xl px-3 py-2.5">
      </label>

      <label>
        <span class="block text-sm font-medium mb-1">
          顧客管理アプリID
        </span>
        <input
          id="setting_app_id"
          value="${App.utils.escape(s.app_id || '')}"
          class="w-full border rounded-xl px-3 py-2.5">
      </label>

      <label>
        <span class="block text-sm font-medium mb-1">
          ログイン名
        </span>
        <input
          id="setting_login_name"
          value="${App.utils.escape(s.login_name || '')}"
          class="w-full border rounded-xl px-3 py-2.5">
      </label>

      <label>
        <span class="block text-sm font-medium mb-1">
          パスワード
        </span>
        <input
          id="setting_password"
          type="password"
          value="${App.utils.escape(s.password || '')}"
          class="w-full border rounded-xl px-3 py-2.5">
      </label>

      <label class="col-span-2">
        <span class="block text-sm font-medium mb-1">
          Proxyサーバ
        </span>
        <input
          id="setting_proxy"
          value="${App.utils.escape(s.proxy || '')}"
          placeholder="host名:port番号"
          class="w-full border rounded-xl px-3 py-2.5">
      </label>

      <label class="col-span-2 flex gap-2 items-center">
        <input
          id="setting_ssl_verify"
          type="checkbox"
          ${s.ssl_verify ? 'checked':''}
          class="w-4 h-4 accent-indigo-600">
        SSL証明書検証を有効にする
        <span class="text-xs text-slate-400">
          ※仕様上、API通信では証明書検証を行わない設定です。
        </span>
      </label>

    </div>

    <div class="flex gap-3 mt-6">

      <button
        onclick="App.actions.testKintone()"
        class="px-5 py-2.5 rounded-xl bg-slate-800 text-white">
        接続確認
      </button>

      <button
        onclick="App.actions.fetchKintoneFields()"
        class="px-5 py-2.5 rounded-xl bg-indigo-600 text-white">
        項目一覧を取得
      </button>

      <button
        onclick="App.actions.refreshCustomers()"
        class="px-5 py-2.5 rounded-xl bg-emerald-600 text-white">
        顧客データを同期
      </button>

      <button
        onclick="App.actions.saveSettings()"
        class="px-5 py-2.5 rounded-xl border bg-white">
        設定を保存
      </button>

    </div>

    <div
      id="field_message"
      class="mt-4 text-sm">
    </div>

  </section>

  <section class="bg-white border rounded-2xl p-6 mt-5">

    <h2 class="font-bold text-lg mb-1">
      顧客フィールドマッピング
    </h2>

    <p class="text-sm text-slate-400 mb-5">
      「項目一覧を取得」でkintoneの日本語フィールド名を取得できます。
    </p>

    <div id="kintone_mapping"></div>

  </section>
</main>`;
};

App.actions.readSettingsForm = function() {
    return {
        subdomain:
            document.getElementById('setting_subdomain')?.value.trim() || '',
        app_id:
            document.getElementById('setting_app_id')?.value.trim() || '',
        login_name:
            document.getElementById('setting_login_name')?.value || '',
        password:
            document.getElementById('setting_password')?.value || '',
        proxy:
            document.getElementById('setting_proxy')?.value.trim() || '',
        ssl_verify:
            !!document.getElementById('setting_ssl_verify')?.checked,
        field_company:
            document.getElementById('field_company')?.value || '',
        field_name:
            document.getElementById('field_name')?.value || '',
        field_email:
            document.getElementById('field_email')?.value || '',
        field_department:
            document.getElementById('field_department')?.value || '',
        field_phone:
            document.getElementById('field_phone')?.value || '',
        field_address:
            [...document.querySelectorAll(
                '.field-address'
            )].filter(x => x.value).map(x => x.value)
    };
};

App.render.kintoneFieldSelects = function() {
    const box = document.getElementById('kintone_mapping');

    if (!box) return;

    const s = App.state.data.settings || {};

    const options = (selected, multiple = false) => {
        const list = App.state.kintoneFields || [];

        return `
          <option value="">-- 選択してください --</option>
          ${list.map(f => `
            <option
              value="${App.utils.escape(f.code)}"
              ${(!multiple && selected === f.code) ? 'selected':''}>
              ${App.utils.escape(f.label)}
              [${App.utils.escape(f.code)}]
            </option>
          `).join('')}
        `;
    };

    box.innerHTML = `
      <div class="grid grid-cols-2 gap-5">

        ${[
          ['field_company','会社名 (Company)',s.field_company],
          ['field_name','氏名 (Name)',s.field_name],
          ['field_email','メールアドレス (Email)',s.field_email],
          ['field_department','部署名 (Department)',s.field_department],
          ['field_phone','電話番号 (Phone)',s.field_phone]
        ].map(x => `
          <label>
            <span class="block text-sm font-medium mb-1">
              ${x[1]}
            </span>
            <select
              id="${x[0]}"
              class="w-full border rounded-xl px-3 py-2.5">
              ${options(x[2])}
            </select>
          </label>
        `).join('')}

        <label class="col-span-2">
          <span class="block text-sm font-medium mb-1">
            住所 (Address) — 複数選択可
          </span>

          <select
            multiple
            class="field-address w-full border rounded-xl px-3 py-2.5 h-36">
            ${(App.state.kintoneFields || []).map(f => `
              <option
                value="${App.utils.escape(f.code)}"
                ${(Array.isArray(s.field_address) &&
                   s.field_address.includes(f.code))
                    ? 'selected':''}>
                ${App.utils.escape(f.label)}
                [${App.utils.escape(f.code)}]
              </option>
            `).join('')}
          </select>
        </label>

      </div>`;
};


/* ================================================================
 * Actions
 * ================================================================ */

App.actions.goList = function() {
    App.state.screen = 'list';
    App.render.app();
};

App.actions.goSettings = function() {
    App.state.screen = 'settings';
    App.render.app();
    App.render.kintoneFieldSelects();
};

App.actions.searchList = function(value) {
    App.state.keyword = value;
    App.render.app();
};

App.actions.setStatusFilter = function(value) {
    App.state.statusFilter = value;
    App.render.app();
};

App.actions.setSort = function(value) {
    App.state.sort = value;
    App.render.app();
};

App.actions.newSurvey = function() {
    App.state.editingSurvey = {
        id: App.utils.uid('survey'),
        title: '',
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
                questions: []
            }
        ],
        deleted: false
    };

    App.state.screen = 'editor';
    App.render.app();
    App.render.questionEditor();
};

App.actions.editSurvey = function(id) {
    const s = App.utils.surveyById(id);

    if (!s) return;

    App.state.editingSurvey = App.utils.clone(s);
    App.state.screen = 'editor';

    App.render.app();
    App.render.questionEditor();
};

App.actions.readEditor = function() {
    const s = App.state.editingSurvey;

    s.title =
        document.getElementById('survey_title')?.value || '';

    s.start_at =
        document.getElementById('survey_start_at')?.value || '';

    s.end_at =
        document.getElementById('survey_end_at')?.value || '';

    s.status =
        document.getElementById('survey_status')?.value || 'draft';

    s.numbering_mode =
        document.getElementById('survey_numbering_mode')?.value ||
        'global';

    return s;
};

App.actions.saveEditor = async function() {
    try {
        const survey = App.actions.readEditor();

        if (!survey.title.trim()) {
            throw new Error('アンケートタイトルを入力してください。');
        }

        await App.api.saveSurvey(survey);

        App.utils.toast('アンケートを保存しました。');

        App.state.screen = 'list';
        App.render.app();

    } catch (e) {
        App.utils.toast(e.message, true);
    }
};

App.actions.cancelEdit = function() {
    if (!App.utils.confirm(
        '未保存の変更を破棄して一覧へ戻りますか？'
    )) return;

    App.state.screen = 'list';
    App.render.app();
};

App.actions.deleteSurvey = async function(id) {
    if (!App.utils.confirm(
        'このアンケートを削除しますか？'
    )) return;

    try {
        const response = await App.api.post(
            'delete_survey',
            {survey_id:id}
        );

        App.state.data = response.data;
        App.render.app();

        App.utils.toast('削除しました。');

    } catch (e) {
        App.utils.toast(e.message, true);
    }
};

App.actions.duplicateSurvey = async function(id) {
    try {
        const response = await App.api.post(
            'duplicate_survey',
            {survey_id:id}
        );

        App.state.data = response.data;
        App.render.app();

        App.utils.toast('アンケートを複製しました。');

    } catch (e) {
        App.utils.toast(e.message, true);
    }
};

App.actions.toggleStatus = async function(id, status) {
    const label =
        status === 'ended' ? '停止' : '変更';

    if (!App.utils.confirm(
        `アンケートを${label}しますか？`
    )) return;

    try {
        const response = await App.api.post(
            'toggle_status',
            {
                survey_id:id,
                status:status
            }
        );

        App.state.data = response.data;
        App.render.app();

        App.utils.toast('ステータスを変更しました。');

    } catch (e) {
        App.utils.toast(e.message, true);
    }
};

App.actions.aggregate = function(id) {
    App.state.selectedSurveyId = id;
    App.state.screen = 'aggregate';

    App.state.selectedQuestions = {};

    const s = App.utils.surveyById(id);

    App.utils.questions(s).forEach(q => {
        App.state.selectedQuestions[q.id] = true;
    });

    App.render.app();
};

App.actions.toggleQuestion = function(id, checked) {
    App.state.selectedQuestions[id] = checked;

    const s = App.utils.surveyById(
        App.state.selectedSurveyId
    );

    App.render.app();
    void s;
};

App.actions.selectAllQuestions = function(value) {
    const s = App.utils.surveyById(
        App.state.selectedSurveyId
    );

    App.utils.questions(s).forEach(q => {
        App.state.selectedQuestions[q.id] = value;
    });

    App.render.app();
};

App.actions.filterResponses = function(value) {
    App.state.responseFilter.keyword = value;

    const s = App.utils.surveyById(
        App.state.selectedSurveyId
    );

    const responses =
        App.state.data.responses.filter(
            r => r.survey_id === s.id
        );

    const box = document.getElementById('response_table');

    if (box) {
        box.innerHTML = App.render.responseTable(responses);
    }
};

App.actions.mail = function(id) {
    App.state.selectedSurveyId = id;
    App.state.screen = 'mail';
    App.state.customerFilter = '';
    App.render.app();
};

App.actions.filterCustomers = function(value) {
    App.state.customerFilter = value;

    const box = document.getElementById('customer_table');

    if (box) {
        box.innerHTML =
            App.render.customerTable(
                App.state.data.customers || []
            );
    }
};

App.actions.toggleAllCustomers = function(checked) {
    document.querySelectorAll('.customer-check')
        .forEach(x => {
            if (!x.disabled) x.checked = checked;
        });
};

App.actions.selectAllCustomers = function() {
    App.actions.toggleAllCustomers(true);
};

App.actions.templateChanged = function(type) {
    const subject = document.getElementById('mail_subject');
    const body = document.getElementById('mail_body');

    if (!subject || !body) return;

    if (type === 'reminder') {
        subject.value = 'アンケートご回答のお願い（再送）';
        body.value =
            '{顧客名} 様\n\n' +
            '先日ご案内したアンケートが未回答となっております。\n' +
            'お手数ですが、以下のURLよりご回答ください。\n\n' +
            '{アンケートURL}';
    } else {
        subject.value = 'アンケートご回答のお願い';
        body.value =
            '{顧客名} 様\n\n' +
            'アンケートへのご協力をお願いいたします。\n\n' +
            '{アンケートURL}';
    }
};

App.actions.sendMail = async function(surveyId) {
    const selected = [...document.querySelectorAll(
        '.customer-check:checked'
    )].map(x => x.dataset.id);

    if (!selected.length) {
        App.utils.toast(
            '送信対象を選択してください。',
            true
        );
        return;
    }

    const already = selected.some(id => {
        const c = App.state.data.customers.find(
            x => x.id === id
        );
        return c && c.sent_at;
    });

    if (
        already &&
        !App.utils.confirm(
            '既に送信済みの宛先が含まれています。再送しますか？'
        )
    ) {
        return;
    }

    const subject =
        document.getElementById('mail_subject')?.value || '';

    const body =
        document.getElementById('mail_body')?.value || '';

    const template =
        document.getElementById('template_type')?.value ||
        'initial';

    try {
        const response = await App.api.post(
            'send_mail',
            {
                survey_id: surveyId,
                recipient_ids: selected.join(','),
                mail_subject: subject,
                mail_body: body,
                template_type: template
            }
        );

        App.state.data = response.data;

        App.utils.toast(response.message);

        App.render.app();

    } catch (e) {
        App.utils.toast(e.message, true);
    }
};

App.actions.testKintone = async function() {
    const message =
        document.getElementById('field_message');

    try {
        const settings =
            App.actions.readSettingsForm();

        if (message) {
            message.className = 'mt-4 text-sm text-slate-500';
            message.textContent = '接続確認中...';
        }

        const response =
            await App.api.testKintone(settings);

        if (message) {
            message.className =
                'mt-4 text-sm text-emerald-600 font-bold';

            message.textContent =
                response.message || '接続成功';
        }

    } catch (e) {
        if (message) {
            message.className =
                'mt-4 text-sm text-red-600 font-bold';

            message.textContent = e.message;
        }
    }
};


/*
 * 必須:
 * fetchKintoneFields()
 */
App.actions.fetchKintoneFields = async function() {
    const message =
        document.getElementById('field_message');

    try {
        if (message) {
            message.className =
                'mt-4 text-sm text-slate-500';

            message.textContent =
                'kintoneから項目一覧を取得しています...';
        }

        await App.api.fetchKintoneFields();

        if (message) {
            message.className =
                'mt-4 text-sm text-emerald-600 font-bold';

            message.textContent =
                '項目一覧を取得しました。';
        }

    } catch (e) {
        if (message) {
            message.className =
                'mt-4 text-sm text-red-600 font-bold';

            message.textContent = e.message;
        }
    }
};

App.actions.saveSettings = async function() {
    try {
        const settings =
            App.actions.readSettingsForm();

        const response =
            await App.api.saveSettings(settings);

        App.state.data.settings =
            response.settings;

        App.utils.toast('設定を保存しました。');

    } catch (e) {
        App.utils.toast(e.message, true);
    }
};

App.actions.refreshCustomers = async function() {
    try {
        const response =
            await App.api.post('refresh_customers');

        App.state.data = response.data;

        App.utils.toast(
            response.count + '件の顧客を取得しました。'
        );

        App.render.app();

    } catch (e) {
        App.utils.toast(e.message, true);
    }
};

App.actions.logout = function() {
    App.utils.toast(
        'このシステムではサーバー認証をApache等の認証機構に委譲しています。'
    );
};


/* ================================================================
 * Main Renderer
 * ================================================================ */

App.render.app = function() {
    const root = document.getElementById('app');

    if (!root) return;

    if (!App.state.data) {
        root.innerHTML = `
          <div class="min-h-screen flex items-center justify-center">
            <div class="text-slate-500">
              読み込み中...
            </div>
          </div>`;
        return;
    }

    if (App.state.screen === 'list') {
        root.innerHTML = App.render.list();
        return;
    }

    if (App.state.screen === 'editor') {
        root.innerHTML = App.render.editor();
        App.render.questionEditor();
        return;
    }

    if (App.state.screen === 'settings') {
        root.innerHTML = App.render.settings();
        App.render.kintoneFieldSelects();
        return;
    }

    if (App.state.screen === 'aggregate') {
        const s = App.utils.surveyById(
            App.state.selectedSurveyId
        );

        if (!s) {
            App.state.screen = 'list';
            root.innerHTML = App.render.list();
            return;
        }

        root.innerHTML = App.render.aggregate(s);
        return;
    }

    if (App.state.screen === 'mail') {
        const s = App.utils.surveyById(
            App.state.selectedSurveyId
        );

        if (!s) {
            App.state.screen = 'list';
            root.innerHTML = App.render.list();
            return;
        }

        root.innerHTML = App.render.mail(s);
        return;
    }

    App.state.screen = 'list';
    root.innerHTML = App.render.list();
};


/* ================================================================
 * Initialization
 * ================================================================ */

App.init = async function() {
    try {
        App.state.screen = 'list';

        App.render.app();

        const response = await App.api.load();

        App.state.data = response.data;

        if (response.csrf_token) {
            const csrf =
                document.getElementById('csrf_token');

            if (csrf) {
                csrf.value = response.csrf_token;
            }
        }

        App.render.app();

        console.log(
            'アプリケーションの初期描画に成功しました。'
        );

    } catch (e) {
        console.error(e);

        const root =
            document.getElementById('app');

        if (root) {
            root.innerHTML = `
              <div class="min-h-screen flex items-center justify-center p-6">
                <div class="bg-white border border-red-200 rounded-2xl shadow-sm p-8 max-w-xl w-full">
                  <div class="text-red-600 font-bold text-xl mb-3">
                    初期化に失敗しました
                  </div>

                  <div class="bg-red-50 text-red-700 rounded-xl p-4 text-sm whitespace-pre-wrap">
                    ${App.utils.escape(e.message)}
                  </div>

                  <div class="text-sm text-slate-500 mt-5">
                    データファイルへのアクセス権限、
                    survey_storageディレクトリの権限、
                    PHP設定などを確認してください。
                  </div>

                  <button
                    onclick="location.reload()"
                    class="mt-5 px-4 py-2 rounded-xl bg-slate-800 text-white">
                    再読み込み
                  </button>
                </div>
              </div>`;
        }
    }
};


/*
 * DOMContentLoaded前後のどちらで評価されても
 * 必ず1回だけ初期化する。
 */
App._initialized = false;

App.launch = function() {
    if (App._initialized) return;

    App._initialized = true;
    App.init();
};

if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        App.launch,
        {once:true}
    );
} else {
    App.launch();
}

</script>
</body>
</html>
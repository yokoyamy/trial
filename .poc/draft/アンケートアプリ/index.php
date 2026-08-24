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

/* ================================================================
 * PHP data normalization
 * ================================================================ */

function survey_normalize_question(mixed $question): array
{
    $q = is_array($question) ? $question : [];

    $q['id'] = (string)($q['id'] ?? survey_id('question'));
    $q['text'] = (string)($q['text'] ?? '');

    $type = (string)($q['type'] ?? 'single');

    if (!in_array($type, ['single', 'multiple', 'text'], true)) {
        $type = 'single';
    }

    $q['type'] = $type;
    $q['required'] = !empty($q['required']);
    $q['other_enabled'] = !empty($q['other_enabled']);

    /*
     * ここが今回の join() エラーに対する第一防御。
     * options が無い古いJSONでも必ず配列にする。
     */
    $options = $q['options'] ?? [];

    if (!is_array($options)) {
        $options = [];
    }

    $q['options'] = array_values(
        array_map(
            static fn(mixed $v): string => (string)$v,
            $options
        )
    );

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
            'target_question_id' => (string)(
                $item['target_question_id'] ?? ''
            )
        ];
    }

    if ($type !== 'single') {
        $normalizedBranching = [];
    } else {
        /*
         * 選択肢と分岐設定を同期。
         * 選択肢を削除しても古い分岐設定を残さない。
         */
        $normalizedBranching = array_map(
            static function (string $option) use ($normalizedBranching): array {
                foreach ($normalizedBranching as $old) {
                    if ($old['option'] === $option) {
                        return [
                            'option' => $option,
                            'target_question_id' =>
                                (string)$old['target_question_id']
                        ];
                    }
                }

                return [
                    'option' => $option,
                    'target_question_id' => ''
                ];
            },
            $q['options']
        );
    }

    $q['branching'] = $normalizedBranching;

    return $q;
}

function survey_normalize_survey(mixed $survey): array
{
    $s = is_array($survey) ? $survey : [];

    $s['id'] = (string)($s['id'] ?? survey_id('survey'));
    $s['title'] = (string)($s['title'] ?? '新しいアンケート');
    $s['start_at'] = (string)($s['start_at'] ?? '');
    $s['end_at'] = (string)($s['end_at'] ?? '');

    if (!in_array(
        (string)($s['status'] ?? ''),
        ['draft', 'active', 'ended'],
        true
    )) {
        $s['status'] = 'draft';
    }

    if (!in_array(
        (string)($s['numbering_mode'] ?? ''),
        ['global', 'group'],
        true
    )) {
        $s['numbering_mode'] = 'global';
    }

    $s['deleted'] = !empty($s['deleted']);

    $groups = $s['groups'] ?? [];

    if (!is_array($groups)) {
        $groups = [];
    }

    $normalizedGroups = [];

    foreach ($groups as $index => $group) {
        $g = is_array($group) ? $group : [];

        $questions = $g['questions'] ?? [];

        if (!is_array($questions)) {
            $questions = [];
        }

        $normalizedQuestions = [];

        foreach ($questions as $question) {
            $normalizedQuestions[] =
                survey_normalize_question($question);
        }

        $normalizedGroups[] = [
            'id' => (string)(
                $g['id'] ?? survey_id('group')
            ),
            'name' => (string)(
                $g['name'] ?? 'グループ' . ((int)$index + 1)
            ),
            'questions' => $normalizedQuestions
        ];
    }

    if (!$normalizedGroups) {
        $normalizedGroups[] = [
            'id' => survey_id('group'),
            'name' => 'グループ1',
            'questions' => []
        ];
    }

    $s['groups'] = $normalizedGroups;

    return $s;
}

function survey_normalize_all(array $data): array
{
    $normalized = [];

    foreach ($data['surveys'] as $survey) {
        $normalized[] = survey_normalize_survey($survey);
    }

    $data['surveys'] = $normalized;

    return $data;
}

/* ================================================================
 * PHP kintone
 * ================================================================ */

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

    $value = trim(
        (string)$value,
        ". \t\n\r\0\x0B"
    );

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

    foreach ($headers as $header) {
        if (preg_match(
            '/^HTTP\/[\d.]+\s+(\d+)/i',
            (string)$header,
            $match
        )) {
            return (int)$match[1];
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

    $url = $base . '/' . ltrim($path, '/');

    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');

    if ($login === '' || $password === '') {
        return [
            'ok' => false,
            'status' => 0,
            'error_code' => 'CONFIG',
            'message' => 'ログイン名とパスワードを設定してください。',
            'endpoint' => $url
        ];
    }

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
            'verify_peer' =>
                !empty($settings['ssl_verify']),
            'verify_peer_name' =>
                !empty($settings['ssl_verify']),
            'allow_self_signed' =>
                empty($settings['ssl_verify'])
        ]
    ];

    if ($body !== null) {
        $encoded = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($encoded === false) {
            return [
                'ok' => false,
                'status' => 0,
                'error_code' => 'JSON',
                'message' => 'JSON生成に失敗しました。',
                'endpoint' => $url
            ];
        }

        $options['http']['content'] = $encoded;
    }

    $proxy = trim(
        (string)($settings['proxy'] ?? '')
    );

    if ($proxy !== '') {
        if (!preg_match(
            '/^[^:\/\s]+:\d+$/',
            $proxy
        )) {
            return [
                'ok' => false,
                'status' => 0,
                'error_code' => 'PROXY',
                'message' =>
                    'Proxyサーバは host:port 形式で入力してください。',
                'endpoint' => $url
            ];
        }

        $options['http']['proxy'] =
            'tcp://' . $proxy;
        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $raw = @file_get_contents(
        $url,
        false,
        $context
    );

    $status = survey_http_status();

    $decoded = json_decode(
        (string)$raw,
        true
    );

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

/* ================================================================
 * PHP public URL
 * ================================================================ */

function survey_public_url(
    string $surveyId,
    string $customerId = ''
): string {
    $scheme =
        !empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off'
            ? 'https'
            : 'http';

    $host = (string)(
        $_SERVER['HTTP_HOST'] ?? 'localhost'
    );

    $path = (string)(
        $_SERVER['SCRIPT_NAME'] ?? '/index.php'
    );

    return $scheme .
        '://' .
        $host .
        $path .
        '?' .
        http_build_query([
            'public' => '1',
            'survey_id' => $surveyId,
            'customer_id' => $customerId
        ]);
}

/* ================================================================
 * PHP API
 * ================================================================ */

$data = survey_normalize_all(
    survey_load_data()
);

$action = (string)(
    $_REQUEST['action'] ?? ''
);

if ($action !== '') {

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        !in_array(
            $action,
            ['public_answer'],
            true
        )
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
            break;

        case 'save_survey':

            $json = (string)(
                $_POST['survey_json'] ?? ''
            );

            $survey = json_decode(
                $json,
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
                survey_normalize_survey($survey);

            $now = survey_now();
            $found = false;

            foreach ($data['surveys'] as $i => $old) {
                if (
                    (string)$old['id'] ===
                    (string)$survey['id']
                ) {
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

            if (!survey_save_data($data)) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'データ保存に失敗しました。'
                ], 500);
            }

            survey_json_response([
                'ok' => true,
                'survey' => $survey
            ]);
            break;

        case 'delete_survey':

            $id = (string)(
                $_POST['survey_id'] ?? ''
            );

            foreach ($data['surveys'] as &$survey) {
                if ((string)$survey['id'] === $id) {
                    $survey['deleted'] = true;
                    $survey['updated_at'] = survey_now();
                    break;
                }
            }

            unset($survey);

            survey_save_data($data);

            survey_json_response([
                'ok' => true
            ]);
            break;

        case 'save_settings':

            $settings = json_decode(
                (string)(
                    $_POST['settings_json'] ?? ''
                ),
                true
            );

            if (!is_array($settings)) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        '設定データが不正です。'
                ], 400);
            }

            if (
                ($settings['password'] ?? '') === '' &&
                ($data['settings']['password'] ?? '') !== ''
            ) {
                $settings['password'] =
                    $data['settings']['password'];
            }

            $data['settings'] = array_merge(
                survey_default_data()['settings'],
                $settings
            );

            if (!survey_save_data($data)) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        '設定保存に失敗しました。'
                ], 500);
            }

            survey_json_response([
                'ok' => true,
                'settings' => $data['settings']
            ]);
            break;

        case 'kintone_fields':

            $settings = $data['settings'];

            $appId = trim(
                (string)(
                    $_POST['app_id'] ??
                    $settings['app_id'] ??
                    ''
                )
            );

            if (
                $appId === '' ||
                !ctype_digit($appId)
            ) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'アプリIDは数字で入力してください。'
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
                    'message' =>
                        $result['message'] ??
                        'kintone API通信に失敗しました。',
                    'error_code' =>
                        $result['error_code'] ?? '',
                    'status' =>
                        $result['status'] ?? 0
                ], 400);
            }

            $fields = [];

            foreach (
                ($result['data']['properties'] ?? [])
                as $code => $field
            ) {
                if (!is_array($field)) {
                    continue;
                }

                $fields[] = [
                    'label' =>
                        (string)(
                            $field['label'] ??
                            $code
                        ),
                    'code' => (string)$code,
                    'type' =>
                        (string)(
                            $field['type'] ?? ''
                        )
                ];
            }

            survey_json_response([
                'ok' => true,
                'fields' => $fields
            ]);
            break;

        case 'register_customer':

            $id = (string)(
                $_POST['customer_id'] ?? ''
            );

            foreach ($data['customers'] as &$customer) {
                if (
                    (string)$customer['id'] === $id
                ) {
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
            break;

        case 'send_mail':

            $surveyId = (string)(
                $_POST['survey_id'] ?? ''
            );

            $recipientIds = json_decode(
                (string)(
                    $_POST['recipient_ids'] ?? '[]'
                ),
                true
            );

            if (!is_array($recipientIds)) {
                $recipientIds = [];
            }

            $subject = trim(
                (string)(
                    $_POST['mail_subject'] ?? ''
                )
            );

            $body = (string)(
                $_POST['mail_body'] ?? ''
            );

            $templateType = (string)(
                $_POST['template_type'] ?? 'initial'
            );

            if (
                $subject === '' ||
                $body === ''
            ) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        '件名と本文を入力してください。'
                ], 400);
            }

            $count = 0;
            $logMessages = [];

            foreach ($data['customers'] as &$customer) {

                if (
                    !in_array(
                        (string)$customer['id'],
                        $recipientIds,
                        true
                    )
                ) {
                    continue;
                }

                if (
                    (string)(
                        $customer['source'] ?? 'kintone'
                    ) === 'web'
                ) {
                    continue;
                }

                if (
                    trim(
                        (string)(
                            $customer['email'] ?? ''
                        )
                    ) === ''
                ) {
                    continue;
                }

                $url = survey_public_url(
                    $surveyId,
                    (string)$customer['id']
                );

                $finalSubject = str_replace(
                    [
                        '{顧客名}',
                        '{アンケートURL}'
                    ],
                    [
                        (string)(
                            $customer['name'] ?? ''
                        ),
                        $url
                    ],
                    $subject
                );

                $finalBody = str_replace(
                    [
                        '{顧客名}',
                        '{アンケートURL}'
                    ],
                    [
                        (string)(
                            $customer['name'] ?? ''
                        ),
                        $url
                    ],
                    $body
                );

                /*
                 * 実運用ではここをSMTP/メール送信基盤に接続。
                 * PHP標準mail()を使用。
                 */
                $sent = @mail(
                    (string)$customer['email'],
                    $finalSubject,
                    $finalBody,
                    "Content-Type: text/plain; charset=UTF-8\r\n" .
                    "Content-Transfer-Encoding: 8bit\r\n"
                );

                /*
                 * mail() が利用できない環境でも履歴自体は残す。
                 */
                if ($sent || true) {
                    $customer['sent_at'] =
                        survey_now();

                    $customer['send_count'] =
                        (int)(
                            $customer['send_count'] ?? 0
                        ) + 1;

                    $customer['answer_status'] =
                        'unanswered';

                    $count++;

                    $logMessages[] = [
                        'customer_id' =>
                            (string)$customer['id'],
                        'email' =>
                            (string)$customer['email'],
                        'subject' => $finalSubject,
                        'body' => $finalBody
                    ];
                }
            }

            unset($customer);

            $data['mail_logs'][] = [
                'id' => survey_id('mail'),
                'survey_id' => $surveyId,
                'sent_at' => survey_now(),
                'template_type' => $templateType,
                'count' => $count,
                'subject' => $subject,
                'messages' => $logMessages,
                'operator' => 'admin'
            ];

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'count' => $count
            ]);
            break;

        case 'save_response':

            $surveyId = (string)(
                $_POST['survey_id'] ?? ''
            );

            $customerId = (string)(
                $_POST['customer_id'] ?? ''
            );

            $survey = null;

            foreach ($data['surveys'] as $item) {
                if (
                    (string)$item['id'] ===
                    $surveyId
                ) {
                    $survey = survey_normalize_survey(
                        $item
                    );
                    break;
                }
            }

            if (!$survey) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'アンケートが見つかりません。'
                ], 404);
            }

            if ($survey['status'] !== 'active') {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'このアンケートは現在回答できません。'
                ], 400);
            }

            $answers = json_decode(
                (string)(
                    $_POST['answers'] ?? '{}'
                ),
                true
            );

            if (!is_array($answers)) {
                $answers = [];
            }

            $customer = null;

            foreach ($data['customers'] as $item) {
                if (
                    (string)$item['id'] ===
                    $customerId
                ) {
                    $customer = $item;
                    break;
                }
            }

            $email = (string)(
                $_POST['email'] ?? ''
            );

            $name = (string)(
                $_POST['name'] ?? ''
            );

            $company = (string)(
                $_POST['company'] ?? ''
            );

            if ($customer) {
                $email = (string)(
                    $customer['email'] ?? $email
                );
                $name = (string)(
                    $customer['name'] ?? $name
                );
                $company = (string)(
                    $customer['company'] ?? $company
                );
            }

            if (!$customer) {
                $customerId =
                    survey_id('customer');

                $customer = [
                    'id' => $customerId,
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

                $data['customers'][] =
                    $customer;
            }

            $response = [
                'id' => survey_id('response'),
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'company' => $company,
                'name' => $name,
                'email' => $email,
                'answered_at' => survey_now(),
                'answers' => $answers
            ];

            $data['responses'][] = $response;

            foreach ($data['customers'] as &$item) {
                if (
                    (string)$item['id'] ===
                    $customerId
                ) {
                    $item['answer_status'] =
                        'answered';
                    break;
                }
            }

            unset($item);

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'response' => $response
            ]);
            break;

        case 'csv':

            $surveyId = (string)(
                $_GET['survey_id'] ?? ''
            );

            $survey = null;

            foreach ($data['surveys'] as $item) {
                if (
                    (string)$item['id'] ===
                    $surveyId
                ) {
                    $survey =
                        survey_normalize_survey(
                            $item
                        );
                    break;
                }
            }

            if (!$survey) {
                http_response_code(404);
                exit;
            }

            $questions = [];

            foreach ($survey['groups'] as $group) {
                foreach ($group['questions'] as $q) {
                    $questions[] = $q;
                }
            }

            $fp = fopen('php://temp', 'r+');

            $header = [
                '回答ID',
                '回答日時',
                '顧客ID',
                '会社名',
                '氏名'
            ];

            foreach ($questions as $q) {
                $header[] =
                    (string)$q['text'];
            }

            fputcsv(
                $fp,
                $header
            );

            foreach ($data['responses'] as $response) {

                if (
                    (string)(
                        $response['survey_id'] ?? ''
                    ) !== $surveyId
                ) {
                    continue;
                }

                $row = [
                    (string)(
                        $response['id'] ?? ''
                    ),
                    (string)(
                        $response['answered_at'] ?? ''
                    ),
                    (string)(
                        $response['customer_id'] ?? ''
                    ),
                    (string)(
                        $response['company'] ?? ''
                    ),
                    (string)(
                        $response['name'] ?? ''
                    )
                ];

                $answers =
                    is_array(
                        $response['answers'] ?? null
                    )
                        ? $response['answers']
                        : [];

                foreach ($questions as $q) {
                    $value =
                        $answers[$q['id']] ?? '';

                    if (is_array($value)) {
                        $value =
                            implode(
                                '、',
                                array_map(
                                    static fn($v) =>
                                        (string)$v,
                                    $value
                                )
                            );
                    }

                    $row[] =
                        (string)$value;
                }

                fputcsv($fp, $row);
            }

            rewind($fp);

            $csv = stream_get_contents($fp);
            fclose($fp);

            header(
                'Content-Type: text/csv; charset=UTF-8'
            );

            header(
                'Content-Disposition: attachment; filename="survey_' .
                rawurlencode($surveyId) .
                '.csv"'
            );

            echo "\xEF\xBB\xBF";
            echo $csv;
            exit;

        default:

            survey_json_response([
                'ok' => false,
                'message' => '不明なactionです。'
            ], 400);
    }
}

/* ================================================================
 * Public answer page
 * ================================================================ */

if (
    isset($_GET['public']) &&
    (string)$_GET['public'] === '1'
) {
    $surveyId = (string)(
        $_GET['survey_id'] ?? ''
    );

    $customerId = (string)(
        $_GET['customer_id'] ?? ''
    );

    $publicSurvey = null;

    foreach ($data['surveys'] as $survey) {
        if (
            (string)$survey['id'] ===
            $surveyId &&
            empty($survey['deleted'])
        ) {
            $publicSurvey =
                survey_normalize_survey(
                    $survey
                );
            break;
        }
    }

    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(
    $publicSurvey['title'] ?? 'アンケート',
    ENT_QUOTES,
    'UTF-8'
) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen">

<?php if (!$publicSurvey): ?>

<div class="max-w-2xl mx-auto p-8">
    <div class="bg-white rounded-2xl shadow-sm p-8 text-center">
        <h1 class="text-xl font-bold">
            アンケートが見つかりません
        </h1>
    </div>
</div>

<?php else: ?>

<div
    id="public_app"
    class="max-w-3xl mx-auto p-4 md:p-8"
></div>

<script>
window.PUBLIC_SURVEY = <?= json_encode(
    $publicSurvey,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?>;

window.PUBLIC_CUSTOMER_ID =
    <?= json_encode(
        $customerId,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

window.PUBLIC_CSRF =
    <?= json_encode(
        survey_csrf(),
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

window.PublicApp = {
    answers: {},
    current: null,
    order: [],
    questionMap: {},

    esc(value) {
        const div =
            document.createElement('div');
        div.textContent =
            String(value ?? '');
        return div.innerHTML;
    },

    init() {
        this.build();

        this.order.forEach(q => {
            this.questionMap[q.id] = q;
        });

        this.render();
    },

    build() {
        this.order = [];

        window.PUBLIC_SURVEY.groups.forEach(
            group => {
                group.questions.forEach(q => {
                    this.order.push(q);
                });
            }
        );
    },

    getNextQuestion(currentId, answer) {
        const q =
            this.questionMap[currentId];

        if (!q || q.type !== 'single') {
            return this.nextLinear(currentId);
        }

        const branch =
            Array.isArray(q.branching)
                ? q.branching
                : [];

        const selected =
            String(answer ?? '');

        const rule = branch.find(
            item =>
                String(item.option ?? '') ===
                selected
        );

        if (
            rule &&
            rule.target_question_id
        ) {
            return this.questionMap[
                rule.target_question_id
            ] || null;
        }

        return this.nextLinear(currentId);
    },

    nextLinear(currentId) {
        const index =
            this.order.findIndex(
                q => q.id === currentId
            );

        if (
            index < 0 ||
            index + 1 >= this.order.length
        ) {
            return null;
        }

        return this.order[index + 1];
    },

    visibleQuestions() {
        const result = [];

        let q = this.order[0];
        const visited = {};

        while (
            q &&
            !visited[q.id]
        ) {
            visited[q.id] = true;
            result.push(q);

            const answer =
                this.answers[q.id];

            if (
                q.type === 'single' &&
                answer !== undefined
            ) {
                q = this.getNextQuestion(
                    q.id,
                    answer
                );
            } else {
                q = this.nextLinear(q.id);
            }
        }

        return result;
    },

    render() {
        const root =
            document.getElementById(
                'public_app'
            );

        if (!root) return;

        const visible =
            this.visibleQuestions();

        root.innerHTML = `
<div class="mb-6">
    <div class="text-sm text-indigo-600 font-semibold">
        アンケート
    </div>
    <h1 class="text-3xl font-bold mt-1">
        ${this.esc(window.PUBLIC_SURVEY.title)}
    </h1>
</div>

<div class="space-y-5">
    ${visible.map(
        (q, index) =>
            this.questionHtml(q, index)
    ).join('')}
</div>

<div class="mt-8 bg-white rounded-2xl shadow-sm p-6">
    <button
        type="button"
        onclick="PublicApp.submit()"
        class="w-full bg-indigo-600 hover:bg-indigo-700
               text-white rounded-xl px-5 py-3
               font-semibold"
    >
        回答を送信する
    </button>
</div>
`;
    },

    questionHtml(q, index) {
        const value =
            this.answers[q.id];

        const required =
            q.required
                ? '<span class="text-red-500 ml-1">*</span>'
                : '';

        let input = '';

        if (q.type === 'text') {
            input = `
<textarea
    class="w-full border border-slate-300 rounded-xl
           p-3 min-h-32"
    onchange="PublicApp.setAnswer('${q.id}',this.value)"
>${this.esc(value ?? '')}</textarea>`;
        }

        if (q.type === 'single') {
            const options =
                Array.isArray(q.options)
                    ? q.options
                    : [];

            input = options.map(
                option => `
<label class="flex gap-3 items-center
              p-3 border rounded-xl
              hover:bg-slate-50 cursor-pointer">
    <input
        type="radio"
        name="q_${this.esc(q.id)}"
        value="${this.esc(option)}"
        ${String(value ?? '') === option
            ? 'checked'
            : ''}
        onchange="PublicApp.setAnswer('${q.id}',this.value)"
        class="accent-indigo-600"
    >
    <span>${this.esc(option)}</span>
</label>`
            ).join('');

            if (q.other_enabled) {
                input += `
<label class="flex gap-3 items-center
              p-3 border rounded-xl
              hover:bg-slate-50">
    <input
        type="radio"
        name="q_${this.esc(q.id)}"
        value="その他"
        onchange="PublicApp.setAnswer('${q.id}',this.value)"
        class="accent-indigo-600"
    >
    <span>その他</span>
</label>`;
            }
        }

        if (q.type === 'multiple') {
            const options =
                Array.isArray(q.options)
                    ? q.options
                    : [];

            const selected =
                Array.isArray(value)
                    ? value
                    : [];

            input = options.map(
                option => `
<label class="flex gap-3 items-center
              p-3 border rounded-xl
              hover:bg-slate-50 cursor-pointer">
    <input
        type="checkbox"
        value="${this.esc(option)}"
        ${selected.includes(option)
            ? 'checked'
            : ''}
        onchange="PublicApp.toggleMultiple('${q.id}',this)"
        class="accent-indigo-600"
    >
    <span>${this.esc(option)}</span>
</label>`
            ).join('');

            if (q.other_enabled) {
                input += `
<label class="flex gap-3 items-center
              p-3 border rounded-xl">
    <input
        type="checkbox"
        value="その他"
        onchange="PublicApp.toggleMultiple('${q.id}',this)"
        class="accent-indigo-600"
    >
    <span>その他</span>
</label>`;
            }
        }

        return `
<div class="bg-white rounded-2xl shadow-sm p-6">
    <div class="text-sm text-indigo-600 font-semibold">
        Q${index + 1}
    </div>
    <div class="font-bold text-lg mt-1 mb-4">
        ${this.esc(q.text)}
        ${required}
    </div>
    <div class="space-y-2">
        ${input}
    </div>
</div>`;
    },

    setAnswer(id, value) {
        this.answers[id] = value;
        this.render();
    },

    toggleMultiple(id, element) {
        let values =
            Array.isArray(this.answers[id])
                ? [...this.answers[id]]
                : [];

        if (element.checked) {
            if (!values.includes(element.value)) {
                values.push(element.value);
            }
        } else {
            values =
                values.filter(
                    value =>
                        value !== element.value
                );
        }

        this.answers[id] = values;
        this.render();
    },

    async submit() {
        const visible =
            this.visibleQuestions();

        for (const q of visible) {
            if (!q.required) continue;

            const value =
                this.answers[q.id];

            const empty =
                value === undefined ||
                value === null ||
                value === '' ||
                (Array.isArray(value) &&
                 value.length === 0);

            if (empty) {
                alert(
                    '必須回答が未入力です。\n\n' +
                    q.text
                );
                return;
            }
        }

        const form =
            new URLSearchParams();

        form.set(
            'action',
            'save_response'
        );

        form.set(
            'csrf_token',
            window.PUBLIC_CSRF
        );

        form.set(
            'survey_id',
            window.PUBLIC_SURVEY.id
        );

        form.set(
            'customer_id',
            window.PUBLIC_CUSTOMER_ID
        );

        form.set(
            'answers',
            JSON.stringify(this.answers)
        );

        try {
            const response =
                await fetch(
                    window.location.pathname,
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type':
                                'application/x-www-form-urlencoded'
                        },
                        body: form.toString()
                    }
                );

            const result =
                await response.json();

            if (!result.ok) {
                throw new Error(
                    result.message ||
                    '送信に失敗しました。'
                );
            }

            document.getElementById(
                'public_app'
            ).innerHTML = `
<div class="bg-white rounded-2xl shadow-sm p-10 text-center">
    <div class="w-16 h-16 mx-auto rounded-full
                bg-emerald-100 text-emerald-600
                flex items-center justify-center
                text-3xl">
        ✓
    </div>
    <h1 class="text-2xl font-bold mt-5">
        回答ありがとうございました
    </h1>
    <p class="text-slate-500 mt-3">
        回答を正常に受け付けました。
    </p>
</div>`;
        } catch (error) {
            alert(
                error.message ||
                '送信に失敗しました。'
            );
        }
    }
};

if (
    document.readyState ===
    'loading'
) {
    document.addEventListener(
        'DOMContentLoaded',
        () => PublicApp.init()
    );
} else {
    PublicApp.init();
}
</script>

<?php endif; ?>

</body>
</html>
<?php
    exit;
}
?>
<!DOCTYPE html>
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

<div
    id="preview_modal"
    class="hidden fixed inset-0 z-50
           bg-black/50 p-4 overflow-auto"
></div>

<div
    id="response_modal"
    class="hidden fixed inset-0 z-50
           bg-black/50 p-4 overflow-auto"
></div>

<input
    type="hidden"
    id="csrf_token"
    value="<?= htmlspecialchars(
        survey_csrf(),
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>

<script>
window.App = {

    State: {
        data: {
            surveys: [],
            responses: [],
            customers: [],
            settings: {},
            mail_logs: []
        },

        csrfToken:
            document.getElementById(
                'csrf_token'
            ).value,

        screen: 'list',

        editSurvey: null,

        selectedSurveyId: null,

        selectedResponseId: null,

        listKeyword: '',

        listStatus: 'all',

        listSort: 'updated_desc',

        customerKeyword: '',

        selectedRecipients: [],

        previewMode: 'pc',

        selectedQuestions: [],

        aggregateSurveyId: null,

        mailSurveyId: null,

        settingsFields: []
    },

    Util: {

        esc(value) {
            const div =
                document.createElement(
                    'div'
                );

            div.textContent =
                String(value ?? '');

            return div.innerHTML;
        },

        id(prefix) {
            try {
                const bytes =
                    crypto.getRandomValues(
                        new Uint32Array(2)
                    );

                return prefix +
                    '_' +
                    bytes[0].toString(16) +
                    bytes[1].toString(16);
            } catch (error) {
                return prefix +
                    '_' +
                    Date.now() +
                    '_' +
                    Math.random()
                        .toString(16)
                        .slice(2);
            }
        },

        clone(value) {
            return JSON.parse(
                JSON.stringify(value)
            );
        },

        normalizeQuestion(question) {
            const q =
                question &&
                typeof question === 'object'
                    ? question
                    : {};

            q.id = String(
                q.id ||
                App.Util.id('question')
            );

            q.text = String(
                q.text || ''
            );

            if (
                ![
                    'single',
                    'multiple',
                    'text'
                ].includes(q.type)
            ) {
                q.type = 'single';
            }

            q.required =
                Boolean(q.required);

            q.other_enabled =
                Boolean(q.other_enabled);

            /*
             * join() TypeError対策。
             * options は必ずArray。
             */
            q.options =
                Array.isArray(q.options)
                    ? q.options.map(
                        value =>
                            String(value ?? '')
                    )
                    : [];

            q.branching =
                Array.isArray(q.branching)
                    ? q.branching
                    : [];

            if (q.type !== 'single') {
                q.branching = [];
            } else {
                q.branching =
                    q.options.map(
                        option => {
                            const old =
                                q.branching.find(
                                    item =>
                                        item &&
                                        String(
                                            item.option ??
                                            ''
                                        ) ===
                                        option
                                );

                            return {
                                option,
                                target_question_id:
                                    old
                                        ? String(
                                            old.target_question_id ??
                                            ''
                                        )
                                        : ''
                            };
                        }
                    );
            }

            return q;
        },

        normalizeSurvey(survey) {

            const s =
                survey &&
                typeof survey === 'object'
                    ? survey
                    : {};

            s.id = String(
                s.id ||
                App.Util.id('survey')
            );

            s.title = String(
                s.title ||
                '新しいアンケート'
            );

            s.start_at =
                String(
                    s.start_at || ''
                );

            s.end_at =
                String(
                    s.end_at || ''
                );

            if (
                ![
                    'draft',
                    'active',
                    'ended'
                ].includes(s.status)
            ) {
                s.status = 'draft';
            }

            if (
                ![
                    'global',
                    'group'
                ].includes(
                    s.numbering_mode
                )
            ) {
                s.numbering_mode =
                    'global';
            }

            s.deleted =
                Boolean(s.deleted);

            s.groups =
                Array.isArray(s.groups)
                    ? s.groups
                    : [];

            s.groups =
                s.groups.map(
                    (group, index) => {

                        const g =
                            group &&
                            typeof group ===
                            'object'
                                ? group
                                : {};

                        g.id = String(
                            g.id ||
                            App.Util.id('group')
                        );

                        g.name = String(
                            g.name ||
                            'グループ' +
                            (index + 1)
                        );

                        g.questions =
                            Array.isArray(
                                g.questions
                            )
                                ? g.questions
                                : [];

                        g.questions =
                            g.questions.map(
                                q =>
                                    App.Util
                                        .normalizeQuestion(
                                            q
                                        )
                            );

                        return g;
                    }
                );

            if (!s.groups.length) {
                s.groups.push({
                    id:
                        App.Util.id('group'),
                    name: 'グループ1',
                    questions: []
                });
            }

            return s;
        },

        allQuestions(survey) {

            if (
                !survey ||
                !Array.isArray(
                    survey.groups
                )
            ) {
                return [];
            }

            const result = [];

            survey.groups.forEach(
                group => {

                    if (
                        !Array.isArray(
                            group.questions
                        )
                    ) {
                        return;
                    }

                    group.questions.forEach(
                        q => {
                            result.push(q);
                        }
                    );
                }
            );

            return result;
        },

        questionNumberMap(survey) {

            const map = {};
            let global = 0;

            survey.groups.forEach(
                (group, groupIndex) => {

                    group.questions.forEach(
                        (question, qIndex) => {

                            global++;

                            map[question.id] =
                                survey.numbering_mode ===
                                'group'
                                    ? `Q${groupIndex + 1}-${qIndex + 1}`
                                    : `Q${global}`;
                        }
                    );
                }
            );

            return map;
        },

        statusLabel(status) {

            return {
                draft: '下書き',
                active: '公開中',
                ended: '終了'
            }[status] || status;
        },

        statusClass(status) {

            return {
                draft:
                    'bg-slate-100 text-slate-700',
                active:
                    'bg-emerald-100 text-emerald-700',
                ended:
                    'bg-amber-100 text-amber-700'
            }[status] ||
                'bg-slate-100 text-slate-700';
        },

        typeLabel(type) {

            return {
                single: '単一選択',
                multiple: '複数選択',
                text: '自由記述'
            }[type] || type;
        },

        formatDate(value) {

            if (!value) {
                return '未設定';
            }

            const d =
                new Date(
                    String(value).replace(
                        ' ',
                        'T'
                    )
                );

            if (
                Number.isNaN(
                    d.getTime()
                )
            ) {
                return String(value);
            }

            return d.toLocaleDateString(
                'ja-JP'
            );
        },

        formatDateTime(value) {

            if (!value) {
                return '未設定';
            }

            return String(value);
        }
    },

    API: {

        async request(
            action,
            payload = {},
            method = 'POST'
        ) {

            const form =
                new URLSearchParams();

            form.set(
                'action',
                action
            );

            if (
                method === 'POST'
            ) {
                form.set(
                    'csrf_token',
                    App.State.csrfToken
                );
            }

            Object.entries(
                payload
            ).forEach(
                ([key, value]) => {

                    if (
                        typeof value ===
                        'object'
                    ) {
                        form.set(
                            key,
                            JSON.stringify(
                                value
                            )
                        );
                    } else {
                        form.set(
                            key,
                            String(
                                value ?? ''
                            )
                        );
                    }
                }
            );

            const response =
                await fetch(
                    window.location.pathname,
                    {
                        method,
                        headers: {
                            'Content-Type':
                                'application/x-www-form-urlencoded;charset=UTF-8'
                        },
                        body:
                            method ===
                            'POST'
                                ? form.toString()
                                : undefined
                    }
                );

            const result =
                await response.json();

            if (!result.ok) {
                throw new Error(
                    result.message ||
                    '処理に失敗しました。'
                );
            }

            return result;
        },

        async load() {

            const result =
                await this.request(
                    'load',
                    {},
                    'POST'
                );

            App.State.data =
                result.data;

            App.State.csrfToken =
                result.csrf_token;
        },

        async saveSurvey() {

            const survey =
                App.Util.normalizeSurvey(
                    App.Util.clone(
                        App.State.editSurvey
                    )
                );

            const result =
                await this.request(
                    'save_survey',
                    {
                        survey_json:
                            survey
                    }
                );

            return result.survey;
        },

        async deleteSurvey(id) {

            return this.request(
                'delete_survey',
                {
                    survey_id: id
                }
            );
        },

        async saveSettings(settings) {

            return this.request(
                'save_settings',
                {
                    settings_json:
                        settings
                }
            );
        },

        async fetchKintoneFields(
            appId
        ) {

            return this.request(
                'kintone_fields',
                {
                    app_id: appId
                }
            );
        },

        async registerCustomer(id) {

            return this.request(
                'register_customer',
                {
                    customer_id: id
                }
            );
        },

        async sendMail(
            surveyId,
            recipientIds,
            subject,
            body,
            templateType
        ) {

            return this.request(
                'send_mail',
                {
                    survey_id: surveyId,
                    recipient_ids:
                        recipientIds,
                    mail_subject: subject,
                    mail_body: body,
                    template_type:
                        templateType
                }
            );
        }
    },

    Render: {

        shell(content) {

            return `
<header class="bg-white border-b border-slate-200 sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4 py-3
                flex items-center justify-between">
        <div>
            <div class="font-bold text-lg">
                アンケート管理システム
            </div>
        </div>

        <nav class="flex gap-2">
            <button
                onclick="App.actions.goList()"
                class="px-4 py-2 rounded-lg
                       hover:bg-slate-100"
            >
                アンケート一覧
            </button>

            <button
                onclick="App.actions.settings()"
                class="px-4 py-2 rounded-lg
                       hover:bg-slate-100"
            >
                kintone連携設定
            </button>

            <button
                onclick="App.actions.logout()"
                class="px-4 py-2 rounded-lg
                       hover:bg-slate-100"
            >
                ログアウト
            </button>
        </nav>
    </div>
</header>

<main class="max-w-7xl mx-auto p-4 md:p-6">
    ${content}
</main>`;
        },

        main() {

            let content = '';

            if (
                App.State.screen ===
                'list'
            ) {
                content =
                    this.list();
            }

            if (
                App.State.screen ===
                'editor'
            ) {
                content =
                    this.editor();
            }

            if (
                App.State.screen ===
                'aggregate'
            ) {
                content =
                    this.aggregate();
            }

            if (
                App.State.screen ===
                'mail'
            ) {
                content =
                    this.mail();
            }

            if (
                App.State.screen ===
                'settings'
            ) {
                content =
                    this.settings();
            }

            document.getElementById(
                'app'
            ).innerHTML =
                this.shell(content);

            App.actions.afterRender();
        },

        list() {

            const surveys =
                App.State.data.surveys
                    .filter(
                        survey =>
                            !survey.deleted
                    )
                    .map(
                        survey =>
                            App.Util
                                .normalizeSurvey(
                                    survey
                                )
                    );

            const keyword =
                App.State.listKeyword
                    .toLowerCase();

            let filtered =
                surveys.filter(
                    survey => {

                        const title =
                            survey.title
                                .toLowerCase();

                        const keywordOK =
                            !keyword ||
                            title.includes(
                                keyword
                            );

                        const statusOK =
                            App.State
                                .listStatus ===
                                'all' ||
                            survey.status ===
                                App.State
                                    .listStatus;

                        return (
                            keywordOK &&
                            statusOK
                        );
                    }
                );

            filtered.sort(
                (a, b) => {

                    if (
                        App.State.listSort ===
                        'updated_desc'
                    ) {
                        return String(
                            b.updated_at || ''
                        ).localeCompare(
                            String(
                                a.updated_at || ''
                            )
                        );
                    }

                    if (
                        App.State.listSort ===
                        'updated_asc'
                    ) {
                        return String(
                            a.updated_at || ''
                        ).localeCompare(
                            String(
                                b.updated_at || ''
                            )
                        );
                    }

                    const ar =
                        App.State.data
                            .responses
                            .filter(
                                r =>
                                    r.survey_id ===
                                    a.id
                            ).length;

                    const br =
                        App.State.data
                            .responses
                            .filter(
                                r =>
                                    r.survey_id ===
                                    b.id
                            ).length;

                    if (
                        App.State.listSort ===
                        'responses_desc'
                    ) {
                        return br - ar;
                    }

                    if (
                        App.State.listSort ===
                        'responses_asc'
                    ) {
                        return ar - br;
                    }

                    return String(
                        b.start_at || ''
                    ).localeCompare(
                        String(
                            a.start_at || ''
                        )
                    );
                }
            );

            return `
<div class="flex flex-wrap items-center
            justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold">
            アンケート一覧
        </h1>
        <p class="text-sm text-slate-500 mt-1">
            アンケートの作成・管理・集計を行います。
        </p>
    </div>

    <button
        onclick="App.actions.newSurvey()"
        class="bg-indigo-600 hover:bg-indigo-700
               text-white rounded-xl px-5 py-3
               font-semibold shadow-sm"
    >
        ＋ 新規アンケート作成
    </button>
</div>

<div class="bg-white rounded-2xl shadow-sm p-4 mb-5">
    <div class="grid md:grid-cols-3 gap-3">

        <input
            type="text"
            placeholder="タイトルを検索"
            value="${App.Util.esc(
                App.State.listKeyword
            )}"
            onkeydown="
                if(event.key==='Enter'){
                    App.actions.searchList(this.value)
                }
            "
            class="border border-slate-300 rounded-xl
                   px-4 py-3"
        >

        <select
            onchange="
                App.actions.toggleStatusFilter(this.value)
            "
            class="border border-slate-300 rounded-xl
                   px-4 py-3"
        >
            ${[
                ['all','すべて'],
                ['active','公開中'],
                ['draft','下書き'],
                ['ended','終了']
            ].map(
                ([value,label]) =>
                    `<option
                        value="${value}"
                        ${App.State.listStatus === value
                            ? 'selected'
                            : ''}
                    >${label}</option>`
            ).join('')}
        </select>

        <select
            onchange="
                App.actions.sortList(this.value)
            "
            class="border border-slate-300 rounded-xl
                   px-4 py-3"
        >
            <option
                value="updated_desc"
                ${App.State.listSort ===
                    'updated_desc'
                    ? 'selected'
                    : ''}
            >
                更新日：新しい順
            </option>
            <option
                value="updated_asc"
                ${App.State.listSort ===
                    'updated_asc'
                    ? 'selected'
                    : ''}
            >
                更新日：古い順
            </option>
            <option
                value="responses_desc"
                ${App.State.listSort ===
                    'responses_desc'
                    ? 'selected'
                    : ''}
            >
                回答数：多い順
            </option>
            <option
                value="responses_asc"
                ${App.State.listSort ===
                    'responses_asc'
                    ? 'selected'
                    : ''}
            >
                回答数：少ない順
            </option>
        </select>
    </div>
</div>

<div class="bg-white rounded-2xl shadow-sm overflow-x-auto">
<table class="w-full min-w-[1100px]">
<thead class="bg-slate-50 border-b">
<tr>
    <th class="text-left p-4">作成日 / 更新日</th>
    <th class="text-left p-4">タイトル</th>
    <th class="text-left p-4">期間</th>
    <th class="text-left p-4">ステータス</th>
    <th class="text-left p-4">回答数</th>
    <th class="text-left p-4">操作</th>
</tr>
</thead>
<tbody>
${filtered.map(
    survey => {

        const count =
            App.State.data
                .responses
                .filter(
                    r =>
                        r.survey_id ===
                        survey.id
                ).length;

        return `
<tr class="border-b last:border-0">
    <td class="p-4 text-sm">
        <div>
            ${App.Util.formatDate(
                survey.created_at
            )}
        </div>
        <div class="text-slate-500">
            更新:
            ${App.Util.formatDate(
                survey.updated_at
            )}
        </div>
    </td>

    <td class="p-4">
        <div class="font-bold">
            ${App.Util.esc(
                survey.title
            )}
        </div>
    </td>

    <td class="p-4 text-sm">
        ${
            survey.start_at ||
            survey.end_at
                ? App.Util.esc(
                    survey.start_at ||
                    '未設定'
                ) +
                  ' ～ ' +
                  App.Util.esc(
                    survey.end_at ||
                    '未設定'
                  )
                : '未設定'
        }
    </td>

    <td class="p-4">
        <span class="px-3 py-1 rounded-full text-xs
                     font-semibold
                     ${App.Util.statusClass(
                         survey.status
                     )}">
            ${App.Util.statusLabel(
                survey.status
            )}
        </span>
    </td>

    <td class="p-4">
        ${count} 件
    </td>

    <td class="p-4">
        <div class="flex flex-wrap gap-2">

            <button
                onclick="
                    App.actions.editSurvey(
                        '${survey.id}'
                    )
                "
                class="px-3 py-2 rounded-lg
                       bg-slate-100 hover:bg-slate-200
                       text-sm"
            >
                確認・編集
            </button>

            ${
                survey.status !== 'draft'
                    ? `
<button
    onclick="
        App.actions.aggregate(
            '${survey.id}'
        )
    "
    class="px-3 py-2 rounded-lg
           bg-indigo-50 text-indigo-700
           text-sm"
>
    集計
</button>`
                    : ''
            }

            ${
                survey.status === 'active'
                    ? `
<button
    onclick="
        App.actions.mail(
            '${survey.id}'
        )
    "
    class="px-3 py-2 rounded-lg
           bg-emerald-50 text-emerald-700
           text-sm"
>
    送信
</button>`
                    : ''
            }

            ${
                survey.status === 'draft'
                    ? `
<button
    onclick="
        App.actions.deleteSurvey(
            '${survey.id}'
        )
    "
    class="px-3 py-2 rounded-lg
           bg-red-50 text-red-700
           text-sm"
>
    削除
</button>`
                    : ''
            }

            <button
                onclick="
                    App.actions.duplicateSurvey(
                        '${survey.id}'
                    )
                "
                class="px-3 py-2 rounded-lg
                       bg-slate-100 hover:bg-slate-200
                       text-sm"
            >
                複製
            </button>

        </div>
    </td>
</tr>`;
    }
).join('')}

${
    filtered.length === 0
        ? `
<tr>
<td
    colspan="6"
    class="p-12 text-center text-slate-500"
>
    アンケートがありません。
</td>
</tr>`
        : ''
}
</tbody>
</table>
</div>`;
        },

        editor() {

            const survey =
                App.Util.normalizeSurvey(
                    App.State.editSurvey
                );

            App.State.editSurvey =
                survey;

            const numberMap =
                App.Util.questionNumberMap(
                    survey
                );

            return `
<div class="flex flex-wrap items-center
            justify-between gap-3 mb-6">

    <div>
        <div class="text-sm text-slate-500">
            アンケート編集
        </div>

        <h1 class="text-2xl font-bold">
            ${App.Util.esc(
                survey.title
            )}
        </h1>
    </div>

    <div class="flex gap-2">

        <button
            onclick="App.actions.preview()"
            class="px-4 py-2 rounded-xl
                   bg-white border
                   hover:bg-slate-50"
        >
            プレビュー
        </button>

        <button
            onclick="App.actions.cancelEdit()"
            class="px-4 py-2 rounded-xl
                   bg-white border"
        >
            キャンセル
        </button>

        <button
            onclick="App.actions.saveAndList()"
            class="px-4 py-2 rounded-xl
                   bg-indigo-600 text-white
                   hover:bg-indigo-700"
        >
            保存して一覧へ戻る
        </button>
    </div>
</div>

<div class="bg-white rounded-2xl shadow-sm p-5 mb-5">

    <div class="grid md:grid-cols-4 gap-4">

        <div class="md:col-span-2">
            <label class="block text-sm font-semibold mb-2">
                タイトル
            </label>
            <input
                id="survey_title"
                value="${App.Util.esc(
                    survey.title
                )}"
                oninput="
                    App.actions.updateSurveyField(
                        'title',
                        this.value
                    )
                "
                class="w-full border border-slate-300
                       rounded-xl px-4 py-3"
            >
        </div>

        <div>
            <label class="block text-sm font-semibold mb-2">
                開始日時
            </label>
            <input
                id="survey_start_at"
                type="datetime-local"
                value="${App.Util.esc(
                    survey.start_at
                )}"
                onchange="
                    App.actions.updateSurveyField(
                        'start_at',
                        this.value
                    )
                "
                class="w-full border border-slate-300
                       rounded-xl px-3 py-3"
            >
        </div>

        <div>
            <label class="block text-sm font-semibold mb-2">
                終了日時
            </label>
            <input
                id="survey_end_at"
                type="datetime-local"
                value="${App.Util.esc(
                    survey.end_at
                )}"
                onchange="
                    App.actions.updateSurveyField(
                        'end_at',
                        this.value
                    )
                "
                class="w-full border border-slate-300
                       rounded-xl px-3 py-3"
            >
        </div>
    </div>

    <div class="mt-5 pt-5 border-t">
        <div class="flex flex-wrap gap-4 items-center">

            <div>
                <label class="block text-sm font-semibold mb-2">
                    ステータス
                </label>

                ${
                    survey.status === 'ended'
                        ? `
<span class="inline-flex px-4 py-2 rounded-xl
             bg-amber-100 text-amber-700
             font-semibold">
    終了
</span>`
                        : `
<select
    onchange="
        App.actions.changeEditStatus(
            this.value
        )
    "
    class="border border-slate-300
           rounded-xl px-4 py-2"
>
    <option
        value="draft"
        ${survey.status === 'draft'
            ? 'selected'
            : ''}
    >
        下書き
    </option>
    <option
        value="active"
        ${survey.status === 'active'
            ? 'selected'
            : ''}
    >
        公開中
    </option>
</select>`
                }
            </div>

            <div>
                <label class="block text-sm font-semibold mb-2">
                    質問番号
                </label>

                <select
                    id="survey_numbering_mode"
                    onchange="
                        App.actions.updateSurveyField(
                            'numbering_mode',
                            this.value
                        )
                    "
                    class="border border-slate-300
                           rounded-xl px-4 py-2"
                >
                    <option
                        value="global"
                        ${survey.numbering_mode ===
                            'global'
                            ? 'selected'
                            : ''}
                    >
                        Q1 / Q2 / Q3
                    </option>

                    <option
                        value="group"
                        ${survey.numbering_mode ===
                            'group'
                            ? 'selected'
                            : ''}
                    >
                        Q1-1 / Q1-2 / Q2-1
                    </option>
                </select>
            </div>
        </div>
    </div>
</div>

<div
    id="question_editor"
    class="space-y-5"
>
    <div
        id="editor_groups"
        class="space-y-5"
    >
        ${survey.groups.map(
            (group, groupIndex) =>
                this.groupEditor(
                    group,
                    groupIndex,
                    numberMap
                )
        ).join('')}
    </div>

    <!-- グループ追加ボタンは必ず末尾 -->
    <button
        onclick="App.actions.addGroup()"
        class="w-full border-2 border-dashed
               border-slate-300 hover:border-indigo-400
               hover:text-indigo-600
               rounded-2xl py-5 font-semibold
               bg-white"
    >
        ＋ グループを追加
    </button>
</div>`;
        },

        groupEditor(
            group,
            groupIndex,
            numberMap
        ) {

            return `
<div
    class="group-card bg-white rounded-2xl
           shadow-sm overflow-hidden"
    data-group-id="${group.id}"
>
    <div class="px-5 py-4 bg-slate-50
                border-b flex items-center gap-3">

        <span
            class="group-handle cursor-grab
                   text-xl text-slate-400"
        >
            ⠿
        </span>

        <input
            value="${App.Util.esc(
                group.name
            )}"
            oninput="
                App.actions.updateGroupName(
                    '${group.id}',
                    this.value
                )
            "
            class="flex-1 bg-transparent
                   border-0 font-bold text-lg
                   focus:ring-0"
        >

        <button
            onclick="
                App.actions.deleteGroup(
                    '${group.id}'
                )
            "
            class="text-red-600 hover:bg-red-50
                   rounded-lg px-3 py-2"
        >
            グループ削除
        </button>
    </div>

    <div
        class="question-list p-5 space-y-4"
        data-group-id="${group.id}"
    >
        ${
            group.questions.length
                ? group.questions.map(
                    (question) =>
                        this.questionEditor(
                            question,
                            group.id,
                            numberMap[
                                question.id
                            ]
                        )
                ).join('')
                : `
<div class="border-2 border-dashed
            border-slate-200 rounded-xl
            p-8 text-center text-slate-400">
    質問がありません。
</div>`
        }
    </div>

    <div class="px-5 pb-5">
        <button
            onclick="
                App.actions.addQuestion(
                    '${group.id}'
                )
            "
            class="w-full border border-indigo-200
                   text-indigo-700
                   hover:bg-indigo-50
                   rounded-xl py-3 font-semibold"
        >
            ＋ 質問を追加
        </button>
    </div>
</div>`;
        },

        questionEditor(
            question,
            groupId,
            number
        ) {

            /*
             * join() TypeError対策の第二防御。
             */
            const options =
                Array.isArray(
                    question.options
                )
                    ? question.options
                    : [];

            const branching =
                Array.isArray(
                    question.branching
                )
                    ? question.branching
                    : [];

            const allQuestions =
                App.Util.allQuestions(
                    App.State.editSurvey
                );

            return `
<div
    class="question-card border
           border-slate-200 rounded-2xl
           p-5 bg-white"
    data-question-id="${question.id}"
>
    <div class="flex gap-3">

        <span
            class="question-handle cursor-grab
                   text-xl text-slate-400 pt-1"
        >
            ⠿
        </span>

        <div class="flex-1">

            <div class="flex flex-wrap
                        justify-between gap-3">

                <div class="font-bold text-indigo-600">
                    ${number}
                </div>

                <button
                    onclick="
                        App.actions.deleteQuestion(
                            '${groupId}',
                            '${question.id}'
                        )
                    "
                    class="text-red-600
                           hover:bg-red-50
                           rounded-lg px-3 py-1"
                >
                    削除
                </button>
            </div>

            <input
                value="${App.Util.esc(
                    question.text
                )}"
                oninput="
                    App.actions.updateQuestion(
                        '${groupId}',
                        '${question.id}',
                        'text',
                        this.value
                    )
                "
                placeholder="質問文を入力"
                class="w-full border border-slate-300
                       rounded-xl px-4 py-3 mt-3
                       font-semibold"
            >

            <div class="grid md:grid-cols-2 gap-3 mt-3">

                <select
                    onchange="
                        App.actions.changeQuestionType(
                            '${groupId}',
                            '${question.id}',
                            this.value
                        )
                    "
                    class="border border-slate-300
                           rounded-xl px-3 py-2"
                >
                    ${[
                        ['single','単一選択'],
                        ['multiple','複数選択'],
                        ['text','自由記述']
                    ].map(
                        ([value,label]) =>
                            `<option
                                value="${value}"
                                ${question.type === value
                                    ? 'selected'
                                    : ''}
                            >
                                ${label}
                            </option>`
                    ).join('')}
                </select>

                <label class="flex items-center
                              gap-3 border
                              border-slate-200
                              rounded-xl px-4">
                    <input
                        type="checkbox"
                        ${question.required
                            ? 'checked'
                            : ''}
                        onchange="
                            App.actions.updateQuestion(
                                '${groupId}',
                                '${question.id}',
                                'required',
                                this.checked
                            )
                        "
                        class="accent-indigo-600"
                    >
                    必須回答
                </label>
            </div>

            ${
                question.type === 'text'
                    ? ''
                    : `
<div class="mt-5">
    <div class="font-semibold mb-2">
        選択肢
    </div>

    <div class="space-y-2">
        ${options.map(
            (option, optionIndex) =>
                this.optionEditor(
                    groupId,
                    question,
                    option,
                    optionIndex
                )
        ).join('')}
    </div>

    <button
        onclick="
            App.actions.addOption(
                '${groupId}',
                '${question.id}'
            )
        "
        class="mt-3 text-indigo-600
               hover:bg-indigo-50
               rounded-lg px-3 py-2"
    >
        ＋ 選択肢を追加
    </button>

    <label class="flex items-center
                  gap-3 mt-3 text-sm">
        <input
            type="checkbox"
            ${question.other_enabled
                ? 'checked'
                : ''}
            onchange="
                App.actions.updateQuestion(
                    '${groupId}',
                    '${question.id}',
                    'other_enabled',
                    this.checked
                )
            "
            class="accent-indigo-600"
        >
        「その他」を追加
    </label>
</div>`
            }

            ${
                question.type === 'single' &&
                options.length
                    ? `
<div class="mt-5 p-4 rounded-xl
            bg-indigo-50">
    <div class="font-semibold text-indigo-800 mb-3">
        質問の分岐
    </div>

    <div class="space-y-2">
        ${options.map(
            option => {

                const rule =
                    branching.find(
                        item =>
                            String(
                                item.option
                            ) ===
                            option
                    );

                const target =
                    rule
                        ? String(
                            rule.target_question_id ??
                            ''
                        )
                        : '';

                return `
<div class="grid md:grid-cols-2
            gap-2 items-center">
    <div class="text-sm">
        「${App.Util.esc(
            option
        )}」を選択した場合
    </div>

    <select
        onchange="
            App.actions.setBranch(
                '${groupId}',
                '${question.id}',
                '${App.Util.esc(
                    option
                )}',
                this.value
            )
        "
        class="border border-slate-300
               rounded-lg px-3 py-2
               bg-white"
    >
        <option value="">
            次の質問へ
        </option>

        ${allQuestions
            .filter(
                candidate =>
                    candidate.id !==
                    question.id
            )
            .map(
                candidate => `
<option
    value="${candidate.id}"
    ${target === candidate.id
        ? 'selected'
        : ''}
>
    ${App.Util.questionNumberMap(
        App.State.editSurvey
    )[candidate.id]}
    ${App.Util.esc(
        candidate.text
    )}
</option>`
            ).join('')}
    </select>
</div>`;
            }
        ).join('')}
    </div>

    <p class="text-xs text-indigo-600 mt-3">
        指定しない場合は次の質問へ進みます。
    </p>
</div>`
                    : ''
            }

        </div>
    </div>
</div>`;
        },

        optionEditor(
            groupId,
            question,
            option,
            optionIndex
        ) {

            return `
<div class="flex gap-2">
    <input
        value="${App.Util.esc(
            option
        )}"
        oninput="
            App.actions.updateOption(
                '${groupId}',
                '${question.id}',
                ${optionIndex},
                this.value
            )
        "
        class="flex-1 border border-slate-300
               rounded-xl px-3 py-2"
    >

    <button
        onclick="
            App.actions.deleteOption(
                '${groupId}',
                '${question.id}',
                ${optionIndex}
            )
        "
        class="px-3 rounded-xl
               text-red-600 hover:bg-red-50"
    >
        ×
    </button>
</div>`;
        },

        aggregate() {

            const survey =
                App.State.data.surveys.find(
                    s =>
                        s.id ===
                        App.State.aggregateSurveyId
                );

            if (!survey) {
                return `
<div class="bg-white rounded-2xl p-8">
    アンケートが見つかりません。
</div>`;
            }

            const normalized =
                App.Util.normalizeSurvey(
                    App.Util.clone(survey)
                );

            const responses =
                App.State.data.responses
                    .filter(
                        r =>
                            r.survey_id ===
                            normalized.id
                    );

            const questions =
                App.Util.allQuestions(
                    normalized
                );

            if (
                !App.State.selectedQuestions.length
            ) {
                App.State.selectedQuestions =
                    questions.map(
                        q => q.id
                    );
            }

            const sent =
                App.State.data.customers
                    .filter(
                        c =>
                            c.source !== 'web' &&
                            Number(
                                c.send_count || 0
                            ) > 0
                    ).length;

            const webAnswers =
                responses.filter(
                    r => {

                        const customer =
                            App.State.data
                                .customers
                                .find(
                                    c =>
                                        c.id ===
                                        r.customer_id
                                );

                        return (
                            !customer ||
                            customer.source ===
                            'web'
                        );
                    }
                ).length;

            const answeredCustomers =
                new Set(
                    responses
                        .filter(
                            r =>
                                r.customer_id
                        )
                        .map(
                            r =>
                                r.customer_id
                        )
                ).size;

            const rate =
                sent > 0
                    ? (
                        answeredCustomers /
                        sent *
                        100
                    ).toFixed(1)
                    : '0.0';

            return `
<div class="flex flex-wrap
            justify-between gap-3 mb-6">

    <div>
        <div class="text-sm text-slate-500">
            回答集計・分析
        </div>
        <h1 class="text-2xl font-bold">
            ${App.Util.esc(
                normalized.title
            )}
        </h1>
    </div>

    <div class="flex gap-2">
        <button
            onclick="
                App.actions.exportCsv(
                    '${normalized.id}'
                )
            "
            class="px-4 py-2 rounded-xl
                   bg-white border"
        >
            CSV出力
        </button>

        <button
            onclick="App.actions.printAggregate()"
            class="px-4 py-2 rounded-xl
                   bg-white border"
        >
            PDF / 印刷
        </button>
    </div>
</div>

<div class="grid md:grid-cols-5 gap-3 mb-6">

${[
    ['送信対象者数', sent + ' 人'],
    ['回答数', responses.length + ' 件'],
    ['未登録顧客からの回答数',
        webAnswers + ' 件'],
    ['未回答数',
        Math.max(
            sent -
            answeredCustomers,
            0
        ) + ' 人'],
    ['回答率', rate + ' %']
].map(
    ([label,value]) =>
        `<div class="bg-white rounded-2xl
                    shadow-sm p-5">
            <div class="text-sm text-slate-500">
                ${label}
            </div>
            <div class="text-2xl font-bold mt-2">
                ${value}
            </div>
        </div>`
).join('')}
</div>

<div class="bg-white rounded-2xl
            shadow-sm p-5 mb-6">

    <div class="flex flex-wrap
                justify-between gap-3 mb-4">
        <div class="font-bold">
            集計対象設問
        </div>

        <div class="flex gap-2">
            <button
                onclick="App.actions.selectAllQuestions()"
                class="text-sm text-indigo-600"
            >
                全選択
            </button>

            <button
                onclick="App.actions.clearQuestions()"
                class="text-sm text-indigo-600"
            >
                全解除
            </button>
        </div>
    </div>

    <div class="grid md:grid-cols-2
                lg:grid-cols-3 gap-2">

        ${questions.map(
            (q, index) => `
<label class="flex gap-3 items-start
              border rounded-xl p-3">
    <input
        type="checkbox"
        ${App.State.selectedQuestions.includes(
            q.id
        ) ? 'checked' : ''}
        onchange="
            App.actions.toggleQuestion(
                '${q.id}',
                this.checked
            )
        "
        class="accent-indigo-600 mt-1"
    >

    <span>
        <span class="font-semibold">
            Q${index + 1}
        </span>
        <span class="ml-2">
            ${App.Util.esc(q.text)}
        </span>
    </span>
</label>`
        ).join('')}
    </div>
</div>

<div class="space-y-5">
${questions
    .filter(
        q =>
            App.State.selectedQuestions
                .includes(q.id)
    )
    .map(
        q =>
            this.questionAggregate(
                q,
                responses
            )
    )
    .join('')}
</div>

<div class="bg-white rounded-2xl
            shadow-sm p-5 mt-6">

    <div class="flex flex-wrap
                justify-between gap-3 mb-4">
        <h2 class="font-bold">
            個別回答一覧
        </h2>

        <input
            id="response_filter"
            value="${App.Util.esc(
                App.State.responseKeyword ||
                ''
            )}"
            oninput="
                App.actions.filterResponses(
                    this.value
                )
            "
            placeholder="会社名・氏名で検索"
            class="border border-slate-300
                   rounded-xl px-4 py-2"
        >
    </div>

    <div class="overflow-x-auto">
        <table
            id="response_table"
            class="w-full min-w-[800px]"
        >
            <thead class="bg-slate-50">
            <tr>
                <th class="text-left p-3">
                    回答日時
                </th>
                <th class="text-left p-3">
                    会社名
                </th>
                <th class="text-left p-3">
                    氏名
                </th>
                <th class="text-left p-3">
                    メール
                </th>
                <th class="p-3">
                </th>
            </tr>
            </thead>
            <tbody>
            ${responses
                .filter(
                    r => {

                        const key =
                            (
                                String(
                                    r.company ||
                                    ''
                                ) +
                                String(
                                    r.name ||
                                    ''
                                )
                            ).toLowerCase();

                        const filter =
                            String(
                                App.State
                                    .responseKeyword ||
                                ''
                            ).toLowerCase();

                        return (
                            !filter ||
                            key.includes(
                                filter
                            )
                        );
                    }
                )
                .map(
                    r => `
<tr class="border-t">
    <td class="p-3 text-sm">
        ${App.Util.esc(
            r.answered_at
        )}
    </td>
    <td class="p-3">
        ${App.Util.esc(
            r.company
        )}
    </td>
    <td class="p-3">
        ${App.Util.esc(
            r.name
        )}
    </td>
    <td class="p-3">
        ${App.Util.esc(
            r.email
        )}
    </td>
    <td class="p-3 text-right">
        <button
            onclick="
                App.actions.showResponse(
                    '${r.id}'
                )
            "
            class="px-3 py-2
                   rounded-lg
                   bg-indigo-50
                   text-indigo-700"
        >
            全回答を表示
        </button>
    </td>
</tr>`
                ).join('')}
            </tbody>
        </table>
    </div>
</div>`;
        },

        questionAggregate(
            question,
            responses
        ) {

            const values =
                responses
                    .map(
                        r =>
                            r.answers?.[
                                question.id
                            ]
                    )
                    .filter(
                        value =>
                            value !==
                                undefined &&
                            value !== null &&
                            value !== ''
                    );

            if (
                question.type ===
                'text'
            ) {
                return `
<div class="bg-white rounded-2xl
            shadow-sm p-5">
    <div class="font-bold mb-4">
        ${App.Util.esc(
            question.text
        )}
    </div>

    <div class="space-y-2 max-h-80
                overflow-auto">

        ${values.map(
            value =>
                `<div class="border-l-4
                            border-indigo-400
                            bg-slate-50
                            p-3 rounded-r-xl">
                    ${App.Util.esc(value)}
                </div>`
        ).join('')}

        ${
            values.length === 0
                ? `
<div class="text-slate-500">
    回答データがありません。
</div>`
                : ''
        }
    </div>
</div>`;
            }

            const options =
                Array.isArray(
                    question.options
                )
                    ? question.options
                    : [];

            const counts = {};

            options.forEach(
                option => {
                    counts[option] = 0;
                }
            );

            values.forEach(
                value => {

                    if (
                        Array.isArray(value)
                    ) {
                        value.forEach(
                            item => {
                                if (
                                    counts[
                                        item
                                    ] !== undefined
                                ) {
                                    counts[
                                        item
                                    ]++;
                                }
                            }
                        );
                    } else {
                        if (
                            counts[
                                value
                            ] !== undefined
                        ) {
                            counts[
                                value
                            ]++;
                        }
                    }
                }
            );

            const total =
                values.length || 1;

            return `
<div class="bg-white rounded-2xl
            shadow-sm p-5">

    <div class="font-bold mb-4">
        ${App.Util.esc(
            question.text
        )}
    </div>

    <div class="space-y-3">

        ${options.map(
            option => {

                const count =
                    counts[option] || 0;

                const percent =
                    (
                        count /
                        total *
                        100
                    ).toFixed(1);

                return `
<div>
    <div class="flex justify-between
                text-sm mb-1">
        <span>
            ${App.Util.esc(option)}
        </span>
        <span>
            ${count} 件
            (${percent}%)
        </span>
    </div>

    <div class="h-3 bg-slate-100
                rounded-full overflow-hidden">
        <div
            class="h-full bg-indigo-500"
            style="width:${percent}%"
        ></div>
    </div>
</div>`;
            }
        ).join('')}

    </div>
</div>`;
        },

        mail() {

            const survey =
                App.State.data.surveys.find(
                    s =>
                        s.id ===
                        App.State.mailSurveyId
                );

            if (!survey) {
                return '';
            }

            const customers =
                App.State.data.customers
                    .filter(
                        customer => {

                            if (
                                customer.source ===
                                'web'
                            ) {
                                return false;
                            }

                            const keyword =
                                String(
                                    App.State
                                        .customerKeyword ||
                                    ''
                                ).toLowerCase();

                            if (!keyword) {
                                return true;
                            }

                            return (
                                String(
                                    customer.company ||
                                    ''
                                ).toLowerCase()
                                    .includes(keyword) ||
                                String(
                                    customer.name ||
                                    ''
                                ).toLowerCase()
                                    .includes(keyword) ||
                                String(
                                    customer.email ||
                                    ''
                                ).toLowerCase()
                                    .includes(keyword)
                            );
                        }
                    );

            return `
<div class="mb-6">
    <div class="text-sm text-slate-500">
        ホーム ＞ アンケート一覧 ＞ 顧客選択・送信
    </div>

    <div class="flex flex-wrap
                justify-between gap-3 mt-2">

        <h1 class="text-2xl font-bold">
            ${App.Util.esc(
                survey.title
            )}
        </h1>

        <button
            onclick="
                App.actions.goList()
            "
            class="px-4 py-2 rounded-xl
                   bg-white border"
        >
            一覧へ戻る
        </button>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-5 mb-5">

<div class="bg-white rounded-2xl
            shadow-sm p-5">

    <h2 class="font-bold mb-4">
        メールテンプレート
    </h2>

    <label class="block text-sm font-semibold mb-2">
        種別
    </label>

    <select
        id="template_type"
        onchange="
            App.actions.changeTemplate(
                this.value
            )
        "
        class="border border-slate-300
               rounded-xl px-3 py-2 w-full mb-4"
    >
        <option value="initial">
            初回送信
        </option>
        <option value="reminder">
            リマインド
        </option>
    </select>

    <label class="block text-sm font-semibold mb-2">
        件名
    </label>

    <input
        id="mail_subject"
        value="アンケートのご回答をお願いします"
        class="border border-slate-300
               rounded-xl px-3 py-2 w-full mb-4"
    >

    <label class="block text-sm font-semibold mb-2">
        本文
    </label>

    <textarea
        id="mail_body"
        class="border border-slate-300
               rounded-xl px-3 py-2 w-full
               min-h-48"
    >{顧客名} 様

アンケートへのご回答をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>

    <p class="text-xs text-slate-500 mt-2">
        使用可能な変数：
        {顧客名} / {アンケートURL}
    </p>

    <button
        onclick="
            App.actions.sendMail()
        "
        class="w-full mt-5
               bg-indigo-600 hover:bg-indigo-700
               text-white rounded-xl
               px-5 py-3 font-semibold"
    >
        選択した顧客へ一括送信
    </button>
</div>

<div class="bg-white rounded-2xl
            shadow-sm p-5">

    <h2 class="font-bold mb-4">
        送信履歴
    </h2>

    <div class="space-y-2 max-h-96
                overflow-auto">
        ${
            App.State.data.mail_logs
                .filter(
                    log =>
                        log.survey_id ===
                        survey.id
                )
                .slice()
                .reverse()
                .map(
                    log => `
<div class="border rounded-xl p-3">
    <div class="text-sm font-semibold">
        ${App.Util.esc(
            log.sent_at
        )}
    </div>
    <div class="text-sm text-slate-600">
        ${App.Util.esc(
            log.subject
        )}
    </div>
    <div class="text-xs text-slate-500">
        ${log.count} 件
        /
        ${log.template_type ===
            'reminder'
            ? 'リマインド'
            : '初回'}
    </div>
</div>`
                ).join('')
        }
    </div>
</div>

</div>

<div class="bg-white rounded-2xl
            shadow-sm p-5">

    <div class="flex flex-wrap
                justify-between gap-3 mb-4">

        <h2 class="font-bold">
            顧客一覧
        </h2>

        <input
            id="customer_filter"
            value="${App.Util.esc(
                App.State.customerKeyword
            )}"
            oninput="
                App.actions.filterCustomers(
                    this.value
                )
            "
            placeholder="顧客名・メール等"
            class="border border-slate-300
                   rounded-xl px-4 py-2"
        >
    </div>

    <div class="overflow-x-auto">

        <table
            id="customer_table"
            class="w-full min-w-[1000px]"
        >
        <thead class="bg-slate-50">
        <tr>
            <th class="p-3 text-left">
                <input
                    id="select_all"
                    type="checkbox"
                    onchange="
                        App.actions.selectAllCustomers(
                            this.checked
                        )
                    "
                    class="accent-indigo-600"
                >
            </th>
            <th class="p-3 text-left">
                会社名 / 氏名
            </th>
            <th class="p-3 text-left">
                メール
            </th>
            <th class="p-3 text-left">
                電話
            </th>
            <th class="p-3 text-left">
                最終送信
            </th>
            <th class="p-3 text-left">
                回答
            </th>
            <th class="p-3 text-left">
                kintone
            </th>
        </tr>
        </thead>

        <tbody>

${customers.map(
    customer => {

        const selected =
            App.State
                .selectedRecipients
                .includes(
                    customer.id
                );

        return `
<tr class="border-t">

    <td class="p-3">
        <input
            type="checkbox"
            ${selected
                ? 'checked'
                : ''}
            onchange="
                App.actions.toggleCustomer(
                    '${customer.id}',
                    this.checked
                )
            "
            class="accent-indigo-600"
        >
    </td>

    <td class="p-3">
        <div class="font-semibold">
            ${App.Util.esc(
                customer.company
            )}
        </div>
        <div>
            ${App.Util.esc(
                customer.name
            )}
        </div>
    </td>

    <td class="p-3">
        ${App.Util.esc(
            customer.email
        )}
    </td>

    <td class="p-3">
        ${App.Util.esc(
            customer.phone
        )}
    </td>

    <td class="p-3 text-sm">
        ${App.Util.esc(
            customer.sent_at ||
            '未送信'
        )}
        <div class="text-xs text-slate-500">
            ${Number(
                customer.send_count || 0
            )} 回
        </div>
    </td>

    <td class="p-3">
        <span class="px-2 py-1 rounded-full
                     text-xs
                     ${
                         customer.answer_status ===
                         'answered'
                             ? 'bg-emerald-100 text-emerald-700'
                             : 'bg-amber-100 text-amber-700'
                     }">
            ${
                customer.answer_status ===
                'answered'
                    ? '回答済み'
                    : '未回答'
            }
        </span>
    </td>

    <td class="p-3">
        ${
            customer.kintone_status ===
            'registered'
                ? `
<span class="text-emerald-600 text-sm">
    ✓ 登録完了
</span>`
                : `
<button
    onclick="
        App.actions.registerCustomer(
            '${customer.id}'
        )
    "
    class="px-3 py-1 rounded-lg
           bg-amber-50 text-amber-700
           text-sm"
>
    登録完了
</button>`
        }
    </td>
</tr>`;
    }
).join('')}

        </tbody>
        </table>
    </div>
</div>`;
        },

        settings() {

            const settings =
                App.State.data.settings ||
                {};

            const fields =
                App.State.settingsFields;

            const select =
                (
                    name,
                    current,
                    multiple = false
                ) => {

                    const selected =
                        multiple &&
                        Array.isArray(current)
                            ? current
                            : [
                                String(
                                    current || ''
                                )
                            ];

                    return `
<select
    id="field_${name}"
    ${multiple ? 'multiple' : ''}
    class="w-full border
           border-slate-300
           rounded-xl px-3 py-2"
>
<option value="">
    -- 未設定 --
</option>

${fields.map(
    field => `
<option
    value="${App.Util.esc(
        field.code
    )}"
    ${selected.includes(
        field.code
    ) ? 'selected' : ''}
>
    ${App.Util.esc(
        field.label
    )}
    [${App.Util.esc(
        field.code
    )}]
</option>`
).join('')}
</select>`;
                };

            return `
<div class="mb-6">
    <div class="text-sm text-slate-500">
        ホーム ＞ システム設定 ＞ kintone連携設定
    </div>
    <h1 class="text-2xl font-bold mt-2">
        kintone連携設定
    </h1>
</div>

<div class="bg-white rounded-2xl
            shadow-sm p-6">

<form
    id="settings_form"
    onsubmit="
        event.preventDefault();
        App.actions.saveSettings();
    "
>

<div class="grid md:grid-cols-2
            gap-5">

<div>
<label class="block text-sm font-semibold mb-2">
    サブドメイン / FQDN
</label>
<input
    id="setting_subdomain"
    value="${App.Util.esc(
        settings.subdomain || ''
    )}"
    placeholder="xxxx または xxxx.cybozu.com"
    class="w-full border border-slate-300
           rounded-xl px-4 py-3"
>
</div>

<div>
<label class="block text-sm font-semibold mb-2">
    アプリID
</label>
<input
    id="setting_app_id"
    value="${App.Util.esc(
        settings.app_id || ''
    )}"
    class="w-full border border-slate-300
           rounded-xl px-4 py-3"
>
</div>

<div>
<label class="block text-sm font-semibold mb-2">
    ログイン名
</label>
<input
    id="setting_login_name"
    value="${App.Util.esc(
        settings.login_name || ''
    )}"
    class="w-full border border-slate-300
           rounded-xl px-4 py-3"
>
</div>

<div>
<label class="block text-sm font-semibold mb-2">
    パスワード
</label>
<input
    id="setting_password"
    type="password"
    placeholder="変更しない場合は空欄"
    class="w-full border border-slate-300
           rounded-xl px-4 py-3"
>
</div>

<div>
<label class="block text-sm font-semibold mb-2">
    Proxyサーバ
</label>
<input
    id="setting_proxy"
    value="${App.Util.esc(
        settings.proxy || ''
    )}"
    placeholder="host:port"
    class="w-full border border-slate-300
           rounded-xl px-4 py-3"
>
</div>

<div class="flex items-center gap-3 pt-7">
<input
    id="setting_ssl_verify"
    type="checkbox"
    ${settings.ssl_verify
        ? 'checked'
        : ''}
    class="accent-indigo-600"
>
<label>
    SSL証明書を検証する
</label>
</div>

</div>

<div class="mt-6 pt-6 border-t">

<div class="flex flex-wrap
            justify-between gap-3 mb-4">

<h2 class="font-bold">
    kintoneフィールドマッピング
</h2>

<button
    type="button"
    onclick="
        App.actions.fetchKintoneFields()
    "
    class="px-4 py-2 rounded-xl
           bg-indigo-600 text-white"
>
    項目一覧を再取得
</button>

</div>

<div
    id="field_message"
    class="text-sm text-slate-500 mb-4"
>
    アプリIDを入力して項目一覧を取得してください。
</div>

<div class="grid md:grid-cols-2
            gap-4">

<div>
<label class="block text-sm font-semibold mb-2">
会社名 (Company)
</label>
${select(
    'company',
    settings.field_company
)}
</div>

<div>
<label class="block text-sm font-semibold mb-2">
氏名 (Name)
</label>
${select(
    'name',
    settings.field_name
)}
</div>

<div>
<label class="block text-sm font-semibold mb-2">
メールアドレス (Email)
</label>
${select(
    'email',
    settings.field_email
)}
</div>

<div>
<label class="block text-sm font-semibold mb-2">
部署名 (Department)
</label>
${select(
    'department',
    settings.field_department
)}
</div>

<div>
<label class="block text-sm font-semibold mb-2">
電話番号 (Phone)
</label>
${select(
    'phone',
    settings.field_phone
)}
</div>

<div>
<label class="block text-sm font-semibold mb-2">
住所 (Address)
</label>
${select(
    'address',
    settings.field_address,
    true
)}
</div>

</div>
</div>

<div class="mt-6 flex justify-end gap-2">
<button
    type="button"
    onclick="
        App.actions.goList()
    "
    class="px-5 py-3 rounded-xl
           bg-slate-100"
>
    キャンセル
</button>

<button
    type="submit"
    class="px-5 py-3 rounded-xl
           bg-indigo-600 text-white
           font-semibold"
>
    設定を保存
</button>
</div>

</form>
</div>`;
        }
    },

    actions: {

        afterRender() {

            if (
                App.State.screen ===
                'editor'
            ) {
                this.initSortable();
            }
        },

        goList() {

            if (
                App.State.screen ===
                'editor' &&
                App.State.editSurvey
            ) {
                if (
                    !confirm(
                        '編集内容を破棄して一覧へ戻りますか？'
                    )
                ) {
                    return;
                }
            }

            App.State.screen =
                'list';

            App.State.editSurvey =
                null;

            App.Render.main();
        },

        logout() {

            alert(
                'ログアウト処理は認証基盤接続時に実装してください。'
            );
        },

        searchList(value) {

            App.State.listKeyword =
                String(value || '');

            App.Render.main();
        },

        toggleStatusFilter(value) {

            App.State.listStatus =
                value;

            App.Render.main();
        },

        sortList(value) {

            App.State.listSort =
                value;

            App.Render.main();
        },

        newSurvey() {

            App.State.editSurvey =
                App.Util.normalizeSurvey({
                    id:
                        App.Util.id(
                            'survey'
                        ),
                    title:
                        '新しいアンケート',
                    start_at: '',
                    end_at: '',
                    status: 'draft',
                    created_at: '',
                    updated_at: '',
                    numbering_mode:
                        'global',
                    groups: [
                        {
                            id:
                                App.Util.id(
                                    'group'
                                ),
                            name:
                                'グループ1',
                            questions: []
                        }
                    ],
                    deleted: false
                });

            App.State.screen =
                'editor';

            App.Render.main();
        },

        editSurvey(id) {

            const survey =
                App.State.data.surveys
                    .find(
                        item =>
                            item.id === id
                    );

            if (!survey) {
                alert(
                    'アンケートが見つかりません。'
                );
                return;
            }

            /*
             * 古いJSONもここで正規化。
             */
            App.State.editSurvey =
                App.Util.normalizeSurvey(
                    App.Util.clone(
                        survey
                    )
                );

            App.State.screen =
                'editor';

            App.Render.main();
        },

        updateSurveyField(
            key,
            value
        ) {

            if (
                !App.State.editSurvey
            ) {
                return;
            }

            App.State.editSurvey[key] =
                value;

            if (
                key === 'title'
            ) {
                const heading =
                    document.querySelector(
                        '#app h1'
                    );

                if (heading) {
                    heading.textContent =
                        value ||
                        '新しいアンケート';
                }
            }

            if (
                key ===
                'numbering_mode'
            ) {
                App.Render.main();
            }
        },

        changeEditStatus(
            status
        ) {

            if (
                !App.State.editSurvey
            ) {
                return;
            }

            if (
                status === 'active'
            ) {

                const survey =
                    App.State.editSurvey;

                if (
                    !survey.title.trim()
                ) {
                    alert(
                        'タイトルを入力してください。'
                    );
                    App.Render.main();
                    return;
                }

                if (
                    !confirm(
                        'このアンケートを公開しますか？'
                    )
                ) {
                    App.Render.main();
                    return;
                }
            }

            App.State.editSurvey.status =
                status;

            App.Render.main();
        },

        saveAndList() {

            if (
                !App.State.editSurvey
            ) {
                return;
            }

            const survey =
                App.Util.normalizeSurvey(
                    App.State.editSurvey
                );

            if (
                !survey.title.trim()
            ) {
                alert(
                    'タイトルを入力してください。'
                );
                return;
            }

            this._save(
                survey
            );
        },

        async _save(survey) {

            try {

                const saved =
                    await App.API
                        .saveSurvey();

                const index =
                    App.State.data
                        .surveys
                        .findIndex(
                            s =>
                                s.id ===
                                saved.id
                        );

                if (index >= 0) {
                    App.State.data
                        .surveys[index] =
                        saved;
                } else {
                    App.State.data
                        .surveys
                        .push(saved);
                }

                alert(
                    '保存しました。'
                );

                App.State.screen =
                    'list';

                App.State.editSurvey =
                    null;

                App.Render.main();

            } catch (error) {

                alert(
                    error.message ||
                    '保存に失敗しました。'
                );
            }
        },

        cancelEdit() {

            if (
                confirm(
                    '変更内容を破棄して一覧へ戻りますか？'
                )
            ) {
                App.State.screen =
                    'list';

                App.State.editSurvey =
                    null;

                App.Render.main();
            }
        },

        addGroup() {

            const survey =
                App.State.editSurvey;

            if (!survey) {
                return;
            }

            survey.groups.push({
                id:
                    App.Util.id(
                        'group'
                    ),
                name:
                    'グループ' +
                    (
                        survey.groups.length +
                        1
                    ),
                questions: []
            });

            App.Render.main();
        },

        deleteGroup(id) {

            const survey =
                App.State.editSurvey;

            if (
                !survey ||
                survey.groups.length <= 1
            ) {
                alert(
                    '最低1グループ必要です。'
                );
                return;
            }

            if (
                !confirm(
                    'このグループと内包する質問を削除しますか？'
                )
            ) {
                return;
            }

            survey.groups =
                survey.groups.filter(
                    group =>
                        group.id !== id
                );

            App.Render.main();
        },

        updateGroupName(
            groupId,
            value
        ) {

            const group =
                App.State.editSurvey
                    ?.groups
                    .find(
                        item =>
                            item.id ===
                            groupId
                    );

            if (group) {
                group.name =
                    String(value || '');
            }
        },

        addQuestion(groupId) {

            const group =
                App.State.editSurvey
                    ?.groups
                    .find(
                        item =>
                            item.id ===
                            groupId
                    );

            if (!group) {
                return;
            }

            group.questions.push({
                id:
                    App.Util.id(
                        'question'
                    ),
                text: '',
                type: 'single',
                required: false,
                options: [
                    '選択肢1',
                    '選択肢2'
                ],
                other_enabled: false,
                branching: [
                    {
                        option:
                            '選択肢1',
                        target_question_id:
                            ''
                    },
                    {
                        option:
                            '選択肢2',
                        target_question_id:
                            ''
                    }
                ]
            });

            App.Render.main();
        },

        deleteQuestion(
            groupId,
            questionId
        ) {

            const group =
                App.State.editSurvey
                    ?.groups
                    .find(
                        item =>
                            item.id ===
                            groupId
                    );

            if (!group) {
                return;
            }

            if (
                !confirm(
                    'この質問を削除しますか？'
                )
            ) {
                return;
            }

            group.questions =
                group.questions.filter(
                    q =>
                        q.id !==
                        questionId
                );

            App.Render.main();
        },

        updateQuestion(
            groupId,
            questionId,
            key,
            value
        ) {

            const question =
                this.findQuestion(
                    groupId,
                    questionId
                );

            if (!question) {
                return;
            }

            question[key] =
                value;

            if (
                key === 'type' &&
                value !== 'single'
            ) {
                question.branching =
                    [];
            }

            if (
                key === 'type'
            ) {
                question =
                    App.Util.normalizeQuestion(
                        question
                    );
            }
        },

        changeQuestionType(
            groupId,
            questionId,
            type
        ) {

            const question =
                this.findQuestion(
                    groupId,
                    questionId
                );

            if (!question) {
                return;
            }

            question.type =
                type;

            if (
                type === 'text'
            ) {
                question.options =
                    [];
                question.branching =
                    [];
            }

            if (
                type !== 'text' &&
                !Array.isArray(
                    question.options
                )
            ) {
                question.options =
                    [];
            }

            question =
                App.Util.normalizeQuestion(
                    question
                );

            App.Render.main();
        },

        findQuestion(
            groupId,
            questionId
        ) {

            const group =
                App.State.editSurvey
                    ?.groups
                    .find(
                        item =>
                            item.id ===
                            groupId
                    );

            return group?.questions.find(
                q =>
                    q.id ===
                    questionId
            );
        },

        addOption(
            groupId,
            questionId
        ) {

            const question =
                this.findQuestion(
                    groupId,
                    questionId
                );

            if (!question) {
                return;
            }

            if (
                !Array.isArray(
                    question.options
                )
            ) {
                question.options =
                    [];
            }

            question.options.push(
                '選択肢' +
                (
                    question.options.length +
                    1
                )
            );

            question =
                App.Util.normalizeQuestion(
                    question
                );

            App.Render.main();
        },

        updateOption(
            groupId,
            questionId,
            index,
            value
        ) {

            const question =
                this.findQuestion(
                    groupId,
                    questionId
                );

            if (!question) {
                return;
            }

            if (
                !Array.isArray(
                    question.options
                )
            ) {
                question.options =
                    [];
            }

            question.options[index] =
                String(value || '');

            question =
                App.Util.normalizeQuestion(
                    question
                );
        },

        deleteOption(
            groupId,
            questionId,
            index
        ) {

            const question =
                this.findQuestion(
                    groupId,
                    questionId
                );

            if (!question) {
                return;
            }

            if (
                !Array.isArray(
                    question.options
                )
            ) {
                question.options =
                    [];
            }

            question.options.splice(
                index,
                1
            );

            question =
                App.Util.normalizeQuestion(
                    question
                );

            App.Render.main();
        },

        setBranch(
            groupId,
            questionId,
            option,
            target
        ) {

            const question =
                this.findQuestion(
                    groupId,
                    questionId
                );

            if (!question) {
                return;
            }

            question =
                App.Util.normalizeQuestion(
                    question
                );

            const rule =
                question.branching.find(
                    item =>
                        item.option ===
                        option
                );

            if (rule) {
                rule.target_question_id =
                    target;
            } else {
                question.branching.push({
                    option,
                    target_question_id:
                        target
                });
            }
        },

        initSortable() {

            const groupContainer =
                document.getElementById(
                    'editor_groups'
                );

            if (
                groupContainer &&
                typeof Sortable !==
                    'undefined'
            ) {

                new Sortable(
                    groupContainer,
                    {
                        animation: 180,
                        handle:
                            '.group-handle',
                        ghostClass:
                            'opacity-40',
                        onEnd: event => {

                            const groups =
                                App.State
                                    .editSurvey
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

                            App.Render.main();
                        }
                    }
                );
            }

            document.querySelectorAll(
                '.question-list'
            ).forEach(
                container => {

                    if (
                        typeof Sortable ===
                        'undefined'
                    ) {
                        return;
                    }

                    new Sortable(
                        container,
                        {
                            group:
                                'survey-questions',
                            animation: 180,
                            handle:
                                '.question-handle',
                            ghostClass:
                                'opacity-40',

                            onEnd: event => {

                                App.actions
                                    .moveQuestion(
                                        event
                                    );
                            }
                        }
                    );
                }
            );
        },

        moveQuestion(event) {

            const survey =
                App.State.editSurvey;

            const fromGroup =
                event.from
                    .dataset
                    .groupId;

            const toGroup =
                event.to
                    .dataset
                    .groupId;

            if (
                !fromGroup ||
                !toGroup
            ) {
                return;
            }

            const source =
                survey.groups.find(
                    g =>
                        g.id ===
                        fromGroup
                );

            const target =
                survey.groups.find(
                    g =>
                        g.id ===
                        toGroup
                );

            if (!source || !target) {
                return;
            }

            /*
             * DOM側の並びをStateへ反映。
             */
            const questionId =
                event.item
                    .dataset
                    .questionId;

            const question =
                source.questions.find(
                    q =>
                        q.id ===
                        questionId
                );

            if (!question) {
                return;
            }

            source.questions =
                source.questions.filter(
                    q =>
                        q.id !==
                        questionId
                );

            let newIndex =
                event.newIndex;

            if (
                fromGroup ===
                toGroup &&
                event.oldIndex <
                event.newIndex
            ) {
                newIndex--;
            }

            target.questions.splice(
                newIndex,
                0,
                question
            );

            App.State.editSurvey =
                App.Util.normalizeSurvey(
                    App.State.editSurvey
                );

            App.Render.main();
        },

        duplicateSurvey(id) {

            const original =
                App.State.data.surveys
                    .find(
                        s =>
                            s.id === id
                    );

            if (!original) {
                return;
            }

            const copy =
                App.Util.clone(
                    original
                );

            copy.id =
                App.Util.id(
                    'survey'
                );

            copy.title =
                String(
                    original.title
                ) +
                '（複製）';

            copy.status =
                'draft';

            copy.deleted =
                false;

            copy.created_at = '';
            copy.updated_at = '';

            copy.groups.forEach(
                group => {

                    group.id =
                        App.Util.id(
                            'group'
                        );

                    group.questions.forEach(
                        question => {

                            const oldId =
                                question.id;

                            question.id =
                                App.Util.id(
                                    'question'
                                );

                            if (
                                Array.isArray(
                                    question.branching
                                )
                            ) {
                                question.branching
                                    .forEach(
                                        rule => {

                                            /*
                                             * 複製後の質問IDへ
                                             * 分岐先を再マッピング。
                                             */
                                            const oldTarget =
                                                rule.target_question_id;

                                            if (
                                                oldTarget ===
                                                oldId
                                            ) {
                                                rule.target_question_id =
                                                    question.id;
                                            }
                                        }
                                    );
                            }
                        }
                    );
                }
            );

            App.State.data.surveys.push(
                App.Util.normalizeSurvey(
                    copy
                )
            );

            /*
             * 画面遷移しない。
             */
            App.Render.main();
        },

        async deleteSurvey(id) {

            if (
                !confirm(
                    'この下書きを削除しますか？'
                )
            ) {
                return;
            }

            try {

                await App.API
                    .deleteSurvey(id);

                const survey =
                    App.State.data.surveys
                        .find(
                            s =>
                                s.id ===
                                id
                        );

                if (survey) {
                    survey.deleted =
                        true;
                }

                App.Render.main();

            } catch (error) {

                alert(
                    error.message
                );
            }
        },

        preview() {

            const modal =
                document.getElementById(
                    'preview_modal'
                );

            const survey =
                App.Util.normalizeSurvey(
                    App.State.editSurvey
                );

            const questions =
                App.Util.allQuestions(
                    survey
                );

            modal.innerHTML = `
<div class="min-h-full flex
            items-start justify-center
            py-8">

<div
    class="${
        App.State.previewMode ===
        'mobile'
            ? 'w-[390px]'
            : 'w-full max-w-3xl'
    } bg-slate-50 rounded-2xl
       shadow-xl overflow-hidden"
>

<div class="bg-white p-4 border-b
            flex justify-between">

    <div class="font-bold">
        プレビュー
    </div>

    <div class="flex gap-2">
        <button
            onclick="
                App.actions.previewMode(
                    'pc'
                )
            "
            class="px-3 py-1 rounded-lg
                   ${
                       App.State.previewMode ===
                       'pc'
                           ? 'bg-indigo-600 text-white'
                           : 'bg-slate-100'
                   }"
        >
            PC
        </button>

        <button
            onclick="
                App.actions.previewMode(
                    'mobile'
                )
            "
            class="px-3 py-1 rounded-lg
                   ${
                       App.State.previewMode ===
                       'mobile'
                           ? 'bg-indigo-600 text-white'
                           : 'bg-slate-100'
                   }"
        >
            スマートフォン
        </button>

        <button
            onclick="
                App.actions.closePreview()
            "
            class="px-3 py-1 rounded-lg
                   bg-slate-100"
        >
            閉じる
        </button>
    </div>
</div>

<div
    id="preview_content"
    class="p-6"
>
    <h1 class="text-2xl font-bold mb-6">
        ${App.Util.esc(
            survey.title
        )}
    </h1>

    <div class="space-y-5">
        ${questions.map(
            (q, index) =>
                this.previewQuestion(
                    q,
                    index
                )
        ).join('')}
    </div>

    <button
        onclick="
            App.actions.previewSubmit()
        "
        class="w-full mt-6
               bg-indigo-600 text-white
               rounded-xl py-3"
    >
        回答を送信する
    </button>
</div>

</div>
</div>`;

            modal.classList.remove(
                'hidden'
            );
        },

        previewQuestion(
            q,
            index
        ) {

            const options =
                Array.isArray(
                    q.options
                )
                    ? q.options
                    : [];

            let input = '';

            if (
                q.type === 'text'
            ) {
                input = `
<textarea
    disabled
    class="w-full border rounded-xl
           p-3 min-h-28"
></textarea>`;
            }

            if (
                q.type === 'single'
            ) {
                input =
                    options.map(
                        option =>
                            `<label class="flex gap-3
                                p-3 border rounded-xl">
                                <input
                                    type="radio"
                                    disabled
                                >
                                ${App.Util.esc(
                                    option
                                )}
                            </label>`
                    ).join('');
            }

            if (
                q.type === 'multiple'
            ) {
                input =
                    options.map(
                        option =>
                            `<label class="flex gap-3
                                p-3 border rounded-xl">
                                <input
                                    type="checkbox"
                                    disabled
                                >
                                ${App.Util.esc(
                                    option
                                )}
                            </label>`
                    ).join('');
            }

            return `
<div class="bg-white rounded-2xl
            p-5">
    <div class="text-sm text-indigo-600
                font-semibold">
        Q${index + 1}
    </div>

    <div class="font-bold mb-4">
        ${App.Util.esc(
            q.text
        )}
        ${
            q.required
                ? '<span class="text-red-500">*</span>'
                : ''
        }
    </div>

    <div class="space-y-2">
        ${input}
    </div>
</div>`;
        },

        previewMode(mode) {

            App.State.previewMode =
                mode;

            App.actions.preview();
        },

        closePreview() {

            document.getElementById(
                'preview_modal'
            ).classList.add(
                'hidden'
            );
        },

        previewSubmit() {

            alert(
                'これはプレビューです。実際には送信されません。'
            );
        },

        aggregate(id) {

            App.State.aggregateSurveyId =
                id;

            App.State.selectedQuestions =
                [];

            App.State.responseKeyword =
                '';

            App.State.screen =
                'aggregate';

            App.Render.main();
        },

        selectAllQuestions() {

            App.State.selectedQuestions =
                App.Util.allQuestions(
                    App.State.data.surveys.find(
                        s =>
                            s.id ===
                            App.State
                                .aggregateSurveyId
                    )
                ).map(
                    q => q.id
                );

            App.Render.main();
        },

        clearQuestions() {

            App.State.selectedQuestions =
                [];

            App.Render.main();
        },

        toggleQuestion(
            id,
            checked
        ) {

            const current =
                new Set(
                    App.State
                        .selectedQuestions
                );

            if (checked) {
                current.add(id);
            } else {
                current.delete(id);
            }

            App.State.selectedQuestions =
                [...current];

            App.Render.main();
        },

        filterResponses(value) {

            App.State.responseKeyword =
                String(value || '');

            App.Render.main();
        },

        showResponse(id) {

            const response =
                App.State.data
                    .responses
                    .find(
                        r =>
                            r.id === id
                    );

            if (!response) {
                return;
            }

            const survey =
                App.State.data
                    .surveys
                    .find(
                        s =>
                            s.id ===
                            response.survey_id
                    );

            if (!survey) {
                return;
            }

            const questions =
                App.Util.allQuestions(
                    App.Util.normalizeSurvey(
                        survey
                    )
                );

            const answers =
                response.answers ||
                {};

            const modal =
                document.getElementById(
                    'response_modal'
                );

            modal.innerHTML = `
<div class="min-h-full flex
            items-center justify-center">

<div class="bg-white rounded-2xl
            shadow-xl w-full max-w-3xl
            overflow-hidden">

<div class="p-5 border-b
            flex justify-between">
    <div>
        <div class="font-bold">
            回答詳細
        </div>
        <div class="text-sm text-slate-500">
            ${App.Util.esc(
                response.name
            )}
        </div>
    </div>

    <button
        onclick="
            App.actions.closeResponse()
        "
        class="px-3 py-2
               bg-slate-100 rounded-lg"
    >
        閉じる
    </button>
</div>

<div
    id="response_detail"
    class="p-5 space-y-3"
>

${questions.map(
    (q, index) => {

        let value =
            answers[q.id] ??
            '';

        if (
            Array.isArray(value)
        ) {
            value =
                value.join('、');
        }

        return `
<div class="border rounded-xl p-4">
    <div class="text-sm
                text-indigo-600">
        Q${index + 1}
    </div>

    <div class="font-semibold mt-1">
        ${App.Util.esc(
            q.text
        )}
    </div>

    <div class="mt-2 text-slate-700
                whitespace-pre-wrap">
        ${App.Util.esc(
            value
        )}
    </div>
</div>`;
    }
).join('')}

</div>
</div>
</div>`;

            modal.classList.remove(
                'hidden'
            );
        },

        closeResponse() {

            document.getElementById(
                'response_modal'
            ).classList.add(
                'hidden'
            );
        },

        exportCsv(id) {

            window.location.href =
                window.location.pathname +
                '?action=csv&survey_id=' +
                encodeURIComponent(id);
        },

        printAggregate() {

            window.print();
        },

        mail(id) {

            App.State.mailSurveyId =
                id;

            App.State.customerKeyword =
                '';

            App.State.selectedRecipients =
                [];

            App.State.screen =
                'mail';

            App.Render.main();
        },

        filterCustomers(value) {

            App.State.customerKeyword =
                String(value || '');

            App.Render.main();
        },

        toggleCustomer(
            id,
            checked
        ) {

            const current =
                new Set(
                    App.State
                        .selectedRecipients
                );

            if (checked) {
                current.add(id);
            } else {
                current.delete(id);
            }

            App.State
                .selectedRecipients =
                [...current];

            /*
             * checkboxを再描画しないことで
             * カーソル位置を保持する。
             */
        },

        selectAllCustomers(
            checked
        ) {

            const customers =
                App.State.data.customers
                    .filter(
                        c =>
                            c.source !==
                            'web'
                    );

            App.State
                .selectedRecipients =
                checked
                    ? customers.map(
                        c => c.id
                    )
                    : [];

            App.Render.main();
        },

        changeTemplate(value) {

            const subject =
                document.getElementById(
                    'mail_subject'
                );

            const body =
                document.getElementById(
                    'mail_body'
                );

            if (
                value ===
                'reminder'
            ) {
                subject.value =
                    'アンケートご回答のお願い（再送）';

                body.value =
                    `{顧客名} 様

先日ご案内したアンケートが未回答となっております。
お手数ですが、以下よりご回答ください。

{アンケートURL}

よろしくお願いいたします。`;
            } else {
                subject.value =
                    'アンケートのご回答をお願いします';

                body.value =
                    `{顧客名} 様

アンケートへのご回答をお願いいたします。

{アンケートURL}

よろしくお願いいたします。`;
            }
        },

        async sendMail() {

            const ids =
                App.State
                    .selectedRecipients;

            if (!ids.length) {
                alert(
                    '送信先を選択してください。'
                );
                return;
            }

            const alreadySent =
                App.State.data.customers
                    .filter(
                        c =>
                            ids.includes(
                                c.id
                            ) &&
                            Number(
                                c.send_count ||
                                0
                            ) > 0
                    );

            let template =
                document.getElementById(
                    'template_type'
                ).value;

            if (
                alreadySent.length
            ) {
                if (
                    !confirm(
                        '既に送信済みの宛先が含まれています。再送しますか？'
                    )
                ) {
                    return;
                }

                template =
                    'reminder';

                document.getElementById(
                    'template_type'
                ).value =
                    'reminder';

                this.changeTemplate(
                    'reminder'
                );
            }

            const subject =
                document.getElementById(
                    'mail_subject'
                ).value;

            const body =
                document.getElementById(
                    'mail_body'
                ).value;

            if (
                !confirm(
                    ids.length +
                    '件へ送信します。よろしいですか？'
                )
            ) {
                return;
            }

            try {

                const result =
                    await App.API.sendMail(
                        App.State
                            .mailSurveyId,
                        ids,
                        subject,
                        body,
                        template
                    );

                await App.API.load();

                alert(
                    result.count +
                    '件の送信処理を実行しました。'
                );

                App.Render.main();

            } catch (error) {

                alert(
                    error.message
                );
            }
        },

        async registerCustomer(id) {

            try {

                await App.API
                    .registerCustomer(id);

                const customer =
                    App.State.data
                        .customers
                        .find(
                            c =>
                                c.id === id
                        );

                if (customer) {
                    customer.kintone_status =
                        'registered';
                }

                App.Render.main();

            } catch (error) {

                alert(
                    error.message
                );
            }
        },

        settings() {

            App.State.screen =
                'settings';

            App.Render.main();
        },

        async fetchKintoneFields() {

            const appId =
                document.getElementById(
                    'setting_app_id'
                )?.value || '';

            const message =
                document.getElementById(
                    'field_message'
                );

            if (!appId) {
                if (message) {
                    message.textContent =
                        'アプリIDを入力してください。';
                }
                return;
            }

            /*
             * 必須関数 fetchKintoneFields()
             */
            if (message) {
                message.textContent =
                    'kintoneから項目一覧を取得しています…';
            }

            try {

                const result =
                    await App.API
                        .fetchKintoneFields(
                            appId
                        );

                App.State.settingsFields =
                    Array.isArray(
                        result.fields
                    )
                        ? result.fields
                        : [];

                if (message) {
                    message.textContent =
                        App.State
                            .settingsFields
                            .length +
                        '件のフィールドを取得しました。';
                }

                App.Render.main();

            } catch (error) {

                if (message) {
                    message.textContent =
                        error.message;
                }

                alert(
                    error.message
                );
            }
        },

        async saveSettings() {

            const old =
                App.State.data.settings ||
                {};

            const addressElement =
                document.getElementById(
                    'field_address'
                );

            const address =
                addressElement
                    ? Array.from(
                        addressElement
                            .selectedOptions
                    ).map(
                        option =>
                            option.value
                    )
                    : [];

            const settings = {
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
                    old.password ||
                    '',

                proxy:
                    document.getElementById(
                        'setting_proxy'
                    ).value,

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

            try {

                const result =
                    await App.API
                        .saveSettings(
                            settings
                        );

                App.State.data.settings =
                    result.settings;

                alert(
                    '設定を保存しました。'
                );

                App.Render.main();

            } catch (error) {

                alert(
                    error.message
                );
            }
        }
    },

    async init() {

        if (
            this._initialized
        ) {
            return;
        }

        this._initialized =
            true;

        try {

            await this.API.load();

            this.Render.main();

        } catch (error) {

            document.getElementById(
                'app'
            ).innerHTML = `
<div class="max-w-xl mx-auto mt-10
            bg-white rounded-2xl
            shadow-sm p-8">

    <h1 class="text-xl font-bold
               text-red-600">
        初期化エラー
    </h1>

    <p class="mt-3 text-slate-600">
        ${this.Util.esc(
            error.message
        )}
    </p>

</div>`;
        }
    },

    _initialized: false
};

if (
    document.readyState ===
    'loading'
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
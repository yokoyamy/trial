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

/* ================================================================
 * normalization
 * ================================================================ */

function survey_normalize_question(mixed $question): array
{
    $q = is_array($question)
        ? $question
        : [];

    $q['id'] = (string)(
        $q['id'] ?? survey_id('question')
    );

    $q['text'] = (string)(
        $q['text'] ?? ''
    );

    $type = (string)(
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
    $q['other_enabled'] =
        !empty($q['other_enabled']);

    $options = $q['options'] ?? [];

    if (!is_array($options)) {
        $options = [];
    }

    $q['options'] = array_values(
        array_map(
            static fn(mixed $v): string =>
                (string)$v,
            $options
        )
    );

    $branching =
        $q['branching'] ?? [];

    if (!is_array($branching)) {
        $branching = [];
    }

    $normalized = [];

    foreach ($branching as $item) {
        if (!is_array($item)) {
            continue;
        }

        $normalized[] = [
            'option' =>
                (string)($item['option'] ?? ''),
            'target_question_id' =>
                (string)(
                    $item['target_question_id']
                    ?? ''
                )
        ];
    }

    if ($type !== 'single') {
        $normalized = [];
    } else {
        $normalized = array_map(
            static function (
                string $option
            ) use ($normalized): array {
                foreach ($normalized as $old) {
                    if (
                        $old['option'] ===
                        $option
                    ) {
                        return $old;
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

    $q['branching'] = $normalized;

    return $q;
}

function survey_normalize_survey(
    mixed $survey
): array {
    $s = is_array($survey)
        ? $survey
        : [];

    $s['id'] = (string)(
        $s['id'] ?? survey_id('survey')
    );

    $s['title'] = (string)(
        $s['title'] ?? '新しいアンケート'
    );

    $s['start_at'] = (string)(
        $s['start_at'] ?? ''
    );

    $s['end_at'] = (string)(
        $s['end_at'] ?? ''
    );

    $status = (string)(
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

    $mode = (string)(
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
    $s['deleted'] = !empty($s['deleted']);

    $groups =
        $s['groups'] ?? [];

    if (!is_array($groups)) {
        $groups = [];
    }

    $result = [];

    foreach ($groups as $index => $group) {
        $g = is_array($group)
            ? $group
            : [];

        $questions =
            $g['questions'] ?? [];

        if (!is_array($questions)) {
            $questions = [];
        }

        $normalizedQuestions = [];

        foreach ($questions as $question) {
            $normalizedQuestions[] =
                survey_normalize_question(
                    $question
                );
        }

        $result[] = [
            'id' => (string)(
                $g['id']
                ?? survey_id('group')
            ),
            'name' => (string)(
                $g['name']
                ?? 'グループ' .
                ((int)$index + 1)
            ),
            'questions' =>
                $normalizedQuestions
        ];
    }

    if (!$result) {
        $result[] = [
            'id' =>
                survey_id('group'),
            'name' => 'グループ1',
            'questions' => []
        ];
    }

    $s['groups'] = $result;

    return $s;
}

function survey_normalize_all(
    array $data
): array {
    $surveys = [];

    foreach ($data['surveys'] as $survey) {
        $surveys[] =
            survey_normalize_survey(
                $survey
            );
    }

    $data['surveys'] = $surveys;

    return $data;
}

/* ================================================================
 * kintone
 *
 * 今回の修正版では、
 * 「不正なリクエスト」だけで終わらせず、
 *
 * HTTP status
 * kintone error code
 * kintone message
 * response body
 * endpoint
 * PHP warning
 * stream error
 *
 * をすべて返す。
 * ================================================================ */

function survey_normalize_kintone_host(
    string $value
): string {
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

    if (
        preg_match(
            '/\.cybozu\.com$/i',
            $value
        )
    ) {
        return 'https://' . $value;
    }

    return 'https://' .
        $value .
        '.cybozu.com';
}

function survey_http_status(): int
{
    if (!function_exists(
        'http_get_last_response_headers'
    )) {
        return 0;
    }

    $headers =
        http_get_last_response_headers();

    if (!is_array($headers)) {
        return 0;
    }

    $status = 0;

    foreach ($headers as $header) {
        if (preg_match(
            '/^HTTP\/[\d.]+\s+(\d+)/i',
            (string)$header,
            $match
        )) {
            $status = (int)$match[1];
        }
    }

    return $status;
}

function survey_header_lines(): array
{
    if (!function_exists(
        'http_get_last_response_headers'
    )) {
        return [];
    }

    $headers =
        http_get_last_response_headers();

    return is_array($headers)
        ? array_values(
            array_map(
                static fn($v) =>
                    (string)$v,
                $headers
            )
        )
        : [];
}

function survey_kintone_request(
    string $method,
    string $path,
    array $settings,
    ?array $body = null
): array {
    $base =
        survey_normalize_kintone_host(
            (string)(
                $settings['subdomain'] ?? ''
            )
        );

    if ($base === '') {
        return [
            'ok' => false,
            'status' => 0,
            'error_code' => 'CONFIG',
            'message' =>
                'kintoneサブドメインが設定されていません。',
            'endpoint' => ''
        ];
    }

    $url =
        $base . '/' .
        ltrim($path, '/');

    $login =
        (string)(
            $settings['login_name'] ?? ''
        );

    $password =
        (string)(
            $settings['password'] ?? ''
        );

    if (
        $login === '' ||
        $password === ''
    ) {
        return [
            'ok' => false,
            'status' => 0,
            'error_code' => 'CONFIG',
            'message' =>
                'kintoneログイン名とパスワードを設定してください。',
            'endpoint' => $url
        ];
    }

    $auth =
        base64_encode(
            $login . ':' . $password
        );

    $options = [
        'http' => [
            'method' =>
                strtoupper($method),
            'header' =>
                "Content-Type: application/json\r\n" .
                "Accept: application/json\r\n" .
                "X-Cybozu-Authorization: " .
                $auth . "\r\n",
            'ignore_errors' => true,
            'timeout' => 30,
            'follow_location' => 0,
            'max_redirects' => 0
        ],
        'ssl' => [
            'verify_peer' =>
                !empty(
                    $settings['ssl_verify']
                ),
            'verify_peer_name' =>
                !empty(
                    $settings['ssl_verify']
                ),
            'allow_self_signed' =>
                empty(
                    $settings['ssl_verify']
                )
        ]
    ];

    if ($body !== null) {
        $encoded =
            json_encode(
                $body,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

        if ($encoded === false) {
            return [
                'ok' => false,
                'status' => 0,
                'error_code' => 'JSON',
                'message' =>
                    'JSON生成に失敗しました。',
                'endpoint' => $url
            ];
        }

        $options['http']['content'] =
            $encoded;
    }

    $proxy = trim(
        (string)(
            $settings['proxy'] ?? ''
        )
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

        $options['http']['request_fulluri'] =
            true;
    }

    $warnings = [];

    set_error_handler(
        static function (
            int $severity,
            string $message
        ) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        }
    );

    try {
        $context =
            stream_context_create(
                $options
            );

        $raw = file_get_contents(
            $url,
            false,
            $context
        );

        $status =
            survey_http_status();

        $headers =
            survey_header_lines();

        $error = error_get_last();
    } finally {
        restore_error_handler();
    }

    $rawString =
        is_string($raw)
            ? $raw
            : '';

    $decoded =
        json_decode(
            $rawString,
            true
        );

    if (!is_array($decoded)) {
        $decoded = [];
    }

    $bodyPreview =
        $rawString !== ''
            ? mb_substr(
                $rawString,
                0,
                4000,
                'UTF-8'
            )
            : '';

    $diagnostic = [
        'endpoint' => $url,
        'status' => $status,
        'headers' => $headers,
        'response_body' => $bodyPreview,
        'php_warning' =>
            $warnings,
        'php_last_error' =>
            is_array($error)
                ? (string)(
                    $error['message'] ?? ''
                )
                : ''
    ];

    if (
        $status >= 200 &&
        $status < 300
    ) {
        return [
            'ok' => true,
            'status' => $status,
            'data' => $decoded,
            'endpoint' => $url,
            'diagnostic' => $diagnostic
        ];
    }

    $code = (string)(
        $decoded['code']
        ?? $decoded['error_code']
        ?? ''
    );

    $message = (string)(
        $decoded['message']
        ?? ''
    );

    if ($message === '') {
        if ($status === 400) {
            $message =
                'kintoneからHTTP 400（Bad Request）が返されました。'
                . 'リクエスト内容または認証方式を確認してください。';
        } elseif ($status === 401) {
            $message =
                '認証に失敗しました。ログイン名・パスワード・認証方式を確認してください。';
        } elseif ($status === 403) {
            $message =
                '権限がありません。対象アプリの権限を確認してください。';
        } elseif ($status === 404) {
            $message =
                'APIエンドポイントまたはアプリが見つかりません。';
        } elseif ($status === 0) {
            $message =
                'kintoneへ接続できませんでした。Proxy、DNS、SSL、Firewall等を確認してください。';
        } else {
            $message =
                'kintone API通信に失敗しました。';
        }
    }

    return [
        'ok' => false,
        'status' => $status,
        'error_code' => $code,
        'message' => $message,
        'data' => $decoded,
        'endpoint' => $url,
        'diagnostic' => $diagnostic
    ];
}

/* ================================================================
 * CSV
 * ================================================================ */

function survey_csv_response(
    string $filename,
    array $rows
): never {
    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="' .
        rawurlencode($filename) .
        '"'
    );

    echo "\xEF\xBB\xBF";

    $fp = fopen('php://output', 'wb');

    foreach ($rows as $row) {
        fputcsv(
            $fp,
            $row
        );
    }

    fclose($fp);
    exit;
}

/* ================================================================
 * API
 * ================================================================ */

$data =
    survey_normalize_all(
        survey_load_data()
    );

$action =
    (string)(
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
                'csrf_token' =>
                    survey_csrf()
            ]);

        case 'save_survey':

            $survey =
                json_decode(
                    (string)(
                        $_POST['survey_json']
                        ?? ''
                    ),
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

            $now = survey_now();
            $found = false;

            foreach (
                $data['surveys']
                as $i => $old
            ) {
                if (
                    (string)$old['id'] ===
                    (string)$survey['id']
                ) {
                    $survey['created_at'] =
                        $old['created_at']
                        ?? $now;

                    $survey['updated_at'] =
                        $now;

                    $data['surveys'][$i] =
                        $survey;

                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $survey['created_at'] =
                    $now;

                $survey['updated_at'] =
                    $now;

                $survey['deleted'] =
                    false;

                $data['surveys'][] =
                    $survey;
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

        case 'delete_survey':

            $id =
                (string)(
                    $_POST['survey_id']
                    ?? ''
                );

            foreach (
                $data['surveys']
                as &$survey
            ) {
                if (
                    (string)$survey['id']
                    === $id
                ) {
                    $survey['deleted'] =
                        true;

                    $survey['updated_at'] =
                        survey_now();

                    break;
                }
            }

            unset($survey);

            survey_save_data($data);

            survey_json_response([
                'ok' => true
            ]);

        case 'set_status':

            $id =
                (string)(
                    $_POST['survey_id']
                    ?? ''
                );

            $status =
                (string)(
                    $_POST['status']
                    ?? ''
                );

            if (!in_array(
                $status,
                ['draft', 'active', 'ended'],
                true
            )) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        '不正なステータスです。'
                ], 400);
            }

            foreach (
                $data['surveys']
                as &$survey
            ) {
                if (
                    (string)$survey['id']
                    === $id
                ) {
                    $survey['status'] =
                        $status;

                    $survey['updated_at'] =
                        survey_now();
                    break;
                }
            }

            unset($survey);

            survey_save_data($data);

            survey_json_response([
                'ok' => true
            ]);

        case 'duplicate_survey':

            $id =
                (string)(
                    $_POST['survey_id']
                    ?? ''
                );

            $source = null;

            foreach (
                $data['surveys']
                as $survey
            ) {
                if (
                    (string)$survey['id']
                    === $id
                ) {
                    $source = $survey;
                    break;
                }
            }

            if (!is_array($source)) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'アンケートが見つかりません。'
                ], 404);
            }

            $new =
                survey_normalize_survey(
                    $source
                );

            $new['id'] =
                survey_id('survey');

            $new['title'] =
                $source['title'] .
                '（コピー）';

            $new['status'] =
                'draft';

            $new['deleted'] =
                false;

            $new['created_at'] =
                survey_now();

            $new['updated_at'] =
                survey_now();

            survey_save_data($data);

            $data['surveys'][] =
                $new;

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'survey' => $new
            ]);

        case 'save_settings':

            $settings =
                json_decode(
                    (string)(
                        $_POST['settings_json']
                        ?? ''
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

            $settings['ssl_verify'] =
                !empty(
                    $settings['ssl_verify']
                );

            if (!is_array(
                $settings['field_address']
                ?? null
            )) {
                $settings['field_address'] =
                    [];
            }

            $data['settings'] =
                array_merge(
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
                'settings' =>
                    $data['settings']
            ]);

        case 'kintone_test':

            $settings =
                $data['settings'];

            if (
                isset($_POST['settings_json'])
            ) {
                $posted =
                    json_decode(
                        (string)(
                            $_POST['settings_json']
                        ),
                        true
                    );

                if (is_array($posted)) {
                    $settings =
                        array_merge(
                            $settings,
                            $posted
                        );
                }
            }

            $appId =
                trim(
                    (string)(
                        $_POST['app_id']
                        ?? $settings['app_id']
                        ?? ''
                    )
                );

            if (
                $appId === '' ||
                !ctype_digit($appId)
            ) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'アプリIDは数字で入力してください。',
                    'error_code' =>
                        'INVALID_APP_ID',
                    'status' => 400
                ], 400);
            }

            $settings['app_id'] =
                $appId;

            $result =
                survey_kintone_request(
                    'GET',
                    '/k/v1/app/form/fields.json?app=' .
                    rawurlencode($appId),
                    $settings
                );

            if (!$result['ok']) {

                survey_json_response([
                    'ok' => false,
                    'message' =>
                        $result['message']
                        ?? 'kintone API通信に失敗しました。',
                    'error_code' =>
                        $result['error_code']
                        ?? '',
                    'status' =>
                        $result['status']
                        ?? 0,
                    'endpoint' =>
                        $result['endpoint']
                        ?? '',
                    'diagnostic' =>
                        $result['diagnostic']
                        ?? [],
                    'data' =>
                        $result['data']
                        ?? []
                ], 400);
            }

            survey_json_response([
                'ok' => true,
                'message' =>
                    'kintoneへの接続に成功しました。',
                'status' =>
                    $result['status'],
                'endpoint' =>
                    $result['endpoint'],
                'fields' =>
                    $result['data']['properties']
                    ?? [],
                'diagnostic' =>
                    $result['diagnostic']
                    ?? []
            ]);

        case 'kintone_fields':

            $settings =
                $data['settings'];

            if (
                isset($_POST['settings_json'])
            ) {
                $posted =
                    json_decode(
                        (string)(
                            $_POST['settings_json']
                        ),
                        true
                    );

                if (is_array($posted)) {
                    $settings =
                        array_merge(
                            $settings,
                            $posted
                        );
                }
            }

            $appId =
                trim(
                    (string)(
                        $_POST['app_id']
                        ?? $settings['app_id']
                        ?? ''
                    )
                );

            if (
                $appId === '' ||
                !ctype_digit($appId)
            ) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'アプリIDは数字で入力してください。',
                    'error_code' =>
                        'INVALID_APP_ID',
                    'status' => 400
                ], 400);
            }

            $settings['app_id'] =
                $appId;

            $result =
                survey_kintone_request(
                    'GET',
                    '/k/v1/app/form/fields.json?app=' .
                    rawurlencode($appId),
                    $settings
                );

            if (!$result['ok']) {

                survey_json_response([
                    'ok' => false,
                    'message' =>
                        $result['message']
                        ?? 'kintone API通信に失敗しました。',
                    'error_code' =>
                        $result['error_code']
                        ?? '',
                    'status' =>
                        $result['status']
                        ?? 0,
                    'endpoint' =>
                        $result['endpoint']
                        ?? '',
                    'diagnostic' =>
                        $result['diagnostic']
                        ?? [],
                    'data' =>
                        $result['data']
                        ?? []
                ], 400);
            }

            $fields =
                $result['data']['properties']
                ?? [];

            survey_json_response([
                'ok' => true,
                'status' =>
                    $result['status'],
                'endpoint' =>
                    $result['endpoint'],
                'fields' =>
                    $fields
            ]);

        case 'mark_kintone_registered':

            $customerId =
                (string)(
                    $_POST['customer_id']
                    ?? ''
                );

            foreach (
                $data['customers']
                as &$customer
            ) {
                if (
                    (string)$customer['id']
                    === $customerId
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

        case 'send_mail':

            $ids =
                json_decode(
                    (string)(
                        $_POST['recipient_ids']
                        ?? '[]'
                    ),
                    true
                );

            if (!is_array($ids)) {
                $ids = [];
            }

            $subject =
                (string)(
                    $_POST['mail_subject']
                    ?? ''
                );

            $body =
                (string)(
                    $_POST['mail_body']
                    ?? ''
                );

            $templateType =
                (string)(
                    $_POST['template_type']
                    ?? 'initial'
                );

            $count = 0;

            foreach (
                $data['customers']
                as &$customer
            ) {
                if (
                    !in_array(
                        (string)$customer['id'],
                        array_map(
                            'strval',
                            $ids
                        ),
                        true
                    )
                ) {
                    continue;
                }

                $customer['sent_at'] =
                    survey_now();

                $customer['send_count'] =
                    ((int)(
                        $customer['send_count']
                        ?? 0
                    )) + 1;

                $customer['answer_status'] =
                    $customer['answer_status']
                    ?? 'unanswered';

                $count++;

                /*
                 * 実サーバでmail()が有効な場合のみ送信。
                 * SMTP未設定環境ではログ記録を優先する。
                 */
                $mailBody =
                    str_replace(
                        [
                            '{顧客名}',
                            '{アンケートURL}'
                        ],
                        [
                            (string)(
                                $customer['name']
                                ?? ''
                            ),
                            ''
                        ],
                        $body
                    );

                if (
                    filter_var(
                        $customer['email']
                        ?? '',
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    @mail(
                        (string)$customer['email'],
                        $subject,
                        $mailBody,
                        "Content-Type: text/plain; charset=UTF-8\r\n"
                    );
                }
            }

            unset($customer);

            $data['mail_logs'][] = [
                'id' =>
                    survey_id('mail'),
                'sent_at' =>
                    survey_now(),
                'template_type' =>
                    $templateType,
                'count' => $count,
                'subject' => $subject,
                'body' => $body,
                'executed_by' =>
                    'admin'
            ];

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'count' => $count
            ]);

        case 'csv':

            $surveyId =
                (string)(
                    $_GET['survey_id']
                    ?? ''
                );

            $rows = [
                [
                    '回答ID',
                    '回答日時',
                    '顧客ID',
                    '会社名',
                    '氏名'
                ]
            ];

            $survey = null;

            foreach (
                $data['surveys']
                as $s
            ) {
                if (
                    (string)$s['id']
                    === $surveyId
                ) {
                    $survey = $s;
                    break;
                }
            }

            if (is_array($survey)) {
                foreach (
                    $survey['groups']
                    as $group
                ) {
                    foreach (
                        $group['questions']
                        as $q
                    ) {
                        $rows[0][] =
                            (string)(
                                $q['text']
                                ?? ''
                            );
                    }
                }
            }

            foreach (
                $data['responses']
                as $response
            ) {
                if (
                    $surveyId !== '' &&
                    (string)(
                        $response['survey_id']
                        ?? ''
                    ) !== $surveyId
                ) {
                    continue;
                }

                $row = [
                    (string)(
                        $response['id']
                        ?? ''
                    ),
                    (string)(
                        $response['answered_at']
                        ?? ''
                    ),
                    (string)(
                        $response['customer_id']
                        ?? ''
                    ),
                    (string)(
                        $response['company']
                        ?? ''
                    ),
                    (string)(
                        $response['name']
                        ?? ''
                    )
                ];

                $answers =
                    $response['answers']
                    ?? [];

                if (!is_array($answers)) {
                    $answers = [];
                }

                if (is_array($survey)) {
                    foreach (
                        $survey['groups']
                        as $group
                    ) {
                        foreach (
                            $group['questions']
                            as $q
                        ) {
                            $value =
                                $answers[
                                    $q['id']
                                ]
                                ?? '';

                            if (is_array($value)) {
                                $value =
                                    implode(
                                        '、',
                                        array_map(
                                            'strval',
                                            $value
                                        )
                                    );
                            }

                            $row[] =
                                (string)$value;
                        }
                    }
                }

                $rows[] = $row;
            }

            survey_csv_response(
                'survey.csv',
                $rows
            );

        case 'public_answer':

            $surveyId =
                (string)(
                    $_POST['survey_id']
                    ?? ''
                );

            $survey = null;

            foreach (
                $data['surveys']
                as $s
            ) {
                if (
                    (string)$s['id']
                    === $surveyId
                ) {
                    $survey = $s;
                    break;
                }
            }

            if (!is_array($survey)) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'アンケートが見つかりません。'
                ], 404);
            }

            $response = [
                'id' =>
                    survey_id('response'),
                'survey_id' =>
                    $surveyId,
                'customer_id' =>
                    (string)(
                        $_POST['customer_id']
                        ?? ''
                    ),
                'company' =>
                    (string)(
                        $_POST['company']
                        ?? ''
                    ),
                'name' =>
                    (string)(
                        $_POST['name']
                        ?? ''
                    ),
                'email' =>
                    (string)(
                        $_POST['email']
                        ?? ''
                    ),
                'answered_at' =>
                    survey_now(),
                'answers' =>
                    json_decode(
                        (string)(
                            $_POST['answers']
                            ?? '{}'
                        ),
                        true
                    )
            ];

            if (!is_array(
                $response['answers']
            )) {
                $response['answers'] =
                    [];
            }

            $data['responses'][] =
                $response;

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'response_id' =>
                    $response['id']
            ]);
    }
}

/* ================================================================
 * public answer page
 * ================================================================ */

if (
    isset($_GET['public']) &&
    $_GET['public'] === '1'
) {
    $surveyId =
        (string)(
            $_GET['survey_id']
            ?? ''
        );

    $publicSurvey = null;

    foreach (
        $data['surveys']
        as $survey
    ) {
        if (
            (string)$survey['id']
            === $surveyId &&
            empty($survey['deleted'])
        ) {
            $publicSurvey =
                $survey;
            break;
        }
    }

    if (!is_array($publicSurvey)) {
        http_response_code(404);
        ?>
        <!doctype html>
        <html lang="ja">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <script src="https://cdn.tailwindcss.com"></script>
        </head>
        <body class="bg-slate-50 min-h-screen flex items-center justify-center p-6">
            <div class="bg-white rounded-2xl shadow p-8 max-w-xl w-full text-center">
                <h1 class="text-xl font-bold text-slate-800">
                    アンケートが見つかりません
                </h1>
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
        <title><?= htmlspecialchars(
            $publicSurvey['title'],
            ENT_QUOTES,
            'UTF-8'
        ) ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-slate-50 min-h-screen">
    <div class="max-w-3xl mx-auto p-6">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <h1 class="text-2xl font-bold text-slate-800 mb-8">
                <?= htmlspecialchars(
                    $publicSurvey['title'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </h1>

            <form
                method="post"
                action="?action=public_answer"
                class="space-y-8"
            >
                <input
                    type="hidden"
                    name="survey_id"
                    value="<?= htmlspecialchars(
                        $surveyId,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <?php foreach (
                    $publicSurvey['groups']
                    as $groupIndex => $group
                ): ?>

                    <section class="border border-slate-200 rounded-xl p-5">
                        <h2 class="font-bold text-lg mb-5">
                            <?= htmlspecialchars(
                                $group['name'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </h2>

                        <div class="space-y-7">
                        <?php foreach (
                            $group['questions']
                            as $qIndex => $q
                        ): ?>

                            <div>
                                <label class="block font-semibold text-slate-700 mb-3">
                                    <?= htmlspecialchars(
                                        $q['text'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                    <?php if (
                                        !empty($q['required'])
                                    ): ?>
                                        <span class="text-red-500">*</span>
                                    <?php endif; ?>
                                </label>

                                <?php if (
                                    $q['type'] === 'single'
                                ): ?>

                                    <div class="space-y-2">
                                    <?php foreach (
                                        $q['options']
                                        as $option
                                    ): ?>
                                        <label class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50">
                                            <input
                                                type="radio"
                                                class="w-4 h-4"
                                                name="answers[<?= htmlspecialchars(
                                                    $q['id'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>]"
                                                value="<?= htmlspecialchars(
                                                    $option,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                                <?= !empty($q['required'])
                                                    ? 'required'
                                                    : '' ?>
                                            >
                                            <span>
                                                <?= htmlspecialchars(
                                                    $option,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                    </div>

                                <?php elseif (
                                    $q['type'] === 'multiple'
                                ): ?>

                                    <div class="space-y-2">
                                    <?php foreach (
                                        $q['options']
                                        as $option
                                    ): ?>
                                        <label class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50">
                                            <input
                                                type="checkbox"
                                                class="w-4 h-4"
                                                name="answers[<?= htmlspecialchars(
                                                    $q['id'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>][]"
                                                value="<?= htmlspecialchars(
                                                    $option,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                            >
                                            <span>
                                                <?= htmlspecialchars(
                                                    $option,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                    </div>

                                <?php else: ?>

                                    <textarea
                                        class="w-full min-h-32 border border-slate-300 rounded-xl p-3"
                                        name="answers[<?= htmlspecialchars(
                                            $q['id'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>]"
                                        <?= !empty($q['required'])
                                            ? 'required'
                                            : '' ?>
                                    ></textarea>

                                <?php endif; ?>
                            </div>

                        <?php endforeach; ?>
                        </div>
                    </section>

                <?php endforeach; ?>

                <div class="flex justify-end">
                    <button
                        class="px-6 py-3 rounded-xl bg-blue-600 text-white font-semibold hover:bg-blue-700"
                    >
                        回答を送信
                    </button>
                </div>
            </form>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

/* ================================================================
 * SPA
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

<script>
window.App = {
    state: {
        data: null,
        csrf: <?= json_encode(
            $csrf,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ) ?>,
        page: 'list',
        currentSurveyId: null,
        editorSurvey: null,
        filter: '',
        statusFilter: '',
        sort: 'updated_desc',
        responseSurveyId: null,
        selectedQuestions: {},
        kintoneFields: {},
        lastKintoneDiagnostic: null
    },

    api: {},

    render: {},

    actions: {},

    util: {},

    init: async function() {
        if (this._initialized) {
            return;
        }

        this._initialized = true;

        await this.api.load();

        this.render.layout();
        this.render.list();
    }
};
</script>
</head>

<body class="bg-slate-50 text-slate-800 min-h-screen">

<div id="app"></div>

<script>
window.App.util.escape = function(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
};

window.App.util.uuid = function(prefix) {
    return prefix + '_' +
        Date.now().toString(36) + '_' +
        Math.random().toString(36).slice(2, 10);
};

window.App.util.toast = function(message, type = 'success') {
    const colors = {
        success: 'bg-emerald-600',
        error: 'bg-red-600',
        info: 'bg-slate-800'
    };

    const el = document.createElement('div');

    el.className =
        'fixed right-5 bottom-5 z-[100] ' +
        'text-white px-5 py-3 rounded-xl shadow-lg ' +
        (colors[type] || colors.info);

    el.textContent = message;

    document.body.appendChild(el);

    setTimeout(() => {
        el.remove();
    }, 3500);
};

window.App.util.statusLabel = function(status) {
    const map = {
        draft: ['下書き', 'bg-slate-100 text-slate-600'],
        active: ['公開中', 'bg-emerald-100 text-emerald-700'],
        ended: ['終了', 'bg-slate-200 text-slate-700']
    };

    return map[status] || map.draft;
};

window.App.util.typeLabel = function(type) {
    return {
        single: '単一選択',
        multiple: '複数選択',
        text: '自由記述'
    }[type] || type;
};

window.App.util.formatDate = function(value) {
    if (!value) {
        return '未設定';
    }

    const d = new Date(
        String(value).replace(' ', 'T')
    );

    if (Number.isNaN(d.getTime())) {
        return value;
    }

    return d.getFullYear() + '/' +
        String(d.getMonth() + 1).padStart(2, '0') +
        '/' +
        String(d.getDate()).padStart(2, '0');
};

window.App.api.request = async function(
    action,
    data = {},
    method = 'POST'
) {
    let url = location.pathname +
        '?action=' +
        encodeURIComponent(action);

    const options = {
        method,
        headers: {}
    };

    if (method === 'GET') {
        const params = new URLSearchParams(data);
        url += '&' + params.toString();
    } else {
        const fd = new FormData();

        fd.append(
            'csrf_token',
            thisApp().state.csrf
        );

        Object.keys(data).forEach(key => {
            const value = data[key];

            if (
                typeof value === 'object' &&
                value !== null
            ) {
                fd.append(
                    key,
                    JSON.stringify(value)
                );
            } else {
                fd.append(
                    key,
                    value == null ? '' : value
                );
            }
        });

        options.body = fd;
    }

    const response =
        await fetch(url, options);

    const text =
        await response.text();

    let json;

    try {
        json = JSON.parse(text);
    } catch (e) {
        throw new Error(
            'サーバーからJSONではない応答が返されました。\n' +
            text.slice(0, 1000)
        );
    }

    if (!response.ok && !json) {
        throw new Error(
            'HTTP ' + response.status
        );
    }

    return json;
};

function thisApp() {
    return window.App;
}

window.App.api.load = async function() {
    const result =
        await this.request(
            'load',
            {},
            'GET'
        );

    if (!result.ok) {
        throw new Error(
            result.message || '読み込みに失敗しました。'
        );
    }

    thisApp().state.data =
        result.data;

    thisApp().state.csrf =
        result.csrf_token;
};

window.App.api.saveSurvey = async function(
    survey
) {
    return await this.request(
        'save_survey',
        {
            survey_json: JSON.stringify(survey)
        }
    );
};

window.App.api.saveSettings = async function(
    settings
) {
    return await this.request(
        'save_settings',
        {
            settings_json:
                JSON.stringify(settings)
        }
    );
};

window.App.api.kintoneFields = async function(
    settings,
    appId
) {
    return await this.request(
        'kintone_fields',
        {
            app_id: appId,
            settings_json:
                JSON.stringify(settings)
        }
    );
};

window.App.api.kintoneTest = async function(
    settings,
    appId
) {
    return await this.request(
        'kintone_test',
        {
            app_id: appId,
            settings_json:
                JSON.stringify(settings)
        }
    );
};

window.App.render.layout = function() {
    document.getElementById('app').innerHTML = `
        <div class="min-h-screen">

            <header class="sticky top-0 z-40 bg-white border-b border-slate-200">
                <div class="max-w-[1600px] mx-auto px-5">
                    <div class="h-16 flex items-center justify-between">

                        <button
                            onclick="App.actions.goList()"
                            class="font-bold text-lg text-slate-800"
                        >
                            アンケート管理
                        </button>

                        <nav class="flex items-center gap-2">
                            <button
                                onclick="App.actions.goList()"
                                class="px-4 py-2 rounded-lg hover:bg-slate-100"
                            >
                                アンケート一覧
                            </button>

                            <button
                                onclick="App.actions.goSettings()"
                                class="px-4 py-2 rounded-lg hover:bg-slate-100"
                            >
                                kintone連携設定
                            </button>

                            <button
                                onclick="App.actions.logout()"
                                class="px-4 py-2 rounded-lg hover:bg-slate-100"
                            >
                                ログアウト
                            </button>
                        </nav>
                    </div>
                </div>
            </header>

            <main
                id="main"
                class="max-w-[1600px] mx-auto p-5"
            ></main>

        </div>
    `;
};

window.App.render.list = function() {
    thisApp().state.page = 'list';

    const data =
        thisApp().state.data;

    let surveys =
        (data.surveys || [])
        .filter(s => !s.deleted);

    const keyword =
        thisApp().state.filter
        .toLowerCase();

    const status =
        thisApp().state.statusFilter;

    if (keyword) {
        surveys = surveys.filter(s =>
            String(s.title)
                .toLowerCase()
                .includes(keyword)
        );
    }

    if (status) {
        surveys = surveys.filter(
            s => s.status === status
        );
    }

    surveys.sort((a, b) => {
        const key =
            thisApp().state.sort;

        if (key === 'answers_desc') {
            return thisApp().countAnswers(b.id) -
                thisApp().countAnswers(a.id);
        }

        if (key === 'answers_asc') {
            return thisApp().countAnswers(a.id) -
                thisApp().countAnswers(b.id);
        }

        if (key === 'start_desc') {
            return String(b.start_at)
                .localeCompare(
                    String(a.start_at)
                );
        }

        if (key === 'start_asc') {
            return String(a.start_at)
                .localeCompare(
                    String(b.start_at)
                );
        }

        const av =
            String(a.updated_at || '');

        const bv =
            String(b.updated_at || '');

        return key === 'updated_asc'
            ? av.localeCompare(bv)
            : bv.localeCompare(av);
    });

    document.getElementById('main').innerHTML = `
        <div class="space-y-5">

            <div class="flex items-center justify-between">
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
                    class="px-5 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-semibold shadow-sm"
                >
                    ＋ 新規アンケート作成
                </button>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <div class="grid md:grid-cols-3 gap-3">

                    <input
                        value="${this.util.escape(
                            thisApp().state.filter
                        )}"
                        onkeydown="if(event.key==='Enter')App.actions.search(this.value)"
                        oninput="App.actions.search(this.value)"
                        placeholder="タイトルを検索"
                        class="border border-slate-300 rounded-xl px-4 py-3"
                    >

                    <select
                        onchange="App.actions.statusFilter(this.value)"
                        class="border border-slate-300 rounded-xl px-4 py-3"
                    >
                        <option value="">すべて</option>
                        <option value="active" ${
                            status === 'active'
                                ? 'selected'
                                : ''
                        }>公開中</option>
                        <option value="draft" ${
                            status === 'draft'
                                ? 'selected'
                                : ''
                        }>下書き</option>
                        <option value="ended" ${
                            status === 'ended'
                                ? 'selected'
                                : ''
                        }>終了</option>
                    </select>

                    <select
                        onchange="App.actions.sort(this.value)"
                        class="border border-slate-300 rounded-xl px-4 py-3"
                    >
                        <option value="updated_desc">更新日：新しい順</option>
                        <option value="updated_asc">更新日：古い順</option>
                        <option value="answers_desc">回答数：多い順</option>
                        <option value="answers_asc">回答数：少ない順</option>
                        <option value="start_desc">開始日：新しい順</option>
                        <option value="start_asc">開始日：古い順</option>
                    </select>

                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 border-b">
                            <tr>
                                <th class="text-left px-5 py-4">作成日 / 更新日</th>
                                <th class="text-left px-5 py-4">タイトル</th>
                                <th class="text-left px-5 py-4">アンケート期間</th>
                                <th class="text-left px-5 py-4">ステータス</th>
                                <th class="text-right px-5 py-4">回答数</th>
                                <th class="text-right px-5 py-4">操作</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y">
                            ${
                                surveys.length
                                ? surveys.map(
                                    s => this.surveyRow(s)
                                ).join('')
                                : `
                                <tr>
                                    <td colspan="6" class="py-16 text-center text-slate-400">
                                        アンケートがありません。
                                    </td>
                                </tr>
                                `
                            }
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    `;
};

window.App.surveyRow = function(s) {
    const status =
        this.util.statusLabel(s.status);

    const count =
        thisApp().countAnswers(s.id);

    let buttons = `
        <button
            onclick="App.actions.editSurvey('${this.util.escape(s.id)}')"
            class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200"
        >
            確認・編集
        </button>
    `;

    if (s.status === 'active') {
        buttons += `
            <button
                onclick="App.actions.analytics('${this.util.escape(s.id)}')"
                class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100"
            >
                集計
            </button>

            <button
                onclick="App.actions.mail('${this.util.escape(s.id)}')"
                class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100"
            >
                送信
            </button>

            <button
                onclick="App.actions.stop('${this.util.escape(s.id)}')"
                class="px-3 py-1.5 rounded-lg bg-red-50 text-red-700 hover:bg-red-100"
            >
                停止
            </button>
        `;
    }

    if (s.status === 'draft') {
        buttons += `
            <button
                onclick="App.actions.deleteSurvey('${this.util.escape(s.id)}')"
                class="px-3 py-1.5 rounded-lg bg-red-50 text-red-700 hover:bg-red-100"
            >
                削除
            </button>
        `;
    }

    if (s.status === 'ended') {
        buttons += `
            <button
                onclick="App.actions.analytics('${this.util.escape(s.id)}')"
                class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700"
            >
                集計
            </button>
        `;
    }

    buttons += `
        <button
            onclick="App.actions.duplicate('${this.util.escape(s.id)}')"
            class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200"
        >
            複製
        </button>
    `;

    return `
        <tr class="hover:bg-slate-50">
            <td class="px-5 py-4 whitespace-nowrap">
                <div>${this.util.escape(
                    this.util.formatDate(
                        s.created_at
                    )
                )}</div>
                <div class="text-xs text-slate-400 mt-1">
                    更新:
                    ${this.util.escape(
                        this.util.formatDate(
                            s.updated_at
                        )
                    )}
                </div>
            </td>

            <td class="px-5 py-4">
                <div class="font-bold">
                    ${this.util.escape(s.title)}
                </div>
            </td>

            <td class="px-5 py-4 whitespace-nowrap">
                ${
                    s.start_at
                    ? this.util.escape(
                        s.start_at
                    )
                    : '未設定'
                }
                ～
                ${
                    s.end_at
                    ? this.util.escape(
                        s.end_at
                    )
                    : '未設定'
                }
            </td>

            <td class="px-5 py-4">
                <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold ${status[1]}">
                    ${status[0]}
                </span>
            </td>

            <td class="px-5 py-4 text-right font-semibold">
                ${count} 件
            </td>

            <td class="px-5 py-4">
                <div class="flex flex-wrap gap-2 justify-end">
                    ${buttons}
                </div>
            </td>
        </tr>
    `;
};

window.App.countAnswers = function(surveyId) {
    return (
        thisApp().state.data.responses || []
    ).filter(
        r => String(r.survey_id) ===
            String(surveyId)
    ).length;
};

window.App.actions.search = function(value) {
    thisApp().state.filter = value;
    thisApp().render.list();
};

window.App.actions.statusFilter = function(value) {
    thisApp().state.statusFilter = value;
    thisApp().render.list();
};

window.App.actions.sort = function(value) {
    thisApp().state.sort = value;
    thisApp().render.list();
};

window.App.actions.goList = function() {
    thisApp().render.list();
};

window.App.actions.goSettings = function() {
    thisApp().state.page = 'settings';
    thisApp().render.settings();
};

window.App.actions.newSurvey = function() {
    const survey = {
        id: thisApp().util.uuid('survey'),
        title: '新しいアンケート',
        start_at: '',
        end_at: '',
        status: 'draft',
        created_at: '',
        updated_at: '',
        numbering_mode: 'global',
        deleted: false,
        groups: [{
            id: thisApp().util.uuid('group'),
            name: 'グループ1',
            questions: []
        }]
    };

    thisApp().state.editorSurvey =
        survey;

    thisApp().render.editor();
};

window.App.actions.editSurvey = function(id) {
    const survey =
        thisApp().state.data.surveys
        .find(
            s => String(s.id) === String(id)
        );

    if (!survey) {
        return;
    }

    thisApp().state.editorSurvey =
        JSON.parse(
            JSON.stringify(survey)
        );

    thisApp().render.editor();
};

window.App.actions.deleteSurvey = async function(id) {
    if (!confirm(
        'このアンケートを削除しますか？'
    )) {
        return;
    }

    const result =
        await thisApp().api.request(
            'delete_survey',
            {survey_id: id}
        );

    if (!result.ok) {
        thisApp().util.toast(
            result.message ||
            '削除に失敗しました。',
            'error'
        );
        return;
    }

    await thisApp().api.load();
    thisApp().util.toast(
        '削除しました。'
    );
    thisApp().render.list();
};

window.App.actions.stop = async function(id) {
    if (!confirm(
        '公開を停止しますか？'
    )) {
        return;
    }

    const result =
        await thisApp().api.request(
            'set_status',
            {
                survey_id: id,
                status: 'ended'
            }
        );

    if (!result.ok) {
        thisApp().util.toast(
            result.message ||
            '停止に失敗しました。',
            'error'
        );
        return;
    }

    await thisApp().api.load();
    thisApp().render.list();
};

window.App.actions.duplicate = async function(id) {
    if (!confirm(
        'アンケートを複製しますか？'
    )) {
        return;
    }

    const result =
        await thisApp().api.request(
            'duplicate_survey',
            {survey_id: id}
        );

    if (!result.ok) {
        thisApp().util.toast(
            result.message ||
            '複製に失敗しました。',
            'error'
        );
        return;
    }

    await thisApp().api.load();
    thisApp().util.toast(
        '下書きとして複製しました。'
    );
    thisApp().render.list();
};

window.App.actions.saveEditor = async function() {
    thisApp().editorCollect();

    const result =
        await thisApp().api.saveSurvey(
            thisApp().state.editorSurvey
        );

    if (!result.ok) {
        thisApp().util.toast(
            result.message ||
            '保存に失敗しました。',
            'error'
        );
        return;
    }

    await thisApp().api.load();

    thisApp().util.toast(
        '保存しました。'
    );

    thisApp().render.list();
};

window.App.actions.cancelEditor = function() {
    if (
        !confirm(
            '変更を破棄して一覧へ戻りますか？'
        )
    ) {
        return;
    }

    thisApp().render.list();
};

window.App.editorCollect = function() {
    const survey =
        thisApp().state.editorSurvey;

    const title =
        document.getElementById(
            'survey_title'
        );

    const start =
        document.getElementById(
            'survey_start_at'
        );

    const end =
        document.getElementById(
            'survey_end_at'
        );

    const mode =
        document.getElementById(
            'survey_numbering_mode'
        );

    if (title) {
        survey.title = title.value;
    }

    if (start) {
        survey.start_at = start.value;
    }

    if (end) {
        survey.end_at = end.value;
    }

    if (mode) {
        survey.numbering_mode =
            mode.value;
    }
};

window.App.render.editor = function() {
    const survey =
        thisApp().state.editorSurvey;

    document.getElementById('main').innerHTML = `
        <div class="space-y-5">

            <div class="flex flex-wrap gap-3 items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">
                        アンケート作成・編集
                    </h1>
                </div>

                <div class="flex gap-2">
                    <button
                        onclick="App.actions.preview()"
                        class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200"
                    >
                        プレビュー
                    </button>

                    <button
                        onclick="App.actions.cancelEditor()"
                        class="px-4 py-2 rounded-xl border border-slate-300 bg-white"
                    >
                        キャンセル
                    </button>

                    <button
                        onclick="App.actions.saveEditor()"
                        class="px-5 py-2 rounded-xl bg-blue-600 text-white font-semibold"
                    >
                        保存して一覧へ戻る
                    </button>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">

                <div>
                    <label class="block text-sm font-semibold mb-2">
                        タイトル
                    </label>

                    <input
                        id="survey_title"
                        value="${this.util.escape(
                            survey.title
                        )}"
                        class="w-full text-xl font-bold border border-slate-300 rounded-xl px-4 py-3"
                    >
                </div>

                <div class="grid md:grid-cols-3 gap-3">

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            開始日時
                        </label>
                        <input
                            id="survey_start_at"
                            type="datetime-local"
                            value="${this.util.escape(
                                survey.start_at
                            )}"
                            class="w-full border border-slate-300 rounded-xl px-3 py-2"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            終了日時
                        </label>
                        <input
                            id="survey_end_at"
                            type="datetime-local"
                            value="${this.util.escape(
                                survey.end_at
                            )}"
                            class="w-full border border-slate-300 rounded-xl px-3 py-2"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            質問番号
                        </label>
                        <select
                            id="survey_numbering_mode"
                            class="w-full border border-slate-300 rounded-xl px-3 py-2"
                        >
                            <option value="global" ${
                                survey.numbering_mode === 'global'
                                    ? 'selected'
                                    : ''
                            }>
                                Q1, Q2, Q3...
                            </option>
                            <option value="group" ${
                                survey.numbering_mode === 'group'
                                    ? 'selected'
                                    : ''
                            }>
                                Q1-1, Q1-2...
                            </option>
                        </select>
                    </div>

                </div>
            </div>

            <div
                id="question_editor"
                class="space-y-4"
            >
                ${this.editorGroups()}
            </div>

            <div class="flex justify-center">
                <button
                    onclick="App.actions.addGroup()"
                    class="px-5 py-3 rounded-xl bg-blue-600 text-white font-semibold"
                >
                    ＋ グループ追加
                </button>
            </div>

        </div>
    `;

    this.initSortables();
};

window.App.editorGroups = function() {
    const survey =
        thisApp().state.editorSurvey;

    return `
        <div
            id="editor_group_list"
            class="space-y-4"
        >
        ${
            survey.groups.map(
                (group, groupIndex) =>
                    this.editorGroup(
                        group,
                        groupIndex
                    )
            ).join('')
        }
        </div>
    `;
};

window.App.editorGroup = function(
    group,
    groupIndex
) {
    return `
        <section
            class="editor-group bg-white border border-slate-200 rounded-2xl overflow-hidden"
            data-group-id="${this.util.escape(group.id)}"
        >

            <div class="bg-slate-50 border-b px-5 py-4 flex items-center gap-3">

                <span
                    class="group-handle cursor-grab text-xl text-slate-400"
                >
                    ⠿
                </span>

                <input
                    value="${this.util.escape(group.name)}"
                    onchange="App.actions.groupName('${this.util.escape(group.id)}', this.value)"
                    class="flex-1 bg-transparent font-bold text-lg outline-none"
                >

                <button
                    onclick="App.actions.addQuestion('${this.util.escape(group.id)}')"
                    class="px-3 py-2 rounded-lg bg-blue-600 text-white"
                >
                    ＋ 質問
                </button>

                <button
                    onclick="App.actions.deleteGroup('${this.util.escape(group.id)}')"
                    class="px-3 py-2 rounded-lg bg-red-50 text-red-700"
                >
                    削除
                </button>

            </div>

            <div
                class="question-list p-5 space-y-3"
                data-group-id="${this.util.escape(group.id)}"
            >
                ${
                    group.questions.length
                    ? group.questions.map(
                        (q, i) =>
                            this.editorQuestion(
                                q,
                                groupIndex,
                                i
                            )
                    ).join('')
                    : `
                    <div class="border-2 border-dashed border-slate-200 rounded-xl p-8 text-center text-slate-400">
                        質問がありません。
                        「＋ 質問」で追加してください。
                    </div>
                    `
                }
            </div>

        </section>
    `;
};

window.App.editorQuestion = function(
    q,
    groupIndex,
    questionIndex
) {
    const survey =
        thisApp().state.editorSurvey;

    let number = '';

    if (
        survey.numbering_mode ===
        'group'
    ) {
        number =
            'Q' +
            (groupIndex + 1) +
            '-' +
            (questionIndex + 1);
    } else {
        let global = 0;

        for (
            let gi = 0;
            gi <= groupIndex;
            gi++
        ) {
            if (
                gi === groupIndex
            ) {
                global +=
                    questionIndex + 1;
            } else {
                global +=
                    survey.groups[gi]
                        .questions.length;
            }
        }

        number = 'Q' + global;
    }

    return `
        <article
            class="question-item border border-slate-200 rounded-xl p-4 bg-white shadow-sm"
            data-question-id="${this.util.escape(q.id)}"
        >

            <div class="flex gap-3">

                <span class="question-handle cursor-grab text-xl text-slate-400">
                    ⠿
                </span>

                <div class="flex-1 space-y-4">

                    <div class="flex items-center justify-between gap-3">

                        <span class="font-bold text-blue-600">
                            ${number}
                        </span>

                        <button
                            onclick="App.actions.deleteQuestion('${this.util.escape(q.id)}')"
                            class="text-red-600 text-sm"
                        >
                            削除
                        </button>

                    </div>

                    <input
                        value="${this.util.escape(q.text)}"
                        onchange="App.actions.questionText('${this.util.escape(q.id)}', this.value)"
                        placeholder="質問文を入力"
                        class="w-full border border-slate-300 rounded-xl px-4 py-3"
                    >

                    <div class="grid md:grid-cols-2 gap-3">

                        <select
                            onchange="App.actions.questionType('${this.util.escape(q.id)}', this.value)"
                            class="border border-slate-300 rounded-xl px-3 py-2"
                        >
                            <option value="single" ${
                                q.type === 'single'
                                    ? 'selected'
                                    : ''
                            }>単一選択</option>

                            <option value="multiple" ${
                                q.type === 'multiple'
                                    ? 'selected'
                                    : ''
                            }>複数選択</option>

                            <option value="text" ${
                                q.type === 'text'
                                    ? 'selected'
                                    : ''
                            }>自由記述</option>
                        </select>

                        <label class="flex items-center gap-2 border border-slate-200 rounded-xl px-3">
                            <input
                                type="checkbox"
                                onchange="App.actions.questionRequired('${this.util.escape(q.id)}', this.checked)"
                                ${q.required ? 'checked' : ''}
                            >
                            必須回答
                        </label>

                    </div>

                    ${
                        q.type !== 'text'
                        ? this.optionsEditor(q)
                        : ''
                    }

                </div>
            </div>
        </article>
    `;
};

window.App.optionsEditor = function(q) {
    return `
        <div class="border-t pt-4 space-y-3">

            <div class="flex justify-between items-center">
                <span class="font-semibold">
                    選択肢
                </span>

                <button
                    onclick="App.actions.addOption('${this.util.escape(q.id)}')"
                    class="text-blue-600 text-sm"
                >
                    ＋ 選択肢追加
                </button>
            </div>

            ${
                q.options.map(
                    (option, i) => `
                    <div class="flex gap-2">
                        <input
                            value="${this.util.escape(option)}"
                            onchange="App.actions.optionText('${this.util.escape(q.id)}', ${i}, this.value)"
                            class="flex-1 border border-slate-300 rounded-lg px-3 py-2"
                        >

                        <button
                            onclick="App.actions.deleteOption('${this.util.escape(q.id)}', ${i})"
                            class="px-3 rounded-lg bg-red-50 text-red-700"
                        >
                            ×
                        </button>
                    </div>
                    `
                ).join('')
            }

            ${
                q.type === 'single'
                ? `
                <label class="flex items-center gap-2">
                    <input
                        type="checkbox"
                        onchange="App.actions.other('${this.util.escape(q.id)}', this.checked)"
                        ${q.other_enabled ? 'checked' : ''}
                    >
                    その他（自由記述）を有効にする
                </label>

                <div class="pt-2">
                    ${this.branchingEditor(q)}
                </div>
                `
                : ''
            }

        </div>
    `;
};

window.App.branchingEditor = function(q) {
    if (
        q.type !== 'single' ||
        !q.options.length
    ) {
        return '';
    }

    return `
        <div class="border border-slate-200 rounded-xl p-3 bg-slate-50">
            <div class="font-semibold text-sm mb-3">
                回答による質問分岐
            </div>

            <div class="space-y-2">
            ${
                q.options.map(
                    option => {
                        const item =
                            (q.branching || [])
                            .find(
                                x =>
                                    x.option === option
                            ) || {};

                        const targets =
                            thisApp().questionList()
                            .filter(
                                x =>
                                    x.id !== q.id
                            );

                        return `
                        <div class="grid md:grid-cols-2 gap-2 items-center">

                            <span class="text-sm">
                                「${this.util.escape(option)}」
                            </span>

                            <select
                                onchange="App.actions.branch('${this.util.escape(q.id)}','${this.util.escape(option)}',this.value)"
                                class="border border-slate-300 rounded-lg px-2 py-2 bg-white"
                            >
                                <option value="">
                                    分岐なし
                                </option>

                                ${
                                    targets.map(
                                        t => `
                                        <option
                                            value="${this.util.escape(t.id)}"
                                            ${
                                                item.target_question_id === t.id
                                                    ? 'selected'
                                                    : ''
                                            }
                                        >
                                            ${this.util.escape(t.text || '無題の質問')}
                                        </option>
                                        `
                                    ).join('')
                                }
                            </select>

                        </div>
                        `;
                    }
                ).join('')
            }
            </div>
        </div>
    `;
};

window.App.questionList = function() {
    const result = [];

    const survey =
        thisApp().state.editorSurvey;

    survey.groups.forEach(
        group => {
            group.questions.forEach(
                q => result.push(q)
            );
        }
    );

    return result;
};

window.App.actions.addGroup = function() {
    const survey =
        thisApp().state.editorSurvey;

    survey.groups.push({
        id: thisApp().util.uuid('group'),
        name:
            'グループ' +
            (survey.groups.length + 1),
        questions: []
    });

    thisApp().render.editor();
};

window.App.actions.deleteGroup = function(id) {
    const survey =
        thisApp().state.editorSurvey;

    if (
        survey.groups.length <= 1
    ) {
        thisApp().util.toast(
            '最低1グループ必要です。',
            'error'
        );
        return;
    }

    if (!confirm(
        'グループと内包する質問を削除しますか？'
    )) {
        return;
    }

    survey.groups =
        survey.groups.filter(
            g => g.id !== id
        );

    thisApp().render.editor();
};

window.App.actions.groupName = function(
    id,
    value
) {
    const group =
        thisApp().state.editorSurvey.groups
        .find(
            g => g.id === id
        );

    if (group) {
        group.name = value;
    }
};

window.App.actions.addQuestion = function(
    groupId
) {
    const group =
        thisApp().state.editorSurvey.groups
        .find(
            g => g.id === groupId
        );

    if (!group) {
        return;
    }

    group.questions.push({
        id:
            thisApp().util.uuid('question'),
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

    thisApp().render.editor();
};

window.App.actions.deleteQuestion = function(
    id
) {
    if (!confirm(
        'この質問を削除しますか？'
    )) {
        return;
    }

    const survey =
        thisApp().state.editorSurvey;

    survey.groups.forEach(
        group => {
            group.questions =
                group.questions.filter(
                    q => q.id !== id
                );
        }
    );

    thisApp().render.editor();
};

window.App.actions.questionText = function(
    id,
    value
) {
    const q =
        thisApp().questionList()
        .find(
            q => q.id === id
        );

    if (q) {
        q.text = value;
    }
};

window.App.actions.questionType = function(
    id,
    value
) {
    const q =
        thisApp().questionList()
        .find(
            q => q.id === id
        );

    if (!q) {
        return;
    }

    q.type = value;

    if (value === 'text') {
        q.options = [];
        q.branching = [];
    }

    thisApp().render.editor();
};

window.App.actions.questionRequired = function(
    id,
    checked
) {
    const q =
        thisApp().questionList()
        .find(
            q => q.id === id
        );

    if (q) {
        q.required = checked;
    }
};

window.App.actions.addOption = function(id) {
    const q =
        thisApp().questionList()
        .find(
            q => q.id === id
        );

    if (!q) {
        return;
    }

    q.options.push(
        '選択肢' +
        (q.options.length + 1)
    );

    thisApp().render.editor();
};

window.App.actions.deleteOption = function(
    id,
    index
) {
    const q =
        thisApp().questionList()
        .find(
            q => q.id === id
        );

    if (!q) {
        return;
    }

    q.options.splice(index, 1);

    q.branching =
        (q.branching || [])
        .filter(
            b =>
                q.options.includes(
                    b.option
                )
        );

    thisApp().render.editor();
};

window.App.actions.optionText = function(
    id,
    index,
    value
) {
    const q =
        thisApp().questionList()
        .find(
            q => q.id === id
        );

    if (!q) {
        return;
    }

    const old =
        q.options[index];

    q.options[index] = value;

    (q.branching || []).forEach(
        b => {
            if (b.option === old) {
                b.option = value;
            }
        }
    );
};

window.App.actions.other = function(
    id,
    checked
) {
    const q =
        thisApp().questionList()
        .find(
            q => q.id === id
        );

    if (q) {
        q.other_enabled =
            checked;
    }
};

window.App.actions.branch = function(
    id,
    option,
    target
) {
    const q =
        thisApp().questionList()
        .find(
            q => q.id === id
        );

    if (!q) {
        return;
    }

    if (!Array.isArray(q.branching)) {
        q.branching = [];
    }

    let item =
        q.branching.find(
            x => x.option === option
        );

    if (!item) {
        item = {
            option: option,
            target_question_id: ''
        };

        q.branching.push(item);
    }

    item.target_question_id =
        target;
};

window.App.initSortables = function() {
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
                onEnd: evt => {
                    const survey =
                        thisApp().state.editorSurvey;

                    const moved =
                        survey.groups.splice(
                            evt.oldIndex,
                            1
                        )[0];

                    survey.groups.splice(
                        evt.newIndex,
                        0,
                        moved
                    );

                    thisApp().render.editor();
                }
            }
        );
    }

    document.querySelectorAll(
        '.question-list'
    ).forEach(list => {

        new Sortable(
            list,
            {
                group: 'survey-questions',
                animation: 180,
                handle: '.question-handle',
                ghostClass:
                    'opacity-40',
                onEnd: evt => {

                    const survey =
                        thisApp().state.editorSurvey;

                    const fromId =
                        evt.from.dataset.groupId;

                    const toId =
                        evt.to.dataset.groupId;

                    const from =
                        survey.groups.find(
                            g =>
                                g.id ===
                                fromId
                        );

                    const to =
                        survey.groups.find(
                            g =>
                                g.id ===
                                toId
                        );

                    if (!from || !to) {
                        return;
                    }

                    const q =
                        from.questions.splice(
                            evt.oldIndex,
                            1
                        )[0];

                    if (!q) {
                        return;
                    }

                    to.questions.splice(
                        evt.newIndex,
                        0,
                        q
                    );

                    thisApp().render.editor();
                }
            }
        );
    });
};

window.App.actions.preview = function() {
    const survey =
        thisApp().state.editorSurvey;

    const modal =
        document.createElement('div');

    modal.id =
        'preview_modal';

    modal.className =
        'fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-5';

    modal.innerHTML = `
        <div class="bg-white rounded-2xl shadow-xl max-w-3xl w-full max-h-[90vh] overflow-hidden">

            <div class="px-5 py-4 border-b flex justify-between">
                <h2 class="font-bold">
                    プレビュー
                </h2>

                <button
                    onclick="document.getElementById('preview_modal').remove()"
                    class="text-slate-500"
                >
                    ✕
                </button>
            </div>

            <div class="p-5 overflow-y-auto max-h-[calc(90vh-70px)]">

                <div class="flex justify-end gap-2 mb-5">
                    <button
                        onclick="App.actions.previewMode('pc')"
                        class="px-3 py-2 rounded-lg bg-slate-100"
                    >
                        PC表示
                    </button>

                    <button
                        onclick="App.actions.previewMode('mobile')"
                        class="px-3 py-2 rounded-lg bg-slate-100"
                    >
                        スマートフォン表示
                    </button>
                </div>

                <div id="preview_content">
                    ${this.previewContent(survey)}
                </div>

            </div>
        </div>
    `;

    document.body.appendChild(modal);
};

window.App.actions.previewMode = function(mode) {
    const content =
        document.getElementById(
            'preview_content'
        );

    if (!content) {
        return;
    }

    content.className =
        mode === 'mobile'
            ? 'max-w-sm mx-auto'
            : '';

    content.innerHTML =
        thisApp().previewContent(
            thisApp().state.editorSurvey
        );
};

window.App.previewContent = function(
    survey
) {
    return `
        <div class="space-y-6">
            <h1 class="text-2xl font-bold">
                ${this.util.escape(survey.title)}
            </h1>

            ${
                survey.groups.map(
                    group => `
                    <section class="border rounded-xl p-5">
                        <h2 class="font-bold mb-5">
                            ${this.util.escape(group.name)}
                        </h2>

                        <div class="space-y-6">
                            ${
                                group.questions.map(
                                    q => `
                                    <div>
                                        <div class="font-semibold mb-2">
                                            ${this.util.escape(q.text || '無題の質問')}
                                        </div>

                                        ${
                                            q.type === 'text'
                                            ? `
                                            <textarea
                                                class="w-full border rounded-xl p-3"
                                                rows="4"
                                                placeholder="回答入力欄"
                                            ></textarea>
                                            `
                                            :
                                            q.options.map(
                                                option => `
                                                <label class="flex gap-2 p-2">
                                                    <input
                                                        type="${q.type === 'single' ? 'radio' : 'checkbox'}"
                                                        name="preview_${this.util.escape(q.id)}"
                                                    >
                                                    ${this.util.escape(option)}
                                                </label>
                                                `
                                            ).join('')
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
                class="px-5 py-3 bg-blue-600 text-white rounded-xl"
            >
                送信
            </button>
        </div>
    `;
};

/* ================================================================
 * kintone settings
 * ================================================================ */

window.App.render.settings = function() {
    const settings =
        thisApp().state.data.settings;

    document.getElementById('main').innerHTML = `
        <div class="max-w-5xl mx-auto space-y-5">

            <div>
                <h1 class="text-2xl font-bold">
                    kintone連携設定
                </h1>

                <p class="text-sm text-slate-500 mt-1">
                    kintone APIへの接続情報と顧客項目マッピングを設定します。
                </p>
            </div>

            <form
                id="settings_form"
                class="space-y-5"
                onsubmit="event.preventDefault();App.actions.saveSettings()"
            >

                <div class="bg-white border border-slate-200 rounded-2xl p-5">

                    <h2 class="font-bold text-lg mb-4">
                        接続設定
                    </h2>

                    <div class="grid md:grid-cols-2 gap-4">

                        <div>
                            <label class="block text-sm font-semibold mb-2">
                                サブドメイン / FQDN
                            </label>

                            <input
                                id="setting_subdomain"
                                value="${this.util.escape(
                                    settings.subdomain || ''
                                )}"
                                placeholder="xxxx または xxxx.cybozu.com"
                                class="w-full border border-slate-300 rounded-xl px-4 py-3"
                            >

                            <p class="text-xs text-slate-500 mt-1">
                                https:// は入力しても構いません。
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold mb-2">
                                アプリID
                            </label>

                            <input
                                id="setting_app_id"
                                value="${this.util.escape(
                                    settings.app_id || ''
                                )}"
                                inputmode="numeric"
                                class="w-full border border-slate-300 rounded-xl px-4 py-3"
                            >
                        </div>

                        <div>
                            <label class="block text-sm font-semibold mb-2">
                                ログイン名
                            </label>

                            <input
                                id="setting_login_name"
                                value="${this.util.escape(
                                    settings.login_name || ''
                                )}"
                                autocomplete="off"
                                class="w-full border border-slate-300 rounded-xl px-4 py-3"
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
                                autocomplete="new-password"
                                class="w-full border border-slate-300 rounded-xl px-4 py-3"
                            >
                        </div>

                        <div>
                            <label class="block text-sm font-semibold mb-2">
                                Proxyサーバ
                            </label>

                            <input
                                id="setting_proxy"
                                value="${this.util.escape(
                                    settings.proxy || ''
                                )}"
                                placeholder="proxy.example.local:8080"
                                class="w-full border border-slate-300 rounded-xl px-4 py-3"
                            >
                        </div>

                        <label class="flex items-center gap-3 p-3 border rounded-xl">
                            <input
                                id="setting_ssl_verify"
                                type="checkbox"
                                ${settings.ssl_verify ? 'checked' : ''}
                            >
                            SSL証明書を検証する
                        </label>

                    </div>

                    <div class="flex flex-wrap gap-2 mt-5">

                        <button
                            type="button"
                            onclick="App.actions.testKintone()"
                            class="px-5 py-3 rounded-xl bg-indigo-600 text-white font-semibold"
                        >
                            接続テスト
                        </button>

                        <button
                            type="button"
                            onclick="App.actions.fetchKintoneFields()"
                            class="px-5 py-3 rounded-xl bg-blue-600 text-white font-semibold"
                        >
                            項目一覧を再取得
                        </button>

                        <button
                            type="submit"
                            class="px-5 py-3 rounded-xl bg-slate-800 text-white font-semibold"
                        >
                            設定を保存
                        </button>

                    </div>

                </div>

                <div
                    id="field_message"
                    class="hidden"
                ></div>

                <div
                    id="kintone_diagnostic"
                    class="hidden bg-slate-950 text-slate-100 rounded-2xl p-5 overflow-auto"
                ></div>

                <div class="bg-white border border-slate-200 rounded-2xl p-5">

                    <h2 class="font-bold text-lg mb-4">
                        顧客項目マッピング
                    </h2>

                    <p class="text-sm text-slate-500 mb-5">
                        kintoneから取得した日本語フィールド名を選択してください。
                    </p>

                    <div class="grid md:grid-cols-2 gap-4">

                        ${this.mappingSelect(
                            'field_company',
                            '会社名',
                            settings.field_company
                        )}

                        ${this.mappingSelect(
                            'field_name',
                            '氏名',
                            settings.field_name
                        )}

                        ${this.mappingSelect(
                            'field_email',
                            'メールアドレス',
                            settings.field_email
                        )}

                        ${this.mappingSelect(
                            'field_department',
                            '部署名',
                            settings.field_department
                        )}

                        ${this.mappingSelect(
                            'field_phone',
                            '電話番号',
                            settings.field_phone
                        )}

                        ${this.mappingSelect(
                            'field_address',
                            '住所',
                            settings.field_address,
                            true
                        )}

                    </div>

                </div>

            </form>
        </div>
    `;

    this.renderKintoneFields();
};

window.App.mappingSelect = function(
    id,
    label,
    value,
    multiple = false
) {
    const values =
        multiple
        ? Array.isArray(value)
            ? value
            : []
        : [value || ''];

    return `
        <div>
            <label class="block text-sm font-semibold mb-2">
                ${label}
            </label>

            <select
                id="${id}"
                ${multiple ? 'multiple size="5"' : ''}
                class="w-full border border-slate-300 rounded-xl px-3 py-3"
            >
                <option value="">
                    -- 選択してください --
                </option>

                ${this.kintoneOptionHtml(
                    values
                )}
            </select>
        </div>
    `;
};

window.App.kintoneOptionHtml = function(
    selectedValues
) {
    const fields =
        thisApp().state.kintoneFields;

    return Object.keys(fields)
        .map(code => {
            const field =
                fields[code];

            const selected =
                selectedValues.includes(
                    code
                );

            return `
                <option
                    value="${this.util.escape(code)}"
                    ${selected ? 'selected' : ''}
                >
                    ${this.util.escape(
                        field.label || code
                    )}
                    [${this.util.escape(code)}]
                </option>
            `;
        })
        .join('');
};

window.App.renderKintoneFields = function() {
    const fields =
        thisApp().state.kintoneFields;

    if (!Object.keys(fields).length) {
        return;
    }

    [
        'field_company',
        'field_name',
        'field_email',
        'field_department',
        'field_phone',
        'field_address'
    ].forEach(id => {
        const el =
            document.getElementById(id);

        if (!el) {
            return;
        }

        const current =
            el.multiple
            ? Array.from(
                el.selectedOptions
            ).map(
                x => x.value
            )
            : [el.value];

        el.innerHTML =
            `<option value="">-- 選択してください --</option>` +
            this.kintoneOptionHtml(
                current
            );

        current.forEach(v => {
            Array.from(
                el.options
            ).forEach(option => {
                if (
                    option.value === v
                ) {
                    option.selected =
                        true;
                }
            });
        });
    });
};

/*
 * 必須関数:
 * fetchKintoneFields()
 *
 * kintone APIからフィールド一覧を取得し、
 * 日本語labelとfield codeを保持する。
 */
window.App.actions.fetchKintoneFields = async function() {
    const settings =
        thisApp().collectSettings();

    const appId =
        settings.app_id;

    if (
        !appId ||
        !/^\d+$/.test(appId)
    ) {
        thisApp().showKintoneError({
            ok: false,
            message:
                'アプリIDは数字で入力してください。',
            error_code:
                'INVALID_APP_ID',
            status: 400
        });

        return;
    }

    thisApp().showFieldMessage(
        'kintoneから項目一覧を取得しています...',
        'info'
    );

    try {
        const result =
            await thisApp().api.kintoneFields(
                settings,
                appId
            );

        if (!result.ok) {
            thisApp().showKintoneError(
                result
            );
            return;
        }

        thisApp().state.kintoneFields =
            result.fields || {};

        thisApp().renderKintoneFields();

        thisApp().showFieldMessage(
            '項目一覧を取得しました。' +
            Object.keys(
                thisApp().state.kintoneFields
            ).length +
            '項目',
            'success'
        );

        thisApp().showDiagnostic({
            status:
                result.status,
            endpoint:
                result.endpoint,
            message:
                'kintone API正常応答'
        });

    } catch (error) {
        thisApp().showKintoneError({
            ok: false,
            message:
                error.message
        });
    }
};

window.App.actions.testKintone = async function() {
    const settings =
        thisApp().collectSettings();

    if (!settings.app_id) {
        thisApp().showKintoneError({
            ok: false,
            message:
                'アプリIDを入力してください。',
            error_code:
                'INVALID_APP_ID',
            status: 400
        });
        return;
    }

    thisApp().showFieldMessage(
        'kintoneへ接続しています...',
        'info'
    );

    try {
        const result =
            await thisApp().api.kintoneTest(
                settings,
                settings.app_id
            );

        if (!result.ok) {
            thisApp().showKintoneError(
                result
            );
            return;
        }

        thisApp().showFieldMessage(
            '接続成功：HTTP ' +
            result.status,
            'success'
        );

        thisApp().showDiagnostic({
            status:
                result.status,
            endpoint:
                result.endpoint,
            message:
                result.message ||
                'kintoneへの接続に成功しました。'
        });

    } catch (error) {
        thisApp().showKintoneError({
            ok: false,
            message:
                error.message
        });
    }
};

window.App.collectSettings = function() {
    const old =
        thisApp().state.data.settings;

    const address =
        document.getElementById(
            'field_address'
        );

    return {
        subdomain:
            document.getElementById(
                'setting_subdomain'
            )?.value.trim() || '',

        login_name:
            document.getElementById(
                'setting_login_name'
            )?.value.trim() || '',

        password:
            document.getElementById(
                'setting_password'
            )?.value || old.password || '',

        app_id:
            document.getElementById(
                'setting_app_id'
            )?.value.trim() || '',

        ssl_verify:
            !!document.getElementById(
                'setting_ssl_verify'
            )?.checked,

        proxy:
            document.getElementById(
                'setting_proxy'
            )?.value.trim() || '',

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
            ? Array.from(
                address.selectedOptions
            ).map(
                option => option.value
            )
            : []
    };
};

window.App.actions.saveSettings = async function() {
    const settings =
        thisApp().collectSettings();

    try {
        const result =
            await thisApp().api.saveSettings(
                settings
            );

        if (!result.ok) {
            thisApp().showKintoneError(
                result
            );
            return;
        }

        await thisApp().api.load();

        thisApp().util.toast(
            'kintone設定を保存しました。'
        );

    } catch (error) {
        thisApp().showKintoneError({
            ok: false,
            message:
                error.message
        });
    }
};

window.App.showFieldMessage = function(
    message,
    type
) {
    const el =
        document.getElementById(
            'field_message'
        );

    if (!el) {
        return;
    }

    const colors = {
        success:
            'bg-emerald-50 text-emerald-700 border-emerald-200',
        error:
            'bg-red-50 text-red-700 border-red-200',
        info:
            'bg-blue-50 text-blue-700 border-blue-200'
    };

    el.className =
        'block border rounded-xl p-4 ' +
        (colors[type] || colors.info);

    el.textContent =
        message;
};

/*
 * kintoneエラーを詳細表示する中心関数。
 *
 * 「不正なリクエストです」だけではなく、
 * HTTP Status
 * kintone error code
 * message
 * endpoint
 * response body
 * PHP warning
 * PHP last error
 * を確認できる。
 */
window.App.showKintoneError = function(result) {
    const status =
        result.status ?? 0;

    const code =
        result.error_code || 'なし';

    const message =
        result.message ||
        '不明なエラー';

    thisApp().showFieldMessage(
        'kintone通信エラー：' +
        message +
        (
            code !== 'なし'
            ? ' [' + code + ']'
            : ''
        ),
        'error'
    );

    thisApp().showDiagnostic({
        status: status,
        error_code: code,
        message: message,
        endpoint:
            result.endpoint || '',
        diagnostic:
            result.diagnostic || {},
        response:
            result.data || {}
    });
};

window.App.showDiagnostic = function(info) {
    const el =
        document.getElementById(
            'kintone_diagnostic'
        );

    if (!el) {
        return;
    }

    el.className =
        'block bg-slate-950 text-slate-100 rounded-2xl p-5 overflow-auto';

    const diagnostic =
        info.diagnostic || {};

    const response =
        info.response || {};

    el.innerHTML = `
        <div class="flex justify-between items-center mb-4">
            <div class="font-bold text-white">
                kintone通信診断情報
            </div>

            <button
                onclick="document.getElementById('kintone_diagnostic').classList.add('hidden')"
                class="text-slate-400 hover:text-white"
            >
                閉じる
            </button>
        </div>

        <div class="grid md:grid-cols-2 gap-3 mb-4">

            <div class="bg-slate-900 rounded-lg p-3">
                <div class="text-xs text-slate-400">
                    HTTP Status
                </div>
                <div class="text-lg font-bold">
                    ${this.util.escape(
                        info.status ?? ''
                    )}
                </div>
            </div>

            <div class="bg-slate-900 rounded-lg p-3">
                <div class="text-xs text-slate-400">
                    kintone error code
                </div>
                <div class="text-lg font-bold text-yellow-300">
                    ${this.util.escape(
                        info.error_code || 'なし'
                    )}
                </div>
            </div>

        </div>

        <div class="space-y-3 text-sm">

            <div>
                <div class="text-slate-400">
                    Message
                </div>
                <pre class="whitespace-pre-wrap text-red-300">${this.util.escape(
                    info.message || ''
                )}</pre>
            </div>

            <div>
                <div class="text-slate-400">
                    Endpoint
                </div>
                <pre class="whitespace-pre-wrap text-cyan-300">${this.util.escape(
                    info.endpoint || ''
                )}</pre>
            </div>

            ${
                diagnostic.response_body
                ? `
                <div>
                    <div class="text-slate-400">
                        Response Body
                    </div>
                    <pre class="whitespace-pre-wrap text-orange-300">${this.util.escape(
                        diagnostic.response_body
                    )}</pre>
                </div>
                `
                : ''
            }

            ${
                diagnostic.php_warning &&
                diagnostic.php_warning.length
                ? `
                <div>
                    <div class="text-slate-400">
                        PHP Warning
                    </div>
                    <pre class="whitespace-pre-wrap text-yellow-300">${this.util.escape(
                        diagnostic.php_warning.join('\\n')
                    )}</pre>
                </div>
                `
                : ''
            }

            ${
                diagnostic.php_last_error
                ? `
                <div>
                    <div class="text-slate-400">
                        PHP Last Error
                    </div>
                    <pre class="whitespace-pre-wrap text-red-300">${this.util.escape(
                        diagnostic.php_last_error
                    )}</pre>
                </div>
                `
                : ''
            }

            ${
                Object.keys(response).length
                ? `
                <div>
                    <div class="text-slate-400">
                        Parsed JSON
                    </div>
                    <pre class="whitespace-pre-wrap text-green-300">${this.util.escape(
                        JSON.stringify(
                            response,
                            null,
                            2
                        )
                    )}</pre>
                </div>
                `
                : ''
            }

        </div>
    `;
};

/* ================================================================
 * analytics
 * ================================================================ */

window.App.actions.analytics = function(id) {
    const survey =
        thisApp().state.data.surveys
        .find(
            s => s.id === id
        );

    if (!survey) {
        return;
    }

    thisApp().state.responseSurveyId =
        id;

    thisApp().render.analytics(
        survey
    );
};

window.App.render.analytics = function(survey) {
    const responses =
        thisApp().state.data.responses
        .filter(
            r =>
                String(r.survey_id) ===
                String(survey.id)
        );

    const customers =
        thisApp().state.data.customers;

    const sent =
        customers.filter(
            c =>
                c.sent_at
        ).length;

    const unanswered =
        Math.max(
            0,
            sent - responses.length
        );

    const rate =
        sent
            ? (
                responses.length /
                sent *
                100
            ).toFixed(1)
            : '0.0';

    const questions =
        thisApp().questionListFor(
            survey
        );

    document.getElementById('main').innerHTML = `
        <div class="space-y-5">

            <div class="flex justify-between items-center">
                <div>
                    <div class="text-sm text-slate-500">
                        集計・分析
                    </div>
                    <h1 class="text-2xl font-bold">
                        ${this.util.escape(
                            survey.title
                        )}
                    </h1>
                </div>

                <div class="flex gap-2">
                    <button
                        onclick="App.actions.exportCsv('${this.util.escape(survey.id)}')"
                        class="px-4 py-2 rounded-xl bg-emerald-600 text-white"
                    >
                        CSV出力
                    </button>

                    <button
                        onclick="App.actions.goList()"
                        class="px-4 py-2 rounded-xl bg-slate-100"
                    >
                        一覧へ戻る
                    </button>
                </div>
            </div>

            <div class="grid md:grid-cols-5 gap-3">

                ${this.summaryCard(
                    '送信対象者数',
                    sent + ' 人'
                )}

                ${this.summaryCard(
                    '回答数',
                    responses.length + ' 件'
                )}

                ${this.summaryCard(
                    '未登録顧客からの回答数',
                    responses.filter(
                        r =>
                            !r.customer_id
                    ).length + ' 件'
                )}

                ${this.summaryCard(
                    '未回答数',
                    unanswered + ' 人'
                )}

                ${this.summaryCard(
                    '回答率',
                    rate + ' %'
                )}

            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5">

                <div class="flex justify-between mb-4">
                    <h2 class="font-bold">
                        設問別集計
                    </h2>

                    <div class="flex gap-2">
                        <button
                            onclick="App.actions.selectAllQuestions(true)"
                            class="text-sm text-blue-600"
                        >
                            全選択
                        </button>

                        <button
                            onclick="App.actions.selectAllQuestions(false)"
                            class="text-sm text-blue-600"
                        >
                            全解除
                        </button>
                    </div>
                </div>

                <div class="space-y-2">
                    ${
                        questions.map(
                            q => `
                            <label class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50">
                                <input
                                    type="checkbox"
                                    ${thisApp().state.selectedQuestions[q.id] !== false ? 'checked' : ''}
                                    onchange="App.actions.toggleQuestion('${this.util.escape(q.id)}', this.checked)"
                                >
                                <span class="font-semibold">
                                    ${this.util.escape(q.text || '無題')}
                                </span>
                                <span class="text-xs px-2 py-1 bg-slate-100 rounded">
                                    ${this.util.escape(
                                        this.util.typeLabel(q.type)
                                    )}
                                </span>
                            </label>
                            `
                        ).join('')
                    }
                </div>
            </div>

            ${
                responses.length
                ? `
                <div class="space-y-4">
                    ${
                        questions
                        .filter(
                            q =>
                                thisApp()
                                .state
                                .selectedQuestions[
                                    q.id
                                ] !== false
                        )
                        .map(
                            q =>
                                this.analyticsQuestion(
                                    q,
                                    responses
                                )
                        ).join('')
                    }
                </div>
                `
                : `
                <div class="bg-white border rounded-2xl p-16 text-center text-slate-400">
                    現在、回答データはありません
                </div>
                `
            }

            <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">

                <div class="p-5 border-b">
                    <h2 class="font-bold">
                        個別回答一覧
                    </h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="text-left px-5 py-3">会社名</th>
                                <th class="text-left px-5 py-3">氏名</th>
                                <th class="text-left px-5 py-3">回答日時</th>
                                <th class="text-right px-5 py-3">詳細</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y">
                            ${
                                responses.map(
                                    r => `
                                    <tr>
                                        <td class="px-5 py-3">
                                            ${this.util.escape(r.company || '')}
                                        </td>

                                        <td class="px-5 py-3">
                                            ${this.util.escape(r.name || '')}
                                        </td>

                                        <td class="px-5 py-3">
                                            ${this.util.escape(r.answered_at || '')}
                                        </td>

                                        <td class="px-5 py-3 text-right">
                                            <button
                                                onclick="App.actions.responseDetail('${this.util.escape(r.id)}')"
                                                class="px-3 py-2 rounded-lg bg-blue-50 text-blue-700"
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
                </div>
            </div>

        </div>
    `;
};

window.App.summaryCard = function(
    label,
    value
) {
    return `
        <div class="bg-white border border-slate-200 rounded-2xl p-5">
            <div class="text-sm text-slate-500">
                ${this.util.escape(label)}
            </div>
            <div class="text-2xl font-bold mt-2">
                ${this.util.escape(value)}
            </div>
        </div>
    `;
};

window.App.questionListFor = function(
    survey
) {
    const result = [];

    survey.groups.forEach(
        group =>
            group.questions.forEach(
                q => result.push(q)
            )
    );

    return result;
};

window.App.analyticsQuestion = function(
    q,
    responses
) {
    if (q.type === 'text') {
        return `
            <div class="bg-white border rounded-2xl p-5">
                <h3 class="font-bold mb-4">
                    ${this.util.escape(q.text)}
                </h3>

                <div class="space-y-3 max-h-80 overflow-y-auto">
                    ${
                        responses.map(
                            r => `
                            <div class="border-b pb-3">
                                <div class="text-xs text-slate-400">
                                    ${this.util.escape(r.company || '')}
                                    /
                                    ${this.util.escape(r.name || '')}
                                </div>
                                <div class="mt-1">
                                    ${this.util.escape(
                                        Array.isArray(
                                            r.answers?.[q.id]
                                        )
                                            ? r.answers[q.id].join('、')
                                            : r.answers?.[q.id] || ''
                                    )}
                                </div>
                            </div>
                            `
                        ).join('')
                    }
                </div>
            </div>
        `;
    }

    const counts = {};

    q.options.forEach(
        o => counts[o] = 0
    );

    let total = 0;

    responses.forEach(
        r => {
            const value =
                r.answers?.[q.id];

            if (Array.isArray(value)) {
                value.forEach(
                    v => {
                        if (
                            counts[v] !== undefined
                        ) {
                            counts[v]++;
                        }
                    }
                );
                total++;
            } else if (
                value !== undefined &&
                value !== ''
            ) {
                if (
                    counts[value] !== undefined
                ) {
                    counts[value]++;
                }
                total++;
            }
        }
    );

    return `
        <div class="bg-white border rounded-2xl p-5">
            <h3 class="font-bold mb-5">
                ${this.util.escape(q.text)}
            </h3>

            <div class="space-y-4">
                ${
                    Object.entries(counts)
                    .map(
                        ([label, count]) => {
                            const percent =
                                total
                                    ? count /
                                      total *
                                      100
                                    : 0;

                            return `
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span>
                                        ${this.util.escape(label)}
                                    </span>
                                    <span>
                                        ${count}件
                                        (${percent.toFixed(1)}%)
                                    </span>
                                </div>

                                <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                    <div
                                        class="h-full bg-blue-600"
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

window.App.actions.toggleQuestion = function(
    id,
    checked
) {
    thisApp().state.selectedQuestions[id] =
        checked;

    const survey =
        thisApp().state.data.surveys
        .find(
            s =>
                s.id ===
                thisApp().state.responseSurveyId
        );

    if (survey) {
        thisApp().render.analytics(
            survey
        );
    }
};

window.App.actions.selectAllQuestions = function(
    checked
) {
    const survey =
        thisApp().state.data.surveys
        .find(
            s =>
                s.id ===
                thisApp().state.responseSurveyId
        );

    if (!survey) {
        return;
    }

    thisApp().questionListFor(
        survey
    ).forEach(
        q =>
            thisApp()
                .state
                .selectedQuestions[q.id] =
                checked
    );

    thisApp().render.analytics(
        survey
    );
};

window.App.actions.responseDetail = function(
    id
) {
    const response =
        thisApp().state.data.responses
        .find(
            r => r.id === id
        );

    if (!response) {
        return;
    }

    const survey =
        thisApp().state.data.surveys
        .find(
            s =>
                s.id ===
                response.survey_id
        );

    if (!survey) {
        return;
    }

    const questions =
        thisApp().questionListFor(
            survey
        );

    const modal =
        document.createElement('div');

    modal.id =
        'response_modal';

    modal.className =
        'fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-5';

    modal.innerHTML = `
        <div class="bg-white rounded-2xl max-w-3xl w-full max-h-[90vh] overflow-hidden">

            <div class="p-5 border-b flex justify-between">
                <div>
                    <h2 class="font-bold">
                        全回答
                    </h2>
                    <div class="text-sm text-slate-500">
                        ${this.util.escape(response.name || '')}
                    </div>
                </div>

                <button
                    onclick="document.getElementById('response_modal').remove()"
                    class="text-slate-500"
                >
                    ✕
                </button>
            </div>

            <div
                id="response_detail"
                class="p-5 space-y-5 overflow-y-auto max-h-[calc(90vh-90px)]"
            >
                ${
                    questions.map(
                        q => {
                            let value =
                                response
                                .answers?.[
                                    q.id
                                ] ?? '';

                            if (
                                Array.isArray(value)
                            ) {
                                value =
                                    value.join(
                                        '、'
                                    );
                            }

                            return `
                            <div class="border rounded-xl p-4">
                                <div class="font-semibold">
                                    ${this.util.escape(q.text)}
                                </div>
                                <div class="mt-2 text-slate-600 whitespace-pre-wrap">
                                    ${this.util.escape(value)}
                                </div>
                            </div>
                            `;
                        }
                    ).join('')
                }
            </div>

        </div>
    `;

    document.body.appendChild(
        modal
    );
};

window.App.actions.exportCsv = function(
    surveyId
) {
    const url =
        location.pathname +
        '?action=csv&survey_id=' +
        encodeURIComponent(
            surveyId
        );

    window.location.href = url;
};

/* ================================================================
 * mail
 * ================================================================ */

window.App.actions.mail = function(id) {
    const survey =
        thisApp().state.data.surveys
        .find(
            s => s.id === id
        );

    if (!survey) {
        return;
    }

    document.getElementById('main').innerHTML = `
        <div class="space-y-5">

            <div class="flex justify-between">
                <div>
                    <div class="text-sm text-slate-500">
                        ホーム ＞ アンケート一覧 ＞ 顧客選択・送信
                    </div>
                    <h1 class="text-2xl font-bold mt-2">
                        ${this.util.escape(survey.title)}
                    </h1>
                </div>

                <button
                    onclick="App.actions.goList()"
                    class="px-4 py-2 bg-slate-100 rounded-xl"
                >
                    一覧へ戻る
                </button>
            </div>

            <div class="bg-white border rounded-2xl p-5 space-y-4">

                <div class="grid md:grid-cols-3 gap-3">

                    <input
                        id="customer_filter"
                        oninput="App.actions.filterCustomers(this.value)"
                        placeholder="顧客名・メールアドレス"
                        class="border rounded-xl px-4 py-3"
                    >

                    <select
                        id="template_type"
                        class="border rounded-xl px-4 py-3"
                    >
                        <option value="initial">
                            初回送信
                        </option>
                        <option value="reminder">
                            リマインド
                        </option>
                    </select>

                    <button
                        onclick="App.actions.sendSelected('${this.util.escape(id)}')"
                        class="px-5 py-3 bg-blue-600 text-white rounded-xl font-semibold"
                    >
                        選択した顧客へ一括送信
                    </button>

                </div>

                <input
                    id="mail_subject"
                    placeholder="メール件名"
                    class="w-full border rounded-xl px-4 py-3"
                    value="${this.util.escape(
                        survey.title
                    )} のご案内"
                >

                <textarea
                    id="mail_body"
                    rows="7"
                    class="w-full border rounded-xl px-4 py-3"
                    placeholder="メール本文"
                >{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}</textarea>

            </div>

            <div
                id="customer_table"
                class="bg-white border rounded-2xl overflow-hidden"
            >
                ${this.customerTable()}
            </div>

        </div>
    `;

    thisApp().currentMailSurveyId =
        id;
};

window.App.customerTable = function() {
    const customers =
        thisApp().state.data.customers;

    const keyword =
        thisApp().state.customerFilter ||
        '';

    const filtered =
        customers.filter(
            c =>
                !keyword ||
                String(
                    c.name || ''
                ).includes(keyword) ||
                String(
                    c.email || ''
                ).includes(keyword)
        );

    return `
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-3 text-left">
                            <input
                                id="select_all"
                                type="checkbox"
                                onchange="App.actions.selectAllCustomers(this.checked)"
                            >
                        </th>
                        <th class="px-4 py-3 text-left">会社名 / 氏名</th>
                        <th class="px-4 py-3 text-left">メール</th>
                        <th class="px-4 py-3 text-left">送信日時</th>
                        <th class="px-4 py-3 text-left">送信回数</th>
                        <th class="px-4 py-3 text-left">回答</th>
                        <th class="px-4 py-3 text-left">kintone</th>
                    </tr>
                </thead>

                <tbody class="divide-y">
                    ${
                        filtered.map(
                            c => `
                            <tr>
                                <td class="px-4 py-3">
                                    ${
                                        c.source === 'web'
                                        ? ''
                                        : `
                                        <input
                                            class="customer-check"
                                            type="checkbox"
                                            value="${this.util.escape(c.id)}"
                                        >
                                        `
                                    }
                                </td>

                                <td class="px-4 py-3">
                                    <div class="font-semibold">
                                        ${this.util.escape(c.company || '')}
                                    </div>
                                    <div>
                                        ${this.util.escape(c.name || '')}
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    ${this.util.escape(c.email || '')}
                                </td>

                                <td class="px-4 py-3">
                                    ${this.util.escape(c.sent_at || '未送信')}
                                </td>

                                <td class="px-4 py-3">
                                    ${Number(c.send_count || 0)} 回
                                </td>

                                <td class="px-4 py-3">
                                    ${
                                        c.answer_status === 'answered'
                                        ? '<span class="text-emerald-600">回答済み</span>'
                                        : '<span class="text-slate-500">未回答</span>'
                                    }
                                </td>

                                <td class="px-4 py-3">
                                    ${
                                        c.kintone_status === 'registered'
                                        ? '✓ 登録完了'
                                        : `
                                        <button
                                            onclick="App.actions.markKintone('${this.util.escape(c.id)}')"
                                            class="text-blue-600"
                                        >
                                            キントーン登録完了
                                        </button>
                                        `
                                    }
                                </td>
                            </tr>
                            `
                        ).join('')
                    }
                </tbody>
            </table>
        </div>
    `;
};

window.App.actions.filterCustomers = function(
    value
) {
    thisApp().state.customerFilter =
        value;

    const el =
        document.getElementById(
            'customer_table'
        );

    if (el) {
        el.innerHTML =
            thisApp().customerTable();
    }
};

window.App.actions.selectAllCustomers = function(
    checked
) {
    document.querySelectorAll(
        '.customer-check'
    ).forEach(
        el =>
            el.checked = checked
    );
};

window.App.actions.sendSelected = async function(
    surveyId
) {
    const selected =
        Array.from(
            document.querySelectorAll(
                '.customer-check:checked'
            )
        ).map(
            el => el.value
        );

    if (!selected.length) {
        thisApp().util.toast(
            '送信対象を選択してください。',
            'error'
        );
        return;
    }

    const subject =
        document.getElementById(
            'mail_subject'
        )?.value || '';

    const body =
        document.getElementById(
            'mail_body'
        )?.value || '';

    const type =
        document.getElementById(
            'template_type'
        )?.value || 'initial';

    const alreadySent =
        selected.filter(id => {
            const c =
                thisApp().state.data.customers
                .find(
                    x => x.id === id
                );

            return c && c.sent_at;
        });

    if (
        alreadySent.length &&
        !confirm(
            '既に送信済みの宛先が含まれています。再送しますか？'
        )
    ) {
        return;
    }

    const result =
        await thisApp().api.request(
            'send_mail',
            {
                recipient_ids:
                    selected,
                mail_subject:
                    subject,
                mail_body:
                    body,
                template_type:
                    type
            }
        );

    if (!result.ok) {
        thisApp().util.toast(
            result.message ||
            '送信に失敗しました。',
            'error'
        );
        return;
    }

    await thisApp().api.load();

    thisApp().util.toast(
        result.count +
        '件の送信処理を実行しました。'
    );

    thisApp().actions.mail(
        surveyId
    );
};

window.App.actions.markKintone = async function(
    id
) {
    const result =
        await thisApp().api.request(
            'mark_kintone_registered',
            {
                customer_id: id
            }
        );

    if (!result.ok) {
        thisApp().util.toast(
            result.message ||
            '更新に失敗しました。',
            'error'
        );
        return;
    }

    await thisApp().api.load();

    thisApp().actions.mail(
        thisApp().currentMailSurveyId
    );
};

/* ================================================================
 * logout
 * ================================================================ */

window.App.actions.logout = function() {
    if (
        confirm(
            'ログアウトしますか？'
        )
    ) {
        sessionStorage.clear();
        thisApp().util.toast(
            'ログアウトしました。',
            'info'
        );
    }
};

/* ================================================================
 * init
 *
 * readyStateを確認し、
 * DOMContentLoaded前後どちらでも1回だけ実行する。
 * ================================================================ */

if (
    document.readyState === 'loading'
) {
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
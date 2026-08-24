<?php
declare(strict_types=1);

/**
 * ============================================================
 * アンケート管理システム
 * index.php
 * PHP 8.5 / Apache 2.4
 * ============================================================
 *
 * GUARD COMMENT
 *
 * 【固定ストレージ名】
 * survey_storage_directory
 * survey_storage_file
 * survey_admin_session_v1
 *
 * 【PHP定数】
 * SURVEY_STORAGE_DIRECTORY
 * SURVEY_STORAGE_FILE
 * SURVEY_ADMIN_SESSION
 *
 * 【JSONトップキー】
 * surveys
 * responses
 * customers
 * settings
 * mail_logs
 *
 * 【固定JSONキー】
 * properties
 * records
 * label
 * code
 * type
 * message
 * ok
 * fields
 *
 * 【主要POST/GET】
 * action
 * survey_id
 * customer_id
 * response_id
 * keyword
 * status_filter
 * sort
 * survey_json
 * settings_json
 * csrf_token
 * recipient_ids
 * mail_subject
 * mail_body
 * template_type
 * app_id
 * response_token
 * public_token
 * response_session
 * response_data
 *
 * 【DOM ID】
 * app
 * csrf_token
 * survey_title
 * survey_start_at
 * survey_end_at
 * survey_numbering_mode
 * question_editor
 * preview_modal
 * preview_content
 * response_modal
 * response_detail
 * response_filter
 * response_table
 * customer_filter
 * customer_table
 * select_all
 * mail_subject
 * mail_body
 * template_type
 * settings_form
 * settings_json
 * setting_subdomain
 * setting_app_id
 * setting_login_name
 * setting_password
 * setting_proxy
 * setting_ssl_verify
 * field_message
 *
 * 【回答者DOM ID】
 * respondent_form
 * respondent_company
 * respondent_name
 * respondent_email
 * respondent_department
 * respondent_phone
 * respondent_address
 * response_form
 * response_content
 * response_confirm_modal
 * response_confirm_content
 * response_complete
 *
 * 【JavaScript名前空間】
 * window.App
 * App.state
 * App.render
 * App.actions
 * App.api
 * App.utils
 *
 * 【固定値】
 * status:
 * draft / active / ended
 *
 * numbering_mode:
 * global / group
 *
 * question type:
 * single / multiple / text
 *
 * source:
 * kintone / web
 *
 * answer_status:
 * unanswered / answered
 *
 * kintone_status:
 * unregistered / registered
 *
 * template_type:
 * initial / reminder
 *
 * send_type:
 * initial / reminder / resend
 *
 * ============================================================
 */

const SURVEY_STORAGE_DIRECTORY = 'survey_storage_directory';
const SURVEY_STORAGE_FILE = 'survey_storage_file';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

const SURVEY_STORAGE_DIR = __DIR__ . '/survey_storage';
const SURVEY_STORAGE_PATH = SURVEY_STORAGE_DIR . '/survey_data.json';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SURVEY_ADMIN_SESSION);
    session_start();
}

/* ------------------------------------------------------------
 * 共通ユーティリティ
 * ------------------------------------------------------------ */

function survey_app_json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function survey_app_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function survey_app_now(): string
{
    return date('Y-m-d H:i:s');
}

function survey_app_uuid(string $prefix = ''): string
{
    try {
        $random = bin2hex(random_bytes(16));
    } catch (Throwable) {
        $random = md5(uniqid('', true) . microtime(true));
    }

    return $prefix . date('YmdHis') . '_' . $random;
}

function survey_app_token(int $length = 48): string
{
    try {
        return rtrim(
            strtr(base64_encode(random_bytes($length)), '+/', '-_'),
            '='
        );
    } catch (Throwable) {
        return hash('sha512', uniqid('', true) . microtime(true) . mt_rand());
    }
}

function survey_app_read_storage(): array
{
    $initial = [
        'surveys' => [],
        'responses' => [],
        'customers' => [],
        'settings' => [],
        'mail_logs' => [],
        'response_tokens' => [],
    ];

    if (!is_dir(SURVEY_STORAGE_DIR)) {
        @mkdir(SURVEY_STORAGE_DIR, 0775, true);
    }

    if (!file_exists(SURVEY_STORAGE_PATH)) {
        survey_app_write_storage($initial);
        return $initial;
    }

    $raw = @file_get_contents(SURVEY_STORAGE_PATH);

    if ($raw === false || trim($raw) === '') {
        survey_app_write_storage($initial);
        return $initial;
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        survey_app_write_storage($initial);
        return $initial;
    }

    foreach ($initial as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    return $data;
}

function survey_app_write_storage(array $data): bool
{
    if (!is_dir(SURVEY_STORAGE_DIR)) {
        if (!@mkdir(SURVEY_STORAGE_DIR, 0775, true) && !is_dir(SURVEY_STORAGE_DIR)) {
            return false;
        }
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $tmp = SURVEY_STORAGE_PATH . '.tmp.' . bin2hex(random_bytes(4));

    $fp = @fopen($tmp, 'wb');
    if ($fp === false) {
        return false;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        @unlink($tmp);
        return false;
    }

    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if (!@rename($tmp, SURVEY_STORAGE_PATH)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function survey_app_require_admin(): void
{
    if (empty($_SESSION['survey_admin_authenticated'])) {
        survey_app_json_response([
            'ok' => false,
            'message' => '管理者ログインが必要です。'
        ], 401);
    }
}

function survey_app_csrf_token(): string
{
    if (empty($_SESSION['survey_csrf_token'])) {
        $_SESSION['survey_csrf_token'] = survey_app_token(32);
    }

    return $_SESSION['survey_csrf_token'];
}

function survey_app_verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals(
            (string)($_SESSION['survey_csrf_token'] ?? ''),
            $token
        )
    ) {
        survey_app_json_response([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。'
        ], 403);
    }
}

function survey_app_get_settings(array $data): array
{
    $settings = $data['settings'] ?? [];

    if (!is_array($settings)) {
        return [];
    }

    return $settings;
}

function survey_app_encrypt_secret(string $value): string
{
    if ($value === '') {
        return '';
    }

    $key = hash(
        'sha256',
        (string)(
            $_SERVER['SERVER_NAME'] ??
            php_uname('n') .
            'survey-app-secret'
        ),
        true
    );

    $iv = random_bytes(16);

    $encrypted = openssl_encrypt(
        $value,
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($encrypted === false) {
        return '';
    }

    return base64_encode($iv . $encrypted);
}

function survey_app_decrypt_secret(string $value): string
{
    if ($value === '') {
        return '';
    }

    $decoded = base64_decode($value, true);

    if ($decoded === false || strlen($decoded) <= 16) {
        return '';
    }

    $key = hash(
        'sha256',
        (string)(
            $_SERVER['SERVER_NAME'] ??
            php_uname('n') .
            'survey-app-secret'
        ),
        true
    );

    $iv = substr($decoded, 0, 16);
    $cipher = substr($decoded, 16);

    $result = openssl_decrypt(
        $cipher,
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    return is_string($result) ? $result : '';
}

/* ------------------------------------------------------------
 * 管理者認証
 *
 * 本番では環境変数を設定:
 * SURVEY_ADMIN_USER
 * SURVEY_ADMIN_PASSWORD
 * ------------------------------------------------------------ */

function survey_app_admin_user(): string
{
    return (string)(getenv('SURVEY_ADMIN_USER') ?: 'admin');
}

function survey_app_admin_password(): string
{
    return (string)(getenv('SURVEY_ADMIN_PASSWORD') ?: '');
}

function survey_app_login(): never
{
    $user = (string)($_POST['login_name'] ?? '');
    $password = (string)($_POST['login_password'] ?? '');

    $configuredPassword = survey_app_admin_password();

    if (
        $configuredPassword !== '' &&
        hash_equals(survey_app_admin_user(), $user) &&
        hash_equals($configuredPassword, $password)
    ) {
        session_regenerate_id(true);

        $_SESSION['survey_admin_authenticated'] = true;
        $_SESSION['survey_admin_user'] = $user;
        $_SESSION['survey_csrf_token'] = survey_app_token(32);

        survey_app_json_response([
            'ok' => true,
            'message' => 'ログインしました。'
        ]);
    }

    survey_app_json_response([
        'ok' => false,
        'message' => 'ログイン情報が正しくありません。'
    ], 401);
}

/* ------------------------------------------------------------
 * アンケート正規化
 * ------------------------------------------------------------ */

function survey_app_normalize_question(array $q): array
{
    $type = $q['type'] ?? 'text';

    if (!in_array($type, ['single', 'multiple', 'text'], true)) {
        $type = 'text';
    }

    $options = [];
    foreach (($q['options'] ?? []) as $option) {
        if (is_array($option)) {
            $options[] = [
                'id' => (string)($option['id'] ?? survey_app_uuid('opt_')),
                'text' => (string)($option['text'] ?? ''),
                'branch_to' => $option['branch_to'] ?? null,
            ];
        } else {
            $options[] = [
                'id' => survey_app_uuid('opt_'),
                'text' => (string)$option,
                'branch_to' => null,
            ];
        }
    }

    return [
        'id' => (string)($q['id'] ?? survey_app_uuid('question_')),
        'text' => (string)($q['text'] ?? ''),
        'type' => $type,
        'required' => !empty($q['required']),
        'options' => $options,
        'other_enabled' => !empty($q['other_enabled']),
        'branching' => is_array($q['branching'] ?? null)
            ? $q['branching']
            : [],
    ];
}

function survey_app_normalize_survey(array $survey): array
{
    $status = $survey['status'] ?? 'draft';

    if (!in_array($status, ['draft', 'active', 'ended'], true)) {
        $status = 'draft';
    }

    $numberingMode = $survey['numbering_mode'] ?? 'global';

    if (!in_array($numberingMode, ['global', 'group'], true)) {
        $numberingMode = 'global';
    }

    $groups = [];

    foreach (($survey['groups'] ?? []) as $group) {
        if (!is_array($group)) {
            continue;
        }

        $questions = [];

        foreach (($group['questions'] ?? []) as $question) {
            if (is_array($question)) {
                $questions[] = survey_app_normalize_question($question);
            }
        }

        $groups[] = [
            'id' => (string)($group['id'] ?? survey_app_uuid('group_')),
            'name' => (string)($group['name'] ?? '新しいグループ'),
            'questions' => $questions,
        ];
    }

    return [
        'id' => (string)($survey['id'] ?? survey_app_uuid('survey_')),
        'title' => trim((string)($survey['title'] ?? '')),
        'start_at' => (string)($survey['start_at'] ?? ''),
        'end_at' => (string)($survey['end_at'] ?? ''),
        'status' => $status,
        'created_at' => (string)($survey['created_at'] ?? survey_app_now()),
        'updated_at' => survey_app_now(),
        'numbering_mode' => $numberingMode,
        'groups' => $groups,
        'deleted' => !empty($survey['deleted']),
        'general_response_enabled' => !empty($survey['general_response_enabled']),
        'public_token' => (string)($survey['public_token'] ?? survey_app_token(32)),
    ];
}

function survey_app_find_survey(array &$data, string $id): ?array
{
    foreach ($data['surveys'] as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function survey_app_survey_response_count(array $data, string $surveyId): int
{
    $count = 0;

    foreach ($data['responses'] as $response) {
        if (
            ($response['survey_id'] ?? '') === $surveyId &&
            empty($response['deleted'])
        ) {
            $count++;
        }
    }

    return $count;
}

function survey_app_renumber(array &$survey): void
{
    $global = 0;

    foreach ($survey['groups'] as $gi => &$group) {
        $local = 0;

        foreach ($group['questions'] as &$question) {
            $global++;
            $local++;

            if ($survey['numbering_mode'] === 'group') {
                $question['number'] = 'Q' . ($gi + 1) . '-' . $local;
            } else {
                $question['number'] = 'Q' . $global;
            }
        }

        unset($question);
    }

    unset($group);
}

/* ------------------------------------------------------------
 * kintone
 * ------------------------------------------------------------ */

function survey_app_normalize_kintone_domain(string $domain): string
{
    $domain = trim($domain);

    if ($domain === '') {
        return '';
    }

    if (!preg_match('#^https?://#i', $domain)) {
        $domain = 'https://' . $domain;
    }

    $parsed = parse_url($domain);

    if (!$parsed || empty($parsed['host'])) {
        return '';
    }

    $host = strtolower($parsed['host']);

    if (!str_ends_with($host, '.cybozu.com')) {
        if (!str_contains($host, '.')) {
            $host .= '.cybozu.com';
        }
    }

    return 'https://' . $host;
}

function survey_app_http_request(
    string $url,
    string $method = 'GET',
    array $headers = [],
    ?string $body = null,
    bool $sslVerify = true,
    string $proxy = ''
): array {
    $headerString = implode("\r\n", $headers);

    $options = [
        'http' => [
            'method' => $method,
            'header' => $headerString,
            'ignore_errors' => true,
            'timeout' => 30,
        ],
        'ssl' => [
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify,
            'allow_self_signed' => !$sslVerify,
        ],
    ];

    if ($body !== null) {
        $options['http']['content'] = $body;
    }

    if ($proxy !== '') {
        $options['http']['proxy'] = 'tcp://' . $proxy;
        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $result = @file_get_contents($url, false, $context);

    $responseHeaders = function_exists('http_get_last_response_headers')
        ? http_get_last_response_headers()
        : null;

    $status = 0;

    if (is_array($responseHeaders)) {
        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
                $status = (int)$m[1];
                break;
            }
        }
    }

    return [
        'status' => $status,
        'headers' => $responseHeaders ?: [],
        'body' => $result === false ? '' : $result,
        'error' => $result === false ? error_get_last() : null,
    ];
}

function survey_app_kintone_request(
    array $settings,
    string $path,
    string $method = 'GET',
    ?array $body = null
): array {
    $domain = survey_app_normalize_kintone_domain(
        (string)($settings['subdomain'] ?? '')
    );

    if ($domain === '') {
        return [
            'ok' => false,
            'message' => 'kintoneサブドメインが設定されていません。'
        ];
    }

    $appId = (string)($settings['app_id'] ?? '');

    if ($appId === '') {
        return [
            'ok' => false,
            'message' => 'kintoneアプリIDが設定されていません。'
        ];
    }

    $url = $domain . $path;

    if ($method === 'GET') {
        $separator = str_contains($url, '?') ? '&' : '?';
        $url .= $separator . 'app=' . rawurlencode($appId);
    }

    $login = (string)($settings['login_name'] ?? '');
    $password = survey_app_decrypt_secret(
        (string)($settings['password'] ?? '')
    );

    $auth = base64_encode($login . ':' . $password);

    $headers = [
        'Host: ' . parse_url($domain, PHP_URL_HOST),
        'X-Cybozu-Authorization: ' . $auth,
        'Accept: application/json',
    ];

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $response = survey_app_http_request(
        $url,
        $method,
        $headers,
        $body === null ? null : json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        !empty($settings['ssl_verify']),
        (string)($settings['proxy'] ?? '')
    );

    $decoded = json_decode($response['body'], true);

    $ok = $response['status'] >= 200 &&
        $response['status'] <= 299;

    if (!is_array($decoded)) {
        $decoded = [];
    }

    return [
        'ok' => $ok,
        'status' => $response['status'],
        'body' => $decoded,
        'raw' => $response['body'],
        'message' => $decoded['message'] ?? (
            $ok ? 'success' : 'kintone APIエラー'
        ),
    ];
}

function survey_app_fetch_kintone_fields(array $data): array
{
    $settings = survey_app_get_settings($data);

    $result = survey_app_kintone_request(
        $settings,
        '/k/v1/app/form/fields.json',
        'GET'
    );

    if (!$result['ok']) {
        return [
            'ok' => false,
            'message' => $result['message'],
            'status' => $result['status'] ?? 0,
            'errors' => $result['body']['errors'] ?? []
        ];
    }

    $fields = [];

    foreach (($result['body']['properties'] ?? []) as $field) {
        if (!is_array($field)) {
            continue;
        }

        $fields[] = [
            'label' => (string)($field['label'] ?? ''),
            'code' => (string)($field['code'] ?? ''),
            'type' => (string)($field['type'] ?? ''),
        ];
    }

    return [
        'ok' => true,
        'fields' => $fields,
        'properties' => $result['body']['properties'] ?? [],
    ];
}

function survey_app_sync_kintone_customers(array &$data): array
{
    $settings = survey_app_get_settings($data);

    $result = survey_app_kintone_request(
        $settings,
        '/k/v1/records.json',
        'GET'
    );

    if (!$result['ok']) {
        return [
            'ok' => false,
            'message' => $result['message'],
            'status' => $result['status'] ?? 0,
            'errors' => $result['body']['errors'] ?? []
        ];
    }

    $existingByKintoneId = [];

    foreach ($data['customers'] as $index => $customer) {
        if (!empty($customer['kintone_record_id'])) {
            $existingByKintoneId[(string)$customer['kintone_record_id']] = $index;
        }
    }

    foreach (($result['body']['records'] ?? []) as $record) {
        if (!is_array($record)) {
            continue;
        }

        $recordId = (string)($record['レコード番号']['value']
            ?? $record['record_number']['value']
            ?? '');

        if ($recordId === '') {
            continue;
        }

        $get = function (string $code) use ($record): string {
            return (string)($record[$code]['value'] ?? '');
        };

        $companyCode = (string)($settings['field_company'] ?? '');
        $nameCode = (string)($settings['field_name'] ?? '');
        $emailCode = (string)($settings['field_email'] ?? '');
        $departmentCode = (string)($settings['field_department'] ?? '');
        $phoneCode = (string)($settings['field_phone'] ?? '');
        $addressCode = (string)($settings['field_address'] ?? '');

        $customer = [
            'id' => 'kintone_' . $recordId,
            'kintone_record_id' => $recordId,
            'web_uuid' => '',
            'company' => $companyCode ? $get($companyCode) : '',
            'name' => $nameCode ? $get($nameCode) : '',
            'email' => $emailCode ? $get($emailCode) : '',
            'department' => $departmentCode ? $get($departmentCode) : '',
            'phone' => $phoneCode ? $get($phoneCode) : '',
            'address' => $addressCode ? $get($addressCode) : '',
            'source' => 'kintone',
            'sent_at' => '',
            'send_count' => 0,
            'answer_status' => 'unanswered',
            'kintone_status' => 'registered',
            'deleted' => false,
            'merged_to' => '',
        ];

        if (isset($existingByKintoneId[$recordId])) {
            $index = $existingByKintoneId[$recordId];

            $old = $data['customers'][$index];

            $customer['sent_at'] = $old['sent_at'] ?? '';
            $customer['send_count'] = (int)($old['send_count'] ?? 0);
            $customer['answer_status'] = $old['answer_status'] ?? 'unanswered';

            $data['customers'][$index] = array_merge(
                $old,
                $customer
            );
        } else {
            $data['customers'][] = $customer;
        }
    }

    return [
        'ok' => true,
        'count' => count($result['body']['records'] ?? [])
    ];
}

/* ------------------------------------------------------------
 * 顧客候補判定
 * ------------------------------------------------------------ */

function survey_app_normalize_match(string $value): string
{
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/\s+/u', '', $value) ?? $value;
    $value = str_replace(
        ['株式会社', '有限会社', '（株）', '(株)'],
        '',
        $value
    );

    return $value;
}

function survey_app_similarity_score(array $a, array $b): int
{
    $fields = [
        'company',
        'name',
        'email',
        'phone',
        'address'
    ];

    $score = 0;

    foreach ($fields as $field) {
        $x = survey_app_normalize_match((string)($a[$field] ?? ''));
        $y = survey_app_normalize_match((string)($b[$field] ?? ''));

        if ($x === '' || $y === '') {
            continue;
        }

        if ($x === $y) {
            $score += 20;
            continue;
        }

        similar_text($x, $y, $percent);

        if ($percent >= 85) {
            $score += 10;
        }
    }

    return $score;
}

function survey_app_customer_candidates(
    array $data,
    string $customerId
): array {
    $source = null;

    foreach ($data['customers'] as $customer) {
        if (($customer['id'] ?? '') === $customerId) {
            $source = $customer;
            break;
        }
    }

    if (!$source) {
        return [];
    }

    $result = [];

    foreach ($data['customers'] as $customer) {
        $id = (string)($customer['id'] ?? '');

        if ($id === $customerId || !empty($customer['deleted'])) {
            continue;
        }

        $score = survey_app_similarity_score($source, $customer);

        if ($score >= 20) {
            $result[] = [
                'customer_id' => $id,
                'score' => $score,
                'company' => $customer['company'] ?? '',
                'name' => $customer['name'] ?? '',
                'email' => $customer['email'] ?? '',
            ];
        }
    }

    usort(
        $result,
        static fn(array $a, array $b): int =>
            $b['score'] <=> $a['score']
    );

    return $result;
}

/* ------------------------------------------------------------
 * SMTP
 * ------------------------------------------------------------ */

function survey_app_smtp_read($socket): array
{
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket, 515);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (preg_match('/^\d{3} /', $line)) {
            break;
        }
    }

    $code = 0;

    if (preg_match('/^(\d{3})/m', $response, $m)) {
        $code = (int)$m[1];
    }

    return [
        'code' => $code,
        'response' => trim($response),
    ];
}

function survey_app_smtp_command(
    $socket,
    string $command,
    array $expected = []
): array {
    fwrite($socket, $command . "\r\n");

    $result = survey_app_smtp_read($socket);

    if ($expected !== [] && !in_array($result['code'], $expected, true)) {
        throw new RuntimeException(
            'SMTP応答エラー: ' . $result['response']
        );
    }

    return $result;
}

function survey_app_smtp_send(
    array $settings,
    string $to,
    string $subject,
    string $body
): array {
    $host = trim((string)($settings['smtp_server'] ?? ''));
    $port = (int)($settings['smtp_port'] ?? 587);
    $encryption = strtolower((string)($settings['smtp_encryption'] ?? 'tls'));
    $username = (string)($settings['smtp_username'] ?? '');
    $password = survey_app_decrypt_secret(
        (string)($settings['smtp_password'] ?? '')
    );

    if ($host === '' || $to === '') {
        return [
            'ok' => false,
            'message' => 'SMTPサーバまたは宛先が未設定です。'
        ];
    }

    $timeout = (int)($settings['smtp_timeout'] ?? 20);

    $connectHost = $encryption === 'ssl'
        ? 'ssl://' . $host
        : $host;

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        'tcp://' . $connectHost . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        return [
            'ok' => false,
            'message' => 'SMTP TCP接続失敗',
            'error' => $errstr,
            'code' => $errno,
        ];
    }

    stream_set_timeout($socket, $timeout);

    try {
        $greeting = survey_app_smtp_read($socket);

        if ($greeting['code'] < 200 || $greeting['code'] >= 400) {
            throw new RuntimeException($greeting['response']);
        }

        survey_app_smtp_command(
            $socket,
            'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'),
            [250]
        );

        if ($encryption === 'tls') {
            survey_app_smtp_command($socket, 'STARTTLS', [220]);

            $crypto = stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException('TLS確立に失敗しました。');
            }

            survey_app_smtp_command(
                $socket,
                'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'),
                [250]
            );
        }

        if ($username !== '') {
            survey_app_smtp_command(
                $socket,
                'AUTH LOGIN',
                [334]
            );

            survey_app_smtp_command(
                $socket,
                base64_encode($username),
                [334]
            );

            survey_app_smtp_command(
                $socket,
                base64_encode($password),
                [235]
            );
        }

        $from = (string)(
            $settings['smtp_from_email'] ?? $username
        );

        $fromName = (string)(
            $settings['smtp_from_name'] ?? 'アンケート管理システム'
        );

        survey_app_smtp_command(
            $socket,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        survey_app_smtp_command(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        survey_app_smtp_command(
            $socket,
            'DATA',
            [354]
        );

        $encodedSubject = '=?UTF-8?B?' .
            base64_encode($subject) .
            '?=';

        $encodedFromName = '=?UTF-8?B?' .
            base64_encode($fromName) .
            '?=';

        $headers = [
            'From: ' . $encodedFromName . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Date: ' . date(DATE_RFC2822),
        ];

        $mail = implode("\r\n", $headers)
            . "\r\n\r\n"
            . str_replace("\r\n.", "\r\n..", $body)
            . "\r\n.";

        fwrite($socket, $mail . "\r\n");

        $result = survey_app_smtp_read($socket);

        survey_app_smtp_command($socket, 'QUIT', [221]);

        fclose($socket);

        if ($result['code'] < 200 || $result['code'] >= 400) {
            throw new RuntimeException($result['response']);
        }

        return [
            'ok' => true,
            'smtp_response' => $result['response'],
            'smtp_code' => $result['code'],
        ];
    } catch (Throwable $e) {
        @fclose($socket);

        return [
            'ok' => false,
            'message' => $e->getMessage(),
        ];
    }
}

/* ------------------------------------------------------------
 * CSV
 * ------------------------------------------------------------ */

function survey_app_csv_value(string $value): string
{
    if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
        $value = "'" . $value;
    }

    return $value;
}

function survey_app_csv_response(array $data, string $surveyId): never
{
    survey_app_require_admin();

    $survey = survey_app_find_survey($data, $surveyId);

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

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey_' .
        rawurlencode($surveyId) .
        '.csv"'
    );

    echo "\xEF\xBB\xBF";

    $fp = fopen('php://output', 'wb');

    $header = [
        '回答ID',
        '回答日時',
        '顧客ID',
        '会社名',
        '氏名'
    ];

    foreach ($questions as $question) {
        $header[] = $question['number'] . ' ' . $question['text'];
    }

    fputcsv($fp, $header);

    foreach ($data['responses'] as $response) {
        if (
            ($response['survey_id'] ?? '') !== $surveyId ||
            !empty($response['deleted'])
        ) {
            continue;
        }

        $row = [
            $response['id'] ?? '',
            $response['answered_at'] ?? '',
            $response['customer_id'] ?? '',
            $response['company'] ?? '',
            $response['name'] ?? '',
        ];

        foreach ($questions as $question) {
            $answer = $response['answers'][$question['id']] ?? '';

            if (is_array($answer)) {
                $answer = implode('、', $answer);
            }

            $row[] = survey_app_csv_value((string)$answer);
        }

        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}

/* ------------------------------------------------------------
 * 回答URL
 * ------------------------------------------------------------ */

function survey_app_get_survey_by_public_token(
    array $data,
    string $token
): ?array {
    foreach ($data['surveys'] as $survey) {
        if (
            !empty($survey['public_token']) &&
            hash_equals((string)$survey['public_token'], $token) &&
            empty($survey['deleted'])
        ) {
            return $survey;
        }
    }

    return null;
}

function survey_app_get_token_record(
    array $data,
    string $token
): ?array {
    foreach ($data['response_tokens'] as $record) {
        if (
            !empty($record['token']) &&
            hash_equals((string)$record['token'], $token)
        ) {
            return $record;
        }
    }

    return null;
}

function survey_app_survey_available(array $survey): bool
{
    if (!empty($survey['deleted'])) {
        return false;
    }

    if (($survey['status'] ?? '') !== 'active') {
        return false;
    }

    $now = time();

    if (!empty($survey['start_at'])) {
        $start = strtotime($survey['start_at']);

        if ($start !== false && $now < $start) {
            return false;
        }
    }

    if (!empty($survey['end_at'])) {
        $end = strtotime($survey['end_at']);

        if ($end !== false && $now > $end) {
            return false;
        }
    }

    return true;
}

/* ------------------------------------------------------------
 * API
 * ------------------------------------------------------------ */

function survey_app_api(): never
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = (string)($_REQUEST['action'] ?? '');

    $data = survey_app_read_storage();

    if ($action === 'login' && $method === 'POST') {
        survey_app_login();
    }

    if ($action === 'logout') {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        survey_app_json_response([
            'ok' => true
        ]);
    }

    /*
     * 回答者API
     * 管理者CSRFとは分離
     */

    if ($action === 'general_start' && $method === 'POST') {
        $token = (string)($_POST['public_token'] ?? '');

        $survey = survey_app_get_survey_by_public_token(
            $data,
            $token
        );

        if (!$survey || !$survey['general_response_enabled']) {
            survey_app_json_response([
                'ok' => false,
                'message' => '一般回答を受け付けていません。'
            ], 403);
        }

        if (!survey_app_survey_available($survey)) {
            survey_app_json_response([
                'ok' => false,
                'message' => '現在回答を受け付けていません。'
            ], 403);
        }

        $company = trim((string)($_POST['company'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));

        if ($company === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            survey_app_json_response([
                'ok' => false,
                'message' => '会社名・氏名・有効なメールアドレスは必須です。'
            ], 422);
        }

        $customerId = survey_app_uuid('web_');

        $customer = [
            'id' => $customerId,
            'web_uuid' => $customerId,
            'company' => $company,
            'name' => $name,
            'email' => $email,
            'department' => trim((string)($_POST['department'] ?? '')),
            'phone' => trim((string)($_POST['phone'] ?? '')),
            'address' => trim((string)($_POST['address'] ?? '')),
            'source' => 'web',
            'sent_at' => '',
            'send_count' => 0,
            'answer_status' => 'unanswered',
            'kintone_status' => 'unregistered',
            'deleted' => false,
            'merged_to' => '',
            'created_at' => survey_app_now(),
        ];

        $data['customers'][] = $customer;

        $responseSession = survey_app_token(32);

        $data['response_tokens'][] = [
            'id' => survey_app_uuid('session_'),
            'token' => $responseSession,
            'survey_id' => $survey['id'],
            'customer_id' => $customerId,
            'type' => 'general',
            'created_at' => survey_app_now(),
            'used' => false,
        ];

        survey_app_write_storage($data);

        survey_app_json_response([
            'ok' => true,
            'response_session' => $responseSession,
            'survey' => $survey,
        ]);
    }

    if ($action === 'load_individual' && $method === 'GET') {
        $token = (string)($_GET['response_token'] ?? '');

        $record = survey_app_get_token_record($data, $token);

        if (!$record || ($record['type'] ?? '') !== 'individual') {
            survey_app_json_response([
                'ok' => false,
                'message' => '回答URLが無効です。'
            ], 404);
        }

        $survey = survey_app_find_survey(
            $data,
            (string)$record['survey_id']
        );

        if (!$survey || !survey_app_survey_available($survey)) {
            survey_app_json_response([
                'ok' => false,
                'message' => '現在回答を受け付けていません。'
            ], 403);
        }

        $customerId = (string)$record['customer_id'];

        $responses = [];

        foreach ($data['responses'] as $response) {
            if (
                ($response['survey_id'] ?? '') === $survey['id'] &&
                ($response['customer_id'] ?? '') === $customerId &&
                empty($response['deleted'])
            ) {
                $responses[] = $response;
            }
        }

        usort(
            $responses,
            static fn(array $a, array $b): int =>
                strcmp(
                    (string)($b['answered_at'] ?? ''),
                    (string)($a['answered_at'] ?? '')
                )
        );

        survey_app_json_response([
            'ok' => true,
            'survey' => $survey,
            'answered' => count($responses) > 0,
            'previous_response' => $responses[0] ?? null,
        ]);
    }

    if ($action === 'load_general_session' && $method === 'GET') {
        $token = (string)($_GET['response_session'] ?? '');

        $record = survey_app_get_token_record($data, $token);

        if (!$record || ($record['type'] ?? '') !== 'general') {
            survey_app_json_response([
                'ok' => false,
                'message' => '回答セッションが無効です。'
            ], 404);
        }

        $survey = survey_app_find_survey(
            $data,
            (string)$record['survey_id']
        );

        if (!$survey || !survey_app_survey_available($survey)) {
            survey_app_json_response([
                'ok' => false,
                'message' => '現在回答を受け付けていません。'
            ], 403);
        }

        survey_app_json_response([
            'ok' => true,
            'survey' => $survey,
        ]);
    }

    if ($action === 'submit_response' && $method === 'POST') {
        $token = (string)(
            $_POST['response_token'] ??
            $_POST['response_session'] ??
            ''
        );

        $record = survey_app_get_token_record($data, $token);

        if (!$record) {
            survey_app_json_response([
                'ok' => false,
                'message' => '回答セッションが無効です。'
            ], 403);
        }

        $survey = survey_app_find_survey(
            $data,
            (string)$record['survey_id']
        );

        if (!$survey || !survey_app_survey_available($survey)) {
            survey_app_json_response([
                'ok' => false,
                'message' => 'アンケートの受付期間外です。'
            ], 403);
        }

        $responseDataRaw = (string)($_POST['response_data'] ?? '');
        $responseData = json_decode($responseDataRaw, true);

        if (!is_array($responseData)) {
            survey_app_json_response([
                'ok' => false,
                'message' => '回答データが不正です。'
            ], 422);
        }

        $customer = null;

        foreach ($data['customers'] as $candidate) {
            if (($candidate['id'] ?? '') === ($record['customer_id'] ?? '')) {
                $customer = $candidate;
                break;
            }
        }

        if (!$customer) {
            survey_app_json_response([
                'ok' => false,
                'message' => '回答者情報を確認できません。'
            ], 404);
        }

        /*
         * 必須チェック
         */
        $missing = [];

        $answerMap = $responseData['answers'] ?? [];

        $visibleQuestionIds = [];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                $visibleQuestionIds[$question['id']] = true;
            }
        }

        /*
         * 分岐判定
         */
        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                if (
                    !empty($question['branching']['depends_on']) &&
                    !empty($question['branching']['show_if'])
                ) {
                    $dependsOn = (string)$question['branching']['depends_on'];
                    $showIf = $question['branching']['show_if'];

                    $parentAnswer = $answerMap[$dependsOn] ?? null;

                    $matches = false;

                    if (is_array($parentAnswer)) {
                        $matches = in_array(
                            $showIf,
                            $parentAnswer,
                            true
                        );
                    } else {
                        $matches = (string)$parentAnswer === (string)$showIf;
                    }

                    if (!$matches) {
                        unset($visibleQuestionIds[$question['id']]);
                    }
                }
            }
        }

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                if (
                    !empty($question['required']) &&
                    isset($visibleQuestionIds[$question['id']])
                ) {
                    $answer = $answerMap[$question['id']] ?? '';

                    $empty = false;

                    if (is_array($answer)) {
                        $empty = count($answer) === 0;
                    } else {
                        $empty = trim((string)$answer) === '';
                    }

                    if ($empty) {
                        $missing[] = $question['id'];
                    }
                }
            }
        }

        if ($missing !== []) {
            survey_app_json_response([
                'ok' => false,
                'message' => '必須項目が未回答です。',
                'missing' => $missing,
            ], 422);
        }

        $answeredAt = survey_app_now();

        $response = [
            'id' => survey_app_uuid('response_'),
            'survey_id' => $survey['id'],
            'customer_id' => $customer['id'],
            'company' => $customer['company'] ?? '',
            'name' => $customer['name'] ?? '',
            'email' => $customer['email'] ?? '',
            'answered_at' => $answeredAt,
            'answers' => $answerMap,
            'deleted' => false,
            'source' => $record['type'] === 'general'
                ? 'web'
                : 'individual',
        ];

        $data['responses'][] = $response;

        /*
         * 一般回答は未登録のまま。
         * 個別回答は回答済みに変更。
         */
        if ($record['type'] === 'general') {
            foreach ($data['customers'] as &$candidate) {
                if (($candidate['id'] ?? '') === $customer['id']) {
                    $candidate['kintone_status'] = 'unregistered';
                    $candidate['answer_status'] = 'answered';
                    break;
                }
            }

            unset($candidate);
        } else {
            foreach ($data['customers'] as &$candidate) {
                if (($candidate['id'] ?? '') === $customer['id']) {
                    $candidate['answer_status'] = 'answered';
                    break;
                }
            }

            unset($candidate);
        }

        survey_app_write_storage($data);

        survey_app_json_response([
            'ok' => true,
            'response_id' => $response['id'],
            'answered_at' => $answeredAt,
        ]);
    }

    /*
     * ここから管理者API
     */
    survey_app_require_admin();

    if ($method === 'POST') {
        survey_app_verify_csrf();
    }

    if ($action === 'bootstrap') {
        $publicData = $data;

        foreach ($publicData['settings'] as $key => $value) {
            if (str_contains((string)$key, 'password')) {
                $publicData['settings'][$key] = '';
            }
        }

        survey_app_json_response([
            'ok' => true,
            'data' => $publicData,
            'csrf_token' => survey_app_csrf_token(),
            'admin_user' => $_SESSION['survey_admin_user'] ?? '',
        ]);
    }

    if ($action === 'save_survey' && $method === 'POST') {
        $raw = (string)($_POST['survey_json'] ?? '');
        $survey = json_decode($raw, true);

        if (!is_array($survey)) {
            survey_app_json_response([
                'ok' => false,
                'message' => 'アンケートJSONが不正です。'
            ], 422);
        }

        $survey = survey_app_normalize_survey($survey);

        if ($survey['title'] === '') {
            survey_app_json_response([
                'ok' => false,
                'message' => 'タイトルを入力してください。'
            ], 422);
        }

        survey_app_renumber($survey);

        $found = false;

        foreach ($data['surveys'] as $index => $oldSurvey) {
            if (($oldSurvey['id'] ?? '') === $survey['id']) {
                $survey['created_at'] =
                    $oldSurvey['created_at'] ?? survey_app_now();

                $data['surveys'][$index] = $survey;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $data['surveys'][] = $survey;
        }

        survey_app_write_storage($data);

        survey_app_json_response([
            'ok' => true,
            'survey' => $survey,
        ]);
    }

    if ($action === 'delete_survey' && $method === 'POST') {
        $surveyId = (string)($_POST['survey_id'] ?? '');

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $surveyId) {
                $survey['deleted'] = true;
                $survey['updated_at'] = survey_app_now();
            }
        }

        unset($survey);

        survey_app_write_storage($data);

        survey_app_json_response([
            'ok' => true
        ]);
    }

    if ($action === 'stop_survey' && $method === 'POST') {
        $surveyId = (string)($_POST['survey_id'] ?? '');

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $surveyId) {
                $survey['status'] = 'ended';
                $survey['updated_at'] = survey_app_now();
            }
        }

        unset($survey);

        survey_app_write_storage($data);

        survey_app_json_response([
            'ok' => true
        ]);
    }

    if ($action === 'resume_survey' && $method === 'POST') {
        $surveyId = (string)($_POST['survey_id'] ?? '');

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $surveyId) {
                $survey['status'] = 'active';
                $survey['updated_at'] = survey_app_now();
            }
        }

        unset($survey);

        survey_app_write_storage($data);

        survey_app_json_response([
            'ok' => true
        ]);
    }

    if ($action === 'duplicate_survey' && $method === 'POST') {
        $surveyId = (string)($_POST['survey_id'] ?? '');

        $source = survey_app_find_survey($data, $surveyId);

        if (!$source) {
            survey_app_json_response([
                'ok' => false,
                'message' => 'アンケートがありません。'
            ], 404);
        }

        $copy = $source;

        $copy['id'] = survey_app_uuid('survey_');
        $copy['title'] = $source['title'] . '（複製）';
        $copy['status'] = 'draft';
        $copy['created_at'] = survey_app_now();
        $copy['updated_at'] = survey_app_now();
        $copy['public_token'] = survey_app_token(32);
        $copy['deleted'] = false;

        /*
         * IDは全て再発行
         */
        foreach ($copy['groups'] as &$group) {
            $group['id'] = survey_app_uuid('group_');

            foreach ($group['questions'] as &$question) {
                $question['id'] = survey_app_uuid('question_');

                foreach ($question['options'] as &$option) {
                    $option['id'] = survey_app_uuid('opt_');
                }

                unset($option);
            }

            unset($question);
        }

        unset($group);

        survey_app_renumber($copy);

        $data['surveys'][] = $copy;

        survey_app_write_storage($data);

        survey_app_json_response([
            'ok' => true,
            'survey' => $copy,
        ]);
    }

    if ($action === 'save_settings' && $method === 'POST') {
        $raw = (string)($_POST['settings_json'] ?? '');
        $settings = json_decode($raw, true);

        if (!is_array($settings)) {
            survey_app_json_response([
                'ok' => false,
                'message' => '設定JSONが不正です。'
            ], 422);
        }

        foreach ([
            'password',
            'smtp_password'
        ] as $secretKey) {
            if (
                array_key_exists($secretKey, $settings) &&
                trim((string)$settings[$secretKey]) !== ''
            ) {
                $settings[$secretKey] = survey_app_encrypt_secret(
                    (string)$settings[$secretKey]
                );
            } else {
                $old = $data['settings'][$secretKey] ?? '';

                if ($old !== '') {
                    $settings[$secretKey] = $old;
                }
            }
        }

        $data['settings'] = array_merge(
            $data['settings'],
            $settings
        );

        survey_app_write_storage($data);

        survey_app_json_response([
            'ok' => true,
            'message' => '設定を保存しました。'
        ]);
    }

    if ($action === 'fetch_kintone_fields') {
        survey_app_json_response(
            survey_app_fetch_kintone_fields($data)
        );
    }

    if ($action === 'sync_customers' && $method === 'POST') {
        $result = survey_app_sync_kintone_customers($data);

        if ($result['ok']) {
            survey_app_write_storage($data);
        }

        survey_app_json_response($result);
    }

    if ($action === 'kintone_connection_test') {
        $settings = survey_app_get_settings($data);

        $result = survey_app_kintone_request(
            $settings,
            '/k/v1/app.json',
            'GET'
        );

        survey_app_json_response([
            'ok' => $result['ok'],
            'status' => $result['status'] ?? 0,
            'message' => $result['message'] ?? '',
            'errors' => $result['body']['errors'] ?? [],
        ]);
    }

    if ($action === 'smtp_test' && $method === 'POST') {
        $settings = survey_app_get_settings($data);

        $to = trim((string)($_POST['smtp_test_to'] ?? ''));

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            survey_app_json_response([
                'ok' => false,
                'message' => 'テスト送信先メールアドレスが不正です。'
            ], 422);
        }

        $result = survey_app_smtp_send(
            $settings,
            $to,
            'アンケート管理システム SMTP送信テスト',
            'アンケート管理システムからのSMTPテスト送信です。'
        );

        survey_app_json_response($result);
    }

    if ($action === 'send_mail' && $method === 'POST') {
        $surveyId = (string)($_POST['survey_id'] ?? '');
        $recipientIdsRaw = (string)($_POST['recipient_ids'] ?? '');
        $recipientIds = json_decode($recipientIdsRaw, true);

        if (!is_array($recipientIds)) {
            survey_app_json_response([
                'ok' => false,
                'message' => '送信対象が不正です。'
            ], 422);
        }

        $survey = survey_app_find_survey($data, $surveyId);

        if (!$survey) {
            survey_app_json_response([
                'ok' => false,
                'message' => 'アンケートがありません。'
            ], 404);
        }

        $settings = survey_app_get_settings($data);
        $subject = (string)($_POST['mail_subject'] ?? '');
        $templateType = (string)($_POST['template_type'] ?? 'initial');
        $bodyTemplate = (string)($_POST['mail_body'] ?? '');

        if (!in_array($templateType, ['initial', 'reminder'], true)) {
            $templateType = 'initial';
        }

        $success = 0;
        $failure = 0;
        $results = [];

        $mailLog = [
            'id' => survey_app_uuid('mail_log_'),
            'sent_at' => survey_app_now(),
            'type' => $templateType,
            'send_type' => $templateType,
            'survey_id' => $surveyId,
            'target_count' => count($recipientIds),
            'success_count' => 0,
            'failure_count' => 0,
            'subject' => $subject,
            'executor' => $_SESSION['survey_admin_user'] ?? '',
            'recipients' => [],
        ];

        foreach ($recipientIds as $customerId) {
            $customerIndex = null;
            $customer = null;

            foreach ($data['customers'] as $index => $candidate) {
                if (($candidate['id'] ?? '') === (string)$customerId) {
                    $customerIndex = $index;
                    $customer = $candidate;
                    break;
                }
            }

            if ($customerIndex === null || !$customer) {
                $failure++;

                $results[] = [
                    'customer_id' => $customerId,
                    'ok' => false,
                    'message' => '顧客が見つかりません。'
                ];

                continue;
            }

            $token = survey_app_token(32);

            $data['response_tokens'][] = [
                'id' => survey_app_uuid('response_token_'),
                'token' => $token,
                'survey_id' => $surveyId,
                'customer_id' => $customer['id'],
                'type' => 'individual',
                'created_at' => survey_app_now(),
                'used' => false,
            ];

            $scheme = (
                !empty($_SERVER['HTTPS']) &&
                $_SERVER['HTTPS'] !== 'off'
            )
                ? 'https'
                : 'http';

            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

            $url = $scheme .
                '://' .
                $host .
                strtok($_SERVER['REQUEST_URI'] ?? '/index.php', '?') .
                '?response_token=' .
                rawurlencode($token);

            $body = str_replace(
                [
                    '{顧客名}',
                    '{アンケートURL}'
                ],
                [
                    (string)($customer['name'] ?? ''),
                    $url
                ],
                $bodyTemplate
            );

            $result = survey_app_smtp_send(
                $settings,
                (string)$customer['email'],
                $subject,
                $body
            );

            if ($result['ok']) {
                $success++;

                $data['customers'][$customerIndex]['sent_at'] =
                    survey_app_now();

                $data['customers'][$customerIndex]['send_count'] =
                    (int)($customer['send_count'] ?? 0) + 1;

                $data['customers'][$customerIndex]['answer_status'] =
                    'unanswered';

                $data['customers'][$customerIndex]['last_send_result'] =
                    'success';

                $data['customers'][$customerIndex]['last_sent_body'] =
                    $body;

                $data['customers'][$customerIndex]['last_send_subject'] =
                    $subject;

                $mailLog['recipients'][] = [
                    'customer_id' => $customer['id'],
                    'email' => $customer['email'],
                    'status' => 'sent',
                    'sent_at' => survey_app_now(),
                    'body' => $body,
                ];

                $results[] = [
                    'customer_id' => $customer['id'],
                    'ok' => true,
                ];
            } else {
                $failure++;

                $data['customers'][$customerIndex]['last_send_result'] =
                    'failure';

                $data['customers'][$customerIndex]['last_send_error'] =
                    $result['message'] ?? 'SMTPエラー';

                $mailLog['recipients'][] = [
                    'customer_id' => $customer['id'],
                    'email' => $customer['email'],
                    'status' => 'failure',
                    'error' => $result['message'] ?? 'SMTPエラー',
                ];

                $results[] = [
                    'customer_id' => $customer['id'],
                    'ok' => false,
                    'message' => $result['message'] ?? 'SMTPエラー'
                ];
            }
        }

        $mailLog['success_count'] = $success;
        $mailLog['failure_count'] = $failure;

        $data['mail_logs'][] = $mailLog;

        survey_app_write_storage($data);

        survey_app_json_response([
            'ok' => true,
            'success_count' => $success,
            'failure_count' => $failure,
            'results' => $results,
        ]);
    }

    if ($action === 'merge_customer' && $method === 'POST') {
        $sourceId = (string)($_POST['source_customer_id'] ?? '');
        $targetId = (string)($_POST['target_customer_id'] ?? '');

        if ($sourceId === $targetId || $sourceId === '' || $targetId === '') {
            survey_app_json_response([
                'ok' => false,
                'message' => '統合対象が不正です。'
            ], 422);
        }

        $sourceExists = false;
        $targetExists = false;

        foreach ($data['customers'] as &$customer) {
            if (($customer['id'] ?? '') === $sourceId) {
                $customer['merged_to'] = $targetId;
                $customer['deleted'] = true;
                $sourceExists = true;
            }

            if (($customer['id'] ?? '') === $targetId) {
                $targetExists = true;
            }
        }

        unset($customer);

        if (!$sourceExists || !$targetExists) {
            survey_app_json_response([
                'ok' => false,
                'message' => '顧客が見つかりません。'
            ], 404);
        }

        foreach ($data['responses'] as &$response) {
            if (($response['customer_id'] ?? '') === $sourceId) {
                $response['customer_id'] = $targetId;
            }
        }

        unset($response);

        survey_app_write_storage($data);

        survey_app_json_response([
            'ok' => true
        ]);
    }

    if ($action === 'delete_response' && $method === 'POST') {
        $responseId = (string)($_POST['response_id'] ?? '');

        foreach ($data['responses'] as &$response) {
            if (($response['id'] ?? '') === $responseId) {
                $response['deleted'] = true;
            }
        }

        unset($response);

        survey_app_write_storage($data);

        survey_app_json_response([
            'ok' => true
        ]);
    }

    if ($action === 'survey_data') {
        $surveyId = (string)($_GET['survey_id'] ?? '');

        $survey = survey_app_find_survey($data, $surveyId);

        if (!$survey) {
            survey_app_json_response([
                'ok' => false,
                'message' => 'アンケートがありません。'
            ], 404);
        }

        $responses = [];

        foreach ($data['responses'] as $response) {
            if (
                ($response['survey_id'] ?? '') === $surveyId &&
                empty($response['deleted'])
            ) {
                $responses[] = $response;
            }
        }

        $selectedQuestions = [];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                $selectedQuestions[] = [
                    'id' => $question['id'],
                    'number' => $question['number'] ?? '',
                    'text' => $question['text'],
                    'type' => $question['type'],
                    'options' => $question['options'],
                ];
            }
        }

        $summary = [
            'response_count' => count($responses),
            'general_response_count' => 0,
            'unregistered_response_count' => 0,
            'sent_target_count' => 0,
            'unanswered_count' => 0,
            'answer_rate' => 0,
        ];

        $sentCustomerIds = [];

        foreach ($data['customers'] as $customer) {
            if (
                !empty($customer['sent_at']) &&
                empty($customer['deleted'])
            ) {
                $sentCustomerIds[] = $customer['id'];
            }
        }

        $summary['sent_target_count'] = count($sentCustomerIds);

        $answeredSent = 0;

        foreach ($responses as $response) {
            $customerId = $response['customer_id'] ?? '';

            $isGeneral = false;

            foreach ($data['customers'] as $customer) {
                if (($customer['id'] ?? '') === $customerId) {
                    $isGeneral =
                        ($customer['source'] ?? '') === 'web' &&
                        ($customer['kintone_status'] ?? '') === 'unregistered';

                    break;
                }
            }

            if ($isGeneral) {
                $summary['general_response_count']++;
                $summary['unregistered_response_count']++;
            }

            if (in_array($customerId, $sentCustomerIds, true)) {
                $answeredSent++;
            }
        }

        $summary['unanswered_count'] = max(
            0,
            $summary['sent_target_count'] - $answeredSent
        );

        if ($summary['sent_target_count'] > 0) {
            $summary['answer_rate'] = round(
                $answeredSent /
                $summary['sent_target_count'] *
                100,
                2
            );
        }

        survey_app_json_response([
            'ok' => true,
            'survey' => $survey,
            'responses' => $responses,
            'questions' => $selectedQuestions,
            'summary' => $summary,
            'customers' => $data['customers'],
        ]);
    }

    if ($action === 'csv') {
        survey_app_csv_response(
            $data,
            (string)($_GET['survey_id'] ?? '')
        );
    }

    survey_app_json_response([
        'ok' => false,
        'message' => '未知のAPIアクションです。'
    ], 404);
}

/* ------------------------------------------------------------
 * 回答者画面
 * ------------------------------------------------------------ */

function survey_app_is_respondent_request(): bool
{
    return
        isset($_GET['response_token']) ||
        isset($_GET['public_token']) ||
        isset($_GET['response_session']);
}

function survey_app_respondent_html(): never
{
    $csrf = survey_app_csrf_token();

    header('Content-Type: text/html; charset=UTF-8');

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート回答</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900">
<div id="app"></div>

<script>
window.App = {
    state: {
        mode: 'respondent',
        step: 'loading',
        survey: null,
        responseToken: new URLSearchParams(location.search).get('response_token') || '',
        publicToken: new URLSearchParams(location.search).get('public_token') || '',
        responseSession: new URLSearchParams(location.search).get('response_session') || '',
        customer: null,
        answers: {},
        previousResponse: null,
        submitting: false
    },

    render: {},

    actions: {},

    api: {},

    utils: {}
};

App.utils.escape = function(value) {
    const div = document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
};

App.utils.storageKey = function() {
    const s = App.state;

    return [
        'survey_response_draft',
        s.survey ? s.survey.id : 'unknown',
        s.responseToken || s.responseSession || s.publicToken
    ].join(':');
};

App.utils.saveDraft = function() {
    try {
        localStorage.setItem(
            App.utils.storageKey(),
            JSON.stringify({
                survey_id: App.state.survey?.id || '',
                answers: App.state.answers
            })
        );
    } catch (e) {}
};

App.actions.restoreDraftResponse = function() {
    try {
        const raw = localStorage.getItem(
            App.utils.storageKey()
        );

        if (!raw) {
            return;
        }

        const data = JSON.parse(raw);

        if (
            data &&
            data.survey_id === App.state.survey?.id &&
            data.answers
        ) {
            App.state.answers = data.answers;
        }
    } catch (e) {}
};

App.actions.clearDraftResponse = function() {
    try {
        localStorage.removeItem(
            App.utils.storageKey()
        );
    } catch (e) {}
};

App.api.get = async function(action, params = {}) {
    const query = new URLSearchParams({
        action,
        ...params
    });

    const response = await fetch(
        location.pathname + '?' + query.toString(),
        {
            credentials: 'same-origin'
        }
    );

    return response.json();
};

App.api.post = async function(action, data = {}) {
    const body = new URLSearchParams({
        action,
        ...data
    });

    const response = await fetch(
        location.pathname,
        {
            method: 'POST',
            headers: {
                'Content-Type':
                    'application/x-www-form-urlencoded'
            },
            body,
            credentials: 'same-origin'
        }
    );

    return response.json();
};

App.actions.startGeneralResponse = function() {
    App.state.step = 'respondent_info';
    App.render();
};

App.actions.startIndividualResponse = function() {
    App.actions.restoreDraftResponse();

    if (App.state.previousResponse) {
        App.state.step = 'already_answered';
    } else {
        App.state.step = 'answer';
    }

    App.render();
};

App.actions.startAnswer = function() {
    App.actions.restoreDraftResponse();
    App.state.step = 'answer';
    App.render();
};

App.actions.startResurvey = function() {
    App.state.answers = {
        ...(App.state.previousResponse?.answers || {})
    };

    App.actions.restoreDraftResponse();

    App.state.step = 'answer';
    App.render();
};

App.actions.startGeneral = async function(form) {
    const data = new FormData(form);

    const result = await App.api.post(
        'general_start',
        Object.fromEntries(data.entries())
    );

    if (!result.ok) {
        alert(result.message || '回答を開始できません。');
        return;
    }

    App.state.responseSession =
        result.response_session;

    App.state.survey = result.survey;

    history.replaceState(
        {},
        '',
        location.pathname +
        '?response_session=' +
        encodeURIComponent(
            App.state.responseSession
        )
    );

    App.state.step = 'answer';

    App.actions.restoreDraftResponse();
    App.render();
};

App.actions.setAnswer = function(questionId, value) {
    App.state.answers[questionId] = value;
    App.utils.saveDraft();
};

App.actions.isQuestionVisible = function(question) {
    const branching = question.branching || {};

    if (
        !branching.depends_on ||
        branching.show_if === undefined ||
        branching.show_if === null
    ) {
        return true;
    }

    const parent =
        App.state.answers[branching.depends_on];

    if (Array.isArray(parent)) {
        return parent.includes(
            String(branching.show_if)
        );
    }

    return String(parent ?? '') ===
        String(branching.show_if);
};

App.actions.validateResponse = function() {
    const missing = [];

    for (const group of App.state.survey.groups) {
        for (const question of group.questions) {
            if (
                !question.required ||
                !App.actions.isQuestionVisible(question)
            ) {
                continue;
            }

            const value =
                App.state.answers[question.id];

            if (
                value === undefined ||
                value === null ||
                value === '' ||
                (Array.isArray(value) && value.length === 0)
            ) {
                missing.push(question.id);
            }
        }
    }

    if (missing.length > 0) {
        App.state.validationErrors = missing;

        App.render();

        const target =
            document.getElementById(
                'question_' + missing[0]
            );

        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }

        return false;
    }

    App.state.validationErrors = [];
    return true;
};

App.actions.confirmResponse = function() {
    if (!App.actions.validateResponse()) {
        return;
    }

    App.state.step = 'confirm';
    App.render();
};

App.actions.submitResponse = async function() {
    if (App.state.submitting) {
        return;
    }

    App.state.submitting = true;
    App.render();

    const token =
        App.state.responseToken ||
        App.state.responseSession;

    const result = await App.api.post(
        'submit_response',
        {
            response_token:
                App.state.responseToken,
            response_session:
                App.state.responseSession,
            response_data: JSON.stringify({
                answers: App.state.answers
            })
        }
    );

    if (!result.ok) {
        App.state.submitting = false;
        alert(result.message || '送信に失敗しました。');
        App.render();
        return;
    }

    App.actions.clearDraftResponse();

    App.state.submitting = false;
    App.state.answeredAt = result.answered_at;
    App.state.step = 'complete';

    App.render();
};

App.render.respondentInfo = function() {
    return `
        <div class="max-w-xl mx-auto py-8 px-4">
            <div class="bg-white rounded-2xl shadow-sm border p-6">
                <h1 class="text-xl font-bold mb-2">
                    回答者情報
                </h1>

                <p class="text-sm text-gray-500 mb-6">
                    回答を開始するため、以下を入力してください。
                </p>

                <form
                    id="respondent_form"
                    onsubmit="event.preventDefault(); App.actions.startGeneral(this)"
                    class="space-y-4"
                >
                    ${App.render.input(
                        'respondent_company',
                        '会社名',
                        true
                    )}

                    ${App.render.input(
                        'respondent_name',
                        '氏名',
                        true
                    )}

                    ${App.render.input(
                        'respondent_email',
                        'メールアドレス',
                        true,
                        'email'
                    )}

                    ${App.render.input(
                        'respondent_department',
                        '部署名',
                        false
                    )}

                    ${App.render.input(
                        'respondent_phone',
                        '電話番号',
                        false
                    )}

                    <div>
                        <label class="block text-sm font-medium mb-1">
                            住所
                        </label>
                        <textarea
                            id="respondent_address"
                            name="address"
                            rows="3"
                            class="w-full rounded-lg border px-3 py-2"
                        ></textarea>
                    </div>

                    <button
                        type="submit"
                        class="w-full rounded-lg bg-blue-600 text-white py-3 font-medium"
                    >
                        回答を開始
                    </button>
                </form>
            </div>
        </div>
    `;
};

App.render.input = function(id, label, required, type = 'text') {
    return `
        <div>
            <label
                for="${id}"
                class="block text-sm font-medium mb-1"
            >
                ${App.utils.escape(label)}
                ${required
                    ? '<span class="text-red-500"> *</span>'
                    : ''}
            </label>

            <input
                id="${id}"
                name="${id.replace('respondent_', '')}"
                type="${type}"
                ${required ? 'required' : ''}
                class="w-full rounded-lg border px-3 py-2"
            >
        </div>
    `;
};

App.render.answer = function() {
    const survey = App.state.survey;

    let html = `
        <div class="max-w-3xl mx-auto py-6 px-4">
            <div class="bg-white rounded-2xl border shadow-sm p-6">
                <h1 class="text-2xl font-bold">
                    ${App.utils.escape(survey.title)}
                </h1>

                <p class="text-sm text-gray-500 mt-2">
                    必須項目には * が付いています。
                </p>
    `;

    for (const group of survey.groups) {
        html += `
            <section class="mt-8">
                <h2 class="text-lg font-bold border-b pb-2">
                    ${App.utils.escape(group.name)}
                </h2>
        `;

        for (const question of group.questions) {
            if (!App.actions.isQuestionVisible(question)) {
                continue;
            }

            html += App.render.question(question);
        }

        html += '</section>';
    }

    html += `
                <button
                    type="button"
                    onclick="App.actions.confirmResponse()"
                    class="mt-8 w-full rounded-lg bg-blue-600 text-white py-3 font-medium disabled:opacity-50"
                >
                    送信する
                </button>
            </div>
        </div>
    `;

    return html;
};

App.render.question = function(question) {
    const value =
        App.state.answers[question.id];

    const error =
        (App.state.validationErrors || [])
            .includes(question.id);

    let html = `
        <div
            id="question_${question.id}"
            class="mt-6 p-4 rounded-xl border ${
                error
                    ? 'border-red-500 bg-red-50'
                    : 'border-gray-200'
            }"
        >
            <div class="font-medium">
                ${App.utils.escape(question.number || '')}
                ${App.utils.escape(question.text)}
                ${
                    question.required
                        ? '<span class="text-red-500"> *</span>'
                        : ''
                }
            </div>
    `;

    if (question.type === 'text') {
        html += `
            <textarea
                rows="4"
                class="mt-3 w-full border rounded-lg px-3 py-2"
                onchange="App.actions.setAnswer('${question.id}', this.value)"
                oninput="App.actions.setAnswer('${question.id}', this.value)"
            >${App.utils.escape(value || '')}</textarea>
        `;
    }

    if (question.type === 'single') {
        for (const option of question.options || []) {
            const checked =
                String(value || '') ===
                String(option.id);

            html += `
                <label class="flex items-center gap-2 mt-3">
                    <input
                        type="radio"
                        name="q_${question.id}"
                        value="${App.utils.escape(option.id)}"
                        ${checked ? 'checked' : ''}
                        onchange="App.actions.setAnswer('${question.id}', this.value); App.render()"
                    >
                    <span>
                        ${App.utils.escape(option.text)}
                    </span>
                </label>
            `;
        }
    }

    if (question.type === 'multiple') {
        const selected =
            Array.isArray(value) ? value : [];

        for (const option of question.options || []) {
            const checked =
                selected.includes(option.id);

            html += `
                <label class="flex items-center gap-2 mt-3">
                    <input
                        type="checkbox"
                        value="${App.utils.escape(option.id)}"
                        ${checked ? 'checked' : ''}
                        onchange="
                            App.actions.toggleMultiple(
                                '${question.id}',
                                this.value,
                                this.checked
                            )
                        "
                    >
                    <span>
                        ${App.utils.escape(option.text)}
                    </span>
                </label>
            `;
        }
    }

    if (error) {
        html += `
            <div class="mt-2 text-sm text-red-600">
                必須項目です
            </div>
        `;
    }

    html += '</div>';

    return html;
};

App.actions.toggleMultiple = function(
    questionId,
    optionId,
    checked
) {
    let values =
        Array.isArray(App.state.answers[questionId])
            ? [...App.state.answers[questionId]]
            : [];

    if (checked) {
        if (!values.includes(optionId)) {
            values.push(optionId);
        }
    } else {
        values = values.filter(
            value => value !== optionId
        );
    }

    App.state.answers[questionId] = values;

    App.utils.saveDraft();

    App.render();
};

App.render.confirm = function() {
    let html = `
        <div class="max-w-3xl mx-auto py-6 px-4">
            <div class="bg-white rounded-2xl border shadow-sm p-6">
                <h1 class="text-xl font-bold">
                    回答内容確認
                </h1>

                <p class="mt-2 text-gray-600">
                    この内容で送信しますか？
                </p>

                <div class="mt-6 space-y-4">
    `;

    for (const group of App.state.survey.groups) {
        for (const question of group.questions) {
            if (!App.actions.isQuestionVisible(question)) {
                continue;
            }

            let value =
                App.state.answers[question.id] ?? '';

            if (Array.isArray(value)) {
                value = value.join('、');
            }

            const options =
                question.options || [];

            if (
                question.type !== 'text' &&
                options.length
            ) {
                const selected =
                    options
                        .filter(o =>
                            Array.isArray(
                                App.state.answers[question.id]
                            )
                                ? App.state.answers[question.id]
                                    .includes(o.id)
                                : App.state.answers[question.id] === o.id
                        )
                        .map(o => o.text);

                if (selected.length) {
                    value = selected.join('、');
                }
            }

            html += `
                <div class="border-b pb-3">
                    <div class="text-sm text-gray-500">
                        ${App.utils.escape(question.number || '')}
                    </div>

                    <div class="font-medium">
                        ${App.utils.escape(question.text)}
                    </div>

                    <div class="mt-1 whitespace-pre-wrap">
                        ${App.utils.escape(value)}
                    </div>
                </div>
            `;
        }
    }

    html += `
                </div>

                <div class="mt-8 flex gap-3">
                    <button
                        type="button"
                        onclick="App.state.step='answer'; App.render()"
                        class="flex-1 rounded-lg border py-3"
                    >
                        戻る
                    </button>

                    <button
                        type="button"
                        onclick="App.actions.submitResponse()"
                        ${App.state.submitting ? 'disabled' : ''}
                        class="flex-1 rounded-lg bg-blue-600 text-white py-3 disabled:opacity-50"
                    >
                        ${App.state.submitting
                            ? '送信中…'
                            : '送信確定'}
                    </button>
                </div>
            </div>
        </div>
    `;

    return html;
};

App.render.alreadyAnswered = function() {
    const previous =
        App.state.previousResponse;

    return `
        <div class="max-w-xl mx-auto py-12 px-4">
            <div class="bg-white rounded-2xl border shadow-sm p-6">
                <h1 class="text-xl font-bold">
                    このアンケートには既に回答済みです
                </h1>

                <p class="mt-4 text-gray-600">
                    前回の回答日時：
                </p>

                <p class="font-medium mt-1">
                    ${App.utils.escape(
                        previous?.answered_at || ''
                    )}
                </p>

                <div class="mt-8 grid gap-3">
                    <button
                        type="button"
                        onclick="App.state.step='previous'; App.render()"
                        class="rounded-lg border py-3"
                    >
                        回答内容を確認
                    </button>

                    <button
                        type="button"
                        onclick="App.actions.startResurvey()"
                        class="rounded-lg bg-blue-600 text-white py-3"
                    >
                        もう一度回答する
                    </button>
                </div>
            </div>
        </div>
    `;
};

App.render.previous = function() {
    const response =
        App.state.previousResponse;

    return `
        <div class="max-w-3xl mx-auto py-6 px-4">
            <div class="bg-white rounded-2xl border shadow-sm p-6">
                <h1 class="text-xl font-bold">
                    前回の回答
                </h1>

                <p class="text-sm text-gray-500 mt-2">
                    ${App.utils.escape(
                        response?.answered_at || ''
                    )}
                </p>

                <div class="mt-6 space-y-4">
                    ${Object.entries(
                        response?.answers || {}
                    ).map(([id, value]) => `
                        <div class="border-b pb-3">
                            <div class="text-sm text-gray-500">
                                ${App.utils.escape(id)}
                            </div>
                            <div class="whitespace-pre-wrap">
                                ${App.utils.escape(
                                    Array.isArray(value)
                                        ? value.join('、')
                                        : value
                                )}
                            </div>
                        </div>
                    `).join('')}
                </div>

                <button
                    class="mt-8 w-full rounded-lg bg-blue-600 text-white py-3"
                    onclick="App.actions.startResurvey()"
                >
                    もう一度回答する
                </button>
            </div>
        </div>
    `;
};

App.render.complete = function() {
    return `
        <div
            id="response_complete"
            class="max-w-xl mx-auto py-16 px-4"
        >
            <div class="bg-white rounded-2xl border shadow-sm p-8 text-center">
                <div class="text-3xl mb-4">✓</div>

                <h1 class="text-xl font-bold">
                    回答ありがとうございました。
                </h1>

                <p class="mt-4 text-gray-600">
                    アンケートへのご回答ありがとうございました。
                </p>

                <div class="mt-6 rounded-lg bg-gray-50 p-4">
                    <div class="text-sm text-gray-500">
                        受付日時
                    </div>

                    <div class="font-medium mt-1">
                        ${App.utils.escape(
                            App.state.answeredAt || ''
                        )}
                    </div>
                </div>

                <p class="mt-6 text-sm text-gray-500">
                    この画面を閉じてください。
                </p>
            </div>
        </div>
    `;
};

App.render = function() {
    const root =
        document.getElementById('app');

    if (!root) {
        return;
    }

    switch (App.state.step) {
        case 'respondent_info':
            root.innerHTML =
                App.render.respondentInfo();
            break;

        case 'answer':
            root.innerHTML =
                App.render.answer();
            break;

        case 'confirm':
            root.innerHTML =
                App.render.confirm();
            break;

        case 'already_answered':
            root.innerHTML =
                App.render.alreadyAnswered();
            break;

        case 'previous':
            root.innerHTML =
                App.render.previous();
            break;

        case 'complete':
            root.innerHTML =
                App.render.complete();
            break;

        default:
            root.innerHTML = `
                <div class="min-h-screen flex items-center justify-center">
                    読み込み中…
                </div>
            `;
    }
};

App.init = async function() {
    const s = App.state;

    try {
        if (s.responseToken) {
            const result =
                await App.api.get(
                    'load_individual',
                    {
                        response_token:
                            s.responseToken
                    }
                );

            if (!result.ok) {
                s.step = 'error';
                document.getElementById('app').innerHTML =
                    `<div class="p-8 text-center">${App.utils.escape(result.message)}</div>`;
                return;
            }

            s.survey = result.survey;
            s.previousResponse =
                result.previous_response || null;

            App.actions.startIndividualResponse();
            return;
        }

        if (s.publicToken) {
            const result =
                await App.api.get(
                    'load_general_session',
                    {
                        response_session:
                            s.publicToken
                    }
                );

            /*
             * public_tokenはセッションではないため、
             * 一般回答開始画面へ。
             */
            const publicResult =
                await fetch(
                    location.pathname +
                    '?action=public_survey&public_token=' +
                    encodeURIComponent(s.publicToken)
                );

            if (!publicResult.ok) {
                throw new Error(
                    '一般回答URLが無効です。'
                );
            }

            const publicData =
                await publicResult.json();

            if (!publicData.ok) {
                throw new Error(
                    publicData.message ||
                    '一般回答URLが無効です。'
                );
            }

            s.survey = publicData.survey;

            s.step = 'respondent_info';
            App.render();
            return;
        }

        if (s.responseSession) {
            const result =
                await App.api.get(
                    'load_general_session',
                    {
                        response_session:
                            s.responseSession
                    }
                );

            if (!result.ok) {
                throw new Error(result.message);
            }

            s.survey = result.survey;
            App.actions.restoreDraftResponse();
            s.step = 'answer';
            App.render();
            return;
        }

        throw new Error(
            '回答URLが指定されていません。'
        );
    } catch (e) {
        document.getElementById('app').innerHTML = `
            <div class="min-h-screen flex items-center justify-center px-4">
                <div class="bg-white rounded-xl border p-6 max-w-md w-full">
                    <h1 class="font-bold text-lg">
                        回答を開始できません
                    </h1>

                    <p class="mt-3 text-gray-600">
                        ${App.utils.escape(e.message)}
                    </p>
                </div>
            </div>
        `;
    }
};

if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        () => App.init(),
        { once: true }
    );
} else {
    App.init();
}
</script>
</body>
</html>
<?php
exit;
}

/* ------------------------------------------------------------
 * 一般回答URLの公開データ取得
 * ------------------------------------------------------------ */

if (
    isset($_GET['action']) &&
    $_GET['action'] === 'public_survey'
) {
    $data = survey_app_read_storage();

    $token = (string)($_GET['public_token'] ?? '');

    $survey = survey_app_get_survey_by_public_token(
        $data,
        $token
    );

    if (!$survey) {
        survey_app_json_response([
            'ok' => false,
            'message' => '一般回答URLが無効です。'
        ], 404);
    }

    if (
        !$survey['general_response_enabled'] ||
        !survey_app_survey_available($survey)
    ) {
        survey_app_json_response([
            'ok' => false,
            'message' => '現在一般回答を受け付けていません。'
        ], 403);
    }

    survey_app_json_response([
        'ok' => true,
        'survey' => $survey
    ]);
}

/* ------------------------------------------------------------
 * 回答者URL
 * ------------------------------------------------------------ */

if (survey_app_is_respondent_request()) {
    survey_app_respondent_html();
}

/* ------------------------------------------------------------
 * API
 * ------------------------------------------------------------ */

if (
    isset($_GET['action']) ||
    isset($_POST['action'])
) {
    survey_app_api();
}

/* ------------------------------------------------------------
 * 管理画面
 * ------------------------------------------------------------ */

$authenticated =
    !empty($_SESSION['survey_admin_authenticated']);

$csrf = survey_app_csrf_token();

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-gray-50 text-gray-900">

<?php if (!$authenticated): ?>

<div class="min-h-screen flex items-center justify-center px-4">

    <div class="bg-white border rounded-2xl shadow-sm w-full max-w-md p-6">

        <h1 class="text-xl font-bold">
            アンケート管理システム
        </h1>

        <p class="text-sm text-gray-500 mt-2">
            管理者ログイン
        </p>

        <form
            class="mt-6 space-y-4"
            onsubmit="return App.login(event,this)"
        >

            <div>
                <label class="block text-sm font-medium mb-1">
                    ログイン名
                </label>

                <input
                    name="login_name"
                    autocomplete="username"
                    class="w-full rounded-lg border px-3 py-2"
                    required
                >
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">
                    パスワード
                </label>

                <input
                    name="login_password"
                    type="password"
                    autocomplete="current-password"
                    class="w-full rounded-lg border px-3 py-2"
                    required
                >
            </div>

            <button
                class="w-full rounded-lg bg-blue-600 text-white py-3 font-medium"
            >
                ログイン
            </button>

            <div
                id="login_message"
                class="text-sm text-red-600"
            ></div>

        </form>

    </div>
</div>

<?php else: ?>

<header class="sticky top-0 z-30 bg-white border-b">

    <div class="max-w-7xl mx-auto px-4 h-16 flex items-center justify-between">

        <div class="font-bold">
            アンケート管理システム
        </div>

        <nav class="flex items-center gap-2">

            <button
                onclick="App.actions.goSurveyList()"
                class="px-3 py-2 rounded-lg hover:bg-gray-100"
            >
                アンケート一覧
            </button>

            <button
                onclick="App.actions.goSettings()"
                class="px-3 py-2 rounded-lg hover:bg-gray-100"
            >
                kintone連携設定
            </button>

            <button
                onclick="App.actions.logout()"
                class="px-3 py-2 rounded-lg hover:bg-gray-100"
            >
                ログアウト
            </button>

        </nav>

    </div>

</header>

<main class="max-w-7xl mx-auto px-4 py-6">

    <div id="app"></div>

</main>

<?php endif; ?>

<script>

window.App = window.App || {

    state: {
        initialized: false,
        page: 'list',
        surveys: [],
        customers: [],
        responses: [],
        settings: {},
        mailLogs: [],
        selectedSurvey: null,
        selectedSurveyData: null,
        surveyFilter: {
            keyword: '',
            status: '',
            sort: 'updated_desc'
        },
        csrfToken: <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>,
        loading: false,
        previewMobile: false,
        selectedQuestions: {}
    },

    render: {},
    actions: {},
    api: {},
    utils: {}
};

App.utils.escape = function(value) {
    const div = document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
};

App.utils.api = async function(
    action,
    method = 'GET',
    data = {}
) {
    let url = location.pathname;

    const options = {
        method,
        credentials: 'same-origin'
    };

    if (method === 'GET') {

        const params = new URLSearchParams({
            action,
            ...data
        });

        url += '?' + params.toString();

    } else {

        const body = new URLSearchParams({
            action,
            csrf_token: App.state.csrfToken,
            ...data
        });

        options.headers = {
            'Content-Type':
                'application/x-www-form-urlencoded'
        };

        options.body = body;
    }

    const response =
        await fetch(url, options);

    const json =
        await response.json();

    if (
        response.status === 401 ||
        response.status === 403
    ) {
        if (json.message) {
            throw new Error(json.message);
        }
    }

    return json;
};

App.api.get = function(action, data = {}) {
    return App.utils.api(action, 'GET', data);
};

App.api.post = function(action, data = {}) {
    return App.utils.api(action, 'POST', data);
};

App.api.fetchKintoneFields = function() {
    return App.api.get('fetch_kintone_fields');
};

App.actions.initSortable = function() {

    if (
        typeof Sortable === 'undefined'
    ) {
        return;
    }

    document
        .querySelectorAll('.survey-question-list')
        .forEach(element => {

            new Sortable(element, {

                group: 'survey_questions',

                animation: 150,

                handle: '.question-handle',

                onEnd: function() {

                    if (!App.state.selectedSurvey) {
                        return;
                    }

                    App.actions.rebuildSurveyFromDOM();
                    App.actions.renumberQuestions();
                    App.render.editor();
                    App.actions.initSortable();
                }

            });
        });

    const groupList =
        document.getElementById('survey_group_list');

    if (groupList) {

        new Sortable(groupList, {

            animation: 150,

            handle: '.group-handle',

            onEnd: function() {

                App.actions.rebuildSurveyFromDOM();
                App.actions.renumberQuestions();
                App.render.editor();
                App.actions.initSortable();

            }
        });
    }
};

App.actions.addGroup = function() {

    if (!App.state.selectedSurvey) {
        return;
    }

    App.state.selectedSurvey.groups.push({

        id:
            'group_' +
            Date.now() +
            '_' +
            Math.random()
                .toString(16)
                .slice(2),

        name: '新しいグループ',

        questions: []

    });

    App.actions.renumberQuestions();

    App.render.editor();

    App.actions.initSortable();
};

App.actions.addQuestion = function(groupId) {

    if (!App.state.selectedSurvey) {
        return;
    }

    const group =
        App.state.selectedSurvey.groups
            .find(g => g.id === groupId);

    if (!group) {
        return;
    }

    group.questions.push({

        id:
            'question_' +
            Date.now() +
            '_' +
            Math.random()
                .toString(16)
                .slice(2),

        text: '新しい質問',

        type: 'text',

        required: false,

        options: [],

        other_enabled: false,

        branching: {}

    });

    App.actions.renumberQuestions();

    App.render.editor();

    App.actions.initSortable();
};

App.actions.deleteGroup = function(groupId) {

    if (!App.state.selectedSurvey) {
        return;
    }

    if (!confirm(
        'このグループを削除しますか？'
    )) {
        return;
    }

    App.state.selectedSurvey.groups =
        App.state.selectedSurvey.groups
            .filter(g => g.id !== groupId);

    App.actions.renumberQuestions();

    App.render.editor();

    App.actions.initSortable();
};

App.actions.deleteQuestion = function(
    groupId,
    questionId
) {

    if (!App.state.selectedSurvey) {
        return;
    }

    const group =
        App.state.selectedSurvey.groups
            .find(g => g.id === groupId);

    if (!group) {
        return;
    }

    group.questions =
        group.questions
            .filter(q => q.id !== questionId);

    App.actions.renumberQuestions();

    App.render.editor();

    App.actions.initSortable();
};

App.actions.moveQuestion = function() {
    App.actions.rebuildSurveyFromDOM();
    App.actions.renumberQuestions();
};

App.actions.renumberQuestions = function() {

    const survey =
        App.state.selectedSurvey;

    if (!survey) {
        return;
    }

    let globalNumber = 0;

    survey.groups.forEach(
        (group, groupIndex) => {

            group.questions.forEach(
                (question, questionIndex) => {

                    globalNumber++;

                    if (
                        survey.numbering_mode ===
                        'group'
                    ) {

                        question.number =
                            'Q' +
                            (groupIndex + 1) +
                            '-' +
                            (questionIndex + 1);

                    } else {

                        question.number =
                            'Q' +
                            globalNumber;

                    }

                }
            );

        }
    );
};

App.actions.rebuildSurveyFromDOM = function() {

    /*
     * SortableJS後のDOM順をStateへ反映。
     * DOMのdata属性を基準に再構築する。
     */

    const survey =
        App.state.selectedSurvey;

    if (!survey) {
        return;
    }

    const groups = [];

    document
        .querySelectorAll(
            '#survey_group_list > .survey-group'
        )
        .forEach(groupElement => {

            const groupId =
                groupElement.dataset.groupId;

            const original =
                survey.groups.find(
                    g => g.id === groupId
                );

            if (!original) {
                return;
            }

            const questions = [];

            groupElement
                .querySelectorAll(
                    '.survey-question'
                )
                .forEach(questionElement => {

                    const questionId =
                        questionElement.dataset.questionId;

                    const question =
                        survey.groups
                            .flatMap(
                                g => g.questions
                            )
                            .find(
                                q => q.id === questionId
                            );

                    if (question) {
                        questions.push(question);
                    }

                });

            groups.push({
                ...original,
                questions
            });

        });

    if (groups.length) {
        survey.groups = groups;
    }
};

App.actions.updateQuestion = function(
    groupId,
    questionId,
    field,
    value
) {

    const group =
        App.state.selectedSurvey.groups
            .find(g => g.id === groupId);

    if (!group) {
        return;
    }

    const question =
        group.questions
            .find(q => q.id === questionId);

    if (!question) {
        return;
    }

    if (field === 'required') {
        question.required = !!value;
    } else {
        question[field] = value;
    }

    if (field === 'type' && value === 'text') {
        question.options = [];
    }

    App.actions.renumberQuestions();
};

App.actions.updateGroupName = function(
    groupId,
    value
) {

    const group =
        App.state.selectedSurvey.groups
            .find(g => g.id === groupId);

    if (group) {
        group.name = value;
    }
};

App.actions.addOption = function(
    groupId,
    questionId
) {

    const group =
        App.state.selectedSurvey.groups
            .find(g => g.id === groupId);

    const question =
        group?.questions.find(
            q => q.id === questionId
        );

    if (!question) {
        return;
    }

    question.options.push({

        id:
            'option_' +
            Date.now() +
            '_' +
            Math.random()
                .toString(16)
                .slice(2),

        text: '選択肢',

        branch_to: null

    });

    App.render.editor();

    App.actions.initSortable();
};

App.actions.deleteOption = function(
    groupId,
    questionId,
    optionId
) {

    const group =
        App.state.selectedSurvey.groups
            .find(g => g.id === groupId);

    const question =
        group?.questions.find(
            q => q.id === questionId
        );

    if (!question) {
        return;
    }

    question.options =
        question.options.filter(
            o => o.id !== optionId
        );

    App.render.editor();

    App.actions.initSortable();
};

App.actions.updateOption = function(
    groupId,
    questionId,
    optionId,
    value
) {

    const group =
        App.state.selectedSurvey.groups
            .find(g => g.id === groupId);

    const question =
        group?.questions.find(
            q => q.id === questionId
        );

    const option =
        question?.options.find(
            o => o.id === optionId
        );

    if (option) {
        option.text = value;
    }
};

App.actions.preview = function() {

    App.state.previewMobile = false;

    const modal =
        document.getElementById(
            'preview_modal'
        );

    const content =
        document.getElementById(
            'preview_content'
        );

    content.innerHTML =
        App.render.previewContent();

    modal.classList.remove('hidden');
};

App.actions.closeModal = function(id) {

    const element =
        document.getElementById(id);

    if (element) {
        element.classList.add('hidden');
    }
};

App.actions.saveSurvey = async function() {

    if (!App.state.selectedSurvey) {
        return;
    }

    App.actions.renumberQuestions();

    const result =
        await App.api.post(
            'save_survey',
            {
                survey_json:
                    JSON.stringify(
                        App.state.selectedSurvey
                    )
            }
        );

    if (!result.ok) {
        alert(result.message);
        return;
    }

    App.state.selectedSurvey =
        result.survey;

    await App.actions.loadBootstrap();

    alert('保存しました。');
};

App.actions.cancelEdit = function() {
    App.actions.goSurveyList();
};

App.actions.stopSurvey = async function(id) {

    if (!confirm(
        'このアンケートを停止しますか？'
    )) {
        return;
    }

    const result =
        await App.api.post(
            'stop_survey',
            {
                survey_id: id
            }
        );

    if (!result.ok) {
        alert(result.message);
        return;
    }

    await App.actions.loadBootstrap();

    App.actions.goSurveyList();
};

App.actions.resumeSurvey = async function(id) {

    const result =
        await App.api.post(
            'resume_survey',
            {
                survey_id: id
            }
        );

    if (!result.ok) {
        alert(result.message);
        return;
    }

    await App.actions.loadBootstrap();

    App.actions.goSurveyList();
};

App.actions.duplicateSurvey = async function(id) {

    const result =
        await App.api.post(
            'duplicate_survey',
            {
                survey_id: id
            }
        );

    if (!result.ok) {
        alert(result.message);
        return;
    }

    await App.actions.loadBootstrap();

    App.actions.goSurveyList();
};

App.actions.deleteSurvey = async function(id) {

    if (!confirm(
        '下書きを削除しますか？'
    )) {
        return;
    }

    const result =
        await App.api.post(
            'delete_survey',
            {
                survey_id: id
            }
        );

    if (!result.ok) {
        alert(result.message);
        return;
    }

    await App.actions.loadBootstrap();

    App.actions.goSurveyList();
};

App.actions.filterSurveys = function() {

    const keyword =
        document.getElementById(
            'survey_keyword'
        )?.value || '';

    const status =
        document.getElementById(
            'survey_status'
        )?.value || '';

    App.state.surveyFilter.keyword =
        keyword;

    App.state.surveyFilter.status =
        status;

    App.render.list();
};

App.actions.sortSurveys = function(value) {

    App.state.surveyFilter.sort =
        value;

    App.render.list();
};

App.actions.showAllResponses = function(
    responseId
) {

    const response =
        App.state.responses
            .find(r => r.id === responseId);

    if (!response) {
        return;
    }

    const detail =
        document.getElementById(
            'response_detail'
        );

    detail.innerHTML =
        App.render.responseDetail(response);

    document
        .getElementById('response_modal')
        .classList.remove('hidden');
};

App.actions.toggleResponseFilter = function() {
    App.render.analytics();
};

App.actions.sendMail = async function() {

    const selected =
        [...document.querySelectorAll(
            '.customer-checkbox:checked'
        )].map(
            element => element.value
        );

    if (!selected.length) {
        alert('送信対象を選択してください。');
        return;
    }

    const alreadySent =
        selected.some(id => {

            const customer =
                App.state.customers
                    .find(c => c.id === id);

            return !!customer?.sent_at;
        });

    if (
        alreadySent &&
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

    const templateType =
        document.getElementById(
            'template_type'
        ).value;

    const result =
        await App.api.post(
            'send_mail',
            {
                survey_id:
                    App.state.selectedSurvey.id,

                recipient_ids:
                    JSON.stringify(selected),

                mail_subject:
                    subject,

                mail_body:
                    body,

                template_type:
                    templateType
            }
        );

    if (!result.ok) {
        alert(result.message);
        return;
    }

    alert(
        '送信成功: ' +
        result.success_count +
        '件 / 失敗: ' +
        result.failure_count +
        '件'
    );

    await App.actions.loadBootstrap();

    App.render.send();
};

App.actions.sendReminder = function() {

    const ids =
        App.state.customers
            .filter(
                c =>
                    c.answer_status ===
                    'unanswered'
            )
            .map(c => c.id);

    document
        .querySelectorAll(
            '.customer-checkbox'
        )
        .forEach(element => {
            element.checked =
                ids.includes(element.value);
        });

    App.actions.sendMail();
};

App.actions.showSentMail = function(
    customerId
) {

    const customer =
        App.state.customers
            .find(c => c.id === customerId);

    if (!customer) {
        return;
    }

    alert(
        customer.last_sent_body ||
        '送信文がありません。'
    );
};

App.actions.fetchKintoneFields = async function() {

    const message =
        document.getElementById(
            'field_message'
        );

    message.textContent =
        '取得中…';

    const result =
        await App.api.fetchKintoneFields();

    if (!result.ok) {
        message.textContent =
            result.message ||
            '取得に失敗しました。';
        return;
    }

    App.state.kintoneFields =
        result.fields || [];

    App.render.settings();

    message.textContent =
        'フィールドを取得しました。';
};

App.actions.syncCustomers = async function() {

    const result =
        await App.api.post(
            'sync_customers'
        );

    if (!result.ok) {
        alert(
            result.message ||
            '同期に失敗しました。'
        );
        return;
    }

    alert(
        result.count +
        '件の顧客データを取得しました。'
    );

    await App.actions.loadBootstrap();
};

App.actions.kintoneConnectionTest =
async function() {

    const result =
        await App.api.get(
            'kintone_connection_test'
        );

    alert(
        [
            result.ok
                ? '接続成功'
                : '接続失敗',

            'HTTP: ' +
                (result.status || '-'),

            result.message || ''
        ].join('\n')
    );
};

App.actions.smtpTest = async function() {

    const to =
        prompt(
            'テスト送信先メールアドレス'
        );

    if (!to) {
        return;
    }

    const result =
        await App.api.post(
            'smtp_test',
            {
                smtp_test_to: to
            }
        );

    alert(
        [
            result.ok
                ? 'SMTP送信成功'
                : 'SMTP送信失敗',

            result.smtp_response || '',
            result.message || ''
        ].join('\n')
    );
};

App.actions.saveSettings = async function() {

    const settings = {};

    document
        .querySelectorAll(
            '#settings_form [data-setting]'
        )
        .forEach(element => {

            settings[
                element.dataset.setting
            ] = element.value;

        });

    const checkbox =
        document.querySelector(
            '[data-setting="ssl_verify"]'
        );

    if (checkbox?.type === 'checkbox') {
        settings.ssl_verify =
            checkbox.checked;
    }

    const result =
        await App.api.post(
            'save_settings',
            {
                settings_json:
                    JSON.stringify(settings)
            }
        );

    if (!result.ok) {
        alert(result.message);
        return;
    }

    await App.actions.loadBootstrap();

    alert('設定を保存しました。');
};

App.actions.goSurveyList = function() {

    App.state.page = 'list';

    App.render.list();
};

App.actions.goSettings = function() {

    App.state.page = 'settings';

    App.render.settings();
};

App.actions.goEdit = function(id) {

    const survey =
        App.state.surveys
            .find(s => s.id === id);

    if (!survey) {
        return;
    }

    App.state.selectedSurvey =
        JSON.parse(JSON.stringify(survey));

    App.state.page = 'editor';

    App.render.editor();

    App.actions.initSortable();
};

App.actions.newSurvey = function() {

    App.state.selectedSurvey = {

        id:
            'survey_' +
            Date.now() +
            '_' +
            Math.random()
                .toString(16)
                .slice(2),

        title: '新しいアンケート',

        start_at: '',

        end_at: '',

        status: 'draft',

        created_at: '',

        updated_at: '',

        numbering_mode: 'global',

        groups: [

            {
                id:
                    'group_' +
                    Date.now(),

                name: '基本情報',

                questions: []
            }

        ],

        deleted: false,

        general_response_enabled: false,

        public_token:
            crypto.randomUUID()
    };

    App.actions.addQuestion(
        App.state.selectedSurvey.groups[0].id
    );

    App.state.page = 'editor';

    App.render.editor();

    App.actions.initSortable();
};

App.actions.goAnalytics = async function(id) {

    const result =
        await App.api.get(
            'survey_data',
            {
                survey_id: id
            }
        );

    if (!result.ok) {
        alert(result.message);
        return;
    }

    App.state.selectedSurveyData =
        result;

    App.state.selectedSurvey =
        result.survey;

    App.state.responses =
        result.responses || [];

    App.state.page = 'analytics';

    App.render.analytics();
};

App.actions.goSend = function(id) {

    const survey =
        App.state.surveys
            .find(s => s.id === id);

    if (!survey) {
        return;
    }

    App.state.selectedSurvey =
        survey;

    App.state.page = 'send';

    App.render.send();
};

App.actions.logout = async function() {

    await App.api.get('logout');

    location.reload();
};

App.actions.loadBootstrap = async function() {

    const result =
        await App.api.get('bootstrap');

    if (!result.ok) {
        throw new Error(
            result.message ||
            '初期データ取得失敗'
        );
    }

    App.state.csrfToken =
        result.csrf_token;

    App.state.surveys =
        (result.data.surveys || [])
            .filter(s => !s.deleted);

    App.state.customers =
        (result.data.customers || [])
            .filter(c => !c.deleted);

    App.state.settings =
        result.data.settings || {};

    App.state.mailLogs =
        result.data.mail_logs || [];
};

App.render.header = function(
    breadcrumb = ''
) {

    return `
        <div class="mb-6 flex items-center justify-between">

            <div>
                <div class="text-sm text-gray-500">
                    ホーム
                    ${breadcrumb
                        ? ' › ' + App.utils.escape(breadcrumb)
                        : ''}
                </div>
            </div>

        </div>
    `;
};

App.render.list = function() {

    let surveys =
        [...App.state.surveys];

    const keyword =
        App.state.surveyFilter.keyword
            .toLowerCase();

    const status =
        App.state.surveyFilter.status;

    if (keyword) {

        surveys =
            surveys.filter(
                s =>
                    String(s.title)
                        .toLowerCase()
                        .includes(keyword)
            );
    }

    if (status) {

        surveys =
            surveys.filter(
                s => s.status === status
            );
    }

    const sort =
        App.state.surveyFilter.sort;

    surveys.sort((a, b) => {

        const responseCountA =
            App.state.responses
                .filter(
                    r =>
                        r.survey_id === a.id &&
                        !r.deleted
                ).length;

        const responseCountB =
            App.state.responses
                .filter(
                    r =>
                        r.survey_id === b.id &&
                        !r.deleted
                ).length;

        if (sort === 'updated_desc') {
            return String(b.updated_at)
                .localeCompare(
                    String(a.updated_at)
                );
        }

        if (sort === 'updated_asc') {
            return String(a.updated_at)
                .localeCompare(
                    String(b.updated_at)
                );
        }

        if (sort === 'responses_desc') {
            return responseCountB -
                responseCountA;
        }

        if (sort === 'responses_asc') {
            return responseCountA -
                responseCountB;
        }

        if (sort === 'start_desc') {
            return String(b.start_at)
                .localeCompare(
                    String(a.start_at)
                );
        }

        return String(a.start_at)
            .localeCompare(
                String(b.start_at)
            );
    });

    document.getElementById('app').innerHTML = `

        ${App.render.header('アンケート一覧')}

        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">

            <h1 class="text-2xl font-bold">
                アンケート一覧
            </h1>

            <button
                onclick="App.actions.newSurvey()"
                class="rounded-lg bg-blue-600 text-white px-4 py-2"
            >
                + 新規アンケート作成
            </button>

        </div>

        <div class="bg-white border rounded-xl p-4 mb-4">

            <div class="grid md:grid-cols-3 gap-3">

                <input
                    id="survey_keyword"
                    value="${App.utils.escape(
                        App.state.surveyFilter.keyword
                    )}"
                    oninput="App.actions.filterSurveys()"
                    placeholder="タイトル検索"
                    class="rounded-lg border px-3 py-2"
                >

                <select
                    id="survey_status"
                    onchange="App.actions.filterSurveys()"
                    class="rounded-lg border px-3 py-2"
                >
                    <option value="">
                        すべて
                    </option>

                    <option
                        value="draft"
                        ${status === 'draft'
                            ? 'selected'
                            : ''}
                    >
                        下書き
                    </option>

                    <option
                        value="active"
                        ${status === 'active'
                            ? 'selected'
                            : ''}
                    >
                        公開中
                    </option>

                    <option
                        value="ended"
                        ${status === 'ended'
                            ? 'selected'
                            : ''}
                    >
                        終了
                    </option>
                </select>

                <select
                    onchange="App.actions.sortSurveys(this.value)"
                    class="rounded-lg border px-3 py-2"
                >

                    <option value="updated_desc">
                        更新日 新しい順
                    </option>

                    <option value="updated_asc">
                        更新日 古い順
                    </option>

                    <option value="responses_desc">
                        回答数 多い順
                    </option>

                    <option value="responses_asc">
                        回答数 少ない順
                    </option>

                    <option value="start_desc">
                        開始日 新しい順
                    </option>

                    <option value="start_asc">
                        開始日 古い順
                    </option>

                </select>

            </div>

        </div>

        <div class="bg-white border rounded-xl overflow-x-auto">

            <table class="w-full text-sm">

                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="text-left p-3">作成日</th>
                        <th class="text-left p-3">更新日</th>
                        <th class="text-left p-3">タイトル</th>
                        <th class="text-left p-3">開始日時</th>
                        <th class="text-left p-3">終了日時</th>
                        <th class="text-left p-3">ステータス</th>
                        <th class="text-left p-3">回答数</th>
                        <th class="text-left p-3">操作</th>
                    </tr>
                </thead>

                <tbody>

                    ${
                        surveys.map(
                            survey =>
                                App.render.surveyRow(
                                    survey
                                )
                        ).join('')
                    }

                </tbody>

            </table>

        </div>
    `;
};

App.render.surveyRow = function(survey) {

    const count =
        App.state.responses.filter(
            r =>
                r.survey_id === survey.id &&
                !r.deleted
        ).length;

    let actions = `
        <button
            onclick="App.actions.goEdit('${survey.id}')"
            class="text-blue-600 hover:underline"
        >
            確認・編集
        </button>
    `;

    if (survey.status === 'active') {

        actions += `
            <button
                onclick="App.actions.goAnalytics('${survey.id}')"
                class="text-blue-600 hover:underline ml-2"
            >
                集計
            </button>

            <button
                onclick="App.actions.goSend('${survey.id}')"
                class="text-blue-600 hover:underline ml-2"
            >
                送信
            </button>

            <button
                onclick="App.actions.stopSurvey('${survey.id}')"
                class="text-red-600 hover:underline ml-2"
            >
                停止
            </button>
        `;

    } else if (survey.status === 'draft') {

        actions += `
            <button
                onclick="App.actions.deleteSurvey('${survey.id}')"
                class="text-red-600 hover:underline ml-2"
            >
                削除
            </button>
        `;

    } else {

        actions += `
            <button
                onclick="App.actions.goAnalytics('${survey.id}')"
                class="text-blue-600 hover:underline ml-2"
            >
                集計
            </button>

            <button
                onclick="App.actions.resumeSurvey('${survey.id}')"
                class="text-blue-600 hover:underline ml-2"
            >
                再開
            </button>
        `;
    }

    actions += `
        <button
            onclick="App.actions.duplicateSurvey('${survey.id}')"
            class="text-gray-600 hover:underline ml-2"
        >
            複製
        </button>
    `;

    return `
        <tr class="border-b last:border-b-0">

            <td class="p-3">
                ${App.utils.escape(
                    survey.created_at
                )}
            </td>

            <td class="p-3">
                ${App.utils.escape(
                    survey.updated_at
                )}
            </td>

            <td class="p-3 font-medium">
                ${App.utils.escape(
                    survey.title
                )}
            </td>

            <td class="p-3">
                ${App.utils.escape(
                    survey.start_at
                )}
            </td>

            <td class="p-3">
                ${App.utils.escape(
                    survey.end_at
                )}
            </td>

            <td class="p-3">
                ${App.render.statusBadge(
                    survey.status
                )}
            </td>

            <td class="p-3">
                ${count}
            </td>

            <td class="p-3 whitespace-nowrap">
                ${actions}
            </td>

        </tr>
    `;
};

App.render.statusBadge = function(status) {

    const map = {

        draft: [
            '下書き',
            'bg-gray-100 text-gray-700'
        ],

        active: [
            '公開中',
            'bg-green-100 text-green-700'
        ],

        ended: [
            '終了',
            'bg-yellow-100 text-yellow-700'
        ]

    };

    const value =
        map[status] ||
        [status, 'bg-gray-100'];

    return `
        <span
            class="inline-flex rounded-full px-2 py-1 text-xs ${value[1]}"
        >
            ${App.utils.escape(value[0])}
        </span>
    `;
};

App.render.editor = function() {

    const survey =
        App.state.selectedSurvey;

    if (!survey) {
        return;
    }

    document.getElementById('app').innerHTML = `

        ${App.render.header(
            'アンケート一覧 › アンケート編集'
        )}

        <div class="flex items-center justify-between mb-4">

            <h1 class="text-2xl font-bold">
                アンケート編集
            </h1>

            <div class="flex gap-2">

                <button
                    onclick="App.actions.preview()"
                    class="rounded-lg border px-4 py-2"
                >
                    プレビュー
                </button>

                <button
                    onclick="App.actions.cancelEdit()"
                    class="rounded-lg border px-4 py-2"
                >
                    戻る
                </button>

                <button
                    onclick="App.actions.saveSurvey()"
                    class="rounded-lg bg-blue-600 text-white px-4 py-2"
                >
                    保存
                </button>

            </div>

        </div>

        <div class="bg-white border rounded-xl p-5">

            <div class="grid md:grid-cols-2 gap-4">

                <div class="md:col-span-2">

                    <label class="block text-sm font-medium mb-1">
                        タイトル
                    </label>

                    <input
                        id="survey_title"
                        value="${App.utils.escape(
                            survey.title
                        )}"
                        oninput="
                            App.state.selectedSurvey.title=this.value
                        "
                        class="w-full rounded-lg border px-3 py-2"
                    >

                </div>

                <div>

                    <label class="block text-sm font-medium mb-1">
                        開始日時
                    </label>

                    <input
                        id="survey_start_at"
                        type="datetime-local"
                        value="${App.utils.escape(
                            survey.start_at
                        )}"
                        oninput="
                            App.state.selectedSurvey.start_at=this.value
                        "
                        class="w-full rounded-lg border px-3 py-2"
                    >

                </div>

                <div>

                    <label class="block text-sm font-medium mb-1">
                        終了日時
                    </label>

                    <input
                        id="survey_end_at"
                        type="datetime-local"
                        value="${App.utils.escape(
                            survey.end_at
                        )}"
                        oninput="
                            App.state.selectedSurvey.end_at=this.value
                        "
                        class="w-full rounded-lg border px-3 py-2"
                    >

                </div>

                <div>

                    <label class="block text-sm font-medium mb-1">
                        質問番号形式
                    </label>

                    <select
                        id="survey_numbering_mode"
                        onchange="
                            App.state.selectedSurvey.numbering_mode=this.value;
                            App.actions.renumberQuestions();
                            App.render.editor();
                            App.actions.initSortable()
                        "
                        class="w-full rounded-lg border px-3 py-2"
                    >

                        <option
                            value="global"
                            ${survey.numbering_mode === 'global'
                                ? 'selected'
                                : ''}
                        >
                            global（Q1, Q2, Q3）
                        </option>

                        <option
                            value="group"
                            ${survey.numbering_mode === 'group'
                                ? 'selected'
                                : ''}
                        >
                            group（Q1-1, Q1-2）
                        </option>

                    </select>

                </div>

                <div class="flex items-center gap-3">

                    <label class="inline-flex items-center gap-2">

                        <input
                            type="checkbox"
                            ${survey.general_response_enabled
                                ? 'checked'
                                : ''}
                            onchange="
                                App.state.selectedSurvey.general_response_enabled=this.checked
                            "
                        >

                        <span>
                            一般回答を許可する
                        </span>

                    </label>

                </div>

            </div>

            ${
                survey.general_response_enabled
                    ? `
                        <div class="mt-4 bg-gray-50 rounded-lg p-4">

                            <div class="font-medium">
                                一般回答URL
                            </div>

                            <div class="mt-2 text-sm break-all">
                                ${
                                    App.utils.escape(
                                        location.origin +
                                        location.pathname +
                                        '?public_token=' +
                                        survey.public_token
                                    )
                                }
                            </div>

                            <button
                                onclick="
                                    navigator.clipboard.writeText(
                                        '${App.utils.escape(
                                            location.origin +
                                            location.pathname +
                                            '?public_token=' +
                                            survey.public_token
                                        )}'
                                    );
                                    alert('URLをコピーしました。')
                                "
                                class="mt-3 rounded-lg border px-3 py-2 bg-white"
                            >
                                URLをコピー
                            </button>

                        </div>
                    `
                    : ''
            }

        </div>

        <div class="mt-5">

            <div class="flex items-center justify-between mb-3">

                <h2 class="text-xl font-bold">
                    質問・グループ
                </h2>

                <button
                    onclick="App.actions.addGroup()"
                    class="rounded-lg border px-3 py-2 bg-white"
                >
                    + グループ追加
                </button>

            </div>

            <div
                id="survey_group_list"
                class="space-y-4"
            >

                ${
                    survey.groups
                        .map(
                            group =>
                                App.render.group(
                                    group
                                )
                        )
                        .join('')
                }

            </div>

        </div>

        <div
            id="preview_modal"
            class="hidden fixed inset-0 z-50 bg-black/50"
        >

            <div
                class="h-full flex items-center justify-center p-4"
                onclick="if(event.target===this) App.actions.closeModal('preview_modal')"
            >

                <div
                    class="bg-white rounded-2xl shadow-xl max-w-4xl w-full max-h-[90vh] overflow-auto"
                >

                    <div class="p-4 border-b flex justify-between">

                        <div class="font-bold">
                            プレビュー
                        </div>

                        <button
                            onclick="App.actions.closeModal('preview_modal')"
                            class="text-gray-500"
                        >
                            ×
                        </button>

                    </div>

                    <div
                        id="preview_content"
                        class="p-4"
                    ></div>

                </div>

            </div>

        </div>
    `;
};

App.render.group = function(group) {

    return `

        <div
            class="survey-group bg-white border rounded-xl p-4"
            data-group-id="${App.utils.escape(group.id)}"
        >

            <div class="flex items-center gap-2 mb-4">

                <span class="group-handle cursor-move text-gray-400">
                    ☰
                </span>

                <input
                    value="${App.utils.escape(group.name)}"
                    onchange="
                        App.actions.updateGroupName(
                            '${group.id}',
                            this.value
                        )
                    "
                    class="flex-1 rounded-lg border px-3 py-2 font-medium"
                >

                <button
                    onclick="
                        App.actions.addQuestion('${group.id}')
                    "
                    class="rounded-lg bg-blue-50 text-blue-700 px-3 py-2"
                >
                    + 質問
                </button>

                <button
                    onclick="
                        App.actions.deleteGroup('${group.id}')
                    "
                    class="rounded-lg bg-red-50 text-red-700 px-3 py-2"
                >
                    削除
                </button>

            </div>

            <div
                class="survey-question-list space-y-3 min-h-10"
            >

                ${
                    group.questions
                        .map(
                            question =>
                                App.render.questionEditor(
                                    group.id,
                                    question
                                )
                        )
                        .join('')
                }

            </div>

        </div>
    `;
};

App.render.questionEditor = function(
    groupId,
    question
) {

    return `

        <div
            class="survey-question border rounded-lg p-4 bg-gray-50"
            data-question-id="${App.utils.escape(
                question.id
            )}"
        >

            <div class="flex gap-2">

                <span class="question-handle cursor-move text-gray-400">
                    ⋮⋮
                </span>

                <div class="flex-1">

                    <div class="flex gap-2 mb-2">

                        <span
                            class="inline-flex items-center px-2 py-1 rounded bg-white border text-xs"
                        >
                            ${App.utils.escape(
                                question.number || ''
                            )}
                        </span>

                        <select
                            onchange="
                                App.actions.updateQuestion(
                                    '${groupId}',
                                    '${question.id}',
                                    'type',
                                    this.value
                                );
                                App.render.editor();
                                App.actions.initSortable()
                            "
                            class="rounded border px-2 py-1"
                        >

                            <option
                                value="text"
                                ${question.type === 'text'
                                    ? 'selected'
                                    : ''}
                            >
                                自由記述
                            </option>

                            <option
                                value="single"
                                ${question.type === 'single'
                                    ? 'selected'
                                    : ''}
                            >
                                単一選択
                            </option>

                            <option
                                value="multiple"
                                ${question.type === 'multiple'
                                    ? 'selected'
                                    : ''}
                            >
                                複数選択
                            </option>

                        </select>

                    </div>

                    <textarea
                        class="w-full rounded-lg border px-3 py-2 bg-white"
                        rows="2"
                        oninput="
                            App.actions.updateQuestion(
                                '${groupId}',
                                '${question.id}',
                                'text',
                                this.value
                            )
                        "
                    >${App.utils.escape(
                        question.text
                    )}</textarea>

                    <div class="mt-3">

                        <label class="inline-flex items-center gap-2">

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
                            >

                            必須回答

                        </label>

                    </div>

                    ${
                        question.type !== 'text'
                            ? `
                                <div class="mt-4">

                                    <div class="text-sm font-medium mb-2">
                                        選択肢
                                    </div>

                                    <div class="space-y-2">

                                        ${
                                            (question.options || [])
                                                .map(
                                                    option =>
                                                        App.render.option(
                                                            groupId,
                                                            question.id,
                                                            option
                                                        )
                                                )
                                                .join('')
                                        }

                                    </div>

                                    <button
                                        onclick="
                                            App.actions.addOption(
                                                '${groupId}',
                                                '${question.id}'
                                            )
                                        "
                                        class="mt-2 rounded-lg border px-3 py-1.5 bg-white"
                                    >
                                        + 選択肢
                                    </button>

                                </div>
                            `
                            : ''
                    }

                </div>

                <button
                    onclick="
                        App.actions.deleteQuestion(
                            '${groupId}',
                            '${question.id}'
                        )
                    "
                    class="text-red-600"
                >
                    ×
                </button>

            </div>

        </div>
    `;
};

App.render.option = function(
    groupId,
    questionId,
    option
) {

    return `
        <div class="flex gap-2">

            <input
                value="${App.utils.escape(
                    option.text
                )}"
                oninput="
                    App.actions.updateOption(
                        '${groupId}',
                        '${questionId}',
                        '${option.id}',
                        this.value
                    )
                "
                class="flex-1 rounded border px-3 py-2 bg-white"
            >

            <button
                onclick="
                    App.actions.deleteOption(
                        '${groupId}',
                        '${questionId}',
                        '${option.id}'
                    )
                "
                class="rounded border px-3 text-red-600"
            >
                ×
            </button>

        </div>
    `;
};

App.render.previewContent = function() {

    const survey =
        App.state.selectedSurvey;

    return `

        <div class="max-w-2xl mx-auto">

            <h1 class="text-2xl font-bold">
                ${App.utils.escape(
                    survey.title
                )}
            </h1>

            <p class="mt-2 text-sm text-gray-500">
                プレビューのため送信されません
            </p>

            ${
                survey.groups
                    .map(
                        group => `
                            <section class="mt-8">

                                <h2 class="font-bold text-lg border-b pb-2">
                                    ${App.utils.escape(
                                        group.name
                                    )}
                                </h2>

                                ${
                                    group.questions
                                        .map(
                                            q =>
                                                App.render.previewQuestion(
                                                    q
                                                )
                                        )
                                        .join('')
                                }

                            </section>
                        `
                    )
                    .join('')
            }

            <button
                onclick="
                    alert('プレビューのため送信されません')
                "
                class="mt-8 w-full rounded-lg bg-blue-600 text-white py-3"
            >
                送信する
            </button>

        </div>
    `;
};

App.render.previewQuestion = function(q) {

    let html = `
        <div class="mt-5">

            <div class="font-medium">
                ${App.utils.escape(
                    q.number || ''
                )}
                ${App.utils.escape(
                    q.text
                )}

                ${
                    q.required
                        ? '<span class="text-red-500"> *</span>'
                        : ''
                }

            </div>
    `;

    if (q.type === 'text') {

        html += `
            <textarea
                disabled
                class="mt-2 w-full rounded-lg border px-3 py-2"
                rows="3"
            ></textarea>
        `;

    } else {

        for (const option of q.options || []) {

            html += `
                <label class="flex gap-2 mt-2">

                    <input
                        disabled
                        type="${
                            q.type === 'multiple'
                                ? 'checkbox'
                                : 'radio'
                        }"
                    >

                    ${App.utils.escape(
                        option.text
                    )}

                </label>
            `;
        }
    }

    html += '</div>';

    return html;
};

App.render.send = function() {

    const survey =
        App.state.selectedSurvey;

    const customers =
        App.state.customers
            .filter(c =>
                !c.deleted
            );

    document.getElementById('app').innerHTML = `

        ${App.render.header(
            'アンケート一覧 › 顧客選択・送信'
        )}

        <div class="flex items-center justify-between mb-4">

            <h1 class="text-2xl font-bold">
                顧客選択・メール送信
            </h1>

            <button
                onclick="App.actions.goSurveyList()"
                class="rounded-lg border px-4 py-2"
            >
                戻る
            </button>

        </div>

        <div class="bg-white border rounded-xl p-5 mb-5">

            <div class="grid md:grid-cols-2 gap-4">

                <div>

                    <label class="block text-sm font-medium mb-1">
                        種別
                    </label>

                    <select
                        id="template_type"
                        class="w-full rounded-lg border px-3 py-2"
                    >

                        <option value="initial">
                            初回送信
                        </option>

                        <option value="reminder">
                            リマインド
                        </option>

                    </select>

                </div>

                <div>

                    <label class="block text-sm font-medium mb-1">
                        件名
                    </label>

                    <input
                        id="mail_subject"
                        value="アンケートのお願い"
                        class="w-full rounded-lg border px-3 py-2"
                    >

                </div>

            </div>

            <div class="mt-4">

                <label class="block text-sm font-medium mb-1">
                    本文
                </label>

                <textarea
                    id="mail_body"
                    rows="10"
                    class="w-full rounded-lg border px-3 py-2"
                >{顧客名} 様

アンケートへのご協力をお願いいたします。

以下のURLからご回答ください。

{アンケートURL}</textarea>

            </div>

        </div>

        <div class="bg-white border rounded-xl overflow-x-auto">

            <table class="w-full text-sm">

                <thead class="bg-gray-50 border-b">

                    <tr>

                        <th class="p-3">
                            <input
                                id="select_all"
                                type="checkbox"
                                onchange="
                                    document
                                        .querySelectorAll('.customer-checkbox')
                                        .forEach(
                                            e => e.checked=this.checked
                                        )
                                "
                            >
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

                        <th class="text-left p-3">
                            回答状態
                        </th>

                        <th class="text-left p-3">
                            最終送信
                        </th>

                        <th class="text-left p-3">
                            送信文
                        </th>

                    </tr>

                </thead>

                <tbody>

                    ${
                        customers
                            .map(
                                customer =>
                                    App.render.customerRow(
                                        customer
                                    )
                            )
                            .join('')
                    }

                </tbody>

            </table>

        </div>

        <div class="mt-4 flex gap-2">

            <button
                onclick="App.actions.sendMail()"
                class="rounded-lg bg-blue-600 text-white px-5 py-3"
            >
                一括送信
            </button>

            <button
                onclick="App.actions.sendReminder()"
                class="rounded-lg border px-5 py-3"
            >
                未回答者へリマインド
            </button>

        </div>
    `;
};

App.render.customerRow = function(customer) {

    const possible =
        customer.source === 'web' &&
        customer.kintone_status === 'unregistered';

    return `

        <tr class="border-b">

            <td class="p-3">

                <input
                    type="checkbox"
                    value="${App.utils.escape(
                        customer.id
                    )}"
                    class="customer-checkbox"
                >

            </td>

            <td class="p-3">
                ${App.utils.escape(
                    customer.company
                )}

                ${
                    possible
                        ? `
                            <span class="ml-2 text-xs bg-yellow-100 text-yellow-700 rounded px-2 py-1">
                                未登録
                            </span>
                        `
                        : ''
                }

            </td>

            <td class="p-3">
                ${App.utils.escape(
                    customer.name
                )}
            </td>

            <td class="p-3">
                ${App.utils.escape(
                    customer.email
                )}
            </td>

            <td class="p-3">
                ${App.utils.escape(
                    customer.answer_status
                )}
            </td>

            <td class="p-3">
                ${App.utils.escape(
                    customer.sent_at || ''
                )}
            </td>

            <td class="p-3">

                <button
                    onclick="
                        App.actions.showSentMail(
                            '${customer.id}'
                        )
                    "
                    class="text-blue-600 hover:underline"
                >
                    送信文を確認
                </button>

            </td>

        </tr>
    `;
};

App.render.analytics = function() {

    const data =
        App.state.selectedSurveyData;

    const survey =
        data.survey;

    document.getElementById('app').innerHTML = `

        ${App.render.header(
            'アンケート一覧 › 集計'
        )}

        <div class="flex items-center justify-between mb-4">

            <h1 class="text-2xl font-bold">
                回答集計
            </h1>

            <div class="flex gap-2">

                <button
                    onclick="
                        location.href =
                        location.pathname +
                        '?action=csv&survey_id=${encodeURIComponent(
                            survey.id
                        )}'
                    "
                    class="rounded-lg border px-4 py-2"
                >
                    CSV出力
                </button>

                <button
                    onclick="window.print()"
                    class="rounded-lg border px-4 py-2"
                >
                    PDF / 印刷
                </button>

                <button
                    onclick="App.actions.goSurveyList()"
                    class="rounded-lg border px-4 py-2"
                >
                    戻る
                </button>

            </div>

        </div>

        <div class="grid md:grid-cols-5 gap-3 mb-5">

            ${App.render.statCard(
                '送信対象者数',
                data.summary.sent_target_count
            )}

            ${App.render.statCard(
                '回答数',
                data.summary.response_count
            )}

            ${App.render.statCard(
                '未登録顧客回答',
                data.summary.unregistered_response_count
            )}

            ${App.render.statCard(
                '未回答数',
                data.summary.unanswered_count
            )}

            ${App.render.statCard(
                '回答率',
                data.summary.answer_rate + '%'
            )}

        </div>

        <div class="bg-white border rounded-xl p-5">

            <h2 class="text-lg font-bold">
                設問別集計
            </h2>

            <div class="mt-4 space-y-2">

                ${data.questions.map(
                    question =>
                        App.render.questionSummary(
                            question,
                            data.responses
                        )
                ).join('')}

            </div>

        </div>

        <div class="mt-5 bg-white border rounded-xl overflow-x-auto">

            <div class="p-4 border-b flex justify-between">

                <h2 class="font-bold">
                    個別回答一覧
                </h2>

                <span class="text-sm text-gray-500">
                    ${data.responses.length}件
                </span>

            </div>

            <table
                id="response_table"
                class="w-full text-sm"
            >

                <thead class="bg-gray-50 border-b">

                    <tr>

                        <th class="p-3 text-left">
                            回答者
                        </th>

                        <th class="p-3 text-left">
                            回答日時
                        </th>

                        <th class="p-3 text-left">
                            顧客ID
                        </th>

                        <th class="p-3">
                            操作
                        </th>

                    </tr>

                </thead>

                <tbody>

                    ${data.responses.map(
                        response =>
                            App.render.responseRow(
                                response
                            )
                    ).join('')}

                </tbody>

            </table>

        </div>

        <div
            id="response_modal"
            class="hidden fixed inset-0 z-50 bg-black/50"
        >

            <div
                class="h-full flex items-center justify-center p-4"
                onclick="
                    if(event.target===this)
                    App.actions.closeModal('response_modal')
                "
            >

                <div
                    class="bg-white rounded-2xl shadow-xl max-w-3xl w-full max-h-[90vh] overflow-auto"
                >

                    <div class="p-4 border-b flex justify-between">

                        <div class="font-bold">
                            全回答
                        </div>

                        <button
                            onclick="
                                App.actions.closeModal(
                                    'response_modal'
                                )
                            "
                        >
                            ×
                        </button>

                    </div>

                    <div
                        id="response_detail"
                        class="p-5"
                    ></div>

                </div>

            </div>

        </div>
    `;
};

App.render.statCard = function(
    label,
    value
) {

    return `
        <div class="bg-white border rounded-xl p-4">

            <div class="text-sm text-gray-500">
                ${App.utils.escape(label)}
            </div>

            <div class="text-2xl font-bold mt-2">
                ${App.utils.escape(value)}
            </div>

        </div>
    `;
};

App.render.questionSummary = function(
    question,
    responses
) {

    if (question.type === 'text') {

        const values =
            responses
                .map(
                    r =>
                        r.answers?.[question.id]
                )
                .filter(
                    v =>
                        v !== undefined &&
                        v !== ''
                );

        return `

            <div class="border rounded-lg p-4">

                <div class="font-medium">
                    ${App.utils.escape(
                        question.number
                    )}
                    ${App.utils.escape(
                        question.text
                    )}
                </div>

                <div class="text-sm text-gray-500 mt-1">
                    自由記述 ${values.length}件
                </div>

                <div class="mt-3 space-y-2">

                    ${
                        values
                            .map(
                                value => `
                                    <div class="bg-gray-50 rounded p-3">
                                        ${App.utils.escape(
                                            Array.isArray(value)
                                                ? value.join('、')
                                                : value
                                        )}
                                    </div>
                                `
                            )
                            .join('')
                    }

                </div>

            </div>
        `;
    }

    const counts = {};

    for (const option of question.options || []) {
        counts[option.id] = 0;
    }

    for (const response of responses) {

        const value =
            response.answers?.[
                question.id
            ];

        if (Array.isArray(value)) {

            value.forEach(
                v => {
                    if (
                        Object.hasOwn(
                            counts,
                            v
                        )
                    ) {
                        counts[v]++;
                    }
                }
            );

        } else if (
            value &&
            Object.hasOwn(counts, value)
        ) {
            counts[value]++;
        }
    }

    const total =
        responses.length || 1;

    return `

        <div class="border rounded-lg p-4">

            <div class="font-medium">
                ${App.utils.escape(
                    question.number
                )}
                ${App.utils.escape(
                    question.text
                )}
            </div>

            <div class="mt-4 space-y-3">

                ${
                    (question.options || [])
                        .map(
                            option => {

                                const count =
                                    counts[option.id] || 0;

                                const rate =
                                    Math.round(
                                        count /
                                        total *
                                        100
                                    );

                                return `

                                    <div>

                                        <div class="flex justify-between text-sm">

                                            <span>
                                                ${App.utils.escape(
                                                    option.text
                                                )}
                                            </span>

                                            <span>
                                                ${count}件
                                                /
                                                ${rate}%
                                            </span>

                                        </div>

                                        <div class="h-3 bg-gray-100 rounded mt-1">

                                            <div
                                                class="h-3 bg-blue-600 rounded"
                                                style="width:${rate}%"
                                            ></div>

                                        </div>

                                    </div>

                                `;
                            }
                        )
                        .join('')
                }

            </div>

        </div>
    `;
};

App.render.responseRow = function(response) {

    return `
        <tr class="border-b">

            <td class="p-3">
                ${App.utils.escape(
                    response.company
                )}
                /
                ${App.utils.escape(
                    response.name
                )}
            </td>

            <td class="p-3">
                ${App.utils.escape(
                    response.answered_at
                )}
            </td>

            <td class="p-3">
                ${App.utils.escape(
                    response.customer_id
                )}
            </td>

            <td class="p-3 text-center">

                <button
                    onclick="
                        App.actions.showAllResponses(
                            '${response.id}'
                        )
                    "
                    class="text-blue-600 hover:underline"
                >
                    全回答を表示
                </button>

                <button
                    onclick="
                        App.actions.deleteResponse(
                            '${response.id}'
                        )
                    "
                    class="ml-3 text-red-600 hover:underline"
                >
                    古い回答として削除
                </button>

            </td>

        </tr>
    `;
};

App.actions.deleteResponse = async function(
    responseId
) {

    if (!confirm(
        'この回答を論理削除しますか？'
    )) {
        return;
    }

    const result =
        await App.api.post(
            'delete_response',
            {
                response_id: responseId
            }
        );

    if (!result.ok) {
        alert(result.message);
        return;
    }

    await App.actions.goAnalytics(
        App.state.selectedSurvey.id
    );
};

App.render.responseDetail = function(
    response
) {

    let questions = [];

    for (
        const group
        of App.state.selectedSurvey.groups
    ) {
        questions.push(
            ...group.questions
        );
    }

    return `

        <div>

            <div class="grid md:grid-cols-2 gap-4 mb-6">

                <div>
                    <div class="text-sm text-gray-500">
                        会社名
                    </div>

                    <div class="font-medium">
                        ${App.utils.escape(
                            response.company
                        )}
                    </div>
                </div>

                <div>
                    <div class="text-sm text-gray-500">
                        氏名
                    </div>

                    <div class="font-medium">
                        ${App.utils.escape(
                            response.name
                        )}
                    </div>
                </div>

                <div>
                    <div class="text-sm text-gray-500">
                        回答日時
                    </div>

                    <div>
                        ${App.utils.escape(
                            response.answered_at
                        )}
                    </div>
                </div>

                <div>
                    <div class="text-sm text-gray-500">
                        回答ID
                    </div>

                    <div>
                        ${App.utils.escape(
                            response.id
                        )}
                    </div>
                </div>

            </div>

            <div class="space-y-4">

                ${
                    questions
                        .map(
                            question => {

                                let value =
                                    response.answers?.[
                                        question.id
                                    ] ?? '';

                                if (
                                    Array.isArray(value)
                                ) {
                                    value =
                                        value.join('、');
                                }

                                return `

                                    <div class="border-b pb-3">

                                        <div class="text-sm text-gray-500">
                                            ${App.utils.escape(
                                                question.number
                                            )}
                                        </div>

                                        <div class="font-medium">
                                            ${App.utils.escape(
                                                question.text
                                            )}
                                        </div>

                                        <div class="mt-1 whitespace-pre-wrap">
                                            ${App.utils.escape(
                                                value
                                            )}
                                        </div>

                                    </div>

                                `;
                            }
                        )
                        .join('')
                }

            </div>

        </div>
    `;
};

App.render.settings = function() {

    const s =
        App.state.settings || {};

    const fields =
        App.state.kintoneFields || [];

    const fieldSelect =
        function(
            setting,
            label
        ) {

            const value =
                s[setting] || '';

            return `
                <div>

                    <label class="block text-sm font-medium mb-1">
                        ${App.utils.escape(label)}
                    </label>

                    <select
                        data-setting="${setting}"
                        class="w-full rounded-lg border px-3 py-2"
                    >

                        <option value="">
                            未設定
                        </option>

                        ${
                            fields
                                .map(
                                    field => `
                                        <option
                                            value="${App.utils.escape(
                                                field.code
                                            )}"
                                            ${value === field.code
                                                ? 'selected'
                                                : ''}
                                        >
                                            ${App.utils.escape(
                                                field.label
                                            )}
                                            /
                                            ${App.utils.escape(
                                                field.code
                                            )}
                                            /
                                            ${App.utils.escape(
                                                field.type
                                            )}
                                        </option>
                                    `
                                )
                                .join('')
                        }

                    </select>

                </div>
            `;
        };

    document.getElementById('app').innerHTML = `

        ${App.render.header(
            'システム設定 › kintone・メール連携設定'
        )}

        <div class="flex items-center justify-between mb-4">

            <h1 class="text-2xl font-bold">
                システム設定
            </h1>

            <button
                onclick="App.actions.saveSettings()"
                class="rounded-lg bg-blue-600 text-white px-4 py-2"
            >
                設定を保存
            </button>

        </div>

        <form id="settings_form">

            <div class="bg-white border rounded-xl p-5">

                <h2 class="text-lg font-bold">
                    kintone設定
                </h2>

                <div class="grid md:grid-cols-2 gap-4 mt-4">

                    ${App.render.settingInput(
                        'subdomain',
                        'サブドメイン',
                        s.subdomain
                    )}

                    ${App.render.settingInput(
                        'app_id',
                        '顧客管理アプリID',
                        s.app_id
                    )}

                    ${App.render.settingInput(
                        'login_name',
                        'ログイン名',
                        s.login_name
                    )}

                    <div>

                        <label class="block text-sm font-medium mb-1">
                            パスワード
                        </label>

                        <input
                            type="password"
                            data-setting="password"
                            value=""
                            autocomplete="new-password"
                            class="w-full rounded-lg border px-3 py-2"
                        >

                    </div>

                    ${App.render.settingInput(
                        'proxy',
                        'Proxy host:port',
                        s.proxy
                    )}

                    <div>

                        <label class="inline-flex items-center gap-2 mt-7">

                            <input
                                type="checkbox"
                                data-setting="ssl_verify"
                                ${s.ssl_verify !== false
                                    ? 'checked'
                                    : ''}
                            >

                            SSL証明書検証を有効にする

                        </label>

                    </div>

                </div>

                <div class="mt-5 flex gap-2">

                    <button
                        type="button"
                        onclick="App.actions.kintoneConnectionTest()"
                        class="rounded-lg border px-4 py-2"
                    >
                        kintone接続確認
                    </button>

                    <button
                        type="button"
                        onclick="App.actions.fetchKintoneFields()"
                        class="rounded-lg border px-4 py-2"
                    >
                        フィールド取得
                    </button>

                    <button
                        type="button"
                        onclick="App.actions.syncCustomers()"
                        class="rounded-lg border px-4 py-2"
                    >
                        顧客データを同期
                    </button>

                </div>

                <div
                    id="field_message"
                    class="mt-3 text-sm text-gray-500"
                ></div>

                <div class="mt-6 grid md:grid-cols-2 gap-4">

                    ${fieldSelect(
                        'field_company',
                        '会社名'
                    )}

                    ${fieldSelect(
                        'field_name',
                        '氏名'
                    )}

                    ${fieldSelect(
                        'field_email',
                        'メールアドレス'
                    )}

                    ${fieldSelect(
                        'field_department',
                        '部署名'
                    )}

                    ${fieldSelect(
                        'field_phone',
                        '電話番号'
                    )}

                    ${fieldSelect(
                        'field_address',
                        '住所'
                    )}

                </div>

            </div>

            <div class="bg-white border rounded-xl p-5 mt-5">

                <h2 class="text-lg font-bold">
                    SMTP設定
                </h2>

                <div class="grid md:grid-cols-2 gap-4 mt-4">

                    ${App.render.settingInput(
                        'smtp_server',
                        'SMTPサーバ',
                        s.smtp_server
                    )}

                    ${App.render.settingInput(
                        'smtp_port',
                        'SMTPポート',
                        s.smtp_port || 587
                    )}

                    ${App.render.settingInput(
                        'smtp_encryption',
                        '暗号化方式（tls / ssl / none）',
                        s.smtp_encryption || 'tls'
                    )}

                    ${App.render.settingInput(
                        'smtp_username',
                        'SMTPユーザー名',
                        s.smtp_username
                    )}

                    <div>

                        <label class="block text-sm font-medium mb-1">
                            SMTPパスワード
                        </label>

                        <input
                            type="password"
                            data-setting="smtp_password"
                            value=""
                            autocomplete="new-password"
                            class="w-full rounded-lg border px-3 py-2"
                        >

                    </div>

                    ${App.render.settingInput(
                        'smtp_from_email',
                        '送信元メールアドレス',
                        s.smtp_from_email
                    )}

                    ${App.render.settingInput(
                        'smtp_from_name',
                        '送信元表示名',
                        s.smtp_from_name
                    )}

                    ${App.render.settingInput(
                        'smtp_timeout',
                        '接続タイムアウト',
                        s.smtp_timeout || 20
                    )}

                </div>

                <div class="mt-5">

                    <button
                        type="button"
                        onclick="App.actions.smtpTest()"
                        class="rounded-lg border px-4 py-2"
                    >
                        SMTPテスト送信
                    </button>

                </div>

            </div>

        </form>
    `;
};

App.render.settingInput = function(
    setting,
    label,
    value
) {

    return `
        <div>

            <label class="block text-sm font-medium mb-1">
                ${App.utils.escape(label)}
            </label>

            <input
                data-setting="${setting}"
                value="${App.utils.escape(
                    value ?? ''
                )}"
                class="w-full rounded-lg border px-3 py-2"
            >

        </div>
    `;
};

App.init = async function() {

    if (App.state.initialized) {
        return;
    }

    App.state.initialized = true;

    try {

        await App.actions.loadBootstrap();

        App.render.list();

    } catch (error) {

        document.getElementById(
            'app'
        ).innerHTML = `
            <div class="bg-red-50 text-red-700 rounded-xl p-5">
                ${App.utils.escape(
                    error.message
                )}
            </div>
        `;
    }
};

App.login = async function(
    event,
    form
) {

    event.preventDefault();

    const message =
        document.getElementById(
            'login_message'
        );

    const data =
        new FormData(form);

    const body =
        new URLSearchParams();

    body.set(
        'action',
        'login'
    );

    body.set(
        'login_name',
        data.get('login_name')
    );

    body.set(
        'login_password',
        data.get('login_password')
    );

    try {

        const response =
            await fetch(
                location.pathname,
                {
                    method: 'POST',
                    body,
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type':
                            'application/x-www-form-urlencoded'
                    }
                }
            );

        const result =
            await response.json();

        if (!result.ok) {

            message.textContent =
                result.message ||
                'ログインに失敗しました。';

            return false;
        }

        location.reload();

    } catch (error) {

        message.textContent =
            '通信エラーが発生しました。';
    }

    return false;
};

if (document.readyState === 'loading') {

    document.addEventListener(
        'DOMContentLoaded',
        () => App.init(),
        { once: true }
    );

} else {

    App.init();

}

document.addEventListener(
    'keydown',
    event => {

        if (event.key !== 'Escape') {
            return;
        }

        document
            .querySelectorAll(
                '#preview_modal,#response_modal'
            )
            .forEach(
                modal =>
                    modal.classList.add('hidden')
            );

    }
);

</script>

</body>
</html>
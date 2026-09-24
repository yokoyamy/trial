<?php
declare(strict_types=1);

namespace jacic\gojacic\newapp;

const APP_SESSION_KEY = 'jacic_gojacic_newapp';
const DATA_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const SETTINGS_FILE = DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'settings.json';
const CUSTOMERS_FILE = DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'customers.json';
const SURVEYS_FILE = DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'surveys.json';
const RESPONSES_FILE = DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'responses.json';
const MAIL_LOGS_FILE = DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'mail_logs.json';

date_default_timezone_set('Asia/Tokyo');

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax'
]);

if (!isset($_SESSION[APP_SESSION_KEY]) || !is_array($_SESSION[APP_SESSION_KEY])) {
    $_SESSION[APP_SESSION_KEY] = [];
}

if (
    !isset($_SESSION[APP_SESSION_KEY]['csrf_token']) ||
    !is_string($_SESSION[APP_SESSION_KEY]['csrf_token']) ||
    strlen($_SESSION[APP_SESSION_KEY]['csrf_token']) !== 64
) {
    $_SESSION[APP_SESSION_KEY]['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * 安全な文字列エスケープ
 */
function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    $token = $_SESSION[APP_SESSION_KEY]['csrf_token'] ?? '';

    if (!is_string($token) || $token === '') {
        json_response(false, 'セキュリティ情報を取得できません。', [], [], 500);
    }

    return $token;
}

/**
 * JSONレスポンス
 */
function json_response(
    bool $success,
    string $message,
    array $data = [],
    array $errors = [],
    int $status = 200
): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    $response = [
        'success' => $success,
        'message' => $message
    ];

    if ($data !== []) {
        $response['data'] = $data;
    }

    if ($errors !== []) {
        $response['errors'] = $errors;
    }

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function requested_action(): string
{
    $action = $_GET['action'] ?? '';

    return is_string($action) ? trim($action) : '';
}

function read_json_request(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        json_response(
            false,
            '入力内容を確認してください。',
            [],
            ['request' => 'JSON形式が正しくありません。'],
            400
        );
    }

    return $data;
}

function validate_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($token) || $token === '') {
        json_response(
            false,
            'セキュリティ確認に失敗しました。画面を再読み込みしてください。',
            [],
            [],
            403
        );
    }

    if (!hash_equals(csrf_token(), $token)) {
        json_response(
            false,
            'セキュリティ確認に失敗しました。画面を再読み込みしてください。',
            [],
            [],
            403
        );
    }
}

function ensure_data_directory(): void
{
    if (is_dir(DATA_DIRECTORY)) {
        return;
    }

    if (!mkdir(DATA_DIRECTORY, 0700, true) && !is_dir(DATA_DIRECTORY)) {
        json_response(
            false,
            'データ保存領域を作成できません。',
            [],
            [],
            500
        );
    }
}

function default_settings(): array
{
    return [
        'version' => 1,
        'updated_at' => null,
        'mail' => [
            'smtp_server' => '',
            'smtp_port' => 587,
            'connection_type' => 'tls',
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => 'アンケート事務局',
            'configured' => false,
            'tested_at' => null
        ],
        'kintone' => [
            'domain' => '',
            'login_name' => '',
            'password' => '',
            'customer_app_id' => '',
            'customer_id_field' => '',
            'customer_name_field' => '',
            'customer_email_field' => '',
            'proxy_host' => '',
            'proxy_port' => '',
            'configured' => false,
            'connection_status' => 'not_configured',
            'tested_at' => null
        ]
    ];
}

function default_customers(): array
{
    return [
        'version' => 1,
        'updated_at' => null,
        'source' => 'kintone',
        'count' => 0,
        'customers' => []
    ];
}

function default_surveys(): array
{
    return [
        'version' => 1,
        'updated_at' => null,
        'surveys' => []
    ];
}

function default_responses(): array
{
    return [
        'version' => 1,
        'updated_at' => null,
        'responses' => []
    ];
}

function default_mail_logs(): array
{
    return [
        'version' => 1,
        'updated_at' => null,
        'logs' => []
    ];
}

function read_json_file(string $file, array $default): array
{
    ensure_data_directory();

    if (!file_exists($file)) {
        return $default;
    }

    $contents = file_get_contents($file);

    if ($contents === false || trim($contents) === '') {
        return $default;
    }

    $data = json_decode($contents, true);

    if (!is_array($data)) {
        json_response(
            false,
            '保存されているデータを読み込めません。',
            [],
            [],
            500
        );
    }

    return $data;
}

function write_json_file(string $file, array $data): void
{
    ensure_data_directory();

    try {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT |
            JSON_THROW_ON_ERROR
        );
    } catch (\Throwable $e) {
        json_response(
            false,
            'データを保存できません。',
            [],
            [],
            500
        );
    }

    $tmp = tempnam(DATA_DIRECTORY, 'newapp_');

    if ($tmp === false) {
        json_response(
            false,
            'データを保存できません。',
            [],
            [],
            500
        );
    }

    $fp = fopen($tmp, 'wb');

    if ($fp === false) {
        @unlink($tmp);

        json_response(
            false,
            'データを保存できません。',
            [],
            [],
            500
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new \RuntimeException('lock');
        }

        $length = strlen($json);
        $written = fwrite($fp, $json);

        if ($written === false || $written !== $length) {
            throw new \RuntimeException('write');
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!rename($tmp, $file)) {
            throw new \RuntimeException('rename');
        }
    } catch (\Throwable $e) {
        if (is_resource($fp)) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }

        @unlink($tmp);

        json_response(
            false,
            'データを保存できません。',
            [],
            [],
            500
        );
    }
}

function initialize_data_files(): void
{
    ensure_data_directory();

    if (!file_exists(SETTINGS_FILE)) {
        write_json_file(SETTINGS_FILE, default_settings());
    }

    if (!file_exists(CUSTOMERS_FILE)) {
        write_json_file(CUSTOMERS_FILE, default_customers());
    }

    if (!file_exists(SURVEYS_FILE)) {
        write_json_file(SURVEYS_FILE, default_surveys());
    }

    if (!file_exists(RESPONSES_FILE)) {
        write_json_file(RESPONSES_FILE, default_responses());
    }

    if (!file_exists(MAIL_LOGS_FILE)) {
        write_json_file(MAIL_LOGS_FILE, default_mail_logs());
    }
}

function load_settings(): array
{
    return read_json_file(SETTINGS_FILE, default_settings());
}

function load_customers(): array
{
    return read_json_file(CUSTOMERS_FILE, default_customers());
}

function load_surveys(): array
{
    return read_json_file(SURVEYS_FILE, default_surveys());
}

function load_responses(): array
{
    return read_json_file(RESPONSES_FILE, default_responses());
}

function load_mail_logs(): array
{
    return read_json_file(MAIL_LOGS_FILE, default_mail_logs());
}

function now(): string
{
    return date('c');
}

function generate_id(string $prefix): string
{
    return $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
}

function application_api_path(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? 'index.php';

    if (!is_string($script) || $script === '') {
        return 'index.php';
    }

    return $script;
}

/* =========================================================
 * kintone
 * ========================================================= */

function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain);
    $domain = rtrim($domain, '/');

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

function make_cybozu_auth_header(
    string $login_name,
    string $password
): string {
    $login_name = trim($login_name);
    $password = trim($password);

    return 'X-Cybozu-Authorization: ' .
        base64_encode($login_name . ':' . $password);
}

function get_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();

        if (is_array($headers)) {
            return $headers;
        }
    }

    return [];
}

function extract_http_status(array $headers): int
{
    foreach ($headers as $header) {
        if (
            is_string($header) &&
            preg_match(
                '/HTTP\/\d(?:\.\d)?\s+(\d{3})/i',
                $header,
                $matches
            )
        ) {
            return (int)$matches[1];
        }
    }

    return 0;
}

function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    array $payload,
    array $config
): array {
    $method = strtoupper($method);

    $options = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20
    ];

    if ($method === 'GET') {
        /*
         * GETではcontentを設定しない。
         */
    } elseif ($payload !== []) {
        try {
            $options['content'] = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 0,
                'message' => '通信データを作成できませんでした。',
                'data' => []
            ];
        }
    }

    $contextOptions = [
        'http' => $options,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];

    /*
     * プロキシはhost:port形式。
     * 設定されている場合は必ずstream_contextへ適用する。
     */
    $proxyHost = trim((string)($config['proxy_host'] ?? ''));
    $proxyPort = trim((string)($config['proxy_port'] ?? ''));

    if ($proxyHost !== '') {
        $proxy = $proxyHost;

        if ($proxyPort !== '') {
            $proxy .= ':' . $proxyPort;
        }

        $contextOptions['http']['proxy'] = 'tcp://' . $proxy;
        $contextOptions['http']['request_fulluri'] = true;
    } else {
        /*
         * プロキシ未指定でもキーを明示的に保持。
         * 実際の通信には空プロキシを渡さない。
         */
        $contextOptions['http']['request_fulluri'] = false;
    }

    $context = stream_context_create($contextOptions);

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    $headersResult = get_safe_response_headers();
    $status = extract_http_status($headersResult);

    $decoded = [];

    if ($response !== false && trim($response) !== '') {
        $decodedValue = json_decode($response, true);

        if (is_array($decodedValue)) {
            $decoded = $decodedValue;
        }
    }

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'status' => $status,
            'message' => '',
            'data' => $decoded
        ];
    }

    $message = 'kintoneとの通信に失敗しました。';

    if (
        isset($decoded['message']) &&
        is_string($decoded['message']) &&
        $decoded['message'] !== ''
    ) {
        $message = $decoded['message'];
    }

    $errors = [];

    if (isset($decoded['errors']) && is_array($decoded['errors'])) {
        foreach ($decoded['errors'] as $field => $error) {
            if (!is_array($error)) {
                continue;
            }

            $messages = $error['messages'] ?? [];

            if (is_array($messages)) {
                foreach ($messages as $errorMessage) {
                    if (is_string($errorMessage)) {
                        $errors[] = (string)$field . ': ' . $errorMessage;
                    }
                }
            }
        }
    }

    return [
        'success' => false,
        'status' => $status,
        'message' => $message,
        'errors' => $errors,
        'data' => $decoded
    ];
}

function test_kintone(array $settings): array
{
    $domain = trim((string)($settings['domain'] ?? ''));
    $login = trim((string)($settings['login_name'] ?? ''));
    $password = (string)($settings['password'] ?? '');
    $appId = trim((string)($settings['customer_app_id'] ?? ''));

    if ($domain === '' || $login === '' || $password === '') {
        return [
            'success' => false,
            'message' => 'kintoneのドメイン、ログイン名、パスワードを入力してください。'
        ];
    }

    $headers = [
        make_cybozu_auth_header($login, $password),
        'Accept: application/json'
    ];

    if ($appId !== '') {
        $url = kintone_build_url(
            $domain,
            '/k/v1/app.json?id=' . rawurlencode($appId)
        );
    } else {
        $url = kintone_build_url(
            $domain,
            '/k/v1/apps.json?limit=1'
        );
    }

    return kintone_api_request(
        'GET',
        $url,
        $headers,
        [],
        [
            'proxy_host' => trim((string)($settings['proxy_host'] ?? '')),
            'proxy_port' => trim((string)($settings['proxy_port'] ?? ''))
        ]
    );
}

function fetch_kintone_customers(array $settings): array
{
    $domain = trim((string)($settings['domain'] ?? ''));
    $login = trim((string)($settings['login_name'] ?? ''));
    $password = (string)($settings['password'] ?? '');
    $appId = trim((string)($settings['customer_app_id'] ?? ''));

    if ($domain === '' || $login === '' || $password === '' || $appId === '') {
        return [
            'success' => false,
            'message' => 'kintone設定が不足しています。'
        ];
    }

    $headers = [
        make_cybozu_auth_header($login, $password),
        'Accept: application/json'
    ];

    $params = [
        'app' => $appId,
        'totalCount' => 'true',
        'query' => 'order by $id asc limit 500'
    ];

    $url = kintone_build_url(
        $domain,
        '/k/v1/records.json?' .
        http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        )
    );

    $result = kintone_api_request(
        'GET',
        $url,
        $headers,
        [],
        [
            'proxy_host' => trim((string)($settings['proxy_host'] ?? '')),
            'proxy_port' => trim((string)($settings['proxy_port'] ?? ''))
        ]
    );

    if (!$result['success']) {
        return $result;
    }

    $records = $result['data']['records'] ?? [];

    if (!is_array($records)) {
        $records = [];
    }

    $idField = trim((string)($settings['customer_id_field'] ?? ''));
    $nameField = trim((string)($settings['customer_name_field'] ?? ''));
    $emailField = trim((string)($settings['customer_email_field'] ?? ''));

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $id = '';
        $name = '';
        $email = '';

        if ($idField !== '' && isset($record[$idField]['value'])) {
            $id = (string)$record[$idField]['value'];
        }

        if ($nameField !== '' && isset($record[$nameField]['value'])) {
            $name = (string)$record[$nameField]['value'];
        }

        if ($emailField !== '' && isset($record[$emailField]['value'])) {
            $email = (string)$record[$emailField]['value'];
        }

        $customers[] = [
            'id' => $id !== '' ? $id : generate_id('customer'),
            'name' => $name,
            'email' => $email,
            'company' => '',
            'code' => $id
        ];
    }

    return [
        'success' => true,
        'message' => '顧客一覧を更新しました。',
        'customers' => $customers
    ];
}

/* =========================================================
 * アンケート補助
 * ========================================================= */

function survey_find(array $surveys, string $id): ?array
{
    foreach ($surveys as $survey) {
        if (
            is_array($survey) &&
            (string)($survey['id'] ?? '') === $id
        ) {
            return $survey;
        }
    }

    return null;
}

function survey_index(array $surveys, string $id): int
{
    foreach ($surveys as $index => $survey) {
        if (
            is_array($survey) &&
            (string)($survey['id'] ?? '') === $id
        ) {
            return $index;
        }
    }

    return -1;
}

function normalize_question(array $question): array
{
    $type = (string)($question['type'] ?? 'free');

    if (!in_array($type, ['free', 'single', 'multiple'], true)) {
        $type = 'free';
    }

    $options = [];

    if (isset($question['options']) && is_array($question['options'])) {
        foreach ($question['options'] as $option) {
            if (!is_array($option)) {
                continue;
            }

            $options[] = [
                'text' => (string)($option['text'] ?? ''),
                'branch' => (string)($option['branch'] ?? '')
            ];
        }
    }

    return [
        'id' => (string)($question['id'] ?? generate_id('q')),
        'text' => (string)($question['text'] ?? ''),
        'type' => $type,
        'required' => !empty($question['required']),
        'options' => $options
    ];
}

function normalize_survey(array $survey): array
{
    $groups = [];

    if (isset($survey['groups']) && is_array($survey['groups'])) {
        foreach ($survey['groups'] as $group) {
            if (!is_array($group)) {
                continue;
            }

            $questions = [];

            if (isset($group['questions']) && is_array($group['questions'])) {
                foreach ($group['questions'] as $question) {
                    if (is_array($question)) {
                        $questions[] = normalize_question($question);
                    }
                }
            }

            $groups[] = [
                'id' => (string)($group['id'] ?? generate_id('g')),
                'name' => (string)($group['name'] ?? 'グループ'),
                'questions' => $questions
            ];
        }
    }

    return [
        'id' => (string)($survey['id'] ?? generate_id('survey')),
        'name' => trim((string)($survey['name'] ?? '')),
        'description' => (string)($survey['description'] ?? ''),
        'status' => in_array(
            (string)($survey['status'] ?? 'draft'),
            ['draft', 'open', 'end'],
            true
        ) ? (string)$survey['status'] : 'draft',
        'created' => (string)($survey['created'] ?? date('Y-m-d')),
        'start' => (string)($survey['start'] ?? ''),
        'end' => (string)($survey['end'] ?? ''),
        'answers' => (int)($survey['answers'] ?? 0),
        'target' => (int)($survey['target'] ?? 0),
        'sent' => (int)($survey['sent'] ?? 0),
        'updated' => (string)($survey['updated'] ?? date('Y-m-d')),
        'numbering' => (
            (string)($survey['numbering'] ?? 'global') === 'group'
            ? 'group'
            : 'global'
        ),
        'groups' => $groups
    ];
}

function calculate_survey_counts(
    array $survey,
    array $responses
): array {
    $surveyId = (string)($survey['id'] ?? '');
    $count = 0;

    foreach ($responses as $response) {
        if (
            is_array($response) &&
            (string)($response['survey_id'] ?? '') === $surveyId
        ) {
            $count++;
        }
    }

    return [
        'answers' => $count,
        'target' => (int)($survey['target'] ?? 0)
    ];
}

/* =========================================================
 * API
 * ========================================================= */

initialize_data_files();

$action = requested_action();

if ($action !== '') {
    if (request_method() === 'POST') {
        validate_csrf();
    }

    switch ($action) {
        case 'load_settings':
            $settings = load_settings();

            /*
             * パスワードは画面へ返さない。
             */
            $settings['mail']['password'] = '';
            $settings['kintone']['password'] = '';

            json_response(
                true,
                '設定を取得しました。',
                ['settings' => $settings]
            );

        case 'save_mail_settings':
            $input = read_json_request();
            $settings = load_settings();

            $mail = [
                'smtp_server' => trim((string)($input['smtp_server'] ?? '')),
                'smtp_port' => (int)($input['smtp_port'] ?? 587),
                'connection_type' => trim(
                    (string)($input['connection_type'] ?? 'tls')
                ),
                'username' => trim((string)($input['username'] ?? '')),
                'password' => (string)($input['password'] ?? ''),
                'from_email' => trim((string)($input['from_email'] ?? '')),
                'from_name' => trim(
                    (string)($input['from_name'] ?? 'アンケート事務局')
                ),
                'configured' => true,
                'tested_at' => $settings['mail']['tested_at'] ?? null
            ];

            if ($mail['password'] === '') {
                $mail['password'] =
                    (string)($settings['mail']['password'] ?? '');
            }

            if ($mail['smtp_server'] === '') {
                json_response(
                    false,
                    'SMTPサーバーを入力してください。',
                    [],
                    [],
                    400
                );
            }

            if ($mail['from_email'] === '') {
                json_response(
                    false,
                    '送信元メールアドレスを入力してください。',
                    [],
                    [],
                    400
                );
            }

            $settings['mail'] = $mail;
            $settings['updated_at'] = now();

            write_json_file(SETTINGS_FILE, $settings);

            json_response(
                true,
                'メール送信設定を保存しました。'
            );

        case 'test_mail_settings':
            $input = read_json_request();

            $smtp = trim((string)($input['smtp_server'] ?? ''));
            $port = (int)($input['smtp_port'] ?? 0);
            $from = trim((string)($input['from_email'] ?? ''));

            if ($smtp === '' || $port <= 0 || $from === '') {
                json_response(
                    false,
                    'SMTPサーバー、ポート、送信元メールアドレスを確認してください。',
                    [],
                    [],
                    400
                );
            }

            /*
             * SMTP実送信を行う環境依存ライブラリは使用しない。
             * 設定値の妥当性確認として扱う。
             */
            json_response(
                true,
                'メール送信設定を確認しました。'
            );

        case 'save_kintone_settings':
            $input = read_json_request();
            $settings = load_settings();

            $password = (string)($input['password'] ?? '');

            if ($password === '') {
                $password =
                    (string)($settings['kintone']['password'] ?? '');
            }

            $kintone = [
                'domain' => trim((string)($input['domain'] ?? '')),
                'login_name' => trim(
                    (string)($input['login_name'] ?? '')
                ),
                'password' => $password,
                'customer_app_id' => trim(
                    (string)($input['customer_app_id'] ?? '')
                ),
                'customer_id_field' => trim(
                    (string)($input['customer_id_field'] ?? '')
                ),
                'customer_name_field' => trim(
                    (string)($input['customer_name_field'] ?? '')
                ),
                'customer_email_field' => trim(
                    (string)($input['customer_email_field'] ?? '')
                ),
                'proxy_host' => trim(
                    (string)($input['proxy_host'] ?? '')
                ),
                'proxy_port' => trim(
                    (string)($input['proxy_port'] ?? '')
                ),
                'configured' => true,
                'connection_status' =>
                    $settings['kintone']['connection_status']
                    ?? 'not_configured',
                'tested_at' =>
                    $settings['kintone']['tested_at']
                    ?? null
            ];

            if ($kintone['domain'] === '') {
                json_response(
                    false,
                    'kintoneドメインを入力してください。',
                    [],
                    [],
                    400
                );
            }

            if ($kintone['login_name'] === '') {
                json_response(
                    false,
                    'kintoneログイン名を入力してください。',
                    [],
                    [],
                    400
                );
            }

            if ($kintone['password'] === '') {
                json_response(
                    false,
                    'kintoneパスワードを入力してください。',
                    [],
                    [],
                    400
                );
            }

            $settings['kintone'] = $kintone;
            $settings['updated_at'] = now();

            write_json_file(SETTINGS_FILE, $settings);

            json_response(
                true,
                'kintone設定を保存しました。'
            );

        case 'test_kintone_connection':
            $input = read_json_request();

            if ($input === []) {
                $settings = load_settings();
                $input = $settings['kintone'];
            }

            $result = test_kintone($input);

            if (!$result['success']) {
                json_response(
                    false,
                    (string)($result['message'] ?? 'kintone接続に失敗しました。'),
                    [
                        'status' => (int)($result['status'] ?? 0)
                    ],
                    isset($result['errors']) && is_array($result['errors'])
                        ? $result['errors']
                        : [],
                    400
                );
            }

            $settings = load_settings();
            $settings['kintone']['configured'] = true;
            $settings['kintone']['connection_status'] = 'connected';
            $settings['kintone']['tested_at'] = now();

            write_json_file(SETTINGS_FILE, $settings);

            json_response(
                true,
                'kintoneへの接続を確認しました。'
            );

        case 'load_customers':
            $customers = load_customers();

            json_response(
                true,
                '顧客一覧を取得しました。',
                [
                    'customers' =>
                        is_array($customers['customers'] ?? null)
                        ? $customers['customers']
                        : []
                ]
            );

        case 'refresh_customers':
            $settings = load_settings();

            $result = fetch_kintone_customers(
                $settings['kintone'] ?? []
            );

            if (!$result['success']) {
                json_response(
                    false,
                    (string)($result['message'] ?? '顧客一覧を更新できませんでした。'),
                    [],
                    isset($result['errors']) && is_array($result['errors'])
                        ? $result['errors']
                        : [],
                    400
                );
            }

            $customerData = [
                'version' => 1,
                'updated_at' => now(),
                'source' => 'kintone',
                'count' => count($result['customers']),
                'customers' => $result['customers']
            ];

            write_json_file(
                CUSTOMERS_FILE,
                $customerData
            );

            json_response(
                true,
                '顧客一覧を更新しました。',
                [
                    'customers' => $result['customers']
                ]
            );

        case 'load_surveys':
            $data = load_surveys();
            $responses = load_responses();

            $surveys = [];

            foreach (($data['surveys'] ?? []) as $survey) {
                if (!is_array($survey)) {
                    continue;
                }

                $counts = calculate_survey_counts(
                    $survey,
                    $responses['responses'] ?? []
                );

                $survey['answers'] = $counts['answers'];

                $surveys[] = $survey;
            }

            json_response(
                true,
                'アンケート一覧を取得しました。',
                ['surveys' => $surveys]
            );

        case 'load_survey':
            $id = trim((string)($_GET['id'] ?? ''));

            if ($id === '') {
                json_response(
                    false,
                    'アンケートを指定してください。',
                    [],
                    [],
                    400
                );
            }

            $data = load_surveys();
            $survey = survey_find(
                $data['surveys'] ?? [],
                $id
            );

            if ($survey === null) {
                json_response(
                    false,
                    '指定されたアンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            json_response(
                true,
                'アンケートを取得しました。',
                ['survey' => $survey]
            );

        case 'save_survey':
            $input = read_json_request();

            $surveyInput = $input['survey'] ?? null;

            if (!is_array($surveyInput)) {
                json_response(
                    false,
                    'アンケート内容を確認してください。',
                    [],
                    [],
                    400
                );
            }

            $survey = normalize_survey($surveyInput);

            if ($survey['name'] === '') {
                json_response(
                    false,
                    'アンケート名を入力してください。',
                    [],
                    [],
                    400
                );
            }

            if ($survey['groups'] === []) {
                json_response(
                    false,
                    'グループを1つ以上作成してください。',
                    [],
                    [],
                    400
                );
            }

            foreach ($survey['groups'] as $group) {
                if (($group['name'] ?? '') === '') {
                    json_response(
                        false,
                        'グループ名を入力してください。',
                        [],
                        [],
                        400
                    );
                }
            }

            $data = load_surveys();

            if (!isset($data['surveys']) || !is_array($data['surveys'])) {
                $data['surveys'] = [];
            }

            $index = survey_index(
                $data['surveys'],
                $survey['id']
            );

            if ($index >= 0) {
                $old = $data['surveys'][$index];

                $survey['created'] =
                    (string)($old['created'] ?? $survey['created']);

                $survey['updated'] = date('Y-m-d');

                $data['surveys'][$index] = $survey;
            } else {
                $survey['created'] = date('Y-m-d');
                $survey['updated'] = date('Y-m-d');

                $data['surveys'][] = $survey;
            }

            $data['updated_at'] = now();

            write_json_file(
                SURVEYS_FILE,
                $data
            );

            json_response(
                true,
                'アンケートを保存しました。',
                ['survey' => $survey]
            );

        case 'delete_survey':
            $input = read_json_request();
            $id = trim((string)($input['id'] ?? ''));

            $data = load_surveys();
            $index = survey_index(
                $data['surveys'] ?? [],
                $id
            );

            if ($index < 0) {
                json_response(
                    false,
                    'アンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            if (($data['surveys'][$index]['status'] ?? 'draft') !== 'draft') {
                json_response(
                    false,
                    '下書きのアンケートだけ削除できます。',
                    [],
                    [],
                    400
                );
            }

            array_splice(
                $data['surveys'],
                $index,
                1
            );

            $data['updated_at'] = now();

            write_json_file(
                SURVEYS_FILE,
                $data
            );

            json_response(
                true,
                'アンケートを削除しました。'
            );

        case 'publish_survey':
            $input = read_json_request();
            $id = trim((string)($input['id'] ?? ''));

            $data = load_surveys();
            $index = survey_index(
                $data['surveys'] ?? [],
                $id
            );

            if ($index < 0) {
                json_response(
                    false,
                    'アンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            $survey = $data['surveys'][$index];

            if (trim((string)($survey['name'] ?? '')) === '') {
                json_response(
                    false,
                    'アンケート名が設定されていません。',
                    [],
                    [],
                    400
                );
            }

            $survey['status'] = 'open';
            $survey['updated'] = date('Y-m-d');

            $data['surveys'][$index] = $survey;
            $data['updated_at'] = now();

            write_json_file(
                SURVEYS_FILE,
                $data
            );

            json_response(
                true,
                'アンケートを公開しました。',
                ['survey' => $survey]
            );

        case 'close_survey':
            $input = read_json_request();
            $id = trim((string)($input['id'] ?? ''));

            $data = load_surveys();
            $index = survey_index(
                $data['surveys'] ?? [],
                $id
            );

            if ($index < 0) {
                json_response(
                    false,
                    'アンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            $data['surveys'][$index]['status'] = 'end';
            $data['surveys'][$index]['updated'] = date('Y-m-d');
            $data['updated_at'] = now();

            write_json_file(
                SURVEYS_FILE,
                $data
            );

            json_response(
                true,
                'アンケートを終了しました。',
                ['survey' => $data['surveys'][$index]]
            );

        case 'load_mail_logs':
            $data = load_mail_logs();

            json_response(
                true,
                '送信履歴を取得しました。',
                [
                    'logs' =>
                        is_array($data['logs'] ?? null)
                        ? $data['logs']
                        : []
                ]
            );

        case 'send_survey':
            $input = read_json_request();

            $surveyId = trim(
                (string)($input['survey_id'] ?? '')
            );

            $customerIds =
                isset($input['customer_ids']) &&
                is_array($input['customer_ids'])
                    ? $input['customer_ids']
                    : [];

            if ($surveyId === '') {
                json_response(
                    false,
                    'アンケートを指定してください。',
                    [],
                    [],
                    400
                );
            }

            if ($customerIds === []) {
                json_response(
                    false,
                    '送信対象を選択してください。',
                    [],
                    [],
                    400
                );
            }

            $surveyData = load_surveys();
            $survey = survey_find(
                $surveyData['surveys'] ?? [],
                $surveyId
            );

            if ($survey === null) {
                json_response(
                    false,
                    'アンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            if (($survey['status'] ?? '') !== 'open') {
                json_response(
                    false,
                    '公開中のアンケートだけ送信できます。',
                    [],
                    [],
                    400
                );
            }

            $customerData = load_customers();
            $customers =
                is_array($customerData['customers'] ?? null)
                ? $customerData['customers']
                : [];

            $logs = load_mail_logs();

            if (!isset($logs['logs']) || !is_array($logs['logs'])) {
                $logs['logs'] = [];
            }

            $sent = 0;

            foreach ($customers as $customer) {
                if (!is_array($customer)) {
                    continue;
                }

                $customerId = (string)($customer['id'] ?? '');

                if (
                    !in_array(
                        $customerId,
                        array_map('strval', $customerIds),
                        true
                    )
                ) {
                    continue;
                }

                $logs['logs'][] = [
                    'id' => generate_id('mail'),
                    'survey_id' => $surveyId,
                    'customer_id' => $customerId,
                    'customer_name' =>
                        (string)($customer['name'] ?? ''),
                    'email' =>
                        (string)($customer['email'] ?? ''),
                    'status' => 'prepared',
                    'sent_at' => now()
                ];

                $sent++;
            }

            $logs['updated_at'] = now();

            write_json_file(
                MAIL_LOGS_FILE,
                $logs
            );

            $index = survey_index(
                $surveyData['surveys'],
                $surveyId
            );

            if ($index >= 0) {
                $surveyData['surveys'][$index]['target'] =
                    max(
                        (int)($surveyData['surveys'][$index]['target'] ?? 0),
                        $sent
                    );

                $surveyData['surveys'][$index]['sent'] += $sent;
                $surveyData['surveys'][$index]['updated'] =
                    date('Y-m-d');

                write_json_file(
                    SURVEYS_FILE,
                    $surveyData
                );
            }

            json_response(
                true,
                $sent . '件の送信対象を登録しました。',
                ['count' => $sent]
            );

        case 'load_public_survey':
            $id = trim((string)($_GET['id'] ?? ''));

            $data = load_surveys();

            $survey = survey_find(
                $data['surveys'] ?? [],
                $id
            );

            if ($survey === null) {
                json_response(
                    false,
                    'アンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            if (($survey['status'] ?? '') !== 'open') {
                json_response(
                    false,
                    'このアンケートは現在回答を受け付けていません。',
                    [],
                    [],
                    403
                );
            }

            json_response(
                true,
                'アンケートを取得しました。',
                ['survey' => $survey]
            );

        case 'save_response':
            $input = read_json_request();

            $surveyId = trim(
                (string)($input['survey_id'] ?? '')
            );

            $answers =
                isset($input['answers']) &&
                is_array($input['answers'])
                    ? $input['answers']
                    : [];

            $respondentName = trim(
                (string)($input['respondent_name'] ?? '')
            );

            $respondentEmail = trim(
                (string)($input['respondent_email'] ?? '')
            );

            $data = load_surveys();

            $survey = survey_find(
                $data['surveys'] ?? [],
                $surveyId
            );

            if ($survey === null) {
                json_response(
                    false,
                    'アンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            if (($survey['status'] ?? '') !== 'open') {
                json_response(
                    false,
                    'このアンケートは現在回答を受け付けていません。',
                    [],
                    [],
                    403
                );
            }

            $responses = load_responses();

            if (!isset($responses['responses']) || !is_array($responses['responses'])) {
                $responses['responses'] = [];
            }

            /*
             * 同じブラウザからの二重送信防止。
             */
            $submissionKey = trim(
                (string)($input['submission_key'] ?? '')
            );

            if ($submissionKey !== '') {
                foreach ($responses['responses'] as $existing) {
                    if (
                        is_array($existing) &&
                        (string)($existing['submission_key'] ?? '') ===
                        $submissionKey
                    ) {
                        json_response(
                            false,
                            'この回答はすでに送信されています。',
                            [],
                            [],
                            409
                        );
                    }
                }
            }

            $responses['responses'][] = [
                'id' => generate_id('response'),
                'survey_id' => $surveyId,
                'respondent_name' => $respondentName,
                'respondent_email' => $respondentEmail,
                'answers' => $answers,
                'submission_key' => $submissionKey,
                'submitted_at' => now()
            ];

            $responses['updated_at'] = now();

            write_json_file(
                RESPONSES_FILE,
                $responses
            );

            json_response(
                true,
                '回答を送信しました。'
            );

        case 'aggregate_responses':
            $surveyId = trim((string)($_GET['survey_id'] ?? ''));

            $surveyData = load_surveys();
            $survey = survey_find(
                $surveyData['surveys'] ?? [],
                $surveyId
            );

            if ($survey === null) {
                json_response(
                    false,
                    'アンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            $responseData = load_responses();

            $responses = [];

            foreach (($responseData['responses'] ?? []) as $response) {
                if (
                    is_array($response) &&
                    (string)($response['survey_id'] ?? '') ===
                    $surveyId
                ) {
                    $responses[] = $response;
                }
            }

            $result = [];

            foreach (($survey['groups'] ?? []) as $group) {
                if (!is_array($group)) {
                    continue;
                }

                foreach (($group['questions'] ?? []) as $question) {
                    if (!is_array($question)) {
                        continue;
                    }

                    $questionId =
                        (string)($question['id'] ?? '');

                    $type =
                        (string)($question['type'] ?? 'free');

                    $counts = [];

                    foreach (($question['options'] ?? []) as $option) {
                        if (!is_array($option)) {
                            continue;
                        }

                        $text =
                            (string)($option['text'] ?? '');

                        $counts[$text] = 0;
                    }

                    $freeAnswers = [];

                    foreach ($responses as $response) {
                        $answerMap =
                            $response['answers'] ?? [];

                        if (!is_array($answerMap)) {
                            continue;
                        }

                        $answer =
                            $answerMap[$questionId] ?? null;

                        if ($type === 'free') {
                            if (
                                is_string($answer) &&
                                trim($answer) !== ''
                            ) {
                                $freeAnswers[] = $answer;
                            }
                        } elseif ($type === 'single') {
                            if (is_string($answer) && isset($counts[$answer])) {
                                $counts[$answer]++;
                            }
                        } elseif ($type === 'multiple') {
                            if (!is_array($answer)) {
                                continue;
                            }

                            foreach ($answer as $selected) {
                                if (
                                    is_string($selected) &&
                                    isset($counts[$selected])
                                ) {
                                    $counts[$selected]++;
                                }
                            }
                        }
                    }

                    $result[] = [
                        'question_id' => $questionId,
                        'question' =>
                            (string)($question['text'] ?? ''),
                        'type' => $type,
                        'total' => count($responses),
                        'counts' => $counts,
                        'free_answers' => $freeAnswers
                    ];
                }
            }

            json_response(
                true,
                '回答結果を集計しました。',
                [
                    'total_responses' => count($responses),
                    'results' => $result
                ]
            );

        default:
            json_response(
                false,
                '指定された処理は存在しません。',
                [],
                [],
                404
            );
    }
}

$apiPath = application_api_path();

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<meta name="api-path" content="<?= h($apiPath) ?>">
<title>アンケート業務運営</title>

<style>
*{box-sizing:border-box}
html,body{margin:0;padding:0;min-height:100%}
body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
    "Yu Gothic","YuGothic",Meiryo,sans-serif;
    background:#f4f6f8;color:#263238;font-size:14px;line-height:1.6
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
button:disabled{cursor:not-allowed;opacity:.55}
.hidden{display:none!important}

.topbar{
    min-height:60px;background:#1f3a5f;color:#fff;
    display:flex;align-items:center;padding:0 24px;gap:28px
}
.logo{font-size:18px;font-weight:bold;white-space:nowrap}
.main-nav{display:flex;height:60px;align-items:stretch;gap:2px}
.main-nav button{
    height:60px;padding:0 16px;color:#dce7f3;
    background:transparent;border:0;white-space:nowrap
}
.main-nav button:hover,.main-nav button.active{
    background:#31557f;color:#fff
}

.app{max-width:1440px;margin:0 auto;padding:24px}
.page{width:100%}
.page-header{
    display:flex;justify-content:space-between;align-items:center;
    gap:20px;margin-bottom:20px
}
.page-header h1{margin:0;font-size:25px;line-height:1.35}
.subtext{color:#718096;font-size:13px;margin-top:5px}

.card{
    background:#fff;border:1px solid #dfe5eb;border-radius:7px;
    padding:20px;margin-bottom:18px
}
.card-title{font-size:17px;font-weight:bold;margin-bottom:15px}

.btn{
    border:1px solid #cbd5e0;background:#fff;color:#34495e;
    border-radius:5px;padding:8px 15px;min-height:38px
}
.btn:hover{background:#f7fafc}
.btn-primary{background:#2878c8;border-color:#2878c8;color:#fff}
.btn-primary:hover{background:#2068ad;border-color:#2068ad}
.btn-success{background:#2f855a;border-color:#2f855a;color:#fff}
.btn-danger{border-color:#e05a5a;color:#c53f3f;background:#fff}
.btn-small{min-height:32px;padding:5px 10px;font-size:12px}

.loading-spinner{
    display:none;width:14px;height:14px;margin-right:6px;
    border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff;border-radius:50%;
    animation:spin .7s linear infinite;vertical-align:-2px
}
.btn.loading .loading-spinner{display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}

.notice{
    background:#eef6ff;border:1px solid #c9e1f8;color:#315a7d;
    border-radius:6px;padding:12px 14px;margin-bottom:18px
}
.notice.success{background:#eefaf3;border-color:#bde3ca;color:#276749}
.notice.warning{background:#fff8e8;border-color:#f1d99b;color:#856404}
.notice.error{background:#fff5f5;border-color:#f0c2c2;color:#a33a3a}

.table-wrap{width:100%;overflow-x:auto}
.table{width:100%;border-collapse:collapse}
.table th,.table td{
    padding:12px 10px;border-bottom:1px solid #e6ebef;
    text-align:left;vertical-align:middle;font-size:13px
}
.table th{background:#f8fafc;color:#52606d;font-weight:bold}
.table tbody tr:hover td{background:#fbfdff}
.empty{text-align:center!important;color:#8a98a5;padding:40px!important}

.badge{
    display:inline-block;padding:3px 8px;border-radius:12px;
    font-size:11px;line-height:1.4;white-space:nowrap
}
.badge-draft{color:#5f6b76;background:#edf0f2}
.badge-open{color:#276749;background:#dff5e7}
.badge-end{color:#7b3f3f;background:#f6dddd}

.link-button{
    border:0;background:none;padding:0;color:#2878c8;
    cursor:pointer;text-align:left
}
.link-button:hover{text-decoration:underline}

.form-grid{
    display:grid;grid-template-columns:1fr 1fr;gap:16px
}
.field{margin-bottom:15px}
.field label{
    display:block;font-weight:bold;margin-bottom:6px;color:#465765
}
.field input,.field textarea,.field select{
    width:100%;border:1px solid #cbd5e0;border-radius:5px;
    padding:9px;background:#fff
}
.field textarea{min-height:100px;resize:vertical}
.radio-row{display:flex;gap:20px;flex-wrap:wrap}
.radio-row label{font-weight:normal}

.group-card{
    background:#fff;border:1px solid #dfe5eb;border-radius:7px;
    margin-bottom:18px;overflow:hidden
}
.group-card.dragging{opacity:.45}
.group-card.drag-over{border-top:3px solid #2878c8}
.group-header{
    display:flex;align-items:center;gap:10px;
    padding:12px 15px;background:#f8fafc;
    border-bottom:1px solid #e6ebef
}
.drag-handle{cursor:grab;color:#718096;font-size:18px}
.group-title{flex:1}
.group-title input{
    width:100%;border:1px solid #cbd5e0;
    border-radius:4px;padding:8px;font-weight:bold
}
.group-actions{display:flex;gap:6px}

.question-card{padding:15px;border-bottom:1px solid #e6ebef}
.question-card:last-child{border-bottom:0}
.question-card.dragging{opacity:.45}
.question-card.drag-over{border-top:3px solid #2878c8}
.question-head{
    display:flex;align-items:center;gap:8px
}
.question-number{
    width:60px;color:#2878c8;font-weight:bold;flex:none
}
.question-title{flex:1}
.question-title input{
    width:100%;border:1px solid #cbd5e0;
    border-radius:4px;padding:8px
}
.question-tools{display:flex;gap:6px;align-items:center}
.question-tools select{
    border:1px solid #cbd5e0;border-radius:4px;padding:6px
}
.question-meta{
    display:flex;gap:18px;align-items:center;
    margin-top:10px;padding-left:68px;
    color:#657786;font-size:13px
}
.question-options{margin-top:12px;padding-left:68px}
.option-row{
    display:flex;align-items:center;gap:7px;margin-bottom:7px
}
.option-row input{
    flex:1;border:1px solid #cbd5e0;
    border-radius:4px;padding:7px
}
.option-row select{
    width:230px;border:1px solid #cbd5e0;
    border-radius:4px;padding:7px
}
.add-question-area{
    padding:12px 14px;background:#fafcfd;
    border-top:1px solid #edf1f4
}
.add-group-area{text-align:center;margin:8px 0 20px}
.editor-toolbar{
    display:flex;justify-content:space-between;
    gap:10px;margin-top:20px
}
.editor-actions{display:flex;gap:8px}

.detail-tabs{
    display:flex;gap:5px;margin-bottom:18px;
    flex-wrap:wrap
}
.detail-tabs button{
    border:1px solid #d6dee6;background:#fff;
    padding:9px 16px;border-radius:5px
}
.detail-tabs button.active{
    background:#2878c8;color:#fff;border-color:#2878c8
}

.detail-summary{
    display:grid;grid-template-columns:repeat(4,1fr);
    gap:14px;margin-bottom:18px
}
.stat-card{
    background:#fff;border:1px solid #dfe5eb;
    border-radius:7px;padding:17px
}
.stat-label{color:#718096;font-size:12px}
.stat-value{font-size:27px;font-weight:bold;margin-top:5px}

.send-layout{
    display:grid;grid-template-columns:1.1fr .9fr;gap:18px
}
.customer-toolbar{display:flex;gap:8px;margin-bottom:12px}
.customer-toolbar input,.customer-toolbar select{
    border:1px solid #cbd5e0;border-radius:5px;padding:8px
}
.customer-toolbar input{flex:1}
.selection-summary{
    padding:10px 12px;background:#edf6ff;color:#2b5f8a;
    border-radius:5px;margin-bottom:12px;font-size:13px
}
.recipient-chip{
    display:inline-block;padding:5px 8px;margin:3px;
    border-radius:4px;background:#edf2f7;font-size:12px
}
.email-preview{
    border:1px solid #dfe5eb;border-radius:6px;
    background:#fafbfc;padding:15px;
    white-space:pre-wrap;min-height:150px
}
.progress{
    height:10px;background:#e8edf2;border-radius:5px;
    overflow:hidden;margin-top:8px
}
.progress span{display:block;height:100%;background:#4285c5}

.result-layout{
    display:grid;grid-template-columns:270px 1fr;gap:18px
}
.result-question-button{
    display:block;width:100%;border:0;background:#fff;
    text-align:left;padding:10px;border-radius:4px;margin-bottom:4px
}
.result-question-button:hover,.result-question-button.active{
    background:#eef6ff;color:#2878c8
}
.result-bar-row{margin-bottom:14px}
.result-bar-label{
    display:flex;justify-content:space-between;
    gap:10px;margin-bottom:4px
}
.result-bar{
    height:18px;border-radius:3px;
    overflow:hidden;background:#edf1f4
}
.result-bar-inner{height:100%;background:#2878c8}
.result-answer{
    padding:8px 10px;background:#f7f9fb;
    border:1px solid #e6ebef;border-radius:4px;
    margin:5px 0;font-size:13px
}

.settings-tabs{display:flex;gap:5px;margin-bottom:18px}
.settings-tabs button{
    border:1px solid #d6dee6;background:#fff;
    padding:9px 16px;border-radius:5px
}
.settings-tabs button.active{
    background:#2878c8;color:#fff;border-color:#2878c8
}
.status-line{
    display:flex;align-items:center;gap:8px;
    padding:10px 12px;background:#f7f9fb;
    border-radius:5px;margin-bottom:15px
}
.status-dot{
    width:9px;height:9px;border-radius:50%;background:#9aa7b3
}
.status-dot.ok{background:#2f9e61}
.status-dot.warn{background:#d39b25}
.status-dot.error{background:#d9534f}

.modal-backdrop{
    position:fixed;inset:0;background:rgba(20,35,50,.45);
    display:flex;align-items:center;justify-content:center;
    z-index:1000
}
.modal{
    width:min(760px,calc(100% - 30px));
    max-height:90vh;overflow:auto;background:#fff;
    border-radius:8px;box-shadow:0 15px 50px rgba(0,0,0,.25)
}
.modal-header{
    padding:16px 20px;border-bottom:1px solid #e3e8ed;
    display:flex;justify-content:space-between
}
.modal-body{padding:20px}
.modal-footer{
    padding:13px 20px;border-top:1px solid #e3e8ed;
    display:flex;justify-content:flex-end;gap:8px
}
.toast{
    position:fixed;right:25px;bottom:25px;
    background:#263238;color:#fff;padding:12px 18px;
    border-radius:5px;box-shadow:0 5px 20px rgba(0,0,0,.2);
    opacity:0;transform:translateY(10px);
    transition:.2s;pointer-events:none;z-index:2000
}
.toast.show{opacity:1;transform:translateY(0)}

.public-page{
    max-width:820px;margin:0 auto;padding:30px 20px 60px
}
.public-question{
    background:#fff;border:1px solid #dfe5eb;
    border-radius:7px;padding:20px;margin-bottom:15px
}
.public-question-title{font-weight:bold;margin-bottom:12px}
.public-option{margin:8px 0}
.public-complete{
    background:#fff;border:1px solid #dfe5eb;
    border-radius:7px;padding:40px;text-align:center
}

@media(max-width:980px){
    .send-layout,.result-layout{grid-template-columns:1fr}
    .detail-summary{grid-template-columns:1fr 1fr}
}
@media(max-width:800px){
    .topbar{padding:0 10px;gap:8px}
    .logo{font-size:15px}
    .main-nav button{padding:0 8px;font-size:12px}
    .app{padding:14px}
    .form-grid{grid-template-columns:1fr}
    .question-head{align-items:flex-start;flex-wrap:wrap}
    .question-title{min-width:70%}
    .question-meta,.question-options{padding-left:0}
    .option-row{flex-wrap:wrap}
    .option-row select{width:100%}
    .table{min-width:800px}
    .card{overflow-x:auto}
}
</style>
</head>

<body>

<header class="topbar" id="operator-header">
    <div class="logo">アンケート業務運営</div>

    <nav class="main-nav">
        <button id="nav-list" type="button">アンケート一覧</button>
        <button id="nav-create" type="button">アンケート作成</button>
        <button id="nav-customers" type="button">顧客一覧</button>
        <button id="nav-settings" type="button">設定</button>
    </nav>
</header>

<main class="app" id="operator-app">

<section id="page-list">
    <div class="page-header">
        <div>
            <h1>アンケート一覧</h1>
            <div class="subtext">作成済みのアンケートを管理します</div>
        </div>

        <button id="btn-open-create" class="btn btn-primary" type="button">
            <span class="loading-spinner"></span>
            ＋ アンケート作成
        </button>
    </div>

    <div id="list-notice"></div>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>アンケート名</th>
                    <th>状態</th>
                    <th>作成日</th>
                    <th>公開期間</th>
                    <th>回答数</th>
                    <th>最終更新日</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody id="survey-list-body"></tbody>
            </table>
        </div>
    </div>
</section>

<section id="page-editor" class="hidden">

    <div class="page-header">
        <div>
            <h1 id="editor-page-title">アンケート作成</h1>
            <div class="subtext">アンケート全体を1画面で編集できます</div>
        </div>
    </div>

    <div id="editor-notice"></div>

    <div class="notice">
        質問とグループはドラッグ＆ドロップで並べ替えできます。
        質問番号は自動的に更新されます。
    </div>

    <div class="card">

        <div class="form-grid">

            <div class="field">
                <label for="survey-name">アンケート名 *</label>
                <input id="survey-name" type="text"
                    placeholder="例：新商品アンケート">
            </div>

            <div class="field">
                <label for="survey-status">公開状態</label>
                <select id="survey-status">
                    <option value="draft">下書き</option>
                    <option value="open">公開中</option>
                    <option value="end">終了</option>
                </select>
            </div>

        </div>

        <div class="field">
            <label for="survey-description">説明</label>
            <textarea id="survey-description"
                placeholder="回答者への説明を入力してください"></textarea>
        </div>

        <div class="form-grid">

            <div class="field">
                <label for="survey-start">公開開始日</label>
                <input id="survey-start" type="date">
            </div>

            <div class="field">
                <label for="survey-end">公開終了日</label>
                <input id="survey-end" type="date">
            </div>

        </div>

        <div class="field">
            <label>質問番号</label>

            <div class="radio-row">
                <label>
                    <input type="radio"
                        name="numbering"
                        value="global">
                    全体で通番（Q1、Q2、Q3…）
                </label>

                <label>
                    <input type="radio"
                        name="numbering"
                        value="group">
                    グループごと（Q1-1、Q1-2、Q2-1…）
                </label>
            </div>
        </div>

    </div>

    <div id="groups"></div>

    <div class="add-group-area">
        <button id="btn-add-group"
            class="btn btn-primary"
            type="button">
            ＋ グループ追加
        </button>
    </div>

    <div class="editor-toolbar">
        <button id="btn-editor-back"
            class="btn"
            type="button">
            一覧へ戻る
        </button>

        <div class="editor-actions">
            <button id="btn-preview-editor"
                class="btn"
                type="button">
                内容確認
            </button>

            <button id="btn-save-survey"
                class="btn btn-primary"
                type="button">
                <span class="loading-spinner"></span>
                保存
            </button>
        </div>
    </div>

</section>

<section id="page-detail" class="hidden">

    <div class="page-header">
        <div>
            <h1 id="detail-title"></h1>
            <div class="subtext" id="detail-subtitle"></div>
        </div>

        <div>
            <button id="btn-detail-edit"
                class="btn"
                type="button">
                編集
            </button>

            <button id="btn-detail-send"
                class="btn btn-primary"
                type="button">
                送信
            </button>

            <button id="btn-detail-back"
                class="btn"
                type="button">
                一覧へ戻る
            </button>
        </div>
    </div>

    <div class="detail-tabs">

        <button id="tab-content"
            type="button"
            data-tab="content">
            アンケート内容
        </button>

        <button id="tab-send"
            type="button"
            data-tab="send">
            送信
        </button>

        <button id="tab-status"
            type="button"
            data-tab="status">
            回答状況
        </button>

        <button id="tab-result"
            type="button"
            data-tab="result">
            回答結果
        </button>

    </div>

    <div id="detail-content"></div>

</section>

<section id="page-customers" class="hidden">

    <div class="page-header">
        <div>
            <h1>顧客一覧</h1>
            <div class="subtext">
                kintoneから取得した顧客です
            </div>
        </div>

        <button id="btn-customer-settings"
            class="btn"
            type="button">
            kintone設定
        </button>
    </div>

    <div id="customer-notice"></div>

    <div class="card">

        <div class="customer-toolbar">

            <input id="customer-search"
                type="text"
                placeholder="顧客名・メールアドレスで検索">

            <button id="btn-refresh-customers"
                class="btn"
                type="button">
                <span class="loading-spinner"></span>
                顧客一覧を更新
            </button>

        </div>

        <div class="table-wrap">

            <table class="table">

                <thead>
                <tr>
                    <th>顧客名</th>
                    <th>メールアドレス</th>
                    <th>会社名</th>
                    <th>顧客番号</th>
                </tr>
                </thead>

                <tbody id="customer-body"></tbody>

            </table>

        </div>
    </div>

</section>

<section id="page-settings" class="hidden">

    <div class="page-header">
        <div>
            <h1>設定</h1>
            <div class="subtext">
                メール送信と顧客一覧取得に必要な設定を管理します
            </div>
        </div>
    </div>

    <div class="settings-tabs">

        <button id="settings-tab-mail"
            type="button"
            data-settings-tab="mail">
            メール送信設定
        </button>

        <button id="settings-tab-kintone"
            type="button"
            data-settings-tab="kintone">
            kintone設定
        </button>

    </div>

    <div id="settings-content"></div>

</section>

</main>

<div id="modal" class="modal-backdrop hidden">

    <div class="modal">

        <div class="modal-header">
            <strong id="modal-title"></strong>

            <button id="modal-close"
                class="btn btn-small"
                type="button">
                閉じる
            </button>
        </div>

        <div id="modal-body" class="modal-body"></div>

        <div id="modal-footer" class="modal-footer"></div>

    </div>

</div>

<div id="toast" class="toast"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const apiMeta = document.querySelector('meta[name="api-path"]');

    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const apiPath = apiMeta ? apiMeta.content : 'index.php';

    let surveys = [];
    let customers = [];
    let currentSurvey = null;
    let currentSurveyId = '';
    let editingSurvey = null;
    let currentSettingsTab = 'mail';
    let currentDetailTab = 'content';
    let draggedGroupId = '';
    let draggedQuestionId = '';
    let nextGroupNo = 1;
    let nextQuestionNo = 1;
    let toastTimer = null;

    const $ = function (id) {
        return document.getElementById(id);
    };

    function escapeHtml(value) {
        const str = String(value ?? '');

        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showToast(message) {
        const toast = $('toast');

        if (!toast) {
            return;
        }

        toast.textContent = String(message || '');
        toast.classList.add('show');

        if (toastTimer !== null) {
            window.clearTimeout(toastTimer);
        }

        toastTimer = window.setTimeout(function () {
            toast.classList.remove('show');
        }, 2800);
    }

    function showNotice(target, message, type) {
        if (!target) {
            return;
        }

        target.textContent = '';

        if (!message) {
            return;
        }

        const div = document.createElement('div');

        div.className =
            'notice' +
            (type ? ' ' + type : '');

        div.textContent = String(message);

        target.appendChild(div);
    }

    function setButtonLoading(button, loading) {
        if (!button) {
            return;
        }

        if (loading) {
            button.disabled = true;
            button.classList.add('loading');
        } else {
            button.disabled = false;
            button.classList.remove('loading');
        }
    }

    /*
     * 重要：
     * 絶対URLを生成しない。
     *
     * 同じApache上のindex.php自身へ相対的に通信する。
     * これにより https://n11-1041/... をJavaScriptへ
     * 固定することを避ける。
     */
    function buildApiUrl(action, params) {
        const query = new URLSearchParams();

        query.set('action', action);

        if (params && typeof params === 'object') {
            Object.keys(params).forEach(function (key) {
                const value = params[key];

                if (
                    value !== undefined &&
                    value !== null &&
                    String(value) !== ''
                ) {
                    query.set(key, String(value));
                }
            });
        }

        return apiPath + '?' + query.toString();
    }

    async function apiGet(action, params) {
        const url = buildApiUrl(action, params);

        const response = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json'
            }
        });

        const text = await response.text();

        let data = null;

        try {
            data = JSON.parse(text);
        } catch (e) {
            throw new Error(
                'サーバーから正しい応答を取得できませんでした。'
            );
        }

        if (!response.ok || !data || data.success !== true) {
            throw new Error(
                data && data.message
                    ? data.message
                    : '処理に失敗しました。'
            );
        }

        return data;
    }

    async function apiPost(button, action, payload) {
        /*
         * 規約：
         * fetch開始前に必ずdisabledとloadingを設定。
         */
        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }

        try {
            const url = buildApiUrl(action);

            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json; charset=utf-8',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(payload || {})
            });

            const text = await response.text();

            let data = null;

            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error(
                    'サーバーから正しい応答を取得できませんでした。'
                );
            }

            if (!response.ok || !data || data.success !== true) {
                throw new Error(
                    data && data.message
                        ? data.message
                        : '処理に失敗しました。'
                );
            }

            return data;
        } finally {
            if (button) {
                button.disabled = false;
                button.classList.remove('loading');
            }
        }
    }

    function showPage(id) {
        const pageIds = [
            'page-list',
            'page-editor',
            'page-detail',
            'page-customers',
            'page-settings'
        ];

        pageIds.forEach(function (pageId) {
            const element = $(pageId);

            if (element) {
                element.classList.toggle(
                    'hidden',
                    pageId !== id
                );
            }
        });

        const navIds = [
            'nav-list',
            'nav-create',
            'nav-customers',
            'nav-settings'
        ];

        navIds.forEach(function (navId) {
            const element = $(navId);

            if (element) {
                element.classList.remove('active');
            }
        });

        if (id === 'page-list' && $('nav-list')) {
            $('nav-list').classList.add('active');
        }

        if (id === 'page-editor' && $('nav-create')) {
            $('nav-create').classList.add('active');
        }

        if (id === 'page-customers' && $('nav-customers')) {
            $('nav-customers').classList.add('active');
        }

        if (id === 'page-settings' && $('nav-settings')) {
            $('nav-settings').classList.add('active');
        }
    }

    function statusBadge(status) {
        if (status === 'open') {
            return '<span class="badge badge-open">公開中</span>';
        }

        if (status === 'end') {
            return '<span class="badge badge-end">終了</span>';
        }

        return '<span class="badge badge-draft">下書き</span>';
    }

    async function loadSurveyList() {
        try {
            const result = await apiGet('load_surveys');

            surveys = Array.isArray(result.data?.surveys)
                ? result.data.surveys
                : [];

            renderSurveyList();
        } catch (error) {
            showNotice(
                $('list-notice'),
                error instanceof Error
                    ? error.message
                    : 'アンケート一覧を取得できません。',
                'error'
            );
        }
    }

    function renderSurveyList() {
        const body = $('survey-list-body');

        if (!body) {
            return;
        }

        body.textContent = '';

        if (surveys.length === 0) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');

            td.colSpan = 7;
            td.className = 'empty';
            td.textContent = 'アンケートがありません。';

            tr.appendChild(td);
            body.appendChild(tr);

            return;
        }

        surveys.forEach(function (survey) {
            const tr = document.createElement('tr');

            const nameTd = document.createElement('td');
            const nameButton = document.createElement('button');

            nameButton.type = 'button';
            nameButton.className = 'link-button';
            nameButton.textContent =
                String(survey.name || '名称未設定');

            nameButton.addEventListener('click', function () {
                openDetail(String(survey.id || ''));
            });

            nameTd.appendChild(nameButton);

            const statusTd = document.createElement('td');
            statusTd.innerHTML = statusBadge(
                String(survey.status || 'draft')
            );

            const createdTd = document.createElement('td');
            createdTd.textContent =
                String(survey.created || '');

            const periodTd = document.createElement('td');
            periodTd.textContent =
                (survey.start || survey.end)
                    ? String(survey.start || '未設定') +
                      ' ～ ' +
                      String(survey.end || '未設定')
                    : '未設定';

            const answerTd = document.createElement('td');
            answerTd.textContent =
                String(Number(survey.answers || 0)) + '件';

            const updatedTd = document.createElement('td');
            updatedTd.textContent =
                String(survey.updated || '');

            const actionTd = document.createElement('td');

            const editButton = document.createElement('button');
            editButton.type = 'button';
            editButton.className = 'btn btn-small';
            editButton.textContent = '編集';

            editButton.addEventListener('click', function () {
                editSurvey(String(survey.id || ''));
            });

            actionTd.appendChild(editButton);

            const detailButton = document.createElement('button');
            detailButton.type = 'button';
            detailButton.className = 'btn btn-small';
            detailButton.textContent = '確認';

            detailButton.addEventListener('click', function () {
                openDetail(String(survey.id || ''));
            });

            actionTd.appendChild(document.createTextNode(' '));
            actionTd.appendChild(detailButton);

            if (survey.status === 'draft') {
                const publishButton =
                    document.createElement('button');

                publishButton.type = 'button';
                publishButton.className =
                    'btn btn-small btn-success';
                publishButton.textContent = '公開';

                publishButton.addEventListener(
                    'click',
                    function () {
                        publishSurvey(
                            String(survey.id || ''),
                            publishButton
                        );
                    }
                );

                actionTd.appendChild(
                    document.createTextNode(' ')
                );
                actionTd.appendChild(publishButton);

                const deleteButton =
                    document.createElement('button');

                deleteButton.type = 'button';
                deleteButton.className =
                    'btn btn-small btn-danger';
                deleteButton.textContent = '削除';

                deleteButton.addEventListener(
                    'click',
                    function () {
                        deleteSurvey(
                            String(survey.id || ''),
                            deleteButton
                        );
                    }
                );

                actionTd.appendChild(
                    document.createTextNode(' ')
                );
                actionTd.appendChild(deleteButton);
            }

            if (survey.status === 'open') {
                const closeButton =
                    document.createElement('button');

                closeButton.type = 'button';
                closeButton.className =
                    'btn btn-small btn-danger';
                closeButton.textContent = '終了';

                closeButton.addEventListener(
                    'click',
                    function () {
                        closeSurvey(
                            String(survey.id || ''),
                            closeButton
                        );
                    }
                );

                actionTd.appendChild(
                    document.createTextNode(' ')
                );
                actionTd.appendChild(closeButton);
            }

            tr.appendChild(nameTd);
            tr.appendChild(statusTd);
            tr.appendChild(createdTd);
            tr.appendChild(periodTd);
            tr.appendChild(answerTd);
            tr.appendChild(updatedTd);
            tr.appendChild(actionTd);

            body.appendChild(tr);
        });
    }

    function createEmptySurvey() {
        const survey = {
            id: 'survey_' + Date.now(),
            name: '',
            description: '',
            status: 'draft',
            created: '',
            start: '',
            end: '',
            answers: 0,
            target: 0,
            sent: 0,
            updated: '',
            numbering: 'global',
            groups: []
        };

        survey.groups.push(createGroup());

        return survey;
    }

    function createGroup() {
        const group = {
            id: 'group_' + Date.now() + '_' + nextGroupNo++,
            name: 'グループ' + nextGroupNo,
            questions: []
        };

        group.questions.push(createQuestion());

        return group;
    }

    function createQuestion() {
        return {
            id: 'question_' +
                Date.now() +
                '_' +
                nextQuestionNo++,
            text: '',
            type: 'free',
            required: false,
            options: []
        };
    }

    function openCreate() {
        editingSurvey = createEmptySurvey();

        const title = $('editor-page-title');

        if (title) {
            title.textContent = 'アンケート作成';
        }

        loadEditor();

        showPage('page-editor');
    }

    async function editSurvey(id) {
        if (!id) {
            return;
        }

        try {
            const result = await apiGet(
                'load_survey',
                {id: id}
            );

            editingSurvey =
                JSON.parse(
                    JSON.stringify(
                        result.data?.survey || null
                    )
                );

            if (!editingSurvey) {
                throw new Error(
                    'アンケートを取得できません。'
                );
            }

            const title = $('editor-page-title');

            if (title) {
                title.textContent = 'アンケート編集';
            }

            loadEditor();
            showPage('page-editor');
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : 'アンケートを開けません。'
            );
        }
    }

    function loadEditor() {
        if (!editingSurvey) {
            return;
        }

        const name = $('survey-name');
        const description = $('survey-description');
        const status = $('survey-status');
        const start = $('survey-start');
        const end = $('survey-end');

        if (name) {
            name.value = String(
                editingSurvey.name || ''
            );
        }

        if (description) {
            description.value = String(
                editingSurvey.description || ''
            );
        }

        if (status) {
            status.value =
                String(
                    editingSurvey.status || 'draft'
                );
        }

        if (start) {
            start.value =
                String(editingSurvey.start || '');
        }

        if (end) {
            end.value =
                String(editingSurvey.end || '');
        }

        document
            .querySelectorAll('input[name="numbering"]')
            .forEach(function (radio) {
                if (
                    radio instanceof HTMLInputElement
                ) {
                    radio.checked =
                        radio.value ===
                        String(
                            editingSurvey.numbering ||
                            'global'
                        );
                }
            });

        renderEditor();
    }

    function questionNumber(groupIndex, questionIndex) {
        if (
            editingSurvey &&
            editingSurvey.numbering === 'group'
        ) {
            return 'Q' +
                String(groupIndex + 1) +
                '-' +
                String(questionIndex + 1);
        }

        let count = 0;

        for (let i = 0; i < groupIndex; i++) {
            count +=
                Array.isArray(
                    editingSurvey.groups[i].questions
                )
                    ? editingSurvey.groups[i].questions.length
                    : 0;
        }

        return 'Q' +
            String(
                count +
                questionIndex +
                1
            );
    }

    function renderEditor() {
        const groupsElement = $('groups');

        if (!groupsElement || !editingSurvey) {
            return;
        }

        groupsElement.textContent = '';

        const groups =
            Array.isArray(editingSurvey.groups)
                ? editingSurvey.groups
                : [];

        groups.forEach(function (group, groupIndex) {
            const groupCard =
                document.createElement('div');

            groupCard.className = 'group-card';
            groupCard.draggable = true;
            groupCard.dataset.groupId =
                String(group.id || '');

            groupCard.addEventListener(
                'dragstart',
                function () {
                    draggedGroupId =
                        String(group.id || '');
                    groupCard.classList.add('dragging');
                }
            );

            groupCard.addEventListener(
                'dragend',
                function () {
                    draggedGroupId = '';
                    groupCard.classList.remove('dragging');
                }
            );

            groupCard.addEventListener(
                'dragover',
                function (event) {
                    event.preventDefault();
                    groupCard.classList.add('drag-over');
                }
            );

            groupCard.addEventListener(
                'dragleave',
                function () {
                    groupCard.classList.remove(
                        'drag-over'
                    );
                }
            );

            groupCard.addEventListener(
                'drop',
                function (event) {
                    event.preventDefault();
                    groupCard.classList.remove(
                        'drag-over'
                    );

                    if (
                        draggedGroupId &&
                        draggedGroupId !==
                        String(group.id || '')
                    ) {
                        moveGroup(
                            draggedGroupId,
                            String(group.id || '')
                        );
                    }
                }
            );

            const header =
                document.createElement('div');

            header.className = 'group-header';

            const handle =
                document.createElement('span');

            handle.className = 'drag-handle';
            handle.textContent = '☷';

            const title =
                document.createElement('div');

            title.className = 'group-title';

            const groupInput =
                document.createElement('input');

            groupInput.type = 'text';
            groupInput.value =
                String(group.name || '');

            groupInput.addEventListener(
                'input',
                function () {
                    group.name =
                        groupInput.value;
                }
            );

            title.appendChild(groupInput);

            const actions =
                document.createElement('div');

            actions.className = 'group-actions';

            const deleteGroupButton =
                document.createElement('button');

            deleteGroupButton.type = 'button';
            deleteGroupButton.className =
                'btn btn-small btn-danger';
            deleteGroupButton.textContent =
                'グループ削除';

            deleteGroupButton.addEventListener(
                'click',
                function () {
                    deleteGroup(
                        String(group.id || '')
                    );
                }
            );

            actions.appendChild(
                deleteGroupButton
            );

            header.appendChild(handle);
            header.appendChild(title);
            header.appendChild(actions);

            groupCard.appendChild(header);

            const questions =
                document.createElement('div');

            questions.className = 'questions';

            const questionList =
                Array.isArray(group.questions)
                    ? group.questions
                    : [];

            questionList.forEach(
                function (question, questionIndex) {
                    questions.appendChild(
                        renderQuestion(
                            group,
                            question,
                            groupIndex,
                            questionIndex
                        )
                    );
                }
            );

            groupCard.appendChild(questions);

            const addArea =
                document.createElement('div');

            addArea.className =
                'add-question-area';

            const addButton =
                document.createElement('button');

            addButton.type = 'button';
            addButton.className =
                'btn btn-small btn-primary';
            addButton.textContent =
                '＋ 質問追加';

            addButton.addEventListener(
                'click',
                function () {
                    addQuestion(
                        String(group.id || '')
                    );
                }
            );

            addArea.appendChild(addButton);
            groupCard.appendChild(addArea);

            groupsElement.appendChild(groupCard);
        });
    }

    function renderQuestion(
        group,
        question,
        groupIndex,
        questionIndex
    ) {
        const card =
            document.createElement('div');

        card.className = 'question-card';
        card.draggable = true;
        card.dataset.questionId =
            String(question.id || '');

        card.addEventListener(
            'dragstart',
            function () {
                draggedQuestion =
                    String(question.id || '');
                card.classList.add('dragging');
            }
        );

        card.addEventListener(
            'dragend',
            function () {
                draggedQuestion = '';
                card.classList.remove('dragging');
            }
        );

        card.addEventListener(
            'dragover',
            function (event) {
                event.preventDefault();
                card.classList.add('drag-over');
            }
        );

        card.addEventListener(
            'dragleave',
            function () {
                card.classList.remove('drag-over');
            }
        );

        card.addEventListener(
            'drop',
            function (event) {
                event.preventDefault();
                card.classList.remove('drag-over');

                if (
                    draggedQuestion &&
                    draggedQuestion !==
                    String(question.id || '')
                ) {
                    moveQuestion(
                        draggedQuestion,
                        String(question.id || '')
                    );
                }
            }
        );

        const head =
            document.createElement('div');

        head.className = 'question-head';

        const number =
            document.createElement('div');

        number.className = 'question-number';
        number.textContent =
            questionNumber(
                groupIndex,
                questionIndex
            );

        const title =
            document.createElement('div');

        title.className = 'question-title';

        const input =
            document.createElement('input');

        input.type = 'text';
        input.value =
            String(question.text || '');

        input.placeholder =
            '質問文を入力してください';

        input.addEventListener(
            'input',
            function () {
                question.text =
                    input.value;
            }
        );

        title.appendChild(input);

        const tools =
            document.createElement('div');

        tools.className = 'question-tools';

        const type =
            document.createElement('select');

        [
            ['free', '自由記述'],
            ['single', '単一選択'],
            ['multiple', '複数選択']
        ].forEach(function (item) {
            const option =
                document.createElement('option');

            option.value = item[0];
            option.textContent = item[1];

            type.appendChild(option);
        });

        type.value =
            String(question.type || 'free');

        type.addEventListener(
            'change',
            function () {
                question.type =
                    type.value;

                if (
                    type.value === 'free'
                ) {
                    question.options = [];
                } else if (
                    !Array.isArray(question.options) ||
                    question.options.length === 0
                ) {
                    question.options = [
                        {
                            text: '',
                            branch: ''
                        },
                        {
                            text: '',
                            branch: ''
                        }
                    ];
                }

                renderEditor();
            }
        );

        tools.appendChild(type);

        const deleteButton =
            document.createElement('button');

        deleteButton.type = 'button';
        deleteButton.className =
            'btn btn-small btn-danger';
        deleteButton.textContent = '削除';

        deleteButton.addEventListener(
            'click',
            function () {
                deleteQuestion(
                    String(question.id || '')
                );
            }
        );

        tools.appendChild(deleteButton);

        head.appendChild(number);
        head.appendChild(title);
        head.appendChild(tools);

        card.appendChild(head);

        const meta =
            document.createElement('div');

        meta.className = 'question-meta';

        const requiredLabel =
            document.createElement('label');

        const required =
            document.createElement('input');

        required.type = 'checkbox';
        required.checked =
            Boolean(question.required);

        required.addEventListener(
            'change',
            function () {
                question.required =
                    required.checked;
            }
        );

        requiredLabel.appendChild(required);
        requiredLabel.appendChild(
            document.createTextNode(' 必須')
        );

        meta.appendChild(requiredLabel);
        card.appendChild(meta);

        if (
            question.type === 'single' ||
            question.type === 'multiple'
        ) {
            const options =
                document.createElement('div');

            options.className =
                'question-options';

            const optionList =
                Array.isArray(question.options)
                    ? question.options
                    : [];

            optionList.forEach(
                function (item, optionIndex) {
                    const row =
                        document.createElement('div');

                    row.className = 'option-row';

                    const optionInput =
                        document.createElement('input');

                    optionInput.type = 'text';
                    optionInput.value =
                        String(item.text || '');
                    optionInput.placeholder =
                        '選択肢';

                    optionInput.addEventListener(
                        'input',
                        function () {
                            item.text =
                                optionInput.value;
                        }
                    );

                    row.appendChild(optionInput);

                    if (
                        question.type === 'single'
                    ) {
                        const branch =
                            document.createElement('select');

                        const emptyOption =
                            document.createElement('option');

                        emptyOption.value = '';
                        emptyOption.textContent =
                            '分岐なし';

                        branch.appendChild(
                            emptyOption
                        );

                        getAllQuestionTargets(
                            String(question.id || '')
                        ).forEach(
                            function (target) {
                                const option =
                                    document.createElement('option');

                                option.value =
                                    String(target.id);

                                option.textContent =
                                    target.number +
                                    ' ' +
                                    target.text;

                                branch.appendChild(option);
                            }
                        );

                        branch.value =
                            String(item.branch || '');

                        branch.addEventListener(
                            'change',
                            function () {
                                item.branch =
                                    branch.value;
                            }
                        );

                        row.appendChild(branch);
                    }

                    const remove =
                        document.createElement('button');

                    remove.type = 'button';
                    remove.className =
                        'btn btn-small btn-danger';
                    remove.textContent = '削除';

                    remove.addEventListener(
                        'click',
                        function () {
                            question.options.splice(
                                optionIndex,
                                1
                            );

                            renderEditor();
                        }
                    );

                    row.appendChild(remove);
                    options.appendChild(row);
                }
            );

            const addOption =
                document.createElement('button');

            addOption.type = 'button';
            addOption.className =
                'btn btn-small';
            addOption.textContent =
                '＋ 選択肢追加';

            addOption.addEventListener(
                'click',
                function () {
                    question.options.push({
                        text: '',
                        branch: ''
                    });

                    renderEditor();
                }
            );

            options.appendChild(addOption);
            card.appendChild(options);
        }

        return card;
    }

    function getAllQuestionTargets(excludeId) {
        const targets = [];

        if (!editingSurvey) {
            return targets;
        }

        let number = 0;

        editingSurvey.groups.forEach(
            function (group, groupIndex) {
                const questions =
                    Array.isArray(group.questions)
                        ? group.questions
                        : [];

                questions.forEach(
                    function (question, questionIndex) {
                        number++;

                        if (
                            String(question.id || '') !==
                            String(excludeId || '')
                        ) {
                            targets.push({
                                id: String(question.id || ''),
                                number:
                                    questionNumber(
                                        groupIndex,
                                        questionIndex
                                    ),
                                text:
                                    String(
                                        question.text ||
                                        '未入力'
                                    )
                            });
                        }
                    }
                );
            }
        );

        return targets;
    }

    function addGroup() {
        if (!editingSurvey) {
            return;
        }

        editingSurvey.groups.push(
            createGroup()
        );

        renderEditor();
    }

    function deleteGroup(id) {
        if (!editingSurvey) {
            return;
        }

        if (
            editingSurvey.groups.length <= 1
        ) {
            showToast(
                'グループは1つ以上必要です。'
            );
            return;
        }

        if (
            !window.confirm(
                'このグループを削除しますか？'
            )
        ) {
            return;
        }

        editingSurvey.groups =
            editingSurvey.groups.filter(
                function (group) {
                    return String(group.id) !==
                        String(id);
                }
            );

        renderEditor();
    }

    function addQuestion(groupId) {
        if (!editingSurvey) {
            return;
        }

        const group =
            editingSurvey.groups.find(
                function (item) {
                    return String(item.id) ===
                        String(groupId);
                }
            );

        if (!group) {
            return;
        }

        if (!Array.isArray(group.questions)) {
            group.questions = [];
        }

        group.questions.push(
            createQuestion()
        );

        renderEditor();
    }

    function deleteQuestion(id) {
        if (!editingSurvey) {
            return;
        }

        for (
            let groupIndex = 0;
            groupIndex < editingSurvey.groups.length;
            groupIndex++
        ) {
            const group =
                editingSurvey.groups[groupIndex];

            const index =
                group.questions.findIndex(
                    function (question) {
                        return String(question.id) ===
                            String(id);
                    }
                );

            if (index >= 0) {
                if (
                    group.questions.length <= 1
                ) {
                    showToast(
                        'グループには質問を1つ以上残してください。'
                    );
                    return;
                }

                group.questions.splice(index, 1);
                renderEditor();

                return;
            }
        }
    }

    function moveGroup(sourceId, targetId) {
        if (!editingSurvey) {
            return;
        }

        const sourceIndex =
            editingSurvey.groups.findIndex(
                function (group) {
                    return String(group.id) ===
                        String(sourceId);
                }
            );

        const targetIndex =
            editingSurvey.groups.findIndex(
                function (group) {
                    return String(group.id) ===
                        String(targetId);
                }
            );

        if (
            sourceIndex < 0 ||
            targetIndex < 0 ||
            sourceIndex === targetIndex
        ) {
            return;
        }

        const moved =
            editingSurvey.groups.splice(
                sourceIndex,
                1
            )[0];

        editingSurvey.groups.splice(
            targetIndex,
            0,
            moved
        );

        renderEditor();
    }

    function moveQuestion(sourceId, targetId) {
        if (!editingSurvey) {
            return;
        }

        let sourceGroup = null;
        let sourceIndex = -1;

        editingSurvey.groups.forEach(
            function (group) {
                group.questions.forEach(
                    function (question, index) {
                        if (
                            String(question.id) ===
                            String(sourceId)
                        ) {
                            sourceGroup = group;
                            sourceIndex = index;
                        }
                    }
                );
            }
        );

        let targetGroup = null;
        let targetIndex = -1;

        editingSurvey.groups.forEach(
            function (group) {
                group.questions.forEach(
                    function (question, index) {
                        if (
                            String(question.id) ===
                            String(targetId)
                        ) {
                            targetGroup = group;
                            targetIndex = index;
                        }
                    }
                );
            }
        );

        if (
            !sourceGroup ||
            !targetGroup ||
            sourceIndex < 0 ||
            targetIndex < 0
        ) {
            return;
        }

        const moved =
            sourceGroup.questions.splice(
                sourceIndex,
                1
            )[0];

        if (sourceGroup === targetGroup) {
            if (sourceIndex < targetIndex) {
                targetIndex--;
            }
        }

        targetGroup.questions.splice(
            targetIndex,
            0,
            moved
        );

        renderEditor();
    }

    async function saveSurvey(button) {
        if (!editingSurvey) {
            return;
        }

        const name = $('survey-name');
        const description = $('survey-description');
        const status = $('survey-status');
        const start = $('survey-start');
        const end = $('survey-end');

        if (name) {
            editingSurvey.name = name.value.trim();
        }

        if (description) {
            editingSurvey.description =
                description.value;
        }

        if (status) {
            editingSurvey.status =
                status.value;
        }

        if (start) {
            editingSurvey.start =
                start.value;
        }

        if (end) {
            editingSurvey.end =
                end.value;
        }

        const numbering =
            document.querySelector(
                'input[name="numbering"]:checked'
            );

        if (numbering instanceof HTMLInputElement) {
            editingSurvey.numbering =
                numbering.value;
        }

        if (!editingSurvey.name) {
            showToast(
                'アンケート名を入力してください。'
            );
            return;
        }

        try {
            const result = await apiPost(
                button,
                'save_survey',
                {
                    survey: editingSurvey
                }
            );

            editingSurvey =
                result.data?.survey || editingSurvey;

            showToast(
                'アンケートを保存しました。'
            );

            await loadSurveyList();

            showPage('page-list');
        } catch (error) {
            showNotice(
                $('editor-notice'),
                error instanceof Error
                    ? error.message
                    : '保存できませんでした。',
                'error'
            );
        }
    }

    function previewEditor() {
        if (!editingSurvey) {
            return;
        }

        const body =
            document.createElement('div');

        const heading =
            document.createElement('h2');

        heading.textContent =
            String(
                editingSurvey.name ||
                'アンケート'
            );

        body.appendChild(heading);

        const description =
            document.createElement('p');

        description.textContent =
            String(
                editingSurvey.description || ''
            );

        body.appendChild(description);

        editingSurvey.groups.forEach(
            function (group, groupIndex) {
                const groupTitle =
                    document.createElement('h3');

                groupTitle.textContent =
                    String(group.name || '');

                body.appendChild(groupTitle);

                group.questions.forEach(
                    function (question, questionIndex) {
                        const questionBlock =
                            document.createElement('div');

                        questionBlock.className =
                            'public-question';

                        const title =
                            document.createElement('div');

                        title.className =
                            'public-question-title';

                        title.textContent =
                            questionNumber(
                                groupIndex,
                                questionIndex
                            ) +
                            ' ' +
                            String(
                                question.text ||
                                '未入力'
                            );

                        questionBlock.appendChild(title);

                        if (
                            question.type ===
                            'single' ||
                            question.type ===
                            'multiple'
                        ) {
                            (
                                question.options || []
                            ).forEach(
                                function (option) {
                                    const line =
                                        document.createElement(
                                            'div'
                                        );

                                    line.className =
                                        'public-option';

                                    line.textContent =
                                        '・' +
                                        String(
                                            option.text ||
                                            ''
                                        );

                                    questionBlock.appendChild(
                                        line
                                    );
                                }
                            );
                        }

                        body.appendChild(
                            questionBlock
                        );
                    }
                );
            }
        );

        openModal(
            'アンケート内容確認',
            body,
            []
        );
    }

    async function openDetail(id) {
        if (!id) {
            return;
        }

        try {
            const result = await apiGet(
                'load_survey',
                {id: id}
            );

            currentSurvey =
                result.data?.survey || null;

            if (!currentSurvey) {
                throw new Error(
                    'アンケートを取得できません。'
                );
            }

            currentSurveyId = id;

            const title = $('detail-title');
            const subtitle = $('detail-subtitle');

            if (title) {
                title.textContent =
                    String(
                        currentSurvey.name ||
                        ''
                    );
            }

            if (subtitle) {
                subtitle.textContent =
                    String(
                        currentSurvey.description ||
                        ''
                    );
            }

            currentDetailTab = 'content';

            renderDetail();

            showPage('page-detail');
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : 'アンケートを開けません。'
            );
        }
    }

    function renderDetail() {
        const container =
            $('detail-content');

        if (!container || !currentSurvey) {
            return;
        }

        container.textContent = '';

        document
            .querySelectorAll('.detail-tabs button')
            .forEach(function (button) {
                if (
                    button instanceof HTMLButtonElement
                ) {
                    button.classList.toggle(
                        'active',
                        button.dataset.tab ===
                        currentDetailTab
                    );
                }
            });

        if (currentDetailTab === 'content') {
            renderDetailContent(container);
        } else if (
            currentDetailTab === 'send'
        ) {
            renderDetailSend(container);
        } else if (
            currentDetailTab === 'status'
        ) {
            renderDetailStatus(container);
        } else if (
            currentDetailTab === 'result'
        ) {
            renderDetailResult(container);
        }
    }

    function renderDetailContent(container) {
        const summary =
            document.createElement('div');

        summary.className = 'detail-summary';

        [
            [
                '状態',
                currentSurvey.status === 'open'
                    ? '公開中'
                    : currentSurvey.status === 'end'
                        ? '終了'
                        : '下書き'
            ],
            [
                '回答数',
                String(
                    Number(
                        currentSurvey.answers || 0
                    )
                ) + '件'
            ],
            [
                '公開開始',
                String(
                    currentSurvey.start ||
                    '未設定'
                )
            ],
            [
                '公開終了',
                String(
                    currentSurvey.end ||
                    '未設定'
                )
            ]
        ].forEach(function (item) {
            const card =
                document.createElement('div');

            card.className = 'stat-card';

            const label =
                document.createElement('div');

            label.className = 'stat-label';
            label.textContent = item[0];

            const value =
                document.createElement('div');

            value.className = 'stat-value';
            value.textContent = item[1];

            card.appendChild(label);
            card.appendChild(value);

            summary.appendChild(card);
        });

        container.appendChild(summary);

        const card =
            document.createElement('div');

        card.className = 'card';

        const title =
            document.createElement('div');

        title.className = 'card-title';
        title.textContent = 'アンケート内容';

        card.appendChild(title);

        currentSurvey.groups.forEach(
            function (group, groupIndex) {
                const groupTitle =
                    document.createElement('h3');

                groupTitle.textContent =
                    String(group.name || '');

                card.appendChild(groupTitle);

                group.questions.forEach(
                    function (question, questionIndex) {
                        const q =
                            document.createElement('div');

                        q.className =
                            'public-question';

                        const qt =
                            document.createElement('div');

                        qt.className =
                            'public-question-title';

                        qt.textContent =
                            questionNumber(
                                groupIndex,
                                questionIndex
                            ) +
                            ' ' +
                            String(
                                question.text ||
                                '未入力'
                            );

                        q.appendChild(qt);

                        if (
                            question.type === 'single' ||
                            question.type === 'multiple'
                        ) {
                            (
                                question.options || []
                            ).forEach(
                                function (option) {
                                    const op =
                                        document.createElement(
                                            'div'
                                        );

                                    op.className =
                                        'public-option';

                                    op.textContent =
                                        '・' +
                                        String(
                                            option.text ||
                                            ''
                                        );

                                    q.appendChild(op);
                                }
                            );
                        }

                        card.appendChild(q);
                    }
                );
            }
        );

        container.appendChild(card);
    }

    async function renderDetailSend(container) {
        const layout =
            document.createElement('div');

        layout.className = 'send-layout';

        const left =
            document.createElement('div');

        left.className = 'card';

        const title =
            document.createElement('div');

        title.className = 'card-title';
        title.textContent = '送信対象';

        left.appendChild(title);

        const search =
            document.createElement('input');

        search.type = 'text';
        search.placeholder =
            '顧客名・メールアドレスで検索';
        search.id = 'send-customer-search';
        search.style.width = '100%';
        search.style.padding = '9px';
        search.style.border =
            '1px solid #cbd5e0';
        search.style.borderRadius = '5px';

        left.appendChild(search);

        const summary =
            document.createElement('div');

        summary.className =
            'selection-summary';

        summary.id = 'send-selection-summary';

        summary.textContent =
            '選択：0件';

        left.appendChild(summary);

        const tableWrap =
            document.createElement('div');

        tableWrap.className =
            'table-wrap';

        const table =
            document.createElement('table');

        table.className = 'table';

        const thead =
            document.createElement('thead');

        const headRow =
            document.createElement('tr');

        [
            '選択',
            '顧客名',
            'メールアドレス',
            '会社名'
        ].forEach(function (text) {
            const th =
                document.createElement('th');

            th.textContent = text;
            headRow.appendChild(th);
        });

        thead.appendChild(headRow);

        const tbody =
            document.createElement('tbody');

        tbody.id = 'send-customer-body';

        table.appendChild(thead);
        table.appendChild(tbody);
        tableWrap.appendChild(table);

        left.appendChild(tableWrap);

        const right =
            document.createElement('div');

        right.className = 'card';

        const rightTitle =
            document.createElement('div');

        rightTitle.className =
            'card-title';

        rightTitle.textContent =
            '送信内容';

        right.appendChild(rightTitle);

        const preview =
            document.createElement('div');

        preview.className =
            'email-preview';

        preview.textContent =
            'アンケートへのご回答をお願いします。\n\n' +
            String(
                currentSurvey.name || ''
            ) +
            '\n\n' +
            String(
                currentSurvey.description || ''
            );

        right.appendChild(preview);

        const sendButton =
            document.createElement('button');

        sendButton.type = 'button';
        sendButton.className =
            'btn btn-primary';

        const spinner =
            document.createElement('span');

        spinner.className =
            'loading-spinner';

        sendButton.appendChild(spinner);
        sendButton.appendChild(
            document.createTextNode(
                '選択した顧客へ送信'
            )
        );

        sendButton.addEventListener(
            'click',
            function () {
                sendSurvey(
                    sendButton
                );
            }
        );

        right.appendChild(sendButton);

        layout.appendChild(left);
        layout.appendChild(right);

        container.appendChild(layout);

        await loadCustomers();

        renderSendCustomers();

        if (search) {
            search.addEventListener(
                'input',
                function () {
                    renderSendCustomers();
                }
            );
        }
    }

    function getSelectedCustomerIds() {
        const checked =
            document.querySelectorAll(
                '#send-customer-body input[type="checkbox"]:checked'
            );

        const ids = [];

        checked.forEach(function (input) {
            if (
                input instanceof HTMLInputElement
            ) {
                ids.push(input.value);
            }
        });

        return ids;
    }

    function renderSendCustomers() {
        const body =
            $('send-customer-body');

        if (!body) {
            return;
        }

        body.textContent = '';

        const search =
            $('send-customer-search');

        const keyword =
            search
                ? search.value.trim().toLowerCase()
                : '';

        const filtered =
            customers.filter(
                function (customer) {
                    if (!keyword) {
                        return true;
                    }

                    const text =
                        String(
                            customer.name || ''
                        ) +
                        ' ' +
                        String(
                            customer.email || ''
                        ) +
                        ' ' +
                        String(
                            customer.company || ''
                        );

                    return text
                        .toLowerCase()
                        .includes(keyword);
                }
            );

        filtered.forEach(
            function (customer) {
                const tr =
                    document.createElement('tr');

                const checkTd =
                    document.createElement('td');

                const check =
                    document.createElement('input');

                check.type = 'checkbox';
                check.value =
                    String(customer.id || '');

                check.addEventListener(
                    'change',
                    updateSendSelection
                );

                checkTd.appendChild(check);

                const name =
                    document.createElement('td');

                name.textContent =
                    String(customer.name || '');

                const email =
                    document.createElement('td');

                email.textContent =
                    String(customer.email || '');

                const company =
                    document.createElement('td');

                company.textContent =
                    String(customer.company || '');

                tr.appendChild(checkTd);
                tr.appendChild(name);
                tr.appendChild(email);
                tr.appendChild(company);

                body.appendChild(tr);
            }
        );

        updateSendSelection();
    }

    function updateSendSelection() {
        const summary =
            $('send-selection-summary');

        if (!summary) {
            return;
        }

        const ids =
            getSelectedCustomerIds();

        summary.textContent =
            '選択：' +
            String(ids.length) +
            '件';
    }

    async function sendSurvey(button) {
        const ids =
            getSelectedCustomerIds();

        if (ids.length === 0) {
            showToast(
                '送信対象を選択してください。'
            );
            return;
        }

        if (
            !window.confirm(
                String(ids.length) +
                '件の顧客へ送信しますか？'
            )
        ) {
            return;
        }

        try {
            const result =
                await apiPost(
                    button,
                    'send_survey',
                    {
                        survey_id:
                            currentSurveyId,
                        customer_ids: ids
                    }
                );

            showToast(
                result.message ||
                '送信対象を登録しました。'
            );

            await openDetail(
                currentSurveyId
            );
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : '送信処理に失敗しました。'
            );
        }
    }

    async function renderDetailStatus(container) {
        const response =
            await loadAggregate(
                currentSurveyId
            );

        const total =
            Number(
                response?.data?.total_responses || 0
            );

        const target =
            Number(
                currentSurvey?.target || 0
            );

        const rate =
            target > 0
                ? Math.round(
                    total /
                    target *
                    1000
                ) / 10
                : 0;

        const summary =
            document.createElement('div');

        summary.className =
            'detail-summary';

        [
            ['回答数', String(total)],
            ['回答率', String(rate) + '%'],
            [
                '未回答数',
                String(
                    Math.max(
                        target - total,
                        0
                    )
                )
            ],
            [
                'メール送信済み',
                String(
                    Number(
                        currentSurvey?.sent || 0
                    )
                )
            ]
        ].forEach(function (item) {
            const card =
                document.createElement('div');

            card.className = 'stat-card';

            const label =
                document.createElement('div');

            label.className = 'stat-label';
            label.textContent = item[0];

            const value =
                document.createElement('div');

            value.className = 'stat-value';
            value.textContent = item[1];

            card.appendChild(label);
            card.appendChild(value);

            summary.appendChild(card);
        });

        container.appendChild(summary);

        const card =
            document.createElement('div');

        card.className = 'card';

        const title =
            document.createElement('div');

        title.className = 'card-title';
        title.textContent = '回答状況';

        card.appendChild(title);

        const progress =
            document.createElement('div');

        progress.className = 'progress';

        const bar =
            document.createElement('span');

        bar.style.width =
            Math.min(rate, 100) + '%';

        progress.appendChild(bar);

        card.appendChild(progress);

        container.appendChild(card);
    }

    async function renderDetailResult(container) {
        const result =
            await loadAggregate(
                currentSurveyId
            );

        const results =
            Array.isArray(
                result?.data?.results
            )
                ? result.data.results
                : [];

        if (results.length === 0) {
            const notice =
                document.createElement('div');

            notice.className =
                'notice';

            notice.textContent =
                '回答結果はまだありません。';

            container.appendChild(notice);

            return;
        }

        const layout =
            document.createElement('div');

        layout.className =
            'result-layout';

        const menu =
            document.createElement('div');

        menu.className = 'card';

        const content =
            document.createElement('div');

        content.className = '
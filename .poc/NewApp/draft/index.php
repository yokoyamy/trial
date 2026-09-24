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
    return htmlspecialchars(
        $str ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
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

function csrf_token(): string
{
    $token = $_SESSION[APP_SESSION_KEY]['csrf_token'] ?? '';

    if (!is_string($token) || $token === '') {
        json_response(
            false,
            'セキュリティ情報を取得できません。',
            [],
            [],
            500
        );
    }

    return $token;
}

function request_method(): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    return is_string($method) ? strtoupper($method) : 'GET';
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
        'updated_at' => '',
        'mail' => [
            'smtp_server' => '',
            'smtp_port' => 587,
            'connection_type' => 'tls',
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => 'アンケート事務局',
            'configured' => false,
            'tested_at' => ''
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
            'tested_at' => ''
        ]
    ];
}

function default_customers(): array
{
    return [
        'version' => 1,
        'updated_at' => '',
        'source' => 'kintone',
        'count' => 0,
        'customers' => []
    ];
}

function default_surveys(): array
{
    return [
        'version' => 1,
        'updated_at' => '',
        'surveys' => []
    ];
}

function default_responses(): array
{
    return [
        'version' => 1,
        'updated_at' => '',
        'responses' => []
    ];
}

function default_mail_logs(): array
{
    return [
        'version' => 1,
        'updated_at' => '',
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

    $http = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20,
        'request_fulluri' => true
    ];

    if ($method !== 'GET' && $payload !== []) {
        try {
            $http['content'] = json_encode(
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

    $context_options = [
        'http' => $http,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];

    $proxy_host = trim((string)($config['proxy_host'] ?? ''));
    $proxy_port = trim((string)($config['proxy_port'] ?? ''));

    if ($proxy_host !== '') {
        $proxy = $proxy_host;

        if ($proxy_port !== '') {
            $proxy .= ':' . $proxy_port;
        }

        $context_options['http']['proxy'] = 'tcp://' . $proxy;
        $context_options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($context_options);

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    $headers_result = get_safe_response_headers();
    $status = extract_http_status($headers_result);

    $decoded = [];

    if ($response !== false && trim($response) !== '') {
        $decoded_value = json_decode($response, true);

        if (is_array($decoded_value)) {
            $decoded = $decoded_value;
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

    $details = [];

    if (isset($decoded['code']) && is_string($decoded['code'])) {
        $details[] = 'コード: ' . $decoded['code'];
    }

    if (isset($decoded['errors']) && is_array($decoded['errors'])) {
        foreach ($decoded['errors'] as $field => $error) {
            if (!is_array($error)) {
                continue;
            }

            $messages = $error['messages'] ?? [];

            if (!is_array($messages)) {
                continue;
            }

            foreach ($messages as $error_message) {
                if (is_string($error_message)) {
                    $details[] =
                        (string)$field . ': ' . $error_message;
                }
            }
        }
    }

    if ($details !== []) {
        $message .= ' ' . implode(' / ', $details);
    }

    return [
        'success' => false,
        'status' => $status,
        'message' => $message,
        'data' => $decoded
    ];
}

function test_kintone(array $settings): array
{
    $domain = trim((string)($settings['domain'] ?? ''));
    $login = trim((string)($settings['login_name'] ?? ''));
    $password = (string)($settings['password'] ?? '');
    $app_id = trim((string)($settings['customer_app_id'] ?? ''));

    if ($domain === '' || $login === '' || $password === '') {
        return [
            'success' => false,
            'message' =>
                'kintoneのドメイン、ログイン名、パスワードを入力してください。'
        ];
    }

    $headers = [
        make_cybozu_auth_header($login, $password),
        'Accept: application/json'
    ];

    if ($app_id !== '') {
        $params = [
            'id' => $app_id
        ];

        $query = http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $url = kintone_build_url(
            $domain,
            '/k/v1/app.json?' . $query
        );
    } else {
        $params = [
            'limit' => 1
        ];

        $query = http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $url = kintone_build_url(
            $domain,
            '/k/v1/apps.json?' . $query
        );
    }

    return kintone_api_request(
        'GET',
        $url,
        $headers,
        [],
        [
            'proxy_host' =>
                trim((string)($settings['proxy_host'] ?? '')),
            'proxy_port' =>
                trim((string)($settings['proxy_port'] ?? ''))
        ]
    );
}

function fetch_kintone_customers(array $settings): array
{
    $domain = trim((string)($settings['domain'] ?? ''));
    $login = trim((string)($settings['login_name'] ?? ''));
    $password = (string)($settings['password'] ?? '');
    $app_id = trim((string)($settings['customer_app_id'] ?? ''));

    if (
        $domain === '' ||
        $login === '' ||
        $password === '' ||
        $app_id === ''
    ) {
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
        'app' => $app_id,
        'totalCount' => 'true',
        'query' => 'order by $id asc limit 500'
    ];

    $query = http_build_query(
        $params,
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    $url = kintone_build_url(
        $domain,
        '/k/v1/records.json?' . $query
    );

    $result = kintone_api_request(
        'GET',
        $url,
        $headers,
        [],
        [
            'proxy_host' =>
                trim((string)($settings['proxy_host'] ?? '')),
            'proxy_port' =>
                trim((string)($settings['proxy_port'] ?? ''))
        ]
    );

    if (!$result['success']) {
        return $result;
    }

    $records = $result['data']['records'] ?? [];

    if (!is_array($records)) {
        $records = [];
    }

    $id_field =
        trim((string)($settings['customer_id_field'] ?? ''));

    $name_field =
        trim((string)($settings['customer_name_field'] ?? ''));

    $email_field =
        trim((string)($settings['customer_email_field'] ?? ''));

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $id = '';
        $name = '';
        $email = '';

        if (
            $id_field !== '' &&
            isset($record[$id_field]['value'])
        ) {
            $id = (string)$record[$id_field]['value'];
        }

        if (
            $name_field !== '' &&
            isset($record[$name_field]['value'])
        ) {
            $name = (string)$record[$name_field]['value'];
        }

        if (
            $email_field !== '' &&
            isset($record[$email_field]['value'])
        ) {
            $email = (string)$record[$email_field]['value'];
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
 * survey
 * ========================================================= */

function normalize_question(array $question): array
{
    $type = (string)($question['type'] ?? 'free');

    if (!in_array(
        $type,
        ['free', 'single', 'multiple'],
        true
    )) {
        $type = 'free';
    }

    $options = [];

    if (
        isset($question['options']) &&
        is_array($question['options'])
    ) {
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
        'id' =>
            (string)($question['id'] ?? generate_id('question')),
        'text' => (string)($question['text'] ?? ''),
        'type' => $type,
        'required' => !empty($question['required']),
        'options' => $options
    ];
}

function normalize_survey(array $survey): array
{
    $groups = [];

    if (
        isset($survey['groups']) &&
        is_array($survey['groups'])
    ) {
        foreach ($survey['groups'] as $group) {
            if (!is_array($group)) {
                continue;
            }

            $questions = [];

            if (
                isset($group['questions']) &&
                is_array($group['questions'])
            ) {
                foreach ($group['questions'] as $question) {
                    if (is_array($question)) {
                        $questions[] =
                            normalize_question($question);
                    }
                }
            }

            $groups[] = [
                'id' =>
                    (string)($group['id'] ?? generate_id('group')),
                'name' =>
                    (string)($group['name'] ?? 'グループ'),
                'questions' => $questions
            ];
        }
    }

    return [
        'id' =>
            (string)($survey['id'] ?? generate_id('survey')),
        'name' => trim((string)($survey['name'] ?? '')),
        'description' =>
            (string)($survey['description'] ?? ''),
        'status' =>
            in_array(
                (string)($survey['status'] ?? 'draft'),
                ['draft', 'open', 'end'],
                true
            )
                ? (string)$survey['status']
                : 'draft',
        'created' =>
            (string)($survey['created'] ?? date('Y-m-d')),
        'start' => (string)($survey['start'] ?? ''),
        'end' => (string)($survey['end'] ?? ''),
        'answers' => (int)($survey['answers'] ?? 0),
        'target' => (int)($survey['target'] ?? 0),
        'sent' => (int)($survey['sent'] ?? 0),
        'updated' =>
            (string)($survey['updated'] ?? date('Y-m-d')),
        'numbering' =>
            in_array(
                (string)($survey['numbering'] ?? 'global'),
                ['global', 'group'],
                true
            )
                ? (string)$survey['numbering']
                : 'global',
        'mail_subject' =>
            (string)($survey['mail_subject'] ?? ''),
        'mail_body' =>
            (string)($survey['mail_body'] ?? ''),
        'groups' => $groups
    ];
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

function survey_find(array $surveys, string $id): ?array
{
    $index = survey_index($surveys, $id);

    if ($index < 0 || !isset($surveys[$index])) {
        return null;
    }

    return is_array($surveys[$index])
        ? $surveys[$index]
        : null;
}

function validate_survey(array $survey): array
{
    $errors = [];

    $name = trim((string)($survey['name'] ?? ''));

    if ($name === '') {
        $errors[] = 'アンケート名を入力してください。';
    }

    $start = trim((string)($survey['start'] ?? ''));
    $end = trim((string)($survey['end'] ?? ''));

    if ($start !== '' && $end !== '' && $start > $end) {
        $errors[] =
            '公開開始日は公開終了日以前にしてください。';
    }

    $groups =
        isset($survey['groups']) &&
        is_array($survey['groups'])
            ? $survey['groups']
            : [];

    if ($groups === []) {
        $errors[] = 'グループを1つ以上作成してください。';
    }

    $question_ids = [];

    foreach ($groups as $group_index => $group) {
        if (!is_array($group)) {
            continue;
        }

        $group_name =
            trim((string)($group['name'] ?? ''));

        if ($group_name === '') {
            $errors[] =
                'グループ' .
                ((int)$group_index + 1) .
                'の名前を入力してください。';
        }

        $questions =
            isset($group['questions']) &&
            is_array($group['questions'])
                ? $group['questions']
                : [];

        foreach ($questions as $question_index => $question) {
            if (!is_array($question)) {
                continue;
            }

            $qid = (string)($question['id'] ?? '');

            if ($qid !== '') {
                $question_ids[$qid] = true;
            }

            $number =
                'Q' .
                ((int)$group_index + 1) .
                '-' .
                ((int)$question_index + 1);

            if (
                (string)($survey['numbering'] ?? 'global') ===
                'global'
            ) {
                $counter = 0;

                for ($g = 0; $g <= $group_index; $g++) {
                    $group_questions =
                        $groups[$g]['questions'] ?? [];

                    if (!is_array($group_questions)) {
                        continue;
                    }

                    $counter += count($group_questions);
                }

                $number = 'Q' . $counter;
            }

            $text = trim(
                (string)($question['text'] ?? '')
            );

            if ($text === '') {
                $errors[] =
                    $number .
                    'の質問文を入力してください。';
            }

            $type = (string)($question['type'] ?? 'free');

            if (
                in_array(
                    $type,
                    ['single', 'multiple'],
                    true
                )
            ) {
                $options =
                    isset($question['options']) &&
                    is_array($question['options'])
                        ? $question['options']
                        : [];

                if ($options === []) {
                    $errors[] =
                        $number .
                        'に選択肢を設定してください。';
                }

                foreach ($options as $option) {
                    if (!is_array($option)) {
                        continue;
                    }

                    if (
                        trim(
                            (string)($option['text'] ?? '')
                        ) === ''
                    ) {
                        $errors[] =
                            $number .
                            'の選択肢を入力してください。';
                    }
                }
            }
        }
    }

    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $questions =
            isset($group['questions']) &&
            is_array($group['questions'])
                ? $group['questions']
                : [];

        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }

            if (
                (string)($question['type'] ?? '') !==
                'single'
            ) {
                continue;
            }

            $options =
                isset($question['options']) &&
                is_array($question['options'])
                    ? $question['options']
                    : [];

            foreach ($options as $option) {
                if (!is_array($option)) {
                    continue;
                }

                $branch =
                    trim((string)($option['branch'] ?? ''));

                if (
                    $branch !== '' &&
                    $branch !== 'next' &&
                    $branch !== 'end' &&
                    !isset($question_ids[$branch])
                ) {
                    $errors[] =
                        '分岐先に存在しない質問が設定されています。';
                }
            }
        }
    }

    return $errors;
}

/* =========================================================
 * SMTP
 * ========================================================= */

function smtp_read($socket): string
{
    $result = '';

    while (!feof($socket)) {
        $line = fgets($socket, 512);

        if ($line === false) {
            break;
        }

        $result .= $line;

        if (
            strlen($line) >= 4 &&
            $line[3] === ' '
        ) {
            break;
        }
    }

    return $result;
}

function smtp_expect($socket, array $codes): bool
{
    $response = smtp_read($socket);

    if (
        preg_match(
            '/^(\d{3})/',
            $response,
            $matches
        )
    ) {
        return in_array(
            (int)$matches[1],
            $codes,
            true
        );
    }

    return false;
}

function smtp_command(
    $socket,
    string $command,
    array $codes
): bool {
    fwrite($socket, $command . "\r\n");
    return smtp_expect($socket, $codes);
}

function smtp_send_mail(
    array $mail_settings,
    string $to,
    string $subject,
    string $body
): bool {
    $server =
        trim((string)($mail_settings['smtp_server'] ?? ''));

    $port =
        (int)($mail_settings['smtp_port'] ?? 587);

    $username =
        (string)($mail_settings['username'] ?? '');

    $password =
        (string)($mail_settings['password'] ?? '');

    $from_email =
        trim((string)($mail_settings['from_email'] ?? ''));

    $from_name =
        trim((string)($mail_settings['from_name'] ?? ''));

    $connection_type =
        (string)($mail_settings['connection_type'] ?? 'tls');

    if (
        $server === '' ||
        $port <= 0 ||
        $from_email === ''
    ) {
        return false;
    }

    $remote = 'tcp://' . $server . ':' . $port;

    if ($connection_type === 'ssl') {
        $remote =
            'ssl://' . $server . ':' . $port;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        return false;
    }

    stream_set_timeout($socket, 15);

    if (!smtp_expect($socket, [220])) {
        fclose($socket);
        return false;
    }

    $hostname =
        gethostname();

    if (!is_string($hostname) || $hostname === '') {
        $hostname = 'localhost';
    }

    if (!smtp_command(
        $socket,
        'EHLO ' . $hostname,
        [250]
    )) {
        fclose($socket);
        return false;
    }

    if ($connection_type === 'tls') {
        if (!smtp_command(
            $socket,
            'STARTTLS',
            [220]
        )) {
            fclose($socket);
            return false;
        }

        $crypto = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);
            return false;
        }

        if (!smtp_command(
            $socket,
            'EHLO ' . $hostname,
            [250]
        )) {
            fclose($socket);
            return false;
        }
    }

    if ($username !== '') {
        if (!smtp_command(
            $socket,
            'AUTH LOGIN',
            [334]
        )) {
            fclose($socket);
            return false;
        }

        if (!smtp_command(
            $socket,
            base64_encode($username),
            [334]
        )) {
            fclose($socket);
            return false;
        }

        if (!smtp_command(
            $socket,
            base64_encode($password),
            [235]
        )) {
            fclose($socket);
            return false;
        }
    }

    if (!smtp_command(
        $socket,
        'MAIL FROM:<' . $from_email . '>',
        [250]
    )) {
        fclose($socket);
        return false;
    }

    if (!smtp_command(
        $socket,
        'RCPT TO:<' . $to . '>',
        [250, 251]
    )) {
        fclose($socket);
        return false;
    }

    if (!smtp_command(
        $socket,
        'DATA',
        [354]
    )) {
        fclose($socket);
        return false;
    }

    $encoded_subject =
        '=?UTF-8?B?' .
        base64_encode($subject) .
        '?=';

    $encoded_name =
        '=?UTF-8?B?' .
        base64_encode($from_name) .
        '?=';

    $headers = [
        'From: ' . $encoded_name . ' <' . $from_email . '>',
        'To: <' . $to . '>',
        'Subject: ' . $encoded_subject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit'
    ];

    $message =
        implode("\r\n", $headers) .
        "\r\n\r\n" .
        str_replace(
            ["\r\n", "\r"],
            "\n",
            $body
        );

    $message =
        str_replace(
            "\n",
            "\r\n",
            $message
        );

    fwrite(
        $socket,
        $message .
        "\r\n.\r\n"
    );

    if (!smtp_expect($socket, [250])) {
        fclose($socket);
        return false;
    }

    smtp_command(
        $socket,
        'QUIT',
        [221]
    );

    fclose($socket);

    return true;
}

/* =========================================================
 * API
 * ========================================================= */

initialize_data_files();

if (requested_action() !== '') {
    $action = requested_action();

    if (request_method() === 'POST') {
        validate_csrf();
    }

    if ($action === 'load_surveys') {
        $data = load_surveys();

        json_response(
            true,
            '',
            [
                'surveys' =>
                    is_array($data['surveys'] ?? null)
                        ? $data['surveys']
                        : []
            ]
        );
    }

    if ($action === 'load_survey') {
        $id = $_GET['id'] ?? '';

        if (!is_string($id) || trim($id) === '') {
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
            is_array($data['surveys'] ?? null)
                ? $data['surveys']
                : [],
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

        json_response(
            true,
            '',
            [
                'survey' => $survey
            ]
        );
    }

    if ($action === 'save_survey') {
        $request = read_json_request();
        $survey_input =
            isset($request['survey']) &&
            is_array($request['survey'])
                ? $request['survey']
                : [];

        $survey = normalize_survey($survey_input);

        $errors = validate_survey($survey);

        if ($errors !== []) {
            json_response(
                false,
                '入力内容を確認してください。',
                [],
                $errors,
                422
            );
        }

        $data = load_surveys();

        $surveys =
            is_array($data['surveys'] ?? null)
                ? $data['surveys']
                : [];

        $index = survey_index(
            $surveys,
            $survey['id']
        );

        $today = date('Y-m-d');

        if ($index >= 0) {
            $survey['created'] =
                (string)(
                    $surveys[$index]['created'] ??
                    $today
                );

            $survey['updated'] = $today;
            $surveys[$index] = $survey;
        } else {
            $survey['created'] = $today;
            $survey['updated'] = $today;
            $surveys[] = $survey;
        }

        $data['surveys'] = array_values($surveys);
        $data['updated_at'] = now();

        write_json_file(
            SURVEYS_FILE,
            $data
        );

        json_response(
            true,
            'アンケートを保存しました。',
            [
                'survey' => $survey
            ]
        );
    }

    if ($action === 'publish_survey') {
        $request = read_json_request();
        $id =
            isset($request['id'])
                ? (string)$request['id']
                : '';

        $data = load_surveys();
        $surveys =
            is_array($data['surveys'] ?? null)
                ? $data['surveys']
                : [];

        $index = survey_index($surveys, $id);

        if ($index < 0) {
            json_response(
                false,
                'アンケートが見つかりません。',
                [],
                [],
                404
            );
        }

        $errors = validate_survey($surveys[$index]);

        if ($errors !== []) {
            json_response(
                false,
                '公開前に入力内容を確認してください。',
                [],
                $errors,
                422
            );
        }

        $surveys[$index]['status'] = 'open';
        $surveys[$index]['updated'] = date('Y-m-d');

        $data['surveys'] = $surveys;
        $data['updated_at'] = now();

        write_json_file(
            SURVEYS_FILE,
            $data
        );

        json_response(
            true,
            'アンケートを公開しました。',
            [
                'survey' => $surveys[$index]
            ]
        );
    }

    if ($action === 'close_survey') {
        $request = read_json_request();
        $id =
            isset($request['id'])
                ? (string)$request['id']
                : '';

        $data = load_surveys();
        $surveys =
            is_array($data['surveys'] ?? null)
                ? $data['surveys']
                : [];

        $index = survey_index($surveys, $id);

        if ($index < 0) {
            json_response(
                false,
                'アンケートが見つかりません。',
                [],
                [],
                404
            );
        }

        $surveys[$index]['status'] = 'end';
        $surveys[$index]['updated'] = date('Y-m-d');

        $data['surveys'] = $surveys;
        $data['updated_at'] = now();

        write_json_file(
            SURVEYS_FILE,
            $data
        );

        json_response(
            true,
            'アンケートを終了しました。',
            [
                'survey' => $surveys[$index]
            ]
        );
    }

    if ($action === 'delete_survey') {
        $request = read_json_request();
        $id =
            isset($request['id'])
                ? (string)$request['id']
                : '';

        $data = load_surveys();
        $surveys =
            is_array($data['surveys'] ?? null)
                ? $data['surveys']
                : [];

        $index = survey_index($surveys, $id);

        if ($index < 0) {
            json_response(
                false,
                'アンケートが見つかりません。',
                [],
                [],
                404
            );
        }

        if (
            (string)($surveys[$index]['status'] ?? '') !==
            'draft'
        ) {
            json_response(
                false,
                '下書きのアンケートだけ削除できます。',
                [],
                [],
                422
            );
        }

        array_splice($surveys, $index, 1);

        $data['surveys'] = array_values($surveys);
        $data['updated_at'] = now();

        write_json_file(
            SURVEYS_FILE,
            $data
        );

        json_response(
            true,
            'アンケートを削除しました。'
        );
    }

    if ($action === 'load_customers') {
        $data = load_customers();

        json_response(
            true,
            '',
            [
                'customers' =>
                    is_array($data['customers'] ?? null)
                        ? $data['customers']
                        : []
            ]
        );
    }

    if ($action === 'refresh_customers') {
        $settings = load_settings();
        $result = fetch_kintone_customers(
            $settings['kintone'] ?? []
        );

        if (!$result['success']) {
            json_response(
                false,
                (string)(
                    $result['message'] ??
                    '顧客一覧を取得できません。'
                ),
                [],
                [],
                502
            );
        }

        $customers =
            is_array($result['customers'] ?? null)
                ? $result['customers']
                : [];

        $data = [
            'version' => 1,
            'updated_at' => now(),
            'source' => 'kintone',
            'count' => count($customers),
            'customers' => $customers
        ];

        write_json_file(
            CUSTOMERS_FILE,
            $data
        );

        json_response(
            true,
            '顧客一覧を更新しました。',
            [
                'customers' => $customers
            ]
        );
    }

    if ($action === 'load_settings') {
        $settings = load_settings();

        $mail = $settings['mail'] ?? [];
        $kintone = $settings['kintone'] ?? [];

        $mail['password'] = '';
        $kintone['password'] = '';

        json_response(
            true,
            '',
            [
                'mail' => $mail,
                'kintone' => $kintone
            ]
        );
    }

    if ($action === 'save_settings') {
        $request = read_json_request();
        $current = load_settings();

        $mail_input =
            isset($request['mail']) &&
            is_array($request['mail'])
                ? $request['mail']
                : [];

        $kintone_input =
            isset($request['kintone']) &&
            is_array($request['kintone'])
                ? $request['kintone']
                : [];

        $mail = $current['mail'] ?? [];
        $kintone = $current['kintone'] ?? [];

        foreach (
            [
                'smtp_server',
                'smtp_port',
                'connection_type',
                'username',
                'from_email',
                'from_name'
            ] as $key
        ) {
            if (array_key_exists($key, $mail_input)) {
                $mail[$key] =
                    is_scalar($mail_input[$key])
                        ? (string)$mail_input[$key]
                        : '';
            }
        }

        if (
            array_key_exists('password', $mail_input) &&
            (string)$mail_input['password'] !== ''
        ) {
            $mail['password'] =
                (string)$mail_input['password'];
        }

        foreach (
            [
                'domain',
                'login_name',
                'customer_app_id',
                'customer_id_field',
                'customer_name_field',
                'customer_email_field',
                'proxy_host',
                'proxy_port'
            ] as $key
        ) {
            if (array_key_exists($key, $kintone_input)) {
                $kintone[$key] =
                    is_scalar($kintone_input[$key])
                        ? (string)$kintone_input[$key]
                        : '';
            }
        }

        if (
            array_key_exists('password', $kintone_input) &&
            (string)$kintone_input['password'] !== ''
        ) {
            $kintone['password'] =
                (string)$kintone_input['password'];
        }

        $mail['smtp_port'] =
            (int)($mail['smtp_port'] ?? 587);

        $mail['configured'] =
            trim((string)($mail['smtp_server'] ?? '')) !== '' &&
            trim((string)($mail['from_email'] ?? '')) !== '';

        $kintone['configured'] =
            trim((string)($kintone['domain'] ?? '')) !== '' &&
            trim((string)($kintone['login_name'] ?? '')) !== '' &&
            (string)($kintone['password'] ?? '') !== '' &&
            trim((string)($kintone['customer_app_id'] ?? '')) !== '';

        $settings = [
            'version' => 1,
            'updated_at' => now(),
            'mail' => $mail,
            'kintone' => $kintone
        ];

        write_json_file(
            SETTINGS_FILE,
            $settings
        );

        json_response(
            true,
            '設定を保存しました。'
        );
    }

    if ($action === 'test_kintone') {
        $settings = load_settings();
        $result = test_kintone(
            $settings['kintone'] ?? []
        );

        if (!$result['success']) {
            json_response(
                false,
                (string)(
                    $result['message'] ??
                    'kintoneへの接続に失敗しました。'
                ),
                [],
                [],
                502
            );
        }

        $settings['kintone']['connection_status'] =
            'connected';

        $settings['kintone']['tested_at'] = now();

        write_json_file(
            SETTINGS_FILE,
            $settings
        );

        json_response(
            true,
            'kintoneへの接続を確認しました。'
        );
    }

    if ($action === 'send_survey') {
        $request = read_json_request();

        $survey_id =
            isset($request['survey_id'])
                ? (string)$request['survey_id']
                : '';

        $customer_ids =
            isset($request['customer_ids']) &&
            is_array($request['customer_ids'])
                ? array_map(
                    'strval',
                    $request['customer_ids']
                )
                : [];

        $subject =
            isset($request['subject'])
                ? (string)$request['subject']
                : '';

        $body =
            isset($request['body'])
                ? (string)$request['body']
                : '';

        $data = load_surveys();

        $surveys =
            is_array($data['surveys'] ?? null)
                ? $data['surveys']
                : [];

        $survey = survey_find(
            $surveys,
            $survey_id
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

        if ($subject === '') {
            $subject =
                (string)$survey['name'] .
                'へのご回答をお願いします';
        }

        if ($body === '') {
            $body =
                "アンケートへのご回答をお願いします。\n\n" .
                (string)$survey['name'];
        }

        $settings = load_settings();
        $mail_settings = $settings['mail'] ?? [];

        if (
            empty($mail_settings['configured'])
        ) {
            json_response(
                false,
                'メール送信設定が完了していません。設定を確認してください。',
                [],
                [],
                422
            );
        }

        $customer_data = load_customers();

        $customers =
            is_array($customer_data['customers'] ?? null)
                ? $customer_data['customers']
                : [];

        $selected = [];

        foreach ($customers as $customer) {
            if (!is_array($customer)) {
                continue;
            }

            $customer_id =
                (string)($customer['id'] ?? '');

            if (
                $customer_id !== '' &&
                in_array(
                    $customer_id,
                    $customer_ids,
                    true
                )
            ) {
                $selected[] = $customer;
            }
        }

        if ($selected === []) {
            json_response(
                false,
                '送信対象を選択してください。',
                [],
                [],
                422
            );
        }

        $success_count = 0;
        $failure_count = 0;
        $failures = [];

        foreach ($selected as $customer) {
            $email =
                trim((string)($customer['email'] ?? ''));

            if (
                $email === '' ||
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                $failure_count++;
                $failures[] = [
                    'id' =>
                        (string)($customer['id'] ?? ''),
                    'name' =>
                        (string)($customer['name'] ?? ''),
                    'email' => $email,
                    'message' =>
                        'メールアドレスが正しくありません。'
                ];
                continue;
            }

            $respond_url =
                (isset($_SERVER['HTTPS']) &&
                $_SERVER['HTTPS'] !== 'off'
                    ? 'https'
                    : 'http') .
                '://' .
                (string)(
                    $_SERVER['HTTP_HOST'] ??
                    'localhost'
                ) .
                application_api_path() .
                '?respond=' .
                rawurlencode(
                    (string)$survey['id']
                );

            $personal_body =
                $body .
                "\n\n回答はこちら：\n" .
                $respond_url;

            $sent = smtp_send_mail(
                $mail_settings,
                $email,
                $subject,
                $personal_body
            );

            if ($sent) {
                $success_count++;
            } else {
                $failure_count++;

                $failures[] = [
                    'id' =>
                        (string)($customer['id'] ?? ''),
                    'name' =>
                        (string)($customer['name'] ?? ''),
                    'email' => $email,
                    'message' =>
                        'メール送信に失敗しました。'
                ];
            }
        }

        $survey_index =
            survey_index(
                $surveys,
                $survey_id
            );

        if ($survey_index >= 0) {
            $surveys[$survey_index]['target'] =
                count($selected);

            $surveys[$survey_index]['sent'] =
                (int)(
                    $surveys[$survey_index]['sent'] ?? 0
                ) + $success_count;

            $surveys[$survey_index]['updated'] =
                date('Y-m-d');

            $data['surveys'] = $surveys;
            $data['updated_at'] = now();

            write_json_file(
                SURVEYS_FILE,
                $data
            );
        }

        $logs = load_mail_logs();

        if (!isset($logs['logs']) || !is_array($logs['logs'])) {
            $logs['logs'] = [];
        }

        $logs['logs'][] = [
            'id' => generate_id('mail'),
            'survey_id' => $survey_id,
            'sent_at' => now(),
            'target_count' => count($selected),
            'success_count' => $success_count,
            'failure_count' => $failure_count,
            'failures' => $failures
        ];

        $logs['updated_at'] = now();

        write_json_file(
            MAIL_LOGS_FILE,
            $logs
        );

        json_response(
            true,
            'メール送信処理が完了しました。',
            [
                'target_count' => count($selected),
                'success_count' => $success_count,
                'failure_count' => $failure_count,
                'failures' => $failures
            ]
        );
    }

    if ($action === 'aggregate') {
        $survey_id =
            isset($_GET['id'])
                ? (string)$_GET['id']
                : '';

        $response_data = load_responses();

        $responses =
            is_array($response_data['responses'] ?? null)
                ? $response_data['responses']
                : [];

        $filtered = [];

        foreach ($responses as $response) {
            if (
                is_array($response) &&
                (string)($response['survey_id'] ?? '') ===
                $survey_id
            ) {
                $filtered[] = $response;
            }
        }

        $survey_data = load_surveys();

        $survey = survey_find(
            is_array($survey_data['surveys'] ?? null)
                ? $survey_data['surveys']
                : [],
            $survey_id
        );

        $results = [];

        if ($survey !== null) {
            $questions = [];

            foreach (
                $survey['groups'] ?? [] as $group
            ) {
                if (!is_array($group)) {
                    continue;
                }

                foreach (
                    $group['questions'] ?? [] as $question
                ) {
                    if (is_array($question)) {
                        $questions[] = $question;
                    }
                }
            }

            foreach ($questions as $question) {
                $qid =
                    (string)($question['id'] ?? '');

                $result = [
                    'question_id' => $qid,
                    'question' =>
                        (string)($question['text'] ?? ''),
                    'type' =>
                        (string)($question['type'] ?? 'free'),
                    'count' => 0,
                    'options' => [],
                    'free_answers' => []
                ];

                $options =
                    isset($question['options']) &&
                    is_array($question['options'])
                        ? $question['options']
                        : [];

                foreach ($options as $option) {
                    if (!is_array($option)) {
                        continue;
                    }

                    $text =
                        (string)($option['text'] ?? '');

                    $result['options'][$text] = 0;
                }

                foreach ($filtered as $response) {
                    $answers =
                        isset($response['answers']) &&
                        is_array($response['answers'])
                            ? $response['answers']
                            : [];

                    if (!array_key_exists($qid, $answers)) {
                        continue;
                    }

                    $answer = $answers[$qid];
                    $result['count']++;

                    if ($result['type'] === 'free') {
                        $result['free_answers'][] =
                            (string)$answer;
                    } elseif (
                        $result['type'] === 'multiple' &&
                        is_array($answer)
                    ) {
                        foreach ($answer as $value) {
                            $value = (string)$value;

                            if (
                                isset(
                                    $result['options'][$value]
                                )
                            ) {
                                $result['options'][$value]++;
                            }
                        }
                    } else {
                        $value = (string)$answer;

                        if (
                            isset(
                                $result['options'][$value]
                            )
                        ) {
                            $result['options'][$value]++;
                        }
                    }
                }

                $results[] = $result;
            }
        }

        json_response(
            true,
            '',
            [
                'total_responses' => count($filtered),
                'results' => $results
            ]
        );
    }

    json_response(
        false,
        '指定された処理は存在しません。',
        [],
        [],
        404
    );
}

/* =========================================================
 * public respondent page
 * ========================================================= */

$respond_id = $_GET['respond'] ?? '';

if (
    is_string($respond_id) &&
    trim($respond_id) !== ''
) {
    $survey_data = load_surveys();

    $survey = survey_find(
        is_array($survey_data['surveys'] ?? null)
            ? $survey_data['surveys']
            : [],
        $respond_id
    );

    if (
        $survey === null ||
        (string)($survey['status'] ?? '') !== 'open'
    ) {
        http_response_code(404);
        ?>
        <!DOCTYPE html>
        <html lang="ja">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>アンケート</title>
            <style>
                body{
                    margin:0;
                    padding:40px 20px;
                    background:#f4f6f8;
                    font-family:-apple-system,BlinkMacSystemFont,
                        "Segoe UI","Yu Gothic",Meiryo,sans-serif;
                    color:#263238
                }
                .box{
                    max-width:720px;
                    margin:auto;
                    background:#fff;
                    border:1px solid #dfe5eb;
                    border-radius:8px;
                    padding:32px
                }
            </style>
        </head>
        <body>
        <div class="box">
            <h1>アンケートを表示できません</h1>
            <p>このアンケートは現在公開されていないか、終了しています。</p>
        </div>
        </body>
        </html>
        <?php
        exit;
    }
}

/* =========================================================
 * HTML
 * ========================================================= */

$csrf = csrf_token();
$api_path = application_api_path();

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= h($csrf) ?>">
<meta name="api-path" content="<?= h($api_path) ?>">
<meta name="color-scheme" content="light">
<link rel="icon"
      href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='10' fill='%231f3a5f'/%3E%3Cpath d='M17 18h30v8H17zm0 13h30v8H17zm0 13h20v8H17z' fill='white'/%3E%3C/svg%3E">
<title>アンケート業務運営</title>

<style>
*{
    box-sizing:border-box;
}

html,
body{
    margin:0;
    padding:0;
    min-height:100%;
}

body{
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Yu Gothic",
        Meiryo,
        sans-serif;
    color:#263238;
    background:#f4f6f8;
    font-size:14px;
}

button,
input,
textarea,
select{
    font:inherit;
}

button{
    cursor:pointer;
}

button:disabled{
    cursor:not-allowed;
    opacity:.55;
}

.hidden{
    display:none !important;
}

.topbar{
    height:60px;
    background:#1f3a5f;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
    gap:30px;
}

.logo{
    font-size:18px;
    font-weight:bold;
    white-space:nowrap;
}

.main-nav{
    height:100%;
    display:flex;
    align-items:center;
    gap:2px;
}

.main-nav button{
    height:100%;
    border:0;
    background:transparent;
    color:#dce7f3;
    padding:0 17px;
}

.main-nav button:hover,
.main-nav button.active{
    background:#31557f;
    color:#fff;
}

.app{
    max-width:1440px;
    margin:0 auto;
    padding:24px;
}

.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:20px;
}

.page-header h1{
    margin:0;
    font-size:25px;
}

.subtext{
    color:#718096;
    font-size:13px;
    margin-top:5px;
}

.card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:20px;
    margin-bottom:18px;
}

.card-title{
    font-size:17px;
    font-weight:bold;
    margin-bottom:15px;
}

.btn{
    border:1px solid #cbd5e0;
    background:#fff;
    color:#34495e;
    border-radius:5px;
    padding:8px 15px;
}

.btn:hover{
    background:#f7fafc;
}

.btn-primary{
    background:#2878c8;
    border-color:#2878c8;
    color:#fff;
}

.btn-primary:hover{
    background:#2068ad;
}

.btn-success{
    background:#2f855a;
    border-color:#2f855a;
    color:#fff;
}

.btn-danger{
    border-color:#e05a5a;
    color:#c53f3f;
    background:#fff;
}

.btn-small{
    padding:5px 10px;
    font-size:12px;
}

.btn.loading{
    position:relative;
}

.loading-spinner{
    display:none;
    width:14px;
    height:14px;
    border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite;
    vertical-align:-2px;
    margin-right:6px;
}

.btn:not(.btn-primary):not(.btn-success)
.loading-spinner{
    border-color:#cbd5e0;
    border-top-color:#2878c8;
}

.btn.loading .loading-spinner{
    display:inline-block;
}

@keyframes spin{
    to{
        transform:rotate(360deg);
    }
}

.table-wrap{
    overflow:auto;
}

.table{
    width:100%;
    border-collapse:collapse;
}

.table th,
.table td{
    padding:12px 10px;
    border-bottom:1px solid #e6ebef;
    text-align:left;
    vertical-align:middle;
    font-size:13px;
}

.table th{
    background:#f8fafc;
    color:#52606d;
    font-weight:bold;
}

.table tr:hover td{
    background:#fbfdff;
}

.empty{
    text-align:center !important;
    color:#8a98a5;
    padding:40px !important;
}

.link-button{
    border:0;
    background:none;
    padding:0;
    color:#2878c8;
    cursor:pointer;
    text-align:left;
}

.link-button:hover{
    text-decoration:underline;
}

.badge{
    display:inline-block;
    padding:4px 9px;
    border-radius:12px;
    font-size:11px;
    font-weight:bold;
}

.badge-open{
    background:#e6f6ed;
    color:#237a49;
}

.badge-draft{
    background:#edf2f7;
    color:#66788a;
}

.badge-end{
    background:#fdecec;
    color:#b43b3b;
}

.badge-ok{
    background:#e6f6ed;
    color:#237a49;
}

.badge-warn{
    background:#fff5d9;
    color:#9a6800;
}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
}

.field{
    margin-bottom:15px;
}

.field-full{
    grid-column:1 / -1;
}

.field label{
    display:block;
    font-size:13px;
    font-weight:bold;
    margin-bottom:6px;
    color:#455563;
}

.field input,
.field textarea,
.field select{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:9px 10px;
    background:#fff;
}

.field textarea{
    min-height:90px;
    resize:vertical;
}

.radio-row{
    display:flex;
    gap:22px;
    flex-wrap:wrap;
}

.radio-row label{
    font-weight:normal;
    display:inline-flex;
    align-items:center;
    gap:5px;
}

.notice{
    padding:11px 13px;
    border-radius:5px;
    background:#edf6ff;
    border:1px solid #c9e2fa;
    color:#2b5f8a;
    font-size:13px;
    margin-bottom:15px;
}

.notice.success{
    background:#edf9f1;
    border-color:#c9ead5;
    color:#267348;
}

.notice.warning{
    background:#fff8e6;
    border-color:#f0dfae;
    color:#8a6408;
}

.notice.error{
    background:#fff0f0;
    border-color:#f0caca;
    color:#b43b3b;
}

.editor-toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    margin-top:20px;
}

.editor-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.group-card{
    border:1px solid #d8e0e7;
    border-radius:7px;
    background:#fff;
    margin-bottom:18px;
}

.group-card.dragging{
    opacity:.55;
}

.group-card.drag-over{
    border:2px dashed #2878c8;
}

.group-header{
    display:flex;
    align-items:center;
    gap:10px;
    padding:12px;
    background:#f8fafc;
    border-bottom:1px solid #e6ebef;
}

.drag-handle{
    color:#7b8794;
    font-size:20px;
    cursor:grab;
}

.group-title{
    flex:1;
}

.group-title input{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:8px;
    font-weight:bold;
}

.group-actions{
    display:flex;
    gap:6px;
}

.questions{
    padding:12px;
}

.question-card{
    border:1px solid #e1e7ec;
    border-radius:6px;
    padding:14px;
    margin-bottom:12px;
    background:#fff;
}

.question-card.dragging{
    opacity:.5;
}

.question-card.drag-over{
    border:2px dashed #2878c8;
}

.question-head{
    display:flex;
    align-items:center;
    gap:10px;
}

.question-number{
    min-width:55px;
    font-weight:bold;
    color:#2878c8;
}

.question-title{
    flex:1;
}

.question-title input{
    width:100%;
    padding:8px;
    border:1px solid #cbd5e0;
    border-radius:5px;
}

.question-tools{
    display:flex;
    gap:6px;
}

.question-meta{
    margin-top:10px;
    display:flex;
    gap:18px;
    align-items:center;
    flex-wrap:wrap;
}

.question-meta label{
    display:inline-flex;
    gap:5px;
    align-items:center;
}

.option-list{
    margin-top:12px;
}

.option-row{
    display:flex;
    gap:7px;
    align-items:center;
    margin-bottom:7px;
}

.option-row input{
    flex:1;
    padding:7px;
    border:1px solid #cbd5e0;
    border-radius:5px;
}

.branch-select{
    width:180px;
}

.add-question-area{
    padding:0 12px 14px;
}

.add-group-area{
    margin-top:8px;
}

.detail-tabs,
.settings-tabs{
    display:flex;
    gap:0;
    border-bottom:1px solid #dfe5eb;
    margin-bottom:20px;
}

.detail-tabs button,
.settings-tabs button{
    border:0;
    background:transparent;
    padding:11px 17px;
    color:#617080;
    border-bottom:3px solid transparent;
}

.detail-tabs button.active,
.settings-tabs button.active{
    color:#2878c8;
    border-bottom-color:#2878c8;
    font-weight:bold;
}

.detail-summary{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:12px;
    margin-bottom:18px;
}

.stat-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:16px;
}

.stat-label{
    color:#718096;
    font-size:12px;
}

.stat-value{
    margin-top:6px;
    font-size:24px;
    font-weight:bold;
    color:#263238;
}

.progress{
    height:16px;
    background:#edf2f7;
    border-radius:20px;
    overflow:hidden;
}

.progress span{
    display:block;
    height:100%;
    background:#2878c8;
}

.detail-question{
    margin-bottom:20px;
}

.detail-question-title{
    font-weight:bold;
    margin-bottom:8px;
}

.option-result{
    display:grid;
    grid-template-columns:minmax(160px,1fr) 80px 1fr;
    align-items:center;
    gap:10px;
    margin-bottom:8px;
}

.option-bar{
    height:10px;
    background:#edf2f7;
    border-radius:10px;
    overflow:hidden;
}

.option-bar span{
    display:block;
    height:100%;
    background:#2878c8;
}

.send-layout{
    display:grid;
    grid-template-columns:1.3fr 1fr;
    gap:18px;
}

.customer-toolbar{
    display:flex;
    gap:10px;
    align-items:center;
    margin-bottom:15px;
}

.customer-toolbar input{
    flex:1;
    padding:9px;
    border:1px solid #cbd5e0;
    border-radius:5px;
}

.selection-summary{
    margin:12px 0;
    color:#52606d;
}

.email-preview{
    white-space:pre-wrap;
    background:#f8fafc;
    border:1px solid #e6ebef;
    border-radius:5px;
    padding:15px;
    min-height:200px;
    margin-bottom:15px;
}

.modal-backdrop{
    position:fixed;
    inset:0;
    background:rgba(20,30,40,.45);
    display:flex;
    align-items:center;
    justify-content:center;
    padding:20px;
    z-index:1000;
}

.modal{
    width:min(900px,100%);
    max-height:90vh;
    overflow:auto;
    background:#fff;
    border-radius:8px;
    box-shadow:0 20px 60px rgba(0,0,0,.25);
}

.modal-header{
    padding:15px 18px;
    border-bottom:1px solid #e6ebef;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.modal-body{
    padding:18px;
}

.modal-footer{
    padding:15px 18px;
    border-top:1px solid #e6ebef;
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

.toast{
    position:fixed;
    left:50%;
    bottom:25px;
    transform:translate(-50%,20px);
    background:#263238;
    color:#fff;
    padding:12px 18px;
    border-radius:5px;
    opacity:0;
    pointer-events:none;
    transition:.2s;
    z-index:1200;
}

.toast.show{
    opacity:1;
    transform:translate(-50%,0);
}

.public-page{
    min-height:100vh;
    background:#f4f6f8;
    padding:30px 20px;
}

.public-container{
    max-width:800px;
    margin:auto;
}

.public-question{
    margin-bottom:24px;
}

.public-question-title{
    font-weight:bold;
    margin-bottom:10px;
}

.public-option{
    margin:8px 0;
}

.public-complete{
    text-align:center;
    padding:50px 20px;
}

@media(max-width:900px){
    .topbar{
        height:auto;
        min-height:60px;
        padding:10px 15px;
        flex-wrap:wrap;
        gap:10px;
    }

    .main-nav{
        height:45px;
        width:100%;
        overflow:auto;
    }

    .main-nav button{
        height:45px;
        white-space:nowrap;
        padding:0 12px;
    }

    .form-grid,
    .send-layout{
        grid-template-columns:1fr;
    }

    .detail-summary{
        grid-template-columns:1fr 1fr;
    }
}

@media(max-width:600px){
    .app{
        padding:14px;
    }

    .page-header{
        align-items:flex-start;
        flex-direction:column;
    }

    .question-head{
        align-items:flex-start;
        flex-direction:column;
    }

    .question-number{
        min-width:0;
    }

    .question-tools{
        width:100%;
    }

    .question-tools select{
        flex:1;
    }

    .detail-summary{
        grid-template-columns:1fr;
    }

    .customer-toolbar{
        flex-direction:column;
        align-items:stretch;
    }
}
</style>
</head>

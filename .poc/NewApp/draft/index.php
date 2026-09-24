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

function current_datetime(): string
{
    return date('c');
}

function request_method(): string
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function requested_action(): string
{
    $action = $_GET['action'] ?? '';
    return is_string($action) ? trim($action) : '';
}

function csrf_token(): string
{
    $token = $_SESSION[APP_SESSION_KEY]['csrf_token'] ?? '';
    return is_string($token) ? $token : '';
}

/**
 * APIレスポンス
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

function validate_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($token) || $token === '' || !hash_equals(csrf_token(), $token)) {
        json_response(
            false,
            'セキュリティ確認に失敗しました。画面を再読み込みして再度お試しください。',
            [],
            [],
            403
        );
    }
}

function read_json_request(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        json_response(
            false,
            '入力内容を確認してください。',
            [],
            ['request' => 'JSON形式が正しくありません。'],
            400
        );
    }

    if (!is_array($data)) {
        json_response(
            false,
            '入力内容を確認してください。',
            [],
            ['request' => 'JSON形式のデータを指定してください。'],
            400
        );
    }

    return $data;
}

/* =========================================================
 * JSON保存
 * ========================================================= */

function ensure_data_directory(): void
{
    if (is_dir(DATA_DIRECTORY)) {
        return;
    }

    if (!mkdir(DATA_DIRECTORY, 0700, true) && !is_dir(DATA_DIRECTORY)) {
        json_response(
            false,
            'データ保存領域を準備できませんでした。',
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
            'from_name' => '',
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

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        json_response(
            false,
            '保存されているデータを読み込めませんでした。',
            [],
            [],
            500
        );
    }

    if (!is_array($decoded)) {
        json_response(
            false,
            '保存されているデータを読み込めませんでした。',
            [],
            [],
            500
        );
    }

    return $decoded;
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
            'データを保存できませんでした。',
            [],
            [],
            500
        );
    }

    $temporary = tempnam(DATA_DIRECTORY, 'newapp_');

    if ($temporary === false) {
        json_response(
            false,
            'データを保存できませんでした。',
            [],
            [],
            500
        );
    }

    $handle = fopen($temporary, 'wb');

    if ($handle === false) {
        @unlink($temporary);
        json_response(
            false,
            'データを保存できませんでした。',
            [],
            [],
            500
        );
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new \RuntimeException('lock');
        }

        $length = strlen($json);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($handle, substr($json, $offset));

            if ($written === false || $written === 0) {
                throw new \RuntimeException('write');
            }

            $offset += $written;
        }

        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        if (!rename($temporary, $file)) {
            @unlink($temporary);
            json_response(
                false,
                'データを保存できませんでした。',
                [],
                [],
                500
            );
        }
    } catch (\Throwable $e) {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        @unlink($temporary);

        json_response(
            false,
            'データを保存できませんでした。',
            [],
            [],
            500
        );
    }
}

function load_settings(): array
{
    return read_json_file(SETTINGS_FILE, default_settings());
}

function load_customer_data(): array
{
    return read_json_file(CUSTOMERS_FILE, default_customers());
}

function load_survey_data(): array
{
    return read_json_file(SURVEYS_FILE, default_surveys());
}

function load_response_data(): array
{
    return read_json_file(RESPONSES_FILE, default_responses());
}

function load_mail_log_data(): array
{
    return read_json_file(MAIL_LOGS_FILE, default_mail_logs());
}

function initialize_data_files(): void
{
    ensure_data_directory();

    $files = [
        SETTINGS_FILE => default_settings(),
        CUSTOMERS_FILE => default_customers(),
        SURVEYS_FILE => default_surveys(),
        RESPONSES_FILE => default_responses(),
        MAIL_LOGS_FILE => default_mail_logs()
    ];

    foreach ($files as $file => $default) {
        if (!file_exists($file)) {
            write_json_file($file, $default);
        }
    }
}

/* =========================================================
 * 入力値
 * ========================================================= */

function input_string(array $data, string $key, string $default = ''): string
{
    $value = $data[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

function input_int(array $data, string $key, int $default = 0): int
{
    $value = $data[$key] ?? $default;

    if (is_int($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int)$value;
    }

    return $default;
}

function input_bool(array $data, string $key, bool $default = false): bool
{
    $value = $data[$key] ?? $default;

    if (is_bool($value)) {
        return $value;
    }

    if (is_string($value)) {
        return in_array(
            strtolower($value),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    return (bool)$value;
}

function valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/* =========================================================
 * ID
 * ========================================================= */

function new_id(string $prefix): string
{
    return $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(5));
}

/* =========================================================
 * アンケート
 * ========================================================= */

function normalize_question(array $question): array
{
    $type = input_string($question, 'type', 'free');

    if (!in_array($type, ['free', 'single', 'multiple'], true)) {
        $type = 'free';
    }

    $options = [];

    if (isset($question['options']) && is_array($question['options'])) {
        foreach ($question['options'] as $option) {
            if (is_string($option)) {
                $text = trim($option);

                if ($text !== '') {
                    $options[] = $text;
                }
            } elseif (is_array($option)) {
                $text = input_string($option, 'text');

                if ($text !== '') {
                    $options[] = [
                        'id' => input_string($option, 'id', new_id('opt')),
                        'text' => $text,
                        'next' => input_string($option, 'next', '')
                    ];
                }
            }
        }
    }

    if ($type === 'free') {
        $options = [];
    }

    return [
        'id' => input_string($question, 'id', new_id('q')),
        'text' => input_string($question, 'text'),
        'type' => $type,
        'required' => input_bool($question, 'required'),
        'options' => $options
    ];
}

function normalize_group(array $group): array
{
    $questions = [];

    if (isset($group['questions']) && is_array($group['questions'])) {
        foreach ($group['questions'] as $question) {
            if (is_array($question)) {
                $questions[] = normalize_question($question);
            }
        }
    }

    return [
        'id' => input_string($group, 'id', new_id('grp')),
        'name' => input_string($group, 'name', '新しいグループ'),
        'questions' => $questions
    ];
}

function normalize_survey(array $survey): array
{
    $groups = [];

    if (isset($survey['groups']) && is_array($survey['groups'])) {
        foreach ($survey['groups'] as $group) {
            if (is_array($group)) {
                $groups[] = normalize_group($group);
            }
        }
    }

    $status = input_string($survey, 'status', 'draft');

    if (!in_array($status, ['draft', 'published', 'closed'], true)) {
        $status = 'draft';
    }

    return [
        'id' => input_string($survey, 'id', new_id('survey')),
        'name' => input_string($survey, 'name'),
        'description' => input_string($survey, 'description'),
        'start_at' => input_string($survey, 'start_at'),
        'end_at' => input_string($survey, 'end_at'),
        'status' => $status,
        'numbering' => in_array(
            input_string($survey, 'numbering', 'global'),
            ['global', 'group'],
            true
        )
            ? input_string($survey, 'numbering', 'global')
            : 'global',
        'created_at' => input_string($survey, 'created_at', current_datetime()),
        'updated_at' => current_datetime(),
        'published_at' => input_string($survey, 'published_at'),
        'closed_at' => input_string($survey, 'closed_at'),
        'groups' => $groups
    ];
}

function find_survey(string $surveyId): ?array
{
    $data = load_survey_data();
    $surveys = $data['surveys'] ?? [];

    if (!is_array($surveys)) {
        return null;
    }

    foreach ($surveys as $survey) {
        if (is_array($survey) && ($survey['id'] ?? '') === $surveyId) {
            return $survey;
        }
    }

    return null;
}

function survey_question_list(array $survey): array
{
    $result = [];

    foreach (($survey['groups'] ?? []) as $groupIndex => $group) {
        if (!is_array($group)) {
            continue;
        }

        $questionIndex = 0;

        foreach (($group['questions'] ?? []) as $question) {
            if (!is_array($question)) {
                continue;
            }

            $questionIndex++;

            $number = $survey['numbering'] === 'group'
                ? 'Q' . ($groupIndex + 1) . '-' . $questionIndex
                : 'Q' . (count($result) + 1);

            $question['_number'] = $number;
            $question['_group_id'] = input_string($group, 'id');
            $result[] = $question;
        }
    }

    return $result;
}

function validate_survey(array $survey): array
{
    $errors = [];

    if (trim((string)($survey['name'] ?? '')) === '') {
        $errors['name'] = 'アンケート名を入力してください。';
    }

    $groups = $survey['groups'] ?? [];

    if (!is_array($groups) || count($groups) === 0) {
        $errors['groups'] = '少なくとも1つのグループを追加してください。';
        return $errors;
    }

    $questionIds = [];
    $optionIds = [];

    foreach ($groups as $groupIndex => $group) {
        if (!is_array($group)) {
            $errors['group_' . $groupIndex] = 'グループの内容を確認してください。';
            continue;
        }

        if (trim((string)($group['name'] ?? '')) === '') {
            $errors['group_name_' . $groupIndex] = 'グループ名を入力してください。';
        }

        $questions = $group['questions'] ?? [];

        if (!is_array($questions)) {
            continue;
        }

        foreach ($questions as $questionIndex => $question) {
            if (!is_array($question)) {
                continue;
            }

            $qid = input_string($question, 'id');

            if ($qid === '' || isset($questionIds[$qid])) {
                $errors['question_id_' . $groupIndex . '_' . $questionIndex] =
                    '質問IDが重複しています。';
            }

            $questionIds[$qid] = true;

            if (input_string($question, 'text') === '') {
                $errors['question_' . $groupIndex . '_' . $questionIndex] =
                    '質問文を入力してください。';
            }

            $type = input_string($question, 'type', 'free');

            if (!in_array($type, ['free', 'single', 'multiple'], true)) {
                $errors['question_type_' . $groupIndex . '_' . $questionIndex] =
                    '回答形式を確認してください。';
            }

            if ($type === 'single' || $type === 'multiple') {
                $options = $question['options'] ?? [];

                if (!is_array($options) || count($options) === 0) {
                    $errors['options_' . $groupIndex . '_' . $questionIndex] =
                        '選択肢を1つ以上設定してください。';
                } else {
                    foreach ($options as $option) {
                        if (!is_array($option)) {
                            continue;
                        }

                        $oid = input_string($option, 'id');

                        if ($oid === '') {
                            $oid = new_id('opt');
                        }

                        if (isset($optionIds[$oid])) {
                            $errors['option_id_' . $oid] =
                                '選択肢IDが重複しています。';
                        }

                        $optionIds[$oid] = true;

                        if (input_string($option, 'text') === '') {
                            $errors['option_text_' . $oid] =
                                '選択肢の内容を入力してください。';
                        }
                    }
                }
            }
        }
    }

    return $errors;
}

/* =========================================================
 * 回答
 * ========================================================= */

function survey_is_answerable(array $survey): bool
{
    if (($survey['status'] ?? '') !== 'published') {
        return false;
    }

    $now = time();

    $start = trim((string)($survey['start_at'] ?? ''));
    $end = trim((string)($survey['end_at'] ?? ''));

    if ($start !== '') {
        $timestamp = strtotime($start);

        if ($timestamp !== false && $now < $timestamp) {
            return false;
        }
    }

    if ($end !== '') {
        $timestamp = strtotime($end);

        if ($timestamp !== false && $now > $timestamp) {
            return false;
        }
    }

    return true;
}

function response_already_exists(string $surveyId, string $respondentToken): bool
{
    $data = load_response_data();

    foreach (($data['responses'] ?? []) as $response) {
        if (
            is_array($response) &&
            ($response['survey_id'] ?? '') === $surveyId &&
            ($response['respondent_token'] ?? '') === $respondentToken
        ) {
            return true;
        }
    }

    return false;
}

function aggregate_survey_responses(array $survey): array
{
    $responseData = load_response_data();
    $responses = [];

    foreach (($responseData['responses'] ?? []) as $response) {
        if (
            is_array($response) &&
            ($response['survey_id'] ?? '') === ($survey['id'] ?? '')
        ) {
            $responses[] = $response;
        }
    }

    $questions = survey_question_list($survey);
    $result = [];

    foreach ($questions as $question) {
        $qid = input_string($question, 'id');
        $type = input_string($question, 'type');

        $item = [
            'question_id' => $qid,
            'number' => $question['_number'] ?? '',
            'text' => $question['text'] ?? '',
            'type' => $type,
            'answered' => 0,
            'total' => count($responses),
            'options' => [],
            'free_answers' => []
        ];

        $optionCounts = [];

        foreach (($question['options'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }

            $oid = input_string($option, 'id');
            $optionCounts[$oid] = 0;

            $item['options'][] = [
                'id' => $oid,
                'text' => input_string($option, 'text'),
                'count' => 0,
                'ratio' => 0
            ];
        }

        foreach ($responses as $response) {
            $answers = $response['answers'] ?? [];

            if (!is_array($answers) || !array_key_exists($qid, $answers)) {
                continue;
            }

            $answer = $answers[$qid];

            if ($type === 'free') {
                if (is_string($answer) && trim($answer) !== '') {
                    $item['answered']++;
                    $item['free_answers'][] = [
                        'responded_at' => input_string(
                            $response,
                            'responded_at'
                        ),
                        'answer' => $answer
                    ];
                }
                continue;
            }

            if ($type === 'single') {
                if (is_string($answer) && $answer !== '') {
                    $item['answered']++;

                    if (isset($optionCounts[$answer])) {
                        $optionCounts[$answer]++;
                    }
                }
                continue;
            }

            if ($type === 'multiple') {
                if (!is_array($answer)) {
                    continue;
                }

                if (count($answer) > 0) {
                    $item['answered']++;
                }

                foreach ($answer as $selected) {
                    if (is_string($selected) && isset($optionCounts[$selected])) {
                        $optionCounts[$selected]++;
                    }
                }
            }
        }

        foreach ($item['options'] as $index => $option) {
            $count = $optionCounts[$option['id']] ?? 0;
            $base = $type === 'multiple'
                ? max(1, count($responses))
                : max(1, count($responses));

            $item['options'][$index]['count'] = $count;
            $item['options'][$index]['ratio'] =
                round(($count / $base) * 100, 1);
        }

        $result[] = $item;
    }

    return [
        'total_responses' => count($responses),
        'respondents' => count($responses),
        'questions' => $result
    ];
}

/* =========================================================
 * kintone
 * ========================================================= */

function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain);
    $domain = rtrim((string)$domain, '/');

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

function normalize_proxy(string $host, string $port): string
{
    $host = trim($host);
    $port = trim($port);

    if ($host === '') {
        return '';
    }

    if ($port !== '') {
        if (!ctype_digit($port)) {
            return '';
        }

        $portNumber = (int)$port;

        if ($portNumber < 1 || $portNumber > 65535) {
            return '';
        }

        return $host . ':' . $portNumber;
    }

    return $host;
}

function get_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();

        return is_array($headers) ? $headers : [];
    }

    return [];
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

function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload = null,
    array $config = []
): array {
    $method = strtoupper(trim($method));

    $httpOptions = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20,
        'protocol_version' => 1.1
    ];

    if ($method === 'GET' && is_array($payload) && $payload !== []) {
        $query = http_build_query(
            $payload,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        if ($query !== '') {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . $query;
        }
    }

    if (
        in_array($method, ['POST', 'PUT', 'DELETE'], true) &&
        $payload !== null
    ) {
        if (is_array($payload)) {
            try {
                $httpOptions['content'] = json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES |
                    JSON_THROW_ON_ERROR
                );
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'status' => 0,
                    'message' => '送信データを作成できませんでした。',
                    'raw' => []
                ];
            }
        } else {
            $httpOptions['content'] = (string)$payload;
        }
    }

    $contextOptions = [
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    /*
     * プロキシは設定値を常に受け取れる構造にする。
     * 設定が空の場合は直接接続する。
     */
    $proxyHost = trim((string)($config['proxy_host'] ?? ''));
    $proxyPort = trim((string)($config['proxy_port'] ?? ''));

    $proxyHostPort = normalize_proxy($proxyHost, $proxyPort);

    if ($proxyHostPort !== '') {
        $contextOptions['http']['proxy'] =
            'tcp://' . $proxyHostPort;

        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $responseBody = @file_get_contents(
        $url,
        false,
        $context
    );

    $responseHeaders = get_safe_response_headers();

    $statusCode = 0;

    foreach ($responseHeaders as $headerLine) {
        if (
            preg_match(
                '/^HTTP\/\d(?:\.\d)?\s+(\d+)/i',
                (string)$headerLine,
                $matches
            )
        ) {
            $statusCode = (int)$matches[1];
        }
    }

    $resultData = [];

    if (is_string($responseBody) && $responseBody !== '') {
        $decoded = json_decode($responseBody, true);

        if (is_array($decoded)) {
            $resultData = $decoded;
        }
    }

    if ($statusCode >= 200 && $statusCode < 300) {
        return [
            'success' => true,
            'status' => $statusCode,
            'data' => $resultData
        ];
    }

    $message = 'kintone APIとの通信に失敗しました。';

    if (isset($resultData['message']) && is_string($resultData['message'])) {
        $message = $resultData['message'];
    }

    $details = [];

    if (isset($resultData['code']) && is_string($resultData['code'])) {
        $details[] = 'コード: ' . $resultData['code'];
    }

    if (isset($resultData['errors']) && is_array($resultData['errors'])) {
        foreach ($resultData['errors'] as $field => $error) {
            if (!is_array($error)) {
                continue;
            }

            $messages = $error['messages'] ?? [];

            if (!is_array($messages)) {
                continue;
            }

            foreach ($messages as $errorMessage) {
                if (is_string($errorMessage)) {
                    $details[] =
                        (string)$field . ': ' . $errorMessage;
                }
            }
        }
    }

    if ($details !== []) {
        $message .= ' (' . implode(' / ', $details) . ')';
    }

    return [
        'success' => false,
        'status' => $statusCode,
        'message' => $message,
        'raw' => $resultData
    ];
}

function kintone_configured(array $settings): bool
{
    $kintone = $settings['kintone'] ?? [];

    if (!is_array($kintone)) {
        return false;
    }

    return
        input_string($kintone, 'domain') !== '' &&
        input_string($kintone, 'login_name') !== '' &&
        input_string($kintone, 'password') !== '' &&
        input_string($kintone, 'customer_app_id') !== '' &&
        input_string($kintone, 'customer_name_field') !== '' &&
        input_string($kintone, 'customer_email_field') !== '';
}

function kintone_test_connection(array $settings): array
{
    $config = $settings['kintone'] ?? [];

    if (!is_array($config)) {
        return [
            'success' => false,
            'message' => 'キントーン設定を確認してください。'
        ];
    }

    $domain = input_string($config, 'domain');
    $loginName = input_string($config, 'login_name');
    $password = input_string($config, 'password');
    $appId = input_string($config, 'customer_app_id');

    if ($domain === '' || $loginName === '' || $password === '') {
        return [
            'success' => false,
            'message' => 'キントーンの接続先、ログイン名、パスワードを設定してください。'
        ];
    }

    if ($appId === '') {
        $endpoint = '/k/v1/apps.json';
        $payload = ['limit' => 1];
    } else {
        $endpoint = '/k/v1/app.json';
        $payload = ['id' => $appId];
    }

    $url = kintone_build_url($domain, $endpoint);

    $headers = [
        make_cybozu_auth_header($loginName, $password),
        'Accept: application/json'
    ];

    return kintone_api_request(
        'GET',
        $url,
        $headers,
        $payload,
        [
            'proxy_host' => input_string($config, 'proxy_host'),
            'proxy_port' => input_string($config, 'proxy_port')
        ]
    );
}

function refresh_customers_from_kintone(array $settings): array
{
    $config = $settings['kintone'] ?? [];

    if (!is_array($config)) {
        return [
            'success' => false,
            'message' => 'キントーン設定を確認してください。'
        ];
    }

    $domain = input_string($config, 'domain');
    $loginName = input_string($config, 'login_name');
    $password = input_string($config, 'password');
    $appId = input_string($config, 'customer_app_id');
    $idField = input_string($config, 'customer_id_field');
    $nameField = input_string($config, 'customer_name_field');
    $emailField = input_string($config, 'customer_email_field');

    if (
        $domain === '' ||
        $loginName === '' ||
        $password === '' ||
        $appId === '' ||
        $nameField === '' ||
        $emailField === ''
    ) {
        return [
            'success' => false,
            'message' => 'キントーン設定をすべて入力してください。'
        ];
    }

    $query = 'order by $id asc limit 500';

    $url = kintone_build_url(
        $domain,
        '/k/v1/records.json'
    );

    $headers = [
        make_cybozu_auth_header($loginName, $password),
        'Accept: application/json'
    ];

    $payload = [
        'app' => $appId,
        'query' => $query,
        'fields' => array_values(
            array_filter(
                [$idField, $nameField, $emailField],
                static fn ($value): bool =>
                    is_string($value) && trim($value) !== ''
            )
        )
    ];

    $result = kintone_api_request(
        'GET',
        $url,
        $headers,
        $payload,
        [
            'proxy_host' => input_string($config, 'proxy_host'),
            'proxy_port' => input_string($config, 'proxy_port')
        ]
    );

    if (!$result['success']) {
        return $result;
    }

    $customers = [];

    $records = $result['data']['records'] ?? [];

    if (!is_array($records)) {
        $records = [];
    }

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

        if (isset($record[$nameField]['value'])) {
            $name = (string)$record[$nameField]['value'];
        }

        if (isset($record[$emailField]['value'])) {
            $email = (string)$record[$emailField]['value'];
        }

        if ($email === '' || !valid_email($email)) {
            continue;
        }

        $customers[] = [
            'id' => $id !== '' ? $id : new_id('customer'),
            'name' => $name,
            'email' => $email,
            'company' => '',
            'customer_no' => $id
        ];
    }

    $customerData = [
        'version' => 1,
        'updated_at' => current_datetime(),
        'source' => 'kintone',
        'count' => count($customers),
        'customers' => $customers
    ];

    write_json_file(CUSTOMERS_FILE, $customerData);

    return [
        'success' => true,
        'status' => 200,
        'data' => [
            'count' => count($customers),
            'customers' => $customers
        ]
    ];
}

/* =========================================================
 * メール
 * ========================================================= */

function mail_settings_ready(array $settings): bool
{
    $mail = $settings['mail'] ?? [];

    if (!is_array($mail)) {
        return false;
    }

    $server = input_string($mail, 'smtp_server');
    $from = input_string($mail, 'from_email');

    $port = input_int($mail, 'smtp_port', 0);

    return
        $server !== '' &&
        $port >= 1 &&
        $port <= 65535 &&
        $from !== '' &&
        valid_email($from);
}

/**
 * SMTP送信。
 *
 * PHP標準機能だけで実装し、外部ライブラリには依存しない。
 */
function smtp_send_mail(
    array $settings,
    string $to,
    string $subject,
    string $body
): array {
    if (!valid_email($to)) {
        return [
            'success' => false,
            'message' => '送信先メールアドレスが正しくありません。'
        ];
    }

    if (!mail_settings_ready($settings)) {
        return [
            'success' => false,
            'message' => 'メール送信設定が未完了です。'
        ];
    }

    /*
     * mail() は環境依存のため、SMTP設定そのものがPHP側に
     * 反映されていないサーバーでは利用できない。
     *
     * この環境では設定確認を行ったうえで、PHP標準のmail()
     * を利用する。
     */
    $mail = $settings['mail'];

    $fromEmail = input_string($mail, 'from_email');
    $fromName = input_string($mail, 'from_name');

    $encodedSubject = '=?UTF-8?B?' .
        base64_encode($subject) .
        '?=';

    $encodedFromName = $fromName !== ''
        ? '=?UTF-8?B?' . base64_encode($fromName) . '?='
        : '';

    $fromHeader = $encodedFromName !== ''
        ? $encodedFromName . ' <' . $fromEmail . '>'
        : $fromEmail;

    $headers = [
        'From: ' . $fromHeader,
        'Reply-To: ' . $fromEmail,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit'
    ];

    $result = @mail(
        $to,
        $encodedSubject,
        $body,
        implode("\r\n", $headers)
    );

    if (!$result) {
        return [
            'success' => false,
            'message' => 'メール送信に失敗しました。メールサーバー設定を確認してください。'
        ];
    }

    return [
        'success' => true,
        'message' => 'メールを送信しました。'
    ];
}

/* =========================================================
 * API
 * ========================================================= */

initialize_data_files();

$action = requested_action();

if ($action !== '') {
    $method = request_method();

    if ($method === 'POST') {
        validate_csrf();
    }

    /*
     * 回答者画面は action=respond のGETで表示する。
     */
    if ($action === 'load_settings') {
        $settings = load_settings();

        $safeSettings = [
            'mail' => [
                'smtp_server' =>
                    input_string($settings['mail'] ?? [], 'smtp_server'),
                'smtp_port' =>
                    input_int($settings['mail'] ?? [], 'smtp_port', 587),
                'connection_type' =>
                    input_string(
                        $settings['mail'] ?? [],
                        'connection_type',
                        'tls'
                    ),
                'username' =>
                    input_string($settings['mail'] ?? [], 'username'),
                'from_email' =>
                    input_string($settings['mail'] ?? [], 'from_email'),
                'from_name' =>
                    input_string($settings['mail'] ?? [], 'from_name'),
                'configured' =>
                    mail_settings_ready($settings),
                'tested_at' =>
                    input_string(
                        $settings['mail'] ?? [],
                        'tested_at'
                    )
            ],
            'kintone' => [
                'domain' =>
                    input_string($settings['kintone'] ?? [], 'domain'),
                'login_name' =>
                    input_string($settings['kintone'] ?? [], 'login_name'),
                'customer_app_id' =>
                    input_string(
                        $settings['kintone'] ?? [],
                        'customer_app_id'
                    ),
                'customer_id_field' =>
                    input_string(
                        $settings['kintone'] ?? [],
                        'customer_id_field'
                    ),
                'customer_name_field' =>
                    input_string(
                        $settings['kintone'] ?? [],
                        'customer_name_field'
                    ),
                'customer_email_field' =>
                    input_string(
                        $settings['kintone'] ?? [],
                        'customer_email_field'
                    ),
                'proxy_host' =>
                    input_string(
                        $settings['kintone'] ?? [],
                        'proxy_host'
                    ),
                'proxy_port' =>
                    input_string(
                        $settings['kintone'] ?? [],
                        'proxy_port'
                    ),
                'configured' =>
                    kintone_configured($settings),
                'connection_status' =>
                    input_string(
                        $settings['kintone'] ?? [],
                        'connection_status',
                        'not_configured'
                    ),
                'tested_at' =>
                    input_string(
                        $settings['kintone'] ?? [],
                        'tested_at'
                    )
            ]
        ];

        json_response(
            true,
            '設定を取得しました。',
            ['settings' => $safeSettings]
        );
    }

    if ($action === 'load_customers') {
        $customerData = load_customer_data();

        json_response(
            true,
            '顧客一覧を取得しました。',
            [
                'customers' => $customerData['customers'] ?? [],
                'count' => $customerData['count'] ?? 0,
                'updated_at' => $customerData['updated_at'] ?? null
            ]
        );
    }

    if ($action === 'load_surveys') {
        $surveyData = load_survey_data();
        $responseData = load_response_data();
        $mailData = load_mail_log_data();

        $responses = is_array($responseData['responses'] ?? null)
            ? $responseData['responses']
            : [];

        $mailLogs = is_array($mailData['logs'] ?? null)
            ? $mailData['logs']
            : [];

        $surveys = [];

        foreach (($surveyData['surveys'] ?? []) as $survey) {
            if (!is_array($survey)) {
                continue;
            }

            $id = input_string($survey, 'id');
            $responseCount = 0;
            $mailCount = 0;

            foreach ($responses as $response) {
                if (
                    is_array($response) &&
                    ($response['survey_id'] ?? '') === $id
                ) {
                    $responseCount++;
                }
            }

            foreach ($mailLogs as $log) {
                if (
                    is_array($log) &&
                    ($log['survey_id'] ?? '') === $id &&
                    ($log['status'] ?? '') === 'sent'
                ) {
                    $mailCount++;
                }
            }

            $survey['response_count'] = $responseCount;
            $survey['mail_sent_count'] = $mailCount;
            $surveys[] = $survey;
        }

        json_response(
            true,
            'アンケート一覧を取得しました。',
            ['surveys' => $surveys]
        );
    }

    if ($action === 'load_survey') {
        $surveyId = input_string($_GET, 'survey_id');

        if ($surveyId === '') {
            json_response(
                false,
                'アンケートを指定してください。',
                [],
                [],
                400
            );
        }

        $survey = find_survey($surveyId);

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
            'アンケートを取得しました。',
            ['survey' => $survey]
        );
    }

    if ($action === 'load_mail_logs') {
        $surveyId = input_string($_GET, 'survey_id');
        $data = load_mail_log_data();
        $logs = [];

        foreach (($data['logs'] ?? []) as $log) {
            if (!is_array($log)) {
                continue;
            }

            if ($surveyId !== '' && ($log['survey_id'] ?? '') !== $surveyId) {
                continue;
            }

            $logs[] = $log;
        }

        json_response(
            true,
            'メール履歴を取得しました。',
            ['logs' => $logs]
        );
    }

    if ($action === 'aggregate_responses') {
        $surveyId = input_string($_GET, 'survey_id');

        if ($surveyId === '') {
            json_response(
                false,
                'アンケートを指定してください。',
                [],
                [],
                400
            );
        }

        $survey = find_survey($surveyId);

        if ($survey === null) {
            json_response(
                false,
                'アンケートが見つかりません。',
                [],
                [],
                404
            );
        }

        $aggregate = aggregate_survey_responses($survey);

        $mailData = load_mail_log_data();
        $sentRecipients = [];

        foreach (($mailData['logs'] ?? []) as $log) {
            if (
                is_array($log) &&
                ($log['survey_id'] ?? '') === $surveyId &&
                ($log['status'] ?? '') === 'sent'
            ) {
                $email = input_string($log, 'email');

                if ($email !== '') {
                    $sentRecipients[$email] = true;
                }
            }
        }

        $sentCount = count($sentRecipients);
        $responseCount = (int)$aggregate['total_responses'];
        $unanswered = max(0, $sentCount - $responseCount);

        $rate = $sentCount > 0
            ? round(($responseCount / $sentCount) * 100, 1)
            : 0;

        $aggregate['status'] = [
            'response_count' => $responseCount,
            'response_rate' => $rate,
            'unanswered_count' => $unanswered,
            'mail_target_count' => $sentCount,
            'mail_sent_count' => $sentCount
        ];

        json_response(
            true,
            '回答結果を集計しました。',
            ['aggregate' => $aggregate]
        );
    }

    if ($action === 'load_public_survey') {
        $surveyId = input_string($_GET, 'survey_id');

        if ($surveyId === '') {
            json_response(
                false,
                'アンケートを指定してください。',
                [],
                [],
                400
            );
        }

        $survey = find_survey($surveyId);

        if ($survey === null) {
            json_response(
                false,
                'アンケートが見つかりません。',
                [],
                [],
                404
            );
        }

        if (!survey_is_answerable($survey)) {
            json_response(
                false,
                'このアンケートは現在回答を受け付けていません。',
                [],
                [],
                403
            );
        }

        $questions = survey_question_list($survey);

        json_response(
            true,
            'アンケートを取得しました。',
            [
                'survey' => [
                    'id' => $survey['id'],
                    'name' => $survey['name'],
                    'description' => $survey['description'],
                    'start_at' => $survey['start_at'],
                    'end_at' => $survey['end_at'],
                    'numbering' => $survey['numbering'],
                    'questions' => $questions
                ]
            ]
        );
    }

    if ($action === 'save_mail_settings') {
        $data = read_json_request();
        $settings = load_settings();

        $smtpServer = input_string($data, 'smtp_server');
        $smtpPort = input_int($data, 'smtp_port', 587);
        $connectionType = input_string($data, 'connection_type', 'tls');
        $username = input_string($data, 'username');
        $password = input_string($data, 'password');
        $fromEmail = input_string($data, 'from_email');
        $fromName = input_string($data, 'from_name');

        $errors = [];

        if ($smtpServer === '') {
            $errors['smtp_server'] = 'SMTPサーバを入力してください。';
        }

        if ($smtpPort < 1 || $smtpPort > 65535) {
            $errors['smtp_port'] = 'ポート番号を確認してください。';
        }

        if (!in_array($connectionType, ['none', 'tls', 'ssl'], true)) {
            $errors['connection_type'] = '接続方式を確認してください。';
        }

        if (!valid_email($fromEmail)) {
            $errors['from_email'] =
                '送信元メールアドレスを確認してください。';
        }

        if ($errors !== []) {
            json_response(
                false,
                '入力内容を確認してください。',
                [],
                $errors,
                422
            );
        }

        $oldPassword =
            input_string($settings['mail'] ?? [], 'password');

        if ($password === '') {
            $password = $oldPassword;
        }

        $settings['mail'] = [
            'smtp_server' => $smtpServer,
            'smtp_port' => $smtpPort,
            'connection_type' => $connectionType,
            'username' => $username,
            'password' => $password,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'configured' => true,
            'tested_at' =>
                input_string(
                    $settings['mail'] ?? [],
                    'tested_at'
                )
        ];

        $settings['updated_at'] = current_datetime();

        write_json_file(SETTINGS_FILE, $settings);

        json_response(
            true,
            'メール送信設定を保存しました。'
        );
    }

    if ($action === 'test_mail_settings') {
        $settings = load_settings();

        if (!mail_settings_ready($settings)) {
            json_response(
                false,
                'メール送信設定が未完了です。',
                [],
                [],
                422
            );
        }

        $settings['mail']['tested_at'] = current_datetime();
        $settings['updated_at'] = current_datetime();

        write_json_file(SETTINGS_FILE, $settings);

        json_response(
            true,
            'メール送信設定を確認しました。',
            [
                'tested_at' => $settings['mail']['tested_at']
            ]
        );
    }

    if ($action === 'save_kintone_settings') {
        $data = read_json_request();
        $settings = load_settings();

        $domain = input_string($data, 'domain');
        $loginName = input_string($data, 'login_name');
        $password = input_string($data, 'password');
        $appId = input_string($data, 'customer_app_id');
        $idField = input_string($data, 'customer_id_field');
        $nameField = input_string($data, 'customer_name_field');
        $emailField = input_string($data, 'customer_email_field');
        $proxyHost = input_string($data, 'proxy_host');
        $proxyPort = input_string($data, 'proxy_port');

        $errors = [];

        if ($domain === '') {
            $errors['domain'] = 'キントーンの利用先を入力してください。';
        }

        if ($loginName === '') {
            $errors['login_name'] = 'ログイン名を入力してください。';
        }

        if ($appId === '') {
            $errors['customer_app_id'] =
                '顧客管理アプリIDを入力してください。';
        }

        if ($nameField === '') {
            $errors['customer_name_field'] =
                '顧客名フィールドを入力してください。';
        }

        if ($emailField === '') {
            $errors['customer_email_field'] =
                'メールアドレスフィールドを入力してください。';
        }

        if ($proxyPort !== '') {
            if (!ctype_digit($proxyPort)) {
                $errors['proxy_port'] =
                    'プロキシポート番号を確認してください。';
            } else {
                $proxyPortNumber = (int)$proxyPort;

                if ($proxyPortNumber < 1 || $proxyPortNumber > 65535) {
                    $errors['proxy_port'] =
                        'プロキシポート番号を確認してください。';
                }
            }
        }

        if ($errors !== []) {
            json_response(
                false,
                '入力内容を確認してください。',
                [],
                $errors,
                422
            );
        }

        $oldPassword =
            input_string($settings['kintone'] ?? [], 'password');

        if ($password === '') {
            $password = $oldPassword;
        }

        if ($password === '') {
            $errors['password'] =
                'パスワードを入力してください。';

            json_response(
                false,
                '入力内容を確認してください。',
                [],
                $errors,
                422
            );
        }

        $settings['kintone'] = [
            'domain' => $domain,
            'login_name' => $loginName,
            'password' => $password,
            'customer_app_id' => $appId,
            'customer_id_field' => $idField,
            'customer_name_field' => $nameField,
            'customer_email_field' => $emailField,
            'proxy_host' => $proxyHost,
            'proxy_port' => $proxyPort,
            'configured' => true,
            'connection_status' => 'not_tested',
            'tested_at' => null
        ];

        $settings['updated_at'] = current_datetime();

        write_json_file(SETTINGS_FILE, $settings);

        json_response(
            true,
            'キントーン設定を保存しました。'
        );
    }

    if ($action === 'test_kintone_connection') {
        $settings = load_settings();

        $result = kintone_test_connection($settings);

        if (!$result['success']) {
            $settings['kintone']['connection_status'] = 'error';
            $settings['kintone']['tested_at'] = current_datetime();
            $settings['updated_at'] = current_datetime();

            write_json_file(SETTINGS_FILE, $settings);

            json_response(
                false,
                (string)($result['message'] ?? '接続確認に失敗しました。'),
                [],
                [],
                502
            );
        }

        $settings['kintone']['connection_status'] = 'connected';
        $settings['kintone']['tested_at'] = current_datetime();
        $settings['kintone']['configured'] = true;
        $settings['updated_at'] = current_datetime();

        write_json_file(SETTINGS_FILE, $settings);

        json_response(
            true,
            'キントーンへの接続を確認しました。',
            [
                'tested_at' =>
                    $settings['kintone']['tested_at']
            ]
        );
    }

    if ($action === 'refresh_customers') {
        $settings = load_settings();

        $result = refresh_customers_from_kintone($settings);

        if (!$result['success']) {
            json_response(
                false,
                (string)($result['message'] ?? '顧客一覧を取得できませんでした。'),
                [],
                [],
                502
            );
        }

        json_response(
            true,
            '顧客一覧を更新しました。',
            [
                'customers' =>
                    $result['data']['customers'] ?? [],
                'count' =>
                    $result['data']['count'] ?? 0
            ]
        );
    }

    if ($action === 'save_survey') {
        $data = read_json_request();

        $surveyInput = $data['survey'] ?? $data;

        if (!is_array($surveyInput)) {
            json_response(
                false,
                'アンケートの入力内容を確認してください。',
                [],
                [],
                422
            );
        }

        $survey = normalize_survey($surveyInput);

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

        $surveyData = load_survey_data();
        $surveys = [];

        foreach (($surveyData['surveys'] ?? []) as $existing) {
            if (!is_array($existing)) {
                continue;
            }

            if (($existing['id'] ?? '') === $survey['id']) {
                /*
                 * 公開中・終了済みの状態そのものは、
                 * 保存操作で勝手に変更しない。
                 */
                $oldStatus = input_string(
                    $existing,
                    'status',
                    'draft'
                );

                $survey['status'] = $oldStatus;
                $survey['published_at'] =
                    input_string($existing, 'published_at');
                $survey['closed_at'] =
                    input_string($existing, 'closed_at');
            }

            $surveys[] = $existing;
        }

        $found = false;

        foreach ($surveys as $index => $existing) {
            if (
                is_array($existing) &&
                ($existing['id'] ?? '') === $survey['id']
            ) {
                $surveys[$index] = $survey;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $survey['created_at'] = current_datetime();
            $survey['updated_at'] = current_datetime();
            $survey['status'] = 'draft';
            $survey['published_at'] = '';
            $survey['closed_at'] = '';
            $surveys[] = $survey;
        }

        $surveyData['version'] = 1;
        $surveyData['updated_at'] = current_datetime();
        $surveyData['surveys'] = array_values($surveys);

        write_json_file(SURVEYS_FILE, $surveyData);

        json_response(
            true,
            'アンケートを保存しました。',
            ['survey' => $survey]
        );
    }

    if ($action === 'delete_survey') {
        $data = read_json_request();
        $surveyId = input_string($data, 'survey_id');

        if ($surveyId === '') {
            json_response(
                false,
                'アンケートを指定してください。',
                [],
                [],
                400
            );
        }

        $surveyData = load_survey_data();
        $surveys = $surveyData['surveys'] ?? [];
        $newSurveys = [];
        $deleted = false;

        foreach ($surveys as $survey) {
            if (!is_array($survey)) {
                continue;
            }

            if (($survey['id'] ?? '') === $surveyId) {
                if (($survey['status'] ?? '') !== 'draft') {
                    json_response(
                        false,
                        '下書きのアンケートだけ削除できます。',
                        [],
                        [],
                        422
                    );
                }

                $deleted = true;
                continue;
            }

            $newSurveys[] = $survey;
        }

        if (!$deleted) {
            json_response(
                false,
                'アンケートが見つかりません。',
                [],
                [],
                404
            );
        }

        $surveyData['surveys'] = array_values($newSurveys);
        $surveyData['updated_at'] = current_datetime();

        write_json_file(SURVEYS_FILE, $surveyData);

        json_response(
            true,
            'アンケートを削除しました。'
        );
    }

    if ($action === 'publish_survey') {
        $data = read_json_request();
        $surveyId = input_string($data, 'survey_id');

        $surveyData = load_survey_data();
        $surveys = $surveyData['surveys'] ?? [];
        $updated = false;
        $publishedSurvey = null;

        foreach ($surveys as $index => $survey) {
            if (
                !is_array($survey) ||
                ($survey['id'] ?? '') !== $surveyId
            ) {
                continue;
            }

            $errors = validate_survey($survey);

            if ($errors !== []) {
                json_response(
                    false,
                    '公開前に入力内容を確認してください。',
                    [],
                    $errors,
                    422
                );
            }

            $survey['status'] = 'published';
            $survey['published_at'] = current_datetime();
            $survey['updated_at'] = current_datetime();

            $surveys[$index] = $survey;
            $publishedSurvey = $survey;
            $updated = true;
            break;
        }

        if (!$updated || !is_array($publishedSurvey)) {
            json_response(
                false,
                'アンケートが見つかりません。',
                [],
                [],
                404
            );
        }

        $surveyData['surveys'] = array_values($surveys);
        $surveyData['updated_at'] = current_datetime();

        write_json_file(SURVEYS_FILE, $surveyData);

        json_response(
            true,
            'アンケートを公開しました。',
            ['survey' => $publishedSurvey]
        );
    }

    if ($action === 'close_survey') {
        $data = read_json_request();
        $surveyId = input_string($data, 'survey_id');

        $surveyData = load_survey_data();
        $surveys = $surveyData['surveys'] ?? [];
        $updated = false;
        $closedSurvey = null;

        foreach ($surveys as $index => $survey) {
            if (
                !is_array($survey) ||
                ($survey['id'] ?? '') !== $surveyId
            ) {
                continue;
            }

            if (($survey['status'] ?? '') !== 'published') {
                json_response(
                    false,
                    '公開中のアンケートだけ終了できます。',
                    [],
                    [],
                    422
                );
            }

            $survey['status'] = 'closed';
            $survey['closed_at'] = current_datetime();
            $survey['updated_at'] = current_datetime();

            $surveys[$index] = $survey;
            $closedSurvey = $survey;
            $updated = true;
            break;
        }

        if (!$updated || !is_array($closedSurvey)) {
            json_response(
                false,
                'アンケートが見つかりません。',
                [],
                [],
                404
            );
        }

        $surveyData['surveys'] = array_values($surveys);
        $surveyData['updated_at'] = current_datetime();

        write_json_file(SURVEYS_FILE, $surveyData);

        json_response(
            true,
            'アンケートを終了しました。',
            ['survey' => $closedSurvey]
        );
    }

    if ($action === 'save_response') {
        $data = read_json_request();

        $surveyId = input_string($data, 'survey_id');
        $respondentToken = input_string($data, 'respondent_token');
        $answers = $data['answers'] ?? [];

        if ($surveyId === '') {
            json_response(
                false,
                'アンケートを指定してください。',
                [],
                [],
                400
            );
        }

        if ($respondentToken === '') {
            json_response(
                false,
                '回答情報を確認してください。',
                [],
                [],
                400
            );
        }

        if (!is_array($answers)) {
            json_response(
                false,
                '回答内容を確認してください。',
                [],
                [],
                422
            );
        }

        $survey = find_survey($surveyId);

        if ($survey === null) {
            json_response(
                false,
                'アンケートが見つかりません。',
                [],
                [],
                404
            );
        }

        if (!survey_is_answerable($survey)) {
            json_response(
                false,
                'このアンケートは現在回答を受け付けていません。',
                [],
                [],
                403
            );
        }

        if (response_already_exists($surveyId, $respondentToken)) {
            json_response(
                false,
                'この回答はすでに送信されています。',
                [],
                [],
                409
            );
        }

        $questions = survey_question_list($survey);
        $errors = [];

        foreach ($questions as $question) {
            $qid = input_string($question, 'id');
            $type = input_string($question, 'type');
            $required = (bool)($question['required'] ?? false);

            $answer = $answers[$qid] ?? null;

            $isEmpty = false;

            if ($type === 'free') {
                $isEmpty =
                    !is_string($answer) ||
                    trim($answer) === '';
            } elseif ($type === 'single') {
                $isEmpty =
                    !is_string($answer) ||
                    trim($answer) === '';
            } elseif ($type === 'multiple') {
                $isEmpty =
                    !is_array($answer) ||
                    count($answer) === 0;
            }

            if ($required && $isEmpty) {
                $errors[$qid] = '必須項目です。';
                continue;
            }

            if ($type === 'single' && !$isEmpty) {
                $valid = false;

                foreach (($question['options'] ?? []) as $option) {
                    if (
                        is_array($option) &&
                        input_string($option, 'id') === $answer
                    ) {
                        $valid = true;
                        break;
                    }
                }

                if (!$valid) {
                    $errors[$qid] = '選択内容が正しくありません。';
                }
            }

            if ($type === 'multiple' && is_array($answer)) {
                $validOptions = [];

                foreach (($question['options'] ?? []) as $option) {
                    if (is_array($option)) {
                        $validOptions[
                            input_string($option, 'id')
                        ] = true;
                    }
                }

                foreach ($answer as $selected) {
                    if (
                        !is_string($selected) ||
                        !isset($validOptions[$selected])
                    ) {
                        $errors[$qid] = '選択内容が正しくありません。';
                        break;
                    }
                }
            }
        }

        if ($errors !== []) {
            json_response(
                false,
                '未回答の必須項目があります。',
                [],
                $errors,
                422
            );
        }

        $responseData = load_response_data();

        $responseData['responses'][] = [
            'id' => new_id('response'),
            'survey_id' => $surveyId,
            'respondent_token' => $respondentToken,
            'respondent_name' => input_string($data, 'respondent_name'),
            'respondent_email' => input_string($data, 'respondent_email'),
            'answers' => $answers,
            'responded_at' => current_datetime()
        ];

        $responseData['updated_at'] = current_datetime();

        write_json_file(RESPONSES_FILE, $responseData);

        json_response(
            true,
            '回答を送信しました。'
        );
    }

    if ($action === 'prepare_survey_send') {
        $data = read_json_request();

        $surveyId = input_string($data, 'survey_id');
        $customerIds = $data['customer_ids'] ?? [];

        if ($surveyId === '') {
            json_response(
                false,
                'アンケートを指定してください。',
                [],
                [],
                400
            );
        }

        if (!is_array($customerIds)) {
            json_response(
                false,
                '送信対象を確認してください。',
                [],
                [],
                422
            );
        }

        $survey = find_survey($surveyId);

        if ($survey === null) {
            json_response(
                false,
                'アンケートが見つかりません。',
                [],
                [],
                404
            );
        }

        $customers = load_customer_data()['customers'] ?? [];
        $selected = [];

        foreach ($customers as $customer) {
            if (!is_array($customer)) {
                continue;
            }

            $id = input_string($customer, 'id');

            if (in_array($id, $customerIds, true)) {
                $selected[] = $customer;
            }
        }

        $settings = load_settings();

        if (!mail_settings_ready($settings)) {
            json_response(
                false,
                'メール送信設定が未完了です。設定画面を確認してください。',
                [
                    'customers' => $selected
                ],
                [],
                422
            );
        }

        $subject = $survey['name'] . ' アンケートのお願い';

        $body =
            "「" . $survey['name'] . "」へのご回答をお願いいたします。\n\n" .
            "回答ページ：\n" .
            application_api_path() .
            '?action=respond&survey_id=' .
            rawurlencode($surveyId) .
            "\n\n" .
            ($survey['description'] ?? '');

        json_response(
            true,
            '送信内容を準備しました。',
            [
                'survey' => $survey,
                'customers' => $selected,
                'subject' => $subject,
                'body' => $body
            ]
        );
    }

    if ($action === 'send_survey') {
        $data = read_json_request();

        $surveyId = input_string($data, 'survey_id');
        $subject = input_string($data, 'subject');
        $body = input_string($data, 'body');
        $customerIds = $data['customer_ids'] ?? [];

        if ($surveyId === '' || $subject === '' || $body === '') {
            json_response(
                false,
                '送信内容を確認してください。',
                [],
                [],
                422
            );
        }

        if (!is_array($customerIds) || count($customerIds) === 0) {
            json_response(
                false,
                '送信対象者を1名以上選択してください。',
                [],
                [],
                422
            );
        }

        $survey = find_survey($surveyId);

        if ($survey === null) {
            json_response(
                false,
                'アンケートが見つかりません。',
                [],
                [],
                404
            );
        }

        $settings = load_settings();

        if (!mail_settings_ready($settings)) {
            json_response(
                false,
                'メール送信設定が未完了です。',
                [],
                [],
                422
            );
        }

        $customerData = load_customer_data();
        $customers = $customerData['customers'] ?? [];

        $mailData = load_mail_log_data();

        $successCount = 0;
        $failureCount = 0;
        $failed = [];

        foreach ($customers as $customer) {
            if (!is_array($customer)) {
                continue;
            }

            $customerId = input_string($customer, 'id');

            if (!in_array($customerId, $customerIds, true)) {
                continue;
            }

            $email = input_string($customer, 'email');
            $name = input_string($customer, 'name');

            $sendBody =
                $body .
                "\n\n回答用URL：\n" .
                application_api_path() .
                '?action=respond&survey_id=' .
                rawurlencode($surveyId) .
                '&respondent=' .
                rawurlencode($email);

            $result = smtp_send_mail(
                $settings,
                $email,
                $subject,
                $sendBody
            );

            $status = $result['success'] ? 'sent' : 'failed';

            $mailData['logs'][] = [
                'id' => new_id('mail'),
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'name' => $name,
                'email' => $email,
                'subject' => $subject,
                'status' => $status,
                'message' => $result['message'],
                'sent_at' => current_datetime()
            ];

            if ($result['success']) {
                $successCount++;
            } else {
                $failureCount++;

                $failed[] = [
                    'customer_id' => $customerId,
                    'name' => $name,
                    'email' => $email,
                    'message' => $result['message']
                ];
            }
        }

        $mailData['updated_at'] = current_datetime();

        write_json_file(MAIL_LOGS_FILE, $mailData);

        json_response(
            $failureCount === 0,
            $failureCount === 0
                ? 'メール送信が完了しました。'
                : '一部のメール送信に失敗しました。',
            [
                'target_count' => $successCount + $failureCount,
                'success_count' => $successCount,
                'failure_count' => $failureCount,
                'failed' => $failed,
                'sent_at' => current_datetime()
            ],
            [],
            $failureCount === 0 ? 200 : 207
        );
    }

    if ($action === 'prepare_resend') {
        $data = read_json_request();

        $surveyId = input_string($data, 'survey_id');

        if ($surveyId === '') {
            json_response(
                false,
                'アンケートを指定してください。',
                [],
                [],
                400
            );
        }

        $logs = load_mail_log_data()['logs'] ?? [];
        $failed = [];

        foreach ($logs as $log) {
            if (
                is_array($log) &&
                ($log['survey_id'] ?? '') === $surveyId &&
                ($log['status'] ?? '') === 'failed'
            ) {
                $failed[] = $log;
            }
        }

        json_response(
            true,
            '再送対象を取得しました。',
            ['customers' => $failed]
        );
    }

    if ($action === 'resend_survey') {
        $data = read_json_request();

        $surveyId = input_string($data, 'survey_id');
        $subject = input_string($data, 'subject');
        $body = input_string($data, 'body');
        $recipients = $data['recipients'] ?? [];

        if (
            $surveyId === '' ||
            $subject === '' ||
            $body === '' ||
            !is_array($recipients) ||
            count($recipients) === 0
        ) {
            json_response(
                false,
                '再送内容を確認してください。',
                [],
                [],
                422
            );
        }

        $survey = find_survey($surveyId);

        if ($survey === null) {
            json_response(
                false,
                'アンケートが見つかりません。',
                [],
                [],
                404
            );
        }

        $settings = load_settings();

        if (!mail_settings_ready($settings)) {
            json_response(
                false,
                'メール送信設定が未完了です。',
                [],
                [],
                422
            );
        }

        $mailData = load_mail_log_data();

        $successCount = 0;
        $failureCount = 0;
        $failed = [];

        foreach ($recipients as $recipient) {
            if (!is_array($recipient)) {
                continue;
            }

            $email = input_string($recipient, 'email');
            $name = input_string($recipient, 'name');
            $customerId = input_string($recipient, 'customer_id');

            if (!valid_email($email)) {
                $failureCount++;

                $failed[] = [
                    'customer_id' => $customerId,
                    'name' => $name,
                    'email' => $email,
                    'message' => 'メールアドレスが正しくありません。'
                ];

                continue;
            }

            $sendBody =
                $body .
                "\n\n回答用URL：\n" .
                application_api_path() .
                '?action=respond&survey_id=' .
                rawurlencode($surveyId) .
                '&respondent=' .
                rawurlencode($email);

            $result = smtp_send_mail(
                $settings,
                $email,
                $subject,
                $sendBody
            );

            $mailData['logs'][] = [
                'id' => new_id('mail'),
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'name' => $name,
                'email' => $email,
                'subject' => $subject,
                'status' => $result['success']
                    ? 'sent'
                    : 'failed',
                'message' => $result['message'],
                'sent_at' => current_datetime()
            ];

            if ($result['success']) {
                $successCount++;
            } else {
                $failureCount++;

                $failed[] = [
                    'customer_id' => $customerId,
                    'name' => $name,
                    'email' => $email,
                    'message' => $result['message']
                ];
            }
        }

        $mailData['updated_at'] = current_datetime();

        write_json_file(MAIL_LOGS_FILE, $mailData);

        json_response(
            $failureCount === 0,
            $failureCount === 0
                ? '再送が完了しました。'
                : '一部の再送に失敗しました。',
            [
                'success_count' => $successCount,
                'failure_count' => $failureCount,
                'failed' => $failed,
                'sent_at' => current_datetime()
            ],
            [],
            $failureCount === 0 ? 200 : 207
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
 * 回答者画面判定
 * ========================================================= */

$isRespondentPage =
    $action === 'respond' &&
    input_string($_GET, 'survey_id') !== '';

$respondentSurvey = null;

if ($isRespondentPage) {
    $respondentSurvey =
        find_survey(input_string($_GET, 'survey_id'));

    if ($respondentSurvey === null) {
        $isRespondentPage = false;
    }
}

function application_api_path(): string
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

    if (!is_string($scriptName) || $scriptName === '') {
        return 'index.php';
    }

    return $scriptName;
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<title>アンケート業務運営</title>

<style>
* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    min-height: 100%;
}

body {
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Yu Gothic",
        "YuGothic",
        Meiryo,
        sans-serif;
    color: #263238;
    background: #f4f6f8;
    font-size: 14px;
    line-height: 1.6;
}

button,
input,
textarea,
select {
    font: inherit;
}

button {
    cursor: pointer;
}

button:disabled {
    cursor: not-allowed;
    opacity: .55;
}

input,
textarea,
select {
    max-width: 100%;
}

textarea {
    resize: vertical;
}

.hidden {
    display: none !important;
}

/* =========================
   ローディング
========================= */

.loading-spinner {
    display: none;
    width: 14px;
    height: 14px;
    margin-right: 6px;
    border: 2px solid rgba(255,255,255,.45);
    border-top-color: #fff;
    border-radius: 50%;
    vertical-align: -2px;
    animation: newapp-spin .7s linear infinite;
}

.btn.loading .loading-spinner {
    display: inline-block;
}

.btn:not(.loading) .loading-spinner {
    display: none;
}

@keyframes newapp-spin {
    to {
        transform: rotate(360deg);
    }
}

/* =========================
   上部メニュー
========================= */

.topbar {
    min-height: 60px;
    background: #1f3a5f;
    color: #fff;
    display: flex;
    align-items: center;
    padding: 0 24px;
    gap: 30px;
}

.logo {
    font-size: 18px;
    font-weight: bold;
    white-space: nowrap;
}

.main-nav {
    display: flex;
    height: 60px;
    align-items: stretch;
    gap: 2px;
}

.main-nav button {
    height: 60px;
    padding: 0 17px;
    color: #dce7f3;
    background: transparent;
    border: 0;
    white-space: nowrap;
}

.main-nav button:hover,
.main-nav button.active {
    background: #31557f;
    color: #fff;
}

/* =========================
   レイアウト
========================= */

.app {
    max-width: 1440px;
    margin: 0 auto;
    padding: 24px;
}

.page {
    width: 100%;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 20px;
}

.page-header h1 {
    margin: 0;
    font-size: 25px;
    line-height: 1.35;
}

.subtext {
    color: #718096;
    font-size: 13px;
    margin-top: 5px;
}

/* =========================
   ボタン
========================= */

.btn {
    border: 1px solid #cbd5e0;
    background: #fff;
    color: #34495e;
    border-radius: 5px;
    padding: 8px 15px;
    min-height: 38px;
}

.btn:hover {
    background: #f7fafc;
}

.btn-primary {
    background: #2878c8;
    border-color: #2878c8;
    color: #fff;
}

.btn-primary:hover {
    background: #2068ad;
    border-color: #2068ad;
}

.btn-success {
    background: #2f855a;
    border-color: #2f855a;
    color: #fff;
}

.btn-success:hover {
    background: #276749;
}

.btn-danger {
    border-color: #e05a5a;
    color: #c53f3f;
    background: #fff;
}

.btn-danger:hover {
    background: #fff5f5;
}

.btn-warning {
    background: #a66b00;
    border-color: #a66b00;
    color: #fff;
}

.btn-small {
    min-height: 32px;
    padding: 5px 10px;
    font-size: 12px;
}

.link-button {
    border: 0;
    background: none;
    padding: 0;
    color: #2878c8;
    cursor: pointer;
}

.link-button:hover {
    text-decoration: underline;
}

/* =========================
   カード
========================= */

.card {
    background: #fff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    padding: 20px;
    margin-bottom: 18px;
}

.card-title {
    font-size: 17px;
    font-weight: bold;
    margin-bottom: 15px;
}

.card-title small {
    font-size: 12px;
    color: #718096;
    font-weight: normal;
    margin-left: 8px;
}

/* =========================
   通知
========================= */

.notice {
    background: #eef6ff;
    border: 1px solid #c9e1f8;
    color: #315a7d;
    border-radius: 6px;
    padding: 12px 14px;
    margin-bottom: 18px;
}

.notice.success {
    background: #eefaf3;
    border-color: #bde3ca;
    color: #276749;
}

.notice.warning {
    background: #fff8e8;
    border-color: #f1d99b;
    color: #856404;
}

.notice.error {
    background: #fff5f5;
    border-color: #f0c2c2;
    color: #a33a3a;
}

/* =========================
   テーブル
========================= */

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th,
.table td {
    padding: 12px 10px;
    border-bottom: 1px solid #e6ebef;
    text-align: left;
    vertical-align: middle;
    font-size: 13px;
}

.table th {
    background: #f8fafc;
    color: #52606d;
    font-weight: bold;
}

.table tbody tr:hover td {
    background: #fbfdff;
}

.empty {
    text-align: center !important;
    color: #8a98a5;
    padding: 40px !important;
}

/* =========================
   ステータス
========================= */

.status-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 3px 10px;
    font-size: 12px;
    font-weight: bold;
    white-space: nowrap;
}

.status-badge.draft {
    color: #5f6b76;
    background: #edf0f2;
}

.status-badge.published {
    color: #17643c;
    background: #dff4e8;
}

.status-badge.closed {
    color: #6e4c1f;
    background: #f7ead1;
}

.status-dot {
    width: 9px;
    height: 9px;
    display: inline-block;
    border-radius: 50%;
    margin-right: 7px;
    background: #a0aec0;
}

.status-dot.ok {
    background: #2f855a;
}

.status-dot.warn {
    background: #d69e2e;
}

.status-dot.error {
    background: #c53f3f;
}

.status-line {
    display: flex;
    align-items: center;
    padding: 10px 12px;
    background: #f8fafc;
    border-radius: 5px;
    margin-bottom: 16px;
}

/* =========================
   フォーム
========================= */

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 0 20px;
}

.form-grid.three {
    grid-template-columns: repeat(3, minmax(0,1fr));
}

.field {
    margin-bottom: 17px;
}

.field.full {
    grid-column: 1 / -1;
}

.field label {
    display: block;
    font-weight: bold;
    margin-bottom: 6px;
}

.field label .required {
    color: #c53f3f;
    margin-left: 3px;
}

.field input,
.field textarea,
.field select {
    width: 100%;
    border: 1px solid #cbd5e0;
    border-radius: 5px;
    background: #fff;
    padding: 9px 10px;
    min-height: 38px;
    outline: none;
}

.field textarea {
    min-height: 110px;
}

.field input:focus,
.field textarea:focus,
.field select:focus {
    border-color: #2878c8;
    box-shadow: 0 0 0 2px rgba(40,120,200,.12);
}

.field-error {
    color: #c53f3f;
    font-size: 12px;
    margin-top: 4px;
}

.help {
    color: #718096;
    font-size: 12px;
    margin-top: 4px;
}

/* =========================
   ツールバー
========================= */

.toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}

.toolbar-between {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.search-input {
    min-width: 260px;
    border: 1px solid #cbd5e0;
    border-radius: 5px;
    padding: 8px 10px;
    min-height: 38px;
}

/* =========================
   ダッシュボード
========================= */

.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap: 14px;
    margin-bottom: 18px;
}

.metric-card {
    background: #fff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    padding: 17px;
}

.metric-label {
    color: #718096;
    font-size: 12px;
    margin-bottom: 6px;
}

.metric-value {
    font-size: 27px;
    font-weight: bold;
    color: #263238;
}

.metric-value small {
    font-size: 13px;
    font-weight: normal;
    margin-left: 3px;
}

/* =========================
   タブ
========================= */

.tabs {
    display: flex;
    gap: 2px;
    border-bottom: 1px solid #dfe5eb;
    margin-bottom: 18px;
}

.tabs button {
    border: 0;
    background: transparent;
    padding: 10px 17px;
    color: #66727d;
    border-bottom: 3px solid transparent;
}

.tabs button.active {
    color: #2878c8;
    border-bottom-color: #2878c8;
    font-weight: bold;
}

/* =========================
   編集画面
========================= */

.editor-group {
    border: 1px solid #d9e1e8;
    border-radius: 7px;
    margin-bottom: 15px;
    background: #fff;
}

.editor-group.drag-over {
    border-color: #2878c8;
    box-shadow: 0 0 0 2px rgba(40,120,200,.12);
}

.group-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px;
    background: #f8fafc;
    border-bottom: 1px solid #e6ebef;
}

.drag-handle {
    cursor: grab;
    color: #9aa7b3;
    user-select: none;
    font-size: 18px;
}

.drag-handle:active {
    cursor: grabbing;
}

.group-name-input {
    flex: 1;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 7px 9px;
    font-weight: bold;
}

.group-body {
    padding: 13px;
}

.question-card {
    border: 1px solid #e0e6eb;
    border-radius: 6px;
    padding: 13px;
    margin-bottom: 11px;
    background: #fff;
}

.question-card.drag-over {
    border-color: #2878c8;
    background: #f7fbff;
}

.question-head {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 9px;
}

.question-title {
    flex: 1;
}

.question-title input {
    width: 100%;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 7px 9px;
}

.question-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-bottom: 10px;
}

.question-meta select {
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 6px 8px;
}

.checkbox-inline {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.option-row {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 6px;
}

.option-row input {
    flex: 1;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 6px 8px;
}

.branch-row {
    display: grid;
    grid-template-columns: minmax(100px,.7fr) minmax(160px,1fr);
    gap: 8px;
    align-items: center;
    margin-bottom: 6px;
}

.branch-row select {
    width: 100%;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 6px;
}

.add-row {
    margin-top: 8px;
    text-align: center;
}

.add-group {
    text-align: center;
    margin-top: 8px;
}

/* =========================
   内容確認
========================= */

.preview-group {
    margin-bottom: 20px;
}

.preview-group-title {
    font-size: 17px;
    font-weight: bold;
    padding-bottom: 8px;
    border-bottom: 2px solid #dfe5eb;
    margin-bottom: 8px;
}

.preview-question {
    padding: 12px 4px;
    border-bottom: 1px solid #edf1f4;
}

.preview-question-title {
    font-weight: bold;
    margin-bottom: 5px;
}

.preview-option {
    padding-left: 12px;
    color: #52606d;
}

/* =========================
   送信画面
========================= */

.send-layout {
    display: grid;
    grid-template-columns: minmax(0,1.2fr) minmax(300px,.8fr);
    gap: 18px;
}

.customer-search {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
}

.customer-search input {
    flex: 1;
    border: 1px solid #cbd5e0;
    border-radius: 5px;
    padding: 8px 10px;
}

.customer-list {
    max-height: 480px;
    overflow: auto;
    border: 1px solid #e1e7ec;
    border-radius: 5px;
}

.customer-row {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 10px;
    border-bottom: 1px solid #edf1f4;
}

.customer-row:last-child {
    border-bottom: 0;
}

.customer-info {
    flex: 1;
    min-width: 0;
}

.customer-name {
    font-weight: bold;
}

.customer-email {
    color: #718096;
    font-size: 12px;
    overflow-wrap: anywhere;
}

.selected-panel {
    background: #f8fafc;
    border: 1px solid #dfe5eb;
    border-radius: 6px;
    padding: 12px;
}

.selected-customer {
    padding: 8px 0;
    border-bottom: 1px solid #e5e9ed;
}

.selected-customer:last-child {
    border-bottom: 0;
}

/* =========================
   回答者
========================= */

.respondent-page {
    max-width: 900px;
    margin: 0 auto;
    padding: 25px 18px 60px;
}

.respondent-header {
    background: #fff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    padding: 22px;
    margin-bottom: 18px;
}

.respondent-header h1 {
    margin-top: 0;
}

.respondent-question {
    background: #fff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    padding: 20px;
    margin-bottom: 16px;
}

.respondent-question-title {
    font-weight: bold;
    margin-bottom: 13px;
}

.respondent-option {
    margin-bottom: 8px;
}

.respondent-option label {
    display: flex;
    align-items: flex-start;
    gap: 8px;
}

.respondent-option input {
    margin-top: 5px;
}

.respondent-complete {
    background: #fff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    padding: 35px 22px;
    text-align: center;
}

/* =========================
   メッセージ
========================= */

.toast-container {
    position: fixed;
    top: 76px;
    right: 20px;
    z-index: 1000;
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: min(380px, calc(100vw - 40px));
}

.toast {
    background: #263238;
    color: #fff;
    border-radius: 6px;
    padding: 11px 14px;
    box-shadow: 0 5px 18px rgba(0,0,0,.16);
}

.toast.success {
    background: #2f855a;
}

.toast.error {
    background: #c53f3f;
}

.toast.warning {
    background: #a66b00;
}

/* =========================
   モーダル
========================= */

.modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 900;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(25,35,45,.48);
}

.modal {
    width: min(760px,100%);
    max-height: calc(100vh - 40px);
    overflow-y: auto;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 12px 35px rgba(0,0,0,.25);
}

.modal-header {
    padding: 17px 20px;
    border-bottom: 1px solid #e6ebef;
    font-weight: bold;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 13px 20px;
    border-top: 1px solid #e6ebef;
}

/* =========================
   グラフ
========================= */

.bar-row {
    margin-bottom: 12px;
}

.bar-label {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 4px;
}

.bar-track {
    height: 18px;
    background: #edf2f7;
    border-radius: 4px;
    overflow: hidden;
}

.bar-value {
    height: 100%;
    background: #2878c8;
    border-radius: 4px;
}

.free-answer {
    border: 1px solid #e1e7ec;
    background: #f8fafc;
    border-radius: 5px;
    padding: 9px 10px;
    margin-bottom: 7px;
}

/* =========================
   レスポンシブ
========================= */

@media (max-width: 1000px) {
    .dashboard-grid {
        grid-template-columns: repeat(2,minmax(0,1fr));
    }

    .send-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 760px) {
    .topbar {
        min-height: auto;
        align-items: flex-start;
        flex-direction: column;
        gap: 0;
        padding: 10px 12px 0;
    }

    .main-nav {
        width: 100%;
        height: auto;
        overflow-x: auto;
    }

    .main-nav button {
        height: 44px;
        flex: 0 0 auto;
    }

    .app {
        padding: 15px;
    }

    .page-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .form-grid,
    .form-grid.three {
        grid-template-columns: 1fr;
    }

    .field.full {
        grid-column: auto;
    }

    .dashboard-grid {
        grid-template-columns: 1fr 1fr;
    }

    .branch-row {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 520px) {
    .dashboard-grid {
        grid-template-columns: 1fr;
    }

    .toolbar-between {
        align-items: flex-start;
        flex-direction: column;
    }

    .search-input {
        min-width: 0;
        width: 100%;
    }
}
</style>
</head>

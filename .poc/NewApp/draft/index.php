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

function now_iso(): string
{
    return date('c');
}

function today(): string
{
    return date('Y-m-d');
}

function csrf_token(): string
{
    $token = $_SESSION[APP_SESSION_KEY]['csrf_token'] ?? '';
    return is_string($token) ? $token : '';
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
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/**
 * PHP 8.4/8.5対応のレスポンスヘッダー取得
 */
function get_safe_response_headers(): array
{
    return http_get_last_response_headers() ?? [];
}

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
        'version' => 2,
        'updated_at' => null,
        'mail' => [
            'smtp_server' => '',
            'smtp_port' => 587,
            'connection_type' => 'STARTTLS',
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
            'customer_id_field' => '$id',
            'customer_name_field' => '顧客名',
            'customer_email_field' => 'メールアドレス',
            'customer_company_field' => '会社名',
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
        'version' => 2,
        'updated_at' => null,
        'source' => 'kintone',
        'count' => 0,
        'customers' => []
    ];
}

function default_surveys(): array
{
    return [
        'version' => 2,
        'updated_at' => null,
        'surveys' => []
    ];
}

function default_responses(): array
{
    return [
        'version' => 2,
        'updated_at' => null,
        'responses' => []
    ];
}

function default_mail_logs(): array
{
    return [
        'version' => 2,
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

    if ($contents === false) {
        json_response(
            false,
            'データを読み込めませんでした。',
            [],
            [],
            500
        );
    }

    if (trim($contents) === '') {
        return $default;
    }

    try {
        $decoded = json_decode(
            $contents,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
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
            '保存されているデータの形式が正しくありません。',
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
        $written = fwrite($handle, $json);

        if ($written === false || $written !== $length) {
            throw new \RuntimeException('write');
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

    $verify = file_get_contents($file);

    if ($verify === false) {
        json_response(
            false,
            '保存したデータを確認できませんでした。',
            [],
            [],
            500
        );
    }

    try {
        $check = json_decode($verify, true, 512, JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        json_response(
            false,
            '保存したデータを確認できませんでした。',
            [],
            [],
            500
        );
    }

    if (!is_array($check)) {
        json_response(
            false,
            '保存したデータを確認できませんでした。',
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

function read_json_request(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    try {
        $data = json_decode(
            $raw,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (\Throwable $e) {
        json_response(
            false,
            '入力内容を確認してください。',
            [],
            ['request' => 'JSON形式のデータを指定してください。'],
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

function application_api_path(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? 'index.php';

    if (!is_string($script) || $script === '') {
        return 'index.php';
    }

    return $script;
}

function scalar_string(mixed $value): string
{
    return is_scalar($value) ? trim((string)$value) : '';
}

function new_id(): string
{
    return bin2hex(random_bytes(12));
}

function survey_question_count(array $survey): int
{
    $count = 0;

    foreach (($survey['groups'] ?? []) as $group) {
        if (!is_array($group)) {
            continue;
        }

        $questions = $group['questions'] ?? [];

        if (is_array($questions)) {
            $count += count($questions);
        }
    }

    return $count;
}

function normalize_question(array $question): array
{
    $type = scalar_string($question['type'] ?? 'free');

    if (!in_array($type, ['free', 'single', 'multiple'], true)) {
        $type = 'free';
    }

    $options = [];

    if (is_array($question['options'] ?? null)) {
        foreach ($question['options'] as $option) {
            if (!is_array($option)) {
                continue;
            }

            $options[] = [
                'id' => scalar_string($option['id'] ?? '') !== ''
                    ? scalar_string($option['id'])
                    : new_id(),
                'text' => scalar_string($option['text'] ?? ''),
                'branch' => scalar_string($option['branch'] ?? '')
            ];
        }
    }

    if ($type === 'free') {
        $options = [];
    }

    return [
        'id' => scalar_string($question['id'] ?? '') !== ''
            ? scalar_string($question['id'])
            : new_id(),
        'text' => scalar_string($question['text'] ?? ''),
        'type' => $type,
        'required' => !empty($question['required']),
        'options' => $options
    ];
}

function normalize_survey(array $survey): array
{
    $status = scalar_string($survey['status'] ?? 'draft');

    if (!in_array($status, ['draft', 'open', 'end'], true)) {
        $status = 'draft';
    }

    $numbering = scalar_string($survey['numbering'] ?? 'global');

    if (!in_array($numbering, ['global', 'group'], true)) {
        $numbering = 'global';
    }

    $groups = [];

    foreach (($survey['groups'] ?? []) as $group) {
        if (!is_array($group)) {
            continue;
        }

        $questions = [];

        foreach (($group['questions'] ?? []) as $question) {
            if (is_array($question)) {
                $questions[] = normalize_question($question);
            }
        }

        $groups[] = [
            'id' => scalar_string($group['id'] ?? '') !== ''
                ? scalar_string($group['id'])
                : new_id(),
            'name' => scalar_string($group['name'] ?? ''),
            'questions' => $questions
        ];
    }

    $accessToken = scalar_string($survey['access_token'] ?? '');

    if ($accessToken === '') {
        $accessToken = bin2hex(random_bytes(24));
    }

    return [
        'id' => scalar_string($survey['id'] ?? '') !== ''
            ? scalar_string($survey['id'])
            : new_id(),
        'name' => scalar_string($survey['name'] ?? ''),
        'description' => scalar_string($survey['description'] ?? ''),
        'status' => $status,
        'created' => scalar_string($survey['created'] ?? today()),
        'start' => scalar_string($survey['start'] ?? ''),
        'end' => scalar_string($survey['end'] ?? ''),
        'updated' => scalar_string($survey['updated'] ?? today()),
        'numbering' => $numbering,
        'access_token' => $accessToken,
        'target' => max(0, (int)($survey['target'] ?? 0)),
        'sent' => max(0, (int)($survey['sent'] ?? 0)),
        'groups' => $groups
    ];
}

function find_survey(array $data, string $id): ?array
{
    foreach (($data['surveys'] ?? []) as $survey) {
        if (!is_array($survey)) {
            continue;
        }

        if ((string)($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function survey_index(array $data, string $id): int
{
    foreach (($data['surveys'] ?? []) as $index => $survey) {
        if (is_array($survey) && (string)($survey['id'] ?? '') === $id) {
            return (int)$index;
        }
    }

    return -1;
}

function find_question(array $survey, string $questionId): ?array
{
    foreach (($survey['groups'] ?? []) as $group) {
        if (!is_array($group)) {
            continue;
        }

        foreach (($group['questions'] ?? []) as $question) {
            if (
                is_array($question) &&
                (string)($question['id'] ?? '') === $questionId
            ) {
                return $question;
            }
        }
    }

    return null;
}

function question_number(
    array $survey,
    int $groupIndex,
    int $questionIndex
): string {
    if (($survey['numbering'] ?? 'global') === 'group') {
        return 'Q' . ($groupIndex + 1) . '-' . ($questionIndex + 1);
    }

    $number = 0;

    foreach (($survey['groups'] ?? []) as $gi => $group) {
        $questions = $group['questions'] ?? [];

        if (!is_array($questions)) {
            continue;
        }

        foreach ($questions as $qi => $question) {
            $number++;

            if ($gi === $groupIndex && $qi === $questionIndex) {
                return 'Q' . $number;
            }
        }
    }

    return 'Q-';
}

function validate_survey(array $survey): array
{
    $errors = [];

    if (trim((string)($survey['name'] ?? '')) === '') {
        $errors[] = 'アンケート名を入力してください。';
    }

    $start = scalar_string($survey['start'] ?? '');
    $end = scalar_string($survey['end'] ?? '');

    if ($start !== '' && $end !== '' && $start > $end) {
        $errors[] = '公開開始日は公開終了日以前にしてください。';
    }

    $groups = $survey['groups'] ?? [];

    if (!is_array($groups) || count($groups) === 0) {
        $errors[] = 'グループを1つ以上設定してください。';
        return $errors;
    }

    $questionIds = [];

    foreach ($groups as $gi => $group) {
        if (!is_array($group)) {
            $errors[] = 'グループの内容を確認してください。';
            continue;
        }

        if (trim((string)($group['name'] ?? '')) === '') {
            $errors[] = 'グループ' . ($gi + 1) . 'の名前を入力してください。';
        }

        $questions = $group['questions'] ?? [];

        if (!is_array($questions)) {
            continue;
        }

        foreach ($questions as $qi => $question) {
            if (!is_array($question)) {
                continue;
            }

            $number = question_number($survey, $gi, $qi);
            $qid = scalar_string($question['id'] ?? '');

            if ($qid === '') {
                $errors[] = $number . 'の識別情報がありません。';
            } else {
                $questionIds[$qid] = true;
            }

            if (trim((string)($question['text'] ?? '')) === '') {
                $errors[] = $number . 'の質問文を入力してください。';
            }

            $type = scalar_string($question['type'] ?? 'free');

            if (!in_array($type, ['free', 'single', 'multiple'], true)) {
                $errors[] = $number . 'の回答形式を確認してください。';
            }

            if (
                in_array($type, ['single', 'multiple'], true) &&
                empty($question['options'])
            ) {
                $errors[] = $number . 'に選択肢を設定してください。';
            }

            foreach (($question['options'] ?? []) as $oi => $option) {
                if (!is_array($option)) {
                    continue;
                }

                if (trim((string)($option['text'] ?? '')) === '') {
                    $errors[] =
                        $number .
                        'の選択肢' .
                        ($oi + 1) .
                        'を入力してください。';
                }
            }
        }
    }

    foreach ($groups as $gi => $group) {
        if (!is_array($group)) {
            continue;
        }

        foreach (($group['questions'] ?? []) as $qi => $question) {
            if (!is_array($question)) {
                continue;
            }

            if (($question['type'] ?? '') !== 'single') {
                continue;
            }

            foreach (($question['options'] ?? []) as $option) {
                if (!is_array($option)) {
                    continue;
                }

                $branch = scalar_string($option['branch'] ?? '');

                if ($branch !== '' && $branch !== 'END' && !isset($questionIds[$branch])) {
                    $errors[] =
                        question_number($survey, $gi, $qi) .
                        'の分岐先が存在しません。';
                }

                if ($branch === (string)($question['id'] ?? '')) {
                    $errors[] =
                        question_number($survey, $gi, $qi) .
                        'が自分自身を分岐先にしています。';
                }
            }
        }
    }

    return array_values(array_unique($errors));
}

function public_survey_url(array $survey): string
{
    $scheme = 'http';

    if (
        isset($_SERVER['HTTPS']) &&
        strtolower((string)$_SERVER['HTTPS']) !== 'off'
    ) {
        $scheme = 'https';
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');

    if ($host === '') {
        return '';
    }

    $script = application_api_path();

    return $scheme .
        '://' .
        $host .
        $script .
        '?respond=1&survey=' .
        rawurlencode((string)$survey['id']) .
        '&token=' .
        rawurlencode((string)$survey['access_token']);
}

function survey_is_open(array $survey): bool
{
    if (($survey['status'] ?? '') !== 'open') {
        return false;
    }

    $today = today();
    $start = scalar_string($survey['start'] ?? '');
    $end = scalar_string($survey['end'] ?? '');

    if ($start !== '' && $today < $start) {
        return false;
    }

    if ($end !== '' && $today > $end) {
        return false;
    }

    return true;
}

/* =========================================================
 * kintone
 * ========================================================= */

function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain) ?? '';
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain) ?? '';
    $domain = rtrim($domain, '/');

    if ($domain === '') {
        throw new \InvalidArgumentException('kintoneの利用先が未設定です。');
    }

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

function parse_proxy_host_port(
    string $host,
    string $port
): string {
    $host = trim($host);
    $port = trim($port);

    if ($host === '' && $port === '') {
        return '';
    }

    if ($host === '' || $port === '') {
        throw new \InvalidArgumentException(
            'プロキシを使用する場合はホスト名とポート番号を入力してください。'
        );
    }

    $host = preg_replace('/^[a-z]+:\/\//i', '', $host) ?? '';
    $host = trim($host, '/');

    if (
        !preg_match(
            '/^(?:[a-zA-Z0-9.-]+|\[[0-9a-fA-F:]+\])$/',
            $host
        )
    ) {
        throw new \InvalidArgumentException(
            'プロキシのホスト名を確認してください。'
        );
    }

    if (!ctype_digit($port)) {
        throw new \InvalidArgumentException(
            'プロキシのポート番号を確認してください。'
        );
    }

    $portNumber = (int)$port;

    if ($portNumber < 1 || $portNumber > 65535) {
        throw new \InvalidArgumentException(
            'プロキシのポート番号を確認してください。'
        );
    }

    return 'tcp://' . $host . ':' . $portNumber;
}

function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload,
    array $config
): array {
    $method = strtoupper($method);

    $httpOptions = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 30,
        'protocol_version' => 1.1
    ];

    if ($method === 'GET') {
        if ($payload !== null && is_array($payload) && $payload !== []) {
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
    } elseif ($payload !== null) {
        $body = is_string($payload)
            ? $payload
            : json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            );

        $httpOptions['content'] = $body;
    }

    $contextOptions = [
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    $proxyHost = trim((string)($config['proxy_host'] ?? ''));
    $proxyPort = trim((string)($config['proxy_port'] ?? ''));

    /*
     * プロキシ設定は毎回受け取り、設定されている場合は
     * 必ず stream_context に適用する。
     */
    $proxy = parse_proxy_host_port($proxyHost, $proxyPort);

    if ($proxy !== '') {
        $contextOptions['http']['proxy'] = $proxy;
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
                '/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i',
                $headerLine,
                $matches
            )
        ) {
            $statusCode = (int)$matches[1];
        }
    }

    $resultData = null;

    if (is_string($responseBody) && trim($responseBody) !== '') {
        $decoded = json_decode($responseBody, true);

        if (is_array($decoded)) {
            $resultData = $decoded;
        }
    }

    if (
        $responseBody !== false &&
        $statusCode >= 200 &&
        $statusCode < 300
    ) {
        return [
            'success' => true,
            'status' => $statusCode,
            'data' => is_array($resultData) ? $resultData : []
        ];
    }

    $message = 'kintone APIとの通信に失敗しました。';

    if (is_array($resultData)) {
        if (
            isset($resultData['message']) &&
            is_string($resultData['message'])
        ) {
            $message = $resultData['message'];
        }

        if (
            isset($resultData['code']) &&
            is_string($resultData['code'])
        ) {
            $message =
                '[' .
                $resultData['code'] .
                '] ' .
                $message;
        }
    }

    if ($statusCode > 0) {
        $message .= ' HTTPステータス: ' . $statusCode;
    }

    return [
        'success' => false,
        'status' => $statusCode,
        'message' => $message,
        'raw' => is_array($resultData) ? $resultData : []
    ];
}

function kintone_config_from_settings(array $settings): array
{
    $kt = $settings['kintone'] ?? [];

    return [
        'domain' => scalar_string($kt['domain'] ?? ''),
        'login_name' => scalar_string($kt['login_name'] ?? ''),
        'password' => (string)($kt['password'] ?? ''),
        'customer_app_id' => scalar_string($kt['customer_app_id'] ?? ''),
        'customer_id_field' => scalar_string(
            $kt['customer_id_field'] ?? '$id'
        ),
        'customer_name_field' => scalar_string(
            $kt['customer_name_field'] ?? ''
        ),
        'customer_email_field' => scalar_string(
            $kt['customer_email_field'] ?? ''
        ),
        'customer_company_field' => scalar_string(
            $kt['customer_company_field'] ?? ''
        ),
        'proxy_host' => scalar_string($kt['proxy_host'] ?? ''),
        'proxy_port' => scalar_string($kt['proxy_port'] ?? '')
    ];
}

function validate_kintone_config(array $config): array
{
    $errors = [];

    if ($config['domain'] === '') {
        $errors[] = 'kintoneの利用先を入力してください。';
    }

    if ($config['login_name'] === '') {
        $errors[] = 'ログイン名を入力してください。';
    }

    if ($config['password'] === '') {
        $errors[] = 'パスワードを入力してください。';
    }

    if ($config['customer_app_id'] === '') {
        $errors[] = '顧客管理アプリIDを入力してください。';
    }

    if ($config['customer_name_field'] === '') {
        $errors[] = '顧客名フィールドコードを入力してください。';
    }

    if ($config['customer_email_field'] === '') {
        $errors[] = 'メールアドレスフィールドコードを入力してください。';
    }

    if (
        ($config['proxy_host'] === '') !==
        ($config['proxy_port'] === '')
    ) {
        $errors[] =
            'プロキシを使用する場合はホスト名とポート番号の両方を入力してください。';
    }

    return $errors;
}

function kintone_auth_headers(array $config): array
{
    return [
        'X-Cybozu-Authorization: ' .
            base64_encode(
                trim($config['login_name']) .
                ':' .
                trim($config['password'])
            ),
        'Accept: application/json'
    ];
}

function kintone_get_customers(array $config): array
{
    $headers = kintone_auth_headers($config);

    $fields = array_values(
        array_filter([
            $config['customer_id_field'] !== ''
                ? $config['customer_id_field']
                : '$id',
            $config['customer_name_field'],
            $config['customer_email_field'],
            $config['customer_company_field']
        ])
    );

    $params = [
        'app' => $config['customer_app_id'],
        'fields' => $fields,
        'totalCount' => 'false',
        'order by' => '$id asc',
        'limit' => 500
    ];

    $url = kintone_build_url(
        $config['domain'],
        '/k/v1/records.json'
    );

    $result = kintone_api_request(
        'GET',
        $url,
        $headers,
        $params,
        $config
    );

    if (!$result['success']) {
        return $result;
    }

    $records = $result['data']['records'] ?? [];

    if (!is_array($records)) {
        $records = [];
    }

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $getValue = static function (
            array $record,
            string $field
        ): string {
            if ($field === '') {
                return '';
            }

            $value = $record[$field]['value'] ?? '';

            if (is_array($value)) {
                return '';
            }

            return is_scalar($value) ? trim((string)$value) : '';
        };

        $id = $getValue(
            $record,
            $config['customer_id_field'] !== ''
                ? $config['customer_id_field']
                : '$id'
        );

        $name = $getValue(
            $record,
            $config['customer_name_field']
        );

        $email = $getValue(
            $record,
            $config['customer_email_field']
        );

        $company = $getValue(
            $record,
            $config['customer_company_field']
        );

        if ($id === '') {
            $id = new_id();
        }

        if ($email === '') {
            continue;
        }

        $customers[] = [
            'id' => $id,
            'name' => $name !== '' ? $name : $email,
            'email' => $email,
            'company' => $company,
            'code' => $id
        ];
    }

    return [
        'success' => true,
        'status' => $result['status'],
        'customers' => $customers
    ];
}

/* =========================================================
 * SMTP
 * ========================================================= */

function smtp_read($socket): string
{
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket, 515);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (
            strlen($line) >= 4 &&
            $line[3] === ' '
        ) {
            break;
        }
    }

    return $response;
}

function smtp_expect($socket, array $codes): void
{
    $response = smtp_read($socket);

    if (
        !preg_match(
            '/^(\d{3})/',
            $response,
            $matches
        )
    ) {
        throw new \RuntimeException(
            'SMTPサーバーから正しい応答を取得できませんでした。'
        );
    }

    $code = (int)$matches[1];

    if (!in_array($code, $codes, true)) {
        throw new \RuntimeException(
            'SMTPサーバーとの通信に失敗しました。'
        );
    }
}

function smtp_command(
    $socket,
    string $command,
    array $codes
): void {
    fwrite($socket, $command . "\r\n");
    smtp_expect($socket, $codes);
}

function smtp_send_mail(
    array $settings,
    string $to,
    string $toName,
    string $subject,
    string $body
): array {
    $server = trim((string)($settings['smtp_server'] ?? ''));
    $port = (int)($settings['smtp_port'] ?? 587);
    $security = strtoupper(
        trim((string)($settings['connection_type'] ?? 'STARTTLS'))
    );
    $username = trim((string)($settings['username'] ?? ''));
    $password = (string)($settings['password'] ?? '');
    $from = trim((string)($settings['from_email'] ?? ''));
    $fromName = trim((string)($settings['from_name'] ?? ''));

    if ($server === '' || $from === '') {
        return [
            'success' => false,
            'message' => 'メール送信設定が未完了です。'
        ];
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => '送信先メールアドレスが正しくありません。'
        ];
    }

    $remote = $server . ':' . $port;

    if ($security === 'SSL/TLS') {
        $remote = 'ssl://' . $remote;
    }

    $errno = 0;
    $errstr = '';

    $socket = @fsockopen(
        $remote,
        $port,
        $errno,
        $errstr,
        20
    );

    if ($socket === false) {
        return [
            'success' => false,
            'message' => 'SMTPサーバーへ接続できませんでした。'
        ];
    }

    stream_set_timeout($socket, 20);

    try {
        smtp_expect($socket, [220]);

        $hostname = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');

        smtp_command(
            $socket,
            'EHLO ' . $hostname,
            [250]
        );

        if ($security === 'STARTTLS') {
            smtp_command(
                $socket,
                'STARTTLS',
                [220]
            );

            $crypto = stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new \RuntimeException(
                    'SMTPの暗号化通信を開始できませんでした。'
                );
            }

            smtp_command(
                $socket,
                'EHLO ' . $hostname,
                [250]
            );
        }

        if ($username !== '') {
            smtp_command(
                $socket,
                'AUTH LOGIN',
                [334]
            );

            smtp_command(
                $socket,
                base64_encode($username),
                [334]
            );

            smtp_command(
                $socket,
                base64_encode($password),
                [235]
            );
        }

        smtp_command(
            $socket,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        smtp_command(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        smtp_command(
            $socket,
            'DATA',
            [354]
        );

        $encodedSubject = '=?UTF-8?B?' .
            base64_encode($subject) .
            '?=';

        $encodedFromName = $fromName !== ''
            ? '=?UTF-8?B?' .
                base64_encode($fromName) .
                '?='
            : $from;

        $encodedToName = $toName !== ''
            ? '=?UTF-8?B?' .
                base64_encode($toName) .
                '?='
            : $to;

        $body = str_replace(
            ["\r\n", "\r", "\n"],
            "\r\n",
            $body
        );

        $body = preg_replace(
            '/^\./m',
            '..',
            $body
        ) ?? $body;

        $message =
            'Date: ' . date(DATE_RFC2822) . "\r\n" .
            'From: ' . $encodedFromName . ' <' . $from . ">\r\n" .
            'To: ' . $encodedToName . ' <' . $to . ">\r\n" .
            'Subject: ' . $encodedSubject . "\r\n" .
            'MIME-Version: 1.0' . "\r\n" .
            'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
            'Content-Transfer-Encoding: 8bit' . "\r\n" .
            "\r\n" .
            $body .
            "\r\n.\r\n";

        fwrite($socket, $message);
        smtp_expect($socket, [250]);

        smtp_command(
            $socket,
            'QUIT',
            [221]
        );

        fclose($socket);

        return [
            'success' => true,
            'message' => 'メールを送信しました。'
        ];
    } catch (\Throwable $e) {
        if (is_resource($socket)) {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
        }

        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/* =========================================================
 * 集計
 * ========================================================= */

function get_responses_for_survey(
    array $responseData,
    string $surveyId
): array {
    $result = [];

    foreach (($responseData['responses'] ?? []) as $response) {
        if (
            is_array($response) &&
            (string)($response['survey_id'] ?? '') === $surveyId
        ) {
            $result[] = $response;
        }
    }

    return $result;
}

function response_has_question(
    array $response,
    string $questionId
): bool {
    $answers = $response['answers'] ?? [];

    return is_array($answers) &&
        array_key_exists($questionId, $answers);
}

function aggregate_question(
    array $survey,
    string $questionId,
    array $responses
): array {
    $question = find_question($survey, $questionId);

    if ($question === null) {
        return [];
    }

    $type = (string)($question['type'] ?? 'free');

    $answerCount = 0;
    $optionCounts = [];
    $freeAnswers = [];

    foreach (($question['options'] ?? []) as $option) {
        if (is_array($option)) {
            $oid = (string)($option['id'] ?? '');

            if ($oid !== '') {
                $optionCounts[$oid] = 0;
            }
        }
    }

    foreach ($responses as $response) {
        $answers = $response['answers'] ?? [];

        if (
            !is_array($answers) ||
            !array_key_exists($questionId, $answers)
        ) {
            continue;
        }

        $answer = $answers[$questionId];

        if (
            $answer === '' ||
            $answer === null ||
            $answer === []
        ) {
            continue;
        }

        $answerCount++;

        if ($type === 'single') {
            $selected = is_scalar($answer)
                ? (string)$answer
                : '';

            if (isset($optionCounts[$selected])) {
                $optionCounts[$selected]++;
            }
        } elseif ($type === 'multiple') {
            $selectedValues = is_array($answer)
                ? $answer
                : [$answer];

            foreach ($selectedValues as $selected) {
                if (!is_scalar($selected)) {
                    continue;
                }

                $selected = (string)$selected;

                if (isset($optionCounts[$selected])) {
                    $optionCounts[$selected]++;
                }
            }
        } else {
            $freeAnswers[] = (string)$answer;
        }
    }

    $options = [];

    foreach (($question['options'] ?? []) as $option) {
        if (!is_array($option)) {
            continue;
        }

        $oid = (string)($option['id'] ?? '');
        $count = $optionCounts[$oid] ?? 0;

        $options[] = [
            'id' => $oid,
            'text' => (string)($option['text'] ?? ''),
            'count' => $count,
            'percentage' => $answerCount > 0
                ? round(($count / $answerCount) * 100, 1)
                : 0
        ];
    }

    return [
        'question_id' => $questionId,
        'type' => $type,
        'answer_count' => $answerCount,
        'options' => $options,
        'free_answers' => $freeAnswers
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

    if ($method === 'GET') {
        if ($action === 'load_settings') {
            $settings = load_settings();

            $public = $settings;

            if (isset($public['mail']['password'])) {
                $public['mail']['password'] = '';
            }

            if (isset($public['kintone']['password'])) {
                $public['kintone']['password'] = '';
            }

            json_response(
                true,
                '設定を取得しました。',
                ['settings' => $public]
            );
        }

        if ($action === 'load_customers') {
            $data = load_customers();

            json_response(
                true,
                '顧客一覧を取得しました。',
                [
                    'customers' => $data['customers'] ?? [],
                    'updated_at' => $data['updated_at'] ?? null
                ]
            );
        }

        if ($action === 'load_surveys') {
            $data = load_surveys();
            $responseData = load_responses();

            foreach ($data['surveys'] as &$survey) {
                if (!is_array($survey)) {
                    continue;
                }

                $responses = get_responses_for_survey(
                    $responseData,
                    (string)($survey['id'] ?? '')
                );

                $survey['answers'] = count($responses);
            }
            unset($survey);

            json_response(
                true,
                'アンケート一覧を取得しました。',
                ['surveys' => $data['surveys'] ?? []]
            );
        }

        if ($action === 'load_survey') {
            $id = scalar_string($_GET['id'] ?? '');

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
            $survey = find_survey($data, $id);

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

        if ($action === 'aggregate_responses') {
            $id = scalar_string($_GET['id'] ?? '');

            $data = load_surveys();
            $survey = find_survey($data, $id);

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
            $responses = get_responses_for_survey(
                $responseData,
                $id
            );

            $questions = [];

            foreach (($survey['groups'] ?? []) as $group) {
                if (!is_array($group)) {
                    continue;
                }

                foreach (($group['questions'] ?? []) as $question) {
                    if (!is_array($question)) {
                        continue;
                    }

                    $qid = (string)($question['id'] ?? '');

                    if ($qid === '') {
                        continue;
                    }

                    $questions[] = aggregate_question(
                        $survey,
                        $qid,
                        $responses
                    );
                }
            }

            json_response(
                true,
                '回答結果を集計しました。',
                [
                    'survey_id' => $id,
                    'total_responses' => count($responses),
                    'unique_respondents' => count(
                        array_unique(
                            array_map(
                                static function (array $response): string {
                                    return (string)(
                                        $response['respondent_key'] ?? ''
                                    );
                                },
                                $responses
                            )
                        )
                    ),
                    'questions' => $questions
                ]
            );
        }

        if ($action === 'load_mail_logs') {
            $surveyId = scalar_string($_GET['survey_id'] ?? '');
            $data = load_mail_logs();

            $logs = [];

            foreach (($data['logs'] ?? []) as $log) {
                if (
                    !is_array($log) ||
                    $surveyId === '' ||
                    (string)($log['survey_id'] ?? '') === $surveyId
                ) {
                    if (is_array($log)) {
                        $logs[] = $log;
                    }
                }
            }

            json_response(
                true,
                '送信履歴を取得しました。',
                ['logs' => $logs]
            );
        }

        if ($action === 'public_survey') {
            $id = scalar_string($_GET['id'] ?? '');
            $token = scalar_string($_GET['token'] ?? '');

            $data = load_surveys();
            $survey = find_survey($data, $id);

            if (
                $survey === null ||
                $token === '' ||
                !hash_equals(
                    (string)($survey['access_token'] ?? ''),
                    $token
                )
            ) {
                json_response(
                    false,
                    'アンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            if (!survey_is_open($survey)) {
                json_response(
                    false,
                    'このアンケートは現在回答を受け付けていません。',
                    [],
                    [],
                    403
                );
            }

            unset($survey['access_token']);

            json_response(
                true,
                'アンケートを取得しました。',
                ['survey' => $survey]
            );
        }

        json_response(
            false,
            '指定された処理はありません。',
            [],
            [],
            404
        );
    }

    if ($method === 'POST') {
        $input = read_json_request();

        if ($action === 'save_mail_settings') {
            $settings = load_settings();
            $mail = $input['mail'] ?? [];

            if (!is_array($mail)) {
                json_response(
                    false,
                    'メール設定を確認してください。',
                    [],
                    [],
                    400
                );
            }

            $server = scalar_string($mail['smtp_server'] ?? '');
            $port = (int)($mail['smtp_port'] ?? 0);
            $security = scalar_string(
                $mail['connection_type'] ?? 'STARTTLS'
            );
            $username = scalar_string($mail['username'] ?? '');
            $password = (string)($mail['password'] ?? '');
            $from = scalar_string($mail['from_email'] ?? '');
            $fromName = scalar_string($mail['from_name'] ?? '');

            $errors = [];

            if ($server === '') {
                $errors[] = 'SMTPサーバを入力してください。';
            }

            if ($port < 1 || $port > 65535) {
                $errors[] = 'ポート番号を確認してください。';
            }

            if (!in_array(
                $security,
                ['なし', 'STARTTLS', 'SSL/TLS'],
                true
            )) {
                $errors[] = '接続方式を確認してください。';
            }

            if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
                $errors[] =
                    '送信元メールアドレスを確認してください。';
            }

            if ($errors !== []) {
                json_response(
                    false,
                    '入力内容を確認してください。',
                    [],
                    $errors,
                    400
                );
            }

            if (
                $password === '' &&
                isset($settings['mail']['password'])
            ) {
                $password = (string)$settings['mail']['password'];
            }

            $settings['mail'] = [
                'smtp_server' => $server,
                'smtp_port' => $port,
                'connection_type' => $security,
                'username' => $username,
                'password' => $password,
                'from_email' => $from,
                'from_name' => $fromName,
                'configured' => true,
                'tested_at' => $settings['mail']['tested_at'] ?? null
            ];

            $settings['updated_at'] = now_iso();

            write_json_file(SETTINGS_FILE, $settings);

            json_response(
                true,
                'メール送信設定を保存しました。'
            );
        }

        if ($action === 'test_mail_settings') {
            $settings = load_settings();
            $mail = $settings['mail'] ?? [];

            $to = scalar_string($input['test_email'] ?? '');

            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                json_response(
                    false,
                    '確認用メールアドレスを入力してください。',
                    [],
                    [],
                    400
                );
            }

            $result = smtp_send_mail(
                $mail,
                $to,
                '',
                'アンケート業務運営：メール送信設定確認',
                "メール送信設定の確認です。\n\nこのメールを受信できれば、メール送信設定は正常です。"
            );

            if (!$result['success']) {
                json_response(
                    false,
                    'メール送信設定の確認に失敗しました。',
                    [],
                    [
                        'smtp' =>
                            (string)$result['message']
                    ],
                    400
                );
            }

            $settings['mail']['tested_at'] = now_iso();
            write_json_file(SETTINGS_FILE, $settings);

            json_response(
                true,
                '確認メールを送信しました。'
            );
        }

        if ($action === 'save_kintone_settings') {
            $settings = load_settings();
            $kt = $input['kintone'] ?? [];

            if (!is_array($kt)) {
                json_response(
                    false,
                    'kintone設定を確認してください。',
                    [],
                    [],
                    400
                );
            }

            $oldPassword =
                (string)($settings['kintone']['password'] ?? '');

            $password = (string)($kt['password'] ?? '');

            if ($password === '') {
                $password = $oldPassword;
            }

            $config = [
                'domain' => scalar_string($kt['domain'] ?? ''),
                'login_name' => scalar_string(
                    $kt['login_name'] ?? ''
                ),
                'password' => $password,
                'customer_app_id' => scalar_string(
                    $kt['customer_app_id'] ?? ''
                ),
                'customer_id_field' => scalar_string(
                    $kt['customer_id_field'] ?? '$id'
                ),
                'customer_name_field' => scalar_string(
                    $kt['customer_name_field'] ?? ''
                ),
                'customer_email_field' => scalar_string(
                    $kt['customer_email_field'] ?? ''
                ),
                'customer_company_field' => scalar_string(
                    $kt['customer_company_field'] ?? ''
                ),
                'proxy_host' => scalar_string(
                    $kt['proxy_host'] ?? ''
                ),
                'proxy_port' => scalar_string(
                    $kt['proxy_port'] ?? ''
                )
            ];

            $errors = validate_kintone_config($config);

            if ($errors !== []) {
                json_response(
                    false,
                    '入力内容を確認してください。',
                    [],
                    $errors,
                    400
                );
            }

            $settings['kintone'] = [
                'domain' => $config['domain'],
                'login_name' => $config['login_name'],
                'password' => $config['password'],
                'customer_app_id' => $config['customer_app_id'],
                'customer_id_field' =>
                    $config['customer_id_field'],
                'customer_name_field' =>
                    $config['customer_name_field'],
                'customer_email_field' =>
                    $config['customer_email_field'],
                'customer_company_field' =>
                    $config['customer_company_field'],
                'proxy_host' => $config['proxy_host'],
                'proxy_port' => $config['proxy_port'],
                'configured' => true,
                'connection_status' => 'configured',
                'tested_at' =>
                    $settings['kintone']['tested_at'] ?? null
            ];

            $settings['updated_at'] = now_iso();

            write_json_file(SETTINGS_FILE, $settings);

            json_response(
                true,
                'kintone設定を保存しました。'
            );
        }

        if ($action === 'test_kintone_connection') {
            $settings = load_settings();
            $config = kintone_config_from_settings($settings);

            $errors = validate_kintone_config($config);

            if ($errors !== []) {
                json_response(
                    false,
                    'kintone設定を確認してください。',
                    [],
                    $errors,
                    400
                );
            }

            try {
                $url = kintone_build_url(
                    $config['domain'],
                    '/k/v1/app.json'
                );

                $result = kintone_api_request(
                    'GET',
                    $url,
                    kintone_auth_headers($config),
                    [
                        'id' => $config['customer_app_id']
                    ],
                    $config
                );
            } catch (\Throwable $e) {
                json_response(
                    false,
                    'kintoneへの接続確認に失敗しました。',
                    [],
                    ['connection' => $e->getMessage()],
                    400
                );
            }

            if (!$result['success']) {
                $settings['kintone']['connection_status'] =
                    'error';

                write_json_file(
                    SETTINGS_FILE,
                    $settings
                );

                json_response(
                    false,
                    'kintoneへの接続確認に失敗しました。',
                    [],
                    [
                        'kintone' =>
                            (string)$result['message']
                    ],
                    400
                );
            }

            $settings['kintone']['connection_status'] =
                'connected';
            $settings['kintone']['tested_at'] = now_iso();

            write_json_file(
                SETTINGS_FILE,
                $settings
            );

            json_response(
                true,
                'kintoneへの接続を確認しました。',
                [
                    'app' =>
                        $result['data']['name'] ?? ''
                ]
            );
        }

        if ($action === 'refresh_customers') {
            $settings = load_settings();
            $config = kintone_config_from_settings($settings);

            $errors = validate_kintone_config($config);

            if ($errors !== []) {
                json_response(
                    false,
                    'kintone設定を確認してください。',
                    [],
                    $errors,
                    400
                );
            }

            try {
                $result = kintone_get_customers($config);
            } catch (\Throwable $e) {
                json_response(
                    false,
                    '顧客一覧を取得できませんでした。',
                    [],
                    ['kintone' => $e->getMessage()],
                    400
                );
            }

            if (!$result['success']) {
                json_response(
                    false,
                    '顧客一覧を取得できませんでした。',
                    [],
                    [
                        'kintone' =>
                            (string)$result['message']
                    ],
                    400
                );
            }

            $customers = $result['customers'] ?? [];

            if (!is_array($customers)) {
                $customers = [];
            }

            $data = [
                'version' => 2,
                'updated_at' => now_iso(),
                'source' => 'kintone',
                'count' => count($customers),
                'customers' => $customers
            ];

            write_json_file(
                CUSTOMERS_FILE,
                $data
            );

            $settings['kintone']['connection_status'] =
                'connected';
            $settings['kintone']['tested_at'] = now_iso();

            write_json_file(
                SETTINGS_FILE,
                $settings
            );

            json_response(
                true,
                '顧客一覧を更新しました。',
                [
                    'customers' => $customers,
                    'count' => count($customers)
                ]
            );
        }

        if ($action === 'save_survey') {
            $surveyInput = $input['survey'] ?? [];

            if (!is_array($surveyInput)) {
                json_response(
                    false,
                    'アンケート内容を確認してください。',
                    [],
                    [],
                    400
                );
            }

            $surveys = load_surveys();
            $survey = normalize_survey($surveyInput);

            $errors = validate_survey($survey);

            if ($errors !== []) {
                json_response(
                    false,
                    '入力内容を確認してください。',
                    [],
                    $errors,
                    400
                );
            }

            $survey['updated'] = today();

            $index = survey_index(
                $surveys,
                (string)$survey['id']
            );

            if ($index < 0) {
                $survey['created'] = today();
                $surveys['surveys'][] = $survey;
                $message = 'アンケートを保存しました。';
            } else {
                $existing =
                    $surveys['surveys'][$index] ?? [];

                if (is_array($existing)) {
                    $survey['created'] =
                        scalar_string(
                            $existing['created'] ?? today()
                        );

                    $survey['target'] =
                        max(
                            (int)($existing['target'] ?? 0),
                            (int)($survey['target'] ?? 0)
                        );

                    $survey['sent'] =
                        max(
                            (int)($existing['sent'] ?? 0),
                            (int)($survey['sent'] ?? 0)
                        );
                }

                $surveys['surveys'][$index] = $survey;
                $message = 'アンケートを更新しました。';
            }

            $surveys['updated_at'] = now_iso();

            write_json_file(
                SURVEYS_FILE,
                $surveys
            );

            json_response(
                true,
                $message,
                ['survey' => $survey]
            );
        }

        if ($action === 'delete_survey') {
            $id = scalar_string($input['id'] ?? '');

            $surveys = load_surveys();
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

            $survey = $surveys['surveys'][$index];

            if (($survey['status'] ?? '') !== 'draft') {
                json_response(
                    false,
                    '下書きのアンケートだけ削除できます。',
                    [],
                    [],
                    400
                );
            }

            array_splice($surveys['surveys'], $index, 1);
            $surveys['updated_at'] = now_iso();

            write_json_file(
                SURVEYS_FILE,
                $surveys
            );

            json_response(
                true,
                'アンケートを削除しました。'
            );
        }

        if (
            $action === 'publish_survey' ||
            $action === 'close_survey'
        ) {
            $id = scalar_string($input['id'] ?? '');

            $surveys = load_surveys();
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

            $survey = normalize_survey(
                $surveys['surveys'][$index]
            );

            if ($action === 'publish_survey') {
                $errors = validate_survey($survey);

                if ($errors !== []) {
                    json_response(
                        false,
                        '公開前に内容を確認してください。',
                        [],
                        $errors,
                        400
                    );
                }

                if (survey_question_count($survey) === 0) {
                    json_response(
                        false,
                        '質問を1つ以上設定してください。',
                        [],
                        [],
                        400
                    );
                }

                $survey['status'] = 'open';
                $message = 'アンケートを公開しました。';
            } else {
                if (($survey['status'] ?? '') !== 'open') {
                    json_response(
                        false,
                        '公開中のアンケートだけ終了できます。',
                        [],
                        [],
                        400
                    );
                }

                $survey['status'] = 'end';
                $message = 'アンケートを終了しました。';
            }

            $survey['updated'] = today();

            $surveys['surveys'][$index] = $survey;
            $surveys['updated_at'] = now_iso();

            write_json_file(
                SURVEYS_FILE,
                $surveys
            );

            json_response(
                true,
                $message,
                ['survey' => $survey]
            );
        }

        if ($action === 'send_survey') {
            $surveyId = scalar_string(
                $input['survey_id'] ?? ''
            );

            $customerIds = $input['customer_ids'] ?? [];

            $subject = scalar_string(
                $input['subject'] ?? ''
            );

            $body = (string)($input['body'] ?? '');

            if (
                !is_array($customerIds) ||
                $customerIds === []
            ) {
                json_response(
                    false,
                    '送信対象者を1名以上選択してください。',
                    [],
                    [],
                    400
                );
            }

            if ($subject === '') {
                json_response(
                    false,
                    '件名を入力してください。',
                    [],
                    [],
                    400
                );
            }

            if (trim($body) === '') {
                json_response(
                    false,
                    '本文を入力してください。',
                    [],
                    [],
                    400
                );
            }

            $surveys = load_surveys();
            $surveyIndex = survey_index(
                $surveys,
                $surveyId
            );

            if ($surveyIndex < 0) {
                json_response(
                    false,
                    'アンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            $survey = normalize_survey(
                $surveys['surveys'][$surveyIndex]
            );

            if (!survey_is_open($survey)) {
                json_response(
                    false,
                    '公開中のアンケートだけ送信できます。',
                    [],
                    [],
                    400
                );
            }

            $settings = load_settings();
            $mail = $settings['mail'] ?? [];

            if (empty($mail['configured'])) {
                json_response(
                    false,
                    'メール送信設定を完了してください。',
                    [],
                    [],
                    400
                );
            }

            $customerData = load_customers();
            $customers = $customerData['customers'] ?? [];

            if (!is_array($customers)) {
                $customers = [];
            }

            $idMap = [];

            foreach ($customers as $customer) {
                if (!is_array($customer)) {
                    continue;
                }

                $idMap[(string)($customer['id'] ?? '')] =
                    $customer;
            }

            $logsData = load_mail_logs();

            $successCount = 0;
            $failedCount = 0;
            $failedRecipients = [];

            $surveyUrl = public_survey_url($survey);

            $bodyTemplate = $body;

            if ($surveyUrl !== '') {
                $bodyTemplate .=
                    "\n\n回答はこちら\n" .
                    $surveyUrl;
            }

            foreach ($customerIds as $customerId) {
                $customerId = scalar_string($customerId);

                if (!isset($idMap[$customerId])) {
                    $failedCount++;
                    $failedRecipients[] = [
                        'id' => $customerId,
                        'name' => '',
                        'email' => '',
                        'reason' => '顧客情報が見つかりません。'
                    ];
                    continue;
                }

                $customer = $idMap[$customerId];

                $to = scalar_string(
                    $customer['email'] ?? ''
                );

                $name = scalar_string(
                    $customer['name'] ?? ''
                );

                $result = smtp_send_mail(
                    $mail,
                    $to,
                    $name,
                    $subject,
                    $bodyTemplate
                );

                $log = [
                    'id' => new_id(),
                    'survey_id' => $surveyId,
                    'customer_id' => $customerId,
                    'customer_name' => $name,
                    'email' => $to,
                    'subject' => $subject,
                    'status' => $result['success']
                        ? 'success'
                        : 'failed',
                    'message' => (string)$result['message'],
                    'sent_at' => now_iso()
                ];

                $logsData['logs'][] = $log;

                if ($result['success']) {
                    $successCount++;
                } else {
                    $failedCount++;

                    $failedRecipients[] = [
                        'id' => $customerId,
                        'name' => $name,
                        'email' => $to,
                        'reason' =>
                            (string)$result['message']
                    ];
                }
            }

            $logsData['updated_at'] = now_iso();

            write_json_file(
                MAIL_LOGS_FILE,
                $logsData
            );

            $survey['target'] = max(
                (int)($survey['target'] ?? 0),
                count($customerIds)
            );

            $survey['sent'] =
                (int)($survey['sent'] ?? 0) +
                $successCount;

            $survey['updated'] = today();

            $surveys['surveys'][$surveyIndex] = $survey;
            $surveys['updated_at'] = now_iso();

            write_json_file(
                SURVEYS_FILE,
                $surveys
            );

            json_response(
                true,
                $failedCount > 0
                    ? 'メール送信が完了しました。一部送信できない宛先があります。'
                    : 'メールを送信しました。',
                [
                    'target_count' => count($customerIds),
                    'success_count' => $successCount,
                    'failed_count' => $failedCount,
                    'failed_recipients' =>
                        $failedRecipients
                ]
            );
        }

        if ($action === 'save_response') {
            $surveyId = scalar_string(
                $input['survey_id'] ?? ''
            );

            $token = scalar_string(
                $input['token'] ?? ''
            );

            $respondentKey = scalar_string(
                $input['respondent_key'] ?? ''
            );

            if ($respondentKey === '') {
                $respondentKey = new_id();
            }

            $answers = $input['answers'] ?? [];

            if (!is_array($answers)) {
                json_response(
                    false,
                    '回答内容を確認してください。',
                    [],
                    [],
                    400
                );
            }

            $surveys = load_surveys();
            $survey = find_survey(
                $surveys,
                $surveyId
            );

            if (
                $survey === null ||
                $token === '' ||
                !hash_equals(
                    (string)($survey['access_token'] ?? ''),
                    $token
                )
            ) {
                json_response(
                    false,
                    'アンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            if (!survey_is_open($survey)) {
                json_response(
                    false,
                    'このアンケートは現在回答を受け付けていません。',
                    [],
                    [],
                    403
                );
            }

            $responseData = load_responses();

            foreach (($responseData['responses'] ?? []) as $existing) {
                if (
                    is_array($existing) &&
                    (string)($existing['survey_id'] ?? '') ===
                        $surveyId &&
                    (string)($existing['respondent_key'] ?? '') ===
                        $respondentKey
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

            $validationErrors = [];

            foreach (($survey['groups'] ?? []) as $group) {
                if (!is_array($group)) {
                    continue;
                }

                foreach (($group['questions'] ?? []) as $question) {
                    if (!is_array($question)) {
                        continue;
                    }

                    $qid = (string)($question['id'] ?? '');

                    if ($qid === '') {
                        continue;
                    }

                    $required =
                        !empty($question['required']);

                    if (!$required) {
                        continue;
                    }

                    $answer = $answers[$qid] ?? null;

                    $empty =
                        $answer === null ||
                        $answer === '' ||
                        $answer === [];

                    if ($empty) {
                        $validationErrors[] =
                            '「' .
                            (string)($question['text'] ?? '') .
                            '」は必須です。';
                    }
                }
            }

            if ($validationErrors !== []) {
                json_response(
                    false,
                    '未回答の必須質問があります。',
                    [],
                    $validationErrors,
                    400
                );
            }

            $cleanAnswers = [];

            foreach (($survey['groups'] ?? []) as $group) {
                if (!is_array($group)) {
                    continue;
                }

                foreach (($group['questions'] ?? []) as $question) {
                    if (!is_array($question)) {
                        continue;
                    }

                    $qid = (string)($question['id'] ?? '');

                    if ($qid === '' || !array_key_exists($qid, $answers)) {
                        continue;
                    }

                    $type = (string)($question['type'] ?? 'free');
                    $answer = $answers[$qid];

                    if ($type === 'multiple') {
                        if (!is_array($answer)) {
                            $answer = [$answer];
                        }

                        $allowed = [];

                        foreach (($question['options'] ?? []) as $option) {
                            if (is_array($option)) {
                                $allowed[] =
                                    (string)($option['id'] ?? '');
                            }
                        }

                        $selected = [];

                        foreach ($answer as $value) {
                            if (!is_scalar($value)) {
                                continue;
                            }

                            $value = (string)$value;

                            if (
                                in_array(
                                    $value,
                                    $allowed,
                                    true
                                )
                            ) {
                                $selected[] = $value;
                            }
                        }

                        $cleanAnswers[$qid] =
                            array_values(
                                array_unique($selected)
                            );
                    } elseif ($type === 'single') {
                        $value = is_scalar($answer)
                            ? (string)$answer
                            : '';

                        $allowed = [];

                        foreach (($question['options'] ?? []) as $option) {
                            if (is_array($option)) {
                                $allowed[] =
                                    (string)($option['id'] ?? '');
                            }
                        }

                        if (
                            $value !== '' &&
                            in_array(
                                $value,
                                $allowed,
                                true
                            )
                        ) {
                            $cleanAnswers[$qid] = $value;
                        }
                    } else {
                        $cleanAnswers[$qid] =
                            is_scalar($answer)
                                ? trim((string)$answer)
                                : '';
                    }
                }
            }

            $responseData['responses'][] = [
                'id' => new_id(),
                'survey_id' => $surveyId,
                'respondent_key' => $respondentKey,
                'submitted_at' => now_iso(),
                'answers' => $cleanAnswers
            ];

            $responseData['updated_at'] = now_iso();

            write_json_file(
                RESPONSES_FILE,
                $responseData
            );

            json_response(
                true,
                '回答を送信しました。',
                ['respondent_key' => $respondentKey]
            );
        }

        json_response(
            false,
            '指定された処理はありません。',
            [],
            [],
            404
        );
    }

    json_response(
        false,
        'この処理は利用できません。',
        [],
        [],
        405
    );
}

/* =========================================================
 * 回答者画面
 * ========================================================= */

$respondMode = isset($_GET['respond']) &&
    (string)$_GET['respond'] === '1';

$respondSurveyId = scalar_string(
    $_GET['survey'] ?? ''
);

$respondToken = scalar_string(
    $_GET['token'] ?? ''
);

if ($respondMode) {
    $respondSurveys = load_surveys();

    $respondSurvey = find_survey(
        $respondSurveys,
        $respondSurveyId
    );

    $respondValid =
        $respondSurvey !== null &&
        $respondToken !== '' &&
        hash_equals(
            (string)($respondSurvey['access_token'] ?? ''),
            $respondToken
        );

    if (
        $respondValid &&
        !survey_is_open($respondSurvey)
    ) {
        $respondValid = false;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<title><?= $respondMode ? 'アンケート回答' : 'アンケート業務運営' ?></title>

<style>
*{box-sizing:border-box}
html,body{margin:0;padding:0;min-height:100%}
body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
    "Yu Gothic","YuGothic",Meiryo,sans-serif;
    color:#263238;
    background:#f4f6f8;
    font-size:14px;
    line-height:1.6
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
button:disabled{cursor:not-allowed;opacity:.55}
input,textarea,select{max-width:100%}
.hidden{display:none!important}
.topbar{
    min-height:60px;
    background:#1f3a5f;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
    gap:30px
}
.logo{font-size:18px;font-weight:bold;white-space:nowrap}
.main-nav{display:flex;height:60px;align-items:stretch;gap:2px}
.main-nav button{
    height:60px;
    padding:0 17px;
    color:#dce7f3;
    background:transparent;
    border:0;
    white-space:nowrap
}
.main-nav button:hover,.main-nav button.active{
    background:#31557f;color:#fff
}
.app{max-width:1440px;margin:0 auto;padding:24px}
.page{width:100%}
.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:20px
}
.page-header h1{margin:0;font-size:25px;line-height:1.35}
.subtext{color:#718096;font-size:13px;margin-top:5px}
.btn{
    border:1px solid #cbd5e0;
    background:#fff;
    color:#34495e;
    border-radius:5px;
    padding:8px 15px;
    min-height:38px
}
.btn:hover{background:#f7fafc}
.btn-primary{background:#2878c8;border-color:#2878c8;color:#fff}
.btn-primary:hover{background:#2068ad;border-color:#2068ad}
.btn-success{background:#2f855a;border-color:#2f855a;color:#fff}
.btn-danger{border-color:#e05a5a;color:#c53f3f;background:#fff}
.btn-small{min-height:32px;padding:5px 10px;font-size:12px}
.link-button{
    border:0;background:none;padding:0;color:#2878c8;
    cursor:pointer;text-align:left
}
.card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:20px;
    margin-bottom:18px
}
.card-title{font-size:17px;font-weight:bold;margin-bottom:15px}
.notice{
    background:#eef6ff;
    border:1px solid #c9e1f8;
    color:#315a7d;
    border-radius:6px;
    padding:12px 14px;
    margin-bottom:18px
}
.notice.success{background:#eefaf3;border-color:#bde3ca;color:#276749}
.notice.warning{background:#fff8e8;border-color:#f1d99b;color:#856404}
.notice.error{background:#fff5f5;border-color:#f0c2c2;color:#a33a3a}
.table-wrap{width:100%;overflow-x:auto}
.table{width:100%;border-collapse:collapse}
.table th,.table td{
    padding:12px 10px;
    border-bottom:1px solid #e6ebef;
    text-align:left;
    vertical-align:middle;
    font-size:13px
}
.table th{background:#f8fafc;color:#52606d;font-weight:bold}
.table tbody tr:hover td{background:#fbfdff}
.empty{text-align:center!important;color:#8a98a5;padding:40px!important}
.badge{
    display:inline-block;
    padding:3px 8px;
    border-radius:12px;
    font-size:11px;
    white-space:nowrap
}
.badge-draft{color:#5f6b76;background:#edf0f2}
.badge-open{color:#276749;background:#dff5e7}
.badge-closed{color:#7b3f3f;background:#f6dddd}
.badge-warn{color:#856404;background:#fff0c7}
.form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:0 18px
}
.field{margin-bottom:15px}
.field label{
    display:block;
    font-weight:bold;
    font-size:13px;
    margin-bottom:6px
}
.field input,.field textarea,.field select,
.customer-toolbar input,.send-panel textarea{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:9px 10px;
    background:#fff
}
.field textarea{min-height:90px;resize:vertical}
.radio-row{display:flex;gap:20px;flex-wrap:wrap}
.radio-row label{font-weight:normal}
.editor-toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    margin-top:18px
}
.editor-actions{display:flex;gap:8px}
.group-card{
    border:1px solid #dfe5eb;
    border-radius:6px;
    margin-bottom:15px;
    background:#fff
}
.group-head{
    padding:12px 14px;
    background:#f8fafc;
    border-bottom:1px solid #e6ebef;
    display:flex;
    gap:10px;
    align-items:center
}
.drag-handle{cursor:grab;color:#718096}
.group-name{flex:1}
.group-name input{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:7px
}
.question-card{
    padding:15px;
    border-bottom:1px solid #e6ebef;
    background:#fff
}
.question-card:last-child{border-bottom:0}
.question-head{display:flex;align-items:center;gap:8px}
.question-number{
    width:58px;
    color:#2878c8;
    font-weight:bold;
    flex:none
}
.question-title{flex:1}
.question-title input{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:8px
}
.question-tools{display:flex;gap:6px;align-items:center}
.question-tools select{
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:6px
}
.question-meta{
    display:flex;
    gap:18px;
    align-items:center;
    margin-top:10px;
    padding-left:66px;
    color:#657786;
    font-size:13px
}
.question-options{margin-top:12px;padding-left:66px}
.option-row{
    display:flex;
    align-items:center;
    gap:7px;
    margin-bottom:7px
}
.option-row input{
    flex:1;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:7px
}
.branch-select{width:245px!important;flex:none}
.branch-label{font-size:11px;color:#718096;width:50px;flex:none}
.invalid-branch{border-color:#d9534f!important;background:#fff5f5!important}
.add-question-area{
    padding:12px 14px;
    background:#fafcfd;
    border-top:1px solid #edf1f4
}
.add-group-area{text-align:center;margin-top:8px}
.detail-tabs{
    display:flex;
    gap:2px;
    border-bottom:1px solid #cbd5e0;
    margin-bottom:18px
}
.detail-tabs button{
    border:1px solid transparent;
    border-bottom:0;
    background:transparent;
    padding:10px 16px;
    color:#52606d
}
.detail-tabs button.active{
    background:#fff;
    border-color:#cbd5e0;
    border-radius:5px 5px 0 0;
    color:#2878c8;
    font-weight:bold
}
.detail-summary{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px;
    margin-bottom:18px
}
.stat-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:16px
}
.stat-label{color:#718096;font-size:12px}
.stat-value{font-size:27px;font-weight:bold;color:#263238}
.stat-note{font-size:11px;color:#8a98a5}
.progress{
    width:100%;
    height:10px;
    background:#edf1f4;
    border-radius:6px;
    overflow:hidden
}
.progress span{display:block;height:100%;background:#2878c8}
.bar{
    width:100%;
    height:18px;
    background:#edf1f4;
    border-radius:4px;
    overflow:hidden
}
.bar span{display:block;height:100%;background:#2878c8}
.result-layout{
    display:grid;
    grid-template-columns:260px 1fr;
    gap:18px
}
.result-question-list{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    overflow:hidden
}
.result-question-button{
    display:block;
    width:100%;
    text-align:left;
    border:0;
    border-bottom:1px solid #edf1f4;
    background:#fff;
    padding:12px;
    color:#34495e
}
.result-question-button:hover,
.result-question-button.active{
    background:#eef6ff;
    color:#2878c8
}
.result-content{
    min-width:0;
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:20px
}
.result-answer{
    padding:10px;
    border:1px solid #e6ebef;
    background:#fafcfd;
    border-radius:4px;
    margin-bottom:7px
}
.send-layout{
    display:grid;
    grid-template-columns:1.1fr .9fr;
    gap:18px
}
.customer-toolbar{
    display:flex;
    gap:8px;
    margin-bottom:12px
}
.customer-toolbar input{flex:1}
.recipient-chip{
    display:inline-block;
    padding:5px 8px;
    background:#eef6ff;
    border:1px solid #c9e1f8;
    border-radius:12px;
    margin:2px;
    font-size:12px
}
.email-preview{
    white-space:pre-wrap;
    border:1px solid #e1e7ec;
    background:#fafbfc;
    border-radius:5px;
    padding:14px;
    min-height:150px
}
.settings-tabs{display:flex;gap:8px;margin-bottom:18px}
.settings-tabs button.active{
    background:#2878c8;
    color:#fff;
    border-color:#2878c8
}
.settings-status{
    display:inline-flex;
    align-items:center;
    gap:6px;
    font-size:12px;
    margin-bottom:12px
}
.status-dot{
    width:9px;
    height:9px;
    border-radius:50%;
    background:#9aa7b3
}
.status-dot.ready{background:#2f855a}
.status-dot.error{background:#d9534f}
.status-dot.warning{background:#d69e2e}
.toast-container{
    position:fixed;
    top:76px;
    right:20px;
    z-index:1000;
    display:flex;
    flex-direction:column;
    gap:8px;
    width:min(380px,calc(100vw - 40px))
}
.toast{
    background:#263238;
    color:#fff;
    border-radius:6px;
    padding:11px 14px;
    box-shadow:0 5px 18px rgba(0,0,0,.16)
}
.toast.success{background:#2f855a}
.toast.error{background:#c53f3f}
.modal-backdrop{
    position:fixed;
    inset:0;
    z-index:900;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:20px;
    background:rgba(25,35,45,.48)
}
.modal{
    width:min(760px,100%);
    max-height:calc(100vh - 40px);
    overflow-y:auto;
    background:#fff;
    border-radius:8px;
    box-shadow:0 12px 35px rgba(0,0,0,.25)
}
.modal-header{
    padding:17px 20px;
    border-bottom:1px solid #e6ebef;
    font-weight:bold
}
.modal-body{padding:20px}
.modal-footer{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    padding:13px 20px;
    border-top:1px solid #e6ebef
}
.loading-spinner{
    display:inline-block;
    width:14px;
    height:14px;
    margin-right:6px;
    border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff;
    border-radius:50%;
    animation:newapp-spin .7s linear infinite;
    vertical-align:-2px
}
.btn:not(.loading) .loading-spinner{display:none}
@keyframes newapp-spin{to{transform:rotate(360deg)}}
.respondent-page{
    max-width:820px;
    margin:0 auto;
    padding:30px 20px 60px
}
.respondent-header,
.respondent-question,
.respondent-complete{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:22px;
    margin-bottom:18px
}
.respondent-question-title{font-weight:bold;margin-bottom:13px}
.respondent-option{margin-bottom:8px}
.respondent-option label{
    display:flex;
    align-items:flex-start;
    gap:8px
}
.respondent-complete{text-align:center}
.answer-review{
    background:#fafcfd;
    border:1px solid #e6ebef;
    padding:14px;
    border-radius:5px;
    margin-bottom:10px
}
@media(max-width:980px){
    .topbar{gap:14px;padding:0 14px;overflow-x:auto}
    .main-nav button{padding:0 11px}
    .app{padding:18px}
    .send-layout,.result-layout{grid-template-columns:1fr}
    .detail-summary{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:680px){
    .topbar{
        min-height:auto;
        align-items:flex-start;
        flex-direction:column;
        gap:0;
        padding:10px 12px 0
    }
    .main-nav{
        width:100%;
        height:auto;
        overflow-x:auto
    }
    .main-nav button{
        height:44px;
        flex:0 0 auto
    }
    .page-header{
        align-items:flex-start;
        flex-direction:column
    }
    .form-grid{grid-template-columns:1fr}
    .question-head{flex-wrap:wrap}
    .question-title{
        order:2;
        flex-basis:100%
    }
    .question-meta,.question-options{padding-left:0}
    .option-row{flex-wrap:wrap}
    .branch-select{width:100%!important}
    .detail-summary{grid-template-columns:1fr 1fr}
}
</style>
</head>

<?php if ($respondMode): ?>

<body>

<div class="respondent-page">

<?php if (!$respondValid): ?>

    <div class="respondent-complete">
        <h1>アンケートを表示できません</h1>
        <p>
            このアンケートは存在しないか、
            現在回答を受け付けていません。
        </p>
    </div>

<?php else: ?>

    <header class="respondent-header">
        <h1><?= h((string)$respondSurvey['name']) ?></h1>

        <?php if (
            trim((string)($respondSurvey['description'] ?? '')) !== ''
        ): ?>
            <p><?= nl2br(h((string)$respondSurvey['description'])) ?></p>
        <?php endif; ?>

        <p class="subtext">
            回答内容をご確認のうえ、最後に送信してください。
        </p>
    </header>

    <div id="respondent-app"></div>

<?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const respondentSurvey = <?= json_encode(
        $respondValid ? $respondSurvey : null,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

    const respondentToken = <?= json_encode(
        $respondToken,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) ?>;

    const apiPath = <?= json_encode(
        application_api_path(),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) ?>;

    const respondentRoot =
        document.getElementById('respondent-app');

    if (!respondentRoot || !respondentSurvey) {
        return;
    }

    let answers = {};
    let respondentKey =
        window.sessionStorage.getItem(
            'newapp_respondent_key_' +
            String(respondentSurvey.id)
        ) || '';

    let submitted = false;

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function getQuestionList() {
        const list = [];

        (respondentSurvey.groups || []).forEach(
            function (group, gi) {
                (group.questions || []).forEach(
                    function (question, qi) {
                        list.push({
                            group: group,
                            question: question,
                            groupIndex: gi,
                            questionIndex: qi
                        });
                    }
                );
            }
        );

        return list;
    }

    function questionNumber(item) {
        if (respondentSurvey.numbering === 'group') {
            return 'Q' +
                (item.groupIndex + 1) +
                '-' +
                (item.questionIndex + 1);
        }

        const list = getQuestionList();

        const index = list.findIndex(
            function (entry) {
                return entry.question.id ===
                    item.question.id;
            }
        );

        return 'Q' + (index + 1);
    }

    function isAnswered(question) {
        const value = answers[question.id];

        if (question.type === 'multiple') {
            return Array.isArray(value) && value.length > 0;
        }

        return value !== undefined &&
            value !== null &&
            String(value).trim() !== '';
    }

    function visibleQuestions() {
        const list = getQuestionList();
        const visible = [];
        let allowed = true;

        for (let i = 0; i < list.length; i++) {
            const item = list[i];

            if (allowed) {
                visible.push(item);
            }

            if (item.question.type === 'single') {
                const selected = answers[item.question.id];

                if (selected) {
                    const option =
                        (item.question.options || []).find(
                            function (entry) {
                                return entry.id === selected;
                            }
                        );

                    if (
                        option &&
                        option.branch &&
                        option.branch !== 'END'
                    ) {
                        const targetIndex = list.findIndex(
                            function (entry) {
                                return entry.question.id ===
                                    option.branch;
                            }
                        );

                        if (targetIndex >= 0) {
                            allowed = false;

                            for (
                                let j = i + 1;
                                j <= targetIndex;
                                j++
                            ) {
                                visible.push(list[j]);
                            }

                            i = targetIndex;
                            allowed = true;
                        }
                    }

                    if (
                        option &&
                        option.branch === 'END'
                    ) {
                        break;
                    }
                }
            }
        }

        const unique = [];
        const ids = new Set();

        visible.forEach(
            function (item) {
                if (!ids.has(item.question.id)) {
                    ids.add(item.question.id);
                    unique.push(item);
                }
            }
        );

        return unique;
    }

    function render() {
        if (submitted) {
            respondentRoot.innerHTML =
                '<div class="respondent-complete">' +
                '<h1>回答ありがとうございました</h1>' +
                '<p>回答の送信が完了しました。</p>' +
                '</div>';
            return;
        }

        const list = visibleQuestions();
        let html = '';

        list.forEach(
            function (item) {
                const q = item.question;
                const no = questionNumber(item);
                const required = q.required
                    ? '<span style="color:#c53f3f"> *</span>'
                    : '';

                html +=
                    '<section class="respondent-question">' +
                    '<div class="respondent-question-title">' +
                    escapeHtml(no) +
                    '　' +
                    escapeHtml(q.text) +
                    required +
                    '</div>';

                if (q.type === 'free') {
                    html +=
                        '<textarea ' +
                        'data-answer="' +
                        escapeHtml(q.id) +
                        '" ' +
                        'style="width:100%;min-height:120px;' +
                        'border:1px solid #cbd5e0;' +
                        'border-radius:4px;padding:10px">' +
                        escapeHtml(
                            answers[q.id] || ''
                        ) +
                        '</textarea>';
                } else {
                    (q.options || []).forEach(
                        function (option) {
                            const checked =
                                q.type === 'single'
                                    ? answers[q.id] ===
                                        option.id
                                    : Array.isArray(
                                        answers[q.id]
                                    ) &&
                                      answers[q.id].includes(
                                          option.id
                                      );

                            html +=
                                '<div class="respondent-option">' +
                                '<label>' +
                                '<input type="' +
                                (
                                    q.type === 'single'
                                        ? 'radio'
                                        : 'checkbox'
                                ) +
                                '" ' +
                                'name="answer_' +
                                escapeHtml(q.id) +
                                '" ' +
                                'value="' +
                                escapeHtml(option.id) +
                                '" ' +
                                'data-question="' +
                                escapeHtml(q.id) +
                                '"' +
                                (
                                    checked
                                        ? ' checked'
                                        : ''
                                ) +
                                '>' +
                                '<span>' +
                                escapeHtml(option.text) +
                                '</span>' +
                                '</label>' +
                                '</div>';
                        }
                    );
                }

                html += '</section>';
            }
        );

        html +=
            '<div class="card">' +
            '<div class="notice">' +
            '送信前に回答内容を確認できます。' +
            '</div>' +
            '<button id="respond-review" ' +
            'class="btn btn-primary">' +
            '<span class="loading-spinner"></span>' +
            '回答内容を確認する' +
            '</button>' +
            '</div>';

        respondentRoot.innerHTML = html;

        const freeInputs =
            respondentRoot.querySelectorAll(
                'textarea[data-answer]'
            );

        freeInputs.forEach(
            function (element) {
                element.addEventListener(
                    'input',
                    function () {
                        const id =
                            element.getAttribute(
                                'data-answer'
                            );

                        if (id) {
                            answers[id] = element.value;
                        }
                    }
                );
            }
        );

        const choiceInputs =
            respondentRoot.querySelectorAll(
                'input[data-question]'
            );

        choiceInputs.forEach(
            function (element) {
                element.addEventListener(
                    'change',
                    function () {
                        const id =
                            element.getAttribute(
                                'data-question'
                            );

                        if (!id) {
                            return;
                        }

                        const question =
                            getQuestionList()
                                .map(
                                    function (x) {
                                        return x.question;
                                    }
                                )
                                .find(
                                    function (x) {
                                        return x.id === id;
                                    }
                                );

                        if (!question) {
                            return;
                        }

                        if (
                            question.type ===
                            'multiple'
                        ) {
                            const selected =
                                Array.from(
                                    respondentRoot
                                        .querySelectorAll(
                                            'input[data-question="' +
                                            CSS.escape(id) +
                                            '"]:checked'
                                        )
                                ).map(
                                    function (input) {
                                        return input.value;
                                    }
                                );

                            answers[id] = selected;
                        } else {
                            answers[id] =
                                element.value;
                        }

                        render();
                    }
                );
            }
        );

        const reviewButton =
            document.getElementById(
                'respond-review'
            );

        if (reviewButton) {
            reviewButton.addEventListener(
                'click',
                function () {
                    reviewButton.disabled = true;
                    reviewButton.classList.add(
                        'loading'
                    );

                    const list =
                        visibleQuestions();

                    const errors = [];

                    list.forEach(
                        function (item) {
                            if (
                                item.question.required &&
                                !isAnswered(
                                    item.question
                                )
                            ) {
                                errors.push(
                                    questionNumber(item) +
                                    '「' +
                                    item.question.text +
                                    '」'
                                );
                            }
                        }
                    );

                    if (errors.length > 0) {
                        alert(
                            '必須項目が未回答です。\n\n' +
                            errors.join('\n')
                        );

                        reviewButton.disabled = false;
                        reviewButton.classList.remove(
                            'loading'
                        );
                        return;
                    }

                    renderReview();
                }
            );
        }
    }

    function renderReview() {
        let html =
            '<div class="respondent-header">' +
            '<h2>回答内容の確認</h2>' +
            '<p>内容を確認して送信してください。</p>' +
            '</div>';

        visibleQuestions().forEach(
            function (item) {
                const q = item.question;
                const value = answers[q.id];

                let display = '';

                if (q.type === 'multiple') {
                    const values =
                        Array.isArray(value)
                            ? value
                            : [];

                    display = values.map(
                        function (id) {
                            const option =
                                (q.options || [])
                                    .find(
                                        function (o) {
                                            return o.id ===
                                                id;
                                        }
                                    );

                            return option
                                ? option.text
                                : id;
                        }
                    ).join('、');
                } else if (q.type === 'single') {
                    const option =
                        (q.options || []).find(
                            function (o) {
                                return o.id === value;
                            }
                        );

                    display = option
                        ? option.text
                        : '';
                } else {
                    display = value || '';
                }

                html +=
                    '<div class="answer-review">' +
                    '<strong>' +
                    escapeHtml(
                        questionNumber(item)
                    ) +
                    '　' +
                    escapeHtml(q.text) +
                    '</strong>' +
                    '<div style="margin-top:7px">' +
                    escapeHtml(display) +
                    '</div>' +
                    '</div>';
            }
        );

        html +=
            '<div class="card">' +
            '<button id="review-back" ' +
            'class="btn">回答画面へ戻る</button> ' +
            '<button id="review-submit" ' +
            'class="btn btn-primary">' +
            '<span class="loading-spinner"></span>' +
            '回答を送信する</button>' +
            '</div>';

        respondentRoot.innerHTML = html;

        const back =
            document.getElementById('review-back');

        if (back) {
            back.addEventListener(
                'click',
                function () {
                    render();
                }
            );
        }

        const submit =
            document.getElementById('review-submit');

        if (submit) {
            submit.addEventListener(
                'click',
                async function () {
                    submit.disabled = true;
                    submit.classList.add('loading');

                    try {
                        const response =
                            await fetch(
                                apiPath +
                                '?action=save_response',
                                {
                                    method:'POST',
                                    headers:{
                                        'Content-Type':
                                            'application/json',
                                        'X-CSRF-Token':
                                            document
                                                .querySelector(
                                                    'meta[name="csrf-token"]'
                                                )
                                                ?.getAttribute(
                                                    'content'
                                                ) || ''
                                    },
                                    body:JSON.stringify({
                                        survey_id:
                                            respondentSurvey.id,
                                        token:
                                            respondentToken,
                                        respondent_key:
                                            respondentKey,
                                        answers:answers
                                    })
                                }
                            );

                        const data =
                            await response.json();

                        if (!response.ok ||
                            !data.success) {
                            const details =
                                Array.isArray(
                                    data.errors
                                )
                                    ? '\n' +
                                      data.errors.join(
                                          '\n'
                                      )
                                    : '';

                            throw new Error(
                                (data.message ||
                                    '回答を送信できませんでした。') +
                                details
                            );
                        }

                        respondentKey =
                            data.data
                                ?.respondent_key ||
                            respondentKey;

                        if (respondentKey) {
                            window.sessionStorage.setItem(
                                'newapp_respondent_key_' +
                                String(
                                    respondentSurvey.id
                                ),
                                respondentKey
                            );
                        }

                        submitted = true;
                        render();
                    } catch (error) {
                        alert(
                            error instanceof Error
                                ? error.message
                                : '回答を送信できませんでした。'
                        );
                    } finally {
                        submit.disabled = false;
                        submit.classList.remove(
                            'loading'
                        );
                    }
                }
            );
        }
    }

    render();
});
</script>

</body>

<?php else: ?>

<body>

<header class="topbar">
    <div class="logo">アンケート業務運営</div>

    <nav class="main-nav">
        <button id="nav-list">アンケート一覧</button>
        <button id="nav-create">アンケート作成</button>
        <button id="nav-customers">顧客一覧</button>
        <button id="nav-settings">設定</button>
    </nav>
</header>

<main class="app">

<section id="page-list" class="page">
    <div class="page-header">
        <div>
            <h1>アンケート一覧</h1>
            <div class="subtext">
                作成済みのアンケートを管理します
            </div>
        </div>

        <button
            id="list-create"
            class="btn btn-primary">
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
                <tbody id="survey-list-body">
                <tr>
                    <td colspan="7" class="empty">
                        読み込み中です。
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section id="page-editor" class="page hidden">
    <div class="page-header">
        <div>
            <h1 id="editor-page-title">
                アンケート作成
            </h1>
            <div class="subtext">
                アンケート全体を1画面で編集できます
            </div>
        </div>
    </div>

    <div id="editor-notice"></div>

    <div class="card">
        <div class="form-grid">
            <div class="field">
                <label for="survey-name">
                    アンケート名 *
                </label>
                <input
                    id="survey-name"
                    type="text"
                    placeholder="例：新商品アンケート">
            </div>

            <div class="field">
                <label for="survey-status">
                    公開状態
                </label>
                <select id="survey-status">
                    <option value="draft">下書き</option>
                    <option value="open">公開中</option>
                    <option value="end">終了</option>
                </select>
            </div>
        </div>

        <div class="field">
            <label for="survey-description">
                説明
            </label>
            <textarea
                id="survey-description"
                placeholder="回答者への説明を入力してください"></textarea>
        </div>

        <div class="form-grid">
            <div class="field">
                <label for="survey-start">
                    公開開始日
                </label>
                <input
                    id="survey-start"
                    type="date">
            </div>

            <div class="field">
                <label for="survey-end">
                    公開終了日
                </label>
                <input
                    id="survey-end"
                    type="date">
            </div>
        </div>

        <div class="field">
            <label>質問番号</label>

            <div class="radio-row">
                <label>
                    <input
                        type="radio"
                        name="numbering"
                        value="global">
                    全体で通番（Q1、Q2、Q3…）
                </label>

                <label>
                    <input
                        type="radio"
                        name="numbering"
                        value="group">
                    グループごと
                    （Q1-1、Q1-2、Q2-1…）
                </label>
            </div>
        </div>
    </div>

    <div id="groups"></div>

    <div class="add-group-area">
        <button
            id="add-group"
            class="btn btn-primary">
            ＋ グループ追加
        </button>
    </div>

    <div class="editor-toolbar">
        <button
            id="editor-back"
            class="btn">
            一覧へ戻る
        </button>

        <div class="editor-actions">
            <button
                id="editor-preview"
                class="btn">
                内容確認
            </button>

            <button
                id="editor-save"
                class="btn btn-primary">
                <span class="loading-spinner"></span>
                保存
            </button>
        </div>
    </div>
</section>

<section id="page-detail" class="page hidden">

    <div class="page-header">
        <div>
            <h1 id="detail-title"></h1>
            <div
                id="detail-subtitle"
                class="subtext"></div>
        </div>

        <div>
            <button
                id="detail-edit"
                class="btn">
                編集
            </button>

            <button
                id="detail-send-top"
                class="btn btn-primary">
                送信
            </button>

            <button
                id="detail-back"
                class="btn">
                一覧へ戻る
            </button>
        </div>
    </div>

    <div class="detail-tabs">
        <button
            id="tab-content"
            data-tab="content">
            アンケート内容
        </button>

        <button
            id="tab-send"
            data-tab="send">
            送信
        </button>

        <button
            id="tab-status"
            data-tab="status">
            回答状況
        </button>

        <button
            id="tab-result"
            data-tab="result">
            回答結果
        </button>
    </div>

    <div id="detail-content"></div>
</section>

<section id="page-customers" class="page hidden">

    <div class="page-header">
        <div>
            <h1>顧客一覧</h1>
            <div class="subtext">
                kintoneの顧客管理アプリから取得した顧客です
            </div>
        </div>

        <button
            id="customers-settings"
            class="btn">
            kintone設定
        </button>
    </div>

    <div id="customer-status"></div>

    <div class="card">
        <div class="customer-toolbar">
            <input
                id="customer-search"
                placeholder="顧客名・メールアドレス・会社名で検索">

            <button
                id="customer-refresh"
                class="btn">
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

<section id="page-settings" class="page hidden">

    <div class="page-header">
        <div>
            <h1>設定</h1>
            <div class="subtext">
                メール送信とkintone接続を設定します
            </div>
        </div>
    </div>

    <div class="settings-tabs">
        <button
            id="settings-tab-mail"
            class="btn">
            メール送信設定
        </button>

        <button
            id="settings-tab-kintone"
            class="btn">
            kintone設定
        </button>
    </div>

    <div id="settings-content"></div>
</section>

</main>

<div
    id="modal"
    class="modal-backdrop hidden"
    role="dialog"
    aria-modal="true">

    <div class="modal">

        <div
            id="modal-title"
            class="modal-header"></div>

        <div
            id="modal-body"
            class="modal-body"></div>

        <div
            id="modal-footer"
            class="modal-footer"></div>

    </div>
</div>

<div
    id="toast-container"
    class="toast-container"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const apiPath = <?= json_encode(
        application_api_path(),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) ?>;

    const csrfToken =
        document
            .querySelector(
                'meta[name="csrf-token"]'
            )
            ?.getAttribute('content') || '';

    let surveys = [];
    let customers = [];
    let settings = null;

    let currentSurveyId = '';
    let editingSurvey = null;
    let currentDetailTab = 'content';
    let currentSettingsTab = 'mail';

    let dirty = false;
    let dragGroupId = '';
    let dragQuestion = null;

    const $ = function (id) {
        return document.getElementById(id);
    };

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent =
            value === null || value === undefined
                ? ''
                : String(value);
        return div.innerHTML;
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function showPage(id) {
        document
            .querySelectorAll('.page')
            .forEach(
                function (page) {
                    page.classList.add('hidden');
                }
            );

        const page = $(id);

        if (page) {
            page.classList.remove('hidden');
        }

        document
            .querySelectorAll('.main-nav button')
            .forEach(
                function (button) {
                    button.classList.remove('active');
                }
            );

        if (id === 'page-list' && $('nav-list')) {
            $('nav-list').classList.add('active');
        }

        if (id === 'page-customers' &&
            $('nav-customers')) {
            $('nav-customers')
                .classList.add('active');
        }

        if (id === 'page-settings' &&
            $('nav-settings')) {
            $('nav-settings')
                .classList.add('active');
        }
    }

    function toast(message, type) {
        const container =
            $('toast-container');

        if (!container) {
            return;
        }

        const element =
            document.createElement('div');

        element.className =
            'toast ' + (type || '');

        element.textContent = message;

        container.appendChild(element);

        window.setTimeout(
            function () {
                element.remove();
            },
            3000
        );
    }

    function openModal(title, bodyNodeOrHtml, footerHtml) {
        const modal = $('modal');
        const titleElement = $('modal-title');
        const bodyElement = $('modal-body');
        const footerElement = $('modal-footer');

        if (!modal ||
            !titleElement ||
            !bodyElement ||
            !footerElement) {
            return;
        }

        titleElement.textContent = title;

        if (typeof bodyNodeOrHtml === 'string') {
            bodyElement.innerHTML =
                bodyNodeOrHtml;
        } else {
            bodyElement.replaceChildren(
                bodyNodeOrHtml
            );
        }

        footerElement.innerHTML =
            footerHtml || '';

        modal.classList.remove('hidden');
    }

    function closeModal() {
        const modal = $('modal');

        if (modal) {
            modal.classList.add('hidden');
        }
    }

    async function apiGet(action, params) {
        const query =
            new URLSearchParams(
                Object.assign(
                    {action: action},
                    params || {}
                )
            );

        const response =
            await fetch(
                apiPath + '?' + query.toString(),
                {
                    method: 'GET',
                    headers: {
                        'Accept':
                            'application/json'
                    }
                }
            );

        const data =
            await response.json();

        if (!response.ok || !data.success) {
            throw new Error(
                data.message ||
                'データを取得できませんでした。'
            );
        }

        return data;
    }

    async function apiPost(
        action,
        payload,
        button
    ) {
        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }

        try {
            const response =
                await fetch(
                    apiPath +
                    '?action=' +
                    encodeURIComponent(action),
                    {
                        method:'POST',
                        headers:{
                            'Content-Type':
                                'application/json',
                            'X-CSRF-Token':
                                csrfToken,
                            'Accept':
                                'application/json'
                        },
                        body:JSON.stringify(
                            payload || {}
                        )
                    }
                );

            const data =
                await response.json();

            if (!response.ok || !data.success) {
                const errorList =
                    Array.isArray(data.errors)
                        ? '\n' +
                          data.errors.join('\n')
                        : '';

                throw new Error(
                    (data.message ||
                        '処理に失敗しました。') +
                    errorList
                );
            }

            return data;
        } finally {
            if (button) {
                button.disabled = false;
                button.classList.remove(
                    'loading'
                );
            }
        }
    }

    function statusBadge(status) {
        if (status === 'open') {
            return '<span class="badge badge-open">公開中</span>';
        }

        if (status === 'end') {
            return '<span class="badge badge-closed">終了</span>';
        }

        return '<span class="badge badge-draft">下書き</span>';
    }

    function formatPeriod(survey) {
        const start =
            survey.start || '未設定';

        const end =
            survey.end || '未設定';

        return escapeHtml(start) +
            ' ～ ' +
            escapeHtml(end);
    }

    function surveyQuestionCount(survey) {
        let count = 0;

        (survey.groups || []).forEach(
            function (group) {
                count +=
                    Array.isArray(group.questions)
                        ? group.questions.length
                        : 0;
            }
        );

        return count;
    }

    async function loadSurveys() {
        const data =
            await apiGet('load_surveys');

        surveys =
            Array.isArray(data.data?.surveys)
                ? data.data.surveys
                : [];

        renderSurveyList();
    }

    function renderSurveyList() {
        const body =
            $('survey-list-body');

        if (!body) {
            return;
        }

        body.replaceChildren();

        if (surveys.length === 0) {
            const tr =
                document.createElement('tr');

            const td =
                document.createElement('td');

            td.colSpan = 7;
            td.className = 'empty';
            td.textContent =
                '登録されているアンケートはありません。';

            tr.appendChild(td);
            body.appendChild(tr);
            return;
        }

        surveys.forEach(
            function (survey) {
                const tr =
                    document.createElement('tr');

                const name =
                    document.createElement('td');

                const open =
                    document.createElement('button');

                open.className =
                    'link-button';

                open.textContent =
                    survey.name || '名称未設定';

                open.addEventListener(
                    'click',
                    function () {
                        openDetail(
                            survey.id
                        );
                    }
                );

                name.appendChild(open);
                tr.appendChild(name);

                const status =
                    document.createElement('td');

                status.innerHTML =
                    statusBadge(
                        survey.status
                    );

                tr.appendChild(status);

                const created =
                    document.createElement('td');

                created.textContent =
                    survey.created || '';

                tr.appendChild(created);

                const period =
                    document.createElement('td');

                period.innerHTML =
                    formatPeriod(survey);

                tr.appendChild(period);

                const answers =
                    document.createElement('td');

                answers.textContent =
                    String(
                        survey.answers || 0
                    ) + '件';

                tr.appendChild(answers);

                const updated =
                    document.createElement('td');

                updated.textContent =
                    survey.updated || '';

                tr.appendChild(updated);

                const actions =
                    document.createElement('td');

                const edit =
                    document.createElement('button');

                edit.className =
                    'btn btn-small';

                edit.textContent = '編集';

                edit.addEventListener(
                    'click',
                    function () {
                        openEditor(
                            survey.id
                        );
                    }
                );

                actions.appendChild(edit);

                const openButton =
                    document.createElement('button');

                openButton.className =
                    'btn btn-small';

                openButton.textContent =
                    '開く';

                openButton.style.marginLeft =
                    '5px';

                openButton.addEventListener(
                    'click',
                    function () {
                        openDetail(
                            survey.id
                        );
                    }
                );

                actions.appendChild(
                    openButton
                );

                if (survey.status === 'draft') {
                    const publish =
                        document.createElement(
                            'button'
                        );

                    publish.className =
                        'btn btn-small btn-success';

                    publish.textContent =
                        '公開';

                    publish.style.marginLeft =
                        '5px';

                    publish.addEventListener(
                        'click',
                        function () {
                            publishSurvey(
                                survey.id,
                                publish
                            );
                        }
                    );

                    actions.appendChild(
                        publish
                    );

                    const del =
                        document.createElement(
                            'button'
                        );

                    del.className =
                        'btn btn-small btn-danger';

                    del.textContent =
                        '削除';

                    del.style.marginLeft =
                        '5px';

                    del.addEventListener(
                        'click',
                        function () {
                            deleteSurvey(
                                survey.id,
                                del
                            );
                        }
                    );

                    actions.appendChild
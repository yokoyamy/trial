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

function csrf_token(): string
{
    $token = $_SESSION[APP_SESSION_KEY]['csrf_token'] ?? '';
    return is_string($token) ? $token : '';
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

function application_api_path(): string
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? 'index.php';

    if (!is_string($scriptName) || $scriptName === '') {
        return 'index.php';
    }

    return $scriptName;
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
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/**
 * PHP 8.4 / 8.5対応
 * $http_response_header は直接参照しない。
 */
function get_safe_response_headers(): array
{
    return http_get_last_response_headers() ?? [];
}

function read_json_request(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    try {
        $decoded = json_decode(
            $raw,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (\JsonException $e) {
        json_response(
            false,
            '入力内容を確認してください。',
            [],
            ['request' => 'JSON形式のデータを指定してください。'],
            400
        );
    }

    if (!is_array($decoded)) {
        json_response(
            false,
            '入力内容を確認してください。',
            [],
            ['request' => 'JSON形式のデータを指定してください。'],
            400
        );
    }

    return $decoded;
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
            'customer_id_field' => '$id',
            'customer_name_field' => '',
            'customer_email_field' => '',
            'customer_extra_fields' => '',
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
    } catch (\JsonException $e) {
        json_response(
            false,
            '保存データの形式が正しくありません。',
            [],
            [],
            500
        );
    }

    if (!is_array($decoded)) {
        json_response(
            false,
            '保存データの形式が正しくありません。',
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
    } catch (\JsonException $e) {
        json_response(
            false,
            'データを保存できませんでした。',
            [],
            [],
            500
        );
    }

    $directory = dirname($file);
    $temporary = tempnam($directory, 'newapp_');

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

        $written = fwrite($handle, $json);

        if ($written === false || $written !== strlen($json)) {
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
        $verified = json_decode(
            $verify,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (\JsonException $e) {
        json_response(
            false,
            '保存データの確認に失敗しました。',
            [],
            [],
            500
        );
    }

    if (!is_array($verified)) {
        json_response(
            false,
            '保存データの確認に失敗しました。',
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
    return read_json_file(
        SETTINGS_FILE,
        default_settings()
    );
}

function save_settings(array $settings): void
{
    $settings['updated_at'] = current_datetime();
    write_json_file(SETTINGS_FILE, $settings);
}

function load_customer_data(): array
{
    return read_json_file(
        CUSTOMERS_FILE,
        default_customers()
    );
}

function save_customer_data(array $data): void
{
    $data['updated_at'] = current_datetime();
    $data['count'] = count($data['customers'] ?? []);
    write_json_file(CUSTOMERS_FILE, $data);
}

function load_survey_data(): array
{
    return read_json_file(
        SURVEYS_FILE,
        default_surveys()
    );
}

function save_survey_data(array $data): void
{
    $data['updated_at'] = current_datetime();
    write_json_file(SURVEYS_FILE, $data);
}

function load_response_data(): array
{
    return read_json_file(
        RESPONSES_FILE,
        default_responses()
    );
}

function save_response_data(array $data): void
{
    $data['updated_at'] = current_datetime();
    write_json_file(RESPONSES_FILE, $data);
}

function load_mail_log_data(): array
{
    return read_json_file(
        MAIL_LOGS_FILE,
        default_mail_logs()
    );
}

function save_mail_log_data(array $data): void
{
    $data['updated_at'] = current_datetime();
    write_json_file(MAIL_LOGS_FILE, $data);
}

function new_id(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(10));
}

function string_value(mixed $value): string
{
    return is_string($value) ? trim($value) : '';
}

function int_value(mixed $value, int $default = 0): int
{
    if (is_int($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int)$value;
    }

    return $default;
}

function bool_value(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_string($value)) {
        return in_array(
            strtolower(trim($value)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    return (bool)$value;
}

function survey_by_id(string $surveyId): ?array
{
    $data = load_survey_data();

    foreach ($data['surveys'] ?? [] as $survey) {
        if (
            is_array($survey) &&
            string_value($survey['id'] ?? '') === $surveyId
        ) {
            return $survey;
        }
    }

    return null;
}

function survey_index_by_id(array $surveys, string $surveyId): int
{
    foreach ($surveys as $index => $survey) {
        if (
            is_array($survey) &&
            string_value($survey['id'] ?? '') === $surveyId
        ) {
            return $index;
        }
    }

    return -1;
}

function response_list_for_survey(string $surveyId): array
{
    $data = load_response_data();
    $result = [];

    foreach ($data['responses'] ?? [] as $response) {
        if (
            is_array($response) &&
            string_value($response['survey_id'] ?? '') === $surveyId
        ) {
            $result[] = $response;
        }
    }

    return $result;
}

function mail_logs_for_survey(string $surveyId): array
{
    $data = load_mail_log_data();
    $result = [];

    foreach ($data['logs'] ?? [] as $log) {
        if (
            is_array($log) &&
            string_value($log['survey_id'] ?? '') === $surveyId
        ) {
            $result[] = $log;
        }
    }

    return $result;
}

function survey_question_map(array $survey): array
{
    $map = [];

    foreach ($survey['groups'] ?? [] as $group) {
        if (!is_array($group)) {
            continue;
        }

        foreach ($group['questions'] ?? [] as $question) {
            if (!is_array($question)) {
                continue;
            }

            $id = string_value($question['id'] ?? '');

            if ($id !== '') {
                $map[$id] = $question;
            }
        }
    }

    return $map;
}

function survey_question_order(array $survey): array
{
    $order = [];

    foreach ($survey['groups'] ?? [] as $group) {
        if (!is_array($group)) {
            continue;
        }

        foreach ($group['questions'] ?? [] as $question) {
            if (!is_array($question)) {
                continue;
            }

            $id = string_value($question['id'] ?? '');

            if ($id !== '') {
                $order[] = $id;
            }
        }
    }

    return $order;
}

function question_number_map(array $survey): array
{
    $map = [];
    $global = 0;
    $groupIndex = 0;

    foreach ($survey['groups'] ?? [] as $group) {
        $groupIndex++;
        $questionIndex = 0;

        if (!is_array($group)) {
            continue;
        }

        foreach ($group['questions'] ?? [] as $question) {
            if (!is_array($question)) {
                continue;
            }

            $questionIndex++;
            $global++;

            $id = string_value($question['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $numbering = string_value(
                $survey['numbering'] ?? 'global'
            );

            if ($numbering === 'group') {
                $map[$id] = 'Q' . $groupIndex . '-' . $questionIndex;
            } else {
                $map[$id] = 'Q' . $global;
            }
        }
    }

    return $map;
}

function status_label(string $status): string
{
    return match ($status) {
        'open' => '公開中',
        'closed' => '終了',
        default => '下書き'
    };
}

function answer_type_label(string $type): string
{
    return match ($type) {
        'single' => '単一選択',
        'multiple' => '複数選択',
        default => '自由記述'
    };
}

function validate_survey_payload(array $input): array
{
    $name = string_value($input['name'] ?? '');

    if ($name === '') {
        return ['アンケート名を入力してください。'];
    }

    if (mb_strlen($name, 'UTF-8') > 200) {
        return ['アンケート名は200文字以内で入力してください。'];
    }

    $groups = $input['groups'] ?? [];

    if (!is_array($groups)) {
        return ['グループの設定を確認してください。'];
    }

    $questionIds = [];
    $errors = [];

    foreach ($groups as $groupIndex => $group) {
        if (!is_array($group)) {
            $errors[] = 'グループの形式を確認してください。';
            continue;
        }

        $groupName = string_value($group['name'] ?? '');

        if ($groupName === '') {
            $errors[] =
                'グループ' . ((int)$groupIndex + 1) .
                'のグループ名を入力してください。';
        }

        $questions = $group['questions'] ?? [];

        if (!is_array($questions)) {
            $errors[] =
                'グループ「' . $groupName .
                '」の質問設定を確認してください。';
            continue;
        }

        foreach ($questions as $questionIndex => $question) {
            if (!is_array($question)) {
                $errors[] =
                    '質問設定を確認してください。';
                continue;
            }

            $questionId = string_value($question['id'] ?? '');
            $questionText = string_value($question['text'] ?? '');
            $type = string_value($question['type'] ?? 'text');

            if ($questionId === '') {
                $errors[] =
                    '質問IDがありません。画面を再読み込みしてください。';
            } elseif (isset($questionIds[$questionId])) {
                $errors[] =
                    '質問IDが重複しています。画面を再読み込みしてください。';
            } else {
                $questionIds[$questionId] = true;
            }

            if ($questionText === '') {
                $errors[] =
                    '「' . $groupName . '」の質問' .
                    ((int)$questionIndex + 1) .
                    'の質問文を入力してください。';
            }

            if (!in_array(
                $type,
                ['text', 'single', 'multiple'],
                true
            )) {
                $errors[] =
                    '質問「' . $questionText .
                    '」の回答形式が不正です。';
            }

            if (in_array($type, ['single', 'multiple'], true)) {
                $options = $question['options'] ?? [];

                if (!is_array($options) || count($options) === 0) {
                    $errors[] =
                        '質問「' . $questionText .
                        '」の選択肢を1つ以上設定してください。';
                } else {
                    $optionIds = [];

                    foreach ($options as $option) {
                        if (!is_array($option)) {
                            continue;
                        }

                        $optionId = string_value(
                            $option['id'] ?? ''
                        );
                        $optionText = string_value(
                            $option['text'] ?? ''
                        );

                        if ($optionId === '') {
                            $errors[] =
                                '質問「' . $questionText .
                                '」の選択肢IDがありません。';
                        } elseif (isset($optionIds[$optionId])) {
                            $errors[] =
                                '質問「' . $questionText .
                                '」の選択肢IDが重複しています。';
                        } else {
                            $optionIds[$optionId] = true;
                        }

                        if ($optionText === '') {
                            $errors[] =
                                '質問「' . $questionText .
                                '」に空の選択肢があります。';
                        }
                    }
                }
            }
        }
    }

    $allQuestionIds = array_keys($questionIds);

    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        foreach ($group['questions'] ?? [] as $question) {
            if (!is_array($question)) {
                continue;
            }

            if (
                string_value($question['type'] ?? '') !== 'single'
            ) {
                continue;
            }

            foreach ($question['options'] ?? [] as $option) {
                if (!is_array($option)) {
                    continue;
                }

                $branch = string_value($option['branch'] ?? '');

                if (
                    $branch !== '' &&
                    $branch !== 'next' &&
                    $branch !== 'end' &&
                    !in_array($branch, $allQuestionIds, true)
                ) {
                    $errors[] =
                        '分岐先の質問が存在しません。';
                }
            }
        }
    }

    return $errors;
}

function normalize_survey(array $input, ?array $existing = null): array
{
    $now = current_datetime();

    $surveyId = string_value($input['id'] ?? '');

    if ($surveyId === '') {
        $surveyId = string_value($existing['id'] ?? '');

        if ($surveyId === '') {
            $surveyId = new_id('survey');
        }
    }

    $createdAt = string_value(
        $existing['created_at'] ?? ''
    );

    if ($createdAt === '') {
        $createdAt = $now;
    }

    $status = string_value(
        $input['status'] ??
        $existing['status'] ??
        'draft'
    );

    if (!in_array(
        $status,
        ['draft', 'open', 'closed'],
        true
    )) {
        $status = 'draft';
    }

    $numbering = string_value(
        $input['numbering'] ??
        $existing['numbering'] ??
        'global'
    );

    if (!in_array(
        $numbering,
        ['global', 'group'],
        true
    )) {
        $numbering = 'global';
    }

    $groups = [];

    foreach ($input['groups'] ?? [] as $groupInput) {
        if (!is_array($groupInput)) {
            continue;
        }

        $groupId = string_value($groupInput['id'] ?? '');

        if ($groupId === '') {
            $groupId = new_id('group');
        }

        $questions = [];

        foreach ($groupInput['questions'] ?? [] as $questionInput) {
            if (!is_array($questionInput)) {
                continue;
            }

            $questionId = string_value(
                $questionInput['id'] ?? ''
            );

            if ($questionId === '') {
                $questionId = new_id('question');
            }

            $type = string_value(
                $questionInput['type'] ?? 'text'
            );

            if (!in_array(
                $type,
                ['text', 'single', 'multiple'],
                true
            )) {
                $type = 'text';
            }

            $options = [];

            if (
                $type === 'single' ||
                $type === 'multiple'
            ) {
                foreach ($questionInput['options'] ?? [] as $optionInput) {
                    if (!is_array($optionInput)) {
                        continue;
                    }

                    $optionId = string_value(
                        $optionInput['id'] ?? ''
                    );

                    if ($optionId === '') {
                        $optionId = new_id('option');
                    }

                    $branch = string_value(
                        $optionInput['branch'] ?? ''
                    );

                    if (
                        $type !== 'single' ||
                        (
                            $branch !== '' &&
                            $branch !== 'next' &&
                            $branch !== 'end'
                        )
                    ) {
                        if ($type !== 'single') {
                            $branch = '';
                        }
                    }

                    $options[] = [
                        'id' => $optionId,
                        'text' => string_value(
                            $optionInput['text'] ?? ''
                        ),
                        'branch' => $branch
                    ];
                }
            }

            $questions[] = [
                'id' => $questionId,
                'text' => string_value(
                    $questionInput['text'] ?? ''
                ),
                'type' => $type,
                'required' => bool_value(
                    $questionInput['required'] ?? false
                ),
                'options' => $options
            ];
        }

        $groups[] = [
            'id' => $groupId,
            'name' => string_value(
                $groupInput['name'] ?? ''
            ),
            'questions' => $questions
        ];
    }

    return [
        'id' => $surveyId,
        'name' => string_value($input['name'] ?? ''),
        'description' => string_value(
            $input['description'] ?? ''
        ),
        'status' => $status,
        'start_at' => string_value(
            $input['start_at'] ?? ''
        ),
        'end_at' => string_value(
            $input['end_at'] ?? ''
        ),
        'numbering' => $numbering,
        'created_at' => $createdAt,
        'updated_at' => $now,
        'groups' => $groups
    ];
}

/**
 * kintone URL整形
 */
function kintone_build_url(
    string $domain,
    string $endpoint
): string {
    $domain = trim($domain);

    $domain = preg_replace(
        '/^https?:\/\//i',
        '',
        $domain
    );

    $domain = preg_replace(
        '/\.cybozu\.com.*$/i',
        '',
        $domain
    );

    $domain = rtrim($domain, '/');

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' .
        $domain .
        '.cybozu.com' .
        $endpoint;
}

function make_cybozu_auth_header(
    string $loginName,
    string $password
): string {
    $loginName = trim($loginName);
    $password = trim($password);

    $authString = base64_encode(
        $loginName . ':' . $password
    );

    return 'X-Cybozu-Authorization: ' . $authString;
}

function normalize_proxy_host_port(
    string $host,
    string $port
): string {
    $host = trim($host);
    $port = trim($port);

    if ($host === '' && $port === '') {
        return '';
    }

    if ($host === '' || $port === '') {
        return '';
    }

    $host = preg_replace(
        '/^https?:\/\//i',
        '',
        $host
    );

    $host = trim($host, '/');

    if (
        !preg_match(
            '/^[A-Za-z0-9._-]+$/',
            $host
        )
    ) {
        return '';
    }

    if (
        !preg_match(
            '/^[0-9]{1,5}$/',
            $port
        )
    ) {
        return '';
    }

    $portNumber = (int)$port;

    if ($portNumber < 1 || $portNumber > 65535) {
        return '';
    }

    return $host . ':' . $portNumber;
}

/**
 * kintone REST API
 */
function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload = null,
    array $config = []
): array {
    $method = strtoupper($method);

    $httpOptions = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20
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
                $url .=
                    (str_contains($url, '?') ? '&' : '?') .
                    $query;
            }
        }
    } elseif ($payload !== null) {
        try {
            $encodedPayload = is_array($payload)
                ? json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES |
                    JSON_THROW_ON_ERROR
                )
                : (string)$payload;
        } catch (\JsonException $e) {
            return [
                'success' => false,
                'status' => 0,
                'message' => '送信データを作成できませんでした。',
                'raw' => []
            ];
        }

        $httpOptions['content'] = $encodedPayload;
    }

    $contextOptions = [
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];

    $proxyHostPort = string_value(
        $config['proxy_host_port'] ?? ''
    );

    if ($proxyHostPort !== '') {
        $proxyAddress = 'tcp://' . $proxyHostPort;

        $contextOptions['http']['proxy'] =
            $proxyAddress;

        $contextOptions['http']['request_fulluri'] =
            true;
    }

    $context = stream_context_create(
        $contextOptions
    );

    $responseBody = @file_get_contents(
        $url,
        false,
        $context
    );

    $responseHeaders =
        get_safe_response_headers();

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

    $responseData = [];

    if (
        is_string($responseBody) &&
        trim($responseBody) !== ''
    ) {
        $decoded = json_decode(
            $responseBody,
            true
        );

        if (is_array($decoded)) {
            $responseData = $decoded;
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
            'data' => $responseData
        ];
    }

    $message = string_value(
        $responseData['message'] ?? ''
    );

    if ($message === '') {
        $message =
            'kintoneとの通信に失敗しました。';
    }

    $details = [];

    if (
        isset($responseData['code']) &&
        is_string($responseData['code'])
    ) {
        $details[] =
            'コード: ' .
            $responseData['code'];
    }

    if (
        isset($responseData['errors']) &&
        is_array($responseData['errors'])
    ) {
        foreach (
            $responseData['errors']
            as $key => $error
        ) {
            if (!is_array($error)) {
                continue;
            }

            $messages =
                $error['messages'] ?? [];

            if (is_array($messages)) {
                foreach ($messages as $errorMessage) {
                    if (is_string($errorMessage)) {
                        $details[] =
                            (string)$key .
                            ': ' .
                            $errorMessage;
                    }
                }
            }
        }
    }

    if ($details !== []) {
        $message .=
            ' [' .
            implode(' / ', $details) .
            ']';
    }

    return [
        'success' => false,
        'status' => $statusCode,
        'message' => $message,
        'raw' => $responseData
    ];
}

function kintone_settings_valid(array $settings): array
{
    $kintone = $settings['kintone'] ?? [];

    if (!is_array($kintone)) {
        return ['kintone' => 'kintone設定を確認してください。'];
    }

    $errors = [];

    if (
        string_value($kintone['domain'] ?? '') === ''
    ) {
        $errors['domain'] =
            'kintoneの利用先を入力してください。';
    }

    if (
        string_value($kintone['login_name'] ?? '') === ''
    ) {
        $errors['login_name'] =
            'ログイン名を入力してください。';
    }

    if (
        string_value($kintone['password'] ?? '') === ''
    ) {
        $errors['password'] =
            'パスワードを入力してください。';
    }

    $appId = string_value(
        $kintone['customer_app_id'] ?? ''
    );

    if (
        $appId === '' ||
        !preg_match('/^[0-9]+$/', $appId)
    ) {
        $errors['customer_app_id'] =
            '顧客管理アプリIDを入力してください。';
    }

    $proxyHost = string_value(
        $kintone['proxy_host'] ?? ''
    );

    $proxyPort = string_value(
        $kintone['proxy_port'] ?? ''
    );

    if (
        ($proxyHost !== '' && $proxyPort === '') ||
        ($proxyHost === '' && $proxyPort !== '')
    ) {
        $errors['proxy'] =
            'プロキシを使用する場合はホスト名とポート番号を両方入力してください。';
    }

    if (
        $proxyHost !== '' ||
        $proxyPort !== ''
    ) {
        if (
            normalize_proxy_host_port(
                $proxyHost,
                $proxyPort
            ) === ''
        ) {
            $errors['proxy'] =
                'プロキシはホスト名とポート番号で正しく入力してください。';
        }
    }

    return $errors;
}

function test_kintone_connection(
    array $kintone
): array {
    $domain = string_value(
        $kintone['domain'] ?? ''
    );
    $loginName = string_value(
        $kintone['login_name'] ?? ''
    );
    $password = string_value(
        $kintone['password'] ?? ''
    );
    $appId = string_value(
        $kintone['customer_app_id'] ?? ''
    );

    $proxyHost = string_value(
        $kintone['proxy_host'] ?? ''
    );
    $proxyPort = string_value(
        $kintone['proxy_port'] ?? ''
    );

    $proxyHostPort =
        normalize_proxy_host_port(
            $proxyHost,
            $proxyPort
        );

    $headers = [
        make_cybozu_auth_header(
            $loginName,
            $password
        ),
        'Accept: application/json'
    ];

    $url = kintone_build_url(
        $domain,
        '/k/v1/app.json'
    );

    $result = kintone_api_request(
        'GET',
        $url,
        $headers,
        ['id' => $appId],
        [
            'proxy_host_port' => $proxyHostPort
        ]
    );

    return $result;
}

function fetch_kintone_customers(
    array $kintone
): array {
    $domain = string_value(
        $kintone['domain'] ?? ''
    );
    $loginName = string_value(
        $kintone['login_name'] ?? ''
    );
    $password = string_value(
        $kintone['password'] ?? ''
    );
    $appId = string_value(
        $kintone['customer_app_id'] ?? ''
    );

    $proxyHost = string_value(
        $kintone['proxy_host'] ?? ''
    );
    $proxyPort = string_value(
        $kintone['proxy_port'] ?? ''
    );

    $proxyHostPort =
        normalize_proxy_host_port(
            $proxyHost,
            $proxyPort
        );

    $nameField = string_value(
        $kintone['customer_name_field'] ?? ''
    );

    $emailField = string_value(
        $kintone['customer_email_field'] ?? ''
    );

    $idField = string_value(
        $kintone['customer_id_field'] ?? '$id'
    );

    if ($idField === '') {
        $idField = '$id';
    }

    if ($nameField === '') {
        return [
            'success' => false,
            'status' => 0,
            'message' =>
                '顧客名フィールドを設定してください。',
            'raw' => []
        ];
    }

    if ($emailField === '') {
        return [
            'success' => false,
            'status' => 0,
            'message' =>
                'メールアドレスフィールドを設定してください。',
            'raw' => []
        ];
    }

    $headers = [
        make_cybozu_auth_header(
            $loginName,
            $password
        ),
        'Accept: application/json'
    ];

    $fields = [
        $idField,
        $nameField,
        $emailField
    ];

    $extraFields = string_value(
        $kintone['customer_extra_fields'] ?? ''
    );

    if ($extraFields !== '') {
        foreach (
            preg_split(
                '/[\s,]+/',
                $extraFields
            ) as $extraField
        ) {
            if (
                is_string($extraField) &&
                $extraField !== ''
            ) {
                $fields[] = $extraField;
            }
        }
    }

    $fields = array_values(
        array_unique($fields)
    );

    $query = [
        'app' => $appId,
        'fields' => $fields,
        'query' => 'order by $id asc limit 500'
    ];

    $url = kintone_build_url(
        $domain,
        '/k/v1/records.json'
    );

    $result = kintone_api_request(
        'GET',
        $url,
        $headers,
        $query,
        [
            'proxy_host_port' => $proxyHostPort
        ]
    );

    if (!$result['success']) {
        return $result;
    }

    $records =
        $result['data']['records'] ?? [];

    if (!is_array($records)) {
        return [
            'success' => false,
            'status' => $result['status'],
            'message' =>
                'kintoneから顧客一覧を取得できませんでした。',
            'raw' => $result['data']
        ];
    }

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $idValue =
            $record[$idField]['value'] ??
            '';

        $nameValue =
            $record[$nameField]['value'] ??
            '';

        $emailValue =
            $record[$emailField]['value'] ??
            '';

        $customer = [
            'id' => string_value($idValue),
            'name' => string_value($nameValue),
            'email' => string_value($emailValue),
            'fields' => []
        ];

        foreach ($record as $fieldCode => $fieldData) {
            if (!is_array($fieldData)) {
                continue;
            }

            if (
                !isset($fieldData['value']) ||
                !is_scalar($fieldData['value'])
            ) {
                continue;
            }

            $customer['fields'][(string)$fieldCode] =
                (string)$fieldData['value'];
        }

        if (
            $customer['id'] === ''
        ) {
            $customer['id'] = new_id('customer');
        }

        $customers[] = $customer;
    }

    return [
        'success' => true,
        'status' => $result['status'],
        'customers' => $customers
    ];
}

/**
 * SMTP通信
 */
function smtp_read(
    $socket
): array {
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket, 8192);

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

    $code = 0;

    if (
        preg_match(
            '/^(\d{3})/',
            $response,
            $matches
        )
    ) {
        $code = (int)$matches[1];
    }

    return [
        'code' => $code,
        'message' => trim($response)
    ];
}

function smtp_command(
    $socket,
    string $command,
    array $expectedCodes
): array {
    $written = fwrite(
        $socket,
        $command . "\r\n"
    );

    if ($written === false) {
        return [
            'success' => false,
            'message' => 'SMTPコマンドの送信に失敗しました。'
        ];
    }

    $response = smtp_read($socket);

    if (
        !in_array(
            $response['code'],
            $expectedCodes,
            true
        )
    ) {
        return [
            'success' => false,
            'message' =>
                'SMTPサーバからエラーが返されました。'
        ];
    }

    return [
        'success' => true,
        'message' => $response['message']
    ];
}

function smtp_connect(
    array $settings
): array {
    $server = string_value(
        $settings['smtp_server'] ?? ''
    );

    $port = int_value(
        $settings['smtp_port'] ?? 587,
        587
    );

    $security = string_value(
        $settings['connection_type'] ?? 'tls'
    );

    if ($server === '') {
        return [
            'success' => false,
            'message' => 'SMTPサーバを設定してください。'
        ];
    }

    if ($port < 1 || $port > 65535) {
        return [
            'success' => false,
            'message' => 'SMTPポート番号を確認してください。'
        ];
    }

    $host = $server;

    if (
        strtolower($security) === 'ssl'
    ) {
        $host = 'tls://' . $server;
    }

    $errno = 0;
    $errstr = '';

    $socket = @fsockopen(
        $host,
        $port,
        $errno,
        $errstr,
        15
    );

    if ($socket === false) {
        return [
            'success' => false,
            'message' =>
                'SMTPサーバへ接続できませんでした。'
        ];
    }

    stream_set_timeout($socket, 15);

    $greeting = smtp_read($socket);

    if (
        !in_array(
            $greeting['code'],
            [220],
            true
        )
    ) {
        fclose($socket);

        return [
            'success' => false,
            'message' =>
                'SMTPサーバの応答を確認できませんでした。'
        ];
    }

    $hostname =
        gethostname() ?: 'localhost';

    $helo = smtp_command(
        $socket,
        'EHLO ' . $hostname,
        [250]
    );

    if (!$helo['success']) {
        fclose($socket);

        return $helo;
    }

    if (
        strtolower($security) === 'tls'
    ) {
        $startTls = smtp_command(
            $socket,
            'STARTTLS',
            [220]
        );

        if (!$startTls['success']) {
            fclose($socket);

            return $startTls;
        }

        $cryptoResult = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($cryptoResult !== true) {
            fclose($socket);

            return [
                'success' => false,
                'message' =>
                    'SMTPの暗号化通信を開始できませんでした。'
            ];
        }

        $helo = smtp_command(
            $socket,
            'EHLO ' . $hostname,
            [250]
        );

        if (!$helo['success']) {
            fclose($socket);

            return $helo;
        }
    }

    return [
        'success' => true,
        'socket' => $socket
    ];
}

function smtp_send_mail(
    array $settings,
    string $to,
    string $subject,
    string $body
): array {
    $connection = smtp_connect($settings);

    if (!$connection['success']) {
        return $connection;
    }

    $socket = $connection['socket'];

    $username = string_value(
        $settings['username'] ?? ''
    );

    $password = string_value(
        $settings['password'] ?? ''
    );

    if ($username !== '') {
        $auth = smtp_command(
            $socket,
            'AUTH LOGIN',
            [334]
        );

        if (!$auth['success']) {
            fclose($socket);
            return $auth;
        }

        $auth = smtp_command(
            $socket,
            base64_encode($username),
            [334]
        );

        if (!$auth['success']) {
            fclose($socket);
            return $auth;
        }

        $auth = smtp_command(
            $socket,
            base64_encode($password),
            [235]
        );

        if (!$auth['success']) {
            fclose($socket);
            return [
                'success' => false,
                'message' =>
                    'SMTP認証に失敗しました。'
            ];
        }
    }

    $from = string_value(
        $settings['from_email'] ?? ''
    );

    $fromName = string_value(
        $settings['from_name'] ?? ''
    );

    if ($from === '') {
        fclose($socket);

        return [
            'success' => false,
            'message' =>
                '送信元メールアドレスを設定してください。'
        ];
    }

    $mailFrom = smtp_command(
        $socket,
        'MAIL FROM:<' . $from . '>',
        [250]
    );

    if (!$mailFrom['success']) {
        fclose($socket);
        return $mailFrom;
    }

    $recipient = smtp_command(
        $socket,
        'RCPT TO:<' . $to . '>',
        [250, 251]
    );

    if (!$recipient['success']) {
        fclose($socket);
        return $recipient;
    }

    $dataCommand = smtp_command(
        $socket,
        'DATA',
        [354]
    );

    if (!$dataCommand['success']) {
        fclose($socket);
        return $dataCommand;
    }

    $encodedSubject =
        '=?UTF-8?B?' .
        base64_encode($subject) .
        '?=';

    $encodedFromName = $fromName !== ''
        ? '=?UTF-8?B?' .
          base64_encode($fromName) .
          '?='
        : '';

    $fromHeader =
        $encodedFromName !== ''
        ? $encodedFromName . ' <' . $from . '>'
        : $from;

    $normalizedBody =
        str_replace(
            ["\r\n", "\r"],
            "\n",
            $body
        );

    $normalizedBody =
        str_replace(
            "\n",
            "\r\n",
            $normalizedBody
        );

    $message =
        'From: ' . $fromHeader . "\r\n" .
        'To: <' . $to . ">\r\n" .
        'Subject: ' . $encodedSubject . "\r\n" .
        'MIME-Version: 1.0' . "\r\n" .
        'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
        'Content-Transfer-Encoding: 8bit' . "\r\n" .
        "\r\n" .
        $normalizedBody .
        "\r\n.";

    $written = fwrite(
        $socket,
        $message . "\r\n"
    );

    if ($written === false) {
        fclose($socket);

        return [
            'success' => false,
            'message' =>
                'メール本文の送信に失敗しました。'
        ];
    }

    $dataResponse = smtp_read($socket);

    if (
        !in_array(
            $dataResponse['code'],
            [250],
            true
        )
    ) {
        fclose($socket);

        return [
            'success' => false,
            'message' =>
                'メール送信時にSMTPサーバからエラーが返されました。'
        ];
    }

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
}

function valid_email(string $email): bool
{
    return filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    ) !== false;
}

function public_survey_url(
    string $surveyId,
    string $token
): string {
    $path = application_api_path();

    return $path .
        '?action=public_survey' .
        '&survey_id=' .
        rawurlencode($surveyId) .
        '&token=' .
        rawurlencode($token);
}

function mail_subject_default(
    array $survey
): string {
    $name = string_value(
        $survey['name'] ?? 'アンケート'
    );

    return '【アンケートのお願い】' . $name;
}

function mail_body_default(
    array $survey,
    string $url
): string {
    $name = string_value(
        $survey['name'] ?? 'アンケート'
    );

    return
        "いつもお世話になっております。\n\n" .
        "「{$name}」へのご回答をお願いいたします。\n\n" .
        "下記の回答用ページよりご回答ください。\n" .
        $url . "\n\n" .
        "よろしくお願いいたします。";
}

function find_mail_log_by_token(
    string $surveyId,
    string $token
): ?array {
    $data = load_mail_log_data();

    foreach ($data['logs'] ?? [] as $log) {
        if (!is_array($log)) {
            continue;
        }

        if (
            string_value($log['survey_id'] ?? '') ===
            $surveyId &&
            hash_equals(
                string_value($log['token'] ?? ''),
                $token
            )
        ) {
            return $log;
        }
    }

    return null;
}

function response_exists_for_token(
    string $surveyId,
    string $token
): bool {
    $responses = response_list_for_survey(
        $surveyId
    );

    foreach ($responses as $response) {
        if (
            hash_equals(
                string_value(
                    $response['token'] ?? ''
                ),
                $token
            )
        ) {
            return true;
        }
    }

    return false;
}

function survey_is_open(
    array $survey
): bool {
    if (
        string_value($survey['status'] ?? '') !==
        'open'
    ) {
        return false;
    }

    $now = time();

    $start = string_value(
        $survey['start_at'] ?? ''
    );

    $end = string_value(
        $survey['end_at'] ?? ''
    );

    if ($start !== '') {
        $startTime = strtotime($start);

        if (
            $startTime !== false &&
            $now < $startTime
        ) {
            return false;
        }
    }

    if ($end !== '') {
        $endTime = strtotime($end);

        if (
            $endTime !== false &&
            $now > $endTime
        ) {
            return false;
        }
    }

    return true;
}

function public_question_sequence(
    array $survey,
    array $answers
): array {
    $map = survey_question_map($survey);
    $order = survey_question_order($survey);

    if ($order === []) {
        return [];
    }

    $sequence = [];
    $currentIndex = 0;
    $guard = 0;

    while (
        isset($order[$currentIndex]) &&
        $guard < 1000
    ) {
        $guard++;

        $questionId = $order[$currentIndex];

        if (in_array(
            $questionId,
            $sequence,
            true
        )) {
            break;
        }

        $sequence[] = $questionId;

        $question = $map[$questionId] ?? null;

        if (!is_array($question)) {
            break;
        }

        $answer = $answers[$questionId] ?? null;

        if (
            string_value($question['type'] ?? '') ===
            'single' &&
            is_string($answer)
        ) {
            $branch = '';

            foreach ($question['options'] ?? [] as $option) {
                if (!is_array($option)) {
                    continue;
                }

                if (
                    string_value($option['id'] ?? '') ===
                    $answer
                ) {
                    $branch =
                        string_value(
                            $option['branch'] ?? ''
                        );
                    break;
                }
            }

            if ($branch === 'end') {
                break;
            }

            if (
                $branch !== '' &&
                $branch !== 'next' &&
                isset($map[$branch])
            ) {
                $targetIndex =
                    array_search(
                        $branch,
                        $order,
                        true
                    );

                if ($targetIndex !== false) {
                    $currentIndex =
                        (int)$targetIndex;
                    continue;
                }
            }
        }

        $currentIndex++;
    }

    return $sequence;
}

function sanitize_response_answers(
    array $survey,
    array $inputAnswers
): array {
    $map = survey_question_map($survey);
    $result = [];

    foreach ($map as $questionId => $question) {
        $type = string_value(
            $question['type'] ?? 'text'
        );

        $raw =
            $inputAnswers[$questionId] ??
            null;

        if ($type === 'multiple') {
            $values = [];

            if (is_array($raw)) {
                foreach ($raw as $value) {
                    if (!is_string($value)) {
                        continue;
                    }

                    foreach (
                        $question['options'] ?? []
                        as $option
                    ) {
                        if (!is_array($option)) {
                            continue;
                        }

                        if (
                            string_value(
                                $option['id'] ?? ''
                            ) === $value
                        ) {
                            $values[] = $value;
                            break;
                        }
                    }
                }
            }

            $result[$questionId] =
                array_values(
                    array_unique($values)
                );

            continue;
        }

        if ($type === 'single') {
            $value =
                is_string($raw)
                ? $raw
                : '';

            $valid = false;

            foreach (
                $question['options'] ?? []
                as $option
            ) {
                if (!is_array($option)) {
                    continue;
                }

                if (
                    string_value(
                        $option['id'] ?? ''
                    ) === $value
                ) {
                    $valid = true;
                    break;
                }
            }

            $result[$questionId] =
                $valid ? $value : '';

            continue;
        }

        $value =
            is_string($raw)
            ? trim($raw)
            : '';

        if (mb_strlen($value, 'UTF-8') > 10000) {
            $value =
                mb_substr(
                    $value,
                    0,
                    10000,
                    'UTF-8'
                );
        }

        $result[$questionId] = $value;
    }

    return $result;
}

function validate_response_answers(
    array $survey,
    array $answers
): array {
    $map = survey_question_map($survey);
    $sequence = public_question_sequence(
        $survey,
        $answers
    );

    $errors = [];

    foreach ($sequence as $questionId) {
        $question = $map[$questionId] ?? null;

        if (!is_array($question)) {
            continue;
        }

        if (
            !bool_value(
                $question['required'] ?? false
            )
        ) {
            continue;
        }

        $type = string_value(
            $question['type'] ?? 'text'
        );

        $value =
            $answers[$questionId] ??
            null;

        $empty = false;

        if ($type === 'multiple') {
            $empty =
                !is_array($value) ||
                count($value) === 0;
        } else {
            $empty =
                !is_string($value) ||
                trim($value) === '';
        }

        if ($empty) {
            $errors[] =
                '必須質問「' .
                string_value(
                    $question['text'] ?? ''
                ) .
                '」に回答してください。';
        }
    }

    return $errors;
}

function aggregate_survey(
    array $survey
): array {
    $responses =
        response_list_for_survey(
            string_value($survey['id'] ?? '')
        );

    $questionMap =
        survey_question_map($survey);

    $summary = [];

    foreach ($questionMap as $questionId => $question) {
        $type = string_value(
            $question['type'] ?? 'text'
        );

        $item = [
            'question_id' => $questionId,
            'question_text' =>
                string_value(
                    $question['text'] ?? ''
                ),
            'type' => $type,
            'type_label' =>
                answer_type_label($type),
            'answered_count' => 0,
            'options' => [],
            'texts' => []
        ];

        $optionMap = [];

        foreach ($question['options'] ?? [] as $option) {
            if (!is_array($option)) {
                continue;
            }

            $optionId =
                string_value(
                    $option['id'] ?? ''
                );

            $optionText =
                string_value(
                    $option['text'] ?? ''
                );

            if ($optionId === '') {
                continue;
            }

            $optionMap[$optionId] = [
                'id' => $optionId,
                'text' => $optionText,
                'count' => 0,
                'percentage' => 0
            ];
        }

        foreach ($responses as $response) {
            $answers =
                $response['answers'] ?? [];

            if (!is_array($answers)) {
                continue;
            }

            if (
                !array_key_exists(
                    $questionId,
                    $answers
                )
            ) {
                continue;
            }

            $value =
                $answers[$questionId];

            if ($type === 'text') {
                if (
                    is_string($value) &&
                    trim($value) !== ''
                ) {
                    $item['answered_count']++;

                    $item['texts'][] = [
                        'value' => $value,
                        'submitted_at' =>
                            string_value(
                                $response['submitted_at'] ?? ''
                            )
                    ];
                }

                continue;
            }

            if ($type === 'single') {
                if (
                    is_string($value) &&
                    isset($optionMap[$value])
                ) {
                    $item['answered_count']++;
                    $optionMap[$value]['count']++;
                }

                continue;
            }

            if ($type === 'multiple') {
                if (!is_array($value)) {
                    continue;
                }

                $hasAnswer = false;

                foreach ($value as $selected) {
                    if (
                        is_string($selected) &&
                        isset($optionMap[$selected])
                    ) {
                        $optionMap[$selected]['count']++;
                        $hasAnswer = true;
                    }
                }

                if ($hasAnswer) {
                    $item['answered_count']++;
                }
            }
        }

        $denominator =
            $item['answered_count'];

        foreach ($optionMap as $option) {
            $option['percentage'] =
                $denominator > 0
                ? round(
                    ($option['count'] /
                    $denominator) * 100,
                    1
                )
                : 0;

            $item['options'][] = $option;
        }

        $summary[] = $item;
    }

    return [
        'total_responses' => count($responses),
        'respondent_count' => count($responses),
        'questions' => $summary
    ];
}

function survey_status_summary(
    array $survey
): array {
    $surveyId =
        string_value($survey['id'] ?? '');

    $responses =
        response_list_for_survey(
            $surveyId
        );

    $logs =
        mail_logs_for_survey(
            $surveyId
        );

    $targetCount = count($logs);
    $sentCount = 0;

    $uniqueRespondedTokens = [];

    foreach ($logs as $log) {
        if (
            string_value(
                $log['status'] ?? ''
            ) === 'success'
        ) {
            $sentCount++;
        }
    }

    foreach ($responses as $response) {
        $token =
            string_value(
                $response['token'] ?? ''
            );

        if ($token !== '') {
            $uniqueRespondedTokens[$token] =
                true;
        }
    }

    $responseCount =
        count($responses);

    $responseRate =
        $sentCount > 0
        ? round(
            ($responseCount / $sentCount) * 100,
            1
        )
        : 0;

    return [
        'response_count' => $responseCount,
        'response_rate' => $responseRate,
        'unanswered_count' =>
            max(
                0,
                $sentCount -
                count($uniqueRespondedTokens)
            ),
        'target_count' => $targetCount,
        'sent_count' => $sentCount,
        'failed_count' =>
            max(
                0,
                $targetCount - $sentCount
            ),
        'public_open' =>
            survey_is_open($survey)
    ];
}

function setting_public_summary(
    array $settings
): array {
    $mail = $settings['mail'] ?? [];
    $kintone = $settings['kintone'] ?? [];

    return [
        'mail' => [
            'configured' =>
                bool_value(
                    $mail['configured'] ?? false
                ),
            'tested_at' =>
                string_value(
                    $mail['tested_at'] ?? ''
                )
        ],
        'kintone' => [
            'configured' =>
                bool_value(
                    $kintone['configured'] ?? false
                ),
            'connection_status' =>
                string_value(
                    $kintone['connection_status'] ??
                    'not_configured'
                ),
            'tested_at' =>
                string_value(
                    $kintone['tested_at'] ?? ''
                )
        ]
    ];
}

function public_survey_payload(
    array $survey,
    string $token
): array {
    $numberMap =
        question_number_map($survey);

    $questions = [];

    foreach (
        $survey['groups'] ?? []
        as $group
    ) {
        if (!is_array($group)) {
            continue;
        }

        $groupQuestions = [];

        foreach (
            $group['questions'] ?? []
            as $question
        ) {
            if (!is_array($question)) {
                continue;
            }

            $questionId =
                string_value(
                    $question['id'] ?? ''
                );

            if ($questionId === '') {
                continue;
            }

            $groupQuestions[] = [
                'id' => $questionId,
                'number' =>
                    $numberMap[$questionId] ??
                    '',
                'text' =>
                    string_value(
                        $question['text'] ?? ''
                    ),
                'type' =>
                    string_value(
                        $question['type'] ?? 'text'
                    ),
                'required' =>
                    bool_value(
                        $question['required'] ?? false
                    ),
                'options' =>
                    array_map(
                        static function (
                            mixed $option
                        ): array {
                            if (!is_array($option)) {
                                return [
                                    'id' => '',
                                    'text' => ''
                                ];
                            }

                            return [
                                'id' =>
                                    string_value(
                                        $option['id'] ?? ''
                                    ),
                                'text' =>
                                    string_value(
                                        $option['text'] ?? ''
                                    )
                            ];
                        },
                        $question['options'] ?? []
                    )
            ];
        }

        $questions[] = [
            'id' =>
                string_value(
                    $group['id'] ?? ''
                ),
            'name' =>
                string_value(
                    $group['name'] ?? ''
                ),
            'questions' => $groupQuestions
        ];
    }

    return [
        'survey' => [
            'id' =>
                string_value(
                    $survey['id'] ?? ''
                ),
            'name' =>
                string_value(
                    $survey['name'] ?? ''
                ),
            'description' =>
                string_value(
                    $survey['description'] ?? ''
                ),
            'start_at' =>
                string_value(
                    $survey['start_at'] ?? ''
                ),
            'end_at' =>
                string_value(
                    $survey['end_at'] ?? ''
                ),
            'numbering' =>
                string_value(
                    $survey['numbering'] ?? 'global'
                ),
            'groups' => $questions
        ],
        'token' => $token,
        'already_submitted' =>
            response_exists_for_token(
                string_value(
                    $survey['id'] ?? ''
                ),
                $token
            )
    ];
}

/* =========================================================
 * 初期化
 * ========================================================= */

initialize_data_files();

/* =========================================================
 * API
 * ========================================================= */

$action = requested_action();

if ($action !== '') {
    $method = request_method();

    if ($method === 'POST') {
        validate_csrf();
    }

    if (
        $action === 'get_bootstrap' &&
        $method === 'GET'
    ) {
        $surveyData = load_survey_data();
        $customerData = load_customer_data();
        $settings = load_settings();

        $surveys = [];

        foreach (
            $surveyData['surveys'] ?? []
            as $survey
        ) {
            if (!is_array($survey)) {
                continue;
            }

            $surveyId =
                string_value(
                    $survey['id'] ?? ''
                );

            $summary =
                survey_status_summary($survey);

            $surveys[] = [
                'id' => $surveyId,
                'name' =>
                    string_value(
                        $survey['name'] ?? ''
                    ),
                'status' =>
                    string_value(
                        $survey['status'] ?? 'draft'
                    ),
                'status_label' =>
                    status_label(
                        string_value(
                            $survey['status'] ?? 'draft'
                        )
                    ),
                'created_at' =>
                    string_value(
                        $survey['created_at'] ?? ''
                    ),
                'updated_at' =>
                    string_value(
                        $survey['updated_at'] ?? ''
                    ),
                'start_at' =>
                    string_value(
                        $survey['start_at'] ?? ''
                    ),
                'end_at' =>
                    string_value(
                        $survey['end_at'] ?? ''
                    ),
                'response_count' =>
                    $summary['response_count']
            ];
        }

        json_response(
            true,
            'データを取得しました。',
            [
                'surveys' => $surveys,
                'customers' =>
                    $customerData['customers'] ?? [],
                'customer_count' =>
                    count(
                        $customerData['customers'] ?? []
                    ),
                'settings' =>
                    setting_public_summary(
                        $settings
                    ),
                'csrf_token' => csrf_token()
            ]
        );
    }

    if (
        $action === 'get_survey' &&
        $method === 'GET'
    ) {
        $surveyId =
            string_value(
                $_GET['survey_id'] ?? ''
            );

        $survey =
            survey_by_id($surveyId);

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

    if (
        $action === 'get_customers' &&
        $method === 'GET'
    ) {
        $data = load_customer_data();

        json_response(
            true,
            '顧客一覧を取得しました。',
            [
                'customers' =>
                    $data['customers'] ?? [],
                'updated_at' =>
                    string_value(
                        $data['updated_at'] ?? ''
                    )
            ]
        );
    }

    if (
        $action === 'get_settings' &&
        $method === 'GET'
    ) {
        $settings = load_settings();

        json_response(
            true,
            '設定を取得しました。',
            [
                'mail' => [
                    'smtp_server' =>
                        string_value(
                            $settings['mail']['smtp_server'] ?? ''
                        ),
                    'smtp_port' =>
                        int_value(
                            $settings['mail']['smtp_port'] ?? 587,
                            587
                        ),
                    'connection_type' =>
                        string_value(
                            $settings['mail']['connection_type'] ?? 'tls'
                        ),
                    'username' =>
                        string_value(
                            $settings['mail']['username'] ?? ''
                        ),
                    'from_email' =>
                        string_value(
                            $settings['mail']['from_email'] ?? ''
                        ),
                    'from_name' =>
                        string_value(
                            $settings['mail']['from_name'] ?? ''
                        ),
                    'configured' =>
                        bool_value(
                            $settings['mail']['configured'] ?? false
                        ),
                    'tested_at' =>
                        string_value(
                            $settings['mail']['tested_at'] ?? ''
                        )
                ],
                'kintone' => [
                    'domain' =>
                        string_value(
                            $settings['kintone']['domain'] ?? ''
                        ),
                    'login_name' =>
                        string_value(
                            $settings['kintone']['login_name'] ?? ''
                        ),
                    'customer_app_id' =>
                        string_value(
                            $settings['kintone']['customer_app_id'] ?? ''
                        ),
                    'customer_id_field' =>
                        string_value(
                            $settings['kintone']['customer_id_field'] ?? '$id'
                        ),
                    'customer_name_field' =>
                        string_value(
                            $settings['kintone']['customer_name_field'] ?? ''
                        ),
                    'customer_email_field' =>
                        string_value(
                            $settings['kintone']['customer_email_field'] ?? ''
                        ),
                    'customer_extra_fields' =>
                        string_value(
                            $settings['kintone']['customer_extra_fields'] ?? ''
                        ),
                    'proxy_host' =>
                        string_value(
                            $settings['kintone']['proxy_host'] ?? ''
                        ),
                    'proxy_port' =>
                        string_value(
                            $settings['kintone']['proxy_port'] ?? ''
                        ),
                    'configured' =>
                        bool_value(
                            $settings['kintone']['configured'] ?? false
                        ),
                    'connection_status' =>
                        string_value(
                            $settings['kintone']['connection_status'] ?? 'not_configured'
                        ),
                    'tested_at' =>
                        string_value(
                            $settings['kintone']['tested_at'] ?? ''
                        )
                ]
            ]
        );
    }

    if (
        $action === 'get_mail_logs' &&
        $method === 'GET'
    ) {
        $surveyId =
            string_value(
                $_GET['survey_id'] ?? ''
            );

        $logs =
            mail_logs_for_survey($surveyId);

        $safeLogs = [];

        foreach ($logs as $log) {
            $safeLogs[] = [
                'id' =>
                    string_value(
                        $log['id'] ?? ''
                    ),
                'customer_id' =>
                    string_value(
                        $log['customer_id'] ?? ''
                    ),
                'name' =>
                    string_value(
                        $log['name'] ?? ''
                    ),
                'email' =>
                    string_value(
                        $log['email'] ?? ''
                    ),
                'status' =>
                    string_value(
                        $log['status'] ?? ''
                    ),
                'error' =>
                    string_value(
                        $log['error'] ?? ''
                    ),
                'sent_at' =>
                    string_value(
                        $log['sent_at'] ?? ''
                    )
            ];
        }

        json_response(
            true,
            '送信履歴を取得しました。',
            ['logs' => $safeLogs]
        );
    }

    if (
        $action === 'get_status' &&
        $method === 'GET'
    ) {
        $surveyId =
            string_value(
                $_GET['survey_id'] ?? ''
            );

        $survey =
            survey_by_id($surveyId);

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
            '回答状況を取得しました。',
            [
                'summary' =>
                    survey_status_summary(
                        $survey
                    )
            ]
        );
    }

    if (
        $action === 'get_results' &&
        $method === 'GET'
    ) {
        $surveyId =
            string_value(
                $_GET['survey_id'] ?? ''
            );

        $survey =
            survey_by_id($surveyId);

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
            '回答結果を取得しました。',
            [
                'aggregate' =>
                    aggregate_survey($survey)
            ]
        );
    }

    if (
        $action === 'save_mail_settings' &&
        $method === 'POST'
    ) {
        $input = read_json_request();
        $settings = load_settings();

        $server =
            string_value(
                $input['smtp_server'] ?? ''
            );

        $port =
            int_value(
                $input['smtp_port'] ?? 0
            );

        $connectionType =
            string_value(
                $input['connection_type'] ?? 'tls'
            );

        $username =
            string_value(
                $input['username'] ?? ''
            );

        $password =
            string_value(
                $input['password'] ?? ''
            );

        $fromEmail =
            string_value(
                $input['from_email'] ?? ''
            );

        $fromName =
            string_value(
                $input['from_name'] ?? ''
            );

        $errors = [];

        if ($server === '') {
            $errors['smtp_server'] =
                'SMTPサーバを入力してください。';
        }

        if ($port < 1 || $port > 65535) {
            $errors['smtp_port'] =
                'ポート番号を確認してください。';
        }

        if (!in_array(
            $connectionType,
            ['none', 'tls', 'ssl'],
            true
        )) {
            $errors['connection_type'] =
                '接続方式を確認してください。';
        }

        if (
            $username !== '' &&
            $password === '' &&
            string_value(
                $settings['mail']['password'] ?? ''
            ) === ''
        ) {
            $errors['password'] =
                '認証パスワードを入力してください。';
        }

        if (
            $fromEmail === '' ||
            !valid_email($fromEmail)
        ) {
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

        if ($password === '') {
            $password =
                string_value(
                    $settings['mail']['password'] ?? ''
                );
        }

        $settings['mail'] = [
            'smtp_server' => $server,
            'smtp_port' => $port,
            'connection_type' =>
                $connectionType,
            'username' => $username,
            'password' => $password,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'configured' => true,
            'tested_at' =>
                string_value(
                    $settings['mail']['tested_at'] ?? ''
                )
        ];

        save_settings($settings);

        json_response(
            true,
            'メール送信設定を保存しました。',
            [
                'settings' =>
                    setting_public_summary(
                        $settings
                    )
            ]
        );
    }

    if (
        $action === 'test_mail_settings' &&
        $method === 'POST'
    ) {
        $settings = load_settings();

        if (
            !bool_value(
                $settings['mail']['configured'] ?? false
            )
        ) {
            json_response(
                false,
                '先にメール送信設定を保存してください。',
                [],
                [],
                422
            );
        }

        $testRecipient =
            string_value(
                read_json_request()['test_email'] ?? ''
            );

        if (
            $testRecipient === '' ||
            !valid_email($testRecipient)
        ) {
            json_response(
                false,
                '確認用メールアドレスを入力してください。',
                [],
                [],
                422
            );
        }

        $mailResult = smtp_send_mail(
            $settings['mail'],
            $testRecipient,
            'アンケート運営アプリ SMTP接続確認',
            "メール送信設定の確認です。\n\n" .
            'このメールが届けばSMTP設定は正常です。'
        );

        if (!$mailResult['success']) {
            json_response(
                false,
                $mailResult['message'],
                [],
                [],
                502
            );
        }

        $settings['mail']['tested_at'] =
            current_datetime();

        save_settings($settings);

        json_response(
            true,
            '確認メールを送信しました。'
        );
    }

    if (
        $action === 'save_kintone_settings' &&
        $method === 'POST'
    ) {
        $input = read_json_request();
        $settings = load_settings();

        $kintone = [
            'domain' =>
                string_value(
                    $input['domain'] ?? ''
                ),
            'login_name' =>
                string_value(
                    $input['login_name'] ?? ''
                ),
            'password' =>
                string_value(
                    $input['password'] ?? ''
                ),
            'customer_app_id' =>
                string_value(
                    $input['customer_app_id'] ?? ''
                ),
            'customer_id_field' =>
                string_value(
                    $input['customer_id_field'] ?? '$id'
                ),
            'customer_name_field' =>
                string_value(
                    $input['customer_name_field'] ?? ''
                ),
            'customer_email_field' =>
                string_value(
                    $input['customer_email_field'] ?? ''
                ),
            'customer_extra_fields' =>
                string_value(
                    $input['customer_extra_fields'] ?? ''
                ),
            'proxy_host' =>
                string_value(
                    $input['proxy_host'] ?? ''
                ),
            'proxy_port' =>
                string_value(
                    $input['proxy_port'] ?? ''
                ),
            'configured' => false,
            'connection_status' =>
                'not_tested',
            'tested_at' => null
        ];

        if ($kintone['password'] === '') {
            $kintone['password'] =
                string_value(
                    $settings['kintone']['password'] ?? ''
                );
        }

        $errors =
            kintone_settings_valid(
                ['kintone' => $kintone]
            );

        if ($errors !== []) {
            json_response(
                false,
                '入力内容を確認してください。',
                [],
                $errors,
                422
            );
        }

        $kintone['configured'] = true;

        $settings['kintone'] =
            $kintone;

        save_settings($settings);

        json_response(
            true,
            'kintone設定を保存しました。',
            [
                'settings' =>
                    setting_public_summary(
                        $settings
                    )
            ]
        );
    }

    if (
        $action === 'test_kintone_connection' &&
        $method === 'POST'
    ) {
        $settings = load_settings();

        $errors =
            kintone_settings_valid(
                $settings
            );

        if ($errors !== []) {
            json_response(
                false,
                'kintone設定を確認してください。',
                [],
                $errors,
                422
            );
        }

        $result =
            test_kintone_connection(
                $settings['kintone']
            );

        if (!$result['success']) {
            $settings['kintone']['connection_status'] =
                'error';

            $settings['kintone']['tested_at'] =
                current_datetime();

            save_settings($settings);

            json_response(
                false,
                $result['message'],
                [
                    'status' =>
                        $result['status']
                ],
                [],
                502
            );
        }

        $settings['kintone']['connection_status'] =
            'connected';

        $settings['kintone']['configured'] =
            true;

        $settings['kintone']['tested_at'] =
            current_datetime();

        save_settings($settings);

        json_response(
            true,
            'kintoneへの接続を確認しました。'
        );
    }

    if (
        $action === 'refresh_customers' &&
        $method === 'POST'
    ) {
        $settings = load_settings();

        $errors =
            kintone_settings_valid(
                $settings
            );

        if ($errors !== []) {
            json_response(
                false,
                'kintone設定を確認してください。',
                [],
                $errors,
                422
            );
        }

        $result =
            fetch_kintone_customers(
                $settings['kintone']
            );

        if (!$result['success']) {
            $settings['kintone']['connection_status'] =
                'error';

            $settings['kintone']['tested_at'] =
                current_datetime();

            save_settings($settings);

            json_response(
                false,
                $result['message'],
                [],
                [],
                502
            );
        }

        $customerData = [
            'version' => 1,
            'updated_at' =>
                current_datetime(),
            'source' => 'kintone',
            'count' =>
                count($result['customers']),
            'customers' =>
                $result['customers']
        ];

        save_customer_data(
            $customerData
        );

        $settings['kintone']['connection_status'] =
            'connected';

        $settings['kintone']['configured'] =
            true;

        $settings['kintone']['tested_at'] =
            current_datetime();

        save_settings($settings);

        json_response(
            true,
            'kintoneから顧客一覧を取得しました。',
            [
                'count' =>
                    count($result['customers'])
            ]
        );
    }

    if (
        $action === 'save_survey' &&
        $method === 'POST'
    ) {
        $input = read_json_request();

        $errors =
            validate_survey_payload($input);

        if ($errors !== []) {
            json_response(
                false,
                'アンケート内容を確認してください。',
                [],
                ['survey' => $errors],
                422
            );
        }

        $data = load_survey_data();

        $existing = null;
        $surveyId =
            string_value(
                $input['id'] ?? ''
            );

        if ($surveyId !== '') {
            $index =
                survey_index_by_id(
                    $data['surveys'] ?? [],
                    $surveyId
                );

            if ($index >= 0) {
                $existing =
                    $data['surveys'][$index];
            }
        }

        $survey =
            normalize_survey(
                $input,
                $existing
            );

        if ($existing !== null) {
            $index =
                survey_index_by_id(
                    $data['surveys'],
                    $survey['id']
                );

            if ($index >= 0) {
                $data['surveys'][$index] =
                    $survey;
            } else {
                $data['surveys'][] =
                    $survey;
            }
        } else {
            $data['surveys'][] =
                $survey;
        }

        save_survey_data($data);

        json_response(
            true,
            'アンケートを保存しました。',
            ['survey' => $survey]
        );
    }

    if (
        $action === 'delete_survey' &&
        $method === 'POST'
    ) {
        $input = read_json_request();

        $surveyId =
            string_value(
                $input['survey_id'] ?? ''
            );

        $data = load_survey_data();

        $index =
            survey_index_by_id(
                $data['surveys'] ?? [],
                $surveyId
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

        $survey =
            $data['surveys'][$index];

        if (
            string_value(
                $survey['status'] ?? ''
            ) !== 'draft'
        ) {
            json_response(
                false,
                '下書きのアンケートだけ削除できます。',
                [],
                [],
                422
            );
        }

        array_splice(
            $data['surveys'],
            $index,
            1
        );

        save_survey_data($data);

        json_response(
            true,
            'アンケートを削除しました。'
        );
    }

    if (
        $action === 'publish_survey' &&
        $method === 'POST'
    ) {
        $input = read_json_request();

        $surveyId =
            string_value(
                $input['survey_id'] ?? ''
            );

        $data = load_survey_data();

        $index =
            survey_index_by_id(
                $data['surveys'] ?? [],
                $surveyId
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

        $survey =
            $data['surveys'][$index];

        $errors =
            validate_survey_payload($survey);

        if ($errors !== []) {
            json_response(
                false,
                '公開前にアンケート内容を確認してください。',
                [],
                ['survey' => $errors],
                422
            );
        }

        $hasQuestions =
            count(
                survey_question_order($survey)
            ) > 0;

        if (!$hasQuestions) {
            json_response(
                false,
                '公開するには質問を1つ以上設定してください。',
                [],
                [],
                422
            );
        }

        $survey['status'] = 'open';
        $survey['updated_at'] =
            current_datetime();

        $data['surveys'][$index] =
            $survey;

        save_survey_data($data);

        json_response(
            true,
            'アンケートを公開しました。',
            ['survey' => $survey]
        );
    }

    if (
        $action === 'close_survey' &&
        $method === 'POST'
    ) {
        $input = read_json_request();

        $surveyId =
            string_value(
                $input['survey_id'] ?? ''
            );

        $data = load_survey_data();

        $index =
            survey_index_by_id(
                $data['surveys'] ?? [],
                $surveyId
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

        $survey =
            $data['surveys'][$index];

        if (
            string_value(
                $survey['status'] ?? ''
            ) !== 'open'
        ) {
            json_response(
                false,
                '公開中のアンケートだけ終了できます。',
                [],
                [],
                422
            );
        }

        $survey['status'] = 'closed';
        $survey['updated_at'] =
            current_datetime();

        $data['surveys'][$index] =
            $survey;

        save_survey_data($data);

        json_response(
            true,
            'アンケートを終了しました。',
            ['survey' => $survey]
        );
    }

    if (
        $action === 'send_survey' &&
        $method === 'POST'
    ) {
        $input = read_json_request();

        $surveyId =
            string_value(
                $input['survey_id'] ?? ''
            );

        $survey =
            survey_by_id($surveyId);

        if ($survey === null) {
            json_response(
                false,
                'アンケートが見つかりません。',
                [],
                [],
                404
            );
        }

        if (
            string_value(
                $survey['status'] ?? ''
            ) !== 'open'
        ) {
            json_response(
                false,
                '公開中のアンケートだけ送信できます。',
                [],
                [],
                422
            );
        }

        $settings =
            load_settings();

        if (
            !bool_value(
                $settings['mail']['configured'] ?? false
            )
        ) {
            json_response(
                false,
                'メール送信設定を先に完了してください。',
                [],
                [],
                422
            );
        }

        $customerIds =
            $input['customer_ids'] ?? [];

        if (!is_array($customerIds)) {
            $customerIds = [];
        }

        $subject =
            string_value(
                $input['subject'] ?? ''
            );

        $body =
            string_value(
                $input['body'] ?? ''
            );

        if ($subject === '') {
            json_response(
                false,
                '件名を入力してください。',
                [],
                [],
                422
            );
        }

        if ($body === '') {
            json_response(
                false,
                '本文を入力してください。',
                [],
                [],
                422
            );
        }

        if ($customerIds === []) {
            json_response(
                false,
                '送信対象者を1名以上選択してください。',
                [],
                [],
                422
            );
        }

        $customerData =
            load_customer_data();

        $customerMap = [];

        foreach (
            $customerData['customers'] ?? []
            as $customer
        ) {
            if (!is_array($customer)) {
                continue;
            }

            $id =
                string_value(
                    $customer['id'] ?? ''
                );

            if ($id !== '') {
                $customerMap[$id] =
                    $customer;
            }
        }

        $mailData =
            load_mail_log_data();

        $logs = $mailData['logs'] ?? [];

        if (!is_array($logs)) {
            $logs = [];
        }

        $successCount = 0;
        $failureCount = 0;
        $results = [];

        foreach ($customerIds as $customerId) {
            if (!is_string($customerId)) {
                continue;
            }

            $customerId =
                trim($customerId);

            if (
                $customerId === '' ||
                !isset($customerMap[$customerId])
            ) {
                continue;
            }

            $customer =
                $customerMap[$customerId];

            $email =
                string_value(
                    $customer['email'] ?? ''
                );

            $name =
                string_value(
                    $customer['name'] ?? ''
                );

            if (
                $email === '' ||
                !valid_email($email)
            ) {
                $failureCount++;

                $results[] = [
                    'name' => $name,
                    'email' => $email,
                    'status' => 'failure',
                    'error' =>
                        'メールアドレスが正しくありません。'
                ];

                continue;
            }

            $token =
                bin2hex(random_bytes(32));

            $url =
                public_survey_url(
                    $surveyId,
                    $token
                );

            $personalizedBody =
                str_replace(
                    [
                        '{{氏名}}',
                        '{{顧客名}}',
                        '{{回答URL}}'
                    ],
                    [
                        $name,
                        $name,
                        $url
                    ],
                    $body
                );

            if (
                !str_contains(
                    $personalizedBody,
                    $url
                )
            ) {
                $personalizedBody .=
                    "\n\n回答用ページ:\n" .
                    $url;
            }

            $mailResult =
                smtp_send_mail(
                    $settings['mail'],
                    $email,
                    $subject,
                    $personalizedBody
                );

            if ($mailResult['success']) {
                $status = 'success';
                $error = '';
                $successCount++;
            } else {
                $status = 'failure';
                $error =
                    string_value(
                        $mailResult['message'] ?? ''
                    );
                $failureCount++;
            }

            $logs[] = [
                'id' => new_id('mail'),
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'name' => $name,
                'email' => $email,
                'status' => $status,
                'error' => $error,
                'sent_at' =>
                    current_datetime(),
                'token' => $token,
                'subject' => $subject
            ];

            $results[] = [
                'name' => $name,
                'email' => $email,
                'status' => $status,
                'error' => $error
            ];
        }

        $mailData['logs'] = $logs;

        save_mail_log_data(
            $mailData
        );

        json_response(
            true,
            'メール送信処理が完了しました。',
            [
                'target_count' =>
                    $successCount +
                    $failureCount,
                'success_count' =>
                    $successCount,
                'failure_count' =>
                    $failureCount,
                'results' => $results
            ]
        );
    }

    if (
        $action === 'resend_survey' &&
        $method === 'POST'
    ) {
        $input = read_json_request();

        $surveyId =
            string_value(
                $input['survey_id'] ?? ''
            );

        $survey =
            survey_by_id($surveyId);

        if ($survey === null) {
            json_response(
                false,
                'アンケートが見つかりません。',
                [],
                [],
                404
            );
        }

        if (
            string_value(
                $survey['status'] ?? ''
            ) !== 'open'
        ) {
            json_response(
                false,
                '公開中のアンケートだけ再送できます。',
                [],
                [],
                422
            );
        }

        $settings =
            load_settings();

        if (
            !bool_value(
                $settings['mail']['configured'] ?? false
            )
        ) {
            json_response(
                false,
                'メール送信設定を先に完了してください。',
                [],
                [],
                422
            );
        }

        $logIds =
            $input['log_ids'] ?? [];

        if (!is_array($logIds)) {
            $logIds = [];
        }

        if ($logIds === []) {
            json_response(
                false,
                '再送対象者を選択してください。',
                [],
                [],
                422
            );
        }

        $subject =
            string_value(
                $input['subject'] ?? ''
            );

        $body =
            string_value(
                $input['body'] ?? ''
            );

        $mailData =
            load_mail_log_data();

        $logs = $mailData['logs'] ?? [];

        if (!is_array($logs)) {
            $logs = [];
        }

        $successCount = 0;
        $failureCount = 0;
        $results = [];

        foreach ($logs as $index => $log) {
            if (!is_array($log)) {
                continue;
            }

            $logId =
                string_value(
                    $log['id'] ?? ''
                );

            if (
                !in_array(
                    $logId,
                    $logIds,
                    true
                )
            ) {
                continue;
            }

            if (
                string_value(
                    $log['survey_id'] ?? ''
                ) !== $surveyId
            ) {
                continue;
            }

            $email =
                string_value(
                    $log['email'] ?? ''
                );

            $name =
                string_value(
                    $log['name'] ?? ''
                );

            if (
                $email === '' ||
                !valid_email($email)
            ) {
                $failureCount++;

                $results[] = [
                    'name' => $name,
                    'email' => $email,
                    'status' => 'failure',
                    'error' =>
                        'メールアドレスが正しくありません。'
                ];

                continue;
            }

            $token =
                bin2hex(random_bytes(32));

            $url =
                public_survey_url(
                    $surveyId,
                    $token
                );

            $personalizedBody =
                str_replace(
                    [
                        '{{氏名}}',
                        '{{顧客名}}',
                        '{{回答URL}}'
                    ],
                    [
                        $name,
                        $name,
                        $url
                    ],
                    $body
                );

            if (
                !str_contains(
                    $personalizedBody,
                    $url
                )
            ) {
                $personalizedBody .=
                    "\n\n回答用ページ:\n" .
                    $url;
            }

            $mailResult =
                smtp_send_mail(
                    $settings['mail'],
                    $email,
                    $subject,
                    $personalizedBody
                );

            if ($mailResult['success']) {
                $logs[$index]['status'] =
                    'success';

                $logs[$index]['error'] = '';

                $logs[$index]['sent_at'] =
                    current_datetime();

                $logs[$index]['token'] =
                    $token;

                $successCount++;
                $results[] = [
                    'name' => $name,
                    'email' => $email,
                    'status' => 'success',
                    'error' => ''
                ];
            } else {
                $logs[$index]['status'] =
                    'failure';

                $logs[$index]['error'] =
                    string_value(
                        $mailResult['message'] ?? ''
                    );

                $logs[$index]['sent_at'] =
                    current_datetime();

                $failureCount++;

                $results[] = [
                    'name' => $name,
                    'email' => $email,
                    'status' => 'failure',
                    'error' =>
                        $logs[$index]['error']
                ];
            }
        }

        $mailData['logs'] = $logs;

        save_mail_log_data(
            $mailData
        );

        json_response(
            true,
            '再送処理が完了しました。',
            [
                'success_count' =>
                    $successCount,
                'failure_count' =>
                    $failureCount,
                'results' => $results
            ]
        );
    }

    if (
        $action === 'save_response' &&
        $method === 'POST'
    ) {
        $input = read_json_request();

        $surveyId =
            string_value(
                $input['survey_id'] ?? ''
            );

        $token =
            string_value(
                $input['token'] ?? ''
            );

        if (
            $surveyId === '' ||
            $token === ''
        ) {
            json_response(
                false,
                '回答用ページの情報を確認できません。',
                [],
                [],
                422
            );
        }

        $survey =
            survey_by_id($surveyId);

        if ($survey === null) {
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
                422
            );
        }

        $mailLog =
            find_mail_log_by_token(
                $surveyId,
                $token
            );

        if ($mailLog === null) {
            json_response(
                false,
                '回答用ページの有効期限またはURLを確認してください。',
                [],
                [],
                403
            );
        }

        if (
            response_exists_for_token(
                $surveyId,
                $token
            )
        ) {
            json_response(
                false,
                'このアンケートはすでに回答済みです。',
                [],
                [],
                409
            );
        }

        $inputAnswers =
            $input['answers'] ?? [];

        if (!is_array($inputAnswers)) {
            $inputAnswers = [];
        }

        $answers =
            sanitize_response_answers(
                $survey,
                $inputAnswers
            );

        $errors =
            validate_response_answers(
                $survey,
                $answers
            );

        if ($errors !== []) {
            json_response(
                false,
                '未回答の必須質問があります。',
                [],
                ['answers' => $errors],
                422
            );
        }

        $responseData =
            load_response_data();

        $responseData['responses'][] = [
            'id' => new_id('response'),
            'survey_id' => $surveyId,
            'token' => $token,
            'customer_id' =>
                string_value(
                    $mailLog['customer_id'] ?? ''
                ),
            'respondent_name' =>
                string_value(
                    $mailLog['name'] ?? ''
                ),
            'respondent_email' =>
                string_value(
                    $mailLog['email'] ?? ''
                ),
            'submitted_at' =>
                current_datetime(),
            'answers' => $answers
        ];

        save_response_data(
            $responseData
        );

        json_response(
            true,
            '回答を送信しました。'
        );
    }

    if (
        $action === 'public_survey' &&
        $method === 'GET'
    ) {
        $surveyId =
            string_value(
                $_GET['survey_id'] ?? ''
            );

        $token =
            string_value(
                $_GET['token'] ?? ''
            );

        $survey =
            survey_by_id($surveyId);

        $publicError = '';

        if ($survey === null) {
            $publicError =
                'アンケートが見つかりません。';
        } elseif (
            !survey_is_open($survey)
        ) {
            $publicError =
                'このアンケートは現在回答を受け付けていません。';
        } elseif ($token === '') {
            $publicError =
                '回答用URLが正しくありません。';
        } elseif (
            find_mail_log_by_token(
                $surveyId,
                $token
            ) === null
        ) {
            $publicError =
                '回答用URLが正しくありません。';
        }

        $publicPayload = null;

        if ($publicError === '') {
            $publicPayload =
                public_survey_payload(
                    $survey,
                    $token
                );
        }

        header(
            'Content-Type: text/html; charset=utf-8'
        );

        ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($survey['name'] ?? 'アンケート') ?></title>
<style>
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
    background:#f4f6f8;
    color:#263238;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Yu Gothic","YuGothic",Meiryo,sans-serif;
    line-height:1.7;
}
.respondent-wrap{
    width:min(900px,calc(100% - 32px));
    margin:35px auto 60px;
}
.respondent-header{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:8px;
    padding:28px;
    margin-bottom:18px;
}
.respondent-header h1{
    margin:0 0 10px;
    font-size:25px;
}
.respondent-description{
    color:#52606d;
    white-space:pre-wrap;
}
.respondent-question{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:8px;
    padding:22px;
    margin-bottom:15px;
}
.respondent-question-title{
    font-weight:bold;
    margin-bottom:14px;
}
.required{
    color:#c53f3f;
    font-size:12px;
    margin-left:7px;
}
.respondent-option{
    margin:9px 0;
}
.respondent-option label{
    display:flex;
    gap:9px;
    align-items:flex-start;
}
.respondent-option input{
    margin-top:6px;
}
.respondent-input{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:10px;
    min-height:42px;
}
.respondent-textarea{
    min-height:130px;
    resize:vertical;
}
.respondent-footer{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:8px;
    padding:22px;
    text-align:center;
}
.respondent-btn{
    border:0;
    background:#2878c8;
    color:#fff;
    padding:12px 30px;
    border-radius:5px;
    min-width:190px;
    font-weight:bold;
}
.respondent-btn:disabled{
    opacity:.55;
    cursor:not-allowed;
}
.notice{
    padding:13px 15px;
    border-radius:6px;
    background:#eef6ff;
    border:1px solid #c9e1f8;
}
.notice.error{
    background:#fff5f5;
    border-color:#f0c2c2;
    color:#a33a3a;
}
.notice.success{
    background:#eefaf3;
    border-color:#bde3ca;
    color:#276749;
}
.loading-spinner{
    display:inline-block;
    width:15px;
    height:15px;
    border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite;
    vertical-align:-2px;
    margin-right:7px;
}
@keyframes spin{
    to{transform:rotate(360deg)}
}
.hidden{display:none!important}
.complete{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:8px;
    padding:45px 25px;
    text-align:center;
}
@media(max-width:600px){
    .respondent-wrap{
        width:calc(100% - 20px);
        margin-top:15px;
    }
    .respondent-header,
    .respondent-question,
    .respondent-footer{
        padding:17px;
    }
}
</style>
</head>
<body>
<div class="respondent-wrap">
<?php if ($publicError !== ''): ?>
    <div class="respondent-header">
        <div class="notice error"><?= h($publicError) ?></div>
    </div>
<?php elseif (
    is_array($publicPayload) &&
    bool_value(
        $publicPayload['already_submitted'] ?? false
    )
): ?>
    <div class="complete">
        <h1>回答済みです</h1>
        <p>このアンケートはすでに回答されています。</p>
    </div>
<?php else: ?>
    <div class="respondent-header">
        <h1><?= h($survey['name'] ?? '') ?></h1>
        <?php if (
            string_value(
                $survey['description'] ?? ''
            ) !== ''
        ): ?>
            <div class="respondent-description"><?= h($survey['description'] ?? '') ?></div>
        <?php endif; ?>
        <?php
        $startText =
            string_value(
                $survey['start_at'] ?? ''
            );
        $endText =
            string_value(
                $survey['end_at'] ?? ''
            );
        ?>
        <?php if ($startText !== '' || $endText !== ''): ?>
            <p>
                回答期間：
                <?= h($startText) ?>
                ～
                <?= h($endText) ?>
            </p>
        <?php endif; ?>
    </div>

    <form id="respondent-form">
        <input type="hidden" id="respondent-token" value="<?= h($token) ?>">
        <input type="hidden" id="respondent-survey-id" value="<?= h($surveyId) ?>">

        <?php
        $numberMap =
            question_number_map($survey);
        ?>
        <?php foreach (
            $survey['groups'] ?? []
            as $group
        ): ?>
            <?php if (!is_array($group)) continue; ?>
            <section class="respondent-question">
                <h2 style="font-size:19px;margin:0 0 15px;">
                    <?= h(
                        string_value(
                            $group['name'] ?? ''
                        )
                    ) ?>
                </h2>

                <?php foreach (
                    $group['questions'] ?? []
                    as $question
                ): ?>
                    <?php if (!is_array($question)) continue; ?>

                    <?php
                    $questionId =
                        string_value(
                            $question['id'] ?? ''
                        );
                    $questionType =
                        string_value(
                            $question['type'] ?? 'text'
                        );
                    $required =
                        bool_value(
                            $question['required'] ?? false
                        );
                    ?>

                    <div
                        class="respondent-question"
                        data-question-id="<?= h($questionId) ?>"
                        data-question-type="<?= h($questionType) ?>"
                    >
                        <div class="respondent-question-title">
                            <?= h(
                                $numberMap[$questionId] ?? ''
                            ) ?>
                            <?= h(
                                string_value(
                                    $question['text'] ?? ''
                                )
                            ) ?>
                            <?php if ($required): ?>
                                <span class="required">必須</span>
                            <?php else: ?>
                                <span class="required" style="color:#718096;">任意</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($questionType === 'text'): ?>
                            <textarea
                                class="respondent-input respondent-textarea"
                                data-answer-input="<?= h($questionId) ?>"
                                <?= $required ? 'data-required="1"' : '' ?>
                            ></textarea>
                        <?php elseif ($questionType === 'single'): ?>
                            <?php foreach (
                                $question['options'] ?? []
                                as $option
                            ): ?>
                                <?php if (!is_array($option)) continue; ?>
                                <div class="respondent-option">
                                    <label>
                                        <input
                                            type="radio"
                                            name="answer_<?= h($questionId) ?>"
                                            value="<?= h(
                                                string_value(
                                                    $option['id'] ?? ''
                                                )
                                            ) ?>"
                                            data-answer-input="<?= h($questionId) ?>"
                                            <?= $required ? 'data-required="1"' : '' ?>
                                        >
                                        <span>
                                            <?= h(
                                                string_value(
                                                    $option['text'] ?? ''
                                                )
                                            ) ?>
                                        </span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php foreach (
                                $question['options'] ?? []
                                as $option
                            ): ?>
                                <?php if (!is_array($option)) continue; ?>
                                <div class="respondent-option">
                                    <label>
                                        <input
                                            type="checkbox"
                                            name="answer_<?= h($questionId) ?>[]"
                                            value="<?= h(
                                                string_value(
                                                    $option['id'] ?? ''
                                                )
                                            ) ?>"
                                            data-answer-input="<?= h($questionId) ?>"
                                        >
                                        <span>
                                            <?= h(
                                                string_value(
                                                    $option['text'] ?? ''
                                                )
                                            ) ?>
                                        </span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>

        <div id="respondent-message"></div>

        <div class="respondent-footer">
            <button
                type="submit"
                id="respondent-submit"
                class="respondent-btn"
            >
                回答を送信する
            </button>
        </div>
    </form>
<?php endif; ?>
</div>

<?php if ($publicError === ''): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('respondent-form');
    const submitButton = document.getElementById('respondent-submit');
    const message = document.getElementById('respondent-message');
    const tokenElement = document.getElementById('respondent-token');
    const surveyElement = document.getElementById('respondent-survey-id');

    if (!form || !submitButton || !message || !tokenElement || !surveyElement) {
        return;
    }

    const apiPath = <?= json_encode(
        application_api_path(),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

    const csrfToken = <?= json_encode(
        csrf_token(),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

    function setMessage(text, type) {
        message.textContent = '';
        const box = document.createElement('div');
        box.className = 'notice ' + (type || '');
        box.textContent = text;
        message.appendChild(box);
    }

    function collectAnswers() {
        const answers = {};
        const questionElements =
            form.querySelectorAll('[data-question-id]');

        questionElements.forEach(function (questionElement) {
            const questionId =
                questionElement.getAttribute('data-question-id');

            const questionType =
                questionElement.getAttribute('data-question-type');

            if (!questionId) {
                return;
            }

            if (questionType === 'text') {
                const input =
                    questionElement.querySelector(
                        '[data-answer-input="' +
                        CSS.escape(questionId) +
                        '"]'
                    );

                if (input) {
                    answers[questionId] =
                        input.value || '';
                }

                return;
            }

            if (questionType === 'single') {
                const checked =
                    questionElement.querySelector(
                        'input[type="radio"]:checked'
                    );

                answers[questionId] =
                    checked ? checked.value : '';

                return;
            }

            const checked =
                questionElement.querySelectorAll(
                    'input[type="checkbox"]:checked'
                );

            answers[questionId] =
                Array.from(checked).map(function (input) {
                    return input.value;
                });
        });

        return answers;
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (submitButton.disabled) {
            return;
        }

        const answers = collectAnswers();

        submitButton.disabled = true;
        submitButton.classList.add('loading');
        submitButton.textContent = '';
        const spinner = document.createElement('span');
        spinner.className = 'loading-spinner';
        submitButton.appendChild(spinner);
        submitButton.appendChild(
            document.createTextNode('送信中...')
        );

        setMessage('', '');

        try {
            const response = await fetch(
                apiPath + '?action=save_response',
                {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/json',
                        'X-CSRF-Token':
                            csrfToken
                    },
                    body: JSON.stringify({
                        survey_id:
                            surveyElement.value,
                        token:
                            tokenElement.value,
                        answers: answers
                    })
                }
            );

            const data = await response.json();

            if (!response.ok || !data.success) {
                const errors =
                    data.errors?.answers;

                if (Array.isArray(errors) && errors.length > 0) {
                    setMessage(
                        errors.join('\n'),
                        'error'
                    );
                } else {
                    setMessage(
                        data.message ||
                        '回答を送信できませんでした。',
                        'error'
                    );
                }

                return;
            }

            form.innerHTML = '';

            const complete =
                document.createElement('div');

            complete.className = 'complete';

            const title =
                document.createElement('h1');

            title.textContent =
                '回答ありがとうございました';

            const text =
                document.createElement('p');

            text.textContent =
                '回答を正常に受け付けました。';

            complete.appendChild(title);
            complete.appendChild(text);

            form.appendChild(complete);
        } catch (error) {
            setMessage(
                '通信に失敗しました。時間をおいて再度お試しください。',
                'error'
            );
        } finally {
            submitButton.disabled = false;
            submitButton.classList.remove('loading');
            submitButton.textContent =
                '回答を送信する';
        }
    });
});
</script>
<?php endif; ?>
</body>
</html>
<?php
        exit;
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
 * 運営者画面
 * ========================================================= */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
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
        "YuGothic",
        Meiryo,
        sans-serif;
    color:#263238;
    background:#f4f6f8;
    font-size:14px;
    line-height:1.6;
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

input,
textarea,
select{
    max-width:100%;
}

.hidden{
    display:none!important;
}

.topbar{
    min-height:60px;
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
    display:flex;
    min-height:60px;
    align-items:stretch;
    gap:2px;
}

.main-nav button{
    min-height:60px;
    padding:0 17px;
    color:#dce7f3;
    background:transparent;
    border:0;
    white-space:nowrap;
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

.page{
    width:100%;
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
    line-height:1.35;
}

.subtext{
    color:#718096;
    font-size:13px;
    margin-top:5px;
}

.btn{
    border:1px solid #cbd5e0;
    background:#fff;
    color:#34495e;
    border-radius:5px;
    padding:8px 15px;
    min-height:38px;
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
    border-color:#2068ad;
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

.btn-danger:hover{
    background:#fff5f5;
}

.btn-small{
    min-height:32px;
    padding:5px 10px;
    font-size:12px;
}

.link-button{
    border:0;
    background:none;
    padding:0;
    color:#2878c8;
    cursor:pointer;
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

.notice{
    background:#eef6ff;
    border:1px solid #c9e1f8;
    color:#315a7d;
    border-radius:6px;
    padding:12px 14px;
    margin-bottom:15px;
    white-space:pre-wrap;
}

.notice.success{
    background:#eefaf3;
    border-color:#bde3ca;
    color:#276749;
}

.notice.warning{
    background:#fff8e8;
    border-color:#f1d99b;
    color:#856404;
}

.notice.error{
    background:#fff5f5;
    border-color:#f0c2c2;
    color:#a33a3a;
}

.table-wrap{
    width:100%;
    overflow-x:auto;
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

.table tbody tr:hover td{
    background:#fbfdff;
}

.empty{
    text-align:center!important;
    color:#8a98a5;
    padding:40px!important;
}

.badge{
    display:inline-block;
    padding:3px 8px;
    border-radius:12px;
    font-size:11px;
    line-height:1.4;
    white-space:nowrap;
}

.badge-draft{
    color:#5f6b76;
    background:#edf0f2;
}

.badge-open{
    color:#276749;
    background:#dff5e7;
}

.badge-closed{
    color:#7b3f3f;
    background:#f6dddd;
}

.form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:15px;
}

.field{
    margin-bottom:15px;
}

.field label{
    display:block;
    font-weight:bold;
    margin-bottom:6px;
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
    min-height:110px;
    resize:vertical;
}

.field-note{
    color:#718096;
    font-size:12px;
    margin-top:5px;
}

.required{
    color:#c53f3f;
}

.action-row{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
}

.detail-tabs{
    display:flex;
    gap:3px;
    border-bottom:1px solid #dfe5eb;
    margin-bottom:18px;
    overflow-x:auto;
}

.detail-tabs button{
    border:0;
    background:transparent;
    padding:11px 15px;
    color:#5b6b79;
    white-space:nowrap;
}

.detail-tabs button.active{
    color:#1f3a5f;
    font-weight:bold;
    border-bottom:3px solid #2878c8;
}

.dashboard-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
}

.stat-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:18px;
}

.stat-label{
    color:#718096;
    font-size:12px;
}

.stat-value{
    font-size:28px;
    font-weight:bold;
    margin-top:4px;
}

.stat-unit{
    font-size:13px;
    margin-left:3px;
    font-weight:normal;
}

.group-card{
    border:1px solid #d8e0e8;
    border-radius:7px;
    background:#fbfcfd;
    padding:16px;
    margin-bottom:15px;
}

.group-card.dragging{
    opacity:.55;
}

.group-head{
    display:flex;
    gap:8px;
    align-items:center;
    margin-bottom:14px;
}

.drag-handle{
    cursor:grab;
    color:#8a98a5;
    font-size:18px;
}

.group-name-input{
    flex:1;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:8px 10px;
    font-weight:bold;
}

.question-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:6px;
    padding:15px;
    margin-bottom:10px;
}

.question-card.dragging{
    opacity:.55;
}

.question-head{
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom:10px;
}

.question-number{
    width:70px;
    color:#52606d;
    font-weight:bold;
}

.question-title-input{
    flex:1;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:8px 10px;
}

.question-controls{
    display:grid;
    grid-template-columns:1fr 160px 100px;
    gap:8px;
    align-items:start;
}

.question-controls select,
.question-controls input{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:8px 10px;
}

.option-list{
    margin-top:10px;
    padding-left:78px;
}

.option-row{
    display:grid;
    grid-template-columns:30px 1fr 230px 70px;
    gap:7px;
    align-items:center;
    margin-bottom:7px;
}

.option-row input,
.option-row select{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:7px 8px;
}

.option-row .option-marker{
    color:#718096;
    text-align:center;
}

.branch-invalid{
    border-color:#c53f3f!important;
    background:#fff5f5!important;
}

.add-row{
    margin-top:10px;
    padding-left:78px;
}

.preview-group{
    margin-bottom:20px;
}

.preview-group-title{
    font-size:17px;
    font-weight:bold;
    padding-bottom:8px;
    border-bottom:2px solid #dfe5eb;
    margin-bottom:8px;
}

.preview-question{
    padding:12px 4px;
    border-bottom:1px solid #edf1f4;
}

.preview-question-title{
    font-weight:bold;
    margin-bottom:5px;
}

.preview-option{
    padding-left:12px;
    color:#52606d;
}

.send-layout{
    display
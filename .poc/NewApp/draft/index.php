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

if (
    !isset($_SESSION[APP_SESSION_KEY]) ||
    !is_array($_SESSION[APP_SESSION_KEY])
) {
    $_SESSION[APP_SESSION_KEY] = [];
}

if (
    !isset($_SESSION[APP_SESSION_KEY]['csrf_token']) ||
    !is_string($_SESSION[APP_SESSION_KEY]['csrf_token']) ||
    strlen($_SESSION[APP_SESSION_KEY]['csrf_token']) !== 64
) {
    $_SESSION[APP_SESSION_KEY]['csrf_token'] =
        bin2hex(random_bytes(32));
}

/**
 * NULL安全なHTMLエスケープ。
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
 * JSONレスポンス。
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

/**
 * CSRFトークン取得。
 */
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

/**
 * リクエスト方式。
 */
function request_method(): string
{
    return strtoupper(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
    );
}

/**
 * action取得。
 */
function requested_action(): string
{
    $action = $_GET['action'] ?? '';

    return is_string($action)
        ? trim($action)
        : '';
}

/**
 * JSONリクエストを読み込む。
 */
function read_json_request(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        json_response(
            false,
            '入力内容を確認してください。',
            [],
            ['request' => 'JSON形式が正しくありません。'],
            400
        );
    }

    return $decoded;
}

/**
 * 状態変更時のCSRF確認。
 */
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

/**
 * データ保存先を作成。
 */
function ensure_data_directory(): void
{
    if (is_dir(DATA_DIRECTORY)) {
        return;
    }

    if (
        !mkdir(DATA_DIRECTORY, 0700, true) &&
        !is_dir(DATA_DIRECTORY)
    ) {
        json_response(
            false,
            'データ保存領域を作成できません。',
            [],
            [],
            500
        );
    }
}

/**
 * 初期設定。
 */
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

/**
 * 顧客初期値。
 */
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

/**
 * アンケート初期値。
 */
function default_surveys(): array
{
    return [
        'version' => 1,
        'updated_at' => null,
        'surveys' => []
    ];
}

/**
 * 回答初期値。
 */
function default_responses(): array
{
    return [
        'version' => 1,
        'updated_at' => null,
        'responses' => []
    ];
}

/**
 * メールログ初期値。
 */
function default_mail_logs(): array
{
    return [
        'version' => 1,
        'updated_at' => null,
        'logs' => []
    ];
}

/**
 * JSONファイル読み込み。
 */
function read_json_file(
    string $file,
    array $default
): array {
    ensure_data_directory();

    if (!file_exists($file)) {
        return $default;
    }

    $contents = file_get_contents($file);

    if ($contents === false || trim($contents) === '') {
        return $default;
    }

    $decoded = json_decode($contents, true);

    if (!is_array($decoded)) {
        json_response(
            false,
            '保存されているデータを読み込めません。',
            [],
            [],
            500
        );
    }

    return $decoded;
}

/**
 * JSONファイル保存。
 */
function write_json_file(
    string $file,
    array $data
): void {
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

    $temporary = tempnam(
        DATA_DIRECTORY,
        'newapp_'
    );

    if ($temporary === false) {
        json_response(
            false,
            'データを保存できません。',
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
            'データを保存できません。',
            [],
            [],
            500
        );
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new \RuntimeException(
                'lock'
            );
        }

        $length = strlen($json);
        $written = fwrite(
            $handle,
            $json
        );

        if (
            $written === false ||
            $written !== $length
        ) {
            throw new \RuntimeException(
                'write'
            );
        }

        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        if (!rename($temporary, $file)) {
            throw new \RuntimeException(
                'rename'
            );
        }
    } catch (\Throwable $e) {
        if (is_resource($handle)) {
            @flock(
                $handle,
                LOCK_UN
            );
            @fclose($handle);
        }

        @unlink($temporary);

        json_response(
            false,
            'データを保存できません。',
            [],
            [],
            500
        );
    }
}

/**
 * 初期ファイル作成。
 */
function initialize_data_files(): void
{
    ensure_data_directory();

    if (!file_exists(SETTINGS_FILE)) {
        write_json_file(
            SETTINGS_FILE,
            default_settings()
        );
    }

    if (!file_exists(CUSTOMERS_FILE)) {
        write_json_file(
            CUSTOMERS_FILE,
            default_customers()
        );
    }

    if (!file_exists(SURVEYS_FILE)) {
        write_json_file(
            SURVEYS_FILE,
            default_surveys()
        );
    }

    if (!file_exists(RESPONSES_FILE)) {
        write_json_file(
            RESPONSES_FILE,
            default_responses()
        );
    }

    if (!file_exists(MAIL_LOGS_FILE)) {
        write_json_file(
            MAIL_LOGS_FILE,
            default_mail_logs()
        );
    }
}

function load_settings(): array
{
    return read_json_file(
        SETTINGS_FILE,
        default_settings()
    );
}

function load_customers(): array
{
    return read_json_file(
        CUSTOMERS_FILE,
        default_customers()
    );
}

function load_surveys(): array
{
    return read_json_file(
        SURVEYS_FILE,
        default_surveys()
    );
}

function load_responses(): array
{
    return read_json_file(
        RESPONSES_FILE,
        default_responses()
    );
}

function load_mail_logs(): array
{
    return read_json_file(
        MAIL_LOGS_FILE,
        default_mail_logs()
    );
}

/**
 * 現在時刻。
 */
function now(): string
{
    return date('c');
}

/**
 * 識別子生成。
 */
function generate_id(string $prefix): string
{
    return $prefix .
        '_' .
        date('YmdHis') .
        '_' .
        bin2hex(
            random_bytes(4)
        );
}

/**
 * 自身のAPIパス。
 */
function application_api_path(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? 'index.php';

    if (
        !is_string($script) ||
        $script === ''
    ) {
        return 'index.php';
    }

    return $script;
}

/* =========================================================
 * kintone
 * ========================================================= */

/**
 * kintone URLを正規化。
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

    $domain = rtrim(
        $domain,
        '/'
    );

    $endpoint =
        '/' .
        ltrim(
            $endpoint,
            '/'
        );

    return
        'https://' .
        $domain .
        '.cybozu.com' .
        $endpoint;
}

/**
 * Cybozu認証ヘッダー。
 */
function make_cybozu_auth_header(
    string $login_name,
    string $password
): string {
    $login_name = trim(
        $login_name
    );

    $password = trim(
        $password
    );

    return
        'X-Cybozu-Authorization: ' .
        base64_encode(
            $login_name .
            ':' .
            $password
        );
}

/**
 * HTTPレスポンスヘッダー取得。
 */
function get_safe_response_headers(): array
{
    if (
        function_exists(
            'http_get_last_response_headers'
        )
    ) {
        $headers =
            http_get_last_response_headers();

        if (is_array($headers)) {
            return $headers;
        }
    }

    return [];
}

/**
 * HTTPステータス取得。
 */
function extract_http_status(
    array $headers
): int {
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

/**
 * kintone API共通通信。
 *
 * cURLは使用しない。
 */
function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    array $payload,
    array $config
): array {
    $method = strtoupper(
        $method
    );

    $http = [
        'method' => $method,
        'header' =>
            implode(
                "\r\n",
                $headers
            ),
        'ignore_errors' => true,
        'timeout' => 20
    ];

    if ($method !== 'GET' && $payload !== []) {
        try {
            $http['content'] =
                json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES |
                    JSON_THROW_ON_ERROR
                );
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 0,
                'message' =>
                    '通信データを作成できませんでした。',
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

    /*
     * プロキシ設定。
     * hostとportの両方を受け取れる。
     */
    $proxy_host = trim(
        (string)(
            $config['proxy_host'] ?? ''
        )
    );

    $proxy_port = trim(
        (string)(
            $config['proxy_port'] ?? ''
        )
    );

    if ($proxy_host !== '') {
        $proxy = $proxy_host;

        if ($proxy_port !== '') {
            $proxy .= ':' . $proxy_port;
        }

        $context_options['http']['proxy'] =
            'tcp://' . $proxy;

        $context_options['http']
            ['request_fulluri'] = true;
    } else {
        $context_options['http']
            ['request_fulluri'] = false;
    }

    $context = stream_context_create(
        $context_options
    );

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    $response_headers =
        get_safe_response_headers();

    $status =
        extract_http_status(
            $response_headers
        );

    $decoded = [];

    if (
        $response !== false &&
        trim($response) !== ''
    ) {
        $decoded_value =
            json_decode(
                $response,
                true
            );

        if (is_array($decoded_value)) {
            $decoded = $decoded_value;
        }
    }

    if (
        $status >= 200 &&
        $status < 300
    ) {
        return [
            'success' => true,
            'status' => $status,
            'message' => '',
            'data' => $decoded
        ];
    }

    $message =
        'kintoneとの通信に失敗しました。';

    if (
        isset($decoded['message']) &&
        is_string($decoded['message']) &&
        $decoded['message'] !== ''
    ) {
        $message =
            $decoded['message'];
    }

    $errors = [];

    if (
        isset($decoded['errors']) &&
        is_array($decoded['errors'])
    ) {
        foreach (
            $decoded['errors']
            as $field => $error
        ) {
            if (!is_array($error)) {
                continue;
            }

            $messages =
                $error['messages'] ?? [];

            if (!is_array($messages)) {
                continue;
            }

            foreach (
                $messages as $error_message
            ) {
                if (
                    is_string(
                        $error_message
                    )
                ) {
                    $errors[] =
                        (string)$field .
                        ': ' .
                        $error_message;
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

/**
 * kintone接続テスト。
 */
function test_kintone(
    array $settings
): array {
    $domain = trim(
        (string)(
            $settings['domain'] ?? ''
        )
    );

    $login = trim(
        (string)(
            $settings['login_name'] ?? ''
        )
    );

    $password = (string)(
        $settings['password'] ?? ''
    );

    $app_id = trim(
        (string)(
            $settings['customer_app_id'] ?? ''
        )
    );

    if (
        $domain === '' ||
        $login === '' ||
        $password === ''
    ) {
        return [
            'success' => false,
            'status' => 400,
            'message' =>
                'kintoneのドメイン、ログイン名、パスワードを入力してください。',
            'data' => []
        ];
    }

    $headers = [
        make_cybozu_auth_header(
            $login,
            $password
        ),
        'Accept: application/json'
    ];

    if ($app_id !== '') {
        $params = [
            'id' => $app_id
        ];

        $endpoint =
            '/k/v1/app.json?' .
            http_build_query(
                $params,
                '',
                '&',
                PHP_QUERY_RFC3986
            );
    } else {
        $params = [
            'limit' => 1
        ];

        $endpoint =
            '/k/v1/apps.json?' .
            http_build_query(
                $params,
                '',
                '&',
                PHP_QUERY_RFC3986
            );
    }

    $url =
        kintone_build_url(
            $domain,
            $endpoint
        );

    return kintone_api_request(
        'GET',
        $url,
        $headers,
        [],
        [
            'proxy_host' =>
                trim(
                    (string)(
                        $settings['proxy_host'] ?? ''
                    )
                ),
            'proxy_port' =>
                trim(
                    (string)(
                        $settings['proxy_port'] ?? ''
                    )
                )
        ]
    );
}

/**
 * kintoneから顧客を取得。
 */
function fetch_kintone_customers(
    array $settings
): array {
    $domain = trim(
        (string)(
            $settings['domain'] ?? ''
        )
    );

    $login = trim(
        (string)(
            $settings['login_name'] ?? ''
        )
    );

    $password = (string)(
        $settings['password'] ?? ''
    );

    $app_id = trim(
        (string)(
            $settings['customer_app_id'] ?? ''
        )
    );

    if (
        $domain === '' ||
        $login === '' ||
        $password === '' ||
        $app_id === ''
    ) {
        return [
            'success' => false,
            'status' => 400,
            'message' =>
                'kintone設定が不足しています。',
            'data' => []
        ];
    }

    $headers = [
        make_cybozu_auth_header(
            $login,
            $password
        ),
        'Accept: application/json'
    ];

    $params = [
        'app' => $app_id,
        'totalCount' => 'true',
        'query' =>
            'order by $id asc limit 500'
    ];

    $query =
        http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

    $url =
        kintone_build_url(
            $domain,
            '/k/v1/records.json?' . $query
        );

    $result =
        kintone_api_request(
            'GET',
            $url,
            $headers,
            [],
            [
                'proxy_host' =>
                    trim(
                        (string)(
                            $settings['proxy_host'] ?? ''
                        )
                    ),
                'proxy_port' =>
                    trim(
                        (string)(
                            $settings['proxy_port'] ?? ''
                        )
                    )
            ]
        );

    if (!$result['success']) {
        return $result;
    }

    $records =
        $result['data']['records'] ?? [];

    if (!is_array($records)) {
        $records = [];
    }

    $id_field = trim(
        (string)(
            $settings['customer_id_field'] ?? ''
        )
    );

    $name_field = trim(
        (string)(
            $settings['customer_name_field'] ?? ''
        )
    );

    $email_field = trim(
        (string)(
            $settings['customer_email_field'] ?? ''
        )
    );

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
            isset(
                $record[$id_field]['value']
            )
        ) {
            $id =
                (string)$record[
                    $id_field
                ]['value'];
        }

        if (
            $name_field !== '' &&
            isset(
                $record[$name_field]['value']
            )
        ) {
            $name =
                (string)$record[
                    $name_field
                ]['value'];
        }

        if (
            $email_field !== '' &&
            isset(
                $record[$email_field]['value']
            )
        ) {
            $email =
                (string)$record[
                    $email_field
                ]['value'];
        }

        $customers[] = [
            'id' =>
                $id !== ''
                    ? $id
                    : generate_id('customer'),
            'name' => $name,
            'email' => $email,
            'company' => '',
            'code' => $id
        ];
    }

    return [
        'success' => true,
        'message' =>
            '顧客一覧を更新しました。',
        'customers' => $customers
    ];
}

/* =========================================================
 * アンケートデータ
 * ========================================================= */

/**
 * 質問を正規化。
 */
function normalize_question(
    array $question
): array {
    $type =
        (string)(
            $question['type'] ?? 'free'
        );

    if (
        !in_array(
            $type,
            [
                'free',
                'single',
                'multiple'
            ],
            true
        )
    ) {
        $type = 'free';
    }

    $options = [];

    if (
        isset($question['options']) &&
        is_array(
            $question['options']
        )
    ) {
        foreach (
            $question['options']
            as $option
        ) {
            if (!is_array($option)) {
                continue;
            }

            $options[] = [
                'text' =>
                    (string)(
                        $option['text'] ?? ''
                    ),
                'branch' =>
                    (string)(
                        $option['branch'] ?? ''
                    )
            ];
        }
    }

    return [
        'id' =>
            (string)(
                $question['id'] ??
                generate_id('q')
            ),
        'text' =>
            (string)(
                $question['text'] ?? ''
            ),
        'type' => $type,
        'required' =>
            !empty(
                $question['required']
            ),
        'options' => $options
    ];
}

/**
 * アンケートを正規化。
 */
function normalize_survey(
    array $survey
): array {
    $groups = [];

    if (
        isset($survey['groups']) &&
        is_array(
            $survey['groups']
        )
    ) {
        foreach (
            $survey['groups']
            as $group
        ) {
            if (!is_array($group)) {
                continue;
            }

            $questions = [];

            if (
                isset($group['questions']) &&
                is_array(
                    $group['questions']
                )
            ) {
                foreach (
                    $group['questions']
                    as $question
                ) {
                    if (is_array($question)) {
                        $questions[] =
                            normalize_question(
                                $question
                            );
                    }
                }
            }

            $groups[] = [
                'id' =>
                    (string)(
                        $group['id'] ??
                        generate_id('g')
                    ),
                'name' =>
                    (string)(
                        $group['name'] ??
                        'グループ'
                    ),
                'questions' =>
                    $questions
            ];
        }
    }

    $status =
        (string)(
            $survey['status'] ??
            'draft'
        );

    if (
        !in_array(
            $status,
            [
                'draft',
                'open',
                'end'
            ],
            true
        )
    ) {
        $status = 'draft';
    }

    $numbering =
        (string)(
            $survey['numbering'] ??
            'global'
        );

    if (
        !in_array(
            $numbering,
            [
                'global',
                'group'
            ],
            true
        )
    ) {
        $numbering = 'global';
    }

    return [
        'id' =>
            (string)(
                $survey['id'] ??
                generate_id('survey')
            ),
        'name' =>
            trim(
                (string)(
                    $survey['name'] ?? ''
                )
            ),
        'description' =>
            (string)(
                $survey['description'] ?? ''
            ),
        'status' => $status,
        'created' =>
            (string)(
                $survey['created'] ??
                date('Y-m-d')
            ),
        'start' =>
            (string)(
                $survey['start'] ?? ''
            ),
        'end' =>
            (string)(
                $survey['end'] ?? ''
            ),
        'answers' =>
            (int)(
                $survey['answers'] ?? 0
            ),
        'target' =>
            (int)(
                $survey['target'] ?? 0
            ),
        'sent' =>
            (int)(
                $survey['sent'] ?? 0
            ),
        'updated' =>
            (string)(
                $survey['updated'] ??
                date('Y-m-d')
            ),
        'numbering' =>
            $numbering,
        'groups' => $groups
    ];
}

/**
 * アンケート検索。
 */
function survey_find(
    array $surveys,
    string $id
): ?array {
    foreach (
        $surveys as $survey
    ) {
        if (
            is_array($survey) &&
            (string)(
                $survey['id'] ?? ''
            ) === $id
        ) {
            return $survey;
        }
    }

    return null;
}

/**
 * アンケート位置検索。
 */
function survey_index(
    array $surveys,
    string $id
): int {
    foreach (
        $surveys as $index => $survey
    ) {
        if (
            is_array($survey) &&
            (string)(
                $survey['id'] ?? ''
            ) === $id
        ) {
            return (int)$index;
        }
    }

    return -1;
}

/**
 * アンケート回答数。
 */
function calculate_survey_counts(
    array $survey,
    array $responses
): array {
    $survey_id =
        (string)(
            $survey['id'] ?? ''
        );

    $count = 0;

    foreach (
        $responses as $response
    ) {
        if (!is_array($response)) {
            continue;
        }

        if (
            (string)(
                $response['survey_id'] ?? ''
            ) === $survey_id
        ) {
            $count++;
        }
    }

    return [
        'answers' => $count
    ];
}

/**
 * 状態変更を伴う操作か確認。
 */
function action_requires_csrf(
    string $action
): bool {
    $read_actions = [
        'load_settings',
        'get_settings',
        'load_customers',
        'list_customers',
        'load_surveys',
        'list_surveys',
        'load_survey',
        'get_survey',
        'get_aggregate'
    ];

    return !in_array(
        $action,
        $read_actions,
        true
    );
}

/* =========================================================
 * API
 * ========================================================= */

initialize_data_files();

$action = requested_action();

if ($action !== '') {
    if (
        request_method() !== 'GET' ||
        action_requires_csrf($action)
    ) {
        validate_csrf();
    }

    switch ($action) {
        case 'load_settings':
        case 'get_settings':
            $settings =
                load_settings();

            if (
                isset($settings['mail']) &&
                is_array($settings['mail'])
            ) {
                $settings['mail']['password'] = '';
            }

            if (
                isset($settings['kintone']) &&
                is_array($settings['kintone'])
            ) {
                $settings['kintone']['password'] = '';
            }

            json_response(
                true,
                '設定を取得しました。',
                [
                    'settings' =>
                        $settings
                ]
            );

        case 'load_customers':
        case 'list_customers':
            $customers =
                load_customers();

            json_response(
                true,
                '顧客一覧を取得しました。',
                [
                    'customers' =>
                        is_array(
                            $customers['customers']
                            ?? null
                        )
                            ? $customers['customers']
                            : []
                ]
            );

        case 'refresh_customers':
            $settings =
                load_settings();

            $result =
                fetch_kintone_customers(
                    $settings['kintone'] ?? []
                );

            if (
                !($result['success'] ?? false)
            ) {
                json_response(
                    false,
                    (string)(
                        $result['message'] ??
                        '顧客一覧の取得に失敗しました。'
                    ),
                    [],
                    isset(
                        $result['errors']
                    ) &&
                    is_array(
                        $result['errors']
                    )
                        ? $result['errors']
                        : [],
                    400
                );
            }

            $customers =
                isset(
                    $result['customers']
                ) &&
                is_array(
                    $result['customers']
                )
                    ? $result['customers']
                    : [];

            write_json_file(
                CUSTOMERS_FILE,
                [
                    'version' => 1,
                    'updated_at' => now(),
                    'source' => 'kintone',
                    'count' =>
                        count($customers),
                    'customers' =>
                        $customers
                ]
            );

            json_response(
                true,
                '顧客一覧を更新しました。',
                [
                    'customers' =>
                        $customers
                ]
            );

        case 'test_kintone':
            $input =
                read_json_request();

            $result =
                test_kintone(
                    [
                        'domain' =>
                            (string)(
                                $input['domain'] ?? ''
                            ),
                        'login_name' =>
                            (string)(
                                $input['login_name'] ?? ''
                            ),
                        'password' =>
                            (string)(
                                $input['password'] ?? ''
                            ),
                        'customer_app_id' =>
                            (string)(
                                $input['customer_app_id'] ?? ''
                            ),
                        'proxy_host' =>
                            (string)(
                                $input['proxy_host'] ?? ''
                            ),
                        'proxy_port' =>
                            (string)(
                                $input['proxy_port'] ?? ''
                            )
                    ]
                );

            if (
                !($result['success'] ?? false)
            ) {
                json_response(
                    false,
                    (string)(
                        $result['message'] ??
                        'kintone接続に失敗しました。'
                    ),
                    [],
                    isset(
                        $result['errors']
                    ) &&
                    is_array(
                        $result['errors']
                    )
                        ? $result['errors']
                        : [],
                    400
                );
            }

            json_response(
                true,
                'kintoneへの接続を確認しました。'
            );

        case 'save_kintone_settings':
            $input =
                read_json_request();

            $settings =
                load_settings();

            $old_password =
                (string)(
                    $settings['kintone']['password']
                    ?? ''
                );

            $new_password =
                (string)(
                    $input['password'] ?? ''
                );

            if ($new_password === '') {
                $new_password =
                    $old_password;
            }

            $settings['kintone'] = [
                'domain' =>
                    trim(
                        (string)(
                            $input['domain'] ?? ''
                        )
                    ),
                'login_name' =>
                    trim(
                        (string)(
                            $input['login_name'] ?? ''
                        )
                    ),
                'password' =>
                    $new_password,
                'customer_app_id' =>
                    trim(
                        (string)(
                            $input['customer_app_id'] ?? ''
                        )
                    ),
                'customer_id_field' =>
                    trim(
                        (string)(
                            $input['customer_id_field'] ?? ''
                        )
                    ),
                'customer_name_field' =>
                    trim(
                        (string)(
                            $input['customer_name_field'] ?? ''
                        )
                    ),
                'customer_email_field' =>
                    trim(
                        (string)(
                            $input['customer_email_field'] ?? ''
                        )
                    ),
                'proxy_host' =>
                    trim(
                        (string)(
                            $input['proxy_host'] ?? ''
                        )
                    ),
                'proxy_port' =>
                    trim(
                        (string)(
                            $input['proxy_port'] ?? ''
                        )
                    ),
                'configured' => true,
                'connection_status' =>
                    (string)(
                        $settings['kintone']
                        ['connection_status']
                        ?? 'not_configured'
                    ),
                'tested_at' =>
                    $settings['kintone']
                    ['tested_at']
                    ?? null
            ];

            $settings['updated_at'] =
                now();

            write_json_file(
                SETTINGS_FILE,
                $settings
            );

            json_response(
                true,
                'kintone設定を保存しました。'
            );

        case 'save_mail_settings':
            $input =
                read_json_request();

            $settings =
                load_settings();

            $old_password =
                (string)(
                    $settings['mail']['password']
                    ?? ''
                );

            $new_password =
                (string)(
                    $input['password'] ?? ''
                );

            if ($new_password === '') {
                $new_password =
                    $old_password;
            }

            $smtp_server =
                trim(
                    (string)(
                        $input['smtp_server'] ?? ''
                    )
                );

            $from_email =
                trim(
                    (string)(
                        $input['from_email'] ?? ''
                    )
                );

            if ($smtp_server === '') {
                json_response(
                    false,
                    'SMTPサーバーを入力してください。',
                    [],
                    [],
                    400
                );
            }

            if ($from_email === '') {
                json_response(
                    false,
                    '送信元メールアドレスを入力してください。',
                    [],
                    [],
                    400
                );
            }

            $settings['mail'] = [
                'smtp_server' =>
                    $smtp_server,
                'smtp_port' =>
                    (int)(
                        $input['smtp_port'] ?? 587
                    ),
                'connection_type' =>
                    trim(
                        (string)(
                            $input['connection_type']
                            ?? 'tls'
                        )
                    ),
                'username' =>
                    trim(
                        (string)(
                            $input['username'] ?? ''
                        )
                    ),
                'password' =>
                    $new_password,
                'from_email' =>
                    $from_email,
                'from_name' =>
                    trim(
                        (string)(
                            $input['from_name']
                            ?? 'アンケート事務局'
                        )
                    ),
                'configured' => true,
                'tested_at' =>
                    $settings['mail']
                    ['tested_at']
                    ?? null
            ];

            $settings['updated_at'] =
                now();

            write_json_file(
                SETTINGS_FILE,
                $settings
            );

            json_response(
                true,
                'メール送信設定を保存しました。'
            );

        case 'test_mail_settings':
            $input =
                read_json_request();

            $smtp_server =
                trim(
                    (string)(
                        $input['smtp_server'] ?? ''
                    )
                );

            $from_email =
                trim(
                    (string)(
                        $input['from_email'] ?? ''
                    )
                );

            if (
                $smtp_server === '' ||
                $from_email === ''
            ) {
                json_response(
                    false,
                    'SMTPサーバーと送信元メールアドレスを入力してください。',
                    [],
                    [],
                    400
                );
            }

            json_response(
                true,
                'メール設定を確認しました。'
            );

        case 'list_surveys':
        case 'load_surveys':
            $survey_data =
                load_surveys();

            $response_data =
                load_responses();

            $surveys =
                $survey_data['surveys']
                ?? [];

            $responses =
                $response_data['responses']
                ?? [];

            if (!is_array($surveys)) {
                $surveys = [];
            }

            if (!is_array($responses)) {
                $responses = [];
            }

            foreach (
                $surveys as &$survey
            ) {
                if (!is_array($survey)) {
                    continue;
                }

                $counts =
                    calculate_survey_counts(
                        $survey,
                        $responses
                    );

                $survey['answers'] =
                    $counts['answers'];
            }

            unset($survey);

            json_response(
                true,
                'アンケート一覧を取得しました。',
                [
                    'surveys' =>
                        $surveys
                ]
            );

        case 'get_survey':
        case 'load_survey':
            $id =
                trim(
                    (string)(
                        $_GET['id'] ?? ''
                    )
                );

            if ($id === '') {
                json_response(
                    false,
                    'アンケートを指定してください。',
                    [],
                    [],
                    400
                );
            }

            $survey_data =
                load_surveys();

            $survey =
                survey_find(
                    $survey_data['surveys']
                    ?? [],
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
                [
                    'survey' =>
                        $survey
                ]
            );

        case 'save_survey':
            $input =
                read_json_request();

            $survey_input =
                $input['survey']
                ?? null;

            if (!is_array($survey_input)) {
                json_response(
                    false,
                    'アンケート内容を確認してください。',
                    [],
                    [],
                    400
                );
            }

            $survey =
                normalize_survey(
                    $survey_input
                );

            if (
                $survey['name'] === ''
            ) {
                json_response(
                    false,
                    'アンケート名を入力してください。',
                    [],
                    [],
                    400
                );
            }

            if (
                $survey['groups'] === []
            ) {
                json_response(
                    false,
                    'グループを1つ以上作成してください。',
                    [],
                    [],
                    400
                );
            }

            foreach (
                $survey['groups']
                as $group
            ) {
                if (
                    trim(
                        (string)(
                            $group['name'] ?? ''
                        )
                    ) === ''
                ) {
                    json_response(
                        false,
                        'グループ名を入力してください。',
                        [],
                        [],
                        400
                    );
                }
            }

            $data =
                load_surveys();

            if (
                !isset($data['surveys']) ||
                !is_array(
                    $data['surveys']
                )
            ) {
                $data['surveys'] = [];
            }

            $index =
                survey_index(
                    $data['surveys'],
                    $survey['id']
                );

            if ($index >= 0) {
                $old =
                    $data['surveys'][$index];

                if (is_array($old)) {
                    $survey['created'] =
                        (string)(
                            $old['created']
                            ?? $survey['created']
                        );
                }

                $survey['updated'] =
                    date('Y-m-d');

                $data['surveys'][$index] =
                    $survey;
            } else {
                $survey['created'] =
                    date('Y-m-d');

                $survey['updated'] =
                    date('Y-m-d');

                $data['surveys'][] =
                    $survey;
            }

            $data['updated_at'] =
                now();

            write_json_file(
                SURVEYS_FILE,
                $data
            );

            json_response(
                true,
                'アンケートを保存しました。',
                [
                    'survey' =>
                        $survey
                ]
            );

        case 'delete_survey':
            $input =
                read_json_request();

            $id =
                trim(
                    (string)(
                        $input['id'] ?? ''
                    )
                );

            if ($id === '') {
                json_response(
                    false,
                    'アンケートを指定してください。',
                    [],
                    [],
                    400
                );
            }

            $data =
                load_surveys();

            $index =
                survey_index(
                    $data['surveys'] ?? [],
                    $id
                );

            if ($index < 0) {
                json_response(
                    false,
                    '指定されたアンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            $survey =
                $data['surveys'][$index];

            if (
                is_array($survey) &&
                (string)(
                    $survey['status'] ?? 'draft'
                ) !== 'draft'
            ) {
                json_response(
                    false,
                    '公開済みまたは終了済みのアンケートは削除できません。',
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

            $data['updated_at'] =
                now();

            write_json_file(
                SURVEYS_FILE,
                $data
            );

            json_response(
                true,
                'アンケートを削除しました。'
            );

        case 'publish_survey':
        case 'end_survey':
            $input =
                read_json_request();

            $id =
                trim(
                    (string)(
                        $input['id'] ?? ''
                    )
                );

            if ($id === '') {
                json_response(
                    false,
                    'アンケートを指定してください。',
                    [],
                    [],
                    400
                );
            }

            $data =
                load_surveys();

            $index =
                survey_index(
                    $data['surveys'] ?? [],
                    $id
                );

            if ($index < 0) {
                json_response(
                    false,
                    '指定されたアンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            if (
                $action === 'publish_survey'
            ) {
                $data['surveys'][$index]
                    ['status'] = 'open';
            } else {
                $data['surveys'][$index]
                    ['status'] = 'end';
            }

            $data['surveys'][$index]
                ['updated'] =
                    date('Y-m-d');

            $data['updated_at'] =
                now();

            write_json_file(
                SURVEYS_FILE,
                $data
            );

            json_response(
                true,
                $action === 'publish_survey'
                    ? 'アンケートを公開しました。'
                    : 'アンケートを終了しました。',
                [
                    'survey' =>
                        $data['surveys'][$index]
                ]
            );

        default:
            json_response(
                false,
                '指定された処理は存在しません。',
                [],
                [
                    'action' => $action
                ],
                404
            );
    }
}

/* =========================================================
 * HTML
 * ========================================================= */

$api_path =
    application_api_path();

$csrf =
    csrf_token();

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1.0">
<meta name="csrf-token"
      content="<?= h($csrf) ?>">
<meta name="api-path"
      content="<?= h($api_path) ?>">
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
    background: #f4f6f8;
    color: #263238;
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

.hidden {
    display: none !important;
}

.topbar {
    min-height: 60px;
    background: #1f3a5f;
    color: #fff;
    display: flex;
    align-items: center;
    padding: 0 24px;
    gap: 28px;
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
    padding: 0 16px;
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

.app {
    max-width: 1440px;
    margin: 0 auto;
    padding: 24px;
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

.btn-danger {
    border-color: #e05a5a;
    color: #c53f3f;
    background: #fff;
}

.btn-small {
    min-height: 32px;
    padding: 5px 10px;
    font-size: 12px;
}

.loading-spinner {
    display: none;
    width: 14px;
    height: 14px;
    margin-right: 6px;
    border: 2px solid rgba(255,255,255,.45);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin .7s linear infinite;
    vertical-align: -2px;
}

.btn.loading .loading-spinner {
    display: inline-block;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

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

.badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 11px;
    line-height: 1.4;
    white-space: nowrap;
}

.badge-draft {
    color: #5f6b76;
    background: #edf0f2;
}

.badge-open {
    color: #276749;
    background: #dff5e7;
}

.badge-end {
    color: #7b3f3f;
    background: #f6dddd;
}

.link-button {
    border: 0;
    background: none;
    padding: 0;
    color: #2878c8;
    cursor: pointer;
    text-align: left;
}

.link-button:hover {
    text-decoration: underline;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.field {
    margin-bottom: 15px;
}

.field label {
    display: block;
    font-weight: bold;
    margin-bottom: 6px;
    color: #465765;
}

.field input,
.field textarea,
.field select {
    width: 100%;
    border: 1px solid #cbd5e0;
    border-radius: 5px;
    padding: 9px;
    background: #fff;
}

.field textarea {
    min-height: 100px;
    resize: vertical;
}

.radio-row {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.radio-row label {
    font-weight: normal;
}

.group-card {
    background: #fff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    margin-bottom: 18px;
    overflow: hidden;
}

.group-card.dragging {
    opacity: .45;
}

.group-card.drag-over {
    border-top: 3px solid #2878c8;
}

.group-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 15px;
    background: #f8fafc;
    border-bottom: 1px solid #e6ebef;
}

.drag-handle {
    cursor: grab;
    color: #718096;
    font-size: 18px;
    user-select: none;
}

.group-title {
    flex: 1;
}

.group-title input {
    width: 100%;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 7px 9px;
    font-weight: bold;
}

.question-card {
    margin: 14px;
    border: 1px solid #e3e8ed;
    border-radius: 6px;
    background: #fff;
}

.question-card.dragging {
    opacity: .45;
}

.question-card.drag-over {
    border-top: 3px solid #2878c8;
}

.question-head {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 13px;
    background: #fafbfc;
    border-bottom: 1px solid #e8edf1;
}

.question-title {
    flex: 1;
    font-weight: bold;
}

.question-body {
    padding: 14px;
}

.question-meta {
    display: grid;
    grid-template-columns: 1fr 170px 120px;
    gap: 10px;
    align-items: end;
}

.question-options {
    margin-top: 12px;
}

.option-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 7px;
}

.option-row input {
    flex: 1;
}

.option-row select {
    width: 230px;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 7px;
}

.add-question-area {
    padding: 12px 14px;
    background: #fafcfd;
    border-top: 1px solid #edf1f4;
}

.add-group-area {
    text-align: center;
    margin: 8px 0 20px;
}

.editor-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
}

.editor-actions {
    display: flex;
    gap: 8px;
}

.detail-tabs {
    display: flex;
    gap: 5px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}

.detail-tabs button {
    border: 1px solid #d6dee6;
    background: #fff;
    padding: 9px 16px;
    border-radius: 5px;
}

.detail-tabs button.active {
    background: #2878c8;
    color: #fff;
    border-color: #2878c8;
}

.detail-summary {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}

.stat-card {
    background: #fff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    padding: 17px;
}

.stat-label {
    color: #718096;
    font-size: 12px;
}

.stat-value {
    font-size: 27px;
    font-weight: bold;
    margin-top: 5px;
}

.send-layout {
    display: grid;
    grid-template-columns: 1.1fr .9fr;
    gap: 18px;
}

.customer-toolbar {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
}

.customer-toolbar input,
.customer-toolbar select {
    border: 1px solid #cbd5e0;
    border-radius: 5px;
    padding: 8px;
}

.customer-toolbar input {
    flex: 1;
}

.selection-summary {
    padding: 10px 12px;
    background: #edf6ff;
    color: #2b5f8a;
    border-radius: 5px;
    margin-bottom: 12px;
    font-size: 13px;
}

.recipient-chip {
    display: inline-block;
    padding: 5px 8px;
    margin: 3px;
    border-radius: 4px;
    background: #edf2f7;
    font-size: 12px;
}

.email-preview {
    border: 1px solid #dfe5eb;
    border-radius: 6px;
    background: #fafbfc;
    padding: 15px;
    white-space: pre-wrap;
    min-height: 150px;
}

.progress {
    height: 10px;
    background: #e8edf2;
    border-radius: 5px;
    overflow: hidden;
    margin-top: 8px;
}

.progress span {
    display: block;
    height: 100%;
    background: #4285c5;
}

.result-layout {
    display: grid;
    grid-template-columns: 270px 1fr;
    gap: 18px;
}

.result-question-button {
    display: block;
    width: 100%;
    border: 0;
    background: #fff;
    text-align: left;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 4px;
}

.result-question-button:hover,
.result-question-button.active {
    background: #eef6ff;
    color: #2878c8;
}

.result-bar-row {
    margin-bottom: 14px;
}

.result-bar-label {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 4px;
}

.result-bar {
    height: 18px;
    border-radius: 3px;
    overflow: hidden;
    background: #edf1f4;
}

.result-bar-inner {
    height: 100%;
    background: #2878c8;
}

.result-answer {
    padding: 8px 10px;
    background: #f7f9fb;
    border: 1px solid #e6ebef;
    border-radius: 4px;
    margin: 5px 0;
    font-size: 13px;
}

.settings-tabs {
    display: flex;
    gap: 5px;
    margin-bottom: 18px;
}

.settings-tabs button {
    border: 1px solid #d6dee6;
    background: #fff;
    padding: 9px 16px;
    border-radius: 5px;
}

.settings-tabs button.active {
    background: #2878c8;
    color: #fff;
    border-color: #2878c8;
}

.status-line {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    background: #f7f9fb;
    border-radius: 5px;
    margin-bottom: 15px;
}

.status-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: #9aa7b3;
}

.status-dot.ok {
    background: #2f9e61;
}

.status-dot.warn {
    background: #d39b25;
}

.status-dot.error {
    background: #d9534f;
}

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(20,35,50,.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal {
    width: min(760px, calc(100% - 30px));
    max-height: 90vh;
    overflow: auto;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 15px 50px rgba(0,0,0,.25);
}

.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e3e8ed;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 13px 20px;
    border-top: 1px solid #e3e8ed;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.toast {
    position: fixed;
    right: 25px;
    bottom: 25px;
    background: #263238;
    color: #fff;
    padding: 12px 18px;
    border-radius: 5px;
    box-shadow: 0 5px 20px rgba(0,0,0,.2);
    opacity: 0;
    transform: translateY(10px);
    transition: .2s;
    pointer-events: none;
    z-index: 2000;
}

.toast.show {
    opacity: 1;
    transform: translateY(0);
}

.public-page {
    max-width: 820px;
    margin: 0 auto;
    padding: 30px 20px 60px;
}

.public-question {
    background: #fff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    padding: 20px;
    margin-bottom: 15px;
}

.public-question-title {
    font-weight: bold;
    margin-bottom: 12px;
}

.public-option {
    margin: 8px 0;
}

.public-complete {
    background: #fff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    padding: 40px;
    text-align: center;
}

.field-help {
    color: #718096;
    font-size: 12px;
    margin-top: 5px;
}

.security-note {
    background: #fff8e8;
    border: 1px solid #f1d99b;
    color: #765b16;
    padding: 10px 12px;
    border-radius: 5px;
    margin-bottom: 15px;
}

.confirm-text {
    margin: 0;
    line-height: 1.8;
}

.action-row {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.section-divider {
    height: 1px;
    background: #e6ebef;
    margin: 20px 0;
}

.text-muted {
    color: #718096;
}

.text-danger {
    color: #c53f3f;
}

.text-success {
    color: #276749;
}

.required {
    color: #c53f3f;
    margin-left: 3px;
}

.readonly-box {
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 5px;
    background: #f8fafc;
    min-height: 40px;
}

.answer-required {
    color: #c53f3f;
    font-size: 12px;
    margin-left: 5px;
}

.mobile-only {
    display: none;
}

@media (max-width: 980px) {
    .send-layout,
    .result-layout {
        grid-template-columns: 1fr;
    }

    .detail-summary {
        grid-template-columns: 1fr 1fr;
    }

    .question-meta {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 800px) {
    .topbar {
        padding: 0 12px;
        gap: 10px;
        overflow-x: auto;
    }

    .main-nav button {
        padding: 0 10px;
    }

    .app {
        padding: 15px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .page-header {
        align-items: flex-start;
        flex-direction: column;
    }
}

@media (max-width: 560px) {
    .detail-summary {
        grid-template-columns: 1fr;
    }

    .question-meta {
        grid-template-columns: 1fr;
    }

    .option-row {
        flex-wrap: wrap;
    }

    .option-row select {
        width: 100%;
    }

    .mobile-only {
        display: block;
    }
}
</style>
</head>

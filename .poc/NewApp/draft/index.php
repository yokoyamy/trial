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
 * PHP側のHTMLエスケープ
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
 * JSONレスポンスを返して処理を終了する。
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
 * アプリ固有CSRFトークン取得。
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
 * HTTPメソッド取得。
 */
function request_method(): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    return is_string($method)
        ? strtoupper($method)
        : 'GET';
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
 * JSONリクエスト読み込み。
 */
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

/**
 * CSRF検証。
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
 * データ保存ディレクトリを準備。
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
 * 顧客データ初期値。
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
 * アンケートデータ初期値。
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
 * 回答データ初期値。
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

/**
 * JSONファイル安全保存。
 */
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

        if (
            $written === false ||
            $written !== $length
        ) {
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

/**
 * 初期JSONファイル作成。
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

function now(): string
{
    return date('c');
}

function generate_id(string $prefix): string
{
    return $prefix .
        '_' .
        date('YmdHis') .
        '_' .
        bin2hex(random_bytes(4));
}

/**
 * 現在のindex.php自身をAPIの呼び出し先とする。
 *
 * 外部ホスト名を組み立てないため、
 * 開発機・本番機・サブディレクトリの違いによる
 * API URLの取り違えを防ぐ。
 */
function application_api_path(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';

    if (!is_string($script) || $script === '') {
        return './index.php';
    }

    $script = str_replace('\\', '/', $script);

    if ($script[0] !== '/') {
        return './' . ltrim($script, '/');
    }

    return $script;
}

/* =========================================================
 * kintone
 * ========================================================= */

/**
 * kintone URL整形。
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

/**
 * Cybozu認証ヘッダー。
 */
function make_cybozu_auth_header(
    string $login_name,
    string $password
): string {
    $login_name = trim($login_name);
    $password = trim($password);

    return 'X-Cybozu-Authorization: ' .
        base64_encode(
            $login_name . ':' . $password
        );
}

/**
 * PHP 8.4/8.5対応のレスポンスヘッダー取得。
 *
 * $http_response_headerは使用しない。
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
 * kintone REST API共通通信。
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
    $method = strtoupper($method);

    $options = [
        'method' => $method,
        'header' => implode(
            "\r\n",
            $headers
        ),
        'ignore_errors' => true,
        'timeout' => 20
    ];

    if ($method !== 'GET' && $payload !== []) {
        try {
            $options['content'] =
                json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES |
                    JSON_THROW_ON_ERROR
                );

            $options['header'] .=
                "\r\nContent-Type: application/json";
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

    /*
     * GETではcontentを設定しない。
     */
    $context_options = [
        'http' => $options,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];

    /*
     * プロキシ設定。
     *
     * host / port は常に受け取れる。
     * 設定されている場合はproxyと
     * request_fulluri=trueを必ず適用する。
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
        /*
         * プロキシ未設定時。
         */
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
        $message = $decoded['message'];
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
                    is_string($error_message)
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
        $endpoint =
            '/k/v1/app.json?' .
            http_build_query(
                ['id' => $app_id],
                '',
                '&',
                PHP_QUERY_RFC3986
            );
    } else {
        $endpoint =
            '/k/v1/apps.json?' .
            http_build_query(
                ['limit' => 1],
                '',
                '&',
                PHP_QUERY_RFC3986
            );
    }

    $url = kintone_build_url(
        $domain,
        $endpoint
    );

    return kintone_api_request(
        'GET',
        $url,
        $headers,
        [],
        [
            'proxy_host' => trim(
                (string)(
                    $settings['proxy_host'] ?? ''
                )
            ),
            'proxy_port' => trim(
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
            'proxy_host' => trim(
                (string)(
                    $settings['proxy_host'] ?? ''
                )
            ),
            'proxy_port' => trim(
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
                (string)$record[$id_field]['value'];
        }

        if (
            $name_field !== '' &&
            isset(
                $record[$name_field]['value']
            )
        ) {
            $name =
                (string)$record[$name_field]['value'];
        }

        if (
            $email_field !== '' &&
            isset(
                $record[$email_field]['value']
            )
        ) {
            $email =
                (string)$record[$email_field]['value'];
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
        'status' => 200,
        'message' =>
            '顧客一覧を更新しました。',
        'customers' => $customers,
        'data' => []
    ];
}

/* =========================================================
 * アンケート共通処理
 * ========================================================= */

function survey_find(
    array $surveys,
    string $id
): ?array {
    foreach ($surveys as $survey) {
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
            return $index;
        }
    }

    return -1;
}

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
        is_array($question['options'])
    ) {
        foreach (
            $question['options'] as $option
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

function normalize_survey(
    array $survey
): array {
    $groups = [];

    if (
        isset($survey['groups']) &&
        is_array($survey['groups'])
    ) {
        foreach (
            $survey['groups'] as $group
        ) {
            if (!is_array($group)) {
                continue;
            }

            $questions = [];

            if (
                isset($group['questions']) &&
                is_array($group['questions'])
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
                'questions' => $questions
            ];
        }
    }

    $status =
        (string)(
            $survey['status'] ?? 'draft'
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
            (
                (string)(
                    $survey['numbering'] ??
                    'global'
                ) === 'group'
                    ? 'group'
                    : 'global'
            ),
        'groups' => $groups
    ];
}

/**
 * 回答数集計。
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

    foreach ($responses as $response) {
        if (
            !is_array($response)
        ) {
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

/* =========================================================
 * API処理
 * ========================================================= */

initialize_data_files();

$action = requested_action();

if ($action !== '') {
    /*
     * 状態を変更する処理だけCSRFを要求。
     */
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

    if (
        request_method() !== 'GET' ||
        !in_array(
            $action,
            $read_actions,
            true
        )
    ) {
        if (request_method() !== 'GET') {
            validate_csrf();
        }
    }

    switch ($action) {

        /*
         * -------------------------------------------------
         * 設定取得
         * 旧名称 get_settings も受け付ける。
         * -------------------------------------------------
         */
        case 'load_settings':
        case 'get_settings':

            $settings = load_settings();

            /*
             * パスワードは絶対に画面へ返さない。
             */
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
                    'settings' => $settings
                ]
            );

        /*
         * -------------------------------------------------
         * メール設定保存
         * -------------------------------------------------
         */
        case 'save_mail_settings':

            $input =
                read_json_request();

            $settings =
                load_settings();

            $mail = [
                'smtp_server' =>
                    trim(
                        (string)(
                            $input['smtp_server'] ?? ''
                        )
                    ),
                'smtp_port' =>
                    (int)(
                        $input['smtp_port'] ?? 587
                    ),
                'connection_type' =>
                    trim(
                        (string)(
                            $input['connection_type'] ??
                            'tls'
                        )
                    ),
                'username' =>
                    trim(
                        (string)(
                            $input['username'] ?? ''
                        )
                    ),
                'password' =>
                    (string)(
                        $input['password'] ?? ''
                    ),
                'from_email' =>
                    trim(
                        (string)(
                            $input['from_email'] ?? ''
                        )
                    ),
                'from_name' =>
                    trim(
                        (string)(
                            $input['from_name'] ??
                            'アンケート事務局'
                        )
                    ),
                'configured' => true,
                'tested_at' =>
                    $settings['mail']['tested_at']
                    ?? null
            ];

            if (
                $mail['password'] === ''
            ) {
                $mail['password'] =
                    (string)(
                        $settings['mail']['password']
                        ?? ''
                    );
            }

            if (
                $mail['smtp_server'] === ''
            ) {
                json_response(
                    false,
                    'SMTPサーバーを入力してください。',
                    [],
                    [],
                    400
                );
            }

            if (
                $mail['from_email'] === ''
            ) {
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

            write_json_file(
                SETTINGS_FILE,
                $settings
            );

            json_response(
                true,
                'メール送信設定を保存しました。'
            );

        /*
         * -------------------------------------------------
         * メール設定確認
         * -------------------------------------------------
         */
        case 'test_mail_settings':

            $input =
                read_json_request();

            $smtp = trim(
                (string)(
                    $input['smtp_server'] ?? ''
                )
            );

            $port = (int)(
                $input['smtp_port'] ?? 0
            );

            $from = trim(
                (string)(
                    $input['from_email'] ?? ''
                )
            );

            if (
                $smtp === '' ||
                $port <= 0 ||
                $from === ''
            ) {
                json_response(
                    false,
                    'SMTPサーバー、ポート、送信元メールアドレスを確認してください。',
                    [],
                    [],
                    400
                );
            }

            json_response(
                true,
                'メール送信設定を確認しました。'
            );

        /*
         * -------------------------------------------------
         * kintone設定保存
         * -------------------------------------------------
         */
        case 'save_kintone_settings':

            $input =
                read_json_request();

            $settings =
                load_settings();

            $password =
                (string)(
                    $input['password'] ?? ''
                );

            if (
                $password === ''
            ) {
                $password =
                    (string)(
                        $settings['kintone']['password']
                        ?? ''
                    );
            }

            $kintone = [
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
                'password' => $password,
                'customer_app_id' =>
                    trim(
                        (string)(
                            $input['customer_app_id'] ??
                            ''
                        )
                    ),
                'customer_id_field' =>
                    trim(
                        (string)(
                            $input['customer_id_field'] ??
                            ''
                        )
                    ),
                'customer_name_field' =>
                    trim(
                        (string)(
                            $input['customer_name_field'] ??
                            ''
                        )
                    ),
                'customer_email_field' =>
                    trim(
                        (string)(
                            $input['customer_email_field'] ??
                            ''
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
                    $settings['kintone']
                        ['connection_status']
                    ?? 'not_configured',
                'tested_at' =>
                    $settings['kintone']
                        ['tested_at']
                    ?? null
            ];

            if (
                $kintone['domain'] === ''
            ) {
                json_response(
                    false,
                    'kintoneドメインを入力してください。',
                    [],
                    [],
                    400
                );
            }

            if (
                $kintone['login_name'] === ''
            ) {
                json_response(
                    false,
                    'kintoneログイン名を入力してください。',
                    [],
                    [],
                    400
                );
            }

            if (
                $kintone['password'] === ''
            ) {
                json_response(
                    false,
                    'kintoneパスワードを入力してください。',
                    [],
                    [],
                    400
                );
            }

            $settings['kintone'] =
                $kintone;

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

        /*
         * -------------------------------------------------
         * kintone接続テスト
         * -------------------------------------------------
         */
        case 'test_kintone_connection':

            $input =
                read_json_request();

            if ($input === []) {
                $settings =
                    load_settings();

                $input =
                    $settings['kintone'];
            }

            $result =
                test_kintone($input);

            if (!$result['success']) {
                json_response(
                    false,
                    (string)(
                        $result['message'] ??
                        'kintone接続に失敗しました。'
                    ),
                    [
                        'status' =>
                            (int)(
                                $result['status'] ??
                                0
                            )
                    ],
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

            $settings =
                load_settings();

            $settings['kintone']
                ['configured'] = true;

            $settings['kintone']
                ['connection_status'] =
                'connected';

            $settings['kintone']
                ['tested_at'] = now();

            write_json_file(
                SETTINGS_FILE,
                $settings
            );

            json_response(
                true,
                'kintoneへの接続を確認しました。'
            );

        /*
         * -------------------------------------------------
         * 顧客一覧取得
         * -------------------------------------------------
         */
        case 'load_customers':
        case 'list_customers':

            $data =
                load_customers();

            json_response(
                true,
                '顧客一覧を取得しました。',
                [
                    'customers' =>
                        is_array(
                            $data['customers'] ??
                            null
                        )
                            ? $data['customers']
                            : []
                ]
            );

        /*
         * -------------------------------------------------
         * kintoneから顧客更新
         * -------------------------------------------------
         */
        case 'refresh_customers':

            $settings =
                load_settings();

            $result =
                fetch_kintone_customers(
                    $settings['kintone'] ?? []
                );

            if (!$result['success']) {
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
                isset($result['customers']) &&
                is_array($result['customers'])
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

        /*
         * -------------------------------------------------
         * アンケート一覧
         *
         * list_surveys / load_surveys の両方を
         * 正式に受け付ける。
         * -------------------------------------------------
         */
        case 'load_surveys':
        case 'list_surveys':

            $data =
                load_surveys();

            $responses =
                load_responses();

            $survey_list = [];

            $stored_surveys =
                $data['surveys'] ?? [];

            if (!is_array($stored_surveys)) {
                $stored_surveys = [];
            }

            $stored_responses =
                $responses['responses'] ?? [];

            if (!is_array($stored_responses)) {
                $stored_responses = [];
            }

            foreach (
                $stored_surveys as $survey
            ) {
                if (!is_array($survey)) {
                    continue;
                }

                $counts =
                    calculate_survey_counts(
                        $survey,
                        $stored_responses
                    );

                $survey['answers'] =
                    $counts['answers'];

                $survey_list[] =
                    $survey;
            }

            json_response(
                true,
                'アンケート一覧を取得しました。',
                [
                    'surveys' =>
                        $survey_list
                ]
            );

        /*
         * -------------------------------------------------
         * アンケート1件取得
         * -------------------------------------------------
         */
        case 'load_survey':
        case 'get_survey':

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

            $data =
                load_surveys();

            $survey =
                survey_find(
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
                [
                    'survey' => $survey
                ]
            );

        /*
         * -------------------------------------------------
         * アンケート保存
         * -------------------------------------------------
         */
        case 'save_survey':

            $input =
                read_json_request();

            $survey_input =
                $input['survey'] ?? null;

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
                $survey['groups'] as $group
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
                            $old['created'] ??
                            $survey['created']
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
                    'survey' => $survey
                ]
            );

        /*
         * -------------------------------------------------
         * アンケート削除
         * -------------------------------------------------
         */
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
                    'アンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            $status =
                (string)(
                    $data['surveys'][$index]
                        ['status'] ??
                    'draft'
                );

            if ($status !== 'draft') {
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

        /*
         * -------------------------------------------------
         * 公開
         * -------------------------------------------------
         */
        case 'publish_survey':

            $input =
                read_json_request();

            $id =
                trim(
                    (string)(
                        $input['id'] ?? ''
                    )
                );

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
                    'アンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            $survey =
                $data['surveys'][$index];

            if (!is_array($survey)) {
                json_response(
                    false,
                    'アンケートデータを確認できません。',
                    [],
                    [],
                    500
                );
            }

            $survey['status'] = 'open';
            $survey['updated'] =
                date('Y-m-d');

            $data['surveys'][$index] =
                $survey;

            $data['updated_at'] =
                now();

            write_json_file(
                SURVEYS_FILE,
                $data
            );

            json_response(
                true,
                'アンケートを公開しました。',
                [
                    'survey' => $survey
                ]
            );

        /*
         * -------------------------------------------------
         * 終了
         * -------------------------------------------------
         */
        case 'close_survey':

            $input =
                read_json_request();

            $id =
                trim(
                    (string)(
                        $input['id'] ?? ''
                    )
                );

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
                    'アンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            $survey =
                $data['surveys'][$index];

            if (!is_array($survey)) {
                json_response(
                    false,
                    'アンケートデータを確認できません。',
                    [],
                    [],
                    500
                );
            }

            $survey['status'] = 'end';
            $survey['updated'] =
                date('Y-m-d');

            $data['surveys'][$index] =
                $survey;

            $data['updated_at'] =
                now();

            write_json_file(
                SURVEYS_FILE,
                $data
            );

            json_response(
                true,
                'アンケートを終了しました。',
                [
                    'survey' => $survey
                ]
            );

        /*
         * -------------------------------------------------
         * 回答状況・結果集計
         * -------------------------------------------------
         */
        case 'get_aggregate':

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
                    $survey_data['surveys'] ?? [],
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

            $response_data =
                load_responses();

            $responses =
                $response_data['responses'] ??
                [];

            if (!is_array($responses)) {
                $responses = [];
            }

            $survey_responses = [];

            foreach (
                $responses as $response
            ) {
                if (!is_array($response)) {
                    continue;
                }

                if (
                    (string)(
                        $response['survey_id'] ??
                        ''
                    ) === $id
                ) {
                    $survey_responses[] =
                        $response;
                }
            }

            $result = [];

            $groups =
                $survey['groups'] ?? [];

            if (!is_array($groups)) {
                $groups = [];
            }

            foreach ($groups as $group) {
                if (!is_array($group)) {
                    continue;
                }

                $questions =
                    $group['questions'] ??
                    [];

                if (!is_array($questions)) {
                    continue;
                }

                foreach (
                    $questions as $question
                ) {
                    if (!is_array($question)) {
                        continue;
                    }

                    $question_id =
                        (string)(
                            $question['id'] ??
                            ''
                        );

                    $type =
                        (string)(
                            $question['type'] ??
                            'free'
                        );

                    $options =
                        $question['options'] ??
                        [];

                    $counts = [];
                    $free_answers = [];

                    if (is_array($options)) {
                        foreach (
                            $options as $option
                        ) {
                            if (!is_array($option)) {
                                continue;
                            }

                            $text =
                                (string)(
                                    $option['text'] ??
                                    ''
                                );

                            if ($text !== '') {
                                $counts[$text] = 0;
                            }
                        }
                    }

                    foreach (
                        $survey_responses
                        as $response
                    ) {
                        $answers =
                            $response['answers'] ??
                            [];

                        if (!is_array($answers)) {
                            continue;
                        }

                        $answer =
                            $answers[$question_id]
                            ?? null;

                        if (
                            $type === 'free'
                        ) {
                            if (
                                is_string($answer) &&
                                trim($answer) !== ''
                            ) {
                                $free_answers[] =
                                    $answer;
                            }
                        } elseif (
                            $type === 'single'
                        ) {
                            if (
                                is_string($answer) &&
                                isset(
                                    $counts[$answer]
                                )
                            ) {
                                $counts[$answer]++;
                            }
                        } elseif (
                            $type === 'multiple'
                        ) {
                            if (!is_array($answer)) {
                                continue;
                            }

                            foreach (
                                $answer as $selected
                            ) {
                                if (
                                    is_string(
                                        $selected
                                    ) &&
                                    isset(
                                        $counts[
                                            $selected
                                        ]
                                    )
                                ) {
                                    $counts[
                                        $selected
                                    ]++;
                                }
                            }
                        }
                    }

                    $result[] = [
                        'question_id' =>
                            $question_id,
                        'question' =>
                            (string)(
                                $question['text'] ??
                                ''
                            ),
                        'type' => $type,
                        'total' =>
                            count(
                                $survey_responses
                            ),
                        'counts' => $counts,
                        'free_answers' =>
                            $free_answers
                    ];
                }
            }

            json_response(
                true,
                '回答結果を集計しました。',
                [
                    'total_responses' =>
                        count(
                            $survey_responses
                        ),
                    'results' => $result
                ]
            );

        /*
         * -------------------------------------------------
         * 回答登録
         * -------------------------------------------------
         */
        case 'submit_response':

            $input =
                read_json_request();

            $survey_id =
                trim(
                    (string)(
                        $input['survey_id'] ?? ''
                    )
                );

            $answers =
                $input['answers'] ?? [];

            if (
                $survey_id === '' ||
                !is_array($answers)
            ) {
                json_response(
                    false,
                    '回答内容を確認してください。',
                    [],
                    [],
                    400
                );
            }

            $survey_data =
                load_surveys();

            $survey =
                survey_find(
                    $survey_data['surveys'] ?? [],
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

            if (
                (string)(
                    $survey['status'] ?? ''
                ) !== 'open'
            ) {
                json_response(
                    false,
                    '現在このアンケートは回答を受け付けていません。',
                    [],
                    [],
                    400
                );
            }

            $response_data =
                load_responses();

            if (
                !isset(
                    $response_data['responses']
                ) ||
                !is_array(
                    $response_data['responses']
                )
            ) {
                $response_data['responses'] =
                    [];
            }

            $response =
                [
                    'id' =>
                        generate_id(
                            'response'
                        ),
                    'survey_id' =>
                        $survey_id,
                    'submitted_at' =>
                        now(),
                    'answers' =>
                        $answers
                ];

            $response_data['responses'][] =
                $response;

            $response_data['updated_at'] =
                now();

            write_json_file(
                RESPONSES_FILE,
                $response_data
            );

            json_response(
                true,
                '回答を受け付けました。'
            );

        /*
         * -------------------------------------------------
         * メール送信
         *
         * この環境では外部SMTPライブラリを使わず、
         * 送信対象をメールログへ登録する。
         * -------------------------------------------------
         */
        case 'send_survey':

            $input =
                read_json_request();

            $survey_id =
                trim(
                    (string)(
                        $input['survey_id'] ?? ''
                    )
                );

            $customer_ids =
                $input['customer_ids'] ?? [];

            if (
                $survey_id === '' ||
                !is_array($customer_ids) ||
                $customer_ids === []
            ) {
                json_response(
                    false,
                    '送信対象を選択してください。',
                    [],
                    [],
                    400
                );
            }

            $survey_data =
                load_surveys();

            $survey =
                survey_find(
                    $survey_data['surveys'] ?? [],
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

            $customer_data =
                load_customers();

            $stored_customers =
                $customer_data['customers'] ??
                [];

            if (!is_array($stored_customers)) {
                $stored_customers = [];
            }

            $selected_customers = [];

            foreach (
                $stored_customers as $customer
            ) {
                if (!is_array($customer)) {
                    continue;
                }

                $customer_id =
                    (string)(
                        $customer['id'] ??
                        ''
                    );

                if (
                    in_array(
                        $customer_id,
                        array_map(
                            'strval',
                            $customer_ids
                        ),
                        true
                    )
                ) {
                    $selected_customers[] =
                        $customer;
                }
            }

            if (
                $selected_customers === []
            ) {
                json_response(
                    false,
                    '選択された顧客が見つかりません。',
                    [],
                    [],
                    400
                );
            }

            $mail_logs =
                load_mail_logs();

            if (
                !isset($mail_logs['logs']) ||
                !is_array(
                    $mail_logs['logs']
                )
            ) {
                $mail_logs['logs'] = [];
            }

            foreach (
                $selected_customers as $customer
            ) {
                $mail_logs['logs'][] = [
                    'id' =>
                        generate_id('mail'),
                    'survey_id' =>
                        $survey_id,
                    'customer_id' =>
                        (string)(
                            $customer['id'] ??
                            ''
                        ),
                    'email' =>
                        (string)(
                            $customer['email'] ??
                            ''
                        ),
                    'sent_at' =>
                        now(),
                    'status' =>
                        'registered'
                ];
            }

            $mail_logs['updated_at'] =
                now();

            write_json_file(
                MAIL_LOGS_FILE,
                $mail_logs
            );

            $survey_index =
                survey_index(
                    $survey_data['surveys'] ?? [],
                    $survey_id
                );

            if ($survey_index >= 0) {
                $survey_data['surveys']
                    [$survey_index]
                    ['sent'] =
                    (int)(
                        $survey_data['surveys']
                            [$survey_index]
                            ['sent'] ??
                        0
                    ) +
                    count(
                        $selected_customers
                    );

                $survey_data['surveys']
                    [$survey_index]
                    ['target'] =
                    max(
                        (int)(
                            $survey_data['surveys']
                                [$survey_index]
                                ['target'] ??
                            0
                        ),
                        count(
                            $selected_customers
                        )
                    );

                $survey_data['surveys']
                    [$survey_index]
                    ['updated'] =
                    date('Y-m-d');

                $survey_data['updated_at'] =
                    now();

                write_json_file(
                    SURVEYS_FILE,
                    $survey_data
                );
            }

            json_response(
                true,
                count(
                    $selected_customers
                ) .
                '件の送信対象を登録しました。'
            );

        default:

            json_response(
                false,
                '指定された処理は存在しません。',
                [],
                [
                    'action' =>
                        $action
                ],
                404
            );
    }
}

/*
 * =========================================================
 * HTML開始
 * =========================================================
 */

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
        padding: 0 10px;
        gap: 8px;
        overflow-x: auto;
    }

    .logo {
        font-size: 15px;
    }

    .main-nav button {
        padding: 0 8px;
        font-size: 12px;
    }

    .app {
        padding: 14px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .question-head {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .question-title {
        min-width: 70%;
    }

    .option-row {
        flex-wrap: wrap;
    }

    .option-row select {
        width: 100%;
    }

    .table {
        min-width: 800px;
    }

    .card {
        overflow-x: auto;
    }

    .detail-summary {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>

<header class="topbar" id="operator-header">
    <div class="logo">アンケート業務運営</div>

    <nav class="main-nav" aria-label="メインメニュー">
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
            <div class="subtext">
                作成済みのアンケートを管理します
            </div>
        </div>

        <button
            id="btn-open-create"
            class="btn btn-primary"
            type="button"
        >
            <span class="loading-spinner" aria-hidden="true"></span>
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
            <h1 id="editor-heading">アンケート作成</h1>
            <div class="subtext">
                アンケート全体を確認しながら作成・編集します
            </div>
        </div>

        <div class="editor-actions">

            <button
                id="btn-editor-back"
                class="btn"
                type="button"
            >
                一覧へ戻る
            </button>

            <button
                id="btn-editor-preview"
                class="btn"
                type="button"
            >
                内容確認
            </button>

            <button
                id="btn-editor-save"
                class="btn btn-primary"
                type="button"
            >
                <span
                    class="loading-spinner"
                    aria-hidden="true"
                ></span>
                保存
            </button>

        </div>
    </div>

    <div id="editor-notice"></div>

    <div class="card">

        <div class="form-grid">

            <div class="field">
                <label for="editor-name">
                    アンケート名
                </label>

                <input
                    id="editor-name"
                    type="text"
                    maxlength="200"
                    autocomplete="off"
                >
            </div>

            <div class="field">
                <label for="editor-status">
                    公開状態
                </label>

                <select id="editor-status">
                    <option value="draft">下書き</option>
                    <option value="open">公開中</option>
                    <option value="end">終了</option>
                </select>
            </div>

        </div>


        <div class="field">
            <label for="editor-description">
                説明
            </label>

            <textarea
                id="editor-description"
                maxlength="2000"
            ></textarea>
        </div>


        <div class="form-grid">

            <div class="field">
                <label for="editor-start">
                    公開開始
                </label>

                <input
                    id="editor-start"
                    type="datetime-local"
                >
            </div>

            <div class="field">
                <label for="editor-end">
                    公開終了
                </label>

                <input
                    id="editor-end"
                    type="datetime-local"
                >
            </div>

        </div>


        <div class="field">

            <label>
                質問番号形式
            </label>

            <div class="radio-row">

                <label>
                    <input
                        id="numbering-global"
                        type="radio"
                        name="question-numbering"
                        value="global"
                    >
                    全体で通番
                </label>

                <label>
                    <input
                        id="numbering-group"
                        type="radio"
                        name="question-numbering"
                        value="group"
                    >
                    グループごとの番号
                </label>

            </div>

        </div>

    </div>


    <div class="editor-toolbar">

        <h2>質問グループ</h2>

    </div>


    <div id="editor-groups"></div>


    <div class="add-group-area">

        <button
            id="btn-add-group"
            class="btn"
            type="button"
        >
            ＋ グループ追加
        </button>

    </div>

</section>


<section id="page-detail" class="hidden">

    <div class="page-header">

        <div>

            <h1 id="detail-title">
                アンケート
            </h1>

            <div
                id="detail-subtitle"
                class="subtext"
            ></div>

        </div>


        <div>

            <button
                id="btn-detail-edit"
                class="btn"
                type="button"
            >
                編集
            </button>

            <button
                id="btn-detail-send"
                class="btn btn-primary"
                type="button"
            >
                送信
            </button>

            <button
                id="btn-detail-back"
                class="btn"
                type="button"
            >
                一覧へ戻る
            </button>

        </div>

    </div>


    <div
        id="detail-notice"
    ></div>


    <div class="detail-tabs">

        <button
            id="tab-content"
            type="button"
            data-tab="content"
        >
            アンケート内容
        </button>

        <button
            id="tab-send"
            type="button"
            data-tab="send"
        >
            送信
        </button>

        <button
            id="tab-status"
            type="button"
            data-tab="status"
        >
            回答状況
        </button>

        <button
            id="tab-result"
            type="button"
            data-tab="result"
        >
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


        <button
            id="btn-customer-settings"
            class="btn"
            type="button"
        >
            kintone設定
        </button>

    </div>


    <div id="customer-notice"></div>


    <div class="card">

        <div class="customer-toolbar">

            <input
                id="customer-search"
                type="search"
                placeholder="顧客名・メールアドレスで検索"
                autocomplete="off"
            >

            <button
                id="btn-refresh-customers"
                class="btn"
                type="button"
            >
                <span
                    class="loading-spinner"
                    aria-hidden="true"
                ></span>
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

        <button
            id="settings-tab-mail"
            type="button"
            data-settings-tab="mail"
        >
            メール送信設定
        </button>

        <button
            id="settings-tab-kintone"
            type="button"
            data-settings-tab="kintone"
        >
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
    aria-modal="true"
    aria-labelledby="modal-title"
>

    <div class="modal">

        <div class="modal-header">

            <strong id="modal-title"></strong>

            <button
                id="modal-close"
                class="btn btn-small"
                type="button"
            >
                閉じる
            </button>

        </div>


        <div
            id="modal-body"
            class="modal-body"
        ></div>


        <div
            id="modal-footer"
            class="modal-footer"
        ></div>

    </div>

</div>


<div
    id="toast"
    class="toast"
    role="status"
    aria-live="polite"
></div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    /*
     * =========================================================
     * アプリケーション状態
     * =========================================================
     */

    const csrfMeta =
        document.querySelector(
            'meta[name="csrf-token"]'
        );

    const apiMeta =
        document.querySelector(
            'meta[name="api-path"]'
        );

    const csrfToken =
        csrfMeta instanceof HTMLMetaElement
            ? csrfMeta.content
            : '';

    /*
     * APIのURLは必ず現在のindex.phpを基準にする。
     *
     * 外部ホスト名をJavaScript側で生成しない。
     * これにより、
     *
     *   https://n11-1041/...
     *
     * のような誤った固定URLへのアクセスを防ぐ。
     */
    const apiPath =
        apiMeta instanceof HTMLMetaElement &&
        apiMeta.content.trim() !== ''
            ? apiMeta.content
            : 'index.php';


    let surveys = [];
    let customers = [];

    let currentSurvey = null;
    let currentSurveyId = '';

    let editingSurvey = null;

    let currentSettingsTab = 'mail';
    let currentDetailTab = 'content';

    let draggedGroupId = '';
    let draggedQuestionId = '';

    let toastTimer = null;


    /*
     * =========================================================
     * DOM取得
     * =========================================================
     */

    const $ = function (id) {
        return document.getElementById(id);
    };


    /*
     * =========================================================
     * 安全な文字列処理
     * =========================================================
     *
     * DOMへの表示は原則textContentを使用する。
     * HTML文字列を組み立てる場合にも、利用箇所を限定する。
     */

    function escapeHtml(value) {

        const str =
            String(value ?? '');

        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }


    /*
     * =========================================================
     * 通知
     * =========================================================
     */

    function showToast(message) {

        const toast = $('toast');

        if (!toast) {
            return;
        }

        toast.textContent =
            String(message ?? '');

        toast.classList.add('show');

        if (toastTimer !== null) {
            window.clearTimeout(toastTimer);
        }

        toastTimer =
            window.setTimeout(
                function () {

                    if (toast) {
                        toast.classList.remove(
                            'show'
                        );
                    }

                },
                2800
            );
    }


    function showNotice(
        target,
        message,
        type
    ) {

        if (!target) {
            return;
        }

        target.textContent = '';

        if (
            message === null ||
            message === undefined ||
            String(message) === ''
        ) {
            return;
        }

        const div =
            document.createElement('div');

        div.className =
            'notice' +
            (
                type
                    ? ' ' + String(type)
                    : ''
            );

        div.textContent =
            String(message);

        target.appendChild(div);
    }


    /*
     * =========================================================
     * ボタンのローディング制御
     * =========================================================
     */

    function setButtonLoading(
        button,
        loading
    ) {

        if (!button) {
            return;
        }

        if (loading) {

            /*
             * fetch開始より前に必ず設定する。
             */
            button.disabled = true;

            button.classList.add(
                'loading'
            );

        } else {

            button.disabled = false;

            button.classList.remove(
                'loading'
            );
        }
    }


    /*
     * =========================================================
     * API URL
     * =========================================================
     *
     * action以外の空値は送信しない。
     */

    function buildApiUrl(
        action,
        params
    ) {

        const query =
            new URLSearchParams();

        query.set(
            'action',
            String(action)
        );

        if (
            params &&
            typeof params === 'object'
        ) {

            Object.keys(params)
                .forEach(
                    function (key) {

                        const value =
                            params[key];

                        if (
                            value !== undefined &&
                            value !== null &&
                            String(value) !== ''
                        ) {

                            query.set(
                                key,
                                String(value)
                            );
                        }
                    }
                );
        }

        return (
            apiPath +
            '?' +
            query.toString()
        );
    }


    /*
     * =========================================================
     * GET通信
     * =========================================================
     */

    async function apiGet(
        action,
        params
    ) {

        const url =
            buildApiUrl(
                action,
                params
            );

        const response =
            await fetch(
                url,
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Accept':
                            'application/json'
                    }
                }
            );

        const text =
            await response.text();

        let data = null;

        try {

            data =
                JSON.parse(text);

        } catch (error) {

            throw new Error(
                'サーバーから正しい応答を取得できませんでした。'
            );
        }

        if (
            !response.ok ||
            !data ||
            data.success !== true
        ) {

            throw new Error(
                data &&
                typeof data.message === 'string' &&
                data.message !== ''
                    ? data.message
                    : '処理に失敗しました。'
            );
        }

        return data;
    }


    /*
     * =========================================================
     * POST通信
     * =========================================================
     *
     * 状態変更処理ではCSRFトークンを送信する。
     *
     * ボタンが指定されている場合は、
     * fetch開始前にdisabled/loadingを設定する。
     */

    async function apiPost(
        button,
        action,
        payload
    ) {

        if (button) {

            button.disabled = true;

            button.classList.add(
                'loading'
            );
        }

        try {

            const url =
                buildApiUrl(action);


            const response =
                await fetch(
                    url,
                    {
                        method: 'POST',
                        credentials: 'same-origin',
                        cache: 'no-store',

                        headers: {
                            'Content-Type':
                                'application/json; charset=utf-8',

                            'Accept':
                                'application/json',

                            'X-CSRF-Token':
                                csrfToken
                        },

                        body:
                            JSON.stringify(
                                payload || {}
                            )
                    }
                );


            const text =
                await response.text();

            let data = null;

            try {

                data =
                    JSON.parse(text);

            } catch (error) {

                throw new Error(
                    'サーバーから正しい応答を取得できませんでした。'
                );
            }


            if (
                !response.ok ||
                !data ||
                data.success !== true
            ) {

                throw new Error(
                    data &&
                    typeof data.message === 'string' &&
                    data.message !== ''
                        ? data.message
                        : '処理に失敗しました。'
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


    /*
     * =========================================================
     * 画面切り替え
     * =========================================================
     */

    function showPage(id) {

        const pageIds = [
            'page-list',
            'page-editor',
            'page-detail',
            'page-customers',
            'page-settings'
        ];


        pageIds.forEach(
            function (pageId) {

                const element =
                    $(pageId);

                if (!element) {
                    return;
                }

                element.classList.toggle(
                    'hidden',
                    pageId !== id
                );
            }
        );


        const navIds = [
            'nav-list',
            'nav-create',
            'nav-customers',
            'nav-settings'
        ];


        navIds.forEach(
            function (navId) {

                const element =
                    $(navId);

                if (!element) {
                    return;
                }

                element.classList.remove(
                    'active'
                );
            }
        );


        if (
            id === 'page-list'
        ) {

            const nav =
                $('nav-list');

            if (nav) {
                nav.classList.add(
                    'active'
                );
            }
        }


        if (
            id === 'page-editor'
        ) {

            const nav =
                $('nav-create');

            if (nav) {
                nav.classList.add(
                    'active'
                );
            }
        }


        if (
            id === 'page-customers'
        ) {

            const nav =
                $('nav-customers');

            if (nav) {
                nav.classList.add(
                    'active'
                );
            }
        }


        if (
            id === 'page-settings'
        ) {

            const nav =
                $('nav-settings');

            if (nav) {
                nav.classList.add(
                    'active'
                );
            }
        }
    }


    /*
     * =========================================================
     * アンケート状態表示
     * =========================================================
     */

    function statusLabel(status) {

        if (status === 'open') {
            return '公開中';
        }

        if (status === 'end') {
            return '終了';
        }

        return '下書き';
    }


    function statusClass(status) {

        if (status === 'open') {
            return 'badge-open';
        }

        if (status === 'end') {
            return 'badge-end';
        }

        return 'badge-draft';
    }


    function appendStatusBadge(
        parent,
        status
    ) {

        if (!parent) {
            return;
        }

        const badge =
            document.createElement('span');

        badge.className =
            'badge ' +
            statusClass(status);

        badge.textContent =
            statusLabel(status);

        parent.appendChild(badge);
    }


    /*
     * =========================================================
     * アンケート一覧取得
     * =========================================================
     */

    async function loadSurveyList() {

        try {

            const result =
                await apiGet(
                    'list_surveys'
                );

            surveys =
                Array.isArray(
                    result.data?.surveys
                )
                    ? result.data.surveys
                    : [];

            renderSurveyList();

        } catch (error) {

            const notice =
                $('list-notice');

            showNotice(
                notice,
                error instanceof Error
                    ? error.message
                    : 'アンケート一覧を取得できません。',
                'error'
            );
        }
    }


    /*
     * =========================================================
     * アンケート一覧描画
     * =========================================================
     */

    function renderSurveyList() {

        const body =
            $('survey-list-body');

        if (!body) {
            return;
        }

        body.textContent = '';


        if (
            surveys.length === 0
        ) {

            const tr =
                document.createElement('tr');

            const td =
                document.createElement('td');

            td.colSpan = 7;

            td.className =
                'empty';

            td.textContent =
                'アンケートがありません。';

            tr.appendChild(td);

            body.appendChild(tr);

            return;
        }


        surveys.forEach(
            function (survey) {

                const tr =
                    document.createElement('tr');


                /*
                 * アンケート名
                 */

                const nameTd =
                    document.createElement('td');

                const nameButton =
                    document.createElement('button');

                nameButton.type =
                    'button';

                nameButton.className =
                    'link-button';

                nameButton.textContent =
                    String(
                        survey?.name ||
                        '名称未設定'
                    );


                nameButton.addEventListener(
                    'click',
                    function () {

                        const surveyId =
                            String(
                                survey?.id || ''
                            );

                        if (!surveyId) {
                            return;
                        }

                        openDetail(
                            surveyId
                        );
                    }
                );


                nameTd.appendChild(
                    nameButton
                );


                /*
                 * 状態
                 */

                const statusTd =
                    document.createElement('td');

                appendStatusBadge(
                    statusTd,
                    String(
                        survey?.status ||
                        'draft'
                    )
                );


                /*
                 * 作成日
                 */

                const createdTd =
                    document.createElement('td');

                createdTd.textContent =
                    String(
                        survey?.created ||
                        ''
                    );


                /*
                 * 公開期間
                 */

                const periodTd =
                    document.createElement('td');

                const start =
                    String(
                        survey?.start ||
                        ''
                    );

                const end =
                    String(
                        survey?.end ||
                        ''
                    );


                if (
                    start ||
                    end
                ) {

                    periodTd.textContent =
                        (
                            start ||
                            '未設定'
                        ) +
                        ' ～ ' +
                        (
                            end ||
                            '未設定'
                        );

                } else {

                    periodTd.textContent =
                        '未設定';
                }


                /*
                 * 回答数
                 */

                const answerTd =
                    document.createElement('td');

                answerTd.textContent =
                    String(
                        Number(
                            survey?.answers || 0
                        )
                    ) +
                    '件';


                /*
                 * 最終更新日
                 */

                const updatedTd =
                    document.createElement('td');

                updatedTd.textContent =
                    String(
                        survey?.updated ||
                        ''
                    );


                /*
                 * 操作
                 */

                const actionTd =
                    document.createElement('td');

                const actionWrap =
                    document.createElement('div');

                actionWrap.className =
                    'editor-actions';


                /*
                 * 開く
                 */

                const openButton =
                    document.createElement('button');

                openButton.type =
                    'button';

                openButton.className =
                    'btn btn-small';

                openButton.textContent =
                    '開く';

                openButton.addEventListener(
                    'click',
                    function () {

                        const id =
                            String(
                                survey?.id ||
                                ''
                            );

                        if (!id) {
                            return;
                        }

                        openDetail(id);
                    }
                );


                actionWrap.appendChild(
                    openButton
                );


                /*
                 * 編集
                 */

                const editButton =
                    document.createElement('button');

                editButton.type =
                    'button';

                editButton.className =
                    'btn btn-small';

                editButton.textContent =
                    '編集';

                editButton.addEventListener(
                    'click',
                    function () {

                        const id =
                            String(
                                survey?.id ||
                                ''
                            );

                        if (!id) {
                            return;
                        }

                        openEditor(
                            id
                        );
                    }
                );


                actionWrap.appendChild(
                    editButton
                );


                /*
                 * 公開
                 */

                if (
                    String(
                        survey?.status ||
                        'draft'
                    ) === 'draft'
                ) {

                    const publishButton =
                        document.createElement(
                            'button'
                        );

                    publishButton.type =
                        'button';

                    publishButton.className =
                        'btn btn-small btn-primary';

                    publishButton.textContent =
                        '公開';


                    publishButton.addEventListener(
                        'click',
                        function () {

                            publishSurvey(
                                publishButton,
                                String(
                                    survey?.id ||
                                    ''
                                )
                            );
                        }
                    );


                    actionWrap.appendChild(
                        publishButton
                    );
                }


                /*
                 * 終了
                 */

                if (
                    String(
                        survey?.status ||
                        ''
                    ) === 'open'
                ) {

                    const endButton =
                        document.createElement(
                            'button'
                        );

                    endButton.type =
                        'button';

                    endButton.className =
                        'btn btn-small';

                    endButton.textContent =
                        '終了';


                    endButton.addEventListener(
                        'click',
                        function () {

                            endSurvey(
                                endButton,
                                String(
                                    survey?.id ||
                                    ''
                                )
                            );
                        }
                    );


                    actionWrap.appendChild(
                        endButton
                    );
                }


                /*
                 * 下書き削除
                 */

                if (
                    String(
                        survey?.status ||
                        'draft'
                    ) === 'draft'
                ) {

                    const deleteButton =
                        document.createElement(
                            'button'
                        );

                    deleteButton.type =
                        'button';

                    deleteButton.className =
                        'btn btn-small btn-danger';

                    deleteButton.textContent =
                        '削除';


                    deleteButton.addEventListener(
                        'click',
                        function () {

                            deleteSurvey(
                                deleteButton,
                                String(
                                    survey?.id ||
                                    ''
                                )
                            );
                        }
                    );


                    actionWrap.appendChild(
                        deleteButton
                    );
                }


                actionTd.appendChild(
                    actionWrap
                );


                tr.appendChild(
                    nameTd
                );

                tr.appendChild(
                    statusTd
                );

                tr.appendChild(
                    createdTd
                );

                tr.appendChild(
                    periodTd
                );

                tr.appendChild(
                    answerTd
                );

                tr.appendChild(
                    updatedTd
                );

                tr.appendChild(
                    actionTd
                );


                body.appendChild(
                    tr
                );
            }
        );
    }


    /*
     * =========================================================
     * アンケート作成用データ
     * =========================================================
     */

    function createEmptySurvey() {

        return {

            id: '',

            name: '',

            description: '',

            start: '',

            end: '',

            status: 'draft',

            numbering: 'global',

            created: '',

            updated: '',

            groups: [

                {
                    id:
                        createClientId('group'),

                    name:
                        '基本情報',

                    questions: [

                        {
                            id:
                                createClientId(
                                    'question'
                                ),

                            text: '',

                            type: 'text',

                            required: false,

                            options: []
                        }

                    ]
                }

            ]
        };
    }


    /*
     * ブラウザ内で一時的に使用するID。
     * 保存時にはサーバー側でも検証する。
     */

    function createClientId(prefix) {

        const random =
            Math.random()
                .toString(36)
                .slice(2, 10);

        return (
            prefix +
            '_' +
            Date.now().toString(36) +
            '_' +
            random
        );
    }


    /*
     * =========================================================
     * エディタの初期表示
     * =========================================================
     */

    function populateEditorForm() {

        if (!editingSurvey) {
            return;
        }


        const name =
            $('editor-name');

        if (name) {
            name.value =
                String(
                    editingSurvey.name ||
                    ''
                );
        }


        const description =
            $('editor-description');

        if (description) {
            description.value =
                String(
                    editingSurvey.description ||
                    ''
                );
        }


        const start =
            $('editor-start');

        if (start) {
            start.value =
                normalizeDateTimeLocal(
                    editingSurvey.start
                );
        }


        const end =
            $('editor-end');

        if (end) {
            end.value =
                normalizeDateTimeLocal(
                    editingSurvey.end
                );
        }


        const status =
            $('editor-status');

        if (status) {
            status.value =
                String(
                    editingSurvey.status ||
                    'draft'
                );
        }


        const numbering =
            String(
                editingSurvey.numbering ||
                'global'
            );


        const globalRadio =
            $('numbering-global');

        if (globalRadio) {

            globalRadio.checked =
                numbering === 'global';
        }


        const groupRadio =
            $('numbering-group');

        if (groupRadio) {

            groupRadio.checked =
                numbering === 'group';
        }


        renderEditorGroups();
    }


    function normalizeDateTimeLocal(
        value
    ) {

        if (
            value === null ||
            value === undefined
        ) {
            return '';
        }

        const text =
            String(value);

        if (
            text.length >= 16 &&
            text.charAt(10) === 'T'
        ) {

            return text.slice(
                0,
                16
            );
        }

        return text;
    }


    /*
     * =========================================================
     * エディタ表示
     * =========================================================
     */

    function renderEditorGroups() {

        const container =
            $('editor-groups');

        if (
            !container ||
            !editingSurvey
        ) {
            return;
        }

        container.textContent = '';


        const groups =
            Array.isArray(
                editingSurvey.groups
            )
                ? editingSurvey.groups
                : [];


        groups.forEach(
            function (
                group,
                groupIndex
            ) {

                renderEditorGroup(
                    container,
                    group,
                    groupIndex
                );
            }
        );


        updateQuestionNumbers();
    }


    function renderEditorGroup(
        container,
        group,
        groupIndex
    ) {

        if (!container || !group) {
            return;
        }


        const groupCard =
            document.createElement('div');

        groupCard.className =
            'group-card';

        groupCard.dataset.groupId =
            String(
                group.id || ''
            );

        groupCard.draggable = true;


        groupCard.addEventListener(
            'dragstart',
            function () {

                draggedGroupId =
                    String(
                        group.id || ''
                    );

                groupCard.classList.add(
                    'dragging'
                );
            }
        );


        groupCard.addEventListener(
            'dragend',
            function () {

                draggedGroupId = '';

                groupCard.classList.remove(
                    'dragging'
                );

                document
                    .querySelectorAll(
                        '.group-card.drag-over'
                    )
                    .forEach(
                        function (element) {

                            element.classList.remove(
                                'drag-over'
                            );
                        }
                    );
            }
        );


        groupCard.addEventListener(
            'dragover',
            function (event) {

                event.preventDefault();

                if (
                    draggedGroupId &&
                    draggedGroupId !==
                        String(
                            group.id || ''
                        )
                ) {

                    groupCard.classList.add(
                        'drag-over'
                    );
                }
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

                moveGroup(
                    draggedGroupId,
                    String(
                        group.id || ''
                    )
                );
            }
        );


        /*
         * グループヘッダー
         */

        const header =
            document.createElement('div');

        header.className =
            'group-header';


        const handle =
            document.createElement('span');

        handle.className =
            'drag-handle';

        handle.textContent =
            '☷';

        handle.setAttribute(
            'aria-hidden',
            'true'
        );


        const titleWrap =
            document.createElement('div');

        titleWrap.className =
            'group-title';


        const titleInput =
            document.createElement('input');

        titleInput.type =
            'text';

        titleInput.maxLength =
            200;

        titleInput.value =
            String(
                group.name ||
                ''
            );

        titleInput.setAttribute(
            'aria-label',
            'グループ名'
        );


        titleInput.addEventListener(
            'input',
            function () {

                group.name =
                    titleInput.value;

                markEditorDirty();
            }
        );


        titleWrap.appendChild(
            titleInput
        );


        const actions =
            document.createElement('div');

        actions.className =
            'group-actions';


        const deleteGroupButton =
            document.createElement(
                'button'
            );

        deleteGroupButton.type =
            'button';

        deleteGroupButton.className =
            'btn btn-small btn-danger';

        deleteGroupButton.textContent =
            'グループ削除';


        deleteGroupButton.addEventListener(
            'click',
            function () {

                removeGroup(
                    deleteGroupButton,
                    String(
                        group.id ||
                        ''
                    )
                );
            }
        );


        actions.appendChild(
            deleteGroupButton
        );


        header.appendChild(
            handle
        );

        header.appendChild(
            titleWrap
        );

        header.appendChild(
            actions
        );


        groupCard.appendChild(
            header
        );


        /*
         * 質問
         */

        const questions =
            Array.isArray(
                group.questions
            )
                ? group.questions
                : [];


        questions.forEach(
            function (
                question,
                questionIndex
            ) {

                renderEditorQuestion(
                    groupCard,
                    group,
                    question,
                    groupIndex,
                    questionIndex
                );
            }
        );


        /*
         * 質問追加
         */

        const addQuestionArea =
            document.createElement('div');

        addQuestionArea.className =
            'add-question-area';


        const addQuestionButton =
            document.createElement(
                'button'
            );

        addQuestionButton.type =
            'button';

        addQuestionButton.className =
            'btn';

        addQuestionButton.textContent =
            '＋ 質問追加';


        addQuestionButton.addEventListener(
            'click',
            function () {

                addQuestion(
                    addQuestionButton,
                    String(
                        group.id ||
                        ''
                    )
                );
            }
        );


        addQuestionArea.appendChild(
            addQuestionButton
        );

        groupCard.appendChild(
            addQuestionArea
        );


        container.appendChild(
            groupCard
        );
    }


    /*
     * =========================================================
     * ここまでが 2/5
     * =========================================================
     *
     * 次の 3/5 は、この直後の
     *
     * renderEditorQuestion()
     *
     * から続ける。
     *
     * 3/5では、質問編集・選択肢・分岐設定・
     * 並べ替え処理を続ける。
     */
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

        <button id="btn-open-create"
            class="btn btn-primary"
            type="button">
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
            <div class="subtext">
                アンケート全体を1画面で編集できます
            </div>
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
                <input id="survey-name"
                    type="text"
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

    const csrfMeta =
        document.querySelector('meta[name="csrf-token"]');

    const apiMeta =
        document.querySelector('meta[name="api-path"]');

    const csrfToken =
        csrfMeta ? csrfMeta.content : '';

    /*
     * APIパスはPHP自身が現在のindex.phpの場所から生成したものだけを使用する。
     *
     * ここではホスト名を組み立てない。
     * したがって、
     * https://n11-1041/...
     * のような環境依存の固定URLにはならない。
     */
    const apiPath =
        apiMeta && apiMeta.content
            ? apiMeta.content
            : window.location.pathname;

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

    /*
     * DOMへ文字列を直接HTMLとして挿入しないための共通処理。
     * 通常の描画ではtextContentを優先する。
     */
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

        toast.textContent =
            String(message || '');

        toast.classList.add('show');

        if (toastTimer !== null) {
            window.clearTimeout(toastTimer);
        }

        toastTimer =
            window.setTimeout(function () {
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

        const div =
            document.createElement('div');

        div.className =
            'notice' +
            (type ? ' ' + type : '');

        div.textContent =
            String(message);

        target.appendChild(div);
    }

    /*
     * 通信開始前にdisabledとローディング状態を設定する。
     */
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
     * API URL生成。
     *
     * 重要：
     * 外部ホストを生成しない。
     * 現在表示しているApache上のindex.php自身へ相対的に送る。
     */
    function buildApiUrl(action, params) {
        const query =
            new URLSearchParams();

        query.set(
            'action',
            String(action || '')
        );

        if (
            params &&
            typeof params === 'object'
        ) {
            Object.keys(params).forEach(
                function (key) {
                    const value =
                        params[key];

                    if (
                        value !== undefined &&
                        value !== null &&
                        String(value) !== ''
                    ) {
                        query.set(
                            key,
                            String(value)
                        );
                    }
                }
            );
        }

        const separator =
            apiPath.includes('?')
                ? '&'
                : '?';

        return (
            apiPath +
            separator +
            query.toString()
        );
    }

    async function apiGet(action, params) {
        const url =
            buildApiUrl(
                action,
                params
            );

        const response =
            await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept':
                        'application/json'
                }
            });

        const text =
            await response.text();

        let data = null;

        try {
            data = JSON.parse(text);
        } catch (error) {
            throw new Error(
                'サーバーから正しい応答を取得できませんでした。'
            );
        }

        if (
            !response.ok ||
            !data ||
            data.success !== true
        ) {
            throw new Error(
                data &&
                typeof data.message === 'string' &&
                data.message
                    ? data.message
                    : '処理に失敗しました。'
            );
        }

        return data;
    }

    async function apiPost(
        button,
        action,
        payload
    ) {
        /*
         * fetchより前に必ずUIをロックする。
         */
        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }

        try {
            const url =
                buildApiUrl(action);

            const response =
                await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Content-Type':
                            'application/json; charset=utf-8',
                        'Accept':
                            'application/json',
                        'X-CSRF-Token':
                            csrfToken
                    },
                    body:
                        JSON.stringify(
                            payload || {}
                        )
                });

            const text =
                await response.text();

            let data = null;

            try {
                data = JSON.parse(text);
            } catch (error) {
                throw new Error(
                    'サーバーから正しい応答を取得できませんでした。'
                );
            }

            if (
                !response.ok ||
                !data ||
                data.success !== true
            ) {
                throw new Error(
                    data &&
                    typeof data.message === 'string' &&
                    data.message
                        ? data.message
                        : '処理に失敗しました。'
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

    function showPage(id) {
        const pageIds = [
            'page-list',
            'page-editor',
            'page-detail',
            'page-customers',
            'page-settings'
        ];

        pageIds.forEach(
            function (pageId) {
                const element =
                    $(pageId);

                if (element) {
                    element.classList.toggle(
                        'hidden',
                        pageId !== id
                    );
                }
            }
        );

        const navIds = [
            'nav-list',
            'nav-create',
            'nav-customers',
            'nav-settings'
        ];

        navIds.forEach(
            function (navId) {
                const element =
                    $(navId);

                if (element) {
                    element.classList.remove(
                        'active'
                    );
                }
            }
        );

        if (
            id === 'page-list'
        ) {
            const nav =
                $('nav-list');

            if (nav) {
                nav.classList.add(
                    'active'
                );
            }
        }

        if (
            id === 'page-editor'
        ) {
            const nav =
                $('nav-create');

            if (nav) {
                nav.classList.add(
                    'active'
                );
            }
        }

        if (
            id === 'page-customers'
        ) {
            const nav =
                $('nav-customers');

            if (nav) {
                nav.classList.add(
                    'active'
                );
            }
        }

        if (
            id === 'page-settings'
        ) {
            const nav =
                $('nav-settings');

            if (nav) {
                nav.classList.add(
                    'active'
                );
            }
        }
    }

    function openModal(
        title,
        body,
        footer
    ) {
        const modal =
            $('modal');

        const modalTitle =
            $('modal-title');

        const modalBody =
            $('modal-body');

        const modalFooter =
            $('modal-footer');

        if (!modal) {
            return;
        }

        if (modalTitle) {
            modalTitle.textContent =
                String(title || '');
        }

        if (modalBody) {
            modalBody.textContent = '';

            if (body instanceof Node) {
                modalBody.appendChild(body);
            }
        }

        if (modalFooter) {
            modalFooter.textContent = '';

            if (footer instanceof Node) {
                modalFooter.appendChild(
                    footer
                );
            }
        }

        modal.classList.remove(
            'hidden'
        );
    }

    function closeModal() {
        const modal =
            $('modal');

        if (modal) {
            modal.classList.add(
                'hidden'
            );
        }
    }

    function createButton(
        text,
        className
    ) {
        const button =
            document.createElement(
                'button'
            );

        button.type = 'button';

        button.className =
            className || 'btn';

        button.textContent =
            String(text || '');

        return button;
    }

    function createLoadingButton(
        text,
        className
    ) {
        const button =
            createButton(
                '',
                className || 'btn'
            );

        const spinner =
            document.createElement(
                'span'
            );

        spinner.className =
            'loading-spinner';

        button.appendChild(
            spinner
        );

        const label =
            document.createElement(
                'span'
            );

        label.textContent =
            String(text || '');

        button.appendChild(
            label
        );

        return button;
    }

    function cloneSurvey(source) {
        try {
            return JSON.parse(
                JSON.stringify(
                    source
                )
            );
        } catch (error) {
            return null;
        }
    }

    function createEmptySurvey() {
        return {
            id: '',
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
    }

    function createEmptyGroup() {
        const group = {
            id:
                'group_' +
                Date.now() +
                '_' +
                Math.random()
                    .toString(16)
                    .slice(2),
            name:
                'グループ ' +
                String(nextGroupNo),
            questions: []
        };

        nextGroupNo += 1;

        return group;
    }

    function createEmptyQuestion() {
        const question = {
            id:
                'question_' +
                Date.now() +
                '_' +
                Math.random()
                    .toString(16)
                    .slice(2),
            text: '',
            type: 'free',
            required: false,
            options: []
        };

        nextQuestionNo += 1;

        return question;
    }

    function questionNumber(
        groupIndex,
        questionIndex
    ) {
        if (
            !editingSurvey ||
            editingSurvey.numbering !==
                'group'
        ) {
            return 'Q' +
                String(
                    calculateGlobalQuestionNumber(
                        groupIndex,
                        questionIndex
                    )
                );
        }

        return (
            'Q' +
            String(groupIndex + 1) +
            '-' +
            String(questionIndex + 1)
        );
    }

    function calculateGlobalQuestionNumber(
        groupIndex,
        questionIndex
    ) {
        if (
            !editingSurvey ||
            !Array.isArray(
                editingSurvey.groups
            )
        ) {
            return 1;
        }

        let number = 1;

        for (
            let i = 0;
            i < groupIndex;
            i += 1
        ) {
            const group =
                editingSurvey.groups[i];

            if (
                group &&
                Array.isArray(
                    group.questions
                )
            ) {
                number +=
                    group.questions.length;
            }
        }

        return (
            number +
            questionIndex
        );
    }

    function normalizeLocalSurvey(
        survey
    ) {
        const normalized =
            cloneSurvey(survey);

        if (!normalized) {
            return createEmptySurvey();
        }

        if (
            !Array.isArray(
                normalized.groups
            )
        ) {
            normalized.groups = [];
        }

        normalized.groups =
            normalized.groups.map(
                function (group) {
                    const result =
                        group &&
                        typeof group ===
                            'object'
                            ? group
                            : createEmptyGroup();

                    if (
                        !result.id
                    ) {
                        result.id =
                            'group_' +
                            Date.now() +
                            '_' +
                            Math.random()
                                .toString(16)
                                .slice(2);
                    }

                    if (
                        !result.name
                    ) {
                        result.name =
                            'グループ';
                    }

                    if (
                        !Array.isArray(
                            result.questions
                        )
                    ) {
                        result.questions =
                            [];
                    }

                    result.questions =
                        result.questions.map(
                            function (
                                question
                            ) {
                                const item =
                                    question &&
                                    typeof question ===
                                        'object'
                                        ? question
                                        : createEmptyQuestion();

                                if (
                                    !item.id
                                ) {
                                    item.id =
                                        'question_' +
                                        Date.now() +
                                        '_' +
                                        Math.random()
                                            .toString(16)
                                            .slice(2);
                                }

                                if (
                                    typeof item.text !==
                                    'string'
                                ) {
                                    item.text =
                                        String(
                                            item.text ??
                                            ''
                                        );
                                }

                                if (
                                    ![
                                        'free',
                                        'single',
                                        'multiple'
                                    ].includes(
                                        item.type
                                    )
                                ) {
                                    item.type =
                                        'free';
                                }

                                item.required =
                                    Boolean(
                                        item.required
                                    );

                                if (
                                    !Array.isArray(
                                        item.options
                                    )
                                ) {
                                    item.options =
                                        [];
                                }

                                item.options =
                                    item.options.map(
                                        function (
                                            option
                                        ) {
                                            if (
                                                !option ||
                                                typeof option !==
                                                    'object'
                                            ) {
                                                return {
                                                    text: '',
                                                    branch: ''
                                                };
                                            }

                                            return {
                                                text:
                                                    String(
                                                        option.text ??
                                                        ''
                                                    ),
                                                branch:
                                                    String(
                                                        option.branch ??
                                                        ''
                                                    )
                                            };
                                        }
                                    );

                                return item;
                            }
                        );

                    return result;
                }
            );

        return normalized;
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

    function questionNumber(
        groupIndex,
        questionIndex
    ) {
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

        for (
            let i = 0;
            i < groupIndex;
            i++
        ) {
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

                    groupCard.classList.add(
                        'dragging'
                    );
                }
            );

            groupCard.addEventListener(
                'dragend',
                function () {
                    draggedGroupId = '';

                    groupCard.classList.remove(
                        'dragging'
                    );
                }
            );

            groupCard.addEventListener(
                'dragover',
                function (event) {
                    event.preventDefault();

                    groupCard.classList.add(
                        'drag-over'
                    );
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

            questions.className =
                'group-questions';

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

                card.classList.add(
                    'dragging'
                );
            }
        );

        card.addEventListener(
            'dragend',
            function () {
                draggedQuestion = '';

                card.classList.remove(
                    'dragging'
                );
            }
        );

        card.addEventListener(
            'dragover',
            function (event) {
                event.preventDefault();

                card.classList.add(
                    'drag-over'
                );
            }
        );

        card.addEventListener(
            'dragleave',
            function () {
                card.classList.remove(
                    'drag-over'
                );
            }
        );

        card.addEventListener(
            'drop',
            function (event) {
                event.preventDefault();

                card.classList.remove(
                    'drag-over'
                );

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
                    !Array.isArray(
                        question.options
                    ) ||
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

                    row.className =
                        'option-row';

                    const optionInput =
                        document.createElement(
                            'input'
                        );

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
                            document.createElement(
                                'select'
                            );

                        const emptyOption =
                            document.createElement(
                                'option'
                            );

                        emptyOption.value = '';

                        emptyOption.textContent =
                            '分岐なし';

                        branch.appendChild(
                            emptyOption
                        );

                        const groups =
                            Array.isArray(
                                editingSurvey.groups
                            )
                                ? editingSurvey.groups
                                : [];

                        groups.forEach(
                            function (
                                branchGroup
                            ) {
                                if (
                                    String(
                                        branchGroup.id
                                    ) ===
                                    String(group.id)
                                ) {
                                    return;
                                }

                                const branchOption =
                                    document.createElement(
                                        'option'
                                    );

                                branchOption.value =
                                    String(
                                        branchGroup.id ||
                                        ''
                                    );

                                branchOption.textContent =
                                    String(
                                        branchGroup.name ||
                                        ''
                                    );

                                branch.appendChild(
                                    branchOption
                                );
                            }
                        );

                        branch.value =
                            String(
                                item.branch || ''
                            );

                        branch.addEventListener(
                            'change',
                            function () {
                                item.branch =
                                    branch.value;
                            }
                        );

                        row.appendChild(branch);
                    }

                    const removeOptionButton =
                        document.createElement(
                            'button'
                        );

                    removeOptionButton.type =
                        'button';

                    removeOptionButton.className =
                        'btn btn-small';

                    removeOptionButton.textContent =
                        '削除';

                    removeOptionButton.addEventListener(
                        'click',
                        function () {
                            if (
                                question.options
                                    .length <= 2
                            ) {
                                showToast(
                                    '選択肢は2つ以上残してください。'
                                );
                                return;
                            }

                            question.options.splice(
                                optionIndex,
                                1
                            );

                            renderEditor();
                        }
                    );

                    row.appendChild(
                        removeOptionButton
                    );

                    options.appendChild(row);
                }
            );

            const addOptionButton =
                document.createElement('button');

            addOptionButton.type = 'button';
            addOptionButton.className =
                'btn btn-small';

            addOptionButton.textContent =
                '＋ 選択肢追加';

            addOptionButton.addEventListener(
                'click',
                function () {
                    question.options.push({
                        text: '',
                        branch: ''
                    });

                    renderEditor();
                }
            );

            options.appendChild(
                addOptionButton
            );

            card.appendChild(options);
        }

        return card;
    }

    function addGroup() {
        if (!editingSurvey) {
            return;
        }

        if (
            !Array.isArray(
                editingSurvey.groups
            )
        ) {
            editingSurvey.groups = [];
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
                'グループは1つ以上残してください。'
            );
            return;
        }

        const index =
            editingSurvey.groups.findIndex(
                function (group) {
                    return String(group.id) ===
                        String(id);
                }
            );

        if (index < 0) {
            return;
        }

        editingSurvey.groups.splice(
            index,
            1
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

        if (
            !Array.isArray(group.questions)
        ) {
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
            groupIndex <
            editingSurvey.groups.length;
            groupIndex++
        ) {
            const group =
                editingSurvey.groups[groupIndex];

            const index =
                group.questions.findIndex(
                    function (question) {
                        return String(
                            question.id
                        ) === String(id);
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

                group.questions.splice(
                    index,
                    1
                );

                renderEditor();

                return;
            }
        }
    }

    function moveGroup(
        sourceId,
        targetId
    ) {
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

    function moveQuestion(
        sourceId,
        targetId
    ) {
        if (!editingSurvey) {
            return;
        }

        let sourceGroup = null;
        let sourceIndex = -1;

        editingSurvey.groups.forEach(
            function (group) {
                group.questions.forEach(
                    function (
                        question,
                        index
                    ) {
                        if (
                            String(
                                question.id
                            ) ===
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
                    function (
                        question,
                        index
                    ) {
                        if (
                            String(
                                question.id
                            ) ===
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

        if (
            sourceGroup === targetGroup
        ) {
            if (
                sourceIndex < targetIndex
            ) {
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
    function collectEditorValues() {
        if (!editingSurvey) {
            return null;
        }

        const name = $('survey-name');
        const description = $('survey-description');
        const status = $('survey-status');
        const start = $('survey-start');
        const end = $('survey-end');

        if (name) {
            editingSurvey.name =
                name.value.trim();
        }

        if (description) {
            editingSurvey.description =
                description.value.trim();
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

        if (
            numbering instanceof HTMLInputElement
        ) {
            editingSurvey.numbering =
                numbering.value;
        }

        return editingSurvey;
    }

    function validateSurvey() {
        if (!editingSurvey) {
            return 'アンケート情報がありません。';
        }

        if (!editingSurvey.name.trim()) {
            return 'アンケート名を入力してください。';
        }

        if (
            !Array.isArray(
                editingSurvey.groups
            ) ||
            editingSurvey.groups.length === 0
        ) {
            return 'グループを1つ以上登録してください。';
        }

        for (
            let groupIndex = 0;
            groupIndex <
            editingSurvey.groups.length;
            groupIndex++
        ) {
            const group =
                editingSurvey.groups[groupIndex];

            if (!String(group.name || '').trim()) {
                return (
                    'グループ' +
                    String(groupIndex + 1) +
                    'の名称を入力してください。'
                );
            }

            if (
                !Array.isArray(group.questions) ||
                group.questions.length === 0
            ) {
                return (
                    'グループ「' +
                    String(group.name || '') +
                    '」に質問を1つ以上登録してください。'
                );
            }

            for (
                let questionIndex = 0;
                questionIndex <
                group.questions.length;
                questionIndex++
            ) {
                const question =
                    group.questions[questionIndex];

                if (
                    !String(
                        question.text || ''
                    ).trim()
                ) {
                    return (
                        'グループ「' +
                        String(group.name || '') +
                        '」の質問' +
                        String(questionIndex + 1) +
                        'を入力してください。'
                    );
                }

                if (
                    question.type === 'single' ||
                    question.type === 'multiple'
                ) {
                    if (
                        !Array.isArray(
                            question.options
                        ) ||
                        question.options.length < 2
                    ) {
                        return (
                            '質問「' +
                            String(question.text || '') +
                            '」には選択肢を2つ以上登録してください。'
                        );
                    }

                    for (
                        let optionIndex = 0;
                        optionIndex <
                        question.options.length;
                        optionIndex++
                    ) {
                        if (
                            !String(
                                question.options[
                                    optionIndex
                                ].text || ''
                            ).trim()
                        ) {
                            return (
                                '質問「' +
                                String(question.text || '') +
                                '」の選択肢' +
                                String(optionIndex + 1) +
                                'を入力してください。'
                            );
                        }
                    }
                }
            }
        }

        if (
            editingSurvey.start &&
            editingSurvey.end &&
            editingSurvey.start >
            editingSurvey.end
        ) {
            return '開始日時は終了日時より前にしてください。';
        }

        return '';
    }

    async function saveSurvey(button) {
        if (!button) {
            return;
        }

        button.disabled = true;
        button.classList.add('loading');

        try {
            collectEditorValues();

            const validation =
                validateSurvey();

            if (validation) {
                showToast(validation);
                return;
            }

            const result =
                await apiPost(
                    'save_survey',
                    {
                        survey: editingSurvey
                    }
                );

            if (
                result.data &&
                result.data.survey
            ) {
                editingSurvey =
                    JSON.parse(
                        JSON.stringify(
                            result.data.survey
                        )
                    );
            }

            showToast(
                'アンケートを保存しました。'
            );

            await loadSurveys();

            showPage('page-list');
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : '保存に失敗しました。'
            );
        } finally {
            button.disabled = false;
            button.classList.remove('loading');
        }
    }

    async function deleteSurvey(
        id,
        button
    ) {
        if (!button || !id) {
            return;
        }

        if (
            !window.confirm(
                'このアンケートを削除しますか？'
            )
        ) {
            return;
        }

        button.disabled = true;
        button.classList.add('loading');

        try {
            await apiPost(
                'delete_survey',
                {id: id}
            );

            showToast(
                'アンケートを削除しました。'
            );

            await loadSurveys();
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : '削除に失敗しました。'
            );
        } finally {
            button.disabled = false;
            button.classList.remove('loading');
        }
    }

    async function closeSurvey(
        id,
        button
    ) {
        if (!button || !id) {
            return;
        }

        if (
            !window.confirm(
                'アンケートを終了しますか？'
            )
        ) {
            return;
        }

        button.disabled = true;
        button.classList.add('loading');

        try {
            await apiPost(
                'close_survey',
                {id: id}
            );

            showToast(
                'アンケートを終了しました。'
            );

            await loadSurveys();
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : '終了処理に失敗しました。'
            );
        } finally {
            button.disabled = false;
            button.classList.remove('loading');
        }
    }

    async function loadSettings() {
        try {
            const result =
                await apiGet(
                    'get_settings'
                );

            const settings =
                result.data?.settings || {};

            const domain =
                $('kintone-domain');

            const appId =
                $('kintone-app-id');

            const proxy =
                $('proxy-host-port');

            const loginName =
                $('cybozu-login-name');

            if (domain) {
                domain.value =
                    String(
                        settings.domain || ''
                    );
            }

            if (appId) {
                appId.value =
                    String(
                        settings.app_id || ''
                    );
            }

            if (proxy) {
                proxy.value =
                    String(
                        settings.proxy_host_port || ''
                    );
            }

            if (loginName) {
                loginName.value =
                    String(
                        settings.login_name || ''
                    );
            }
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : '設定を取得できません。'
            );
        }
    }

    async function saveSettings(button) {
        if (!button) {
            return;
        }

        button.disabled = true;
        button.classList.add('loading');

        try {
            const domain =
                $('kintone-domain');

            const appId =
                $('kintone-app-id');

            const proxy =
                $('proxy-host-port');

            const loginName =
                $('cybozu-login-name');

            const password =
                $('cybozu-password');

            const settings = {
                domain:
                    domain
                        ? domain.value.trim()
                        : '',
                app_id:
                    appId
                        ? appId.value.trim()
                        : '',
                proxy_host_port:
                    proxy
                        ? proxy.value.trim()
                        : '',
                login_name:
                    loginName
                        ? loginName.value.trim()
                        : ''
            };

            if (password) {
                settings.password =
                    password.value;
            }

            if (!settings.domain) {
                showToast(
                    'kintoneドメインを入力してください。'
                );
                return;
            }

            if (!settings.app_id) {
                showToast(
                    'アプリIDを入力してください。'
                );
                return;
            }

            const result =
                await apiPost(
                    'save_settings',
                    settings
                );

            if (password) {
                password.value = '';
            }

            showToast(
                result.data?.message ||
                '設定を保存しました。'
            );
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : '設定の保存に失敗しました。'
            );
        } finally {
            button.disabled = false;
            button.classList.remove('loading');
        }
    }

    async function testKintoneConnection(
        button
    ) {
        if (!button) {
            return;
        }

        button.disabled = true;
        button.classList.add('loading');

        try {
            const domain =
                $('kintone-domain');

            const appId =
                $('kintone-app-id');

            const proxy =
                $('proxy-host-port');

            const loginName =
                $('cybozu-login-name');

            const password =
                $('cybozu-password');

            const payload = {
                domain:
                    domain
                        ? domain.value.trim()
                        : '',
                app_id:
                    appId
                        ? appId.value.trim()
                        : '',
                proxy_host_port:
                    proxy
                        ? proxy.value.trim()
                        : '',
                login_name:
                    loginName
                        ? loginName.value.trim()
                        : '',
                password:
                    password
                        ? password.value
                        : ''
            };

            const result =
                await apiPost(
                    'test_kintone',
                    payload
                );

            showToast(
                result.data?.message ||
                'kintoneへの接続に成功しました。'
            );
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : 'kintoneへの接続に失敗しました。'
            );
        } finally {
            button.disabled = false;
            button.classList.remove('loading');
        }
    }

    function setupNavigation() {
        document
            .querySelectorAll(
                '[data-page]'
            )
            .forEach(function (element) {
                if (!element) {
                    return;
                }

                element.addEventListener(
                    'click',
                    function () {
                        const page =
                            element.getAttribute(
                                'data-page'
                            );

                        if (!page) {
                            return;
                        }

                        showPage(page);

                        if (
                            page === 'page-list'
                        ) {
                            loadSurveys();
                        }

                        if (
                            page === 'page-settings'
                        ) {
                            loadSettings();
                        }
                    }
                );
            });
    }

    function setupButtons() {
        const createButton =
            $('btn-create');

        if (createButton) {
            createButton.addEventListener(
                'click',
                function () {
                    createButton.disabled =
                        true;
                    createButton.classList.add(
                        'loading'
                    );

                    try {
                        openCreate();
                    } finally {
                        createButton.disabled =
                            false;
                        createButton.classList.remove(
                            'loading'
                        );
                    }
                }
            );
        }

        const addGroupButton =
            $('btn-add-group');

        if (addGroupButton) {
            addGroupButton.addEventListener(
                'click',
                function () {
                    addGroupButton.disabled =
                        true;
                    addGroupButton.classList.add(
                        'loading'
                    );

                    try {
                        addGroup();
                    } finally {
                        addGroupButton.disabled =
                            false;
                        addGroupButton.classList.remove(
                            'loading'
                        );
                    }
                }
            );
        }

        const saveButton =
            $('btn-save-survey');

        if (saveButton) {
            saveButton.addEventListener(
                'click',
                function () {
                    saveSurvey(saveButton);
                }
            );
        }

        const settingsButton =
            $('btn-save-settings');

        if (settingsButton) {
            settingsButton.addEventListener(
                'click',
                function () {
                    saveSettings(
                        settingsButton
                    );
                }
            );
        }

        const testButton =
            $('btn-test-kintone');

        if (testButton) {
            testButton.addEventListener(
                'click',
                function () {
                    testKintoneConnection(
                        testButton
                    );
                }
            );
        }

        const backButton =
            $('btn-back-list');

        if (backButton) {
            backButton.addEventListener(
                'click',
                function () {
                    showPage('page-list');
                    loadSurveys();
                }
            );
        }
    }

    function setupEditorEvents() {
        const numberingElements =
            document.querySelectorAll(
                'input[name="numbering"]'
            );

        numberingElements.forEach(
            function (element) {
                if (!element) {
                    return;
                }

                element.addEventListener(
                    'change',
                    function () {
                        if (
                            editingSurvey &&
                            element instanceof
                                HTMLInputElement &&
                            element.checked
                        ) {
                            editingSurvey.numbering =
                                element.value;

                            renderEditor();
                        }
                    }
                );
            }
        );
    }

    function setupInitialState() {
        showPage('page-list');
        loadSurveys();
    }

    setupNavigation();
    setupButtons();
    setupEditorEvents();
    setupInitialState();
});
</script>

</body>
</html>

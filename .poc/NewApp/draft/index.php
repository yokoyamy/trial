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
 * 現在のリクエストメソッド。
 */
function request_method(): string
{
    return strtoupper(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
    );
}

/**
 * APIアクション取得。
 */
function requested_action(): string
{
    $action = $_GET['action'] ?? '';

    return is_string($action)
        ? trim($action)
        : '';
}

/**
 * CSRFトークン取得。
 */
function csrf_token(): string
{
    $token =
        $_SESSION[APP_SESSION_KEY]['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        $token === ''
    ) {
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
 * CSRF検証。
 */
function validate_csrf(): void
{
    $token =
        $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        !is_string($token) ||
        $token === ''
    ) {
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
 * JSONリクエスト本文を取得。
 */
function read_json_request(): array
{
    $raw = file_get_contents('php://input');

    if (
        $raw === false ||
        trim($raw) === ''
    ) {
        return [];
    }

    $decoded = json_decode(
        $raw,
        true
    );

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
 * データディレクトリを作成。
 */
function ensure_data_directory(): void
{
    if (is_dir(DATA_DIRECTORY)) {
        return;
    }

    if (
        !mkdir(
            DATA_DIRECTORY,
            0700,
            true
        ) &&
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
            'proxy_host_port' => '',
            'configured' => false,
            'connection_status' => 'not_configured',
            'tested_at' => null
        ]
    ];
}

/**
 * 初期顧客データ。
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
 * 初期アンケートデータ。
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
 * 初期回答データ。
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
 * 初期送信履歴。
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

    if (
        $contents === false ||
        trim($contents) === ''
    ) {
        return $default;
    }

    $decoded = json_decode(
        $contents,
        true
    );

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
 * JSONファイルを安全に保存。
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
    } catch (\Throwable) {
        json_response(
            false,
            'データを保存できません。',
            [],
            [],
            500
        );
    }

    $temporaryFile = tempnam(
        DATA_DIRECTORY,
        'newapp_'
    );

    if ($temporaryFile === false) {
        json_response(
            false,
            'データを保存できません。',
            [],
            [],
            500
        );
    }

    $handle = fopen(
        $temporaryFile,
        'wb'
    );

    if ($handle === false) {
        @unlink($temporaryFile);

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

        if (!rename(
            $temporaryFile,
            $file
        )) {
            throw new \RuntimeException(
                'rename'
            );
        }
    } catch (\Throwable) {
        if (is_resource($handle)) {
            @flock(
                $handle,
                LOCK_UN
            );
            @fclose($handle);
        }

        @unlink($temporaryFile);

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
 * データファイル初期化。
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
        bin2hex(
            random_bytes(4)
        );
}

/**
 * 現在のindex.phpのパス。
 */
function application_api_path(): string
{
    $script =
        $_SERVER['SCRIPT_NAME'] ??
        'index.php';

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
 * kintone URLを統一的に作成。
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
    $login_name = trim($login_name);
    $password = trim($password);

    $credentials =
        $login_name .
        ':' .
        $password;

    return
        'X-Cybozu-Authorization: ' .
        base64_encode(
            $credentials
        );
}

/**
 * PHP 8.4/8.5対応のレスポンスヘッダー取得。
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
 * HTTPステータスコード取得。
 */
function extract_http_status(
    array $headers
): int {
    foreach ($headers as $header) {
        if (
            !is_string($header)
        ) {
            continue;
        }

        if (
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
 * プロキシ設定を正規化。
 *
 * host:port の入力を優先し、
 * 既存のhost/port指定も受け取れるようにする。
 */
function normalize_proxy(
    array $config
): string {
    $combined = trim(
        (string)(
            $config['proxy_host_port'] ??
            ''
        )
    );

    if ($combined !== '') {
        $combined =
            preg_replace(
                '/^https?:\/\//i',
                '',
                $combined
            );

        return trim(
            (string)$combined
        );
    }

    $host = trim(
        (string)(
            $config['proxy_host'] ??
            ''
        )
    );

    $port = trim(
        (string)(
            $config['proxy_port'] ??
            ''
        )
    );

    if ($host === '') {
        return '';
    }

    if ($port === '') {
        return $host;
    }

    return $host . ':' . $port;
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
    $method =
        strtoupper($method);

    $httpOptions = [
        'method' => $method,
        'header' => implode(
            "\r\n",
            $headers
        ),
        'ignore_errors' => true,
        'timeout' => 20,
        'protocol_version' => 1.1
    ];

    if (
        $method !== 'GET' &&
        $payload !== []
    ) {
        try {
            $httpOptions['content'] =
                json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES |
                    JSON_THROW_ON_ERROR
                );
        } catch (\Throwable) {
            return [
                'success' => false,
                'status' => 0,
                'message' =>
                    '通信データを作成できませんでした。',
                'data' => [],
                'errors' => []
            ];
        }
    }

    $contextOptions = [
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];

    /*
     * プロキシはhost:portで受け取れるようにする。
     * 指定されている場合はproxyとrequest_fulluriを適用する。
     */
    $proxy = normalize_proxy($config);

    if ($proxy !== '') {
        $contextOptions['http']['proxy'] =
            'tcp://' . $proxy;

        $contextOptions['http']['request_fulluri'] =
            true;
    }

    $context = stream_context_create(
        $contextOptions
    );

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    $responseHeaders =
        get_safe_response_headers();

    $status =
        extract_http_status(
            $responseHeaders
        );

    $decoded = [];

    if (
        $response !== false &&
        trim($response) !== ''
    ) {
        $decodedValue =
            json_decode(
                $response,
                true
            );

        if (is_array($decodedValue)) {
            $decoded = $decodedValue;
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
            'data' => $decoded,
            'errors' => []
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
            if (
                !is_array($error)
            ) {
                continue;
            }

            $messages =
                $error['messages'] ??
                [];

            if (
                !is_array($messages)
            ) {
                continue;
            }

            foreach (
                $messages
                as $errorMessage
            ) {
                if (
                    is_string(
                        $errorMessage
                    )
                ) {
                    $errors[] =
                        (string)$field .
                        ': ' .
                        $errorMessage;
                }
            }
        }
    }

    return [
        'success' => false,
        'status' => $status,
        'message' => $message,
        'data' => $decoded,
        'errors' => $errors
    ];
}

/**
 * kintone接続確認。
 */
function test_kintone(
    array $settings
): array {
    $domain =
        trim(
            (string)(
                $settings['domain'] ??
                ''
            )
        );

    $login =
        trim(
            (string)(
                $settings['login_name'] ??
                ''
            )
        );

    $password =
        (string)(
            $settings['password'] ??
            ''
        );

    $appId =
        trim(
            (string)(
                $settings['customer_app_id'] ??
                ''
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
            'errors' => []
        ];
    }

    $headers = [
        make_cybozu_auth_header(
            $login,
            $password
        ),
        'Accept: application/json'
    ];

    if ($appId !== '') {
        $params = [
            'id' => $appId
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
                '/k/v1/app.json?' .
                $query
            );
    } else {
        $params = [
            'limit' => 1
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
                '/k/v1/apps.json?' .
                $query
            );
    }

    return kintone_api_request(
        'GET',
        $url,
        $headers,
        [],
        $settings
    );
}

/**
 * kintone顧客一覧取得。
 */
function fetch_kintone_customers(
    array $settings
): array {
    $domain =
        trim(
            (string)(
                $settings['domain'] ??
                ''
            )
        );

    $login =
        trim(
            (string)(
                $settings['login_name'] ??
                ''
            )
        );

    $password =
        (string)(
            $settings['password'] ??
            ''
        );

    $appId =
        trim(
            (string)(
                $settings['customer_app_id'] ??
                ''
            )
        );

    if (
        $domain === '' ||
        $login === '' ||
        $password === '' ||
        $appId === ''
    ) {
        return [
            'success' => false,
            'status' => 400,
            'message' =>
                'kintone設定が不足しています。',
            'errors' => []
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
        'app' => $appId,
        'totalCount' => 'true',
        'query' => 'order by $id asc limit 500'
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
            '/k/v1/records.json?' .
            $query
        );

    $result =
        kintone_api_request(
            'GET',
            $url,
            $headers,
            [],
            $settings
        );

    if (!$result['success']) {
        return $result;
    }

    $records =
        $result['data']['records'] ??
        [];

    if (!is_array($records)) {
        $records = [];
    }

    $idField =
        trim(
            (string)(
                $settings['customer_id_field'] ??
                ''
            )
        );

    $nameField =
        trim(
            (string)(
                $settings['customer_name_field'] ??
                ''
            )
        );

    $emailField =
        trim(
            (string)(
                $settings['customer_email_field'] ??
                ''
            )
        );

    $customers = [];

    foreach (
        $records
        as $record
    ) {
        if (!is_array($record)) {
            continue;
        }

        $id = '';
        $name = '';
        $email = '';

        if (
            $idField !== '' &&
            isset(
                $record[$idField]['value']
            )
        ) {
            $id =
                (string)(
                    $record[$idField]['value']
                );
        }

        if (
            $nameField !== '' &&
            isset(
                $record[$nameField]['value']
            )
        ) {
            $name =
                (string)(
                    $record[$nameField]['value']
                );
        }

        if (
            $emailField !== '' &&
            isset(
                $record[$emailField]['value']
            )
        ) {
            $email =
                (string)(
                    $record[$emailField]['value']
                );
        }

        if ($id === '') {
            $id =
                generate_id(
                    'customer'
                );
        }

        $customers[] = [
            'id' => $id,
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
            '顧客一覧を取得しました。',
        'customers' => $customers,
        'errors' => []
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
            $question['type'] ??
            'free'
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
            if (
                !is_array($option)
            ) {
                continue;
            }

            $options[] = [
                'text' =>
                    (string)(
                        $option['text'] ??
                        ''
                    ),
                'branch' =>
                    (string)(
                        $option['branch'] ??
                        ''
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
                $question['text'] ??
                ''
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
        is_array($survey['groups'])
    ) {
        foreach (
            $survey['groups']
            as $group
        ) {
            if (
                !is_array($group)
            ) {
                continue;
            }

            $questions = [];

            if (
                isset(
                    $group['questions']
                ) &&
                is_array(
                    $group['questions']
                )
            ) {
                foreach (
                    $group['questions']
                    as $question
                ) {
                    if (
                        is_array($question)
                    ) {
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
                        generate_id('group')
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
                    $survey['name'] ??
                    ''
                )
            ),
        'description' =>
            (string)(
                $survey['description'] ??
                ''
            ),
        'status' => $status,
        'created' =>
            (string)(
                $survey['created'] ??
                date('Y-m-d')
            ),
        'start' =>
            (string)(
                $survey['start'] ??
                ''
            ),
        'end' =>
            (string)(
                $survey['end'] ??
                ''
            ),
        'answers' =>
            (int)(
                $survey['answers'] ??
                0
            ),
        'target' =>
            (int)(
                $survey['target'] ??
                0
            ),
        'sent' =>
            (int)(
                $survey['sent'] ??
                0
            ),
        'updated' =>
            (string)(
                $survey['updated'] ??
                date('Y-m-d')
            ),
        'numbering' =>
            $numbering,
        'groups' =>
            $groups
    ];
}

/**
 * アンケート検索。
 */
function find_survey(
    array $surveys,
    string $id
): ?array {
    foreach (
        $surveys
        as $survey
    ) {
        if (
            is_array($survey) &&
            (string)(
                $survey['id'] ??
                ''
            ) === $id
        ) {
            return $survey;
        }
    }

    return null;
}

/**
 * アンケート位置取得。
 */
function survey_index(
    array $surveys,
    string $id
): int {
    foreach (
        $surveys
        as $index => $survey
    ) {
        if (
            is_array($survey) &&
            (string)(
                $survey['id'] ??
                ''
            ) === $id
        ) {
            return $index;
        }
    }

    return -1;
}

/**
 * 回答数を取得。
 */
function survey_answer_count(
    string $surveyId,
    array $responses
): int {
    $count = 0;

    foreach (
        $responses
        as $response
    ) {
        if (
            is_array($response) &&
            (string)(
                $response['survey_id'] ??
                ''
            ) === $surveyId
        ) {
            $count++;
        }
    }

    return $count;
}

/**
 * アンケートの回答結果集計。
 */
function aggregate_survey(
    array $survey,
    array $responses
): array {
    $surveyId =
        (string)(
            $survey['id'] ??
            ''
        );

    $surveyResponses = [];

    foreach (
        $responses
        as $response
    ) {
        if (
            !is_array($response)
        ) {
            continue;
        }

        if (
            (string)(
                $response['survey_id'] ??
                ''
            ) !== $surveyId
        ) {
            continue;
        }

        $surveyResponses[] =
            $response;
    }

    $results = [];

    foreach (
        ($survey['groups'] ?? [])
        as $group
    ) {
        if (!is_array($group)) {
            continue;
        }

        foreach (
            ($group['questions'] ?? [])
            as $question
        ) {
            if (
                !is_array($question)
            ) {
                continue;
            }

            $questionId =
                (string)(
                    $question['id'] ??
                    ''
                );

            $type =
                (string)(
                    $question['type'] ??
                    'free'
                );

            $counts = [];

            foreach (
                ($question['options'] ?? [])
                as $option
            ) {
                if (
                    !is_array($option)
                ) {
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

            $freeAnswers = [];

            foreach (
                $surveyResponses
                as $response
            ) {
                $answerMap =
                    $response['answers'] ??
                    [];

                if (
                    !is_array($answerMap)
                ) {
                    continue;
                }

                $answer =
                    $answerMap[
                        $questionId
                    ] ??
                    null;

                if (
                    $type === 'free'
                ) {
                    if (
                        is_string($answer) &&
                        trim($answer) !== ''
                    ) {
                        $freeAnswers[] =
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
                    if (
                        !is_array($answer)
                    ) {
                        continue;
                    }

                    foreach (
                        $answer
                        as $selected
                    ) {
                        if (
                            is_string($selected) &&
                            isset(
                                $counts[$selected]
                            )
                        ) {
                            $counts[$selected]++;
                        }
                    }
                }
            }

            $results[] = [
                'question_id' =>
                    $questionId,
                'question' =>
                    (string)(
                        $question['text'] ??
                        ''
                    ),
                'type' =>
                    $type,
                'total' =>
                    count(
                        $surveyResponses
                    ),
                'counts' =>
                    $counts,
                'free_answers' =>
                    $freeAnswers
            ];
        }
    }

    return [
        'total_responses' =>
            count($surveyResponses),
        'results' =>
            $results
    ];
}

/* =========================================================
 * API
 * ========================================================= */

initialize_data_files();

$action = requested_action();

if ($action !== '') {
    if (
        request_method() !== 'GET'
    ) {
        validate_csrf();
    }

    switch ($action) {
        case 'load_settings':
            $settings =
                load_settings();

            /*
             * パスワードは画面へ返さない。
             */
            $settings['mail']['password'] =
                '';

            $settings['kintone']['password'] =
                '';

            json_response(
                true,
                '設定を取得しました。',
                [
                    'settings' =>
                        $settings
                ]
            );

        case 'load_survey_list':
            $surveyData =
                load_surveys();

            $responseData =
                load_responses();

            $surveys = [];

            foreach (
                ($surveyData['surveys'] ?? [])
                as $survey
            ) {
                if (
                    !is_array($survey)
                ) {
                    continue;
                }

                $survey =
                    normalize_survey(
                        $survey
                    );

                $survey['answers'] =
                    survey_answer_count(
                        $survey['id'],
                        $responseData['responses'] ??
                        []
                    );

                $surveys[] =
                    $survey;
            }

            json_response(
                true,
                'アンケート一覧を取得しました。',
                [
                    'surveys' =>
                        $surveys
                ]
            );

        case 'load_survey':
            $id =
                trim(
                    (string)(
                        $_GET['id'] ??
                        ''
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

            $surveyData =
                load_surveys();

            $survey =
                find_survey(
                    $surveyData['surveys'] ??
                    [],
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
                'アンケートを取得しました。',
                [
                    'survey' =>
                        normalize_survey(
                            $survey
                        )
                ]
            );

        case 'save_survey':
            $input =
                read_json_request();

            if (
                !isset(
                    $input['survey']
                ) ||
                !is_array(
                    $input['survey']
                )
            ) {
                json_response(
                    false,
                    'アンケート内容を入力してください。',
                    [],
                    [],
                    400
                );
            }

            $survey =
                normalize_survey(
                    $input['survey']
                );

            if (
                trim(
                    $survey['name']
                ) === ''
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
                count(
                    $survey['groups']
                ) === 0
            ) {
                json_response(
                    false,
                    'グループを1つ以上登録してください。',
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
                        $group['name']
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

                if (
                    count(
                        $group['questions']
                    ) === 0
                ) {
                    json_response(
                        false,
                        '各グループには質問を1問以上登録してください。',
                        [],
                        [],
                        400
                    );
                }

                foreach (
                    $group['questions']
                    as $question
                ) {
                    if (
                        trim(
                            $question['text']
                        ) === ''
                    ) {
                        json_response(
                            false,
                            '質問文を入力してください。',
                            [],
                            [],
                            400
                        );
                    }

                    if (
                        in_array(
                            $question['type'],
                            [
                                'single',
                                'multiple'
                            ],
                            true
                        )
                    ) {
                        $validOptions = 0;

                        foreach (
                            $question['options']
                            as $option
                        ) {
                            if (
                                trim(
                                    $option['text']
                                ) !== ''
                            ) {
                                $validOptions++;
                            }
                        }

                        if (
                            $validOptions < 2
                        ) {
                            json_response(
                                false,
                                '選択式の質問には選択肢を2つ以上登録してください。',
                                [],
                                [],
                                400
                            );
                        }
                    }
                }
            }

            $surveyData =
                load_surveys();

            if (
                !isset(
                    $surveyData['surveys']
                ) ||
                !is_array(
                    $surveyData['surveys']
                )
            ) {
                $surveyData['surveys'] =
                    [];
            }

            $existingIndex =
                survey_index(
                    $surveyData['surveys'],
                    $survey['id']
                );

            $oldSurvey =
                $existingIndex >= 0
                    ? $surveyData['surveys'][$existingIndex]
                    : null;

            $survey['created'] =
                is_array($oldSurvey)
                    ? (string)(
                        $oldSurvey['created'] ??
                        date('Y-m-d')
                    )
                    : date('Y-m-d');

            $survey['updated'] =
                date('Y-m-d');

            if (
                $existingIndex >= 0
            ) {
                $surveyData['surveys'][
                    $existingIndex
                ] = $survey;
            } else {
                $surveyData['surveys'][] =
                    $survey;
            }

            $surveyData['updated_at'] =
                now();

            write_json_file(
                SURVEYS_FILE,
                $surveyData
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
                        $input['id'] ??
                        ''
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

            $surveyData =
                load_surveys();

            $index =
                survey_index(
                    $surveyData['surveys'] ??
                    [],
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
                $surveyData['surveys'][$index];

            if (
                !is_array($survey) ||
                ($survey['status'] ?? 'draft') !==
                'draft'
            ) {
                json_response(
                    false,
                    '下書きのアンケートだけ削除できます。',
                    [],
                    [],
                    400
                );
            }

            array_splice(
                $surveyData['surveys'],
                $index,
                1
            );

            $surveyData['updated_at'] =
                now();

            write_json_file(
                SURVEYS_FILE,
                $surveyData
            );

            json_response(
                true,
                'アンケートを削除しました。'
            );

        case 'change_survey_status':
            $input =
                read_json_request();

            $id =
                trim(
                    (string)(
                        $input['id'] ??
                        ''
                    )
                );

            $status =
                trim(
                    (string)(
                        $input['status'] ??
                        ''
                    )
                );

            if (
                $id === '' ||
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
                json_response(
                    false,
                    '指定内容を確認してください。',
                    [],
                    [],
                    400
                );
            }

            $surveyData =
                load_surveys();

            $index =
                survey_index(
                    $surveyData['surveys'] ??
                    [],
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
                normalize_survey(
                    $surveyData['surveys'][$index]
                );

            $survey['status'] =
                $status;

            $survey['updated'] =
                date('Y-m-d');

            $surveyData['surveys'][$index] =
                $survey;

            $surveyData['updated_at'] =
                now();

            write_json_file(
                SURVEYS_FILE,
                $surveyData
            );

            json_response(
                true,
                $status === 'open'
                    ? 'アンケートを公開しました。'
                    : 'アンケートを終了しました。',
                [
                    'survey' =>
                        $survey
                ]
            );

        case 'load_customers':
            $customers =
                load_customers();

            json_response(
                true,
                '顧客一覧を取得しました。',
                [
                    'customers' =>
                        $customers['customers'] ??
                        []
                ]
            );

        case 'refresh_customers':
            $settings =
                load_settings();

            $result =
                fetch_kintone_customers(
                    $settings['kintone'] ??
                    []
                );

            if (
                !$result['success']
            ) {
                json_response(
                    false,
                    (string)(
                        $result['message'] ??
                        '顧客一覧を取得できませんでした。'
                    ),
                    [],
                    is_array(
                        $result['errors'] ??
                        null
                    )
                        ? $result['errors']
                        : [],
                    400
                );
            }

            $customers =
                $result['customers'] ??
                [];

            if (!is_array($customers)) {
                $customers = [];
            }

            $customerData = [
                'version' => 1,
                'updated_at' => now(),
                'source' => 'kintone',
                'count' =>
                    count($customers),
                'customers' =>
                    $customers
            ];

            write_json_file(
                CUSTOMERS_FILE,
                $customerData
            );

            json_response(
                true,
                '顧客一覧を更新しました。',
                [
                    'customers' =>
                        $customers
                ]
            );

        case 'save_mail_settings':
            $input =
                read_json_request();

            $settings =
                load_settings();

            $mail = [
                'smtp_server' =>
                    trim(
                        (string)(
                            $input['smtp_server'] ??
                            ''
                        )
                    ),
                'smtp_port' =>
                    (int)(
                        $input['smtp_port'] ??
                        587
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
                            $input['username'] ??
                            ''
                        )
                    ),
                'password' =>
                    (string)(
                        $input['password'] ??
                        ''
                    ),
                'from_email' =>
                    trim(
                        (string)(
                            $input['from_email'] ??
                            ''
                        )
                    ),
                'from_name' =>
                    trim(
                        (string)(
                            $input['from_name'] ??
                            ''
                        )
                    ),
                'configured' => false,
                'tested_at' =>
                    $settings['mail']['tested_at'] ??
                    null
            ];

            if (
                $mail['password'] === '' &&
                isset(
                    $settings['mail']['password']
                )
            ) {
                $mail['password'] =
                    (string)(
                        $settings['mail']['password']
                    );
            }

            if (
                $mail['smtp_server'] === ''
            ) {
                json_response(
                    false,
                    'SMTPサーバを入力してください。',
                    [],
                    [],
                    400
                );
            }

            if (
                $mail['from_email'] === '' ||
                !filter_var(
                    $mail['from_email'],
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                json_response(
                    false,
                    '送信元メールアドレスを確認してください。',
                    [],
                    [],
                    400
                );
            }

            if (
                $mail['password'] === ''
            ) {
                json_response(
                    false,
                    'SMTP認証パスワードを入力してください。',
                    [],
                    [],
                    400
                );
            }

            $mail['configured'] = true;

            $settings['mail'] =
                $mail;

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

        case 'save_kintone_settings':
            $input =
                read_json_request();

            $settings =
                load_settings();

            $password =
                (string)(
                    $input['password'] ??
                    ''
                );

            if (
                $password === ''
            ) {
                $password =
                    (string)(
                        $settings['kintone']['password'] ??
                        ''
                    );
            }

            $kintone = [
                'domain' =>
                    trim(
                        (string)(
                            $input['domain'] ??
                            ''
                        )
                    ),
                'login_name' =>
                    trim(
                        (string)(
                            $input['login_name'] ??
                            ''
                        )
                    ),
                'password' =>
                    $password,
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
                            $input['proxy_host'] ??
                            ''
                        )
                    ),
                'proxy_port' =>
                    trim(
                        (string)(
                            $input['proxy_port'] ??
                            ''
                        )
                    ),
                'proxy_host_port' =>
                    trim(
                        (string)(
                            $input['proxy_host_port'] ??
                            ''
                        )
                    ),
                'configured' => false,
                'connection_status' =>
                    $settings['kintone']['connection_status'] ??
                    'not_configured',
                'tested_at' =>
                    $settings['kintone']['tested_at'] ??
                    null
            ];

            if (
                $kintone['domain'] === '' ||
                $kintone['login_name'] === '' ||
                $kintone['password'] === ''
            ) {
                json_response(
                    false,
                    'kintoneの接続情報を入力してください。',
                    [],
                    [],
                    400
                );
            }

            $kintone['configured'] =
                true;

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

        case 'test_kintone_connection':
            $input =
                read_json_request();

            if (
                $input === []
            ) {
                $settings =
                    load_settings();

                $input =
                    $settings['kintone'] ??
                    [];
            } else {
                $settings =
                    load_settings();

                if (
                    !isset(
                        $input['password']
                    ) ||
                    !is_string(
                        $input['password']
                    ) ||
                    $input['password'] === ''
                ) {
                    $input['password'] =
                        (string)(
                            $settings['kintone']['password'] ??
                            ''
                        );
                }
            }

            $result =
                test_kintone(
                    $input
                );

            if (
                !$result['success']
            ) {
                $settings['kintone']['connection_status'] =
                    'error';

                $settings['kintone']['tested_at'] =
                    now();

                write_json_file(
                    SETTINGS_FILE,
                    $settings
                );

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
                    is_array(
                        $result['errors'] ??
                        null
                    )
                        ? $result['errors']
                        : [],
                    400
                );
            }

            $settings['kintone']['connection_status'] =
                'connected';

            $settings['kintone']['tested_at'] =
                now();

            write_json_file(
                SETTINGS_FILE,
                $settings
            );

            json_response(
                true,
                'kintoneへの接続を確認しました。',
                [
                    'status' =>
                        (int)(
                            $result['status'] ??
                            200
                        )
                ]
            );

        case 'aggregate_responses':
            $surveyId =
                trim(
                    (string)(
                        $_GET['survey_id'] ??
                        ''
                    )
                );

            if (
                $surveyId === ''
            ) {
                json_response(
                    false,
                    'アンケートを指定してください。',
                    [],
                    [],
                    400
                );
            }

            $surveyData =
                load_surveys();

            $survey =
                find_survey(
                    $surveyData['surveys'] ??
                    [],
                    $surveyId
                );

            if (
                $survey === null
            ) {
                json_response(
                    false,
                    'アンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            $responseData =
                load_responses();

            $aggregate =
                aggregate_survey(
                    normalize_survey(
                        $survey
                    ),
                    $responseData['responses'] ??
                    []
                );

            json_response(
                true,
                '回答結果を集計しました。',
                $aggregate
            );

        case 'submit_response':
            $input =
                read_json_request();

            $surveyId =
                trim(
                    (string)(
                        $input['survey_id'] ??
                        ''
                    )
                );

            $answers =
                $input['answers'] ??
                [];

            $respondentName =
                trim(
                    (string)(
                        $input['respondent_name'] ??
                        ''
                    )
                );

            $respondentEmail =
                trim(
                    (string)(
                        $input['respondent_email'] ??
                        ''
                    )
                );

            $submissionKey =
                trim(
                    (string)(
                        $input['submission_key'] ??
                        ''
                    )
                );

            if (
                $surveyId === '' ||
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

            $surveyData =
                load_surveys();

            $survey =
                find_survey(
                    $surveyData['surveys'] ??
                    [],
                    $surveyId
                );

            if (
                $survey === null
            ) {
                json_response(
                    false,
                    'アンケートが見つかりません。',
                    [],
                    [],
                    404
                );
            }

            if (
                ($survey['status'] ?? '') !==
                'open'
            ) {
                json_response(
                    false,
                    'このアンケートは現在回答を受け付けていません。',
                    [],
                    [],
                    403
                );
            }

            $responses =
                load_responses();

            if (
                !isset(
                    $responses['responses']
                ) ||
                !is_array(
                    $responses['responses']
                )
            ) {
                $responses['responses'] =
                    [];
            }

            if (
                $submissionKey !== ''
            ) {
                foreach (
                    $responses['responses']
                    as $existing
                ) {
                    if (
                        is_array($existing) &&
                        (string)(
                            $existing['submission_key'] ??
                            ''
                        ) ===
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

            /*
             * 必須項目をサーバー側でも確認。
             */
            foreach (
                ($survey['groups'] ?? [])
                as $group
            ) {
                if (
                    !is_array($group)
                ) {
                    continue;
                }

                foreach (
                    ($group['questions'] ?? [])
                    as $question
                ) {
                    if (
                        !is_array($question)
                    ) {
                        continue;
                    }

                    if (
                        empty(
                            $question['required']
                        )
                    ) {
                        continue;
                    }

                    $questionId =
                        (string)(
                            $question['id'] ??
                            ''
                        );

                    $answer =
                        $answers[$questionId] ??
                        null;

                    $empty =
                        $answer === null ||
                        $answer === '' ||
                        (
                            is_array($answer) &&
                            count($answer) === 0
                        );

                    if ($empty) {
                        json_response(
                            false,
                            '必須項目に未回答があります。',
                            [],
                            [
                                $questionId =>
                                    '必須項目です。'
                            ],
                            400
                        );
                    }
                }
            }

            $responses['responses'][] = [
                'id' =>
                    generate_id(
                        'response'
                    ),
                'survey_id' =>
                    $surveyId,
                'respondent_name' =>
                    $respondentName,
                'respondent_email' =>
                    $respondentEmail,
                'answers' =>
                    $answers,
                'submission_key' =>
                    $submissionKey,
                'submitted_at' =>
                    now()
            ];

            $responses['updated_at'] =
                now();

            write_json_file(
                RESPONSES_FILE,
                $responses
            );

            json_response(
                true,
                '回答を送信しました。'
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

$apiPath =
    application_api_path();

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta
    name="csrf-token"
    content="<?= h(csrf_token()) ?>"
>
<meta
    name="api-path"
    content="<?= h($apiPath) ?>"
>
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
    background:#f4f6f8;
    color:#263238;
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
    gap:28px;
}

.logo{
    font-size:18px;
    font-weight:bold;
    white-space:nowrap;
}

.main-nav{
    display:flex;
    height:60px;
    align-items:stretch;
    gap:2px;
}

.main-nav button{
    height:60px;
    padding:0 16px;
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

.btn-small{
    min-height:32px;
    padding:5px 10px;
    font-size:12px;
}

.loading-spinner{
    display:none;
    width:14px;
    height:14px;
    margin-right:6px;
    border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite;
    vertical-align:-2px;
}

.btn.loading .loading-spinner{
    display:inline-block;
}

@keyframes spin{
    to{
        transform:rotate(360deg);
    }
}

.notice{
    background:#eef6ff;
    border:1px solid #c9e1f8;
    color:#315a7d;
    border-radius:6px;
    padding:12px 14px;
    margin-bottom:18px;
    white-space:pre-line;
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

.badge-end{
    color:#7b3f3f;
    background:#f6dddd;
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

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
}

.field{
    margin-bottom:15px;
}

.field label{
    display:block;
    font-weight:bold;
    margin-bottom:6px;
    color:#465765;
}

.field input,
.field textarea,
.field select{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:9px;
    background:#fff;
}

.field textarea{
    min-height:100px;
    resize:vertical;
}

.radio-row{
    display:flex;
    gap:20px;
    flex-wrap:wrap;
}

.radio-row label{
    font-weight:normal;
}

.group-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    margin-bottom:18px;
    overflow:hidden;
}

.group-card.dragging{
    opacity:.45;
}

.group-card.drag-over{
    border-top:3px solid #2878c8;
}

.group-header{
    display:flex;
    align-items:center;
    gap:10px;
    padding:12px 15px;
    background:#f8fafc;
    border-bottom:1px solid #e6ebef;
}

.drag-handle{
    cursor:grab;
    color:#718096;
    font-size:18px;
}

.group-title{
    flex:1;
}

.group-title input{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:8px;
    font-weight:bold;
}

.group-actions{
    display:flex;
    gap:6px;
}

.question-card{
    padding:15px;
    border-bottom:1px solid #e6ebef;
}

.question-card:last-child{
    border-bottom:0;
}

.question-card.dragging{
    opacity:.45;
}

.question-card.drag-over{
    border-top:3px solid #2878c8;
}

.question-head{
    display:flex;
    align-items:center;
    gap:8px;
}

.question-number{
    width:65px;
    color:#2878c8;
    font-weight:bold;
    flex:none;
}

.question-title{
    flex:1;
}

.question-title input{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:8px;
}

.question-tools{
    display:flex;
    gap:6px;
    align-items:center;
}

.question-tools select{
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:6px;
}

.question-meta{
    display:flex;
    gap:18px;
    align-items:center;
    margin-top:10px;
    padding-left:73px;
    color:#657786;
    font-size:13px;
}

.question-options{
    margin-top:12px;
    padding-left:73px;
}

.option-row{
    display:flex;
    align-items:center;
    gap:7px;
    margin-bottom:7px;
}

.option-row input{
    flex:1;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:7px;
}

.option-row select{
    width:250px;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:7px;
}

.add-question-area{
    padding:12px 14px;
    background:#fafcfd;
    border-top:1px solid #edf1f4;
}

.add-group-area{
    text-align:center;
    margin:8px 0 20px;
}

.editor-toolbar{
    display:flex;
    justify-content:space-between;
    gap:10px;
    margin-top:20px;
}

.editor-actions{
    display:flex;
    gap:8px;
}

.detail-tabs{
    display:flex;
    gap:5px;
    margin-bottom:18px;
    flex-wrap:wrap;
}

.detail-tabs button{
    border:1px solid #d6dee6;
    background:#fff;
    padding:9px 16px;
    border-radius:5px;
}

.detail-tabs button.active{
    background:#2878c8;
    color:#fff;
    border-color:#2878c8;
}

.detail-summary{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    margin-bottom:18px;
}

.stat-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:17px;
}

.stat-label{
    color:#718096;
    font-size:12px;
}

.stat-value{
    font-size:27px;
    font-weight:bold;
    margin-top:5px;
}

.send-layout{
    display:grid;
    grid-template-columns:1.1fr .9fr;
    gap:18px;
}

.customer-toolbar{
    display:flex;
    gap:8px;
    margin-bottom:12px;
}

.customer-toolbar input,
.customer-toolbar select{
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:8px;
}

.customer-toolbar input{
    flex:1;
}

.selection-summary{
    padding:10px 12px;
    background:#edf6ff;
    color:#2b5f8a;
    border-radius:5px;
    margin-bottom:12px;
    font-size:13px;
}

.recipient-chip{
    display:inline-block;
    padding:5px 8px;
    margin:3px;
    border-radius:4px;
    background:#edf2f7;
    font-size:12px;
}

.email-preview{
    border:1px solid #dfe5eb;
    border-radius:6px;
    background:#fafbfc;
    padding:15px;
    white-space:pre-wrap;
    min-height:150px;
}

.progress{
    height:10px;
    background:#e8edf2;
    border-radius:5px;
    overflow:hidden;
    margin-top:8px;
}

.progress span{
    display:block;
    height:100%;
    background:#4285c5;
}

.result-layout{
    display:grid;
    grid-template-columns:270px 1fr;
    gap:18px;
}

.result-question-button{
    display:block;
    width:100%;
    border:0;
    background:#fff;
    text-align:left;
    padding:10px;
    border-radius:4px;
    margin-bottom:4px;
}

.result-question-button:hover,
.result-question-button.active{
    background:#eef6ff;
    color:#2878c8;
}

.result-bar-row{
    margin-bottom:14px;
}

.result-bar-label{
    display:flex;
    justify-content:space-between;
    gap:10px;
    margin-bottom:4px;
}

.result-bar{
    height:18px;
    border-radius:3px;
    overflow:hidden;
    background:#edf1f4;
}

.result-bar-inner{
    height:100%;
    background:#2878c8;
}

.result-answer{
    padding:8px 10px;
    background:#f7f9fb;
    border:1px solid #e6ebef;
    border-radius:4px;
    margin:5px 0;
    font-size:13px;
}

.settings-tabs{
    display:flex;
    gap:5px;
    margin-bottom:18px;
}

.settings-tabs button{
    border:1px solid #d6dee6;
    background:#fff;
    padding:9px 16px;
    border-radius:5px;
}

.settings-tabs button.active{
    background:#2878c8;
    color:#fff;
    border-color:#2878c8;
}

.status-line{
    display:flex;
    align-items:center;
    gap:8px;
    padding:10px 12px;
    background:#f7f9fb;
    border-radius:5px;
    margin-bottom:15px;
}

.status-dot{
    width:9px;
    height:9px;
    border-radius:50%;
    background:#9aa7b3;
}

.status-dot.ok{
    background:#2f9e61;
}

.status-dot.warn{
    background:#d39b25;
}

.status-dot.error{
    background:#d9534f;
}

.modal-backdrop{
    position:fixed;
    inset:0;
    background:rgba(20,35,50,.45);
    display:flex;
    align-items:center;
    justify-content:center;
    z-index:1000;
}

.modal{
    width:min(
        760px,
        calc(100% - 30px)
    );
    max-height:90vh;
    overflow:auto;
    background:#fff;
    border-radius:8px;
    box-shadow:
        0 15px 50px rgba(0,0,0,.25);
}

.modal-header{
    padding:16px 20px;
    border-bottom:1px solid #e3e8ed;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
}

.modal-header h2{
    margin:0;
    font-size:18px;
}

.modal-body{
    padding:20px;
}

.modal-footer{
    padding:13px 20px;
    border-top:1px solid #e3e8ed;
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

.toast{
    position:fixed;
    right:25px;
    bottom:25px;
    background:#263238;
    color:#fff;
    padding:12px 18px;
    border-radius:5px;
    box-shadow:
        0 5px 20px rgba(0,0,0,.2);
    opacity:0;
    transform:translateY(10px);
    transition:.2s;
    pointer-events:none;
    z-index:2000;
    max-width:min(
        500px,
        calc(100vw - 40px)
    );
}

.toast.show{
    opacity:1;
    transform:translateY(0);
}

.public-page{
    max-width:820px;
    margin:0 auto;
    padding:30px 20px 60px;
}

.public-question{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:20px;
    margin-bottom:15px;
}

.public-question-title{
    font-weight:bold;
    margin-bottom:12px;
}

.public-option{
    margin:8px 0;
}

.public-complete{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:40px;
    text-align:center;
}

.answer-header{
    margin-bottom:20px;
}

.answer-header h1{
    margin:0 0 8px;
    font-size:25px;
}

.answer-description{
    color:#64748b;
    white-space:pre-line;
}

.answer-actions{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    margin-top:20px;
}

.customer-checkbox{
    width:18px;
    height:18px;
}

.settings-password-note{
    font-size:12px;
    color:#718096;
    margin-top:5px;
}

.branch-warning{
    color:#b42318;
    background:#fff4f2;
    border:1px solid #f2c7c2;
    border-radius:4px;
    padding:7px 9px;
    margin-top:7px;
    font-size:12px;
}

.success-text{
    color:#276749;
}

.warning-text{
    color:#9a6700;
}

.error-text{
    color:#b42318;
}

@media(max-width:980px){
    .send-layout,
    .result-layout{
        grid-template-columns:1fr;
    }

    .detail-summary{
        grid-template-columns:1fr 1fr;
    }
}

@media(max-width:800px){
    .topbar{
        padding:0 10px;
        gap:8px;
        overflow-x:auto;
    }

    .logo{
        font-size:15px;
    }

    .main-nav button{
        padding:0 8px;
        font-size:12px;
    }

    .app{
        padding:14px;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .question-head{
        align-items:flex-start;
        flex-wrap:wrap;
    }

    .question-title{
        min-width:70%;
    }

    .question-meta,
    .question-options{
        padding-left:0;
    }

    .option-row{
        flex-wrap:wrap;
    }

    .option-row select{
        width:100%;
    }

    .table{
        min-width:800px;
    }

    .card{
        overflow-x:auto;
    }

    .detail-summary{
        grid-template-columns:1fr;
    }

    .group-header{
        flex-wrap:wrap;
    }

    .group-actions{
        width:100%;
        justify-content:flex-end;
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
            <div class="subtext">作成済みのアンケートを管理します</div>
        </div>

        <button
            id="btn-open-create"
            class="btn btn-primary"
            type="button"
        >
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
                <label for="survey-name">
                    アンケート名 *
                </label>

                <input
                    id="survey-name"
                    type="text"
                    maxlength="200"
                    placeholder="例：新商品アンケート"
                >
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
                maxlength="2000"
                placeholder="回答者への説明を入力してください"
            ></textarea>
        </div>


        <div class="form-grid">

            <div class="field">
                <label for="survey-start">
                    公開開始日
                </label>

                <input
                    id="survey-start"
                    type="date"
                >
            </div>

            <div class="field">
                <label for="survey-end">
                    公開終了日
                </label>

                <input
                    id="survey-end"
                    type="date"
                >
            </div>

        </div>


        <div class="field">

            <label>
                質問番号
            </label>

            <div class="radio-row">

                <label>
                    <input
                        type="radio"
                        name="numbering"
                        value="global"
                    >
                    全体で通番（Q1、Q2、Q3…）
                </label>

                <label>
                    <input
                        type="radio"
                        name="numbering"
                        value="group"
                    >
                    グループごと（Q1-1、Q1-2、Q2-1…）
                </label>

            </div>

        </div>

    </div>


    <div id="groups"></div>


    <div class="add-group-area">

        <button
            id="btn-add-group"
            class="btn btn-primary"
            type="button"
        >
            ＋ グループ追加
        </button>

    </div>


    <div class="editor-toolbar">

        <div class="editor-actions">

            <button
                id="btn-editor-back"
                class="btn"
                type="button"
            >
                一覧へ戻る
            </button>

        </div>

        <div class="editor-actions">

            <button
                id="btn-save-survey"
                class="btn btn-primary"
                type="button"
            >
                <span class="loading-spinner"></span>
                保存する
            </button>

        </div>

    </div>

</section>


<section id="page-detail" class="hidden">

    <div class="page-header">

        <div>
            <h1 id="detail-title">
                アンケート詳細
            </h1>

            <div
                id="detail-subtitle"
                class="subtext"
            ></div>
        </div>

        <div class="editor-actions">

            <button
                id="btn-detail-back"
                class="btn"
                type="button"
            >
                一覧へ戻る
            </button>

            <button
                id="btn-detail-edit"
                class="btn btn-primary"
                type="button"
            >
                編集
            </button>

        </div>

    </div>


    <div id="detail-notice"></div>


    <div class="detail-summary">

        <div class="summary-item">
            <div class="summary-label">
                状態
            </div>

            <div
                id="detail-status"
                class="summary-value"
            ></div>
        </div>


        <div class="summary-item">
            <div class="summary-label">
                回答数
            </div>

            <div
                id="detail-answers"
                class="summary-value"
            >
                0
            </div>
        </div>


        <div class="summary-item">
            <div class="summary-label">
                対象者数
            </div>

            <div
                id="detail-target"
                class="summary-value"
            >
                0
            </div>
        </div>


        <div class="summary-item">
            <div class="summary-label">
                送信数
            </div>

            <div
                id="detail-sent"
                class="summary-value"
            >
                0
            </div>
        </div>

    </div>


    <div class="card">

        <div class="card-title">
            アンケート情報
        </div>

        <div class="detail-grid">

            <div>
                <div class="detail-label">
                    説明
                </div>

                <div
                    id="detail-description"
                    class="detail-value"
                ></div>
            </div>


            <div>
                <div class="detail-label">
                    公開期間
                </div>

                <div
                    id="detail-period"
                    class="detail-value"
                ></div>
            </div>


            <div>
                <div class="detail-label">
                    作成日
                </div>

                <div
                    id="detail-created"
                    class="detail-value"
                ></div>
            </div>


            <div>
                <div class="detail-label">
                    最終更新日
                </div>

                <div
                    id="detail-updated"
                    class="detail-value"
                ></div>
            </div>

        </div>

    </div>


    <div class="card">

        <div class="card-title">
            質問内容
        </div>

        <div id="detail-questions"></div>

    </div>


    <div class="card">

        <div class="card-title">
            回答結果
        </div>

        <div class="editor-actions">

            <button
                id="btn-show-results"
                class="btn btn-primary"
                type="button"
            >
                <span class="loading-spinner"></span>
                回答結果を表示
            </button>

        </div>

        <div
            id="detail-results"
            class="hidden"
        ></div>

    </div>


    <div class="card">

        <div class="card-title">
            回答依頼
        </div>

        <div class="send-layout">

            <div>

                <div class="field">

                    <label for="send-subject">
                        メール件名
                    </label>

                    <input
                        id="send-subject"
                        type="text"
                        maxlength="200"
                        placeholder="アンケートご協力のお願い"
                    >

                </div>


                <div class="field">

                    <label for="send-body">
                        メール本文
                    </label>

                    <textarea
                        id="send-body"
                        maxlength="5000"
                        placeholder="アンケートへのご協力をお願いいたします。"
                    ></textarea>

                </div>

            </div>


            <div>

                <div class="field">

                    <label>
                        送信対象
                    </label>

                    <div
                        id="send-target-count"
                        class="target-count"
                    >
                        対象者：0名
                    </div>

                </div>


                <button
                    id="btn-send-survey"
                    class="btn btn-primary"
                    type="button"
                >
                    <span class="loading-spinner"></span>
                    回答依頼を送信
                </button>

            </div>

        </div>

    </div>

</section>


<section id="page-customers" class="hidden">

    <div class="page-header">

        <div>

            <h1>
                顧客一覧
            </h1>

            <div class="subtext">
                kintoneから取得した顧客情報を確認します
            </div>

        </div>


        <button
            id="btn-refresh-customers"
            class="btn btn-primary"
            type="button"
        >
            <span class="loading-spinner"></span>
            顧客情報を更新
        </button>

    </div>


    <div id="customers-notice"></div>


    <div class="card">

        <div class="table-wrap">

            <table class="table">

                <thead>

                <tr>
                    <th>顧客ID</th>
                    <th>会社名</th>
                    <th>顧客名</th>
                    <th>メールアドレス</th>
                </tr>

                </thead>

                <tbody id="customer-list-body"></tbody>

            </table>

        </div>

    </div>

</section>


<section id="page-settings" class="hidden">

    <div class="page-header">

        <div>

            <h1>
                設定
            </h1>

            <div class="subtext">
                アンケート運営に必要な接続情報を設定します
            </div>

        </div>

    </div>


    <div id="settings-notice"></div>


    <div class="settings-tabs">

        <button
            id="settings-tab-kintone"
            type="button"
            class="active"
        >
            kintone設定
        </button>

        <button
            id="settings-tab-mail"
            type="button"
        >
            メール設定
        </button>

    </div>


    <div
        id="settings-panel-kintone"
    >

        <div class="card">

            <div class="card-title">
                kintone接続設定
            </div>


            <div
                id="kintone-status-line"
                class="status-line"
            >

                <span
                    id="kintone-status-dot"
                    class="status-dot"
                ></span>

                <span
                    id="kintone-status-text"
                >
                    未確認
                </span>

            </div>


            <div class="form-grid">

                <div class="field">

                    <label for="kintone-domain">
                        kintoneドメイン *
                    </label>

                    <input
                        id="kintone-domain"
                        type="text"
                        maxlength="200"
                        placeholder="例：example.cybozu.com"
                    >

                    <div class="field-help">
                        https:// や .cybozu.com を含めても設定できます。
                    </div>

                </div>


                <div class="field">

                    <label for="kintone-app-id">
                        顧客アプリID
                    </label>

                    <input
                        id="kintone-app-id"
                        type="text"
                        maxlength="50"
                        inputmode="numeric"
                        placeholder="例：123"
                    >

                </div>

            </div>


            <div class="form-grid">

                <div class="field">

                    <label for="kintone-login-name">
                        ログイン名 *
                    </label>

                    <input
                        id="kintone-login-name"
                        type="text"
                        maxlength="200"
                        autocomplete="off"
                    >

                </div>


                <div class="field">

                    <label for="kintone-password">
                        パスワード *
                    </label>

                    <input
                        id="kintone-password"
                        type="password"
                        maxlength="500"
                        autocomplete="new-password"
                    >

                </div>

            </div>


            <div class="form-grid">

                <div class="field">

                    <label for="kintone-proxy-host">
                        プロキシホスト
                    </label>

                    <input
                        id="kintone-proxy-host"
                        type="text"
                        maxlength="255"
                        placeholder="例：proxy.example.local"
                    >

                </div>


                <div class="field">

                    <label for="kintone-proxy-port">
                        プロキシポート
                    </label>

                    <input
                        id="kintone-proxy-port"
                        type="text"
                        maxlength="10"
                        inputmode="numeric"
                        placeholder="例：8080"
                    >

                </div>

            </div>


            <div class="field">

                <label>
                    顧客アプリの項目設定
                </label>

            </div>


            <div class="form-grid">

                <div class="field">

                    <label for="kintone-customer-id-field">
                        顧客ID項目名
                    </label>

                    <input
                        id="kintone-customer-id-field"
                        type="text"
                        maxlength="100"
                        placeholder="例：customer_id"
                    >

                </div>


                <div class="field">

                    <label for="kintone-customer-name-field">
                        顧客名項目名
                    </label>

                    <input
                        id="kintone-customer-name-field"
                        type="text"
                        maxlength="100"
                        placeholder="例：customer_name"
                    >

                </div>

            </div>


            <div class="form-grid">

                <div class="field">

                    <label for="kintone-customer-email-field">
                        メールアドレス項目名
                    </label>

                    <input
                        id="kintone-customer-email-field"
                        type="text"
                        maxlength="100"
                        placeholder="例：email"
                    >

                </div>

            </div>


            <div class="editor-actions">

                <button
                    id="btn-test-kintone"
                    class="btn"
                    type="button"
                >
                    <span class="loading-spinner"></span>
                    接続テスト
                </button>


                <button
                    id="btn-save-kintone"
                    class="btn btn-primary"
                    type="button"
                >
                    <span class="loading-spinner"></span>
                    kintone設定を保存
                </button>

            </div>

        </div>

    </div>


    <div
        id="settings-panel-mail"
        class="hidden"
    >

        <div class="card">

            <div class="card-title">
                メール設定
            </div>


            <div class="form-grid">

                <div class="field">

                    <label for="mail-smtp-server">
                        SMTPサーバー
                    </label>

                    <input
                        id="mail-smtp-server"
                        type="text"
                        maxlength="255"
                    >

                </div>


                <div class="field">

                    <label for="mail-smtp-port">
                        SMTPポート
                    </label>

                    <input
                        id="mail-smtp-port"
                        type="text"
                        maxlength="10"
                        inputmode="numeric"
                    >

                </div>

            </div>


            <div class="form-grid">

                <div class="field">

                    <label for="mail-connection-type">
                        接続方式
                    </label>

                    <select id="mail-connection-type">

                        <option value="tls">
                            TLS
                        </option>

                        <option value="ssl">
                            SSL
                        </option>

                        <option value="none">
                            なし
                        </option>

                    </select>

                </div>


                <div class="field">

                    <label for="mail-username">
                        SMTPユーザー名
                    </label>

                    <input
                        id="mail-username"
                        type="text"
                        maxlength="255"
                        autocomplete="off"
                    >

                </div>

            </div>


            <div class="form-grid">

                <div class="field">

                    <label for="mail-password">
                        SMTPパスワード
                    </label>

                    <input
                        id="mail-password"
                        type="password"
                        maxlength="500"
                        autocomplete="new-password"
                    >

                </div>


                <div class="field">

                    <label for="mail-from-email">
                        送信元メールアドレス
                    </label>

                    <input
                        id="mail-from-email"
                        type="email"
                        maxlength="255"
                    >

                </div>

            </div>


            <div class="field">

                <label for="mail-from-name">
                    送信元名称
                </label>

                <input
                    id="mail-from-name"
                    type="text"
                    maxlength="100"
                >

            </div>


            <div class="editor-actions">

                <button
                    id="btn-test-mail"
                    class="btn"
                    type="button"
                >
                    <span class="loading-spinner"></span>
                    メール設定を確認
                </button>


                <button
                    id="btn-save-mail"
                    class="btn btn-primary"
                    type="button"
                >
                    <span class="loading-spinner"></span>
                    メール設定を保存
                </button>

            </div>

        </div>

    </div>

</section>

</main>


<div
    id="toast"
    class="toast"
    role="status"
    aria-live="polite"
></div>


<div
    id="modal"
    class="modal-backdrop hidden"
    role="dialog"
    aria-modal="true"
    aria-labelledby="modal-title"
>

    <div class="modal">

        <div class="modal-header">

            <div
                id="modal-title"
                class="card-title"
            >
                確認
            </div>

            <button
                id="modal-close"
                class="btn btn-small"
                type="button"
                aria-label="閉じる"
            >
                ×
            </button>

        </div>


        <div
            id="modal-body"
            class="modal-body"
        ></div>


        <div class="modal-footer">

            <button
                id="modal-cancel"
                class="btn"
                type="button"
            >
                キャンセル
            </button>

            <button
                id="modal-ok"
                class="btn btn-primary"
                type="button"
            >
                実行
            </button>

        </div>

    </div>

</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    /*
     * 画面状態
     */
    let currentPage = 'list';
    let surveys = [];
    let customers = [];
    let editingSurvey = null;
    let selectedSurveyId = '';
    let currentSettings = null;

    let draggedGroup = '';
    let draggedQuestion = '';

    let modalResolve = null;


    /*
     * DOM取得
     */
    const apiPathElement =
        document.querySelector('meta[name="api-path"]');

    const csrfElement =
        document.querySelector('meta[name="csrf-token"]');

    const apiPath =
        apiPathElement
            ? String(apiPathElement.getAttribute('content') || '')
            : '';

    const csrfToken =
        csrfElement
            ? String(csrfElement.getAttribute('content') || '')
            : '';


    /*
     * 要素取得ヘルパー
     */
    function getElement(id) {
        return document.getElementById(id);
    }


    /*
     * 通知表示
     */
    function showNotice(targetId, message, type) {
        const target = getElement(targetId);

        if (!target) {
            return;
        }

        while (target.firstChild) {
            target.removeChild(target.firstChild);
        }

        const box = document.createElement('div');

        box.className =
            'notice ' +
            (type === 'error'
                ? 'notice-error'
                : type === 'success'
                    ? 'notice-success'
                    : '');

        box.textContent = String(message || '');

        target.appendChild(box);
    }


    /*
     * トースト
     */
    let toastTimer = null;

    function showToast(message) {
        const toast = getElement('toast');

        if (!toast) {
            return;
        }

        toast.textContent = String(message || '');
        toast.classList.add('show');

        if (toastTimer !== null) {
            window.clearTimeout(toastTimer);
        }

        toastTimer = window.setTimeout(
            function () {
                toast.classList.remove('show');
            },
            3000
        );
    }


    /*
     * API通信
     */
    async function apiRequest(
        action,
        method,
        data,
        button
    ) {
        const requestButton = button || null;

        if (requestButton) {
            requestButton.disabled = true;
            requestButton.classList.add('is-loading');
        }

        try {
            const requestOptions = {
                method: method || 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            };

            if (
                requestOptions.method !== 'GET' &&
                requestOptions.method !== 'HEAD'
            ) {
                requestOptions.headers['Content-Type'] =
                    'application/json';

                requestOptions.headers['X-CSRF-Token'] =
                    csrfToken;

                requestOptions.body =
                    JSON.stringify(data || {});
            }

            const separator =
                apiPath.indexOf('?') >= 0
                    ? '&'
                    : '?';

            const url =
                apiPath +
                separator +
                'action=' +
                encodeURIComponent(String(action || ''));

            const response =
                await fetch(
                    url,
                    requestOptions
                );

            let result = null;

            try {
                result = await response.json();
            } catch (error) {
                throw new Error(
                    'サーバーから正しい応答を取得できませんでした。'
                );
            }

            if (!response.ok || !result || result.success !== true) {
                const message =
                    result &&
                    typeof result.message === 'string' &&
                    result.message !== ''
                        ? result.message
                        : '処理に失敗しました。';

                throw new Error(message);
            }

            return result;

        } finally {
            if (requestButton) {
                requestButton.disabled = false;
                requestButton.classList.remove('is-loading');
            }
        }
    }


    /*
     * 画面切り替え
     */
    function showPage(pageName) {
        const pages = [
            'page-list',
            'page-editor',
            'page-detail',
            'page-customers',
            'page-settings'
        ];

        pages.forEach(function (pageId) {
            const page = getElement(pageId);

            if (!page) {
                return;
            }

            page.classList.toggle(
                'hidden',
                pageId !== 'page-' + pageName
            );
        });

        currentPage = pageName;

        const navMap = {
            list: 'nav-list',
            editor: 'nav-create',
            detail: 'nav-list',
            customers: 'nav-customers',
            settings: 'nav-settings'
        };

        Object.keys(navMap).forEach(function (key) {
            const button =
                getElement(navMap[key]);

            if (!button) {
                return;
            }

            button.classList.toggle(
                'active',
                key === pageName ||
                (
                    pageName === 'detail' &&
                    key === 'list'
                )
            );
        });
    }


    /*
     * 日付表示
     */
    function formatDate(value) {
        const text = String(value || '');

        if (text === '') {
            return '－';
        }

        return text;
    }


    /*
     * 状態表示
     */
    function statusLabel(status) {
        const labels = {
            draft: '下書き',
            open: '公開中',
            end: '終了'
        };

        return labels[String(status || '')] || '－';
    }


    /*
     * 配列保証
     */
    function asArray(value) {
        return Array.isArray(value)
            ? value
            : [];
    }


    /*
     * アンケート一覧取得
     */
    async function loadSurveys(button) {
        try {
            const result =
                await apiRequest(
                    'list_surveys',
                    'GET',
                    {},
                    button
                );

            surveys =
                asArray(
                    result &&
                    result.data &&
                    result.data.surveys
                );

            renderSurveyList();

        } catch (error) {
            showNotice(
                'list-notice',
                error instanceof Error
                    ? error.message
                    : 'アンケート一覧を取得できませんでした。',
                'error'
            );
        }
    }


    /*
     * アンケート一覧描画
     */
    function renderSurveyList() {
        const body =
            getElement('survey-list-body');

        if (!body) {
            return;
        }

        while (body.firstChild) {
            body.removeChild(body.firstChild);
        }

        if (surveys.length === 0) {
            const row =
                document.createElement('tr');

            const cell =
                document.createElement('td');

            cell.colSpan = 7;
            cell.className = 'empty-cell';
            cell.textContent =
                '登録されているアンケートはありません。';

            row.appendChild(cell);
            body.appendChild(row);

            return;
        }

        surveys.forEach(function (survey) {

            if (!survey || typeof survey !== 'object') {
                return;
            }

            const row =
                document.createElement('tr');


            const nameCell =
                document.createElement('td');

            const nameButton =
                document.createElement('button');

            nameButton.type = 'button';
            nameButton.className = 'link-button';
            nameButton.textContent =
                String(survey.name || '名称未設定');

            nameButton.addEventListener(
                'click',
                function () {
                    openSurveyDetail(
                        String(survey.id || '')
                    );
                }
            );

            nameCell.appendChild(nameButton);
            row.appendChild(nameCell);


            const statusCell =
                document.createElement('td');

            const statusBadge =
                document.createElement('span');

            statusBadge.className =
                'status-badge status-' +
                String(survey.status || 'draft');

            statusBadge.textContent =
                statusLabel(survey.status);

            statusCell.appendChild(statusBadge);
            row.appendChild(statusCell);


            const createdCell =
                document.createElement('td');

            createdCell.textContent =
                formatDate(survey.created);

            row.appendChild(createdCell);


            const periodCell =
                document.createElement('td');

            const start =
                String(survey.start || '');

            const end =
                String(survey.end || '');

            if (start === '' && end === '') {
                periodCell.textContent = '－';
            } else {
                periodCell.textContent =
                    (start || '－') +
                    ' ～ ' +
                    (end || '－');
            }

            row.appendChild(periodCell);


            const answersCell =
                document.createElement('td');

            answersCell.textContent =
                String(
                    Number.isFinite(
                        Number(survey.answers)
                    )
                        ? Number(survey.answers)
                        : 0
                );

            row.appendChild(answersCell);


            const updatedCell =
                document.createElement('td');

            updatedCell.textContent =
                formatDate(survey.updated);

            row.appendChild(updatedCell);


            const actionCell =
                document.createElement('td');

            const actionArea =
                document.createElement('div');

            actionArea.className =
                'table-actions';


            const detailButton =
                document.createElement('button');

            detailButton.type = 'button';
            detailButton.className =
                'btn btn-small';

            detailButton.textContent =
                '詳細';

            detailButton.addEventListener(
                'click',
                function () {
                    openSurveyDetail(
                        String(survey.id || '')
                    );
                }
            );

            actionArea.appendChild(detailButton);


            const editButton =
                document.createElement('button');

            editButton.type = 'button';
            editButton.className =
                'btn btn-small';

            editButton.textContent =
                '編集';

            editButton.addEventListener(
                'click',
                function () {
                    openSurveyEditor(
                        String(survey.id || '')
                    );
                }
            );

            actionArea.appendChild(editButton);


            const deleteButton =
                document.createElement('button');

            deleteButton.type = 'button';
            deleteButton.className =
                'btn btn-small btn-danger';

            deleteButton.textContent =
                '削除';

            deleteButton.addEventListener(
                'click',
                async function () {
                    await deleteSurvey(
                        String(survey.id || ''),
                        deleteButton
                    );
                }
            );

            actionArea.appendChild(deleteButton);

            actionCell.appendChild(actionArea);
            row.appendChild(actionCell);

            body.appendChild(row);
        });
    }


    /*
     * アンケート作成
     */
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
            groups: [
                {
                    id: createClientId('group'),
                    name: 'グループ1',
                    questions: [
                        {
                            id: createClientId('question'),
                            text: '',
                            type: 'free',
                            required: false,
                            options: []
                        }
                    ]
                }
            ]
        };
    }


    /*
     * ブラウザ側ID
     */
    function createClientId(prefix) {
        return (
            String(prefix || 'item') +
            '_' +
            Date.now().toString(36) +
            '_' +
            Math.random()
                .toString(36)
                .slice(2, 10)
        );
    }


    /*
     * 編集画面
     */
    function openSurveyEditor(surveyId) {
        let survey = null;

        surveys.forEach(function (item) {
            if (
                item &&
                String(item.id || '') ===
                    String(surveyId || '')
            ) {
                survey = item;
            }
        });

        if (!survey) {
            showNotice(
                'list-notice',
                '対象のアンケートが見つかりません。',
                'error'
            );
            return;
        }

        editingSurvey =
            JSON.parse(
                JSON.stringify(survey)
            );

        setupEditorForm();
        renderEditor();
        showPage('editor');
    }


    /*
     * 新規作成画面
     */
    function openNewSurveyEditor() {
        editingSurvey =
            createEmptySurvey();

        setupEditorForm();
        renderEditor();
        showPage('editor');
    }


    /*
     * 編集フォーム初期値
     */
    function setupEditorForm() {
        if (!editingSurvey) {
            return;
        }

        const title =
            getElement('editor-page-title');

        if (title) {
            title.textContent =
                editingSurvey.id
                    ? 'アンケート編集'
                    : 'アンケート作成';
        }


        const name =
            getElement('survey-name');

        if (name) {
            name.value =
                String(editingSurvey.name || '');
        }


        const status =
            getElement('survey-status');

        if (status) {
            status.value =
                String(
                    editingSurvey.status || 'draft'
                );
        }


        const description =
            getElement('survey-description');

        if (description) {
            description.value =
                String(
                    editingSurvey.description || ''
                );
        }


        const start =
            getElement('survey-start');

        if (start) {
            start.value =
                String(editingSurvey.start || '');
        }


        const end =
            getElement('survey-end');

        if (end) {
            end.value =
                String(editingSurvey.end || '');
        }


        const numbering =
            String(
                editingSurvey.numbering || 'global'
            );

        const numberingInputs =
            document.querySelectorAll(
                'input[name="numbering"]'
            );

        numberingInputs.forEach(
            function (input) {
                if (!input) {
                    return;
                }

                input.checked =
                    input.value === numbering;
            }
        );
    }


    /*
     * 編集内容を状態へ反映
     */
    function syncEditorForm() {
        if (!editingSurvey) {
            return;
        }

        const name =
            getElement('survey-name');

        if (name) {
            editingSurvey.name =
                String(name.value || '').trim();
        }


        const status =
            getElement('survey-status');

        if (status) {
            editingSurvey.status =
                String(status.value || 'draft');
        }


        const description =
            getElement('survey-description');

        if (description) {
            editingSurvey.description =
                String(description.value || '');
        }


        const start =
            getElement('survey-start');

        if (start) {
            editingSurvey.start =
                String(start.value || '');
        }


        const end =
            getElement('survey-end');

        if (end) {
            editingSurvey.end =
                String(end.value || '');
        }


        const numbering =
            document.querySelector(
                'input[name="numbering"]:checked'
            );

        if (numbering) {
            editingSurvey.numbering =
                String(numbering.value || 'global');
        }
    }


    /*
     * グループ追加
     */
    function addGroup() {
        if (!editingSurvey) {
            return;
        }

        if (!Array.isArray(editingSurvey.groups)) {
            editingSurvey.groups = [];
        }

        editingSurvey.groups.push({
            id: createClientId('group'),
            name:
                'グループ' +
                String(
                    editingSurvey.groups.length + 1
                ),
            questions: [
                {
                    id: createClientId('question'),
                    text: '',
                    type: 'free',
                    required: false,
                    options: []
                }
            ]
        });

        renderEditor();
    }


    /*
     * 質問追加
     */
    function addQuestion(groupId) {
        if (!editingSurvey) {
            return;
        }

        if (!Array.isArray(editingSurvey.groups)) {
            editingSurvey.groups = [];
        }

        editingSurvey.groups.forEach(
            function (group) {

                if (
                    !group ||
                    String(group.id || '') !==
                        String(groupId || '')
                ) {
                    return;
                }

                if (!Array.isArray(group.questions)) {
                    group.questions = [];
                }

                group.questions.push({
                    id: createClientId('question'),
                    text: '',
                    type: 'free',
                    required: false,
                    options: []
                });
            }
        );

        renderEditor();
    }


    /*
     * 質問削除
     */
    function deleteQuestion(questionId) {
        if (!editingSurvey) {
            return;
        }

        editingSurvey.groups =
            asArray(editingSurvey.groups);

        editingSurvey.groups.forEach(
            function (group) {

                if (!group) {
                    return;
                }

                group.questions =
                    asArray(group.questions)
                        .filter(
                            function (question) {
                                return String(
                                    question.id || ''
                                ) !==
                                String(
                                    questionId || ''
                                );
                            }
                        );
            }
        );

        renderEditor();
    }


    /*
     * グループ削除
     */
    function deleteGroup(groupId) {
        if (!editingSurvey) {
            return;
        }

        editingSurvey.groups =
            asArray(editingSurvey.groups);

        if (editingSurvey.groups.length <= 1) {
            showToast(
                'グループは1つ以上必要です。'
            );
            return;
        }

        editingSurvey.groups =
            editingSurvey.groups.filter(
                function (group) {
                    return String(
                        group.id || ''
                    ) !==
                    String(groupId || '');
                }
            );

        renderEditor();
    }


    /*
     * エディタ描画
     */
    function renderEditor() {
        if (!editingSurvey) {
            return;
        }

        syncEditorForm();

        const groupsElement =
            getElement('groups');

        if (!groupsElement) {
            return;
        }

        while (groupsElement.firstChild) {
            groupsElement.removeChild(
                groupsElement.firstChild
            );
        }

        const groupList =
            asArray(editingSurvey.groups);

        groupList.forEach(
            function (group, groupIndex) {

                if (!group) {
                    return;
                }

                const groupCard =
                    document.createElement('div');

                groupCard.className =
                    'group-card';

                groupCard.draggable = true;

                groupCard.dataset.groupId =
                    String(group.id || '');


                groupCard.addEventListener(
                    'dragstart',
                    function () {
                        draggedGroup =
                            String(group.id || '');

                        groupCard.classList.add(
                            'dragging'
                        );
                    }
                );


                groupCard.addEventListener(
                    'dragend',
                    function () {
                        draggedGroup = '';

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

                        const targetId =
                            String(group.id || '');

                        if (
                            draggedGroup &&
                            draggedGroup !== targetId
                        ) {
                            moveGroup(
                                draggedGroup,
                                targetId
                            );
                        }
                    }
                );


                const groupHeader =
                    document.createElement('div');

                groupHeader.className =
                    'group-header';


                const dragHandle =
                    document.createElement('span');

                dragHandle.className =
                    'drag-handle';

                dragHandle.textContent =
                    '☷';

                dragHandle.setAttribute(
                    'aria-label',
                    'グループを並べ替え'
                );


                const groupTitle =
                    document.createElement('div');

                groupTitle.className =
                    'group-title';


                const groupInput =
                    document.createElement('input');

                groupInput.type = 'text';
                groupInput.maxLength = 200;

                groupInput.value =
                    String(group.name || '');

                groupInput.placeholder =
                    'グループ名';


                groupInput.addEventListener(
                    'input',
                    function () {
                        group.name =
                            groupInput.value;
                    }
                );


                groupTitle.appendChild(
                    groupInput
                );


                const groupActions =
                    document.createElement('div');

                groupActions.className =
                    'group-actions';


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


                groupActions.appendChild(
                    deleteGroupButton
                );


                groupHeader.appendChild(
                    dragHandle
                );

                groupHeader.appendChild(
                    groupTitle
                );

                groupHeader.appendChild(
                    groupActions
                );


                groupCard.appendChild(
                    groupHeader
                );


                const questions =
                    document.createElement('div');

                questions.className =
                    'questions';


                const questionList =
                    asArray(group.questions);


                questionList.forEach(
                    function (
                        question,
                        questionIndex
                    ) {

                        if (!question) {
                            return;
                        }

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


                groupCard.appendChild(
                    questions
                );


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


                addArea.appendChild(
                    addButton
                );

                groupCard.appendChild(
                    addArea
                );


                groupsElement.appendChild(
                    groupCard
                );
            }
        );
    }


    /*
     * 質問番号
     */
    function questionNumber(
        groupIndex,
        questionIndex
    ) {
        if (!editingSurvey) {
            return '';
        }

        const numbering =
            String(
                editingSurvey.numbering || 'global'
            );

        if (numbering === 'group') {
            return (
                'Q' +
                String(groupIndex + 1) +
                '-' +
                String(questionIndex + 1)
            );
        }

        let number = 0;

        asArray(
            editingSurvey.groups
        ).forEach(
            function (group, index) {

                if (index > groupIndex) {
                    return;
                }

                const questions =
                    asArray(
                        group &&
                        group.questions
                    );

                if (index === groupIndex) {
                    number +=
                        questionIndex + 1;
                } else {
                    number += questions.length;
                }
            }
        );

        return 'Q' + String(number);
    }
    /*
     * 質問編集UI
     */
    function renderQuestion(
        group,
        question,
        groupIndex,
        questionIndex
    ) {
        const wrapper =
            document.createElement('div');

        wrapper.className =
            'question-card';

        wrapper.draggable = true;

        wrapper.dataset.questionId =
            String(question.id || '');


        wrapper.addEventListener(
            'dragstart',
            function () {
                draggedQuestion =
                    String(question.id || '');

                wrapper.classList.add(
                    'dragging'
                );
            }
        );


        wrapper.addEventListener(
            'dragend',
            function () {
                draggedQuestion = '';

                wrapper.classList.remove(
                    'dragging'
                );
            }
        );


        wrapper.addEventListener(
            'dragover',
            function (event) {
                event.preventDefault();

                wrapper.classList.add(
                    'drag-over'
                );
            }
        );


        wrapper.addEventListener(
            'dragleave',
            function () {
                wrapper.classList.remove(
                    'drag-over'
                );
            }
        );


        wrapper.addEventListener(
            'drop',
            function (event) {
                event.preventDefault();

                wrapper.classList.remove(
                    'drag-over'
                );

                const targetId =
                    String(question.id || '');

                if (
                    draggedQuestion &&
                    draggedQuestion !== targetId
                ) {
                    moveQuestion(
                        draggedQuestion,
                        targetId
                    );
                }
            }
        );


        const header =
            document.createElement('div');

        header.className =
            'question-header';


        const left =
            document.createElement('div');

        left.className =
            'question-header-left';


        const handle =
            document.createElement('span');

        handle.className =
            'drag-handle';

        handle.textContent =
            '☷';

        handle.setAttribute(
            'aria-label',
            '質問を並べ替え'
        );


        const number =
            document.createElement('span');

        number.className =
            'question-number';

        number.textContent =
            questionNumber(
                groupIndex,
                questionIndex
            );


        left.appendChild(handle);
        left.appendChild(number);


        const right =
            document.createElement('div');

        right.className =
            'question-actions';


        const deleteButton =
            document.createElement('button');

        deleteButton.type = 'button';
        deleteButton.className =
            'btn btn-small btn-danger';

        deleteButton.textContent =
            '削除';


        deleteButton.addEventListener(
            'click',
            function () {
                deleteQuestion(
                    String(question.id || '')
                );
            }
        );


        right.appendChild(
            deleteButton
        );


        header.appendChild(left);
        header.appendChild(right);

        wrapper.appendChild(header);


        const content =
            document.createElement('div');

        content.className =
            'question-content';


        const textField =
            document.createElement('div');

        textField.className =
            'field';


        const textLabel =
            document.createElement('label');

        textLabel.textContent =
            '質問文';


        const textInput =
            document.createElement('textarea');

        textInput.rows = 2;
        textInput.maxLength = 2000;

        textInput.value =
            String(question.text || '');

        textInput.placeholder =
            '質問文を入力してください';


        textInput.addEventListener(
            'input',
            function () {
                question.text =
                    textInput.value;
            }
        );


        textField.appendChild(
            textLabel
        );

        textField.appendChild(
            textInput
        );

        content.appendChild(
            textField
        );


        const settings =
            document.createElement('div');

        settings.className =
            'question-settings';


        const typeField =
            document.createElement('div');

        typeField.className =
            'field';


        const typeLabel =
            document.createElement('label');

        typeLabel.textContent =
            '回答形式';


        const typeSelect =
            document.createElement('select');


        const typeOptions = [
            {
                value: 'free',
                label: '自由記述'
            },
            {
                value: 'single',
                label: '単一選択'
            },
            {
                value: 'multiple',
                label: '複数選択'
            },
            {
                value: 'scale',
                label: '5段階評価'
            },
            {
                value: 'date',
                label: '日付'
            }
        ];


        typeOptions.forEach(
            function (option) {

                const optionElement =
                    document.createElement('option');

                optionElement.value =
                    option.value;

                optionElement.textContent =
                    option.label;

                typeSelect.appendChild(
                    optionElement
                );
            }
        );


        typeSelect.value =
            String(
                question.type || 'free'
            );


        typeSelect.addEventListener(
            'change',
            function () {

                question.type =
                    String(
                        typeSelect.value || 'free'
                    );

                if (
                    question.type !== 'single' &&
                    question.type !== 'multiple'
                ) {
                    question.options = [];
                }

                renderEditor();
            }
        );


        typeField.appendChild(
            typeLabel
        );

        typeField.appendChild(
            typeSelect
        );


        const requiredField =
            document.createElement('div');

        requiredField.className =
            'field required-field';


        const requiredLabel =
            document.createElement('label');


        const requiredInput =
            document.createElement('input');

        requiredInput.type = 'checkbox';

        requiredInput.checked =
            Boolean(question.required);


        requiredInput.addEventListener(
            'change',
            function () {
                question.required =
                    requiredInput.checked;
            }
        );


        const requiredText =
            document.createElement('span');

        requiredText.textContent =
            '必須回答';


        requiredLabel.appendChild(
            requiredInput
        );

        requiredLabel.appendChild(
            requiredText
        );


        requiredField.appendChild(
            requiredLabel
        );


        settings.appendChild(
            typeField
        );

        settings.appendChild(
            requiredField
        );


        content.appendChild(
            settings
        );


        if (
            question.type === 'single' ||
            question.type === 'multiple'
        ) {
            content.appendChild(
                renderOptionsEditor(
                    question
                )
            );
        }


        wrapper.appendChild(
            content
        );

        return wrapper;
    }


    /*
     * 選択肢編集
     */
    function renderOptionsEditor(question) {
        const area =
            document.createElement('div');

        area.className =
            'options-editor';


        const title =
            document.createElement('div');

        title.className =
            'options-title';

        title.textContent =
            '選択肢';


        area.appendChild(title);


        if (!Array.isArray(question.options)) {
            question.options = [];
        }


        question.options.forEach(
            function (option, index) {

                const row =
                    document.createElement('div');

                row.className =
                    'option-row';


                const input =
                    document.createElement('input');

                input.type = 'text';
                input.maxLength = 500;

                input.value =
                    String(option || '');

                input.placeholder =
                    '選択肢' +
                    String(index + 1);


                input.addEventListener(
                    'input',
                    function () {
                        question.options[index] =
                            input.value;
                    }
                );


                const deleteButton =
                    document.createElement('button');

                deleteButton.type = 'button';
                deleteButton.className =
                    'btn btn-small btn-danger';

                deleteButton.textContent =
                    '削除';


                deleteButton.addEventListener(
                    'click',
                    function () {

                        question.options.splice(
                            index,
                            1
                        );

                        renderEditor();
                    }
                );


                row.appendChild(input);
                row.appendChild(deleteButton);

                area.appendChild(row);
            }
        );


        const addButton =
            document.createElement('button');

        addButton.type = 'button';
        addButton.className =
            'btn btn-small';

        addButton.textContent =
            '＋ 選択肢追加';


        addButton.addEventListener(
            'click',
            function () {

                if (
                    !Array.isArray(
                        question.options
                    )
                ) {
                    question.options = [];
                }

                question.options.push('');

                renderEditor();
            }
        );


        area.appendChild(
            addButton
        );

        return area;
    }


    /*
     * グループ並べ替え
     */
    function moveGroup(
        sourceId,
        targetId
    ) {
        if (!editingSurvey) {
            return;
        }

        const groups =
            asArray(
                editingSurvey.groups
            );

        const sourceIndex =
            groups.findIndex(
                function (group) {
                    return String(
                        group &&
                        group.id || ''
                    ) ===
                    String(sourceId || '');
                }
            );

        const targetIndex =
            groups.findIndex(
                function (group) {
                    return String(
                        group &&
                        group.id || ''
                    ) ===
                    String(targetId || '');
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
            groups.splice(
                sourceIndex,
                1
            )[0];


        groups.splice(
            targetIndex,
            0,
            moved
        );


        editingSurvey.groups =
            groups;

        renderEditor();
    }


    /*
     * 質問並べ替え
     *
     * 同一グループ内だけでなく、
     * 別グループへの移動にも対応する。
     */
    function moveQuestion(
        sourceId,
        targetId
    ) {
        if (!editingSurvey) {
            return;
        }


        let sourceQuestion = null;
        let sourceGroup = null;
        let sourceIndex = -1;


        editingSurvey.groups =
            asArray(
                editingSurvey.groups
            );


        editingSurvey.groups.forEach(
            function (group) {

                if (
                    !group ||
                    sourceQuestion
                ) {
                    return;
                }

                const questions =
                    asArray(
                        group.questions
                    );


                questions.forEach(
                    function (
                        question,
                        index
                    ) {

                        if (
                            question &&
                            String(
                                question.id || ''
                            ) ===
                            String(
                                sourceId || ''
                            )
                        ) {
                            sourceQuestion =
                                question;

                            sourceGroup =
                                group;

                            sourceIndex =
                                index;
                        }
                    }
                );
            }
        );


        if (
            !sourceQuestion ||
            !sourceGroup ||
            sourceIndex < 0
        ) {
            return;
        }


        let targetGroup = null;
        let targetIndex = -1;


        editingSurvey.groups.forEach(
            function (group) {

                if (
                    !group ||
                    targetGroup
                ) {
                    return;
                }

                const questions =
                    asArray(
                        group.questions
                    );


                questions.forEach(
                    function (
                        question,
                        index
                    ) {

                        if (
                            question &&
                            String(
                                question.id || ''
                            ) ===
                            String(
                                targetId || ''
                            )
                        ) {
                            targetGroup =
                                group;

                            targetIndex =
                                index;
                        }
                    }
                );
            }
        );


        if (
            !targetGroup ||
            targetIndex < 0
        ) {
            return;
        }


        sourceGroup.questions =
            asArray(
                sourceGroup.questions
            );


        sourceGroup.questions.splice(
            sourceIndex,
            1
        );


        if (
            sourceGroup === targetGroup &&
            sourceIndex < targetIndex
        ) {
            targetIndex -= 1;
        }


        targetGroup.questions =
            asArray(
                targetGroup.questions
            );


        targetGroup.questions.splice(
            targetIndex,
            0,
            sourceQuestion
        );


        renderEditor();
    }


    /*
     * 編集内容の検証
     */
    function validateSurvey() {
        if (!editingSurvey) {
            return {
                valid: false,
                message: '編集対象がありません。'
            };
        }


        syncEditorForm();


        if (
            String(
                editingSurvey.name || ''
            ).trim() === ''
        ) {
            return {
                valid: false,
                message:
                    'アンケート名を入力してください。'
            };
        }


        if (
            editingSurvey.start &&
            editingSurvey.end &&
            String(editingSurvey.start) >
                String(editingSurvey.end)
        ) {
            return {
                valid: false,
                message:
                    '公開終了日は公開開始日以降にしてください。'
            };
        }


        const groups =
            asArray(
                editingSurvey.groups
            );


        if (groups.length === 0) {
            return {
                valid: false,
                message:
                    'グループを1つ以上登録してください。'
            };
        }


        for (
            let groupIndex = 0;
            groupIndex < groups.length;
            groupIndex += 1
        ) {
            const group =
                groups[groupIndex];

            if (!group) {
                continue;
            }


            if (
                String(
                    group.name || ''
                ).trim() === ''
            ) {
                return {
                    valid: false,
                    message:
                        'グループ名を入力してください。'
                };
            }


            const questions =
                asArray(
                    group.questions
                );


            if (questions.length === 0) {
                return {
                    valid: false,
                    message:
                        '各グループには質問を1つ以上登録してください。'
                };
            }


            for (
                let questionIndex = 0;
                questionIndex < questions.length;
                questionIndex += 1
            ) {
                const question =
                    questions[questionIndex];

                if (!question) {
                    continue;
                }


                if (
                    String(
                        question.text || ''
                    ).trim() === ''
                ) {
                    return {
                        valid: false,
                        message:
                            '質問文を入力してください。'
                    };
                }


                if (
                    question.type === 'single' ||
                    question.type === 'multiple'
                ) {
                    const options =
                        asArray(
                            question.options
                        ).filter(
                            function (option) {
                                return String(
                                    option || ''
                                ).trim() !== '';
                            }
                        );


                    if (options.length < 2) {
                        return {
                            valid: false,
                            message:
                                '選択式の質問には2つ以上の選択肢を登録してください。'
                        };
                    }
                }
            }
        }


        return {
            valid: true,
            message: ''
        };
    }


    /*
     * アンケート保存
     */
    async function saveSurvey(button) {
        const validation =
            validateSurvey();


        if (!validation.valid) {
            showNotice(
                'editor-notice',
                validation.message,
                'error'
            );

            return;
        }


        try {
            const result =
                await apiRequest(
                    'save_survey',
                    'POST',
                    {
                        survey: editingSurvey
                    },
                    button
                );


            if (
                result &&
                result.data &&
                result.data.survey
            ) {
                editingSurvey =
                    result.data.survey;
            }


            showToast(
                'アンケートを保存しました。'
            );


            await loadSurveys();


            if (editingSurvey.id) {
                openSurveyDetail(
                    String(
                        editingSurvey.id
                    )
                );
            } else {
                showPage('list');
            }

        } catch (error) {
            showNotice(
                'editor-notice',
                error instanceof Error
                    ? error.message
                    : 'アンケートを保存できませんでした。',
                'error'
            );
        }
    }


    /*
     * アンケート削除
     */
    async function deleteSurvey(
        surveyId,
        button
    ) {
        if (
            String(surveyId || '') === ''
        ) {
            return;
        }


        const confirmed =
            await openConfirmModal(
                'アンケート削除',
                'このアンケートを削除します。よろしいですか？'
            );


        if (!confirmed) {
            return;
        }


        try {
            await apiRequest(
                'delete_survey',
                'POST',
                {
                    id: String(surveyId)
                },
                button
            );


            showToast(
                'アンケートを削除しました。'
            );


            await loadSurveys();

        } catch (error) {
            showNotice(
                'list-notice',
                error instanceof Error
                    ? error.message
                    : 'アンケートを削除できませんでした。',
                'error'
            );
        }
    }


    /*
     * 詳細取得
     */
    async function openSurveyDetail(
        surveyId
    ) {
        if (
            String(surveyId || '') === ''
        ) {
            return;
        }


        selectedSurveyId =
            String(surveyId);


        showPage('detail');


        showNotice(
            'detail-notice',
            'アンケート情報を読み込んでいます。',
            ''
        );


        try {
            const result =
                await apiRequest(
                    'get_survey',
                    'GET',
                    {
                        id: selectedSurveyId
                    },
                    null
                );


            const survey =
                result &&
                result.data &&
                result.data.survey
                    ? result.data.survey
                    : null;


            if (!survey) {
                throw new Error(
                    'アンケート情報を取得できませんでした。'
                );
            }


            renderSurveyDetail(
                survey
            );


            showNotice(
                'detail-notice',
                '',
                ''
            );

        } catch (error) {
            showNotice(
                'detail-notice',
                error instanceof Error
                    ? error.message
                    : 'アンケート詳細を取得できませんでした。',
                'error'
            );
        }
    }


    /*
     * 詳細画面描画
     */
    function renderSurveyDetail(
        survey
    ) {
        if (!survey) {
            return;
        }


        const title =
            getElement('detail-title');

        if (title) {
            title.textContent =
                String(
                    survey.name ||
                    'アンケート詳細'
                );
        }


        const subtitle =
            getElement('detail-subtitle');

        if (subtitle) {
            subtitle.textContent =
                String(
                    survey.description || ''
                );
        }


        const status =
            getElement('detail-status');

        if (status) {
            status.textContent =
                statusLabel(
                    survey.status
                );

            status.className =
                'summary-value status-text-' +
                String(
                    survey.status || 'draft'
                );
        }


        const answers =
            getElement('detail-answers');

        if (answers) {
            answers.textContent =
                String(
                    Number.isFinite(
                        Number(survey.answers)
                    )
                        ? Number(survey.answers)
                        : 0
                );
        }


        const target =
            getElement('detail-target');

        if (target) {
            target.textContent =
                String(
                    Number.isFinite(
                        Number(survey.target)
                    )
                        ? Number(survey.target)
                        : 0
                );
        }


        const sent =
            getElement('detail-sent');

        if (sent) {
            sent.textContent =
                String(
                    Number.isFinite(
                        Number(survey.sent)
                    )
                        ? Number(survey.sent)
                        : 0
                );
        }


        const description =
            getElement('detail-description');

        if (description) {
            description.textContent =
                String(
                    survey.description || '－'
                );
        }


        const period =
            getElement('detail-period');

        if (period) {

            const start =
                String(survey.start || '');

            const end =
                String(survey.end || '');


            if (
                start === '' &&
                end === ''
            ) {
                period.textContent =
                    '－';
            } else {
                period.textContent =
                    (start || '－') +
                    ' ～ ' +
                    (end || '－');
            }
        }


        const created =
            getElement('detail-created');

        if (created) {
            created.textContent =
                formatDate(
                    survey.created
                );
        }


        const updated =
            getElement('detail-updated');

        if (updated) {
            updated.textContent =
                formatDate(
                    survey.updated
                );
        }


        const targetCount =
            getElement(
                'send-target-count'
            );

        if (targetCount) {
            const count =
                Number.isFinite(
                    Number(survey.target)
                )
                    ? Number(survey.target)
                    : 0;

            targetCount.textContent =
                '対象者：' +
                String(count) +
                '名';
        }


        const questions =
            getElement(
                'detail-questions'
            );

        if (!questions) {
            return;
        }


        while (questions.firstChild) {
            questions.removeChild(
                questions.firstChild
            );
        }


        const groups =
            asArray(
                survey.groups
            );


        if (groups.length === 0) {
            const empty =
                document.createElement('div');

            empty.className =
                'empty-cell';

            empty.textContent =
                '質問は登録されていません。';

            questions.appendChild(empty);

            return;
        }


        groups.forEach(
            function (
                group,
                groupIndex
            ) {

                if (!group) {
                    return;
                }


                const groupBlock =
                    document.createElement('div');

                groupBlock.className =
                    'detail-group';


                const groupTitle =
                    document.createElement('h3');

                groupTitle.textContent =
                    String(
                        group.name ||
                        'グループ' +
                        String(groupIndex + 1)
                    );


                groupBlock.appendChild(
                    groupTitle
                );


                const questionList =
                    document.createElement('div');

                questionList.className =
                    'detail-question-list';


                asArray(
                    group.questions
                ).forEach(
                    function (
                        question,
                        questionIndex
                    ) {

                        if (!question) {
                            return;
                        }


                        const item =
                            document.createElement('div');

                        item.className =
                            'detail-question';


                        const number =
                            document.createElement('div');

                        number.className =
                            'detail-question-number';

                        number.textContent =
                            survey.numbering === 'group'
                                ? (
                                    'Q' +
                                    String(
                                        groupIndex + 1
                                    ) +
                                    '-' +
                                    String(
                                        questionIndex + 1
                                    )
                                )
                                : getGlobalQuestionNumber(
                                    groups,
                                    groupIndex,
                                    questionIndex
                                );


                        const body =
                            document.createElement('div');

                        body.className =
                            'detail-question-body';


                        const text =
                            document.createElement('div');

                        text.className =
                            'detail-question-text';

                        text.textContent =
                            String(
                                question.text || ''
                            );


                        const meta =
                            document.createElement('div');

                        meta.className =
                            'detail-question-meta';

                        meta.textContent =
                            getQuestionTypeLabel(
                                question.type
                            ) +
                            (
                                question.required
                                    ? ' ／ 必須'
                                    : ' ／ 任意'
                            );


                        body.appendChild(text);
                        body.appendChild(meta);


                        if (
                            question.type === 'single' ||
                            question.type === 'multiple'
                        ) {
                            const options =
                                document.createElement('ul');

                            options.className =
                                'detail-options';


                            asArray(
                                question.options
                            ).forEach(
                                function (option) {

                                    const optionItem =
                                        document.createElement('li');

                                    optionItem.textContent =
                                        String(
                                            option || ''
                                        );

                                    options.appendChild(
                                        optionItem
                                    );
                                }
                            );


                            body.appendChild(
                                options
                            );
                        }


                        item.appendChild(number);
                        item.appendChild(body);

                        questionList.appendChild(
                            item
                        );
                    }
                );


                groupBlock.appendChild(
                    questionList
                );

                questions.appendChild(
                    groupBlock
                );
            }
        );
    }


    /*
     * 詳細画面用の通番
     */
    function getGlobalQuestionNumber(
        groups,
        groupIndex,
        questionIndex
    ) {
        let number = 0;


        for (
            let index = 0;
            index <= groupIndex;
            index += 1
        ) {
            const group =
                groups[index];


            const questions =
                asArray(
                    group &&
                    group.questions
                );


            if (index === groupIndex) {
                number +=
                    questionIndex + 1;
            } else {
                number +=
                    questions.length;
            }
        }


        return 'Q' + String(number);
    }


    /*
     * 回答形式表示
     */
    function getQuestionTypeLabel(
        type
    ) {
        const labels = {
            free: '自由記述',
            single: '単一選択',
            multiple: '複数選択',
            scale: '5段階評価',
            date: '日付'
        };


        return labels[
            String(type || '')
        ] || '回答形式未設定';
    }


    /*
     * 回答結果表示
     */
    async function loadSurveyResults(
        button
    ) {
        if (
            String(
                selectedSurveyId || ''
            ) === ''
        ) {
            return;
        }


        const resultArea =
            getElement(
                'detail-results'
            );


        if (!resultArea) {
            return;
        }


        try {
            const result =
                await apiRequest(
                    'survey_results',
                    'GET',
                    {
                        id: selectedSurveyId
                    },
                    button
                );


            renderSurveyResults(
                resultArea,
                result &&
                result.data
                    ? result.data
                    : {}
            );


            resultArea.classList.remove(
                'hidden'
            );

        } catch (error) {
            showNotice(
                'detail-notice',
                error instanceof Error
                    ? error.message
                    : '回答結果を取得できませんでした。',
                'error'
            );
        }
    }


    /*
     * 回答結果描画
     */
    function renderSurveyResults(
        target,
        data
    ) {
        if (!target) {
            return;
        }


        while (target.firstChild) {
            target.removeChild(
                target.firstChild
            );
        }


        const results =
            asArray(
                data.results
            );


        if (results.length === 0) {
            const empty =
                document.createElement('div');

            empty.className =
                'empty-cell';

            empty.textContent =
                '回答結果はありません。';

            target.appendChild(empty);

            return;
        }


        const tableWrap =
            document.createElement('div');

        tableWrap.className =
            'table-wrap';


        const table =
            document.createElement('table');

        table.className =
            'table';


        const thead =
            document.createElement('thead');


        const headRow =
            document.createElement('tr');


        [
            '回答日時',
            '回答者',
            '回答内容'
        ].forEach(
            function (label) {

                const th =
                    document.createElement('th');

                th.textContent =
                    label;

                headRow.appendChild(th);
            }
        );


        thead.appendChild(
            headRow
        );

        table.appendChild(
            thead
        );


        const tbody =
            document.createElement('tbody');


        results.forEach(
            function (result) {

                if (
                    !result ||
                    typeof result !== 'object'
                ) {
                    return;
                }


                const row =
                    document.createElement('tr');


                const date =
                    document.createElement('td');

                date.textContent =
                    String(
                        result.created || '－'
                    );


                const respondent =
                    document.createElement('td');

                respondent.textContent =
                    String(
                        result.respondent ||
                        '－'
                    );


                const answer =
                    document.createElement('td');

                answer.textContent =
                    formatAnswerSummary(
                        result.answers
                    );


                row.appendChild(date);
                row.appendChild(respondent);
                row.appendChild(answer);

                tbody.appendChild(row);
            }
        );


        table.appendChild(tbody);
        tableWrap.appendChild(table);

        target.appendChild(
            tableWrap
        );
    }


    /*
     * 回答内容の安全な表示用文字列化
     */
    function formatAnswerSummary(
        answers
    ) {
        if (
            answers === null ||
            answers === undefined
        ) {
            return '－';
        }


        if (
            typeof answers === 'string'
        ) {
            return answers;
        }


        if (Array.isArray(answers)) {
            return answers
                .map(
                    function (value) {
                        return String(
                            value ?? ''
                        );
                    }
                )
                .join('、');
        }


        if (
            typeof answers === 'object'
        ) {
            return Object.keys(
                answers
            )
                .map(
                    function (key) {
                        return (
                            String(key) +
                            '：' +
                            String(
                                answers[key] ?? ''
                            )
                        );
                    }
                )
                .join(' / ');
        }


        return String(answers);
    }


    /*
     * 顧客一覧取得
     */
    async function loadCustomers(
        button
    ) {
        try {
            const result =
                await apiRequest(
                    'list_customers',
                    'GET',
                    {},
                    button
                );


            customers =
                asArray(
                    result &&
                    result.data &&
                    result.data.customers
                );


            renderCustomerList();

        } catch (error) {
            showNotice(
                'customers-notice',
                error instanceof Error
                    ? error.message
                    : '顧客情報を取得できませんでした。',
                'error'
            );
        }
    }


    /*
     * 顧客一覧描画
     */
    function renderCustomerList() {
        const body =
            getElement(
                'customer-list-body'
            );


        if (!body) {
            return;
        }


        while (body.firstChild) {
            body.removeChild(
                body.firstChild
            );
        }


        if (customers.length === 0) {
            const row =
                document.createElement('tr');


            const cell =
                document.createElement('td');

            cell.colSpan = 4;
            cell.className =
                'empty-cell';

            cell.textContent =
                '顧客情報はありません。';


            row.appendChild(cell);
            body.appendChild(row);

            return;
        }


        customers.forEach(
            function (customer) {

                if (
                    !customer ||
                    typeof customer !== 'object'
                ) {
                    return;
                }


                const row =
                    document.createElement('tr');


                const id =
                    document.createElement('td');

                id.textContent =
                    String(
                        customer.id || '－'
                    );


                const company =
                    document.createElement('td');

                company.textContent =
                    String(
                        customer.company || '－'
                    );


                const name =
                    document.createElement('td');

                name.textContent =
                    String(
                        customer.name || '－'
                    );


                const email =
                    document.createElement('td');

                email.textContent =
                    String(
                        customer.email || '－'
                    );


                row.appendChild(id);
                row.appendChild(company);
                row.appendChild(name);
                row.appendChild(email);


                body.appendChild(row);
            }
        );
    }


    /*
     * 設定情報取得
     */
    async function loadSettings() {
        try {
            const result =
                await apiRequest(
                    'get_settings',
                    'GET',
                    {},
                    null
                );


            currentSettings =
                result &&
                result.data &&
                result.data.settings
                    ? result.data.settings
                    : {};


            renderSettings();

        } catch (error) {
            showNotice(
                'settings-notice',
                error instanceof Error
                    ? error.message
                    : '設定情報を取得できませんでした。',
                'error'
            );
        }
    }


    /*
     * 設定画面描画
     */
    function renderSettings() {
        const settings =
            currentSettings &&
            typeof currentSettings === 'object'
                ? currentSettings
                : {};


        const kintone =
            settings.kintone &&
            typeof settings.kintone === 'object'
                ? settings.kintone
                : {};


        const mail =
            settings.mail &&
            typeof settings.mail === 'object'
                ? settings.mail
                : {};


        setInputValue(
            'kintone-domain',
            kintone.domain
        );


        setInputValue(
            'kintone-app-id',
            kintone.app_id
        );


        setInputValue(
            'kintone-login-name',
            kintone.login_name
        );


        setInputValue(
            'kintone-password',
            ''
        );


        setInputValue(
            'kintone-proxy-host',
            kintone.proxy_host
        );


        setInputValue(
            'kintone-proxy-port',
            kintone.proxy_port
        );


        setInputValue(
            'kintone-customer-id-field',
            kintone.customer_id_field
        );


        setInputValue(
            'kintone-customer-name-field',
            kintone.customer_name_field
        );


        setInputValue(
            'kintone-customer-email-field',
            kintone.customer_email_field
        );


        setInputValue(
            'mail-smtp-server',
            mail.smtp_server
        );


        setInputValue(
            'mail-smtp-port',
            mail.smtp_port
        );


        setInputValue(
            'mail-connection-type',
            mail.connection_type ||
            'tls'
        );


        setInputValue(
            'mail-username',
            mail.username
        );


        setInputValue(
            'mail-password',
            ''
        );


        setInputValue(
            'mail-from-email',
            mail.from_email
        );


        setInputValue(
            'mail-from-name',
            mail.from_name
        );
    }


    /*
     * input/select値設定
     */
    function setInputValue(
        id,
        value
    ) {
        const element =
            getElement(id);


        if (!element) {
            return;
        }


        element.value =
            value === null ||
            value === undefined
                ? ''
                : String(value);
    }


    /*
     * kintone設定保存用データ
     */
    function collectKintoneSettings() {
        return {
            domain:
                getInputValue(
                    'kintone-domain'
                ),

            app_id:
                getInputValue(
                    'kintone-app-id'
                ),

            login_name:
                getInputValue(
                    'kintone-login-name'
                ),

            password:
                getInputValue(
                    'kintone-password'
                ),

            proxy_host:
                getInputValue(
                    'kintone-proxy-host'
                ),

            proxy_port:
                getInputValue(
                    'kintone-proxy-port'
                ),

            customer_id_field:
                getInputValue(
                    'kintone-customer-id-field'
                ),

            customer_name_field:
                getInputValue(
                    'kintone-customer-name-field'
                ),

            customer_email_field:
                getInputValue(
                    'kintone-customer-email-field'
                )
        };
    }


    /*
     * メール設定保存用データ
     */
    function collectMailSettings() {
        return {
            smtp_server:
                getInputValue(
                    'mail-smtp-server'
                ),

            smtp_port:
                getInputValue(
                    'mail-smtp-port'
                ),

            connection_type:
                getInputValue(
                    'mail-connection-type'
                ),

            username:
                getInputValue(
                    'mail-username'
                ),

            password:
                getInputValue(
                    'mail-password'
                ),

            from_email:
                getInputValue(
                    'mail-from-email'
                ),

            from_name:
                getInputValue(
                    'mail-from-name'
                )
        };
    }


    /*
     * 入力値取得
     */
    function getInputValue(id) {
        const element =
            getElement(id);


        if (!element) {
            return '';
        }


        return String(
            element.value || ''
        ).trim();
    }


    /*
     * kintone設定保存
     */
    async function saveKintoneSettings(
        button
    ) {
        const settings =
            collectKintoneSettings();


        if (
            settings.domain === ''
        ) {
            showNotice(
                'settings-notice',
                'kintoneドメインを入力してください。',
                'error'
            );

            return;
        }


        try {
            await apiRequest(
                'save_kintone_settings',
                'POST',
                {
                    settings: settings
                },
                button
            );


            showToast(
                'kintone設定を保存しました。'
            );


            setInputValue(
                'kintone-password',
                ''
            );


            await loadSettings();

        } catch (error) {
            showNotice(
                'settings-notice',
                error instanceof Error
                    ? error.message
                    : 'kintone設定を保存できませんでした。',
                'error'
            );
        }
    }


    /*
     * kintone接続テスト
     */
    async function testKintone(
        button
    ) {
        const settings =
            collectKintoneSettings();


        if (
            settings.domain === ''
        ) {
            showNotice(
                'settings-notice',
                'kintoneドメインを入力してください。',
                'error'
            );

            return;
        }


        try {
            const result =
                await apiRequest(
                    'test_kintone',
                    'POST',
                    {
                        settings: settings
                    },
                    button
                );


            setKintoneStatus(
                true,
                result &&
                result.message
                    ? String(result.message)
                    : 'kintoneへの接続に成功しました。'
            );


            showToast(
                'kintoneへの接続を確認しました。'
            );

        } catch (error) {

            setKintoneStatus(
                false,
                error instanceof Error
                    ? error.message
                    : 'kintoneへの接続に失敗しました。'
            );


            showNotice(
                'settings-notice',
                error instanceof Error
                    ? error.message
                    : 'kintoneへの接続に失敗しました。',
                'error'
            );
        }
    }


    /*
     * kintone接続状態表示
     */
    function setKintoneStatus(
        success,
        message
    ) {
        const dot =
            getElement(
                'kintone-status-dot'
            );


        const text =
            getElement(
                'kintone-status-text'
            );


        if (dot) {
            dot.classList.toggle(
                'success',
                Boolean(success)
            );

            dot.classList.toggle(
                'error',
                !success
            );
        }


        if (text) {
            text.textContent =
                String(
                    message ||
                    (
                        success
                            ? '接続成功'
                            : '接続失敗'
                    )
                );
        }
    }


    /*
     * メール設定保存
     */
    async function saveMailSettings(
        button
    ) {
        const settings =
            collectMailSettings();


        try {
            await apiRequest(
                'save_mail_settings',
                'POST',
                {
                    settings: settings
                },
                button
            );


            showToast(
                'メール設定を保存しました。'
            );


            setInputValue(
                'mail-password',
                ''
            );


            await loadSettings();

        } catch (error) {
            showNotice(
                'settings-notice',
                error instanceof Error
                    ? error.message
                    : 'メール設定を保存できませんでした。',
                'error'
            );
        }
    }


    /*
     * メール設定確認
     */
    async function testMail(
        button
    ) {
        const settings =
            collectMailSettings();


        try {
            await apiRequest(
                'test_mail',
                'POST',
                {
                    settings: settings
                },
                button
            );


            showToast(
                'メール設定を確認しました。'
            );

        } catch (error) {
            showNotice(
                'settings-notice',
                error instanceof Error
                    ? error.message
                    : 'メール設定の確認に失敗しました。',
                'error'
            );
        }
    }


    /*
     * 回答依頼送信
     */
    async function sendSurveyRequest(
        button
    ) {
        if (
            String(
                selectedSurveyId || ''
            ) === ''
        ) {
            return;
        }


        const subject =
            getInputValue(
                'send-subject'
            );


        const body =
            getInputValue(
                'send-body'
            );


        if (subject === '') {
            showNotice(
                'detail-notice',
                'メール件名を入力してください。',
                'error'
            );

            return;
        }


        if (body === '') {
            showNotice(
                'detail-notice',
                'メール本文を入力してください。',
                'error'
            );

            return;
        }


        const confirmed =
            await openConfirmModal(
                '回答依頼の送信',
                '対象者へ回答依頼メールを送信します。よろしいですか？'
            );


        if (!confirmed) {
            return;
        }


        try {
            const result =
                await apiRequest(
                    'send_survey',
                    'POST',
                    {
                        id:
                            selectedSurveyId,

                        subject:
                            subject,

                        body:
                            body
                    },
                    button
                );


            const sentCount =
                result &&
                result.data &&
                Number.isFinite(
                    Number(
                        result.data.sent
                    )
                )
                    ? Number(
                        result.data.sent
                    )
                    : 0;


            showToast(
                '回答依頼を送信しました。送信数：' +
                String(sentCount) +
                '件'
            );


            await openSurveyDetail(
                selectedSurveyId
            );

        } catch (error) {
            showNotice(
                'detail-notice',
                error instanceof Error
                    ? error.message
                    : '回答依頼を送信できませんでした。',
                'error'
            );
        }
    }


    /*
     * モーダル
     */
    function openConfirmModal(
        title,
        message
    ) {
        return new Promise(
            function (resolve) {

                const modal =
                    getElement('modal');

                const modalTitle =
                    getElement('modal-title');

                const modalBody =
                    getElement('modal-body');


                if (
                    !modal ||
                    !modalTitle ||
                    !modalBody
                ) {
                    resolve(false);
                    return;
                }


                modalTitle.textContent =
                    String(title || '確認');


                modalBody.textContent =
                    String(message || '');


                modal.classList.remove(
                    'hidden'
                );


                modalResolve =
                    resolve;
            }
        );
    }


    /*
     * モーダル終了
     */
    function closeModal(
        result
    ) {
        const modal =
            getElement('modal');


        if (modal) {
            modal.classList.add(
                'hidden'
            );
        }


        if (
            typeof modalResolve ===
            'function'
        ) {
            const resolve =
                modalResolve;

            modalResolve = null;

            resolve(
                Boolean(result)
            );
        }
    }


    /*
     * 設定タブ切り替え
     */
    function showSettingsTab(
        tabName
    ) {
        const kintonePanel =
            getElement(
                'settings-panel-kintone'
            );

        const mailPanel =
            getElement(
                'settings-panel-mail'
            );

        const kintoneTab =
            getElement(
                'settings-tab-kintone'
            );

        const mailTab =
            getElement(
                'settings-tab-mail'
            );


        if (kintonePanel) {
            kintonePanel.classList.toggle(
                'hidden',
                tabName !== 'kintone'
            );
        }


        if (mailPanel) {
            mailPanel.classList.toggle(
                'hidden',
                tabName !== 'mail'
            );
        }


        if (kintoneTab) {
            kintoneTab.classList.toggle(
                'active',
                tabName === 'kintone'
            );
        }


        if (mailTab) {
            mailTab.classList.toggle(
                'active',
                tabName === 'mail'
            );
        }
    }


    /*
     * ナビゲーションイベント
     */
    const navList =
        getElement('nav-list');

    if (navList) {
        navList.addEventListener(
            'click',
            function () {
                showPage('list');
                loadSurveys();
            }
        );
    }


    const navCreate =
        getElement('nav-create');

    if (navCreate) {
        navCreate.addEventListener(
            'click',
            function () {
                openNewSurveyEditor();
            }
        );
    }


    const navCustomers =
        getElement('nav-customers');

    if (navCustomers) {
        navCustomers.addEventListener(
            'click',
            function () {
                showPage('customers');
                loadCustomers();
            }
        );
    }


    const navSettings =
        getElement('nav-settings');

    if (navSettings) {
        navSettings.addEventListener(
            'click',
            function () {
                showPage('settings');
                loadSettings();
            }
        );
    }


    /*
     * 一覧から新規作成
     */
    const openCreate =
        getElement('btn-open-create');

    if (openCreate) {
        openCreate.addEventListener(
            'click',
            function () {
                openNewSurveyEditor();
            }
        );
    }


    /*
     * グループ追加
     */
    const addGroupButton =
        getElement('btn-add-group');

    if (addGroupButton) {
        addGroupButton.addEventListener(
            'click',
            function () {
                addGroup();
            }
        );
    }


    /*
     * 編集画面から一覧へ戻る
     */
    const editorBack =
        getElement('btn-editor-back');

    if (editorBack) {
        editorBack.addEventListener(
            'click',
            function () {
                showPage('list');
                loadSurveys();
            }
        );
    }


    /*
     * アンケート保存
     */
    const saveSurveyButton =
        getElement(
            'btn-save-survey'
        );

    if (saveSurveyButton) {
        saveSurveyButton.addEventListener(
            'click',
            function () {

                saveSurvey(
                    saveSurveyButton
                );
            }
        );
    }


    /*
     * 詳細から一覧へ
     */
    const detailBack =
        getElement(
            'btn-detail-back'
        );

    if (detailBack) {
        detailBack.addEventListener(
            'click',
            function () {
                showPage('list');
                loadSurveys();
            }
        );
    }


    /*
     * 詳細から編集
     */
    const detailEdit =
        getElement(
            'btn-detail-edit'
        );

    if (detailEdit) {
        detailEdit.addEventListener(
            'click',
            function () {

                if (
                    String(
                        selectedSurveyId || ''
                    ) === ''
                ) {
                    return;
                }

                openSurveyEditor(
                    selectedSurveyId
                );
            }
        );
    }


    /*
     * 回答結果
     */
    const showResults =
        getElement(
            'btn-show-results'
        );

    if (showResults) {
        showResults.addEventListener(
            'click',
            function () {

                loadSurveyResults(
                    showResults
                );
            }
        );
    }


    /*
     * 顧客更新
     */
    const refreshCustomers =
        getElement(
            'btn-refresh-customers'
        );

    if (refreshCustomers) {
        refreshCustomers.addEventListener(
            'click',
            function () {

                loadCustomers(
                    refreshCustomers
                );
            }
        );
    }


    /*
     * kintone接続テスト
     */
    const testKintoneButton =
        getElement(
            'btn-test-kintone'
        );

    if (testKintoneButton) {
        testKintoneButton.addEventListener(
            'click',
            function () {

                testKintone(
                    testKintoneButton
                );
            }
        );
    }


    /*
     * kintone設定保存
     */
    const saveKintoneButton =
        getElement(
            'btn-save-kintone'
        );

    if (saveKintoneButton) {
        saveKintoneButton.addEventListener(
            'click',
            function () {

                saveKintoneSettings(
                    saveKintoneButton
                );
            }
        );
    }


    /*
     * メール設定確認
     */
    const testMailButton =
        getElement(
            'btn-test-mail'
        );

    if (testMailButton) {
        testMailButton.addEventListener(
            'click',
            function () {

                testMail(
                    testMailButton
                );
            }
        );
    }


    /*
     * メール設定保存
     */
    const saveMailButton =
        getElement(
            'btn-save-mail'
        );

    if (saveMailButton) {
        saveMailButton.addEventListener(
            'click',
            function () {

                saveMailSettings(
                    saveMailButton
                );
            }
        );
    }


    /*
     * 回答依頼送信
     */
    const sendSurveyButton =
        getElement(
            'btn-send-survey'
        );

    if (sendSurveyButton) {
        sendSurveyButton.addEventListener(
            'click',
            function () {

                sendSurveyRequest(
                    sendSurveyButton
                );
            }
        );
    }


    /*
     * 設定タブ
     */
    const kintoneTab =
        getElement(
            'settings-tab-kintone'
        );

    if (kintoneTab) {
        kintoneTab.addEventListener(
            'click',
            function () {
                showSettingsTab(
                    'kintone'
                );
            }
        );
    }


    const mailTab =
        getElement(
            'settings-tab-mail'
        );

    if (mailTab) {
        mailTab.addEventListener(
            'click',
            function () {
                showSettingsTab(
                    'mail'
                );
            }
        );
    }


    /*
     * モーダル閉じる
     */
    const modalClose =
        getElement(
            'modal-close'
        );

    if (modalClose) {
        modalClose.addEventListener(
            'click',
            function () {
                closeModal(false);
            }
        );
    }


    const modalCancel =
        getElement(
            'modal-cancel'
        );

    if (modalCancel) {
        modalCancel.addEventListener(
            'click',
            function () {
                closeModal(false);
            }
        );
    }


    const modalOk =
        getElement(
            'modal-ok'
        );

    if (modalOk) {
        modalOk.addEventListener(
            'click',
            function () {
                closeModal(true);
            }
        );
    }


    /*
     * モーダル背景クリック
     */
    const modal =
        getElement('modal');

    if (modal) {
        modal.addEventListener(
            'click',
            function (event) {

                if (
                    event.target === modal
                ) {
                    closeModal(false);
                }
            }
        );
    }


    /*
     * Escapeキー
     */
    document.addEventListener(
        'keydown',
        function (event) {

            if (
                event.key === 'Escape'
            ) {
                const modal =
                    getElement('modal');

                if (
                    modal &&
                    !modal.classList.contains(
                        'hidden'
                    )
                ) {
                    closeModal(false);
                }
            }
        }
    );


    /*
     * 初期表示
     */
    showPage('list');

    showSettingsTab(
        'kintone'
    );

    loadSurveys();
});
</script>

</body>
</html>

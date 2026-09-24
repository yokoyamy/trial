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
 * CSRFトークン取得
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

    header(
        'Content-Type: application/json; charset=utf-8'
    );

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
 * HTTPメソッド
 */
function request_method(): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    return strtoupper(
        is_string($method) ? $method : 'GET'
    );
}

/**
 * 処理名取得
 */
function requested_action(): string
{
    $action = $_GET['action'] ?? '';

    return is_string($action)
        ? trim($action)
        : '';
}

/**
 * JSONリクエスト取得
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

    $data = json_decode(
        $raw,
        true
    );

    if (!is_array($data)) {
        json_response(
            false,
            '入力内容を確認してください。',
            [],
            [
                'request' =>
                    'JSON形式が正しくありません。'
            ],
            400
        );
    }

    return $data;
}

/**
 * CSRF検証
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

    if (
        !hash_equals(
            csrf_token(),
            $token
        )
    ) {
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
 * データ保存領域作成
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
 * 初期設定
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
            'connection_status' =>
                'not_configured',
            'tested_at' => null
        ]
    ];
}

/**
 * 初期顧客データ
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
 * 初期アンケートデータ
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
 * 初期回答データ
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
 * 初期メールログ
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
 * JSON読み込み
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

    $data = json_decode(
        $contents,
        true
    );

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
 * JSON保存
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

    $tmp = tempnam(
        DATA_DIRECTORY,
        'newapp_'
    );

    if ($tmp === false) {
        json_response(
            false,
            'データを保存できません。',
            [],
            [],
            500
        );
    }

    $fp = fopen(
        $tmp,
        'wb'
    );

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
            throw new \RuntimeException(
                'lock'
            );
        }

        $length = strlen($json);
        $written = fwrite(
            $fp,
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

        fflush($fp);
        flock(
            $fp,
            LOCK_UN
        );
        fclose($fp);

        if (!rename($tmp, $file)) {
            throw new \RuntimeException(
                'rename'
            );
        }
    } catch (\Throwable $e) {
        if (is_resource($fp)) {
            @flock(
                $fp,
                LOCK_UN
            );
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
 * データファイル初期化
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

/**
 * 各データ取得
 */
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
 * 現在時刻
 */
function now(): string
{
    return date('c');
}

/**
 * ID生成
 */
function generate_id(
    string $prefix
): string {
    return $prefix .
        '_' .
        date('YmdHis') .
        '_' .
        bin2hex(
            random_bytes(4)
        );
}

/**
 * 同一PHPファイル自身をAPIとして利用する。
 *
 * 絶対URLを作らないことが重要。
 * これによりホスト名をJavaScript側へ固定せず、
 * 現在表示しているindex.phpへ相対的に通信する。
 */
function application_api_path(): string
{
    $script =
        $_SERVER['SCRIPT_NAME'] ?? '';

    if (
        !is_string($script) ||
        $script === ''
    ) {
        return './index.php';
    }

    /*
     * SCRIPT_NAMEは現在のindex.phpそのもの。
     * URL生成時にホスト名を付けない。
     */
    return $script;
}

/* =========================================================
 * kintone
 * ========================================================= */

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
 * Cybozu認証ヘッダー
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
 * PHP 8.4/8.5対応
 * HTTPレスポンスヘッダー取得
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
 * HTTPステータス取得
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
 * kintone API通信
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

    $options = [
        'method' => $method,
        'header' =>
            implode(
                "\r\n",
                $headers
            ),
        'ignore_errors' => true,
        'timeout' => 20
    ];

    /*
     * GETにはcontentを設定しない。
     */
    if (
        $method !== 'GET' &&
        $payload !== []
    ) {
        try {
            $options['content'] =
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

        $hasContentType = false;

        foreach ($headers as $header) {
            if (
                is_string($header) &&
                stripos(
                    $header,
                    'Content-Type:'
                ) === 0
            ) {
                $hasContentType = true;
                break;
            }
        }

        if (!$hasContentType) {
            $options['header'] =
                implode(
                    "\r\n",
                    array_merge(
                        $headers,
                        [
                            'Content-Type: application/json'
                        ]
                    )
                );
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
     * プロキシ設定。
     *
     * host / port のどちらも受け取れるようにし、
     * 設定されている場合はstream contextへ適用する。
     */
    $proxyHost = trim(
        (string)(
            $config['proxy_host'] ?? ''
        )
    );

    $proxyPort = trim(
        (string)(
            $config['proxy_port'] ?? ''
        )
    );

    if ($proxyHost !== '') {
        $proxy = $proxyHost;

        if ($proxyPort !== '') {
            $proxy .= ':' . $proxyPort;
        }

        $contextOptions['http']['proxy'] =
            'tcp://' . $proxy;

        $contextOptions['http']['request_fulluri'] =
            true;
    } else {
        $contextOptions['http']['request_fulluri'] =
            false;
    }

    $context =
        stream_context_create(
            $contextOptions
        );

    $response =
        @file_get_contents(
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
            $decoded =
                $decodedValue;
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
        is_string(
            $decoded['message']
        ) &&
        $decoded['message'] !== ''
    ) {
        $message =
            $decoded['message'];
    }

    $errors = [];

    if (
        isset($decoded['errors']) &&
        is_array(
            $decoded['errors']
        )
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
                $error['messages'] ?? [];

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
        'errors' => $errors,
        'data' => $decoded
    ];
}

/**
 * kintone接続テスト
 */
function test_kintone(
    array $settings
): array {
    $domain =
        trim(
            (string)(
                $settings['domain'] ?? ''
            )
        );

    $login =
        trim(
            (string)(
                $settings['login_name'] ?? ''
            )
        );

    $password =
        (string)(
            $settings['password'] ?? ''
        );

    $appId =
        trim(
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
            'message' =>
                'kintoneのドメイン、ログイン名、パスワードを入力してください。'
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
        $url =
            kintone_build_url(
                $domain,
                '/k/v1/app.json?id=' .
                rawurlencode($appId)
            );
    } else {
        $url =
            kintone_build_url(
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
 * kintone顧客取得
 */
function fetch_kintone_customers(
    array $settings
): array {
    $domain =
        trim(
            (string)(
                $settings['domain'] ?? ''
            )
        );

    $login =
        trim(
            (string)(
                $settings['login_name'] ?? ''
            )
        );

    $password =
        (string)(
            $settings['password'] ?? ''
        );

    $appId =
        trim(
            (string)(
                $settings['customer_app_id'] ?? ''
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
            'message' =>
                'kintone設定が不足しています。'
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
            '/k/v1/records.json?' .
            $query
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

    if (
        !($result['success'] ?? false)
    ) {
        return $result;
    }

    $records =
        $result['data']['records'] ?? [];

    if (!is_array($records)) {
        $records = [];
    }

    $idField =
        trim(
            (string)(
                $settings['customer_id_field'] ?? ''
            )
        );

    $nameField =
        trim(
            (string)(
                $settings['customer_name_field'] ?? ''
            )
        );

    $emailField =
        trim(
            (string)(
                $settings['customer_email_field'] ?? ''
            )
        );

    $customers = [];

    foreach (
        $records as $record
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
 * アンケートデータ補助
 * ========================================================= */

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

    $numbering =
        (string)(
            $survey['numbering'] ??
            'global'
        );

    if (
        $numbering !== 'group'
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
        'groups' =>
            $groups
    ];
}

function calculate_survey_counts(
    array $survey,
    array $responses
): array {
    $surveyId =
        (string)(
            $survey['id'] ?? ''
        );

    $count = 0;

    foreach (
        $responses as $response
    ) {
        if (
            is_array($response) &&
            (string)(
                $response['survey_id'] ?? ''
            ) === $surveyId
        ) {
            $count++;
        }
    }

    return [
        'answers' => $count,
        'target' =>
            (int)(
                $survey['target'] ?? 0
            )
    ];
}

/* =========================================================
 * API処理
 * ========================================================= */

initialize_data_files();

$action = requested_action();

if ($action !== '') {

    if (
        request_method() === 'POST'
    ) {
        validate_csrf();
    }

    switch ($action) {

        /*
         * 新しい画面側の処理名
         */
        case 'get_settings':
        case 'load_settings':

            $settings =
                load_settings();

            /*
             * パスワードは画面へ返さない。
             */
            if (
                isset($settings['mail']) &&
                is_array(
                    $settings['mail']
                )
            ) {
                $settings['mail']['password'] =
                    '';
            }

            if (
                isset($settings['kintone']) &&
                is_array(
                    $settings['kintone']
                )
            ) {
                $settings['kintone']['password'] =
                    '';
            }

            json_response(
                true,
                '設定を取得しました。',
                [
                    'settings' =>
                        $settings
                ]
            );

        /*
         * 新しい画面側の処理名と旧処理名を
         * 両方受け付ける。
         */
        case 'list_surveys':
        case 'load_surveys':

            $surveyData =
                load_surveys();

            $responseData =
                load_responses();

            $items = [];

            $storedSurveys =
                $surveyData['surveys'] ?? [];

            if (!is_array($storedSurveys)) {
                $storedSurveys = [];
            }

            $responses =
                $responseData['responses'] ?? [];

            if (!is_array($responses)) {
                $responses = [];
            }

            foreach (
                $storedSurveys as $survey
            ) {
                if (!is_array($survey)) {
                    continue;
                }

                $survey =
                    normalize_survey(
                        $survey
                    );

                $counts =
                    calculate_survey_counts(
                        $survey,
                        $responses
                    );

                $survey['answers'] =
                    $counts['answers'];

                $survey['target'] =
                    $counts['target'];

                $items[] =
                    $survey;
            }

            json_response(
                true,
                'アンケート一覧を取得しました。',
                [
                    'surveys' =>
                        $items
                ]
            );

        /*
         * アンケート1件取得
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
                    'survey' =>
                        normalize_survey(
                            $survey
                        )
                ]
            );

        /*
         * 顧客一覧
         */
        case 'list_customers':
        case 'load_customers':

            $data =
                load_customers();

            $customers =
                $data['customers'] ?? [];

            if (!is_array($customers)) {
                $customers = [];
            }

            json_response(
                true,
                '顧客一覧を取得しました。',
                [
                    'customers' =>
                        $customers
                ]
            );

        /*
         * kintoneから顧客更新
         */
        case 'refresh_customers':

            $settings =
                load_settings();

            $kintone =
                $settings['kintone'] ?? [];

            if (!is_array($kintone)) {
                $kintone = [];
            }

            $result =
                fetch_kintone_customers(
                    $kintone
                );

            if (
                !($result['success'] ?? false)
            ) {
                json_response(
                    false,
                    (string)(
                        $result['message'] ??
                        '顧客情報を取得できませんでした。'
                    ),
                    [],
                    $result['errors'] ?? [],
                    400
                );
            }

            $customers =
                $result['customers'] ?? [];

            if (!is_array($customers)) {
                $customers = [];
            }

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
         * kintone接続テスト
         */
        case 'test_kintone':

            $input =
                read_json_request();

            $result =
                test_kintone(
                    $input
                );

            if (
                !($result['success'] ?? false)
            ) {
                json_response(
                    false,
                    (string)(
                        $result['message'] ??
                        'kintoneへの接続に失敗しました。'
                    ),
                    [],
                    $result['errors'] ?? [],
                    400
                );
            }

            json_response(
                true,
                'kintoneへの接続に成功しました。',
                [
                    'status' =>
                        $result['status'] ?? 0
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

/*
 * ここから画面本体。
 *
 * API処理の場合は上記json_response()でexitするため、
 * 以下のHTMLは出力されない。
 */
$apiPath =
    application_api_path();

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1.0">
<meta name="csrf-token"
      content="<?= h(csrf_token()) ?>">
<meta name="api-path"
      content="<?= h($apiPath) ?>">

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

/* =========================
   全体
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

/* =========================
   ローディング
   ========================= */

.loading-spinner {
    display: none;

    width: 14px;
    height: 14px;

    margin-right: 6px;

    border:
        2px solid
        rgba(255,255,255,.45);

    border-top-color: #fff;
    border-radius: 50%;

    animation:
        spin .7s linear infinite;

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

/* =========================
   メッセージ
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

    border-bottom:
        1px solid #e6ebef;

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
   状態バッジ
   ========================= */

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
    color: #8a4141;
    background: #fbe5e5;
}

/* =========================
   フォーム
   ========================= */

.form-grid {
    display: grid;

    grid-template-columns:
        repeat(2, minmax(0, 1fr));

    gap: 18px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group.full {
    grid-column: 1 / -1;
}

.form-label {
    display: block;

    font-weight: bold;
    margin-bottom: 6px;
}

.form-help {
    color: #718096;
    font-size: 12px;
    margin-top: 5px;
}

input[type="text"],
input[type="email"],
input[type="password"],
input[type="number"],
input[type="date"],
input[type="datetime-local"],
textarea,
select {
    width: 100%;

    border:
        1px solid #cbd5e0;

    border-radius: 5px;

    padding: 9px 10px;

    background: #fff;
    color: #263238;

    outline: none;
}

input:focus,
textarea:focus,
select:focus {
    border-color: #2878c8;

    box-shadow:
        0 0 0 2px
        rgba(40,120,200,.12);
}

textarea {
    min-height: 100px;
    resize: vertical;
}

/* =========================
   グリッド
   ========================= */

.summary-grid {
    display: grid;

    grid-template-columns:
        repeat(4, minmax(0, 1fr));

    gap: 14px;

    margin-bottom: 18px;
}

.stat-card {
    background: #fff;

    border:
        1px solid #dfe5eb;

    border-radius: 7px;

    padding: 16px;
}

.stat-label {
    color: #718096;
    font-size: 12px;
}

.stat-value {
    font-size: 24px;
    font-weight: bold;

    margin-top: 3px;
}

/* =========================
   アンケート編集
   ========================= */

.editor-layout {
    display: grid;

    grid-template-columns:
        minmax(0, 1fr)
        280px;

    gap: 18px;
}

.group-card {
    border:
        1px solid #dfe5eb;

    border-radius: 7px;

    background: #fff;

    margin-bottom: 18px;
}

.group-head {
    display: flex;

    align-items: center;
    justify-content: space-between;

    gap: 10px;

    padding: 12px 14px;

    background: #f8fafc;

    border-bottom:
        1px solid #e6ebef;
}

.group-body {
    padding: 14px;
}

.question-card {
    border:
        1px solid #e2e8f0;

    border-radius: 6px;

    padding: 12px;

    margin-bottom: 12px;

    background: #fff;
}

.question-head {
    display: flex;

    align-items: center;

    gap: 10px;

    margin-bottom: 10px;
}

.question-number {
    flex: 0 0 auto;

    min-width: 30px;

    font-weight: bold;
    color: #2878c8;
}

.question-title {
    flex: 1;
}

.question-tools {
    display: flex;

    align-items: center;

    gap: 6px;
}

.option-row {
    display: grid;

    grid-template-columns:
        1fr 130px auto;

    gap: 8px;

    margin-top: 8px;
}

.editor-actions {
    display: flex;

    flex-wrap: wrap;

    gap: 8px;

    margin-top: 18px;
}

/* =========================
   詳細画面
   ========================= */

.detail-tabs {
    display: flex;

    gap: 2px;

    border-bottom:
        1px solid #dfe5eb;

    margin-bottom: 18px;
}

.detail-tabs button {
    border: 0;

    background: transparent;

    padding: 10px 15px;

    color: #718096;
}

.detail-tabs button.active {
    color: #2878c8;

    border-bottom:
        2px solid #2878c8;
}

.detail-summary {
    display: grid;

    grid-template-columns:
        repeat(4, minmax(0, 1fr));

    gap: 12px;

    margin-bottom: 18px;
}

.send-layout {
    display: grid;

    grid-template-columns:
        minmax(0, 1fr)
        minmax(300px, 400px);

    gap: 18px;
}

.email-preview {
    white-space: pre-wrap;

    border:
        1px solid #e2e8f0;

    border-radius: 6px;

    padding: 14px;

    background: #f8fafc;

    min-height: 220px;
}

.selection-summary {
    padding: 10px 0;

    color: #52606d;
    font-size: 13px;
}

/* =========================
   設定
   ========================= */

.settings-tabs {
    display: flex;

    gap: 2px;

    margin-bottom: 18px;

    border-bottom:
        1px solid #dfe5eb;
}

.settings-tabs button {
    border: 0;

    background: transparent;

    padding: 10px 15px;

    color: #718096;
}

.settings-tabs button.active {
    color: #2878c8;

    border-bottom:
        2px solid #2878c8;
}

/* =========================
   プログレス
   ========================= */

.progress {
    height: 14px;

    background: #edf2f7;

    border-radius: 8px;

    overflow: hidden;
}

.progress span {
    display: block;

    height: 100%;

    background: #2878c8;

    border-radius: 8px;
}

/* =========================
   モーダル
   ========================= */

.modal-backdrop {
    position: fixed;

    inset: 0;

    z-index: 1000;

    background:
        rgba(20,30,40,.45);

    display: flex;

    align-items: center;
    justify-content: center;

    padding: 20px;
}

.modal {
    width: min(
        900px,
        100%
    );

    max-height: 90vh;

    background: #fff;

    border-radius: 8px;

    display: flex;

    flex-direction: column;

    box-shadow:
        0 20px 50px
        rgba(0,0,0,.2);
}

.modal-header {
    display: flex;

    align-items: center;
    justify-content: space-between;

    gap: 12px;

    padding: 14px 18px;

    border-bottom:
        1px solid #e6ebef;
}

.modal-body {
    padding: 18px;

    overflow: auto;
}

.modal-footer {
    display: flex;

    justify-content: flex-end;

    gap: 8px;

    padding: 14px 18px;

    border-top:
        1px solid #e6ebef;
}

/* =========================
   トースト
   ========================= */

.toast {
    position: fixed;

    right: 20px;
    bottom: 20px;

    z-index: 2000;

    min-width: 240px;

    max-width: 420px;

    padding: 12px 16px;

    border-radius: 6px;

    background: #263238;
    color: #fff;

    box-shadow:
        0 8px 24px
        rgba(0,0,0,.2);

    opacity: 0;

    transform:
        translateY(10px);

    pointer-events: none;

    transition:
        opacity .2s ease,
        transform .2s ease;
}

.toast.show {
    opacity: 1;

    transform:
        translateY(0);
}

/* =========================
   回答結果
   ========================= */

.result-layout {
    display: grid;

    grid-template-columns:
        280px
        minmax(0, 1fr);

    gap: 18px;
}

.result-menu button {
    display: block;

    width: 100%;

    text-align: left;

    border: 0;

    background: transparent;

    padding: 9px 10px;

    border-radius: 5px;

    color: #52606d;
}

.result-menu button:hover,
.result-menu button.active {
    background: #edf5fc;

    color: #2878c8;
}

/* =========================
   レスポンシブ
   ========================= */

@media (max-width: 1000px) {

    .summary-grid,
    .detail-summary {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

    .editor-layout,
    .send-layout,
    .result-layout {
        grid-template-columns:
            1fr;
    }

    .form-grid {
        grid-template-columns:
            1fr;
    }

    .form-group.full {
        grid-column: auto;
    }

    .topbar {
        padding: 0 12px;
        gap: 12px;
    }

    .main-nav {
        overflow-x: auto;
    }

    .main-nav button {
        padding: 0 10px;
    }
}

@media (max-width: 600px) {

    .app {
        padding: 12px;
    }

    .page-header {
        align-items: flex-start;

        flex-direction: column;
    }

    .summary-grid,
    .detail-summary {
        grid-template-columns:
            1fr;
    }

    .option-row {
        grid-template-columns:
            1fr;
    }

    .question-head {
        align-items: flex-start;

        flex-direction: column;
    }
}
</style>
</head>
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
            const groupCard = document.createElement('div');
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
                    groupCard.classList.remove('drag-over');
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
                    const questionCard =
                        document.createElement('div');

                    questionCard.className =
                        'question-card';

                    questionCard.draggable = true;

                    questionCard.dataset.questionId =
                        String(question.id || '');

                    questionCard.addEventListener(
                        'dragstart',
                        function () {
                            draggedQuestionId =
                                String(
                                    question.id || ''
                                );

                            questionCard.classList.add(
                                'dragging'
                            );
                        }
                    );

                    questionCard.addEventListener(
                        'dragend',
                        function () {
                            draggedQuestionId = '';

                            questionCard.classList.remove(
                                'dragging'
                            );
                        }
                    );

                    questionCard.addEventListener(
                        'dragover',
                        function (event) {
                            event.preventDefault();

                            questionCard.classList.add(
                                'drag-over'
                            );
                        }
                    );

                    questionCard.addEventListener(
                        'dragleave',
                        function () {
                            questionCard.classList.remove(
                                'drag-over'
                            );
                        }
                    );

                    questionCard.addEventListener(
                        'drop',
                        function (event) {
                            event.preventDefault();

                            questionCard.classList.remove(
                                'drag-over'
                            );

                            if (
                                draggedQuestionId &&
                                draggedQuestionId !==
                                    String(
                                        question.id || ''
                                    )
                            ) {
                                moveQuestion(
                                    draggedQuestionId,
                                    String(
                                        question.id || ''
                                    )
                                );
                            }
                        }
                    );

                    const questionHead =
                        document.createElement('div');

                    questionHead.className =
                        'question-head';

                    const questionTitle =
                        document.createElement('div');

                    questionTitle.className =
                        'question-title';

                    const questionHandle =
                        document.createElement('span');

                    questionHandle.className =
                        'drag-handle';

                    questionHandle.textContent =
                        '☷';

                    const questionNumberElement =
                        document.createElement('span');

                    questionNumberElement.className =
                        'question-number';

                    questionNumberElement.textContent =
                        questionNumber(
                            groupIndex,
                            questionIndex
                        );

                    questionTitle.appendChild(
                        questionHandle
                    );

                    questionTitle.appendChild(
                        questionNumberElement
                    );

                    const questionMeta =
                        document.createElement('span');

                    questionMeta.className =
                        'question-meta';

                    questionMeta.textContent =
                        question.type === 'single'
                            ? '単一選択'
                            : question.type ===
                              'multiple'
                                ? '複数選択'
                                : '自由記述';

                    questionTitle.appendChild(
                        questionMeta
                    );

                    const questionActions =
                        document.createElement('div');

                    questionActions.className =
                        'question-actions';

                    const deleteQuestionButton =
                        document.createElement(
                            'button'
                        );

                    deleteQuestionButton.type =
                        'button';

                    deleteQuestionButton.className =
                        'btn btn-small btn-danger';

                    deleteQuestionButton.textContent =
                        '質問削除';

                    deleteQuestionButton.addEventListener(
                        'click',
                        function () {
                            deleteQuestion(
                                String(
                                    question.id || ''
                                )
                            );
                        }
                    );

                    questionActions.appendChild(
                        deleteQuestionButton
                    );

                    questionHead.appendChild(
                        questionTitle
                    );

                    questionHead.appendChild(
                        questionActions
                    );

                    questionCard.appendChild(
                        questionHead
                    );

                    const questionBody =
                        document.createElement('div');

                    questionBody.className =
                        'question-body';

                    const questionTextField =
                        document.createElement('div');

                    questionTextField.className =
                        'field';

                    const questionLabel =
                        document.createElement('label');

                    questionLabel.textContent =
                        '質問文';

                    const questionInput =
                        document.createElement(
                            'textarea'
                        );

                    questionInput.value =
                        String(
                            question.text || ''
                        );

                    questionInput.rows = 2;

                    questionInput.placeholder =
                        '質問文を入力してください';

                    questionInput.addEventListener(
                        'input',
                        function () {
                            question.text =
                                questionInput.value;
                        }
                    );

                    questionTextField.appendChild(
                        questionLabel
                    );

                    questionTextField.appendChild(
                        questionInput
                    );

                    questionBody.appendChild(
                        questionTextField
                    );

                    const questionSettings =
                        document.createElement('div');

                    questionSettings.className =
                        'form-grid';

                    const typeField =
                        document.createElement('div');

                    typeField.className = 'field';

                    const typeLabel =
                        document.createElement('label');

                    typeLabel.textContent =
                        '回答形式';

                    const typeSelect =
                        document.createElement('select');

                    const typeOptions = [
                        {
                            value: 'free',
                            text: '自由記述'
                        },
                        {
                            value: 'single',
                            text: '単一選択'
                        },
                        {
                            value: 'multiple',
                            text: '複数選択'
                        }
                    ];

                    typeOptions.forEach(
                        function (typeOption) {
                            const option =
                                document.createElement(
                                    'option'
                                );

                            option.value =
                                typeOption.value;

                            option.textContent =
                                typeOption.text;

                            typeSelect.appendChild(
                                option
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
                                typeSelect.value;

                            if (
                                question.type ===
                                'free'
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

                    questionSettings.appendChild(
                        typeField
                    );

                    const requiredField =
                        document.createElement('div');

                    requiredField.className =
                        'field';

                    const requiredLabel =
                        document.createElement('label');

                    requiredLabel.textContent =
                        '必須回答';

                    const requiredWrap =
                        document.createElement(
                            'label'
                        );

                    requiredWrap.className =
                        'checkbox-label';

                    const requiredInput =
                        document.createElement(
                            'input'
                        );

                    requiredInput.type =
                        'checkbox';

                    requiredInput.checked =
                        Boolean(question.required);

                    requiredInput.addEventListener(
                        'change',
                        function () {
                            question.required =
                                requiredInput.checked;
                        }
                    );

                    requiredWrap.appendChild(
                        requiredInput
                    );

                    requiredWrap.appendChild(
                        document.createTextNode(
                            ' 回答を必須にする'
                        )
                    );

                    requiredField.appendChild(
                        requiredLabel
                    );

                    requiredField.appendChild(
                        requiredWrap
                    );

                    questionSettings.appendChild(
                        requiredField
                    );

                    questionBody.appendChild(
                        questionSettings
                    );

                    if (
                        question.type === 'single' ||
                        question.type === 'multiple'
                    ) {
                        const optionSection =
                            document.createElement(
                                'div'
                            );

                        optionSection.className =
                            'question-options';

                        const optionTitle =
                            document.createElement(
                                'div'
                            );

                        optionTitle.className =
                            'field-label';

                        optionTitle.textContent =
                            '選択肢';

                        optionSection.appendChild(
                            optionTitle
                        );

                        const optionList =
                            document.createElement(
                                'div'
                            );

                        optionList.className =
                            'option-list';

                        const options =
                            Array.isArray(
                                question.options
                            )
                                ? question.options
                                : [];

                        options.forEach(
                            function (
                                option,
                                optionIndex
                            ) {
                                const optionRow =
                                    document.createElement(
                                        'div'
                                    );

                                optionRow.className =
                                    'option-row';

                                const optionInput =
                                    document.createElement(
                                        'input'
                                    );

                                optionInput.type =
                                    'text';

                                optionInput.value =
                                    String(
                                        option.text ||
                                            ''
                                    );

                                optionInput.placeholder =
                                    '選択肢';

                                optionInput.addEventListener(
                                    'input',
                                    function () {
                                        option.text =
                                            optionInput.value;
                                    }
                                );

                                optionRow.appendChild(
                                    optionInput
                                );

                                const branchSelect =
                                    document.createElement(
                                        'select'
                                    );

                                const noBranchOption =
                                    document.createElement(
                                        'option'
                                    );

                                noBranchOption.value =
                                    '';

                                noBranchOption.textContent =
                                    '分岐なし';

                                branchSelect.appendChild(
                                    noBranchOption
                                );

                                groups.forEach(
                                    function (
                                        branchGroup
                                    ) {
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
                                            '→ ' +
                                            String(
                                                branchGroup.name ||
                                                    'グループ'
                                            );

                                        branchSelect.appendChild(
                                            branchOption
                                        );
                                    }
                                );

                                branchSelect.value =
                                    String(
                                        option.branch ||
                                            ''
                                    );

                                branchSelect.addEventListener(
                                    'change',
                                    function () {
                                        option.branch =
                                            branchSelect.value;
                                    }
                                );

                                optionRow.appendChild(
                                    branchSelect
                                );

                                const removeOptionButton =
                                    document.createElement(
                                        'button'
                                    );

                                removeOptionButton.type =
                                    'button';

                                removeOptionButton.className =
                                    'btn btn-small btn-danger';

                                removeOptionButton.textContent =
                                    '削除';

                                removeOptionButton.addEventListener(
                                    'click',
                                    function () {
                                        question.options.splice(
                                            optionIndex,
                                            1
                                        );

                                        renderEditor();
                                    }
                                );

                                optionRow.appendChild(
                                    removeOptionButton
                                );

                                optionList.appendChild(
                                    optionRow
                                );
                            }
                        );

                        optionSection.appendChild(
                            optionList
                        );

                        const addOptionButton =
                            document.createElement(
                                'button'
                            );

                        addOptionButton.type =
                            'button';

                        addOptionButton.className =
                            'btn btn-small';

                        addOptionButton.textContent =
                            '＋ 選択肢を追加';

                        addOptionButton.addEventListener(
                            'click',
                            function () {
                                if (
                                    !Array.isArray(
                                        question.options
                                    )
                                ) {
                                    question.options =
                                        [];
                                }

                                question.options.push(
                                    {
                                        text: '',
                                        branch: ''
                                    }
                                );

                                renderEditor();
                            }
                        );

                        optionSection.appendChild(
                            addOptionButton
                        );

                        questionBody.appendChild(
                            optionSection
                        );
                    }

                    questionCard.appendChild(
                        questionBody
                    );

                    questions.appendChild(
                        questionCard
                    );
                }
            );

            if (questionList.length === 0) {
                const emptyQuestions =
                    document.createElement('div');

                emptyQuestions.className =
                    'empty-question';

                emptyQuestions.textContent =
                    '質問がありません。';

                questions.appendChild(
                    emptyQuestions
                );
            }

            const groupFooter =
                document.createElement('div');

            groupFooter.className =
                'group-footer';

            const addQuestionButton =
                document.createElement('button');

            addQuestionButton.type = 'button';
            addQuestionButton.className = 'btn';

            addQuestionButton.textContent =
                '＋ 質問を追加';

            addQuestionButton.addEventListener(
                'click',
                function () {
                    if (
                        !Array.isArray(
                            group.questions
                        )
                    ) {
                        group.questions = [];
                    }

                    group.questions.push(
                        createQuestion()
                    );

                    renderEditor();
                }
            );

            groupFooter.appendChild(
                addQuestionButton
            );

            groupCard.appendChild(
                questions
            );

            groupCard.appendChild(
                groupFooter
            );

            groupsElement.appendChild(
                groupCard
            );
        });

        const addGroupArea =
            document.createElement('div');

        addGroupArea.className =
            'add-group-area';

        const addGroupButton =
            document.createElement('button');

        addGroupButton.type = 'button';
        addGroupButton.className =
            'btn btn-primary';

        addGroupButton.textContent =
            '＋ グループを追加';

        addGroupButton.addEventListener(
            'click',
            function () {
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
        );

        addGroupArea.appendChild(
            addGroupButton
        );

        groupsElement.appendChild(
            addGroupArea
        );
    }

    function moveGroup(sourceId, targetId) {
        if (
            !editingSurvey ||
            !Array.isArray(editingSurvey.groups)
        ) {
            return;
        }

        const sourceIndex =
            editingSurvey.groups.findIndex(
                function (group) {
                    return String(group.id || '') ===
                        String(sourceId);
                }
            );

        const targetIndex =
            editingSurvey.groups.findIndex(
                function (group) {
                    return String(group.id || '') ===
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
        if (
            !editingSurvey ||
            !Array.isArray(editingSurvey.groups)
        ) {
            return;
        }

        let sourceGroup = null;
        let sourceIndex = -1;
        let targetGroup = null;
        let targetIndex = -1;

        editingSurvey.groups.forEach(
            function (group) {
                if (
                    !Array.isArray(
                        group.questions
                    )
                ) {
                    return;
                }

                group.questions.forEach(
                    function (
                        question,
                        index
                    ) {
                        const questionId =
                            String(
                                question.id || ''
                            );

                        if (
                            questionId ===
                            String(sourceId)
                        ) {
                            sourceGroup =
                                group;
                            sourceIndex =
                                index;
                        }

                        if (
                            questionId ===
                            String(targetId)
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
            sourceGroup === targetGroup &&
            sourceIndex < targetIndex
        ) {
            targetIndex--;
        }

        targetGroup.questions.splice(
            targetIndex,
            0,
            moved
        );

        renderEditor();
    }

    function deleteGroup(groupId) {
        if (
            !editingSurvey ||
            !Array.isArray(
                editingSurvey.groups
            )
        ) {
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
                'このグループを削除しますか？\n含まれる質問も削除されます。'
            )
        ) {
            return;
        }

        const index =
            editingSurvey.groups.findIndex(
                function (group) {
                    return String(
                        group.id || ''
                    ) === String(groupId);
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

    function deleteQuestion(questionId) {
        if (
            !editingSurvey ||
            !Array.isArray(
                editingSurvey.groups
            )
        ) {
            return;
        }

        let targetGroup = null;
        let targetIndex = -1;

        editingSurvey.groups.some(
            function (group) {
                if (
                    !Array.isArray(
                        group.questions
                    )
                ) {
                    return false;
                }

                const index =
                    group.questions.findIndex(
                        function (question) {
                            return String(
                                question.id || ''
                            ) === String(
                                questionId
                            );
                        }
                    );

                if (index >= 0) {
                    targetGroup = group;
                    targetIndex = index;
                    return true;
                }

                return false;
            }
        );

        if (
            !targetGroup ||
            targetIndex < 0
        ) {
            return;
        }

        if (
            !window.confirm(
                'この質問を削除しますか？'
            )
        ) {
            return;
        }

        targetGroup.questions.splice(
            targetIndex,
            1
        );

        renderEditor();
    }
    function createGroup() {
        return {
            id: createId('group'),
            name: '新しいグループ',
            questions: [
                createQuestion()
            ]
        };
    }

    function createQuestion() {
        return {
            id: createId('question'),
            text: '',
            type: 'free',
            required: false,
            options: []
        };
    }

    function createId(prefix) {
        const randomPart =
            Math.random()
                .toString(36)
                .substring(2, 10);

        return (
            String(prefix) +
            '_' +
            Date.now().toString(36) +
            '_' +
            randomPart
        );
    }

    function questionNumber(
        groupIndex,
        questionIndex
    ) {
        let number = 1;

        if (
            editingSurvey &&
            Array.isArray(editingSurvey.groups)
        ) {
            for (
                let i = 0;
                i < groupIndex;
                i++
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
        }

        number += questionIndex;

        return String(number);
    }

    function normalizeSurvey(survey) {
        const normalized =
            survey &&
            typeof survey === 'object'
                ? survey
                : {};

        if (
            typeof normalized.id !==
            'string'
        ) {
            normalized.id =
                createId('survey');
        }

        if (
            typeof normalized.title !==
            'string'
        ) {
            normalized.title = '';
        }

        if (
            typeof normalized.description !==
            'string'
        ) {
            normalized.description = '';
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
                    const normalizedGroup =
                        group &&
                        typeof group ===
                            'object'
                            ? group
                            : {};

                    if (
                        typeof normalizedGroup.id !==
                        'string'
                    ) {
                        normalizedGroup.id =
                            createId('group');
                    }

                    if (
                        typeof normalizedGroup.name !==
                        'string'
                    ) {
                        normalizedGroup.name =
                            'グループ';
                    }

                    if (
                        !Array.isArray(
                            normalizedGroup.questions
                        )
                    ) {
                        normalizedGroup.questions =
                            [];
                    }

                    normalizedGroup.questions =
                        normalizedGroup.questions.map(
                            function (
                                question
                            ) {
                                const normalizedQuestion =
                                    question &&
                                    typeof question ===
                                        'object'
                                        ? question
                                        : {};

                                if (
                                    typeof normalizedQuestion.id !==
                                    'string'
                                ) {
                                    normalizedQuestion.id =
                                        createId(
                                            'question'
                                        );
                                }

                                if (
                                    typeof normalizedQuestion.text !==
                                    'string'
                                ) {
                                    normalizedQuestion.text =
                                        '';
                                }

                                if (
                                    normalizedQuestion.type !==
                                        'single' &&
                                    normalizedQuestion.type !==
                                        'multiple' &&
                                    normalizedQuestion.type !==
                                        'free'
                                ) {
                                    normalizedQuestion.type =
                                        'free';
                                }

                                normalizedQuestion.required =
                                    Boolean(
                                        normalizedQuestion.required
                                    );

                                if (
                                    !Array.isArray(
                                        normalizedQuestion.options
                                    )
                                ) {
                                    normalizedQuestion.options =
                                        [];
                                }

                                normalizedQuestion.options =
                                    normalizedQuestion.options.map(
                                        function (
                                            option
                                        ) {
                                            const normalizedOption =
                                                option &&
                                                typeof option ===
                                                    'object'
                                                    ? option
                                                    : {};

                                            if (
                                                typeof normalizedOption.text !==
                                                'string'
                                            ) {
                                                normalizedOption.text =
                                                    '';
                                            }

                                            if (
                                                typeof normalizedOption.branch !==
                                                'string'
                                            ) {
                                                normalizedOption.branch =
                                                    '';
                                            }

                                            return normalizedOption;
                                        }
                                    );

                                return normalizedQuestion;
                            }
                        );

                    return normalizedGroup;
                }
            );

        return normalized;
    }

    function cloneSurvey(survey) {
        return JSON.parse(
            JSON.stringify(
                survey
            )
        );
    }

    function setValue(
        elementId,
        value
    ) {
        const element =
            $(elementId);

        if (!element) {
            return;
        }

        element.value =
            value === null ||
            value === undefined
                ? ''
                : String(value);
    }

    function getValue(elementId) {
        const element =
            $(elementId);

        if (!element) {
            return '';
        }

        return String(
            element.value || ''
        );
    }

    function setChecked(
        elementId,
        value
    ) {
        const element =
            $(elementId);

        if (!element) {
            return;
        }

        element.checked =
            Boolean(value);
    }

    function getChecked(elementId) {
        const element =
            $(elementId);

        if (!element) {
            return false;
        }

        return Boolean(
            element.checked
        );
    }

    function showElement(
        elementId
    ) {
        const element =
            $(elementId);

        if (!element) {
            return;
        }

        element.hidden = false;
    }

    function hideElement(
        elementId
    ) {
        const element =
            $(elementId);

        if (!element) {
            return;
        }

        element.hidden = true;
    }

    function switchScreen(
        screenName
    ) {
        const screens =
            document.querySelectorAll(
                '[data-screen]'
            );

        screens.forEach(
            function (screen) {
                const active =
                    screen.dataset.screen ===
                    screenName;

                screen.hidden =
                    !active;
            }
        );

        const navigation =
            document.querySelectorAll(
                '[data-nav]'
            );

        navigation.forEach(
            function (item) {
                const active =
                    item.dataset.nav ===
                    screenName;

                item.classList.toggle(
                    'active',
                    active
                );
            }
        );

        currentScreen =
            screenName;
    }

    function showToast(
        message,
        type
    ) {
        const container =
            $('toast-container');

        if (!container) {
            return;
        }

        const toast =
            document.createElement(
                'div'
            );

        toast.className =
            'toast';

        if (
            type === 'error'
        ) {
            toast.classList.add(
                'toast-error'
            );
        }

        if (
            type === 'success'
        ) {
            toast.classList.add(
                'toast-success'
            );
        }

        const text =
            document.createElement(
                'span'
            );

        text.textContent =
            String(
                message || ''
            );

        toast.appendChild(text);

        container.appendChild(
            toast
        );

        window.setTimeout(
            function () {
                toast.classList.add(
                    'toast-hide'
                );

                window.setTimeout(
                    function () {
                        if (
                            toast.parentNode
                        ) {
                            toast.parentNode.removeChild(
                                toast
                            );
                        }
                    },
                    250
                );
            },
            3500
        );
    }

    function showLoading(
        button
    ) {
        if (!button) {
            return;
        }

        button.disabled = true;

        button.classList.add(
            'is-loading'
        );

        button.setAttribute(
            'aria-busy',
            'true'
        );
    }

    function hideLoading(
        button
    ) {
        if (!button) {
            return;
        }

        button.disabled = false;

        button.classList.remove(
            'is-loading'
        );

        button.removeAttribute(
            'aria-busy'
        );
    }

    function setPageLoading(
        loading
    ) {
        const indicator =
            $('page-loading');

        if (!indicator) {
            return;
        }

        indicator.hidden =
            !loading;
    }

    function getCsrfToken() {
        const element =
            $('csrf-token');

        if (!element) {
            return '';
        }

        return String(
            element.value || ''
        );
    }

    async function apiRequest(
        action,
        options
    ) {
        const requestOptions =
            options &&
            typeof options === 'object'
                ? options
                : {};

        const method =
            String(
                requestOptions.method ||
                    'GET'
            ).toUpperCase();

        const body =
            requestOptions.body !==
            undefined
                ? requestOptions.body
                : null;

        const headers = {
            'Accept':
                'application/json'
        };

        if (
            method !== 'GET' &&
            method !== 'HEAD'
        ) {
            headers[
                'Content-Type'
            ] =
                'application/json';

            headers[
                'X-CSRF-Token'
            ] =
                getCsrfToken();
        }

        const url =
            new URL(
                window.location.href
            );

        url.search = '';

        url.searchParams.set(
            'action',
            action
        );

        const fetchOptions = {
            method: method,
            headers: headers,
            credentials: 'same-origin'
        };

        if (
            method !== 'GET' &&
            method !== 'HEAD' &&
            body !== null
        ) {
            fetchOptions.body =
                JSON.stringify(body);
        }

        let response;

        try {
            response =
                await fetch(
                    url.toString(),
                    fetchOptions
                );
        } catch (error) {
            throw new Error(
                'サーバーとの通信に失敗しました。'
            );
        }

        let data = null;

        try {
            data =
                await response.json();
        } catch (error) {
            throw new Error(
                'サーバーから正しい応答を受け取れませんでした。'
            );
        }

        if (
            !response.ok
        ) {
            const message =
                data &&
                typeof data.message ===
                    'string'
                    ? data.message
                    : 'サーバー処理でエラーが発生しました。';

            throw new Error(
                message
            );
        }

        if (
            data &&
            data.success === false
        ) {
            throw new Error(
                typeof data.message ===
                    'string'
                    ? data.message
                    : '処理に失敗しました。'
            );
        }

        return data;
    }

    async function loadSurveys() {
        const list =
            $('survey-list');

        if (!list) {
            return;
        }

        setPageLoading(true);

        try {
            const result =
                await apiRequest(
                    'list_surveys'
                );

            const surveys =
                Array.isArray(
                    result?.surveys
                )
                    ? result.surveys
                    : [];

            surveyList =
                surveys.map(
                    function (survey) {
                        return normalizeSurvey(
                            survey
                        );
                    }
                );

            renderSurveyList();
        } catch (error) {
            surveyList = [];

            renderSurveyList();

            showToast(
                error instanceof Error
                    ? error.message
                    : 'アンケート一覧を取得できませんでした。',
                'error'
            );
        } finally {
            setPageLoading(false);
        }
    }

    function renderSurveyList() {
        const list =
            $('survey-list');

        if (!list) {
            return;
        }

        list.textContent = '';

        if (
            surveyList.length === 0
        ) {
            const empty =
                document.createElement(
                    'div'
                );

            empty.className =
                'empty-state';

            empty.textContent =
                'アンケートがありません。';

            list.appendChild(
                empty
            );

            return;
        }

        surveyList.forEach(
            function (survey) {
                const item =
                    document.createElement(
                        'div'
                    );

                item.className =
                    'survey-item';

                const main =
                    document.createElement(
                        'div'
                    );

                main.className =
                    'survey-item-main';

                const title =
                    document.createElement(
                        'div'
                    );

                title.className =
                    'survey-item-title';

                title.textContent =
                    survey.title ||
                    '無題のアンケート';

                const description =
                    document.createElement(
                        'div'
                    );

                description.className =
                    'survey-item-description';

                description.textContent =
                    survey.description ||
                    '';

                main.appendChild(
                    title
                );

                if (
                    survey.description
                ) {
                    main.appendChild(
                        description
                    );
                }

                const actions =
                    document.createElement(
                        'div'
                    );

                actions.className =
                    'survey-item-actions';

                const editButton =
                    document.createElement(
                        'button'
                    );

                editButton.type =
                    'button';

                editButton.className =
                    'btn';

                editButton.textContent =
                    '編集';

                editButton.addEventListener(
                    'click',
                    function () {
                        openSurveyEditor(
                            survey.id
                        );
                    }
                );

                actions.appendChild(
                    editButton
                );

                const deleteButton =
                    document.createElement(
                        'button'
                    );

                deleteButton.type =
                    'button';

                deleteButton.className =
                    'btn btn-danger';

                deleteButton.textContent =
                    '削除';

                deleteButton.addEventListener(
                    'click',
                    function () {
                        deleteSurvey(
                            survey.id,
                            deleteButton
                        );
                    }
                );

                actions.appendChild(
                    deleteButton
                );

                item.appendChild(
                    main
                );

                item.appendChild(
                    actions
                );

                list.appendChild(
                    item
                );
            }
        );
    }

    function openSurveyEditor(
        surveyId
    ) {
        const survey =
            surveyList.find(
                function (item) {
                    return String(
                        item.id
                    ) === String(
                        surveyId
                    );
                }
            );

        if (!survey) {
            showToast(
                '対象のアンケートが見つかりません。',
                'error'
            );

            return;
        }

        editingSurvey =
            cloneSurvey(
                normalizeSurvey(
                    survey
                )
            );

        renderSurveyForm();

        renderEditor();

        switchScreen(
            'editor'
        );
    }

    function createNewSurvey() {
        editingSurvey =
            normalizeSurvey({
                id: createId('survey'),
                title: '',
                description: '',
                groups: [
                    createGroup()
                ]
            });

        renderSurveyForm();

        renderEditor();

        switchScreen(
            'editor'
        );
    }

    function renderSurveyForm() {
        if (!editingSurvey) {
            return;
        }

        setValue(
            'survey-title',
            editingSurvey.title
        );

        setValue(
            'survey-description',
            editingSurvey.description
        );

        const statusElement =
            $('survey-status');

        if (statusElement) {
            statusElement.textContent =
                editingSurvey.status ===
                'published'
                    ? '公開中'
                    : '下書き';
        }
    }

    function collectSurveyForm() {
        if (!editingSurvey) {
            return null;
        }

        editingSurvey.title =
            getValue(
                'survey-title'
            ).trim();

        editingSurvey.description =
            getValue(
                'survey-description'
            ).trim();

        return editingSurvey;
    }

    function validateSurvey(
        survey
    ) {
        const errors = [];

        if (
            !survey ||
            typeof survey !==
                'object'
        ) {
            errors.push(
                'アンケート情報がありません。'
            );

            return errors;
        }

        if (
            !String(
                survey.title || ''
            ).trim()
        ) {
            errors.push(
                'アンケート名を入力してください。'
            );
        }

        if (
            !Array.isArray(
                survey.groups
            ) ||
            survey.groups.length === 0
        ) {
            errors.push(
                'グループを1つ以上登録してください。'
            );

            return errors;
        }

        survey.groups.forEach(
            function (
                group,
                groupIndex
            ) {
                if (
                    !String(
                        group.name || ''
                    ).trim()
                ) {
                    errors.push(
                        'グループ' +
                            String(
                                groupIndex + 1
                            ) +
                            'の名前を入力してください。'
                    );
                }

                if (
                    !Array.isArray(
                        group.questions
                    ) ||
                    group.questions.length ===
                        0
                ) {
                    errors.push(
                        '「' +
                            String(
                                group.name ||
                                    'グループ'
                            ) +
                            '」に質問を1つ以上登録してください。'
                    );

                    return;
                }

                group.questions.forEach(
                    function (
                        question,
                        questionIndex
                    ) {
                        if (
                            !String(
                                question.text ||
                                    ''
                            ).trim()
                        ) {
                            errors.push(
                                '質問' +
                                    String(
                                        questionIndex +
                                            1
                                    ) +
                                    'の質問文を入力してください。'
                            );
                        }

                        if (
                            question.type ===
                                'single' ||
                            question.type ===
                                'multiple'
                        ) {
                            const options =
                                Array.isArray(
                                    question.options
                                )
                                    ? question.options
                                    : [];

                            if (
                                options.length ===
                                0
                            ) {
                                errors.push(
                                    '選択式の質問には選択肢を1つ以上登録してください。'
                                );
                            }

                            options.forEach(
                                function (
                                    option
                                ) {
                                    if (
                                        !String(
                                            option.text ||
                                                ''
                                        ).trim()
                                    ) {
                                        errors.push(
                                            '選択肢の内容を入力してください。'
                                        );
                                    }
                                }
                            );
                        }
                    }
                );
            }
        );

        return errors;
    }

    async function saveSurvey(
        button
    ) {
        if (!button) {
            return;
        }

        showLoading(button);

        try {
            const survey =
                collectSurveyForm();

            if (!survey) {
                throw new Error(
                    '保存するアンケートがありません。'
                );
            }

            const errors =
                validateSurvey(
                    survey
                );

            if (
                errors.length > 0
            ) {
                throw new Error(
                    errors[0]
                );
            }

            const result =
                await apiRequest(
                    'save_survey',
                    {
                        method: 'POST',
                        body: {
                            survey:
                                survey
                        }
                    }
                );

            const savedSurvey =
                result?.survey;

            if (
                savedSurvey &&
                typeof savedSurvey ===
                    'object'
            ) {
                editingSurvey =
                    normalizeSurvey(
                        savedSurvey
                    );
            }

            await loadSurveys();

            showToast(
                'アンケートを保存しました。',
                'success'
            );
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : 'アンケートの保存に失敗しました。',
                'error'
            );
        } finally {
            hideLoading(button);
        }
    }

    async function deleteSurvey(
        surveyId,
        button
    ) {
        if (!button) {
            return;
        }

        if (
            !window.confirm(
                'このアンケートを削除しますか？'
            )
        ) {
            return;
        }

        showLoading(button);

        try {
            await apiRequest(
                'delete_survey',
                {
                    method: 'POST',
                    body: {
                        id:
                            String(
                                surveyId
                            )
                    }
                }
            );

            await loadSurveys();

            showToast(
                'アンケートを削除しました。',
                'success'
            );
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : 'アンケートの削除に失敗しました。',
                'error'
            );
        } finally {
            hideLoading(button);
        }
    }

    function cancelEditor() {
        editingSurvey = null;

        switchScreen(
            'surveys'
        );
    }

    function openSettings() {
        switchScreen(
            'settings'
        );

        loadSettings();
    }

    async function loadSettings() {
        setPageLoading(true);

        try {
            const result =
                await apiRequest(
                    'get_settings'
                );

            const settings =
                result?.settings;

            if (
                !settings ||
                typeof settings !==
                    'object'
            ) {
                throw new Error(
                    '設定情報を取得できませんでした。'
                );
            }

            setValue(
                'kintone-domain',
                settings.domain || ''
            );

            setValue(
                'kintone-app-id',
                settings.app_id || ''
            );

            setValue(
                'kintone-login-name',
                settings.login_name || ''
            );

            setValue(
                'kintone-proxy',
                settings.proxy_host_port ||
                    ''
            );

            setValue(
                'kintone-password',
                ''
            );
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : '設定情報の取得に失敗しました。',
                'error'
            );
        } finally {
            setPageLoading(false);
        }
    }

    function collectSettings() {
        return {
            domain:
                getValue(
                    'kintone-domain'
                ).trim(),

            app_id:
                getValue(
                    'kintone-app-id'
                ).trim(),

            login_name:
                getValue(
                    'kintone-login-name'
                ).trim(),

            password:
                getValue(
                    'kintone-password'
                ),

            proxy_host_port:
                getValue(
                    'kintone-proxy'
                ).trim()
        };
    }

    async function testKintoneConnection(
        button
    ) {
        if (!button) {
            return;
        }

        showLoading(button);

        try {
            const settings =
                collectSettings();

            if (
                !settings.domain
            ) {
                throw new Error(
                    'kintoneのドメインを入力してください。'
                );
            }

            if (
                !settings.app_id
            ) {
                throw new Error(
                    'アプリIDを入力してください。'
                );
            }

            if (
                !settings.login_name
            ) {
                throw new Error(
                    'ログイン名を入力してください。'
                );
            }

            if (
                !settings.password
            ) {
                throw new Error(
                    'パスワードを入力してください。'
                );
            }

            const result =
                await apiRequest(
                    'test_kintone',
                    {
                        method: 'POST',
                        body: {
                            domain:
                                settings.domain,

                            app_id:
                                settings.app_id,

                            login_name:
                                settings.login_name,

                            password:
                                settings.password,

                            proxy_host_port:
                                settings.proxy_host_port
                        }
                    }
                );

            showToast(
                result?.message ||
                    'kintoneへの接続を確認しました。',
                'success'
            );
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : 'kintoneへの接続確認に失敗しました。',
                'error'
            );
        } finally {
            hideLoading(button);
        }
    }

    async function saveSettings(
        button
    ) {
        if (!button) {
            return;
        }

        showLoading(button);

        try {
            const settings =
                collectSettings();

            if (
                !settings.domain
            ) {
                throw new Error(
                    'kintoneのドメインを入力してください。'
                );
            }

            if (
                !settings.app_id
            ) {
                throw new Error(
                    'アプリIDを入力してください。'
                );
            }

            if (
                !settings.login_name
            ) {
                throw new Error(
                    'ログイン名を入力してください。'
                );
            }

            const result =
                await apiRequest(
                    'save_settings',
                    {
                        method: 'POST',
                        body: {
                            domain:
                                settings.domain,

                            app_id:
                                settings.app_id,

                            login_name:
                                settings.login_name,

                            password:
                                settings.password,

                            proxy_host_port:
                                settings.proxy_host_port
                        }
                    }
                );

            setValue(
                'kintone-password',
                ''
            );

            showToast(
                result?.message ||
                    '設定を保存しました。',
                'success'
            );
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : '設定の保存に失敗しました。',
                'error'
            );
        } finally {
            hideLoading(button);
        }
    }

    function bindNavigation() {
        const navigation =
            document.querySelectorAll(
                '[data-nav]'
            );

        navigation.forEach(
            function (item) {
                if (!item) {
                    return;
                }

                item.addEventListener(
                    'click',
                    function () {
                        const target =
                            item.dataset.nav ||
                            '';

                        if (
                            target ===
                            'surveys'
                        ) {
                            switchScreen(
                                'surveys'
                            );

                            loadSurveys();

                            return;
                        }

                        if (
                            target ===
                            'settings'
                        ) {
                            openSettings();

                            return;
                        }

                        switchScreen(
                            target
                        );
                    }
                );
            }
        );
    }

    function bindEditorButtons() {
        const newSurveyButton =
            $('btn-new-survey');

        if (newSurveyButton) {
            newSurveyButton.addEventListener(
                'click',
                function () {
                    createNewSurvey();
                }
            );
        }

        const saveButton =
            $('btn-save-survey');

        if (saveButton) {
            saveButton.addEventListener(
                'click',
                function () {
                    saveSurvey(
                        saveButton
                    );
                }
            );
        }

        const cancelButton =
            $('btn-cancel-editor');

        if (cancelButton) {
            cancelButton.addEventListener(
                'click',
                function () {
                    cancelEditor();
                }
            );
        }
    }

    function bindSettingsButtons() {
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

        const saveButton =
            $('btn-save-settings');

        if (saveButton) {
            saveButton.addEventListener(
                'click',
                function () {
                    saveSettings(
                        saveButton
                    );
                }
            );
        }
    }

    function bindSurveyListButtons() {
        const refreshButton =
            $('btn-refresh-surveys');

        if (refreshButton) {
            refreshButton.addEventListener(
                'click',
                function () {
                    showLoading(
                        refreshButton
                    );

                    loadSurveys()
                        .finally(
                            function () {
                                hideLoading(
                                    refreshButton
                                );
                            }
                        );
                }
            );
        }
    }

    function bindCommonButtons() {
        const backToListButton =
            $('btn-back-surveys');

        if (backToListButton) {
            backToListButton.addEventListener(
                'click',
                function () {
                    switchScreen(
                        'surveys'
                    );

                    loadSurveys();
                }
            );
        }

        const logoButton =
            $('app-logo');

        if (logoButton) {
            logoButton.addEventListener(
                'click',
                function () {
                    switchScreen(
                        'surveys'
                    );

                    loadSurveys();
                }
            );
        }
    }

    function initialize() {
        bindNavigation();

        bindEditorButtons();

        bindSettingsButtons();

        bindSurveyListButtons();

        bindCommonButtons();

        switchScreen(
            'surveys'
        );

        loadSurveys();
    }

    document.addEventListener(
        'DOMContentLoaded',
        function () {
            initialize();
        }
    );

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
header('Content-Type: text/html; charset=UTF-8');

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

/**
 * CSRFトークン取得
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
 * リクエストメソッド取得
 */
function request_method(): string
{
    return strtoupper(
        is_string($_SERVER['REQUEST_METHOD'] ?? null)
            ? $_SERVER['REQUEST_METHOD']
            : 'GET'
    );
}

/**
 * アクション取得
 */
function requested_action(): string
{
    $action = $_GET['action'] ?? '';

    return is_string($action) ? trim($action) : '';
}

/**
 * JSONリクエスト取得
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
 * CSRF検証
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
 * データ保存ディレクトリを準備
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
            'connection_status' => 'not_configured',
            'tested_at' => null
        ]
    ];
}

/**
 * 顧客データ初期値
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
 * アンケートデータ初期値
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
 * 回答データ初期値
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
 * メール送信履歴初期値
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
 * JSONファイル読み込み
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
 * JSONファイル安全保存
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
    } catch (\Throwable) {
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
    } catch (\Throwable) {
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
 * 各データ読み込み
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
 * 現在日時
 */
function now(): string
{
    return date('c');
}

/**
 * 一意なIDを生成
 */
function generate_id(string $prefix): string
{
    return $prefix .
        '_' .
        date('YmdHis') .
        '_' .
        bin2hex(random_bytes(4));
}

/**
 * APIパス
 */
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

/**
 * kintone URL生成
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
 * サイボウズ認証ヘッダー生成
 */
function make_cybozu_auth_header(
    string $login_name,
    string $password
): string {
    $login_name = trim($login_name);
    $password = trim($password);

    $auth = base64_encode(
        $login_name . ':' . $password
    );

    return 'X-Cybozu-Authorization: ' . $auth;
}

/**
 * PHP 8.4/8.5対応レスポンスヘッダー取得
 */
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

/**
 * HTTPステータス取得
 */
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

/**
 * kintone API通信
 *
 * cURLは使用せず、
 * stream_context_create + file_get_contents を使用する。
 */
function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    array $payload,
    array $config
): array {
    $method = strtoupper($method);

    $httpOptions = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20
    ];

    if ($method === 'GET') {
        // GETではcontentを設定しない。
    } elseif ($payload !== []) {
        try {
            $httpOptions['content'] = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable) {
            return [
                'success' => false,
                'status' => 0,
                'message' => '通信データを作成できませんでした。',
                'data' => []
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
     * プロキシ設定。
     *
     * host:portの入力を常に受け取れる構成とし、
     * 指定された場合はproxyおよびrequest_fulluriを適用する。
     */
    $proxyHost = trim(
        (string)($config['proxy_host'] ?? '')
    );

    $proxyPort = trim(
        (string)($config['proxy_port'] ?? '')
    );

    if ($proxyHost !== '') {
        $proxy = $proxyHost;

        if ($proxyPort !== '') {
            $proxy .= ':' . $proxyPort;
        }

        $contextOptions['http']['proxy'] =
            'tcp://' . $proxy;

        $contextOptions['http']['request_fulluri'] = true;
    } else {
        /*
         * プロキシ未設定時は通信を通常経路で行う。
         */
        $contextOptions['http']['request_fulluri'] = false;
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
        extract_http_status($responseHeaders);

    $decoded = [];

    if (
        $response !== false &&
        trim($response) !== ''
    ) {
        $decodedValue =
            json_decode($response, true);

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

            foreach ($messages as $errorMessage) {
                if (is_string($errorMessage)) {
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
 * kintone接続確認
 */
function test_kintone(array $settings): array
{
    $domain = trim(
        (string)($settings['domain'] ?? '')
    );

    $login = trim(
        (string)($settings['login_name'] ?? '')
    );

    $password =
        (string)($settings['password'] ?? '');

    $appId = trim(
        (string)($settings['customer_app_id'] ?? '')
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
        $url = kintone_build_url(
            $domain,
            '/k/v1/app.json?id=' .
            rawurlencode($appId)
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
 * kintoneから顧客取得
 */
function fetch_kintone_customers(
    array $settings
): array {
    $domain = trim(
        (string)($settings['domain'] ?? '')
    );

    $login = trim(
        (string)($settings['login_name'] ?? '')
    );

    $password =
        (string)($settings['password'] ?? '');

    $appId = trim(
        (string)($settings['customer_app_id'] ?? '')
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

    $idField = trim(
        (string)(
            $settings['customer_id_field'] ?? ''
        )
    );

    $nameField = trim(
        (string)(
            $settings['customer_name_field'] ?? ''
        )
    );

    $emailField = trim(
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
            $idField !== '' &&
            isset(
                $record[$idField]['value']
            )
        ) {
            $id =
                (string)$record[$idField]['value'];
        }

        if (
            $nameField !== '' &&
            isset(
                $record[$nameField]['value']
            )
        ) {
            $name =
                (string)$record[$nameField]['value'];
        }

        if (
            $emailField !== '' &&
            isset(
                $record[$emailField]['value']
            )
        ) {
            $email =
                (string)$record[$emailField]['value'];
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
 * アンケート共通処理
 * ========================================================= */

/**
 * アンケート検索
 */
function survey_find(
    array $surveys,
    string $id
): ?array {
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

/**
 * アンケート位置取得
 */
function survey_index(
    array $surveys,
    string $id
): int {
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

/**
 * 質問を正規化
 */
function normalize_question(
    array $question
): array {
    $type =
        (string)($question['type'] ?? 'free');

    if (
        !in_array(
            $type,
            ['free', 'single', 'multiple'],
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
            !empty($question['required']),
        'options' => $options
    ];
}

/**
 * アンケートを正規化
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
            ['draft', 'open', 'end'],
            true
        )
    ) {
        $status = 'draft';
    }

    $numbering =
        (string)(
            $survey['numbering'] ?? 'global'
        );

    if (
        !in_array(
            $numbering,
            ['global', 'group'],
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
        'numbering' => $numbering,
        'groups' => $groups
    ];
}

/**
 * 全質問取得
 */
function all_questions(
    array $survey
): array {
    $result = [];

    foreach (
        ($survey['groups'] ?? [])
        as $groupIndex => $group
    ) {
        if (!is_array($group)) {
            continue;
        }

        foreach (
            ($group['questions'] ?? [])
            as $questionIndex => $question
        ) {
            if (!is_array($question)) {
                continue;
            }

            $result[] = [
                'id' =>
                    (string)(
                        $question['id'] ?? ''
                    ),
                'text' =>
                    (string)(
                        $question['text'] ?? ''
                    ),
                'group_index' =>
                    $groupIndex,
                'question_index' =>
                    $questionIndex
            ];
        }
    }

    return $result;
}

/**
 * 質問番号
 */
function question_number(
    array $survey,
    int $groupIndex,
    int $questionIndex
): string {
    $numbering =
        (string)(
            $survey['numbering'] ?? 'global'
        );

    if ($numbering === 'group') {
        return 'Q' .
            ($groupIndex + 1) .
            '-' .
            ($questionIndex + 1);
    }

    $number = 0;

    foreach (
        ($survey['groups'] ?? [])
        as $currentGroupIndex => $group
    ) {
        if (!is_array($group)) {
            continue;
        }

        $questions =
            $group['questions'] ?? [];

        if (!is_array($questions)) {
            continue;
        }

        foreach (
            $questions as $currentQuestionIndex => $unused
        ) {
            $number++;

            if (
                $currentGroupIndex === $groupIndex &&
                $currentQuestionIndex === $questionIndex
            ) {
                return 'Q' . $number;
            }
        }
    }

    return 'Q' . ($number + 1);
}

/**
 * 分岐先の妥当性確認
 */
function validate_branches(
    array $survey
): array {
    $questionIds = [];

    foreach (
        all_questions($survey)
        as $question
    ) {
        $questionIds[
            (string)$question['id']
        ] = true;
    }

    $errors = [];

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
            if (!is_array($question)) {
                continue;
            }

            if (
                (string)(
                    $question['type'] ?? ''
                ) !== 'single'
            ) {
                continue;
            }

            $questionText =
                (string)(
                    $question['text'] ?? ''
                );

            foreach (
                ($question['options'] ?? [])
                as $optionIndex => $option
            ) {
                if (!is_array($option)) {
                    continue;
                }

                $branch =
                    trim(
                        (string)(
                            $option['branch'] ?? ''
                        )
                    );

                if (
                    $branch !== '' &&
                    !isset($questionIds[$branch])
                ) {
                    $errors[] =
                        '「' .
                        $questionText .
                        '」の選択肢「' .
                        (string)(
                            $option['text'] ?? ''
                        ) .
                        '」の分岐先が存在しません。';
                }
            }
        }
    }

    return $errors;
}

/**
 * アンケート入力内容の検証
 */
function validate_survey(
    array $survey
): array {
    $errors = [];

    $name =
        trim(
            (string)(
                $survey['name'] ?? ''
            )
        );

    if ($name === '') {
        $errors['name'] =
            'アンケート名を入力してください。';
    }

    $start =
        trim(
            (string)(
                $survey['start'] ?? ''
            )
        );

    $end =
        trim(
            (string)(
                $survey['end'] ?? ''
            )
        );

    if (
        $start !== '' &&
        $end !== '' &&
        $start > $end
    ) {
        $errors['period'] =
            '公開終了日は公開開始日以降にしてください。';
    }

    $groups =
        $survey['groups'] ?? [];

    if (!is_array($groups)) {
        $errors['groups'] =
            '質問グループを確認してください。';

        return $errors;
    }

    foreach (
        $groups as $groupIndex => $group
    ) {
        if (!is_array($group)) {
            $errors[
                'group_' . $groupIndex
            ] = 'グループを確認してください。';

            continue;
        }

        $groupName =
            trim(
                (string)(
                    $group['name'] ?? ''
                )
            );

        if ($groupName === '') {
            $errors[
                'group_' . $groupIndex
            ] =
                'グループ名を入力してください。';
        }

        $questions =
            $group['questions'] ?? [];

        if (!is_array($questions)) {
            continue;
        }

        foreach (
            $questions
            as $questionIndex => $question
        ) {
            if (!is_array($question)) {
                continue;
            }

            $text =
                trim(
                    (string)(
                        $question['text'] ?? ''
                    )
                );

            if ($text === '') {
                $errors[
                    'question_' .
                    $groupIndex .
                    '_' .
                    $questionIndex
                ] =
                    '質問文を入力してください。';
            }

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
                $errors[
                    'question_' .
                    $groupIndex .
                    '_' .
                    $questionIndex
                ] =
                    '回答形式を確認してください。';

                continue;
            }

            if (
                $type === 'single' ||
                $type === 'multiple'
            ) {
                $options =
                    $question['options'] ?? [];

                if (!is_array($options)) {
                    $options = [];
                }

                $validOptionCount = 0;

                foreach (
                    $options as $option
                ) {
                    if (!is_array($option)) {
                        continue;
                    }

                    if (
                        trim(
                            (string)(
                                $option['text'] ?? ''
                            )
                        ) !== ''
                    ) {
                        $validOptionCount++;
                    }
                }

                if ($validOptionCount < 1) {
                    $errors[
                        'question_' .
                        $groupIndex .
                        '_' .
                        $questionIndex
                    ] =
                        '選択肢を1つ以上入力してください。';
                }
            }
        }
    }

    $branchErrors =
        validate_branches($survey);

    if ($branchErrors !== []) {
        $errors['branch'] =
            implode(' ', $branchErrors);
    }

    return $errors;
}

/* =========================================================
 * 初期化
 * ========================================================= */

initialize_data_files();

/* =========================================================
 * API処理
 * ========================================================= */

if (request_method() !== 'GET') {
    validate_csrf();
}

$action = requested_action();

if ($action !== '') {
    switch ($action) {
        case 'get_surveys':
            $stored = load_surveys();

            $surveys =
                $stored['surveys'] ?? [];

            if (!is_array($surveys)) {
                $surveys = [];
            }

            $normalized = [];

            foreach ($surveys as $survey) {
                if (is_array($survey)) {
                    $normalized[] =
                        normalize_survey($survey);
                }
            }

            json_response(
                true,
                'アンケート一覧を取得しました。',
                [
                    'surveys' => $normalized
                ]
            );

        case 'get_survey':
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

            $stored = load_surveys();

            $surveys =
                $stored['surveys'] ?? [];

            if (!is_array($surveys)) {
                $surveys = [];
            }

            $survey =
                survey_find(
                    $surveys,
                    trim($id)
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
                        normalize_survey($survey)
                ]
            );

        case 'save_survey':
            $input = read_json_request();

            $surveyInput =
                $input['survey'] ?? null;

            if (!is_array($surveyInput)) {
                json_response(
                    false,
                    'アンケート内容を確認してください。',
                    [],
                    [],
                    400
                );
            }

            $survey =
                normalize_survey($surveyInput);

            if ($survey['id'] === '') {
                $survey['id'] =
                    generate_id('survey');
            }

            $errors =
                validate_survey($survey);

            if ($errors !== []) {
                json_response(
                    false,
                    '入力内容を確認してください。',
                    [],
                    $errors,
                    422
                );
            }

            $stored = load_surveys();

            $surveys =
                $stored['surveys'] ?? [];

            if (!is_array($surveys)) {
                $surveys = [];
            }

            $index =
                survey_index(
                    $surveys,
                    $survey['id']
                );

            $today = date('Y-m-d');

            if ($index >= 0) {
                $old =
                    is_array($surveys[$index])
                        ? $surveys[$index]
                        : [];

                $survey['created'] =
                    (string)(
                        $old['created'] ??
                        $today
                    );

                $survey['updated'] =
                    $today;

                $surveys[$index] =
                    $survey;
            } else {
                $survey['created'] =
                    $today;

                $survey['updated'] =
                    $today;

                $survey['answers'] = 0;
                $survey['target'] = 0;
                $survey['sent'] = 0;

                $surveys[] = $survey;
            }

            $stored['version'] = 1;
            $stored['updated_at'] = now();
            $stored['surveys'] = $surveys;

            write_json_file(
                SURVEYS_FILE,
                $stored
            );

            json_response(
                true,
                'アンケートを保存しました。',
                [
                    'survey' => $survey
                ]
            );

        case 'delete_survey':
            $input = read_json_request();

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

            $stored = load_surveys();

            $surveys =
                $stored['surveys'] ?? [];

            if (!is_array($surveys)) {
                $surveys = [];
            }

            $index =
                survey_index(
                    $surveys,
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
                is_array($surveys[$index])
                    ? $surveys[$index]
                    : [];

            if (
                (string)(
                    $survey['status'] ?? 'draft'
                ) !== 'draft'
            ) {
                json_response(
                    false,
                    '下書きのアンケートだけ削除できます。',
                    [],
                    [],
                    409
                );
            }

            array_splice(
                $surveys,
                $index,
                1
            );

            $stored['updated_at'] = now();
            $stored['surveys'] = $surveys;

            write_json_file(
                SURVEYS_FILE,
                $stored
            );

            json_response(
                true,
                'アンケートを削除しました。'
            );

        case 'change_survey_status':
            $input = read_json_request();

            $id =
                trim(
                    (string)(
                        $input['id'] ?? ''
                    )
                );

            $status =
                trim(
                    (string)(
                        $input['status'] ?? ''
                    )
                );

            if (
                $id === '' ||
                !in_array(
                    $status,
                    ['draft', 'open', 'end'],
                    true
                )
            ) {
                json_response(
                    false,
                    '変更内容を確認してください。',
                    [],
                    [],
                    400
                );
            }

            $stored = load_surveys();

            $surveys =
                $stored['surveys'] ?? [];

            if (!is_array($surveys)) {
                $surveys = [];
            }

            $index =
                survey_index(
                    $surveys,
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
                    $surveys[$index]
                );

            $survey['status'] = $status;
            $survey['updated'] = date('Y-m-d');

            $surveys[$index] = $survey;

            $stored['updated_at'] = now();
            $stored['surveys'] = $surveys;

            write_json_file(
                SURVEYS_FILE,
                $stored
            );

            json_response(
                true,
                'アンケートの状態を変更しました。',
                [
                    'survey' => $survey
                ]
            );

        case 'get_customers':
            $stored = load_customers();

            $customers =
                $stored['customers'] ?? [];

            if (!is_array($customers)) {
                $customers = [];
            }

            json_response(
                true,
                '顧客一覧を取得しました。',
                [
                    'customers' => $customers
                ]
            );

        case 'refresh_customers':
            $settings = load_settings();

            $kintone =
                $settings['kintone'] ?? [];

            if (!is_array($kintone)) {
                $kintone = [];
            }

            $result =
                fetch_kintone_customers(
                    $kintone
                );

            if (!$result['success']) {
                json_response(
                    false,
                    (string)(
                        $result['message'] ??
                        '顧客一覧を取得できませんでした。'
                    ),
                    [],
                    is_array(
                        $result['errors'] ?? null
                    )
                        ? $result['errors']
                        : [],
                    502
                );
            }

            $customers =
                $result['customers'] ?? [];

            if (!is_array($customers)) {
                $customers = [];
            }

            $stored = default_customers();

            $stored['updated_at'] = now();
            $stored['count'] = count($customers);
            $stored['customers'] = $customers;

            write_json_file(
                CUSTOMERS_FILE,
                $stored
            );

            json_response(
                true,
                '顧客一覧を更新しました。',
                [
                    'customers' => $customers
                ]
            );

        case 'get_settings':
            $settings = load_settings();

            /*
             * パスワードは画面へ返さない。
             */
            if (
                isset(
                    $settings['mail']
                ) &&
                is_array(
                    $settings['mail']
                )
            ) {
                $settings['mail']['password'] = '';
            }

            if (
                isset(
                    $settings['kintone']
                ) &&
                is_array(
                    $settings['kintone']
                )
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

        case 'save_settings':
            $input = read_json_request();

            $settingsInput =
                $input['settings'] ?? null;

            if (!is_array($settingsInput)) {
                json_response(
                    false,
                    '設定内容を確認してください。',
                    [],
                    [],
                    400
                );
            }

            $current = load_settings();

            $mailInput =
                $settingsInput['mail'] ?? [];

            if (!is_array($mailInput)) {
                $mailInput = [];
            }

            $currentMail =
                $current['mail'] ?? [];

            if (!is_array($currentMail)) {
                $currentMail = [];
            }

            $mailPassword =
                array_key_exists(
                    'password',
                    $mailInput
                )
                    ? (string)(
                        $mailInput['password']
                    )
                    : (string)(
                        $currentMail['password']
                        ?? ''
                    );

            $kintoneInput =
                $settingsInput['kintone'] ?? [];

            if (!is_array($kintoneInput)) {
                $kintoneInput = [];
            }

            $currentKintone =
                $current['kintone'] ?? [];

            if (!is_array($currentKintone)) {
                $currentKintone = [];
            }

            $kintonePassword =
                array_key_exists(
                    'password',
                    $kintoneInput
                )
                    ? (string)(
                        $kintoneInput['password']
                    )
                    : (string)(
                        $currentKintone['password']
                        ?? ''
                    );

            $newSettings = [
                'version' => 1,
                'updated_at' => now(),
                'mail' => [
                    'smtp_server' =>
                        trim(
                            (string)(
                                $mailInput[
                                    'smtp_server'
                                ] ?? ''
                            )
                        ),
                    'smtp_port' =>
                        (int)(
                            $mailInput[
                                'smtp_port'
                            ] ?? 587
                        ),
                    'connection_type' =>
                        trim(
                            (string)(
                                $mailInput[
                                    'connection_type'
                                ] ?? 'tls'
                            )
                        ),
                    'username' =>
                        trim(
                            (string)(
                                $mailInput[
                                    'username'
                                ] ?? ''
                            )
                        ),
                    'password' =>
                        $mailPassword,
                    'from_email' =>
                        trim(
                            (string)(
                                $mailInput[
                                    'from_email'
                                ] ?? ''
                            )
                        ),
                    'from_name' =>
                        trim(
                            (string)(
                                $mailInput[
                                    'from_name'
                                ] ??
                                'アンケート事務局'
                            )
                        ),
                    'configured' =>
                        !empty(
                            $mailInput[
                                'configured'
                            ]
                        ),
                    'tested_at' =>
                        $currentMail[
                            'tested_at'
                        ] ?? null
                ],
                'kintone' => [
                    'domain' =>
                        trim(
                            (string)(
                                $kintoneInput[
                                    'domain'
                                ] ?? ''
                            )
                        ),
                    'login_name' =>
                        trim(
                            (string)(
                                $kintoneInput[
                                    'login_name'
                                ] ?? ''
                            )
                        ),
                    'password' =>
                        $kintonePassword,
                    'customer_app_id' =>
                        trim(
                            (string)(
                                $kintoneInput[
                                    'customer_app_id'
                                ] ?? ''
                            )
                        ),
                    'customer_id_field' =>
                        trim(
                            (string)(
                                $kintoneInput[
                                    'customer_id_field'
                                ] ?? ''
                            )
                        ),
                    'customer_name_field' =>
                        trim(
                            (string)(
                                $kintoneInput[
                                    'customer_name_field'
                                ] ?? ''
                            )
                        ),
                    'customer_email_field' =>
                        trim(
                            (string)(
                                $kintoneInput[
                                    'customer_email_field'
                                ] ?? ''
                            )
                        ),
                    'proxy_host' =>
                        trim(
                            (string)(
                                $kintoneInput[
                                    'proxy_host'
                                ] ?? ''
                            )
                        ),
                    'proxy_port' =>
                        trim(
                            (string)(
                                $kintoneInput[
                                    'proxy_port'
                                ] ?? ''
                            )
                        ),
                    'configured' =>
                        !empty(
                            $kintoneInput[
                                'configured'
                            ]
                        ),
                    'connection_status' =>
                        (string)(
                            $currentKintone[
                                'connection_status'
                            ] ??
                            'not_configured'
                        ),
                    'tested_at' =>
                        $currentKintone[
                            'tested_at'
                        ] ?? null
                ]
            ];

            write_json_file(
                SETTINGS_FILE,
                $newSettings
            );

            json_response(
                true,
                '設定を保存しました。'
            );

        case 'test_kintone':
            $input = read_json_request();

            $settingsInput =
                $input['kintone'] ?? [];

            if (!is_array($settingsInput)) {
                json_response(
                    false,
                    'kintone設定を確認してください。',
                    [],
                    [],
                    400
                );
            }

            $current = load_settings();

            $currentKintone =
                $current['kintone'] ?? [];

            if (!is_array($currentKintone)) {
                $currentKintone = [];
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
                if (
                    array_key_exists(
                        $key,
                        $settingsInput
                    )
                ) {
                    $currentKintone[$key] =
                        trim(
                            (string)(
                                $settingsInput[$key]
                            )
                        );
                }
            }

            if (
                array_key_exists(
                    'password',
                    $settingsInput
                ) &&
                (string)(
                    $settingsInput['password']
                ) !== ''
            ) {
                $currentKintone['password'] =
                    (string)(
                        $settingsInput['password']
                    );
            }

            $result =
                test_kintone(
                    $currentKintone
                );

            if (!$result['success']) {
                json_response(
                    false,
                    (string)(
                        $result['message'] ??
                        'kintoneへの接続を確認できませんでした。'
                    ),
                    [],
                    is_array(
                        $result['errors'] ?? null
                    )
                        ? $result['errors']
                        : [],
                    502
                );
            }

            $currentKintone[
                'configured'
            ] = true;

            $currentKintone[
                'connection_status'
            ] = 'connected';

            $currentKintone[
                'tested_at'
            ] = now();

            $current['kintone'] =
                $currentKintone;

            $current['updated_at'] = now();

            write_json_file(
                SETTINGS_FILE,
                $current
            );

            json_response(
                true,
                'kintoneへの接続を確認しました。'
            );

        case 'get_responses':
            $surveyId = $_GET['survey_id'] ?? '';

            if (
                !is_string($surveyId) ||
                trim($surveyId) === ''
            ) {
                json_response(
                    false,
                    'アンケートを指定してください。',
                    [],
                    [],
                    400
                );
            }

            $stored = load_responses();

            $responses =
                $stored['responses'] ?? [];

            if (!is_array($responses)) {
                $responses = [];
            }

            $filtered = [];

            foreach ($responses as $response) {
                if (
                    !is_array($response)
                ) {
                    continue;
                }

                if (
                    (string)(
                        $response['survey_id']
                        ?? ''
                    ) !== trim($surveyId)
                ) {
                    continue;
                }

                $filtered[] = $response;
            }

            json_response(
                true,
                '回答状況を取得しました。',
                [
                    'responses' => $filtered
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

/* -----------------------------
   共通レイアウト
----------------------------- */

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

/* -----------------------------
   カード
----------------------------- */

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

/* -----------------------------
   ボタン
----------------------------- */

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

/* -----------------------------
   ローディング
----------------------------- */

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

.is-loading .loading-spinner {
    display: inline-block;
}

@keyframes spin {
    from {
        transform: rotate(0deg);
    }

    to {
        transform: rotate(360deg);
    }
}

/* -----------------------------
   フォーム
----------------------------- */

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 15px;
}

.field {
    margin-bottom: 15px;
}

.field:last-child {
    margin-bottom: 0;
}

.field label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
}

.field input[type="text"],
.field input[type="email"],
.field input[type="password"],
.field input[type="date"],
.field input[type="number"],
.field input[type="url"],
.field textarea,
.field select {
    width: 100%;
    border: 1px solid #cbd5e0;
    border-radius: 5px;
    background: #fff;
    color: #263238;
    padding: 9px 10px;
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
    display: flex;
    align-items: center;
    gap: 5px;
}

/* -----------------------------
   通知
----------------------------- */

.notice {
    background: #f7f9fb;
    border: 1px solid #dfe5eb;
    border-radius: 5px;
    padding: 11px 13px;
    margin-bottom: 15px;
}

.notice.success {
    background: #eef9f1;
    border-color: #b9dfc4;
    color: #246b38;
}

.notice.warning {
    background: #fff8e7;
    border-color: #ead79d;
    color: #785d10;
}

.notice.error {
    background: #fff1f1;
    border-color: #e6b8b8;
    color: #a02b2b;
}

/* -----------------------------
   テーブル
----------------------------- */

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
    border-bottom: 1px solid #e6ebef;
    padding: 11px 10px;
    text-align: left;
    vertical-align: middle;
}

.table th {
    background: #f8fafc;
    font-weight: 600;
    white-space: nowrap;
}

.table tbody tr:hover {
    background: #fbfcfd;
}

.table-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}

/* -----------------------------
   ステータス
----------------------------- */

.status-badge {
    display: inline-block;
    border-radius: 999px;
    padding: 3px 9px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
}

.status-draft {
    background: #edf2f7;
    color: #52606d;
}

.status-open {
    background: #e7f7ed;
    color: #237542;
}

.status-end {
    background: #f1f1f1;
    color: #606060;
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

/* -----------------------------
   アンケート編集
----------------------------- */

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
    padding: 8px;
    font-weight: bold;
}

.group-actions {
    display: flex;
    gap: 6px;
}

.question-card {
    padding: 15px;
    border-bottom: 1px solid #e6ebef;
}

.question-card:last-child {
    border-bottom: 0;
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
    gap: 8px;
}

.question-number {
    width: 60px;
    color: #2878c8;
    font-weight: bold;
    flex: none;
}

.question-title {
    flex: 1;
}

.question-title input {
    width: 100%;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 8px;
}

.question-tools {
    display: flex;
    gap: 6px;
    align-items: center;
}

.question-tools select {
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 6px;
}

.question-meta {
    display: flex;
    gap: 18px;
    align-items: center;
    margin-top: 10px;
    padding-left: 68px;
    color: #657786;
    font-size: 13px;
}

.question-options {
    margin-top: 12px;
    padding-left: 68px;
}

.option-row {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 7px;
}

.option-row input {
    flex: 1;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 7px;
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
    gap: 10px;
    margin-top: 20px;
}

.editor-actions {
    display: flex;
    gap: 8px;
}

/* -----------------------------
   詳細・回答状況
----------------------------- */

.detail-summary {
    display: grid;
    grid-template-columns:
        repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}

.summary-box {
    background: #fff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    padding: 15px;
}

.summary-label {
    color: #718096;
    font-size: 12px;
    margin-bottom: 4px;
}

.summary-value {
    font-size: 25px;
    font-weight: bold;
}

.detail-tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid #dfe5eb;
    margin-bottom: 18px;
}

.detail-tabs button {
    border: 0;
    background: transparent;
    padding: 10px 15px;
    color: #5b6875;
}

.detail-tabs button.active {
    color: #2878c8;
    font-weight: bold;
    border-bottom: 3px solid #2878c8;
}

.result-layout {
    display: grid;
    grid-template-columns:
        minmax(0, 1fr)
        minmax(0, 1fr);
    gap: 18px;
}

.result-item {
    background: #fff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    padding: 16px;
    margin-bottom: 12px;
}

.result-question {
    font-weight: bold;
    margin-bottom: 12px;
}

.result-bar-row {
    display: grid;
    grid-template-columns: 180px 1fr 60px;
    align-items: center;
    gap: 8px;
    margin: 7px 0;
}

.result-bar {
    height: 10px;
    background: #e9eef3;
    border-radius: 999px;
    overflow: hidden;
}

.result-bar-fill {
    height: 100%;
    background: #2878c8;
    border-radius: 999px;
}

/* -----------------------------
   送信
----------------------------- */

.send-layout {
    display: grid;
    grid-template-columns:
        minmax(0, 1.2fr)
        minmax(320px, .8fr);
    gap: 18px;
}

.selection-summary {
    margin: 10px 0;
    padding: 8px 10px;
    background: #f7f9fb;
    border-radius: 5px;
    color: #52606d;
}

.email-preview {
    white-space: pre-wrap;
    background: #f8fafc;
    border: 1px solid #e3e8ed;
    border-radius: 5px;
    padding: 15px;
    min-height: 220px;
}

/* -----------------------------
   設定
----------------------------- */

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

/* -----------------------------
   モーダル
----------------------------- */

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
    gap: 10px;
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

/* -----------------------------
   トースト
----------------------------- */

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

/* -----------------------------
   回答画面
----------------------------- */

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

/* -----------------------------
   レスポンシブ
----------------------------- */

@media (max-width: 980px) {
    .send-layout,
    .result-layout {
        grid-template-columns: 1fr;
    }

    .detail-summary {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 800px) {
    .topbar {
        padding: 0 10px;
        gap: 8px;
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

    .question-meta,
    .question-options {
        padding-left: 0;
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
}
</style>
</head>

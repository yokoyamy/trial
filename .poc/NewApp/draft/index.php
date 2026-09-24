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
<body>

<header class="topbar">
    <div class="logo">アンケート業務運営</div>

    <nav class="main-nav" aria-label="メインメニュー">
        <button
            type="button"
            class="nav-button active"
            data-page="dashboard"
        >
            ダッシュボード
        </button>

        <button
            type="button"
            class="nav-button"
            data-page="surveys"
        >
            アンケート管理
        </button>

        <button
            type="button"
            class="nav-button"
            data-page="customers"
        >
            顧客管理
        </button>

        <button
            type="button"
            class="nav-button"
            data-page="settings"
        >
            システム設定
        </button>
    </nav>
</header>

<main class="app">

    <!-- =====================================================
         ダッシュボード
    ====================================================== -->
    <section
        id="page-dashboard"
        class="page"
    >
        <div class="page-header">
            <div>
                <h1>ダッシュボード</h1>
                <div class="subtext">
                    アンケートの運営状況を確認できます。
                </div>
            </div>

            <button
                type="button"
                class="btn btn-primary"
                id="dashboard-new-survey-button"
            >
                新しいアンケートを作成
            </button>
        </div>

        <div
            id="dashboard-notice"
            class="notice hidden"
            role="status"
            aria-live="polite"
        ></div>

        <div class="detail-summary">
            <div class="summary-box">
                <div class="summary-label">
                    アンケート総数
                </div>
                <div
                    class="summary-value"
                    id="dashboard-survey-count"
                >
                    0
                </div>
            </div>

            <div class="summary-box">
                <div class="summary-label">
                    公開中
                </div>
                <div
                    class="summary-value"
                    id="dashboard-open-count"
                >
                    0
                </div>
            </div>

            <div class="summary-box">
                <div class="summary-label">
                    回答数
                </div>
                <div
                    class="summary-value"
                    id="dashboard-response-count"
                >
                    0
                </div>
            </div>

            <div class="summary-box">
                <div class="summary-label">
                    顧客数
                </div>
                <div
                    class="summary-value"
                    id="dashboard-customer-count"
                >
                    0
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">
                アンケート一覧
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>アンケート名</th>
                            <th>状態</th>
                            <th>公開期間</th>
                            <th>回答数</th>
                            <th>操作</th>
                        </tr>
                    </thead>

                    <tbody id="dashboard-survey-list">
                        <tr>
                            <td colspan="5">
                                読み込み中です。
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>


    <!-- =====================================================
         アンケート一覧
    ====================================================== -->
    <section
        id="page-surveys"
        class="page hidden"
    >
        <div class="page-header">
            <div>
                <h1>アンケート管理</h1>
                <div class="subtext">
                    アンケートの作成、編集、公開状態を管理します。
                </div>
            </div>

            <button
                type="button"
                class="btn btn-primary"
                id="new-survey-button"
            >
                新しいアンケートを作成
            </button>
        </div>

        <div
            id="survey-list-notice"
            class="notice hidden"
            role="status"
            aria-live="polite"
        ></div>

        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>アンケート名</th>
                            <th>状態</th>
                            <th>公開期間</th>
                            <th>回答</th>
                            <th>送信</th>
                            <th>更新日</th>
                            <th>操作</th>
                        </tr>
                    </thead>

                    <tbody id="survey-list-body">
                        <tr>
                            <td colspan="7">
                                読み込み中です。
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>


    <!-- =====================================================
         アンケート編集
    ====================================================== -->
    <section
        id="page-survey-editor"
        class="page hidden"
    >
        <div class="page-header">
            <div>
                <h1 id="editor-page-title">
                    新しいアンケート
                </h1>

                <div
                    class="subtext"
                    id="editor-page-subtext"
                >
                    アンケートの内容を設定してください。
                </div>
            </div>

            <button
                type="button"
                class="btn"
                id="editor-back-button"
            >
                一覧へ戻る
            </button>
        </div>

        <div
            id="editor-notice"
            class="notice hidden"
            role="status"
            aria-live="polite"
        ></div>

        <div class="card">
            <div class="card-title">
                基本情報
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="survey-name">
                        アンケート名
                    </label>

                    <input
                        type="text"
                        id="survey-name"
                        maxlength="200"
                        autocomplete="off"
                    >
                </div>

                <div class="field">
                    <label for="survey-numbering">
                        質問番号
                    </label>

                    <select id="survey-numbering">
                        <option value="global">
                            全体で連番
                        </option>
                        <option value="group">
                            グループごとに番号
                        </option>
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="survey-description">
                    説明
                </label>

                <textarea
                    id="survey-description"
                    rows="3"
                ></textarea>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="survey-start">
                        公開開始日
                    </label>

                    <input
                        type="date"
                        id="survey-start"
                    >
                </div>

                <div class="field">
                    <label for="survey-end">
                        公開終了日
                    </label>

                    <input
                        type="date"
                        id="survey-end"
                    >
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">
                質問内容
            </div>

            <div
                id="survey-groups"
            ></div>

            <div class="add-group-area">
                <button
                    type="button"
                    class="btn"
                    id="add-group-button"
                >
                    ＋ 質問グループを追加
                </button>
            </div>
        </div>

        <div class="editor-toolbar">
            <div>
                <button
                    type="button"
                    class="btn btn-danger hidden"
                    id="delete-survey-button"
                >
                    アンケートを削除
                </button>
            </div>

            <div class="editor-actions">
                <button
                    type="button"
                    class="btn"
                    id="cancel-editor-button"
                >
                    キャンセル
                </button>

                <button
                    type="button"
                    class="btn btn-primary"
                    id="save-survey-button"
                >
                    <span class="loading-spinner"></span>
                    保存する
                </button>
            </div>
        </div>
    </section>


    <!-- =====================================================
         アンケート詳細
    ====================================================== -->
    <section
        id="page-survey-detail"
        class="page hidden"
    >
        <div class="page-header">
            <div>
                <h1 id="detail-survey-name">
                    アンケート詳細
                </h1>

                <div
                    class="subtext"
                    id="detail-survey-period"
                ></div>
            </div>

            <div class="editor-actions">
                <button
                    type="button"
                    class="btn"
                    id="detail-edit-button"
                >
                    編集
                </button>

                <button
                    type="button"
                    class="btn"
                    id="detail-back-button"
                >
                    一覧へ戻る
                </button>
            </div>
        </div>

        <div
            id="detail-notice"
            class="notice hidden"
            role="status"
            aria-live="polite"
        ></div>

        <div class="detail-summary">
            <div class="summary-box">
                <div class="summary-label">
                    状態
                </div>

                <div
                    class="summary-value"
                    id="detail-status"
                >
                    -
                </div>
            </div>

            <div class="summary-box">
                <div class="summary-label">
                    回答数
                </div>

                <div
                    class="summary-value"
                    id="detail-answer-count"
                >
                    0
                </div>
            </div>

            <div class="summary-box">
                <div class="summary-label">
                    対象者数
                </div>

                <div
                    class="summary-value"
                    id="detail-target-count"
                >
                    0
                </div>
            </div>

            <div class="summary-box">
                <div class="summary-label">
                    送信数
                </div>

                <div
                    class="summary-value"
                    id="detail-sent-count"
                >
                    0
                </div>
            </div>
        </div>

        <div class="detail-tabs">
            <button
                type="button"
                class="detail-tab-button active"
                data-detail-tab="summary"
            >
                概要
            </button>

            <button
                type="button"
                class="detail-tab-button"
                data-detail-tab="questions"
            >
                質問内容
            </button>

            <button
                type="button"
                class="detail-tab-button"
                data-detail-tab="responses"
            >
                回答状況
            </button>

            <button
                type="button"
                class="detail-tab-button"
                data-detail-tab="send"
            >
                回答依頼
            </button>
        </div>

        <div
            id="detail-tab-summary"
            class="detail-tab-content"
        >
            <div class="card">
                <div class="card-title">
                    アンケート概要
                </div>

                <div
                    id="detail-description"
                    class="subtext"
                ></div>
            </div>
        </div>

        <div
            id="detail-tab-questions"
            class="detail-tab-content hidden"
        >
            <div
                id="detail-question-content"
            ></div>
        </div>

        <div
            id="detail-tab-responses"
            class="detail-tab-content hidden"
        >
            <div class="card">
                <div class="card-title">
                    回答状況
                </div>

                <div
                    id="detail-response-content"
                >
                    回答状況を読み込んでいます。
                </div>
            </div>
        </div>

        <div
            id="detail-tab-send"
            class="detail-tab-content hidden"
        >
            <div class="send-layout">

                <div class="card">
                    <div class="card-title">
                        回答依頼
                    </div>

                    <div class="field">
                        <label for="send-target">
                            送信対象
                        </label>

                        <select id="send-target">
                            <option value="all">
                                顧客全員
                            </option>

                            <option value="selected">
                                選択した顧客
                            </option>
                        </select>
                    </div>

                    <div
                        class="selection-summary"
                        id="send-selection-summary"
                    >
                        顧客全員を対象にします。
                    </div>

                    <div class="field">
                        <label for="send-subject">
                            件名
                        </label>

                        <input
                            type="text"
                            id="send-subject"
                            maxlength="200"
                        >
                    </div>

                    <div class="field">
                        <label for="send-message">
                            本文
                        </label>

                        <textarea
                            id="send-message"
                            rows="12"
                        ></textarea>
                    </div>

                    <button
                        type="button"
                        class="btn btn-primary"
                        id="send-survey-button"
                    >
                        <span class="loading-spinner"></span>
                        回答依頼を送信
                    </button>
                </div>

                <div class="card">
                    <div class="card-title">
                        顧客選択
                    </div>

                    <div class="field">
                        <label for="customer-search-send">
                            顧客を検索
                        </label>

                        <input
                            type="text"
                            id="customer-search-send"
                            placeholder="会社名・氏名・メールアドレス"
                        >
                    </div>

                    <div
                        id="send-customer-list"
                    ></div>
                </div>

            </div>
        </div>
    </section>


    <!-- =====================================================
         顧客管理
    ====================================================== -->
    <section
        id="page-customers"
        class="page hidden"
    >
        <div class="page-header">
            <div>
                <h1>顧客管理</h1>

                <div class="subtext">
                    kintoneから取得した顧客情報を確認します。
                </div>
            </div>

            <button
                type="button"
                class="btn btn-primary"
                id="refresh-customers-button"
            >
                <span class="loading-spinner"></span>
                kintoneから更新
            </button>
        </div>

        <div
            id="customer-notice"
            class="notice hidden"
            role="status"
            aria-live="polite"
        ></div>

        <div class="card">
            <div class="status-line">
                <span
                    id="customer-status-dot"
                    class="status-dot"
                ></span>

                <span id="customer-status-text">
                    顧客情報を確認しています。
                </span>
            </div>

            <div class="field">
                <label for="customer-search">
                    顧客を検索
                </label>

                <input
                    type="text"
                    id="customer-search"
                    placeholder="顧客ID・会社名・氏名・メールアドレス"
                >
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>顧客ID</th>
                            <th>会社名</th>
                            <th>氏名</th>
                            <th>メールアドレス</th>
                        </tr>
                    </thead>

                    <tbody id="customer-list-body">
                        <tr>
                            <td colspan="4">
                                読み込み中です。
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>


    <!-- =====================================================
         システム設定
    ====================================================== -->
    <section
        id="page-settings"
        class="page hidden"
    >
        <div class="page-header">
            <div>
                <h1>システム設定</h1>

                <div class="subtext">
                    アンケート運営に必要な接続情報を設定します。
                </div>
            </div>
        </div>

        <div
            id="settings-notice"
            class="notice hidden"
            role="status"
            aria-live="polite"
        ></div>

        <div class="settings-tabs">
            <button
                type="button"
                class="settings-tab-button active"
                data-settings-tab="kintone"
            >
                kintone設定
            </button>

            <button
                type="button"
                class="settings-tab-button"
                data-settings-tab="mail"
            >
                メール設定
            </button>
        </div>


        <!-- kintone設定 -->
        <div
            id="settings-panel-kintone"
            class="settings-panel"
        >
            <div class="card">
                <div class="card-title">
                    kintone接続設定
                </div>

                <div class="field">
                    <label for="settings-kintone-domain">
                        kintoneドメイン
                    </label>

                    <input
                        type="text"
                        id="settings-kintone-domain"
                        placeholder="example"
                        autocomplete="off"
                    >

                    <div class="subtext">
                        https:// や .cybozu.com を付けても自動で整理します。
                    </div>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="settings-kintone-login">
                            ログイン名
                        </label>

                        <input
                            type="text"
                            id="settings-kintone-login"
                            autocomplete="username"
                        >
                    </div>

                    <div class="field">
                        <label for="settings-kintone-password">
                            パスワード
                        </label>

                        <input
                            type="password"
                            id="settings-kintone-password"
                            autocomplete="current-password"
                        >
                    </div>
                </div>

                <div class="field">
                    <label for="settings-kintone-app-id">
                        顧客アプリID
                    </label>

                    <input
                        type="text"
                        id="settings-kintone-app-id"
                        inputmode="numeric"
                    >
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="settings-kintone-id-field">
                            顧客IDフィールドコード
                        </label>

                        <input
                            type="text"
                            id="settings-kintone-id-field"
                        >
                    </div>

                    <div class="field">
                        <label for="settings-kintone-name-field">
                            顧客名フィールドコード
                        </label>

                        <input
                            type="text"
                            id="settings-kintone-name-field"
                        >
                    </div>
                </div>

                <div class="field">
                    <label for="settings-kintone-email-field">
                        メールアドレスフィールドコード
                    </label>

                    <input
                        type="text"
                        id="settings-kintone-email-field"
                    >
                </div>

                <div class="card-title">
                    プロキシ設定
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="settings-kintone-proxy-host">
                            プロキシホスト
                        </label>

                        <input
                            type="text"
                            id="settings-kintone-proxy-host"
                            placeholder="proxy.example.local"
                        >
                    </div>

                    <div class="field">
                        <label for="settings-kintone-proxy-port">
                            プロキシポート
                        </label>

                        <input
                            type="text"
                            id="settings-kintone-proxy-port"
                            inputmode="numeric"
                            placeholder="8080"
                        >
                    </div>
                </div>

                <div class="editor-actions">
                    <button
                        type="button"
                        class="btn btn-primary"
                        id="save-kintone-settings-button"
                    >
                        <span class="loading-spinner"></span>
                        設定を保存
                    </button>

                    <button
                        type="button"
                        class="btn"
                        id="test-kintone-button"
                    >
                        <span class="loading-spinner"></span>
                        接続テスト
                    </button>
                </div>

                <div
                    class="status-line"
                    style="margin-top:15px;"
                >
                    <span
                        id="kintone-status-dot"
                        class="status-dot"
                    ></span>

                    <span id="kintone-status-text">
                        未確認
                    </span>
                </div>
            </div>
        </div>


        <!-- メール設定 -->
        <div
            id="settings-panel-mail"
            class="settings-panel hidden"
        >
            <div class="card">
                <div class="card-title">
                    メール送信設定
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="settings-mail-server">
                            SMTPサーバー
                        </label>

                        <input
                            type="text"
                            id="settings-mail-server"
                        >
                    </div>

                    <div class="field">
                        <label for="settings-mail-port">
                            SMTPポート
                        </label>

                        <input
                            type="number"
                            id="settings-mail-port"
                            min="1"
                            max="65535"
                        >
                    </div>
                </div>

                <div class="field">
                    <label>
                        接続方式
                    </label>

                    <div class="radio-row">
                        <label>
                            <input
                                type="radio"
                                name="mail-connection-type"
                                value="tls"
                                checked
                            >
                            TLS
                        </label>

                        <label>
                            <input
                                type="radio"
                                name="mail-connection-type"
                                value="ssl"
                            >
                            SSL
                        </label>

                        <label>
                            <input
                                type="radio"
                                name="mail-connection-type"
                                value="none"
                            >
                            なし
                        </label>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="settings-mail-username">
                            SMTPユーザー名
                        </label>

                        <input
                            type="text"
                            id="settings-mail-username"
                            autocomplete="username"
                        >
                    </div>

                    <div class="field">
                        <label for="settings-mail-password">
                            SMTPパスワード
                        </label>

                        <input
                            type="password"
                            id="settings-mail-password"
                            autocomplete="current-password"
                        >
                    </div>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="settings-mail-from-email">
                            送信元メールアドレス
                        </label>

                        <input
                            type="email"
                            id="settings-mail-from-email"
                        >
                    </div>

                    <div class="field">
                        <label for="settings-mail-from-name">
                            送信元名称
                        </label>

                        <input
                            type="text"
                            id="settings-mail-from-name"
                        >
                    </div>
                </div>

                <button
                    type="button"
                    class="btn btn-primary"
                    id="save-mail-settings-button"
                >
                    <span class="loading-spinner"></span>
                    設定を保存
                </button>
            </div>
        </div>
    </section>

</main>


<!-- =========================================================
     モーダル
========================================================= -->

<div
    id="confirm-modal"
    class="modal-backdrop hidden"
    role="dialog"
    aria-modal="true"
    aria-labelledby="confirm-modal-title"
>
    <div class="modal">
        <div class="modal-header">
            <strong id="confirm-modal-title">
                確認
            </strong>

            <button
                type="button"
                class="btn btn-small"
                id="confirm-modal-close"
                aria-label="閉じる"
            >
                ×
            </button>
        </div>

        <div
            class="modal-body"
            id="confirm-modal-message"
        ></div>

        <div class="modal-footer">
            <button
                type="button"
                class="btn"
                id="confirm-modal-cancel"
            >
                キャンセル
            </button>

            <button
                type="button"
                class="btn btn-primary"
                id="confirm-modal-ok"
            >
                実行する
            </button>
        </div>
    </div>
</div>


<div
    id="toast"
    class="toast"
    role="status"
    aria-live="polite"
></div>


<script>
document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    /*
     * =========================================================
     * 基本設定
     * =========================================================
     */

    const csrfMeta =
        document.querySelector('meta[name="csrf-token"]');

    const apiPathMeta =
        document.querySelector('meta[name="api-path"]');

    const csrfToken =
        csrfMeta
            ? (csrfMeta.getAttribute('content') || '')
            : '';

    const apiPath =
        apiPathMeta
            ? (apiPathMeta.getAttribute('content') || 'index.php')
            : 'index.php';


    /*
     * =========================================================
     * アプリケーション状態
     * =========================================================
     */

    const state = {
        currentPage: 'dashboard',
        surveys: [],
        customers: [],
        currentSurvey: null,
        currentDetailTab: 'summary',
        currentSettingsTab: 'kintone',
        editingSurveyId: '',
        sendSelectedCustomerIds: new Set(),
        confirmResolver: null,
        dragSourceType: '',
        dragSourceGroupIndex: -1,
        dragSourceQuestionIndex: -1
    };


    /*
     * =========================================================
     * DOMユーティリティ
     * =========================================================
     */

    const getElement = (id) => {
        return document.getElementById(id);
    };

    const queryAll = (selector) => {
        return Array.from(
            document.querySelectorAll(selector)
        );
    };

    const setText = (element, value) => {
        if (!element) {
            return;
        }

        element.textContent =
            value === null ||
            value === undefined
                ? ''
                : String(value);
    };

    const showElement = (element) => {
        if (!element) {
            return;
        }

        element.classList.remove('hidden');
    };

    const hideElement = (element) => {
        if (!element) {
            return;
        }

        element.classList.add('hidden');
    };

    const setNotice = (
        element,
        message,
        type = ''
    ) => {
        if (!element) {
            return;
        }

        element.textContent = '';

        if (!message) {
            element.className =
                'notice hidden';

            return;
        }

        element.textContent = message;

        element.className =
            'notice';

        if (type) {
            element.classList.add(type);
        }
    };

    const setLoading = (
        button,
        loading
    ) => {
        if (!button) {
            return;
        }

        if (loading) {
            /*
             * 非同期通信開始前に即座にdisabled。
             */
            button.disabled = true;
            button.classList.add('is-loading');
        } else {
            button.disabled = false;
            button.classList.remove('is-loading');
        }
    };


    /*
     * =========================================================
     * HTMLエスケープ
     *
     * 原則textContentを利用するが、
     * HTMLテンプレート生成が必要な箇所では必ず使用する。
     * =========================================================
     */

    const escapeHtml = (value) => {
        const text =
            value === null ||
            value === undefined
                ? ''
                : String(value);

        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };


    /*
     * =========================================================
     * API通信
     * =========================================================
     */

    const apiGet = async (
        action,
        params = {}
    ) => {
        const url =
            new URL(
                apiPath,
                window.location.href
            );

        url.searchParams.set(
            'action',
            action
        );

        Object.keys(params).forEach((key) => {
            const value = params[key];

            if (
                value !== null &&
                value !== undefined
            ) {
                url.searchParams.set(
                    key,
                    String(value)
                );
            }
        });

        const response =
            await fetch(
                url.toString(),
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Accept':
                            'application/json'
                    }
                }
            );

        const data =
            await response.json();

        if (
            !response.ok ||
            !data ||
            data.success !== true
        ) {
            const message =
                data &&
                typeof data.message === 'string'
                    ? data.message
                    : '処理に失敗しました。';

            throw new Error(message);
        }

        return data;
    };


    const apiPost = async (
        action,
        payload = {}
    ) => {
        const url =
            new URL(
                apiPath,
                window.location.href
            );

        url.searchParams.set(
            'action',
            action
        );

        const response =
            await fetch(
                url.toString(),
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type':
                            'application/json; charset=UTF-8',
                        'Accept':
                            'application/json',
                        'X-CSRF-Token':
                            csrfToken
                    },
                    body:
                        JSON.stringify(
                            payload
                        )
                }
            );

        const data =
            await response.json();

        if (
            !response.ok ||
            !data ||
            data.success !== true
        ) {
            let message =
                data &&
                typeof data.message === 'string'
                    ? data.message
                    : '処理に失敗しました。';

            if (
                data &&
                data.errors &&
                typeof data.errors === 'object'
            ) {
                const details =
                    Object.values(
                        data.errors
                    )
                        .filter(
                            (item) =>
                                typeof item === 'string'
                        )
                        .join(' ');

                if (details) {
                    message +=
                        ' ' + details;
                }
            }

            throw new Error(message);
        }

        return data;
    };


    /*
     * =========================================================
     * 日付・表示用
     * =========================================================
     */

    const formatPeriod = (
        start,
        end
    ) => {
        const startText =
            start || '未設定';

        const endText =
            end || '未設定';

        return (
            startText +
            ' ～ ' +
            endText
        );
    };


    const statusLabel = (
        status
    ) => {
        switch (status) {
            case 'open':
                return '公開中';

            case 'end':
                return '終了';

            case 'draft':
            default:
                return '下書き';
        }
    };


    const statusClass = (
        status
    ) => {
        switch (status) {
            case 'open':
                return 'status-open';

            case 'end':
                return 'status-end';

            case 'draft':
            default:
                return 'status-draft';
        }
    };


    const questionTypeLabel = (
        type
    ) => {
        switch (type) {
            case 'single':
                return '単一選択';

            case 'multiple':
                return '複数選択';

            case 'free':
            default:
                return '自由記述';
        }
    };


    /*
     * =========================================================
     * トースト
     * =========================================================
     */

    let toastTimer = null;

    const showToast = (
        message
    ) => {
        const toast =
            getElement('toast');

        if (!toast) {
            return;
        }

        setText(
            toast,
            message
        );

        toast.classList.add('show');

        if (toastTimer) {
            clearTimeout(
                toastTimer
            );
        }

        toastTimer =
            setTimeout(() => {
                toast.classList.remove(
                    'show'
                );
            }, 2800);
    };


    /*
     * =========================================================
     * 確認ダイアログ
     * =========================================================
     */

    const closeConfirmModal = (
        result = false
    ) => {
        const modal =
            getElement('confirm-modal');

        if (modal) {
            modal.classList.add(
                'hidden'
            );
        }

        if (
            typeof state.confirmResolver ===
            'function'
        ) {
            const resolver =
                state.confirmResolver;

            state.confirmResolver =
                null;

            resolver(result);
        }
    };


    const confirmAction = (
        message,
        title = '確認'
    ) => {
        const modal =
            getElement('confirm-modal');

        const titleElement =
            getElement('confirm-modal-title');

        const messageElement =
            getElement('confirm-modal-message');

        if (
            !modal ||
            !titleElement ||
            !messageElement
        ) {
            return Promise.resolve(
                window.confirm(message)
            );
        }

        setText(
            titleElement,
            title
        );

        setText(
            messageElement,
            message
        );

        showElement(modal);

        return new Promise(
            (resolve) => {
                state.confirmResolver =
                    resolve;
            }
        );
    };


    /*
     * =========================================================
     * ページ切替
     * =========================================================
     */

    const pages = [
        'dashboard',
        'surveys',
        'survey-editor',
        'survey-detail',
        'customers',
        'settings'
    ];


    const showPage = (
        pageName
    ) => {
        pages.forEach((name) => {
            const page =
                getElement(
                    'page-' + name
                );

            if (!page) {
                return;
            }

            if (name === pageName) {
                showElement(page);
            } else {
                hideElement(page);
            }
        });

        state.currentPage =
            pageName;

        queryAll('.nav-button')
            .forEach((button) => {
                const page =
                    button.getAttribute(
                        'data-page'
                    );

                if (page === pageName) {
                    button.classList.add(
                        'active'
                    );
                } else {
                    button.classList.remove(
                        'active'
                    );
                }
            });

        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    };


    /*
     * =========================================================
     * アンケート一覧
     * =========================================================
     */

    const renderSurveyRows = (
        container,
        surveys
    ) => {
        if (!container) {
            return;
        }

        container.textContent = '';

        if (!surveys.length) {
            const row =
                document.createElement('tr');

            const cell =
                document.createElement('td');

            cell.colSpan = 7;
            cell.textContent =
                'アンケートはまだありません。';

            row.appendChild(cell);
            container.appendChild(row);

            return;
        }

        surveys.forEach((survey) => {
            if (!survey) {
                return;
            }

            const row =
                document.createElement('tr');

            const nameCell =
                document.createElement('td');

            const nameButton =
                document.createElement('button');

            nameButton.type =
                'button';

            nameButton.className =
                'btn btn-small';

            nameButton.textContent =
                survey.name || '名称未設定';

            nameButton.addEventListener(
                'click',
                () => {
                    openSurveyDetail(
                        survey.id
                    );
                }
            );

            nameCell.appendChild(
                nameButton
            );

            const statusCell =
                document.createElement('td');

            const status =
                document.createElement('span');

            status.className =
                'status-badge ' +
                statusClass(
                    survey.status
                );

            status.textContent =
                statusLabel(
                    survey.status
                );

            statusCell.appendChild(
                status
            );

            const periodCell =
                document.createElement('td');

            periodCell.textContent =
                formatPeriod(
                    survey.start,
                    survey.end
                );

            const answerCell =
                document.createElement('td');

            answerCell.textContent =
                String(
                    survey.answers || 0
                );

            const sentCell =
                document.createElement('td');

            sentCell.textContent =
                String(
                    survey.sent || 0
                );

            const updatedCell =
                document.createElement('td');

            updatedCell.textContent =
                survey.updated || '';

            const actionCell =
                document.createElement('td');

            const actions =
                document.createElement('div');

            actions.className =
                'table-actions';

            const detailButton =
                document.createElement('button');

            detailButton.type =
                'button';

            detailButton.className =
                'btn btn-small';

            detailButton.textContent =
                '詳細';

            detailButton.addEventListener(
                'click',
                () => {
                    openSurveyDetail(
                        survey.id
                    );
                }
            );

            actions.appendChild(
                detailButton
            );

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
                () => {
                    openSurveyEditor(
                        survey.id
                    );
                }
            );

            actions.appendChild(
                editButton
            );

            if (
                survey.status ===
                'draft'
            ) {
                const openButton =
                    document.createElement(
                        'button'
                    );

                openButton.type =
                    'button';

                openButton.className =
                    'btn btn-small btn-success';

                openButton.textContent =
                    '公開';

                openButton.addEventListener(
                    'click',
                    () => {
                        changeSurveyStatus(
                            survey.id,
                            'open'
                        );
                    }
                );

                actions.appendChild(
                    openButton
                );
            }

            if (
                survey.status ===
                'open'
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
                    () => {
                        changeSurveyStatus(
                            survey.id,
                            'end'
                        );
                    }
                );

                actions.appendChild(
                    endButton
                );
            }

            actionCell.appendChild(
                actions
            );

            row.appendChild(nameCell);
            row.appendChild(statusCell);
            row.appendChild(periodCell);
            row.appendChild(answerCell);
            row.appendChild(sentCell);
            row.appendChild(updatedCell);
            row.appendChild(actionCell);

            container.appendChild(row);
        });
    };


    const renderDashboardRows = (
        container,
        surveys
    ) => {
        if (!container) {
            return;
        }

        container.textContent = '';

        if (!surveys.length) {
            const row =
                document.createElement('tr');

            const cell =
                document.createElement('td');

            cell.colSpan = 5;
            cell.textContent =
                'アンケートはまだありません。';

            row.appendChild(cell);
            container.appendChild(row);

            return;
        }

        surveys
            .slice(0, 10)
            .forEach((survey) => {
                if (!survey) {
                    return;
                }

                const row =
                    document.createElement('tr');

                const nameCell =
                    document.createElement('td');

                const button =
                    document.createElement(
                        'button'
                    );

                button.type =
                    'button';

                button.className =
                    'btn btn-small';

                button.textContent =
                    survey.name ||
                    '名称未設定';

                button.addEventListener(
                    'click',
                    () => {
                        openSurveyDetail(
                            survey.id
                        );
                    }
                );

                nameCell.appendChild(
                    button
                );

                const statusCell =
                    document.createElement('td');

                const badge =
                    document.createElement('span');

                badge.className =
                    'status-badge ' +
                    statusClass(
                        survey.status
                    );

                badge.textContent =
                    statusLabel(
                        survey.status
                    );

                statusCell.appendChild(
                    badge
                );

                const periodCell =
                    document.createElement('td');

                periodCell.textContent =
                    formatPeriod(
                        survey.start,
                        survey.end
                    );

                const answerCell =
                    document.createElement('td');

                answerCell.textContent =
                    String(
                        survey.answers || 0
                    );

                const actionCell =
                    document.createElement('td');

                const detailButton =
                    document.createElement(
                        'button'
                    );

                detailButton.type =
                    'button';

                detailButton.className =
                    'btn btn-small';

                detailButton.textContent =
                    '詳細';

                detailButton.addEventListener(
                    'click',
                    () => {
                        openSurveyDetail(
                            survey.id
                        );
                    }
                );

                actionCell.appendChild(
                    detailButton
                );

                row.appendChild(
                    nameCell
                );

                row.appendChild(
                    statusCell
                );

                row.appendChild(
                    periodCell
                );

                row.appendChild(
                    answerCell
                );

                row.appendChild(
                    actionCell
                );

                container.appendChild(row);
            });
    };


    const updateDashboardSummary = () => {
        const surveyCount =
            getElement(
                'dashboard-survey-count'
            );

        const openCount =
            getElement(
                'dashboard-open-count'
            );

        const responseCount =
            getElement(
                'dashboard-response-count'
            );

        const customerCount =
            getElement(
                'dashboard-customer-count'
            );

        const surveys =
            Array.isArray(
                state.surveys
            )
                ? state.surveys
                : [];

        const openSurveys =
            surveys.filter(
                (survey) =>
                    survey &&
                    survey.status === 'open'
            );

        const responses =
            surveys.reduce(
                (total, survey) => {
                    return total +
                        Number(
                            survey &&
                            survey.answers
                                ? survey.answers
                                : 0
                        );
                },
                0
            );

        setText(
            surveyCount,
            surveys.length
        );

        setText(
            openCount,
            openSurveys.length
        );

        setText(
            responseCount,
            responses
        );

        setText(
            customerCount,
            Array.isArray(
                state.customers
            )
                ? state.customers.length
                : 0
        );
    };


    const loadSurveys = async () => {
        const listBody =
            getElement(
                'survey-list-body'
            );

        const dashboardBody =
            getElement(
                'dashboard-survey-list'
            );

        try {
            const result =
                await apiGet(
                    'get_surveys'
                );

            const surveys =
                result &&
                result.data &&
                Array.isArray(
                    result.data.surveys
                )
                    ? result.data.surveys
                    : [];

            state.surveys =
                surveys;

            renderSurveyRows(
                listBody,
                surveys
            );

            renderDashboardRows(
                dashboardBody,
                surveys
            );

            updateDashboardSummary();
        } catch (error) {
            const message =
                error instanceof Error
                    ? error.message
                    : 'アンケート一覧を取得できませんでした。';

            if (listBody) {
                listBody.textContent = '';

                const row =
                    document.createElement(
                        'tr'
                    );

                const cell =
                    document.createElement(
                        'td'
                    );

                cell.colSpan = 7;
                cell.textContent =
                    message;

                row.appendChild(cell);
                listBody.appendChild(row);
            }

            if (dashboardBody) {
                dashboardBody.textContent = '';

                const row =
                    document.createElement(
                        'tr'
                    );

                const cell =
                    document.createElement(
                        'td'
                    );

                cell.colSpan = 5;
                cell.textContent =
                    message;

                row.appendChild(cell);
                dashboardBody.appendChild(row);
            }
        }
    };


    /*
     * =========================================================
     * アンケート編集データ
     * =========================================================
     */

    const createNewQuestion = () => {
        return {
            id:
                'q_' +
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
    };


    const createNewGroup = () => {
        return {
            id:
                'g_' +
                Date.now() +
                '_' +
                Math.random()
                    .toString(16)
                    .slice(2),

            name: '新しいグループ',

            questions: [
                createNewQuestion()
            ]
        };
    };


    const createNewSurvey = () => {
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
                createNewGroup()
            ]
        };
    };


    const normalizeEditorSurvey = (
        survey
    ) => {
        if (
            !survey ||
            typeof survey !== 'object'
        ) {
            return createNewSurvey();
        }

        const groups =
            Array.isArray(
                survey.groups
            )
                ? survey.groups
                : [];

        return {
            id:
                typeof survey.id === 'string'
                    ? survey.id
                    : '',

            name:
                typeof survey.name === 'string'
                    ? survey.name
                    : '',

            description:
                typeof survey.description === 'string'
                    ? survey.description
                    : '',

            status:
                typeof survey.status === 'string'
                    ? survey.status
                    : 'draft',

            created:
                typeof survey.created === 'string'
                    ? survey.created
                    : '',

            start:
                typeof survey.start === 'string'
                    ? survey.start
                    : '',

            end:
                typeof survey.end === 'string'
                    ? survey.end
                    : '',

            answers:
                Number(
                    survey.answers || 0
                ),

            target:
                Number(
                    survey.target || 0
                ),

            sent:
                Number(
                    survey.sent || 0
                ),

            updated:
                typeof survey.updated === 'string'
                    ? survey.updated
                    : '',

            numbering:
                survey.numbering === 'group'
                    ? 'group'
                    : 'global',

            groups:
                groups.map(
                    (group) => ({
                        id:
                            typeof group.id === 'string'
                                ? group.id
                                : createNewGroup().id,

                        name:
                            typeof group.name === 'string'
                                ? group.name
                                : 'グループ',

                        questions:
                            Array.isArray(
                                group.questions
                            )
                                ? group.questions.map(
                                    (question) => ({
                                        id:
                                            typeof question.id === 'string'
                                                ? question.id
                                                : createNewQuestion().id,

                                        text:
                                            typeof question.text === 'string'
                                                ? question.text
                                                : '',

                                        type:
                                            [
                                                'free',
                                                'single',
                                                'multiple'
                                            ].includes(
                                                question.type
                                            )
                                                ? question.type
                                                : 'free',

                                        required:
                                            Boolean(
                                                question.required
                                            ),

                                        options:
                                            Array.isArray(
                                                question.options
                                            )
                                                ? question.options.map(
                                                    (option) => ({
                                                        text:
                                                            typeof option.text === 'string'
                                                                ? option.text
                                                                : '',

                                                        branch:
                                                            typeof option.branch === 'string'
                                                                ? option.branch
                                                                : ''
                                                    })
                                                )
                                                : []
                                    })
                                )
                                : []
                    })
                )
        };
    };


    /*
     * =========================================================
     * エディタ描画
     * =========================================================
     */

    const getAllQuestionItems = (
        survey
    ) => {
        const result = [];

        if (
            !survey ||
            !Array.isArray(
                survey.groups
            )
        ) {
            return result;
        }

        survey.groups.forEach(
            (group, groupIndex) => {
                if (
                    !group ||
                    !Array.isArray(
                        group.questions
                    )
                ) {
                    return;
                }

                group.questions.forEach(
                    (question, questionIndex) => {
                        if (!question) {
                            return;
                        }

                        result.push({
                            question,
                            groupIndex,
                            questionIndex
                        });
                    }
                );
            }
        );

        return result;
    };


    const getQuestionNumber = (
        survey,
        groupIndex,
        questionIndex
    ) => {
        if (
            survey &&
            survey.numbering === 'group'
        ) {
            return (
                'Q' +
                (groupIndex + 1) +
                '-' +
                (questionIndex + 1)
            );
        }

        let number = 0;

        const groups =
            survey &&
            Array.isArray(
                survey.groups
            )
                ? survey.groups
                : [];

        for (
            let gi = 0;
            gi < groups.length;
            gi++
        ) {
            const group =
                groups[gi];

            if (
                !group ||
                !Array.isArray(
                    group.questions
                )
            ) {
                continue;
            }

            for (
                let qi = 0;
                qi < group.questions.length;
                qi++
            ) {
                number++;

                if (
                    gi === groupIndex &&
                    qi === questionIndex
                ) {
                    return 'Q' + number;
                }
            }
        }

        return 'Q' + (
            number + 1
        );
    };


    const renderBranchOptions = (
        survey,
        currentQuestionId,
        selectedBranch
    ) => {
        const items =
            getAllQuestionItems(
                survey
            );

        const options = [
            {
                value: '',
                label: '分岐なし'
            }
        ];

        items.forEach(
            (item) => {
                if (
                    !item ||
                    !item.question
                ) {
                    return;
                }

                if (
                    item.question.id ===
                    currentQuestionId
                ) {
                    return;
                }

                options.push({
                    value:
                        item.question.id,
                    label:
                        getQuestionNumber(
                            survey,
                            item.groupIndex,
                            item.questionIndex
                        ) +
                        ' ' +
                        (
                            item.question.text ||
                            '質問'
                        )
                });
            }
        );

        return options.map(
            (option) => {
                const selected =
                    option.value ===
                    selectedBranch
                        ? ' selected'
                        : '';

                return (
                    '<option value="' +
                    escapeHtml(
                        option.value
                    ) +
                    '"' +
                    selected +
                    '>' +
                    escapeHtml(
                        option.label
                    ) +
                    '</option>'
                );
            }
        ).join('');
    };


    const renderQuestionOptions = (
        survey,
        groupIndex,
        questionIndex,
        question
    ) => {
        const container =
            document.createElement(
                'div'
            );

        container.className =
            'question-options';

        if (
            question.type !==
                'single' &&
            question.type !==
                'multiple'
        ) {
            return container;
        }

        const options =
            Array.isArray(
                question.options
            )
                ? question.options
                : [];

        if (!options.length) {
            const message =
                document.createElement(
                    'div'
                );

            message.className =
                'subtext';

            message.textContent =
                '選択肢を追加してください。';

            container.appendChild(
                message
            );
        }

        options.forEach(
            (option, optionIndex) => {
                if (!option) {
                    return;
                }

                const row =
                    document.createElement(
                        'div'
                    );

                row.className =
                    'option-row';

                const textInput =
                    document.createElement(
                        'input'
                    );

                textInput.type =
                    'text';

                textInput.placeholder =
                    '選択肢';

                textInput.value =
                    option.text || '';

                textInput.addEventListener(
                    'input',
                    (event) => {
                        const target =
                            event.target;

                        if (
                            !target ||
                            !state.currentSurvey
                        ) {
                            return;
                        }

                        const currentGroup =
                            state.currentSurvey
                                .groups[
                                    groupIndex
                                ];

                        if (
                            !currentGroup ||
                            !Array.isArray(
                                currentGroup.questions
                            )
                        ) {
                            return;
                        }

                        const currentQuestion =
                            currentGroup.questions[
                                questionIndex
                            ];

                        if (
                            !currentQuestion ||
                            !Array.isArray(
                                currentQuestion.options
                            )
                        ) {
                            return;
                        }

                        const currentOption =
                            currentQuestion
                                .options[
                                    optionIndex
                                ];

                        if (!currentOption) {
                            return;
                        }

                        currentOption.text =
                            target.value;
                    }
                );

                row.appendChild(
                    textInput
                );

                if (
                    question.type ===
                    'single'
                ) {
                    const branchSelect =
                        document.createElement(
                            'select'
                        );

                    branchSelect.innerHTML =
                        renderBranchOptions(
                            state.currentSurvey,
                            question.id,
                            option.branch || ''
                        );

                    branchSelect.addEventListener(
                        'change',
                        (event) => {
                            const target =
                                event.target;

                            if (
                                !target ||
                                !state.currentSurvey
                            ) {
                                return;
                            }

                            const group =
                                state.currentSurvey
                                    .groups[
                                        groupIndex
                                    ];

                            if (
                                !group ||
                                !Array.isArray(
                                    group.questions
                                )
                            ) {
                                return;
                            }

                            const currentQuestion =
                                group.questions[
                                    questionIndex
                                ];

                            if (
                                !currentQuestion ||
                                !Array.isArray(
                                    currentQuestion.options
                                )
                            ) {
                                return;
                            }

                            const currentOption =
                                currentQuestion.options[
                                    optionIndex
                                ];

                            if (!currentOption) {
                                return;
                            }

                            currentOption.branch =
                                target.value;
                        }
                    );

                    row.appendChild(
                        branchSelect
                    );
                }

                const deleteButton =
                    document.createElement(
                        'button'
                    );

                deleteButton.type =
                    'button';

                deleteButton.className =
                    'btn btn-small';

                deleteButton.textContent =
                    '削除';

                deleteButton.addEventListener(
                    'click',
                    () => {
                        const group =
                            state.currentSurvey &&
                            state.currentSurvey.groups[
                                groupIndex
                            ];

                        if (
                            !group ||
                            !Array.isArray(
                                group.questions
                            )
                        ) {
                            return;
                        }

                        const currentQuestion =
                            group.questions[
                                questionIndex
                            ];

                        if (
                            !currentQuestion ||
                            !Array.isArray(
                                currentQuestion.options
                            )
                        ) {
                            return;
                        }

                        currentQuestion.options.splice(
                            optionIndex,
                            1
                        );

                        renderEditor();
                    }
                );

                row.appendChild(
                    deleteButton
                );

                container.appendChild(
                    row
                );
            }
        );

        const addButton =
            document.createElement(
                'button'
            );

        addButton.type =
            'button';

        addButton.className =
            'btn btn-small';

        addButton.textContent =
            '＋ 選択肢を追加';

        addButton.addEventListener(
            'click',
            () => {
                const group =
                    state.currentSurvey &&
                    state.currentSurvey.groups[
                        groupIndex
                    ];

                if (
                    !group ||
                    !Array.isArray(
                        group.questions
                    )
                ) {
                    return;
                }

                const currentQuestion =
                    group.questions[
                        questionIndex
                    ];

                if (
                    !currentQuestion ||
                    !Array.isArray(
                        currentQuestion.options
                    )
                ) {
                    return;
                }

                currentQuestion.options.push({
                    text: '',
                    branch: ''
                });

                renderEditor();
            }
        );

        container.appendChild(
            addButton
        );

        return container;
    };


    const renderQuestionCard = (
        survey,
        groupIndex,
        questionIndex,
        question
    ) => {
        const card =
            document.createElement(
                'div'
            );

        card.className =
            'question-card';

        card.draggable = true;

        card.dataset.groupIndex =
            String(groupIndex);

        card.dataset.questionIndex =
            String(questionIndex);

        card.addEventListener(
            'dragstart',
            (event) => {
                state.dragSourceType =
                    'question';

                state.dragSourceGroupIndex =
                    groupIndex;

                state.dragSourceQuestionIndex =
                    questionIndex;

                if (
                    event.dataTransfer
                ) {
                    event.dataTransfer.effectAllowed =
                        'move';
                }

                card.classList.add(
                    'dragging'
                );
            }
        );

        card.addEventListener(
            'dragend',
            () => {
                card.classList.remove(
                    'dragging'
                );

                queryAll(
                    '.question-card.drag-over'
                ).forEach(
                    (element) => {
                        element.classList.remove(
                            'drag-over'
                        );
                    }
                );
            }
        );

        card.addEventListener(
            'dragover',
            (event) => {
                if (
                    state.dragSourceType !==
                    'question'
                ) {
                    return;
                }

                event.preventDefault();

                card.classList.add(
                    'drag-over'
                );
            }
        );

        card.addEventListener(
            'dragleave',
            () => {
                card.classList.remove(
                    'drag-over'
                );
            }
        );

        card.addEventListener(
            'drop',
            (event) => {
                event.preventDefault();

                card.classList.remove(
                    'drag-over'
                );

                if (
                    !state.currentSurvey ||
                    state.dragSourceType !==
                    'question'
                ) {
                    return;
                }

                const fromGroup =
                    state.dragSourceGroupIndex;

                const fromQuestion =
                    state.dragSourceQuestionIndex;

                const toGroup =
                    groupIndex;

                const toQuestion =
                    questionIndex;

                if (
                    fromGroup < 0 ||
                    fromQuestion < 0 ||
                    toGroup < 0 ||
                    toQuestion < 0
                ) {
                    return;
                }

                const sourceGroup =
                    state.currentSurvey.groups[
                        fromGroup
                    ];

                const targetGroup =
                    state.currentSurvey.groups[
                        toGroup
                    ];

                if (
                    !sourceGroup ||
                    !targetGroup ||
                    !Array.isArray(
                        sourceGroup.questions
                    ) ||
                    !Array.isArray(
                        targetGroup.questions
                    )
                ) {
                    return;
                }

                const moved =
                    sourceGroup.questions.splice(
                        fromQuestion,
                        1
                    )[0];

                if (!moved) {
                    return;
                }

                let insertIndex =
                    toQuestion;

                if (
                    fromGroup === toGroup &&
                    fromQuestion < toQuestion
                ) {
                    insertIndex--;
                }

                if (
                    insertIndex < 0
                ) {
                    insertIndex = 0;
                }

                if (
                    insertIndex >
                    targetGroup.questions.length
                ) {
                    insertIndex =
                        targetGroup.questions.length;
                }

                targetGroup.questions.splice(
                    insertIndex,
                    0,
                    moved
                );

                renderEditor();
            }
        );


        const head =
            document.createElement(
                'div'
            );

        head.className =
            'question-head';


        const number =
            document.createElement(
                'div'
            );

        number.className =
            'question-number';

        number.textContent =
            getQuestionNumber(
                survey,
                groupIndex,
                questionIndex
            );

        head.appendChild(
            number
        );


        const title =
            document.createElement(
                'div'
            );

        title.className =
            'question-title';

        const titleInput =
            document.createElement(
                'input'
            );

        titleInput.type =
            'text';

        titleInput.placeholder =
            '質問文を入力してください';

        titleInput.value =
            question.text || '';

        titleInput.addEventListener(
            'input',
            (event) => {
                const target =
                    event.target;

                if (
                    !target ||
                    !state.currentSurvey
                ) {
                    return;
                }

                const group =
                    state.currentSurvey.groups[
                        groupIndex
                    ];

                if (
                    !group ||
                    !Array.isArray(
                        group.questions
                    )
                ) {
                    return;
                }

                const currentQuestion =
                    group.questions[
                        questionIndex
                    ];

                if (!currentQuestion) {
                    return;
                }

                currentQuestion.text =
                    target.value;
            }
        );

        title.appendChild(
            titleInput
        );

        head.appendChild(
            title
        );


        const tools =
            document.createElement(
                'div'
            );

        tools.className =
            'question-tools';

        const typeSelect =
            document.createElement(
                'select'
            );

        [
            ['free', '自由記述'],
            ['single', '単一選択'],
            ['multiple', '複数選択']
        ].forEach(
            ([value, label]) => {
                const option =
                    document.createElement(
                        'option'
                    );

                option.value =
                    value;

                option.textContent =
                    label;

                if (
                    question.type ===
                    value
                ) {
                    option.selected =
                        true;
                }

                typeSelect.appendChild(
                    option
                );
            }
        );

        typeSelect.addEventListener(
            'change',
            (event) => {
                const target =
                    event.target;

                if (
                    !target ||
                    !state.currentSurvey
                ) {
                    return;
                }

                const group =
                    state.currentSurvey.groups[
                        groupIndex
                    ];

                if (
                    !group ||
                    !Array.isArray(
                        group.questions
                    )
                ) {
                    return;
                }

                const currentQuestion =
                    group.questions[
                        questionIndex
                    ];

                if (!currentQuestion) {
                    return;
                }

                currentQuestion.type =
                    target.value;

                if (
                    currentQuestion.type ===
                    'free'
                ) {
                    currentQuestion.options =
                        [];
                } else if (
                    !Array.isArray(
                        currentQuestion.options
                    )
                ) {
                    currentQuestion.options =
                        [
                            {
                                text: '',
                                branch: ''
                            }
                        ];
                }

                renderEditor();
            }
        );

        tools.appendChild(
            typeSelect
        );


        const deleteQuestionButton =
            document.createElement(
                'button'
            );

        deleteQuestionButton.type =
            'button';

        deleteQuestionButton.className =
            'btn btn-small';

        deleteQuestionButton.textContent =
            '削除';

        deleteQuestionButton.addEventListener(
            'click',
            async () => {
                const confirmed =
                    await confirmAction(
                        'この質問を削除しますか？',
                        '質問の削除'
                    );

                if (!confirmed) {
                    return;
                }

                if (
                    !state.currentSurvey
                ) {
                    return;
                }

                const group =
                    state.currentSurvey.groups[
                        groupIndex
                    ];

                if (
                    !group ||
                    !Array.isArray(
                        group.questions
                    )
                ) {
                    return;
                }

                group.questions.splice(
                    questionIndex,
                    1
                );

                renderEditor();
            }
        );

        tools.appendChild(
            deleteQuestionButton
        );

        head.appendChild(
            tools
        );

        card.appendChild(
            head
        );


        const meta =
            document.createElement(
                'div'
            );

        meta.className =
            'question-meta';

        const requiredLabel =
            document.createElement(
                'label'
            );

        const requiredCheckbox =
            document.createElement(
                'input'
            );

        requiredCheckbox.type =
            'checkbox';

        requiredCheckbox.checked =
            Boolean(
                question.required
            );

        requiredCheckbox.addEventListener(
            'change',
            (event) => {
                const target =
                    event.target;

                if (
                    !target ||
                    !state.currentSurvey
                ) {
                    return;
                }

                const group =
                    state.currentSurvey.groups[
                        groupIndex
                    ];

                if (
                    !group ||
                    !Array.isArray(
                        group.questions
                    )
                ) {
                    return;
                }

                const currentQuestion =
                    group.questions[
                        questionIndex
                    ];

                if (!currentQuestion) {
                    return;
                }

                currentQuestion.required =
                    Boolean(
                        target.checked
                    );
            }
        );

        requiredLabel.appendChild(
            requiredCheckbox
        );

        const requiredText =
            document.createTextNode(
                '必須回答'
            );

        requiredLabel.appendChild(
            requiredText
        );

        meta.appendChild(
            requiredLabel
        );

        card.appendChild(
            meta
        );


        const options =
            renderQuestionOptions(
                survey,
                groupIndex,
                questionIndex,
                question
            );

        card.appendChild(
            options
        );

        return card;
    };


    const renderGroupCard = (
        survey,
        groupIndex,
        group
    ) => {
        const card =
            document.createElement(
                'div'
            );

        card.className =
            'group-card';

        card.draggable = true;

        card.dataset.groupIndex =
            String(groupIndex);

        card.addEventListener(
            'dragstart',
            (event) => {
                state.dragSourceType =
                    'group';

                state.dragSourceGroupIndex =
                    groupIndex;

                state.dragSourceQuestionIndex =
                    -1;

                if (
                    event.dataTransfer
                ) {
                    event.dataTransfer.effectAllowed =
                        'move';
                }

                card.classList.add(
                    'dragging'
                );
            }
        );

        card.addEventListener(
            'dragend',
            () => {
                card.classList.remove(
                    'dragging'
                );

                queryAll(
                    '.group-card.drag-over'
                ).forEach(
                    (element) => {
                        element.classList.remove(
                            'drag-over'
                        );
                    }
                );
            }
        );

        card.addEventListener(
            'dragover',
            (event) => {
                if (
                    state.dragSourceType !==
                    'group'
                ) {
                    return;
                }

                event.preventDefault();

                card.classList.add(
                    'drag-over'
                );
            }
        );

        card.addEventListener(
            'dragleave',
            () => {
                card.classList.remove(
                    'drag-over'
                );
            }
        );

        card.addEventListener(
            'drop',
            (event) => {
                event.preventDefault();

                card.classList.remove(
                    'drag-over'
                );

                if (
                    !state.currentSurvey ||
                    state.dragSourceType !==
                    'group'
                ) {
                    return;
                }

                const fromIndex =
                    state.dragSourceGroupIndex;

                const toIndex =
                    groupIndex;

                if (
                    fromIndex < 0 ||
                    toIndex < 0 ||
                    fromIndex === toIndex
                ) {
                    return;
                }

                const groups =
                    state.currentSurvey.groups;

                if (
                    !Array.isArray(groups)
                ) {
                    return;
                }

                const moved =
                    groups.splice(
                        fromIndex,
                        1
                    )[0];

                if (!moved) {
                    return;
                }

                let insertIndex =
                    toIndex;

                if (
                    fromIndex < toIndex
                ) {
                    insertIndex--;
                }

                if (insertIndex < 0) {
                    insertIndex = 0;
                }

                if (
                    insertIndex >
                    groups.length
                ) {
                    insertIndex =
                        groups.length;
                }

                groups.splice(
                    insertIndex,
                    0,
                    moved
                );

                renderEditor();
            }
        );


        const header =
            document.createElement(
                'div'
            );

        header.className =
            'group-header';


        const handle =
            document.createElement(
                'span'
            );

        handle.className =
            'drag-handle';

        handle.textContent =
            '☰';

        handle.setAttribute(
            'aria-label',
            'グループを並べ替える'
        );

        header.appendChild(
            handle
        );


        const title =
            document.createElement(
                'div'
            );

        title.className =
            'group-title';

        const input =
            document.createElement(
                'input'
            );

        input.type =
            'text';

        input.value =
            group.name || '';

        input.placeholder =
            'グループ名';

        input.addEventListener(
            'input',
            (event) => {
                const target =
                    event.target;

                if (
                    !target ||
                    !state.currentSurvey
                ) {
                    return;
                }

                const currentGroup =
                    state.currentSurvey.groups[
                        groupIndex
                    ];

                if (!currentGroup) {
                    return;
                }

                currentGroup.name =
                    target.value;
            }
        );

        title.appendChild(
            input
        );

        header.appendChild(
            title
        );


        const actions =
            document.createElement(
                'div'
            );

        actions.className =
            'group-actions';


        const addQuestionButton =
            document.createElement(
                'button'
            );

        addQuestionButton.type =
            'button';

        addQuestionButton.className =
            'btn btn-small';

        addQuestionButton.textContent =
            '＋ 質問';

        addQuestionButton.addEventListener(
            'click',
            () => {
                if (
                    !state.currentSurvey
                ) {
                    return;
                }

                const currentGroup =
                    state.currentSurvey.groups[
                        groupIndex
                    ];

                if (
                    !currentGroup ||
                    !Array.isArray(
                        currentGroup.questions
                    )
                ) {
                    return;
                }

                currentGroup.questions.push(
                    createNewQuestion()
                );

                renderEditor();
            }
        );

        actions.appendChild(
            addQuestionButton
        );


        const deleteGroupButton =
            document.createElement(
                'button'
            );

        deleteGroupButton.type =
            'button';

        deleteGroupButton.className =
            'btn btn-small';

        deleteGroupButton.textContent =
            'グループ削除';

        deleteGroupButton.addEventListener(
            'click',
            async () => {
                if (
                    !state.currentSurvey
                ) {
                    return;
                }

                if (
                    state.currentSurvey.groups
                        .length <= 1
                ) {
                    showToast(
                        'グループは1つ以上必要です。'
                    );

                    return;
                }

                const confirmed =
                    await confirmAction(
                        'この質問グループを削除しますか？',
                        'グループの削除'
                    );

                if (!confirmed) {
                    return;
                }

                state.currentSurvey.groups.splice(
                    groupIndex,
                    1
                );

                renderEditor();
            }
        );

        actions.appendChild(
            deleteGroupButton
        );

        header.appendChild(
            actions
        );

        card.appendChild(
            header
        );


        const questions =
            Array.isArray(
                group.questions
            )
                ? group.questions
                : [];

        questions.forEach(
            (question, questionIndex) => {
                if (!question) {
                    return;
                }

                const questionCard =
                    renderQuestionCard(
                        survey,
                        groupIndex,
                        questionIndex,
                        question
                    );

                card.appendChild(
                    questionCard
                );
            }
        );


        const addArea =
            document.createElement(
                'div'
            );

        addArea.className =
            'add-question-area';

        const addButton =
            document.createElement(
                'button'
            );

        addButton.type =
            'button';

        addButton.className =
            'btn btn-small';

        addButton.textContent =
            '＋ 質問を追加';

        addButton.addEventListener(
            'click',
            () => {
                if (
                    !state.currentSurvey
                ) {
                    return;
                }

                const currentGroup =
                    state.currentSurvey.groups[
                        groupIndex
                    ];

                if (
                    !currentGroup ||
                    !Array.isArray(
                        currentGroup.questions
                    )
                ) {
                    return;
                }

                currentGroup.questions.push(
                    createNewQuestion()
                );

                renderEditor();
            }
        );

        addArea.appendChild(
            addButton
        );

        card.appendChild(
            addArea
        );

        return card;
    };


    const renderEditor = () => {
        const container =
            getElement(
                'survey-groups'
            );

        if (!container) {
            return;
        }

        if (
            !state.currentSurvey
        ) {
            container.textContent = '';

            return;
        }

        const survey =
            state.currentSurvey;

        container.textContent = '';

        const groups =
            Array.isArray(
                survey.groups
            )
                ? survey.groups
                : [];

        if (!groups.length) {
            const message =
                document.createElement(
                    'div'
                );

            message.className =
                'notice warning';

            message.textContent =
                '質問グループを追加してください。';

            container.appendChild(
                message
            );

            return;
        }

        groups.forEach(
            (group, groupIndex) => {
                if (!group) {
                    return;
                }

                const groupCard =
                    renderGroupCard(
                        survey,
                        groupIndex,
                        group
                    );

                container.appendChild(
                    groupCard
                );
            }
        );
    };


    const fillEditorBasicFields = () => {
        const survey =
            state.currentSurvey;

        if (!survey) {
            return;
        }

        const name =
            getElement(
                'survey-name'
            );

        const description =
            getElement(
                'survey-description'
            );

        const numbering =
            getElement(
                'survey-numbering'
            );

        const start =
            getElement(
                'survey-start'
            );

        const end =
            getElement(
                'survey-end'
            );

        const title =
            getElement(
                'editor-page-title'
            );

        const subtext =
            getElement(
                'editor-page-subtext'
            );

        const deleteButton =
            getElement(
                'delete-survey-button'
            );

        if (name) {
            name.value =
                survey.name || '';
        }

        if (description) {
            description.value =
                survey.description || '';
        }

        if (numbering) {
            numbering.value =
                survey.numbering === 'group'
                    ? 'group'
                    : 'global';
        }

        if (start) {
            start.value =
                survey.start || '';
        }

        if (end) {
            end.value =
                survey.end || '';
        }

        if (title) {
            title.textContent =
                survey.id
                    ? 'アンケートを編集'
                    : '新しいアンケート';
        }

        if (subtext) {
            subtext.textContent =
                survey.id
                    ? 'アンケートの内容を変更できます。'
                    : 'アンケートの内容を設定してください。';
        }

        if (deleteButton) {
            if (survey.id) {
                showElement(
                    deleteButton
                );
            } else {
                hideElement(
                    deleteButton
                );
            }
        }
    };


    const openSurveyEditor = async (
        surveyId = ''
    ) => {
        setNotice(
            getElement(
                'editor-notice'
            ),
            ''
        );

        if (surveyId) {
            try {
                const result =
                    await apiGet(
                        'get_survey',
                        {
                            id: surveyId
                        }
                    );

                const survey =
                    result &&
                    result.data &&
                    result.data.survey
                        ? result.data.survey
                        : null;

                if (!survey) {
                    throw new Error(
                        'アンケートが見つかりません。'
                    );
                }

                state.currentSurvey =
                    normalizeEditorSurvey(
                        survey
                    );

                state.editingSurveyId =
                    surveyId;
            } catch (error) {
                showToast(
                    error instanceof Error
                        ? error.message
                        : 'アンケートを取得できませんでした。'
                );

                return;
            }
        } else {
            state.currentSurvey =
                createNewSurvey();

            state.editingSurveyId =
                '';
        }

        fillEditorBasicFields();
        renderEditor();

        showPage(
            'survey-editor'
        );
    };


    const collectEditorBasicFields = () => {
        if (
            !state.currentSurvey
        ) {
            return;
        }

        const name =
            getElement(
                'survey-name'
            );

        const description =
            getElement(
                'survey-description'
            );

        const numbering =
            getElement(
                'survey-numbering'
            );

        const start =
            getElement(
                'survey-start'
            );

        const end =
            getElement(
                'survey-end'
            );

        state.currentSurvey.name =
            name
                ? name.value.trim()
                : '';

        state.currentSurvey.description =
            description
                ? description.value
                : '';

        state.currentSurvey.numbering =
            numbering &&
            numbering.value === 'group'
                ? 'group'
                : 'global';

        state.currentSurvey.start =
            start
                ? start.value
                : '';

        state.currentSurvey.end =
            end
                ? end.value
                : '';
    };


    const saveSurvey = async (
        button
    ) => {
        /*
         * ボタンを押した瞬間にdisabledと
         * ローディング表示を設定してから通信。
         */
        if (!button) {
            return;
        }

        setLoading(
            button,
            true
        );

        try {
            collectEditorBasicFields();

            if (
                !state.currentSurvey
            ) {
                throw new Error(
                    'アンケート情報がありません。'
                );
            }

            const result =
                await apiPost(
                    'save_survey',
                    {
                        survey:
                            state.currentSurvey
                    }
                );

            const saved =
                result &&
                result.data &&
                result.data.survey
                    ? result.data.survey
                    : null;

            if (saved) {
                state.currentSurvey =
                    normalizeEditorSurvey(
                        saved
                    );
            }

            await loadSurveys();

            showToast(
                'アンケートを保存しました。'
            );

            showPage(
                'surveys'
            );
        } catch (error) {
            setNotice(
                getElement(
                    'editor-notice'
                ),
                error instanceof Error
                    ? error.message
                    : 'アンケートを保存できませんでした。',
                'error'
            );

            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        } finally {
            setLoading(
                button,
                false
            );
        }
    };


    const deleteCurrentSurvey = async (
        button
    ) => {
        if (!button) {
            return;
        }

        setLoading(
            button,
            true
        );

        try {
            if (
                !state.currentSurvey ||
                !state.currentSurvey.id
            ) {
                return;
            }

            const confirmed =
                await confirmAction(
                    'このアンケートを削除しますか？',
                    'アンケートの削除'
                );

            if (!confirmed) {
                return;
            }

            await apiPost(
                'delete_survey',
                {
                    id:
                        state.currentSurvey.id
                }
            );

            await loadSurveys();

            showToast(
                'アンケートを削除しました。'
            );

            showPage(
                'surveys'
            );
        } catch (error) {
            setNotice(
                getElement(
                    'editor-notice'
                ),
                error instanceof Error
                    ? error.message
                    : 'アンケートを削除できませんでした。',
                'error'
            );
        } finally {
            setLoading(
                button,
                false
            );
        }
    };


    const changeSurveyStatus = async (
        surveyId,
        status
    ) => {
        const actionLabel =
            status === 'open'
                ? '公開'
                : status === 'end'
                    ? '終了'
                    : '下書き';

        const confirmed =
            await confirmAction(
                'アンケートを「' +
                actionLabel +
                '」に変更しますか？',
                '公開状態の変更'
            );

        if (!confirmed) {
            return;
        }

        try {
            await apiPost(
                'change_survey_status',
                {
                    id: surveyId,
                    status: status
                }
            );

            await loadSurveys();

            showToast(
                'アンケートの状態を変更しました。'
            );
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : '状態を変更できませんでした。'
            );
        }
    };


    /*
     * =========================================================
     * アンケート詳細
     * =========================================================
     */

    const openSurveyDetail = async (
        surveyId
    ) => {
        if (!surveyId) {
            return;
        }

        try {
            const result =
                await apiGet(
                    'get_survey',
                    {
                        id: surveyId
                    }
                );

            const survey =
                result &&
                result.data &&
                result.data.survey
                    ? result.data.survey
                    : null;

            if (!survey) {
                throw new Error(
                    'アンケートが見つかりません。'
                );
            }

            state.currentSurvey =
                normalizeEditorSurvey(
                    survey
                );

            state.editingSurveyId =
                surveyId;

            state.currentDetailTab =
                'summary';

            renderSurveyDetail();

            showPage(
                'survey-detail'
            );
        } catch (error) {
            showToast(
                error instanceof Error
                    ? error.message
                    : 'アンケート詳細を取得できませんでした。'
            );
        }
    };


    const renderSurveyDetail = () => {
        const survey =
            state.currentSurvey;

        if (!survey) {
            return;
        }

        setText(
            getElement(
                'detail-survey-name'
            ),
            survey.name ||
                '名称未設定'
        );

        setText(
            getElement(
                'detail-survey-period'
            ),
            formatPeriod(
                survey.start,
                survey.end
            )
        );

        setText(
            getElement(
                'detail-answer-count'
            ),
            survey.answers || 0
        );

        setText(
            getElement(
                'detail-target-count'
            ),
            survey.target || 0
        );

        setText(
            getElement(
                'detail-sent-count'
            ),
            survey.sent || 0
        );

        const statusElement =
            getElement(
                'detail-status'
            );

        if (statusElement) {
            statusElement.textContent =
                statusLabel(
                    survey.status
                );

            statusElement.className =
                'status-badge ' +
                statusClass(
                    survey.status
                );
        }

        setText(
            getElement(
                'detail-description'
            ),
            survey.description ||
                '説明は設定されていません。'
        );

        renderDetailQuestions();

        setDetailTab(
            state.currentDetailTab
        );

        prepareSendForm();
    };


    const renderDetailQuestions = () => {
        const container =
            getElement(
                'detail-question-content'
            );

        if (!container) {
            return;
        }

        container.textContent = '';

        const survey =
            state.currentSurvey;

        if (!survey) {
            return;
        }

        const groups =
            Array.isArray(
                survey.groups
            )
                ? survey.groups
                : [];

        if (!groups.length) {
            const notice =
                document.createElement(
                    'div'
                );

            notice.className =
                'notice';

            notice.textContent =
                '質問は登録されていません。';

            container.appendChild(
                notice
            );

            return;
        }

        groups.forEach(
            (group, groupIndex) => {
                if (!group) {
                    return;
                }

                const card =
                    document.createElement(
                        'div'
                    );

                card.className =
                    'card';

                const title =
                    document.createElement(
                        'div'
                    );

                title.className =
                    'card-title';

                title.textContent =
                    group.name ||
                    'グループ';

                card.appendChild(
                    title
                );

                const questions =
                    Array.isArray(
                        group.questions
                    )
                        ? group.questions
                        : [];

                questions.forEach(
                    (
                        question,
                        questionIndex
                    ) => {
                        if (!question) {
                            return;
                        }

                        const item =
                            document.createElement(
                                'div'
                            );

                        item.className =
                            'result-item';

                        const questionTitle =
                            document.createElement(
                                'div'
                            );

                        questionTitle.className =
                            'result-question';

                        questionTitle.textContent =
                            getQuestionNumber(
                                survey,
                                groupIndex,
                                questionIndex
                            ) +
                            ' ' +
                            (
                                question.text ||
                                '質問'
                            );

                        item.appendChild(
                            questionTitle
                        );

                        const type =
                            document.createElement(
                                'div'
                            );

                        type.className =
                            'subtext';

                        type.textContent =
                            questionTypeLabel(
                                question.type
                            ) +
                            (
                                question.required
                                    ? ' ・ 必須'
                                    : ' ・ 任意'
                            );

                        item.appendChild(
                            type
                        );

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

                            options.forEach(
                                (option) => {
                                    if (!option) {
                                        return;
                                    }

                                    const row =
                                        document.createElement(
                                            'div'
                                        );

                                    row.className =
                                        'subtext';

                                    row.textContent =
                                        '・ ' +
                                        (
                                            option.text ||
                                            '選択肢'
                                        );

                                    if (
                                        option.branch
                                    ) {
                                        row.textContent +=
                                            ' → ' +
                                            option.branch;
                                    }

                                    item.appendChild(
                                        row
                                    );
                                }
                            );
                        }

                        card.appendChild(
                            item
                        );
                    }
                );

                container.appendChild(
                    card
                );
            }
        );
    };


    const setDetailTab = (
        tab
    ) => {
        const validTabs = [
            'summary',
            'questions',
            'responses',
            'send'
        ];

        if (
            !validTabs.includes(tab)
        ) {
            tab = 'summary';
        }

        state.currentDetailTab =
            tab;

        queryAll(
            '.detail-tab-button'
        ).forEach(
            (button) => {
                const buttonTab =
                    button.getAttribute(
                        'data-detail-tab'
                    );

                if (
                    buttonTab === tab
                ) {
                    button.classList.add(
                        'active'
                    );
                } else {
                    button.classList.remove(
                        'active'
                    );
                }
            }
        );

        validTabs.forEach(
            (name) => {
                const panel =
                    getElement(
                        'detail-tab-' +
                        name
                    );

                if (!panel) {
                    return;
                }

                if (name === tab) {
                    showElement(panel);
                } else {
                    hideElement(panel);
                }
            }
        );

        if (
            tab === 'responses' &&
            state.currentSurvey
        ) {
            loadResponses(
                state.currentSurvey.id
            );
        }
    };


    const loadResponses = async (
        surveyId
    ) => {
        const container =
            getElement(
                'detail-response-content'
            );

        if (!container) {
            return;
        }

        container.textContent =
            '回答状況を読み込んでいます。';

        try {
            const result =
                await apiGet(
                    'get_responses',
                    {
                        survey_id:
                            surveyId
                    }
                );

            const responses =
                result &&
                result.data &&
                Array.isArray(
                    result.data.responses
                )
                    ? result.data.responses
                    : [];

            container.textContent = '';

            if (!responses.length) {
                const notice =
                    document.createElement(
                        'div'
                    );

                notice.className =
                    'notice';

                notice.textContent =
                    '回答はまだありません。';

                container.appendChild(
                    notice
                );

                return;
            }

            const tableWrap =
                document.createElement(
                    'div'
                );

            tableWrap.className =
                'table-wrap';

            const table =
                document.createElement(
                    'table'
                );

            table.className =
                'table';

            const thead =
                document.createElement(
                    'thead'
                );

            const headerRow =
                document.createElement(
                    'tr'
                );

            [
                '回答日時',
                '顧客',
                '状態'
            ].forEach(
                (label) => {
                    const th =
                        document.createElement(
                            'th'
                        );

                    th.textContent =
                        label;

                    headerRow.appendChild(
                        th
                    );
                }
            );

            thead.appendChild(
                headerRow
            );

            table.appendChild(
                thead
            );

            const tbody =
                document.createElement(
                    'tbody'
                );

            responses.forEach(
                (response) => {
                    if (!response) {
                        return;
                    }

                    const row =
                        document.createElement(
                            'tr'
                        );

                    const dateCell =
                        document.createElement(
                            'td'
                        );

                    dateCell.textContent =
                        response.created_at ||
                        '';

                    const customerCell =
                        document.createElement(
                            'td'
                        );

                    customerCell.textContent =
                        response.customer_name ||
                        response.customer_id ||
                        '';

                    const statusCell =
                        document.createElement(
                            'td'
                        );

                    statusCell.textContent =
                        response.status ||
                        '回答済み';

                    row.appendChild(
                        dateCell
                    );

                    row.appendChild(
                        customerCell
                    );

                    row.appendChild(
                        statusCell
                    );

                    tbody.appendChild(
                        row
                    );
                }
            );

            table.appendChild(
                tbody
            );

            tableWrap.appendChild(
                table
            );

            container.appendChild(
                tableWrap
            );
        } catch (error) {
            container.textContent =
                error instanceof Error
                    ? error.message
                    : '回答状況を取得できませんでした。';
        }
    };


    /*
     * =========================================================
     * 顧客管理
     * =========================================================
     */

    const renderCustomers = (
        filter = ''
    ) => {
        const body =
            getElement(
                'customer-list-body'
            );

        if (!body) {
            return;
        }

        body.textContent = '';

        const normalizedFilter =
            filter
                .trim()
                .toLowerCase();

        const customers =
            Array.isArray(
                state.customers
            )
                ? state.customers
                : [];

        const filtered =
            customers.filter(
                (customer) => {
                    if (!customer) {
                        return false;
                    }

                    if (
                        normalizedFilter === ''
                    ) {
                        return true;
                    }

                    const target = [
                        customer.id,
                        customer.name,
                        customer.email,
                        customer.company,
                        customer.code
                    ]
                        .map(
                            (value) =>
                                value === null ||
                                value === undefined
                                    ? ''
                                    : String(value)
                        )
                        .join(' ')
                        .toLowerCase();

                    return target.includes(
                        normalizedFilter
                    );
                }
            );

        if (!filtered.length) {
            const row =
                document.createElement(
                    'tr'
                );

            const cell =
                document.createElement(
                    'td'
                );

            cell.colSpan = 4;

            cell.textContent =
                customers.length
                    ? '条件に一致する顧客がありません。'
                    : '顧客データがありません。';

            row.appendChild(cell);
            body.appendChild(row);

            return;
        }

        filtered.forEach(
            (customer) => {
                const row =
                    document.createElement(
                        'tr'
                    );

                const idCell =
                    document.createElement(
                        'td'
                    );

                idCell.textContent =
                    customer.id || '';

                const companyCell =
                    document.createElement(
                        'td'
                    );

                companyCell.textContent =
                    customer.company || '';

                const nameCell =
                    document.createElement(
                        'td'
                    );

                nameCell.textContent =
                    customer.name || '';

                const emailCell =
                    document.createElement(
                        'td'
                    );

                emailCell.textContent =
                    customer.email || '';

                row.appendChild(
                    idCell
                );

                row.appendChild(
                    companyCell
                );

                row.appendChild(
                    nameCell
                );

                row.appendChild(
                    emailCell
                );

                body.appendChild(row);
            }
        );

        setText(
            getElement(
                'customer-status-text'
            ),
            '顧客数: ' +
            customers.length +
            '件'
        );

        const dot =
            getElement(
                'customer-status-dot'
            );

        if (dot) {
            dot.className =
                'status-dot ' +
                (
                    customers.length
                        ? 'ok'
                        : 'warn'
                );
        }
    };


    const loadCustomers = async () => {
        try {
            const result =
                await apiGet(
                    'get_customers'
                );

            const customers =
                result &&
                result.data &&
                Array.isArray(
                    result.data.customers
                )
                    ? result.data.customers
                    : [];

            state.customers =
                customers;

            renderCustomers();

            updateDashboardSummary();

            renderSendCustomerList();
        } catch (error) {

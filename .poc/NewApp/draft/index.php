<?php
declare(strict_types=1);

namespace gojacic\newapp\survey;

use RuntimeException;
use Throwable;

/*
 * ============================================================
 * アンケート業務運営アプリ
 * index.php 単一ファイル版
 *
 * 1/2
 * PHP処理 + HTML/CSSのheadまで
 * ============================================================
 */

const APP_SESSION_KEY = 'gojacic_newapp_survey';
const DATA_DIR_NAME   = 'data';

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

/**
 * NULL安全HTMLエスケープ
 */
function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * JSONレスポンスを返して終了
 */
function json_response(array $response, int $statusCode = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/**
 * アプリ固有セッション領域
 */
function &app_session(): array
{
    if (!isset($_SESSION[APP_SESSION_KEY]) || !is_array($_SESSION[APP_SESSION_KEY])) {
        $_SESSION[APP_SESSION_KEY] = [];
    }

    return $_SESSION[APP_SESSION_KEY];
}

/**
 * CSRFトークン取得・生成
 */
function get_csrf_token(): string
{
    $session =& app_session();

    if (
        !isset($session['csrf_token']) ||
        !is_string($session['csrf_token']) ||
        $session['csrf_token'] === ''
    ) {
        $session['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $session['csrf_token'];
}

/**
 * CSRF検証
 */
function verify_csrf(): void
{
    $token = '';

    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($headerToken) && $headerToken !== '') {
        $token = $headerToken;
    } elseif (isset($_POST['_csrf']) && is_string($_POST['_csrf'])) {
        $token = $_POST['_csrf'];
    }

    $expected = get_csrf_token();

    if ($token === '' || !hash_equals($expected, $token)) {
        json_response([
            'success' => false,
            'message' => 'セキュリティ確認に失敗しました。画面を再読み込みして再度お試しください。'
        ], 403);
    }
}

/**
 * JSONリクエスト本文を取得
 */
function get_json_input(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        json_response([
            'success' => false,
            'message' => '送信データの形式が正しくありません。'
        ], 400);
    }

    return $data;
}

/**
 * アプリルート
 */
function app_root(): string
{
    $root = dirname(__FILE__);

    if (!is_dir($root)) {
        throw new RuntimeException('アプリケーションフォルダを取得できません。');
    }

    return $root;
}

/**
 * データ保存先
 */
function data_dir(): string
{
    $dir = app_root() . DIRECTORY_SEPARATOR . DATA_DIR_NAME;

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('データ保存フォルダを作成できません。');
        }
    }

    return $dir;
}

/**
 * JSONファイル読み込み
 */
function read_json_file(string $filename, array $default = []): array
{
    $path = data_dir() . DIRECTORY_SEPARATOR . $filename;

    if (!is_file($path)) {
        return $default;
    }

    $raw = file_get_contents($path);

    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : $default;
}

/**
 * JSONファイル保存
 */
function write_json_file(string $filename, array $data): void
{
    $path = data_dir() . DIRECTORY_SEPARATOR . $filename;

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException('データをJSON形式に変換できません。');
    }

    $tmp = $path . '.tmp';

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('データを一時保存できません。');
    }

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('データを保存できません。');
    }
}

/**
 * 初期アンケートデータ
 */
function default_surveys(): array
{
    return [
        [
            'id' => 'survey_demo_001',
            'name' => '新サービスに関するアンケート',
            'description' => '新サービスについてのご意見をお聞かせください。',
            'status' => 'draft',
            'start_at' => '',
            'end_at' => '',
            'numbering' => 'global',
            'created_at' => '2026-09-20 10:00:00',
            'updated_at' => '2026-09-20 10:00:00',
            'groups' => [
                [
                    'id' => 'group_demo_001',
                    'name' => '基本情報',
                    'questions' => [
                        [
                            'id' => 'question_demo_001',
                            'text' => '今回のサービスについてどう感じましたか？',
                            'type' => 'single',
                            'required' => true,
                            'choices' => [
                                [
                                    'id' => 'choice_demo_001',
                                    'text' => 'とても良い',
                                    'branch' => ['type' => 'next', 'target' => '']
                                ],
                                [
                                    'id' => 'choice_demo_002',
                                    'text' => '良い',
                                    'branch' => ['type' => 'next', 'target' => '']
                                ],
                                [
                                    'id' => 'choice_demo_003',
                                    'text' => '改善してほしい',
                                    'branch' => ['type' => 'next', 'target' => '']
                                ]
                            ]
                        ],
                        [
                            'id' => 'question_demo_002',
                            'text' => 'ご意見・ご要望があればお聞かせください。',
                            'type' => 'text',
                            'required' => false,
                            'choices' => []
                        ]
                    ]
                ]
            ],
            'stats' => [
                'responses' => 0,
                'sent' => 0,
                'delivered' => 0
            ]
        ]
    ];
}

/**
 * 初期顧客データ
 */
function default_customers(): array
{
    return [
        [
            'id' => 'customer_001',
            'name' => '山田 太郎',
            'email' => 'taro.yamada@example.com',
            'status' => '未回答'
        ],
        [
            'id' => 'customer_002',
            'name' => '佐藤 花子',
            'email' => 'hanako.sato@example.com',
            'status' => '未回答'
        ],
        [
            'id' => 'customer_003',
            'name' => '鈴木 一郎',
            'email' => 'ichiro.suzuki@example.com',
            'status' => '回答済み'
        ]
    ];
}

/**
 * 保存データを初期化
 */
function initialize_data(): void
{
    $surveysPath = data_dir() . DIRECTORY_SEPARATOR . 'surveys.json';
    $customersPath = data_dir() . DIRECTORY_SEPARATOR . 'customers.json';
    $settingsPath = data_dir() . DIRECTORY_SEPARATOR . 'settings.json';

    if (!is_file($surveysPath)) {
        write_json_file('surveys.json', default_surveys());
    }

    if (!is_file($customersPath)) {
        write_json_file('customers.json', default_customers());
    }

    if (!is_file($settingsPath)) {
        write_json_file('settings.json', [
            'smtp' => [
                'host' => '',
                'port' => '587',
                'encryption' => 'TLS',
                'username' => '',
                'password' => '',
                'from_email' => '',
                'from_name' => ''
            ],
            'kintone' => [
                'domain' => '',
                'app_id' => '',
                'login_name' => '',
                'password' => '',
                'customer_name_field' => '顧客名',
                'customer_email_field' => 'メールアドレス',
                'proxy_host_port' => '',
                'verify_ssl' => false
            ]
        ]);
    }
}

/**
 * kintone URL成形
 */
function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain) ?? '';
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain) ?? '';
    $domain = rtrim($domain, '/');

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

/**
 * kintoneレスポンスヘッダー取得
 *
 * PHP 8.4/8.5では標準関数が存在する場合に利用する。
 * 直接 $http_response_header を参照しない。
 */
function get_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();

        return is_array($headers) ? $headers : [];
    }

    return [];
}

/**
 * kintone APIリクエスト
 *
 * cURLは使用しない。
 */
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
        'timeout' => 15
    ];

    /*
     * GETにはcontentを絶対に設定しない。
     */
    if ($method !== 'GET' && $payload !== null) {
        if (is_array($payload)) {
            $encoded = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ($encoded === false) {
                return [
                    'success' => false,
                    'status' => 0,
                    'message' => '送信データをJSON化できません。'
                ];
            }

            $httpOptions['content'] = $encoded;
        } elseif (is_string($payload)) {
            $httpOptions['content'] = $payload;
        }
    }

    /*
     * プロキシは入力値がある場合に常に適用可能。
     */
    $proxyHostPort = trim(
        is_string($config['proxy_host_port'] ?? null)
            ? $config['proxy_host_port']
            : ''
    );

    if ($proxyHostPort !== '') {
        $proxyHostPort = preg_replace(
            '/^https?:\/\//i',
            '',
            $proxyHostPort
        ) ?? '';

        $proxyHostPort = trim($proxyHostPort, '/');

        if ($proxyHostPort !== '') {
            $httpOptions['proxy'] = 'tcp://' . $proxyHostPort;
            $httpOptions['request_fulluri'] = true;
        }
    }

    $contextOptions = [
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];

    $context = stream_context_create($contextOptions);

    $responseBody = @file_get_contents(
        $url,
        false,
        $context
    );

    $responseHeaders = get_safe_response_headers();

    $statusCode = 0;

    if (!empty($responseHeaders)) {
        foreach ($responseHeaders as $headerLine) {
            if (
                is_string($headerLine) &&
                preg_match(
                    '/HTTP\/\d(?:\.\d)?\s+(\d+)/i',
                    $headerLine,
                    $matches
                )
            ) {
                $statusCode = (int)$matches[1];
            }
        }
    }

    $decoded = [];

    if (is_string($responseBody) && $responseBody !== '') {
        $json = json_decode($responseBody, true);

        if (is_array($json)) {
            $decoded = $json;
        }
    }

    if ($statusCode >= 200 && $statusCode < 300) {
        return [
            'success' => true,
            'status' => $statusCode,
            'data' => $decoded
        ];
    }

    $message = 'kintone APIとの通信に失敗しました。';

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

            if (is_array($messages)) {
                foreach ($messages as $errorMessage) {
                    if (is_string($errorMessage)) {
                        $details[] = (string)$field . ': ' . $errorMessage;
                    }
                }
            }
        }
    }

    if (!empty($details)) {
        $message .= ' (' . implode(' / ', $details) . ')';
    }

    return [
        'success' => false,
        'status' => $statusCode,
        'message' => $message
    ];
}

/**
 * Cybozu認証ヘッダー
 */
function make_cybozu_auth_header(
    string $loginName,
    string $password
): string {
    $loginName = trim($loginName);
    $password = trim($password);

    $auth = base64_encode($loginName . ':' . $password);

    return 'X-Cybozu-Authorization: ' . $auth;
}

/**
 * kintone接続確認
 */
function test_kintone_connection(array $settings): array
{
    $domain = is_string($settings['domain'] ?? null)
        ? trim($settings['domain'])
        : '';

    $loginName = is_string($settings['login_name'] ?? null)
        ? $settings['login_name']
        : '';

    $password = is_string($settings['password'] ?? null)
        ? $settings['password']
        : '';

    $proxy = is_string($settings['proxy_host_port'] ?? null)
        ? $settings['proxy_host_port']
        : '';

    if ($domain === '') {
        return [
            'success' => false,
            'message' => 'kintoneのサブドメインを入力してください。'
        ];
    }

    if ($loginName === '' || $password === '') {
        return [
            'success' => false,
            'message' => 'kintoneのログイン名とパスワードを入力してください。'
        ];
    }

    $url = kintone_build_url(
        $domain,
        '/k/v1/apps.json?limit=1'
    );

    $headers = [
        make_cybozu_auth_header($loginName, $password),
        'Accept: application/json'
    ];

    return kintone_api_request(
        'GET',
        $url,
        $headers,
        null,
        [
            'proxy_host_port' => $proxy
        ]
    );
}

/**
 * kintone顧客取得
 */
function fetch_kintone_customers(array $settings): array
{
    $domain = is_string($settings['domain'] ?? null)
        ? trim($settings['domain'])
        : '';

    $appId = is_string($settings['app_id'] ?? null)
        ? trim($settings['app_id'])
        : '';

    $loginName = is_string($settings['login_name'] ?? null)
        ? $settings['login_name']
        : '';

    $password = is_string($settings['password'] ?? null)
        ? $settings['password']
        : '';

    $proxy = is_string($settings['proxy_host_port'] ?? null)
        ? $settings['proxy_host_port']
        : '';

    if ($domain === '' || $appId === '') {
        return [
            'success' => false,
            'message' => 'kintoneの接続先とアプリIDを設定してください。'
        ];
    }

    if ($loginName === '' || $password === '') {
        return [
            'success' => false,
            'message' => 'kintoneのログイン情報を設定してください。'
        ];
    }

    if (!ctype_digit($appId)) {
        return [
            'success' => false,
            'message' => 'kintoneアプリIDは数字で入力してください。'
        ];
    }

    $params = [
        'app' => $appId,
        'query' => 'order by $id asc',
        'totalCount' => 'true'
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

    $headers = [
        make_cybozu_auth_header($loginName, $password),
        'Accept: application/json'
    ];

    return kintone_api_request(
        'GET',
        $url,
        $headers,
        null,
        [
            'proxy_host_port' => $proxy
        ]
    );
}

/**
 * API処理
 */
function handle_api_request(): never
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method !== 'POST') {
        json_response([
            'success' => false,
            'message' => '許可されていないリクエストです。'
        ], 405);
    }

    verify_csrf();

    $request = get_json_input();

    $action = isset($request['action']) && is_string($request['action'])
        ? $request['action']
        : '';

    try {
        initialize_data();

        switch ($action) {
            case 'load_surveys':
                json_response([
                    'success' => true,
                    'surveys' => read_json_file(
                        'surveys.json',
                        default_surveys()
                    )
                ]);

            case 'load_customers':
                json_response([
                    'success' => true,
                    'customers' => read_json_file(
                        'customers.json',
                        default_customers()
                    )
                ]);

            case 'load_settings':
                $settings = read_json_file('settings.json', []);

                /*
                 * パスワードは画面へ返さない。
                 */
                if (isset($settings['smtp']['password'])) {
                    $settings['smtp']['password'] =
                        !empty($settings['smtp']['password'])
                            ? '********'
                            : '';
                }

                if (isset($settings['kintone']['password'])) {
                    $settings['kintone']['password'] =
                        !empty($settings['kintone']['password'])
                            ? '********'
                            : '';
                }

                json_response([
                    'success' => true,
                    'settings' => $settings
                ]);

            case 'save_survey':
                $survey = $request['survey'] ?? null;

                if (!is_array($survey)) {
                    json_response([
                        'success' => false,
                        'message' => 'アンケートデータが正しくありません。'
                    ], 400);
                }

                $surveys = read_json_file(
                    'surveys.json',
                    default_surveys()
                );

                $surveyId = isset($survey['id']) &&
                    is_string($survey['id']) &&
                    $survey['id'] !== ''
                    ? $survey['id']
                    : 'survey_' . bin2hex(random_bytes(8));

                $survey['id'] = $surveyId;

                $now = date('Y-m-d H:i:s');

                if (!isset($survey['created_at'])) {
                    $survey['created_at'] = $now;
                }

                $survey['updated_at'] = $now;

                $found = false;

                foreach ($surveys as $index => $existing) {
                    if (
                        is_array($existing) &&
                        isset($existing['id']) &&
                        $existing['id'] === $surveyId
                    ) {
                        $surveys[$index] = $survey;
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $surveys[] = $survey;
                }

                write_json_file('surveys.json', $surveys);

                json_response([
                    'success' => true,
                    'message' => 'アンケートを保存しました。',
                    'survey' => $survey
                ]);

            case 'create_survey':
                $surveys = read_json_file(
                    'surveys.json',
                    default_surveys()
                );

                $now = date('Y-m-d H:i:s');

                $survey = [
                    'id' => 'survey_' . bin2hex(random_bytes(8)),
                    'name' => '新しいアンケート',
                    'description' => '',
                    'status' => 'draft',
                    'start_at' => '',
                    'end_at' => '',
                    'numbering' => 'global',
                    'created_at' => $now,
                    'updated_at' => $now,
                    'groups' => [
                        [
                            'id' => 'group_' . bin2hex(random_bytes(8)),
                            'name' => 'グループ1',
                            'questions' => []
                        ]
                    ],
                    'stats' => [
                        'responses' => 0,
                        'sent' => 0,
                        'delivered' => 0
                    ]
                ];

                $surveys[] = $survey;

                write_json_file('surveys.json', $surveys);

                json_response([
                    'success' => true,
                    'message' => '新しいアンケートを作成しました。',
                    'survey' => $survey
                ]);

            case 'delete_survey':
                $surveyId = isset($request['survey_id']) &&
                    is_string($request['survey_id'])
                    ? $request['survey_id']
                    : '';

                if ($surveyId === '') {
                    json_response([
                        'success' => false,
                        'message' => '削除対象が指定されていません。'
                    ], 400);
                }

                $surveys = read_json_file(
                    'surveys.json',
                    default_surveys()
                );

                $newSurveys = [];
                $deleted = false;

                foreach ($surveys as $survey) {
                    if (
                        is_array($survey) &&
                        isset($survey['id']) &&
                        $survey['id'] === $surveyId
                    ) {
                        $status = $survey['status'] ?? 'draft';

                        if ($status !== 'draft') {
                            json_response([
                                'success' => false,
                                'message' => '公開中または終了したアンケートは削除できません。'
                            ], 400);
                        }

                        $deleted = true;
                        continue;
                    }

                    $newSurveys[] = $survey;
                }

                if (!$deleted) {
                    json_response([
                        'success' => false,
                        'message' => '対象アンケートが見つかりません。'
                    ], 404);
                }

                write_json_file('surveys.json', $newSurveys);

                json_response([
                    'success' => true,
                    'message' => 'アンケートを削除しました。'
                ]);

            case 'change_survey_status':
                $surveyId = isset($request['survey_id']) &&
                    is_string($request['survey_id'])
                    ? $request['survey_id']
                    : '';

                $newStatus = isset($request['status']) &&
                    is_string($request['status'])
                    ? $request['status']
                    : '';

                if (
                    $surveyId === '' ||
                    !in_array(
                        $newStatus,
                        ['draft', 'active', 'closed'],
                        true
                    )
                ) {
                    json_response([
                        'success' => false,
                        'message' => '状態変更の指定が正しくありません。'
                    ], 400);
                }

                $surveys = read_json_file(
                    'surveys.json',
                    default_surveys()
                );

                $changed = false;

                foreach ($surveys as &$survey) {
                    if (
                        is_array($survey) &&
                        isset($survey['id']) &&
                        $survey['id'] === $surveyId
                    ) {
                        $survey['status'] = $newStatus;
                        $survey['updated_at'] = date('Y-m-d H:i:s');
                        $changed = true;
                        break;
                    }
                }
                unset($survey);

                if (!$changed) {
                    json_response([
                        'success' => false,
                        'message' => '対象アンケートが見つかりません。'
                    ], 404);
                }

                write_json_file('surveys.json', $surveys);

                json_response([
                    'success' => true,
                    'message' => 'アンケートの状態を変更しました。'
                ]);

            case 'save_settings':
                $incoming = $request['settings'] ?? null;

                if (!is_array($incoming)) {
                    json_response([
                        'success' => false,
                        'message' => '設定データが正しくありません。'
                    ], 400);
                }

                $current = read_json_file('settings.json', []);

                $smtp = is_array($incoming['smtp'] ?? null)
                    ? $incoming['smtp']
                    : [];

                $kintone = is_array($incoming['kintone'] ?? null)
                    ? $incoming['kintone']
                    : [];

                /*
                 * 既存パスワードを維持する。
                 */
                if (
                    !isset($smtp['password']) ||
                    !is_string($smtp['password']) ||
                    $smtp['password'] === '' ||
                    $smtp['password'] === '********'
                ) {
                    $smtp['password'] =
                        is_string($current['smtp']['password'] ?? null)
                            ? $current['smtp']['password']
                            : '';
                }

                if (
                    !isset($kintone['password']) ||
                    !is_string($kintone['password']) ||
                    $kintone['password'] === '' ||
                    $kintone['password'] === '********'
                ) {
                    $kintone['password'] =
                        is_string($current['kintone']['password'] ?? null)
                            ? $current['kintone']['password']
                            : '';
                }

                $settings = [
                    'smtp' => [
                        'host' => is_string($smtp['host'] ?? null)
                            ? trim($smtp['host'])
                            : '',
                        'port' => is_string($smtp['port'] ?? null)
                            ? trim($smtp['port'])
                            : '',
                        'encryption' => is_string($smtp['encryption'] ?? null)
                            ? $smtp['encryption']
                            : 'TLS',
                        'username' => is_string($smtp['username'] ?? null)
                            ? trim($smtp['username'])
                            : '',
                        'password' => $smtp['password'],
                        'from_email' => is_string($smtp['from_email'] ?? null)
                            ? trim($smtp['from_email'])
                            : '',
                        'from_name' => is_string($smtp['from_name'] ?? null)
                            ? trim($smtp['from_name'])
                            : ''
                    ],
                    'kintone' => [
                        'domain' => is_string($kintone['domain'] ?? null)
                            ? trim($kintone['domain'])
                            : '',
                        'app_id' => is_string($kintone['app_id'] ?? null)
                            ? trim($kintone['app_id'])
                            : '',
                        'login_name' => is_string($kintone['login_name'] ?? null)
                            ? trim($kintone['login_name'])
                            : '',
                        'password' => $kintone['password'],
                        'customer_name_field' =>
                            is_string($kintone['customer_name_field'] ?? null)
                                ? trim($kintone['customer_name_field'])
                                : '',
                        'customer_email_field' =>
                            is_string($kintone['customer_email_field'] ?? null)
                                ? trim($kintone['customer_email_field'])
                                : '',
                        'proxy_host_port' =>
                            is_string($kintone['proxy_host_port'] ?? null)
                                ? trim($kintone['proxy_host_port'])
                                : '',
                        'verify_ssl' => false
                    ]
                ];

                write_json_file('settings.json', $settings);

                json_response([
                    'success' => true,
                    'message' => '設定を保存しました。'
                ]);

            case 'test_kintone':
                $settings = $request['settings'] ?? null;

                if (!is_array($settings)) {
                    json_response([
                        'success' => false,
                        'message' => 'kintone設定が正しくありません。'
                    ], 400);
                }

                $result = test_kintone_connection($settings);

                if ($result['success'] ?? false) {
                    json_response([
                        'success' => true,
                        'message' => 'kintoneへの接続を確認しました。'
                    ]);
                }

                json_response([
                    'success' => false,
                    'message' => $result['message'] ?? 'kintone接続に失敗しました。'
                ]);

            case 'sync_customers':
                $settings = read_json_file('settings.json', []);
                $kintoneSettings = is_array($settings['kintone'] ?? null)
                    ? $settings['kintone']
                    : [];

                $result = fetch_kintone_customers($kintoneSettings);

                if (!($result['success'] ?? false)) {
                    json_response([
                        'success' => false,
                        'message' => $result['message'] ?? '顧客情報を取得できません。'
                    ]);
                }

                $records = $result['data']['records'] ?? [];

                if (!is_array($records)) {
                    $records = [];
                }

                $nameField = is_string(
                    $kintoneSettings['customer_name_field'] ?? null
                )
                    ? $kintoneSettings['customer_name_field']
                    : '';

                $emailField = is_string(
                    $kintoneSettings['customer_email_field'] ?? null
                )
                    ? $kintoneSettings['customer_email_field']
                    : '';

                $customers = [];

                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }

                    $nameValue = '';
                    $emailValue = '';

                    if (
                        $nameField !== '' &&
                        isset($record[$nameField]) &&
                        is_array($record[$nameField])
                    ) {
                        $value = $record[$nameField]['value'] ?? '';
                        if (is_string($value)) {
                            $nameValue = $value;
                        }
                    }

                    if (
                        $emailField !== '' &&
                        isset($record[$emailField]) &&
                        is_array($record[$emailField])
                    ) {
                        $value = $record[$emailField]['value'] ?? '';
                        if (is_string($value)) {
                            $emailValue = $value;
                        }
                    }

                    $customers[] = [
                        'id' => 'kintone_' . bin2hex(random_bytes(6)),
                        'name' => $nameValue,
                        'email' => $emailValue,
                        'status' => '未回答'
                    ];
                }

                write_json_file('customers.json', $customers);

                json_response([
                    'success' => true,
                    'message' => count($customers) . '件の顧客情報を取得しました。',
                    'customers' => $customers
                ]);

            case 'save_response':
                /*
                 * 回答者側からの保存。
                 * 回答者識別情報は顧客一覧に存在しなくても受け付ける。
                 */
                $responseData = $request['response'] ?? null;

                if (!is_array($responseData)) {
                    json_response([
                        'success' => false,
                        'message' => '回答データが正しくありません。'
                    ], 400);
                }

                $responses = read_json_file('responses.json', []);

                $responses[] = [
                    'id' => 'response_' . bin2hex(random_bytes(10)),
                    'survey_id' =>
                        is_string($responseData['survey_id'] ?? null)
                            ? $responseData['survey_id']
                            : '',
                    'respondent_email' =>
                        is_string($responseData['respondent_email'] ?? null)
                            ? trim($responseData['respondent_email'])
                            : '',
                    'answers' =>
                        is_array($responseData['answers'] ?? null)
                            ? $responseData['answers']
                            : [],
                    'created_at' => date('Y-m-d H:i:s')
                ];

                write_json_file('responses.json', $responses);

                json_response([
                    'success' => true,
                    'message' => '回答を受け付けました。'
                ]);

            default:
                json_response([
                    'success' => false,
                    'message' => '指定された処理は存在しません。'
                ], 400);
        }
    } catch (Throwable $e) {
        /*
         * パスワード・認証情報などをエラーレスポンスへ含めない。
         */
        error_log(
            'NewApp error: ' . $e->getMessage()
        );

        json_response([
            'success' => false,
            'message' => '処理中にエラーが発生しました。'
        ], 500);
    }
}

/*
 * APIリクエストの場合はHTMLを出力しない。
 */
if (
    strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' &&
    isset($_SERVER['HTTP_X_REQUESTED_WITH'])
) {
    handle_api_request();
}

/*
 * 初期データを用意。
 */
try {
    initialize_data();
} catch (Throwable $e) {
    error_log('NewApp initialization error: ' . $e->getMessage());
}

/*
 * 画面初期表示用CSRFトークン。
 */
$csrfToken = get_csrf_token();

/*
 * 現在のURL。
 *
 * JavaScript側ではこの値を利用し、
 * 特定ホスト名を固定したURLへfetchしない。
 */
$currentEndpoint = $_SERVER['REQUEST_URI'] ?? '';

if (!is_string($currentEndpoint) || $currentEndpoint === '') {
    $currentEndpoint = '/';
}

$currentEndpoint = strtok($currentEndpoint, '?');

if ($currentEndpoint === false || $currentEndpoint === '') {
    $currentEndpoint = '/';
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= h($csrfToken) ?>">
<meta name="app-endpoint" content="<?= h($currentEndpoint) ?>">
<title>アンケート業務運営アプリ</title>

<style>
:root {
    --primary: #2563eb;
    --primary-hover: #1d4ed8;
    --primary-light: #eff6ff;
    --bg: #f8fafc;
    --surface: #ffffff;
    --border: #e2e8f0;
    --border-dark: #cbd5e1;
    --text: #1e293b;
    --text-muted: #64748b;
    --success: #16a34a;
    --success-light: #dcfce7;
    --danger: #dc2626;
    --danger-light: #fee2e2;
    --warning: #d97706;
    --warning-light: #fef3c7;
    --shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
    --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.12);
}

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
    background: var(--bg);
    color: var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Hiragino Kaku Gothic ProN",
        "Yu Gothic",
        Meiryo,
        Arial,
        sans-serif;
    line-height: 1.5;
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
    opacity: 0.6;
}

.app-header {
    position: sticky;
    top: 0;
    z-index: 100;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
}

.header-container {
    width: min(1200px, calc(100% - 32px));
    min-height: 64px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
}

.logo {
    flex: 0 0 auto;
    color: var(--primary);
    font-size: 17px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
}

.main-nav {
    display: flex;
    align-items: stretch;
    gap: 2px;
    height: 64px;
}

.main-nav button {
    border: 0;
    border-bottom: 3px solid transparent;
    background: transparent;
    color: var(--text-muted);
    padding: 0 14px;
    font-size: 14px;
    font-weight: 600;
}

.main-nav button:hover {
    color: var(--text);
    background: #f8fafc;
}

.main-nav button.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
}

.sub-nav {
    background: #f1f5f9;
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}

.sub-nav-container {
    width: min(1200px, calc(100% - 32px));
    min-height: 50px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.sub-nav-title {
    color: var(--text-muted);
    font-size: 13px;
    white-space: nowrap;
}

.sub-nav-title strong {
    color: var(--text);
    font-size: 14px;
}

.sub-nav-actions {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.sub-nav-actions button {
    border: 1px solid var(--border-dark);
    border-radius: 5px;
    background: #fff;
    color: var(--text);
    padding: 6px 10px;
    font-size: 12px;
    font-weight: 600;
}

.sub-nav-actions button.active {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
}

main {
    width: min(1200px, calc(100% - 32px));
    margin: 24px auto 80px;
}

.page-title-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
}

.page-title {
    margin: 0;
    font-size: 24px;
    line-height: 1.3;
}

.page-description {
    margin: 5px 0 0;
    color: var(--text-muted);
    font-size: 13px;
}

.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: var(--shadow);
    padding: 20px;
    margin-bottom: 20px;
}

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding-bottom: 12px;
    margin-bottom: 16px;
    border-bottom: 1px solid var(--border);
}

.card-title {
    margin: 0;
    font-size: 17px;
    font-weight: 700;
}

.card-description {
    color: var(--text-muted);
    font-size: 13px;
}

.btn {
    min-height: 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border: 1px solid transparent;
    border-radius: 6px;
    padding: 7px 13px;
    font-size: 13px;
    font-weight: 600;
    transition:
        background-color 0.15s ease,
        border-color 0.15s ease,
        color 0.15s ease;
}

.btn-primary {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
}

.btn-primary:hover:not(:disabled) {
    background: var(--primary-hover);
    border-color: var(--primary-hover);
}

.btn-outline {
    background: #fff;
    border-color: var(--border-dark);
    color: var(--text);
}

.btn-outline:hover:not(:disabled) {
    background: #f8fafc;
}

.btn-danger {
    background: var(--danger);
    border-color: var(--danger);
    color: #fff;
}

.btn-danger-outline {
    background: #fff;
    border-color: #fca5a5;
    color: var(--danger);
}

.btn-success {
    background: var(--success);
    border-color: var(--success);
    color: #fff;
}

.btn-sm {
    min-height: 32px;
    padding: 5px 9px;
    font-size: 12px;
}

.btn-block {
    width: 100%;
}

.btn.loading {
    position: relative;
    color: transparent !important;
    pointer-events: none;
}

.btn.loading::after {
    content: "";
    position: absolute;
    width: 15px;
    height: 15px;
    left: 50%;
    top: 50%;
    margin-left: -8px;
    margin-top: -8px;
    border: 2px solid rgba(255,255,255,0.4);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}

.btn-outline.loading::after {
    border-color: rgba(37,99,235,0.25);
    border-top-color: var(--primary);
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.badge {
    display: inline-flex;
    align-items: center;
    min-height: 24px;
    border-radius: 999px;
    padding: 3px 9px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.badge-draft {
    background: #e2e8f0;
    color: #475569;
}

.badge-active {
    background: var(--success-light);
    color: #15803d;
}

.badge-closed {
    background: var(--danger-light);
    color: #b91c1c;
}

.badge-warning {
    background: var(--warning-light);
    color: #92400e;
}

.table-wrap {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.data-table th,
.data-table td {
    padding: 11px 10px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    vertical-align: middle;
}

.data-table th {
    background: #f8fafc;
    color: var(--text-muted);
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.data-table tbody tr:hover {
    background: #fafcff;
}

.actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 5px;
    flex-wrap: wrap;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.form-group {
    margin-bottom: 14px;
}

.form-group.full {
    grid-column: 1 / -1;
}

.form-label {
    display: block;
    margin-bottom: 5px;
    font-size: 13px;
    font-weight: 700;
}

.required {
    color: var(--danger);
    margin-left: 3px;
}

.form-control {
    width: 100%;
    min-height: 38px;
    border: 1px solid var(--border-dark);
    border-radius: 6px;
    background: #fff;
    color: var(--text);
    padding: 7px 10px;
    font-size: 13px;
}

textarea.form-control {
    min-height: 90px;
    resize: vertical;
}

.form-control:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
}

.form-help {
    margin-top: 4px;
    color: var(--text-muted);
    font-size: 11px;
}

.form-error {
    border-color: var(--danger) !important;
    background: #fff7f7;
}

.validation-message {
    margin-top: 5px;
    color: var(--danger);
    font-size: 12px;
}

.inline-fields {
    display: flex;
    align-items: center;
    gap: 10px;
}

.inline-fields > * {
    flex: 1;
}

.radio-group,
.checkbox-group {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
}

.radio-item,
.checkbox-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 13px;
}

.empty-state {
    padding: 50px 20px;
    text-align: center;
    color: var(--text-muted);
}

.empty-state-title {
    margin-bottom: 6px;
    color: var(--text);
    font-size: 16px;
    font-weight: 700;
}

.stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}

.stat-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 17px;
    box-shadow: var(--shadow);
}

.stat-label {
    color: var(--text-muted);
    font-size: 12px;
    font-weight: 600;
}

.stat-value {
    margin-top: 5px;
    color: var(--primary);
    font-size: 27px;
    font-weight: 800;
}

.group-card {
    margin-bottom: 18px;
    border: 1px solid var(--border-dark);
    border-radius: 8px;
    background: #f8fafc;
    overflow: hidden;
}

.group-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    background: #f1f5f9;
    border-bottom: 1px solid var(--border);
}

.drag-handle {
    color: #94a3b8;
    cursor: grab;
    user-select: none;
}

.group-name-input {
    flex: 1;
    border: 1px solid transparent;
    background: transparent;
    color: var(--text);
    font-weight: 700;
    padding: 5px 7px;
    border-radius: 5px;
}

.group-name-input:focus {
    outline: none;
    background: #fff;
    border-color: var(--border-dark);
}

.group-body {
    padding: 14px;
}

.question-card {
    margin-bottom: 12px;
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: #fff;
}

.question-card:last-child {
    margin-bottom: 0;
}

.question-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 12px;
}

.question-number {
    color: var(--primary);
    font-size: 13px;
    font-weight: 800;
}

.question-actions {
    display: flex;
    gap: 5px;
}

.choice-list {
    margin-top: 12px;
}

.choice-row {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 7px;
}

.choice-row input {
    flex: 1;
}

.branch-box {
    margin-top: 12px;
    padding: 11px;
    border-radius: 6px;
    background: #f8fafc;
    border: 1px solid var(--border);
}

.branch-title {
    margin-bottom: 7px;
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 700;
}

.warning-box {
    margin-top: 10px;
    padding: 10px 12px;
    border: 1px solid #fcd34d;
    border-radius: 6px;
    background: var(--warning-light);
    color: #92400e;
    font-size: 12px;
}

.editor-footer {
    position: sticky;
    bottom: 0;
    z-index: 50;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-top: 20px;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: rgba(255,255,255,0.96);
    box-shadow: var(--shadow-lg);
    backdrop-filter: blur(8px);
}

.editor-footer-message {
    color: var(--text-muted);
    font-size: 12px;
}

.editor-footer-actions {
    display: flex;
    gap: 7px;
}

.search-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
}

.search-row .form-control {
    max-width: 380px;
}

.preview-card {
    max-width: 760px;
    margin: 0 auto;
}

.preview-title {
    margin-bottom: 6px;
    font-size: 24px;
    font-weight: 800;
}

.preview-description {
    margin-bottom: 24px;
    color: var(--text-muted);
    white-space: pre-wrap;
}

.preview-question {
    margin-bottom: 20px;
}

.preview-question-title {
    margin-bottom: 8px;
    font-size: 15px;
    font-weight: 700;
}

.preview-option {
    display: block;
    margin: 7px 0;
}

.mail-preview {
    padding: 15px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: #f8fafc;
    white-space: pre-wrap;
    font-size: 13px;
}

.progress {
    height: 9px;
    overflow: hidden;
    border-radius: 999px;
    background: #e2e8f0;
}

.progress-fill {
    height: 100%;
    border-radius: inherit;
    background: var(--primary);
    transition: width 0.2s ease;
}

.chart-row {
    display: grid;
    grid-template-columns: 120px 1fr 55px;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
    font-size: 12px;
}

.chart-bar {
    height: 18px;
    overflow: hidden;
    border-radius: 4px;
    background: #e2e8f0;
}

.chart-bar-fill {
    height: 100%;
    background: var(--primary);
}

.toast {
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 1000;
    max-width: 420px;
    display: none;
    padding: 11px 15px;
    border-radius: 7px;
    background: #334155;
    color: #fff;
    box-shadow: var(--shadow-lg);
    font-size: 13px;
}

.toast.show {
    display: block;
}

.toast.success {
    background: #166534;
}

.toast.error {
    background: #991b1b;
}

.toast.warning {
    background: #92400e;
}

.modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 900;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(15, 23, 42, 0.48);
}

.modal-overlay.show {
    display: flex;
}

.modal {
    width: min(620px, 100%);
    max-height: 90vh;
    overflow-y: auto;
    padding: 20px;
    border-radius: 9px;
    background: #fff;
    box-shadow: var(--shadow-lg);
}

.modal-title {
    margin: 0 0 10px;
    font-size: 18px;
}

.modal-message {
    margin-bottom: 20px;
    color: var(--text-muted);
    font-size: 13px;
    white-space: pre-wrap;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.status-panel {
    padding: 14px;
    border-radius: 7px;
    background: #f8fafc;
    border: 1px solid var(--border);
}

.setting-section {
    margin-bottom: 22px;
}

.setting-section:last-child {
    margin-bottom: 0;
}

.setting-title {
    margin: 0 0 12px;
    font-size: 15px;
}

.password-note {
    color: var(--text-muted);
    font-size: 11px;
}

@media (max-width: 900px) {
    .header-container {
        align-items: flex-start;
        flex-direction: column;
        gap: 0;
        padding-top: 10px;
    }

    .main-nav {
        width: 100%;
        overflow-x: auto;
        height: 50px;
    }

    .main-nav button {
        min-width: max-content;
        padding: 0 10px;
    }

    .sub-nav-container {
        align-items: flex-start;
        flex-direction: column;
        padding: 8px 0;
    }

    .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-group.full {
        grid-column: auto;
    }
}

@media (max-width: 600px) {
    main,
    .header-container,
    .sub-nav-container {
        width: min(100% - 20px, 1200px);
    }

    main {
        margin-top: 14px;
    }

    .page-title-row {
        align-items: flex-start;
        flex-direction: column;
    }

    .stat-grid {
        grid-template-columns: 1fr;
    }

    .card {
        padding: 14px;
    }

    .editor-footer {
        align-items: flex-start;
        flex-direction: column;
    }

    .editor-footer-actions {
        width: 100%;
    }

    .editor-footer-actions .btn {
        flex: 1;
    }

    .chart-row {
        grid-template-columns: 80px 1fr 45px;
    }
}
</style>
</head>


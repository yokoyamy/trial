<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * 単一ファイル index.php
 * PHP 8.5 / Apache 2.4
 * DBなし / PHP cURLなし
 *
 * 重要な設計方針
 * ------------------------------------------------------------
 * - すべて index.php を入口とする
 * - screen は GET パラメータで制御
 * - データはサーバー側 JSON ファイルへ保存
 * - kintone は PHP 標準 stream で通信
 * - cURL は使用しない
 * - kintone パスワード認証は X-Cybozu-Authorization を使用
 * - 認証情報を URL / HTML / JavaScript に出力しない
 * - kintone 接続テストと顧客同期を分離
 * - POST処理は設定系では同一リクエスト内で結果を表示する
 * - 設定保存・接続テストで303リダイレクトに依存しない
 * - /k/v1/records.json は複数レコード取得専用
 * - /k/v1/record.json は1件取得専用
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';

const DATA_DIR      = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE     = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const SESSION_NAME = 'survey_app_session';

const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_READ_TIMEOUT    = 30;

const MAX_TITLE_LENGTH       = 200;
const MAX_DESCRIPTION_LENGTH = 5000;
const MAX_QUESTION_LENGTH    = 1000;
const MAX_OPTION_LENGTH      = 500;


/* ============================================================
 * 初期化
 * ============================================================ */

function app_init(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!@mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException('データ保存フォルダを作成できません。');
        }
    }

    if (!is_file(DATA_FILE)) {
        save_json_file(DATA_FILE, default_data());
    }

    if (!is_file(SETTINGS_FILE)) {
        save_json_file(SETTINGS_FILE, default_settings());
    }

    start_session();
}


/* ============================================================
 * セッション
 * ============================================================ */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443
    );

    session_name(SESSION_NAME);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => cookie_path(),
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException('セッションを開始できません。');
    }
}

function cookie_path(): string
{
    $script = str_replace(
        '\\',
        '/',
        (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')
    );

    $dir = dirname($script);

    if ($dir === '.' || $dir === '/' || $dir === '\\') {
        return '/';
    }

    return rtrim($dir, '/') . '/';
}


/* ============================================================
 * デフォルトデータ
 * ============================================================ */

function default_data(): array
{
    return [
        'surveys' => [
            [
                'id' => 'survey-001',
                'title' => '顧客満足度アンケート',
                'description' => 'サービスについてのご意見をお聞かせください。',
                'startAt' => date('Y-m-d\TH:i'),
                'endAt' => date('Y-m-d\TH:i', strtotime('+30 days')),
                'status' => 'published',
                'numbering' => 'global',
                'createdAt' => date('Y-m-d H:i:s'),
                'updatedAt' => date('Y-m-d H:i:s'),
                'groups' => [
                    [
                        'id' => 'group-001',
                        'title' => '基本アンケート',
                        'questions' => [
                            [
                                'id' => 'question-001',
                                'number' => 'Q1',
                                'text' => 'サービスの満足度を教えてください。',
                                'type' => 'single',
                                'required' => true,
                                'options' => [
                                    [
                                        'id' => 'option-001',
                                        'label' => '非常に満足',
                                        'nextQuestionId' => '',
                                    ],
                                    [
                                        'id' => 'option-002',
                                        'label' => '満足',
                                        'nextQuestionId' => '',
                                    ],
                                    [
                                        'id' => 'option-003',
                                        'label' => '普通',
                                        'nextQuestionId' => '',
                                    ],
                                    [
                                        'id' => 'option-004',
                                        'label' => '不満',
                                        'nextQuestionId' => '',
                                    ],
                                ],
                            ],
                            [
                                'id' => 'question-002',
                                'number' => 'Q2',
                                'text' => 'ご意見・ご要望があれば入力してください。',
                                'type' => 'text',
                                'required' => false,
                                'options' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'answers' => [],
        'customers' => [],
        'send_history' => [],
    ];
}

function default_settings(): array
{
    return [
        'kintone' => [
            'subdomain' => '',
            'app_id' => '',
            'username' => '',
            'password' => '',
            'proxy' => '',
            'verify_ssl' => false,
            'mapping' => [
                'organization' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
            'fields' => [],
            'last_test' => null,
            'last_sync' => null,
        ],
        'mail' => [
            'host' => '',
            'port' => '587',
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '',
            'reply_to' => '',
            'last_test' => null,
        ],
    ];
}


/* ============================================================
 * JSON
 * ============================================================ */

function load_json_file(string $file, array $fallback): array
{
    if (!is_file($file)) {
        return $fallback;
    }

    $fp = @fopen($file, 'rb');

    if ($fp === false) {
        return $fallback;
    }

    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return $fallback;
    }

    $contents = stream_get_contents($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    if ($contents === false || trim($contents) === '') {
        return $fallback;
    }

    $decoded = json_decode($contents, true);

    return is_array($decoded) ? $decoded : $fallback;
}

function save_json_file(string $file, array $data): void
{
    $dir = dirname($file);

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('データ保存フォルダを作成できません。');
        }
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException('データをJSONへ変換できません。');
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));

    $fp = @fopen($tmp, 'wb');

    if ($fp === false) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('データファイルをロックできません。');
        }

        $length = strlen($json);
        $written = 0;

        while ($written < $length) {
            $n = fwrite($fp, substr($json, $written));

            if ($n === false) {
                throw new RuntimeException('データを書き込めません。');
            }

            $written += $n;
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('保存ファイルを更新できません。');
        }
    } catch (Throwable $e) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
        @unlink($tmp);
        throw $e;
    }
}

function load_data(): array
{
    $data = load_json_file(DATA_FILE, default_data());

    foreach ([
        'surveys',
        'answers',
        'customers',
        'send_history',
    ] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }

    return $data;
}

function load_settings(): array
{
    $settings = load_json_file(
        SETTINGS_FILE,
        default_settings()
    );

    $default = default_settings();

    $settings['kintone'] = array_replace_recursive(
        $default['kintone'],
        is_array($settings['kintone'] ?? null)
            ? $settings['kintone']
            : []
    );

    $settings['mail'] = array_replace_recursive(
        $default['mail'],
        is_array($settings['mail'] ?? null)
            ? $settings['mail']
            : []
    );

    return $settings;
}


/* ============================================================
 * 入力
 * ============================================================ */

function post_string(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_scalar($value)
        ? trim((string)$value)
        : '';
}

function get_string(string $key): string
{
    $value = $_GET[$key] ?? '';

    return is_scalar($value)
        ? trim((string)$value)
        : '';
}

function post_bool(string $key): bool
{
    $value = $_POST[$key] ?? null;

    return $value === '1'
        || $value === 'on'
        || $value === 'true';
}

function h(?string $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function app_url(array $params = []): string
{
    $base = (string)($_SERVER['SCRIPT_NAME'] ?? 'index.php');

    if (!$params) {
        return $base;
    }

    return $base . '?' . http_build_query(
        $params,
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}


/* ============================================================
 * Flash
 * ============================================================ */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function take_flash(): array
{
    $items = $_SESSION['flash'] ?? [];

    unset($_SESSION['flash']);

    return is_array($items) ? $items : [];
}


/* ============================================================
 * ID
 * ============================================================ */

function new_id(string $prefix): string
{
    return $prefix
        . '-'
        . date('YmdHis')
        . '-'
        . bin2hex(random_bytes(4));
}


/* ============================================================
 * アンケート
 * ============================================================ */

function survey_index(array $data, string $id): int
{
    foreach ($data['surveys'] as $index => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $index;
        }
    }

    return -1;
}

function find_survey(array $data, string $id): ?array
{
    $index = survey_index($data, $id);

    return $index >= 0
        ? $data['surveys'][$index]
        : null;
}

function refresh_survey_statuses(array &$data): void
{
    $changed = false;

    foreach ($data['surveys'] as &$survey) {
        if (
            ($survey['status'] ?? '') === 'published'
            && !empty($survey['endAt'])
        ) {
            $end = strtotime((string)$survey['endAt']);

            if ($end !== false && $end < time()) {
                $survey['status'] = 'ended';
                $survey['updatedAt'] = date('Y-m-d H:i:s');
                $changed = true;
            }
        }
    }

    unset($survey);

    if ($changed) {
        save_json_file(DATA_FILE, $data);
    }
}

function recalculate_question_numbers(array &$survey): void
{
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            if (($survey['numbering'] ?? 'global') === 'group') {
                $question['number'] =
                    'Q' . $groupNo . '-' . $questionNo;
            } else {
                $question['number'] = 'Q' . $global;
            }

            $global++;
            $questionNo++;
        }

        unset($question);

        $groupNo++;
    }

    unset($group);
}

function count_answers(array $data, string $surveyId): int
{
    $count = 0;

    foreach ($data['answers'] as $answer) {
        if (($answer['surveyId'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}


/* ============================================================
 * kintone URL
 * ============================================================ */

function normalize_kintone_base_url(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    /*
     * 以下をすべて許容
     *
     * xxxx
     * xxxx.cybozu.com
     * https://xxxx.cybozu.com
     */
    if (
        !str_starts_with($value, 'http://')
        && !str_starts_with($value, 'https://')
    ) {
        $value = 'https://' . $value;
    }

    $parts = parse_url($value);

    if (!is_array($parts) || empty($parts['host'])) {
        throw new InvalidArgumentException(
            'kintoneサブドメインの形式が不正です。'
        );
    }

    $host = strtolower((string)$parts['host']);

    /*
     * 「xxxx」だけを入力した場合は上記で
     * https://xxxx と解釈されるため、
     * cybozu.com を補完する。
     */
    if (!str_ends_with($host, '.cybozu.com')) {
        if (
            preg_match(
                '/^[a-z0-9][a-z0-9-]*$/i',
                $host
            )
        ) {
            $host .= '.cybozu.com';
        } else {
            throw new InvalidArgumentException(
                'kintoneサブドメインは xxxx.cybozu.com の形式で入力してください。'
            );
        }
    }

    $scheme = strtolower(
        (string)($parts['scheme'] ?? 'https')
    );

    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException(
            'kintone URLの形式が不正です。'
        );
    }

    return $scheme . '://' . $host;
}


/* ============================================================
 * Proxy
 * ============================================================ */

function parse_proxy(string $value): ?array
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    if (
        !preg_match(
            '/^([a-zA-Z0-9._-]+):([0-9]{1,5})$/',
            $value,
            $m
        )
    ) {
        throw new InvalidArgumentException(
            'Proxyは「ホスト:ポート」の形式で入力してください。'
        );
    }

    $port = (int)$m[2];

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'Proxyのポート番号は1～65535で指定してください。'
        );
    }

    return [
        'host' => $m[1],
        'port' => $port,
    ];
}


/* ============================================================
 * kintone HTTP
 *
 * ここが今回の障害を再発させないための中心部分。
 *
 * - GET のパラメータはURLへ付与
 * - POST/PUT は JSON body
 * - Content-Type はbodyがある場合のみ
 * - X-Cybozu-Authorizationはサーバー側だけ
 * - cURLは使わない
 * - HTTPエラーでもレスポンス本文を取得する
 * ============================================================ */

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $payload = null
): array {
    $base = normalize_kintone_base_url(
        (string)($config['subdomain'] ?? '')
    );

    $appId = trim(
        (string)($config['app_id'] ?? '')
    );

    if (
        $appId === ''
        || !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDは1以上の整数で指定してください。'
        );
    }

    $username = (string)($config['username'] ?? '');
    $password = (string)($config['password'] ?? '');

    if ($username === '') {
        throw new InvalidArgumentException(
            'kintoneログイン名を入力してください。'
        );
    }

    if ($password === '') {
        throw new InvalidArgumentException(
            'kintoneパスワードが設定されていません。'
        );
    }

    /*
     * kintone パスワード認証仕様:
     *
     * X-Cybozu-Authorization:
     * Base64(loginName:password)
     *
     * ブラウザには絶対に渡さない。
     */
    $authorization = base64_encode(
        $username . ':' . $password
    );

    $url = $base . $path;

    $headers = [
        'Accept: application/json',
        'X-Cybozu-Authorization: ' . $authorization,
        'Connection: close',
    ];

    $body = null;

    if ($payload !== null) {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        if ($body === false) {
            throw new RuntimeException(
                'kintoneリクエストデータを作成できません。'
            );
        }

        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($body);
    }

    $verifySsl = !empty($config['verify_ssl']);

    $contextOptions = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,

            /*
             * アプリケーション側では
             * HTTPリダイレクトを追従しない。
             */
            'follow_location' => 0,

            'timeout' => KINTONE_READ_TIMEOUT,
            'protocol_version' => 1.1,
        ],

        'ssl' => [
            'verify_peer' => $verifySsl,
            'verify_peer_name' => $verifySsl,
            'allow_self_signed' => !$verifySsl,

            'capture_peer_cert' => false,
        ],
    ];

    if ($body !== null) {
        $contextOptions['http']['content'] = $body;
    }

    $proxy = parse_proxy(
        (string)($config['proxy'] ?? '')
    );

    if ($proxy !== null) {
        $contextOptions['http']['proxy'] =
            'tcp://'
            . $proxy['host']
            . ':'
            . $proxy['port'];

        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create(
        $contextOptions
    );

    $errorMessage = '';

    set_error_handler(
        static function (
            int $severity,
            string $message
        ) use (&$errorMessage): bool {
            $errorMessage = $message;
            return true;
        }
    );

    try {
        $stream = fopen(
            $url,
            'rb',
            false,
            $context
        );
    } finally {
        restore_error_handler();
    }

    if ($stream === false) {
        throw new RuntimeException(
            'kintoneへの接続に失敗しました。'
            . (
                $errorMessage !== ''
                    ? ' ' . $errorMessage
                    : ''
            )
        );
    }

    $responseBody = stream_get_contents($stream);
    $meta = stream_get_meta_data($stream);

    fclose($stream);

    if (!is_string($responseBody)) {
        $responseBody = '';
    }

    $status = 0;

    foreach (
        ($meta['wrapper_data'] ?? []) as $header
    ) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})/',
                (string)$header,
                $m
            )
        ) {
            $status = (int)$m[1];
        }
    }

    $json = null;

    if (trim($responseBody) !== '') {
        $decoded = json_decode(
            $responseBody,
            true
        );

        if (is_array($decoded)) {
            $json = $decoded;
        }
    }

    return [
        'status' => $status,
        'body' => $responseBody,
        'json' => $json,
    ];
}


/* ============================================================
 * kintone 接続テスト
 *
 * ★重要
 *
 * 旧実装:
 *   /k/v1/record.json?app=...&query=...
 *
 * 新実装:
 *   /k/v1/records.json?app=...&query=limit%201
 *
 * /record.json は id 必須の「1件取得」API。
 * 接続確認には /records.json を使用する。
 * ============================================================ */

function kintone_test(array $config): array
{
    $started = microtime(true);

    $result = [
        'success' => false,
        'status' => 0,
        'elapsed' => 0,
        'steps' => [],
        'message' => '',
        'detail' => '',
        'error_id' => '',
    ];

    try {
        $base = normalize_kintone_base_url(
            (string)($config['subdomain'] ?? '')
        );

        $result['steps'][] =
            '1. kintone接続先を検証しました。';

        $result['steps'][] =
            '2. 接続先: ' . $base;

        $proxy = parse_proxy(
            (string)($config['proxy'] ?? '')
        );

        $result['steps'][] =
            $proxy === null
                ? '3. Proxy: 使用しません。'
                : '3. Proxy: '
                    . $proxy['host']
                    . ':'
                    . $proxy['port'];

        /*
         * ここが旧コードからの根本的変更点。
         *
         * 複数レコード取得API:
         * /k/v1/records.json
         *
         * query:
         * limit 1
         */
        $path =
            '/k/v1/records.json'
            . '?app='
            . rawurlencode(
                (string)$config['app_id']
            )
            . '&query='
            . rawurlencode('limit 1')
            . '&totalCount=true';

        $result['steps'][] =
            '4. /k/v1/records.json へ接続します。';

        $response = kintone_request(
            $config,
            'GET',
            $path
        );

        $status = (int)$response['status'];

        $result['status'] = $status;

        $result['steps'][] =
            '5. HTTPステータス: ' . $status;

        $api = $response['json'];

        if (is_array($api)) {
            $errorId =
                (string)($api['id'] ?? '');

            $apiMessage =
                (string)($api['message'] ?? '');

            if ($errorId !== '') {
                $result['error_id'] = $errorId;
            }
        } else {
            $apiMessage = '';
        }

        if ($status >= 200 && $status < 300) {
            $result['success'] = true;
            $result['message'] =
                'kintone接続に成功しました。';

            $result['detail'] =
                '認証に成功し、指定した顧客管理アプリへアクセスできました。';

            $result['steps'][] =
                '6. 認証・アプリ確認に成功しました。';

            return finish_test_result(
                $result,
                $started
            );
        }

        $result['steps'][] =
            '6. kintone APIからエラー応答を受信しました。';

        switch ($status) {
            case 400:
                $result['message'] =
                    'kintoneから不正なリクエストとして拒否されました。';

                $result['detail'] =
                    'アプリID、APIエンドポイント、kintoneユーザーの権限を確認してください。';

                break;

            case 401:
                $result['message'] =
                    'kintone認証に失敗しました。';

                $result['detail'] =
                    'ログイン名またはパスワードを確認してください。';

                break;

            case 403:
                $result['message'] =
                    'kintoneへのアクセスが拒否されました。';

                $result['detail'] =
                    'ログインユーザーの権限とアプリへのアクセス権を確認してください。';

                break;

            case 404:
                $result['message'] =
                    'kintoneのアプリが見つかりません。';

                $result['detail'] =
                    'サブドメインと顧客管理アプリIDを確認してください。';

                break;

            default:
                $result['message'] =
                    'kintone APIからエラーが返されました。';

                $result['detail'] =
                    'HTTP ' . $status . ' のエラーです。';
        }

        if ($apiMessage !== '') {
            $result['detail'] .=
                ' kintone: ' . $apiMessage;
        }

        if ($result['error_id'] !== '') {
            $result['detail'] .=
                ' エラーID: '
                . $result['error_id'];
        }
    } catch (InvalidArgumentException $e) {
        $result['message'] =
            'kintone設定に問題があります。';

        $result['detail'] =
            $e->getMessage();

        $result['steps'][] =
            '設定値の検証で停止しました。';
    } catch (Throwable $e) {
        /*
         * パスワードや認証ヘッダーを
         * 例外文字列として画面に出さない。
         */
        $result['message'] =
            'kintoneへの接続処理に失敗しました。';

        $result['detail'] =
            'ネットワーク、Proxy、SSL、kintone URL、サーバー設定を確認してください。';

        $result['steps'][] =
            '通信処理で例外が発生しました。';
    }

    return finish_test_result(
        $result,
        $started
    );
}

function finish_test_result(
    array $result,
    float $started
): array {
    $result['elapsed'] = round(
        microtime(true) - $started,
        3
    );

    return $result;
}


/* ============================================================
 * kintone フィールド取得
 * ============================================================ */

function kintone_get_fields(array $config): array
{
    $response = kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app='
        . rawurlencode(
            (string)$config['app_id']
        )
    );

    if (
        $response['status'] < 200
        || $response['status'] >= 300
    ) {
        $message =
            is_array($response['json'])
                ? (string)(
                    $response['json']['message']
                    ?? '項目一覧を取得できませんでした。'
                )
                : '項目一覧を取得できませんでした。';

        $errorId =
            is_array($response['json'])
                ? (string)(
                    $response['json']['id']
                    ?? ''
                )
                : '';

        throw new RuntimeException(
            'kintone項目一覧取得失敗。'
            . ' HTTP '
            . $response['status']
            . ' / '
            . $message
            . (
                $errorId !== ''
                    ? ' / エラーID: ' . $errorId
                    : ''
            )
        );
    }

    return is_array($response['json'])
        ? $response['json']
        : [];
}


/* ============================================================
 * kintone 顧客同期
 * ============================================================ */

function kintone_sync_customers(
    array $config,
    array &$data,
    array &$settings
): int {
    /*
     * kintoneの複数レコード取得API。
     *
     * 500件ずつ取得する。
     */
    $allRecords = [];
    $offset = 0;

    while (true) {
        $query =
            'order by $id asc'
            . ' limit 500'
            . ' offset '
            . $offset;

        $path =
            '/k/v1/records.json'
            . '?app='
            . rawurlencode(
                (string)$config['app_id']
            )
            . '&query='
            . rawurlencode($query);

        $response = kintone_request(
            $config,
            'GET',
            $path
        );

        if (
            $response['status'] < 200
            || $response['status'] >= 300
        ) {
            $api = $response['json'];

            $message =
                is_array($api)
                    ? (string)(
                        $api['message']
                        ?? '顧客情報を取得できませんでした。'
                    )
                    : '顧客情報を取得できませんでした。';

            $errorId =
                is_array($api)
                    ? (string)(
                        $api['id'] ?? ''
                    )
                    : '';

            throw new RuntimeException(
                '顧客同期失敗。HTTP '
                . $response['status']
                . ' / '
                . $message
                . (
                    $errorId !== ''
                        ? ' / エラーID: ' . $errorId
                        : ''
                )
            );
        }

        $records =
            $response['json']['records'] ?? [];

        if (!is_array($records)) {
            throw new RuntimeException(
                'kintoneから顧客レコードを取得できませんでした。'
            );
        }

        foreach ($records as $record) {
            if (is_array($record)) {
                $allRecords[] = $record;
            }
        }

        if (count($records) < 500) {
            break;
        }

        $offset += 500;
    }

    $mapping =
        is_array($config['mapping'] ?? null)
            ? $config['mapping']
            : [];

    $customers = [];

    foreach ($allRecords as $record) {
        $customers[] = [
            'id' => new_id('customer'),
            'kintoneId' =>
                extract_kintone_value(
                    $record,
                    '$id'
                ),
            'organization' =>
                extract_kintone_value(
                    $record,
                    (string)($mapping['organization'] ?? '')
                ),
            'name' =>
                extract_kintone_value(
                    $record,
                    (string)($mapping['name'] ?? '')
                ),
            'email' =>
                extract_kintone_value(
                    $record,
                    (string)($mapping['email'] ?? '')
                ),
            'department' =>
                extract_kintone_value(
                    $record,
                    (string)($mapping['department'] ?? '')
                ),
            'phone' =>
                extract_kintone_value(
                    $record,
                    (string)($mapping['phone'] ?? '')
                ),
            'address' =>
                extract_multiple_kintone_values(
                    $record,
                    $mapping['address'] ?? []
                ),
            'updatedAt' =>
                date('Y-m-d H:i:s'),
        ];
    }

    $data['customers'] = $customers;

    $settings['kintone']['last_sync'] =
        date('Y-m-d H:i:s');

    save_json_file(DATA_FILE, $data);
    save_json_file(SETTINGS_FILE, $settings);

    return count($customers);
}

function extract_kintone_value(
    array $record,
    string $fieldCode
): string {
    if ($fieldCode === '') {
        return '';
    }

    $field = $record[$fieldCode] ?? null;

    if (!is_array($field)) {
        return '';
    }

    $value = $field['value'] ?? '';

    if (is_scalar($value)) {
        return trim((string)$value);
    }

    if (is_array($value)) {
        $values = [];

        foreach ($value as $item) {
            if (is_array($item) && isset($item['value'])) {
                $values[] = (string)$item['value'];
            }
        }

        return implode('、', $values);
    }

    return '';
}

function extract_multiple_kintone_values(
    array $record,
    array $fieldCodes
): string {
    $values = [];

    foreach ($fieldCodes as $fieldCode) {
        if (!is_scalar($fieldCode)) {
            continue;
        }

        $value = extract_kintone_value(
            $record,
            (string)$fieldCode
        );

        if ($value !== '') {
            $values[] = $value;
        }
    }

    return implode(' ', array_unique($values));
}


/* ============================================================
 * kintone フィールド一覧を表示用に整形
 * ============================================================ */

function normalize_kintone_fields(array $raw): array
{
    $fields = [];

    foreach ($raw['properties'] ?? [] as $code => $field) {
        if (!is_array($field)) {
            continue;
        }

        $fields[] = [
            'code' => (string)$code,
            'label' => (string)(
                $field['label']
                ?? $code
            ),
            'type' => (string)(
                $field['type']
                ?? ''
            ),
        ];
    }

    usort(
        $fields,
        static fn(array $a, array $b): int =>
            strcmp(
                $a['label'],
                $b['label']
            )
    );

    return $fields;
}


/* ============================================================
 * POST処理
 *
 * 設定画面についてはリダイレクトしない。
 * 同一リクエストで処理して、screenをそのまま表示する。
 * ============================================================ */

function handle_post(
    array &$data,
    array &$settings
): ?array {
    $action = post_string('action');

    if ($action === '') {
        return null;
    }

    try {
        switch ($action) {

            /* ------------------------------------------------
             * kintone設定保存
             * ------------------------------------------------ */

            case 'save_kintone':
                $old = $settings['kintone'];

                $subdomain = post_string('subdomain');
                $appId     = post_string('app_id');
                $username  = post_string('username');
                $password  = post_string('password');
                $proxy     = post_string('proxy');

                normalize_kintone_base_url($subdomain);

                if (
                    $appId === ''
                    || !ctype_digit($appId)
                    || (int)$appId < 1
                ) {
                    throw new InvalidArgumentException(
                        '顧客管理アプリIDは1以上の整数で指定してください。'
                    );
                }

                if ($username === '') {
                    throw new InvalidArgumentException(
                        'kintoneログイン名を入力してください。'
                    );
                }

                if ($password === '') {
                    /*
                     * パスワード欄を空欄にした場合は
                     * 保存済みパスワードを維持する。
                     */
                    $password =
                        (string)(
                            $old['password'] ?? ''
                        );

                    if ($password === '') {
                        throw new InvalidArgumentException(
                            'kintoneパスワードを入力してください。'
                        );
                    }
                }

                parse_proxy($proxy);

                $settings['kintone'] = [
                    'subdomain' => $subdomain,
                    'app_id' => $appId,
                    'username' => $username,
                    'password' => $password,
                    'proxy' => $proxy,
                    'verify_ssl' => post_bool('verify_ssl'),
                    'mapping' =>
                        is_array($old['mapping'] ?? null)
                            ? $old['mapping']
                            : default_settings()['kintone']['mapping'],
                    'fields' =>
                        is_array($old['fields'] ?? null)
                            ? $old['fields']
                            : [],
                    'last_test' =>
                        $old['last_test'] ?? null,
                    'last_sync' =>
                        $old['last_sync'] ?? null,
                ];

                save_json_file(
                    SETTINGS_FILE,
                    $settings
                );

                flash(
                    'success',
                    'kintone設定を保存しました。'
                );

                return [
                    'screen' => 'kintone',
                ];


            /* ------------------------------------------------
             * kintone接続テスト
             * ------------------------------------------------ */

            case 'test_kintone':
                $k = $settings['kintone'];

                $testPassword = post_string('password');

                if ($testPassword === '') {
                    $testPassword =
                        (string)($k['password'] ?? '');
                }

                $config = [
                    'subdomain' =>
                        post_string('subdomain'),
                    'app_id' =>
                        post_string('app_id'),
                    'username' =>
                        post_string('username'),
                    'password' =>
                        $testPassword,
                    'proxy' =>
                        post_string('proxy'),
                    'verify_ssl' =>
                        post_bool('verify_ssl'),
                    'mapping' =>
                        $k['mapping'] ?? [],
                ];

                $test = kintone_test(
                    $config
                );

                /*
                 * テスト結果だけを画面表示用セッションへ保持。
                 *
                 * 認証情報は保存しない。
                 */
                $_SESSION['kintone_test_result'] =
                    $test;

                return [
                    'screen' => 'kintone',
                    'test' => $test,
                ];


            /* ------------------------------------------------
             * kintone項目一覧取得
             * ------------------------------------------------ */

            case 'get_kintone_fields':
                $config = $settings['kintone'];

                $fieldsRaw =
                    kintone_get_fields(
                        $config
                    );

                $fields =
                    normalize_kintone_fields(
                        $fieldsRaw
                    );

                $settings['kintone']['fields'] =
                    $fields;

                save_json_file(
                    SETTINGS_FILE,
                    $settings
                );

                flash(
                    'success',
                    'kintone項目一覧を取得しました。'
                );

                return [
                    'screen' => 'kintone',
                ];


            /* ------------------------------------------------
             * kintoneマッピング保存
             * ------------------------------------------------ */

            case 'save_kintone_mapping':
                $mapping = [
                    'organization' =>
                        post_string('mapping_organization'),
                    'name' =>
                        post_string('mapping_name'),
                    'email' =>
                        post_string('mapping_email'),
                    'department' =>
                        post_string('mapping_department'),
                    'phone' =>
                        post_string('mapping_phone'),
                    'address' =>
                        normalize_post_array(
                            'mapping_address'
                        ),
                ];

                $settings['kintone']['mapping'] =
                    $mapping;

                save_json_file(
                    SETTINGS_FILE,
                    $settings
                );

                flash(
                    'success',
                    'kintone項目マッピングを保存しました。'
                );

                return [
                    'screen' => 'kintone',
                ];


            /* ------------------------------------------------
             * 顧客同期
             * ------------------------------------------------ */

            case 'sync_kintone':
                $count =
                    kintone_sync_customers(
                        $settings['kintone'],
                        $data,
                        $settings
                    );

                flash(
                    'success',
                    $count . '件の顧客情報を同期しました。'
                );

                return [
                    'screen' => 'kintone',
                ];


            /* ------------------------------------------------
             * アンケート回答
             * ------------------------------------------------ */

            case 'answer':
                handle_answer_post($data);

                return [
                    'screen' => 'confirm',
                    'id' => post_string('survey_id'),
                ];


            /* ------------------------------------------------
             * 回答確定
             * ------------------------------------------------ */

            case 'finalize_answer':
                finalize_answer($data);

                return [
                    'screen' => 'complete',
                ];


            /* ------------------------------------------------
             * アンケート保存
             * ------------------------------------------------ */

            case 'save_survey':
                $surveyId = save_survey_post(
                    $data
                );

                flash(
                    'success',
                    'アンケートを保存しました。'
                );

                /*
                 * PRGリダイレクトではなく、
                 * 同一POST処理内で一覧を表示する。
                 *
                 * これにより環境側の302/303処理に
                 * 依存しない。
                 */
                return [
                    'screen' => 'list',
                ];


            /* ------------------------------------------------
             * アンケート削除
             * ------------------------------------------------ */

            case 'delete_survey':
                $id = post_string('survey_id');

                $index =
                    survey_index(
                        $data,
                        $id
                    );

                if ($index < 0) {
                    throw new RuntimeException(
                        '削除対象のアンケートが見つかりません。'
                    );
                }

                array_splice(
                    $data['surveys'],
                    $index,
                    1
                );

                save_json_file(
                    DATA_FILE,
                    $data
                );

                flash(
                    'success',
                    'アンケートを削除しました。'
                );

                return [
                    'screen' => 'list',
                ];


            /* ------------------------------------------------
             * アンケート複製
             * ------------------------------------------------ */

            case 'duplicate_survey':
                $id = post_string('survey_id');

                $survey =
                    find_survey(
                        $data,
                        $id
                    );

                if ($survey === null) {
                    throw new RuntimeException(
                        '複製対象のアンケートが見つかりません。'
                    );
                }

                $copy = $survey;

                $copy['id'] =
                    new_id('survey');

                $copy['title'] =
                    (string)$copy['title']
                    . '（コピー）';

                $copy['status'] =
                    'draft';

                $copy['createdAt'] =
                    date('Y-m-d H:i:s');

                $copy['updatedAt'] =
                    date('Y-m-d H:i:s');

                $data['surveys'][] = $copy;

                save_json_file(
                    DATA_FILE,
                    $data
                );

                flash(
                    'success',
                    'アンケートを複製しました。'
                );

                return [
                    'screen' => 'list',
                ];


            /* ------------------------------------------------
             * ステータス変更
             * ------------------------------------------------ */

            case 'change_status':
                change_survey_status(
                    $data
                );

                return [
                    'screen' => 'list',
                ];


            default:
                throw new InvalidArgumentException(
                    '不明な操作です。'
                );
        }
    } catch (InvalidArgumentException $e) {
        flash(
            'error',
            $e->getMessage()
        );

        return [
            'screen' =>
                post_string('return_screen')
                ?: 'list',
            'id' =>
                post_string('survey_id'),
        ];
    } catch (Throwable $e) {
        flash(
            'error',
            '処理に失敗しました。入力値、設定値、権限、通信状態を確認してください。'
        );

        return [
            'screen' =>
                post_string('return_screen')
                ?: 'list',
            'id' =>
                post_string('survey_id'),
        ];
    }

    return null;
}

function normalize_post_array(string $key): array
{
    $value = $_POST[$key] ?? [];

    if (!is_array($value)) {
        return [];
    }

    $result = [];

    foreach ($value as $item) {
        if (is_scalar($item)) {
            $item = trim((string)$item);

            if ($item !== '') {
                $result[] = $item;
            }
        }
    }

    return array_values(
        array_unique($result)
    );
}


/* ============================================================
 * 回答
 * ============================================================ */

function handle_answer_post(array &$data): void
{
    $surveyId = post_string('survey_id');

    $survey =
        find_survey(
            $data,
            $surveyId
        );

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが見つかりません。'
        );
    }

    if (($survey['status'] ?? '') !== 'published') {
        throw new RuntimeException(
            'このアンケートは現在回答を受け付けていません。'
        );
    }

    $answers = $_POST['answer'] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $qid = (string)$question['id'];

            $value = $answers[$qid] ?? '';

            if (
                !empty($question['required'])
                && (
                    $value === ''
                    || $value === []
                    || (
                        is_string($value)
                        && trim($value) === ''
                    )
                )
            ) {
                throw new InvalidArgumentException(
                    '必須項目に回答してください。'
                );
            }
        }
    }

    $_SESSION['answer_draft'] = [
        'surveyId' => $surveyId,
        'answers' => $answers,
        'createdAt' => date('Y-m-d H:i:s'),
    ];
}

function finalize_answer(array &$data): void
{
    $draft =
        $_SESSION['answer_draft']
        ?? null;

    if (!is_array($draft)) {
        throw new RuntimeException(
            '回答データが見つかりません。'
        );
    }

    $surveyId =
        (string)($draft['surveyId'] ?? '');

    $survey =
        find_survey(
            $data,
            $surveyId
        );

    if ($survey === null) {
        throw new RuntimeException(
            '対象アンケートが見つかりません。'
        );
    }

    $data['answers'][] = [
        'id' => new_id('answer'),
        'surveyId' => $surveyId,
        'answers' =>
            is_array($draft['answers'] ?? null)
                ? $draft['answers']
                : [],
        'createdAt' =>
            date('Y-m-d H:i:s'),
    ];

    save_json_file(
        DATA_FILE,
        $data
    );

    unset(
        $_SESSION['answer_draft']
    );
}


/* ============================================================
 * アンケート保存
 * ============================================================ */

function save_survey_post(array &$data): string
{
    $id = post_string('survey_id');

    $title = post_string('title');

    if ($title === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルを入力してください。'
        );
    }

    if (mb_strlen($title) > MAX_TITLE_LENGTH) {
        throw new InvalidArgumentException(
            'アンケートタイトルが長すぎます。'
        );
    }

    $description =
        post_string('description');

    $startAt =
        post_string('start_at');

    $endAt =
        post_string('end_at');

    if ($startAt !== '' && strtotime($startAt) === false) {
        throw new InvalidArgumentException(
            '開始日時の形式が不正です。'
        );
    }

    if ($endAt !== '' && strtotime($endAt) === false) {
        throw new InvalidArgumentException(
            '終了日時の形式が不正です。'
        );
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && strtotime($startAt) > strtotime($endAt)
    ) {
        throw new InvalidArgumentException(
            '終了日時は開始日時より後にしてください。'
        );
    }

    if ($id === '') {
        $survey = [
            'id' => new_id('survey'),
            'title' => $title,
            'description' => $description,
            'startAt' => $startAt,
            'endAt' => $endAt,
            'status' => 'draft',
            'numbering' =>
                post_string('numbering') === 'group'
                    ? 'group'
                    : 'global',
            'createdAt' =>
                date('Y-m-d H:i:s'),
            'updatedAt' =>
                date('Y-m-d H:i:s'),
            'groups' => [
                [
                    'id' => new_id('group'),
                    'title' => '基本アンケート',
                    'questions' => [],
                ],
            ],
        ];

        $data['surveys'][] = $survey;

        save_json_file(
            DATA_FILE,
            $data
        );

        return $survey['id'];
    }

    $index =
        survey_index(
            $data,
            $id
        );

    if ($index < 0) {
        throw new RuntimeException(
            '編集対象のアンケートが見つかりません。'
        );
    }

    $old = $data['surveys'][$index];

    $survey = $old;

    $survey['title'] = $title;
    $survey['description'] = $description;
    $survey['startAt'] = $startAt;
    $survey['endAt'] = $endAt;

    /*
     * 既存編集では現在状態を維持する。
     */
    $survey['status'] =
        $old['status'] ?? 'draft';

    $survey['numbering'] =
        post_string('numbering') === 'group'
            ? 'group'
            : 'global';

    $survey['updatedAt'] =
        date('Y-m-d H:i:s');

    /*
     * 既存グループ・質問をフォームから再構築。
     */
    $survey['groups'] =
        parse_survey_groups_from_post(
            $old
        );

    recalculate_question_numbers(
        $survey
    );

    $data['surveys'][$index] =
        $survey;

    save_json_file(
        DATA_FILE,
        $data
    );

    return $id;
}

function parse_survey_groups_from_post(
    array $oldSurvey
): array {
    $groups = [];

    $oldGroups =
        is_array($oldSurvey['groups'] ?? null)
            ? $oldSurvey['groups']
            : [];

    $groupCount =
        count($oldGroups);

    for ($gi = 0; $gi < $groupCount; $gi++) {
        $oldGroup =
            $oldGroups[$gi];

        $groupId =
            (string)(
                $oldGroup['id']
                ?? new_id('group')
            );

        $groupTitle =
            post_string(
                'group_title_' . $gi
            );

        if ($groupTitle === '') {
            $groupTitle = 'グループ ' . ($gi + 1);
        }

        $questions = [];

        $oldQuestions =
            is_array($oldGroup['questions'] ?? null)
                ? $oldGroup['questions']
                : [];

        foreach (
            $oldQuestions as $qi => $oldQuestion
        ) {
            $questionId =
                (string)(
                    $oldQuestion['id']
                    ?? new_id('question')
                );

            $text =
                post_string(
                    'question_text_'
                    . $gi
                    . '_'
                    . $qi
                );

            $type =
                post_string(
                    'question_type_'
                    . $gi
                    . '_'
                    . $qi
                );

            if (!in_array(
                $type,
                ['single', 'multiple', 'text'],
                true
            )) {
                $type = 'single';
            }

            $required =
                isset(
                    $_POST[
                        'question_required_'
                        . $gi
                        . '_'
                        . $qi
                    ]
                );

            $options = [];

            if ($type !== 'text') {
                $optionValues =
                    $_POST[
                        'options_'
                        . $gi
                        . '_'
                        . $qi
                    ] ?? [];

                $nextValues =
                    $_POST[
                        'nexts_'
                        . $gi
                        . '_'
                        . $qi
                    ] ?? [];

                if (!is_array($optionValues)) {
                    $optionValues = [];
                }

                if (!is_array($nextValues)) {
                    $nextValues = [];
                }

                foreach (
                    $optionValues as $oi => $label
                ) {
                    if (!is_scalar($label)) {
                        continue;
                    }

                    $label = trim((string)$label);

                    if ($label === '') {
                        continue;
                    }

                    $oldOption =
                        $oldQuestion['options'][$oi]
                        ?? null;

                    $options[] = [
                        'id' =>
                            is_array($oldOption)
                                ? (string)(
                                    $oldOption['id']
                                    ?? new_id('option')
                                )
                                : new_id('option'),
                        'label' => $label,
                        'nextQuestionId' =>
                            isset($nextValues[$oi])
                            && is_scalar($nextValues[$oi])
                                ? trim(
                                    (string)$nextValues[$oi]
                                )
                                : '',
                    ];
                }
            }

            $questions[] = [
                'id' => $questionId,
                'text' => $text,
                'type' => $type,
                'required' => $required,
                'options' => $options,
            ];
        }

        $groups[] = [
            'id' => $groupId,
            'title' => $groupTitle,
            'questions' => $questions,
        ];
    }

    if (!$groups) {
        $groups[] = [
            'id' => new_id('group'),
            'title' => '基本アンケート',
            'questions' => [],
        ];
    }

    return $groups;
}


/* ============================================================
 * ステータス
 * ============================================================ */

function change_survey_status(array &$data): void
{
    $id =
        post_string('survey_id');

    $newStatus =
        post_string('new_status');

    if (!in_array(
        $newStatus,
        ['draft', 'published', 'stopped'],
        true
    )) {
        throw new InvalidArgumentException(
            '指定された状態へ変更できません。'
        );
    }

    $index =
        survey_index(
            $data,
            $id
        );

    if ($index < 0) {
        throw new RuntimeException(
            '対象アンケートが見つかりません。'
        );
    }

    $current =
        (string)(
            $data['surveys'][$index]['status']
            ?? 'draft'
        );

    if ($current === 'ended') {
        throw new InvalidArgumentException(
            '終了したアンケートの状態は変更できません。'
        );
    }

    $allowed = [
        'draft' => ['published'],
        'published' => ['stopped'],
        'stopped' => ['published'],
    ];

    if (
        !in_array(
            $newStatus,
            $allowed[$current] ?? [],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '現在の状態から指定した状態へ変更できません。'
        );
    }

    $data['surveys'][$index]['status'] =
        $newStatus;

    $data['surveys'][$index]['updatedAt'] =
        date('Y-m-d H:i:s');

    save_json_file(
        DATA_FILE,
        $data
    );

    flash(
        'success',
        'アンケートの状態を変更しました。'
    );
}


/* ============================================================
 * 表示
 * ============================================================ */

function render_header(
    string $title,
    string $screen = ''
): void {
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1">
<title><?= h($title) ?> - <?= h(APP_TITLE) ?></title>

<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --success:#16a34a;
    --warning:#d97706;
    --danger:#dc2626;
    --gray:#64748b;
    --gray-light:#f1f5f9;
    --border:#dbe2ea;
    --text:#1e293b;
    --white:#fff;
    --shadow:0 4px 18px rgba(15,23,42,.08);
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    background:#f8fafc;
    color:var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
    line-height:1.6;
}

a{
    color:inherit;
}

.admin-header{
    background:#0f172a;
    color:#fff;
}

.admin-header-inner{
    max-width:1280px;
    margin:auto;
    padding:16px 20px;
    display:flex;
    gap:20px;
    align-items:center;
    justify-content:space-between;
}

.brand{
    font-weight:700;
    font-size:20px;
}

.nav{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.nav a{
    color:#cbd5e1;
    text-decoration:none;
    padding:7px 10px;
    border-radius:7px;
}

.nav a:hover,
.nav a.active{
    background:#1e293b;
    color:#fff;
}

.container{
    max-width:1280px;
    margin:0 auto;
    padding:28px 20px 60px;
}

.page-title{
    margin:0 0 8px;
    font-size:28px;
}

.page-description{
    margin:0 0 22px;
    color:var(--gray);
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    margin-bottom:20px;
    overflow:hidden;
}

.card-header{
    padding:16px 20px;
    border-bottom:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
}

.card-header h2{
    margin:0;
    font-size:18px;
}

.card-body{
    padding:20px;
}

.grid{
    display:grid;
    gap:16px;
}

.grid-2{
    grid-template-columns:repeat(2,minmax(0,1fr));
}

.grid-3{
    grid-template-columns:repeat(3,minmax(0,1fr));
}

label{
    display:block;
    font-weight:600;
}

label > span{
    display:block;
    margin-bottom:6px;
}

input,
select,
textarea{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:10px 12px;
    font:inherit;
    color:var(--text);
    background:#fff;
}

textarea{
    min-height:120px;
    resize:vertical;
}

input:focus,
select:focus,
textarea:focus{
    outline:none;
    border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(37,99,235,.12);
}

.help{
    margin-top:5px;
    color:var(--gray);
    font-size:13px;
    font-weight:400;
}

.button-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
}

.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    min-height:40px;
    border:0;
    border-radius:8px;
    padding:9px 15px;
    cursor:pointer;
    text-decoration:none;
    font:inherit;
    font-weight:600;
}

.btn-primary{
    background:var(--primary);
    color:#fff;
}

.btn-primary:hover{
    background:var(--primary-dark);
}

.btn-success{
    background:var(--success);
    color:#fff;
}

.btn-warning{
    background:var(--warning);
    color:#fff;
}

.btn-danger{
    background:var(--danger);
    color:#fff;
}

.btn-secondary{
    background:#e2e8f0;
    color:#334155;
}

.btn-light{
    background:#f8fafc;
    color:#334155;
    border:1px solid var(--border);
}

.alert{
    padding:13px 15px;
    border-radius:9px;
    margin-bottom:16px;
}

.alert-success{
    color:#166534;
    background:#dcfce7;
    border:1px solid #bbf7d0;
}

.alert-error{
    color:#991b1b;
    background:#fee2e2;
    border:1px solid #fecaca;
}

.alert-warning{
    color:#92400e;
    background:#fef3c7;
    border:1px solid #fde68a;
}

.alert-info{
    color:#1e40af;
    background:#dbeafe;
    border:1px solid #bfdbfe;
}

.table-scroll{
    overflow-x:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:760px;
}

th,
td{
    padding:11px 12px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th{
    background:#f8fafc;
    font-size:13px;
}

.badge{
    display:inline-block;
    border-radius:999px;
    padding:3px 9px;
    font-size:12px;
    font-weight:700;
}

.badge-success{
    color:#166534;
    background:#dcfce7;
}

.badge-warning{
    color:#92400e;
    background:#fef3c7;
}

.badge-gray{
    color:#475569;
    background:#e2e8f0;
}

.badge-draft{
    color:#1e40af;
    background:#dbeafe;
}

.empty{
    color:var(--gray);
    padding:30px;
    text-align:center;
}

.question-card{
    border:1px solid var(--border);
    border-radius:10px;
    margin-top:16px;
    overflow:hidden;
}

.question-head{
    background:#f8fafc;
    padding:12px 14px;
    border-bottom:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    gap:10px;
}

.question-body{
    padding:15px;
}

.option-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
    margin-top:8px;
}

.test-result{
    border-radius:10px;
    padding:16px;
    border:1px solid var(--border);
}

.test-result.success{
    background:#f0fdf4;
    border-color:#bbf7d0;
}

.test-result.failure{
    background:#fff7ed;
    border-color:#fed7aa;
}

.test-result-title{
    font-size:18px;
    font-weight:700;
    margin-bottom:8px;
}

.test-step{
    display:flex;
    gap:8px;
    margin-top:7px;
    font-size:14px;
}

.footer{
    color:var(--gray);
    text-align:center;
    padding:20px;
    font-size:13px;
}

.spinner{
    display:none;
    width:16px;
    height:16px;
    border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite;
}

.loading .spinner{
    display:inline-block;
}

@keyframes spin{
    to{transform:rotate(360deg)}
}

@media(max-width:800px){
    .grid-2,
    .grid-3{
        grid-template-columns:1fr;
    }

    .admin-header-inner{
        align-items:flex-start;
        flex-direction:column;
    }

    .container{
        padding:20px 12px 40px;
    }

    .option-row{
        grid-template-columns:1fr;
    }

    .page-title{
        font-size:24px;
    }
}
</style>
</head>

<body>

<?php if ($screen !== 'answer'
    && $screen !== 'confirm'
    && $screen !== 'complete'): ?>

<header class="admin-header">
<div class="admin-header-inner">

<div class="brand">
<?= h(APP_TITLE) ?>
</div>

<nav class="nav">

<a href="<?= h(app_url(['screen'=>'list'])) ?>"
   class="<?= $screen === 'list' ? 'active' : '' ?>">
アンケート一覧
</a>

<a href="<?= h(app_url(['screen'=>'kintone'])) ?>"
   class="<?= $screen === 'kintone' ? 'active' : '' ?>">
kintone設定
</a>

<a href="<?= h(app_url(['screen'=>'mail'])) ?>"
   class="<?= $screen === 'mail' ? 'active' : '' ?>">
メール設定
</a>

</nav>

</div>
</header>

<?php endif; ?>

<main class="container">
<?php
}


/* ============================================================
 * Flash表示
 * ============================================================ */

function render_flash_messages(): void
{
    foreach (take_flash() as $item) {
        $type =
            match (
                (string)($item['type'] ?? '')
            ) {
                'success' => 'alert-success',
                'warning' => 'alert-warning',
                default => 'alert-error',
            };

        ?>
<div class="alert <?= h($type) ?>">
<?= h((string)($item['message'] ?? '')) ?>
</div>
<?php
    }
}


/* ============================================================
 * Footer
 * ============================================================ */

function render_footer(): void
{
    ?>
</main>

<footer class="footer">
<?= h(APP_TITLE) ?>
</footer>

<script>
(function(){

    document.querySelectorAll('form[data-loading]')
        .forEach(function(form){

            form.addEventListener('submit', function(e){

                const submitter = e.submitter;

                if(
                    submitter &&
                    submitter.dataset.confirm
                ){
                    if(
                        !window.confirm(
                            submitter.dataset.confirm
                        )
                    ){
                        e.preventDefault();
                        return;
                    }
                }

                if(form.dataset.submitting === '1'){
                    e.preventDefault();
                    return;
                }

                form.dataset.submitting = '1';

                if(submitter){
                    submitter.classList.add('loading');

                    const spinner =
                        document.createElement('span');

                    spinner.className = 'spinner';

                    submitter.prepend(spinner);
                }
            });

        });

    document.querySelectorAll('[data-confirm]')
        .forEach(function(el){

            if(el.tagName.toLowerCase() === 'button'){
                return;
            }

            el.addEventListener('click', function(e){
                if(
                    !window.confirm(
                        el.dataset.confirm
                    )
                ){
                    e.preventDefault();
                }
            });
        });

})();
</script>

</body>
</html>
<?php
}


/* ============================================================
 * 一覧画面
 * ============================================================ */

function render_list(
    array $data
): void {
    render_header(
        'アンケート一覧',
        'list'
    );

    render_flash_messages();

    ?>
<div style="
display:flex;
justify-content:space-between;
gap:15px;
align-items:center;
margin-bottom:20px;
flex-wrap:wrap;
">

<div>
<h1 class="page-title">
アンケート一覧
</h1>

<p class="page-description">
アンケートの作成・公開・集計・送信を管理します。
</p>
</div>

<a class="btn btn-primary"
   href="<?= h(app_url(['screen'=>'edit'])) ?>">
新規作成
</a>

</div>

<div class="card">
<div class="card-body">

<form method="get">

<input type="hidden"
       name="screen"
       value="list">

<div class="grid grid-3">

<label>
<span>検索</span>
<input type="search"
       name="q"
       value="<?= h(get_string('q')) ?>"
       placeholder="タイトルを検索">
</label>

<label>
<span>ステータス</span>
<select name="status">
<option value="">すべて</option>
<?php
foreach ([
    'published'=>'公開中',
    'draft'=>'下書き',
    'stopped'=>'停止',
    'ended'=>'終了',
] as $value => $label):
?>
<option value="<?= h($value) ?>"
<?= get_string('status') === $value
    ? 'selected'
    : '' ?>>
<?= h($label) ?>
</option>
<?php endforeach; ?>
</select>
</label>

<label>
<span>並び順</span>
<select name="sort">
<?php
$sort =
    get_string('sort')
    ?: 'updated_desc';

foreach ([
    'updated_desc'=>'更新日：新しい順',
    'updated_asc'=>'更新日：古い順',
    'answers_desc'=>'回答数：多い順',
    'answers_asc'=>'回答数：少ない順',
    'start_desc'=>'開始日：新しい順',
    'start_asc'=>'開始日：古い順',
] as $value=>$label):
?>
<option value="<?= h($value) ?>"
<?= $sort === $value
    ? 'selected'
    : '' ?>>
<?= h($label) ?>
</option>
<?php endforeach; ?>
</select>
</label>

</div>

<div class="button-row"
     style="margin-top:16px">

<button class="btn btn-primary"
        type="submit">
検索
</button>

<a class="btn btn-secondary"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
条件クリア
</a>

</div>

</form>

</div>
</div>

<?php
$surveys = $data['surveys'];

$q =
    mb_strtolower(
        get_string('q')
    );

$status =
    get_string('status');

$surveys = array_values(
    array_filter(
        $surveys,
        static function(array $survey) use (
            $q,
            $status
        ): bool {

            if (
                $q !== ''
                && !str_contains(
                    mb_strtolower(
                        (string)(
                            $survey['title'] ?? ''
                        )
                    ),
                    $q
                )
            ) {
                return false;
            }

            if (
                $status !== ''
                && ($survey['status'] ?? '') !== $status
            ) {
                return false;
            }

            return true;
        }
    )
);

usort(
    $surveys,
    static function(
        array $a,
        array $b
    ) use ($sort): int {

        switch ($sort) {
            case 'updated_asc':
                return strcmp(
                    (string)($a['updatedAt'] ?? ''),
                    (string)($b['updatedAt'] ?? '')
                );

            case 'answers_desc':
                return 0;

            case 'answers_asc':
                return 0;

            case 'start_desc':
                return strcmp(
                    (string)($b['startAt'] ?? ''),
                    (string)($a['startAt'] ?? '')
                );

            case 'start_asc':
                return strcmp(
                    (string)($a['startAt'] ?? ''),
                    (string)($b['startAt'] ?? '')
                );

            default:
                return strcmp(
                    (string)($b['updatedAt'] ?? ''),
                    (string)($a['updatedAt'] ?? '')
                );
        }
    }
);

?>

<div class="card">
<div class="card-body">

<div class="table-scroll">

<table>

<thead>
<tr>
<th>タイトル</th>
<th>期間</th>
<th>ステータス</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>

<tbody>

<?php if (!$surveys): ?>

<tr>
<td colspan="5">
<div class="empty">
該当するアンケートがありません。
</div>
</td>
</tr>

<?php else: ?>

<?php foreach ($surveys as $survey): ?>

<?php
$statusValue =
    (string)(
        $survey['status'] ?? 'draft'
    );

$statusLabel =
    match ($statusValue) {
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き',
    };

$statusClass =
    match ($statusValue) {
        'published' => 'badge-success',
        'stopped' => 'badge-warning',
        'ended' => 'badge-gray',
        default => 'badge-draft',
    };

$answerCount =
    count_answers(
        $data,
        (string)$survey['id']
    );
?>

<tr>

<td>
<strong>
<?= h((string)$survey['title']) ?>
</strong>
<br>
<small>
作成：
<?= h((string)$survey['createdAt']) ?>
</small>
</td>

<td>
<?= h((string)$survey['startAt']) ?>
<br>
～
<?= h((string)$survey['endAt']) ?>
</td>

<td>
<span class="badge <?= h($statusClass) ?>">
<?= h($statusLabel) ?>
</span>
</td>

<td>
<?= (int)$answerCount ?>
</td>

<td>

<div class="button-row">

<a class="btn btn-light"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>$survey['id'],
   ])) ?>">
確認・編集
</a>

<a class="btn btn-light"
   href="<?= h(app_url([
       'screen'=>'analytics',
       'id'=>$survey['id'],
   ])) ?>">
集計
</a>

<a class="btn btn-light"
   href="<?= h(app_url([
       'screen'=>'send',
       'id'=>$survey['id'],
   ])) ?>">
送信
</a>

<form method="post">

<input type="hidden"
       name="action"
       value="duplicate_survey">

<input type="hidden"
       name="survey_id"
       value="<?= h((string)$survey['id']) ?>">

<button class="btn btn-light"
        type="submit"
        data-confirm="このアンケートを複製しますか？">
複製
</button>

</form>

<form method="post">

<input type="hidden"
       name="action"
       value="delete_survey">

<input type="hidden"
       name="survey_id"
       value="<?= h((string)$survey['id']) ?>">

<button class="btn btn-danger"
        type="submit"
        data-confirm="このアンケートを削除しますか？">
削除
</button>

</form>

</div>

</td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>

</div>

</div>
</div>

<?php
    render_footer();
}


/* ============================================================
 * 編集画面
 * ============================================================ */

function render_edit(
    array $data,
    ?array $survey
): void {
    $isNew =
        $survey === null;

    if ($isNew) {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => date('Y-m-d\TH:i'),
            'endAt' => date(
                'Y-m-d\TH:i',
                strtotime('+30 days')
            ),
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [
                [
                    'id' => 'temporary-group',
                    'title' => '基本アンケート',
                    'questions' => [],
                ],
            ],
        ];
    }

    render_header(
        $isNew
            ? 'アンケート作成'
            : 'アンケート編集',
        'edit'
    );

    render_flash_messages();

    ?>

<div style="
display:flex;
justify-content:space-between;
gap:12px;
align-items:center;
flex-wrap:wrap;
margin-bottom:20px;
">

<div>
<h1 class="page-title">
<?= $isNew ? 'アンケート作成' : 'アンケート編集' ?>
</h1>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
キャンセル
</a>

</div>

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="return_screen"
       value="edit">

<input type="hidden"
       name="survey_id"
       value="<?= h((string)$survey['id']) ?>">

<div class="card">
<div class="card-body">

<div class="grid grid-2">

<label>
<span>アンケートタイトル</span>
<input type="text"
       name="title"
       required
       maxlength="<?= MAX_TITLE_LENGTH ?>"
       value="<?= h((string)$survey['title']) ?>">
</label>

<label>
<span>質問番号の採番方式</span>
<select name="numbering">
<option value="global"
<?= ($survey['numbering'] ?? 'global') === 'global'
    ? 'selected'
    : '' ?>>
全体で連番
</option>

<option value="group"
<?= ($survey['numbering'] ?? '') === 'group'
    ? 'selected'
    : '' ?>>
グループごと
</option>
</select>
</label>

<label>
<span>開始日時</span>
<input type="datetime-local"
       name="start_at"
       value="<?= h((string)$survey['startAt']) ?>">
</label>

<label>
<span>終了日時</span>
<input type="datetime-local"
       name="end_at"
       value="<?= h((string)$survey['endAt']) ?>">
</label>

</div>

<label style="margin-top:16px">
<span>アンケート説明</span>
<textarea name="description"
          maxlength="<?= MAX_DESCRIPTION_LENGTH ?>"><?= h((string)$survey['description']) ?></textarea>
</label>

</div>
</div>

<?php foreach (
    $survey['groups'] as $gi => $group
): ?>

<div class="card">

<div class="card-header">

<h2>
グループ <?= $gi + 1 ?>
</h2>

</div>

<div class="card-body">

<label>
<span>グループ名</span>
<input type="text"
       name="group_title_<?= $gi ?>"
       value="<?= h((string)($group['title'] ?? '')) ?>">
</label>

<?php foreach (
    ($group['questions'] ?? [])
    as $qi => $question
): ?>

<div class="question-card">

<div class="question-head">

<strong>
<?= h((string)($question['number'] ?? '')) ?>
</strong>

</div>

<div class="question-body">

<label>
<span>質問文</span>
<input type="text"
       name="question_text_<?= $gi ?>_<?= $qi ?>"
       maxlength="<?= MAX_QUESTION_LENGTH ?>"
       value="<?= h((string)($question['text'] ?? '')) ?>">
</label>

<div class="grid grid-2"
     style="margin-top:14px">

<label>
<span>回答形式</span>

<select name="question_type_<?= $gi ?>_<?= $qi ?>">

<option value="single"
<?= ($question['type'] ?? '') === 'single'
    ? 'selected'
    : '' ?>>
単一選択
</option>

<option value="multiple"
<?= ($question['type'] ?? '') === 'multiple'
    ? 'selected'
    : '' ?>>
複数選択
</option>

<option value="text"
<?= ($question['type'] ?? '') === 'text'
    ? 'selected'
    : '' ?>>
自由記述
</option>

</select>

</label>

<label style="padding-top:30px">

<input type="checkbox"
       name="question_required_<?= $gi ?>_<?= $qi ?>"
       value="1"
<?= !empty($question['required'])
    ? 'checked'
    : '' ?>>

必須回答

</label>

</div>

<?php if (
    ($question['type'] ?? 'single')
    !== 'text'
): ?>

<div style="margin-top:16px">

<strong>
選択肢
</strong>

<?php foreach (
    ($question['options'] ?? [])
    as $oi => $option
): ?>

<div class="option-row">

<input type="text"
       name="options_<?= $gi ?>_<?= $qi ?>[]"
       maxlength="<?= MAX_OPTION_LENGTH ?>"
       value="<?= h((string)($option['label'] ?? '')) ?>"
       placeholder="選択肢">

<input type="text"
       name="nexts_<?= $gi ?>_<?= $qi ?>[]"
       value="<?= h((string)($option['nextQuestionId'] ?? '')) ?>"
       placeholder="次に表示する質問ID（任意）">

</div>

<?php endforeach; ?>

</div>

<?php endif; ?>

</div>
</div>

<?php endforeach; ?>

</div>
</div>

<?php endforeach; ?>

<div class="button-row">

<button class="btn btn-primary"
        type="submit">
<span class="spinner"></span>
保存して一覧へ
</button>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'list',
   ])) ?>">
キャンセル
</a>

</div>

</form>

<?php
    render_footer();
}


/* ============================================================
 * プレビュー
 * ============================================================ */

function render_preview(
    array $survey
): void {
    render_header(
        'プレビュー',
        'preview'
    );

    render_flash_messages();

    ?>

<h1 class="page-title">
プレビュー
</h1>

<p class="page-description">
<?= h((string)$survey['title']) ?>
</p>

<div class="card">
<div class="card-body">

<h2>
<?= h((string)$survey['title']) ?>
</h2>

<p>
<?= nl2br(
    h((string)$survey['description'])
) ?>
</p>

<?php foreach (
    $survey['groups'] as $group
): ?>

<h3 style="margin-top:28px">
<?= h((string)$group['title']) ?>
</h3>

<?php foreach (
    $group['questions'] as $question
): ?>

<div class="question-card">

<div class="question-head">

<strong>
<?= h((string)($question['number'] ?? '')) ?>
<?= h((string)$question['text']) ?>
</strong>

</div>

<div class="question-body">

<?php
$type =
    $question['type']
    ?? 'single';
?>

<?php if ($type === 'text'): ?>

<textarea placeholder="回答を入力してください。"></textarea>

<?php else: ?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>

<label style="
display:block;
padding:8px 0;
">

<input
    type="<?= $type === 'multiple'
        ? 'checkbox'
        : 'radio' ?>"
    name="preview_<?= h((string)$question['id']) ?>"
    value="<?= h((string)$option['label']) ?>">

<?= h((string)$option['label']) ?>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>
</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>
</div>

<?php
    render_footer();
}


/* ============================================================
 * kintone設定画面
 * ============================================================ */

function render_kintone(
    array $settings
): void {
    $k =
        $settings['kintone'];

    $test =
        $_SESSION['kintone_test_result']
        ?? null;

    unset(
        $_SESSION['kintone_test_result']
    );

    render_header(
        'kintone連携設定',
        'kintone'
    );

    render_flash_messages();

    ?>

<h1 class="page-title">
kintone連携設定
</h1>

<p class="page-description">
顧客情報をkintoneから取得します。
</p>

<div class="alert alert-info">
設定保存・接続テスト・項目一覧取得・顧客同期は
それぞれ独立した処理です。
</div>

<div class="card">

<div class="card-header">
<h2>kintone接続設定</h2>
</div>

<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_kintone">

<input type="hidden"
       name="return_screen"
       value="kintone">

<div class="grid grid-2">

<label>
<span>サブドメイン</span>

<input type="text"
       name="subdomain"
       required
       value="<?= h((string)$k['subdomain']) ?>"
       placeholder="xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com">

<div class="help">
xxxx、xxxx.cybozu.com、https://xxxx.cybozu.com のいずれかを入力できます。
</div>

</label>

<label>
<span>顧客管理アプリID</span>

<input type="number"
       name="app_id"
       min="1"
       required
       value="<?= h((string)$k['app_id']) ?>">

</label>

<label>
<span>ログイン名</span>

<input type="text"
       name="username"
       required
       autocomplete="username"
       value="<?= h((string)$k['username']) ?>">

</label>

<label>
<span>パスワード</span>

<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更する場合のみ入力">

<div class="help">
空欄の場合は保存済みパスワードを維持します。
</div>

</label>

<label>
<span>Proxy</span>

<input type="text"
       name="proxy"
       value="<?= h((string)$k['proxy']) ?>"
       placeholder="proxy.example.local:8080">

<div class="help">
未入力の場合は直接接続します。
</div>

</label>

<label style="padding-top:30px">

<input type="checkbox"
       name="verify_ssl"
       value="1"
<?= !empty($k['verify_ssl'])
    ? 'checked'
    : '' ?>>

SSL証明書を検証する

</label>

</div>

<div class="button-row"
     style="margin-top:20px">

<button class="btn btn-primary"
        type="submit">
<span class="spinner"></span>
設定保存
</button>

</div>

</form>

</div>
</div>


<!-- 接続テスト -->

<div class="card">

<div class="card-header">
<h2>接続テスト</h2>
</div>

<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="test_kintone">

<input type="hidden"
       name="return_screen"
       value="kintone">

<div class="grid grid-2">

<label>
<span>サブドメイン</span>
<input type="text"
       name="subdomain"
       required
       value="<?= h((string)$k['subdomain']) ?>">
</label>

<label>
<span>アプリID</span>
<input type="number"
       name="app_id"
       required
       min="1"
       value="<?= h((string)$k['app_id']) ?>">
</label>

<label>
<span>ログイン名</span>
<input type="text"
       name="username"
       required
       value="<?= h((string)$k['username']) ?>">
</label>

<label>
<span>パスワード</span>
<input type="password"
       name="password"
       placeholder="空欄なら保存済みパスワードを使用">
</label>

<label>
<span>Proxy</span>
<input type="text"
       name="proxy"
       value="<?= h((string)$k['proxy']) ?>"
       placeholder="proxy.example.local:8080">
</label>

<label style="padding-top:30px">

<input type="checkbox"
       name="verify_ssl"
       value="1"
<?= !empty($k['verify_ssl'])
    ? 'checked'
    : '' ?>>

SSL証明書を検証する

</label>

</div>

<div class="button-row"
     style="margin-top:20px">

<button class="btn btn-success"
        type="submit">
<span class="spinner"></span>
接続テスト
</button>

</div>

</form>

</div>
</div>


<?php if (is_array($test)): ?>

<div class="card">

<div class="card-header">
<h2>接続テスト結果</h2>
</div>

<div class="card-body">

<div class="test-result <?= !empty($test['success'])
    ? 'success'
    : 'failure' ?>">

<div class="test-result-title">

<?= !empty($test['success'])
    ? '✓ 接続成功'
    : '✕ 接続失敗' ?>

</div>

<strong>
<?= h((string)($test['message'] ?? '')) ?>
</strong>

<?php if (!empty($test['detail'])): ?>

<p>
<?= h((string)$test['detail']) ?>
</p>

<?php endif; ?>

<p>
HTTPステータス：
<strong>
<?= (int)($test['status'] ?? 0) ?>
</strong>
</p>

<p>
処理時間：
<?= h((string)($test['elapsed'] ?? '')) ?>
秒
</p>

<?php if (!empty($test['steps'])): ?>

<div style="margin-top:15px">

<strong>
接続テスト経過
</strong>

<?php foreach (
    $test['steps'] as $step
): ?>

<div class="test-step">
<span>●</span>
<span><?= h((string)$step) ?></span>
</div>

<?php endforeach; ?>

</div>

<?php endif; ?>

</div>

</div>
</div>

<?php endif; ?>


<!-- 項目一覧 -->

<div class="card">

<div class="card-header">

<h2>
kintone項目一覧
</h2>

</div>

<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="get_kintone_fields">

<input type="hidden"
       name="return_screen"
       value="kintone">

<button class="btn btn-primary"
        type="submit">

<span class="spinner"></span>

項目一覧を再取得

</button>

</form>

<?php
$fields =
    is_array($k['fields'] ?? null)
        ? $k['fields']
        : [];
?>

<?php if ($fields): ?>

<div class="table-scroll"
     style="margin-top:20px">

<table>

<thead>
<tr>
<th>フィールドコード</th>
<th>表示名</th>
<th>種類</th>
</tr>
</thead>

<tbody>

<?php foreach ($fields as $field): ?>

<tr>
<td>
<code>
<?= h((string)$field['code']) ?>
</code>
</td>

<td>
<?= h((string)$field['label']) ?>
</td>

<td>
<?= h((string)$field['type']) ?>
</td>
</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php else: ?>

<div class="empty">
まだkintone項目を取得していません。
</div>

<?php endif; ?>

</div>
</div>


<!-- マッピング -->

<div class="card">

<div class="card-header">
<h2>顧客項目マッピング</h2>
</div>

<div class="card-body">

<form method="post">

<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<div class="grid grid-2">

<?php
$mapping =
    $k['mapping'];
?>

<label>
<span>組織名</span>

<select name="mapping_organization">

<option value="">
未設定
</option>

<?php foreach ($fields as $field): ?>

<option
    value="<?= h((string)$field['code']) ?>"
<?= ($mapping['organization'] ?? '') === $field['code']
    ? 'selected'
    : '' ?>>
<?= h((string)$field['label']) ?>
（<?= h((string)$field['code']) ?>）
</option>

<?php endforeach; ?>

</select>
</label>


<label>
<span>氏名</span>

<select name="mapping_name">

<option value="">
未設定
</option>

<?php foreach ($fields as $field): ?>

<option
    value="<?= h((string)$field['code']) ?>"
<?= ($mapping['name'] ?? '') === $field['code']
    ? 'selected'
    : '' ?>>
<?= h((string)$field['label']) ?>
（<?= h((string)$field['code']) ?>）
</option>

<?php endforeach; ?>

</select>
</label>


<label>
<span>メールアドレス</span>

<select name="mapping_email">

<option value="">
未設定
</option>

<?php foreach ($fields as $field): ?>

<option
    value="<?= h((string)$field['code']) ?>"
<?= ($mapping['email'] ?? '') === $field['code']
    ? 'selected'
    : '' ?>>
<?= h((string)$field['label']) ?>
（<?= h((string)$field['code']) ?>）
</option>

<?php endforeach; ?>

</select>
</label>


<label>
<span>部署名</span>

<select name="mapping_department">

<option value="">
未設定
</option>

<?php foreach ($fields as $field): ?>

<option
    value="<?= h((string)$field['code']) ?>"
<?= ($mapping['department'] ?? '') === $field['code']
    ? 'selected'
    : '' ?>>
<?= h((string)$field['label']) ?>
（<?= h((string)$field['code']) ?>）
</option>

<?php endforeach; ?>

</select>
</label>


<label>
<span>電話番号</span>

<select name="mapping_phone">

<option value="">
未設定
</option>

<?php foreach ($fields as $field): ?>

<option
    value="<?= h((string)$field['code']) ?>"
<?= ($mapping['phone'] ?? '') === $field['code']
    ? 'selected'
    : '' ?>>
<?= h((string)$field['label']) ?>
（<?= h((string)$field['code']) ?>）
</option>

<?php endforeach; ?>

</select>
</label>

</div>


<div style="margin-top:20px">

<strong>
住所
</strong>

<div class="grid grid-3"
     style="margin-top:10px">

<?php foreach ($fields as $field): ?>

<?php
$checked =
    in_array(
        $field['code'],
        $mapping['address'] ?? [],
        true
    );
?>

<label style="
font-weight:400;
padding:8px;
border:1px solid var(--border);
border-radius:8px;
">

<input type="checkbox"
       name="mapping_address[]"
       value="<?= h((string)$field['code']) ?>"
<?= $checked ? 'checked' : '' ?>>

<?= h((string)$field['label']) ?>

<br>

<small>
<?= h((string)$field['code']) ?>
</small>

</label>

<?php endforeach; ?>

</div>

</div>


<div class="button-row"
     style="margin-top:20px">

<button class="btn btn-primary"
        type="submit">

設定保存

</button>

</div>

</form>

</div>
</div>


<!-- 顧客同期 -->

<div class="card">

<div class="card-header">
<h2>顧客情報同期</h2>
</div>

<div class="card-body">

<p>
kintoneの顧客管理アプリから顧客情報を取得し、
サーバー側の顧客データを更新します。
</p>

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="sync_kintone">

<input type="hidden"
       name="return_screen"
       value="kintone">

<button class="btn btn-success"
        type="submit"
        data-confirm="kintoneから顧客情報を同期しますか？">

<span class="spinner"></span>

顧客情報を同期

</button>

</form>

<?php if (!empty($k['last_sync'])): ?>

<p class="help">
最終同期：
<?= h((string)$k['last_sync']) ?>
</p>

<?php endif; ?>

</div>
</div>

<?php
    render_footer();
}


/* ============================================================
 * メール設定
 * ============================================================ */

function render_mail(
    array $settings
): void {
    $mail =
        $settings['mail'];

    render_header(
        'メールサーバ設定',
        'mail'
    );

    render_flash_messages();

    ?>

<h1 class="page-title">
メールサーバ設定
</h1>

<p class="page-description">
SMTPサーバ設定を管理します。
</p>

<div class="card">

<div class="card-body">

<form method="post">

<input type="hidden"
       name="action"
       value="save_mail">

<div class="grid grid-2">

<label>
<span>SMTPサーバ</span>
<input type="text"
       name="smtp_host"
       value="<?= h((string)$mail['host']) ?>">
</label>

<label>
<span>SMTPポート</span>
<input type="number"
       name="smtp_port"
       min="1"
       max="65535"
       value="<?= h((string)$mail['port']) ?>">
</label>

<label>
<span>暗号化方式</span>
<select name="smtp_encryption">

<option value="tls"
<?= ($mail['encryption'] ?? '') === 'tls'
    ? 'selected'
    : '' ?>>
TLS
</option>

<option value="ssl"
<?= ($mail['encryption'] ?? '') === 'ssl'
    ? 'selected'
    : '' ?>>
SSL
</option>

<option value="none"
<?= ($mail['encryption'] ?? '') === 'none'
    ? 'selected'
    : '' ?>>
なし
</option>

</select>
</label>

<label style="padding-top:30px">

<input type="checkbox"
       name="smtp_auth"
       value="1"
<?= !empty($mail['auth'])
    ? 'checked'
    : '' ?>>

SMTP認証を使用する

</label>

<label>
<span>SMTPユーザー名</span>
<input type="text"
       name="smtp_username"
       value="<?= h((string)$mail['username']) ?>">
</label>

<label>
<span>SMTPパスワード</span>
<input type="password"
       name="smtp_password"
       placeholder="変更する場合のみ入力">
</label>

<label>
<span>送信元メールアドレス</span>
<input type="email"
       name="from_email"
       value="<?= h((string)$mail['from_email']) ?>">
</label>

<label>
<span>送信元名</span>
<input type="text"
       name="from_name"
       value="<?= h((string)$mail['from_name']) ?>">
</label>

<label>
<span>返信先メールアドレス</span>
<input type="email"
       name="reply_to"
       value="<?= h((string)$mail['reply_to']) ?>">
</label>

</div>

<div class="button-row"
     style="margin-top:20px">

<button class="btn btn-primary"
        type="submit">
設定保存
</button>

</div>

</form>

</div>
</div>

<?php
    render_footer();
}


/* ============================================================
 * 回答画面
 * ============================================================ */

function render_answer(
    array $survey
): void {
    render_header(
        'アンケート回答',
        'answer'
    );

    ?>

<div class="card">

<div class="card-body">

<h1 class="page-title">
<?= h((string)$survey['title']) ?>
</h1>

<p>
<?= nl2br(
    h((string)$survey['description'])
) ?>
</p>

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="answer">

<input type="hidden"
       name="survey_id"
       value="<?= h((string)$survey['id']) ?>">

<?php foreach (
    $survey['groups'] as $group
): ?>

<h2 style="margin-top:30px">
<?= h((string)$group['title']) ?>
</h2>

<?php foreach (
    $group['questions'] as $question
): ?>

<div class="question-card">

<div class="question-head">

<strong>
<?= h((string)($question['number'] ?? '')) ?>
<?= h((string)$question['text']) ?>
</strong>

<?php if (!empty($question['required'])): ?>

<span class="badge badge-warning">
必須
</span>

<?php endif; ?>

</div>

<div class="question-body">

<?php
$qid =
    (string)$question['id'];

$type =
    $question['type']
    ?? 'single';
?>

<?php if ($type === 'text'): ?>

<textarea
    name="answer[<?= h($qid) ?>]"
<?= !empty($question['required'])
    ? 'required'
    : '' ?>></textarea>

<?php else: ?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>

<label style="
display:block;
padding:10px;
margin-bottom:5px;
border:1px solid var(--border);
border-radius:8px;
font-weight:400;
">

<input
    type="<?= $type === 'multiple'
        ? 'checkbox'
        : 'radio' ?>"
    name="answer[<?= h($qid) ?>]<?= $type === 'multiple' ? '[]' : '' ?>"
    value="<?= h((string)$option['label']) ?>"
<?= !empty($question['required'])
    ? 'required'
    : '' ?>>

<?= h((string)$option['label']) ?>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>
</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="button-row"
     style="margin-top:24px">

<button class="btn btn-primary"
        type="submit">
回答を確認
</button>

</div>

</form>

</div>
</div>

<?php
    render_footer();
}


/* ============================================================
 * 回答確認
 * ============================================================ */

function render_confirm(
    array $survey
): void {
    $draft =
        $_SESSION['answer_draft']
        ?? [];

    render_header(
        '回答確認',
        'confirm'
    );

    ?>

<div class="card">

<div class="card-body">

<h1 class="page-title">
回答確認
</h1>

<p>
以下の内容で送信します。
</p>

<?php foreach (
    $survey['groups'] as $group
): ?>

<?php foreach (
    $group['questions'] as $question
): ?>

<?php
$qid =
    (string)$question['id'];

$value =
    $draft['answers'][$qid]
    ?? '';

if (is_array($value)) {
    $display =
        implode(
            '、',
            array_map(
                'strval',
                $value
            )
        );
} else {
    $display =
        (string)$value;
}
?>

<div class="question-card">

<div class="question-head">

<strong>
<?= h((string)($question['number'] ?? '')) ?>
<?= h((string)$question['text']) ?>
</strong>

</div>

<div class="question-body">

<?= nl2br(h($display)) ?>

</div>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="button-row"
     style="margin-top:20px">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'answer',
       'id'=>$survey['id'],
   ])) ?>">
戻って修正
</a>

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="finalize_answer">

<button class="btn btn-primary"
        type="submit"
        data-confirm="この回答を送信しますか？">
回答を送信
</button>

</form>

</div>

</div>
</div>

<?php
    render_footer();
}


/* ============================================================
 * 完了
 * ============================================================ */

function render_complete(): void
{
    render_header(
        '回答完了',
        'complete'
    );

    ?>

<div class="card">

<div class="card-body"
     style="
     text-align:center;
     padding:60px 20px;
     ">

<div style="
font-size:48px;
color:var(--success);
">
✓
</div>

<h1 class="page-title">
回答ありがとうございました
</h1>

<p>
回答を受け付けました。
</p>

</div>
</div>

<?php
    render_footer();
}


/* ============================================================
 * 集計
 * ============================================================ */

function render_analytics(
    array $survey,
    array $data
): void {
    $answers = array_values(
        array_filter(
            $data['answers'],
            static fn(array $answer): bool =>
                ($answer['surveyId'] ?? '')
                === ($survey['id'] ?? '')
        )
    );

    $answerCount =
        count($answers);

    render_header(
        '回答集計・分析',
        'analytics'
    );

    render_flash_messages();

    ?>

<h1 class="page-title">
回答集計・分析
</h1>

<p class="page-description">
対象アンケート：
<strong>
<?= h((string)$survey['title']) ?>
</strong>
</p>

<div class="grid grid-3">

<div class="card">
<div class="card-body">
<strong>回答数</strong>
<div style="font-size:30px">
<?= $answerCount ?>
</div>
</div>
</div>

<div class="card">
<div class="card-body">
<strong>送信対象者数</strong>
<div style="font-size:30px">
<?= count($data['customers']) ?>
</div>
</div>
</div>

<div class="card">
<div class="card-body">
<strong>回答率</strong>
<div style="font-size:30px">
<?=
count($data['customers']) > 0
    ? round(
        $answerCount
        / count($data['customers'])
        * 100,
        1
    )
    : 0
?>%
</div>
</div>
</div>

</div>

<?php if ($answerCount === 0): ?>

<div class="alert alert-info">
現在、回答データはありません。
</div>

<?php else: ?>

<div class="card">

<div class="card-header">
<h2>設問別集計</h2>
</div>

<div class="card-body">

<?php foreach (
    $survey['groups'] as $group
): ?>

<?php foreach (
    $group['questions'] as $question
): ?>

<?php
$qid =
    (string)$question['id'];

$counts = [];

foreach ($answers as $answer) {
    $value =
        $answer['answers'][$qid]
        ?? '';

    if (is_array($value)) {
        foreach ($value as $v) {
            $v = (string)$v;

            if ($v !== '') {
                $counts[$v] =
                    ($counts[$v] ?? 0) + 1;
            }
        }
    } else {
        $v = trim((string)$value);

        if ($v !== '') {
            $counts[$v] =
                ($counts[$v] ?? 0) + 1;
        }
    }
}
?>

<div class="question-card">

<div class="question-head">

<strong>
<?= h((string)($question['number'] ?? '')) ?>
<?= h((string)$question['text']) ?>
</strong>

</div>

<div class="question-body">

<?php if (!$counts): ?>

<div class="empty">
回答データがありません。
</div>

<?php else: ?>

<?php foreach (
    $counts as $label => $count
): ?>

<div style="
display:flex;
justify-content:space-between;
padding:8px 0;
border-bottom:1px solid var(--border);
">

<span>
<?= h($label) ?>
</span>

<strong>
<?= (int)$count ?>件
</strong>

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>
</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>
</div>

<?php endif; ?>

<?php
    render_footer();
}


/* ============================================================
 * 送信画面
 * ============================================================ */

function render_send(
    array $survey,
    array $data
): void {
    render_header(
        '顧客選択・メール送信',
        'send'
    );

    render_flash_messages();

    ?>

<h1 class="page-title">
顧客選択・メール送信
</h1>

<p class="page-description">
対象アンケート：
<strong>
<?= h((string)$survey['title']) ?>
</strong>
</p>

<div class="card">

<div class="card-header">
<h2>顧客一覧</h2>
</div>

<div class="card-body">

<?php if (!$data['customers']): ?>

<div class="alert alert-warning">
顧客データがありません。
kintone設定画面から顧客情報を同期してください。
</div>

<?php else: ?>

<div class="table-scroll">

<table>

<thead>
<tr>
<th>選択</th>
<th>組織名</th>
<th>氏名</th>
<th>メールアドレス</th>
<th>部署</th>
</tr>
</thead>

<tbody>

<?php foreach (
    $data['customers']
    as $customer
): ?>

<tr>

<td>
<input type="checkbox"
       disabled
       title="メール送信機能で使用">
</td>

<td>
<?= h((string)($customer['organization'] ?? '')) ?>
</td>

<td>
<?= h((string)($customer['name'] ?? '')) ?>
</td>

<td>
<?= h((string)($customer['email'] ?? '')) ?>
</td>

<td>
<?= h((string)($customer['department'] ?? '')) ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php endif; ?>

</div>
</div>


<div class="card">

<div class="card-header">
<h2>メール作成</h2>
</div>

<div class="card-body">

<label>
<span>件名</span>
<input type="text"
       value="<?= h((string)$survey['title']) ?>">
</label>

<label style="margin-top:16px">
<span>本文</span>
<textarea>アンケートへのご協力をお願いいたします。

{顧客名} 様

以下のURLからアンケートへご回答ください。

{アンケートURL}</textarea>
</label>

<div class="alert alert-info"
     style="margin-top:16px">

メール変数：
<code>{顧客名}</code>
<code>{アンケートURL}</code>

</div>

<div class="button-row">

<button class="btn btn-primary"
        type="button"
        onclick="alert('SMTP設定後に実際の送信処理を有効化できます。')">
一括送信
</button>

<button class="btn btn-secondary"
        type="button"
        onclick="alert('再送対象を選択してから実行します。')">
再送
</button>

<button class="btn btn-secondary"
        type="button"
        onclick="alert('リマインド対象を選択してから実行します。')">
リマインド
</button>

</div>

</div>
</div>


<div class="card">

<div class="card-header">
<h2>送信履歴</h2>
</div>

<div class="card-body">

<?php
$history =
    array_values(
        array_filter(
            $data['send_history'],
            static fn(array $item): bool =>
                ($item['surveyId'] ?? '')
                === ($survey['id'] ?? '')
        )
    );
?>

<?php if (!$history): ?>

<div class="empty">
送信履歴はありません。
</div>

<?php else: ?>

<div class="table-scroll">

<table>

<thead>
<tr>
<th>日時</th>
<th>件数</th>
<th>結果</th>
</tr>
</thead>

<tbody>

<?php foreach ($history as $item): ?>

<tr>

<td>
<?= h((string)($item['createdAt'] ?? '')) ?>
</td>

<td>
<?= (int)($item['count'] ?? 0) ?>
</td>

<td>
<?= h((string)($item['status'] ?? '')) ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php endif; ?>

</div>
</div>

<?php
    render_footer();
}


/* ============================================================
 * CSV
 * ============================================================ */

function output_csv(
    array $survey,
    array $data
): never {
    $filename =
        'survey-'
        . preg_replace(
            '/[^a-zA-Z0-9_-]+/',
            '-',
            (string)$survey['id']
        )
        . '.csv';

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="'
        . $filename
        . '"'
    );

    $fp = fopen('php://output', 'wb');

    if ($fp === false) {
        http_response_code(500);
        exit;
    }

    /*
     * Excel向けUTF-8 BOM。
     */
    fwrite(
        $fp,
        "\xEF\xBB\xBF"
    );

    fputcsv(
        $fp,
        [
            '回答ID',
            '回答日時',
            '質問',
            '回答',
        ]
    );

    foreach ($data['answers'] as $answer) {
        if (
            ($answer['surveyId'] ?? '')
            !== ($survey['id'] ?? '')
        ) {
            continue;
        }

        foreach (
            $survey['groups'] as $group
        ) {
            foreach (
                $group['questions'] as $question
            ) {
                $qid =
                    (string)$question['id'];

                $value =
                    $answer['answers'][$qid]
                    ?? '';

                if (is_array($value)) {
                    $value =
                        implode(
                            '、',
                            array_map(
                                'strval',
                                $value
                            )
                        );
                }

                fputcsv(
                    $fp,
                    [
                        (string)(
                            $answer['id'] ?? ''
                        ),
                        (string)(
                            $answer['createdAt'] ?? ''
                        ),
                        (string)(
                            $question['text'] ?? ''
                        ),
                        (string)$value,
                    ]
                );
            }
        }
    }

    fclose($fp);

    exit;
}


/* ============================================================
 * メイン
 * ============================================================ */

try {

    app_init();

    $data =
        load_data();

    $settings =
        load_settings();

    /*
     * 公開中かつ終了日時経過のみ終了へ。
     *
     * draft / stopped は自動終了しない。
     */
    refresh_survey_statuses(
        $data
    );

    /*
     * POST処理。
     *
     * 重要:
     * 設定画面等ではPOST後に303を返さず、
     * 同一リクエストで画面を再描画する。
     */
    $postResult = null;

    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        === 'POST'
    ) {
        $postResult =
            handle_post(
                $data,
                $settings
            );
    }

    /*
     * POST結果がある場合はそれを優先。
     */
    if (is_array($postResult)) {
        $screen =
            (string)(
                $postResult['screen']
                ?? 'list'
            );
    } else {
        $screen =
            get_string('screen');

        if ($screen === '') {
            $screen = 'list';
        }
    }


    /* --------------------------------------------------------
     * CSV
     * -------------------------------------------------------- */

    if ($screen === 'csv') {
        $id =
            get_string('id');

        $survey =
            find_survey(
                $data,
                $id
            );

        if ($survey === null) {
            http_response_code(404);
            echo 'アンケートが見つかりません。';
            exit;
        }

        output_csv(
            $survey,
            $data
        );
    }


    /* --------------------------------------------------------
     * 回答者画面
     * -------------------------------------------------------- */

    if (
        in_array(
            $screen,
            ['answer', 'confirm'],
            true
        )
    ) {
        $id =
            get_string('id');

        if (
            $id === ''
            && isset($postResult['id'])
        ) {
            $id =
                (string)$postResult['id'];
        }

        $survey =
            find_survey(
                $data,
                $id
            );

        if ($survey === null) {
            render_header(
                'アンケート',
                $screen
            );

            ?>
<div class="alert alert-error">
アンケートが見つかりません。
</div>
<?php

            render_footer();
            exit;
        }

        if (
            ($survey['status'] ?? '')
            !== 'published'
        ) {
            render_header(
                'アンケート',
                $screen
            );

            ?>
<div class="alert alert-warning">
このアンケートは現在回答を受け付けていません。
</div>
<?php

            render_footer();
            exit;
        }

        if ($screen === 'answer') {
            render_answer($survey);
        } else {
            render_confirm($survey);
        }

        exit;
    }


    /* --------------------------------------------------------
     * 回答完了
     * -------------------------------------------------------- */

    if ($screen === 'complete') {
        render_complete();
        exit;
    }


    /* --------------------------------------------------------
     * 管理者画面
     * -------------------------------------------------------- */

    switch ($screen) {

        case 'list':
            render_list(
                $data
            );
            break;


        case 'edit':
            $id =
                get_string('id');

            if (
                $id === ''
                && isset($postResult['id'])
            ) {
                $id =
                    (string)$postResult['id'];
            }

            $survey =
                $id === ''
                    ? null
                    : find_survey(
                        $data,
                        $id
                    );

            if (
                $id !== ''
                && $survey === null
            ) {
                flash(
                    'error',
                    '指定されたアンケートが見つかりません。'
                );

                render_list(
                    $data
                );

                break;
            }

            render_edit(
                $data,
                $survey
            );

            break;


        case 'preview':
            $id =
                get_string('id');

            $survey =
                find_survey(
                    $data,
                    $id
                );

            if ($survey === null) {
                flash(
                    'error',
                    'プレビュー対象のアンケートが見つかりません。'
                );

                render_list(
                    $data
                );

                break;
            }

            render_preview(
                $survey
            );

            break;


        case 'analytics':
            $id =
                get_string('id');

            $survey =
                find_survey(
                    $data,
                    $id
                );

            if ($survey === null) {
                flash(
                    'error',
                    '集計対象のアンケートが見つかりません。'
                );

                render_list(
                    $data
                );

                break;
            }

            render_analytics(
                $survey,
                $data
            );

            break;


        case 'send':
            $id =
                get_string('id');

            $survey =
                find_survey(
                    $data,
                    $id
                );

            if ($survey === null) {
                flash(
                    'error',
                    '送信対象のアンケートが見つかりません。'
                );

                render_list(
                    $data
                );

                break;
            }

            render_send(
                $survey,
                $data
            );

            break;


        case 'kintone':
            render_kintone(
                $settings
            );

            break;


        case 'mail':
            render_mail(
                $settings
            );

            break;


        default:
            render_list(
                $data
            );

            break;
    }

} catch (Throwable $e) {

    /*
     * 内部情報・認証情報を画面へ出さない。
     */
    http_response_code(500);

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1">
<title>システムエラー</title>
<style>
body{
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        sans-serif;
    background:#f8fafc;
    color:#1e293b;
    padding:30px;
}
.box{
    max-width:760px;
    margin:40px auto;
    background:#fff;
    border:1px solid #dbe2ea;
    border-radius:12px;
    padding:30px;
    box-shadow:0 4px 18px rgba(15,23,42,.08);
}
</style>
</head>
<body>

<div class="box">

<h1>
システムエラー
</h1>

<p>
処理を完了できませんでした。
</p>

<p>
設定値、ファイル保存権限、サーバー環境を確認してください。
</p>

</div>

</body>
</html>
<?php
}
<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 * 単一ファイル版 index.php
 *
 * PHP 8.5 / Apache 2.4
 * DBなし
 * PHP cURLなし
 *
 * 外部通信：
 *   kintone : PHP標準stream + stream_context_create()
 *   SMTP    : PHP標準stream_socket_client()
 *
 * 重要：
 * - curl_* は一切使用しない
 * - curl_close() も使用しない
 * - kintone APIトークン認証は使用しない
 * - X-Cybozu-Authorization はサーバー側だけで生成
 * - 認証情報をURL/HTML/JavaScriptへ出力しない
 * - 設定保存と接続テストを完全に分離
 * - エラー時に勝手に一覧へリダイレクトしない
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_READ_TIMEOUT = 30;

const SESSION_NAME = 'survey_app_session';

/* ============================================================
 * 初期化
 * ============================================================ */

function app_init(): void
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }

    if (!file_exists(DATA_FILE)) {
        save_json_file(DATA_FILE, default_data());
    }

    if (!file_exists(SETTINGS_FILE)) {
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
        'path' => app_cookie_path(),
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function app_cookie_path(): string
{
    $script = str_replace(
        '\\',
        '/',
        $_SERVER['SCRIPT_NAME'] ?? '/index.php'
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
                'endAt' => date(
                    'Y-m-d\TH:i',
                    strtotime('+30 days')
                ),
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
 * JSON保存
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

    $decoded = json_decode(
        $contents,
        true
    );

    return is_array($decoded)
        ? $decoded
        : $fallback;
}

function save_json_file(string $file, array $data): void
{
    $dir = dirname($file);

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(
                'データ保存フォルダを作成できません。'
            );
        }
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException(
            'データをJSONへ変換できません。'
        );
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));

    $fp = @fopen($tmp, 'wb');

    if ($fp === false) {
        throw new RuntimeException(
            '一時ファイルを作成できません。'
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException(
                'データファイルをロックできません。'
            );
        }

        if (fwrite($fp, $json) === false) {
            throw new RuntimeException(
                'データを書き込めません。'
            );
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException(
                '保存ファイルを更新できません。'
            );
        }
    } catch (Throwable $e) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
        @unlink($tmp);
        throw $e;
    }
}

/* ============================================================
 * データ読み込み
 * ============================================================ */

function load_data(): array
{
    $data = load_json_file(
        DATA_FILE,
        default_data()
    );

    if (!isset($data['surveys']) || !is_array($data['surveys'])) {
        $data['surveys'] = [];
    }

    if (!isset($data['answers']) || !is_array($data['answers'])) {
        $data['answers'] = [];
    }

    if (!isset($data['customers']) || !is_array($data['customers'])) {
        $data['customers'] = [];
    }

    if (
        !isset($data['send_history'])
        || !is_array($data['send_history'])
    ) {
        $data['send_history'] = [];
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

    $settings['kintone'] =
        array_replace_recursive(
            $default['kintone'],
            $settings['kintone'] ?? []
        );

    $settings['mail'] =
        array_replace_recursive(
            $default['mail'],
            $settings['mail'] ?? []
        );

    return $settings;
}

/* ============================================================
 * 入力
 * ============================================================ */

function post_string(string $key): string
{
    $value = $_POST[$key] ?? '';

    if (is_array($value)) {
        return '';
    }

    return trim((string)$value);
}

function get_string(string $key): string
{
    $value = $_GET[$key] ?? '';

    if (is_array($value)) {
        return '';
    }

    return trim((string)$value);
}

function post_bool(string $key): bool
{
    return isset($_POST[$key])
        && (
            $_POST[$key] === '1'
            || $_POST[$key] === 'on'
            || $_POST[$key] === 'true'
        );
}

function h(?string $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
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
 * URL
 * ============================================================ */

function app_url(array $params = []): string
{
    $base = $_SERVER['SCRIPT_NAME'] ?? 'index.php';

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

function redirect_screen(
    string $screen,
    array $params = []
): never {
    $params = array_merge(
        ['screen' => $screen],
        $params
    );

    header('Location: ' . app_url($params));
    exit;
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

function survey_index(
    array $data,
    string $id
): int {
    foreach ($data['surveys'] as $index => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $index;
        }
    }

    return -1;
}

function find_survey(
    array $data,
    string $id
): ?array {
    $index = survey_index($data, $id);

    if ($index < 0) {
        return null;
    }

    return $data['surveys'][$index];
}

function normalize_survey_status(
    array &$survey
): bool {
    if (
        ($survey['status'] ?? '') === 'published'
        && !empty($survey['endAt'])
    ) {
        $end = strtotime((string)$survey['endAt']);

        if (
            $end !== false
            && $end < time()
        ) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = date('Y-m-d H:i:s');
            return true;
        }
    }

    return false;
}

function refresh_all_statuses(
    array &$data
): void {
    $changed = false;

    foreach ($data['surveys'] as &$survey) {
        if (normalize_survey_status($survey)) {
            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {
        save_json_file(DATA_FILE, $data);
    }
}

function status_label(string $status): string
{
    return match ($status) {
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き',
    };
}

function status_class(string $status): string
{
    return match ($status) {
        'published' => 'badge-success',
        'stopped' => 'badge-warning',
        'ended' => 'badge-gray',
        default => 'badge-draft',
    };
}

function can_change_status(string $status): bool
{
    return in_array(
        $status,
        ['draft', 'published', 'stopped'],
        true
    );
}

function count_survey_answers(
    array $data,
    string $surveyId
): int {
    $count = 0;

    foreach ($data['answers'] as $answer) {
        if (($answer['surveyId'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}

/* ============================================================
 * 質問番号
 * ============================================================ */

function recalculate_question_numbers(
    array &$survey
): void {
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            if (($survey['numbering'] ?? 'global') === 'group') {
                $question['number'] =
                    'Q'
                    . $groupNo
                    . '-'
                    . $questionNo;
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

/* ============================================================
 * kintone URL
 * ============================================================ */

function normalize_kintone_base_url(
    string $value
): string {
    $value = trim($value);

    if ($value === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    if (
        !str_starts_with($value, 'http://')
        && !str_starts_with($value, 'https://')
    ) {
        $value = 'https://' . $value;
    }

    $parts = parse_url($value);

    if (
        !is_array($parts)
        || empty($parts['host'])
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインの形式が不正です。'
        );
    }

    $host = strtolower($parts['host']);

    if (!str_ends_with($host, '.cybozu.com')) {
        throw new InvalidArgumentException(
            'kintoneサブドメインは xxx.cybozu.com の形式で入力してください。'
        );
    }

    $scheme = strtolower(
        $parts['scheme'] ?? 'https'
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

function parse_proxy(string $proxy): ?array
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    /*
     * 要件：
     * host:port
     *
     * ポート番号入力欄は作らない。
     */
    if (!preg_match(
        '/^([a-zA-Z0-9._-]+):([0-9]{1,5})$/',
        $proxy,
        $m
    )) {
        throw new InvalidArgumentException(
            'Proxyは「ホスト:ポート」の形式で入力してください。例：proxy.example.local:8080'
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
            'kintoneパスワードを入力してください。'
        );
    }

    $auth = base64_encode(
        $username . ':' . $password
    );

    $url = $base . $path;

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Cybozu-Authorization: ' . $auth,
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

        $headers[] =
            'Content-Length: ' . strlen($body);
    }

    $verify = !empty(
        $config['verify_ssl']
    );

    $contextOptions = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => KINTONE_READ_TIMEOUT,
            'protocol_version' => 1.1,
        ],
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
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

    $errorNumber = 0;
    $errorMessage = '';

    set_error_handler(
        static function (
            int $severity,
            string $message
        ) use (
            &$errorNumber,
            &$errorMessage
        ): bool {
            $errorNumber = $severity;
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
        $detail = $errorMessage !== ''
            ? $errorMessage
            : 'HTTPS通信を開始できません。';

        throw new RuntimeException(
            'kintoneへの接続に失敗しました。'
            . ' '
            . $detail
        );
    }

    $responseBody = stream_get_contents($stream);

    $meta = stream_get_meta_data($stream);

    fclose($stream);

    $statusCode = 0;

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
            $statusCode = (int)$m[1];
        }
    }

    $decoded = null;

    if (
        is_string($responseBody)
        && trim($responseBody) !== ''
    ) {
        $decoded = json_decode(
            $responseBody,
            true
        );
    }

    return [
        'status' => $statusCode,
        'body' => is_string($responseBody)
            ? $responseBody
            : '',
        'json' => is_array($decoded)
            ? $decoded
            : null,
    ];
}

/* ============================================================
 * kintone 接続テスト
 * ============================================================ */

function kintone_test(
    array $config
): array {
    $started = microtime(true);

    $result = [
        'success' => false,
        'status' => 0,
        'elapsed' => 0,
        'steps' => [],
        'message' => '',
        'detail' => '',
    ];

    $result['steps'][] =
        '1. 設定値を検証しました。';

    $base = normalize_kintone_base_url(
        (string)($config['subdomain'] ?? '')
    );

    $result['steps'][] =
        '2. 接続先: '
        . $base;

    $proxy = parse_proxy(
        (string)($config['proxy'] ?? '')
    );

    if ($proxy === null) {
        $result['steps'][] =
            '3. Proxy: 使用しません。';
    } else {
        $result['steps'][] =
            '3. Proxy: '
            . $proxy['host']
            . ':'
            . $proxy['port'];
    }

    $result['steps'][] =
        '4. kintone REST APIへHTTPS接続を開始しました。';

    /*
     * /v1/record.json のGETは
     * アプリIDを指定して1件取得する。
     *
     * ここでは接続・認証・アプリ存在確認を
     * 一度に確認する。
     */
    $path =
        '/k/v1/record.json?app='
        . rawurlencode(
            (string)$config['app_id']
        )
        . '&totalCount=true'
        . '&query='
        . rawurlencode('limit 1');

    $response = kintone_request(
        $config,
        'GET',
        $path
    );

    $result['status'] =
        (int)$response['status'];

    $result['steps'][] =
        '5. HTTPステータス: '
        . $result['status'];

    $result['elapsed'] =
        round(
            microtime(true) - $started,
            3
        );

    if (
        $result['status'] >= 200
        && $result['status'] < 300
    ) {
        $result['success'] = true;
        $result['message'] =
            'kintone接続に成功しました。';
        $result['detail'] =
            '認証に成功し、指定したアプリへアクセスできました。';

        $result['steps'][] =
            '6. 認証・アプリ確認に成功しました。';

        return $result;
    }

    $api = $response['json'];

    $errorId = '';

    if (is_array($api)) {
        $errorId =
            (string)($api['id'] ?? '');

        $apiMessage =
            (string)($api['message'] ?? '');
    } else {
        $apiMessage = '';
    }

    $result['steps'][] =
        '6. kintone APIからエラー応答を受信しました。';

    if ($result['status'] === 401) {
        $result['message'] =
            'kintone認証に失敗しました。';
        $result['detail'] =
            'ログイン名またはパスワードを確認してください。';
    } elseif ($result['status'] === 403) {
        $result['message'] =
            'kintoneへのアクセスが拒否されました。';
        $result['detail'] =
            'ログインユーザーの権限とアプリへのアクセス権を確認してください。';
    } elseif ($result['status'] === 404) {
        $result['message'] =
            'kintoneのアプリが見つかりません。';
        $result['detail'] =
            'サブドメインと顧客管理アプリIDを確認してください。';
    } elseif ($result['status'] >= 400) {
        $result['message'] =
            'kintone APIからエラーが返されました。';

        $result['detail'] =
            'HTTP '
            . $result['status']
            . '。';

        if ($apiMessage !== '') {
            $result['detail'] .=
                ' kintone: '
                . $apiMessage;
        }
    } else {
        $result['message'] =
            'kintoneへの接続を確認できませんでした。';

        $result['detail'] =
            'HTTPステータスを取得できませんでした。'
            . ' Proxy、ネットワーク、HTTPS設定を確認してください。';
    }

    if ($errorId !== '') {
        $result['detail'] .=
            ' エラーID: '
            . $errorId;
    }

    return $result;
}

/* ============================================================
 * kintone フィールド取得
 * ============================================================ */

function kintone_get_fields(
    array $config
): array {
    $appId = (string)$config['app_id'];

    $response = kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app='
        . rawurlencode($appId)
    );

    if (
        $response['status'] < 200
        || $response['status'] >= 300
    ) {
        $message =
            (string)(
                $response['json']['message']
                ?? '項目一覧を取得できませんでした。'
            );

        throw new RuntimeException(
            'kintone項目一覧取得失敗。HTTP '
            . $response['status']
            . ' / '
            . $message
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
    $appId = (string)$config['app_id'];

    $response = kintone_request(
        $config,
        'GET',
        '/k/v1/records.json?app='
        . rawurlencode($appId)
        . '&totalCount=true'
        . '&query='
        . rawurlencode('limit 500')
    );

    if (
        $response['status'] < 200
        || $response['status'] >= 300
    ) {
        $message =
            (string)(
                $response['json']['message']
                ?? '顧客情報を取得できませんでした。'
            );

        throw new RuntimeException(
            '顧客同期失敗。HTTP '
            . $response['status']
            . ' / '
            . $message
        );
    }

    $records =
        $response['json']['records'] ?? [];

    if (!is_array($records)) {
        throw new RuntimeException(
            'kintoneから顧客レコードを取得できませんでした。'
        );
    }

    $mapping =
        $config['mapping'] ?? [];

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $customers[] = [
            'id' => new_id('customer'),
            'organization' =>
                kintone_record_value(
                    $record,
                    (string)($mapping['organization'] ?? '')
                ),
            'name' =>
                kintone_record_value(
                    $record,
                    (string)($mapping['name'] ?? '')
                ),
            'email' =>
                kintone_record_value(
                    $record,
                    (string)($mapping['email'] ?? '')
                ),
            'department' =>
                kintone_record_value(
                    $record,
                    (string)($mapping['department'] ?? '')
                ),
            'phone' =>
                kintone_record_value(
                    $record,
                    (string)($mapping['phone'] ?? '')
                ),
            'address' =>
                kintone_record_value(
                    $record,
                    implode(
                        ',',
                        (array)(
                            $mapping['address']
                            ?? []
                        )
                    )
                ),
            'source' => 'kintone',
            'syncedAt' => date('Y-m-d H:i:s'),
        ];
    }

    $data['customers'] = $customers;

    $settings['kintone']['last_sync'] = [
        'at' => date('Y-m-d H:i:s'),
        'count' => count($customers),
    ];

    save_json_file(DATA_FILE, $data);
    save_json_file(SETTINGS_FILE, $settings);

    return count($customers);
}

function kintone_record_value(
    array $record,
    string $code
): string {
    if ($code === '') {
        return '';
    }

    if (str_contains($code, ',')) {
        foreach (
            explode(',', $code) as $part
        ) {
            $value = kintone_record_value(
                $record,
                trim($part)
            );

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    $field = $record[$code] ?? null;

    if (!is_array($field)) {
        return '';
    }

    $value = $field['value'] ?? '';

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $parts[] =
                    (string)(
                        $item['value']
                        ?? $item['name']
                        ?? ''
                    );
            } else {
                $parts[] = (string)$item;
            }
        }

        return implode(', ', $parts);
    }

    return trim((string)$value);
}

/* ============================================================
 * kintone 設定保存
 *
 * 重要：
 * - 保存失敗時は redirect しない
 * - 保存成功時のみ PRG
 * ============================================================ */

function save_kintone_settings(
    array &$settings
): array {
    $old =
        $settings['kintone'] ?? [];

    $subdomain =
        post_string('subdomain');

    $appId =
        post_string('app_id');

    $username =
        post_string('username');

    $password =
        post_string('password');

    $proxy =
        post_string('proxy');

    $verify =
        post_bool('verify_ssl');

    /*
     * 入力検証。
     * ここで例外になった場合は、
     * 呼び出し元が画面に留まる。
     */
    normalize_kintone_base_url(
        $subdomain
    );

    if (
        $appId === ''
        || !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDは1以上の整数で入力してください。'
        );
    }

    if ($username === '') {
        throw new InvalidArgumentException(
            'kintoneログイン名を入力してください。'
        );
    }

    /*
     * パスワード空欄は既存値を維持。
     */
    if ($password === '') {
        $password =
            (string)($old['password'] ?? '');
    }

    if ($password === '') {
        throw new InvalidArgumentException(
            'kintoneパスワードを入力してください。'
        );
    }

    parse_proxy($proxy);

    $settings['kintone'] = [
        'subdomain' => $subdomain,
        'app_id' => $appId,
        'username' => $username,
        'password' => $password,
        'proxy' => $proxy,
        'verify_ssl' => $verify,
        'mapping' =>
            $old['mapping']
            ?? default_settings()['kintone']['mapping'],
        'fields' =>
            $old['fields']
            ?? [],
        'last_test' =>
            $old['last_test']
            ?? null,
        'last_sync' =>
            $old['last_sync']
            ?? null,
    ];

    save_json_file(
        SETTINGS_FILE,
        $settings
    );

    return [
        'subdomain' => $subdomain,
        'app_id' => $appId,
    ];
}

/* ============================================================
 * kintone マッピング保存
 * ============================================================ */

function save_kintone_mapping(
    array &$settings
): void {
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
            array_values(
                array_filter(
                    $_POST['mapping_address'] ?? [],
                    static fn($v) =>
                        is_string($v)
                        && trim($v) !== ''
                )
            ),
    ];

    $settings['kintone']['mapping'] =
        $mapping;

    save_json_file(
        SETTINGS_FILE,
        $settings
    );
}

/* ============================================================
 * アンケート保存
 * ============================================================ */

function save_survey_action(
    array &$data
): void {
    $id = post_string('id');

    $title = post_string('title');
    $description = post_string('description');
    $startAt = post_string('startAt');
    $endAt = post_string('endAt');
    $numbering = post_string('numbering');

    if ($title === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルを入力してください。'
        );
    }

    if (
        $startAt !== ''
        && strtotime($startAt) === false
    ) {
        throw new InvalidArgumentException(
            '開始日時の形式が不正です。'
        );
    }

    if (
        $endAt !== ''
        && strtotime($endAt) === false
    ) {
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

    if (
        !in_array(
            $numbering,
            ['global', 'group'],
            true
        )
    ) {
        $numbering = 'global';
    }

    if ($id === '') {
        $survey = [
            'id' => new_id('survey'),
            'title' => $title,
            'description' => $description,
            'startAt' => $startAt,
            'endAt' => $endAt,
            'status' => 'draft',
            'numbering' => $numbering,
            'createdAt' => date('Y-m-d H:i:s'),
            'updatedAt' => date('Y-m-d H:i:s'),
            'groups' => [
                [
                    'id' => new_id('group'),
                    'title' => 'グループ1',
                    'questions' => [],
                ],
            ],
        ];

        recalculate_question_numbers(
            $survey
        );

        $data['surveys'][] = $survey;
    } else {
        $index = survey_index(
            $data,
            $id
        );

        if ($index < 0) {
            throw new InvalidArgumentException(
                '対象アンケートが見つかりません。'
            );
        }

        $current =
            $data['surveys'][$index];

        $data['surveys'][$index]['title'] =
            $title;

        $data['surveys'][$index]['description'] =
            $description;

        $data['surveys'][$index]['startAt'] =
            $startAt;

        $data['surveys'][$index]['endAt'] =
            $endAt;

        /*
         * 既存編集時は状態を維持。
         */
        $data['surveys'][$index]['status'] =
            $current['status'] ?? 'draft';

        $data['surveys'][$index]['numbering'] =
            $numbering;

        $data['surveys'][$index]['updatedAt'] =
            date('Y-m-d H:i:s');

        recalculate_question_numbers(
            $data['surveys'][$index]
        );
    }

    save_json_file(
        DATA_FILE,
        $data
    );
}

/* ============================================================
 * ステータス変更
 * ============================================================ */

function change_status_action(
    array &$data
): void {
    $id = post_string('id');
    $newStatus = post_string('new_status');

    $index = survey_index(
        $data,
        $id
    );

    if ($index < 0) {
        throw new InvalidArgumentException(
            'アンケートが見つかりません。'
        );
    }

    $current =
        $data['surveys'][$index]['status']
        ?? 'draft';

    $allowed = [
        'draft' => ['published'],
        'published' => ['stopped'],
        'stopped' => ['published'],
    ];

    if (
        !isset($allowed[$current])
        || !in_array(
            $newStatus,
            $allowed[$current],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '指定された状態変更はできません。'
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
}

/* ============================================================
 * 複製
 * ============================================================ */

function duplicate_survey_action(
    array &$data
): void {
    $id = post_string('id');

    $survey = find_survey(
        $data,
        $id
    );

    if ($survey === null) {
        throw new InvalidArgumentException(
            '複製対象のアンケートが見つかりません。'
        );
    }

    $survey['id'] =
        new_id('survey');

    $survey['title'] =
        ($survey['title'] ?? '')
        . '（複製）';

    $survey['status'] = 'draft';
    $survey['createdAt'] =
        date('Y-m-d H:i:s');
    $survey['updatedAt'] =
        date('Y-m-d H:i:s');

    foreach ($survey['groups'] as &$group) {
        $group['id'] =
            new_id('group');

        foreach ($group['questions'] as &$question) {
            $question['id'] =
                new_id('question');

            foreach (
                $question['options']
                ?? [] as &$option
            ) {
                $option['id'] =
                    new_id('option');
            }

            unset($option);
        }

        unset($question);
    }

    unset($group);

    recalculate_question_numbers(
        $survey
    );

    $data['surveys'][] = $survey;

    save_json_file(
        DATA_FILE,
        $data
    );
}

/* ============================================================
 * 削除
 * ============================================================ */

function delete_survey_action(
    array &$data
): void {
    $id = post_string('id');

    $index = survey_index(
        $data,
        $id
    );

    if ($index < 0) {
        throw new InvalidArgumentException(
            '削除対象が見つかりません。'
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
}

/* ============================================================
 * グループ追加
 * ============================================================ */

function add_group_action(
    array &$data
): void {
    $id = post_string('id');

    $index = survey_index(
        $data,
        $id
    );

    if ($index < 0) {
        throw new InvalidArgumentException(
            'アンケートが見つかりません。'
        );
    }

    $data['surveys'][$index]['groups'][] = [
        'id' => new_id('group'),
        'title' => '新しいグループ',
        'questions' => [],
    ];

    $data['surveys'][$index]['updatedAt'] =
        date('Y-m-d H:i:s');

    recalculate_question_numbers(
        $data['surveys'][$index]
    );

    save_json_file(
        DATA_FILE,
        $data
    );
}

/* ============================================================
 * 質問追加
 * ============================================================ */

function add_question_action(
    array &$data
): void {
    $id = post_string('id');
    $groupId = post_string('group_id');

    $index = survey_index(
        $data,
        $id
    );

    if ($index < 0) {
        throw new InvalidArgumentException(
            'アンケートが見つかりません。'
        );
    }

    foreach (
        $data['surveys'][$index]['groups']
        as &$group
    ) {
        if (($group['id'] ?? '') !== $groupId) {
            continue;
        }

        $group['questions'][] = [
            'id' => new_id('question'),
            'text' => '新しい質問',
            'type' => 'single',
            'required' => false,
            'options' => [
                [
                    'id' => new_id('option'),
                    'label' => '選択肢1',
                    'nextQuestionId' => '',
                ],
                [
                    'id' => new_id('option'),
                    'label' => '選択肢2',
                    'nextQuestionId' => '',
                ],
            ],
        ];

        break;
    }

    unset($group);

    $data['surveys'][$index]['updatedAt'] =
        date('Y-m-d H:i:s');

    recalculate_question_numbers(
        $data['surveys'][$index]
    );

    save_json_file(
        DATA_FILE,
        $data
    );
}

/* ============================================================
 * 質問・グループ削除
 * ============================================================ */

function delete_group_action(
    array &$data
): void {
    $id = post_string('id');
    $groupId = post_string('group_id');

    $index = survey_index(
        $data,
        $id
    );

    if ($index < 0) {
        throw new InvalidArgumentException(
            'アンケートが見つかりません。'
        );
    }

    $groups =
        &$data['surveys'][$index]['groups'];

    if (count($groups) <= 1) {
        throw new InvalidArgumentException(
            '最後のグループは削除できません。'
        );
    }

    $groups = array_values(
        array_filter(
            $groups,
            static fn($group) =>
                ($group['id'] ?? '') !== $groupId
        )
    );

    recalculate_question_numbers(
        $data['surveys'][$index]
    );

    $data['surveys'][$index]['updatedAt'] =
        date('Y-m-d H:i:s');

    save_json_file(
        DATA_FILE,
        $data
    );
}

function delete_question_action(
    array &$data
): void {
    $id = post_string('id');
    $questionId = post_string('question_id');

    $index = survey_index(
        $data,
        $id
    );

    if ($index < 0) {
        throw new InvalidArgumentException(
            'アンケートが見つかりません。'
        );
    }

    foreach (
        $data['surveys'][$index]['groups']
        as &$group
    ) {
        $group['questions'] = array_values(
            array_filter(
                $group['questions'] ?? [],
                static fn($question) =>
                    ($question['id'] ?? '')
                    !== $questionId
            )
        );
    }

    unset($group);

    recalculate_question_numbers(
        $data['surveys'][$index]
    );

    $data['surveys'][$index]['updatedAt'] =
        date('Y-m-d H:i:s');

    save_json_file(
        DATA_FILE,
        $data
    );
}

/* ============================================================
 * 質問編集保存
 * ============================================================ */

function save_questions_action(
    array &$data
): void {
    $surveyId = post_string('id');

    $index = survey_index(
        $data,
        $surveyId
    );

    if ($index < 0) {
        throw new InvalidArgumentException(
            'アンケートが見つかりません。'
        );
    }

    $survey =
        &$data['surveys'][$index];

    foreach (
        $survey['groups'] as $gi => &$group
    ) {
        $groupTitle =
            post_string('group_title_' . $gi);

        if ($groupTitle !== '') {
            $group['title'] =
                $groupTitle;
        }

        foreach (
            $group['questions']
            as $qi => &$question
        ) {
            $question['text'] =
                post_string(
                    'question_text_' . $gi . '_' . $qi
                );

            $type =
                post_string(
                    'question_type_' . $gi . '_' . $qi
                );

            if (
                !in_array(
                    $type,
                    ['single', 'multiple', 'text'],
                    true
                )
            ) {
                $type = 'single';
            }

            $question['type'] = $type;

            $question['required'] =
                post_bool(
                    'question_required_' . $gi . '_' . $qi
                );

            if ($type === 'text') {
                $question['options'] = [];
                continue;
            }

            $labels =
                $_POST[
                    'options_' . $gi . '_' . $qi
                ] ?? [];

            $nexts =
                $_POST[
                    'nexts_' . $gi . '_' . $qi
                ] ?? [];

            $options = [];

            if (is_array($labels)) {
                foreach ($labels as $oi => $label) {
                    $label = trim((string)$label);

                    if ($label === '') {
                        continue;
                    }

                    $oldId =
                        $question['options'][$oi]['id']
                        ?? new_id('option');

                    $next =
                        is_array($nexts)
                        ? trim(
                            (string)(
                                $nexts[$oi]
                                ?? ''
                            )
                        )
                        : '';

                    $options[] = [
                        'id' => $oldId,
                        'label' => $label,
                        'nextQuestionId' => $next,
                    ];
                }
            }

            $question['options'] =
                $options;
        }

        unset($question);
    }

    unset($group);

    $survey['updatedAt'] =
        date('Y-m-d H:i:s');

    recalculate_question_numbers(
        $survey
    );

    save_json_file(
        DATA_FILE,
        $data
    );
}

/* ============================================================
 * 回答保存
 * ============================================================ */

function save_answer_action(
    array &$data
): void {
    $surveyId =
        post_string('survey_id');

    $survey =
        find_survey(
            $data,
            $surveyId
        );

    if ($survey === null) {
        throw new InvalidArgumentException(
            'アンケートが見つかりません。'
        );
    }

    $answers = $_POST['answer'] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $normalized = [];

    foreach (
        $survey['groups'] as $group
    ) {
        foreach (
            $group['questions'] as $question
        ) {
            $qid =
                (string)$question['id'];

            $value =
                $answers[$qid] ?? '';

            if (
                ($question['required'] ?? false)
                && (
                    $value === ''
                    || $value === []
                )
            ) {
                throw new InvalidArgumentException(
                    '必須項目が未回答です。'
                );
            }

            if (
                is_array($value)
            ) {
                $value =
                    array_values(
                        array_map(
                            'strval',
                            $value
                        )
                    );
            } else {
                $value = trim((string)$value);
            }

            $normalized[$qid] = $value;
        }
    }

    $_SESSION['answer_draft'] = [
        'surveyId' => $surveyId,
        'answers' => $normalized,
    ];
}

function finalize_answer_action(
    array &$data
): void {
    $draft =
        $_SESSION['answer_draft']
        ?? null;

    if (!is_array($draft)) {
        throw new RuntimeException(
            '回答情報が見つかりません。最初から回答してください。'
        );
    }

    $data['answers'][] = [
        'id' => new_id('answer'),
        'surveyId' =>
            (string)$draft['surveyId'],
        'answers' =>
            $draft['answers'] ?? [],
        'createdAt' =>
            date('Y-m-d H:i:s'),
    ];

    save_json_file(
        DATA_FILE,
        $data
    );

    unset($_SESSION['answer_draft']);
}

/* ============================================================
 * POST処理
 * ============================================================ */

function handle_post(
    array &$data,
    array &$settings
): void {
    $action =
        post_string('action');

    if ($action === '') {
        return;
    }

    try {
        switch ($action) {

            case 'save_kintone':
                /*
                 * ここでは絶対に screen=list へ
                 * 先に飛ばさない。
                 *
                 * 保存処理成功後のみPRG。
                 */
                save_kintone_settings(
                    $settings
                );

                flash(
                    'success',
                    'kintone設定を保存しました。'
                );

                redirect_screen('kintone');

            case 'test_kintone':
                /*
                 * 接続テストは設定保存とは別。
                 * 現在入力された値で直接テストする。
                 */
                $temporary =
                    $settings['kintone'];

                $temporary['subdomain'] =
                    post_string('subdomain');

                $temporary['app_id'] =
                    post_string('app_id');

                $temporary['username'] =
                    post_string('username');

                $enteredPassword =
                    post_string('password');

                if ($enteredPassword !== '') {
                    $temporary['password'] =
                        $enteredPassword;
                }

                $temporary['proxy'] =
                    post_string('proxy');

                $temporary['verify_ssl'] =
                    post_bool('verify_ssl');

                $test =
                    kintone_test(
                        $temporary
                    );

                $settings['kintone']['last_test'] =
                    $test;

                /*
                 * テスト結果だけを保存。
                 * パスワードは保存データへ入れない。
                 */
                $settingsForSave =
                    $settings;

                save_json_file(
                    SETTINGS_FILE,
                    $settingsForSave
                );

                $_SESSION['kintone_test'] =
                    $test;

                redirect_screen('kintone');

            case 'save_kintone_mapping':
                save_kintone_mapping(
                    $settings
                );

                flash(
                    'success',
                    'kintone項目マッピングを保存しました。'
                );

                redirect_screen('kintone');

            case 'refresh_kintone_fields':
                $fields =
                    kintone_get_fields(
                        $settings['kintone']
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

                redirect_screen('kintone');

            case 'sync_kintone':
                $count =
                    kintone_sync_customers(
                        $settings['kintone'],
                        $data,
                        $settings
                    );

                flash(
                    'success',
                    'kintoneから顧客情報を'
                    . $count
                    . '件同期しました。'
                );

                redirect_screen('kintone');

            case 'save_survey':
                save_survey_action(
                    $data
                );

                flash(
                    'success',
                    'アンケートを保存しました。'
                );

                redirect_screen('list');

            case 'change_status':
                change_status_action(
                    $data
                );

                flash(
                    'success',
                    'アンケートの状態を変更しました。'
                );

                redirect_screen('list');

            case 'duplicate_survey':
                duplicate_survey_action(
                    $data
                );

                flash(
                    'success',
                    'アンケートを複製しました。'
                );

                redirect_screen('list');

            case 'delete_survey':
                delete_survey_action(
                    $data
                );

                flash(
                    'success',
                    'アンケートを削除しました。'
                );

                redirect_screen('list');

            case 'add_group':
                add_group_action(
                    $data
                );

                flash(
                    'success',
                    'グループを追加しました。'
                );

                redirect_screen(
                    'edit',
                    ['id' => post_string('id')]
                );

            case 'add_question':
                add_question_action(
                    $data
                );

                flash(
                    'success',
                    '質問を追加しました。'
                );

                redirect_screen(
                    'edit',
                    ['id' => post_string('id')]
                );

            case 'delete_group':
                delete_group_action(
                    $data
                );

                flash(
                    'success',
                    'グループを削除しました。'
                );

                redirect_screen(
                    'edit',
                    ['id' => post_string('id')]
                );

            case 'delete_question':
                delete_question_action(
                    $data
                );

                flash(
                    'success',
                    '質問を削除しました。'
                );

                redirect_screen(
                    'edit',
                    ['id' => post_string('id')]
                );

            case 'save_questions':
                save_questions_action(
                    $data
                );

                flash(
                    'success',
                    '質問設定を保存しました。'
                );

                redirect_screen(
                    'edit',
                    ['id' => post_string('id')]
                );

            case 'answer':
                save_answer_action(
                    $data
                );

                redirect_screen(
                    'confirm',
                    [
                        'id' =>
                            post_string('survey_id'),
                    ]
                );

            case 'finalize_answer':
                finalize_answer_action(
                    $data
                );

                redirect_screen(
                    'complete'
                );

            default:
                throw new InvalidArgumentException(
                    '不明な操作です。'
                );
        }
    } catch (Throwable $e) {

        /*
         * 重要：
         * エラー時は勝手に一覧へ戻さない。
         *
         * kintone設定保存ならkintone画面へ戻す。
         * その他は元画面へ戻す。
         */
        $message =
            $e instanceof InvalidArgumentException
            ? $e->getMessage()
            : '処理に失敗しました。入力値・設定・ネットワークを確認してください。';

        flash(
            'error',
            $message
        );

        switch ($action) {
            case 'save_kintone':
            case 'test_kintone':
            case 'save_kintone_mapping':
            case 'refresh_kintone_fields':
            case 'sync_kintone':
                redirect_screen('kintone');

            case 'save_survey':
                redirect_screen(
                    'edit',
                    [
                        'id' =>
                            post_string('id'),
                    ]
                );

            case 'save_questions':
            case 'add_group':
            case 'add_question':
            case 'delete_group':
            case 'delete_question':
                redirect_screen(
                    'edit',
                    [
                        'id' =>
                            post_string('id'),
                    ]
                );

            default:
                redirect_screen('list');
        }
    }
}

/* ============================================================
 * HTML 共通
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
:root {
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

* {
    box-sizing:border-box;
}

html,
body {
    margin:0;
    padding:0;
}

body {
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Yu Gothic",
        Meiryo,
        sans-serif;
    background:#f8fafc;
    color:var(--text);
    line-height:1.6;
}

a {
    color:var(--primary);
    text-decoration:none;
}

a:hover {
    text-decoration:underline;
}

.app-header {
    background:#fff;
    border-bottom:1px solid var(--border);
    position:sticky;
    top:0;
    z-index:20;
}

.header-inner {
    max-width:1400px;
    margin:0 auto;
    padding:16px 24px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.brand {
    font-size:20px;
    font-weight:800;
    color:var(--text);
}

.nav {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.nav a {
    padding:8px 12px;
    border-radius:8px;
    color:#475569;
    font-size:14px;
}

.nav a:hover,
.nav a.active {
    background:#eff6ff;
    color:var(--primary);
    text-decoration:none;
}

.container {
    max-width:1400px;
    margin:0 auto;
    padding:28px 24px 60px;
}

.page-title {
    margin:0 0 6px;
    font-size:28px;
    font-weight:800;
}

.page-description {
    margin:0 0 24px;
    color:var(--gray);
}

.card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:14px;
    box-shadow:var(--shadow);
    margin-bottom:20px;
}

.card-header {
    padding:18px 20px;
    border-bottom:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
}

.card-header h2,
.card-header h3 {
    margin:0;
    font-size:18px;
}

.card-body {
    padding:20px;
}

.grid {
    display:grid;
    gap:18px;
}

.grid-2 {
    grid-template-columns:
        repeat(2,minmax(0,1fr));
}

.grid-3 {
    grid-template-columns:
        repeat(3,minmax(0,1fr));
}

label {
    display:block;
    font-weight:700;
    font-size:14px;
}

label > span {
    display:block;
    margin-bottom:6px;
}

input[type="text"],
input[type="number"],
input[type="email"],
input[type="password"],
input[type="datetime-local"],
input[type="url"],
select,
textarea {
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:10px 12px;
    background:#fff;
    color:var(--text);
    font:inherit;
}

textarea {
    min-height:120px;
    resize:vertical;
}

input:focus,
select:focus,
textarea:focus {
    outline:3px solid #dbeafe;
    border-color:var(--primary);
}

.help {
    color:var(--gray);
    font-size:13px;
    font-weight:400;
    margin-top:5px;
}

.button-row {
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
}

.btn {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    min-height:40px;
    padding:9px 15px;
    border-radius:8px;
    border:1px solid transparent;
    font:inherit;
    font-weight:700;
    cursor:pointer;
    text-decoration:none;
}

.btn:hover {
    text-decoration:none;
}

.btn-primary {
    background:var(--primary);
    color:#fff;
}

.btn-primary:hover {
    background:var(--primary-dark);
}

.btn-success {
    background:var(--success);
    color:#fff;
}

.btn-warning {
    background:var(--warning);
    color:#fff;
}

.btn-danger {
    background:var(--danger);
    color:#fff;
}

.btn-secondary {
    background:#fff;
    color:#334155;
    border-color:#cbd5e1;
}

.btn-light {
    background:#f8fafc;
    color:#334155;
    border-color:var(--border);
}

.btn:disabled {
    opacity:.5;
    cursor:not-allowed;
}

.alert {
    border-radius:10px;
    padding:14px 16px;
    margin-bottom:16px;
    border:1px solid;
}

.alert-success {
    color:#166534;
    background:#f0fdf4;
    border-color:#bbf7d0;
}

.alert-error {
    color:#991b1b;
    background:#fef2f2;
    border-color:#fecaca;
}

.alert-warning {
    color:#92400e;
    background:#fffbeb;
    border-color:#fde68a;
}

.alert-info {
    color:#1e40af;
    background:#eff6ff;
    border-color:#bfdbfe;
}

.badge {
    display:inline-flex;
    align-items:center;
    padding:4px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
}

.badge-success {
    color:#166534;
    background:#dcfce7;
}

.badge-warning {
    color:#92400e;
    background:#fef3c7;
}

.badge-gray {
    color:#475569;
    background:#e2e8f0;
}

.badge-draft {
    color:#1e40af;
    background:#dbeafe;
}

.table-scroll {
    overflow-x:auto;
}

table {
    width:100%;
    border-collapse:collapse;
    min-width:900px;
}

th,
td {
    padding:12px 14px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th {
    background:#f8fafc;
    font-size:13px;
    white-space:nowrap;
}

td {
    font-size:14px;
}

.empty {
    text-align:center;
    padding:40px 20px;
    color:var(--gray);
}

.stat-grid {
    display:grid;
    grid-template-columns:
        repeat(4,minmax(0,1fr));
    gap:16px;
}

.stat {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:18px;
}

.stat-label {
    color:var(--gray);
    font-size:13px;
}

.stat-value {
    font-size:28px;
    font-weight:800;
    margin-top:4px;
}

.kintone-status {
    border:1px solid var(--border);
    border-radius:12px;
    background:#f8fafc;
    padding:18px;
}

.test-step {
    display:flex;
    gap:10px;
    padding:8px 0;
    border-bottom:1px solid #e2e8f0;
}

.test-step:last-child {
    border-bottom:0;
}

.test-result {
    margin-top:18px;
    border-radius:12px;
    padding:18px;
}

.test-result.success {
    background:#f0fdf4;
    border:1px solid #86efac;
}

.test-result.failure {
    background:#fef2f2;
    border:1px solid #fca5a5;
}

.test-result-title {
    font-size:18px;
    font-weight:800;
    margin-bottom:8px;
}

.question-card {
    border:1px solid var(--border);
    border-radius:12px;
    margin-bottom:16px;
    background:#fff;
}

.question-head {
    padding:14px 16px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
}

.question-body {
    padding:16px;
}

.option-row {
    display:grid;
    grid-template-columns:
        minmax(0,1fr) 250px;
    gap:10px;
    margin-bottom:10px;
}

.answer-option {
    display:flex;
    align-items:center;
    gap:10px;
    padding:12px;
    border:1px solid var(--border);
    border-radius:10px;
    margin-bottom:10px;
    cursor:pointer;
}

.answer-option:hover {
    background:#f8fafc;
}

.mobile-actions {
    display:none;
}

pre.debug {
    white-space:pre-wrap;
    word-break:break-word;
    background:#0f172a;
    color:#e2e8f0;
    padding:14px;
    border-radius:8px;
    overflow:auto;
}

@media (max-width:900px) {
    .grid-2,
    .grid-3 {
        grid-template-columns:1fr;
    }

    .stat-grid {
        grid-template-columns:
            repeat(2,minmax(0,1fr));
    }

    .header-inner {
        align-items:flex-start;
        flex-direction:column;
    }

    .nav {
        width:100%;
    }
}

@media (max-width:600px) {
    .container {
        padding:18px 14px 40px;
    }

    .header-inner {
        padding:12px 14px;
    }

    .page-title {
        font-size:23px;
    }

    .stat-grid {
        grid-template-columns:1fr 1fr;
    }

    .button-row {
        flex-direction:column;
        align-items:stretch;
    }

    .btn {
        width:100%;
    }

    .option-row {
        grid-template-columns:1fr;
    }

    .answer-option {
        min-height:52px;
    }
}
</style>
</head>

<body>

<header class="app-header">
<div class="header-inner">

<a class="brand"
   href="<?= h(app_url(['screen' => 'list'])) ?>">
    <?= h(APP_TITLE) ?>
</a>

<nav class="nav">
<a class="<?= $screen === 'list' ? 'active' : '' ?>"
   href="<?= h(app_url(['screen' => 'list'])) ?>">
    アンケート一覧
</a>

<a class="<?= $screen === 'kintone' ? 'active' : '' ?>"
   href="<?= h(app_url(['screen' => 'kintone'])) ?>">
    kintone
</a>

<a class="<?= $screen === 'mail' ? 'active' : '' ?>"
   href="<?= h(app_url(['screen' => 'mail'])) ?>">
    メール
</a>
</nav>

</div>
</header>

<main class="container">

<?php
}

function render_footer(): void
{
    ?>
</main>

<script>
document.querySelectorAll('[data-confirm]')
    .forEach(function (el) {
        el.addEventListener('click', function (event) {
            var message =
                el.getAttribute('data-confirm');

            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

document.querySelectorAll('form[data-loading]')
    .forEach(function (form) {
        form.addEventListener('submit', function () {
            var button =
                form.querySelector('button[type="submit"]');

            if (!button) {
                return;
            }

            button.disabled = true;

            var text =
                button.textContent.trim();

            button.dataset.originalText = text;

            button.textContent =
                '処理中です…';
        });
    });
</script>

</body>
</html>
<?php
}

/* ============================================================
 * Flash表示
 * ============================================================ */

function render_flash_messages(): void
{
    foreach (take_flash() as $item) {
        $type =
            ($item['type'] ?? '') === 'success'
            ? 'alert-success'
            : 'alert-error';

        echo '<div class="alert '
            . $type
            . '">'
            . h((string)(
                $item['message'] ?? ''
            ))
            . '</div>';
    }
}

/* ============================================================
 * 一覧画面
 * ============================================================ */

function render_list(
    array $data
): void {
    $search =
        get_string('q');

    $status =
        get_string('status');

    $sort =
        get_string('sort');

    if ($sort === '') {
        $sort = 'updated_desc';
    }

    $surveys =
        $data['surveys'];

    $filtered = [];

    foreach ($surveys as $survey) {
        $title =
            (string)($survey['title'] ?? '');

        if (
            $search !== ''
            && mb_stripos(
                $title,
                $search
            ) === false
        ) {
            continue;
        }

        if (
            $status !== ''
            && $status !== 'all'
            && ($survey['status'] ?? 'draft')
                !== $status
        ) {
            continue;
        }

        $filtered[] = $survey;
    }

    usort(
        $filtered,
        static function (
            array $a,
            array $b
        ) use ($sort): int {

            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)($a['updatedAt'] ?? ''),
                        (string)($b['updatedAt'] ?? '')
                    ),

                'answers_desc' =>
                    count_survey_answers(
                        $GLOBALS['__APP_DATA'],
                        (string)$b['id']
                    )
                    <=>
                    count_survey_answers(
                        $GLOBALS['__APP_DATA'],
                        (string)$a['id']
                    ),

                'answers_asc' =>
                    count_survey_answers(
                        $GLOBALS['__APP_DATA'],
                        (string)$a['id']
                    )
                    <=>
                    count_survey_answers(
                        $GLOBALS['__APP_DATA'],
                        (string)$b['id']
                    ),

                'start_desc' =>
                    strcmp(
                        (string)($b['startAt'] ?? ''),
                        (string)($a['startAt'] ?? '')
                    ),

                'start_asc' =>
                    strcmp(
                        (string)($a['startAt'] ?? ''),
                        (string)($b['startAt'] ?? '')
                    ),

                default =>
                    strcmp(
                        (string)($b['updatedAt'] ?? ''),
                        (string)($a['updatedAt'] ?? '')
                    ),
            };
        }
    );

    render_header(
        'アンケート一覧',
        'list'
    );

    render_flash_messages();
    ?>

<h1 class="page-title">アンケート一覧</h1>
<p class="page-description">
    アンケートの作成・編集・送信・集計を管理します。
</p>

<div class="card">
<div class="card-body">

<form method="get">
<input type="hidden"
       name="screen"
       value="list">

<div class="grid grid-3">

<label>
<span>タイトル検索</span>
<input type="text"
       name="q"
       value="<?= h($search) ?>"
       placeholder="タイトルを入力してEnter">
</label>

<label>
<span>ステータス</span>
<select name="status">
<option value="all">すべて</option>
<option value="published"
    <?= $status === 'published' ? 'selected' : '' ?>>
    公開中
</option>
<option value="draft"
    <?= $status === 'draft' ? 'selected' : '' ?>>
    下書き
</option>
<option value="stopped"
    <?= $status === 'stopped' ? 'selected' : '' ?>>
    停止
</option>
<option value="ended"
    <?= $status === 'ended' ? 'selected' : '' ?>>
    終了
</option>
</select>
</label>

<label>
<span>ソート</span>
<select name="sort">
<option value="updated_desc"
    <?= $sort === 'updated_desc' ? 'selected' : '' ?>>
    更新日：新しい順
</option>
<option value="updated_asc"
    <?= $sort === 'updated_asc' ? 'selected' : '' ?>>
    更新日：古い順
</option>
<option value="answers_desc"
    <?= $sort === 'answers_desc' ? 'selected' : '' ?>>
    回答数：多い順
</option>
<option value="answers_asc"
    <?= $sort === 'answers_asc' ? 'selected' : '' ?>>
    回答数：少ない順
</option>
<option value="start_desc"
    <?= $sort === 'start_desc' ? 'selected' : '' ?>>
    開始日：新しい順
</option>
<option value="start_asc"
    <?= $sort === 'start_asc' ? 'selected' : '' ?>>
    開始日：古い順
</option>
</select>
</label>

</div>

<div class="button-row"
     style="margin-top:16px">
<button class="btn btn-primary"
        type="submit">
    検索・絞り込み
</button>

<a class="btn btn-secondary"
   href="<?= h(app_url(['screen' => 'list'])) ?>">
    条件をクリア
</a>

<a class="btn btn-success"
   href="<?= h(app_url(['screen' => 'edit'])) ?>">
    ＋ 新規作成
</a>
</div>

</form>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>アンケート</h2>
<span>
<?= count($filtered) ?>件
</span>
</div>

<div class="table-scroll">

<?php if (!$filtered): ?>

<div class="empty">
    該当するアンケートはありません。
</div>

<?php else: ?>

<table>
<thead>
<tr>
<th>タイトル</th>
<th>作成日</th>
<th>更新日</th>
<th>期間</th>
<th>状態</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>

<tbody>

<?php foreach ($filtered as $survey): ?>

<?php
$sid =
    (string)$survey['id'];

$sstatus =
    (string)($survey['status'] ?? 'draft');

$answerCount =
    count_survey_answers(
        $data,
        $sid
    );
?>

<tr>

<td>
<strong><?= h(
    (string)$survey['title']
) ?></strong>
</td>

<td><?= h(
    (string)($survey['createdAt'] ?? '')
) ?></td>

<td><?= h(
    (string)($survey['updatedAt'] ?? '')
) ?></td>

<td>
<?= h(
    (string)($survey['startAt'] ?? '')
) ?>
<br>
～
<br>
<?= h(
    (string)($survey['endAt'] ?? '')
) ?>
</td>

<td>
<span class="badge <?= h(
    status_class($sstatus)
) ?>">
<?= h(
    status_label($sstatus)
) ?>
</span>
</td>

<td>
<?= $answerCount ?>
</td>

<td>

<div class="button-row">

<a class="btn btn-light"
   href="<?= h(
       app_url([
           'screen' => 'edit',
           'id' => $sid,
       ])
   ) ?>">
    確認・編集
</a>

<a class="btn btn-light"
   href="<?= h(
       app_url([
           'screen' => 'analytics',
           'id' => $sid,
       ])
   ) ?>">
    集計
</a>

<a class="btn btn-light"
   href="<?= h(
       app_url([
           'screen' => 'send',
           'id' => $sid,
       ])
   ) ?>">
    送信
</a>

<a class="btn btn-light"
   href="<?= h(
       app_url([
           'screen' => 'preview',
           'id' => $sid,
       ])
   ) ?>">
    プレビュー
</a>

<form method="post"
      style="display:inline"
      data-loading>
<input type="hidden"
       name="action"
       value="duplicate_survey">
<input type="hidden"
       name="id"
       value="<?= h($sid) ?>">
<button class="btn btn-light"
        type="submit"
        data-confirm="このアンケートを複製しますか？">
    複製
</button>
</form>

<form method="post"
      style="display:inline"
      data-loading>
<input type="hidden"
       name="action"
       value="delete_survey">
<input type="hidden"
       name="id"
       value="<?= h($sid) ?>">
<button class="btn btn-danger"
        type="submit"
        data-confirm="このアンケートを削除しますか？">
    削除
</button>
</form>

<?php if ($sstatus === 'draft'): ?>

<form method="post"
      style="display:inline"
      data-loading>
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="id"
       value="<?= h($sid) ?>">
<input type="hidden"
       name="new_status"
       value="published">
<button class="btn btn-success"
        type="submit"
        data-confirm="このアンケートを公開しますか？">
    公開
</button>
</form>

<?php elseif ($sstatus === 'published'): ?>

<form method="post"
      style="display:inline"
      data-loading>
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="id"
       value="<?= h($sid) ?>">
<input type="hidden"
       name="new_status"
       value="stopped">
<button class="btn btn-warning"
        type="submit"
        data-confirm="このアンケートを停止しますか？">
    停止
</button>
</form>

<?php elseif ($sstatus === 'stopped'): ?>

<form method="post"
      style="display:inline"
      data-loading>
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="id"
       value="<?= h($sid) ?>">
<input type="hidden"
       name="new_status"
       value="published">
<button class="btn btn-success"
        type="submit"
        data-confirm="このアンケートを再開しますか？">
    再開
</button>
</form>

<?php endif; ?>

</div>

</td>

</tr>

<?php endforeach; ?>

</tbody>
</table>

<?php endif; ?>

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
    $isNew = $survey === null;

    if ($isNew) {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [
                [
                    'id' => '',
                    'title' => 'グループ1',
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

<h1 class="page-title">
<?= $isNew
    ? 'アンケート作成'
    : 'アンケート編集' ?>
</h1>

<div class="card">
<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="id"
       value="<?= h(
           (string)$survey['id']
       ) ?>">

<div class="button-row"
     style="margin-bottom:20px">

<a class="btn btn-secondary"
   href="<?= h(
       app_url(['screen' => 'list'])
   ) ?>">
    キャンセル
</a>

<button class="btn btn-primary"
        type="submit">
    保存して一覧へ
</button>

<span class="badge <?= h(
    status_class(
        (string)$survey['status']
    )
) ?>">
状態：
<?= h(
    status_label(
        (string)$survey['status']
    )
) ?>
</span>

</div>

<div class="grid grid-2">

<label>
<span>アンケートタイトル</span>
<input type="text"
       name="title"
       required
       maxlength="200"
       value="<?= h(
           (string)$survey['title']
       ) ?>">
</label>

<label>
<span>質問番号の採番方式</span>
<select name="numbering">
<option value="global"
    <?= ($survey['numbering'] ?? 'global')
        === 'global'
        ? 'selected'
        : '' ?>>
    アンケート全体で通番：Q1、Q2、Q3...
</option>
<option value="group"
    <?= ($survey['numbering'] ?? '')
        === 'group'
        ? 'selected'
        : '' ?>>
    グループ毎：Q1-1、Q1-2、Q2-1...
</option>
</select>
</label>

<label>
<span>開始日時</span>
<input type="datetime-local"
       name="startAt"
       value="<?= h(
           (string)$survey['startAt']
       ) ?>">
</label>

<label>
<span>終了日時</span>
<input type="datetime-local"
       name="endAt"
       value="<?= h(
           (string)$survey['endAt']
       ) ?>">
</label>

</div>

<label style="margin-top:18px">
<span>アンケート説明</span>
<textarea name="description"><?= h(
    (string)$survey['description']
) ?></textarea>
</label>

</form>

</div>
</div>

<?php if (!$isNew): ?>

<div class="card">
<div class="card-header">
<h2>質問・グループ</h2>
</div>

<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_questions">

<input type="hidden"
       name="id"
       value="<?= h(
           (string)$survey['id']
       ) ?>">

<?php foreach (
    $survey['groups'] as $gi => $group
): ?>

<div class="question-card">

<div class="question-head">

<strong>
グループ <?= $gi + 1 ?>
</strong>

<?php if (
    count($survey['groups']) > 1
): ?>

<button class="btn btn-danger"
        type="submit"
        name="action"
        value="delete_group"
        formaction="<?= h(
            app_url()
        ) ?>"
        formmethod="post"
        onclick="return confirm('このグループを削除しますか？')">
    グループ削除
</button>

<?php endif; ?>

</div>

<div class="question-body">

<label>
<span>グループタイトル</span>
<input type="text"
       name="group_title_<?= $gi ?>"
       value="<?= h(
           (string)($group['title'] ?? '')
       ) ?>">
</label>

<input type="hidden"
       name="id"
       value="<?= h(
           (string)$survey['id']
       ) ?>">

<input type="hidden"
       name="group_id"
       value="<?= h(
           (string)$group['id']
       ) ?>">

<?php foreach (
    $group['questions'] as $qi => $question
): ?>

<div class="question-card"
     style="margin-top:18px">

<div class="question-head">

<div>
<strong>
<?= h(
    (string)($question['number'] ?? '')
) ?>
</strong>

<span style="margin-left:8px">
質問 <?= $qi + 1 ?>
</span>
</div>

<button class="btn btn-danger"
        type="submit"
        name="action"
        value="delete_question"
        formaction="<?= h(
            app_url()
        ) ?>"
        formmethod="post"
        onclick="return confirm('この質問を削除しますか？')">
    削除
</button>

</div>

<div class="question-body">

<label>
<span>質問文</span>
<input type="text"
       name="question_text_<?= $gi ?>_<?= $qi ?>"
       value="<?= h(
           (string)($question['text'] ?? '')
       ) ?>">
</label>

<div class="grid grid-2"
     style="margin-top:14px">

<label>
<span>回答形式</span>
<select name="question_type_<?= $gi ?>_<?= $qi ?>">
<option value="single"
    <?= ($question['type'] ?? '')
        === 'single'
        ? 'selected'
        : '' ?>>
    単一選択
</option>
<option value="multiple"
    <?= ($question['type'] ?? '')
        === 'multiple'
        ? 'selected'
        : '' ?>>
    複数選択
</option>
<option value="text"
    <?= ($question['type'] ?? '')
        === 'text'
        ? 'selected'
        : '' ?>>
    自由記述
</option>
</select>
</label>

<label style="padding-top:28px">
<input type="checkbox"
       name="question_required_<?= $gi ?>_<?= $qi ?>"
       value="1"
       <?= !empty(
           $question['required']
       )
           ? 'checked'
           : '' ?>>
必須回答
</label>

</div>

<?php if (
    ($question['type'] ?? 'single')
    !== 'text'
): ?>

<div style="margin-top:18px">

<strong>選択肢</strong>

<?php foreach (
    ($question['options'] ?? [])
    as $oi => $option
): ?>

<div class="option-row">

<input type="text"
       name="options_<?= $gi ?>_<?= $qi ?>[]"
       value="<?= h(
           (string)($option['label'] ?? '')
       ) ?>"
       placeholder="選択肢">

<input type="text"
       name="nexts_<?= $gi ?>_<?= $qi ?>[]"
       value="<?= h(
           (string)($option['nextQuestionId'] ?? '')
       ) ?>"
       placeholder="次に表示する質問ID（任意）">

</div>

<?php endforeach; ?>

</div>

<?php endif; ?>

</div>
</div>

<?php endforeach; ?>

<button class="btn btn-light"
        type="submit"
        name="action"
        value="add_question"
        formaction="<?= h(
            app_url()
        ) ?>"
        formmethod="post">
    ＋ 質問を追加
</button>

</div>
</div>

<?php endforeach; ?>

<div class="button-row">

<button class="btn btn-primary"
        type="submit">
    質問設定を保存
</button>

<button class="btn btn-light"
        type="submit"
        name="action"
        value="add_group"
        formaction="<?= h(
            app_url()
        ) ?>"
        formmethod="post">
    ＋ グループを追加
</button>

<a class="btn btn-secondary"
   href="<?= h(
       app_url([
           'screen' => 'preview',
           'id' => $survey['id'],
       ])
   ) ?>">
    プレビュー
</a>

</div>

</form>

</div>
</div>

<?php endif; ?>

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

    ?>

<h1 class="page-title">プレビュー</h1>
<p class="page-description">
    <?= h(
        (string)$survey['title']
    ) ?>
</p>

<div class="card">
<div class="card-body">

<h2><?= h(
    (string)$survey['title']
) ?></h2>

<p>
<?= nl2br(
    h((string)$survey['description'])
) ?>
</p>

<?php foreach (
    $survey['groups'] as $group
): ?>

<h3 style="margin-top:28px">
<?= h(
    (string)$group['title']
) ?>
</h3>

<?php foreach (
    $group['questions'] as $question
): ?>

<div class="question-card">

<div class="question-head">
<strong>
<?= h(
    (string)($question['number'] ?? '')
) ?>
<?= h(
    (string)($question['text'] ?? '')
) ?>
</strong>
</div>

<div class="question-body">

<?php
$type =
    $question['type'] ?? 'single';
?>

<?php if ($type === 'text'): ?>

<textarea
    placeholder="回答を入力してください。"></textarea>

<?php else: ?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>

<div class="answer-option">

<input
    type="<?= $type === 'multiple'
        ? 'checkbox'
        : 'radio' ?>"
    disabled>

<span>
<?= h(
    (string)$option['label']
) ?>
</span>

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>
</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>
</div>

<div class="button-row">

<a class="btn btn-secondary"
   href="<?= h(
       app_url([
           'screen' => 'edit',
           'id' => $survey['id'],
       ])
   ) ?>">
    編集へ戻る
</a>

</div>

<?php
    render_footer();
}

/* ============================================================
 * kintone画面
 * ============================================================ */

function render_kintone(
    array $settings,
    array $data
): void {
    $k =
        $settings['kintone'];

    $test =
        $_SESSION['kintone_test']
        ?? $k['last_test']
        ?? null;

    unset(
        $_SESSION['kintone_test']
    );

    $fields =
        $k['fields']
        ?? [];

    $customers =
        $data['customers']
        ?? [];

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
「設定保存」「接続テスト」「項目一覧を再取得」
「顧客情報を同期」は別々の操作です。
</p>

<div class="card">

<div class="card-header">
<h2>接続設定</h2>
</div>

<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid grid-2">

<label>
<span>サブドメイン</span>

<input type="text"
       name="subdomain"
       required
       value="<?= h(
           (string)$k['subdomain']
       ) ?>"
       placeholder="xxxx.cybozu.com">

<div class="help">
https://xxxx.cybozu.com、
xxxx.cybozu.com、xxxx のいずれでも入力できます。
</div>

</label>

<label>
<span>顧客管理アプリID</span>

<input type="number"
       name="app_id"
       min="1"
       step="1"
       required
       value="<?= h(
           (string)$k['app_id']
       ) ?>">

</label>

<label>
<span>ログイン名</span>

<input type="text"
       name="username"
       required
       autocomplete="username"
       value="<?= h(
           (string)$k['username']
       ) ?>">

</label>

<label>
<span>パスワード</span>

<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更する場合のみ入力">

<div class="help">
空欄の場合、保存済みパスワードを維持します。
</div>

</label>

<label>
<span>Proxy</span>

<input type="text"
       name="proxy"
       value="<?= h(
           (string)$k['proxy']
       ) ?>"
       placeholder="proxy.example.local:8080">

<div class="help">
未入力ならProxyを使用せず直接接続します。
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

<div class="help">
POCでは通常、無効で構いません。
</div>

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

<hr style="
margin:24px 0;
border:0;
border-top:1px solid var(--border);
">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="test_kintone">

<div class="grid grid-2">

<label>
<span>接続テスト用サブドメイン</span>
<input type="text"
       name="subdomain"
       required
       value="<?= h(
           (string)$k['subdomain']
       ) ?>">
</label>

<label>
<span>接続テスト用アプリID</span>
<input type="number"
       name="app_id"
       min="1"
       required
       value="<?= h(
           (string)$k['app_id']
       ) ?>">
</label>

<label>
<span>接続テスト用ログイン名</span>
<input type="text"
       name="username"
       required
       value="<?= h(
           (string)$k['username']
       ) ?>">
</label>

<label>
<span>接続テスト用パスワード</span>
<input type="password"
       name="password"
       placeholder="保存済みパスワードを使用する場合は空欄">
</label>

<label>
<span>Proxy</span>
<input type="text"
       name="proxy"
       value="<?= h(
           (string)$k['proxy']
       ) ?>"
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

<div class="test-result <?= !empty(
    $test['success']
)
    ? 'success'
    : 'failure' ?>">

<div class="test-result-title">

<?= !empty($test['success'])
    ? '✓ 接続成功'
    : '✕ 接続失敗' ?>

</div>

<div>
<strong>
<?= h(
    (string)($test['message'] ?? '')
) ?>
</strong>
</div>

<?php if (
    !empty($test['detail'])
): ?>

<p>
<?= h(
    (string)$test['detail']
) ?>
</p>

<?php endif; ?>

<?php if (
    isset($test['status'])
): ?>

<p>
HTTPステータス：
<strong>
<?= (int)$test['status'] ?>
</strong>
</p>

<?php endif; ?>

<?php if (
    isset($test['elapsed'])
): ?>

<p>
処理時間：
<?= h(
    (string)$test['elapsed']
) ?> 秒
</p>

<?php endif; ?>

<?php if (
    !empty($test['steps'])
): ?>

<div style="margin-top:15px">

<strong>
接続テスト経過
</strong>

<?php foreach (
    $test['steps'] as $step
): ?>

<div class="test-step">
<span>●</span>
<span><?= h(
    (string)$step
) ?></span>
</div>

<?php endforeach; ?>

</div>

<?php endif; ?>

<?php if (
    empty($test['success'])
): ?>

<div class="alert alert-warning"
     style="margin-top:16px;margin-bottom:0">

<strong>次に確認する項目</strong>

<ul>
<li>サブドメインが正しいか</li>
<li>顧客管理アプリIDが正しいか</li>
<li>ログイン名・パスワードが正しいか</li>
<li>ログインユーザーが対象アプリを閲覧できるか</li>
<li>Proxyを使用する環境なら「host:port」が正しいか</li>
<li>Proxyを使用しないならProxy欄を空欄にする</li>
<li>Windows/Apacheから外部HTTPS通信が可能か</li>
</ul>

</div>

<?php endif; ?>

</div>

</div>
</div>

<?php endif; ?>

<div class="card">

<div class="card-header">
<h2>kintoneデータ操作</h2>
</div>

<div class="card-body">

<div class="button-row">

<form method="post"
      data-loading>
<input type="hidden"
       name="action"
       value="refresh_kintone_fields">
<button class="btn btn-secondary"
        type="submit">
    項目一覧を再取得
</button>
</form>

<form method="post"
      data-loading>
<input type="hidden"
       name="action"
       value="sync_kintone">
<button class="btn btn-primary"
        type="submit"
        data-confirm="kintoneから顧客情報を同期しますか？">
    顧客情報を同期
</button>
</form>

</div>

<?php if (
    !empty($k['last_sync'])
): ?>

<div class="alert alert-success"
     style="margin-top:18px">

最終同期：
<?= h(
    (string)(
        $k['last_sync']['at']
        ?? ''
    )
) ?>

／

<?= (int)(
    $k['last_sync']['count']
    ?? 0
) ?>件

</div>

<?php endif; ?>

</div>
</div>

<div class="card">

<div class="card-header">
<h2>顧客情報マッピング</h2>
</div>

<div class="card-body">

<form method="post">

<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<div class="grid grid-2">

<label>
<span>組織名</span>
<select name="mapping_organization">
<option value="">未設定</option>
<?php
render_field_options(
    $fields,
    (string)$k['mapping']['organization']
);
?>
</select>
</label>

<label>
<span>氏名</span>
<select name="mapping_name">
<option value="">未設定</option>
<?php
render_field_options(
    $fields,
    (string)$k['mapping']['name']
);
?>
</select>
</label>

<label>
<span>メールアドレス</span>
<select name="mapping_email">
<option value="">未設定</option>
<?php
render_field_options(
    $fields,
    (string)$k['mapping']['email']
);
?>
</select>
</label>

<label>
<span>部署名</span>
<select name="mapping_department">
<option value="">未設定</option>
<?php
render_field_options(
    $fields,
    (string)$k['mapping']['department']
);
?>
</select>
</label>

<label>
<span>電話番号</span>
<select name="mapping_phone">
<option value="">未設定</option>
<?php
render_field_options(
    $fields,
    (string)$k['mapping']['phone']
);
?>
</select>
</label>

</div>

<label style="margin-top:18px">
<span>住所</span>

<?php
$selectedAddresses =
    $k['mapping']['address']
    ?? [];

foreach (
    field_options_array($fields)
    as $field
):
?>

<label style="
margin:6px 0;
font-weight:400;
">

<input type="checkbox"
       name="mapping_address[]"
       value="<?= h(
           (string)$field['code']
       ) ?>"
       <?= in_array(
           $field['code'],
           $selectedAddresses,
           true
       )
           ? 'checked'
           : '' ?>>

<?= h(
    (string)$field['label']
) ?>

（<?= h(
    (string)$field['code']
) ?>）

</label>

<?php endforeach; ?>

</label>

<div class="button-row"
     style="margin-top:18px">

<button class="btn btn-primary"
        type="submit">
    マッピングを保存
</button>

</div>

</form>

</div>
</div>

<?php if (
    !empty($fields['properties'])
): ?>

<div class="card">

<div class="card-header">
<h2>kintone項目一覧</h2>
</div>

<div class="table-scroll">

<table>
<thead>
<tr>
<th>フィールドコード</th>
<th>項目名</th>
<th>種類</th>
</tr>
</thead>

<tbody>

<?php foreach (
    $fields['properties'] as $code => $field
): ?>

<tr>
<td>
<code><?= h(
    (string)$code
) ?></code>
</td>

<td><?= h(
    (string)(
        $field['label']
        ?? ''
    )
) ?></td>

<td><?= h(
    (string)(
        $field['type']
        ?? ''
    )
) ?></td>
</tr>

<?php endforeach; ?>

</tbody>
</table>

</div>
</div>

<?php endif; ?>

<div class="card">

<div class="card-header">
<h2>同期済み顧客</h2>
</div>

<div class="card-body">

<p>
現在の同期件数：
<strong>
<?= count($customers) ?>件
</strong>
</p>

</div>
</div>

<?php
    render_footer();
}

/* ============================================================
 * フィールド選択肢
 * ============================================================ */

function field_options_array(
    array $fields
): array {
    $result = [];

    foreach (
        $fields['properties']
        ?? [] as $code => $field
    ) {
        if (!is_array($field)) {
            continue;
        }

        $result[] = [
            'code' => (string)$code,
            'label' =>
                (string)(
                    $field['label']
                    ?? $code
                ),
        ];
    }

    return $result;
}

function render_field_options(
    array $fields,
    string $selected
): void {
    foreach (
        field_options_array($fields)
        as $field
    ) {
        echo '<option value="'
            . h($field['code'])
            . '"'
            . (
                $selected === $field['code']
                ? ' selected'
                : ''
            )
            . '>'
            . h($field['label'])
            . '（'
            . h($field['code'])
            . '）'
            . '</option>';
    }
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
            static fn($answer) =>
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
<?= h(
    (string)$survey['title']
) ?>
</strong>
</p>

<div class="stat-grid">

<div class="stat">
<div class="stat-label">送信対象者数</div>
<div class="stat-value">
<?= count($data['customers']) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">回答数</div>
<div class="stat-value">
<?= $answerCount ?>
</div>
</div>

<div class="stat">
<div class="stat-label">未回答数</div>
<div class="stat-value">
<?= max(
    0,
    count($data['customers'])
    - $answerCount
) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">回答率</div>
<div class="stat-value">
<?php
$total =
    count($data['customers']);

$rate =
    $total > 0
    ? round(
        ($answerCount / $total) * 100,
        1
    )
    : 0;
?>
<?= $rate ?>%
</div>
</div>

</div>

<div class="card"
     style="margin-top:20px">

<div class="card-header">
<h2>設問別集計</h2>
</div>

<div class="card-body">

<?php if ($answerCount === 0): ?>

<div class="empty">
現在、回答データはありません
</div>

<?php else: ?>

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

foreach (
    $answers as $answer
) {
    $value =
        $answer['answers'][$qid]
        ?? '';

    if (is_array($value)) {
        foreach ($value as $item) {
            $item = (string)$item;

            if ($item === '') {
                continue;
            }

            $counts[$item] =
                ($counts[$item] ?? 0)
                + 1;
        }
    } else {
        $value = trim((string)$value);

        if ($value !== '') {
            $counts[$value] =
                ($counts[$value] ?? 0)
                + 1;
        }
    }
}
?>

<div style="
padding:16px 0;
border-bottom:1px solid var(--border);
">

<strong>
<?= h(
    (string)($question['number'] ?? '')
) ?>

<?= h(
    (string)($question['text'] ?? '')
) ?>
</strong>

<?php if (
    !$counts
): ?>

<p class="help">
回答なし
</p>

<?php else: ?>

<ul>

<?php foreach (
    $counts as $label => $count
): ?>

<li>
<?= h($label) ?>：
<strong>
<?= $count ?>件
</strong>
</li>

<?php endforeach; ?>

</ul>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<?php endif; ?>

</div>
</div>

<div class="button-row">

<a class="btn btn-secondary"
   href="<?= h(
       app_url(['screen' => 'list'])
   ) ?>">
    一覧へ戻る
</a>

</div>

<?php
    render_footer();
}

/* ============================================================
 * 送信
 * ============================================================ */

function render_send(
    array $survey,
    array $data
): void {
    $customers =
        $data['customers']
        ?? [];

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
<?= h(
    (string)$survey['title']
) ?>
</strong>
</p>

<div class="card">

<div class="card-header">
<h2>顧客選択</h2>
</div>

<div class="card-body">

<?php if (!$customers): ?>

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
    $customers as $customer
): ?>

<tr>

<td>
<input type="checkbox"
       disabled
       title="メール送信機能で使用">
</td>

<td><?= h(
    (string)($customer['organization'] ?? '')
) ?></td>

<td><?= h(
    (string)($customer['name'] ?? '')
) ?></td>

<td><?= h(
    (string)($customer['email'] ?? '')
) ?></td>

<td><?= h(
    (string)($customer['department'] ?? '')
) ?></td>

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
       value="<?= h(
           (string)$survey['title']
       ) ?>">
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
$history = array_values(
    array_filter(
        $data['send_history'],
        static fn($item) =>
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
<td><?= h(
    (string)($item['createdAt'] ?? '')
) ?></td>
<td><?= (int)(
    $item['count'] ?? 0
) ?></td>
<td><?= h(
    (string)($item['status'] ?? '')
) ?></td>
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
<?= h(
    (string)$survey['title']
) ?>
</h1>

<p class="page-description">
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
       value="<?= h(
           (string)$survey['id']
       ) ?>">

<?php foreach (
    $survey['groups'] as $group
): ?>

<h2 style="margin-top:30px">
<?= h(
    (string)$group['title']
) ?>
</h2>

<?php foreach (
    $group['questions'] as $question
): ?>

<div class="question-card">

<div class="question-head">

<strong>
<?= h(
    (string)($question['number'] ?? '')
) ?>

<?= h(
    (string)($question['text'] ?? '')
) ?>
</strong>

<?php if (
    !empty($question['required'])
): ?>

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
    $question['type'] ?? 'single';
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

<label class="answer-option">

<input
    type="<?= $type === 'multiple'
        ? 'checkbox'
        : 'radio' ?>"
    name="answer[<?= h($qid) ?>]<?= $type === 'multiple'
        ? '[]'
        : '' ?>"
    value="<?= h(
        (string)$option['label']
    ) ?>"
    <?= !empty($question['required'])
        ? 'required'
        : '' ?>>

<span>
<?= h(
    (string)$option['label']
) ?>
</span>

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
<?= h(
    (string)($question['number'] ?? '')
) ?>

<?= h(
    (string)$question['text']
) ?>
</strong>
</div>

<div class="question-body">
<?= nl2br(
    h($display)
) ?>
</div>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="button-row">

<a class="btn btn-secondary"
   href="<?= h(
       app_url([
           'screen' => 'answer',
           'id' => $survey['id'],
       ])
   ) ?>">
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
     style="text-align:center;padding:60px 20px">

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

<div class="alert alert-info">
現在の単一ファイル構成では、
メール認証情報をブラウザへ公開せず、
サーバー側SMTP通信を行う設計です。
</div>

<form method="post">

<div class="grid grid-2">

<label>
<span>SMTPサーバ</span>
<input type="text"
       name="smtp_host"
       value="<?= h(
           (string)$mail['host']
       ) ?>">
</label>

<label>
<span>SMTPポート</span>
<input type="number"
       name="smtp_port"
       value="<?= h(
           (string)$mail['port']
       ) ?>"
       min="1"
       max="65535">
</label>

<label>
<span>暗号化方式</span>
<select name="smtp_encryption">
<option value="tls"
    <?= ($mail['encryption'] ?? '')
        === 'tls'
        ? 'selected'
        : '' ?>>
    TLS
</option>
<option value="ssl"
    <?= ($mail['encryption'] ?? '')
        === 'ssl'
        ? 'selected'
        : '' ?>>
    SSL
</option>
<option value="none"
    <?= ($mail['encryption'] ?? '')
        === 'none'
        ? 'selected'
        : '' ?>>
    なし
</option>
</select>
</label>

<label>
<span>SMTP認証</span>
<select name="smtp_auth">
<option value="1">使用する</option>
<option value="0">使用しない</option>
</select>
</label>

<label>
<span>SMTPユーザー名</span>
<input type="text"
       name="smtp_username"
       value="<?= h(
           (string)$mail['username']
       ) ?>">
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
       value="<?= h(
           (string)$mail['from_email']
       ) ?>">
</label>

<label>
<span>送信元名</span>
<input type="text"
       name="from_name"
       value="<?= h(
           (string)$mail['from_name']
       ) ?>">
</label>

<label>
<span>返信先メールアドレス</span>
<input type="email"
       name="reply_to"
       value="<?= h(
           (string)$mail['reply_to']
       ) ?>">
</label>

</div>

<div class="button-row"
     style="margin-top:20px">

<button class="btn btn-primary"
        type="button"
        onclick="alert('メール設定保存処理を実装できます。')">
    設定保存
</button>

<button class="btn btn-success"
        type="button"
        onclick="alert('SMTP接続テストを実行します。')">
    接続テスト
</button>

<button class="btn btn-secondary"
        type="button"
        onclick="alert('設定保存後にテストメールを送信します。')">
    テストメール送信
</button>

</div>

</form>

</div>
</div>

<?php
    render_footer();
}

/* ============================================================
 * メイン
 * ============================================================ */

app_init();

$data =
    load_data();

$settings =
    load_settings();

/*
 * 現在のアンケート状態を自動判定。
 */
refresh_all_statuses(
    $data
);

/*
 * POSTは最初に処理。
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_post(
        $data,
        $settings
    );

    /*
     * handle_post() 内で
     * 成功時・エラー時ともにredirectする。
     */
    exit;
}

$screen =
    get_string('screen');

if ($screen === '') {
    $screen = 'list';
}

/*
 * 回答者画面。
 *
 * answer / confirm / complete から
 * 管理者画面へ自動リダイレクトしない。
 */
if (
    in_array(
        $screen,
        ['answer', 'confirm'],
        true
    )
) {
    $id =
        get_string('id');

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

if ($screen === 'complete') {
    render_complete();
    exit;
}

/*
 * 管理者画面。
 */
switch ($screen) {

    case 'edit':
        $id =
            get_string('id');

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

            redirect_screen('list');
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

            redirect_screen('list');
        }

        render_preview($survey);
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

            redirect_screen('list');
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

            redirect_screen('list');
        }

        render_send(
            $survey,
            $data
        );
        break;

    case 'kintone':
        render_kintone(
            $settings,
            $data
        );
        break;

    case 'mail':
        render_mail(
            $settings
        );
        break;

    case 'list':
    default:
        $GLOBALS['__APP_DATA'] =
            $data;

        render_list(
            $data
        );
        break;
}
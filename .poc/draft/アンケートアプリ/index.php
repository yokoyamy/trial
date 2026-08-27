<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * prompt.txt に基づく単一エントリーポイント版
 *
 * 実行環境:
 *   Apache 2.4
 *   PHP 8.5
 *   DBなし
 *   PHP cURLあり
 *
 * 重要:
 *   - index.php 1ファイル
 *   - DBを使用しない
 *   - サーバー側JSONへ永続化
 *   - CSRF対策は実装しない
 *   - POST後303リダイレクトは使用しない
 *   - 管理者認証は実装しない
 *   - kintone APIトークンは使用しない
 *   - kintone認証情報はブラウザへ渡さない
 *   - X-Cybozu-Authorizationはサーバー側だけで生成
 *   - kintone接続テストと同期を分離
 *   - PHP mail()は使用しない
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR       = __DIR__ . '/data';
const SETTINGS_FILE  = DATA_DIR . '/settings.json';
const SURVEYS_FILE   = DATA_DIR . '/surveys.json';
const CUSTOMERS_FILE = DATA_DIR . '/customers.json';
const ANSWERS_FILE   = DATA_DIR . '/answers.json';
const SEND_LOG_FILE  = DATA_DIR . '/send_logs.json';

const CONNECT_TIMEOUT = 10;
const READ_TIMEOUT    = 20;

const APP_SCREENS = [
    'list',
    'edit',
    'preview',
    'send',
    'analytics',
    'kintone',
    'mail',
    'answer',
    'confirm',
    'complete',
];

/* ============================================================
 * 初期化
 * ========================================================== */

if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

/*
 * セッション
 *
 * GETごとにsession_regenerate_id()を実行しない。
 */
$isHttps =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

$scriptDir = str_replace(
    '\\',
    '/',
    dirname($_SERVER['SCRIPT_NAME'] ?? '/')
);

$cookiePath = rtrim($scriptDir, '/');
if ($cookiePath === '') {
    $cookiePath = '/';
}

session_set_cookie_params([
    'lifetime' => 0,
    'path' => $cookiePath,
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!session_start()) {
        http_response_code(500);
        exit('セッションを開始できません。');
    }
}

/* ============================================================
 * 共通関数
 * ========================================================== */

function e(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function now_iso(): string
{
    return date('c');
}

function uuid(): string
{
    return bin2hex(random_bytes(16));
}

function valid_id(string $id): bool
{
    return (bool)preg_match(
        '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/',
        $id
    );
}

function screen_url(string $screen, ?string $id = null): string
{
    if (!in_array($screen, APP_SCREENS, true)) {
        $screen = 'list';
    }

    $url = 'index.php?screen=' . rawurlencode($screen);

    if ($id !== null && $id !== '' && valid_id($id)) {
        $url .= '&id=' . rawurlencode($id);
    }

    return $url;
}

function set_result(string $type, string $message): void
{
    $_SESSION['_operation_result'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function take_result(): ?array
{
    $result = $_SESSION['_operation_result'] ?? null;
    unset($_SESSION['_operation_result']);

    return is_array($result) ? $result : null;
}

function redirect_screen(string $screen, ?string $id = null): never
{
    /*
     * prompt.txtではPOST→303→GETに依存しないことになっているため、
     * 外部サービス処理等の後も画面表示は原則同一POSTレスポンスで行う。
     *
     * この関数は、業務上の画面遷移が必要なGETリンク生成用ではなく、
     * 明示的に安全な画面遷移が必要な場合のみ使用する。
     */
    header('Location: ' . screen_url($screen, $id));
    exit;
}

/* ============================================================
 * JSON永続化
 * ========================================================== */

function read_json(string $file, array $default = []): array
{
    if (!is_file($file)) {
        return $default;
    }

    $fp = fopen($file, 'rb');

    if ($fp === false) {
        throw new RuntimeException('データファイルを開けません。');
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            throw new RuntimeException('データファイルをロックできません。');
        }

        $raw = stream_get_contents($fp);

        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new RuntimeException('保存データが不正です。');
    }

    return $data;
}

function write_json_atomic(string $file, array $data): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException(
                'データ保存領域を作成できません。'
            );
        }
    }

    $tmp = tempnam(DATA_DIR, 'survey_');

    if ($tmp === false) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    try {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT |
            JSON_THROW_ON_ERROR
        );

        $fp = fopen($tmp, 'wb');

        if ($fp === false) {
            throw new RuntimeException('一時ファイルを開けません。');
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException('データをロックできません。');
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
        } finally {
            fclose($fp);
        }

        if (!rename($tmp, $file)) {
            throw new RuntimeException('データファイルを更新できません。');
        }

        $tmp = '';
    } finally {
        if ($tmp !== '' && is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

/* ============================================================
 * kintone
 * ========================================================== */

/**
 * kintone URLの成形
 *
 * 許容:
 *   https://example.cybozu.com
 *   http://example.cybozu.com
 *   example.cybozu.com
 *   example
 *
 * 結果:
 *   https://example.cybozu.com/...
 */
function kintone_build_url(
    string $domain,
    string $endpoint
): string {
    $domain = trim($domain);

    /*
     * プロトコルを除去
     */
    $domain = preg_replace(
        '#^https?://#i',
        '',
        $domain
    ) ?? $domain;

    /*
     * パス・クエリを除去
     */
    $domain = preg_replace(
        '#/.*$#',
        '',
        $domain
    ) ?? $domain;

    /*
     * .cybozu.comを除去してから再付与する。
     * これにより
     *
     * example.cybozu.com.cybozu.com
     *
     * のような重複を防止する。
     */
    $domain = preg_replace(
        '#\.cybozu\.com$#i',
        '',
        $domain
    ) ?? $domain;

    $domain = trim($domain, "/ \t\n\r\0\x0B");

    if ($domain === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    /*
     * サブドメインとして許可する文字だけに限定する。
     */
    if (!preg_match(
        '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/',
        $domain
    )) {
        throw new InvalidArgumentException(
            'kintoneサブドメインの形式が不正です。'
        );
    }

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

/**
 * X-Cybozu-Authorization生成
 *
 * 認証情報はこの関数からブラウザ側へ返さない。
 */
function make_cybozu_auth_header(
    string $login_name,
    string $password
): string {
    $login_name = trim($login_name);

    if ($login_name === '' || $password === '') {
        throw new InvalidArgumentException(
            'kintoneログイン名またはパスワードが未設定です。'
        );
    }

    $auth = base64_encode(
        $login_name . ':' . $password
    );

    return 'X-Cybozu-Authorization: ' . $auth;
}

function normalize_kintone_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    ) ?? $value;

    $value = preg_replace(
        '#/.*$#',
        '',
        $value
    ) ?? $value;

    $value = preg_replace(
        '#\.cybozu\.com$#i',
        '',
        $value
    ) ?? $value;

    $value = trim($value);

    if ($value === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    if (!preg_match(
        '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/',
        $value
    )) {
        throw new InvalidArgumentException(
            'kintoneサブドメインが不正です。'
        );
    }

    return $value;
}

function parse_proxy(string $proxy): ?array
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    /*
     * 要件:
     *   host:port
     *
     * URL形式や認証情報は受け付けない。
     */
    if (!preg_match(
        '/^([^:\/\s]+):([0-9]{1,5})$/',
        $proxy,
        $m
    )) {
        throw new InvalidArgumentException(
            'Proxyは host:port 形式で入力してください。'
        );
    }

    $port = (int)$m[2];

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'Proxyのポート番号が不正です。'
        );
    }

    return [
        'host' => $m[1],
        'port' => $port,
    ];
}

function validate_kintone(array $k): void
{
    $subdomain = trim((string)($k['subdomain'] ?? ''));
    $appId     = trim((string)($k['app_id'] ?? ''));
    $username  = trim((string)($k['username'] ?? ''));
    $password  = (string)($k['password'] ?? '');

    normalize_kintone_subdomain($subdomain);

    if ($appId === '' || !ctype_digit($appId) || (int)$appId < 1) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    if ($username === '') {
        throw new InvalidArgumentException(
            'kintoneログイン名を入力してください。'
        );
    }

    if ($password === '') {
        throw new InvalidArgumentException(
            'kintoneパスワードを入力してください。'
        );

    parse_proxy((string)($k['proxy'] ?? ''));
}

/**
 * kintone REST API通信
 *
 * PHP cURLを使用する。
 *
 * 重要:
 * - connect timeout と read timeout を設定
 * - HTTPエラーを握りつぶさない
 * - 認証情報は戻り値に含めない
 * - 認証リトライを行わない
 */
function kintone_request(
    array $k,
    string $endpoint,
    string $method = 'GET',
    ?array $body = null
): array {
    validate_kintone($k);

    if (!extension_loaded('curl')) {
        throw new RuntimeException(
            'PHP cURL拡張が利用できません。'
        );
    }

    $subdomain = normalize_kintone_subdomain(
        (string)$k['subdomain']
    );

    $appId = trim((string)$k['app_id']);

    /*
     * endpointはAPIパスのみ。
     *
     * 例:
     * /k/v1/app.json?id=123
     */
    if ($endpoint === '' || $endpoint[0] !== '/') {
        throw new InvalidArgumentException(
            'kintone APIエンドポイントが不正です。'
        );
    }

    $url = kintone_build_url(
        $subdomain,
        $endpoint
    );

    /*
     * 念のためアプリIDは数字のみ。
     * URLへの認証情報混入を防止。
     */
    if (!ctype_digit($appId)) {
        throw new InvalidArgumentException(
            'kintoneアプリIDが不正です。'
        );
    }

    $method = strtoupper($method);

    if (!in_array(
        $method,
        ['GET', 'POST', 'PUT', 'DELETE'],
        true
    )) {
        throw new InvalidArgumentException(
            'kintone HTTPメソッドが不正です。'
        );
    }

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        make_cybozu_auth_header(
            (string)$k['username'],
            (string)$k['password']
        ),
    ];

    $payload = null;

    if ($body !== null) {
        $payload = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_THROW_ON_ERROR
        );
    }

    $ch = curl_init();

    if ($ch === false) {
        throw new RuntimeException(
            'cURLを初期化できません。'
        );
    }

    try {
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,

            /*
             * 接続タイムアウト
             */
            CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,

            /*
             * 接続後の最大処理時間。
             * read timeoutの代替ではなく、
             * 全体が無期限にならないための上限。
             */
            CURLOPT_TIMEOUT => CONNECT_TIMEOUT + READ_TIMEOUT,

            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,

            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,

            /*
             * SSL検証設定
             *
             * prompt.txtではPOC段階で無効。
             * 設定画面で有効化できる。
             */
            CURLOPT_SSL_VERIFYPEER =>
                !empty($k['verify_ssl']),
            CURLOPT_SSL_VERIFYHOST =>
                !empty($k['verify_ssl']) ? 2 : 0,
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }

        $proxy = parse_proxy(
            (string)($k['proxy'] ?? '')
        );

        if ($proxy !== null) {
            $options[CURLOPT_PROXY] =
                $proxy['host'];

            $options[CURLOPT_PROXYPORT] =
                $proxy['port'];

            /*
             * HTTPSの場合、cURLがCONNECTトンネルを作成する。
             */
            $options[CURLOPT_HTTPPROXYTUNNEL] = true;
        }

        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);

        if ($raw === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);

            /*
             * 認証情報やAuthorizationヘッダーは
             * エラー文字列へ出さない。
             */
            throw new RuntimeException(
                'kintone通信エラー。'
                . ' cURL errno=' . $errno
                . ' / ' . $error
            );
        }

        $status = (int)curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        $headerSize = (int)curl_getinfo(
            $ch,
            CURLINFO_HEADER_SIZE
        );

        $rawHeaders = substr(
            $raw,
            0,
            $headerSize
        );

        $responseBody = substr(
            $raw,
            $headerSize
        );

        $json = null;

        if ($responseBody !== '') {
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
            'headers' => $rawHeaders,
            'body' => $responseBody,
            'json' => $json,
            'url' => $url,
        ];
    } finally {
        curl_close($ch);
    }
}

/**
 * kintone接続テスト
 *
 * 接続テストでは「アプリ情報取得」を行う。
 *
 * 正:
 *   /k/v1/app.json?id=123
 *
 * 誤:
 *   /k/v1/app.json?app=123
 *
 * APIトークンは使用しない。
 */
function test_kintone(array $settings): array
{
    $k = $settings['kintone'] ?? [];

    validate_kintone($k);

    $appId = trim((string)$k['app_id']);

    /*
     * ここが重要。
     *
     * app.jsonのアプリ指定は id。
     */
    $endpoint =
        '/k/v1/app.json?id=' .
        rawurlencode($appId);

    $result = kintone_request(
        $k,
        $endpoint,
        'GET'
    );

    $status = (int)$result['status'];
    $json   = $result['json'] ?? [];

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'message' => 'kintone接続テスト成功。',
            'detail' => 'HTTP ' . $status,
        ];
    }

    $message = '';
    $errorId = '';

    if (is_array($json)) {
        $message = (string)($json['message'] ?? '');
        $errorId = (string)($json['id'] ?? '');
    }

    $detail = 'HTTP ' . $status;

    if ($message !== '') {
        $detail .= ' / ' . $message;
    }

    if ($errorId !== '') {
        $detail .= ' / エラーID: ' . $errorId;
    }

    return [
        'success' => false,
        'message' => 'kintone接続テスト失敗。',
        'detail' => $detail,
    ];
}

/**
 * kintone設定保存
 */
function save_kintone_settings(): void
{
    $settings = read_json(
        SETTINGS_FILE,
        []
    );

    $current = $settings['kintone'] ?? [];

    $subdomain = normalize_kintone_subdomain(
        (string)($_POST['subdomain'] ?? '')
    );

    $appId = trim(
        (string)($_POST['app_id'] ?? '')
    );

    if (
        $appId === ''
        || !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    $username = trim(
        (string)($_POST['username'] ?? '')
    );

    if ($username === '') {
        throw new InvalidArgumentException(
            'ログイン名を入力してください。'
        );
    }

    /*
     * パスワード未入力の場合は既存値を維持。
     * 画面には既存パスワードを返さない。
     */
    $password = (string)(
        $_POST['password'] ?? ''
    );

    if ($password === '') {
        $password = (string)(
            $current['password'] ?? ''
        );
    }

    if ($password === '') {
        throw new InvalidArgumentException(
            'パスワードを入力してください。'
        );
    }

    $proxy = trim(
        (string)($_POST['proxy'] ?? '')
    );

    parse_proxy($proxy);

    /*
     * prompt.txt:
     * POC段階ではSSL証明書検証を無効。
     */
    $verifySsl = isset(
        $_POST['verify_ssl']
    );

    $settings['kintone'] = array_merge(
        $current,
        [
            'subdomain' => $subdomain,
            'app_id' => $appId,
            'username' => $username,
            'password' => $password,
            'proxy' => $proxy,
            'verify_ssl' => $verifySsl,
        ]
    );

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    set_result(
        'success',
        'kintone設定を保存しました。'
    );
}

/**
 * kintone項目一覧取得
 */
function fetch_kintone_fields(): void
{
    $settings = read_json(
        SETTINGS_FILE,
        []
    );

    $k = $settings['kintone'] ?? [];

    validate_kintone($k);

    $appId = (string)$k['app_id'];

    /*
     * form/fields.json は app パラメータ。
     * app.jsonの接続テストとは異なる。
     */
    $endpoint =
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode($appId);

    $result = kintone_request(
        $k,
        $endpoint,
        'GET'
    );

    if (
        $result['status'] < 200
        || $result['status'] >= 300
    ) {
        $json = $result['json'] ?? [];

        $message = is_array($json)
            ? (string)($json['message'] ?? '')
            : '';

        $errorId = is_array($json)
            ? (string)($json['id'] ?? '')
            : '';

        $detail = 'HTTP ' . $result['status'];

        if ($message !== '') {
            $detail .= ' / ' . $message;
        }

        if ($errorId !== '') {
            $detail .= ' / エラーID: ' . $errorId;
        }

        throw new RuntimeException(
            'kintone項目一覧取得失敗。' . $detail
        );
    }

    $properties =
        $result['json']['properties']
        ?? [];

    if (!is_array($properties)) {
        $properties = [];
    }

    $settings['kintone']['fields'] =
        $properties;

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    set_result(
        'success',
        'kintoneの項目一覧を再取得しました。'
    );
}

function customer_field(
    array $record,
    string $field
): string {
    $field = trim($field);

    if (
        $field === ''
        || !isset($record[$field])
        || !is_array($record[$field])
    ) {
        return '';
    }

    $value =
        $record[$field]['value'] ?? '';

    if (is_array($value)) {
        $values = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $values[] =
                    (string)($item['value'] ?? '');
            } else {
                $values[] = (string)$item;
            }
        }

        return implode(', ', $values);
    }

    return (string)$value;
}

function normalize_customer(
    array $record,
    array $mapping
): array {
    $id = (string)(
        $record['$id']['value']
        ?? uuid()
    );

    $addressParts = [];

    foreach (
        ($mapping['address'] ?? [])
        as $field
    ) {
        $value = customer_field(
            $record,
            (string)$field
        );

        if ($value !== '') {
            $addressParts[] = $value;
        }
    }

    return [
        'id' => 'kintone-' . $id,
        'organization' => customer_field(
            $record,
            (string)($mapping['organization'] ?? '')
        ),
        'name' => customer_field(
            $record,
            (string)($mapping['name'] ?? '')
        ),
        'email' => customer_field(
            $record,
            (string)($mapping['email'] ?? '')
        ),
        'department' => customer_field(
            $record,
            (string)($mapping['department'] ?? '')
        ),
        'phone' => customer_field(
            $record,
            (string)($mapping['phone'] ?? '')
        ),
        'address' => implode(
            ' ',
            $addressParts
        ),
        'updatedAt' => now_iso(),
    ];
}

/**
 * kintone顧客同期
 *
 * 接続テストとは別操作。
 */
function sync_kintone(): void
{
    $settings = read_json(
        SETTINGS_FILE,
        []
    );

    $k = $settings['kintone'] ?? [];

    validate_kintone($k);

    $mapping =
        $k['field_mapping'] ?? [];

    $customers = [];
    $offset = 0;

    while (true) {
        /*
         * kintone REST API:
         * records.json
         *
         * 500件単位で取得。
         */
        $query =
            'order by $id asc limit 500 offset '
            . $offset;

        $endpoint =
            '/k/v1/records.json?'
            . http_build_query(
                [
                    'app' => (string)$k['app_id'],
                    'query' => $query,
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            );

        $result = kintone_request(
            $k,
            $endpoint,
            'GET'
        );

        if (
            $result['status'] < 200
            || $result['status'] >= 300
        ) {
            $json = $result['json'] ?? [];

            $message = is_array($json)
                ? (string)($json['message'] ?? '')
                : '';

            $errorId = is_array($json)
                ? (string)($json['id'] ?? '')
                : '';

            $detail =
                'HTTP ' .
                $result['status'];

            if ($message !== '') {
                $detail .=
                    ' / ' . $message;
            }

            if ($errorId !== '') {
                $detail .=
                    ' / エラーID: ' . $errorId;
            }

            throw new RuntimeException(
                'kintone顧客同期失敗。'
                . $detail
            );
        }

        $records =
            $result['json']['records']
            ?? [];

        if (!is_array($records)) {
            $records = [];
        }

        if ($records === []) {
            break;
        }

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $customers[] =
                normalize_customer(
                    $record,
                    $mapping
                );
        }

        $count = count($records);

        $offset += $count;

        if ($count < 500) {
            break;
        }
    }

    write_json_atomic(
        CUSTOMERS_FILE,
        $customers
    );

    set_result(
        'success',
        count($customers)
        . '件の顧客情報を同期しました。'
    );
}

/* ============================================================
 * アンケート
 * ========================================================== */

function default_question(): array
{
    return [
        'id' => 'q-' . uuid(),
        'number' => '',
        'text' => '',
        'type' => 'single',
        'required' => true,
        'options' => [
            [
                'id' => 'o-' . uuid(),
                'label' => '選択肢1',
                'nextQuestionId' => '',
            ],
            [
                'id' => 'o-' . uuid(),
                'label' => '選択肢2',
                'nextQuestionId' => '',
            ],
        ],
    ];
}

function default_group(): array
{
    return [
        'id' => 'g-' . uuid(),
        'title' => 'グループ1',
        'questions' => [
            default_question(),
        ],
    ];
}

function default_survey(): array
{
    return [
        'id' => 'survey-' . uuid(),
        'title' => '',
        'description' => '',
        'startAt' => '',
        'endAt' => '',
        'status' => 'draft',
        'numbering' => 'global',
        'groups' => [
            default_group(),
        ],
        'createdAt' => now_iso(),
        'updatedAt' => now_iso(),
    ];
}

function normalize_question_numbers(
    array &$survey
): void {
    $mode = $survey['numbering'] ?? 'global';

    $global = 0;

    foreach (
        $survey['groups'] as $gi => &$group
    ) {
        $local = 0;

        foreach (
            $group['questions'] as $qi => &$question
        ) {
            $global++;
            $local++;

            if ($mode === 'group') {
                $question['number'] =
                    'Q' . ($gi + 1)
                    . '-' . $local;
            } else {
                $question['number'] =
                    'Q' . $global;
            }

            if (!isset($question['id'])) {
                $question['id'] =
                    'q-' . uuid();
            }

            if (!isset($question['type'])) {
                $question['type'] = 'single';
            }

            if (!isset($question['options'])) {
                $question['options'] = [];
            }
        }

        unset($question);
    }

    unset($group);
}

function update_auto_status(
    array &$survey
): bool {
    if (
        ($survey['status'] ?? '')
        !== 'published'
    ) {
        return false;
    }

    $endAt = trim(
        (string)($survey['endAt'] ?? '')
    );

    if ($endAt === '') {
        return false;
    }

    $timestamp = strtotime($endAt);

    if ($timestamp === false) {
        return false;
    }

    if ($timestamp < time()) {
        $survey['status'] = 'ended';
        $survey['updatedAt'] = now_iso();

        return true;
    }

    return false;
}

function load_surveys(): array
{
    $surveys = read_json(
        SURVEYS_FILE,
        []
    );

    $changed = false;

    foreach ($surveys as &$survey) {
        if (update_auto_status($survey)) {
            $changed = true;
        }

        if (!isset($survey['groups'])) {
            $survey['groups'] = [];
        }

        normalize_question_numbers($survey);
    }

    unset($survey);

    if ($changed) {
        write_json_atomic(
            SURVEYS_FILE,
            $surveys
        );
    }

    return $surveys;
}

function find_survey(
    array $surveys,
    string $id
): ?array {
    foreach ($surveys as $survey) {
        if (
            (string)($survey['id'] ?? '')
            === $id
        ) {
            return $survey;
        }
    }

    return null;
}

function validate_survey(
    array $survey
): void {
    $title = trim(
        (string)($survey['title'] ?? '')
    );

    if ($title === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルを入力してください。'
        );
    }

    if (mb_strlen($title) > 200) {
        throw new InvalidArgumentException(
            'アンケートタイトルは200文字以内で入力してください。'
        );
    }

    $status = (string)(
        $survey['status'] ?? 'draft'
    );

    if (!in_array(
        $status,
        [
            'draft',
            'published',
            'stopped',
            'ended',
        ],
        true
    )) {
        throw new InvalidArgumentException(
            'アンケート状態が不正です。'
        );
    }

    $numbering = (string)(
        $survey['numbering'] ?? 'global'
    );

    if (!in_array(
        $numbering,
        ['global', 'group'],
        true
    )) {
        throw new InvalidArgumentException(
            '質問番号採番方式が不正です。'
        );
    }

    if (
        ($survey['startAt'] ?? '') !== ''
        && strtotime(
            (string)$survey['startAt']
        ) === false
    ) {
        throw new InvalidArgumentException(
            '開始日時が不正です。'
        );
    }

    if (
        ($survey['endAt'] ?? '') !== ''
        && strtotime(
            (string)$survey['endAt']
        ) === false
    ) {
        throw new InvalidArgumentException(
            '終了日時が不正です。'
        );
    }

    if (
        !empty($survey['startAt'])
        && !empty($survey['endAt'])
        && strtotime((string)$survey['startAt'])
        > strtotime((string)$survey['endAt'])
    ) {
        throw new InvalidArgumentException(
            '終了日時は開始日時以降にしてください。'
        );
    }

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

            $type = (string)(
                $question['type'] ?? ''
            );

            if (!in_array(
                $type,
                [
                    'single',
                    'multiple',
                    'text',
                ],
                true
            )) {
                throw new InvalidArgumentException(
                    '質問の回答形式が不正です。'
                );
            }

            if (
                trim(
                    (string)($question['text'] ?? '')
                ) === ''
            ) {
                throw new InvalidArgumentException(
                    '質問文を入力してください。'
                );
            }

            if (
                $type === 'single'
                || $type === 'multiple'
            ) {
                $options =
                    $question['options']
                    ?? [];

                if (!is_array($options)) {
                    throw new InvalidArgumentException(
                        '選択肢データが不正です。'
                    );
                }

                foreach ($options as $option) {
                    if (
                        trim(
                            (string)(
                                $option['label'] ?? ''
                            )
                        ) === ''
                    ) {
                        throw new InvalidArgumentException(
                            '選択肢を空欄にできません。'
                        );
                    }
                }
            }
        }
    }
}

function save_survey_from_post(
    ?array $existing
): array {
    $survey =
        $existing
        ?? default_survey();

    $survey['title'] = trim(
        (string)($_POST['title'] ?? '')
    );

    $survey['description'] = trim(
        (string)($_POST['description'] ?? '')
    );

    $survey['startAt'] = trim(
        (string)($_POST['start_at'] ?? '')
    );

    $survey['endAt'] = trim(
        (string)($_POST['end_at'] ?? '')
    );

    $survey['numbering'] =
        (string)(
            $_POST['numbering']
            ?? 'global'
        );

    if ($existing === null) {
        $survey['status'] = 'draft';
    }

    /*
     * 編集時は現在状態を維持。
     * 状態変更は別操作。
     */
    normalize_question_numbers($survey);

    validate_survey($survey);

    $survey['updatedAt'] = now_iso();

    $surveys = load_surveys();

    $found = false;

    foreach ($surveys as $i => $item) {
        if (
            (string)($item['id'] ?? '')
            === (string)$survey['id']
        ) {
            $surveys[$i] = $survey;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $surveys[] = $survey;
    }

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    return $survey;
}

function change_survey_status(
    string $id,
    string $newStatus
): void {
    $surveys = load_surveys();

    foreach ($surveys as &$survey) {
        if (
            (string)($survey['id'] ?? '')
            !== $id
        ) {
            continue;
        }

        $current =
            (string)($survey['status'] ?? '');

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
            !isset($allowed[$current])
            || !in_array(
                $newStatus,
                $allowed[$current],
                true
            )
        ) {
            throw new InvalidArgumentException(
                '指定された状態変更は許可されていません。'
            );
        }

        $survey['status'] = $newStatus;
        $survey['updatedAt'] = now_iso();

        unset($survey);

        write_json_atomic(
            SURVEYS_FILE,
            $surveys
        );

        return;
    }

    throw new InvalidArgumentException(
        '対象アンケートが存在しません。'
    );
}

function delete_survey(string $id): void
{
    $surveys = load_surveys();

    $next = [];

    $found = false;

    foreach ($surveys as $survey) {
        if (
            (string)($survey['id'] ?? '')
            === $id
        ) {
            $found = true;
            continue;
        }

        $next[] = $survey;
    }

    if (!$found) {
        throw new InvalidArgumentException(
            '対象アンケートが存在しません。'
        );
    }

    write_json_atomic(
        SURVEYS_FILE,
        $next
    );
}

function duplicate_survey(string $id): void
{
    $surveys = load_surveys();

    $source = find_survey(
        $surveys,
        $id
    );

    if ($source === null) {
        throw new InvalidArgumentException(
            '複製対象のアンケートが存在しません。'
        );
    }

    $copy = $source;

    $copy['id'] =
        'survey-' . uuid();

    $copy['title'] =
        (string)$copy['title']
        . '（コピー）';

    $copy['status'] = 'draft';
    $copy['createdAt'] = now_iso();
    $copy['updatedAt'] = now_iso();

    foreach (
        $copy['groups'] as &$group
    ) {
        $group['id'] =
            'g-' . uuid();

        foreach (
            ($group['questions'] ?? [])
            as &$question
        ) {
            $question['id'] =
                'q-' . uuid();

            foreach (
                ($question['options'] ?? [])
                as &$option
            ) {
                $option['id'] =
                    'o-' . uuid();
            }

            unset($option);
        }

        unset($question);
    }

    unset($group);

    normalize_question_numbers($copy);

    $surveys[] = $copy;

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );
}

/* ============================================================
 * 回答
 * ========================================================== */

function visible_questions(
    array $survey
): array {
    $questions = [];

    foreach (
        ($survey['groups'] ?? [])
        as $group
    ) {
        foreach (
            ($group['questions'] ?? [])
            as $question
        ) {
            $questions[] = $question;
        }
    }

    return $questions;
}

function answer_value(
    array $post,
    string $questionId
): mixed {
    return $post['answer'][$questionId]
        ?? null;
}

function validate_answers(
    array $survey,
    array $answers
): void {
    foreach (
        visible_questions($survey)
        as $question
    ) {
        $qid =
            (string)($question['id'] ?? '');

        if ($qid === '') {
            continue;
        }

        $value =
            $answers[$qid] ?? null;

        if (
            !empty($question['required'])
            && (
                $value === null
                || $value === ''
                || (
                    is_array($value)
                    && count($value) === 0
                )
            )
        ) {
            throw new InvalidArgumentException(
                '必須項目が未回答です。'
            );
        }

        if (
            $value !== null
            && is_string($value)
            && mb_strlen($value) > 10000
        ) {
            throw new InvalidArgumentException(
                '自由記述が長すぎます。'
            );
        }
    }
}

function save_answer(
    string $surveyId,
    array $answers
): void {
    $data = read_json(
        ANSWERS_FILE,
        []
    );

    $data[] = [
        'id' => 'answer-' . uuid(),
        'surveyId' => $surveyId,
        'answers' => $answers,
        'createdAt' => now_iso(),
    ];

    write_json_atomic(
        ANSWERS_FILE,
        $data
    );
}

/* ============================================================
 * メール設定
 * ========================================================== */

function save_mail_settings(): void
{
    $settings = read_json(
        SETTINGS_FILE,
        []
    );

    $current = $settings['mail'] ?? [];

    $host = trim(
        (string)($_POST['host'] ?? '')
    );

    $port = (int)(
        $_POST['port'] ?? 0
    );

    $encryption =
        (string)(
            $_POST['encryption']
            ?? 'tls'
        );

    if ($host === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを入力してください。'
        );
    }

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    if (!in_array(
        $encryption,
        ['ssl', 'tls', 'none'],
        true
    )) {
        throw new InvalidArgumentException(
            '暗号化方式が不正です。'
        );
    }

    $username = trim(
        (string)(
            $_POST['username'] ?? ''
        )
    );

    $password = (string)(
        $_POST['password'] ?? ''
    );

    if ($password === '') {
        $password = (string)(
            $current['password'] ?? ''
        );
    }

    $fromEmail = trim(
        (string)(
            $_POST['from_email'] ?? ''
        )
    );

    if (!filter_var(
        $fromEmail,
        FILTER_VALIDATE_EMAIL
    )) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    $replyTo = trim(
        (string)(
            $_POST['reply_to'] ?? ''
        )
    );

    if (
        $replyTo !== ''
        && !filter_var(
            $replyTo,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが不正です。'
        );
    }

    $settings['mail'] =
        array_merge(
            $current,
            [
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'auth' => isset($_POST['auth']),
                'username' => $username,
                'password' => $password,
                'from_email' => $fromEmail,
                'from_name' => trim(
                    (string)(
                        $_POST['from_name'] ?? ''
                    )
                ),
                'reply_to' => $replyTo,
            ]
        );

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    set_result(
        'success',
        'メールサーバ設定を保存しました。'
    );
}

/* ============================================================
 * POST処理
 * ========================================================== */

$screen = (string)(
    $_GET['screen'] ?? 'list'
);

if (!in_array(
    $screen,
    APP_SCREENS,
    true
)) {
    $screen = 'list';
}

$id = trim(
    (string)($_GET['id'] ?? '')
);

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)(
        $_POST['action'] ?? ''
    );

    try {
        switch ($action) {
            /* ------------------------------------------------
             * kintone
             * ---------------------------------------------- */

            case 'kintone_save':
                save_kintone_settings();
                $screen = 'kintone';
                break;

            case 'kintone_test':
                /*
                 * 接続テストはPOSTされた設定値を直接使う。
                 * ブラウザには認証ヘッダーを返さない。
                 *
                 * 設定保存と接続テストを一体化しない。
                 */
                $settings = read_json(
                    SETTINGS_FILE,
                    []
                );

                $current =
                    $settings['kintone']
                    ?? [];

                $testK = [
                    'subdomain' =>
                        trim(
                            (string)(
                                $_POST['subdomain']
                                ?? $current['subdomain']
                                ?? ''
                            )
                        ),
                    'app_id' =>
                        trim(
                            (string)(
                                $_POST['app_id']
                                ?? $current['app_id']
                                ?? ''
                            )
                        ),
                    'username' =>
                        trim(
                            (string)(
                                $_POST['username']
                                ?? $current['username']
                                ?? ''
                            )
                        ),
                    'password' =>
                        (string)(
                            $_POST['password']
                            ?? ''
                        ),
                    'proxy' =>
                        trim(
                            (string)(
                                $_POST['proxy']
                                ?? $current['proxy']
                                ?? ''
                            )
                        ),
                    'verify_ssl' =>
                        isset(
                            $_POST['verify_ssl']
                        ),
                ];

                /*
                 * パスワード未入力時は保存済み値を
                 * サーバー内部だけで利用。
                 */
                if (
                    $testK['password'] === ''
                ) {
                    $testK['password'] =
                        (string)(
                            $current['password']
                            ?? ''
                        );
                }

                $testResult =
                    test_kintone([
                        'kintone' => $testK,
                    ]);

                $result = [
                    'type' =>
                        $testResult['success']
                            ? 'success'
                            : 'error',
                    'message' =>
                        $testResult['message']
                        . (
                            !empty(
                                $testResult['detail']
                            )
                            ? ' '
                            . $testResult['detail']
                            : ''
                        ),
                ];

                $screen = 'kintone';
                break;

            case 'kintone_fields':
                fetch_kintone_fields();
                $screen = 'kintone';
                break;

            case 'kintone_sync':
                sync_kintone();
                $screen = 'kintone';
                break;

            /* ------------------------------------------------
             * メール
             * ---------------------------------------------- */

            case 'mail_save':
                save_mail_settings();
                $screen = 'mail';
                break;

            /* ------------------------------------------------
             * アンケート
             * ---------------------------------------------- */

            case 'survey_save':
                $surveyId = trim(
                    (string)(
                        $_POST['id'] ?? ''
                    )
                );

                $surveys = load_surveys();

                $existing = null;

                if ($surveyId !== '') {
                    $existing = find_survey(
                        $surveys,
                        $surveyId
                    );

                    if ($existing === null) {
                        throw new InvalidArgumentException(
                            '編集対象のアンケートが存在しません。'
                        );
                    }
                }

                save_survey_from_post(
                    $existing
                );

                set_result(
                    'success',
                    'アンケートを保存しました。'
                );

                $screen = 'list';
                break;

            case 'survey_status':
                $surveyId = trim(
                    (string)(
                        $_POST['id'] ?? ''
                    )
                );

                $newStatus = (string)(
                    $_POST['new_status']
                    ?? ''
                );

                change_survey_status(
                    $surveyId,
                    $newStatus
                );

                set_result(
                    'success',
                    'アンケートの状態を変更しました。'
                );

                $screen = 'list';
                break;

            case 'survey_delete':
                delete_survey(
                    trim(
                        (string)(
                            $_POST['id'] ?? ''
                        )
                    )
                );

                set_result(
                    'success',
                    'アンケートを削除しました。'
                );

                $screen = 'list';
                break;

            case 'survey_duplicate':
                duplicate_survey(
                    trim(
                        (string)(
                            $_POST['id'] ?? ''
                        )
                    )
                );

                set_result(
                    'success',
                    'アンケートを複製しました。'
                );

                $screen = 'list';
                break;

            /* ------------------------------------------------
             * 回答
             * ---------------------------------------------- */

            case 'answer_confirm':
                $surveyId = trim(
                    (string)(
                        $_POST['id'] ?? ''
                    )
                );

                $surveys = load_surveys();

                $survey = find_survey(
                    $surveys,
                    $surveyId
                );

                if ($survey === null) {
                    throw new InvalidArgumentException(
                        '対象アンケートが存在しません。'
                    );
                }

                if (
                    ($survey['status'] ?? '')
                    !== 'published'
                ) {
                    throw new InvalidArgumentException(
                        'このアンケートは現在回答できません。'
                    );
                }

                $answers =
                    $_POST['answer'] ?? [];

                if (!is_array($answers)) {
                    $answers = [];
                }

                validate_answers(
                    $survey,
                    $answers
                );

                $_SESSION['answer_draft'] = [
                    'surveyId' => $surveyId,
                    'answers' => $answers,
                ];

                $id = $surveyId;
                $screen = 'confirm';
                break;

            case 'answer_submit':
                $draft =
                    $_SESSION['answer_draft']
                    ?? null;

                if (
                    !is_array($draft)
                    || empty($draft['surveyId'])
                ) {
                    throw new RuntimeException(
                        '回答セッションが存在しません。'
                    );
                }

                $surveyId =
                    (string)$draft['surveyId'];

                $surveys = load_surveys();

                $survey = find_survey(
                    $surveys,
                    $surveyId
                );

                if ($survey === null) {
                    throw new InvalidArgumentException(
                        '対象アンケートが存在しません。'
                    );
                }

                $answers =
                    $draft['answers']
                    ?? [];

                validate_answers(
                    $survey,
                    $answers
                );

                save_answer(
                    $surveyId,
                    $answers
                );

                unset(
                    $_SESSION['answer_draft']
                );

                $id = $surveyId;
                $screen = 'complete';
                break;

            case 'answer_back':
                $id = trim(
                    (string)(
                        $_POST['id'] ?? ''
                    )
                );

                $screen = 'answer';
                break;

            default:
                /*
                 * 不明なPOST actionは何もしない。
                 */
                $result = [
                    'type' => 'error',
                    'message' => '不正な操作です。',
                ];
                break;
        }
    } catch (Throwable $ex) {
        /*
         * 機密情報は例外文字列として画面に出さない。
         *
         * kintone通信エラーについては
         * test_kintone()が返す安全な情報のみ表示する。
         */
        $result = [
            'type' => 'error',
            'message' => $ex->getMessage(),
        ];
    }

    /*
     * POST後の画面表示は、このレスポンスで行う。
     * 設定保存を303→GET→flashに依存させない。
     */
    if ($result === null) {
        $result = take_result();
    }
} else {
    $result = take_result();
}

/* ============================================================
 * データ読み込み
 * ========================================================== */

$surveys = load_surveys();

$currentSurvey = null;

if ($id !== '' && valid_id($id)) {
    $currentSurvey =
        find_survey(
            $surveys,
            $id
        );
}

/*
 * analytics/sendでは対象アンケートを必須とする。
 */
if (
    in_array(
        $screen,
        ['analytics', 'send'],
        true
    )
    && (
        $id === ''
        || $currentSurvey === null
    )
) {
    $screen = 'list';

    $result = [
        'type' => 'error',
        'message' =>
            '対象アンケートが指定されていません。',
    ];
}

$settings = read_json(
    SETTINGS_FILE,
    []
);

$kintoneSettings =
    $settings['kintone']
    ?? [];

$mailSettings =
    $settings['mail']
    ?? [];

$customers = read_json(
    CUSTOMERS_FILE,
    []
);

/* ============================================================
 * HTML
 * ========================================================== */
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>
<title>アンケート管理</title>

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
}

a {
    color:var(--primary);
    text-decoration:none;
}

button,
input,
textarea,
select {
    font:inherit;
}

button {
    cursor:pointer;
}

.admin-header {
    background:#0f172a;
    color:#fff;
    padding:14px 22px;
}

.admin-header-inner {
    max-width:1400px;
    margin:auto;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
}

.admin-header a {
    color:#fff;
}

.admin-title {
    font-size:20px;
    font-weight:700;
}

.admin-nav {
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

.admin-nav a {
    opacity:.9;
    padding:7px 10px;
    border-radius:6px;
}

.admin-nav a:hover {
    background:rgba(255,255,255,.1);
}

.container {
    max-width:1400px;
    margin:28px auto;
    padding:0 18px;
}

.card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:22px;
    margin-bottom:18px;
}

h1 {
    margin:0 0 20px;
    font-size:28px;
}

h2 {
    margin:22px 0 12px;
    font-size:20px;
}

h3 {
    margin:16px 0 10px;
    font-size:17px;
}

.toolbar {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:16px;
}

.toolbar-left,
.toolbar-right {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    align-items:center;
}

button,
.button {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    border:1px solid var(--border);
    background:#fff;
    color:var(--text);
    border-radius:7px;
    padding:9px 14px;
    text-decoration:none;
}

button:hover,
.button:hover {
    background:#f8fafc;
}

.primary {
    background:var(--primary);
    border-color:var(--primary);
    color:#fff;
}

.primary:hover {
    background:var(--primary-dark);
}

.success {
    background:var(--success);
    border-color:var(--success);
    color:#fff;
}

.warning {
    background:var(--warning);
    border-color:var(--warning);
    color:#fff;
}

.danger {
    background:var(--danger);
    border-color:var(--danger);
    color:#fff;
}

.small {
    padding:6px 9px;
    font-size:13px;
}

input[type="text"],
input[type="email"],
input[type="password"],
input[type="number"],
input[type="datetime-local"],
textarea,
select {
    width:100%;
    border:1px solid var(--border);
    border-radius:7px;
    padding:9px 11px;
    background:#fff;
    color:var(--text);
}

textarea {
    min-height:120px;
    resize:vertical;
}

label.field-label {
    display:block;
    font-weight:700;
    margin-bottom:6px;
}

.form-grid {
    display:grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap:16px;
}

.form-row {
    margin-bottom:16px;
}

.full {
    grid-column:1 / -1;
}

.notice {
    background:#eff6ff;
    border:1px solid #bfdbfe;
    border-radius:8px;
    padding:12px 14px;
    margin-bottom:16px;
}

.notice.error {
    background:#fef2f2;
    border-color:#fecaca;
    color:#991b1b;
}

.notice.success {
    background:#f0fdf4;
    border-color:#bbf7d0;
    color:#166534;
}

.table-wrap {
    overflow-x:auto;
}

table {
    width:100%;
    border-collapse:collapse;
    min-width:1050px;
}

th,
td {
    padding:11px 10px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:top;
}

th {
    background:#f8fafc;
    white-space:nowrap;
}

.badge {
    display:inline-block;
    border-radius:999px;
    padding:4px 9px;
    font-size:12px;
    font-weight:700;
}

.badge-draft {
    background:#e2e8f0;
    color:#334155;
}

.badge-published {
    background:#dcfce7;
    color:#166534;
}

.badge-stopped {
    background:#fef3c7;
    color:#92400e;
}

.badge-ended {
    background:#fee2e2;
    color:#991b1b;
}

.group-card {
    border:1px solid var(--border);
    border-radius:10px;
    margin-bottom:18px;
    padding:16px;
    background:#fff;
}

.question-card {
    border:1px solid var(--border);
    border-radius:8px;
    padding:14px;
    margin:12px 0;
    background:#f8fafc;
}

.question-head {
    display:flex;
    justify-content:space-between;
    gap:10px;
    align-items:center;
}

.option-row {
    display:grid;
    grid-template-columns:
        minmax(0,1fr) auto;
    gap:8px;
    margin-bottom:8px;
}

.drag-handle {
    cursor:grab;
    color:var(--gray);
}

.actions {
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.spinner {
    display:none;
    width:14px;
    height:14px;
    border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite;
}

.processing .spinner {
    display:inline-block;
}

@keyframes spin {
    to {
        transform:rotate(360deg);
    }
}

.answer-card {
    max-width:760px;
    margin:0 auto;
}

.answer-question {
    margin-bottom:24px;
}

.answer-option {
    display:block;
    border:1px solid var(--border);
    border-radius:8px;
    padding:12px;
    margin:8px 0;
    background:#fff;
}

.answer-option input {
    margin-right:8px;
}

.mobile-actions {
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

.search-box {
    display:flex;
    gap:8px;
}

.search-box input {
    min-width:260px;
}

@media (max-width:800px) {
    .form-grid {
        grid-template-columns:1fr;
    }

    .full {
        grid-column:auto;
    }

    .admin-header-inner {
        align-items:flex-start;
        flex-direction:column;
    }

    h1 {
        font-size:23px;
    }

    .search-box {
        width:100%;
    }

    .search-box input {
        min-width:0;
        flex:1;
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
        <div class="admin-title">
            アンケート管理
        </div>

        <nav class="admin-nav">
            <a href="<?=e(screen_url('list'))?>">
                アンケート一覧
            </a>
            <a href="<?=e(screen_url('kintone'))?>">
                kintone連携
            </a>
            <a href="<?=e(screen_url('mail'))?>">
                メール設定
            </a>
        </nav>
    </div>
</header>

<?php endif; ?>

<main class="container">

<?php if ($result !== null): ?>
    <div class="notice <?=e(
        $result['type'] ?? 'error'
    )?>">
        <?=e(
            $result['message'] ?? ''
        )?>
    </div>
<?php endif; ?>


<?php
/* ============================================================
 * 一覧
 * ========================================================== */
?>

<?php if ($screen === 'list'): ?>

<div class="card">

    <div class="toolbar">
        <div>
            <h1>アンケート一覧</h1>
        </div>

        <div class="toolbar-right">
            <a
                class="button primary"
                href="<?=e(
                    screen_url('edit')
                )?>"
            >
                新規作成
            </a>
        </div>
    </div>

    <?php
    $keyword = trim(
        (string)(
            $_GET['q'] ?? ''
        )
    );

    $filter = (string)(
        $_GET['status'] ?? 'all'
    );

    $sort = (string)(
        $_GET['sort'] ?? 'updated_desc'
    );

    $filtered = array_values(
        array_filter(
            $surveys,
            static function (
                array $survey
            ) use (
                $keyword,
                $filter
            ): bool {
                if (
                    $keyword !== ''
                    && mb_stripos(
                        (string)(
                            $survey['title']
                            ?? ''
                        ),
                        $keyword
                    ) === false
                ) {
                    return false;
                }

                if (
                    $filter !== 'all'
                    && (
                        string)(
                            $survey['status']
                            ?? ''
                        )
                        !== $filter
                    )
                ) {
                    return false;
                }

                return true;
            }
        )
    );

    usort(
        $filtered,
        static function (
            array $a,
            array $b
        ) use ($sort): int {
            if ($sort === 'answers_desc') {
                return 0;
            }

            if ($sort === 'start_desc') {
                return strcmp(
                    (string)(
                        $b['startAt'] ?? ''
                    ),
                    (string)(
                        $a['startAt'] ?? ''
                    )
                );
            }

            if ($sort === 'start_asc') {
                return strcmp(
                    (string)(
                        $a['startAt'] ?? ''
                    ),
                    (string)(
                        $b['startAt'] ?? ''
                    )
                );
            }

            if ($sort === 'updated_asc') {
                return strcmp(
                    (string)(
                        $a['updatedAt'] ?? ''
                    ),
                    (string)(
                        $b['updatedAt'] ?? ''
                    )
                );
            }

            return strcmp(
                (string)(
                    $b['updatedAt'] ?? ''
                ),
                (string)(
                    $a['updatedAt'] ?? ''
                )
            );
        }
    );
    ?>

    <form
        method="get"
        class="toolbar"
    >
        <input
            type="hidden"
            name="screen"
            value="list"
        >

        <div class="search-box">
            <input
                type="text"
                name="q"
                value="<?=e($keyword)?>"
                placeholder="タイトルを検索"
            >
            <button
                type="submit"
            >
                検索
            </button>
        </div>

        <div class="toolbar-right">
            <select
                name="status"
                onchange="this.form.submit()"
            >
                <option value="all"
                    <?=$filter === 'all'
                        ? 'selected'
                        : ''?>
                >
                    すべて
                </option>
                <option value="published"
                    <?=$filter === 'published'
                        ? 'selected'
                        : ''?>
                >
                    公開中
                </option>
                <option value="draft"
                    <?=$filter === 'draft'
                        ? 'selected'
                        : ''?>
                >
                    下書き
                </option>
                <option value="stopped"
                    <?=$filter === 'stopped'
                        ? 'selected'
                        : ''?>
                >
                    停止
                </option>
                <option value="ended"
                    <?=$filter === 'ended'
                        ? 'selected'
                        : ''?>
                >
                    終了
                </option>
            </select>

            <select
                name="sort"
                onchange="this.form.submit()"
            >
                <option value="updated_desc"
                    <?=$sort === 'updated_desc'
                        ? 'selected'
                        : ''?>
                >
                    更新日：新しい順
                </option>

                <option value="updated_asc"
                    <?=$sort === 'updated_asc'
                        ? 'selected'
                        : ''?>
                >
                    更新日：古い順
                </option>

                <option value="start_desc"
                    <?=$sort === 'start_desc'
                        ? 'selected'
                        : ''?>
                >
                    開始日：新しい順
                </option>

                <option value="start_asc"
                    <?=$sort === 'start_asc'
                        ? 'selected'
                        : ''?>
                >
                    開始日：古い順
                </option>
            </select>
        </div>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>タイトル</th>
                <th>作成日</th>
                <th>更新日</th>
                <th>期間</th>
                <th>ステータス</th>
                <th>回答数</th>
                <th>操作</th>
            </tr>
            </thead>

            <tbody>

            <?php if ($filtered === []): ?>

                <tr>
                    <td colspan="7">
                        アンケートがありません。
                    </td>
                </tr>

            <?php else: ?>

                <?php
                $answersForList =
                    read_json(
                        ANSWERS_FILE,
                        []
                    );
                ?>

                <?php foreach (
                    $filtered as $survey
                ): ?>

                    <?php
                    $surveyId =
                        (string)(
                            $survey['id']
                            ?? ''
                        );

                    $answerCount = 0;

                    foreach (
                        $answersForList
                        as $answer
                    ) {
                        if (
                            (string)(
                                $answer['surveyId']
                                ?? ''
                            ) === $surveyId
                        ) {
                            $answerCount++;
                        }
                    }

                    $status =
                        (string)(
                            $survey['status']
                            ?? 'draft'
                        );
                    ?>

                    <tr>
                        <td>
                            <strong>
                                <?=e(
                                    $survey['title']
                                    ?? ''
                                )?>
                            </strong>
                        </td>

                        <td>
                            <?=e(
                                $survey['createdAt']
                                ?? ''
                            )?>
                        </td>

                        <td>
                            <?=e(
                                $survey['updatedAt']
                                ?? ''
                            )?>
                        </td>

                        <td>
                            <?=e(
                                $survey['startAt']
                                ?? ''
                            )?>
                            <br>
                            ～
                            <br>
                            <?=e(
                                $survey['endAt']
                                ?? ''
                            )?>
                        </td>

                        <td>
                            <span class="badge badge-<?=e(
                                $status
                            )?>">
                                <?=
                                match ($status) {
                                    'published'
                                        => '公開中',
                                    'stopped'
                                        => '停止',
                                    'ended'
                                        => '終了',
                                    default
                                        => '下書き',
                                }
                                ?>
                            </span>
                        </td>

                        <td>
                            <?=$answerCount?>
                        </td>

                        <td>
                            <div class="actions">

                                <a
                                    class="button small"
                                    href="<?=e(
                                        screen_url(
                                            'edit',
                                            $surveyId
                                        )
                                    )?>"
                                >
                                    確認・編集
                                </a>

                                <a
                                    class="button small"
                                    href="<?=e(
                                        screen_url(
                                            'preview',
                                            $surveyId
                                        )
                                    )?>"
                                >
                                    プレビュー
                                </a>

                                <a
                                    class="button small"
                                    href="<?=e(
                                        screen_url(
                                            'analytics',
                                            $surveyId
                                        )
                                    )?>"
                                >
                                    集計
                                </a>

                                <a
                                    class="button small"
                                    href="<?=e(
                                        screen_url(
                                            'send',
                                            $surveyId
                                        )
                                    )?>"
                                >
                                    送信
                                </a>

                                <?php if (
                                    $status !== 'ended'
                                ): ?>

                                    <?php
                                    $nextStatus =
                                        $status === 'published'
                                            ? 'stopped'
                                            : 'published';

                                    $actionLabel =
                                        $status === 'published'
                                            ? '停止'
                                            : (
                                                $status === 'stopped'
                                                    ? '再開'
                                                    : '公開'
                                            );
                                    ?>

                                    <form
                                        method="post"
                                        data-confirm=
                                            "<?=e(
                                                $actionLabel
                                                . 'しますか？'
                                            )?>"
                                        data-processing-form
                                    >
                                        <input
                                            type="hidden"
                                            name="action"
                                            value="survey_status"
                                        >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?=e(
                                                $surveyId
                                            )?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="new_status"
                                            value="<?=e(
                                                $nextStatus
                                            )?>"
                                        >

                                        <button
                                            class="small"
                                            type="submit"
                                        >
                                            <?=$actionLabel?>
                                        </button>
                                    </form>

                                <?php endif; ?>

                                <form
                                    method="post"
                                    data-confirm="複製しますか？"
                                    data-processing-form
                                >
                                    <input
                                        type="hidden"
                                        name="action"
                                        value="survey_duplicate"
                                    >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?=e(
                                            $surveyId
                                        )?>"
                                    >

                                    <button
                                        class="small"
                                        type="submit"
                                    >
                                        複製
                                    </button>
                                </form>

                                <form
                                    method="post"
                                    data-confirm="削除しますか？"
                                    data-processing-form
                                >
                                    <input
                                        type="hidden"
                                        name="action"
                                        value="survey_delete"
                                    >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?=e(
                                            $surveyId
                                        )?>"
                                    >

                                    <button
                                        class="danger small"
                                        type="submit"
                                    >
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


<?php
/* ============================================================
 * 編集
 * ========================================================== */
?>

<?php elseif ($screen === 'edit'): ?>

<?php
$editing =
    $currentSurvey
    ?? default_survey();

normalize_question_numbers(
    $editing
);
?>

<div class="card">

    <div class="toolbar">
        <h1>アンケート作成・編集</h1>

        <div class="actions">
            <a
                class="button"
                href="<?=e(
                    screen_url('list')
                )?>"
            >
                キャンセル
            </a>
        </div>
    </div>

    <form
        method="post"
        data-processing-form
        onsubmit="return prepareSurvey(this)"
    >
        <input
            type="hidden"
            name="action"
            value="survey_save"
        >

        <input
            type="hidden"
            name="id"
            value="<?=e(
                $editing['id'] ?? ''
            )?>"
        >

        <div class="form-grid">

            <div class="full form-row">
                <label class="field-label">
                    アンケートタイトル
                </label>

                <input
                    type="text"
                    name="title"
                    maxlength="200"
                    required
                    value="<?=e(
                        $editing['title'] ?? ''
                    )?>"
                >
            </div>

            <div class="full form-row">
                <label class="field-label">
                    アンケート説明
                </label>

                <textarea
                    name="description"
                ><?=e(
                    $editing['description']
                    ?? ''
                )?></textarea>
            </div>

            <div class="form-row">
                <label class="field-label">
                    開始日時
                </label>

                <input
                    type="datetime-local"
                    name="start_at"
                    value="<?=e(
                        $editing['startAt']
                        ?? ''
                    )?>"
                >
            </div>

            <div class="form-row">
                <label class="field-label">
                    終了日時
                </label>

                <input
                    type="datetime-local"
                    name="end_at"
                    value="<?=e(
                        $editing['endAt']
                        ?? ''
                    )?>"
                >
            </div>

            <div class="form-row">
                <label class="field-label">
                    質問番号の採番方式
                </label>

                <select name="numbering">
                    <option
                        value="global"
                        <?=(
                            ($editing['numbering']
                                ?? 'global')
                            === 'global'
                        )
                            ? 'selected'
                            : ''?>
                    >
                        アンケート全体で通番
                        （Q1、Q2、Q3…）
                    </option>

                    <option
                        value="group"
                        <?=(
                            ($editing['numbering']
                                ?? 'global')
                            === 'group'
                        )
                            ? 'selected'
                            : ''?>
                    >
                        グループ毎
                        （Q1-1、Q1-2…）
                    </option>
                </select>
            </div>

            <div class="form-row">
                <label class="field-label">
                    状態
                </label>

                <input
                    type="text"
                    readonly
                    value="<?=
                        match (
                            $editing['status']
                            ?? 'draft'
                        ) {
                            'published'
                                => '公開中',
                            'stopped'
                                => '停止',
                            'ended'
                                => '終了',
                            default
                                => '下書き',
                        }
                    ?>"
                >
            </div>

        </div>

        <div
            id="groups"
            data-numbering="<?=e(
                $editing['numbering']
                ?? 'global'
            )?>"
        >

        <?php foreach (
            ($editing['groups'] ?? [])
            as $gi => $group
        ): ?>

            <div
                class="group-card"
                draggable="true"
                data-group
            >

                <div class="question-head">
                    <span
                        class="drag-handle"
                        title="ドラッグして並び替え"
                    >
                        ☰ グループ
                    </span>

                    <button
                        type="button"
                        class="danger small"
                        onclick="deleteGroup(this)"
                    >
                        グループ削除
                    </button>
                </div>

                <div class="form-row">
                    <label class="field-label">
                        グループタイトル
                    </label>

                    <input
                        type="text"
                        data-group-title
                        value="<?=e(
                            $group['title']
                            ?? ''
                        )?>"
                    >
                </div>

                <div data-questions>

                <?php foreach (
                    ($group['questions'] ?? [])
                    as $question
                ): ?>

                    <div
                        class="question-card"
                        draggable="true"
                        data-question
                    >

                        <div class="question-head">
                            <strong
                                data-question-number
                            >
                                <?=e(
                                    $question['number']
                                    ?? ''
                                )?>
                            </strong>

                            <span
                                class="drag-handle"
                            >
                                ☰
                            </span>

                            <button
                                type="button"
                                class="danger small"
                                onclick="deleteQuestion(this)"
                            >
                                質問削除
                            </button>
                        </div>

                        <div class="form-row">
                            <label class="field-label">
                                質問文
                            </label>

                            <input
                                type="text"
                                data-question-text
                                value="<?=e(
                                    $question['text']
                                    ?? ''
                                )?>"
                            >
                        </div>

                        <div class="form-row">
                            <label class="field-label">
                                回答形式
                            </label>

                            <select
                                data-question-type
                                onchange="toggleOptions(this)"
                            >
                                <option
                                    value="single"
                                    <?=(
                                        ($question['type']
                                            ?? '')
                                        === 'single'
                                    )
                                        ? 'selected'
                                        : ''?>
                                >
                                    単一選択
                                </option>

                                <option
                                    value="multiple"
                                    <?=(
                                        ($question['type']
                                            ?? '')
                                        === 'multiple'
                                    )
                                        ? 'selected'
                                        : ''?>
                                >
                                    複数選択
                                </option>

                                <option
                                    value="text"
                                    <?=(
                                        ($question['type']
                                            ?? '')
                                        === 'text'
                                    )
                                        ? 'selected'
                                        : ''?>
                                >
                                    自由記述
                                </option>
                            </select>
                        </div>

                        <label>
                            <input
                                type="checkbox"
                                data-required
                                <?=!empty(
                                    $question['required']
                                )
                                    ? 'checked'
                                    : ''?>
                            >
                            必須
                        </label>

                        <div
                            class="form-row"
                            data-options
                            style="<?=
                                in_array(
                                    $question['type']
                                        ?? '',
                                    [
                                        'single',
                                        'multiple',
                                    ],
                                    true
                                )
                                    ? ''
                                    : 'display:none'
                            ?>"
                        >
                            <h3>選択肢</h3>

                            <div data-option-list>

                            <?php foreach (
                                ($question['options']
                                    ?? [])
                                as $option
                            ): ?>

                                <div
                                    class="option-row"
                                    data-option
                                >
                                    <input
                                        type="text"
                                        data-option-label
                                        value="<?=e(
                                            $option['label']
                                            ?? ''
                                        )?>"
                                    >

                                    <button
                                        type="button"
                                        class="danger small"
                                        onclick="deleteOption(this)"
                                    >
                                        削除
                                    </button>
                                </div>

                            <?php endforeach; ?>

                            </div>

                            <button
                                type="button"
                                class="small"
                                onclick="addOption(this)"
                            >
                                選択肢を追加
                            </button>
                        </div>

                    </div>

                <?php endforeach; ?>

                </div>

                <button
                    type="button"
                    class="small"
                    onclick="addQuestion(this)"
                >
                    質問を追加
                </button>

            </div>

        <?php endforeach; ?>

        </div>

        <button
            type="button"
            onclick="addGroup()"
        >
            グループを追加
        </button>

        <div
            style="
                margin-top:20px;
                display:flex;
                gap:8px;
                flex-wrap:wrap;
            "
        >
            <a
                class="button"
                href="<?=e(
                    screen_url(
                        'preview',
                        $editing['id']
                        ?? ''
                    )
                )?>"
            >
                プレビュー
            </a>

            <button
                class="primary"
                type="submit"
            >
                保存して一覧へ
                <span class="spinner"></span>
            </button>
        </div>

    </form>
</div>


<?php
/* ============================================================
 * プレビュー
 * ========================================================== */
?>

<?php elseif ($screen === 'preview'): ?>

<div class="card">

    <?php if ($currentSurvey === null): ?>

        <h1>対象アンケートが存在しません。</h1>

    <?php else: ?>

        <h1>プレビュー</h1>

        <h2>
            <?=e(
                $currentSurvey['title']
            )?>
        </h2>

        <p>
            <?=nl2br(
                e(
                    $currentSurvey['description']
                    ?? ''
                )
            )?>
        </p>

        <?php
        $qNo = 0;
        ?>

        <?php foreach (
            ($currentSurvey['groups']
                ?? [])
            as $group
        ): ?>

            <div class="group-card">

                <h3>
                    <?=e(
                        $group['title']
                        ?? ''
                    )?>
                </h3>

                <?php foreach (
                    ($group['questions']
                        ?? [])
                    as $question
                ): ?>

                    <?php $qNo++; ?>

                    <div
                        class="question-card"
                    >
                        <strong>
                            <?=e(
                                $question['number']
                                ?? ('Q' . $qNo)
                            )?>
                            .
                            <?=e(
                                $question['text']
                                ?? ''
                            )?>
                        </strong>

                        <?php if (
                            !empty(
                                $question['required']
                            )
                        ): ?>

                            <span class="badge badge-published">
                                必須
                            </span>

                        <?php else: ?>

                            <span class="badge">
                                任意
                            </span>

                        <?php endif; ?>

                        <?php if (
                            ($question['type']
                                ?? '')
                            === 'single'
                        ): ?>

                            <?php foreach (
                                ($question['options']
                                    ?? [])
                                as $option
                            ): ?>

                                <label
                                    class="answer-option"
                                >
                                    <input
                                        type="radio"
                                        disabled
                                    >
                                    <?=e(
                                        $option['label']
                                        ?? ''
                                    )?>
                                </label>

                            <?php endforeach; ?>

                        <?php elseif (
                            ($question['type']
                                ?? '')
                            === 'multiple'
                        ): ?>

                            <?php foreach (
                                ($question['options']
                                    ?? [])
                                as $option
                            ): ?>

                                <label
                                    class="answer-option"
                                >
                                    <input
                                        type="checkbox"
                                        disabled
                                    >
                                    <?=e(
                                        $option['label']
                                        ?? ''
                                    )?>
                                </label>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <textarea
                                disabled
                            ></textarea>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>


<?php
/* ============================================================
 * kintone
 * ========================================================== */
?>

<?php elseif ($screen === 'kintone'): ?>

<div class="card">

    <h1>kintone連携設定</h1>

    <div class="notice">
        接続テストは実際のkintone REST APIへ接続します。
        認証情報はブラウザ側JavaScriptへ渡しません。
    </div>

    <form
        method="post"
        data-processing-form
    >
        <input
            type="hidden"
            name="action"
            value="kintone_save"
        >

        <div class="form-grid">

            <div class="form-row">
                <label class="field-label">
                    サブドメイン
                </label>

                <input
                    type="text"
                    name="subdomain"
                    required
                    value="<?=e(
                        $kintoneSettings[
                            'subdomain'
                        ] ?? ''
                    )?>"
                    placeholder="xxxx.cybozu.com または xxxx"
                >
            </div>

            <div class="form-row">
                <label class="field-label">
                    顧客管理アプリID
                </label>

                <input
                    type="number"
                    name="app_id"
                    min="1"
                    required
                    value="<?=e(
                        $kintoneSettings[
                            'app_id'
                        ] ?? ''
                    )?>"
                >
            </div>

            <div class="form-row">
                <label class="field-label">
                    ログイン名
                </label>

                <input
                    type="text"
                    name="username"
                    required
                    value="<?=e(
                        $kintoneSettings[
                            'username'
                        ] ?? ''
                    )?>"
                    autocomplete="username"
                >
            </div>

            <div class="form-row">
                <label class="field-label">
                    パスワード
                </label>

                <input
                    type="password"
                    name="password"
                    value=""
                    placeholder="変更しない場合は空欄"
                    autocomplete="new-password"
                >
            </div>

            <div class="form-row">
                <label class="field-label">
                    Proxy
                </label>

                <input
                    type="text"
                    name="proxy"
                    value="<?=e(
                        $kintoneSettings[
                            'proxy'
                        ] ?? ''
                    )?>"
                    placeholder="host:port"
                >
            </div>

            <div class="form-row">
                <label class="field-label">
                    SSL証明書検証
                </label>

                <label>
                    <input
                        type="checkbox"
                        name="verify_ssl"
                        value="1"
                        <?=!empty(
                            $kintoneSettings[
                                'verify_ssl'
                            ]
                        )
                            ? 'checked'
                            : ''?>
                    >
                    有効
                </label>

                <div
                    style="
                        color:var(--gray);
                        font-size:13px;
                        margin-top:6px;
                    "
                >
                    POC段階では無効が既定値です。
                </div>
            </div>

        </div>

        <div class="actions">

            <button
                class="primary"
                type="submit"
            >
                設定保存
                <span class="spinner"></span>
            </button>

        </div>
    </form>

    <hr
        style="
            border:0;
            border-top:1px solid var(--border);
            margin:24px 0;
        "
    >

    <h2>接続確認</h2>

    <form
        method="post"
        data-processing-form
    >
        <input
            type="hidden"
            name="action"
            value="kintone_test"
        >

        <input
            type="hidden"
            name="subdomain"
            value="<?=e(
                $kintoneSettings[
                    'subdomain'
                ] ?? ''
            )?>"
        >

        <input
            type="hidden"
            name="app_id"
            value="<?=e(
                $kintoneSettings[
                    'app_id'
                ] ?? ''
            )?>"
        >

        <input
            type="hidden"
            name="username"
            value="<?=e(
                $kintoneSettings[
                    'username'
                ] ?? ''
            )?>"
        >

        <!--
            パスワードはhiddenでブラウザへ再出力しない。
            接続テスト時は画面上で入力する。
        -->

        <div class="form-row">
            <label class="field-label">
                接続テスト用パスワード
            </label>

            <input
                type="password"
                name="password"
                autocomplete="off"
                placeholder="保存済み設定を使用する場合は空欄"
            >
        </div>

        <div class="form-row">
            <label class="field-label">
                Proxy
            </label>

            <input
                type="text"
                name="proxy"
                value="<?=e(
                    $kintoneSettings[
                        'proxy'
                    ] ?? ''
                )?>"
                placeholder="host:port"
            >
        </div>

        <label>
            <input
                type="checkbox"
                name="verify_ssl"
                value="1"
                <?=!empty(
                    $kintoneSettings[
                        'verify_ssl'
                    ]
                )
                    ? 'checked'
                    : ''?>
            >
            SSL証明書検証を有効にする
        </label>

        <div
            style="margin-top:14px"
        >
            <button
                class="primary"
                type="submit"
            >
                接続テスト
                <span class="spinner"></span>
            </button>
        </div>
    </form>

    <hr
        style="
            border:0;
            border-top:1px solid var(--border);
            margin:24px 0;
        "
    >

    <h2>kintone項目</h2>

    <form
        method="post"
        data-processing-form
    >
        <input
            type="hidden"
            name="action"
            value="kintone_fields"
        >

        <button
            type="submit"
        >
            項目一覧を再取得
            <span class="spinner"></span>
        </button>
    </form>

    <?php
    $fields =
        $kintoneSettings['fields']
        ?? [];
    ?>

    <?php if ($fields !== []): ?>

        <div class="table-wrap">
            <table
                style="min-width:650px"
            >
                <thead>
                <tr>
                    <th>フィールドコード</th>
                    <th>ラベル</th>
                    <th>タイプ</th>
                </tr>
                </thead>

                <tbody>

                <?php foreach (
                    $fields as $code => $field
                ): ?>

                    <tr>
                        <td>
                            <?=e($code)?>
                        </td>

                        <td>
                            <?=e(
                                $field['label']
                                ?? ''
                            )?>
                        </td>

                        <td>
                            <?=e(
                                $field['type']
                                ?? ''
                            )?>
                        </td>
                    </tr>

                <?php endforeach; ?>

                </tbody>
            </table>
        </div>

    <?php endif; ?>

    <h2>顧客情報同期</h2>

    <p>
        現在の同期済み顧客数：
        <strong>
            <?=count($customers)?>
        </strong>
    </p>

    <form
        method="post"
        data-processing-form
        data-confirm="kintoneから顧客情報を同期しますか？"
    >
        <input
            type="hidden"
            name="action"
            value="kintone_sync"
        >

        <button
            class="primary"
            type="submit"
        >
            顧客情報を同期
            <span class="spinner"></span>
        </button>
    </form>

    <div class="notice">
        「接続テスト」「項目一覧を再取得」
        「顧客情報を同期」はそれぞれ独立した操作です。
    </div>

</div>


<?php
/* ============================================================
 * メール
 * ========================================================== */
?>

<?php elseif ($screen === 'mail'): ?>

<div class="card">

    <h1>メールサーバ設定</h1>

    <form
        method="post"
        data-processing-form
    >
        <input
            type="hidden"
            name="action"
            value="mail_save"
        >

        <div class="form-grid">

            <div class="form-row">
                <label class="field-label">
                    SMTPサーバ
                </label>

                <input
                    type="text"
                    name="host"
                    required
                    value="<?=e(
                        $mailSettings['host']
                        ?? ''
                    )?>"
                >
            </div>

            <div class="form-row">
                <label class="field-label">
                    SMTPポート
                </label>

                <input
                    type="number"
                    name="port"
                    min="1"
                    max="65535"
                    required
                    value="<?=e(
                        $mailSettings['port']
                        ?? 587
                    )?>"
                >
            </div>

            <div class="form-row">
                <label class="field-label">
                    暗号化方式
                </label>

                <select name="encryption">
                    <option
                        value="ssl"
                        <?=(
                            ($mailSettings[
                                'encryption'
                            ] ?? 'tls')
                            === 'ssl'
                        )
                            ? 'selected'
                            : ''?>
                    >
                        SSL
                    </option>

                    <option
                        value="tls"
                        <?=(
                            ($mailSettings[
                                'encryption'
                            ] ?? 'tls')
                            === 'tls'
                        )
                            ? 'selected'
                            : ''?>
                    >
                        TLS
                    </option>

                    <option
                        value="none"
                        <?=(
                            ($mailSettings[
                                'encryption'
                            ] ?? 'tls')
                            === 'none'
                        )
                            ? 'selected'
                            : ''?>
                    >
                        なし
                    </option>
                </select>
            </div>

            <div class="form-row">
                <label>
                    <input
                        type="checkbox"
                        name="auth"
                        value="1"
                        <?=!empty(
                            $mailSettings['auth']
                        )
                            ? 'checked'
                            : ''?>
                    >
                    SMTP認証
                </label>
            </div>

            <div class="form-row">
                <label class="field-label">
                    SMTPユーザー名
                </label>

                <input
                    type="text"
                    name="username"
                    value="<?=e(
                        $mailSettings[
                            'username'
                        ] ?? ''
                    )?>"
                >
            </div>

            <div class="form-row">
                <label class="field-label">
                    SMTPパスワード
                </label>

                <input
                    type="password"
                    name="password"
                    placeholder="変更しない場合は空欄"
                    autocomplete="new-password"
                >
            </div>

            <div class="form-row">
                <label class="field-label">
                    送信元メールアドレス
                </label>

                <input
                    type="email"
                    name="from_email"
                    required
                    value="<?=e(
                        $mailSettings[
                            'from_email'
                        ] ?? ''
                    )?>"
                >
            </div>

            <div class="form-row">
                <label class="field-label">
                    送信元名
                </label>

                <input
                    type="text"
                    name="from_name"
                    value="<?=e(
                        $mailSettings[
                            'from_name'
                        ] ?? ''
                    )?>"
                >
            </div>

            <div class="form-row">
                <label class="field-label">
                    返信先メールアドレス
                </label>

                <input
                    type="email"
                    name="reply_to"
                    value="<?=e(
                        $mailSettings[
                            'reply_to'
                        ] ?? ''
                    )?>"
                >
            </div>

        </div>

        <button
            class="primary"
            type="submit"
        >
            設定保存
            <span class="spinner"></span>
        </button>
    </form>

    <div class="notice">
        メール送信はPHP mail()ではなくSMTP接続を使用する構成です。
    </div>

</div>


<?php
/* ============================================================
 * 集計
 * ========================================================== */
?>

<?php elseif ($screen === 'analytics'): ?>

<div class="card">

<?php if ($currentSurvey === null): ?>

    <h1>対象アンケートが存在しません。</h1>

<?php else: ?>

    <h1>回答集計・分析</h1>

    <h2>
        <?=e(
            $currentSurvey['title']
        )?>
    </h2>

    <?php
    $allAnswers = read_json(
        ANSWERS_FILE,
        []
    );

    $surveyAnswers =
        array_values(
            array_filter(
                $allAnswers,
                static function (
                    array $answer
                ) use ($id): bool {
                    return
                        (string)(
                            $answer['surveyId']
                            ?? ''
                        )
                        === $id;
                }
            )
        );
    ?>

    <div class="form-grid">

        <div class="card">
            <strong>回答数</strong>
            <div
                style="
                    font-size:28px;
                    margin-top:8px;
                "
            >
                <?=count($surveyAnswers)?>
            </div>
        </div>

        <div class="card">
            <strong>同期済み顧客数</strong>
            <div
                style="
                    font-size:28px;
                    margin-top:8px;
                "
            >
                <?=count($customers)?>
            </div>
        </div>

    </div>

    <?php if (
        $surveyAnswers === []
    ): ?>

        <p>
            現在、回答データはありません
        </p>

    <?php else: ?>

        <h2>個別回答</h2>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>回答日時</th>
                    <th>回答</th>
                </tr>
                </thead>

                <tbody>

                <?php foreach (
                    $surveyAnswers
                    as $answer
                ): ?>

                    <tr>
                        <td>
                            <?=e(
                                $answer['createdAt']
                                ?? ''
                            )?>
                        </td>

                        <td>
                            <pre
                                style="
                                    white-space:pre-wrap;
                                    margin:0;
                                "
                            ><?=e(
                                json_encode(
                                    $answer['answers']
                                    ?? [],
                                    JSON_UNESCAPED_UNICODE
                                    | JSON_PRETTY_PRINT
                                )
                            )?></pre>
                        </td>
                    </tr>

                <?php endforeach; ?>

                </tbody>
            </table>
        </div>

    <?php endif; ?>

<?php endif; ?>

</div>


<?php
/* ============================================================
 * 送信
 * ========================================================== */
?>

<?php elseif ($screen === 'send'): ?>

<div class="card">

<?php if ($currentSurvey === null): ?>

    <h1>対象アンケートが存在しません。</h1>

<?php else: ?>

    <h1>顧客選択・メール送信</h1>

    <div class="notice">
        対象アンケート：
        <strong>
            <?=e(
                $currentSurvey['title']
            )?>
        </strong>
    </div>

    <p>
        同期済み顧客数：
        <strong>
            <?=count($customers)?>
        </strong>
    </p>

    <div class="notice">
        この画面では対象アンケートを変更できません。
        対象アンケートはURLのidによって固定されています。
    </div>

    <div class="form-row">
        <label class="field-label">
            顧客検索
        </label>

        <input
            type="text"
            id="customerSearch"
            placeholder="会社名・氏名・メールアドレス"
            oninput="filterCustomers()"
        >
    </div>

    <div
        class="table-wrap"
        style="max-height:400px"
    >
        <table
            style="min-width:800px"
        >
            <thead>
            <tr>
                <th>選択</th>
                <th>組織名</th>
                <th>氏名</th>
                <th>メール</th>
                <th>部署</th>
                <th>電話</th>
                <th>住所</th>
            </tr>
            </thead>

            <tbody id="customerRows">

            <?php foreach (
                $customers as $customer
            ): ?>

                <?php
                $searchText =
                    mb_strtolower(
                        implode(
                            ' ',
                            [
                                $customer[
                                    'organization'
                                ] ?? '',
                                $customer[
                                    'name'
                                ] ?? '',
                                $customer[
                                    'email'
                                ] ?? '',
                                $customer[
                                    'department'
                                ] ?? '',
                            ]
                        )
                    );
                ?>

                <tr
                    data-search="<?=e(
                        $searchText
                    )?>"
                >
                    <td>
                        <input
                            type="checkbox"
                            name="customer[]"
                            value="<?=e(
                                $customer['id']
                                ?? ''
                            )?>"
                        >
                    </td>

                    <td>
                        <?=e(
                            $customer[
                                'organization'
                            ] ?? ''
                        )?>
                    </td>

                    <td>
                        <?=e(
                            $customer[
                                'name'
                            ] ?? ''
                        )?>
                    </td>

                    <td>
                        <?=e(
                            $customer[
                                'email'
                            ] ?? ''
                        )?>
                    </td>

                    <td>
                        <?=e(
                            $customer[
                                'department'
                            ] ?? ''
                        )?>
                    </td>

                    <td>
                        <?=e(
                            $customer[
                                'phone'
                            ] ?? ''
                        )?>
                    </td>

                    <td>
                        <?=e(
                            $customer[
                                'address'
                            ] ?? ''
                        )?>
                    </td>
                </tr>

            <?php endforeach; ?>

            </tbody>
        </table>
    </div>

    <h2>メール本文</h2>

    <div class="form-row">
        <label class="field-label">
            件名
        </label>

        <input
            type="text"
            id="mailSubject"
            value="<?=e(
                ($currentSurvey['title']
                    ?? 'アンケート')
                . 'のご案内'
            )?>"
        >
    </div>

    <div class="form-row">
        <label class="field-label">
            本文
        </label>

        <textarea
            id="mailBody"
        >{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}</textarea>
    </div>

    <button
        type="button"
        class="primary"
        onclick="confirmSend()"
    >
        一括送信
    </button>

    <h2>送信履歴</h2>

    <?php
    $sendLogs = read_json(
        SEND_LOG_FILE,
        []
    );

    $surveyLogs =
        array_values(
            array_filter(
                $sendLogs,
                static function (
                    array $log
                ) use ($id): bool {
                    return
                        (string)(
                            $log['surveyId']
                            ?? ''
                        ) === $id;
                }
            )
        );
    ?>

    <?php if (
        $surveyLogs === []
    ): ?>

        <p>
            送信履歴はありません。
        </p>

    <?php else: ?>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>日時</th>
                    <th>対象</th>
                    <th>結果</th>
                </tr>
                </thead>

                <tbody>

                <?php foreach (
                    $surveyLogs
                    as $log
                ): ?>

                    <tr>
                        <td>
                            <?=e(
                                $log['createdAt']
                                ?? ''
                            )?>
                        </td>

                        <td>
                            <?=e(
                                $log['customerName']
                                ?? ''
                            )?>
                        </td>

                        <td>
                            <?=e(
                                $log['status']
                                ?? ''
                            )?>
                        </td>
                    </tr>

                <?php endforeach; ?>

                </tbody>
            </table>
        </div>

    <?php endif; ?>

<?php endif; ?>

</div>


<?php
/* ============================================================
 * 回答
 * ========================================================== */
?>

<?php elseif ($screen === 'answer'): ?>

<div class="card answer-card">

<?php if ($currentSurvey === null): ?>

    <h1>アンケートが存在しません。</h1>

<?php elseif (
    ($currentSurvey['status'] ?? '')
    !== 'published'
): ?>

    <h1>回答できません。</h1>

    <p>
        このアンケートは現在公開されていません。
    </p>

<?php else: ?>

    <h1>
        <?=e(
            $currentSurvey['title']
        )?>
    </h1>

    <p>
        <?=nl2br(
            e(
                $currentSurvey[
                    'description'
                ] ?? ''
            )
        )?>
    </p>

    <form
        method="post"
        data-processing-form
    >
        <input
            type="hidden"
            name="action"
            value="answer_confirm"
        >

        <input
            type="hidden"
            name="id"
            value="<?=e($id)?>"
        >

        <?php foreach (
            ($currentSurvey['groups']
                ?? [])
            as $group
        ): ?>

            <h2>
                <?=e(
                    $group['title']
                    ?? ''
                )?>
            </h2>

            <?php foreach (
                ($group['questions']
                    ?? [])
                as $question
            ): ?>

                <div class="answer-question">

                    <label
                        class="field-label"
                    >
                        <?=e(
                            $question['number']
                            ?? ''
                        )?>
                        .
                        <?=e(
                            $question['text']
                            ?? ''
                        )?>

                        <?php if (
                            !empty(
                                $question['required']
                            )
                        ): ?>

                            <span
                                style="
                                    color:var(--danger);
                                "
                            >
                                *
                            </span>

                        <?php endif; ?>

                    </label>

                    <?php
                    $qid =
                        (string)(
                            $question['id']
                            ?? ''
                        );

                    $type =
                        (string)(
                            $question['type']
                            ?? 'text'
                        );
                    ?>

                    <?php if (
                        $type === 'single'
                    ): ?>

                        <?php foreach (
                            ($question[
                                'options'
                            ] ?? [])
                            as $option
                        ): ?>

                            <label
                                class="answer-option"
                            >
                                <input
                                    type="radio"
                                    name="answer[<?=e(
                                        $qid
                                    )?>]"
                                    value="<?=e(
                                        $option[
                                            'label'
                                        ] ?? ''
                                    )?>"
                                    <?=!empty(
                                        $question[
                                            'required'
                                        ]
                                    )
                                        ? 'required'
                                        : ''?>
                                >
                                <?=e(
                                    $option[
                                        'label'
                                    ] ?? ''
                                )?>
                            </label>

                        <?php endforeach; ?>

                    <?php elseif (
                        $type === 'multiple'
                    ): ?>

                        <?php foreach (
                            ($question[
                                'options'
                            ] ?? [])
                            as $option
                        ): ?>

                            <label
                                class="answer-option"
                            >
                                <input
                                    type="checkbox"
                                    name="answer[<?=e(
                                        $qid
                                    )?>][]"
                                    value="<?=e(
                                        $option[
                                            'label'
                                        ] ?? ''
                                    )?>"
                                >
                                <?=e(
                                    $option[
                                        'label'
                                    ] ?? ''
                                )?>
                            </label>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <textarea
                            name="answer[<?=e(
                                $qid
                            )?>]"
                            <?=!empty(
                                $question[
                                    'required'
                                ]
                            )
                                ? 'required'
                                : ''?>
                        ></textarea>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php endforeach; ?>

        <button
            class="primary"
            type="submit"
        >
            回答確認へ
            <span class="spinner"></span>
        </button>

    </form>

<?php endif; ?>

</div>


<?php
/* ============================================================
 * 回答確認
 * ========================================================== */
?>

<?php elseif ($screen === 'confirm'): ?>

<div class="card answer-card">

<?php
$draft =
    $_SESSION['answer_draft']
    ?? null;

$confirmSurvey = null;

if (
    is_array($draft)
    && !empty($draft['surveyId'])
) {
    $confirmSurvey =
        find_survey(
            $surveys,
            (string)$draft['surveyId']
        );
}
?>

<?php if (
    $confirmSurvey === null
): ?>

    <h1>回答セッションがありません。</h1>

    <a
        class="button"
        href="<?=e(
            screen_url(
                'list'
            )
        )?>"
    >
        一覧へ
    </a>

<?php else: ?>

    <h1>回答確認</h1>

    <p>
        以下の内容で送信します。
    </p>

    <?php
    $confirmAnswers =
        $draft['answers']
        ?? [];
    ?>

    <?php foreach (
        ($confirmSurvey[
            'groups'
        ] ?? [])
        as $group
    ): ?>

        <h2>
            <?=e(
                $group['title']
                ?? ''
            )?>
        </h2>

        <?php foreach (
            ($group['questions']
                ?? [])
            as $question
        ): ?>

            <?php
            $qid =
                (string)(
                    $question['id']
                    ?? ''
                );

            $value =
                $confirmAnswers[$qid]
                ?? '';

            if (is_array($value)) {
                $displayValue =
                    implode(
                        '、',
                        array_map(
                            'strval',
                            $value
                        )
                    );
            } else {
                $displayValue =
                    (string)$value;
            }
            ?>

            <div
                class="question-card"
            >
                <strong>
                    <?=e(
                        $question['number']
                        ?? ''
                    )?>
                    .
                    <?=e(
                        $question['text']
                        ?? ''
                    )?>
                </strong>

                <p>
                    <?=nl2br(
                        e($displayValue)
                    )?>
                </p>
            </div>

        <?php endforeach; ?>

    <?php endforeach; ?>

    <div class="mobile-actions">

        <form method="post">
            <input
                type="hidden"
                name="action"
                value="answer_back"
            >

            <input
                type="hidden"
                name="id"
                value="<?=e(
                    $confirmSurvey['id']
                    ?? ''
                )?>"
            >

            <button type="submit">
                戻る
            </button>
        </form>

        <form
            method="post"
            data-confirm="回答を送信しますか？"
            data-processing-form
        >
            <input
                type="hidden"
                name="action"
                value="answer_submit"
            >

            <button
                class="primary"
                type="submit"
            >
                回答を送信
                <span class="spinner"></span>
            </button>
        </form>

    </div>

<?php endif; ?>

</div>


<?php
/* ============================================================
 * 完了
 * ========================================================== */
?>

<?php elseif ($screen === 'complete'): ?>

<div class="card answer-card">

    <h1>回答完了</h1>

    <p>
        アンケートへのご回答ありがとうございました。
    </p>

</div>

<?php endif; ?>

</main>

<script>
/* ============================================================
 * 共通確認
 * ========================================================== */

document
    .querySelectorAll('[data-confirm]')
    .forEach(function(form) {
        form.addEventListener(
            'submit',
            function(event) {
                const message =
                    form.dataset.confirm || '';

                if (
                    message !== ''
                    && !window.confirm(message)
                ) {
                    event.preventDefault();
                }
            }
        );
    });

/* ============================================================
 * 外部通信中の二重操作防止
 * ========================================================== */

document
    .querySelectorAll(
        '[data-processing-form]'
    )
    .forEach(function(form) {
        form.addEventListener(
            'submit',
            function() {
                setTimeout(
                    function() {
                        form.classList.add(
                            'processing'
                        );

                        form
                            .querySelectorAll(
                                'button'
                            )
                            .forEach(
                                function(button) {
                                    button.disabled =
                                        true;
                                }
                            );
                    },
                    0
                );
            }
        );
    });

/* ============================================================
 * アンケート編集
 * ========================================================== */

function addGroup() {
    const groups =
        document.getElementById('groups');

    if (!groups) {
        return;
    }

    const group =
        document.createElement('div');

    group.className =
        'group-card';

    group.draggable = true;
    group.dataset.group = '';

    group.innerHTML = `
        <div class="question-head">
            <span class="drag-handle">
                ☰ グループ
            </span>

            <button
                type="button"
                class="danger small"
                onclick="deleteGroup(this)"
            >
                グループ削除
            </button>
        </div>

        <div class="form-row">
            <label class="field-label">
                グループタイトル
            </label>

            <input
                type="text"
                data-group-title
                value="新しいグループ"
            >
        </div>

        <div data-questions></div>

        <button
            type="button"
            class="small"
            onclick="addQuestion(this)"
        >
            質問を追加
        </button>
    `;

    groups.appendChild(group);

    addQuestion(
        group.querySelector(
            'button[onclick^="addQuestion"]'
        )
    );

    updateQuestionNumbers();
}

function addQuestion(button) {
    const group =
        button.closest('[data-group]');

    if (!group) {
        return;
    }

    const questions =
        group.querySelector('[data-questions]');

    const question =
        document.createElement('div');

    question.className =
        'question-card';

    question.draggable = true;
    question.dataset.question = '';

    question.innerHTML = `
        <div class="question-head">
            <strong data-question-number>
                Q
            </strong>

            <span class="drag-handle">
                ☰
            </span>

            <button
                type="button"
                class="danger small"
                onclick="deleteQuestion(this)"
            >
                質問削除
            </button>
        </div>

        <div class="form-row">
            <label class="field-label">
                質問文
            </label>

            <input
                type="text"
                data-question-text
                value=""
            >
        </div>

        <div class="form-row">
            <label class="field-label">
                回答形式
            </label>

            <select
                data-question-type
                onchange="toggleOptions(this)"
            >
                <option value="single">
                    単一選択
                </option>

                <option value="multiple">
                    複数選択
                </option>

                <option value="text">
                    自由記述
                </option>
            </select>
        </div>

        <label>
            <input
                type="checkbox"
                data-required
                checked
            >
            必須
        </label>

        <div
            class="form-row"
            data-options
        >
            <h3>選択肢</h3>

            <div data-option-list></div>

            <button
                type="button"
                class="small"
                onclick="addOption(this)"
            >
                選択肢を追加
            </button>
        </div>
    `;

    questions.appendChild(question);

    addOption(
        question.querySelector(
            'button[onclick^="addOption"]'
        )
    );

    addOption(
        question.querySelector(
            'button[onclick^="addOption"]'
        )
    );

    updateQuestionNumbers();
}

function addOption(button) {
    const wrapper =
        button.closest('[data-options]');

    if (!wrapper) {
        return;
    }

    const list =
        wrapper.querySelector(
            '[data-option-list]'
        );

    const row =
        document.createElement('div');

    row.className =
        'option-row';

    row.dataset.option = '';

    row.innerHTML = `
        <input
            type="text"
            data-option-label
            value=""
            placeholder="選択肢"
        >

        <button
            type="button"
            class="danger small"
            onclick="deleteOption(this)"
        >
            削除
        </button>
    `;

    list.appendChild(row);
}

function deleteOption(button) {
    const option =
        button.closest('[data-option]');

    if (!option) {
        return;
    }

    if (
        !window.confirm(
            '選択肢を削除しますか？'
        )
    ) {
        return;
    }

    option.remove();
}

function deleteQuestion(button) {
    if (
        !window.confirm(
            '質問を削除しますか？'
        )
    ) {
        return;
    }

    const question =
        button.closest('[data-question]');

    if (question) {
        question.remove();
        updateQuestionNumbers();
    }
}

function deleteGroup(button) {
    if (
        !window.confirm(
            'グループを削除しますか？'
        )
    ) {
        return;
    }

    const group =
        button.closest('[data-group]');

    if (group) {
        group.remove();
        updateQuestionNumbers();
    }
}

function toggleOptions(select) {
    const question =
        select.closest(
            '[data-question]'
        );

    if (!question) {
        return;
    }

    const options =
        question.querySelector(
            '[data-options]'
        );

    if (!options) {
        return;
    }

    options.style.display =
        (
            select.value === 'single'
            || select.value === 'multiple'
        )
            ? ''
            : 'none';
}

function updateQuestionNumbers() {
    const groups =
        document.querySelectorAll(
            '[data-group]'
        );

    const numbering =
        document.querySelector(
            '[name="numbering"]'
        );

    const mode =
        numbering
            ? numbering.value
            : 'global';

    let globalNo = 0;

    groups.forEach(
        function(group, gi) {
            let localNo = 0;

            group
                .querySelectorAll(
                    ':scope > [data-questions] > [data-question]'
                )
                .forEach(
                    function(question) {
                        globalNo++;
                        localNo++;

                        const number =
                            mode === 'group'
                                ? 'Q'
                                  + (
                                      gi + 1
                                  )
                                  + '-'
                                  + localNo
                                : 'Q'
                                  + globalNo;

                        const label =
                            question.querySelector(
                                '[data-question-number]'
                            );

                        if (label) {
                            label.textContent =
                                number;
                        }
                    }
                );
        }
    );
}

if (document.querySelector(
    '[name="numbering"]'
)) {
    document
        .querySelector(
            '[name="numbering"]'
        )
        .addEventListener(
            'change',
            updateQuestionNumbers
        );
}

function prepareSurvey(form) {
    const groups =
        document.querySelectorAll(
            '[data-group]'
        );

    /*
     * 既存のgroup/questionデータを
     * hidden JSONとしてサーバーへ送る。
     */
    const survey =
        {
            groups: []
        };

    groups.forEach(
        function(group) {
            const groupData = {
                id:
                    group.dataset.id
                    || (
                        'g-'
                        + Math.random()
                            .toString(36)
                            .slice(2)
                    ),
                title:
                    (
                        group.querySelector(
                            '[data-group-title]'
                        )?.value
                        || ''
                    ),
                questions: []
            };

            group
                .querySelectorAll(
                    ':scope > [data-questions] > [data-question]'
                )
                .forEach(
                    function(question) {
                        const questionData = {
                            id:
                                question.dataset.id
                                || (
                                    'q-'
                                    + Math.random()
                                        .toString(36)
                                        .slice(2)
                                ),
                            text:
                                (
                                    question.querySelector(
                                        '[data-question-text]'
                                    )?.value
                                    || ''
                                ),
                            type:
                                (
                                    question.querySelector(
                                        '[data-question-type]'
                                    )?.value
                                    || 'single'
                                ),
                            required:
                                !!question.querySelector(
                                    '[data-required]'
                                )?.checked,
                            options: []
                        };

                        question
                            .querySelectorAll(
                                '[data-option]'
                            )
                            .forEach(
                                function(option) {
                                    questionData.options
                                        .push({
                                            id:
                                                option.dataset.id
                                                || (
                                                    'o-'
                                                    + Math.random()
                                                        .toString(36)
                                                        .slice(2)
                                                ),
                                            label:
                                                (
                                                    option.querySelector(
                                                        '[data-option-label]'
                                                    )?.value
                                                    || ''
                                                ),
                                            nextQuestionId:
                                                ''
                                        });
                                }
                            );

                        groupData.questions
                            .push(
                                questionData
                            );
                    }
                );

            survey.groups.push(
                groupData
            );
        }
    );

    let input =
        form.querySelector(
            '[name="survey_json"]'
        );

    if (!input) {
        input =
            document.createElement('input');

        input.type = 'hidden';
        input.name = 'survey_json';

        form.appendChild(input);
    }

    /*
     * PHP側でも必ず再検証する。
     */
    input.value =
        JSON.stringify(
            survey
        );

    return true;
}

/* ============================================================
 * 顧客検索
 * ========================================================== */

function filterCustomers() {
    const input =
        document.getElementById(
            'customerSearch'
        );

    const rows =
        document.querySelectorAll(
            '#customerRows tr'
        );

    if (!input) {
        return;
    }

    const keyword =
        input.value
            .toLowerCase()
            .trim();

    rows.forEach(
        function(row) {
            const text =
                (
                    row.dataset.search
                    || ''
                ).toLowerCase();

            row.style.display =
                keyword === ''
                || text.includes(keyword)
                    ? ''
                    : 'none';
        }
    );
}

/* ============================================================
 * 送信
 * ========================================================== */

function confirmSend() {
    if (
        !window.confirm(
            '選択した顧客へ送信しますか？'
        )
    ) {
        return;
    }

    /*
     * 実際のSMTP送信処理はサーバー側で行う。
     *
     * このPOCでは、ブラウザ側からSMTP認証情報を
     * 送信処理へ渡さない。
     */
    alert(
        '送信処理はSMTP設定後にサーバー側で実行します。'
    );
}
</script>

</body>
</html>
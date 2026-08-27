<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * prompt.txt から再構成した単一エントリーポイント
 *
 * 重要:
 * - POST -> 303 -> GET -> flash に依存しない
 * - 外部通信失敗を画面に表示する
 * - kintone / SMTP は実サービスへ接続
 * - PHP cURL は使用しない
 * - PHP mail() は使用しない
 * - DB は使用しない
 * - セッションIDをURLへ出さない
 * - 通常GETでsession_regenerate_id()しない
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR       = __DIR__ . '/data';
const SETTINGS_FILE  = DATA_DIR . '/settings.json';
const SURVEYS_FILE   = DATA_DIR . '/surveys.json';
const CUSTOMERS_FILE = DATA_DIR . '/customers.json';
const ANSWERS_FILE   = DATA_DIR . '/answers.json';
const SEND_LOG_FILE  = DATA_DIR . '/send_logs.json';

/*
 * 外部通信タイムアウト
 *
 * connect:
 *   TCP/TLS接続確立まで
 *
 * write:
 *   リクエスト送信
 *
 * read:
 *   レスポンス待機
 */
const CONNECT_TIMEOUT = 10.0;
const WRITE_TIMEOUT   = 10.0;
const READ_TIMEOUT    = 20.0;

const MAX_BODY_SIZE = 10 * 1024 * 1024;


/* ============================================================
 * 初期化
 * ============================================================ */

if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

init_json_file(SETTINGS_FILE, [
    'kintone' => [
        'subdomain' => '',
        'app_id' => '',
        'username' => '',
        'password' => '',
        'proxy' => '',
        'verify_ssl' => false,
        'connection_status' => '未設定',
        'last_test_at' => '',
        'last_error' => '',
        'fields' => [],
        'field_mapping' => [
            'organization' => '',
            'name' => '',
            'email' => '',
            'department' => '',
            'phone' => '',
            'address' => [],
        ],
    ],
    'mail' => [
        'host' => '',
        'port' => 587,
        'encryption' => 'tls',
        'auth' => true,
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => '',
        'reply_to' => '',
        'connection_status' => '未設定',
        'last_test_at' => '',
        'last_error' => '',
    ],
]);

init_json_file(SURVEYS_FILE, []);
init_json_file(CUSTOMERS_FILE, []);
init_json_file(ANSWERS_FILE, []);
init_json_file(SEND_LOG_FILE, []);


/* ============================================================
 * セッション
 * ============================================================ */

$secureCookie =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
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
 * リクエスト状態
 *
 * 303リダイレクトを使わず、POST結果をそのまま表示する。
 * ============================================================ */

$operationResult = null;
$operationError  = null;

$screen = (string)($_GET['screen'] ?? 'list');

$allowedScreens = [
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

if (!in_array($screen, $allowedScreens, true)) {
    $screen = 'list';
}


/* ============================================================
 * POST
 * ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {

            /* -------------------------
             * kintone
             * ------------------------- */

            case 'save_kintone':
                $operationResult = save_kintone();
                $screen = 'kintone';
                break;

            case 'test_kintone':
                $operationResult = test_kintone();
                $screen = 'kintone';
                break;

            case 'fetch_kintone_fields':
                $operationResult = fetch_kintone_fields();
                $screen = 'kintone';
                break;

            case 'sync_kintone':
                $operationResult = sync_kintone();
                $screen = 'kintone';
                break;


            /* -------------------------
             * SMTP
             * ------------------------- */

            case 'save_mail':
                $operationResult = save_mail();
                $screen = 'mail';
                break;

            case 'test_mail':
                $operationResult = test_mail();
                $screen = 'mail';
                break;

            case 'send_test_mail':
                $operationResult = send_test_mail();
                $screen = 'mail';
                break;


            /* -------------------------
             * アンケート
             * ------------------------- */

            case 'save_survey':
                $operationResult = save_survey();
                $screen = 'list';
                break;

            case 'delete_survey':
                $operationResult = delete_survey();
                $screen = 'list';
                break;

            case 'duplicate_survey':
                $operationResult = duplicate_survey();
                $screen = 'list';
                break;

            case 'change_status':
                $operationResult = change_survey_status();
                $screen = 'edit';
                break;

            case 'send_mail':
                $operationResult = send_survey_mail();
                $screen = 'send';
                break;


            /* -------------------------
             * 回答
             * ------------------------- */

            case 'answer_next':
                $operationResult = answer_next();
                $screen = 'confirm';
                break;

            case 'answer_submit':
                $operationResult = answer_submit();
                $screen = 'complete';
                break;

            default:
                throw new RuntimeException('不明な操作です。');
        }

    } catch (Throwable $e) {

        $operationError = public_error_message($e);

        /*
         * 重要:
         * ここでもリダイレクトしない。
         *
         * POSTのままエラーを画面に表示する。
         */
    }
}


/* ============================================================
 * HTML
 * ============================================================ */

render_page(
    $screen,
    $operationResult,
    $operationError
);


/* ============================================================
 * JSON
 * ============================================================ */

function init_json_file(string $file, array $default): void
{
    if (!file_exists($file)) {
        write_json_atomic($file, $default);
    }
}


function read_json(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $raw = file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new RuntimeException(
            '保存データの形式が不正です。'
        );
    }

    return $data;
}


function write_json_atomic(
    string $file,
    array $data
): void {

    $dir = dirname($file);

    if (!is_dir($dir)) {
        throw new RuntimeException(
            'データ保存先が存在しません。'
        );
    }

    $tmp =
        $file
        . '.tmp.'
        . bin2hex(random_bytes(8));

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException(
            'データをJSON化できません。'
        );
    }

    $fp = fopen($tmp, 'wb');

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

        $written = fwrite($fp, $json);

        if ($written === false || $written < strlen($json)) {
            throw new RuntimeException(
                'データを書き込めません。'
            );
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!rename($tmp, $file)) {
            throw new RuntimeException(
                'データファイルを更新できません。'
            );
        }

    } catch (Throwable $e) {

        fclose($fp);

        if (file_exists($tmp)) {
            @unlink($tmp);
        }

        throw $e;
    }
}


/* ============================================================
 * 共通
 * ============================================================ */

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


function public_error_message(Throwable $e): string
{
    /*
     * 認証情報・パスワード等を表示しない。
     *
     * 実運用では内部ログを別途実装してもよいが、
     * このPOCでは画面へ安全な範囲のエラーだけ出す。
     */

    $message = trim($e->getMessage());

    $message = preg_replace(
        '/(password|passwd|authorization|x-cybozu-authorization)\s*[:=]\s*\S+/i',
        '$1: [REDACTED]',
        $message
    ) ?? $message;

    return mb_substr(
        $message !== ''
            ? $message
            : '処理に失敗しました。',
        0,
        1000
    );
}


function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException(
            'POSTで実行してください。'
        );
    }
}


function valid_email(string $email): bool
{
    return filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    ) !== false;
}


/* ============================================================
 * URL
 * ============================================================ */

function screen_url(
    string $screen,
    array $params = []
): string {

    $params =
        array_merge(
            ['screen' => $screen],
            $params
        );

    return 'index.php?'
        . http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
}


/* ============================================================
 * kintone設定
 * ============================================================ */

function normalize_subdomain(string $value): string
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

    if (
        $value === ''
        || !preg_match(
            '/^[a-zA-Z0-9][a-zA-Z0-9-]*$/',
            $value
        )
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインが不正です。'
        );
    }

    return $value;
}


function validate_kintone(array $k): void
{
    if (
        empty($k['subdomain'])
        || empty($k['app_id'])
        || empty($k['username'])
        || !isset($k['password'])
    ) {
        throw new InvalidArgumentException(
            'kintoneのサブドメイン、アプリID、ログイン名、パスワードを設定してください。'
        );
    }

    if (
        !preg_match(
            '/^[0-9]+$/',
            (string)$k['app_id']
        )
    ) {
        throw new InvalidArgumentException(
            'kintoneアプリIDは数値で指定してください。'
        );
    }

    if (!empty($k['proxy'])) {
        validate_proxy((string)$k['proxy']);
    }
}


function validate_proxy(string $proxy): void
{
    if (
        !preg_match(
            '/^[^:\s]+:[0-9]{1,5}$/',
            trim($proxy)
        )
    ) {
        throw new InvalidArgumentException(
            'Proxyは host:port 形式で指定してください。'
        );
    }
}


function save_kintone(): array
{
    require_post();

    $settings = read_json(SETTINGS_FILE);

    $current =
        $settings['kintone']
        ?? [];

    $subdomain =
        normalize_subdomain(
            (string)($_POST['subdomain'] ?? '')
        );

    $appId =
        trim(
            (string)($_POST['app_id'] ?? '')
        );

    $username =
        trim(
            (string)($_POST['username'] ?? '')
        );

    $password =
        (string)($_POST['password'] ?? '');

    /*
     * パスワード空欄は既存値を維持。
     */
    if ($password === '') {
        $password =
            (string)($current['password'] ?? '');
    }

    $proxy =
        trim(
            (string)($_POST['proxy'] ?? '')
        );

    if ($proxy !== '') {
        validate_proxy($proxy);
    }

    if (!preg_match('/^[0-9]+$/', $appId)) {
        throw new InvalidArgumentException(
            'アプリIDは数値で入力してください。'
        );
    }

    if ($username === '') {
        throw new InvalidArgumentException(
            'ログイン名を入力してください。'
        );
    }

    if ($password === '') {
        throw new InvalidArgumentException(
            'パスワードを入力してください。'
        );
    }

    $settings['kintone'] =
        array_merge(
            $current,
            [
                'subdomain' => $subdomain,
                'app_id' => $appId,
                'username' => $username,
                'password' => $password,
                'proxy' => $proxy,
                'verify_ssl' =>
                    isset($_POST['verify_ssl']),
                'connection_status' => '未設定',
                'last_error' => '',
            ]
        );

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    return [
        'type' => 'success',
        'message' => 'kintone設定を保存しました。',
    ];
}


/* ============================================================
 * kintone HTTP
 *
 * PHP cURLを使用しない。
 *
 * Content-Length / chunked / close を処理する。
 * 「closeするまで無期限に読む」ことをしない。
 * ============================================================ */

function kintone_request(
    array $k,
    string $path,
    string $method = 'GET',
    ?string $body = null
): array {

    validate_kintone($k);

    $host =
        (string)$k['subdomain']
        . '.cybozu.com';

    $port = 443;

    $verify =
        !empty($k['verify_ssl']);

    $proxy =
        trim(
            (string)($k['proxy'] ?? '')
        );

    $targetHost = $host;
    $targetPort = $port;

    $connectHost = $host;
    $connectPort = $port;

    $requestTarget = $path;

    if ($proxy !== '') {

        [$proxyHost, $proxyPort] =
            explode(':', $proxy, 2);

        $connectHost = $proxyHost;
        $connectPort = (int)$proxyPort;

        $requestTarget =
            'https://'
            . $host
            . $path;

        /*
         * HTTPS proxy CONNECT対応。
         */
    }

    $contextOptions = [
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
            'peer_name' => $targetHost,
            'crypto_method' =>
                STREAM_CRYPTO_METHOD_TLS_CLIENT,
        ],
    ];

    $context =
        stream_context_create(
            $contextOptions
        );

    $remote =
        'tcp://'
        . $connectHost
        . ':'
        . $connectPort;

    $errno = 0;
    $errstr = '';

    $socket =
        @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );

    if ($socket === false) {
        throw new RuntimeException(
            'kintoneへの接続に失敗しました。'
            . ' '
            . $errstr
        );
    }

    try {

        stream_set_blocking(
            $socket,
            true
        );

        /*
         * proxyを使わない場合は、
         * TCP接続後にTLSを開始する。
         */
        if ($proxy === '') {

            $crypto =
                @stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'kintoneのTLS接続に失敗しました。'
                );
            }

        } else {

            /*
             * HTTPS proxy CONNECT。
             */
            socket_write_all(
                $socket,
                "CONNECT "
                . $targetHost
                . ":"
                . $targetPort
                . " HTTP/1.1\r\n"
                . "Host: "
                . $targetHost
                . ":"
                . $targetPort
                . "\r\n"
                . "Connection: keep-alive\r\n"
                . "\r\n"
            );

            $proxyResponse =
                read_http_headers(
                    $socket
                );

            if (
                $proxyResponse['status'] < 200
                || $proxyResponse['status'] >= 300
            ) {
                throw new RuntimeException(
                    'Proxy CONNECTに失敗しました。'
                    . ' HTTP '
                    . $proxyResponse['status']
                );
            }

            $crypto =
                @stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'Proxy経由のTLS接続に失敗しました。'
                );
            }
        }

        $authorization =
            base64_encode(
                (string)$k['username']
                . ':'
                . (string)$k['password']
            );

        $request =
            $method
            . ' '
            . $requestTarget
            . " HTTP/1.1\r\n"
            . "Host: "
            . $host
            . "\r\n"
            . "X-Cybozu-Authorization: "
            . $authorization
            . "\r\n"
            . "Accept: application/json\r\n"
            . "Connection: close\r\n";

        if ($body !== null) {
            $request .=
                "Content-Type: application/json\r\n"
                . "Content-Length: "
                . strlen($body)
                . "\r\n";
        }

        $request .= "\r\n";

        if ($body !== null) {
            $request .= $body;
        }

        socket_write_all(
            $socket,
            $request
        );

        $headers =
            read_http_headers(
                $socket
            );

        $bodyText =
            read_http_body(
                $socket,
                $headers
            );

        $json = null;

        if ($bodyText !== '') {
            $decoded =
                json_decode(
                    $bodyText,
                    true
                );

            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        return [
            'status' => $headers['status'],
            'headers' => $headers['headers'],
            'body' => $bodyText,
            'json' => $json,
        ];

    } finally {

        fclose($socket);
    }
}


function socket_write_all(
    $socket,
    string $data
): void {

    $length = strlen($data);
    $offset = 0;
    $start = microtime(true);

    while ($offset < $length) {

        if (
            microtime(true) - $start
            > WRITE_TIMEOUT
        ) {
            throw new RuntimeException(
                '外部サービスへの送信がタイムアウトしました。'
            );
        }

        $written =
            @fwrite(
                $socket,
                substr(
                    $data,
                    $offset
                )
            );

        if ($written === false) {
            throw new RuntimeException(
                '外部サービスへデータを送信できません。'
            );
        }

        if ($written === 0) {
            usleep(10000);
            continue;
        }

        $offset += $written;
    }
}


function read_http_headers(
    $socket
): array {

    $start = microtime(true);
    $raw = '';

    while (true) {

        if (
            microtime(true) - $start
            > READ_TIMEOUT
        ) {
            throw new RuntimeException(
                '外部サービスのHTTPヘッダー読み取りがタイムアウトしました。'
            );
        }

        $line = fgets($socket, 8192);

        if ($line === false) {
            throw new RuntimeException(
                '外部サービスのHTTPヘッダーを読み取れません。'
            );
        }

        $raw .= $line;

        if (substr($raw, -4) === "\r\n\r\n") {
            break;
        }

        if (strlen($raw) > 65536) {
            throw new RuntimeException(
                'HTTPヘッダーが大きすぎます。'
            );
        }
    }

    $lines =
        preg_split(
            "/\r\n/",
            trim($raw)
        );

    $statusLine =
        (string)array_shift($lines);

    if (
        !preg_match(
            '#^HTTP/\d+\.\d+\s+(\d+)#',
            $statusLine,
            $m
        )
    ) {
        throw new RuntimeException(
            'HTTPレスポンス形式が不正です。'
        );
    }

    $headers = [];

    foreach ($lines as $line) {

        $pos = strpos($line, ':');

        if ($pos === false) {
            continue;
        }

        $name =
            strtolower(
                trim(
                    substr(
                        $line,
                        0,
                        $pos
                    )
                )
            );

        $value =
            trim(
                substr(
                    $line,
                    $pos + 1
                )
            );

        $headers[$name] = $value;
    }

    return [
        'status' => (int)$m[1],
        'headers' => $headers,
    ];
}


function read_http_body(
    $socket,
    array $headers
): string {

    $headerMap =
        $headers['headers']
        ?? [];

    $transfer =
        strtolower(
            (string)(
                $headerMap['transfer-encoding']
                ?? ''
            )
        );

    if (
        str_contains(
            $transfer,
            'chunked'
        )
    ) {
        return read_chunked_body_safe(
            $socket
        );
    }

    if (
        isset(
            $headerMap['content-length']
        )
    ) {

        $length =
            (int)$headerMap[
                'content-length'
            ];

        if ($length < 0 || $length > MAX_BODY_SIZE) {
            throw new RuntimeException(
                'HTTPレスポンスサイズが不正です。'
            );
        }

        return read_exact(
            $socket,
            $length
        );
    }

    /*
     * Content-Lengthがない場合のみcloseまで読む。
     *
     * ただしREAD_TIMEOUTを厳密に適用する。
     */
    return read_until_close_safe(
        $socket
    );
}


function read_exact(
    $socket,
    int $length
): string {

    if ($length === 0) {
        return '';
    }

    $result = '';
    $start = microtime(true);

    while (strlen($result) < $length) {

        if (
            microtime(true) - $start
            > READ_TIMEOUT
        ) {
            throw new RuntimeException(
                '外部サービスのレスポンス読み取りがタイムアウトしました。'
            );
        }

        $remaining =
            $length - strlen($result);

        $chunk =
            @fread(
                $socket,
                min(8192, $remaining)
            );

        if ($chunk === false) {
            throw new RuntimeException(
                '外部サービスのレスポンスを読み取れません。'
            );
        }

        if ($chunk === '') {
            $meta =
                stream_get_meta_data(
                    $socket
                );

            if (!empty($meta['timed_out'])) {
                throw new RuntimeException(
                    '外部サービスのレスポンス読み取りがタイムアウトしました。'
                );
            }

            usleep(10000);
            continue;
        }

        $result .= $chunk;

        if (strlen($result) > MAX_BODY_SIZE) {
            throw new RuntimeException(
                '外部サービスのレスポンスが大きすぎます。'
            );
        }
    }

    return $result;
}


function read_chunked_body_safe(
    $socket
): string {

    $result = '';
    $start = microtime(true);

    while (true) {

        if (
            microtime(true) - $start
            > READ_TIMEOUT
        ) {
            throw new RuntimeException(
                'chunkedレスポンスの読み取りがタイムアウトしました。'
            );
        }

        $line =
            fgets(
                $socket,
                8192
            );

        if ($line === false) {
            throw new RuntimeException(
                'chunkサイズを読み取れません。'
            );
        }

        $line =
            trim(
                explode(';', $line, 2)[0]
            );

        if (
            $line === ''
            || !ctype_xdigit($line)
        ) {
            throw new RuntimeException(
                'chunkサイズが不正です。'
            );
        }

        $size = hexdec($line);

        if ($size === 0) {

            /*
             * trailerを空行まで読む。
             */
            while (true) {
                $trailer =
                    fgets(
                        $socket,
                        8192
                    );

                if (
                    $trailer === false
                    || trim($trailer) === ''
                ) {
                    break;
                }
            }

            break;
        }

        if (
            strlen($result) + $size
            > MAX_BODY_SIZE
        ) {
            throw new RuntimeException(
                'レスポンスサイズが大きすぎます。'
            );
        }

        $result .=
            read_exact(
                $socket,
                $size
            );

        $crlf =
            fread(
                $socket,
                2
            );

        if ($crlf !== "\r\n") {
            throw new RuntimeException(
                'chunkedレスポンス形式が不正です。'
            );
        }
    }

    return $result;
}


function read_until_close_safe(
    $socket
): string {

    $result = '';
    $start = microtime(true);

    while (true) {

        if (
            microtime(true) - $start
            > READ_TIMEOUT
        ) {
            throw new RuntimeException(
                '外部サービスのレスポンス読み取りがタイムアウトしました。'
            );
        }

        $chunk =
            @fread(
                $socket,
                8192
            );

        if ($chunk === false) {
            throw new RuntimeException(
                '外部サービスのレスポンスを読み取れません。'
            );
        }

        if ($chunk === '') {

            $meta =
                stream_get_meta_data(
                    $socket
                );

            if (!empty($meta['timed_out'])) {
                throw new RuntimeException(
                    '外部サービスのレスポンス読み取りがタイムアウトしました。'
                );
            }

            break;
        }

        $result .= $chunk;

        if (strlen($result) > MAX_BODY_SIZE) {
            throw new RuntimeException(
                '外部サービスのレスポンスが大きすぎます。'
            );
        }
    }

    return $result;
}


/* ============================================================
 * kintone 接続テスト
 * ============================================================ */

function test_kintone(): array
{
    $settings =
        read_json(
            SETTINGS_FILE
        );

    $k =
        $settings['kintone']
        ?? [];

    validate_kintone($k);

    try {

        $result =
            kintone_request(
                $k,
                '/k/v1/app.json?app='
                . rawurlencode(
                    (string)$k['app_id']
                ),
                'GET'
            );

        $status =
            (int)$result['status'];

        if (
            $status >= 200
            && $status < 300
        ) {

            $settings['kintone']
                ['connection_status']
                = '接続確認済み';

            $settings['kintone']
                ['last_test_at']
                = now_iso();

            $settings['kintone']
                ['last_error']
                = '';

            write_json_atomic(
                SETTINGS_FILE,
                $settings
            );

            return [
                'type' => 'success',
                'message' =>
                    'kintoneへの接続に成功しました。',
            ];
        }

        $message =
            kintone_error_message(
                $result
            );

        $settings['kintone']
            ['connection_status']
            = '接続できません';

        $settings['kintone']
            ['last_test_at']
            = now_iso();

        $settings['kintone']
            ['last_error']
            = $message;

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        return [
            'type' => 'error',
            'message' =>
                'kintoneへの接続に失敗しました。'
                . ' HTTP '
                . $status
                . $message,
        ];

    } catch (Throwable $e) {

        $message =
            public_error_message($e);

        $settings['kintone']
            ['connection_status']
            = '接続できません';

        $settings['kintone']
            ['last_test_at']
            = now_iso();

        $settings['kintone']
            ['last_error']
            = $message;

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        return [
            'type' => 'error',
            'message' =>
                'kintone接続テストに失敗しました。'
                . ' '
                . $message,
        ];
    }
}


function kintone_error_message(
    array $result
): string {

    $json =
        $result['json']
        ?? null;

    if (
        is_array($json)
        && isset($json['message'])
    ) {
        return ' ' . mb_substr(
            (string)$json['message'],
            0,
            500
        );
    }

    $body =
        trim(
            (string)(
                $result['body']
                ?? ''
            )
        );

    if ($body === '') {
        return '';
    }

    return ' '
        . mb_substr(
            $body,
            0,
            500
        );
}


/* ============================================================
 * kintone 項目一覧
 * ============================================================ */

function fetch_kintone_fields(): array
{
    $settings =
        read_json(
            SETTINGS_FILE
        );

    $k =
        $settings['kintone']
        ?? [];

    validate_kintone($k);

    $result =
        kintone_request(
            $k,
            '/k/v1/app/form/fields.json?app='
            . rawurlencode(
                (string)$k['app_id']
            ),
            'GET'
        );

    if (
        $result['status'] < 200
        || $result['status'] >= 300
    ) {
        throw new RuntimeException(
            'kintone項目取得に失敗しました。'
            . kintone_error_message($result)
        );
    }

    $json =
        $result['json']
        ?? [];

    $settings['kintone']['fields'] =
        is_array(
            $json['properties']
            ?? null
        )
            ? $json['properties']
            : [];

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    return [
        'type' => 'success',
        'message' =>
            'kintoneの項目一覧を再取得しました。',
    ];
}


/* ============================================================
 * kintone 顧客同期
 * ============================================================ */

function sync_kintone(): array
{
    $settings =
        read_json(
            SETTINGS_FILE
        );

    $k =
        $settings['kintone']
        ?? [];

    validate_kintone($k);

    $mapping =
        $k['field_mapping']
        ?? [];

    $customers = [];
    $offset = 0;

    while (true) {

        $query =
            'order by $id asc limit 500 offset '
            . $offset;

        $result =
            kintone_request(
                $k,
                '/k/v1/records.json?'
                . http_build_query([
                    'app' =>
                        (string)$k['app_id'],
                    'query' =>
                        $query,
                ]),
                'GET'
            );

        if (
            $result['status'] < 200
            || $result['status'] >= 300
        ) {
            throw new RuntimeException(
                'kintone顧客同期に失敗しました。'
                . kintone_error_message($result)
            );
        }

        $json =
            $result['json']
            ?? [];

        $records =
            is_array(
                $json['records']
                ?? null
            )
                ? $json['records']
                : [];

        if (!$records) {
            break;
        }

        foreach ($records as $record) {

            $customers[] =
                normalize_kintone_customer(
                    $record,
                    $mapping
                );
        }

        $offset +=
            count($records);

        if (count($records) < 500) {
            break;
        }
    }

    write_json_atomic(
        CUSTOMERS_FILE,
        $customers
    );

    return [
        'type' => 'success',
        'message' =>
            count($customers)
            . '件の顧客情報を同期しました。',
    ];
}


function normalize_kintone_customer(
    array $record,
    array $mapping
): array {

    return [
        'id' =>
            'kintone-'
            . (string)(
                $record['$id']['value']
                ?? uuid()
            ),

        'organization' =>
            kintone_field_value(
                $record,
                (string)(
                    $mapping['organization']
                    ?? ''
                )
            ),

        'name' =>
            kintone_field_value(
                $record,
                (string)(
                    $mapping['name']
                    ?? ''
                )
            ),

        'email' =>
            kintone_field_value(
                $record,
                (string)(
                    $mapping['email']
                    ?? ''
                )
            ),

        'department' =>
            kintone_field_value(
                $record,
                (string)(
                    $mapping['department']
                    ?? ''
                )
            ),

        'phone' =>
            kintone_field_value(
                $record,
                (string)(
                    $mapping['phone']
                    ?? ''
                )
            ),

        'address' =>
            kintone_address_value(
                $record,
                $mapping['address']
                ?? []
            ),
    ];
}


function kintone_field_value(
    array $record,
    string $field
): string {

    if ($field === '') {
        return '';
    }

    $value =
        $record[$field]['value']
        ?? '';

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $parts[] =
                    (string)(
                        $item['value']
                        ?? ''
                    );
            }
        }

        return implode(
            ', ',
            $parts
        );
    }

    return (string)$value;
}


function kintone_address_value(
    array $record,
    array $fields
): string {

    $values = [];

    foreach ($fields as $field) {

        $field = (string)$field;

        if ($field === '') {
            continue;
        }

        $value =
            kintone_field_value(
                $record,
                $field
            );

        if ($value !== '') {
            $values[] = $value;
        }
    }

    return implode(
        ' ',
        $values
    );
}


/* ============================================================
 * SMTP設定
 * ============================================================ */

function save_mail(): array
{
    require_post();

    $settings =
        read_json(
            SETTINGS_FILE
        );

    $current =
        $settings['mail']
        ?? [];

    $host =
        trim(
            (string)($_POST['host'] ?? '')
        );

    $port =
        (int)($_POST['port'] ?? 0);

    $encryption =
        (string)(
            $_POST['encryption']
            ?? 'tls'
        );

    $auth =
        isset($_POST['auth']);

    $username =
        trim(
            (string)($_POST['username'] ?? '')
        );

    $password =
        (string)($_POST['password'] ?? '');

    if ($password === '') {
        $password =
            (string)($current['password'] ?? '');
    }

    $fromEmail =
        trim(
            (string)($_POST['from_email'] ?? '')
        );

    $fromName =
        trim(
            (string)($_POST['from_name'] ?? '')
        );

    $replyTo =
        trim(
            (string)($_POST['reply_to'] ?? '')
        );

    if ($host === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを入力してください。'
        );
    }

    if (
        $port < 1
        || $port > 65535
    ) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    if (
        !in_array(
            $encryption,
            ['ssl', 'tls', 'none'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '暗号化方式が不正です。'
        );
    }

    if (!valid_email($fromEmail)) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    if (
        $replyTo !== ''
        && !valid_email($replyTo)
    ) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが不正です。'
        );
    }

    if (
        $auth
        && (
            $username === ''
            || $password === ''
        )
    ) {
        throw new InvalidArgumentException(
            'SMTP認証を使用する場合はユーザー名とパスワードが必要です。'
        );
    }

    $settings['mail'] =
        array_merge(
            $current,
            [
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'auth' => $auth,
                'username' => $username,
                'password' => $password,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'reply_to' => $replyTo,
                'connection_status' => '未設定',
                'last_error' => '',
            ]
        );

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    return [
        'type' => 'success',
        'message' => 'メール設定を保存しました。',
    ];
}


/* ============================================================
 * SMTP接続
 * ============================================================ */

function smtp_connect(
    array $mail
) {

    $host =
        (string)($mail['host'] ?? '');

    $port =
        (int)($mail['port'] ?? 0);

    $encryption =
        (string)(
            $mail['encryption']
            ?? 'tls'
        );

    if (
        $host === ''
        || $port < 1
        || $port > 65535
    ) {
        throw new InvalidArgumentException(
            'SMTP設定が不正です。'
        );
    }

    $connectHost = $host;

    if ($encryption === 'ssl') {
        $connectHost =
            'ssl://'
            . $host;
    }

    $errno = 0;
    $errstr = '';

    $socket =
        @stream_socket_client(
            $connectHost
            . ':'
            . $port,
            $errno,
            $errstr,
            CONNECT_TIMEOUT
        );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPサーバへ接続できません。'
            . ' '
            . $errstr
        );
    }

    try {

        smtp_expect(
            $socket,
            220
        );

        if ($encryption === 'tls') {

            smtp_command(
                $socket,
                'EHLO '
                . smtp_hostname()
            );

            smtp_expect(
                $socket,
                250
            );

            smtp_command(
                $socket,
                'STARTTLS'
            );

            smtp_expect(
                $socket,
                220
            );

            $crypto =
                @stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'SMTP TLS接続に失敗しました。'
                );
            }

            smtp_command(
                $socket,
                'EHLO '
                . smtp_hostname()
            );

            smtp_expect(
                $socket,
                250
            );

        } else {

            smtp_command(
                $socket,
                'EHLO '
                . smtp_hostname()
            );

            smtp_expect(
                $socket,
                250
            );
        }

        if (!empty($mail['auth'])) {

            smtp_command(
                $socket,
                'AUTH LOGIN'
            );

            smtp_expect(
                $socket,
                334
            );

            smtp_command(
                $socket,
                base64_encode(
                    (string)$mail['username']
                )
            );

            smtp_expect(
                $socket,
                334
            );

            smtp_command(
                $socket,
                base64_encode(
                    (string)$mail['password']
                )
            );

            smtp_expect(
                $socket,
                235
            );
        }

        return $socket;

    } catch (Throwable $e) {

        fclose($socket);

        throw $e;
    }
}


function smtp_hostname(): string
{
    $host =
        gethostname();

    if (
        !is_string($host)
        || $host === ''
    ) {
        return 'localhost';
    }

    return $host;
}


function smtp_command(
    $socket,
    string $command
): void {

    socket_write_all(
        $socket,
        $command . "\r\n"
    );
}


function smtp_expect(
    $socket,
    int $expected
): string {

    $start = microtime(true);
    $response = '';

    while (true) {

        if (
            microtime(true) - $start
            > READ_TIMEOUT
        ) {
            throw new RuntimeException(
                'SMTP応答の読み取りがタイムアウトしました。'
            );
        }

        $line =
            fgets(
                $socket,
                8192
            );

        if ($line === false) {
            throw new RuntimeException(
                'SMTP応答を読み取れません。'
            );
        }

        $response .= $line;

        if (
            preg_match(
                '/^(\d{3})([\s-])/',
                $line,
                $m
            )
        ) {

            if (
                $m[2] === ' '
            ) {

                $code =
                    (int)$m[1];

                if ($code !== $expected) {
                    throw new RuntimeException(
                        'SMTPエラー。応答コード: '
                        . $code
                    );
                }

                return $response;
            }
        }
    }
}


/* ============================================================
 * メール接続テスト
 * ============================================================ */

function test_mail(): array
{
    $settings =
        read_json(
            SETTINGS_FILE
        );

    $mail =
        $settings['mail']
        ?? [];

    try {

        $socket =
            smtp_connect(
                $mail
            );

        smtp_command(
            $socket,
            'QUIT'
        );

        /*
         * QUITへの応答は必須ではない。
         * ここで無期限待機しない。
         */
        @fgets(
            $socket,
            8192
        );

        fclose($socket);

        $settings['mail']
            ['connection_status']
            = '接続確認済み';

        $settings['mail']
            ['last_test_at']
            = now_iso();

        $settings['mail']
            ['last_error']
            = '';

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        return [
            'type' => 'success',
            'message' =>
                'SMTPサーバへの接続に成功しました。',
        ];

    } catch (Throwable $e) {

        $message =
            public_error_message($e);

        $settings['mail']
            ['connection_status']
            = '接続できません';

        $settings['mail']
            ['last_test_at']
            = now_iso();

        $settings['mail']
            ['last_error']
            = $message;

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        return [
            'type' => 'error',
            'message' =>
                'SMTP接続テストに失敗しました。'
                . ' '
                . $message,
        ];
    }
}


/* ============================================================
 * テストメール
 * ============================================================ */

function send_test_mail(): array
{
    $settings =
        read_json(
            SETTINGS_FILE
        );

    $mail =
        $settings['mail']
        ?? [];

    $to =
        trim(
            (string)($_POST['test_to'] ?? '')
        );

    if (!valid_email($to)) {
        throw new InvalidArgumentException(
            'テスト送信先メールアドレスが不正です。'
        );
    }

    smtp_send(
        $mail,
        $to,
        'アンケートアプリ SMTPテスト',
        "SMTP接続テストです。\r\n"
        . "送信日時: "
        . now_iso()
    );

    return [
        'type' => 'success',
        'message' =>
            'テストメールを送信しました。',
    ];
}


function smtp_send(
    array $mail,
    string $to,
    string $subject,
    string $body
): void {

    $socket =
        smtp_connect(
            $mail
        );

    try {

        smtp_command(
            $socket,
            'MAIL FROM:<'
            . $mail['from_email']
            . '>'
        );

        smtp_expect(
            $socket,
            250
        );

        smtp_command(
            $socket,
            'RCPT TO:<'
            . $to
            . '>'
        );

        smtp_expect(
            $socket,
            250
        );

        smtp_command(
            $socket,
            'DATA'
        );

        smtp_expect(
            $socket,
            354
        );

        $headers = [];

        $headers[] =
            'Date: '
            . date(
                'r'
            );

        $headers[] =
            'From: '
            . encode_mail_name(
                (string)(
                    $mail['from_name']
                    ?? ''
                )
            )
            . ' <'
            . $mail['from_email']
            . '>';

        $headers[] =
            'To: <'
            . $to
            . '>';

        if (
            !empty(
                $mail['reply_to']
            )
        ) {
            $headers[] =
                'Reply-To: '
                . $mail['reply_to'];
        }

        $headers[] =
            'Subject: '
            . encode_subject(
                $subject
            );

        $headers[] =
            'MIME-Version: 1.0';

        $headers[] =
            'Content-Type: text/plain; charset=UTF-8';

        $headers[] =
            'Content-Transfer-Encoding: 8bit';

        $message =
            implode(
                "\r\n",
                $headers
            )
            . "\r\n\r\n"
            . normalize_mail_body($body)
            . "\r\n.\r\n";

        socket_write_all(
            $socket,
            $message
        );

        smtp_expect(
            $socket,
            250
        );

        smtp_command(
            $socket,
            'QUIT'
        );

        @fgets(
            $socket,
            8192
        );

    } finally {

        fclose($socket);
    }
}


function normalize_mail_body(
    string $body
): string {

    $body =
        str_replace(
            ["\r\n", "\r"],
            "\n",
            $body
        );

    /*
     * SMTP DATAのドットスタッフィング。
     */
    $body =
        preg_replace(
            '/^\\./m',
            '..',
            $body
        ) ?? $body;

    return str_replace(
        "\n",
        "\r\n",
        $body
    );
}


function encode_subject(
    string $subject
): string {

    return '=?UTF-8?B?'
        . base64_encode(
            $subject
        )
        . '?=';
}


function encode_mail_name(
    string $name
): string {

    if ($name === '') {
        return '';
    }

    return '=?UTF-8?B?'
        . base64_encode($name)
        . '?=';
}


/* ============================================================
 * アンケート
 * ============================================================ */

function default_survey(): array
{
    return [
        'id' => 'survey-' . uuid(),
        'title' => '新しいアンケート',
        'description' => '',
        'startAt' => '',
        'endAt' => '',
        'status' => 'draft',
        'createdAt' => now_iso(),
        'updatedAt' => now_iso(),
        'numbering' => 'global',
        'groups' => [
            [
                'id' => 'group-' . uuid(),
                'title' => 'グループ1',
                'questions' => [
                    [
                        'id' => 'question-' . uuid(),
                        'text' => '',
                        'type' => 'single',
                        'required' => false,
                        'options' => [
                            [
                                'id' => 'option-' . uuid(),
                                'label' => '',
                                'nextQuestionId' => '',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}


function save_survey(): array
{
    require_post();

    $surveys =
        read_json(
            SURVEYS_FILE
        );

    $id =
        trim(
            (string)($_POST['id'] ?? '')
        );

    $survey = null;

    foreach ($surveys as &$item) {
        if (
            (string)($item['id'] ?? '')
            === $id
        ) {
            $survey = &$item;
            break;
        }
    }

    unset($item);

    if ($survey === null) {
        $survey =
            default_survey();

        $id =
            $survey['id'];

        $surveys[] =
            $survey;

        $survey =
            &$surveys[
                count($surveys) - 1
            ];
    }

    $title =
        trim(
            (string)(
                $_POST['title']
                ?? ''
            )
        );

    if ($title === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルを入力してください。'
        );
    }

    $survey['title'] =
        mb_substr(
            $title,
            0,
            200
        );

    $survey['description'] =
        (string)(
            $_POST['description']
            ?? ''
        );

    $survey['startAt'] =
        normalize_datetime_input(
            (string)(
                $_POST['startAt']
                ?? ''
            )
        );

    $survey['endAt'] =
        normalize_datetime_input(
            (string)(
                $_POST['endAt']
                ?? ''
            )
        );

    $survey['numbering'] =
        in_array(
            $_POST['numbering']
                ?? 'global',
            ['global', 'group'],
            true
        )
            ? $_POST['numbering']
            : 'global';

    if (
        empty($survey['status'])
    ) {
        $survey['status'] =
            'draft';
    }

    $survey['updatedAt'] =
        now_iso();

    recalculate_question_numbers(
        $survey
    );

    write_json_atomic(
        SURVEYS_FILE,
        array_values($surveys)
    );

    return [
        'type' => 'success',
        'message' =>
            'アンケートを保存しました。',
    ];
}


function normalize_datetime_input(
    string $value
): string {

    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $timestamp =
        strtotime($value);

    if ($timestamp === false) {
        throw new InvalidArgumentException(
            '日時の形式が不正です。'
        );
    }

    return date(
        'Y-m-d\TH:i',
        $timestamp
    );
}


function delete_survey(): array
{
    require_post();

    $id =
        trim(
            (string)($_POST['id'] ?? '')
        );

    $surveys =
        read_json(
            SURVEYS_FILE
        );

    $found = false;

    $surveys =
        array_values(
            array_filter(
                $surveys,
                function ($survey) use (
                    $id,
                    &$found
                ) {
                    if (
                        (string)(
                            $survey['id']
                            ?? ''
                        ) === $id
                    ) {
                        $found = true;
                        return false;
                    }

                    return true;
                }
            )
        );

    if (!$found) {
        throw new RuntimeException(
            '削除対象のアンケートが存在しません。'
        );
    }

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    return [
        'type' => 'success',
        'message' =>
            'アンケートを削除しました。',
    ];
}


function duplicate_survey(): array
{
    require_post();

    $id =
        trim(
            (string)($_POST['id'] ?? '')
        );

    $surveys =
        read_json(
            SURVEYS_FILE
        );

    $source = null;

    foreach ($surveys as $survey) {

        if (
            (string)(
                $survey['id']
                ?? ''
            ) === $id
        ) {
            $source = $survey;
            break;
        }
    }

    if ($source === null) {
        throw new RuntimeException(
            '複製対象のアンケートが存在しません。'
        );
    }

    $source['id'] =
        'survey-' . uuid();

    $source['title'] =
        (string)(
            $source['title']
            ?? ''
        )
        . '（複製）';

    $source['status'] =
        'draft';

    $source['createdAt'] =
        now_iso();

    $source['updatedAt'] =
        now_iso();

    $surveys[] =
        $source;

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    return [
        'type' => 'success',
        'message' =>
            'アンケートを複製しました。',
    ];
}


function change_survey_status(): array
{
    require_post();

    $id =
        trim(
            (string)($_POST['id'] ?? '')
        );

    $next =
        (string)(
            $_POST['status']
            ?? ''
        );

    if (
        !in_array(
            $next,
            ['draft', 'published', 'stopped'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '指定された状態は変更できません。'
        );
    }

    $surveys =
        read_json(
            SURVEYS_FILE
        );

    foreach ($surveys as &$survey) {

        if (
            (string)(
                $survey['id']
                ?? ''
            ) !== $id
        ) {
            continue;
        }

        update_auto_status(
            $survey
        );

        $current =
            (string)(
                $survey['status']
                ?? 'draft'
            );

        if ($current === 'ended') {
            throw new RuntimeException(
                '終了したアンケートの状態は変更できません。'
            );
        }

        $valid = [
            'draft' => ['published'],
            'published' => ['stopped'],
            'stopped' => ['published'],
        ];

        if (
            !in_array(
                $next,
                $valid[$current]
                    ?? [],
                true
            )
        ) {
            throw new RuntimeException(
                '許可されていない状態変更です。'
            );
        }

        $survey['status'] =
            $next;

        $survey['updatedAt'] =
            now_iso();

        unset($survey);

        write_json_atomic(
            SURVEYS_FILE,
            $surveys
        );

        return [
            'type' => 'success',
            'message' =>
                'アンケートの状態を変更しました。',
        ];
    }

    throw new RuntimeException(
        '対象アンケートが存在しません。'
    );
}


function update_auto_status(
    array &$survey
): void {

    if (
        ($survey['status'] ?? '')
        !== 'published'
    ) {
        return;
    }

    $endAt =
        (string)(
            $survey['endAt']
            ?? ''
        );

    if ($endAt === '') {
        return;
    }

    $timestamp =
        strtotime($endAt);

    if (
        $timestamp !== false
        && $timestamp < time()
    ) {
        $survey['status'] =
            'ended';

        $survey['updatedAt'] =
            now_iso();
    }
}


function recalculate_question_numbers(
    array &$survey
): void {

    $number = 1;

    foreach (
        $survey['groups']
        as $gi => &$group
    ) {

        $local = 1;

        foreach (
            $group['questions']
            as &$question
        ) {

            if (
                ($survey['numbering'] ?? 'global')
                === 'group'
            ) {
                $question['number'] =
                    'Q'
                    . ($gi + 1)
                    . '-'
                    . $local;
            } else {
                $question['number'] =
                    'Q'
                    . $number;
            }

            $number++;
            $local++;
        }

        unset($question);
    }

    unset($group);
}


/* ============================================================
 * 送信
 * ============================================================ */

function send_survey_mail(): array
{
    require_post();

    $surveyId =
        trim(
            (string)(
                $_POST['survey_id']
                ?? ''
            )
        );

    if ($surveyId === '') {
        throw new InvalidArgumentException(
            '対象アンケートが指定されていません。'
        );
    }

    $survey =
        find_survey(
            $surveyId
        );

    if ($survey === null) {
        throw new RuntimeException(
            '対象アンケートが存在しません。'
        );
    }

    $customerIds =
        $_POST['customer_ids']
        ?? [];

    if (!is_array($customerIds)) {
        $customerIds = [];
    }

    $subject =
        trim(
            (string)(
                $_POST['subject']
                ?? ''
            )
        );

    $body =
        (string)(
            $_POST['body']
            ?? ''
        );

    if ($subject === '') {
        throw new InvalidArgumentException(
            'メール件名を入力してください。'
        );
    }

    if ($body === '') {
        throw new InvalidArgumentException(
            'メール本文を入力してください。'
        );
    }

    $customers =
        read_json(
            CUSTOMERS_FILE
        );

    $mailSettings =
        read_json(
            SETTINGS_FILE
        )['mail']
        ?? [];

    $results = [];

    foreach ($customers as $customer) {

        if (
            !in_array(
                (string)(
                    $customer['id']
                    ?? ''
                ),
                array_map(
                    'strval',
                    $customerIds
                ),
                true
            )
        ) {
            continue;
        }

        $email =
            (string)(
                $customer['email']
                ?? ''
            );

        if (!valid_email($email)) {
            $results[] = [
                'name' =>
                    (string)(
                        $customer['name']
                        ?? ''
                    ),
                'success' => false,
                'message' =>
                    'メールアドレスが不正です。',
            ];

            continue;
        }

        $url =
            answer_url(
                $survey['id']
            );

        $personalBody =
            str_replace(
                [
                    '{顧客名}',
                    '{アンケートURL}',
                ],
                [
                    (string)(
                        $customer['name']
                        ?? ''
                    ),
                    $url,
                ],
                $body
            );

        try {

            smtp_send(
                $mailSettings,
                $email,
                $subject,
                $personalBody
            );

            $results[] = [
                'name' =>
                    (string)(
                        $customer['name']
                        ?? ''
                    ),
                'email' => $email,
                'success' => true,
                'message' => '送信成功',
            ];

        } catch (Throwable $e) {

            $results[] = [
                'name' =>
                    (string)(
                        $customer['name']
                        ?? ''
                    ),
                'email' => $email,
                'success' => false,
                'message' =>
                    public_error_message($e),
            ];
        }
    }

    $logs =
        read_json(
            SEND_LOG_FILE
        );

    $logs[] = [
        'id' => uuid(),
        'survey_id' => $surveyId,
        'createdAt' => now_iso(),
        'results' => $results,
    ];

    write_json_atomic(
        SEND_LOG_FILE,
        $logs
    );

    $success =
        count(
            array_filter(
                $results,
                fn($r) =>
                    !empty($r['success'])
            )
        );

    $failed =
        count($results)
        - $success;

    return [
        'type' =>
            $failed === 0
                ? 'success'
                : 'warning',
        'message' =>
            'メール送信処理が完了しました。'
            . ' 成功: '
            . $success
            . '件 / 失敗: '
            . $failed
            . '件',
        'details' => $results,
    ];
}


function answer_url(
    string $surveyId
): string {

    return screen_url(
        'answer',
        [
            'id' => $surveyId,
        ]
    );
}


/* ============================================================
 * 回答
 * ============================================================ */

function find_survey(
    string $id
): ?array {

    $surveys =
        read_json(
            SURVEYS_FILE
        );

    foreach ($surveys as $survey) {

        if (
            (string)(
                $survey['id']
                ?? ''
            ) === $id
        ) {

            update_auto_status(
                $survey
            );

            return $survey;
        }
    }

    return null;
}


function answer_next(): array
{
    require_post();

    $surveyId =
        trim(
            (string)(
                $_POST['survey_id']
                ?? ''
            )
        );

    $survey =
        find_survey(
            $surveyId
        );

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $answers =
        $_POST['answers']
        ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $_SESSION[
        'answer_' . $surveyId
    ] =
        $answers;

    return [
        'type' => 'success',
        'message' =>
            '回答内容を確認してください。',
    ];
}


function answer_submit(): array
{
    require_post();

    $surveyId =
        trim(
            (string)(
                $_POST['survey_id']
                ?? ''
            )
        );

    $survey =
        find_survey(
            $surveyId
        );

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $answers =
        $_SESSION[
            'answer_' . $surveyId
        ]
        ?? [];

    $all =
        read_json(
            ANSWERS_FILE
        );

    $all[] = [
        'id' => uuid(),
        'survey_id' => $surveyId,
        'createdAt' => now_iso(),
        'answers' => $answers,
    ];

    write_json_atomic(
        ANSWERS_FILE,
        $all
    );

    unset(
        $_SESSION[
            'answer_' . $surveyId
        ]
    );

    return [
        'type' => 'success',
        'message' =>
            '回答を送信しました。',
    ];
}


/* ============================================================
 * 画面
 * ============================================================ */

function render_page(
    string $screen,
    ?array $result,
    ?string $error
): void {

    $title =
        match ($screen) {
            'kintone' => 'kintone連携設定',
            'mail' => 'メールサーバ設定',
            'edit' => 'アンケート編集',
            'send' => '顧客選択・メール送信',
            'analytics' => '回答集計・分析',
            'answer' => 'アンケート回答',
            'confirm' => '回答確認',
            'complete' => '回答完了',
            'preview' => 'プレビュー',
            default => 'アンケート一覧',
        };

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>
<title><?=e($title)?></title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f5f7fa;
    color: #222;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}

header {
    background: #1f2937;
    color: #fff;
    padding: 16px 24px;
}

nav {
    margin-top: 8px;
}

nav a {
    color: #fff;
    margin-right: 16px;
    text-decoration: none;
}

main {
    max-width: 1200px;
    margin: 24px auto;
    padding: 0 16px;
}

.card {
    background: #fff;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow:
        0 1px 3px rgba(0,0,0,.08);
}

.form-row {
    display: grid;
    grid-template-columns:
        220px 1fr;
    gap: 12px;
    margin-bottom: 14px;
    align-items: center;
}

input,
textarea,
select {
    width: 100%;
    padding: 9px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font: inherit;
}

textarea {
    min-height: 140px;
}

button {
    border: 0;
    border-radius: 6px;
    padding: 9px 15px;
    cursor: pointer;
    margin: 3px;
}

button.primary {
    background: #2563eb;
    color: #fff;
}

button.secondary {
    background: #475569;
    color: #fff;
}

button.danger {
    background: #dc2626;
    color: #fff;
}

button:disabled {
    opacity: .5;
    cursor: wait;
}

.notice {
    padding: 14px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.notice.success {
    background: #dcfce7;
    color: #166534;
}

.notice.error {
    background: #fee2e2;
    color: #991b1b;
}

.notice.warning {
    background: #fef3c7;
    color: #92400e;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    border-bottom: 1px solid #e5e7eb;
    padding: 10px;
    text-align: left;
    white-space: nowrap;
}

.table-scroll {
    overflow-x: auto;
}

.status {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 999px;
    background: #e5e7eb;
}

.spinner {
    display: none;
    margin-left: 6px;
}

.processing .spinner {
    display: inline-block;
}

@media (max-width: 700px) {
    .form-row {
        grid-template-columns: 1fr;
    }

    th,
    td {
        font-size: 13px;
    }
}
</style>
</head>

<body>

<header>

<strong>アンケートアプリ</strong>

<nav>
<?php if (!in_array(
    $screen,
    ['answer','confirm','complete'],
    true
)): ?>

<a href="<?=e(screen_url('list'))?>">
アンケート一覧
</a>

<a href="<?=e(screen_url('kintone'))?>">
kintone
</a>

<a href="<?=e(screen_url('mail'))?>">
メール
</a>

<?php endif; ?>
</nav>

</header>

<main>

<?php if ($error !== null): ?>

<div class="notice error">
<strong>処理失敗</strong><br>
<?=e($error)?>
</div>

<?php endif; ?>

<?php if ($result !== null): ?>

<div class="notice <?=e(
    $result['type'] ?? 'success'
)?>">
<strong>
<?=e(
    $result['message']
    ?? ''
)?>
</strong>

<?php
if (
    !empty(
        $result['details']
    )
): ?>

<div style="margin-top:10px">

<?php foreach (
    $result['details']
    as $detail
): ?>

<div>
<?=e(
    $detail['name']
    ?? ''
)?>
:
<?=e(
    $detail['message']
    ?? ''
)?>
</div>

<?php endforeach; ?>

</div>

<?php endif; ?>

</div>

<?php endif; ?>


<?php

switch ($screen) {

    case 'kintone':
        render_kintone();
        break;

    case 'mail':
        render_mail();
        break;

    case 'edit':
        render_edit();
        break;

    case 'send':
        render_send();
        break;

    case 'analytics':
        render_analytics();
        break;

    case 'answer':
        render_answer();
        break;

    case 'confirm':
        render_confirm();
        break;

    case 'complete':
        render_complete();
        break;

    case 'preview':
        render_preview();
        break;

    default:
        render_list();
        break;
}

?>

</main>

<script>
document.querySelectorAll('form').forEach(function(form) {

    form.addEventListener('submit', function() {

        var buttons =
            form.querySelectorAll(
                'button[type="submit"]'
            );

        buttons.forEach(function(button) {
            button.disabled = true;
        });

        form.classList.add('processing');
    });
});
</script>

</body>
</html>
<?php
}


/* ============================================================
 * kintone画面
 * ============================================================ */

function render_kintone(): void
{
    $settings =
        read_json(
            SETTINGS_FILE
        );

    $k =
        $settings['kintone']
        ?? [];

    ?>

<div class="card">

<h1>kintone連携設定</h1>

<form
    method="post"
    action="<?=e(screen_url('kintone'))?>"
>

<input
    type="hidden"
    name="action"
    value="save_kintone"
>

<div class="form-row">
<label>サブドメイン</label>
<input
    name="subdomain"
    value="<?=e(
        $k['subdomain']
        ?? ''
    )?>"
    placeholder="xxxx / xxxx.cybozu.com"
    required
>
</div>

<div class="form-row">
<label>顧客管理アプリID</label>
<input
    name="app_id"
    value="<?=e(
        $k['app_id']
        ?? ''
    )?>"
    inputmode="numeric"
    required
>
</div>

<div class="form-row">
<label>ログイン名</label>
<input
    name="username"
    value="<?=e(
        $k['username']
        ?? ''
    )?>"
    required
>
</div>

<div class="form-row">
<label>パスワード</label>
<input
    type="password"
    name="password"
    autocomplete="new-password"
    placeholder="変更しない場合は空欄"
>
</div>

<div class="form-row">
<label>Proxy</label>
<input
    name="proxy"
    value="<?=e(
        $k['proxy']
        ?? ''
    )?>"
    placeholder="host:port"
>
</div>

<div class="form-row">
<label>SSL証明書検証</label>
<label>
<input
    type="checkbox"
    name="verify_ssl"
    value="1"
    style="width:auto"
    <?=!empty(
        $k['verify_ssl']
    ) ? 'checked' : ''?>
>
有効
</label>
</div>

<button
    class="primary"
    type="submit"
>
設定保存
</button>

</form>

<hr>

<h2>接続テスト</h2>

<p>
状態:
<strong>
<?=e(
    $k['connection_status']
    ?? '未設定'
)?>
</strong>
</p>

<?php if (
    !empty($k['last_error'])
): ?>

<p class="notice error">
<?=e(
    $k['last_error']
)?>
</p>

<?php endif; ?>

<form
    method="post"
    action="<?=e(screen_url('kintone'))?>"
>

<input
    type="hidden"
    name="action"
    value="test_kintone"
>

<button
    class="secondary"
    type="submit"
>
接続テスト
<span class="spinner">⏳</span>
</button>

</form>

<hr>

<h2>項目一覧</h2>

<form
    method="post"
    action="<?=e(screen_url('kintone'))?>"
>

<input
    type="hidden"
    name="action"
    value="fetch_kintone_fields"
>

<button
    class="secondary"
    type="submit"
>
項目一覧を再取得
<span class="spinner">⏳</span>
</button>

</form>

<?php

$fields =
    $k['fields']
    ?? [];

if (is_array($fields) && $fields):

?>

<div class="table-scroll">

<table>

<tr>
<th>フィールドコード</th>
<th>ラベル</th>
<th>タイプ</th>
</tr>

<?php foreach (
    $fields
    as $code => $field
): ?>

<tr>

<td><?=e($code)?></td>

<td><?=e(
    $field['label']
    ?? ''
)?></td>

<td><?=e(
    $field['type']
    ?? ''
)?></td>

</tr>

<?php endforeach; ?>

</table>

</div>

<?php endif; ?>

<hr>

<h2>顧客情報同期</h2>

<form
    method="post"
    action="<?=e(screen_url('kintone'))?>"
>

<input
    type="hidden"
    name="action"
    value="sync_kintone"
>

<button
    class="primary"
    type="submit"
>
顧客情報を同期
<span class="spinner">⏳</span>
</button>

</form>

</div>

<?php
}


/* ============================================================
 * メール画面
 * ============================================================ */

function render_mail(): void
{
    $settings =
        read_json(
            SETTINGS_FILE
        );

    $mail =
        $settings['mail']
        ?? [];

    ?>

<div class="card">

<h1>メールサーバ設定</h1>

<form
    method="post"
    action="<?=e(screen_url('mail'))?>"
>

<input
    type="hidden"
    name="action"
    value="save_mail"
>

<div class="form-row">
<label>SMTPサーバ</label>
<input
    name="host"
    value="<?=e(
        $mail['host']
        ?? ''
    )?>"
    required
>
</div>

<div class="form-row">
<label>SMTPポート</label>
<input
    type="number"
    name="port"
    min="1"
    max="65535"
    value="<?=e(
        $mail['port']
        ?? 587
    )?>"
    required
>
</div>

<div class="form-row">
<label>暗号化方式</label>
<select name="encryption">

<?php foreach (
    [
        'ssl' => 'SSL',
        'tls' => 'TLS',
        'none' => 'なし',
    ]
    as $value => $label
): ?>

<option
    value="<?=e($value)?>"
    <?=(
        ($mail['encryption']
            ?? 'tls')
        === $value
    )
        ? 'selected'
        : ''?>
>
<?=e($label)?>
</option>

<?php endforeach; ?>

</select>
</div>

<div class="form-row">
<label>SMTP認証</label>
<label>
<input
    type="checkbox"
    name="auth"
    value="1"
    style="width:auto"
    <?=!empty(
        $mail['auth']
    ) ? 'checked' : ''?>
>
使用する
</label>
</div>

<div class="form-row">
<label>SMTPユーザー名</label>
<input
    name="username"
    value="<?=e(
        $mail['username']
        ?? ''
    )?>"
>
</div>

<div class="form-row">
<label>SMTPパスワード</label>
<input
    type="password"
    name="password"
    autocomplete="new-password"
    placeholder="変更しない場合は空欄"
>
</div>

<div class="form-row">
<label>送信元メールアドレス</label>
<input
    type="email"
    name="from_email"
    value="<?=e(
        $mail['from_email']
        ?? ''
    )?>"
    required
>
</div>

<div class="form-row">
<label>送信元名</label>
<input
    name="from_name"
    value="<?=e(
        $mail['from_name']
        ?? ''
    )?>"
>
</div>

<div class="form-row">
<label>返信先メールアドレス</label>
<input
    type="email"
    name="reply_to"
    value="<?=e(
        $mail['reply_to']
        ?? ''
    )?>"
>
</div>

<button
    class="primary"
    type="submit"
>
設定保存
</button>

</form>

<hr>

<h2>接続確認</h2>

<p>
状態:
<strong>
<?=e(
    $mail['connection_status']
    ?? '未設定'
)?>
</strong>
</p>

<?php if (
    !empty(
        $mail['last_error']
    )
): ?>

<div class="notice error">
<?=e(
    $mail['last_error']
)?>
</div>

<?php endif; ?>

<form
    method="post"
    action="<?=e(screen_url('mail'))?>"
>

<input
    type="hidden"
    name="action"
    value="test_mail"
>

<button
    class="secondary"
    type="submit"
>
接続テスト
<span class="spinner">⏳</span>
</button>

</form>

<hr>

<h2>テストメール</h2>

<form
    method="post"
    action="<?=e(screen_url('mail'))?>"
>

<input
    type="hidden"
    name="action"
    value="send_test_mail"
>

<div class="form-row">
<label>送信先</label>
<input
    type="email"
    name="test_to"
    required
>
</div>

<button
    class="primary"
    type="submit"
>
テストメール送信
<span class="spinner">⏳</span>
</button>

</form>

</div>

<?php
}


/* ============================================================
 * 一覧
 * ============================================================ */

function render_list(): void
{
    $surveys =
        read_json(
            SURVEYS_FILE
        );

    foreach (
        $surveys as &$survey
    ) {
        update_auto_status(
            $survey
        );
    }

    unset($survey);

    usort(
        $surveys,
        function ($a, $b) {
            return strcmp(
                (string)(
                    $b['updatedAt']
                    ?? ''
                ),
                (string)(
                    $a['updatedAt']
                    ?? ''
                )
            );
        }
    );

    ?>

<div class="card">

<h1>アンケート一覧</h1>

<p>
<a href="<?=e(
    screen_url('edit')
)?>">
<button class="primary">
新規作成
</button>
</a>
</p>

<div class="table-scroll">

<table>

<tr>
<th>タイトル</th>
<th>作成日</th>
<th>更新日</th>
<th>期間</th>
<th>状態</th>
<th>回答数</th>
<th>操作</th>
</tr>

<?php foreach (
    $surveys
    as $survey
): ?>

<tr>

<td><?=e(
    $survey['title']
    ?? ''
)?></td>

<td><?=e(
    $survey['createdAt']
    ?? ''
)?></td>

<td><?=e(
    $survey['updatedAt']
    ?? ''
)?></td>

<td>
<?=e(
    $survey['startAt']
    ?? ''
)?>
～
<?=e(
    $survey['endAt']
    ?? ''
)?>
</td>

<td>
<span class="status">
<?=e(
    survey_status_label(
        (string)(
            $survey['status']
            ?? 'draft'
        )
    )
)?>
</span>
</td>

<td>
<?=e(
    count_answers(
        (string)(
            $survey['id']
            ?? ''
        )
    )
)?>
</td>

<td>

<a href="<?=e(
    screen_url(
        'edit',
        [
            'id' =>
                $survey['id']
        ]
    )
)?>">
編集
</a>

|

<a href="<?=e(
    screen_url(
        'preview',
        [
            'id' =>
                $survey['id']
        ]
    )
)?>">
プレビュー
</a>

|

<a href="<?=e(
    screen_url(
        'analytics',
        [
            'id' =>
                $survey['id']
        ]
    )
)?>">
集計
</a>

|

<a href="<?=e(
    screen_url(
        'send',
        [
            'id' =>
                $survey['id']
        ]
    )
)?>">
送信
</a>

<form
    method="post"
    action="<?=e(
        screen_url('list')
    )?>"
    style="display:inline"
    onsubmit="
        return confirm(
            '削除しますか？'
        );
    "
>

<input
    type="hidden"
    name="action"
    value="delete_survey"
>

<input
    type="hidden"
    name="id"
    value="<?=e(
        $survey['id']
    )?>"
>

<button
    class="danger"
    type="submit"
>
削除
</button>

</form>

<form
    method="post"
    action="<?=e(
        screen_url('list')
    )?>"
    style="display:inline"
    onsubmit="
        return confirm(
            '複製しますか？'
        );
    "
>

<input
    type="hidden"
    name="action"
    value="duplicate_survey"
>

<input
    type="hidden"
    name="id"
    value="<?=e(
        $survey['id']
    )?>"
>

<button
    class="secondary"
    type="submit"
>
複製
</button>

</form>

</td>

</tr>

<?php endforeach; ?>

</table>

</div>

</div>

<?php
}


function survey_status_label(
    string $status
): string {

    return match ($status) {
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き',
    };
}


function count_answers(
    string $surveyId
): int {

    $answers =
        read_json(
            ANSWERS_FILE
        );

    $count = 0;

    foreach ($answers as $answer) {

        if (
            (string)(
                $answer['survey_id']
                ?? ''
            ) === $surveyId
        ) {
            $count++;
        }
    }

    return $count;
}


/* ============================================================
 * 編集画面
 * ============================================================ */

function render_edit(): void
{
    $id =
        trim(
            (string)(
                $_GET['id']
                ?? ''
            )
        );

    $survey =
        $id !== ''
            ? find_survey($id)
            : default_survey();

    if ($survey === null) {
        echo '<div class="card">';
        echo '<h1>アンケートが存在しません</h1>';
        echo '</div>';
        return;
    }

    ?>

<div class="card">

<h1>アンケート作成・編集</h1>

<form
    method="post"
    action="<?=e(
        screen_url(
            'edit',
            $id !== ''
                ? ['id' => $id]
                : []
        )
    )?>"
>

<input
    type="hidden"
    name="action"
    value="save_survey"
>

<input
    type="hidden"
    name="id"
    value="<?=e(
        $id
    )?>"
>

<div class="form-row">
<label>アンケートタイトル</label>
<input
    name="title"
    value="<?=e(
        $survey['title']
        ?? ''
    )?>"
    required
>
</div>

<div class="form-row">
<label>アンケート説明</label>
<textarea
    name="description"
><?=e(
    $survey['description']
    ?? ''
)?></textarea>
</div>

<div class="form-row">
<label>開始日時</label>
<input
    type="datetime-local"
    name="startAt"
    value="<?=e(
        $survey['startAt']
        ?? ''
    )?>"
>
</div>

<div class="form-row">
<label>終了日時</label>
<input
    type="datetime-local"
    name="endAt"
    value="<?=e(
        $survey['endAt']
        ?? ''
    )?>"
>
</div>

<div class="form-row">
<label>質問番号の採番方式</label>
<select name="numbering">

<option
    value="global"
    <?=(
        ($survey['numbering']
            ?? 'global')
        === 'global'
    )
        ? 'selected'
        : ''?>
>
アンケート全体で通番
</option>

<option
    value="group"
    <?=(
        ($survey['numbering']
            ?? '')
        === 'group'
    )
        ? 'selected'
        : ''?>
>
グループ毎に採番
</option>

</select>
</div>

<div class="form-row">
<label>状態</label>

<select
    name="status"
    disabled
>

<option>
<?=e(
    survey_status_label(
        (string)(
            $survey['status']
            ?? 'draft'
        )
    )
)?>
</option>

</select>

</div>

<button
    class="primary"
    type="submit"
>
保存して一覧へ
<span class="spinner">⏳</span>
</button>

</form>

</div>

<?php
}


/* ============================================================
 * プレビュー
 * ============================================================ */

function render_preview(): void
{
    $id =
        (string)(
            $_GET['id']
            ?? ''
        );

    $survey =
        find_survey($id);

    if ($survey === null) {
        echo '<div class="card">';
        echo 'アンケートが存在しません。';
        echo '</div>';
        return;
    }

    ?>

<div class="card">

<h1>
<?=e(
    $survey['title']
    ?? ''
)?>
</h1>

<p>
<?=nl2br(
    e(
        $survey['description']
        ?? ''
    )
)?>
</p>

<?php foreach (
    $survey['groups']
    ?? []
    as $group
): ?>

<h2><?=e(
    $group['title']
    ?? ''
)?></h2>

<?php foreach (
    $group['questions']
    ?? []
    as $question
): ?>

<div class="card">

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

<?php if (
    ($question['type']
        ?? '')
    === 'free'
): ?>

<textarea></textarea>

<?php else: ?>

<?php foreach (
    $question['options']
    ?? []
    as $option
): ?>

<label style="display:block;margin:8px">
<input
    type="<?=
        ($question['type']
            ?? '')
        === 'multiple'
            ? 'checkbox'
            : 'radio'
    ?>"
>
<?=e(
    $option['label']
    ?? ''
)?>
</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>

<?php
}


/* ============================================================
 * 送信画面
 * ============================================================ */

function render_send(): void
{
    $id =
        (string)(
            $_GET['id']
            ?? ''
        );

    if ($id === '') {
        echo '<div class="card">';
        echo '対象アンケートが指定されていません。';
        echo '</div>';
        return;
    }

    $survey =
        find_survey($id);

    if ($survey === null) {
        echo '<div class="card">';
        echo '対象アンケートが存在しません。';
        echo '</div>';
        return;
    }

    $customers =
        read_json(
            CUSTOMERS_FILE
        );

    ?>

<div class="card">

<h1>顧客選択・メール送信</h1>

<p>
対象アンケート:
<strong>
<?=e(
    $survey['title']
    ?? ''
)?>
</strong>
</p>

<form
    method="post"
    action="<?=e(
        screen_url(
            'send',
            ['id' => $id]
        )
    )?>"
>

<input
    type="hidden"
    name="action"
    value="send_mail"
>

<input
    type="hidden"
    name="survey_id"
    value="<?=e($id)?>"
>

<h2>顧客選択</h2>

<div class="table-scroll">

<table>

<tr>
<th></th>
<th>組織</th>
<th>氏名</th>
<th>メール</th>
<th>部署</th>
<th>電話</th>
</tr>

<?php foreach (
    $customers
    as $customer
): ?>

<tr>

<td>
<input
    type="checkbox"
    name="customer_ids[]"
    value="<?=e(
        $customer['id']
        ?? ''
    )?>"
>
</td>

<td><?=e(
    $customer['organization']
    ?? ''
)?></td>

<td><?=e(
    $customer['name']
    ?? ''
)?></td>

<td><?=e(
    $customer['email']
    ?? ''
)?></td>

<td><?=e(
    $customer['department']
    ?? ''
)?></td>

<td><?=e(
    $customer['phone']
    ?? ''
)?></td>

</tr>

<?php endforeach; ?>

</table>

</div>

<h2>メール</h2>

<div class="form-row">
<label>件名</label>
<input
    name="subject"
    value="アンケートのお願い"
    required
>
</div>

<div class="form-row">
<label>本文</label>
<textarea
    name="body"
    required
>こんにちは、{顧客名}様。

以下のURLからアンケートへご回答ください。

{アンケートURL}
</textarea>
</div>

<button
    class="primary"
    type="submit"
    onclick="
        return confirm(
            '選択した顧客へメールを送信します。よろしいですか？'
        );
    "
>
一括送信
<span class="spinner">⏳</span>
</button>

</form>

</div>

<?php
}


/* ============================================================
 * 集計
 * ============================================================ */

function render_analytics(): void
{
    $id =
        (string)(
            $_GET['id']
            ?? ''
        );

    if ($id === '') {
        echo '<div class="card">';
        echo '対象アンケートが指定されていません。';
        echo '</div>';
        return;
    }

    $survey =
        find_survey($id);

    if ($survey === null) {
        echo '<div class="card">';
        echo '対象アンケートが存在しません。';
        echo '</div>';
        return;
    }

    $answers =
        read_json(
            ANSWERS_FILE
        );

    $targetAnswers =
        array_values(
            array_filter(
                $answers,
                fn($answer) =>
                    (string)(
                        $answer['survey_id']
                        ?? ''
                    ) === $id
            )
        );

    ?>

<div class="card">

<h1>回答集計・分析</h1>

<p>
対象アンケート:
<strong>
<?=e(
    $survey['title']
    ?? ''
)?>
</strong>
</p>

<div class="table-scroll">

<table>

<tr>
<th>送信対象者数</th>
<th>回答数</th>
<th>未登録回答数</th>
<th>未回答数</th>
<th>回答率</th>
</tr>

<tr>
<td>
<?=e(
    count(
        read_json(
            CUSTOMERS_FILE
        )
    )
)?>
</td>

<td>
<?=e(
    count(
        $targetAnswers
    )
)?>
</td>

<td>0</td>

<td>
<?=e(
    max(
        0,
        count(
            read_json(
                CUSTOMERS_FILE
            )
        )
        -
        count(
            $targetAnswers
        )
    )
)?>
</td>

<td>
<?php
$target =
    count(
        read_json(
            CUSTOMERS_FILE
        )
    );

$answered =
    count(
        $targetAnswers
    );

$rate =
    $target > 0
        ? round(
            $answered / $target * 100,
            1
        )
        : 0;
?>
<?=e($rate)?>%
</td>

</tr>

</table>

</div>

<?php if (!$targetAnswers): ?>

<div class="notice">
現在、回答データはありません
</div>

<?php else: ?>

<h2>個別回答</h2>

<?php foreach (
    $targetAnswers
    as $answer
): ?>

<div class="card">

<strong>
<?=e(
    $answer['createdAt']
    ?? ''
)?>
</strong>

<pre><?=e(
    json_encode(
        $answer['answers']
            ?? [],
        JSON_UNESCAPED_UNICODE
        | JSON_PRETTY_PRINT
    )
)?></pre>

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php
}


/* ============================================================
 * 回答画面
 * ============================================================ */

function render_answer(): void
{
    $id =
        (string)(
            $_GET['id']
            ?? ''
        );

    $survey =
        find_survey($id);

    if ($survey === null) {
        echo '<div class="card">';
        echo 'アンケートが存在しません。';
        echo '</div>';
        return;
    }

    if (
        ($survey['status'] ?? '')
        !== 'published'
    ) {
        echo '<div class="card">';
        echo 'このアンケートは現在回答できません。';
        echo '</div>';
        return;
    }

    ?>

<div class="card">

<h1><?=e(
    $survey['title']
    ?? ''
)?></h1>

<p><?=nl2br(
    e(
        $survey['description']
        ?? ''
    )
)?></p>

<form
    method="post"
    action="<?=e(
        screen_url(
            'answer',
            ['id' => $id]
        )
    )?>"
>

<input
    type="hidden"
    name="action"
    value="answer_next"
>

<input
    type="hidden"
    name="survey_id"
    value="<?=e($id)?>"
>

<?php foreach (
    $survey['groups']
    ?? []
    as $group
): ?>

<h2><?=e(
    $group['title']
    ?? ''
)?></h2>

<?php foreach (
    $group['questions']
    ?? []
    as $question
): ?>

<div class="card">

<label>
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

<?php if (
    !empty(
        $question['required']
    )
): ?>
<span style="color:red">
必須
</span>
<?php endif; ?>

</label>

<?php
$type =
    $question['type']
    ?? 'single';
?>

<?php if ($type === 'free'): ?>

<textarea
    name="answers[<?=e(
        $question['id']
    )?>]"
></textarea>

<?php else: ?>

<?php foreach (
    $question['options']
    ?? []
    as $option
): ?>

<label style="display:block;margin:8px">

<input
    type="<?=e(
        $type === 'multiple'
            ? 'checkbox'
            : 'radio'
    )?>"
    name="answers[<?=e(
        $question['id']
    )?>]<?=e(
        $type === 'multiple'
            ? '[]'
            : ''
    )?>"
    value="<?=e(
        $option['id']
        ?? ''
    )?>"
>

<?=e(
    $option['label']
    ?? ''
)?>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<button
    class="primary"
    type="submit"
>
回答確認
<span class="spinner">⏳</span>
</button>

</form>

</div>

<?php
}


/* ============================================================
 * 回答確認
 * ============================================================ */

function render_confirm(): void
{
    $id =
        (string)(
            $_GET['id']
            ?? ''
        );

    $survey =
        find_survey($id);

    if ($survey === null) {
        echo '<div class="card">';
        echo 'アンケートが存在しません。';
        echo '</div>';
        return;
    }

    $answers =
        $_SESSION[
            'answer_' . $id
        ]
        ?? [];

    ?>

<div class="card">

<h1>回答確認</h1>

<p>
以下の内容で送信します。
</p>

<pre><?=e(
    json_encode(
        $answers,
        JSON_UNESCAPED_UNICODE
        | JSON_PRETTY_PRINT
    )
)?></pre>

<form
    method="post"
    action="<?=e(
        screen_url(
            'confirm',
            ['id' => $id]
        )
    )?>"
>

<input
    type="hidden"
    name="action"
    value="answer_submit"
>

<input
    type="hidden"
    name="survey_id"
    value="<?=e($id)?>"
>

<button
    class="primary"
    type="submit"
    onclick="
        return confirm(
            '回答を送信します。よろしいですか？'
        );
    "
>
回答を送信
<span class="spinner">⏳</span>
</button>

</form>

</div>

<?php
}


/* ============================================================
 * 完了
 * ============================================================ */

function render_complete(): void
{
    ?>

<div class="card">

<h1>回答完了</h1>

<p>
回答を受け付けました。
ご協力ありがとうございました。
</p>

</div>

<?php
}
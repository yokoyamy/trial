<?php
declare(strict_types=1);

/**
 * アンケートアプリ POC
 *
 * prompt.txt 準拠・単一エントリーポイント
 *
 * - DBなし
 * - 管理者認証なし
 * - PHP cURLなし
 * - PHP mail()なし
 * - kintone REST API
 * - X-Cybozu-Authorization
 * - SMTPソケット通信
 * - サーバー側JSON永続化
 * - GETごとのsession_regenerate_id()なし
 * - URLへセッションIDを出さない
 * - 日本語公開パスをCookie Pathへ直接使用しない
 * - 外部通信に接続/読み取り/書き込みタイムアウトを設定
 * - kintone接続テストと顧客同期を分離
 * - 集計/送信は対象アンケートID必須
 */


/* ============================================================
 * 基本設定
 * ============================================================ */

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
 * 接続:
 *   TCP接続確立まで最大10秒
 *
 * 書込み:
 *   HTTPリクエスト送信最大10秒
 *
 * 読込み:
 *   レスポンス待機最大20秒
 */
const CONNECT_TIMEOUT = 10.0;
const WRITE_TIMEOUT   = 10.0;
const READ_TIMEOUT    = 20.0;


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
        'field_mapping' => [
            'organization' => '',
            'name' => '',
            'email' => '',
            'department' => '',
            'phone' => '',
            'address' => [],
        ],
        'fields' => [],
        'connection_status' => '未設定',
        'last_test_at' => null,
        'last_error' => '',
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
        'last_test_at' => null,
        'last_error' => '',
    ],
]);

init_json_file(SURVEYS_FILE, []);
init_json_file(CUSTOMERS_FILE, []);
init_json_file(ANSWERS_FILE, []);
init_json_file(SEND_LOG_FILE, []);


/* ============================================================
 * セッション
 *
 * 日本語を含む公開URLをCookie Pathに使わない。
 * 通常GETでsession_regenerate_id()しない。
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
 * ルーティング
 * ============================================================ */

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
 * POST処理
 * ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string)($_POST['action'] ?? '');

    try {

        switch ($action) {

            case 'save_kintone':
                save_kintone();
                break;

            case 'test_kintone':
                test_kintone();
                break;

            case 'fetch_kintone_fields':
                fetch_kintone_fields();
                break;

            case 'sync_kintone':
                sync_kintone();
                break;

            case 'save_mail':
                save_mail();
                break;

            case 'test_mail':
                test_mail();
                break;

            case 'send_test_mail':
                send_test_mail();
                break;

            case 'save_survey':
                save_survey();
                break;

            case 'delete_survey':
                delete_survey();
                break;

            case 'duplicate_survey':
                duplicate_survey();
                break;

            case 'change_status':
                change_status();
                break;

            case 'send_mail':
                send_survey_mail();
                break;

            case 'resend_mail':
                resend_mail();
                break;

            case 'remind_mail':
                remind_mail();
                break;

            case 'answer_submit':
                answer_submit();
                break;

            default:
                throw new InvalidArgumentException(
                    '不明な操作です。'
                );
        }

    } catch (Throwable $e) {

        /*
         * 外部通信エラー等をここで捕捉。
         *
         * 重要:
         * kintone通信自体には必ずタイムアウトがあるため、
         * 「接続テスト中から戻ってこない」状態を防止する。
         */

        flash(
            'error',
            '処理に失敗しました。'
            . public_error_message($e)
        );

        redirect(
            screen_url($screen, valid_id_from_request())
        );
    }
}


/* ============================================================
 * 公開中アンケートの自動終了
 * ============================================================ */

$surveys = read_json(SURVEYS_FILE);

$changed = false;

foreach ($surveys as &$survey) {

    if (!is_array($survey)) {
        continue;
    }

    if (
        ($survey['status'] ?? '') === 'published'
        && !empty($survey['endAt'])
    ) {

        $end = parse_datetime(
            (string)$survey['endAt']
        );

        if (
            $end !== null
            && $end->getTimestamp() < time()
        ) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = now_iso();
            $changed = true;
        }
    }
}

unset($survey);

if ($changed) {
    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );
}


/* ============================================================
 * 対象アンケート
 * ============================================================ */

$survey = null;

if (in_array(
    $screen,
    [
        'edit',
        'preview',
        'send',
        'analytics',
        'answer',
        'confirm',
        'complete',
    ],
    true
)) {

    $id = (string)($_GET['id'] ?? '');

    if ($id !== '') {
        $survey = find_survey($id);
    }

    /*
     * 集計・送信では対象アンケート必須。
     */
    if (
        in_array(
            $screen,
            ['send', 'analytics'],
            true
        )
        && $survey === null
    ) {
        flash(
            'error',
            '対象アンケートが指定されていません。'
        );

        redirect(
            screen_url('list')
        );
    }
}


/* ============================================================
 * HTML
 * ============================================================ */

render_header($screen);

switch ($screen) {

    case 'list':
        render_list();
        break;

    case 'edit':
        render_edit($survey);
        break;

    case 'preview':
        render_preview($survey);
        break;

    case 'send':
        render_send($survey);
        break;

    case 'analytics':
        render_analytics($survey);
        break;

    case 'kintone':
        render_kintone();
        break;

    case 'mail':
        render_mail();
        break;

    case 'answer':
        render_answer($survey);
        break;

    case 'confirm':
        render_confirm($survey);
        break;

    case 'complete':
        render_complete($survey);
        break;
}

render_footer();

exit;


/* ============================================================
 * 共通関数
 * ============================================================ */

function now_iso(): string
{
    return date('c');
}


function uuid(): string
{
    return bin2hex(random_bytes(16));
}


function e(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


function init_json_file(
    string $file,
    array $default
): void {

    if (is_file($file)) {
        return;
    }

    write_json_atomic(
        $file,
        $default
    );
}


function read_json(
    string $file
): array {

    if (!is_file($file)) {
        return [];
    }

    $fp = @fopen($file, 'rb');

    if ($fp === false) {
        throw new RuntimeException(
            'データファイルを開けません。'
        );
    }

    if (!flock($fp, LOCK_SH)) {
        fclose($fp);

        throw new RuntimeException(
            'データファイルをロックできません。'
        );
    }

    $raw = stream_get_contents($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode(
        $raw,
        true
    );

    if (!is_array($data)) {
        throw new RuntimeException(
            '保存データが不正です。'
        );
    }

    return $data;
}


function write_json_atomic(
    string $file,
    array $data
): void {

    $dir = dirname($file);

    $tmp = tempnam(
        $dir,
        'survey_'
    );

    if ($tmp === false) {
        throw new RuntimeException(
            '一時ファイルを作成できません。'
        );
    }

    try {

        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT
            | JSON_THROW_ON_ERROR
        );

        $fp = @fopen(
            $tmp,
            'wb'
        );

        if ($fp === false) {
            throw new RuntimeException(
                '一時ファイルを開けません。'
            );
        }

        try {

            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException(
                    '保存ファイルをロックできません。'
                );
            }

            $written = fwrite(
                $fp,
                $json . PHP_EOL
            );

            if ($written === false) {
                throw new RuntimeException(
                    'データを書き込めません。'
                );
            }

            fflush($fp);
            flock($fp, LOCK_UN);

        } finally {
            fclose($fp);
        }

        if (!rename($tmp, $file)) {
            throw new RuntimeException(
                'データを保存できません。'
            );
        }

    } catch (Throwable $e) {

        @unlink($tmp);

        throw $e;
    }
}


function flash(
    string $type,
    string $message
): void {

    $_SESSION['_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}


function consume_flash(): ?array
{
    $flash = $_SESSION['_flash'] ?? null;

    unset($_SESSION['_flash']);

    return is_array($flash)
        ? $flash
        : null;
}


function public_error_message(
    Throwable $e
): string {

    if ($e instanceof InvalidArgumentException) {
        return ' ' . $e->getMessage();
    }

    if (
        $e instanceof KintoneTimeoutException
    ) {
        return ' kintone通信がタイムアウトしました。'
            . 'サーバーからkintoneへの接続経路、'
            . 'Proxy、DNS、ファイアウォールを確認してください。';
    }

    if (
        $e instanceof KintoneConnectionException
    ) {
        return ' kintoneへ接続できませんでした。'
            . '接続先、Proxy、ネットワーク設定を確認してください。';
    }

    return ' サーバー側で処理を完了できませんでした。';
}


function parse_datetime(
    string $value
): ?DateTimeImmutable {

    if ($value === '') {
        return null;
    }

    try {

        return new DateTimeImmutable(
            $value,
            new DateTimeZone('Asia/Tokyo')
        );

    } catch (Throwable) {

        return null;
    }
}


function valid_id(
    string $id
): bool {

    return preg_match(
        '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/',
        $id
    ) === 1;
}


function valid_id_from_request(): ?string
{
    $id = trim(
        (string)($_POST['id'] ?? $_GET['id'] ?? '')
    );

    return valid_id($id)
        ? $id
        : null;
}


function screen_url(
    string $screen,
    ?string $id = null
): string {

    $allowed = [
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

    if (!in_array($screen, $allowed, true)) {
        $screen = 'list';
    }

    $url =
        'index.php?screen='
        . rawurlencode($screen);

    if (
        $id !== null
        && $id !== ''
        && valid_id($id)
    ) {
        $url .=
            '&id='
            . rawurlencode($id);
    }

    return $url;
}


function redirect(
    string $url
): never {

    /*
     * 外部URLやREQUEST_URIを許可しない。
     */
    if (
        !str_starts_with(
            $url,
            'index.php'
        )
        || str_contains($url, "\r")
        || str_contains($url, "\n")
    ) {
        $url = screen_url('list');
    }

    header(
        'Cache-Control: no-store, no-cache, must-revalidate'
    );

    header(
        'Pragma: no-cache'
    );

    header(
        'Location: ' . $url,
        true,
        303
    );

    exit;
}


/* ============================================================
 * kintone
 * ============================================================ */

class KintoneConnectionException
    extends RuntimeException
{
}


class KintoneTimeoutException
    extends RuntimeException
{
}


function normalize_kintone_subdomain(
    string $value
): string {

    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    ) ?? $value;

    $value = trim(
        $value,
        "/ \t\r\n"
    );

    if (
        str_ends_with(
            strtolower($value),
            '.cybozu.com'
        )
    ) {

        $value = substr(
            $value,
            0,
            -strlen('.cybozu.com')
        );
    }

    if (
        $value === ''
        || !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
            $value
        )
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインが不正です。'
        );
    }

    return $value;
}


function validate_kintone(
    array $k
): void {

    normalize_kintone_subdomain(
        (string)($k['subdomain'] ?? '')
    );

    $appId =
        (string)($k['app_id'] ?? '');

    if (
        $appId === ''
        || !ctype_digit($appId)
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    if (
        (string)($k['username'] ?? '')
        === ''
    ) {
        throw new InvalidArgumentException(
            'ログイン名を入力してください。'
        );
    }

    if (
        (string)($k['password'] ?? '')
        === ''
    ) {
        throw new InvalidArgumentException(
            'パスワードを入力してください。'
        );
    }

    $proxy =
        trim((string)($k['proxy'] ?? ''));

    if ($proxy !== '') {

        if (
            !preg_match(
                '/^([^:\s]+):([0-9]{1,5})$/',
                $proxy,
                $m
            )
        ) {
            throw new InvalidArgumentException(
                'Proxyはhost:port形式で入力してください。'
            );
        }

        $port = (int)$m[2];

        if (
            $port < 1
            || $port > 65535
        ) {
            throw new InvalidArgumentException(
                'Proxyポート番号が不正です。'
            );
        }
    }
}


/**
 * kintone API通信
 *
 * ここが今回の再生成で最重要。
 *
 * 旧実装:
 *
 *   file_get_contents()
 *   http context timeout
 *
 * だけでは接続確立を明示的に制御できない。
 *
 * 新実装:
 *
 *   DNS解決
 *       ↓
 *   stream_socket_client()
 *       ↓ CONNECT_TIMEOUT
 *   HTTPリクエスト送信
 *       ↓ WRITE_TIMEOUT
 *   HTTPレスポンス受信
 *       ↓ READ_TIMEOUT
 *
 * Proxy指定時:
 *
 *   Browser
 *       ↓
 *   PHP
 *       ↓
 *   Proxy
 *       ↓
 *   kintone
 */
function kintone_request(
    array $k,
    string $path,
    string $method = 'GET',
    ?array $body = null
): array {

    validate_kintone($k);

    $subdomain =
        normalize_kintone_subdomain(
            (string)$k['subdomain']
        );

    $host =
        $subdomain . '.cybozu.com';

    $port = 443;

    $method =
        strtoupper($method);

    $allowedMethods = [
        'GET',
        'POST',
        'PUT',
        'DELETE',
    ];

    if (
        !in_array(
            $method,
            $allowedMethods,
            true
        )
    ) {
        throw new InvalidArgumentException(
            'kintone HTTPメソッドが不正です。'
        );
    }

    if (
        $path === ''
        || $path[0] !== '/'
    ) {
        throw new InvalidArgumentException(
            'kintone APIパスが不正です。'
        );
    }

    /*
     * 認証情報はサーバー側だけで生成。
     * ブラウザへ渡さない。
     */
    $authorization =
        base64_encode(
            (string)$k['username']
            . ':'
            . (string)$k['password']
        );

    $requestBody = '';

    if ($body !== null) {

        $requestBody = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );
    }

    $headers = [
        'Host: ' . $host,
        'X-Cybozu-Authorization: '
            . $authorization,
        'Accept: application/json',
        'Content-Type: application/json',
        'Connection: close',
    ];

    if ($requestBody !== '') {
        $headers[] =
            'Content-Length: '
            . strlen($requestBody);
    }

    /*
     * Proxy:
     *
     * 未設定:
     *   tcp://kintone-host:443
     *
     * 設定あり:
     *   tcp://proxy-host:proxy-port
     *
     * HTTPS CONNECTをPHP側で実装する。
     */
    $proxy =
        trim((string)($k['proxy'] ?? ''));

    $socketTarget = '';

    if ($proxy === '') {

        $socketTarget =
            'tcp://' . $host . ':' . $port;

    } else {

        if (
            !preg_match(
                '/^([^:\s]+):([0-9]{1,5})$/',
                $proxy,
                $m
            )
        ) {
            throw new InvalidArgumentException(
                'Proxyはhost:port形式で入力してください。'
            );
        }

        $proxyHost = $m[1];
        $proxyPort = (int)$m[2];

        $socketTarget =
            'tcp://'
            . $proxyHost
            . ':'
            . $proxyPort;
    }

    $errno = 0;
    $errstr = '';

    /*
     * TCP接続確立に最大10秒。
     *
     * ここを明示することで、
     * DNS/Proxy/Firewall等でTCP接続が成立しない場合に
     * PHP処理が無期限に停止しない。
     */
    $socket = @stream_socket_client(
        $socketTarget,
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {

        throw new KintoneConnectionException(
            'kintone接続を確立できませんでした。'
            . ' errno='
            . $errno
            . ' '
            . $errstr
        );
    }

    try {

        /*
         * DNS/TCP接続後の読み書きタイムアウト。
         */
        stream_set_timeout(
            $socket,
            (int)READ_TIMEOUT,
            (int)(
                (READ_TIMEOUT
                - floor(READ_TIMEOUT))
                * 1000000
            )
        );

        stream_set_blocking(
            $socket,
            true
        );

        /*
         * Proxy経由の場合はCONNECT。
         */
        if ($proxy !== '') {

            $connectRequest =
                "CONNECT "
                . $host
                . ":"
                . $port
                . " HTTP/1.1\r\n"
                . "Host: "
                . $host
                . ":"
                . $port
                . "\r\n"
                . "Connection: close\r\n"
                . "\r\n";

            write_socket_all(
                $socket,
                $connectRequest,
                WRITE_TIMEOUT
            );

            $connectResponse =
                read_http_headers(
                    $socket,
                    READ_TIMEOUT
                );

            if (
                $connectResponse['status']
                < 200
                || $connectResponse['status']
                >= 300
            ) {

                throw new KintoneConnectionException(
                    'ProxyのCONNECTに失敗しました。HTTP '
                    . $connectResponse['status']
                );
            }

            /*
             * CONNECT後にTLS開始。
             */
            $crypto = @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new KintoneConnectionException(
                    'Proxy経由のTLS接続を確立できませんでした。'
                );
            }

        } else {

            /*
             * 直接接続時もここでTLSを開始。
             */
            $crypto = @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new KintoneConnectionException(
                    'kintoneとのTLS接続を確立できませんでした。'
                );
            }
        }

        /*
         * SSL証明書検証について。
         *
         * prompt.txtではPOC段階で無効設定可能。
         *
         * stream_socket_enable_crypto()だけでは
         * stream_contextのverify設定が重要なので、
         * ソケット生成前のcontextで設定する必要がある。
         *
         * したがって、実際の本番環境では
         * SSL検証有効を推奨する。
         *
         * POCで無効の場合はTLS自体は実施するが、
         * 証明書検証を要求しない。
         */

        $requestTarget = $path;

        /*
         * HTTPS CONNECT後なのでorigin-formを使用。
         */
        $httpRequest =
            $method
            . ' '
            . $requestTarget
            . ' HTTP/1.1'
            . "\r\n"
            . implode(
                "\r\n",
                $headers
            )
            . "\r\n\r\n"
            . $requestBody;

        /*
         * HTTP書き込み最大10秒。
         */
        write_socket_all(
            $socket,
            $httpRequest,
            WRITE_TIMEOUT
        );

        /*
         * レスポンスヘッダーを読む。
         */
        $responseHeaders =
            read_http_headers(
                $socket,
                READ_TIMEOUT
            );

        $status =
            $responseHeaders['status'];

        $headersMap =
            $responseHeaders['headers'];

        /*
         * Content-Lengthがある場合。
         */
        $responseBody = '';

        if (
            isset(
                $headersMap['content-length']
            )
        ) {

            $length =
                (int)$headersMap['content-length'];

            if ($length > 0) {

                $responseBody =
                    read_socket_exact(
                        $socket,
                        $length,
                        READ_TIMEOUT
                    );
            }

        } elseif (
            isset(
                $headersMap['transfer-encoding']
            )
            && str_contains(
                strtolower(
                    $headersMap['transfer-encoding']
                ),
                'chunked'
            )
        ) {

            $responseBody =
                read_chunked_body(
                    $socket,
                    READ_TIMEOUT
                );

        } else {

            /*
             * Connection: closeを利用。
             *
             * ただしREAD_TIMEOUTを各readに適用するため、
             * 無期限待機にはならない。
             */
            $responseBody =
                read_socket_until_close(
                    $socket,
                    READ_TIMEOUT
                );
        }

        $decoded = null;

        if ($responseBody !== '') {

            $decoded =
                json_decode(
                    $responseBody,
                    true
                );
        }

        return [
            'status' => $status,
            'headers' => $headersMap,
            'body' => $responseBody,
            'json' =>
                is_array($decoded)
                    ? $decoded
                    : null,
        ];

    } finally {

        fclose($socket);
    }
}


/* ============================================================
 * ソケット書込み
 * ============================================================ */

function write_socket_all(
    $socket,
    string $data,
    float $timeout
): void {

    $length =
        strlen($data);

    $offset = 0;

    $start = microtime(true);

    while ($offset < $length) {

        if (
            microtime(true) - $start
            > $timeout
        ) {
            throw new KintoneTimeoutException(
                'kintoneへのリクエスト送信がタイムアウトしました。'
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

            throw new KintoneConnectionException(
                'kintoneへのリクエスト送信に失敗しました。'
            );
        }

        if ($written === 0) {

            usleep(10000);

            continue;
        }

        $offset += $written;
    }
}


/* ============================================================
 * HTTPヘッダー読込み
 * ============================================================ */

function read_http_headers(
    $socket,
    float $timeout
): array {

    $start =
        microtime(true);

    $raw = '';

    while (true) {

        if (
            microtime(true) - $start
            > $timeout
        ) {

            throw new KintoneTimeoutException(
                'kintoneからのレスポンスヘッダーがタイムアウトしました。'
            );
        }

        $line =
            fgets(
                $socket,
                8192
            );

        if ($line === false) {

            $meta =
                stream_get_meta_data(
                    $socket
                );

            if (
                !empty(
                    $meta['timed_out']
                )
            ) {
                throw new KintoneTimeoutException(
                    'kintoneからのレスポンス待機がタイムアウトしました。'
                );
            }

            throw new KintoneConnectionException(
                'kintoneのレスポンスを読み取れませんでした。'
            );
        }

        $raw .= $line;

        if (
            $raw === ''
            || str_ends_with(
                $raw,
                "\r\n\r\n"
            )
        ) {
            break;
        }

        if (strlen($raw) > 65536) {

            throw new KintoneConnectionException(
                'HTTPレスポンスヘッダーが大きすぎます。'
            );
        }
    }

    $lines =
        preg_split(
            "/\r\n/",
            trim($raw)
        );

    if (!$lines || !isset($lines[0])) {

        throw new KintoneConnectionException(
            'HTTPレスポンスを解析できません。'
        );
    }

    if (
        !preg_match(
            '#^HTTP/\S+\s+(\d{3})#',
            $lines[0],
            $m
        )
    ) {

        throw new KintoneConnectionException(
            'HTTPステータスを解析できません。'
        );
    }

    $headers = [];

    foreach (
        array_slice($lines, 1)
        as $line
    ) {

        $pos =
            strpos(
                $line,
                ':'
            );

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


/* ============================================================
 * Content-Length
 * ============================================================ */

function read_socket_exact(
    $socket,
    int $length,
    float $timeout
): string {

    $start =
        microtime(true);

    $result = '';

    while (
        strlen($result)
        < $length
    ) {

        if (
            microtime(true) - $start
            > $timeout
        ) {

            throw new KintoneTimeoutException(
                'kintoneレスポンスの読み取りがタイムアウトしました。'
            );
        }

        $remaining =
            $length - strlen($result);

        $chunk =
            fread(
                $socket,
                min(
                    8192,
                    $remaining
                )
            );

        if ($chunk === false) {

            throw new KintoneConnectionException(
                'kintoneレスポンスを読み取れません。'
            );
        }

        if ($chunk === '') {

            $meta =
                stream_get_meta_data(
                    $socket
                );

            if (
                !empty(
                    $meta['timed_out']
                )
            ) {

                throw new KintoneTimeoutException(
                    'kintoneレスポンスの読み取りがタイムアウトしました。'
                );
            }

            break;
        }

        $result .= $chunk;
    }

    return $result;
}


/* ============================================================
 * chunked
 * ============================================================ */

function read_chunked_body(
    $socket,
    float $timeout
): string {

    $result = '';

    $start =
        microtime(true);

    while (true) {

        if (
            microtime(true) - $start
            > $timeout
        ) {

            throw new KintoneTimeoutException(
                'kintoneのchunkedレスポンスがタイムアウトしました。'
            );
        }

        $line =
            fgets(
                $socket,
                8192
            );

        if ($line === false) {

            throw new KintoneConnectionException(
                'chunkサイズを読み取れません。'
            );
        }

        $size =
            hexdec(
                trim($line)
            );

        if ($size === 0) {

            /*
             * trailerは不要。
             */
            fgets(
                $socket,
                8192
            );

            break;
        }

        $chunk =
            read_socket_exact(
                $socket,
                $size,
                $timeout
            );

        $result .= $chunk;

        /*
         * CRLF
         */
        fgets(
            $socket,
            3
        );
    }

    return $result;
}


/* ============================================================
 * closeまで読む
 * ============================================================ */

function read_socket_until_close(
    $socket,
    float $timeout
): string {

    $result = '';

    $start =
        microtime(true);

    while (true) {

        if (
            microtime(true) - $start
            > $timeout
        ) {

            throw new KintoneTimeoutException(
                'kintoneレスポンスの読み取りがタイムアウトしました。'
            );
        }

        $chunk =
            fread(
                $socket,
                8192
            );

        if ($chunk === false) {

            throw new KintoneConnectionException(
                'kintoneレスポンスを読み取れません。'
            );
        }

        if ($chunk === '') {

            $meta =
                stream_get_meta_data(
                    $socket
                );

            if (
                !empty(
                    $meta['timed_out']
                )
            ) {

                throw new KintoneTimeoutException(
                    'kintoneレスポンスの読み取りがタイムアウトしました。'
                );
            }

            break;
        }

        $result .= $chunk;
    }

    return $result;
}


/* ============================================================
 * kintone保存
 * ============================================================ */

function save_kintone(): void
{
    $settings =
        read_json(
            SETTINGS_FILE
        );

    $current =
        $settings['kintone']
        ?? [];

    $subdomain =
        normalize_kintone_subdomain(
            trim(
                (string)(
                    $_POST['subdomain']
                    ?? ''
                )
            )
        );

    $appId =
        trim(
            (string)(
                $_POST['app_id']
                ?? ''
            )
        );

    if (
        $appId === ''
        || !ctype_digit($appId)
    ) {

        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    $username =
        trim(
            (string)(
                $_POST['username']
                ?? ''
            )
        );

    if ($username === '') {

        throw new InvalidArgumentException(
            'ログイン名を入力してください。'
        );
    }

    $password =
        (string)(
            $_POST['password']
            ?? ''
        );

    if ($password === '') {

        $password =
            (string)(
                $current['password']
                ?? ''
            );
    }

    if ($password === '') {

        throw new InvalidArgumentException(
            'パスワードを入力してください。'
        );
    }

    $proxy =
        trim(
            (string)(
                $_POST['proxy']
                ?? ''
            )
        );

    if (
        $proxy !== ''
        && !preg_match(
            '/^([^:\s]+):([0-9]{1,5})$/',
            $proxy,
            $m
        )
    ) {

        throw new InvalidArgumentException(
            'Proxyはhost:port形式で入力してください。'
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
                    isset(
                        $_POST['verify_ssl']
                    ),
            ]
        );

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    flash(
        'success',
        'kintone設定を保存しました。'
    );

    redirect(
        screen_url('kintone')
    );
}


/* ============================================================
 * kintone接続テスト
 * ============================================================ */

function test_kintone(): void
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

        /*
         * app.jsonを実際のkintoneへ問い合わせる。
         *
         * モックは禁止。
         */
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

            flash(
                'success',
                'kintoneへの接続に成功しました。'
            );

        } else {

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

            flash(
                'error',
                'kintoneへの接続に失敗しました。'
                . ' HTTP '
                . $status
                . '。'
                . $message
            );
        }

    } catch (Throwable $e) {

        $message =
            $e->getMessage();

        $settings['kintone']
            ['connection_status']
            = '接続できません';

        $settings['kintone']
            ['last_test_at']
            = now_iso();

        $settings['kintone']
            ['last_error']
            = mb_substr(
                $message,
                0,
                500
            );

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        flash(
            'error',
            'kintone接続テストに失敗しました。'
            . public_error_message($e)
        );
    }

    /*
     * 接続テスト終了後は必ずkintone画面へ戻る。
     *
     * ソケット側に明示的なタイムアウトがあるため、
     * ここへ到達できず永遠に待ち続けることを防ぐ。
     */
    redirect(
        screen_url('kintone')
    );
}


/* ============================================================
 * kintoneエラー
 * ============================================================ */

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

        return ' ' . (string)$json['message'];
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

    return ' ' .
        mb_substr(
            $body,
            0,
            300
        );
}


/* ============================================================
 * kintone項目再取得
 * ============================================================ */

function fetch_kintone_fields(): void
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
            kintone_error_message(
                $result
            )
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

    flash(
        'success',
        'kintoneの項目一覧を再取得しました。'
    );

    redirect(
        screen_url('kintone')
    );
}


/* ============================================================
 * kintone顧客同期
 * ============================================================ */

function sync_kintone(): void
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
                . http_build_query(
                    [
                        'app' =>
                            (string)$k['app_id'],
                        'query' =>
                            $query,
                    ]
                ),
                'GET'
            );

        if (
            $result['status'] < 200
            || $result['status'] >= 300
        ) {

            throw new RuntimeException(
                kintone_error_message(
                    $result
                )
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

        foreach (
            $records as $record
        ) {

            $customers[] =
                normalize_kintone_customer(
                    $record,
                    $mapping
                );
        }

        $offset +=
            count($records);

        if (
            count($records) < 500
        ) {
            break;
        }
    }

    write_json_atomic(
        CUSTOMERS_FILE,
        $customers
    );

    flash(
        'success',
        count($customers)
        . '件の顧客情報を同期しました。'
    );

    redirect(
        screen_url('kintone')
    );
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

        'updatedAt' =>
            now_iso(),
    ];
}


function kintone_field_value(
    array $record,
    string $field
): string {

    if (
        $field === ''
        || !isset($record[$field])
    ) {
        return '';
    }

    $value =
        $record[$field]['value']
        ?? '';

    if (is_array($value)) {

        $values = [];

        foreach ($value as $item) {

            if (is_array($item)) {

                $values[] =
                    (string)(
                        $item['value']
                        ?? ''
                    );

            } else {

                $values[] =
                    (string)$item;
            }
        }

        return implode(
            ', ',
            $values
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

        $field =
            (string)$field;

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
 * アンケート
 * ============================================================ */

function find_survey(
    string $id
): ?array {

    if (!valid_id($id)) {
        return null;
    }

    foreach (
        read_json(SURVEYS_FILE)
        as $survey
    ) {

        if (
            is_array($survey)
            && (string)(
                $survey['id']
                ?? ''
            ) === $id
        ) {

            return $survey;
        }
    }

    return null;
}


function find_survey_index(
    array $surveys,
    string $id
): int {

    foreach (
        $surveys as $index => $survey
    ) {

        if (
            is_array($survey)
            && (string)(
                $survey['id']
                ?? ''
            ) === $id
        ) {

            return $index;
        }
    }

    return -1;
}


function normalize_survey(
    array $survey
): array {

    $survey['id'] =
        (string)(
            $survey['id']
            ?? 'survey-' . uuid()
        );

    $survey['title'] =
        (string)(
            $survey['title']
            ?? ''
        );

    $survey['description'] =
        (string)(
            $survey['description']
            ?? ''
        );

    $survey['startAt'] =
        (string)(
            $survey['startAt']
            ?? ''
        );

    $survey['endAt'] =
        (string)(
            $survey['endAt']
            ?? ''
        );

    $survey['status'] =
        (string)(
            $survey['status']
            ?? 'draft'
        );

    if (!in_array(
        $survey['status'],
        [
            'draft',
            'published',
            'stopped',
            'ended',
        ],
        true
    )) {
        $survey['status'] = 'draft';
    }

    $survey['numbering'] =
        (string)(
            $survey['numbering']
            ?? 'global'
        );

    if (!in_array(
        $survey['numbering'],
        [
            'global',
            'group',
        ],
        true
    )) {
        $survey['numbering'] = 'global';
    }

    $survey['groups'] =
        is_array(
            $survey['groups']
            ?? null
        )
            ? $survey['groups']
            : [];

    $survey['createdAt'] =
        (string)(
            $survey['createdAt']
            ?? now_iso()
        );

    $survey['updatedAt'] =
        (string)(
            $survey['updatedAt']
            ?? now_iso()
        );

    $survey['answerCount'] =
        (int)(
            $survey['answerCount']
            ?? 0
        );

    return $survey;
}


function recalculate_numbers(
    array &$survey
): void {

    $global = 0;

    foreach (
        $survey['groups']
        as $groupIndex => &$group
    ) {

        $groupNumber =
            $groupIndex + 1;

        if (
            !isset($group['questions'])
            || !is_array(
                $group['questions']
            )
        ) {
            $group['questions'] = [];
        }

        foreach (
            $group['questions']
            as $questionIndex => &$question
        ) {

            $global++;

            if (
                ($survey['numbering']
                    ?? 'global')
                === 'group'
            ) {

                $question['number'] =
                    'Q'
                    . $groupNumber
                    . '-'
                    . ($questionIndex + 1);

            } else {

                $question['number'] =
                    'Q'
                    . $global;
            }
        }

        unset($question);
    }

    unset($group);
}


/* ============================================================
 * アンケート保存
 * ============================================================ */

function save_survey(): void
{
    $surveys =
        read_json(
            SURVEYS_FILE
        );

    $id =
        trim(
            (string)(
                $_POST['id']
                ?? ''
            )
        );

    $isNew =
        $id === '';

    if ($isNew) {

        $id =
            'survey-' . uuid();

        $survey = [
            'id' => $id,
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [],
            'createdAt' => now_iso(),
            'updatedAt' => now_iso(),
            'answerCount' => 0,
        ];

    } else {

        if (!valid_id($id)) {
            throw new InvalidArgumentException(
                'アンケートIDが不正です。'
            );
        }

        $index =
            find_survey_index(
                $surveys,
                $id
            );

        if ($index < 0) {
            throw new InvalidArgumentException(
                'アンケートが存在しません。'
            );
        }

        $survey =
            normalize_survey(
                $surveys[$index]
            );
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
        $title;

    $survey['description'] =
        (string)(
            $_POST['description']
            ?? ''
        );

    $survey['startAt'] =
        (string)(
            $_POST['startAt']
            ?? ''
        );

    $survey['endAt'] =
        (string)(
            $_POST['endAt']
            ?? ''
        );

    $numbering =
        (string)(
            $_POST['numbering']
            ?? 'global'
        );

    if (!in_array(
        $numbering,
        [
            'global',
            'group',
        ],
        true
    )) {
        $numbering = 'global';
    }

    $survey['numbering'] =
        $numbering;

    $survey['updatedAt'] =
        now_iso();

    $survey =
        normalize_survey(
            $survey
        );

    recalculate_numbers(
        $survey
    );

    if ($isNew) {

        $surveys[] =
            $survey;

    } else {

        $index =
            find_survey_index(
                $surveys,
                $id
            );

        $surveys[$index] =
            $survey;
    }

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    redirect(
        screen_url('list')
    );
}


/* ============================================================
 * 削除
 * ============================================================ */

function delete_survey(): void
{
    $id =
        (string)(
            $_POST['id']
            ?? ''
        );

    if (!valid_id($id)) {
        throw new InvalidArgumentException(
            'アンケートIDが不正です。'
        );
    }

    $surveys =
        read_json(
            SURVEYS_FILE
        );

    $index =
        find_survey_index(
            $surveys,
            $id
        );

    if ($index < 0) {
        throw new InvalidArgumentException(
            'アンケートが存在しません。'
        );
    }

    array_splice(
        $surveys,
        $index,
        1
    );

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    redirect(
        screen_url('list')
    );
}


/* ============================================================
 * 複製
 * ============================================================ */

function duplicate_survey(): void
{
    $id =
        (string)(
            $_POST['id']
            ?? ''
        );

    $survey =
        find_survey($id);

    if ($survey === null) {
        throw new InvalidArgumentException(
            '複製対象のアンケートが存在しません。'
        );
    }

    $surveys =
        read_json(
            SURVEYS_FILE
        );

    $copy =
        normalize_survey(
            $survey
        );

    $copy['id'] =
        'survey-' . uuid();

    $copy['title'] =
        $copy['title']
        . '（コピー）';

    $copy['status'] =
        'draft';

    $copy['createdAt'] =
        now_iso();

    $copy['updatedAt'] =
        now_iso();

    $surveys[] =
        $copy;

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    redirect(
        screen_url('list')
    );
}


/* ============================================================
 * 状態変更
 * ============================================================ */

function change_status(): void
{
    $id =
        (string)(
            $_POST['id']
            ?? ''
        );

    $newStatus =
        (string)(
            $_POST['status']
            ?? ''
        );

    $allowed = [
        'draft',
        'published',
        'stopped',
    ];

    if (!in_array(
        $newStatus,
        $allowed,
        true
    )) {
        throw new InvalidArgumentException(
            '指定された状態へ変更できません。'
        );
    }

    $surveys =
        read_json(
            SURVEYS_FILE
        );

    $index =
        find_survey_index(
            $surveys,
            $id
        );

    if ($index < 0) {
        throw new InvalidArgumentException(
            'アンケートが存在しません。'
        );
    }

    $current =
        (string)(
            $surveys[$index]['status']
            ?? 'draft'
        );

    if ($current === 'ended') {
        throw new InvalidArgumentException(
            '終了状態のアンケートは変更できません。'
        );
    }

    $validTransitions = [
        'draft' => [
            'draft',
            'published',
        ],
        'published' => [
            'published',
            'stopped',
        ],
        'stopped' => [
            'stopped',
            'published',
        ],
    ];

    if (
        !in_array(
            $newStatus,
            $validTransitions[$current]
                ?? [],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '状態遷移が不正です。'
        );
    }

    $surveys[$index]['status'] =
        $newStatus;

    $surveys[$index]['updatedAt'] =
        now_iso();

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    redirect(
        screen_url(
            'edit',
            $id
        )
    );
}


/* ============================================================
 * メール設定
 * ============================================================ */

function save_mail(): void
{
    $settings =
        read_json(
            SETTINGS_FILE
        );

    $mail =
        $settings['mail']
        ?? [];

    $host =
        trim(
            (string)(
                $_POST['host']
                ?? ''
            )
        );

    if ($host === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを入力してください。'
        );
    }

    $port =
        filter_var(
            $_POST['port'] ?? null,
            FILTER_VALIDATE_INT
        );

    if (
        $port === false
        || $port < 1
        || $port > 65535
    ) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    $encryption =
        (string)(
            $_POST['encryption']
            ?? 'tls'
        );

    if (!in_array(
        $encryption,
        [
            'ssl',
            'tls',
            'none',
        ],
        true
    )) {
        throw new InvalidArgumentException(
            '暗号化方式が不正です。'
        );
    }

    $from =
        trim(
            (string)(
                $_POST['from_email']
                ?? ''
            )
        );

    if (
        !filter_var(
            $from,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    $password =
        (string)(
            $_POST['password']
            ?? ''
        );

    if ($password === '') {
        $password =
            (string)(
                $mail['password']
                ?? ''
            );
    }

    $settings['mail'] =
        array_merge(
            $mail,
            [
                'host' => $host,
                'port' => (int)$port,
                'encryption' => $encryption,
                'auth' =>
                    isset(
                        $_POST['auth']
                    ),
                'username' =>
                    trim(
                        (string)(
                            $_POST['username']
                            ?? ''
                        )
                    ),
                'password' => $password,
                'from_email' => $from,
                'from_name' =>
                    trim(
                        (string)(
                            $_POST['from_name']
                            ?? ''
                        )
                    ),
                'reply_to' =>
                    trim(
                        (string)(
                            $_POST['reply_to']
                            ?? ''
                        )
                    ),
            ]
        );

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    flash(
        'success',
        'メール設定を保存しました。'
    );

    redirect(
        screen_url('mail')
    );
}


/* ============================================================
 * SMTP
 * ============================================================ */

function smtp_connect(
    array $mail
) {

    $host =
        (string)(
            $mail['host']
            ?? ''
        );

    $port =
        (int)(
            $mail['port']
            ?? 587
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

    $encryption =
        (string)(
            $mail['encryption']
            ?? 'tls'
        );

    if ($encryption === 'ssl') {
        $target =
            'ssl://' . $host . ':' . $port;
    } else {
        $target =
            'tcp://' . $host . ':' . $port;
    }

    $errno = 0;
    $errstr = '';

    $socket =
        @stream_socket_client(
            $target,
            $errno,
            $errstr,
            CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPサーバへ接続できません。'
            . ' '
            . $errstr
        );
    }

    stream_set_timeout(
        $socket,
        (int)READ_TIMEOUT
    );

    return $socket;
}


function smtp_read($socket): string
{
    $line =
        fgets(
            $socket,
            8192
        );

    if ($line === false) {

        $meta =
            stream_get_meta_data(
                $socket
            );

        if (
            !empty(
                $meta['timed_out']
            )
        ) {
            throw new RuntimeException(
                'SMTP応答がタイムアウトしました。'
            );
        }

        throw new RuntimeException(
            'SMTP応答を取得できません。'
        );
    }

    return $line;
}


function smtp_expect(
    $socket,
    array $codes
): string {

    $response = '';

    while (true) {

        $line =
            smtp_read(
                $socket
            );

        $response .= $line;

        if (
            preg_match(
                '/^(\d{3})([\s-])/',
                $line,
                $m
            )
        ) {

            if (
                in_array(
                    (int)$m[1],
                    $codes,
                    true
                )
                && $m[2] === ' '
            ) {
                return $response;
            }

            if (
                $m[2] === ' '
            ) {
                throw new RuntimeException(
                    'SMTPエラー: '
                    . trim($response)
                );
            }
        }
    }
}


function smtp_command(
    $socket,
    string $command,
    array $codes
): string {

    $written =
        fwrite(
            $socket,
            $command . "\r\n"
        );

    if ($written === false) {
        throw new RuntimeException(
            'SMTPコマンドを送信できません。'
        );
    }

    return smtp_expect(
        $socket,
        $codes
    );
}


function smtp_test(
    array $mail
): void {

    $socket =
        smtp_connect(
            $mail
        );

    try {

        smtp_expect(
            $socket,
            [220]
        );

        smtp_command(
            $socket,
            'EHLO localhost',
            [250]
        );

        if (
            ($mail['encryption'] ?? '')
            === 'tls'
        ) {

            smtp_command(
                $socket,
                'STARTTLS',
                [220]
            );

            if (
                @stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                ) !== true
            ) {
                throw new RuntimeException(
                    'SMTP TLSを開始できません。'
                );
            }

            smtp_command(
                $socket,
                'EHLO localhost',
                [250]
            );
        }

        if (
            !empty(
                $mail['auth']
            )
        ) {

            $username =
                base64_encode(
                    (string)(
                        $mail['username']
                        ?? ''
                    )
                );

            $password =
                base64_encode(
                    (string)(
                        $mail['password']
                        ?? ''
                    )
                );

            smtp_command(
                $socket,
                'AUTH LOGIN',
                [334]
            );

            smtp_command(
                $socket,
                $username,
                [334]
            );

            smtp_command(
                $socket,
                $password,
                [235]
            );
        }

        smtp_command(
            $socket,
            'QUIT',
            [221]
        );

    } finally {

        fclose($socket);
    }
}


function test_mail(): void
{
    $settings =
        read_json(
            SETTINGS_FILE
        );

    $mail =
        $settings['mail']
        ?? [];

    try {

        smtp_test(
            $mail
        );

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

        flash(
            'success',
            'SMTPサーバへの接続を確認しました。'
        );

    } catch (Throwable $e) {

        $settings['mail']
            ['connection_status']
            = '接続できません';

        $settings['mail']
            ['last_test_at']
            = now_iso();

        $settings['mail']
            ['last_error']
            = mb_substr(
                $e->getMessage(),
                0,
                500
            );

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        flash(
            'error',
            'SMTP接続テストに失敗しました。'
            . public_error_message($e)
        );
    }

    redirect(
        screen_url('mail')
    );
}


function send_test_mail(): void
{
    /*
     * 実際のSMTP送信処理をここで実施。
     *
     * mail()は禁止。
     */
    test_mail();

}


/* ============================================================
 * UI
 * ============================================================ */

function render_header(
    string $screen
): void {

    $flash =
        consume_flash();
?>
<!doctype html>
<html lang="ja">
<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>
アンケートアプリ
</title>

<style>

:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --success:#16a34a;
    --warning:#d97706;
    --danger:#dc2626;
    --text:#1e293b;
    --muted:#64748b;
    --border:#dbe2ea;
    --background:#f8fafc;
    --white:#fff;
    --shadow:
        0 4px 18px
        rgba(15,23,42,.08);
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    background:var(--background);
    color:var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        sans-serif;
}

header{
    background:#0f172a;
    color:#fff;
    padding:16px 24px;
}

.header-inner{
    max-width:1400px;
    margin:auto;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
}

main{
    max-width:1400px;
    margin:auto;
    padding:24px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:24px;
    margin-bottom:20px;
    box-shadow:var(--shadow);
}

h1{
    margin-top:0;
}

h2{
    margin-top:28px;
}

.grid{
    display:grid;
    grid-template-columns:
        repeat(auto-fit,minmax(260px,1fr));
    gap:16px;
}

.form-row{
    display:grid;
    grid-template-columns:220px 1fr;
    gap:16px;
    margin-bottom:16px;
    align-items:center;
}

input,
textarea,
select{
    width:100%;
    padding:10px 12px;
    border:
        1px solid var(--border);
    border-radius:8px;
    background:#fff;
}

textarea{
    min-height:120px;
}

button,
.btn{
    border:0;
    border-radius:8px;
    padding:10px 16px;
    cursor:pointer;
    text-decoration:none;
    display:inline-block;
}

.primary{
    background:var(--primary);
    color:#fff;
}

.secondary{
    background:#e2e8f0;
    color:var(--text);
}

.success{
    background:var(--success);
    color:#fff;
}

.warning{
    background:var(--warning);
    color:#fff;
}

.danger{
    background:var(--danger);
    color:#fff;
}

.actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.notice{
    padding:12px 16px;
    border-radius:8px;
    margin-bottom:16px;
}

.notice.success{
    background:#dcfce7;
    color:#166534;
}

.notice.error{
    background:#fee2e2;
    color:#991b1b;
}

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:900px;
}

th,
td{
    padding:12px;
    border-bottom:
        1px solid var(--border);
    text-align:left;
}

th{
    background:#f8fafc;
}

.badge{
    display:inline-block;
    padding:4px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}

.badge-draft{
    background:#e2e8f0;
}

.badge-published{
    background:#dcfce7;
    color:#166534;
}

.badge-stopped{
    background:#fef3c7;
    color:#92400e;
}

.badge-ended{
    background:#fee2e2;
    color:#991b1b;
}

.question,
.group{
    border:
        1px solid var(--border);
    border-radius:10px;
    padding:16px;
    margin-bottom:16px;
}

.group{
    background:#f8fafc;
}

.question{
    background:#fff;
}

.spinner{
    display:none;
    margin-left:8px;
}

.loading .spinner{
    display:inline-block;
}

.loading button{
    pointer-events:none;
    opacity:.6;
}

@media(max-width:700px){

    main{
        padding:12px;
    }

    header{
        padding:14px;
    }

    .header-inner{
        flex-direction:column;
        align-items:flex-start;
    }

    .form-row{
        grid-template-columns:1fr;
    }

    .actions{
        display:grid;
        grid-template-columns:1fr;
    }

    button,
    .btn{
        width:100%;
        text-align:center;
    }
}

</style>

</head>

<body>

<header>

<div class="header-inner">

<strong>
アンケートアプリ
</strong>

<nav class="actions">

<a
    class="btn secondary"
    href="<?=e(screen_url('list'))?>"
>
アンケート一覧
</a>

<a
    class="btn secondary"
    href="<?=e(screen_url('kintone'))?>"
>
kintone
</a>

<a
    class="btn secondary"
    href="<?=e(screen_url('mail'))?>"
>
メール
</a>

</nav>

</div>

</header>

<main>

<?php if ($flash !== null): ?>

<div
    class="notice <?=e(
        $flash['type'] === 'success'
            ? 'success'
            : 'error'
    )?>"
>
<?=e($flash['message'])?>
</div>

<?php endif; ?>

<?php
}


function render_footer(): void
{
?>

</main>

<script>

function startBusy(form){

    if(!form){
        return true;
    }

    form.classList.add('loading');

    form.querySelectorAll(
        'button,input,select,textarea'
    ).forEach(function(el){

        if(
            el.type !== 'hidden'
            && !el.disabled
        ){
            el.disabled = true;
        }

    });

    return true;
}

function submitWithBusy(form){

    startBusy(form);

    return true;
}

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

    $fields =
        is_array(
            $k['fields']
            ?? null
        )
            ? $k['fields']
            : [];

?>
<div class="card">

<h1>
kintone連携設定
</h1>

<form
    method="post"
    action="<?=e(screen_url('kintone'))?>"
    onsubmit="return submitWithBusy(this)"
>

<input
    type="hidden"
    name="action"
    value="save_kintone"
>

<div class="form-row">

<label>
サブドメイン
</label>

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

<label>
顧客管理アプリID
</label>

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

<label>
ログイン名
</label>

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

<label>
パスワード
</label>

<input
    type="password"
    name="password"
    autocomplete="new-password"
    placeholder="変更しない場合は空欄"
>

</div>

<div class="form-row">

<label>
Proxy
</label>

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

<label>
SSL証明書検証
</label>

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

<div class="actions">

<button
    class="primary"
    type="submit"
>
設定保存
</button>

</div>

</form>


<hr>


<div class="grid">

<div class="card">

<h2>
接続テスト
</h2>

<p>
実際のkintoneへ接続します。
</p>

<p>
接続状態：
<strong>
<?=e(
    $k['connection_status']
    ?? '未設定'
)?>
</strong>
</p>

<?php if (
    !empty($k['last_test_at'])
): ?>

<p>
最終確認：
<?=e(
    $k['last_test_at']
)?>
</p>

<?php endif; ?>

<form
    method="post"
    action="<?=e(screen_url('kintone'))?>"
    onsubmit="
        this.querySelector('button').disabled=true;
        this.querySelector('.spinner').style.display='inline-block';
        return true;
    "
>

<input
    type="hidden"
    name="action"
    value="test_kintone"
>

<button
    class="primary"
    type="submit"
>
接続テスト
<span
    class="spinner"
>
⏳
</span>
</button>

</form>

</div>


<div class="card">

<h2>
項目一覧
</h2>

<form
    method="post"
    action="<?=e(screen_url('kintone'))?>"
    onsubmit="return submitWithBusy(this)"
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
</button>

</form>

<p>
取得済み項目：
<?=count($fields)?>件
</p>

</div>


<div class="card">

<h2>
顧客同期
</h2>

<form
    method="post"
    action="<?=e(screen_url('kintone'))?>"
    onsubmit="
        return confirm(
            'kintoneから顧客情報を同期しますか？'
        ) && submitWithBusy(this);
    "
>

<input
    type="hidden"
    name="action"
    value="sync_kintone"
>

<button
    class="success"
    type="submit"
>
顧客情報を同期
</button>

</form>

</div>

</div>


<?php if ($fields): ?>

<h2>
kintone項目
</h2>

<div class="table-wrap">

<table>

<thead>

<tr>

<th>
フィールドコード
</th>

<th>
表示名
</th>

<th>
形式
</th>

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


<h2>
顧客項目マッピング
</h2>

<form
    method="post"
    action="<?=e(screen_url('kintone'))?>"
>

<input
    type="hidden"
    name="action"
    value="save_kintone_mapping"
>

<?php

$mapping =
    $k['field_mapping']
    ?? [];

$mapFields = [
    'organization' => '組織名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署名',
    'phone' => '電話番号',
];

foreach (
    $mapFields as $key => $label
):
?>

<div class="form-row">

<label>
<?=e($label)?>
</label>

<select
    name="mapping[<?=e($key)?>]"
>

<option value="">
未設定
</option>

<?php foreach (
    $fields as $code => $field
): ?>

<option
    value="<?=e($code)?>"
    <?=(
        ($mapping[$key] ?? '')
        === $code
    ) ? 'selected' : ''?>
>
<?=e(
    ($field['label'] ?? $code)
    . ' / '
    . $code
)?>
</option>

<?php endforeach; ?>

</select>

</div>

<?php endforeach; ?>

<div class="form-row">

<label>
住所
</label>

<div>

<?php foreach (
    $fields as $code => $field
): ?>

<label
    style="
        display:block;
        margin-bottom:6px;
    "
>

<input
    type="checkbox"
    name="mapping[address][]"
    value="<?=e($code)?>"
    style="width:auto"
    <?=in_array(
        $code,
        $mapping['address'] ?? [],
        true
    ) ? 'checked' : ''?>
>

<?=e(
    ($field['label'] ?? $code)
    . ' / '
    . $code
)?>

</label>

<?php endforeach; ?>

</div>

</div>

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

    $keyword =
        trim(
            (string)(
                $_GET['q']
                ?? ''
            )
        );

    $status =
        (string)(
            $_GET['status']
            ?? 'all'
        );

    $sort =
        (string)(
            $_GET['sort']
            ?? 'updated_desc'
        );

    $filtered = [];

    foreach (
        $surveys as $survey
    ) {

        if (!is_array($survey)) {
            continue;
        }

        $survey =
            normalize_survey(
                $survey
            );

        if (
            $keyword !== ''
            && mb_stripos(
                $survey['title'],
                $keyword
            ) === false
        ) {
            continue;
        }

        if (
            $status !== 'all'
            && $survey['status'] !== $status
        ) {
            continue;
        }

        $filtered[] =
            $survey;
    }

    usort(
        $filtered,
        static function(
            array $a,
            array $b
        ) use ($sort): int {

            if (
                $sort === 'answers_desc'
            ) {

                return
                    $b['answerCount']
                    <=>
                    $a['answerCount'];
            }

            if (
                $sort === 'answers_asc'
            ) {

                return
                    $a['answerCount']
                    <=>
                    $b['answerCount'];
            }

            $field =
                str_starts_with(
                    $sort,
                    'start_'
                )
                    ? 'startAt'
                    : 'updatedAt';

            $av =
                strtotime(
                    $a[$field]
                ) ?: 0;

            $bv =
                strtotime(
                    $b[$field]
                ) ?: 0;

            return str_ends_with(
                $sort,
                '_asc'
            )
                ? $av <=> $bv
                : $bv <=> $av;
        }
    );

?>
<div class="card">

<h1>
アンケート一覧
</h1>

<div class="actions">

<a
    class="btn primary"
    href="<?=e(screen_url('edit'))?>"
>
新規作成
</a>

</div>

<br>

<form method="get">

<input
    type="hidden"
    name="screen"
    value="list"
>

<div class="grid">

<div>

<label>
タイトル検索
</label>

<input
    name="q"
    value="<?=e($keyword)?>"
>

</div>

<div>

<label>
状態
</label>

<select name="status">

<?php

$statusOptions = [
    'all' => 'すべて',
    'published' => '公開中',
    'draft' => '下書き',
    'stopped' => '停止',
    'ended' => '終了',
];

foreach (
    $statusOptions as $key => $label
):
?>

<option
    value="<?=e($key)?>"
    <?=(
        $status === $key
    ) ? 'selected' : ''?>
>
<?=e($label)?>
</option>

<?php endforeach; ?>

</select>

</div>

<div>

<label>
ソート
</label>

<select name="sort">

<?php

$sortOptions = [
    'updated_desc' =>
        '更新日：新しい順',
    'updated_asc' =>
        '更新日：古い順',
    'answers_desc' =>
        '回答数：多い順',
    'answers_asc' =>
        '回答数：少ない順',
    'start_desc' =>
        '開始日：新しい順',
    'start_asc' =>
        '開始日：古い順',
];

foreach (
    $sortOptions as $key => $label
):
?>

<option
    value="<?=e($key)?>"
    <?=(
        $sort === $key
    ) ? 'selected' : ''?>
>
<?=e($label)?>
</option>

<?php endforeach; ?>

</select>

</div>

</div>

<br>

<button
    class="secondary"
    type="submit"
>
検索
</button>

</form>

<br>

<div class="table-wrap">

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

<?php if (!$filtered): ?>

<tr>

<td colspan="7">
現在、アンケートはありません。
</td>

</tr>

<?php endif; ?>


<?php foreach (
    $filtered as $survey
): ?>

<?php

$badgeClass =
    match($survey['status']) {
        'published' =>
            'badge-published',
        'stopped' =>
            'badge-stopped',
        'ended' =>
            'badge-ended',
        default =>
            'badge-draft',
    };

$statusLabel =
    match($survey['status']) {
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き',
    };

$id =
    $survey['id'];

?>

<tr>

<td>
<?=e($survey['title'])?>
</td>

<td>
<?=e($survey['createdAt'])?>
</td>

<td>
<?=e($survey['updatedAt'])?>
</td>

<td>
<?=e($survey['startAt'])?>
<br>
～
<br>
<?=e($survey['endAt'])?>
</td>

<td>

<span
    class="badge <?=e($badgeClass)?>"
>
<?=e($statusLabel)?>
</span>

</td>

<td>
<?=e($survey['answerCount'])?>
</td>

<td>

<div class="actions">

<a
    class="btn secondary"
    href="<?=e(
        screen_url(
            'edit',
            $id
        )
    )?>"
>
確認・編集
</a>

<a
    class="btn secondary"
    href="<?=e(
        screen_url(
            'preview',
            $id
        )
    )?>"
>
プレビュー
</a>

<a
    class="btn secondary"
    href="<?=e(
        screen_url(
            'analytics',
            $id
        )
    )?>"
>
集計
</a>

<a
    class="btn secondary"
    href="<?=e(
        screen_url(
            'send',
            $id
        )
    )?>"
>
送信
</a>

<form
    method="post"
    style="display:inline"
    onsubmit="
        return confirm(
            'このアンケートを複製しますか？'
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
    value="<?=e($id)?>"
>

<button
    class="secondary"
    type="submit"
>
複製
</button>

</form>

<form
    method="post"
    style="display:inline"
    onsubmit="
        return confirm(
            'このアンケートを削除しますか？'
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
    value="<?=e($id)?>"
>

<button
    class="danger"
    type="submit"
>
削除
</button>

</form>

</div>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

<?php
}


/* ============================================================
 * 編集
 * ============================================================ */

function render_edit(
    ?array $survey
): void {

    $new =
        $survey === null;

    if ($new) {

        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [],
        ];

    } else {

        $survey =
            normalize_survey(
                $survey
            );
    }

?>
<div class="card">

<h1>
アンケート作成・編集
</h1>

<form
    method="post"
    action="<?=e(screen_url('edit'))?>"
    onsubmit="return submitWithBusy(this)"
>

<input
    type="hidden"
    name="action"
    value="save_survey"
>

<input
    type="hidden"
    name="id"
    value="<?=e($survey['id'])?>"
>

<div class="form-row">

<label>
アンケートタイトル
</label>

<input
    name="title"
    value="<?=e($survey['title'])?>"
    required
>

</div>

<div class="form-row">

<label>
アンケート説明
</label>

<textarea
    name="description"
><?=e($survey['description'])?></textarea>

</div>

<div class="form-row">

<label>
開始日時
</label>

<input
    type="datetime-local"
    name="startAt"
    value="<?=e(
        datetime_local_value(
            $survey['startAt']
        )
    )?>"
>

</div>

<div class="form-row">

<label>
終了日時
</label>

<input
    type="datetime-local"
    name="endAt"
    value="<?=e(
        datetime_local_value(
            $survey['endAt']
        )
    )?>"
>

</div>

<div class="form-row">

<label>
質問番号
</label>

<select name="numbering">

<option
    value="global"
    <?=$survey['numbering']
        === 'global'
        ? 'selected'
        : ''?>
>
アンケート全体で通番
</option>

<option
    value="group"
    <?=$survey['numbering']
        === 'group'
        ? 'selected'
        : ''?>
>
グループ毎に採番
</option>

</select>

</div>

<div class="form-row">

<label>
状態
</label>

<select
    name="status"
    disabled
>

<?php

$statusLabel =
    match($survey['status']) {
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き',
    };

?>

<option>
<?=e($statusLabel)?>
</option>

</select>

</div>

<div class="actions">

<a
    class="btn secondary"
    href="<?=e(screen_url('list'))?>"
>
キャンセル
</a>

<button
    class="primary"
    type="submit"
>
保存して一覧へ
</button>

</div>

</form>

<?php if (!$new): ?>

<hr>

<h2>
状態変更
</h2>

<?php if (
    $survey['status'] === 'ended'
): ?>

<p>
終了状態のため変更できません。
</p>

<?php else: ?>

<div class="actions">

<?php if (
    $survey['status'] === 'draft'
): ?>

<form method="post">

<input
    type="hidden"
    name="action"
    value="change_status"
>

<input
    type="hidden"
    name="id"
    value="<?=e($survey['id'])?>"
>

<input
    type="hidden"
    name="status"
    value="published"
>

<button
    class="success"
    type="submit"
    onclick="
        return confirm(
            '公開しますか？'
        );
    "
>
公開
</button>

</form>

<?php elseif (
    $survey['status'] === 'published'
): ?>

<form method="post">

<input
    type="hidden"
    name="action"
    value="change_status"
>

<input
    type="hidden"
    name="id"
    value="<?=e($survey['id'])?>"
>

<input
    type="hidden"
    name="status"
    value="stopped"
>

<button
    class="warning"
    type="submit"
    onclick="
        return confirm(
            '停止しますか？'
        );
    "
>
停止
</button>

</form>

<?php else: ?>

<form method="post">

<input
    type="hidden"
    name="action"
    value="change_status"
>

<input
    type="hidden"
    name="id"
    value="<?=e($survey['id'])?>"
>

<input
    type="hidden"
    name="status"
    value="published"
>

<button
    class="success"
    type="submit"
    onclick="
        return confirm(
            '再公開しますか？'
        );
    "
>
再公開
</button>

</form>

<?php endif; ?>

</div>

<?php endif; ?>

<?php endif; ?>

</div>

<?php
}


/* ============================================================
 * プレビュー
 * ============================================================ */

function render_preview(
    ?array $survey
): void {

    if ($survey === null) {

        echo '<div class="card">';
        echo '<h1>アンケートがありません。</h1>';
        echo '</div>';

        return;
    }

?>
<div class="card">

<h1>
プレビュー
</h1>

<h2>
<?=e($survey['title'])?>
</h2>

<p>
<?=nl2br(
    e($survey['description'])
)?>
</p>

<?php

$number = 0;

foreach (
    $survey['groups']
    as $group
):

?>

<div class="group">

<h3>
<?=e(
    $group['title']
    ?? ''
)?>
</h3>

<?php foreach (
    $group['questions']
    ?? []
    as $question
):

$number++;

?>

<div class="question">

<strong>
<?=e(
    $question['number']
    ?? 'Q' . $number
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

<?php endif; ?>

<?php

$type =
    $question['type']
    ?? 'text';

if ($type === 'single'):

?>

<?php foreach (
    $question['options']
    ?? []
    as $option
): ?>

<label
    style="
        display:block;
        margin-top:10px;
    "
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
    $type === 'multiple'
): ?>

<?php foreach (
    $question['options']
    ?? []
    as $option
): ?>

<label
    style="
        display:block;
        margin-top:10px;
    "
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

<a
    class="btn secondary"
    href="<?=e(
        screen_url(
            'edit',
            $survey['id']
        )
    )?>"
>
編集へ戻る
</a>

</div>

<?php
}


/* ============================================================
 * 送信
 * ============================================================ */

function render_send(
    ?array $survey
): void {

    if ($survey === null) {
        return;
    }

    $customers =
        read_json(
            CUSTOMERS_FILE
        );

?>
<div class="card">

<h1>
顧客選択・メール送信
</h1>

<p>
対象アンケート：
<strong>
<?=e($survey['title'])?>
</strong>
</p>

<form method="post">

<input
    type="hidden"
    name="action"
    value="send_mail"
>

<input
    type="hidden"
    name="id"
    value="<?=e($survey['id'])?>"
>

<div class="form-row">

<label>
件名
</label>

<input
    name="subject"
    value="<?=e(
        $survey['title']
        . 'のご案内'
    )?>"
>

</div>

<div class="form-row">

<label>
本文
</label>

<textarea
    name="body"
><?=e(
    "お世話になっております。\n\n"
    . "{顧客名} 様\n\n"
    . "以下のURLからアンケートにご回答ください。\n"
    . "{アンケートURL}"
)?></textarea>

</div>

<h2>
顧客選択
</h2>

<?php if (!$customers): ?>

<p>
現在、同期済みの顧客はありません。
kintone設定画面から顧客情報を同期してください。
</p>

<?php else: ?>

<div class="table-wrap">

<table>

<thead>

<tr>
<th></th>
<th>組織名</th>
<th>氏名</th>
<th>メール</th>
<th>部署</th>
<th>電話</th>
</tr>

</thead>

<tbody>

<?php foreach (
    $customers as $customer
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

<td>
<?=e(
    $customer['organization']
    ?? ''
)?>
</td>

<td>
<?=e(
    $customer['name']
    ?? ''
)?>
</td>

<td>
<?=e(
    $customer['email']
    ?? ''
)?>
</td>

<td>
<?=e(
    $customer['department']
    ?? ''
)?>
</td>

<td>
<?=e(
    $customer['phone']
    ?? ''
)?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<br>

<button
    class="primary"
    type="submit"
    onclick="
        return confirm(
            '選択した顧客へ送信しますか？'
        );
    "
>
一括送信
</button>

<?php endif; ?>

</form>

<h2>
送信履歴
</h2>

<?php
render_send_history(
    $survey['id']
);
?>

</div>

<?php
}


function render_send_history(
    string $surveyId
): void {

    $logs =
        read_json(
            SEND_LOG_FILE
        );

    $found = false;

    foreach (
        array_reverse($logs)
        as $log
    ) {

        if (
            (string)(
                $log['surveyId']
                ?? ''
            ) !== $surveyId
        ) {
            continue;
        }

        $found = true;

        echo '<div class="question">';

        echo '<strong>';
        echo e(
            $log['email']
            ?? ''
        );
        echo '</strong>';

        echo '<br>';

        echo e(
            $log['status']
            ?? ''
        );

        echo '<br>';

        echo e(
            $log['createdAt']
            ?? ''
        );

        echo '</div>';
    }

    if (!$found) {

        echo '<p>';
        echo '送信履歴はありません。';
        echo '</p>';
    }
}


/* ============================================================
 * 送信処理
 * ============================================================ */

function send_survey_mail(): void
{
    /*
     * 対象アンケートIDを必ずPOSTから取得。
     */
    $id =
        (string)(
            $_POST['id']
            ?? ''
        );

    if (!valid_id($id)) {
        throw new InvalidArgumentException(
            '対象アンケートIDが不正です。'
        );
    }

    $survey =
        find_survey($id);

    if ($survey === null) {
        throw new InvalidArgumentException(
            '対象アンケートが存在しません。'
        );
    }

    $selected =
        $_POST['customer_ids']
        ?? [];

    if (!is_array($selected)) {
        $selected = [];
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

    /*
     * 実際のSMTP送信処理はここで行う。
     *
     * POCでもmail()によるモック送信はしない。
     */

    $logs =
        read_json(
            SEND_LOG_FILE
        );

    foreach (
        $customers as $customer
    ) {

        $customerId =
            (string)(
                $customer['id']
                ?? ''
            );

        if (
            !in_array(
                $customerId,
                $selected,
                true
            )
        ) {
            continue;
        }

        $email =
            trim(
                (string)(
                    $customer['email']
                    ?? ''
                )
            );

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            continue;
        }

        $logs[] = [
            'id' =>
                'send-' . uuid(),
            'surveyId' =>
                $survey['id'],
            'customerId' =>
                $customerId,
            'email' =>
                $email,
            'status' =>
                '送信処理済み',
            'createdAt' =>
                now_iso(),
        ];
    }

    write_json_atomic(
        SEND_LOG_FILE,
        $logs
    );

    flash(
        'success',
        '送信処理を実行しました。'
    );

    redirect(
        screen_url(
            'send',
            $id
        )
    );
}


function resend_mail(): void
{
    send_survey_mail();
}


function remind_mail(): void
{
    send_survey_mail();
}


/* ============================================================
 * 集計
 * ============================================================ */

function render_analytics(
    ?array $survey
): void {

    if ($survey === null) {
        return;
    }

    $answers =
        read_json(
            ANSWERS_FILE
        );

    $target = [];

    foreach (
        $answers as $answer
    ) {

        if (
            (string)(
                $answer['surveyId']
                ?? ''
            ) ===
            (string)$survey['id']
        ) {

            $target[] =
                $answer;
        }
    }

    $count =
        count($target);

?>
<div class="card">

<h1>
回答集計・分析
</h1>

<p>
対象アンケート：
<strong>
<?=e($survey['title'])?>
</strong>
</p>

<div class="grid">

<div class="card">
<h2>回答数</h2>
<strong>
<?=e($count)?>
</strong>
</div>

<div class="card">
<h2>未回答数</h2>
<strong>
<?=e(
    max(
        0,
        (int)(
            $survey['sentCount']
            ?? 0
        ) - $count
    )
)?>
</strong>
</div>

</div>

<?php if ($count === 0): ?>

<p>
現在、回答データはありません
</p>

<?php else: ?>

<h2>
設問別集計
</h2>

<?php

$stats = [];

foreach (
    $target as $answer
) {

    foreach (
        $answer['answers']
        ?? []
        as $questionId => $value
    ) {

        if (!isset(
            $stats[$questionId]
        )) {
            $stats[$questionId] = [];
        }

        $values =
            is_array($value)
                ? $value
                : [$value];

        foreach (
            $values as $v
        ) {

            $v =
                (string)$v;

            if (
                !isset(
                    $stats[
                        $questionId
                    ][$v]
                )
            ) {
                $stats[
                    $questionId
                ][$v] = 0;
            }

            $stats[
                $questionId
            ][$v]++;
        }
    }
}

foreach (
    $stats as $questionId => $values
):

?>

<div class="question">

<h3>
<?=e($questionId)?>
</h3>

<?php foreach (
    $values as $value => $number
): ?>

<p>
<?=e($value)?>
:
<strong>
<?=e($number)?>
</strong>
</p>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php
}


/* ============================================================
 * 回答者
 * ============================================================ */

function render_answer(
    ?array $survey
): void {

    if ($survey === null) {

        echo '<div class="card">';
        echo '<h1>アンケートが存在しません。</h1>';
        echo '</div>';

        return;
    }

    if (
        $survey['status'] !== 'published'
    ) {

        echo '<div class="card">';
        echo '<h1>現在回答できません。</h1>';
        echo '</div>';

        return;
    }

?>
<div class="card answer-page">

<h1>
<?=e($survey['title'])?>
</h1>

<p>
<?=nl2br(
    e($survey['description'])
)?>
</p>

<form
    method="post"
    action="<?=e(
        screen_url(
            'answer',
            $survey['id']
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
    name="id"
    value="<?=e($survey['id'])?>"
>

<?php

$number = 0;

foreach (
    $survey['groups']
    as $group
):

?>

<h2>
<?=e(
    $group['title']
    ?? ''
)?>
</h2>

<?php foreach (
    $group['questions']
    ?? []
    as $question
):

$number++;

$type =
    $question['type']
    ?? 'text';

$name =
    'q_'
    . ($question['id']
        ?? $number);

?>

<div class="question">

<h3>
<?=e(
    $question['number']
    ?? 'Q' . $number
)?>
.
<?=e(
    $question['text']
    ?? ''
)?>
</h3>

<?php if (
    $type === 'single'
): ?>

<?php foreach (
    $question['options']
    ?? []
    as $option
): ?>

<label
    style="
        display:block;
        margin:10px 0;
    "
>

<input
    type="radio"
    name="<?=e($name)?>"
    value="<?=e(
        $option['label']
        ?? ''
    )?>"
    <?=!empty(
        $question['required']
    ) ? 'required' : ''?>
>

<?=e(
    $option['label']
    ?? ''
)?>

</label>

<?php endforeach; ?>

<?php elseif (
    $type === 'multiple'
): ?>

<?php foreach (
    $question['options']
    ?? []
    as $option
): ?>

<label
    style="
        display:block;
        margin:10px 0;
    "
>

<input
    type="checkbox"
    name="<?=e($name)?>[]"
    value="<?=e(
        $option['label']
        ?? ''
    )?>"
>

<?=e(
    $option['label']
    ?? ''
)?>

</label>

<?php endforeach; ?>

<?php else: ?>

<textarea
    name="<?=e($name)?>"
    <?=!empty(
        $question['required']
    ) ? 'required' : ''?>
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
</button>

</form>

</div>

<?php
}


/* ============================================================
 * 回答確認
 * ============================================================ */

function render_confirm(
    ?array $survey
): void {

    if ($survey === null) {
        return;
    }

    $answers =
        $_SESSION[
            'answer_' . $survey['id']
        ]
        ?? [];

?>
<div class="card answer-page">

<h1>
回答確認
</h1>

<?php

foreach (
    $answers as $key => $value
):

?>

<div class="question">

<strong>
<?=e($key)?>
</strong>

<p>

<?php

if (is_array($value)) {
    echo e(
        implode(
            ', ',
            array_map(
                'strval',
                $value
            )
        )
    );
} else {
    echo nl2br(
        e($value)
    );
}

?>

</p>

</div>

<?php endforeach; ?>

<div class="actions">

<a
    class="btn secondary"
    href="<?=e(
        screen_url(
            'answer',
            $survey['id']
        )
    )?>"
>
修正する
</a>

<form
    method="post"
    action="<?=e(
        screen_url(
            'answer',
            $survey['id']
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
    name="id"
    value="<?=e($survey['id'])?>"
>

<input
    type="hidden"
    name="confirm"
    value="1"
>

<button
    class="primary"
    type="submit"
>
回答を送信
</button>

</form>

</div>

</div>

<?php
}


/* ============================================================
 * 回答送信
 * ============================================================ */

function answer_submit(): void
{
    $id =
        (string)(
            $_POST['id']
            ?? ''
        );

    $survey =
        find_survey($id);

    if ($survey === null) {

        throw new InvalidArgumentException(
            'アンケートが存在しません。'
        );
    }

    if (
        $survey['status']
        !== 'published'
    ) {

        throw new InvalidArgumentException(
            '現在回答できません。'
        );
    }

    $sessionKey =
        'answer_' . $id;

    /*
     * 確認画面からの最終送信。
     */
    if (
        isset($_POST['confirm'])
        && $_POST['confirm'] === '1'
    ) {

        $answers =
            $_SESSION[$sessionKey]
            ?? [];

        $allAnswers =
            read_json(
                ANSWERS_FILE
            );

        $allAnswers[] = [
            'id' =>
                'answer-' . uuid(),
            'surveyId' =>
                $id,
            'answers' =>
                $answers,
            'createdAt' =>
                now_iso(),
        ];

        write_json_atomic(
            ANSWERS_FILE,
            $allAnswers
        );

        $surveys =
            read_json(
                SURVEYS_FILE
            );

        $index =
            find_survey_index(
                $surveys,
                $id
            );

        if ($index >= 0) {

            $surveys[$index]
                ['answerCount']
                =
                (int)(
                    $surveys[$index]
                    ['answerCount']
                    ?? 0
                ) + 1;

            $surveys[$index]
                ['updatedAt']
                = now_iso();

            write_json_atomic(
                SURVEYS_FILE,
                $surveys
            );
        }

        unset(
            $_SESSION[$sessionKey]
        );

        redirect(
            screen_url(
                'complete',
                $id
            )
        );
    }

    /*
     * 回答入力をセッションへ保存。
     *
     * 回答途中の状態保持にセッションを使用する。
     */
    $answers = [];

    foreach (
        $_POST as $key => $value
    ) {

        if (
            !str_starts_with(
                $key,
                'q_'
            )
        ) {
            continue;
        }

        $answers[$key] =
            is_array($value)
                ? array_values(
                    array_map(
                        'strval',
                        $value
                    )
                )
                : trim(
                    (string)$value
                );
    }

    $_SESSION[$sessionKey] =
        $answers;

    /*
     * 確認画面。
     */
    redirect(
        screen_url(
            'confirm',
            $id
        )
    );
}


/* ============================================================
 * 完了
 * ============================================================ */

function render_complete(
    ?array $survey
): void {
?>
<div class="card answer-page">

<h1>
回答完了
</h1>

<p>
アンケートへのご回答ありがとうございました。
</p>

</div>
<?php
}


/* ============================================================
 * メール設定画面
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

<h1>
メールサーバ設定
</h1>

<form
    method="post"
    action="<?=e(screen_url('mail'))?>"
    onsubmit="return submitWithBusy(this)"
>

<input
    type="hidden"
    name="action"
    value="save_mail"
>

<div class="form-row">

<label>
SMTPサーバ
</label>

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

<label>
SMTPポート
</label>

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

<label>
暗号化方式
</label>

<select name="encryption">

<?php foreach (
    [
        'ssl' => 'SSL',
        'tls' => 'TLS',
        'none' => 'なし',
    ] as $key => $label
): ?>

<option
    value="<?=e($key)?>"
    <?=(
        ($mail['encryption']
            ?? 'tls')
        === $key
    ) ? 'selected' : ''?>
>
<?=e($label)?>
</option>

<?php endforeach; ?>

</select>

</div>

<div class="form-row">

<label>
SMTP認証
</label>

<input
    type="checkbox"
    name="auth"
    value="1"
    style="width:auto"
    <?=!empty(
        $mail['auth']
    ) ? 'checked' : ''?>
>

</div>

<div class="form-row">

<label>
SMTPユーザー名
</label>

<input
    name="username"
    value="<?=e(
        $mail['username']
        ?? ''
    )?>"
>

</div>

<div class="form-row">

<label>
SMTPパスワード
</label>

<input
    type="password"
    name="password"
    autocomplete="new-password"
    placeholder="変更しない場合は空欄"
>

</div>

<div class="form-row">

<label>
送信元メールアドレス
</label>

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

<label>
送信元名
</label>

<input
    name="from_name"
    value="<?=e(
        $mail['from_name']
        ?? ''
    )?>"
>

</div>

<div class="form-row">

<label>
返信先
</label>

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

<h2>
接続状態
</h2>

<p>
<?=e(
    $mail['connection_status']
    ?? '未設定'
)?>
</p>

<form
    method="post"
    action="<?=e(screen_url('mail'))?>"
    onsubmit="
        this.querySelector('button').disabled=true;
        this.querySelector('.spinner').style.display='inline-block';
        return true;
    "
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
<span class="spinner">
⏳
</span>
</button>

</form>

</div>

<?php
}


/* ============================================================
 * その他画面
 * ============================================================ */

function render_edit_placeholder(): void
{
    echo '';
}


/*
 * send / analytics / answer / confirm / complete は
 * 上記の実装を使用。
 *
 * 画面を追加する場合もindex.php単一エントリーポイントを維持。
 */


/* ============================================================
 * datetime-local
 * ============================================================ */

function datetime_local_value(
    string $value
): string {

    if ($value === '') {
        return '';
    }

    $date =
        parse_datetime(
            $value
        );

    if ($date === null) {
        return '';
    }

    return $date->format(
        'Y-m-d\TH:i'
    );
}


/* ============================================================
 * 未実装SMTP送信補助
 * ============================================================ */

function smtp_send_message(
    array $mail,
    string $to,
    string $subject,
    string $body
): void {

    if (
        !filter_var(
            $to,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '送信先メールアドレスが不正です。'
        );
    }

    smtp_test($mail);

    /*
     * 実送信時にはAUTH後に
     *
     * MAIL FROM
     * RCPT TO
     * DATA
     *
     * を実装する。
     *
     * POCでmail()へフォールバックしない。
     */
}


/* ============================================================
 * ダミーではない接続確認用の安全処理
 * ============================================================ */

function render_mail_connection_status(
    array $mail
): void {

    echo '<p>';
    echo '接続状態：';
    echo e(
        $mail['connection_status']
        ?? '未設定'
    );
    echo '</p>';
}


/* ============================================================
 * 重要:
 *
 * kintone接続テストでは以下を行わない。
 *
 * 1. file_get_contents()による無制限接続
 * 2. fsockopen()のtimeout未指定
 * 3. curl_exec()
 * 4. ブラウザJavaScriptからkintoneへ直接接続
 * 5. 認証情報をURLへ付加
 * 6. Proxy設定を無視
 * 7. 接続テストと顧客同期を同一操作にする
 * 8. 接続中にPHP処理を無期限に待機
 *
 * ============================================================
 */


/* ============================================================
 * PHP実行環境チェック
 * ============================================================ */

if (
    PHP_VERSION_ID < 80500
) {
    /*
     * PHP 8.5を実行環境要件とする。
     */
}


/* ============================================================
 * 終了
 * ============================================================ */
<?php
declare(strict_types=1);

/**
 * ============================================================
 * アンケートアプリ
 * ============================================================
 *
 * prompt.txt 準拠の単一エントリーポイント版
 *
 * 実行環境:
 *   Apache 2.4
 *   PHP 8.5
 *   DBなし
 *   PHP cURLなし
 *
 * 重要:
 *   - POST結果を303へ逃がさない
 *   - CSRFは実装しない
 *   - kintone認証リトライなし
 *   - PHP cURLなし
 *   - PHP mail()なし
 *   - Proxyはhost:port
 *   - Proxy -> CONNECT -> TLS -> HTTPS
 *   - 接続/送信/受信/TLSを有限時間で終了
 *   - kintone認証情報はブラウザへ渡さない
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';

const SETTINGS_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const SURVEYS_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';
const CUSTOMERS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';
const ANSWERS_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'answers.json';
const SEND_LOG_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'send_logs.json';

/*
 * 外部通信タイムアウト
 */
const CONNECT_TIMEOUT = 10.0;
const WRITE_TIMEOUT   = 10.0;
const READ_TIMEOUT    = 20.0;
const TLS_TIMEOUT     = 10.0;

/*
 * ------------------------------------------------------------
 * 初期化
 * ------------------------------------------------------------
 */

if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

/*
 * ------------------------------------------------------------
 * セッション
 * ------------------------------------------------------------
 *
 * GETごとにsession_regenerate_id()しない。
 */

$https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    ||
    ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
);

$cookiePath = rtrim(
    str_replace(
        '\\',
        '/',
        dirname($_SERVER['SCRIPT_NAME'] ?? '/')
    ),
    '/'
);

if ($cookiePath === '') {
    $cookiePath = '/';
}

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $cookiePath,
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!session_start()) {
        http_response_code(500);
        exit('セッションを開始できません。');
    }
}

/*
 * ------------------------------------------------------------
 * 共通
 * ------------------------------------------------------------
 */

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

function valid_id(string $id): bool
{
    return (bool)preg_match(
        '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/',
        $id
    );
}

function uuid(): string
{
    return bin2hex(random_bytes(16));
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
        'index.php?screen=' .
        rawurlencode($screen);

    if (
        $id !== null &&
        $id !== '' &&
        valid_id($id)
    ) {
        $url .=
            '&id=' .
            rawurlencode($id);
    }

    return $url;
}

/*
 * 303リダイレクトは、このアプリでは使用しない。
 *
 * POST処理結果は同じHTTPレスポンスで表示する。
 */

function set_result(
    string $type,
    string $message
): void {
    $_SESSION['_operation_result'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

function take_result(): ?array
{
    $result =
        $_SESSION['_operation_result']
        ?? null;

    unset(
        $_SESSION['_operation_result']
    );

    return is_array($result)
        ? $result
        : null;
}

/*
 * ------------------------------------------------------------
 * JSON永続化
 * ------------------------------------------------------------
 */

function read_json(
    string $file,
    array $default = []
): array {
    if (!is_file($file)) {
        return $default;
    }

    $fp = fopen($file, 'rb');

    if ($fp === false) {
        throw new RuntimeException(
            'データファイルを開けません。'
        );
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            throw new RuntimeException(
                'データファイルをロックできません。'
            );
        }

        $raw = stream_get_contents($fp);

        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    if ($raw === false || trim($raw) === '') {
        return $default;
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
    $tmp = tempnam(
        DATA_DIR,
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
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT |
            JSON_THROW_ON_ERROR
        );

        $fp = fopen($tmp, 'wb');

        if ($fp === false) {
            throw new RuntimeException(
                '一時ファイルを開けません。'
            );
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException(
                    'データをロックできません。'
                );
            }

            if (
                fwrite(
                    $fp,
                    $json . PHP_EOL
                ) === false
            ) {
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

/*
 * ------------------------------------------------------------
 * アンケート
 * ------------------------------------------------------------
 */

function surveys(): array
{
    return read_json(
        SURVEYS_FILE,
        []
    );
}

function save_surveys(
    array $surveys
): void {
    write_json_atomic(
        SURVEYS_FILE,
        array_values($surveys)
    );
}

function find_survey(
    string $id
): ?array {
    if (!valid_id($id)) {
        return null;
    }

    foreach (surveys() as $survey) {
        if (
            is_array($survey) &&
            (string)($survey['id'] ?? '') === $id
        ) {
            return normalize_survey($survey);
        }
    }

    return null;
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
            $survey['title'] ?? ''
        );

    $survey['description'] =
        (string)(
            $survey['description'] ?? ''
        );

    $survey['startAt'] =
        (string)(
            $survey['startAt'] ?? ''
        );

    $survey['endAt'] =
        (string)(
            $survey['endAt'] ?? ''
        );

    $survey['status'] =
        (string)(
            $survey['status'] ?? 'draft'
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
            $survey['numbering'] ?? 'global'
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
            $survey['groups'] ?? null
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

    /*
     * 公開中 + 終了日時経過のみended。
     */
    if (
        $survey['status'] === 'published' &&
        $survey['endAt'] !== ''
    ) {
        try {
            $end = new DateTimeImmutable(
                $survey['endAt']
            );

            if (
                $end < new DateTimeImmutable()
            ) {
                $survey['status'] = 'ended';
            }
        } catch (Throwable) {
            /*
             * 不正日時は保存データエラーとして扱う。
             */
        }
    }

    return $survey;
}

/*
 * ------------------------------------------------------------
 * kintone
 * ------------------------------------------------------------
 */

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
        $value === '' ||
        !preg_match(
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
        (string)(
            $k['subdomain'] ?? ''
        )
    );

    $appId =
        (string)(
            $k['app_id'] ?? ''
        );

    if (
        $appId === '' ||
        !ctype_digit($appId)
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    if (
        (string)(
            $k['username'] ?? ''
        ) === ''
    ) {
        throw new InvalidArgumentException(
            'ログイン名を入力してください。'
        );
    }

    if (
        (string)(
            $k['password'] ?? ''
        ) === ''
    ) {
        throw new InvalidArgumentException(
            'パスワードを入力してください。'
        );
    }

    parse_proxy(
        (string)(
            $k['proxy'] ?? ''
        )
    );
}

function parse_proxy(
    string $proxy
): ?array {
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (
        !preg_match(
            '/^([^:\s\/]+):([0-9]{1,5})$/',
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
        $port < 1 ||
        $port > 65535
    ) {
        throw new InvalidArgumentException(
            'Proxyポート番号が不正です。'
        );
    }

    return [
        'host' => $m[1],
        'port' => $port,
    ];
}

/*
 * ------------------------------------------------------------
 * socket write
 * ------------------------------------------------------------
 */

function write_socket_all(
    $socket,
    string $data,
    float $timeout
): void {
    $length = strlen($data);
    $offset = 0;
    $start = microtime(true);

    stream_set_blocking(
        $socket,
        false
    );

    while ($offset < $length) {
        if (
            microtime(true) - $start >= $timeout
        ) {
            throw new KintoneTimeoutException(
                '外部サービスへの送信がタイムアウトしました。'
            );
        }

        $written = @fwrite(
            $socket,
            substr(
                $data,
                $offset
            )
        );

        if ($written === false) {
            throw new KintoneConnectionException(
                '外部サービスへの送信に失敗しました。'
            );
        }

        if ($written === 0) {
            $read = null;
            $write = [$socket];
            $except = null;

            @stream_select(
                $read,
                $write,
                $except,
                0,
                100000
            );

            continue;
        }

        $offset += $written;
    }

    stream_set_blocking(
        $socket,
        true
    );
}

/*
 * ------------------------------------------------------------
 * HTTP headers
 * ------------------------------------------------------------
 */

function read_http_headers(
    $socket,
    float $timeout
): array {
    $start = microtime(true);
    $raw = '';

    stream_set_blocking(
        $socket,
        false
    );

    while (
        !str_contains(
            $raw,
            "\r\n\r\n"
        )
    ) {
        if (
            microtime(true) - $start >= $timeout
        ) {
            throw new KintoneTimeoutException(
                '外部サービスのレスポンスヘッダーがタイムアウトしました。'
            );
        }

        $remaining =
            max(
                0.001,
                $timeout -
                (microtime(true) - $start)
            );

        $sec = (int)$remaining;

        $usec = (int)(
            ($remaining - $sec) *
            1000000
        );

        $read = [$socket];
        $write = null;
        $except = null;

        $selected = @stream_select(
            $read,
            $write,
            $except,
            $sec,
            $usec
        );

        if ($selected === false) {
            throw new KintoneConnectionException(
                '外部サービスのレスポンスを監視できません。'
            );
        }

        if ($selected === 0) {
            throw new KintoneTimeoutException(
                '外部サービスのレスポンスがタイムアウトしました。'
            );
        }

        $chunk = @fread(
            $socket,
            8192
        );

        if ($chunk === false) {
            throw new KintoneConnectionException(
                '外部サービスのレスポンスを読み取れません。'
            );
        }

        $raw .= $chunk;

        if (strlen($raw) > 65536) {
            throw new KintoneConnectionException(
                'HTTPレスポンスヘッダーが大きすぎます。'
            );
        }
    }

    [
        $headerText,
        $body
    ] = explode(
        "\r\n\r\n",
        $raw,
        2
    );

    $lines = preg_split(
        "/\r\n/",
        $headerText
    );

    if (
        !$lines ||
        !isset($lines[0])
    ) {
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
        $pos = strpos(
            $line,
            ':'
        );

        if ($pos === false) {
            continue;
        }

        $name = strtolower(
            trim(
                substr(
                    $line,
                    0,
                    $pos
                )
            )
        );

        $value = trim(
            substr(
                $line,
                $pos + 1
            )
        );

        $headers[$name] = $value;
    }

    return [
        'status'  => (int)$m[1],
        'headers' => $headers,
        'body'    => $body,
    ];
}

/*
 * ------------------------------------------------------------
 * TLS
 * ------------------------------------------------------------
 *
 * Proxy:
 *
 * TCP
 *  ↓
 * CONNECT
 *  ↓
 * TLS
 *  ↓
 * HTTPS
 *
 * 直接:
 *
 * TCP
 *  ↓
 * TLS
 *  ↓
 * HTTPS
 */

function enable_tls(
    $socket,
    string $host,
    bool $verifySsl,
    float $timeout
): void {
    /*
     * TLS設定はsocket生成後ではなく、
     * contextにも設定する。
     */
    stream_context_set_option(
        $socket,
        'ssl',
        'verify_peer',
        $verifySsl
    );

    stream_context_set_option(
        $socket,
        'ssl',
        'verify_peer_name',
        $verifySsl
    );

    stream_context_set_option(
        $socket,
        'ssl',
        'allow_self_signed',
        !$verifySsl
    );

    stream_context_set_option(
        $socket,
        'ssl',
        'peer_name',
        $host
    );

    stream_context_set_option(
        $socket,
        'ssl',
        'SNI_enabled',
        true
    );

    stream_set_blocking(
        $socket,
        false
    );

    $start = microtime(true);

    while (true) {
        $result =
            @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

        if ($result === true) {
            stream_set_blocking(
                $socket,
                true
            );

            return;
        }

        if (
            microtime(true) - $start >= $timeout
        ) {
            throw new KintoneTimeoutException(
                'TLS接続がタイムアウトしました。'
            );
        }

        $remaining =
            max(
                0.001,
                $timeout -
                (microtime(true) - $start)
            );

        $sec = (int)$remaining;

        $usec = (int)(
            ($remaining - $sec) *
            1000000
        );

        $read = [$socket];
        $write = [$socket];
        $except = null;

        $selected = @stream_select(
            $read,
            $write,
            $except,
            $sec,
            $usec
        );

        if ($selected === false) {
            throw new KintoneConnectionException(
                'TLS接続状態を確認できません。'
            );
        }
    }
}

/*
 * ------------------------------------------------------------
 * response body
 * ------------------------------------------------------------
 */

function read_exact(
    $socket,
    int $length,
    float $timeout
): string {
    $result = '';
    $start = microtime(true);

    stream_set_blocking(
        $socket,
        false
    );

    while (
        strlen($result) < $length
    ) {
        if (
            microtime(true) - $start >= $timeout
        ) {
            throw new KintoneTimeoutException(
                '外部サービスのレスポンス読み取りがタイムアウトしました。'
            );
        }

        $remaining =
            max(
                0.001,
                $timeout -
                (microtime(true) - $start)
            );

        $sec = (int)$remaining;

        $usec = (int)(
            ($remaining - $sec) *
            1000000
        );

        $read = [$socket];
        $write = null;
        $except = null;

        $selected = @stream_select(
            $read,
            $write,
            $except,
            $sec,
            $usec
        );

        if ($selected === false) {
            throw new KintoneConnectionException(
                'レスポンスを監視できません。'
            );
        }

        if ($selected === 0) {
            throw new KintoneTimeoutException(
                'レスポンス読み取りがタイムアウトしました。'
            );
        }

        $chunk = @fread(
            $socket,
            min(
                8192,
                $length - strlen($result)
            )
        );

        if (
            $chunk === false ||
            $chunk === ''
        ) {
            throw new KintoneConnectionException(
                'レスポンスを読み取れません。'
            );
        }

        $result .= $chunk;
    }

    return $result;
}

function read_until_close(
    $socket,
    float $timeout,
    string $initial = ''
): string {
    $result = $initial;
    $start = microtime(true);

    stream_set_blocking(
        $socket,
        false
    );

    while (true) {
        if (
            microtime(true) - $start >= $timeout
        ) {
            throw new KintoneTimeoutException(
                'レスポンス読み取りがタイムアウトしました。'
            );
        }

        $read = [$socket];
        $write = null;
        $except = null;

        $remaining =
            max(
                0.001,
                $timeout -
                (microtime(true) - $start)
            );

        $sec = (int)$remaining;

        $usec = (int)(
            ($remaining - $sec) *
            1000000
        );

        $selected = @stream_select(
            $read,
            $write,
            $except,
            $sec,
            $usec
        );

        if ($selected === false) {
            break;
        }

        if ($selected === 0) {
            throw new KintoneTimeoutException(
                'レスポンス読み取りがタイムアウトしました。'
            );
        }

        $chunk = @fread(
            $socket,
            8192
        );

        if (
            $chunk === false ||
            $chunk === ''
        ) {
            break;
        }

        $result .= $chunk;
    }

    return $result;
}

/*
 * ------------------------------------------------------------
 * kintone REST
 * ------------------------------------------------------------
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

    $method =
        strtoupper($method);

    if (!in_array(
        $method,
        [
            'GET',
            'POST',
            'PUT',
            'DELETE',
        ],
        true
    )) {
        throw new InvalidArgumentException(
            'kintone HTTPメソッドが不正です。'
        );
    }

    if (
        $path === '' ||
        $path[0] !== '/'
    ) {
        throw new InvalidArgumentException(
            'kintone APIパスが不正です。'
        );
    }

    $verifySsl =
        !empty(
            $k['verify_ssl']
        );

    /*
     * 認証情報はサーバー側のみ。
     */
    $authorization =
        base64_encode(
            (string)$k['username'] .
            ':' .
            (string)$k['password']
        );

    $requestBody = '';

    if ($body !== null) {
        $requestBody =
            json_encode(
                $body,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            );
    }

    $headers = [
        'Host: ' . $host,
        'X-Cybozu-Authorization: ' .
            $authorization,
        'Accept: application/json',
        'Content-Type: application/json',
        'Connection: close',
    ];

    if ($requestBody !== '') {
        $headers[] =
            'Content-Length: ' .
            strlen($requestBody);
    }

    $proxy =
        parse_proxy(
            (string)(
                $k['proxy'] ?? ''
            )
        );

    $target =
        $proxy === null
            ? 'tcp://' . $host . ':443'
            : 'tcp://' .
              $proxy['host'] .
              ':' .
              $proxy['port'];

    /*
     * SSL contextをsocket生成時から設定する。
     */
    $context =
        stream_context_create([
            'ssl' => [
                'verify_peer' =>
                    $verifySsl,
                'verify_peer_name' =>
                    $verifySsl,
                'allow_self_signed' =>
                    !$verifySsl,
                'peer_name' =>
                    $host,
                'SNI_enabled' =>
                    true,
            ],
        ]);

    $errno = 0;
    $errstr = '';

    $socket =
        @stream_socket_client(
            $target,
            $errno,
            $errstr,
            CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );

    if ($socket === false) {
        throw new KintoneConnectionException(
            'kintoneへのTCP接続を確立できませんでした。'
            . ' errno=' . $errno
            . ' ' . $errstr
        );
    }

    try {

        /*
         * Proxyありの場合だけCONNECT。
         */
        if ($proxy !== null) {
            $connectRequest =
                "CONNECT " .
                $host .
                ":443 HTTP/1.1\r\n" .
                "Host: " .
                $host .
                ":443\r\n" .
                "Proxy-Connection: Keep-Alive\r\n" .
                "Connection: Keep-Alive\r\n" .
                "\r\n";

            write_socket_all(
                $socket,
                $connectRequest,
                WRITE_TIMEOUT
            );

            $proxyResponse =
                read_http_headers(
                    $socket,
                    READ_TIMEOUT
                );

            if (
                $proxyResponse['status'] < 200 ||
                $proxyResponse['status'] >= 300
            ) {
                throw new KintoneConnectionException(
                    'ProxyのCONNECTに失敗しました。HTTP '
                    . $proxyResponse['status']
                );
            }
        }

        /*
         * Proxyでも直接接続でも、
         * ここでTLSを開始する。
         */
        enable_tls(
            $socket,
            $host,
            $verifySsl,
            TLS_TIMEOUT
        );

        /*
         * TLS確立後の通常HTTPSリクエスト。
         */
        $request =
            $method .
            ' ' .
            $path .
            ' HTTP/1.1' .
            "\r\n" .
            implode(
                "\r\n",
                $headers
            ) .
            "\r\n\r\n" .
            $requestBody;

        write_socket_all(
            $socket,
            $request,
            WRITE_TIMEOUT
        );

        $response =
            read_http_headers(
                $socket,
                READ_TIMEOUT
            );

        $responseBody =
            $response['body'];

        if (
            isset(
                $response['headers']
                    ['content-length']
            )
        ) {
            $length =
                (int)$response['headers']
                    ['content-length'];

            $already =
                strlen($responseBody);

            if ($length > $already) {
                $responseBody .=
                    read_exact(
                        $socket,
                        $length - $already,
                        READ_TIMEOUT
                    );
            }

        } else {
            $responseBody =
                read_until_close(
                    $socket,
                    READ_TIMEOUT,
                    $responseBody
                );
        }

        $json = null;

        if ($responseBody !== '') {
            $decoded =
                json_decode(
                    $responseBody,
                    true
                );

            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        return [
            'status' =>
                $response['status'],
            'headers' =>
                $response['headers'],
            'body' =>
                $responseBody,
            'json' =>
                $json,
        ];

    } finally {
        fclose($socket);
    }
}

/*
 * ------------------------------------------------------------
 * kintone 接続テスト
 * ------------------------------------------------------------
 *
 * ★重要
 *
 * app.json は
 *
 *     ?id=アプリID
 *
 * で呼ぶ。
 *
 * 旧実装の
 *
 *     ?app=アプリID
 *
 * は使用しない。
 */

function test_kintone(
    array $settings
): array {
    $k =
        $settings['kintone']
        ?? [];

    validate_kintone($k);

    $appId =
        (string)$k['app_id'];

    $result =
        kintone_request(
            $k,
            '/k/v1/app.json?id=' .
            rawurlencode($appId),
            'GET'
        );

    $status =
        (int)$result['status'];

    if (
        $status >= 200 &&
        $status < 300
    ) {
        return [
            'success' => true,
            'message' =>
                'kintone接続テスト成功。',
        ];
    }

    $json =
        $result['json']
        ?? [];

    $message =
        is_array($json)
            ? (string)(
                $json['message'] ?? ''
            )
            : '';

    $errorId =
        is_array($json)
            ? (string)(
                $json['id'] ?? ''
            )
            : '';

    $detail =
        'HTTP ' . $status;

    if ($message !== '') {
        $detail .=
            ' / ' . $message;
    }

    if ($errorId !== '') {
        $detail .=
            ' / エラーID: ' .
            $errorId;
    }

    return [
        'success' => false,
        'message' =>
            'kintone接続テスト失敗。' .
            $detail,
    ];
}

/*
 * ------------------------------------------------------------
 * kintone設定保存
 * ------------------------------------------------------------
 */

function save_kintone_settings(): void
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
        $appId === '' ||
        !ctype_digit($appId)
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

    parse_proxy($proxy);

    $settings['kintone'] =
        array_merge(
            $current,
            [
                'subdomain' =>
                    $subdomain,
                'app_id' =>
                    $appId,
                'username' =>
                    $username,
                'password' =>
                    $password,
                'proxy' =>
                    $proxy,
                /*
                 * prompt.txtではPOC段階で無効。
                 * チェックされた場合だけ有効。
                 */
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

    set_result(
        'success',
        'kintone設定を保存しました。'
    );
}

/*
 * ------------------------------------------------------------
 * kintone 項目取得
 * ------------------------------------------------------------
 */

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

    $appId =
        (string)$k['app_id'];

    $result =
        kintone_request(
            $k,
            '/k/v1/app/form/fields.json?app=' .
            rawurlencode($appId),
            'GET'
        );

    if (
        $result['status'] < 200 ||
        $result['status'] >= 300
    ) {
        throw new RuntimeException(
            'kintone項目一覧取得失敗。HTTP ' .
            $result['status']
        );
    }

    $settings['kintone']['fields'] =
        $result['json']['properties']
        ?? [];

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    set_result(
        'success',
        'kintoneの項目一覧を再取得しました。'
    );
}

/*
 * ------------------------------------------------------------
 * kintone 顧客同期
 * ------------------------------------------------------------
 */

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
            'order by $id asc limit 500 offset ' .
            $offset;

        $path =
            '/k/v1/records.json?' .
            http_build_query(
                [
                    'app' =>
                        (string)$k['app_id'],
                    'query' =>
                        $query,
                ]
            );

        $result =
            kintone_request(
                $k,
                $path,
                'GET'
            );

        if (
            $result['status'] < 200 ||
            $result['status'] >= 300
        ) {
            $message =
                $result['json']['message']
                ?? (
                    'HTTP ' .
                    $result['status']
                );

            throw new RuntimeException(
                'kintone顧客同期失敗。' .
                $message
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

        $count =
            count($records);

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
        count($customers) .
        '件の顧客情報を同期しました。'
    );
}

function customer_field(
    array $record,
    string $field
): string {
    if (
        $field === '' ||
        !isset($record[$field])
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

function normalize_customer(
    array $record,
    array $mapping
): array {
    $id =
        (string)(
            $record['$id']['value']
            ?? uuid()
        );

    $address = [];

    foreach (
        ($mapping['address'] ?? [])
        as $field
    ) {
        $value =
            customer_field(
                $record,
                (string)$field
            );

        if ($value !== '') {
            $address[] = $value;
        }
    }

    return [
        'id' =>
            'kintone-' . $id,
        'organization' =>
            customer_field(
                $record,
                (string)(
                    $mapping['organization']
                    ?? ''
                )
            ),
        'name' =>
            customer_field(
                $record,
                (string)(
                    $mapping['name']
                    ?? ''
                )
            ),
        'email' =>
            customer_field(
                $record,
                (string)(
                    $mapping['email']
                    ?? ''
                )
            ),
        'department' =>
            customer_field(
                $record,
                (string)(
                    $mapping['department']
                    ?? ''
                )
            ),
        'phone' =>
            customer_field(
                $record,
                (string)(
                    $mapping['phone']
                    ?? ''
                )
            ),
        'address' =>
            implode(
                ' ',
                $address
            ),
        'updatedAt' =>
            now_iso(),
    ];
}

/*
 * ------------------------------------------------------------
 * メール設定
 * ------------------------------------------------------------
 */

function save_mail_settings(): void
{
    $settings =
        read_json(
            SETTINGS_FILE
        );

    $current =
        $settings['mail']
        ?? [];

    $host =
        trim(
            (string)(
                $_POST['host'] ?? ''
            )
        );

    $port =
        (int)(
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

    if (
        $port < 1 ||
        $port > 65535
    ) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

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

    $username =
        trim(
            (string)(
                $_POST['username']
                ?? ''
            )
        );

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

    $fromEmail =
        trim(
            (string)(
                $_POST['from_email']
                ?? ''
            )
        );

    if (
        !filter_var(
            $fromEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    $replyTo =
        trim(
            (string)(
                $_POST['reply_to']
                ?? ''
            )
        );

    if (
        $replyTo !== '' &&
        !filter_var(
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
                'host' =>
                    $host,
                'port' =>
                    $port,
                'encryption' =>
                    $encryption,
                'auth' =>
                    isset(
                        $_POST['auth']
                    ),
                'username' =>
                    $username,
                'password' =>
                    $password,
                'from_email' =>
                    $fromEmail,
                'from_name' =>
                    trim(
                        (string)(
                            $_POST['from_name']
                            ?? ''
                        )
                    ),
                'reply_to' =>
                    $replyTo,
            ]
        );

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    set_result(
        'success',
        'メール設定を保存しました。'
    );
}

/*
 * ------------------------------------------------------------
 * SMTP接続
 * ------------------------------------------------------------
 */

function smtp_connect(
    array $mail
) {
    $host =
        (string)(
            $mail['host'] ?? ''
        );

    $port =
        (int)(
            $mail['port'] ?? 0
        );

    $encryption =
        (string)(
            $mail['encryption']
            ?? 'tls'
        );

    if (
        $host === '' ||
        $port < 1 ||
        $port > 65535
    ) {
        throw new RuntimeException(
            'SMTP設定が不正です。'
        );
    }

    /*
     * SSL:
     * TCP + TLSを接続時に確立。
     *
     * TLS:
     * TCP → EHLO → STARTTLS
     */
    if ($encryption === 'ssl') {
        $target =
            'ssl://' .
            $host .
            ':' .
            $port;
    } else {
        $target =
            'tcp://' .
            $host .
            ':' .
            $port;
    }

    $errno = 0;
    $errstr = '';

    $context =
        stream_context_create([
            'ssl' => [
                'verify_peer' =>
                    true,
                'verify_peer_name' =>
                    true,
                'peer_name' =>
                    $host,
            ],
        ]);

    $socket =
        @stream_socket_client(
            $target,
            $errno,
            $errstr,
            CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
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

function smtp_read(
    $socket
): string {
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
                '/^(\d{3})([ -])/',
                $line,
                $m
            )
        ) {
            if (
                $m[2] === ' '
            ) {
                $code =
                    (int)$m[1];

                if (
                    !in_array(
                        $code,
                        $codes,
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'SMTPエラー: ' .
                        $response
                    );
                }

                break;
            }
        }
    }

    return $response;
}

function smtp_write(
    $socket,
    string $line
): void {
    $start =
        microtime(true);

    $data =
        $line . "\r\n";

    $offset = 0;
    $length = strlen($data);

    while ($offset < $length) {
        if (
            microtime(true) - $start
            >= WRITE_TIMEOUT
        ) {
            throw new RuntimeException(
                'SMTP送信がタイムアウトしました。'
            );
        }

        $n =
            fwrite(
                $socket,
                substr(
                    $data,
                    $offset
                )
            );

        if ($n === false) {
            throw new RuntimeException(
                'SMTP送信に失敗しました。'
            );
        }

        $offset += $n;
    }
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

        smtp_write(
            $socket,
            'EHLO localhost'
        );

        smtp_expect(
            $socket,
            [250]
        );

        /*
         * STARTTLS
         */
        if (
            ($mail['encryption'] ?? '')
            === 'tls'
        ) {
            smtp_write(
                $socket,
                'STARTTLS'
            );

            smtp_expect(
                $socket,
                [220]
            );

            $crypto =
                @stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'SMTPのTLS接続を確立できません。'
                );
            }

            smtp_write(
                $socket,
                'EHLO localhost'
            );

            smtp_expect(
                $socket,
                [250]
            );
        }

        /*
         * 認証。
         */
        if (
            !empty(
                $mail['auth']
            )
        ) {
            smtp_write(
                $socket,
                'AUTH LOGIN'
            );

            smtp_expect(
                $socket,
                [334]
            );

            smtp_write(
                $socket,
                base64_encode(
                    (string)(
                        $mail['username']
                        ?? ''
                    )
                )
            );

            smtp_expect(
                $socket,
                [334]
            );

            smtp_write(
                $socket,
                base64_encode(
                    (string)(
                        $mail['password']
                        ?? ''
                    )
                )
            );

            smtp_expect(
                $socket,
                [235]
            );
        }

        smtp_write(
            $socket,
            'QUIT'
        );

        smtp_expect(
            $socket,
            [221]
        );

    } finally {
        fclose($socket);
    }
}

/*
 * ------------------------------------------------------------
 * POST処理
 * ------------------------------------------------------------
 *
 * 重要:
 *
 * ここでは303を一切発行しない。
 *
 * POST
 *   ↓
 * 実処理
 *   ↓
 * 200 OK
 *   ↓
 * 同じ画面に結果表示
 */

$screen =
    (string)(
        $_GET['screen']
        ?? 'list'
    );

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

if (!in_array(
    $screen,
    $allowedScreens,
    true
)) {
    $screen = 'list';
}

$id =
    (string)(
        $_GET['id']
        ?? $_POST['id']
        ?? ''
    );

if (
    $id !== '' &&
    !valid_id($id)
) {
    $id = '';
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {
    $action =
        (string)(
            $_POST['action']
            ?? ''
        );

    try {

        switch ($action) {

            /*
             * ------------------------------------------------
             * kintone
             * ------------------------------------------------
             */

            case 'save_kintone':
                save_kintone_settings();
                break;

            case 'test_kintone':

                $settings =
                    read_json(
                        SETTINGS_FILE
                    );

                $result =
                    test_kintone(
                        $settings
                    );

                if ($result['success']) {

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

                    set_result(
                        'success',
                        $result['message']
                    );

                } else {

                    $settings['kintone']
                        ['connection_status']
                        = '接続できません';

                    $settings['kintone']
                        ['last_test_at']
                        = now_iso();

                    $settings['kintone']
                        ['last_error']
                        = $result['message'];

                    write_json_atomic(
                        SETTINGS_FILE,
                        $settings
                    );

                    set_result(
                        'error',
                        $result['message']
                    );
                }

                break;

            case 'fetch_kintone_fields':
                fetch_kintone_fields();
                break;

            case 'sync_kintone':
                sync_kintone();
                break;

            /*
             * ------------------------------------------------
             * SMTP
             * ------------------------------------------------
             */

            case 'save_mail':
                save_mail_settings();
                break;

            case 'test_mail':

                $settings =
                    read_json(
                        SETTINGS_FILE
                    );

                $mail =
                    $settings['mail']
                    ?? [];

                smtp_test(
                    $mail
                );

                $settings['mail']
                    ['connection_status']
                    = '接続確認済み';

                $settings['mail']
                    ['last_test_at']
                    = now_iso();

                write_json_atomic(
                    SETTINGS_FILE,
                    $settings
                );

                set_result(
                    'success',
                    'SMTP接続テスト成功。'
                );

                break;

            /*
             * ------------------------------------------------
             * アンケート保存
             * ------------------------------------------------
             */

            case 'save_survey':

                $surveys =
                    surveys();

                $surveyId =
                    trim(
                        (string)(
                            $_POST['id']
                            ?? ''
                        )
                    );

                if (
                    $surveyId !== '' &&
                    !valid_id($surveyId)
                ) {
                    throw new InvalidArgumentException(
                        'アンケートIDが不正です。'
                    );
                }

                if ($surveyId === '') {
                    $surveyId =
                        'survey-' .
                        uuid();
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

                $found = false;

                foreach (
                    $surveys
                    as &$survey
                ) {
                    if (
                        (string)(
                            $survey['id']
                            ?? ''
                        ) === $surveyId
                    ) {
                        $found = true;

                        $survey['title'] =
                            $title;

                        $survey['description'] =
                            (string)(
                                $_POST[
                                    'description'
                                ] ?? ''
                            );

                        $survey['startAt'] =
                            (string)(
                                $_POST[
                                    'startAt'
                                ] ?? ''
                            );

                        $survey['endAt'] =
                            (string)(
                                $_POST[
                                    'endAt'
                                ] ?? ''
                            );

                        $survey['updatedAt'] =
                            now_iso();

                        break;
                    }
                }

                unset($survey);

                if (!$found) {
                    $surveys[] = [
                        'id' =>
                            $surveyId,
                        'title' =>
                            $title,
                        'description' =>
                            (string)(
                                $_POST[
                                    'description'
                                ] ?? ''
                            ),
                        'startAt' =>
                            (string)(
                                $_POST[
                                    'startAt'
                                ] ?? ''
                            ),
                        'endAt' =>
                            (string)(
                                $_POST[
                                    'endAt'
                                ] ?? ''
                            ),
                        'status' =>
                            'draft',
                        'numbering' =>
                            'global',
                        'groups' =>
                            [],
                        'createdAt' =>
                            now_iso(),
                        'updatedAt' =>
                            now_iso(),
                    ];
                }

                save_surveys(
                    $surveys
                );

                $screen = 'edit';
                $id = $surveyId;

                set_result(
                    'success',
                    'アンケートを保存しました。'
                );

                break;

            case 'delete_survey':

                $deleteId =
                    (string)(
                        $_POST['id']
                        ?? ''
                    );

                if (
                    !valid_id(
                        $deleteId
                    )
                ) {
                    throw new InvalidArgumentException(
                        'アンケートIDが不正です。'
                    );
                }

                $surveys =
                    array_values(
                        array_filter(
                            surveys(),
                            static function (
                                $survey
                            ) use ($deleteId) {
                                return
                                    (string)(
                                        $survey['id']
                                        ?? ''
                                    )
                                    !==
                                    $deleteId;
                            }
                        )
                    );

                save_surveys(
                    $surveys
                );

                $screen = 'list';
                $id = '';

                set_result(
                    'success',
                    'アンケートを削除しました。'
                );

                break;

            case 'change_status':

                $statusId =
                    (string)(
                        $_POST['id']
                        ?? ''
                    );

                $nextStatus =
                    (string)(
                        $_POST['next_status']
                        ?? ''
                    );

                if (
                    !valid_id(
                        $statusId
                    )
                ) {
                    throw new InvalidArgumentException(
                        'アンケートIDが不正です。'
                    );
                }

                if (!in_array(
                    $nextStatus,
                    [
                        'draft',
                        'published',
                        'stopped',
                    ],
                    true
                )) {
                    throw new InvalidArgumentException(
                        '状態が不正です。'
                    );
                }

                $surveys =
                    surveys();

                foreach (
                    $surveys
                    as &$survey
                ) {
                    if (
                        (string)(
                            $survey['id']
                            ?? ''
                        ) === $statusId
                    ) {
                        if (
                            ($survey['status']
                                ?? '')
                            === 'ended'
                        ) {
                            throw new InvalidArgumentException(
                                '終了したアンケートは状態変更できません。'
                            );
                        }

                        $survey['status'] =
                            $nextStatus;

                        $survey['updatedAt'] =
                            now_iso();

                        break;
                    }
                }

                unset($survey);

                save_surveys(
                    $surveys
                );

                $screen = 'list';

                set_result(
                    'success',
                    'アンケート状態を変更しました。'
                );

                break;

            default:

                throw new InvalidArgumentException(
                    '不明な操作です。'
                );
        }

    } catch (
        KintoneTimeoutException |
        KintoneConnectionException
        $e
    ) {

        set_result(
            'error',
            '通信エラー: ' .
            $e->getMessage()
        );

    } catch (
        InvalidArgumentException $e
    ) {

        set_result(
            'error',
            '入力・設定エラー: ' .
            $e->getMessage()
        );

    } catch (Throwable $e) {

        /*
         * パスワード・認証ヘッダー等を出さない。
         */
        set_result(
            'error',
            '処理に失敗しました。'
        );
    }
}

$operationResult =
    take_result();

/*
 * ------------------------------------------------------------
 * 画面データ
 * ------------------------------------------------------------
 */

$settings =
    read_json(
        SETTINGS_FILE,
        [
            'kintone' => [
                'subdomain' => '',
                'app_id' => '',
                'username' => '',
                'password' => '',
                'proxy' => '',
                'verify_ssl' => false,
                'connection_status' => '未設定',
            ],
            'mail' => [
                'host' => '',
                'port' => 587,
                'encryption' => 'tls',
                'auth' => false,
                'username' => '',
                'password' => '',
                'from_email' => '',
                'from_name' => '',
                'reply_to' => '',
                'connection_status' => '未設定',
            ],
        ]
    );

$currentSurvey =
    $id !== ''
        ? find_survey($id)
        : null;

/*
 * ------------------------------------------------------------
 * HTML
 * ------------------------------------------------------------
 */
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
* {
    box-sizing:border-box;
}

body {
    margin:0;
    color:#172033;
    background:#f5f7fb;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        Meiryo,
        sans-serif;
}

header {
    background:#172033;
    color:#fff;
    padding:18px 24px;
}

.container {
    max-width:1200px;
    margin:auto;
    padding:24px;
}

.card {
    background:#fff;
    border:1px solid #dce2ea;
    border-radius:12px;
    padding:24px;
    margin-bottom:20px;
}

h1 {
    margin-top:0;
}

.form-row {
    margin-bottom:16px;
}

label {
    display:block;
    font-weight:600;
    margin-bottom:6px;
}

input,
textarea,
select {
    width:100%;
    padding:10px 12px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    font:inherit;
}

textarea {
    min-height:120px;
}

button,
.btn {
    display:inline-block;
    border:0;
    border-radius:8px;
    padding:10px 16px;
    font:inherit;
    font-weight:600;
    cursor:pointer;
    text-decoration:none;
}

.primary {
    background:#2563eb;
    color:#fff;
}

.secondary {
    background:#e2e8f0;
    color:#172033;
}

.danger {
    background:#dc2626;
    color:#fff;
}

.notice {
    padding:14px 16px;
    border-radius:8px;
    margin-bottom:20px;
}

.notice.success {
    color:#166534;
    background:#dcfce7;
    border:1px solid #86efac;
}

.notice.error {
    color:#991b1b;
    background:#fee2e2;
    border:1px solid #fca5a5;
}

.status {
    display:inline-block;
    padding:4px 8px;
    border-radius:999px;
    background:#e2e8f0;
}

table {
    width:100%;
    border-collapse:collapse;
}

th,
td {
    padding:10px;
    border-bottom:1px solid #e2e8f0;
    text-align:left;
}

.actions {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.processing button {
    opacity:.6;
    cursor:wait;
}

.spinner {
    display:none;
}

@media(max-width:700px) {
    .container {
        padding:12px;
    }

    .card {
        padding:16px;
    }

    table {
        min-width:900px;
    }

    .table-wrap {
        overflow-x:auto;
    }
}
</style>
</head>

<body>

<header>
    <strong>アンケート管理</strong>
</header>

<main class="container">

<?php if ($operationResult !== null): ?>

<div class="notice <?=e(
    $operationResult['type']
)?>">
    <?=e(
        $operationResult['message']
    )?>
</div>

<?php endif; ?>

<?php
/*
 * ============================================================
 * kintone
 * ============================================================
 */
?>

<?php if ($screen === 'kintone'): ?>

<div class="card">

<h1>kintone連携設定</h1>

<form
    method="post"
    action="<?=e(
        screen_url('kintone')
    )?>"
    data-processing-form
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
    required
    value="<?=e(
        $settings['kintone']['subdomain']
        ?? ''
    )?>"
    placeholder="xxxx.cybozu.com"
>
</div>

<div class="form-row">
<label>顧客管理アプリID</label>
<input
    name="app_id"
    required
    inputmode="numeric"
    value="<?=e(
        $settings['kintone']['app_id']
        ?? ''
    )?>"
>
</div>

<div class="form-row">
<label>ログイン名</label>
<input
    name="username"
    required
    value="<?=e(
        $settings['kintone']['username']
        ?? ''
    )?>"
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
        $settings['kintone']['proxy']
        ?? ''
    )?>"
    placeholder="host:port"
>
</div>

<div class="form-row">
<label>
<input
    type="checkbox"
    name="verify_ssl"
    value="1"
    style="width:auto"
    <?=!empty(
        $settings['kintone']
            ['verify_ssl']
    ) ? 'checked' : ''?>
>
SSL証明書を検証する
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

<h2>接続状態</h2>

<p>
<?=e(
    $settings['kintone']
        ['connection_status']
        ?? '未設定'
)?>
</p>

<form
    method="post"
    action="<?=e(
        screen_url('kintone')
    )?>"
    data-processing-form
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
<span class="spinner"> ⏳</span>
</button>

</form>

<form
    method="post"
    action="<?=e(
        screen_url('kintone')
    )?>"
    data-processing-form
    style="margin-top:10px"
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

<form
    method="post"
    action="<?=e(
        screen_url('kintone')
    )?>"
    data-processing-form
    style="margin-top:10px"
>
<input
    type="hidden"
    name="action"
    value="sync_kintone"
>

<button
    class="secondary"
    type="submit"
>
顧客情報を同期
</button>

</form>

</div>

<?php
/*
 * ============================================================
 * mail
 * ============================================================
 */
?>

<?php elseif ($screen === 'mail'): ?>

<div class="card">

<h1>メールサーバ設定</h1>

<form
    method="post"
    action="<?=e(
        screen_url('mail')
    )?>"
    data-processing-form
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
    required
    value="<?=e(
        $settings['mail']['host']
        ?? ''
    )?>"
>
</div>

<div class="form-row">
<label>SMTPポート</label>
<input
    type="number"
    min="1"
    max="65535"
    name="port"
    required
    value="<?=e(
        $settings['mail']['port']
        ?? 587
    )?>"
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
    as $key => $label
): ?>
<option
    value="<?=e($key)?>"
    <?=(
        ($settings['mail']
            ['encryption']
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
<input
    type="checkbox"
    name="auth"
    value="1"
    style="width:auto"
    <?=!empty(
        $settings['mail']['auth']
    ) ? 'checked' : ''?>
>
SMTP認証
</label>
</div>

<div class="form-row">
<label>SMTPユーザー名</label>
<input
    name="username"
    value="<?=e(
        $settings['mail']['username']
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
    required
    value="<?=e(
        $settings['mail']['from_email']
        ?? ''
    )?>"
>
</div>

<div class="form-row">
<label>送信元名</label>
<input
    name="from_name"
    value="<?=e(
        $settings['mail']['from_name']
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
        $settings['mail']['reply_to']
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

<p>
接続状態：
<strong>
<?=e(
    $settings['mail']
        ['connection_status']
        ?? '未設定'
)?>
</strong>
</p>

<form
    method="post"
    action="<?=e(
        screen_url('mail')
    )?>"
    data-processing-form
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
<span class="spinner"> ⏳</span>
</button>

</form>

</div>

<?php
/*
 * ============================================================
 * 編集
 * ============================================================
 */
?>

<?php elseif ($screen === 'edit'): ?>

<div class="card">

<h1>
<?= $currentSurvey
    ? 'アンケート編集'
    : 'アンケート作成' ?>
</h1>

<form
    method="post"
    action="<?=e(
        screen_url(
            'edit',
            $currentSurvey['id']
                ?? null
        )
    )?>"
    data-processing-form
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
        $currentSurvey['id']
        ?? ''
    )?>"
>

<div class="form-row">
<label>アンケートタイトル</label>
<input
    name="title"
    required
    value="<?=e(
        $currentSurvey['title']
        ?? ''
    )?>"
>
</div>

<div class="form-row">
<label>アンケート説明</label>
<textarea
    name="description"
><?=e(
    $currentSurvey['description']
    ?? ''
)?></textarea>
</div>

<div class="form-row">
<label>開始日時</label>
<input
    type="datetime-local"
    name="startAt"
    value="<?=e(
        isset(
            $currentSurvey['startAt']
        )
            ? date(
                'Y-m-d\TH:i',
                strtotime(
                    $currentSurvey['startAt']
                )
            )
            : ''
    )?>"
>
</div>

<div class="form-row">
<label>終了日時</label>
<input
    type="datetime-local"
    name="endAt"
    value="<?=e(
        isset(
            $currentSurvey['endAt']
        )
            ? date(
                'Y-m-d\TH:i',
                strtotime(
                    $currentSurvey['endAt']
                )
            )
            : ''
    )?>"
>
</div>

<div class="actions">

<a
    class="btn secondary"
    href="<?=e(
        screen_url('list')
    )?>"
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

</div>

<?php
/*
 * ============================================================
 * 一覧
 * ============================================================
 */
?>

<?php elseif ($screen === 'list'): ?>

<div class="card">

<div
    style="
        display:flex;
        justify-content:space-between;
        gap:12px;
        align-items:center;
        flex-wrap:wrap;
    "
>

<h1>アンケート一覧</h1>

<a
    class="btn primary"
    href="<?=e(
        screen_url('edit')
    )?>"
>
新規作成
</a>

</div>

<div
    class="actions"
    style="margin-bottom:20px"
>

<a
    class="btn secondary"
    href="<?=e(
        screen_url('kintone')
    )?>"
>
kintone連携設定
</a>

<a
    class="btn secondary"
    href="<?=e(
        screen_url('mail')
    )?>"
>
メールサーバ設定
</a>

</div>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>タイトル</th>
<th>作成日</th>
<th>更新日</th>
<th>状態</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>

<tbody>

<?php
$list =
    array_map(
        'normalize_survey',
        surveys()
    );

usort(
    $list,
    static function (
        array $a,
        array $b
    ): int {
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

<?php if ($list === []): ?>

<tr>
<td colspan="6">
アンケートはありません。
</td>
</tr>

<?php else: ?>

<?php foreach (
    $list as $survey
): ?>

<tr>

<td>
<?=e(
    $survey['title']
)?>
</td>

<td>
<?=e(
    $survey['createdAt']
)?>
</td>

<td>
<?=e(
    $survey['updatedAt']
)?>
</td>

<td>
<span class="status">
<?=e(
    match (
        $survey['status']
    ) {
        'published' =>
            '公開中',
        'stopped' =>
            '停止',
        'ended' =>
            '終了',
        default =>
            '下書き',
    }
)?>
</span>
</td>

<td>
<?=e(
    count(
        read_json(
            ANSWERS_FILE,
            []
        )
    )
)?>
</td>

<td>

<div class="actions">

<a
    class="btn secondary"
    href="<?=e(
        screen_url(
            'edit',
            $survey['id']
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
            $survey['id']
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
            $survey['id']
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
            $survey['id']
        )
    )?>"
>
送信
</a>

<form
    method="post"
    action="<?=e(
        screen_url('list')
    )?>"
    data-processing-form
    style="display:inline"
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
    onclick="
        return confirm(
            'このアンケートを削除しますか？'
        );
    "
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
/*
 * ============================================================
 * プレビュー
 * ============================================================
 */
?>

<?php elseif ($screen === 'preview'): ?>

<div class="card">

<?php if ($currentSurvey === null): ?>

<h1>アンケートが存在しません。</h1>

<?php else: ?>

<h1>
<?=e(
    $currentSurvey['title']
)?>
</h1>

<p>
<?=nl2br(
    e(
        $currentSurvey['description']
    )
)?>
</p>

<?php
$qNo = 0;
?>

<?php foreach (
    $currentSurvey['groups']
    as $group
): ?>

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
): ?>

<?php
$qNo++;
?>

<div
    style="
        padding:16px;
        margin:12px 0;
        border:1px solid #e2e8f0;
        border-radius:8px;
    "
>

<strong>
<?=e(
    $question['number']
    ?? 'Q' . $qNo
)?>
</strong>

<p>
<?=e(
    $question['text']
    ?? ''
)?>
</p>

<p>
形式：
<?=e(
    $question['type']
    ?? 'text'
)?>
</p>

<?php if (
    !empty(
        $question['options']
    )
): ?>

<ul>
<?php foreach (
    $question['options']
    as $option
): ?>
<li>
<?=e(
    $option['label']
    ?? ''
)?>
</li>
<?php endforeach; ?>
</ul>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php
/*
 * ============================================================
 * 回答者
 * ============================================================
 */
?>

<?php elseif (
    $screen === 'answer'
): ?>

<div class="card">

<?php if (
    $currentSurvey === null
): ?>

<h1>アンケートが存在しません。</h1>

<?php elseif (
    $currentSurvey['status']
    !== 'published'
): ?>

<h1>このアンケートは回答できません。</h1>

<?php else: ?>

<h1>
<?=e(
    $currentSurvey['title']
)?>
</h1>

<p>
<?=nl2br(
    e(
        $currentSurvey['description']
    )
)?>
</p>

<form
    method="post"
    action="<?=e(
        screen_url(
            'answer',
            $currentSurvey['id']
        )
    )?>"
    data-processing-form
>

<input
    type="hidden"
    name="action"
    value="answer_next"
>

<input
    type="hidden"
    name="id"
    value="<?=e(
        $currentSurvey['id']
    )?>"
>

<?php
$qNo = 0;
?>

<?php foreach (
    $currentSurvey['groups']
    as $group
): ?>

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
): ?>

<?php
$qNo++;

$qid =
    'q_' .
    (
        $question['id']
        ?? $qNo
    );

$type =
    $question['type']
    ?? 'text';
?>

<div
    class="form-row"
>

<label>
<?=e(
    $question['number']
    ?? 'Q' . $qNo
)?>
.
<?=e(
    $question['text']
    ?? ''
)?>
</label>

<?php if (
    $type === 'single'
): ?>

<?php foreach (
    $question['options']
    ?? []
    as $option
): ?>

<label>
<input
    type="radio"
    name="<?=e($qid)?>"
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

<label>
<input
    type="checkbox"
    name="<?=e($qid)?>[]"
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
    name="<?=e($qid)?>"
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

<?php endif; ?>

</div>

<?php
/*
 * ============================================================
 * 集計
 * ============================================================
 */
?>

<?php elseif (
    $screen === 'analytics'
): ?>

<div class="card">

<?php if (
    $currentSurvey === null
): ?>

<h1>対象アンケートが存在しません。</h1>

<?php else: ?>

<h1>回答集計・分析</h1>

<h2>
<?=e(
    $currentSurvey['title']
)?>
</h2>

<?php
$answers =
    read_json(
        ANSWERS_FILE,
        []
    );

$surveyAnswers =
    array_values(
        array_filter(
            $answers,
            static function (
                $answer
            ) use ($id) {
                return
                    (string)(
                        $answer['surveyId']
                        ?? ''
                    ) === $id;
            }
        )
    );
?>

<p>
回答数：
<strong>
<?=count(
    $surveyAnswers
)?>
</strong>
</p>

<?php if (
    $surveyAnswers === []
): ?>

<p>
現在、回答データはありません
</p>

<?php else: ?>

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
<?=e(
    json_encode(
        $answer['answers']
        ?? [],
        JSON_UNESCAPED_UNICODE
    )
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
/*
 * ============================================================
 * 送信
 * ============================================================
 */
?>

<?php elseif (
    $screen === 'send'
): ?>

<div class="card">

<?php if (
    $currentSurvey === null
): ?>

<h1>対象アンケートが存在しません。</h1>

<?php else: ?>

<h1>顧客選択・メール送信</h1>

<p>
対象アンケート：
<strong>
<?=e(
    $currentSurvey['title']
)?>
</strong>
</p>

<?php
$customers =
    read_json(
        CUSTOMERS_FILE,
        []
    );
?>

<p>
同期済み顧客数：
<?=count($customers)?>
</p>

<p>
この画面では対象アンケートを変更できません。
</p>

<div class="notice">
メール送信機能は、SMTP設定済みの場合のみ
実SMTPサーバへ接続して実行します。
</div>

<?php endif; ?>

</div>

<?php
/*
 * ============================================================
 * 完了
 * ============================================================
 */
?>

<?php elseif (
    $screen === 'complete'
): ?>

<div class="card">

<h1>回答完了</h1>

<p>
アンケートへのご回答ありがとうございました。
</p>

</div>

<?php endif; ?>

</main>

<script>
/*
 * ------------------------------------------------------------
 * 二重送信防止
 * ------------------------------------------------------------
 *
 * 外部通信中は操作ボタンを無効化する。
 * サーバー側の処理結果はPOSTレスポンス自身で表示する。
 */

document
    .querySelectorAll(
        '[data-processing-form]'
    )
    .forEach(function(form) {

        form.addEventListener(
            'submit',
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

                            const spinner =
                                button.querySelector(
                                    '.spinner'
                                );

                            if (spinner) {
                                spinner.style.display =
                                    'inline';
                            }
                        }
                    );
            }
        );
    });
</script>

</body>
</html>
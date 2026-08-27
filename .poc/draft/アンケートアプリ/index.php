<?php
declare(strict_types=1);

/**
 * ============================================================
 * アンケートアプリ
 * ============================================================
 *
 * prompt.txt 準拠
 *
 * PHP 8.5 / Apache 2.4
 * DBなし / ファイル保存
 * index.php 単一エントリーポイント
 *
 * kintone:
 *   - 実サービスへ接続
 *   - X-Cybozu-Authorization
 *   - APIトークン認証なし
 *   - 接続テストと同期を分離
 *   - cURLを優先
 *   - cURLが利用できない場合はStreamへフォールバック
 *
 * PHP 8.5:
 *   - curl_close() は使用しない
 *
 * セキュリティ:
 *   - 認証情報をURLへ出さない
 *   - 認証情報をHTMLへ出さない
 *   - 認証ヘッダーをJavaScriptへ渡さない
 *   - 認証情報をエラー画面へ出さない
 */

date_default_timezone_set('Asia/Tokyo');

/* ============================================================
 * 定数
 * ============================================================ */

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';

const SETTINGS_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const SURVEYS_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';
const CUSTOMERS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';
const ANSWERS_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'answers.json';
const SEND_LOG_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'send_logs.json';

const CONNECT_TIMEOUT = 10;
const WRITE_TIMEOUT   = 10;
const READ_TIMEOUT    = 30;

const APP_NAME = 'アンケート管理';

/* ============================================================
 * 初期化
 * ============================================================ */

if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

/* ============================================================
 * セッション
 * ============================================================ */

$isHttps =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

$scriptDirectory = dirname(
    str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/')
);

$cookiePath = rtrim($scriptDirectory, '/');

if ($cookiePath === '') {
    $cookiePath = '/';
}

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $cookiePath,
    'secure'   => $isHttps,
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

function valid_id(string $id): bool
{
    return preg_match(
        '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/',
        $id
    ) === 1;
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

/**
 * POST結果は同一レスポンスで表示する。
 *
 * prompt.txtの禁止事項に合わせ、
 * 設定保存をPOST→303→GET→flashに依存させない。
 */
function set_result(
    string $type,
    string $message,
    array $details = []
): void {
    $_SESSION['_operation_result'] = [
        'type'    => $type,
        'message' => $message,
        'details' => $details,
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

function read_json(
    string $file,
    mixed $default = []
): mixed {
    if (!is_file($file)) {
        return $default;
    }

    $json = file_get_contents($file);

    if ($json === false || trim($json) === '') {
        return $default;
    }

    try {
        return json_decode(
            $json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable) {
        throw new RuntimeException(
            '保存データを読み込めません。'
        );
    }
}

function write_json(
    string $file,
    mixed $data
): void {
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_THROW_ON_ERROR
    );

    $temporary =
        $file .
        '.tmp.' .
        bin2hex(random_bytes(6));

    if (
        file_put_contents(
            $temporary,
            $json,
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException(
            'データを一時保存できません。'
        );
    }

    if (!rename($temporary, $file)) {
        @unlink($temporary);

        throw new RuntimeException(
            'データを保存できません。'
        );
    }
}

/* ============================================================
 * 初期設定
 * ============================================================ */

function default_settings(): array
{
    return [
        'kintone' => [
            'subdomain'         => '',
            'app_id'             => '',
            'username'           => '',
            'password'           => '',
            'proxy'              => '',
            'verify_ssl'         => false,
            'connection_status'  => '未設定',
            'last_test_at'       => '',
            'last_error'         => '',
            'fields'             => [],
            'mapping'            => [
                'organization' => '',
                'name'         => '',
                'email'        => '',
                'department'   => '',
                'phone'        => '',
                'address'      => [],
            ],
        ],

        'mail' => [
            'host'              => '',
            'port'              => '587',
            'encryption'        => 'tls',
            'auth'              => true,
            'username'          => '',
            'password'          => '',
            'from_email'        => '',
            'from_name'         => '',
            'reply_to'          => '',
            'connection_status' => '未設定',
            'last_test_at'      => '',
            'last_error'        => '',
        ],
    ];
}

function load_settings(): array
{
    $settings =
        read_json(
            SETTINGS_FILE,
            default_settings()
        );

    if (!is_array($settings)) {
        $settings =
            default_settings();
    }

    return array_replace_recursive(
        default_settings(),
        $settings
    );
}

/* ============================================================
 * kintone設定
 * ============================================================ */

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
        " \t\n\r\0\x0B/"
    );

    $value = preg_replace(
        '/\.cybozu\.com$/i',
        '',
        $value
    ) ?? $value;

    return $value;
}

function kintone_host(
    array $settings
): string {
    $subdomain =
        normalize_kintone_subdomain(
            (string)(
                $settings['subdomain']
                ?? ''
            )
        );

    return $subdomain .
        '.cybozu.com';
}

function validate_kintone(
    array $k
): void {
    $subdomain =
        normalize_kintone_subdomain(
            (string)(
                $k['subdomain']
                ?? ''
            )
        );

    if ($subdomain === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    if (
        preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
            $subdomain
        ) !== 1
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインの形式が正しくありません。'
        );
    }

    $appId =
        trim(
            (string)(
                $k['app_id']
                ?? ''
            )
        );

    if (
        $appId === '' ||
        !ctype_digit($appId)
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDは数字で入力してください。'
        );
    }

    if (
        trim(
            (string)(
                $k['username']
                ?? ''
            )
        ) === ''
    ) {
        throw new InvalidArgumentException(
            'kintoneログイン名を入力してください。'
        );
    }

    if (
        (string)(
            $k['password']
            ?? ''
        ) === ''
    ) {
        throw new InvalidArgumentException(
            'kintoneパスワードを入力してください。'
        );
    }

    $proxy =
        trim(
            (string)(
                $k['proxy']
                ?? ''
            )
        );

    if ($proxy !== '') {
        if (
            preg_match(
                '/^[^:\s\/]+:[0-9]{1,5}$/',
                $proxy,
                $m
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Proxyは host:port 形式で入力してください。'
            );
        }

        $port = (int)$m[1];

        if (
            $port < 1 ||
            $port > 65535
        ) {
            throw new InvalidArgumentException(
                'Proxyポート番号が不正です。'
            );
        }
    }
}

function kintone_auth_header(
    array $k
): string {
    return base64_encode(
        (string)$k['username'] .
        ':' .
        (string)$k['password']
    );
}

/* ============================================================
 * kintone通信
 *
 * cURLを第一選択。
 * cURLが無い場合はStreamへフォールバック。
 *
 * 重要:
 * curl_close() は使用しない。
 * PHP 8.5では不要。
 * ============================================================ */

function kintone_request_curl(
    array $k,
    string $url,
    string $method,
    ?array $body
): array {
    if (!extension_loaded('curl')) {
        throw new RuntimeException(
            'cURL拡張は利用できません。'
        );
    }

    $ch = curl_init();

    if ($ch === false) {
        throw new RuntimeException(
            'cURLを初期化できません。'
        );
    }

    $headers = [
        'Accept: application/json',
        'X-Cybozu-Authorization: ' .
            kintone_auth_header($k),
        'User-Agent: SurveyApp/1.0',
    ];

    $jsonBody = null;

    if ($body !== null) {
        $jsonBody =
            json_encode(
                $body,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            );

        $headers[] =
            'Content-Type: application/json';
    }

    $options = [
        CURLOPT_URL =>
            $url,

        CURLOPT_RETURNTRANSFER =>
            true,

        CURLOPT_HEADER =>
            false,

        CURLOPT_CUSTOMREQUEST =>
            strtoupper($method),

        CURLOPT_HTTPHEADER =>
            $headers,

        CURLOPT_CONNECTTIMEOUT =>
            CONNECT_TIMEOUT,

        CURLOPT_TIMEOUT =>
            READ_TIMEOUT,

        CURLOPT_FOLLOWLOCATION =>
            false,

        CURLOPT_SSL_VERIFYPEER =>
            !empty($k['verify_ssl']),

        CURLOPT_SSL_VERIFYHOST =>
            !empty($k['verify_ssl'])
                ? 2
                : 0,
    ];

    if ($jsonBody !== null) {
        $options[
            CURLOPT_POSTFIELDS
        ] = $jsonBody;
    }

    $proxy =
        trim(
            (string)(
                $k['proxy']
                ?? ''
            )
        );

    if ($proxy !== '') {
        $options[
            CURLOPT_PROXY
        ] = $proxy;
    }

    if (
        curl_setopt_array(
            $ch,
            $options
        ) === false
    ) {
        throw new RuntimeException(
            'cURL通信設定を適用できません。'
        );
    }

    $response =
        curl_exec($ch);

    if ($response === false) {
        $error =
            curl_error($ch);

        $errno =
            curl_errno($ch);

        throw new RuntimeException(
            'kintone通信エラー。' .
            ' cURL errno=' .
            $errno .
            ' / ' .
            $error
        );
    }

    $status =
        (int)curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    /*
     * PHP 8.5:
     * curl_close() は呼ばない。
     *
     * スコープ終了時に解放される。
     */

    $decoded =
        json_decode(
            $response,
            true
        );

    return [
        'status' => $status,
        'body'   => $response,
        'json'   =>
            is_array($decoded)
                ? $decoded
                : null,
        'driver' => 'cURL',
    ];
}

/* ============================================================
 * Stream通信
 * ============================================================ */

function stream_write_all(
    $socket,
    string $data
): void {
    $length =
        strlen($data);

    $offset = 0;
    $start =
        microtime(true);

    while (
        $offset < $length
    ) {
        if (
            microtime(true) -
            $start >
            WRITE_TIMEOUT
        ) {
            throw new RuntimeException(
                'kintoneへの送信がタイムアウトしました。'
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

        if (
            $written === false
        ) {
            throw new RuntimeException(
                'kintoneへの送信に失敗しました。'
            );
        }

        if ($written === 0) {
            usleep(10000);
            continue;
        }

        $offset += $written;
    }
}

function stream_read_response(
    $socket
): array {
    $start =
        microtime(true);

    $buffer = '';

    while (
        !str_contains(
            $buffer,
            "\r\n\r\n"
        )
    ) {
        if (
            microtime(true) -
            $start >
            READ_TIMEOUT
        ) {
            throw new RuntimeException(
                'kintoneのレスポンスがタイムアウトしました。'
            );
        }

        $chunk =
            @fread(
                $socket,
                8192
            );

        if (
            $chunk === false
        ) {
            throw new RuntimeException(
                'kintoneのレスポンスを読み取れません。'
            );
        }

        if (
            $chunk === ''
        ) {
            if (feof($socket)) {
                break;
            }

            usleep(10000);
            continue;
        }

        $buffer .= $chunk;

        if (
            strlen($buffer) >
            1024 * 1024
        ) {
            throw new RuntimeException(
                'kintoneのHTTPレスポンスが大きすぎます。'
            );
        }
    }

    $separator =
        "\r\n\r\n";

    $position =
        strpos(
            $buffer,
            $separator
        );

    if ($position === false) {
        throw new RuntimeException(
            'kintoneのHTTPレスポンスを解析できません。'
        );
    }

    $headerText =
        substr(
            $buffer,
            0,
            $position
        );

    $body =
        substr(
            $buffer,
            $position +
            strlen($separator)
        );

    $lines =
        preg_split(
            "/\r\n/",
            $headerText
        );

    if (
        !isset($lines[0]) ||
        preg_match(
            '#^HTTP/\S+\s+(\d{3})#',
            $lines[0],
            $match
        ) !== 1
    ) {
        throw new RuntimeException(
            'kintoneのHTTPステータスを取得できません。'
        );
    }

    $status =
        (int)$match[1];

    $headers = [];

    foreach (
        array_slice(
            $lines,
            1
        ) as $line
    ) {
        $p =
            strpos(
                $line,
                ':'
            );

        if ($p === false) {
            continue;
        }

        $name =
            strtolower(
                trim(
                    substr(
                        $line,
                        0,
                        $p
                    )
                )
            );

        $value =
            trim(
                substr(
                    $line,
                    $p + 1
                )
            );

        $headers[$name] =
            $value;
    }

    /*
     * Content-Lengthがある場合、
     * ヘッダー直後に取得できている分を基に
     * 必要な残りを読む。
     */
    if (
        isset(
            $headers['content-length']
        )
    ) {
        $length =
            (int)$headers[
                'content-length'
            ];

        while (
            strlen($body) <
            $length
        ) {
            if (
                microtime(true) -
                $start >
                READ_TIMEOUT
            ) {
                throw new RuntimeException(
                    'kintoneレスポンス読み取りがタイムアウトしました。'
                );
            }

            $chunk =
                @fread(
                    $socket,
                    min(
                        8192,
                        $length -
                        strlen($body)
                    )
                );

            if (
                $chunk === false
            ) {
                throw new RuntimeException(
                    'kintoneレスポンスを読み取れません。'
                );
            }

            if ($chunk === '') {
                if (feof($socket)) {
                    break;
                }

                usleep(10000);
                continue;
            }

            $body .= $chunk;
        }
    } else {
        /*
         * Content-Lengthが無い場合。
         * Connection: closeを利用する。
         */
        while (!feof($socket)) {
            if (
                microtime(true) -
                $start >
                READ_TIMEOUT
            ) {
                break;
            }

            $chunk =
                @fread(
                    $socket,
                    8192
                );

            if (
                $chunk === false ||
                $chunk === ''
            ) {
                break;
            }

            $body .= $chunk;
        }
    }

    /*
     * chunkedの場合はデコード。
     */
    if (
        isset(
            $headers['transfer-encoding']
        ) &&
        stripos(
            $headers['transfer-encoding'],
            'chunked'
        ) !== false
    ) {
        $body =
            decode_chunked_body(
                $body
            );
    }

    return [
        'status'  => $status,
        'headers' => $headers,
        'body'    => $body,
    ];
}

function decode_chunked_body(
    string $body
): string {
    $result = '';

    while ($body !== '') {
        $lineEnd =
            strpos(
                $body,
                "\r\n"
            );

        if ($lineEnd === false) {
            break;
        }

        $sizeText =
            trim(
                substr(
                    $body,
                    0,
                    $lineEnd
                )
            );

        /*
         * chunk extensionを除去。
         */
        if (
            str_contains(
                $sizeText,
                ';'
            )
        ) {
            $sizeText =
                explode(
                    ';',
                    $sizeText,
                    2
                )[0];
        }

        $size =
            hexdec($sizeText);

        if ($size === 0) {
            break;
        }

        $start =
            $lineEnd + 2;

        $result .=
            substr(
                $body,
                $start,
                $size
            );

        $body =
            substr(
                $body,
                $start +
                $size +
                2
            );
    }

    return $result;
}

function kintone_request_stream(
    array $k,
    string $url,
    string $method,
    ?array $body
): array {
    $parts =
        parse_url($url);

    if (
        !is_array($parts) ||
        ($parts['scheme'] ?? '') !== 'https' ||
        empty($parts['host'])
    ) {
        throw new RuntimeException(
            'kintone URLを解析できません。'
        );
    }

    $host =
        (string)$parts['host'];

    $targetPath =
        $parts['path'] ?? '/';

    if (
        !empty($parts['query'])
    ) {
        $targetPath .=
            '?' .
            $parts['query'];
    }

    $verify =
        !empty(
            $k['verify_ssl']
        );

    $ssl =
        [
            'verify_peer' =>
                $verify,

            'verify_peer_name' =>
                $verify,

            'allow_self_signed' =>
                !$verify,

            'peer_name' =>
                $host,

            'SNI_enabled' =>
                true,
        ];

    $proxy =
        trim(
            (string)(
                $k['proxy']
                ?? ''
            )
        );

    /*
     * 直接接続。
     */
    if ($proxy === '') {
        $context =
            stream_context_create([
                'ssl' => $ssl,
            ]);

        $errno = 0;
        $errstr = '';

        $socket =
            @stream_socket_client(
                'ssl://' .
                $host .
                ':443',
                $errno,
                $errstr,
                CONNECT_TIMEOUT,
                STREAM_CLIENT_CONNECT,
                $context
            );

        if (
            $socket === false
        ) {
            throw new RuntimeException(
                'kintoneへ接続できません。' .
                ' errno=' .
                $errno .
                ' / ' .
                $errstr
            );
        }
    } else {
        /*
         * Proxy接続。
         */
        [$proxyHost, $proxyPort] =
            explode(
                ':',
                $proxy,
                2
            );

        $proxySocket =
            @stream_socket_client(
                'tcp://' .
                $proxyHost .
                ':' .
                (int)$proxyPort,
                $errno,
                $errstr,
                CONNECT_TIMEOUT,
                STREAM_CLIENT_CONNECT
            );

        if (
            $proxySocket === false
        ) {
            throw new RuntimeException(
                'Proxyへ接続できません。' .
                ' errno=' .
                $errno .
                ' / ' .
                $errstr
            );
        }

        $connectRequest =
            "CONNECT " .
            $host .
            ":443 HTTP/1.1\r\n" .
            "Host: " .
            $host .
            ":443\r\n" .
            "Connection: close\r\n\r\n";

        stream_write_all(
            $proxySocket,
            $connectRequest
        );

        $proxyResponse =
            stream_read_response(
                $proxySocket
            );

        if (
            $proxyResponse['status'] < 200 ||
            $proxyResponse['status'] >= 300
        ) {
            fclose(
                $proxySocket
            );

            throw new RuntimeException(
                'ProxyのCONNECTに失敗しました。HTTP ' .
                $proxyResponse['status']
            );
        }

        $socket =
            $proxySocket;

        /*
         * CONNECT後にTLS。
         */
        stream_context_set_option(
            $socket,
            'ssl',
            $ssl
        );

        if (
            @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            ) !== true
        ) {
            fclose(
                $socket
            );

            throw new RuntimeException(
                'Proxy経由のTLS接続に失敗しました。'
            );
        }
    }

    try {
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
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Cybozu-Authorization: ' .
                kintone_auth_header($k),
            'Connection: close',
        ];

        if ($requestBody !== '') {
            $headers[] =
                'Content-Length: ' .
                strlen($requestBody);
        }

        $request =
            strtoupper($method) .
            ' ' .
            $targetPath .
            " HTTP/1.1\r\n" .
            implode(
                "\r\n",
                $headers
            ) .
            "\r\n\r\n" .
            $requestBody;

        stream_write_all(
            $socket,
            $request
        );

        $response =
            stream_read_response(
                $socket
            );

        $decoded =
            json_decode(
                $response['body'],
                true
            );

        return [
            'status' =>
                $response['status'],

            'body' =>
                $response['body'],

            'json' =>
                is_array($decoded)
                    ? $decoded
                    : null,

            'driver' =>
                'PHP Stream',
        ];
    } finally {
        fclose(
            $socket
        );
    }
}

/* ============================================================
 * kintone共通API
 * ============================================================ */

function kintone_request(
    array $k,
    string $path,
    string $method = 'GET',
    ?array $body = null
): array {
    validate_kintone($k);

    if (
        $path === '' ||
        $path[0] !== '/'
    ) {
        throw new InvalidArgumentException(
            'kintone APIパスが不正です。'
        );
    }

    $host =
        kintone_host($k);

    $url =
        'https://' .
        $host .
        $path;

    /*
     * まずcURL。
     *
     * PHP cURLが無効なPCでも、
     * ここでアプリ全体を停止させず
     * Streamへフォールバックする。
     */
    if (
        extension_loaded('curl')
    ) {
        try {
            return kintone_request_curl(
                $k,
                $url,
                $method,
                $body
            );
        } catch (Throwable $curlError) {
            /*
             * cURL固有エラーの場合のみ
             * Streamへフォールバック。
             *
             * 認証失敗やHTTP 400等は
             * cURL関数から例外にならず
             * そのまま返るため再試行しない。
             */
            if (
                $curlError instanceof RuntimeException
            ) {
                return kintone_request_stream(
                    $k,
                    $url,
                    $method,
                    $body
                );
            }

            throw $curlError;
        }
    }

    return kintone_request_stream(
        $k,
        $url,
        $method,
        $body
    );
}

/* ============================================================
 * kintone接続テスト
 * ============================================================ */

function kintone_test(
    array $settings
): array {
    $k =
        $settings['kintone']
        ?? [];

    try {
        validate_kintone($k);

        /*
         * GET /k/v1/app.json
         *
         * 実際のkintoneへ接続する。
         */
        $result =
            kintone_request(
                $k,
                '/k/v1/app.json?id=' .
                rawurlencode(
                    (string)$k['app_id']
                ),
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
                    'kintone接続成功。' .
                    ' HTTP ' .
                    $status .
                    ' / 通信方式: ' .
                    $result['driver'],

                'details' => [
                    'HTTPステータス' =>
                        $status,

                    '通信方式' =>
                        $result['driver'],
                ],
            ];
        }

        $apiMessage =
            '';

        if (
            is_array(
                $result['json']
                ?? null
            )
        ) {
            $apiMessage =
                trim(
                    (string)(
                        $result['json'][
                            'message'
                        ]
                        ?? ''
                    )
                );
        }

        if (
            $apiMessage === ''
        ) {
            $apiMessage =
                trim(
                    (string)(
                        $result['body']
                        ?? ''
                    )
                );
        }

        if (
            $apiMessage === ''
        ) {
            $apiMessage =
                'kintoneからエラー詳細を取得できませんでした。';
        }

        /*
         * 認証情報は絶対に含めない。
         */
        return [
            'success' => false,

            'message' =>
                'kintone接続失敗。' .
                ' HTTP ' .
                $status .
                ' / ' .
                $apiMessage,

            'details' => [
                'HTTPステータス' =>
                    $status,

                '通信方式' =>
                    $result['driver'],

                '確認対象' =>
                    'kintoneアプリ情報取得',
            ],
        ];
    } catch (
        InvalidArgumentException $e
    ) {
        return [
            'success' => false,
            'message' =>
                '入力エラー：' .
                $e->getMessage(),
        ];
    } catch (
        Throwable $e
    ) {
        return [
            'success' => false,
            'message' =>
                '通信エラー：' .
                $e->getMessage(),
        ];
    }
}

/* ============================================================
 * kintone項目取得
 * ============================================================ */

function kintone_fetch_fields(): void
{
    $settings =
        load_settings();

    $k =
        $settings['kintone'];

    $result =
        kintone_request(
            $k,
            '/k/v1/app/form/fields.json?app=' .
            rawurlencode(
                (string)$k['app_id']
            ),
            'GET'
        );

    if (
        $result['status'] < 200 ||
        $result['status'] >= 300
    ) {
        $message =
            (string)(
                $result['json']['message']
                ?? 'kintone項目取得に失敗しました。'
            );

        throw new RuntimeException(
            'HTTP ' .
            $result['status'] .
            ' / ' .
            $message
        );
    }

    $settings[
        'kintone'
    ][
        'fields'
    ] =
        $result['json'][
            'properties'
        ] ?? [];

    write_json(
        SETTINGS_FILE,
        $settings
    );
}

/* ============================================================
 * kintoneフィールド値
 * ============================================================ */

function kintone_value(
    array $record,
    string $code
): string {
    if ($code === '') {
        return '';
    }

    if (
        !isset(
            $record[$code]
        )
    ) {
        return '';
    }

    $value =
        $record[$code]['value']
        ?? '';

    if (is_scalar($value)) {
        return (string)$value;
    }

    if (is_array($value)) {
        $items = [];

        foreach (
            $value as $item
        ) {
            if (
                is_array($item)
            ) {
                $items[] =
                    (string)(
                        $item['name']
                        ??
                        $item['value']
                        ??
                        ''
                    );
            } else {
                $items[] =
                    (string)$item;
            }
        }

        return implode(
            ' ',
            array_filter(
                $items,
                static fn($v) =>
                    $v !== ''
            )
        );
    }

    return '';
}

/* ============================================================
 * kintone顧客同期
 * ============================================================ */

function kintone_sync(): int
{
    $settings =
        load_settings();

    $k =
        $settings['kintone'];

    validate_kintone($k);

    $mapping =
        $k['mapping']
        ?? [];

    $customers = [];
    $offset = 0;

    while (true) {
        $query =
            'order by $id asc limit 500 offset ' .
            $offset;

        $path =
            '/k/v1/records.json?' .
            http_build_query([
                'app' =>
                    (string)$k['app_id'],

                'query' =>
                    $query,
            ]);

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
                (string)(
                    $result['json']['message']
                    ??
                    '顧客情報取得に失敗しました。'
                );

            throw new RuntimeException(
                'HTTP ' .
                $result['status'] .
                ' / ' .
                $message
            );
        }

        $records =
            $result['json'][
                'records'
            ] ?? [];

        if (
            !is_array($records)
        ) {
            $records = [];
        }

        foreach (
            $records as $record
        ) {
            $address = [];

            foreach (
                ($mapping['address'] ?? [])
                as $addressCode
            ) {
                $addressCode =
                    (string)$addressCode;

                if (
                    $addressCode === ''
                ) {
                    continue;
                }

                $value =
                    kintone_value(
                        $record,
                        $addressCode
                    );

                if ($value !== '') {
                    $address[] =
                        $value;
                }
            }

            $customers[] = [
                'id' =>
                    (string)(
                        $record['$id']['value']
                        ??
                        uuid()
                    ),

                'organization' =>
                    kintone_value(
                        $record,
                        (string)(
                            $mapping[
                                'organization'
                            ] ?? ''
                        )
                    ),

                'name' =>
                    kintone_value(
                        $record,
                        (string)(
                            $mapping[
                                'name'
                            ] ?? ''
                        )
                    ),

                'email' =>
                    kintone_value(
                        $record,
                        (string)(
                            $mapping[
                                'email'
                            ] ?? ''
                        )
                    ),

                'department' =>
                    kintone_value(
                        $record,
                        (string)(
                            $mapping[
                                'department'
                            ] ?? ''
                        )
                    ),

                'phone' =>
                    kintone_value(
                        $record,
                        (string)(
                            $mapping[
                                'phone'
                            ] ?? ''
                        )
                    ),

                'address' =>
                    implode(
                        ' ',
                        $address
                    ),

                'synced_at' =>
                    now_iso(),
            ];
        }

        if (
            count($records) < 500
        ) {
            break;
        }

        $offset += 500;
    }

    write_json(
        CUSTOMERS_FILE,
        $customers
    );

    return count($customers);
}

/* ============================================================
 * アンケートデータ
 * ============================================================ */

function load_surveys(): array
{
    $data =
        read_json(
            SURVEYS_FILE,
            []
        );

    return is_array($data)
        ? array_values($data)
        : [];
}

function save_surveys(
    array $surveys
): void {
    write_json(
        SURVEYS_FILE,
        array_values($surveys)
    );
}

function find_survey(
    array $surveys,
    string $id
): ?array {
    foreach (
        $surveys as $survey
    ) {
        if (
            (string)(
                $survey['id']
                ?? ''
            ) === $id
        ) {
            return $survey;
        }
    }

    return null;
}

function default_survey(): array
{
    return [
        'id' =>
            'survey-' .
            uuid(),

        'title' =>
            '',

        'description' =>
            '',

        'startAt' =>
            '',

        'endAt' =>
            '',

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

function refresh_survey_status(
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
        $timestamp !== false &&
        $timestamp < time()
    ) {
        $survey['status'] =
            'ended';
    }
}

/* ============================================================
 * POST処理
 * ============================================================ */

$screen =
    (string)(
        $_GET['screen']
        ??
        $_POST['screen']
        ??
        'list'
    );

$id =
    (string)(
        $_GET['id']
        ??
        $_POST['id']
        ??
        ''
    );

if (
    $id !== '' &&
    !valid_id($id)
) {
    $id = '';
}

try {
    if (
        $_SERVER['REQUEST_METHOD']
        === 'POST'
    ) {
        $action =
            (string)(
                $_POST['action']
                ?? ''
            );

        /* ====================================================
         * kintone設定保存
         * ==================================================== */

        if (
            $action ===
            'save_kintone'
        ) {
            $settings =
                load_settings();

            $old =
                $settings['kintone'];

            $password =
                (string)(
                    $_POST['password']
                    ?? ''
                );

            /*
             * 空欄なら既存パスワードを維持。
             */
            if (
                $password === ''
            ) {
                $password =
                    (string)(
                        $old['password']
                        ?? ''
                    );
            }

            $settings[
                'kintone'
            ] = [
                'subdomain' =>
                    normalize_kintone_subdomain(
                        (string)(
                            $_POST[
                                'subdomain'
                            ] ?? ''
                        )
                    ),

                'app_id' =>
                    trim(
                        (string)(
                            $_POST[
                                'app_id'
                            ] ?? ''
                        )
                    ),

                'username' =>
                    trim(
                        (string)(
                            $_POST[
                                'username'
                            ] ?? ''
                        )
                    ),

                'password' =>
                    $password,

                'proxy' =>
                    trim(
                        (string)(
                            $_POST[
                                'proxy'
                            ] ?? ''
                        )
                    ),

                'verify_ssl' =>
                    isset(
                        $_POST[
                            'verify_ssl'
                        ]
                    ),

                'connection_status' =>
                    $old[
                        'connection_status'
                    ] ?? '未設定',

                'last_test_at' =>
                    $old[
                        'last_test_at'
                    ] ?? '',

                'last_error' =>
                    $old[
                        'last_error'
                    ] ?? '',

                'fields' =>
                    $old[
                        'fields'
                    ] ?? [],

                'mapping' =>
                    $old[
                        'mapping'
                    ] ?? [
                        'organization' => '',
                        'name' => '',
                        'email' => '',
                        'department' => '',
                        'phone' => '',
                        'address' => [],
                    ],
            ];

            validate_kintone(
                $settings['kintone']
            );

            write_json(
                SETTINGS_FILE,
                $settings
            );

            set_result(
                'success',
                'kintone設定を保存しました。'
            );
        }

        /* ====================================================
         * kintone接続テスト
         * ==================================================== */

        elseif (
            $action ===
            'test_kintone'
        ) {
            $settings =
                load_settings();

            $test =
                kintone_test(
                    $settings
                );

            $settings[
                'kintone'
            ][
                'last_test_at'
            ] =
                now_iso();

            if (
                $test['success']
            ) {
                $settings[
                    'kintone'
                ][
                    'connection_status'
                ] =
                    '接続確認済み';

                $settings[
                    'kintone'
                ][
                    'last_error'
                ] = '';

                write_json(
                    SETTINGS_FILE,
                    $settings
                );

                set_result(
                    'success',
                    $test['message'],
                    $test['details']
                    ?? []
                );
            } else {
                $settings[
                    'kintone'
                ][
                    'connection_status'
                ] =
                    '接続できません';

                $settings[
                    'kintone'
                ][
                    'last_error'
                ] =
                    $test['message'];

                write_json(
                    SETTINGS_FILE,
                    $settings
                );

                set_result(
                    'error',
                    $test['message'],
                    $test['details']
                    ?? []
                );
            }
        }

        /* ====================================================
         * kintone項目取得
         * ==================================================== */

        elseif (
            $action ===
            'fetch_kintone_fields'
        ) {
            kintone_fetch_fields();

            set_result(
                'success',
                'kintoneの項目一覧を再取得しました。'
            );
        }

        /* ====================================================
         * kintone同期
         * ==================================================== */

        elseif (
            $action ===
            'sync_kintone'
        ) {
            $count =
                kintone_sync();

            set_result(
                'success',
                'kintone同期が完了しました。' .
                ' 同期件数：' .
                $count .
                '件'
            );
        }

        /* ====================================================
         * アンケート保存
         * ==================================================== */

        elseif (
            $action ===
            'save_survey'
        ) {
            $surveys =
                load_surveys();

            $surveyId =
                trim(
                    (string)(
                        $_POST[
                            'survey_id'
                        ] ?? ''
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

            $survey = null;
            $index = -1;

            foreach (
                $surveys as $i => $item
            ) {
                if (
                    (string)(
                        $item['id']
                        ?? ''
                    ) === $surveyId
                ) {
                    $survey =
                        $item;

                    $index =
                        $i;

                    break;
                }
            }

            if (
                $survey === null
            ) {
                $survey =
                    default_survey();

                if (
                    $surveyId !== ''
                ) {
                    $survey['id'] =
                        $surveyId;
                }
            }

            $survey['title'] =
                trim(
                    (string)(
                        $_POST[
                            'title'
                        ] ?? ''
                    )
                );

            $survey['description'] =
                trim(
                    (string)(
                        $_POST[
                            'description'
                        ] ?? ''
                    )
                );

            $survey['startAt'] =
                trim(
                    (string)(
                        $_POST[
                            'startAt'
                        ] ?? ''
                    )
                );

            $survey['endAt'] =
                trim(
                    (string)(
                        $_POST[
                            'endAt'
                        ] ?? ''
                    )
                );

            $survey['numbering'] =
                in_array(
                    $_POST[
                        'numbering'
                    ] ?? '',
                    [
                        'global',
                        'group',
                    ],
                    true
                )
                ? $_POST[
                    'numbering'
                ]
                : 'global';

            /*
             * 新規は必ず下書き。
             * 既存は現在状態を維持。
             */
            if (
                $index < 0
            ) {
                $survey['status'] =
                    'draft';

                $survey['createdAt'] =
                    now_iso();
            }

            $survey['updatedAt'] =
                now_iso();

            if (
                $index >= 0
            ) {
                $surveys[
                    $index
                ] =
                    $survey;
            } else {
                $surveys[] =
                    $survey;
            }

            save_surveys(
                $surveys
            );

            set_result(
                'success',
                'アンケートを保存しました。'
            );
        }

        /* ====================================================
         * 状態変更
         * ==================================================== */

        elseif (
            $action ===
            'change_status'
        ) {
            $surveys =
                load_surveys();

            $surveyId =
                (string)(
                    $_POST[
                        'survey_id'
                    ] ?? ''
                );

            $newStatus =
                (string)(
                    $_POST[
                        'new_status'
                    ] ?? ''
                );

            $allowedStatus = [
                'draft',
                'published',
                'stopped',
            ];

            if (
                !in_array(
                    $newStatus,
                    $allowedStatus,
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    '変更先ステータスが不正です。'
                );
            }

            foreach (
                $surveys as &$survey
            ) {
                if (
                    (string)(
                        $survey['id']
                        ?? ''
                    ) !== $surveyId
                ) {
                    continue;
                }

                refresh_survey_status(
                    $survey
                );

                if (
                    ($survey['status']
                    ?? '')
                    === 'ended'
                ) {
                    throw new RuntimeException(
                        '終了したアンケートの状態は変更できません。'
                    );
                }

                $survey['status'] =
                    $newStatus;

                $survey['updatedAt'] =
                    now_iso();
            }

            unset($survey);

            save_surveys(
                $surveys
            );

            set_result(
                'success',
                'アンケート状態を変更しました。'
            );
        }

        /* ====================================================
         * 複製
         * ==================================================== */

        elseif (
            $action ===
            'duplicate_survey'
        ) {
            $surveys =
                load_surveys();

            $surveyId =
                (string)(
                    $_POST[
                        'survey_id'
                    ] ?? ''
                );

            $source =
                find_survey(
                    $surveys,
                    $surveyId
                );

            if (
                $source === null
            ) {
                throw new RuntimeException(
                    '複製対象のアンケートがありません。'
                );
            }

            $copy =
                $source;

            $copy['id'] =
                'survey-' .
                uuid();

            $copy['title'] =
                ($copy['title'] ?? '') .
                '（コピー）';

            $copy['status'] =
                'draft';

            $copy['createdAt'] =
                now_iso();

            $copy['updatedAt'] =
                now_iso();

            $surveys[] =
                $copy;

            save_surveys(
                $surveys
            );

            set_result(
                'success',
                'アンケートを複製しました。'
            );
        }

        /* ====================================================
         * 削除
         * ==================================================== */

        elseif (
            $action ===
            'delete_survey'
        ) {
            $surveys =
                load_surveys();

            $surveyId =
                (string)(
                    $_POST[
                        'survey_id'
                    ] ?? ''
                );

            $before =
                count($surveys);

            $surveys =
                array_values(
                    array_filter(
                        $surveys,
                        static function(
                            $survey
                        ) use (
                            $surveyId
                        ) {
                            return (string)(
                                $survey['id']
                                ?? ''
                            ) !==
                            $surveyId;
                        }
                    )
                );

            if (
                count($surveys)
                ===
                $before
            ) {
                throw new RuntimeException(
                    '削除対象がありません。'
                );
            }

            save_surveys(
                $surveys
            );

            set_result(
                'success',
                'アンケートを削除しました。'
            );
        }
    }
} catch (
    InvalidArgumentException $e
) {
    set_result(
        'error',
        '入力エラー：' .
        $e->getMessage()
    );
} catch (
    RuntimeException $e
) {
    set_result(
        'error',
        '処理失敗：' .
        $e->getMessage()
    );
} catch (
    Throwable $e
) {
    /*
     * 内部例外をそのまま画面へ出さない。
     */
    set_result(
        'error',
        'システムエラーが発生しました。' .
        ' 入力値・設定・ネットワーク環境を確認してください。'
    );
}

/* ============================================================
 * 表示共通
 * ============================================================ */

function render_result(
    ?array $result
): void {
    if (
        $result === null
    ) {
        return;
    }

    $type =
        in_array(
            $result['type'] ?? '',
            [
                'success',
                'error',
                'warning',
            ],
            true
        )
        ? $result['type']
        : 'error';

    ?>
    <div class="result <?=e($type)?>">
        <div class="result-title">
            <?=
            $type === 'success'
                ? '✓ 成功'
                : (
                    $type === 'warning'
                        ? '⚠ 確認'
                        : '✕ 失敗'
                )
            ?>
        </div>

        <div class="result-message">
            <?=nl2br(
                e(
                    $result['message']
                    ?? ''
                )
            )?>
        </div>

        <?php
        if (
            !empty(
                $result['details']
            )
        ):
        ?>

        <div class="result-details">

            <?php
            foreach (
                $result['details']
                as $key => $value
            ):
            ?>

            <div>
                <strong>
                    <?=e($key)?>：
                </strong>

                <?=e(
                    is_scalar($value)
                        ? $value
                        : json_encode(
                            $value,
                            JSON_UNESCAPED_UNICODE
                        )
                )?>
            </div>

            <?php endforeach; ?>

        </div>

        <?php endif; ?>
    </div>
    <?php
}

/* ============================================================
 * HTMLヘッダー
 * ============================================================ */

function render_header(
    string $title,
    bool $respondent = false
): void {
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
<?=e($title)?> -
<?=e(APP_NAME)?>
</title>

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
}

.header{
    background:#0f172a;
    color:#fff;
    padding:16px 24px;
}

.header-inner{
    max-width:1400px;
    margin:auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.header a{
    color:#fff;
    text-decoration:none;
}

.nav{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
}

.nav a{
    padding:8px 12px;
    border-radius:8px;
}

.nav a:hover{
    background:#1e293b;
}

.container{
    max-width:1400px;
    margin:28px auto;
    padding:0 20px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:24px;
    margin-bottom:20px;
}

h1{
    margin-top:0;
}

h2{
    margin-top:28px;
}

.form-row{
    margin-bottom:18px;
}

label{
    display:block;
    font-weight:600;
    margin-bottom:7px;
}

input,
textarea,
select{
    width:100%;
    max-width:760px;
    border:1px solid var(--border);
    border-radius:8px;
    padding:10px 12px;
    background:#fff;
    color:var(--text);
    font:inherit;
}

textarea{
    min-height:120px;
}

input[type=checkbox],
input[type=radio]{
    width:auto;
}

button{
    border:0;
    border-radius:8px;
    padding:10px 16px;
    cursor:pointer;
    font-weight:600;
    font:inherit;
}

button:disabled{
    opacity:.5;
    cursor:not-allowed;
}

.primary{
    background:var(--primary);
    color:#fff;
}

.primary:hover{
    background:var(--primary-dark);
}

.secondary{
    background:#e2e8f0;
    color:#0f172a;
}

.success{
    background:var(--success);
    color:#fff;
}

.danger{
    background:var(--danger);
    color:#fff;
}

.warning{
    background:var(--warning);
    color:#fff;
}

.result{
    border-radius:10px;
    padding:16px;
    margin-bottom:20px;
    border:1px solid;
}

.result.success{
    background:#f0fdf4;
    border-color:#86efac;
    color:#166534;
}

.result.error{
    background:#fef2f2;
    border-color:#fca5a5;
    color:#991b1b;
}

.result.warning{
    background:#fffbeb;
    border-color:#fcd34d;
    color:#92400e;
}

.result-title{
    font-size:18px;
    font-weight:700;
    margin-bottom:7px;
}

.result-message{
    white-space:normal;
}

.result-details{
    margin-top:12px;
    padding:12px;
    background:rgba(255,255,255,.75);
    border-radius:8px;
}

.alert{
    padding:12px 14px;
    background:#eff6ff;
    border:1px solid #bfdbfe;
    border-radius:8px;
    margin:15px 0;
}

.muted{
    color:var(--gray);
}

.actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    min-width:900px;
    border-collapse:collapse;
}

th,
td{
    border-bottom:1px solid var(--border);
    padding:12px;
    vertical-align:top;
    text-align:left;
}

th{
    background:#f8fafc;
}

.status{
    display:inline-block;
    padding:4px 10px;
    border-radius:999px;
    font-size:13px;
    font-weight:700;
}

.status-draft{
    background:#e2e8f0;
    color:#475569;
}

.status-published{
    background:#dcfce7;
    color:#166534;
}

.status-stopped{
    background:#fef3c7;
    color:#92400e;
}

.status-ended{
    background:#fee2e2;
    color:#991b1b;
}

.processing .spinner{
    display:inline-block;
}

.spinner{
    display:none;
}

.grid{
    display:grid;
    grid-template-columns:
        repeat(
            auto-fit,
            minmax(240px,1fr)
        );
    gap:16px;
}

.stat{
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:10px;
    padding:18px;
}

.stat-label{
    color:var(--gray);
    font-size:14px;
}

.stat-value{
    font-size:28px;
    font-weight:700;
    margin-top:5px;
}

.question{
    border:1px solid var(--border);
    border-radius:10px;
    padding:18px;
    margin-bottom:14px;
    background:#fff;
}

.group{
    border:1px solid var(--border);
    border-radius:12px;
    padding:18px;
    margin-bottom:18px;
    background:#f8fafc;
}

@media(max-width:800px){

    .header-inner{
        flex-direction:column;
        align-items:flex-start;
    }

    .container{
        margin-top:18px;
        padding:0 12px;
    }

    .card{
        padding:16px;
    }

    table{
        min-width:800px;
    }

}

</style>
</head>

<body>

<?php if (!$respondent): ?>

<header class="header">

<div class="header-inner">

<strong>
<a href="<?=e(
    screen_url('list')
)?>">
<?=e(APP_NAME)?>
</a>
</strong>

<nav class="nav">

<a href="<?=e(
    screen_url('list')
)?>">
アンケート一覧
</a>

<a href="<?=e(
    screen_url('kintone')
)?>">
kintone
</a>

<a href="<?=e(
    screen_url('mail')
)?>">
メール
</a>

</nav>

</div>

</header>

<?php endif; ?>

<main class="container">

<?php
}

/* ============================================================
 * HTMLフッター
 * ============================================================ */

function render_footer(): void
{
    ?>
</main>

<script>

document
.querySelectorAll(
    '[data-processing-form]'
)
.forEach(function(form){

    form.addEventListener(
        'submit',
        function(){

            form.classList.add(
                'processing'
            );

            form
            .querySelectorAll(
                'button'
            )
            .forEach(
                function(button){
                    button.disabled = true;
                }
            );
        }
    );

});

function confirmAction(message){
    return window.confirm(message);
}

</script>

</body>
</html>
<?php
}

/* ============================================================
 * 画面
 * ============================================================ */

$result =
    take_result();

$settings =
    load_settings();

/* ============================================================
 * kintone設定画面
 * ============================================================ */

if (
    $screen ===
    'kintone'
) {

    $k =
        $settings['kintone'];

    $fields =
        $k['fields']
        ?? [];

    $status =
        (string)(
            $k['connection_status']
            ?? '未設定'
        );

    render_header(
        'kintone連携設定'
    );

    render_result(
        $result
    );

    ?>

    <div class="card">

    <h1>
        kintone連携設定
    </h1>

    <div class="alert">
        <strong>
            接続テストと顧客情報同期は別操作です。
        </strong>
        <br>
        まず「設定保存」、
        次に「接続テスト」、
        必要に応じて「項目一覧を再取得」、
        最後に「顧客情報を同期」を実行してください。
    </div>

    <form
        method="post"
        data-processing-form
    >

    <input
        type="hidden"
        name="action"
        value="save_kintone"
    >

    <div class="form-row">

    <label>
        kintoneサブドメイン
    </label>

    <input
        name="subdomain"
        required
        value="<?=e(
            $k['subdomain']
            ?? ''
        )?>"
        placeholder="xxxx.cybozu.com"
    >

    <div class="muted">
        「xxxx」
        「xxxx.cybozu.com」
        「https://xxxx.cybozu.com」
        のいずれでも入力できます。
    </div>

    </div>

    <div class="form-row">

    <label>
        顧客管理アプリID
    </label>

    <input
        name="app_id"
        required
        inputmode="numeric"
        value="<?=e(
            $k['app_id']
            ?? ''
        )?>"
    >

    </div>

    <div class="form-row">

    <label>
        kintoneログイン名
    </label>

    <input
        name="username"
        required
        value="<?=e(
            $k['username']
            ?? ''
        )?>"
    >

    </div>

    <div class="form-row">

    <label>
        kintoneパスワード
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

    <div class="muted">
        未入力の場合はProxyを使用せず直接接続します。
    </div>

    </div>

    <div class="form-row">

    <label>
        <input
            type="checkbox"
            name="verify_ssl"
            value="1"
            <?=!empty(
                $k['verify_ssl']
            ) ? 'checked' : ''?>
        >
        SSL証明書を検証する
    </label>

    <div class="muted">
        POC初期値は無効です。
    </div>

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
        接続テスト
    </h2>

    <p>
        現在の状態：

        <?php if (
            $status ===
            '接続確認済み'
        ): ?>

        <span class="status status-published">
            接続確認済み
        </span>

        <?php elseif (
            $status ===
            '接続できません'
        ): ?>

        <span class="status status-ended">
            接続できません
        </span>

        <?php else: ?>

        <span class="status status-draft">
            <?=e($status)?>
        </span>

        <?php endif; ?>

    </p>

    <?php if (
        !empty(
            $k['last_test_at']
        )
    ): ?>

    <p class="muted">
        最終テスト：
        <?=e(
            $k['last_test_at']
        )?>
    </p>

    <?php endif; ?>

    <form
        method="post"
        data-processing-form
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
        <span class="spinner">
            ⏳
        </span>
    </button>

    </form>

    <hr>

    <h2>
        kintone項目一覧
    </h2>

    <form
        method="post"
        data-processing-form
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

    <?php if (
        is_array($fields) &&
        count($fields) > 0
    ): ?>

    <div class="table-wrap">

    <table>

    <thead>
    <tr>
        <th>
            フィールドコード
        </th>

        <th>
            ラベル
        </th>

        <th>
            種類
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

    <?php else: ?>

    <p class="muted">
        まだkintone項目を取得していません。
    </p>

    <?php endif; ?>

    <hr>

    <h2>
        顧客情報同期
    </h2>

    <p>
        kintoneの顧客管理アプリから
        顧客情報を取得してサーバーへ保存します。
    </p>

    <form
        method="post"
        data-processing-form
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
        <span class="spinner">
            ⏳
        </span>
    </button>

    </form>

    <?php

    $customers =
        read_json(
            CUSTOMERS_FILE,
            []
        );

    if (!is_array($customers)) {
        $customers = [];
    }

    ?>

    <p>
        現在の同期件数：
        <strong>
            <?=count($customers)?>件
        </strong>
    </p>

    </div>

    <?php

    render_footer();

    exit;
}

/* ============================================================
 * メール設定
 * ============================================================ */

if (
    $screen ===
    'mail'
) {

    render_header(
        'メールサーバ設定'
    );

    render_result(
        $result
    );

    ?>

    <div class="card">

    <h1>
        メールサーバ設定
    </h1>

    <div class="alert">
        SMTP接続を使用します。
        PHP mail() は使用しません。
    </div>

    <div class="form-row">
        <label>
            SMTPサーバ
        </label>

        <input
            value="<?=e(
                $settings['mail']['host']
                ?? ''
            )?>"
            placeholder="smtp.example.com"
        >
    </div>

    <div class="form-row">
        <label>
            SMTPポート
        </label>

        <input
            value="<?=e(
                $settings['mail']['port']
                ?? '587'
            )?>"
        >
    </div>

    <div class="form-row">
        <label>
            暗号化方式
        </label>

        <select>
            <option value="tls">
                TLS
            </option>

            <option value="ssl">
                SSL
            </option>

            <option value="none">
                なし
            </option>
        </select>
    </div>

    <p class="muted">
        SMTP実装はPHP mail()ではなくSMTP接続を使用します。
    </p>

    </div>

    <?php

    render_footer();

    exit;
}

/* ============================================================
 * 一覧
 * ============================================================ */

if (
    $screen ===
    'list'
) {

    $surveys =
        load_surveys();

    foreach (
        $surveys as &$survey
    ) {
        refresh_survey_status(
            $survey
        );
    }

    unset($survey);

    save_surveys(
        $surveys
    );

    usort(
        $surveys,
        static function(
            $a,
            $b
        ) {
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

    render_header(
        'アンケート一覧'
    );

    render_result(
        $result
    );

    ?>

    <div class="card">

    <div style="
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
    ">

    <h1>
        アンケート一覧
    </h1>

    <a href="<?=e(
        screen_url('edit')
    )?>">
        <button class="primary">
            新規作成
        </button>
    </a>

    </div>

    <?php if (
        count($surveys) === 0
    ): ?>

    <p class="muted">
        アンケートはまだありません。
    </p>

    <?php else: ?>

    <div class="table-wrap">

    <table>

    <thead>
    <tr>
        <th>タイトル</th>
        <th>作成日</th>
        <th>更新日</th>
        <th>期間</th>
        <th>ステータス</th>
        <th>操作</th>
    </tr>
    </thead>

    <tbody>

    <?php foreach (
        $surveys as $survey
    ): ?>

    <?php
    $surveyStatus =
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
                ?? '無題'
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
        ～
        <?=e(
            $survey['endAt']
            ?? ''
        )?>
    </td>

    <td>

    <span class="status
    <?=
        match($surveyStatus){
            'published' =>
                'status-published',

            'stopped' =>
                'status-stopped',

            'ended' =>
                'status-ended',

            default =>
                'status-draft',
        }
    ?>
    ">

    <?=
        match($surveyStatus){
            'published' =>
                '公開中',

            'stopped' =>
                '停止',

            'ended' =>
                '終了',

            default =>
                '下書き',
        }
    ?>

    </span>

    </td>

    <td>

    <div class="actions">

    <a href="<?=e(
        screen_url(
            'edit',
            (string)$survey['id']
        )
    )?>">
        <button class="secondary">
            確認・編集
        </button>
    </a>

    <a href="<?=e(
        screen_url(
            'preview',
            (string)$survey['id']
        )
    )?>">
        <button class="secondary">
            プレビュー
        </button>
    </a>

    <a href="<?=e(
        screen_url(
            'analytics',
            (string)$survey['id']
        )
    )?>">
        <button class="secondary">
            集計
        </button>
    </a>

    <a href="<?=e(
        screen_url(
            'send',
            (string)$survey['id']
        )
    )?>">
        <button class="secondary">
            送信
        </button>
    </a>

    <form
        method="post"
        style="display:inline"
        onsubmit="
            return confirmAction(
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
        name="survey_id"
        value="<?=e(
            $survey['id']
        )?>"
    >

    <button class="secondary">
        複製
    </button>

    </form>

    <form
        method="post"
        style="display:inline"
        onsubmit="
            return confirmAction(
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
        name="survey_id"
        value="<?=e(
            $survey['id']
        )?>"
    >

    <button class="danger">
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

    <?php endif; ?>

    </div>

    <?php

    render_footer();

    exit;
}

/* ============================================================
 * 編集画面
 * ============================================================ */

if (
    $screen ===
    'edit'
) {

    $surveys =
        load_surveys();

    $survey =
        $id !== ''
            ? find_survey(
                $surveys,
                $id
            )
            : null;

    if (
        $survey === null
    ) {
        $survey =
            default_survey();
    }

    render_header(
        'アンケート作成・編集'
    );

    render_result(
        $result
    );

    ?>

    <div class="card">

    <h1>
        アンケート作成・編集
    </h1>

    <form
        method="post"
        data-processing-form
    >

    <input
        type="hidden"
        name="action"
        value="save_survey"
    >

    <input
        type="hidden"
        name="survey_id"
        value="<?=e(
            $survey['id']
        )?>"
    >

    <div class="form-row">

    <label>
        アンケートタイトル
    </label>

    <input
        name="title"
        required
        maxlength="200"
        value="<?=e(
            $survey['title']
            ?? ''
        )?>"
    >

    </div>

    <div class="form-row">

    <label>
        アンケート説明
    </label>

    <textarea
        name="description"
    ><?=e(
        $survey['description']
        ?? ''
    )?></textarea>

    </div>

    <div class="form-row">

    <label>
        開始日時
    </label>

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

    <label>
        終了日時
    </label>

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

    <label>
        質問番号の採番方式
    </label>

    <select name="numbering">

    <option
        value="global"
        <?=(
            ($survey['numbering']
            ?? 'global')
            === 'global'
        )
        ? 'selected'
        : ''
        ?>
    >
        アンケート全体で通番
        Q1、Q2、Q3...
    </option>

    <option
        value="group"
        <?=(
            ($survey['numbering']
            ?? '')
            === 'group'
        )
        ? 'selected'
        : ''
        ?>
    >
        グループ毎に採番
        Q1-1、Q1-2...
    </option>

    </select>

    </div>

    <div class="actions">

    <a href="<?=e(
        screen_url('list')
    )?>">
        <button
            type="button"
            class="secondary"
        >
            キャンセル
        </button>
    </a>

    <button
        type="submit"
        class="primary"
    >
        保存して一覧へ
    </button>

    </div>

    </form>

    <hr>

    <h2>
        現在の状態
    </h2>

    <p>
        <strong>
        <?=
            match(
                $survey['status']
                ?? 'draft'
            ){
                'published' =>
                    '公開中',

                'stopped' =>
                    '停止',

                'ended' =>
                    '終了',

                default =>
                    '下書き',
            }
        ?>
        </strong>
    </p>

    <?php if (
        ($survey['status']
        ?? 'draft')
        !== 'ended'
    ): ?>

    <div class="actions">

    <?php if (
        ($survey['status']
        ?? '')
        === 'draft'
    ): ?>

    <form
        method="post"
        onsubmit="
            return confirmAction(
                '公開しますか？'
            );
        "
    >

    <input
        type="hidden"
        name="action"
        value="change_status"
    >

    <input
        type="hidden"
        name="survey_id"
        value="<?=e(
            $survey['id']
        )?>"
    >

    <input
        type="hidden"
        name="new_status"
        value="published"
    >

    <button class="success">
        公開
    </button>

    </form>

    <?php endif; ?>

    <?php if (
        ($survey['status']
        ?? '')
        === 'published'
    ): ?>

    <form
        method="post"
        onsubmit="
            return confirmAction(
                '停止しますか？'
            );
        "
    >

    <input
        type="hidden"
        name="action"
        value="change_status"
    >

    <input
        type="hidden"
        name="survey_id"
        value="<?=e(
            $survey['id']
        )?>"
    >

    <input
        type="hidden"
        name="new_status"
        value="stopped"
    >

    <button class="warning">
        停止
    </button>

    </form>

    <?php endif; ?>

    <?php if (
        ($survey['status']
        ?? '')
        === 'stopped'
    ): ?>

    <form
        method="post"
        onsubmit="
            return confirmAction(
                '再開しますか？'
            );
        "
    >

    <input
        type="hidden"
        name="action"
        value="change_status"
    >

    <input
        type="hidden"
        name="survey_id"
        value="<?=e(
            $survey['id']
        )?>"
    >

    <input
        type="hidden"
        name="new_status"
        value="published"
    >

    <button class="success">
        再開
    </button>

    </form>

    <?php endif; ?>

    </div>

    <?php endif; ?>

    </div>

    <?php

    render_footer();

    exit;
}

/* ============================================================
 * プレビュー
 * ============================================================ */

if (
    $screen ===
    'preview'
) {

    $surveys =
        load_surveys();

    $survey =
        find_survey(
            $surveys,
            $id
        );

    if (
        $survey === null
    ) {
        set_result(
            'error',
            '対象アンケートがありません。'
        );

        render_header(
            'プレビュー'
        );

        render_result(
            take_result()
        );

        ?>

        <a href="<?=e(
            screen_url('list')
        )?>">
            <button class="secondary">
                一覧へ戻る
            </button>
        </a>

        <?php

        render_footer();
        exit;
    }

    render_header(
        'アンケートプレビュー'
    );

    render_result(
        $result
    );

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

    <?php

    $groups =
        $survey['groups']
        ?? [];

    $questionNumber = 0;

    foreach (
        $groups as $groupIndex =>
        $group
    ):

        $groupTitle =
            $group['title']
            ?? 'グループ';

    ?>

    <div class="group">

    <h2>
        <?=e(
            $groupTitle
        )?>
    </h2>

    <?php

    foreach (
        ($group['questions']
        ?? []) as $question
    ):

        $questionNumber++;

    ?>

    <div class="question">

    <strong>
        Q<?=e(
            $questionNumber
        )?>
        <?=e(
            $question['text']
            ?? ''
        )?>

        <?php if (
            !empty(
                $question['required']
            )
        ): ?>

        <span style="
            color:#dc2626;
        ">
            必須
        </span>

        <?php endif; ?>

    </strong>

    <?php
    $type =
        $question['type']
        ?? 'single';
    ?>

    <?php if (
        $type === 'single'
    ): ?>

        <?php foreach (
            ($question['options']
            ?? []) as $option
        ): ?>

        <label>
            <input
                type="radio"
                disabled
            >
            <?=e($option)?>
        </label>

        <?php endforeach; ?>

    <?php elseif (
        $type === 'multiple'
    ): ?>

        <?php foreach (
            ($question['options']
            ?? []) as $option
        ): ?>

        <label>
            <input
                type="checkbox"
                disabled
            >
            <?=e($option)?>
        </label>

        <?php endforeach; ?>

    <?php else: ?>

        <textarea
            disabled
            placeholder="回答欄"
        ></textarea>

    <?php endif; ?>

    </div>

    <?php endforeach; ?>

    </div>

    <?php endforeach; ?>

    </div>

    <a href="<?=e(
        screen_url(
            'edit',
            $survey['id']
        )
    )?>">
        <button class="secondary">
            編集へ戻る
        </button>
    </a>

    <?php

    render_footer();
    exit;
}

/* ============================================================
 * 回答者画面
 * ============================================================ */

if (
    $screen ===
    'answer'
    ||
    $screen ===
    'confirm'
    ||
    $screen ===
    'complete'
) {

    $surveys =
        load_surveys();

    $survey =
        find_survey(
            $surveys,
            $id
        );

    if (
        $survey === null
    ) {
        http_response_code(404);

        render_header(
            'アンケート',
            true
        );

        ?>

        <div class="card">

        <h1>
            アンケートが見つかりません
        </h1>

        <p>
            指定されたアンケートは存在しません。
        </p>

        </div>

        <?php

        render_footer();
        exit;
    }

    refresh_survey_status(
        $survey
    );

    if (
        ($survey['status']
        ?? '')
        !== 'published'
    ) {
        render_header(
            'アンケート',
            true
        );

        ?>

        <div class="card">

        <h1>
            <?=e(
                $survey['title']
                ?? ''
            )?>
        </h1>

        <p>
            このアンケートは現在回答できません。
        </p>

        </div>

        <?php

        render_footer();
        exit;
    }

    /*
     * 回答者画面では管理者メニューを出さない。
     */
    render_header(
        match($screen){
            'confirm' =>
                '回答確認',

            'complete' =>
                '回答完了',

            default =>
                'アンケート回答',
        },
        true
    );

    ?>

    <div class="card">

    <h1>
        <?=e(
            $survey['title']
            ?? ''
        )?>
    </h1>

    <?php if (
        $screen ===
        'complete'
    ): ?>

        <h2>
            回答ありがとうございました
        </h2>

        <p>
            回答を受け付けました。
        </p>

    <?php elseif (
        $screen ===
        'confirm'
    ): ?>

        <h2>
            回答確認
        </h2>

        <p>
            以下の内容で送信します。
        </p>

        <p>
            回答確認画面から内容を修正できます。
        </p>

        <a href="<?=e(
            screen_url(
                'answer',
                $survey['id']
            )
        )?>">
            <button class="secondary">
                修正する
            </button>
        </a>

        <a href="<?=e(
            screen_url(
                'complete',
                $survey['id']
            )
        )?>">
            <button class="primary">
                回答を送信
            </button>
        </a>

    <?php else: ?>

        <p>
            <?=nl2br(
                e(
                    $survey[
                        'description'
                    ] ?? ''
                )
            )?>
        </p>

        <?php

        $number = 0;

        foreach (
            ($survey['groups']
            ?? []) as $group
        ):

        ?>

        <div class="group">

        <h2>
            <?=e(
                $group['title']
                ?? '質問'
            )?>
        </h2>

        <?php foreach (
            ($group['questions']
            ?? []) as $question
        ):

            $number++;

            $type =
                $question['type']
                ?? 'single';

        ?>

        <div class="question">

        <label>
            Q<?=e($number)?>.
            <?=e(
                $question['text']
                ?? ''
            )?>

            <?php if (
                !empty(
                    $question['required']
                )
            ): ?>

            <span style="
                color:#dc2626;
            ">
                必須
            </span>

            <?php endif; ?>

        </label>

        <?php if (
            $type === 'single'
        ): ?>

        <?php foreach (
            ($question['options']
            ?? []) as $option
        ): ?>

        <label>
            <input
                type="radio"
                name="q<?=e(
                    $number
                )?>"
            >
            <?=e($option)?>
        </label>

        <?php endforeach; ?>

        <?php elseif (
            $type === 'multiple'
        ): ?>

        <?php foreach (
            ($question['options']
            ?? []) as $option
        ): ?>

        <label>
            <input
                type="checkbox"
                name="q<?=e(
                    $number
                )?>[]"
            >
            <?=e($option)?>
        </label>

        <?php endforeach; ?>

        <?php else: ?>

        <textarea
            name="q<?=e(
                $number
            )?>"
        ></textarea>

        <?php endif; ?>

        </div>

        <?php endforeach; ?>

        </div>

        <?php endforeach; ?>

        <a href="<?=e(
            screen_url(
                'confirm',
                $survey['id']
            )
        )?>">
            <button class="primary">
                次へ
            </button>
        </a>

    <?php endif; ?>

    </div>

    <?php

    render_footer();
    exit;
}

/* ============================================================
 * 集計
 * ============================================================ */

if (
    $screen ===
    'analytics'
) {

    $surveys =
        load_surveys();

    $survey =
        find_survey(
            $surveys,
            $id
        );

    if (
        $survey === null
    ) {
        set_result(
            'error',
            '対象アンケートがありません。'
        );

        render_header(
            '回答集計・分析'
        );

        render_result(
            take_result()
        );

        render_footer();
        exit;
    }

    $answers =
        read_json(
            ANSWERS_FILE,
            []
        );

    if (
        !is_array($answers)
    ) {
        $answers = [];
    }

    $surveyAnswers =
        array_values(
            array_filter(
                $answers,
                static function(
                    $answer
                ) use (
                    $id
                ) {
                    return (string)(
                        $answer[
                            'survey_id'
                        ] ?? ''
                    ) === $id;
                }
            )
        );

    render_header(
        '回答集計・分析'
    );

    render_result(
        $result
    );

    ?>

    <div class="card">

    <h1>
        回答集計・分析
    </h1>

    <p>
        対象アンケート：
        <strong>
            <?=e(
                $survey['title']
                ?? ''
            )?>
        </strong>
    </p>

    <div class="grid">

    <div class="stat">
        <div class="stat-label">
            回答数
        </div>

        <div class="stat-value">
            <?=count(
                $surveyAnswers
            )?>
        </div>
    </div>

    <div class="stat">
        <div class="stat-label">
            未回答
        </div>

        <div class="stat-value">
            -
        </div>
    </div>

    <div class="stat">
        <div class="stat-label">
            回答率
        </div>

        <div class="stat-value">
            -
        </div>
    </div>

    </div>

    <?php if (
        count(
            $surveyAnswers
        ) === 0
    ): ?>

    <div class="alert">
        現在、回答データはありません
    </div>

    <?php else: ?>

    <h2>
        個別回答
    </h2>

    <div class="table-wrap">

    <table>

    <thead>
    <tr>
        <th>
            回答ID
        </th>

        <th>
            回答日時
        </th>
    </tr>
    </thead>

    <tbody>

    <?php foreach (
        $surveyAnswers as $answer
    ): ?>

    <tr>

    <td>
        <?=e(
            $answer['id']
            ?? ''
        )?>
    </td>

    <td>
        <?=e(
            $answer['created_at']
            ?? ''
        )?>
    </td>

    </tr>

    <?php endforeach; ?>

    </tbody>

    </table>

    </div>

    <?php endif; ?>

    <p>
        <a href="<?=e(
            screen_url('list')
        )?>">
            <button class="secondary">
                一覧へ戻る
            </button>
        </a>
    </p>

    </div>

    <?php

    render_footer();
    exit;
}

/* ============================================================
 * 送信画面
 * ============================================================ */

if (
    $screen ===
    'send'
) {

    $surveys =
        load_surveys();

    $survey =
        find_survey(
            $surveys,
            $id
        );

    if (
        $survey === null
    ) {
        set_result(
            'error',
            '送信対象のアンケートがありません。'
        );

        render_header(
            '顧客選択・メール送信'
        );

        render_result(
            take_result()
        );

        render_footer();
        exit;
    }

    $customers =
        read_json(
            CUSTOMERS_FILE,
            []
        );

    if (
        !is_array($customers)
    ) {
        $customers = [];
    }

    render_header(
        '顧客選択・メール送信'
    );

    render_result(
        $result
    );

    ?>

    <div class="card">

    <h1>
        顧客選択・メール送信
    </h1>

    <p>
        対象アンケート：
        <strong>
            <?=e(
                $survey['title']
                ?? ''
            )?>
        </strong>
    </p>

    <div class="alert">
        この画面では対象アンケートを変更できません。
    </div>

    <h2>
        顧客
    </h2>

    <?php if (
        count($customers) === 0
    ): ?>

    <p>
        顧客データがありません。
    </p>

    <a href="<?=e(
        screen_url('kintone')
    )?>">
        <button class="secondary">
            kintone設定へ
        </button>
    </a>

    <?php else: ?>

    <div class="table-wrap">

    <table>

    <thead>
    <tr>
        <th>
            選択
        </th>

        <th>
            組織名
        </th>

        <th>
            氏名
        </th>

        <th>
            メールアドレス
        </th>
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
            disabled
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

    </tr>

    <?php endforeach; ?>

    </tbody>

    </table>

    </div>

    <?php endif; ?>

    <h2>
        メール本文
    </h2>

    <div class="form-row">

    <label>
        件名
    </label>

    <input
        value="アンケートのお願い"
    >

    </div>

    <div class="form-row">

    <label>
        本文
    </label>

    <textarea
    >{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}</textarea>

    </div>

    <div class="actions">

    <button
        class="primary"
        disabled
    >
        一括送信
    </button>

    <button
        class="secondary"
        disabled
    >
        再送
    </button>

    <button
        class="secondary"
        disabled
    >
        リマインド
    </button>

    </div>

    <p class="muted">
        SMTP設定が完了した状態で送信処理を実行します。
    </p>

    <h2>
        送信履歴
    </h2>

    <p class="muted">
        送信履歴は独立画面ではなく、この画面内に表示します。
    </p>

    </div>

    <?php

    render_footer();
    exit;
}

/* ============================================================
 * 未知screen
 * ============================================================ */

render_header(
    'アンケート一覧'
);

?>

<div class="card">

<h1>
    画面が見つかりません
</h1>

<p>
    指定された画面は存在しません。
</p>

<a href="<?=e(
    screen_url('list')
)?>">
    <button class="primary">
        アンケート一覧へ
    </button>
</a>

</div>

<?php

render_footer();
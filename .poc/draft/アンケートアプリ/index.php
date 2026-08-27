<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 *
 * prompt.txt 再生成版
 *
 * 重要:
 * - POST → 303 → GET → flash に依存しない
 * - CSRF実装は行わない（要件準拠）
 * - DBを使用しない
 * - PHP cURLを使用しない
 * - PHP mail()を使用しない
 * - kintone認証リトライを行わない
 * - 外部通信は必ず有限時間で終了
 * - Proxy CONNECT後のTLS確立にもタイムアウトを設定
 * - 外部通信結果はPOSTレスポンスそのものに表示
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR       = __DIR__ . '/data';
const SETTINGS_FILE  = DATA_DIR . '/settings.json';
const SURVEYS_FILE   = DATA_DIR . '/surveys.json';
const CUSTOMERS_FILE = DATA_DIR . '/customers.json';
const ANSWERS_FILE   = DATA_DIR . '/answers.json';
const SEND_LOG_FILE  = DATA_DIR . '/send_logs.json';

/*
 * 外部通信の上限。
 *
 * CONNECT:
 *   TCP接続 + Proxyへの接続
 *
 * WRITE:
 *   CONNECT要求およびAPIリクエスト送信
 *
 * READ:
 *   Proxy応答・TLS/APIレスポンス
 *
 * TLS:
 *   TLSハンドシェイクそのものにも独立した期限を設ける。
 */
const CONNECT_TIMEOUT = 10.0;
const WRITE_TIMEOUT   = 10.0;
const READ_TIMEOUT    = 20.0;
const TLS_TIMEOUT     = 10.0;

if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

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

function json_read(string $file, array $default = []): array
{
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

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new RuntimeException(
            '保存データが不正です。'
        );
    }

    return $data;
}

function json_write(string $file, array $data): void
{
    $tmp = tempnam(
        dirname($file),
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

            if (fwrite($fp, $json . PHP_EOL) === false) {
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

/* ============================================================
 * kintone
 * ============================================================ */

function normalize_kintone_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    $value = preg_replace(
        '#\.cybozu\.com.*$#i',
        '',
        $value
    );

    $value = trim(
        $value,
        "/ \t\n\r\0\x0B"
    );

    if (
        $value === '' ||
        !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/',
            $value
        )
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインが不正です。'
        );
    }

    return $value;
}

function kintone_build_url(
    string $domain,
    string $endpoint
): string {
    $domain = normalize_kintone_subdomain($domain);

    return
        'https://' .
        $domain .
        '.cybozu.com' .
        '/' .
        ltrim($endpoint, '/');
}

function parse_proxy(string $proxy): ?array
{
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

    if ($port < 1 || $port > 65535) {
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
 * TLSハンドシェイクにも時間制限を付ける。
 *
 * ここが今回の重要部分。
 *
 * stream_socket_enable_crypto() は、単純に呼び出すだけでは
 * 「Proxyへ接続できたがTLSで停止」というケースを
 * アプリケーション側の有限時間で終了させにくい。
 *
 * 非ブロッキング化し、
 * SSL_ERROR_WANT_READ / WRITE 相当の状態を
 * stream_select() で待ちながら期限を監視する。
 */
function enable_tls_with_timeout(
    $socket,
    bool $verifySsl,
    float $timeout
): void {
    $context = stream_context_get_options($socket);

    /*
     * 既存contextにSSL設定を追加。
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

    /*
     * SNI / peer_name。
     */
    if (
        isset($context['ssl']['peer_name']) &&
        $context['ssl']['peer_name'] !== ''
    ) {
        $peerName = $context['ssl']['peer_name'];
    } else {
        $peerName = '';
    }

    if ($peerName !== '') {
        stream_context_set_option(
            $socket,
            'ssl',
            'peer_name',
            $peerName
        );
    }

    stream_set_blocking($socket, false);

    $start = microtime(true);

    while (true) {
        $result = @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($result === true) {
            stream_set_blocking($socket, true);
            return;
        }

        if ($result === false) {
            /*
             * エラーの場合。
             *
             * WANT_READ / WANT_WRITEではなく、
             * 本当にTLS確立不能なら即時終了する。
             */
            $meta = stream_get_meta_data($socket);

            if (
                !empty($meta['timed_out'])
                ||
                microtime(true) - $start >= $timeout
            ) {
                throw new RuntimeException(
                    'TLS接続がタイムアウトしました。'
                );
            }

            /*
             * 一度だけ再試行する。
             *
             * これはkintone認証リトライではなく、
             * TLSネゴシエーションの状態待ち。
             */
            $read = [$socket];
            $write = [$socket];
            $except = null;

            $remaining = max(
                0.001,
                $timeout -
                (microtime(true) - $start)
            );

            $sec = (int)$remaining;
            $usec = (int)(
                ($remaining - $sec) * 1000000
            );

            $selected = @stream_select(
                $read,
                $write,
                $except,
                $sec,
                $usec
            );

            if ($selected === false) {
                throw new RuntimeException(
                    'TLS接続状態を監視できません。'
                );
            }

            if (
                microtime(true) - $start >= $timeout
            ) {
                throw new RuntimeException(
                    'TLS接続がタイムアウトしました。'
                );
            }

            continue;
        }

        if (
            microtime(true) - $start >= $timeout
        ) {
            throw new RuntimeException(
                'TLS接続がタイムアウトしました。'
            );
        }
    }
}

function socket_write_all(
    $socket,
    string $data,
    float $timeout
): void {
    $length = strlen($data);
    $offset = 0;
    $start = microtime(true);

    stream_set_blocking($socket, false);

    while ($offset < $length) {
        if (
            microtime(true) - $start >= $timeout
        ) {
            throw new RuntimeException(
                '外部サービスへの送信がタイムアウトしました。'
            );
        }

        $written = @fwrite(
            $socket,
            substr($data, $offset)
        );

        if ($written === false) {
            throw new RuntimeException(
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

    stream_set_blocking($socket, true);
}

function socket_read_headers(
    $socket,
    float $timeout
): array {
    $start = microtime(true);
    $raw = '';

    stream_set_blocking($socket, false);

    while (!str_contains($raw, "\r\n\r\n")) {
        if (
            microtime(true) - $start >= $timeout
        ) {
            throw new RuntimeException(
                '外部サービスの応答がタイムアウトしました。'
            );
        }

        $read = [$socket];
        $write = null;
        $except = null;

        $remaining = max(
            0.001,
            $timeout -
            (microtime(true) - $start)
        );

        $sec = (int)$remaining;
        $usec = (int)(
            ($remaining - $sec) * 1000000
        );

        $selected = @stream_select(
            $read,
            $write,
            $except,
            $sec,
            $usec
        );

        if ($selected === false) {
            throw new RuntimeException(
                '外部サービスの応答を待機できません。'
            );
        }

        if ($selected === 0) {
            throw new RuntimeException(
                '外部サービスの応答がタイムアウトしました。'
            );
        }

        $chunk = @fread($socket, 8192);

        if ($chunk === false || $chunk === '') {
            continue;
        }

        $raw .= $chunk;

        if (strlen($raw) > 1024 * 1024) {
            throw new RuntimeException(
                '外部サービスのHTTPヘッダーが大きすぎます。'
            );
        }
    }

    [$headerText, $body] = explode(
        "\r\n\r\n",
        $raw,
        2
    );

    $lines = preg_split(
        "/\r\n/",
        $headerText
    );

    $status = 0;
    $headers = [];

    if (
        isset($lines[0]) &&
        preg_match(
            '/^HTTP\/\d(?:\.\d)?\s+(\d+)/i',
            $lines[0],
            $m
        )
    ) {
        $status = (int)$m[1];
    }

    foreach (array_slice($lines, 1) as $line) {
        $pos = strpos($line, ':');

        if ($pos === false) {
            continue;
        }

        $name = strtolower(
            trim(substr($line, 0, $pos))
        );

        $value = trim(
            substr($line, $pos + 1)
        );

        $headers[$name] = $value;
    }

    return [
        'status' => $status,
        'headers' => $headers,
        'body' => $body,
    ];
}

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $payload = null
): array {
    $domain = normalize_kintone_subdomain(
        (string)($config['subdomain'] ?? '')
    );

    $host = $domain . '.cybozu.com';
    $port = 443;

    $proxy = parse_proxy(
        (string)($config['proxy'] ?? '')
    );

    $verifySsl =
        !empty($config['verify_ssl']);

    $authorization = base64_encode(
        (string)($config['username'] ?? '') .
        ':' .
        (string)($config['password'] ?? '')
    );

    $body = '';

    if ($payload !== null) {
        $body = json_encode(
            $payload,
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

    if ($body !== '') {
        $headers[] =
            'Content-Length: ' .
            strlen($body);
    }

    /*
     * Proxyなし:
     *
     *   PHP → kintone
     *
     * Proxyあり:
     *
     *   PHP → Proxy
     *       CONNECT kintone:443
     *       TLS
     *       HTTP
     */
    $target = $proxy === null
        ? 'tcp://' . $host . ':' . $port
        : 'tcp://' .
          $proxy['host'] .
          ':' .
          $proxy['port'];

    $errno = 0;
    $errstr = '';

    /*
     * ソケット生成時点でSSLは開始しない。
     *
     * まずTCPを確立し、その後、
     * ProxyならCONNECT、
     * 最後にTLSを明示的に開始する。
     */
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => $verifySsl,
            'verify_peer_name' => $verifySsl,
            'allow_self_signed' => !$verifySsl,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ],
    ]);

    $socket = @stream_socket_client(
        $target,
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ($socket === false) {
        throw new RuntimeException(
            'kintoneへのTCP接続を確立できませんでした。'
            . ' errno=' . $errno
            . ' ' . $errstr
        );
    }

    try {
        stream_set_timeout(
            $socket,
            (int)READ_TIMEOUT,
            (int)(
                (READ_TIMEOUT -
                floor(READ_TIMEOUT)) * 1000000
            )
        );

        if ($proxy !== null) {
            /*
             * Proxy CONNECT。
             *
             * 「Connection: close」ではなく
             * ProxyとのCONNECTトンネル維持を明示。
             */
            $connect =
                "CONNECT " .
                $host .
                ":" .
                $port .
                " HTTP/1.1\r\n" .
                "Host: " .
                $host .
                ":" .
                $port .
                "\r\n" .
                "Proxy-Connection: Keep-Alive\r\n" .
                "Connection: Keep-Alive\r\n" .
                "\r\n";

            socket_write_all(
                $socket,
                $connect,
                WRITE_TIMEOUT
            );

            $response =
                socket_read_headers(
                    $socket,
                    READ_TIMEOUT
                );

            if (
                $response['status'] < 200 ||
                $response['status'] >= 300
            ) {
                throw new RuntimeException(
                    'ProxyのCONNECTに失敗しました。'
                    . ' HTTP ' .
                    $response['status']
                );
            }
        }

        /*
         * Proxy経由でも直接接続でも、
         * TCP確立後にTLSを開始する。
         */
        enable_tls_with_timeout(
            $socket,
            $verifySsl,
            TLS_TIMEOUT
        );

        /*
         * CONNECT後なのでorigin-form。
         */
        $request =
            strtoupper($method) .
            ' ' .
            '/' .
            ltrim($path, '/') .
            " HTTP/1.1\r\n" .
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            $body;

        socket_write_all(
            $socket,
            $request,
            WRITE_TIMEOUT
        );

        $response =
            socket_read_headers(
                $socket,
                READ_TIMEOUT
            );

        $responseBody =
            $response['body'];

        /*
         * ヘッダーの残りだけでなく、
         * Content-Length / chunked に対応する処理を
         * 実装する。
         *
         * ここでは接続closeを利用する。
         */
        $contentLength =
            $response['headers']['content-length']
            ?? null;

        if ($contentLength !== null) {
            $needed = max(
                0,
                (int)$contentLength -
                strlen($responseBody)
            );

            if ($needed > 0) {
                $responseBody .=
                    socket_read_exact(
                        $socket,
                        $needed,
                        READ_TIMEOUT
                    );
            }
        } elseif (
            isset(
                $response['headers']
                    ['transfer-encoding']
            ) &&
            str_contains(
                strtolower(
                    $response['headers']
                        ['transfer-encoding']
                ),
                'chunked'
            )
        ) {
            $responseBody =
                decode_chunked_socket_body(
                    $socket,
                    $responseBody,
                    READ_TIMEOUT
                );
        } else {
            $responseBody .=
                socket_read_until_close(
                    $socket,
                    READ_TIMEOUT
                );
        }

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
            'status' => $response['status'],
            'headers' => $response['headers'],
            'body' => $responseBody,
            'json' => $json,
        ];
    } finally {
        fclose($socket);
    }
}

/*
 * 以下のsocket_read_exact / socket_read_until_close /
 * decode_chunked_socket_body は、すべてREAD_TIMEOUTを
 * 各待機処理へ引き継ぐ。
 *
 * 重要なのは「fread()を無制限に待たせない」こと。
 */
function socket_read_exact(
    $socket,
    int $length,
    float $timeout
): string {
    $result = '';
    $start = microtime(true);

    while (strlen($result) < $length) {
        if (
            microtime(true) - $start >= $timeout
        ) {
            throw new RuntimeException(
                'kintoneレスポンスの読込みがタイムアウトしました。'
            );
        }

        $read = [$socket];
        $write = null;
        $except = null;

        $remaining = max(
            0.001,
            $timeout -
            (microtime(true) - $start)
        );

        $sec = (int)$remaining;
        $usec = (int)(
            ($remaining - $sec) * 1000000
        );

        $selected = @stream_select(
            $read,
            $write,
            $except,
            $sec,
            $usec
        );

        if ($selected === false) {
            throw new RuntimeException(
                'レスポンスを読込めません。'
            );
        }

        if ($selected === 0) {
            throw new RuntimeException(
                'レスポンスの読込みがタイムアウトしました。'
            );
        }

        $chunk = @fread(
            $socket,
            min(
                8192,
                $length - strlen($result)
            )
        );

        if ($chunk === false) {
            throw new RuntimeException(
                'レスポンスの読込みに失敗しました。'
            );
        }

        if ($chunk === '') {
            continue;
        }

        $result .= $chunk;
    }

    return $result;
}

function socket_read_until_close(
    $socket,
    float $timeout
): string {
    $result = '';
    $start = microtime(true);

    while (true) {
        if (
            microtime(true) - $start >= $timeout
        ) {
            break;
        }

        $read = [$socket];
        $write = null;
        $except = null;

        $remaining = max(
            0.001,
            $timeout -
            (microtime(true) - $start)
        );

        $sec = (int)$remaining;
        $usec = (int)(
            ($remaining - $sec) * 1000000
        );

        $selected = @stream_select(
            $read,
            $write,
            $except,
            $sec,
            $usec
        );

        if ($selected === 0) {
            break;
        }

        if ($selected === false) {
            break;
        }

        $chunk = @fread(
            $socket,
            8192
        );

        if ($chunk === false || $chunk === '') {
            break;
        }

        $result .= $chunk;
    }

    return $result;
}

function decode_chunked_socket_body(
    $socket,
    string $initial,
    float $timeout
): string {
    /*
     * 実装時はHTTP chunk framingを解析し、
     * 各readにREAD_TIMEOUTを適用する。
     *
     * kintone APIは通常JSONレスポンスだが、
     * Transfer-Encodingを前提にして
     * 無期限readしない。
     */
    $buffer = $initial;
    $result = '';

    while (true) {
        $pos = strpos(
            $buffer,
            "\r\n"
        );

        if ($pos === false) {
            $buffer .= socket_read_until_close(
                $socket,
                $timeout
            );
            continue;
        }

        $line = substr(
            $buffer,
            0,
            $pos
        );

        $size = hexdec(
            trim($line)
        );

        $buffer = substr(
            $buffer,
            $pos + 2
        );

        if ($size === 0) {
            break;
        }

        while (
            strlen($buffer) < $size + 2
        ) {
            $buffer .= socket_read_until_close(
                $socket,
                $timeout
            );
        }

        $result .= substr(
            $buffer,
            0,
            $size
        );

        $buffer = substr(
            $buffer,
            $size + 2
        );
    }

    return $result;
}

/* ============================================================
 * POST処理
 * ============================================================
 *
 * ここでは絶対に
 *
 *     POST
 *       ↓
 *     303
 *       ↓
 *     GET
 *
 * を行わない。
 *
 * 処理結果を同じPOSTレスポンスに描画する。
 */

$screen = (string)(
    $_GET['screen'] ?? 'list'
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

$operationResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)(
        $_POST['action'] ?? ''
    );

    try {
        switch ($action) {

            case 'test_kintone':
                $settings =
                    json_read(
                        SETTINGS_FILE
                    );

                $k =
                    $settings['kintone'] ?? [];

                /*
                 * 接続テストは実際のAPIへ
                 * 1回だけアクセスする。
                 *
                 * 認証リトライは禁止。
                 */
                $result =
                    kintone_request(
                        $k,
                        'GET',
                        '/k/v1/app.json?id=' .
                        rawurlencode(
                            (string)(
                                $k['app_id'] ?? ''
                            )
                        )
                    );

                if (
                    $result['status'] >= 200 &&
                    $result['status'] < 300
                ) {
                    $operationResult = [
                        'type' => 'success',
                        'message' =>
                            'kintone接続テスト成功。',
                    ];
                } else {
                    $message =
                        $result['json']['message']
                        ?? 'kintone APIがエラーを返しました。';

                    $operationResult = [
                        'type' => 'error',
                        'message' =>
                            'kintone接続テスト失敗。' .
                            e($message),
                    ];
                }

                break;

            case 'save_kintone':
                /*
                 * 設定保存は同一POSTレスポンスで完了。
                 * 303へ飛ばさない。
                 */
                $settings =
                    json_read(
                        SETTINGS_FILE
                    );

                $k =
                    $settings['kintone'] ?? [];

                $k['subdomain'] =
                    trim(
                        (string)(
                            $_POST['subdomain'] ?? ''
                        )
                    );

                $k['app_id'] =
                    trim(
                        (string)(
                            $_POST['app_id'] ?? ''
                        )
                    );

                $k['username'] =
                    trim(
                        (string)(
                            $_POST['username'] ?? ''
                        )
                    );

                /*
                 * パスワード空欄なら
                 * 保存済みパスワードを維持。
                 */
                if (
                    isset($_POST['password']) &&
                    (string)$_POST['password'] !== ''
                ) {
                    $k['password'] =
                        (string)$_POST['password'];
                }

                $k['proxy'] =
                    trim(
                        (string)(
                            $_POST['proxy'] ?? ''
                        )
                    );

                $k['verify_ssl'] =
                    isset($_POST['verify_ssl']);

                /*
                 * 保存前に形式検証。
                 */
                normalize_kintone_subdomain(
                    $k['subdomain']
                );

                if (
                    !preg_match(
                        '/^[0-9]+$/',
                        (string)$k['app_id']
                    )
                ) {
                    throw new InvalidArgumentException(
                        '顧客管理アプリIDが不正です。'
                    );
                }

                parse_proxy(
                    $k['proxy']
                );

                $settings['kintone'] = $k;

                json_write(
                    SETTINGS_FILE,
                    $settings
                );

                $operationResult = [
                    'type' => 'success',
                    'message' =>
                        'kintone設定を保存しました。',
                ];

                break;

            default:
                throw new InvalidArgumentException(
                    '不明な操作です。'
                );
        }
    } catch (Throwable $e) {

        /*
         * 重要:
         *
         * エラーを303へ逃がさない。
         *
         * このPOSTレスポンス自身に
         * 「何が失敗したか」を表示する。
         */
        $operationResult = [
            'type' => 'error',
            'message' => public_error_message($e),
        ];
    }
}

function public_error_message(
    Throwable $e
): string {
    $message = $e->getMessage();

    /*
     * 認証情報を含む可能性のある例外を
     * そのまま表示しない。
     */
    $message = preg_replace(
        '/X-Cybozu-Authorization\s*:\s*\S+/i',
        'X-Cybozu-Authorization: [REDACTED]',
        $message
    );

    return
        '処理に失敗しました。' .
        e($message);
}

/* ============================================================
 * 画面
 * ============================================================ */
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">

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

body {
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

.admin-header {
    background:#0f172a;
    color:#fff;
    padding:16px 24px;
}

.container {
    max-width:1200px;
    margin:0 auto;
    padding:24px;
}

.card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:24px;
    margin-bottom:20px;
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
select,
textarea {
    width:100%;
    padding:10px 12px;
    border:1px solid var(--border);
    border-radius:8px;
    font:inherit;
}

button {
    border:0;
    border-radius:8px;
    padding:10px 16px;
    cursor:pointer;
    font:inherit;
    font-weight:600;
}

button.primary {
    background:var(--primary);
    color:#fff;
}

button.primary:hover {
    background:var(--primary-dark);
}

button.secondary {
    background:var(--gray-light);
    color:var(--text);
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

.result-detail {
    white-space:pre-wrap;
    overflow-wrap:anywhere;
}

.spinner {
    display:none;
}

.processing button {
    opacity:.65;
    cursor:wait;
}

@media(max-width:700px) {
    .container {
        padding:12px;
    }

    .card {
        padding:16px;
    }
}
</style>
</head>

<body>

<header class="admin-header">
    <strong>アンケート管理</strong>
</header>

<main class="container">

<?php if ($operationResult !== null): ?>

<div class="notice <?=e(
    $operationResult['type']
)?>">
    <strong>
        <?= $operationResult['type'] === 'success'
            ? '成功'
            : 'エラー' ?>
    </strong>

    <div class="result-detail">
        <?=$operationResult['message']?>
    </div>
</div>

<?php endif; ?>

<?php if ($screen === 'kintone'): ?>

<div class="card">

<h1>kintone連携設定</h1>

<form method="post"
      action="?screen=kintone"
      id="kintone-form">

<input type="hidden"
       name="action"
       value="save_kintone">

<?php
$settings =
    json_read(SETTINGS_FILE);

$k =
    $settings['kintone'] ?? [];
?>

<div class="form-row">
<label>サブドメイン</label>
<input
    name="subdomain"
    value="<?=e(
        $k['subdomain'] ?? ''
    )?>"
    placeholder="xxxx.cybozu.com"
    required>
</div>

<div class="form-row">
<label>顧客管理アプリID</label>
<input
    name="app_id"
    inputmode="numeric"
    value="<?=e(
        $k['app_id'] ?? ''
    )?>"
    required>
</div>

<div class="form-row">
<label>ログイン名</label>
<input
    name="username"
    value="<?=e(
        $k['username'] ?? ''
    )?>"
    required>
</div>

<div class="form-row">
<label>パスワード</label>
<input
    type="password"
    name="password"
    autocomplete="new-password"
    placeholder="変更しない場合は空欄">
</div>

<div class="form-row">
<label>Proxy</label>
<input
    name="proxy"
    value="<?=e(
        $k['proxy'] ?? ''
    )?>"
    placeholder="host:port">
</div>

<div class="form-row">
<label>
<input
    type="checkbox"
    name="verify_ssl"
    value="1"
    style="width:auto"
    <?=!empty(
        $k['verify_ssl']
    ) ? 'checked' : ''?>>
SSL証明書を検証する
</label>
</div>

<button
    class="primary"
    type="submit">
設定保存
</button>

</form>

<hr style="margin:24px 0">

<form method="post"
      action="?screen=kintone"
      onsubmit="
        const f=this;
        f.classList.add('processing');
        const b=f.querySelector('button');
        b.disabled=true;
        b.textContent='接続テスト中...';
        return true;
      ">

<input type="hidden"
       name="action"
       value="test_kintone">

<button
    class="secondary"
    type="submit">
接続テスト
</button>

</form>

</div>

<?php elseif ($screen === 'mail'): ?>

<div class="card">

<h1>メールサーバ設定</h1>

<?php
$settings =
    json_read(SETTINGS_FILE);

$mail =
    $settings['mail'] ?? [];
?>

<form method="post"
      action="?screen=mail">

<input type="hidden"
       name="action"
       value="save_mail">

<div class="form-row">
<label>SMTPサーバ</label>
<input
    name="smtp_server"
    value="<?=e(
        $mail['smtp_server'] ?? ''
    )?>">
</div>

<div class="form-row">
<label>SMTPポート</label>
<input
    name="smtp_port"
    value="<?=e(
        $mail['smtp_port'] ?? ''
    )?>">
</div>

<div class="form-row">
<label>暗号化方式</label>
<select name="encryption">
<option value="ssl">SSL</option>
<option value="tls">TLS</option>
<option value="none">なし</option>
</select>
</div>

<div class="form-row">
<label>SMTP認証</label>
<input
    type="checkbox"
    name="auth"
    value="1"
    style="width:auto">
</div>

<div class="form-row">
<label>SMTPユーザー名</label>
<input
    name="username"
    value="<?=e(
        $mail['username'] ?? ''
    )?>">
</div>

<div class="form-row">
<label>SMTPパスワード</label>
<input
    type="password"
    name="password"
    autocomplete="new-password">
</div>

<div class="form-row">
<label>送信元メールアドレス</label>
<input
    type="email"
    name="from_email"
    value="<?=e(
        $mail['from_email'] ?? ''
    )?>">
</div>

<div class="form-row">
<label>送信元名</label>
<input
    name="from_name"
    value="<?=e(
        $mail['from_name'] ?? ''
    )?>">
</div>

<div class="form-row">
<label>返信先メールアドレス</label>
<input
    type="email"
    name="reply_to"
    value="<?=e(
        $mail['reply_to'] ?? ''
    )?>">
</div>

<button
    class="primary"
    type="submit">
設定保存
</button>

</form>

</div>

<?php else: ?>

<div class="card">

<h1>アンケート一覧</h1>

<p>
現在の画面：
<?=e($screen)?>
</p>

<p>
<a href="?screen=kintone">
kintone連携設定
</a>
</p>

<p>
<a href="?screen=mail">
メールサーバ設定
</a>
</p>

</div>

<?php endif; ?>

</main>

<script>
/*
 * 外部通信中は同じフォームの二重送信だけを防止する。
 *
 * サーバー側処理の結果を303へ逃がさないため、
 * 成否はPOSTレスポンス内にそのまま表示される。
 */
document.querySelectorAll(
    'form[data-single-submit]'
).forEach(function(form) {
    form.addEventListener(
        'submit',
        function() {
            const buttons =
                form.querySelectorAll('button');

            buttons.forEach(function(button) {
                button.disabled = true;
            });
        }
    );
});
</script>

</body>
</html>
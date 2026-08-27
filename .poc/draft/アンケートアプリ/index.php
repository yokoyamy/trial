<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 * 単一エントリーポイント index.php
 *
 * 方針:
 * - DBなし
 * - PHP cURLなし
 * - PHP mail()なし
 * - CSRFなし（POC要件）
 * - 管理者認証なし
 * - kintone: X-Cybozu-Authorization
 * - SMTP: PHP socket
 * - 外部通信は必ず timeout
 * - 設定保存はJSONレスポンス
 * - 接続テストもJSONレスポンス
 * - POST処理で303/flashに依存しない
 * - 認証情報をブラウザJSへ返さない
 * - セッションIDをURLへ出さない
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

/* =========================================================
 * 共通
 * =======================================================*/

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

function ok(string $message, array $data = []): never
{
    json_response([
        'ok'      => true,
        'message' => $message,
        'data'    => $data,
    ]);
}

function fail(
    string $message,
    string $type = 'system',
    array $data = [],
    int $status = 400
): never {
    json_response([
        'ok'      => false,
        'type'    => $type,
        'message' => $message,
        'data'    => $data,
    ], $status);
}

function ensure_storage(): void
{
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0775, true)) {
        fail('データ保存領域を作成できません。', 'system', [], 500);
    }

    $defaults = [
        SETTINGS_FILE => [
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
                'connection_status' => '未設定',
                'last_test_at' => null,
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
            ],
        ],
        SURVEYS_FILE => [],
        CUSTOMERS_FILE => [],
        ANSWERS_FILE => [],
        SEND_LOG_FILE => [],
    ];

    foreach ($defaults as $file => $value) {
        if (!file_exists($file)) {
            atomic_write($file, $value);
        }
    }
}

function atomic_write(string $file, mixed $data): bool
{
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        return false;
    }

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function read_json(string $file, mixed $default = []): mixed
{
    if (!file_exists($file)) {
        return $default;
    }

    $raw = file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $data = json_decode($raw, true);

    return json_last_error() === JSON_ERROR_NONE
        ? $data
        : $default;
}

function post_string(string $key, string $default = ''): string
{
    return isset($_POST[$key])
        ? trim((string)$_POST[$key])
        : $default;
}

function post_int(string $key, int $default = 0): int
{
    return isset($_POST[$key])
        ? (int)$_POST[$key]
        : $default;
}

function now_iso(): string
{
    return date('Y-m-d\TH:i:s');
}

function new_id(string $prefix): string
{
    return $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
}

/* =========================================================
 * セッション
 * =======================================================*/

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');

    if ($path === '') {
        $path = '/';
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $path,
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/* =========================================================
 * URL / kintone
 * =======================================================*/

function normalize_kintone_host(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    $value = trim($value, "/ \t\n\r\0\x0B");

    if ($value === '') {
        fail('kintoneサブドメインを入力してください。', 'validation');
    }

    /*
     * 入力:
     *   example
     *   example.cybozu.com
     *   https://example.cybozu.com
     */
    if (preg_match('/^[A-Za-z0-9-]+$/', $value)) {
        return $value . '.cybozu.com';
    }

    if (!preg_match(
        '/^[A-Za-z0-9-]+\.cybozu\.com$/i',
        $value
    )) {
        fail(
            'kintoneサブドメインの形式が正しくありません。',
            'validation'
        );
    }

    return strtolower($value);
}

function kintone_auth_header(
    string $username,
    string $password
): string {
    return base64_encode($username . ':' . $password);
}

function parse_proxy(string $proxy): ?array
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (!preg_match(
        '/^([A-Za-z0-9.-]+):([0-9]{1,5})$/',
        $proxy,
        $m
    )) {
        fail(
            'Proxyは host:port 形式で指定してください。',
            'validation'
        );
    }

    $port = (int)$m[2];

    if ($port < 1 || $port > 65535) {
        fail('Proxyのポート番号が不正です。', 'validation');
    }

    return [
        'host' => $m[1],
        'port' => $port,
    ];
}

/**
 * PHP cURLを使わずHTTP/HTTPS通信する。
 *
 * TLS:
 *   verify_ssl=true  -> 証明書検証
 *   verify_ssl=false -> POC用に検証を無効化
 *
 * Proxy:
 *   http proxyによるCONNECTを実施。
 */
function http_request(
    string $url,
    string $method,
    array $headers = [],
    ?string $body = null,
    bool $verify_ssl = false,
    ?array $proxy = null
): array {
    $parts = parse_url($url);

    if ($parts === false || empty($parts['host'])) {
        throw new RuntimeException('接続先URLが不正です。');
    }

    $scheme = strtolower($parts['scheme'] ?? 'https');
    $host   = $parts['host'];
    $port   = (int)($parts['port'] ?? (
        $scheme === 'https' ? 443 : 80
    ));
    $path   = ($parts['path'] ?? '/') .
              (isset($parts['query']) ? '?' . $parts['query'] : '');

    $contextOptions = [
        'socket' => [
            'tcp_nodelay' => true,
        ],
        'ssl' => [
            'verify_peer'      => $verify_ssl,
            'verify_peer_name' => $verify_ssl,
            'allow_self_signed'=> !$verify_ssl,
            'SNI_enabled'      => true,
            'peer_name'        => $host,
        ],
    ];

    /*
     * Proxyを使用する場合:
     *
     * proxy TCP接続
     *      ↓
     * CONNECT host:443
     *      ↓
     * TLS
     */
    if ($proxy !== null) {
        $transport = 'tcp://' . $proxy['host'] . ':' . $proxy['port'];

        $fp = @stream_socket_client(
            $transport,
            $errno,
            $errstr,
            CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            stream_context_create([
                'socket' => [
                    'tcp_nodelay' => true,
                ],
            ])
        );

        if ($fp === false) {
            throw new RuntimeException(
                'Proxyへ接続できません: ' . $errstr .
                ' (' . $errno . ')'
            );
        }

        stream_set_timeout($fp, READ_TIMEOUT);

        $connect =
            "CONNECT {$host}:{$port} HTTP/1.1\r\n" .
            "Host: {$host}:{$port}\r\n" .
            "Connection: keep-alive\r\n\r\n";

        fwrite($fp, $connect);

        $responseHeaders = read_http_headers($fp);

        if (!preg_match(
            '#^HTTP/\S+\s+2\d\d#i',
            $responseHeaders
        )) {
            fclose($fp);

            throw new RuntimeException(
                'Proxy CONNECTに失敗しました。応答: ' .
                first_line($responseHeaders)
            );
        }

        if ($scheme === 'https') {
            stream_context_set_option(
                $fp,
                'ssl',
                'verify_peer',
                $verify_ssl
            );

            stream_context_set_option(
                $fp,
                'ssl',
                'verify_peer_name',
                $verify_ssl
            );

            stream_context_set_option(
                $fp,
                'ssl',
                'allow_self_signed',
                !$verify_ssl
            );

            stream_context_set_option(
                $fp,
                'ssl',
                'peer_name',
                $host
            );

            if (!stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )) {
                fclose($fp);
                throw new RuntimeException(
                    'TLS接続を確立できません。' .
                    'Proxy経由のTLSハンドシェイクに失敗しました。'
                );
            }
        }
    } else {
        $transport =
            ($scheme === 'https' ? 'tls' : 'tcp') .
            '://' . $host . ':' . $port;

        $fp = @stream_socket_client(
            $transport,
            $errno,
            $errstr,
            CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            stream_context_create($contextOptions)
        );

        if ($fp === false) {
            throw new RuntimeException(
                '接続できません: ' . $errstr .
                ' (' . $errno . ')'
            );
        }
    }

    stream_set_timeout($fp, READ_TIMEOUT);

    $requestHeaders = '';

    $hasHost = false;
    $hasConnection = false;

    foreach ($headers as $name => $value) {
        if (strcasecmp($name, 'Host') === 0) {
            $hasHost = true;
        }

        if (strcasecmp($name, 'Connection') === 0) {
            $hasConnection = true;
        }

        $requestHeaders .= $name . ': ' . $value . "\r\n";
    }

    if (!$hasHost) {
        $requestHeaders .= 'Host: ' . $host . "\r\n";
    }

    if (!$hasConnection) {
        $requestHeaders .= "Connection: close\r\n";
    }

    if ($body !== null) {
        $requestHeaders .=
            'Content-Length: ' . strlen($body) . "\r\n";
    }

    $request =
        $method . ' ' . $path . " HTTP/1.1\r\n" .
        $requestHeaders .
        "\r\n" .
        ($body ?? '');

    $written = fwrite($fp, $request);

    if ($written === false) {
        fclose($fp);
        throw new RuntimeException(
            'HTTPリクエストを書き込めません。'
        );
    }

    $rawHeaders = read_http_headers($fp);

    if (!preg_match(
        '#^HTTP/\S+\s+(\d{3})#i',
        $rawHeaders,
        $m
    )) {
        fclose($fp);
        throw new RuntimeException(
            'HTTP応答を解析できません。'
        );
    }

    $status = (int)$m[1];

    $headerMap = parse_header_block($rawHeaders);

    $contentLength =
        isset($headerMap['content-length'])
            ? (int)$headerMap['content-length']
            : null;

    $transferEncoding =
        strtolower($headerMap['transfer-encoding'] ?? '');

    if ($contentLength !== null) {
        $responseBody = read_exact(
            $fp,
            $contentLength
        );
    } elseif (str_contains($transferEncoding, 'chunked')) {
        $responseBody = read_chunked_body($fp);
    } else {
        $responseBody = stream_get_contents($fp) ?: '';
    }

    fclose($fp);

    return [
        'status'  => $status,
        'headers' => $headerMap,
        'body'    => $responseBody,
    ];
}

function first_line(string $text): string
{
    $line = preg_split('/\r\n|\r|\n/', $text)[0] ?? '';
    return trim($line);
}

function read_http_headers($fp): string
{
    $headers = '';

    while (!feof($fp)) {
        $line = fgets($fp);

        if ($line === false) {
            break;
        }

        $headers .= $line;

        if (str_ends_with($headers, "\r\n\r\n")) {
            break;
        }

        if (strlen($headers) > 65536) {
            throw new RuntimeException(
                'HTTPヘッダーが大きすぎます。'
            );
        }
    }

    return $headers;
}

function parse_header_block(string $raw): array
{
    $result = [];

    foreach (
        preg_split('/\r\n|\r|\n/', $raw)
        as $line
    ) {
        if (!str_contains($line, ':')) {
            continue;
        }

        [$name, $value] = explode(':', $line, 2);

        $result[strtolower(trim($name))] = trim($value);
    }

    return $result;
}

function read_exact($fp, int $length): string
{
    $result = '';

    while (strlen($result) < $length && !feof($fp)) {
        $chunk = fread(
            $fp,
            min(8192, $length - strlen($result))
        );

        if ($chunk === false || $chunk === '') {
            break;
        }

        $result .= $chunk;
    }

    return $result;
}

function read_chunked_body($fp): string
{
    $body = '';

    while (!feof($fp)) {
        $line = fgets($fp);

        if ($line === false) {
            break;
        }

        $size = hexdec(trim($line));

        if ($size === 0) {
            fgets($fp);
            break;
        }

        $chunk = read_exact($fp, $size);
        $body .= $chunk;

        fgets($fp);
    }

    return $body;
}

/* =========================================================
 * kintone
 * =======================================================*/

function validate_kintone_settings(array $k): array
{
    $host = normalize_kintone_host(
        (string)($k['subdomain'] ?? '')
    );

    $appId = (int)($k['app_id'] ?? 0);

    if ($appId <= 0) {
        fail(
            'kintoneアプリIDを入力してください。',
            'validation'
        );
    }

    $username = trim((string)($k['username'] ?? ''));
    $password = (string)($k['password'] ?? '');

    if ($username === '') {
        fail(
            'kintoneログイン名を入力してください。',
            'validation'
        );
    }

    if ($password === '') {
        fail(
            'kintoneパスワードを入力してください。',
            'validation'
        );
    }

    $proxy = parse_proxy(
        (string)($k['proxy'] ?? '')
    );

    return [
        'host'       => $host,
        'app_id'     => $appId,
        'username'   => $username,
        'password'   => $password,
        'proxy'      => $proxy,
        'verify_ssl' => !empty($k['verify_ssl']),
    ];
}

function kintone_request(
    array $k,
    string $method,
    string $endpoint,
    ?string $body = null
): array {
    $cfg = validate_kintone_settings($k);

    $url =
        'https://' .
        $cfg['host'] .
        '/k/v1/' .
        ltrim($endpoint, '/');

    $headers = [
        'X-Cybozu-Authorization' =>
            kintone_auth_header(
                $cfg['username'],
                $cfg['password']
            ),
        'Accept' => 'application/json',
    ];

    if ($body !== null) {
        $headers['Content-Type'] = 'application/json';
    }

    return http_request(
        $url,
        $method,
        $headers,
        $body,
        $cfg['verify_ssl'],
        $cfg['proxy']
    );
}

function kintone_test(array $k): array
{
    $response = kintone_request(
        $k,
        'GET',
        'app.json?app=' . rawurlencode((string)(int)$k['app_id'])
    );

    if ($response['status'] >= 200 &&
        $response['status'] < 300) {
        return [
            'success' => true,
            'message' => 'kintoneへの接続に成功しました。',
        ];
    }

    $detail = kintone_error_detail($response);

    return [
        'success' => false,
        'message' =>
            'kintone接続に失敗しました。' .
            ($detail !== '' ? ' ' . $detail : ''),
    ];
}

function kintone_error_detail(array $response): string
{
    $status = (int)($response['status'] ?? 0);
    $body = (string)($response['body'] ?? '');

    $json = json_decode($body, true);

    if (is_array($json)) {
        $message =
            trim((string)($json['message'] ?? ''));

        if ($message !== '') {
            return
                'HTTP ' . $status .
                ': ' . $message;
        }
    }

    return 'HTTP ' . $status . '。';
}

function kintone_fields(array $k): array
{
    $response = kintone_request(
        $k,
        'GET',
        'app/form/fields.json?app=' .
        rawurlencode((string)(int)$k['app_id'])
    );

    if ($response['status'] < 200 ||
        $response['status'] >= 300) {
        throw new RuntimeException(
            '項目一覧取得に失敗しました。' .
            ' ' . kintone_error_detail($response)
        );
    }

    $json = json_decode(
        (string)$response['body'],
        true
    );

    if (!is_array($json)) {
        throw new RuntimeException(
            'kintoneから不正なJSONが返されました。'
        );
    }

    return $json['properties'] ?? [];
}

function kintone_records(array $k, array $mapping): array
{
    $response = kintone_request(
        $k,
        'GET',
        'records.json?app=' .
        rawurlencode((string)(int)$k['app_id']) .
        '&query=' . rawurlencode('order by $id asc')
    );

    if ($response['status'] < 200 ||
        $response['status'] >= 300) {
        throw new RuntimeException(
            '顧客情報取得に失敗しました。' .
            ' ' . kintone_error_detail($response)
        );
    }

    $json = json_decode(
        (string)$response['body'],
        true
    );

    if (!is_array($json)) {
        throw new RuntimeException(
            'kintoneから不正なJSONが返されました。'
        );
    }

    $records = [];

    foreach (($json['records'] ?? []) as $record) {
        $get = static function (
            string $field
        ) use ($record): string {
            if ($field === '') {
                return '';
            }

            $value = $record[$field]['value'] ?? '';

            if (is_array($value)) {
                return implode(
                    ', ',
                    array_map(
                        static fn($v) =>
                            is_array($v)
                                ? (string)($v['name'] ?? '')
                                : (string)$v,
                        $value
                    )
                );
            }

            return (string)$value;
        };

        $records[] = [
            'id'           => new_id('customer'),
            'kintone_id'   => $get('$id'),
            'organization' => $get(
                (string)($mapping['organization'] ?? '')
            ),
            'name' => $get(
                (string)($mapping['name'] ?? '')
            ),
            'email' => $get(
                (string)($mapping['email'] ?? '')
            ),
            'department' => $get(
                (string)($mapping['department'] ?? '')
            ),
            'phone' => $get(
                (string)($mapping['phone'] ?? '')
            ),
            'address' => $get(
                (string)($mapping['address'] ?? '')
            ),
            'updated_at' => now_iso(),
        ];
    }

    return $records;
}

/* =========================================================
 * SMTP
 * =======================================================*/

function smtp_connect(array $mail)
{
    $host = trim((string)($mail['host'] ?? ''));
    $port = (int)($mail['port'] ?? 0);
    $encryption = strtolower(
        trim((string)($mail['encryption'] ?? 'none'))
    );

    if ($host === '') {
        throw new RuntimeException(
            'SMTPサーバを入力してください。'
        );
    }

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException(
            'SMTPポートが不正です。'
        );
    }

    if (!in_array(
        $encryption,
        ['ssl', 'tls', 'none'],
        true
    )) {
        throw new RuntimeException(
            '暗号化方式が不正です。'
        );
    }

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ],
    ]);

    $transport =
        $encryption === 'ssl'
            ? 'ssl://'
            : 'tcp://';

    $fp = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ($fp === false) {
        throw new RuntimeException(
            'SMTPサーバへ接続できません: ' .
            $errstr . ' (' . $errno . ')'
        );
    }

    stream_set_timeout($fp, READ_TIMEOUT);

    smtp_expect($fp, [220]);

    smtp_command(
        $fp,
        'EHLO ' . smtp_local_name()
    );

    if ($encryption === 'tls') {
        smtp_command(
            $fp,
            'STARTTLS',
            [220]
        );

        if (!stream_socket_enable_crypto(
            $fp,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        )) {
            fclose($fp);

            throw new RuntimeException(
                'SMTPのTLS接続を確立できません。' .
                ' STARTTLSに失敗しました。'
            );
        }

        smtp_command(
            $fp,
            'EHLO ' . smtp_local_name()
        );
    }

    if (!empty($mail['auth'])) {
        $username =
            (string)($mail['username'] ?? '');
        $password =
            (string)($mail['password'] ?? '');

        if ($username === '' || $password === '') {
            fclose($fp);

            throw new RuntimeException(
                'SMTP認証情報が設定されていません。'
            );
        }

        smtp_command(
            $fp,
            'AUTH LOGIN',
            [334]
        );

        smtp_command(
            $fp,
            base64_encode($username),
            [334]
        );

        smtp_command(
            $fp,
            base64_encode($password),
            [235]
        );
    }

    return $fp;
}

function smtp_local_name(): string
{
    $name = $_SERVER['SERVER_NAME'] ?? 'localhost';

    return preg_match(
        '/^[A-Za-z0-9.-]+$/',
        $name
    )
        ? $name
        : 'localhost';
}

function smtp_read($fp): array
{
    $lines = [];

    while (!feof($fp)) {
        $line = fgets($fp);

        if ($line === false) {
            break;
        }

        $lines[] = rtrim($line, "\r\n");

        if (preg_match(
            '/^\d{3}\s/',
            $line
        )) {
            break;
        }
    }

    if (!$lines) {
        throw new RuntimeException(
            'SMTP応答を受信できません。'
        );
    }

    $last = end($lines);

    $code = (int)substr($last, 0, 3);

    return [
        'code' => $code,
        'text' => implode("\n", $lines),
    ];
}

function smtp_expect($fp, array $codes): array
{
    $response = smtp_read($fp);

    if (!in_array(
        $response['code'],
        $codes,
        true
    )) {
        throw new RuntimeException(
            'SMTP応答エラー。' .
            ' HTTPではなくSMTP応答です。' .
            ' 応答コード=' .
            $response['code']
        );
    }

    return $response;
}

function smtp_command(
    $fp,
    string $command,
    array $expected = [250]
): array {
    fwrite($fp, $command . "\r\n");

    return smtp_expect(
        $fp,
        $expected
    );
}

function smtp_test(array $mail): array
{
    $fp = smtp_connect($mail);

    try {
        smtp_command(
            $fp,
            'QUIT',
            [221]
        );
    } finally {
        fclose($fp);
    }

    return [
        'success' => true,
        'message' => 'SMTPサーバへの接続に成功しました。',
    ];
}

function smtp_send(
    array $mail,
    string $to,
    string $subject,
    string $body
): void {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(
            '宛先メールアドレスが不正です。'
        );
    }

    $from = trim(
        (string)($mail['from_email'] ?? '')
    );

    if (!filter_var(
        $from,
        FILTER_VALIDATE_EMAIL
    )) {
        throw new RuntimeException(
            '送信元メールアドレスが不正です。'
        );
    }

    $fp = smtp_connect($mail);

    try {
        smtp_command(
            $fp,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        smtp_command(
            $fp,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        smtp_command(
            $fp,
            'DATA',
            [354]
        );

        $fromName = trim(
            (string)($mail['from_name'] ?? '')
        );

        $fromHeader =
            $fromName !== ''
                ? '=?UTF-8?B?' .
                  base64_encode($fromName) .
                  '?= <' . $from . '>'
                : $from;

        $replyTo = trim(
            (string)($mail['reply_to'] ?? '')
        );

        $headers =
            'From: ' . $fromHeader . "\r\n" .
            'To: ' . $to . "\r\n";

        if ($replyTo !== '') {
            $headers .=
                'Reply-To: ' . $replyTo . "\r\n";
        }

        $headers .=
            'Subject: =?UTF-8?B?' .
            base64_encode($subject) .
            "?=\r\n" .
            "MIME-Version: 1.0\r\n" .
            "Content-Type: text/plain; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n" .
            "\r\n";

        $message =
            $headers .
            normalize_smtp_body($body) .
            "\r\n.\r\n";

        fwrite($fp, $message);

        smtp_expect($fp, [250]);

        smtp_command(
            $fp,
            'QUIT',
            [221]
        );
    } finally {
        fclose($fp);
    }
}

function normalize_smtp_body(string $body): string
{
    $body = str_replace(
        ["\r\n", "\r"],
        "\n",
        $body
    );

    $body = str_replace(
        "\n.",
        "\n..",
        $body
    );

    return str_replace(
        "\n",
        "\r\n",
        $body
    );
}

/* =========================================================
 * 設定処理
 * =======================================================*/

function save_kintone(): void
{
    $settings = read_json(SETTINGS_FILE, []);

    $old = $settings['kintone'] ?? [];

    $password = post_string(
        'password',
        (string)($old['password'] ?? '')
    );

    $data = [
        'subdomain' => post_string('subdomain'),
        'app_id' => post_int('app_id'),
        'username' => post_string('username'),
        'password' => $password,
        'proxy' => post_string('proxy'),
        'verify_ssl' =>
            isset($_POST['verify_ssl']) &&
            (string)$_POST['verify_ssl'] === '1',
        'field_mapping' => [
            'organization' =>
                post_string('field_organization'),
            'name' =>
                post_string('field_name'),
            'email' =>
                post_string('field_email'),
            'department' =>
                post_string('field_department'),
            'phone' =>
                post_string('field_phone'),
            'address' =>
                $_POST['field_address'] ?? '',
        ],
        'connection_status' =>
            $old['connection_status'] ?? '未設定',
        'last_test_at' =>
            $old['last_test_at'] ?? null,
    ];

    if (is_string($data['field_mapping']['address'])) {
        $data['field_mapping']['address'] = array_values(
            array_filter(
                array_map(
                    'trim',
                    explode(
                        ',',
                        $data['field_mapping']['address']
                    )
                ),
                static fn($v) => $v !== ''
            )
        );
    }

    /*
     * 「保存しました」と表示する前に、
     * 実際にファイルへ保存する。
     */
    if (!atomic_write(
        SETTINGS_FILE,
        array_replace(
            $settings,
            ['kintone' => $data]
        )
    )) {
        fail(
            'kintone設定を保存できませんでした。',
            'system',
            [],
            500
        );
    }

    ok('kintone設定を保存しました。');
}

function save_mail(): void
{
    $settings = read_json(SETTINGS_FILE, []);

    $old = $settings['mail'] ?? [];

    $password = post_string(
        'password',
        (string)($old['password'] ?? '')
    );

    $data = [
        'host' =>
            post_string('host'),
        'port' =>
            post_int('port', 587),
        'encryption' =>
            post_string('encryption', 'tls'),
        'auth' =>
            isset($_POST['auth']) &&
            (string)$_POST['auth'] === '1',
        'username' =>
            post_string('username'),
        'password' =>
            $password,
        'from_email' =>
            post_string('from_email'),
        'from_name' =>
            post_string('from_name'),
        'reply_to' =>
            post_string('reply_to'),
        'connection_status' =>
            $old['connection_status'] ?? '未設定',
        'last_test_at' =>
            $old['last_test_at'] ?? null,
    ];

    if (!atomic_write(
        SETTINGS_FILE,
        array_replace(
            $settings,
            ['mail' => $data]
        )
    )) {
        fail(
            'メール設定を保存できませんでした。',
            'system',
            [],
            500
        );
    }

    ok('メール設定を保存しました。');
}

/* =========================================================
 * アクション
 * =======================================================*/

function handle_action(): void
{
    $action = post_string('action');

    if ($action === '') {
        fail('処理が指定されていません。', 'validation');
    }

    $settings = read_json(
        SETTINGS_FILE,
        []
    );

    try {
        switch ($action) {

            case 'save_kintone':
                save_kintone();
                break;

            case 'test_kintone':
                $k = $settings['kintone'] ?? [];

                $result = kintone_test($k);

                $settings['kintone']['connection_status'] =
                    $result['success']
                        ? '接続確認済み'
                        : '接続できません';

                $settings['kintone']['last_test_at'] =
                    now_iso();

                atomic_write(
                    SETTINGS_FILE,
                    $settings
                );

                if ($result['success']) {
                    ok($result['message']);
                }

                fail(
                    $result['message'],
                    'communication'
                );
                break;

            case 'kintone_fields':
                $k = $settings['kintone'] ?? [];

                $fields = kintone_fields($k);

                ok(
                    'kintoneの項目一覧を取得しました。',
                    [
                        'fields' => $fields,
                    ]
                );
                break;

            case 'kintone_sync':
                $k = $settings['kintone'] ?? [];

                $mapping =
                    $k['field_mapping'] ?? [];

                $customers =
                    kintone_records(
                        $k,
                        $mapping
                    );

                if (!atomic_write(
                    CUSTOMERS_FILE,
                    $customers
                )) {
                    fail(
                        '顧客情報を保存できませんでした。',
                        'system',
                        [],
                        500
                    );
                }

                ok(
                    '顧客情報を同期しました。',
                    [
                        'count' =>
                            count($customers),
                    ]
                );
                break;

            case 'save_mail':
                save_mail();
                break;

            case 'test_mail':
                $mail =
                    $settings['mail'] ?? [];

                $result = smtp_test($mail);

                $settings['mail']['connection_status'] =
                    '接続確認済み';

                $settings['mail']['last_test_at'] =
                    now_iso();

                atomic_write(
                    SETTINGS_FILE,
                    $settings
                );

                ok($result['message']);
                break;

            case 'send_test_mail':
                $mail =
                    $settings['mail'] ?? [];

                $to = post_string('to');

                if (!filter_var(
                    $to,
                    FILTER_VALIDATE_EMAIL
                )) {
                    fail(
                        'テストメール送信先メールアドレスが不正です。',
                        'validation'
                    );
                }

                smtp_send(
                    $mail,
                    $to,
                    'アンケートアプリ テストメール',
                    "テストメールです。\n\n" .
                    "SMTP接続および送信処理が正常に動作しました。"
                );

                ok(
                    'テストメールを送信しました。'
                );
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
                change_survey_status();
                break;

            case 'save_answer':
                save_answer();
                break;

            case 'send_mail':
                send_survey_mail();
                break;

            default:
                fail(
                    '未対応の処理です。',
                    'validation'
                );
        }
    } catch (Throwable $e) {
        /*
         * パスワードや認証ヘッダーは
         * 絶対にここから返さない。
         */
        $message = $e->getMessage();

        if ($message === '') {
            $message = '外部サービスとの通信に失敗しました。';
        }

        fail(
            $message,
            'communication'
        );
    }
}

/* =========================================================
 * アンケート
 * =======================================================*/

function normalize_survey(array $survey): array
{
    $survey['status'] =
        $survey['status'] ?? 'draft';

    if (
        $survey['status'] === 'published' &&
        !empty($survey['endAt']) &&
        strtotime((string)$survey['endAt']) !== false &&
        strtotime((string)$survey['endAt']) < time()
    ) {
        $survey['status'] = 'ended';
    }

    return $survey;
}

function save_survey(): void
{
    $surveys = read_json(
        SURVEYS_FILE,
        []
    );

    $id = post_string('id');

    $title = post_string('title');

    if ($title === '') {
        fail(
            'アンケートタイトルを入力してください。',
            'validation'
        );
    }

    $existing = null;

    foreach ($surveys as $survey) {
        if (($survey['id'] ?? '') === $id) {
            $existing = $survey;
            break;
        }
    }

    $status =
        $existing['status'] ??
        'draft';

    $survey = [
        'id' =>
            $id !== ''
                ? $id
                : new_id('survey'),

        'title' =>
            $title,

        'description' =>
            post_string('description'),

        'startAt' =>
            post_string('startAt'),

        'endAt' =>
            post_string('endAt'),

        'numbering' =>
            in_array(
                post_string('numbering'),
                ['global', 'group'],
                true
            )
                ? post_string('numbering')
                : 'global',

        'status' =>
            $status,

        'groups' =>
            decode_json_post(
                'groups',
                []
            ),

        'createdAt' =>
            $existing['createdAt']
                ?? now_iso(),

        'updatedAt' =>
            now_iso(),
    ];

    if ($existing === null) {
        $surveys[] = $survey;
    } else {
        foreach ($surveys as &$item) {
            if (($item['id'] ?? '') === $id) {
                $item = $survey;
                break;
            }
        }
        unset($item);
    }

    if (!atomic_write(
        SURVEYS_FILE,
        $surveys
    )) {
        fail(
            'アンケートを保存できませんでした。',
            'system',
            [],
            500
        );
    }

    ok(
        'アンケートを保存しました。',
        ['id' => $survey['id']]
    );
}

function decode_json_post(
    string $key,
    mixed $default
): mixed {
    $raw = $_POST[$key] ?? null;

    if (!is_string($raw) || trim($raw) === '') {
        return $default;
    }

    $data = json_decode(
        $raw,
        true
    );

    return json_last_error() === JSON_ERROR_NONE
        ? $data
        : $default;
}

function delete_survey(): void
{
    $id = post_string('id');

    if ($id === '') {
        fail(
            'アンケートIDがありません。',
            'validation'
        );
    }

    $surveys = read_json(
        SURVEYS_FILE,
        []
    );

    $new = [];

    foreach ($surveys as $survey) {
        if (($survey['id'] ?? '') !== $id) {
            $new[] = $survey;
        }
    }

    if (count($new) === count($surveys)) {
        fail(
            '対象アンケートが見つかりません。',
            'data'
        );
    }

    atomic_write(
        SURVEYS_FILE,
        $new
    );

    ok('アンケートを削除しました。');
}

function duplicate_survey(): void
{
    $id = post_string('id');

    $surveys = read_json(
        SURVEYS_FILE,
        []
    );

    foreach ($surveys as $survey) {
        if (($survey['id'] ?? '') !== $id) {
            continue;
        }

        $copy = $survey;

        $copy['id'] =
            new_id('survey');

        $copy['title'] =
            (string)$copy['title'] .
            '（コピー）';

        $copy['status'] = 'draft';

        $copy['createdAt'] = now_iso();
        $copy['updatedAt'] = now_iso();

        $surveys[] = $copy;

        atomic_write(
            SURVEYS_FILE,
            $surveys
        );

        ok(
            'アンケートを複製しました。',
            ['id' => $copy['id']]
        );
    }

    fail(
        '対象アンケートが見つかりません。',
        'data'
    );
}

function change_survey_status(): void
{
    $id = post_string('id');
    $status = post_string('status');

    if (!in_array(
        $status,
        ['draft', 'published', 'stopped'],
        true
    )) {
        fail(
            '指定された状態へ変更できません。',
            'validation'
        );
    }

    $surveys = read_json(
        SURVEYS_FILE,
        []
    );

    foreach ($surveys as &$survey) {
        if (($survey['id'] ?? '') !== $id) {
            continue;
        }

        $survey = normalize_survey($survey);

        if (($survey['status'] ?? '') === 'ended') {
            fail(
                '終了したアンケートは変更できません。',
                'data'
            );
        }

        $survey['status'] = $status;
        $survey['updatedAt'] = now_iso();

        unset($survey);

        atomic_write(
            SURVEYS_FILE,
            $surveys
        );

        ok('状態を変更しました。');
    }

    unset($survey);

    fail(
        '対象アンケートが見つかりません。',
        'data'
    );
}

/* =========================================================
 * 回答
 * =======================================================*/

function save_answer(): void
{
    $surveyId = post_string('survey_id');

    if ($surveyId === '') {
        fail(
            'アンケートIDがありません。',
            'validation'
        );
    }

    $answers = read_json(
        ANSWERS_FILE,
        []
    );

    $answers[] = [
        'id' =>
            new_id('answer'),

        'survey_id' =>
            $surveyId,

        'answers' =>
            decode_json_post(
                'answers',
                []
            ),

        'created_at' =>
            now_iso(),
    ];

    if (!atomic_write(
        ANSWERS_FILE,
        $answers
    )) {
        fail(
            '回答を保存できませんでした。',
            'system',
            [],
            500
        );
    }

    ok(
        '回答を送信しました。'
    );
}

/* =========================================================
 * メール送信
 * =======================================================*/

function replace_mail_variables(
    string $text,
    array $customer,
    string $surveyUrl
): string {
    return str_replace(
        [
            '{顧客名}',
            '{アンケートURL}',
        ],
        [
            (string)($customer['name'] ?? ''),
            $surveyUrl,
        ],
        $text
    );
}

function send_survey_mail(): void
{
    $surveyId = post_string('survey_id');

    if ($surveyId === '') {
        fail(
            '対象アンケートが指定されていません。',
            'validation'
        );
    }

    $customerIds =
        $_POST['customer_ids'] ?? [];

    if (!is_array($customerIds) ||
        !$customerIds) {
        fail(
            '送信対象の顧客を選択してください。',
            'validation'
        );
    }

    $subject =
        post_string('subject');

    $body =
        post_string('body');

    if ($subject === '' || $body === '') {
        fail(
            'メール件名と本文を入力してください。',
            'validation'
        );
    }

    $settings = read_json(
        SETTINGS_FILE,
        []
    );

    $mail =
        $settings['mail'] ?? [];

    $customers = read_json(
        CUSTOMERS_FILE,
        []
    );

    $logs = read_json(
        SEND_LOG_FILE,
        []
    );

    $success = 0;
    $errors = [];

    $baseUrl =
        rtrim(
            (string)(
                $_POST['survey_base_url'] ??
                ''
            ),
            '/'
        );

    foreach ($customers as $customer) {
        $customerId =
            (string)($customer['id'] ?? '');

        if (!in_array(
            $customerId,
            $customerIds,
            true
        )) {
            continue;
        }

        $email =
            (string)($customer['email'] ?? '');

        try {
            $url =
                $baseUrl .
                '/index.php?screen=answer&id=' .
                rawurlencode($surveyId);

            $finalSubject =
                replace_mail_variables(
                    $subject,
                    $customer,
                    $url
                );

            $finalBody =
                replace_mail_variables(
                    $body,
                    $customer,
                    $url
                );

            smtp_send(
                $mail,
                $email,
                $finalSubject,
                $finalBody
            );

            $logs[] = [
                'id' =>
                    new_id('send'),

                'survey_id' =>
                    $surveyId,

                'customer_id' =>
                    $customerId,

                'email' =>
                    $email,

                'status' =>
                    'success',

                'created_at' =>
                    now_iso(),
            ];

            $success++;
        } catch (Throwable $e) {
            $errors[] =
                '顧客 ' .
                (string)($customer['name'] ?? '') .
                ': ' .
                $e->getMessage();

            $logs[] = [
                'id' =>
                    new_id('send'),

                'survey_id' =>
                    $surveyId,

                'customer_id' =>
                    $customerId,

                'email' =>
                    $email,

                'status' =>
                    'error',

                'created_at' =>
                    now_iso(),
            ];
        }
    }

    atomic_write(
        SEND_LOG_FILE,
        $logs
    );

    ok(
        $success .
        '件のメールを送信しました。',
        [
            'success' =>
                $success,

            'errors' =>
                $errors,
        ]
    );
}

/* =========================================================
 * GET画面
 * =======================================================*/

function get_screen(): string
{
    $screen =
        trim((string)(
            $_GET['screen'] ?? 'list'
        ));

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

    return in_array(
        $screen,
        $allowed,
        true
    )
        ? $screen
        : 'list';
}

function get_survey(
    string $id
): ?array {
    $surveys = read_json(
        SURVEYS_FILE,
        []
    );

    foreach ($surveys as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return normalize_survey($survey);
        }
    }

    return null;
}

/* =========================================================
 * HTML
 * =======================================================*/

function page_start(
    string $title,
    bool $admin = true
): void {
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= e($title) ?></title>

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

.header {
    background:#0f172a;
    color:#fff;
    padding:16px 24px;
}

.header-inner {
    max-width:1280px;
    margin:auto;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
}

.header-title {
    font-weight:700;
}

.nav {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.nav a {
    color:#fff;
    padding:8px 12px;
    border-radius:7px;
}

.nav a:hover {
    background:#1e293b;
}

.container {
    max-width:1280px;
    margin:24px auto;
    padding:0 16px;
}

.card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:20px;
    margin-bottom:20px;
}

.toolbar {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:20px;
}

.btn {
    border:1px solid var(--border);
    background:#fff;
    color:var(--text);
    padding:9px 14px;
    border-radius:8px;
}

.btn-primary {
    background:var(--primary);
    border-color:var(--primary);
    color:#fff;
}

.btn-danger {
    background:var(--danger);
    border-color:var(--danger);
    color:#fff;
}

.btn-success {
    background:var(--success);
    border-color:var(--success);
    color:#fff;
}

.btn:disabled {
    opacity:.5;
    cursor:not-allowed;
}

.form-grid {
    display:grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap:16px;
}

.form-group {
    display:flex;
    flex-direction:column;
    gap:6px;
}

.form-group.full {
    grid-column:1/-1;
}

label {
    font-weight:600;
}

input,
textarea,
select {
    width:100%;
    border:1px solid var(--border);
    border-radius:8px;
    padding:10px 12px;
    background:#fff;
}

textarea {
    min-height:140px;
    resize:vertical;
}

.table-wrap {
    overflow-x:auto;
}

table {
    width:100%;
    min-width:900px;
    border-collapse:collapse;
}

th,
td {
    padding:11px 10px;
    border-bottom:1px solid var(--border);
    text-align:left;
    white-space:nowrap;
}

th {
    background:var(--gray-light);
}

.badge {
    display:inline-block;
    padding:4px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}

.badge-draft {
    background:#e2e8f0;
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

.message {
    display:none;
    padding:12px 14px;
    border-radius:8px;
    margin-bottom:16px;
}

.message.show {
    display:block;
}

.message.success {
    background:#dcfce7;
    color:#166534;
}

.message.error {
    background:#fee2e2;
    color:#991b1b;
}

.message.info {
    background:#dbeafe;
    color:#1e40af;
}

.spinner {
    display:inline-block;
    width:16px;
    height:16px;
    border:2px solid currentColor;
    border-right-color:transparent;
    border-radius:50%;
    animation:spin .7s linear infinite;
    vertical-align:-3px;
    margin-right:6px;
}

@keyframes spin {
    to { transform:rotate(360deg); }
}

.actions {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.group {
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
    margin-bottom:14px;
    background:#fff;
}

.question {
    border:1px solid var(--border);
    border-radius:8px;
    padding:12px;
    margin-top:10px;
    background:#f8fafc;
}

.empty {
    padding:40px;
    text-align:center;
    color:var(--gray);
}

@media(max-width:700px) {
    .form-grid {
        grid-template-columns:1fr;
    }

    .header-inner {
        align-items:flex-start;
        flex-direction:column;
    }

    .container {
        padding:0 10px;
    }
}
</style>
</head>
<body>

<?php if ($admin): ?>
<header class="header">
<div class="header-inner">
<div class="header-title">アンケート管理</div>
<nav class="nav">
<a href="?screen=list">一覧</a>
<a href="?screen=edit">新規作成</a>
<a href="?screen=kintone">kintone</a>
<a href="?screen=mail">メール</a>
</nav>
</div>
</header>
<?php endif; ?>

<main class="container">
<div id="globalMessage"
     class="message"></div>
<?php
}

function page_end(): void
{
?>
</main>

<script>
'use strict';

function showMessage(
    message,
    type = 'success'
) {
    const el =
        document.getElementById(
            'globalMessage'
        );

    if (!el) return;

    el.className =
        'message show ' + type;

    el.textContent = message;
}

function formDataFromForm(form) {
    return new FormData(form);
}

async function postAction(
    form,
    options = {}
) {
    const buttons =
        form.querySelectorAll(
            'button'
        );

    buttons.forEach(
        b => b.disabled = true
    );

    const original =
        Array.from(buttons).map(
            b => b.innerHTML
        );

    const submit =
        form.querySelector(
            '[type="submit"]'
        );

    if (submit) {
        submit.innerHTML =
            '<span class="spinner"></span>処理中...';
    }

    try {
        const response =
            await fetch(
                window.location.href,
                {
                    method:'POST',
                    body:formDataFromForm(form),
                    credentials:'same-origin',
                    cache:'no-store',
                    headers:{
                        'X-Requested-With':
                            'XMLHttpRequest'
                    }
                }
            );

        const text =
            await response.text();

        let json;

        try {
            json = JSON.parse(text);
        } catch (e) {
            throw new Error(
                'サーバーからJSON応答を取得できませんでした。'
            );
        }

        if (!response.ok ||
            !json.ok) {
            throw new Error(
                json.message ||
                '処理に失敗しました。'
            );
        }

        showMessage(
            json.message ||
            '処理が完了しました。',
            'success'
        );

        if (
            typeof options.success ===
            'function'
        ) {
            await options.success(json);
        }

        return json;

    } catch (e) {
        showMessage(
            e.message ||
            '通信に失敗しました。',
            'error'
        );

        if (
            typeof options.error ===
            'function'
        ) {
            options.error(e);
        }

        return null;

    } finally {
        buttons.forEach(
            (b, i) => {
                b.disabled = false;

                if (
                    b === submit &&
                    original[i]
                ) {
                    b.innerHTML =
                        original[i];
                }
            }
        );
    }
}

document.addEventListener(
    'submit',
    function(event) {
        const form =
            event.target;

        if (
            form.dataset.ajax !== '1'
        ) {
            return;
        }

        event.preventDefault();

        postAction(
            form,
            {
                success: function(json) {
                    if (
                        json.data &&
                        json.data.redirect
                    ) {
                        window.location.href =
                            json.data.redirect;
                    }
                }
            }
        );
    }
);
</script>

</body>
</html>
<?php
}

/* =========================================================
 * 画面
 * =======================================================*/

function screen_list(): void
{
    page_start('アンケート一覧');

    $surveys = read_json(
        SURVEYS_FILE,
        []
    );

    foreach ($surveys as &$survey) {
        $survey =
            normalize_survey($survey);
    }
    unset($survey);
?>
<div class="toolbar">
<h1>アンケート一覧</h1>
<a class="btn btn-primary"
   href="?screen=edit">
新規作成
</a>
</div>

<div class="card">
<div class="table-wrap">

<?php if (!$surveys): ?>

<div class="empty">
アンケートはありません。
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

<?php foreach ($surveys as $survey): ?>

<?php
$status =
    (string)($survey['status'] ?? 'draft');

$statusText = [
    'draft' => '下書き',
    'published' => '公開中',
    'stopped' => '停止',
    'ended' => '終了',
][$status] ?? $status;

$answers = read_json(
    ANSWERS_FILE,
    []
);

$count = 0;

foreach ($answers as $answer) {
    if (
        ($answer['survey_id'] ?? '') ===
        ($survey['id'] ?? '')
    ) {
        $count++;
    }
}
?>

<tr>
<td><?= e($survey['title'] ?? '') ?></td>
<td><?= e($survey['createdAt'] ?? '') ?></td>
<td><?= e($survey['updatedAt'] ?? '') ?></td>
<td>
<?= e($survey['startAt'] ?? '') ?>
～
<?= e($survey['endAt'] ?? '') ?>
</td>
<td>
<span class="badge badge-<?= e($status) ?>">
<?= e($statusText) ?>
</span>
</td>
<td><?= $count ?></td>
<td>
<div class="actions">
<a class="btn"
   href="?screen=edit&id=<?= rawurlencode($survey['id']) ?>">
編集
</a>

<a class="btn"
   href="?screen=analytics&id=<?= rawurlencode($survey['id']) ?>">
集計
</a>

<a class="btn"
   href="?screen=send&id=<?= rawurlencode($survey['id']) ?>">
送信
</a>

<form method="post"
      data-ajax="1"
      style="display:inline">
<input type="hidden"
       name="action"
       value="duplicate_survey">
<input type="hidden"
       name="id"
       value="<?= e($survey['id']) ?>">
<button class="btn"
        type="submit">
複製
</button>
</form>

<form method="post"
      data-ajax="1"
      style="display:inline"
      onsubmit="return confirm('削除しますか？')">
<input type="hidden"
       name="action"
       value="delete_survey">
<input type="hidden"
       name="id"
       value="<?= e($survey['id']) ?>">
<button class="btn btn-danger"
        type="submit">
削除
</button>
</form>
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
    page_end();
}

function screen_kintone(): void
{
    $settings =
        read_json(SETTINGS_FILE, []);

    $k =
        $settings['kintone'] ?? [];

    page_start('kintone連携設定');
?>
<h1>kintone連携設定</h1>

<div class="card">

<form method="post"
      data-ajax="1">

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="form-grid">

<div class="form-group">
<label>サブドメイン</label>
<input name="subdomain"
       value="<?= e($k['subdomain'] ?? '') ?>"
       placeholder="example / example.cybozu.com">
</div>

<div class="form-group">
<label>顧客管理アプリID</label>
<input type="number"
       name="app_id"
       value="<?= e($k['app_id'] ?? '') ?>">
</div>

<div class="form-group">
<label>ログイン名</label>
<input name="username"
       autocomplete="off"
       value="<?= e($k['username'] ?? '') ?>">
</div>

<div class="form-group">
<label>パスワード</label>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</div>

<div class="form-group">
<label>Proxy</label>
<input name="proxy"
       value="<?= e($k['proxy'] ?? '') ?>"
       placeholder="host:port">
</div>

<div class="form-group">
<label>SSL証明書検証</label>
<select name="verify_ssl">
<option value="0"
<?= empty($k['verify_ssl']) ? 'selected' : '' ?>>
無効
</option>
<option value="1"
<?= !empty($k['verify_ssl']) ? 'selected' : '' ?>>
有効
</option>
</select>
</div>

<div class="form-group">
<label>組織名フィールド</label>
<input name="field_organization"
       value="<?= e($k['field_mapping']['organization'] ?? '') ?>">
</div>

<div class="form-group">
<label>氏名フィールド</label>
<input name="field_name"
       value="<?= e($k['field_mapping']['name'] ?? '') ?>">
</div>

<div class="form-group">
<label>メールアドレスフィールド</label>
<input name="field_email"
       value="<?= e($k['field_mapping']['email'] ?? '') ?>">
</div>

<div class="form-group">
<label>部署名フィールド</label>
<input name="field_department"
       value="<?= e($k['field_mapping']['department'] ?? '') ?>">
</div>

<div class="form-group">
<label>電話番号フィールド</label>
<input name="field_phone"
       value="<?= e($k['field_mapping']['phone'] ?? '') ?>">
</div>

<div class="form-group">
<label>住所フィールド</label>
<input name="field_address"
       value="<?= e(
           implode(
               ',',
               $k['field_mapping']['address'] ?? []
           )
       ) ?>">
</div>

</div>

<div class="actions"
     style="margin-top:20px">

<button class="btn btn-primary"
        type="submit">
設定保存
</button>

</form>

<form method="post"
      data-ajax="1">

<input type="hidden"
       name="action"
       value="test_kintone">

<button class="btn"
        type="submit">
接続テスト
</button>

</form>

<form method="post"
      data-ajax="1">

<input type="hidden"
       name="action"
       value="kintone_fields">

<button class="btn"
        type="submit">
項目一覧を再取得
</button>

</form>

<form method="post"
      data-ajax="1">

<input type="hidden"
       name="action"
       value="kintone_sync">

<button class="btn"
        type="submit">
顧客情報を同期
</button>

</form>

</div>

<hr>

<p>
接続状態:
<strong>
<?= e($k['connection_status'] ?? '未設定') ?>
</strong>
</p>

<?php
    page_end();
}

function screen_mail(): void
{
    $settings =
        read_json(SETTINGS_FILE, []);

    $m =
        $settings['mail'] ?? [];

    page_start('メールサーバ設定');
?>
<h1>メールサーバ設定</h1>

<div class="card">

<form method="post"
      data-ajax="1">

<input type="hidden"
       name="action"
       value="save_mail">

<div class="form-grid">

<div class="form-group">
<label>SMTPサーバ</label>
<input name="host"
       value="<?= e($m['host'] ?? '') ?>">
</div>

<div class="form-group">
<label>SMTPポート</label>
<input type="number"
       name="port"
       value="<?= e($m['port'] ?? 587) ?>">
</div>

<div class="form-group">
<label>暗号化方式</label>
<select name="encryption">
<option value="ssl"
<?= ($m['encryption'] ?? '') === 'ssl'
    ? 'selected' : '' ?>>
SSL
</option>
<option value="tls"
<?= ($m['encryption'] ?? 'tls') === 'tls'
    ? 'selected' : '' ?>>
TLS / STARTTLS
</option>
<option value="none"
<?= ($m['encryption'] ?? '') === 'none'
    ? 'selected' : '' ?>>
なし
</option>
</select>
</div>

<div class="form-group">
<label>SMTP認証</label>
<select name="auth">
<option value="1"
<?= !empty($m['auth'])
    ? 'selected' : '' ?>>
あり
</option>
<option value="0"
<?= empty($m['auth'])
    ? 'selected' : '' ?>>
なし
</option>
</select>
</div>

<div class="form-group">
<label>SMTPユーザー名</label>
<input name="username"
       autocomplete="off"
       value="<?= e($m['username'] ?? '') ?>">
</div>

<div class="form-group">
<label>SMTPパスワード</label>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</div>

<div class="form-group">
<label>送信元メールアドレス</label>
<input type="email"
       name="from_email"
       value="<?= e($m['from_email'] ?? '') ?>">
</div>

<div class="form-group">
<label>送信元名</label>
<input name="from_name"
       value="<?= e($m['from_name'] ?? '') ?>">
</div>

<div class="form-group full">
<label>返信先メールアドレス</label>
<input type="email"
       name="reply_to"
       value="<?= e($m['reply_to'] ?? '') ?>">
</div>

</div>

<div class="actions"
     style="margin-top:20px">

<button class="btn btn-primary"
        type="submit">
設定保存
</button>

</form>

<form method="post"
      data-ajax="1">

<input type="hidden"
       name="action"
       value="test_mail">

<button class="btn"
        type="submit">
接続テスト
</button>

</form>

</div>

<div class="card">

<h2>テストメール送信</h2>

<form method="post"
      data-ajax="1">

<input type="hidden"
       name="action"
       value="send_test_mail">

<div class="form-group">
<label>送信先</label>
<input type="email"
       name="to"
       required>
</div>

<div style="margin-top:12px">
<button class="btn btn-primary"
        type="submit">
テストメール送信
</button>
</div>

</form>

</div>

<p>
接続状態:
<strong>
<?= e($m['connection_status'] ?? '未設定') ?>
</strong>
</p>

<?php
    page_end();
}

function screen_edit(): void
{
    $id =
        trim((string)(
            $_GET['id'] ?? ''
        ));

    $survey =
        $id !== ''
            ? get_survey($id)
            : null;

    page_start(
        $survey
            ? 'アンケート編集'
            : 'アンケート作成'
    );
?>
<h1>
<?= $survey
    ? 'アンケート編集'
    : 'アンケート作成' ?>
</h1>

<div class="card">

<form method="post"
      data-ajax="1">

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="id"
       value="<?= e($survey['id'] ?? '') ?>">

<div class="form-grid">

<div class="form-group full">
<label>アンケートタイトル</label>
<input name="title"
       required
       value="<?= e($survey['title'] ?? '') ?>">
</div>

<div class="form-group full">
<label>アンケート説明</label>
<textarea name="description"><?= e(
    $survey['description'] ?? ''
) ?></textarea>
</div>

<div class="form-group">
<label>開始日時</label>
<input type="datetime-local"
       name="startAt"
       value="<?= e(
           $survey['startAt'] ?? ''
       ) ?>">
</div>

<div class="form-group">
<label>終了日時</label>
<input type="datetime-local"
       name="endAt"
       value="<?= e(
           $survey['endAt'] ?? ''
       ) ?>">
</div>

<div class="form-group">
<label>質問番号の採番方式</label>
<select name="numbering">
<option value="global"
<?= ($survey['numbering'] ?? 'global') === 'global'
    ? 'selected' : '' ?>>
アンケート全体で通番
</option>
<option value="group"
<?= ($survey['numbering'] ?? '') === 'group'
    ? 'selected' : '' ?>>
グループ毎に採番
</option>
</select>
</div>

<div class="form-group">
<label>状態</label>
<select name="status"
        disabled>
<option>
<?= e(
    [
        'draft'=>'下書き',
        'published'=>'公開中',
        'stopped'=>'停止',
        'ended'=>'終了',
    ][$survey['status'] ?? 'draft']
) ?>
</option>
</select>
</div>

</div>

<div style="margin-top:20px">
<button class="btn btn-primary"
        type="submit">
保存して一覧へ
</button>

<a class="btn"
   href="?screen=list">
キャンセル
</a>
</div>

</form>

</div>
<?php
    page_end();
}

function screen_send(): void
{
    $id =
        trim((string)(
            $_GET['id'] ?? ''
        ));

    $survey =
        $id !== ''
            ? get_survey($id)
            : null;

    if ($survey === null) {
        header('Location: index.php?screen=list');
        exit;
    }

    $customers =
        read_json(
            CUSTOMERS_FILE,
            []
        );

    page_start('顧客選択・メール送信');
?>
<h1>顧客選択・メール送信</h1>

<div class="card">
<p>
対象アンケート:
<strong><?= e($survey['title']) ?></strong>
</p>
</div>

<div class="card">

<form method="post"
      data-ajax="1"
      onsubmit="return confirm('選択した顧客へ送信しますか？')">

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?= e($id) ?>">

<input type="hidden"
       name="survey_base_url"
       value="<?= e(
           (isset($_SERVER['HTTPS'])
               ? 'https'
               : 'http') .
           '://' .
           ($_SERVER['HTTP_HOST'] ?? '') .
           rtrim(dirname($_SERVER['SCRIPT_NAME']), '/')
       ) ?>">

<div class="form-group">
<label>件名</label>
<input name="subject"
       required
       value="アンケートのお願い">
</div>

<div class="form-group"
     style="margin-top:12px">
<label>本文</label>
<textarea name="body"
          required> {顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}</textarea>
</div>

<div class="table-wrap"
     style="margin-top:20px">

<table>
<thead>
<tr>
<th></th>
<th>組織名</th>
<th>氏名</th>
<th>メール</th>
<th>部署</th>
</tr>
</thead>

<tbody>

<?php foreach ($customers as $customer): ?>

<tr>
<td>
<input type="checkbox"
       name="customer_ids[]"
       value="<?= e($customer['id'] ?? '') ?>">
</td>

<td><?= e($customer['organization'] ?? '') ?></td>
<td><?= e($customer['name'] ?? '') ?></td>
<td><?= e($customer['email'] ?? '') ?></td>
<td><?= e($customer['department'] ?? '') ?></td>
</tr>

<?php endforeach; ?>

</tbody>
</table>

</div>

<div style="margin-top:20px">
<button class="btn btn-primary"
        type="submit">
一括送信
</button>
</div>

</form>

</div>

<?php
    page_end();
}

function screen_analytics(): void
{
    $id =
        trim((string)(
            $_GET['id'] ?? ''
        ));

    $survey =
        $id !== ''
            ? get_survey($id)
            : null;

    if ($survey === null) {
        header('Location: index.php?screen=list');
        exit;
    }

    $answers =
        read_json(
            ANSWERS_FILE,
            []
        );

    $count = 0;

    foreach ($answers as $answer) {
        if (
            ($answer['survey_id'] ?? '') ===
            $id
        ) {
            $count++;
        }
    }

    $customers =
        read_json(
            CUSTOMERS_FILE,
            []
        );

    page_start('回答集計・分析');
?>
<h1>回答集計・分析</h1>

<div class="card">
<h2><?= e($survey['title']) ?></h2>

<div class="form-grid">

<div>
<strong>送信対象者数</strong>
<div><?= count($customers) ?></div>
</div>

<div>
<strong>回答数</strong>
<div><?= $count ?></div>
</div>

<div>
<strong>未回答数</strong>
<div>
<?= max(
    0,
    count($customers) - $count
) ?>
</div>
</div>

<div>
<strong>回答率</strong>
<div>
<?= count($customers) > 0
    ? e(
        number_format(
            ($count / count($customers)) * 100,
            1
        )
      ) . '%'
    : '0%' ?>
</div>
</div>

</div>
</div>

<div class="card">

<h2>個別回答</h2>

<?php if ($count === 0): ?>

<div class="empty">
現在、回答データはありません
</div>

<?php else: ?>

<pre><?= e(
    json_encode(
        array_values(
            array_filter(
                $answers,
                static fn($a) =>
                    ($a['survey_id'] ?? '') === $id
            )
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_PRETTY_PRINT
    )
) ?></pre>

<?php endif; ?>

</div>

<?php
    page_end();
}

function screen_answer(): void
{
    $id =
        trim((string)(
            $_GET['id'] ?? ''
        ));

    $survey =
        $id !== ''
            ? get_survey($id)
            : null;

    if ($survey === null) {
        http_response_code(404);
        page_start(
            'アンケート',
            false
        );
        echo '<div class="card"><h1>アンケートが見つかりません。</h1></div>';
        page_end();
        return;
    }

    page_start(
        'アンケート回答',
        false
    );
?>
<div class="card">
<h1><?= e($survey['title']) ?></h1>

<p>
<?= nl2br(
    e($survey['description'] ?? '')
) ?>
</p>

<form method="post"
      data-ajax="1">

<input type="hidden"
       name="action"
       value="save_answer">

<input type="hidden"
       name="survey_id"
       value="<?= e($id) ?>">

<div class="form-group">
<label>回答</label>
<textarea name="answers"
          required
          placeholder="回答内容"></textarea>
</div>

<div style="margin-top:20px">
<button class="btn btn-primary"
        type="submit">
回答を送信
</button>
</div>

</form>
</div>

<?php
    page_end();
}

function screen_preview(): void
{
    $id =
        trim((string)(
            $_GET['id'] ?? ''
        ));

    $survey =
        $id !== ''
            ? get_survey($id)
            : null;

    if ($survey === null) {
        header('Location: index.php?screen=list');
        exit;
    }

    page_start('プレビュー');
?>
<h1>プレビュー</h1>

<div class="card">
<h2><?= e($survey['title']) ?></h2>

<p>
<?= nl2br(
    e($survey['description'] ?? '')
) ?>
</p>

<?php
$groups =
    is_array($survey['groups'] ?? null)
        ? $survey['groups']
        : [];
?>

<?php foreach ($groups as $gi => $group): ?>

<div class="group">

<h3>
<?= e(
    $group['title'] ??
    'グループ ' . ($gi + 1)
) ?>
</h3>

<?php
$questions =
    is_array($group['questions'] ?? null)
        ? $group['questions']
        : [];
?>

<?php foreach (
    $questions
    as $qi => $question
): ?>

<div class="question">

<strong>
<?= e(
    $question['number'] ??
    'Q' . ($qi + 1)
) ?>
</strong>

<p>
<?= e(
    $question['text'] ??
    $question['question'] ??
    ''
) ?>
</p>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

</div>
<?php
    page_end();
}

/* =========================================================
 * 起動
 * =======================================================*/

ensure_storage();
start_app_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_action();
}

$screen = get_screen();

switch ($screen) {

    case 'kintone':
        screen_kintone();
        break;

    case 'mail':
        screen_mail();
        break;

    case 'edit':
        screen_edit();
        break;

    case 'preview':
        screen_preview();
        break;

    case 'send':
        screen_send();
        break;

    case 'analytics':
        screen_analytics();
        break;

    case 'answer':
        screen_answer();
        break;

    case 'confirm':
        screen_answer();
        break;

    case 'complete':
        page_start(
            '回答完了',
            false
        );

        echo
            '<div class="card">' .
            '<h1>回答完了</h1>' .
            '<p>回答を受け付けました。</p>' .
            '</div>';

        page_end();
        break;

    case 'list':
    default:
        screen_list();
        break;
}
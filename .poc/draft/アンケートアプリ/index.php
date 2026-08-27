<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * prompt.txt 再生成版
 *
 * 前提:
 *   Apache 2.4
 *   PHP 8.5
 *   DBなし
 *   PHP cURLなし
 *   PHP mail()なし
 *
 * 外部通信:
 *   kintone : TCP socket + TLS / HTTP
 *   SMTP    : TCP socket + TLS / STARTTLS
 *
 * 管理者認証:
 *   POCのためなし
 *
 * CSRF:
 *   prompt.txt の禁止事項に従い実装しない
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

/* ============================================================
 * 初期化
 * ============================================================ */

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0770, true);
}

ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');

if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}

session_start();

function default_settings(): array
{
    return [
        'kintone' => [
            'subdomain' => '',
            'app_id'    => '',
            'username'  => '',
            'password'  => '',
            'proxy'     => '',
            'verify_ssl'=> false,
            'fields'    => [
                'organization' => '',
                'name'         => '',
                'email'        => '',
                'department'   => '',
                'phone'        => '',
                'address'      => [],
            ],
        ],
        'mail' => [
            'host'       => '',
            'port'       => 587,
            'encryption' => 'tls',
            'username'   => '',
            'password'   => '',
            'from_email' => '',
            'from_name'  => '',
            'reply_to'   => '',
        ],
    ];
}

function ensure_data_files(): void
{
    $defaults = [
        SETTINGS_FILE  => default_settings(),
        SURVEYS_FILE   => [],
        CUSTOMERS_FILE => [],
        ANSWERS_FILE   => [],
        SEND_LOG_FILE  => [],
    ];

    foreach ($defaults as $file => $value) {
        if (!is_file($file)) {
            write_json_atomic($file, $value);
        }
    }
}

ensure_data_files();

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

function read_json(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $raw = file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function write_json_atomic(string $file, array $data): void
{
    $dir = dirname($file);

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('データ保存先を作成できません。');
        }
    }

    $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('データのJSON化に失敗しました。');
    }

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('一時ファイルへ保存できません。');
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データファイルを更新できません。');
    }
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function screen_url(string $screen, ?string $id = null): string
{
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

    $url = 'index.php?screen=' . rawurlencode($screen);

    if ($id !== null && $id !== '') {
        $url .= '&id=' . rawurlencode($id);
    }

    return $url;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_flash(): ?array
{
    $value = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);

    return is_array($value) ? $value : null;
}

function public_error_message(Throwable $e): string
{
    if ($e instanceof InvalidArgumentException) {
        return $e->getMessage();
    }

    if ($e instanceof RuntimeException) {
        return $e->getMessage();
    }

    return 'システムエラーが発生しました。';
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
    ) ?? $value;

    $value = trim($value, "/ \t\r\n");

    if (str_contains($value, '/')) {
        throw new InvalidArgumentException(
            'kintoneサブドメインの形式が不正です。'
        );
    }

    if (str_ends_with(
        strtolower($value),
        '.cybozu.com'
    )) {
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

function validate_kintone(array $k): void
{
    normalize_kintone_subdomain(
        (string)($k['subdomain'] ?? '')
    );

    $appId = trim((string)($k['app_id'] ?? ''));

    if ($appId === '' || !ctype_digit($appId)) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    if ((string)($k['username'] ?? '') === '') {
        throw new InvalidArgumentException(
            'ログイン名を入力してください。'
        );
    }

    if ((string)($k['password'] ?? '') === '') {
        throw new InvalidArgumentException(
            'パスワードを入力してください。'
        );
    }

    $proxy = trim((string)($k['proxy'] ?? ''));

    if (
        $proxy !== '' &&
        !preg_match(
            '/^[^:\s\/]+:\d{1,5}$/',
            $proxy
        )
    ) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で入力してください。'
        );
    }
}

/**
 * Proxyをhost:portに分解する。
 */
function parse_host_port(string $value): array
{
    $value = trim($value);

    $pos = strrpos($value, ':');

    if ($pos === false) {
        throw new InvalidArgumentException(
            '接続先の形式が不正です。'
        );
    }

    $host = substr($value, 0, $pos);
    $port = (int)substr($value, $pos + 1);

    if (
        $host === '' ||
        $port < 1 ||
        $port > 65535
    ) {
        throw new InvalidArgumentException(
            '接続先のhost:portが不正です。'
        );
    }

    return [$host, $port];
}

/**
 * HTTPヘッダーを配列化。
 */
function parse_http_headers(string $raw): array
{
    $headers = [];

    foreach (preg_split("/\r\n|\n|\r/", $raw) as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }

        [$name, $value] = explode(':', $line, 2);

        $headers[strtolower(trim($name))] = trim($value);
    }

    return $headers;
}

/**
 * chunked transfer decoding
 */
function decode_chunked_body(string $body): string
{
    $result = '';
    $offset = 0;
    $length = strlen($body);

    while ($offset < $length) {
        $lineEnd = strpos($body, "\r\n", $offset);

        if ($lineEnd === false) {
            break;
        }

        $sizeText = trim(
            substr(
                $body,
                $offset,
                $lineEnd - $offset
            )
        );

        $size = hexdec(
            explode(';', $sizeText, 2)[0]
        );

        $offset = $lineEnd + 2;

        if ($size === 0) {
            break;
        }

        $result .= substr(
            $body,
            $offset,
            $size
        );

        $offset += $size + 2;
    }

    return $result;
}

/**
 * Proxy CONNECT。
 */
function proxy_connect(
    $socket,
    string $targetHost,
    int $targetPort
): void {
    $request =
        "CONNECT {$targetHost}:{$targetPort} HTTP/1.1\r\n" .
        "Host: {$targetHost}:{$targetPort}\r\n" .
        "Connection: Keep-Alive\r\n" .
        "\r\n";

    fwrite($socket, $request);

    $headers = '';

    while (!feof($socket)) {
        $line = fgets($socket);

        if ($line === false) {
            break;
        }

        $headers .= $line;

        if (rtrim($line, "\r\n") === '') {
            break;
        }
    }

    if (
        !preg_match(
            '#^HTTP/\d(?:\.\d)?\s+200\b#i',
            $headers
        )
    ) {
        $firstLine = strtok($headers, "\r\n");

        throw new RuntimeException(
            'Proxy CONNECTに失敗しました。' .
            ($firstLine ? ' ' . $firstLine : '')
        );
    }
}

/**
 * TLSを明示的に開始する。
 *
 * ここが従来版との重要な差。
 *
 * - TLS開始を明示
 * - OpenSSLエラーを取得
 * - verify_ssl=trueなら証明書検証
 * - verify_ssl=falseなら証明書検証を停止
 * - Proxy CONNECTにも対応
 */
function open_kintone_socket(array $k): array
{
    $host = normalize_kintone_subdomain(
        (string)$k['subdomain']
    ) . '.cybozu.com';

    $proxy = trim((string)($k['proxy'] ?? ''));

    $remoteHost = $proxy !== ''
        ? parse_host_port($proxy)[0]
        : $host;

    $remotePort = $proxy !== ''
        ? parse_host_port($proxy)[1]
        : 443;

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        'tcp://' . $remoteHost . ':' . $remotePort,
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'kintoneへのTCP接続に失敗しました。' .
            ' host=' . $remoteHost .
            ' port=' . $remotePort .
            ' errno=' . $errno .
            ' error=' . $errstr
        );
    }

    stream_set_timeout(
        $socket,
        READ_TIMEOUT
    );

    if ($proxy !== '') {
        proxy_connect(
            $socket,
            $host,
            443
        );
    }

    $verify = (bool)($k['verify_ssl'] ?? false);

    $sslOptions = [
        'capture_peer_cert' => false,
        'verify_peer'       => $verify,
        'verify_peer_name'  => $verify,
        'allow_self_signed' => !$verify,
        'SNI_enabled'       => true,
        'peer_name'         => $host,
        'disable_compression' => false,
    ];

    $context = stream_context_create([
        'ssl' => $sslOptions,
    ]);

    stream_context_set_option(
        $socket,
        'ssl',
        $sslOptions
    );

    $cryptoResult = @stream_socket_enable_crypto(
        $socket,
        true,
        STREAM_CRYPTO_METHOD_TLS_CLIENT
    );

    if ($cryptoResult !== true) {
        $opensslErrors = [];

        while (
            ($error = openssl_error_string()) !== false
        ) {
            $opensslErrors[] = $error;
        }

        fclose($socket);

        $detail = $opensslErrors
            ? implode(' / ', $opensslErrors)
            : 'OpenSSLから詳細なエラーが返されませんでした。';

        throw new RuntimeException(
            'kintoneへのTLS接続を確立できません。' .
            ' host=' . $host .
            ' verify_ssl=' . ($verify ? 'true' : 'false') .
            ' ' . $detail
        );
    }

    return [
        'socket' => $socket,
        'host'   => $host,
    ];
}

/**
 * kintone HTTPリクエスト。
 *
 * PHP cURLは使用しない。
 */
function kintone_request(
    array $k,
    string $path,
    string $method = 'GET',
    ?array $body = null
): array {
    validate_kintone($k);

    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }

    $connection = open_kintone_socket($k);

    $socket = $connection['socket'];
    $host   = $connection['host'];

    $auth = base64_encode(
        (string)$k['username'] .
        ':' .
        (string)$k['password']
    );

    $jsonBody = '';

    if ($body !== null) {
        $jsonBody = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($jsonBody === false) {
            fclose($socket);

            throw new RuntimeException(
                'kintoneリクエスト本文の生成に失敗しました。'
            );
        }
    }

    $request =
        $method . ' ' . $path . " HTTP/1.1\r\n" .
        'Host: ' . $host . "\r\n" .
        "X-Cybozu-Authorization: {$auth}\r\n" .
        "Accept: application/json\r\n" .
        "Connection: close\r\n";

    if ($jsonBody !== '') {
        $request .=
            "Content-Type: application/json\r\n" .
            "Content-Length: " .
            strlen($jsonBody) .
            "\r\n";
    } else {
        $request .=
            "Content-Length: 0\r\n";
    }

    $request .= "\r\n";

    if ($jsonBody !== '') {
        $request .= $jsonBody;
    }

    $written = fwrite(
        $socket,
        $request
    );

    if ($written === false) {
        fclose($socket);

        throw new RuntimeException(
            'kintoneへのHTTPリクエスト送信に失敗しました。'
        );
    }

    $response = '';

    while (!feof($socket)) {
        $chunk = fread($socket, 8192);

        if ($chunk === false) {
            break;
        }

        $response .= $chunk;

        $meta = stream_get_meta_data($socket);

        if (!empty($meta['timed_out'])) {
            break;
        }
    }

    fclose($socket);

    if ($response === '') {
        throw new RuntimeException(
            'kintoneから応答を受信できませんでした。'
        );
    }

    $parts = preg_split(
        "/\r\n\r\n/",
        $response,
        2
    );

    if (count($parts) !== 2) {
        throw new RuntimeException(
            'kintoneのHTTP応答を解析できません。'
        );
    }

    [$headerText, $bodyText] = $parts;

    if (
        !preg_match(
            '#^HTTP/\d(?:\.\d)?\s+(\d{3})#m',
            $headerText,
            $match
        )
    ) {
        throw new RuntimeException(
            'kintoneのHTTPステータスを取得できません。'
        );
    }

    $status = (int)$match[1];

    $headers = parse_http_headers(
        $headerText
    );

    if (
        isset($headers['transfer-encoding']) &&
        stripos(
            $headers['transfer-encoding'],
            'chunked'
        ) !== false
    ) {
        $bodyText = decode_chunked_body(
            $bodyText
        );
    }

    if ($status < 200 || $status >= 300) {
        $detail = '';

        $decoded = json_decode(
            $bodyText,
            true
        );

        if (is_array($decoded)) {
            $message = trim(
                (string)(
                    $decoded['message'] ?? ''
                )
            );

            $id = trim(
                (string)(
                    $decoded['id'] ?? ''
                )
            );

            if ($message !== '') {
                $detail = $message;

                if ($id !== '') {
                    $detail .=
                        ' (id=' . $id . ')';
                }
            }
        }

        if ($detail === '') {
            $detail = trim(
                preg_replace(
                    '/\s+/',
                    ' ',
                    $bodyText
                ) ?? ''
            );
        }

        throw new RuntimeException(
            'kintone HTTP ' .
            $status .
            ($detail !== ''
                ? ': ' . $detail
                : '.')
        );
    }

    $decoded = json_decode(
        $bodyText,
        true
    );

    return [
        'status'  => $status,
        'headers' => $headers,
        'body'    => $bodyText,
        'data'    => is_array($decoded)
            ? $decoded
            : [],
    ];
}

/* ============================================================
 * kintone操作
 * ============================================================ */

function save_kintone(): void
{
    $settings = read_json(
        SETTINGS_FILE
    );

    $k = $settings['kintone']
        ?? default_settings()['kintone'];

    $k['subdomain'] =
        normalize_kintone_subdomain(
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

    if (
        $k['app_id'] === '' ||
        !ctype_digit($k['app_id'])
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    $k['username'] =
        trim(
            (string)(
                $_POST['username'] ?? ''
            )
        );

    if (isset($_POST['password']) &&
        (string)$_POST['password'] !== '') {
        $k['password'] =
            (string)$_POST['password'];
    }

    if ($k['password'] === '') {
        throw new InvalidArgumentException(
            'パスワードを入力してください。'
        );
    }

    $k['proxy'] =
        trim(
            (string)(
                $_POST['proxy'] ?? ''
            )
        );

    if ($k['proxy'] !== '' &&
        !preg_match(
            '/^[^:\s\/]+:\d{1,5}$/',
            $k['proxy']
        )) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で入力してください。'
        );
    }

    $k['verify_ssl'] =
        isset($_POST['verify_ssl']);

    $settings['kintone'] = $k;

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

function test_kintone(): void
{
    $settings = read_json(
        SETTINGS_FILE
    );

    $k = $settings['kintone']
        ?? [];

    validate_kintone($k);

    try {
        $result = kintone_request(
            $k,
            '/k/v1/app.json?id=' .
            rawurlencode(
                (string)$k['app_id']
            ),
            'GET'
        );

        $name =
            (string)(
                $result['data']['name'] ?? ''
            );

        flash(
            'success',
            'kintone接続成功' .
            ($name !== ''
                ? '：' . $name
                : '。')
        );
    } catch (Throwable $e) {
        flash(
            'error',
            'kintone接続エラー。 ' .
            public_error_message($e)
        );
    }

    redirect(
        screen_url('kintone')
    );
}

function fetch_kintone_fields(): void
{
    $settings = read_json(
        SETTINGS_FILE
    );

    $k = $settings['kintone']
        ?? [];

    validate_kintone($k);

    $result = kintone_request(
        $k,
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode(
            (string)$k['app_id']
        ),
        'GET'
    );

    $properties =
        $result['data']['properties'] ?? [];

    if (!is_array($properties)) {
        throw new RuntimeException(
            'kintoneの項目一覧を解析できません。'
        );
    }

    $settings['kintone']['field_catalog'] =
        $properties;

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

function sync_kintone(): void
{
    $settings = read_json(
        SETTINGS_FILE
    );

    $k = $settings['kintone']
        ?? [];

    validate_kintone($k);

    $query =
        '/k/v1/records.json?' .
        'app=' .
        rawurlencode(
            (string)$k['app_id']
        ) .
        '&totalCount=true';

    $result = kintone_request(
        $k,
        $query,
        'GET'
    );

    $records =
        $result['data']['records'] ?? [];

    if (!is_array($records)) {
        throw new RuntimeException(
            'kintoneの顧客データ形式が不正です。'
        );
    }

    $fields =
        $k['fields'] ?? [];

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $customers[] = [
            'id' => uuid(),
            'organization' =>
                kintone_field_value(
                    $record,
                    (string)(
                        $fields['organization'] ?? ''
                    )
                ),
            'name' =>
                kintone_field_value(
                    $record,
                    (string)(
                        $fields['name'] ?? ''
                    )
                ),
            'email' =>
                kintone_field_value(
                    $record,
                    (string)(
                        $fields['email'] ?? ''
                    )
                ),
            'department' =>
                kintone_field_value(
                    $record,
                    (string)(
                        $fields['department'] ?? ''
                    )
                ),
            'phone' =>
                kintone_field_value(
                    $record,
                    (string)(
                        $fields['phone'] ?? ''
                    )
                ),
            'address' =>
                kintone_field_value_multi(
                    $record,
                    (array)(
                        $fields['address'] ?? []
                    )
                ),
            'syncedAt' => now_iso(),
        ];
    }

    write_json_atomic(
        CUSTOMERS_FILE,
        $customers
    );

    flash(
        'success',
        count($customers) .
        '件の顧客情報を同期しました。'
    );

    redirect(
        screen_url('kintone')
    );
}

function kintone_field_value(
    array $record,
    string $code
): string {
    if ($code === '') {
        return '';
    }

    $field = $record[$code] ?? null;

    if (!is_array($field)) {
        return '';
    }

    return trim(
        (string)($field['value'] ?? '')
    );
}

function kintone_field_value_multi(
    array $record,
    array $codes
): string {
    $values = [];

    foreach ($codes as $code) {
        $code = trim((string)$code);

        if ($code === '') {
            continue;
        }

        $value =
            kintone_field_value(
                $record,
                $code
            );

        if ($value !== '') {
            $values[] = $value;
        }
    }

    return implode(
        ' ',
        array_unique($values)
    );
}

/* ============================================================
 * SMTP
 * ============================================================ */

function validate_mail(array $m): void
{
    $host = trim(
        (string)($m['host'] ?? '')
    );

    if ($host === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを入力してください。'
        );
    }

    $port = (int)($m['port'] ?? 0);

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'SMTPポートは1～65535で指定してください。'
        );
    }

    $encryption =
        (string)($m['encryption'] ?? '');

    if (!in_array(
        $encryption,
        ['ssl', 'tls', 'none'],
        true
    )) {
        throw new InvalidArgumentException(
            'SMTP暗号化方式が不正です。'
        );
    }

    if (
        trim(
            (string)(
                $m['from_email'] ?? ''
            )
        ) !== '' &&
        !filter_var(
            $m['from_email'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }
}

function smtp_read($socket): string
{
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (
            preg_match(
                '/^\d{3} /',
                $line
            )
        ) {
            break;
        }
    }

    return trim($response);
}

function smtp_expect(
    $socket,
    array $codes
): string {
    $response = smtp_read($socket);

    if (
        !preg_match(
            '/^(\d{3})/',
            $response,
            $match
        )
    ) {
        throw new RuntimeException(
            'SMTP応答を解析できません。'
        );
    }

    $code = (int)$match[1];

    if (!in_array(
        $code,
        $codes,
        true
    )) {
        throw new RuntimeException(
            'SMTP応答エラー。 ' .
            $response
        );
    }

    return $response;
}

function smtp_command(
    $socket,
    string $command,
    array $codes
): string {
    fwrite(
        $socket,
        $command . "\r\n"
    );

    return smtp_expect(
        $socket,
        $codes
    );
}

function smtp_open(array $m)
{
    validate_mail($m);

    $host =
        trim(
            (string)$m['host']
        );

    $port =
        (int)$m['port'];

    $encryption =
        (string)$m['encryption'];

    $transport =
        $encryption === 'ssl'
            ? 'ssl'
            : 'tcp';

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $transport .
        '://' .
        $host .
        ':' .
        $port,
        $errno,
        $errstr,
        CONNECT_TIMEOUT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPサーバへの接続に失敗しました。' .
            ' host=' . $host .
            ' port=' . $port .
            ' errno=' . $errno .
            ' error=' . $errstr
        );
    }

    stream_set_timeout(
        $socket,
        READ_TIMEOUT
    );

    smtp_expect(
        $socket,
        [220]
    );

    smtp_command(
        $socket,
        'EHLO ' .
        ($_SERVER['SERVER_NAME']
            ?? 'localhost'),
        [250]
    );

    if ($encryption === 'tls') {
        smtp_command(
            $socket,
            'STARTTLS',
            [220]
        );

        $crypto = @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            $errors = [];

            while (
                ($error = openssl_error_string())
                !== false
            ) {
                $errors[] = $error;
            }

            fclose($socket);

            throw new RuntimeException(
                'SMTP TLS接続を確立できません。' .
                (
                    $errors
                        ? ' ' . implode(
                            ' / ',
                            $errors
                        )
                        : ''
                )
            );
        }

        smtp_command(
            $socket,
            'EHLO ' .
            ($_SERVER['SERVER_NAME']
                ?? 'localhost'),
            [250]
        );
    }

    $username =
        (string)($m['username'] ?? '');

    $password =
        (string)($m['password'] ?? '');

    if (
        $username !== '' ||
        $password !== ''
    ) {
        smtp_command(
            $socket,
            'AUTH LOGIN',
            [334]
        );

        smtp_command(
            $socket,
            base64_encode($username),
            [334]
        );

        smtp_command(
            $socket,
            base64_encode($password),
            [235]
        );
    }

    return $socket;
}

function smtp_close($socket): void
{
    try {
        @fwrite(
            $socket,
            "QUIT\r\n"
        );
    } catch (Throwable) {
    }

    @fclose($socket);
}

function save_mail(): void
{
    $settings = read_json(
        SETTINGS_FILE
    );

    $m =
        $settings['mail']
        ?? default_settings()['mail'];

    $m['host'] =
        trim(
            (string)(
                $_POST['host'] ?? ''
            )
        );

    $m['port'] =
        (int)(
            $_POST['port'] ?? 0
        );

    $m['encryption'] =
        (string)(
            $_POST['encryption'] ?? 'tls'
        );

    $m['username'] =
        trim(
            (string)(
                $_POST['username'] ?? ''
            )
        );

    if (
        isset($_POST['password']) &&
        (string)$_POST['password'] !== ''
    ) {
        $m['password'] =
            (string)$_POST['password'];
    }

    $m['from_email'] =
        trim(
            (string)(
                $_POST['from_email'] ?? ''
            )
        );

    $m['from_name'] =
        trim(
            (string)(
                $_POST['from_name'] ?? ''
            )
        );

    $m['reply_to'] =
        trim(
            (string)(
                $_POST['reply_to'] ?? ''
            )
        );

    validate_mail($m);

    $settings['mail'] = $m;

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

function test_mail(): void
{
    $settings = read_json(
        SETTINGS_FILE
    );

    $m =
        $settings['mail']
        ?? [];

    $socket = smtp_open($m);

    smtp_close($socket);

    flash(
        'success',
        'SMTPサーバーへの接続を確認しました。'
    );

    redirect(
        screen_url('mail')
    );
}

function send_test_mail(): void
{
    $to =
        trim(
            (string)(
                $_POST['test_to'] ?? ''
            )
        );

    if (
        !filter_var(
            $to,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            'テスト送信先メールアドレスが不正です。'
        );
    }

    $settings = read_json(
        SETTINGS_FILE
    );

    $m =
        $settings['mail']
        ?? [];

    $socket = smtp_open($m);

    try {
        smtp_command(
            $socket,
            'MAIL FROM:<' .
            $m['from_email'] .
            '>',
            [250]
        );

        smtp_command(
            $socket,
            'RCPT TO:<' .
            $to .
            '>',
            [250, 251]
        );

        smtp_command(
            $socket,
            'DATA',
            [354]
        );

        $fromName =
            (string)(
                $m['from_name'] ?? ''
            );

        $subject =
            'アンケートアプリ テストメール';

        $headers =
            'From: ' .
            ($fromName !== ''
                ? '=?UTF-8?B?' .
                  base64_encode($fromName) .
                  '?= '
                : '') .
            '<' .
            $m['from_email'] .
            ">\r\n" .
            'To: <' .
            $to .
            ">\r\n" .
            'Subject: =?UTF-8?B?' .
            base64_encode($subject) .
            "?=\r\n" .
            "MIME-Version: 1.0\r\n" .
            "Content-Type: text/plain; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n";

        $message =
            $headers .
            "\r\n" .
            "これはテストメールです。\r\n" .
            "アンケートアプリから送信されました。\r\n" .
            ".\r\n";

        fwrite(
            $socket,
            $message
        );

        smtp_expect(
            $socket,
            [250]
        );
    } finally {
        smtp_close($socket);
    }

    flash(
        'success',
        'テストメールを送信しました。'
    );

    redirect(
        screen_url('mail')
    );
}

/* ============================================================
 * アンケート
 * ============================================================ */

function find_survey(string $id): ?array
{
    foreach (
        read_json(SURVEYS_FILE)
        as $survey
    ) {
        if (
            (string)($survey['id'] ?? '') ===
            $id
        ) {
            return $survey;
        }
    }

    return null;
}

function normalize_survey_status(
    array $survey
): array {
    if (
        ($survey['status'] ?? '') ===
        'published' &&
        !empty($survey['endAt'])
    ) {
        $time = strtotime(
            (string)$survey['endAt']
        );

        if (
            $time !== false &&
            $time < time()
        ) {
            $survey['status'] = 'ended';
        }
    }

    return $survey;
}

function save_survey(): void
{
    $surveys =
        read_json(SURVEYS_FILE);

    $id =
        trim(
            (string)(
                $_POST['id'] ?? ''
            )
        );

    $title =
        trim(
            (string)(
                $_POST['title'] ?? ''
            )
        );

    if ($title === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルは必須です。'
        );
    }

    if (mb_strlen($title) > 200) {
        throw new InvalidArgumentException(
            'アンケートタイトルは200文字以内です。'
        );
    }

    $numbering =
        (string)(
            $_POST['numbering'] ?? 'global'
        );

    if (!in_array(
        $numbering,
        ['global', 'group'],
        true
    )) {
        throw new InvalidArgumentException(
            '質問番号方式が不正です。'
        );
    }

    $startAt =
        trim(
            (string)(
                $_POST['startAt'] ?? ''
            )
        );

    $endAt =
        trim(
            (string)(
                $_POST['endAt'] ?? ''
            )
        );

    if (
        $startAt !== '' &&
        strtotime($startAt) === false
    ) {
        throw new InvalidArgumentException(
            '開始日時が不正です。'
        );
    }

    if (
        $endAt !== '' &&
        strtotime($endAt) === false
    ) {
        throw new InvalidArgumentException(
            '終了日時が不正です。'
        );
    }

    if (
        $startAt !== '' &&
        $endAt !== '' &&
        strtotime($startAt) >
        strtotime($endAt)
    ) {
        throw new InvalidArgumentException(
            '終了日時は開始日時以降にしてください。'
        );
    }

    $description =
        trim(
            (string)(
                $_POST['description'] ?? ''
            )
        );

    $found = false;

    foreach ($surveys as &$survey) {
        if (
            (string)($survey['id'] ?? '') !==
            $id
        ) {
            continue;
        }

        $survey['title'] =
            $title;

        $survey['description'] =
            $description;

        $survey['startAt'] =
            $startAt;

        $survey['endAt'] =
            $endAt;

        $survey['numbering'] =
            $numbering;

        $survey['updatedAt'] =
            now_iso();

        $found = true;
        break;
    }

    unset($survey);

    if (!$found) {
        $surveys[] = [
            'id' =>
                uuid(),
            'title' =>
                $title,
            'description' =>
                $description,
            'startAt' =>
                $startAt,
            'endAt' =>
                $endAt,
            'status' =>
                'draft',
            'numbering' =>
                $numbering,
            'groups' =>
                [],
            'createdAt' =>
                now_iso(),
            'updatedAt' =>
                now_iso(),
        ];
    }

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    redirect(
        screen_url('list')
    );
}

function delete_survey(): void
{
    $id =
        trim(
            (string)(
                $_POST['id'] ?? ''
            )
        );

    $surveys =
        read_json(SURVEYS_FILE);

    $new = [];
    $deleted = false;

    foreach ($surveys as $survey) {
        if (
            (string)($survey['id'] ?? '') ===
            $id
        ) {
            $deleted = true;
            continue;
        }

        $new[] = $survey;
    }

    if (!$deleted) {
        throw new RuntimeException(
            '指定されたアンケートが存在しません。'
        );
    }

    write_json_atomic(
        SURVEYS_FILE,
        $new
    );

    redirect(
        screen_url('list')
    );
}

function duplicate_survey(): void
{
    $id =
        trim(
            (string)(
                $_POST['id'] ?? ''
            )
        );

    $survey =
        find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            '複製対象のアンケートが存在しません。'
        );
    }

    $surveys =
        read_json(SURVEYS_FILE);

    $copy = $survey;

    $copy['id'] =
        uuid();

    $copy['title'] =
        (string)(
            $copy['title'] ?? ''
        ) .
        '（コピー）';

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

function change_status(): void
{
    $id =
        trim(
            (string)(
                $_POST['id'] ?? ''
            )
        );

    $status =
        (string)(
            $_POST['status'] ?? ''
        );

    if (!in_array(
        $status,
        [
            'draft',
            'published',
            'stopped',
        ],
        true
    )) {
        throw new InvalidArgumentException(
            '指定された状態へ変更できません。'
        );
    }

    $surveys =
        read_json(SURVEYS_FILE);

    $found = false;

    foreach ($surveys as &$survey) {
        if (
            (string)($survey['id'] ?? '') !==
            $id
        ) {
            continue;
        }

        $survey =
            normalize_survey_status(
                $survey
            );

        if (
            ($survey['status'] ?? '') ===
            'ended'
        ) {
            throw new InvalidArgumentException(
                '終了状態から変更できません。'
            );
        }

        $survey['status'] =
            $status;

        $survey['updatedAt'] =
            now_iso();

        $found = true;

        break;
    }

    unset($survey);

    if (!$found) {
        throw new RuntimeException(
            '指定されたアンケートが存在しません。'
        );
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
 * POSTルーティング
 * ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action =
        (string)(
            $_POST['action'] ?? ''
        );

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

            default:
                throw new InvalidArgumentException(
                    '不正な操作です。'
                );
        }
    } catch (Throwable $e) {
        $screen =
            (string)(
                $_GET['screen']
                ?? 'list'
            );

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

        if (!in_array(
            $screen,
            $allowed,
            true
        )) {
            $screen = 'list';
        }

        flash(
            'error',
            public_error_message($e)
        );

        redirect(
            screen_url($screen)
        );
    }
}

/* ============================================================
 * 画面
 * ============================================================ */

$screen =
    (string)(
        $_GET['screen'] ?? 'list'
    );

$id =
    trim(
        (string)(
            $_GET['id'] ?? ''
        )
    );

$survey =
    $id !== ''
        ? find_survey($id)
        : null;

if ($survey !== null) {
    $survey =
        normalize_survey_status(
            $survey
        );
}

$flash =
    consume_flash();

function render_header(
    string $title
): void {
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?=e($title)?> - アンケートアプリ</title>

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

*{box-sizing:border-box}

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

header{
 background:#0f172a;
 color:#fff;
 padding:16px 24px;
}

header .inner{
 max-width:1400px;
 margin:auto;
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:20px;
}

header a{
 color:#fff;
 text-decoration:none;
 margin-right:16px;
}

main{
 max-width:1400px;
 margin:24px auto;
 padding:0 20px;
}

.card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:12px;
 box-shadow:var(--shadow);
 padding:20px;
 margin-bottom:20px;
}

.form-row{
 display:grid;
 grid-template-columns:220px 1fr;
 gap:16px;
 align-items:center;
 margin-bottom:16px;
}

label{
 font-weight:600;
}

input,
select,
textarea{
 width:100%;
 padding:10px 12px;
 border:1px solid var(--border);
 border-radius:8px;
 font:inherit;
 background:#fff;
}

textarea{
 min-height:120px;
 resize:vertical;
}

button{
 border:1px solid var(--border);
 background:#fff;
 color:var(--text);
 border-radius:8px;
 padding:9px 15px;
 cursor:pointer;
 font:inherit;
}

button:hover{
 background:#f8fafc;
}

button.primary{
 background:var(--primary);
 color:#fff;
 border-color:var(--primary);
}

button.primary:hover{
 background:var(--primary-dark);
}

button.danger{
 color:#fff;
 background:var(--danger);
 border-color:var(--danger);
}

.flash{
 padding:14px 16px;
 border-radius:8px;
 margin-bottom:20px;
}

.flash.success{
 background:#dcfce7;
 color:#166534;
}

.flash.error{
 background:#fee2e2;
 color:#991b1b;
}

.table-wrap{
 overflow-x:auto;
}

table{
 width:100%;
 border-collapse:collapse;
 background:#fff;
}

th,
td{
 padding:12px;
 border-bottom:1px solid var(--border);
 text-align:left;
 white-space:nowrap;
}

.badge{
 display:inline-block;
 padding:4px 9px;
 border-radius:999px;
 background:var(--gray-light);
}

.badge.success{
 background:#dcfce7;
 color:#166534;
}

.badge.warning{
 background:#fef3c7;
 color:#92400e;
}

.badge.danger{
 background:#fee2e2;
 color:#991b1b;
}

.actions{
 display:flex;
 flex-wrap:wrap;
 gap:8px;
}

.actions form{
 display:inline;
}

.grid{
 display:grid;
 grid-template-columns:
  repeat(auto-fit,minmax(260px,1fr));
 gap:16px;
}

.muted{
 color:var(--gray);
}

.spinner{
 display:none;
 margin-left:8px;
}

@media(max-width:700px){
 .form-row{
  grid-template-columns:1fr;
  gap:6px;
 }

 header .inner{
  align-items:flex-start;
  flex-direction:column;
 }
}
</style>
</head>

<body>

<header>
<div class="inner">
<strong>アンケートアプリ</strong>

<nav>
<a href="<?=e(screen_url('list'))?>">
アンケート一覧
</a>
<a href="<?=e(screen_url('kintone'))?>">
kintone
</a>
<a href="<?=e(screen_url('mail'))?>">
メール
</a>
</nav>
</div>
</header>

<main>
<?php
}

function render_footer(): void
{
?>
</main>

<script>
document.querySelectorAll(
 'form[data-external-action]'
).forEach(function(form){
 form.addEventListener('submit',function(){
  var button =
   form.querySelector('button[type="submit"]');

  if(button){
   button.disabled=true;

   var spinner =
    button.parentElement.querySelector(
     '.spinner'
    );

   if(spinner){
    spinner.style.display='inline';
   }
  }
 });
});

document.querySelectorAll(
 '[data-confirm]'
).forEach(function(el){
 el.addEventListener('click',function(e){
  if(!confirm(el.dataset.confirm)){
   e.preventDefault();
  }
 });
});
</script>

</body>
</html>
<?php
}

/* ============================================================
 * 一覧
 * ============================================================ */

function render_list(): void
{
    $surveys =
        read_json(SURVEYS_FILE);

    foreach ($surveys as &$survey) {
        $survey =
            normalize_survey_status(
                $survey
            );
    }

    unset($survey);

    usort(
        $surveys,
        static function(
            array $a,
            array $b
        ): int {
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
<div class="card">
<h1>アンケート一覧</h1>

<div class="actions">
<a href="<?=e(screen_url('edit'))?>">
<button class="primary">
新規作成
</button>
</a>
</div>
</div>

<div class="card">
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

<?php if (!$surveys): ?>

<tr>
<td colspan="7">
アンケートはありません。
</td>
</tr>

<?php endif; ?>

<?php foreach ($surveys as $survey): ?>

<tr>

<td><?=e($survey['title'] ?? '')?></td>

<td><?=e($survey['createdAt'] ?? '')?></td>

<td><?=e($survey['updatedAt'] ?? '')?></td>

<td>
<?=e($survey['startAt'] ?? '')?>
～
<?=e($survey['endAt'] ?? '')?>
</td>

<td>
<?php
$status =
    (string)(
        $survey['status'] ?? 'draft'
    );

$statusText = [
    'draft'     => '下書き',
    'published' => '公開中',
    'stopped'   => '停止',
    'ended'     => '終了',
][$status] ?? $status;
?>

<span class="badge">
<?=e($statusText)?>
</span>
</td>

<td>0</td>

<td>
<div class="actions">

<a href="<?=e(
 screen_url(
  'edit',
  (string)$survey['id']
 )
)?>">
<button>確認・編集</button>
</a>

<a href="<?=e(
 screen_url(
  'preview',
  (string)$survey['id']
 )
)?>">
<button>プレビュー</button>
</a>

<a href="<?=e(
 screen_url(
  'analytics',
  (string)$survey['id']
 )
)?>">
<button>集計</button>
</a>

<a href="<?=e(
 screen_url(
  'send',
  (string)$survey['id']
 )
)?>">
<button>送信</button>
</a>

<form method="post">
<input type="hidden"
       name="action"
       value="duplicate_survey">

<input type="hidden"
       name="id"
       value="<?=e($survey['id'])?>">

<button
 data-confirm="このアンケートを複製しますか？">
複製
</button>
</form>

<form method="post">
<input type="hidden"
       name="action"
       value="delete_survey">

<input type="hidden"
       name="id"
       value="<?=e($survey['id'])?>">

<button
 class="danger"
 data-confirm="このアンケートを削除しますか？">
削除
</button>
</form>

<?php if ($status !== 'ended'): ?>

<form method="post">
<input type="hidden"
       name="action"
       value="change_status">

<input type="hidden"
       name="id"
       value="<?=e($survey['id'])?>">

<?php if ($status === 'draft'): ?>

<input type="hidden"
       name="status"
       value="published">

<button
 data-confirm="公開しますか？">
公開
</button>

<?php elseif ($status === 'published'): ?>

<input type="hidden"
       name="status"
       value="stopped">

<button
 data-confirm="停止しますか？">
停止
</button>

<?php else: ?>

<input type="hidden"
       name="status"
       value="published">

<button
 data-confirm="公開を再開しますか？">
再開
</button>

<?php endif; ?>

</form>

<?php endif; ?>

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
    $isNew =
        $survey === null;

    if ($isNew) {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => 'draft',
            'numbering' => 'global',
        ];
    }
?>
<div class="card">

<h1>アンケート作成・編集</h1>

<form method="post">

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="id"
       value="<?=e($survey['id'] ?? '')?>">

<div class="form-row">
<label>状態</label>
<div>
<span class="badge">
<?=e([
 'draft'=>'下書き',
 'published'=>'公開中',
 'stopped'=>'停止',
 'ended'=>'終了',
][$survey['status'] ?? 'draft']
 ?? '')?>
</span>
</div>
</div>

<div class="form-row">
<label>アンケートタイトル</label>
<input name="title"
       maxlength="200"
       required
       value="<?=e(
        $survey['title'] ?? ''
       )?>">
</div>

<div class="form-row">
<label>アンケート説明</label>
<textarea name="description"><?=e(
 $survey['description'] ?? ''
)?></textarea>
</div>

<div class="form-row">
<label>開始日時</label>
<input type="datetime-local"
       name="startAt"
       value="<?=e(
        $survey['startAt'] ?? ''
       )?>">
</div>

<div class="form-row">
<label>終了日時</label>
<input type="datetime-local"
       name="endAt"
       value="<?=e(
        $survey['endAt'] ?? ''
       )?>">
</div>

<div class="form-row">
<label>質問番号</label>
<select name="numbering">

<option value="global"
<?=($survey['numbering'] ?? 'global')
 === 'global'
 ? 'selected'
 : ''?>>
Q1、Q2、Q3...
</option>

<option value="group"
<?=($survey['numbering'] ?? '')
 === 'group'
 ? 'selected'
 : ''?>>
Q1-1、Q1-2、Q2-1...
</option>

</select>
</div>

<div class="actions">
<a href="<?=e(
 screen_url('list')
)?>">
<button type="button">
キャンセル
</button>
</a>

<button class="primary"
        type="submit">
保存して一覧へ
</button>
</div>

</form>

</div>
<?php
}

/* ============================================================
 * kintone画面
 * ============================================================ */

function render_kintone(): void
{
    $settings =
        read_json(SETTINGS_FILE);

    $k =
        $settings['kintone']
        ?? default_settings()['kintone'];

    $catalog =
        $k['field_catalog']
        ?? [];

    $customers =
        read_json(CUSTOMERS_FILE);
?>
<div class="card">

<h1>kintone連携設定</h1>

<form method="post">

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="form-row">
<label>サブドメイン</label>

<input name="subdomain"
       placeholder="xxxx / xxxx.cybozu.com"
       value="<?=e(
        $k['subdomain'] ?? ''
       )?>">
</div>

<div class="form-row">
<label>顧客管理アプリID</label>

<input name="app_id"
       inputmode="numeric"
       value="<?=e(
        $k['app_id'] ?? ''
       )?>">
</div>

<div class="form-row">
<label>ログイン名</label>

<input name="username"
       autocomplete="username"
       value="<?=e(
        $k['username'] ?? ''
       )?>">
</div>

<div class="form-row">
<label>パスワード</label>

<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</div>

<div class="form-row">
<label>Proxy</label>

<input name="proxy"
       placeholder="host:port"
       value="<?=e(
        $k['proxy'] ?? ''
       )?>">
</div>

<div class="form-row">
<label>SSL証明書検証</label>

<label>
<input type="checkbox"
       name="verify_ssl"
       value="1"
       <?=!empty($k['verify_ssl'])
        ? 'checked'
        : ''?>>
有効
</label>
</div>

<button class="primary"
        type="submit">
設定保存
</button>

</form>

<hr>

<div class="actions">

<form method="post"
      data-external-action>

<input type="hidden"
       name="action"
       value="test_kintone">

<button type="submit">
接続テスト
</button>

<span class="spinner">
接続確認中...
</span>

</form>

<form method="post"
      data-external-action>

<input type="hidden"
       name="action"
       value="fetch_kintone_fields">

<button type="submit">
項目一覧を再取得
</button>

<span class="spinner">
取得中...
</span>

</form>

<form method="post"
      data-external-action>

<input type="hidden"
       name="action"
       value="sync_kintone">

<button type="submit">
顧客情報を同期
</button>

<span class="spinner">
同期中...
</span>

</form>

</div>

</div>

<div class="card">

<h2>kintone項目マッピング</h2>

<?php
$propertyCodes = [];

if (is_array($catalog)) {
    foreach ($catalog as $code => $property) {
        $propertyCodes[] =
            (string)$code;
    }
}

$fieldNames = [
 'organization'=>'組織名',
 'name'=>'氏名',
 'email'=>'メールアドレス',
 'department'=>'部署名',
 'phone'=>'電話番号',
];
?>

<form method="post">

<input type="hidden"
       name="action"
       value="save_kintone">

<input type="hidden"
       name="subdomain"
       value="<?=e(
        $k['subdomain'] ?? ''
       )?>">

<input type="hidden"
       name="app_id"
       value="<?=e(
        $k['app_id'] ?? ''
       )?>">

<input type="hidden"
       name="username"
       value="<?=e(
        $k['username'] ?? ''
       )?>">

<input type="hidden"
       name="proxy"
       value="<?=e(
        $k['proxy'] ?? ''
       )?>">

<?php foreach (
    $fieldNames as $code => $label
): ?>

<div class="form-row">

<label><?=e($label)?></label>

<select name="fields[<?=e($code)?>]">

<option value="">未設定</option>

<?php foreach (
    $propertyCodes as $propertyCode
): ?>

<option value="<?=e(
 $propertyCode
)?>"
<?=(
 (string)(
  $k['fields'][$code] ?? ''
 )
 === $propertyCode
)
 ? 'selected'
 : ''?>>
<?=e($propertyCode)?>
</option>

<?php endforeach; ?>

</select>

</div>

<?php endforeach; ?>

<button class="primary"
        type="submit">
項目設定を保存
</button>

</form>

<p class="muted">
同期済み顧客：
<?=count($customers)?>件
</p>

</div>

<?php
}

/* ============================================================
 * メール画面
 * ============================================================ */

function render_mail(): void
{
    $settings =
        read_json(SETTINGS_FILE);

    $m =
        $settings['mail']
        ?? default_settings()['mail'];
?>
<div class="card">

<h1>メールサーバ設定</h1>

<form method="post">

<input type="hidden"
       name="action"
       value="save_mail">

<div class="form-row">
<label>SMTPサーバ</label>
<input name="host"
       value="<?=e(
        $m['host'] ?? ''
       )?>">
</div>

<div class="form-row">
<label>SMTPポート</label>
<input type="number"
       name="port"
       min="1"
       max="65535"
       value="<?=e(
        $m['port'] ?? 587
       )?>">
</div>

<div class="form-row">
<label>暗号化方式</label>

<select name="encryption">

<option value="ssl"
<?=($m['encryption'] ?? '')
 === 'ssl'
 ? 'selected'
 : ''?>>
SSL
</option>

<option value="tls"
<?=($m['encryption'] ?? 'tls')
 === 'tls'
 ? 'selected'
 : ''?>>
TLS
</option>

<option value="none"
<?=($m['encryption'] ?? '')
 === 'none'
 ? 'selected'
 : ''?>>
なし
</option>

</select>
</div>

<div class="form-row">
<label>SMTPユーザー名</label>
<input name="username"
       value="<?=e(
        $m['username'] ?? ''
       )?>">
</div>

<div class="form-row">
<label>SMTPパスワード</label>
<input type="password"
       name="password"
       autocomplete="new-password">
</div>

<div class="form-row">
<label>送信元メールアドレス</label>
<input type="email"
       name="from_email"
       value="<?=e(
        $m['from_email'] ?? ''
       )?>">
</div>

<div class="form-row">
<label>送信元名</label>
<input name="from_name"
       value="<?=e(
        $m['from_name'] ?? ''
       )?>">
</div>

<div class="form-row">
<label>返信先メールアドレス</label>
<input type="email"
       name="reply_to"
       value="<?=e(
        $m['reply_to'] ?? ''
       )?>">
</div>

<button class="primary"
        type="submit">
設定保存
</button>

</form>

<hr>

<h2>接続確認</h2>

<form method="post"
      data-external-action>

<input type="hidden"
       name="action"
       value="test_mail">

<button type="submit">
接続テスト
</button>

<span class="spinner">
接続確認中...
</span>

</form>

<hr>

<h2>テストメール</h2>

<form method="post"
      data-external-action>

<input type="hidden"
       name="action"
       value="send_test_mail">

<div class="form-row">

<label>送信先</label>

<input type="email"
       name="test_to"
       required>

</div>

<button class="primary"
        type="submit">
テストメール送信
</button>

<span class="spinner">
送信中...
</span>

</form>

</div>
<?php
}

/* ============================================================
 * 送信画面
 * ============================================================ */

function render_send(
    ?array $survey
): void {
?>
<div class="card">

<h1>顧客選択・メール送信</h1>

<?php if ($survey === null): ?>

<p>
対象アンケートが指定されていません。
</p>

<?php else: ?>

<h2>対象アンケート</h2>

<p>
<strong>
<?=e(
 $survey['title'] ?? ''
)?>
</strong>
</p>

<?php
$customers =
    read_json(CUSTOMERS_FILE);
?>

<form method="post">

<input type="hidden"
       name="action"
       value="send_survey_mail">

<input type="hidden"
       name="id"
       value="<?=e(
        $survey['id']
       )?>">

<div class="form-row">
<label>顧客</label>

<select name="customer_ids[]"
        multiple
        size="8">

<?php foreach (
    $customers as $customer
): ?>

<option value="<?=e(
 $customer['id'] ?? ''
)?>">
<?=e(
 $customer['name'] ?? ''
)?>
 -
<?=e(
 $customer['email'] ?? ''
)?>
</option>

<?php endforeach; ?>

</select>
</div>

<div class="form-row">
<label>件名</label>

<input name="subject"
       value="<?=e(
        $survey['title'] ?? ''
       )?>">
</div>

<div class="form-row">
<label>本文</label>

<textarea name="body"><?=e(
 "アンケートのご案内です。\n\n{顧客名} 様\n\n" .
 "以下のURLからご回答ください。\n" .
 "{アンケートURL}"
)?></textarea>
</div>

<button class="primary"
        type="submit"
        data-confirm="選択した顧客へ送信しますか？">
一括送信
</button>

</form>

<hr>

<h2>送信履歴</h2>

<?php
$logs =
    read_json(SEND_LOG_FILE);

$surveyId =
    (string)$survey['id'];

$logs = array_filter(
    $logs,
    static fn(array $log): bool =>
        (string)(
            $log['survey_id'] ?? ''
        ) === $surveyId
);
?>

<?php if (!$logs): ?>

<p>
送信履歴はありません。
</p>

<?php else: ?>

<div class="table-wrap">
<table>

<thead>
<tr>
<th>日時</th>
<th>宛先</th>
<th>状態</th>
</tr>
</thead>

<tbody>

<?php foreach ($logs as $log): ?>

<tr>
<td><?=e(
 $log['createdAt'] ?? ''
)?></td>

<td><?=e(
 $log['email'] ?? ''
)?></td>

<td><?=e(
 $log['status'] ?? ''
)?></td>
</tr>

<?php endforeach; ?>

</tbody>
</table>
</div>

<?php endif; ?>

<?php endif; ?>

</div>
<?php
}

/* ============================================================
 * 集計
 * ============================================================ */

function render_analytics(
    ?array $survey
): void {
?>
<div class="card">

<h1>回答集計・分析</h1>

<?php if ($survey === null): ?>

<p>
対象アンケートが指定されていません。
</p>

<?php else: ?>

<h2>
<?=e(
 $survey['title'] ?? ''
)?>
</h2>

<?php
$answers =
    read_json(ANSWERS_FILE);

$rows = [];

foreach ($answers as $answer) {
    if (
        (string)(
            $answer['survey_id'] ?? ''
        ) ===
        (string)$survey['id']
    ) {
        $rows[] = $answer;
    }
}

$customers =
    read_json(CUSTOMERS_FILE);

$totalCustomers =
    count($customers);

$answerCount =
    count($rows);

$rate =
    $totalCustomers > 0
        ? round(
            $answerCount /
            $totalCustomers *
            100,
            1
        )
        : 0;
?>

<div class="grid">

<div class="card">
<strong>送信対象者数</strong>
<h2><?=e($totalCustomers)?></h2>
</div>

<div class="card">
<strong>回答数</strong>
<h2><?=e($answerCount)?></h2>
</div>

<div class="card">
<strong>未回答数</strong>
<h2><?=e(
 max(
  0,
  $totalCustomers -
  $answerCount
 )
)?></h2>
</div>

<div class="card">
<strong>回答率</strong>
<h2><?=e($rate)?>%</h2>
</div>

</div>

<?php if ($answerCount === 0): ?>

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
<th>回答内容</th>
</tr>
</thead>

<tbody>

<?php foreach ($rows as $answer): ?>

<tr>

<td><?=e(
 $answer['createdAt'] ?? ''
)?></td>

<td>
<pre><?=e(
 json_encode(
  $answer['data'] ?? [],
  JSON_UNESCAPED_UNICODE |
  JSON_PRETTY_PRINT
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
}

/* ============================================================
 * プレビュー
 * ============================================================ */

function render_preview(
    ?array $survey
): void {
?>
<div class="card">

<h1>プレビュー</h1>

<?php if ($survey === null): ?>

<p>
アンケートが存在しません。
</p>

<?php else: ?>

<h2><?=e(
 $survey['title'] ?? ''
)?></h2>

<p><?=nl2br(
 e($survey['description'] ?? '')
)?></p>

<?php
$groups =
    $survey['groups'] ?? [];
?>

<?php foreach (
    $groups as $groupIndex => $group
): ?>

<div class="card">

<h3><?=e(
 $group['title'] ??
 'グループ ' .
 ($groupIndex + 1)
)?></h3>

<?php foreach (
    ($group['questions'] ?? [])
    as $questionIndex => $question
): ?>

<div class="card">

<strong>
Q<?=e(
 $question['number'] ??
 ($questionIndex + 1)
)?>
</strong>

<p><?=e(
 $question['text'] ?? ''
)?></p>

<?php
$type =
    $question['type'] ??
    'text';
?>

<?php if (
    $type === 'single'
): ?>

<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>

<label>
<input type="radio">
<?=e($option['text'] ?? $option)?>
</label><br>

<?php endforeach; ?>

<?php elseif (
    $type === 'multiple'
): ?>

<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>

<label>
<input type="checkbox">
<?=e($option['text'] ?? $option)?>
</label><br>

<?php endforeach; ?>

<?php else: ?>

<textarea></textarea>

<?php endif; ?>

</div>

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
?>
<div class="card">

<h1>アンケート回答</h1>

<?php if ($survey === null): ?>

<p>
アンケートが存在しません。
</p>

<?php else: ?>

<h2><?=e(
 $survey['title'] ?? ''
)?></h2>

<p><?=nl2br(
 e($survey['description'] ?? '')
)?></p>

<form method="post">

<input type="hidden"
       name="action"
       value="answer_next">

<input type="hidden"
       name="id"
       value="<?=e(
        $survey['id']
       )?>">

<?php
$groups =
    $survey['groups'] ?? [];

foreach (
    $groups as $group
) {
    foreach (
        ($group['questions'] ?? [])
        as $question
    ) {
?>
<div class="card">

<p>
<strong>
<?=e(
 $question['number'] ?? ''
)?>
</strong>
</p>

<p>
<?=e(
 $question['text'] ?? ''
)?>
</p>

<?php
$type =
    $question['type'] ??
    'text';

$name =
    'answer_' .
    preg_replace(
        '/[^A-Za-z0-9_-]/',
        '',
        (string)(
            $question['id'] ??
            uuid()
        )
    );
?>

<?php if ($type === 'single'): ?>

<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>

<label>
<input type="radio"
       name="<?=e($name)?>"
       value="<?=e(
        $option['value'] ??
        $option['text'] ??
        ''
       )?>">

<?=e(
 $option['text'] ??
 $option
)?>

</label><br>

<?php endforeach; ?>

<?php elseif (
    $type === 'multiple'
): ?>

<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>

<label>
<input type="checkbox"
       name="<?=e($name)?>[]"
       value="<?=e(
        $option['value'] ??
        $option['text'] ??
        ''
       )?>">

<?=e(
 $option['text'] ??
 $option
)?>

</label><br>

<?php endforeach; ?>

<?php else: ?>

<textarea name="<?=e($name)?>"></textarea>

<?php endif; ?>

</div>
<?php
    }
}
?>

<button class="primary"
        type="submit">
回答確認へ
</button>

</form>

<?php endif; ?>

</div>
<?php
}

function render_confirm(
    ?array $survey
): void {
?>
<div class="card">

<h1>回答確認</h1>

<?php if ($survey === null): ?>

<p>
アンケートが存在しません。
</p>

<?php else: ?>

<h2><?=e(
 $survey['title'] ?? ''
)?></h2>

<p>
回答内容を確認してください。
</p>

<form method="post">

<input type="hidden"
       name="action"
       value="answer_submit">

<input type="hidden"
       name="id"
       value="<?=e(
        $survey['id']
       )?>">

<button class="primary"
        type="submit"
        data-confirm="回答を送信しますか？">
送信する
</button>

</form>

<?php endif; ?>

</div>
<?php
}

function render_complete(
    ?array $survey
): void {
?>
<div class="card">

<h1>回答完了</h1>

<p>
回答を受け付けました。
</p>

</div>
<?php
}

/* ============================================================
 * エラー表示
 * ============================================================ */

if ($flash !== null):
?>
<?php endif; ?>

<?php

render_header(
    match ($screen) {
        'edit' =>
            'アンケート作成・編集',
        'preview' =>
            'プレビュー',
        'send' =>
            '顧客選択・メール送信',
        'analytics' =>
            '回答集計・分析',
        'kintone' =>
            'kintone連携設定',
        'mail' =>
            'メールサーバ設定',
        'answer' =>
            'アンケート回答',
        'confirm' =>
            '回答確認',
        'complete' =>
            '回答完了',
        default =>
            'アンケート一覧',
    }
);

if ($flash !== null):
?>
<div class="flash <?=e(
    $flash['type'] ?? 'error'
)?>">
<?=nl2br(
 e($flash['message'] ?? '')
)?>
</div>
<?php
endif;

switch ($screen) {

    case 'edit':
        render_edit($survey);
        break;

    case 'preview':
        render_preview($survey);
        break;

    case 'send':
        if ($id === '' || $survey === null) {
            render_list();
        } else {
            render_send($survey);
        }
        break;

    case 'analytics':
        if ($id === '' || $survey === null) {
            render_list();
        } else {
            render_analytics($survey);
        }
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

    case 'list':
    default:
        render_list();
        break;
}

render_footer();
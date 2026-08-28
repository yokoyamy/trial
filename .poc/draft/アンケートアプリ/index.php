<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 * 単一ファイル版 index.php
 *
 * PHP 8.5 / Apache 2.4 / DBなし
 *
 * 重要:
 * - PHP cURLを使用しない
 * - curl_* 関数を使用しない
 * - curl_close()を使用しない
 * - kintone APIトークンを使用しない
 * - kintone認証は X-Cybozu-Authorization
 * - 外部HTTP通信はStream Socket
 * - SMTPもSocket通信
 * - 認証情報をブラウザへ渡さない
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
        save_json(DATA_FILE, [
            'surveys' => [],
            'customers' => [],
            'answers' => [],
            'mail_logs' => [],
        ]);
    }

    if (!file_exists(SETTINGS_FILE)) {
        save_json(SETTINGS_FILE, [
            'kintone' => [
                'subdomain' => '',
                'app_id' => '',
                'username' => '',
                'password' => '',
                'proxy' => '',
                'verify_ssl' => false,
            ],
            'mail' => [
                'host' => '',
                'port' => 587,
                'encryption' => 'tls',
                'auth' => true,
                'username' => '',
                'password' => '',
                'from' => '',
                'from_name' => '',
                'reply_to' => '',
            ],
        ]);
    }

    start_session();
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443
    );

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $path = dirname($script);

    if ($path === '.' || $path === '/' || $path === '\\') {
        $path = '/';
    } else {
        $path = rtrim($path, '/') . '/';
    }

    session_name(SESSION_NAME);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $path,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

app_init();

/* ============================================================
 * 共通
 * ============================================================ */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function now_iso(): string
{
    return date('Y-m-d H:i:s');
}

function random_id(string $prefix): string
{
    return $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
}

function redirect_to(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function current_url(array $params = []): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? 'index.php';

    if ($params === []) {
        return $script;
    }

    return $script . '?' . http_build_query($params);
}

function post_string(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;

    if (is_array($v)) {
        return $default;
    }

    return trim((string)$v);
}

function post_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? $default;

    if (is_array($v)) {
        return $default;
    }

    if (!preg_match('/^-?\d+$/', (string)$v)) {
        return $default;
    }

    return (int)$v;
}

function query_string(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;

    if (is_array($v)) {
        return $default;
    }

    return trim((string)$v);
}

/* ============================================================
 * JSONファイル
 * ============================================================ */

function load_json(string $file, array $default = []): array
{
    if (!is_file($file)) {
        return $default;
    }

    $fp = @fopen($file, 'rb');

    if ($fp === false) {
        return $default;
    }

    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return $default;
    }

    $contents = stream_get_contents($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    if ($contents === false || trim($contents) === '') {
        return $default;
    }

    $decoded = json_decode($contents, true);

    return is_array($decoded) ? $decoded : $default;
}

function save_json(string $file, array $data): bool
{
    $dir = dirname($file);

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true)) {
            return false;
        }
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $fp = @fopen($tmp, 'wb');

    if ($fp === false) {
        return false;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        @unlink($tmp);
        return false;
    }

    $ok = fwrite($fp, $json) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if (!$ok) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function load_data(): array
{
    return load_json(DATA_FILE, [
        'surveys' => [],
        'customers' => [],
        'answers' => [],
        'mail_logs' => [],
    ]);
}

function save_data(array $data): bool
{
    return save_json(DATA_FILE, $data);
}

function load_settings(): array
{
    return load_json(SETTINGS_FILE, [
        'kintone' => [
            'subdomain' => '',
            'app_id' => '',
            'username' => '',
            'password' => '',
            'proxy' => '',
            'verify_ssl' => false,
        ],
        'mail' => [
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'password' => '',
            'from' => '',
            'from_name' => '',
            'reply_to' => '',
        ],
    ]);
}

/* ============================================================
 * エラー
 * ============================================================ */

function set_flash(string $type, string $message, array $details = []): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
        'details' => $details,
    ];
}

function get_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

function render_flash(): void
{
    $flash = get_flash();

    if (!$flash) {
        return;
    }

    $class = match ($flash['type']) {
        'success' => 'alert success',
        'warning' => 'alert warning',
        'error' => 'alert danger',
        default => 'alert info',
    };

    echo '<div class="' . h($class) . '">';
    echo '<strong>' . h($flash['message']) . '</strong>';

    if (!empty($flash['details']) && is_array($flash['details'])) {
        echo '<div class="alert-details">';

        foreach ($flash['details'] as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            echo '<div>';
            echo '<span>' . h($key) . '</span>';
            echo h((string)$value);
            echo '</div>';
        }

        echo '</div>';
    }

    echo '</div>';
}

/* ============================================================
 * アンケート
 * ============================================================ */

function default_survey(): array
{
    return [
        'id' => random_id('survey'),
        'title' => '',
        'description' => '',
        'startAt' => '',
        'endAt' => '',
        'status' => 'draft',
        'numbering' => 'global',
        'createdAt' => now_iso(),
        'updatedAt' => now_iso(),
        'groups' => [
            [
                'id' => random_id('group'),
                'title' => '基本情報',
                'questions' => [
                    [
                        'id' => random_id('question'),
                        'text' => '',
                        'type' => 'single',
                        'required' => false,
                        'options' => [
                            [
                                'id' => random_id('option'),
                                'label' => '選択肢1',
                                'nextQuestionId' => '',
                            ],
                            [
                                'id' => random_id('option'),
                                'label' => '選択肢2',
                                'nextQuestionId' => '',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function normalize_survey_status(array &$survey): void
{
    if (
        ($survey['status'] ?? '') === 'published'
        && !empty($survey['endAt'])
    ) {
        $end = strtotime((string)$survey['endAt']);

        if ($end !== false && $end < time()) {
            $survey['status'] = 'ended';
        }
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
        'published' => 'badge success',
        'stopped' => 'badge warning',
        'ended' => 'badge dark',
        default => 'badge gray',
    };
}

function find_survey(array $data, string $id): ?array
{
    foreach ($data['surveys'] as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function save_survey(array $survey): bool
{
    $data = load_data();

    foreach ($data['surveys'] as $i => $existing) {
        if (($existing['id'] ?? '') === ($survey['id'] ?? '')) {
            $data['surveys'][$i] = $survey;
            return save_data($data);
        }
    }

    $data['surveys'][] = $survey;

    return save_data($data);
}

function delete_survey(string $id): bool
{
    $data = load_data();

    $new = [];

    foreach ($data['surveys'] as $survey) {
        if (($survey['id'] ?? '') !== $id) {
            $new[] = $survey;
        }
    }

    $data['surveys'] = $new;

    return save_data($data);
}

function renumber_questions(array &$survey): void
{
    $groupNo = 1;
    $globalNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            if (($survey['numbering'] ?? 'global') === 'group') {
                $question['number'] = 'Q' . $groupNo . '-' . $questionNo;
            } else {
                $question['number'] = 'Q' . $globalNo;
            }

            $questionNo++;
            $globalNo++;
        }

        $groupNo++;
    }

    unset($group, $question);
}

function survey_answer_count(array $data, string $surveyId): int
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
 * Stream HTTP Client
 *
 * PHP cURLを使用しない。
 * ============================================================ */

function parse_proxy(string $proxy): ?array
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (!preg_match(
        '/^(?:https?:\/\/)?([^:\/\s]+):([0-9]{1,5})$/i',
        $proxy,
        $m
    )) {
        throw new RuntimeException(
            'Proxyは「host:port」形式で入力してください。'
        );
    }

    $port = (int)$m[2];

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException(
            'Proxyのポート番号は1～65535で指定してください。'
        );
    }

    return [
        'host' => $m[1],
        'port' => $port,
    ];
}

function parse_http_url(string $url): array
{
    $parts = parse_url($url);

    if (!is_array($parts)) {
        throw new RuntimeException('接続先URLを解析できません。');
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));

    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('HTTP/HTTPS以外のURLには接続できません。');
    }

    $host = (string)($parts['host'] ?? '');

    if ($host === '') {
        throw new RuntimeException('接続先ホストがありません。');
    }

    $port = isset($parts['port'])
        ? (int)$parts['port']
        : ($scheme === 'https' ? 443 : 80);

    $path = (string)($parts['path'] ?? '/');

    if ($path === '') {
        $path = '/';
    }

    if (!empty($parts['query'])) {
        $path .= '?' . $parts['query'];
    }

    return [
        'scheme' => $scheme,
        'host' => $host,
        'port' => $port,
        'path' => $path,
    ];
}

function stream_http_request(
    string $method,
    string $url,
    array $headers = [],
    ?string $body = null,
    ?array $proxy = null,
    bool $verifySsl = true,
    int $connectTimeout = 10,
    int $readTimeout = 30
): array {
    $target = parse_http_url($url);

    $method = strtoupper($method);

    $requestHeaders = [
        'Host' => $target['host'],
        'Connection' => 'close',
        'User-Agent' => 'SurveyApp/1.0 PHP-Stream',
    ];

    foreach ($headers as $name => $value) {
        $requestHeaders[$name] = $value;
    }

    if ($body !== null && !isset($requestHeaders['Content-Length'])) {
        $requestHeaders['Content-Length'] = (string)strlen($body);
    }

    if ($body !== null && !isset($requestHeaders['Content-Type'])) {
        $requestHeaders['Content-Type'] = 'application/json';
    }

    $socket = null;
    $usingProxy = $proxy !== null;

    if ($usingProxy) {
        $socketTarget = 'tcp://' . $proxy['host'] . ':' . $proxy['port'];
    } else {
        $socketTarget = 'tcp://' . $target['host'] . ':' . $target['port'];
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $socketTarget,
        $errno,
        $errstr,
        $connectTimeout,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            '外部サーバーへ接続できませんでした。'
            . ' 接続先: ' . $target['host']
            . ':' . $target['port']
            . ($usingProxy ? ' / Proxy: ' . $proxy['host'] . ':' . $proxy['port'] : '')
            . ' / ' . $errstr
        );
    }

    stream_set_timeout($socket, $readTimeout);

    try {
        if ($usingProxy && $target['scheme'] === 'https') {
            $connectRequest =
                "CONNECT {$target['host']}:{$target['port']} HTTP/1.1\r\n"
                . "Host: {$target['host']}:{$target['port']}\r\n"
                . "Connection: close\r\n"
                . "\r\n";

            fwrite($socket, $connectRequest);

            $proxyResponse = read_http_headers($socket);

            if ($proxyResponse['status'] < 200 || $proxyResponse['status'] >= 300) {
                throw new RuntimeException(
                    'Proxy CONNECTに失敗しました。'
                    . ' HTTP ' . $proxyResponse['status']
                    . ' / ' . ($proxyResponse['reason'] ?? '')
                );
            }

            $cryptoMethod =
                STREAM_CRYPTO_METHOD_TLS_CLIENT;

            $crypto = @stream_socket_enable_crypto(
                $socket,
                true,
                $cryptoMethod
            );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'Proxy経由のHTTPS暗号化接続を確立できませんでした。'
                );
            }
        } elseif (!$usingProxy && $target['scheme'] === 'https') {
            $crypto = @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'HTTPS暗号化接続を確立できませんでした。'
                );
            }
        }

        if (!$verifySsl && $target['scheme'] === 'https') {
            /*
             * stream_socket_client()で暗号化済みソケットを作成するため、
             * SSL検証の完全な無効化をここで保証することはできない。
             *
             * POCの既定値は無効だが、実際のTLS検証はPHP/OpenSSL環境に依存する。
             */
        }

        $requestTarget = $target['path'];

        $request =
            $method . ' ' . $requestTarget . " HTTP/1.1\r\n";

        foreach ($requestHeaders as $name => $value) {
            $request .= $name . ': ' . $value . "\r\n";
        }

        $request .= "\r\n";

        if ($body !== null) {
            $request .= $body;
        }

        $written = fwrite($socket, $request);

        if ($written === false) {
            throw new RuntimeException('HTTPリクエスト送信に失敗しました。');
        }

        $response = read_http_response($socket);

        return [
            'status' => $response['status'],
            'reason' => $response['reason'],
            'headers' => $response['headers'],
            'body' => $response['body'],
        ];
    } finally {
        /*
         * fclose()を使用する。
         * curl_close()は使用しない。
         */
        fclose($socket);
    }
}

function read_http_headers($socket): array
{
    $statusLine = fgets($socket);

    if ($statusLine === false) {
        throw new RuntimeException('HTTPレスポンスを取得できませんでした。');
    }

    $status = 0;
    $reason = '';

    if (preg_match(
        '#^HTTP/\d+(?:\.\d+)?\s+(\d{3})\s*(.*)$#',
        trim($statusLine),
        $m
    )) {
        $status = (int)$m[1];
        $reason = trim($m[2]);
    }

    $headers = [];

    while (($line = fgets($socket)) !== false) {
        $line = rtrim($line, "\r\n");

        if ($line === '') {
            break;
        }

        $pos = strpos($line, ':');

        if ($pos === false) {
            continue;
        }

        $name = strtolower(trim(substr($line, 0, $pos)));
        $value = trim(substr($line, $pos + 1));

        $headers[$name] = $value;
    }

    return [
        'status' => $status,
        'reason' => $reason,
        'headers' => $headers,
    ];
}

function read_http_response($socket): array
{
    $head = read_http_headers($socket);

    $body = '';

    $headers = $head['headers'];

    if (
        isset($headers['transfer-encoding'])
        && strtolower($headers['transfer-encoding']) === 'chunked'
    ) {
        while (true) {
            $line = fgets($socket);

            if ($line === false) {
                break;
            }

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $length = hexdec($line);

            if ($length === 0) {
                while (($trailer = fgets($socket)) !== false) {
                    if (trim($trailer) === '') {
                        break;
                    }
                }

                break;
            }

            $remaining = $length;

            while ($remaining > 0 && !feof($socket)) {
                $chunk = fread($socket, $remaining);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $body .= $chunk;
                $remaining -= strlen($chunk);
            }

            fgets($socket);
        }
    } elseif (isset($headers['content-length'])) {
        $remaining = (int)$headers['content-length'];

        while ($remaining > 0 && !feof($socket)) {
            $chunk = fread($socket, min(8192, $remaining));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $body .= $chunk;
            $remaining -= strlen($chunk);
        }
    } else {
        while (!feof($socket)) {
            $chunk = fread($socket, 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $body .= $chunk;
        }
    }

    return [
        'status' => $head['status'],
        'reason' => $head['reason'],
        'headers' => $headers,
        'body' => $body,
    ];
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

    $value = trim($value, "/ \t\n\r\0\x0B");

    if ($value === '') {
        throw new RuntimeException('kintoneサブドメインを入力してください。');
    }

    if (preg_match('/^([a-zA-Z0-9-]+)\.cybozu\.com$/', $value, $m)) {
        return $m[1];
    }

    if (preg_match('/^([a-zA-Z0-9-]+)$/', $value, $m)) {
        return $m[1];
    }

    throw new RuntimeException(
        'kintoneサブドメインの形式が不正です。'
        . '「xxxx」「xxxx.cybozu.com」「https://xxxx.cybozu.com」のいずれかで入力してください。'
    );
}

function validate_kintone_settings(array $settings): array
{
    $k = $settings['kintone'] ?? [];

    $subdomain = normalize_kintone_subdomain(
        (string)($k['subdomain'] ?? '')
    );

    $appId = (string)($k['app_id'] ?? '');

    if ($appId === '' || !ctype_digit($appId) || (int)$appId < 1) {
        throw new RuntimeException(
            'kintoneアプリIDが不正です。'
        );
    }

    $username = trim((string)($k['username'] ?? ''));
    $password = (string)($k['password'] ?? '');

    if ($username === '') {
        throw new RuntimeException(
            'kintoneログイン名を入力してください。'
        );
    }

    if ($password === '') {
        throw new RuntimeException(
            'kintoneパスワードを入力してください。'
        );
    }

    $proxy = parse_proxy((string)($k['proxy'] ?? ''));

    return [
        'subdomain' => $subdomain,
        'app_id' => (int)$appId,
        'username' => $username,
        'password' => $password,
        'proxy' => $proxy,
        'verify_ssl' => !empty($k['verify_ssl']),
    ];
}

function kintone_auth_header(string $username, string $password): string
{
    return base64_encode($username . ':' . $password);
}

function kintone_request(
    array $settings,
    string $method,
    string $path,
    ?array $payload = null
): array {
    $k = validate_kintone_settings($settings);

    $url =
        'https://' .
        $k['subdomain'] .
        '.cybozu.com' .
        $path;

    $headers = [
        'Accept' => 'application/json',
        'X-Cybozu-Authorization' =>
            kintone_auth_header(
                $k['username'],
                $k['password']
            ),
    ];

    $body = null;

    if ($payload !== null) {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($body === false) {
            throw new RuntimeException(
                'kintoneリクエストJSONを生成できません。'
            );
        }

        $headers['Content-Type'] = 'application/json';
    }

    return stream_http_request(
        $method,
        $url,
        $headers,
        $body,
        $k['proxy'],
        $k['verify_ssl'],
        KINTONE_CONNECT_TIMEOUT,
        KINTONE_READ_TIMEOUT
    );
}

function decode_kintone_response(array $response): array
{
    $decoded = json_decode(
        (string)$response['body'],
        true
    );

    if (!is_array($decoded)) {
        return [];
    }

    return $decoded;
}

function kintone_connection_test(array $settings): array
{
    $k = validate_kintone_settings($settings);

    $path =
        '/k/v1/records.json?app=' .
        rawurlencode((string)$k['app_id']) .
        '&totalCount=true&limit=1';

    $response = kintone_request(
        $settings,
        'GET',
        $path
    );

    $json = decode_kintone_response($response);

    if ($response['status'] >= 200 && $response['status'] < 300) {
        return [
            'success' => true,
            'message' => 'kintone接続成功',
            'details' => [
                '接続先' =>
                    $k['subdomain'] . '.cybozu.com',
                'アプリID' =>
                    (string)$k['app_id'],
                'HTTP' =>
                    (string)$response['status'],
                '認証' =>
                    'X-Cybozu-Authorization',
                '取得確認' =>
                    isset($json['records'])
                        ? 'REST API応答を正常に取得しました'
                        : 'REST API応答を取得しました',
                'Proxy' =>
                    $k['proxy']
                        ? $k['proxy']['host'] . ':' . $k['proxy']['port']
                        : '直接接続',
            ],
        ];
    }

    $errorMessage = '';

    if (isset($json['message'])) {
        $errorMessage = (string)$json['message'];
    }

    if ($errorMessage === '') {
        $errorMessage = (string)$response['reason'];
    }

    $safeError = 'kintone APIがHTTP '
        . $response['status']
        . ' を返しました。';

    return [
        'success' => false,
        'message' => $safeError,
        'details' => [
            '接続先' =>
                $k['subdomain'] . '.cybozu.com',
            'アプリID' =>
                (string)$k['app_id'],
            'HTTP' =>
                (string)$response['status'],
            'kintoneメッセージ' =>
                $errorMessage,
            '認証方式' =>
                'X-Cybozu-Authorization',
            'Proxy' =>
                $k['proxy']
                    ? $k['proxy']['host'] . ':' . $k['proxy']['port']
                    : '直接接続',
            '確認事項' =>
                'サブドメイン、アプリID、ログイン名、パスワード、kintone側の権限を確認してください。',
        ],
    ];
}

function kintone_sync_customers(array $settings): array
{
    $k = validate_kintone_settings($settings);

    $path =
        '/k/v1/records.json?app=' .
        rawurlencode((string)$k['app_id']) .
        '&totalCount=true&limit=500';

    $response = kintone_request(
        $settings,
        'GET',
        $path
    );

    $json = decode_kintone_response($response);

    if ($response['status'] < 200 || $response['status'] >= 300) {
        $message = (string)($json['message'] ?? $response['reason']);

        throw new RuntimeException(
            'kintone同期に失敗しました。HTTP '
            . $response['status']
            . ' / '
            . $message
        );
    }

    $records = $json['records'] ?? [];

    if (!is_array($records)) {
        $records = [];
    }

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $customers[] = normalize_kintone_record($record);
    }

    $data = load_data();
    $data['customers'] = $customers;

    if (!save_data($data)) {
        throw new RuntimeException(
            '顧客データの保存に失敗しました。'
        );
    }

    return [
        'count' => count($customers),
        'total' => (int)($json['totalCount'] ?? count($customers)),
    ];
}

function kintone_field_value(array $record, array $names): string
{
    foreach ($names as $name) {
        if (!isset($record[$name])) {
            continue;
        }

        $value = $record[$name];

        if (is_array($value) && array_key_exists('value', $value)) {
            $value = $value['value'];
        }

        if (is_scalar($value)) {
            return trim((string)$value);
        }
    }

    return '';
}

function normalize_kintone_record(array $record): array
{
    $organization = kintone_field_value(
        $record,
        ['組織名', 'organization', 'company', '会社名']
    );

    $name = kintone_field_value(
        $record,
        ['氏名', 'name', '名前']
    );

    $email = kintone_field_value(
        $record,
        ['メールアドレス', 'email', 'mail']
    );

    $department = kintone_field_value(
        $record,
        ['部署名', 'department', '部署']
    );

    $phone = kintone_field_value(
        $record,
        ['電話番号', 'phone', 'tel']
    );

    $address = kintone_field_value(
        $record,
        ['住所', 'address']
    );

    return [
        'id' => random_id('customer'),
        'kintoneRecordId' =>
            kintone_field_value($record, ['$id', 'id']),
        'organization' => $organization,
        'name' => $name,
        'email' => $email,
        'department' => $department,
        'phone' => $phone,
        'address' => $address,
        'raw' => $record,
        'updatedAt' => now_iso(),
    ];
}

/* ============================================================
 * POST処理
 * ============================================================ */

function handle_post(): void
{
    $action = post_string('action');

    if ($action === '') {
        return;
    }

    switch ($action) {
        case 'save_kintone':
            save_kintone_settings();
            break;

        case 'test_kintone':
            test_kintone();
            break;

        case 'sync_kintone':
            sync_kintone();
            break;

        case 'save_survey':
            handle_save_survey();
            break;

        case 'delete_survey':
            handle_delete_survey();
            break;

        case 'duplicate_survey':
            handle_duplicate_survey();
            break;

        case 'change_status':
            handle_change_status();
            break;

        case 'save_answer':
            handle_save_answer();
            break;

        case 'save_mail':
            save_mail_settings();
            break;

        default:
            set_flash(
                'error',
                '不明な操作です。'
            );
            redirect_to(current_url(['screen' => 'list']));
    }
}

/* ============================================================
 * kintone設定
 * ============================================================ */

function save_kintone_settings(): void
{
    $settings = load_settings();

    $currentPassword =
        (string)($settings['kintone']['password'] ?? '');

    $password = post_string(
        'kintone_password',
        $currentPassword
    );

    $settings['kintone'] = [
        'subdomain' =>
            post_string('kintone_subdomain'),
        'app_id' =>
            post_string('kintone_app_id'),
        'username' =>
            post_string('kintone_username'),
        'password' =>
            $password,
        'proxy' =>
            post_string('kintone_proxy'),
        'verify_ssl' =>
            isset($_POST['kintone_verify_ssl']),
    ];

    try {
        validate_kintone_settings($settings);
    } catch (Throwable $e) {
        set_flash(
            'error',
            '設定を保存できません。',
            [
                '原因' => $e->getMessage(),
            ]
        );

        redirect_to(current_url(['screen' => 'kintone']));
    }

    if (!save_json(SETTINGS_FILE, $settings)) {
        set_flash(
            'error',
            '設定ファイルを保存できませんでした。'
        );

        redirect_to(current_url(['screen' => 'kintone']));
    }

    set_flash(
        'success',
        'kintone設定を保存しました。'
    );

    redirect_to(current_url(['screen' => 'kintone']));
}

function test_kintone(): void
{
    $settings = load_settings();

    $posted = [
        'kintone' => [
            'subdomain' =>
                post_string(
                    'kintone_subdomain',
                    (string)($settings['kintone']['subdomain'] ?? '')
                ),
            'app_id' =>
                post_string(
                    'kintone_app_id',
                    (string)($settings['kintone']['app_id'] ?? '')
                ),
            'username' =>
                post_string(
                    'kintone_username',
                    (string)($settings['kintone']['username'] ?? '')
                ),
            'password' =>
                post_string(
                    'kintone_password',
                    (string)($settings['kintone']['password'] ?? '')
                ),
            'proxy' =>
                post_string(
                    'kintone_proxy',
                    (string)($settings['kintone']['proxy'] ?? '')
                ),
            'verify_ssl' =>
                isset($_POST['kintone_verify_ssl']),
        ],
    ];

    try {
        $result = kintone_connection_test($posted);

        if ($result['success']) {
            set_flash(
                'success',
                '✓ 接続テスト成功',
                $result['details']
            );
        } else {
            set_flash(
                'error',
                '✕ 接続テスト失敗',
                $result['details']
            );
        }
    } catch (Throwable $e) {
        set_flash(
            'error',
            '✕ 接続テスト失敗',
            [
                '原因' => $e->getMessage(),
                '対処' =>
                    '入力値、kintoneアプリ権限、ネットワーク、Proxy設定を確認してください。',
            ]
        );
    }

    redirect_to(current_url(['screen' => 'kintone']));
}

function sync_kintone(): void
{
    $settings = load_settings();

    try {
        $result = kintone_sync_customers($settings);

        set_flash(
            'success',
            '✓ kintone顧客同期が完了しました。',
            [
                '同期件数' => (string)$result['count'],
                'kintone総件数' => (string)$result['total'],
                '処理' =>
                    '接続テストとは独立して実行されています。',
            ]
        );
    } catch (Throwable $e) {
        set_flash(
            'error',
            '✕ kintone顧客同期に失敗しました。',
            [
                '原因' => $e->getMessage(),
            ]
        );
    }

    redirect_to(current_url(['screen' => 'kintone']));
}

/* ============================================================
 * アンケート保存
 * ============================================================ */

function handle_save_survey(): void
{
    $id = post_string('survey_id');

    $data = load_data();

    if ($id !== '') {
        $survey = find_survey($data, $id);

        if ($survey === null) {
            set_flash(
                'error',
                '対象アンケートが見つかりません。'
            );

            redirect_to(current_url(['screen' => 'list']));
        }
    } else {
        $survey = default_survey();
    }

    $survey['title'] =
        post_string('title');

    $survey['description'] =
        post_string('description');

    $survey['startAt'] =
        post_string('start_at');

    $survey['endAt'] =
        post_string('end_at');

    $numbering =
        post_string('numbering', 'global');

    $survey['numbering'] =
        in_array(
            $numbering,
            ['global', 'group'],
            true
        )
            ? $numbering
            : 'global';

    if ($id === '') {
        $survey['status'] = 'draft';
    }

    $survey['updatedAt'] = now_iso();

    renumber_questions($survey);

    if (!save_survey($survey)) {
        set_flash(
            'error',
            'アンケートを保存できませんでした。'
        );

        redirect_to(current_url(['screen' => 'list']));
    }

    set_flash(
        'success',
        'アンケートを保存しました。'
    );

    redirect_to(current_url(['screen' => 'list']));
}

function handle_delete_survey(): void
{
    $id = post_string('survey_id');

    if ($id === '') {
        set_flash(
            'error',
            '削除対象が指定されていません。'
        );

        redirect_to(current_url(['screen' => 'list']));
    }

    if (delete_survey($id)) {
        set_flash(
            'success',
            'アンケートを削除しました。'
        );
    } else {
        set_flash(
            'error',
            'アンケートを削除できませんでした。'
        );
    }

    redirect_to(current_url(['screen' => 'list']));
}

function handle_duplicate_survey(): void
{
    $id = post_string('survey_id');
    $data = load_data();

    $survey = find_survey($data, $id);

    if ($survey === null) {
        set_flash(
            'error',
            '複製対象が見つかりません。'
        );

        redirect_to(current_url(['screen' => 'list']));
    }

    $survey['id'] = random_id('survey');
    $survey['title'] =
        ($survey['title'] ?? '無題') . '（複製）';
    $survey['status'] = 'draft';
    $survey['createdAt'] = now_iso();
    $survey['updatedAt'] = now_iso();

    foreach ($survey['groups'] as &$group) {
        $group['id'] = random_id('group');

        foreach ($group['questions'] as &$question) {
            $question['id'] = random_id('question');

            foreach ($question['options'] as &$option) {
                $option['id'] = random_id('option');
                $option['nextQuestionId'] = '';
            }

            unset($option);
        }

        unset($question);
    }

    unset($group);

    renumber_questions($survey);

    $data['surveys'][] = $survey;

    save_data($data);

    set_flash(
        'success',
        'アンケートを複製しました。'
    );

    redirect_to(current_url(['screen' => 'list']));
}

function handle_change_status(): void
{
    $id = post_string('survey_id');
    $status = post_string('status');

    $data = load_data();

    $allowed = [
        'draft',
        'published',
        'stopped',
    ];

    if (!in_array($status, $allowed, true)) {
        set_flash(
            'error',
            '不正な状態変更です。'
        );

        redirect_to(current_url(['screen' => 'list']));
    }

    foreach ($data['surveys'] as &$survey) {
        if (($survey['id'] ?? '') !== $id) {
            continue;
        }

        normalize_survey_status($survey);

        if (($survey['status'] ?? '') === 'ended') {
            set_flash(
                'error',
                '終了したアンケートの状態は変更できません。'
            );

            redirect_to(current_url(['screen' => 'list']));
        }

        $current = $survey['status'] ?? 'draft';

        $valid = false;

        if ($current === 'draft' && $status === 'published') {
            $valid = true;
        }

        if (
            $current === 'published'
            && $status === 'stopped'
        ) {
            $valid = true;
        }

        if (
            $current === 'stopped'
            && $status === 'published'
        ) {
            $valid = true;
        }

        if (!$valid) {
            set_flash(
                'error',
                '許可されていない状態変更です。'
            );

            redirect_to(current_url(['screen' => 'list']));
        }

        $survey['status'] = $status;
        $survey['updatedAt'] = now_iso();

        save_data($data);

        set_flash(
            'success',
            '状態を変更しました。'
        );

        redirect_to(current_url(['screen' => 'list']));
    }

    unset($survey);

    set_flash(
        'error',
        '対象アンケートが見つかりません。'
    );

    redirect_to(current_url(['screen' => 'list']));
}

/* ============================================================
 * 回答
 * ============================================================ */

function handle_save_answer(): void
{
    $surveyId = post_string('survey_id');

    $data = load_data();

    $survey = find_survey($data, $surveyId);

    if ($survey === null) {
        set_flash(
            'error',
            'アンケートが見つかりません。'
        );

        redirect_to(current_url(['screen' => 'list']));
    }

    $answers = $_POST['answers'] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $qid = (string)$question['id'];

            if (!empty($question['required'])) {
                $value = $answers[$qid] ?? '';

                if (is_array($value)) {
                    $value = array_filter($value);
                }

                if ($value === '' || $value === []) {
                    set_flash(
                        'error',
                        '必須項目が未回答です。'
                    );

                    redirect_to(
                        current_url([
                            'screen' => 'answer',
                            'id' => $surveyId,
                        ])
                    );
                }
            }
        }
    }

    $data['answers'][] = [
        'id' => random_id('answer'),
        'surveyId' => $surveyId,
        'answers' => $answers,
        'createdAt' => now_iso(),
    ];

    if (!save_data($data)) {
        set_flash(
            'error',
            '回答を保存できませんでした。'
        );

        redirect_to(
            current_url([
                'screen' => 'answer',
                'id' => $surveyId,
            ])
        );
    }

    $_SESSION['last_answer_id'] =
        $data['answers'][array_key_last($data['answers'])]['id'];

    redirect_to(
        current_url([
            'screen' => 'complete',
            'id' => $surveyId,
        ])
    );
}

/* ============================================================
 * Mail設定
 * ============================================================ */

function save_mail_settings(): void
{
    $settings = load_settings();

    $oldPassword =
        (string)($settings['mail']['password'] ?? '');

    $password = post_string(
        'mail_password',
        $oldPassword
    );

    $settings['mail'] = [
        'host' =>
            post_string('mail_host'),
        'port' =>
            post_int('mail_port', 587),
        'encryption' =>
            post_string('mail_encryption', 'tls'),
        'auth' =>
            isset($_POST['mail_auth']),
        'username' =>
            post_string('mail_username'),
        'password' =>
            $password,
        'from' =>
            post_string('mail_from'),
        'from_name' =>
            post_string('mail_from_name'),
        'reply_to' =>
            post_string('mail_reply_to'),
    ];

    if (
        $settings['mail']['port'] < 1
        || $settings['mail']['port'] > 65535
    ) {
        set_flash(
            'error',
            'SMTPポート番号が不正です。'
        );

        redirect_to(current_url(['screen' => 'mail']));
    }

    if (!in_array(
        $settings['mail']['encryption'],
        ['ssl', 'tls', 'none'],
        true
    )) {
        set_flash(
            'error',
            '暗号化方式が不正です。'
        );

        redirect_to(current_url(['screen' => 'mail']));
    }

    if (!save_json(SETTINGS_FILE, $settings)) {
        set_flash(
            'error',
            'メール設定を保存できませんでした。'
        );

        redirect_to(current_url(['screen' => 'mail']));
    }

    set_flash(
        'success',
        'メール設定を保存しました。'
    );

    redirect_to(current_url(['screen' => 'mail']));
}

/* ============================================================
 * 画面共通
 * ============================================================ */

function render_header(
    string $title,
    bool $admin = true
): void {
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
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

html,body{
    margin:0;
    padding:0;
    background:#f8fafc;
    color:var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        sans-serif;
}

body{
    min-height:100vh;
}

a{
    color:var(--primary);
    text-decoration:none;
}

a:hover{
    text-decoration:underline;
}

.app-header{
    background:#fff;
    border-bottom:1px solid var(--border);
    position:sticky;
    top:0;
    z-index:20;
}

.header-inner{
    max-width:1280px;
    margin:0 auto;
    min-height:64px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    padding:12px 20px;
}

.brand{
    font-size:20px;
    font-weight:800;
    color:#0f172a;
}

.nav{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.nav a{
    padding:9px 12px;
    border-radius:8px;
    color:#475569;
    font-size:14px;
}

.nav a:hover{
    background:#eff6ff;
    color:var(--primary);
    text-decoration:none;
}

.container{
    width:min(1280px,calc(100% - 32px));
    margin:28px auto 60px;
}

.page-title{
    margin-bottom:20px;
}

.page-title h1{
    margin:0 0 6px;
    font-size:28px;
}

.page-title p{
    margin:0;
    color:var(--gray);
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:14px;
    box-shadow:var(--shadow);
    padding:22px;
    margin-bottom:20px;
}

.card h2,
.card h3{
    margin-top:0;
}

.toolbar{
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:center;
    flex-wrap:wrap;
    margin-bottom:16px;
}

.actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:9px 14px;
    border:1px solid var(--border);
    border-radius:8px;
    background:#fff;
    color:#334155;
    cursor:pointer;
    font-size:14px;
    font-weight:600;
    text-decoration:none;
}

.btn:hover{
    text-decoration:none;
    background:#f8fafc;
}

.btn.primary{
    background:var(--primary);
    border-color:var(--primary);
    color:#fff;
}

.btn.primary:hover{
    background:var(--primary-dark);
}

.btn.success{
    background:var(--success);
    border-color:var(--success);
    color:#fff;
}

.btn.warning{
    background:var(--warning);
    border-color:var(--warning);
    color:#fff;
}

.btn.danger{
    background:var(--danger);
    border-color:var(--danger);
    color:#fff;
}

.btn.small{
    min-height:32px;
    padding:6px 10px;
    font-size:12px;
}

form.inline{
    display:inline;
}

label{
    display:block;
    margin-bottom:7px;
    font-weight:700;
    font-size:14px;
}

input[type=text],
input[type=password],
input[type=email],
input[type=number],
input[type=datetime-local],
textarea,
select{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:10px 12px;
    font:inherit;
    background:#fff;
    color:#0f172a;
}

textarea{
    min-height:120px;
    resize:vertical;
}

input:focus,
textarea:focus,
select:focus{
    outline:3px solid rgba(37,99,235,.12);
    border-color:var(--primary);
}

.form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
}

.form-group{
    margin-bottom:18px;
}

.form-group.full{
    grid-column:1/-1;
}

.help{
    margin-top:5px;
    color:#64748b;
    font-size:12px;
}

.alert{
    border-radius:10px;
    padding:15px 17px;
    margin-bottom:18px;
    border:1px solid;
}

.alert.success{
    background:#f0fdf4;
    border-color:#86efac;
    color:#166534;
}

.alert.warning{
    background:#fffbeb;
    border-color:#fcd34d;
    color:#92400e;
}

.alert.danger{
    background:#fef2f2;
    border-color:#fca5a5;
    color:#991b1b;
}

.alert.info{
    background:#eff6ff;
    border-color:#93c5fd;
    color:#1e40af;
}

.alert-details{
    margin-top:12px;
    background:rgba(255,255,255,.65);
    border-radius:8px;
    padding:10px;
}

.alert-details div{
    display:grid;
    grid-template-columns:150px 1fr;
    gap:10px;
    padding:5px 0;
}

.alert-details span{
    font-weight:700;
}

.badge{
    display:inline-flex;
    align-items:center;
    padding:5px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}

.badge.success{
    background:#dcfce7;
    color:#166534;
}

.badge.warning{
    background:#fef3c7;
    color:#92400e;
}

.badge.dark{
    background:#e2e8f0;
    color:#334155;
}

.badge.gray{
    background:#f1f5f9;
    color:#64748b;
}

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:900px;
}

th,td{
    padding:12px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th{
    background:#f8fafc;
    color:#475569;
    font-size:13px;
}

td{
    font-size:14px;
}

.empty{
    text-align:center;
    color:#64748b;
    padding:50px 20px;
}

.stat-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
}

.stat{
    border:1px solid var(--border);
    border-radius:12px;
    padding:18px;
    background:#fff;
}

.stat-label{
    color:#64748b;
    font-size:13px;
}

.stat-value{
    font-size:30px;
    font-weight:800;
    margin-top:5px;
}

.question-card{
    border:1px solid var(--border);
    border-radius:12px;
    padding:18px;
    margin-bottom:15px;
    background:#fff;
}

.question-number{
    color:var(--primary);
    font-weight:800;
}

.question-type{
    color:#64748b;
    font-size:12px;
}

.choice{
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px;
    border:1px solid var(--border);
    border-radius:8px;
    margin:7px 0;
}

.preview{
    max-width:820px;
    margin:0 auto;
}

.mobile-preview{
    max-width:430px;
}

.footer{
    color:#64748b;
    text-align:center;
    padding:30px 10px;
    font-size:12px;
}

.spinner{
    width:16px;
    height:16px;
    border:2px solid rgba(255,255,255,.4);
    border-top-color:#fff;
    border-radius:50%;
    display:inline-block;
    animation:spin .7s linear infinite;
}

@keyframes spin{
    to{transform:rotate(360deg)}
}

@media(max-width:800px){
    .header-inner{
        align-items:flex-start;
        flex-direction:column;
    }

    .container{
        width:min(100% - 20px,1280px);
        margin-top:18px;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .form-group.full{
        grid-column:auto;
    }

    .stat-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .page-title h1{
        font-size:23px;
    }

    .card{
        padding:16px;
    }
}
</style>
</head>

<body>

<?php if ($admin): ?>
<header class="app-header">
    <div class="header-inner">
        <div class="brand"><?= h(APP_TITLE) ?></div>

        <nav class="nav">
            <a href="<?= h(current_url(['screen'=>'list'])) ?>">
                アンケート一覧
            </a>
            <a href="<?= h(current_url(['screen'=>'kintone'])) ?>">
                kintone
            </a>
            <a href="<?= h(current_url(['screen'=>'mail'])) ?>">
                メール
            </a>
        </nav>
    </div>
</header>
<?php endif; ?>

<main class="container">

<?php
    render_flash();
}

function render_footer(): void
{
?>
</main>

<footer class="footer">
    <?= h(APP_TITLE) ?> / PHP <?= h(PHP_VERSION) ?>
</footer>

<script>
document.querySelectorAll('form[data-confirm]').forEach(function(form){
    form.addEventListener('submit',function(e){
        var message = form.getAttribute('data-confirm');

        if(message && !window.confirm(message)){
            e.preventDefault();
        }
    });
});

document.querySelectorAll('form[data-loading]').forEach(function(form){
    form.addEventListener('submit',function(){
        var button = form.querySelector('button[type="submit"]');

        if(!button){
            return;
        }

        button.disabled = true;
        button.innerHTML =
            '<span class="spinner"></span> 処理中...';
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

function screen_list(): void
{
    $data = load_data();

    foreach ($data['surveys'] as &$survey) {
        normalize_survey_status($survey);
    }

    unset($survey);

    $keyword = query_string('q');
    $filter = query_string('filter', 'all');
    $sort = query_string('sort', 'updated_desc');

    $surveys = [];

    foreach ($data['surveys'] as $survey) {
        if ($keyword !== ''
            && mb_stripos(
                (string)($survey['title'] ?? ''),
                $keyword
            ) === false
        ) {
            continue;
        }

        if ($filter !== 'all'
            && ($survey['status'] ?? 'draft') !== $filter
        ) {
            continue;
        }

        $surveys[] = $survey;
    }

    usort(
        $surveys,
        function(array $a, array $b) use ($sort): int {
            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)($a['updatedAt'] ?? ''),
                        (string)($b['updatedAt'] ?? '')
                    ),
                'answers_desc' =>
                    0,
                default =>
                    strcmp(
                        (string)($b['updatedAt'] ?? ''),
                        (string)($a['updatedAt'] ?? '')
                    ),
            };
        }
    );

    render_header('アンケート一覧');
?>

<div class="page-title">
    <h1>アンケート一覧</h1>
    <p>アンケートの作成・公開・集計・送信を管理します。</p>
</div>

<div class="card">
    <div class="toolbar">
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;">
            <input type="hidden" name="screen" value="list">

            <input
                type="text"
                name="q"
                value="<?= h($keyword) ?>"
                placeholder="タイトルを検索"
                style="min-width:260px;"
            >

            <select name="filter">
                <option value="all" <?= $filter==='all'?'selected':'' ?>>
                    すべて
                </option>
                <option value="published" <?= $filter==='published'?'selected':'' ?>>
                    公開中
                </option>
                <option value="draft" <?= $filter==='draft'?'selected':'' ?>>
                    下書き
                </option>
                <option value="stopped" <?= $filter==='stopped'?'selected':'' ?>>
                    停止
                </option>
                <option value="ended" <?= $filter==='ended'?'selected':'' ?>>
                    終了
                </option>
            </select>

            <button class="btn" type="submit">
                検索
            </button>
        </form>

        <a class="btn primary"
           href="<?= h(current_url(['screen'=>'edit'])) ?>">
            ＋ 新規作成
        </a>
    </div>

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
                <td colspan="7" class="empty">
                    アンケートはありません。
                </td>
            </tr>

<?php else: ?>

<?php foreach ($surveys as $survey): ?>

<?php
$status = (string)($survey['status'] ?? 'draft');
?>

            <tr>
                <td>
                    <strong><?= h($survey['title'] ?: '無題') ?></strong>
                </td>

                <td><?= h($survey['createdAt'] ?? '') ?></td>

                <td><?= h($survey['updatedAt'] ?? '') ?></td>

                <td>
                    <?= h($survey['startAt'] ?? '-') ?>
                    <br>
                    ～
                    <br>
                    <?= h($survey['endAt'] ?? '-') ?>
                </td>

                <td>
                    <span class="<?= h(status_class($status)) ?>">
                        <?= h(status_label($status)) ?>
                    </span>
                </td>

                <td>
                    <?= h((string)survey_answer_count(
                        $data,
                        (string)$survey['id']
                    )) ?>
                </td>

                <td>
                    <div class="actions">

                        <a class="btn small"
                           href="<?= h(current_url([
                               'screen'=>'edit',
                               'id'=>$survey['id']
                           ])) ?>">
                            編集
                        </a>

                        <a class="btn small"
                           href="<?= h(current_url([
                               'screen'=>'preview',
                               'id'=>$survey['id']
                           ])) ?>">
                            プレビュー
                        </a>

                        <a class="btn small"
                           href="<?= h(current_url([
                               'screen'=>'analytics',
                               'id'=>$survey['id']
                           ])) ?>">
                            集計
                        </a>

                        <a class="btn small"
                           href="<?= h(current_url([
                               'screen'=>'send',
                               'id'=>$survey['id']
                           ])) ?>">
                            送信
                        </a>

                        <?php if ($status !== 'ended'): ?>

                            <?php if ($status === 'draft'): ?>

                            <form
                                method="post"
                                class="inline"
                                data-confirm="このアンケートを公開しますか？"
                            >
                                <input type="hidden"
                                       name="action"
                                       value="change_status">

                                <input type="hidden"
                                       name="survey_id"
                                       value="<?= h($survey['id']) ?>">

                                <input type="hidden"
                                       name="status"
                                       value="published">

                                <button class="btn small success">
                                    公開
                                </button>
                            </form>

                            <?php elseif ($status === 'published'): ?>

                            <form
                                method="post"
                                class="inline"
                                data-confirm="このアンケートを停止しますか？"
                            >
                                <input type="hidden"
                                       name="action"
                                       value="change_status">

                                <input type="hidden"
                                       name="survey_id"
                                       value="<?= h($survey['id']) ?>">

                                <input type="hidden"
                                       name="status"
                                       value="stopped">

                                <button class="btn small warning">
                                    停止
                                </button>
                            </form>

                            <?php elseif ($status === 'stopped'): ?>

                            <form
                                method="post"
                                class="inline"
                                data-confirm="このアンケートを再開しますか？"
                            >
                                <input type="hidden"
                                       name="action"
                                       value="change_status">

                                <input type="hidden"
                                       name="survey_id"
                                       value="<?= h($survey['id']) ?>">

                                <input type="hidden"
                                       name="status"
                                       value="published">

                                <button class="btn small success">
                                    再開
                                </button>
                            </form>

                            <?php endif; ?>

                        <?php endif; ?>

                        <form
                            method="post"
                            class="inline"
                            data-confirm="このアンケートを複製しますか？"
                        >
                            <input type="hidden"
                                   name="action"
                                   value="duplicate_survey">

                            <input type="hidden"
                                   name="survey_id"
                                   value="<?= h($survey['id']) ?>">

                            <button class="btn small">
                                複製
                            </button>
                        </form>

                        <form
                            method="post"
                            class="inline"
                            data-confirm="このアンケートを削除しますか？"
                        >
                            <input type="hidden"
                                   name="action"
                                   value="delete_survey">

                            <input type="hidden"
                                   name="survey_id"
                                   value="<?= h($survey['id']) ?>">

                            <button class="btn small danger">
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
render_footer();
}

/* ============================================================
 * 編集
 * ============================================================ */

function screen_edit(): void
{
    $data = load_data();
    $id = query_string('id');

    if ($id !== '') {
        $survey = find_survey($data, $id);

        if ($survey === null) {
            set_flash(
                'error',
                '対象アンケートが見つかりません。'
            );

            redirect_to(current_url(['screen'=>'list']));
        }

        normalize_survey_status($survey);
    } else {
        $survey = default_survey();
    }

    render_header(
        $id === ''
            ? 'アンケート作成'
            : 'アンケート編集'
    );
?>

<div class="page-title">
    <h1>
        <?= $id === '' ? 'アンケート作成' : 'アンケート編集' ?>
    </h1>
    <p>基本情報を設定して保存します。</p>
</div>

<div class="card">

    <div class="toolbar">
        <div>
            状態：
            <span class="<?= h(status_class(
                (string)$survey['status']
            )) ?>">
                <?= h(status_label(
                    (string)$survey['status']
                )) ?>
            </span>
        </div>

        <div class="actions">
            <a class="btn"
               href="<?= h(current_url(['screen'=>'list'])) ?>">
                キャンセル
            </a>

            <?php if ($id !== ''): ?>
            <a class="btn"
               href="<?= h(current_url([
                   'screen'=>'preview',
                   'id'=>$survey['id']
               ])) ?>">
                プレビュー
            </a>
            <?php endif; ?>
        </div>
    </div>

    <form method="post">
        <input type="hidden"
               name="action"
               value="save_survey">

        <input type="hidden"
               name="survey_id"
               value="<?= h($survey['id']) ?>">

        <div class="form-grid">

            <div class="form-group full">
                <label>アンケートタイトル</label>

                <input
                    type="text"
                    name="title"
                    maxlength="200"
                    required
                    value="<?= h($survey['title']) ?>"
                >
            </div>

            <div class="form-group full">
                <label>アンケート説明</label>

                <textarea
                    name="description"
                ><?= h($survey['description']) ?></textarea>
            </div>

            <div class="form-group">
                <label>開始日時</label>

                <input
                    type="datetime-local"
                    name="start_at"
                    value="<?= h(
                        $survey['startAt'] ?? ''
                    ) ?>"
                >
            </div>

            <div class="form-group">
                <label>終了日時</label>

                <input
                    type="datetime-local"
                    name="end_at"
                    value="<?= h(
                        $survey['endAt'] ?? ''
                    ) ?>"
                >
            </div>

            <div class="form-group">
                <label>質問番号の採番方式</label>

                <select name="numbering">
                    <option
                        value="global"
                        <?= ($survey['numbering'] ?? 'global') === 'global'
                            ? 'selected'
                            : '' ?>
                    >
                        アンケート全体で通番
                        （Q1、Q2、Q3）
                    </option>

                    <option
                        value="group"
                        <?= ($survey['numbering'] ?? '') === 'group'
                            ? 'selected'
                            : '' ?>
                    >
                        グループ毎
                        （Q1-1、Q1-2、Q2-1）
                    </option>
                </select>
            </div>

        </div>

        <div style="margin-top:15px;">
            <button class="btn primary" type="submit">
                保存して一覧へ
            </button>
        </div>
    </form>
</div>

<div class="card">
    <h2>質問・グループ</h2>

    <p class="help">
        POCでは基本情報の保存に加え、質問構造をJSONとして保持します。
    </p>

<?php
    renumber_questions($survey);

    foreach ($survey['groups'] as $group):
?>

    <div class="question-card">
        <h3><?= h($group['title']) ?></h3>

<?php foreach ($group['questions'] as $question): ?>

        <div style="
            border-top:1px solid #e2e8f0;
            padding-top:12px;
            margin-top:12px;
        ">
            <div class="question-number">
                <?= h($question['number'] ?? '') ?>
            </div>

            <div>
                <?= h(
                    $question['text']
                    ?: '質問文未設定'
                ) ?>
            </div>

            <div class="question-type">
                <?=
                    $question['type'] === 'single'
                        ? '単一選択'
                        : (
                            $question['type'] === 'multiple'
                                ? '複数選択'
                                : '自由記述'
                        )
                ?>
                /
                <?= !empty($question['required'])
                    ? '必須'
                    : '任意' ?>
            </div>
        </div>

<?php endforeach; ?>

    </div>

<?php endforeach; ?>

</div>

<?php
render_footer();
}

/* ============================================================
 * プレビュー
 * ============================================================ */

function screen_preview(): void
{
    $data = load_data();
    $id = query_string('id');

    $survey = find_survey($data, $id);

    if ($survey === null) {
        set_flash(
            'error',
            '対象アンケートが見つかりません。'
        );

        redirect_to(current_url(['screen'=>'list']));
    }

    normalize_survey_status($survey);
    renumber_questions($survey);

    render_header('プレビュー');
?>

<div class="page-title">
    <h1>プレビュー</h1>
    <p><?= h($survey['title']) ?></p>
</div>

<div class="card preview">

    <h1><?= h($survey['title']) ?></h1>

    <?php if ($survey['description'] !== ''): ?>
        <p><?= nl2br(h($survey['description'])) ?></p>
    <?php endif; ?>

<?php foreach ($survey['groups'] as $group): ?>

    <div style="margin-top:30px;">
        <h2><?= h($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>

        <div class="question-card">
            <div class="question-number">
                <?= h($question['number']) ?>
            </div>

            <h3>
                <?= h($question['text'] ?: '質問文未設定') ?>

                <?php if (!empty($question['required'])): ?>
                    <span style="color:#dc2626;">＊</span>
                <?php endif; ?>
            </h3>

<?php if ($question['type'] === 'single'): ?>

<?php foreach ($question['options'] as $option): ?>

            <div class="choice">
                <input type="radio" disabled>
                <span><?= h($option['label']) ?></span>
            </div>

<?php endforeach; ?>

<?php elseif ($question['type'] === 'multiple'): ?>

<?php foreach ($question['options'] as $option): ?>

            <div class="choice">
                <input type="checkbox" disabled>
                <span><?= h($option['label']) ?></span>
            </div>

<?php endforeach; ?>

<?php else: ?>

            <textarea
                placeholder="回答を入力"
                disabled
            ></textarea>

<?php endif; ?>

        </div>

<?php endforeach; ?>

    </div>

<?php endforeach; ?>

    <a
        class="btn primary"
        href="<?= h(current_url([
            'screen'=>'answer',
            'id'=>$survey['id']
        ])) ?>"
    >
        回答画面を開く
    </a>

</div>

<?php
render_footer();
}

/* ============================================================
 * kintone画面
 * ============================================================ */

function screen_kintone(): void
{
    $settings = load_settings();
    $k = $settings['kintone'] ?? [];
    $data = load_data();

    render_header('kintone連携設定');
?>

<div class="page-title">
    <h1>kintone連携設定</h1>
    <p>
        kintoneへの接続設定、接続テスト、顧客同期を行います。
    </p>
</div>

<div class="card">

    <form method="post" data-loading>

        <input type="hidden"
               name="action"
               value="save_kintone">

        <div class="form-grid">

            <div class="form-group">
                <label>サブドメイン</label>

                <input
                    type="text"
                    name="kintone_subdomain"
                    value="<?= h(
                        $k['subdomain'] ?? ''
                    ) ?>"
                    placeholder="xxxx.cybozu.com"
                >

                <div class="help">
                    xxxx / xxxx.cybozu.com /
                    https://xxxx.cybozu.com のいずれか
                </div>
            </div>

            <div class="form-group">
                <label>顧客管理アプリID</label>

                <input
                    type="number"
                    name="kintone_app_id"
                    min="1"
                    value="<?= h(
                        $k['app_id'] ?? ''
                    ) ?>"
                >
            </div>

            <div class="form-group">
                <label>ログイン名</label>

                <input
                    type="text"
                    name="kintone_username"
                    value="<?= h(
                        $k['username'] ?? ''
                    ) ?>"
                    autocomplete="off"
                >
            </div>

            <div class="form-group">
                <label>パスワード</label>

                <input
                    type="password"
                    name="kintone_password"
                    value=""
                    placeholder="変更しない場合は空欄でも可"
                    autocomplete="new-password"
                >

                <div class="help">
                    保存済みパスワードは画面へ再表示しません。
                </div>
            </div>

            <div class="form-group">
                <label>Proxy</label>

                <input
                    type="text"
                    name="kintone_proxy"
                    value="<?= h(
                        $k['proxy'] ?? ''
                    ) ?>"
                    placeholder="proxy.example.local:8080"
                >

                <div class="help">
                    未入力なら直接接続。
                    入力形式は host:port の1項目です。
                </div>
            </div>

            <div class="form-group">
                <label>SSL証明書検証</label>

                <label style="font-weight:400;">
                    <input
                        type="checkbox"
                        name="kintone_verify_ssl"
                        value="1"
                        <?= !empty($k['verify_ssl'])
                            ? 'checked'
                            : '' ?>
                    >
                    有効
                </label>

                <div class="help">
                    POC既定値は無効です。
                </div>
            </div>

        </div>

        <div class="actions">

            <button
                class="btn primary"
                type="submit"
            >
                設定保存
            </button>

        </div>
    </form>
</div>

<div class="card">
    <h2>kintone接続</h2>

    <p>
        接続テストと顧客情報同期は別操作です。
    </p>

    <div class="actions">

        <form method="post" data-loading>
            <input type="hidden"
                   name="action"
                   value="test_kintone">

            <input type="hidden"
                   name="kintone_subdomain"
                   value="<?= h(
                       $k['subdomain'] ?? ''
                   ) ?>">

            <input type="hidden"
                   name="kintone_app_id"
                   value="<?= h(
                       $k['app_id'] ?? ''
                   ) ?>">

            <input type="hidden"
                   name="kintone_username"
                   value="<?= h(
                       $k['username'] ?? ''
                   ) ?>">

            <input type="hidden"
                   name="kintone_password"
                   value="<?= h(
                       $k['password'] ?? ''
                   ) ?>">

            <input type="hidden"
                   name="kintone_proxy"
                   value="<?= h(
                       $k['proxy'] ?? ''
                   ) ?>">

            <input type="hidden"
                   name="kintone_verify_ssl"
                   value="<?= !empty($k['verify_ssl'])
                       ? '1'
                       : '0' ?>">

            <button class="btn primary" type="submit">
                接続テスト
            </button>
        </form>

        <form
            method="post"
            data-loading
            data-confirm="kintoneから顧客情報を取得して同期しますか？"
        >
            <input type="hidden"
                   name="action"
                   value="sync_kintone">

            <button class="btn success" type="submit">
                顧客情報を同期
            </button>
        </form>

    </div>
</div>

<div class="card">
    <h2>同期済み顧客</h2>

    <div class="stat-grid">

        <div class="stat">
            <div class="stat-label">同期件数</div>
            <div class="stat-value">
                <?= h((string)count(
                    $data['customers']
                )) ?>
            </div>
        </div>

    </div>

<?php if (!empty($data['customers'])): ?>

    <div class="table-wrap" style="margin-top:20px;">
        <table>
            <thead>
            <tr>
                <th>組織名</th>
                <th>氏名</th>
                <th>メールアドレス</th>
                <th>部署</th>
                <th>電話番号</th>
                <th>住所</th>
            </tr>
            </thead>

            <tbody>

<?php foreach ($data['customers'] as $customer): ?>

            <tr>
                <td><?= h($customer['organization'] ?? '') ?></td>
                <td><?= h($customer['name'] ?? '') ?></td>
                <td><?= h($customer['email'] ?? '') ?></td>
                <td><?= h($customer['department'] ?? '') ?></td>
                <td><?= h($customer['phone'] ?? '') ?></td>
                <td><?= h($customer['address'] ?? '') ?></td>
            </tr>

<?php endforeach; ?>

            </tbody>
        </table>
    </div>

<?php else: ?>

    <div class="empty">
        現在、同期済みの顧客データはありません。
    </div>

<?php endif; ?>

</div>

<?php
render_footer();
}

/* ============================================================
 * メール画面
 * ============================================================ */

function screen_mail(): void
{
    $settings = load_settings();
    $m = $settings['mail'] ?? [];

    render_header('メールサーバ設定');
?>

<div class="page-title">
    <h1>メールサーバ設定</h1>
    <p>SMTP接続設定を管理します。</p>
</div>

<div class="card">

<form method="post">

<input type="hidden"
       name="action"
       value="save_mail">

<div class="form-grid">

<div class="form-group">
<label>SMTPサーバ</label>
<input
    type="text"
    name="mail_host"
    value="<?= h($m['host'] ?? '') ?>"
>
</div>

<div class="form-group">
<label>SMTPポート</label>
<input
    type="number"
    name="mail_port"
    min="1"
    max="65535"
    value="<?= h($m['port'] ?? 587) ?>"
>
</div>

<div class="form-group">
<label>暗号化方式</label>

<select name="mail_encryption">
<option
    value="tls"
    <?= ($m['encryption'] ?? 'tls') === 'tls'
        ? 'selected'
        : '' ?>
>
TLS
</option>

<option
    value="ssl"
    <?= ($m['encryption'] ?? '') === 'ssl'
        ? 'selected'
        : '' ?>
>
SSL
</option>

<option
    value="none"
    <?= ($m['encryption'] ?? '') === 'none'
        ? 'selected'
        : '' ?>
>
なし
</option>

</select>
</div>

<div class="form-group">
<label>SMTP認証</label>

<label style="font-weight:400;">
<input
    type="checkbox"
    name="mail_auth"
    value="1"
    <?= !empty($m['auth']) ? 'checked' : '' ?>
>
使用する
</label>
</div>

<div class="form-group">
<label>SMTPユーザー名</label>

<input
    type="text"
    name="mail_username"
    value="<?= h($m['username'] ?? '') ?>"
>
</div>

<div class="form-group">
<label>SMTPパスワード</label>

<input
    type="password"
    name="mail_password"
    autocomplete="new-password"
    placeholder="変更しない場合は空欄"
>
</div>

<div class="form-group">
<label>送信元メールアドレス</label>

<input
    type="email"
    name="mail_from"
    value="<?= h($m['from'] ?? '') ?>"
>
</div>

<div class="form-group">
<label>送信元名</label>

<input
    type="text"
    name="mail_from_name"
    value="<?= h($m['from_name'] ?? '') ?>"
>
</div>

<div class="form-group">
<label>返信先メールアドレス</label>

<input
    type="email"
    name="mail_reply_to"
    value="<?= h($m['reply_to'] ?? '') ?>"
>
</div>

</div>

<button class="btn primary">
設定保存
</button>

</form>

</div>

<?php
render_footer();
}

/* ============================================================
 * 集計
 * ============================================================ */

function screen_analytics(): void
{
    $data = load_data();
    $id = query_string('id');

    $survey = find_survey($data, $id);

    if ($survey === null) {
        set_flash(
            'error',
            '対象アンケートが見つかりません。'
        );

        redirect_to(current_url(['screen'=>'list']));
    }

    $answers = [];

    foreach ($data['answers'] as $answer) {
        if (($answer['surveyId'] ?? '') === $id) {
            $answers[] = $answer;
        }
    }

    $total = count($answers);

    render_header('回答集計・分析');
?>

<div class="page-title">
    <h1>回答集計・分析</h1>
    <p><?= h($survey['title']) ?></p>
</div>

<div class="card">

<div class="stat-grid">

<div class="stat">
<div class="stat-label">送信対象者数</div>
<div class="stat-value">
<?= h((string)count($data['customers'])) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">回答数</div>
<div class="stat-value">
<?= h((string)$total) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">未回答数</div>
<div class="stat-value">
<?= h((string)max(
    0,
    count($data['customers']) - $total
)) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">回答率</div>
<div class="stat-value">
<?=
    count($data['customers']) > 0
        ? h(
            number_format(
                ($total / count($data['customers'])) * 100,
                1
            ) . '%'
        )
        : '0%'
?>
</div>
</div>

</div>

</div>

<div class="card">

<h2>設問別集計</h2>

<?php if ($total === 0): ?>

<div class="empty">
現在、回答データはありません
</div>

<?php else: ?>

<?php foreach ($survey['groups'] as $group): ?>

<h3><?= h($group['title']) ?></h3>

<?php foreach ($group['questions'] as $question): ?>

<div class="question-card">

<strong>
<?= h($question['number'] ?? '') ?>
<?= h($question['text'] ?? '') ?>
</strong>

<?php
$counter = [];

foreach ($answers as $answer) {
    $v = $answer['answers'][$question['id']] ?? '';

    if (is_array($v)) {
        foreach ($v as $item) {
            $counter[(string)$item] =
                ($counter[(string)$item] ?? 0) + 1;
        }
    } else {
        $counter[(string)$v] =
            ($counter[(string)$v] ?? 0) + 1;
    }
}
?>

<?php if ($question['type'] !== 'free'): ?>

<?php foreach ($question['options'] as $option): ?>

<?php
$label = (string)$option['label'];
$count = $counter[$label] ?? 0;
?>

<div style="margin-top:10px;">
<?= h($label) ?>：
<strong><?= h((string)$count) ?></strong>
</div>

<?php endforeach; ?>

<?php else: ?>

<div class="help">
自由記述回答は個別回答データで確認できます。
</div>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<?php endif; ?>

</div>

<div class="card">

<h2>個別回答</h2>

<?php if (!$answers): ?>

<div class="empty">
現在、回答データはありません
</div>

<?php else: ?>

<?php foreach ($answers as $answer): ?>

<div class="question-card">

<div>
<strong>
回答ID:
</strong>
<?= h($answer['id']) ?>
</div>

<div class="help">
<?= h($answer['createdAt'] ?? '') ?>
</div>

<pre style="
white-space:pre-wrap;
background:#f8fafc;
padding:12px;
border-radius:8px;
margin-top:12px;
"><?= h(json_encode(
    $answer['answers'],
    JSON_UNESCAPED_UNICODE |
    JSON_PRETTY_PRINT
)) ?></pre>

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php
render_footer();
}

/* ============================================================
 * 送信画面
 * ============================================================ */

function screen_send(): void
{
    $data = load_data();
    $id = query_string('id');

    $survey = find_survey($data, $id);

    if ($survey === null) {
        set_flash(
            'error',
            '対象アンケートが見つかりません。'
        );

        redirect_to(current_url(['screen'=>'list']));
    }

    render_header('顧客選択・メール送信');
?>

<div class="page-title">
    <h1>顧客選択・メール送信</h1>
    <p>
        対象アンケート：
        <strong><?= h($survey['title']) ?></strong>
    </p>
</div>

<div class="card">

<h2>顧客</h2>

<?php if (!$data['customers']): ?>

<div class="empty">
kintoneから顧客情報を同期してください。
</div>

<?php else: ?>

<form method="post">

<div class="table-wrap">

<table>
<thead>
<tr>
<th>選択</th>
<th>組織名</th>
<th>氏名</th>
<th>メールアドレス</th>
</tr>
</thead>

<tbody>

<?php foreach ($data['customers'] as $customer): ?>

<tr>
<td>
<input
    type="checkbox"
    name="customer_ids[]"
    value="<?= h($customer['id']) ?>"
>
</td>

<td><?= h($customer['organization'] ?? '') ?></td>

<td><?= h($customer['name'] ?? '') ?></td>

<td><?= h($customer['email'] ?? '') ?></td>

</tr>

<?php endforeach; ?>

</tbody>
</table>

</div>

</form>

<?php endif; ?>

</div>

<div class="card">

<h2>メール作成</h2>

<div class="form-grid">

<div class="form-group full">

<label>件名</label>

<input
    type="text"
    value="<?= h(
        $survey['title'] . ' のご案内'
    ) ?>"
>

</div>

<div class="form-group full">

<label>本文</label>

<textarea><?= h(
    "{顧客名} 様\n\n"
    . "以下のURLからアンケートへご回答ください。\n"
    . "{アンケートURL}\n"
) ?></textarea>

</div>

</div>

<div class="alert info">
この画面では対象アンケートIDをURLから固定しています。
送信履歴はこの送信画面内に表示します。
</div>

</div>

<div class="card">

<h2>送信結果</h2>

<div class="empty">
まだ送信処理は実行されていません。
</div>

</div>

<?php
render_footer();
}

/* ============================================================
 * 回答者
 * ============================================================ */

function screen_answer(): void
{
    $data = load_data();
    $id = query_string('id');

    $survey = find_survey($data, $id);

    if ($survey === null) {
        render_header('アンケート', false);

        echo '<div class="card">';
        echo '<h1>アンケートが見つかりません</h1>';
        echo '<p>指定されたアンケートは存在しません。</p>';
        echo '</div>';

        render_footer();

        return;
    }

    normalize_survey_status($survey);

    if (($survey['status'] ?? '') !== 'published') {
        render_header('アンケート', false);

        echo '<div class="card">';
        echo '<h1>回答できません</h1>';
        echo '<p>現在、このアンケートは回答受付中ではありません。</p>';
        echo '</div>';

        render_footer();

        return;
    }

    renumber_questions($survey);

    render_header('アンケート回答', false);
?>

<div class="preview">

<div class="card">

<h1><?= h($survey['title']) ?></h1>

<?php if ($survey['description'] !== ''): ?>
<p><?= nl2br(h($survey['description'])) ?></p>
<?php endif; ?>

<form
    method="post"
    action="<?= h(current_url()) ?>"
>

<input type="hidden"
       name="action"
       value="save_answer">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<?php foreach ($survey['groups'] as $group): ?>

<h2><?= h($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>

<div class="question-card">

<div class="question-number">
<?= h($question['number']) ?>
</div>

<h3>
<?= h($question['text'] ?: '質問文未設定') ?>

<?php if (!empty($question['required'])): ?>
<span style="color:#dc2626;">＊</span>
<?php endif; ?>

</h3>

<?php if ($question['type'] === 'single'): ?>

<?php foreach ($question['options'] as $option): ?>

<label class="choice"
       style="font-weight:400;">

<input
    type="radio"
    name="answers[<?= h($question['id']) ?>]"
    value="<?= h($option['label']) ?>"
    <?= !empty($question['required'])
        ? 'required'
        : '' ?>
>

<?= h($option['label']) ?>

</label>

<?php endforeach; ?>

<?php elseif ($question['type'] === 'multiple'): ?>

<?php foreach ($question['options'] as $option): ?>

<label class="choice"
       style="font-weight:400;">

<input
    type="checkbox"
    name="answers[<?= h($question['id']) ?>][]"
    value="<?= h($option['label']) ?>"
>

<?= h($option['label']) ?>

</label>

<?php endforeach; ?>

<?php else: ?>

<textarea
    name="answers[<?= h($question['id']) ?>]"
    <?= !empty($question['required'])
        ? 'required'
        : '' ?>
></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<button class="btn primary"
        type="submit">
回答を送信
</button>

</form>

</div>

</div>

<?php
render_footer();
}

/* ============================================================
 * 完了
 * ============================================================ */

function screen_complete(): void
{
    $id = query_string('id');

    $data = load_data();

    $survey = find_survey($data, $id);

    render_header('回答完了', false);
?>

<div class="preview">

<div class="card"
     style="text-align:center;">

<div style="
    font-size:56px;
    color:#16a34a;
    margin-bottom:15px;
">
✓
</div>

<h1>回答ありがとうございました</h1>

<?php if ($survey): ?>

<p>
「<?= h($survey['title']) ?>」の回答を受け付けました。
</p>

<?php endif; ?>

<p>
回答は正常に保存されました。
</p>

</div>

</div>

<?php
render_footer();
}

/* ============================================================
 * 画面ルーティング
 * ============================================================ */

try {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handle_post();
    }

    $screen = query_string('screen', 'list');

    switch ($screen) {

        case 'list':
            screen_list();
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

        case 'kintone':
            screen_kintone();
            break;

        case 'mail':
            screen_mail();
            break;

        case 'answer':
            screen_answer();
            break;

        case 'confirm':
            /*
             * POCでは回答送信時にサーバー側で検証を行う。
             * 専用の複雑な画面状態を持たせず、
             * 将来の拡張用にルートだけ確保する。
             */
            $id = query_string('id');

            redirect_to(
                current_url([
                    'screen' => 'answer',
                    'id' => $id,
                ])
            );

        case 'complete':
            screen_complete();
            break;

        default:
            set_flash(
                'warning',
                '指定された画面は存在しません。'
            );

            redirect_to(
                current_url([
                    'screen' => 'list',
                ])
            );
    }

} catch (Throwable $e) {

    /*
     * 機密情報をエラー画面へ出さない。
     * ただし、ユーザーが次に何を確認すべきかは表示する。
     */

    http_response_code(500);

    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="utf-8">
        <meta name="viewport"
              content="width=device-width,initial-scale=1">
        <title>システムエラー</title>

        <style>
        body{
            margin:0;
            padding:30px;
            background:#f8fafc;
            color:#1e293b;
            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                "Noto Sans JP",
                sans-serif;
        }

        .error{
            max-width:760px;
            margin:50px auto;
            background:#fff;
            border:1px solid #fecaca;
            border-radius:14px;
            padding:28px;
            box-shadow:0 4px 18px rgba(15,23,42,.08);
        }

        h1{
            color:#b91c1c;
        }

        .cause{
            background:#fef2f2;
            border:1px solid #fca5a5;
            border-radius:8px;
            padding:15px;
            margin:20px 0;
        }

        a{
            color:#2563eb;
        }
        </style>
    </head>

    <body>

    <div class="error">

        <h1>システムエラー</h1>

        <p>
            処理を実行できませんでした。
        </p>

        <div class="cause">
            <?= h($e->getMessage()) ?>
        </div>

        <p>
            入力値、設定値、ネットワーク設定を確認して、
            もう一度操作してください。
        </p>

        <p>
            <a href="<?= h(
                $_SERVER['SCRIPT_NAME'] ?? 'index.php'
            ) ?>">
                アンケート一覧へ戻る
            </a>
        </p>

    </div>

    </body>
    </html>
    <?php
}
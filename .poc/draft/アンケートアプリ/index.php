<?php
declare(strict_types=1);

/*
============================================================
アンケート管理システム
Apache 2.4 / PHP 8.5
単一ファイル構成
============================================================
固定名称:
survey_storage_directory
survey_storage_file
survey_admin_session_v1

PHP定数:
SURVEY_STORAGE_DIRECTORY
SURVEY_STORAGE_FILE
SURVEY_ADMIN_SESSION
============================================================
*/

const SURVEY_STORAGE_DIRECTORY = 'survey_storage_directory';
const SURVEY_STORAGE_FILE = 'survey_storage_file';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

header_remove('X-Powered-By');

/* =========================================================
 * Storage
 * ======================================================= */

function storageDir(): string
{
    return __DIR__ . '/survey_storage';
}

function storageFile(): string
{
    return storageDir() . '/survey_data.json';
}

function initialData(): array
{
    return [
        'surveys' => [],
        'responses' => [],
        'customers' => [],
        'settings' => [
            'kintone' => [],
            'smtp' => []
        ],
        'mail_logs' => []
    ];
}

function loadData(): array
{
    $file = storageFile();
    $dir = dirname($file);

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    if (!is_file($file)) {
        $data = initialData();
        saveData($data);
        return $data;
    }

    $raw = @file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        $data = initialData();
        saveData($data);
        return $data;
    }

    try {
        $data = json_decode(
            $raw,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable) {
        $data = initialData();
        saveData($data);
        return $data;
    }

    if (!is_array($data)) {
        $data = initialData();
    }

    foreach (initialData() as $key => $default) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $default;
        }
    }

    if (!is_array($data['surveys'])) {
        $data['surveys'] = [];
    }

    if (!is_array($data['responses'])) {
        $data['responses'] = [];
    }

    if (!is_array($data['customers'])) {
        $data['customers'] = [];
    }

    if (!is_array($data['mail_logs'])) {
        $data['mail_logs'] = [];
    }

    if (!is_array($data['settings'])) {
        $data['settings'] = [];
    }

    if (!isset($data['settings']['kintone']) ||
        !is_array($data['settings']['kintone'])) {
        $data['settings']['kintone'] = [];
    }

    if (!isset($data['settings']['smtp']) ||
        !is_array($data['settings']['smtp'])) {
        $data['settings']['smtp'] = [];
    }

    return $data;
}

function saveData(array $data): bool
{
    $dir = storageDir();

    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return false;
    }

    try {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT |
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable) {
        return false;
    }

    $tmp = $dir . '/survey_data.tmp.' . bin2hex(random_bytes(8));

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    try {
        json_decode(
            (string)file_get_contents($tmp),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, storageFile())) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

/* =========================================================
 * Security
 * ======================================================= */

function csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function requireCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals(csrf(), $token)) {
        jsonResponse([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。'
        ], 403);
    }
}

function makeId(string $prefix): string
{
    return $prefix . '_' .
        date('YmdHis') . '_' .
        bin2hex(random_bytes(6));
}

function safeText(mixed $value, int $max = 10000): string
{
    $value = trim((string)$value);

    if (strlen($value) > $max) {
        $value = substr($value, 0, $max);
    }

    return $value;
}

/* =========================================================
 * kintone configuration
 * ======================================================= */

/*
 * サブドメインは以下の3形式をすべて許可する。
 *
 * https://xxxx.cybozu.com
 * xxxx.cybozu.com
 * xxxx
 *
 * 保存時は xxxx に正規化する。
 */
function normalizeKintoneSubdomain(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    if (preg_match(
        '#^https?://([^/]+?)(?:/.*)?$#i',
        $value,
        $m
    )) {
        $value = $m[1];
    }

    $value = preg_replace(
        '/\.cybozu\.com$/i',
        '',
        $value
    ) ?? $value;

    $value = trim($value);

    if ($value === '' || strlen($value) > 63) {
        return null;
    }

    if (!preg_match(
        '/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/',
        $value
    )) {
        return null;
    }

    return strtolower($value);
}

/*
 * Proxyは URL ではない。
 *
 * 正しい形式:
 *
 * proxy.example.local:8080
 * 192.168.1.10:3128
 * localhost:8080
 *
 * http://
 * https://
 * /path
 * ユーザー名・パスワード付きURL
 *
 * は要求しない。
 */
function normalizeProxy(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
        return null;
    }

    if (!preg_match(
        '/^(?:(?:[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?)|(?:\d{1,3}(?:\.\d{1,3}){3})):(\d{1,5})$/',
        $value,
        $m
    )) {
        return null;
    }

    $port = (int)$m[1];

    if ($port < 1 || $port > 65535) {
        return null;
    }

    return $value;
}

function validateKintone(array $input, array $old): array
{
    $subdomain = normalizeKintoneSubdomain(
        (string)($input['subdomain'] ?? '')
    );

    if ($subdomain === null) {
        return [
            'ok' => false,
            'message' =>
                'サブドメインは https://xxxx.cybozu.com、xxxx.cybozu.com、xxxx のいずれかの形式で入力してください。'
        ];
    }

    $login = trim((string)($input['login_name'] ?? ''));

    if ($login === '') {
        return [
            'ok' => false,
            'message' => 'ログイン名を入力してください。'
        ];
    }

    $password = (string)($input['password'] ?? '');

    if ($password === '' && !empty($old['password'])) {
        $password = (string)$old['password'];
    }

    if ($password === '') {
        return [
            'ok' => false,
            'message' => 'パスワードを入力してください。'
        ];
    }

    $appId = filter_var(
        $input['app_id'] ?? '',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($appId === false) {
        return [
            'ok' => false,
            'message' =>
                '顧客管理アプリIDは1以上の整数で入力してください。'
        ];
    }

    $proxy = normalizeProxy(
        (string)($input['proxy'] ?? '')
    );

    if ($proxy === null) {
        return [
            'ok' => false,
            'message' =>
                'Proxyは host名:port番号 の形式で入力してください。例：proxy.example.com:8080'
        ];
    }

    return [
        'ok' => true,
        'data' => [
            'subdomain' => $subdomain,
            'login_name' => $login,
            'password' => $password,
            'app_id' => (int)$appId,
            'ssl_verify' => !empty($input['ssl_verify']),
            'proxy' => $proxy,

            'field_company' =>
                safeText($input['field_company'] ?? '', 255),
            'field_name' =>
                safeText($input['field_name'] ?? '', 255),
            'field_email' =>
                safeText($input['field_email'] ?? '', 255),
            'field_department' =>
                safeText($input['field_department'] ?? '', 255),
            'field_phone' =>
                safeText($input['field_phone'] ?? '', 255),
            'field_address' =>
                safeText($input['field_address'] ?? '', 255)
        ]
    ];
}

/* =========================================================
 * SMTP configuration
 * ======================================================= */

function validateSmtp(array $input, array $old): array
{
    $server = trim((string)($input['smtp_server'] ?? ''));

    if ($server === '') {
        return [
            'ok' => false,
            'message' => 'SMTPサーバを入力してください。'
        ];
    }

    $port = filter_var(
        $input['smtp_port'] ?? '',
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
                'max_range' => 65535
            ]
        ]
    );

    if ($port === false) {
        return [
            'ok' => false,
            'message' => 'SMTPポートが不正です。'
        ];
    }

    $encryption = (string)(
        $input['smtp_encryption'] ?? 'none'
    );

    if (!in_array(
        $encryption,
        ['none', 'starttls', 'ssl'],
        true
    )) {
        return [
            'ok' => false,
            'message' => '暗号化方式が不正です。'
        ];
    }

    $auth = !empty($input['smtp_auth']);

    $username = trim(
        (string)($input['smtp_username'] ?? '')
    );

    $password = (string)(
        $input['smtp_password'] ?? ''
    );

    if ($password === '' &&
        !empty($old['smtp_password'])) {
        $password = (string)$old['smtp_password'];
    }

    if ($auth && $username === '') {
        return [
            'ok' => false,
            'message' =>
                'SMTP認証を有効にする場合はユーザー名を入力してください。'
        ];
    }

    if ($auth && $password === '') {
        return [
            'ok' => false,
            'message' =>
                'SMTP認証を有効にする場合はパスワードを入力してください。'
        ];
    }

    $from = trim(
        (string)($input['smtp_from_email'] ?? '')
    );

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return [
            'ok' => false,
            'message' =>
                '送信元メールアドレスが不正です。'
        ];
    }

    $timeout = filter_var(
        $input['smtp_timeout'] ?? 10,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
                'max_range' => 300
            ]
        ]
    );

    if ($timeout === false) {
        return [
            'ok' => false,
            'message' =>
                '接続タイムアウトは1～300秒で指定してください。'
        ];
    }

    if ($encryption === 'ssl' &&
        !in_array((int)$port, [465, 587, 25], true)) {
        return [
            'ok' => false,
            'message' =>
                'SSL方式とSMTPポートの組み合わせを確認してください。'
        ];
    }

    return [
        'ok' => true,
        'data' => [
            'smtp_server' => $server,
            'smtp_port' => (int)$port,
            'smtp_encryption' => $encryption,
            'smtp_auth' => $auth,
            'smtp_username' => $username,
            'smtp_password' => $password,
            'smtp_from_email' => $from,
            'smtp_from_name' =>
                safeText($input['smtp_from_name'] ?? '', 255),
            'smtp_timeout' => (int)$timeout
        ]
    ];
}

/* =========================================================
 * Safe settings response
 * ======================================================= */

function publicKintoneSettings(array $config): array
{
    $out = $config;
    unset($out['password']);

    $out['password_configured'] =
        !empty($config['password']);

    return $out;
}

function publicSmtpSettings(array $config): array
{
    $out = $config;
    unset($out['smtp_password']);

    $out['password_configured'] =
        !empty($config['smtp_password']);

    return $out;
}

/* =========================================================
 * kintone communication
 * ======================================================= */

function kintoneBaseUrl(array $config): string
{
    return 'https://' .
        $config['subdomain'] .
        '.cybozu.com';
}

function kintoneUrl(
    array $config,
    string $path
): string {
    return kintoneBaseUrl($config) .
        '/k/v1/' .
        ltrim($path, '/');
}

function kintoneErrorType(
    int $errno,
    int $status
): string {
    if ($errno === CURLE_COULDNT_RESOLVE_HOST) {
        return 'dns';
    }

    if ($errno === CURLE_COULDNT_CONNECT) {
        return 'connection';
    }

    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        return 'timeout';
    }

    if ($errno === CURLE_SSL_CONNECT_ERROR ||
        $errno === CURLE_PEER_FAILED_VERIFICATION ||
        $errno === CURLE_SSL_CACERT) {
        return 'tls';
    }

    if ($status === 401) {
        return 'authentication';
    }

    if ($status === 403) {
        return 'authorization';
    }

    if ($status >= 400 && $status < 500) {
        return 'http_4xx';
    }

    if ($status >= 500) {
        return 'http_5xx';
    }

    return 'api';
}

function kintoneCheckItems(string $type): array
{
    return match ($type) {
        'dns' => [
            'サブドメインを確認してください。',
            'DNS設定を確認してください。'
        ],
        'connection' => [
            'kintoneの接続先を確認してください。',
            'Proxy設定を使用している場合はhost名とポート番号を確認してください。'
        ],
        'timeout' => [
            'ネットワーク接続を確認してください。',
            'Proxyまたはファイアウォール設定を確認してください。'
        ],
        'tls' => [
            'SSL証明書検証設定を確認してください。',
            'サーバー証明書とTLS設定を確認してください。'
        ],
        'authentication' => [
            'ログイン名を確認してください。',
            'パスワードを確認してください。',
            'kintone側の認証設定を確認してください。'
        ],
        'authorization' => [
            '対象アプリへの権限を確認してください。',
            'kintone側のアクセス権を確認してください。'
        ],
        default => [
            'サブドメイン、認証情報、アプリIDを確認してください。',
            'kintone側のAPI設定を確認してください。'
        ]
    };
}

function safeKintoneMessage(
    int $status,
    array $decoded
): string {
    $message = safeText(
        $decoded['message'] ?? '',
        500
    );

    if ($status === 401) {
        return 'kintone APIの認証に失敗しました。';
    }

    if ($status === 403) {
        return 'kintone APIへのアクセスが拒否されました。';
    }

    if ($status === 404) {
        return 'kintone APIまたは対象リソースが見つかりません。';
    }

    if ($status === 429) {
        return 'kintone APIのリクエスト制限に達しました。';
    }

    if ($status >= 500) {
        return 'kintone API側でサーバーエラーが発生しました。';
    }

    return $message !== ''
        ? $message
        : 'kintone APIがエラーを返しました。';
}

function kintoneRequest(
    array $config,
    string $method,
    string $path,
    ?array $body = null,
    int $timeout = 30
): array {
    $url = kintoneUrl($config, $path);

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'ok' => false,
            'error_type' => 'connection',
            'http_status' => 0,
            'message' =>
                'kintone通信を開始できませんでした。',
            'check_items' =>
                kintoneCheckItems('connection')
        ];
    }

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json'
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER =>
            !empty($config['ssl_verify']),
        CURLOPT_SSL_VERIFYHOST =>
            !empty($config['ssl_verify']) ? 2 : 0
    ];

    /*
     * Proxyは host:port を保存し、
     * CURLOPT_PROXYにはそのまま渡す。
     */
    if (!empty($config['proxy'])) {
        $options[CURLOPT_PROXY] =
            $config['proxy'];
    }

    /*
     * kintoneのユーザー名・パスワード認証。
     * APIレスポンスには絶対に返さない。
     */
    $options[CURLOPT_USERPWD] =
        $config['login_name'] .
        ':' .
        $config['password'];

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] =
            json_encode(
                $body,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if ($response === false) {
        $type = kintoneErrorType(
            $errno,
            $status
        );

        $message = match ($type) {
            'dns' =>
                'kintoneホストのDNS解決に失敗しました。',
            'connection' =>
                'kintoneサーバーへ接続できませんでした。',
            'timeout' =>
                'kintoneへの接続がタイムアウトしました。',
            'tls' =>
                'kintoneとのTLS/SSL接続に失敗しました。',
            default =>
                'kintoneとの通信に失敗しました。'
        };

        return [
            'ok' => false,
            'error_type' => $type,
            'http_status' => $status,
            'message' => $message,
            'detail' => safeText($error, 500),
            'check_items' =>
                kintoneCheckItems($type)
        ];
    }

    $decoded = json_decode(
        (string)$response,
        true
    );

    if (!is_array($decoded)) {
        $decoded = [];
    }

    if ($status < 200 || $status >= 300) {
        $type = kintoneErrorType(
            0,
            $status
        );

        return [
            'ok' => false,
            'error_type' => $type,
            'http_status' => $status,
            'message' =>
                safeKintoneMessage(
                    $status,
                    $decoded
                ),
            'check_items' =>
                kintoneCheckItems($type)
        ];
    }

    return [
        'ok' => true,
        'http_status' => $status,
        'data' => $decoded
    ];
}

/* =========================================================
 * SMTP
 * ======================================================= */

function smtpRead($socket): array
{
    $response = '';

    while (($line = fgets(
        $socket,
        4096
    )) !== false) {
        $response .= $line;

        if (
            strlen($line) >= 4 &&
            $line[3] === ' '
        ) {
            break;
        }
    }

    $code = 0;

    if (preg_match(
        '/^(\d{3})/',
        $response,
        $m
    )) {
        $code = (int)$m[1];
    }

    return [
        'code' => $code,
        'response' => trim($response)
    ];
}

function smtpCommand(
    $socket,
    string $command
): array {
    if (@fwrite(
        $socket,
        $command . "\r\n"
    ) === false) {
        return [
            'code' => 0,
            'response' => 'SMTP command write failed'
        ];
    }

    return smtpRead($socket);
}

function smtpCheckItems(string $type): array
{
    return match ($type) {
        'dns' => [
            'SMTPサーバ名を確認してください。',
            'DNS設定を確認してください。'
        ],
        'timeout' => [
            'SMTPサーバの接続状態を確認してください。',
            'ファイアウォールやProxyを確認してください。'
        ],
        'tls' => [
            '暗号化方式を確認してください。',
            'SMTPポートを確認してください。'
        ],
        'authentication' => [
            'SMTPユーザー名を確認してください。',
            'SMTPパスワードを確認してください。',
            'SMTP認証方式を確認してください。'
        ],
        'smtp_response' => [
            'SMTPサーバの応答内容を確認してください。',
            'ポートと暗号化方式を確認してください。'
        ],
        default => [
            'SMTPサーバ、ポート、暗号化方式を確認してください。'
        ]
    };
}

function smtpConnect(array $config): array
{
    $server = $config['smtp_server'];
    $port = (int)$config['smtp_port'];
    $timeout = (int)$config['smtp_timeout'];

    $target = $server;

    if ($config['smtp_encryption'] === 'ssl') {
        $target = 'ssl://' . $server;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $target . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        $type = 'connection';

        if (
            stripos($errstr, 'getaddrinfo') !== false ||
            stripos($errstr, 'resolve') !== false
        ) {
            $type = 'dns';
        } elseif (
            stripos($errstr, 'timed out') !== false
        ) {
            $type = 'timeout';
        }

        return [
            'ok' => false,
            'error_type' => $type,
            'smtp_code' => null,
            'message' => match ($type) {
                'dns' =>
                    'SMTPサーバ名のDNS解決に失敗しました。',
                'timeout' =>
                    'SMTPサーバへの接続がタイムアウトしました。',
                default =>
                    'SMTPサーバへ接続できませんでした.'
            },
            'detail' => safeText($errstr, 500),
            'check_items' =>
                smtpCheckItems($type)
        ];
    }

    stream_set_timeout(
        $socket,
        $timeout
    );

    $greeting = smtpRead($socket);

    if (
        $greeting['code'] < 200 ||
        $greeting['code'] >= 400
    ) {
        fclose($socket);

        return [
            'ok' => false,
            'error_type' => 'smtp_response',
            'smtp_code' => $greeting['code'],
            'message' =>
                'SMTPサーバから接続を拒否されました。',
            'check_items' =>
                smtpCheckItems('smtp_response')
        ];
    }

    $host = $_SERVER['SERVER_NAME'] ??
        'localhost';

    $ehlo = smtpCommand(
        $socket,
        'EHLO ' . $host
    );

    if ($ehlo['code'] >= 400) {
        $ehlo = smtpCommand(
            $socket,
            'HELO ' . $host
        );
    }

    if ($ehlo['code'] >= 400) {
        fclose($socket);

        return [
            'ok' => false,
            'error_type' => 'smtp_protocol',
            'smtp_code' => $ehlo['code'],
            'message' =>
                'SMTP EHLO/HELOに失敗しました。',
            'check_items' =>
                smtpCheckItems('smtp_protocol')
        ];
    }

    if (
        $config['smtp_encryption'] ===
        'starttls'
    ) {
        $tls = smtpCommand(
            $socket,
            'STARTTLS'
        );

        if ($tls['code'] !== 220) {
            fclose($socket);

            return [
                'ok' => false,
                'error_type' => 'tls',
                'smtp_code' => $tls['code'],
                'message' =>
                    'SMTP STARTTLSを開始できませんでした。',
                'check_items' =>
                    smtpCheckItems('tls')
            ];
        }

        $crypto =
            @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

        if ($crypto !== true) {
            fclose($socket);

            return [
                'ok' => false,
                'error_type' => 'tls',
                'smtp_code' => null,
                'message' =>
                    'SMTP TLSネゴシエーションに失敗しました。',
                'check_items' =>
                    smtpCheckItems('tls')
            ];
        }

        $ehlo = smtpCommand(
            $socket,
            'EHLO ' . $host
        );

        if ($ehlo['code'] >= 400) {
            fclose($socket);

            return [
                'ok' => false,
                'error_type' => 'smtp_protocol',
                'smtp_code' => $ehlo['code'],
                'message' =>
                    'TLS接続後のEHLOに失敗しました。',
                'check_items' =>
                    smtpCheckItems('smtp_protocol')
            ];
        }
    }

    if (!empty($config['smtp_auth'])) {
        $auth = smtpCommand(
            $socket,
            'AUTH LOGIN'
        );

        if ($auth['code'] !== 334) {
            fclose($socket);

            return [
                'ok' => false,
                'error_type' => 'authentication',
                'smtp_code' => $auth['code'],
                'message' =>
                    'SMTP認証を開始できませんでした。',
                'check_items' =>
                    smtpCheckItems('authentication')
            ];
        }

        $user = smtpCommand(
            $socket,
            base64_encode(
                $config['smtp_username']
            )
        );

        if ($user['code'] !== 334) {
            fclose($socket);

            return [
                'ok' => false,
                'error_type' => 'authentication',
                'smtp_code' => $user['code'],
                'message' =>
                    'SMTPユーザー名の認証に失敗しました。',
                'check_items' =>
                    smtpCheckItems('authentication')
            ];
        }

        $pass = smtpCommand(
            $socket,
            base64_encode(
                $config['smtp_password']
            )
        );

        if (
            $pass['code'] < 200 ||
            $pass['code'] >= 300
        ) {
            fclose($socket);

            return [
                'ok' => false,
                'error_type' => 'authentication',
                'smtp_code' => $pass['code'],
                'message' =>
                    'SMTP認証に失敗しました。',
                'check_items' =>
                    smtpCheckItems('authentication')
            ];
        }
    }

    return [
        'ok' => true,
        'socket' => $socket,
        'smtp_code' => $ehlo['code']
    ];
}

function smtpSend(
    array $config,
    string $recipient,
    string $subject,
    string $body
): array {
    if (!filter_var(
        $recipient,
        FILTER_VALIDATE_EMAIL
    )) {
        return [
            'ok' => false,
            'error_type' => 'configuration',
            'message' =>
                'テスト宛先メールアドレスが不正です。',
            'check_items' => [
                'テスト宛先を確認してください。'
            ]
        ];
    }

    $conn = smtpConnect($config);

    if (!$conn['ok']) {
        return $conn;
    }

    $socket = $conn['socket'];

    $r = smtpCommand(
        $socket,
        'MAIL FROM:<' .
        $config['smtp_from_email'] .
        '>'
    );

    if (
        $r['code'] < 200 ||
        $r['code'] >= 300
    ) {
        fclose($socket);

        return [
            'ok' => false,
            'error_type' => 'smtp_response',
            'smtp_code' => $r['code'],
            'message' =>
                'MAIL FROMがSMTPサーバに拒否されました。',
            'check_items' =>
                smtpCheckItems('smtp_response')
        ];
    }

    $r = smtpCommand(
        $socket,
        'RCPT TO:<' . $recipient . '>'
    );

    if (
        $r['code'] < 200 ||
        $r['code'] >= 300
    ) {
        fclose($socket);

        return [
            'ok' => false,
            'error_type' => 'smtp_response',
            'smtp_code' => $r['code'],
            'message' =>
                '指定された宛先がSMTPサーバに拒否されました。',
            'check_items' =>
                smtpCheckItems('smtp_response')
        ];
    }

    $r = smtpCommand(
        $socket,
        'DATA'
    );

    if ($r['code'] !== 354) {
        fclose($socket);

        return [
            'ok' => false,
            'error_type' => 'smtp_response',
            'smtp_code' => $r['code'],
            'message' =>
                'SMTP DATAを開始できませんでした。',
            'check_items' =>
                smtpCheckItems('smtp_response')
        ];
    }

    $fromName =
        $config['smtp_from_name'] !== ''
        ? '=?UTF-8?B?' .
          base64_encode(
              $config['smtp_from_name']
          ) .
          '?='
        : $config['smtp_from_email'];

    $encodedSubject =
        '=?UTF-8?B?' .
        base64_encode($subject) .
        '?=';

    $body = str_replace(
        ["\r\n", "\r"],
        "\n",
        $body
    );

    $body = str_replace(
        "\n",
        "\r\n",
        $body
    );

    $message =
        'From: ' .
        $fromName .
        ' <' .
        $config['smtp_from_email'] .
        ">\r\n" .
        'To: <' .
        $recipient .
        ">\r\n" .
        'Subject: ' .
        $encodedSubject .
        "\r\n" .
        "MIME-Version: 1.0\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\n" .
        "Content-Transfer-Encoding: 8bit\r\n" .
        "\r\n" .
        $body .
        "\r\n.\r\n";

    @fwrite($socket, $message);

    $r = smtpRead($socket);

    @smtpCommand(
        $socket,
        'QUIT'
    );

    fclose($socket);

    if (
        $r['code'] < 200 ||
        $r['code'] >= 300
    ) {
        return [
            'ok' => false,
            'error_type' => 'smtp_response',
            'smtp_code' => $r['code'],
            'message' =>
                'メール送信がSMTPサーバに拒否されました。',
            'check_items' =>
                smtpCheckItems('smtp_response')
        ];
    }

    return [
        'ok' => true,
        'smtp_code' => $r['code']
    ];
}

/* =========================================================
 * Survey normalization
 * ======================================================= */

function normalizeQuestion(array $q): array
{
    $q['id'] = safeText(
        $q['id'] ?? makeId('question'),
        100
    );

    $q['text'] = safeText(
        $q['text'] ?? '',
        5000
    );

    $q['type'] = in_array(
        $q['type'] ?? 'text',
        ['text', 'textarea', 'single', 'multiple', 'number', 'date'],
        true
    )
        ? $q['type']
        : 'text';

    $q['required'] =
        !empty($q['required']);

    $q['options'] =
        is_array($q['options'] ?? null)
        ? array_values($q['options'])
        : [];

    $q['branching'] =
        is_array($q['branching'] ?? null)
        ? $q['branching']
        : [];

    $q['other_enabled'] =
        !empty($q['other_enabled']);

    $q['number'] =
        safeText($q['number'] ?? '', 50);

    return $q;
}

function normalizeGroup(array $group): array
{
    $group['id'] = safeText(
        $group['id'] ?? makeId('group'),
        100
    );

    $group['name'] = safeText(
        $group['name'] ?? '',
        1000
    );

    $group['questions'] =
        is_array($group['questions'] ?? null)
        ? array_map(
            'normalizeQuestion',
            array_values($group['questions'])
        )
        : [];

    return $group;
}

function normalizeSurvey(array $survey): array
{
    $survey['id'] = safeText(
        $survey['id'] ?? makeId('survey'),
        100
    );

    $survey['title'] = safeText(
        $survey['title'] ?? '',
        500
    );

    $survey['start_at'] =
        safeText($survey['start_at'] ?? '', 100);

    $survey['end_at'] =
        safeText($survey['end_at'] ?? '', 100);

    $survey['status'] =
        in_array(
            $survey['status'] ?? 'draft',
            ['draft', 'active', 'ended'],
            true
        )
        ? $survey['status']
        : 'draft';

    $survey['numbering_mode'] =
        in_array(
            $survey['numbering_mode'] ?? 'global',
            ['global', 'group'],
            true
        )
        ? $survey['numbering_mode']
        : 'global';

    $survey['allow_general'] =
        !empty($survey['allow_general']);

    $survey['groups'] =
        is_array($survey['groups'] ?? null)
        ? array_map(
            'normalizeGroup',
            array_values($survey['groups'])
        )
        : [];

    $survey['other_settings'] =
        is_array(
            $survey['other_settings'] ?? null
        )
        ? $survey['other_settings']
        : [];

    $survey['deleted'] =
        !empty($survey['deleted']);

    return $survey;
}

/* =========================================================
 * Survey helpers
 * ======================================================= */

function findSurvey(
    array &$surveys,
    string $id
): ?array {
    foreach ($surveys as $i => $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return [
                'index' => $i,
                'survey' => $survey
            ];
        }
    }

    return null;
}

function renumberSurvey(array &$survey): void
{
    $global = 0;

    foreach (
        $survey['groups']
        as $gi => &$group
    ) {
        $local = 0;

        foreach (
            $group['questions']
            as &$question
        ) {
            $global++;
            $local++;

            if (
                ($survey['numbering_mode'] ?? 'global')
                === 'group'
            ) {
                $question['number'] =
                    'Q' .
                    ($gi + 1) .
                    '-' .
                    $local;
            } else {
                $question['number'] =
                    'Q' . $global;
            }
        }

        unset($question);
    }

    unset($group);
}

/* =========================================================
 * API
 * ======================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action'])
) {
    requireCsrf();

    $action = (string)$_POST['action'];
    $data = loadData();

    switch ($action) {
        case 'get_settings':
            jsonResponse([
                'ok' => true,
                'settings' => [
                    'kintone' =>
                        publicKintoneSettings(
                            $data['settings']['kintone']
                        ),
                    'smtp' =>
                        publicSmtpSettings(
                            $data['settings']['smtp']
                        )
                ]
            ]);
            break;

        case 'save_kintone_settings':
            $old =
                $data['settings']['kintone'];

            $input = [
                'subdomain' =>
                    $_POST['setting_subdomain'] ??
                    '',
                'login_name' =>
                    $_POST['setting_login_name'] ??
                    '',
                'password' =>
                    $_POST['setting_password'] ??
                    '',
                'app_id' =>
                    $_POST['setting_app_id'] ??
                    '',
                'ssl_verify' =>
                    $_POST['setting_ssl_verify'] ??
                    '',
                'proxy' =>
                    $_POST['setting_proxy'] ??
                    '',

                'field_company' =>
                    $_POST['field_company'] ?? '',
                'field_name' =>
                    $_POST['field_name'] ?? '',
                'field_email' =>
                    $_POST['field_email'] ?? '',
                'field_department' =>
                    $_POST['field_department'] ?? '',
                'field_phone' =>
                    $_POST['field_phone'] ?? '',
                'field_address' =>
                    $_POST['field_address'] ?? ''
            ];

            $result =
                validateKintone(
                    $input,
                    $old
                );

            if (!$result['ok']) {
                jsonResponse($result, 422);
            }

            $data['settings']['kintone'] =
                $result['data'];

            if (!saveData($data)) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'キントーン設定を保存できませんでした。保存先ファイルの書き込み権限を確認してください。'
                ], 500);
            }

            jsonResponse([
                'ok' => true,
                'message' =>
                    'キントーン設定を保存しました。'
            ]);
            break;

        case 'save_smtp_settings':
            $old =
                $data['settings']['smtp'];

            $input = [
                'smtp_server' =>
                    $_POST['smtp_server'] ?? '',
                'smtp_port' =>
                    $_POST['smtp_port'] ?? '',
                'smtp_encryption' =>
                    $_POST['smtp_encryption'] ??
                    'none',
                'smtp_auth' =>
                    $_POST['smtp_auth'] ?? '',
                'smtp_username' =>
                    $_POST['smtp_username'] ?? '',
                'smtp_password' =>
                    $_POST['smtp_password'] ?? '',
                'smtp_from_email' =>
                    $_POST['smtp_from_email'] ??
                    '',
                'smtp_from_name' =>
                    $_POST['smtp_from_name'] ?? '',
                'smtp_timeout' =>
                    $_POST['smtp_timeout'] ?? 10
            ];

            $result =
                validateSmtp(
                    $input,
                    $old
                );

            if (!$result['ok']) {
                jsonResponse($result, 422);
            }

            $data['settings']['smtp'] =
                $result['data'];

            if (!saveData($data)) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'SMTP設定を保存できませんでした。保存先ファイルの書き込み権限を確認してください。'
                ], 500);
            }

            jsonResponse([
                'ok' => true,
                'message' =>
                    'SMTP設定を保存しました。'
            ]);
            break;

        case 'connect_kintone':
            $config =
                $data['settings']['kintone'];

            if (empty($config)) {
                jsonResponse([
                    'ok' => false,
                    'error_type' =>
                        'configuration',
                    'message' =>
                        '保存済みのキントーン設定がありません。',
                    'check_items' => [
                        'キントーン設定を保存してください。'
                    ]
                ], 422);
            }

            $result =
                kintoneRequest(
                    $config,
                    'GET',
                    'app.json?id=' .
                    rawurlencode(
                        (string)$config['app_id']
                    )
                );

            if (!$result['ok']) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        $result['message'],
                    'error_type' =>
                        $result['error_type'],
                    'http_status' =>
                        $result['http_status'],
                    'check_items' =>
                        $result['check_items'] ??
                        []
                ]);
            }

            jsonResponse([
                'ok' => true,
                'message' =>
                    'キントーンへの接続に成功しました。',
                'http_status' =>
                    $result['http_status'],
                'subdomain' =>
                    $config['subdomain'],
                'app_id' =>
                    (int)$config['app_id']
            ]);
            break;

        case 'fetch_kintone_fields':
            $config =
                $data['settings']['kintone'];

            if (empty($config)) {
                jsonResponse([
                    'ok' => false,
                    'error_type' =>
                        'configuration',
                    'message' =>
                        '保存済みのキントーン設定がありません。',
                    'check_items' => [
                        'キントーン設定を保存してください。'
                    ]
                ], 422);
            }

            $result =
                kintoneRequest(
                    $config,
                    'GET',
                    'app/form/fields.json?app=' .
                    rawurlencode(
                        (string)$config['app_id']
                    )
                );

            if (!$result['ok']) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        $result['message'],
                    'error_type' =>
                        $result['error_type'],
                    'http_status' =>
                        $result['http_status'],
                    'check_items' =>
                        $result['check_items'] ??
                        []
                ]);
            }

            $fields = [];

            foreach (
                ($result['data']['properties'] ?? [])
                as $code => $field
            ) {
                if (!is_array($field)) {
                    continue;
                }

                $fields[] = [
                    'label' =>
                        safeText(
                            $field['label'] ?? '',
                            500
                        ),
                    'code' =>
                        safeText(
                            $code,
                            255
                        ),
                    'type' =>
                        safeText(
                            $field['type'] ?? '',
                            100
                        )
                ];
            }

            jsonResponse([
                'ok' => true,
                'message' =>
                    'kintoneフィールドを取得しました。',
                'http_status' =>
                    $result['http_status'],
                'fields' => $fields
            ]);
            break;

        case 'sync_customers':
            $config =
                $data['settings']['kintone'];

            if (empty($config)) {
                jsonResponse([
                    'ok' => false,
                    'error_type' =>
                        'configuration',
                    'message' =>
                        '保存済みのキントーン設定がありません。'
                ], 422);
            }

            $result =
                kintoneRequest(
                    $config,
                    'GET',
                    'records.json?app=' .
                    rawurlencode(
                        (string)$config['app_id']
                    ) .
                    '&query=' .
                    rawurlencode(
                        'order by $id asc limit 500'
                    )
                );

            if (!$result['ok']) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        $result['message'],
                    'error_type' =>
                        $result['error_type'],
                    'http_status' =>
                        $result['http_status'],
                    'check_items' =>
                        $result['check_items'] ??
                        []
                ]);
            }

            $records =
                is_array(
                    $result['data']['records'] ?? null
                )
                ? $result['data']['records']
                : [];

            $inserted = 0;
            $updated = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($records as $record) {
                if (!is_array($record)) {
                    $errors++;
                    continue;
                }

                $externalId =
                    (string)(
                        $record['$id']['value'] ??
                        ''
                    );

                if ($externalId === '') {
                    $skipped++;
                    continue;
                }

                $existing = null;

                foreach (
                    $data['customers']
                    as $customer
                ) {
                    if (
                        (string)(
                            $customer['kintone_id'] ??
                            ''
                        ) === $externalId
                    ) {
                        $existing = $customer;
                        break;
                    }
                }

                $customer = [
                    'id' =>
                        $existing['id'] ??
                        makeId('customer'),
                    'kintone_id' =>
                        $externalId,
                    'data' => $record,
                    'updated_at' =>
                        date(DATE_ATOM)
                ];

                if ($existing === null) {
                    $data['customers'][] =
                        $customer;
                    $inserted++;
                } else {
                    foreach (
                        $data['customers']
                        as &$stored
                    ) {
                        if (
                            (string)(
                                $stored['kintone_id'] ??
                                ''
                            ) === $externalId
                        ) {
                            $stored = $customer;
                            break;
                        }
                    }
                    unset($stored);
                    $updated++;
                }
            }

            if (!saveData($data)) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        '顧客データ同期後の保存に失敗しました。'
                ], 500);
            }

            jsonResponse([
                'ok' => true,
                'message' =>
                    '顧客データを同期しました。',
                'count' =>
                    count($records),
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors
            ]);
            break;

        case 'test_smtp_connection':
            $config =
                $data['settings']['smtp'];

            if (empty($config)) {
                jsonResponse([
                    'ok' => false,
                    'error_type' =>
                        'configuration',
                    'message' =>
                        '保存済みのSMTP設定がありません。',
                    'check_items' => [
                        'SMTP設定を保存してください。'
                    ]
                ], 422);
            }

            $result =
                smtpConnect($config);

            if (!$result['ok']) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        $result['message'],
                    'error_type' =>
                        $result['error_type'],
                    'smtp_code' =>
                        $result['smtp_code'] ??
                        null,
                    'smtp_server' =>
                        $config['smtp_server'],
                    'smtp_port' =>
                        (int)$config['smtp_port'],
                    'smtp_encryption' =>
                        $config['smtp_encryption'],
                    'check_items' =>
                        $result['check_items'] ??
                        []
                ]);
            }

            @smtpCommand(
                $result['socket'],
                'QUIT'
            );
            @fclose(
                $result['socket']
            );

            jsonResponse([
                'ok' => true,
                'message' =>
                    'SMTPサーバへの接続に成功しました。',
                'smtp_server' =>
                    $config['smtp_server'],
                'smtp_port' =>
                    (int)$config['smtp_port'],
                'smtp_encryption' =>
                    $config['smtp_encryption'],
                'authentication' =>
                    !empty($config['smtp_auth'])
                    ? '成功'
                    : 'なし'
            ]);
            break;

        case 'send_smtp_test':
            $config =
                $data['settings']['smtp'];

            $recipient =
                trim(
                    (string)(
                        $_POST['recipient'] ??
                        $_POST['test_recipient'] ??
                        ''
                    )
                );

            if ($recipient === '') {
                jsonResponse([
                    'ok' => false,
                    'error_type' =>
                        'configuration',
                    'message' =>
                        'テストメール宛先を入力してください。'
                ], 422);
            }

            if (empty($config)) {
                jsonResponse([
                    'ok' => false,
                    'error_type' =>
                        'configuration',
                    'message' =>
                        '保存済みのSMTP設定がありません。',
                    'check_items' => [
                        'SMTP設定を保存してください。'
                    ]
                ], 422);
            }

            $result =
                smtpSend(
                    $config,
                    $recipient,
                    'アンケート管理システム SMTP送信テスト',
                    "アンケート管理システムからのSMTP送信テストです。\r\n\r\nこのメールはテスト送信です。"
                );

            if (!$result['ok']) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        $result['message'],
                    'error_type' =>
                        $result['error_type'],
                    'smtp_code' =>
                        $result['smtp_code'] ??
                        null,
                    'recipient' =>
                        $recipient,
                    'check_items' =>
                        $result['check_items'] ??
                        []
                ]);
            }

            $data['mail_logs'][] = [
                'id' =>
                    makeId('mail'),
                'type' =>
                    'smtp_test',
                'recipient' =>
                    $recipient,
                'subject' =>
                    'アンケート管理システム SMTP送信テスト',
                'status' =>
                    'sent',
                'created_at' =>
                    date(DATE_ATOM)
            ];

            saveData($data);

            jsonResponse([
                'ok' => true,
                'message' =>
                    'テストメールを送信しました。',
                'recipient' =>
                    $recipient,
                'smtp_code' =>
                    $result['smtp_code']
            ]);
            break;

        case 'save_survey':
            $raw =
                (string)(
                    $_POST['survey_json'] ?? ''
                );

            try {
                $survey =
                    json_decode(
                        $raw,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
            } catch (Throwable) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'survey_jsonが不正です。'
                ], 422);
            }

            if (!is_array($survey)) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'アンケートデータが不正です。'
                ], 422);
            }

            $survey =
                normalizeSurvey($survey);

            if (!in_array(
                $survey['status'],
                ['draft', 'active', 'ended'],
                true
            )) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'ステータスが不正です。'
                ], 422);
            }

            renumberSurvey($survey);

            $found =
                findSurvey(
                    $data['surveys'],
                    $survey['id']
                );

            $survey['updated_at'] =
                date(DATE_ATOM);

            if ($found === null) {
                $survey['created_at'] =
                    date(DATE_ATOM);
                $data['surveys'][] =
                    $survey;
            } else {
                $survey['created_at'] =
                    $found['survey']['created_at'] ??
                    date(DATE_ATOM);

                $data['surveys'][
                    $found['index']
                ] = $survey;
            }

            if (!saveData($data)) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'アンケートを保存できませんでした。'
                ], 500);
            }

            jsonResponse([
                'ok' => true,
                'message' =>
                    'アンケートを保存しました。',
                'survey' => $survey
            ]);
            break;

        case 'delete_survey':
            $surveyId =
                (string)(
                    $_POST['survey_id'] ?? ''
                );

            $found =
                findSurvey(
                    $data['surveys'],
                    $surveyId
                );

            if ($found === null) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'アンケートが見つかりません。'
                ], 404);
            }

            if (
                ($found['survey']['status'] ?? '')
                !== 'draft'
            ) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        '削除できるのは下書きアンケートだけです。'
                ], 422);
            }

            $data['surveys'][
                $found['index']
            ]['deleted'] = true;

            if (!saveData($data)) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'アンケートの削除に失敗しました。'
                ], 500);
            }

            jsonResponse([
                'ok' => true,
                'message' =>
                    'アンケートを削除しました。'
            ]);
            break;

        case 'get_data':
            $surveys = array_values(
                array_filter(
                    $data['surveys'],
                    static fn($s) =>
                        empty($s['deleted'])
                )
            );

            $publicSurveys = [];

            foreach ($surveys as $survey) {
                $publicSurveys[] =
                    normalizeSurvey($survey);
            }

            jsonResponse([
                'ok' => true,
                'surveys' =>
                    $publicSurveys,
                'responses' =>
                    $data['responses'],
                'customers' =>
                    $data['customers']
            ]);
            break;

        default:
            jsonResponse([
                'ok' => false,
                'message' =>
                    '未対応のActionです。'
            ], 400);
    }
}

/* =========================================================
 * Initial page
 * ======================================================= */

$csrfToken = csrf();

?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

<style>
[x-cloak]{display:none!important}
</style>
</head>

<body class="bg-slate-100 text-slate-900">
<div id="app"></div>

<script>
window.App = {
    state: {
        initialized: false,
        screen: 'list',
        csrfToken: <?=json_encode(
            $csrfToken,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        )?>,

        surveys: [],
        responses: [],
        customers: [],

        survey: null,

        settings: {
            kintone: {},
            smtp: {}
        },

        settingsLoaded: false,

        kintoneMessage: null,
        smtpMessage: null,

        preview: false,
        responseModal: false,

        search: '',
        statusFilter: '',
        sort: 'updated_desc'
    },

    render: {},

    actions: {},

    api: {},

    utils: {},

    initSortable: function () {},

    init: function () {}
};

/* =========================================================
 * Utils
 * ======================================================= */

App.utils.escapeHTML = function(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
};

App.utils.escapeAttr = function(value) {
    return App.utils.escapeHTML(value)
        .replace(/"/g, '&quot;');
};

App.utils.statusLabel = function(status) {
    return {
        draft: '下書き',
        active: '公開中',
        ended: '終了'
    }[status] || status;
};

App.utils.uuid = function(prefix) {
    return prefix + '_' +
        Date.now() + '_' +
        Math.random()
            .toString(36)
            .slice(2, 10);
};

App.utils.confirmStatusChange = function(
    oldStatus,
    newStatus
) {
    if (
        oldStatus === 'active' &&
        newStatus === 'ended'
    ) {
        return confirm(
            'このアンケートを終了状態に変更しますか？'
        );
    }

    if (
        oldStatus === 'ended' &&
        newStatus === 'active'
    ) {
        return confirm(
            'このアンケートを公開状態に変更しますか？'
        );
    }

    return true;
};

/* =========================================================
 * API
 * ======================================================= */

App.api.request = async function(
    action,
    payload = {}
) {
    const form = new FormData();

    form.append(
        'action',
        action
    );

    form.append(
        'csrf_token',
        App.state.csrfToken
    );

    Object.entries(payload).forEach(
        ([key, value]) => {
            if (
                value !== undefined &&
                value !== null
            ) {
                form.append(
                    key,
                    typeof value === 'object'
                        ? JSON.stringify(value)
                        : String(value)
                );
            }
        }
    );

    const response = await fetch(
        location.href,
        {
            method: 'POST',
            body: form,
            credentials: 'same-origin'
        }
    );

    let json;

    try {
        json = await response.json();
    } catch (e) {
        throw new Error(
            'サーバーからJSON形式の応答を取得できませんでした。HTTPステータス: ' +
            response.status
        );
    }

    if (!response.ok && json.ok !== false) {
        json.ok = false;
        json.message =
            'HTTPエラーが発生しました。HTTPステータス: ' +
            response.status;
    }

    return json;
};

/* =========================================================
 * Survey actions
 * ======================================================= */

App.actions.newSurvey = function() {
    App.state.survey = {
        id: App.utils.uuid('survey'),
        title: '',
        start_at: '',
        end_at: '',
        status: 'draft',
        numbering_mode: 'global',
        allow_general: false,
        groups: [
            {
                id: App.utils.uuid('group'),
                name: 'ブロック1',
                questions: []
            }
        ],
        other_settings: {}
    };

    App.actions.renumberQuestions();

    App.state.screen = 'edit';

    App.render.app();
};

App.actions.editSurvey = function(id) {
    const survey =
        App.state.surveys.find(
            s => String(s.id) === String(id)
        );

    if (!survey) {
        alert('アンケートが見つかりません。');
        return;
    }

    App.state.survey =
        JSON.parse(
            JSON.stringify(survey)
        );

    App.state.screen = 'edit';

    App.render.app();
};

App.actions.changeSurveyStatus = function(
    value
) {
    if (!App.state.survey) {
        return;
    }

    const oldStatus =
        App.state.survey.status;

    if (
        oldStatus !== value &&
        !App.utils.confirmStatusChange(
            oldStatus,
            value
        )
    ) {
        App.render.app();
        return;
    }

    App.state.survey.status = value;
};

App.actions.saveSurvey = async function() {
    if (!App.state.survey) {
        return;
    }

    App.actions.renumberQuestions();

    const result =
        await App.api.request(
            'save_survey',
            {
                survey_json:
                    JSON.stringify(
                        App.state.survey
                    )
            }
        );

    if (!result.ok) {
        alert(
            result.message ||
            'アンケートを保存できませんでした。'
        );
        return;
    }

    App.state.survey =
        result.survey;

    await App.actions.loadData();

    alert(
        'アンケートを保存しました。'
    );
};

App.actions.addGroup = function() {
    if (!App.state.survey) {
        return;
    }

    const groups =
        App.state.survey.groups;

    groups.push({
        id: App.utils.uuid('group'),
        name:
            'ブロック' +
            (groups.length + 1),
        questions: []
    });

    App.actions.renumberQuestions();

    App.render.app();

    App.initSortable();
};

App.actions.addQuestion = function(
    groupId
) {
    if (!App.state.survey) {
        return;
    }

    const group =
        App.state.survey.groups.find(
            g => g.id === groupId
        );

    if (!group) {
        return;
    }

    group.questions.push({
        id: App.utils.uuid('question'),
        text: '',
        type: 'text',
        required: false,
        options: [],
        other_enabled: false,
        branching: {},
        number: ''
    });

    App.actions.renumberQuestions();

    App.render.app();

    App.initSortable();
};

App.actions.deleteQuestion = function(
    groupId,
    questionId
) {
    if (!App.state.survey) {
        return;
    }

    const group =
        App.state.survey.groups.find(
            g => g.id === groupId
        );

    if (!group) {
        return;
    }

    group.questions =
        group.questions.filter(
            q => q.id !== questionId
        );

    App.actions.removeInvalidBranching();

    App.actions.renumberQuestions();

    App.render.app();

    App.initSortable();
};

App.actions.deleteGroup = function(
    groupId
) {
    if (!App.state.survey) {
        return;
    }

    if (
        App.state.survey.groups.length <= 1
    ) {
        alert(
            '最低1つのブロックを残してください。'
        );
        return;
    }

    App.state.survey.groups =
        App.state.survey.groups.filter(
            g => g.id !== groupId
        );

    App.actions.removeInvalidBranching();

    App.actions.renumberQuestions();

    App.render.app();

    App.initSortable();
};

App.actions.addOption = function(
    groupId,
    questionId
) {
    const q =
        App.actions.findQuestion(
            questionId
        );

    if (!q) {
        return;
    }

    if (!Array.isArray(q.options)) {
        q.options = [];
    }

    q.options.push({
        id: App.utils.uuid('option'),
        text: ''
    });

    App.render.app();
};

App.actions.deleteOption = function(
    questionId,
    optionId
) {
    const q =
        App.actions.findQuestion(
            questionId
        );

    if (!q) {
        return;
    }

    q.options =
        q.options.filter(
            o => o.id !== optionId
        );

    if (q.branching) {
        delete q.branching[optionId];
    }

    App.render.app();
};

App.actions.findQuestion = function(
    questionId
) {
    if (!App.state.survey) {
        return null;
    }

    for (
        const group
        of App.state.survey.groups
    ) {
        const q =
            group.questions.find(
                x => x.id === questionId
            );

        if (q) {
            return q;
        }
    }

    return null;
};

App.actions.questionIndex = function(
    questionId
) {
    if (!App.state.survey) {
        return -1;
    }

    let index = 0;

    for (
        const group
        of App.state.survey.groups
    ) {
        for (
            const question
            of group.questions
        ) {
            if (question.id === questionId) {
                return index;
            }

            index++;
        }
    }

    return -1;
};

App.actions.allQuestions = function() {
    if (!App.state.survey) {
        return [];
    }

    return App.state.survey.groups
        .flatMap(
            g => g.questions
        );
};

App.actions.renumberQuestions =
function() {
    if (!App.state.survey) {
        return;
    }

    let globalNumber = 0;

    App.state.survey.groups
        .forEach(
            (group, gi) => {
                group.questions
                    .forEach(
                        (q, qi) => {
                            globalNumber++;

                            if (
                                App.state.survey
                                    .numbering_mode
                                === 'group'
                            ) {
                                q.number =
                                    'Q' +
                                    (gi + 1) +
                                    '-' +
                                    (qi + 1);
                            } else {
                                q.number =
                                    'Q' +
                                    globalNumber;
                            }
                        }
                    );
            }
        );
};

App.actions.removeInvalidBranching =
function() {
    const questions =
        App.actions.allQuestions();

    const ids =
        new Set(
            questions.map(q => q.id)
        );

    const positions =
        new Map();

    questions.forEach(
        (q, index) => {
            positions.set(
                q.id,
                index
            );
        }
    );

    questions.forEach(
        q => {
            if (
                !q.branching ||
                typeof q.branching !==
                    'object'
            ) {
                q.branching = {};
                return;
            }

            Object.keys(
                q.branching
            ).forEach(
                optionId => {
                    const target =
                        q.branching[
                            optionId
                        ];

                    if (
                        target === null ||
                        target === ''
                    ) {
                        return;
                    }

                    if (
                        !ids.has(target) ||
                        positions.get(target) <=
                            positions.get(q.id)
                    ) {
                        delete q.branching[
                            optionId
                        ];
                    }
                }
            );
        }
    );
};

App.actions.setQuestionField =
function(
    questionId,
    field,
    value
) {
    const q =
        App.actions.findQuestion(
            questionId
        );

    if (!q) {
        return;
    }

    if (field === 'required') {
        q.required = !!value;
    } else if (
        field === 'other_enabled'
    ) {
        q.other_enabled = !!value;
    } else {
        q[field] = value;
    }

    if (field === 'type') {
        if (value !== 'single') {
            q.branching = {};
        }
    }

    App.render.app();
};

App.actions.setOptionField =
function(
    questionId,
    optionId,
    field,
    value
) {
    const q =
        App.actions.findQuestion(
            questionId
        );

    if (!q) {
        return;
    }

    const option =
        q.options.find(
            o => o.id === optionId
        );

    if (!option) {
        return;
    }

    option[field] = value;
};

App.actions.setBranchTarget =
function(
    questionId,
    optionId,
    targetId
) {
    const q =
        App.actions.findQuestion(
            questionId
        );

    if (!q) {
        return;
    }

    if (!q.branching) {
        q.branching = {};
    }

    q.branching[optionId] =
        targetId || null;
};

/* =========================================================
 * Sortable
 * ======================================================= */

App.initSortable = function() {
    if (
        typeof Sortable ===
        'undefined'
    ) {
        return;
    }

    document
        .querySelectorAll(
            '[data-question-list]'
        )
        .forEach(
            element => {
                if (element._sortable) {
                    element._sortable.destroy();
                }

                element._sortable =
                    Sortable.create(
                        element,
                        {
                            group: {
                                name:
                                    'survey-questions',
                                pull: true,
                                put: true
                            },
                            animation: 150,
                            handle:
                                '[data-drag-handle]',
                            onEnd:
                                App.actions
                                    .handleQuestionSort
                        }
                    );
            }
        );

    const groups =
        document.querySelector(
            '[data-group-list]'
        );

    if (groups) {
        if (groups._sortable) {
            groups._sortable.destroy();
        }

        groups._sortable =
            Sortable.create(
                groups,
                {
                    animation: 150,
                    handle:
                        '[data-group-handle]',
                    onEnd:
                        App.actions
                            .handleGroupSort
                }
            );
    }
};

App.actions.handleQuestionSort =
function() {
    if (!App.state.survey) {
        return;
    }

    const nextGroups = [];

    document
        .querySelectorAll(
            '[data-group-id]'
        )
        .forEach(
            groupElement => {
                const groupId =
                    groupElement
                        .dataset
                        .groupId;

                const group =
                    App.state.survey.groups
                        .find(
                            g =>
                                g.id ===
                                groupId
                        );

                if (!group) {
                    return;
                }

                const ids =
                    Array.from(
                        groupElement
                            .querySelectorAll(
                                '[data-question-id]'
                            )
                    ).map(
                        el =>
                            el.dataset
                                .questionId
                    );

                group.questions =
                    ids.map(
                        id =>
                            group.questions
                                .find(
                                    q =>
                                        q.id ===
                                        id
                                ) ||
                            App.actions
                                .findQuestion(id)
                    ).filter(Boolean);

                nextGroups.push(group);
            }
        );

    /*
     * グループ間移動では、DOMから取得した
     * 全質問を元Stateから再構築する。
     */
    const questionMap =
        new Map(
            App.actions
                .allQuestions()
                .map(
                    q => [q.id, q]
                )
        );

    App.state.survey.groups =
        nextGroups.map(
            group => {
                group.questions =
                    Array.from(
                        document.querySelectorAll(
                            '[data-group-id="' +
                            CSS.escape(
                                group.id
                            ) +
                            '"] [data-question-id]'
                        )
                    ).map(
                        el =>
                            questionMap.get(
                                el.dataset
                                    .questionId
                            )
                    ).filter(Boolean);

                return group;
            }
        );

    App.actions.removeInvalidBranching();

    App.actions.renumberQuestions();

    App.render.app();

    App.initSortable();
};

App.actions.handleGroupSort =
function() {
    if (!App.state.survey) {
        return;
    }

    const ids =
        Array.from(
            document.querySelectorAll(
                '[data-group-list] > [data-group-id]'
            )
        ).map(
            el =>
                el.dataset.groupId
        );

    const map =
        new Map(
            App.state.survey.groups.map(
                g => [g.id, g]
            )
        );

    App.state.survey.groups =
        ids.map(
            id => map.get(id)
        ).filter(Boolean);

    App.actions.renumberQuestions();

    App.render.app();

    App.initSortable();
};

/* =========================================================
 * Settings actions
 * ======================================================= */

App.actions.loadSettings =
async function() {
    const result =
        await App.api.request(
            'get_settings'
        );

    if (!result.ok) {
        throw new Error(
            result.message ||
            '設定を取得できませんでした。'
        );
    }

    App.state.settings =
        result.settings || {
            kintone: {},
            smtp: {}
        };

    App.state.settingsLoaded =
        true;
};

App.actions.saveKintoneSettings =
async function(form) {
    const fd =
        new FormData(form);

    const result =
        await App.api.request(
            'save_kintone_settings',
            Object.fromEntries(fd.entries())
        );

    if (!result.ok) {
        App.state.kintoneMessage =
            {
                ok: false,
                ...result
            };

        App.render.app();
        return;
    }

    App.state.kintoneMessage =
        {
            ok: true,
            ...result
        };

    await App.actions.loadSettings();

    App.render.app();
};

App.actions.saveSmtpSettings =
async function(form) {
    const fd =
        new FormData(form);

    const result =
        await App.api.request(
            'save_smtp_settings',
            Object.fromEntries(fd.entries())
        );

    if (!result.ok) {
        App.state.smtpMessage =
            {
                ok: false,
                ...result
            };

        App.render.app();
        return;
    }

    App.state.smtpMessage =
        {
            ok: true,
            ...result
        };

    await App.actions.loadSettings();

    App.render.app();
};

App.actions.connectKintone =
async function() {
    const result =
        await App.api.request(
            'connect_kintone'
        );

    App.state.kintoneMessage =
        result;

    App.render.app();
};

App.actions.fetchKintoneFields =
async function() {
    const result =
        await App.api.request(
            'fetch_kintone_fields'
        );

    App.state.kintoneMessage =
        result;

    App.render.app();
};

App.actions.syncCustomers =
async function() {
    const result =
        await App.api.request(
            'sync_customers'
        );

    App.state.kintoneMessage =
        result;

    if (result.ok) {
        await App.actions.loadData();
    }

    App.render.app();
};

App.actions.testSmtpConnection =
async function() {
    const result =
        await App.api.request(
            'test_smtp_connection'
        );

    App.state.smtpMessage =
        result;

    App.render.app();
};

App.actions.sendSmtpTest =
async function() {
    const recipient =
        prompt(
            'テストメールの宛先を入力してください。'
        );

    if (!recipient) {
        return;
    }

    const result =
        await App.api.request(
            'send_smtp_test',
            {
                recipient
            }
        );

    App.state.smtpMessage =
        result;

    App.render.app();
};

/* =========================================================
 * Data
 * ======================================================= */

App.actions.loadData =
async function() {
    const result =
        await App.api.request(
            'get_data'
        );

    if (!result.ok) {
        throw new Error(
            result.message ||
            'データを取得できませんでした。'
        );
    }

    App.state.surveys =
        result.surveys || [];

    App.state.responses =
        result.responses || [];

    App.state.customers =
        result.customers || [];
};

/* =========================================================
 * Preview
 * ======================================================= */

App.actions.preview = function() {
    App.state.preview = true;
    App.render.app();
};

App.actions.closePreview =
function() {
    App.state.preview = false;
    App.render.app();
};

/* =========================================================
 * Branching response
 * ======================================================= */

App.state.responseAnswers = {};
App.state.visibleQuestionIds = [];

App.actions.updateBranchVisibility =
function() {
    if (!App.state.survey) {
        return;
    }

    const questions =
        App.actions.allQuestions();

    const visible =
        new Set();

    questions.forEach(
        q => visible.add(q.id)
    );

    questions.forEach(
        q => {
            if (
                q.type !== 'single'
            ) {
                return;
            }

            const answer =
                App.state.responseAnswers[
                    q.id
                ];

            if (!answer) {
                return;
            }

            const target =
                q.branching &&
                q.branching[
                    answer
                ];

            if (
                target === null ||
                target === undefined ||
                target === ''
            ) {
                return;
            }

            const targetIndex =
                questions.findIndex(
                    x => x.id === target
                );

            const currentIndex =
                questions.findIndex(
                    x => x.id === q.id
                );

            if (
                targetIndex < 0 ||
                targetIndex <= currentIndex
            ) {
                return;
            }

            for (
                let i =
                    currentIndex + 1;
                i < targetIndex;
                i++
            ) {
                visible.delete(
                    questions[i].id
                );
            }
        }
    );

    App.state.visibleQuestionIds =
        Array.from(visible);
};

App.actions.validateResponse =
function() {
    if (!App.state.survey) {
        return {
            ok: false,
            message:
                'アンケートがありません。'
        };
    }

    App.actions
        .updateBranchVisibility();

    const visible =
        new Set(
            App.state.visibleQuestionIds
        );

    for (
        const q
        of App.actions.allQuestions()
    ) {
        if (
            !q.required ||
            !visible.has(q.id)
        ) {
            continue;
        }

        const answer =
            App.state.responseAnswers[
                q.id
            ];

        if (
            answer === undefined ||
            answer === null ||
            answer === ''
        ) {
            return {
                ok: false,
                message:
                    q.number +
                    'の回答は必須です。'
            };
        }
    }

    return {
        ok: true
    };
};

/* =========================================================
 * Render helpers
 * ======================================================= */

App.render.statusBadge =
function(status) {
    const cls = {
        draft:
            'bg-slate-100 text-slate-700',
        active:
            'bg-emerald-100 text-emerald-700',
        ended:
            'bg-red-100 text-red-700'
    }[status] ||
        'bg-slate-100 text-slate-700';

    return `
        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${cls}">
            ${App.utils.escapeHTML(
                App.utils.statusLabel(status)
            )}
        </span>
    `;
};

/* =========================================================
 * Settings render
 * ======================================================= */

App.render.settings =
function() {
    const k =
        App.state.settings.kintone ||
        {};

    const s =
        App.state.settings.smtp ||
        {};

    return `
    <div class="mx-auto max-w-7xl p-6">

        <div class="mb-6">
            <div class="mb-2 text-sm text-slate-500">
                <button
                    class="hover:text-blue-600"
                    onclick="App.actions.goList()">
                    ホーム
                </button>
                <span class="mx-2">＞</span>
                <span>キントーン・メール設定</span>
            </div>

            <h1 class="text-2xl font-bold">
                キントーン・メール設定
            </h1>
        </div>

        <div class="space-y-6">

            <section class="rounded-xl bg-white p-6 shadow">
                <h2 class="mb-5 text-xl font-bold">
                    キントーン設定
                </h2>

                <form
                    id="kintone_settings_form"
                    onsubmit="
                        event.preventDefault();
                        App.actions.saveKintoneSettings(this)
                    "
                    class="space-y-4">

                    <div>
                        <label class="mb-1 block text-sm font-medium">
                            サブドメイン
                        </label>
                        <input
                            name="setting_subdomain"
                            id="setting_subdomain"
                            value="${App.utils.escapeAttr(
                                k.subdomain || ''
                            )}"
                            placeholder="https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx"
                            class="w-full rounded-lg border p-2.5"
                            required>
                        <p class="mt-1 text-xs text-slate-500">
                            https://xxxx.cybozu.com、xxxx.cybozu.com、xxxx のいずれも入力できます。
                        </p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">
                                ログイン名
                            </label>
                            <input
                                name="setting_login_name"
                                id="setting_login_name"
                                value="${App.utils.escapeAttr(
                                    k.login_name || ''
                                )}"
                                class="w-full rounded-lg border p-2.5"
                                required>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">
                                パスワード
                            </label>
                            <input
                                type="password"
                                name="setting_password"
                                id="setting_password"
                                autocomplete="new-password"
                                placeholder="${
                                    k.password_configured
                                        ? '変更しない場合は空欄'
                                        : ''
                                }"
                                class="w-full rounded-lg border p-2.5">
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">
                                顧客管理アプリID
                            </label>
                            <input
                                type="number"
                                min="1"
                                name="setting_app_id"
                                id="setting_app_id"
                                value="${App.utils.escapeAttr(
                                    k.app_id || ''
                                )}"
                                class="w-full rounded-lg border p-2.5"
                                required>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">
                                Proxy
                            </label>
                            <input
                                name="setting_proxy"
                                id="setting_proxy"
                                value="${App.utils.escapeAttr(
                                    k.proxy || ''
                                )}"
                                placeholder="proxy.example.com:8080"
                                class="w-full rounded-lg border p-2.5">
                            <p class="mt-1 text-xs text-slate-500">
                                host名:port番号。http:// / https:// は付けません。
                            </p>
                        </div>
                    </div>

                    <div>
                        <label class="inline-flex items-center gap-2">
                            <input
                                type="checkbox"
                                name="setting_ssl_verify"
                                id="setting_ssl_verify"
                                value="1"
                                ${k.ssl_verify ? 'checked' : ''}>
                            <span>
                                SSL証明書検証
                            </span>
                        </label>
                        <p class="mt-1 text-xs text-slate-500">
                            デフォルトは検証なしです。
                        </p>
                    </div>

                    <div class="border-t pt-4">
                        <h3 class="mb-3 font-semibold">
                            顧客フィールド
                        </h3>

                        <div class="grid gap-4 md:grid-cols-2">
                            ${[
                                ['field_company','会社名'],
                                ['field_name','氏名'],
                                ['field_email','メール'],
                                ['field_department','部署'],
                                ['field_phone','電話'],
                                ['field_address','住所']
                            ].map(
                                ([name,label]) => `
                                <div>
                                    <label class="mb-1 block text-sm">
                                        ${label}
                                    </label>
                                    <input
                                        name="${name}"
                                        value="${App.utils.escapeAttr(
                                            k[name] || ''
                                        )}"
                                        class="w-full rounded-lg border p-2.5">
                                </div>
                            `
                            ).join('')}
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2 pt-2">
                        <button
                            id="kintone_save_button"
                            class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700">
                            設定を保存
                        </button>

                        <button
                            type="button"
                            onclick="App.actions.connectKintone()"
                            class="rounded-lg bg-slate-800 px-4 py-2 font-semibold text-white hover:bg-slate-900">
                            キントーン接続確認
                        </button>

                        <button
                            type="button"
                            onclick="App.actions.fetchKintoneFields()"
                            class="rounded-lg border px-4 py-2 font-semibold">
                            フィールド取得
                        </button>

                        <button
                            type="button"
                            onclick="App.actions.syncCustomers()"
                            class="rounded-lg border px-4 py-2 font-semibold">
                            顧客データを同期
                        </button>
                    </div>
                </form>

                <div
                    id="kintone_message"
                    class="mt-5">
                    ${
                        App.render.result(
                            App.state.kintoneMessage
                        )
                    }
                </div>
            </section>

            <section class="rounded-xl bg-white p-6 shadow">
                <h2 class="mb-5 text-xl font-bold">
                    SMTP設定
                </h2>

                <form
                    id="smtp_settings_form"
                    onsubmit="
                        event.preventDefault();
                        App.actions.saveSmtpSettings(this)
                    "
                    class="space-y-4">

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">
                                SMTPサーバ
                            </label>
                            <input
                                name="smtp_server"
                                value="${App.utils.escapeAttr(
                                    s.smtp_server || ''
                                )}"
                                class="w-full rounded-lg border p-2.5"
                                required>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">
                                SMTPポート
                            </label>
                            <input
                                type="number"
                                min="1"
                                max="65535"
                                name="smtp_port"
                                value="${App.utils.escapeAttr(
                                    s.smtp_port || ''
                                )}"
                                class="w-full rounded-lg border p-2.5"
                                required>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">
                                暗号化方式
                            </label>
                            <select
                                name="smtp_encryption"
                                class="w-full rounded-lg border p-2.5">
                                <option
                                    value="none"
                                    ${s.smtp_encryption === 'none' || !s.smtp_encryption ? 'selected' : ''}>
                                    none
                                </option>
                                <option
                                    value="starttls"
                                    ${s.smtp_encryption === 'starttls' ? 'selected' : ''}>
                                    starttls
                                </option>
                                <option
                                    value="ssl"
                                    ${s.smtp_encryption === 'ssl' ? 'selected' : ''}>
                                    ssl
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">
                                接続タイムアウト
                            </label>
                            <input
                                type="number"
                                min="1"
                                max="300"
                                name="smtp_timeout"
                                value="${App.utils.escapeAttr(
                                    s.smtp_timeout || 10
                                )}"
                                class="w-full rounded-lg border p-2.5">
                        </div>
                    </div>

                    <div>
                        <label class="inline-flex items-center gap-2">
                            <input
                                type="checkbox"
                                name="smtp_auth"
                                value="1"
                                ${s.smtp_auth ? 'checked' : ''}>
                            <span>SMTP認証</span>
                        </label>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">
                                SMTPユーザー名
                            </label>
                            <input
                                name="smtp_username"
                                value="${App.utils.escapeAttr(
                                    s.smtp_username || ''
                                )}"
                                class="w-full rounded-lg border p-2.5">
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">
                                SMTPパスワード
                            </label>
                            <input
                                type="password"
                                name="smtp_password"
                                autocomplete="new-password"
                                placeholder="${
                                    s.password_configured
                                        ? '変更しない場合は空欄'
                                        : ''
                                }"
                                class="w-full rounded-lg border p-2.5">
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">
                                送信元メールアドレス
                            </label>
                            <input
                                type="email"
                                name="smtp_from_email"
                                value="${App.utils.escapeAttr(
                                    s.smtp_from_email || ''
                                )}"
                                class="w-full rounded-lg border p-2.5"
                                required>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">
                                送信元表示名
                            </label>
                            <input
                                name="smtp_from_name"
                                value="${App.utils.escapeAttr(
                                    s.smtp_from_name || ''
                                )}"
                                class="w-full rounded-lg border p-2.5">
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2 pt-2">
                        <button
                            id="smtp_save_button"
                            class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700">
                            設定を保存
                        </button>

                        <button
                            type="button"
                            onclick="App.actions.testSmtpConnection()"
                            class="rounded-lg bg-slate-800 px-4 py-2 font-semibold text-white hover:bg-slate-900">
                            SMTP接続確認
                        </button>

                        <button
                            type="button"
                            onclick="App.actions.sendSmtpTest()"
                            class="rounded-lg border px-4 py-2 font-semibold">
                            テストメール送信
                        </button>
                    </div>
                </form>

                <div
                    id="smtp_message"
                    class="mt-5">
                    ${
                        App.render.result(
                            App.state.smtpMessage
                        )
                    }
                </div>
            </section>

        </div>
    </div>
    `;
};

App.render.result =
function(result) {
    if (!result) {
        return '';
    }

    const ok =
        result.ok === true;

    return `
        <div class="rounded-lg border ${
            ok
                ? 'border-emerald-200 bg-emerald-50'
                : 'border-red-200 bg-red-50'
        } p-4">
            <div class="font-bold ${
                ok
                    ? 'text-emerald-800'
                    : 'text-red-800'
            }">
                ${ok ? '成功' : '失敗'}
            </div>

            <div class="mt-2 whitespace-pre-wrap text-sm">
                ${App.utils.escapeHTML(
                    result.message || ''
                )}
            </div>

            ${
                result.http_status !==
                undefined &&
                result.http_status !== null
                    ? `
                    <div class="mt-2 text-sm">
                        HTTPステータス：
                        <strong>
                            ${App.utils.escapeHTML(
                                result.http_status
                            )}
                        </strong>
                    </div>
                    `
                    : ''
            }

            ${
                result.smtp_code !==
                undefined &&
                result.smtp_code !== null
                    ? `
                    <div class="mt-2 text-sm">
                        SMTP応答：
                        <strong>
                            ${App.utils.escapeHTML(
                                result.smtp_code
                            )}
                        </strong>
                    </div>
                    `
                    : ''
            }

            ${
                result.error_type
                    ? `
                    <div class="mt-2 text-sm">
                        エラー種別：
                        <strong>
                            ${App.utils.escapeHTML(
                                result.error_type
                            )}
                        </strong>
                    </div>
                    `
                    : ''
            }

            ${
                result.subdomain
                    ? `
                    <div class="mt-2 text-sm">
                        サブドメイン：
                        ${App.utils.escapeHTML(
                            result.subdomain
                        )}
                    </div>
                    `
                    : ''
            }

            ${
                result.app_id
                    ? `
                    <div class="mt-1 text-sm">
                        対象アプリID：
                        ${App.utils.escapeHTML(
                            result.app_id
                        )}
                    </div>
                    `
                    : ''
            }

            ${
                result.recipient
                    ? `
                    <div class="mt-1 text-sm">
                        宛先：
                        ${App.utils.escapeHTML(
                            result.recipient
                        )}
                    </div>
                    `
                    : ''
            }

            ${
                Array.isArray(
                    result.check_items
                ) &&
                result.check_items.length
                    ? `
                    <div class="mt-3">
                        <div class="font-semibold">
                            確認事項：
                        </div>
                        <ul class="mt-1 list-disc pl-5 text-sm">
                            ${result.check_items.map(
                                item =>
                                    `<li>${App.utils.escapeHTML(item)}</li>`
                            ).join('')}
                        </ul>
                    </div>
                    `
                    : ''
            }

            ${
                Array.isArray(result.fields)
                    ? `
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b">
                                    <th class="p-2 text-left">
                                        label
                                    </th>
                                    <th class="p-2 text-left">
                                        code
                                    </th>
                                    <th class="p-2 text-left">
                                        type
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                ${result.fields.map(
                                    field => `
                                    <tr class="border-b">
                                        <td class="p-2">
                                            ${App.utils.escapeHTML(field.label)}
                                        </td>
                                        <td class="p-2">
                                            ${App.utils.escapeHTML(field.code)}
                                        </td>
                                        <td class="p-2">
                                            ${App.utils.escapeHTML(field.type)}
                                        </td>
                                    </tr>
                                `
                                ).join('')}
                            </tbody>
                        </table>
                    </div>
                    `
                    : ''
            }

            ${
                result.count !==
                undefined
                    ? `
                    <div class="mt-4 grid gap-2 md:grid-cols-5 text-sm">
                        <div>
                            取得件数：
                            <strong>${result.count}</strong>
                        </div>
                        <div>
                            追加：
                            <strong>${result.inserted}</strong>
                        </div>
                        <div>
                            更新：
                            <strong>${result.updated}</strong>
                        </div>
                        <div>
                            スキップ：
                            <strong>${result.skipped}</strong>
                        </div>
                        <div>
                            エラー：
                            <strong>${result.errors}</strong>
                        </div>
                    </div>
                    `
                    : ''
            }
        </div>
    `;
};

/* =========================================================
 * List render
 * ======================================================= */

App.render.list =
function() {
    let surveys =
        App.state.surveys.filter(
            s => !s.deleted
        );

    const keyword =
        App.state.search
            .trim()
            .toLowerCase();

    if (keyword) {
        surveys =
            surveys.filter(
                s =>
                    String(s.title || '')
                        .toLowerCase()
                        .includes(keyword)
            );
    }

    if (App.state.statusFilter) {
        surveys =
            surveys.filter(
                s =>
                    s.status ===
                    App.state.statusFilter
            );
    }

    surveys.sort(
        (a, b) => {
            const aa =
                a.updated_at || '';
            const bb =
                b.updated_at || '';

            return App.state.sort ===
                'title'
                ? String(a.title)
                    .localeCompare(
                        String(b.title),
                        'ja'
                    )
                : String(bb)
                    .localeCompare(
                        String(aa)
                    );
        }
    );

    return `
    <div class="mx-auto max-w-7xl p-6">

        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold">
                    アンケート一覧
                </h1>
            </div>

            <button
                onclick="App.actions.newSurvey()"
                class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white">
                ＋ 新規作成
            </button>
        </div>

        <div class="mb-4 flex flex-wrap gap-3 rounded-xl bg-white p-4 shadow">
            <input
                value="${App.utils.escapeAttr(
                    App.state.search
                )}"
                oninput="
                    App.state.search=this.value;
                    App.render.app()
                "
                placeholder="アンケートを検索"
                class="rounded-lg border p-2.5">

            <select
                onchange="
                    App.state.statusFilter=this.value;
                    App.render.app()
                "
                class="rounded-lg border p-2.5">
                <option value="">すべて</option>
                <option
                    value="draft"
                    ${App.state.statusFilter === 'draft' ? 'selected' : ''}>
                    下書き
                </option>
                <option
                    value="active"
                    ${App.state.statusFilter === 'active' ? 'selected' : ''}>
                    公開中
                </option>
                <option
                    value="ended"
                    ${App.state.statusFilter === 'ended' ? 'selected' : ''}>
                    終了
                </option>
            </select>

            <select
                onchange="
                    App.state.sort=this.value;
                    App.render.app()
                "
                class="rounded-lg border p-2.5">
                <option
                    value="updated_desc"
                    ${App.state.sort === 'updated_desc' ? 'selected' : ''}>
                    更新日時順
                </option>
                <option
                    value="title"
                    ${App.state.sort === 'title' ? 'selected' : ''}>
                    タイトル順
                </option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-xl bg-white shadow">
            <table class="min-w-full">
                <thead class="border-b bg-slate-50">
                    <tr>
                        <th class="p-4 text-left">
                            タイトル
                        </th>
                        <th class="p-4 text-left">
                            ステータス
                        </th>
                        <th class="p-4 text-left">
                            更新日時
                        </th>
                        <th class="p-4 text-left">
                            操作
                        </th>
                    </tr>
                </thead>

                <tbody>
                    ${
                        surveys.length
                            ? surveys.map(
                                survey =>
                                    App.render
                                        .surveyRow(
                                            survey
                                        )
                            ).join('')
                            : `
                                <tr>
                                    <td
                                        colspan="4"
                                        class="p-8 text-center text-slate-500">
                                        アンケートがありません。
                                    </td>
                                </tr>
                            `
                    }
                </tbody>
            </table>
        </div>
    </div>
    `;
};

App.render.surveyRow =
function(survey) {
    const buttons = [];

    buttons.push(`
        <button
            onclick="App.actions.editSurvey('${App.utils.escapeAttr(survey.id)}')"
            class="rounded-lg border px-3 py-1.5 text-sm">
            確認・編集
        </button>
    `);

    if (
        survey.status === 'active' ||
        survey.status === 'ended'
    ) {
        buttons.push(`
            <button
                onclick="App.actions.showAggregation('${App.utils.escapeAttr(survey.id)}')"
                class="rounded-lg border px-3 py-1.5 text-sm">
                集計
            </button>
        `);
    }

    if (survey.status === 'active') {
        buttons.push(`
            <button
                onclick="App.actions.showSend('${App.utils.escapeAttr(survey.id)}')"
                class="rounded-lg border px-3 py-1.5 text-sm">
                送信
            </button>
        `);
    }

    if (survey.status === 'draft') {
        buttons.push(`
            <button
                onclick="App.actions.deleteSurvey('${App.utils.escapeAttr(survey.id)}')"
                class="rounded-lg border border-red-300 px-3 py-1.5 text-sm text-red-600">
                削除
            </button>
        `);
    }

    buttons.push(`
        <button
            onclick="App.actions.duplicateSurvey('${App.utils.escapeAttr(survey.id)}')"
            class="rounded-lg border px-3 py-1.5 text-sm">
            複製
        </button>
    `);

    return `
        <tr class="border-b">
            <td class="p-4 font-medium">
                ${App.utils.escapeHTML(
                    survey.title ||
                    '無題のアンケート'
                )}
            </td>

            <td class="p-4">
                ${App.render.statusBadge(
                    survey.status
                )}
            </td>

            <td class="p-4 text-sm text-slate-500">
                ${App.utils.escapeHTML(
                    survey.updated_at || ''
                )}
            </td>

            <td class="p-4">
                <div class="flex flex-wrap gap-2">
                    ${buttons.join('')}
                </div>
            </td>
        </tr>
    `;
};

/* =========================================================
 * Editor
 * ======================================================= */

App.render.editor =
function() {
    const survey =
        App.state.survey;

    if (!survey) {
        return '';
    }

    return `
    <div class="mx-auto max-w-7xl p-6">

        <div class="mb-6">
            <div class="mb-2 text-sm text-slate-500">
                <button
                    onclick="App.actions.goList()"
                    class="hover:text-blue-600">
                    ホーム
                </button>
                <span class="mx-2">＞</span>
                <span>アンケート一覧</span>
                <span class="mx-2">＞</span>
                <span>確認・編集</span>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-2xl font-bold">
                    アンケート作成・編集
                </h1>

                <div class="flex gap-2">
                    <button
                        onclick="App.actions.preview()"
                        class="rounded-lg border px-4 py-2">
                        プレビュー
                    </button>

                    <button
                        onclick="App.actions.saveSurvey()"
                        class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white">
                        保存
                    </button>
                </div>
            </div>
        </div>

        <div class="space-y-6">

            <section class="rounded-xl bg-white p-6 shadow">
                <h2 class="mb-4 text-lg font-bold">
                    基本設定
                </h2>

                <div class="grid gap-4 md:grid-cols-2">

                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-medium">
                            タイトル
                        </label>
                        <input
                            id="survey_title"
                            value="${App.utils.escapeAttr(
                                survey.title
                            )}"
                            oninput="
                                App.state.survey.title=this.value
                            "
                            class="w-full rounded-lg border p-2.5">
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">
                            開始日時
                        </label>
                        <input
                            id="survey_start_at"
                            type="datetime-local"
                            value="${App.utils.escapeAttr(
                                survey.start_at
                            )}"
                            onchange="
                                App.state.survey.start_at=this.value
                            "
                            class="w-full rounded-lg border p-2.5">
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">
                            終了日時
                        </label>
                        <input
                            id="survey_end_at"
                            type="datetime-local"
                            value="${App.utils.escapeAttr(
                                survey.end_at
                            )}"
                            onchange="
                                App.state.survey.end_at=this.value
                            "
                            class="w-full rounded-lg border p-2.5">
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">
                            ステータス
                        </label>
                        <select
                            id="survey_status"
                            onchange="
                                App.actions.changeSurveyStatus(this.value)
                            "
                            class="w-full rounded-lg border p-2.5">
                            <option
                                value="draft"
                                ${survey.status === 'draft' ? 'selected' : ''}>
                                下書き
                            </option>
                            <option
                                value="active"
                                ${survey.status === 'active' ? 'selected' : ''}>
                                公開中
                            </option>
                            <option
                                value="ended"
                                ${survey.status === 'ended' ? 'selected' : ''}>
                                終了
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">
                            質問番号形式
                        </label>
                        <select
                            id="survey_numbering_mode"
                            onchange="
                                App.state.survey.numbering_mode=this.value;
                                App.actions.renumberQuestions();
                                App.render.app()
                            "
                            class="w-full rounded-lg border p-2.5">
                            <option
                                value="global"
                                ${survey.numbering_mode === 'global' ? 'selected' : ''}>
                                Q1 / Q2 / Q3
                            </option>
                            <option
                                value="group"
                                ${survey.numbering_mode === 'group' ? 'selected' : ''}>
                                Q1-1 / Q1-2 / Q2-1
                            </option>
                        </select>
                    </div>

                    <div class="md:col-span-2">
                        <label class="inline-flex items-center gap-2">
                            <input
                                type="checkbox"
                                ${survey.allow_general ? 'checked' : ''}
                                onchange="
                                    App.state.survey.allow_general=this.checked
                                ">
                            <span>
                                一般回答を許可する
                            </span>
                        </label>
                    </div>
                </div>
            </section>

            <section class="space-y-5">
                <div
                    data-group-list
                    class="space-y-5">

                    ${survey.groups.map(
                        (group, gi) =>
                            App.render.group(
                                group,
                                gi
                            )
                    ).join('')}

                </div>

                <button
                    onclick="App.actions.addGroup()"
                    class="w-full rounded-xl border-2 border-dashed border-slate-300 bg-white px-4 py-4 font-semibold hover:border-blue-400 hover:text-blue-600">
                    ＋ ブロックを追加
                </button>
            </section>

            <div class="flex justify-end gap-2">
                <button
                    onclick="App.actions.goList()"
                    class="rounded-lg border px-4 py-2">
                    戻る
                </button>

                <button
                    onclick="App.actions.saveSurvey()"
                    class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white">
                    保存
                </button>
            </div>
        </div>
    </div>

    ${
        App.state.preview
            ? App.render.previewModal()
            : ''
    }
    `;
};

App.render.group =
function(group, gi) {
    return `
    <section
        data-group-id="${App.utils.escapeAttr(group.id)}"
        class="rounded-xl bg-white p-5 shadow">

        <div class="mb-4 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span
                    data-group-handle
                    class="cursor-move text-slate-400">
                    ☷
                </span>

                <input
                    value="${App.utils.escapeAttr(
                        group.name
                    )}"
                    oninput="
                        const g=App.state.survey.groups.find(x=>x.id==='${App.utils.escapeAttr(group.id)}');
                        if(g) g.name=this.value
                    "
                    class="rounded-lg border px-3 py-2 font-semibold">
            </div>

            <button
                onclick="App.actions.deleteGroup('${App.utils.escapeAttr(group.id)}')"
                class="text-sm text-red-600">
                グループ削除
            </button>
        </div>

        <div
            data-question-list
            class="space-y-4">

            ${group.questions.map(
                question =>
                    App.render.question(
                        group,
                        question
                    )
            ).join('')}

        </div>

        <button
            onclick="App.actions.addQuestion('${App.utils.escapeAttr(group.id)}')"
            class="mt-4 w-full rounded-lg border-2 border-dashed border-slate-300 px-4 py-3 font-semibold hover:border-blue-400 hover:text-blue-600">
            ＋ 質問を追加
        </button>
    </section>
    `;
};

App.render.question =
function(group, q) {
    return `
    <div
        data-question-id="${App.utils.escapeAttr(q.id)}"
        class="rounded-lg border bg-slate-50 p-4">

        <div class="mb-3 flex items-start justify-between gap-3">
            <div class="flex items-center gap-2">
                <span
                    data-drag-handle
                    class="cursor-move text-slate-400">
                    ☷
                </span>

                <span class="font-bold">
                    ${App.utils.escapeHTML(
                        q.number || ''
                    )}
                </span>
            </div>

            <button
                onclick="
                    App.actions.deleteQuestion(
                        '${App.utils.escapeAttr(group.id)}',
                        '${App.utils.escapeAttr(q.id)}'
                    )
                "
                class="text-sm text-red-600">
                質問削除
            </button>
        </div>

        <div class="space-y-3">

            <textarea
                class="w-full rounded-lg border p-3"
                rows="2"
                placeholder="質問文"
                oninput="
                    App.actions.setQuestionField(
                        '${App.utils.escapeAttr(q.id)}',
                        'text',
                        this.value
                    )
                ">${App.utils.escapeHTML(
                    q.text || ''
                )}</textarea>

            <div class="grid gap-3 md:grid-cols-3">

                <div>
                    <label class="mb-1 block text-sm">
                        質問形式
                    </label>
                    <select
                        onchange="
                            App.actions.setQuestionField(
                                '${App.utils.escapeAttr(q.id)}',
                                'type',
                                this.value
                            )
                        "
                        class="w-full rounded-lg border p-2.5">
                        ${[
                            ['text','短文'],
                            ['textarea','長文'],
                            ['single','単一選択'],
                            ['multiple','複数選択'],
                            ['number','数値'],
                            ['date','日付']
                        ].map(
                            ([value,label]) => `
                            <option
                                value="${value}"
                                ${q.type === value ? 'selected' : ''}>
                                ${label}
                            </option>
                        `
                        ).join('')}
                    </select>
                </div>

                <div class="flex items-end">
                    <label class="inline-flex items-center gap-2">
                        <input
                            type="checkbox"
                            ${q.required ? 'checked' : ''}
                            onchange="
                                App.actions.setQuestionField(
                                    '${App.utils.escapeAttr(q.id)}',
                                    'required',
                                    this.checked
                                )
                            ">
                        <span>必須回答</span>
                    </label>
                </div>

                <div class="flex items-end">
                    <label class="inline-flex items-center gap-2">
                        <input
                            type="checkbox"
                            ${q.other_enabled ? 'checked' : ''}
                            onchange="
                                App.actions.setQuestionField(
                                    '${App.utils.escapeAttr(q.id)}',
                                    'other_enabled',
                                    this.checked
                                )
                            ">
                        <span>その他を許可</span>
                    </label>
                </div>
            </div>

            ${
                q.type === 'single' ||
                q.type === 'multiple'
                    ? App.render.options(q)
                    : ''
            }

        </div>
    </div>
    `;
};

App.render.options =
function(q) {
    return `
    <div class="rounded-lg border bg-white p-4">
        <div class="mb-3 flex items-center justify-between">
            <div class="font-semibold">
                選択肢
            </div>

            <button
                onclick="
                    App.actions.addOption(
                        '',
                        '${App.utils.escapeAttr(q.id)}'
                    )
                "
                class="text-sm text-blue-600">
                ＋ 選択肢追加
            </button>
        </div>

        <div class="space-y-3">
            ${
                q.options.length
                    ? q.options.map(
                        option =>
                            App.render.option(
                                q,
                                option
                            )
                    ).join('')
                    : `
                        <div class="text-sm text-slate-500">
                            選択肢を追加してください。
                        </div>
                    `
            }
        </div>
    </div>
    `;
};

App.render.option =
function(q, option) {
    const candidates =
        App.actions
            .allQuestions()
            .filter(
                candidate =>
                    candidate.id !== q.id &&
                    App.actions.questionIndex(
                        candidate.id
                    ) >
                    App.actions.questionIndex(
                        q.id
                    )
            );

    const target =
        q.branching &&
        q.branching[
            option.id
        ];

    return `
    <div class="grid gap-3 md:grid-cols-[1fr_1fr_auto]">
        <input
            value="${App.utils.escapeAttr(
                option.text || ''
            )}"
            oninput="
                App.actions.setOptionField(
                    '${App.utils.escapeAttr(q.id)}',
                    '${App.utils.escapeAttr(option.id)}',
                    'text',
                    this.value
                )
            "
            class="rounded-lg border p-2.5"
            placeholder="選択肢内容">

        ${
            q.type === 'single'
                ? `
                <select
                    onchange="
                        App.actions.setBranchTarget(
                            '${App.utils.escapeAttr(q.id)}',
                            '${App.utils.escapeAttr(option.id)}',
                            this.value
                        )
                    "
                    class="rounded-lg border p-2.5">
                    <option value="">
                        表示しない
                    </option>

                    ${candidates.map(
                        candidate =>
                            `<option
                                value="${App.utils.escapeAttr(candidate.id)}"
                                ${target === candidate.id ? 'selected' : ''}>
                                ${App.utils.escapeHTML(
                                    candidate.number
                                )}：
                                ${App.utils.escapeHTML(
                                    candidate.text
                                )}
                            </option>`
                    ).join('')}
                </select>
                `
                : `
                    <div></div>
                `
        }

        <button
            onclick="
                App.actions.deleteOption(
                    '${App.utils.escapeAttr(q.id)}',
                    '${App.utils.escapeAttr(option.id)}'
                )
            "
            class="rounded-lg border border-red-300 px-3 text-red-600">
            削除
        </button>
    </div>
    `;
};

/* =========================================================
 * Preview
 * ======================================================= */

App.render.previewModal =
function() {
    const survey =
        App.state.survey;

    return `
    <div
        id="preview_modal"
        class="fixed inset-0 z-50 overflow-y-auto bg-black/50 p-4">

        <div class="mx-auto max-w-3xl rounded-xl bg-white shadow-xl">

            <div class="flex items-center justify-between border-b p-5">
                <h2 class="text-xl font-bold">
                    プレビュー
                </h2>

                <button
                    onclick="App.actions.closePreview()"
                    class="text-slate-500">
                    ✕
                </button>
            </div>

            <div
                id="preview_content"
                class="p-6">
                <h1 class="mb-2 text-2xl font-bold">
                    ${App.utils.escapeHTML(
                        survey.title ||
                        '無題のアンケート'
                    )}
                </h1>

                <div class="mb-6">
                    ${App.render.statusBadge(
                        survey.status
                    )}
                </div>

                <div class="space-y-6">
                    ${survey.groups.map(
                        group =>
                            group.questions
                                .map(
                                    q =>
                                        App.render
                                            .previewQuestion(
                                                q
                                            )
                                )
                                .join('')
                    ).join('')}
                </div>
            </div>
        </div>
    </div>
    `;
};

App.render.previewQuestion =
function(q) {
    return `
    <div class="rounded-lg border p-4">
        <div class="mb-3 font-semibold">
            ${App.utils.escapeHTML(
                q.number
            )}：
            ${App.utils.escapeHTML(
                q.text
            )}
            ${
                q.required
                    ? '<span class="text-red-600"> *</span>'
                    : ''
            }
        </div>

        ${
            q.type === 'single'
                ? `
                <div class="space-y-2">
                    ${q.options.map(
                        option => `
                        <label class="flex items-center gap-2">
                            <input
                                type="radio"
                                name="preview_${App.utils.escapeAttr(q.id)}">
                            <span>
                                ${App.utils.escapeHTML(
                                    option.text
                                )}
                            </span>
                        </label>
                    `
                    ).join('')}
                </div>
                `
                : q.type === 'multiple'
                    ? `
                    <div class="space-y-2">
                        ${q.options.map(
                            option => `
                            <label class="flex items-center gap-2">
                                <input
                                    type="checkbox">
                                <span>
                                    ${App.utils.escapeHTML(
                                        option.text
                                    )}
                                </span>
                            </label>
                        `
                        ).join('')}
                    </div>
                    `
                    : q.type === 'textarea'
                        ? `
                            <textarea
                                class="w-full rounded-lg border p-3"
                                rows="4"></textarea>
                        `
                        : `
                            <input
                                class="w-full rounded-lg border p-2.5"
                                type="${
                                    q.type === 'number'
                                        ? 'number'
                                        : q.type === 'date'
                                            ? 'date'
                                            : 'text'
                                }">
                        `
        }
    </div>
    `;
};

/* =========================================================
 * Navigation / list actions
 * ======================================================= */

App.actions.goList =
function() {
    App.state.screen = 'list';
    App.state.preview = false;
    App.render.app();
};

App.actions.openSettings =
async function() {
    App.state.screen =
        'settings';

    if (!App.state.settingsLoaded) {
        try {
            await App.actions
                .loadSettings();
        } catch (e) {
            alert(e.message);
        }
    }

    App.render.app();
};

App.actions.duplicateSurvey =
function(id) {
    const source =
        App.state.surveys.find(
            s => s.id === id
        );

    if (!source) {
        return;
    }

    const copy =
        JSON.parse(
            JSON.stringify(source)
        );

    copy.id =
        App.utils.uuid('survey');

    copy.title =
        (copy.title || '') +
        '（複製）';

    copy.status =
        'draft';

    copy.created_at =
        new Date().toISOString();

    copy.updated_at =
        new Date().toISOString();

    copy.groups =
        copy.groups.map(
            group => ({
                ...group,
                id:
                    App.utils.uuid('group'),
                questions:
                    group.questions.map(
                        q => ({
                            ...q,
                            id:
                                App.utils.uuid(
                                    'question'
                                ),
                            options:
                                (q.options || [])
                                    .map(
                                        o => ({
                                            ...o,
                                            id:
                                                App.utils.uuid(
                                                    'option'
                                                )
                                        })
                                    ),
                            branching: {}
                        })
                    )
            })
        );

    App.state.survey = copy;

    App.actions.renumberQuestions();

    App.state.screen =
        'edit';

    App.render.app();
};

App.actions.deleteSurvey =
async function(id) {
    if (
        !confirm(
            'この下書きアンケートを削除しますか？'
        )
    ) {
        return;
    }

    const result =
        await App.api.request(
            'delete_survey',
            {
                survey_id: id
            }
        );

    if (!result.ok) {
        alert(
            result.message ||
            '削除に失敗しました。'
        );
        return;
    }

    await App.actions.loadData();

    App.render.app();
};

App.actions.showAggregation =
function(id) {
    alert(
        '集計画面を表示します。対象アンケートID: ' +
        id
    );
};

App.actions.showSend =
function(id) {
    alert(
        '送信画面を表示します。対象アンケートID: ' +
        id
    );
};

/* =========================================================
 * Header
 * ======================================================= */

App.render.header =
function() {
    return `
    <header class="sticky top-0 z-40 bg-slate-900 text-white shadow">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-3">
            <div class="font-bold">
                アンケート管理システム
            </div>

            <nav class="flex flex-wrap items-center gap-2 text-sm">
                <button
                    onclick="App.actions.goList()"
                    class="rounded-lg px-3 py-2 hover:bg-white/10">
                    アンケート一覧
                </button>

                <button
                    onclick="App.actions.openSettings()"
                    class="rounded-lg px-3 py-2 hover:bg-white/10">
                    キントーン・メール設定
                </button>

                <button
                    onclick="App.actions.logout()"
                    class="rounded-lg px-3 py-2 hover:bg-white/10">
                    ログアウト
                </button>
            </nav>
        </div>
    </header>
    `;
};

App.actions.logout =
function() {
    alert(
        'ログアウト処理を実行します。'
    );
};

/* =========================================================
 * Main render
 * ======================================================= */

App.render.app =
function() {
    const app =
        document.getElementById('app');

    if (!app) {
        return;
    }

    let content = '';

    switch (
        App.state.screen
    ) {
        case 'settings':
            content =
                App.render.settings();
            break;

        case 'edit':
            content =
                App.render.editor();
            break;

        case 'list':
        default:
            content =
                App.render.list();
            break;
    }

    app.innerHTML =
        App.render.header() +
        content;

    if (
        App.state.screen === 'edit'
    ) {
        App.initSortable();
    }
};

/* =========================================================
 * Init
 * ======================================================= */

App.init = async function() {
    if (
        App.state.initialized
    ) {
        return;
    }

    App.state.initialized =
        true;

    try {
        await App.actions.loadData();
    } catch (e) {
        console.error(e);
    }

    App.render.app();
};

if (
    document.readyState ===
    'loading'
) {
    document.addEventListener(
        'DOMContentLoaded',
        () => App.init(),
        { once: true }
    );
} else {
    App.init();
}
</script>
</body>
</html>
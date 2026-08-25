<?php
declare(strict_types=1);

/*
 * アンケート管理システム
 * Apache 2.4 / PHP 8.5
 * 単一ファイル構成
 *
 * 固定名称:
 * survey_storage_directory
 * survey_storage_file
 * survey_admin_session_v1
 */

const SURVEY_STORAGE_DIRECTORY = 'survey_storage_directory';
const SURVEY_STORAGE_FILE = 'survey_storage_file';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();
header_remove('X-Powered-By');

function storageDir(): string {
    return __DIR__ . '/survey_storage';
}

function storageFile(): string {
    return storageDir() . '/survey_data.json';
}

function initialData(): array {
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

function loadData(): array {
    $file = storageFile();

    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0775, true);
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

function saveData(array $data): bool {
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

function jsonResponse(array $data, int $status = 200): never {
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

function csrf(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function requireCsrf(): void {
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals(csrf(), $token)) {
        jsonResponse([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。'
        ], 403);
    }
}

function makeId(string $prefix): string {
    return $prefix . '_' .
        date('YmdHis') . '_' .
        bin2hex(random_bytes(6));
}

function safeSubdomain(string $value): ?string {
    $value = trim($value);

    /*
     * 以下を許可:
     * xxxx
     * xxxx.cybozu.com
     * https://xxxx.cybozu.com
     */
    $value = preg_replace('#^https?://#i', '', $value);
    $value = preg_replace('#/.*$#', '', $value);
    $value = strtolower(trim((string)$value));

    if (str_ends_with($value, '.cybozu.com')) {
        $value = substr(
            $value,
            0,
            -strlen('.cybozu.com')
        );
    }

    if ($value === '' ||
        strlen($value) > 63 ||
        !preg_match(
            '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
            $value
        )) {
        return null;
    }

    return $value;
}

function safeProxy(string $value): ?string {
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    /*
     * host:port
     */
    if (!preg_match(
        '/^(?:[a-zA-Z0-9.-]+|\[[0-9a-fA-F:]+\]):([0-9]{1,5})$/',
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

function validateKintone(array $input, array $old): array {
    $subdomain = safeSubdomain(
        (string)($input['subdomain'] ?? '')
    );

    if ($subdomain === null) {
        return [
            'ok' => false,
            'message' =>
                'サブドメインは xxxx、xxxx.cybozu.com、https://xxxx.cybozu.com のいずれかで入力してください。'
        ];
    }

    $login = trim(
        (string)($input['login_name'] ?? '')
    );

    if ($login === '') {
        return [
            'ok' => false,
            'message' => 'ログイン名を入力してください。'
        ];
    }

    $password = (string)($input['password'] ?? '');

    if ($password === '' &&
        !empty($old['password'])) {
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
        [
            'options' => [
                'min_range' => 1
            ]
        ]
    );

    if ($appId === false) {
        return [
            'ok' => false,
            'message' =>
                '顧客管理アプリIDは1以上の整数で入力してください。'
        ];
    }

    $proxy = safeProxy(
        (string)($input['proxy'] ?? '')
    );

    if ($proxy === null) {
        return [
            'ok' => false,
            'message' =>
                'Proxyは host名:port番号 の形式で入力してください。'
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
                trim((string)($input['field_company'] ?? '')),
            'field_name' =>
                trim((string)($input['field_name'] ?? '')),
            'field_email' =>
                trim((string)($input['field_email'] ?? '')),
            'field_department' =>
                trim((string)($input['field_department'] ?? '')),
            'field_phone' =>
                trim((string)($input['field_phone'] ?? '')),
            'field_address' =>
                trim((string)($input['field_address'] ?? ''))
        ]
    ];
}

function validateSmtp(array $input, array $old): array {
    $server = trim(
        (string)($input['smtp_server'] ?? '')
    );

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
                'SMTP認証を有効にする場合はSMTPユーザー名が必要です。'
        ];
    }

    if ($auth && $password === '') {
        return [
            'ok' => false,
            'message' =>
                'SMTP認証を有効にする場合はSMTPパスワードが必要です。'
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
        (int)$port !== 465) {
        return [
            'ok' => false,
            'message' =>
                'SSL方式では通常465番ポートを使用します。'
        ];
    }

    if ($encryption === 'starttls' &&
        (int)$port === 465) {
        return [
            'ok' => false,
            'message' =>
                'STARTTLSと465番ポートの組み合わせは不自然です。'
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
                trim((string)(
                    $input['smtp_from_name'] ?? ''
                )),
            'smtp_timeout' => (int)$timeout
        ]
    ];
}

function publicSettings(array $settings): array {
    $result = $settings;

    if (isset($result['kintone']['password'])) {
        unset($result['kintone']['password']);
        $result['kintone']['password_configured'] = true;
    } else {
        $result['kintone']['password_configured'] = false;
    }

    if (isset($result['smtp']['smtp_password'])) {
        unset($result['smtp']['smtp_password']);
        $result['smtp']['smtp_password_configured'] = true;
    } else {
        $result['smtp']['smtp_password_configured'] = false;
    }

    return $result;
}

function kintoneUrl(array $config, string $path): string {
    return 'https://' .
        $config['subdomain'] .
        '.cybozu.com/k/v1/' .
        ltrim($path, '/');
}

function kintoneErrorType(
    int $status,
    int $errno
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

    if ($errno === CURLE_SSL_CONNECT_ERROR) {
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

function kintoneCheckItems(
    string $type
): array {
    return match ($type) {
        'dns' => [
            'サブドメイン',
            'DNS設定'
        ],
        'authentication' => [
            'ログイン名',
            'パスワード',
            'kintone側の認証設定'
        ],
        'authorization' => [
            'ログインユーザーの権限',
            '対象アプリへのアクセス権'
        ],
        'tls' => [
            'SSL証明書検証設定',
            'サーバー側のTLS設定'
        ],
        'proxy' => [
            'Proxyのホスト名',
            'Proxyのポート',
            'ネットワーク設定'
        ],
        'timeout' => [
            'ネットワーク接続',
            'Proxy',
            'タイムアウト設定'
        ],
        default => [
            'サブドメイン',
            'ログイン名',
            'パスワード',
            '顧客管理アプリID',
            'kintone側の権限',
            'Proxy',
            'SSL証明書検証'
        ]
    };
}

function kintoneRequest(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $url = kintoneUrl($config, $path);

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'ok' => false,
            'error_type' => 'connection',
            'http_status' => 0,
            'message' =>
                'kintone通信の初期化に失敗しました。',
            'check_items' => [
                'PHP cURL拡張',
                'サーバー設定'
            ]
        ];
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER =>
            !empty($config['ssl_verify']),
        CURLOPT_SSL_VERIFYHOST =>
            !empty($config['ssl_verify']) ? 2 : 0,
        CURLOPT_USERPWD =>
            $config['login_name'] .
            ':' .
            $config['password']
    ];

    if (!empty($config['proxy'])) {
        [$proxyHost, $proxyPort] =
            explode(':', $config['proxy'], 2);

        $options[CURLOPT_PROXY] = $proxyHost;
        $options[CURLOPT_PROXYPORT] = (int)$proxyPort;
    }

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if ($response === false) {
        $type = kintoneErrorType(
            $status,
            $errno
        );

        $message = match ($type) {
            'dns' =>
                'kintoneホストのDNS解決に失敗しました。',
            'connection' =>
                'kintoneサーバーへTCP接続できませんでした。',
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
            'check_items' =>
                kintoneCheckItems($type),
            'detail' => $curlError
        ];
    }

    $decoded = json_decode(
        $response,
        true
    );

    if ($status < 200 || $status >= 300) {
        $type = kintoneErrorType(
            $status,
            0
        );

        $apiMessage = '';

        if (is_array($decoded)) {
            $apiMessage = trim(
                (string)($decoded['message'] ?? '')
            );
        }

        if ($type === 'authentication') {
            $message =
                'kintone APIの認証に失敗しました。';
        } elseif ($type === 'authorization') {
            $message =
                'kintone APIへのアクセス権がありません。';
        } elseif ($status === 404) {
            $message =
                'kintone APIまたは対象アプリが見つかりません。';
        } elseif ($status === 429) {
            $message =
                'kintone APIのリクエスト制限に達しました。';
        } elseif ($status >= 500) {
            $message =
                'kintoneサーバー側でエラーが発生しました。';
        } else {
            $message =
                'kintone APIがエラーを返しました。';
        }

        return [
            'ok' => false,
            'error_type' => $type,
            'http_status' => $status,
            'message' => $message,
            'api_summary' =>
                mb_substr($apiMessage, 0, 500),
            'check_items' =>
                kintoneCheckItems($type)
        ];
    }

    return [
        'ok' => true,
        'http_status' => $status,
        'data' =>
            is_array($decoded) ? $decoded : []
    ];
}

function smtpRead($socket): array {
    $response = '';

    while (($line = fgets($socket, 4096)) !== false) {
        $response .= $line;

        if (strlen($line) >= 4 &&
            $line[3] === ' ') {
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
        'response' =>
            trim(
                preg_replace(
                    '/\s+/',
                    ' ',
                    $response
                )
            )
    ];
}

function smtpCommand(
    $socket,
    string $command
): array {
    fwrite(
        $socket,
        $command . "\r\n"
    );

    return smtpRead($socket);
}

function smtpConnect(array $config): array {
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

        $lower = strtolower($errstr);

        if (
            str_contains($lower, 'getaddrinfo') ||
            str_contains($lower, 'name or service') ||
            str_contains($lower, 'could not resolve')
        ) {
            $type = 'dns';
        } elseif (
            str_contains($lower, 'timed out') ||
            str_contains($lower, 'timeout')
        ) {
            $type = 'timeout';
        }

        return [
            'ok' => false,
            'error_type' => $type,
            'smtp_code' => null,
            'message' => match ($type) {
                'dns' =>
                    'SMTPサーバのDNS解決に失敗しました。',
                'timeout' =>
                    'SMTPサーバへの接続がタイムアウトしました。',
                default =>
                    'SMTPサーバへ接続できませんでした。'
            },
            'detail' => $errstr
        ];
    }

    stream_set_timeout(
        $socket,
        $timeout
    );

    $greeting = smtpRead($socket);

    if ($greeting['code'] < 200 ||
        $greeting['code'] >= 400) {
        fclose($socket);

        return [
            'ok' => false,
            'error_type' => 'smtp_response',
            'smtp_code' => $greeting['code'],
            'message' =>
                'SMTPサーバの接続応答が不正です。'
        ];
    }

    $hostname =
        $_SERVER['SERVER_NAME'] ??
        'localhost';

    $ehlo = smtpCommand(
        $socket,
        'EHLO ' . $hostname
    );

    if ($ehlo['code'] >= 400) {
        $ehlo = smtpCommand(
            $socket,
            'HELO ' . $hostname
        );
    }

    if ($ehlo['code'] >= 400) {
        fclose($socket);

        return [
            'ok' => false,
            'error_type' => 'smtp_protocol',
            'smtp_code' => $ehlo['code'],
            'message' =>
                'SMTP EHLO/HELOに失敗しました。'
        ];
    }

    if ($config['smtp_encryption'] === 'starttls') {
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
                    'SMTP STARTTLSを開始できませんでした。'
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
                    'SMTP TLSネゴシエーションに失敗しました。'
            ];
        }

        $ehlo = smtpCommand(
            $socket,
            'EHLO ' . $hostname
        );

        if ($ehlo['code'] >= 400) {
            fclose($socket);

            return [
                'ok' => false,
                'error_type' => 'smtp_protocol',
                'smtp_code' => $ehlo['code'],
                'message' =>
                    'TLS後のEHLOに失敗しました。'
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
                    'SMTP認証を開始できませんでした。'
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
                    'SMTPユーザー名の認証に失敗しました。'
            ];
        }

        $pass = smtpCommand(
            $socket,
            base64_encode(
                $config['smtp_password']
            )
        );

        if ($pass['code'] < 200 ||
            $pass['code'] >= 300) {
            fclose($socket);

            return [
                'ok' => false,
                'error_type' => 'authentication',
                'smtp_code' => $pass['code'],
                'message' =>
                    $pass['code'] === 535
                        ? 'SMTP認証に失敗しました。'
                        : 'SMTP認証でエラーが発生しました。'
            ];
        }
    }

    return [
        'ok' => true,
        'socket' => $socket,
        'greeting' => $greeting['code'],
        'ehlo' => $ehlo['code']
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
                'テスト宛先メールアドレスが不正です。'
        ];
    }

    $connection = smtpConnect($config);

    if (!$connection['ok']) {
        return $connection;
    }

    $socket = $connection['socket'];

    $r = smtpCommand(
        $socket,
        'MAIL FROM:<' .
        $config['smtp_from_email'] .
        '>'
    );

    if ($r['code'] < 200 ||
        $r['code'] >= 300) {
        fclose($socket);

        return [
            'ok' => false,
            'error_type' => 'smtp_response',
            'smtp_code' => $r['code'],
            'message' =>
                'MAIL FROMがSMTPサーバに拒否されました。'
        ];
    }

    $r = smtpCommand(
        $socket,
        'RCPT TO:<' . $recipient . '>'
    );

    if ($r['code'] < 200 ||
        $r['code'] >= 300) {
        fclose($socket);

        return [
            'ok' => false,
            'error_type' => 'smtp_response',
            'smtp_code' => $r['code'],
            'message' =>
                '宛先がSMTPサーバに拒否されました。'
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
                'SMTP DATAを開始できませんでした。'
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

    $body = preg_replace(
        '/^\./m',
        '..',
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
        str_replace(
            "\n",
            "\r\n",
            $body
        ) .
        "\r\n.\r\n";

    fwrite($socket, $message);

    $r = smtpRead($socket);

    @smtpCommand(
        $socket,
        'QUIT'
    );

    fclose($socket);

    if ($r['code'] < 200 ||
        $r['code'] >= 300) {
        return [
            'ok' => false,
            'error_type' => 'smtp_response',
            'smtp_code' => $r['code'],
            'message' =>
                'メール送信がSMTPサーバに拒否されました。'
        ];
    }

    return [
        'ok' => true,
        'smtp_code' => $r['code']
    ];
}

function newQuestion(): array {
    return [
        'id' => makeId('question'),
        'number' => '',
        'text' => '',
        'type' => 'text',
        'required' => false,
        'options' => [],
        'other_enabled' => false,
        'branching' => []
    ];
}

function newGroup(): array {
    return [
        'id' => makeId('group'),
        'name' => 'ブロック',
        'questions' => []
    ];
}

function normalizeSurvey(array $survey): array {
    $survey['id'] =
        (string)($survey['id'] ?? makeId('survey'));

    $survey['title'] =
        (string)($survey['title'] ?? '');

    $survey['start_at'] =
        (string)($survey['start_at'] ?? '');

    $survey['end_at'] =
        (string)($survey['end_at'] ?? '');

    $survey['status'] =
        (string)($survey['status'] ?? 'draft');

    if (!in_array(
        $survey['status'],
        ['draft', 'active', 'ended'],
        true
    )) {
        $survey['status'] = 'draft';
    }

    $survey['numbering_mode'] =
        ($survey['numbering_mode'] ?? 'global') === 'group'
            ? 'group'
            : 'global';

    $survey['general_answer_allowed'] =
        !empty($survey['general_answer_allowed']);

    $survey['other_settings'] =
        is_array($survey['other_settings'] ?? null)
            ? $survey['other_settings']
            : [];

    $survey['groups'] =
        is_array($survey['groups'] ?? null)
            ? $survey['groups']
            : [];

    foreach ($survey['groups'] as &$group) {
        $group['id'] =
            (string)($group['id'] ?? makeId('group'));

        $group['name'] =
            (string)($group['name'] ?? 'ブロック');

        $group['questions'] =
            is_array($group['questions'] ?? null)
                ? $group['questions']
                : [];

        foreach ($group['questions'] as &$question) {
            $question['id'] =
                (string)(
                    $question['id'] ??
                    makeId('question')
                );

            $question['text'] =
                (string)($question['text'] ?? '');

            $question['type'] =
                in_array(
                    $question['type'] ?? '',
                    ['text', 'single', 'multiple', 'textarea'],
                    true
                )
                    ? $question['type']
                    : 'text';

            $question['required'] =
                !empty($question['required']);

            $question['options'] =
                is_array($question['options'] ?? null)
                    ? $question['options']
                    : [];

            foreach ($question['options'] as &$option) {
                $option['id'] =
                    (string)(
                        $option['id'] ??
                        makeId('option')
                    );

                $option['text'] =
                    (string)($option['text'] ?? '');
            }

            unset($option);

            $question['other_enabled'] =
                !empty($question['other_enabled']);

            $question['branching'] =
                is_array($question['branching'] ?? null)
                    ? $question['branching']
                    : [];
        }

        unset($question);
    }

    unset($group);

    renumberSurvey($survey);

    return $survey;
}

function renumberSurvey(array &$survey): void {
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            $question['number'] =
                $survey['numbering_mode'] === 'group'
                    ? 'Q' .
                      $groupNo .
                      '-' .
                      $questionNo
                    : 'Q' . $global;

            $questionNo++;
            $global++;
        }

        unset($question);
        $groupNo++;
    }

    unset($group);

    $flat = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $flat[] = $question;
        }
    }

    $position = [];

    foreach ($flat as $index => $question) {
        $position[$question['id']] = $index;
    }

    foreach ($survey['groups'] as &$group) {
        foreach ($group['questions'] as &$question) {
            $branching = [];

            foreach ($question['branching'] as $optionId => $targetId) {
                if ($targetId === null ||
                    $targetId === '') {
                    $branching[$optionId] = null;
                    continue;
                }

                $current =
                    $position[$question['id']] ?? -1;

                $target =
                    $position[$targetId] ?? -1;

                $branching[$optionId] =
                    $target > $current
                        ? $targetId
                        : null;
            }

            $question['branching'] = $branching;
        }

        unset($question);
    }

    unset($group);
}

function findSurvey(
    array &$data,
    string $surveyId
): ?int {
    foreach ($data['surveys'] as $index => $survey) {
        if ((string)(
            $survey['id'] ?? ''
        ) === $surveyId) {
            return $index;
        }
    }

    return null;
}

function handleApi(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = (string)(
        $_POST['action'] ?? ''
    );

    if ($action === '') {
        return;
    }

    requireCsrf();

    $data = loadData();

    switch ($action) {
        case 'get_data':
            jsonResponse([
                'ok' => true,
                'surveys' => $data['surveys'],
                'responses' => $data['responses'],
                'customers' => $data['customers'],
                'settings' =>
                    publicSettings(
                        $data['settings']
                    ),
                'mail_logs' => $data['mail_logs']
            ]);

        case 'list_surveys':
            $surveys = [];

            foreach ($data['surveys'] as $survey) {
                if (!empty($survey['deleted'])) {
                    continue;
                }

                $survey['response_count'] = 0;

                foreach ($data['responses'] as $response) {
                    if (
                        ($response['survey_id'] ?? '') ===
                        ($survey['id'] ?? '') &&
                        empty($response['deleted'])
                    ) {
                        $survey['response_count']++;
                    }
                }

                $surveys[] = $survey;
            }

            jsonResponse([
                'ok' => true,
                'surveys' => $surveys
            ]);

        case 'save_survey':
            $raw = (string)(
                $_POST['survey_json'] ?? ''
            );

            try {
                $survey = json_decode(
                    $raw,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (Throwable) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'アンケートJSONが不正です。'
                ], 400);
            }

            if (!is_array($survey)) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'アンケートデータが不正です。'
                ], 400);
            }

            if (!in_array(
                $survey['status'] ?? '',
                ['draft', 'active', 'ended'],
                true
            )) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'ステータスはdraft、active、endedのいずれかです。'
                ], 400);
            }

            $survey = normalizeSurvey($survey);

            $index = findSurvey(
                $data,
                $survey['id']
            );

            $survey['updated_at'] = date('c');

            if ($index === null) {
                $survey['created_at'] =
                    date('c');

                $survey['deleted'] = false;

                $survey['public_token'] =
                    bin2hex(random_bytes(24));

                $data['surveys'][] = $survey;
            } else {
                $survey['created_at'] =
                    $data['surveys'][$index]['created_at']
                    ?? date('c');

                $survey['public_token'] =
                    $data['surveys'][$index]['public_token']
                    ?? bin2hex(random_bytes(24));

                $survey['deleted'] =
                    !empty(
                        $data['surveys'][$index]['deleted']
                    );

                $data['surveys'][$index] =
                    $survey;
            }

            if (!saveData($data)) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'アンケートの保存に失敗しました。'
                ], 500);
            }

            jsonResponse([
                'ok' => true,
                'survey' => $survey
            ]);

        case 'duplicate_survey':
            $index = findSurvey(
                $data,
                (string)(
                    $_POST['survey_id'] ?? ''
                )
            );

            if ($index === null) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'アンケートが見つかりません。'
                ], 404);
            }

            $copy = $data['surveys'][$index];

            $oldQuestionIds = [];
            $newQuestionIds = [];

            $copy['id'] = makeId('survey');
            $copy['title'] .= '（複製）';
            $copy['status'] = 'draft';
            $copy['deleted'] = false;
            $copy['created_at'] = date('c');
            $copy['updated_at'] = date('c');
            $copy['public_token'] =
                bin2hex(random_bytes(24));

            foreach ($copy['groups'] as &$group) {
                $group['id'] = makeId('group');

                foreach ($group['questions'] as &$question) {
                    $oldQuestionIds[] =
                        $question['id'];

                    $newQuestionId =
                        makeId('question');

                    $newQuestionIds[] =
                        $newQuestionId;

                    $question['id'] =
                        $newQuestionId;

                    foreach (
                        $question['options']
                        as &$option
                    ) {
                        $option['id'] =
                            makeId('option');
                    }

                    unset($option);
                }

                unset($question);
            }

            unset($group);

            $map = array_combine(
                $oldQuestionIds,
                $newQuestionIds
            );

            foreach ($copy['groups'] as &$group) {
                foreach (
                    $group['questions']
                    as &$question
                ) {
                    foreach (
                        $question['branching']
                        as $optionId => $target
                    ) {
                        if (
                            $target !== null &&
                            isset($map[$target])
                        ) {
                            $question['branching'][$optionId] =
                                $map[$target];
                        }
                    }
                }

                unset($question);
            }

            unset($group);

            $copy = normalizeSurvey($copy);

            $data['surveys'][] = $copy;

            if (!saveData($data)) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'アンケートの複製に失敗しました。'
                ], 500);
            }

            jsonResponse([
                'ok' => true,
                'survey' => $copy
            ]);

        case 'delete_survey':
            $index = findSurvey(
                $data,
                (string)(
                    $_POST['survey_id'] ?? ''
                )
            );

            if ($index === null) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'アンケートが見つかりません。'
                ], 404);
            }

            if (
                ($data['surveys'][$index]['status'] ?? '')
                !== 'draft'
            ) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        '削除できるのは下書きアンケートだけです。'
                ], 400);
            }

            $data['surveys'][$index]['deleted'] = true;
            $data['surveys'][$index]['updated_at'] =
                date('c');

            if (!saveData($data)) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'アンケートの削除に失敗しました。'
                ], 500);
            }

            jsonResponse([
                'ok' => true
            ]);

        case 'save_kintone_settings':
            $validation = validateKintone(
                $_POST,
                $data['settings']['kintone']
            );

            if (!$validation['ok']) {
                jsonResponse(
                    $validation,
                    400
                );
            }

            $oldSettings =
                $data['settings'];

            $data['settings']['kintone'] =
                $validation['data'];

            if (!saveData($data)) {
                $data['settings'] =
                    $oldSettings;

                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'キントーン設定の保存に失敗しました。' .
                        'ファイル書き込み権限と保存先を確認してください。'
                ], 500);
            }

            jsonResponse([
                'ok' => true,
                'message' =>
                    'キントーン設定を保存しました。',
                'settings' =>
                    publicSettings(
                        $data['settings']
                    )
            ]);

        case 'save_smtp_settings':
            $validation = validateSmtp(
                $_POST,
                $data['settings']['smtp']
            );

            if (!$validation['ok']) {
                jsonResponse(
                    $validation,
                    400
                );
            }

            $oldSettings =
                $data['settings'];

            $data['settings']['smtp'] =
                $validation['data'];

            if (!saveData($data)) {
                $data['settings'] =
                    $oldSettings;

                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'SMTP設定の保存に失敗しました。' .
                        'ファイル書き込み権限と保存先を確認してください。'
                ], 500);
            }

            jsonResponse([
                'ok' => true,
                'message' =>
                    'SMTP設定を保存しました。',
                'settings' =>
                    publicSettings(
                        $data['settings']
                    )
            ]);

        case 'connect_kintone':
            $config =
                $data['settings']['kintone'];

            if (
                empty($config['subdomain']) ||
                empty($config['login_name']) ||
                empty($config['password']) ||
                empty($config['app_id'])
            ) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        '保存済みキントーン設定が不足しています。',
                    'error_type' =>
                        'configuration',
                    'check_items' => [
                        'サブドメイン',
                        'ログイン名',
                        'パスワード',
                        '顧客管理アプリID'
                    ]
                ], 400);
            }

            $result = kintoneRequest(
                $config,
                'GET',
                'app.json?app=' .
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
                        $result['http_status'] ?? null,
                    'check_items' =>
                        $result['check_items'] ?? []
                ], 502);
            }

            jsonResponse([
                'ok' => true,
                'message' =>
                    'キントーンへの接続に成功しました。',
                'subdomain' =>
                    $config['subdomain'] .
                    '.cybozu.com',
                'app_id' =>
                    (int)$config['app_id'],
                'http_status' =>
                    $result['http_status']
            ]);

        case 'fetch_kintone_fields':
            $config =
                $data['settings']['kintone'];

            if (
                empty($config['subdomain']) ||
                empty($config['login_name']) ||
                empty($config['password']) ||
                empty($config['app_id'])
            ) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        '保存済みキントーン設定が不足しています。',
                    'error_type' =>
                        'configuration'
                ], 400);
            }

            $result = kintoneRequest(
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
                        $result['http_status'] ?? null,
                    'check_items' =>
                        $result['check_items'] ?? []
                ], 502);
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
                        (string)(
                            $field['label'] ?? ''
                        ),
                    'code' => (string)$code,
                    'type' =>
                        (string)(
                            $field['type'] ?? ''
                        )
                ];
            }

            jsonResponse([
                'ok' => true,
                'message' =>
                    'フィールドを取得しました。',
                'fields' => $fields,
                'http_status' =>
                    $result['http_status']
            ]);

        case 'sync_customers':
            $config =
                $data['settings']['kintone'];

            if (
                empty($config['subdomain']) ||
                empty($config['login_name']) ||
                empty($config['password']) ||
                empty($config['app_id'])
            ) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        '保存済みキントーン設定が不足しています。',
                    'error_type' =>
                        'configuration'
                ], 400);
            }

            $result = kintoneRequest(
                $config,
                'GET',
                'records.json?app=' .
                rawurlencode(
                    (string)$config['app_id']
                ) .
                '&totalCount=true'
            );

            if (!$result['ok']) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        $result['message'],
                    'error_type' =>
                        $result['error_type'],
                    'http_status' =>
                        $result['http_status'] ?? null,
                    'check_items' =>
                        $result['check_items'] ?? []
                ], 502);
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

            $fieldMap = [
                'company' =>
                    $config['field_company'] ?? '',
                'name' =>
                    $config['field_name'] ?? '',
                'email' =>
                    $config['field_email'] ?? '',
                'department' =>
                    $config['field_department'] ?? '',
                'phone' =>
                    $config['field_phone'] ?? '',
                'address' =>
                    $config['field_address'] ?? ''
            ];

            foreach ($records as $record) {
                try {
                    $kintoneId =
                        (string)(
                            $record['$id']['value']
                            ?? ''
                        );

                    if ($kintoneId === '') {
                        $skipped++;
                        continue;
                    }

                    $customer = [
                        'id' => $kintoneId,
                        'kintone_id' => $kintoneId,
                        'company' =>
                            (string)(
                                $record[
                                    $fieldMap['company']
                                ]['value'] ?? ''
                            ),
                        'name' =>
                            (string)(
                                $record[
                                    $fieldMap['name']
                                ]['value'] ?? ''
                            ),
                        'email' =>
                            (string)(
                                $record[
                                    $fieldMap['email']
                                ]['value'] ?? ''
                            ),
                        'department' =>
                            (string)(
                                $record[
                                    $fieldMap['department']
                                ]['value'] ?? ''
                            ),
                        'phone' =>
                            (string)(
                                $record[
                                    $fieldMap['phone']
                                ]['value'] ?? ''
                            ),
                        'address' =>
                            (string)(
                                $record[
                                    $fieldMap['address']
                                ]['value'] ?? ''
                            ),
                        'updated_at' => date('c')
                    ];

                    $existing = null;

                    foreach (
                        $data['customers']
                        as $index => $old
                    ) {
                        if (
                            ($old['kintone_id'] ?? '') ===
                            $kintoneId
                        ) {
                            $existing = $index;
                            break;
                        }
                    }

                    if ($existing === null) {
                        $data['customers'][] =
                            $customer;
                        $inserted++;
                    } else {
                        $data['customers'][$existing] =
                            array_merge(
                                $data['customers'][$existing],
                                $customer
                            );
                        $updated++;
                    }
                } catch (Throwable) {
                    $errors++;
                }
            }

            if (!saveData($data)) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        '顧客データの保存に失敗しました。',
                    'error_type' =>
                        'storage'
                ], 500);
            }

            jsonResponse([
                'ok' => true,
                'message' =>
                    '顧客データを同期しました。',
                'count' => count($records),
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors
            ]);

        case 'test_smtp_connection':
            $config =
                $data['settings']['smtp'];

            if (empty($config['smtp_server'])) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        '保存済みSMTP設定がありません。',
                    'error_type' =>
                        'configuration',
                    'check_items' => [
                        'SMTPサーバ',
                        'SMTPポート',
                        '暗号化方式',
                        'SMTP認証',
                        'SMTPユーザー名',
                        'SMTPパスワード'
                    ]
                ], 400);
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
                        $result['smtp_code'] ?? null,
                    'smtp_server' =>
                        $config['smtp_server'],
                    'smtp_port' =>
                        $config['smtp_port'],
                    'smtp_encryption' =>
                        $config['smtp_encryption'],
                    'check_items' => [
                        'SMTPサーバ',
                        'SMTPポート',
                        '暗号化方式',
                        'SMTP認証',
                        'SMTPユーザー名',
                        'SMTPパスワード',
                        'ネットワーク設定'
                    ]
                ], 502);
            }

            fclose($result['socket']);

            jsonResponse([
                'ok' => true,
                'message' =>
                    'SMTP接続に成功しました。',
                'smtp_server' =>
                    $config['smtp_server'],
                'smtp_port' =>
                    $config['smtp_port'],
                'smtp_encryption' =>
                    $config['smtp_encryption'],
                'authentication' =>
                    !empty($config['smtp_auth'])
                        ? '成功'
                        : '未使用',
                'smtp_code' =>
                    $result['greeting']
            ]);

        case 'send_smtp_test':
            $config =
                $data['settings']['smtp'];

            $recipient = trim(
                (string)(
                    $_POST['recipient'] ?? ''
                )
            );

            if (
                !filter_var(
                    $recipient,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'テスト宛先メールアドレスが不正です。',
                    'error_type' =>
                        'configuration'
                ], 400);
            }

            if (empty($config['smtp_server'])) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        '保存済みSMTP設定がありません。',
                    'error_type' =>
                        'configuration'
                ], 400);
            }

            $result = smtpSend(
                $config,
                $recipient,
                'アンケート管理システム SMTP送信テスト',
                "アンケート管理システムのSMTPテストメールです。\n\n" .
                'このメールが届けば、SMTP設定による送信に成功しています。'
            );

            if (!$result['ok']) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        $result['message'],
                    'error_type' =>
                        $result['error_type'] ?? '',
                    'smtp_code' =>
                        $result['smtp_code'] ?? null,
                    'recipient' => $recipient
                ], 502);
            }

            $data['mail_logs'][] = [
                'id' => makeId('mail'),
                'recipient' => $recipient,
                'subject' =>
                    'アンケート管理システム SMTP送信テスト',
                'status' => 'sent',
                'smtp_code' =>
                    $result['smtp_code'] ?? null,
                'created_at' => date('c'),
                'type' => 'smtp_test'
            ];

            saveData($data);

            jsonResponse([
                'ok' => true,
                'message' =>
                    'テストメールを送信しました。',
                'recipient' => $recipient,
                'smtp_code' =>
                    $result['smtp_code'] ?? null
            ]);

        case 'save_response':
            $raw = (string)(
                $_POST['response_json'] ?? ''
            );

            try {
                $response = json_decode(
                    $raw,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (Throwable) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        '回答データが不正です。'
                ], 400);
            }

            if (!is_array($response)) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        '回答データが不正です。'
                ], 400);
            }

            $response['id'] =
                (string)(
                    $response['id'] ??
                    makeId('response')
                );

            $response['created_at'] =
                date('c');

            $data['responses'][] =
                $response;

            if (!saveData($data)) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        '回答の保存に失敗しました。'
                ], 500);
            }

            jsonResponse([
                'ok' => true,
                'response_id' =>
                    $response['id']
            ]);

        case 'send_mail':
            $config =
                $data['settings']['smtp'];

            if (empty($config['smtp_server'])) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        '保存済みSMTP設定がありません。',
                    'error_type' =>
                        'configuration'
                ], 400);
            }

            $recipientIds =
                json_decode(
                    (string)(
                        $_POST['recipient_ids']
                        ?? '[]'
                    ),
                    true
                );

            if (!is_array($recipientIds)) {
                $recipientIds = [];
            }

            $subject = trim(
                (string)(
                    $_POST['mail_subject'] ?? ''
                )
            );

            $body = (string)(
                $_POST['mail_body'] ?? ''
            );

            if ($subject === '') {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        '件名を入力してください。'
                ], 400);
            }

            $sent = 0;
            $errors = 0;

            foreach ($recipientIds as $customerId) {
                foreach (
                    $data['customers']
                    as $customer
                ) {
                    if (
                        (string)(
                            $customer['id'] ?? ''
                        ) !==
                        (string)$customerId
                    ) {
                        continue;
                    }

                    $email =
                        (string)(
                            $customer['email'] ?? ''
                        );

                    if (!filter_var(
                        $email,
                        FILTER_VALIDATE_EMAIL
                    )) {
                        $errors++;
                        continue;
                    }

                    $result = smtpSend(
                        $config,
                        $email,
                        $subject,
                        $body
                    );

                    $data['mail_logs'][] = [
                        'id' => makeId('mail'),
                        'customer_id' =>
                            $customer['id'],
                        'recipient' => $email,
                        'subject' => $subject,
                        'status' =>
                            $result['ok']
                                ? 'sent'
                                : 'error',
                        'smtp_code' =>
                            $result['smtp_code']
                            ?? null,
                        'message' =>
                            $result['message']
                            ?? '',
                        'created_at' => date('c')
                    ];

                    if ($result['ok']) {
                        $sent++;
                    } else {
                        $errors++;
                    }

                    break;
                }
            }

            saveData($data);

            jsonResponse([
                'ok' => $errors === 0,
                'message' =>
                    'メール送信を処理しました。',
                'sent' => $sent,
                'errors' => $errors
            ]);

        default:
            jsonResponse([
                'ok' => false,
                'message' =>
                    '未対応のActionです。'
            ], 400);
    }
}

handleApi();

$csrfToken = csrf();

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

<style>
[v-cloak] {
    display:none;
}
</style>
</head>

<body class="bg-gray-50 text-gray-900">

<div id="app"></div>

<script>
'use strict';

window.App = {

    state: {
        initialized: false,
        screen: 'list',

        surveys: [],
        responses: [],
        customers: [],
        mailLogs: [],

        settings: {
            kintone: {},
            smtp: {}
        },

        currentSurvey: null,
        previewSurvey: null,

        answers: {},

        keyword: '',
        statusFilter: '',
        sort: 'updated_desc',

        responseFilter: '',
        customerFilter: '',

        csrfToken:
            <?= json_encode(
                $csrfToken,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ) ?>
    },

    render: {},

    actions: {},

    api: {},

    utils: {},

    sortableInstances: []
};

App.utils.escapeHTML = function(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
};

App.utils.newQuestion = function() {
    return {
        id:
            'question_' +
            Date.now() +
            '_' +
            Math.random()
                .toString(36)
                .slice(2),

        number: '',
        text: '',
        type: 'text',
        required: false,
        options: [],
        other_enabled: false,
        branching: {}
    };
};

App.utils.newGroup = function() {
    return {
        id:
            'group_' +
            Date.now() +
            '_' +
            Math.random()
                .toString(36)
                .slice(2),

        name: 'ブロック',
        questions: []
    };
};

App.api.request = async function(
    action,
    payload = {}
) {
    const form = new FormData();

    form.append('action', action);
    form.append(
        'csrf_token',
        App.state.csrfToken
    );

    Object.entries(payload)
        .forEach(([key, value]) => {
            if (
                value !== null &&
                typeof value === 'object'
            ) {
                form.append(
                    key,
                    JSON.stringify(value)
                );
            } else {
                form.append(
                    key,
                    String(value ?? '')
                );
            }
        });

    const response = await fetch(
        window.location.href,
        {
            method: 'POST',
            body: form,
            credentials: 'same-origin'
        }
    );

    let data;

    try {
        data = await response.json();
    } catch (e) {
        throw {
            message:
                'サーバーから正しいJSON応答を取得できませんでした。',
            error_type: 'api'
        };
    }

    if (!response.ok || !data.ok) {
        throw data;
    }

    return data;
};

App.render.shell = function() {
    document.getElementById('app').innerHTML = `
        <div class="min-h-screen">

            <header class="
                fixed top-0 left-0 right-0 z-50
                bg-white border-b shadow-sm
            ">
                <div class="
                    max-w-7xl mx-auto px-4
                    h-16 flex items-center
                    justify-between
                ">
                    <div class="font-bold">
                        アンケート管理システム
                    </div>

                    <nav class="
                        flex items-center gap-1
                    ">
                        <button
                            class="px-3 py-2 rounded hover:bg-gray-100"
                            onclick="App.actions.goList()"
                        >
                            アンケート一覧
                        </button>

                        <button
                            class="px-3 py-2 rounded hover:bg-gray-100"
                            onclick="App.actions.goSettings()"
                        >
                            キントーン・メール設定
                        </button>

                        <button
                            class="px-3 py-2 rounded hover:bg-gray-100"
                            onclick="App.actions.logout()"
                        >
                            ログアウト
                        </button>
                    </nav>
                </div>
            </header>

            <main
                id="main_content"
                class="max-w-7xl mx-auto px-4 py-24"
            ></main>

        </div>
    `;
};

App.render.current = function() {
    App.render.shell();

    if (App.state.screen === 'list') {
        App.render.list();
        return;
    }

    if (App.state.screen === 'edit') {
        App.render.editor();
        return;
    }

    if (App.state.screen === 'settings') {
        App.render.settings();
        return;
    }

    if (App.state.screen === 'preview') {
        App.render.preview();
        return;
    }

    if (App.state.screen === 'send') {
        App.render.send();
        return;
    }

    if (App.state.screen === 'aggregate') {
        App.render.aggregate();
        return;
    }

    if (App.state.screen === 'respond') {
        App.render.respond();
        return;
    }
};

App.render.list = function() {
    const root =
        document.getElementById(
            'main_content'
        );

    let surveys =
        App.state.surveys.filter(
            s => !s.deleted
        );

    const keyword =
        App.state.keyword
            .trim()
            .toLowerCase();

    if (keyword) {
        surveys = surveys.filter(
            s =>
                String(s.title || '')
                    .toLowerCase()
                    .includes(keyword)
        );
    }

    if (App.state.statusFilter) {
        surveys = surveys.filter(
            s =>
                s.status ===
                App.state.statusFilter
        );
    }

    if (App.state.sort === 'title_asc') {
        surveys.sort(
            (a,b) =>
                String(a.title)
                    .localeCompare(
                        String(b.title),
                        'ja'
                    )
        );
    } else {
        surveys.sort(
            (a,b) =>
                String(b.updated_at || '')
                    .localeCompare(
                        String(a.updated_at || '')
                    )
        );
    }

    root.innerHTML = `
        <div class="mb-6">
            <div class="text-sm text-gray-500">
                ホーム ＞ アンケート一覧
            </div>

            <div class="
                flex justify-between
                items-center mt-1
            ">
                <h1 class="text-2xl font-bold">
                    アンケート一覧
                </h1>

                <button
                    class="
                        bg-blue-600 text-white
                        px-4 py-2 rounded-lg
                        hover:bg-blue-700
                    "
                    onclick="App.actions.createSurvey()"
                >
                    ＋ 新規アンケート
                </button>
            </div>
        </div>

        <div class="
            bg-white border rounded-xl
            p-4 mb-4 flex flex-wrap gap-3
        ">
            <input
                class="
                    border rounded px-3 py-2
                    flex-1 min-w-[240px]
                "
                placeholder="アンケートを検索"
                value="${App.utils.escapeHTML(
                    App.state.keyword
                )}"
                oninput="
                    App.actions.search(this.value)
                "
            >

            <select
                class="border rounded px-3 py-2"
                onchange="
                    App.actions.filterStatus(this.value)
                "
            >
                <option value="">すべて</option>
                <option value="draft"
                    ${
                        App.state.statusFilter === 'draft'
                            ? 'selected'
                            : ''
                    }>
                    下書き
                </option>
                <option value="active"
                    ${
                        App.state.statusFilter === 'active'
                            ? 'selected'
                            : ''
                    }>
                    公開中
                </option>
                <option value="ended"
                    ${
                        App.state.statusFilter === 'ended'
                            ? 'selected'
                            : ''
                    }>
                    終了
                </option>
            </select>

            <select
                class="border rounded px-3 py-2"
                onchange="
                    App.actions.sortBy(this.value)
                "
            >
                <option value="updated_desc">
                    更新日時順
                </option>
                <option value="title_asc">
                    タイトル順
                </option>
            </select>
        </div>

        <div class="
            bg-white border rounded-xl
            overflow-hidden
        ">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left p-3">
                                タイトル
                            </th>
                            <th class="text-left p-3">
                                ステータス
                            </th>
                            <th class="text-left p-3">
                                回答数
                            </th>
                            <th class="text-left p-3">
                                操作
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        ${
                            surveys.length
                                ? surveys
                                    .map(
                                        App.render.surveyRow
                                    )
                                    .join('')
                                : `
                                    <tr>
                                        <td
                                            colspan="4"
                                            class="p-8 text-center text-gray-500"
                                        >
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

App.render.surveyRow = function(survey) {
    const statusMap = {
        draft: '下書き',
        active: '公開中',
        ended: '終了'
    };

    const status =
        statusMap[survey.status] ||
        survey.status;

    let actions = `
        <button
            class="text-blue-600 hover:underline"
            onclick="
                App.actions.editSurvey(
                    '${App.utils.escapeHTML(survey.id)}'
                )
            "
        >
            確認・編集
        </button>
    `;

    if (
        survey.status === 'active' ||
        survey.status === 'ended'
    ) {
        actions += `
            <button
                class="text-indigo-600 hover:underline"
                onclick="
                    App.actions.aggregate(
                        '${App.utils.escapeHTML(survey.id)}'
                    )
                "
            >
                集計
            </button>

            <button
                class="text-green-600 hover:underline"
                onclick="
                    App.actions.send(
                        '${App.utils.escapeHTML(survey.id)}'
                    )
                "
            >
                送信
            </button>
        `;
    }

    actions += `
        <button
            class="text-gray-700 hover:underline"
            onclick="
                App.actions.duplicate(
                    '${App.utils.escapeHTML(survey.id)}'
                )
            "
        >
            複製
        </button>
    `;

    if (survey.status === 'draft') {
        actions += `
            <button
                class="text-red-600 hover:underline"
                onclick="
                    App.actions.deleteSurvey(
                        '${App.utils.escapeHTML(survey.id)}'
                    )
                "
            >
                削除
            </button>
        `;
    }

    return `
        <tr class="border-b">
            <td class="p-3 font-medium">
                ${App.utils.escapeHTML(
                    survey.title
                )}
            </td>

            <td class="p-3">
                ${App.utils.escapeHTML(status)}
            </td>

            <td class="p-3">
                ${Number(
                    survey.response_count || 0
                )}
            </td>

            <td class="p-3">
                <div class="
                    flex flex-wrap gap-3
                ">
                    ${actions}
                </div>
            </td>
        </tr>
    `;
};

App.render.editor = function() {
    const root =
        document.getElementById(
            'main_content'
        );

    const survey =
        App.state.currentSurvey;

    if (!survey) {
        App.actions.goList();
        return;
    }

    root.innerHTML = `
        <div class="mb-6">
            <div class="text-sm text-gray-500">
                ホーム ＞ アンケート一覧 ＞ 確認・編集
            </div>

            <div class="
                flex justify-between
                items-center mt-1
            ">
                <h1 class="text-2xl font-bold">
                    アンケート作成・編集
                </h1>

                <div class="flex gap-2">
                    <button
                        class="
                            border px-4 py-2
                            rounded-lg
                        "
                        onclick="
                            App.actions.preview()
                        "
                    >
                        プレビュー
                    </button>

                    <button
                        class="
                            bg-blue-600
                            text-white
                            px-4 py-2
                            rounded-lg
                        "
                        onclick="
                            App.actions.saveSurvey()
                        "
                    >
                        保存
                    </button>
                </div>
            </div>
        </div>

        <div class="
            bg-white border rounded-xl
            p-5 mb-5
        ">
            <div class="
                grid md:grid-cols-2
                gap-4
            ">
                <label>
                    <span class="
                        block text-sm
                        font-medium mb-1
                    ">
                        タイトル
                    </span>

                    <input
                        id="survey_title"
                        class="
                            w-full border
                            rounded px-3 py-2
                        "
                        value="${App.utils.escapeHTML(
                            survey.title
                        )}"
                        oninput="
                            App.actions.changeSurveyField(
                                'title',
                                this.value
                            )
                        "
                    >
                </label>

                <label>
                    <span class="
                        block text-sm
                        font-medium mb-1
                    ">
                        ステータス
                    </span>

                    <select
                        id="survey_status"
                        class="
                            w-full border
                            rounded px-3 py-2
                        "
                        onchange="
                            App.actions.changeSurveyStatus(
                                this.value
                            )
                        "
                    >
                        <option value="draft"
                            ${
                                survey.status === 'draft'
                                    ? 'selected'
                                    : ''
                            }>
                            下書き
                        </option>

                        <option value="active"
                            ${
                                survey.status === 'active'
                                    ? 'selected'
                                    : ''
                            }>
                            公開中
                        </option>

                        <option value="ended"
                            ${
                                survey.status === 'ended'
                                    ? 'selected'
                                    : ''
                            }>
                            終了
                        </option>
                    </select>
                </label>

                <label>
                    <span class="
                        block text-sm
                        font-medium mb-1
                    ">
                        開始日時
                    </span>

                    <input
                        id="survey_start_at"
                        type="datetime-local"
                        class="
                            w-full border
                            rounded px-3 py-2
                        "
                        value="${App.utils.escapeHTML(
                            survey.start_at
                        )}"
                        oninput="
                            App.actions.changeSurveyField(
                                'start_at',
                                this.value
                            )
                        "
                    >
                </label>

                <label>
                    <span class="
                        block text-sm
                        font-medium mb-1
                    ">
                        終了日時
                    </span>

                    <input
                        id="survey_end_at"
                        type="datetime-local"
                        class="
                            w-full border
                            rounded px-3 py-2
                        "
                        value="${App.utils.escapeHTML(
                            survey.end_at
                        )}"
                        oninput="
                            App.actions.changeSurveyField(
                                'end_at',
                                this.value
                            )
                        "
                    >
                </label>

                <label>
                    <span class="
                        block text-sm
                        font-medium mb-1
                    ">
                        質問番号形式
                    </span>

                    <select
                        id="survey_numbering_mode"
                        class="
                            w-full border
                            rounded px-3 py-2
                        "
                        onchange="
                            App.actions.changeSurveyField(
                                'numbering_mode',
                                this.value
                            )
                        "
                    >
                        <option value="global"
                            ${
                                survey.numbering_mode === 'global'
                                    ? 'selected'
                                    : ''
                            }>
                            Q1 / Q2 / Q3
                        </option>

                        <option value="group"
                            ${
                                survey.numbering_mode === 'group'
                                    ? 'selected'
                                    : ''
                            }>
                            Q1-1 / Q1-2
                        </option>
                    </select>
                </label>

                <label class="
                    flex items-center
                    gap-2 mt-6
                ">
                    <input
                        type="checkbox"
                        ${
                            survey.general_answer_allowed
                                ? 'checked'
                                : ''
                        }
                        onchange="
                            App.actions.changeSurveyField(
                                'general_answer_allowed',
                                this.checked
                            )
                        "
                    >
                    一般回答を許可
                </label>
            </div>
        </div>

        <div id="question_editor">
            ${
                survey.groups
                    .map(
                        App.render.group
                    )
                    .join('')
            }

            <button
                class="
                    w-full border-2
                    border-dashed
                    border-blue-300
                    text-blue-700
                    py-3 rounded-lg
                    hover:bg-blue-50
                "
                onclick="
                    App.actions.addGroup()
                "
            >
                ＋ ブロックを追加
            </button>
        </div>
    `;

    App.initSortable();
};

App.render.group = function(group) {
    return `
        <section
            class="
                bg-white border
                rounded-xl p-5 mb-5
            "
            data-group-id="${App.utils.escapeHTML(
                group.id
            )}"
        >
            <div class="
                flex justify-between
                items-center mb-4
            ">
                <input
                    class="
                        text-lg font-bold
                        border rounded
                        px-3 py-2
                    "
                    value="${App.utils.escapeHTML(
                        group.name
                    )}"
                    oninput="
                        App.actions.changeGroupName(
                            '${App.utils.escapeHTML(group.id)}',
                            this.value
                        )
                    "
                >

                <button
                    class="
                        text-red-600
                    "
                    onclick="
                        App.actions.deleteGroup(
                            '${App.utils.escapeHTML(group.id)}'
                        )
                    "
                >
                    グループ削除
                </button>
            </div>

            <div
                data-sortable-questions
                data-group-id="${App.utils.escapeHTML(
                    group.id
                )}"
                class="space-y-4"
            >
                ${
                    group.questions
                        .map(
                            q =>
                                App.render.question(
                                    q,
                                    group.id
                                )
                        )
                        .join('')
                }
            </div>

            <button
                class="
                    mt-4 border
                    border-blue-300
                    text-blue-700
                    px-4 py-2
                    rounded-lg
                "
                onclick="
                    App.actions.addQuestion(
                        '${App.utils.escapeHTML(group.id)}'
                    )
                "
            >
                ＋ 質問を追加
            </button>
        </section>
    `;
};

App.render.question = function(
    question,
    groupId
) {
    return `
        <div
            data-question-id="${App.utils.escapeHTML(
                question.id
            )}"
            class="
                border rounded-xl
                p-4 bg-gray-50
            "
        >
            <div class="
                flex justify-between
                items-center mb-3
            ">
                <div class="
                    font-bold
                    text-blue-700
                ">
                    ${App.utils.escapeHTML(
                        question.number
                    )}
                </div>

                <button
                    class="
                        text-red-600
                    "
                    onclick="
                        App.actions.deleteQuestion(
                            '${App.utils.escapeHTML(groupId)}',
                            '${App.utils.escapeHTML(question.id)}'
                        )
                    "
                >
                    質問削除
                </button>
            </div>

            <input
                class="
                    w-full border
                    rounded px-3 py-2
                    mb-3
                "
                placeholder="質問文"
                value="${App.utils.escapeHTML(
                    question.text
                )}"
                oninput="
                    App.actions.changeQuestion(
                        '${App.utils.escapeHTML(groupId)}',
                        '${App.utils.escapeHTML(question.id)}',
                        'text',
                        this.value
                    )
                "
            >

            <div class="
                grid md:grid-cols-3
                gap-3
            ">
                <label>
                    <span class="
                        block text-sm
                        font-medium mb-1
                    ">
                        質問形式
                    </span>

                    <select
                        class="
                            w-full border
                            rounded px-2 py-2
                        "
                        onchange="
                            App.actions.changeQuestion(
                                '${App.utils.escapeHTML(groupId)}',
                                '${App.utils.escapeHTML(question.id)}',
                                'type',
                                this.value
                            )
                        "
                    >
                        <option value="text"
                            ${
                                question.type === 'text'
                                    ? 'selected'
                                    : ''
                            }>
                            文字列
                        </option>

                        <option value="textarea"
                            ${
                                question.type === 'textarea'
                                    ? 'selected'
                                    : ''
                            }>
                            自由記述
                        </option>

                        <option value="single"
                            ${
                                question.type === 'single'
                                    ? 'selected'
                                    : ''
                            }>
                            単一選択
                        </option>

                        <option value="multiple"
                            ${
                                question.type === 'multiple'
                                    ? 'selected'
                                    : ''
                            }>
                            複数選択
                        </option>
                    </select>
                </label>

                <label class="
                    flex items-center
                    gap-2 mt-6
                ">
                    <input
                        type="checkbox"
                        ${
                            question.required
                                ? 'checked'
                                : ''
                        }
                        onchange="
                            App.actions.changeQuestion(
                                '${App.utils.escapeHTML(groupId)}',
                                '${App.utils.escapeHTML(question.id)}',
                                'required',
                                this.checked
                            )
                        "
                    >
                    必須回答
                </label>

                <label class="
                    flex items-center
                    gap-2 mt-6
                ">
                    <input
                        type="checkbox"
                        ${
                            question.other_enabled
                                ? 'checked'
                                : ''
                        }
                        onchange="
                            App.actions.changeQuestion(
                                '${App.utils.escapeHTML(groupId)}',
                                '${App.utils.escapeHTML(question.id)}',
                                'other_enabled',
                                this.checked
                            )
                        "
                    >
                    その他を許可
                </label>
            </div>

            ${
                question.type === 'single' ||
                question.type === 'multiple'
                    ? App.render.options(
                        question,
                        groupId
                    )
                    : ''
            }
        </div>
    `;
};

App.render.options = function(
    question,
    groupId
) {
    const candidates =
        App.actions.branchCandidates(
            question.id
        );

    return `
        <div class="mt-4">
            <div class="
                font-medium mb-2
            ">
                選択肢
            </div>

            <div class="space-y-2">
                ${
                    question.options
                        .map(
                            option => `
                                <div class="
                                    border
                                    rounded
                                    bg-white
                                    p-3
                                ">
                                    <div class="
                                        flex gap-2
                                    ">
                                        <input
                                            class="
                                                border
                                                rounded
                                                px-2
                                                py-1
                                                flex-1
                                            "
                                            value="${App.utils.escapeHTML(
                                                option.text
                                            )}"
                                            oninput="
                                                App.actions.changeOption(
                                                    '${App.utils.escapeHTML(groupId)}',
                                                    '${App.utils.escapeHTML(question.id)}',
                                                    '${App.utils.escapeHTML(option.id)}',
                                                    this.value
                                                )
                                            "
                                        >

                                        <button
                                            class="
                                                text-red-600
                                            "
                                            onclick="
                                                App.actions.deleteOption(
                                                    '${App.utils.escapeHTML(groupId)}',
                                                    '${App.utils.escapeHTML(question.id)}',
                                                    '${App.utils.escapeHTML(option.id)}'
                                                )
                                            "
                                        >
                                            削除
                                        </button>
                                    </div>

                                    ${
                                        question.type === 'single'
                                            ? `
                                                <label class="
                                                    block
                                                    text-sm
                                                    mt-3
                                                ">
                                                    分岐先質問

                                                    <select
                                                        class="
                                                            w-full
                                                            border
                                                            rounded
                                                            px-2
                                                            py-2
                                                            mt-1
                                                        "
                                                        onchange="
                                                            App.actions.changeBranch(
                                                                '${App.utils.escapeHTML(question.id)}',
                                                                '${App.utils.escapeHTML(option.id)}',
                                                                this.value
                                                            )
                                                        "
                                                    >
                                                        <option value="">
                                                            分岐しない
                                                        </option>

                                                        ${
                                                            candidates
                                                                .map(
                                                                    candidate =>
                                                                        `
                                                                        <option
                                                                            value="${App.utils.escapeHTML(candidate.id)}"
                                                                            ${
                                                                                question.branching?.[option.id] === candidate.id
                                                                                    ? 'selected'
                                                                                    : ''
                                                                            }
                                                                        >
                                                                            ${App.utils.escapeHTML(candidate.number)}
                                                                            ：
                                                                            ${App.utils.escapeHTML(candidate.text)}
                                                                        </option>
                                                                        `
                                                                )
                                                                .join('')
                                                        }
                                                    </select>
                                                </label>
                                            `
                                            : ''
                                    }
                                </div>
                            `
                        )
                        .join('')
                }
            </div>

            <button
                class="
                    mt-3 border
                    px-3 py-2
                    rounded
                "
                onclick="
                    App.actions.addOption(
                        '${App.utils.escapeHTML(groupId)}',
                        '${App.utils.escapeHTML(question.id)}'
                    )
                "
            >
                ＋ 選択肢を追加
            </button>
        </div>
    `;
};

App.render.settings = function() {
    const root =
        document.getElementById(
            'main_content'
        );

    const k =
        App.state.settings.kintone || {};

    const s =
        App.state.settings.smtp || {};

    root.innerHTML = `
        <div class="mb-6">
            <div class="
                text-sm text-gray-500
            ">
                ホーム ＞ キントーン・メール設定
            </div>

            <h1 class="
                text-2xl font-bold mt-1
            ">
                キントーン・メール設定
            </h1>
        </div>

        <section
            id="kintone_settings_form"
            class="
                bg-white border
                rounded-xl p-6 mb-6
            "
        >
            <h2 class="
                text-xl font-bold
                mb-5
            ">
                キントーン設定
            </h2>

            <div class="
                grid md:grid-cols-2
                gap-4
            ">
                <label>
                    <span class="block text-sm font-medium mb-1">
                        サブドメイン
                    </span>

                    <input
                        id="setting_subdomain"
                        class="w-full border rounded px-3 py-2"
                        placeholder="xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com"
                        value="${App.utils.escapeHTML(
                            k.subdomain || ''
                        )}"
                    >
                </label>

                <label>
                    <span class="block text-sm font-medium mb-1">
                        ログイン名
                    </span>

                    <input
                        id="setting_login_name"
                        class="w-full border rounded px-3 py-2"
                        value="${App.utils.escapeHTML(
                            k.login_name || ''
                        )}"
                    >
                </label>

                <label>
                    <span class="block text-sm font-medium mb-1">
                        パスワード
                    </span>

                    <input
                        id="setting_password"
                        type="password"
                        autocomplete="new-password"
                        class="w-full border rounded px-3 py-2"
                        placeholder="${
                            k.password_configured
                                ? '変更しない場合は空欄'
                                : ''
                        }"
                    >
                </label>

                <label>
                    <span class="block text-sm font-medium mb-1">
                        顧客管理アプリID
                    </span>

                    <input
                        id="setting_app_id"
                        type="number"
                        min="1"
                        class="w-full border rounded px-3 py-2"
                        value="${App.utils.escapeHTML(
                            k.app_id || ''
                        )}"
                    >
                </label>

                <label>
                    <span class="block text-sm font-medium mb-1">
                        Proxy
                    </span>

                    <input
                        id="setting_proxy"
                        class="w-full border rounded px-3 py-2"
                        placeholder="proxy.example.com:8080"
                        value="${App.utils.escapeHTML(
                            k.proxy || ''
                        )}"
                    >
                </label>

                <label class="
                    flex items-center gap-2
                    mt-6
                ">
                    <input
                        id="setting_ssl_verify"
                        type="checkbox"
                        ${
                            k.ssl_verify
                                ? 'checked'
                                : ''
                        }
                    >
                    SSL証明書を検証する
                </label>
            </div>

            <div class="
                mt-6 border-t pt-5
            ">
                <h3 class="font-bold mb-3">
                    顧客フィールド
                </h3>

                <div class="
                    grid md:grid-cols-3
                    gap-3
                ">
                    ${[
                        ['company','会社名'],
                        ['name','氏名'],
                        ['email','メール'],
                        ['department','部署'],
                        ['phone','電話'],
                        ['address','住所']
                    ].map(
                        ([code,label]) => `
                            <label>
                                <span class="
                                    block text-sm
                                    font-medium mb-1
                                ">
                                    ${label}
                                </span>

                                <input
                                    id="field_${code}"
                                    class="
                                        w-full border
                                        rounded px-3 py-2
                                    "
                                    value="${App.utils.escapeHTML(
                                        k[
                                            'field_' +
                                            code
                                        ] || ''
                                    )}"
                                >
                            </label>
                        `
                    ).join('')}
                </div>
            </div>

            <div class="
                flex flex-wrap gap-2
                mt-6
            ">
                <button
                    id="kintone_save_button"
                    class="
                        bg-blue-600
                        text-white
                        px-4 py-2
                        rounded-lg
                    "
                    onclick="
                        App.actions.saveKintoneSettings()
                    "
                >
                    設定を保存
                </button>

                <button
                    class="
                        border
                        px-4 py-2
                        rounded-lg
                    "
                    onclick="
                        App.actions.connectKintone()
                    "
                >
                    キントーン接続確認
                </button>

                <button
                    class="
                        border
                        px-4 py-2
                        rounded-lg
                    "
                    onclick="
                        App.actions.fetchKintoneFields()
                    "
                >
                    フィールド取得
                </button>

                <button
                    class="
                        border
                        px-4 py-2
                        rounded-lg
                    "
                    onclick="
                        App.actions.syncCustomers()
                    "
                >
                    顧客データを同期
                </button>
            </div>

            <div
                id="kintone_message"
                class="mt-4"
            ></div>

            <div
                id="field_message"
                class="mt-4"
            ></div>
        </section>

        <section
            id="smtp_settings_form"
            class="
                bg-white border
                rounded-xl p-6 mb-6
            "
        >
            <h2 class="
                text-xl font-bold
                mb-5
            ">
                SMTP設定
            </h2>

            <div class="
                grid md:grid-cols-2
                gap-4
            ">
                <label>
                    <span class="block text-sm font-medium mb-1">
                        SMTPサーバ
                    </span>

                    <input
                        id="smtp_server"
                        class="w-full border rounded px-3 py-2"
                        value="${App.utils.escapeHTML(
                            s.smtp_server || ''
                        )}"
                    >
                </label>

                <label>
                    <span class="block text-sm font-medium mb-1">
                        SMTPポート
                    </span>

                    <input
                        id="smtp_port"
                        type="number"
                        min="1"
                        max="65535"
                        class="w-full border rounded px-3 py-2"
                        value="${App.utils.escapeHTML(
                            s.smtp_port || ''
                        )}"
                    >
                </label>

                <label>
                    <span class="block text-sm font-medium mb-1">
                        暗号化方式
                    </span>

                    <select
                        id="smtp_encryption"
                        class="w-full border rounded px-3 py-2"
                    >
                        <option value="none"
                            ${
                                (s.smtp_encryption || 'none')
                                === 'none'
                                    ? 'selected'
                                    : ''
                            }>
                            なし
                        </option>

                        <option value="starttls"
                            ${
                                s.smtp_encryption === 'starttls'
                                    ? 'selected'
                                    : ''
                            }>
                            STARTTLS
                        </option>

                        <option value="ssl"
                            ${
                                s.smtp_encryption === 'ssl'
                                    ? 'selected'
                                    : ''
                            }>
                            SSL/TLS
                        </option>
                    </select>
                </label>

                <label class="
                    flex items-center gap-2
                    mt-6
                ">
                    <input
                        id="smtp_auth"
                        type="checkbox"
                        ${
                            s.smtp_auth
                                ? 'checked'
                                : ''
                        }
                    >
                    SMTP認証
                </label>

                <label>
                    <span class="block text-sm font-medium mb-1">
                        SMTPユーザー名
                    </span>

                    <input
                        id="smtp_username"
                        class="w-full border rounded px-3 py-2"
                        value="${App.utils.escapeHTML(
                            s.smtp_username || ''
                        )}"
                    >
                </label>

                <label>
                    <span class="block text-sm font-medium mb-1">
                        SMTPパスワード
                    </span>

                    <input
                        id="smtp_password"
                        type="password"
                        autocomplete="new-password"
                        class="w-full border rounded px-3 py-2"
                        placeholder="${
                            s.smtp_password_configured
                                ? '変更しない場合は空欄'
                                : ''
                        }"
                    >
                </label>

                <label>
                    <span class="block text-sm font-medium mb-1">
                        送信元メールアドレス
                    </span>

                    <input
                        id="smtp_from_email"
                        type="email"
                        class="w-full border rounded px-3 py-2"
                        value="${App.utils.escapeHTML(
                            s.smtp_from_email || ''
                        )}"
                    >
                </label>

                <label>
                    <span class="block text-sm font-medium mb-1">
                        送信元表示名
                    </span>

                    <input
                        id="smtp_from_name"
                        class="w-full border rounded px-3 py-2"
                        value="${App.utils.escapeHTML(
                            s.smtp_from_name || ''
                        )}"
                    >
                </label>

                <label>
                    <span class="block text-sm font-medium mb-1">
                        接続タイムアウト
                    </span>

                    <input
                        id="smtp_timeout"
                        type="number"
                        min="1"
                        max="300"
                        class="w-full border rounded px-3 py-2"
                        value="${App.utils.escapeHTML(
                            s.smtp_timeout || 10
                        )}"
                    >
                </label>
            </div>

            <div class="
                flex flex-wrap gap-2
                mt-6
            ">
                <button
                    id="smtp_save_button"
                    class="
                        bg-blue-600
                        text-white
                        px-4 py-2
                        rounded-lg
                    "
                    onclick="
                        App.actions.saveSmtpSettings()
                    "
                >
                    設定を保存
                </button>

                <button
                    class="
                        border
                        px-4 py-2
                        rounded-lg
                    "
                    onclick="
                        App.actions.testSmtpConnection()
                    "
                >
                    SMTP接続確認
                </button>

                <button
                    class="
                        border
                        px-4 py-2
                        rounded-lg
                    "
                    onclick="
                        App.actions.sendSmtpTest()
                    "
                >
                    テストメール送信
                </button>
            </div>

            <div class="mt-4">
                <label>
                    <span class="
                        block text-sm
                        font-medium mb-1
                    ">
                        テスト送信先
                    </span>

                    <input
                        id="smtp_test_recipient"
                        type="email"
                        class="
                            w-full border
                            rounded px-3 py-2
                        "
                        placeholder="test@example.com"
                    >
                </label>
            </div>

            <div
                id="smtp_message"
                class="mt-4"
            ></div>
        </section>
    `;
};

App.render.preview = function() {
    const root =
        document.getElementById(
            'main_content'
        );

    const survey =
        App.state.previewSurvey;

    if (!survey) {
        App.actions.editCurrent();
        return;
    }

    root.innerHTML = `
        <div class="mb-6">
            <div class="text-sm text-gray-500">
                ホーム ＞ アンケート一覧 ＞ 確認・編集 ＞ プレビュー
            </div>

            <div class="
                flex justify-between
                items-center
            ">
                <h1 class="text-2xl font-bold">
                    ${App.utils.escapeHTML(
                        survey.title
                    )}
                </h1>

                <button
                    class="
                        border px-4 py-2
                        rounded-lg
                    "
                    onclick="
                        App.state.screen = 'edit';
                        App.render.current();
                    "
                >
                    編集画面へ戻る
                </button>
            </div>
        </div>

        <div class="
            bg-white border
            rounded-xl p-6
        ">
            ${
                survey.groups
                    .map(
                        group => `
                            <section class="mb-8">
                                <h2 class="
                                    text-lg
                                    font-bold
                                    mb-4
                                ">
                                    ${App.utils.escapeHTML(
                                        group.name
                                    )}
                                </h2>

                                ${
                                    group.questions
                                        .map(
                                            q =>
                                                App.render.previewQuestion(
                                                    q
                                                )
                                        )
                                        .join('')
                                }
                            </section>
                        `
                    )
                    .join('')
            }
        </div>
    `;
};

App.render.previewQuestion =
    function(q) {
        return `
            <div
                class="
                    mb-5
                    border-b
                    pb-4
                "
                data-preview-question
                data-question-id="${App.utils.escapeHTML(
                    q.id
                )}"
            >
                <div class="
                    font-medium
                    mb-2
                ">
                    ${App.utils.escapeHTML(
                        q.number
                    )}
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
                    q.type === 'text'
                        ? `
                            <input
                                class="
                                    w-full
                                    border
                                    rounded
                                    px-3
                                    py-2
                                "
                                data-answer-id="${App.utils.escapeHTML(q.id)}"
                            >
                        `
                        : ''
                }

                ${
                    q.type === 'textarea'
                        ? `
                            <textarea
                                class="
                                    w-full
                                    border
                                    rounded
                                    px-3
                                    py-2
                                "
                                rows="4"
                                data-answer-id="${App.utils.escapeHTML(q.id)}"
                            ></textarea>
                        `
                        : ''
                }

                ${
                    q.type === 'single' ||
                    q.type === 'multiple'
                        ? `
                            <div class="space-y-2">
                                ${
                                    q.options
                                        .map(
                                            option => `
                                                <label class="
                                                    flex
                                                    items-center
                                                    gap-2
                                                ">
                                                    <input
                                                        type="${
                                                            q.type === 'single'
                                                                ? 'radio'
                                                                : 'checkbox'
                                                        }"
                                                        name="${App.utils.escapeHTML(q.id)}"
                                                        value="${App.utils.escapeHTML(option.id)}"
                                                        onchange="
                                                            App.actions.previewAnswerChange(
                                                                '${App.utils.escapeHTML(q.id)}'
                                                            )
                                                        "
                                                    >

                                                    ${App.utils.escapeHTML(
                                                        option.text
                                                    )}
                                                </label>
                                            `
                                        )
                                        .join('')
                                }
                            </div>
                        `
                        : ''
                }
            </div>
        `;
    };

App.render.send = function() {
    const root =
        document.getElementById(
            'main_content'
        );

    root.innerHTML = `
        <div class="mb-6">
            <div class="text-sm text-gray-500">
                ホーム ＞ アンケート一覧 ＞ 送信
            </div>

            <h1 class="text-2xl font-bold mt-1">
                メール送信
            </h1>
        </div>

        <div class="
            bg-white border
            rounded-xl p-5
        ">
            <label class="block mb-4">
                <span class="
                    block text-sm
                    font-medium mb-1
                ">
                    顧客検索
                </span>

                <input
                    id="customer_filter"
                    class="
                        w-full border
                        rounded px-3 py-2
                    "
                    oninput="
                        App.actions.renderCustomerList()
                    "
                >
            </label>

            <div
                id="customer_table"
                class="mb-5"
            ></div>

            <label class="block mb-4">
                <span class="
                    block text-sm
                    font-medium mb-1
                ">
                    件名
                </span>

                <input
                    id="mail_subject"
                    class="
                        w-full border
                        rounded px-3 py-2
                    "
                >
            </label>

            <label class="block mb-4">
                <span class="
                    block text-sm
                    font-medium mb-1
                ">
                    本文
                </span>

                <textarea
                    id="mail_body"
                    rows="10"
                    class="
                        w-full border
                        rounded px-3 py-2
                    "
                ></textarea>
            </label>

            <button
                class="
                    bg-blue-600
                    text-white
                    px-4 py-2
                    rounded-lg
                "
                onclick="
                    App.actions.sendBulkMail()
                "
            >
                一括送信
            </button>
        </div>
    `;

    App.actions.renderCustomerList();
};

App.render.aggregate = function() {
    const root =
        document.getElementById(
            'main_content'
        );

    const survey =
        App.state.currentSurvey;

    const responses =
        App.state.responses.filter(
            r =>
                r.survey_id ===
                survey?.id
        );

    root.innerHTML = `
        <div class="mb-6">
            <div class="text-sm text-gray-500">
                ホーム ＞ アンケート一覧 ＞ 集計
            </div>

            <h1 class="
                text-2xl font-bold mt-1
            ">
                集計
            </h1>
        </div>

        <div class="
            bg-white border
            rounded-xl p-5
        ">
            <div class="mb-5">
                回答件数：
                <strong>
                    ${responses.length}
                </strong>
            </div>

            ${
                survey
                    ? survey.groups
                        .map(
                            group =>
                                group.questions
                                    .map(
                                        q =>
                                            App.render.aggregateQuestion(
                                                q,
                                                responses
                                            )
                                    )
                                    .join('')
                        )
                        .join('')
                    : ''
            }
        </div>
    `;
};

App.render.aggregateQuestion =
    function(q, responses) {
        const values =
            responses
                .map(
                    r =>
                        r.answers?.[q.id]
                )
                .filter(
                    value =>
                        value !== undefined &&
                        value !== null &&
                        value !== ''
                );

        return `
            <div class="
                border-b
                py-4
            ">
                <div class="font-bold mb-2">
                    ${App.utils.escapeHTML(
                        q.number
                    )}
                    ${App.utils.escapeHTML(
                        q.text
                    )}
                </div>

                <div class="
                    text-sm text-gray-600
                ">
                    回答数：
                    ${values.length}
                </div>

                ${
                    q.type === 'text' ||
                    q.type === 'textarea'
                        ? `
                            <div class="
                                mt-2 space-y-1
                            ">
                                ${
                                    values
                                        .map(
                                            value =>
                                                `<div class="border rounded p-2">
                                                    ${App.utils.escapeHTML(value)}
                                                </div>`
                                        )
                                        .join('')
                                }
                            </div>
                        `
                        : ''
                }

                ${
                    q.type === 'single'
                        ? `
                            <div class="mt-2">
                                ${
                                    q.options
                                        .map(
                                            option => {
                                                const count =
                                                    values.filter(
                                                        value =>
                                                            value ===
                                                            option.id
                                                    ).length;

                                                return `
                                                    <div class="
                                                        flex
                                                        justify-between
                                                        border-b
                                                        py-1
                                                    ">
                                                        <span>
                                                            ${App.utils.escapeHTML(option.text)}
                                                        </span>
                                                        <span>
                                                            ${count}
                                                        </span>
                                                    </div>
                                                `;
                                            }
                                        )
                                        .join('')
                                }
                            </div>
                        `
                        : ''
                }
            </div>
        `;
    };

App.render.respond = function() {
    const survey =
        App.state.currentSurvey;

    const root =
        document.getElementById(
            'main_content'
        );

    root.innerHTML = `
        <div class="mb-6">
            <h1 class="
                text-2xl font-bold
            ">
                ${App.utils.escapeHTML(
                    survey.title
                )}
            </h1>
        </div>

        <div
            id="response_form"
            class="
                bg-white border
                rounded-xl p-6
            "
        >
            ${
                survey.groups
                    .map(
                        group =>
                            group.questions
                                .map(
                                    q =>
                                        App.render.responseQuestion(
                                            q
                                        )
                                )
                                .join('')
                    )
                    .join('')
            }

            <button
                class="
                    bg-blue-600
                    text-white
                    px-4 py-2
                    rounded-lg
                    mt-5
                "
                onclick="
                    App.actions.submitResponse()
                "
            >
                回答を送信
            </button>

            <div
                id="response_detail"
                class="mt-4"
            ></div>
        </div>
    `;

    App.actions.updateBranchVisibility();
};

App.render.responseQuestion =
    function(q) {
        return `
            <div
                class="
                    response-question
                    mb-6
                "
                data-question-id="${App.utils.escapeHTML(q.id)}"
            >
                <div class="
                    font-medium
                    mb-2
                ">
                    ${App.utils.escapeHTML(q.number)}
                    ${App.utils.escapeHTML(q.text)}

                    ${
                        q.required
                            ? '<span class="text-red-600"> *</span>'
                            : ''
                    }
                </div>

                ${
                    q.type === 'text'
                        ? `
                            <input
                                class="
                                    w-full
                                    border
                                    rounded
                                    px-3
                                    py-2
                                "
                                data-answer-id="${App.utils.escapeHTML(q.id)}"
                                oninput="
                                    App.actions.updateBranchVisibility()
                                "
                            >
                        `
                        : ''
                }

                ${
                    q.type === 'textarea'
                        ? `
                            <textarea
                                rows="4"
                                class="
                                    w-full
                                    border
                                    rounded
                                    px-3
                                    py-2
                                "
                                data-answer-id="${App.utils.escapeHTML(q.id)}"
                            ></textarea>
                        `
                        : ''
                }

                ${
                    q.type === 'single'
                        ? `
                            <div class="space-y-2">
                                ${
                                    q.options
                                        .map(
                                            option => `
                                                <label class="
                                                    flex
                                                    items-center
                                                    gap-2
                                                ">
                                                    <input
                                                        type="radio"
                                                        name="${App.utils.escapeHTML(q.id)}"
                                                        value="${App.utils.escapeHTML(option.id)}"
                                                        onchange="
                                                            App.actions.updateBranchVisibility()
                                                        "
                                                    >

                                                    ${App.utils.escapeHTML(
                                                        option.text
                                                    )}
                                                </label>
                                            `
                                        )
                                        .join('')
                                }
                            </div>
                        `
                        : ''
                }

                ${
                    q.type === 'multiple'
                        ? `
                            <div class="space-y-2">
                                ${
                                    q.options
                                        .map(
                                            option => `
                                                <label class="
                                                    flex
                                                    items-center
                                                    gap-2
                                                ">
                                                    <input
                                                        type="checkbox"
                                                        name="${App.utils.escapeHTML(q.id)}"
                                                        value="${App.utils.escapeHTML(option.id)}"
                                                    >

                                                    ${App.utils.escapeHTML(
                                                        option.text
                                                    )}
                                                </label>
                                            `
                                        )
                                        .join('')
                                }
                            </div>
                        `
                        : ''
                }
            </div>
        `;
    };

App.actions.goList = async function() {
    App.state.screen = 'list';
    App.render.current();
};

App.actions.goSettings = async function() {
    App.state.screen = 'settings';
    App.render.current();
};

App.actions.search = function(value) {
    App.state.keyword = value;
    App.render.list();
};

App.actions.filterStatus = function(value) {
    App.state.statusFilter = value;
    App.render.list();
};

App.actions.sortBy = function(value) {
    App.state.sort = value;
    App.render.list();
};

App.actions.createSurvey = function() {
    App.state.currentSurvey = {
        id:
            'survey_' +
            Date.now() +
            '_' +
            Math.random()
                .toString(36)
                .slice(2),

        title: '',
        start_at: '',
        end_at: '',
        status: 'draft',
        numbering_mode: 'global',
        general_answer_allowed: true,
        groups: [
            App.utils.newGroup()
        ],
        other_settings: {},
        deleted: false
    };

    App.state.screen = 'edit';
    App.render.current();
};

App.actions.editSurvey = function(id) {
    const survey =
        App.state.surveys.find(
            s => s.id === id
        );

    if (!survey) {
        return;
    }

    App.state.currentSurvey =
        JSON.parse(
            JSON.stringify(survey)
        );

    App.state.screen = 'edit';

    App.render.current();
};

App.actions.editCurrent = function() {
    App.state.screen = 'edit';
    App.render.current();
};

App.actions.changeSurveyField =
    function(field, value) {
        if (!App.state.currentSurvey) {
            return;
        }

        App.state.currentSurvey[field] =
            value;

        if (
            field === 'numbering_mode'
        ) {
            App.actions.renumberQuestions();
            App.render.editor();
        }
    };

App.actions.changeSurveyStatus =
    function(value) {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return;
        }

        const old = survey.status;

        if (
            old === 'active' &&
            value === 'ended'
        ) {
            if (!confirm(
                'このアンケートを終了状態に変更しますか？'
            )) {
                const select =
                    document.getElementById(
                        'survey_status'
                    );

                if (select) {
                    select.value = old;
                }

                return;
            }
        }

        if (
            old === 'ended' &&
            value === 'active'
        ) {
            if (!confirm(
                'このアンケートを公開状態に変更しますか？'
            )) {
                const select =
                    document.getElementById(
                        'survey_status'
                    );

                if (select) {
                    select.value = old;
                }

                return;
            }
        }

        if (![
            'draft',
            'active',
            'ended'
        ].includes(value)) {
            return;
        }

        survey.status = value;
    };

App.actions.addGroup = function() {
    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return;
    }

    survey.groups.push(
        App.utils.newGroup()
    );

    App.actions.renumberQuestions();

    App.render.editor();

    App.initSortable();
};

App.actions.deleteGroup =
    function(groupId) {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return;
        }

        if (!confirm(
            'このグループを削除しますか？'
        )) {
            return;
        }

        survey.groups =
            survey.groups.filter(
                g => g.id !== groupId
            );

        if (!survey.groups.length) {
            survey.groups.push(
                App.utils.newGroup()
            );
        }

        App.actions.removeInvalidBranches();
        App.actions.renumberQuestions();

        App.render.editor();
        App.initSortable();
    };

App.actions.changeGroupName =
    function(groupId, value) {
        const group =
            App.state.currentSurvey
                ?.groups
                .find(
                    g => g.id === groupId
                );

        if (group) {
            group.name = value;
        }
    };

App.actions.addQuestion =
    function(groupId) {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return;
        }

        const group =
            survey.groups.find(
                g => g.id === groupId
            );

        if (!group) {
            return;
        }

        group.questions.push(
            App.utils.newQuestion()
        );

        App.actions.renumberQuestions();

        App.render.editor();

        App.initSortable();
    };

App.actions.deleteQuestion =
    function(groupId, questionId) {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return;
        }

        const group =
            survey.groups.find(
                g => g.id === groupId
            );

        if (!group) {
            return;
        }

        group.questions =
            group.questions.filter(
                q => q.id !== questionId
            );

        App.actions.removeInvalidBranches();
        App.actions.renumberQuestions();

        App.render.editor();
        App.initSortable();
    };

App.actions.changeQuestion =
    function(
        groupId,
        questionId,
        field,
        value
    ) {
        const group =
            App.state.currentSurvey
                ?.groups
                .find(
                    g => g.id === groupId
                );

        const question =
            group?.questions.find(
                q => q.id === questionId
            );

        if (!question) {
            return;
        }

        question[field] = value;

        if (
            field === 'type' &&
            value !== 'single'
        ) {
            question.branching = {};
        }

        if (
            field === 'type' &&
            ![
                'single',
                'multiple'
            ].includes(value)
        ) {
            question.options = [];
        }

        App.render.editor();
        App.initSortable();
    };

App.actions.addOption =
    function(
        groupId,
        questionId
    ) {
        const group =
            App.state.currentSurvey
                ?.groups
                .find(
                    g => g.id === groupId
                );

        const question =
            group?.questions.find(
                q => q.id === questionId
            );

        if (!question) {
            return;
        }

        question.options.push({
            id:
                'option_' +
                Date.now() +
                '_' +
                Math.random()
                    .toString(36)
                    .slice(2),
            text: ''
        });

        App.render.editor();
        App.initSortable();
    };

App.actions.changeOption =
    function(
        groupId,
        questionId,
        optionId,
        value
    ) {
        const group =
            App.state.currentSurvey
                ?.groups
                .find(
                    g => g.id === groupId
                );

        const question =
            group?.questions.find(
                q => q.id === questionId
            );

        const option =
            question?.options.find(
                o => o.id === optionId
            );

        if (option) {
            option.text = value;
        }
    };

App.actions.deleteOption =
    function(
        groupId,
        questionId,
        optionId
    ) {
        const group =
            App.state.currentSurvey
                ?.groups
                .find(
                    g => g.id === groupId
                );

        const question =
            group?.questions.find(
                q => q.id === questionId
            );

        if (!question) {
            return;
        }

        question.options =
            question.options.filter(
                o => o.id !== optionId
            );

        delete question.branching[
            optionId
        ];

        App.render.editor();
        App.initSortable();
    };

App.actions.branchCandidates =
    function(questionId) {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return [];
        }

        const flat = [];

        survey.groups.forEach(
            group => {
                group.questions.forEach(
                    question => {
                        flat.push(question);
                    }
                );
            }
        );

        const index =
            flat.findIndex(
                q => q.id === questionId
            );

        if (index < 0) {
            return [];
        }

        return flat.slice(
            index + 1
        );
    };

App.actions.changeBranch =
    function(
        questionId,
        optionId,
        value
    ) {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return;
        }

        for (
            const group
            of survey.groups
        ) {
            const question =
                group.questions.find(
                    q =>
                        q.id === questionId
                );

            if (!question) {
                continue;
            }

            question.branching[
                optionId
            ] =
                value === ''
                    ? null
                    : value;

            break;
        }

        App.actions.removeInvalidBranches();

        App.render.editor();
        App.initSortable();
    };

App.actions.removeInvalidBranches =
    function() {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return;
        }

        const flat = [];

        survey.groups.forEach(
            group => {
                group.questions.forEach(
                    q => flat.push(q)
                );
            }
        );

        const positions = {};

        flat.forEach(
            (q, index) => {
                positions[q.id] =
                    index;
            }
        );

        flat.forEach(
            (question, index) => {
                const branching =
                    question.branching ||
                    {};

                Object.keys(branching)
                    .forEach(
                        optionId => {
                            const target =
                                branching[
                                    optionId
                                ];

                            if (
                                target !== null &&
                                (
                                    positions[target] ===
                                    undefined ||
                                    positions[target] <=
                                    index
                                )
                            ) {
                                branching[
                                    optionId
                                ] = null;
                            }
                        }
                    );
            }
        );
    };

App.actions.renumberQuestions =
    function() {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return;
        }

        let global = 1;

        survey.groups.forEach(
            (group, groupIndex) => {
                group.questions.forEach(
                    (question, questionIndex) => {
                        question.number =
                            survey.numbering_mode ===
                            'group'
                                ? 'Q' +
                                  (groupIndex + 1) +
                                  '-' +
                                  (questionIndex + 1)
                                : 'Q' + global;

                        global++;
                    }
                );
            }
        );

        App.actions.removeInvalidBranches();
    };

App.actions.syncQuestionStructure =
    function() {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return;
        }

        const groups = [];

        document.querySelectorAll(
            '[data-group-id]'
        ).forEach(
            groupEl => {
                if (
                    !groupEl.querySelector(
                        '[data-sortable-questions]'
                    )
                ) {
                    return;
                }

                const groupId =
                    groupEl.dataset.groupId;

                const group =
                    survey.groups.find(
                        g =>
                            g.id ===
                            groupId
                    );

                if (!group) {
                    return;
                }

                const ids = [];

                groupEl.querySelectorAll(
                    '[data-sortable-questions] > [data-question-id]'
                ).forEach(
                    questionEl => {
                        ids.push(
                            questionEl.dataset.questionId
                        );
                    }
                );

                group.questions =
                    ids
                        .map(
                            id =>
                                group.questions.find(
                                    q =>
                                        q.id === id
                                )
                        )
                        .filter(Boolean);

                groups.push(group);
            }
        );

        if (groups.length) {
            survey.groups = groups;
        }

        App.actions.removeInvalidBranches();
        App.actions.renumberQuestions();
    };

App.initSortable = function() {
    App.sortableInstances
        .forEach(
            instance => {
                try {
                    instance.destroy();
                } catch (e) {}
            }
        );

    App.sortableInstances = [];

    const lists =
        document.querySelectorAll(
            '[data-sortable-questions]'
        );

    lists.forEach(
        list => {
            const instance =
                new Sortable(
                    list,
                    {
                        group: {
                            name:
                                'survey_questions',
                            pull: true,
                            put: true
                        },

                        animation: 150,

                        onEnd: function() {
                            App.actions
                                .syncQuestionStructure();

                            App.actions
                                .renumberQuestions();

                            App.render.editor();

                            App.initSortable();
                        }
                    }
                );

            App.sortableInstances.push(
                instance
            );
        }
    );
};

App.actions.saveSurvey =
    async function() {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return;
        }

        App.actions.syncQuestionStructure();
        App.actions.renumberQuestions();

        try {
            const result =
                await App.api.request(
                    'save_survey',
                    {
                        survey_json:
                            survey
                    }
                );

            App.state.currentSurvey =
                result.survey;

            const index =
                App.state.surveys.findIndex(
                    s =>
                        s.id ===
                        result.survey.id
                );

            if (index < 0) {
                App.state.surveys.push(
                    result.survey
                );
            } else {
                App.state.surveys[index] =
                    result.survey;
            }

            alert(
                'アンケートを保存しました。'
            );

        } catch (error) {
            alert(
                error.message ||
                'アンケートの保存に失敗しました。'
            );
        }
    };

App.actions.duplicate =
    async function(id) {
        try {
            const result =
                await App.api.request(
                    'duplicate_survey',
                    {
                        survey_id: id
                    }
                );

            App.state.surveys.push(
                result.survey
            );

            App.render.list();

        } catch (error) {
            alert(
                error.message ||
                '複製に失敗しました。'
            );
        }
    };

App.actions.deleteSurvey =
    async function(id) {
        if (!confirm(
            'このアンケートを削除しますか？'
        )) {
            return;
        }

        try {
            await App.api.request(
                'delete_survey',
                {
                    survey_id: id
                }
            );

            App.state.surveys =
                App.state.surveys.filter(
                    s => s.id !== id
                );

            App.render.list();

        } catch (error) {
            alert(
                error.message ||
                '削除に失敗しました。'
            );
        }
    };

App.actions.saveKintoneSettings =
    async function() {
        const payload = {
            subdomain:
                document.getElementById(
                    'setting_subdomain'
                ).value,

            login_name:
                document.getElementById(
                    'setting_login_name'
                ).value,

            password:
                document.getElementById(
                    'setting_password'
                ).value,

            app_id:
                document.getElementById(
                    'setting_app_id'
                ).value,

            ssl_verify:
                document.getElementById(
                    'setting_ssl_verify'
                ).checked,

            proxy:
                document.getElementById(
                    'setting_proxy'
                ).value,

            field_company:
                document.getElementById(
                    'field_company'
                ).value,

            field_name:
                document.getElementById(
                    'field_name'
                ).value,

            field_email:
                document.getElementById(
                    'field_email'
                ).value,

            field_department:
                document.getElementById(
                    'field_department'
                ).value,

            field_phone:
                document.getElementById(
                    'field_phone'
                ).value,

            field_address:
                document.getElementById(
                    'field_address'
                ).value
        };

        try {
            const result =
                await App.api.request(
                    'save_kintone_settings',
                    payload
                );

            App.state.settings =
                result.settings;

            document.getElementById(
                'setting_password'
            ).value = '';

            App.actions.showResult(
                'kintone_message',
                true,
                result.message,
                result
            );

        } catch (error) {
            App.actions.showResult(
                'kintone_message',
                false,
                error.message ||
                    'キントーン設定の保存に失敗しました。',
                error
            );
        }
    };

App.actions.saveSmtpSettings =
    async function() {
        const payload = {
            smtp_server:
                document.getElementById(
                    'smtp_server'
                ).value,

            smtp_port:
                document.getElementById(
                    'smtp_port'
                ).value,

            smtp_encryption:
                document.getElementById(
                    'smtp_encryption'
                ).value,

            smtp_auth:
                document.getElementById(
                    'smtp_auth'
                ).checked,

            smtp_username:
                document.getElementById(
                    'smtp_username'
                ).value,

            smtp_password:
                document.getElementById(
                    'smtp_password'
                ).value,

            smtp_from_email:
                document.getElementById(
                    'smtp_from_email'
                ).value,

            smtp_from_name:
                document.getElementById(
                    'smtp_from_name'
                ).value,

            smtp_timeout:
                document.getElementById(
                    'smtp_timeout'
                ).value
        };

        try {
            const result =
                await App.api.request(
                    'save_smtp_settings',
                    payload
                );

            App.state.settings =
                result.settings;

            document.getElementById(
                'smtp_password'
            ).value = '';

            App.actions.showResult(
                'smtp_message',
                true,
                result.message,
                result
            );

        } catch (error) {
            App.actions.showResult(
                'smtp_message',
                false,
                error.message ||
                    'SMTP設定の保存に失敗しました。',
                error
            );
        }
    };

App.actions.connectKintone =
    async function() {
        try {
            const result =
                await App.api.request(
                    'connect_kintone'
                );

            App.actions.showResult(
                'kintone_message',
                true,
                result.message,
                result
            );

        } catch (error) {
            App.actions.showResult(
                'kintone_message',
                false,
                error.message ||
                    'キントーン接続確認に失敗しました。',
                error
            );
        }
    };

App.actions.fetchKintoneFields =
    async function() {
        try {
            const result =
                await App.api.request(
                    'fetch_kintone_fields'
                );

            const target =
                document.getElementById(
                    'field_message'
                );

            target.innerHTML = `
                <div class="
                    border
                    border-green-200
                    bg-green-50
                    rounded-lg
                    p-4
                ">
                    <div class="
                        font-bold mb-3
                    ">
                        フィールド取得結果
                    </div>

                    <div class="
                        overflow-x-auto
                    ">
                        <table
                            class="
                                w-full
                                text-sm
                            "
                        >
                            <thead>
                                <tr
                                    class="
                                        border-b
                                    "
                                >
                                    <th class="
                                        text-left
                                        p-2
                                    ">
                                        label
                                    </th>

                                    <th class="
                                        text-left
                                        p-2
                                    ">
                                        code
                                    </th>

                                    <th class="
                                        text-left
                                        p-2
                                    ">
                                        type
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                ${
                                    result.fields
                                        .map(
                                            field => `
                                                <tr
                                                    class="
                                                        border-b
                                                    "
                                                >
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
                                        )
                                        .join('')
                                }
                            </tbody>
                        </table>
                    </div>
                </div>
            `;

        } catch (error) {
            App.actions.showResult(
                'field_message',
                false,
                error.message ||
                    'フィールド取得に失敗しました。',
                error
            );
        }
    };

App.actions.syncCustomers =
    async function() {
        try {
            const result =
                await App.api.request(
                    'sync_customers'
                );

            App.actions.showResult(
                'kintone_message',
                true,
                [
                    result.message,
                    '取得件数：' +
                        result.count,
                    '追加件数：' +
                        result.inserted,
                    '更新件数：' +
                        result.updated,
                    'スキップ件数：' +
                        result.skipped,
                    'エラー件数：' +
                        result.errors
                ].join('\n'),
                result
            );

            await App.actions.loadData();

        } catch (error) {
            App.actions.showResult(
                'kintone_message',
                false,
                error.message ||
                    '顧客データ同期に失敗しました。',
                error
            );
        }
    };

App.actions.testSmtpConnection =
    async function() {
        try {
            const result =
                await App.api.request(
                    'test_smtp_connection'
                );

            App.actions.showResult(
                'smtp_message',
                true,
                result.message,
                result
            );

        } catch (error) {
            App.actions.showResult(
                'smtp_message',
                false,
                error.message ||
                    'SMTP接続確認に失敗しました。',
                error
            );
        }
    };

App.actions.sendSmtpTest =
    async function() {
        const recipient =
            document.getElementById(
                'smtp_test_recipient'
            ).value.trim();

        if (!recipient) {
            App.actions.showResult(
                'smtp_message',
                false,
                'テスト送信先を入力してください。',
                {}
            );
            return;
        }

        try {
            const result =
                await App.api.request(
                    'send_smtp_test',
                    {
                        recipient
                    }
                );

            App.actions.showResult(
                'smtp_message',
                true,
                result.message,
                result
            );

        } catch (error) {
            App.actions.showResult(
                'smtp_message',
                false,
                error.message ||
                    'テストメール送信に失敗しました。',
                error
            );
        }
    };

App.actions.showResult =
    function(
        elementId,
        success,
        message,
        result
    ) {
        const element =
            document.getElementById(
                elementId
            );

        if (!element) {
            return;
        }

        const lines = [];

        if (
            result.http_status !== undefined &&
            result.http_status !== null
        ) {
            lines.push(
                'HTTPステータス：' +
                result.http_status
            );
        }

        if (
            result.smtp_code !== undefined &&
            result.smtp_code !== null
        ) {
            lines.push(
                'SMTP応答コード：' +
                result.smtp_code
            );
        }

        if (result.error_type) {
            lines.push(
                'エラー種別：' +
                result.error_type
            );
        }

        if (result.check_items?.length) {
            lines.push(
                '確認事項：'
            );

            result.check_items.forEach(
                item => {
                    lines.push(
                        '・' + item
                    );
                }
            );
        }

        const escapedMessage =
            App.utils.escapeHTML(
                String(message || '')
            ).replaceAll(
                '\n',
                '<br>'
            );

        element.innerHTML = `
            <div class="
                border
                ${
                    success
                        ? 'border-green-200 bg-green-50 text-green-800'
                        : 'border-red-200 bg-red-50 text-red-800'
                }
                rounded-lg p-4
            ">
                <div class="
                    font-bold mb-2
                ">
                    ${
                        success
                            ? '成功'
                            : '失敗'
                    }
                </div>

                <div>
                    ${escapedMessage}
                </div>

                ${
                    lines.length
                        ? `
                            <div class="mt-3 whitespace-pre-line">
                                ${App.utils.escapeHTML(
                                    lines.join('\n')
                                )}
                            </div>
                        `
                        : ''
                }

                ${
                    result.subdomain
                        ? `
                            <div class="mt-2">
                                接続先：
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
                            <div>
                                対象アプリID：
                                ${Number(result.app_id)}
                            </div>
                        `
                        : ''
                }

                ${
                    result.smtp_server
                        ? `
                            <div class="mt-2">
                                SMTPサーバ：
                                ${App.utils.escapeHTML(
                                    result.smtp_server
                                )}
                            </div>

                            <div>
                                ポート：
                                ${Number(result.smtp_port)}
                            </div>

                            <div>
                                暗号化方式：
                                ${App.utils.escapeHTML(
                                    result.smtp_encryption
                                )}
                            </div>
                        `
                        : ''
                }

                ${
                    result.recipient
                        ? `
                            <div class="mt-2">
                                宛先：
                                ${App.utils.escapeHTML(
                                    result.recipient
                                )}
                            </div>
                        `
                        : ''
                }
            </div>
        `;
    };

App.actions.preview =
    function() {
        App.actions.syncQuestionStructure();
        App.actions.renumberQuestions();

        App.state.previewSurvey =
            JSON.parse(
                JSON.stringify(
                    App.state.currentSurvey
                )
            );

        App.state.screen =
            'preview';

        App.render.current();
    };

App.actions.previewAnswerChange =
    function() {
        App.actions.updateBranchVisibility();
    };

App.actions.updateBranchVisibility =
    function() {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return;
        }

        const answers = {};

        survey.groups.forEach(
            group => {
                group.questions.forEach(
                    q => {
                        if (
                            q.type === 'single'
                        ) {
                            const input =
                                document.querySelector(
                                    `input[name="${CSS.escape(q.id)}"]:checked`
                                );

                            answers[q.id] =
                                input?.value ||
                                null;
                        } else {
                            const input =
                                document.querySelector(
                                    `[data-answer-id="${CSS.escape(q.id)}"]`
                                );

                            if (input) {
                                answers[q.id] =
                                    input.value;
                            }
                        }
                    }
                );
            }
        );

        App.state.answers =
            answers;

        const visible =
            App.actions.calculateVisibleQuestions(
                answers,
                survey
            );

        document.querySelectorAll(
            '[data-question-id]'
        ).forEach(
            element => {
                const id =
                    element.dataset.questionId;

                if (
                    visible[id] === false
                ) {
                    element.classList.add(
                        'hidden'
                    );
                } else {
                    element.classList.remove(
                        'hidden'
                    );
                }
            }
        );
    };

App.actions.calculateVisibleQuestions =
    function(
        answers,
        survey
    ) {
        const visible = {};

        const flat = [];

        survey.groups.forEach(
            group => {
                group.questions.forEach(
                    q => flat.push(q)
                );
            }
        );

        flat.forEach(
            q => {
                visible[q.id] = true;
            }
        );

        /*
         * 各single質問について、
         * 選択された分岐先より前にある
         * 後続質問を非表示にする。
         */
        flat.forEach(
            question => {
                if (
                    question.type !==
                    'single'
                ) {
                    return;
                }

                const answer =
                    answers[
                        question.id
                    ];

                if (!answer) {
                    return;
                }

                const target =
                    question.branching?.[
                        answer
                    ];

                if (!target) {
                    return;
                }

                const currentIndex =
                    flat.findIndex(
                        q =>
                            q.id ===
                            question.id
                    );

                const targetIndex =
                    flat.findIndex(
                        q =>
                            q.id ===
                            target
                    );

                if (
                    currentIndex < 0 ||
                    targetIndex <=
                        currentIndex
                ) {
                    return;
                }

                for (
                    let i =
                        currentIndex + 1;
                    i < targetIndex;
                    i++
                ) {
                    visible[
                        flat[i].id
                    ] = false;
                }
            }
        );

        return visible;
    };

App.actions.validateResponse =
    function(
        answers,
        survey
    ) {
        const visible =
            App.actions.calculateVisibleQuestions(
                answers,
                survey
            );

        const errors = [];

        survey.groups.forEach(
            group => {
                group.questions.forEach(
                    question => {
                        if (
                            visible[
                                question.id
                            ] === false
                        ) {
                            return;
                        }

                        if (
                            !question.required
                        ) {
                            return;
                        }

                        const answer =
                            answers[
                                question.id
                            ];

                        let empty =
                            answer ===
                            undefined ||
                            answer === null ||
                            answer === '';

                        if (
                            Array.isArray(
                                answer
                            )
                        ) {
                            empty =
                                answer.length === 0;
                        }

                        if (empty) {
                            errors.push(
                                question.number +
                                '：' +
                                question.text
                            );
                        }
                    }
                );
            }
        );

        return errors;
    };

App.actions.submitResponse =
    async function() {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return;
        }

        const answers = {};

        survey.groups.forEach(
            group => {
                group.questions.forEach(
                    question => {
                        if (
                            question.type ===
                            'single'
                        ) {
                            const input =
                                document.querySelector(
                                    `input[name="${CSS.escape(question.id)}"]:checked`
                                );

                            answers[
                                question.id
                            ] =
                                input?.value ||
                                null;
                        } else if (
                            question.type ===
                            'multiple'
                        ) {
                            answers[
                                question.id
                            ] =
                                Array.from(
                                    document.querySelectorAll(
                                        `input[name="${CSS.escape(question.id)}"]:checked`
                                    )
                                ).map(
                                    input =>
                                        input.value
                                );
                        } else {
                            const input =
                                document.querySelector(
                                    `[data-answer-id="${CSS.escape(question.id)}"]`
                                );

                            answers[
                                question.id
                            ] =
                                input?.value ||
                                '';
                        }
                    }
                );
            }
        );

        const errors =
            App.actions.validateResponse(
                answers,
                survey
            );

        const detail =
            document.getElementById(
                'response_detail'
            );

        if (errors.length) {
            detail.innerHTML = `
                <div class="
                    border border-red-200
                    bg-red-50
                    text-red-800
                    rounded-lg p-4
                ">
                    <div class="font-bold">
                        必須回答を確認してください。
                    </div>

                    <ul class="
                        list-disc ml-5 mt-2
                    ">
                        ${
                            errors
                                .map(
                                    e =>
                                        `<li>${App.utils.escapeHTML(e)}</li>`
                                )
                                .join('')
                        }
                    </ul>
                </div>
            `;

            return;
        }

        try {
            const result =
                await App.api.request(
                    'save_response',
                    {
                        response_json: {
                            survey_id:
                                survey.id,
                            answers,
                            customer: {},
                            created_at:
                                new Date()
                                    .toISOString()
                        }
                    }
                );

            detail.innerHTML = `
                <div class="
                    border border-green-200
                    bg-green-50
                    text-green-800
                    rounded-lg p-4
                ">
                    回答を保存しました。
                    回答ID：
                    ${App.utils.escapeHTML(
                        result.response_id
                    )}
                </div>
            `;

            localStorage.removeItem(
                'survey_response_' +
                survey.id
            );

        } catch (error) {
            detail.innerHTML = `
                <div class="
                    border border-red-200
                    bg-red-50
                    text-red-800
                    rounded-lg p-4
                ">
                    ${App.utils.escapeHTML(
                        error.message ||
                        '回答保存に失敗しました。'
                    )}
                </div>
            `;
        }
    };

App.actions.aggregate =
    function(id) {
        const survey =
            App.state.surveys.find(
                s => s.id === id
            );

        if (!survey) {
            return;
        }

        App.state.currentSurvey =
            JSON.parse(
                JSON.stringify(survey)
            );

        App.state.screen =
            'aggregate';

        App.render.current();
    };

App.actions.send =
    function(id) {
        const survey =
            App.state.surveys.find(
                s => s.id === id
            );

        if (!survey) {
            return;
        }

        App.state.currentSurvey =
            JSON.parse(
                JSON.stringify(survey)
            );

        App.state.screen =
            'send';

        App.render.current();
    };

App.actions.renderCustomerList =
    function() {
        const root =
            document.getElementById(
                'customer_table'
            );

        if (!root) {
            return;
        }

        const keyword =
            document.getElementById(
                'customer_filter'
            )?.value
            .trim()
            .toLowerCase() ||
            '';

        const customers =
            App.state.customers.filter(
                customer => {
                    if (!keyword) {
                        return true;
                    }

                    return [
                        customer.company,
                        customer.name,
                        customer.email,
                        customer.department,
                        customer.phone
                    ].some(
                        value =>
                            String(value || '')
                                .toLowerCase()
                                .includes(keyword)
                    );
                }
            );

        root.innerHTML = `
            <div class="
                overflow-x-auto
            ">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="p-2">
                                <input
                                    id="select_all"
                                    type="checkbox"
                                    onchange="
                                        App.actions.selectAllCustomers(
                                            this.checked
                                        )
                                    "
                                >
                            </th>

                            <th class="p-2 text-left">
                                会社名
                            </th>

                            <th class="p-2 text-left">
                                氏名
                            </th>

                            <th class="p-2 text-left">
                                メール
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        ${
                            customers
                                .map(
                                    customer => `
                                        <tr class="border-b">
                                            <td class="p-2">
                                                <input
                                                    type="checkbox"
                                                    class="customer-checkbox"
                                                    value="${App.utils.escapeHTML(customer.id)}"
                                                >
                                            </td>

                                            <td class="p-2">
                                                ${App.utils.escapeHTML(customer.company)}
                                            </td>

                                            <td class="p-2">
                                                ${App.utils.escapeHTML(customer.name)}
                                            </td>

                                            <td class="p-2">
                                                ${App.utils.escapeHTML(customer.email)}
                                            </td>
                                        </tr>
                                    `
                                )
                                .join('')
                        }
                    </tbody>
                </table>
            </div>
        `;
    };

App.actions.selectAllCustomers =
    function(checked) {
        document.querySelectorAll(
            '.customer-checkbox'
        ).forEach(
            input => {
                input.checked =
                    checked;
            }
        );
    };

App.actions.sendBulkMail =
    async function() {
        const ids =
            Array.from(
                document.querySelectorAll(
                    '.customer-checkbox:checked'
                )
            ).map(
                input => input.value
            );

        if (!ids.length) {
            alert(
                '送信対象の顧客を選択してください。'
            );
            return;
        }

        const subject =
            document.getElementById(
                'mail_subject'
            ).value;

        const body =
            document.getElementById(
                'mail_body'
            ).value;

        try {
            const result =
                await App.api.request(
                    'send_mail',
                    {
                        recipient_ids:
                            ids,
                        mail_subject:
                            subject,
                        mail_body:
                            body
                    }
                );

            alert(
                '送信済み：' +
                result.sent +
                '\nエラー：' +
                result.errors
            );

        } catch (error) {
            alert(
                error.message ||
                'メール送信に失敗しました。'
            );
        }
    };

App.actions.logout =
    function() {
        /*
         * 管理画面の認証を別途導入する場合に
         * サーバー側logout Actionを追加できる。
         * 現在の単一ファイル版では画面状態のみ初期化。
         */
        App.state.currentSurvey = null;
        App.state.screen = 'list';
        App.render.current();
    };

App.actions.loadData =
    async function() {
        const result =
            await App.api.request(
                'get_data'
            );

        App.state.surveys =
            result.surveys || [];

        App.state.responses =
            result.responses || [];

        App.state.customers =
            result.customers || [];

        App.state.mailLogs =
            result.mail_logs || [];

        App.state.settings =
            result.settings || {
                kintone: {},
                smtp: {}
            };

        if (
            !App.state.settings.kintone
        ) {
            App.state.settings.kintone = {};
        }

        if (
            !App.state.settings.smtp
        ) {
            App.state.settings.smtp = {};
        }
    };

App.actions.startRespond =
    function(id) {
        const survey =
            App.state.surveys.find(
                s => s.id === id
            );

        if (!survey) {
            return;
        }

        App.state.currentSurvey =
            JSON.parse(
                JSON.stringify(survey)
            );

        App.state.answers = {};

        App.state.screen =
            'respond';

        App.render.current();
    };

App.init = async function() {
    if (App.state.initialized) {
        return;
    }

    App.state.initialized = true;

    try {
        await App.actions.loadData();
        App.render.current();
    } catch (error) {
        document.getElementById(
            'app'
        ).innerHTML = `
            <div class="
                min-h-screen
                flex items-center
                justify-center
                p-6
            ">
                <div class="
                    max-w-xl
                    bg-white
                    border
                    rounded-xl
                    p-6
                ">
                    <h1 class="
                        text-xl
                        font-bold
                        text-red-700
                        mb-3
                    ">
                        初期データの取得に失敗しました。
                    </h1>

                    <div>
                        ${App.utils.escapeHTML(
                            error.message ||
                            'API通信に失敗しました。'
                        )}
                    </div>
                </div>
            </div>
        `;
    }
};

if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        () => App.init(),
        {once: true}
    );
} else {
    App.init();
}
</script>

</body>
</html>
<?php
declare(strict_types=1);

/*
 * ============================================================
 * アンケート管理システム
 * Apache 2.4 / PHP 8.5
 * 単一ファイル構成
 *
 * 重要:
 * - APIはこの index.php 自身で処理する
 * - JavaScriptから https://localhost:8443/... へ送信しない
 * - Proxyは host:port 形式
 * - kintoneサブドメインは
 *      https://xxxx.cybozu.com
 *      xxxx.cybozu.com
 *      xxxx
 *   のいずれも受け付け、保存時は xxxx に正規化する
 * ============================================================
 */

const SURVEY_STORAGE_DIRECTORY = 'survey_storage_directory';
const SURVEY_STORAGE_FILE      = 'survey_storage_file';
const SURVEY_ADMIN_SESSION     = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

header_remove('X-Powered-By');

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
        'surveys'   => [],
        'responses' => [],
        'customers' => [],
        'settings'  => [
            'kintone' => [],
            'smtp'    => []
        ],
        'mail_logs' => []
    ];
}

function loadData(): array
{
    $file = storageFile();
    $dir  = dirname($file);

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
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $data = initialData();
        saveData($data);
        return $data;
    }

    if (!is_array($data)) {
        $data = initialData();
    }

    $defaults = initialData();

    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    if (!is_array($data['settings'])) {
        $data['settings'] = [];
    }

    if (
        !isset($data['settings']['kintone']) ||
        !is_array($data['settings']['kintone'])
    ) {
        $data['settings']['kintone'] = [];
    }

    if (
        !isset($data['settings']['smtp']) ||
        !is_array($data['settings']['smtp'])
    ) {
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
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

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

    if (
        $token === '' ||
        !hash_equals(csrf(), $token)
    ) {
        jsonResponse([
            'ok'      => false,
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

function postArray(string $key): array
{
    $value = $_POST[$key] ?? [];

    if (is_array($value)) {
        return $value;
    }

    $decoded = json_decode((string)$value, true);

    return is_array($decoded) ? $decoded : [];
}

/*
 * ------------------------------------------------------------
 * kintoneサブドメイン
 * ------------------------------------------------------------
 *
 * 以下をすべて許可:
 *
 * https://xxxx.cybozu.com
 * xxxx.cybozu.com
 * xxxx
 *
 * 保存値は xxxx に統一。
 */
function normalizeKintoneSubdomain(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    if (
        str_starts_with(
            strtolower($value),
            'https://'
        ) ||
        str_starts_with(
            strtolower($value),
            'http://'
        )
    ) {
        $parts = parse_url($value);

        if (
            $parts === false ||
            empty($parts['host']) ||
            !empty($parts['path']) ||
            !empty($parts['query']) ||
            !empty($parts['fragment'])
        ) {
            return null;
        }

        $value = $parts['host'];
    }

    $suffix = '.cybozu.com';

    if (
        strlen($value) > strlen($suffix) &&
        str_ends_with(
            strtolower($value),
            $suffix
        )
    ) {
        $value = substr(
            $value,
            0,
            -strlen($suffix)
        );
    }

    if (
        !preg_match(
            '/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/',
            $value
        )
    ) {
        return null;
    }

    return strtolower($value);
}

/*
 * ------------------------------------------------------------
 * Proxy
 * ------------------------------------------------------------
 *
 * 要件:
 *
 *     host名:port番号
 *
 * http:// / https:// は必須ではない。
 *
 * 例:
 *
 * proxy.example.local:8080
 * 192.168.1.10:3128
 */
function normalizeProxy(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (
        str_contains($value, '://') ||
        str_contains($value, '/') ||
        str_contains($value, '?') ||
        str_contains($value, '#') ||
        str_contains($value, '@')
    ) {
        return null;
    }

    if (
        !preg_match(
            '/^([a-zA-Z0-9][a-zA-Z0-9.-]*|\[[0-9a-fA-F:]+\]):([0-9]{1,5})$/',
            $value,
            $m
        )
    ) {
        return null;
    }

    $port = (int)$m[2];

    if ($port < 1 || $port > 65535) {
        return null;
    }

    return $value;
}

function proxyParts(string $proxy): ?array
{
    if ($proxy === '') {
        return null;
    }

    if (
        !preg_match(
            '/^(.+):([0-9]{1,5})$/',
            $proxy,
            $m
        )
    ) {
        return null;
    }

    return [
        'host' => $m[1],
        'port' => (int)$m[2]
    ];
}

/*
 * ------------------------------------------------------------
 * パスワード
 * ------------------------------------------------------------
 */

function passwordForSave(
    string $newValue,
    array $old,
    string $key
): ?string {
    if ($newValue !== '') {
        return $newValue;
    }

    if (isset($old[$key]) && is_string($old[$key])) {
        return $old[$key];
    }

    return null;
}

/*
 * ------------------------------------------------------------
 * 設定検証
 * ------------------------------------------------------------
 */

function validateKintone(
    array $input,
    array $old
): array {
    $subdomain = normalizeKintoneSubdomain(
        (string)($input['subdomain'] ?? '')
    );

    if ($subdomain === null) {
        return [
            'ok'      => false,
            'message' =>
                'サブドメインは https://xxxx.cybozu.com、xxxx.cybozu.com、xxxx のいずれかの形式で入力してください。'
        ];
    }

    $login = trim(
        (string)($input['login_name'] ?? '')
    );

    if ($login === '') {
        return [
            'ok'      => false,
            'message' => 'ログイン名を入力してください。'
        ];
    }

    $password = passwordForSave(
        (string)($input['password'] ?? ''),
        $old,
        'password'
    );

    if ($password === null || $password === '') {
        return [
            'ok'      => false,
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
            'ok'      => false,
            'message' =>
                '顧客管理アプリIDは1以上の整数で入力してください。'
        ];
    }

    $proxy = normalizeProxy(
        (string)($input['proxy'] ?? '')
    );

    if ($proxy === null) {
        return [
            'ok'      => false,
            'message' =>
                'Proxyはhost名:port番号の形式で入力してください。'
        ];
    }

    return [
        'ok'   => true,
        'data' => [
            'subdomain'        => $subdomain,
            'login_name'       => $login,
            'password'         => $password,
            'app_id'           => (int)$appId,
            'ssl_verify'      => !empty($input['ssl_verify']),
            'proxy'            => $proxy,
            'field_company'    => trim((string)($input['field_company'] ?? '')),
            'field_name'       => trim((string)($input['field_name'] ?? '')),
            'field_email'      => trim((string)($input['field_email'] ?? '')),
            'field_department' => trim((string)($input['field_department'] ?? '')),
            'field_phone'      => trim((string)($input['field_phone'] ?? '')),
            'field_address'    => trim((string)($input['field_address'] ?? ''))
        ]
    ];
}

function validateSmtp(
    array $input,
    array $old
): array {
    $server = trim(
        (string)($input['smtp_server'] ?? '')
    );

    if ($server === '') {
        return [
            'ok'      => false,
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
            'ok'      => false,
            'message' => 'SMTPポートが不正です。'
        ];
    }

    $encryption = (string)(
        $input['smtp_encryption'] ?? 'none'
    );

    if (
        !in_array(
            $encryption,
            ['none', 'starttls', 'ssl'],
            true
        )
    ) {
        return [
            'ok'      => false,
            'message' => '暗号化方式が不正です。'
        ];
    }

    $auth = !empty(
        $input['smtp_auth']
    );

    $username = trim(
        (string)($input['smtp_username'] ?? '')
    );

    $password = passwordForSave(
        (string)($input['smtp_password'] ?? ''),
        $old,
        'smtp_password'
    );

    if ($auth && $username === '') {
        return [
            'ok'      => false,
            'message' =>
                'SMTP認証を有効にする場合はSMTPユーザー名を入力してください。'
        ];
    }

    if (
        $auth &&
        ($password === null || $password === '')
    ) {
        return [
            'ok'      => false,
            'message' =>
                'SMTP認証を有効にする場合はSMTPパスワードを入力してください。'
        ];
    }

    $fromEmail = trim(
        (string)($input['smtp_from_email'] ?? '')
    );

    if (
        !filter_var(
            $fromEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        return [
            'ok'      => false,
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
            'ok'      => false,
            'message' =>
                '接続タイムアウトは1～300秒で指定してください。'
        ];
    }

    if (
        $encryption === 'ssl' &&
        $port === 587
    ) {
        return [
            'ok'      => false,
            'message' =>
                'SSL方式と587番ポートの組み合わせは不自然です。通常はSTARTTLSを使用してください。'
        ];
    }

    if (
        $encryption === 'starttls' &&
        $port === 465
    ) {
        return [
            'ok'      => false,
            'message' =>
                'STARTTLS方式と465番ポートの組み合わせは不自然です。通常はSSLを使用してください。'
        ];
    }

    return [
        'ok'   => true,
        'data' => [
            'smtp_server'      => $server,
            'smtp_port'        => (int)$port,
            'smtp_encryption'  => $encryption,
            'smtp_auth'        => $auth,
            'smtp_username'    => $username,
            'smtp_password'    => $password ?? '',
            'smtp_from_email'  => $fromEmail,
            'smtp_from_name'   => trim(
                (string)($input['smtp_from_name'] ?? '')
            ),
            'smtp_timeout'     => (int)$timeout
        ]
    ];
}

/*
 * ------------------------------------------------------------
 * 安全な設定レスポンス
 * ------------------------------------------------------------
 */

function publicKintoneSettings(array $settings): array
{
    $result = $settings;

    unset($result['password']);

    $result['password_configured'] =
        !empty($settings['password']);

    return $result;
}

function publicSmtpSettings(array $settings): array
{
    $result = $settings;

    unset($result['smtp_password']);

    $result['password_configured'] =
        !empty($settings['smtp_password']);

    return $result;
}

function publicSettings(array $settings): array
{
    return [
        'kintone' => publicKintoneSettings(
            is_array($settings['kintone'] ?? null)
                ? $settings['kintone']
                : []
        ),
        'smtp' => publicSmtpSettings(
            is_array($settings['smtp'] ?? null)
                ? $settings['smtp']
                : []
        )
    ];
}

/*
 * ------------------------------------------------------------
 * kintone API
 * ------------------------------------------------------------
 */

function kintoneUrl(
    array $config,
    string $path
): string {
    return 'https://' .
        $config['subdomain'] .
        '.cybozu.com/k/v1/' .
        ltrim($path, '/');
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
            'ok'         => false,
            'error_type' => 'connection',
            'http_status'=> 0,
            'message'    =>
                'cURLを初期化できませんでした。'
        ];
    }

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json'
    ];

    $options = [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_CUSTOMREQUEST   => $method,
        CURLOPT_HTTPHEADER      => $headers,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_TIMEOUT         => 30,
        CURLOPT_SSL_VERIFYPEER  =>
            !empty($config['ssl_verify']),
        CURLOPT_SSL_VERIFYHOST  =>
            !empty($config['ssl_verify']) ? 2 : 0,
        CURLOPT_FOLLOWLOCATION  => false
    ];

    $proxy = (string)($config['proxy'] ?? '');

    if ($proxy !== '') {
        $parts = proxyParts($proxy);

        if ($parts === null) {
            curl_close($ch);

            return [
                'ok'         => false,
                'error_type' => 'proxy',
                'http_status'=> 0,
                'message'    =>
                    '保存されているProxy設定が不正です。host名:port番号で指定してください。',
                'check_items' => [
                    'Proxyホスト名',
                    'Proxyポート番号'
                ]
            ];
        }

        curl_setopt(
            $ch,
            CURLOPT_PROXY,
            $parts['host']
        );

        curl_setopt(
            $ch,
            CURLOPT_PROXYPORT,
            $parts['port']
        );
    }

    /*
     * kintoneのユーザー名・パスワードは
     * HTTP Basic認証として送信する。
     */
    curl_setopt(
        $ch,
        CURLOPT_USERPWD,
        (string)$config['login_name'] .
        ':' .
        (string)$config['password']
    );

    if ($body !== null) {
        $encoded = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($encoded === false) {
            curl_close($ch);

            return [
                'ok'         => false,
                'error_type' => 'configuration',
                'message'    =>
                    'kintone APIリクエストを生成できませんでした。'
            ];
        }

        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            $encoded
        );
    }

    $response = curl_exec($ch);

    $errno = curl_errno($ch);
    $error = curl_error($ch);

    $status = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if ($response === false) {
        $type = 'connection';
        $message =
            'kintoneへの接続に失敗しました。';

        if (
            $errno === CURLE_COULDNT_RESOLVE_HOST
        ) {
            $type = 'dns';
            $message =
                'kintoneホストのDNS解決に失敗しました。';
        } elseif (
            $errno === CURLE_COULDNT_CONNECT
        ) {
            $type = 'connection';
            $message =
                'kintoneサーバへTCP接続できませんでした。';
        } elseif (
            $errno === CURLE_OPERATION_TIMEDOUT
        ) {
            $type = 'timeout';
            $message =
                'kintoneへの接続がタイムアウトしました。';
        } elseif (
            $errno === CURLE_SSL_CONNECT_ERROR
        ) {
            $type = 'tls';
            $message =
                'kintoneとのTLS/SSL接続に失敗しました。';
        }

        if ($proxy !== '' && $type === 'connection') {
            $type = 'proxy';
            $message =
                'Proxy経由のkintone接続に失敗しました。';
        }

        return [
            'ok'          => false,
            'error_type'  => $type,
            'http_status' => $status,
            'message'     => $message,
            'detail'      => $error,
            'check_items' => match ($type) {
                'dns' => [
                    'サブドメイン',
                    'DNS設定',
                    'ネットワーク接続'
                ],
                'proxy' => [
                    'Proxyホスト名',
                    'Proxyポート番号',
                    'Proxyから外部HTTPSへの接続'
                ],
                'tls' => [
                    'SSL証明書検証設定',
                    'PHP/cURLのTLS環境',
                    'ProxyのTLS中継設定'
                ],
                'timeout' => [
                    'ネットワーク接続',
                    'Proxy設定',
                    'ファイアウォール'
                ],
                default => [
                    'サブドメイン',
                    'ネットワーク接続',
                    'Proxy設定'
                ]
            }
        ];
    }

    $decoded = json_decode(
        (string)$response,
        true
    );

    if ($status < 200 || $status >= 300) {
        $type = 'api';

        if ($status === 401) {
            $type = 'authentication';
        } elseif ($status === 403) {
            $type = 'authorization';
        } elseif (
            $status >= 400 &&
            $status < 500
        ) {
            $type = 'http_4xx';
        } elseif ($status >= 500) {
            $type = 'http_5xx';
        }

        $message = '';

        if (is_array($decoded)) {
            $message = trim(
                (string)($decoded['message'] ?? '')
            );
        }

        /*
         * kintone APIの安全なmessageのみ返す。
         * Authorization等は返さない。
         */
        if ($message === '') {
            $message = match ($status) {
                401 =>
                    'kintone APIの認証に失敗しました。',
                403 =>
                    'kintone APIへのアクセス権がありません。',
                404 =>
                    '指定したkintone APIまたはアプリが見つかりません。',
                408 =>
                    'kintone APIへの要求がタイムアウトしました。',
                429 =>
                    'kintone APIの利用回数制限に達しました。',
                500, 502, 503, 504 =>
                    'kintone側でサーバーエラーが発生しました。',
                default =>
                    'kintone APIがエラーを返しました。'
            };
        }

        return [
            'ok'          => false,
            'error_type'  => $type,
            'http_status' => $status,
            'message'     => $message,
            'check_items' => match ($type) {
                'authentication' => [
                    'サブドメイン',
                    'ログイン名',
                    'パスワード',
                    'kintone側の認証設定'
                ],
                'authorization' => [
                    'ログインユーザーの権限',
                    '対象アプリの権限',
                    'kintone側のアクセス権'
                ],
                'http_4xx' => [
                    'サブドメイン',
                    'アプリID',
                    'kintone側の権限',
                    'API設定'
                ],
                'http_5xx' => [
                    'kintoneサービス状態',
                    'ネットワーク',
                    'Proxy設定'
                ],
                default => [
                    'kintone設定',
                    'アプリID',
                    'kintone側のAPI設定'
                ]
            }
        ];
    }

    return [
        'ok'          => true,
        'http_status' => $status,
        'data'        =>
            is_array($decoded)
                ? $decoded
                : []
    ];
}

/*
 * ------------------------------------------------------------
 * SMTP
 * ------------------------------------------------------------
 */

function smtpRead($socket): array
{
    $response = '';

    while (
        ($line = fgets($socket, 8192)) !== false
    ) {
        $response .= $line;

        if (
            strlen($line) >= 4 &&
            $line[3] === ' '
        ) {
            break;
        }
    }

    $code = 0;

    if (
        preg_match(
            '/^(\d{3})/',
            $response,
            $m
        )
    ) {
        $code = (int)$m[1];
    }

    return [
        'code'     => $code,
        'response' => trim($response)
    ];
}

function smtpCommand(
    $socket,
    string $command
): array {
    $written = @fwrite(
        $socket,
        $command . "\r\n"
    );

    if ($written === false) {
        return [
            'code'     => 0,
            'response' => 'SMTPコマンド送信に失敗しました。'
        ];
    }

    return smtpRead($socket);
}

function smtpConnect(
    array $config
): array {
    $server = (string)$config['smtp_server'];
    $port   = (int)$config['smtp_port'];
    $timeout= (int)$config['smtp_timeout'];

    $target = $server;

    if (
        $config['smtp_encryption'] === 'ssl'
    ) {
        $target = 'ssl://' . $server;
    }

    $errno  = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $target . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        $lower = strtolower($errstr);

        $type = 'connection';
        $message =
            'SMTPサーバへ接続できませんでした。';

        if (
            str_contains($lower, 'getaddrinfo') ||
            str_contains($lower, 'name or service') ||
            str_contains($lower, 'could not resolve')
        ) {
            $type = 'dns';
            $message =
                'SMTPサーバ名のDNS解決に失敗しました。';
        } elseif (
            str_contains($lower, 'timed out') ||
            str_contains($lower, 'timeout')
        ) {
            $type = 'timeout';
            $message =
                'SMTPサーバへの接続がタイムアウトしました。';
        } elseif (
            $config['smtp_encryption'] !== 'none' &&
            (
                str_contains($lower, 'ssl') ||
                str_contains($lower, 'tls')
            )
        ) {
            $type = 'tls';
            $message =
                'SMTP TLS/SSL接続に失敗しました。';
        }

        return [
            'ok'          => false,
            'error_type'  => $type,
            'smtp_code'   => null,
            'message'     => $message,
            'detail'      => $errstr,
            'check_items' => match ($type) {
                'dns' => [
                    'SMTPサーバ名',
                    'DNS設定'
                ],
                'timeout' => [
                    'SMTPサーバ',
                    'SMTPポート',
                    'ファイアウォール'
                ],
                'tls' => [
                    '暗号化方式',
                    'SMTPポート',
                    'SMTPサーバのTLS設定'
                ],
                default => [
                    'SMTPサーバ',
                    'SMTPポート',
                    'ネットワーク接続'
                ]
            }
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
            'ok'         => false,
            'error_type' => 'smtp_response',
            'smtp_code'  => $greeting['code'],
            'message'    =>
                'SMTPサーバから正常な接続応答を受信できませんでした。'
        ];
    }

    $localHost =
        $_SERVER['SERVER_NAME'] ??
        'localhost';

    $ehlo = smtpCommand(
        $socket,
        'EHLO ' . $localHost
    );

    if ($ehlo['code'] >= 400) {
        $ehlo = smtpCommand(
            $socket,
            'HELO ' . $localHost
        );
    }

    if ($ehlo['code'] >= 400) {
        fclose($socket);

        return [
            'ok'         => false,
            'error_type' => 'smtp_protocol',
            'smtp_code'  => $ehlo['code'],
            'message'    =>
                'SMTP EHLO/HELOに失敗しました。'
        ];
    }

    if (
        $config['smtp_encryption'] === 'starttls'
    ) {
        $tls = smtpCommand(
            $socket,
            'STARTTLS'
        );

        if ($tls['code'] !== 220) {
            fclose($socket);

            return [
                'ok'         => false,
                'error_type' => 'tls',
                'smtp_code'  => $tls['code'],
                'message'    =>
                    'SMTP STARTTLSを開始できませんでした。'
            ];
        }

        $crypto = @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);

            return [
                'ok'         => false,
                'error_type' => 'tls',
                'smtp_code'  => null,
                'message'    =>
                    'SMTP TLSネゴシエーションに失敗しました。'
            ];
        }

        $ehlo = smtpCommand(
            $socket,
            'EHLO ' . $localHost
        );

        if ($ehlo['code'] >= 400) {
            fclose($socket);

            return [
                'ok'         => false,
                'error_type' => 'smtp_protocol',
                'smtp_code'  => $ehlo['code'],
                'message'    =>
                    'TLS接続後のEHLOに失敗しました。'
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
                'ok'         => false,
                'error_type' => 'authentication',
                'smtp_code'  => $auth['code'],
                'message'    =>
                    'SMTP認証を開始できませんでした。'
            ];
        }

        $user = smtpCommand(
            $socket,
            base64_encode(
                (string)$config['smtp_username']
            )
        );

        if ($user['code'] !== 334) {
            fclose($socket);

            return [
                'ok'         => false,
                'error_type' => 'authentication',
                'smtp_code'  => $user['code'],
                'message'    =>
                    'SMTPユーザー名の認証に失敗しました。'
            ];
        }

        $pass = smtpCommand(
            $socket,
            base64_encode(
                (string)$config['smtp_password']
            )
        );

        if (
            $pass['code'] < 200 ||
            $pass['code'] >= 300
        ) {
            fclose($socket);

            return [
                'ok'         => false,
                'error_type' => 'authentication',
                'smtp_code'  => $pass['code'],
                'message'    =>
                    'SMTP認証に失敗しました。',
                'check_items' => [
                    'SMTPユーザー名',
                    'SMTPパスワード',
                    'SMTP認証方式',
                    'SMTPポート',
                    '暗号化方式'
                ]
            ];
        }
    }

    return [
        'ok'       => true,
        'socket'   => $socket,
        'smtp_code'=> $ehlo['code']
    ];
}

function smtpSend(
    array $config,
    string $recipient,
    string $subject,
    string $body
): array {
    if (
        !filter_var(
            $recipient,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        return [
            'ok'         => false,
            'error_type' => 'configuration',
            'message'    =>
                'テスト宛先メールアドレスが不正です。'
        ];
    }

    $connection = smtpConnect($config);

    if (!$connection['ok']) {
        return $connection;
    }

    $socket = $connection['socket'];

    $from = (string)$config['smtp_from_email'];

    $result = smtpCommand(
        $socket,
        'MAIL FROM:<' . $from . '>'
    );

    if (
        $result['code'] < 200 ||
        $result['code'] >= 300
    ) {
        fclose($socket);

        return [
            'ok'         => false,
            'error_type' => 'smtp_response',
            'smtp_code'  => $result['code'],
            'message'    =>
                'MAIL FROMがSMTPサーバに拒否されました。'
        ];
    }

    $result = smtpCommand(
        $socket,
        'RCPT TO:<' . $recipient . '>'
    );

    if (
        $result['code'] < 200 ||
        $result['code'] >= 300
    ) {
        fclose($socket);

        return [
            'ok'         => false,
            'error_type' => 'smtp_response',
            'smtp_code'  => $result['code'],
            'message'    =>
                '指定された宛先がSMTPサーバに拒否されました。'
        ];
    }

    $result = smtpCommand(
        $socket,
        'DATA'
    );

    if ($result['code'] !== 354) {
        fclose($socket);

        return [
            'ok'         => false,
            'error_type' => 'smtp_response',
            'smtp_code'  => $result['code'],
            'message'    =>
                'SMTP DATAを開始できませんでした。'
        ];
    }

    $fromName =
        trim((string)(
            $config['smtp_from_name'] ?? ''
        ));

    $fromHeader = $from;

    if ($fromName !== '') {
        $fromHeader =
            '=?UTF-8?B?' .
            base64_encode($fromName) .
            '?= <' .
            $from .
            '>';
    }

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

    /*
     * DATA中の行頭"."をエスケープ。
     */
    $body = preg_replace(
        '/^\./m',
        '..',
        $body
    );

    $message =
        'From: ' . $fromHeader . "\r\n" .
        'To: <' . $recipient . ">\r\n" .
        'Subject: ' . $encodedSubject . "\r\n" .
        "MIME-Version: 1.0\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\n" .
        "Content-Transfer-Encoding: 8bit\r\n" .
        "\r\n" .
        $body .
        "\r\n.\r\n";

    if (@fwrite($socket, $message) === false) {
        fclose($socket);

        return [
            'ok'         => false,
            'error_type' => 'connection',
            'smtp_code'  => null,
            'message'    =>
                'SMTP DATAの送信に失敗しました。'
        ];
    }

    $result = smtpRead($socket);

    @fwrite(
        $socket,
        "QUIT\r\n"
    );

    fclose($socket);

    if (
        $result['code'] < 200 ||
        $result['code'] >= 300
    ) {
        return [
            'ok'         => false,
            'error_type' => 'smtp_response',
            'smtp_code'  => $result['code'],
            'message'    =>
                'メール送信がSMTPサーバに拒否されました。'
        ];
    }

    return [
        'ok'         => true,
        'smtp_code'  => $result['code'],
        'message'    =>
            'メールを送信しました。'
    ];
}

/*
 * ------------------------------------------------------------
 * アンケート正規化
 * ------------------------------------------------------------
 */

function normalizeQuestion(
    array $question
): array {
    $question['id'] =
        (string)($question['id'] ?? makeId('question'));

    $question['text'] =
        (string)($question['text'] ?? '');

    $question['type'] =
        in_array(
            $question['type'] ?? 'text',
            ['text', 'textarea', 'single', 'multiple', 'date'],
            true
        )
            ? $question['type']
            : 'text';

    $question['required'] =
        !empty($question['required']);

    $question['other_enabled'] =
        !empty($question['other_enabled']);

    $question['options'] =
        is_array($question['options'] ?? null)
            ? array_values($question['options'])
            : [];

    $question['branching'] =
        is_array($question['branching'] ?? null)
            ? $question['branching']
            : [];

    foreach ($question['options'] as $i => &$option) {
        if (!is_array($option)) {
            $option = [
                'id'   => makeId('option'),
                'text' => (string)$option
            ];
        }

        $option['id'] =
            (string)($option['id'] ?? makeId('option'));

        $option['text'] =
            (string)($option['text'] ?? '');
    }

    unset($option);

    return $question;
}

function normalizeSurvey(
    array $survey
): array {
    $survey['id'] =
        (string)($survey['id'] ?? makeId('survey'));

    $survey['title'] =
        (string)($survey['title'] ?? '');

    $survey['start_at'] =
        (string)($survey['start_at'] ?? '');

    $survey['end_at'] =
        (string)($survey['end_at'] ?? '');

    $survey['status'] =
        in_array(
            $survey['status'] ?? 'draft',
            ['draft', 'active', 'ended'],
            true
        )
            ? $survey['status']
            : 'draft';

    $survey['numbering_mode'] =
        (string)($survey['numbering_mode'] ?? 'global');

    $survey['general_response_allowed'] =
        array_key_exists(
            'general_response_allowed',
            $survey
        )
            ? !empty($survey['general_response_allowed'])
            : true;

    $survey['groups'] =
        is_array($survey['groups'] ?? null)
            ? array_values($survey['groups'])
            : [];

    foreach ($survey['groups'] as &$group) {
        if (!is_array($group)) {
            $group = [];
        }

        $group['id'] =
            (string)($group['id'] ?? makeId('group'));

        $group['name'] =
            (string)($group['name'] ?? '');

        $group['questions'] =
            is_array($group['questions'] ?? null)
                ? array_values($group['questions'])
                : [];

        foreach (
            $group['questions'] as &$question
        ) {
            $question =
                normalizeQuestion($question);
        }

        unset($question);
    }

    unset($group);

    $survey['settings'] =
        is_array($survey['settings'] ?? null)
            ? $survey['settings']
            : [];

    return $survey;
}

/*
 * ------------------------------------------------------------
 * API
 * ------------------------------------------------------------
 */

function handleApi(): never
{
    requireCsrf();

    $action = (string)(
        $_POST['action'] ?? ''
    );

    $data = loadData();

    switch ($action) {

        case 'get_settings':
            jsonResponse([
                'ok'       => true,
                'settings' =>
                    publicSettings(
                        $data['settings']
                    )
            ]);

        case 'save_kintone_settings':
            $input = postArray('settings_json');

            /*
             * settings_jsonがJSON文字列の場合にも対応。
             */
            if (!$input) {
                $input = [
                    'subdomain' =>
                        (string)($_POST['setting_subdomain'] ?? ''),
                    'login_name' =>
                        (string)($_POST['setting_login_name'] ?? ''),
                    'password' =>
                        (string)($_POST['setting_password'] ?? ''),
                    'app_id' =>
                        (string)($_POST['app_id'] ?? ''),
                    'ssl_verify' =>
                        !empty($_POST['setting_ssl_verify']),
                    'proxy' =>
                        (string)($_POST['setting_proxy'] ?? ''),
                    'field_company' =>
                        (string)($_POST['field_company'] ?? ''),
                    'field_name' =>
                        (string)($_POST['field_name'] ?? ''),
                    'field_email' =>
                        (string)($_POST['field_email'] ?? ''),
                    'field_department' =>
                        (string)($_POST['field_department'] ?? ''),
                    'field_phone' =>
                        (string)($_POST['field_phone'] ?? ''),
                    'field_address' =>
                        (string)($_POST['field_address'] ?? '')
                ];
            }

            $old =
                is_array($data['settings']['kintone'] ?? null)
                    ? $data['settings']['kintone']
                    : [];

            $validated = validateKintone(
                $input,
                $old
            );

            if (!$validated['ok']) {
                jsonResponse(
                    $validated,
                    422
                );
            }

            $newData = $data;

            /*
             * kintoneだけを更新。
             * SMTPには触れない。
             */
            $newData['settings']['kintone'] =
                $validated['data'];

            if (!saveData($newData)) {
                jsonResponse([
                    'ok'      => false,
                    'message' =>
                        'キントーン設定の保存に失敗しました。survey_storageへの書き込み権限とディスク容量を確認してください。'
                ], 500);
            }

            jsonResponse([
                'ok'      => true,
                'message' =>
                    'キントーン設定を保存しました。',
                'settings' => publicSettings(
                    $newData['settings']
                )
            ]);

        case 'save_smtp_settings':
            $input = postArray('settings_json');

            if (!$input) {
                $input = [
                    'smtp_server' =>
                        (string)($_POST['smtp_server'] ?? ''),
                    'smtp_port' =>
                        (string)($_POST['smtp_port'] ?? ''),
                    'smtp_encryption' =>
                        (string)($_POST['smtp_encryption'] ?? 'none'),
                    'smtp_auth' =>
                        !empty($_POST['smtp_auth']),
                    'smtp_username' =>
                        (string)($_POST['smtp_username'] ?? ''),
                    'smtp_password' =>
                        (string)($_POST['smtp_password'] ?? ''),
                    'smtp_from_email' =>
                        (string)($_POST['smtp_from_email'] ?? ''),
                    'smtp_from_name' =>
                        (string)($_POST['smtp_from_name'] ?? ''),
                    'smtp_timeout' =>
                        (string)($_POST['smtp_timeout'] ?? '10')
                ];
            }

            $old =
                is_array($data['settings']['smtp'] ?? null)
                    ? $data['settings']['smtp']
                    : [];

            $validated = validateSmtp(
                $input,
                $old
            );

            if (!$validated['ok']) {
                jsonResponse(
                    $validated,
                    422
                );
            }

            $newData = $data;

            /*
             * SMTPだけを更新。
             * kintoneには触れない。
             */
            $newData['settings']['smtp'] =
                $validated['data'];

            if (!saveData($newData)) {
                jsonResponse([
                    'ok'      => false,
                    'message' =>
                        'SMTP設定の保存に失敗しました。survey_storageへの書き込み権限とディスク容量を確認してください。'
                ], 500);
            }

            jsonResponse([
                'ok'      => true,
                'message' =>
                    'SMTP設定を保存しました。',
                'settings' => publicSettings(
                    $newData['settings']
                )
            ]);

        case 'connect_kintone':
            $config =
                $data['settings']['kintone'] ?? [];

            if (
                !is_array($config) ||
                empty($config['subdomain']) ||
                empty($config['login_name']) ||
                empty($config['password']) ||
                empty($config['app_id'])
            ) {
                jsonResponse([
                    'ok'          => false,
                    'error_type'  => 'configuration',
                    'message'     =>
                        '保存済みのキントーン設定が不足しています。先にキントーン設定を保存してください。',
                    'check_items' => [
                        'サブドメイン',
                        'ログイン名',
                        'パスワード',
                        '顧客管理アプリID'
                    ]
                ], 422);
            }

            $result = kintoneRequest(
                $config,
                'GET',
                'record.json?app=' .
                rawurlencode(
                    (string)$config['app_id']
                ) .
                '&totalCount=true&query=' .
                rawurlencode(
                    'limit 1'
                )
            );

            if (!$result['ok']) {
                jsonResponse($result);
            }

            jsonResponse([
                'ok'          => true,
                'message'     =>
                    'キントーンへの接続に成功しました。',
                'http_status' =>
                    $result['http_status'],
                'subdomain'   =>
                    $config['subdomain'],
                'app_id'      =>
                    (int)$config['app_id']
            ]);

        case 'fetch_kintone_fields':
            $config =
                $data['settings']['kintone'] ?? [];

            if (
                !is_array($config) ||
                empty($config['subdomain']) ||
                empty($config['login_name']) ||
                empty($config['password']) ||
                empty($config['app_id'])
            ) {
                jsonResponse([
                    'ok'         => false,
                    'error_type' => 'configuration',
                    'message'    =>
                        '保存済みのキントーン設定が不足しています。'
                ], 422);
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
                jsonResponse($result);
            }

            $properties =
                $result['data']['properties'] ?? [];

            $fields = [];

            foreach (
                $properties as $code => $property
            ) {
                if (!is_array($property)) {
                    continue;
                }

                $fields[] = [
                    'label' =>
                        (string)($property['label'] ?? ''),
                    'code' =>
                        (string)($property['code'] ?? $code),
                    'type' =>
                        (string)($property['type'] ?? '')
                ];
            }

            jsonResponse([
                'ok'          => true,
                'message'     =>
                    'kintoneフィールドを取得しました。',
                'http_status' =>
                    $result['http_status'],
                'fields'      => $fields
            ]);

        case 'sync_customers':
            $config =
                $data['settings']['kintone'] ?? [];

            if (
                !is_array($config) ||
                empty($config['app_id']) ||
                empty($config['subdomain']) ||
                empty($config['login_name']) ||
                empty($config['password'])
            ) {
                jsonResponse([
                    'ok'         => false,
                    'error_type' => 'configuration',
                    'message'    =>
                        '保存済みのキントーン設定が不足しています。'
                ], 422);
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
                jsonResponse($result);
            }

            $records =
                $result['data']['records'] ?? [];

            if (!is_array($records)) {
                $records = [];
            }

            $inserted = 0;
            $updated  = 0;
            $skipped  = 0;
            $errors   = 0;

            $customers =
                is_array($data['customers'])
                    ? $data['customers']
                    : [];

            $index = [];

            foreach (
                $customers as $i => $customer
            ) {
                if (!is_array($customer)) {
                    continue;
                }

                $key =
                    (string)($customer['kintone_id'] ?? '');

                if ($key !== '') {
                    $index[$key] = $i;
                }
            }

            foreach ($records as $record) {
                if (!is_array($record)) {
                    $skipped++;
                    continue;
                }

                $recordId =
                    (string)(
                        $record['$id']['value'] ??
                        ''
                    );

                if ($recordId === '') {
                    $skipped++;
                    continue;
                }

                $customer = [
                    'id' =>
                        makeId('customer'),
                    'kintone_id' =>
                        $recordId,
                    'synced_at' =>
                        date('c'),
                    'data' =>
                        $record
                ];

                if (
                    isset($index[$recordId])
                ) {
                    $customers[
                        $index[$recordId]
                    ] = array_merge(
                        $customers[$index[$recordId]],
                        $customer
                    );

                    $updated++;
                } else {
                    $customers[] = $customer;
                    $index[$recordId] =
                        count($customers) - 1;
                    $inserted++;
                }
            }

            $data['customers'] = $customers;

            if (!saveData($data)) {
                jsonResponse([
                    'ok'      => false,
                    'message' =>
                        '顧客データは取得できましたが、survey_data.jsonへの保存に失敗しました。',
                    'count'   => count($records)
                ], 500);
            }

            jsonResponse([
                'ok'      => true,
                'message' =>
                    '顧客データを同期しました。',
                'count'   => count($records),
                'inserted'=> $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors'  => $errors
            ]);

        case 'test_smtp_connection':
            $config =
                $data['settings']['smtp'] ?? [];

            if (
                !is_array($config) ||
                empty($config['smtp_server']) ||
                empty($config['smtp_port']) ||
                empty($config['smtp_from_email'])
            ) {
                jsonResponse([
                    'ok'          => false,
                    'error_type'  => 'configuration',
                    'message'     =>
                        '保存済みのSMTP設定が不足しています。先にSMTP設定を保存してください。',
                    'check_items' => [
                        'SMTPサーバ',
                        'SMTPポート',
                        '送信元メールアドレス'
                    ]
                ], 422);
            }

            $result = smtpConnect($config);

            if (!$result['ok']) {
                jsonResponse([
                    'ok'              => false,
                    'message'         => $result['message'],
                    'error_type'      => $result['error_type'] ?? null,
                    'smtp_server'     =>
                        $config['smtp_server'],
                    'smtp_port'       =>
                        (int)$config['smtp_port'],
                    'smtp_encryption' =>
                        $config['smtp_encryption'],
                    'smtp_code'       =>
                        $result['smtp_code'] ?? null,
                    'check_items'     =>
                        $result['check_items'] ?? []
                ]);
            }

            $socket = $result['socket'];

            @fwrite(
                $socket,
                "QUIT\r\n"
            );

            fclose($socket);

            jsonResponse([
                'ok'              => true,
                'message'         =>
                    'SMTPサーバへの接続に成功しました。',
                'smtp_server'     =>
                    $config['smtp_server'],
                'smtp_port'       =>
                    (int)$config['smtp_port'],
                'smtp_encryption' =>
                    $config['smtp_encryption'],
                'authentication'  =>
                    !empty($config['smtp_auth'])
                        ? '成功'
                        : '未使用'
            ]);

        case 'send_smtp_test':
            $config =
                $data['settings']['smtp'] ?? [];

            if (
                !is_array($config) ||
                empty($config['smtp_server']) ||
                empty($config['smtp_from_email'])
            ) {
                jsonResponse([
                    'ok'         => false,
                    'error_type' => 'configuration',
                    'message'    =>
                        '保存済みのSMTP設定が不足しています。'
                ], 422);
            }

            $recipient = trim(
                (string)(
                    $_POST['recipient'] ??
                    $_POST['test_recipient'] ??
                    ''
                )
            );

            if (
                !filter_var(
                    $recipient,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                jsonResponse([
                    'ok'         => false,
                    'error_type' => 'configuration',
                    'message'    =>
                        'テスト宛先メールアドレスを入力してください。'
                ], 422);
            }

            $result = smtpSend(
                $config,
                $recipient,
                'アンケート管理システム SMTP送信テスト',
                "これはアンケート管理システムのSMTP送信テストメールです。\n\n"
                . '送信日時: ' . date('c')
            );

            if (!$result['ok']) {
                jsonResponse([
                    'ok'         => false,
                    'message'    => $result['message'],
                    'error_type' =>
                        $result['error_type'] ?? null,
                    'smtp_code'  =>
                        $result['smtp_code'] ?? null,
                    'recipient'  => $recipient
                ]);
            }

            $data['mail_logs'][] = [
                'id'        => makeId('mail'),
                'type'      => 'smtp_test',
                'recipient' => $recipient,
                'subject'   =>
                    'アンケート管理システム SMTP送信テスト',
                'status'    => 'sent',
                'smtp_code' =>
                    $result['smtp_code'] ?? null,
                'created_at'=> date('c')
            ];

            saveData($data);

            jsonResponse([
                'ok'         => true,
                'message'    =>
                    'テストメールを送信しました。',
                'recipient'  => $recipient,
                'smtp_code'  =>
                    $result['smtp_code'] ?? null
            ]);

        case 'save_survey':
            $survey = postArray('survey_json');

            if (!$survey) {
                jsonResponse([
                    'ok'      => false,
                    'message' =>
                        'アンケートデータが不正です。'
                ], 422);
            }

            $survey = normalizeSurvey($survey);

            if (
                !in_array(
                    $survey['status'],
                    ['draft', 'active', 'ended'],
                    true
                )
            ) {
                jsonResponse([
                    'ok'      => false,
                    'message' =>
                        'ステータスが不正です。'
                ], 422);
            }

            $found = false;

            foreach (
                $data['surveys'] as $i => $existing
            ) {
                if (
                    is_array($existing) &&
                    ($existing['id'] ?? '') ===
                    $survey['id']
                ) {
                    $data['surveys'][$i] = $survey;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $data['surveys'][] = $survey;
            }

            if (!saveData($data)) {
                jsonResponse([
                    'ok'      => false,
                    'message' =>
                        'アンケートの保存に失敗しました。'
                ], 500);
            }

            jsonResponse([
                'ok'     => true,
                'message'=> 'アンケートを保存しました。',
                'survey' => $survey
            ]);

        case 'delete_survey':
            $surveyId = trim(
                (string)($_POST['survey_id'] ?? '')
            );

            if ($surveyId === '') {
                jsonResponse([
                    'ok'      => false,
                    'message' =>
                        'アンケートIDが指定されていません。'
                ], 422);
            }

            foreach (
                $data['surveys'] as &$survey
            ) {
                if (
                    is_array($survey) &&
                    ($survey['id'] ?? '') === $surveyId
                ) {
                    $survey['deleted'] = true;
                    $survey['deleted_at'] = date('c');
                }
            }

            unset($survey);

            if (!saveData($data)) {
                jsonResponse([
                    'ok'      => false,
                    'message' =>
                        'アンケートの削除に失敗しました。'
                ], 500);
            }

            jsonResponse([
                'ok'      => true,
                'message' =>
                    'アンケートを削除しました。'
            ]);

        case 'get_data':
            $surveys = [];

            foreach ($data['surveys'] as $survey) {
                if (
                    !is_array($survey) ||
                    !empty($survey['deleted'])
                ) {
                    continue;
                }

                $surveys[] =
                    normalizeSurvey($survey);
            }

            jsonResponse([
                'ok'       => true,
                'surveys'  => $surveys,
                'responses'=> $data['responses'],
                'customers'=> $data['customers'],
                'settings' => publicSettings(
                    $data['settings']
                ),
                'csrf_token'=> csrf()
            ]);

        default:
            jsonResponse([
                'ok'      => false,
                'message' =>
                    '未対応のAPI actionです。'
            ], 400);
    }
}

/*
 * ------------------------------------------------------------
 * API判定
 *
 * 同一 index.php への POST だけを処理する。
 *
 * ここが今回の
 *
 *   https://localhost:8443/...
 *
 * へ飛ばして403/CORSになる問題を防ぐ。
 * ------------------------------------------------------------
 */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action'])
) {
    handleApi();
}

$csrfToken = csrf();

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<meta name="csrf-token"
      content="<?= htmlspecialchars(
          $csrfToken,
          ENT_QUOTES,
          'UTF-8'
      ) ?>">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

<style>
[x-cloak] {
    display:none !important;
}
</style>
</head>

<body class="bg-slate-100 text-slate-900">

<div id="app"></div>

<script>
'use strict';

window.App = {
    state: {
        initialized: false,
        page: 'list',
        surveys: [],
        responses: [],
        customers: [],
        settings: {
            kintone: {},
            smtp: {}
        },
        currentSurvey: null,
        previewSurvey: null,
        responseAnswers: {},
        visibleQuestions: {},
        csrfToken:
            document.querySelector(
                'meta[name="csrf-token"]'
            )?.content || '',
        kintoneMessage: null,
        smtpMessage: null,
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

/* ============================================================
 * Utils
 * ========================================================== */

App.utils.escapeHTML = function(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};

App.utils.escapeAttr = App.utils.escapeHTML;

App.utils.uid = function(prefix) {
    return prefix + '_' +
        Date.now().toString(36) + '_' +
        Math.random().toString(36).slice(2, 10);
};

App.utils.statusLabel = function(status) {
    return {
        draft: '下書き',
        active: '公開中',
        ended: '終了'
    }[status] || status;
};

App.utils.deepClone = function(value) {
    return JSON.parse(JSON.stringify(value));
};

App.utils.normalizeSurvey = function(survey) {
    const s = survey || {};

    s.id = s.id || App.utils.uid('survey');
    s.title = s.title || '';
    s.status =
        ['draft','active','ended'].includes(s.status)
            ? s.status
            : 'draft';

    s.groups =
        Array.isArray(s.groups)
            ? s.groups
            : [];

    s.groups.forEach(group => {
        group.id =
            group.id ||
            App.utils.uid('group');

        group.name =
            group.name || '';

        group.questions =
            Array.isArray(group.questions)
                ? group.questions
                : [];

        group.questions =
            group.questions.map(q => {
                q = q || {};

                q.id =
                    q.id ||
                    App.utils.uid('question');

                q.text =
                    q.text || '';

                q.type =
                    ['text','textarea','single',
                     'multiple','date']
                    .includes(q.type)
                        ? q.type
                        : 'text';

                q.required =
                    !!q.required;

                q.options =
                    Array.isArray(q.options)
                        ? q.options
                        : [];

                q.other_enabled =
                    !!q.other_enabled;

                q.branching =
                    q.branching &&
                    typeof q.branching === 'object'
                        ? q.branching
                        : {};

                q.options =
                    q.options.map(o => ({
                        id:
                            o?.id ||
                            App.utils.uid('option'),
                        text:
                            o?.text || ''
                    }));

                return q;
            });
    });

    return s;
};

App.utils.flattenQuestions = function(survey) {
    const result = [];

    (survey?.groups || []).forEach(
        (group, gi) => {
            (group.questions || []).forEach(
                (question, qi) => {
                    result.push({
                        question,
                        group,
                        groupIndex: gi,
                        questionIndex: qi
                    });
                }
            );
        }
    );

    return result;
};

/* ============================================================
 * API
 * ========================================================== */

App.api.request = async function(action, data = {}) {

    const form = new FormData();

    form.append(
        'action',
        action
    );

    form.append(
        'csrf_token',
        App.state.csrfToken
    );

    Object.entries(data).forEach(
        ([key, value]) => {

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
                    value ?? ''
                );
            }
        }
    );

    /*
     * ★重要
     *
     * localhost:8443等を作らない。
     *
     * このindex.php自身へ送る。
     *
     * location.pathname は、
     * 日本語パスやサブディレクトリでもそのまま使用可能。
     */
    const endpoint =
        window.location.href.split('#')[0];

    let response;

    try {
        response = await fetch(
            endpoint,
            {
                method: 'POST',
                body: form,
                credentials: 'same-origin',
                cache: 'no-store'
            }
        );
    } catch (error) {
        throw new Error(
            'API通信に失敗しました。現在のページがPHPサーバーからHTTP/HTTPSで配信されているか確認してください。'
        );
    }

    let json = null;

    try {
        json = await response.json();
    } catch (error) {
        throw new Error(
            'サーバーからJSON形式の応答を取得できませんでした。HTTPステータス: ' +
            response.status
        );
    }

    if (!response.ok && !json) {
        throw new Error(
            'APIエラーです。HTTPステータス: ' +
            response.status
        );
    }

    return json;
};

App.api.load = async function() {
    const result =
        await App.api.request(
            'get_data'
        );

    if (!result.ok) {
        throw new Error(
            result.message ||
            'データ取得に失敗しました。'
        );
    }

    App.state.surveys =
        Array.isArray(result.surveys)
            ? result.surveys
            : [];

    App.state.responses =
        Array.isArray(result.responses)
            ? result.responses
            : [];

    App.state.customers =
        Array.isArray(result.customers)
            ? result.customers
            : [];

    App.state.settings =
        result.settings || {
            kintone: {},
            smtp: {}
        };

    if (result.csrf_token) {
        App.state.csrfToken =
            result.csrf_token;
    }
};

/* ============================================================
 * Settings
 * ========================================================== */

App.actions.loadSettings = async function() {
    const result =
        await App.api.request(
            'get_settings'
        );

    if (!result.ok) {
        throw new Error(
            result.message ||
            '設定の取得に失敗しました。'
        );
    }

    App.state.settings =
        result.settings || {
            kintone: {},
            smtp: {}
        };

    return result;
};

App.actions.openSettings = async function() {
    try {
        await App.actions.loadSettings();
        App.state.page = 'settings';
        App.state.kintoneMessage = null;
        App.state.smtpMessage = null;
        App.render.app();
    } catch (error) {
        alert(error.message);
    }
};

App.actions.saveKintoneSettings = async function() {
    const form =
        document.getElementById(
            'kintone_settings_form'
        );

    if (!form) {
        return;
    }

    const data = {};

    new FormData(form).forEach(
        (value, key) => {
            data[key] = value;
        }
    );

    data.ssl_verify =
        form.querySelector(
            '[name="ssl_verify"]'
        )?.checked
            ? true
            : false;

    App.state.kintoneMessage = {
        type: 'info',
        text: '保存しています...'
    };

    App.render.app();

    try {
        const result =
            await App.api.request(
                'save_kintone_settings',
                {
                    settings_json: data
                }
            );

        if (!result.ok) {
            App.state.kintoneMessage = {
                type: 'error',
                text:
                    result.message ||
                    'キントーン設定の保存に失敗しました。'
            };

            App.render.app();
            return;
        }

        App.state.settings =
            result.settings;

        App.state.kintoneMessage = {
            type: 'success',
            text:
                result.message ||
                'キントーン設定を保存しました。'
        };

        App.render.app();

    } catch (error) {
        App.state.kintoneMessage = {
            type: 'error',
            text: error.message
        };

        App.render.app();
    }
};

App.actions.saveSmtpSettings = async function() {
    const form =
        document.getElementById(
            'smtp_settings_form'
        );

    if (!form) {
        return;
    }

    const data = {};

    new FormData(form).forEach(
        (value, key) => {
            data[key] = value;
        }
    );

    data.smtp_auth =
        form.querySelector(
            '[name="smtp_auth"]'
        )?.checked
            ? true
            : false;

    App.state.smtpMessage = {
        type: 'info',
        text: '保存しています...'
    };

    App.render.app();

    try {
        const result =
            await App.api.request(
                'save_smtp_settings',
                {
                    settings_json: data
                }
            );

        if (!result.ok) {
            App.state.smtpMessage = {
                type: 'error',
                text:
                    result.message ||
                    'SMTP設定の保存に失敗しました。'
            };

            App.render.app();
            return;
        }

        App.state.settings =
            result.settings;

        App.state.smtpMessage = {
            type: 'success',
            text:
                result.message ||
                'SMTP設定を保存しました。'
        };

        App.render.app();

    } catch (error) {
        App.state.smtpMessage = {
            type: 'error',
            text: error.message
        };

        App.render.app();
    }
};

App.actions.connectKintone = async function() {
    App.state.kintoneMessage = {
        type: 'info',
        text: 'キントーンへ接続しています...'
    };

    App.render.app();

    try {
        const result =
            await App.api.request(
                'connect_kintone'
            );

        App.state.kintoneMessage = {
            type:
                result.ok
                    ? 'success'
                    : 'error',
            data: result
        };

        App.render.app();
    } catch (error) {
        App.state.kintoneMessage = {
            type: 'error',
            text: error.message
        };

        App.render.app();
    }
};

App.actions.fetchKintoneFields =
    async function() {

    App.state.kintoneMessage = {
        type: 'info',
        text:
            'kintoneからフィールドを取得しています...'
    };

    App.render.app();

    try {
        const result =
            await App.api.request(
                'fetch_kintone_fields'
            );

        App.state.kintoneMessage = {
            type:
                result.ok
                    ? 'success'
                    : 'error',
            data: result
        };

        App.render.app();
    } catch (error) {
        App.state.kintoneMessage = {
            type: 'error',
            text: error.message
        };

        App.render.app();
    }
};

App.actions.syncCustomers =
    async function() {

    App.state.kintoneMessage = {
        type: 'info',
        text:
            '顧客データを同期しています...'
    };

    App.render.app();

    try {
        const result =
            await App.api.request(
                'sync_customers'
            );

        App.state.kintoneMessage = {
            type:
                result.ok
                    ? 'success'
                    : 'error',
            data: result
        };

        if (result.ok) {
            await App.actions.loadData();
        }

        App.render.app();

    } catch (error) {
        App.state.kintoneMessage = {
            type: 'error',
            text: error.message
        };

        App.render.app();
    }
};

App.actions.testSmtpConnection =
    async function() {

    App.state.smtpMessage = {
        type: 'info',
        text:
            'SMTPサーバへ接続しています...'
    };

    App.render.app();

    try {
        const result =
            await App.api.request(
                'test_smtp_connection'
            );

        App.state.smtpMessage = {
            type:
                result.ok
                    ? 'success'
                    : 'error',
            data: result
        };

        App.render.app();

    } catch (error) {
        App.state.smtpMessage = {
            type: 'error',
            text: error.message
        };

        App.render.app();
    }
};

App.actions.sendSmtpTest =
    async function() {

    const recipient =
        prompt(
            'テストメールの宛先メールアドレスを入力してください。'
        );

    if (!recipient) {
        return;
    }

    App.state.smtpMessage = {
        type: 'info',
        text:
            'テストメールを送信しています...'
    };

    App.render.app();

    try {
        const result =
            await App.api.request(
                'send_smtp_test',
                {
                    recipient
                }
            );

        App.state.smtpMessage = {
            type:
                result.ok
                    ? 'success'
                    : 'error',
            data: result
        };

        App.render.app();

    } catch (error) {
        App.state.smtpMessage = {
            type: 'error',
            text: error.message
        };

        App.render.app();
    }
};

/* ============================================================
 * Survey actions
 * ========================================================== */

App.actions.loadData = async function() {
    await App.api.load();
};

App.actions.newSurvey = function() {
    App.state.currentSurvey =
        App.utils.normalizeSurvey({
            id: App.utils.uid('survey'),
            title: '',
            start_at: '',
            end_at: '',
            status: 'draft',
            numbering_mode: 'global',
            general_response_allowed: true,
            groups: [
                {
                    id: App.utils.uid('group'),
                    name: 'ブロック1',
                    questions: []
                }
            ],
            settings: {}
        });

    App.state.page = 'edit';
    App.render.app();
    App.initSortable();
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
        App.utils.deepClone(
            App.utils.normalizeSurvey(
                survey
            )
        );

    App.state.page = 'edit';
    App.render.app();
    App.initSortable();
};

App.actions.changeSurveyStatus =
    function(value) {

    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return;
    }

    const oldStatus =
        survey.status;

    if (
        oldStatus === 'active' &&
        value === 'ended'
    ) {
        if (
            !confirm(
                'このアンケートを終了状態に変更しますか？'
            )
        ) {
            return;
        }
    }

    if (
        oldStatus === 'ended' &&
        value === 'active'
    ) {
        if (
            !confirm(
                'このアンケートを公開状態に変更しますか？'
            )
        ) {
            return;
        }
    }

    survey.status = value;

    App.render.app();
};

App.actions.saveSurvey = async function() {
    if (!App.state.currentSurvey) {
        return;
    }

    App.actions.syncSurveyForm();

    const survey =
        App.utils.deepClone(
            App.state.currentSurvey
        );

    try {
        const result =
            await App.api.request(
                'save_survey',
                {
                    survey_json: survey
                }
            );

        if (!result.ok) {
            alert(
                result.message ||
                'アンケートの保存に失敗しました。'
            );
            return;
        }

        await App.actions.loadData();

        App.state.currentSurvey =
            App.utils.deepClone(
                result.survey
            );

        alert(
            result.message ||
            'アンケートを保存しました。'
        );

        App.render.app();
        App.initSortable();

    } catch (error) {
        alert(error.message);
    }
};

App.actions.deleteSurvey =
    async function(id) {

    if (
        !confirm(
            'このアンケートを削除しますか？'
        )
    ) {
        return;
    }

    try {
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

    } catch (error) {
        alert(error.message);
    }
};

App.actions.addGroup = function() {
    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return;
    }

    survey.groups.push({
        id: App.utils.uid('group'),
        name:
            'ブロック' +
            (survey.groups.length + 1),
        questions: []
    });

    App.actions.renumberQuestions();
    App.render.app();
    App.initSortable();
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

    /*
     * 必ずquestions末尾へ追加。
     */
    group.questions.push({
        id: App.utils.uid('question'),
        text: '',
        type: 'text',
        required: false,
        options: [],
        other_enabled: false,
        branching: {}
    });

    App.actions.renumberQuestions();
    App.render.app();
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

    App.actions.removeInvalidBranching();
    App.actions.renumberQuestions();
    App.render.app();
    App.initSortable();
};

App.actions.addOption =
    function(groupId, questionId) {

    const q =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (!q) {
        return;
    }

    q.options.push({
        id: App.utils.uid('option'),
        text: ''
    });

    App.render.app();
};

App.actions.deleteOption =
    function(
        groupId,
        questionId,
        optionId
    ) {

    const q =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (!q) {
        return;
    }

    q.options =
        q.options.filter(
            o => o.id !== optionId
        );

    delete q.branching[optionId];

    App.render.app();
};

App.actions.findQuestion =
    function(groupId, questionId) {

    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return null;
    }

    const group =
        survey.groups.find(
            g => g.id === groupId
        );

    if (!group) {
        return null;
    }

    return group.questions.find(
        q => q.id === questionId
    ) || null;
};

App.actions.renumberQuestions =
    function() {

    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return;
    }

    let globalNumber = 1;

    survey.groups.forEach(
        (group, gi) => {

        group.questions.forEach(
            (question, qi) => {

            question.number =
                'Q' + globalNumber;

            question.group_number =
                'Q' +
                (gi + 1) +
                '-' +
                (qi + 1);

            globalNumber++;
        });
    });
};

App.actions.removeInvalidBranching =
    function() {

    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return;
    }

    const flattened =
        App.utils.flattenQuestions(
            survey
        );

    const validIds =
        new Set(
            flattened.map(
                item =>
                    item.question.id
            )
        );

    flattened.forEach(
        item => {

        const q =
            item.question;

        const branching =
            q.branching || {};

        Object.keys(branching)
            .forEach(optionId => {

                const target =
                    branching[optionId];

                if (
                    target &&
                    !validIds.has(target)
                ) {
                    delete branching[optionId];
                }
            });
    });
};

App.actions.branchCandidates =
    function(groupId, questionId) {

    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return [];
    }

    const flattened =
        App.utils.flattenQuestions(
            survey
        );

    const index =
        flattened.findIndex(
            item =>
                item.question.id ===
                questionId
        );

    if (index < 0) {
        return [];
    }

    return flattened
        .slice(index + 1)
        .map(item => ({
            id: item.question.id,
            number:
                item.question.number ||
                item.question.group_number ||
                '',
            text:
                item.question.text || ''
        }));
};

App.actions.updateBranchVisibility =
    function() {

    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return;
    }

    const answers =
        App.state.responseAnswers;

    const flattened =
        App.utils.flattenQuestions(
            survey
        );

    const visible = {};

    flattened.forEach(item => {
        visible[item.question.id] = true;
    });

    flattened.forEach(
        item => {

        const q =
            item.question;

        if (q.type !== 'single') {
            return;
        }

        const answer =
            answers[q.id];

        if (!answer) {
            return;
        }

        const target =
            q.branching?.[answer];

        if (!target) {
            return;
        }

        const targetIndex =
            flattened.findIndex(
                item2 =>
                    item2.question.id ===
                    target
            );

        if (targetIndex < 0) {
            return;
        }

        /*
         * 分岐先以降を表示し、
         * 分岐先より前の質問は表示。
         *
         * 分岐元からtargetまでの間だけ
         * 非表示にする。
         */
        const currentIndex =
            flattened.findIndex(
                item2 =>
                    item2.question.id ===
                    q.id
            );

        for (
            let i = currentIndex + 1;
            i < targetIndex;
            i++
        ) {
            visible[
                flattened[i].question.id
            ] = false;
        }
    });

    App.state.visibleQuestions =
        visible;

    App.render.response();
};

App.actions.validateResponse =
    function() {

    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return {
            ok: false,
            errors: [
                'アンケートがありません。'
            ]
        };
    }

    const answers =
        App.state.responseAnswers;

    const errors = [];

    const flattened =
        App.utils.flattenQuestions(
            survey
        );

    flattened.forEach(item => {

        const q =
            item.question;

        if (
            App.state.visibleQuestions[q.id] ===
            false
        ) {
            return;
        }

        if (!q.required) {
            return;
        }

        const answer =
            answers[q.id];

        if (
            answer === undefined ||
            answer === null ||
            answer === '' ||
            (
                Array.isArray(answer) &&
                answer.length === 0
            )
        ) {
            errors.push(
                (
                    q.number ||
                    q.group_number ||
                    '質問'
                ) +
                ' は必須です。'
            );
        }
    });

    return {
        ok: errors.length === 0,
        errors
    };
};

App.actions.preview =
    function() {

    App.actions.syncSurveyForm();

    App.state.previewSurvey =
        App.utils.deepClone(
            App.state.currentSurvey
        );

    App.render.app();
};

App.actions.syncSurveyForm =
    function() {

    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return;
    }

    const title =
        document.getElementById(
            'survey_title'
        );

    const start =
        document.getElementById(
            'survey_start_at'
        );

    const end =
        document.getElementById(
            'survey_end_at'
        );

    const numbering =
        document.getElementById(
            'survey_numbering_mode'
        );

    const status =
        document.getElementById(
            'survey_status'
        );

    if (title) {
        survey.title =
            title.value;
    }

    if (start) {
        survey.start_at =
            start.value;
    }

    if (end) {
        survey.end_at =
            end.value;
    }

    if (numbering) {
        survey.numbering_mode =
            numbering.value;
    }

    if (status) {
        survey.status =
            status.value;
    }
};

/* ============================================================
 * SortableJS
 * ========================================================== */

App.initSortable = function() {

    if (
        typeof Sortable ===
        'undefined'
    ) {
        return;
    }

    document
        .querySelectorAll(
            '.question-sortable'
        )
        .forEach(element => {

        if (
            element.__sortable
        ) {
            element.__sortable.destroy();
        }

        const groupId =
            element.dataset.groupId;

        const sortable =
            new Sortable(
                element,
                {
                    group: {
                        name: 'survey-questions',
                        pull: true,
                        put: true
                    },
                    animation: 150,
                    draggable:
                        '.question-item',
                    onEnd: function() {

                        const survey =
                            App.state.currentSurvey;

                        if (!survey) {
                            return;
                        }

                        const oldGroups =
                            survey.groups;

                        const questionMap =
                            new Map();

                        oldGroups.forEach(
                            group => {
                                group.questions.forEach(
                                    q => {
                                        questionMap.set(
                                            q.id,
                                            q
                                        );
                                    }
                                );
                            }
                        );

                        const newGroups =
                            [];

                        document
                            .querySelectorAll(
                                '.question-sortable'
                            )
                            .forEach(
                                container => {

                                const gid =
                                    container.dataset.groupId;

                                const group =
                                    oldGroups.find(
                                        g =>
                                            g.id === gid
                                    );

                                if (!group) {
                                    return;
                                }

                                const questions =
                                    [];

                                container
                                    .querySelectorAll(
                                        '.question-item'
                                    )
                                    .forEach(
                                        item => {

                                        const qid =
                                            item.dataset.questionId;

                                        if (
                                            questionMap.has(
                                                qid
                                            )
                                        ) {
                                            questions.push(
                                                questionMap.get(
                                                    qid
                                                )
                                            );
                                        }
                                    }
                                );

                                group.questions =
                                    questions;

                                newGroups.push(
                                    group
                                );
                            });

                        survey.groups =
                            newGroups.length
                                ? newGroups
                                : oldGroups;

                        App.actions
                            .removeInvalidBranching();

                        App.actions
                            .renumberQuestions();

                        App.render.app();

                        App.initSortable();
                    }
                }
            );

        element.__sortable =
            sortable;
    });
};

/* ============================================================
 * Render
 * ========================================================== */

App.render.header = function() {
    return `
<header class="fixed top-0 left-0 right-0 z-50
               bg-slate-900 text-white shadow">
  <div class="max-w-7xl mx-auto px-4">
    <div class="h-16 flex items-center justify-between">

      <div class="font-bold">
        アンケート管理システム
      </div>

      <nav class="flex items-center gap-2">

        <button
          class="px-4 py-2 rounded hover:bg-slate-700"
          onclick="App.actions.openList()">
          アンケート一覧
        </button>

        <button
          class="px-4 py-2 rounded hover:bg-slate-700"
          onclick="App.actions.openSettings()">
          キントーン・メール設定
        </button>

        <button
          class="px-4 py-2 rounded hover:bg-slate-700"
          onclick="App.actions.logout()">
          ログアウト
        </button>

      </nav>
    </div>
  </div>
</header>
`;
};

App.render.breadcrumb =
    function(items) {

    return `
<div class="text-sm text-slate-500 mb-5">
  ${items.map(
      (item, index) =>
          `<span>${App.utils.escapeHTML(item)}</span>` +
          (
              index <
              items.length - 1
                  ? `<span class="mx-2">＞</span>`
                  : ''
          )
  ).join('')}
</div>
`;
};

App.render.message =
    function(message) {

    if (!message) {
        return '';
    }

    if (message.data) {
        return App.render.result(
            message.data
        );
    }

    const color =
        message.type === 'success'
            ? 'green'
            : message.type === 'error'
                ? 'red'
                : 'blue';

    return `
<div class="mb-4 rounded-lg border
            border-${color}-200
            bg-${color}-50
            text-${color}-800
            p-4">
  ${App.utils.escapeHTML(
      message.text || ''
  )}
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

    const color =
        ok ? 'green' : 'red';

    let html = `
<div class="rounded-lg border
            border-${color}-200
            bg-${color}-50
            p-4 space-y-2">

  <div class="font-bold">
    状態：
    ${ok ? '成功' : '失敗'}
  </div>
`;

    if (result.http_status) {
        html += `
<div>
  HTTPステータス：
  ${App.utils.escapeHTML(
      result.http_status
  )}
</div>
`;
    }

    if (result.smtp_code) {
        html += `
<div>
  SMTP応答コード：
  ${App.utils.escapeHTML(
      result.smtp_code
  )}
</div>
`;
    }

    if (result.error_type) {
        html += `
<div>
  エラー種別：
  ${App.utils.escapeHTML(
      result.error_type
  )}
</div>
`;
    }

    if (result.message) {
        html += `
<div>
  内容：
  ${App.utils.escapeHTML(
      result.message
  )}
</div>
`;
    }

    if (result.subdomain) {
        html += `
<div>
  サブドメイン：
  ${App.utils.escapeHTML(
      result.subdomain
  )}
</div>
`;
    }

    if (result.app_id) {
        html += `
<div>
  対象アプリID：
  ${App.utils.escapeHTML(
      result.app_id
  )}
</div>
`;
    }

    if (result.smtp_server) {
        html += `
<div>
  SMTPサーバ：
  ${App.utils.escapeHTML(
      result.smtp_server
  )}
</div>
`;
    }

    if (result.smtp_port) {
        html += `
<div>
  SMTPポート：
  ${App.utils.escapeHTML(
      result.smtp_port
  )}
</div>
`;
    }

    if (result.smtp_encryption) {
        html += `
<div>
  暗号化方式：
  ${App.utils.escapeHTML(
      result.smtp_encryption
  )}
</div>
`;
    }

    if (result.recipient) {
        html += `
<div>
  宛先：
  ${App.utils.escapeHTML(
      result.recipient
  )}
</div>
`;
    }

    if (Array.isArray(result.check_items)) {
        html += `
<div>
  <div class="font-semibold mt-2">
    確認事項：
  </div>
  <ul class="list-disc ml-6">
    ${result.check_items.map(
        item =>
            `<li>${App.utils.escapeHTML(item)}</li>`
    ).join('')}
  </ul>
</div>
`;
    }

    if (Array.isArray(result.fields)) {
        html += `
<div class="mt-3">
  <div class="font-semibold mb-2">
    フィールド：
  </div>

  <div class="overflow-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b">
          <th class="text-left p-2">label</th>
          <th class="text-left p-2">code</th>
          <th class="text-left p-2">type</th>
        </tr>
      </thead>
      <tbody>
        ${result.fields.map(
            field => `
<tr class="border-b">
  <td class="p-2">
    ${App.utils.escapeHTML(
        field.label
    )}
  </td>
  <td class="p-2">
    ${App.utils.escapeHTML(
        field.code
    )}
  </td>
  <td class="p-2">
    ${App.utils.escapeHTML(
        field.type
    )}
  </td>
</tr>
`
        ).join('')}
      </tbody>
    </table>
  </div>
</div>
`;
    }

    if (
        result.count !== undefined
    ) {
        html += `
<div>
  取得件数：
  ${App.utils.escapeHTML(
      result.count
  )}
</div>
<div>
  追加件数：
  ${App.utils.escapeHTML(
      result.inserted ?? 0
  )}
</div>
<div>
  更新件数：
  ${App.utils.escapeHTML(
      result.updated ?? 0
  )}
</div>
<div>
  スキップ件数：
  ${App.utils.escapeHTML(
      result.skipped ?? 0
  )}
</div>
<div>
  エラー件数：
  ${App.utils.escapeHTML(
      result.errors ?? 0
  )}
</div>
`;
    }

    html += `</div>`;

    return html;
};

/* ============================================================
 * Settings page
 * ========================================================== */

App.render.settings =
    function() {

    const k =
        App.state.settings.kintone || {};

    const s =
        App.state.settings.smtp || {};

    return `
${App.render.header()}

<main class="max-w-7xl mx-auto px-4 pt-24 pb-12">

${App.render.breadcrumb([
    'ホーム',
    'キントーン・メール設定'
])}

<h1 class="text-2xl font-bold mb-6">
  キントーン・メール設定
</h1>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

<!-- ========================================================
     KINTONE
========================================================= -->

<section class="bg-white rounded-xl shadow p-6">

<h2 class="text-xl font-bold mb-5">
  キントーン設定
</h2>

${App.render.message(
    App.state.kintoneMessage
)}

<form
  id="kintone_settings_form"
  onsubmit="event.preventDefault();
            App.actions.saveKintoneSettings();">

<div class="space-y-4">

<div>
<label class="block text-sm font-semibold mb-1">
  サブドメイン
</label>
<input
  name="subdomain"
  value="${App.utils.escapeAttr(
      k.subdomain || ''
  )}"
  placeholder="https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx"
  class="w-full border rounded-lg px-3 py-2">
<p class="text-xs text-slate-500 mt-1">
  3形式のいずれでも入力できます。
</p>
</div>

<div>
<label class="block text-sm font-semibold mb-1">
  ログイン名
</label>
<input
  name="login_name"
  value="${App.utils.escapeAttr(
      k.login_name || ''
  )}"
  class="w-full border rounded-lg px-3 py-2">
</div>

<div>
<label class="block text-sm font-semibold mb-1">
  パスワード
</label>
<input
  type="password"
  name="password"
  value=""
  autocomplete="new-password"
  placeholder="${
      k.password_configured
          ? '変更しない場合は空欄'
          : ''
  }"
  class="w-full border rounded-lg px-3 py-2">
</div>

<div>
<label class="block text-sm font-semibold mb-1">
  顧客管理アプリID
</label>
<input
  type="number"
  min="1"
  name="app_id"
  value="${App.utils.escapeAttr(
      k.app_id || ''
  )}"
  class="w-full border rounded-lg px-3 py-2">
</div>

<div>
<label class="flex items-center gap-2">
<input
  type="checkbox"
  name="ssl_verify"
  ${k.ssl_verify ? 'checked' : ''}>
<span>SSL証明書を検証する</span>
</label>
<p class="text-xs text-slate-500">
  デフォルトは検証なしです。
</p>
</div>

<div>
<label class="block text-sm font-semibold mb-1">
  Proxy
</label>
<input
  name="proxy"
  value="${App.utils.escapeAttr(
      k.proxy || ''
  )}"
  placeholder="proxy.example.com:8080"
  class="w-full border rounded-lg px-3 py-2">
<p class="text-xs text-slate-500 mt-1">
  host名:port番号の形式。http:// / https:// は不要です。
</p>
</div>

<div class="border-t pt-4">

<h3 class="font-semibold mb-3">
  顧客フィールド
</h3>

<div class="grid grid-cols-1 md:grid-cols-2 gap-3">

<input
  name="field_company"
  value="${App.utils.escapeAttr(
      k.field_company || ''
  )}"
  placeholder="会社名フィールドコード"
  class="border rounded-lg px-3 py-2">

<input
  name="field_name"
  value="${App.utils.escapeAttr(
      k.field_name || ''
  )}"
  placeholder="氏名フィールドコード"
  class="border rounded-lg px-3 py-2">

<input
  name="field_email"
  value="${App.utils.escapeAttr(
      k.field_email || ''
  )}"
  placeholder="メールフィールドコード"
  class="border rounded-lg px-3 py-2">

<input
  name="field_department"
  value="${App.utils.escapeAttr(
      k.field_department || ''
  )}"
  placeholder="部署フィールドコード"
  class="border rounded-lg px-3 py-2">

<input
  name="field_phone"
  value="${App.utils.escapeAttr(
      k.field_phone || ''
  )}"
  placeholder="電話フィールドコード"
  class="border rounded-lg px-3 py-2">

<input
  name="field_address"
  value="${App.utils.escapeAttr(
      k.field_address || ''
  )}"
  placeholder="住所フィールドコード"
  class="border rounded-lg px-3 py-2">

</div>
</div>

</div>

<div class="flex flex-wrap gap-2 mt-6">

<button
  id="kintone_save_button"
  type="submit"
  class="px-4 py-2 rounded-lg
         bg-blue-600 text-white
         hover:bg-blue-700">
  設定を保存
</button>

<button
  type="button"
  onclick="App.actions.connectKintone()"
  class="px-4 py-2 rounded-lg
         bg-slate-700 text-white
         hover:bg-slate-800">
  キントーン接続確認
</button>

<button
  type="button"
  onclick="App.actions.fetchKintoneFields()"
  class="px-4 py-2 rounded-lg
         bg-indigo-600 text-white
         hover:bg-indigo-700">
  フィールド取得
</button>

<button
  type="button"
  onclick="App.actions.syncCustomers()"
  class="px-4 py-2 rounded-lg
         bg-emerald-600 text-white
         hover:bg-emerald-700">
  顧客データを同期
</button>

</div>

</form>

</section>

<!-- ========================================================
     SMTP
========================================================= -->

<section class="bg-white rounded-xl shadow p-6">

<h2 class="text-xl font-bold mb-5">
  SMTP設定
</h2>

${App.render.message(
    App.state.smtpMessage
)}

<form
  id="smtp_settings_form"
  onsubmit="event.preventDefault();
            App.actions.saveSmtpSettings();">

<div class="space-y-4">

<div>
<label class="block text-sm font-semibold mb-1">
  SMTPサーバ
</label>
<input
  name="smtp_server"
  value="${App.utils.escapeAttr(
      s.smtp_server || ''
  )}"
  class="w-full border rounded-lg px-3 py-2">
</div>

<div>
<label class="block text-sm font-semibold mb-1">
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
  class="w-full border rounded-lg px-3 py-2">
</div>

<div>
<label class="block text-sm font-semibold mb-1">
  暗号化方式
</label>
<select
  name="smtp_encryption"
  class="w-full border rounded-lg px-3 py-2">

<option
  value="none"
  ${s.smtp_encryption === 'none'
      ? 'selected'
      : ''}>
  none
</option>

<option
  value="starttls"
  ${s.smtp_encryption === 'starttls'
      ? 'selected'
      : ''}>
  STARTTLS
</option>

<option
  value="ssl"
  ${s.smtp_encryption === 'ssl'
      ? 'selected'
      : ''}>
  SSL
</option>

</select>
</div>

<div>
<label class="flex items-center gap-2">
<input
  type="checkbox"
  name="smtp_auth"
  ${s.smtp_auth ? 'checked' : ''}>
<span>SMTP認証を使用する</span>
</label>
</div>

<div>
<label class="block text-sm font-semibold mb-1">
  SMTPユーザー名
</label>
<input
  name="smtp_username"
  value="${App.utils.escapeAttr(
      s.smtp_username || ''
  )}"
  class="w-full border rounded-lg px-3 py-2">
</div>

<div>
<label class="block text-sm font-semibold mb-1">
  SMTPパスワード
</label>
<input
  type="password"
  name="smtp_password"
  value=""
  autocomplete="new-password"
  placeholder="${
      s.password_configured
          ? '変更しない場合は空欄'
          : ''
  }"
  class="w-full border rounded-lg px-3 py-2">
</div>

<div>
<label class="block text-sm font-semibold mb-1">
  送信元メールアドレス
</label>
<input
  type="email"
  name="smtp_from_email"
  value="${App.utils.escapeAttr(
      s.smtp_from_email || ''
  )}"
  class="w-full border rounded-lg px-3 py-2">
</div>

<div>
<label class="block text-sm font-semibold mb-1">
  送信元表示名
</label>
<input
  name="smtp_from_name"
  value="${App.utils.escapeAttr(
      s.smtp_from_name || ''
  )}"
  class="w-full border rounded-lg px-3 py-2">
</div>

<div>
<label class="block text-sm font-semibold mb-1">
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
  class="w-full border rounded-lg px-3 py-2">
</div>

</div>

<div class="flex flex-wrap gap-2 mt-6">

<button
  id="smtp_save_button"
  type="submit"
  class="px-4 py-2 rounded-lg
         bg-blue-600 text-white
         hover:bg-blue-700">
  設定を保存
</button>

<button
  type="button"
  onclick="App.actions.testSmtpConnection()"
  class="px-4 py-2 rounded-lg
         bg-slate-700 text-white
         hover:bg-slate-800">
  SMTP接続確認
</button>

<button
  type="button"
  onclick="App.actions.sendSmtpTest()"
  class="px-4 py-2 rounded-lg
         bg-indigo-600 text-white
         hover:bg-indigo-700">
  テストメール送信
</button>

</div>

</form>

</section>

</div>

</main>
`;
};

/* ============================================================
 * List
 * ========================================================== */

App.actions.openList = function() {
    App.state.page = 'list';
    App.state.currentSurvey = null;
    App.render.app();
};

App.actions.logout = function() {
    /*
     * 管理者認証を既存環境側で管理する場合に備え、
     * 現時点ではセッション破棄を行わない。
     */
    alert('ログアウトしました。');
};

App.render.list = function() {

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
                    String(
                        s.title || ''
                    )
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

    return `
${App.render.header()}

<main class="max-w-7xl mx-auto px-4 pt-24 pb-12">

${App.render.breadcrumb([
    'ホーム',
    'アンケート一覧'
])}

<div class="flex items-center justify-between
            gap-4 mb-6">

<h1 class="text-2xl font-bold">
  アンケート一覧
</h1>

<button
  onclick="App.actions.newSurvey()"
  class="px-4 py-2 rounded-lg
         bg-blue-600 text-white
         hover:bg-blue-700">
  ＋ アンケートを作成
</button>

</div>

<div class="bg-white rounded-xl shadow p-4 mb-5">

<div class="grid grid-cols-1 md:grid-cols-3 gap-3">

<input
  value="${App.utils.escapeAttr(
      App.state.search
  )}"
  oninput="App.state.search=this.value;
           App.render.app()"
  placeholder="アンケートを検索"
  class="border rounded-lg px-3 py-2">

<select
  onchange="App.state.statusFilter=this.value;
            App.render.app()"
  class="border rounded-lg px-3 py-2">

<option value="">すべてのステータス</option>

<option
  value="draft"
  ${App.state.statusFilter === 'draft'
      ? 'selected'
      : ''}>
  下書き
</option>

<option
  value="active"
  ${App.state.statusFilter === 'active'
      ? 'selected'
      : ''}>
  公開中
</option>

<option
  value="ended"
  ${App.state.statusFilter === 'ended'
      ? 'selected'
      : ''}>
  終了
</option>

</select>

<select
  onchange="App.state.sort=this.value;
            App.render.app()"
  class="border rounded-lg px-3 py-2">

<option value="updated_desc">
  更新日時の新しい順
</option>

<option value="title">
  タイトル順
</option>

</select>

</div>
</div>

<div class="bg-white rounded-xl shadow overflow-hidden">

<div class="overflow-auto">

<table class="w-full text-sm">

<thead class="bg-slate-50">
<tr>
<th class="text-left p-4">タイトル</th>
<th class="text-left p-4">ステータス</th>
<th class="text-left p-4">操作</th>
</tr>
</thead>

<tbody>

${
surveys.length
    ? surveys.map(
        survey =>
            App.render.surveyRow(
                survey
            )
      ).join('')
    : `
<tr>
<td colspan="3"
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

</main>
`;
};

App.render.surveyRow =
    function(survey) {

    const status =
        survey.status || 'draft';

    let actions = `
<button
  onclick="App.actions.editSurvey(
      '${App.utils.escapeAttr(survey.id)}'
  )"
  class="text-blue-600 hover:underline">
  確認・編集
</button>
`;

    if (
        status === 'active' ||
        status === 'ended'
    ) {
        actions += `
<button
  onclick="alert('集計画面を開きます。')"
  class="text-indigo-600 hover:underline">
  集計
</button>

<button
  onclick="alert('送信画面を開きます。')"
  class="text-emerald-600 hover:underline">
  送信
</button>
`;
    }

    if (status === 'draft') {
        actions += `
<button
  onclick="App.actions.deleteSurvey(
      '${App.utils.escapeAttr(survey.id)}'
  )"
  class="text-red-600 hover:underline">
  削除
</button>
`;
    }

    actions += `
<button
  onclick="App.actions.duplicateSurvey(
      '${App.utils.escapeAttr(survey.id)}'
  )"
  class="text-slate-600 hover:underline">
  複製
</button>
`;

    return `
<tr class="border-t">

<td class="p-4 font-medium">
  ${App.utils.escapeHTML(
      survey.title || '無題'
  )}
</td>

<td class="p-4">
<span class="
inline-flex px-2 py-1 rounded-full
bg-slate-100 text-slate-700">
  ${App.utils.escapeHTML(
      App.utils.statusLabel(
          status
      )
  )}
</span>
</td>

<td class="p-4">
<div class="flex flex-wrap gap-3">
  ${actions}
</div>
</td>

</tr>
`;
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
        App.utils.deepClone(source);

    copy.id =
        App.utils.uid('survey');

    copy.title =
        (copy.title || '無題') +
        '（複製）';

    copy.status = 'draft';

    copy.groups =
        (copy.groups || []).map(
            group => {

            const g =
                App.utils.deepClone(group);

            g.id =
                App.utils.uid('group');

            g.questions =
                (g.questions || []).map(
                    q => {

                    const nq =
                        App.utils.deepClone(q);

                    nq.id =
                        App.utils.uid('question');

                    nq.options =
                        (nq.options || []).map(
                            o => ({
                                ...o,
                                id:
                                    App.utils.uid(
                                        'option'
                                    )
                            })
                        );

                    nq.branching = {};

                    return nq;
                }
            );

            return g;
        }
    );

    App.state.currentSurvey =
        App.utils.normalizeSurvey(copy);

    App.state.page = 'edit';

    App.render.app();
    App.initSortable();
};

/* ============================================================
 * Editor
 * ========================================================== */

App.render.editor = function() {

    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return '';
    }

    App.actions.renumberQuestions();

    return `
${App.render.header()}

<main class="max-w-7xl mx-auto px-4 pt-24 pb-12">

${App.render.breadcrumb([
    'ホーム',
    'アンケート一覧',
    '確認・編集'
])}

<div class="flex items-center justify-between
            gap-3 mb-6">

<h1 class="text-2xl font-bold">
  アンケート作成・編集
</h1>

<div class="flex gap-2">

<button
  onclick="App.actions.preview()"
  class="px-4 py-2 rounded-lg
         bg-indigo-600 text-white">
  プレビュー
</button>

<button
  onclick="App.actions.saveSurvey()"
  class="px-4 py-2 rounded-lg
         bg-blue-600 text-white">
  保存
</button>

</div>
</div>

<div class="space-y-6">

<section class="bg-white rounded-xl shadow p-6">

<h2 class="font-bold text-lg mb-4">
  基本設定
</h2>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">

<div class="md:col-span-2">

<label class="block text-sm font-semibold mb-1">
  タイトル
</label>

<input
  id="survey_title"
  value="${App.utils.escapeAttr(
      survey.title
  )}"
  class="w-full border rounded-lg px-3 py-2">

</div>

<div>

<label class="block text-sm font-semibold mb-1">
  開始日時
</label>

<input
  id="survey_start_at"
  type="datetime-local"
  value="${App.utils.escapeAttr(
      survey.start_at
  )}"
  class="w-full border rounded-lg px-3 py-2">

</div>

<div>

<label class="block text-sm font-semibold mb-1">
  終了日時
</label>

<input
  id="survey_end_at"
  type="datetime-local"
  value="${App.utils.escapeAttr(
      survey.end_at
  )}"
  class="w-full border rounded-lg px-3 py-2">

</div>

<div>

<label class="block text-sm font-semibold mb-1">
  ステータス
</label>

<select
  id="survey_status"
  onchange="App.actions.changeSurveyStatus(
      this.value
  )"
  class="w-full border rounded-lg px-3 py-2">

<option
  value="draft"
  ${survey.status === 'draft'
      ? 'selected'
      : ''}>
  下書き
</option>

<option
  value="active"
  ${survey.status === 'active'
      ? 'selected'
      : ''}>
  公開中
</option>

<option
  value="ended"
  ${survey.status === 'ended'
      ? 'selected'
      : ''}>
  終了
</option>

</select>

</div>

<div>

<label class="block text-sm font-semibold mb-1">
  質問番号形式
</label>

<select
  id="survey_numbering_mode"
  class="w-full border rounded-lg px-3 py-2">

<option
  value="global"
  ${survey.numbering_mode === 'global'
      ? 'selected'
      : ''}>
  Q1 / Q2 / Q3
</option>

<option
  value="group"
  ${survey.numbering_mode === 'group'
      ? 'selected'
      : ''}>
  Q1-1 / Q1-2 / Q2-1
</option>

</select>

</div>

<div>

<label class="flex items-center gap-2 mt-7">
<input
  type="checkbox"
  ${survey.general_response_allowed
      ? 'checked'
      : ''}
  onchange="
    App.state.currentSurvey
      .general_response_allowed =
      this.checked;
  ">
<span>一般回答を許可する</span>
</label>

</div>

</div>

</section>

<section>

<div class="space-y-5">

${survey.groups.map(
    (group, gi) =>
        App.render.group(
            group,
            gi,
            survey
        )
).join('')}

</div>

<button
  type="button"
  onclick="App.actions.addGroup()"
  class="mt-5 w-full py-3
         border-2 border-dashed
         rounded-xl
         text-slate-600
         hover:bg-white">
  ＋ ブロックを追加
</button>

</section>

</div>

</main>

${App.render.previewModal()}

`;
};

App.render.group =
    function(group, gi, survey) {

    return `
<section
  class="bg-white rounded-xl shadow p-6"
  data-group-id="${App.utils.escapeAttr(
      group.id
  )}">

<div class="flex items-center
            justify-between mb-4">

<h2 class="font-bold text-lg">
  ${App.utils.escapeHTML(
      group.name ||
      'ブロック' + (gi + 1)
  )}
</h2>

</div>

<div
  class="question-sortable space-y-4"
  data-group-id="${App.utils.escapeAttr(
      group.id
  )}">

${group.questions.map(
    question =>
        App.render.question(
            question,
            group,
            survey
        )
).join('')}

</div>

<button
  type="button"
  onclick="App.actions.addQuestion(
      '${App.utils.escapeAttr(group.id)}'
  )"
  class="mt-4 px-4 py-2
         rounded-lg
         border
         border-blue-300
         text-blue-700
         hover:bg-blue-50">
  ＋ 質問を追加
</button>

</section>
`;
};

App.render.question =
    function(question, group, survey) {

    const candidates =
        App.actions.branchCandidates(
            group.id,
            question.id
        );

    return `
<div
  class="question-item
         border rounded-xl p-4
         bg-slate-50"
  data-question-id="${App.utils.escapeAttr(
      question.id
  )}">

<div class="flex items-center
            justify-between mb-4">

<div class="font-bold">
  ${App.utils.escapeHTML(
      question.number ||
      question.group_number ||
      ''
  )}
</div>

<button
  type="button"
  onclick="App.actions.deleteQuestion(
      '${App.utils.escapeAttr(group.id)}',
      '${App.utils.escapeAttr(question.id)}'
  )"
  class="text-red-600">
  質問を削除
</button>

</div>

<div class="space-y-3">

<div>

<label class="block text-sm font-semibold mb-1">
  質問文
</label>

<textarea
  oninput="
    App.actions.findQuestion(
      '${App.utils.escapeAttr(group.id)}',
      '${App.utils.escapeAttr(question.id)}'
    ).text = this.value;
  "
  class="w-full border rounded-lg px-3 py-2"
  rows="3">${App.utils.escapeHTML(
      question.text
  )}</textarea>

</div>

<div>

<label class="block text-sm font-semibold mb-1">
  質問形式
</label>

<select
  onchange="
    const q = App.actions.findQuestion(
      '${App.utils.escapeAttr(group.id)}',
      '${App.utils.escapeAttr(question.id)}'
    );
    q.type = this.value;
    App.render.app();
    App.initSortable();
  "
  class="w-full border rounded-lg px-3 py-2">

<option
  value="text"
  ${question.type === 'text'
      ? 'selected'
      : ''}>
  テキスト
</option>

<option
  value="textarea"
  ${question.type === 'textarea'
      ? 'selected'
      : ''}>
  長文
</option>

<option
  value="single"
  ${question.type === 'single'
      ? 'selected'
      : ''}>
  単一選択
</option>

<option
  value="multiple"
  ${question.type === 'multiple'
      ? 'selected'
      : ''}>
  複数選択
</option>

<option
  value="date"
  ${question.type === 'date'
      ? 'selected'
      : ''}>
  日付
</option>

</select>

</div>

<label class="flex items-center gap-2">
<input
  type="checkbox"
  ${question.required ? 'checked' : ''}
  onchange="
    App.actions.findQuestion(
      '${App.utils.escapeAttr(group.id)}',
      '${App.utils.escapeAttr(question.id)}'
    ).required = this.checked;
  ">
<span>必須回答</span>
</label>

${
question.type === 'single'
    ? App.render.singleOptions(
        question,
        group,
        survey,
        candidates
      )
    : ''
}

</div>

</div>
`;
};

App.render.singleOptions =
    function(question, group, survey, candidates) {

    return `
<div class="border-t pt-4">

<div class="font-semibold mb-3">
  選択肢・分岐設定
</div>

<div class="space-y-3">

${question.options.map(
    option => `
<div
  class="grid grid-cols-1
         md:grid-cols-2 gap-2">

<input
  value="${App.utils.escapeAttr(
      option.text
  )}"
  oninput="
    const q = App.actions.findQuestion(
      '${App.utils.escapeAttr(group.id)}',
      '${App.utils.escapeAttr(question.id)}'
    );
    const o = q.options.find(
      x => x.id === '${App.utils.escapeAttr(option.id)}'
    );
    if (o) o.text = this.value;
  "
  placeholder="選択肢内容"
  class="border rounded-lg px-3 py-2">

<select
  onchange="
    const q = App.actions.findQuestion(
      '${App.utils.escapeAttr(group.id)}',
      '${App.utils.escapeAttr(question.id)}'
    );
    q.branching['${App.utils.escapeAttr(option.id)}'] =
      this.value || null;
  "
  class="border rounded-lg px-3 py-2">

<option value="">
  表示しない
</option>

${candidates.map(
    candidate => `
<option
  value="${App.utils.escapeAttr(
      candidate.id
  )}"
  ${
      question.branching?.[option.id] ===
      candidate.id
          ? 'selected'
          : ''
  }>
  ${App.utils.escapeHTML(
      candidate.number
  )}：
  ${App.utils.escapeHTML(
      candidate.text
  )}
</option>
`
).join('')}

</select>

</div>
`
).join('')}

<button
  type="button"
  onclick="App.actions.addOption(
      '${App.utils.escapeAttr(group.id)}',
      '${App.utils.escapeAttr(question.id)}'
  )"
  class="px-3 py-2 rounded-lg
         border text-sm">
  ＋ 選択肢を追加
</button>

</div>

</div>
`;
};

/* ============================================================
 * Preview
 * ========================================================== */

App.render.previewModal =
    function() {

    if (!App.state.previewSurvey) {
        return '';
    }

    const survey =
        App.state.previewSurvey;

    return `
<div
  id="preview_modal"
  class="fixed inset-0 z-[100]
         bg-black/50
         flex items-center
         justify-center p-4">

<div class="bg-white rounded-xl
            shadow-xl
            max-w-4xl w-full
            max-h-[90vh]
            overflow-auto">

<div class="p-6">

<div class="flex items-center
            justify-between mb-5">

<h2 class="text-xl font-bold">
  プレビュー
</h2>

<button
  onclick="App.state.previewSurvey=null;
           App.render.app()"
  class="text-slate-500">
  ✕
</button>

</div>

<h3 class="text-2xl font-bold mb-6">
  ${App.utils.escapeHTML(
      survey.title
  )}
</h3>

${survey.groups.map(
    group => `
<div class="mb-6">

<h4 class="font-bold mb-3">
  ${App.utils.escapeHTML(
      group.name
  )}
</h4>

${group.questions.map(
    q => `
<div class="border rounded-lg p-4 mb-3">

<div class="font-semibold mb-2">
  ${App.utils.escapeHTML(
      q.number ||
      q.group_number ||
      ''
  )}
  ${App.utils.escapeHTML(
      q.text
  )}
  ${q.required
      ? '<span class="text-red-600">*</span>'
      : ''}
</div>

${
q.type === 'single'
    ? `
<div class="space-y-2">
${q.options.map(
    o => `
<label class="flex gap-2">
<input type="radio"
       name="preview_${App.utils.escapeAttr(q.id)}">
<span>
${App.utils.escapeHTML(o.text)}
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
    o => `
<label class="flex gap-2">
<input type="checkbox">
<span>
${App.utils.escapeHTML(o.text)}
</span>
</label>
`
).join('')}
</div>
`
        : `
<input
  class="w-full border rounded-lg px-3 py-2"
  type="${
      q.type === 'date'
          ? 'date'
          : 'text'
  }">
`
}

</div>
`
).join('')}

</div>
`
).join('')}

</div>
</div>
</div>
`;
};

/* ============================================================
 * Response
 * ========================================================== */

App.render.response =
    function() {
    return '';
};

/* ============================================================
 * Main renderer
 * ========================================================== */

App.render.app = function() {

    const app =
        document.getElementById('app');

    if (!app) {
        return;
    }

    if (App.state.page === 'settings') {
        app.innerHTML =
            App.render.settings();
        return;
    }

    if (App.state.page === 'edit') {
        app.innerHTML =
            App.render.editor();

        /*
         * 動的HTML描画後にSortableを再初期化。
         */
        setTimeout(
            () => App.initSortable(),
            0
        );

        return;
    }

    app.innerHTML =
        App.render.list();
};

/* ============================================================
 * Init
 * ========================================================== */

App.init = async function() {

    if (App.state.initialized) {
        return;
    }

    App.state.initialized = true;

    try {
        await App.actions.loadData();
        App.render.app();
    } catch (error) {

        const app =
            document.getElementById(
                'app'
            );

        if (app) {
            app.innerHTML = `
<div class="min-h-screen
            flex items-center
            justify-center p-6">

<div class="bg-white rounded-xl
            shadow p-6
            max-w-xl w-full">

<h1 class="text-xl font-bold mb-3">
  アンケート管理システム
</h1>

<div class="text-red-700
            bg-red-50
            border border-red-200
            rounded-lg p-4">

${App.utils.escapeHTML(
    error.message
)}

</div>

<div class="text-sm
            text-slate-500 mt-4">
  index.phpがPHPサーバー経由で実行されていること、
  survey_storageへの書き込み権限、
  CSRFセッションCookieを確認してください。
</div>

</div>
</div>
`;
        }
    }
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
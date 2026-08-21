<?php
declare(strict_types=1);

/*
========================================================================
GUARD COMMENT — 固定名称一覧
※以下の名称は、今後の修正・再生成時も変更・削除禁止。

ストレージ:
- survey_storage_directory
- survey_storage_file
- survey_admin_session_v1

データトップキー:
- surveys
- responses
- customers
- settings
- mail_logs

アンケート項目:
- id
- title
- start_at
- end_at
- status
- created_at
- updated_at
- numbering_mode
- groups
- deleted

グループ項目:
- id
- name
- questions

質問項目:
- id
- text
- type
- required
- options
- other_enabled

質問形式:
- single
- multiple
- text

顧客項目:
- id
- company
- name
- email
- department
- phone
- address
- source
- sent_at
- send_count
- answer_status
- kintone_status

回答項目:
- id
- survey_id
- customer_id
- company
- name
- email
- answered_at
- answers

設定項目:
- subdomain
- login_name
- password
- app_id
- ssl_verify
- proxy
- field_company
- field_name
- field_email
- field_department
- field_phone
- field_address

POST/GETパラメータ:
- action
- survey_id
- customer_id
- response_id
- keyword
- status_filter
- sort
- survey_json
- settings_json
- csrf_token
- recipient_ids
- mail_subject
- mail_body
- template_type
- app_id

API/JSONキー:
- properties
- records
- label
- code
- type
- message
- ok
- fields

HTML DOM ID / JS参照名:
- app
- csrf_token
- survey_title
- survey_start_at
- survey_end_at
- survey_numbering_mode
- question_editor
- preview_modal
- preview_content
- response_modal
- response_detail
- response_filter
- response_table
- customer_filter
- customer_table
- select_all
- mail_subject
- mail_body
- template_type
- settings_form
- settings_json
- setting_subdomain
- setting_app_id
- setting_login_name
- setting_password
- setting_proxy
- setting_ssl_verify
- field_message

取り得る値:
- status: draft / active / ended
- numbering_mode: global / group
- type: single / multiple / text
- source: kintone / web
- answer_status: unanswered / answered
- kintone_status: unregistered / registered
- template_type: initial / reminder
========================================================================
*/

const SURVEY_STORAGE_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';
const SURVEY_STORAGE_FILE = SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

/* ---------------------------------------------------------------------
 * 共通
 * ------------------------------------------------------------------- */

function survey_h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function survey_json(mixed $value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: 'null';
}

function survey_uuid(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable) {
        return md5(uniqid('', true));
    }
}

function survey_default_data(): array
{
    return [
        'surveys' => [],
        'responses' => [],
        'customers' => [],
        'settings' => [
            'subdomain' => '',
            'login_name' => '',
            'password' => '',
            'app_id' => '',
            'ssl_verify' => true,
            'proxy' => '',
            'field_company' => '',
            'field_name' => '',
            'field_email' => '',
            'field_department' => '',
            'field_phone' => '',
            'field_address' => [],
        ],
        'mail_logs' => [],
    ];
}

function survey_read_data(): array
{
    if (!is_file(SURVEY_STORAGE_FILE)) {
        return survey_default_data();
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);
    $data = is_string($raw) ? json_decode($raw, true) : null;

    if (!is_array($data)) {
        return survey_default_data();
    }

    return array_replace_recursive(
        survey_default_data(),
        $data
    );
}

function survey_write_data(array $data): bool
{
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        if (!@mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true)) {
            return false;
        }
    }

    $tmp = SURVEY_STORAGE_FILE . '.tmp';
    $json = survey_json($data);

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    return @rename($tmp, SURVEY_STORAGE_FILE);
}

function survey_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function survey_check_token(): bool
{
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $postToken = (string)($_POST['csrf_token'] ?? '');

    return $sessionToken !== ''
        && $postToken !== ''
        && hash_equals($sessionToken, $postToken);
}

function survey_api_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo survey_json($payload);
    exit;
}

function survey_public_data(array $data): array
{
    /*
     * ブラウザ側へパスワードを返さない。
     * 保存済みパスワードはサーバー側JSONにのみ保持する。
     */
    $data['settings']['password'] = '';
    return $data;
}

/* ---------------------------------------------------------------------
 * kintone URL 正規化
 * ------------------------------------------------------------------- */

function survey_normalize_kintone_base(string $input): array
{
    $input = trim($input);
    $input = rtrim($input, "/ \t\r\n");

    if ($input === '') {
        return [
            'ok' => false,
            'error' => 'kintoneサブドメインが未入力です。'
        ];
    }

    /*
     * https:// がない場合は付与。
     */
    if (!preg_match('~^https?://~i', $input)) {
        $input = 'https://' . $input;
    }

    $host = '';
    $port = null;

    /*
     * parse_url()だけに依存しない。
     */
    $parsed = @parse_url($input);

    if (is_array($parsed)) {
        $host = (string)($parsed['host'] ?? '');
        $port = isset($parsed['port'])
            ? (int)$parsed['port']
            : null;
    }

    /*
     * parse_url()が失敗した場合のフォールバック。
     */
    if ($host === '') {
        if (preg_match(
            '~^https?://([^/?#]+)~i',
            $input,
            $matches
        )) {
            $authority = strtolower($matches[1]);

            if (str_contains($authority, ':')) {
                $parts = explode(':', $authority);
                $host = $parts[0];

                if (isset($parts[1]) && ctype_digit($parts[1])) {
                    $port = (int)$parts[1];
                }
            } else {
                $host = $authority;
            }
        }
    }

    $host = strtolower(trim($host));
    $host = trim($host, '[]');

    if ($host === '') {
        return [
            'ok' => false,
            'error' => 'kintoneホスト名を取得できません。'
        ];
    }

    if ($port !== null && ($port < 1 || $port > 65535)) {
        return [
            'ok' => false,
            'error' => 'kintoneポート番号が不正です。'
        ];
    }

    /*
     * 標準環境:
     *   xxxx.cybozu.com
     *
     * 検証環境・社内FQDN等については、
     * 明示的に入力されたホスト形式を許容する。
     */
    $validCybozu = preg_match(
        '~^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.cybozu\.com$~i',
        $host
    );

    $validHost = preg_match(
        '~^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$~i',
        $host
    );

    if (!$validCybozu && !$validHost) {
        return [
            'ok' => false,
            'error' => '許可されていないkintoneホスト名です。'
        ];
    }

    $authority = $host;

    if ($port !== null) {
        $authority .= ':' . $port;
    }

    return [
        'ok' => true,
        'base' => 'https://' . $authority,
        'host' => $host,
        'port' => $port,
    ];
}

/* ---------------------------------------------------------------------
 * Proxy
 *
 * 入力:
 *   host:port
 *   http://host:port
 *   https://host:port
 *
 * PHP stream contextのhttp.proxyへは
 * tcp://host:port を渡す。
 *
 * これが今回の
 * "Unable to find the socket transport "http""
 * 対策の重要部分。
 * ------------------------------------------------------------------- */

function survey_parse_proxy(string $input): array
{
    $input = trim($input);

    if ($input === '') {
        return [
            'ok' => true,
            'used' => false,
            'value' => '',
            'scheme' => '',
            'host' => '',
            'port' => 0,
        ];
    }

    if (!preg_match(
        '~^(?:(https?)://)?([^/:?#\s]+):(\d{1,5})$~i',
        $input,
        $m
    )) {
        return [
            'ok' => false,
            'used' => true,
            'value' => '',
            'error' =>
                'Proxy形式は host:port、http://host:port、https://host:port で指定してください。',
        ];
    }

    $scheme = strtolower($m[1] ?: 'http');
    $host = trim($m[2]);
    $port = (int)$m[3];

    if ($host === '') {
        return [
            'ok' => false,
            'used' => true,
            'value' => '',
            'error' => 'Proxyホスト名が空です。',
        ];
    }

    if ($port < 1 || $port > 65535) {
        return [
            'ok' => false,
            'used' => true,
            'value' => '',
            'error' => 'Proxyポート番号が不正です。',
        ];
    }

    /*
     * http.proxy は tcp://host:port を要求する。
     * 入力されたProxy自体のschemeはUI上の受け付け形式として保持するが、
     * stream wrapperにはtcpを渡す。
     */
    $streamProxy = 'tcp://' . $host . ':' . $port;

    return [
        'ok' => true,
        'used' => true,
        'value' => $streamProxy,
        'display' => $scheme . '://' . $host . ':' . $port,
        'scheme' => $scheme,
        'host' => $host,
        'port' => $port,
    ];
}

/* ---------------------------------------------------------------------
 * HTTP response headers
 * ------------------------------------------------------------------- */

function survey_last_headers(): array
{
    /*
     * PHP 8.4/8.5以降を考慮。
     */
    if (function_exists('http_get_last_response_headers')) {
        try {
            $headers = http_get_last_response_headers();

            if (is_array($headers)) {
                return $headers;
            }
        } catch (Throwable) {
        }
    }

    /*
     * fallback
     */
    global $http_response_header;

    if (isset($http_response_header) && is_array($http_response_header)) {
        return $http_response_header;
    }

    return [];
}

function survey_status_from_headers(array $headers): int
{
    /*
     * リダイレクトがあった場合は最後のHTTPステータスを優先。
     */
    $status = 0;

    foreach ($headers as $header) {
        if (preg_match(
            '~^HTTP/\S+\s+(\d{3})~i',
            (string)$header,
            $m
        )) {
            $status = (int)$m[1];
        }
    }

    return $status;
}

/* ---------------------------------------------------------------------
 * PHP stream HTTP request
 * ------------------------------------------------------------------- */

function survey_http_request(
    string $url,
    string $method,
    array $headers,
    ?string $content,
    bool $sslVerify,
    string $proxy
): array {
    $proxyInfo = survey_parse_proxy($proxy);

    if (!$proxyInfo['ok']) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' => $proxyInfo['error'],
            'url' => $url,
            'proxy_used' => true,
        ];
    }

    $wrappers = stream_get_wrappers();

    /*
     * HTTPS通信にはhttps wrapperが必要。
     * http wrapperもstream HTTP contextには必要なので両方確認。
     */
    if (
        !in_array('http', $wrappers, true) ||
        !in_array('https', $wrappers, true)
    ) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' =>
                'PHPからHTTP/HTTPS通信を実行できるstream transportが登録されていません。'
                . ' PHPのHTTP/HTTPS wrapper、OpenSSL設定を確認してください。',
            'url' => $url,
            'proxy_used' => $proxyInfo['used'],
        ];
    }

    $parts = @parse_url($url);

    $peerName = is_array($parts)
        ? (string)($parts['host'] ?? '')
        : '';

    if ($peerName === '') {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' => '接続先URLのホスト名を取得できません。',
            'url' => $url,
            'proxy_used' => $proxyInfo['used'],
        ];
    }

    $httpOptions = [
        'method' => strtoupper($method),
        'timeout' => 30,
        'ignore_errors' => true,
        'protocol_version' => 1.1,
        'header' => implode("\r\n", $headers),
    ];

    if ($content !== null) {
        $httpOptions['content'] = $content;
    }

    /*
     * Proxy空欄の場合はproxy/request_fulluriを絶対に追加しない。
     */
    if ($proxyInfo['used']) {
        $httpOptions['proxy'] = $proxyInfo['value'];
        $httpOptions['request_fulluri'] = true;
    }

    $context = stream_context_create([
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify,
            'allow_self_signed' => !$sslVerify,
            'SNI_enabled' => true,
            'peer_name' => $peerName,
        ],
    ]);

    $warning = '';

    set_error_handler(
        static function (
            int $severity,
            string $message
        ) use (&$warning): bool {
            $warning = $message;
            return true;
        }
    );

    try {
        $body = file_get_contents(
            $url,
            false,
            $context
        );
    } catch (Throwable $exception) {
        $body = false;

        if ($warning === '') {
            $warning = $exception->getMessage();
        }
    }

    restore_error_handler();

    $headersReceived = survey_last_headers();
    $status = survey_status_from_headers($headersReceived);
    $bodyText = is_string($body) ? $body : '';

    $decoded = json_decode($bodyText, true);

    /*
     * status=0は認証失敗ではない。
     */
    if ($status === 0) {
        $cause = $warning !== ''
            ? $warning
            : 'HTTPレスポンスを取得できませんでした。';

        $cause .=
            "\n確認事項: DNS名前解決、PHPサーバーからの外部HTTPS通信、"
            . "Proxy、Proxy形式、ファイアウォール、SSL/TLS、OpenSSL、タイムアウト。";

        if ($proxyInfo['used']) {
            $cause .= "\nProxy: 使用";
            $cause .= "\nProxy接続失敗またはProxy設定不正の可能性があります。";
        } else {
            $cause .= "\nProxy: 未使用";
        }

        return [
            'status' => 0,
            'body' => $bodyText,
            'json' => $decoded,
            'error' => $cause,
            'url' => $url,
            'proxy_used' => $proxyInfo['used'],
        ];
    }

    return [
        'status' => $status,
        'body' => $bodyText,
        'json' => $decoded,
        'error' => $warning,
        'url' => $url,
        'proxy_used' => $proxyInfo['used'],
    ];
}

/* ---------------------------------------------------------------------
 * kintone API
 * ------------------------------------------------------------------- */

function survey_kintone_request(
    array $settings,
    string $path,
    string $method = 'GET',
    ?string $content = null
): array {
    $normalized = survey_normalize_kintone_base(
        (string)($settings['subdomain'] ?? '')
    );

    if (!$normalized['ok']) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' => $normalized['error'],
            'url' => '',
            'proxy_used' => false,
        ];
    }

    $appId = trim((string)($settings['app_id'] ?? ''));

    if ($appId === '' || !preg_match('/^\d+$/', $appId)) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' => 'アプリIDは数字で入力してください。',
            'url' => '',
            'proxy_used' => false,
        ];
    }

    $cleanPath = ltrim($path, '/');

    /*
     * API URLは必ず
     * https://{normalized_host}/k/v1/{path}
     * 形式。
     */
    $url =
        $normalized['base']
        . '/k/v1/'
        . $cleanPath;

    $separator = str_contains($url, '?')
        ? '&'
        : '?';

    /*
     * app_idは必ずrawurlencode。
     */
    $url .=
        $separator
        . 'app='
        . rawurlencode($appId);

    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');

    /*
     * APIトークンは使用しない。
     * ログイン名・パスワードによる認証。
     */
    $authorization = base64_encode(
        $login . ':' . $password
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Accept: application/json',
        'Connection: close',
    ];

    if ($content !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    return survey_http_request(
        $url,
        $method,
        $headers,
        $content,
        (bool)($settings['ssl_verify'] ?? true),
        (string)($settings['proxy'] ?? '')
    );
}

/* ---------------------------------------------------------------------
 * HTTP status diagnostics
 * ------------------------------------------------------------------- */

function survey_kintone_diagnostic_message(array $result): string
{
    $status = (int)($result['status'] ?? 0);
    $url = (string)($result['url'] ?? '');
    $proxyUsed = !empty($result['proxy_used']);
    $error = trim((string)($result['error'] ?? ''));
    $body = trim((string)($result['body'] ?? ''));

    if ($status === 0) {
        $message =
            "kintoneからHTTPレスポンスを取得できませんでした。\n"
            . "HTTPステータス: 0\n"
            . "接続先: " . $url . "\n"
            . "Proxy: " . ($proxyUsed ? '使用' : '未使用') . "\n"
            . "PHP通信エラー: "
            . ($error !== '' ? $error : 'なし') . "\n"
            . "確認事項: DNS、PHPサーバーからの外部HTTPS通信、"
            . "Proxy、Proxy形式、ファイアウォール、SSL/TLS、OpenSSL、タイムアウト。";

        return $message;
    }

    if ($status === 401 || $status === 403) {
        return
            "kintone認証または権限エラーです。\n"
            . "HTTPステータス: {$status}\n"
            . "接続先: {$url}\n"
            . "ユーザー名: 入力済み\n"
            . "確認事項: ログイン名、パスワード、アプリ権限。";
    }

    if ($status === 404) {
        return
            "kintone APIまたはアプリが見つかりません。\n"
            . "HTTPステータス: 404\n"
            . "接続先: {$url}\n"
            . "確認事項: kintoneホスト名、アプリID、API URL。";
    }

    if ($status === 408) {
        return
            "kintone通信がタイムアウトしました。\n"
            . "HTTPステータス: 408\n"
            . "接続先: {$url}";
    }

    if ($status === 429) {
        return
            "kintone側のレート制限に達しました。\n"
            . "HTTPステータス: 429";
    }

    if ($status >= 500) {
        return
            "kintoneまたはProxy側でサーバーエラーが発生しました。\n"
            . "HTTPステータス: {$status}";
    }

    if ($body !== '') {
        return
            "kintone APIからエラー応答が返されました。\n"
            . "HTTPステータス: {$status}\n"
            . "本文: {$body}";
    }

    return
        "kintone API通信結果。\n"
        . "HTTPステータス: {$status}";
}

/* ---------------------------------------------------------------------
 * API
 * ------------------------------------------------------------------- */

$action = (string)(
    $_POST['action']
    ?? $_GET['action']
    ?? ''
);

if ($action !== '') {

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && !survey_check_token()
    ) {
        survey_api_response([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。',
        ], 419);
    }

    $data = survey_read_data();

    /* -------------------------------------------------------------
     * load
     * ----------------------------------------------------------- */
    if ($action === 'load') {
        survey_api_response([
            'ok' => true,
            'data' => survey_public_data($data),
        ]);
    }

    /* -------------------------------------------------------------
     * save_settings
     * ----------------------------------------------------------- */
    if ($action === 'save_settings') {
        $json = (string)(
            $_POST['settings_json'] ?? ''
        );

        $settings = json_decode(
            $json,
            true
        );

        if (!is_array($settings)) {
            survey_api_response([
                'ok' => false,
                'message' => '設定データが不正です。',
            ], 400);
        }

        $password = (string)(
            $settings['password']
            ?? ''
        );

        /*
         * 空パスワードの場合は既存パスワードを維持。
         */
        if ($password === '') {
            $password =
                (string)(
                    $data['settings']['password']
                    ?? ''
                );
        }

        $data['settings'] = array_replace(
            $data['settings'],
            [
                'subdomain' =>
                    trim((string)(
                        $settings['subdomain'] ?? ''
                    )),

                'login_name' =>
                    trim((string)(
                        $settings['login_name'] ?? ''
                    )),

                'password' =>
                    $password,

                'app_id' =>
                    trim((string)(
                        $settings['app_id'] ?? ''
                    )),

                'ssl_verify' =>
                    (bool)(
                        $settings['ssl_verify'] ?? true
                    ),

                'proxy' =>
                    trim((string)(
                        $settings['proxy'] ?? ''
                    )),

                'field_company' =>
                    (string)(
                        $settings['field_company'] ?? ''
                    ),

                'field_name' =>
                    (string)(
                        $settings['field_name'] ?? ''
                    ),

                'field_email' =>
                    (string)(
                        $settings['field_email'] ?? ''
                    ),

                'field_department' =>
                    (string)(
                        $settings['field_department'] ?? ''
                    ),

                'field_phone' =>
                    (string)(
                        $settings['field_phone'] ?? ''
                    ),

                'field_address' =>
                    is_array(
                        $settings['field_address'] ?? null
                    )
                        ? array_values(
                            $settings['field_address']
                        )
                        : [],
            ]
        );

        if (!survey_write_data($data)) {
            survey_api_response([
                'ok' => false,
                'message' => '設定保存に失敗しました。',
            ], 500);
        }

        survey_api_response([
            'ok' => true,
            'message' => '設定を保存しました。',
            'data' => survey_public_data($data),
        ]);
    }

    /* -------------------------------------------------------------
     * kintone fields / test
     * ----------------------------------------------------------- */
    if (
        $action === 'kintone_fields'
        || $action === 'kintone_test'
    ) {
        $result = survey_kintone_request(
            $data['settings'],
            'app/form/fields.json',
            'GET'
        );

        $status = (int)$result['status'];

        if ($status === 0) {
            survey_api_response([
                'ok' => false,
                'status' => 0,
                'message' =>
                    'kintoneからHTTPレスポンスを取得できませんでした。',
                'diagnostic' => [
                    'status' =>
                        $result['status'],

                    'url' =>
                        $result['url'],

                    'proxy_used' =>
                        $result['proxy_used'],

                    'error' =>
                        survey_kintone_diagnostic_message(
                            $result
                        ),

                    'body' =>
                        $result['body'],
                ],
            ]);
        }

        if ($status === 401 || $status === 403) {
            survey_api_response([
                'ok' => false,
                'status' => $status,
                'message' =>
                    'kintone認証または権限エラーです。',
                'diagnostic' => [
                    'status' => $status,
                    'url' => $result['url'],
                    'proxy_used' =>
                        $result['proxy_used'],
                    'error' =>
                        survey_kintone_diagnostic_message(
                            $result
                        ),
                ],
            ]);
        }

        if ($status === 404) {
            survey_api_response([
                'ok' => false,
                'status' => $status,
                'message' =>
                    'kintone APIまたはアプリが見つかりません。',
                'diagnostic' => [
                    'status' => $status,
                    'url' => $result['url'],
                    'error' =>
                        survey_kintone_diagnostic_message(
                            $result
                        ),
                ],
            ]);
        }

        if ($status < 200 || $status >= 300) {
            survey_api_response([
                'ok' => false,
                'status' => $status,
                'message' =>
                    'kintone APIエラーです。',
                'diagnostic' => [
                    'status' => $status,
                    'url' => $result['url'],
                    'error' =>
                        survey_kintone_diagnostic_message(
                            $result
                        ),
                    'body' => $result['body'],
                ],
            ]);
        }

        $json = is_array($result['json'])
            ? $result['json']
            : [];

        $properties =
            $json['properties']
            ?? null;

        /*
         * fields.jsonではpropertiesが正常。
         * 念のためrecords等の存在も確認可能な形にする。
         */
        if (!is_array($properties)) {
            survey_api_response([
                'ok' => false,
                'status' => $status,
                'message' =>
                    'kintone APIのレスポンスにpropertiesがありません。',
                'diagnostic' => [
                    'status' => $status,
                    'url' => $result['url'],
                    'body' => $result['body'],
                ],
            ]);
        }

        $fields = [];

        foreach ($properties as $code => $field) {
            if (!is_array($field)) {
                continue;
            }

            $fields[] = [
                'label' =>
                    (string)($field['label'] ?? $code),

                'code' =>
                    (string)($field['code'] ?? $code),

                'type' =>
                    (string)($field['type'] ?? ''),
            ];
        }

        survey_api_response([
            'ok' => true,
            'status' => $status,
            'message' =>
                $action === 'kintone_test'
                    ? 'kintone接続に成功しました。'
                    : '項目一覧を取得しました。',
            'fields' => $fields,
        ]);
    }

    /* -------------------------------------------------------------
     * save_survey
     * ----------------------------------------------------------- */
    if ($action === 'save_survey') {
        $survey = json_decode(
            (string)(
                $_POST['survey_json'] ?? ''
            ),
            true
        );

        if (!is_array($survey)) {
            survey_api_response([
                'ok' => false,
                'message' => 'アンケートデータが不正です。',
            ], 400);
        }

        $survey['id'] =
            (string)(
                $survey['id'] ?? survey_uuid()
            );

        $survey['title'] =
            trim((string)(
                $survey['title']
                ?? '無題のアンケート'
            ));

        $survey['status'] =
            in_array(
                $survey['status'] ?? 'draft',
                ['draft', 'active', 'ended'],
                true
            )
                ? $survey['status']
                : 'draft';

        $survey['numbering_mode'] =
            ($survey['numbering_mode'] ?? 'global')
            === 'group'
                ? 'group'
                : 'global';

        $survey['groups'] =
            is_array($survey['groups'] ?? null)
                ? $survey['groups']
                : [];

        /*
         * サーバー側でも質問構造を正規化。
         */
        foreach ($survey['groups'] as &$group) {
            $group['id'] =
                (string)(
                    $group['id'] ?? survey_uuid()
                );

            $group['name'] =
                (string)(
                    $group['name'] ?? 'グループ'
                );

            $group['questions'] =
                is_array(
                    $group['questions'] ?? null
                )
                    ? $group['questions']
                    : [];

            foreach ($group['questions'] as &$question) {
                $question['id'] =
                    (string)(
                        $question['id']
                        ?? survey_uuid()
                    );

                $question['text'] =
                    (string)(
                        $question['text']
                        ?? ''
                    );

                $question['type'] =
                    in_array(
                        $question['type'] ?? '',
                        ['single', 'multiple', 'text'],
                        true
                    )
                        ? $question['type']
                        : 'single';

                $question['required'] =
                    (bool)(
                        $question['required']
                        ?? false
                    );

                $question['options'] =
                    is_array(
                        $question['options'] ?? null
                    )
                        ? array_values(
                            array_map(
                                static fn($v) =>
                                    (string)$v,
                                $question['options']
                            )
                        )
                        : [];

                $question['other_enabled'] =
                    (bool)(
                        $question['other_enabled']
                        ?? false
                    );

                if ($question['type'] === 'text') {
                    $question['options'] = [];
                }
            }
            unset($question);
        }
        unset($group);

        $survey['updated_at'] = date('c');

        $survey['created_at'] =
            (string)(
                $survey['created_at']
                ?? date('c')
            );

        $survey['deleted'] = false;

        $found = false;

        foreach (
            $data['surveys']
            as $index => $existing
        ) {
            if (
                ($existing['id'] ?? '')
                === $survey['id']
            ) {
                $data['surveys'][$index] =
                    $survey;

                $found = true;
                break;
            }
        }

        if (!$found) {
            $data['surveys'][] = $survey;
        }

        if (!survey_write_data($data)) {
            survey_api_response([
                'ok' => false,
                'message' =>
                    'アンケート保存に失敗しました。',
            ], 500);
        }

        survey_api_response([
            'ok' => true,
            'message' =>
                'アンケートを保存しました。',
            'data' =>
                survey_public_data($data),
        ]);
    }

    /* -------------------------------------------------------------
     * delete_survey
     * ----------------------------------------------------------- */
    if ($action === 'delete_survey') {
        $surveyId =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        foreach (
            $data['surveys']
            as &$survey
        ) {
            if (
                ($survey['id'] ?? '')
                === $surveyId
            ) {
                $survey['deleted'] = true;
                $survey['updated_at'] = date('c');
            }
        }

        unset($survey);

        survey_write_data($data);

        survey_api_response([
            'ok' => true,
            'data' =>
                survey_public_data($data),
        ]);
    }

    /* -------------------------------------------------------------
     * duplicate_survey
     * ----------------------------------------------------------- */
    if ($action === 'duplicate_survey') {
        $surveyId =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        $copy = null;

        foreach (
            $data['surveys']
            as $survey
        ) {
            if (
                ($survey['id'] ?? '')
                === $surveyId
            ) {
                $copy = $survey;
                break;
            }
        }

        if (!is_array($copy)) {
            survey_api_response([
                'ok' => false,
                'message' =>
                    '対象アンケートがありません。',
            ], 404);
        }

        $copy['id'] = survey_uuid();

        $copy['title'] =
            (string)(
                $copy['title'] ?? ''
            )
            . '（複製）';

        $copy['status'] = 'draft';
        $copy['created_at'] = date('c');
        $copy['updated_at'] = date('c');
        $copy['deleted'] = false;

        $data['surveys'][] = $copy;

        if (!survey_write_data($data)) {
            survey_api_response([
                'ok' => false,
                'message' =>
                    'アンケート複製に失敗しました。',
            ], 500);
        }

        survey_api_response([
            'ok' => true,
            'data' =>
                survey_public_data($data),
        ]);
    }

    /* -------------------------------------------------------------
     * save_response
     * ----------------------------------------------------------- */
    if ($action === 'save_response') {
        $response = json_decode(
            (string)(
                $_POST['response_json'] ?? ''
            ),
            true
        );

        if (!is_array($response)) {
            survey_api_response([
                'ok' => false,
                'message' =>
                    '回答データが不正です。',
            ], 400);
        }

        $response['id'] =
            (string)(
                $response['id']
                ?? survey_uuid()
            );

        $response['answered_at'] = date('c');

        $data['responses'][] = $response;

        /*
         * メール照合可能な顧客を回答へ紐付け。
         */
        $email = strtolower(
            trim((string)(
                $response['email'] ?? ''
            ))
        );

        if ($email !== '') {
            foreach (
                $data['customers']
                as &$customer
            ) {
                if (
                    strtolower(
                        trim(
                            (string)(
                                $customer['email']
                                ?? ''
                            )
                        )
                    )
                    === $email
                ) {
                    $response['customer_id'] =
                        (string)(
                            $customer['id'] ?? ''
                        );

                    $customer['answer_status'] =
                        'answered';

                    $responseIndex =
                        array_key_last(
                            $data['responses']
                        );

                    if (
                        $responseIndex !== null
                    ) {
                        $data['responses'][$responseIndex]
                            = $response;
                    }

                    break;
                }
            }
            unset($customer);
        }

        if (!survey_write_data($data)) {
            survey_api_response([
                'ok' => false,
                'message' =>
                    '回答保存に失敗しました。',
            ], 500);
        }

        survey_api_response([
            'ok' => true,
            'message' =>
                '回答を保存しました。',
        ]);
    }

    /* -------------------------------------------------------------
     * CSV
     * ----------------------------------------------------------- */
    if ($action === 'csv') {
        $surveyId =
            (string)(
                $_GET['survey_id'] ?? ''
            );

        $survey = null;

        foreach (
            $data['surveys']
            as $item
        ) {
            if (
                ($item['id'] ?? '')
                === $surveyId
            ) {
                $survey = $item;
                break;
            }
        }

        if (!is_array($survey)) {
            http_response_code(404);
            exit('Not Found');
        }

        $questions = [];

        foreach (
            ($survey['groups'] ?? [])
            as $group
        ) {
            foreach (
                ($group['questions'] ?? [])
                as $question
            ) {
                $questions[] = $question;
            }
        }

        header(
            'Content-Type: text/csv; charset=UTF-8'
        );

        header(
            'Content-Disposition: attachment; filename="survey_'
            . rawurlencode($surveyId)
            . '.csv"'
        );

        $out = fopen(
            'php://output',
            'wb'
        );

        if ($out === false) {
            exit;
        }

        /*
         * UTF-8 BOM
         */
        fwrite(
            $out,
            "\xEF\xBB\xBF"
        );

        $header = [
            '回答ID',
            '回答日時',
            '顧客ID',
            '会社名',
            '氏名',
            'メールアドレス',
        ];

        foreach (
            $questions
            as $question
        ) {
            $header[] =
                (string)(
                    $question['text'] ?? ''
                );
        }

        fputcsv($out, $header);

        foreach (
            $data['responses']
            as $response
        ) {
            if (
                ($response['survey_id'] ?? '')
                !== $surveyId
            ) {
                continue;
            }

            $row = [
                $response['id'] ?? '',
                $response['answered_at'] ?? '',
                $response['customer_id'] ?? '',
                $response['company'] ?? '',
                $response['name'] ?? '',
                $response['email'] ?? '',
            ];

            $answers =
                is_array(
                    $response['answers'] ?? null
                )
                    ? $response['answers']
                    : [];

            foreach (
                $questions
                as $question
            ) {
                $questionId =
                    (string)(
                        $question['id'] ?? ''
                    );

                $answer =
                    $answers[$questionId]
                    ?? '';

                $row[] =
                    is_array($answer)
                        ? implode(
                            '、',
                            array_map(
                                static fn($v) =>
                                    (string)$v,
                                $answer
                            )
                        )
                        : (string)$answer;
            }

            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }

    survey_api_response([
        'ok' => false,
        'message' =>
            '不明な操作です。',
    ], 400);
}

/* ---------------------------------------------------------------------
 * Public survey
 * ------------------------------------------------------------------- */

$data = survey_read_data();
$csrf = survey_token();

if (
    isset($_GET['public'])
    && isset($_GET['survey_id'])
) {
    $surveyId =
        (string)$_GET['survey_id'];

    $publicSurvey = null;

    foreach (
        $data['surveys']
        as $survey
    ) {
        if (
            ($survey['id'] ?? '')
            === $surveyId
            && empty($survey['deleted'])
            && ($survey['status'] ?? '')
            === 'active'
        ) {
            $publicSurvey = $survey;
            break;
        }
    }

    if (!is_array($publicSurvey)) {
        http_response_code(404);
        exit(
            'アンケートが見つからないか、公開されていません。'
        );
    }

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= survey_h($publicSurvey['title']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 text-slate-800">

<main class="max-w-3xl mx-auto p-6">

<section class="bg-white rounded-2xl shadow p-6">

<h1 class="text-2xl font-bold mb-6">
<?= survey_h($publicSurvey['title']) ?>
</h1>

<form
    method="post"
    class="space-y-6"
    id="public_form"
>

<input type="hidden"
       name="action"
       value="save_response">

<input type="hidden"
       name="csrf_token"
       value="<?= survey_h($csrf) ?>">

<input type="hidden"
       name="response_json"
       id="public_response_json">

<div>
<label class="block font-semibold mb-1">
会社名
</label>
<input
    id="public_company"
    class="w-full border rounded-lg p-2"
>
</div>

<div>
<label class="block font-semibold mb-1">
氏名
</label>
<input
    id="public_name"
    class="w-full border rounded-lg p-2"
>
</div>

<div>
<label class="block font-semibold mb-1">
メールアドレス
</label>
<input
    id="public_email"
    type="email"
    class="w-full border rounded-lg p-2"
>
</div>

<?php foreach (
    ($publicSurvey['groups'] ?? [])
    as $group
): ?>

<fieldset class="border-t pt-4">

<legend class="font-bold mb-3">
<?= survey_h($group['name'] ?? '') ?>
</legend>

<?php foreach (
    ($group['questions'] ?? [])
    as $question
): ?>

<?php
$qid =
    (string)(
        $question['id'] ?? ''
    );

$qtype =
    (string)(
        $question['type'] ?? ''
    );
?>

<div class="mb-5">

<label class="block font-semibold mb-2">

<?= survey_h($question['text'] ?? '') ?>

<?php if (!empty($question['required'])): ?>
<span class="text-rose-600">*</span>
<?php endif; ?>

</label>

<?php if ($qtype === 'text'): ?>

<textarea
    data-question="<?= survey_h($qid) ?>"
    class="w-full border rounded-lg p-2"
></textarea>

<?php else: ?>

<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>

<label class="block mb-2">

<input
    data-question="<?= survey_h($qid) ?>"
    value="<?= survey_h($option) ?>"
    type="<?= $qtype === 'multiple'
        ? 'checkbox'
        : 'radio' ?>"
>

<?= survey_h($option) ?>

</label>

<?php endforeach; ?>

<?php if (!empty($question['other_enabled'])): ?>

<label class="block mb-2">
<input
    data-question="<?= survey_h($qid) ?>"
    value="その他"
    type="<?= $qtype === 'multiple'
        ? 'checkbox'
        : 'radio' ?>"
>
その他
</label>

<?php endif; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</fieldset>

<?php endforeach; ?>

<button
    type="button"
    onclick="App.public.submit()"
    class="bg-indigo-600 text-white px-5 py-3 rounded-lg"
>
回答を送信
</button>

</form>

</section>
</main>

<script>
window.App = window.App || {};

App.public = {

    submit() {

        const answers = {};

        document
            .querySelectorAll('[data-question]')
            .forEach(function(el) {

                const id =
                    el.dataset.question;

                if (el.type === 'checkbox') {

                    if (!answers[id]) {
                        answers[id] = [];
                    }

                    if (el.checked) {
                        answers[id].push(
                            el.value
                        );
                    }

                } else if (el.type === 'radio') {

                    if (el.checked) {
                        answers[id] =
                            el.value;
                    }

                } else {

                    answers[id] =
                        el.value;
                }
            });

        const response = {

            id:
                (crypto.randomUUID
                    ? crypto.randomUUID()
                    : String(Date.now())),

            survey_id:
                <?= survey_json($surveyId) ?>,

            customer_id: '',

            company:
                document.getElementById(
                    'public_company'
                ).value,

            name:
                document.getElementById(
                    'public_name'
                ).value,

            email:
                document.getElementById(
                    'public_email'
                ).value,

            answered_at: '',

            answers: answers
        };

        document.getElementById(
            'public_response_json'
        ).value =
            JSON.stringify(response);

        document.getElementById(
            'public_form'
        ).submit();
    }
};
</script>

</body>
</html>
<?php

    exit;
}

/* ---------------------------------------------------------------------
 * Admin SPA
 * ------------------------------------------------------------------- */
?>
<!doctype html>
<html lang="ja">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>

</head>

<body class="bg-slate-100 text-slate-800">

<div id="app"></div>

<script>
window.App = {

    state: {

        data: null,

        page: 'list',

        editing: null,

        selectedSurvey: null,

        fields: [],

        keyword: '',

        status_filter: 'all',

        sort: 'updated_desc',

        response_filter: '',

        customer_filter: '',

        csrf_token:
            <?= survey_json($csrf) ?>
    },

    api: {

        async request(
            action,
            values = {}
        ) {

            const body =
                new URLSearchParams();

            body.set(
                'action',
                action
            );

            body.set(
                'csrf_token',
                App.state.csrf_token
            );

            Object.keys(values)
                .forEach(function(key) {

                    body.set(
                        key,
                        typeof values[key] === 'string'
                            ? values[key]
                            : JSON.stringify(
                                values[key]
                            )
                    );
                });

            const response =
                await fetch(
                    location.pathname,
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type':
                                'application/x-www-form-urlencoded;charset=UTF-8'
                        },
                        body:
                            body.toString()
                    }
                );

            let json;

            try {
                json =
                    await response.json();
            } catch (error) {

                throw new Error(
                    'サーバーからJSON応答を取得できませんでした。HTTPステータス: '
                    + response.status
                );
            }

            if (!json.ok) {

                const diagnostic =
                    json.diagnostic || {};

                let message =
                    json.message
                    || '処理に失敗しました。';

                if (
                    diagnostic.error
                ) {
                    message +=
                        '\n\n'
                        + diagnostic.error;
                }

                throw new Error(message);
            }

            return json;
        },

        async load() {

            const response =
                await fetch(
                    location.pathname
                    + '?action=load',
                    {
                        cache: 'no-store'
                    }
                );

            const json =
                await response.json();

            if (!json.ok) {
                throw new Error(
                    json.message
                    || '読み込みに失敗しました。'
                );
            }

            App.state.data =
                json.data;
        }
    },

    actions: {

        async init() {

            try {

                await App.api.load();

                App.render.list();

            } catch (error) {

                App.render.error(
                    error.message
                );
            }
        },

        newSurvey() {

            App.state.editing =
                App.helpers.emptySurvey();

            App.state.page =
                'edit';

            App.render.edit();
        },

        editSurvey(id) {

            const survey =
                App.state.data.surveys
                    .find(
                        item =>
                            item.id === id
                    );

            if (!survey) {
                return;
            }

            App.state.editing =
                structuredClone(
                    survey
                );

            App.state.page =
                'edit';

            App.render.edit();
        },

        async saveSurvey() {

            const survey =
                App.helpers.readSurveyForm();

            App.helpers.normalizeSurveyNumbers(
                survey
            );

            App.state.editing =
                survey;

            try {

                const result =
                    await App.api.request(
                        'save_survey',
                        {
                            survey_json:
                                survey
                        }
                    );

                App.state.data =
                    result.data;

                App.state.page =
                    'list';

                App.state.editing =
                    null;

                App.render.list();

                alert(
                    '保存しました。'
                );

            } catch (error) {

                alert(
                    error.message
                );
            }
        },

        cancelEdit() {

            if (
                confirm(
                    '変更を破棄して一覧へ戻りますか？'
                )
            ) {

                App.state.page =
                    'list';

                App.state.editing =
                    null;

                App.render.list();
            }
        },

        async duplicateSurvey(id) {

            try {

                const result =
                    await App.api.request(
                        'duplicate_survey',
                        {
                            survey_id: id
                        }
                    );

                App.state.data =
                    result.data;

                App.render.list();

            } catch (error) {

                alert(
                    error.message
                );
            }
        },

        async deleteSurvey(id) {

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

                App.state.data =
                    result.data;

                App.render.list();

            } catch (error) {

                alert(
                    error.message
                );
            }
        },

        toggleStatus(id) {

            const survey =
                App.state.data.surveys
                    .find(
                        item =>
                            item.id === id
                    );

            if (!survey) {
                return;
            }

            if (
                survey.status === 'active'
            ) {

                if (
                    !confirm(
                        'このアンケートを停止しますか？'
                    )
                ) {
                    return;
                }

                survey.status =
                    'ended';

            } else {

                survey.status =
                    'active';
            }

            App.api.request(
                'save_survey',
                {
                    survey_json:
                        survey
                }
            )
            .then(function(result) {

                App.state.data =
                    result.data;

                App.render.list();

            })
            .catch(function(error) {

                alert(
                    error.message
                );
            });
        },

        openPreview() {

            const survey =
                App.helpers.readSurveyForm();

            App.helpers.normalizeSurveyNumbers(
                survey
            );

            document.getElementById(
                'preview_content'
            ).innerHTML =
                App.helpers.previewHtml(
                    survey
                );

            document.getElementById(
                'preview_modal'
            ).classList.remove(
                'hidden'
            );
        },

        closeModal(id) {

            const node =
                document.getElementById(id);

            if (node) {
                node.classList.add(
                    'hidden'
                );
            }
        },

        addGroup() {

            const survey =
                App.helpers.readSurveyForm();

            survey.groups.push({

                id:
                    App.helpers.uuid(),

                name:
                    '新しいグループ',

                questions: []
            });

            App.state.editing =
                survey;

            App.render.edit();
        },

        removeGroup(groupId) {

            const survey =
                App.helpers.readSurveyForm();

            if (
                survey.groups.length <= 1
            ) {

                alert(
                    '最低1グループは必要です。'
                );

                return;
            }

            survey.groups =
                survey.groups.filter(
                    group =>
                        group.id !== groupId
                );

            App.helpers.normalizeSurveyNumbers(
                survey
            );

            App.state.editing =
                survey;

            App.render.edit();
        },

        addQuestion(groupId) {

            const survey =
                App.helpers.readSurveyForm();

            const group =
                survey.groups.find(
                    item =>
                        item.id === groupId
                );

            if (!group) {
                return;
            }

            group.questions.push({

                id:
                    App.helpers.uuid(),

                text:
                    '新しい質問',

                type:
                    'single',

                required:
                    false,

                options:
                    [
                        '選択肢1',
                        '選択肢2'
                    ],

                other_enabled:
                    false
            });

            App.helpers.normalizeSurveyNumbers(
                survey
            );

            App.state.editing =
                survey;

            App.render.edit();
        },

        removeQuestion(
            groupId,
            questionId
        ) {

            const survey =
                App.helpers.readSurveyForm();

            const group =
                survey.groups.find(
                    item =>
                        item.id === groupId
                );

            if (!group) {
                return;
            }

            group.questions =
                group.questions.filter(
                    question =>
                        question.id !==
                        questionId
                );

            App.helpers.normalizeSurveyNumbers(
                survey
            );

            App.state.editing =
                survey;

            App.render.edit();
        },

        changeType(
            groupId,
            questionId,
            type
        ) {

            const survey =
                App.helpers.readSurveyForm();

            const question =
                App.helpers.findQuestion(
                    survey,
                    groupId,
                    questionId
                );

            if (!question) {
                return;
            }

            question.type =
                [
                    'single',
                    'multiple',
                    'text'
                ].includes(type)
                    ? type
                    : 'single';

            if (
                question.type === 'text'
            ) {
                question.options = [];
            }

            if (
                question.type !== 'text'
                && !Array.isArray(
                    question.options
                )
            ) {
                question.options = [
                    '選択肢1',
                    '選択肢2'
                ];
            }

            App.state.editing =
                survey;

            App.render.edit();
        },

        addOption(
            groupId,
            questionId
        ) {

            const survey =
                App.helpers.readSurveyForm();

            const question =
                App.helpers.findQuestion(
                    survey,
                    groupId,
                    questionId
                );

            if (!question) {
                return;
            }

            if (
                !Array.isArray(
                    question.options
                )
            ) {
                question.options = [];
            }

            question.options.push(
                '新しい選択肢'
            );

            App.state.editing =
                survey;

            App.render.edit();
        },

        removeOption(
            groupId,
            questionId,
            index
        ) {

            const survey =
                App.helpers.readSurveyForm();

            const question =
                App.helpers.findQuestion(
                    survey,
                    groupId,
                    questionId
                );

            if (!question) {
                return;
            }

            question.options.splice(
                Number(index),
                1
            );

            App.state.editing =
                survey;

            App.render.edit();
        },

        filterList() {

            const keyword =
                document.getElementById(
                    'list_keyword'
                );

            const status =
                document.getElementById(
                    'list_status'
                );

            const sort =
                document.getElementById(
                    'list_sort'
                );

            App.state.keyword =
                keyword
                    ? keyword.value
                    : '';

            App.state.status_filter =
                status
                    ? status.value
                    : 'all';

            App.state.sort =
                sort
                    ? sort.value
                    : 'updated_desc';

            App.render.list();
        },

        showAggregate(id) {

            App.state.selectedSurvey =
                id;

            App.state.page =
                'aggregate';

            App.render.aggregate();
        },

        showSend(id) {

            App.state.selectedSurvey =
                id;

            App.state.page =
                'send';

            App.render.send();
        },

        showSettings() {

            App.state.page =
                'settings';

            App.render.settings();
        },

        async testKintone() {

            try {

                /*
                 * 画面値を一時的にStateへ反映。
                 * test自体では保存しない。
                 */
                App.helpers.writeSettingsForm();

                const result =
                    await App.api.request(
                        'kintone_test'
                    );

                const node =
                    document.getElementById(
                        'field_message'
                    );

                if (node) {

                    node.textContent =
                        result.message
                        + '\nHTTPステータス: '
                        + result.status;
                }

            } catch (error) {

                const node =
                    document.getElementById(
                        'field_message'
                    );

                if (node) {
                    node.textContent =
                        error.message;
                }
            }
        },

        async fetchKintoneFields() {

            try {

                App.helpers.writeSettingsForm();

                const result =
                    await App.api.request(
                        'kintone_fields'
                    );

                App.state.fields =
                    Array.isArray(
                        result.fields
                    )
                        ? result.fields
                        : [];

                App.render.fieldSelectors();

                const node =
                    document.getElementById(
                        'field_message'
                    );

                if (node) {

                    node.textContent =
                        result.message
                        + '\nHTTPステータス: '
                        + result.status
                        + '\n取得フィールド数: '
                        + App.state.fields.length;
                }

            } catch (error) {

                const node =
                    document.getElementById(
                        'field_message'
                    );

                if (node) {
                    node.textContent =
                        error.message;
                }
            }
        },

        async saveSettings() {

            try {

                App.helpers.writeSettingsForm();

                const values =
                    App.helpers.readSettingsForm();

                const result =
                    await App.api.request(
                        'save_settings',
                        {
                            settings_json:
                                values
                        }
                    );

                App.state.data =
                    result.data;

                alert(
                    result.message
                );

            } catch (error) {

                alert(
                    error.message
                );
            }
        },

        openResponse(id) {

            const response =
                App.state.data.responses
                    .find(
                        item =>
                            item.id === id
                    );

            if (!response) {
                return;
            }

            const survey =
                App.state.data.surveys
                    .find(
                        item =>
                            item.id ===
                            response.survey_id
                    );

            document.getElementById(
                'response_detail'
            ).innerHTML =
                App.helpers.responseHtml(
                    response,
                    survey
                );

            document.getElementById(
                'response_modal'
            ).classList.remove(
                'hidden'
            );
        },

        filterResponses() {

            const node =
                document.getElementById(
                    'response_filter'
                );

            App.state.response_filter =
                node
                    ? node.value
                    : '';

            App.render.aggregate();
        },

        filterCustomers() {

            const node =
                document.getElementById(
                    'customer_filter'
                );

            App.state.customer_filter =
                node
                    ? node.value
                    : '';

            App.render.send();
        },

        toggleAllCustomers() {

            const all =
                document.getElementById(
                    'select_all'
                );

            document
                .querySelectorAll(
                    '[data-recipient]'
                )
                .forEach(function(node) {

                    if (
                        !node.disabled
                    ) {
                        node.checked =
                            !!all?.checked;
                    }
                });
        }
    },

    helpers: {

        uuid() {

            if (
                typeof crypto !== 'undefined'
                && typeof crypto.randomUUID ===
                    'function'
            ) {
                return crypto.randomUUID();
            }

            return (
                Date.now().toString(36)
                + Math.random()
                    .toString(36)
                    .slice(2)
            );
        },

        emptySurvey() {

            return {

                id:
                    App.helpers.uuid(),

                title:
                    '新しいアンケート',

                start_at:
                    '',

                end_at:
                    '',

                status:
                    'draft',

                created_at:
                    new Date().toISOString(),

                updated_at:
                    new Date().toISOString(),

                numbering_mode:
                    'global',

                groups: [

                    {
                        id:
                            App.helpers.uuid(),

                        name:
                            '基本情報',

                        questions: []
                    }
                ],

                deleted:
                    false
            };
        },

        esc(value) {

            return String(
                value ?? ''
            ).replace(
                /[&<>"']/g,
                function(char) {

                    return {
                        '&':
                            '&amp;',

                        '<':
                            '&lt;',

                        '>':
                            '&gt;',

                        '"':
                            '&quot;',

                        "'":
                            '&#039;'
                    }[char];
                }
            );
        },

        findQuestion(
            survey,
            groupId,
            questionId
        ) {

            const group =
                survey.groups.find(
                    item =>
                        item.id === groupId
                );

            return group
                ? group.questions.find(
                    item =>
                        item.id ===
                        questionId
                )
                : null;
        },

        /*
         * ------------------------------------------------------------
         * 質問番号の唯一の計算元
         *
         * global:
         *   Q1
         *   Q2
         *   Q3
         *
         * group:
         *   グループ1 -> Q1-1, Q1-2
         *   グループ2 -> Q2-1, Q2-2
         *
         * 番号そのものは保存せず、
         * groups/questionsの実際の配列順から毎回計算する。
         * ----------------------------------------------------------
         */
        getQuestionNumberMap(
            survey
        ) {

            const map = {};

            let globalNumber = 0;

            (
                survey.groups || []
            ).forEach(
                function(
                    group,
                    groupIndex
                ) {

                    let groupNumber = 0;

                    (
                        group.questions || []
                    ).forEach(
                        function(question) {

                            globalNumber++;
                            groupNumber++;

                            map[question.id] =
                                survey.numbering_mode ===
                                    'group'

                                    ? 'Q'
                                        + (groupIndex + 1)
                                        + '-'
                                        + groupNumber

                                    : 'Q'
                                        + globalNumber;
                        }
                    );
                }
            );

            return map;
        },

        questionNumber(
            survey,
            questionId
        ) {

            const map =
                App.helpers.getQuestionNumberMap(
                    survey
                );

            return map[questionId]
                || '';
        },

        normalizeSurveyNumbers(
            survey
        ) {

            if (
                survey.numbering_mode !==
                'group'
            ) {
                survey.numbering_mode =
                    'global';
            }

            /*
             * 質問番号は永続データへ保存しない。
             * 配列順だけを正とする。
             */
            return survey;
        },

        /*
         * DOMの実際の並び順をStateへ反映。
         *
         * これにより、
         * 「グループを跨いで質問をドラッグ」
         * した場合も移動先グループが正しく所属先になる。
         */
        syncOrderFromDom() {

            const survey =
                App.helpers.readSurveyForm(
                    true
                );

            const oldGroups =
                survey.groups || [];

            const groupMap = {};

            oldGroups.forEach(
                function(group) {

                    groupMap[group.id] =
                        group;
                }
            );

            const newGroups = [];

            document
                .querySelectorAll(
                    '#question_editor > .group-item'
                )
                .forEach(
                    function(groupNode) {

                        const groupId =
                            groupNode.dataset.groupId;

                        if (
                            !groupMap[groupId]
                        ) {
                            return;
                        }

                        const oldGroup =
                            groupMap[groupId];

                        const questionMap = {};

                        (
                            oldGroup.questions || []
                        ).forEach(
                            function(question) {

                                questionMap[
                                    question.id
                                ] = question;
                            }
                        );

                        const questions = [];

                        groupNode
                            .querySelectorAll(
                                ':scope > .question-list > .question-item'
                            )
                            .forEach(
                                function(
                                    questionNode
                                ) {

                                    const questionId =
                                        questionNode
                                            .dataset
                                            .questionId;

                                    if (
                                        questionMap[
                                            questionId
                                        ]
                                    ) {

                                        questions.push(
                                            questionMap[
                                                questionId
                                            ]
                                        );
                                    }
                                }
                            );

                        newGroups.push({

                            id:
                                oldGroup.id,

                            name:
                                oldGroup.name,

                            questions:
                                questions
                        });
                    }
                );

            survey.groups =
                newGroups;

            App.helpers.normalizeSurveyNumbers(
                survey
            );

            App.state.editing =
                survey;

            return survey;
        },

        /*
         * 編集画面のフォーム値をStateへ取得。
         *
         * reorder処理時はtrueを渡す。
         */
        readSurveyForm(
            preserveOrder = false
        ) {

            if (
                !App.state.editing
            ) {
                return App.helpers.emptySurvey();
            }

            const survey =
                structuredClone(
                    App.state.editing
                );

            const title =
                document.getElementById(
                    'survey_title'
                );

            if (title) {
                survey.title =
                    title.value;
            }

            const start =
                document.getElementById(
                    'survey_start_at'
                );

            if (start) {
                survey.start_at =
                    start.value;
            }

            const end =
                document.getElementById(
                    'survey_end_at'
                );

            if (end) {
                survey.end_at =
                    end.value;
            }

            const numbering =
                document.getElementById(
                    'survey_numbering_mode'
                );

            if (numbering) {
                survey.numbering_mode =
                    numbering.value ===
                    'group'
                        ? 'group'
                        : 'global';
            }

            document
                .querySelectorAll(
                    '[data-group-name]'
                )
                .forEach(
                    function(node) {

                        const group =
                            survey.groups.find(
                                item =>
                                    item.id ===
                                    node.dataset.groupName
                            );

                        if (group) {
                            group.name =
                                node.value;
                        }
                    }
                );

            document
                .querySelectorAll(
                    '[data-question-text]'
                )
                .forEach(
                    function(node) {

                        const group =
                            survey.groups.find(
                                item =>
                                    item.id ===
                                    node.dataset.groupId
                            );

                        if (!group) {
                            return;
                        }

                        const question =
                            group.questions.find(
                                item =>
                                    item.id ===
                                    node.dataset.questionText
                            );

                        if (question) {
                            question.text =
                                node.value;
                        }
                    }
                );

            document
                .querySelectorAll(
                    '[data-required]'
                )
                .forEach(
                    function(node) {

                        const group =
                            survey.groups.find(
                                item =>
                                    item.id ===
                                    node.dataset.groupId
                            );

                        if (!group) {
                            return;
                        }

                        const question =
                            group.questions.find(
                                item =>
                                    item.id ===
                                    node.dataset.required
                            );

                        if (question) {
                            question.required =
                                node.checked;
                        }
                    }
                );

            document
                .querySelectorAll(
                    '[data-option]'
                )
                .forEach(
                    function(node) {

                        const group =
                            survey.groups.find(
                                item =>
                                    item.id ===
                                    node.dataset.groupId
                            );

                        if (!group) {
                            return;
                        }

                        const question =
                            group.questions.find(
                                item =>
                                    item.id ===
                                    node.dataset.questionId
                            );

                        if (
                            question
                            && Array.isArray(
                                question.options
                            )
                        ) {

                            question.options[
                                Number(
                                    node.dataset.index
                                )
                            ] =
                                node.value;
                        }
                    }
                );

            App.helpers.normalizeSurveyNumbers(
                survey
            );

            return survey;
        },

        writeSettingsForm() {

            const settings =
                App.state.data.settings
                || {};

            const set =
                function(
                    id,
                    value
                ) {

                    const node =
                        document.getElementById(
                            id
                        );

                    if (node) {
                        node.value =
                            value ?? '';
                    }
                };

            set(
                'setting_subdomain',
                settings.subdomain
            );

            set(
                'setting_app_id',
                settings.app_id
            );

            set(
                'setting_login_name',
                settings.login_name
            );

            /*
             * passwordは再表示しない。
             */
            set(
                'setting_password',
                ''
            );

            set(
                'setting_proxy',
                settings.proxy
            );

            const verify =
                document.getElementById(
                    'setting_ssl_verify'
                );

            if (verify) {
                verify.checked =
                    settings.ssl_verify !== false;
            }
        },

        readSettingsForm() {

            const old =
                App.state.data.settings
                || {};

            const selected =
                function(id) {
                    return document.getElementById(
                        id
                    );
                };

            const address =
                Array.from(
                    document.querySelectorAll(
                        '[data-address-field]:checked'
                    )
                ).map(
                    node =>
                        node.value
                );

            return {

                ...old,

                subdomain:
                    selected(
                        'setting_subdomain'
                    )?.value || '',

                app_id:
                    selected(
                        'setting_app_id'
                    )?.value || '',

                login_name:
                    selected(
                        'setting_login_name'
                    )?.value || '',

                password:
                    selected(
                        'setting_password'
                    )?.value
                    || old.password
                    || '',

                proxy:
                    selected(
                        'setting_proxy'
                    )?.value || '',

                ssl_verify:
                    selected(
                        'setting_ssl_verify'
                    )?.checked
                    ?? true,

                field_company:
                    selected(
                        'field_company'
                    )?.value || '',

                field_name:
                    selected(
                        'field_name'
                    )?.value || '',

                field_email:
                    selected(
                        'field_email'
                    )?.value || '',

                field_department:
                    selected(
                        'field_department'
                    )?.value || '',

                field_phone:
                    selected(
                        'field_phone'
                    )?.value || '',

                field_address:
                    address
            };
        },

        statusLabel(status) {

            return {

                active:
                    '公開中',

                draft:
                    '下書き',

                ended:
                    '終了'

            }[status]
            || status;
        },

        statusClass(status) {

            return {

                active:
                    'bg-emerald-100 text-emerald-700',

                draft:
                    'bg-amber-100 text-amber-700',

                ended:
                    'bg-slate-200 text-slate-600'

            }[status]
            || 'bg-slate-100';
        },

        questionCount(survey) {

            return (
                survey.groups || []
            ).reduce(
                function(
                    sum,
                    group
                ) {

                    return sum
                        + (
                            group.questions
                            || []
                        ).length;

                },
                0
            );
        },

        responseCount(id) {

            return (
                App.state.data
                    .responses
                    || []
            ).filter(
                response =>
                    response.survey_id === id
            ).length;
        },

        previewHtml(survey) {

            const numberMap =
                App.helpers.getQuestionNumberMap(
                    survey
                );

            let html =
                '<div class="space-y-5">';

            html +=
                '<h2 class="text-2xl font-bold">'
                + App.helpers.esc(
                    survey.title
                )
                + '</h2>';

            (
                survey.groups || []
            ).forEach(
                function(group) {

                    html +=
                        '<section class="border-t pt-4">';

                    html +=
                        '<h3 class="font-bold mb-3">'
                        + App.helpers.esc(
                            group.name
                        )
                        + '</h3>';

                    (
                        group.questions || []
                    ).forEach(
                        function(question) {

                            html +=
                                '<div class="mb-4">';

                            html +=
                                '<label class="block font-semibold mb-2">'
                                + App.helpers.esc(
                                    numberMap[
                                        question.id
                                    ]
                                )
                                + ' '
                                + App.helpers.esc(
                                    question.text
                                )
                                + '</label>';

                            if (
                                question.type ===
                                'text'
                            ) {

                                html +=
                                    '<textarea class="w-full border rounded-lg p-2" disabled></textarea>';

                            } else {

                                (
                                    question.options
                                    || []
                                ).forEach(
                                    function(option) {

                                        html +=
                                            '<label class="block mb-1">';

                                        html +=
                                            '<input disabled type="'
                                            + (
                                                question.type ===
                                                'multiple'
                                                    ? 'checkbox'
                                                    : 'radio'
                                            )
                                            + '"> ';

                                        html +=
                                            App.helpers.esc(
                                                option
                                            );

                                        html +=
                                            '</label>';
                                    }
                                );
                            }

                            html +=
                                '</div>';
                        }
                    );

                    html +=
                        '</section>';
                }
            );

            return html
                + '</div>';
        },

        responseHtml(
            response,
            survey
        ) {

            let html =
                '<div class="space-y-4">';

            html +=
                '<p><b>会社名:</b> '
                + App.helpers.esc(
                    response.company
                )
                + '</p>';

            html +=
                '<p><b>氏名:</b> '
                + App.helpers.esc(
                    response.name
                )
                + '</p>';

            html +=
                '<p><b>メール:</b> '
                + App.helpers.esc(
                    response.email
                )
                + '</p>';

            html +=
                '<p><b>回答日時:</b> '
                + App.helpers.esc(
                    response.answered_at
                )
                + '</p>';

            html +=
                '<hr>';

            const questionMap = {};

            (
                survey?.groups || []
            ).forEach(
                function(group) {

                    (
                        group.questions || []
                    ).forEach(
                        function(question) {

                            questionMap[
                                question.id
                            ] = question;
                        }
                    );
                }
            );

            Object.keys(
                response.answers || {}
            ).forEach(
                function(key) {

                    const question =
                        questionMap[key];

                    const answer =
                        response.answers[key];

                    const text =
                        Array.isArray(answer)
                            ? answer.join('、')
                            : answer;

                    html +=
                        '<div class="border rounded-xl p-3">';

                    html +=
                        '<div class="font-semibold">';

                    html +=
                        App.helpers.esc(
                            question
                                ? question.text
                                : key
                        );

                    html +=
                        '</div>';

                    html +=
                        '<div class="mt-1 whitespace-pre-wrap">';

                    html +=
                        App.helpers.esc(
                            text
                        );

                    html +=
                        '</div>';

                    html +=
                        '</div>';
                }
            );

            return html
                + '</div>';
        }
    },

    render: {

        shell(content) {

            document.getElementById(
                'app'
            ).innerHTML = `

<header class="bg-white border-b">

<div class="max-w-7xl mx-auto px-6 py-4
            flex justify-between items-center">

<div class="font-bold text-xl">
アンケート管理システム
</div>

<nav class="flex gap-2">

<button
    onclick="App.render.list()"
    class="px-3 py-2 rounded hover:bg-slate-100"
>
アンケート一覧
</button>

<button
    onclick="App.actions.showSettings()"
    class="px-3 py-2 rounded hover:bg-slate-100"
>
kintone連携設定
</button>

</nav>
</div>
</header>

<main class="max-w-7xl mx-auto p-6">
${content}
</main>

<div
    id="preview_modal"
    class="hidden fixed inset-0 bg-black/40 p-6 z-20"
>

<div class="bg-white max-w-3xl mx-auto rounded-2xl p-6
            max-h-[90vh] overflow-auto">

<div class="flex justify-between mb-4">

<h2 class="font-bold text-xl">
プレビュー
</h2>

<button
    onclick="App.actions.closeModal('preview_modal')"
    class="px-3 py-1"
>
閉じる
</button>

</div>

<div id="preview_content"></div>

</div>
</div>

<div
    id="response_modal"
    class="hidden fixed inset-0 bg-black/40 p-6 z-20"
>

<div class="bg-white max-w-2xl mx-auto rounded-2xl p-6
            max-h-[90vh] overflow-auto">

<div class="flex justify-between mb-4">

<h2 class="font-bold text-xl">
回答詳細
</h2>

<button
    onclick="App.actions.closeModal('response_modal')"
    class="px-3 py-1"
>
閉じる
</button>

</div>

<div id="response_detail"></div>

</div>
</div>
`;
        },

        error(message) {

            App.render.shell(`

<div class="bg-white rounded-2xl shadow p-6">

<h1 class="text-xl font-bold text-red-600">
エラー
</h1>

<pre class="mt-4 whitespace-pre-wrap">${App.helpers.esc(
    message
)}</pre>

</div>
`);
        },

        list() {

            const state =
                App.state;

            let surveys =
                (
                    state.data?.surveys
                    || []
                ).filter(
                    item =>
                        !item.deleted
                );

            surveys =
                surveys.filter(
                    function(survey) {

                        const keyword =
                            String(
                                state.keyword
                                || ''
                            ).toLowerCase();

                        const title =
                            String(
                                survey.title
                                || ''
                            ).toLowerCase();

                        const keywordOk =
                            !keyword
                            || title.includes(
                                keyword
                            );

                        const statusOk =
                            state.status_filter ===
                                'all'
                            || survey.status ===
                                state.status_filter;

                        return (
                            keywordOk
                            && statusOk
                        );
                    }
                );

            surveys.sort(
                function(a, b) {

                    if (
                        state.sort ===
                        'responses_desc'
                    ) {

                        return (
                            App.helpers.responseCount(
                                b.id
                            )
                            -
                            App.helpers.responseCount(
                                a.id
                            )
                        );
                    }

                    if (
                        state.sort ===
                        'responses_asc'
                    ) {

                        return (
                            App.helpers.responseCount(
                                a.id
                            )
                            -
                            App.helpers.responseCount(
                                b.id
                            )
                        );
                    }

                    if (
                        state.sort ===
                        'start_desc'
                    ) {

                        return String(
                            b.start_at || ''
                        ).localeCompare(
                            String(
                                a.start_at || ''
                            )
                        );
                    }

                    if (
                        state.sort ===
                        'start_asc'
                    ) {

                        return String(
                            a.start_at || ''
                        ).localeCompare(
                            String(
                                b.start_at || ''
                            )
                        );
                    }

                    return state.sort ===
                        'updated_asc'

                        ? String(
                            a.updated_at || ''
                        ).localeCompare(
                            String(
                                b.updated_at || ''
                            )
                        )

                        : String(
                            b.updated_at || ''
                        ).localeCompare(
                            String(
                                a.updated_at || ''
                            )
                        );
                }
            );

            const rows =
                surveys.map(
                    function(survey) {

                        const count =
                            App.helpers.responseCount(
                                survey.id
                            );

                        let buttons = `

<button
    onclick="App.actions.editSurvey('${App.helpers.esc(survey.id)}')"
    class="text-indigo-600"
>
確認・編集
</button>

<button
    onclick="App.actions.duplicateSurvey('${App.helpers.esc(survey.id)}')"
    class="text-slate-600"
>
複製
</button>
`;

                        if (
                            survey.status ===
                            'active'
                        ) {

                            buttons += `

<button
    onclick="App.actions.showAggregate('${App.helpers.esc(survey.id)}')"
    class="text-indigo-600"
>
集計
</button>

<button
    onclick="App.actions.showSend('${App.helpers.esc(survey.id)}')"
    class="text-indigo-600"
>
送信
</button>

<button
    onclick="App.actions.toggleStatus('${App.helpers.esc(survey.id)}')"
    class="text-rose-600"
>
停止
</button>
`;
                        }

                        if (
                            survey.status ===
                            'draft'
                        ) {

                            buttons += `

<button
    onclick="App.actions.deleteSurvey('${App.helpers.esc(survey.id)}')"
    class="text-rose-600"
>
削除
</button>
`;
                        }

                        if (
                            survey.status ===
                            'ended'
                        ) {

                            buttons += `

<button
    onclick="App.actions.showAggregate('${App.helpers.esc(survey.id)}')"
    class="text-indigo-600"
>
集計
</button>
`;
                        }

                        const created =
                            String(
                                survey.created_at
                                || ''
                            ).slice(
                                0,
                                10
                            );

                        const updated =
                            String(
                                survey.updated_at
                                || ''
                            ).slice(
                                0,
                                10
                            );

                        return `

<tr class="border-t">

<td class="p-3">

<div>
${App.helpers.esc(created)}
</div>

<div class="text-xs text-slate-500">
更新: ${App.helpers.esc(updated)}
</div>

</td>

<td class="p-3 font-bold">
${App.helpers.esc(survey.title)}
</td>

<td class="p-3">

${App.helpers.esc(
    survey.start_at
    || '未設定'
)}

～

${App.helpers.esc(
    survey.end_at
    || '未設定'
)}

</td>

<td class="p-3">

<span
    class="px-2 py-1 rounded
    ${App.helpers.statusClass(
        survey.status
    )}"
>
${App.helpers.statusLabel(
    survey.status
)}
</span>

</td>

<td class="p-3">
${count} 件
</td>

<td class="p-3">

<div class="flex flex-wrap gap-2 text-sm">
${buttons}
</div>

</td>

</tr>
`;
                    }
                ).join('');

            App.render.shell(`

<div class="flex justify-between items-center mb-6">

<h1 class="text-2xl font-bold">
アンケート一覧
</h1>

<button
    onclick="App.actions.newSurvey()"
    class="bg-indigo-600 text-white px-4 py-2 rounded-lg"
>
＋ 新規アンケート作成
</button>

</div>

<div class="bg-white rounded-2xl shadow p-4 mb-5
            flex gap-3 flex-wrap">

<input
    id="list_keyword"
    value="${App.helpers.esc(
        state.keyword
    )}"
    onkeydown="if(event.key==='Enter')App.actions.filterList()"
    placeholder="タイトル検索"
    class="border rounded-lg p-2"
>

<select
    id="list_status"
    onchange="App.actions.filterList()"
    class="border rounded-lg p-2"
>

<option value="all">
すべて
</option>

<option
    value="active"
    ${state.status_filter === 'active'
        ? 'selected'
        : ''}
>
公開中
</option>

<option
    value="draft"
    ${state.status_filter === 'draft'
        ? 'selected'
        : ''}
>
下書き
</option>

<option
    value="ended"
    ${state.status_filter === 'ended'
        ? 'selected'
        : ''}
>
終了
</option>

</select>

<select
    id="list_sort"
    onchange="App.actions.filterList()"
    class="border rounded-lg p-2"
>

<option
    value="updated_desc"
    ${state.sort === 'updated_desc'
        ? 'selected'
        : ''}
>
更新日（新しい順）
</option>

<option
    value="updated_asc"
    ${state.sort === 'updated_asc'
        ? 'selected'
        : ''}
>
更新日（古い順）
</option>

<option
    value="responses_desc"
    ${state.sort === 'responses_desc'
        ? 'selected'
        : ''}
>
回答数（多い順）
</option>

<option
    value="responses_asc"
    ${state.sort === 'responses_asc'
        ? 'selected'
        : ''}
>
回答数（少ない順）
</option>

<option
    value="start_desc"
    ${state.sort === 'start_desc'
        ? 'selected'
        : ''}
>
アンケート期間開始日（新しい順）
</option>

<option
    value="start_asc"
    ${state.sort === 'start_asc'
        ? 'selected'
        : ''}
>
アンケート期間開始日（古い順）
</option>

</select>

</div>

<div class="bg-white rounded-2xl shadow overflow-auto">

<table class="w-full text-left min-w-[1000px]">

<thead class="bg-slate-50">

<tr>

<th class="p-3">
作成日 / 更新日
</th>

<th class="p-3">
タイトル
</th>

<th class="p-3">
期間
</th>

<th class="p-3">
ステータス
</th>

<th class="p-3">
回答数
</th>

<th class="p-3">
操作
</th>

</tr>

</thead>

<tbody>

${
    rows
    ||
    '<tr><td colspan="6" class="p-8 text-center text-slate-500">アンケートがありません。</td></tr>'
}

</tbody>

</table>

</div>
`);
        },

        edit() {

            const survey =
                App.state.editing;

            App.helpers.normalizeSurveyNumbers(
                survey
            );

            const numberMap =
                App.helpers.getQuestionNumberMap(
                    survey
                );

            const groups =
                survey.groups.map(
                    function(group) {

                        const questions =
                            group.questions.map(
                                function(question) {

                                    const options =
                                        question.type ===
                                            'text'

                                            ? ''

                                            : (
                                                question.options
                                                || []
                                            ).map(
                                                function(
                                                    option,
                                                    index
                                                ) {

                                                    return `

<div class="flex gap-2 mb-2">

<input
    data-option
    data-group-id="${App.helpers.esc(group.id)}"
    data-question-id="${App.helpers.esc(question.id)}"
    data-index="${index}"
    value="${App.helpers.esc(option)}"
    class="border rounded p-2 flex-1"
>

<button
    onclick="App.actions.removeOption('${App.helpers.esc(group.id)}','${App.helpers.esc(question.id)}',${index})"
    class="text-rose-600"
>
削除
</button>

</div>
`;
                                                }
                                            ).join('');

                                    return `

<div
    class="question-item border rounded-xl p-4 mb-3 bg-slate-50"
    data-question-id="${App.helpers.esc(question.id)}"
>

<div class="flex gap-3 items-start">

<div
    class="text-slate-400 text-xl cursor-move select-none"
    title="ドラッグして並び替え"
>
⠿
</div>

<div class="flex-1">

<div class="flex gap-2 mb-2">

<span
    class="font-bold whitespace-nowrap"
>
${App.helpers.esc(
    numberMap[question.id]
)}
</span>

<input
    data-question-text
    data-group-id="${App.helpers.esc(group.id)}"
    data-question-text="${App.helpers.esc(question.id)}"
    value="${App.helpers.esc(question.text)}"
    class="border rounded p-2 flex-1"
>

</div>

<div class="flex gap-3 items-center mb-3 flex-wrap">

<select
    onchange="App.actions.changeType('${App.helpers.esc(group.id)}','${App.helpers.esc(question.id)}',this.value)"
    class="border rounded p-2"
>

<option
    value="single"
    ${question.type === 'single'
        ? 'selected'
        : ''}
>
単一選択
</option>

<option
    value="multiple"
    ${question.type === 'multiple'
        ? 'selected'
        : ''}
>
複数選択
</option>

<option
    value="text"
    ${question.type === 'text'
        ? 'selected'
        : ''}
>
自由記述
</option>

</select>

<label>

<input
    data-required
    data-group-id="${App.helpers.esc(group.id)}"
    data-required="${App.helpers.esc(question.id)}"
    type="checkbox"
    ${question.required
        ? 'checked'
        : ''}
>

必須

</label>

<label>

<input
    type="checkbox"
    onchange="App.actions.toggleOther('${App.helpers.esc(group.id)}','${App.helpers.esc(question.id)}',this.checked)"
    ${question.other_enabled
        ? 'checked'
        : ''}
    ${question.type === 'text'
        ? 'disabled'
        : ''}
>

その他

</label>

<button
    onclick="App.actions.removeQuestion('${App.helpers.esc(group.id)}','${App.helpers.esc(question.id)}')"
    class="text-rose-600 ml-auto"
>
質問削除
</button>

</div>

${options}

${
    question.type !== 'text'

    ? `
<button
    onclick="App.actions.addOption('${App.helpers.esc(group.id)}','${App.helpers.esc(question.id)}')"
    class="text-indigo-600 text-sm"
>
＋ 選択肢追加
</button>
`
    : ''
}

</div>

</div>

</div>
`;
                                }
                            ).join('');

                        return `

<section
    class="group-item border rounded-2xl p-4 mb-5 bg-white"
    data-group-id="${App.helpers.esc(group.id)}"
>

<div class="flex gap-3 items-center mb-4">

<div
    class="text-slate-400 text-xl cursor-move select-none"
    title="グループをドラッグ"
>
⠿
</div>

<input
    data-group-name="${App.helpers.esc(group.id)}"
    value="${App.helpers.esc(group.name)}"
    class="border rounded p-2 font-bold flex-1"
>

<button
    onclick="App.actions.removeGroup('${App.helpers.esc(group.id)}')"
    class="text-rose-600"
>
グループ削除
</button>

</div>

<div class="question-list min-h-[30px]">

${questions}

</div>

<button
    onclick="App.actions.addQuestion('${App.helpers.esc(group.id)}')"
    class="text-indigo-600 mt-2"
>
＋ 質問追加
</button>

</section>
`;
                    }
                ).join('');

            App.render.shell(`

<div class="flex justify-between items-center mb-6 flex-wrap gap-3">

<h1 class="text-2xl font-bold">
アンケート作成・編集
</h1>

<div class="flex gap-2 flex-wrap">

<button
    onclick="App.actions.openPreview()"
    class="border px-4 py-2 rounded-lg"
>
プレビュー
</button>

<button
    onclick="App.actions.saveSurvey()"
    class="bg-indigo-600 text-white px-4 py-2 rounded-lg"
>
保存して一覧へ戻る
</button>

<button
    onclick="App.actions.cancelEdit()"
    class="border px-4 py-2 rounded-lg"
>
キャンセル
</button>

</div>
</div>

<div
    class="bg-white rounded-2xl shadow p-5 mb-5
           grid md:grid-cols-4 gap-3"
>

<input
    id="survey_title"
    value="${App.helpers.esc(survey.title)}"
    class="border rounded-lg p-2 md:col-span-2"
    placeholder="タイトル"
>

<input
    id="survey_start_at"
    value="${App.helpers.esc(survey.start_at)}"
    type="datetime-local"
    class="border rounded-lg p-2"
>

<input
    id="survey_end_at"
    value="${App.helpers.esc(survey.end_at)}"
    type="datetime-local"
    class="border rounded-lg p-2"
>

<select
    id="survey_numbering_mode"
    onchange="App.actions.changeNumberingMode(this.value)"
    class="border rounded-lg p-2"
>

<option
    value="global"
    ${survey.numbering_mode === 'global'
        ? 'selected'
        : ''}
>
質問番号：全体（Q1, Q2...）
</option>

<option
    value="group"
    ${survey.numbering_mode === 'group'
        ? 'selected'
        : ''}
>
質問番号：グループ別（Q1-1, Q1-2...）
</option>

</select>

</div>

<div id="question_editor">

${groups}

</div>

<button
    onclick="App.actions.addGroup()"
    class="bg-slate-800 text-white px-4 py-2 rounded-lg"
>
＋ グループ追加
</button>
`);

            /*
             * 同一グループ内＋グループ跨ぎを許可。
             */
            document
                .querySelectorAll(
                    '.question-list'
                )
                .forEach(
                    function(node) {

                        new Sortable(
                            node,
                            {

                                group:
                                    'survey_questions',

                                animation:
                                    150,

                                ghostClass:
                                    'opacity-40',

                                chosenClass:
                                    'ring-2 ring-indigo-300',

                                dragClass:
                                    'shadow-xl',

                                handle:
                                    '.cursor-move',

                                fallbackOnBody:
                                    true,

                                swapThreshold:
                                    0.65,

                                onEnd:
                                    function() {

                                        App.helpers.syncOrderFromDom();

                                        App.render.edit();
                                    }
                            }
                        );
                    }
                );

            const groupEditor =
                document.getElementById(
                    'question_editor'
                );

            if (groupEditor) {

                new Sortable(
                    groupEditor,
                    {

                        animation:
                            150,

                        ghostClass:
                            'opacity-40',

                        handle:
                            '.group-item > .flex .cursor-move',

                        onEnd:
                            function() {

                                App.helpers.syncOrderFromDom();

                                App.render.edit();
                            }
                    }
                );
            }
        },

        fieldSelectors() {

            const settings =
                App.state.data.settings
                || {};

            const properties =
                App.state.fields
                || [];

            const select =
                function(
                    id,
                    current,
                    multiple = false
                ) {

                    const items =
                        properties.map(
                            function(field) {

                                const label =
                                    field.label
                                    || field.code
                                    || '';

                                const code =
                                    field.code
                                    || '';

                                const selected =
                                    multiple

                                        ? (
                                            current
                                            || []
                                        ).includes(
                                            code
                                        )

                                        : current ===
                                            code;

                                return `

<option
    value="${App.helpers.esc(code)}"
    ${selected
        ? 'selected'
        : ''}
>
${App.helpers.esc(label)}
（${App.helpers.esc(code)}）
</option>
`;
                            }
                        ).join('');

                    return `

<select
    id="${id}"
    ${multiple
        ? 'multiple'
        : ''}
    class="border rounded-lg p-2 w-full"
>
${items}
</select>
`;
                };

            const replace =
                function(
                    id,
                    current,
                    multiple = false
                ) {

                    const node =
                        document.getElementById(
                            id
                        );

                    if (!node) {
                        return;
                    }

                    node.outerHTML =
                        select(
                            id,
                            current,
                            multiple
                        );
                };

            replace(
                'field_company',
                settings.field_company
            );

            replace(
                'field_name',
                settings.field_name
            );

            replace(
                'field_email',
                settings.field_email
            );

            replace(
                'field_department',
                settings.field_department
            );

            replace(
                'field_phone',
                settings.field_phone
            );

            replace(
                'field_address',
                settings.field_address,
                true
            );
        },

        settings() {

            App.render.shell(`

<h1 class="text-2xl font-bold mb-6">
kintone連携設定
</h1>

<div
    class="bg-white rounded-2xl shadow p-6 max-w-3xl"
    id="settings_form"
>

<div class="grid gap-4">

<label>

サブドメイン

<input
    id="setting_subdomain"
    placeholder="xxxx.cybozu.com"
    class="border rounded-lg p-2 w-full"
>

<p class="text-xs text-slate-500 mt-1">
xxxx.cybozu.com /
https://xxxx.cybozu.com /
https://xxxx.cybozu.com/
のいずれも入力できます。
</p>

</label>

<label>

アプリID

<input
    id="setting_app_id"
    inputmode="numeric"
    class="border rounded-lg p-2 w-full"
>

</label>

<label>

ログイン名

<input
    id="setting_login_name"
    autocomplete="username"
    class="border rounded-lg p-2 w-full"
>

</label>

<label>

パスワード

<input
    id="setting_password"
    type="password"
    autocomplete="new-password"
    class="border rounded-lg p-2 w-full"
>

<p class="text-xs text-slate-500">
空欄で保存した場合は、保存済みパスワードを維持します。
</p>

</label>

<label>

Proxy

<input
    id="setting_proxy"
    placeholder="host:port"
    class="border rounded-lg p-2 w-full"
>

<p class="text-xs text-slate-500">
host:port / http://host:port / https://host:port
</p>

</label>

<label class="flex items-center gap-2">

<input
    id="setting_ssl_verify"
    type="checkbox"
    checked
>

SSL証明書を検証する

</label>

</div>

<div class="flex gap-2 mt-5 flex-wrap">

<button
    onclick="App.actions.testKintone()"
    class="border px-4 py-2 rounded-lg"
>
接続確認
</button>

<button
    onclick="App.actions.fetchKintoneFields()"
    class="border px-4 py-2 rounded-lg"
>
項目一覧を再取得
</button>

<button
    onclick="App.actions.saveSettings()"
    class="bg-indigo-600 text-white px-4 py-2 rounded-lg"
>
保存
</button>

</div>

<p
    id="field_message"
    class="mt-4 whitespace-pre-wrap text-sm"
></p>

<hr class="my-6">

<h2 class="font-bold mb-4">
フィールドマッピング
</h2>

<div class="grid gap-4">

<label>
会社名
<select
    id="field_company"
    class="border rounded-lg p-2 w-full"
></select>
</label>

<label>
氏名
<select
    id="field_name"
    class="border rounded-lg p-2 w-full"
></select>
</label>

<label>
メールアドレス
<select
    id="field_email"
    class="border rounded-lg p-2 w-full"
></select>
</label>

<label>
部署名
<select
    id="field_department"
    class="border rounded-lg p-2 w-full"
></select>
</label>

<label>
電話番号
<select
    id="field_phone"
    class="border rounded-lg p-2 w-full"
></select>
</label>

<label>
住所
<select
    id="field_address"
    multiple
    class="border rounded-lg p-2 w-full min-h-32"
></select>
</label>

</div>

</div>
`);

            App.helpers.writeSettingsForm();

            if (
                Array.isArray(
                    App.state.fields
                )
                && App.state.fields.length
            ) {
                App.render.fieldSelectors();
            }
        },

        aggregate() {

            const survey =
                App.state.data.surveys
                    .find(
                        item =>
                            item.id ===
                            App.state.selectedSurvey
                    );

            if (!survey) {
                App.render.error(
                    'アンケートが見つかりません。'
                );
                return;
            }

            const allResponses =
                (
                    App.state.data.responses
                    || []
                ).filter(
                    item =>
                        item.survey_id ===
                        App.state.selectedSurvey
                );

            const filter =
                String(
                    App.state.response_filter
                    || ''
                ).toLowerCase();

            const responses =
                allResponses.filter(
                    function(response) {

                        if (!filter) {
                            return true;
                        }

                        return (
                            String(
                                response.company
                                || ''
                            ).toLowerCase()
                                .includes(filter)

                            ||

                            String(
                                response.name
                                || ''
                            ).toLowerCase()
                                .includes(filter)

                            ||

                            String(
                                response.email
                                || ''
                            ).toLowerCase()
                                .includes(filter)
                        );
                    }
                );

            const questionList = [];

            (
                survey.groups || []
            ).forEach(
                function(group) {

                    (
                        group.questions || []
                    ).forEach(
                        function(question) {

                            questionList.push(
                                question
                            );
                        }
                    );
                }
            );

            const numberMap =
                App.helpers.getQuestionNumberMap(
                    survey
                );

            const charts =
                questionList.map(
                    function(question) {

                        if (
                            question.type ===
                            'text'
                        ) {

                            const texts =
                                responses
                                    .map(
                                        item =>
                                            item.answers
                                            ?.[
                                                question.id
                                            ]
                                    )
                                    .filter(
                                        Boolean
                                    );

                            return `

<div
    class="border rounded-xl p-4 bg-white"
>

<h3 class="font-bold">

${App.helpers.esc(
    numberMap[question.id]
)}

${App.helpers.esc(
    question.text
)}

<span
    class="text-xs bg-slate-100 px-2 py-1 rounded ml-2"
>
自由記述
</span>

</h3>

<div class="mt-3 space-y-2">

${
    texts.map(
        function(text) {

            return `
<p
    class="bg-slate-50 p-2 rounded whitespace-pre-wrap"
>
${App.helpers.esc(text)}
</p>
`;
        }
    ).join('')
    ||
    '<p class="text-slate-500">回答なし</p>'
}

</div>

</div>
`;
                        }

                        const counts = {};

                        (
                            question.options
                            || []
                        ).forEach(
                            function(option) {

                                counts[
                                    option
                                ] = 0;
                            }
                        );

                        let totalAnswers =
                            0;

                        responses.forEach(
                            function(response) {

                                const answer =
                                    response
                                        .answers
                                        ?.[
                                            question.id
                                        ];

                                const values =
                                    Array.isArray(
                                        answer
                                    )
                                        ? answer
                                        : (
                                            answer
                                                ? [answer]
                                                : []
                                        );

                                values.forEach(
                                    function(value) {

                                        if (
                                            value
                                            && counts[
                                                value
                                            ] !==
                                            undefined
                                        ) {

                                            counts[
                                                value
                                            ]++;

                                            totalAnswers++;
                                        }
                                    }
                                );
                            }
                        );

                        return `

<div
    class="border rounded-xl p-4 bg-white"
>

<h3 class="font-bold mb-3">

${App.helpers.esc(
    numberMap[question.id]
)}

${App.helpers.esc(
    question.text
)}

<span
    class="text-xs bg-indigo-50 text-indigo-700 px-2 py-1 rounded ml-2"
>
${
    question.type === 'multiple'
        ? '複数選択'
        : '単一選択'
}
</span>

</h3>

${
    Object.entries(counts)
        .map(
            function(
                [label, count]
            ) {

                const percentage =
                    responses.length
                        ? (
                            count
                            /
                            responses.length
                            * 100
                        )
                        : 0;

                return `

<div class="mb-3">

<div
    class="flex justify-between text-sm"
>

<span>
${App.helpers.esc(label)}
</span>

<span>
${count}件
（${percentage.toFixed(1)}%）
</span>

</div>

<div
    class="bg-slate-200 rounded h-3"
>

<div
    class="bg-indigo-500 h-3 rounded"
    style="width:${Math.min(
        percentage,
        100
    )}%"
></div>

</div>

</div>
`;
            }
        ).join('')
}

</div>
`;
                    }
                ).join('');

            const responseRows =
                responses.map(
                    function(response) {

                        return `

<tr class="border-t">

<td class="p-3">
${App.helpers.esc(
    response.company
)}
</td>

<td class="p-3">
${App.helpers.esc(
    response.name
)}
</td>

<td class="p-3">
${App.helpers.esc(
    response.email
)}
</td>

<td class="p-3">
${App.helpers.esc(
    response.answered_at
)}
</td>

<td class="p-3">

<button
    onclick="App.actions.openResponse('${App.helpers.esc(response.id)}')"
    class="text-indigo-600"
>
全回答を表示
</button>

</td>

</tr>
`;
                    }
                ).join('');

            const customers =
                App.state.data.customers
                || [];

            const sentCustomers =
                customers.filter(
                    customer =>
                        customer.sent_at
                );

            const answeredCustomerIds =
                new Set(
                    allResponses
                        .map(
                            response =>
                                response.customer_id
                        )
                        .filter(Boolean)
                );

            const answeredByCustomers =
                sentCustomers.filter(
                    customer =>
                        answeredCustomerIds.has(
                            customer.id
                        )
                ).length;

            const responseRate =
                sentCustomers.length
                    ? (
                        answeredByCustomers
                        /
                        sentCustomers.length
                        * 100
                    )
                    : 0;

            const unregistered =
                allResponses.filter(
                    response =>
                        !response.customer_id
                ).length;

            const unanswered =
                sentCustomers.length
                -
                answeredByCustomers;

            App.render.shell(`

<div
    class="flex justify-between mb-6 items-center flex-wrap gap-3"
>

<h1 class="text-2xl font-bold">
${App.helpers.esc(
    survey.title
)}：集計
</h1>

<a
    href="?action=csv&survey_id=${encodeURIComponent(
        App.state.selectedSurvey
    )}"
    class="bg-indigo-600 text-white px-4 py-2 rounded-lg"
>
CSV出力
</a>

</div>

<div
    class="grid md:grid-cols-5 gap-4 mb-6"
>

<div class="bg-white rounded-xl shadow p-4">

<div class="text-sm text-slate-500">
送信対象者数
</div>

<div class="text-2xl font-bold">
${sentCustomers.length} 人
</div>

</div>

<div class="bg-white rounded-xl shadow p-4">

<div class="text-sm text-slate-500">
回答数
</div>

<div class="text-2xl font-bold">
${allResponses.length} 件
</div>

</div>

<div class="bg-white rounded-xl shadow p-4">

<div class="text-sm text-slate-500">
未登録顧客からの回答数
</div>

<div class="text-2xl font-bold">
${unregistered} 件
</div>

</div>

<div class="bg-white rounded-xl shadow p-4">

<div class="text-sm text-slate-500">
未回答数
</div>

<div class="text-2xl font-bold">
${Math.max(unanswered, 0)} 人
</div>

</div>

<div class="bg-white rounded-xl shadow p-4">

<div class="text-sm text-slate-500">
回答率
</div>

<div class="text-2xl font-bold">
${responseRate.toFixed(1)} %
</div>

</div>

</div>

<div
    class="bg-white rounded-2xl shadow p-4 mb-5"
>

<div class="flex gap-3 items-center">

<label class="font-semibold">
回答者検索
</label>

<input
    id="response_filter"
    value="${App.helpers.esc(
        App.state.response_filter
    )}"
    onkeydown="if(event.key==='Enter')App.actions.filterResponses()"
    placeholder="会社名・氏名・メール"
    class="border rounded-lg p-2 flex-1"
>

<button
    onclick="App.actions.filterResponses()"
    class="border px-4 py-2 rounded-lg"
>
絞り込み
</button>

</div>

</div>

<div
    class="grid gap-4 mb-6"
>

${
    charts
    ||
    `
<div
    class="bg-white p-8 rounded-xl text-center text-slate-500"
>
現在、回答データはありません
</div>
`
}

</div>

<div
    class="bg-white rounded-2xl shadow overflow-auto"
>

<table class="w-full text-left min-w-[800px]">

<thead class="bg-slate-50">

<tr>

<th class="p-3">
会社名
</th>

<th class="p-3">
氏名
</th>

<th class="p-3">
メール
</th>

<th class="p-3">
回答日時
</th>

<th class="p-3">
詳細
</th>

</tr>

</thead>

<tbody>

${
    responseRows
    ||
    `
<tr>
<td
    colspan="5"
    class="p-6 text-center text-slate-500"
>
回答なし
</td>
</tr>
`
}

</tbody>

</table>

</div>
`);
        },

        send() {

            const survey =
                App.state.data.surveys
                    .find(
                        item =>
                            item.id ===
                            App.state.selectedSurvey
                    );

            if (!survey) {
                App.render.error(
                    'アンケートが見つかりません。'
                );
                return;
            }

            const filter =
                String(
                    App.state.customer_filter
                    || ''
                ).toLowerCase();

            const customers =
                (
                    App.state.data.customers
                    || []
                ).filter(
                    function(customer) {

                        if (!filter) {
                            return true;
                        }

                        return (
                            String(
                                customer.company
                                || ''
                            ).toLowerCase()
                                .includes(filter)

                            ||

                            String(
                                customer.name
                                || ''
                            ).toLowerCase()
                                .includes(filter)

                            ||

                            String(
                                customer.email
                                || ''
                            ).toLowerCase()
                                .includes(filter)

                            ||

                            String(
                                customer.answer_status
                                || ''
                            ).toLowerCase()
                                .includes(filter)
                        );
                    }
                );

            App.render.shell(`

<h1 class="text-2xl font-bold mb-6">

${App.helpers.esc(
    survey.title
)}

：顧客選択・送信

</h1>

<div
    class="bg-white rounded-2xl shadow p-6"
>

<div class="grid gap-4">

<input
    id="mail_subject"
    placeholder="件名"
    class="border rounded-lg p-2"
>

<textarea
    id="mail_body"
    rows="7"
    placeholder="{顧客名} 様&#10;アンケートURL: {アンケートURL}"
    class="border rounded-lg p-2"
></textarea>

<select
    id="template_type"
    class="border rounded-lg p-2"
>

<option value="initial">
初回
</option>

<option value="reminder">
リマインド
</option>

</select>

</div>

<div class="flex gap-3 my-5">

<input
    id="customer_filter"
    value="${App.helpers.esc(
        App.state.customer_filter
    )}"
    onkeydown="if(event.key==='Enter')App.actions.filterCustomers()"
    placeholder="顧客名・メール・ステータス"
    class="border rounded-lg p-2 flex-1"
>

<button
    onclick="App.actions.filterCustomers()"
    class="border px-4 py-2 rounded-lg"
>
絞り込み
</button>

</div>

<div
    class="overflow-auto"
>

<table
    id="customer_table"
    class="w-full text-left min-w-[900px]"
>

<thead class="bg-slate-50">

<tr>

<th class="p-3">

<input
    id="select_all"
    type="checkbox"
    onchange="App.actions.toggleAllCustomers()"
>

</th>

<th class="p-3">
会社名
</th>

<th class="p-3">
氏名
</th>

<th class="p-3">
メール
</th>

<th class="p-3">
電話番号
</th>

<th class="p-3">
送信ステータス
</th>

<th class="p-3">
回答ステータス
</th>

</tr>

</thead>

<tbody>

${
    customers.map(
        function(customer) {

            const disabled =
                customer.source ===
                'web';

            return `

<tr class="border-t">

<td class="p-3">

<input
    type="checkbox"
    data-recipient="${App.helpers.esc(customer.id)}"
    ${disabled
        ? 'disabled'
        : ''}
>

</td>

<td class="p-3">

<div class="font-bold">
${App.helpers.esc(
    customer.company
)}
</div>

${App.helpers.esc(
    customer.address
    || ''
)}

</td>

<td class="p-3">
${App.helpers.esc(
    customer.name
)}
</td>

<td class="p-3">
${App.helpers.esc(
    customer.email
)}
</td>

<td class="p-3">
${App.helpers.esc(
    customer.phone
)}
</td>

<td class="p-3">

${
    customer.sent_at
        ? `
<div>
最終送信:
${App.helpers.esc(
    customer.sent_at
)}
</div>
<div>
${Number(
    customer.send_count || 0
)} 回
</div>
`
        : '未送信'
}

</td>

<td class="p-3">

<span
    class="px-2 py-1 rounded
    ${
        customer.answer_status ===
        'answered'
            ? 'bg-emerald-100 text-emerald-700'
            : 'bg-amber-100 text-amber-700'
    }"
>
${
    customer.answer_status ===
    'answered'
        ? '回答済み'
        : '送信済み（未回答）'
}
</span>

</td>

</tr>
`;
        }
    ).join('')
    ||
    `
<tr>
<td
    colspan="7"
    class="p-6 text-center text-slate-500"
>
顧客データがありません。
</td>
</tr>
`
}

</tbody>

</table>

</div>

<div class="mt-5 flex gap-3">

<button
    onclick="App.actions.executeSend()"
    class="bg-indigo-600 text-white px-4 py-2 rounded-lg"
>
一括送信実行
</button>

<button
    onclick="App.actions.showSettings()"
    class="border px-4 py-2 rounded-lg"
>
kintone設定
</button>

</div>

</div>
`);
        }
    }
};

/* ---------------------------------------------------------------------
 * 追加Action
 * ------------------------------------------------------------------- */

App.actions.changeNumberingMode =
    function(mode) {

        const survey =
            App.helpers.readSurveyForm();

        survey.numbering_mode =
            mode === 'group'
                ? 'group'
                : 'global';

        App.helpers.normalizeSurveyNumbers(
            survey
        );

        App.state.editing =
            survey;

        App.render.edit();
    };

App.actions.toggleOther =
    function(
        groupId,
        questionId,
        checked
    ) {

        const survey =
            App.helpers.readSurveyForm();

        const question =
            App.helpers.findQuestion(
                survey,
                groupId,
                questionId
            );

        if (!question) {
            return;
        }

        question.other_enabled =
            !!checked;

        App.state.editing =
            survey;

        /*
         * 再描画すると入力途中の内容が失われるため、
         * Stateのみ更新。
         */
    };

App.actions.executeSend =
    async function() {

        const checked =
            Array.from(
                document.querySelectorAll(
                    '[data-recipient]:checked'
                )
            ).map(
                node =>
                    node.dataset.recipient
            );

        if (!checked.length) {

            alert(
                '送信対象を選択してください。'
            );

            return;
        }

        const alreadySent =
            checked.filter(
                function(id) {

                    const customer =
                        (
                            App.state.data
                                .customers
                            || []
                        ).find(
                            item =>
                                item.id === id
                        );

                    return !!customer?.sent_at;
                }
            );

        if (
            alreadySent.length
            && !confirm(
                '既に送信済みの宛先が含まれています。再送しますか？'
            )
        ) {
            return;
        }

        /*
         * メールサーバー設定がこの1ファイルにはないため、
         * 実送信はここでは行わず、送信内容をログ化する。
         */
        const subject =
            document.getElementById(
                'mail_subject'
            )?.value || '';

        const body =
            document.getElementById(
                'mail_body'
            )?.value || '';

        const template =
            document.getElementById(
                'template_type'
            )?.value || 'initial';

        alert(
            '送信対象 '
            + checked.length
            + ' 件を選択しました。\n\n'
            + '件名: '
            + subject
            + '\nテンプレート: '
            + template
            + '\n\n'
            + 'このindex.php単体ではSMTP送信設定がないため、実メール送信は実行していません。'
        );
    };

/* ---------------------------------------------------------------------
 * Initialization
 * ------------------------------------------------------------------- */

if (
    document.readyState ===
    'loading'
) {

    document.addEventListener(
        'DOMContentLoaded',
        function() {
            App.actions.init();
        },
        {
            once: true
        }
    );

} else {

    App.actions.init();
}

</script>

</body>
</html>

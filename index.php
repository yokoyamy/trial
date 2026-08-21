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

/* =========================================================
 * 共通
 * ========================================================= */

function survey_h(mixed $v): string
{
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function survey_json(mixed $v): string
{
    return json_encode(
        $v,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: 'null';
}

function survey_id(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable) {
        return sha1(uniqid('', true));
    }
}

function survey_now(): string
{
    return date('Y-m-d H:i:s');
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

    if (!is_string($raw) || $raw === '') {
        return survey_default_data();
    }

    $data = json_decode($raw, true);

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

    if (
        @file_put_contents(
            $tmp,
            survey_json($data),
            LOCK_EX
        ) === false
    ) {
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
    $a = (string)($_SESSION['csrf_token'] ?? '');
    $b = (string)($_POST['csrf_token'] ?? '');

    return $a !== '' &&
           $b !== '' &&
           hash_equals($a, $b);
}

function survey_api(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo survey_json($data);
    exit;
}

function survey_public_data(array $data): array
{
    $data['settings']['password'] = '';
    return $data;
}

/* =========================================================
 * kintone URL
 * ========================================================= */

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

    if (!preg_match('~^https?://~i', $input)) {
        $input = 'https://' . $input;
    }

    $host = '';
    $port = null;

    $parsed = @parse_url($input);

    if (is_array($parsed)) {
        $host = (string)($parsed['host'] ?? '');

        if (isset($parsed['port'])) {
            $port = (int)$parsed['port'];
        }
    }

    /*
     * parse_url()だけに依存しない。
     */
    if ($host === '') {
        if (preg_match(
            '~^https?://([^/?#]+)~i',
            $input,
            $m
        )) {
            $authority = strtolower($m[1]);

            if (preg_match(
                '~^(.+):(\d+)$~',
                $authority,
                $pm
            )) {
                $host = $pm[1];
                $port = (int)$pm[2];
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
     * 通常のcybozu.comを許可。
     * また検証環境・社内FQDN等を想定して一般的なFQDNも許可。
     *
     * Proxyの値はここへ絶対に渡さない。
     */
    $valid =
        preg_match(
            '~^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.cybozu\.com$~i',
            $host
        )
        ||
        preg_match(
            '~^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$~i',
            $host
        );

    if (!$valid) {
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

/* =========================================================
 * Proxy
 *
 * 入力:
 *   host:port
 *   http://host:port
 *   https://host:port
 *
 * stream context:
 *   tcp://host:port
 *
 * 「http://」をhttp.proxyへそのまま渡さない。
 * ========================================================= */

function survey_parse_proxy(string $input): array
{
    $input = trim($input);

    if ($input === '') {
        return [
            'ok' => true,
            'used' => false,
            'value' => '',
            'display' => '',
            'host' => '',
            'port' => 0,
        ];
    }

    if (!preg_match(
        '~^(?:(https?)://)?([^/:?#\s]+):([0-9]{1,5})$~i',
        $input,
        $m
    )) {
        return [
            'ok' => false,
            'used' => true,
            'value' => '',
            'error' =>
                'Proxy形式は host:port、http://host:port、https://host:port で指定してください。'
        ];
    }

    $scheme = strtolower($m[1] ?: 'http');
    $host = strtolower(trim($m[2]));
    $port = (int)$m[3];

    if ($host === '') {
        return [
            'ok' => false,
            'used' => true,
            'value' => '',
            'error' => 'Proxyホスト名が空です。'
        ];
    }

    if ($port < 1 || $port > 65535) {
        return [
            'ok' => false,
            'used' => true,
            'value' => '',
            'error' => 'Proxyポート番号が不正です。'
        ];
    }

    /*
     * 重要:
     * http.proxy に http://host:port を渡さない。
     * PHP stream wrapperでは tcp://host:port を使用する。
     */
    $streamProxy =
        'tcp://' . $host . ':' . $port;

    return [
        'ok' => true,
        'used' => true,
        'value' => $streamProxy,
        'display' =>
            $scheme . '://' . $host . ':' . $port,
        'host' => $host,
        'port' => $port,
    ];
}

/* =========================================================
 * HTTPレスポンスヘッダー
 *
 * PHP 8.4/8.5:
 * http_get_last_response_headers()
 *
 * fallbackでは $http_response_header をローカル変数として
 * 宣言しない。
 * ========================================================= */

function survey_last_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        try {
            $headers =
                http_get_last_response_headers();

            return is_array($headers)
                ? $headers
                : [];
        } catch (Throwable) {
            return [];
        }
    }

    /*
     * PHP 8.3以前等のfallback。
     *
     * 「global $http_response_header」は使用しない。
     * $GLOBALS経由にして、PHP 8.5のローカル変数Deprecatedを
     * 発生させない。
     */
    $fallback =
        $GLOBALS['http_response_header'] ?? null;

    return is_array($fallback)
        ? $fallback
        : [];
}

function survey_status_from_headers(
    array $headers
): int {
    $status = 0;

    foreach ($headers as $header) {
        if (preg_match(
            '~^HTTP/\S+\s+([0-9]{3})~i',
            (string)$header,
            $m
        )) {
            $status = (int)$m[1];
        }
    }

    return $status;
}

/* =========================================================
 * HTTP通信
 * ========================================================= */

function survey_http_request(
    string $url,
    string $method,
    array $headers,
    ?string $content,
    bool $sslVerify,
    string $proxy
): array {
    $proxyInfo =
        survey_parse_proxy($proxy);

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

    /*
     * PHPにHTTP/HTTPS wrapperがなければ、
     * file_get_contents()以前に明示的に診断する。
     */
    $wrappers = stream_get_wrappers();

    if (
        !in_array('http', $wrappers, true) ||
        !in_array('https', $wrappers, true)
    ) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' =>
                'PHPのHTTP/HTTPS stream wrapperが利用できません。'
                . ' PHP設定とOpenSSLを確認してください。',
            'url' => $url,
            'proxy_used' => $proxyInfo['used'],
        ];
    }

    $parsed = @parse_url($url);

    $peerName =
        is_array($parsed)
        ? (string)($parsed['host'] ?? '')
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

    /*
     * HTTP設定。
     */
    $http = [
        'method' =>
            strtoupper($method),

        'timeout' => 30,

        'ignore_errors' => true,

        'protocol_version' => 1.1,

        'header' =>
            implode("\r\n", $headers),
    ];

    /*
     * POST / PUT等の場合のみcontent。
     */
    if ($content !== null) {
        $http['content'] = $content;
    }

    /*
     * Proxy空欄なら、
     * proxy / request_fulluriを一切追加しない。
     */
    if ($proxyInfo['used']) {
        $http['proxy'] =
            $proxyInfo['value'];

        $http['request_fulluri'] = true;
    }

    $context =
        stream_context_create([
            'http' => $http,

            'ssl' => [
                'verify_peer' =>
                    $sslVerify,

                'verify_peer_name' =>
                    $sslVerify,

                'allow_self_signed' =>
                    !$sslVerify,

                'SNI_enabled' => true,

                'peer_name' =>
                    $peerName,

                'capture_peer_cert' =>
                    false,
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
        /*
         * curlは使用しない。
         */
        $body =
            file_get_contents(
                $url,
                false,
                $context
            );
    } catch (Throwable $e) {
        $body = false;

        if ($warning === '') {
            $warning = $e->getMessage();
        }
    }

    restore_error_handler();

    /*
     * file_get_contents()直後にレスポンスヘッダーを取得。
     */
    $responseHeaders =
        survey_last_headers();

    $status =
        survey_status_from_headers(
            $responseHeaders
        );

    $bodyText =
        is_string($body)
        ? $body
        : '';

    $json =
        json_decode(
            $bodyText,
            true
        );

    /*
     * HTTP 0は認証エラーではない。
     */
    if ($status === 0) {
        $diagnostic =
            $warning !== ''
            ? $warning
            : 'HTTPレスポンスを取得できませんでした。';

        $diagnostic .=
            "\n確認事項: DNS名前解決、PHPサーバーからの外部HTTPS通信、"
            . "Proxy、Proxy形式、ファイアウォール、SSL/TLS、OpenSSL、タイムアウト。";

        if ($proxyInfo['used']) {
            $diagnostic .=
                "\nProxy: 使用";
            $diagnostic .=
                "\nProxy接続失敗の可能性があります。";
        } else {
            $diagnostic .=
                "\nProxy: 未使用";
        }

        return [
            'status' => 0,
            'body' => $bodyText,
            'json' => $json,
            'error' => $diagnostic,
            'url' => $url,
            'proxy_used' =>
                $proxyInfo['used'],
        ];
    }

    return [
        'status' => $status,
        'body' => $bodyText,
        'json' => $json,
        'error' => $warning,
        'url' => $url,
        'proxy_used' =>
            $proxyInfo['used'],
    ];
}

/* =========================================================
 * kintone API
 * ========================================================= */

function survey_kintone_request(
    array $settings
): array {
    $normalized =
        survey_normalize_kintone_base(
            (string)(
                $settings['subdomain'] ?? ''
            )
        );

    if (!$normalized['ok']) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' =>
                $normalized['error'],
            'url' => '',
            'proxy_used' => false,
        ];
    }

    $appId =
        trim(
            (string)(
                $settings['app_id'] ?? ''
            )
        );

    if (
        $appId === '' ||
        !preg_match(
            '/^[0-9]+$/',
            $appId
        )
    ) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' =>
                'アプリIDは数字で入力してください。',
            'url' => '',
            'proxy_used' => false,
        ];
    }

    /*
     * 接続確認も項目取得も必ずこれ。
     *
     * GET /k/v1/app/form/fields.json?app={app_id}
     */
    $url =
        $normalized['base']
        . '/k/v1/app/form/fields.json'
        . '?app='
        . rawurlencode($appId);

    $login =
        (string)(
            $settings['login_name'] ?? ''
        );

    $password =
        (string)(
            $settings['password'] ?? ''
        );

    /*
     * APIトークンは使用しない。
     */
    $authorization =
        base64_encode(
            $login . ':' . $password
        );

    $headers = [
        'X-Cybozu-Authorization: '
            . $authorization,

        'Accept: application/json',

        'Connection: close',
    ];

    return survey_http_request(
        $url,
        'GET',
        $headers,
        null,
        (bool)(
            $settings['ssl_verify']
            ?? true
        ),
        (string)(
            $settings['proxy'] ?? ''
        )
    );
}

function survey_kintone_message(
    array $r
): string {
    $status =
        (int)($r['status'] ?? 0);

    $url =
        (string)($r['url'] ?? '');

    $error =
        trim(
            (string)($r['error'] ?? '')
        );

    $proxy =
        !empty($r['proxy_used'])
        ? '使用'
        : '未使用';

    if ($status === 0) {
        return
            "kintoneからHTTPレスポンスを取得できませんでした。\n"
            . "HTTPステータス: 0\n"
            . "接続先: {$url}\n"
            . "Proxy: {$proxy}\n"
            . "PHP通信エラー: "
            . (
                $error !== ''
                ? $error
                : 'なし'
            )
            . "\n確認事項: DNS、PHPサーバーからの外部HTTPS通信、"
            . "Proxy、ファイアウォール、SSL/TLS、OpenSSL、タイムアウト。";
    }

    if (
        $status === 401 ||
        $status === 403
    ) {
        return
            "kintone認証または権限エラーです。\n"
            . "HTTPステータス: {$status}\n"
            . "接続先: {$url}\n"
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
            "kintone側のレート制限です。\n"
            . "HTTPステータス: 429";
    }

    if ($status >= 500) {
        return
            "kintoneまたはProxy側のサーバーエラーです。\n"
            . "HTTPステータス: {$status}";
    }

    $body =
        trim(
            (string)($r['body'] ?? '')
        );

    if ($body !== '') {
        return
            "kintone APIから応答がありました。\n"
            . "HTTPステータス: {$status}\n"
            . "本文: {$body}";
    }

    return
        "kintone通信結果。\n"
        . "HTTPステータス: {$status}";
}

/* =========================================================
 * アンケート番号
 *
 * global:
 *   Q1
 *   Q2
 *   Q3
 *
 * group:
 *   Q1-1
 *   Q1-2
 *   Q2-1
 *
 * グループ番号はgroups配列順。
 * 質問番号は各questions配列順。
 * ========================================================= */

function survey_reindex(array &$survey): void
{
    $mode =
        ($survey['numbering_mode'] ?? 'global')
        === 'group'
        ? 'group'
        : 'global';

    $global = 0;

    foreach (
        ($survey['groups'] ?? []) as $gi => &$group
    ) {
        $groupNo = $gi + 1;
        $questionNo = 0;

        foreach (
            ($group['questions'] ?? []) as &$question
        ) {
            $global++;
            $questionNo++;

            $question['number'] =
                $mode === 'group'
                ? 'Q' . $groupNo . '-' . $questionNo
                : 'Q' . $global;
        }

        unset($question);

        $group['id'] =
            (string)(
                $group['id'] ?? survey_id()
            );

        $group['name'] =
            (string)(
                $group['name']
                ?? 'グループ ' . $groupNo
            );
    }

    unset($group);
}

/* =========================================================
 * API
 * ========================================================= */

$action =
    (string)(
        $_POST['action']
        ?? $_GET['action']
        ?? ''
    );

if ($action !== '') {
    $data =
        survey_read_data();

    /*
     * CSRFが必要な管理系POST。
     */
    $writeActions = [
        'save_survey',
        'delete_survey',
        'duplicate_survey',
        'toggle_status',
        'save_settings',
        'kintone_fields',
        'send_mail',
        'register_customer',
    ];

    if (
        in_array(
            $action,
            $writeActions,
            true
        ) &&
        $_SERVER['REQUEST_METHOD'] === 'POST'
    ) {
        if (!survey_check_token()) {
            survey_api([
                'ok' => false,
                'message' =>
                    'CSRFトークンが不正です。'
            ], 419);
        }
    }

    /* -----------------------------------------------
     * 初期データ
     * --------------------------------------------- */

    if ($action === 'bootstrap') {
        survey_api([
            'ok' => true,
            'data' =>
                survey_public_data($data),
            'csrf_token' =>
                survey_token(),
        ]);
    }

    /* -----------------------------------------------
     * 保存
     * --------------------------------------------- */

    if ($action === 'save_survey') {
        $raw =
            (string)(
                $_POST['survey_json']
                ?? ''
            );

        $survey =
            json_decode(
                $raw,
                true
            );

        if (!is_array($survey)) {
            survey_api([
                'ok' => false,
                'message' =>
                    'アンケートデータが不正です。'
            ], 400);
        }

        $id =
            (string)(
                $survey['id']
                ?? survey_id()
            );

        $existingIndex = null;

        foreach (
            $data['surveys'] as $i => $s
        ) {
            if (
                (string)($s['id'] ?? '')
                === $id
            ) {
                $existingIndex = $i;
                break;
            }
        }

        $old =
            $existingIndex !== null
            ? $data['surveys'][$existingIndex]
            : null;

        $survey['id'] = $id;

        $survey['title'] =
            trim(
                (string)(
                    $survey['title'] ?? ''
                )
            );

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

        $survey['created_at'] =
            (string)(
                $old['created_at']
                ?? survey_now()
            );

        $survey['updated_at'] =
            survey_now();

        $survey['deleted'] =
            false;

        if (!isset($survey['groups']) ||
            !is_array($survey['groups'])) {
            $survey['groups'] = [];
        }

        survey_reindex($survey);

        if ($existingIndex === null) {
            $data['surveys'][] = $survey;
        } else {
            $data['surveys'][$existingIndex] =
                $survey;
        }

        if (!survey_write_data($data)) {
            survey_api([
                'ok' => false,
                'message' =>
                    'JSONファイルへ保存できませんでした。'
            ], 500);
        }

        survey_api([
            'ok' => true,
            'survey' => $survey,
            'data' =>
                survey_public_data($data),
        ]);
    }

    /* -----------------------------------------------
     * 削除
     * --------------------------------------------- */

    if ($action === 'delete_survey') {
        $id =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        foreach (
            $data['surveys'] as &$survey
        ) {
            if (
                (string)($survey['id'] ?? '')
                === $id
            ) {
                $survey['deleted'] = true;
                $survey['updated_at'] =
                    survey_now();
            }
        }

        unset($survey);

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'data' =>
                survey_public_data($data),
        ]);
    }

    /* -----------------------------------------------
     * 複製
     * --------------------------------------------- */

    if ($action === 'duplicate_survey') {
        $id =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        $found = null;

        foreach (
            $data['surveys'] as $survey
        ) {
            if (
                (string)($survey['id'] ?? '')
                === $id
            ) {
                $found = $survey;
                break;
            }
        }

        if (!is_array($found)) {
            survey_api([
                'ok' => false,
                'message' =>
                    '複製元アンケートがありません。'
            ], 404);
        }

        $found['id'] =
            survey_id();

        $found['title'] =
            (string)$found['title']
            . '（コピー）';

        $found['status'] =
            'draft';

        $found['deleted'] =
            false;

        $found['created_at'] =
            survey_now();

        $found['updated_at'] =
            survey_now();

        foreach (
            ($found['groups'] ?? []) as &$g
        ) {
            $g['id'] =
                survey_id();

            foreach (
                ($g['questions'] ?? []) as &$q
            ) {
                $q['id'] =
                    survey_id();
            }

            unset($q);
        }

        unset($g);

        survey_reindex($found);

        $data['surveys'][] =
            $found;

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'survey' => $found,
            'data' =>
                survey_public_data($data),
        ]);
    }

    /* -----------------------------------------------
     * ステータス
     * --------------------------------------------- */

    if ($action === 'toggle_status') {
        $id =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        foreach (
            $data['surveys'] as &$survey
        ) {
            if (
                (string)($survey['id'] ?? '')
                === $id
            ) {
                $survey['status'] =
                    ($survey['status'] ?? '')
                    === 'active'
                    ? 'ended'
                    : 'active';

                $survey['updated_at'] =
                    survey_now();
            }
        }

        unset($survey);

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'data' =>
                survey_public_data($data),
        ]);
    }

    /* -----------------------------------------------
     * kintone項目取得
     * --------------------------------------------- */

    if ($action === 'kintone_fields') {
        $settingsRaw =
            (string)(
                $_POST['settings_json']
                ?? ''
            );

        $settings =
            json_decode(
                $settingsRaw,
                true
            );

        if (!is_array($settings)) {
            $settings =
                $data['settings'];
        }

        /*
         * パスワードはPOSTされたものが空なら
         * 保存済みパスワードを使用。
         */
        if (
            trim(
                (string)(
                    $settings['password']
                    ?? ''
                )
            ) === ''
        ) {
            $settings['password'] =
                (string)(
                    $data['settings']['password']
                    ?? ''
                );
        }

        $r =
            survey_kintone_request(
                $settings
            );

        if (
            (int)$r['status'] !== 200
        ) {
            survey_api([
                'ok' => false,
                'status' =>
                    $r['status'],
                'message' =>
                    survey_kintone_message($r),
                'error' =>
                    $r['error'],
                'url' =>
                    $r['url'],
                'proxy_used' =>
                    $r['proxy_used'],
            ]);
        }

        $json =
            is_array($r['json'])
            ? $r['json']
            : [];

        $properties =
            $json['properties']
            ?? null;

        if (!is_array($properties)) {
            survey_api([
                'ok' => false,
                'status' =>
                    $r['status'],
                'message' =>
                    'kintoneレスポンスにpropertiesがありません。',
                'body' =>
                    survey_h($r['body']),
            ]);
        }

        $fields = [];

        foreach (
            $properties as $code => $field
        ) {
            if (!is_array($field)) {
                continue;
            }

            $fields[] = [
                'code' =>
                    (string)$code,

                'label' =>
                    (string)(
                        $field['label']
                        ?? $code
                    ),

                'type' =>
                    (string)(
                        $field['type']
                        ?? ''
                    ),
            ];
        }

        usort(
            $fields,
            static fn(array $a, array $b): int =>
                strcmp(
                    $a['label'],
                    $b['label']
                )
        );

        survey_api([
            'ok' => true,
            'status' =>
                $r['status'],
            'fields' => $fields,
        ]);
    }

    /* -----------------------------------------------
     * 設定保存
     * --------------------------------------------- */

    if ($action === 'save_settings') {
        $raw =
            (string)(
                $_POST['settings_json']
                ?? ''
            );

        $settings =
            json_decode(
                $raw,
                true
            );

        if (!is_array($settings)) {
            survey_api([
                'ok' => false,
                'message' =>
                    '設定データが不正です。'
            ], 400);
        }

        $oldPassword =
            (string)(
                $data['settings']['password']
                ?? ''
            );

        $newPassword =
            (string)(
                $settings['password']
                ?? ''
            );

        if ($newPassword === '') {
            $settings['password'] =
                $oldPassword;
        }

        $settings['subdomain'] =
            trim(
                (string)(
                    $settings['subdomain']
                    ?? ''
                )
            );

        $settings['app_id'] =
            trim(
                (string)(
                    $settings['app_id']
                    ?? ''
                )
            );

        $settings['proxy'] =
            trim(
                (string)(
                    $settings['proxy']
                    ?? ''
                )
            );

        $settings['ssl_verify'] =
            !empty(
                $settings['ssl_verify']
            );

        if (!is_array(
            $settings['field_address']
            ?? null
        )) {
            $settings['field_address'] =
                [];
        }

        $data['settings'] =
            array_replace(
                $data['settings'],
                $settings
            );

        if (!survey_write_data($data)) {
            survey_api([
                'ok' => false,
                'message' =>
                    '設定を保存できませんでした。'
            ], 500);
        }

        survey_api([
            'ok' => true,
            'data' =>
                survey_public_data($data),
        ]);
    }

    /* -----------------------------------------------
     * CSV
     * --------------------------------------------- */

    if ($action === 'csv') {
        $surveyId =
            (string)(
                $_GET['survey_id']
                ?? $_POST['survey_id']
                ?? ''
            );

        $survey = null;

        foreach (
            $data['surveys'] as $s
        ) {
            if (
                (string)($s['id'] ?? '')
                === $surveyId
            ) {
                $survey = $s;
                break;
            }
        }

        if (!is_array($survey)) {
            http_response_code(404);
            exit('Survey not found');
        }

        $questions = [];

        foreach (
            ($survey['groups'] ?? [])
            as $group
        ) {
            foreach (
                ($group['questions'] ?? [])
                as $q
            ) {
                $questions[] =
                    $q;
            }
        }

        $filename =
            'survey_' .
            preg_replace(
                '/[^a-zA-Z0-9_-]/',
                '_',
                $surveyId
            ) .
            '.csv';

        header(
            'Content-Type: text/csv; charset=UTF-8'
        );

        header(
            'Content-Disposition: attachment; filename="' .
            $filename .
            '"'
        );

        echo "\xEF\xBB\xBF";

        $out =
            fopen('php://output', 'wb');

        $headerRow = [
            '回答ID',
            '回答日時',
            '顧客ID',
            '会社名',
            '氏名',
            'メールアドレス',
        ];

        foreach ($questions as $q) {
            $headerRow[] =
                (string)(
                    $q['number']
                    ?? ''
                ) . ' ' .
                (string)(
                    $q['text']
                    ?? ''
                );
        }

        fputcsv(
            $out,
            $headerRow
        );

        foreach (
            $data['responses'] as $response
        ) {
            if (
                (string)(
                    $response['survey_id']
                    ?? ''
                ) !== $surveyId
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

            foreach ($questions as $q) {
                $id =
                    (string)(
                        $q['id'] ?? ''
                    );

                $value =
                    $answers[$id]
                    ?? '';

                if (is_array($value)) {
                    $value =
                        implode(
                            '、',
                            array_map(
                                'strval',
                                $value
                            )
                        );
                }

                $row[] =
                    (string)$value;
            }

            fputcsv(
                $out,
                $row
            );
        }

        fclose($out);
        exit;
    }

    /* -----------------------------------------------
     * 公開回答取得
     * --------------------------------------------- */

    if ($action === 'public_survey') {
        $id =
            (string)(
                $_GET['survey_id']
                ?? ''
            );

        foreach (
            $data['surveys'] as $survey
        ) {
            if (
                (string)($survey['id'] ?? '')
                === $id &&
                empty($survey['deleted'])
            ) {
                survey_api([
                    'ok' => true,
                    'survey' => $survey,
                ]);
            }
        }

        survey_api([
            'ok' => false,
            'message' =>
                'アンケートが見つかりません。'
        ], 404);
    }

    /* -----------------------------------------------
     * 公開回答保存
     * --------------------------------------------- */

    if ($action === 'submit_response') {
        $surveyId =
            (string)(
                $_POST['survey_id']
                ?? ''
            );

        $survey = null;

        foreach (
            $data['surveys'] as $s
        ) {
            if (
                (string)($s['id'] ?? '')
                === $surveyId
            ) {
                $survey = $s;
                break;
            }
        }

        if (!is_array($survey)) {
            survey_api([
                'ok' => false,
                'message' =>
                    'アンケートがありません。'
            ], 404);
        }

        $answers =
            json_decode(
                (string)(
                    $_POST['answers']
                    ?? '{}'
                ),
                true
            );

        if (!is_array($answers)) {
            $answers = [];
        }

        $email =
            trim(
                (string)(
                    $_POST['email']
                    ?? ''
                )
            );

        $name =
            trim(
                (string)(
                    $_POST['name']
                    ?? ''
                )
            );

        $company =
            trim(
                (string)(
                    $_POST['company']
                    ?? ''
                )
            );

        $customerId = '';

        foreach (
            $data['customers'] as &$customer
        ) {
            if (
                $email !== '' &&
                strtolower(
                    (string)(
                        $customer['email']
                        ?? ''
                    )
                ) === strtolower($email)
            ) {
                $customerId =
                    (string)(
                        $customer['id']
                        ?? survey_id()
                    );

                $customer['answer_status'] =
                    'answered';

                break;
            }
        }

        unset($customer);

        if ($customerId === '') {
            $customerId =
                survey_id();

            $data['customers'][] = [
                'id' => $customerId,
                'company' => $company,
                'name' => $name,
                'email' => $email,
                'department' => '',
                'phone' => '',
                'address' => '',
                'source' => 'web',
                'sent_at' => '',
                'send_count' => 0,
                'answer_status' => 'answered',
                'kintone_status' =>
                    'unregistered',
            ];
        }

        $response = [
            'id' => survey_id(),
            'survey_id' => $surveyId,
            'customer_id' => $customerId,
            'company' => $company,
            'name' => $name,
            'email' => $email,
            'answered_at' => survey_now(),
            'answers' => $answers,
        ];

        $data['responses'][] =
            $response;

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'response_id' =>
                $response['id'],
        ]);
    }

    survey_api([
        'ok' => false,
        'message' =>
            '未知のactionです。'
    ], 400);
}

/* =========================================================
 * SPA HTML
 * ========================================================= */

$csrf =
    survey_token();

?><!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-slate-50 text-slate-800">

<div
    id="app"
    class="min-h-screen">
</div>

<script>
window.App = {
    state: {
        data: null,
        csrf: <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>,
        screen: 'list',
        editingSurvey: null,
        previewMode: 'pc',
        responseSurveyId: '',
        responseFilter: '',
        customerFilter: '',
        fields: [],
        loading: false
    },

    utils: {},

    api: {},

    render: {},

    actions: {},

    init: function() {
        if (this.state.initialized) return;

        this.state.initialized = true;

        this.api.bootstrap();
    }
};

/* =========================================================
 * Utils
 * ========================================================= */

App.utils.escape = function(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
};

App.utils.uuid = function() {
    if (crypto.randomUUID) {
        return crypto.randomUUID();
    }

    return Date.now().toString(36) +
        Math.random().toString(36).slice(2);
};

App.utils.statusLabel = function(status) {
    return {
        draft: '下書き',
        active: '公開中',
        ended: '終了'
    }[status] || status;
};

App.utils.statusClass = function(status) {
    return {
        draft: 'bg-slate-100 text-slate-600',
        active: 'bg-emerald-100 text-emerald-700',
        ended: 'bg-amber-100 text-amber-700'
    }[status] || 'bg-slate-100';
};

App.utils.questionTypeLabel = function(type) {
    return {
        single: '単一選択',
        multiple: '複数選択',
        text: '自由記述'
    }[type] || type;
};

App.utils.notify = function(message, error = false) {
    const el = document.createElement('div');

    el.className =
        'fixed right-5 top-5 z-[100] max-w-lg rounded-xl px-5 py-4 shadow-xl ' +
        (error
            ? 'bg-red-600 text-white'
            : 'bg-slate-900 text-white');

    el.textContent = message;

    document.body.appendChild(el);

    setTimeout(() => {
        el.remove();
    }, 4000);
};

/* =========================================================
 * API
 * ========================================================= */

App.api.request = async function(action, data = {}) {
    const fd = new FormData();

    fd.append('action', action);

    if (action !== 'bootstrap' &&
        action !== 'public_survey') {
        fd.append(
            'csrf_token',
            App.state.csrf
        );
    }

    Object.entries(data).forEach(([key, value]) => {
        if (value === undefined || value === null) {
            return;
        }

        fd.append(
            key,
            typeof value === 'string'
                ? value
                : JSON.stringify(value)
        );
    });

    const response =
        await fetch(location.pathname, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        });

    const json =
        await response.json();

    if (!json.ok) {
        throw new Error(
            json.message ||
            '処理に失敗しました。'
        );
    }

    return json;
};

App.api.bootstrap = async function() {
    try {
        const result =
            await App.api.request(
                'bootstrap'
            );

        App.state.data =
            result.data;

        App.state.csrf =
            result.csrf_token;

        App.render.list();
    } catch (e) {
        App.utils.notify(
            e.message,
            true
        );
    }
};

App.api.saveSurvey = async function(survey) {
    const result =
        await App.api.request(
            'save_survey',
            {
                survey_json:
                    JSON.stringify(survey)
            }
        );

    App.state.data =
        result.data;

    return result.survey;
};

App.api.deleteSurvey = async function(id) {
    const result =
        await App.api.request(
            'delete_survey',
            {
                survey_id: id
            }
        );

    App.state.data =
        result.data;
};

App.api.duplicateSurvey = async function(id) {
    const result =
        await App.api.request(
            'duplicate_survey',
            {
                survey_id: id
            }
        );

    App.state.data =
        result.data;
};

App.api.toggleStatus = async function(id) {
    const result =
        await App.api.request(
            'toggle_status',
            {
                survey_id: id
            }
        );

    App.state.data =
        result.data;
};

App.api.fetchKintoneFields = async function(settings) {
    const result =
        await App.api.request(
            'kintone_fields',
            {
                settings_json:
                    JSON.stringify(settings)
            }
        );

    App.state.fields =
        result.fields || [];

    return App.state.fields;
};

App.api.saveSettings = async function(settings) {
    const result =
        await App.api.request(
            'save_settings',
            {
                settings_json:
                    JSON.stringify(settings)
            }
        );

    App.state.data =
        result.data;
};

App.api.submitResponse = async function(
    surveyId,
    form
) {
    const fd =
        new FormData(form);

    fd.append(
        'action',
        'submit_response'
    );

    fd.append(
        'csrf_token',
        App.state.csrf
    );

    fd.append(
        'survey_id',
        surveyId
    );

    const response =
        await fetch(
            location.pathname,
            {
                method: 'POST',
                body: fd
            }
        );

    return response.json();
};

/* =========================================================
 * Survey helpers
 * ========================================================= */

App.actions.newSurvey = function() {
    App.state.editingSurvey = {
        id: App.utils.uuid(),
        title: '新しいアンケート',
        start_at: '',
        end_at: '',
        status: 'draft',
        created_at: '',
        updated_at: '',
        numbering_mode: 'global',
        groups: [],
        deleted: false
    };

    App.actions.addGroup();
    App.render.editor();
};

App.actions.editSurvey = function(id) {
    const found =
        App.state.data.surveys.find(
            s => s.id === id &&
                 !s.deleted
        );

    if (!found) return;

    App.state.editingSurvey =
        structuredClone(found);

    App.render.editor();
};

App.actions.addGroup = function() {
    const survey =
        App.state.editingSurvey;

    if (!survey) return;

    survey.groups.push({
        id: App.utils.uuid(),
        name:
            'グループ ' +
            (survey.groups.length + 1),
        questions: []
    });

    App.actions.reindex();
    App.render.editor();
};

App.actions.removeGroup = function(index) {
    if (!confirm(
        'このグループと内包する質問を削除しますか？'
    )) {
        return;
    }

    App.state.editingSurvey.groups
        .splice(index, 1);

    App.actions.reindex();
    App.render.editor();
};

App.actions.addQuestion = function(groupIndex) {
    const group =
        App.state.editingSurvey.groups[
            groupIndex
        ];

    if (!group) return;

    group.questions.push({
        id: App.utils.uuid(),
        number: '',
        text: '新しい質問',
        type: 'single',
        required: false,
        options: [
            '選択肢1',
            '選択肢2'
        ],
        other_enabled: false
    });

    App.actions.reindex();
    App.render.editor();
};

App.actions.removeQuestion = function(
    groupIndex,
    questionIndex
) {
    App.state.editingSurvey
        .groups[groupIndex]
        .questions
        .splice(questionIndex, 1);

    App.actions.reindex();
    App.render.editor();
};

App.actions.reindex = function() {
    const survey =
        App.state.editingSurvey;

    if (!survey) return;

    let globalNo = 0;

    survey.groups.forEach(
        (group, gi) => {
            group.questions.forEach(
                (question, qi) => {
                    globalNo++;

                    question.number =
                        survey.numbering_mode ===
                        'group'
                        ? `Q${gi + 1}-${qi + 1}`
                        : `Q${globalNo}`;
                }
            );
        }
    );
};

App.actions.setNumberingMode = function(value) {
    App.state.editingSurvey
        .numbering_mode =
        value === 'group'
            ? 'group'
            : 'global';

    App.actions.reindex();
    App.render.editor();
};

App.actions.updateSurveyTitle = function(value) {
    App.state.editingSurvey.title =
        value;
};

App.actions.updateGroupName = function(
    index,
    value
) {
    App.state.editingSurvey
        .groups[index]
        .name = value;
};

App.actions.updateQuestion = function(
    gi,
    qi,
    key,
    value
) {
    const q =
        App.state.editingSurvey
            .groups[gi]
            .questions[qi];

    if (key === 'required') {
        q.required =
            value === true ||
            value === 'true';
    } else {
        q[key] = value;
    }
};

App.actions.updateOption = function(
    gi,
    qi,
    oi,
    value
) {
    App.state.editingSurvey
        .groups[gi]
        .questions[qi]
        .options[oi] = value;
};

App.actions.addOption = function(
    gi,
    qi
) {
    App.state.editingSurvey
        .groups[gi]
        .questions[qi]
        .options.push(
            '新しい選択肢'
        );

    App.render.editor();
};

App.actions.removeOption = function(
    gi,
    qi,
    oi
) {
    App.state.editingSurvey
        .groups[gi]
        .questions[qi]
        .options.splice(oi, 1);

    App.render.editor();
};

App.actions.saveSurvey = async function() {
    try {
        App.actions.reindex();

        await App.api.saveSurvey(
            App.state.editingSurvey
        );

        App.utils.notify(
            '保存しました。'
        );

        App.state.editingSurvey =
            null;

        App.render.list();
    } catch (e) {
        App.utils.notify(
            e.message,
            true
        );
    }
};

App.actions.cancelEditor = function() {
    if (!confirm(
        '変更を破棄して一覧へ戻りますか？'
    )) {
        return;
    }

    App.state.editingSurvey =
        null;

    App.render.list();
};

App.actions.preview = function() {
    App.actions.reindex();

    const survey =
        App.state.editingSurvey;

    let html = '';

    survey.groups.forEach(group => {
        html += `
            <section class="mb-8">
                <h3 class="mb-4 text-xl font-bold">
                    ${App.utils.escape(group.name)}
                </h3>
        `;

        group.questions.forEach(q => {
            html += `
                <div class="mb-6 rounded-xl border bg-white p-5">
                    <div class="mb-3 font-semibold">
                        ${App.utils.escape(q.number)}
                        ${App.utils.escape(q.text)}
                        ${q.required
                            ? '<span class="ml-2 text-red-500">必須</span>'
                            : ''}
                    </div>
            `;

            if (q.type === 'text') {
                html += `
                    <textarea
                        class="w-full rounded-lg border p-3"
                        rows="4"
                        disabled></textarea>
                `;
            } else {
                q.options.forEach(option => {
                    const type =
                        q.type === 'single'
                        ? 'radio'
                        : 'checkbox';

                    html += `
                        <label class="mb-2 flex items-center gap-2">
                            <input
                                type="${type}"
                                disabled>
                            <span>
                                ${App.utils.escape(option)}
                            </span>
                        </label>
                    `;
                });

                if (q.other_enabled) {
                    html += `
                        <input
                            class="mt-2 w-full rounded-lg border p-3"
                            placeholder="その他"
                            disabled>
                    `;
                }
            }

            html += '</div>';
        });

        html += '</section>';
    });

    document.getElementById(
        'preview_content'
    ).innerHTML = html;

    document.getElementById(
        'preview_modal'
    ).classList.remove('hidden');
};

App.actions.closePreview = function() {
    document.getElementById(
        'preview_modal'
    ).classList.add('hidden');
};

/* =========================================================
 * SortableJS
 * ========================================================= */

App.actions.initSortable = function() {
    const groupList =
        document.getElementById(
            'question_editor'
        );

    if (!groupList ||
        typeof Sortable === 'undefined') {
        return;
    }

    new Sortable(
        groupList,
        {
            animation: 180,
            handle: '.group-handle',
            ghostClass: 'opacity-40',
            onEnd: function(evt) {
                const groups =
                    App.state.editingSurvey.groups;

                const moved =
                    groups.splice(
                        evt.oldIndex,
                        1
                    )[0];

                groups.splice(
                    evt.newIndex,
                    0,
                    moved
                );

                App.actions.reindex();
                App.render.editor();
            }
        }
    );

    document
        .querySelectorAll(
            '.question-list'
        )
        .forEach((element, gi) => {
            new Sortable(
                element,
                {
                    group: 'surveyQuestions',
                    animation: 180,
                    handle: '.question-handle',
                    ghostClass: 'opacity-40',

                    onEnd: function(evt) {
                        const survey =
                            App.state.editingSurvey;

                        const source =
                            survey.groups[
                                evt.from.dataset.group
                            ];

                        const target =
                            survey.groups[
                                evt.to.dataset.group
                            ];

                        const moved =
                            source.questions
                                .splice(
                                    evt.oldIndex,
                                    1
                                )[0];

                        target.questions.splice(
                            evt.newIndex,
                            0,
                            moved
                        );

                        App.actions.reindex();
                        App.render.editor();
                    }
                }
            );
        });
};

/* =========================================================
 * List
 * ========================================================= */

App.actions.search = function() {
    App.render.list();
};

App.actions.filterStatus = function() {
    App.render.list();
};

App.actions.sortList = function() {
    App.render.list();
};

App.actions.stopSurvey = async function(id) {
    if (!confirm(
        'このアンケートを停止しますか？'
    )) {
        return;
    }

    try {
        await App.api.toggleStatus(id);

        App.utils.notify(
            'ステータスを変更しました。'
        );

        App.render.list();
    } catch (e) {
        App.utils.notify(
            e.message,
            true
        );
    }
};

App.actions.deleteSurvey = async function(id) {
    if (!confirm(
        'この下書きを削除しますか？'
    )) {
        return;
    }

    try {
        await App.api.deleteSurvey(id);

        App.utils.notify(
            '削除しました。'
        );

        App.render.list();
    } catch (e) {
        App.utils.notify(
            e.message,
            true
        );
    }
};

App.actions.duplicateSurvey = async function(id) {
    try {
        await App.api.duplicateSurvey(id);

        App.utils.notify(
            '下書きを複製しました。'
        );

        App.render.list();
    } catch (e) {
        App.utils.notify(
            e.message,
            true
        );
    }
};

/* =========================================================
 * 集計
 * ========================================================= */

App.actions.openAnalysis = function(id) {
    App.state.responseSurveyId =
        id;

    App.render.analysis();
};

App.actions.filterResponses = function(value) {
    App.state.responseFilter =
        value;

    App.render.analysis();
};

App.actions.showResponse = function(id) {
    const response =
        App.state.data.responses.find(
            r => r.id === id
        );

    if (!response) return;

    const survey =
        App.state.data.surveys.find(
            s =>
                s.id === response.survey_id
        );

    if (!survey) return;

    const questions = [];

    survey.groups.forEach(group => {
        group.questions.forEach(q => {
            questions.push(q);
        });
    });

    let html = `
        <div class="space-y-4">
            <div>
                <div class="text-sm text-slate-500">
                    回答日時
                </div>
                <div>
                    ${App.utils.escape(response.answered_at)}
                </div>
            </div>
            <div>
                <div class="text-sm text-slate-500">
                    会社名 / 氏名
                </div>
                <div class="font-semibold">
                    ${App.utils.escape(response.company)}
                    /
                    ${App.utils.escape(response.name)}
                </div>
            </div>
    `;

    questions.forEach(q => {
        let value =
            response.answers?.[q.id] ?? '';

        if (Array.isArray(value)) {
            value =
                value.join('、');
        }

        html += `
            <div class="border-t pt-4">
                <div class="font-semibold">
                    ${App.utils.escape(q.number)}
                    ${App.utils.escape(q.text)}
                </div>
                <div class="mt-2 whitespace-pre-wrap text-slate-600">
                    ${App.utils.escape(value)}
                </div>
            </div>
        `;
    });

    html += '</div>';

    document.getElementById(
        'response_detail'
    ).innerHTML = html;

    document.getElementById(
        'response_modal'
    ).classList.remove('hidden');
};

App.actions.closeResponse = function() {
    document.getElementById(
        'response_modal'
    ).classList.add('hidden');
};

/* =========================================================
 * kintone設定
 * ========================================================= */

App.actions.fetchKintoneFields = async function() {
    const settings =
        App.actions.readSettingsForm();

    const message =
        document.getElementById(
            'field_message'
        );

    message.textContent =
        '取得中...';

    try {
        const fields =
            await App.api.fetchKintoneFields(
                settings
            );

        message.textContent =
            `${fields.length}項目取得しました。`;

        App.render.settings(
            settings
        );
    } catch (e) {
        message.textContent =
            e.message;

        App.utils.notify(
            e.message,
            true
        );
    }
};

App.actions.readSettingsForm = function() {
    const old =
        App.state.data.settings || {};

    const get =
        id =>
            document.getElementById(id)?.value
            ?? '';

    const addresses =
        Array.from(
            document.querySelectorAll(
                '[data-address-field]'
            )
        ).map(
            el => el.value
        );

    return {
        subdomain:
            get('setting_subdomain'),

        app_id:
            get('setting_app_id'),

        login_name:
            get('setting_login_name'),

        /*
         * 空欄ならサーバー側で既存値を維持。
         */
        password:
            get('setting_password'),

        proxy:
            get('setting_proxy'),

        ssl_verify:
            document.getElementById(
                'setting_ssl_verify'
            )?.checked ?? true,

        field_company:
            document.getElementById(
                'field_company'
            )?.value ?? old.field_company ?? '',

        field_name:
            document.getElementById(
                'field_name'
            )?.value ?? old.field_name ?? '',

        field_email:
            document.getElementById(
                'field_email'
            )?.value ?? old.field_email ?? '',

        field_department:
            document.getElementById(
                'field_department'
            )?.value ?? old.field_department ?? '',

        field_phone:
            document.getElementById(
                'field_phone'
            )?.value ?? old.field_phone ?? '',

        field_address:
            addresses.length
            ? addresses
            : (
                Array.isArray(
                    old.field_address
                )
                ? old.field_address
                : []
            )
    };
};

App.actions.saveSettings = async function() {
    const settings =
        App.actions.readSettingsForm();

    try {
        await App.api.saveSettings(
            settings
        );

        App.utils.notify(
            'kintone連携設定を保存しました。'
        );

        App.render.list();
    } catch (e) {
        App.utils.notify(
            e.message,
            true
        );
    }
};

/* =========================================================
 * 送信
 * ========================================================= */

App.actions.openMail = function(id) {
    App.state.mailSurveyId =
        id;

    App.render.mail();
};

App.actions.sendMail = function() {
    App.utils.notify(
        'メール送信機能はメールサーバー設定後に実送信へ接続してください。'
    );
};

/* =========================================================
 * Render: Layout
 * ========================================================= */

App.render.shell = function(content) {
    document.getElementById(
        'app'
    ).innerHTML = `
        <header class="sticky top-0 z-40 border-b bg-white/95 backdrop-blur">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                <button
                    onclick="App.render.list()"
                    class="text-lg font-bold text-slate-900">
                    アンケート管理
                </button>

                <nav class="flex gap-2">
                    <button
                        onclick="App.render.list()"
                        class="rounded-lg px-4 py-2 text-sm hover:bg-slate-100">
                        アンケート一覧
                    </button>

                    <button
                        onclick="App.render.settings()"
                        class="rounded-lg px-4 py-2 text-sm hover:bg-slate-100">
                        キントーン連携設定
                    </button>

                    <button
                        onclick="location.reload()"
                        class="rounded-lg px-4 py-2 text-sm hover:bg-slate-100">
                        ログアウト
                    </button>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-6 py-8">
            ${content}
        </main>
    `;
};

/* =========================================================
 * Render: List
 * ========================================================= */

App.render.list = function() {
    if (!App.state.data) {
        document.getElementById(
            'app'
        ).innerHTML =
            '<div class="p-10">読み込み中...</div>';
        return;
    }

    App.state.screen =
        'list';

    let surveys =
        App.state.data.surveys
            .filter(s => !s.deleted);

    const keyword =
        document.getElementById(
            'survey_keyword'
        )?.value ?? '';

    const status =
        document.getElementById(
            'survey_status'
        )?.value ?? '';

    if (keyword) {
        surveys =
            surveys.filter(
                s =>
                    String(s.title)
                        .toLowerCase()
                        .includes(
                            keyword.toLowerCase()
                        )
            );
    }

    if (status) {
        surveys =
            surveys.filter(
                s =>
                    s.status === status
            );
    }

    const sort =
        document.getElementById(
            'survey_sort'
        )?.value ?? 'updated_desc';

    surveys.sort((a, b) => {
        if (sort === 'updated_desc') {
            return String(b.updated_at)
                .localeCompare(
                    String(a.updated_at)
                );
        }

        if (sort === 'updated_asc') {
            return String(a.updated_at)
                .localeCompare(
                    String(b.updated_at)
                );
        }

        const countA =
            App.state.data.responses
                .filter(
                    r =>
                        r.survey_id === a.id
                ).length;

        const countB =
            App.state.data.responses
                .filter(
                    r =>
                        r.survey_id === b.id
                ).length;

        return sort === 'responses_desc'
            ? countB - countA
            : countA - countB;
    });

    let rows = '';

    surveys.forEach(s => {
        const responseCount =
            App.state.data.responses
                .filter(
                    r =>
                        r.survey_id === s.id
                ).length;

        let actions = `
            <button
                onclick="App.actions.editSurvey('${s.id}')"
                class="rounded-lg bg-slate-900 px-3 py-2 text-xs text-white">
                確認・編集
            </button>
        `;

        if (s.status === 'active') {
            actions += `
                <button
                    onclick="App.actions.openAnalysis('${s.id}')"
                    class="rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-700">
                    集計
                </button>

                <button
                    onclick="App.actions.openMail('${s.id}')"
                    class="rounded-lg bg-indigo-50 px-3 py-2 text-xs text-indigo-700">
                    送信
                </button>

                <button
                    onclick="App.actions.stopSurvey('${s.id}')"
                    class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">
                    停止
                </button>
            `;
        }

        if (s.status === 'draft') {
            actions += `
                <button
                    onclick="App.actions.deleteSurvey('${s.id}')"
                    class="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">
                    削除
                </button>
            `;
        }

        if (s.status === 'ended') {
            actions += `
                <button
                    onclick="App.actions.openAnalysis('${s.id}')"
                    class="rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-700">
                    集計
                </button>
            `;
        }

        actions += `
            <button
                onclick="App.actions.duplicateSurvey('${s.id}')"
                class="rounded-lg border px-3 py-2 text-xs">
                複製
            </button>
        `;

        rows += `
            <tr class="border-t">
                <td class="px-4 py-4">
                    <div>
                        ${App.utils.escape(
                            String(s.created_at || '')
                                .slice(0, 10)
                        )}
                    </div>
                    <div class="text-xs text-slate-400">
                        更新:
                        ${App.utils.escape(
                            String(s.updated_at || '')
                                .slice(0, 10)
                        )}
                    </div>
                </td>

                <td class="px-4 py-4">
                    <div class="font-bold">
                        ${App.utils.escape(s.title)}
                    </div>
                </td>

                <td class="px-4 py-4 text-sm">
                    ${s.start_at
                        ? App.utils.escape(s.start_at)
                        : '未設定'}
                    ～
                    ${s.end_at
                        ? App.utils.escape(s.end_at)
                        : '未設定'}
                </td>

                <td class="px-4 py-4">
                    <span
                        class="rounded-full px-3 py-1 text-xs ${App.utils.statusClass(s.status)}">
                        ${App.utils.statusLabel(s.status)}
                    </span>
                </td>

                <td class="px-4 py-4">
                    ${responseCount} 件
                </td>

                <td class="px-4 py-4">
                    <div class="flex flex-wrap gap-2">
                        ${actions}
                    </div>
                </td>
            </tr>
        `;
    });

    const html = `
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold">
                    アンケート一覧
                </h1>
                <p class="mt-1 text-sm text-slate-500">
                    作成・公開・集計・顧客送信を管理します。
                </p>
            </div>

            <button
                onclick="App.actions.newSurvey()"
                class="rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white shadow">
                ＋ 新規アンケート作成
            </button>
        </div>

        <div class="mb-5 grid gap-3 rounded-xl border bg-white p-4 md:grid-cols-3">
            <input
                id="survey_keyword"
                onkeydown="if(event.key==='Enter')App.actions.search()"
                placeholder="タイトルを検索"
                class="rounded-lg border px-3 py-2">

            <select
                id="survey_status"
                onchange="App.actions.filterStatus()"
                class="rounded-lg border px-3 py-2">
                <option value="">すべて</option>
                <option value="active">公開中</option>
                <option value="draft">下書き</option>
                <option value="ended">終了</option>
            </select>

            <select
                id="survey_sort"
                onchange="App.actions.sortList()"
                class="rounded-lg border px-3 py-2">
                <option value="updated_desc">更新日：新しい順</option>
                <option value="updated_asc">更新日：古い順</option>
                <option value="responses_desc">回答数：多い順</option>
                <option value="responses_asc">回答数：少ない順</option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
            <table class="min-w-[1100px] w-full text-sm">
                <thead class="bg-slate-100">
                    <tr>
                        <th class="px-4 py-3 text-left">作成日 / 更新日</th>
                        <th class="px-4 py-3 text-left">タイトル</th>
                        <th class="px-4 py-3 text-left">アンケート期間</th>
                        <th class="px-4 py-3 text-left">ステータス</th>
                        <th class="px-4 py-3 text-left">回答数</th>
                        <th class="px-4 py-3 text-left">操作</th>
                    </tr>
                </thead>
                <tbody>
                    ${rows ||
                        `<tr>
                            <td colspan="6" class="px-4 py-16 text-center text-slate-400">
                                アンケートはありません。
                            </td>
                        </tr>`
                    }
                </tbody>
            </table>
        </div>
    `;

    App.render.shell(html);
};

/* =========================================================
 * Render: Editor
 * ========================================================= */

App.render.editor = function() {
    const survey =
        App.state.editingSurvey;

    if (!survey) {
        App.render.list();
        return;
    }

    App.actions.reindex();

    let groups = '';

    survey.groups.forEach(
        (group, gi) => {
            let questions = '';

            group.questions.forEach(
                (q, qi) => {
                    let options = '';

                    q.options =
                        Array.isArray(q.options)
                        ? q.options
                        : [];

                    q.options.forEach(
                        (option, oi) => {
                            options += `
                                <div class="flex gap-2">
                                    <input
                                        value="${App.utils.escape(option)}"
                                        onchange="App.actions.updateOption(${gi},${qi},${oi},this.value)"
                                        class="flex-1 rounded-lg border px-3 py-2">

                                    <button
                                        onclick="App.actions.removeOption(${gi},${qi},${oi})"
                                        class="rounded-lg bg-red-50 px-3 text-red-600">
                                        ×
                                    </button>
                                </div>
                            `;
                        }
                    );

                    questions += `
                        <div
                            class="question-card rounded-xl border bg-slate-50 p-4"
                            data-question-id="${q.id}">

                            <div class="mb-4 flex items-center gap-3">
                                <span class="question-handle cursor-grab text-xl">
                                    ⠿
                                </span>

                                <span class="rounded-lg bg-white px-3 py-1 text-sm font-bold">
                                    ${App.utils.escape(q.number)}
                                </span>

                                <input
                                    value="${App.utils.escape(q.text)}"
                                    onchange="App.actions.updateQuestion(${gi},${qi},'text',this.value)"
                                    class="flex-1 rounded-lg border bg-white px-3 py-2">

                                <button
                                    onclick="App.actions.removeQuestion(${gi},${qi})"
                                    class="rounded-lg bg-red-50 px-3 py-2 text-red-600">
                                    削除
                                </button>
                            </div>

                            <div class="grid gap-3 md:grid-cols-3">
                                <select
                                    onchange="App.actions.updateQuestion(${gi},${qi},'type',this.value);App.render.editor()"
                                    class="rounded-lg border bg-white px-3 py-2">
                                    <option
                                        value="single"
                                        ${q.type === 'single' ? 'selected' : ''}>
                                        単一選択
                                    </option>
                                    <option
                                        value="multiple"
                                        ${q.type === 'multiple' ? 'selected' : ''}>
                                        複数選択
                                    </option>
                                    <option
                                        value="text"
                                        ${q.type === 'text' ? 'selected' : ''}>
                                        自由記述
                                    </option>
                                </select>

                                <label class="flex items-center gap-2 rounded-lg border bg-white px-3">
                                    <input
                                        type="checkbox"
                                        ${q.required ? 'checked' : ''}
                                        onchange="App.actions.updateQuestion(${gi},${qi},'required',this.checked)">
                                    必須回答
                                </label>

                                ${
                                    q.type !== 'text'
                                    ? `
                                        <label class="flex items-center gap-2 rounded-lg border bg-white px-3">
                                            <input
                                                type="checkbox"
                                                ${q.other_enabled ? 'checked' : ''}
                                                onchange="App.actions.updateQuestion(${gi},${qi},'other_enabled',this.checked)">
                                            その他入力
                                        </label>
                                    `
                                    : ''
                                }
                            </div>

                            ${
                                q.type !== 'text'
                                ? `
                                    <div class="mt-4 space-y-2">
                                        ${options}

                                        <button
                                            onclick="App.actions.addOption(${gi},${qi})"
                                            class="rounded-lg border bg-white px-3 py-2 text-sm">
                                            ＋ 選択肢追加
                                        </button>
                                    </div>
                                `
                                : ''
                            }
                        </div>
                    `;
                }
            );

            groups += `
                <section
                    class="group-card rounded-2xl border bg-white p-5 shadow-sm"
                    data-group="${gi}">

                    <div class="mb-4 flex items-center gap-3">
                        <span class="group-handle cursor-grab text-xl">
                            ⠿
                        </span>

                        <input
                            value="${App.utils.escape(group.name)}"
                            onchange="App.actions.updateGroupName(${gi},this.value)"
                            class="flex-1 rounded-lg border px-3 py-2 text-lg font-bold">

                        <button
                            onclick="App.actions.removeGroup(${gi})"
                            class="rounded-lg bg-red-50 px-3 py-2 text-red-600">
                            グループ削除
                        </button>
                    </div>

                    <div
                        class="question-list space-y-3"
                        data-group="${gi}">
                        ${questions}
                    </div>

                    <button
                        onclick="App.actions.addQuestion(${gi})"
                        class="mt-4 rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">
                        ＋ 質問追加
                    </button>
                </section>
            `;
        }
    );

    const html = `
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold">
                    アンケート作成・編集
                </h1>
            </div>

            <div class="flex gap-2">
                <button
                    onclick="App.actions.preview()"
                    class="rounded-lg border bg-white px-4 py-2">
                    プレビュー
                </button>

                <button
                    onclick="App.actions.cancelEditor()"
                    class="rounded-lg border px-4 py-2">
                    キャンセル
                </button>

                <button
                    onclick="App.actions.saveSurvey()"
                    class="rounded-lg bg-slate-900 px-5 py-2 text-white">
                    保存して一覧へ戻る
                </button>
            </div>
        </div>

        <div class="mb-5 rounded-xl border bg-white p-5">
            <div class="grid gap-4 md:grid-cols-3">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-semibold">
                        タイトル
                    </label>

                    <input
                        id="survey_title"
                        value="${App.utils.escape(survey.title)}"
                        onchange="App.actions.updateSurveyTitle(this.value)"
                        class="w-full rounded-lg border px-3 py-3">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-semibold">
                        質問番号
                    </label>

                    <select
                        id="survey_numbering_mode"
                        onchange="App.actions.setNumberingMode(this.value)"
                        class="w-full rounded-lg border px-3 py-3">
                        <option
                            value="global"
                            ${survey.numbering_mode === 'global' ? 'selected' : ''}>
                            全体連番 Q1, Q2...
                        </option>
                        <option
                            value="group"
                            ${survey.numbering_mode === 'group' ? 'selected' : ''}>
                            グループ別 Q1-1, Q1-2...
                        </option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-semibold">
                        開始日時
                    </label>
                    <input
                        id="survey_start_at"
                        type="datetime-local"
                        value="${App.utils.escape(survey.start_at)}"
                        onchange="App.actions.updateSurvey('start_at',this.value)"
                        class="w-full rounded-lg border px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-semibold">
                        終了日時
                    </label>
                    <input
                        id="survey_end_at"
                        type="datetime-local"
                        value="${App.utils.escape(survey.end_at)}"
                        onchange="App.actions.updateSurvey('end_at',this.value)"
                        class="w-full rounded-lg border px-3 py-2">
                </div>
            </div>
        </div>

        <div
            id="question_editor"
            class="space-y-5">
            ${groups}
        </div>

        <button
            onclick="App.actions.addGroup()"
            class="mt-5 rounded-xl border-2 border-dashed border-slate-300 bg-white px-5 py-4 font-semibold">
            ＋ グループ追加
        </button>

        <div
            id="preview_modal"
            class="fixed inset-0 z-50 hidden bg-black/50 p-6">
            <div class="mx-auto max-h-[90vh] max-w-4xl overflow-auto rounded-2xl bg-slate-50 p-6">
                <div class="mb-4 flex justify-between">
                    <h2 class="text-xl font-bold">
                        プレビュー
                    </h2>
                    <button
                        onclick="App.actions.closePreview()"
                        class="rounded-lg bg-white px-4 py-2">
                        閉じる
                    </button>
                </div>

                <div id="preview_content"></div>
            </div>
        </div>
    `;

    App.render.shell(html);

    App.actions.initSortable();
};

/* =========================================================
 * Editor helper
 * ========================================================= */

App.actions.updateSurvey = function(
    key,
    value
) {
    App.state.editingSurvey[key] =
        value;
};

/* =========================================================
 * Analysis
 * ========================================================= */

App.render.analysis = function() {
    const survey =
        App.state.data.surveys.find(
            s =>
                s.id ===
                App.state.responseSurveyId
        );

    if (!survey) {
        App.render.list();
        return;
    }

    const responses =
        App.state.data.responses.filter(
            r =>
                r.survey_id === survey.id
        );

    const filter =
        App.state.responseFilter;

    const filtered =
        filter
        ? responses.filter(
            r =>
                `${r.company} ${r.name}`
                    .toLowerCase()
                    .includes(
                        filter.toLowerCase()
                    )
        )
        : responses;

    const questions = [];

    survey.groups.forEach(group => {
        group.questions.forEach(q => {
            questions.push(q);
        });
    });

    const sentCount =
        App.state.data.customers.filter(
            c =>
                c.source === 'kintone' &&
                c.sent_at
        ).length;

    const webCount =
        responses.filter(
            r =>
                App.state.data.customers.find(
                    c =>
                        c.id ===
                        r.customer_id &&
                        c.source === 'web'
                )
        ).length;

    const rate =
        sentCount > 0
        ? (
            responses.length /
            sentCount *
            100
        ).toFixed(1)
        : '0.0';

    let questionCards = '';

    questions.forEach(q => {
        const counts = {};

        responses.forEach(r => {
            let value =
                r.answers?.[q.id];

            if (Array.isArray(value)) {
                value.forEach(v => {
                    counts[v] =
                        (counts[v] || 0) + 1;
                });
            } else if (value !== undefined &&
                       value !== '') {
                counts[value] =
                    (counts[value] || 0) + 1;
            }
        });

        let bars = '';

        if (q.type !== 'text') {
            q.options.forEach(option => {
                const count =
                    counts[option] || 0;

                const percent =
                    responses.length
                    ? (
                        count /
                        responses.length *
                        100
                    ).toFixed(1)
                    : '0.0';

                bars += `
                    <div class="mb-3">
                        <div class="mb-1 flex justify-between text-sm">
                            <span>
                                ${App.utils.escape(option)}
                            </span>
                            <span>
                                ${count}件
                                (${percent}%)
                            </span>
                        </div>

                        <div class="h-3 overflow-hidden rounded-full bg-slate-100">
                            <div
                                class="h-full bg-blue-500"
                                style="width:${percent}%">
                            </div>
                        </div>
                    </div>
                `;
            });
        } else {
            responses.forEach(r => {
                const value =
                    r.answers?.[q.id] ?? '';

                if (!value) return;

                bars += `
                    <div class="mb-2 rounded-lg bg-slate-50 p-3">
                        <div class="text-xs text-slate-400">
                            ${App.utils.escape(r.company)}
                            /
                            ${App.utils.escape(r.name)}
                        </div>
                        <div class="mt-1 whitespace-pre-wrap">
                            ${App.utils.escape(value)}
                        </div>
                    </div>
                `;
            });
        }

        questionCards += `
            <section class="rounded-xl border bg-white p-5">
                <div class="mb-5 flex items-center justify-between">
                    <div>
                        <span class="mr-2 font-bold">
                            ${App.utils.escape(q.number)}
                        </span>
                        ${App.utils.escape(q.text)}
                    </div>

                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs">
                        ${App.utils.questionTypeLabel(q.type)}
                    </span>
                </div>

                ${bars ||
                    '<div class="text-slate-400">回答データはありません。</div>'
                }
            </section>
        `;
    });

    let responseRows = '';

    filtered.forEach(r => {
        responseRows += `
            <tr class="border-t">
                <td class="px-4 py-3">
                    ${App.utils.escape(r.company)}
                </td>

                <td class="px-4 py-3">
                    ${App.utils.escape(r.name)}
                </td>

                <td class="px-4 py-3">
                    ${App.utils.escape(r.answered_at)}
                </td>

                <td class="px-4 py-3">
                    <button
                        onclick="App.actions.showResponse('${r.id}')"
                        class="rounded-lg bg-slate-900 px-3 py-2 text-xs text-white">
                        全回答を表示
                    </button>
                </td>
            </tr>
        `;
    });

    const html = `
        <div class="mb-6 flex items-center justify-between">
            <div>
                <div class="mb-1 text-sm text-slate-400">
                    集計
                </div>
                <h1 class="text-2xl font-bold">
                    ${App.utils.escape(survey.title)}
                </h1>
            </div>

            <div class="flex gap-2">
                <button
                    onclick="location.href='?action=csv&survey_id=${encodeURIComponent(survey.id)}'"
                    class="rounded-lg border bg-white px-4 py-2">
                    CSV出力
                </button>

                <button
                    onclick="App.render.list()"
                    class="rounded-lg bg-slate-900 px-4 py-2 text-white">
                    戻る
                </button>
            </div>
        </div>

        <div class="mb-6 grid gap-4 md:grid-cols-5">
            <div class="rounded-xl border bg-white p-5">
                <div class="text-sm text-slate-400">
                    送信対象者数
                </div>
                <div class="mt-2 text-2xl font-bold">
                    ${sentCount} 人
                </div>
            </div>

            <div class="rounded-xl border bg-white p-5">
                <div class="text-sm text-slate-400">
                    回答数
                </div>
                <div class="mt-2 text-2xl font-bold">
                    ${responses.length} 件
                </div>
            </div>

            <div class="rounded-xl border bg-white p-5">
                <div class="text-sm text-slate-400">
                    未登録顧客回答
                </div>
                <div class="mt-2 text-2xl font-bold">
                    ${webCount} 件
                </div>
            </div>

            <div class="rounded-xl border bg-white p-5">
                <div class="text-sm text-slate-400">
                    未回答
                </div>
                <div class="mt-2 text-2xl font-bold">
                    ${Math.max(
                        sentCount -
                        responses.length,
                        0
                    )} 人
                </div>
            </div>

            <div class="rounded-xl border bg-white p-5">
                <div class="text-sm text-slate-400">
                    回答率
                </div>
                <div class="mt-2 text-2xl font-bold">
                    ${rate} %
                </div>
            </div>
        </div>

        <div class="mb-6">
            <h2 class="mb-4 text-lg font-bold">
                設問別集計
            </h2>

            <div class="space-y-4">
                ${questionCards ||
                    '<div class="rounded-xl border bg-white p-10 text-center text-slate-400">現在、回答データはありません</div>'
                }
            </div>
        </div>

        <div class="rounded-xl border bg-white p-5">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-bold">
                    個別回答一覧
                </h2>

                <input
                    id="response_filter"
                    value="${App.utils.escape(filter)}"
                    oninput="App.actions.filterResponses(this.value)"
                    placeholder="会社名・氏名で検索"
                    class="rounded-lg border px-3 py-2">
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-4 py-3 text-left">会社名</th>
                            <th class="px-4 py-3 text-left">氏名</th>
                            <th class="px-4 py-3 text-left">回答日時</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        ${responseRows}
                    </tbody>
                </table>
            </div>
        </div>

        <div
            id="response_modal"
            class="fixed inset-0 z-50 hidden bg-black/50 p-6">
            <div class="mx-auto max-h-[90vh] max-w-3xl overflow-auto rounded-2xl bg-white p-6">
                <div class="mb-4 flex justify-between">
                    <h2 class="text-xl font-bold">
                        回答詳細
                    </h2>

                    <button
                        onclick="App.actions.closeResponse()"
                        class="rounded-lg border px-4 py-2">
                        閉じる
                    </button>
                </div>

                <div id="response_detail"></div>
            </div>
        </div>
    `;

    App.render.shell(html);
};

/* =========================================================
 * Settings
 * ========================================================= */

App.render.settings = function(current = null) {
    const settings =
        current ||
        App.state.data.settings ||
        {};

    const fieldOptions =
        (selected) => {
            let html =
                '<option value="">未選択</option>';

            App.state.fields.forEach(
                field => {
                    html += `
                        <option
                            value="${App.utils.escape(field.code)}"
                            ${selected === field.code ? 'selected' : ''}>
                            ${App.utils.escape(field.label)}
                            [${App.utils.escape(field.code)}]
                        </option>
                    `;
                }
            );

            return html;
        };

    const addresses =
        Array.isArray(
            settings.field_address
        )
        ? settings.field_address
        : [];

    const addressSelect =
        addresses.map(
            selected => `
                <select
                    data-address-field
                    class="w-full rounded-lg border px-3 py-2">
                    ${fieldOptions(selected)}
                </select>
            `
        ).join('');

    const html = `
        <div class="mb-6">
            <h1 class="text-2xl font-bold">
                キントーン連携設定
            </h1>

            <p class="mt-1 text-sm text-slate-500">
                APIトークンは使用せず、ログイン名・パスワードで接続します。
            </p>
        </div>

        <div
            id="settings_form"
            class="space-y-5">

            <section class="rounded-xl border bg-white p-6">
                <h2 class="mb-4 text-lg font-bold">
                    接続・認証
                </h2>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-semibold">
                            サブドメイン
                        </label>

                        <input
                            id="setting_subdomain"
                            value="${App.utils.escape(settings.subdomain || '')}"
                            placeholder="xxxx.cybozu.com"
                            class="w-full rounded-lg border px-3 py-2">
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold">
                            アプリID
                        </label>

                        <input
                            id="setting_app_id"
                            value="${App.utils.escape(settings.app_id || '')}"
                            class="w-full rounded-lg border px-3 py-2">
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold">
                            ログイン名
                        </label>

                        <input
                            id="setting_login_name"
                            value="${App.utils.escape(settings.login_name || '')}"
                            autocomplete="username"
                            class="w-full rounded-lg border px-3 py-2">
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold">
                            パスワード
                        </label>

                        <input
                            id="setting_password"
                            type="password"
                            value=""
                            autocomplete="new-password"
                            placeholder="変更しない場合は空欄"
                            class="w-full rounded-lg border px-3 py-2">
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold">
                            Proxy
                        </label>

                        <input
                            id="setting_proxy"
                            value="${App.utils.escape(settings.proxy || '')}"
                            placeholder="空欄 / host:port / http://host:port"
                            class="w-full rounded-lg border px-3 py-2">

                        <p class="mt-1 text-xs text-slate-400">
                            Proxy空欄時はHTTP contextへProxy設定を追加しません。
                        </p>
                    </div>

                    <div class="flex items-center">
                        <label class="flex items-center gap-2">
                            <input
                                id="setting_ssl_verify"
                                type="checkbox"
                                ${settings.ssl_verify !== false ? 'checked' : ''}>
                            SSL証明書を検証する
                        </label>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap gap-2">
                    <button
                        onclick="App.actions.fetchKintoneFields()"
                        class="rounded-lg bg-blue-600 px-4 py-2 text-white">
                        項目一覧を再取得
                    </button>

                    <button
                        onclick="App.actions.saveSettings()"
                        class="rounded-lg bg-slate-900 px-4 py-2 text-white">
                        設定を保存
                    </button>
                </div>

                <pre
                    id="field_message"
                    class="mt-4 whitespace-pre-wrap rounded-lg bg-slate-50 p-4 text-sm"></pre>
            </section>

            <section class="rounded-xl border bg-white p-6">
                <h2 class="mb-4 text-lg font-bold">
                    フィールドマッピング
                </h2>

                <div class="grid gap-4 md:grid-cols-2">
                    <label>
                        <span class="mb-1 block text-sm font-semibold">
                            会社名 (Company)
                        </span>
                        <select
                            id="field_company"
                            class="w-full rounded-lg border px-3 py-2">
                            ${fieldOptions(settings.field_company || '')}
                        </select>
                    </label>

                    <label>
                        <span class="mb-1 block text-sm font-semibold">
                            氏名 (Name)
                        </span>
                        <select
                            id="field_name"
                            class="w-full rounded-lg border px-3 py-2">
                            ${fieldOptions(settings.field_name || '')}
                        </select>
                    </label>

                    <label>
                        <span class="mb-1 block text-sm font-semibold">
                            メールアドレス (Email)
                        </span>
                        <select
                            id="field_email"
                            class="w-full rounded-lg border px-3 py-2">
                            ${fieldOptions(settings.field_email || '')}
                        </select>
                    </label>

                    <label>
                        <span class="mb-1 block text-sm font-semibold">
                            部署名 (Department)
                        </span>
                        <select
                            id="field_department"
                            class="w-full rounded-lg border px-3 py-2">
                            ${fieldOptions(settings.field_department || '')}
                        </select>
                    </label>

                    <label>
                        <span class="mb-1 block text-sm font-semibold">
                            電話番号 (Phone)
                        </span>
                        <select
                            id="field_phone"
                            class="w-full rounded-lg border px-3 py-2">
                            ${fieldOptions(settings.field_phone || '')}
                        </select>
                    </label>

                    <div>
                        <span class="mb-1 block text-sm font-semibold">
                            住所 (Address)
                        </span>

                        <div class="space-y-2">
                            ${addressSelect ||
                                `
                                    <select
                                        data-address-field
                                        class="w-full rounded-lg border px-3 py-2">
                                        ${fieldOptions('')}
                                    </select>
                                `
                            }
                        </div>
                    </div>
                </div>
            </section>
        </div>
    `;

    App.render.shell(html);
};

/* =========================================================
 * Mail
 * ========================================================= */

App.render.mail = function() {
    const survey =
        App.state.data.surveys.find(
            s =>
                s.id ===
                App.state.mailSurveyId
        );

    if (!survey) {
        App.render.list();
        return;
    }

    const customers =
        App.state.data.customers;

    const rows =
        customers.map(c => `
            <tr class="border-t">
                <td class="px-4 py-3">
                    <input
                        type="checkbox"
                        ${c.source === 'web' ? 'disabled' : ''}
                        data-recipient="${App.utils.escape(c.id)}">
                </td>

                <td class="px-4 py-3">
                    <div class="font-bold">
                        ${App.utils.escape(c.company)}
                    </div>
                    <div>
                        ${App.utils.escape(c.name)}
                    </div>
                    <div class="text-xs text-slate-400">
                        ${App.utils.escape(c.email)}
                    </div>
                </td>

                <td class="px-4 py-3">
                    ${c.sent_at
                        ? App.utils.escape(c.sent_at)
                        : '未送信'}
                </td>

                <td class="px-4 py-3">
                    ${c.answer_status === 'answered'
                        ? '<span class="text-emerald-600">回答済み</span>'
                        : '<span class="text-amber-600">未回答</span>'}
                </td>
            </tr>
        `).join('');

    App.render.shell(`
        <div class="mb-6 flex items-center justify-between">
            <div>
                <div class="text-sm text-slate-400">
                    ホーム ＞ アンケート一覧 ＞ 顧客選択・送信
                </div>
                <h1 class="mt-2 text-2xl font-bold">
                    ${App.utils.escape(survey.title)}
                </h1>
            </div>

            <button
                onclick="App.render.list()"
                class="rounded-lg border bg-white px-4 py-2">
                戻る
            </button>
        </div>

        <div class="mb-5 rounded-xl border bg-white p-6">
            <div class="grid gap-4">
                <label>
                    <span class="mb-1 block text-sm font-semibold">
                        テンプレート
                    </span>

                    <select
                        id="template_type"
                        class="rounded-lg border px-3 py-2">
                        <option value="initial">
                            初回送信
                        </option>
                        <option value="reminder">
                            リマインド
                        </option>
                    </select>
                </label>

                <label>
                    <span class="mb-1 block text-sm font-semibold">
                        件名
                    </span>

                    <input
                        id="mail_subject"
                        value="${App.utils.escape(survey.title)}"
                        class="rounded-lg border px-3 py-2">
                </label>

                <label>
                    <span class="mb-1 block text-sm font-semibold">
                        本文
                    </span>

                    <textarea
                        id="mail_body"
                        rows="8"
                        class="rounded-lg border px-3 py-2">{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}</textarea>
                </label>

                <button
                    onclick="App.actions.sendMail()"
                    class="w-fit rounded-lg bg-slate-900 px-5 py-3 text-white">
                    一括送信実行
                </button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border bg-white">
            <table
                id="customer_table"
                class="min-w-[900px] w-full text-sm">
                <thead class="bg-slate-100">
                    <tr>
                        <th class="px-4 py-3">
                            <input
                                id="select_all"
                                type="checkbox"
                                onchange="
                                    document
                                    .querySelectorAll('[data-recipient]')
                                    .forEach(x => {
                                        if (!x.disabled)
                                            x.checked=this.checked;
                                    })
                                ">
                        </th>
                        <th class="px-4 py-3 text-left">
                            会社名 / 氏名等
                        </th>
                        <th class="px-4 py-3 text-left">
                            最終送信日時
                        </th>
                        <th class="px-4 py-3 text-left">
                            回答ステータス
                        </th>
                    </tr>
                </thead>
                <tbody>
                    ${rows}
                </tbody>
            </table>
        </div>
    `);
};

/* =========================================================
 * 公開フォーム
 *
 * ?public=1&survey_id=...
 * ========================================================= */

App.render.public = async function() {
    const params =
        new URLSearchParams(
            location.search
        );

    const surveyId =
        params.get('survey_id');

    if (!surveyId) {
        document.getElementById('app')
            .innerHTML =
            '<div class="p-10">survey_idがありません。</div>';
        return;
    }

    try {
        const response =
            await fetch(
                location.pathname +
                '?action=public_survey&survey_id=' +
                encodeURIComponent(surveyId)
            );

        const json =
            await response.json();

        if (!json.ok) {
            throw new Error(
                json.message
            );
        }

        const survey =
            json.survey;

        let questions = '';

        survey.groups.forEach(
            group => {
                questions += `
                    <section class="mb-8">
                        <h2 class="mb-4 text-xl font-bold">
                            ${App.utils.escape(group.name)}
                        </h2>
                `;

                group.questions.forEach(
                    q => {
                        let control = '';

                        if (q.type === 'text') {
                            control = `
                                <textarea
                                    name="answer_${App.utils.escape(q.id)}"
                                    class="w-full rounded-lg border p-3"
                                    rows="4"></textarea>
                            `;
                        } else {
                            q.options.forEach(
                                option => {
                                    const type =
                                        q.type === 'single'
                                        ? 'radio'
                                        : 'checkbox';

                                    control += `
                                        <label class="mb-2 flex items-center gap-2">
                                            <input
                                                type="${type}"
                                                name="answer_${App.utils.escape(q.id)}${q.type === 'multiple' ? '[]' : ''}"
                                                value="${App.utils.escape(option)}">
                                            ${App.utils.escape(option)}
                                        </label>
                                    `;
                                }
                            );
                        }

                        questions += `
                            <div class="mb-6 rounded-xl border bg-white p-5">
                                <div class="mb-4 font-semibold">
                                    ${App.utils.escape(q.number)}
                                    ${App.utils.escape(q.text)}
                                    ${q.required
                                        ? '<span class="text-red-500"> *</span>'
                                        : ''}
                                </div>

                                ${control}
                            </div>
                        `;
                    }
                );

                questions += '</section>';
            }
        );

        document.getElementById('app')
            .innerHTML = `
                <main class="min-h-screen bg-slate-50 px-4 py-10">
                    <div class="mx-auto max-w-3xl">
                        <div class="mb-6">
                            <h1 class="text-3xl font-bold">
                                ${App.utils.escape(survey.title)}
                            </h1>
                        </div>

                        <form
                            id="public_response_form"
                            class="rounded-2xl">

                            <div class="mb-6 rounded-xl border bg-white p-5">
                                <div class="grid gap-4 md:grid-cols-3">
                                    <input
                                        name="company"
                                        placeholder="会社名"
                                        class="rounded-lg border px-3 py-2">

                                    <input
                                        name="name"
                                        placeholder="氏名"
                                        class="rounded-lg border px-3 py-2">

                                    <input
                                        name="email"
                                        type="email"
                                        placeholder="メールアドレス"
                                        class="rounded-lg border px-3 py-2">
                                </div>
                            </div>

                            ${questions}

                            <button
                                type="submit"
                                class="rounded-xl bg-slate-900 px-6 py-3 font-semibold text-white">
                                回答を送信
                            </button>
                        </form>
                    </div>
                </main>
            `;

        document.getElementById(
            'public_response_form'
        ).addEventListener(
            'submit',
            async function(e) {
                e.preventDefault();

                /*
                 * HTMLフォームからanswersを作成。
                 */
                const answers = {};

                survey.groups.forEach(
                    group => {
                        group.questions.forEach(
                            q => {
                                const nodes =
                                    document.querySelectorAll(
                                        `[name="answer_${CSS.escape(q.id)}"],[name="answer_${CSS.escape(q.id)}[]"]`
                                    );

                                if (q.type === 'multiple') {
                                    answers[q.id] =
                                        Array.from(nodes)
                                            .filter(x => x.checked)
                                            .map(x => x.value);
                                } else if (q.type === 'single') {
                                    const checked =
                                        Array.from(nodes)
                                            .find(x => x.checked);

                                    answers[q.id] =
                                        checked
                                        ? checked.value
                                        : '';
                                } else {
                                    answers[q.id] =
                                        nodes[0]?.value || '';
                                }
                            }
                        );
                    }
                );

                const fd =
                    new FormData(
                        this
                    );

                fd.append(
                    'answers',
                    JSON.stringify(answers)
                );

                fd.append(
                    'survey_id',
                    surveyId
                );

                fd.append(
                    'action',
                    'submit_response'
                );

                const result =
                    await fetch(
                        location.pathname,
                        {
                            method: 'POST',
                            body: fd
                        }
                    ).then(
                        r => r.json()
                    );

                if (!result.ok) {
                    App.utils.notify(
                        result.message ||
                        '送信できませんでした。',
                        true
                    );
                    return;
                }

                this.innerHTML = `
                    <div class="rounded-2xl border bg-white p-10 text-center">
                        <div class="text-2xl font-bold">
                            ご回答ありがとうございました。
                        </div>
                        <div class="mt-3 text-slate-500">
                            回答を受け付けました。
                        </div>
                    </div>
                `;
            }
        );
    } catch (e) {
        document.getElementById('app')
            .innerHTML = `
                <div class="p-10 text-red-600">
                    ${App.utils.escape(e.message)}
                </div>
            `;
    }
};

/* =========================================================
 * 初期化
 * ========================================================= */

App.actions.route = function() {
    const params =
        new URLSearchParams(
            location.search
        );

    if (
        params.get('public') === '1'
    ) {
        App.render.public();
        return;
    }

    App.api.bootstrap();
};

if (
    document.readyState ===
    'loading'
) {
    document.addEventListener(
        'DOMContentLoaded',
        () => App.actions.route(),
        { once: true }
    );
} else {
    App.actions.route();
}
</script>

</body>
</html>

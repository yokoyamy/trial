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

/* ============================================================
 * 基本
 * ============================================================ */

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
    if (
        !is_dir(SURVEY_STORAGE_DIRECTORY) &&
        !@mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true)
    ) {
        return false;
    }

    $tmp = SURVEY_STORAGE_FILE . '.tmp';

    $json = survey_json($data);

    if (
        @file_put_contents(
            $tmp,
            $json,
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
        $_SESSION['csrf_token'] = bin2hex(
            random_bytes(32)
        );
    }

    return (string)$_SESSION['csrf_token'];
}

function survey_check_token(): bool
{
    $a = (string)($_SESSION['csrf_token'] ?? '');
    $b = (string)($_POST['csrf_token'] ?? '');

    return (
        $a !== '' &&
        $b !== '' &&
        hash_equals($a, $b)
    );
}

function survey_api(
    array $data,
    int $status = 200
): never {
    http_response_code($status);
    header(
        'Content-Type: application/json; charset=UTF-8'
    );
    echo survey_json($data);
    exit;
}

/* ============================================================
 * kintone URL正規化
 * ============================================================ */

function survey_normalize_kintone_base(
    string $input
): array {
    $input = trim($input);
    $input = rtrim(
        $input,
        "/ \t\r\n"
    );

    if ($input === '') {
        return [
            'ok' => false,
            'error' => 'kintoneホストが未入力です。'
        ];
    }

    if (!preg_match(
        '~^https?://~i',
        $input
    )) {
        $input = 'https://' . $input;
    }

    $host = '';

    $parsed = @parse_url($input);

    if (is_array($parsed)) {
        $host = (string)(
            $parsed['host'] ?? ''
        );

        if (isset($parsed['port'])) {
            $host .= ':' .
                (int)$parsed['port'];
        }
    }

    if (
        $host === '' &&
        preg_match(
            '~^https?://([^/?#]+)~i',
            $input,
            $m
        )
    ) {
        $host = $m[1];
    }

    $host = strtolower(trim($host));

    if ($host === '') {
        return [
            'ok' => false,
            'error' =>
                'kintoneホストを取得できません。'
        ];
    }

    $hostOnly = preg_replace(
        '/:\d+$/',
        '',
        $host
    );

    $validCybozu = preg_match(
        '~^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.cybozu\.com$~i',
        (string)$hostOnly
    );

    $validFqdn = preg_match(
        '~^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$~i',
        (string)$hostOnly
    );

    if (!$validCybozu && !$validFqdn) {
        return [
            'ok' => false,
            'error' =>
                '許可されていないkintoneホスト名です。'
        ];
    }

    return [
        'ok' => true,
        'base' => 'https://' . $host,
        'host' => $hostOnly,
    ];
}

/* ============================================================
 * Proxy
 * ============================================================ */

function survey_parse_proxy(
    string $input
): array {
    $input = trim($input);

    if ($input === '') {
        return [
            'ok' => true,
            'used' => false,
            'value' => '',
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
                'Proxy形式は host:port、http://host:port、https://host:port です。'
        ];
    }

    $port = (int)$m[3];

    if ($port < 1 || $port > 65535) {
        return [
            'ok' => false,
            'used' => true,
            'value' => '',
            'error' =>
                'Proxyポート番号が不正です。'
        ];
    }

    return [
        'ok' => true,
        'used' => true,
        'value' =>
            'tcp://' .
            strtolower($m[2]) .
            ':' .
            $port,
    ];
}

/* ============================================================
 * HTTP
 * ============================================================ */

function survey_last_headers(): array
{
    if (
        function_exists(
            'http_get_last_response_headers'
        )
    ) {
        try {
            $h =
                http_get_last_response_headers();

            if (is_array($h)) {
                return $h;
            }
        } catch (Throwable) {
        }
    }

    $h =
        $GLOBALS['http_response_header'] ?? null;

    return is_array($h) ? $h : [];
}

function survey_status_from_headers(
    array $headers
): int {
    $status = 0;

    foreach ($headers as $header) {
        if (
            preg_match(
                '~^HTTP/\S+\s+([0-9]{3})~i',
                (string)$header,
                $m
            )
        ) {
            $status = (int)$m[1];
        }
    }

    return $status;
}

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

    if (!filter_var(
        $url,
        FILTER_VALIDATE_URL
    )) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' =>
                '接続先URLが不正です。',
            'url' => $url,
            'proxy_used' =>
                $proxyInfo['used'],
        ];
    }

    $parsed = @parse_url($url);

    $peerName = is_array($parsed)
        ? (string)(
            $parsed['host'] ?? ''
        )
        : '';

    $http = [
        'method' =>
            strtoupper($method),
        'timeout' => 30,
        'ignore_errors' => true,
        'protocol_version' => 1.1,
        'header' =>
            implode(
                "\r\n",
                $headers
            ),
    ];

    if (
        $content !== null &&
        strtoupper($method) !== 'GET'
    ) {
        $http['content'] = $content;
    }

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
        $body =
            file_get_contents(
                $url,
                false,
                $context
            );
    } catch (Throwable $e) {
        $body = false;
        $warning = $e->getMessage();
    }

    restore_error_handler();

    $headersResult =
        survey_last_headers();

    $status =
        survey_status_from_headers(
            $headersResult
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

    if ($status === 0) {
        $error =
            $warning !== ''
                ? $warning
                : 'HTTPレスポンスを取得できませんでした。';

        $error .=
            "\n確認事項: DNS、外部HTTPS通信、Proxy、"
            . "ファイアウォール、SSL/TLS、OpenSSL、タイムアウト。";

        return [
            'status' => 0,
            'body' => $bodyText,
            'json' => $json,
            'error' => $error,
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

/* ============================================================
 * kintone API
 * ============================================================ */

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

    $appId = trim(
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

    $url =
        $normalized['base'] .
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode($appId);

    $login =
        (string)(
            $settings['login_name'] ?? ''
        );

    $password =
        (string)(
            $settings['password'] ?? ''
        );

    if (
        $login === '' ||
        $password === ''
    ) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' =>
                'ログイン名とパスワードを入力してください。',
            'url' => $url,
            'proxy_used' => false,
        ];
    }

    $auth = base64_encode(
        $login . ':' . $password
    );

    return survey_http_request(
        $url,
        'GET',
        [
            'X-Cybozu-Authorization: ' .
                $auth,
            'Accept: application/json',
            'Connection: close',
        ],
        null,
        (bool)(
            $settings['ssl_verify'] ?? true
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
            "kintoneからHTTPレスポンスを取得できませんでした。\n" .
            "HTTPステータス: 0\n" .
            "接続先: {$url}\n" .
            "Proxy: {$proxy}\n" .
            "PHP通信エラー: " .
            ($error !== ''
                ? $error
                : 'なし') .
            "\n確認事項: サーバーからの外部HTTPS通信、DNS、" .
            "Proxy、ファイアウォール、SSL/TLS、OpenSSL";
    }

    if (
        $status === 401 ||
        $status === 403
    ) {
        return
            "kintone認証または権限エラーです。\n" .
            "HTTPステータス: {$status}\n" .
            "接続先: {$url}";
    }

    if ($status === 404) {
        return
            "kintone APIまたはアプリが見つかりません。\n" .
            "HTTPステータス: 404\n" .
            "接続先: {$url}";
    }

    if ($status === 408) {
        return
            "kintone通信がタイムアウトしました。\n" .
            "HTTPステータス: 408";
    }

    if ($status === 429) {
        return
            "kintone側のレート制限です。\n" .
            "HTTPステータス: 429";
    }

    if ($status >= 500) {
        return
            "kintoneまたはProxy側のサーバーエラーです。\n" .
            "HTTPステータス: {$status}";
    }

    if (
        $status >= 200 &&
        $status < 300
    ) {
        return
            "kintone通信に成功しました。\n" .
            "HTTPステータス: {$status}";
    }

    return
        "kintone通信でエラーが発生しました。\n" .
        "HTTPステータス: {$status}\n" .
        "接続先: {$url}\n" .
        (
            $error !== ''
                ? "PHP通信エラー: {$error}"
                : ''
        );
}

/* ============================================================
 * 必須関数 fetchKintoneFields()
 * ============================================================ */

function fetchKintoneFields(
    array $settings
): array {
    $r =
        survey_kintone_request(
            $settings
        );

    $status =
        (int)$r['status'];

    if (
        $status < 200 ||
        $status >= 300
    ) {
        return [
            'ok' => false,
            'fields' => [],
            'message' =>
                survey_kintone_message($r),
            'diagnostic' => $r,
        ];
    }

    $json = $r['json'];

    if (
        !is_array($json) ||
        !isset($json['properties']) ||
        !is_array($json['properties'])
    ) {
        return [
            'ok' => false,
            'fields' => [],
            'message' =>
                'kintoneレスポンスにpropertiesがありません。',
            'diagnostic' => $r,
        ];
    }

    $fields = [];

    foreach (
        $json['properties']
        as $code => $property
    ) {
        if (!is_array($property)) {
            continue;
        }

        $fields[] = [
            'code' => (string)$code,
            'label' =>
                (string)(
                    $property['label'] ??
                    $code
                ),
            'type' =>
                (string)(
                    $property['type'] ?? ''
                ),
        ];
    }

    return [
        'ok' => true,
        'fields' => $fields,
        'message' =>
            'kintone接続成功。項目一覧を取得しました。',
        'diagnostic' => [
            'status' => $status,
            'url' => $r['url'],
            'proxy_used' =>
                $r['proxy_used'],
        ],
    ];
}

/* ============================================================
 * POST API
 * ============================================================ */

if (
    $_SERVER['REQUEST_METHOD'] ===
    'POST'
) {
    $action =
        (string)(
            $_POST['action'] ?? ''
        );

    if (!survey_check_token()) {
        survey_api([
            'ok' => false,
            'message' =>
                'CSRFトークンが不正です。'
        ], 403);
    }

    $data =
        survey_read_data();

    /* --------------------------------------------------------
     * データ取得
     * -------------------------------------------------------- */

    if ($action === 'get_data') {
        survey_api([
            'ok' => true,
            'data' => $data
        ]);
    }

    /* --------------------------------------------------------
     * アンケート保存
     * -------------------------------------------------------- */

    if ($action === 'save_survey') {
        $raw =
            (string)(
                $_POST['survey_json'] ?? ''
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

        $survey['id'] =
            (string)(
                $survey['id'] ??
                survey_id()
            );

        $survey['title'] =
            trim(
                (string)(
                    $survey['title'] ?? ''
                )
            );

        if ($survey['title'] === '') {
            $survey['title'] =
                '無題のアンケート';
        }

        $survey['start_at'] =
            (string)(
                $survey['start_at'] ?? ''
            );

        $survey['end_at'] =
            (string)(
                $survey['end_at'] ?? ''
            );

        $survey['numbering_mode'] =
            in_array(
                $survey['numbering_mode'] ?? '',
                ['global', 'group'],
                true
            )
                ? $survey['numbering_mode']
                : 'global';

        $survey['status'] =
            in_array(
                $survey['status'] ?? '',
                ['draft', 'active', 'ended'],
                true
            )
                ? $survey['status']
                : 'draft';

        $survey['groups'] =
            is_array(
                $survey['groups'] ?? null
            )
                ? $survey['groups']
                : [];

        $survey['deleted'] =
            (bool)(
                $survey['deleted'] ?? false
            );

        $survey['updated_at'] =
            survey_now();

        $found = false;

        foreach (
            $data['surveys']
            as $i => $old
        ) {
            if (
                ($old['id'] ?? '') ===
                $survey['id']
            ) {
                $survey['created_at'] =
                    $old['created_at'] ??
                    survey_now();

                $data['surveys'][$i] =
                    $survey;

                $found = true;
                break;
            }
        }

        if (!$found) {
            $survey['created_at'] =
                survey_now();

            $data['surveys'][] =
                $survey;
        }

        if (!survey_write_data($data)) {
            survey_api([
                'ok' => false,
                'message' =>
                    'データ保存に失敗しました。'
            ], 500);
        }

        survey_api([
            'ok' => true,
            'data' => $data
        ]);
    }

    /* --------------------------------------------------------
     * ステータス変更
     * -------------------------------------------------------- */

    if ($action === 'status') {
        $id =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        $newStatus =
            (string)(
                $_POST['status'] ?? ''
            );

        if (!in_array(
            $newStatus,
            ['draft', 'active', 'ended'],
            true
        )) {
            survey_api([
                'ok' => false,
                'message' =>
                    'ステータスが不正です。'
            ], 400);
        }

        $changed = false;

        foreach (
            $data['surveys']
            as &$survey
        ) {
            if (
                ($survey['id'] ?? '') ===
                $id
            ) {
                $survey['status'] =
                    $newStatus;

                $survey['updated_at'] =
                    survey_now();

                $changed = true;
                break;
            }
        }

        unset($survey);

        if (!$changed) {
            survey_api([
                'ok' => false,
                'message' =>
                    'アンケートが見つかりません。'
            ], 404);
        }

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'data' => $data
        ]);
    }

    /* --------------------------------------------------------
     * 論理削除
     * -------------------------------------------------------- */

    if ($action === 'delete_survey') {
        $id =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        $changed = false;

        foreach (
            $data['surveys']
            as &$survey
        ) {
            if (
                ($survey['id'] ?? '') ===
                $id
            ) {
                $survey['deleted'] = true;
                $survey['updated_at'] =
                    survey_now();

                $changed = true;
                break;
            }
        }

        unset($survey);

        if (!$changed) {
            survey_api([
                'ok' => false,
                'message' =>
                    'アンケートが見つかりません。'
            ], 404);
        }

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'data' => $data
        ]);
    }

    /* --------------------------------------------------------
     * kintone設定保存
     * -------------------------------------------------------- */

    if ($action === 'save_settings') {
        $raw =
            (string)(
                $_POST['settings_json'] ?? ''
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

        $settings['subdomain'] =
            trim(
                (string)(
                    $settings['subdomain'] ?? ''
                )
            );

        $settings['login_name'] =
            trim(
                (string)(
                    $settings['login_name'] ?? ''
                )
            );

        $settings['password'] =
            (string)(
                $settings['password'] ?? ''
            );

        $settings['app_id'] =
            trim(
                (string)(
                    $settings['app_id'] ?? ''
                )
            );

        $settings['proxy'] =
            trim(
                (string)(
                    $settings['proxy'] ?? ''
                )
            );

        $settings['ssl_verify'] =
            !empty(
                $settings['ssl_verify']
            );

        $settings['field_address'] =
            is_array(
                $settings['field_address'] ?? null
            )
                ? $settings['field_address']
                : [];

        $data['settings'] =
            array_replace(
                $data['settings'],
                $settings
            );

        if (!survey_write_data($data)) {
            survey_api([
                'ok' => false,
                'message' =>
                    '設定保存に失敗しました。'
            ], 500);
        }

        survey_api([
            'ok' => true,
            'data' => $data
        ]);
    }

    /* --------------------------------------------------------
     * kintone接続確認・項目取得
     * -------------------------------------------------------- */

    if (
        $action === 'kintone_fields'
    ) {
        $raw =
            (string)(
                $_POST['settings_json'] ?? ''
            );

        $settings =
            json_decode(
                $raw,
                true
            );

        if (!is_array($settings)) {
            $settings =
                $data['settings'];
        }

        $result =
            fetchKintoneFields(
                $settings
            );

        survey_api($result);
    }

    /* --------------------------------------------------------
     * 顧客追加
     * -------------------------------------------------------- */

    if ($action === 'save_customer') {
        $customer = [
            'id' =>
                (string)(
                    $_POST['customer_id'] ??
                    survey_id()
                ),
            'company' =>
                trim(
                    (string)(
                        $_POST['company'] ?? ''
                    )
                ),
            'name' =>
                trim(
                    (string)(
                        $_POST['name'] ?? ''
                    )
                ),
            'email' =>
                trim(
                    (string)(
                        $_POST['email'] ?? ''
                    )
                ),
            'department' =>
                trim(
                    (string)(
                        $_POST['department'] ?? ''
                    )
                ),
            'phone' =>
                trim(
                    (string)(
                        $_POST['phone'] ?? ''
                    )
                ),
            'address' =>
                trim(
                    (string)(
                        $_POST['address'] ?? ''
                    )
                ),
            'source' => 'kintone',
            'sent_at' => '',
            'send_count' => 0,
            'answer_status' =>
                'unanswered',
            'kintone_status' =>
                'registered',
        ];

        if (
            !filter_var(
                $customer['email'],
                FILTER_VALIDATE_EMAIL
            )
        ) {
            survey_api([
                'ok' => false,
                'message' =>
                    'メールアドレスが不正です。'
            ], 400);
        }

        $updated = false;

        foreach (
            $data['customers']
            as $i => $old
        ) {
            if (
                ($old['id'] ?? '') ===
                $customer['id']
            ) {
                $customer['send_at'] =
                    $old['sent_at'] ?? '';

                $customer['send_count'] =
                    (int)(
                        $old['send_count'] ?? 0
                    );

                $customer['answer_status'] =
                    $old['answer_status'] ??
                    'unanswered';

                $data['customers'][$i] =
                    $customer;

                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $data['customers'][] =
                $customer;
        }

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'data' => $data
        ]);
    }

    /* --------------------------------------------------------
     * メール送信ログ
     * -------------------------------------------------------- */

    if ($action === 'send_mail') {
        $surveyId =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        $ids =
            json_decode(
                (string)(
                    $_POST['recipient_ids'] ??
                    '[]'
                ),
                true
            );

        if (!is_array($ids)) {
            $ids = [];
        }

        $subject =
            trim(
                (string)(
                    $_POST['mail_subject'] ?? ''
                )
            );

        $body =
            (string)(
                $_POST['mail_body'] ?? ''
            );

        $template =
            (string)(
                $_POST['template_type'] ??
                'initial'
            );

        if ($subject === '') {
            survey_api([
                'ok' => false,
                'message' =>
                    '件名を入力してください。'
            ], 400);
        }

        $count = 0;

        foreach (
            $data['customers']
            as &$customer
        ) {
            if (
                in_array(
                    $customer['id'] ?? '',
                    $ids,
                    true
                )
            ) {
                $customer['sent_at'] =
                    survey_now();

                $customer['send_count'] =
                    (int)(
                        $customer['send_count'] ??
                        0
                    ) + 1;

                $count++;
            }
        }

        unset($customer);

        $data['mail_logs'][] = [
            'id' => survey_id(),
            'survey_id' => $surveyId,
            'sent_at' => survey_now(),
            'type' => $template,
            'count' => $count,
            'subject' => $subject,
            'body' => $body,
            'executor' => 'admin',
        ];

        survey_write_data($data);

        /*
         * この1ファイル版では、メール配送自体は
         * PHP mail()/SMTP設定に依存するため、
         * 送信ログと送信対象更新を先に確実に行う。
         */
        survey_api([
            'ok' => true,
            'count' => $count,
            'data' => $data,
            'message' =>
                $count .
                '件を送信対象として記録しました。'
        ]);
    }

    /* --------------------------------------------------------
     * kintone登録済み更新
     * -------------------------------------------------------- */

    if (
        $action ===
        'set_kintone_registered'
    ) {
        $id =
            (string)(
                $_POST['customer_id'] ?? ''
            );

        foreach (
            $data['customers']
            as &$customer
        ) {
            if (
                ($customer['id'] ?? '') ===
                $id
            ) {
                $customer['kintone_status'] =
                    'registered';
                break;
            }
        }

        unset($customer);

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'data' => $data
        ]);
    }

    /* --------------------------------------------------------
     * CSV
     * -------------------------------------------------------- */

    if ($action === 'csv') {
        $surveyId =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        $survey = null;

        foreach (
            $data['surveys']
            as $s
        ) {
            if (
                ($s['id'] ?? '') ===
                $surveyId
            ) {
                $survey = $s;
                break;
            }
        }

        $questions = [];

        if (is_array($survey)) {
            foreach (
                $survey['groups'] ?? []
                as $group
            ) {
                foreach (
                    $group['questions'] ?? []
                    as $question
                ) {
                    $questions[] =
                        $question;
                }
            }
        }

        $fp = fopen(
            'php://output',
            'wb'
        );

        if ($fp === false) {
            exit;
        }

        header(
            'Content-Type: text/csv; charset=UTF-8'
        );

        header(
            'Content-Disposition: attachment; filename="survey_' .
            date('Ymd_His') .
            '.csv"'
        );

        fwrite(
            $fp,
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
            as $i => $question
        ) {
            $header[] =
                '設問' . ($i + 1);
        }

        fputcsv(
            $fp,
            $header
        );

        foreach (
            $data['responses']
            as $response
        ) {
            if (
                ($response['survey_id'] ?? '') !==
                $surveyId
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
                $qid =
                    (string)(
                        $question['id'] ?? ''
                    );

                $value =
                    $answers[$qid] ?? '';

                if (is_array($value)) {
                    $value =
                        implode(
                            ' / ',
                            $value
                        );
                }

                $row[] = $value;
            }

            fputcsv(
                $fp,
                $row
            );
        }

        fclose($fp);
        exit;
    }

    /* --------------------------------------------------------
     * 公開回答
     * -------------------------------------------------------- */

    if ($action === 'submit_response') {
        $surveyId =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        $survey = null;

        foreach (
            $data['surveys']
            as $s
        ) {
            if (
                ($s['id'] ?? '') ===
                $surveyId &&
                empty($s['deleted'])
            ) {
                $survey = $s;
                break;
            }
        }

        if (!is_array($survey)) {
            survey_api([
                'ok' => false,
                'message' =>
                    'アンケートが見つかりません。'
            ], 404);
        }

        if (
            ($survey['status'] ?? '') !==
            'active'
        ) {
            survey_api([
                'ok' => false,
                'message' =>
                    'このアンケートは現在回答できません。'
            ], 400);
        }

        $answers =
            json_decode(
                (string)(
                    $_POST['answers'] ?? '{}'
                ),
                true
            );

        if (!is_array($answers)) {
            $answers = [];
        }

        $response = [
            'id' => survey_id(),
            'survey_id' => $surveyId,
            'customer_id' =>
                (string)(
                    $_POST['customer_id'] ?? ''
                ),
            'company' =>
                trim(
                    (string)(
                        $_POST['company'] ?? ''
                    )
                ),
            'name' =>
                trim(
                    (string)(
                        $_POST['name'] ?? ''
                    )
                ),
            'email' =>
                trim(
                    (string)(
                        $_POST['email'] ?? ''
                    )
                ),
            'answered_at' =>
                survey_now(),
            'answers' => $answers,
        ];

        $data['responses'][] =
            $response;

        foreach (
            $data['customers']
            as &$customer
        ) {
            if (
                $response['customer_id'] !== '' &&
                ($customer['id'] ?? '') ===
                $response['customer_id']
            ) {
                $customer['answer_status'] =
                    'answered';
                break;
            }

            if (
                $response['email'] !== '' &&
                strcasecmp(
                    (string)(
                        $customer['email'] ?? ''
                    ),
                    $response['email']
                ) === 0
            ) {
                $customer['answer_status'] =
                    'answered';
                break;
            }
        }

        unset($customer);

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'message' =>
                '回答を送信しました。'
        ]);
    }

    survey_api([
        'ok' => false,
        'message' =>
            '不明なactionです。'
    ], 400);
}

/* ============================================================
 * GET 公開回答画面
 * ============================================================ */

$isPublic =
    isset($_GET['survey_id']) &&
    (string)$_GET['survey_id'] !== '';

$csrf = survey_token();

if ($isPublic) {
    $data =
        survey_read_data();

    $surveyId =
        (string)$_GET['survey_id'];

    $publicSurvey = null;

    foreach (
        $data['surveys']
        as $s
    ) {
        if (
            ($s['id'] ?? '') ===
            $surveyId &&
            empty($s['deleted'])
        ) {
            $publicSurvey = $s;
            break;
        }
    }
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= survey_h($publicSurvey['title'] ?? 'アンケート') ?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen">

<div class="max-w-3xl mx-auto px-4 py-10">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 md:p-8">

<?php if (!$publicSurvey): ?>

        <div class="text-center py-16">
            <div class="text-5xl mb-4">!</div>
            <h1 class="text-xl font-bold">
                アンケートが見つかりません
            </h1>
        </div>

<?php elseif (($publicSurvey['status'] ?? '') !== 'active'): ?>

        <div class="text-center py-16">
            <h1 class="text-xl font-bold mb-3">
                <?= survey_h($publicSurvey['title']) ?>
            </h1>
            <p class="text-slate-500">
                このアンケートは現在回答できません。
            </p>
        </div>

<?php else: ?>

        <h1 class="text-2xl font-bold mb-8">
            <?= survey_h($publicSurvey['title']) ?>
        </h1>

        <form
            id="publicForm"
            class="space-y-8"
            onsubmit="return App.public.submit(event)"
        >

            <div class="grid md:grid-cols-2 gap-4">
                <label class="block">
                    <span class="block text-sm font-medium mb-2">
                        会社名
                    </span>
                    <input
                        id="publicCompany"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2.5 outline-none focus:ring-2 focus:ring-indigo-200"
                    >
                </label>

                <label class="block">
                    <span class="block text-sm font-medium mb-2">
                        氏名
                    </span>
                    <input
                        id="publicName"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2.5 outline-none focus:ring-2 focus:ring-indigo-200"
                    >
                </label>
            </div>

            <label class="block">
                <span class="block text-sm font-medium mb-2">
                    メールアドレス
                </span>
                <input
                    id="publicEmail"
                    type="email"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2.5 outline-none focus:ring-2 focus:ring-indigo-200"
                >
            </label>

<?php
$questionNo = 0;

foreach (
    $publicSurvey['groups'] ?? []
    as $group
):
?>
            <section class="border-t border-slate-200 pt-7">
                <h2 class="text-lg font-bold mb-5">
                    <?= survey_h($group['name'] ?? 'グループ') ?>
                </h2>

                <div class="space-y-7">
<?php
foreach (
    $group['questions'] ?? []
    as $question
):
    $questionNo++;
    $qid =
        (string)(
            $question['id'] ??
            ('q' . $questionNo)
        );

    $type =
        $question['type'] ?? 'single';

    $options =
        is_array(
            $question['options'] ?? null
        )
            ? $question['options']
            : [];
?>
                    <div>
                        <div class="font-medium mb-3">
                            <span class="text-indigo-600 mr-1">
                                Q<?= $questionNo ?>
                            </span>
                            <?= survey_h($question['text'] ?? '') ?>

<?php if (!empty($question['required'])): ?>
                            <span class="text-red-500 text-xs ml-2">
                                必須
                            </span>
<?php endif; ?>
                        </div>

<?php if ($type === 'text'): ?>

                        <textarea
                            data-qid="<?= survey_h($qid) ?>"
                            data-required="<?= !empty($question['required']) ? '1' : '0' ?>"
                            rows="5"
                            class="answer w-full border border-slate-300 rounded-lg px-3 py-2.5"
                        ></textarea>

<?php elseif ($type === 'multiple'): ?>

                        <div class="space-y-2">
<?php foreach ($options as $option): ?>
                            <label class="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    class="answer"
                                    data-qid="<?= survey_h($qid) ?>"
                                    data-required="<?= !empty($question['required']) ? '1' : '0' ?>"
                                    value="<?= survey_h($option) ?>"
                                >
                                <span><?= survey_h($option) ?></span>
                            </label>
<?php endforeach; ?>
                        </div>

<?php else: ?>

                        <div class="space-y-2">
<?php foreach ($options as $option): ?>
                            <label class="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="q_<?= survey_h($qid) ?>"
                                    class="answer"
                                    data-qid="<?= survey_h($qid) ?>"
                                    data-required="<?= !empty($question['required']) ? '1' : '0' ?>"
                                    value="<?= survey_h($option) ?>"
                                >
                                <span><?= survey_h($option) ?></span>
                            </label>
<?php endforeach; ?>
                        </div>

<?php endif; ?>
                    </div>
<?php endforeach; ?>
                </div>
            </section>
<?php endforeach; ?>

            <input
                type="hidden"
                id="csrf_token"
                value="<?= survey_h($csrf) ?>"
            >

            <button
                type="submit"
                class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-xl py-3 transition"
            >
                回答を送信する
            </button>

            <div
                id="publicMessage"
                class="hidden rounded-lg p-4 text-sm"
            ></div>

        </form>

<?php endif; ?>

    </div>
</div>

<script>
window.App = window.App || {};

App.public = {
    submit: async function(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const answers = {};
        const elements = form.querySelectorAll('.answer');
        const required = {};

        elements.forEach(function(el) {
            const qid = el.dataset.qid;

            if (el.dataset.required === '1') {
                required[qid] = true;
            }

            if (el.type === 'radio') {
                if (el.checked) {
                    answers[qid] = el.value;
                }
            } else if (el.type === 'checkbox') {
                if (!Array.isArray(answers[qid])) {
                    answers[qid] = [];
                }

                if (el.checked) {
                    answers[qid].push(el.value);
                }
            } else {
                answers[qid] = el.value;
            }
        });

        for (const qid of Object.keys(required)) {
            const value = answers[qid];

            if (
                value === undefined ||
                value === '' ||
                (Array.isArray(value) && value.length === 0)
            ) {
                alert('必須回答の設問が未回答です。');
                return false;
            }
        }

        const body = new URLSearchParams();

        body.set('action', 'submit_response');
        body.set(
            'csrf_token',
            document.getElementById('csrf_token').value
        );
        body.set(
            'survey_id',
            <?= json_encode($surveyId, JSON_UNESCAPED_UNICODE) ?>
        );
        body.set(
            'company',
            document.getElementById('publicCompany').value
        );
        body.set(
            'name',
            document.getElementById('publicName').value
        );
        body.set(
            'email',
            document.getElementById('publicEmail').value
        );
        body.set(
            'answers',
            JSON.stringify(answers)
        );

        try {
            const response = await fetch(
                location.href,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body: body
                }
            );

            const json = await response.json();

            if (!response.ok || !json.ok) {
                throw new Error(
                    json.message ||
                    '回答送信に失敗しました。'
                );
            }

            form.innerHTML =
                '<div class="text-center py-16">' +
                '<div class="text-5xl mb-5">✓</div>' +
                '<h2 class="text-2xl font-bold mb-3">' +
                '回答ありがとうございました' +
                '</h2>' +
                '<p class="text-slate-500">' +
                '回答を正常に受け付けました。' +
                '</p>' +
                '</div>';

        } catch (error) {
            const box =
                document.getElementById(
                    'publicMessage'
                );

            box.className =
                'rounded-lg p-4 text-sm bg-red-50 text-red-700';

            box.textContent =
                error.message;

            box.classList.remove('hidden');
        }

        return false;
    }
};
</script>

</body>
</html>
<?php
exit;
}

/* ============================================================
 * 管理SPA
 *
 * ★重要:
 * csrf_tokenをApp描画後ではなく、
 * PHP初期HTMLで必ず生成する。
 *
 * これが今回の「画面が出ない」問題の修正箇所。
 * ============================================================ */

$csrf = survey_token();
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-slate-50 text-slate-800 min-h-screen">

<!-- 初期HTMLで必ず存在させる -->
<input
    type="hidden"
    id="csrf_token"
    value="<?= survey_h($csrf) ?>"
>

<div id="app"></div>

<script>
'use strict';

/* ============================================================
 * window.App — 唯一のアプリケーション名前空間
 * ============================================================ */

window.App = {
    state: {
        data: null,
        screen: 'list',
        editingSurvey: null,
        currentSurveyId: '',
        responseSurveyId: '',
        selectedQuestions: {},
        responseFilter: '',
        customerFilter: '',
        loading: false,
        initialized: false
    },

    api: {},

    actions: {},

    render: {},

    util: {},

    survey: {},

    settings: {},

    aggregate: {},

    mail: {},

    public: {},

    init: async function() {
        if (App.state.initialized) {
            return;
        }

        App.state.initialized = true;

        try {
            await App.actions.load();
        } catch (error) {
            App.render.error(
                error.message
            );
        }
    }
};

/* ============================================================
 * Utility
 * ============================================================ */

App.util.esc = function(value) {
    return String(
        value === null ||
        value === undefined
            ? ''
            : value
    )
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
};

App.util.uid = function(prefix) {
    return (
        prefix +
        '_' +
        Date.now().toString(36) +
        '_' +
        Math.random()
            .toString(36)
            .slice(2, 9)
    );
};

App.util.formatDate = function(value) {
    if (!value) {
        return '未設定';
    }

    return String(value)
        .replace(
            /^(\d{4})-(\d{2})-(\d{2}).*$/,
            '$1/$2/$3'
        );
};

App.util.statusText = function(status) {
    const map = {
        active: '公開中',
        draft: '下書き',
        ended: '終了'
    };

    return map[status] || status;
};

App.util.statusClass = function(status) {
    const map = {
        active:
            'bg-emerald-50 text-emerald-700 border-emerald-200',
        draft:
            'bg-amber-50 text-amber-700 border-amber-200',
        ended:
            'bg-slate-100 text-slate-600 border-slate-200'
    };

    return map[status] ||
        'bg-slate-100 text-slate-600';
};

App.util.clone = function(value) {
    return JSON.parse(
        JSON.stringify(value)
    );
};

/* ============================================================
 * API
 * ============================================================ */

App.api.request = async function(
    action,
    params = {}
) {
    const csrfEl =
        document.getElementById(
            'csrf_token'
        );

    if (!csrfEl) {
        throw new Error(
            'CSRFトークン要素(#csrf_token)が見つかりません。'
        );
    }

    const body =
        new URLSearchParams();

    body.set(
        'action',
        action
    );

    body.set(
        'csrf_token',
        csrfEl.value
    );

    Object.entries(params)
        .forEach(function(
            [key, value]
        ) {
            if (
                Array.isArray(value) ||
                (
                    typeof value ===
                    'object' &&
                    value !== null
                )
            ) {
                body.set(
                    key,
                    JSON.stringify(value)
                );
            } else {
                body.set(
                    key,
                    value === null ||
                    value === undefined
                        ? ''
                        : String(value)
                );
            }
        });

    const response =
        await fetch(
            location.href,
            {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: body
            }
        );

    let json;

    try {
        json = await response.json();
    } catch (error) {
        throw new Error(
            'サーバーからJSON応答を取得できませんでした。'
        );
    }

    if (
        !response.ok ||
        !json.ok
    ) {
        throw new Error(
            json.message ||
            'サーバー処理に失敗しました。'
        );
    }

    return json;
};

/* ============================================================
 * Load
 * ============================================================ */

App.actions.load = async function() {
    App.state.loading = true;

    const result =
        await App.api.request(
            'get_data'
        );

    App.state.data =
        result.data;

    App.state.loading = false;

    App.render.current();
};

/* ============================================================
 * Main rendering
 * ============================================================ */

App.render.current = function() {
    const app =
        document.getElementById('app');

    if (!app) {
        return;
    }

    if (App.state.screen === 'list') {
        App.render.list();
        return;
    }

    if (
        App.state.screen ===
        'editor'
    ) {
        App.render.editor();
        return;
    }

    if (
        App.state.screen ===
        'aggregate'
    ) {
        App.render.aggregate();
        return;
    }

    if (
        App.state.screen ===
        'mail'
    ) {
        App.render.mail();
        return;
    }

    if (
        App.state.screen ===
        'settings'
    ) {
        App.render.settings();
        return;
    }
};

App.render.error = function(message) {
    const app =
        document.getElementById('app');

    if (!app) {
        return;
    }

    app.innerHTML = `
        <div class="min-h-screen flex items-center justify-center p-6">
            <div class="max-w-xl w-full bg-white border border-red-200 rounded-2xl shadow-sm p-8">
                <div class="text-red-600 text-4xl mb-4">!</div>
                <h1 class="text-xl font-bold mb-3">
                    アプリケーションの初期化に失敗しました
                </h1>
                <pre class="whitespace-pre-wrap text-sm text-red-700 bg-red-50 rounded-lg p-4">${App.util.esc(message)}</pre>
                <button
                    class="mt-5 px-4 py-2 rounded-lg bg-indigo-600 text-white"
                    onclick="location.reload()"
                >
                    再読み込み
                </button>
            </div>
        </div>
    `;
};

/* ============================================================
 * Header
 * ============================================================ */

App.render.header = function(
    active
) {
    return `
        <header class="bg-white border-b border-slate-200 sticky top-0 z-40">
            <div class="max-w-7xl mx-auto px-4">
                <div class="h-16 flex items-center justify-between gap-4">
                    <button
                        class="font-bold text-lg text-slate-900"
                        onclick="App.actions.goList()"
                    >
                        アンケート管理
                    </button>

                    <nav class="flex items-center gap-1">
                        <button
                            class="px-3 py-2 rounded-lg text-sm ${
                                active === 'list'
                                    ? 'bg-indigo-50 text-indigo-700'
                                    : 'text-slate-600 hover:bg-slate-100'
                            }"
                            onclick="App.actions.goList()"
                        >
                            アンケート一覧
                        </button>

                        <button
                            class="px-3 py-2 rounded-lg text-sm ${
                                active === 'settings'
                                    ? 'bg-indigo-50 text-indigo-700'
                                    : 'text-slate-600 hover:bg-slate-100'
                            }"
                            onclick="App.actions.goSettings()"
                        >
                            kintone連携設定
                        </button>
                    </nav>
                </div>
            </div>
        </header>
    `;
};

/* ============================================================
 * List
 * ============================================================ */

App.render.list = function() {
    const data =
        App.state.data || {};

    const surveys =
        (data.surveys || [])
            .filter(function(s) {
                return !s.deleted;
            });

    const keyword =
        App.state.listKeyword || '';

    const statusFilter =
        App.state.listStatus || '';

    const sort =
        App.state.listSort ||
        'updated_desc';

    let filtered =
        surveys.filter(function(s) {
            const hitKeyword =
                !keyword ||
                String(
                    s.title || ''
                )
                .toLowerCase()
                .includes(
                    keyword.toLowerCase()
                );

            const hitStatus =
                !statusFilter ||
                s.status === statusFilter;

            return (
                hitKeyword &&
                hitStatus
            );
        });

    filtered.sort(
        function(a, b) {
            if (
                sort ===
                'answers_desc'
            ) {
                return (
                    App.aggregate.count(
                        b.id
                    ) -
                    App.aggregate.count(
                        a.id
                    )
                );
            }

            if (
                sort ===
                'answers_asc'
            ) {
                return (
                    App.aggregate.count(
                        a.id
                    ) -
                    App.aggregate.count(
                        b.id
                    )
                );
            }

            const key =
                sort.startsWith(
                    'start'
                )
                    ? 'start_at'
                    : 'updated_at';

            const av =
                a[key] || '';

            const bv =
                b[key] || '';

            return sort.endsWith(
                '_asc'
            )
                ? av.localeCompare(bv)
                : bv.localeCompare(av);
        }
    );

    document.getElementById(
        'app'
    ).innerHTML = `
        ${App.render.header('list')}

        <main class="max-w-7xl mx-auto px-4 py-8">

            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-7">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900">
                        アンケート一覧
                    </h1>
                    <p class="text-sm text-slate-500 mt-1">
                        アンケートの作成・公開・集計・送信を管理します。
                    </p>
                </div>

                <button
                    class="px-5 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold shadow-sm"
                    onclick="App.actions.newSurvey()"
                >
                    ＋ 新規アンケート作成
                </button>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-4 mb-5">
                <div class="grid md:grid-cols-3 gap-3">

                    <input
                        id="listKeyword"
                        value="${App.util.esc(keyword)}"
                        placeholder="タイトルを検索"
                        class="border border-slate-300 rounded-lg px-3 py-2.5"
                        onkeydown="App.actions.searchKey(event)"
                    >

                    <select
                        class="border border-slate-300 rounded-lg px-3 py-2.5"
                        onchange="App.actions.setListStatus(this.value)"
                    >
                        <option value="">すべて</option>
                        <option value="active" ${statusFilter === 'active' ? 'selected' : ''}>
                            公開中
                        </option>
                        <option value="draft" ${statusFilter === 'draft' ? 'selected' : ''}>
                            下書き
                        </option>
                        <option value="ended" ${statusFilter === 'ended' ? 'selected' : ''}>
                            終了
                        </option>
                    </select>

                    <select
                        class="border border-slate-300 rounded-lg px-3 py-2.5"
                        onchange="App.actions.setListSort(this.value)"
                    >
                        <option value="updated_desc" ${sort === 'updated_desc' ? 'selected' : ''}>
                            更新日：新しい順
                        </option>
                        <option value="updated_asc" ${sort === 'updated_asc' ? 'selected' : ''}>
                            更新日：古い順
                        </option>
                        <option value="answers_desc" ${sort === 'answers_desc' ? 'selected' : ''}>
                            回答数：多い順
                        </option>
                        <option value="answers_asc" ${sort === 'answers_asc' ? 'selected' : ''}>
                            回答数：少ない順
                        </option>
                        <option value="start_desc" ${sort === 'start_desc' ? 'selected' : ''}>
                            開始日：新しい順
                        </option>
                        <option value="start_asc" ${sort === 'start_asc' ? 'selected' : ''}>
                            開始日：古い順
                        </option>
                    </select>

                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1050px] text-sm">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="text-left p-4">作成日 / 更新日</th>
                                <th class="text-left p-4">タイトル</th>
                                <th class="text-left p-4">期間</th>
                                <th class="text-left p-4">ステータス</th>
                                <th class="text-right p-4">回答数</th>
                                <th class="text-right p-4">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${
                                filtered.length
                                    ? filtered.map(
                                        App.render.surveyRow
                                      ).join('')
                                    : `
                                        <tr>
                                            <td colspan="6" class="p-12 text-center text-slate-500">
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

App.render.surveyRow = function(survey) {
    const count =
        App.aggregate.count(
            survey.id
        );

    let buttons = '';

    if (
        survey.status ===
        'active'
    ) {
        buttons = `
            <button
                class="px-2.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200"
                onclick="App.actions.editSurvey('${survey.id}')"
            >
                確認・編集
            </button>
            <button
                class="px-2.5 py-1.5 rounded-lg bg-indigo-50 text-indigo-700"
                onclick="App.actions.aggregate('${survey.id}')"
            >
                集計
            </button>
            <button
                class="px-2.5 py-1.5 rounded-lg bg-violet-50 text-violet-700"
                onclick="App.actions.mail('${survey.id}')"
            >
                送信
            </button>
            <button
                class="px-2.5 py-1.5 rounded-lg bg-rose-50 text-rose-700"
                onclick="App.actions.stop('${survey.id}')"
            >
                停止
            </button>
            <button
                class="px-2.5 py-1.5 rounded-lg bg-slate-100"
                onclick="App.actions.duplicate('${survey.id}')"
            >
                複製
            </button>
        `;
    } else if (
        survey.status ===
        'draft'
    ) {
        buttons = `
            <button
                class="px-2.5 py-1.5 rounded-lg bg-slate-100"
                onclick="App.actions.editSurvey('${survey.id}')"
            >
                確認・編集
            </button>
            <button
                class="px-2.5 py-1.5 rounded-lg bg-rose-50 text-rose-700"
                onclick="App.actions.deleteSurvey('${survey.id}')"
            >
                削除
            </button>
            <button
                class="px-2.5 py-1.5 rounded-lg bg-slate-100"
                onclick="App.actions.duplicate('${survey.id}')"
            >
                複製
            </button>
        `;
    } else {
        buttons = `
            <button
                class="px-2.5 py-1.5 rounded-lg bg-slate-100"
                onclick="App.actions.editSurvey('${survey.id}')"
            >
                閲覧
            </button>
            <button
                class="px-2.5 py-1.5 rounded-lg bg-indigo-50 text-indigo-700"
                onclick="App.actions.aggregate('${survey.id}')"
            >
                集計
            </button>
            <button
                class="px-2.5 py-1.5 rounded-lg bg-slate-100"
                onclick="App.actions.duplicate('${survey.id}')"
            >
                複製
            </button>
        `;
    }

    return `
        <tr class="border-b border-slate-100 hover:bg-slate-50">
            <td class="p-4 whitespace-nowrap">
                <div>
                    ${App.util.esc(
                        App.util.formatDate(
                            survey.created_at
                        )
                    )}
                </div>
                <div class="text-xs text-slate-400 mt-1">
                    更新:
                    ${App.util.esc(
                        App.util.formatDate(
                            survey.updated_at
                        )
                    )}
                </div>
            </td>

            <td class="p-4">
                <div class="font-bold">
                    ${App.util.esc(
                        survey.title
                    )}
                </div>
            </td>

            <td class="p-4 whitespace-nowrap">
                ${
                    survey.start_at ||
                    survey.end_at
                        ? `
                            ${App.util.esc(
                                survey.start_at ||
                                '未設定'
                            )}
                            ～ 
                            ${App.util.esc(
                                survey.end_at ||
                                '未設定'
                            )}
                        `
                        : '未設定'
                }
            </td>

            <td class="p-4">
                <span class="inline-flex px-2.5 py-1 rounded-full border text-xs font-semibold ${App.util.statusClass(survey.status)}">
                    ${App.util.statusText(
                        survey.status
                    )}
                </span>
            </td>

            <td class="p-4 text-right font-semibold">
                ${count} 件
            </td>

            <td class="p-4">
                <div class="flex justify-end flex-wrap gap-1.5">
                    ${buttons}
                </div>
            </td>
        </tr>
    `;
};

/* ============================================================
 * List actions
 * ============================================================ */

App.actions.searchKey = function(event) {
    if (
        event.key ===
        'Enter'
    ) {
        App.state.listKeyword =
            document.getElementById(
                'listKeyword'
            ).value;

        App.render.list();
    }
};

App.actions.setListStatus =
    function(value) {
        App.state.listStatus =
            value;

        App.render.list();
    };

App.actions.setListSort =
    function(value) {
        App.state.listSort =
            value;

        App.render.list();
    };

App.actions.goList = function() {
    App.state.screen =
        'list';

    App.render.current();
};

App.actions.newSurvey =
    function() {
        App.state.editingSurvey =
            App.survey.blank();

        App.state.screen =
            'editor';

        App.render.editor();
    };

App.actions.editSurvey =
    function(id) {
        const survey =
            App.state.data.surveys.find(
                function(s) {
                    return (
                        s.id === id
                    );
                }
            );

        if (!survey) {
            alert(
                'アンケートが見つかりません。'
            );
            return;
        }

        App.state.editingSurvey =
            App.util.clone(
                survey
            );

        App.state.screen =
            'editor';

        App.render.editor();
    };

App.actions.stop =
    async function(id) {
        if (
            !confirm(
                'このアンケートを停止しますか？'
            )
        ) {
            return;
        }

        await App.api.request(
            'status',
            {
                survey_id: id,
                status: 'ended'
            }
        );

        await App.actions.load();
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

        await App.api.request(
            'delete_survey',
            {
                survey_id: id
            }
        );

        await App.actions.load();
    };

App.actions.duplicate =
    async function(id) {
        const original =
            App.state.data.surveys.find(
                function(s) {
                    return s.id === id;
                }
            );

        if (!original) {
            return;
        }

        const copy =
            App.util.clone(
                original
            );

        copy.id =
            App.util.uid('survey');

        copy.title =
            String(
                copy.title || ''
            ) +
            '（複製）';

        copy.status =
            'draft';

        copy.created_at =
            '';

        copy.updated_at =
            '';

        copy.deleted =
            false;

        await App.api.request(
            'save_survey',
            {
                survey_json: copy
            }
        );

        await App.actions.load();

        alert(
            '下書きとして複製しました。'
        );
    };

/* ============================================================
 * Survey model
 * ============================================================ */

App.survey.blank =
    function() {
        return {
            id:
                App.util.uid(
                    'survey'
                ),
            title: '新しいアンケート',
            start_at: '',
            end_at: '',
            status: 'draft',
            created_at: '',
            updated_at: '',
            numbering_mode:
                'global',
            groups: [
                {
                    id:
                        App.util.uid(
                            'group'
                        ),
                    name:
                        'グループ1',
                    questions: []
                }
            ],
            deleted: false
        };
    };

App.survey.addGroup =
    function() {
        App.state.editingSurvey.groups
            .push({
                id:
                    App.util.uid(
                        'group'
                    ),
                name:
                    '新しいグループ',
                questions: []
            });

        App.render.editor();

        setTimeout(
            App.survey.initSortable,
            0
        );
    };

App.survey.addQuestion =
    function(groupId) {
        const group =
            App.state.editingSurvey.groups
                .find(
                    function(g) {
                        return (
                            g.id ===
                            groupId
                        );
                    }
                );

        if (!group) {
            return;
        }

        group.questions.push({
            id:
                App.util.uid(
                    'question'
                ),
            text:
                '新しい質問',
            type:
                'single',
            required:
                false,
            options: [
                '選択肢1',
                '選択肢2'
            ],
            other_enabled:
                false
        });

        App.render.editor();

        setTimeout(
            App.survey.initSortable,
            0
        );
    };

App.survey.deleteGroup =
    function(groupId) {
        if (
            !confirm(
                'グループと内包される質問を削除しますか？'
            )
        ) {
            return;
        }

        App.state.editingSurvey.groups =
            App.state.editingSurvey.groups
                .filter(
                    function(g) {
                        return (
                            g.id !==
                            groupId
                        );
                    }
                );

        App.render.editor();

        setTimeout(
            App.survey.initSortable,
            0
        );
    };

App.survey.deleteQuestion =
    function(groupId, questionId) {
        const group =
            App.state.editingSurvey.groups
                .find(
                    function(g) {
                        return (
                            g.id ===
                            groupId
                        );
                    }
                );

        if (!group) {
            return;
        }

        group.questions =
            group.questions.filter(
                function(q) {
                    return (
                        q.id !==
                        questionId
                    );
                }
            );

        App.render.editor();

        setTimeout(
            App.survey.initSortable,
            0
        );
    };

App.survey.addOption =
    function(groupId, questionId) {
        const q =
            App.survey.findQuestion(
                groupId,
                questionId
            );

        if (!q) {
            return;
        }

        q.options =
            Array.isArray(q.options)
                ? q.options
                : [];

        q.options.push(
            '新しい選択肢'
        );

        App.render.editor();

        setTimeout(
            App.survey.initSortable,
            0
        );
    };

App.survey.removeOption =
    function(
        groupId,
        questionId,
        index
    ) {
        const q =
            App.survey.findQuestion(
                groupId,
                questionId
            );

        if (!q) {
            return;
        }

        q.options.splice(
            index,
            1
        );

        App.render.editor();

        setTimeout(
            App.survey.initSortable,
            0
        );
    };

App.survey.findQuestion =
    function(
        groupId,
        questionId
    ) {
        const group =
            App.state.editingSurvey.groups
                .find(
                    function(g) {
                        return (
                            g.id ===
                            groupId
                        );
                    }
                );

        if (!group) {
            return null;
        }

        return (
            group.questions.find(
                function(q) {
                    return (
                        q.id ===
                        questionId
                    );
                }
            ) || null
        );
    };

App.survey.renumber =
    function() {
        let n = 0;

        App.state.editingSurvey.groups
            .forEach(
                function(group) {
                    group.questions
                        .forEach(
                            function(q) {
                                n++;
                                q.number =
                                    n;
                            }
                        );
                }
            );
    };

/* ============================================================
 * Editor
 * ============================================================ */

App.render.editor =
    function() {
        const survey =
            App.state.editingSurvey;

        if (!survey) {
            return;
        }

        App.survey.renumber();

        document.getElementById(
            'app'
        ).innerHTML = `
            ${App.render.header('list')}

            <main class="max-w-6xl mx-auto px-4 py-7">

                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                    <div>
                        <div class="text-sm text-slate-500 mb-1">
                            ホーム ＞ アンケート一覧 ＞ 編集
                        </div>
                        <h1 class="text-2xl font-bold">
                            アンケート作成・編集
                        </h1>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button
                            class="px-4 py-2 rounded-lg bg-slate-100"
                            onclick="App.actions.preview()"
                        >
                            プレビュー
                        </button>

                        <button
                            class="px-4 py-2 rounded-lg bg-slate-100"
                            onclick="App.actions.cancelEdit()"
                        >
                            キャンセル
                        </button>

                        <button
                            class="px-4 py-2 rounded-lg bg-indigo-600 text-white font-semibold"
                            onclick="App.actions.saveSurvey()"
                        >
                            保存して一覧へ戻る
                        </button>
                    </div>
                </div>

                <section class="bg-white border border-slate-200 rounded-2xl p-5 mb-5">
                    <div class="grid md:grid-cols-3 gap-4">

                        <label class="md:col-span-3">
                            <span class="block text-sm font-medium mb-2">
                                アンケートタイトル
                            </span>
                            <input
                                id="survey_title"
                                value="${App.util.esc(survey.title)}"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-lg font-semibold"
                                oninput="App.actions.updateSurveyField('title',this.value)"
                            >
                        </label>

                        <label>
                            <span class="block text-sm font-medium mb-2">
                                開始日時
                            </span>
                            <input
                                id="survey_start_at"
                                type="datetime-local"
                                value="${App.util.esc(survey.start_at)}"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2.5"
                                onchange="App.actions.updateSurveyField('start_at',this.value)"
                            >
                        </label>

                        <label>
                            <span class="block text-sm font-medium mb-2">
                                終了日時
                            </span>
                            <input
                                id="survey_end_at"
                                type="datetime-local"
                                value="${App.util.esc(survey.end_at)}"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2.5"
                                onchange="App.actions.updateSurveyField('end_at',this.value)"
                            >
                        </label>

                        <label>
                            <span class="block text-sm font-medium mb-2">
                                質問番号方式
                            </span>
                            <select
                                id="survey_numbering_mode"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2.5"
                                onchange="App.actions.updateSurveyField('numbering_mode',this.value)"
                            >
                                <option value="global" ${survey.numbering_mode === 'global' ? 'selected' : ''}>
                                    Q1, Q2, Q3...
                                </option>
                                <option value="group" ${survey.numbering_mode === 'group' ? 'selected' : ''}>
                                    Q1-1, Q1-2...
                                </option>
                            </select>
                        </label>

                    </div>
                </section>

                <div class="space-y-5" id="question_editor">

                    ${
                        survey.groups.map(
                            function(group, gi) {
                                return App.render.group(
                                    group,
                                    gi
                                );
                            }
                        ).join('')
                    }

                </div>

                <button
                    class="mt-5 w-full py-3 rounded-xl border-2 border-dashed border-slate-300 hover:border-indigo-400 hover:text-indigo-600"
                    onclick="App.survey.addGroup()"
                >
                    ＋ グループを追加
                </button>

            </main>

            <div
                id="preview_modal"
                class="hidden fixed inset-0 bg-black/50 z-50 p-4 overflow-auto"
            ></div>
        `;

        setTimeout(
            App.survey.initSortable,
            0
        );
    };

App.render.group =
    function(
        group,
        groupIndex
    ) {
        return `
            <section
                class="survey-group bg-white border border-slate-200 rounded-2xl p-5"
                data-group-id="${App.util.esc(group.id)}"
            >

                <div class="flex items-center gap-3 mb-5">
                    <span class="drag-group cursor-grab text-xl">
                        ⠿
                    </span>

                    <input
                        value="${App.util.esc(group.name)}"
                        class="flex-1 border border-slate-300 rounded-lg px-3 py-2 font-semibold"
                        oninput="App.actions.updateGroupName('${group.id}',this.value)"
                    >

                    <button
                        class="px-3 py-2 rounded-lg bg-rose-50 text-rose-700"
                        onclick="App.survey.deleteGroup('${group.id}')"
                    >
                        グループ削除
                    </button>
                </div>

                <div
                    class="question-list space-y-4"
                    data-group-id="${App.util.esc(group.id)}"
                >

                    ${
                        group.questions.map(
                            function(q) {
                                return App.render.question(
                                    group.id,
                                    q
                                );
                            }
                        ).join('')
                    }

                </div>

                <button
                    class="mt-4 px-4 py-2 rounded-lg bg-indigo-50 text-indigo-700"
                    onclick="App.survey.addQuestion('${group.id}')"
                >
                    ＋ 質問を追加
                </button>

            </section>
        `;
    };

App.render.question =
    function(
        groupId,
        q
    ) {
        const options =
            Array.isArray(q.options)
                ? q.options
                : [];

        return `
            <div
                class="question-card border border-slate-200 rounded-xl p-4 bg-slate-50"
                data-question-id="${App.util.esc(q.id)}"
            >

                <div class="flex items-start gap-3">

                    <span class="drag-question cursor-grab text-lg pt-1">
                        ⠿
                    </span>

                    <div class="flex-1">

                        <div class="flex items-center justify-between gap-3 mb-3">
                            <span class="font-bold text-indigo-600">
                                Q${q.number || ''}
                            </span>

                            <button
                                class="text-xs text-rose-600"
                                onclick="App.survey.deleteQuestion('${groupId}','${q.id}')"
                            >
                                質問削除
                            </button>
                        </div>

                        <input
                            value="${App.util.esc(q.text)}"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2.5 bg-white mb-3"
                            oninput="App.actions.updateQuestion('${groupId}','${q.id}','text',this.value)"
                        >

                        <div class="grid md:grid-cols-2 gap-3 mb-3">

                            <select
                                class="border border-slate-300 rounded-lg px-3 py-2.5 bg-white"
                                onchange="App.actions.updateQuestion('${groupId}','${q.id}','type',this.value)"
                            >
                                <option value="single" ${q.type === 'single' ? 'selected' : ''}>
                                    単一選択
                                </option>
                                <option value="multiple" ${q.type === 'multiple' ? 'selected' : ''}>
                                    複数選択
                                </option>
                                <option value="text" ${q.type === 'text' ? 'selected' : ''}>
                                    自由記述
                                </option>
                            </select>

                            <label class="flex items-center gap-2 bg-white border border-slate-300 rounded-lg px-3 py-2.5">
                                <input
                                    type="checkbox"
                                    ${q.required ? 'checked' : ''}
                                    onchange="App.actions.updateQuestion('${groupId}','${q.id}','required',this.checked)"
                                >
                                <span>
                                    必須回答
                                </span>
                            </label>

                        </div>

                        ${
                            q.type !== 'text'
                                ? `
                                    <div class="bg-white border border-slate-200 rounded-lg p-3">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-sm font-medium">
                                                選択肢
                                            </span>

                                            <button
                                                class="text-sm text-indigo-600"
                                                onclick="App.survey.addOption('${groupId}','${q.id}')"
                                            >
                                                ＋追加
                                            </button>
                                        </div>

                                        <div class="space-y-2">
                                            ${
                                                options.map(
                                                    function(option, oi) {
                                                        return `
                                                            <div class="flex gap-2">
                                                                <input
                                                                    value="${App.util.esc(option)}"
                                                                    class="flex-1 border border-slate-300 rounded-lg px-3 py-2"
                                                                    oninput="App.actions.updateOption('${groupId}','${q.id}',${oi},this.value)"
                                                                >
                                                                <button
                                                                    class="px-3 rounded-lg bg-rose-50 text-rose-700"
                                                                    onclick="App.survey.removeOption('${groupId}','${q.id}',${oi})"
                                                                >
                                                                    ×
                                                                </button>
                                                            </div>
                                                        `;
                                                    }
                                                ).join('')
                                            }
                                        </div>

                                        <label class="flex items-center gap-2 mt-3 text-sm">
                                            <input
                                                type="checkbox"
                                                ${q.other_enabled ? 'checked' : ''}
                                                onchange="App.actions.updateQuestion('${groupId}','${q.id}','other_enabled',this.checked)"
                                            >
                                            その他（自由記述）を許可
                                        </label>
                                    </div>
                                `
                                : ''
                        }

                    </div>
                </div>
            </div>
        `;
    };

/* ============================================================
 * Editor actions
 * ============================================================ */

App.actions.updateSurveyField =
    function(
        field,
        value
    ) {
        App.state.editingSurvey[field] =
            value;
    };

App.actions.updateGroupName =
    function(
        groupId,
        value
    ) {
        const group =
            App.state.editingSurvey.groups
                .find(
                    function(g) {
                        return (
                            g.id ===
                            groupId
                        );
                    }
                );

        if (group) {
            group.name = value;
        }
    };

App.actions.updateQuestion =
    function(
        groupId,
        questionId,
        field,
        value
    ) {
        const q =
            App.survey.findQuestion(
                groupId,
                questionId
            );

        if (!q) {
            return;
        }

        q[field] = value;

        if (
            field === 'type'
        ) {
            if (
                value === 'text'
            ) {
                q.options = [];
            } else if (
                !Array.isArray(
                    q.options
                ) ||
                !q.options.length
            ) {
                q.options = [
                    '選択肢1',
                    '選択肢2'
                ];
            }

            App.render.editor();

            setTimeout(
                App.survey.initSortable,
                0
            );
        }
    };

App.actions.updateOption =
    function(
        groupId,
        questionId,
        index,
        value
    ) {
        const q =
            App.survey.findQuestion(
                groupId,
                questionId
            );

        if (q) {
            q.options[index] =
                value;
        }
    };

App.actions.saveSurvey =
    async function() {
        try {
            await App.api.request(
                'save_survey',
                {
                    survey_json:
                        App.state.editingSurvey
                }
            );

            App.state.editingSurvey =
                null;

            await App.actions.load();

            alert(
                '保存しました。'
            );
        } catch (error) {
            alert(
                error.message
            );
        }
    };

App.actions.cancelEdit =
    function() {
        if (
            !confirm(
                '変更を破棄して一覧へ戻りますか？'
            )
        ) {
            return;
        }

        App.state.editingSurvey =
            null;

        App.actions.goList();
    };

App.actions.preview =
    function() {
        const survey =
            App.state.editingSurvey;

        const modal =
            document.getElementById(
                'preview_modal'
            );

        if (!modal) {
            return;
        }

        let n = 0;

        modal.innerHTML = `
            <div class="min-h-full flex items-start justify-center py-8">
                <div class="bg-white rounded-2xl shadow-xl max-w-3xl w-full p-6">

                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold">
                            プレビュー
                        </h2>

                        <button
                            class="px-3 py-2 rounded-lg bg-slate-100"
                            onclick="App.actions.closePreview()"
                        >
                            閉じる
                        </button>
                    </div>

                    <div class="border border-slate-200 rounded-xl p-5">

                        <h1 class="text-2xl font-bold mb-7">
                            ${App.util.esc(survey.title)}
                        </h1>

                        ${
                            survey.groups.map(
                                function(group) {
                                    return `
                                        <section class="mb-7">
                                            <h3 class="font-bold text-lg mb-4">
                                                ${App.util.esc(group.name)}
                                            </h3>

                                            <div class="space-y-5">
                                                ${
                                                    group.questions.map(
                                                        function(q) {
                                                            n++;

                                                            return `
                                                                <div>
                                                                    <div class="font-medium mb-2">
                                                                        Q${n}.
                                                                        ${App.util.esc(q.text)}
                                                                    </div>

                                                                    ${
                                                                        q.type === 'text'
                                                                            ? `
                                                                                <textarea
                                                                                    disabled
                                                                                    class="w-full border border-slate-300 rounded-lg p-3"
                                                                                    rows="3"
                                                                                ></textarea>
                                                                            `
                                                                            :
                                                                            `
                                                                                <div class="space-y-2">
                                                                                    ${
                                                                                        (q.options || []).map(
                                                                                            function(o) {
                                                                                                return `
                                                                                                    <label class="flex gap-2">
                                                                                                        <input
                                                                                                            type="${q.type === 'multiple' ? 'checkbox' : 'radio'}"
                                                                                                            disabled
                                                                                                        >
                                                                                                        ${App.util.esc(o)}
                                                                                                    </label>
                                                                                                `;
                                                                                            }
                                                                                        ).join('')
                                                                                    }
                                                                                </div>
                                                                            `
                                                                    }
                                                                </div>
                                                            `;
                                                        }
                                                    ).join('')
                                                }
                                            </div>
                                        </section>
                                    `;
                                }
                            ).join('')
                        }

                        <button
                            class="w-full bg-indigo-600 text-white rounded-xl py-3"
                            onclick="alert('これはプレビューです。実際には送信されません。')"
                        >
                            回答を送信する
                        </button>

                    </div>
                </div>
            </div>
        `;

        modal.classList.remove(
            'hidden'
        );
    };

App.actions.closePreview =
    function() {
        const modal =
            document.getElementById(
                'preview_modal'
            );

        if (modal) {
            modal.classList.add(
                'hidden'
            );
        }
    };

/* ============================================================
 * SortableJS
 * ============================================================ */

App.survey.initSortable =
    function() {
        if (
            typeof Sortable ===
            'undefined'
        ) {
            return;
        }

        const editor =
            document.getElementById(
                'question_editor'
            );

        if (!editor) {
            return;
        }

        new Sortable(
            editor,
            {
                animation: 180,
                handle:
                    '.drag-group',
                ghostClass:
                    'opacity-40',
                onEnd: function() {
                    const ids =
                        Array.from(
                            editor.querySelectorAll(
                                '.survey-group'
                            )
                        )
                        .map(
                            function(el) {
                                return el.dataset.groupId;
                            }
                        );

                    App.state.editingSurvey.groups =
                        ids.map(
                            function(id) {
                                return App.state.editingSurvey.groups.find(
                                    function(g) {
                                        return g.id === id;
                                    }
                                );
                            }
                        )
                        .filter(Boolean);

                    App.survey.renumber();
                    App.render.editor();

                    setTimeout(
                        App.survey.initSortable,
                        0
                    );
                }
            }
        );

        editor.querySelectorAll(
            '.question-list'
        ).forEach(
            function(list) {
                new Sortable(
                    list,
                    {
                        group:
                            'survey-questions',
                        animation: 180,
                        handle:
                            '.drag-question',
                        ghostClass:
                            'opacity-40',

                        onEnd:
                            function() {
                                const groups =
                                    App.state.editingSurvey.groups;

                                groups.forEach(
                                    function(group) {
                                        const dom =
                                            editor.querySelector(
                                                '.question-list[data-group-id="' +
                                                CSS.escape(
                                                    group.id
                                                ) +
                                                '"]'
                                            );

                                        if (!dom) {
                                            return;
                                        }

                                        const ids =
                                            Array.from(
                                                dom.querySelectorAll(
                                                    '.question-card'
                                                )
                                            ).map(
                                                function(el) {
                                                    return el.dataset.questionId;
                                                }
                                            );

                                        const old =
                                            group.questions;

                                        group.questions =
                                            ids.map(
                                                function(id) {
                                                    return old.find(
                                                        function(q) {
                                                            return q.id === id;
                                                        }
                                                    ) ||
                                                    App.state.editingSurvey.groups
                                                        .flatMap(
                                                            function(g) {
                                                                return g.questions;
                                                            }
                                                        )
                                                        .find(
                                                            function(q) {
                                                                return q.id === id;
                                                            }
                                                    );
                                                }
                                            )
                                            .filter(Boolean);
                                    }
                                );

                                App.survey.renumber();

                                App.render.editor();

                                setTimeout(
                                    App.survey.initSortable,
                                    0
                                );
                            }
                    }
                );
            }
        );
    };

/* ============================================================
 * Aggregate
 * ============================================================ */

App.aggregate.count =
    function(surveyId) {
        return (
            App.state.data &&
            Array.isArray(
                App.state.data.responses
            )
                ? App.state.data.responses.filter(
                    function(r) {
                        return (
                            r.survey_id ===
                            surveyId
                        );
                    }
                ).length
                : 0
        );
    };

App.actions.aggregate =
    function(surveyId) {
        App.state.currentSurveyId =
            surveyId;

        App.state.screen =
            'aggregate';

        App.render.aggregate();
    };

App.render.aggregate =
    function() {
        const survey =
            App.state.data.surveys.find(
                function(s) {
                    return (
                        s.id ===
                        App.state.currentSurveyId
                    );
                }
            );

        if (!survey) {
            return;
        }

        const responses =
            App.state.data.responses
                .filter(
                    function(r) {
                        return (
                            r.survey_id ===
                            survey.id
                        );
                    }
                );

        const questions = [];

        survey.groups.forEach(
            function(group) {
                group.questions.forEach(
                    function(q) {
                        questions.push(q);
                    }
                );
            }
        );

        const customers =
            App.state.data.customers;

        const sent =
            customers.filter(
                function(c) {
                    return !!c.sent_at;
                }
            ).length;

        const answered =
            responses.length;

        const external =
            responses.filter(
                function(r) {
                    return !r.customer_id;
                }
            ).length;

        const unanswered =
            Math.max(
                sent -
                responses.filter(
                    function(r) {
                        return !!r.customer_id;
                    }
                ).length,
                0
            );

        const rate =
            sent > 0
                ? (
                    answered /
                    sent *
                    100
                ).toFixed(1)
                : '0.0';

        document.getElementById(
            'app'
        ).innerHTML = `
            ${App.render.header('list')}

            <main class="max-w-7xl mx-auto px-4 py-7">

                <div class="mb-6">
                    <div class="text-sm text-slate-500 mb-1">
                        ホーム ＞ アンケート一覧 ＞ 集計
                    </div>

                    <h1 class="text-2xl font-bold">
                        ${App.util.esc(survey.title)}
                    </h1>
                </div>

                <div class="grid md:grid-cols-5 gap-3 mb-6">

                    ${[
                        ['送信対象者数', sent + ' 人'],
                        ['回答数', answered + ' 件'],
                        ['未登録顧客からの回答数', external + ' 件'],
                        ['未回答数', unanswered + ' 人'],
                        ['回答率', rate + ' %']
                    ].map(
                        function(item) {
                            return `
                                <div class="bg-white border border-slate-200 rounded-xl p-4">
                                    <div class="text-xs text-slate-500 mb-2">
                                        ${item[0]}
                                    </div>
                                    <div class="text-2xl font-bold">
                                        ${item[1]}
                                    </div>
                                </div>
                            `;
                        }
                    ).join('')}

                </div>

                ${
                    responses.length === 0
                        ? `
                            <div class="bg-white border border-slate-200 rounded-2xl p-12 text-center text-slate-500">
                                現在、回答データはありません
                            </div>
                        `
                        : ''
                }

                <div class="grid lg:grid-cols-4 gap-5">

                    <aside class="bg-white border border-slate-200 rounded-2xl p-4">
                        <div class="font-bold mb-3">
                            設問絞り込み
                        </div>

                        <div class="flex gap-2 mb-3">
                            <button
                                class="text-xs px-2 py-1 bg-slate-100 rounded"
                                onclick="App.actions.selectAllQuestions(true)"
                            >
                                全選択
                            </button>

                            <button
                                class="text-xs px-2 py-1 bg-slate-100 rounded"
                                onclick="App.actions.selectAllQuestions(false)"
                            >
                                全解除
                            </button>
                        </div>

                        <div class="space-y-2">
                            ${
                                questions.map(
                                    function(q, i) {
                                        const selected =
                                            App.state.selectedQuestions[q.id] !== false;

                                        return `
                                            <label class="flex gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    ${selected ? 'checked' : ''}
                                                    onchange="App.actions.toggleQuestion('${q.id}',this.checked)"
                                                >
                                                <span>
                                                    Q${i + 1}
                                                    ${App.util.esc(q.text)}
                                                </span>
                                            </label>
                                        `;
                                    }
                                ).join('')
                            }
                        </div>
                    </aside>

                    <section class="lg:col-span-3 space-y-5">

                        ${
                            questions.map(
                                function(q, i) {
                                    return App.aggregate.questionCard(
                                        q,
                                        i,
                                        responses
                                    );
                                }
                            ).join('')
                        }

                        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">

                            <div class="p-4 border-b border-slate-200">
                                <div class="flex flex-col md:flex-row gap-3 md:items-center md:justify-between">
                                    <h2 class="font-bold">
                                        個別回答一覧
                                    </h2>

                                    <input
                                        id="response_filter"
                                        value="${App.util.esc(App.state.responseFilter)}"
                                        oninput="App.actions.filterResponses(this.value)"
                                        placeholder="会社名・氏名で検索"
                                        class="border border-slate-300 rounded-lg px-3 py-2"
                                    >
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <table
                                    id="response_table"
                                    class="w-full min-w-[800px] text-sm"
                                >
                                    <thead class="bg-slate-50">
                                        <tr>
                                            <th class="text-left p-3">回答日時</th>
                                            <th class="text-left p-3">会社名</th>
                                            <th class="text-left p-3">氏名</th>
                                            <th class="text-left p-3">メール</th>
                                            <th class="text-right p-3">操作</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        ${
                                            responses.filter(
                                                function(r) {
                                                    const kw =
                                                        App.state.responseFilter
                                                            .toLowerCase();

                                                    return (
                                                        !kw ||
                                                        (
                                                            String(r.company || '') +
                                                            ' ' +
                                                            String(r.name || '')
                                                        )
                                                        .toLowerCase()
                                                        .includes(kw)
                                                    );
                                                }
                                            ).map(
                                                function(r) {
                                                    return `
                                                        <tr class="border-t border-slate-100">
                                                            <td class="p-3">
                                                                ${App.util.esc(r.answered_at)}
                                                            </td>
                                                            <td class="p-3">
                                                                ${App.util.esc(r.company)}
                                                            </td>
                                                            <td class="p-3">
                                                                ${App.util.esc(r.name)}
                                                            </td>
                                                            <td class="p-3">
                                                                ${App.util.esc(r.email)}
                                                            </td>
                                                            <td class="p-3 text-right">
                                                                <button
                                                                    class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700"
                                                                    onclick="App.actions.showResponse('${r.id}')"
                                                                >
                                                                    全回答を表示
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    `;
                                                }
                                            ).join('')
                                        }
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <button
                            class="px-4 py-2 rounded-lg bg-emerald-600 text-white"
                            onclick="App.actions.csv('${survey.id}')"
                        >
                            CSV出力
                        </button>

                    </section>

                </div>

            </main>

            <div
                id="response_modal"
                class="hidden fixed inset-0 bg-black/50 z-50 p-4 overflow-auto"
            ></div>
        `;
    };

App.aggregate.questionCard =
    function(
        q,
        index,
        responses
    ) {
        if (
            App.state.selectedQuestions[q.id] ===
            false
        ) {
            return '';
        }

        if (q.type === 'text') {
            const values =
                responses.map(
                    function(r) {
                        return {
                            response: r,
                            value:
                                r.answers
                                    ? r.answers[q.id]
                                    : ''
                        };
                    }
                )
                .filter(
                    function(x) {
                        return (
                            x.value !== '' &&
                            x.value !== undefined
                        );
                    }
                );

            return `
                <div class="bg-white border border-slate-200 rounded-2xl p-5">
                    <h2 class="font-bold mb-4">
                        Q${index + 1}.
                        ${App.util.esc(q.text)}
                    </h2>

                    ${
                        values.length
                            ? `
                                <div class="space-y-3 max-h-80 overflow-auto">
                                    ${
                                        values.map(
                                            function(x) {
                                                return `
                                                    <div class="border-l-4 border-indigo-400 pl-3">
                                                        <div class="text-xs text-slate-400">
                                                            ${App.util.esc(x.response.company)}
                                                            /
                                                            ${App.util.esc(x.response.name)}
                                                        </div>
                                                        <div class="mt-1">
                                                            ${App.util.esc(x.value)}
                                                        </div>
                                                    </div>
                                                `;
                                            }
                                        ).join('')
                                    }
                                </div>
                            `
                            : `
                                <div class="text-sm text-slate-400">
                                    回答なし
                                </div>
                            `
                    }
                </div>
            `;
        }

        const counts = {};

        (q.options || [])
            .forEach(
                function(option) {
                    counts[option] = 0;
                }
            );

        responses.forEach(
            function(r) {
                let value =
                    r.answers
                        ? r.answers[q.id]
                        : '';

                if (Array.isArray(value)) {
                    value.forEach(
                        function(v) {
                            counts[v] =
                                (counts[v] || 0) +
                                1;
                        }
                    );
                } else if (
                    value !== ''
                ) {
                    counts[value] =
                        (counts[value] || 0) +
                        1;
                }
            }
        );

        const total =
            responses.length;

        return `
            <div class="bg-white border border-slate-200 rounded-2xl p-5">
                <div class="flex items-start justify-between gap-3 mb-5">
                    <div>
                        <h2 class="font-bold">
                            Q${index + 1}.
                            ${App.util.esc(q.text)}
                        </h2>
                    </div>

                    <span class="text-xs px-2 py-1 rounded-full bg-slate-100">
                        ${q.type === 'multiple' ? '複数選択' : '単一選択'}
                    </span>
                </div>

                <div class="space-y-3">
                    ${
                        Object.entries(counts).map(
                            function(
                                [label, count]
                            ) {
                                const percent =
                                    total
                                        ? (
                                            count /
                                            total *
                                            100
                                        )
                                        .toFixed(1)
                                        : '0.0';

                                return `
                                    <div>
                                        <div class="flex justify-between text-sm mb-1">
                                            <span>
                                                ${App.util.esc(label)}
                                            </span>
                                            <span>
                                                ${count}件
                                                (${percent}%)
                                            </span>
                                        </div>

                                        <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                            <div
                                                class="h-full bg-indigo-500 rounded-full"
                                                style="width:${percent}%"
                                            ></div>
                                        </div>
                                    </div>
                                `;
                            }
                        ).join('')
                    }
                </div>
            </div>
        `;
    };

App.actions.toggleQuestion =
    function(
        id,
        checked
    ) {
        App.state.selectedQuestions[id] =
            checked;

        App.render.aggregate();
    };

App.actions.selectAllQuestions =
    function(value) {
        const survey =
            App.state.data.surveys.find(
                function(s) {
                    return (
                        s.id ===
                        App.state.currentSurveyId
                    );
                }
            );

        if (!survey) {
            return;
        }

        survey.groups.forEach(
            function(group) {
                group.questions.forEach(
                    function(q) {
                        App.state.selectedQuestions[
                            q.id
                        ] = value;
                    }
                );
            }
        );

        App.render.aggregate();
    };

App.actions.filterResponses =
    function(value) {
        App.state.responseFilter =
            value;

        App.render.aggregate();
    };

App.actions.showResponse =
    function(responseId) {
        const response =
            App.state.data.responses.find(
                function(r) {
                    return (
                        r.id ===
                        responseId
                    );
                }
            );

        if (!response) {
            return;
        }

        const survey =
            App.state.data.surveys.find(
                function(s) {
                    return (
                        s.id ===
                        response.survey_id
                    );
                }
            );

        const modal =
            document.getElementById(
                'response_modal'
            );

        if (!modal) {
            return;
        }

        const rows = [];

        if (survey) {
            survey.groups.forEach(
                function(group) {
                    group.questions.forEach(
                        function(q) {
                            let value =
                                response.answers
                                    ? response.answers[q.id]
                                    : '';

                            if (
                                Array.isArray(
                                    value
                                )
                            ) {
                                value =
                                    value.join(
                                        ' / '
                                    );
                            }

                            rows.push(`
                                <div class="border-b border-slate-100 py-3">
                                    <div class="text-xs text-slate-500 mb-1">
                                        ${App.util.esc(q.text)}
                                    </div>
                                    <div>
                                        ${App.util.esc(value)}
                                    </div>
                                </div>
                            `);
                        }
                    );
                }
            );
        }

        modal.innerHTML = `
            <div class="min-h-full flex items-start justify-center py-8">
                <div class="bg-white rounded-2xl shadow-xl max-w-3xl w-full p-6">

                    <div class="flex justify-between items-center mb-5">
                        <div>
                            <h2 class="text-xl font-bold">
                                回答詳細
                            </h2>
                            <div class="text-sm text-slate-500 mt-1">
                                ${App.util.esc(response.company)}
                                /
                                ${App.util.esc(response.name)}
                            </div>
                        </div>

                        <button
                            class="px-3 py-2 rounded-lg bg-slate-100"
                            onclick="App.actions.closeResponse()"
                        >
                            閉じる
                        </button>
                    </div>

                    <div class="max-h-[70vh] overflow-auto">
                        ${rows.join('')}
                    </div>

                </div>
            </div>
        `;

        modal.classList.remove(
            'hidden'
        );
    };

App.actions.closeResponse =
    function() {
        const modal =
            document.getElementById(
                'response_modal'
            );

        if (modal) {
            modal.classList.add(
                'hidden'
            );
        }
    };

App.actions.csv =
    async function(surveyId) {
        const csrf =
            document.getElementById(
                'csrf_token'
            ).value;

        const body =
            new URLSearchParams();

        body.set(
            'action',
            'csv'
        );

        body.set(
            'csrf_token',
            csrf
        );

        body.set(
            'survey_id',
            surveyId
        );

        const response =
            await fetch(
                location.href,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body: body
                }
            );

        if (!response.ok) {
            alert(
                'CSV出力に失敗しました。'
            );
            return;
        }

        const blob =
            await response.blob();

        const url =
            URL.createObjectURL(
                blob
            );

        const a =
            document.createElement(
                'a'
            );

        a.href = url;
        a.download =
            'survey_' +
            new Date()
                .toISOString()
                .slice(0, 19)
                .replaceAll(':', '') +
            '.csv';

        document.body.appendChild(a);
        a.click();
        a.remove();

        URL.revokeObjectURL(
            url
        );
    };

/* ============================================================
 * Mail
 * ============================================================ */

App.actions.mail =
    function(surveyId) {
        App.state.currentSurveyId =
            surveyId;

        App.state.screen =
            'mail';

        App.render.mail();
    };

App.render.mail =
    function() {
        const survey =
            App.state.data.surveys.find(
                function(s) {
                    return (
                        s.id ===
                        App.state.currentSurveyId
                    );
                }
            );

        if (!survey) {
            return;
        }

        const customers =
            App.state.data.customers
                .filter(
                    function(c) {
                        const kw =
                            App.state.customerFilter ||
                            '';

                        return (
                            !kw ||
                            (
                                String(
                                    c.company || ''
                                ) +
                                ' ' +
                                String(
                                    c.name || ''
                                ) +
                                ' ' +
                                String(
                                    c.email || ''
                                )
                            )
                            .toLowerCase()
                            .includes(
                                kw.toLowerCase()
                            )
                        );
                    }
                );

        document.getElementById(
            'app'
        ).innerHTML = `
            ${App.render.header('list')}

            <main class="max-w-7xl mx-auto px-4 py-7">

                <div class="text-sm text-slate-500 mb-1">
                    ホーム ＞ アンケート一覧 ＞ 顧客選択・送信
                </div>

                <div class="flex justify-between items-center gap-3 mb-6">
                    <h1 class="text-2xl font-bold">
                        ${App.util.esc(survey.title)}
                    </h1>
                </div>

                <div class="grid lg:grid-cols-3 gap-5">

                    <section class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl overflow-hidden">

                        <div class="p-4 border-b border-slate-200 flex flex-col md:flex-row gap-3 md:items-center md:justify-between">

                            <input
                                id="customer_filter"
                                value="${App.util.esc(App.state.customerFilter || '')}"
                                placeholder="顧客名・メールアドレスで検索"
                                class="border border-slate-300 rounded-lg px-3 py-2"
                                oninput="App.actions.customerFilter(this.value)"
                            >

                            <label class="flex items-center gap-2">
                                <input
                                    id="select_all"
                                    type="checkbox"
                                    onchange="App.actions.selectAllCustomers(this.checked)"
                                >
                                全選択
                            </label>

                        </div>

                        <div class="overflow-x-auto">
                            <table
                                id="customer_table"
                                class="w-full min-w-[1000px] text-sm"
                            >
                                <thead class="bg-slate-50">
                                    <tr>
                                        <th class="p-3 text-left">選択</th>
                                        <th class="p-3 text-left">会社名 / 氏名</th>
                                        <th class="p-3 text-left">メール</th>
                                        <th class="p-3 text-left">電話</th>
                                        <th class="p-3 text-left">送信状況</th>
                                        <th class="p-3 text-left">回答</th>
                                        <th class="p-3 text-left">kintone</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    ${
                                        customers.map(
                                            function(c) {
                                                return `
                                                    <tr class="border-t border-slate-100">
                                                        <td class="p-3">
                                                            <input
                                                                type="checkbox"
                                                                class="customer-check"
                                                                value="${App.util.esc(c.id)}"
                                                                onchange="App.actions.updateRecipient()"
                                                                ${c.source === 'web' ? 'disabled' : ''}
                                                            >
                                                        </td>

                                                        <td class="p-3">
                                                            <div class="font-semibold">
                                                                ${App.util.esc(c.company)}
                                                            </div>
                                                            <div>
                                                                ${App.util.esc(c.name)}
                                                            </div>
                                                        </td>

                                                        <td class="p-3">
                                                            ${App.util.esc(c.email)}
                                                        </td>

                                                        <td class="p-3">
                                                            ${App.util.esc(c.phone)}
                                                        </td>

                                                        <td class="p-3">
                                                            ${
                                                                c.sent_at
                                                                    ? `
                                                                        <div>
                                                                            ${App.util.esc(c.sent_at)}
                                                                        </div>
                                                                        <div class="text-xs text-slate-400">
                                                                            ${Number(c.send_count || 0)}回
                                                                        </div>
                                                                    `
                                                                    : '未送信'
                                                            }
                                                        </td>

                                                        <td class="p-3">
                                                            <span class="px-2 py-1 rounded-full text-xs ${
                                                                c.answer_status === 'answered'
                                                                    ? 'bg-emerald-50 text-emerald-700'
                                                                    : 'bg-amber-50 text-amber-700'
                                                            }">
                                                                ${
                                                                    c.answer_status === 'answered'
                                                                        ? '回答済み'
                                                                        : '未回答'
                                                                }
                                                            </span>
                                                        </td>

                                                        <td class="p-3">
                                                            ${
                                                                c.kintone_status === 'registered'
                                                                    ? `
                                                                        <span class="text-emerald-700 text-xs">
                                                                            ✓ 登録完了
                                                                        </span>
                                                                    `
                                                                    : `
                                                                        <button
                                                                            class="text-xs text-indigo-600"
                                                                            onclick="App.actions.kintoneRegistered('${c.id}')"
                                                                        >
                                                                            登録完了
                                                                        </button>
                                                                    `
                                                            }
                                                        </td>
                                                    </tr>
                                                `;
                                            }
                                        ).join('')
                                    }
                                </tbody>
                            </table>
                        </div>

                    </section>

                    <section class="bg-white border border-slate-200 rounded-2xl p-5 h-fit">

                        <h2 class="font-bold mb-4">
                            メール送信
                        </h2>

                        <label class="block mb-4">
                            <span class="text-sm font-medium">
                                テンプレート
                            </span>

                            <select
                                id="template_type"
                                class="mt-2 w-full border border-slate-300 rounded-lg px-3 py-2.5"
                            >
                                <option value="initial">
                                    初回送信
                                </option>
                                <option value="reminder">
                                    リマインド
                                </option>
                            </select>
                        </label>

                        <label class="block mb-4">
                            <span class="text-sm font-medium">
                                件名
                            </span>

                            <input
                                id="mail_subject"
                                class="mt-2 w-full border border-slate-300 rounded-lg px-3 py-2.5"
                                value="${App.util.esc(survey.title)} のご案内"
                            >
                        </label>

                        <label class="block mb-4">
                            <span class="text-sm font-medium">
                                本文
                            </span>

                            <textarea
                                id="mail_body"
                                rows="12"
                                class="mt-2 w-full border border-slate-300 rounded-lg px-3 py-2.5"
                            >{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>
                        </label>

                        <div class="text-xs text-slate-500 mb-4">
                            使用可能な変数：
                            {顧客名}
                            {アンケートURL}
                        </div>

                        <button
                            class="w-full bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl py-3 font-semibold"
                            onclick="App.actions.sendMail()"
                        >
                            一括送信実行
                        </button>

                    </section>

                </div>

            </main>
        `;
    };

App.actions.customerFilter =
    function(value) {
        App.state.customerFilter =
            value;

        App.render.mail();
    };

App.actions.selectAllCustomers =
    function(checked) {
        document.querySelectorAll(
            '.customer-check:not(:disabled)'
        ).forEach(
            function(el) {
                el.checked =
                    checked;
            }
        );
    };

App.actions.updateRecipient =
    function() {};

App.actions.kintoneRegistered =
    async function(id) {
        await App.api.request(
            'set_kintone_registered',
            {
                customer_id: id
            }
        );

        await App.actions.load();

        App.actions.mail(
            App.state.currentSurveyId
        );
    };

App.actions.sendMail =
    async function() {
        const ids =
            Array.from(
                document.querySelectorAll(
                    '.customer-check:checked'
                )
            ).map(
                function(el) {
                    return el.value;
                }
            );

        if (!ids.length) {
            alert(
                '送信対象を選択してください。'
            );
            return;
        }

        const resend =
            ids.some(
                function(id) {
                    const c =
                        App.state.data.customers.find(
                            function(x) {
                                return x.id === id;
                            }
                        );

                    return (
                        c &&
                        Number(
                            c.send_count || 0
                        ) > 0
                    );
                }
            );

        if (
            resend &&
            !confirm(
                '既に送信済みの宛先が含まれています。再送しますか？'
            )
        ) {
            return;
        }

        const subject =
            document.getElementById(
                'mail_subject'
            ).value;

        const mailBody =
            document.getElementById(
                'mail_body'
            ).value;

        const template =
            document.getElementById(
                'template_type'
            ).value;

        const result =
            await App.api.request(
                'send_mail',
                {
                    survey_id:
                        App.state.currentSurveyId,
                    recipient_ids:
                        ids,
                    mail_subject:
                        subject,
                    mail_body:
                        mailBody,
                    template_type:
                        template
                }
            );

        await App.actions.load();

        App.actions.mail(
            App.state.currentSurveyId
        );

        alert(
            result.message ||
            '送信処理を完了しました。'
        );
    };

/* ============================================================
 * Settings
 * ============================================================ */

App.actions.goSettings =
    function() {
        App.state.screen =
            'settings';

        App.render.settings();
    };

App.render.settings =
    function() {
        const s =
            App.state.data.settings ||
            {};

        document.getElementById(
            'app'
        ).innerHTML = `
            ${App.render.header('settings')}

            <main class="max-w-4xl mx-auto px-4 py-8">

                <div class="text-sm text-slate-500 mb-1">
                    ホーム ＞ システム設定 ＞ kintone連携設定
                </div>

                <h1 class="text-2xl font-bold mb-6">
                    kintone連携設定
                </h1>

                <form
                    id="settings_form"
                    class="bg-white border border-slate-200 rounded-2xl p-6"
                    onsubmit="return App.settings.save(event)"
                >

                    <div class="grid md:grid-cols-2 gap-5">

                        <label>
                            <span class="block text-sm font-medium mb-2">
                                サブドメイン
                            </span>

                            <input
                                id="setting_subdomain"
                                value="${App.util.esc(s.subdomain || '')}"
                                placeholder="xxxx.cybozu.com"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2.5"
                            >

                            <div class="text-xs text-slate-500 mt-1">
                                xxxx.cybozu.com / https://xxxx.cybozu.com/ の両方に対応
                            </div>
                        </label>

                        <label>
                            <span class="block text-sm font-medium mb-2">
                                アプリID
                            </span>

                            <input
                                id="setting_app_id"
                                value="${App.util.esc(s.app_id || '')}"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2.5"
                            >
                        </label>

                        <label>
                            <span class="block text-sm font-medium mb-2">
                                ログイン名
                            </span>

                            <input
                                id="setting_login_name"
                                value="${App.util.esc(s.login_name || '')}"
                                autocomplete="username"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2.5"
                            >
                        </label>

                        <label>
                            <span class="block text-sm font-medium mb-2">
                                パスワード
                            </span>

                            <input
                                id="setting_password"
                                type="password"
                                value=""
                                autocomplete="new-password"
                                placeholder="変更時のみ入力"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2.5"
                            >
                        </label>

                        <label class="md:col-span-2">
                            <span class="block text-sm font-medium mb-2">
                                Proxy
                            </span>

                            <input
                                id="setting_proxy"
                                value="${App.util.esc(s.proxy || '')}"
                                placeholder="host:port"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2.5"
                            >
                        </label>

                        <label class="flex items-center gap-2">
                            <input
                                id="setting_ssl_verify"
                                type="checkbox"
                                ${s.ssl_verify !== false ? 'checked' : ''}
                            >
                            SSL証明書を検証する
                        </label>

                    </div>

                    <div class="flex flex-wrap gap-3 mt-7">

                        <button
                            type="button"
                            class="px-4 py-2.5 rounded-lg bg-indigo-600 text-white"
                            onclick="App.settings.test()"
                        >
                            接続確認・項目取得
                        </button>

                        <button
                            type="button"
                            class="px-4 py-2.5 rounded-lg bg-slate-100"
                            onclick="App.settings.save(event)"
                        >
                            設定を保存
                        </button>

                    </div>

                    <div
                        id="field_message"
                        class="hidden mt-5 rounded-lg p-4 whitespace-pre-wrap text-sm"
                    ></div>

                    <div
                        id="kintoneFields"
                        class="mt-6"
                    ></div>

                </form>

            </main>
        `;
    };

App.settings.collect =
    function() {
        const old =
            App.state.data.settings ||
            {};

        const password =
            document.getElementById(
                'setting_password'
            ).value;

        return {
            subdomain:
                document.getElementById(
                    'setting_subdomain'
                ).value.trim(),

            app_id:
                document.getElementById(
                    'setting_app_id'
                ).value.trim(),

            login_name:
                document.getElementById(
                    'setting_login_name'
                ).value.trim(),

            /*
             * 空欄なら保存済みパスワードを維持。
             * HTMLへ保存済みパスワードを再出力しない。
             */
            password:
                password !== ''
                    ? password
                    : (
                        old.password ||
                        ''
                    ),

            proxy:
                document.getElementById(
                    'setting_proxy'
                ).value.trim(),

            ssl_verify:
                document.getElementById(
                    'setting_ssl_verify'
                ).checked,

            field_company:
                old.field_company ||
                '',

            field_name:
                old.field_name ||
                '',

            field_email:
                old.field_email ||
                '',

            field_department:
                old.field_department ||
                '',

            field_phone:
                old.field_phone ||
                '',

            field_address:
                Array.isArray(
                    old.field_address
                )
                    ? old.field_address
                    : []
        };
    };

App.settings.save =
    async function(event) {
        if (event) {
            event.preventDefault();
        }

        const settings =
            App.settings.collect();

        await App.api.request(
            'save_settings',
            {
                settings_json:
                    settings
            }
        );

        await App.actions.load();

        alert(
            '設定を保存しました。'
        );

        return false;
    };

App.settings.test =
    async function() {
        const box =
            document.getElementById(
                'field_message'
            );

        const fieldsBox =
            document.getElementById(
                'kintoneFields'
            );

        box.className =
            'mt-5 rounded-lg p-4 whitespace-pre-wrap text-sm bg-slate-50 text-slate-700';

        box.textContent =
            'kintoneへ接続しています…';

        fieldsBox.innerHTML = '';

        try {
            const settings =
                App.settings.collect();

            const result =
                await App.api.request(
                    'kintone_fields',
                    {
                        settings_json:
                            settings
                    }
                );

            box.className =
                'mt-5 rounded-lg p-4 whitespace-pre-wrap text-sm bg-emerald-50 text-emerald-700';

            box.textContent =
                result.message;

            App.settings.renderFields(
                result.fields || []
            );

        } catch (error) {
            box.className =
                'mt-5 rounded-lg p-4 whitespace-pre-wrap text-sm bg-red-50 text-red-700';

            box.textContent =
                error.message;
        }
    };

App.settings.renderFields =
    function(fields) {
        const box =
            document.getElementById(
                'kintoneFields'
            );

        if (!box) {
            return;
        }

        const settings =
            App.state.data.settings ||
            {};

        function select(
            name,
            title,
            value,
            multiple
        ) {
            return `
                <label class="block">
                    <span class="block text-sm font-medium mb-2">
                        ${title}
                    </span>

                    <select
                        ${
                            multiple
                                ? 'multiple size="5"'
                                : ''
                        }
                        data-field="${name}"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2.5"
                        onchange="App.settings.updateMapping('${name}',this)"
                    >
                        ${
                            multiple
                                ? ''
                                : `
                                    <option value="">
                                        選択してください
                                    </option>
                                `
                        }

                        ${
                            fields.map(
                                function(f) {
                                    const selected =
                                        multiple
                                            ? (
                                                Array.isArray(value) &&
                                                value.includes(
                                                    f.code
                                                )
                                            )
                                            : (
                                                value ===
                                                f.code
                                            );

                                    return `
                                        <option
                                            value="${App.util.esc(f.code)}"
                                            ${selected ? 'selected' : ''}
                                        >
                                            ${App.util.esc(f.label)}
                                            (${App.util.esc(f.code)})
                                        </option>
                                    `;
                                }
                            ).join('')
                        }
                    </select>
                </label>
            `;
        }

        box.innerHTML = `
            <div class="border-t border-slate-200 pt-6">
                <h2 class="font-bold mb-4">
                    kintone項目マッピング
                </h2>

                <div class="grid md:grid-cols-2 gap-4">
                    ${select(
                        'field_company',
                        '会社名 (Company)',
                        settings.field_company || '',
                        false
                    )}

                    ${select(
                        'field_name',
                        '氏名 (Name)',
                        settings.field_name || '',
                        false
                    )}

                    ${select(
                        'field_email',
                        'メールアドレス (Email)',
                        settings.field_email || '',
                        false
                    )}

                    ${select(
                        'field_department',
                        '部署名 (Department)',
                        settings.field_department || '',
                        false
                    )}

                    ${select(
                        'field_phone',
                        '電話番号 (Phone)',
                        settings.field_phone || '',
                        false
                    )}

                    ${select(
                        'field_address',
                        '住所 (Address)',
                        settings.field_address || [],
                        true
                    )}
                </div>
            </div>
        `;
    };

App.settings.updateMapping =
    async function(
        name,
        element
    ) {
        const settings =
            App.state.data.settings ||
            {};

        if (
            element.multiple
        ) {
            settings[name] =
                Array.from(
                    element.selectedOptions
                ).map(
                    function(o) {
                        return o.value;
                    }
                );
        } else {
            settings[name] =
                element.value;
        }

        App.state.data.settings =
            settings;

        await App.api.request(
            'save_settings',
            {
                settings_json:
                    settings
            }
        );
    };

/* ============================================================
 * Boot
 *
 * ★ document.readyStateを確認し、
 * DOMContentLoaded前後どちらでも1回だけ起動。
 * ============================================================ */

if (
    document.readyState ===
    'loading'
) {
    document.addEventListener(
        'DOMContentLoaded',
        function() {
            App.init();
        },
        {
            once: true
        }
    );
} else {
    App.init();
}
</script>

</body>
</html>

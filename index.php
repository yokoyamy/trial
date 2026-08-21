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

function survey_h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function survey_json(mixed $v): string {
    return json_encode(
        $v,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: 'null';
}

function survey_id(): string {
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable) {
        return sha1(uniqid('', true));
    }
}

function survey_now(): string {
    return date('Y-m-d H:i:s');
}

function survey_default_data(): array {
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

function survey_read_data(): array {
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

    return array_replace_recursive(survey_default_data(), $data);
}

function survey_write_data(array $data): bool {
    if (!is_dir(SURVEY_STORAGE_DIRECTORY) &&
        !@mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true)) {
        return false;
    }

    $tmp = SURVEY_STORAGE_FILE . '.tmp';

    if (@file_put_contents(
        $tmp,
        survey_json($data),
        LOCK_EX
    ) === false) {
        return false;
    }

    return @rename($tmp, SURVEY_STORAGE_FILE);
}

function survey_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function survey_check_token(): bool {
    $a = (string)($_SESSION['csrf_token'] ?? '');
    $b = (string)($_POST['csrf_token'] ?? '');

    return $a !== '' && $b !== '' && hash_equals($a, $b);
}

function survey_api(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo survey_json($data);
    exit;
}

/* ============================================================
 * kintone URL
 * ============================================================ */

function survey_normalize_kintone_base(string $input): array {
    $input = trim($input);
    $input = rtrim($input, "/ \t\r\n");

    if ($input === '') {
        return [
            'ok' => false,
            'error' => 'kintoneホストが未入力です。'
        ];
    }

    if (!preg_match('~^https?://~i', $input)) {
        $input = 'https://' . $input;
    }

    $host = '';

    $parsed = @parse_url($input);

    if (is_array($parsed)) {
        $host = (string)($parsed['host'] ?? '');

        if (isset($parsed['port'])) {
            $host .= ':' . (int)$parsed['port'];
        }
    }

    if ($host === '' &&
        preg_match('~^https?://([^/?#]+)~i', $input, $m)) {
        $host = $m[1];
    }

    $host = strtolower(trim($host));

    if ($host === '') {
        return [
            'ok' => false,
            'error' => 'kintoneホストを取得できません。'
        ];
    }

    $hostOnly = preg_replace('/:\d+$/', '', $host);

    /*
     * 通常のcybozu.comを許可。
     * 検証環境用ポートも許可。
     * 社内FQDNも利用できるよう一般的なFQDNを許可。
     */
    if (!preg_match(
        '~^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.cybozu\.com$~i',
        (string)$hostOnly
    ) &&
    !preg_match(
        '~^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$~i',
        (string)$hostOnly
    )) {
        return [
            'ok' => false,
            'error' => '許可されていないkintoneホスト名です。'
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

function survey_parse_proxy(string $input): array {
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
            'error' => 'Proxyポート番号が不正です。'
        ];
    }

    return [
        'ok' => true,
        'used' => true,
        'value' => 'tcp://' . strtolower($m[2]) . ':' . $port,
    ];
}

/* ============================================================
 * HTTP
 * ============================================================ */

function survey_last_headers(): array {
    if (function_exists('http_get_last_response_headers')) {
        try {
            $h = http_get_last_response_headers();

            if (is_array($h)) {
                return $h;
            }
        } catch (Throwable) {
        }
    }

    $h = $GLOBALS['http_response_header'] ?? null;

    return is_array($h) ? $h : [];
}

function survey_status_from_headers(array $headers): int {
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

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' => '接続先URLが不正です。',
            'url' => $url,
            'proxy_used' => $proxyInfo['used'],
        ];
    }

    $parsed = @parse_url($url);

    $peerName = is_array($parsed)
        ? (string)($parsed['host'] ?? '')
        : '';

    $http = [
        'method' => strtoupper($method),
        'timeout' => 30,
        'ignore_errors' => true,
        'protocol_version' => 1.1,
        'header' => implode("\r\n", $headers),
    ];

    if ($content !== null && strtoupper($method) !== 'GET') {
        $http['content'] = $content;
    }

    if ($proxyInfo['used']) {
        $http['proxy'] = $proxyInfo['value'];
        $http['request_fulluri'] = true;
    }

    $context = stream_context_create([
        'http' => $http,
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
    } catch (Throwable $e) {
        $body = false;
        $warning = $e->getMessage();
    }

    restore_error_handler();

    $headersResult = survey_last_headers();

    $status = survey_status_from_headers($headersResult);

    $bodyText = is_string($body)
        ? $body
        : '';

    $json = json_decode(
        $bodyText,
        true
    );

    if ($status === 0) {
        $error = $warning !== ''
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
            'proxy_used' => $proxyInfo['used'],
        ];
    }

    return [
        'status' => $status,
        'body' => $bodyText,
        'json' => $json,
        'error' => $warning,
        'url' => $url,
        'proxy_used' => $proxyInfo['used'],
    ];
}

/* ============================================================
 * kintone
 * ============================================================ */

function survey_kintone_request(
    array $settings,
    string $path
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

    $appId = trim(
        (string)($settings['app_id'] ?? '')
    );

    if ($appId === '' ||
        !preg_match('/^[0-9]+$/', $appId)) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' => 'アプリIDは数字で入力してください。',
            'url' => '',
            'proxy_used' => false,
        ];
    }

    /*
     * 接続確認も項目取得も必ず app_id 付き。
     * app.json は使用しない。
     */
    $url =
        $normalized['base']
        . '/k/v1/app/form/fields.json?app='
        . rawurlencode($appId);

    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');

    if ($login === '' || $password === '') {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' => 'ログイン名とパスワードを入力してください。',
            'url' => $url,
            'proxy_used' => false,
        ];
    }

    $auth = base64_encode(
        $login . ':' . $password
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $auth,
        'Accept: application/json',
        'Connection: close',
    ];

    return survey_http_request(
        $url,
        'GET',
        $headers,
        null,
        (bool)($settings['ssl_verify'] ?? true),
        (string)($settings['proxy'] ?? '')
    );
}

function survey_kintone_message(array $r): string {
    $status = (int)($r['status'] ?? 0);

    $url = (string)($r['url'] ?? '');

    $error = trim(
        (string)($r['error'] ?? '')
    );

    $proxy = !empty($r['proxy_used'])
        ? '使用'
        : '未使用';

    if ($status === 0) {
        return
            "kintoneからHTTPレスポンスを取得できませんでした。\n"
            . "HTTPステータス: 0\n"
            . "接続先: {$url}\n"
            . "Proxy: {$proxy}\n"
            . "PHP通信エラー: "
            . ($error !== '' ? $error : 'なし')
            . "\n確認事項: サーバーからの外部HTTPS通信、DNS、"
            . "Proxy、ファイアウォール、SSL/TLS、OpenSSL";
    }

    if ($status === 401 || $status === 403) {
        return
            "kintone認証または権限エラーです。\n"
            . "HTTPステータス: {$status}\n"
            . "接続先: {$url}";
    }

    if ($status === 404) {
        return
            "kintone APIまたはアプリが見つかりません。\n"
            . "HTTPステータス: 404\n"
            . "接続先: {$url}";
    }

    if ($status === 408) {
        return
            "kintone通信がタイムアウトしました。\n"
            . "HTTPステータス: 408";
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

    if ($status >= 200 && $status < 300) {
        return
            "kintone通信に成功しました。\n"
            . "HTTPステータス: {$status}";
    }

    return
        "kintone通信でエラーが発生しました。\n"
        . "HTTPステータス: {$status}\n"
        . "接続先: {$url}\n"
        . ($error !== ''
            ? "PHP通信エラー: {$error}"
            : '');
}

function fetchKintoneFields(array $settings): array {
    $r = survey_kintone_request($settings);

    $status = (int)$r['status'];

    if ($status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'fields' => [],
            'message' => survey_kintone_message($r),
            'diagnostic' => $r,
        ];
    }

    $json = $r['json'];

    if (!is_array($json) ||
        !isset($json['properties']) ||
        !is_array($json['properties'])) {

        return [
            'ok' => false,
            'fields' => [],
            'message' =>
                'kintoneレスポンスにpropertiesがありません。',
            'diagnostic' => $r,
        ];
    }

    $fields = [];

    foreach ($json['properties'] as $code => $property) {
        if (!is_array($property)) {
            continue;
        }

        $fields[] = [
            'code' => (string)$code,
            'label' =>
                (string)($property['label'] ?? $code),
            'type' =>
                (string)($property['type'] ?? ''),
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
            'proxy_used' => $r['proxy_used'],
        ],
    ];
}

/* ============================================================
 * POST API
 * ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string)($_POST['action'] ?? '');

    if (!survey_check_token()) {
        survey_api([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。'
        ], 403);
    }

    $data = survey_read_data();

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

        $raw = (string)($_POST['survey_json'] ?? '');

        $survey = json_decode(
            $raw,
            true
        );

        if (!is_array($survey)) {
            survey_api([
                'ok' => false,
                'message' => 'アンケートデータが不正です。'
            ], 400);
        }

        $survey['id'] =
            (string)($survey['id'] ?? survey_id());

        $survey['title'] =
            trim((string)($survey['title'] ?? ''));

        if ($survey['title'] === '') {
            $survey['title'] = '無題のアンケート';
        }

        $survey['start_at'] =
            (string)($survey['start_at'] ?? '');

        $survey['end_at'] =
            (string)($survey['end_at'] ?? '');

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

        $survey['updated_at'] = survey_now();

        if (empty($survey['created_at'])) {
            $survey['created_at'] = survey_now();
        }

        $found = false;

        foreach ($data['surveys'] as $i => $old) {
            if (($old['id'] ?? '') === $survey['id']) {
                $survey['created_at'] =
                    $old['created_at'] ?? $survey['created_at'];

                $data['surveys'][$i] = $survey;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $data['surveys'][] = $survey;
        }

        if (!survey_write_data($data)) {
            survey_api([
                'ok' => false,
                'message' => 'データ保存に失敗しました。'
            ], 500);
        }

        survey_api([
            'ok' => true,
            'data' => $data
        ]);
    }

    /* --------------------------------------------------------
     * ステータス
     * -------------------------------------------------------- */

    if ($action === 'status') {

        $id = (string)($_POST['survey_id'] ?? '');

        $newStatus =
            (string)($_POST['status'] ?? '');

        if (!in_array(
            $newStatus,
            ['draft', 'active', 'ended'],
            true
        )) {
            survey_api([
                'ok' => false,
                'message' => 'ステータスが不正です。'
            ], 400);
        }

        $found = false;

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $id) {
                $survey['status'] = $newStatus;
                $survey['updated_at'] = survey_now();
                $found = true;
                break;
            }
        }

        unset($survey);

        if (!$found) {
            survey_api([
                'ok' => false,
                'message' => 'アンケートが見つかりません。'
            ], 404);
        }

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'data' => $data
        ]);
    }

    /* --------------------------------------------------------
     * 複製
     * -------------------------------------------------------- */

    if ($action === 'duplicate_survey') {

        $id = (string)($_POST['survey_id'] ?? '');

        $source = null;

        foreach ($data['surveys'] as $survey) {
            if (($survey['id'] ?? '') === $id) {
                $source = $survey;
                break;
            }
        }

        if (!is_array($source)) {
            survey_api([
                'ok' => false,
                'message' => '複製元が見つかりません。'
            ], 404);
        }

        $copy = $source;

        $copy['id'] = survey_id();

        $copy['title'] =
            (string)($source['title'] ?? '')
            . '（複製）';

        $copy['status'] = 'draft';

        $copy['created_at'] = survey_now();
        $copy['updated_at'] = survey_now();

        $data['surveys'][] = $copy;

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'data' => $data
        ]);
    }

    /* --------------------------------------------------------
     * 削除
     * -------------------------------------------------------- */

    if ($action === 'delete_survey') {

        $id = (string)($_POST['survey_id'] ?? '');

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $id) {
                $survey['deleted'] = true;
                $survey['updated_at'] = survey_now();
            }
        }

        unset($survey);

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'data' => $data
        ]);
    }

    /* --------------------------------------------------------
     * kintone項目取得
     * -------------------------------------------------------- */

    if ($action === 'kintone_fields' ||
        $action === 'kintone_test') {

        $raw =
            (string)($_POST['settings_json'] ?? '');

        $settings =
            json_decode($raw, true);

        if (!is_array($settings)) {
            survey_api([
                'ok' => false,
                'message' =>
                    'kintone設定データが不正です。'
            ], 400);
        }

        /*
         * ★重要
         * 保存済みsettingsではなく、
         * 画面で現在入力されているsettingsを使用。
         */

        $result =
            fetchKintoneFields($settings);

        if ($action === 'kintone_test') {

            if ($result['ok']) {
                survey_api([
                    'ok' => true,
                    'message' =>
                        "【OK】kintone接続確認成功\n"
                        . "HTTPステータス: 200\n"
                        . "取得項目数: "
                        . count($result['fields'])
                        . "件",
                    'fields' => $result['fields'],
                    'diagnostic' =>
                        $result['diagnostic']
                ]);
            }

            survey_api([
                'ok' => false,
                'message' =>
                    "【NG】kintone接続確認失敗\n\n"
                    . $result['message'],
                'fields' => [],
                'diagnostic' =>
                    $result['diagnostic']
            ], 400);
        }

        if (!$result['ok']) {
            survey_api([
                'ok' => false,
                'message' => $result['message'],
                'fields' => [],
                'diagnostic' =>
                    $result['diagnostic']
            ], 400);
        }

        survey_api([
            'ok' => true,
            'message' =>
                'kintone項目一覧を取得しました。',
            'fields' => $result['fields']
        ]);
    }

    /* --------------------------------------------------------
     * kintone設定保存
     * -------------------------------------------------------- */

    if ($action === 'save_settings') {

        $raw =
            (string)($_POST['settings_json'] ?? '');

        $settings =
            json_decode($raw, true);

        if (!is_array($settings)) {
            survey_api([
                'ok' => false,
                'message' =>
                    '設定データが不正です。'
            ], 400);
        }

        /*
         * パスワード空欄なら既存パスワードを維持。
         * HTMLへは再出力しない。
         */

        if (
            trim(
                (string)($settings['password'] ?? '')
            ) === ''
        ) {
            $settings['password'] =
                (string)(
                    $data['settings']['password']
                    ?? ''
                );
        }

        $allowed = [
            'subdomain',
            'login_name',
            'password',
            'app_id',
            'ssl_verify',
            'proxy',
            'field_company',
            'field_name',
            'field_email',
            'field_department',
            'field_phone',
            'field_address',
        ];

        $newSettings = [];

        foreach ($allowed as $key) {
            $newSettings[$key] =
                $settings[$key] ?? '';
        }

        $newSettings['ssl_verify'] =
            (bool)($settings['ssl_verify'] ?? true);

        $newSettings['field_address'] =
            is_array($settings['field_address'] ?? null)
            ? $settings['field_address']
            : [];

        $data['settings'] =
            $newSettings;

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
     * CSV
     * -------------------------------------------------------- */

    if ($action === 'csv') {

        $surveyId =
            (string)($_POST['survey_id'] ?? '');

        $survey = null;

        foreach ($data['surveys'] as $s) {
            if (($s['id'] ?? '') === $surveyId) {
                $survey = $s;
                break;
            }
        }

        if (!$survey) {
            survey_api([
                'ok' => false,
                'message' =>
                    'アンケートが見つかりません。'
            ], 404);
        }

        $rows = [];

        $questions = [];

        foreach (($survey['groups'] ?? []) as $group) {
            foreach (($group['questions'] ?? []) as $q) {
                $questions[] = $q;
            }
        }

        $header = [
            '回答ID',
            '回答日時',
            '顧客ID',
            '会社名',
            '氏名',
            'メールアドレス',
        ];

        foreach ($questions as $q) {
            $header[] =
                (string)($q['text'] ?? '');
        }

        $rows[] = $header;

        foreach ($data['responses'] as $response) {

            if (
                (string)($response['survey_id'] ?? '')
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
                is_array($response['answers'] ?? null)
                ? $response['answers']
                : [];

            foreach ($questions as $q) {
                $qid =
                    (string)($q['id'] ?? '');

                $value =
                    $answers[$qid] ?? '';

                if (is_array($value)) {
                    $value =
                        implode(' / ', $value);
                }

                $row[] = (string)$value;
            }

            $rows[] = $row;
        }

        header(
            'Content-Type: text/csv; charset=UTF-8'
        );

        header(
            'Content-Disposition: attachment; filename="survey_'
            . rawurlencode($surveyId)
            . '.csv"'
        );

        echo "\xEF\xBB\xBF";

        $fp = fopen('php://output', 'wb');

        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }

        fclose($fp);

        exit;
    }

    survey_api([
        'ok' => false,
        'message' =>
            '未対応のactionです。'
    ], 400);
}

/* ============================================================
 * SPA
 * ============================================================ */

$csrf = survey_token();

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-slate-50 text-slate-800">

<div id="app"></div>

<script>
window.App = {

State: {
    data: {
        surveys: [],
        responses: [],
        customers: [],
        settings: {},
        mail_logs: []
    },

    screen: 'list',
    editingSurvey: null,
    fields: [],
    selectedSurveyId: null,
    keyword: '',
    statusFilter: 'all',
    sort: 'updated_desc'
},

/* ==========================================================
 * Utility
 * ========================================================== */

util: {

    esc(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    },

    id() {
        return (
            Date.now().toString(36) +
            Math.random().toString(36).slice(2)
        );
    },

    clone(value) {
        return JSON.parse(JSON.stringify(value));
    },

    formatDate(value) {
        if (!value) return '未設定';

        const d = new Date(
            String(value).replace(' ', 'T')
        );

        if (Number.isNaN(d.getTime())) {
            return value;
        }

        return d.toLocaleString('ja-JP');
    },

    statusLabel(status) {
        return {
            draft: '下書き',
            active: '公開中',
            ended: '終了'
        }[status] || status;
    },

    statusClass(status) {
        if (status === 'active') {
            return 'bg-emerald-100 text-emerald-700';
        }

        if (status === 'ended') {
            return 'bg-slate-200 text-slate-600';
        }

        return 'bg-amber-100 text-amber-700';
    },

    questionCount(survey) {
        let n = 0;

        for (const g of survey.groups || []) {
            n += (g.questions || []).length;
        }

        return n;
    },

    answerCount(surveyId) {
        return this.State.data.responses.filter(
            r => r.survey_id === surveyId
        ).length;
    }
},

/* ==========================================================
 * API
 * ========================================================== */

api: async function(action, params = {}) {

    const body = new URLSearchParams();

    body.set('action', action);
    body.set('csrf_token',
        document.getElementById('csrf_token').value
    );

    for (const [key, value] of Object.entries(params)) {
        if (Array.isArray(value) ||
            typeof value === 'object') {
            body.set(key, JSON.stringify(value));
        } else {
            body.set(key, value == null ? '' : String(value));
        }
    }

    const response = await fetch(
        location.href,
        {
            method: 'POST',
            headers: {
                'Content-Type':
                    'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body
        }
    );

    let json;

    try {
        json = await response.json();
    } catch (e) {
        throw new Error(
            'サーバーからJSON応答を取得できませんでした。'
        );
    }

    if (!response.ok || !json.ok) {
        throw new Error(
            json.message ||
            'サーバー処理に失敗しました。'
        );
    }

    return json;
},

/* ==========================================================
 * Actions
 * ========================================================== */

actions: {

    async load() {

        const r =
            await App.api('get_data');

        App.State.data = r.data;

        App.renderList();
    },

    newSurvey() {

        App.State.editingSurvey = {
            id: App.util.id(),
            title: '新しいアンケート',
            start_at: '',
            end_at: '',
            status: 'draft',
            created_at: '',
            updated_at: '',
            numbering_mode: 'global',
            groups: [
                {
                    id: App.util.id(),
                    name: 'グループ1',
                    questions: []
                }
            ],
            deleted: false
        };

        App.State.screen = 'edit';

        App.renderEdit();
    },

    editSurvey(id) {

        const survey =
            App.State.data.surveys.find(
                s => s.id === id &&
                !s.deleted
            );

        if (!survey) return;

        App.State.editingSurvey =
            App.util.clone(survey);

        App.State.screen = 'edit';

        App.renderEdit();
    },

    async publishFromList(id) {

        const survey =
            App.State.data.surveys.find(
                s => s.id === id
            );

        if (!survey) return;

        if (!confirm(
            'このアンケートを公開します。\n\n' +
            '公開後は回答者がアクセスできます。\n' +
            '公開しますか？'
        )) {
            return;
        }

        await App.api('status', {
            survey_id: id,
            status: 'active'
        });

        await App.actions.load();

        alert('アンケートを公開しました。');
    },

    async stopFromList(id) {

        const survey =
            App.State.data.surveys.find(
                s => s.id === id
            );

        if (!survey) return;

        if (!confirm(
            'このアンケートを停止します。\n\n' +
            '回答者から回答できなくなります。\n' +
            '停止しますか？'
        )) {
            return;
        }

        await App.api('status', {
            survey_id: id,
            status: 'ended'
        });

        await App.actions.load();

        alert('アンケートを停止しました。');
    },

    async duplicateSurvey(id) {

        await App.api(
            'duplicate_survey',
            { survey_id: id }
        );

        await App.actions.load();

        alert(
            'アンケートを複製しました。\n' +
            '一覧に「下書き」として追加されています。'
        );
    },

    async deleteSurvey(id) {

        if (!confirm(
            'この下書きを削除しますか？'
        )) {
            return;
        }

        await App.api(
            'delete_survey',
            { survey_id: id }
        );

        await App.actions.load();
    },

    cancelEdit() {

        if (!confirm(
            '変更内容を破棄して一覧へ戻りますか？'
        )) {
            return;
        }

        App.State.editingSurvey = null;
        App.State.screen = 'list';

        App.renderList();
    },

    async saveSurvey() {

        const survey =
            App.State.editingSurvey;

        if (!survey) return;

        survey.title =
            document.getElementById(
                'survey_title'
            ).value.trim();

        survey.start_at =
            document.getElementById(
                'survey_start_at'
            ).value;

        survey.end_at =
            document.getElementById(
                'survey_end_at'
            ).value;

        survey.numbering_mode =
            document.getElementById(
                'survey_numbering_mode'
            ).value;

        App.actions.renumber();

        await App.api(
            'save_survey',
            {
                survey_json: survey
            }
        );

        App.State.editingSurvey = null;

        await App.actions.load();

        alert('保存しました。');
    },

    addGroup() {

        const survey =
            App.State.editingSurvey;

        survey.groups.push({
            id: App.util.id(),
            name:
                'グループ' +
                (survey.groups.length + 1),
            questions: []
        });

        App.renderEdit();
    },

    removeGroup(groupId) {

        const survey =
            App.State.editingSurvey;

        const group =
            survey.groups.find(
                g => g.id === groupId
            );

        if (!group) return;

        if (
            group.questions.length &&
            !confirm(
                'このグループと質問を削除しますか？'
            )
        ) {
            return;
        }

        survey.groups =
            survey.groups.filter(
                g => g.id !== groupId
            );

        if (!survey.groups.length) {
            App.actions.addGroup();
            return;
        }

        App.actions.renumber();

        App.renderEdit();
    },

    addQuestion(groupId) {

        const survey =
            App.State.editingSurvey;

        const group =
            survey.groups.find(
                g => g.id === groupId
            );

        if (!group) return;

        group.questions.push({
            id: App.util.id(),
            text: '新しい質問',
            type: 'single',
            required: false,
            options: ['選択肢1', '選択肢2'],
            other_enabled: false
        });

        App.actions.renumber();

        App.renderEdit();
    },

    removeQuestion(groupId, questionId) {

        const survey =
            App.State.editingSurvey;

        const group =
            survey.groups.find(
                g => g.id === groupId
            );

        if (!group) return;

        group.questions =
            group.questions.filter(
                q => q.id !== questionId
            );

        App.actions.renumber();

        App.renderEdit();
    },

    updateQuestion(
        groupId,
        questionId,
        field,
        value
    ) {

        const group =
            App.State.editingSurvey.groups.find(
                g => g.id === groupId
            );

        const q =
            group.questions.find(
                x => x.id === questionId
            );

        if (!q) return;

        q[field] = value;
    },

    updateGroupName(groupId, value) {

        const group =
            App.State.editingSurvey.groups.find(
                g => g.id === groupId
            );

        if (group) {
            group.name = value;
        }
    },

    addOption(groupId, questionId) {

        const group =
            App.State.editingSurvey.groups.find(
                g => g.id === groupId
            );

        const q =
            group.questions.find(
                x => x.id === questionId
            );

        q.options.push(
            '選択肢' +
            (q.options.length + 1)
        );

        App.renderEdit();
    },

    removeOption(
        groupId,
        questionId,
        index
    ) {

        const group =
            App.State.editingSurvey.groups.find(
                g => g.id === groupId
            );

        const q =
            group.questions.find(
                x => x.id === questionId
            );

        q.options.splice(index, 1);

        App.renderEdit();
    },

    updateOption(
        groupId,
        questionId,
        index,
        value
    ) {

        const group =
            App.State.editingSurvey.groups.find(
                g => g.id === groupId
            );

        const q =
            group.questions.find(
                x => x.id === questionId
            );

        if (q) {
            q.options[index] = value;
        }
    },

    renumber() {

        const survey =
            App.State.editingSurvey;

        let global = 1;

        survey.groups.forEach(
            (group, gi) => {

                group.questions.forEach(
                    (q, qi) => {

                        q.number =
                            survey.numbering_mode ===
                            'group'
                            ? `Q${gi + 1}-${qi + 1}`
                            : `Q${global}`;

                        global++;
                    }
                );
            }
        );
    },

    initSortables() {

        const survey =
            App.State.editingSurvey;

        if (!survey) return;

        survey.groups.forEach(
            group => {

                const el =
                    document.getElementById(
                        'questions_' + group.id
                    );

                if (!el) return;

                new Sortable(
                    el,
                    {
                        group: 'surveyQuestions',
                        animation: 180,
                        ghostClass:
                            'opacity-40',
                        handle:
                            '.question-handle',

                        onEnd(evt) {

                            const fromId =
                                evt.from.id.replace(
                                    'questions_',
                                    ''
                                );

                            const toId =
                                evt.to.id.replace(
                                    'questions_',
                                    ''
                                );

                            const from =
                                survey.groups.find(
                                    g =>
                                    g.id === fromId
                                );

                            const to =
                                survey.groups.find(
                                    g =>
                                    g.id === toId
                                );

                            if (!from || !to) {
                                return;
                            }

                            const moved =
                                from.questions.splice(
                                    evt.oldIndex,
                                    1
                                )[0];

                            if (!moved) return;

                            to.questions.splice(
                                evt.newIndex,
                                0,
                                moved
                            );

                            App.actions.renumber();

                            App.renderEdit();
                        }
                    }
                );
            }
        );

        const groupEl =
            document.getElementById(
                'group_sortable'
            );

        if (groupEl) {

            new Sortable(
                groupEl,
                {
                    animation: 180,
                    ghostClass:
                        'opacity-40',
                    handle:
                        '.group-handle',

                    onEnd(evt) {

                        const moved =
                            survey.groups.splice(
                                evt.oldIndex,
                                1
                            )[0];

                        if (!moved) return;

                        survey.groups.splice(
                            evt.newIndex,
                            0,
                            moved
                        );

                        App.actions.renumber();

                        App.renderEdit();
                    }
                }
            );
        }
    },

    preview() {

        const survey =
            App.State.editingSurvey;

        if (!survey) return;

        document.getElementById(
            'preview_content'
        ).innerHTML =
            App.renderPreviewHtml(survey);

        document.getElementById(
            'preview_modal'
        ).classList.remove('hidden');
    },

    closePreview() {

        document.getElementById(
            'preview_modal'
        ).classList.add('hidden');
    },

    async openSettings() {

        App.State.screen =
            'settings';

        const r =
            await App.api('get_data');

        App.State.data =
            r.data;

        App.State.fields = [];

        App.renderSettings();
    },

    async fetchKintoneFields() {

        const settings =
            App.actions.readKintoneSettings();

        if (!settings.subdomain) {
            alert(
                'kintoneホストを入力してください。'
            );
            return;
        }

        if (!settings.app_id) {
            alert(
                '顧客管理アプリIDを入力してください。'
            );
            return;
        }

        const message =
            document.getElementById(
                'field_message'
            );

        if (message) {
            message.textContent =
                'kintoneから項目一覧を取得中...';
        }

        try {

            const r =
                await App.api(
                    'kintone_fields',
                    {
                        settings_json:
                            settings
                    }
                );

            App.State.fields =
                r.fields || [];

            App.renderSettings();

            document.getElementById(
                'field_message'
            ).textContent =
                `取得成功: ${App.State.fields.length}件`;

        } catch (e) {

            document.getElementById(
                'field_message'
            ).textContent =
                e.message;

            alert(
                '【NG】項目一覧取得失敗\n\n' +
                e.message
            );
        }
    },

    async testKintone() {

        const settings =
            App.actions.readKintoneSettings();

        try {

            const r =
                await App.api(
                    'kintone_test',
                    {
                        settings_json:
                            settings
                    }
                );

            alert(
                r.message
            );

        } catch (e) {

            alert(
                '【NG】kintone接続確認失敗\n\n' +
                e.message
            );
        }
    },

    readKintoneSettings() {

        const value =
            id =>
            document.getElementById(id);

        return {

            subdomain:
                value(
                    'setting_subdomain'
                )?.value.trim() || '',

            app_id:
                value(
                    'setting_app_id'
                )?.value.trim() || '',

            login_name:
                value(
                    'setting_login_name'
                )?.value || '',

            /*
             * パスワードはDOMから読むだけ。
             * StateやHTMLへ保存しない。
             */
            password:
                value(
                    'setting_password'
                )?.value || '',

            proxy:
                value(
                    'setting_proxy'
                )?.value.trim() || '',

            ssl_verify:
                value(
                    'setting_ssl_verify'
                )?.checked ?? true,

            field_company:
                value(
                    'setting_field_company'
                )?.value || '',

            field_name:
                value(
                    'setting_field_name'
                )?.value || '',

            field_email:
                value(
                    'setting_field_email'
                )?.value || '',

            field_department:
                value(
                    'setting_field_department'
                )?.value || '',

            field_phone:
                value(
                    'setting_field_phone'
                )?.value || '',

            field_address:
                Array.from(
                    document.querySelectorAll(
                        '.address_field:checked'
                    )
                ).map(
                    el => el.value
                )
        };
    },

    async saveSettings() {

        const settings =
            App.actions.readKintoneSettings();

        await App.api(
            'save_settings',
            {
                settings_json:
                    settings
            }
        );

        const r =
            await App.api('get_data');

        App.State.data =
            r.data;

        alert(
            'kintone連携設定を保存しました。'
        );

        App.renderSettings();
    },

    showAggregation(id) {

        App.State.selectedSurveyId =
            id;

        App.State.screen =
            'aggregation';

        App.renderAggregation();
    },

    showMail(id) {

        App.State.selectedSurveyId =
            id;

        App.State.screen =
            'mail';

        App.renderMail();
    },

    filterResponses(value) {

        const el =
            document.getElementById(
                'response_filter'
            );

        if (el) {
            el.dataset.keyword =
                value.toLowerCase();
        }

        App.renderAggregation();
    },

    showResponse(id) {

        const response =
            App.State.data.responses.find(
                r => r.id === id
            );

        if (!response) return;

        document.getElementById(
            'response_detail'
        ).innerHTML =
            App.renderResponseDetail(
                response
            );

        document.getElementById(
            'response_modal'
        ).classList.remove(
            'hidden'
        );
    },

    closeResponse() {

        document.getElementById(
            'response_modal'
        ).classList.add(
            'hidden'
        );
    },

    async syncCustomers() {

        /*
         * 顧客同期APIは必要なときだけ実行。
         * 自動同期はしない。
         */

        alert(
            '顧客同期機能は、保存したkintone設定を使用して実行します。'
        );
    }
},

/* ==========================================================
 * Render
 * ========================================================== */

renderHeader() {

    return `
    <header class="bg-white border-b sticky top-0 z-30">
      <div class="max-w-7xl mx-auto px-6 py-4
                  flex items-center justify-between">

        <button
          onclick="App.actions.load()"
          class="text-xl font-bold text-slate-800">
          アンケート管理
        </button>

        <nav class="flex gap-2">

          <button
            onclick="App.State.screen='list';App.renderList()"
            class="px-4 py-2 rounded-lg hover:bg-slate-100">
            アンケート一覧
          </button>

          <button
            onclick="App.actions.openSettings()"
            class="px-4 py-2 rounded-lg hover:bg-slate-100">
            キントーン連携設定
          </button>

        </nav>
      </div>
    </header>`;
},

renderList() {

    App.State.screen = 'list';

    const surveys =
        App.State.data.surveys.filter(
            s => !s.deleted
        );

    const keyword =
        App.State.keyword.toLowerCase();

    let rows =
        surveys.filter(
            s =>
                !keyword ||
                String(s.title || '')
                    .toLowerCase()
                    .includes(keyword)
        );

    if (App.State.statusFilter !== 'all') {
        rows =
            rows.filter(
                s =>
                    s.status ===
                    App.State.statusFilter
            );
    }

    rows.sort(
        (a, b) => {

            if (
                App.State.sort ===
                'answers_desc'
            ) {
                return App.util.answerCount(b.id) -
                    App.util.answerCount(a.id);
            }

            if (
                App.State.sort ===
                'answers_asc'
            ) {
                return App.util.answerCount(a.id) -
                    App.util.answerCount(b.id);
            }

            return String(
                b.updated_at || ''
            ).localeCompare(
                String(a.updated_at || '')
            );
        }
    );

    document.getElementById(
        'app'
    ).innerHTML = `

    ${App.renderHeader()}

    <main class="max-w-7xl mx-auto px-6 py-8">

      <div class="flex justify-between items-center mb-6">

        <div>
          <h1 class="text-2xl font-bold">
            アンケート一覧
          </h1>
          <p class="text-slate-500 mt-1">
            作成・公開・集計・送信を管理します。
          </p>
        </div>

        <button
          onclick="App.actions.newSurvey()"
          class="bg-blue-600 hover:bg-blue-700
                 text-white px-5 py-3 rounded-xl
                 font-bold shadow-sm">
          ＋ 新規アンケート作成
        </button>

      </div>

      <div class="bg-white rounded-2xl border p-4 mb-5
                  flex gap-3">

        <input
          value="${App.util.esc(App.State.keyword)}"
          onkeydown="
            if(event.key==='Enter'){
              App.State.keyword=this.value;
              App.renderList();
            }
          "
          placeholder="タイトルを検索"
          class="border rounded-lg px-4 py-2 w-80">

        <select
          onchange="
            App.State.statusFilter=this.value;
            App.renderList();
          "
          class="border rounded-lg px-4 py-2">

          <option value="all"
            ${App.State.statusFilter==='all'?'selected':''}>
            すべて
          </option>

          <option value="active"
            ${App.State.statusFilter==='active'?'selected':''}>
            公開中
          </option>

          <option value="draft"
            ${App.State.statusFilter==='draft'?'selected':''}>
            下書き
          </option>

          <option value="ended"
            ${App.State.statusFilter==='ended'?'selected':''}>
            終了
          </option>

        </select>

        <select
          onchange="
            App.State.sort=this.value;
            App.renderList();
          "
          class="border rounded-lg px-4 py-2">

          <option value="updated_desc">
            更新日：新しい順
          </option>

          <option value="answers_desc">
            回答数：多い順
          </option>

          <option value="answers_asc">
            回答数：少ない順
          </option>

        </select>

      </div>

      <div class="bg-white rounded-2xl border overflow-x-auto">

        <table class="w-full text-sm">

          <thead class="bg-slate-100">
            <tr>
              <th class="p-4 text-left">アンケート</th>
              <th class="p-4 text-left">期間</th>
              <th class="p-4 text-left">ステータス</th>
              <th class="p-4 text-left">回答数</th>
              <th class="p-4 text-left">操作</th>
            </tr>
          </thead>

          <tbody>

          ${
            rows.length
            ? rows.map(
                s => {

                  const count =
                    App.util.answerCount(
                      s.id
                    );

                  let actions = `
                    <button
                      onclick="App.actions.editSurvey('${s.id}')"
                      class="px-3 py-2 rounded-lg
                             bg-slate-100 hover:bg-slate-200">
                      確認・編集
                    </button>

                    <button
                      onclick="App.actions.showAggregation('${s.id}')"
                      class="px-3 py-2 rounded-lg
                             bg-indigo-50 text-indigo-700">
                      集計
                    </button>

                    <button
                      onclick="App.actions.showMail('${s.id}')"
                      class="px-3 py-2 rounded-lg
                             bg-violet-50 text-violet-700">
                      送信
                    </button>
                  `;

                  if (s.status === 'active') {

                    actions += `
                      <button
                        onclick="App.actions.stopFromList('${s.id}')"
                        class="px-3 py-2 rounded-lg
                               bg-red-50 text-red-700 font-bold">
                        停止
                      </button>`;
                  }

                  if (s.status === 'draft') {

                    actions = `
                      <button
                        onclick="App.actions.editSurvey('${s.id}')"
                        class="px-3 py-2 rounded-lg
                               bg-slate-100">
                        確認・編集
                      </button>

                      <button
                        onclick="App.actions.publishFromList('${s.id}')"
                        class="px-3 py-2 rounded-lg
                               bg-blue-600 text-white font-bold">
                        公開する
                      </button>

                      <button
                        onclick="App.actions.deleteSurvey('${s.id}')"
                        class="px-3 py-2 rounded-lg
                               bg-red-50 text-red-700">
                        削除
                      </button>`;
                  }

                  if (s.status === 'ended') {

                    actions = `
                      <button
                        onclick="App.actions.editSurvey('${s.id}')"
                        class="px-3 py-2 rounded-lg
                               bg-slate-100">
                        確認・編集
                      </button>

                      <button
                        onclick="App.actions.showAggregation('${s.id}')"
                        class="px-3 py-2 rounded-lg
                               bg-indigo-50 text-indigo-700">
                        集計
                      </button>`;
                  }

                  actions += `
                    <button
                      onclick="App.actions.duplicateSurvey('${s.id}')"
                      class="px-3 py-2 rounded-lg
                             bg-slate-50 border">
                      複製
                    </button>`;

                  return `
                  <tr class="border-t hover:bg-slate-50">

                    <td class="p-4">
                      <div class="font-bold">
                        ${App.util.esc(s.title)}
                      </div>
                      <div class="text-xs text-slate-500 mt-1">
                        作成:
                        ${App.util.formatDate(s.created_at)}
                        <br>
                        更新:
                        ${App.util.formatDate(s.updated_at)}
                      </div>
                    </td>

                    <td class="p-4">
                      ${App.util.esc(s.start_at || '未設定')}
                      <br>～
                      ${App.util.esc(s.end_at || '未設定')}
                    </td>

                    <td class="p-4">
                      <span class="px-3 py-1 rounded-full text-xs
                                   ${App.util.statusClass(s.status)}">
                        ${App.util.statusLabel(s.status)}
                      </span>
                    </td>

                    <td class="p-4 font-bold">
                      ${count} 件
                    </td>

                    <td class="p-4">
                      <div class="flex flex-wrap gap-2">
                        ${actions}
                      </div>
                    </td>

                  </tr>`;
                }
              ).join('')
            : `
              <tr>
                <td colspan="5"
                    class="p-12 text-center text-slate-400">
                  アンケートがありません。
                </td>
              </tr>`
          }

          </tbody>
        </table>

      </div>

    </main>

    <input
      type="hidden"
      id="csrf_token"
      value="${App.util.esc(
        <?= survey_json($csrf) ?>
      )}">`;
},

renderEdit() {

    const survey =
        App.State.editingSurvey;

    if (!survey) {
        App.renderList();
        return;
    }

    App.actions.renumber();

    document.getElementById(
        'app'
    ).innerHTML = `

    ${App.renderHeader()}

    <main class="max-w-6xl mx-auto px-6 py-8">

      <div class="flex justify-between items-center mb-6">

        <div>
          <h1 class="text-2xl font-bold">
            アンケート作成・編集
          </h1>
          <p class="text-slate-500">
            ${App.util.statusLabel(survey.status)}
          </p>
        </div>

        <div class="flex gap-2">

          <button
            onclick="App.actions.preview()"
            class="px-4 py-2 rounded-lg
                   bg-white border">
            プレビュー
          </button>

          <button
            onclick="App.actions.cancelEdit()"
            class="px-4 py-2 rounded-lg
                   bg-slate-100">
            キャンセル
          </button>

          <button
            onclick="App.actions.saveSurvey()"
            class="px-4 py-2 rounded-lg
                   bg-blue-600 text-white font-bold">
            保存して一覧へ戻る
          </button>

        </div>

      </div>

      <section class="bg-white border rounded-2xl p-6 mb-6">

        <div class="grid grid-cols-3 gap-4">

          <label>
            <span class="font-bold text-sm">
              タイトル
            </span>

            <input
              id="survey_title"
              value="${App.util.esc(survey.title)}"
              class="w-full border rounded-lg px-4 py-3 mt-1">
          </label>

          <label>
            <span class="font-bold text-sm">
              開始日時
            </span>

            <input
              id="survey_start_at"
              type="datetime-local"
              value="${App.util.esc(survey.start_at)}"
              class="w-full border rounded-lg px-4 py-3 mt-1">
          </label>

          <label>
            <span class="font-bold text-sm">
              終了日時
            </span>

            <input
              id="survey_end_at"
              type="datetime-local"
              value="${App.util.esc(survey.end_at)}"
              class="w-full border rounded-lg px-4 py-3 mt-1">
          </label>

        </div>

        <div class="mt-4">

          <label>
            <span class="font-bold text-sm">
              質問番号
            </span>

            <select
              id="survey_numbering_mode"
              class="border rounded-lg px-4 py-2 mt-1">

              <option value="global"
                ${survey.numbering_mode==='global'
                    ? 'selected':''}>
                Q1、Q2、Q3...
              </option>

              <option value="group"
                ${survey.numbering_mode==='group'
                    ? 'selected':''}>
                Q1-1、Q1-2、Q2-1...
              </option>

            </select>
          </label>

        </div>

      </section>

      <div
        id="group_sortable"
        class="space-y-5">

        ${
          survey.groups.map(
            group => `

            <section
              class="bg-white border rounded-2xl p-5"
              data-group="${group.id}">

              <div class="flex items-center gap-3 mb-5">

                <span
                  class="group-handle cursor-grab
                         text-slate-400 text-xl">
                  ⠿
                </span>

                <input
                  value="${App.util.esc(group.name)}"
                  onchange="
                    App.actions.updateGroupName(
                      '${group.id}',
                      this.value
                    )
                  "
                  class="flex-1 text-lg font-bold
                         border-b px-2 py-2">

                <button
                  onclick="
                    App.actions.removeGroup(
                      '${group.id}'
                    )
                  "
                  class="text-red-600">
                  グループ削除
                </button>

              </div>

              <div
                id="questions_${group.id}"
                class="space-y-4">

                ${
                  group.questions.map(
                    (q, qi) => `

                    <div
                      class="border rounded-xl p-5 bg-slate-50"
                      data-question="${q.id}">

                      <div class="flex gap-3">

                        <span
                          class="question-handle cursor-grab
                                 text-slate-400 text-xl">
                          ⠿
                        </span>

                        <div class="flex-1">

                          <div class="flex justify-between mb-3">

                            <span
                              class="font-bold text-blue-600">
                              ${q.number}
                            </span>

                            <button
                              onclick="
                                App.actions.removeQuestion(
                                  '${group.id}',
                                  '${q.id}'
                                )
                              "
                              class="text-red-600">
                              削除
                            </button>

                          </div>

                          <input
                            value="${App.util.esc(q.text)}"
                            onchange="
                              App.actions.updateQuestion(
                                '${group.id}',
                                '${q.id}',
                                'text',
                                this.value
                              )
                            "
                            class="w-full border rounded-lg
                                   px-4 py-3 bg-white mb-3">

                          <div class="flex gap-3 mb-3">

                            <select
                              onchange="
                                App.actions.updateQuestion(
                                  '${group.id}',
                                  '${q.id}',
                                  'type',
                                  this.value
                                );
                                App.renderEdit();
                              "
                              class="border rounded-lg px-3 py-2">

                              <option value="single"
                                ${q.type==='single'
                                    ? 'selected':''}>
                                単一選択
                              </option>

                              <option value="multiple"
                                ${q.type==='multiple'
                                    ? 'selected':''}>
                                複数選択
                              </option>

                              <option value="text"
                                ${q.type==='text'
                                    ? 'selected':''}>
                                自由記述
                              </option>

                            </select>

                            <label
                              class="flex items-center gap-2">

                              <input
                                type="checkbox"
                                ${q.required?'checked':''}
                                onchange="
                                  App.actions.updateQuestion(
                                    '${group.id}',
                                    '${q.id}',
                                    'required',
                                    this.checked
                                  )
                                ">

                              必須回答

                            </label>

                          </div>

                          ${
                            q.type !== 'text'
                            ? `

                            <div class="space-y-2">

                              ${
                                (q.options || []).map(
                                  (option, oi) => `

                                  <div
                                    class="flex gap-2">

                                    <input
                                      value="${App.util.esc(option)}"
                                      onchange="
                                        App.actions.updateOption(
                                          '${group.id}',
                                          '${q.id}',
                                          ${oi},
                                          this.value
                                        )
                                      "
                                      class="flex-1 border
                                             rounded-lg px-3 py-2
                                             bg-white">

                                    <button
                                      onclick="
                                        App.actions.removeOption(
                                          '${group.id}',
                                          '${q.id}',
                                          ${oi}
                                        )
                                      "
                                      class="text-red-600">
                                      ×
                                    </button>

                                  </div>`
                                ).join('')
                              }

                              <button
                                onclick="
                                  App.actions.addOption(
                                    '${group.id}',
                                    '${q.id}'
                                  )
                                "
                                class="text-blue-600">
                                ＋ 選択肢追加
                              </button>

                            </div>`
                            : `
                              <textarea
                                disabled
                                class="w-full border rounded-lg
                                       p-3 bg-white"
                                placeholder="回答者の自由記述欄">
                              </textarea>`
                          }

                        </div>

                      </div>

                    </div>`
                  ).join('')
                }

              </div>

              <button
                onclick="
                  App.actions.addQuestion(
                    '${group.id}'
                  )
                "
                class="mt-4 px-4 py-2 rounded-lg
                       bg-blue-50 text-blue-700 font-bold">
                ＋ 質問を追加
              </button>

            </section>`
          ).join('')
        }

      </div>

      <button
        onclick="App.actions.addGroup()"
        class="mt-5 px-5 py-3 rounded-xl
               bg-slate-800 text-white font-bold">
        ＋ グループを追加
      </button>

    </main>

    <div
      id="preview_modal"
      class="hidden fixed inset-0 bg-black/50
             z-50 p-6">

      <div
        class="bg-white max-w-3xl mx-auto rounded-2xl
               max-h-[90vh] overflow-auto">

        <div
          class="p-4 border-b flex justify-between">

          <b>プレビュー</b>

          <button
            onclick="App.actions.closePreview()"
            class="text-slate-500">
            閉じる
          </button>

        </div>

        <div
          id="preview_content"
          class="p-6">
        </div>

      </div>

    </div>
    `;

    document.getElementById(
        'csrf_token'
    ).value =
        <?= survey_json($csrf) ?>;

    App.actions.initSortables();
},

renderPreviewHtml(survey) {

    let n = 1;

    return `
      <div class="max-w-xl mx-auto">

        <h2 class="text-2xl font-bold mb-8">
          ${App.util.esc(survey.title)}
        </h2>

        ${
          (survey.groups || []).map(
            group => `

            <section class="mb-8">

              <h3 class="text-lg font-bold mb-4">
                ${App.util.esc(group.name)}
              </h3>

              ${
                (group.questions || []).map(
                  q => {

                    const number =
                      survey.numbering_mode ===
                      'group'
                      ? q.number
                      : 'Q' + n++;

                    return `

                    <div class="mb-7">

                      <div class="font-bold mb-3">
                        ${number}.
                        ${App.util.esc(q.text)}
                        ${
                          q.required
                          ? '<span class="text-red-500"> *</span>'
                          : ''
                        }
                      </div>

                      ${
                        q.type === 'text'
                        ? `
                          <textarea
                            class="w-full border
                                   rounded-lg p-3"
                            rows="4">
                          </textarea>`
                        :
                        (q.options || []).map(
                          option => `

                          <label
                            class="flex gap-2 mb-2">

                            <input
                              type="${
                                q.type ===
                                'multiple'
                                ? 'checkbox'
                                : 'radio'
                              }"
                              name="${q.id}">

                            <span>
                              ${App.util.esc(option)}
                            </span>

                          </label>`
                        ).join('')
                      }

                    </div>`;
                  }
                ).join('')
              }

            </section>`
          ).join('')
        }

        <button
          onclick="
            alert(
              'これはプレビューです。\\n送信は実行されません。'
            );
            return false;
          "
          class="w-full bg-blue-600
                 text-white rounded-xl py-3 font-bold">
          回答を送信
        </button>

      </div>`;
},

renderSettings() {

    const s =
        App.State.data.settings || {};

    const fieldOptions =
        App.State.fields || [];

    const selectField =
        (
            id,
            label,
            current,
            multiple = false
        ) => {

            if (multiple) {

                return `
                <div>
                  <label class="font-bold text-sm">
                    ${label}
                  </label>

                  <div
                    class="border rounded-lg p-3
                           mt-1 max-h-48 overflow-auto">

                    ${
                      fieldOptions.map(
                        f => {

                          const checked =
                            Array.isArray(
                              current
                            ) &&
                            current.includes(
                              f.code
                            );

                          return `
                          <label
                            class="flex gap-2 py-1">

                            <input
                              type="checkbox"
                              class="address_field"
                              value="${App.util.esc(f.code)}"
                              ${checked?'checked':''}>

                            <span>
                              ${App.util.esc(f.label)}
                              <span
                                class="text-xs text-slate-400">
                                (${App.util.esc(f.code)})
                              </span>
                            </span>

                          </label>`;
                        }
                      ).join('')
                    }

                  </div>
                </div>`;
            }

            return `
            <label>
              <span class="font-bold text-sm">
                ${label}
              </span>

              <select
                id="${id}"
                class="w-full border rounded-lg
                       px-3 py-2 mt-1">

                <option value="">
                  -- 未設定 --
                </option>

                ${
                  fieldOptions.map(
                    f => `
                    <option
                      value="${App.util.esc(f.code)}"
                      ${current===f.code
                        ?'selected':''}>
                      ${App.util.esc(f.label)}
                      (${App.util.esc(f.code)})
                    </option>`
                  ).join('')
                }

              </select>
            </label>`;
        };

    document.getElementById(
        'app'
    ).innerHTML = `

    ${App.renderHeader()}

    <main class="max-w-5xl mx-auto px-6 py-8">

      <div class="mb-6">

        <h1 class="text-2xl font-bold">
          kintone連携設定
        </h1>

        <p class="text-slate-500 mt-1">
          PHPサーバーからkintone APIへ接続します。
        </p>

      </div>

      <form
        id="settings_form"
        class="bg-white border rounded-2xl p-6">

        <div class="grid grid-cols-2 gap-5">

          <label class="col-span-2">
            <span class="font-bold text-sm">
              kintoneホスト
            </span>

            <input
              id="setting_subdomain"
              value="${App.util.esc(
                s.subdomain || ''
              )}"
              placeholder="xxxx.cybozu.com"
              class="w-full border rounded-lg
                     px-4 py-3 mt-1">

            <span
              class="text-xs text-slate-500">
              xxxx.cybozu.com / https://xxxx.cybozu.com/
              のいずれも入力可能
            </span>
          </label>

          <label>
            <span class="font-bold text-sm">
              顧客管理アプリID
            </span>

            <input
              id="setting_app_id"
              value="${App.util.esc(
                s.app_id || ''
              )}"
              class="w-full border rounded-lg
                     px-4 py-3 mt-1">
          </label>

          <label>
            <span class="font-bold text-sm">
              ログイン名
            </span>

            <input
              id="setting_login_name"
              value="${App.util.esc(
                s.login_name || ''
              )}"
              autocomplete="username"
              class="w-full border rounded-lg
                     px-4 py-3 mt-1">
          </label>

          <label>
            <span class="font-bold text-sm">
              パスワード
            </span>

            <!-- ★既存パスワードをHTMLへ出さない -->
            <input
              id="setting_password"
              type="password"
              value=""
              autocomplete="new-password"
              placeholder="変更する場合のみ入力"
              class="w-full border rounded-lg
                     px-4 py-3 mt-1">
          </label>

          <label>
            <span class="font-bold text-sm">
              Proxy
            </span>

            <input
              id="setting_proxy"
              value="${App.util.esc(
                s.proxy || ''
              )}"
              placeholder="host:port"
              class="w-full border rounded-lg
                     px-4 py-3 mt-1">
          </label>

          <label
            class="flex items-center gap-3 mt-7">

            <input
              id="setting_ssl_verify"
              type="checkbox"
              ${s.ssl_verify !== false
                ? 'checked':''}>

            SSL証明書を検証する

          </label>

        </div>

        <div class="mt-8">

          <div class="flex justify-between items-center">

            <h2 class="font-bold text-lg">
              kintone項目マッピング
            </h2>

            <div class="flex gap-2">

              <button
                type="button"
                onclick="
                  App.actions.fetchKintoneFields()
                "
                class="px-4 py-2 rounded-lg
                       bg-indigo-600 text-white font-bold">
                項目一覧を再取得
              </button>

              <button
                type="button"
                onclick="
                  App.actions.testKintone()
                "
                class="px-4 py-2 rounded-lg
                       bg-emerald-600 text-white font-bold">
                接続確認
              </button>

            </div>

          </div>

          <div
            id="field_message"
            class="mt-3 text-sm text-slate-500">
            ${
              fieldOptions.length
              ? `取得済み: ${fieldOptions.length}件`
              : 'アプリIDを入力して「項目一覧を再取得」を押してください。'
            }
          </div>

          <div
            class="grid grid-cols-2 gap-5 mt-5">

            ${
              selectField(
                'setting_field_company',
                '会社名',
                s.field_company || ''
              )
            }

            ${
              selectField(
                'setting_field_name',
                '氏名',
                s.field_name || ''
              )
            }

            ${
              selectField(
                'setting_field_email',
                'メールアドレス',
                s.field_email || ''
              )
            }

            ${
              selectField(
                'setting_field_department',
                '部署名',
                s.field_department || ''
              )
            }

            ${
              selectField(
                'setting_field_phone',
                '電話番号',
                s.field_phone || ''
              )
            }

            ${
              selectField(
                '',
                '住所',
                s.field_address || [],
                true
              )
            }

          </div>

        </div>

        <div
          class="mt-8 flex justify-end gap-3">

          <button
            type="button"
            onclick="
              App.State.screen='list';
              App.renderList();
            "
            class="px-5 py-3 rounded-lg
                   bg-slate-100">
            戻る
          </button>

          <button
            type="button"
            onclick="
              App.actions.saveSettings()
            "
            class="px-5 py-3 rounded-lg
                   bg-blue-600 text-white font-bold">
            設定を保存
          </button>

        </div>

      </form>

    </main>

    <input
      type="hidden"
      id="csrf_token"
      value="${App.util.esc(
        <?= survey_json($csrf) ?>
      )}">
    `;
},

renderAggregation() {

    const survey =
        App.State.data.surveys.find(
            s =>
            s.id ===
            App.State.selectedSurveyId
        );

    if (!survey) {
        App.renderList();
        return;
    }

    const responses =
        App.State.data.responses.filter(
            r =>
            r.survey_id === survey.id
        );

    const questions = [];

    for (const g of survey.groups || []) {
        for (const q of g.questions || []) {
            questions.push(q);
        }
    }

    const answered =
        responses.length;

    const customers =
        App.State.data.customers.filter(
            c =>
            Number(c.send_count || 0) > 0
        );

    const sent =
        customers.length;

    const answerRate =
        sent
        ? ((answered / sent) * 100).toFixed(1)
        : '0.0';

    document.getElementById(
        'app'
    ).innerHTML = `

    ${App.renderHeader()}

    <main class="max-w-7xl mx-auto px-6 py-8">

      <div class="flex justify-between mb-6">

        <div>
          <h1 class="text-2xl font-bold">
            回答集計・分析
          </h1>

          <p class="text-slate-500">
            ${App.util.esc(survey.title)}
          </p>
        </div>

        <button
          onclick="
            App.State.screen='list';
            App.renderList();
          "
          class="px-4 py-2 rounded-lg bg-slate-100">
          一覧へ戻る
        </button>

      </div>

      <div
        class="grid grid-cols-5 gap-4 mb-8">

        ${[
          ['送信対象者数', sent + ' 人'],
          ['回答数', answered + ' 件'],
          ['未登録顧客からの回答数',
            responses.filter(
              r => !r.customer_id
            ).length + ' 件'],
          ['未回答数',
            Math.max(sent - answered, 0) + ' 人'],
          ['回答率', answerRate + ' %']
        ].map(
          x => `
          <div
            class="bg-white border rounded-2xl p-5">

            <div class="text-sm text-slate-500">
              ${x[0]}
            </div>

            <div class="text-2xl font-bold mt-2">
              ${x[1]}
            </div>

          </div>`
        ).join('')}

      </div>

      <section
        class="bg-white border rounded-2xl p-6 mb-6">

        <h2 class="font-bold text-lg mb-5">
          設問別集計
        </h2>

        <div class="space-y-6">

          ${
            questions.map(
              q => {

                const counts = {};

                for (const option of q.options || []) {
                    counts[option] = 0;
                }

                for (const response of responses) {

                    const value =
                        response.answers?.[q.id];

                    if (Array.isArray(value)) {

                        value.forEach(
                            v => {
                                counts[v] =
                                    (counts[v] || 0) + 1;
                            }
                        );

                    } else if (value) {

                        counts[value] =
                            (counts[value] || 0) + 1;
                    }
                }

                const total =
                    responses.length || 1;

                return `
                <div class="border rounded-xl p-5">

                  <div class="font-bold mb-4">
                    ${App.util.esc(q.number || '')}
                    ${App.util.esc(q.text)}
                  </div>

                  ${
                    q.type === 'text'
                    ? `
                      <div class="space-y-2">

                        ${
                          responses.map(
                            r => {

                              const value =
                                r.answers?.[q.id];

                              if (!value) {
                                return '';
                              }

                              return `
                              <div
                                class="bg-slate-50
                                       rounded-lg p-3">

                                <div
                                  class="text-xs
                                         text-slate-500">
                                  ${App.util.esc(
                                    r.company || ''
                                  )}
                                  ${App.util.esc(
                                    r.name || ''
                                  )}
                                </div>

                                <div class="mt-1">
                                  ${App.util.esc(
                                    Array.isArray(value)
                                    ? value.join(' / ')
                                    : value
                                  )}
                                </div>

                              </div>`;
                            }
                          ).join('')
                        }

                      </div>`
                    :
                    `
                    <div class="space-y-3">

                      ${
                        Object.entries(
                          counts
                        ).map(
                          ([label, count]) => {

                            const pct =
                              Math.round(
                                count /
                                total *
                                100
                              );

                            return `
                            <div>

                              <div
                                class="flex justify-between
                                       text-sm mb-1">

                                <span>
                                  ${App.util.esc(label)}
                                </span>

                                <span>
                                  ${count}件
                                  (${pct}%)
                                </span>

                              </div>

                              <div
                                class="h-3 bg-slate-100
                                       rounded-full">

                                <div
                                  class="h-3 bg-blue-600
                                         rounded-full"
                                  style="
                                    width:${pct}%
                                  ">
                                </div>

                              </div>

                            </div>`;
                          }
                        ).join('')
                      }

                    </div>`
                  }

                </div>`;
              }
            ).join('')
          }

        </div>

      </section>

      <section
        class="bg-white border rounded-2xl p-6">

        <h2 class="font-bold text-lg mb-4">
          個別回答一覧
        </h2>

        ${
          responses.length
          ? `
          <div
            class="overflow-x-auto">

            <table class="w-full text-sm">

              <thead
                class="bg-slate-100">

                <tr>
                  <th class="p-3 text-left">
                    会社名
                  </th>

                  <th class="p-3 text-left">
                    氏名
                  </th>

                  <th class="p-3 text-left">
                    回答日時
                  </th>

                  <th class="p-3">
                  </th>
                </tr>

              </thead>

              <tbody>

                ${
                  responses.map(
                    r => `
                    <tr class="border-t">

                      <td class="p-3">
                        ${App.util.esc(
                          r.company || ''
                        )}
                      </td>

                      <td class="p-3">
                        ${App.util.esc(
                          r.name || ''
                        )}
                      </td>

                      <td class="p-3">
                        ${App.util.formatDate(
                          r.answered_at
                        )}
                      </td>

                      <td class="p-3 text-right">

                        <button
                          onclick="
                            App.actions.showResponse(
                              '${r.id}'
                            )
                          "
                          class="px-3 py-2
                                 rounded-lg
                                 bg-indigo-50
                                 text-indigo-700">
                          全回答を表示
                        </button>

                      </td>

                    </tr>`
                  ).join('')
                }

              </tbody>

            </table>

          </div>`
          :
          `
          <div
            class="text-center p-12
                   text-slate-400">
            現在、回答データはありません
          </div>`
        }

      </section>

    </main>

    <div
      id="response_modal"
      class="hidden fixed inset-0
             bg-black/50 z-50 p-6">

      <div
        class="max-w-3xl mx-auto bg-white
               rounded-2xl max-h-[90vh]
               overflow-auto">

        <div
          class="p-4 border-b
                 flex justify-between">

          <b>回答詳細</b>

          <button
            onclick="App.actions.closeResponse()">
            閉じる
          </button>

        </div>

        <div
          id="response_detail"
          class="p-6">
        </div>

      </div>

    </div>

    <input
      type="hidden"
      id="csrf_token"
      value="${App.util.esc(
        <?= survey_json($csrf) ?>
      )}">
    `;
},

renderResponseDetail(response) {

    const survey =
        App.State.data.surveys.find(
            s =>
            s.id ===
            response.survey_id
        );

    if (!survey) {
        return 'アンケートが見つかりません。';
    }

    const questions = [];

    for (const group of survey.groups || []) {
        for (const q of group.questions || []) {
            questions.push(q);
        }
    }

    return `

      <div class="mb-6">

        <div class="font-bold">
          ${App.util.esc(
            response.company || ''
          )}
          ${App.util.esc(
            response.name || ''
          )}
        </div>

        <div class="text-sm text-slate-500">
          ${App.util.esc(
            response.email || ''
          )}
          /
          ${App.util.formatDate(
            response.answered_at
          )}
        </div>

      </div>

      <div class="space-y-5">

        ${
          questions.map(
            q => {

              let value =
                  response.answers?.[q.id] ??
                  '';

              if (Array.isArray(value)) {
                  value =
                    value.join(' / ');
              }

              return `
              <div
                class="border rounded-xl p-4">

                <div
                  class="font-bold mb-2">
                  ${App.util.esc(
                    q.number || ''
                  )}
                  ${App.util.esc(q.text)}
                </div>

                <div>
                  ${App.util.esc(value)}
                </div>

              </div>`;
            }
          ).join('')
        }

      </div>`;
},

renderMail() {

    const survey =
        App.State.data.surveys.find(
            s =>
            s.id ===
            App.State.selectedSurveyId
        );

    if (!survey) {
        App.renderList();
        return;
    }

    const customers =
        App.State.data.customers;

    document.getElementById(
        'app'
    ).innerHTML = `

    ${App.renderHeader()}

    <main class="max-w-7xl mx-auto px-6 py-8">

      <div
        class="flex justify-between mb-6">

        <div>
          <h1 class="text-2xl font-bold">
            顧客選択・メール送信
          </h1>

          <p class="text-slate-500">
            ${App.util.esc(
              survey.title
            )}
          </p>
        </div>

        <button
          onclick="
            App.State.screen='list';
            App.renderList();
          "
          class="px-4 py-2 rounded-lg
                 bg-slate-100">
          一覧へ戻る
        </button>

      </div>

      <section
        class="bg-white border rounded-2xl p-6 mb-6">

        <div
          class="grid grid-cols-2 gap-5">

          <label>

            <span class="font-bold">
              件名
            </span>

            <input
              id="mail_subject"
              value="${App.util.esc(
                survey.title
              )}"
              class="w-full border
                     rounded-lg px-3 py-2 mt-1">

          </label>

          <label>

            <span class="font-bold">
              テンプレート
            </span>

            <select
              id="template_type"
              class="w-full border
                     rounded-lg px-3 py-2 mt-1">

              <option value="initial">
                初回送信
              </option>

              <option value="reminder">
                リマインド
              </option>

            </select>

          </label>

        </div>

        <label class="block mt-5">

          <span class="font-bold">
            本文
          </span>

          <textarea
            id="mail_body"
            rows="8"
            class="w-full border
                   rounded-lg p-3 mt-1">{
顧客名} 様

アンケートへのご回答をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>

        </label>

        <div class="mt-3 text-sm text-slate-500">
          使用可能な変数：
          {顧客名}
          {アンケートURL}
        </div>

      </section>

      <section
        class="bg-white border rounded-2xl p-6">

        <div
          class="flex justify-between items-center mb-4">

          <h2 class="font-bold text-lg">
            顧客一覧
          </h2>

          <input
            id="customer_filter"
            oninput="
              App.renderMail()
            "
            placeholder="顧客名・メール検索"
            class="border rounded-lg
                   px-3 py-2">

        </div>

        <div class="overflow-x-auto">

          <table
            id="customer_table"
            class="w-full text-sm">

            <thead class="bg-slate-100">

              <tr>

                <th class="p-3">
                  <input
                    id="select_all"
                    type="checkbox">
                </th>

                <th class="p-3 text-left">
                  会社名 / 氏名
                </th>

                <th class="p-3 text-left">
                  メール
                </th>

                <th class="p-3 text-left">
                  送信履歴
                </th>

                <th class="p-3 text-left">
                  回答
                </th>

              </tr>

            </thead>

            <tbody>

              ${
                customers.map(
                  c => `

                  <tr
                    class="border-t">

                    <td class="p-3">

                      <input
                        type="checkbox"
                        class="recipient"
                        value="${App.util.esc(
                          c.id
                        )}">

                    </td>

                    <td class="p-3">

                      <b>
                        ${App.util.esc(
                          c.company
                        )}
                      </b>

                      <br>

                      ${App.util.esc(
                        c.name
                      )}

                    </td>

                    <td class="p-3">
                      ${App.util.esc(
                        c.email
                      )}
                    </td>

                    <td class="p-3">

                      ${
                        c.sent_at
                        ? App.util.formatDate(
                            c.sent_at
                          )
                        : '未送信'
                      }

                      <br>

                      ${Number(
                        c.send_count || 0
                      )}回

                    </td>

                    <td class="p-3">

                      ${
                        c.answer_status ===
                        'answered'
                        ? '<span class="text-emerald-600">回答済み</span>'
                        : '<span class="text-amber-600">未回答</span>'
                      }

                    </td>

                  </tr>`
                ).join('')
              }

            </tbody>

          </table>

        </div>

        <div class="mt-5 flex justify-end">

          <button
            onclick="
              alert(
                'この1ファイル版ではメール送信処理の実送信部分を未接続です。'
              )
            "
            class="px-6 py-3 rounded-xl
                   bg-blue-600 text-white
                   font-bold">
            一括送信実行
          </button>

        </div>

      </section>

    </main>

    <input
      type="hidden"
      id="csrf_token"
      value="${App.util.esc(
        <?= survey_json($csrf) ?>
      )}">
    `;
},

/* ==========================================================
 * Init
 * ========================================================== */

init() {

    if (
        window.__survey_app_initialized
    ) {
        return;
    }

    window.__survey_app_initialized =
        true;

    App.actions.load();
}

};

if (
    document.readyState ===
    'loading'
) {

    document.addEventListener(
        'DOMContentLoaded',
        () => App.init(),
        {once:true}
    );

} else {

    App.init();
}
</script>

</body>
</html>

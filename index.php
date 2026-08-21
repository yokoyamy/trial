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
- number

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

/* ---------------------------------------------------------------
 * 初期環境
 * --------------------------------------------------------------- */

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SURVEY_ADMIN_SESSION);
    @session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

/* ---------------------------------------------------------------
 * 共通
 * --------------------------------------------------------------- */

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
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    return is_string($json) ? $json : 'null';
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

    if (!is_string($raw) || trim($raw) === '') {
        return survey_default_data();
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        return survey_default_data();
    }

    return array_replace_recursive(
        survey_default_data(),
        $decoded
    );
}

function survey_write_data(array $data): bool
{
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        if (!@mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true) &&
            !is_dir(SURVEY_STORAGE_DIRECTORY)) {
            return false;
        }
    }

    $tmp = SURVEY_STORAGE_FILE . '.tmp';

    $written = @file_put_contents(
        $tmp,
        survey_json($data),
        LOCK_EX
    );

    if ($written === false) {
        return false;
    }

    if (@rename($tmp, SURVEY_STORAGE_FILE)) {
        return true;
    }

    @unlink($tmp);

    return false;
}

function survey_token(): string
{
    if (!isset($_SESSION['csrf_token']) ||
        !is_string($_SESSION['csrf_token']) ||
        $_SESSION['csrf_token'] === '') {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
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

/* ---------------------------------------------------------------
 * アンケート補正
 * --------------------------------------------------------------- */

function survey_normalize_question(array $q): array
{
    $type = (string)($q['type'] ?? 'single');

    if (!in_array($type, ['single', 'multiple', 'text'], true)) {
        $type = 'single';
    }

    $options = $q['options'] ?? [];

    if (!is_array($options)) {
        $options = [];
    }

    $options = array_values(
        array_map(
            static fn($v): string => (string)$v,
            $options
        )
    );

    if ($type === 'text') {
        $options = [];
    }

    return [
        'id' => (string)($q['id'] ?? survey_id()),
        'text' => (string)($q['text'] ?? ''),
        'type' => $type,
        'required' => !empty($q['required']),
        'options' => $options,
        'other_enabled' => !empty($q['other_enabled']),
        'number' => (string)($q['number'] ?? ''),
    ];
}

function survey_normalize_group(array $g): array
{
    $questions = [];

    foreach (($g['questions'] ?? []) as $q) {
        if (is_array($q)) {
            $questions[] = survey_normalize_question($q);
        }
    }

    return [
        'id' => (string)($g['id'] ?? survey_id()),
        'name' => (string)($g['name'] ?? '新しいグループ'),
        'questions' => $questions,
    ];
}

function survey_normalize_survey(array $s): array
{
    $groups = [];

    foreach (($s['groups'] ?? []) as $g) {
        if (is_array($g)) {
            $groups[] = survey_normalize_group($g);
        }
    }

    $status = (string)($s['status'] ?? 'draft');

    if (!in_array($status, ['draft', 'active', 'ended'], true)) {
        $status = 'draft';
    }

    $mode = (string)($s['numbering_mode'] ?? 'global');

    if (!in_array($mode, ['global', 'group'], true)) {
        $mode = 'global';
    }

    return [
        'id' => (string)($s['id'] ?? survey_id()),
        'title' => (string)($s['title'] ?? ''),
        'start_at' => (string)($s['start_at'] ?? ''),
        'end_at' => (string)($s['end_at'] ?? ''),
        'status' => $status,
        'created_at' => (string)($s['created_at'] ?? ''),
        'updated_at' => (string)($s['updated_at'] ?? ''),
        'numbering_mode' => $mode,
        'groups' => $groups,
        'deleted' => !empty($s['deleted']),
    ];
}

/* ---------------------------------------------------------------
 * kintone URL
 * --------------------------------------------------------------- */

function survey_normalize_kintone_base(string $input): array
{
    $input = trim($input);
    $input = rtrim($input, "/ \t\r\n");

    if ($input === '') {
        return [
            'ok' => false,
            'error' => 'kintoneサブドメインが未入力です。',
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

    if ($host === '' &&
        preg_match(
            '~^https?://([^/?#]+)~i',
            $input,
            $matches
        )) {
        $authority = strtolower($matches[1]);

        if (preg_match(
            '~^(.+):([0-9]+)$~',
            $authority,
            $pm
        )) {
            $host = $pm[1];
            $port = (int)$pm[2];
        } else {
            $host = $authority;
        }
    }

    $host = strtolower(trim($host));
    $host = trim($host, '[]');

    if ($host === '') {
        return [
            'ok' => false,
            'error' => 'kintoneホスト名を取得できません。',
        ];
    }

    if ($port !== null &&
        ($port < 1 || $port > 65535)) {
        return [
            'ok' => false,
            'error' => 'kintoneポート番号が不正です。',
        ];
    }

    /*
     * cybozu.com を正式許可。
     * それ以外のFQDNも検証環境・社内環境用として
     * ホスト名形式を許可する。
     */
    $isCybozu = preg_match(
        '~^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.cybozu\.com$~i',
        $host
    );

    $isGenericHost = preg_match(
        '~^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$~i',
        $host
    );

    if (!$isCybozu && !$isGenericHost) {
        return [
            'ok' => false,
            'error' => '許可されていないkintoneホスト名です。',
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

/* ---------------------------------------------------------------
 * Proxy
 * --------------------------------------------------------------- */

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
                'Proxy形式は host:port、http://host:port、https://host:port です。',
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

    return [
        'ok' => true,
        'used' => true,
        'value' => 'tcp://' . $host . ':' . $port,
        'display' => $scheme . '://' . $host . ':' . $port,
        'host' => $host,
        'port' => $port,
    ];
}

/* ---------------------------------------------------------------
 * HTTP response headers
 * --------------------------------------------------------------- */

function survey_last_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        try {
            $headers = http_get_last_response_headers();

            return is_array($headers) ? $headers : [];
        } catch (Throwable) {
            return [];
        }
    }

    $headers = $GLOBALS['http_response_header'] ?? null;

    return is_array($headers) ? $headers : [];
}

function survey_status_from_headers(array $headers): int
{
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

/* ---------------------------------------------------------------
 * HTTP通信
 * --------------------------------------------------------------- */

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

    if (!in_array('http', $wrappers, true) ||
        !in_array('https', $wrappers, true)) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' =>
                'PHPのHTTP/HTTPS stream wrapperが利用できません。'
                . ' allow_url_fopen、HTTP wrapper、OpenSSLを確認してください。',
            'url' => $url,
            'proxy_used' => $proxyInfo['used'],
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

    $http = [
        'method' => strtoupper($method),
        'timeout' => 30,
        'ignore_errors' => true,
        'protocol_version' => 1.1,
        'header' => implode("\r\n", $headers),
    ];

    if ($content !== null &&
        strtoupper($method) !== 'GET') {
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
    $body = false;

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

        if ($warning === '') {
            $warning = $e->getMessage();
        }
    }

    restore_error_handler();

    $responseHeaders = survey_last_headers();
    $status = survey_status_from_headers($responseHeaders);

    $bodyText = is_string($body) ? $body : '';
    $json = json_decode($bodyText, true);

    if ($status === 0) {
        $diagnostic = $warning !== ''
            ? $warning
            : 'HTTPレスポンスを取得できませんでした。';

        $diagnostic .=
            "\n確認事項: DNS名前解決、PHPサーバーからの外部HTTPS通信、"
            . "Proxy、Proxy形式、ファイアウォール、SSL/TLS、OpenSSL、タイムアウト。";

        if ($proxyInfo['used']) {
            $diagnostic .=
                "\nProxy: 使用\nProxy接続失敗の可能性があります。";
        } else {
            $diagnostic .= "\nProxy: 未使用";
        }

        return [
            'status' => 0,
            'body' => $bodyText,
            'json' => $json,
            'error' => $diagnostic,
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

/* ---------------------------------------------------------------
 * kintone
 * --------------------------------------------------------------- */

function survey_kintone_request(array $settings): array
{
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

    $url =
        $normalized['base']
        . '/k/v1/app/form/fields.json?app='
        . rawurlencode($appId);

    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');

    $authorization = base64_encode(
        $login . ':' . $password
    );

    return survey_http_request(
        $url,
        'GET',
        [
            'X-Cybozu-Authorization: ' . $authorization,
            'Accept: application/json',
            'Connection: close',
        ],
        null,
        (bool)($settings['ssl_verify'] ?? true),
        (string)($settings['proxy'] ?? '')
    );
}

function survey_kintone_message(array $r): string
{
    $status = (int)($r['status'] ?? 0);
    $url = (string)($r['url'] ?? '');
    $error = trim((string)($r['error'] ?? ''));
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
            . "\n確認事項: DNS、外部HTTPS通信、Proxy、"
            . "ファイアウォール、SSL/TLS、OpenSSL。";
    }

    if ($status === 401 || $status === 403) {
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

function survey_kintone_fields(array $r): array
{
    if ((int)($r['status'] ?? 0) < 200 ||
        (int)($r['status'] ?? 0) >= 300) {
        return [
            'ok' => false,
            'fields' => [],
            'message' => survey_kintone_message($r),
        ];
    }

    $json = $r['json'] ?? null;

    if (!is_array($json) ||
        !isset($json['properties']) ||
        !is_array($json['properties'])) {
        return [
            'ok' => false,
            'fields' => [],
            'message' =>
                'kintone APIレスポンスに properties がありません。',
        ];
    }

    $fields = [];

    foreach ($json['properties'] as $code => $property) {
        if (!is_array($property)) {
            continue;
        }

        $fields[] = [
            'code' => (string)$code,
            'label' => (string)(
                $property['label'] ?? $code
            ),
            'type' => (string)(
                $property['type'] ?? ''
            ),
        ];
    }

    return [
        'ok' => true,
        'fields' => $fields,
        'message' => '項目一覧を取得しました。',
    ];
}

/* ---------------------------------------------------------------
 * CSV
 * --------------------------------------------------------------- */

function survey_csv_download(
    array $data,
    string $surveyId
): never {
    $survey = null;

    foreach ($data['surveys'] as $s) {
        if (($s['id'] ?? '') === $surveyId) {
            $survey = $s;
            break;
        }
    }

    if (!is_array($survey)) {
        http_response_code(404);
        exit('Survey not found');
    }

    $questions = [];

    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            $questions[] = $question;
        }
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey_'
        . preg_replace(
            '/[^a-zA-Z0-9_-]/',
            '_',
            $surveyId
        )
        . '.csv"'
    );

    $fp = fopen('php://output', 'wb');

    if ($fp === false) {
        exit;
    }

    fwrite($fp, "\xEF\xBB\xBF");

    $header = [
        '回答ID',
        '回答日時',
        '顧客ID',
        '会社名',
        '氏名',
        'メールアドレス',
    ];

    foreach ($questions as $q) {
        $header[] = (string)($q['text'] ?? '');
    }

    fputcsv($fp, $header);

    foreach ($data['responses'] as $response) {
        if (($response['survey_id'] ?? '') !== $surveyId) {
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

        $answers = $response['answers'] ?? [];

        foreach ($questions as $q) {
            $value = $answers[$q['id'] ?? ''] ?? '';

            if (is_array($value)) {
                $value = implode('、', $value);
            }

            $row[] = (string)$value;
        }

        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}

/* ---------------------------------------------------------------
 * メール
 * --------------------------------------------------------------- */

function survey_mail_send(
    string $to,
    string $subject,
    string $body
): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $from = (string)(
        $_SERVER['SERVER_ADMIN']
        ?? 'webmaster@localhost'
    );

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $from,
    ];

    return @mail(
        $to,
        mb_encode_mimeheader(
            $subject,
            'UTF-8'
        ),
        $body,
        implode("\r\n", $headers)
    );
}

/* ---------------------------------------------------------------
 * API
 * --------------------------------------------------------------- */

$data = survey_read_data();

/*
 * 公開回答フォームは管理APIのCSRF対象外。
 * 管理操作のみCSRFを要求する。
 */
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

$publicActions = [
    'submit_response',
];

$adminActions = [
    'get_data',
    'save_survey',
    'delete_survey',
    'duplicate_survey',
    'toggle_status',
    'save_settings',
    'test_kintone',
    'fetch_kintone_fields',
    'send_mail',
    'mark_kintone',
];

if ($action !== '' &&
    in_array($action, $adminActions, true)) {
    if (!survey_check_token()) {
        survey_api([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。ページを再読み込みしてください。',
        ], 403);
    }
}

switch ($action) {

    case 'get_data':
        survey_api([
            'ok' => true,
            'data' => survey_public_data($data),
            'csrf_token' => survey_token(),
        ]);
        break;

    case 'save_survey':
        $raw = (string)($_POST['survey_json'] ?? '');
        $survey = json_decode($raw, true);

        if (!is_array($survey)) {
            survey_api([
                'ok' => false,
                'message' => 'アンケートデータが不正です。',
            ], 400);
        }

        $survey = survey_normalize_survey($survey);

        $now = survey_now();
        $found = false;

        foreach ($data['surveys'] as $index => $old) {
            if (($old['id'] ?? '') === $survey['id']) {
                $survey['created_at'] =
                    (string)($old['created_at'] ?? $now);
                $survey['updated_at'] = $now;
                $data['surveys'][$index] = $survey;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $survey['created_at'] = $now;
            $survey['updated_at'] = $now;
            $data['surveys'][] = $survey;
        }

        if (!survey_write_data($data)) {
            survey_api([
                'ok' => false,
                'message' =>
                    '保存に失敗しました。'
                    . ' survey_storage の書き込み権限を確認してください。',
            ], 500);
        }

        survey_api([
            'ok' => true,
            'message' => '保存しました。',
            'survey' => $survey,
        ]);
        break;

    case 'delete_survey':
        $id = (string)($_POST['survey_id'] ?? '');
        $found = false;

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $id) {
                $survey['deleted'] = true;
                $survey['status'] = 'draft';
                $survey['updated_at'] = survey_now();
                $found = true;
                break;
            }
        }

        unset($survey);

        if (!$found) {
            survey_api([
                'ok' => false,
                'message' => 'アンケートが見つかりません。',
            ], 404);
        }

        if (!survey_write_data($data)) {
            survey_api([
                'ok' => false,
                'message' => '削除状態の保存に失敗しました。',
            ], 500);
        }

        survey_api([
            'ok' => true,
            'message' => '削除しました。',
        ]);
        break;

    case 'duplicate_survey':
        $id = (string)($_POST['survey_id'] ?? '');
        $copy = null;

        foreach ($data['surveys'] as $survey) {
            if (($survey['id'] ?? '') === $id &&
                empty($survey['deleted'])) {
                $copy = $survey;
                break;
            }
        }

        if (!is_array($copy)) {
            survey_api([
                'ok' => false,
                'message' => 'アンケートが見つかりません。',
            ], 404);
        }

        $copy['id'] = survey_id();
        $copy['title'] =
            (string)$copy['title'] . '（コピー）';
        $copy['status'] = 'draft';
        $copy['created_at'] = survey_now();
        $copy['updated_at'] = survey_now();
        $copy['deleted'] = false;

        foreach ($copy['groups'] as &$group) {
            $group['id'] = survey_id();

            foreach ($group['questions'] as &$question) {
                $question['id'] = survey_id();
            }

            unset($question);
        }

        unset($group);

        $data['surveys'][] = $copy;

        if (!survey_write_data($data)) {
            survey_api([
                'ok' => false,
                'message' => '複製結果の保存に失敗しました。',
            ], 500);
        }

        survey_api([
            'ok' => true,
            'message' => '複製しました。',
            'survey' => $copy,
        ]);
        break;

    case 'toggle_status':
        $id = (string)($_POST['survey_id'] ?? '');
        $status = (string)($_POST['status'] ?? '');

        if (!in_array(
            $status,
            ['draft', 'active', 'ended'],
            true
        )) {
            survey_api([
                'ok' => false,
                'message' => 'ステータスが不正です。',
            ], 400);
        }

        $found = false;

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $id) {
                $survey['status'] = $status;
                $survey['updated_at'] = survey_now();
                $found = true;
                break;
            }
        }

        unset($survey);

        if (!$found) {
            survey_api([
                'ok' => false,
                'message' => 'アンケートが見つかりません。',
            ], 404);
        }

        if (!survey_write_data($data)) {
            survey_api([
                'ok' => false,
                'message' => 'ステータス変更の保存に失敗しました。',
            ], 500);
        }

        survey_api([
            'ok' => true,
            'message' => 'ステータスを変更しました。',
        ]);
        break;

    case 'save_settings':
        $raw = (string)($_POST['settings_json'] ?? '');
        $settings = json_decode($raw, true);

        if (!is_array($settings)) {
            survey_api([
                'ok' => false,
                'message' => '設定データが不正です。',
            ], 400);
        }

        $current = $data['settings'];

        foreach ([
            'subdomain',
            'login_name',
            'app_id',
            'proxy',
        ] as $key) {
            if (array_key_exists($key, $settings)) {
                $current[$key] =
                    trim((string)$settings[$key]);
            }
        }

        if (isset($settings['password']) &&
            (string)$settings['password'] !== '') {
            $current['password'] =
                (string)$settings['password'];
        }

        $current['ssl_verify'] =
            !empty($settings['ssl_verify']);

        foreach ([
            'field_company',
            'field_name',
            'field_email',
            'field_department',
            'field_phone',
        ] as $key) {
            $current[$key] =
                (string)($settings[$key] ?? '');
        }

        $current['field_address'] =
            is_array($settings['field_address'] ?? null)
            ? array_values(
                array_map(
                    static fn($v): string => (string)$v,
                    $settings['field_address']
                )
            )
            : [];

        $data['settings'] = $current;

        if (!survey_write_data($data)) {
            survey_api([
                'ok' => false,
                'message' =>
                    '設定保存に失敗しました。'
                    . ' survey_storage の権限を確認してください。',
            ], 500);
        }

        survey_api([
            'ok' => true,
            'message' => 'kintone連携設定を保存しました。',
        ]);
        break;

    case 'test_kintone':
    case 'fetch_kintone_fields':
        $settings = $data['settings'];

        $posted = json_decode(
            (string)($_POST['settings_json'] ?? ''),
            true
        );

        if (is_array($posted)) {
            $settings = array_replace(
                $settings,
                $posted
            );
        }

        $result = survey_kintone_request($settings);
        $fields = survey_kintone_fields($result);

        survey_api([
            'ok' => $fields['ok'],
            'status' => $result['status'],
            'url' => $result['url'],
            'proxy_used' => $result['proxy_used'],
            'message' => $fields['message'],
            'fields' => $fields['fields'],
            'error' => $result['error'],
        ]);
        break;

    case 'send_mail':
        $surveyId = (string)($_POST['survey_id'] ?? '');

        $ids = json_decode(
            (string)($_POST['recipient_ids'] ?? '[]'),
            true
        );

        if (!is_array($ids)) {
            $ids = [];
        }

        $subject = (string)(
            $_POST['mail_subject'] ?? ''
        );

        $body = (string)(
            $_POST['mail_body'] ?? ''
        );

        $template = (string)(
            $_POST['template_type'] ?? 'initial'
        );

        if (!in_array(
            $template,
            ['initial', 'reminder'],
            true
        )) {
            $template = 'initial';
        }

        $survey = null;

        foreach ($data['surveys'] as $s) {
            if (($s['id'] ?? '') === $surveyId) {
                $survey = $s;
                break;
            }
        }

        if (!is_array($survey)) {
            survey_api([
                'ok' => false,
                'message' => 'アンケートが見つかりません。',
            ], 404);
        }

        $scheme =
            (!empty($_SERVER['HTTPS']) &&
             $_SERVER['HTTPS'] !== 'off')
            ? 'https://'
            : 'http://';

        $host = (string)(
            $_SERVER['HTTP_HOST'] ?? ''
        );

        $path = (string)(
            $_SERVER['PHP_SELF'] ?? ''
        );

        $baseUrl =
            $scheme .
            $host .
            $path;

        $sent = 0;
        $failed = 0;

        foreach ($data['customers'] as &$customer) {
            $customerId = (string)(
                $customer['id'] ?? ''
            );

            if (!in_array(
                $customerId,
                $ids,
                true
            )) {
                continue;
            }

            if (($customer['source'] ?? '') === 'web') {
                continue;
            }

            $url =
                $baseUrl .
                '?survey=' .
                rawurlencode($surveyId) .
                '&customer=' .
                rawurlencode($customerId);

            $mailSubject = str_replace(
                '{顧客名}',
                (string)($customer['name'] ?? ''),
                $subject
            );

            $mailBody = str_replace(
                [
                    '{顧客名}',
                    '{アンケートURL}',
                ],
                [
                    (string)($customer['name'] ?? ''),
                    $url,
                ],
                $body
            );

            if (survey_mail_send(
                (string)($customer['email'] ?? ''),
                $mailSubject,
                $mailBody
            )) {
                $customer['sent_at'] = survey_now();
                $customer['send_count'] =
                    ((int)($customer['send_count'] ?? 0)) + 1;
                $customer['answer_status'] =
                    'unanswered';

                $sent++;
            } else {
                $failed++;
            }
        }

        unset($customer);

        $data['mail_logs'][] = [
            'id' => survey_id(),
            'survey_id' => $surveyId,
            'sent_at' => survey_now(),
            'type' => $template,
            'count' => $sent,
            'subject' => $subject,
            'body' => $body,
            'operator' =>
                (string)($_SESSION['user'] ?? 'admin'),
        ];

        if (!survey_write_data($data)) {
            survey_api([
                'ok' => false,
                'message' => '送信履歴の保存に失敗しました。',
            ], 500);
        }

        survey_api([
            'ok' => true,
            'sent' => $sent,
            'failed' => $failed,
            'message' =>
                $sent . '件送信しました。',
        ]);
        break;

    case 'mark_kintone':
        $id = (string)(
            $_POST['customer_id'] ?? ''
        );

        foreach ($data['customers'] as &$customer) {
            if (($customer['id'] ?? '') === $id) {
                $customer['kintone_status'] =
                    'registered';
                break;
            }
        }

        unset($customer);

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'message' =>
                'kintone登録済みに変更しました。',
        ]);
        break;

    case 'submit_response':
        $surveyId = (string)(
            $_POST['survey_id'] ?? ''
        );

        $customerId = (string)(
            $_POST['customer_id'] ?? ''
        );

        $answers = json_decode(
            (string)($_POST['answers'] ?? '{}'),
            true
        );

        if (!is_array($answers)) {
            $answers = [];
        }

        $survey = null;
        $customer = null;

        foreach ($data['surveys'] as $s) {
            if (($s['id'] ?? '') === $surveyId) {
                $survey = $s;
                break;
            }
        }

        foreach ($data['customers'] as $c) {
            if (($c['id'] ?? '') === $customerId) {
                $customer = $c;
                break;
            }
        }

        if (!is_array($survey) ||
            ($survey['status'] ?? '') !== 'active') {
            survey_api([
                'ok' => false,
                'message' =>
                    '公開中のアンケートではありません。',
            ], 404);
        }

        $response = [
            'id' => survey_id(),
            'survey_id' => $surveyId,
            'customer_id' => $customerId,
            'company' => (string)(
                $customer['company'] ?? ''
            ),
            'name' => (string)(
                $customer['name'] ?? ''
            ),
            'email' => (string)(
                $customer['email'] ?? ''
            ),
            'answered_at' => survey_now(),
            'answers' => $answers,
        ];

        $data['responses'][] = $response;

        foreach ($data['customers'] as &$c) {
            if (($c['id'] ?? '') === $customerId) {
                $c['answer_status'] = 'answered';
                break;
            }
        }

        unset($c);

        if (!survey_write_data($data)) {
            survey_api([
                'ok' => false,
                'message' =>
                    '回答データの保存に失敗しました。',
            ], 500);
        }

        survey_api([
            'ok' => true,
            'message' =>
                '回答を受け付けました。',
        ]);
        break;

    case 'csv':
        survey_csv_download(
            $data,
            (string)($_GET['survey_id'] ?? '')
        );
        break;

    default:
        break;
}

/* ---------------------------------------------------------------
 * CSRF
 * --------------------------------------------------------------- */

$csrf = survey_token();

/* ---------------------------------------------------------------
 * 公開回答フォーム
 * --------------------------------------------------------------- */

$publicSurveyId = (string)(
    $_GET['survey'] ?? ''
);

$publicCustomerId = (string)(
    $_GET['customer'] ?? ''
);

if ($publicSurveyId !== '') {

    $survey = null;
    $customer = null;

    foreach ($data['surveys'] as $s) {
        if (($s['id'] ?? '') === $publicSurveyId &&
            empty($s['deleted'])) {
            $survey = $s;
            break;
        }
    }

    foreach ($data['customers'] as $c) {
        if (($c['id'] ?? '') === $publicCustomerId) {
            $customer = $c;
            break;
        }
    }

    if (is_array($survey) &&
        ($survey['status'] ?? '') === 'active') {
        ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= survey_h($survey['title']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-800">
<div id="public_app" class="max-w-3xl mx-auto p-5 md:p-8">
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 md:p-8">
<h1 class="text-2xl font-bold">
<?= survey_h($survey['title']) ?>
</h1>

<?php if ($customer): ?>
<p class="mt-2 mb-8 text-sm text-slate-500">
<?= survey_h($customer['name'] ?? '') ?> 様
</p>
<?php endif; ?>

<form id="public_form" class="space-y-10">

<?php
$qGlobal = 0;

foreach (($survey['groups'] ?? []) as $group):
?>
<section class="border-t border-slate-200 pt-7">
<h2 class="text-xl font-bold mb-6">
<?= survey_h($group['name'] ?? '') ?>
</h2>

<?php
foreach (($group['questions'] ?? []) as $q):
    $qGlobal++;
    $qid = (string)($q['id'] ?? '');
    $type = (string)($q['type'] ?? 'single');
    $required = !empty($q['required']);
?>
<div class="mb-8">

<label class="block font-semibold mb-3">
<span class="text-slate-400 mr-2">
<?= survey_h(
    $q['number'] ??
    (
        ($survey['numbering_mode'] ?? 'global')
        === 'group'
        ? 'Q' . $qGlobal
        : 'Q' . $qGlobal
    )
) ?>
</span>

<?= survey_h($q['text'] ?? '') ?>

<?php if ($required): ?>
<span class="text-red-500 text-xs ml-2">
必須
</span>
<?php endif; ?>
</label>

<?php if ($type === 'text'): ?>

<textarea
name="answers[<?= survey_h($qid) ?>]"
rows="5"
class="w-full border border-slate-300 rounded-xl px-4 py-3"
<?= $required ? 'required' : '' ?>
></textarea>

<?php else: ?>

<div class="space-y-2">

<?php foreach (($q['options'] ?? []) as $option): ?>

<label class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-50">
<input
class="w-5 h-5"
type="<?= $type === 'multiple' ? 'checkbox' : 'radio' ?>"
name="answers[<?= survey_h($qid) ?>]<?= $type === 'multiple' ? '[]' : '' ?>"
value="<?= survey_h($option) ?>"
<?= $required && $type === 'single' ? 'required' : '' ?>
>
<span><?= survey_h($option) ?></span>
</label>

<?php endforeach; ?>

<?php if (!empty($q['other_enabled'])): ?>

<label class="flex items-center gap-3 p-3 rounded-xl">
<input
class="w-5 h-5"
type="<?= $type === 'multiple' ? 'checkbox' : 'radio' ?>"
name="answers[<?= survey_h($qid) ?>]<?= $type === 'multiple' ? '[]' : '' ?>"
value="その他"
<?= $required && $type === 'single' ? 'required' : '' ?>
>
<span>その他</span>

<input
type="text"
name="other[<?= survey_h($qid) ?>]"
class="flex-1 border border-slate-300 rounded-lg px-3 py-2"
placeholder="内容を入力"
>
</label>

<?php endif; ?>

</div>
<?php endif; ?>

</div>
<?php endforeach; ?>
</section>
<?php endforeach; ?>

<input
type="hidden"
name="survey_id"
value="<?= survey_h($publicSurveyId) ?>"
>

<input
type="hidden"
name="customer_id"
value="<?= survey_h($publicCustomerId) ?>"
>

<input
type="hidden"
name="action"
value="submit_response"
>

<button
type="submit"
class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl px-5 py-3"
>
回答を送信する
</button>

</form>
</div>
</div>

<script>
(function(){
    const form=document.getElementById('public_form');
    if(!form)return;

    form.addEventListener('submit',async function(event){
        event.preventDefault();

        if(!confirm('回答を送信します。よろしいですか？')){
            return;
        }

        const fd=new FormData(form);
        const answers={};

        for(const [key,value] of fd.entries()){
            if(!key.startsWith('answers['))continue;

            const match=key.match(
                /^answers\[([^\]]+)\](\[\])?$/
            );

            if(!match)continue;

            const id=match[1];

            if(match[2]){
                if(!Array.isArray(answers[id])){
                    answers[id]=[];
                }

                answers[id].push(value);
            }else{
                answers[id]=value;
            }
        }

        fd.set('answers',JSON.stringify(answers));

        try{
            const response=await fetch(
                location.href,
                {
                    method:'POST',
                    body:fd
                }
            );

            const text=await response.text();

            let json;

            try{
                json=JSON.parse(text);
            }catch(error){
                throw new Error(
                    text ||
                    'サーバーから不正な応答が返りました。'
                );
            }

            if(!json.ok){
                throw new Error(
                    json.message ||
                    '回答送信に失敗しました。'
                );
            }

            document.getElementById(
                'public_app'
            ).innerHTML=
            '<div class="bg-white rounded-2xl border p-10 text-center">'
            +'<div class="text-green-600 text-5xl mb-5">✓</div>'
            +'<h1 class="text-2xl font-bold mb-3">'
            +'回答を受け付けました'
            +'</h1>'
            +'<p class="text-slate-500">'
            +'ご回答ありがとうございました。'
            +'</p>'
            +'</div>';

        }catch(error){
            alert(
                error instanceof Error
                    ? error.message
                    : String(error)
            );
        }
    });
})();
</script>

</body>
</html>
<?php
        exit;
    }
}

/* ---------------------------------------------------------------
 * 管理画面
 * --------------------------------------------------------------- */

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

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

<meta
name="csrf-token"
content="<?= survey_h($csrf) ?>"
>

</head>

<body class="bg-slate-50 text-slate-800 min-h-screen">

<!--
 JSが停止しても完全な白紙にならないための初期表示。
-->
<div id="app">

<div class="min-h-screen">

<header class="bg-white border-b border-slate-200">
<div class="max-w-7xl mx-auto px-5 py-4">
<h1 class="text-xl font-bold">
アンケート管理システム
</h1>
<p class="text-sm text-slate-500 mt-1">
読み込み中です…
</p>
</div>
</header>

<main class="max-w-7xl mx-auto px-5 py-8">
<div class="bg-white border rounded-2xl p-8 text-center">
<div class="text-slate-400 mb-3">
画面を読み込んでいます
</div>
<div class="text-sm text-slate-500">
しばらくお待ちください。
</div>
</div>
</main>

</div>

</div>

<input
type="hidden"
id="csrf_token"
value="<?= survey_h($csrf) ?>"
>

<script>
'use strict';

window.App = {

    State: {
        data: null,
        page: 'list',
        survey: null,
        dirty: false,
        fields: [],
        responseModal: null,
        selectedQuestions: {},
        selectedCustomerIds: [],
        filter: {
            keyword: '',
            status: '',
            sort: 'updated_desc'
        },
        customerKeyword: '',
        responseKeyword: ''
    },

    Util: {

        h(value){
            return String(value ?? '')
                .replace(/&/g,'&amp;')
                .replace(/</g,'&lt;')
                .replace(/>/g,'&gt;')
                .replace(/"/g,'&quot;')
                .replace(/'/g,'&#039;');
        },

        id(){
            if(
                window.crypto &&
                typeof window.crypto.randomUUID === 'function'
            ){
                return window.crypto
                    .randomUUID()
                    .replaceAll('-','');
            }

            return Date.now().toString(36)
                + Math.random()
                    .toString(36)
                    .slice(2);
        },

        json(value){
            return JSON.stringify(value)
                .replace(/</g,'\\u003c');
        },

        clone(value){
            return JSON.parse(
                JSON.stringify(value)
            );
        },

        status(value){
            if(value === 'active')return '公開中';
            if(value === 'ended')return '終了';
            return '下書き';
        },

        type(value){
            if(value === 'single')return '単一選択';
            if(value === 'multiple')return '複数選択';
            return '自由記述';
        }
    },

    API: {

        async request(action,extra={}){

            const fd=new FormData();

            fd.set('action',action);

            const token=document.getElementById(
                'csrf_token'
            )?.value
            || document.querySelector(
                'meta[name="csrf-token"]'
            )?.content
            || '';

            fd.set('csrf_token',token);

            Object.entries(extra).forEach(
                ([key,value])=>{
                    fd.set(
                        key,
                        typeof value === 'string'
                            ? value
                            : JSON.stringify(value)
                    );
                }
            );

            const response=await fetch(
                location.href,
                {
                    method:'POST',
                    body:fd,
                    credentials:'same-origin'
                }
            );

            const text=await response.text();

            let json;

            try{
                json=JSON.parse(text);
            }catch(error){

                const message=text
                    ? text.slice(0,3000)
                    : 'サーバーから空の応答が返りました。';

                throw new Error(
                    'サーバー応答をJSONとして解析できませんでした。\n\n'
                    +message
                );
            }

            if(!response.ok && !json.ok){
                throw new Error(
                    json.message ||
                    'HTTPエラー: '+response.status
                );
            }

            return json;
        },

        async load(){

            const json=await this.request(
                'get_data'
            );

            if(!json.ok){
                throw new Error(
                    json.message ||
                    'データ取得に失敗しました。'
                );
            }

            App.State.data=json.data;

            const token=document.getElementById(
                'csrf_token'
            );

            if(token && json.csrf_token){
                token.value=json.csrf_token;
            }
        },

        async saveSurvey(survey){

            const json=await this.request(
                'save_survey',
                {
                    survey_json:
                        App.Util.json(survey)
                }
            );

            if(!json.ok){
                throw new Error(json.message);
            }

            await this.load();

            return json;
        },

        async saveSettings(settings){

            const json=await this.request(
                'save_settings',
                {
                    settings_json:
                        App.Util.json(settings)
                }
            );

            if(!json.ok){
                throw new Error(json.message);
            }

            await this.load();

            return json;
        }
    },

    Render: {

        root(){

            const page=App.State.page;

            const titles={
                list:'アンケート一覧',
                editor:
                    App.State.survey?.id
                    ? 'アンケート編集'
                    : '新規アンケート作成',
                aggregate:'回答集計・分析',
                mail:'顧客選択・メール送信',
                settings:'kintone連携設定'
            };

            const app=document.getElementById('app');

            if(!app){
                throw new Error(
                    'HTML DOM ID "app" が見つかりません。'
                );
            }

            app.innerHTML=`
            <div class="min-h-screen">

                <header
                class="sticky top-0 z-30 bg-white border-b border-slate-200 shadow-sm"
                >

                    <div
                    class="max-w-7xl mx-auto px-5 py-4 flex items-center justify-between gap-4"
                    >

                        <button
                        class="font-bold text-lg"
                        onclick="App.actions.home()"
                        >
                        アンケート管理
                        </button>

                        <nav class="flex gap-2 flex-wrap">

                            <button
                            class="px-3 py-2 rounded-lg hover:bg-slate-100 text-sm"
                            onclick="App.actions.home()"
                            >
                            アンケート一覧
                            </button>

                            <button
                            class="px-3 py-2 rounded-lg hover:bg-slate-100 text-sm"
                            onclick="App.actions.settings()"
                            >
                            キントーン連携設定
                            </button>

                            <button
                            class="px-3 py-2 rounded-lg hover:bg-slate-100 text-sm"
                            onclick="App.actions.logout()"
                            >
                            ログアウト
                            </button>

                        </nav>

                    </div>

                </header>

                <main
                class="max-w-7xl mx-auto px-5 py-7"
                >

                    <div class="mb-6">

                        <div class="text-sm text-slate-500">
                        ホーム
                        </div>

                        <h1 class="text-2xl font-bold mt-1">
                        ${App.Util.h(titles[page] || '')}
                        </h1>

                    </div>

                    <div id="view"></div>

                </main>

            </div>
            `;
        },

        list(){

            const state=App.State;

            let surveys=(
                state.data?.surveys || []
            ).filter(
                survey=>!survey.deleted
            );

            const keyword=
                String(state.filter.keyword || '')
                .trim()
                .toLowerCase();

            if(keyword){
                surveys=surveys.filter(
                    survey=>
                        String(
                            survey.title || ''
                        )
                        .toLowerCase()
                        .includes(keyword)
                );
            }

            if(state.filter.status){
                surveys=surveys.filter(
                    survey=>
                        survey.status ===
                        state.filter.status
                );
            }

            surveys.sort((a,b)=>{

                if(state.filter.sort==='updated_asc'){
                    return String(a.updated_at||'')
                        .localeCompare(
                            String(b.updated_at||'')
                        );
                }

                if(state.filter.sort==='answers_desc'){
                    return App.answersCount(b)
                        -App.answersCount(a);
                }

                if(state.filter.sort==='answers_asc'){
                    return App.answersCount(a)
                        -App.answersCount(b);
                }

                if(state.filter.sort==='start_desc'){
                    return String(b.start_at||'')
                        .localeCompare(
                            String(a.start_at||'')
                        );
                }

                if(state.filter.sort==='start_asc'){
                    return String(a.start_at||'')
                        .localeCompare(
                            String(b.start_at||'')
                        );
                }

                return String(b.updated_at||'')
                    .localeCompare(
                        String(a.updated_at||'')
                    );
            });

            document.getElementById(
                'view'
            ).innerHTML=`

            <div
            class="flex justify-between items-center gap-4 mb-5 flex-wrap"
            >

                <div class="flex gap-2 flex-wrap">

                    <input
                    value="${App.Util.h(state.filter.keyword)}"
                    placeholder="タイトル検索"
                    onkeydown="
                    if(event.key==='Enter')
                    App.actions.keyword(this.value)
                    "
                    class="w-64 border rounded-xl px-4 py-2 bg-white"
                    >

                    <select
                    onchange="App.actions.statusFilter(this.value)"
                    class="border rounded-xl px-3 py-2 bg-white"
                    >

                        <option value="">すべて</option>

                        <option
                        value="active"
                        ${state.filter.status==='active'?'selected':''}
                        >
                        公開中
                        </option>

                        <option
                        value="draft"
                        ${state.filter.status==='draft'?'selected':''}
                        >
                        下書き
                        </option>

                        <option
                        value="ended"
                        ${state.filter.status==='ended'?'selected':''}
                        >
                        終了
                        </option>

                    </select>

                    <select
                    onchange="App.actions.sort(this.value)"
                    class="border rounded-xl px-3 py-2 bg-white"
                    >

                        <option value="updated_desc">
                        更新日 新しい順
                        </option>

                        <option value="updated_asc">
                        更新日 古い順
                        </option>

                        <option value="answers_desc">
                        回答数 多い順
                        </option>

                        <option value="answers_asc">
                        回答数 少ない順
                        </option>

                        <option value="start_desc">
                        開始日 新しい順
                        </option>

                        <option value="start_asc">
                        開始日 古い順
                        </option>

                    </select>

                </div>

                <button
                onclick="App.actions.newSurvey()"
                class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-3 rounded-xl font-semibold"
                >
                ＋ 新規アンケート作成
                </button>

            </div>

            <div
            class="bg-white rounded-2xl border border-slate-200 overflow-x-auto"
            >

                <table class="w-full text-sm">

                    <thead
                    class="bg-slate-50 border-b"
                    >
                        <tr>
                            <th class="text-left p-4">
                            作成日 / 更新日
                            </th>
                            <th class="text-left p-4">
                            タイトル
                            </th>
                            <th class="text-left p-4">
                            アンケート期間
                            </th>
                            <th class="text-left p-4">
                            ステータス
                            </th>
                            <th class="text-right p-4">
                            回答数
                            </th>
                            <th class="text-left p-4">
                            操作
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                    ${
                        surveys.map(
                            survey=>
                                App.Render.surveyRow(
                                    survey
                                )
                        ).join('')
                    }
                    </tbody>

                </table>

                ${
                    surveys.length
                    ? ''
                    : `
                    <div class="p-12 text-center text-slate-500">
                    アンケートがありません。
                    </div>
                    `
                }

            </div>
            `;
        },

        surveyRow(survey){

            const badge=
                survey.status==='active'
                ? 'bg-green-100 text-green-700'
                : survey.status==='ended'
                ? 'bg-slate-200 text-slate-600'
                : 'bg-amber-100 text-amber-700';

            let buttons=`
                <button
                class="px-3 py-1.5 rounded-lg bg-slate-100"
                onclick="App.actions.edit('${App.Util.h(survey.id)}')"
                >
                確認・編集
                </button>
            `;

            if(survey.status!=='draft'){
                buttons+=`
                    <button
                    class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700"
                    onclick="App.actions.aggregate('${App.Util.h(survey.id)}')"
                    >
                    集計
                    </button>

                    <button
                    class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700"
                    onclick="App.actions.mail('${App.Util.h(survey.id)}')"
                    >
                    送信
                    </button>
                `;
            }

            if(survey.status==='active'){
                buttons+=`
                    <button
                    class="px-3 py-1.5 rounded-lg bg-red-50 text-red-700"
                    onclick="App.actions.toggle('${App.Util.h(survey.id)}','ended')"
                    >
                    停止
                    </button>
                `;
            }

            if(survey.status==='draft'){
                buttons+=`
                    <button
                    class="px-3 py-1.5 rounded-lg bg-red-50 text-red-700"
                    onclick="App.actions.delete('${App.Util.h(survey.id)}')"
                    >
                    削除
                    </button>
                `;
            }

            buttons+=`
                <button
                class="px-3 py-1.5 rounded-lg bg-slate-100"
                onclick="App.actions.duplicate('${App.Util.h(survey.id)}')"
                >
                複製
                </button>
            `;

            return `
            <tr class="border-b last:border-0">

                <td class="p-4 whitespace-nowrap">
                    ${App.Util.h(survey.created_at || '')}
                    <br>
                    <span class="text-slate-500">
                    更新:
                    ${App.Util.h(survey.updated_at || '')}
                    </span>
                </td>

                <td class="p-4 font-bold">
                    ${App.Util.h(survey.title)}
                </td>

                <td class="p-4 whitespace-nowrap">
                    ${App.Util.h(
                        survey.start_at || '未設定'
                    )}
                    ～
                    ${App.Util.h(
                        survey.end_at || '未設定'
                    )}
                </td>

                <td class="p-4">
                    <span
                    class="px-2.5 py-1 rounded-full text-xs ${badge}"
                    >
                    ${App.Util.status(survey.status)}
                    </span>
                </td>

                <td class="p-4 text-right">
                    ${App.answersCount(survey)} 件
                </td>

                <td class="p-4">
                    <div class="flex flex-wrap gap-2">
                    ${buttons}
                    </div>
                </td>

            </tr>
            `;
        },

        editor(){

            const survey=App.State.survey;

            if(!survey){
                App.actions.home();
                return;
            }

            App.renumberData();

            document.getElementById(
                'view'
            ).innerHTML=`

            <div
            class="flex justify-between gap-3 mb-5 flex-wrap"
            >

                <div class="flex gap-2">

                    <button
                    onclick="App.actions.preview()"
                    class="px-4 py-2 bg-white border rounded-xl"
                    >
                    プレビュー
                    </button>

                    <button
                    onclick="App.actions.saveEditor()"
                    class="px-4 py-2 bg-blue-600 text-white rounded-xl"
                    >
                    保存して一覧へ戻る
                    </button>

                    <button
                    onclick="App.actions.cancelEditor()"
                    class="px-4 py-2 bg-slate-200 rounded-xl"
                    >
                    キャンセル
                    </button>

                </div>

                <button
                onclick="App.actions.addGroup()"
                class="px-4 py-2 bg-slate-900 text-white rounded-xl"
                >
                ＋ グループ追加
                </button>

            </div>

            <div
            class="bg-white rounded-2xl border p-6 mb-5"
            >

                <div class="grid md:grid-cols-4 gap-4">

                    <label class="md:col-span-2">

                        <span class="text-sm font-semibold">
                        タイトル
                        </span>

                        <input
                        id="survey_title"
                        value="${App.Util.h(survey.title)}"
                        onchange="App.actions.field('title',this.value)"
                        class="mt-1 w-full border rounded-xl px-4 py-2"
                        >

                    </label>

                    <label>

                        <span class="text-sm font-semibold">
                        開始日時
                        </span>

                        <input
                        id="survey_start_at"
                        type="datetime-local"
                        value="${App.Util.h(survey.start_at)}"
                        onchange="App.actions.field('start_at',this.value)"
                        class="mt-1 w-full border rounded-xl px-4 py-2"
                        >

                    </label>

                    <label>

                        <span class="text-sm font-semibold">
                        終了日時
                        </span>

                        <input
                        id="survey_end_at"
                        type="datetime-local"
                        value="${App.Util.h(survey.end_at)}"
                        onchange="App.actions.field('end_at',this.value)"
                        class="mt-1 w-full border rounded-xl px-4 py-2"
                        >

                    </label>

                </div>

                <div class="mt-5">

                    <label>

                        <span class="text-sm font-semibold">
                        質問番号形式
                        </span>

                        <select
                        id="survey_numbering_mode"
                        onchange="App.actions.field('numbering_mode',this.value);App.Render.editor()"
                        class="ml-3 border rounded-xl px-3 py-2"
                        >

                            <option
                            value="global"
                            ${survey.numbering_mode==='global'?'selected':''}
                            >
                            Q1, Q2, Q3…
                            </option>

                            <option
                            value="group"
                            ${survey.numbering_mode==='group'?'selected':''}
                            >
                            Q1-1, Q1-2…
                            </option>

                        </select>

                    </label>

                </div>

            </div>

            <div
            id="question_editor"
            class="space-y-5"
            >

            ${
                survey.groups.length
                ? survey.groups.map(
                    (group,groupIndex)=>
                        App.Render.group(
                            group,
                            groupIndex
                        )
                ).join('')
                : `
                <div class="bg-white border rounded-2xl p-10 text-center">
                    <p class="text-slate-500 mb-4">
                    グループがありません。
                    </p>
                    <button
                    onclick="App.actions.addGroup()"
                    class="bg-blue-600 text-white rounded-xl px-5 py-3"
                    >
                    最初のグループを追加
                    </button>
                </div>
                `
            }

            </div>
            `;

            App.actions.sortable();
        },

        group(group,index){

            return `
            <section
            class="group-item bg-white border rounded-2xl p-5"
            data-group-id="${App.Util.h(group.id)}"
            >

                <div
                class="flex items-center gap-3 mb-5"
                >

                    <span
                    class="cursor-move text-xl text-slate-400"
                    title="ドラッグして並び替え"
                    >
                    ⠿
                    </span>

                    <input
                    value="${App.Util.h(group.name)}"
                    onchange="
                    App.actions.groupField(
                        '${App.Util.h(group.id)}',
                        'name',
                        this.value
                    )"
                    class="flex-1 border rounded-xl px-4 py-2 font-bold"
                    >

                    <button
                    onclick="App.actions.deleteGroup('${App.Util.h(group.id)}')"
                    class="px-3 py-2 rounded-xl bg-red-50 text-red-600"
                    >
                    グループ削除
                    </button>

                </div>

                <div
                class="question-list space-y-4"
                data-group-id="${App.Util.h(group.id)}"
                >

                ${
                    (group.questions || []).map(
                        (question,questionIndex)=>
                            App.Render.question(
                                question,
                                group.id,
                                questionIndex
                            )
                    ).join('')
                }

                </div>

                <button
                onclick="App.actions.addQuestion('${App.Util.h(group.id)}')"
                class="mt-5 px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200"
                >
                ＋ 質問追加
                </button>

            </section>
            `;
        },

        question(question,groupId,index){

            const type=question.type || 'single';

            return `
            <div
            class="question-item border border-slate-200 rounded-2xl p-5 bg-slate-50"
            data-question-id="${App.Util.h(question.id)}"
            >

                <div class="flex gap-3">

                    <span
                    class="cursor-move text-xl text-slate-400"
                    >
                    ⠿
                    </span>

                    <div class="flex-1">

                        <div class="flex justify-between gap-3 mb-4">

                            <div class="font-bold text-blue-600">
                            <span
                            data-qnumber="${App.Util.h(question.id)}"
                            >
                            ${App.Util.h(question.number || 'Q')}
                            </span>
                            </div>

                            <button
                            onclick="App.actions.deleteQuestion('${App.Util.h(question.id)}')"
                            class="text-red-600 text-sm"
                            >
                            削除
                            </button>

                        </div>

                        <input
                        value="${App.Util.h(question.text)}"
                        placeholder="質問文を入力してください"
                        onchange="
                        App.actions.questionField(
                            '${App.Util.h(question.id)}',
                            'text',
                            this.value
                        )"
                        class="w-full border rounded-xl px-4 py-2 bg-white"
                        >

                        <div class="grid md:grid-cols-3 gap-3 mt-4">

                            <label>

                                <span class="text-xs text-slate-500">
                                回答形式
                                </span>

                                <select
                                onchange="
                                App.actions.questionField(
                                    '${App.Util.h(question.id)}',
                                    'type',
                                    this.value
                                )"
                                class="w-full border rounded-xl px-3 py-2 bg-white"
                                >

                                    <option
                                    value="single"
                                    ${type==='single'?'selected':''}
                                    >
                                    単一選択
                                    </option>

                                    <option
                                    value="multiple"
                                    ${type==='multiple'?'selected':''}
                                    >
                                    複数選択
                                    </option>

                                    <option
                                    value="text"
                                    ${type==='text'?'selected':''}
                                    >
                                    自由記述
                                    </option>

                                </select>

                            </label>

                            <label class="flex items-end gap-2 pb-2">

                                <input
                                type="checkbox"
                                ${question.required?'checked':''}
                                onchange="
                                App.actions.questionField(
                                    '${App.Util.h(question.id)}',
                                    'required',
                                    this.checked
                                )"
                                >

                                <span>
                                必須回答
                                </span>

                            </label>

                            ${
                                type!=='text'
                                ? `
                                <label class="flex items-end gap-2 pb-2">

                                    <input
                                    type="checkbox"
                                    ${question.other_enabled?'checked':''}
                                    onchange="
                                    App.actions.questionField(
                                        '${App.Util.h(question.id)}',
                                        'other_enabled',
                                        this.checked
                                    )"
                                    >

                                    <span>
                                    その他を許可
                                    </span>

                                </label>
                                `
                                : ''
                            }

                        </div>

                        ${
                            type!=='text'
                            ? `
                            <div class="mt-5">

                                <div
                                class="flex justify-between items-center mb-2"
                                >
                                    <span class="text-sm font-semibold">
                                    選択肢
                                    </span>

                                    <button
                                    onclick="
                                    App.actions.addOption(
                                        '${App.Util.h(question.id)}'
                                    )"
                                    class="text-blue-600 text-sm"
                                    >
                                    ＋選択肢追加
                                    </button>
                                </div>

                                <div class="space-y-2">

                                ${
                                    (question.options || [])
                                    .map(
                                        (option,optionIndex)=>`
                                        <div class="flex gap-2">

                                            <input
                                            value="${App.Util.h(option)}"
                                            onchange="
                                            App.actions.optionField(
                                                '${App.Util.h(question.id)}',
                                                ${optionIndex},
                                                this.value
                                            )"
                                            class="flex-1 border rounded-xl px-3 py-2 bg-white"
                                            >

                                            <button
                                            onclick="
                                            App.actions.removeOption(
                                                '${App.Util.h(question.id)}',
                                                ${optionIndex}
                                            )"
                                            class="px-3 rounded-xl bg-white border text-red-600"
                                            >
                                            ×
                                            </button>

                                        </div>
                                        `
                                    ).join('')
                                }

                                </div>

                            </div>
                            `
                            : ''
                        }

                    </div>

                </div>

            </div>
            `;
        },

        preview(){

            const survey=App.State.survey;

            const modal=document.createElement('div');

            modal.id='preview_modal';

            modal.className=
                'fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-5';

            modal.innerHTML=`

            <div
            class="bg-white rounded-2xl w-full max-w-3xl max-h-[90vh] overflow-auto"
            >

                <div
                class="sticky top-0 bg-white border-b p-4 flex justify-between items-center"
                >

                    <strong>
                    プレビュー
                    </strong>

                    <button
                    onclick="App.actions.closePreview()"
                    class="text-2xl"
                    >
                    ×
                    </button>

                </div>

                <div id="preview_content" class="p-6">

                    <h1 class="text-2xl font-bold mb-8">
                    ${App.Util.h(survey.title)}
                    </h1>

                    ${
                        survey.groups.map(
                            group=>`

                            <section class="mb-8">

                                <h2
                                class="text-xl font-bold mb-5"
                                >
                                ${App.Util.h(group.name)}
                                </h2>

                                ${
                                    (group.questions || [])
                                    .map(
                                        question=>`

                                        <div class="mb-7">

                                            <div
                                            class="font-semibold mb-3"
                                            >

                                                ${App.Util.h(
                                                    question.number || 'Q'
                                                )}

                                                ${App.Util.h(
                                                    question.text
                                                )}

                                                ${
                                                    question.required
                                                    ? '<span class="text-red-500 text-xs ml-2">必須</span>'
                                                    : ''
                                                }

                                            </div>

                                            ${
                                                question.type==='text'
                                                ? `
                                                <textarea
                                                class="w-full border rounded-xl p-3"
                                                rows="4"
                                                disabled
                                                ></textarea>
                                                `
                                                :
                                                (question.options || [])
                                                .map(
                                                    option=>`
                                                    <label class="block p-2">
                                                    <input
                                                    type="${question.type==='multiple'?'checkbox':'radio'}"
                                                    disabled
                                                    >
                                                    ${App.Util.h(option)}
                                                    </label>
                                                    `
                                                ).join('')
                                            }

                                        </div>
                                        `
                                    ).join('')
                                }

                            </section>
                            `
                        ).join('')
                    }

                    <button
                    onclick="alert('プレビューでは実際の送信は行いません。')"
                    class="w-full bg-blue-600 text-white rounded-xl py-3"
                    >
                    回答を送信する
                    </button>

                </div>

            </div>
            `;

            document.body.appendChild(modal);
        },

        aggregate(){

            const survey=App.State.survey;

            const responses=(
                App.State.data?.responses || []
            ).filter(
                response=>
                    response.survey_id === survey.id
            );

            const customers=(
                App.State.data?.customers || []
            );

            const targets=customers.filter(
                customer=>
                    customer.source !== 'web'
            );

            const answeredByTarget=new Set(
                responses
                .filter(
                    response=>
                        response.customer_id
                )
                .map(
                    response=>
                        response.customer_id
                )
            );

            const targetAnswers=
                [...answeredByTarget]
                .filter(
                    id=>
                        targets.some(
                            customer=>
                                customer.id === id
                        )
                ).length;

            const unanswered=Math.max(
                targets.length-targetAnswers,
                0
            );

            const rate=targets.length
                ? (
                    targetAnswers /
                    targets.length *
                    100
                ).toFixed(1)
                : '0.0';

            const questions=[];

            survey.groups.forEach(
                group=>{
                    (group.questions || [])
                    .forEach(
                        question=>{
                            questions.push(question);
                        }
                    );
                }
            );

            document.getElementById(
                'view'
            ).innerHTML=`

            <div class="grid md:grid-cols-5 gap-4 mb-6">

                ${[
                    ['送信対象者数',targets.length+' 人'],
                    ['回答数',responses.length+' 件'],
                    [
                        '未登録顧客からの回答数',
                        responses.filter(
                            r=>
                                !targets.some(
                                    c=>
                                        c.id===r.customer_id
                                )
                        ).length+' 件'
                    ],
                    ['未回答数',unanswered+' 人'],
                    ['回答率',rate+' %']
                ].map(
                    card=>`
                    <div
                    class="bg-white border rounded-2xl p-5"
                    >
                        <div class="text-sm text-slate-500">
                        ${App.Util.h(card[0])}
                        </div>

                        <div class="text-2xl font-bold mt-2">
                        ${App.Util.h(card[1])}
                        </div>
                    </div>
                    `
                ).join('')}

            </div>

            <div
            class="bg-white border rounded-2xl p-5 mb-6"
            >

                <div class="flex justify-between items-center mb-4">

                    <h2 class="font-bold">
                    設問表示
                    </h2>

                    <div class="flex gap-2">

                        <button
                        onclick="App.actions.selectQuestions(true)"
                        class="text-blue-600 text-sm"
                        >
                        全選択
                        </button>

                        <button
                        onclick="App.actions.selectQuestions(false)"
                        class="text-blue-600 text-sm"
                        >
                        全解除
                        </button>

                    </div>

                </div>

                <div class="grid md:grid-cols-2 gap-2">

                ${
                    questions.map(
                        question=>`

                        <label
                        class="flex gap-3 items-center p-2"
                        >

                            <input
                            type="checkbox"
                            ${App.State.selectedQuestions[question.id] ? 'checked' : ''}
                            onchange="
                            App.actions.questionFilter(
                                '${App.Util.h(question.id)}',
                                this.checked
                            )"
                            >

                            <span>
                            ${App.Util.h(question.number || '')}
                            ${App.Util.h(question.text)}
                            </span>

                            <span
                            class="text-xs text-slate-400"
                            >
                            ${App.Util.type(question.type)}
                            </span>

                        </label>
                        `
                    ).join('')
                }

                </div>

            </div>

            ${
                responses.length
                ? ''
                : `
                <div
                class="bg-white border rounded-2xl p-12 text-center text-slate-500 mb-6"
                >
                現在、回答データはありません。
                </div>
                `
            }

            <div class="space-y-5">

            ${
                questions
                .filter(
                    q=>
                        App.State.selectedQuestions[q.id]
                )
                .map(
                    question=>
                        App.Render.questionSummary(
                            question,
                            responses
                        )
                ).join('')
            }

            </div>

            <div
            class="bg-white border rounded-2xl p-5 mt-6"
            >

                <div
                class="flex justify-between items-center gap-3 mb-4 flex-wrap"
                >

                    <h2 class="font-bold">
                    個別回答一覧
                    </h2>

                    <div class="flex gap-2">

                        <input
                        id="response_filter"
                        value="${App.Util.h(App.State.responseKeyword || '')}"
                        placeholder="会社名・氏名で検索"
                        oninput="App.actions.responseFilter(this.value)"
                        class="border rounded-xl px-3 py-2"
                        >

                        <button
                        onclick="App.actions.csv('${App.Util.h(survey.id)}')"
                        class="px-4 py-2 bg-slate-900 text-white rounded-xl"
                        >
                        CSV
                        </button>

                    </div>

                </div>

                <div
                id="response_table"
                class="overflow-x-auto"
                >
                ${App.Render.responseTable(responses)}
                </div>

            </div>
            `;
        },

        questionSummary(question,responses){

            const values=[];

            responses.forEach(
                response=>{
                    const value=
                        response.answers?.[
                            question.id
                        ];

                    if(Array.isArray(value)){
                        value.forEach(
                            item=>values.push(String(item))
                        );
                    }else if(
                        value !== undefined &&
                        value !== null &&
                        String(value) !== ''
                    ){
                        values.push(String(value));
                    }
                }
            );

            if(question.type==='text'){
                return `
                <div
                class="bg-white border rounded-2xl p-5"
                >

                    <div class="font-bold mb-4">
                    ${App.Util.h(question.number)}
                    ${App.Util.h(question.text)}
                    </div>

                    ${
                        values.length
                        ? values.map(
                            value=>`
                            <div
                            class="border-l-4 border-blue-200 pl-4 py-2 mb-3"
                            >
                            ${App.Util.h(value)}
                            </div>
                            `
                        ).join('')
                        : `
                        <div class="text-slate-400">
                        回答なし
                        </div>
                        `
                    }

                </div>
                `;
            }

            const counts={};

            values.forEach(
                value=>{
                    counts[value]=
                        (counts[value] || 0)+1;
                }
            );

            return `
            <div
            class="bg-white border rounded-2xl p-5"
            >

                <div class="font-bold mb-5">
                ${App.Util.h(question.number)}
                ${App.Util.h(question.text)}
                </div>

                <div class="space-y-3">

                ${
                    (question.options || [])
                    .map(
                        option=>{

                            const count=
                                counts[option] || 0;

                            const percent=
                                values.length
                                ? (
                                    count /
                                    values.length *
                                    100
                                ).toFixed(1)
                                : '0.0';

                            return `
                            <div>

                                <div
                                class="flex justify-between text-sm mb-1"
                                >
                                    <span>
                                    ${App.Util.h(option)}
                                    </span>

                                    <span>
                                    ${count} 件
                                    (${percent}%)
                                    </span>
                                </div>

                                <div
                                class="h-3 bg-slate-100 rounded-full overflow-hidden"
                                >

                                    <div
                                    class="h-full bg-blue-500"
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
        },

        responseTable(responses){

            const keyword=
                String(App.State.responseKeyword || '')
                .trim()
                .toLowerCase();

            const filtered=responses.filter(
                response=>{

                    if(!keyword)return true;

                    return (
                        String(
                            response.company || ''
                        )
                        .toLowerCase()
                        .includes(keyword)
                        ||
                        String(
                            response.name || ''
                        )
                        .toLowerCase()
                        .includes(keyword)
                    );
                }
            );

            if(!filtered.length){
                return `
                <div class="p-8 text-center text-slate-500">
                該当する回答はありません。
                </div>
                `;
            }

            return `
            <table class="w-full text-sm">

                <thead class="bg-slate-50">
                    <tr>
                        <th class="text-left p-3">
                        回答日時
                        </th>
                        <th class="text-left p-3">
                        会社名
                        </th>
                        <th class="text-left p-3">
                        氏名
                        </th>
                        <th class="text-left p-3">
                        メール
                        </th>
                        <th class="p-3">
                        操作
                        </th>
                    </tr>
                </thead>

                <tbody>

                ${
                    filtered.map(
                        response=>`
                        <tr class="border-t">

                            <td class="p-3">
                            ${App.Util.h(response.answered_at)}
                            </td>

                            <td class="p-3">
                            ${App.Util.h(response.company)}
                            </td>

                            <td class="p-3">
                            ${App.Util.h(response.name)}
                            </td>

                            <td class="p-3">
                            ${App.Util.h(response.email)}
                            </td>

                            <td class="p-3 text-center">
                                <button
                                onclick="
                                App.actions.response(
                                    '${App.Util.h(response.id)}'
                                )"
                                class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700"
                                >
                                全回答を表示
                                </button>
                            </td>

                        </tr>
                        `
                    ).join('')
                }

                </tbody>

            </table>
            `;
        },

        responseModal(){

            const response=
                App.State.responseModal;

            if(!response)return;

            const modal=document.createElement('div');

            modal.id='response_modal';

            modal.className=
                'fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-5';

            modal.innerHTML=`

            <div
            class="bg-white rounded-2xl max-w-3xl w-full max-h-[85vh] overflow-auto p-6"
            >

                <div
                class="flex justify-between mb-5"
                >

                    <h2 class="font-bold text-xl">
                    全回答
                    </h2>

                    <button
                    onclick="App.actions.closeModal()"
                    class="text-2xl"
                    >
                    ×
                    </button>

                </div>

                <div
                id="response_detail"
                class="space-y-4"
                >

                ${
                    Object.entries(
                        response.answers || {}
                    ).map(
                        ([qid,value])=>`
                        <div
                        class="border rounded-xl p-4"
                        >

                            <div class="font-semibold mb-2">
                            ${App.Util.h(
                                App.questionText(qid)
                            )}
                            </div>

                            <div>
                            ${App.Util.h(
                                Array.isArray(value)
                                ? value.join('、')
                                : value
                            )}
                            </div>

                        </div>
                        `
                    ).join('')
                }

                </div>

            </div>
            `;

            document.body.appendChild(modal);
        },

        mail(){

            const survey=App.State.survey;

            const customers=(
                App.State.data?.customers || []
            );

            document.getElementById(
                'view'
            ).innerHTML=`

            <div
            class="bg-white border rounded-2xl p-6 mb-5"
            >

                <div
                class="flex justify-between gap-3 flex-wrap mb-5"
                >

                    <div>
                        <h2 class="font-bold text-xl">
                        ${App.Util.h(survey.title)}
                        </h2>

                        <p class="text-sm text-slate-500 mt-1">
                        顧客選択・メール送信
                        </p>
                    </div>

                    <button
                    onclick="
                    App.actions.sendMail(
                        '${App.Util.h(survey.id)}'
                    )"
                    class="bg-blue-600 text-white rounded-xl px-5 py-3 font-semibold"
                    >
                    一括送信
                    </button>

                </div>

                <div
                class="flex gap-3 mb-5 flex-wrap"
                >

                    <input
                    id="customer_filter"
                    value="${App.Util.h(App.State.customerKeyword || '')}"
                    oninput="App.actions.customerFilter(this.value)"
                    placeholder="顧客名・メール検索"
                    class="border rounded-xl px-4 py-2 w-72"
                    >

                    <select
                    id="template_type"
                    class="border rounded-xl px-3 py-2"
                    >
                        <option value="initial">
                        初回送信
                        </option>
                        <option value="reminder">
                        リマインド
                        </option>
                    </select>

                </div>

                <div
                class="grid md:grid-cols-2 gap-4 mb-5"
                >

                    <input
                    id="mail_subject"
                    placeholder="件名"
                    class="border rounded-xl px-4 py-3"
                    value="${App.Util.h(
                        '【アンケートのご案内】'+survey.title
                    )}"
                    >

                    <div></div>

                    <textarea
                    id="mail_body"
                    rows="8"
                    placeholder="本文"
                    class="border rounded-xl px-4 py-3 md:col-span-2"
                    >{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>

                </div>

            </div>

            <div
            class="bg-white border rounded-2xl overflow-x-auto"
            >

                <table class="w-full text-sm">

                    <thead class="bg-slate-50">

                        <tr>

                            <th class="p-3">
                                <input
                                id="select_all"
                                type="checkbox"
                                onchange="
                                App.actions.selectAll(
                                    this.checked
                                )"
                                >
                            </th>

                            <th class="text-left p-3">
                            会社名 / 氏名等
                            </th>

                            <th class="text-left p-3">
                            送信状況
                            </th>

                            <th class="text-left p-3">
                            回答状況
                            </th>

                            <th class="text-left p-3">
                            kintone
                            </th>

                        </tr>

                    </thead>

                    <tbody id="customer_table">

                    ${App.Render.customers(customers)}

                    </tbody>

                </table>

            </div>
            `;
        },

        customers(customers){

            const keyword=
                String(
                    App.State.customerKeyword || ''
                )
                .trim()
                .toLowerCase();

            return customers
                .filter(
                    customer=>{
                        if(!keyword)return true;

                        const target=[
                            customer.company,
                            customer.name,
                            customer.email,
                            customer.phone
                        ]
                        .join(' ')
                        .toLowerCase();

                        return target.includes(keyword);
                    }
                )
                .map(
                    customer=>{

                        const selected=
                            App.State.selectedCustomerIds
                            .includes(customer.id);

                        const web=
                            customer.source === 'web';

                        return `
                        <tr class="border-t">

                            <td class="p-3 text-center">

                            <input
                            type="checkbox"
                            ${selected?'checked':''}
                            ${web?'disabled':''}
                            onchange="
                            App.actions.customerSelect(
                                '${App.Util.h(customer.id)}',
                                this.checked
                            )"
                            >

                            </td>

                            <td class="p-3">

                                <div class="font-bold">
                                ${App.Util.h(customer.company)}
                                </div>

                                <div>
                                ${App.Util.h(customer.name)}
                                </div>

                                <div class="text-xs text-slate-500">
                                ${App.Util.h(customer.email)}
                                </div>

                                <div class="text-xs text-slate-500">
                                ${App.Util.h(customer.phone)}
                                </div>

                            </td>

                            <td class="p-3">

                                ${
                                    web
                                    ? '<span class="text-slate-400">Web直接回答</span>'
                                    : `
                                    <div>
                                    最終送信:
                                    ${App.Util.h(customer.sent_at || '未送信')}
                                    </div>

                                    <div>
                                    送信回数:
                                    ${Number(customer.send_count || 0)}
                                    </div>
                                    `
                                }

                            </td>

                            <td class="p-3">

                                <span
                                class="
                                px-2 py-1 rounded-full text-xs
                                ${
                                    customer.answer_status==='answered'
                                    ? 'bg-green-100 text-green-700'
                                    : 'bg-amber-100 text-amber-700'
                                }
                                "
                                >
                                ${
                                    customer.answer_status==='answered'
                                    ? '回答済み'
                                    : '送信済み（未回答）'
                                }
                                </span>

                            </td>

                            <td class="p-3">

                                ${
                                    customer.kintone_status==='registered'
                                    ? `
                                    <span class="text-green-600">
                                    ✓ kintone登録完了
                                    </span>
                                    `
                                    : `
                                    <button
                                    onclick="
                                    App.actions.markKintone(
                                        '${App.Util.h(customer.id)}'
                                    )"
                                    class="px-3 py-1.5 bg-slate-100 rounded-lg"
                                    >
                                    kintone登録完了
                                    </button>
                                    `
                                }

                            </td>

                        </tr>
                        `;
                    }
                )
                .join('');
        },

        settings(){

            const settings=
                App.State.data?.settings || {};

            document.getElementById(
                'view'
            ).innerHTML=`

            <form
            id="settings_form"
            class="bg-white border rounded-2xl p-6 max-w-4xl"
            >

                <div class="grid md:grid-cols-2 gap-5">

                    <label>

                        <span class="font-semibold text-sm">
                        サブドメイン
                        </span>

                        <input
                        id="setting_subdomain"
                        value="${App.Util.h(settings.subdomain)}"
                        placeholder="xxxx.cybozu.com"
                        class="w-full border rounded-xl px-4 py-2 mt-1"
                        >

                    </label>

                    <label>

                        <span class="font-semibold text-sm">
                        アプリID
                        </span>

                        <input
                        id="setting_app_id"
                        value="${App.Util.h(settings.app_id)}"
                        inputmode="numeric"
                        class="w-full border rounded-xl px-4 py-2 mt-1"
                        >

                    </label>

                    <label>

                        <span class="font-semibold text-sm">
                        ログイン名
                        </span>

                        <input
                        id="setting_login_name"
                        value="${App.Util.h(settings.login_name)}"
                        autocomplete="username"
                        class="w-full border rounded-xl px-4 py-2 mt-1"
                        >

                    </label>

                    <label>

                        <span class="font-semibold text-sm">
                        パスワード
                        </span>

                        <input
                        id="setting_password"
                        type="password"
                        autocomplete="new-password"
                        placeholder="変更時のみ入力"
                        class="w-full border rounded-xl px-4 py-2 mt-1"
                        >

                    </label>

                    <label class="md:col-span-2">

                        <span class="font-semibold text-sm">
                        Proxy
                        </span>

                        <input
                        id="setting_proxy"
                        value="${App.Util.h(settings.proxy)}"
                        placeholder="host:port / http://host:port / https://host:port"
                        class="w-full border rounded-xl px-4 py-2 mt-1"
                        >

                    </label>

                </div>

                <label class="flex gap-2 mt-5">

                    <input
                    id="setting_ssl_verify"
                    type="checkbox"
                    ${settings.ssl_verify ? 'checked' : ''}
                    >

                    SSL証明書を検証する

                </label>

                <div class="mt-7 border-t pt-6">

                    <div
                    class="flex justify-between items-center mb-4 gap-3 flex-wrap"
                    >

                        <h2 class="font-bold">
                        kintoneフィールドマッピング
                        </h2>

                        <button
                        type="button"
                        onclick="App.actions.fetchKintoneFields()"
                        class="px-4 py-2 bg-slate-900 text-white rounded-xl"
                        >
                        項目一覧を再取得
                        </button>

                    </div>

                    <div
                    id="field_message"
                    class="mb-4 whitespace-pre-line text-sm"
                    ></div>

                    <div
                    id="field_mapping"
                    class="grid md:grid-cols-2 gap-4"
                    >

                    ${App.Render.mapping(settings)}

                    </div>

                </div>

                <input
                type="hidden"
                id="settings_json"
                >

                <div class="flex gap-3 mt-7">

                    <button
                    type="button"
                    onclick="App.actions.testKintone()"
                    class="px-5 py-3 bg-slate-100 rounded-xl"
                    >
                    接続確認
                    </button>

                    <button
                    type="button"
                    onclick="App.actions.saveSettings()"
                    class="px-5 py-3 bg-blue-600 text-white rounded-xl"
                    >
                    設定を保存
                    </button>

                </div>

            </form>
            `;
        },

        mapping(settings){

            const names=[
                ['field_company','会社名'],
                ['field_name','氏名'],
                ['field_email','メールアドレス'],
                ['field_department','部署名'],
                ['field_phone','電話番号']
            ];

            const options=App.State.fields.map(
                field=>`
                <option
                value="${App.Util.h(field.code)}"
                >
                ${App.Util.h(field.label)}
                (${App.Util.h(field.code)})
                </option>
                `
            ).join('');

            return names.map(
                ([key,label])=>`

                <label>

                    <span class="text-sm font-semibold">
                    ${label}
                    </span>

                    <select
                    id="${key}"
                    class="mt-1 w-full border rounded-xl px-3 py-2"
                    >

                        <option value="">
                        未設定
                        </option>

                        ${options}

                    </select>

                </label>
                `
            ).join('')
            +`

            <label>

                <span class="text-sm font-semibold">
                住所（複数可）
                </span>

                <select
                id="field_address"
                multiple
                class="mt-1 w-full border rounded-xl px-3 py-2 h-36"
                >

                ${options}

                </select>

            </label>
            `;
        }
    },

    answersCount(survey){

        return (
            App.State.data?.responses || []
        ).filter(
            response=>
                response.survey_id === survey.id
        ).length;
    },

    questionText(id){

        const survey=App.State.survey;

        if(!survey)return '';

        for(const group of survey.groups || []){
            for(const question of group.questions || []){
                if(question.id === id){
                    return question.text || '';
                }
            }
        }

        return '';
    },

    renumberData(){

        const survey=App.State.survey;

        if(!survey)return;

        let globalNumber=0;

        survey.groups.forEach(
            (group,groupIndex)=>{

                group.questions.forEach(
                    (question,questionIndex)=>{

                        globalNumber++;

                        question.number=
                            survey.numbering_mode==='group'
                            ? `Q${groupIndex+1}-${questionIndex+1}`
                            : `Q${globalNumber}`;
                    }
                );
            }
        );
    },

    actions: {

        async init(){

            if(App.State.__loaded){
                return;
            }

            App.State.__loaded=true;

            const app=document.getElementById('app');

            try{

                await App.API.load();

                App.Render.root();

                App.Render.list();

            }catch(error){

                const message=
                    error instanceof Error
                    ? error.message
                    : String(error);

                if(app){

                    app.innerHTML=`

                    <div class="min-h-screen">

                        <header
                        class="bg-white border-b border-red-200"
                        >

                            <div
                            class="max-w-7xl mx-auto px-5 py-4"
                            >

                                <h1 class="text-xl font-bold">
                                アンケート管理システム
                                </h1>

                            </div>

                        </header>

                        <main
                        class="max-w-4xl mx-auto px-5 py-8"
                        >

                            <div
                            class="bg-white border border-red-200 rounded-2xl p-6"
                            >

                                <h2
                                class="text-lg font-bold text-red-600 mb-4"
                                >
                                初期化エラー
                                </h2>

                                <div
                                class="whitespace-pre-wrap text-sm text-slate-700"
                                >
                                ${App.Util.h(message)}
                                </div>

                                <div class="mt-6 p-4 bg-slate-50 rounded-xl text-sm">

                                <div class="font-semibold mb-2">
                                確認事項
                                </div>

                                <ul class="list-disc pl-5 space-y-1">
                                    <li>
                                    PHP 8.5 が動作しているか
                                    </li>
                                    <li>
                                    survey_storage ディレクトリを書き込めるか
                                    </li>
                                    <li>
                                    ApacheのPHPエラーログ
                                    </li>
                                    <li>
                                    ブラウザのJavaScript Console
                                    </li>
                                    <li>
                                    外部CDNへの通信が許可されているか
                                    </li>
                                </ul>

                                </div>

                            </div>

                        </main>

                    </div>
                    `;
                }
            }
        },

        home(){

            App.State.page='list';
            App.State.survey=null;
            App.State.dirty=false;

            App.Render.root();
            App.Render.list();
        },

        newSurvey(){

            App.State.survey={
                id:App.Util.id(),
                title:'',
                start_at:'',
                end_at:'',
                status:'draft',
                created_at:'',
                updated_at:'',
                numbering_mode:'global',
                groups:[],
                deleted:false
            };

            App.State.page='editor';
            App.State.dirty=true;

            App.Render.root();
            App.Render.editor();
        },

        edit(id){

            const survey=(
                App.State.data?.surveys || []
            ).find(
                item=>item.id===id
            );

            if(!survey)return;

            App.State.survey=
                App.Util.clone(survey);

            App.State.page='editor';
            App.State.dirty=false;

            App.Render.root();
            App.Render.editor();
        },

        field(key,value){

            if(!App.State.survey)return;

            App.State.survey[key]=value;
            App.State.dirty=true;
        },

        groupField(id,key,value){

            const group=
                App.State.survey?.groups.find(
                    item=>item.id===id
                );

            if(!group)return;

            group[key]=value;
            App.State.dirty=true;
        },

        questionField(id,key,value){

            for(
                const group of
                App.State.survey?.groups || []
            ){

                const question=
                    group.questions.find(
                        item=>item.id===id
                    );

                if(!question)continue;

                question[key]=value;

                if(key==='type' &&
                    value==='text'){
                    question.options=[];
                }

                if(key==='type' &&
                    value!=='text' &&
                    (!Array.isArray(question.options) ||
                     question.options.length===0)){
                    question.options=[
                        '選択肢1',
                        '選択肢2'
                    ];
                }

                break;
            }

            App.State.dirty=true;

            App.Render.editor();
        },

        optionField(id,index,value){

            for(
                const group of
                App.State.survey?.groups || []
            ){

                const question=
                    group.questions.find(
                        item=>item.id===id
                    );

                if(!question)continue;

                if(!Array.isArray(question.options)){
                    question.options=[];
                }

                question.options[index]=value;

                break;
            }

            App.State.dirty=true;
        },

        addOption(id){

            for(
                const group of
                App.State.survey?.groups || []
            ){

                const question=
                    group.questions.find(
                        item=>item.id===id
                    );

                if(!question)continue;

                if(!Array.isArray(question.options)){
                    question.options=[];
                }

                question.options.push(
                    '選択肢'+
                    (question.options.length+1)
                );

                break;
            }

            App.State.dirty=true;

            App.Render.editor();
        },

        removeOption(id,index){

            for(
                const group of
                App.State.survey?.groups || []
            ){

                const question=
                    group.questions.find(
                        item=>item.id===id
                    );

                if(!question)continue;

                if(Array.isArray(question.options)){
                    question.options.splice(
                        index,
                        1
                    );
                }

                break;
            }

            App.State.dirty=true;

            App.Render.editor();
        },

        addGroup(){

            if(!App.State.survey)return;

            App.State.survey.groups.push({
                id:App.Util.id(),
                name:'新しいグループ',
                questions:[]
            });

            App.State.dirty=true;

            App.Render.editor();
        },

        deleteGroup(id){

            if(!confirm(
                'グループと内包する質問を削除しますか？'
            )){
                return;
            }

            App.State.survey.groups=
                App.State.survey.groups.filter(
                    group=>group.id!==id
                );

            App.State.dirty=true;

            App.Render.editor();
        },

        addQuestion(groupId){

            const group=
                App.State.survey?.groups.find(
                    item=>item.id===groupId
                );

            if(!group)return;

            group.questions.push({
                id:App.Util.id(),
                text:'',
                type:'single',
                required:false,
                options:[
                    '選択肢1',
                    '選択肢2'
                ],
                other_enabled:false,
                number:''
            });

            App.State.dirty=true;

            App.Render.editor();
        },

        deleteQuestion(id){

            if(!confirm(
                'この質問を削除しますか？'
            )){
                return;
            }

            for(
                const group of
                App.State.survey?.groups || []
            ){

                group.questions=
                    group.questions.filter(
                        question=>
                            question.id!==id
                    );
            }

            App.State.dirty=true;

            App.Render.editor();
        },

        sortable(){

            const editor=
                document.getElementById(
                    'question_editor'
                );

            if(
                !editor ||
                typeof Sortable === 'undefined'
            ){
                return;
            }

            new Sortable(
                editor,
                {
                    group:'survey-groups',
                    handle:'.cursor-move',
                    animation:150,
                    ghostClass:'opacity-40',

                    onEnd(){

                        const ids=[
                            ...editor.querySelectorAll(
                                '.group-item'
                            )
                        ].map(
                            item=>
                                item.dataset.groupId
                        );

                        App.State.survey.groups.sort(
                            (a,b)=>
                                ids.indexOf(a.id)
                                -
                                ids.indexOf(b.id)
                        );

                        App.State.dirty=true;

                        App.actions
                            .sortableQuestions();

                        App.renumberData();

                        App.Render.editor();
                    }
                }
            );

            App.actions.sortableQuestions();
        },

        sortableQuestions(){

            document
                .querySelectorAll('.question-list')
                .forEach(
                    element=>{

                        new Sortable(
                            element,
                            {
                                group:'survey-questions',
                                handle:'.cursor-move',
                                animation:150,
                                ghostClass:'opacity-40',

                                onEnd(event){

                                    const id=
                                        event.item
                                        .dataset
                                        .questionId;

                                    let moved=null;

                                    for(
                                        const group of
                                        App.State.survey.groups
                                    ){

                                        const index=
                                            group.questions
                                            .findIndex(
                                                q=>q.id===id
                                            );

                                        if(index>=0){
                                            moved=
                                                group.questions
                                                .splice(
                                                    index,
                                                    1
                                                )[0];
                                            break;
                                        }
                                    }

                                    const target=
                                        App.State.survey.groups
                                        .find(
                                            group=>
                                                group.id===
                                                event.to.dataset.groupId
                                        );

                                    if(target && moved){
                                        target.questions.splice(
                                            event.newIndex,
                                            0,
                                            moved
                                        );
                                    }

                                    App.State.dirty=true;

                                    App.renumberData();

                                    App.Render.editor();
                                }
                            }
                        );
                    }
                );
        },

        async saveEditor(){

            try{

                App.renumberData();

                const survey=
                    App.Util.clone(
                        App.State.survey
                    );

                await App.API.saveSurvey(
                    survey
                );

                App.State.dirty=false;

                alert('保存しました。');

                App.actions.home();

            }catch(error){

                alert(
                    error instanceof Error
                    ? error.message
                    : String(error)
                );
            }
        },

        cancelEditor(){

            if(
                App.State.dirty &&
                !confirm(
                    '未保存の変更を破棄しますか？'
                )
            ){
                return;
            }

            App.actions.home();
        },

        preview(){

            App.Render.preview();
        },

        closePreview(){

            document.getElementById(
                'preview_modal'
            )?.remove();
        },

        aggregate(id){

            const survey=(
                App.State.data?.surveys || []
            ).find(
                item=>item.id===id
            );

            if(!survey)return;

            App.State.survey=
                App.Util.clone(survey);

            App.State.page='aggregate';

            App.State.selectedQuestions={};

            survey.groups.forEach(
                group=>{
                    group.questions.forEach(
                        question=>{
                            App.State.selectedQuestions[
                                question.id
                            ]=true;
                        }
                    );
                }
            );

            App.Render.root();
            App.Render.aggregate();
        },

        questionFilter(id,value){

            App.State.selectedQuestions[id]=
                Boolean(value);

            App.Render.aggregate();
        },

        selectQuestions(value){

            App.State.survey.groups.forEach(
                group=>{
                    group.questions.forEach(
                        question=>{
                            App.State.selectedQuestions[
                                question.id
                            ]=Boolean(value);
                        }
                    );
                }
            );

            App.Render.aggregate();
        },

        responseFilter(value){

            App.State.responseKeyword=value;

            const responses=(
                App.State.data?.responses || []
            ).filter(
                response=>
                    response.survey_id===
                    App.State.survey.id
            );

            const table=
                document.getElementById(
                    'response_table'
                );

            if(table){
                table.innerHTML=
                    App.Render.responseTable(
                        responses
                    );
            }
        },

        response(id){

            const response=(
                App.State.data?.responses || []
            ).find(
                item=>item.id===id
            );

            if(!response)return;

            App.State.responseModal=
                response;

            App.Render.responseModal();
        },

        closeModal(){

            document.getElementById(
                'response_modal'
            )?.remove();

            App.State.responseModal=null;
        },

        mail(id){

            const survey=(
                App.State.data?.surveys || []
            ).find(
                item=>item.id===id
            );

            if(!survey)return;

            App.State.survey=
                App.Util.clone(survey);

            App.State.page='mail';

            App.State.selectedCustomerIds=[];

            App.Render.root();
            App.Render.mail();
        },

        customerFilter(value){

            App.State.customerKeyword=value;

            const table=
                document.getElementById(
                    'customer_table'
                );

            if(table){
                table.innerHTML=
                    App.Render.customers(
                        App.State.data?.customers || []
                    );
            }
        },

        customerSelect(id,value){

            if(value){

                if(
                    !App.State.selectedCustomerIds
                    .includes(id)
                ){
                    App.State.selectedCustomerIds
                        .push(id);
                }

            }else{

                App.State.selectedCustomerIds=
                    App.State.selectedCustomerIds
                    .filter(
                        item=>item!==id
                    );
            }
        },

        selectAll(value){

            if(value){

                App.State.selectedCustomerIds=
                    (
                        App.State.data?.customers || []
                    )
                    .filter(
                        customer=>
                            customer.source!=='web'
                    )
                    .map(
                        customer=>customer.id
                    );

            }else{

                App.State.selectedCustomerIds=[];
            }

            App.Render.mail();
        },

        async sendMail(surveyId){

            const ids=
                App.State.selectedCustomerIds;

            if(!ids.length){
                alert(
                    '送信先を選択してください。'
                );
                return;
            }

            const already=(
                App.State.data?.customers || []
            ).filter(
                customer=>
                    ids.includes(customer.id) &&
                    Number(
                        customer.send_count || 0
                    )>0
            );

            if(
                already.length &&
                !confirm(
                    '既に送信済みの宛先が含まれています。'
                    +'再送しますか？'
                )
            ){
                return;
            }

            try{

                const subject=
                    document.getElementById(
                        'mail_subject'
                    )?.value || '';

                const body=
                    document.getElementById(
                        'mail_body'
                    )?.value || '';

                const template=
                    document.getElementById(
                        'template_type'
                    )?.value || 'initial';

                const json=
                    await App.API.request(
                        'send_mail',
                        {
                            survey_id:surveyId,
                            recipient_ids:
                                App.Util.json(ids),
                            mail_subject:subject,
                            mail_body:body,
                            template_type:template
                        }
                    );

                if(!json.ok){
                    throw new Error(
                        json.message
                    );
                }

                alert(
                    json.message
                    +' 成功:'
                    +json.sent
                    +'件 / 失敗:'
                    +json.failed
                    +'件'
                );

                await App.API.load();

                App.Render.mail();

            }catch(error){

                alert(
                    error instanceof Error
                    ? error.message
                    : String(error)
                );
            }
        },

        async duplicate(id){

            if(!confirm(
                'このアンケートを複製しますか？'
            )){
                return;
            }

            try{

                const json=
                    await App.API.request(
                        'duplicate_survey',
                        {
                            survey_id:id
                        }
                    );

                if(!json.ok){
                    throw new Error(
                        json.message
                    );
                }

                await App.API.load();

                App.Render.list();

            }catch(error){

                alert(
                    error instanceof Error
                    ? error.message
                    : String(error)
                );
            }
        },

        async delete(id){

            if(!confirm(
                'このアンケートを削除しますか？'
            )){
                return;
            }

            try{

                const json=
                    await App.API.request(
                        'delete_survey',
                        {
                            survey_id:id
                        }
                    );

                if(!json.ok){
                    throw new Error(
                        json.message
                    );
                }

                await App.API.load();

                App.Render.list();

            }catch(error){

                alert(
                    error instanceof Error
                    ? error.message
                    : String(error)
                );
            }
        },

        async toggle(id,status){

            if(!confirm(
                'ステータスを変更しますか？'
            )){
                return;
            }

            try{

                const json=
                    await App.API.request(
                        'toggle_status',
                        {
                            survey_id:id,
                            status:status
                        }
                    );

                if(!json.ok){
                    throw new Error(
                        json.message
                    );
                }

                await App.API.load();

                App.Render.list();

            }catch(error){

                alert(
                    error instanceof Error
                    ? error.message
                    : String(error)
                );
            }
        },

        keyword(value){

            App.State.filter.keyword=value;

            App.Render.list();
        },

        statusFilter(value){

            App.State.filter.status=value;

            App.Render.list();
        },

        sort(value){

            App.State.filter.sort=value;

            App.Render.list();
        },

        settings(){

            App.State.page='settings';

            App.Render.root();
            App.Render.settings();

            /*
             * 保存済みフィールド値を再描画後に反映。
             */
            const settings=
                App.State.data?.settings || {};

            [
                'field_company',
                'field_name',
                'field_email',
                'field_department',
                'field_phone'
            ].forEach(
                key=>{
                    const element=
                        document.getElementById(key);

                    if(element){
                        element.value=
                            settings[key] || '';
                    }
                }
            );

            const address=
                document.getElementById(
                    'field_address'
                );

            if(address){
                const selected=
                    settings.field_address || [];

                [...address.options]
                    .forEach(
                        option=>{
                            option.selected=
                                selected.includes(
                                    option.value
                                );
                        }
                    );
            }
        },

        settingsObject(){

            const old=
                App.State.data?.settings || {};

            const address=
                document.getElementById(
                    'field_address'
                );

            return {

                subdomain:
                    document.getElementById(
                        'setting_subdomain'
                    )?.value.trim() || '',

                login_name:
                    document.getElementById(
                        'setting_login_name'
                    )?.value.trim() || '',

                password:
                    document.getElementById(
                        'setting_password'
                    )?.value || old.password || '',

                app_id:
                    document.getElementById(
                        'setting_app_id'
                    )?.value.trim() || '',

                proxy:
                    document.getElementById(
                        'setting_proxy'
                    )?.value.trim() || '',

                ssl_verify:
                    Boolean(
                        document.getElementById(
                            'setting_ssl_verify'
                        )?.checked
                    ),

                field_company:
                    document.getElementById(
                        'field_company'
                    )?.value || '',

                field_name:
                    document.getElementById(
                        'field_name'
                    )?.value || '',

                field_email:
                    document.getElementById(
                        'field_email'
                    )?.value || '',

                field_department:
                    document.getElementById(
                        'field_department'
                    )?.value || '',

                field_phone:
                    document.getElementById(
                        'field_phone'
                    )?.value || '',

                field_address:
                    address
                    ? [...address.selectedOptions]
                        .map(
                            option=>option.value
                        )
                    : []
            };
        },

        async fetchKintoneFields(){

            const message=
                document.getElementById(
                    'field_message'
                );

            if(!message)return;

            message.className=
                'mb-4 whitespace-pre-line text-sm text-slate-600';

            message.textContent=
                'kintoneから項目一覧を取得しています…';

            try{

                const settings=
                    App.actions.settingsObject();

                const json=
                    await App.API.request(
                        'fetch_kintone_fields',
                        {
                            settings_json:
                                App.Util.json(
                                    settings
                                )
                        }
                    );

                if(!json.ok){

                    message.className=
                        'mb-4 whitespace-pre-line text-sm text-red-600';

                    message.textContent=
                        json.message ||
                        '項目取得に失敗しました。';

                    return;
                }

                App.State.fields=
                    Array.isArray(json.fields)
                    ? json.fields
                    : [];

                message.className=
                    'mb-4 whitespace-pre-line text-sm text-green-600';

                message.textContent=
                    json.message
                    +'\nHTTPステータス: '
                    +String(json.status ?? 0)
                    +'\n接続先: '
                    +String(json.url ?? '');

                const mapping=
                    document.getElementById(
                        'field_mapping'
                    );

                if(mapping){

                    const settings2=
                        App.actions.settingsObject();

                    mapping.innerHTML=
                        App.Render.mapping(
                            settings2
                        );

                    App.actions.settings();
                }

            }catch(error){

                message.className=
                    'mb-4 whitespace-pre-line text-sm text-red-600';

                message.textContent=
                    error instanceof Error
                    ? error.message
                    : String(error);
            }
        },

        async testKintone(){

            const message=
                document.getElementById(
                    'field_message'
                );

            if(!message)return;

            message.className=
                'mb-4 whitespace-pre-line text-sm text-slate-600';

            message.textContent=
                '接続確認中…';

            try{

                const settings=
                    App.actions.settingsObject();

                const json=
                    await App.API.request(
                        'test_kintone',
                        {
                            settings_json:
                                App.Util.json(
                                    settings
                                )
                        }
                    );

                message.className=
                    'mb-4 whitespace-pre-line text-sm '
                    +(json.ok
                        ? 'text-green-600'
                        : 'text-red-600');

                message.textContent=
                    String(
                        json.message || ''
                    )
                    +'\nHTTPステータス: '
                    +String(
                        json.status ?? 0
                    )
                    +'\n接続先: '
                    +String(
                        json.url ||
                        '(URL生成前)'
                    )
                    +'\nProxy: '
                    +(json.proxy_used
                        ? '使用'
                        : '未使用');

            }catch(error){

                message.className=
                    'mb-4 whitespace-pre-line text-sm text-red-600';

                message.textContent=
                    error instanceof Error
                    ? error.message
                    : String(error);
            }
        },

        async saveSettings(){

            const settings=
                App.actions.settingsObject();

            try{

                const json=
                    await App.API.saveSettings(
                        settings
                    );

                if(!json.ok){
                    throw new Error(
                        json.message
                    );
                }

                alert(json.message);

                App.actions.settings();

            }catch(error){

                alert(
                    error instanceof Error
                    ? error.message
                    : String(error)
                );
            }
        },

        async markKintone(id){

            try{

                const json=
                    await App.API.request(
                        'mark_kintone',
                        {
                            customer_id:id
                        }
                    );

                if(!json.ok){
                    throw new Error(
                        json.message
                    );
                }

                await App.API.load();

                App.Render.mail();

            }catch(error){

                alert(
                    error instanceof Error
                    ? error.message
                    : String(error)
                );
            }
        },

        csv(id){

            location.href=
                location.pathname
                +'?action=csv&survey_id='
                +encodeURIComponent(id);
        },

        logout(){

            if(!confirm(
                'ログアウトしますか？'
            )){
                return;
            }

            /*
             * 本システムには認証画面を固定実装していないため、
             * セッション上のCSRFを破棄してページを再読み込みする。
             */
            location.href=
                location.pathname
                +'?logout=1';
        }
    },

    init(){

        if(window.App.__initialized){
            return;
        }

        window.App.__initialized=true;

        App.actions.init();
    }
};

/*
 * ログアウト処理。
 */
<?php
if (isset($_GET['logout'])) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    @session_destroy();

    header(
        'Location: ' .
        strtok(
            (string)($_SERVER['REQUEST_URI'] ?? ''),
            '?'
        )
    );

    exit;
}
?>

/*
 * 初期化タイミングをdocument.readyStateで二重防御。
 */
if(document.readyState === 'loading'){

    document.addEventListener(
        'DOMContentLoaded',
        function(){
            App.init();
        },
        {once:true}
    );

}else{

    App.init();
}

/*
 * JavaScript未実行時の診断表示。
 * JavaScript実行後には App が上書きする。
 */
window.setTimeout(function(){

    if(
        window.App &&
        window.App.__initialized &&
        window.App.State &&
        window.App.State.data
    ){
        return;
    }

    const app=document.getElementById('app');

    if(!app)return;

    /*
     * 既に初期化エラー画面などが表示されている場合は変更しない。
     */
    if(
        app.textContent &&
        !app.textContent.includes('読み込み中です')
    ){
        return;
    }

    app.innerHTML=`
    <div class="min-h-screen">

        <header class="bg-white border-b">
            <div class="max-w-4xl mx-auto px-5 py-4">
                <h1 class="font-bold text-xl">
                アンケート管理システム
                </h1>
            </div>
        </header>

        <main class="max-w-4xl mx-auto px-5 py-8">

            <div
            class="bg-white border border-red-200 rounded-2xl p-6"
            >

                <h2 class="font-bold text-red-600 mb-3">
                画面の初期化に時間がかかっています
                </h2>

                <p class="text-sm text-slate-600">
                ブラウザのJavaScript Console、
                PHPエラーログ、
                survey_storage の書き込み権限を確認してください。
                </p>

            </div>

        </main>

    </div>
    `;

},8000);

</script>

</body>
</html>

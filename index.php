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
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

    return array_replace_recursive(survey_default_data(), $data);
}

function survey_write_data(array $data): bool
{
    if (!is_dir(SURVEY_STORAGE_DIRECTORY) &&
        !@mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true)) {
        return false;
    }

    $tmp = SURVEY_STORAGE_FILE . '.tmp';

    if (@file_put_contents($tmp, survey_json($data), LOCK_EX) === false) {
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

    return $a !== '' && $b !== '' && hash_equals($a, $b);
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
        return ['ok' => false, 'error' => 'kintoneサブドメインが未入力です。'];
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
        preg_match('~^https?://([^/?#]+)~i', $input, $m)) {
        $authority = strtolower($m[1]);

        if (preg_match('~^(.+):(\d+)$~', $authority, $pm)) {
            $host = $pm[1];
            $port = (int)$pm[2];
        } else {
            $host = $authority;
        }
    }

    $host = strtolower(trim($host));
    $host = trim($host, '[]');

    if ($host === '') {
        return ['ok' => false, 'error' => 'kintoneホスト名を取得できません。'];
    }

    if ($port !== null && ($port < 1 || $port > 65535)) {
        return ['ok' => false, 'error' => 'kintoneポート番号が不正です。'];
    }

    $valid =
        preg_match(
            '~^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.cybozu\.com$~i',
            $host
        ) ||
        preg_match(
            '~^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$~i',
            $host
        );

    if (!$valid) {
        return ['ok' => false, 'error' => '許可されていないkintoneホスト名です。'];
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
            'error' => 'Proxy形式は host:port、http://host:port、https://host:port で指定してください。',
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

/* =========================================================
 * HTTPレスポンスヘッダー
 * ========================================================= */

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
                . ' php.ini の allow_url_fopen、HTTP wrapper、OpenSSLを確認してください。',
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
    $peerName = is_array($parsed) ? (string)($parsed['host'] ?? '') : '';

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
        $body = file_get_contents($url, false, $context);
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
            $diagnostic .= "\nProxy: 使用\nProxy接続失敗の可能性があります。";
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

/* =========================================================
 * kintone
 * ========================================================= */

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

    if ($appId === '' || !preg_match('/^[0-9]+$/', $appId)) {
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

    /*
     * APIトークンは使用しない。
     */
    $authorization = base64_encode($login . ':' . $password);

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
    $proxy = !empty($r['proxy_used']) ? '使用' : '未使用';

    if ($status === 0) {
        return
            "kintoneからHTTPレスポンスを取得できませんでした。\n"
            . "HTTPステータス: 0\n"
            . "接続先: {$url}\n"
            . "Proxy: {$proxy}\n"
            . "PHP通信エラー: " . ($error !== '' ? $error : 'なし')
            . "\n確認事項: DNS、外部HTTPS通信、Proxy、ファイアウォール、SSL/TLS、OpenSSL。";
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
        return "kintone通信がタイムアウトしました。\nHTTPステータス: 408\n接続先: {$url}";
    }

    if ($status === 429) {
        return "kintone側のレート制限です。\nHTTPステータス: 429";
    }

    if ($status >= 500) {
        return "kintoneまたはProxy側のサーバーエラーです。\nHTTPステータス: {$status}";
    }

    if ($status >= 200 && $status < 300) {
        return "kintone通信に成功しました。\nHTTPステータス: {$status}";
    }

    return
        "kintone通信でエラーが発生しました。\n"
        . "HTTPステータス: {$status}\n"
        . "接続先: {$url}\n"
        . ($error !== '' ? "PHP通信エラー: {$error}" : '');
}

/* =========================================================
 * kintone fields
 * ========================================================= */

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

    if (!is_array($json) || !isset($json['properties']) ||
        !is_array($json['properties'])) {
        return [
            'ok' => false,
            'fields' => [],
            'message' => 'kintone APIレスポンスに properties がありません。',
        ];
    }

    $fields = [];

    foreach ($json['properties'] as $code => $property) {
        if (!is_array($property)) {
            continue;
        }

        $fields[] = [
            'code' => (string)$code,
            'label' => (string)($property['label'] ?? $code),
            'type' => (string)($property['type'] ?? ''),
        ];
    }

    return [
        'ok' => true,
        'fields' => $fields,
        'message' => '項目一覧を取得しました。',
    ];
}

/* =========================================================
 * メール
 * ========================================================= */

function survey_mail_send(
    string $to,
    string $subject,
    string $body
): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . ($_SERVER['SERVER_ADMIN'] ?? 'webmaster@localhost'),
    ];

    return @mail(
        $to,
        mb_encode_mimeheader($subject, 'UTF-8'),
        $body,
        implode("\r\n", $headers)
    );
}

/* =========================================================
 * CSV
 * ========================================================= */

function survey_csv_download(array $data, string $surveyId): never
{
    $survey = null;

    foreach ($data['surveys'] as $s) {
        if (($s['id'] ?? '') === $surveyId) {
            $survey = $s;
            break;
        }
    }

    if (!$survey) {
        http_response_code(404);
        exit('Survey not found');
    }

    $questions = [];

    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            $questions[] = $question;
        }
    }

    $fp = fopen('php://output', 'wb');

    if ($fp === false) {
        exit;
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey_' .
        preg_replace('/[^a-zA-Z0-9_-]/', '_', $surveyId) .
        '.csv"'
    );

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
            $qid = (string)($q['id'] ?? '');
            $value = $answers[$qid] ?? '';

            if (is_array($value)) {
                $value = implode('、', array_map('strval', $value));
            }

            $row[] = $value;
        }

        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}

/* =========================================================
 * API
 * ========================================================= */

$data = survey_read_data();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($action !== '') {
    if (
        in_array(
            $action,
            [
                'save_survey',
                'delete_survey',
                'duplicate_survey',
                'toggle_status',
                'save_settings',
                'send_mail',
                'mark_kintone',
            ],
            true
        ) &&
        !survey_check_token()
    ) {
        survey_api([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。',
        ], 403);
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

            $id = (string)($survey['id'] ?? '');
            if ($id === '') {
                $id = survey_id();
            }

            $existingIndex = null;

            foreach ($data['surveys'] as $i => $s) {
                if (($s['id'] ?? '') === $id) {
                    $existingIndex = $i;
                    break;
                }
            }

            $now = survey_now();

            $survey['id'] = $id;
            $survey['title'] = (string)($survey['title'] ?? '無題のアンケート');
            $survey['start_at'] = (string)($survey['start_at'] ?? '');
            $survey['end_at'] = (string)($survey['end_at'] ?? '');
            $survey['status'] = in_array(
                ($survey['status'] ?? 'draft'),
                ['draft', 'active', 'ended'],
                true
            ) ? $survey['status'] : 'draft';
            $survey['numbering_mode'] =
                ($survey['numbering_mode'] ?? 'global') === 'group'
                    ? 'group'
                    : 'global';
            $survey['groups'] =
                is_array($survey['groups'] ?? null)
                    ? $survey['groups']
                    : [];
            $survey['deleted'] = false;

            if ($existingIndex === null) {
                $survey['created_at'] = $now;
                $survey['updated_at'] = $now;
                $data['surveys'][] = $survey;
            } else {
                $survey['created_at'] =
                    $data['surveys'][$existingIndex]['created_at'] ?? $now;
                $survey['updated_at'] = $now;
                $data['surveys'][$existingIndex] = $survey;
            }

            if (!survey_write_data($data)) {
                survey_api([
                    'ok' => false,
                    'message' => '保存に失敗しました。',
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

            foreach ($data['surveys'] as &$s) {
                if (($s['id'] ?? '') === $id) {
                    $s['deleted'] = true;
                    $s['status'] = 'draft';
                    $s['updated_at'] = survey_now();
                }
            }
            unset($s);

            survey_write_data($data);

            survey_api([
                'ok' => true,
                'message' => '削除しました。',
            ]);
            break;

        case 'duplicate_survey':
            $id = (string)($_POST['survey_id'] ?? '');
            $copy = null;

            foreach ($data['surveys'] as $s) {
                if (($s['id'] ?? '') === $id) {
                    $copy = $s;
                    break;
                }
            }

            if (!$copy) {
                survey_api([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            $copy['id'] = survey_id();
            $copy['title'] = $copy['title'] . '（コピー）';
            $copy['status'] = 'draft';
            $copy['created_at'] = survey_now();
            $copy['updated_at'] = survey_now();
            $copy['deleted'] = false;

            foreach ($copy['groups'] as &$g) {
                $g['id'] = survey_id();

                foreach ($g['questions'] as &$q) {
                    $q['id'] = survey_id();
                }
                unset($q);
            }
            unset($g);

            $data['surveys'][] = $copy;
            survey_write_data($data);

            survey_api([
                'ok' => true,
                'survey' => $copy,
            ]);
            break;

        case 'toggle_status':
            $id = (string)($_POST['survey_id'] ?? '');
            $status = (string)($_POST['status'] ?? '');

            if (!in_array($status, ['draft', 'active', 'ended'], true)) {
                survey_api([
                    'ok' => false,
                    'message' => 'ステータスが不正です。',
                ], 400);
            }

            foreach ($data['surveys'] as &$s) {
                if (($s['id'] ?? '') === $id) {
                    $s['status'] = $status;
                    $s['updated_at'] = survey_now();
                }
            }
            unset($s);

            survey_write_data($data);

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
                if (isset($settings[$key])) {
                    $current[$key] = trim((string)$settings[$key]);
                }
            }

            if (isset($settings['password']) &&
                (string)$settings['password'] !== '') {
                $current['password'] = (string)$settings['password'];
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
                    ? array_values($settings['field_address'])
                    : [];

            $data['settings'] = $current;

            survey_write_data($data);

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

            $r = survey_kintone_request($settings);
            $fields = survey_kintone_fields($r);

            survey_api([
                'ok' => $fields['ok'],
                'status' => $r['status'],
                'url' => $r['url'],
                'proxy_used' => $r['proxy_used'],
                'message' => $fields['message'],
                'fields' => $fields['fields'],
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

            $subject = (string)($_POST['mail_subject'] ?? '');
            $body = (string)($_POST['mail_body'] ?? '');
            $template = (string)($_POST['template_type'] ?? 'initial');

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
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            $sent = 0;
            $failed = 0;

            foreach ($data['customers'] as &$customer) {
                if (!in_array($customer['id'] ?? '', $ids, true)) {
                    continue;
                }

                $url =
                    rtrim(
                        (
                            (isset($_SERVER['HTTPS']) &&
                             $_SERVER['HTTPS'] !== 'off')
                                ? 'https://'
                                : 'http://'
                        ) .
                        ($_SERVER['HTTP_HOST'] ?? ''),
                        '/'
                    ) .
                    $_SERVER['PHP_SELF'] .
                    '?survey=' .
                    rawurlencode($surveyId) .
                    '&customer=' .
                    rawurlencode((string)$customer['id']);

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
                    $customer['answer_status'] = 'unanswered';
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
                'operator' => (string)($_SESSION['user'] ?? 'admin'),
            ];

            survey_write_data($data);

            survey_api([
                'ok' => true,
                'sent' => $sent,
                'failed' => $failed,
                'message' => "{$sent}件送信しました。",
            ]);
            break;

        case 'mark_kintone':
            $id = (string)($_POST['customer_id'] ?? '');

            foreach ($data['customers'] as &$customer) {
                if (($customer['id'] ?? '') === $id) {
                    $customer['kintone_status'] = 'registered';
                }
            }
            unset($customer);

            survey_write_data($data);

            survey_api([
                'ok' => true,
                'message' => 'kintone登録済みに変更しました。',
            ]);
            break;

        case 'submit_response':
            $surveyId = (string)($_POST['survey_id'] ?? '');
            $customerId = (string)($_POST['customer_id'] ?? '');
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

            if (!$survey) {
                survey_api([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            $response = [
                'id' => survey_id(),
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'company' => (string)($customer['company'] ?? ''),
                'name' => (string)($customer['name'] ?? ''),
                'email' => (string)($customer['email'] ?? ''),
                'answered_at' => survey_now(),
                'answers' => $answers,
            ];

            $data['responses'][] = $response;

            foreach ($data['customers'] as &$c) {
                if (($c['id'] ?? '') === $customerId) {
                    $c['answer_status'] = 'answered';
                }
            }
            unset($c);

            survey_write_data($data);

            survey_api([
                'ok' => true,
                'message' => '回答を送信しました。',
            ]);
            break;

        case 'csv':
            survey_csv_download(
                $data,
                (string)($_GET['survey_id'] ?? '')
            );
            break;

        default:
            survey_api([
                'ok' => false,
                'message' => '不明なactionです。',
            ], 400);
    }
}

$csrf = survey_token();

/* =========================================================
 * 回答者公開フォーム
 * ========================================================= */

$publicSurveyId = (string)($_GET['survey'] ?? '');
$publicCustomerId = (string)($_GET['customer'] ?? '');

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

    if ($survey && ($survey['status'] ?? '') === 'active') {
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
<div id="public_app" class="max-w-3xl mx-auto p-6">
<div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-8">
<h1 class="text-2xl font-bold mb-2"><?= survey_h($survey['title']) ?></h1>
<p class="text-sm text-slate-500 mb-8">
<?= survey_h($customer['name'] ?? '') ?> 様
</p>

<form id="public_form" class="space-y-8">
<?php
$qno = 0;
foreach ($survey['groups'] ?? [] as $group):
?>
<section class="border-t border-slate-200 pt-6">
<h2 class="text-lg font-bold mb-5"><?= survey_h($group['name'] ?? '') ?></h2>
<?php
foreach ($group['questions'] ?? [] as $q):
    $qno++;
    $qid = (string)($q['id'] ?? '');
    $required = !empty($q['required']);
?>
<div class="mb-7">
<label class="block font-semibold mb-3">
<span class="text-slate-500 mr-2">Q<?= $qno ?></span>
<?= survey_h($q['text'] ?? '') ?>
<?php if ($required): ?>
<span class="text-red-500 text-xs ml-2">必須</span>
<?php endif; ?>
</label>

<?php if (($q['type'] ?? '') === 'text'): ?>
<textarea
name="answers[<?= survey_h($qid) ?>]"
rows="5"
class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500"
<?= $required ? 'required' : '' ?>
></textarea>
<?php else: ?>
<div class="space-y-2">
<?php foreach (($q['options'] ?? []) as $oi => $option): ?>
<label class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-50">
<input
class="w-5 h-5"
type="<?= ($q['type'] ?? '') === 'multiple' ? 'checkbox' : 'radio' ?>"
name="answers[<?= survey_h($qid) ?>]<?= ($q['type'] ?? '') === 'multiple' ? '[]' : '' ?>"
value="<?= survey_h($option) ?>"
<?= ($required && $oi === 0) ? 'required' : '' ?>
>
<span><?= survey_h($option) ?></span>
</label>
<?php endforeach; ?>
<?php if (!empty($q['other_enabled'])): ?>
<label class="flex items-center gap-3 p-3 rounded-xl">
<input
type="<?= ($q['type'] ?? '') === 'multiple' ? 'checkbox' : 'radio' ?>"
name="answers[<?= survey_h($qid) ?>]<?= ($q['type'] ?? '') === 'multiple' ? '[]' : '' ?>"
value="その他"
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

<input type="hidden" name="survey_id" value="<?= survey_h($publicSurveyId) ?>">
<input type="hidden" name="customer_id" value="<?= survey_h($publicCustomerId) ?>">
<input type="hidden" name="action" value="submit_response">

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
document.getElementById('public_form').addEventListener('submit',async function(e){
e.preventDefault();
if(!confirm('回答を送信します。よろしいですか？'))return;
const fd=new FormData(this);
const answers={};
for(const [k,v] of fd.entries()){
 if(k.startsWith('answers[')){
  const m=k.match(/^answers\[([^\]]+)\](\[\])?$/);
  if(!m)continue;
  if(m[2]){
   if(!answers[m[1]])answers[m[1]]=[];
   answers[m[1]].push(v);
  }else answers[m[1]]=v;
 }
}
fd.set('answers',JSON.stringify(answers));
const r=await fetch(location.href,{method:'POST',body:fd});
const j=await r.json();
if(j.ok){
 document.getElementById('public_app').innerHTML=
 '<div class="bg-white rounded-2xl shadow-sm border p-10 text-center">'+
 '<div class="text-green-600 text-4xl mb-4">✓</div>'+
 '<h1 class="text-2xl font-bold mb-3">回答を受け付けました</h1>'+
 '<p class="text-slate-500">ご回答ありがとうございました。</p></div>';
}else alert(j.message||'送信に失敗しました。');
});
</script>
</body>
</html>
<?php
        exit;
    }
}

/* =========================================================
 * 管理SPA
 * ========================================================= */
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

<meta name="csrf-token" content="<?= survey_h($csrf) ?>">
</head>

<body class="bg-slate-50 text-slate-800 min-h-screen">

<div id="app"></div>

<script>
window.App={
State:{
 data:null,
 page:'list',
 survey:null,
 filter:{keyword:'',status:'',sort:'updated_desc'},
 selectedQuestions:{},
 selectedCustomerIds:[],
 editing:false,
 dirty:false,
 fields:[],
 fieldMessage:'',
 responseModal:null,
 preview:false,
 mobilePreview:false
},

Util:{
 h(v){
  return String(v??'')
   .replace(/&/g,'&amp;')
   .replace(/</g,'&lt;')
   .replace(/>/g,'&gt;')
   .replace(/"/g,'&quot;')
   .replace(/'/g,'&#039;');
 },
 id(){
  if(window.crypto&&crypto.randomUUID)return crypto.randomUUID().replaceAll('-','');
  return Date.now().toString(36)+Math.random().toString(36).slice(2);
 },
 esc(v){return this.h(v)},
 json(v){return JSON.stringify(v).replace(/</g,'\\u003c')},
 status(s){
  return s==='active'?'公開中':s==='ended'?'終了':'下書き';
 },
 type(t){
  return t==='single'?'単一選択':t==='multiple'?'複数選択':'自由記述';
 },
 clone(v){return JSON.parse(JSON.stringify(v))}
},

API:{
 async request(action,extra={}){
  const fd=new FormData();
  fd.set('action',action);
  fd.set('csrf_token',document.getElementById('csrf_token')?.value||
    document.querySelector('meta[name="csrf-token"]')?.content||'');
  Object.entries(extra).forEach(([k,v])=>{
   fd.set(k,typeof v==='string'?v:JSON.stringify(v));
  });
  const r=await fetch(location.href,{method:'POST',body:fd});
  const text=await r.text();
  let j;
  try{j=JSON.parse(text)}catch(e){
   throw new Error(text||'サーバーから不正な応答が返りました。');
  }
  return j;
 },

 async load(){
  const j=await this.request('get_data');
  if(!j.ok)throw new Error(j.message);
  App.State.data=j.data;
  const csrf=document.getElementById('csrf_token');
  if(csrf)csrf.value=j.csrf_token||'';
 },

 async saveSurvey(s){
  const j=await this.request('save_survey',{survey_json:App.Util.json(s)});
  if(!j.ok)throw new Error(j.message);
  await this.load();
  return j;
 },

 async settings(s){
  const j=await this.request('save_settings',{settings_json:App.Util.json(s)});
  if(!j.ok)throw new Error(j.message);
  await this.load();
  return j;
 }
},

Render:{
 root(){
  const s=App.State;
  const titles={
   list:'アンケート一覧',
   editor:s.survey?.id?'アンケート編集':'新規アンケート作成',
   aggregate:'回答集計・分析',
   mail:'顧客選択・メール送信',
   settings:'kintone連携設定'
  };

  document.getElementById('app').innerHTML=`
  <div class="min-h-screen">
   <header class="sticky top-0 z-30 bg-white border-b border-slate-200 shadow-sm">
    <div class="max-w-7xl mx-auto px-5 py-4 flex items-center justify-between gap-4">
     <button class="font-bold text-lg" onclick="App.actions.home()">アンケート管理</button>
     <nav class="flex gap-2 flex-wrap">
      <button class="px-3 py-2 rounded-lg hover:bg-slate-100 text-sm"
       onclick="App.actions.home()">アンケート一覧</button>
      <button class="px-3 py-2 rounded-lg hover:bg-slate-100 text-sm"
       onclick="App.actions.settings()">キントーン連携設定</button>
      <button class="px-3 py-2 rounded-lg hover:bg-slate-100 text-sm"
       onclick="App.actions.logout()">ログアウト</button>
     </nav>
    </div>
   </header>
   <main class="max-w-7xl mx-auto px-5 py-7">
    <div class="mb-6">
     <div class="text-sm text-slate-500">ホーム</div>
     <h1 class="text-2xl font-bold mt-1">${App.Util.h(titles[s.page]||'')}</h1>
    </div>
    <div id="view"></div>
   </main>
  </div>`;
 },

 list(){
  const s=App.State;
  let surveys=(s.data?.surveys||[]).filter(x=>!x.deleted);

  const kw=s.filter.keyword.toLowerCase();
  if(kw)surveys=surveys.filter(x=>
   String(x.title||'').toLowerCase().includes(kw));

  if(s.filter.status)
   surveys=surveys.filter(x=>x.status===s.filter.status);

  surveys.sort((a,b)=>{
   if(s.filter.sort==='updated_asc')
    return String(a.updated_at).localeCompare(String(b.updated_at));
   if(s.filter.sort==='answers_desc')
    return App.answersCount(b)-App.answersCount(a);
   if(s.filter.sort==='answers_asc')
    return App.answersCount(a)-App.answersCount(b);
   if(s.filter.sort==='start_desc')
    return String(b.start_at||'').localeCompare(String(a.start_at||''));
   if(s.filter.sort==='start_asc')
    return String(a.start_at||'').localeCompare(String(b.start_at||''));
   return String(b.updated_at).localeCompare(String(a.updated_at));
  });

  document.getElementById('view').innerHTML=`
  <div class="flex justify-between items-center gap-4 mb-5">
   <div class="flex gap-2 flex-wrap">
    <input value="${App.Util.h(s.filter.keyword)}"
     placeholder="タイトル検索"
     onkeydown="if(event.key==='Enter')App.actions.keyword(this.value)"
     class="w-64 border rounded-xl px-4 py-2 bg-white">
    <select onchange="App.actions.statusFilter(this.value)"
     class="border rounded-xl px-3 py-2 bg-white">
     <option value="">すべて</option>
     <option value="active" ${s.filter.status==='active'?'selected':''}>公開中</option>
     <option value="draft" ${s.filter.status==='draft'?'selected':''}>下書き</option>
     <option value="ended" ${s.filter.status==='ended'?'selected':''}>終了</option>
    </select>
    <select onchange="App.actions.sort(this.value)"
     class="border rounded-xl px-3 py-2 bg-white">
     <option value="updated_desc">更新日 新しい順</option>
     <option value="updated_asc">更新日 古い順</option>
     <option value="answers_desc">回答数 多い順</option>
     <option value="answers_asc">回答数 少ない順</option>
     <option value="start_desc">開始日 新しい順</option>
     <option value="start_asc">開始日 古い順</option>
    </select>
   </div>
   <button onclick="App.actions.newSurvey()"
    class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-3 rounded-xl font-semibold">
    ＋ 新規アンケート作成
   </button>
  </div>

  <div class="bg-white rounded-2xl border border-slate-200 overflow-x-auto">
   <table class="w-full text-sm">
    <thead class="bg-slate-50 border-b">
     <tr>
      <th class="text-left p-4">作成日 / 更新日</th>
      <th class="text-left p-4">タイトル</th>
      <th class="text-left p-4">期間</th>
      <th class="text-left p-4">ステータス</th>
      <th class="text-right p-4">回答数</th>
      <th class="text-left p-4">操作</th>
     </tr>
    </thead>
    <tbody>
    ${surveys.map(x=>App.Render.surveyRow(x)).join('')}
    </tbody>
   </table>
   ${surveys.length?'':`
    <div class="p-12 text-center text-slate-500">
     アンケートがありません。
    </div>`}
  </div>`;
 },

 surveyRow(x){
  const badge=x.status==='active'
   ?'bg-green-100 text-green-700'
   :x.status==='ended'
   ?'bg-slate-200 text-slate-600'
   :'bg-amber-100 text-amber-700';

  return `<tr class="border-b last:border-0">
   <td class="p-4 whitespace-nowrap">
    ${App.Util.h(x.created_at||'')}<br>
    <span class="text-slate-500">更新: ${App.Util.h(x.updated_at||'')}</span>
   </td>
   <td class="p-4 font-bold">${App.Util.h(x.title)}</td>
   <td class="p-4 whitespace-nowrap">
    ${App.Util.h(x.start_at||'未設定')}
    ～ ${App.Util.h(x.end_at||'未設定')}
   </td>
   <td class="p-4"><span class="px-2.5 py-1 rounded-full text-xs ${badge}">
    ${App.Util.status(x.status)}</span></td>
   <td class="p-4 text-right">${App.answersCount(x)} 件</td>
   <td class="p-4">
    <div class="flex flex-wrap gap-2">
     <button class="px-3 py-1.5 rounded-lg bg-slate-100"
      onclick="App.actions.edit('${x.id}')">確認・編集</button>
     ${x.status!=='draft'?`
     <button class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700"
      onclick="App.actions.aggregate('${x.id}')">集計</button>
     <button class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700"
      onclick="App.actions.mail('${x.id}')">送信</button>`:''}
     ${x.status==='active'?`
     <button class="px-3 py-1.5 rounded-lg bg-red-50 text-red-700"
      onclick="App.actions.toggle('${x.id}','ended')">停止</button>`:''}
     ${x.status==='draft'?`
     <button class="px-3 py-1.5 rounded-lg bg-red-50 text-red-700"
      onclick="App.actions.delete('${x.id}')">削除</button>`:''}
     <button class="px-3 py-1.5 rounded-lg bg-slate-100"
      onclick="App.actions.duplicate('${x.id}')">複製</button>
    </div>
   </td>
  </tr>`;
 },

 editor(){
  const s=App.State.survey;
  const qn=s.groups.reduce((n,g)=>n+(g.questions||[]).length,0);

  document.getElementById('view').innerHTML=`
  <div class="flex justify-between gap-3 mb-5 flex-wrap">
   <div class="flex gap-2">
    <button onclick="App.actions.preview()"
     class="px-4 py-2 bg-white border rounded-xl">プレビュー</button>
    <button onclick="App.actions.saveEditor()"
     class="px-4 py-2 bg-blue-600 text-white rounded-xl">
     保存して一覧へ戻る
    </button>
    <button onclick="App.actions.cancelEditor()"
     class="px-4 py-2 bg-slate-200 rounded-xl">キャンセル</button>
   </div>
   <button onclick="App.actions.addGroup()"
    class="px-4 py-2 bg-slate-900 text-white rounded-xl">
    ＋ グループ追加
   </button>
  </div>

  <div class="bg-white rounded-2xl border p-6 mb-5">
   <div class="grid md:grid-cols-4 gap-4">
    <label class="md:col-span-2">
     <span class="text-sm font-semibold">タイトル</span>
     <input id="survey_title" value="${App.Util.h(s.title)}"
      onchange="App.actions.field('title',this.value)"
      class="mt-1 w-full border rounded-xl px-4 py-2">
    </label>
    <label>
     <span class="text-sm font-semibold">開始日時</span>
     <input id="survey_start_at" type="datetime-local"
      value="${App.Util.h(s.start_at)}"
      onchange="App.actions.field('start_at',this.value)"
      class="mt-1 w-full border rounded-xl px-3 py-2">
    </label>
    <label>
     <span class="text-sm font-semibold">終了日時</span>
     <input id="survey_end_at" type="datetime-local"
      value="${App.Util.h(s.end_at)}"
      onchange="App.actions.field('end_at',this.value)"
      class="mt-1 w-full border rounded-xl px-3 py-2">
    </label>
   </div>
   <div class="mt-4">
    <label class="text-sm font-semibold">質問番号</label>
    <select id="survey_numbering_mode"
     onchange="App.actions.field('numbering_mode',this.value)"
     class="ml-3 border rounded-lg px-3 py-2">
     <option value="global" ${s.numbering_mode==='global'?'selected':''}>Q1, Q2...</option>
     <option value="group" ${s.numbering_mode==='group'?'selected':''}>Q1-1, Q1-2...</option>
    </select>
   </div>
  </div>

  <div id="question_editor" class="space-y-5">
  ${s.groups.map((g,gi)=>App.Render.group(g,gi)).join('')}
  </div>
  <div class="mt-4 text-sm text-slate-500">質問数: ${qn}</div>`;
  App.actions.sortable();
 },

 group(g,gi){
  return `<section class="group-item bg-white rounded-2xl border p-5"
   data-group-id="${App.Util.h(g.id)}">
   <div class="flex gap-3 items-center mb-5">
    <span class="cursor-move text-slate-400 text-xl">⠿</span>
    <input value="${App.Util.h(g.name)}"
     onchange="App.actions.groupField('${g.id}','name',this.value)"
     class="flex-1 text-lg font-bold border-0 border-b focus:ring-0">
    <button onclick="App.actions.deleteGroup('${g.id}')"
     class="text-red-500 px-3 py-2">削除</button>
    <button onclick="App.actions.addQuestion('${g.id}')"
     class="bg-blue-600 text-white px-3 py-2 rounded-lg">＋質問</button>
   </div>
   <div class="question-list space-y-4" data-group-id="${App.Util.h(g.id)}">
   ${(g.questions||[]).map((q,qi)=>App.Render.question(q,gi,qi)).join('')}
   </div>
  </section>`;
 },

 question(q,gi,qi){
  return `<div class="question-item border border-slate-200 rounded-xl p-4"
   data-question-id="${App.Util.h(q.id)}">
   <div class="flex gap-3">
    <span class="cursor-move text-slate-400 pt-2">⠿</span>
    <div class="flex-1">
     <div class="flex justify-between gap-3 mb-3">
      <span class="font-bold text-blue-600" data-qnumber="${q.id}"></span>
      <button onclick="App.actions.deleteQuestion('${q.id}')"
       class="text-red-500 text-sm">削除</button>
     </div>
     <input value="${App.Util.h(q.text)}"
      onchange="App.actions.questionField('${q.id}','text',this.value)"
      placeholder="質問文"
      class="w-full border rounded-lg px-3 py-2 mb-3">
     <div class="flex gap-3 flex-wrap">
      <select onchange="App.actions.questionField('${q.id}','type',this.value)"
       class="border rounded-lg px-3 py-2">
       <option value="single" ${q.type==='single'?'selected':''}>単一選択</option>
       <option value="multiple" ${q.type==='multiple'?'selected':''}>複数選択</option>
       <option value="text" ${q.type==='text'?'selected':''}>自由記述</option>
      </select>
      <label class="flex items-center gap-2">
       <input type="checkbox" ${q.required?'checked':''}
        onchange="App.actions.questionField('${q.id}','required',this.checked)">
       必須回答
      </label>
      ${q.type!=='text'?`
      <label class="flex items-center gap-2">
       <input type="checkbox" ${q.other_enabled?'checked':''}
        onchange="App.actions.questionField('${q.id}','other_enabled',this.checked)">
       その他
      </label>`:''}
     </div>
     ${q.type!=='text'?`
     <div class="mt-4 space-y-2">
      ${(q.options||[]).map((o,oi)=>`
       <div class="flex gap-2">
        <input value="${App.Util.h(o)}"
         onchange="App.actions.optionField('${q.id}',${oi},this.value)"
         class="flex-1 border rounded-lg px-3 py-2">
        <button onclick="App.actions.removeOption('${q.id}',${oi})"
         class="px-3 text-red-500">×</button>
       </div>`).join('')}
      <button onclick="App.actions.addOption('${q.id}')"
       class="text-blue-600 text-sm">＋ 選択肢追加</button>
     </div>`:''}
    </div>
   </div>
  </div>`;
 },

 aggregate(){
  const s=App.State.survey;
  const responses=(App.State.data.responses||[])
   .filter(x=>x.survey_id===s.id);

  const questions=[];
  s.groups.forEach(g=>g.questions.forEach(q=>questions.push(q)));

  document.getElementById('view').innerHTML=`
  <div class="grid md:grid-cols-5 gap-3 mb-6">
   ${[
    ['送信対象者数',App.targetCount(s.id)],
    ['回答数',responses.length],
    ['未登録顧客からの回答数',
     responses.filter(r=>!r.customer_id).length],
    ['未回答数',Math.max(0,App.targetCount(s.id)-responses.length)],
    ['回答率',
     App.targetCount(s.id)?
      ((responses.length/App.targetCount(s.id))*100).toFixed(1)+' %':'0.0 %']
   ].map(x=>`<div class="bg-white border rounded-2xl p-5">
    <div class="text-sm text-slate-500">${x[0]}</div>
    <div class="text-2xl font-bold mt-2">${x[1]}</div>
   </div>`).join('')}
  </div>

  <div class="bg-white border rounded-2xl p-5 mb-6">
   <div class="flex justify-between mb-4">
    <h2 class="font-bold">設問別集計</h2>
    <div class="flex gap-2">
     <button onclick="App.actions.selectQuestions(true)"
      class="text-sm text-blue-600">一括選択</button>
     <button onclick="App.actions.selectQuestions(false)"
      class="text-sm text-slate-500">全解除</button>
    </div>
   </div>
   <div class="space-y-2">
   ${questions.map(q=>`
    <label class="flex gap-3 items-center p-3 rounded-lg hover:bg-slate-50">
     <input type="checkbox"
      ${App.State.selectedQuestions[q.id]!==false?'checked':''}
      onchange="App.actions.questionFilter('${q.id}',this.checked)">
     <span>${App.Util.h(q.text)}</span>
     <span class="ml-auto text-xs bg-slate-100 px-2 py-1 rounded">
      ${App.Util.type(q.type)}
     </span>
    </label>`).join('')}
   </div>
  </div>

  <div class="space-y-5">
  ${questions.filter(q=>App.State.selectedQuestions[q.id]!==false)
    .map(q=>App.Render.questionStats(q,responses)).join('')}
  </div>

  <div class="bg-white border rounded-2xl p-5 mt-6">
   <div class="flex justify-between mb-4">
    <h2 class="font-bold">個別回答一覧</h2>
    <button onclick="App.actions.csv('${s.id}')"
     class="bg-slate-900 text-white rounded-lg px-4 py-2">
     CSV出力
    </button>
   </div>
   <input id="response_filter"
    oninput="App.actions.responseFilter(this.value)"
    placeholder="会社名・氏名検索"
    class="border rounded-xl px-4 py-2 mb-4 w-full">
   <div id="response_table">
    ${App.Render.responseTable(responses)}
   </div>
  </div>`;
 },

 questionStats(q,responses){
  if(q.type==='text'){
   return `<div class="bg-white border rounded-2xl p-5">
    <h3 class="font-bold mb-4">${App.Util.h(q.text)}</h3>
    <div class="space-y-3 max-h-80 overflow-auto">
    ${responses.map(r=>{
     const v=r.answers?.[q.id]??'';
     return v?`<div class="border-l-4 border-blue-500 pl-4">
      <div class="text-sm text-slate-500">${App.Util.h(r.company)} ${App.Util.h(r.name)}</div>
      <div>${App.Util.h(Array.isArray(v)?v.join('、'):v)}</div>
     </div>`:'';
    }).join('')||'<div class="text-slate-400">回答はありません。</div>'}
    </div>
   </div>`;
  }

  const counts={};
  (q.options||[]).forEach(o=>counts[o]=0);

  responses.forEach(r=>{
   let v=r.answers?.[q.id];
   if(!Array.isArray(v))v=[v];
   v.forEach(x=>{
    if(x!==undefined&&counts[x]!==undefined)counts[x]++;
   });
  });

  const total=responses.length||1;

  return `<div class="bg-white border rounded-2xl p-5">
   <h3 class="font-bold mb-5">${App.Util.h(q.text)}</h3>
   <div class="space-y-4">
   ${Object.entries(counts).map(([o,n])=>{
    const p=(n/total*100).toFixed(1);
    return `<div>
     <div class="flex justify-between text-sm mb-1">
      <span>${App.Util.h(o)}</span><span>${n}件 / ${p}%</span>
     </div>
     <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
      <div class="h-full bg-blue-500" style="width:${p}%"></div>
     </div>
    </div>`;
   }).join('')}
   </div>
  </div>`;
 },

 responseTable(responses){
  const kw=(App.State.responseKeyword||'').toLowerCase();

  const list=responses.filter(r=>
   !kw ||
   String(r.company||'').toLowerCase().includes(kw)||
   String(r.name||'').toLowerCase().includes(kw));

  return `<div class="overflow-x-auto">
  <table class="w-full text-sm">
   <thead><tr class="border-b text-left">
    <th class="p-3">会社名</th>
    <th class="p-3">氏名</th>
    <th class="p-3">回答日時</th>
    <th class="p-3">操作</th>
   </tr></thead>
   <tbody>
   ${list.map(r=>`<tr class="border-b">
    <td class="p-3">${App.Util.h(r.company)}</td>
    <td class="p-3">${App.Util.h(r.name)}</td>
    <td class="p-3">${App.Util.h(r.answered_at)}</td>
    <td class="p-3">
     <button onclick="App.actions.response('${r.id}')"
      class="text-blue-600">全回答を表示</button>
    </td>
   </tr>`).join('')}
   </tbody>
  </table>
  </div>`;
 },

 mail(){
  const s=App.State.survey;
  const customers=App.State.data.customers||[];

  document.getElementById('view').innerHTML=`
  <div class="grid lg:grid-cols-2 gap-5">
   <div class="bg-white border rounded-2xl p-5">
    <div class="flex justify-between mb-4">
     <h2 class="font-bold">顧客選択</h2>
     <label class="text-sm flex gap-2">
      <input id="select_all" type="checkbox"
       onchange="App.actions.selectAll(this.checked)">
      全選択
     </label>
    </div>
    <input id="customer_filter"
     oninput="App.actions.customerFilter(this.value)"
     placeholder="顧客名・メールアドレス検索"
     class="w-full border rounded-xl px-4 py-2 mb-4">
    <div id="customer_table" class="space-y-2 max-h-[600px] overflow-auto">
     ${App.Render.customers(customers)}
    </div>
   </div>

   <div class="bg-white border rounded-2xl p-5">
    <h2 class="font-bold mb-4">メール送信</h2>
    <select id="template_type"
     class="border rounded-xl px-3 py-2 mb-3 w-full">
     <option value="initial">初回</option>
     <option value="reminder">リマインド</option>
    </select>
    <input id="mail_subject"
     value="アンケートご協力のお願い"
     class="w-full border rounded-xl px-4 py-2 mb-3"
     placeholder="件名">
    <textarea id="mail_body" rows="12"
     class="w-full border rounded-xl px-4 py-3 mb-4"> {顧客名} 様

アンケートへのご協力をお願いいたします。

回答URL：
{アンケートURL}

よろしくお願いいたします。</textarea>
    <button onclick="App.actions.sendMail('${s.id}')"
     class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-3 font-semibold">
     一括送信実行
    </button>
   </div>
  </div>`;
 },

 customers(customers){
  const kw=(App.State.customerKeyword||'').toLowerCase();

  return customers.filter(c=>
   !kw||
   String(c.name||'').toLowerCase().includes(kw)||
   String(c.email||'').toLowerCase().includes(kw)
  ).map(c=>{
   const disabled=c.source==='web';
   return `<label class="block border rounded-xl p-4 ${disabled?'bg-slate-50':''}">
    <div class="flex gap-3">
     <input type="checkbox"
      ${disabled?'disabled':''}
      ${App.State.selectedCustomerIds.includes(c.id)?'checked':''}
      onchange="App.actions.customerSelect('${c.id}',this.checked)">
     <div class="flex-1">
      <div class="font-bold">${App.Util.h(c.company)}</div>
      <div>${App.Util.h(c.name)}</div>
      <div class="text-sm text-slate-500">${App.Util.h(c.email)}</div>
     </div>
     <div class="text-xs">
      <span class="px-2 py-1 rounded-full ${
       c.answer_status==='answered'
       ?'bg-green-100 text-green-700'
       :'bg-amber-100 text-amber-700'
      }">
       ${c.answer_status==='answered'?'回答済み':'未回答'}
      </span>
     </div>
    </div>
   </label>`;
  }).join('')||'<div class="text-slate-400">顧客がありません。</div>';
 },

 settings(){
  const s=App.State.data.settings||{};

  document.getElementById('view').innerHTML=`
  <form id="settings_form" class="bg-white border rounded-2xl p-6 max-w-3xl">
   <div class="grid md:grid-cols-2 gap-5">
    <label>
     <span class="font-semibold text-sm">サブドメイン</span>
     <input id="setting_subdomain"
      value="${App.Util.h(s.subdomain)}"
      placeholder="xxxx.cybozu.com"
      class="w-full border rounded-xl px-4 py-2 mt-1">
    </label>
    <label>
     <span class="font-semibold text-sm">アプリID</span>
     <input id="setting_app_id"
      value="${App.Util.h(s.app_id)}"
      class="w-full border rounded-xl px-4 py-2 mt-1">
    </label>
    <label>
     <span class="font-semibold text-sm">ログイン名</span>
     <input id="setting_login_name"
      value="${App.Util.h(s.login_name)}"
      autocomplete="username"
      class="w-full border rounded-xl px-4 py-2 mt-1">
    </label>
    <label>
     <span class="font-semibold text-sm">パスワード</span>
     <input id="setting_password"
      type="password"
      autocomplete="new-password"
      class="w-full border rounded-xl px-4 py-2 mt-1"
      placeholder="変更する場合のみ入力">
    </label>
    <label class="md:col-span-2">
     <span class="font-semibold text-sm">Proxy</span>
     <input id="setting_proxy"
      value="${App.Util.h(s.proxy)}"
      placeholder="host:port / http://host:port / https://host:port"
      class="w-full border rounded-xl px-4 py-2 mt-1">
    </label>
   </div>

   <label class="flex gap-2 mt-5">
    <input id="setting_ssl_verify"
     type="checkbox" ${s.ssl_verify?'checked':''}>
    SSL証明書を検証する
   </label>

   <div class="mt-7 border-t pt-6">
    <div class="flex justify-between items-center mb-4">
     <h2 class="font-bold">kintoneフィールドマッピング</h2>
     <button type="button"
      onclick="App.actions.fetchKintoneFields()"
      class="px-4 py-2 bg-slate-900 text-white rounded-xl">
      項目一覧を再取得
     </button>
    </div>

    <div id="field_message" class="mb-4 whitespace-pre-line text-sm"></div>
    <div id="field_mapping" class="grid md:grid-cols-2 gap-4">
     ${App.Render.mapping(s)}
    </div>
   </div>

   <input type="hidden" id="settings_json">

   <div class="flex gap-3 mt-7">
    <button type="button"
     onclick="App.actions.testKintone()"
     class="px-5 py-3 bg-slate-100 rounded-xl">
     接続確認
    </button>
    <button type="button"
     onclick="App.actions.saveSettings()"
     class="px-5 py-3 bg-blue-600 text-white rounded-xl">
     設定を保存
    </button>
   </div>
  </form>`;
 },

 mapping(s){
  const names=[
   ['field_company','会社名'],
   ['field_name','氏名'],
   ['field_email','メールアドレス'],
   ['field_department','部署名'],
   ['field_phone','電話番号']
  ];

  return names.map(([key,label])=>`
   <label>
    <span class="text-sm font-semibold">${label}</span>
    <select id="${key}" class="mt-1 w-full border rounded-xl px-3 py-2">
     <option value="">未設定</option>
     ${App.State.fields.map(f=>`
      <option value="${App.Util.h(f.code)}"
       ${s[key]===f.code?'selected':''}>
       ${App.Util.h(f.label)} (${App.Util.h(f.code)})
      </option>`).join('')}
    </select>
   </label>`).join('')+
   `<label>
    <span class="text-sm font-semibold">住所（複数可）</span>
    <select id="field_address" multiple
     class="mt-1 w-full border rounded-xl px-3 py-2 h-32">
     ${App.State.fields.map(f=>`
      <option value="${App.Util.h(f.code)}"
       ${(s.field_address||[]).includes(f.code)?'selected':''}>
       ${App.Util.h(f.label)} (${App.Util.h(f.code)})
      </option>`).join('')}
    </select>
   </label>`;
 },

 responseModal(){
  const r=App.State.responseModal;
  if(!r)return;

  const modal=document.createElement('div');
  modal.id='response_modal';
  modal.className='fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-5';
  modal.innerHTML=`
  <div class="bg-white rounded-2xl max-w-3xl w-full max-h-[85vh] overflow-auto p-6">
   <div class="flex justify-between mb-5">
    <h2 class="font-bold text-xl">全回答</h2>
    <button onclick="App.actions.closeModal()" class="text-2xl">×</button>
   </div>
   <div id="response_detail" class="space-y-4">
   ${Object.entries(r.answers||{}).map(([qid,v])=>`
    <div class="border rounded-xl p-4">
     <div class="font-semibold mb-2">${App.Util.h(App.questionText(qid))}</div>
     <div>${App.Util.h(Array.isArray(v)?v.join('、'):v)}</div>
    </div>`).join('')}
   </div>
  </div>`;
  document.body.appendChild(modal);
 }
},

answersCount(x){
 return (App.State.data?.responses||[])
  .filter(r=>r.survey_id===x.id).length;
},

targetCount(id){
 return (App.State.data?.customers||[])
  .filter(c=>c.source!=='web').length;
},

questionText(id){
 const s=App.State.survey;
 for(const g of s.groups||[])
  for(const q of g.questions||[])
   if(q.id===id)return q.text||'';
 return '';
},

actions:{
 async home(){
  App.State.page='list';
  App.State.survey=null;
  App.Render.root();
  App.Render.list();
 },

 async init(){
  try{
   await App.API.load();
   App.Render.root();
   App.Render.list();
  }catch(e){
   document.getElementById('app').innerHTML=
    `<div class="p-10 text-red-600">${App.Util.h(e.message)}</div>`;
  }
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
  App.State.dirty=true;
  App.State.page='editor';
  App.Render.root();
  App.Render.editor();
 },

 edit(id){
  const s=App.State.data.surveys.find(x=>x.id===id);
  if(!s)return;
  App.State.survey=App.Util.clone(s);
  App.State.dirty=false;
  App.State.page='editor';
  App.Render.root();
  App.Render.editor();
 },

 field(key,value){
  App.State.survey[key]=value;
  App.State.dirty=true;
 },

 groupField(id,key,value){
  const g=App.State.survey.groups.find(x=>x.id===id);
  if(g)g[key]=value;
  App.State.dirty=true;
 },

 questionField(id,key,value){
  for(const g of App.State.survey.groups){
   const q=g.questions.find(x=>x.id===id);
   if(q){
    q[key]=value;
    if(key==='type'&&value==='text')q.options=[];
    if(key==='type'&&value!=='text'&&!q.options?.length)
     q.options=['選択肢1','選択肢2'];
   }
  }
  App.State.dirty=true;
  App.Render.editor();
 },

 optionField(id,index,value){
  for(const g of App.State.survey.groups){
   const q=g.questions.find(x=>x.id===id);
   if(q)q.options[index]=value;
  }
  App.State.dirty=true;
 },

 addOption(id){
  for(const g of App.State.survey.groups){
   const q=g.questions.find(x=>x.id===id);
   if(q){
    q.options=q.options||[];
    q.options.push('選択肢'+(q.options.length+1));
   }
  }
  App.State.dirty=true;
  App.Render.editor();
 },

 removeOption(id,index){
  for(const g of App.State.survey.groups){
   const q=g.questions.find(x=>x.id===id);
   if(q)q.options.splice(index,1);
  }
  App.State.dirty=true;
  App.Render.editor();
 },

 addGroup(){
  App.State.survey.groups.push({
   id:App.Util.id(),
   name:'新しいグループ',
   questions:[]
  });
  App.State.dirty=true;
  App.Render.editor();
 },

 deleteGroup(id){
  if(!confirm('グループと内包する質問を削除しますか？'))return;
  App.State.survey.groups=
   App.State.survey.groups.filter(g=>g.id!==id);
  App.State.dirty=true;
  App.Render.editor();
 },

 addQuestion(groupId){
  const g=App.State.survey.groups.find(x=>x.id===groupId);
  if(!g)return;

  g.questions.push({
   id:App.Util.id(),
   text:'',
   type:'single',
   required:false,
   options:['選択肢1','選択肢2'],
   other_enabled:false
  });

  App.State.dirty=true;
  App.Render.editor();
 },

 deleteQuestion(id){
  if(!confirm('この質問を削除しますか？'))return;

  for(const g of App.State.survey.groups)
   g.questions=g.questions.filter(q=>q.id!==id);

  App.State.dirty=true;
  App.Render.editor();
 },

 sortable(){
  const editor=document.getElementById('question_editor');
  if(!editor||typeof Sortable==='undefined')return;

  new Sortable(editor,{
   group:'survey-groups',
   handle:'.cursor-move',
   animation:150,
   ghostClass:'opacity-40',
   onEnd(){
    const ids=[...editor.querySelectorAll('.group-item')]
     .map(x=>x.dataset.groupId);
    App.State.survey.groups.sort(
     (a,b)=>ids.indexOf(a.id)-ids.indexOf(b.id)
    );
    App.State.dirty=true;
    App.actions.sortableQuestions();
    App.actions.renumber();
   }
  });

  App.actions.sortableQuestions();
  App.actions.renumber();
 },

 sortableQuestions(){
  document.querySelectorAll('.question-list').forEach(el=>{
   new Sortable(el,{
    group:'survey-questions',
    handle:'.cursor-move',
    animation:150,
    ghostClass:'opacity-40',
    onEnd(evt){
     const id=evt.item.dataset.questionId;
     let moved=null;

     for(const g of App.State.survey.groups){
      const i=g.questions.findIndex(q=>q.id===id);
      if(i>=0)moved=g.questions.splice(i,1)[0];
     }

     const target=App.State.survey.groups.find(
      g=>g.id===evt.to.dataset.groupId
     );

     if(target&&moved){
      target.questions.splice(evt.newIndex,0,moved);
     }

     App.State.dirty=true;
     App.Render.editor();
    }
   });
  });
 },

 renumber(){
  const mode=App.State.survey.numbering_mode;
  let global=0;

  App.State.survey.groups.forEach((g,gi)=>{
   g.questions.forEach((q,qi)=>{
    global++;
    const el=document.querySelector(
     `[data-qnumber="${CSS.escape(q.id)}"]`
    );
    if(el)
     el.textContent=mode==='group'
      ?`Q${gi+1}-${qi+1}`
      :`Q${global}`;
   });
  });
 },

 async saveEditor(){
  try{
   App.renumberData();
   await App.API.saveSurvey(App.State.survey);
   App.State.dirty=false;
   alert('保存しました。');
   App.actions.home();
  }catch(e){alert(e.message)}
 },

 renumberData(){
  let n=0;
  App.State.survey.groups.forEach((g,gi)=>{
   g.questions.forEach((q,qi)=>{
    n++;
    q.number=
     App.State.survey.numbering_mode==='group'
      ?`Q${gi+1}-${qi+1}`:`Q${n}`;
   });
  });
 },

 cancelEditor(){
  if(App.State.dirty&&!confirm('未保存の変更を破棄しますか？'))return;
  App.actions.home();
 },

 preview(){
  const s=App.State.survey;
  const modal=document.createElement('div');
  modal.id='preview_modal';
  modal.className='fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-5';
  modal.innerHTML=`
  <div class="bg-white rounded-2xl w-full max-w-3xl max-h-[90vh] overflow-auto">
   <div class="sticky top-0 bg-white border-b p-4 flex justify-between">
    <div class="font-bold">プレビュー</div>
    <button onclick="App.actions.closePreview()" class="text-xl">×</button>
   </div>
   <div id="preview_content" class="p-6">
    <h1 class="text-2xl font-bold mb-8">${App.Util.h(s.title)}</h1>
    ${s.groups.map((g,gi)=>`
     <section class="mb-8">
      <h2 class="text-xl font-bold mb-5">${App.Util.h(g.name)}</h2>
      ${g.questions.map((q,qi)=>`
       <div class="mb-6">
        <div class="font-semibold mb-2">
         ${App.Util.h(q.number||'Q')}
         ${App.Util.h(q.text)}
         ${q.required?'<span class="text-red-500 text-xs">必須</span>':''}
        </div>
        ${q.type==='text'
         ?'<textarea class="w-full border rounded-xl p-3" rows="4"></textarea>'
         :(q.options||[]).map(o=>
          `<label class="block p-2">
           <input type="${q.type==='multiple'?'checkbox':'radio'}"
            disabled> ${App.Util.h(o)}
          </label>`).join('')}
       </div>`).join('')}
     </section>`).join('')}
    <button onclick="alert('プレビューでは送信されません。')"
     class="w-full bg-blue-600 text-white rounded-xl py-3">
     回答を送信する
    </button>
   </div>
  </div>`;
  document.body.appendChild(modal);
 },

 closePreview(){
  document.getElementById('preview_modal')?.remove();
 },

 aggregate(id){
  const s=App.State.data.surveys.find(x=>x.id===id);
  if(!s)return;
  App.State.survey=App.Util.clone(s);
  App.State.page='aggregate';
  App.State.selectedQuestions={};
  (s.groups||[]).forEach(g=>
   (g.questions||[]).forEach(q=>
    App.State.selectedQuestions[q.id]=true));
  App.Render.root();
  App.Render.aggregate();
 },

 questionFilter(id,value){
  App.State.selectedQuestions[id]=value;
  App.Render.aggregate();
 },

 selectQuestions(value){
  App.State.survey.groups.forEach(g=>
   g.questions.forEach(q=>
    App.State.selectedQuestions[q.id]=value));
  App.Render.aggregate();
 },

 responseFilter(value){
  App.State.responseKeyword=value;
  const responses=(App.State.data.responses||[])
   .filter(r=>r.survey_id===App.State.survey.id);
  document.getElementById('response_table').innerHTML=
   App.Render.responseTable(responses);
 },

 response(id){
  const r=App.State.data.responses.find(x=>x.id===id);
  if(!r)return;
  App.State.responseModal=r;
  App.Render.responseModal();
 },

 closeModal(){
  document.getElementById('response_modal')?.remove();
  App.State.responseModal=null;
 },

 mail(id){
  const s=App.State.data.surveys.find(x=>x.id===id);
  if(!s)return;
  App.State.survey=App.Util.clone(s);
  App.State.page='mail';
  App.State.selectedCustomerIds=[];
  App.Render.root();
  App.Render.mail();
 },

 customerFilter(value){
  App.State.customerKeyword=value;
  document.getElementById('customer_table').innerHTML=
   App.Render.customers(App.State.data.customers||[]);
 },

 customerSelect(id,value){
  const a=App.State.selectedCustomerIds;
  if(value&&!a.includes(id))a.push(id);
  if(!value)App.State.selectedCustomerIds=
   a.filter(x=>x!==id);
 },

 selectAll(value){
  App.State.selectedCustomerIds=value
   ?(App.State.data.customers||[])
     .filter(c=>c.source!=='web').map(c=>c.id)
   :[];
  App.Render.mail();
 },

 async sendMail(surveyId){
  const ids=App.State.selectedCustomerIds;
  if(!ids.length){
   alert('送信先を選択してください。');
   return;
  }

  const already=App.State.data.customers.filter(c=>
   ids.includes(c.id)&&Number(c.send_count||0)>0);

  if(already.length&&!confirm(
   '既に送信済みの宛先が含まれています。再送しますか？'
  ))return;

  try{
   const j=await App.API.request('send_mail',{
    survey_id:surveyId,
    recipient_ids:App.Util.json(ids),
    mail_subject:document.getElementById('mail_subject').value,
    mail_body:document.getElementById('mail_body').value,
    template_type:document.getElementById('template_type').value
   });

   if(!j.ok)throw new Error(j.message);
   alert(j.message+` 成功:${j.sent}件 / 失敗:${j.failed}件`);
   await App.API.load();
   App.Render.mail();
  }catch(e){alert(e.message)}
 },

 async duplicate(id){
  if(!confirm('このアンケートを複製しますか？'))return;

  try{
   const j=await App.API.request('duplicate_survey',{survey_id:id});
   if(!j.ok)throw new Error(j.message);
   await App.API.load();
   App.Render.list();
  }catch(e){alert(e.message)}
 },

 async delete(id){
  if(!confirm('このアンケートを削除しますか？'))return;

  try{
   const j=await App.API.request('delete_survey',{survey_id:id});
   if(!j.ok)throw new Error(j.message);
   await App.API.load();
   App.Render.list();
  }catch(e){alert(e.message)}
 },

 async toggle(id,status){
  if(!confirm('ステータスを変更しますか？'))return;

  try{
   const j=await App.API.request('toggle_status',{
    survey_id:id,status
   });
   if(!j.ok)throw new Error(j.message);
   await App.API.load();
   App.Render.list();
  }catch(e){alert(e.message)}
 },

 keyword(v){
  App.State.filter.keyword=v;
  App.Render.list();
 },

 statusFilter(v){
  App.State.filter.status=v;
  App.Render.list();
 },

 sort(v){
  App.State.filter.sort=v;
  App.Render.list();
 },

 settings(){
  App.State.page='settings';
  App.Render.root();
  App.Render.settings();
 },

 settingsObject(){
  const old=App.State.data.settings||{};
  const address=document.getElementById('field_address');

  return {
   subdomain:document.getElementById('setting_subdomain').value.trim(),
   login_name:document.getElementById('setting_login_name').value.trim(),
   password:document.getElementById('setting_password').value||
    old.password||'',
   app_id:document.getElementById('setting_app_id').value.trim(),
   proxy:document.getElementById('setting_proxy').value.trim(),
   ssl_verify:document.getElementById('setting_ssl_verify').checked,
   field_company:document.getElementById('field_company')?.value||'',
   field_name:document.getElementById('field_name')?.value||'',
   field_email:document.getElementById('field_email')?.value||'',
   field_department:document.getElementById('field_department')?.value||'',
   field_phone:document.getElementById('field_phone')?.value||'',
   field_address:address
    ?[...address.selectedOptions].map(x=>x.value):[]
  };
 },

 async fetchKintoneFields(){
  const s=App.actions.settingsObject();

  const message=document.getElementById('field_message');
  message.textContent='kintoneから項目一覧を取得しています…';

  try{
   const j=await App.API.request('fetch_kintone_fields',{
    settings_json:App.Util.json(s)
   });

   if(!j.ok){
    message.className='mb-4 whitespace-pre-line text-sm text-red-600';
    message.textContent=j.message||
     `HTTPステータス: ${j.status||0}`;
    return;
   }

   App.State.fields=j.fields||[];
   App.State.fieldMessage=j.message||'';
   message.className='mb-4 whitespace-pre-line text-sm text-green-600';
   message.textContent=
    `${j.message}\nHTTPステータス: ${j.status}\n接続先: ${j.url}`;
   document.getElementById('field_mapping').innerHTML=
    App.Render.mapping(s);
  }catch(e){
   message.className='mb-4 whitespace-pre-line text-sm text-red-600';
   message.textContent=e.message;
  }
 },

 async testKintone(){
  const s=App.actions.settingsObject();
  const message=document.getElementById('field_message');

  message.className='mb-4 whitespace-pre-line text-sm text-slate-600';
  message.textContent='接続確認中…';

  try{
   const j=await App.API.request('test_kintone',{
    settings_json:App.Util.json(s)
   });

   message.textContent=
    `${j.message}\nHTTPステータス: ${j.status}\n`+
    `接続先: ${j.url||'(URL生成前)'}\n`+
    `Proxy: ${j.proxy_used?'使用':'未使用'}`;
   message.className=
    'mb-4 whitespace-pre-line text-sm '+
    (j.ok?'text-green-600':'text-red-600');
  }catch(e){
   message.className='mb-4 whitespace-pre-line text-sm text-red-600';
   message.textContent=e.message;
  }
 },

 async saveSettings(){
  const s=App.actions.settingsObject();

  try{
   const j=await App.API.settings(s);
   if(!j.ok)throw new Error(j.message);
   alert(j.message);
   App.Render.settings();
  }catch(e){alert(e.message)}
 },

 csv(id){
  location.href=
   location.pathname+'?action=csv&survey_id='+
   encodeURIComponent(id);
 },

 logout(){
  if(confirm('ログアウトしますか？'))location.reload();
 }
},

init(){
 if(window.App.__initialized)return;
 window.App.__initialized=true;
 App.actions.init();
}
};

if(document.readyState==='loading'){
 document.addEventListener('DOMContentLoaded',()=>App.init(),{once:true});
}else{
 App.init();
}
</script>

<input type="hidden" id="csrf_token" value="<?= survey_h($csrf) ?>">

</body>
</html>

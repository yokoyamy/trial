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
header('Cache-Control: no-store');

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

/* =========================================================
 * 基本
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

/*
 * Windows/Unix両対応の保存。
 * rename()だけに依存しない。
 */
function survey_write_data(array $data): bool
{
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        if (!@mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true) &&
            !is_dir(SURVEY_STORAGE_DIRECTORY)) {
            return false;
        }
    }

    $json = survey_json($data);

    if ($json === 'null') {
        return false;
    }

    $tmp = SURVEY_STORAGE_FILE . '.' . bin2hex(random_bytes(8)) . '.tmp';

    $written = @file_put_contents($tmp, $json, LOCK_EX);

    if ($written === false || $written !== strlen($json)) {
        @unlink($tmp);
        return false;
    }

    /*
     * 最初にrenameを試す。
     */
    if (@rename($tmp, SURVEY_STORAGE_FILE)) {
        return true;
    }

    /*
     * Windowsで既存ファイルがある場合。
     */
    $backup = SURVEY_STORAGE_FILE . '.' . bin2hex(random_bytes(5)) . '.bak';

    if (is_file(SURVEY_STORAGE_FILE)) {
        if (!@rename(SURVEY_STORAGE_FILE, $backup)) {
            $direct = @file_put_contents(
                SURVEY_STORAGE_FILE,
                $json,
                LOCK_EX
            );

            @unlink($tmp);

            return $direct !== false &&
                $direct === strlen($json);
        }
    }

    if (@rename($tmp, SURVEY_STORAGE_FILE)) {
        @unlink($backup);
        return true;
    }

    /*
     * 最終フォールバック。
     */
    $direct = @file_put_contents(
        SURVEY_STORAGE_FILE,
        $json,
        LOCK_EX
    );

    @unlink($tmp);

    if ($direct !== false && $direct === strlen($json)) {
        @unlink($backup);
        return true;
    }

    if (!is_file(SURVEY_STORAGE_FILE) && is_file($backup)) {
        @rename($backup, SURVEY_STORAGE_FILE);
    }

    return false;
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
        return [
            'ok' => false,
            'error' => 'kintone接続先が未入力です。'
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
        $port = isset($parsed['port'])
            ? (int)$parsed['port']
            : null;
    }

    if ($host === '' &&
        preg_match('~^https?://([^/?#]+)~i', $input, $m)) {

        $authority = strtolower($m[1]);

        if (preg_match('~^(.+):([0-9]+)$~', $authority, $pm)) {
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
     * 通常環境:
     * xxxx.cybozu.com
     *
     * 検証環境等のFQDNも許可。
     */
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
                'Proxy形式は host:port、http://host:port、https://host:port です。'
        ];
    }

    $scheme = strtolower($m[1] ?: 'http');
    $host = strtolower(trim($m[2]));
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
        'value' => 'tcp://' . $host . ':' . $port,
        'display' => $scheme . '://' . $host . ':' . $port,
        'host' => $host,
        'port' => $port,
    ];
}

/* =========================================================
 * HTTP
 * ========================================================= */

function survey_last_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        try {
            $h = http_get_last_response_headers();
            return is_array($h) ? $h : [];
        } catch (Throwable) {
            return [];
        }
    }

    $h = $GLOBALS['http_response_header'] ?? null;

    return is_array($h) ? $h : [];
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
            'error' => 'Proxy接続失敗: ' . $proxyInfo['error'],
            'url' => $url,
            'proxy_used' => true,
        ];
    }

    if (!in_array('http', stream_get_wrappers(), true) ||
        !in_array('https', stream_get_wrappers(), true)) {

        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' =>
                'PHP HTTP/HTTPS stream wrapperが利用できません。'
                . ' allow_url_fopen、OpenSSLを確認してください。',
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

    $headers2 = survey_last_headers();
    $status = survey_status_from_headers($headers2);
    $bodyText = is_string($body) ? $body : '';
    $json = json_decode($bodyText, true);

    if ($status === 0) {

        $error = $warning !== ''
            ? $warning
            : 'HTTPレスポンスを取得できませんでした。';

        $error .=
            "\n確認事項: DNS名前解決、PHPサーバーからの外部HTTPS通信、"
            . "Proxy、ファイアウォール、SSL/TLS、OpenSSL、タイムアウト。";

        if ($proxyInfo['used']) {
            $error .= "\nProxy: 使用\nProxy接続失敗の可能性があります。";
        } else {
            $error .= "\nProxy: 未使用";
        }

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
        $normalized['base'] .
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode($appId);

    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');

    $auth = base64_encode($login . ':' . $password);

    return survey_http_request(
        $url,
        'GET',
        [
            'X-Cybozu-Authorization: ' . $auth,
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
            . "PHP通信エラー: "
            . ($error !== '' ? $error : 'なし')
            . "\n確認事項: DNS、外部HTTPS通信、Proxy、"
            . "ファイアウォール、SSL/TLS、OpenSSL。";
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
            . "接続先: {$url}\n"
            . "確認事項: ホスト名、アプリID、API URL。";
    }

    if ($status === 408) {
        return "kintone通信タイムアウトです。\nHTTPステータス: 408";
    }

    if ($status === 429) {
        return "kintone側のレート制限です。\nHTTPステータス: 429";
    }

    if ($status >= 500) {
        return
            "kintoneまたはProxy側のサーバーエラーです。\n"
            . "HTTPステータス: {$status}";
    }

    if ($status >= 200 && $status < 300) {
        return "kintone通信に成功しました。\nHTTPステータス: {$status}";
    }

    return
        "kintone通信エラーです。\n"
        . "HTTPステータス: {$status}\n"
        . "接続先: {$url}";
}

function survey_kintone_fields(array $r): array
{
    $status = (int)($r['status'] ?? 0);

    if ($status < 200 || $status >= 300) {
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

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey_' .
        preg_replace('/[^a-zA-Z0-9_-]/', '_', $surveyId) .
        '.csv"'
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
            $id = (string)($q['id'] ?? '');
            $value = $answers[$id] ?? '';

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $row[] = $value;
        }

        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}

/* =========================================================
 * POST API
 * ========================================================= */

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($action !== '') {

    if (
        !in_array($action, [
            'public_get',
            'public_submit'
        ], true)
    ) {
        if (!survey_check_token()) {
            survey_api([
                'ok' => false,
                'message' => 'CSRFトークンが不正です。画面を再読み込みしてください。'
            ], 403);
        }
    }

    $data = survey_read_data();

    switch ($action) {

        case 'get_data':

            survey_api([
                'ok' => true,
                'data' => survey_public_data($data),
            ]);
            break;

        case 'save_survey':

            $raw = (string)($_POST['survey_json'] ?? '');
            $survey = json_decode($raw, true);

            if (!is_array($survey)) {
                survey_api([
                    'ok' => false,
                    'message' => 'アンケートデータが不正です。'
                ], 400);
            }

            $survey['id'] =
                trim((string)($survey['id'] ?? ''));

            if ($survey['id'] === '') {
                $survey['id'] = survey_id();
            }

            $survey['title'] =
                trim((string)($survey['title'] ?? '無題のアンケート'));

            $survey['updated_at'] = survey_now();

            if (empty($survey['created_at'])) {
                $survey['created_at'] = survey_now();
            }

            $survey['status'] =
                in_array(
                    ($survey['status'] ?? 'draft'),
                    ['draft', 'active', 'ended'],
                    true
                )
                ? $survey['status']
                : 'draft';

            $found = false;

            foreach ($data['surveys'] as $i => $existing) {
                if (($existing['id'] ?? '') === $survey['id']) {
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
                    'message' =>
                        '保存に失敗しました。'
                        . "\n保存先: "
                        . SURVEY_STORAGE_FILE
                        . "\n確認事項: survey_storageフォルダの書込権限、"
                        . "PHPプロセスの権限、Windowsのファイルロック。"
                ], 500);
            }

            /*
             * 保存直後に再読込して、本当に保存されたことを確認。
             */
            $verify = survey_read_data();
            $verified = false;

            foreach ($verify['surveys'] as $s) {
                if (($s['id'] ?? '') === $survey['id'] &&
                    ($s['title'] ?? '') === $survey['title']) {
                    $verified = true;
                    break;
                }
            }

            if (!$verified) {
                survey_api([
                    'ok' => false,
                    'message' =>
                        'ファイル保存処理は終了しましたが、'
                        . '保存内容を再読込できませんでした。'
                ], 500);
            }

            survey_api([
                'ok' => true,
                'data' => survey_public_data($verify),
                'message' => '保存しました。'
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

            if (!survey_write_data($data)) {
                survey_api([
                    'ok' => false,
                    'message' => '削除状態の保存に失敗しました。'
                ], 500);
            }

            survey_api([
                'ok' => true,
                'data' => survey_public_data($data)
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
                    'message' => '複製対象が見つかりません。'
                ], 404);
            }

            $copy['id'] = survey_id();
            $copy['title'] =
                (string)($copy['title'] ?? '') . '（複製）';
            $copy['status'] = 'draft';
            $copy['created_at'] = survey_now();
            $copy['updated_at'] = survey_now();
            $copy['deleted'] = false;

            $data['surveys'][] = $copy;

            if (!survey_write_data($data)) {
                survey_api([
                    'ok' => false,
                    'message' => '複製保存に失敗しました。'
                ], 500);
            }

            survey_api([
                'ok' => true,
                'data' => survey_public_data($data)
            ]);
            break;

        case 'set_status':

            $id = (string)($_POST['survey_id'] ?? '');
            $status = (string)($_POST['status'] ?? '');

            if (!in_array(
                $status,
                ['draft', 'active', 'ended'],
                true
            )) {
                survey_api([
                    'ok' => false,
                    'message' => 'ステータスが不正です。'
                ], 400);
            }

            foreach ($data['surveys'] as &$s) {
                if (($s['id'] ?? '') === $id) {
                    $s['status'] = $status;
                    $s['updated_at'] = survey_now();
                }
            }
            unset($s);

            if (!survey_write_data($data)) {
                survey_api([
                    'ok' => false,
                    'message' => 'ステータス保存に失敗しました。'
                ], 500);
            }

            survey_api([
                'ok' => true,
                'data' => survey_public_data($data)
            ]);
            break;

        case 'save_settings':

            $raw = (string)($_POST['settings_json'] ?? '');
            $settings = json_decode($raw, true);

            if (!is_array($settings)) {
                survey_api([
                    'ok' => false,
                    'message' => '設定データが不正です。'
                ], 400);
            }

            $oldPassword =
                (string)($data['settings']['password'] ?? '');

            $password =
                (string)($settings['password'] ?? '');

            if ($password === '') {
                $password = $oldPassword;
            }

            $data['settings'] = array_replace(
                $data['settings'],
                $settings,
                [
                    'password' => $password,
                    'ssl_verify' =>
                        !empty($settings['ssl_verify']),
                ]
            );

            if (!survey_write_data($data)) {
                survey_api([
                    'ok' => false,
                    'message' => 'kintone設定の保存に失敗しました。'
                ], 500);
            }

            $public = survey_public_data($data);

            survey_api([
                'ok' => true,
                'data' => $public,
                'message' => '設定を保存しました。'
            ]);
            break;

        case 'kintone_test':
        case 'fetch_fields':

            $settingsRaw =
                (string)($_POST['settings_json'] ?? '');

            $settings =
                json_decode($settingsRaw, true);

            if (!is_array($settings)) {
                $settings = $data['settings'];
            }

            /*
             * 入力値はこのリクエストだけで使用。
             * パスワードをレスポンスへ返さない。
             */
            $r = survey_kintone_request($settings);

            if ($action === 'kintone_test') {

                $ok =
                    (int)$r['status'] >= 200 &&
                    (int)$r['status'] < 300;

                survey_api([
                    'ok' => $ok,
                    'status' => $r['status'],
                    'message' =>
                        $ok
                        ? 'kintone接続に成功しました。'
                        : survey_kintone_message($r),
                    'url' => $r['url'],
                    'proxy_used' => $r['proxy_used'],
                ]);
            }

            $fields = survey_kintone_fields($r);

            survey_api($fields);
            break;

        case 'mail_send':

            $surveyId =
                (string)($_POST['survey_id'] ?? '');

            $idsRaw =
                (string)($_POST['recipient_ids'] ?? '');

            $ids = json_decode($idsRaw, true);

            if (!is_array($ids)) {
                $ids = [];
            }

            $subject =
                (string)($_POST['mail_subject'] ?? '');

            $body =
                (string)($_POST['mail_body'] ?? '');

            $templateType =
                (string)($_POST['template_type'] ?? 'initial');

            $sent = 0;

            foreach ($data['customers'] as &$customer) {

                if (!in_array(
                    (string)($customer['id'] ?? ''),
                    array_map('strval', $ids),
                    true
                )) {
                    continue;
                }

                $to =
                    (string)($customer['email'] ?? '');

                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                $url =
                    (string)(
                        ($_SERVER['REQUEST_SCHEME'] ?? 'http')
                    )
                    . '://'
                    . ($_SERVER['HTTP_HOST'] ?? '')
                    . strtok(
                        $_SERVER['REQUEST_URI'] ?? '/',
                        '?'
                    )
                    . '?public=1&survey_id='
                    . rawurlencode($surveyId)
                    . '&customer_id='
                    . rawurlencode(
                        (string)($customer['id'] ?? '')
                    );

                $mailBody = str_replace(
                    [
                        '{顧客名}',
                        '{アンケートURL}'
                    ],
                    [
                        (string)($customer['name'] ?? ''),
                        $url
                    ],
                    $body
                );

                $ok = @mail(
                    $to,
                    mb_encode_mimeheader(
                        $subject,
                        'UTF-8'
                    ),
                    $mailBody,
                    "MIME-Version: 1.0\r\n"
                    . "Content-Type: text/plain; charset=UTF-8\r\n"
                    . "From: "
                    . ($_SERVER['SERVER_ADMIN']
                        ?? 'webmaster@localhost')
                );

                if ($ok) {
                    $customer['sent_at'] = survey_now();
                    $customer['send_count'] =
                        (int)($customer['send_count'] ?? 0) + 1;
                    $customer['answer_status'] = 'unanswered';
                    $sent++;
                }
            }

            unset($customer);

            $data['mail_logs'][] = [
                'id' => survey_id(),
                'survey_id' => $surveyId,
                'sent_at' => survey_now(),
                'template_type' => $templateType,
                'count' => $sent,
                'subject' => $subject,
            ];

            if (!survey_write_data($data)) {
                survey_api([
                    'ok' => false,
                    'message' => '送信履歴の保存に失敗しました。'
                ], 500);
            }

            survey_api([
                'ok' => true,
                'sent' => $sent,
                'data' => survey_public_data($data)
            ]);
            break;

        case 'kintone_register':

            $customerId =
                (string)($_POST['customer_id'] ?? '');

            foreach ($data['customers'] as &$customer) {
                if (($customer['id'] ?? '') === $customerId) {
                    $customer['kintone_status'] = 'registered';
                }
            }

            unset($customer);

            if (!survey_write_data($data)) {
                survey_api([
                    'ok' => false,
                    'message' => '状態保存に失敗しました。'
                ], 500);
            }

            survey_api([
                'ok' => true,
                'data' => survey_public_data($data)
            ]);
            break;

        case 'csv':

            survey_csv_download(
                $data,
                (string)($_POST['survey_id'] ?? '')
            );
            break;

        case 'public_get':

            $surveyId =
                (string)($_POST['survey_id'] ?? $_GET['survey_id'] ?? '');

            foreach ($data['surveys'] as $survey) {

                if (($survey['id'] ?? '') !== $surveyId) {
                    continue;
                }

                if (!empty($survey['deleted'])) {
                    break;
                }

                survey_api([
                    'ok' => true,
                    'survey' => $survey
                ]);
            }

            survey_api([
                'ok' => false,
                'message' => 'アンケートが見つかりません。'
            ], 404);
            break;

        case 'public_submit':

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
                    'message' => 'アンケートが見つかりません。'
                ], 404);
            }

            $email =
                trim((string)($_POST['email'] ?? ''));

            $name =
                trim((string)($_POST['name'] ?? ''));

            $company =
                trim((string)($_POST['company'] ?? ''));

            $customerId =
                trim((string)($_POST['customer_id'] ?? ''));

            $answers =
                json_decode(
                    (string)($_POST['answers'] ?? '{}'),
                    true
                );

            if (!is_array($answers)) {
                $answers = [];
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

            $data['responses'][] = $response;

            $foundCustomer = false;

            foreach ($data['customers'] as &$customer) {

                $sameId =
                    $customerId !== '' &&
                    ($customer['id'] ?? '') === $customerId;

                $sameEmail =
                    $email !== '' &&
                    strcasecmp(
                        (string)($customer['email'] ?? ''),
                        $email
                    ) === 0;

                if ($sameId || $sameEmail) {
                    $customer['answer_status'] = 'answered';
                    $foundCustomer = true;
                    break;
                }
            }

            unset($customer);

            if (!$foundCustomer) {

                $data['customers'][] = [
                    'id' => $customerId !== ''
                        ? $customerId
                        : survey_id(),
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
                    'kintone_status' => 'unregistered',
                ];
            }

            if (!survey_write_data($data)) {
                survey_api([
                    'ok' => false,
                    'message' => '回答を保存できませんでした。'
                ], 500);
            }

            survey_api([
                'ok' => true,
                'message' => '回答を送信しました。'
            ]);
            break;

        default:

            survey_api([
                'ok' => false,
                'message' => '不明なactionです。'
            ], 400);
    }
}

/* =========================================================
 * Public form
 * ========================================================= */

$isPublic =
    isset($_GET['public']) &&
    $_GET['public'] === '1';

if ($isPublic) {

    $surveyId =
        (string)($_GET['survey_id'] ?? '');

    $data = survey_read_data();
    $survey = null;

    foreach ($data['surveys'] as $s) {
        if (($s['id'] ?? '') === $surveyId) {
            $survey = $s;
            break;
        }
    }

    if (!$survey || !empty($survey['deleted'])) {
        http_response_code(404);
        ?>
        <!doctype html>
        <html lang="ja">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>アンケート</title>
            <script src="https://cdn.tailwindcss.com"></script>
        </head>
        <body class="bg-slate-50 text-slate-800">
        <main class="max-w-3xl mx-auto p-8">
            <div class="bg-white rounded-2xl shadow p-8 text-center">
                アンケートが見つかりません。
            </div>
        </main>
        </body>
        </html>
        <?php
        exit;
    }

    $customerId =
        (string)($_GET['customer_id'] ?? '');

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
    <main class="max-w-3xl mx-auto p-4 md:p-8">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <h1 class="text-2xl font-bold mb-6">
                <?= survey_h($survey['title']) ?>
            </h1>

            <form id="publicForm" class="space-y-6">

                <input type="hidden"
                       name="survey_id"
                       value="<?= survey_h($surveyId) ?>">

                <input type="hidden"
                       name="customer_id"
                       value="<?= survey_h($customerId) ?>">

                <div>
                    <label class="block text-sm font-medium mb-1">
                        会社名
                    </label>
                    <input name="company"
                           class="w-full border rounded-lg px-3 py-2">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">
                        氏名
                    </label>
                    <input name="name"
                           class="w-full border rounded-lg px-3 py-2">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">
                        メールアドレス
                    </label>
                    <input name="email"
                           type="email"
                           class="w-full border rounded-lg px-3 py-2">
                </div>

                <?php
                $qNo = 0;

                foreach ($survey['groups'] ?? [] as $group):
                ?>
                    <section class="border-t pt-6">
                        <h2 class="font-bold text-lg mb-4">
                            <?= survey_h($group['name'] ?? '') ?>
                        </h2>

                        <?php
                        foreach ($group['questions'] ?? [] as $q):
                            $qNo++;
                            $qid = (string)($q['id'] ?? '');
                            $type = (string)($q['type'] ?? 'text');
                        ?>
                            <div class="mb-6">
                                <label class="block font-medium mb-2">
                                    Q<?= $qNo ?>.
                                    <?= survey_h($q['text'] ?? '') ?>
                                    <?php if (!empty($q['required'])): ?>
                                        <span class="text-red-500">*</span>
                                    <?php endif; ?>
                                </label>

                                <?php if ($type === 'single'): ?>

                                    <div class="space-y-2">
                                    <?php foreach ($q['options'] ?? [] as $option): ?>
                                        <label class="flex gap-2 items-center">
                                            <input type="radio"
                                                   name="q_<?= survey_h($qid) ?>"
                                                   value="<?= survey_h($option) ?>"
                                                   <?= !empty($q['required']) ? 'required' : '' ?>>
                                            <span><?= survey_h($option) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                    </div>

                                <?php elseif ($type === 'multiple'): ?>

                                    <div class="space-y-2">
                                    <?php foreach ($q['options'] ?? [] as $option): ?>
                                        <label class="flex gap-2 items-center">
                                            <input type="checkbox"
                                                   name="q_<?= survey_h($qid) ?>[]"
                                                   value="<?= survey_h($option) ?>">
                                            <span><?= survey_h($option) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                    </div>

                                <?php else: ?>

                                    <textarea
                                        name="q_<?= survey_h($qid) ?>"
                                        rows="4"
                                        class="w-full border rounded-lg p-3"
                                        <?= !empty($q['required']) ? 'required' : '' ?>
                                    ></textarea>

                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>

                <input type="hidden"
                       name="answers"
                       id="publicAnswers">

                <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-3 font-bold">
                    回答を送信
                </button>
            </form>

            <div id="publicMessage"
                 class="hidden mt-5 rounded-lg p-4"></div>
        </div>
    </main>

    <script>
    (() => {
        const form = document.getElementById('publicForm');
        const message = document.getElementById('publicMessage');

        form.addEventListener('submit', async e => {
            e.preventDefault();

            const fd = new FormData(form);
            const answers = {};

            <?php foreach ($survey['groups'] ?? [] as $group): ?>
            <?php foreach ($group['questions'] ?? [] as $q): ?>
            <?php $qid = (string)($q['id'] ?? ''); ?>
            <?php if (($q['type'] ?? '') === 'multiple'): ?>
            answers[<?= json_encode($qid, JSON_UNESCAPED_UNICODE) ?>] =
                fd.getAll(
                    <?= json_encode('q_' . $qid . '[]') ?>
                );
            <?php else: ?>
            answers[<?= json_encode($qid, JSON_UNESCAPED_UNICODE) ?>] =
                fd.get(
                    <?= json_encode('q_' . $qid) ?>
                ) || '';
            <?php endif; ?>
            <?php endforeach; ?>
            <?php endforeach; ?>

            fd.set('answers', JSON.stringify(answers));
            fd.set('action', 'public_submit');

            try {
                const r = await fetch(location.pathname, {
                    method: 'POST',
                    body: fd,
                    cache: 'no-store'
                });

                const text = await r.text();
                let json;

                try {
                    json = JSON.parse(text);
                } catch {
                    throw new Error('サーバーから不正な応答が返りました。');
                }

                if (!json.ok) {
                    throw new Error(json.message || '送信に失敗しました。');
                }

                form.classList.add('hidden');
                message.className =
                    'mt-5 rounded-lg p-4 bg-green-50 text-green-700';
                message.textContent =
                    json.message || '回答を送信しました。';

            } catch (err) {
                message.className =
                    'mt-5 rounded-lg p-4 bg-red-50 text-red-700';
                message.textContent =
                    err.message || String(err);
            }
        });
    })();
    </script>
    </body>
    </html>
    <?php
    exit;
}

/* =========================================================
 * 管理画面
 * ========================================================= */

$csrf = survey_token();

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= survey_h($csrf) ?>">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-slate-50 text-slate-800">

<input type="hidden"
       id="csrf_token"
       value="<?= survey_h($csrf) ?>">

<div id="app"></div>

<script>
'use strict';

/*
 * ================================================================
 * APP
 * ================================================================
 *
 * グローバル関数を作らない。
 * すべてwindow.App以下に置く。
 */
window.App = {

    state: {
        data: {
            surveys: [],
            responses: [],
            customers: [],
            settings: {},
            mail_logs: []
        },
        page: 'list',
        editingSurvey: null,
        selectedSurveyId: null,
        responseSurveyId: null,
        previewSurvey: null,
        fields: [],
        surveyKeyword: '',
        surveyStatus: '',
        surveySort: 'updated_desc',
        customerFilter: '',
        responseFilter: '',
        selectedRecipients: [],
        selectedQuestions: {}
    },

    utils: {},

    api: {},

    render: {},

    actions: {},

    __initialized: false,

    init: null,

    /*
     * 旧版互換。
     * 古いブラウザキャッシュがApp.RememberData()を
     * 呼んでもJavaScript停止しない。
     */
    RememberData: function () {
        return window.App.state;
    }
};

window.RememberData = window.App.RememberData;

/* =========================================================
 * Utils
 * ========================================================= */

App.utils.escape = function (v) {
    return String(v ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};

App.utils.uuid = function () {
    if (crypto.randomUUID) {
        return crypto.randomUUID();
    }

    return Date.now().toString(36) +
        Math.random().toString(36).slice(2);
};

App.utils.notify = function (message, error = false) {

    const old = document.getElementById('app-notice');

    if (old) {
        old.remove();
    }

    const el = document.createElement('div');

    el.id = 'app-notice';

    el.className =
        'fixed top-4 right-4 z-[9999] max-w-md rounded-xl shadow-lg px-5 py-4 text-sm ' +
        (error
            ? 'bg-red-600 text-white'
            : 'bg-slate-900 text-white');

    el.textContent = message;

    document.body.appendChild(el);

    setTimeout(() => el.remove(), 4500);
};

App.utils.clone = function (v) {
    return JSON.parse(JSON.stringify(v));
};

App.utils.surveyStatus = function (s) {

    if (s === 'active') {
        return '<span class="px-2 py-1 rounded-full bg-green-100 text-green-700 text-xs">公開中</span>';
    }

    if (s === 'ended') {
        return '<span class="px-2 py-1 rounded-full bg-slate-200 text-slate-600 text-xs">終了</span>';
    }

    return '<span class="px-2 py-1 rounded-full bg-amber-100 text-amber-700 text-xs">下書き</span>';
};

App.utils.formatDate = function (v) {

    if (!v) {
        return '未設定';
    }

    return String(v).replace(
        /^(\d{4})-(\d{2})-(\d{2}).*$/,
        '$1/$2/$3'
    );
};

/* =========================================================
 * API
 * ========================================================= */

App.api.request = async function (formData) {

    const response = await fetch(location.pathname, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    });

    const text = await response.text();

    let json = null;

    try {
        json = JSON.parse(text);
    } catch (e) {
        throw new Error(
            'サーバーがJSONを返しませんでした。\n' +
            'HTTPステータス: ' + response.status +
            '\nレスポンス:\n' +
            text.substring(0, 1200)
        );
    }

    if (!response.ok || json.ok === false) {
        throw new Error(
            json.message ||
            json.error ||
            ('HTTPエラー: ' + response.status)
        );
    }

    return json;
};

App.api.load = async function () {

    const fd = new FormData();

    fd.append('action', 'get_data');
    fd.append(
        'csrf_token',
        document.getElementById('csrf_token').value
    );

    const result = await App.api.request(fd);

    App.state.data = result.data;

    return result.data;
};

App.api.saveSurvey = async function (survey) {

    const fd = new FormData();

    fd.append('action', 'save_survey');
    fd.append(
        'csrf_token',
        document.getElementById('csrf_token').value
    );
    fd.append(
        'survey_json',
        JSON.stringify(survey)
    );

    return App.api.request(fd);
};

App.api.deleteSurvey = async function (id) {

    const fd = new FormData();

    fd.append('action', 'delete_survey');
    fd.append(
        'csrf_token',
        document.getElementById('csrf_token').value
    );
    fd.append('survey_id', id);

    return App.api.request(fd);
};

App.api.duplicateSurvey = async function (id) {

    const fd = new FormData();

    fd.append('action', 'duplicate_survey');
    fd.append(
        'csrf_token',
        document.getElementById('csrf_token').value
    );
    fd.append('survey_id', id);

    return App.api.request(fd);
};

App.api.setStatus = async function (id, status) {

    const fd = new FormData();

    fd.append('action', 'set_status');
    fd.append(
        'csrf_token',
        document.getElementById('csrf_token').value
    );
    fd.append('survey_id', id);
    fd.append('status', status);

    return App.api.request(fd);
};

App.api.saveSettings = async function (settings) {

    const fd = new FormData();

    fd.append('action', 'save_settings');
    fd.append(
        'csrf_token',
        document.getElementById('csrf_token').value
    );
    fd.append(
        'settings_json',
        JSON.stringify(settings)
    );

    return App.api.request(fd);
};

App.api.kintone = async function (
    settings,
    action = 'kintone_test'
) {

    const fd = new FormData();

    fd.append('action', action);
    fd.append(
        'csrf_token',
        document.getElementById('csrf_token').value
    );
    fd.append(
        'settings_json',
        JSON.stringify(settings)
    );

    return App.api.request(fd);
};

App.api.sendMail = async function (
    surveyId,
    ids,
    subject,
    body,
    type
) {

    const fd = new FormData();

    fd.append('action', 'mail_send');
    fd.append(
        'csrf_token',
        document.getElementById('csrf_token').value
    );
    fd.append('survey_id', surveyId);
    fd.append(
        'recipient_ids',
        JSON.stringify(ids)
    );
    fd.append('mail_subject', subject);
    fd.append('mail_body', body);
    fd.append('template_type', type);

    return App.api.request(fd);
};

App.api.registerKintone = async function (id) {

    const fd = new FormData();

    fd.append('action', 'kintone_register');
    fd.append(
        'csrf_token',
        document.getElementById('csrf_token').value
    );
    fd.append('customer_id', id);

    return App.api.request(fd);
};

/* =========================================================
 * Survey creation
 * ========================================================= */

App.actions.newSurvey = function () {

    App.state.editingSurvey = {
        id: App.utils.uuid(),
        title: '',
        start_at: '',
        end_at: '',
        status: 'draft',
        created_at: '',
        updated_at: '',
        numbering_mode: 'global',
        groups: [
            {
                id: App.utils.uuid(),
                name: 'グループ1',
                questions: []
            }
        ],
        deleted: false
    };

    App.state.page = 'edit';

    App.render.edit();
};

App.actions.addGroup = function () {

    const survey = App.state.editingSurvey;

    survey.groups.push({
        id: App.utils.uuid(),
        name: '新しいグループ',
        questions: []
    });

    App.render.edit();
    App.actions.enableSortable();
};

App.actions.removeGroup = function (groupId) {

    if (!confirm(
        'このグループと内包する質問を削除しますか？'
    )) {
        return;
    }

    const survey = App.state.editingSurvey;

    survey.groups =
        survey.groups.filter(g => g.id !== groupId);

    if (!survey.groups.length) {
        survey.groups.push({
            id: App.utils.uuid(),
            name: 'グループ1',
            questions: []
        });
    }

    App.actions.renumber();
    App.render.edit();
    App.actions.enableSortable();
};

App.actions.addQuestion = function (groupId) {

    const survey = App.state.editingSurvey;

    const group =
        survey.groups.find(g => g.id === groupId);

    if (!group) {
        return;
    }

    group.questions.push({
        id: App.utils.uuid(),
        text: '',
        type: 'single',
        required: false,
        options: ['選択肢1', '選択肢2'],
        other_enabled: false
    });

    App.actions.renumber();
    App.render.edit();
    App.actions.enableSortable();
};

App.actions.removeQuestion = function (
    groupId,
    questionId
) {

    const survey = App.state.editingSurvey;

    const group =
        survey.groups.find(g => g.id === groupId);

    if (!group) {
        return;
    }

    group.questions =
        group.questions.filter(
            q => q.id !== questionId
        );

    App.actions.renumber();
    App.render.edit();
    App.actions.enableSortable();
};

App.actions.updateGroupName = function (
    groupId,
    value
) {

    const g =
        App.state.editingSurvey.groups.find(
            x => x.id === groupId
        );

    if (g) {
        g.name = value;
    }
};

App.actions.updateQuestion = function (
    groupId,
    questionId,
    key,
    value
) {

    const g =
        App.state.editingSurvey.groups.find(
            x => x.id === groupId
        );

    if (!g) {
        return;
    }

    const q =
        g.questions.find(
            x => x.id === questionId
        );

    if (!q) {
        return;
    }

    if (key === 'required') {
        q.required = !!value;
    } else {
        q[key] = value;
    }

    if (key === 'type' &&
        !['single', 'multiple', 'text'].includes(value)) {
        q.type = 'text';
    }
};

App.actions.addOption = function (
    groupId,
    questionId
) {

    const g =
        App.state.editingSurvey.groups.find(
            x => x.id === groupId
        );

    const q =
        g?.questions.find(
            x => x.id === questionId
        );

    if (!q) {
        return;
    }

    q.options = q.options || [];
    q.options.push('新しい選択肢');

    App.render.edit();
    App.actions.enableSortable();
};

App.actions.removeOption = function (
    groupId,
    questionId,
    index
) {

    const g =
        App.state.editingSurvey.groups.find(
            x => x.id === groupId
        );

    const q =
        g?.questions.find(
            x => x.id === questionId
        );

    if (!q) {
        return;
    }

    q.options.splice(index, 1);

    App.render.edit();
};

App.actions.updateOption = function (
    groupId,
    questionId,
    index,
    value
) {

    const g =
        App.state.editingSurvey.groups.find(
            x => x.id === groupId
        );

    const q =
        g?.questions.find(
            x => x.id === questionId
        );

    if (q && q.options[index] !== undefined) {
        q.options[index] = value;
    }
};

App.actions.renumber = function () {

    const survey = App.state.editingSurvey;

    let n = 0;

    survey.groups.forEach((group, gi) => {

        group.questions.forEach((q, qi) => {

            n++;

            q.number =
                survey.numbering_mode === 'group'
                    ? `Q${gi + 1}-${qi + 1}`
                    : `Q${n}`;
        });
    });
};

/* =========================================================
 * SortableJS
 * ========================================================= */

App.actions.enableSortable = function () {

    if (!window.Sortable) {
        return;
    }

    const groupList =
        document.getElementById('question_editor');

    if (!groupList) {
        return;
    }

    if (groupList._sortable) {
        groupList._sortable.destroy();
    }

    groupList._sortable =
        new Sortable(groupList, {
            animation: 180,
            handle: '.group-handle',
            ghostClass: 'opacity-40',

            onEnd: evt => {

                if (
                    evt.oldIndex === evt.newIndex
                ) {
                    return;
                }

                const groups =
                    App.state.editingSurvey.groups;

                const moved =
                    groups.splice(evt.oldIndex, 1)[0];

                groups.splice(
                    evt.newIndex,
                    0,
                    moved
                );

                App.actions.renumber();
                App.render.edit();
                App.actions.enableSortable();
            }
        });

    document
        .querySelectorAll('.question-list')
        .forEach(list => {

            if (list._sortable) {
                list._sortable.destroy();
            }

            list._sortable =
                new Sortable(list, {
                    group: 'survey-questions',
                    animation: 180,
                    handle: '.question-handle',
                    ghostClass: 'opacity-40',

                    onEnd: evt => {

                        const from =
                            evt.from.dataset.group;

                        const to =
                            evt.to.dataset.group;

                        const fromGroup =
                            App.state.editingSurvey.groups
                            .find(g => g.id === from);

                        const toGroup =
                            App.state.editingSurvey.groups
                            .find(g => g.id === to);

                        if (!fromGroup || !toGroup) {
                            return;
                        }

                        const question =
                            fromGroup.questions
                            .splice(evt.oldIndex, 1)[0];

                        if (!question) {
                            return;
                        }

                        toGroup.questions.splice(
                            evt.newIndex,
                            0,
                            question
                        );

                        App.actions.renumber();

                        App.render.edit();

                        App.actions.enableSortable();
                    }
                });
        });
};

/* =========================================================
 * Save / navigation
 * ========================================================= */

App.actions.saveSurvey = async function () {

    try {

        const survey =
            App.state.editingSurvey;

        if (!survey) {
            throw new Error(
                '編集対象のアンケートがありません。'
            );
        }

        survey.title =
            String(survey.title || '').trim();

        if (!survey.title) {
            alert('アンケートタイトルを入力してください。');
            return;
        }

        App.actions.renumber();

        survey.updated_at =
            new Date().toISOString();

        const result =
            await App.api.saveSurvey(survey);

        if (!result.ok) {
            throw new Error(
                result.message ||
                '保存に失敗しました。'
            );
        }

        /*
         * サーバー保存済みの最新データをStateへ反映。
         */
        if (result.data) {
            App.state.data = result.data;
        }

        /*
         * 編集状態を完全に解除。
         */
        App.state.editingSurvey = null;
        App.state.selectedSurveyId = null;
        App.state.page = 'list';

        /*
         * ここで必ず一覧描画。
         */
        App.render.list();

        App.utils.notify('保存しました。');

    } catch (error) {

        console.error(
            'saveSurvey:',
            error
        );

        App.utils.notify(
            error instanceof Error
                ? error.message
                : String(error),
            true
        );
    }
};

App.actions.cancelEdit = function () {

    if (!confirm(
        '変更を破棄して一覧へ戻りますか？'
    )) {
        return;
    }

    App.state.editingSurvey = null;
    App.state.page = 'list';

    App.render.list();
};

App.actions.editSurvey = function (id) {

    const survey =
        App.state.data.surveys.find(
            s => s.id === id
        );

    if (!survey) {
        App.utils.notify(
            'アンケートが見つかりません。',
            true
        );
        return;
    }

    App.state.editingSurvey =
        App.utils.clone(survey);

    App.state.page = 'edit';

    App.render.edit();

    App.actions.enableSortable();
};

App.actions.preview = function () {

    App.actions.renumber();

    App.state.previewSurvey =
        App.utils.clone(
            App.state.editingSurvey
        );

    App.render.preview();
};

App.actions.closePreview = function () {

    const el =
        document.getElementById('preview_modal');

    if (el) {
        el.remove();
    }
};

/* =========================================================
 * List actions
 * ========================================================= */

App.actions.toggleStatus = async function (
    id,
    status
) {

    try {

        const result =
            await App.api.setStatus(
                id,
                status
            );

        App.state.data =
            result.data;

        App.render.list();

        App.utils.notify(
            status === 'active'
                ? '公開しました。'
                : '停止しました。'
        );

    } catch (e) {

        App.utils.notify(
            e.message || String(e),
            true
        );
    }
};

App.actions.duplicate = async function (id) {

    if (!confirm(
        'このアンケートを複製しますか？'
    )) {
        return;
    }

    try {

        const result =
            await App.api.duplicateSurvey(id);

        App.state.data =
            result.data;

        App.render.list();

        App.utils.notify(
            '下書きとして複製しました。'
        );

    } catch (e) {

        App.utils.notify(
            e.message || String(e),
            true
        );
    }
};

App.actions.deleteSurvey = async function (id) {

    if (!confirm(
        'この下書きを削除しますか？'
    )) {
        return;
    }

    try {

        const result =
            await App.api.deleteSurvey(id);

        App.state.data =
            result.data;

        App.render.list();

        App.utils.notify(
            '削除しました。'
        );

    } catch (e) {

        App.utils.notify(
            e.message || String(e),
            true
        );
    }
};

App.actions.goList = function () {

    App.state.page = 'list';
    App.state.editingSurvey = null;

    App.render.list();
};

App.actions.goSettings = function () {

    App.state.page = 'settings';

    App.render.settings();
};

App.actions.goSend = function (id) {

    App.state.selectedSurveyId = id;
    App.state.page = 'send';

    App.render.send();
};

App.actions.goResults = function (id) {

    App.state.responseSurveyId = id;
    App.state.page = 'results';

    App.render.results();
};

/* =========================================================
 * Render header
 * ========================================================= */

App.render.header = function (title = '') {

    return `
<header class="sticky top-0 z-30 bg-white border-b border-slate-200">
<div class="max-w-[1500px] mx-auto px-5 py-3 flex items-center justify-between gap-4">
<div>
<div class="font-bold text-lg">アンケート管理</div>
${title
    ? `<div class="text-xs text-slate-500">${App.utils.escape(title)}</div>`
    : ''}
</div>

<nav class="flex gap-2 flex-wrap">
<button
 class="px-3 py-2 rounded-lg text-sm hover:bg-slate-100"
 onclick="App.actions.goList()">
アンケート一覧
</button>

<button
 class="px-3 py-2 rounded-lg text-sm hover:bg-slate-100"
 onclick="App.actions.goSettings()">
kintone連携設定
</button>

<button
 class="px-3 py-2 rounded-lg text-sm hover:bg-slate-100"
 onclick="alert('ログアウト処理はApache/PHPの認証方式に合わせて実装してください。')">
ログアウト
</button>
</nav>
</div>
</header>`;
};

/* =========================================================
 * List render
 * ========================================================= */

App.render.list = function () {

    App.state.page = 'list';

    const all =
        App.state.data.surveys.filter(
            s => !s.deleted
        );

    let surveys = all.filter(s => {

        const keyword =
            App.state.surveyKeyword
                .trim()
                .toLowerCase();

        if (
            keyword &&
            !String(s.title || '')
                .toLowerCase()
                .includes(keyword)
        ) {
            return false;
        }

        if (
            App.state.surveyStatus &&
            s.status !== App.state.surveyStatus
        ) {
            return false;
        }

        return true;
    });

    surveys.sort((a, b) => {

        if (App.state.surveySort === 'updated_desc') {
            return String(b.updated_at || '')
                .localeCompare(String(a.updated_at || ''));
        }

        if (App.state.surveySort === 'updated_asc') {
            return String(a.updated_at || '')
                .localeCompare(String(b.updated_at || ''));
        }

        if (App.state.surveySort === 'answers_desc') {
            return App.state.data.responses.filter(
                r => r.survey_id === b.id
            ).length -
            App.state.data.responses.filter(
                r => r.survey_id === a.id
            ).length;
        }

        if (App.state.surveySort === 'answers_asc') {
            return App.state.data.responses.filter(
                r => r.survey_id === a.id
            ).length -
            App.state.data.responses.filter(
                r => r.survey_id === b.id
            ).length;
        }

        return 0;
    });

    const rows = surveys.map(s => {

        const count =
            App.state.data.responses.filter(
                r => r.survey_id === s.id
            ).length;

        let buttons = '';

        if (s.status === 'active') {

            buttons = `
<button class="px-2 py-1 text-xs rounded bg-slate-100"
 onclick="App.actions.editSurvey('${s.id}')">
確認・編集
</button>

<button class="px-2 py-1 text-xs rounded bg-indigo-50 text-indigo-700"
 onclick="App.actions.goResults('${s.id}')">
集計
</button>

<button class="px-2 py-1 text-xs rounded bg-blue-50 text-blue-700"
 onclick="App.actions.goSend('${s.id}')">
送信
</button>

<button class="px-2 py-1 text-xs rounded bg-red-50 text-red-700"
 onclick="App.actions.toggleStatus('${s.id}','ended')">
停止
</button>

<button class="px-2 py-1 text-xs rounded bg-slate-100"
 onclick="App.actions.duplicate('${s.id}')">
複製
</button>`;

        } else if (s.status === 'draft') {

            buttons = `
<button class="px-2 py-1 text-xs rounded bg-slate-100"
 onclick="App.actions.editSurvey('${s.id}')">
確認・編集
</button>

<button class="px-2 py-1 text-xs rounded bg-red-50 text-red-700"
 onclick="App.actions.deleteSurvey('${s.id}')">
削除
</button>

<button class="px-2 py-1 text-xs rounded bg-slate-100"
 onclick="App.actions.duplicate('${s.id}')">
複製
</button>`;

        } else {

            buttons = `
<button class="px-2 py-1 text-xs rounded bg-slate-100"
 onclick="App.actions.editSurvey('${s.id}')">
確認・編集
</button>

<button class="px-2 py-1 text-xs rounded bg-indigo-50 text-indigo-700"
 onclick="App.actions.goResults('${s.id}')">
集計
</button>

<button class="px-2 py-1 text-xs rounded bg-slate-100"
 onclick="App.actions.duplicate('${s.id}')">
複製
</button>`;
        }

        return `
<tr class="border-b hover:bg-slate-50">
<td class="p-4">
<div class="text-xs text-slate-500">
${App.utils.formatDate(s.created_at)}
</div>
<div class="text-xs text-slate-400">
更新: ${App.utils.formatDate(s.updated_at)}
</div>
</td>

<td class="p-4 font-bold">
${App.utils.escape(s.title)}
</td>

<td class="p-4 text-sm">
${App.utils.escape(s.start_at || '未設定')}
～
${App.utils.escape(s.end_at || '未設定')}
</td>

<td class="p-4">
${App.utils.surveyStatus(s.status)}
</td>

<td class="p-4 text-right">
${count} 件
</td>

<td class="p-4">
<div class="flex flex-wrap gap-1">
${buttons}
</div>
</td>
</tr>`;
    }).join('');

    document.getElementById('app').innerHTML =
        App.render.header() +
        `
<main class="max-w-[1500px] mx-auto p-5">

<div class="flex items-center justify-between mb-5 gap-4 flex-wrap">
<div>
<h1 class="text-2xl font-bold">アンケート一覧</h1>
<p class="text-sm text-slate-500 mt-1">
すべての操作の起点です。
</p>
</div>

<button
 class="px-5 py-3 rounded-xl bg-blue-600 text-white font-bold hover:bg-blue-700"
 onclick="App.actions.newSurvey()">
＋ 新規アンケート作成
</button>
</div>

<div class="bg-white rounded-xl border p-4 mb-5 flex flex-wrap gap-3">

<input
 class="border rounded-lg px-3 py-2"
 placeholder="タイトル検索"
 value="${App.utils.escape(App.state.surveyKeyword)}"
 onkeydown="if(event.key==='Enter'){App.actions.searchSurvey(this.value)}">

<select
 class="border rounded-lg px-3 py-2"
 onchange="App.actions.filterStatus(this.value)">
<option value="">すべて</option>
<option value="active" ${App.state.surveyStatus==='active'?'selected':''}>公開中</option>
<option value="draft" ${App.state.surveyStatus==='draft'?'selected':''}>下書き</option>
<option value="ended" ${App.state.surveyStatus==='ended'?'selected':''}>終了</option>
</select>

<select
 class="border rounded-lg px-3 py-2"
 onchange="App.actions.sortSurvey(this.value)">
<option value="updated_desc" ${App.state.surveySort==='updated_desc'?'selected':''}>更新日：新しい順</option>
<option value="updated_asc" ${App.state.surveySort==='updated_asc'?'selected':''}>更新日：古い順</option>
<option value="answers_desc" ${App.state.surveySort==='answers_desc'?'selected':''}>回答数：多い順</option>
<option value="answers_asc" ${App.state.surveySort==='answers_asc'?'selected':''}>回答数：少ない順</option>
</select>

</div>

<div class="bg-white rounded-xl border overflow-x-auto">
<table class="w-full min-w-[1100px] text-sm">
<thead class="bg-slate-50">
<tr>
<th class="text-left p-4">作成日 / 更新日</th>
<th class="text-left p-4">タイトル</th>
<th class="text-left p-4">アンケート期間</th>
<th class="text-left p-4">ステータス</th>
<th class="text-right p-4">回答数</th>
<th class="text-left p-4">操作</th>
</tr>
</thead>
<tbody>
${rows || `
<tr>
<td colspan="6" class="p-12 text-center text-slate-400">
アンケートがありません。
</td>
</tr>`}
</tbody>
</table>
</div>

</main>`;
};

App.actions.searchSurvey = function (value) {
    App.state.surveyKeyword = value;
    App.render.list();
};

App.actions.filterStatus = function (value) {
    App.state.surveyStatus = value;
    App.render.list();
};

App.actions.sortSurvey = function (value) {
    App.state.surveySort = value;
    App.render.list();
};

/* =========================================================
 * Edit render
 * ========================================================= */

App.render.edit = function () {

    const s =
        App.state.editingSurvey;

    App.actions.renumber();

    const groups =
        s.groups.map((g, gi) => {

            const questions =
                g.questions.map(q => {

                    const options =
                        (q.options || []).map(
                            (o, oi) => `
<div class="flex gap-2 mb-2">
<input
 class="flex-1 border rounded-lg px-3 py-2"
 value="${App.utils.escape(o)}"
 onchange="App.actions.updateOption('${g.id}','${q.id}',${oi},this.value)">
<button
 class="px-2 text-red-500"
 onclick="App.actions.removeOption('${g.id}','${q.id}',${oi})">
×
</button>
</div>`
                        ).join('');

                    return `
<div
 class="question-card bg-white border rounded-xl p-4 shadow-sm"
 data-question="${q.id}">

<div class="flex gap-3">

<div class="question-handle cursor-grab text-slate-400 text-xl">
⠿
</div>

<div class="flex-1">

<div class="flex justify-between gap-3 mb-3">
<div class="font-bold">
${App.utils.escape(q.number || '')}
</div>

<button
 class="text-red-500 text-sm"
 onclick="App.actions.removeQuestion('${g.id}','${q.id}')">
削除
</button>
</div>

<input
 class="w-full border rounded-lg px-3 py-2 mb-3"
 placeholder="質問文"
 value="${App.utils.escape(q.text)}"
 onchange="App.actions.updateQuestion('${g.id}','${q.id}','text',this.value)">

<div class="flex flex-wrap gap-3 mb-3">

<select
 class="border rounded-lg px-3 py-2"
 onchange="App.actions.updateQuestion('${g.id}','${q.id}','type',this.value)">
<option value="single" ${q.type==='single'?'selected':''}>単一選択</option>
<option value="multiple" ${q.type==='multiple'?'selected':''}>複数選択</option>
<option value="text" ${q.type==='text'?'selected':''}>自由記述</option>
</select>

<label class="flex items-center gap-2">
<input type="checkbox"
 ${q.required?'checked':''}
 onchange="App.actions.updateQuestion('${g.id}','${q.id}','required',this.checked)">
必須回答
</label>

<label class="flex items-center gap-2">
<input type="checkbox"
 ${q.other_enabled?'checked':''}
 onchange="App.actions.updateQuestion('${g.id}','${q.id}','other_enabled',this.checked)">
その他
</label>

</div>

${q.type !== 'text' ? `
<div class="border-t pt-3">
<div class="text-sm font-medium mb-2">選択肢</div>
${options}
<button
 class="text-sm text-blue-600"
 onclick="App.actions.addOption('${g.id}','${q.id}')">
＋ 選択肢追加
</button>
</div>` : `
<textarea
 rows="3"
 disabled
 class="w-full bg-slate-50 border rounded-lg p-3"
 placeholder="回答者が入力する自由記述欄"></textarea>
`}

</div>
</div>
</div>`;
                }).join('');

            return `
<section
 class="group-card bg-slate-100 border border-slate-200 rounded-2xl p-4"
 data-group="${g.id}">

<div class="flex gap-3 items-center mb-4">

<div class="group-handle cursor-grab text-xl text-slate-400">
⠿
</div>

<input
 class="flex-1 bg-white border rounded-lg px-3 py-2 font-bold"
 value="${App.utils.escape(g.name)}"
 onchange="App.actions.updateGroupName('${g.id}',this.value)">

<button
 class="text-red-500 text-sm"
 onclick="App.actions.removeGroup('${g.id}')">
グループ削除
</button>

</div>

<div
 class="question-list space-y-3 min-h-[30px]"
 data-group="${g.id}">
${questions}
</div>

<button
 class="mt-4 px-3 py-2 rounded-lg bg-white border text-sm"
 onclick="App.actions.addQuestion('${g.id}')">
＋ 質問追加
</button>

</section>`;
        }).join('');

    document.getElementById('app').innerHTML =
        App.render.header('アンケート作成・編集') +
        `
<main class="max-w-[1300px] mx-auto p-5">

<div class="flex items-center justify-between mb-5 gap-3 flex-wrap">

<div>
<h1 class="text-2xl font-bold">アンケート編集</h1>
</div>

<div class="flex gap-2 flex-wrap">

<button
 class="px-4 py-2 rounded-lg border bg-white"
 onclick="App.actions.preview()">
プレビュー
</button>

<button
 class="px-4 py-2 rounded-lg border"
 onclick="App.actions.cancelEdit()">
キャンセル
</button>

<button
 class="px-5 py-2 rounded-lg bg-blue-600 text-white font-bold"
 onclick="App.actions.saveSurvey()">
保存して一覧へ戻る
</button>

</div>
</div>

<div class="bg-white rounded-xl border p-5 mb-5">

<label class="block text-sm font-medium mb-1">
タイトル
</label>

<input
 id="survey_title"
 class="w-full border rounded-lg px-3 py-3 text-lg font-bold mb-4"
 value="${App.utils.escape(s.title)}"
 oninput="App.state.editingSurvey.title=this.value">

<div class="grid md:grid-cols-3 gap-4">

<div>
<label class="text-sm">開始日時</label>
<input
 id="survey_start_at"
 type="datetime-local"
 class="w-full border rounded-lg px-3 py-2"
 value="${App.utils.escape(s.start_at)}"
 onchange="App.state.editingSurvey.start_at=this.value">
</div>

<div>
<label class="text-sm">終了日時</label>
<input
 id="survey_end_at"
 type="datetime-local"
 class="w-full border rounded-lg px-3 py-2"
 value="${App.utils.escape(s.end_at)}"
 onchange="App.state.editingSurvey.end_at=this.value">
</div>

<div>
<label class="text-sm">質問番号</label>
<select
 id="survey_numbering_mode"
 class="w-full border rounded-lg px-3 py-2"
 onchange="App.state.editingSurvey.numbering_mode=this.value;App.actions.renumber();App.render.edit();App.actions.enableSortable()">
<option value="global" ${s.numbering_mode==='global'?'selected':''}>
Q1, Q2, Q3...
</option>
<option value="group" ${s.numbering_mode==='group'?'selected':''}>
Q1-1, Q1-2...
</option>
</select>
</div>

</div>
</div>

<div class="flex justify-between items-center mb-3">
<h2 class="text-xl font-bold">設問構成</h2>

<button
 class="px-4 py-2 rounded-lg bg-white border"
 onclick="App.actions.addGroup()">
＋ グループ追加
</button>
</div>

<div
 id="question_editor"
 class="space-y-4">
${groups}
</div>

</main>`;
};

/* =========================================================
 * Preview
 * ========================================================= */

App.render.preview = function () {

    const s =
        App.state.previewSurvey;

    const content =
        s.groups.map(g => `
<section class="mb-8">
<h2 class="font-bold text-lg border-b pb-2 mb-4">
${App.utils.escape(g.name)}
</h2>

${g.questions.map(q => `
<div class="mb-6">
<div class="font-medium mb-2">
${App.utils.escape(q.number)}.
${App.utils.escape(q.text)}
${q.required ? '<span class="text-red-500">*</span>' : ''}
</div>

${
q.type === 'text'
? '<textarea class="w-full border rounded-lg p-3" rows="4"></textarea>'
: q.options.map(o => `
<label class="flex gap-2 mb-2">
<input type="${q.type === 'multiple' ? 'checkbox' : 'radio'}">
${App.utils.escape(o)}
</label>`).join('')
}

</div>`).join('')}
</section>`).join('');

    document.body.insertAdjacentHTML(
        'beforeend',
        `
<div
 id="preview_modal"
 class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">

<div class="bg-white rounded-2xl w-full max-w-3xl max-h-[90vh] overflow-auto">

<div class="sticky top-0 bg-white border-b p-4 flex justify-between">
<strong>プレビュー</strong>
<button onclick="App.actions.closePreview()">×</button>
</div>

<div class="p-6">
<h1 class="text-2xl font-bold mb-8">
${App.utils.escape(s.title)}
</h1>

${content}

<button
 onclick="alert('これはプレビューです。実際には送信されません。')"
 class="w-full bg-blue-600 text-white py-3 rounded-xl">
送信
</button>

</div>
</div>
</div>`
    );
};

/* =========================================================
 * Results
 * ========================================================= */

App.render.results = function () {

    const id =
        App.state.responseSurveyId;

    const survey =
        App.state.data.surveys.find(
            s => s.id === id
        );

    if (!survey) {
        App.render.list();
        return;
    }

    const responses =
        App.state.data.responses.filter(
            r => r.survey_id === id
        );

    const questions = [];

    survey.groups.forEach(g => {
        g.questions.forEach(q => questions.push(q));
    });

    const sent =
        App.state.data.customers.filter(
            c => c.sent_at
        ).length;

    const answered =
        responses.length;

    const web =
        responses.filter(
            r => !r.customer_id
        ).length;

    const unanswered =
        Math.max(sent - answered, 0);

    const rate =
        sent
            ? ((answered / sent) * 100).toFixed(1)
            : '0.0';

    const stats = questions.map(q => {

        if (q.type === 'text') {

            const texts =
                responses
                    .map(r => r.answers?.[q.id])
                    .filter(v => v);

            return `
<div class="bg-white border rounded-xl p-5">
<h3 class="font-bold mb-4">
${App.utils.escape(q.text)}
</h3>

<div class="space-y-2 max-h-64 overflow-auto">
${texts.length
    ? texts.map(t => `
<div class="bg-slate-50 rounded-lg p-3">
${App.utils.escape(
    Array.isArray(t)
        ? t.join(', ')
        : t
)}
</div>`).join('')
    : '<div class="text-slate-400">回答なし</div>'}
</div>
</div>`;

        }

        const total =
            responses.length || 1;

        const bars =
            (q.options || []).map(o => {

                let count = 0;

                responses.forEach(r => {

                    const v =
                        r.answers?.[q.id];

                    if (Array.isArray(v)) {
                        if (v.includes(o)) count++;
                    } else if (v === o) {
                        count++;
                    }
                });

                const pct =
                    ((count / total) * 100).toFixed(1);

                return `
<div class="mb-3">
<div class="flex justify-between text-sm">
<span>${App.utils.escape(o)}</span>
<span>${count}件 / ${pct}%</span>
</div>

<div class="h-3 bg-slate-100 rounded-full overflow-hidden">
<div
 class="h-full bg-blue-500"
 style="width:${pct}%"></div>
</div>
</div>`;
            }).join('');

        return `
<div class="bg-white border rounded-xl p-5">
<h3 class="font-bold mb-4">
${App.utils.escape(q.text)}
</h3>
${bars}
</div>`;
    }).join('');

    const table =
        responses.map(r => `
<tr class="border-b">
<td class="p-3">
${App.utils.escape(r.company)}
</td>
<td class="p-3">
${App.utils.escape(r.name)}
</td>
<td class="p-3">
${App.utils.escape(r.email)}
</td>
<td class="p-3">
${App.utils.escape(r.answered_at)}
</td>
<td class="p-3">
<button
 class="text-blue-600"
 onclick="App.actions.showResponse('${r.id}')">
全回答を表示
</button>
</td>
</tr>`).join('');

    document.getElementById('app').innerHTML =
        App.render.header(survey.title) +
        `
<main class="max-w-[1400px] mx-auto p-5">

<div class="flex justify-between items-center mb-5">
<h1 class="text-2xl font-bold">
集計・分析
</h1>

<button
 class="px-4 py-2 rounded-lg bg-white border"
 onclick="App.actions.exportCsv('${id}')">
CSV出力
</button>
</div>

<div class="grid md:grid-cols-5 gap-3 mb-6">

${[
['送信対象者数', sent + ' 人'],
['回答数', answered + ' 件'],
['未登録回答', web + ' 件'],
['未回答', unanswered + ' 人'],
['回答率', rate + ' %']
].map(x => `
<div class="bg-white border rounded-xl p-5">
<div class="text-sm text-slate-500">${x[0]}</div>
<div class="text-2xl font-bold mt-2">${x[1]}</div>
</div>`).join('')}

</div>

<div class="grid lg:grid-cols-2 gap-5 mb-8">
${stats}
</div>

<div class="bg-white border rounded-xl overflow-x-auto">
<div class="p-5">
<h2 class="font-bold text-lg">個別回答一覧</h2>
<input
 id="response_filter"
 class="border rounded-lg px-3 py-2 mt-3"
 placeholder="会社名・氏名検索"
 oninput="App.actions.filterResponses(this.value)">
</div>

<table
 id="response_table"
 class="w-full min-w-[800px] text-sm">
<thead class="bg-slate-50">
<tr>
<th class="text-left p-3">会社名</th>
<th class="text-left p-3">氏名</th>
<th class="text-left p-3">メール</th>
<th class="text-left p-3">回答日時</th>
<th class="text-left p-3">操作</th>
</tr>
</thead>
<tbody>
${table || `
<tr>
<td colspan="5" class="p-10 text-center text-slate-400">
現在、回答データはありません
</td>
</tr>`}
</tbody>
</table>
</div>

</main>`;
};

App.actions.filterResponses = function (value) {

    const rows =
        document.querySelectorAll(
            '#response_table tbody tr'
        );

    const keyword =
        value.toLowerCase();

    rows.forEach(row => {

        row.style.display =
            row.textContent
                .toLowerCase()
                .includes(keyword)
                ? ''
                : 'none';
    });
};

App.actions.showResponse = function (id) {

    const r =
        App.state.data.responses.find(
            x => x.id === id
        );

    if (!r) return;

    const survey =
        App.state.data.surveys.find(
            x => x.id === r.survey_id
        );

    const rows = [];

    survey?.groups.forEach(g => {
        g.questions.forEach(q => {

            let value =
                r.answers?.[q.id] ?? '';

            if (Array.isArray(value)) {
                value = value.join(', ');
            }

            rows.push(`
<tr class="border-b">
<td class="p-3 font-medium">
${App.utils.escape(q.text)}
</td>
<td class="p-3">
${App.utils.escape(value)}
</td>
</tr>`);
        });
    });

    document.body.insertAdjacentHTML(
        'beforeend',
        `
<div
 id="response_modal"
 class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">

<div class="bg-white rounded-2xl w-full max-w-4xl max-h-[90vh] overflow-auto">

<div class="sticky top-0 bg-white border-b p-4 flex justify-between">
<strong>回答詳細</strong>
<button
 onclick="document.getElementById('response_modal').remove()">
×
</button>
</div>

<div class="p-5">

<div class="grid md:grid-cols-3 gap-3 mb-5 text-sm">
<div>会社名: ${App.utils.escape(r.company)}</div>
<div>氏名: ${App.utils.escape(r.name)}</div>
<div>メール: ${App.utils.escape(r.email)}</div>
</div>

<table class="w-full">
<tbody>
${rows.join('')}
</tbody>
</table>

</div>
</div>
</div>`
    );
};

App.actions.exportCsv = function (id) {

    const form =
        document.createElement('form');

    form.method = 'POST';
    form.action = location.pathname;
    form.style.display = 'none';

    const add = (name, value) => {

        const input =
            document.createElement('input');

        input.type = 'hidden';
        input.name = name;
        input.value = value;

        form.appendChild(input);
    };

    add('action', 'csv');
    add('survey_id', id);
    add(
        'csrf_token',
        document.getElementById('csrf_token').value
    );

    document.body.appendChild(form);

    form.submit();
};

/* =========================================================
 * Send
 * ========================================================= */

App.render.send = function () {

    const id =
        App.state.selectedSurveyId;

    const survey =
        App.state.data.surveys.find(
            s => s.id === id
        );

    if (!survey) {
        App.render.list();
        return;
    }

    const customers =
        App.state.data.customers.filter(
            c => !c.deleted
        );

    const rows =
        customers.map(c => `
<tr class="border-b">
<td class="p-3">
<input
 type="checkbox"
 class="recipient"
 value="${App.utils.escape(c.id)}"
 ${c.source === 'web' ? 'disabled' : ''}>
</td>

<td class="p-3">
<strong>${App.utils.escape(c.company)}</strong><br>
${App.utils.escape(c.name)}<br>
<span class="text-xs text-slate-500">
${App.utils.escape(c.email)}
</span>
</td>

<td class="p-3">
${App.utils.escape(c.department)}
</td>

<td class="p-3">
${App.utils.escape(c.phone)}
</td>

<td class="p-3">
${c.answer_status === 'answered'
    ? '<span class="text-green-600">回答済み</span>'
    : '<span class="text-amber-600">未回答</span>'}
</td>

<td class="p-3">
${
c.kintone_status === 'registered'
? '<span class="text-green-600">✓ kintone登録完了</span>'
: `
<button
 class="text-blue-600 text-sm"
 onclick="App.actions.registerKintone('${c.id}')">
kintone登録完了
</button>`
}
</td>
</tr>`).join('');

    document.getElementById('app').innerHTML =
        App.render.header(survey.title) +
        `
<main class="max-w-[1400px] mx-auto p-5">

<div class="flex justify-between items-center mb-5">
<div>
<h1 class="text-2xl font-bold">
顧客選択・メール送信
</h1>
<p class="text-sm text-slate-500">
${App.utils.escape(survey.title)}
</p>
</div>
</div>

<div class="grid lg:grid-cols-[1fr_2fr] gap-5">

<div class="bg-white border rounded-xl p-5 h-fit">

<label class="block text-sm mb-1">
テンプレート
</label>

<select
 id="template_type"
 class="w-full border rounded-lg px-3 py-2 mb-4">
<option value="initial">初回送信</option>
<option value="reminder">リマインド</option>
</select>

<label class="block text-sm mb-1">
件名
</label>

<input
 id="mail_subject"
 class="w-full border rounded-lg px-3 py-2 mb-4"
 value="アンケートのお願い">

<label class="block text-sm mb-1">
本文
</label>

<textarea
 id="mail_body"
 rows="12"
 class="w-full border rounded-lg p-3">{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>

<button
 class="w-full mt-4 bg-blue-600 text-white rounded-xl py-3 font-bold"
 onclick="App.actions.sendMail('${id}')">
選択した顧客へ一括送信
</button>

</div>

<div class="bg-white border rounded-xl overflow-x-auto">

<div class="p-4 border-b">
<input
 id="customer_filter"
 class="border rounded-lg px-3 py-2"
 placeholder="顧客名・メール検索"
 oninput="App.actions.filterCustomers(this.value)">

<label class="ml-4">
<input
 id="select_all"
 type="checkbox"
 onchange="App.actions.selectAll(this.checked)">
全選択
</label>
</div>

<table id="customer_table"
 class="w-full min-w-[1000px] text-sm">
<thead class="bg-slate-50">
<tr>
<th class="p-3">選択</th>
<th class="p-3 text-left">顧客</th>
<th class="p-3 text-left">部署</th>
<th class="p-3 text-left">電話</th>
<th class="p-3 text-left">回答</th>
<th class="p-3 text-left">kintone</th>
</tr>
</thead>
<tbody>
${rows}
</tbody>
</table>
</div>

</div>
</main>`;
};

App.actions.selectAll = function (checked) {

    document
        .querySelectorAll('.recipient:not(:disabled)')
        .forEach(x => {
            x.checked = checked;
        });
};

App.actions.filterCustomers = function (value) {

    const keyword =
        value.toLowerCase();

    document
        .querySelectorAll('#customer_table tbody tr')
        .forEach(row => {

            row.style.display =
                row.textContent
                    .toLowerCase()
                    .includes(keyword)
                    ? ''
                    : 'none';
        });
};

App.actions.sendMail = async function (surveyId) {

    const ids =
        Array.from(
            document.querySelectorAll(
                '.recipient:checked'
            )
        ).map(x => x.value);

    if (!ids.length) {
        alert('送信先を選択してください。');
        return;
    }

    const already =
        App.state.data.customers.some(
            c =>
                ids.includes(String(c.id)) &&
                c.sent_at
        );

    if (
        already &&
        !confirm(
            '既に送信済みの宛先が含まれています。再送しますか？'
        )
    ) {
        return;
    }

    try {

        const result =
            await App.api.sendMail(
                surveyId,
                ids,
                document.getElementById('mail_subject').value,
                document.getElementById('mail_body').value,
                document.getElementById('template_type').value
            );

        App.state.data =
            result.data;

        App.render.send();

        App.utils.notify(
            result.sent + '件送信しました。'
        );

    } catch (e) {

        App.utils.notify(
            e.message || String(e),
            true
        );
    }
};

App.actions.registerKintone = async function (id) {

    try {

        const result =
            await App.api.registerKintone(id);

        App.state.data =
            result.data;

        App.render.send();

    } catch (e) {

        App.utils.notify(
            e.message || String(e),
            true
        );
    }
};

/* =========================================================
 * Settings
 * ========================================================= */

App.render.settings = function () {

    const s =
        App.state.data.settings || {};

    document.getElementById('app').innerHTML =
        App.render.header('kintone連携設定') +
        `
<main class="max-w-5xl mx-auto p-5">

<div class="mb-5">
<h1 class="text-2xl font-bold">
kintone連携設定
</h1>
<p class="text-sm text-slate-500">
PHPサーバーからkintoneへ接続します。
</p>
</div>

<div class="bg-white border rounded-xl p-6">

<form id="settings_form"
 onsubmit="event.preventDefault();App.actions.saveSettings()">

<div class="grid md:grid-cols-2 gap-5">

<div>
<label class="block text-sm mb-1">
サブドメイン / URL
</label>
<input
 id="setting_subdomain"
 class="w-full border rounded-lg px-3 py-2"
 value="${App.utils.escape(s.subdomain || '')}"
 placeholder="xxxx.cybozu.com">
</div>

<div>
<label class="block text-sm mb-1">
アプリID
</label>
<input
 id="setting_app_id"
 class="w-full border rounded-lg px-3 py-2"
 value="${App.utils.escape(s.app_id || '')}"
 placeholder="123">
</div>

<div>
<label class="block text-sm mb-1">
ログイン名
</label>
<input
 id="setting_login_name"
 autocomplete="off"
 class="w-full border rounded-lg px-3 py-2"
 value="${App.utils.escape(s.login_name || '')}">
</div>

<div>
<label class="block text-sm mb-1">
パスワード
</label>
<input
 id="setting_password"
 type="password"
 autocomplete="new-password"
 class="w-full border rounded-lg px-3 py-2"
 placeholder="変更しない場合は空欄">
</div>

<div>
<label class="block text-sm mb-1">
Proxy
</label>
<input
 id="setting_proxy"
 class="w-full border rounded-lg px-3 py-2"
 value="${App.utils.escape(s.proxy || '')}"
 placeholder="host:port">
</div>

<div class="flex items-center">
<label class="flex gap-2 items-center">
<input
 id="setting_ssl_verify"
 type="checkbox"
 ${s.ssl_verify !== false ? 'checked' : ''}>
SSL証明書を検証する
</label>
</div>

</div>

<div class="flex gap-3 flex-wrap mt-6">

<button
 type="button"
 class="px-4 py-2 rounded-lg border"
 onclick="App.actions.testKintone()">
接続確認
</button>

<button
 type="button"
 class="px-4 py-2 rounded-lg border"
 onclick="App.actions.fetchKintoneFields()">
項目一覧を再取得
</button>

<button
 type="submit"
 class="px-5 py-2 rounded-lg bg-blue-600 text-white">
設定を保存
</button>

</div>

<div
 id="field_message"
 class="mt-5 whitespace-pre-wrap text-sm">
</div>

</form>

<hr class="my-7">

<h2 class="font-bold text-lg mb-4">
フィールドマッピング
</h2>

<div id="field_mapping"
 class="space-y-4">
${App.render.fieldMapping(s)}
</div>

</div>
</main>`;
};

App.render.fieldMapping = function (s) {

    const fields =
        App.state.fields || [];

    const select = (
        id,
        label,
        value,
        multiple = false
    ) => {

        const selected =
            Array.isArray(value)
                ? value
                : [value || ''];

        return `
<div>
<label class="block text-sm mb-1">
${label}
</label>

<select
 ${multiple ? 'multiple size="5"' : ''}
 data-map="${id}"
 class="w-full border rounded-lg px-3 py-2">

<option value="">-- 選択 --</option>

${fields.map(f => `
<option
 value="${App.utils.escape(f.code)}"
 ${selected.includes(f.code) ? 'selected' : ''}>
${App.utils.escape(f.label)}
 [${App.utils.escape(f.code)}]
</option>`).join('')}

</select>
</div>`;
    };

    return `
${select(
    'field_company',
    '会社名',
    s.field_company
)}

${select(
    'field_name',
    '氏名',
    s.field_name
)}

${select(
    'field_email',
    'メールアドレス',
    s.field_email
)}

${select(
    'field_department',
    '部署名',
    s.field_department
)}

${select(
    'field_phone',
    '電話番号',
    s.field_phone
)}

${select(
    'field_address',
    '住所（複数選択可）',
    s.field_address,
    true
)}

<button
 class="px-4 py-2 bg-slate-900 text-white rounded-lg"
 onclick="App.actions.saveMapping()">
マッピングを保存
</button>`;
};

App.actions.getSettingsFromForm = function () {

    const old =
        App.state.data.settings || {};

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

        password:
            password || old.password || '',

        proxy:
            document.getElementById(
                'setting_proxy'
            ).value.trim(),

        ssl_verify:
            document.getElementById(
                'setting_ssl_verify'
            ).checked,

        field_company:
            old.field_company || '',

        field_name:
            old.field_name || '',

        field_email:
            old.field_email || '',

        field_department:
            old.field_department || '',

        field_phone:
            old.field_phone || '',

        field_address:
            old.field_address || []
    };
};

App.actions.saveSettings = async function () {

    try {

        const settings =
            App.actions.getSettingsFromForm();

        const result =
            await App.api.saveSettings(
                settings
            );

        App.state.data =
            result.data;

        App.utils.notify(
            '設定を保存しました。'
        );

        App.render.settings();

    } catch (e) {

        App.utils.notify(
            e.message || String(e),
            true
        );
    }
};

App.actions.testKintone = async function () {

    const msg =
        document.getElementById('field_message');

    msg.className =
        'mt-5 whitespace-pre-wrap text-sm text-blue-600';

    msg.textContent =
        'kintoneへ接続しています…';

    try {

        const result =
            await App.api.kintone(
                App.actions.getSettingsFromForm(),
                'kintone_test'
            );

        msg.className =
            'mt-5 whitespace-pre-wrap text-sm text-green-700';

        msg.textContent =
            result.message;

    } catch (e) {

        msg.className =
            'mt-5 whitespace-pre-wrap text-sm text-red-700';

        msg.textContent =
            e.message || String(e);
    }
};

/*
 * 必須関数:
 * fetchKintoneFields()
 */
App.actions.fetchKintoneFields = async function () {

    const msg =
        document.getElementById('field_message');

    msg.className =
        'mt-5 whitespace-pre-wrap text-sm text-blue-600';

    msg.textContent =
        'kintoneから項目一覧を取得しています…';

    try {

        const settings =
            App.actions.getSettingsFromForm();

        const result =
            await App.api.kintone(
                settings,
                'fetch_fields'
            );

        App.state.fields =
            result.fields || [];

        msg.className =
            'mt-5 whitespace-pre-wrap text-sm text-green-700';

        msg.textContent =
            result.message;

        const mapping =
            document.getElementById(
                'field_mapping'
            );

        if (mapping) {
            mapping.innerHTML =
                App.render.fieldMapping(
                    App.state.data.settings
                );
        }

    } catch (e) {

        msg.className =
            'mt-5 whitespace-pre-wrap text-sm text-red-700';

        msg.textContent =
            e.message || String(e);
    }
};

App.actions.saveMapping = async function () {

    try {

        const settings =
            App.actions.getSettingsFromForm();

        document
            .querySelectorAll(
                '#field_mapping [data-map]'
            )
            .forEach(select => {

                const key =
                    select.dataset.map;

                if (select.multiple) {
                    settings[key] =
                        Array.from(
                            select.selectedOptions
                        ).map(o => o.value);
                } else {
                    settings[key] =
                        select.value;
                }
            });

        const result =
            await App.api.saveSettings(
                settings
            );

        App.state.data =
            result.data;

        App.utils.notify(
            'マッピングを保存しました。'
        );

        App.render.settings();

    } catch (e) {

        App.utils.notify(
            e.message || String(e),
            true
        );
    }
};

/* =========================================================
 * Init
 * ========================================================= */

App.actions.route = function () {

    switch (App.state.page) {

        case 'edit':
            App.render.edit();
            break;

        case 'settings':
            App.render.settings();
            break;

        case 'send':
            App.render.send();
            break;

        case 'results':
            App.render.results();
            break;

        default:
            App.render.list();
    }
};

App.init = async function () {

    if (App.__loaded) {
        return;
    }

    App.__loaded = true;

    const app =
        document.getElementById('app');

    if (app) {
        app.innerHTML = `
<div class="min-h-screen flex items-center justify-center">
<div class="text-slate-500">
読み込み中…
</div>
</div>`;
    }

    try {

        await App.api.load();

        App.state.page = 'list';

        App.render.list();

    } catch (e) {

        console.error(
            'App.init:',
            e
        );

        if (app) {

            app.innerHTML = `
<div class="min-h-screen flex items-center justify-center p-5">
<div class="bg-white border border-red-200 rounded-2xl p-8 max-w-xl">
<h1 class="font-bold text-red-600 text-xl mb-3">
アプリの初期化に失敗しました
</h1>
<pre class="text-sm whitespace-pre-wrap text-slate-600">${App.utils.escape(
    e.message || String(e)
)}</pre>
<button
 class="mt-5 px-4 py-2 bg-blue-600 text-white rounded-lg"
 onclick="location.reload()">
再読み込み
</button>
</div>
</div>`;
        }
    }
};

/*
 * 指定された安全な初期化方式。
 */
if (document.readyState === 'loading') {

    document.addEventListener(
        'DOMContentLoaded',
        function () {

            if (!App.__initialized) {
                App.__initialized = true;
                App.init();
            }
        },
        { once: true }
    );

} else {

    if (!App.__initialized) {
        App.__initialized = true;
        App.init();
    }
}
</script>

</body>
</html>

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
- branching

分岐項目:
- option
- target_question_id

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
- kintone_record_id

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

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

/* =========================================================
 * 基本
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

    if (@file_put_contents(
        $tmp,
        survey_json($data),
        LOCK_EX
    ) === false) {
        return false;
    }

    return @rename($tmp, SURVEY_STORAGE_FILE);
}

function survey_api(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo survey_json($data);
    exit;
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
    return isset($_POST['csrf_token'])
        && isset($_SESSION['csrf_token'])
        && hash_equals(
            (string)$_SESSION['csrf_token'],
            (string)$_POST['csrf_token']
        );
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
            'error' => 'kintoneホストを取得できません。'
        ];
    }

    $hostOnly = preg_replace('/:\d+$/', '', $host);

    if (
        !preg_match(
            '~^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.cybozu\.com$~i',
            (string)$hostOnly
        ) &&
        !preg_match(
            '~^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$~i',
            (string)$hostOnly
        )
    ) {
        return [
            'ok' => false,
            'error' => '許可されていないkintoneホスト名です。'
        ];
    }

    return [
        'ok' => true,
        'base' => 'https://' . $host,
        'host' => $hostOnly
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
            'value' => ''
        ];
    }

    if (
        !preg_match(
            '~^(?:(https?)://)?([^/:?#\s]+):([0-9]{1,5})$~i',
            $input,
            $m
        )
    ) {
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
        'value' => 'tcp://' . strtolower($m[2]) . ':' . $port
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

            if (is_array($h)) {
                return $h;
            }
        } catch (Throwable) {
        }
    }

    $h = $GLOBALS['http_response_header'] ?? null;

    return is_array($h) ? $h : [];
}

function survey_status_from_headers(array $headers): int
{
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

    $proxyInfo = survey_parse_proxy($proxy);

    if (!$proxyInfo['ok']) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' => $proxyInfo['error'],
            'url' => $url,
            'proxy_used' => true
        ];
    }

    $parsed = @parse_url($url);

    if (!is_array($parsed) || empty($parsed['host'])) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' => '接続先URLが不正です。',
            'url' => $url,
            'proxy_used' => $proxyInfo['used']
        ];
    }

    $http = [
        'method' => strtoupper($method),
        'timeout' => 30,
        'ignore_errors' => true,
        'protocol_version' => 1.1,
        'header' => implode("\r\n", $headers)
    ];

    if (
        $content !== null &&
        strtoupper($method) !== 'GET'
    ) {
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
            'peer_name' => (string)$parsed['host']
        ]
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

    $bodyText = is_string($body) ? $body : '';
    $json = json_decode($bodyText, true);

    if ($status === 0) {
        return [
            'status' => 0,
            'body' => $bodyText,
            'json' => $json,
            'error' =>
                ($warning !== '' ? $warning : 'HTTPレスポンスを取得できませんでした。')
                . "\n確認事項: DNS、外部HTTPS通信、Proxy、ファイアウォール、SSL/TLS、OpenSSL、タイムアウト。",
            'url' => $url,
            'proxy_used' => $proxyInfo['used']
        ];
    }

    return [
        'status' => $status,
        'body' => $bodyText,
        'json' => $json,
        'error' => $warning,
        'url' => $url,
        'proxy_used' => $proxyInfo['used']
    ];
}

/* =========================================================
 * kintone API
 * ========================================================= */

function survey_kintone_request(
    array $settings,
    string $path,
    string $method = 'GET',
    ?array $payload = null,
    array $query = []
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
            'proxy_used' => false
        ];
    }

    $appId = trim(
        (string)($settings['app_id'] ?? '')
    );

    if (
        $appId === '' ||
        !preg_match('/^[0-9]+$/', $appId)
    ) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' => 'アプリIDは数字で入力してください。',
            'url' => '',
            'proxy_used' => false
        ];
    }

    $query = array_merge(
        ['app' => $appId],
        $query
    );

    $qs = http_build_query(
        $query,
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    $url =
        $normalized['base']
        . '/k/v1/'
        . ltrim($path, '/')
        . '?'
        . $qs;

    $auth = base64_encode(
        (string)($settings['login_name'] ?? '')
        . ':'
        . (string)($settings['password'] ?? '')
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $auth,
        'Accept: application/json',
        'Connection: close'
    ];

    $content = null;

    if ($payload !== null) {
        $content = survey_json($payload);
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
            . "\n確認事項: サーバーからの外部HTTPS通信、DNS、Proxy、ファイアウォール、SSL設定";
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
        return 'kintone通信がタイムアウトしました。';
    }

    if ($status === 429) {
        return 'kintone側のレート制限です。';
    }

    if ($status >= 500) {
        return
            "kintoneまたはProxy側のサーバーエラーです。HTTPステータス: {$status}";
    }

    if ($status >= 200 && $status < 300) {
        return
            "kintone通信に成功しました。HTTPステータス: {$status}";
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

function fetchKintoneFields(array $settings): array
{
    $r = survey_kintone_request(
        $settings,
        'app/form/fields.json'
    );

    if (
        (int)$r['status'] < 200 ||
        (int)$r['status'] >= 300
    ) {
        return [
            'ok' => false,
            'fields' => [],
            'message' => survey_kintone_message($r)
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
                'kintoneレスポンスにpropertiesがありません。'
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
                (string)($property['type'] ?? '')
        ];
    }

    return [
        'ok' => true,
        'fields' => $fields,
        'message' => '項目一覧を取得しました。'
    ];
}

/* =========================================================
 * kintone value
 * ========================================================= */

function survey_kintone_value(
    array $record,
    string $code
): string {

    if (
        $code === '' ||
        !isset($record[$code])
    ) {
        return '';
    }

    $v = $record[$code]['value'] ?? '';

    if (is_array($v)) {
        $values = [];

        foreach ($v as $item) {
            if (is_array($item)) {
                $values[] =
                    (string)($item['value'] ?? '');
            } else {
                $values[] = (string)$item;
            }
        }

        return implode(' ', $values);
    }

    return (string)$v;
}

/* =========================================================
 * kintone records — 全件ページング
 * ========================================================= */

function survey_kintone_all_records(
    array $settings
): array {

    $all = [];
    $offset = 0;
    $limit = 500;

    while (true) {

        $r = survey_kintone_request(
            $settings,
            'records.json',
            'GET',
            null,
            [
                'query' => 'order by $id asc limit '
                    . $limit
                    . ' offset '
                    . $offset
            ]
        );

        if (
            (int)$r['status'] < 200 ||
            (int)$r['status'] >= 300
        ) {
            return [
                'ok' => false,
                'records' => [],
                'message' =>
                    survey_kintone_message($r)
            ];
        }

        $records =
            $r['json']['records'] ?? null;

        if (!is_array($records)) {
            return [
                'ok' => false,
                'records' => [],
                'message' =>
                    'kintone APIレスポンスにrecordsがありません。'
            ];
        }

        $all = array_merge(
            $all,
            $records
        );

        if (count($records) < $limit) {
            break;
        }

        $offset += $limit;

        if ($offset > 100000) {
            return [
                'ok' => false,
                'records' => [],
                'message' =>
                    'kintone取得件数が安全上限を超えました。'
            ];
        }
    }

    return [
        'ok' => true,
        'records' => $all,
        'message' =>
            count($all) . '件取得しました。'
    ];
}

/* =========================================================
 * kintone customer sync
 *
 * 重要:
 * メールアドレスを配列キーにしない。
 * kintoneのレコードIDを顧客IDとして使用する。
 * 同一メールアドレスの複数レコードを保持する。
 * ========================================================= */

function survey_sync_customers(
    array &$data
): array {

    $settings = $data['settings'];

    $fields = fetchKintoneFields($settings);

    if (!$fields['ok']) {
        return [
            'ok' => false,
            'message' => $fields['message'],
            'count' => 0
        ];
    }

    $result =
        survey_kintone_all_records(
            $settings
        );

    if (!$result['ok']) {
        return [
            'ok' => false,
            'message' => $result['message'],
            'count' => 0
        ];
    }

    $records = $result['records'];
    $map = $settings;

    /*
     * 既存顧客はIDで管理。
     * emailでは絶対に一意化しない。
     */
    $existing = [];

    foreach ($data['customers'] as $customer) {
        if (!is_array($customer)) {
            continue;
        }

        $id = (string)($customer['id'] ?? '');

        if ($id !== '') {
            $existing[$id] = $customer;
        }
    }

    $count = 0;
    $newCount = 0;
    $updateCount = 0;

    foreach ($records as $record) {

        if (!is_array($record)) {
            continue;
        }

        $recordId =
            survey_kintone_value(
                $record,
                '$id'
            );

        /*
         * APIの$idが取得できない環境にも対応。
         */
        if ($recordId === '') {
            $recordId =
                survey_kintone_value(
                    $record,
                    'レコード番号'
                );
        }

        if ($recordId === '') {
            $recordId = survey_id();
        }

        $customerId =
            'kintone_' . $recordId;

        $email = trim(
            survey_kintone_value(
                $record,
                (string)$map['field_email']
            )
        );

        if (
            $email === '' ||
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            continue;
        }

        $old =
            $existing[$customerId] ?? [];

        $customer = [
            'id' => $customerId,
            'company' =>
                survey_kintone_value(
                    $record,
                    (string)$map['field_company']
                ),
            'name' =>
                survey_kintone_value(
                    $record,
                    (string)$map['field_name']
                ),
            'email' => $email,
            'department' =>
                survey_kintone_value(
                    $record,
                    (string)$map['field_department']
                ),
            'phone' =>
                survey_kintone_value(
                    $record,
                    (string)$map['field_phone']
                ),
            'address' => '',
            'source' => 'kintone',
            'sent_at' =>
                $old['sent_at'] ?? null,
            'send_count' =>
                (int)($old['send_count'] ?? 0),
            'answer_status' =>
                $old['answer_status']
                ?? 'unanswered',
            'kintone_status' => 'registered',
            'kintone_record_id' => $recordId
        ];

        $addresses =
            $map['field_address'] ?? [];

        if (!is_array($addresses)) {
            $addresses = [$addresses];
        }

        $parts = [];

        foreach ($addresses as $code) {
            $value =
                survey_kintone_value(
                    $record,
                    (string)$code
                );

            if ($value !== '') {
                $parts[] = $value;
            }
        }

        $customer['address'] =
            implode(' ', $parts);

        if (isset($existing[$customerId])) {
            $updateCount++;
        } else {
            $newCount++;
        }

        $existing[$customerId] =
            $customer;

        $count++;
    }

    $data['customers'] =
        array_values($existing);

    return [
        'ok' => true,
        'message' =>
            "kintone顧客同期完了\n"
            . "取得: {$count}件\n"
            . "新規: {$newCount}件\n"
            . "更新: {$updateCount}件",
        'count' => $count
    ];
}

/* =========================================================
 * Mail
 * ========================================================= */

function survey_mail_send(
    string $to,
    string $subject,
    string $body
): array {

    $to = trim($to);

    if (
        $to === '' ||
        !filter_var(
            $to,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        return [
            'ok' => false,
            'message' =>
                'メールアドレスが不正です。'
        ];
    }

    $subject =
        str_replace(
            ["\r", "\n"],
            '',
            $subject
        );

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit'
    ];

    $from =
        trim(
            (string)(
                $_SERVER['SERVER_ADMIN']
                ?? ''
            )
        );

    if (
        $from !== '' &&
        filter_var(
            $from,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $headers[] =
            'From: ' . $from;
    }

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
        $ok = mail(
            $to,
            $subject,
            $body,
            implode(
                "\r\n",
                $headers
            )
        );
    } catch (Throwable $e) {
        $ok = false;
        $warning = $e->getMessage();
    }

    restore_error_handler();

    return [
        'ok' => (bool)$ok,
        'message' => $ok
            ? 'メールを送信しました。'
            : (
                $warning !== ''
                ? $warning
                : 'メール送信に失敗しました。'
            )
    ];
}

/*
 * 旧コードからの名称揺れ対策。
 */
function survey_mail_sand(
    string $to,
    string $subject,
    string $body
): array {
    return survey_mail_send(
        $to,
        $subject,
        $body
    );
}

/* =========================================================
 * Survey helpers
 * ========================================================= */

function survey_find(
    array $data,
    string $id
): ?array {

    foreach ($data['surveys'] as $survey) {
        if (
            (string)($survey['id'] ?? '')
            === $id
        ) {
            return $survey;
        }
    }

    return null;
}

function survey_questions(
    array $survey
): array {

    $result = [];

    foreach (
        ($survey['groups'] ?? [])
        as $group
    ) {
        foreach (
            ($group['questions'] ?? [])
            as $question
        ) {
            if (is_array($question)) {
                $result[] = $question;
            }
        }
    }

    return $result;
}

function survey_question_index(
    array $survey
): array {

    $index = [];

    foreach (
        survey_questions($survey)
        as $question
    ) {
        $id =
            (string)($question['id'] ?? '');

        if ($id !== '') {
            $index[$id] = $question;
        }
    }

    return $index;
}

/*
 * 保存時に存在しない分岐先を削除。
 */
function survey_clean_branching(
    array $survey
): array {

    $questionIndex =
        survey_question_index(
            $survey
        );

    foreach (
        $survey['groups'] ?? []
        as &$group
    ) {
        foreach (
            $group['questions'] ?? []
            as &$question
        ) {

            if (
                !isset($question['branching']) ||
                !is_array(
                    $question['branching']
                )
            ) {
                $question['branching'] = [];
                continue;
            }

            $clean = [];

            foreach (
                $question['branching']
                as $branch
            ) {
                if (!is_array($branch)) {
                    continue;
                }

                $target =
                    (string)(
                        $branch['target_question_id']
                        ?? ''
                    );

                $option =
                    (string)(
                        $branch['option']
                        ?? ''
                    );

                if (
                    $option !== '' &&
                    $target !== '' &&
                    isset(
                        $questionIndex[$target]
                    ) &&
                    $target !==
                        (string)$question['id']
                ) {
                    $clean[] = [
                        'option' => $option,
                        'target_question_id' =>
                            $target
                    ];
                }
            }

            $question['branching'] = $clean;
        }
    }

    unset(
        $group,
        $question
    );

    return $survey;
}

/* =========================================================
 * API actions
 * ========================================================= */

$action =
    (string)(
        $_POST['action']
        ?? $_GET['action']
        ?? ''
    );

if ($action !== '') {

    if (
        in_array(
            $action,
            [
                'save_survey',
                'delete_survey',
                'duplicate_survey',
                'stop_survey',
                'save_settings',
                'sync_kintone',
                'send_mail',
                'save_response'
            ],
            true
        ) &&
        !survey_check_token()
    ) {
        survey_api([
            'ok' => false,
            'message' =>
                'CSRFトークンが不正です。'
        ], 403);
    }

    $data =
        survey_read_data();

    /* -------------------------
     * GET data
     * ------------------------- */

    if ($action === 'get_data') {

        survey_api([
            'ok' => true,
            'data' => $data,
            'csrf_token' => survey_token()
        ]);
    }

    if ($action === 'get_fields') {

        $settings = $data['settings'];

        if (
            isset($_POST['settings_json'])
        ) {
            $tmp =
                json_decode(
                    (string)$_POST['settings_json'],
                    true
                );

            if (is_array($tmp)) {
                $settings =
                    array_replace(
                        $settings,
                        $tmp
                    );
            }
        }

        $result =
            fetchKintoneFields(
                $settings
            );

        survey_api($result);
    }

    /* -------------------------
     * Save settings
     * ------------------------- */

    if ($action === 'save_settings') {

        $json =
            (string)(
                $_POST['settings_json']
                ?? ''
            );

        $settings =
            json_decode(
                $json,
                true
            );

        if (!is_array($settings)) {
            survey_api([
                'ok' => false,
                'message' =>
                    'settings_jsonが不正です。'
            ], 400);
        }

        $settings =
            array_replace(
                $data['settings'],
                $settings
            );

        $settings['subdomain'] =
            trim(
                (string)(
                    $settings['subdomain']
                    ?? ''
                )
            );

        $settings['login_name'] =
            trim(
                (string)(
                    $settings['login_name']
                    ?? ''
                )
            );

        /*
         * パスワードは既存値を維持可能。
         */
        if (
            !isset($settings['password'])
        ) {
            $settings['password'] =
                $data['settings']['password']
                ?? '';
        }

        $data['settings'] =
            $settings;

        if (!survey_write_data($data)) {
            survey_api([
                'ok' => false,
                'message' =>
                    '設定ファイルを書き込めません。'
            ], 500);
        }

        survey_api([
            'ok' => true,
            'message' =>
                '設定を保存しました。'
        ]);
    }

    /* -------------------------
     * kintone connection test
     * ------------------------- */

    if ($action === 'test_kintone') {

        $settings =
            $data['settings'];

        if (
            isset($_POST['settings_json'])
        ) {
            $tmp =
                json_decode(
                    (string)$_POST['settings_json'],
                    true
                );

            if (is_array($tmp)) {
                $settings =
                    array_replace(
                        $settings,
                        $tmp
                    );
            }
        }

        $r =
            survey_kintone_request(
                $settings,
                'app/form/fields.json'
            );

        survey_api([
            'ok' =>
                $r['status'] >= 200 &&
                $r['status'] < 300,
            'status' =>
                $r['status'],
            'message' =>
                survey_kintone_message($r),
            'url' =>
                $r['url'],
            'proxy_used' =>
                $r['proxy_used']
        ]);
    }

    /* -------------------------
     * Sync
     * ------------------------- */

    if ($action === 'sync_kintone') {

        $result =
            survey_sync_customers(
                $data
            );

        if ($result['ok']) {
            survey_write_data(
                $data
            );
        }

        survey_api($result);
    }

    /* -------------------------
     * Save survey
     * ------------------------- */

    if ($action === 'save_survey') {

        $json =
            (string)(
                $_POST['survey_json']
                ?? ''
            );

        $survey =
            json_decode(
                $json,
                true
            );

        if (!is_array($survey)) {
            survey_api([
                'ok' => false,
                'message' =>
                    'survey_jsonが不正です。'
            ], 400);
        }

        $survey['id'] =
            (string)(
                $survey['id']
                ?? survey_id()
            );

        $survey['title'] =
            trim(
                (string)(
                    $survey['title']
                    ?? ''
                )
            );

        $survey['status'] =
            (string)(
                $survey['status']
                ?? 'draft'
            );

        if (
            !in_array(
                $survey['status'],
                [
                    'draft',
                    'active',
                    'ended'
                ],
                true
            )
        ) {
            $survey['status'] =
                'draft';
        }

        $survey['groups'] =
            is_array(
                $survey['groups']
                ?? null
            )
            ? $survey['groups']
            : [];

        $old = null;

        foreach (
            $data['surveys']
            as $i => $s
        ) {
            if (
                (string)($s['id'] ?? '')
                === $survey['id']
            ) {
                $old = $s;

                $survey['created_at'] =
                    $s['created_at']
                    ?? survey_now();

                $survey['updated_at'] =
                    survey_now();

                $data['surveys'][$i] =
                    survey_clean_branching(
                        $survey
                    );

                break;
            }
        }

        if ($old === null) {

            $survey['created_at'] =
                survey_now();

            $survey['updated_at'] =
                survey_now();

            $survey['deleted'] =
                false;

            $data['surveys'][] =
                survey_clean_branching(
                    $survey
                );
        }

        if (!survey_write_data($data)) {
            survey_api([
                'ok' => false,
                'message' =>
                    'アンケート保存に失敗しました。'
            ], 500);
        }

        survey_api([
            'ok' => true,
            'survey_id' =>
                $survey['id'],
            'message' =>
                'アンケートを保存しました。'
        ]);
    }

    /* -------------------------
     * Duplicate
     * ------------------------- */

    if ($action === 'duplicate_survey') {

        $id =
            (string)(
                $_POST['survey_id']
                ?? ''
            );

        $survey =
            survey_find(
                $data,
                $id
            );

        if ($survey === null) {
            survey_api([
                'ok' => false,
                'message' =>
                    'アンケートがありません。'
            ], 404);
        }

        $copy = $survey;

        $copy['id'] =
            survey_id();

        $copy['title'] =
            (string)(
                $copy['title']
                ?? ''
            )
            . '（コピー）';

        $copy['status'] =
            'draft';

        $copy['created_at'] =
            survey_now();

        $copy['updated_at'] =
            survey_now();

        $copy['deleted'] =
            false;

        foreach (
            $copy['groups']
            as &$group
        ) {

            $group['id'] =
                survey_id();

            foreach (
                $group['questions']
                as &$question
            ) {

                $oldId =
                    (string)$question['id'];

                $newId =
                    survey_id();

                $question['id'] =
                    $newId;

                foreach (
                    $question['branching']
                    ?? []
                    as &$branch
                ) {
                    /*
                     * 後段で全IDを再マッピング。
                     */
                    $branch['_old_target'] =
                        (string)(
                            $branch[
                                'target_question_id'
                            ] ?? ''
                        );
                }

                unset($branch);

                $question['_old_id'] =
                    $oldId;
            }

            unset($question);
        }

        unset($group);

        /*
         * コピー時の分岐IDを再構築。
         */
        $idMap = [];

        foreach (
            $copy['groups']
            as $group
        ) {
            foreach (
                $group['questions']
                as $question
            ) {
                if (
                    isset(
                        $question['_old_id']
                    )
                ) {
                    $idMap[
                        $question['_old_id']
                    ] =
                        $question['id'];
                }
            }
        }

        foreach (
            $copy['groups']
            as &$group
        ) {
            foreach (
                $group['questions']
                as &$question
            ) {

                foreach (
                    $question['branching']
                    ?? []
                    as &$branch
                ) {

                    $oldTarget =
                        (string)(
                            $branch[
                                '_old_target'
                            ] ?? ''
                        );

                    $branch[
                        'target_question_id'
                    ] =
                        $idMap[$oldTarget]
                        ?? '';

                    unset(
                        $branch['_old_target']
                    );
                }

                unset(
                    $branch,
                    $question['_old_id']
                );
            }
        }

        unset(
            $group,
            $question
        );

        $data['surveys'][] =
            survey_clean_branching(
                $copy
            );

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'survey_id' =>
                $copy['id'],
            'message' =>
                'アンケートを複製しました。'
        ]);
    }

    /* -------------------------
     * Stop
     * ------------------------- */

    if ($action === 'stop_survey') {

        $id =
            (string)(
                $_POST['survey_id']
                ?? ''
            );

        foreach (
            $data['surveys']
            as &$survey
        ) {
            if (
                (string)($survey['id'] ?? '')
                === $id
            ) {
                $survey['status'] =
                    'ended';

                $survey['updated_at'] =
                    survey_now();
            }
        }

        unset($survey);

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'message' =>
                'アンケートを停止しました。'
        ]);
    }

    /* -------------------------
     * Delete
     * ------------------------- */

    if ($action === 'delete_survey') {

        $id =
            (string)(
                $_POST['survey_id']
                ?? ''
            );

        foreach (
            $data['surveys']
            as &$survey
        ) {
            if (
                (string)($survey['id'] ?? '')
                === $id
            ) {
                $survey['deleted'] =
                    true;

                $survey['updated_at'] =
                    survey_now();
            }
        }

        unset($survey);

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'message' =>
                '削除しました。'
        ]);
    }

    /* -------------------------
     * Send mail
     * ------------------------- */

    if ($action === 'send_mail') {

        $ids =
            json_decode(
                (string)(
                    $_POST['recipient_ids']
                    ?? '[]'
                ),
                true
            );

        if (!is_array($ids)) {
            survey_api([
                'ok' => false,
                'message' =>
                    'recipient_idsが不正です。'
            ], 400);
        }

        $surveyId =
            (string)(
                $_POST['survey_id']
                ?? ''
            );

        $subject =
            (string)(
                $_POST['mail_subject']
                ?? ''
            );

        $body =
            (string)(
                $_POST['mail_body']
                ?? ''
            );

        $sent = 0;
        $failed = 0;
        $details = [];

        foreach ($ids as $id) {

            $id = (string)$id;

            foreach (
                $data['customers']
                as &$customer
            ) {

                if (
                    (string)(
                        $customer['id']
                        ?? ''
                    ) !== $id
                ) {
                    continue;
                }

                $customerName =
                    (string)(
                        $customer['name']
                        ?? ''
                    );

                $personalUrl =
                    rtrim(
                        (
                            (
                                isset($_SERVER['HTTPS'])
                                &&
                                $_SERVER['HTTPS']
                                !== 'off'
                            )
                            ? 'https://'
                            : 'http://'
                        )
                        . (
                            $_SERVER['HTTP_HOST']
                            ?? 'localhost'
                        )
                        . dirname(
                            $_SERVER['SCRIPT_NAME']
                            ?? '/'
                        ),
                        '/'
                    )
                    . '/?survey='
                    . rawurlencode(
                        $surveyId
                    )
                    . '&customer='
                    . rawurlencode(
                        $id
                    );

                $mailSubject =
                    str_replace(
                        [
                            '{顧客名}',
                            '{アンケートURL}'
                        ],
                        [
                            $customerName,
                            $personalUrl
                        ],
                        $subject
                    );

                $mailBody =
                    str_replace(
                        [
                            '{顧客名}',
                            '{アンケートURL}'
                        ],
                        [
                            $customerName,
                            $personalUrl
                        ],
                        $body
                    );

                $result =
                    survey_mail_send(
                        (string)(
                            $customer['email']
                            ?? ''
                        ),
                        $mailSubject,
                        $mailBody
                    );

                if ($result['ok']) {

                    $customer['sent_at'] =
                        survey_now();

                    $customer['send_count'] =
                        (int)(
                            $customer['send_count']
                            ?? 0
                        ) + 1;

                    $customer['answer_status'] =
                        'unanswered';

                    $sent++;

                    $data['mail_logs'][] = [
                        'id' => survey_id(),
                        'survey_id' =>
                            $surveyId,
                        'customer_id' =>
                            $id,
                        'sent_at' =>
                            survey_now(),
                        'template_type' =>
                            (string)(
                                $_POST[
                                    'template_type'
                                ]
                                ?? 'initial'
                            ),
                        'subject' =>
                            $mailSubject,
                        'body' =>
                            $mailBody,
                        'ok' => true
                    ];

                } else {

                    $failed++;

                    $details[] = [
                        'customer_id' =>
                            $id,
                        'message' =>
                            $result['message']
                    ];
                }

                break;
            }
        }

        unset($customer);

        survey_write_data($data);

        survey_api([
            'ok' => $failed === 0,
            'sent' => $sent,
            'failed' => $failed,
            'details' => $details,
            'message' =>
                "送信: {$sent}件 / 失敗: {$failed}件"
        ]);
    }

    /* -------------------------
     * Save response
     * ------------------------- */

    if ($action === 'save_response') {

        $surveyId =
            (string)(
                $_POST['survey_id']
                ?? ''
            );

        $survey =
            survey_find(
                $data,
                $surveyId
            );

        if ($survey === null) {
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

        $customerId =
            (string)(
                $_POST['customer_id']
                ?? ''
            );

        $customer = null;

        foreach (
            $data['customers']
            as $c
        ) {
            if (
                (string)(
                    $c['id'] ?? ''
                ) === $customerId
            ) {
                $customer = $c;
                break;
            }
        }

        $email =
            (string)(
                $_POST['email']
                ?? ($customer['email'] ?? '')
            );

        $response = [
            'id' => survey_id(),
            'survey_id' => $surveyId,
            'customer_id' => $customerId,
            'company' =>
                (string)(
                    $customer['company']
                    ?? $_POST['company']
                    ?? ''
                ),
            'name' =>
                (string)(
                    $customer['name']
                    ?? $_POST['name']
                    ?? ''
                ),
            'email' => $email,
            'answered_at' =>
                survey_now(),
            'answers' => $answers
        ];

        $data['responses'][] =
            $response;

        if ($customerId !== '') {
            foreach (
                $data['customers']
                as &$c
            ) {
                if (
                    (string)(
                        $c['id'] ?? ''
                    ) === $customerId
                ) {
                    $c['answer_status'] =
                        'answered';
                }
            }

            unset($c);
        }

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'response_id' =>
                $response['id'],
            'message' =>
                '回答を保存しました。'
        ]);
    }

    /* -------------------------
     * CSV
     * ------------------------- */

    if ($action === 'csv') {

        $surveyId =
            (string)(
                $_GET['survey_id']
                ?? ''
            );

        $survey =
            survey_find(
                $data,
                $surveyId
            );

        if ($survey === null) {
            http_response_code(404);
            exit;
        }

        $questions =
            survey_questions(
                $survey
            );

        header(
            'Content-Type: text/csv; charset=UTF-8'
        );

        header(
            'Content-Disposition: attachment; filename="survey.csv"'
        );

        echo "\xEF\xBB\xBF";

        $fp = fopen(
            'php://output',
            'w'
        );

        $header = [
            '回答ID',
            '回答日時',
            '顧客ID',
            '会社名',
            '氏名',
            'メールアドレス'
        ];

        foreach (
            $questions
            as $question
        ) {
            $header[] =
                (string)(
                    $question['text']
                    ?? ''
                );
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
                $response['email'] ?? ''
            ];

            $answers =
                is_array(
                    $response['answers']
                    ?? null
                )
                ? $response['answers']
                : [];

            foreach (
                $questions
                as $question
            ) {
                $qid =
                    (string)(
                        $question['id']
                        ?? ''
                    );

                $value =
                    $answers[$qid]
                    ?? '';

                if (is_array($value)) {
                    $value =
                        implode(
                            ' / ',
                            array_map(
                                'strval',
                                $value
                            )
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

    survey_api([
        'ok' => false,
        'message' =>
            'Unknown action.'
    ], 400);
}

/* =========================================================
 * Public survey mode
 * ========================================================= */

$data =
    survey_read_data();

$publicSurveyId =
    (string)(
        $_GET['survey']
        ?? ''
    );

if ($publicSurveyId !== ''):

    $publicSurvey =
        survey_find(
            $data,
            $publicSurveyId
        );

    if (
        $publicSurvey === null ||
        !empty($publicSurvey['deleted'])
    ):
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<title>アンケート</title>
</head>
<body class="bg-gray-50 min-h-screen">
<div class="max-w-3xl mx-auto p-6">
<div class="bg-white rounded-2xl shadow p-8">
<h1 class="text-xl font-bold">アンケートが見つかりません</h1>
</div>
</div>
</body>
</html>
<?php
        exit;
    endif;

    $customerId =
        (string)(
            $_GET['customer']
            ?? ''
        );

    $publicCustomer = null;

    foreach (
        $data['customers']
        as $customer
    ) {
        if (
            (string)(
                $customer['id'] ?? ''
            ) === $customerId
        ) {
            $publicCustomer =
                $customer;
            break;
        }
    }

    $publicQuestions =
        survey_questions(
            $publicSurvey
        );

    $publicJson =
        survey_json([
            'survey' =>
                $publicSurvey,
            'customer' =>
                $publicCustomer
        ]);

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<title><?= survey_h($publicSurvey['title'] ?? 'アンケート') ?></title>
</head>
<body class="bg-gray-50 min-h-screen text-gray-800">
<div id="app" class="max-w-3xl mx-auto p-4 md:p-8"></div>

<script>
window.App = {
    state: {
        data: <?= $publicJson ?>,
        answers: {},
        submitting: false
    },

    util: {
        esc(value) {
            const div = document.createElement('div');
            div.textContent = value == null ? '' : String(value);
            return div.innerHTML;
        },

        questions() {
            const survey = App.state.data.survey;
            const result = [];

            (survey.groups || []).forEach(group => {
                (group.questions || []).forEach(q => {
                    result.push(q);
                });
            });

            return result;
        },

        visible(question) {
            const branching = App.util.questions()
                .flatMap(q => q.branching || []);

            let hasRule = false;

            for (const q of App.util.questions()) {
                for (const b of (q.branching || [])) {
                    if (b.target_question_id === question.id) {
                        hasRule = true;

                        const answer =
                            App.state.answers[q.id];

                        if (Array.isArray(answer)) {
                            if (answer.includes(b.option)) {
                                return true;
                            }
                        } else if (
                            String(answer || '') ===
                            String(b.option)
                        ) {
                            return true;
                        }
                    }
                }
            }

            return !hasRule;
        }
    },

    render: {
        survey() {
            const survey =
                App.state.data.survey;

            let number = 0;

            let html = `
                <div class="mb-8">
                    <h1 class="text-2xl font-bold">
                        ${App.util.esc(survey.title)}
                    </h1>
                    <p class="text-gray-500 mt-2">
                        以下の項目にご回答ください。
                    </p>
                </div>
            `;

            (survey.groups || []).forEach(group => {

                html += `
                    <section class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                        <h2 class="font-bold text-lg border-b pb-3 mb-5">
                            ${App.util.esc(group.name || '')}
                        </h2>
                `;

                (group.questions || []).forEach(question => {

                    number++;

                    if (!App.util.visible(question)) {
                        return;
                    }

                    const value =
                        App.state.answers[question.id];

                    html += `
                        <div class="mb-7" data-question="${App.util.esc(question.id)}">
                            <div class="font-semibold mb-3">
                                Q${number}.
                                ${App.util.esc(question.text)}
                                ${
                                    question.required
                                    ? '<span class="text-red-500 ml-1">必須</span>'
                                    : ''
                                }
                            </div>
                    `;

                    if (question.type === 'text') {

                        html += `
                            <textarea
                                class="w-full border rounded-xl p-3"
                                rows="4"
                                onchange="App.actions.answer('${question.id}', this.value)"
                            >${App.util.esc(value || '')}</textarea>
                        `;

                    } else if (question.type === 'multiple') {

                        (question.options || [])
                            .forEach(option => {

                                const checked =
                                    Array.isArray(value) &&
                                    value.includes(option);

                                html += `
                                    <label class="flex items-center gap-3 mb-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            value="${App.util.esc(option)}"
                                            ${checked ? 'checked' : ''}
                                            onchange="App.actions.multi('${question.id}', this)"
                                            class="w-4 h-4"
                                        >
                                        <span>${App.util.esc(option)}</span>
                                    </label>
                                `;
                            });

                    } else {

                        (question.options || [])
                            .forEach(option => {

                                const checked =
                                    String(value || '') ===
                                    String(option);

                                html += `
                                    <label class="flex items-center gap-3 mb-2 cursor-pointer">
                                        <input
                                            type="radio"
                                            name="q_${App.util.esc(question.id)}"
                                            value="${App.util.esc(option)}"
                                            ${checked ? 'checked' : ''}
                                            onchange="App.actions.answer('${question.id}', this.value)"
                                            class="w-4 h-4"
                                        >
                                        <span>${App.util.esc(option)}</span>
                                    </label>
                                `;
                            });
                    }

                    html += `</div>`;
                });

                html += `</section>`;
            });

            html += `
                <button
                    onclick="App.actions.submit()"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 rounded-xl"
                >
                    回答を送信する
                </button>
            `;

            document.getElementById('app').innerHTML =
                html;
        }
    },

    actions: {

        answer(id, value) {
            App.state.answers[id] =
                value;

            App.render.survey();
        },

        multi(id, element) {

            let values =
                Array.isArray(
                    App.state.answers[id]
                )
                ? App.state.answers[id]
                : [];

            if (element.checked) {
                if (!values.includes(element.value)) {
                    values.push(element.value);
                }
            } else {
                values =
                    values.filter(
                        v => v !== element.value
                    );
            }

            App.state.answers[id] =
                values;

            App.render.survey();
        },

        async submit() {

            if (App.state.submitting) {
                return;
            }

            const survey =
                App.state.data.survey;

            for (
                const question
                of App.util.questions()
            ) {

                if (
                    !App.util.visible(question)
                ) {
                    continue;
                }

                if (!question.required) {
                    continue;
                }

                const value =
                    App.state.answers[
                        question.id
                    ];

                const empty =
                    value == null ||
                    value === '' ||
                    (
                        Array.isArray(value) &&
                        value.length === 0
                    );

                if (empty) {
                    alert(
                        '必須項目に回答してください。'
                    );
                    return;
                }
            }

            App.state.submitting = true;

            const fd =
                new FormData();

            fd.append(
                'action',
                'save_response'
            );

            fd.append(
                'csrf_token',
                '<?= survey_h(survey_token()) ?>'
            );

            fd.append(
                'survey_id',
                survey.id
            );

            fd.append(
                'customer_id',
                <?= json_encode($customerId, JSON_UNESCAPED_UNICODE) ?>
            );

            fd.append(
                'answers',
                JSON.stringify(
                    App.state.answers
                )
            );

            try {

                const response =
                    await fetch(
                        location.href,
                        {
                            method: 'POST',
                            body: fd
                        }
                    );

                const json =
                    await response.json();

                if (!json.ok) {
                    throw new Error(
                        json.message ||
                        '回答保存に失敗しました。'
                    );
                }

                document.getElementById(
                    'app'
                ).innerHTML = `
                    <div class="bg-white rounded-2xl shadow p-10 text-center">
                        <div class="text-green-600 text-4xl mb-4">✓</div>
                        <h1 class="text-xl font-bold mb-3">
                            回答ありがとうございました
                        </h1>
                        <p class="text-gray-500">
                            回答を正常に受け付けました。
                        </p>
                    </div>
                `;

            } catch (error) {
                alert(error.message);
            } finally {
                App.state.submitting = false;
            }
        }
    },

    init() {
        App.render.survey();
    }
};

if (document.readyState === 'loading') {
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
<?php
exit;
endif;

/* =========================================================
 * Admin SPA
 * ========================================================= */

$initial = [
    'data' => $data,
    'csrf_token' => survey_token()
];

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<title>アンケート管理</title>
</head>

<body class="bg-gray-100 text-gray-800">

<div id="app"></div>

<script>
window.App = {

    state: {
        data: <?= survey_json($initial['data']) ?>,
        csrf: <?= json_encode($initial['csrf_token']) ?>,
        page: 'surveys',
        survey: null,
        selectedSurvey: null,
        selectedRecipients: [],
        fields: []
    },

    util: {

        esc(value) {
            const div =
                document.createElement('div');

            div.textContent =
                value == null
                    ? ''
                    : String(value);

            return div.innerHTML;
        },

        async api(action, params = {}) {

            const fd =
                new FormData();

            fd.append(
                'action',
                action
            );

            fd.append(
                'csrf_token',
                App.state.csrf
            );

            Object.keys(params)
                .forEach(key => {

                    const value =
                        params[key];

                    if (
                        typeof value ===
                        'object'
                    ) {
                        fd.append(
                            key,
                            JSON.stringify(value)
                        );
                    } else {
                        fd.append(
                            key,
                            String(value)
                        );
                    }
                });

            const response =
                await fetch(
                    location.pathname,
                    {
                        method: 'POST',
                        body: fd
                    }
                );

            const text =
                await response.text();

            let json;

            try {
                json =
                    JSON.parse(text);
            } catch (e) {
                throw new Error(
                    'サーバーからJSONではないレスポンスが返りました。\n'
                    + text.substring(0, 500)
                );
            }

            if (
                !json.ok &&
                action !== 'get_data'
            ) {
                throw new Error(
                    json.message ||
                    '処理に失敗しました。'
                );
            }

            return json;
        },

        newId() {
            if (
                window.crypto &&
                crypto.randomUUID
            ) {
                return crypto.randomUUID();
            }

            return (
                Date.now().toString(36) +
                Math.random()
                    .toString(36)
                    .substring(2)
            );
        },

        questions(survey) {

            const result = [];

            (survey.groups || [])
                .forEach(group => {

                    (group.questions || [])
                        .forEach(q => {
                            result.push(q);
                        });
                });

            return result;
        }
    },

    render: {

        shell(content) {

            document.getElementById(
                'app'
            ).innerHTML = `
                <header class="bg-white border-b sticky top-0 z-30">
                    <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
                        <div class="font-bold text-lg">
                            アンケート管理
                        </div>

                        <nav class="flex gap-2">
                            <button
                                onclick="App.actions.page('surveys')"
                                class="px-4 py-2 rounded-lg hover:bg-gray-100"
                            >
                                アンケート一覧
                            </button>

                            <button
                                onclick="App.actions.page('settings')"
                                class="px-4 py-2 rounded-lg hover:bg-gray-100"
                            >
                                kintone連携設定
                            </button>
                        </nav>
                    </div>
                </header>

                <main class="max-w-7xl mx-auto p-6">
                    ${content}
                </main>
            `;
        },

        surveys() {

            const surveys =
                App.state.data.surveys
                    .filter(
                        s => !s.deleted
                    );

            let rows = '';

            surveys.forEach(survey => {

                const responses =
                    App.state.data.responses
                        .filter(
                            r =>
                                r.survey_id ===
                                survey.id
                        ).length;

                const badge =
                    survey.status === 'active'
                    ? 'bg-green-100 text-green-700'
                    : survey.status === 'ended'
                    ? 'bg-gray-200 text-gray-700'
                    : 'bg-yellow-100 text-yellow-700';

                const label =
                    survey.status === 'active'
                    ? '公開中'
                    : survey.status === 'ended'
                    ? '終了'
                    : '下書き';

                rows += `
                    <tr class="border-t">
                        <td class="p-4">
                            <div class="font-bold">
                                ${App.util.esc(survey.title)}
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                ${App.util.esc(survey.updated_at || '')}
                            </div>
                        </td>

                        <td class="p-4">
                            <span class="px-3 py-1 rounded-full text-xs ${badge}">
                                ${label}
                            </span>
                        </td>

                        <td class="p-4">
                            ${responses} 件
                        </td>

                        <td class="p-4 whitespace-nowrap">
                            <button
                                onclick="App.actions.editSurvey('${survey.id}')"
                                class="px-3 py-2 bg-blue-600 text-white rounded-lg"
                            >
                                確認・編集
                            </button>

                            <button
                                onclick="App.actions.duplicateSurvey('${survey.id}')"
                                class="px-3 py-2 border rounded-lg ml-1"
                            >
                                複製
                            </button>

                            ${
                                survey.status === 'active'
                                ? `
                                    <button
                                        onclick="App.actions.stopSurvey('${survey.id}')"
                                        class="px-3 py-2 border border-red-300 text-red-600 rounded-lg ml-1"
                                    >
                                        停止
                                    </button>

                                    <button
                                        onclick="App.actions.mail('${survey.id}')"
                                        class="px-3 py-2 border rounded-lg ml-1"
                                    >
                                        送信
                                    </button>

                                    <button
                                        onclick="App.actions.analytics('${survey.id}')"
                                        class="px-3 py-2 border rounded-lg ml-1"
                                    >
                                        集計
                                    </button>
                                `
                                : ''
                            }

                            ${
                                survey.status === 'draft'
                                ? `
                                    <button
                                        onclick="App.actions.deleteSurvey('${survey.id}')"
                                        class="px-3 py-2 border border-red-300 text-red-600 rounded-lg ml-1"
                                    >
                                        削除
                                    </button>
                                `
                                : ''
                            }
                        </td>
                    </tr>
                `;
            });

            App.render.shell(`
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-2xl font-bold">
                            アンケート一覧
                        </h1>
                    </div>

                    <button
                        onclick="App.actions.newSurvey()"
                        class="bg-blue-600 text-white px-5 py-3 rounded-xl font-bold"
                    >
                        ＋ 新規アンケート作成
                    </button>
                </div>

                <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="text-left p-4">タイトル</th>
                                    <th class="text-left p-4">ステータス</th>
                                    <th class="text-left p-4">回答数</th>
                                    <th class="text-left p-4">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${
                                    rows ||
                                    `
                                    <tr>
                                        <td colspan="4" class="p-12 text-center text-gray-400">
                                            アンケートはありません
                                        </td>
                                    </tr>
                                    `
                                }
                            </tbody>
                        </table>
                    </div>
                </div>
            `);
        },

        editor() {

            const survey =
                App.state.survey;

            App.render.shell(`
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <button
                            onclick="App.actions.page('surveys')"
                            class="text-gray-500 mb-2"
                        >
                            ← 一覧へ戻る
                        </button>

                        <h1 class="text-2xl font-bold">
                            アンケート編集
                        </h1>
                    </div>

                    <div class="flex gap-2">
                        <button
                            onclick="App.actions.preview()"
                            class="px-4 py-2 border rounded-lg"
                        >
                            プレビュー
                        </button>

                        <button
                            onclick="App.actions.saveSurvey()"
                            class="px-5 py-2 bg-blue-600 text-white rounded-lg"
                        >
                            保存
                        </button>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                    <label class="block text-sm font-bold mb-2">
                        タイトル
                    </label>

                    <input
                        id="survey_title"
                        value="${App.util.esc(survey.title || '')}"
                        class="w-full border rounded-xl p-3"
                    >

                    <div class="grid grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="text-sm font-bold">
                                開始日時
                            </label>
                            <input
                                id="survey_start_at"
                                type="datetime-local"
                                value="${App.util.esc(survey.start_at || '')}"
                                class="w-full border rounded-xl p-3 mt-1"
                            >
                        </div>

                        <div>
                            <label class="text-sm font-bold">
                                終了日時
                            </label>
                            <input
                                id="survey_end_at"
                                type="datetime-local"
                                value="${App.util.esc(survey.end_at || '')}"
                                class="w-full border rounded-xl p-3 mt-1"
                            >
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="text-sm font-bold">
                            質問番号
                        </label>

                        <select
                            id="survey_numbering_mode"
                            class="border rounded-xl p-3 ml-3"
                        >
                            <option value="global"
                                ${survey.numbering_mode === 'global' ? 'selected' : ''}>
                                Q1, Q2, Q3
                            </option>

                            <option value="group"
                                ${survey.numbering_mode === 'group' ? 'selected' : ''}>
                                グループ別
                            </option>
                        </select>
                    </div>
                </div>

                <div id="question_editor"></div>

                <button
                    onclick="App.actions.addGroup()"
                    class="w-full border-2 border-dashed rounded-xl py-4 text-blue-600 font-bold"
                >
                    ＋ グループ追加
                </button>
            `);

            App.render.groups();
        },

        groups() {

            const container =
                document.getElementById(
                    'question_editor'
                );

            if (!container) {
                return;
            }

            let html = '';

            App.state.survey.groups =
                App.state.survey.groups || [];

            App.state.survey.groups
                .forEach(
                    (group, gi) => {

                    html += `
                        <section
                            class="bg-white rounded-2xl shadow-sm p-6 mb-6"
                            data-group="${group.id}"
                        >
                            <div class="flex items-center gap-3 mb-5">
                                <span class="cursor-move text-gray-400 text-xl">
                                    ⠿
                                </span>

                                <input
                                    value="${App.util.esc(group.name || '')}"
                                    onchange="App.actions.changeGroupName('${group.id}', this.value)"
                                    class="flex-1 text-lg font-bold border-b p-2"
                                >

                                <button
                                    onclick="App.actions.deleteGroup('${group.id}')"
                                    class="text-red-500"
                                >
                                    削除
                                </button>
                            </div>

                            <div
                                class="question-list space-y-4"
                                data-group-id="${group.id}"
                            >
                    `;

                    (group.questions || [])
                        .forEach(
                            (q, qi) => {

                            html +=
                                App.render.question(
                                    q,
                                    gi,
                                    qi
                                );
                        });

                    html += `
                            </div>

                            <button
                                onclick="App.actions.addQuestion('${group.id}')"
                                class="mt-4 px-4 py-2 border rounded-lg text-blue-600"
                            >
                                ＋ 質問追加
                            </button>
                        </section>
                    `;
                });

            container.innerHTML =
                html;

            App.actions.sortable();
        },

        question(q, gi, qi) {

            let options = '';

            if (
                q.type === 'single' ||
                q.type === 'multiple'
            ) {

                options = `
                    <div class="mt-4">
                        <div class="font-bold text-sm mb-2">
                            選択肢
                        </div>

                        <div id="options_${q.id}">
                `;

                (q.options || [])
                    .forEach(
                        (option, oi) => {

                        options += `
                            <div class="flex gap-2 mb-2">
                                <input
                                    value="${App.util.esc(option)}"
                                    onchange="App.actions.optionChange('${q.id}', ${oi}, this.value)"
                                    class="flex-1 border rounded-lg p-2"
                                >

                                <button
                                    onclick="App.actions.removeOption('${q.id}', ${oi})"
                                    class="px-3 text-red-500"
                                >
                                    ×
                                </button>
                            </div>
                        `;
                    });

                options += `
                        </div>

                        <button
                            onclick="App.actions.addOption('${q.id}')"
                            class="text-sm text-blue-600"
                        >
                            ＋ 選択肢
                        </button>
                    </div>
                `;
            }

            /*
             * ★ 質問分岐UI
             */
            let branching = '';

            if (
                q.type === 'single' ||
                q.type === 'multiple'
            ) {

                branching = `
                    <div class="mt-5 bg-blue-50 rounded-xl p-4">
                        <div class="font-bold text-sm mb-3">
                            質問分岐
                        </div>

                        <p class="text-xs text-gray-500 mb-3">
                            選択した場合に表示する質問を設定します。
                        </p>

                        <div id="branching_${q.id}">
                `;

                const branchList =
                    q.branching || [];

                (q.options || [])
                    .forEach(
                        option => {

                        const branch =
                            branchList.find(
                                b =>
                                    b.option ===
                                    option
                            );

                        const target =
                            branch
                            ? branch.target_question_id
                            : '';

                        const targets =
                            App.util.questions(
                                App.state.survey
                            ).filter(
                                targetQ =>
                                    targetQ.id !==
                                    q.id
                            );

                        branching += `
                            <div class="grid grid-cols-2 gap-3 mb-2 items-center">
                                <div class="text-sm">
                                    「${App.util.esc(option)}」
                                </div>

                                <select
                                    onchange="App.actions.branchChange('${q.id}', '${App.util.esc(option).replace(/'/g, "\\'")}', this.value)"
                                    class="border rounded-lg p-2 bg-white"
                                >
                                    <option value="">
                                        分岐なし
                                    </option>

                                    ${targets.map(
                                        targetQ => `
                                            <option
                                                value="${targetQ.id}"
                                                ${targetQ.id === target ? 'selected' : ''}
                                            >
                                                ${App.util.esc(targetQ.text)}
                                            </option>
                                        `
                                    ).join('')}
                                </select>
                            </div>
                        `;
                    });

                branching += `
                        </div>
                    </div>
                `;
            }

            return `
                <div
                    class="border rounded-xl p-5"
                    data-question-id="${q.id}"
                >
                    <div class="flex gap-3">
                        <span class="cursor-move text-gray-400 text-xl">
                            ⠿
                        </span>

                        <div class="flex-1">

                            <div class="flex gap-3">
                                <input
                                    value="${App.util.esc(q.text || '')}"
                                    onchange="App.actions.changeQuestion('${q.id}', 'text', this.value)"
                                    class="flex-1 border rounded-lg p-2"
                                    placeholder="質問文"
                                >

                                <select
                                    onchange="App.actions.changeQuestion('${q.id}', 'type', this.value)"
                                    class="border rounded-lg p-2"
                                >
                                    <option value="single"
                                        ${q.type === 'single' ? 'selected' : ''}>
                                        単一選択
                                    </option>

                                    <option value="multiple"
                                        ${q.type === 'multiple' ? 'selected' : ''}>
                                        複数選択
                                    </option>

                                    <option value="text"
                                        ${q.type === 'text' ? 'selected' : ''}>
                                        自由記述
                                    </option>
                                </select>
                            </div>

                            <label class="flex items-center gap-2 mt-3 text-sm">
                                <input
                                    type="checkbox"
                                    ${q.required ? 'checked' : ''}
                                    onchange="App.actions.changeQuestion('${q.id}', 'required', this.checked)"
                                >
                                必須回答
                            </label>

                            ${options}

                            ${branching}
                        </div>

                        <button
                            onclick="App.actions.deleteQuestion('${q.id}')"
                            class="text-red-500"
                        >
                            削除
                        </button>
                    </div>
                </div>
            `;
        },

        settings() {

            const s =
                App.state.data.settings;

            App.render.shell(`
                <div class="mb-6">
                    <h1 class="text-2xl font-bold">
                        kintone連携設定
                    </h1>
                </div>

                <div class="bg-white rounded-2xl shadow-sm p-6">

                    <div class="grid md:grid-cols-2 gap-5">

                        <div>
                            <label class="font-bold text-sm">
                                サブドメイン
                            </label>
                            <input
                                id="setting_subdomain"
                                value="${App.util.esc(s.subdomain || '')}"
                                placeholder="xxxx.cybozu.com"
                                class="w-full border rounded-xl p-3 mt-1"
                            >
                        </div>

                        <div>
                            <label class="font-bold text-sm">
                                アプリID
                            </label>
                            <input
                                id="setting_app_id"
                                value="${App.util.esc(s.app_id || '')}"
                                class="w-full border rounded-xl p-3 mt-1"
                            >
                        </div>

                        <div>
                            <label class="font-bold text-sm">
                                ログイン名
                            </label>
                            <input
                                id="setting_login_name"
                                value="${App.util.esc(s.login_name || '')}"
                                class="w-full border rounded-xl p-3 mt-1"
                            >
                        </div>

                        <div>
                            <label class="font-bold text-sm">
                                パスワード
                            </label>
                            <input
                                id="setting_password"
                                type="password"
                                value=""
                                placeholder="変更する場合のみ入力"
                                class="w-full border rounded-xl p-3 mt-1"
                            >
                        </div>

                        <div>
                            <label class="font-bold text-sm">
                                Proxy
                            </label>
                            <input
                                id="setting_proxy"
                                value="${App.util.esc(s.proxy || '')}"
                                placeholder="host:port"
                                class="w-full border rounded-xl p-3 mt-1"
                            >
                        </div>

                        <label class="flex items-center gap-3 mt-7">
                            <input
                                id="setting_ssl_verify"
                                type="checkbox"
                                ${s.ssl_verify !== false ? 'checked' : ''}
                            >
                            SSL証明書を検証する
                        </label>
                    </div>

                    <div class="flex gap-3 mt-6">
                        <button
                            onclick="App.actions.testKintone()"
                            class="px-5 py-3 border rounded-xl"
                        >
                            接続確認
                        </button>

                        <button
                            onclick="App.actions.fetchKintoneFields()"
                            class="px-5 py-3 border rounded-xl"
                        >
                            項目一覧を再取得
                        </button>

                        <button
                            onclick="App.actions.syncKintone()"
                            class="px-5 py-3 bg-blue-600 text-white rounded-xl"
                        >
                            kintone手動同期
                        </button>

                        <button
                            onclick="App.actions.saveSettings()"
                            class="px-5 py-3 bg-gray-900 text-white rounded-xl"
                        >
                            設定保存
                        </button>
                    </div>

                    <pre
                        id="field_message"
                        class="mt-5 whitespace-pre-wrap bg-gray-50 rounded-xl p-4 text-sm"
                    ></pre>

                    <div id="field_mapping" class="mt-6"></div>
                </div>
            `);

            App.render.mapping();
        },

        mapping() {

            const s =
                App.state.data.settings;

            if (!App.state.fields.length) {
                return;
            }

            const mappings = [
                ['field_company', '会社名'],
                ['field_name', '氏名'],
                ['field_email', 'メールアドレス'],
                ['field_department', '部署名'],
                ['field_phone', '電話番号']
            ];

            let html = `
                <h2 class="font-bold mb-4">
                    フィールドマッピング
                </h2>
            `;

            mappings.forEach(
                ([key, label]) => {

                html += `
                    <div class="grid grid-cols-2 gap-4 mb-3">
                        <div>${label}</div>

                        <select
                            onchange="App.actions.mappingChange('${key}', this.value)"
                            class="border rounded-lg p-2"
                        >
                            <option value="">未設定</option>

                            ${App.state.fields.map(
                                f => `
                                    <option
                                        value="${App.util.esc(f.code)}"
                                        ${s[key] === f.code ? 'selected' : ''}
                                    >
                                        ${App.util.esc(f.label)}
                                        (${App.util.esc(f.code)})
                                    </option>
                                `
                            ).join('')}
                        </select>
                    </div>
                `;
            });

            html += `
                <div class="font-bold mt-5 mb-3">
                    住所
                </div>
            `;

            App.state.fields.forEach(
                f => {

                const selected =
                    Array.isArray(
                        s.field_address
                    ) &&
                    s.field_address
                        .includes(f.code);

                html += `
                    <label class="flex gap-2 mb-2">
                        <input
                            type="checkbox"
                            ${selected ? 'checked' : ''}
                            onchange="App.actions.addressMapping('${App.util.esc(f.code)}', this.checked)"
                        >
                        ${App.util.esc(f.label)}
                        (${App.util.esc(f.code)})
                    </label>
                `;
            });

            document.getElementById(
                'field_mapping'
            ).innerHTML = html;
        },

        mail() {

            const customers =
                App.state.data.customers;

            App.render.shell(`
                <div class="mb-6">
                    <button
                        onclick="App.actions.page('surveys')"
                        class="text-gray-500"
                    >
                        ← 一覧へ戻る
                    </button>

                    <h1 class="text-2xl font-bold mt-3">
                        顧客選択・メール送信
                    </h1>
                </div>

                <div class="bg-white rounded-2xl p-6 shadow-sm mb-6">
                    <input
                        id="customer_filter"
                        oninput="App.actions.filterCustomers(this.value)"
                        placeholder="顧客名・メールアドレス検索"
                        class="w-full border rounded-xl p-3 mb-4"
                    >

                    <div class="grid md:grid-cols-2 gap-4">
                        <input
                            id="mail_subject"
                            placeholder="件名"
                            class="border rounded-xl p-3"
                        >

                        <select
                            id="template_type"
                            class="border rounded-xl p-3"
                        >
                            <option value="initial">
                                初回
                            </option>
                            <option value="reminder">
                                リマインド
                            </option>
                        </select>
                    </div>

                    <textarea
                        id="mail_body"
                        rows="8"
                        class="w-full border rounded-xl p-3 mt-4"
                        placeholder="本文&#10;&#10;{顧客名}&#10;{アンケートURL}"
                    ></textarea>

                    <button
                        onclick="App.actions.sendMail()"
                        class="mt-4 px-6 py-3 bg-blue-600 text-white rounded-xl font-bold"
                    >
                        選択者へ送信
                    </button>
                </div>

                <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <div class="p-4 border-b">
                        <label class="flex items-center gap-2">
                            <input
                                id="select_all"
                                type="checkbox"
                                onchange="App.actions.selectAll(this.checked)"
                            >
                            全選択
                        </label>
                    </div>

                    <div id="customer_table"></div>
                </div>
            `);

            App.render.customers(
                customers
            );
        },

        customers(customers) {

            let html =
                `<div class="divide-y">`;

            customers.forEach(
                customer => {

                html += `
                    <label class="flex items-center gap-4 p-4 hover:bg-gray-50">
                        <input
                            type="checkbox"
                            data-customer-id="${App.util.esc(customer.id)}"
                            onchange="App.actions.toggleCustomer('${customer.id}', this.checked)"
                            ${App.state.selectedRecipients.includes(customer.id) ? 'checked' : ''}
                        >

                        <div class="flex-1">
                            <div class="font-bold">
                                ${App.util.esc(customer.company || '')}
                            </div>

                            <div>
                                ${App.util.esc(customer.name || '')}
                            </div>

                            <div class="text-sm text-gray-500">
                                ${App.util.esc(customer.email || '')}
                            </div>
                        </div>

                        <div class="text-right text-sm">
                            ${
                                customer.answer_status === 'answered'
                                ? '<span class="text-green-600">回答済み</span>'
                                : '<span class="text-gray-500">未回答</span>'
                            }

                            <div class="text-gray-400">
                                ${customer.send_count || 0} 回送信
                            </div>
                        </div>
                    </label>
                `;
            });

            html += `</div>`;

            document.getElementById(
                'customer_table'
            ).innerHTML = html;
        },

        analytics(surveyId) {

            const survey =
                App.util.surveyById(
                    surveyId
                );

            if (!survey) {
                return;
            }

            const responses =
                App.state.data.responses
                    .filter(
                        r =>
                            r.survey_id ===
                            surveyId
                    );

            const questions =
                App.util.questions(
                    survey
                );

            let body = '';

            questions.forEach(
                q => {

                const counts = {};

                (q.options || [])
                    .forEach(
                        o => counts[o] = 0
                    );

                responses.forEach(
                    response => {

                    const value =
                        response.answers
                            ? response.answers[q.id]
                            : '';

                    if (Array.isArray(value)) {
                        value.forEach(
                            v => {
                                counts[v] =
                                    (counts[v] || 0)
                                    + 1;
                            }
                        );
                    } else if (value !== '') {
                        counts[value] =
                            (counts[value] || 0)
                            + 1;
                    }
                });

                body += `
                    <div class="bg-white rounded-2xl p-6 mb-4">
                        <div class="font-bold mb-4">
                            ${App.util.esc(q.text)}
                        </div>
                `;

                if (q.type === 'text') {

                    responses.forEach(
                        response => {

                        const value =
                            response.answers
                                ? response.answers[q.id]
                                : '';

                        if (value) {
                            body += `
                                <div class="border-b py-3">
                                    <div class="text-sm font-bold">
                                        ${App.util.esc(response.name || '')}
                                    </div>
                                    <div>
                                        ${App.util.esc(value)}
                                    </div>
                                </div>
                            `;
                        }
                    });

                } else {

                    Object.keys(counts)
                        .forEach(
                            option => {

                            body += `
                                <div class="mb-3">
                                    <div class="flex justify-between text-sm">
                                        <span>${App.util.esc(option)}</span>
                                        <span>${counts[option]}件</span>
                                    </div>

                                    <div class="h-3 bg-gray-100 rounded-full mt-1">
                                        <div
                                            class="h-3 bg-blue-500 rounded-full"
                                            style="width:${responses.length ? Math.round(counts[option] / responses.length * 100) : 0}%"
                                        ></div>
                                    </div>
                                </div>
                            `;
                        });
                }

                body += `</div>`;
            });

            App.render.shell(`
                <div class="mb-6">
                    <button
                        onclick="App.actions.page('surveys')"
                        class="text-gray-500"
                    >
                        ← 一覧へ戻る
                    </button>

                    <h1 class="text-2xl font-bold mt-3">
                        集計・分析
                    </h1>

                    <p class="text-gray-500">
                        ${App.util.esc(survey.title)}
                    </p>
                </div>

                <div class="grid grid-cols-4 gap-4 mb-6">
                    <div class="bg-white p-5 rounded-2xl">
                        <div class="text-gray-500 text-sm">回答数</div>
                        <div class="text-2xl font-bold">${responses.length}</div>
                    </div>

                    <div class="bg-white p-5 rounded-2xl">
                        <div class="text-gray-500 text-sm">送信対象</div>
                        <div class="text-2xl font-bold">
                            ${
                                App.state.data.customers
                                    .filter(c => c.sent_at)
                                    .length
                            }
                        </div>
                    </div>

                    <div class="bg-white p-5 rounded-2xl">
                        <div class="text-gray-500 text-sm">未回答</div>
                        <div class="text-2xl font-bold">
                            ${
                                App.state.data.customers
                                    .filter(
                                        c =>
                                            c.sent_at &&
                                            c.answer_status !== 'answered'
                                    )
                                    .length
                            }
                        </div>
                    </div>

                    <div class="bg-white p-5 rounded-2xl">
                        <div class="text-gray-500 text-sm">回答率</div>
                        <div class="text-2xl font-bold">
                            ${
                                App.state.data.customers.length
                                ? (
                                    responses.length /
                                    App.state.data.customers.length *
                                    100
                                ).toFixed(1)
                                : '0.0'
                            }%
                        </div>
                    </div>
                </div>

                ${body}

                <button
                    onclick="location.href='?action=csv&survey_id=${surveyId}'"
                    class="px-5 py-3 bg-gray-900 text-white rounded-xl"
                >
                    CSV出力
                </button>
            `);
        }
    },

    actions: {

        page(name) {

            App.state.page =
                name;

            if (name === 'surveys') {
                App.render.surveys();
            }

            if (name === 'settings') {
                App.render.settings();
            }
        },

        newSurvey() {

            App.state.survey = {
                id: App.util.newId(),
                title: '',
                start_at: '',
                end_at: '',
                status: 'draft',
                created_at: '',
                updated_at: '',
                numbering_mode: 'global',
                groups: [],
                deleted: false
            };

            App.render.editor();
        },

        editSurvey(id) {

            const survey =
                App.util.surveyById(
                    id
                );

            if (!survey) {
                return;
            }

            App.state.survey =
                JSON.parse(
                    JSON.stringify(
                        survey
                    )
                );

            App.render.editor();
        },

        async saveSurvey() {

            const survey =
                App.state.survey;

            survey.title =
                document.getElementById(
                    'survey_title'
                ).value;

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

            const result =
                await App.util.api(
                    'save_survey',
                    {
                        survey_json:
                            survey
                    }
                );

            if (result.ok) {

                const index =
                    App.state.data.surveys
                        .findIndex(
                            s =>
                                s.id ===
                                survey.id
                        );

                if (index >= 0) {
                    App.state.data
                        .surveys[index] =
                        survey;
                } else {
                    App.state.data
                        .surveys.push(
                            survey
                        );
                }

                alert(
                    '保存しました。'
                );

                App.actions.page(
                    'surveys'
                );
            }
        },

        addGroup() {

            App.state.survey.groups =
                App.state.survey.groups ||
                [];

            App.state.survey.groups.push({
                id: App.util.newId(),
                name:
                    '新しいグループ',
                questions: []
            });

            App.render.groups();
        },

        deleteGroup(id) {

            if (
                !confirm(
                    'グループと質問を削除しますか？'
                )
            ) {
                return;
            }

            App.state.survey.groups =
                App.state.survey.groups
                    .filter(
                        g => g.id !== id
                    );

            App.actions.removeInvalidBranches();

            App.render.groups();
        },

        changeGroupName(id, value) {

            const group =
                App.state.survey.groups
                    .find(
                        g => g.id === id
                    );

            if (group) {
                group.name = value;
            }
        },

        addQuestion(groupId) {

            const group =
                App.state.survey.groups
                    .find(
                        g => g.id === groupId
                    );

            if (!group) {
                return;
            }

            group.questions =
                group.questions || [];

            group.questions.push({
                id: App.util.newId(),
                text: '新しい質問',
                type: 'single',
                required: false,
                options: [
                    '選択肢1',
                    '選択肢2'
                ],
                other_enabled: false,
                branching: []
            });

            App.render.groups();
        },

        deleteQuestion(id) {

            App.state.survey.groups =
                App.state.survey.groups
                    .map(
                        group => ({
                            ...group,
                            questions:
                                (
                                    group.questions
                                    || []
                                ).filter(
                                    q =>
                                        q.id !==
                                        id
                                )
                        })
                    );

            App.actions
                .removeInvalidBranches();

            App.render.groups();
        },

        changeQuestion(
            id,
            key,
            value
        ) {

            const q =
                App.actions.findQuestion(
                    id
                );

            if (!q) {
                return;
            }

            q[key] = value;

            if (
                key === 'type' &&
                value === 'text'
            ) {
                q.options = [];
                q.branching = [];
            }

            if (
                key === 'type' &&
                value !== 'text' &&
                !q.options.length
            ) {
                q.options = [
                    '選択肢1',
                    '選択肢2'
                ];
            }

            App.render.groups();
        },

        optionChange(
            id,
            index,
            value
        ) {

            const q =
                App.actions.findQuestion(
                    id
                );

            if (!q) {
                return;
            }

            const old =
                q.options[index];

            q.options[index] =
                value;

            (q.branching || [])
                .forEach(
                    b => {
                        if (
                            b.option === old
                        ) {
                            b.option =
                                value;
                        }
                    }
                );

            App.render.groups();
        },

        addOption(id) {

            const q =
                App.actions.findQuestion(
                    id
                );

            if (!q) {
                return;
            }

            q.options =
                q.options || [];

            q.options.push(
                '選択肢' +
                (
                    q.options.length + 1
                )
            );

            App.render.groups();
        },

        removeOption(
            id,
            index
        ) {

            const q =
                App.actions.findQuestion(
                    id
                );

            if (!q) {
                return;
            }

            const old =
                q.options[index];

            q.options.splice(
                index,
                1
            );

            q.branching =
                (q.branching || [])
                    .filter(
                        b =>
                            b.option !==
                            old
                    );

            App.render.groups();
        },

        /*
         * ★ 分岐設定
         */
        branchChange(
            questionId,
            option,
            targetId
        ) {

            const q =
                App.actions.findQuestion(
                    questionId
                );

            if (!q) {
                return;
            }

            q.branching =
                q.branching || [];

            q.branching =
                q.branching.filter(
                    b =>
                        b.option !==
                        option
                );

            if (targetId) {
                q.branching.push({
                    option:
                        option,
                    target_question_id:
                        targetId
                });
            }

            /*
             * 画面を再描画しない。
             * selectの選択状態を維持する。
             */
        },

        findQuestion(id) {

            for (
                const group
                of App.state.survey.groups
            ) {

                for (
                    const q
                    of group.questions || []
                ) {

                    if (q.id === id) {
                        return q;
                    }
                }
            }

            return null;
        },

        removeInvalidBranches() {

            const ids =
                new Set(
                    App.util.questions(
                        App.state.survey
                    ).map(
                        q => q.id
                    )
                );

            App.state.survey.groups
                .forEach(
                    group => {

                    (group.questions || [])
                        .forEach(
                            q => {

                            q.branching =
                                (q.branching || [])
                                    .filter(
                                        b =>
                                            ids.has(
                                                b.target_question_id
                                            ) &&
                                            b.target_question_id !==
                                                q.id
                                    );
                        });
                });
        },

        /*
         * 循環分岐検査
         */
        hasBranchCycle() {

            const map = {};

            App.util.questions(
                App.state.survey
            ).forEach(
                q => {
                    map[q.id] =
                        (q.branching || [])
                            .map(
                                b =>
                                    b.target_question_id
                            )
                            .filter(Boolean);
                }
            );

            const visiting =
                new Set();

            const visited =
                new Set();

            function dfs(id) {

                if (visiting.has(id)) {
                    return true;
                }

                if (visited.has(id)) {
                    return false;
                }

                visiting.add(id);

                for (
                    const next
                    of map[id] || []
                ) {
                    if (dfs(next)) {
                        return true;
                    }
                }

                visiting.delete(id);
                visited.add(id);

                return false;
            }

            return Object.keys(map)
                .some(
                    id => dfs(id)
                );
        },

        renumber() {

            let global = 1;

            App.state.survey.groups
                .forEach(
                    (group, gi) => {

                    let local = 1;

                    (group.questions || [])
                        .forEach(
                            q => {

                            if (
                                App.state.survey
                                    .numbering_mode
                                === 'group'
                            ) {
                                q.number =
                                    `Q${gi + 1}-${local++}`;
                            } else {
                                q.number =
                                    `Q${global++}`;
                            }
                        });
                });
        },

        sortable() {

            const groupContainer =
                document.getElementById(
                    'question_editor'
                );

            if (
                groupContainer &&
                typeof Sortable !==
                    'undefined'
            ) {

                new Sortable(
                    groupContainer,
                    {
                        animation: 150,
                        handle: '.cursor-move',
                        onEnd() {
                            const ids =
                                Array.from(
                                    groupContainer
                                        .querySelectorAll(
                                            '[data-group]'
                                        )
                                ).map(
                                    el =>
                                        el.dataset.group
                                );

                            App.state.survey.groups =
                                ids.map(
                                    id =>
                                        App.state.survey
                                            .groups
                                            .find(
                                                g =>
                                                    g.id ===
                                                    id
                                            )
                                ).filter(Boolean);

                            App.render.groups();
                        }
                    }
                );

                document
                    .querySelectorAll(
                        '.question-list'
                    )
                    .forEach(
                        list => {

                        new Sortable(
                            list,
                            {
                                group:
                                    'questions',
                                animation: 150,
                                handle:
                                    '.cursor-move',

                                onEnd(evt) {

                                    const ids =
                                        Array.from(
                                            list.children
                                        ).map(
                                            el =>
                                                el.dataset.questionId
                                        );

                                    const sourceGroup =
                                        App.state.survey
                                            .groups
                                            .find(
                                                g =>
                                                    (
                                                        g.questions
                                                        || []
                                                    ).some(
                                                        q =>
                                                            q.id ===
                                                            evt.item.dataset.questionId
                                                    )
                                            );

                                    const targetGroup =
                                        App.state.survey
                                            .groups
                                            .find(
                                                g =>
                                                    g.id ===
                                                    list.dataset.groupId
                                            );

                                    if (
                                        !sourceGroup ||
                                        !targetGroup
                                    ) {
                                        return;
                                    }

                                    const q =
                                        sourceGroup
                                            .questions
                                            .find(
                                                x =>
                                                    x.id ===
                                                    evt.item.dataset.questionId
                                            );

                                    sourceGroup.questions =
                                        sourceGroup.questions
                                            .filter(
                                                x =>
                                                    x.id !==
                                                    evt.item.dataset.questionId
                                            );

                                    if (q) {

                                        targetGroup.questions =
                                            ids.map(
                                                id => {

                                                    if (
                                                        id ===
                                                        q.id
                                                    ) {
                                                        return q;
                                                    }

                                                    return targetGroup
                                                        .questions
                                                        .find(
                                                            x =>
                                                                x.id ===
                                                                id
                                                        );
                                                }
                                            ).filter(Boolean);
                                    }

                                    App.actions
                                        .removeInvalidBranches();

                                    App.actions
                                        .renumber();

                                    App.render.groups();
                                }
                            }
                        );
                    });
            }
        },

        preview() {

            const survey =
                App.state.survey;

            const win =
                window.open(
                    '',
                    '_blank',
                    'width=800,height=800'
                );

            if (!win) {
                alert(
                    'ポップアップを許可してください。'
                );
                return;
            }

            const html =
                App.actions
                    .previewHtml(
                        survey
                    );

            win.document.write(
                html
            );

            win.document.close();
        },

        previewHtml(survey) {

            let questions = '';

            let n = 0;

            App.util.questions(
                survey
            ).forEach(
                q => {

                n++;

                questions += `
                    <div style="margin-bottom:24px">
                        <strong>
                            Q${n}.
                            ${App.util.esc(q.text)}
                        </strong>
                        <div style="margin-top:8px">
                            ${
                                q.type === 'text'
                                ? '<textarea style="width:100%;height:100px"></textarea>'
                                : (
                                    q.options || []
                                ).map(
                                    o =>
                                        `<label style="display:block;margin:8px 0">
                                            <input type="${q.type === 'multiple' ? 'checkbox' : 'radio'}">
                                            ${App.util.esc(o)}
                                        </label>`
                                ).join('')
                            }
                        </div>
                    </div>
                `;
            });

            return `
                <!doctype html>
                <html lang="ja">
                <head>
                    <meta charset="UTF-8">
                    <title>プレビュー</title>
                    <style>
                        body {
                            font-family:
                                Arial,
                                sans-serif;
                            max-width:
                                760px;
                            margin:
                                40px auto;
                            padding:
                                20px;
                            color:
                                #333;
                        }
                    </style>
                </head>
                <body>
                    <h1>
                        ${App.util.esc(survey.title)}
                    </h1>
                    ${questions}
                    <button
                        onclick="alert('これはプレビューです。実送信されません。')"
                    >
                        回答を送信
                    </button>
                </body>
                </html>
            `;
        },

        async duplicateSurvey(id) {

            await App.util.api(
                'duplicate_survey',
                {
                    survey_id: id
                }
            );

            const result =
                await App.util.api(
                    'get_data'
                );

            App.state.data =
                result.data;

            App.actions.page(
                'surveys'
            );
        },

        async stopSurvey(id) {

            if (
                !confirm(
                    'このアンケートを停止しますか？'
                )
            ) {
                return;
            }

            await App.util.api(
                'stop_survey',
                {
                    survey_id: id
                }
            );

            const result =
                await App.util.api(
                    'get_data'
                );

            App.state.data =
                result.data;

            App.actions.page(
                'surveys'
            );
        },

        async deleteSurvey(id) {

            if (
                !confirm(
                    'このアンケートを削除しますか？'
                )
            ) {
                return;
            }

            await App.util.api(
                'delete_survey',
                {
                    survey_id: id
                }
            );

            const result =
                await App.util.api(
                    'get_data'
                );

            App.state.data =
                result.data;

            App.actions.page(
                'surveys'
            );
        },

        mail(surveyId) {

            App.state.selectedSurvey =
                surveyId;

            App.state.selectedRecipients =
                [];

            App.actions
                .pageMail();
        },

        pageMail() {
            App.render.mail();
        },

        filterCustomers(keyword) {

            keyword =
                String(keyword)
                    .toLowerCase();

            const customers =
                App.state.data.customers
                    .filter(
                        c =>
                            String(
                                c.company || ''
                            ).toLowerCase()
                                .includes(keyword)
                            ||
                            String(
                                c.name || ''
                            ).toLowerCase()
                                .includes(keyword)
                            ||
                            String(
                                c.email || ''
                            ).toLowerCase()
                                .includes(keyword)
                    );

            App.render.customers(
                customers
            );
        },

        toggleCustomer(
            id,
            checked
        ) {

            if (checked) {

                if (
                    !App.state
                        .selectedRecipients
                        .includes(id)
                ) {
                    App.state
                        .selectedRecipients
                        .push(id);
                }

            } else {

                App.state
                    .selectedRecipients =
                    App.state
                        .selectedRecipients
                        .filter(
                            x => x !== id
                        );
            }
        },

        selectAll(checked) {

            if (checked) {

                App.state
                    .selectedRecipients =
                    App.state.data.customers
                        .map(
                            c => c.id
                        );

            } else {

                App.state
                    .selectedRecipients =
                    [];
            }

            App.actions
                .filterCustomers(
                    document.getElementById(
                        'customer_filter'
                    )?.value || ''
                );
        },

        async sendMail() {

            if (
                !App.state
                    .selectedRecipients
                    .length
            ) {
                alert(
                    '送信先を選択してください。'
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

            if (
                !confirm(
                    '選択した顧客へメールを送信しますか？'
                )
            ) {
                return;
            }

            const result =
                await App.util.api(
                    'send_mail',
                    {
                        survey_id:
                            App.state
                                .selectedSurvey,

                        recipient_ids:
                            App.state
                                .selectedRecipients,

                        mail_subject:
                            subject,

                        mail_body:
                            body,

                        template_type:
                            document.getElementById(
                                'template_type'
                            ).value
                    }
                );

            alert(
                result.message
            );

            const data =
                await App.util.api(
                    'get_data'
                );

            App.state.data =
                data.data;

            App.actions
                .page('surveys');
        },

        async testKintone() {

            const settings =
                App.actions
                    .settingsFromForm();

            const result =
                await App.util.api(
                    'test_kintone',
                    {
                        settings_json:
                            settings
                    }
                );

            document.getElementById(
                'field_message'
            ).textContent =
                result.message;
        },

        async fetchKintoneFields() {

            const settings =
                App.actions
                    .settingsFromForm();

            const result =
                await App.util.api(
                    'get_fields',
                    {
                        settings_json:
                            settings
                    }
                );

            if (!result.ok) {
                document.getElementById(
                    'field_message'
                ).textContent =
                    result.message;

                return;
            }

            App.state.fields =
                result.fields;

            document.getElementById(
                'field_message'
            ).textContent =
                result.message;

            App.render.mapping();
        },

        async syncKintone() {

            if (
                !confirm(
                    'kintoneから顧客を再取得しますか？'
                )
            ) {
                return;
            }

            const result =
                await App.util.api(
                    'sync_kintone'
                );

            alert(
                result.message
            );

            const data =
                await App.util.api(
                    'get_data'
                );

            App.state.data =
                data.data;

            App.render.settings();
        },

        settingsFromForm() {

            const current =
                App.state.data.settings;

            const password =
                document.getElementById(
                    'setting_password'
                ).value;

            return {
                ...current,

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
                 * 空なら既存パスワード維持。
                 */
                password:
                    password !== ''
                    ? password
                    : current.password,

                proxy:
                    document.getElementById(
                        'setting_proxy'
                    ).value.trim(),

                ssl_verify:
                    document.getElementById(
                        'setting_ssl_verify'
                    ).checked
            };
        },

        async saveSettings() {

            const settings =
                App.actions
                    .settingsFromForm();

            const result =
                await App.util.api(
                    'save_settings',
                    {
                        settings_json:
                            settings
                    }
                );

            if (result.ok) {

                App.state.data.settings =
                    settings;

                alert(
                    '設定を保存しました。'
                );
            }
        },

        mappingChange(
            key,
            value
        ) {

            App.state.data.settings[
                key
            ] = value;
        },

        addressMapping(
            code,
            checked
        ) {

            let list =
                App.state.data.settings
                    .field_address;

            if (!Array.isArray(list)) {
                list = [];
            }

            if (checked) {

                if (!list.includes(code)) {
                    list.push(code);
                }

            } else {

                list =
                    list.filter(
                        x =>
                            x !== code
                    );
            }

            App.state.data.settings
                .field_address =
                list;
        }
    },

    util: {
        esc(value) {
            const div =
                document.createElement('div');

            div.textContent =
                value == null
                    ? ''
                    : String(value);

            return div.innerHTML;
        },

        newId() {
            if (
                crypto &&
                crypto.randomUUID
            ) {
                return crypto.randomUUID();
            }

            return (
                Date.now().toString(36)
                +
                Math.random()
                    .toString(36)
                    .slice(2)
            );
        },

        questions(survey) {

            const result = [];

            (survey.groups || [])
                .forEach(
                    group => {

                    (group.questions || [])
                        .forEach(
                            q =>
                                result.push(q)
                        );
                });

            return result;
        },

        surveyById(id) {

            return App.state.data
                .surveys
                .find(
                    s =>
                        s.id === id
                )
                || null;
        },

        async api(
            action,
            params = {}
        ) {

            const fd =
                new FormData();

            fd.append(
                'action',
                action
            );

            fd.append(
                'csrf_token',
                App.state.csrf
            );

            Object.entries(params)
                .forEach(
                    ([key, value]) => {

                    fd.append(
                        key,
                        typeof value ===
                            'object'
                            ? JSON.stringify(
                                value
                            )
                            : String(value)
                    );
                });

            const response =
                await fetch(
                    location.pathname,
                    {
                        method: 'POST',
                        body: fd
                    }
                );

            const text =
                await response.text();

            let json;

            try {
                json =
                    JSON.parse(text);
            } catch (e) {

                console.error(
                    'API response:',
                    text
                );

                throw new Error(
                    'サーバーがJSONではなくHTML等を返しました。'
                    + '\n'
                    + text.substring(
                        0,
                        500
                    )
                );
            }

            if (
                !json.ok &&
                action !== 'get_data'
            ) {
                throw new Error(
                    json.message
                    ||
                    '処理に失敗しました。'
                );
            }

            return json;
        }
    },

    init() {

        /*
         * 旧コードと同様にApp配下のみを使用。
         */
        App.actions.page(
            'surveys'
        );
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

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
- branch

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

追加固定名称:
- branch
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

/* ================================================================
 * 共通
 * ================================================================ */

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
    $a = (string)($_SESSION['csrf_token'] ?? '');
    $b = (string)($_POST['csrf_token'] ?? '');

    return $a !== '' &&
        $b !== '' &&
        hash_equals($a, $b);
}

function survey_request_json(string $key): ?array
{
    $raw = (string)($_POST[$key] ?? '');

    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

function survey_find(array $data, string $id): ?array
{
    foreach ($data['surveys'] as $survey) {
        if (
            (string)($survey['id'] ?? '') === $id &&
            empty($survey['deleted'])
        ) {
            return $survey;
        }
    }

    return null;
}

function survey_question_list(array $survey): array
{
    $questions = [];

    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            $questions[] = $question;
        }
    }

    return $questions;
}

function survey_question_count(array $survey): int
{
    return count(survey_question_list($survey));
}

/* ================================================================
 * kintone URL
 * ================================================================ */

function survey_normalize_kintone_base(string $input): array
{
    $input = trim($input);
    $input = rtrim($input, "/ \t\r\n");

    if ($input === '') {
        return [
            'ok' => false,
            'error' => 'kintoneホストが未入力です。',
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
            $matches
        )
    ) {
        $host = $matches[1];
    }

    $host = strtolower(trim($host));

    if ($host === '') {
        return [
            'ok' => false,
            'error' => 'kintoneホストを取得できません。',
        ];
    }

    $hostOnly = preg_replace('/:\d+$/', '', $host);

    $validCybozu = preg_match(
        '~^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.cybozu\.com$~i',
        (string)$hostOnly
    );

    $validInternal = preg_match(
        '~^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$~i',
        (string)$hostOnly
    );

    if (!$validCybozu && !$validInternal) {
        return [
            'ok' => false,
            'error' => '許可されていないkintoneホスト名です。',
        ];
    }

    return [
        'ok' => true,
        'base' => 'https://' . $host,
        'host' => $hostOnly,
    ];
}

/* ================================================================
 * Proxy
 * ================================================================ */

function survey_parse_proxy(string $input): array
{
    $input = trim($input);

    if ($input === '') {
        return [
            'ok' => true,
            'used' => false,
            'value' => '',
        ];
    }

    if (
        !preg_match(
            '~^(?:(https?)://)?([^/:?#\s]+):([0-9]{1,5})$~i',
            $input,
            $matches
        )
    ) {
        return [
            'ok' => false,
            'used' => true,
            'value' => '',
            'error' =>
                'Proxy形式は host:port、http://host:port、https://host:port です。',
        ];
    }

    $port = (int)$matches[3];

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
        'value' => 'tcp://' . strtolower($matches[2]) . ':' . $port,
    ];
}

/* ================================================================
 * HTTP
 * ================================================================ */

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
        if (
            preg_match(
                '~^HTTP/\S+\s+([0-9]{3})~i',
                (string)$header,
                $matches
            )
        ) {
            $status = (int)$matches[1];
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
        $warning = $exception->getMessage();
    }

    restore_error_handler();

    $responseHeaders = survey_last_headers();
    $status = survey_status_from_headers($responseHeaders);

    $bodyText = is_string($body) ? $body : '';

    $json = json_decode(
        $bodyText,
        true
    );

    if ($status === 0) {
        $error = $warning !== ''
            ? $warning
            : 'HTTPレスポンスを取得できませんでした。';

        $error .=
            "\n確認事項: DNS名前解決、サーバー外部HTTPS通信、"
            . "Proxy、ファイアウォール、SSL/TLS、OpenSSL、タイムアウト。";

        if ($proxyInfo['used']) {
            $error .= "\nProxy接続失敗の可能性があります。";
        }

        if (!$sslVerify) {
            $error .= "\nSSL証明書検証は無効化されています。";
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

/* ================================================================
 * kintone
 * ================================================================ */

function survey_kintone_request(
    array $settings,
    string $path,
    string $method = 'GET',
    ?array $payload = null
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
            'proxy_used' => false,
        ];
    }

    $path = '/' . ltrim($path, '/');

    $url =
        $normalized['base'] .
        '/k/v1' .
        $path;

    if (!str_contains($url, '?')) {
        $url .= '?app=' . rawurlencode($appId);
    }

    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');

    $authorization = base64_encode(
        $login . ':' . $password
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Accept: application/json',
        'Connection: close',
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

function survey_kintone_message(array $result): string
{
    $status = (int)($result['status'] ?? 0);
    $url = (string)($result['url'] ?? '');
    $error = trim((string)($result['error'] ?? ''));

    $proxy = !empty($result['proxy_used'])
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
            "kintone APIまたは指定アプリが見つかりません。\n"
            . "HTTPステータス: 404\n"
            . "接続先: {$url}";
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

function fetchKintoneFields(array $settings): array
{
    $result = survey_kintone_request(
        $settings,
        '/app/form/fields.json'
    );

    if (
        (int)$result['status'] < 200 ||
        (int)$result['status'] >= 300
    ) {
        return [
            'ok' => false,
            'fields' => [],
            'message' => survey_kintone_message($result),
        ];
    }

    $json = $result['json'];

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

    $value = $record[$code]['value'] ?? '';

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $parts[] = (string)(
                    $item['value'] ?? ''
                );
            } else {
                $parts[] = (string)$item;
            }
        }

        return implode(' ', $parts);
    }

    return (string)$value;
}

function survey_sync_customers(array &$data): array
{
    $settings = $data['settings'];

    $fields = fetchKintoneFields($settings);

    if (!$fields['ok']) {
        return [
            'ok' => false,
            'message' => $fields['message'],
            'count' => 0,
        ];
    }

    $result = survey_kintone_request(
        $settings,
        '/records.json'
    );

    if (
        (int)$result['status'] < 200 ||
        (int)$result['status'] >= 300
    ) {
        return [
            'ok' => false,
            'message' => survey_kintone_message($result),
            'count' => 0,
        ];
    }

    $records = $result['json']['records'] ?? null;

    if (!is_array($records)) {
        return [
            'ok' => false,
            'message' =>
                'kintone APIレスポンスにrecordsがありません。',
            'count' => 0,
        ];
    }

    $existing = [];

    foreach ($data['customers'] as $customer) {
        $email = trim(
            (string)($customer['email'] ?? '')
        );

        if ($email !== '') {
            $existing[$email] = $customer;
        }
    }

    $newCount = 0;
    $updateCount = 0;
    $count = 0;

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $email = trim(
            survey_kintone_value(
                $record,
                (string)(
                    $settings['field_email'] ?? ''
                )
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

        $old = $existing[$email] ?? null;

        $customer = [
            'id' => $old['id'] ?? survey_id(),
            'company' => survey_kintone_value(
                $record,
                (string)(
                    $settings['field_company'] ?? ''
                )
            ),
            'name' => survey_kintone_value(
                $record,
                (string)(
                    $settings['field_name'] ?? ''
                )
            ),
            'email' => $email,
            'department' => survey_kintone_value(
                $record,
                (string)(
                    $settings['field_department'] ?? ''
                )
            ),
            'phone' => survey_kintone_value(
                $record,
                (string)(
                    $settings['field_phone'] ?? ''
                )
            ),
            'address' => '',
            'source' => 'kintone',
            'sent_at' => $old['sent_at'] ?? null,
            'send_count' => (int)(
                $old['send_count'] ?? 0
            ),
            'answer_status' => $old['answer_status']
                ?? 'unanswered',
            'kintone_status' => 'registered',
        ];

        $addressCodes =
            $settings['field_address'] ?? [];

        if (!is_array($addressCodes)) {
            $addressCodes = [$addressCodes];
        }

        $addressParts = [];

        foreach ($addressCodes as $code) {
            $value = survey_kintone_value(
                $record,
                (string)$code
            );

            if ($value !== '') {
                $addressParts[] = $value;
            }
        }

        $customer['address'] =
            implode(' ', $addressParts);

        if ($old !== null) {
            $updateCount++;
        } else {
            $newCount++;
        }

        $existing[$email] = $customer;
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
        'count' => $count,
    ];
}

/* ================================================================
 * CSV
 * ================================================================ */

function survey_csv_download(
    array $data,
    string $surveyId
): never {
    $survey = survey_find($data, $surveyId);

    if (!$survey) {
        http_response_code(404);
        exit('Survey not found');
    }

    $questions =
        survey_question_list($survey);

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

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

    foreach ($questions as $question) {
        $header[] =
            (string)($question['text'] ?? '');
    }

    fputcsv($fp, $header);

    foreach ($data['responses'] as $response) {
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

        foreach ($questions as $question) {
            $qid =
                (string)($question['id'] ?? '');

            $value =
                $response['answers'][$qid] ?? '';

            if (is_array($value)) {
                $value =
                    implode(', ', $value);
            }

            $row[] = $value;
        }

        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}

/* ================================================================
 * 公開フォーム
 * ================================================================ */

function survey_public_page(
    array $survey,
    ?array $customer
): never {
    $customer = $customer ?? [];

    $questions =
        survey_question_list($survey);

    $surveyJson = survey_json($survey);
    $csrf = survey_token();

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= survey_h($survey['title']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-800">
<main class="max-w-3xl mx-auto p-4 md:p-8">
<section class="bg-white rounded-2xl shadow-sm p-6 md:p-10">
<div class="mb-8">
<p class="text-sm text-blue-600 font-semibold">
アンケート
</p>
<h1 class="text-2xl md:text-3xl font-bold mt-2">
<?= survey_h($survey['title']) ?>
</h1>
<?php if (!empty($survey['end_at'])): ?>
<p class="text-sm text-slate-500 mt-2">
回答期限:
<?= survey_h($survey['end_at']) ?>
</p>
<?php endif; ?>
</div>

<form method="post" action="" class="space-y-8">
<input type="hidden" name="action" value="public_submit">
<input type="hidden" name="survey_id"
value="<?= survey_h($survey['id']) ?>">
<input type="hidden" name="customer_id"
value="<?= survey_h($customer['id'] ?? '') ?>">
<input type="hidden" name="csrf_token"
value="<?= survey_h($csrf) ?>">

<div class="grid md:grid-cols-2 gap-4">
<label>
<span class="block text-sm font-semibold mb-1">
会社名
</span>
<input name="company"
value="<?= survey_h($customer['company'] ?? '') ?>"
class="w-full border border-slate-300 rounded-lg p-3"
required>
</label>

<label>
<span class="block text-sm font-semibold mb-1">
氏名
</span>
<input name="name"
value="<?= survey_h($customer['name'] ?? '') ?>"
class="w-full border border-slate-300 rounded-lg p-3"
required>
</label>

<label class="md:col-span-2">
<span class="block text-sm font-semibold mb-1">
メールアドレス
</span>
<input type="email" name="email"
value="<?= survey_h($customer['email'] ?? '') ?>"
class="w-full border border-slate-300 rounded-lg p-3"
required>
</label>
</div>

<?php foreach ($survey['groups'] ?? [] as $group): ?>
<div class="border-t border-slate-200 pt-6">
<h2 class="text-lg font-bold mb-5">
<?= survey_h($group['name'] ?? '') ?>
</h2>

<div class="space-y-7">
<?php foreach ($group['questions'] ?? [] as $question): ?>
<?php
$qid = (string)$question['id'];
$type = (string)($question['type'] ?? 'text');
$options = $question['options'] ?? [];
?>
<div>
<div class="font-semibold mb-3">
<?= survey_h($question['text'] ?? '') ?>
<?php if (!empty($question['required'])): ?>
<span class="text-red-500 ml-1">必須</span>
<?php endif; ?>
</div>

<?php if ($type === 'single'): ?>

<div class="space-y-2">
<?php foreach ($options as $index => $option): ?>
<label class="flex gap-2 items-center">
<input
type="radio"
name="answers[<?= survey_h($qid) ?>]"
value="<?= survey_h($option) ?>"
class="accent-blue-600"
<?= !empty($question['required']) ? 'required' : '' ?>>
<span><?= survey_h($option) ?></span>
</label>
<?php endforeach; ?>

<?php if (!empty($question['other_enabled'])): ?>
<label class="flex gap-2 items-start">
<input
type="radio"
name="answers[<?= survey_h($qid) ?>]"
value="その他"
class="accent-blue-600">
<span>その他</span>
</label>
<input
name="other[<?= survey_h($qid) ?>]"
placeholder="その他の内容"
class="w-full border rounded-lg p-3">
<?php endif; ?>
</div>

<?php elseif ($type === 'multiple'): ?>

<div class="space-y-2">
<?php foreach ($options as $option): ?>
<label class="flex gap-2 items-center">
<input
type="checkbox"
name="answers[<?= survey_h($qid) ?>][]"
value="<?= survey_h($option) ?>"
class="accent-blue-600">
<span><?= survey_h($option) ?></span>
</label>
<?php endforeach; ?>

<?php if (!empty($question['other_enabled'])): ?>
<label class="flex gap-2 items-center">
<input
type="checkbox"
name="answers[<?= survey_h($qid) ?>][]"
value="その他"
class="accent-blue-600">
<span>その他</span>
</label>
<input
name="other[<?= survey_h($qid) ?>]"
placeholder="その他の内容"
class="w-full border rounded-lg p-3">
<?php endif; ?>
</div>

<?php else: ?>

<textarea
name="answers[<?= survey_h($qid) ?>]"
rows="5"
class="w-full border border-slate-300 rounded-lg p-3"
<?= !empty($question['required']) ? 'required' : '' ?>></textarea>

<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endforeach; ?>

<button
class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl py-4">
回答を送信する
</button>
</form>
</section>
</main>
</body>
</html>
<?php
exit;
}

/* ================================================================
 * データ
 * ================================================================ */

$data = survey_read_data();

/* ================================================================
 * 公開回答 POST
 * ================================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    (string)($_POST['action'] ?? '') ===
    'public_submit'
) {
    if (!survey_check_token()) {
        http_response_code(403);
        exit('CSRF validation failed');
    }

    $surveyId =
        (string)($_POST['survey_id'] ?? '');

    $survey = survey_find(
        $data,
        $surveyId
    );

    if (
        !$survey ||
        ($survey['status'] ?? '') !== 'active'
    ) {
        http_response_code(404);
        exit('このアンケートは公開されていません。');
    }

    $customerId =
        (string)($_POST['customer_id'] ?? '');

    $company = trim(
        (string)($_POST['company'] ?? '')
    );

    $name = trim(
        (string)($_POST['name'] ?? '')
    );

    $email = trim(
        (string)($_POST['email'] ?? '')
    );

    if (
        $company === '' ||
        $name === '' ||
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        exit('入力内容を確認してください。');
    }

    $customer = null;
    $customerIndex = -1;

    foreach (
        $data['customers']
        as $index => $item
    ) {
        if (
            $customerId !== '' &&
            ($item['id'] ?? '') === $customerId
        ) {
            $customer = $item;
            $customerIndex = $index;
            break;
        }

        if (
            $customerId === '' &&
            strtolower(
                (string)($item['email'] ?? '')
            ) === strtolower($email)
        ) {
            $customer = $item;
            $customerIndex = $index;
            break;
        }
    }

    if ($customer === null) {
        $customer = [
            'id' => survey_id(),
            'company' => $company,
            'name' => $name,
            'email' => $email,
            'department' => '',
            'phone' => '',
            'address' => '',
            'source' => 'web',
            'sent_at' => null,
            'send_count' => 0,
            'answer_status' => 'unanswered',
            'kintone_status' => 'unregistered',
        ];

        $data['customers'][] = $customer;
        $customerIndex =
            count($data['customers']) - 1;
    } else {
        $data['customers'][$customerIndex]['company'] =
            $company;
        $data['customers'][$customerIndex]['name'] =
            $name;
        $data['customers'][$customerIndex]['email'] =
            $email;
    }

    $answers =
        $_POST['answers'] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $other =
        $_POST['other'] ?? [];

    if (!is_array($other)) {
        $other = [];
    }

    foreach ($other as $qid => $otherText) {
        if (
            isset($answers[$qid]) &&
            is_array($answers[$qid])
        ) {
            if (
                in_array(
                    'その他',
                    $answers[$qid],
                    true
                ) &&
                trim((string)$otherText) !== ''
            ) {
                $answers[$qid][] =
                    'その他: ' .
                    trim((string)$otherText);
            }
        } elseif (
            isset($answers[$qid]) &&
            $answers[$qid] === 'その他' &&
            trim((string)$otherText) !== ''
        ) {
            $answers[$qid] =
                'その他: ' .
                trim((string)$otherText);
        }
    }

    $response = [
        'id' => survey_id(),
        'survey_id' => $surveyId,
        'customer_id' =>
            $data['customers'][$customerIndex]['id'],
        'company' => $company,
        'name' => $name,
        'email' => $email,
        'answered_at' => survey_now(),
        'answers' => $answers,
    ];

    $data['responses'][] = $response;

    $data['customers'][$customerIndex]
        ['answer_status'] = 'answered';

    survey_write_data($data);

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
content="width=device-width,initial-scale=1">
<title>回答完了</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen">
<main class="max-w-xl mx-auto p-6 pt-16">
<div class="bg-white rounded-2xl shadow-sm p-8 text-center">
<div class="text-green-600 text-5xl mb-4">✓</div>
<h1 class="text-2xl font-bold">
回答ありがとうございました
</h1>
<p class="text-slate-500 mt-3">
アンケートへの回答を受け付けました。
</p>
</div>
</main>
</body>
</html>
<?php
    exit;
}

/* ================================================================
 * 公開GET
 * ================================================================ */

if (
    isset($_GET['survey']) &&
    $_SERVER['REQUEST_METHOD'] === 'GET'
) {
    $surveyId =
        (string)($_GET['survey'] ?? '');

    $customerId =
        (string)($_GET['customer'] ?? '');

    $survey =
        survey_find($data, $surveyId);

    if (
        !$survey ||
        ($survey['status'] ?? '') !== 'active'
    ) {
        http_response_code(404);
        ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50">
<main class="max-w-xl mx-auto p-8">
<div class="bg-white rounded-2xl p-8">
<h1 class="text-xl font-bold">
このアンケートは公開されていません
</h1>
</div>
</main>
</body>
</html>
<?php
        exit;
    }

    $customer = null;

    foreach ($data['customers'] as $item) {
        if (
            $customerId !== '' &&
            ($item['id'] ?? '') === $customerId
        ) {
            $customer = $item;
            break;
        }
    }

    survey_public_page(
        $survey,
        $customer
    );
}

/* ================================================================
 * 管理API
 * ================================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action'])
) {
    $action =
        (string)$_POST['action'];

    if (
        $action !== 'csrf' &&
        !survey_check_token()
    ) {
        survey_api([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。',
        ], 403);
    }

    if ($action === 'csrf') {
        survey_api([
            'ok' => true,
            'csrf_token' => survey_token(),
        ]);
    }

    if ($action === 'get_data') {
        $safe = $data;

        /* パスワードはブラウザへ戻さない */
        $safe['settings']['password'] = '';

        survey_api([
            'ok' => true,
            'data' => $safe,
        ]);
    }

    if ($action === 'save_survey') {
        $survey =
            survey_request_json(
                'survey_json'
            );

        if (!$survey) {
            survey_api([
                'ok' => false,
                'message' =>
                    'アンケートデータが不正です。',
            ], 400);
        }

        $id = (string)(
            $survey['id'] ?? ''
        );

        if ($id === '') {
            $id = survey_id();
        }

        $existingIndex = -1;

        foreach (
            $data['surveys']
            as $index => $item
        ) {
            if (
                ($item['id'] ?? '') === $id
            ) {
                $existingIndex = $index;
                break;
            }
        }

        $now = survey_now();

        $normalized = [
            'id' => $id,
            'title' => trim(
                (string)($survey['title'] ?? '')
            ),
            'start_at' =>
                (string)($survey['start_at'] ?? ''),
            'end_at' =>
                (string)($survey['end_at'] ?? ''),
            'status' =>
                in_array(
                    ($survey['status'] ?? 'draft'),
                    ['draft', 'active', 'ended'],
                    true
                )
                ? $survey['status']
                : 'draft',
            'created_at' =>
                $existingIndex >= 0
                ? ($data['surveys'][$existingIndex]
                    ['created_at'] ?? $now)
                : $now,
            'updated_at' => $now,
            'numbering_mode' =>
                ($survey['numbering_mode'] ?? 'global')
                === 'group'
                ? 'group'
                : 'global',
            'groups' => [],
            'deleted' => false,
        ];

        foreach (
            ($survey['groups'] ?? [])
            as $group
        ) {
            if (!is_array($group)) {
                continue;
            }

            $groupId =
                (string)($group['id'] ?? survey_id());

            $questions = [];

            foreach (
                ($group['questions'] ?? [])
                as $question
            ) {
                if (!is_array($question)) {
                    continue;
                }

                $type =
                    (string)($question['type'] ?? 'text');

                if (
                    !in_array(
                        $type,
                        ['single', 'multiple', 'text'],
                        true
                    )
                ) {
                    $type = 'text';
                }

                $options = [];

                foreach (
                    ($question['options'] ?? [])
                    as $option
                ) {
                    $option = trim(
                        (string)$option
                    );

                    if ($option !== '') {
                        $options[] = $option;
                    }
                }

                $questions[] = [
                    'id' =>
                        (string)(
                            $question['id'] ?? survey_id()
                        ),
                    'text' =>
                        trim(
                            (string)(
                                $question['text'] ?? ''
                            )
                        ),
                    'type' => $type,
                    'required' =>
                        !empty($question['required']),
                    'options' => $options,
                    'other_enabled' =>
                        !empty(
                            $question['other_enabled']
                        ),
                    'branch' =>
                        is_array(
                            $question['branch'] ?? null
                        )
                        ? $question['branch']
                        : [],
                ];
            }

            $normalized['groups'][] = [
                'id' => $groupId,
                'name' =>
                    trim(
                        (string)(
                            $group['name'] ??
                            'グループ'
                        )
                    ),
                'questions' => $questions,
            ];
        }

        if ($existingIndex >= 0) {
            $data['surveys'][$existingIndex] =
                $normalized;
        } else {
            $data['surveys'][] =
                $normalized;
        }

        if (!survey_write_data($data)) {
            survey_api([
                'ok' => false,
                'message' =>
                    'データ保存に失敗しました。'
                    . 'survey_storageの書き込み権限を確認してください。',
            ], 500);
        }

        survey_api([
            'ok' => true,
            'survey' => $normalized,
        ]);
    }

    if ($action === 'delete_survey') {
        $id =
            (string)($_POST['survey_id'] ?? '');

        foreach (
            $data['surveys']
            as &$survey
        ) {
            if (($survey['id'] ?? '') === $id) {
                $survey['deleted'] = true;
                $survey['updated_at'] =
                    survey_now();
                break;
            }
        }
        unset($survey);

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'message' => '削除しました。',
        ]);
    }

    if ($action === 'change_status') {
        $id =
            (string)($_POST['survey_id'] ?? '');

        $status =
            (string)($_POST['status'] ?? '');

        if (
            !in_array(
                $status,
                ['draft', 'active', 'ended'],
                true
            )
        ) {
            survey_api([
                'ok' => false,
                'message' =>
                    'ステータスが不正です。',
            ], 400);
        }

        foreach (
            $data['surveys']
            as &$survey
        ) {
            if (($survey['id'] ?? '') === $id) {
                $survey['status'] = $status;
                $survey['updated_at'] =
                    survey_now();
                break;
            }
        }
        unset($survey);

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'message' => 'ステータスを変更しました。',
        ]);
    }

    if ($action === 'duplicate_survey') {
        $id =
            (string)($_POST['survey_id'] ?? '');

        $source = survey_find(
            $data,
            $id
        );

        if (!$source) {
            survey_api([
                'ok' => false,
                'message' =>
                    '複製元が見つかりません。',
            ], 404);
        }

        $new = $source;

        $new['id'] = survey_id();
        $new['title'] =
            (string)$source['title'] .
            '（複製）';
        $new['status'] = 'draft';
        $new['created_at'] =
            survey_now();
        $new['updated_at'] =
            survey_now();
        $new['deleted'] = false;

        foreach (
            $new['groups']
            as &$group
        ) {
            $group['id'] = survey_id();

            foreach (
                $group['questions']
                as &$question
            ) {
                $question['id'] =
                    survey_id();
            }

            unset($question);
        }
        unset($group);

        $data['surveys'][] = $new;

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'survey' => $new,
        ]);
    }

    if ($action === 'save_settings') {
        $settings =
            survey_request_json(
                'settings_json'
            );

        if (!$settings) {
            survey_api([
                'ok' => false,
                'message' =>
                    '設定データが不正です。',
            ], 400);
        }

        $current =
            $data['settings'];

        $keys = [
            'subdomain',
            'login_name',
            'app_id',
            'proxy',
            'field_company',
            'field_name',
            'field_email',
            'field_department',
            'field_phone',
        ];

        foreach ($keys as $key) {
            if (array_key_exists($key, $settings)) {
                $current[$key] =
                    trim((string)$settings[$key]);
            }
        }

        if (array_key_exists('ssl_verify', $settings)) {
            $current['ssl_verify'] =
                (bool)$settings['ssl_verify'];
        }

        if (
            isset($settings['field_address']) &&
            is_array($settings['field_address'])
        ) {
            $current['field_address'] =
                array_values(
                    array_map(
                        'strval',
                        $settings['field_address']
                    )
                );
        }

        /*
         * パスワード:
         * 空欄なら既存値を維持する。
         */
        if (
            isset($settings['password']) &&
            (string)$settings['password'] !== ''
        ) {
            $current['password'] =
                (string)$settings['password'];
        }

        $data['settings'] = $current;

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'message' => '設定を保存しました。',
        ]);
    }

    if ($action === 'kintone_test') {
        $settings =
            survey_request_json(
                'settings_json'
            );

        if (!$settings) {
            survey_api([
                'ok' => false,
                'message' =>
                    '接続設定が不正です。',
            ], 400);
        }

        $result =
            survey_kintone_request(
                $settings,
                '/app/form/fields.json'
            );

        $status =
            (int)$result['status'];

        if (
            $status >= 200 &&
            $status < 300
        ) {
            $json = $result['json'];

            if (
                !is_array($json) ||
                !isset($json['properties']) ||
                !is_array($json['properties'])
            ) {
                survey_api([
                    'ok' => false,
                    'status' => $status,
                    'message' =>
                        'HTTP通信は成功しましたが、'
                        . 'propertiesを取得できませんでした。',
                ]);
            }

            survey_api([
                'ok' => true,
                'status' => $status,
                'message' =>
                    'kintoneへの接続とアプリ項目取得に成功しました。',
                'fields' =>
                    array_map(
                        static function (
                            mixed $property,
                            mixed $code
                        ): array {
                            return [
                                'code' => (string)$code,
                                'label' =>
                                    is_array($property)
                                    ? (string)(
                                        $property['label']
                                        ?? $code
                                    )
                                    : (string)$code,
                                'type' =>
                                    is_array($property)
                                    ? (string)(
                                        $property['type']
                                        ?? ''
                                    )
                                    : '',
                            ];
                        },
                        $json['properties'],
                        array_keys($json['properties'])
                    ),
            ]);
        }

        survey_api([
            'ok' => false,
            'status' => $status,
            'message' =>
                survey_kintone_message($result),
            'error' =>
                (string)($result['error'] ?? ''),
            'url' =>
                (string)($result['url'] ?? ''),
            'proxy_used' =>
                !empty($result['proxy_used']),
        ]);
    }

    if ($action === 'kintone_fields') {
        $settings =
            survey_request_json(
                'settings_json'
            );

        if (!$settings) {
            survey_api([
                'ok' => false,
                'message' =>
                    '接続設定が不正です。',
            ], 400);
        }

        $result =
            fetchKintoneFields($settings);

        survey_api($result);
    }

    if ($action === 'sync_customers') {
        $result =
            survey_sync_customers($data);

        if (!$result['ok']) {
            survey_api(
                $result,
                500
            );
        }

        survey_write_data($data);

        survey_api($result);
    }

    if ($action === 'register_kintone') {
        $customerId =
            (string)($_POST['customer_id'] ?? '');

        foreach (
            $data['customers']
            as &$customer
        ) {
            if (
                ($customer['id'] ?? '') ===
                $customerId
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
            'message' =>
                'kintone登録完了として更新しました。',
        ]);
    }

    if ($action === 'get_response') {
        $responseId =
            (string)($_POST['response_id'] ?? '');

        foreach (
            $data['responses']
            as $response
        ) {
            if (
                ($response['id'] ?? '') ===
                $responseId
            ) {
                survey_api([
                    'ok' => true,
                    'response' => $response,
                ]);
            }
        }

        survey_api([
            'ok' => false,
            'message' =>
                '回答が見つかりません。',
        ], 404);
    }

    if ($action === 'send_mail') {
        $surveyId =
            (string)($_POST['survey_id'] ?? '');

        $recipientIds =
            survey_request_json(
                'recipient_ids'
            );

        if (!$recipientIds) {
            $recipientIds = [];
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

        $templateType =
            (string)(
                $_POST['template_type'] ?? 'initial'
            );

        if (
            !in_array(
                $templateType,
                ['initial', 'reminder'],
                true
            )
        ) {
            $templateType = 'initial';
        }

        if (
            $subject === '' ||
            trim($body) === ''
        ) {
            survey_api([
                'ok' => false,
                'message' =>
                    '件名と本文を入力してください。',
            ], 400);
        }

        $survey =
            survey_find(
                $data,
                $surveyId
            );

        if (!$survey) {
            survey_api([
                'ok' => false,
                'message' =>
                    'アンケートが見つかりません。',
            ], 404);
        }

        $success = 0;
        $failed = 0;
        $items = [];

        $scheme =
            (!empty($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off')
            ? 'https'
            : 'http';

        $host =
            (string)(
                $_SERVER['HTTP_HOST'] ?? ''
            );

        $base =
            $scheme . '://' . $host .
            dirname(
                (string)(
                    $_SERVER['SCRIPT_NAME'] ?? ''
                )
            );

        $base = rtrim(
            $base,
            '/'
        );

        foreach (
            $data['customers']
            as &$customer
        ) {
            $customerId =
                (string)(
                    $customer['id'] ?? ''
                );

            if (
                !in_array(
                    $customerId,
                    array_map(
                        'strval',
                        $recipientIds
                    ),
                    true
                )
            ) {
                continue;
            }

            $email =
                trim(
                    (string)(
                        $customer['email'] ?? ''
                    )
                );

            if (
                $email === '' ||
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                $failed++;
                continue;
            }

            $surveyUrl =
                $base .
                '/index.php?survey=' .
                rawurlencode($surveyId) .
                '&customer=' .
                rawurlencode($customerId);

            $actualSubject =
                str_replace(
                    [
                        '{顧客名}',
                        '{アンケートURL}',
                    ],
                    [
                        (string)(
                            $customer['name'] ?? ''
                        ),
                        $surveyUrl,
                    ],
                    $subject
                );

            $actualBody =
                str_replace(
                    [
                        '{顧客名}',
                        '{アンケートURL}',
                    ],
                    [
                        (string)(
                            $customer['name'] ?? ''
                        ),
                        $surveyUrl,
                    ],
                    $body
                );

            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'From: ' .
                    (
                        string)(
                            $_SERVER['SERVER_ADMIN']
                            ?? 'no-reply@localhost'
                        ),
            ];

            $sent = @mail(
                $email,
                mb_encode_mimeheader(
                    $actualSubject,
                    'UTF-8'
                ),
                $actualBody,
                implode(
                    "\r\n",
                    $headers
                )
            );

            if ($sent) {
                $success++;

                $customer['sent_at'] =
                    survey_now();

                $customer['send_count'] =
                    (int)(
                        $customer['send_count']
                        ?? 0
                    ) + 1;

                $customer['answer_status'] =
                    'unanswered';

                $items[] = [
                    'customer_id' =>
                        $customerId,
                    'email' => $email,
                    'subject' =>
                        $actualSubject,
                    'body' =>
                        $actualBody,
                ];
            } else {
                $failed++;
            }
        }

        unset($customer);

        $data['mail_logs'][] = [
            'id' => survey_id(),
            'survey_id' => $surveyId,
            'sent_at' => survey_now(),
            'template_type' => $templateType,
            'count' => $success,
            'failed' => $failed,
            'subject' => $subject,
            'items' => $items,
        ];

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'message' =>
                "送信処理完了\n"
                . "成功: {$success}件\n"
                . "失敗: {$failed}件",
            'success_count' => $success,
            'failed_count' => $failed,
        ]);
    }

    survey_api([
        'ok' => false,
        'message' =>
            'Unknown action: ' . $action,
    ], 400);
}

/* ================================================================
 * CSV GET
 * ================================================================ */

if (
    (string)($_GET['action'] ?? '') ===
    'csv'
) {
    survey_csv_download(
        $data,
        (string)(
            $_GET['survey_id'] ?? ''
        )
    );
}

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-slate-100 text-slate-800">

<div id="app"></div>

<script>
'use strict';

window.App = {

State: {
    data: null,
    csrf: '',
    page: 'list',
    editing: null,
    selectedSurvey: null,
    sendSurvey: null,
    fields: [],
    selectedCustomers: new Set(),
    customerFilter: '',
    statusFilter: '',
    keyword: '',
    sort: 'updated_desc',
    summaryQuestions: new Set(),
    previewMode: 'pc',
    dirty: false
},

escape(value) {
    const element = document.createElement('div');
    element.textContent = value == null ? '' : String(value);
    return element.innerHTML;
},

json(value) {
    return JSON.stringify(value)
        .replace(/</g, '\\u003c')
        .replace(/>/g, '\\u003e')
        .replace(/&/g, '\\u0026');
},

async api(action, params = {}, method = 'POST') {

    let url = location.pathname;

    if (method === 'GET') {
        const query = new URLSearchParams(params);
        query.set('action', action);
        url += '?' + query.toString();
    }

    const body = new URLSearchParams();

    if (method !== 'GET') {
        body.set('action', action);

        if (App.State.csrf) {
            body.set(
                'csrf_token',
                App.State.csrf
            );
        }

        Object.entries(params).forEach(([key, value]) => {
            body.set(
                key,
                typeof value === 'object'
                    ? JSON.stringify(value)
                    : String(value)
            );
        });
    }

    const response = await fetch(url, {
        method,
        headers:
            method === 'POST'
                ? {
                    'Content-Type':
                        'application/x-www-form-urlencoded;charset=UTF-8'
                }
                : {},
        body:
            method === 'POST'
                ? body
                : undefined
    });

    const text = await response.text();

    let json;

    try {
        json = JSON.parse(text);
    } catch (error) {
        throw new Error(
            'サーバーからJSONではない応答が返りました。\n'
            + 'HTTP: ' + response.status
            + '\n'
            + text.slice(0, 500)
        );
    }

    if (
        !response.ok ||
        json.ok === false
    ) {
        throw new Error(
            json.message ||
            '処理に失敗しました。'
        );
    }

    return json;
},

async init() {

    if (App.State.initialized) {
        return;
    }

    App.State.initialized = true;

    try {

        const csrf =
            await App.api('csrf');

        App.State.csrf =
            csrf.csrf_token;

        const result =
            await App.api('get_data');

        App.State.data =
            result.data;

        App.render();

    } catch (error) {

        document.getElementById('app').innerHTML = `
<div class="min-h-screen flex items-center justify-center p-6">
<div class="bg-white rounded-2xl shadow-sm p-8 max-w-2xl w-full">
<h1 class="text-2xl font-bold text-red-600">
アプリを起動できません
</h1>
<pre class="bg-slate-900 text-white rounded-xl p-4 mt-5 whitespace-pre-wrap text-sm">${App.escape(error.message)}</pre>
<p class="text-slate-500 mt-4">
survey_storageの書き込み権限、PHP設定、サーバー環境を確認してください。
</p>
</div>
</div>`;
    }
},

refreshData: async function() {

    const result =
        await App.api('get_data');

    App.State.data =
        result.data;
},

header() {

    return `
<header class="sticky top-0 z-30 bg-white border-b border-slate-200">
<div class="max-w-7xl mx-auto px-4 py-3">
<div class="flex flex-wrap items-center justify-between gap-3">

<div>
<div class="font-bold text-lg">
アンケート管理システム
</div>
<div class="text-xs text-slate-400">
Survey Management
</div>
</div>

<nav class="flex flex-wrap gap-2">
<button
onclick="App.actions.goList()"
class="px-3 py-2 rounded-lg text-sm bg-slate-100 hover:bg-slate-200">
アンケート一覧
</button>

<button
onclick="App.actions.settings()"
class="px-3 py-2 rounded-lg text-sm bg-slate-100 hover:bg-slate-200">
キントーン連携設定
</button>

<button
onclick="App.actions.reload()"
class="px-3 py-2 rounded-lg text-sm bg-slate-100 hover:bg-slate-200">
再読み込み
</button>
</nav>

</div>
</div>
</header>`;
},

statusBadge(status) {

    const map = {
        active: ['公開中', 'bg-green-100 text-green-700'],
        draft: ['下書き', 'bg-slate-100 text-slate-600'],
        ended: ['終了', 'bg-red-100 text-red-700']
    };

    const item =
        map[status] ||
        ['不明', 'bg-slate-100 text-slate-500'];

    return `
<span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold ${item[1]}">
${item[0]}
</span>`;
},

formatDate(value) {

    if (!value) {
        return '未設定';
    }

    const text =
        String(value).replace(
            /^(\d{4})-(\d{2})-(\d{2}).*$/,
            '$1/$2/$3'
        );

    return text;
},

actions: {

goList() {

    App.State.page = 'list';
    App.render();
},

reload: async function() {

    try {
        await App.refreshData();
        App.render();
    } catch (error) {
        alert(error.message);
    }
},

newSurvey() {

    App.State.editing = {
        id: '',
        title: '新しいアンケート',
        start_at: '',
        end_at: '',
        status: 'draft',
        created_at: '',
        updated_at: '',
        numbering_mode: 'global',
        groups: [
            {
                id: App.uuid(),
                name: 'グループ1',
                questions: []
            }
        ],
        deleted: false
    };

    App.State.dirty = false;
    App.State.page = 'edit';
    App.render();
},

editSurvey(id) {

    const survey =
        App.State.data.surveys.find(
            item => item.id === id
        );

    if (!survey) {
        return;
    }

    App.State.editing =
        JSON.parse(
            JSON.stringify(survey)
        );

    App.State.dirty = false;
    App.State.page = 'edit';
    App.render();
},

viewSurvey(id) {

    App.actions.editSurvey(id);
},

duplicate: async function(id) {

    try {

        if (
            !confirm(
                'このアンケートを複製しますか？'
            )
        ) {
            return;
        }

        await App.api(
            'duplicate_survey',
            { survey_id: id }
        );

        await App.refreshData();
        App.render();

    } catch (error) {
        alert(error.message);
    }
},

deleteSurvey: async function(id) {

    if (
        !confirm(
            'この下書きを削除しますか？\n'
            + '論理削除されます。'
        )
    ) {
        return;
    }

    try {

        await App.api(
            'delete_survey',
            { survey_id: id }
        );

        await App.refreshData();
        App.render();

    } catch (error) {
        alert(error.message);
    }
},

stopSurvey: async function(id) {

    if (
        !confirm(
            'このアンケートを停止しますか？'
        )
    ) {
        return;
    }

    try {

        await App.api(
            'change_status',
            {
                survey_id: id,
                status: 'ended'
            }
        );

        await App.refreshData();
        App.render();

    } catch (error) {
        alert(error.message);
    }
},

resumeSurvey: async function(id) {

    if (
        !confirm(
            'このアンケートを再公開しますか？'
        )
    ) {
        return;
    }

    try {

        await App.api(
            'change_status',
            {
                survey_id: id,
                status: 'active'
            }
        );

        await App.refreshData();
        App.render();

    } catch (error) {
        alert(error.message);
    }
},

summary(id) {

    App.State.selectedSurvey =
        App.State.data.surveys.find(
            item => item.id === id
        );

    const questions =
        App.questions(
            App.State.selectedSurvey
        );

    App.State.summaryQuestions =
        new Set(
            questions.map(
                question => question.id
            )
        );

    App.State.page = 'summary';
    App.render();
},

send(id) {

    App.State.sendSurvey =
        App.State.data.surveys.find(
            item => item.id === id
        );

    App.State.selectedCustomers =
        new Set();

    App.State.customerFilter = '';
    App.State.page = 'send';
    App.render();
},

settings() {

    App.State.page = 'settings';
    App.render();
},

updateTitle(value) {

    App.State.editing.title = value;
    App.State.dirty = true;
},

updateField(path, value) {

    const parts = path.split('.');
    let target =
        App.State.editing;

    for (
        let i = 0;
        i < parts.length - 1;
        i++
    ) {
        target =
            target[parts[i]];
    }

    target[
        parts[parts.length - 1]
    ] = value;

    App.State.dirty = true;
},

addGroup() {

    App.State.editing.groups.push({
        id: App.uuid(),
        name:
            'グループ' +
            (App.State.editing.groups.length + 1),
        questions: []
    });

    App.State.dirty = true;
    App.renderEdit();
},

removeGroup(groupId) {

    if (
        !confirm(
            'グループと内包する質問を削除しますか？'
        )
    ) {
        return;
    }

    App.State.editing.groups =
        App.State.editing.groups.filter(
            group => group.id !== groupId
        );

    if (
        App.State.editing.groups.length === 0
    ) {
        App.addGroup();
        return;
    }

    App.State.dirty = true;
    App.renderEdit();
},

addQuestion(groupId) {

    const group =
        App.State.editing.groups.find(
            item => item.id === groupId
        );

    if (!group) {
        return;
    }

    group.questions.push({
        id: App.uuid(),
        text: '新しい質問',
        type: 'single',
        required: false,
        options: ['選択肢1', '選択肢2'],
        other_enabled: false,
        branch: {}
    });

    App.State.dirty = true;
    App.renderEdit();
},

removeQuestion(groupId, questionId) {

    const group =
        App.State.editing.groups.find(
            item => item.id === groupId
        );

    if (!group) {
        return;
    }

    group.questions =
        group.questions.filter(
            question =>
                question.id !== questionId
        );

    App.State.dirty = true;
    App.renderEdit();
},

changeQuestionType(
    groupId,
    questionId,
    type
) {

    const question =
        App.findQuestion(
            groupId,
            questionId
        );

    if (!question) {
        return;
    }

    question.type = type;

    if (
        type === 'text'
    ) {
        question.options = [];
        question.other_enabled = false;
    }

    if (
        type !== 'text' &&
        (!question.options ||
        question.options.length === 0)
    ) {
        question.options = [
            '選択肢1',
            '選択肢2'
        ];
    }

    App.State.dirty = true;
    App.renderEdit();
},

addOption(groupId, questionId) {

    const question =
        App.findQuestion(
            groupId,
            questionId
        );

    if (!question) {
        return;
    }

    question.options =
        question.options || [];

    question.options.push(
        '選択肢' +
        (question.options.length + 1)
    );

    App.State.dirty = true;
    App.renderEdit();
},

removeOption(
    groupId,
    questionId,
    index
) {

    const question =
        App.findQuestion(
            groupId,
            questionId
        );

    if (!question) {
        return;
    }

    question.options.splice(
        index,
        1
    );

    App.State.dirty = true;
    App.renderEdit();
},

updateOption(
    groupId,
    questionId,
    index,
    value
) {

    const question =
        App.findQuestion(
            groupId,
            questionId
        );

    if (!question) {
        return;
    }

    question.options[index] =
        value;

    App.State.dirty = true;
},

toggleRequired(
    groupId,
    questionId,
    value
) {

    const question =
        App.findQuestion(
            groupId,
            questionId
        );

    if (question) {
        question.required = !!value;
        App.State.dirty = true;
    }
},

toggleOther(
    groupId,
    questionId,
    value
) {

    const question =
        App.findQuestion(
            groupId,
            questionId
        );

    if (question) {
        question.other_enabled = !!value;
        App.State.dirty = true;
    }
},

updateQuestionText(
    groupId,
    questionId,
    value
) {

    const question =
        App.findQuestion(
            groupId,
            questionId
        );

    if (question) {
        question.text = value;
        App.State.dirty = true;
    }
},

updateGroupName(
    groupId,
    value
) {

    const group =
        App.State.editing.groups.find(
            item => item.id === groupId
        );

    if (group) {
        group.name = value;
        App.State.dirty = true;
    }
},

saveSurvey: async function() {

    const survey =
        App.State.editing;

    if (
        !survey.title.trim()
    ) {
        alert(
            'アンケートタイトルを入力してください。'
        );
        return;
    }

    App.renumber(
        survey
    );

    try {

        await App.api(
            'save_survey',
            {
                survey_json: survey
            }
        );

        App.State.dirty = false;

        await App.refreshData();

        alert(
            'アンケートを保存しました。'
        );

        App.State.page = 'list';
        App.render();

    } catch (error) {
        alert(error.message);
    }
},

cancelEdit() {

    if (
        App.State.dirty &&
        !confirm(
            '未保存の変更を破棄して一覧へ戻りますか？'
        )
    ) {
        return;
    }

    App.State.dirty = false;
    App.State.page = 'list';
    App.render();
},

preview() {

    App.State.previewMode = 'pc';

    const modal =
        document.getElementById(
            'preview_modal'
        );

    if (!modal) {
        App.renderEdit();
    }

    App.renderPreview();
},

closePreview() {

    const modal =
        document.getElementById(
            'preview_modal'
        );

    if (modal) {
        modal.remove();
    }
},

previewSubmit() {

    alert(
        'これはプレビューです。\n'
        + '実際の回答送信は行われません。'
    );
},

togglePreviewMode(mode) {

    App.State.previewMode = mode;
    App.renderPreview();
},

toggleStatusFilter(value) {

    App.State.statusFilter = value;
    App.renderList();
},

searchKeyword(value) {

    App.State.keyword = value;
    App.renderList();
},

changeSort(value) {

    App.State.sort = value;
    App.renderList();
},

toggleCustomer(id) {

    if (
        App.State.selectedCustomers.has(id)
    ) {
        App.State.selectedCustomers.delete(id);
    } else {
        App.State.selectedCustomers.add(id);
    }

    App.renderSend();
},

toggleAllCustomers(checked) {

    App.State.selectedCustomers =
        new Set();

    if (checked) {
        const filter =
            App.State.customerFilter
                .toLowerCase();

        App.State.data.customers
            .filter(customer => {

                if (
                    customer.source !==
                    'kintone'
                ) {
                    return false;
                }

                if (!customer.email) {
                    return false;
                }

                if (!filter) {
                    return true;
                }

                return App.customerMatches(
                    customer,
                    filter
                );
            })
            .forEach(customer => {
                App.State.selectedCustomers
                    .add(customer.id);
            });
    }

    App.renderSend();
},

filterCustomers(value) {

    App.State.customerFilter =
        value;

    App.renderSend();
},

reminderOnly() {

    const survey =
        App.State.sendSurvey;

    App.State.selectedCustomers =
        new Set();

    App.State.data.customers
        .filter(customer =>
            customer.source === 'kintone' &&
            customer.email &&
            customer.answer_status !==
                'answered' &&
            Number(customer.send_count || 0) > 0
        )
        .forEach(customer => {
            App.State.selectedCustomers
                .add(customer.id);
        });

    App.renderSend();

    const template =
        document.getElementById(
            'template_type'
        );

    if (template) {
        template.value =
            'reminder';
    }
},

executeSend: async function() {

    const ids = [
        ...App.State.selectedCustomers
    ];

    if (!ids.length) {
        alert(
            '送信先を選択してください。'
        );
        return;
    }

    const subject =
        document.getElementById(
            'mail_subject'
        )?.value || '';

    const body =
        document.getElementById(
            'mail_body'
        )?.value || '';

    const templateType =
        document.getElementById(
            'template_type'
        )?.value || 'initial';

    if (
        !subject.trim() ||
        !body.trim()
    ) {
        alert(
            '件名と本文を入力してください。'
        );
        return;
    }

    const customers =
        App.State.data.customers.filter(
            customer =>
                ids.includes(customer.id)
        );

    const already =
        customers.filter(
            customer =>
                Number(
                    customer.send_count || 0
                ) > 0
        );

    if (already.length) {

        if (
            !confirm(
                '既に送信済みの宛先が'
                + already.length
                + '件含まれています。\n'
                + '再送しますか？'
            )
        ) {
            return;
        }
    }

    if (
        !confirm(
            customers.length
            + '件へメール送信します。\n'
            + '送信を実行しますか？'
        )
    ) {
        return;
    }

    try {

        const result =
            await App.api(
                'send_mail',
                {
                    survey_id:
                        App.State.sendSurvey.id,
                    recipient_ids: ids,
                    mail_subject: subject,
                    mail_body: body,
                    template_type:
                        templateType
                }
            );

        alert(result.message);

        await App.refreshData();

        App.State.page = 'list';
        App.render();

    } catch (error) {
        alert(error.message);
    }
},

syncCustomers: async function() {

    if (
        !confirm(
            'kintoneから顧客データを取得しますか？'
        )
    ) {
        return;
    }

    try {

        const result =
            await App.api(
                'sync_customers'
            );

        alert(result.message);

        await App.refreshData();
        App.renderSend();

    } catch (error) {
        alert(error.message);
    }
},

registerKintone: async function(id) {

    try {

        await App.api(
            'register_kintone',
            {
                customer_id: id
            }
        );

        await App.refreshData();
        App.renderSend();

    } catch (error) {
        alert(error.message);
    }
},

saveSettings: async function() {

    const fields = [
        'subdomain',
        'login_name',
        'app_id',
        'proxy',
        'field_company',
        'field_name',
        'field_email',
        'field_department',
        'field_phone'
    ];

    const settings = {};

    fields.forEach(key => {

        const element =
            document.getElementById(
                'setting_' + key
            );

        settings[key] =
            element?.value || '';
    });

    settings.password =
        document.getElementById(
            'setting_password'
        )?.value || '';

    settings.ssl_verify =
        document.getElementById(
            'setting_ssl_verify'
        )?.checked ?? true;

    settings.field_address =
        Array.from(
            document.querySelectorAll(
                '.address_field:checked'
            )
        ).map(
            element => element.value
        );

    try {

        await App.api(
            'save_settings',
            {
                settings_json: settings
            }
        );

        alert(
            '設定を保存しました。'
        );

        await App.refreshData();
        App.render();

    } catch (error) {
        alert(error.message);
    }
},

testKintone: async function() {

    const settings =
        App.readSettings();

    const message =
        document.getElementById(
            'field_message'
        );

    if (message) {
        message.textContent =
            '接続確認中...';
        message.className =
            'mt-4 p-4 rounded-xl bg-slate-100 whitespace-pre-wrap text-sm';
    }

    try {

        const result =
            await App.api(
                'kintone_test',
                {
                    settings_json: settings
                }
            );

        App.State.fields =
            result.fields || [];

        if (message) {
            message.textContent =
                result.message;
            message.className =
                'mt-4 p-4 rounded-xl bg-green-50 text-green-700 whitespace-pre-wrap text-sm';
        }

        App.renderSettings();

    } catch (error) {

        if (message) {
            message.textContent =
                error.message;
            message.className =
                'mt-4 p-4 rounded-xl bg-red-50 text-red-700 whitespace-pre-wrap text-sm';
        }
    }
},

fetchKintoneFields: async function() {

    const settings =
        App.readSettings();

    const message =
        document.getElementById(
            'field_message'
        );

    if (message) {
        message.textContent =
            '項目一覧を取得中...';
    }

    try {

        const result =
            await App.api(
                'kintone_fields',
                {
                    settings_json: settings
                }
            );

        App.State.fields =
            result.fields || [];

        if (message) {
            message.textContent =
                result.message;
            message.className =
                'mt-4 p-4 rounded-xl bg-green-50 text-green-700 whitespace-pre-wrap text-sm';
        }

        App.renderSettings();

    } catch (error) {

        if (message) {
            message.textContent =
                error.message;
            message.className =
                'mt-4 p-4 rounded-xl bg-red-50 text-red-700 whitespace-pre-wrap text-sm';
        }
    }
},

toggleSummaryQuestion(id, checked) {

    if (checked) {
        App.State.summaryQuestions.add(id);
    } else {
        App.State.summaryQuestions.delete(id);
    }

    App.renderSummary();
},

selectAllSummaryQuestions() {

    const survey =
        App.State.selectedSurvey;

    App.State.summaryQuestions =
        new Set(
            App.questions(survey)
                .map(
                    question =>
                        question.id
                )
        );

    App.renderSummary();
},

clearSummaryQuestions() {

    App.State.summaryQuestions =
        new Set();

    App.renderSummary();
},

filterResponses(value) {

    App.State.responseFilter =
        value;

    App.renderSummary();
},

showResponse(responseId) {

    const response =
        App.State.data.responses.find(
            item =>
                item.id === responseId
        );

    if (!response) {
        return;
    }

    const modal =
        document.getElementById(
            'response_modal'
        );

    if (modal) {
        modal.remove();
    }

    document.body.insertAdjacentHTML(
        'beforeend',
        App.responseModal(response)
    );
},

closeResponse() {

    document.getElementById(
        'response_modal'
    )?.remove();
}

},

uuid() {

    if (
        window.crypto &&
        crypto.randomUUID
    ) {
        return crypto.randomUUID();
    }

    return (
        Date.now().toString(36) +
        Math.random().toString(36).slice(2)
    );
},

questions(survey) {

    if (!survey) {
        return [];
    }

    return (survey.groups || [])
        .flatMap(
            group =>
                group.questions || []
        );
},

findQuestion(
    groupId,
    questionId
) {

    const group =
        App.State.editing.groups.find(
            item =>
                item.id === groupId
        );

    if (!group) {
        return null;
    }

    return group.questions.find(
        question =>
            question.id === questionId
    ) || null;
},

renumber(survey) {

    let number = 1;

    if (
        survey.numbering_mode ===
        'global'
    ) {

        survey.groups.forEach(group => {

            group.questions.forEach(
                question => {

                    question.display_number =
                        'Q' + number;

                    number++;
                }
            );
        });

    } else {

        survey.groups.forEach(
            (group, groupIndex) => {

                group.questions.forEach(
                    (
                        question,
                        questionIndex
                    ) => {

                        question.display_number =
                            'Q' +
                            (groupIndex + 1) +
                            '-' +
                            (questionIndex + 1);
                    }
                );
            }
        );
    }
},

customerMatches(
    customer,
    filter
) {

    return [
        customer.company,
        customer.name,
        customer.email,
        customer.phone,
        customer.address
    ].some(value =>
        String(value || '')
            .toLowerCase()
            .includes(filter)
    );
},

readSettings() {

    const data =
        App.State.data.settings;

    const get =
        key =>
            document.getElementById(
                'setting_' + key
            )?.value ??
            data[key] ??
            '';

    return {
        subdomain: get('subdomain'),
        login_name: get('login_name'),
        password:
            document.getElementById(
                'setting_password'
            )?.value || '',
        app_id: get('app_id'),
        proxy: get('proxy'),
        ssl_verify:
            document.getElementById(
                'setting_ssl_verify'
            )?.checked ?? true,
        field_company:
            get('field_company'),
        field_name:
            get('field_name'),
        field_email:
            get('field_email'),
        field_department:
            get('field_department'),
        field_phone:
            get('field_phone'),
        field_address:
            Array.from(
                document.querySelectorAll(
                    '.address_field:checked'
                )
            ).map(
                element =>
                    element.value
            )
    };
},

render() {

    if (!App.State.data) {
        return;
    }

    switch (App.State.page) {

        case 'edit':
            App.renderEdit();
            break;

        case 'summary':
            App.renderSummary();
            break;

        case 'send':
            App.renderSend();
            break;

        case 'settings':
            App.renderSettings();
            break;

        default:
            App.renderList();
    }
},

renderList() {

    const data =
        App.State.data;

    let surveys =
        data.surveys.filter(
            survey =>
                !survey.deleted
        );

    const keyword =
        App.State.keyword
            .trim()
            .toLowerCase();

    if (keyword) {
        surveys =
            surveys.filter(
                survey =>
                    String(
                        survey.title || ''
                    )
                    .toLowerCase()
                    .includes(keyword)
            );
    }

    if (App.State.statusFilter) {
        surveys =
            surveys.filter(
                survey =>
                    survey.status ===
                    App.State.statusFilter
            );
    }

    const responseCount =
        survey =>
            data.responses.filter(
                response =>
                    response.survey_id ===
                    survey.id
            ).length;

    surveys.sort((a, b) => {

        switch (App.State.sort) {

            case 'updated_asc':
                return String(
                    a.updated_at
                ).localeCompare(
                    String(b.updated_at)
                );

            case 'answers_desc':
                return (
                    responseCount(b) -
                    responseCount(a)
                );

            case 'answers_asc':
                return (
                    responseCount(a) -
                    responseCount(b)
                );

            case 'start_desc':
                return String(
                    b.start_at || ''
                ).localeCompare(
                    String(a.start_at || '')
                );

            case 'start_asc':
                return String(
                    a.start_at || ''
                ).localeCompare(
                    String(b.start_at || '')
                );

            default:
                return String(
                    b.updated_at
                ).localeCompare(
                    String(a.updated_at)
                );
        }
    });

    document.getElementById(
        'app'
    ).innerHTML = `
${App.header()}

<main class="max-w-7xl mx-auto p-4 md:p-8">

<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
<div>
<h1 class="text-2xl font-bold">
アンケート一覧
</h1>
<p class="text-slate-500 mt-1">
作成・公開・送信・集計を一元管理
</p>
</div>

<button
onclick="App.actions.newSurvey()"
class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-5 py-3 rounded-xl shadow-sm">
＋ 新規アンケート作成
</button>
</div>

<div class="bg-white rounded-2xl shadow-sm p-4 mb-5">
<div class="grid md:grid-cols-3 gap-3">

<input
value="${App.escape(App.State.keyword)}"
placeholder="タイトルを検索してEnter"
onkeydown="if(event.key==='Enter'){App.actions.searchKeyword(this.value)}"
class="border border-slate-300 rounded-lg px-3 py-2">

<select
onchange="App.actions.toggleStatusFilter(this.value)"
class="border border-slate-300 rounded-lg px-3 py-2">
<option value="">すべて</option>
<option value="active" ${App.State.statusFilter==='active'?'selected':''}>
公開中
</option>
<option value="draft" ${App.State.statusFilter==='draft'?'selected':''}>
下書き
</option>
<option value="ended" ${App.State.statusFilter==='ended'?'selected':''}>
終了
</option>
</select>

<select
onchange="App.actions.changeSort(this.value)"
class="border border-slate-300 rounded-lg px-3 py-2">
<option value="updated_desc" ${App.State.sort==='updated_desc'?'selected':''}>
更新日：新しい順
</option>
<option value="updated_asc" ${App.State.sort==='updated_asc'?'selected':''}>
更新日：古い順
</option>
<option value="answers_desc" ${App.State.sort==='answers_desc'?'selected':''}>
回答数：多い順
</option>
<option value="answers_asc" ${App.State.sort==='answers_asc'?'selected':''}>
回答数：少ない順
</option>
<option value="start_desc" ${App.State.sort==='start_desc'?'selected':''}>
期間開始：新しい順
</option>
<option value="start_asc" ${App.State.sort==='start_asc'?'selected':''}>
期間開始：古い順
</option>
</select>

</div>
</div>

<div class="bg-white rounded-2xl shadow-sm overflow-hidden">
<div class="overflow-x-auto">

<table class="min-w-[1100px] w-full text-sm">
<thead class="bg-slate-50 border-b">
<tr>
<th class="text-left p-4">作成日 / 更新日</th>
<th class="text-left p-4">タイトル</th>
<th class="text-left p-4">アンケート期間</th>
<th class="text-left p-4">ステータス</th>
<th class="text-right p-4">回答数</th>
<th class="text-left p-4">操作</th>
</tr>
</thead>

<tbody class="divide-y">

${
surveys.length
? surveys.map(survey => {

const count =
responseCount(survey);

let actions = '';

if (survey.status === 'active') {

actions = `
<button
onclick="App.actions.editSurvey('${App.escape(survey.id)}')"
class="px-2.5 py-1.5 rounded-lg bg-slate-100">
確認・編集
</button>

<button
onclick="App.actions.summary('${App.escape(survey.id)}')"
class="px-2.5 py-1.5 rounded-lg bg-blue-50 text-blue-700">
集計
</button>

<button
onclick="App.actions.send('${App.escape(survey.id)}')"
class="px-2.5 py-1.5 rounded-lg bg-blue-600 text-white">
送信
</button>

<button
onclick="App.actions.stopSurvey('${App.escape(survey.id)}')"
class="px-2.5 py-1.5 rounded-lg bg-red-50 text-red-700">
停止
</button>

<button
onclick="App.actions.duplicate('${App.escape(survey.id)}')"
class="px-2.5 py-1.5 rounded-lg bg-slate-100">
複製
</button>`;

} else if (
survey.status === 'draft'
) {

actions = `
<button
onclick="App.actions.editSurvey('${App.escape(survey.id)}')"
class="px-2.5 py-1.5 rounded-lg bg-slate-100">
確認・編集
</button>

<button
onclick="App.actions.deleteSurvey('${App.escape(survey.id)}')"
class="px-2.5 py-1.5 rounded-lg bg-red-50 text-red-700">
削除
</button>

<button
onclick="App.actions.duplicate('${App.escape(survey.id)}')"
class="px-2.5 py-1.5 rounded-lg bg-slate-100">
複製
</button>`;

} else {

actions = `
<button
onclick="App.actions.editSurvey('${App.escape(survey.id)}')"
class="px-2.5 py-1.5 rounded-lg bg-slate-100">
確認・編集
</button>

<button
onclick="App.actions.summary('${App.escape(survey.id)}')"
class="px-2.5 py-1.5 rounded-lg bg-blue-50 text-blue-700">
集計
</button>

<button
onclick="App.actions.duplicate('${App.escape(survey.id)}')"
class="px-2.5 py-1.5 rounded-lg bg-slate-100">
複製
</button>`;

}

return `
<tr class="hover:bg-slate-50">
<td class="p-4 whitespace-nowrap">
<div>${App.formatDate(survey.created_at)}</div>
<div class="text-xs text-slate-400">
更新: ${App.formatDate(survey.updated_at)}
</div>
</td>

<td class="p-4">
<div class="font-bold">
${App.escape(survey.title)}
</div>
</td>

<td class="p-4 whitespace-nowrap">
${
survey.start_at || survey.end_at
? `${App.escape(survey.start_at || '未設定')}
～ ${App.escape(survey.end_at || '未設定')}`
: '未設定'
}
</td>

<td class="p-4">
${App.statusBadge(survey.status)}
</td>

<td class="p-4 text-right font-semibold">
${count.toLocaleString()} 件
</td>

<td class="p-4">
<div class="flex flex-wrap gap-1.5">
${actions}
</div>
</td>
</tr>`;

}).join('')
: `
<tr>
<td colspan="6" class="p-12 text-center text-slate-400">
該当するアンケートがありません。
</td>
</tr>`
}

</tbody>
</table>

</div>
</div>

</main>`;
},

renderEdit() {

    const survey =
        App.State.editing;

    App.renumber(survey);

    document.getElementById(
        'app'
    ).innerHTML = `
${App.header()}

<main class="max-w-6xl mx-auto p-4 md:p-8">

<div class="flex flex-wrap justify-between gap-4 mb-6">

<div class="flex-1">
<p class="text-sm text-blue-600 font-semibold">
アンケート作成・編集
</p>

<input
id="survey_title"
value="${App.escape(survey.title)}"
oninput="App.actions.updateTitle(this.value)"
class="mt-1 text-2xl font-bold bg-transparent border-b border-transparent focus:border-blue-500 outline-none w-full">

</div>

<div class="flex flex-wrap gap-2">

<button
onclick="App.actions.preview()"
class="px-4 py-2 rounded-lg bg-slate-200">
プレビュー
</button>

<button
onclick="App.actions.cancelEdit()"
class="px-4 py-2 rounded-lg bg-slate-200">
キャンセル
</button>

<button
onclick="App.actions.saveSurvey()"
class="px-4 py-2 rounded-lg bg-blue-600 text-white font-semibold">
保存して一覧へ戻る
</button>

</div>
</div>

<section class="bg-white rounded-2xl shadow-sm p-5 mb-5">
<div class="grid md:grid-cols-3 gap-4">

<label>
<span class="text-sm font-semibold">
開始日時
</span>
<input
id="survey_start_at"
type="datetime-local"
value="${App.escape(survey.start_at)}"
onchange="App.actions.updateField('start_at',this.value)"
class="w-full border rounded-lg p-3 mt-1">
</label>

<label>
<span class="text-sm font-semibold">
終了日時
</span>
<input
id="survey_end_at"
type="datetime-local"
value="${App.escape(survey.end_at)}"
onchange="App.actions.updateField('end_at',this.value)"
class="w-full border rounded-lg p-3 mt-1">
</label>

<label>
<span class="text-sm font-semibold">
質問番号
</span>
<select
id="survey_numbering_mode"
onchange="App.actions.updateField('numbering_mode',this.value);App.renderEdit()"
class="w-full border rounded-lg p-3 mt-1">
<option value="global" ${survey.numbering_mode==='global'?'selected':''}>
Q1 / Q2 / Q3
</option>
<option value="group" ${survey.numbering_mode==='group'?'selected':''}>
Q1-1 / Q1-2 / Q2-1
</option>
</select>
</label>

</div>
</section>

<div class="flex items-center justify-between mb-3">
<h2 class="text-xl font-bold">
質問グループ
</h2>

<button
onclick="App.actions.addGroup()"
class="bg-blue-600 text-white rounded-lg px-4 py-2">
＋ グループ追加
</button>
</div>

<div id="question_editor" class="space-y-5">

${survey.groups.map(
(group, groupIndex) => `

<section
data-group-id="${App.escape(group.id)}"
class="group-card bg-white rounded-2xl shadow-sm p-5">

<div class="flex items-center gap-3 mb-5">

<div
class="group-handle cursor-grab text-xl text-slate-400">
⠿
</div>

<input
value="${App.escape(group.name)}"
oninput="App.actions.updateGroupName('${App.escape(group.id)}',this.value)"
class="font-bold text-lg flex-1 border-b border-transparent focus:border-blue-500 outline-none">

<button
onclick="App.actions.removeGroup('${App.escape(group.id)}')"
class="text-red-600 text-sm">
グループ削除
</button>

</div>

<div
class="question-list space-y-4"
data-group-id="${App.escape(group.id)}">

${group.questions.map(
(question, questionIndex) =>
App.questionEditor(
group,
question,
questionIndex
)
).join('')}

</div>

<button
onclick="App.actions.addQuestion('${App.escape(group.id)}')"
class="mt-4 w-full border-2 border-dashed border-slate-300 rounded-xl py-3 text-slate-500 hover:bg-slate-50">
＋ 質問を追加
</button>

</section>`
).join('')}

</div>

</main>

${App.previewModal()}`;

    App.initSortable();
},

questionEditor(
    group,
    question,
    questionIndex
) {

    return `
<article
data-question-id="${App.escape(question.id)}"
class="question-card border border-slate-200 rounded-xl p-4 bg-slate-50">

<div class="flex gap-3">

<div class="question-handle cursor-grab text-xl text-slate-400 pt-2">
⠿
</div>

<div class="flex-1">

<div class="flex flex-wrap items-center gap-2 mb-3">

<span class="font-bold text-blue-600">
${App.escape(
question.display_number ||
'Q' + (questionIndex + 1)
)}
</span>

<select
onchange="App.actions.changeQuestionType('${App.escape(group.id)}','${App.escape(question.id)}',this.value)"
class="border rounded-lg px-3 py-2 text-sm">
<option value="single" ${question.type==='single'?'selected':''}>
単一選択
</option>
<option value="multiple" ${question.type==='multiple'?'selected':''}>
複数選択
</option>
<option value="text" ${question.type==='text'?'selected':''}>
自由記述
</option>
</select>

<label class="flex items-center gap-2 text-sm">
<input
type="checkbox"
${question.required?'checked':''}
onchange="App.actions.toggleRequired('${App.escape(group.id)}','${App.escape(question.id)}',this.checked)">
必須回答
</label>

<button
onclick="App.actions.removeQuestion('${App.escape(group.id)}','${App.escape(question.id)}')"
class="ml-auto text-red-600 text-sm">
削除
</button>

</div>

<input
value="${App.escape(question.text)}"
oninput="App.actions.updateQuestionText('${App.escape(group.id)}','${App.escape(question.id)}',this.value)"
class="w-full border rounded-lg p-3 bg-white font-semibold">

${
question.type !== 'text'
? `
<div class="mt-4 space-y-2">

${(question.options || []).map(
(option, index) => `
<div class="flex gap-2">
<span class="pt-2 text-slate-400">
${question.type === 'single' ? '○' : '□'}
</span>

<input
value="${App.escape(option)}"
oninput="App.actions.updateOption('${App.escape(group.id)}','${App.escape(question.id)}',${index},this.value)"
class="flex-1 border rounded-lg p-2 bg-white">

<button
onclick="App.actions.removeOption('${App.escape(group.id)}','${App.escape(question.id)}',${index})"
class="text-red-500">
削除
</button>
</div>`
).join('')}

<button
onclick="App.actions.addOption('${App.escape(group.id)}','${App.escape(question.id)}')"
class="text-blue-600 text-sm">
＋ 選択肢追加
</button>

<label class="flex items-center gap-2 text-sm mt-3">
<input
type="checkbox"
${question.other_enabled?'checked':''}
onchange="App.actions.toggleOther('${App.escape(group.id)}','${App.escape(question.id)}',this.checked)">
その他を許可
</label>

${
question.type === 'single'
? `
<div class="mt-4">
<div class="text-xs text-slate-500 mb-1">
選択時の分岐先
</div>

<select
class="w-full border rounded-lg p-2"
onchange="
const q=App.findQuestion('${App.escape(group.id)}','${App.escape(question.id)}');
q.branch=this.value?{target:this.value}:{};App.State.dirty=true;">
<option value="">
分岐なし
</option>
${App.questions(App.State.editing)
.map(target => `
<option
value="${App.escape(target.id)}"
${question.branch?.target===target.id?'selected':''}>
${App.escape(
target.display_number ||
target.text
)}
</option>
`).join('')}
</select>
</div>`
: ''
}

</div>`
: `
<textarea
rows="4"
disabled
class="w-full mt-4 border rounded-lg p-3 bg-white"
placeholder="回答者が入力する自由記述欄"></textarea>`
}

</div>
</div>
</article>`;
},

initSortable() {

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
            handle: '.group-handle',
            ghostClass: 'opacity-40',
            onEnd() {

                const order =
                    Array.from(
                        editor.querySelectorAll(
                            ':scope > .group-card'
                        )
                    ).map(
                        element =>
                            element.dataset.groupId
                    );

                App.State.editing.groups.sort(
                    (a, b) =>
                        order.indexOf(a.id) -
                        order.indexOf(b.id)
                );

                App.State.dirty = true;
                App.renderEdit();
            }
        }
    );

    document.querySelectorAll(
        '.question-list'
    ).forEach(list => {

        new Sortable(
            list,
            {
                group: 'survey-questions',
                animation: 180,
                handle: '.question-handle',
                ghostClass: 'opacity-40',

                onEnd(event) {

                    const fromGroup =
                        App.State.editing.groups
                            .find(
                                group =>
                                    group.id ===
                                    event.from.dataset.groupId
                            );

                    const toGroup =
                        App.State.editing.groups
                            .find(
                                group =>
                                    group.id ===
                                    event.to.dataset.groupId
                            );

                    if (
                        !fromGroup ||
                        !toGroup
                    ) {
                        return;
                    }

                    const questionId =
                        event.item.dataset.questionId;

                    const questionIndex =
                        fromGroup.questions.findIndex(
                            question =>
                                question.id ===
                                questionId
                        );

                    if (
                        questionIndex < 0
                    ) {
                        return;
                    }

                    const [
                        question
                    ] =
                        fromGroup.questions.splice(
                            questionIndex,
                            1
                        );

                    const children =
                        Array.from(
                            event.to.children
                        );

                    let newIndex =
                        children.findIndex(
                            element =>
                                element ===
                                event.item
                        );

                    if (newIndex < 0) {
                        newIndex =
                            toGroup.questions.length;
                    }

                    toGroup.questions.splice(
                        newIndex,
                        0,
                        question
                    );

                    App.State.dirty = true;
                    App.renderEdit();
                }
            }
        );
    });
},

previewModal() {

    return `
<div
id="preview_modal"
class="hidden fixed inset-0 z-50 bg-black/50 p-4">

<div class="bg-white rounded-2xl max-w-4xl mx-auto h-[90vh] overflow-hidden">

<div class="p-4 border-b flex items-center justify-between">

<div class="font-bold">
回答者プレビュー
</div>

<div class="flex gap-2">

<button
onclick="App.actions.togglePreviewMode('pc')"
class="px-3 py-2 rounded-lg bg-slate-100">
PC表示
</button>

<button
onclick="App.actions.togglePreviewMode('mobile')"
class="px-3 py-2 rounded-lg bg-slate-100">
スマートフォン表示
</button>

<button
onclick="App.actions.closePreview()"
class="px-3 py-2 rounded-lg bg-slate-800 text-white">
閉じる
</button>

</div>
</div>

<div
id="preview_content"
class="overflow-auto h-[calc(90vh-70px)] p-5">
</div>

</div>
</div>`;
},

renderPreview() {

    const modal =
        document.getElementById(
            'preview_modal'
        );

    if (!modal) {
        return;
    }

    modal.classList.remove(
        'hidden'
    );

    const survey =
        App.State.editing;

    App.renumber(survey);

    const width =
        App.State.previewMode ===
        'mobile'
        ? 'max-w-sm'
        : 'max-w-2xl';

    document.getElementById(
        'preview_content'
    ).innerHTML = `
<div class="${width} mx-auto bg-slate-50 rounded-2xl p-5">

<h1 class="text-2xl font-bold mb-2">
${App.escape(survey.title)}
</h1>

<div class="space-y-7 mt-6">

${survey.groups.map(group => `
<section>
<h2 class="font-bold text-lg mb-4">
${App.escape(group.name)}
</h2>

<div class="space-y-5">

${group.questions.map(question => `
<div class="bg-white rounded-xl p-4">

<div class="font-semibold mb-3">
${App.escape(
question.display_number || ''
)}
.
${App.escape(question.text)}
${
question.required
? '<span class="text-red-500 ml-1">必須</span>'
: ''
}
</div>

${
question.type === 'single'
? `
<div class="space-y-2">
${(question.options || []).map(
option => `
<label class="flex gap-2">
<input type="radio" disabled>
<span>${App.escape(option)}</span>
</label>`
).join('')}
</div>`
: question.type === 'multiple'
? `
<div class="space-y-2">
${(question.options || []).map(
option => `
<label class="flex gap-2">
<input type="checkbox" disabled>
<span>${App.escape(option)}</span>
</label>`
).join('')}
</div>`
: `
<textarea
disabled
rows="4"
class="w-full border rounded-lg p-3">
</textarea>`
}

</div>
`).join('')}

</div>
</section>
`).join('')}

</div>

<button
onclick="App.actions.previewSubmit()"
class="w-full mt-8 bg-blue-600 text-white rounded-xl py-3 font-bold">
回答を送信する
</button>

</div>`;
},

renderSend() {

    const survey =
        App.State.sendSurvey;

    if (!survey) {
        App.State.page = 'list';
        App.render();
        return;
    }

    const filter =
        App.State.customerFilter
            .trim()
            .toLowerCase();

    let customers =
        App.State.data.customers
            .filter(customer => {

                if (!filter) {
                    return true;
                }

                return App.customerMatches(
                    customer,
                    filter
                );
            });

    const selected =
        App.State.selectedCustomers;

    document.getElementById(
        'app'
    ).innerHTML = `
${App.header()}

<main class="max-w-7xl mx-auto p-4 md:p-8">

<div class="mb-6">
<div class="text-sm text-blue-600 font-semibold">
ホーム ＞ アンケート一覧 ＞ 顧客選択・送信
</div>

<h1 class="text-2xl font-bold mt-2">
${App.escape(survey.title)}
</h1>

<p class="text-slate-500 mt-1">
顧客選択 → 本文確認 → 送信
</p>
</div>

<div class="grid lg:grid-cols-2 gap-5">

<section class="bg-white rounded-2xl shadow-sm p-5">

<div class="flex flex-wrap items-center justify-between gap-2 mb-4">
<h2 class="font-bold text-lg">
送信対象顧客
</h2>

<div class="flex gap-2">
<button
onclick="App.actions.syncCustomers()"
class="text-blue-600 text-sm">
kintoneから更新
</button>

<button
onclick="App.actions.reminderOnly()"
class="text-orange-600 text-sm">
未回答リマインド対象
</button>
</div>
</div>

<input
id="customer_filter"
value="${App.escape(App.State.customerFilter)}"
oninput="App.actions.filterCustomers(this.value)"
placeholder="顧客名・会社名・メールで検索"
class="w-full border rounded-lg p-3 mb-4">

<label class="flex items-center gap-2 border-b pb-3 mb-3">
<input
id="select_all"
type="checkbox"
onchange="App.actions.toggleAllCustomers(this.checked)"
${customers.length &&
customers
.filter(c =>
c.source === 'kintone' &&
c.email)
.every(c =>
selected.has(c.id))
? 'checked'
: ''}>
全選択
</label>

<div
id="customer_table"
class="max-h-[650px] overflow-y-auto space-y-2">

${
customers.length
? customers.map(customer => `

<div class="border rounded-xl p-3">

<div class="flex gap-3">

<input
type="checkbox"
${selected.has(customer.id) ? 'checked' : ''}
${customer.source !== 'kintone' || !customer.email ? 'disabled' : ''}
onchange="App.actions.toggleCustomer('${App.escape(customer.id)}')"
class="mt-1">

<div class="flex-1">

<div class="flex flex-wrap gap-2 items-center">
<div class="font-bold">
${App.escape(customer.company)}
</div>

${
customer.source === 'web'
? '<span class="px-2 py-0.5 rounded-full bg-orange-100 text-orange-700 text-xs">Web回答者</span>'
: ''
}
</div>

<div>
${App.escape(customer.name)}
</div>

<div class="text-sm text-slate-500">
${App.escape(customer.email)}
</div>

<div class="text-xs text-slate-500 mt-1">
${App.escape(customer.phone)}
${customer.phone && customer.address ? ' / ' : ''}
${App.escape(customer.address)}
</div>

<div class="flex flex-wrap gap-2 mt-2">

<span class="px-2 py-1 rounded-full text-xs ${
customer.answer_status === 'answered'
? 'bg-green-100 text-green-700'
: 'bg-orange-100 text-orange-700'
}">
${
customer.answer_status === 'answered'
? '回答済み'
: customer.send_count > 0
? '送信済み（未回答）'
: '未送信'
}
</span>

${
customer.kintone_status === 'registered'
? '<span class="text-green-600 text-xs">✓ キントーン登録完了</span>'
: `
<button
onclick="App.actions.registerKintone('${App.escape(customer.id)}')"
class="text-blue-600 text-xs">
キントーン登録完了
</button>`
}

</div>

${
customer.sent_at
? `
<div class="text-xs text-slate-400 mt-2">
最終送信: ${App.escape(customer.sent_at)}
／送信回数: ${Number(customer.send_count || 0)}
</div>`
: ''
}

</div>
</div>

</div>
`).join('')
: `
<div class="text-center text-slate-400 p-10">
顧客データがありません。
</div>`
}

</div>
</section>

<section class="bg-white rounded-2xl shadow-sm p-5">

<h2 class="font-bold text-lg mb-4">
メールテンプレート
</h2>

<div class="mb-4 p-3 rounded-xl bg-blue-50 text-blue-800 text-sm">
使用可能な変数:
<strong>{顧客名}</strong>
<strong>{アンケートURL}</strong>
</div>

<label class="block mb-4">
<span class="text-sm font-semibold">
送信種別
</span>
<select
id="template_type"
class="w-full border rounded-lg p-3 mt-1">
<option value="initial">
初回送信
</option>
<option value="reminder">
リマインド
</option>
</select>
</label>

<label class="block mb-4">
<span class="text-sm font-semibold">
件名
</span>
<input
id="mail_subject"
value="${App.escape(survey.title)}のご案内"
class="w-full border rounded-lg p-3 mt-1">
</label>

<label class="block">
<span class="text-sm font-semibold">
本文
</span>
<textarea
id="mail_body"
rows="15"
class="w-full border rounded-lg p-3 mt-1">{顧客名} 様

いつもお世話になっております。

下記URLよりアンケートへご回答ください。

{アンケートURL}

ご協力のほど、よろしくお願いいたします。</textarea>
</label>

<div class="mt-5 p-4 rounded-xl bg-slate-50">
<div class="font-semibold">
選択中
</div>
<div class="text-2xl font-bold text-blue-600 mt-1">
${selected.size} 件
</div>
</div>

<button
onclick="App.actions.executeSend()"
class="w-full mt-5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-4 font-bold">
一括送信を実行
</button>

</section>
</div>

</main>`;
},

renderSummary() {

    const survey =
        App.State.selectedSurvey;

    if (!survey) {
        App.State.page = 'list';
        App.render();
        return;
    }

    const questions =
        App.questions(survey);

    const responses =
        App.State.data.responses.filter(
            response =>
                response.survey_id ===
                survey.id
        );

    const customers =
        App.State.data.customers;

    const surveyCustomerIds =
        new Set(
            customers
                .filter(
                    customer =>
                        customer.source ===
                        'kintone'
                )
                .map(
                    customer =>
                        customer.id
                )
        );

    const recipients =
        customers.filter(
            customer =>
                survey.id &&
                Number(
                    customer.send_count || 0
                ) > 0
        );

    const recipientResponses =
        responses.filter(
            response =>
                surveyCustomerIds.has(
                    response.customer_id
                )
        );

    const webResponses =
        responses.filter(
            response =>
                !surveyCustomerIds.has(
                    response.customer_id
                )
        );

    const answeredCustomerIds =
        new Set(
            recipientResponses.map(
                response =>
                    response.customer_id
            )
        );

    const unanswered =
        Math.max(
            0,
            recipients.length -
            answeredCustomerIds.size
        );

    const rate =
        recipients.length
        ? (
            recipientResponses.length /
            recipients.length *
            100
        )
        : 0;

    const filter =
        (
            App.State.responseFilter ||
            ''
        ).toLowerCase();

    const filteredResponses =
        responses.filter(
            response =>
                !filter ||
                String(
                    response.company || ''
                )
                .toLowerCase()
                .includes(filter) ||
                String(
                    response.name || ''
                )
                .toLowerCase()
                .includes(filter)
        );

    document.getElementById(
        'app'
    ).innerHTML = `
${App.header()}

<main class="max-w-7xl mx-auto p-4 md:p-8">

<div class="flex flex-wrap justify-between gap-3 mb-6">

<div>
<div class="text-sm text-blue-600 font-semibold">
ホーム ＞ アンケート一覧 ＞ 集計
</div>

<h1 class="text-2xl font-bold mt-1">
${App.escape(survey.title)}
</h1>
</div>

<div class="flex gap-2">

<a
href="?action=csv&survey_id=${encodeURIComponent(survey.id)}"
class="px-4 py-2 rounded-lg bg-green-600 text-white">
CSV出力
</a>

<button
onclick="window.print()"
class="px-4 py-2 rounded-lg bg-slate-800 text-white">
PDF / 印刷
</button>

</div>
</div>

<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">

${[
['送信対象者数', recipients.length + ' 人'],
['回答数', responses.length + ' 件'],
['未登録顧客からの回答数', webResponses.length + ' 件'],
['未回答数', unanswered + ' 人'],
['回答率', rate.toFixed(1) + ' %']
].map(
item => `
<div class="bg-white rounded-2xl shadow-sm p-4">
<div class="text-xs text-slate-500">
${item[0]}
</div>
<div class="text-2xl font-bold mt-2">
${item[1]}
</div>
</div>`
).join('')}

</div>

<section class="bg-white rounded-2xl shadow-sm p-5 mb-5">

<div class="flex flex-wrap justify-between gap-3 mb-4">

<h2 class="font-bold text-lg">
設問別集計
</h2>

<div class="flex gap-2">
<button
onclick="App.actions.selectAllSummaryQuestions()"
class="text-blue-600 text-sm">
全選択
</button>

<button
onclick="App.actions.clearSummaryQuestions()"
class="text-blue-600 text-sm">
全解除
</button>
</div>

</div>

<div class="grid md:grid-cols-2 gap-2 mb-6">

${questions.map(question => `
<label class="flex gap-2 items-center border rounded-lg p-2">

<input
type="checkbox"
${App.State.summaryQuestions.has(question.id) ? 'checked' : ''}
onchange="App.actions.toggleSummaryQuestion('${App.escape(question.id)}',this.checked)">

<span class="font-semibold">
${App.escape(question.display_number || '')}
</span>

<span class="flex-1">
${App.escape(question.text)}
</span>

<span class="text-xs text-slate-400">
${App.escape(question.type)}
</span>

</label>`
).join('')}

</div>

<div class="space-y-6">

${
questions
.filter(
question =>
App.State.summaryQuestions.has(
question.id
)
)
.map(
question =>
App.questionSummary(
question,
responses
)
)
.join('')
}

${
!responses.length
? `
<div class="text-center p-12 text-slate-400">
現在、回答データはありません
</div>`
: ''
}

</div>

</section>

<section class="bg-white rounded-2xl shadow-sm p-5">

<div class="flex flex-wrap items-center justify-between gap-3 mb-4">

<h2 class="font-bold text-lg">
個別回答一覧
</h2>

<input
id="response_filter"
value="${App.escape(App.State.responseFilter || '')}"
oninput="App.actions.filterResponses(this.value)"
placeholder="会社名・氏名で検索"
class="border rounded-lg p-2">

</div>

<div
id="response_table"
class="overflow-x-auto">

<table class="min-w-[850px] w-full text-sm">
<thead class="bg-slate-50">
<tr>
<th class="text-left p-3">回答日時</th>
<th class="text-left p-3">会社名</th>
<th class="text-left p-3">氏名</th>
<th class="text-left p-3">メール</th>
<th class="text-left p-3">操作</th>
</tr>
</thead>

<tbody class="divide-y">

${
filteredResponses.length
? filteredResponses.map(response => `
<tr>
<td class="p-3">
${App.escape(response.answered_at)}
</td>

<td class="p-3 font-semibold">
${App.escape(response.company)}
</td>

<td class="p-3">
${App.escape(response.name)}
</td>

<td class="p-3">
${App.escape(response.email)}
</td>

<td class="p-3">
<button
onclick="App.actions.showResponse('${App.escape(response.id)}')"
class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700">
全回答を表示
</button>
</td>
</tr>`
).join('')
: `
<tr>
<td colspan="5" class="p-10 text-center text-slate-400">
該当する回答がありません。
</td>
</tr>`
}

</tbody>
</table>

</div>

</section>

</main>`;
},

questionSummary(
    question,
    responses
) {

    const values = [];

    responses.forEach(response => {

        let value =
            response.answers?.[
                question.id
            ];

        if (Array.isArray(value)) {
            value.forEach(item =>
                values.push(
                    String(item)
                )
            );
        } else if (
            value !== undefined &&
            value !== ''
        ) {
            values.push(
                String(value)
            );
        }
    });

    if (question.type === 'text') {

        return `
<div class="border rounded-xl p-4">
<h3 class="font-bold mb-3">
${App.escape(
question.display_number || ''
)}
${App.escape(question.text)}
</h3>

<div class="max-h-72 overflow-y-auto space-y-2">

${
values.length
? values.map(
value => `
<div class="bg-slate-50 rounded-lg p-3">
${App.escape(value)}
</div>`
).join('')
: `
<div class="text-slate-400">
回答なし
</div>`
}

</div>
</div>`;
    }

    const counts = {};

    (question.options || []).forEach(
        option =>
            counts[option] = 0
    );

    let other = 0;

    values.forEach(value => {

        if (
            Object.prototype.hasOwnProperty.call(
                counts,
                value
            )
        ) {
            counts[value]++;
        } else {
            other++;
        }
    });

    const total =
        values.length || 1;

    return `
<div class="border rounded-xl p-4">

<h3 class="font-bold mb-4">
${App.escape(
question.display_number || ''
)}
${App.escape(question.text)}
</h3>

<div class="space-y-3">

${Object.entries(counts).map(
([label, count]) => {

const percent =
count / total * 100;

return `
<div>
<div class="flex justify-between text-sm mb-1">
<span>${App.escape(label)}</span>
<span>
${count}件 /
${percent.toFixed(1)}%
</span>
</div>

<div class="h-3 bg-slate-100 rounded-full overflow-hidden">
<div
class="h-full bg-blue-600"
style="width:${Math.min(
100,
percent
)}%">
</div>
</div>
</div>`;
}
).join('')}

${
question.other_enabled
? `
<div class="mt-3 text-sm text-orange-700">
その他:
<strong>${other} 件</strong>
</div>`
: ''
}

</div>
</div>`;
},

responseModal(response) {

    const survey =
        App.State.selectedSurvey;

    const questions =
        App.questions(survey);

    return `
<div
id="response_modal"
class="fixed inset-0 z-50 bg-black/50 p-4">

<div class="max-w-3xl mx-auto bg-white rounded-2xl shadow-xl max-h-[90vh] overflow-hidden">

<div class="flex items-center justify-between p-4 border-b">

<div>
<div class="font-bold">
回答詳細
</div>
<div class="text-sm text-slate-500">
${App.escape(response.company)}
／
${App.escape(response.name)}
</div>
</div>

<button
onclick="App.actions.closeResponse()"
class="px-3 py-2 rounded-lg bg-slate-100">
閉じる
</button>

</div>

<div
id="response_detail"
class="p-5 overflow-y-auto max-h-[calc(90vh-80px)]">

<div class="grid md:grid-cols-2 gap-3 mb-6">

<div>
<div class="text-xs text-slate-400">
回答日時
</div>
<div>
${App.escape(response.answered_at)}
</div>
</div>

<div>
<div class="text-xs text-slate-400">
メール
</div>
<div>
${App.escape(response.email)}
</div>
</div>

</div>

<div class="space-y-4">

${questions.map(
question => {

let value =
response.answers?.[
question.id
] ?? '';

if (Array.isArray(value)) {
value =
value.join(', ');
}

return `
<div class="border rounded-xl p-4">

<div class="text-sm text-slate-500 mb-1">
${App.escape(
question.display_number || ''
)}
</div>

<div class="font-semibold mb-2">
${App.escape(question.text)}
</div>

<div class="bg-slate-50 rounded-lg p-3 whitespace-pre-wrap">
${App.escape(value)}
</div>

</div>`;
}
).join('')}

</div>

</div>
</div>
</div>`;
},

renderSettings() {

    const settings =
        App.State.data.settings;

    const fields =
        App.State.fields || [];

    const optionHtml =
        fields.map(field =>
            `<option value="${App.escape(field.code)}">
${App.escape(field.label)}
（${App.escape(field.code)}）
</option>`
        ).join('');

    const selected =
        key =>
            String(
                settings[key] || ''
            );

    const select =
        (key, label) => `
<label class="block">
<span class="text-sm font-semibold">
${label}
</span>

<select
id="setting_${key}"
class="w-full border rounded-lg p-3 mt-1">

<option value="">
-- 選択してください --
</option>

${fields.map(field => `
<option
value="${App.escape(field.code)}"
${selected(key) === field.code ? 'selected' : ''}>
${App.escape(field.label)}
（${App.escape(field.code)}）
</option>
`).join('')}

</select>
</label>`;

    document.getElementById(
        'app'
    ).innerHTML = `
${App.header()}

<main class="max-w-5xl mx-auto p-4 md:p-8">

<div class="mb-6">
<div class="text-sm text-blue-600 font-semibold">
ホーム ＞ システム設定 ＞ kintone連携設定
</div>

<h1 class="text-2xl font-bold mt-1">
kintone連携設定
</h1>

<p class="text-slate-500 mt-2">
APIトークンは使用せず、ログイン名・パスワードで接続します。
</p>
</div>

<section
id="settings_form"
class="bg-white rounded-2xl shadow-sm p-5">

<div class="grid md:grid-cols-2 gap-4">

<label>
<span class="text-sm font-semibold">
サブドメイン / ホスト
</span>
<input
id="setting_subdomain"
value="${App.escape(settings.subdomain || '')}"
placeholder="xxxx.cybozu.com"
class="w-full border rounded-lg p-3 mt-1">
</label>

<label>
<span class="text-sm font-semibold">
顧客管理アプリID
</span>
<input
id="setting_app_id"
value="${App.escape(settings.app_id || '')}"
placeholder="123"
class="w-full border rounded-lg p-3 mt-1">
</label>

<label>
<span class="text-sm font-semibold">
ログイン名
</span>
<input
id="setting_login_name"
autocomplete="off"
value="${App.escape(settings.login_name || '')}"
class="w-full border rounded-lg p-3 mt-1">
</label>

<label>
<span class="text-sm font-semibold">
パスワード
</span>
<input
id="setting_password"
type="password"
autocomplete="new-password"
placeholder="変更する場合のみ入力"
class="w-full border rounded-lg p-3 mt-1">
</label>

<label class="md:col-span-2">
<span class="text-sm font-semibold">
Proxy
</span>
<input
id="setting_proxy"
value="${App.escape(settings.proxy || '')}"
placeholder="host:port / http://host:port / https://host:port"
class="w-full border rounded-lg p-3 mt-1">
</label>

<label class="flex items-center gap-2 md:col-span-2">
<input
id="setting_ssl_verify"
type="checkbox"
${settings.ssl_verify !== false ? 'checked' : ''}>
SSL証明書を検証する
</label>

</div>

<div class="flex flex-wrap gap-2 mt-6">

<button
onclick="App.actions.testKintone()"
class="px-4 py-2 rounded-lg bg-slate-800 text-white">
接続確認
</button>

<button
onclick="App.actions.fetchKintoneFields()"
class="px-4 py-2 rounded-lg bg-blue-600 text-white">
項目一覧を再取得
</button>

<button
onclick="App.actions.saveSettings()"
class="px-4 py-2 rounded-lg bg-green-600 text-white">
設定を保存
</button>

</div>

<div
id="field_message"
class="mt-4 p-4 rounded-xl bg-slate-50 whitespace-pre-wrap text-sm">
</div>

</section>

<section class="bg-white rounded-2xl shadow-sm p-5 mt-5">

<h2 class="font-bold text-lg mb-5">
フィールドマッピング
</h2>

<div class="grid md:grid-cols-2 gap-4">

${select(
'field_company',
'会社名 (Company)'
)}

${select(
'field_name',
'氏名 (Name)'
)}

${select(
'field_email',
'メールアドレス (Email)'
)}

${select(
'field_department',
'部署名 (Department)'
)}

${select(
'field_phone',
'電話番号 (Phone)'
)}

</div>

<div class="mt-5">
<div class="text-sm font-semibold mb-2">
住所 (Address)
</div>

<div class="grid md:grid-cols-2 gap-2">

${
fields.length
? fields.map(field => `
<label class="flex items-center gap-2 border rounded-lg p-2">
<input
type="checkbox"
class="address_field"
value="${App.escape(field.code)}"
${
(settings.field_address || [])
.includes(field.code)
? 'checked'
: ''
}>
<span>
${App.escape(field.label)}
</span>
</label>`
).join('')
: `
<div class="text-slate-400 text-sm">
先に「項目一覧を再取得」してください。
</div>`
}

</div>
</div>

</section>

</main>`;
}

};

if (
document.readyState ===
'loading'
) {
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

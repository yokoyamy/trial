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

    return is_array($data)
        ? array_replace_recursive(survey_default_data(), $data)
        : survey_default_data();
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

/* =========================================================
 * kintone URL
 * ========================================================= */

function survey_normalize_kintone_base(string $input): array {
    $input = trim($input);
    $input = rtrim($input, "/ \t\r\n");

    if ($input === '') {
        return ['ok' => false, 'error' => 'kintoneホストが未入力です。'];
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
        return ['ok' => false, 'error' => 'kintoneホストを取得できません。'];
    }

    $hostOnly = preg_replace('/:\d+$/', '', $host);

    if (!preg_match(
        '~^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.cybozu\.com$~i',
        (string)$hostOnly
    ) &&
        !preg_match(
            '~^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$~i',
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

/* =========================================================
 * Proxy
 * ========================================================= */

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

/* =========================================================
 * HTTP
 * ========================================================= */

function survey_last_headers(): array {
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
        $body = file_get_contents($url, false, $context);
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
        $error = $warning !== ''
            ? $warning
            : 'HTTPレスポンスを取得できませんでした。';

        $error .=
            "\n確認事項: DNS、外部HTTPS通信、Proxy、ファイアウォール、"
            . "SSL/TLS、OpenSSL、タイムアウト。";

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

function survey_kintone_base_request(
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
        . '/k/v1/'
        . ltrim($path, '/');

    if (!str_contains($url, '?')) {
        $url .= '?app=' . rawurlencode($appId);
    }

    $auth = base64_encode(
        (string)($settings['login_name'] ?? '')
        . ':'
        . (string)($settings['password'] ?? '')
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $auth,
        'Accept: application/json',
        'Connection: close',
    ];

    $content = null;

    if ($payload !== null) {
        $content = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );

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

function survey_kintone_message(array $r): string {
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
            . ($error !== '' ? $error : 'なし');
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
        return "kintone通信がタイムアウトしました。";
    }

    if ($status === 429) {
        return "kintone側のレート制限です。";
    }

    if ($status >= 500) {
        return "kintoneまたはProxy側のサーバーエラーです。HTTPステータス: {$status}";
    }

    if ($status >= 200 && $status < 300) {
        return "kintone通信に成功しました。HTTPステータス: {$status}";
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

function fetchKintoneFields(array $settings): array {
    $r = survey_kintone_base_request(
        $settings,
        'app/form/fields.json'
    );

    if ((int)$r['status'] < 200 || (int)$r['status'] >= 300) {
        return [
            'ok' => false,
            'fields' => [],
            'message' => survey_kintone_message($r),
        ];
    }

    $json = $r['json'];

    if (!is_array($json) ||
        !isset($json['properties']) ||
        !is_array($json['properties'])) {
        return [
            'ok' => false,
            'fields' => [],
            'message' => 'kintoneレスポンスにpropertiesがありません。',
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
 * kintone customer sync
 * ========================================================= */

function survey_kintone_value(
    array $record,
    string $code
): string {

    if ($code === '' || !isset($record[$code])) {
        return '';
    }

    $v = $record[$code]['value'] ?? '';

    if (is_array($v)) {
        $values = [];

        foreach ($v as $item) {
            if (is_array($item)) {
                $values[] = (string)($item['value'] ?? '');
            } else {
                $values[] = (string)$item;
            }
        }

        return implode(' ', $values);
    }

    return (string)$v;
}

function survey_sync_customers(array &$data): array {
    $settings = $data['settings'];

    $fields = fetchKintoneFields($settings);

    if (!$fields['ok']) {
        return [
            'ok' => false,
            'message' => $fields['message'],
            'count' => 0,
        ];
    }

    $r = survey_kintone_base_request(
        $settings,
        'records.json'
    );

    if ((int)$r['status'] < 200 || (int)$r['status'] >= 300) {
        return [
            'ok' => false,
            'message' => survey_kintone_message($r),
            'count' => 0,
        ];
    }

    $records = $r['json']['records'] ?? null;

    if (!is_array($records)) {
        return [
            'ok' => false,
            'message' => 'kintone APIレスポンスにrecordsがありません。',
            'count' => 0,
        ];
    }

    $map = $settings;

    $existing = [];

    foreach ($data['customers'] as $customer) {
        if (!empty($customer['email'])) {
            $existing[(string)$customer['email']] = $customer;
        }
    }

    $count = 0;
    $newCount = 0;
    $updateCount = 0;

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $email = trim(survey_kintone_value(
            $record,
            (string)$map['field_email']
        ));

        if ($email === '' ||
            !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $customer = [
            'id' => $existing[$email]['id'] ?? survey_id(),
            'company' => survey_kintone_value(
                $record,
                (string)$map['field_company']
            ),
            'name' => survey_kintone_value(
                $record,
                (string)$map['field_name']
            ),
            'email' => $email,
            'department' => survey_kintone_value(
                $record,
                (string)$map['field_department']
            ),
            'phone' => survey_kintone_value(
                $record,
                (string)$map['field_phone']
            ),
            'address' => '',
            'source' => 'kintone',
            'sent_at' => $existing[$email]['sent_at'] ?? null,
            'send_count' => (int)($existing[$email]['send_count'] ?? 0),
            'answer_status' =>
                $existing[$email]['answer_status'] ?? 'unanswered',
            'kintone_status' => 'registered',
        ];

        $addresses = $map['field_address'] ?? [];

        if (!is_array($addresses)) {
            $addresses = [$addresses];
        }

        $addressParts = [];

        foreach ($addresses as $code) {
            $v = survey_kintone_value($record, (string)$code);

            if ($v !== '') {
                $addressParts[] = $v;
            }
        }

        $customer['address'] = implode(' ', $addressParts);

        if (isset($existing[$email])) {
            $updateCount++;
        } else {
            $newCount++;
        }

        $existing[$email] = $customer;
        $count++;
    }

    $data['customers'] = array_values($existing);

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

/* =========================================================
 * CSV
 * ========================================================= */

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
        'Content-Disposition: attachment; filename="survey_'
        . preg_replace('/[^a-zA-Z0-9_-]/', '_', $surveyId)
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

        foreach ($questions as $q) {
            $qid = (string)($q['id'] ?? '');
            $v = $response['answers'][$qid] ?? '';

            if (is_array($v)) {
                $v = implode(', ', $v);
            }

            $row[] = $v;
        }

        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}

/* =========================================================
 * 公開回答
 * ========================================================= */

function survey_find(array $data, string $id): ?array {
    foreach ($data['surveys'] as $survey) {
        if (($survey['id'] ?? '') === $id &&
            empty($survey['deleted'])) {
            return $survey;
        }
    }

    return null;
}

$data = survey_read_data();

/* =========================================================
 * Public response
 * ========================================================= */

if (isset($_GET['survey']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $surveyId = (string)$_GET['survey'];
    $customerId = (string)($_GET['customer'] ?? '');

    $survey = survey_find($data, $surveyId);

    if (!$survey || ($survey['status'] ?? '') !== 'active') {
        http_response_code(404);
        ?>
        <!doctype html>
        <html lang="ja">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>アンケート</title>
            <script src="https://cdn.tailwindcss.com"></script>
        </head>
        <body class="bg-slate-50 min-h-screen">
        <main class="max-w-2xl mx-auto p-6">
            <div class="bg-white rounded-2xl shadow-sm p-8">
                <h1 class="text-xl font-bold text-slate-800">
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

    foreach ($data['customers'] as $c) {
        if (($c['id'] ?? '') === $customerId) {
            $customer = $c;
            break;
        }
    }

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
        <div class="bg-white rounded-2xl shadow-sm p-6 md:p-8">
            <div class="mb-8">
                <div class="text-sm text-blue-600 font-semibold mb-2">
                    アンケート
                </div>
                <h1 class="text-2xl font-bold">
                    <?= survey_h($survey['title']) ?>
                </h1>
                <?php if ($customer): ?>
                <p class="mt-3 text-slate-500">
                    <?= survey_h($customer['name']) ?> 様
                </p>
                <?php endif; ?>
            </div>

            <form method="post">
                <input type="hidden" name="action" value="public_answer">
                <input type="hidden" name="survey_id"
                       value="<?= survey_h($surveyId) ?>">
                <input type="hidden" name="customer_id"
                       value="<?= survey_h($customerId) ?>">

                <?php $number = 1; ?>

                <?php foreach ($survey['groups'] ?? [] as $group): ?>
                    <section class="mb-8">
                        <h2 class="font-bold text-lg border-b pb-3 mb-5">
                            <?= survey_h($group['name'] ?? '') ?>
                        </h2>

                        <?php foreach ($group['questions'] ?? [] as $q): ?>
                            <?php
                            $qid = (string)($q['id'] ?? '');
                            $required = !empty($q['required']);
                            ?>
                            <div class="mb-7">
                                <label class="block font-semibold mb-3">
                                    Q<?= $number++ ?>.
                                    <?= survey_h($q['text'] ?? '') ?>
                                    <?php if ($required): ?>
                                        <span class="text-red-500 text-sm">
                                            必須
                                        </span>
                                    <?php endif; ?>
                                </label>

                                <?php if (($q['type'] ?? '') === 'single'): ?>
                                    <div class="space-y-2">
                                    <?php foreach ($q['options'] ?? [] as $option): ?>
                                        <label class="flex items-center gap-2">
                                            <input
                                                type="radio"
                                                name="answers[<?= survey_h($qid) ?>]"
                                                value="<?= survey_h($option) ?>"
                                                <?= $required ? 'required' : '' ?>
                                                class="w-4 h-4">
                                            <span><?= survey_h($option) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                    </div>

                                <?php elseif (($q['type'] ?? '') === 'multiple'): ?>
                                    <div class="space-y-2">
                                    <?php foreach ($q['options'] ?? [] as $option): ?>
                                        <label class="flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                name="answers[<?= survey_h($qid) ?>][]"
                                                value="<?= survey_h($option) ?>"
                                                class="w-4 h-4">
                                            <span><?= survey_h($option) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                    </div>

                                <?php else: ?>
                                    <textarea
                                        name="answers[<?= survey_h($qid) ?>]"
                                        rows="4"
                                        <?= $required ? 'required' : '' ?>
                                        class="w-full border rounded-xl p-3"></textarea>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>

                <button
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white
                           font-bold rounded-xl py-4">
                    回答を送信する
                </button>
            </form>
        </div>
    </main>
    </body>
    </html>
    <?php
    exit;
}

/* =========================================================
 * Public answer POST
 * ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'public_answer') {

    $surveyId = (string)($_POST['survey_id'] ?? '');
    $customerId = (string)($_POST['customer_id'] ?? '');

    $survey = survey_find($data, $surveyId);

    if (!$survey) {
        http_response_code(404);
        exit('Survey not found');
    }

    $customer = null;

    foreach ($data['customers'] as $c) {
        if (($c['id'] ?? '') === $customerId) {
            $customer = $c;
            break;
        }
    }

    $response = [
        'id' => survey_id(),
        'survey_id' => $surveyId,
        'customer_id' => $customerId,
        'company' => $customer['company'] ?? '',
        'name' => $customer['name'] ?? '',
        'email' => $customer['email'] ?? '',
        'answered_at' => survey_now(),
        'answers' => is_array($_POST['answers'] ?? null)
            ? $_POST['answers']
            : [],
    ];

    $data['responses'][] = $response;

    foreach ($data['customers'] as &$c) {
        if (($c['id'] ?? '') === $customerId) {
            $c['answer_status'] = 'answered';
            break;
        }
    }
    unset($c);

    survey_write_data($data);

    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>回答完了</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-slate-50 min-h-screen">
    <main class="max-w-xl mx-auto p-6 mt-16">
        <div class="bg-white rounded-2xl shadow-sm p-8 text-center">
            <div class="text-5xl mb-5">✓</div>
            <h1 class="text-2xl font-bold">回答を受け付けました</h1>
            <p class="text-slate-500 mt-3">
                ご回答ありがとうございました。
            </p>
        </div>
    </main>
    </body>
    </html>
    <?php
    exit;
}

/* =========================================================
 * Admin API
 * ========================================================= */

if (isset($_GET['action']) || isset($_POST['action'])) {

    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

    if ($action === 'csrf') {
        survey_api([
            'ok' => true,
            'csrf_token' => survey_token(),
        ]);
    }

    if ($action !== 'public_answer' &&
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        !survey_check_token()) {
        survey_api([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。',
        ], 403);
    }

    if ($action === 'get_data') {
        survey_api([
            'ok' => true,
            'data' => $data,
            'csrf_token' => survey_token(),
        ]);
    }

    if ($action === 'save_settings') {
        $json = (string)($_POST['settings_json'] ?? '');
        $settings = json_decode($json, true);

        if (!is_array($settings)) {
            survey_api([
                'ok' => false,
                'message' => 'settings_jsonが不正です。',
            ], 400);
        }

        $data['settings'] = array_replace(
            $data['settings'],
            [
                'subdomain' => trim((string)($settings['subdomain'] ?? '')),
                'login_name' => trim((string)($settings['login_name'] ?? '')),
                'password' => (string)($settings['password'] ?? ''),
                'app_id' => trim((string)($settings['app_id'] ?? '')),
                'ssl_verify' => !empty($settings['ssl_verify']),
                'proxy' => trim((string)($settings['proxy'] ?? '')),
                'field_company' => (string)($settings['field_company'] ?? ''),
                'field_name' => (string)($settings['field_name'] ?? ''),
                'field_email' => (string)($settings['field_email'] ?? ''),
                'field_department' => (string)($settings['field_department'] ?? ''),
                'field_phone' => (string)($settings['field_phone'] ?? ''),
                'field_address' => is_array($settings['field_address'] ?? null)
                    ? $settings['field_address']
                    : [],
            ]
        );

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'message' => '設定を保存しました。',
        ]);
    }

    if ($action === 'kintone_fields') {
        $result = fetchKintoneFields($data['settings']);

        survey_api($result, $result['ok'] ? 200 : 400);
    }

    if ($action === 'kintone_sync') {
        $result = survey_sync_customers($data);

        if ($result['ok']) {
            survey_write_data($data);
        }

        survey_api($result, $result['ok'] ? 200 : 400);
    }

    if ($action === 'kintone_test') {
        $result = fetchKintoneFields($data['settings']);

        survey_api([
            'ok' => $result['ok'],
            'message' => $result['message'],
            'fields' => $result['fields'] ?? [],
        ], $result['ok'] ? 200 : 400);
    }

    if ($action === 'save_survey') {
        $json = (string)($_POST['survey_json'] ?? '');
        $survey = json_decode($json, true);

        if (!is_array($survey)) {
            survey_api([
                'ok' => false,
                'message' => 'survey_jsonが不正です。',
            ], 400);
        }

        $id = (string)($survey['id'] ?? '');

        if ($id === '') {
            $id = survey_id();
            $survey['id'] = $id;
            $survey['created_at'] = survey_now();
        }

        $survey['updated_at'] = survey_now();
        $survey['deleted'] = false;
        $survey['status'] =
            in_array(
                $survey['status'] ?? 'draft',
                ['draft', 'active', 'ended'],
                true
            )
            ? $survey['status']
            : 'draft';

        $found = false;

        foreach ($data['surveys'] as $i => $old) {
            if (($old['id'] ?? '') === $id) {
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
                'message' => 'データ保存に失敗しました。',
            ], 500);
        }

        survey_api([
            'ok' => true,
            'message' => 'アンケートを保存しました。',
            'survey' => $survey,
        ]);
    }

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
            'message' => '削除しました。',
        ]);
    }

    if ($action === 'status') {
        $id = (string)($_POST['survey_id'] ?? '');
        $status = (string)($_POST['status'] ?? '');

        if (!in_array($status, ['draft', 'active', 'ended'], true)) {
            survey_api([
                'ok' => false,
                'message' => 'ステータスが不正です。',
            ], 400);
        }

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $id) {
                $survey['status'] = $status;
                $survey['updated_at'] = survey_now();
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
        $id = (string)($_POST['survey_id'] ?? '');
        $source = survey_find($data, $id);

        if (!$source) {
            survey_api([
                'ok' => false,
                'message' => 'アンケートが見つかりません。',
            ], 404);
        }

        $copy = $source;
        $copy['id'] = survey_id();
        $copy['title'] = (string)$source['title'] . '（複製）';
        $copy['status'] = 'draft';
        $copy['created_at'] = survey_now();
        $copy['updated_at'] = survey_now();
        $copy['deleted'] = false;

        $data['surveys'][] = $copy;
        survey_write_data($data);

        survey_api([
            'ok' => true,
            'message' => '複製しました。',
            'survey' => $copy,
        ]);
    }

    if ($action === 'send_mail') {
        $surveyId = (string)($_POST['survey_id'] ?? '');
        $ids = json_decode(
            (string)($_POST['recipient_ids'] ?? '[]'),
            true
        );

        $subject = (string)($_POST['mail_subject'] ?? '');
        $body = (string)($_POST['mail_body'] ?? '');
        $templateType = (string)($_POST['template_type'] ?? 'initial');

        if (!is_array($ids)) {
            $ids = [];
        }

        $survey = survey_find($data, $surveyId);

        if (!$survey) {
            survey_api([
                'ok' => false,
                'message' => 'アンケートが見つかりません。',
            ], 404);
        }

        $success = 0;
        $failed = 0;
        $sentCustomers = [];

        foreach ($data['customers'] as &$customer) {
            if (!in_array($customer['id'] ?? '', $ids, true)) {
                continue;
            }

            $url =
                rtrim(
                    (
                        (!empty($_SERVER['HTTPS']) &&
                         $_SERVER['HTTPS'] !== 'off')
                        ? 'https://'
                        : 'http://'
                    )
                    . ($_SERVER['HTTP_HOST'] ?? ''),
                    '/'
                )
                . ($_SERVER['SCRIPT_NAME'] ?? '/index.php')
                . '?survey='
                . rawurlencode($surveyId)
                . '&customer='
                . rawurlencode((string)$customer['id']);

            $actualSubject = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [(string)$customer['name'], $url],
                $subject
            );

            $actualBody = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [(string)$customer['name'], $url],
                $body
            );

            $ok = survey_mail_send(
                (string)$customer['email'],
                $actualSubject,
                $actualBody
            );

            if ($ok) {
                $success++;

                $customer['sent_at'] = survey_now();
                $customer['send_count'] =
                    (int)($customer['send_count'] ?? 0) + 1;
                $customer['answer_status'] = 'unanswered';

                $sentCustomers[] = [
                    'customer_id' => $customer['id'],
                    'email' => $customer['email'],
                    'subject' => $actualSubject,
                    'body' => $actualBody,
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
            'items' => $sentCustomers,
        ];

        survey_write_data($data);

        survey_api([
            'ok' => true,
            'message' =>
                "送信処理完了\n成功: {$success}件\n失敗: {$failed}件",
            'success_count' => $success,
            'failed_count' => $failed,
        ]);
    }

    if ($action === 'csv') {
        survey_csv_download(
            $data,
            (string)($_GET['survey_id'] ?? '')
        );
    }

    if ($action === 'get_response') {
        $id = (string)($_GET['response_id'] ?? '');

        foreach ($data['responses'] as $response) {
            if (($response['id'] ?? '') === $id) {
                survey_api([
                    'ok' => true,
                    'response' => $response,
                ]);
            }
        }

        survey_api([
            'ok' => false,
            'message' => '回答が見つかりません。',
        ], 404);
    }

    survey_api([
        'ok' => false,
        'message' => 'Unknown action: ' . $action,
    ], 400);
}

/* =========================================================
 * Admin SPA
 * ========================================================= */
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

<body class="bg-slate-100 text-slate-800">

<div id="app"></div>

<script>
window.App = {

State: {
    data: null,
    csrf: '',
    page: 'list',
    editing: null,
    selectedSurvey: null,
    preview: null,
    sendSurvey: null,
    customerFilter: '',
    selectedCustomers: new Set(),
    fields: []
},

escape(v) {
    const d = document.createElement('div');
    d.textContent = v ?? '';
    return d.innerHTML;
},

async api(action, params = {}, method = 'POST') {

    let url = location.pathname;

    if (method === 'GET') {
        const q = new URLSearchParams(params);
        q.set('action', action);
        url += '?' + q.toString();
    }

    const body = new URLSearchParams();

    if (method !== 'GET') {
        body.set('action', action);

        if (App.State.csrf) {
            body.set('csrf_token', App.State.csrf);
        }

        Object.entries(params).forEach(([k,v]) => {
            body.set(
                k,
                typeof v === 'object'
                    ? JSON.stringify(v)
                    : String(v)
            );
        });
    }

    const response = await fetch(url, {
        method,
        headers: method === 'POST'
            ? {'Content-Type':'application/x-www-form-urlencoded'}
            : {},
        body: method === 'POST' ? body : undefined
    });

    const text = await response.text();

    let json;

    try {
        json = JSON.parse(text);
    } catch (e) {
        console.error('PHP/API response:', text);
        throw new Error(
            'サーバーからJSONではない応答が返りました。' +
            '\nHTTP: ' + response.status +
            '\n先頭: ' + text.slice(0,300)
        );
    }

    if (!response.ok || json.ok === false) {
        throw new Error(json.message || '処理に失敗しました。');
    }

    return json;
},

async init() {

    try {
        const csrf = await App.api('csrf');
        App.State.csrf = csrf.csrf_token;

        const result = await App.api('get_data');
        App.State.data = result.data;

        App.render();
    } catch (e) {
        document.getElementById('app').innerHTML = `
        <div class="min-h-screen flex items-center justify-center p-6">
            <div class="bg-white rounded-2xl shadow p-8 max-w-2xl w-full">
                <h1 class="text-2xl font-bold text-red-600 mb-4">
                    アプリを起動できません
                </h1>
                <pre class="bg-slate-900 text-white rounded-xl p-4
                            whitespace-pre-wrap text-sm">${App.escape(e.message)}</pre>
                <p class="mt-4 text-slate-500">
                    PHPのエラーがHTMLとして返っていないか、
                    survey_storageの書き込み権限を確認してください。
                </p>
            </div>
        </div>`;
    }
},

render() {

    const s = App.State;

    if (s.page === 'list') App.renderList();
    else if (s.page === 'edit') App.renderEdit();
    else if (s.page === 'settings') App.renderSettings();
    else if (s.page === 'send') App.renderSend();
    else if (s.page === 'summary') App.renderSummary();
},

header(active = 'list') {

    return `
    <header class="bg-white border-b sticky top-0 z-30">
      <div class="max-w-7xl mx-auto px-4 py-4 flex flex-wrap
                  items-center justify-between gap-3">

        <button onclick="App.actions.goList()"
                class="font-bold text-xl text-slate-900">
            アンケート管理
        </button>

        <nav class="flex gap-2">
          <button
            onclick="App.actions.goList()"
            class="px-4 py-2 rounded-lg
                   ${active==='list'
                     ? 'bg-blue-600 text-white'
                     : 'bg-slate-100'}">
            アンケート一覧
          </button>

          <button
            onclick="App.actions.settings()"
            class="px-4 py-2 rounded-lg
                   ${active==='settings'
                     ? 'bg-blue-600 text-white'
                     : 'bg-slate-100'}">
            kintone連携設定
          </button>
        </nav>

      </div>
    </header>`;
},

renderList() {

    const surveys = (App.State.data.surveys || [])
        .filter(x => !x.deleted);

    document.getElementById('app').innerHTML =
    App.header('list') + `
    <main class="max-w-7xl mx-auto p-4 md:p-8">

      <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
          <h1 class="text-2xl font-bold">アンケート一覧</h1>
          <p class="text-slate-500 mt-1">
            ここからすべての操作を開始します。
          </p>
        </div>

        <button onclick="App.actions.newSurvey()"
                class="bg-blue-600 hover:bg-blue-700 text-white
                       px-5 py-3 rounded-xl font-bold">
          ＋ 新規アンケート作成
        </button>
      </div>

      <div class="bg-white rounded-2xl shadow-sm overflow-hidden">

        <div class="p-4 border-b flex flex-wrap gap-3">
          <input id="list_keyword"
                 placeholder="タイトルを検索"
                 class="border rounded-lg px-3 py-2 w-64"
                 oninput="App.actions.filterList()">

          <select id="list_status"
                  onchange="App.actions.filterList()"
                  class="border rounded-lg px-3 py-2">
            <option value="">すべて</option>
            <option value="active">公開中</option>
            <option value="draft">下書き</option>
            <option value="ended">終了</option>
          </select>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b">
              <tr>
                <th class="text-left p-4">アンケート</th>
                <th class="text-left p-4">期間</th>
                <th class="text-left p-4">ステータス</th>
                <th class="text-left p-4">回答数</th>
                <th class="text-right p-4">操作</th>
              </tr>
            </thead>
            <tbody id="survey_table">
            ${App.rows(surveys)}
            </tbody>
          </table>
        </div>
      </div>
    </main>`;
},

rows(surveys) {

    return surveys.map(s => {

        const responses =
            App.State.data.responses.filter(
                r => r.survey_id === s.id
            ).length;

        const badge =
            s.status === 'active'
                ? 'bg-green-100 text-green-700'
                : s.status === 'ended'
                    ? 'bg-slate-200 text-slate-700'
                    : 'bg-yellow-100 text-yellow-700';

        const label =
            s.status === 'active'
                ? '公開中'
                : s.status === 'ended'
                    ? '終了'
                    : '下書き';

        let actions = `
          <button onclick="App.actions.editSurvey('${s.id}')"
                  class="text-blue-600 font-semibold">
            確認・編集
          </button>
          <button onclick="App.actions.duplicate('${s.id}')"
                  class="text-slate-600">
            複製
          </button>`;

        if (s.status === 'active') {
            actions += `
              <button onclick="App.actions.send('${s.id}')"
                      class="bg-blue-600 text-white px-3 py-2 rounded-lg">
                送信
              </button>
              <button onclick="App.actions.summary('${s.id}')"
                      class="text-indigo-600">
                集計
              </button>
              <button onclick="App.actions.stop('${s.id}')"
                      class="text-red-600">
                停止
              </button>`;
        }

        if (s.status === 'draft') {
            actions += `
              <button onclick="App.actions.deleteSurvey('${s.id}')"
                      class="text-red-600">
                削除
              </button>`;
        }

        if (s.status === 'ended') {
            actions += `
              <button onclick="App.actions.summary('${s.id}')"
                      class="text-indigo-600">
                集計
              </button>`;
        }

        return `
        <tr class="border-b hover:bg-slate-50">
          <td class="p-4">
            <div class="font-bold">${App.escape(s.title || '無題')}</div>
            <div class="text-xs text-slate-400 mt-1">
              作成: ${App.escape(s.created_at || '')}<br>
              更新: ${App.escape(s.updated_at || '')}
            </div>
          </td>

          <td class="p-4">
            ${App.escape(s.start_at || '未設定')}
            ～
            ${App.escape(s.end_at || '未設定')}
          </td>

          <td class="p-4">
            <span class="px-3 py-1 rounded-full ${badge}">
              ${label}
            </span>
          </td>

          <td class="p-4 font-semibold">${responses} 件</td>

          <td class="p-4">
            <div class="flex flex-wrap justify-end gap-3">
              ${actions}
            </div>
          </td>
        </tr>`;
    }).join('');
},

actions: {

    goList() {
        App.State.page = 'list';
        App.State.editing = null;
        App.render();
    },

    settings() {
        App.State.page = 'settings';
        App.render();
    },

    newSurvey() {

        App.State.editing = {
            id: '',
            title: '新しいアンケート',
            start_at: '',
            end_at: '',
            status: 'draft',
            numbering_mode: 'global',
            groups: [{
                id: crypto.randomUUID(),
                name: '基本情報',
                questions: []
            }],
            deleted: false
        };

        App.State.page = 'edit';
        App.render();
    },

    editSurvey(id) {

        const s = App.State.data.surveys.find(
            x => x.id === id
        );

        if (!s) return;

        App.State.editing =
            JSON.parse(JSON.stringify(s));

        App.State.page = 'edit';
        App.render();
    },

    async duplicate(id) {

        if (!confirm('このアンケートを複製しますか？')) return;

        await App.api('duplicate_survey', {
            survey_id: id
        });

        const r = await App.api('get_data');
        App.State.data = r.data;
        App.renderList();
    },

    async deleteSurvey(id) {

        if (!confirm('削除しますか？')) return;

        await App.api('delete_survey', {
            survey_id: id
        });

        const r = await App.api('get_data');
        App.State.data = r.data;
        App.renderList();
    },

    async stop(id) {

        if (!confirm('公開を停止しますか？')) return;

        await App.api('status', {
            survey_id: id,
            status: 'ended'
        });

        const r = await App.api('get_data');
        App.State.data = r.data;
        App.renderList();
    },

    async publish() {

        const s = App.State.editing;

        if (!s.title.trim()) {
            alert('タイトルを入力してください。');
            return;
        }

        s.status = 'active';

        await App.api('save_survey', {
            survey_json: s
        });

        alert('公開しました。');

        App.State.page = 'list';

        const r = await App.api('get_data');
        App.State.data = r.data;
        App.render();
    },

    async saveSurvey() {

        const s = App.State.editing;

        await App.api('save_survey', {
            survey_json: s
        });

        alert('保存しました。');

        const r = await App.api('get_data');
        App.State.data = r.data;

        App.State.page = 'list';
        App.State.editing = null;

        App.render();
    },

    addGroup() {

        App.State.editing.groups.push({
            id: crypto.randomUUID(),
            name: '新しいグループ',
            questions: []
        });

        App.renderEdit();
        App.initSortable();
    },

    addQuestion(groupId) {

        const group =
            App.State.editing.groups.find(
                g => g.id === groupId
            );

        if (!group) return;

        group.questions.push({
            id: crypto.randomUUID(),
            text: '新しい質問',
            type: 'single',
            required: false,
            options: ['選択肢1','選択肢2'],
            other_enabled: false
        });

        App.renderEdit();
        App.initSortable();
    },

    deleteGroup(groupId) {

        if (!confirm(
            'グループと質問をすべて削除しますか？'
        )) return;

        App.State.editing.groups =
            App.State.editing.groups.filter(
                g => g.id !== groupId
            );

        App.renderEdit();
        App.initSortable();
    },

    deleteQuestion(groupId, questionId) {

        const g =
            App.State.editing.groups.find(
                x => x.id === groupId
            );

        if (!g) return;

        g.questions =
            g.questions.filter(
                q => q.id !== questionId
            );

        App.renderEdit();
        App.initSortable();
    },

    updateTitle(v) {
        App.State.editing.title = v;
    },

    updateGroupName(id, v) {

        const g =
            App.State.editing.groups.find(
                x => x.id === id
            );

        if (g) g.name = v;
    },

    updateQuestion(groupId, qid, key, value) {

        const g =
            App.State.editing.groups.find(
                x => x.id === groupId
            );

        const q =
            g?.questions.find(
                x => x.id === qid
            );

        if (!q) return;

        q[key] = value;
    },

    async preview() {

        App.State.preview =
            JSON.parse(JSON.stringify(
                App.State.editing
            ));

        document.getElementById(
            'preview_modal'
        ).classList.remove('hidden');

        document.getElementById(
            'preview_content'
        ).innerHTML = App.previewHtml(
            App.State.preview
        );
    },

    closePreview() {
        document.getElementById(
            'preview_modal'
        ).classList.add('hidden');
    },

    send(id) {

        App.State.sendSurvey =
            App.State.data.surveys.find(
                x => x.id === id
            );

        App.State.selectedCustomers =
            new Set();

        App.State.page = 'send';
        App.render();
    },

    async syncCustomers() {

        try {

            const r =
                await App.api('kintone_sync');

            alert(r.message);

            const d =
                await App.api('get_data');

            App.State.data = d.data;

            if (App.State.page === 'send') {
                App.renderSend();
            } else {
                App.renderSettings();
            }

        } catch(e) {
            alert(e.message);
        }
    },

    async fields() {

        try {

            const r =
                await App.api('kintone_fields');

            App.State.fields = r.fields || [];

            App.renderSettings();

        } catch(e) {
            alert(e.message);
        }
    },

    async testKintone() {

        try {

            const r =
                await App.api('kintone_test');

            alert(r.message);

        } catch(e) {
            alert(e.message);
        }
    },

    async saveSettings() {

        const fields = [
            'subdomain',
            'login_name',
            'password',
            'app_id',
            'proxy',
            'field_company',
            'field_name',
            'field_email',
            'field_department',
            'field_phone'
        ];

        const settings = {};

        fields.forEach(k => {
            settings[k] =
                document.getElementById(
                    'setting_' + k
                )?.value || '';
        });

        settings.ssl_verify =
            document.getElementById(
                'setting_ssl_verify'
            )?.checked ?? true;

        settings.field_address =
            Array.from(
                document.querySelectorAll(
                    '.address_field:checked'
                )
            ).map(x => x.value);

        await App.api('save_settings', {
            settings_json: settings
        });

        const r = await App.api('get_data');

        App.State.data = r.data;

        alert('設定を保存しました。');
    },

    toggleCustomer(id) {

        if (App.State.selectedCustomers.has(id)) {
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

            App.State.data.customers.forEach(c => {

                if (
                    c.email &&
                    c.source === 'kintone'
                ) {
                    App.State.selectedCustomers.add(
                        c.id
                    );
                }

            });
        }

        App.renderSend();
    },

    async executeSend() {

        const ids =
            [...App.State.selectedCustomers];

        if (!ids.length) {
            alert('送信先を選択してください。');
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

        if (!subject || !body) {
            alert('件名と本文を入力してください。');
            return;
        }

        const customers =
            App.State.data.customers.filter(
                c => ids.includes(c.id)
            );

        const already =
            customers.filter(
                c => Number(c.send_count || 0) > 0
            );

        if (already.length) {

            if (!confirm(
                `既に送信済みの宛先が${already.length}件含まれています。\n再送しますか？`
            )) {
                return;
            }
        }

        const preview =
            customers.slice(0,3).map(c =>
                `${c.name} <${c.email}>`
            ).join('\n');

        if (!confirm(
            `以下の宛先へ送信します。\n\n`
            + `件数: ${customers.length}件\n\n`
            + preview
            + (customers.length > 3
                ? '\n…'
                : '')
            + '\n\n送信しますか？'
        )) {
            return;
        }

        try {

            const r =
                await App.api('send_mail', {
                    survey_id:
                        App.State.sendSurvey.id,
                    recipient_ids: ids,
                    mail_subject: subject,
                    mail_body: body,
                    template_type:
                        document.getElementById(
                            'template_type'
                        ).value
                });

            alert(r.message);

            const d =
                await App.api('get_data');

            App.State.data = d.data;

            App.State.page = 'list';

            App.render();

        } catch(e) {
            alert(e.message);
        }
    },

    summary(id) {

        App.State.selectedSurvey =
            App.State.data.surveys.find(
                x => x.id === id
            );

        App.State.page = 'summary';
        App.render();
    }
},

renderEdit() {

    const s = App.State.editing;

    document.getElementById('app').innerHTML =
    App.header('list') + `
    <main class="max-w-6xl mx-auto p-4 md:p-8">

      <div class="flex flex-wrap justify-between gap-3 mb-6">
        <div>
          <div class="text-sm text-blue-600 font-semibold">
            アンケート作成・編集
          </div>

          <input
            id="survey_title"
            value="${App.escape(s.title)}"
            oninput="App.actions.updateTitle(this.value)"
            class="text-2xl font-bold bg-transparent
                   border-b border-transparent
                   focus:border-blue-500 outline-none w-full">
        </div>

        <div class="flex flex-wrap gap-2">
          <button onclick="App.actions.preview()"
                  class="px-4 py-2 rounded-lg bg-slate-200">
            プレビュー
          </button>

          <button onclick="App.actions.saveSurvey()"
                  class="px-4 py-2 rounded-lg bg-blue-600 text-white">
            保存して一覧へ戻る
          </button>

          <button onclick="App.actions.publish()"
                  class="px-4 py-2 rounded-lg bg-green-600 text-white">
            公開する
          </button>
        </div>
      </div>

      <div class="bg-white rounded-2xl shadow-sm p-6">

        <div class="grid md:grid-cols-3 gap-4 mb-8">
          <label>
            <span class="block text-sm font-semibold mb-1">
              開始日時
            </span>
            <input
              id="survey_start_at"
              type="datetime-local"
              value="${App.escape(s.start_at || '')}"
              onchange="App.State.editing.start_at=this.value"
              class="w-full border rounded-lg p-2">
          </label>

          <label>
            <span class="block text-sm font-semibold mb-1">
              終了日時
            </span>
            <input
              id="survey_end_at"
              type="datetime-local"
              value="${App.escape(s.end_at || '')}"
              onchange="App.State.editing.end_at=this.value"
              class="w-full border rounded-lg p-2">
          </label>

          <label>
            <span class="block text-sm font-semibold mb-1">
              質問番号
            </span>
            <select
              id="survey_numbering_mode"
              onchange="App.State.editing.numbering_mode=this.value"
              class="w-full border rounded-lg p-2">
              <option value="global"
                ${s.numbering_mode==='global'?'selected':''}>
                Q1 / Q2 / Q3
              </option>
              <option value="group"
                ${s.numbering_mode==='group'?'selected':''}>
                Q1-1 / Q1-2
              </option>
            </select>
          </label>
        </div>

        <div id="question_editor"
             class="space-y-5">

          ${s.groups.map((g,gi) => `
            <section
              data-group-id="${g.id}"
              class="group-item border rounded-2xl p-5">

              <div class="flex gap-3 items-center mb-4">
                <span class="cursor-move text-slate-400 text-xl">
                  ⠿
                </span>

                <input
                  value="${App.escape(g.name)}"
                  oninput="App.actions.updateGroupName(
                      '${g.id}',this.value)"
                  class="font-bold text-lg flex-1 border-b
                         border-slate-200 outline-none">

                <button
                  onclick="App.actions.deleteGroup('${g.id}')"
                  class="text-red-500">
                  グループ削除
                </button>
              </div>

              <div
                class="question-list space-y-4"
                data-group-id="${g.id}">

                ${g.questions.map((q,qi) => `
                <div
                  data-question-id="${q.id}"
                  class="question-item border bg-slate-50
                         rounded-xl p-4">

                  <div class="flex gap-3">

                    <span class="cursor-move text-slate-400">
                      ⠿
                    </span>

                    <div class="flex-1">

                      <div class="font-bold mb-2">
                        ${s.numbering_mode==='group'
                          ? `Q${gi+1}-${qi+1}`
                          : `Q${App.questionNumber(s,q.id)}`}
                      </div>

                      <input
                        value="${App.escape(q.text)}"
                        oninput="App.actions.updateQuestion(
                          '${g.id}','${q.id}','text',this.value)"
                        class="w-full border rounded-lg p-2 mb-3">

                      <div class="flex flex-wrap gap-3 mb-3">

                        <select
                          onchange="App.actions.updateQuestion(
                            '${g.id}','${q.id}','type',this.value)"
                          class="border rounded-lg p-2">

                          <option value="single"
                            ${q.type==='single'?'selected':''}>
                            単一選択
                          </option>

                          <option value="multiple"
                            ${q.type==='multiple'?'selected':''}>
                            複数選択
                          </option>

                          <option value="text"
                            ${q.type==='text'?'selected':''}>
                            自由記述
                          </option>

                        </select>

                        <label class="flex items-center gap-2">
                          <input
                            type="checkbox"
                            ${q.required?'checked':''}
                            onchange="App.actions.updateQuestion(
                              '${g.id}','${q.id}',
                              'required',this.checked)">
                          必須回答
                        </label>
                      </div>

                      ${
                        q.type !== 'text'
                        ? `
                        <div class="space-y-2">
                          ${(q.options || []).map((o,oi)=>`
                            <input
                              value="${App.escape(o)}"
                              oninput="
                              App.State.editing.groups
                              .find(x=>x.id==='${g.id}')
                              .questions.find(x=>x.id==='${q.id}')
                              .options[${oi}]=this.value"
                              class="w-full border rounded-lg p-2">
                          `).join('')}

                          <button
                            onclick="
                              App.State.editing.groups
                              .find(x=>x.id==='${g.id}')
                              .questions.find(x=>x.id==='${q.id}')
                              .options.push('新しい選択肢');
                              App.renderEdit();
                              App.initSortable();"
                            class="text-blue-600 text-sm">
                            ＋ 選択肢追加
                          </button>
                        </div>`
                        : ''
                      }

                    </div>

                    <button
                      onclick="App.actions.deleteQuestion(
                        '${g.id}','${q.id}')"
                      class="text-red-500">
                      削除
                    </button>

                  </div>
                </div>
                `).join('')}

              </div>

              <button
                onclick="App.actions.addQuestion('${g.id}')"
                class="mt-4 px-4 py-2 rounded-lg
                       bg-blue-50 text-blue-700">
                ＋ 質問追加
              </button>

            </section>
          `).join('')}

        </div>

        <button
          onclick="App.actions.addGroup()"
          class="mt-6 px-5 py-3 rounded-xl
                 bg-slate-900 text-white">
          ＋ グループ追加
        </button>

      </div>
    </main>

    <div id="preview_modal"
         class="hidden fixed inset-0 bg-black/50 z-50 p-4">

      <div class="bg-white max-w-3xl mx-auto mt-10
                  rounded-2xl max-h-[85vh] overflow-auto">

        <div class="p-4 border-b flex justify-between">
          <b>回答者プレビュー</b>
          <button onclick="App.actions.closePreview()">
            ✕
          </button>
        </div>

        <div id="preview_content" class="p-6"></div>

      </div>
    </div>`;

    App.initSortable();
},

questionNumber(s,id) {

    let n = 1;

    for (const g of s.groups) {
        for (const q of g.questions) {
            if (q.id === id) return n;
            n++;
        }
    }

    return n;
},

initSortable() {

    document.querySelectorAll('.question-list')
        .forEach(el => {

            new Sortable(el, {
                group: 'questions',
                animation: 180,

                onEnd(evt) {

                    const from =
                        evt.from.dataset.groupId;

                    const to =
                        evt.to.dataset.groupId;

                    const id =
                        evt.item.dataset.questionId;

                    let q = null;

                    const fg =
                        App.State.editing.groups.find(
                            g => g.id === from
                        );

                    if (!fg) return;

                    const index =
                        fg.questions.findIndex(
                            x => x.id === id
                        );

                    if (index >= 0) {
                        q = fg.questions.splice(
                            index,1
                        )[0];
                    }

                    if (!q) return;

                    const tg =
                        App.State.editing.groups.find(
                            g => g.id === to
                        );

                    if (!tg) return;

                    tg.questions.splice(
                        evt.newIndex,
                        0,
                        q
                    );

                    App.renderEdit();
                    App.initSortable();
                }
            });

        });

    const groups =
        document.getElementById('question_editor');

    if (groups) {

        new Sortable(groups, {
            animation: 180,
            handle: '.cursor-move',
            onEnd(evt) {

                const item =
                    App.State.editing.groups.splice(
                        evt.oldIndex,
                        1
                    )[0];

                App.State.editing.groups.splice(
                    evt.newIndex,
                    0,
                    item
                );

                App.renderEdit();
                App.initSortable();
            }
        });

    }
},

previewHtml(s) {

    let n = 1;

    return `
    <div>
      <h1 class="text-2xl font-bold mb-8">
        ${App.escape(s.title)}
      </h1>

      ${s.groups.map(g => `
        <section class="mb-8">
          <h2 class="font-bold text-lg mb-4">
            ${App.escape(g.name)}
          </h2>

          ${(g.questions || []).map(q => {

            const no = n++;

            return `
            <div class="mb-6">
              <div class="font-semibold mb-2">
                Q${no}.
                ${App.escape(q.text)}
                ${q.required
                  ? '<span class="text-red-500"> *</span>'
                  : ''}
              </div>

              ${
                q.type === 'text'
                ? '<textarea class="w-full border rounded-xl p-3" rows="4"></textarea>'
                : (q.options || []).map(o => `
                    <label class="block py-1">
                      <input
                        type="${q.type==='single'
                          ? 'radio'
                          : 'checkbox'}">
                      ${App.escape(o)}
                    </label>
                  `).join('')
              }
            </div>`;
          }).join('')}

        </section>
      `).join('')}

      <button
        onclick="alert('これはプレビューです。実際には送信されません。')"
        class="w-full bg-blue-600 text-white
               rounded-xl py-3 font-bold">
        回答を送信する
      </button>
    </div>`;
},

renderSettings() {

    const s = App.State.data.settings;
    const fields = App.State.fields;

    const options = fields.map(f => `
      <option value="${App.escape(f.code)}">
        ${App.escape(f.label)}
        (${App.escape(f.code)})
      </option>
    `).join('');

    document.getElementById('app').innerHTML =
    App.header('settings') + `
    <main class="max-w-5xl mx-auto p-4 md:p-8">

      <h1 class="text-2xl font-bold mb-2">
        kintone連携設定
      </h1>

      <p class="text-slate-500 mb-6">
        設定 → 接続確認 → 項目取得 → 顧客同期
        の順で操作してください。
      </p>

      <div class="bg-white rounded-2xl shadow-sm p-6">

        <div class="grid md:grid-cols-2 gap-5">

          ${App.settingInput(
            'subdomain',
            'kintoneホスト',
            s.subdomain,
            'xxxx.cybozu.com'
          )}

          ${App.settingInput(
            'app_id',
            '顧客管理アプリID',
            s.app_id,
            '123'
          )}

          ${App.settingInput(
            'login_name',
            'ログイン名',
            s.login_name,
            ''
          )}

          ${App.settingInput(
            'password',
            'パスワード',
            s.password,
            '',
            'password'
          )}

          ${App.settingInput(
            'proxy',
            'Proxy',
            s.proxy,
            'host:port'
          )}

        </div>

        <label class="flex items-center gap-2 mt-5">
          <input id="setting_ssl_verify"
                 type="checkbox"
                 ${s.ssl_verify ? 'checked':''}>
          SSL証明書を検証する
        </label>

        <div class="flex flex-wrap gap-3 mt-6">

          <button
            onclick="App.actions.saveSettings()"
            class="bg-slate-900 text-white px-4 py-3 rounded-xl">
            設定を保存
          </button>

          <button
            onclick="App.actions.testKintone()"
            class="bg-blue-600 text-white px-4 py-3 rounded-xl">
            接続確認
          </button>

          <button
            onclick="App.actions.fields()"
            class="bg-indigo-600 text-white px-4 py-3 rounded-xl">
            項目一覧を取得
          </button>

          <button
            onclick="App.actions.syncCustomers()"
            class="bg-green-600 text-white px-4 py-3 rounded-xl">
            顧客データを取得・同期
          </button>

        </div>

        <hr class="my-8">

        <h2 class="font-bold text-lg mb-4">
          kintone項目マッピング
        </h2>

        ${App.fieldSelect(
            'field_company',
            '会社名',
            s.field_company,
            options
        )}

        ${App.fieldSelect(
            'field_name',
            '氏名',
            s.field_name,
            options
        )}

        ${App.fieldSelect(
            'field_email',
            'メールアドレス',
            s.field_email,
            options
        )}

        ${App.fieldSelect(
            'field_department',
            '部署名',
            s.field_department,
            options
        )}

        ${App.fieldSelect(
            'field_phone',
            '電話番号',
            s.field_phone,
            options
        )}

        <div class="mt-5">
          <div class="font-semibold mb-2">住所</div>

          <div class="grid md:grid-cols-2 gap-2">
            ${fields.map(f => `
              <label class="flex items-center gap-2">
                <input
                  type="checkbox"
                  class="address_field"
                  value="${App.escape(f.code)}"
                  ${(s.field_address || []).includes(f.code)
                    ? 'checked':''}>
                ${App.escape(f.label)}
              </label>
            `).join('')}
          </div>
        </div>

      </div>
    </main>`;
},

settingInput(key,label,value,placeholder,type='text') {

    return `
    <label>
      <span class="block font-semibold text-sm mb-1">
        ${label}
      </span>
      <input
        id="setting_${key}"
        type="${type}"
        value="${App.escape(value || '')}"
        placeholder="${App.escape(placeholder)}"
        class="w-full border rounded-lg p-3">
    </label>`;
},

fieldSelect(key,label,value,options) {

    return `
    <label class="block mt-4">
      <span class="block font-semibold text-sm mb-1">
        ${label}
      </span>

      <select
        id="setting_${key}"
        class="w-full border rounded-lg p-3">

        <option value="">-- 選択してください --</option>
        ${options.replace(
            `value="${App.escape(value || '')}"`,
            `value="${App.escape(value || '')}" selected`
        )}

      </select>
    </label>`;
},

renderSend() {

    const s = App.State.sendSurvey;
    const filter =
        App.State.customerFilter.toLowerCase();

    const customers =
        App.State.data.customers.filter(c => {

            if (!filter) return true;

            return (
                String(c.company || '').toLowerCase().includes(filter) ||
                String(c.name || '').toLowerCase().includes(filter) ||
                String(c.email || '').toLowerCase().includes(filter)
            );
        });

    const selected =
        App.State.selectedCustomers;

    document.getElementById('app').innerHTML =
    App.header('list') + `
    <main class="max-w-7xl mx-auto p-4 md:p-8">

      <div class="mb-6">
        <div class="text-sm text-blue-600 font-semibold">
          ホーム ＞ アンケート一覧 ＞ 顧客選択・送信
        </div>

        <h1 class="text-2xl font-bold mt-2">
          ${App.escape(s.title)}
        </h1>

        <p class="text-slate-500 mt-1">
          ①顧客選択 → ②本文確認 → ③送信
        </p>
      </div>

      <div class="grid lg:grid-cols-2 gap-6">

        <section class="bg-white rounded-2xl shadow-sm p-5">

          <div class="flex justify-between items-center mb-4">
            <h2 class="font-bold text-lg">
              ①送信先を選択
            </h2>

            <button
              onclick="App.actions.syncCustomers()"
              class="text-blue-600 text-sm">
              kintoneから更新
            </button>
          </div>

          <input
            id="customer_filter"
            value="${App.escape(App.State.customerFilter)}"
            oninput="
              App.State.customerFilter=this.value;
              App.renderSend();"
            placeholder="会社名・氏名・メールで検索"
            class="w-full border rounded-lg p-3 mb-4">

          <label class="flex items-center gap-2 border-b pb-3 mb-3">
            <input
              id="select_all"
              type="checkbox"
              onchange="
                App.actions.toggleAllCustomers(this.checked)"
              ${customers.length &&
                customers.every(c => selected.has(c.id))
                ? 'checked':''}>
            全選択
          </label>

          <div id="customer_table"
               class="max-h-[500px] overflow-y-auto space-y-2">

            ${customers.map(c => `
              <label class="block border rounded-xl p-3
                             hover:bg-slate-50">

                <div class="flex gap-3">

                  <input
                    type="checkbox"
                    ${selected.has(c.id)?'checked':''}
                    ${c.source !== 'kintone'?'disabled':''}
                    onchange="
                      App.actions.toggleCustomer(
                        '${c.id}')">

                  <div class="flex-1">
                    <div class="font-bold">
                      ${App.escape(c.company)}
                    </div>

                    <div>
                      ${App.escape(c.name)}
                    </div>

                    <div class="text-sm text-slate-500">
                      ${App.escape(c.email)}
                    </div>

                    <div class="text-xs mt-1">
                      ${
                        c.answer_status === 'answered'
                        ? '<span class="text-green-600">回答済み</span>'
                        : '<span class="text-orange-600">未回答</span>'
                      }
                    </div>
                  </div>

                </div>

              </label>
            `).join('')}

          </div>

          <div class="mt-4 bg-blue-50 text-blue-800
                      rounded-xl p-3">
            選択中：<b>${selected.size}</b> 件
          </div>

        </section>

        <section class="bg-white rounded-2xl shadow-sm p-5">

          <h2 class="font-bold text-lg mb-4">
            ②メール内容
          </h2>

          <label class="block mb-4">
            <span class="font-semibold text-sm">
              テンプレート
            </span>

            <select id="template_type"
                    class="w-full border rounded-lg p-3 mt-1">
              <option value="initial">初回送信</option>
              <option value="reminder">リマインド</option>
            </select>
          </label>

          <label class="block mb-4">
            <span class="font-semibold text-sm">
              件名
            </span>

            <input
              id="mail_subject"
              value="${App.escape(
                s.mail_subject ||
                'アンケートご協力のお願い'
              )}"
              class="w-full border rounded-lg p-3 mt-1">
          </label>

          <label class="block">
            <span class="font-semibold text-sm">
              本文
            </span>

            <textarea
              id="mail_body"
              rows="14"
              class="w-full border rounded-lg p-3 mt-1">{$顧客名} 様

アンケートへのご協力をお願いいたします。

以下のURLからご回答ください。

{アンケートURL}

ご回答よろしくお願いいたします。</textarea>
          </label>

          <div class="mt-4 bg-slate-50 rounded-xl p-4 text-sm">
            <b>使用できる変数</b>
            <div class="mt-2 font-mono">
              {顧客名}<br>
              {アンケートURL}
            </div>
          </div>

          <button
            onclick="App.actions.executeSend()"
            class="mt-5 w-full bg-blue-600 hover:bg-blue-700
                   text-white py-4 rounded-xl font-bold">
            ③送信内容を確認して一括送信
          </button>

        </section>

      </div>
    </main>`;
},

renderSummary() {

    const s = App.State.selectedSurvey;

    const responses =
        App.State.data.responses.filter(
            r => r.survey_id === s.id
        );

    const sent =
        App.State.data.customers.filter(
            c => Number(c.send_count || 0) > 0
        ).length;

    const answered =
        responses.length;

    const unanswered =
        Math.max(sent - answered, 0);

    const rate =
        sent
            ? ((answered / sent) * 100).toFixed(1)
            : '0.0';

    document.getElementById('app').innerHTML =
    App.header('list') + `
    <main class="max-w-7xl mx-auto p-4 md:p-8">

      <div class="mb-6">
        <div class="text-sm text-blue-600">
          ホーム ＞ アンケート一覧 ＞ 集計
        </div>

        <h1 class="text-2xl font-bold mt-2">
          ${App.escape(s.title)}
        </h1>
      </div>

      <div class="grid md:grid-cols-4 gap-4 mb-8">

        ${App.card('送信対象者数', sent + ' 人')}
        ${App.card('回答数', answered + ' 件')}
        ${App.card('未回答数', unanswered + ' 人')}
        ${App.card('回答率', rate + ' %')}

      </div>

      <div class="bg-white rounded-2xl shadow-sm p-6">

        <div class="flex justify-between items-center mb-5">
          <h2 class="font-bold text-lg">
            回答一覧
          </h2>

          <a
            href="?action=csv&survey_id=${encodeURIComponent(s.id)}"
            class="bg-slate-900 text-white px-4 py-2 rounded-lg">
            CSV出力
          </a>
        </div>

        ${
          responses.length
          ? `<div class="space-y-3">
              ${responses.map(r => `
                <button
                  onclick="App.actions.showResponse('${r.id}')"
                  class="w-full text-left border rounded-xl p-4
                         hover:bg-slate-50">

                  <div class="font-bold">
                    ${App.escape(r.company)}
                    ${App.escape(r.name)}
                  </div>

                  <div class="text-sm text-slate-500">
                    ${App.escape(r.email)}
                    ／
                    ${App.escape(r.answered_at)}
                  </div>

                </button>
              `).join('')}
             </div>`
          : `<div class="text-center text-slate-400 py-16">
               現在、回答データはありません
             </div>`
        }

      </div>

      <div id="response_modal"
           class="hidden fixed inset-0 bg-black/50
                  z-50 p-4">

        <div class="bg-white max-w-2xl mx-auto mt-10
                    rounded-2xl p-6">

          <div class="flex justify-between mb-5">
            <b>全回答</b>
            <button onclick="
              document.getElementById(
                'response_modal'
              ).classList.add('hidden')">
              ✕
            </button>
          </div>

          <div id="response_detail"></div>

        </div>
      </div>

    </main>`;
},

card(label,value) {

    return `
    <div class="bg-white rounded-2xl shadow-sm p-5">
      <div class="text-sm text-slate-500">${label}</div>
      <div class="text-2xl font-bold mt-2">${value}</div>
    </div>`;
},

async showResponse(id) {

    const r =
        await App.api(
            'get_response',
            {response_id:id},
            'GET'
        );

    const response = r.response;

    document.getElementById(
        'response_detail'
    ).innerHTML = `
      <div class="mb-5">
        <div class="font-bold text-lg">
          ${App.escape(response.company)}
          ${App.escape(response.name)}
        </div>

        <div class="text-sm text-slate-500">
          ${App.escape(response.email)}
          ／
          ${App.escape(response.answered_at)}
        </div>
      </div>

      <div class="space-y-4">
        ${Object.entries(
          response.answers || {}
        ).map(([k,v]) => `
          <div class="border rounded-xl p-4">
            <div class="font-semibold">${App.escape(k)}</div>
            <div class="mt-2 whitespace-pre-wrap">
              ${App.escape(
                Array.isArray(v)
                  ? v.join(', ')
                  : v
              )}
            </div>
          </div>
        `).join('')}
      </div>`;

    document.getElementById(
        'response_modal'
    ).classList.remove('hidden');
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

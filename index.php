<?php
declare(strict_types=1);

/*
========================================================================
GUARD COMMENT — 固定名称一覧
※以下の名称は変更・削除禁止。

ストレージ:
survey_storage_directory
survey_storage_file
survey_admin_session_v1

データトップキー:
surveys
responses
customers
settings
mail_logs

主要項目:
id
title
start_at
end_at
status
created_at
updated_at
numbering_mode
groups
deleted
name
questions
text
type
required
options
other_enabled
company
email
department
phone
address
source
sent_at
send_count
answer_status
kintone_status
survey_id
customer_id
answered_at
answers
subdomain
login_name
password
app_id
ssl_verify
proxy
field_company
field_name
field_email
field_department
field_phone
field_address

POST/GET:
action
survey_id
customer_id
response_id
keyword
status_filter
sort
survey_json
settings_json
csrf_token
recipient_ids
mail_subject
mail_body
template_type
app_id

API/JSON:
properties
records
label
code
type
message
ok
fields

DOM/JS:
app
csrf_token
survey_title
survey_start_at
survey_end_at
survey_numbering_mode
question_editor
preview_modal
preview_content
response_modal
response_detail
response_filter
response_table
customer_filter
customer_table
select_all
mail_subject
mail_body
template_type
settings_form
settings_json
setting_subdomain
setting_app_id
setting_login_name
setting_password
setting_proxy
setting_ssl_verify
field_message
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

function survey_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

    return array_replace_recursive(survey_default_data(), $data);
}

function survey_write_data(array $data): bool
{
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
    return hash_equals(
        (string)($_SESSION['csrf_token'] ?? ''),
        (string)($_POST['csrf_token'] ?? '')
    );
}

function survey_uuid(): string
{
    return bin2hex(random_bytes(16));
}

function survey_normalize_kintone_base(string $input): array
{
    $input = trim($input);
    $input = rtrim($input, '/');

    if ($input === '') {
        return ['ok' => false, 'error' => 'kintoneサブドメインが未入力です。'];
    }

    if (!preg_match('~^https?://~i', $input)) {
        $input = 'https://' . $input;
    }

    $parsed = parse_url($input);
    $host = is_array($parsed) ? ($parsed['host'] ?? '') : '';
    $port = is_array($parsed) ? ($parsed['port'] ?? null) : null;

    if ($host === '' && preg_match(
        '~^https?://([^/?#]+)~i',
        $input,
        $matches
    )) {
        $hostPort = strtolower($matches[1]);
        $host = $hostPort;
    }

    $host = strtolower(trim($host));

    if ($host === '') {
        return ['ok' => false, 'error' => 'kintoneホスト名を取得できません。'];
    }

    $hostOnly = preg_replace('~:\d+$~', '', $host) ?: $host;

    if (
        !preg_match(
            '~^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.cybozu\.com$~i',
            $hostOnly
        ) &&
        !preg_match('~^[a-z0-9.-]+$~i', $hostOnly)
    ) {
        return ['ok' => false, 'error' => '許可されていないkintoneホスト名です。'];
    }

    $authority = $hostOnly . ($port !== null ? ':' . (int)$port : '');

    return [
        'ok' => true,
        'base' => 'https://' . $authority,
        'host' => $hostOnly,
    ];
}

function survey_parse_proxy(string $input): array
{
    $input = trim($input);

    if ($input === '') {
        return ['ok' => true, 'used' => false, 'value' => ''];
    }

    if (!preg_match('~^(?:(https?)://)?([^/:]+):(\d{1,5})$~i', $input, $m)) {
        return [
            'ok' => false,
            'used' => true,
            'value' => '',
            'error' => 'Proxy形式は host:port、http://host:port、https://host:port で指定してください。',
        ];
    }

    $scheme = strtolower($m[1] ?: 'http');
    $host = $m[2];
    $port = (int)$m[3];

    if ($port < 1 || $port > 65535) {
        return ['ok' => false, 'used' => true, 'value' => '', 'error' => 'Proxyポート番号が不正です。'];
    }

    return [
        'ok' => true,
        'used' => true,
        'value' => $scheme . '://' . $host . ':' . $port,
        'host' => $host,
        'port' => $port,
    ];
}

function survey_last_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
        return is_array($headers) ? $headers : [];
    }

    global $http_response_header;
    return isset($http_response_header) && is_array($http_response_header)
        ? $http_response_header
        : [];
}

function survey_status_from_headers(array $headers): int
{
    foreach ($headers as $header) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~i', (string)$header, $m)) {
            return (int)$m[1];
        }
    }

    return 0;
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

    $wrappers = stream_get_wrappers();

    if (!in_array('http', $wrappers, true) || !in_array('https', $wrappers, true)) {
        return [
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' => 'PHPからHTTP/HTTPS通信を実行できるstream transportが登録されていません。',
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

    if ($proxyInfo['used']) {
        $httpOptions['proxy'] = $proxyInfo['value'];
        $httpOptions['request_fulluri'] = true;
    }

    $parts = parse_url($url);
    $peerName = is_array($parts) ? ($parts['host'] ?? '') : '';

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
        static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        }
    );

    $body = file_get_contents($url, false, $context);

    restore_error_handler();

    $headersReceived = survey_last_headers();
    $status = survey_status_from_headers($headersReceived);
    $bodyText = is_string($body) ? $body : '';

    if ($status === 0) {
        $cause = $warning !== ''
            ? $warning
            : 'HTTPレスポンスを取得できませんでした。';

        $cause .= ' 確認事項: DNS、外部HTTPS通信、Proxy、ファイアウォール、OpenSSL、タイムアウト。';

        if ($proxyInfo['used']) {
            $cause .= ' Proxy接続失敗の可能性があります。';
        }

        return [
            'status' => 0,
            'body' => $bodyText,
            'json' => json_decode($bodyText, true),
            'error' => $cause,
            'url' => $url,
            'proxy_used' => $proxyInfo['used'],
        ];
    }

    return [
        'status' => $status,
        'body' => $bodyText,
        'json' => json_decode($bodyText, true),
        'error' => $warning,
        'url' => $url,
        'proxy_used' => $proxyInfo['used'],
    ];
}

function survey_kintone_request(
    array $settings,
    string $path,
    string $method = 'GET',
    ?string $content = null
): array {
    $normalized = survey_normalize_kintone_base((string)($settings['subdomain'] ?? ''));

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

    $url = $normalized['base'] . '/k/v1/' . ltrim($path, '/');
    $separator = str_contains($url, '?') ? '&' : '?';
    $url .= $separator . 'app=' . rawurlencode($appId);

    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');
    $auth = base64_encode($login . ':' . $password);

    $headers = [
        'X-Cybozu-Authorization: ' . $auth,
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

function survey_api_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo survey_json($payload);
    exit;
}

function survey_public_data(array $data): array
{
    $data['settings']['password'] = '';
    return $data;
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($action !== '') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !survey_check_token()) {
        survey_api_response([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。',
        ], 419);
    }

    $data = survey_read_data();

    if ($action === 'load') {
        survey_api_response([
            'ok' => true,
            'data' => survey_public_data($data),
        ]);
    }

    if ($action === 'save_settings') {
        $json = (string)($_POST['settings_json'] ?? '');
        $settings = json_decode($json, true);

        if (!is_array($settings)) {
            survey_api_response(['ok' => false, 'message' => '設定データが不正です。'], 400);
        }

        $settings['password'] = (string)($settings['password'] ?? '');

        $data['settings'] = array_replace($data['settings'], [
            'subdomain' => trim((string)($settings['subdomain'] ?? '')),
            'login_name' => trim((string)($settings['login_name'] ?? '')),
            'password' => $settings['password'],
            'app_id' => trim((string)($settings['app_id'] ?? '')),
            'ssl_verify' => (bool)($settings['ssl_verify'] ?? true),
            'proxy' => trim((string)($settings['proxy'] ?? '')),
            'field_company' => (string)($settings['field_company'] ?? ''),
            'field_name' => (string)($settings['field_name'] ?? ''),
            'field_email' => (string)($settings['field_email'] ?? ''),
            'field_department' => (string)($settings['field_department'] ?? ''),
            'field_phone' => (string)($settings['field_phone'] ?? ''),
            'field_address' => is_array($settings['field_address'] ?? null)
                ? array_values($settings['field_address'])
                : [],
        ]);

        if (!survey_write_data($data)) {
            survey_api_response(['ok' => false, 'message' => '設定保存に失敗しました。'], 500);
        }

        survey_api_response([
            'ok' => true,
            'message' => '設定を保存しました。',
            'data' => survey_public_data($data),
        ]);
    }

    if ($action === 'kintone_fields' || $action === 'kintone_test') {
        $result = survey_kintone_request(
            $data['settings'],
            'app/form/fields.json'
        );

        if ($result['status'] === 0) {
            survey_api_response([
                'ok' => false,
                'status' => 0,
                'message' => 'kintoneからHTTPレスポンスを取得できませんでした。',
                'diagnostic' => [
                    'status' => $result['status'],
                    'url' => $result['url'],
                    'proxy_used' => $result['proxy_used'],
                    'error' => $result['error'],
                    'body' => $result['body'],
                ],
            ]);
        }

        if (in_array($result['status'], [401, 403], true)) {
            survey_api_response([
                'ok' => false,
                'status' => $result['status'],
                'message' => '認証または権限エラーです。',
                'diagnostic' => $result,
            ]);
        }

        if ($result['status'] < 200 || $result['status'] >= 300) {
            survey_api_response([
                'ok' => false,
                'status' => $result['status'],
                'message' => 'kintone APIエラーです。',
                'diagnostic' => $result,
            ]);
        }

        $properties = $result['json']['properties'] ?? [];

        survey_api_response([
            'ok' => true,
            'status' => $result['status'],
            'message' => $action === 'kintone_test'
                ? 'kintone接続に成功しました。'
                : '項目一覧を取得しました。',
            'fields' => $properties,
        ]);
    }

    if ($action === 'save_survey') {
        $survey = json_decode((string)($_POST['survey_json'] ?? ''), true);

        if (!is_array($survey)) {
            survey_api_response(['ok' => false, 'message' => 'アンケートデータが不正です。'], 400);
        }

        $survey['id'] = (string)($survey['id'] ?? survey_uuid());
        $survey['title'] = trim((string)($survey['title'] ?? '無題のアンケート'));
        $survey['status'] = in_array(
            $survey['status'] ?? 'draft',
            ['draft', 'active', 'ended'],
            true
        ) ? $survey['status'] : 'draft';
        $survey['updated_at'] = date('c');
        $survey['created_at'] = (string)($survey['created_at'] ?? date('c'));
        $survey['deleted'] = false;

        $found = false;

        foreach ($data['surveys'] as $index => $existing) {
            if (($existing['id'] ?? '') === $survey['id']) {
                $data['surveys'][$index] = $survey;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $data['surveys'][] = $survey;
        }

        if (!survey_write_data($data)) {
            survey_api_response(['ok' => false, 'message' => 'アンケート保存に失敗しました。'], 500);
        }

        survey_api_response([
            'ok' => true,
            'message' => 'アンケートを保存しました。',
            'data' => survey_public_data($data),
        ]);
    }

    if ($action === 'delete_survey') {
        $surveyId = (string)($_POST['survey_id'] ?? '');

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $surveyId) {
                $survey['deleted'] = true;
                $survey['updated_at'] = date('c');
            }
        }
        unset($survey);

        survey_write_data($data);
        survey_api_response(['ok' => true, 'data' => survey_public_data($data)]);
    }

    if ($action === 'duplicate_survey') {
        $surveyId = (string)($_POST['survey_id'] ?? '');
        $copy = null;

        foreach ($data['surveys'] as $survey) {
            if (($survey['id'] ?? '') === $surveyId) {
                $copy = $survey;
                break;
            }
        }

        if ($copy === null) {
            survey_api_response(['ok' => false, 'message' => '対象がありません。'], 404);
        }

        $copy['id'] = survey_uuid();
        $copy['title'] = $copy['title'] . '（複製）';
        $copy['status'] = 'draft';
        $copy['created_at'] = date('c');
        $copy['updated_at'] = date('c');
        $copy['deleted'] = false;
        $data['surveys'][] = $copy;

        survey_write_data($data);
        survey_api_response(['ok' => true, 'data' => survey_public_data($data)]);
    }

    if ($action === 'save_response') {
        $response = json_decode((string)($_POST['response_json'] ?? ''), true);

        if (!is_array($response)) {
            survey_api_response(['ok' => false, 'message' => '回答データが不正です。'], 400);
        }

        $response['id'] = (string)($response['id'] ?? survey_uuid());
        $response['answered_at'] = date('c');
        $data['responses'][] = $response;

        survey_write_data($data);
        survey_api_response(['ok' => true, 'message' => '回答を保存しました。']);
    }

    if ($action === 'csv') {
        $surveyId = (string)($_GET['survey_id'] ?? '');
        $survey = null;

        foreach ($data['surveys'] as $item) {
            if (($item['id'] ?? '') === $surveyId) {
                $survey = $item;
                break;
            }
        }

        if (!is_array($survey)) {
            http_response_code(404);
            exit('Not Found');
        }

        $questions = [];
        foreach (($survey['groups'] ?? []) as $group) {
            foreach (($group['questions'] ?? []) as $question) {
                $questions[] = $question;
            }
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="survey_' . rawurlencode($surveyId) . '.csv"');

        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");

        $header = ['回答ID', '回答日時', '顧客ID', '会社名', '氏名', 'メールアドレス'];
        foreach ($questions as $question) {
            $header[] = (string)($question['text'] ?? '');
        }
        fputcsv($out, $header);

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

            $answers = is_array($response['answers'] ?? null)
                ? $response['answers']
                : [];

            foreach ($questions as $question) {
                $row[] = is_array($answers[$question['id'] ?? ''] ?? null)
                    ? implode('、', $answers[$question['id'] ?? ''])
                    : ($answers[$question['id'] ?? ''] ?? '');
            }

            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }

    survey_api_response(['ok' => false, 'message' => '不明な操作です。'], 400);
}

$data = survey_read_data();
$csrf = survey_token();

if (isset($_GET['public'], $_GET['survey_id'])) {
    $surveyId = (string)$_GET['survey_id'];
    $publicSurvey = null;

    foreach ($data['surveys'] as $survey) {
        if (($survey['id'] ?? '') === $surveyId && empty($survey['deleted'])) {
            $publicSurvey = $survey;
            break;
        }
    }

    if (!$publicSurvey) {
        http_response_code(404);
        exit('アンケートが見つかりません。');
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
<h1 class="text-2xl font-bold mb-6"><?= survey_h($publicSurvey['title']) ?></h1>
<form method="post" class="space-y-6">
<input type="hidden" name="action" value="save_response">
<input type="hidden" name="csrf_token" value="<?= survey_h($csrf) ?>">
<input type="hidden" name="response_json" id="public_response_json">
<div>
<label class="block font-semibold mb-1">会社名</label>
<input id="public_company" class="w-full border rounded-lg p-2">
</div>
<div>
<label class="block font-semibold mb-1">氏名</label>
<input id="public_name" class="w-full border rounded-lg p-2">
</div>
<div>
<label class="block font-semibold mb-1">メールアドレス</label>
<input id="public_email" type="email" class="w-full border rounded-lg p-2">
</div>
<?php foreach (($publicSurvey['groups'] ?? []) as $group): ?>
<fieldset class="border-t pt-4">
<legend class="font-bold mb-3"><?= survey_h($group['name'] ?? '') ?></legend>
<?php foreach (($group['questions'] ?? []) as $question): ?>
<div class="mb-5">
<label class="block font-semibold mb-2"><?= survey_h($question['text'] ?? '') ?></label>
<?php if (($question['type'] ?? '') === 'text'): ?>
<textarea data-question="<?= survey_h($question['id'] ?? '') ?>" class="w-full border rounded-lg p-2"></textarea>
<?php else: ?>
<?php foreach (($question['options'] ?? []) as $option): ?>
<label class="block mb-1">
<input
data-question="<?= survey_h($question['id'] ?? '') ?>"
value="<?= survey_h($option) ?>"
type="<?= ($question['type'] ?? '') === 'multiple' ? 'checkbox' : 'radio' ?>"
>
<?= survey_h($option) ?>
</label>
<?php endforeach; ?>
<?php endif; ?>
</div>
<?php endforeach; ?>
</fieldset>
<?php endforeach; ?>
<button type="button" onclick="submitPublicSurvey()" class="bg-indigo-600 text-white px-5 py-3 rounded-lg">
回答を送信
</button>
</form>
</section>
</main>
<script>
function submitPublicSurvey() {
    const answers = {};
    document.querySelectorAll('[data-question]').forEach(function (el) {
        const id = el.dataset.question;
        if (el.type === 'checkbox') {
            if (!answers[id]) answers[id] = [];
            if (el.checked) answers[id].push(el.value);
        } else if (el.type === 'radio') {
            if (el.checked) answers[id] = el.value;
        } else {
            answers[id] = el.value;
        }
    });

    const response = {
        id: crypto.randomUUID ? crypto.randomUUID() : String(Date.now()),
        survey_id: <?= survey_json($surveyId) ?>,
        customer_id: '',
        company: document.getElementById('public_company').value,
        name: document.getElementById('public_name').value,
        email: document.getElementById('public_email').value,
        answered_at: '',
        answers: answers
    };

    document.getElementById('public_response_json').value = JSON.stringify(response);
    document.querySelector('form').submit();
}
</script>
</body>
</html>
<?php
exit;
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
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
        csrf_token: <?= survey_json($csrf) ?>
    },

    api: {
        async request(action, values = {}) {
            const body = new URLSearchParams();
            body.set('action', action);
            body.set('csrf_token', App.state.csrf_token);

            Object.keys(values).forEach(function (key) {
                body.set(key, typeof values[key] === 'string'
                    ? values[key]
                    : JSON.stringify(values[key]));
            });

            const response = await fetch(location.pathname, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: body.toString()
            });

            const json = await response.json();

            if (!json.ok) {
                const diagnostic = json.diagnostic || {};
                throw new Error(
                    json.message +
                    (diagnostic.error ? '\n' + diagnostic.error : '')
                );
            }

            return json;
        },

        async load() {
            const result = await fetch(location.pathname + '?action=load');
            const json = await result.json();

            if (!json.ok) throw new Error(json.message);
            App.state.data = json.data;
        }
    },

    actions: {
        async init() {
            try {
                await App.api.load();
                App.render.list();
            } catch (error) {
                App.render.error(error.message);
            }
        },

        newSurvey() {
            App.state.editing = App.helpers.emptySurvey();
            App.state.page = 'edit';
            App.render.edit();
        },

        editSurvey(id) {
            const survey = App.state.data.surveys.find(item => item.id === id);
            if (!survey) return;

            App.state.editing = structuredClone(survey);
            App.state.page = 'edit';
            App.render.edit();
        },

        async saveSurvey() {
            const survey = App.helpers.readSurveyForm();
            App.state.editing = survey;

            try {
                const result = await App.api.request('save_survey', {
                    survey_json: survey
                });

                App.state.data = result.data;
                App.state.page = 'list';
                App.state.editing = null;
                App.render.list();
                alert('保存しました。');
            } catch (error) {
                alert(error.message);
            }
        },

        cancelEdit() {
            if (confirm('変更を破棄して一覧へ戻りますか？')) {
                App.state.page = 'list';
                App.state.editing = null;
                App.render.list();
            }
        },

        async duplicateSurvey(id) {
            try {
                const result = await App.api.request('duplicate_survey', {survey_id: id});
                App.state.data = result.data;
                App.render.list();
            } catch (error) {
                alert(error.message);
            }
        },

        async deleteSurvey(id) {
            if (!confirm('このアンケートを削除しますか？')) return;

            try {
                const result = await App.api.request('delete_survey', {survey_id: id});
                App.state.data = result.data;
                App.render.list();
            } catch (error) {
                alert(error.message);
            }
        },

        toggleStatus(id) {
            const survey = App.state.data.surveys.find(item => item.id === id);
            if (!survey) return;

            survey.status = survey.status === 'active' ? 'ended' : 'active';
            App.api.request('save_survey', {survey_json: survey})
                .then(result => {
                    App.state.data = result.data;
                    App.render.list();
                })
                .catch(error => alert(error.message));
        },

        openPreview() {
            const survey = App.helpers.readSurveyForm();
            document.getElementById('preview_content').innerHTML =
                App.helpers.previewHtml(survey);
            document.getElementById('preview_modal').classList.remove('hidden');
        },

        closeModal(id) {
            document.getElementById(id).classList.add('hidden');
        },

        addGroup() {
            const survey = App.helpers.readSurveyForm();
            survey.groups.push({
                id: App.helpers.uuid(),
                name: '新しいグループ',
                questions: []
            });
            App.state.editing = survey;
            App.render.edit();
        },

        removeGroup(groupId) {
            const survey = App.helpers.readSurveyForm();
            survey.groups = survey.groups.filter(group => group.id !== groupId);
            App.state.editing = survey;
            App.render.edit();
        },

        addQuestion(groupId) {
            const survey = App.helpers.readSurveyForm();
            const group = survey.groups.find(item => item.id === groupId);
            if (!group) return;

            group.questions.push({
                id: App.helpers.uuid(),
                text: '新しい質問',
                type: 'single',
                required: false,
                options: ['選択肢1', '選択肢2'],
                other_enabled: false
            });

            App.state.editing = survey;
            App.render.edit();
        },

        removeQuestion(groupId, questionId) {
            const survey = App.helpers.readSurveyForm();
            const group = survey.groups.find(item => item.id === groupId);
            if (!group) return;

            group.questions = group.questions.filter(question => question.id !== questionId);
            App.state.editing = survey;
            App.render.edit();
        },

        changeType(groupId, questionId, type) {
            const survey = App.helpers.readSurveyForm();
            const question = App.helpers.findQuestion(survey, groupId, questionId);
            if (!question) return;

            question.type = type;
            if (type === 'text') question.options = [];
            if (type !== 'text' && !question.options.length) {
                question.options = ['選択肢1', '選択肢2'];
            }

            App.state.editing = survey;
            App.render.edit();
        },

        addOption(groupId, questionId) {
            const survey = App.helpers.readSurveyForm();
            const question = App.helpers.findQuestion(survey, groupId, questionId);
            if (!question) return;

            question.options.push('新しい選択肢');
            App.state.editing = survey;
            App.render.edit();
        },

        removeOption(groupId, questionId, index) {
            const survey = App.helpers.readSurveyForm();
            const question = App.helpers.findQuestion(survey, groupId, questionId);
            if (!question) return;

            question.options.splice(index, 1);
            App.state.editing = survey;
            App.render.edit();
        },

        filterList() {
            App.state.keyword = document.getElementById('list_keyword').value;
            App.state.status_filter = document.getElementById('list_status').value;
            App.state.sort = document.getElementById('list_sort').value;
            App.render.list();
        },

        showAggregate(id) {
            App.state.selectedSurvey = id;
            App.state.page = 'aggregate';
            App.render.aggregate();
        },

        showSend(id) {
            App.state.selectedSurvey = id;
            App.state.page = 'send';
            App.render.send();
        },

        showSettings() {
            App.state.page = 'settings';
            App.render.settings();
        },

        async testKintone() {
            try {
                App.helpers.writeSettingsForm();
                const result = await App.api.request('kintone_test');
                document.getElementById('field_message').textContent =
                    result.message + ' HTTPステータス: ' + result.status;
            } catch (error) {
                document.getElementById('field_message').textContent = error.message;
            }
        },

        async fetchKintoneFields() {
            try {
                App.helpers.writeSettingsForm();
                const result = await App.api.request('kintone_fields');
                App.state.fields = result.fields || [];
                App.render.fieldSelectors();
                document.getElementById('field_message').textContent =
                    result.message + ' HTTPステータス: ' + result.status;
            } catch (error) {
                document.getElementById('field_message').textContent = error.message;
            }
        },

        async saveSettings() {
            try {
                App.helpers.writeSettingsForm();
                const values = App.helpers.readSettingsForm();
                const result = await App.api.request('save_settings', {
                    settings_json: values
                });

                App.state.data = result.data;
                alert(result.message);
            } catch (error) {
                alert(error.message);
            }
        },

        openResponse(id) {
            const response = App.state.data.responses.find(item => item.id === id);
            if (!response) return;

            document.getElementById('response_detail').innerHTML =
                App.helpers.responseHtml(response);
            document.getElementById('response_modal').classList.remove('hidden');
        },

        toggleQuestion(id) {
            const node = document.getElementById(id);
            if (node) node.classList.toggle('hidden');
        }
    },

    helpers: {
        uuid() {
            return crypto.randomUUID
                ? crypto.randomUUID()
                : Date.now().toString(36) + Math.random().toString(36).slice(2);
        },

        emptySurvey() {
            return {
                id: App.helpers.uuid(),
                title: '新しいアンケート',
                start_at: '',
                end_at: '',
                status: 'draft',
                created_at: new Date().toISOString(),
                updated_at: new Date().toISOString(),
                numbering_mode: 'global',
                groups: [{
                    id: App.helpers.uuid(),
                    name: '基本情報',
                    questions: []
                }],
                deleted: false
            };
        },

        esc(value) {
            return String(value ?? '').replace(/[&<>"']/g, function (char) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[char];
            });
        },

        findQuestion(survey, groupId, questionId) {
            const group = survey.groups.find(item => item.id === groupId);
            return group
                ? group.questions.find(item => item.id === questionId)
                : null;
        },

        readSurveyForm() {
            if (!App.state.editing) return App.helpers.emptySurvey();

            const survey = structuredClone(App.state.editing);
            const title = document.getElementById('survey_title');

            if (title) survey.title = title.value;
            survey.start_at = document.getElementById('survey_start_at')?.value || '';
            survey.end_at = document.getElementById('survey_end_at')?.value || '';
            survey.numbering_mode =
                document.getElementById('survey_numbering_mode')?.value || 'global';

            document.querySelectorAll('[data-group-name]').forEach(function (node) {
                const group = survey.groups.find(item => item.id === node.dataset.groupName);
                if (group) group.name = node.value;
            });

            document.querySelectorAll('[data-question-text]').forEach(function (node) {
                const question = App.helpers.findQuestion(
                    survey,
                    node.dataset.groupId,
                    node.dataset.questionText
                );
                if (question) question.text = node.value;
            });

            document.querySelectorAll('[data-required]').forEach(function (node) {
                const question = App.helpers.findQuestion(
                    survey,
                    node.dataset.groupId,
                    node.dataset.required
                );
                if (question) question.required = node.checked;
            });

            document.querySelectorAll('[data-option]').forEach(function (node) {
                const question = App.helpers.findQuestion(
                    survey,
                    node.dataset.groupId,
                    node.dataset.questionId
                );
                if (question) {
                    question.options[Number(node.dataset.index)] = node.value;
                }
            });

            return survey;
        },

        writeSettingsForm() {
            const settings = App.state.data.settings || {};
            const set = (id, value) => {
                const node = document.getElementById(id);
                if (node) node.value = value ?? '';
            };

            set('setting_subdomain', settings.subdomain);
            set('setting_app_id', settings.app_id);
            set('setting_login_name', settings.login_name);
            set('setting_password', '');
            set('setting_proxy', settings.proxy);

            const verify = document.getElementById('setting_ssl_verify');
            if (verify) verify.checked = settings.ssl_verify !== false;
        },

        readSettingsForm() {
            const old = App.state.data.settings || {};
            const selected = id => document.getElementById(id);

            return {
                ...old,
                subdomain: selected('setting_subdomain')?.value || '',
                app_id: selected('setting_app_id')?.value || '',
                login_name: selected('setting_login_name')?.value || '',
                password: selected('setting_password')?.value || old.password || '',
                proxy: selected('setting_proxy')?.value || '',
                ssl_verify: selected('setting_ssl_verify')?.checked ?? true,
                field_company: selected('field_company')?.value || '',
                field_name: selected('field_name')?.value || '',
                field_email: selected('field_email')?.value || '',
                field_department: selected('field_department')?.value || '',
                field_phone: selected('field_phone')?.value || '',
                field_address: Array.from(
                    document.querySelectorAll('[data-address-field]:checked')
                ).map(node => node.value)
            };
        },

        statusLabel(status) {
            return {
                active: '公開中',
                draft: '下書き',
                ended: '終了'
            }[status] || status;
        },

        statusClass(status) {
            return {
                active: 'bg-emerald-100 text-emerald-700',
                draft: 'bg-amber-100 text-amber-700',
                ended: 'bg-slate-200 text-slate-600'
            }[status] || 'bg-slate-100';
        },

        questionCount(survey) {
            return (survey.groups || []).reduce(
                (sum, group) => sum + (group.questions || []).length,
                0
            );
        },

        responseCount(id) {
            return App.state.data.responses.filter(
                response => response.survey_id === id
            ).length;
        },

        previewHtml(survey) {
            let html = '<div class="space-y-5">';
            html += '<h2 class="text-2xl font-bold">' + App.helpers.esc(survey.title) + '</h2>';

            let number = 0;

            survey.groups.forEach(function (group) {
                html += '<section class="border-t pt-4">';
                html += '<h3 class="font-bold mb-3">' + App.helpers.esc(group.name) + '</h3>';

                group.questions.forEach(function (question) {
                    number++;
                    html += '<div class="mb-4">';
                    html += '<label class="block font-semibold mb-2">Q' +
                        number + ' ' + App.helpers.esc(question.text) + '</label>';

                    if (question.type === 'text') {
                        html += '<textarea class="w-full border rounded-lg p-2" disabled></textarea>';
                    } else {
                        question.options.forEach(function (option) {
                            html += '<label class="block mb-1">';
                            html += '<input disabled type="' +
                                (question.type === 'multiple' ? 'checkbox' : 'radio') +
                                '"> ' + App.helpers.esc(option);
                            html += '</label>';
                        });
                    }

                    html += '</div>';
                });

                html += '</section>';
            });

            return html + '</div>';
        },

        responseHtml(response) {
            let html = '<div class="space-y-3">';
            html += '<p><b>会社名:</b> ' + App.helpers.esc(response.company) + '</p>';
            html += '<p><b>氏名:</b> ' + App.helpers.esc(response.name) + '</p>';
            html += '<p><b>メール:</b> ' + App.helpers.esc(response.email) + '</p>';
            html += '<p><b>回答日時:</b> ' + App.helpers.esc(response.answered_at) + '</p>';
            html += '<hr>';

            Object.keys(response.answers || {}).forEach(function (key) {
                const answer = Array.isArray(response.answers[key])
                    ? response.answers[key].join('、')
                    : response.answers[key];

                html += '<p><b>' + App.helpers.esc(key) + ':</b> ' +
                    App.helpers.esc(answer) + '</p>';
            });

            return html + '</div>';
        }
    },

    render: {
        shell(content) {
            document.getElementById('app').innerHTML = `
<header class="bg-white border-b">
<div class="max-w-7xl mx-auto px-6 py-4 flex justify-between items-center">
<div class="font-bold text-xl">アンケート管理システム</div>
<nav class="flex gap-2">
<button onclick="App.render.list()" class="px-3 py-2 rounded hover:bg-slate-100">アンケート一覧</button>
<button onclick="App.actions.showSettings()" class="px-3 py-2 rounded hover:bg-slate-100">kintone連携設定</button>
</nav>
</div>
</header>
<main class="max-w-7xl mx-auto p-6">${content}</main>
<div id="preview_modal" class="hidden fixed inset-0 bg-black/40 p-6 z-20">
<div class="bg-white max-w-3xl mx-auto rounded-2xl p-6 max-h-[90vh] overflow-auto">
<div class="flex justify-between mb-4">
<h2 class="font-bold text-xl">プレビュー</h2>
<button onclick="App.actions.closeModal('preview_modal')">閉じる</button>
</div>
<div id="preview_content"></div>
</div>
</div>
<div id="response_modal" class="hidden fixed inset-0 bg-black/40 p-6 z-20">
<div class="bg-white max-w-2xl mx-auto rounded-2xl p-6 max-h-[90vh] overflow-auto">
<div class="flex justify-between mb-4">
<h2 class="font-bold text-xl">回答詳細</h2>
<button onclick="App.actions.closeModal('response_modal')">閉じる</button>
</div>
<div id="response_detail"></div>
</div>
</div>`;
        },

        error(message) {
            App.render.shell(`
<div class="bg-white rounded-2xl shadow p-6">
<h1 class="text-xl font-bold text-red-600">エラー</h1>
<pre class="mt-4 whitespace-pre-wrap">${App.helpers.esc(message)}</pre>
</div>`);
        },

        list() {
            const state = App.state;
            let surveys = (state.data?.surveys || []).filter(item => !item.deleted);

            surveys = surveys.filter(function (survey) {
                const keywordOk = !state.keyword ||
                    survey.title.toLowerCase().includes(state.keyword.toLowerCase());
                const statusOk = state.status_filter === 'all' ||
                    survey.status === state.status_filter;
                return keywordOk && statusOk;
            });

            surveys.sort(function (a, b) {
                if (state.sort === 'responses_desc') {
                    return App.helpers.responseCount(b.id) - App.helpers.responseCount(a.id);
                }
                if (state.sort === 'responses_asc') {
                    return App.helpers.responseCount(a.id) - App.helpers.responseCount(b.id);
                }
                return state.sort === 'updated_asc'
                    ? a.updated_at.localeCompare(b.updated_at)
                    : b.updated_at.localeCompare(a.updated_at);
            });

            const rows = surveys.map(function (survey) {
                const count = App.helpers.responseCount(survey.id);
                let buttons = `
<button onclick="App.actions.editSurvey('${survey.id}')" class="text-indigo-600">確認・編集</button>
<button onclick="App.actions.duplicateSurvey('${survey.id}')" class="text-slate-600">複製</button>`;

                if (survey.status === 'active') {
                    buttons += `
<button onclick="App.actions.showAggregate('${survey.id}')" class="text-indigo-600">集計</button>
<button onclick="App.actions.showSend('${survey.id}')" class="text-indigo-600">送信</button>
<button onclick="App.actions.toggleStatus('${survey.id}')" class="text-rose-600">停止</button>`;
                }

                if (survey.status === 'draft') {
                    buttons += `
<button onclick="App.actions.deleteSurvey('${survey.id}')" class="text-rose-600">削除</button>`;
                }

                if (survey.status === 'ended') {
                    buttons += `
<button onclick="App.actions.showAggregate('${survey.id}')" class="text-indigo-600">集計</button>`;
                }

                return `
<tr class="border-t">
<td class="p-3">${App.helpers.esc(survey.updated_at.slice(0, 10))}</td>
<td class="p-3 font-bold">${App.helpers.esc(survey.title)}</td>
<td class="p-3">${App.helpers.esc(survey.start_at || '未設定')} ～ ${App.helpers.esc(survey.end_at || '未設定')}</td>
<td class="p-3"><span class="px-2 py-1 rounded ${App.helpers.statusClass(survey.status)}">${App.helpers.statusLabel(survey.status)}</span></td>
<td class="p-3">${count} 件</td>
<td class="p-3"><div class="flex flex-wrap gap-2 text-sm">${buttons}</div></td>
</tr>`;
            }).join('');

            App.render.shell(`
<div class="flex justify-between items-center mb-6">
<h1 class="text-2xl font-bold">アンケート一覧</h1>
<button onclick="App.actions.newSurvey()" class="bg-indigo-600 text-white px-4 py-2 rounded-lg">＋ 新規アンケート作成</button>
</div>
<div class="bg-white rounded-2xl shadow p-4 mb-5 flex gap-3 flex-wrap">
<input id="list_keyword" value="${App.helpers.esc(state.keyword)}" onkeydown="if(event.key==='Enter')App.actions.filterList()" placeholder="タイトル検索" class="border rounded-lg p-2">
<select id="list_status" onchange="App.actions.filterList()" class="border rounded-lg p-2">
<option value="all">すべて</option>
<option value="active" ${state.status_filter === 'active' ? 'selected' : ''}>公開中</option>
<option value="draft" ${state.status_filter === 'draft' ? 'selected' : ''}>下書き</option>
<option value="ended" ${state.status_filter === 'ended' ? 'selected' : ''}>終了</option>
</select>
<select id="list_sort" onchange="App.actions.filterList()" class="border rounded-lg p-2">
<option value="updated_desc">更新日（新しい順）</option>
<option value="updated_asc">更新日（古い順）</option>
<option value="responses_desc">回答数（多い順）</option>
<option value="responses_asc">回答数（少ない順）</option>
</select>
</div>
<div class="bg-white rounded-2xl shadow overflow-auto">
<table class="w-full text-left min-w-[900px]">
<thead class="bg-slate-50"><tr>
<th class="p-3">更新日</th><th class="p-3">タイトル</th><th class="p-3">期間</th>
<th class="p-3">ステータス</th><th class="p-3">回答数</th><th class="p-3">操作</th>
</tr></thead>
<tbody>${rows || '<tr><td colspan="6" class="p-8 text-center text-slate-500">アンケートがありません。</td></tr>'}</tbody>
</table>
</div>`);
        },

        edit() {
            const survey = App.state.editing;
            const groups = survey.groups.map(function (group, groupIndex) {
                const questions = group.questions.map(function (question, questionIndex) {
                    const options = question.type === 'text'
                        ? ''
                        : question.options.map(function (option, index) {
                            return `
<div class="flex gap-2 mb-2">
<input data-option data-group-id="${group.id}" data-question-id="${question.id}" data-index="${index}" value="${App.helpers.esc(option)}" class="border rounded p-2 flex-1">
<button onclick="App.actions.removeOption('${group.id}','${question.id}',${index})" class="text-rose-600">削除</button>
</div>`;
                        }).join('');

                    return `
<div class="question-item border rounded-xl p-4 mb-3 bg-slate-50" data-question-id="${question.id}">
<div class="flex gap-3 items-start">
<div class="text-slate-400 text-xl cursor-move">⠿</div>
<div class="flex-1">
<div class="flex gap-2 mb-2">
<span class="font-bold">Q${questionIndex + 1}</span>
<input data-question-text data-group-id="${group.id}" data-question-text="${question.id}" value="${App.helpers.esc(question.text)}" class="border rounded p-2 flex-1">
</div>
<div class="flex gap-3 items-center mb-3">
<select onchange="App.actions.changeType('${group.id}','${question.id}',this.value)" class="border rounded p-2">
<option value="single" ${question.type === 'single' ? 'selected' : ''}>単一選択</option>
<option value="multiple" ${question.type === 'multiple' ? 'selected' : ''}>複数選択</option>
<option value="text" ${question.type === 'text' ? 'selected' : ''}>自由記述</option>
</select>
<label><input data-required data-group-id="${group.id}" data-required="${question.id}" type="checkbox" ${question.required ? 'checked' : ''}> 必須</label>
<button onclick="App.actions.removeQuestion('${group.id}','${question.id}')" class="text-rose-600 ml-auto">質問削除</button>
</div>
${options}
${question.type !== 'text' ? `<button onclick="App.actions.addOption('${group.id}','${question.id}')" class="text-indigo-600 text-sm">＋ 選択肢追加</button>` : ''}
</div>
</div>
</div>`;
                }).join('');

                return `
<section class="group-item border rounded-2xl p-4 mb-5 bg-white" data-group-id="${group.id}">
<div class="flex gap-3 items-center mb-4">
<div class="text-slate-400 text-xl cursor-move">⠿</div>
<input data-group-name="${group.id}" value="${App.helpers.esc(group.name)}" class="border rounded p-2 font-bold flex-1">
<button onclick="App.actions.removeGroup('${group.id}')" class="text-rose-600">グループ削除</button>
</div>
<div class="question-list">${questions}</div>
<button onclick="App.actions.addQuestion('${group.id}')" class="text-indigo-600">＋ 質問追加</button>
</section>`;
            }).join('');

            App.render.shell(`
<div class="flex justify-between items-center mb-6">
<h1 class="text-2xl font-bold">アンケート作成・編集</h1>
<div class="flex gap-2">
<button onclick="App.actions.openPreview()" class="border px-4 py-2 rounded-lg">プレビュー</button>
<button onclick="App.actions.saveSurvey()" class="bg-indigo-600 text-white px-4 py-2 rounded-lg">保存して一覧へ戻る</button>
<button onclick="App.actions.cancelEdit()" class="border px-4 py-2 rounded-lg">キャンセル</button>
</div>
</div>
<div class="bg-white rounded-2xl shadow p-5 mb-5 grid md:grid-cols-4 gap-3">
<input id="survey_title" value="${App.helpers.esc(survey.title)}" class="border rounded-lg p-2 md:col-span-2" placeholder="タイトル">
<input id="survey_start_at" value="${App.helpers.esc(survey.start_at)}" type="datetime-local" class="border rounded-lg p-2">
<input id="survey_end_at" value="${App.helpers.esc(survey.end_at)}" type="datetime-local" class="border rounded-lg p-2">
<select id="survey_numbering_mode" class="border rounded-lg p-2">
<option value="global" ${survey.numbering_mode === 'global' ? 'selected' : ''}>質問番号：全体</option>
<option value="group" ${survey.numbering_mode === 'group' ? 'selected' : ''}>質問番号：グループ別</option>
</select>
</div>
<div id="question_editor">${groups}</div>
<button onclick="App.actions.addGroup()" class="bg-slate-800 text-white px-4 py-2 rounded-lg">＋ グループ追加</button>`);

            document.querySelectorAll('.question-list').forEach(function (node) {
                new Sortable(node, {
                    group: 'survey_questions',
                    animation: 150,
                    handle: '.cursor-move',
                    onEnd: function () {
                        const surveyData = App.helpers.readSurveyForm();
                        App.state.editing = surveyData;
                        App.render.edit();
                    }
                });
            });

            const groupEditor = document.getElementById('question_editor');
            if (groupEditor) {
                new Sortable(groupEditor, {
                    animation: 150,
                    handle: '.group-item > .flex .cursor-move',
                    onEnd: function () {
                        App.state.editing = App.helpers.readSurveyForm();
                        App.render.edit();
                    }
                });
            }
        },

        fieldSelectors() {
            const settings = App.state.data.settings || {};
            const properties = App.state.fields || [];

            const select = function (id, current, multiple = false) {
                const items = properties.map(function (field) {
                    const label = field.label || field.code || '';
                    const code = field.code || '';
                    const selected = multiple
                        ? (current || []).includes(code)
                        : current === code;

                    return `<option value="${App.helpers.esc(code)}" ${selected ? 'selected' : ''}>${App.helpers.esc(label)}（${App.helpers.esc(code)}）</option>`;
                }).join('');

                return `<select id="${id}" ${multiple ? 'multiple' : ''} class="border rounded-lg p-2 w-full">${items}</select>`;
            };

            document.getElementById('field_company').outerHTML =
                select('field_company', settings.field_company);
            document.getElementById('field_name').outerHTML =
                select('field_name', settings.field_name);
            document.getElementById('field_email').outerHTML =
                select('field_email', settings.field_email);
            document.getElementById('field_department').outerHTML =
                select('field_department', settings.field_department);
            document.getElementById('field_phone').outerHTML =
                select('field_phone', settings.field_phone);
            document.getElementById('field_address').outerHTML =
                select('field_address', settings.field_address, true);
        },

        settings() {
            App.render.shell(`
<h1 class="text-2xl font-bold mb-6">kintone連携設定</h1>
<div class="bg-white rounded-2xl shadow p-6 max-w-3xl">
<div class="grid gap-4">
<label>サブドメイン<input id="setting_subdomain" placeholder="xxxx.cybozu.com" class="border rounded-lg p-2 w-full"></label>
<label>アプリID<input id="setting_app_id" class="border rounded-lg p-2 w-full"></label>
<label>ログイン名<input id="setting_login_name" class="border rounded-lg p-2 w-full"></label>
<label>パスワード<input id="setting_password" type="password" autocomplete="new-password" class="border rounded-lg p-2 w-full"></label>
<label>Proxy<input id="setting_proxy" placeholder="host:port" class="border rounded-lg p-2 w-full"></label>
<label><input id="setting_ssl_verify" type="checkbox" checked> SSL証明書を検証する</label>
</div>
<div class="flex gap-2 mt-5">
<button onclick="App.actions.testKintone()" class="border px-4 py-2 rounded-lg">接続確認</button>
<button onclick="App.actions.fetchKintoneFields()" class="border px-4 py-2 rounded-lg">項目一覧を再取得</button>
<button onclick="App.actions.saveSettings()" class="bg-indigo-600 text-white px-4 py-2 rounded-lg">保存</button>
</div>
<p id="field_message" class="mt-4 whitespace-pre-wrap text-sm"></p>
<hr class="my-6">
<h2 class="font-bold mb-4">フィールドマッピング</h2>
<div class="grid gap-4">
<label>会社名<select id="field_company" class="border rounded-lg p-2 w-full"></select></label>
<label>氏名<select id="field_name" class="border rounded-lg p-2 w-full"></select></label>
<label>メールアドレス<select id="field_email" class="border rounded-lg p-2 w-full"></select></label>
<label>部署名<select id="field_department" class="border rounded-lg p-2 w-full"></select></label>
<label>電話番号<select id="field_phone" class="border rounded-lg p-2 w-full"></select></label>
<label>住所<select id="field_address" multiple class="border rounded-lg p-2 w-full"></select></label>
</div>
</div>`);

            App.helpers.writeSettingsForm();
        },

        aggregate() {
            const survey = App.state.data.surveys.find(
                item => item.id === App.state.selectedSurvey
            );

            const responses = App.state.data.responses.filter(
                item => item.survey_id === App.state.selectedSurvey
            );

            const questionList = [];
            (survey?.groups || []).forEach(group => {
                (group.questions || []).forEach(question => questionList.push(question));
            });

            const charts = questionList.map(function (question) {
                if (question.type === 'text') {
                    const texts = responses.map(item => item.answers?.[question.id]).filter(Boolean);
                    return `
<div class="border rounded-xl p-4">
<h3 class="font-bold">${App.helpers.esc(question.text)}</h3>
<div class="mt-3 space-y-2">${texts.map(text => `<p class="bg-slate-50 p-2 rounded">${App.helpers.esc(text)}</p>`).join('') || '回答なし'}</div>
</div>`;
                }

                const counts = {};
                question.options.forEach(option => counts[option] = 0);

                responses.forEach(function (response) {
                    const answer = response.answers?.[question.id];
                    const values = Array.isArray(answer) ? answer : [answer];
                    values.forEach(value => {
                        if (value && counts[value] !== undefined) counts[value]++;
                    });
                });

                return `
<div class="border rounded-xl p-4">
<h3 class="font-bold mb-3">${App.helpers.esc(question.text)}</h3>
${Object.entries(counts).map(([label, count]) => `
<div class="mb-3">
<div class="flex justify-between text-sm"><span>${App.helpers.esc(label)}</span><span>${count}件</span></div>
<div class="bg-slate-200 rounded h-3"><div class="bg-indigo-500 h-3 rounded" style="width:${responses.length ? (count / responses.length * 100) : 0}%"></div></div>
</div>`).join('')}
</div>`;
            }).join('');

            const responseRows = responses.map(function (response) {
                return `<tr class="border-t">
<td class="p-3">${App.helpers.esc(response.company)}</td>
<td class="p-3">${App.helpers.esc(response.name)}</td>
<td class="p-3">${App.helpers.esc(response.answered_at)}</td>
<td class="p-3"><button onclick="App.actions.openResponse('${response.id}')" class="text-indigo-600">全回答を表示</button></td>
</tr>`;
            }).join('');

            App.render.shell(`
<div class="flex justify-between mb-6">
<h1 class="text-2xl font-bold">${App.helpers.esc(survey?.title || '')}：集計</h1>
<a href="?action=csv&survey_id=${encodeURIComponent(App.state.selectedSurvey)}" class="bg-indigo-600 text-white px-4 py-2 rounded-lg">CSV出力</a>
</div>
<div class="grid md:grid-cols-4 gap-4 mb-6">
<div class="bg-white rounded-xl shadow p-4"><div class="text-sm text-slate-500">回答数</div><div class="text-2xl font-bold">${responses.length} 件</div></div>
<div class="bg-white rounded-xl shadow p-4"><div class="text-sm text-slate-500">設問数</div><div class="text-2xl font-bold">${questionList.length} 問</div></div>
<div class="bg-white rounded-xl shadow p-4"><div class="text-sm text-slate-500">回答率</div><div class="text-2xl font-bold">-</div></div>
<div class="bg-white rounded-xl shadow p-4"><div class="text-sm text-slate-500">未登録回答</div><div class="text-2xl font-bold">${responses.filter(item => !item.customer_id).length} 件</div></div>
</div>
<div class="grid gap-4 mb-6">${charts || '<div class="bg-white p-8 rounded-xl text-center">現在、回答データはありません</div>'}</div>
<div class="bg-white rounded-2xl shadow overflow-auto">
<table class="w-full text-left">
<thead class="bg-slate-50"><tr><th class="p-3">会社名</th><th class="p-3">氏名</th><th class="p-3">回答日時</th><th class="p-3">詳細</th></tr></thead>
<tbody>${responseRows || '<tr><td colspan="4" class="p-6 text-center">回答なし</td></tr>'}</tbody>
</table>
</div>`);
        },

        send() {
            const survey = App.state.data.surveys.find(
                item => item.id === App.state.selectedSurvey
            );

            const customers = App.state.data.customers || [];

            App.render.shell(`
<h1 class="text-2xl font-bold mb-6">${App.helpers.esc(survey?.title || '')}：送信</h1>
<div class="bg-white rounded-2xl shadow p-6">
<div class="grid gap-4">
<input id="mail_subject" placeholder="件名" class="border rounded-lg p-2">
<textarea id="mail_body" rows="7" placeholder="{顧客名} 様&#10;アンケートURL: {アンケートURL}" class="border rounded-lg p-2"></textarea>
<select id="template_type" class="border rounded-lg p-2">
<option value="initial">初回</option>
<option value="reminder">リマインド</option>
</select>
</div>
<div class="mt-6 overflow-auto">
<table class="w-full text-left">
<thead class="bg-slate-50"><tr><th class="p-3">選択</th><th class="p-3">会社名</th><th class="p-3">氏名</th><th class="p-3">メール</th></tr></thead>
<tbody>${customers.map(customer => `
<tr class="border-t">
<td class="p-3"><input type="checkbox" data-recipient="${App.helpers.esc(customer.id)}"></td>
<td class="p-3">${App.helpers.esc(customer.company)}</td>
<td class="p-3">${App.helpers.esc(customer.name)}</td>
<td class="p-3">${App.helpers.esc(customer.email)}</td>
</tr>`).join('') || '<tr><td colspan="4" class="p-6 text-center">顧客データがありません。</td></tr>'}</tbody>
</table>
</div>
<button onclick="alert('メール送信処理はメールサーバー設定後に実行できます。')" class="mt-5 bg-indigo-600 text-white px-4 py-2 rounded-lg">一括送信実行</button>
</div>`);
        }
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => App.actions.init(), {once: true});
} else {
    App.actions.init();
}
</script>
</body>
</html>

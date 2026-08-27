<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 * Single Entry Point / PHP 8.5 / Apache 2.4 / No DB
 *
 * prompt.txt に基づく再生成版
 *
 * 重要:
 * - 関数はこのファイル内で一度だけ定義
 * - curl_close() は使用しない
 * - CSRF処理は実装しない（要件禁止）
 * - kintone接続テストと同期を分離
 * - 認証情報はブラウザへ渡さない
 */

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

/* =========================================================
 * 1. セッション
 * ========================================================= */

$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') ?: '/',
        'secure'   => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* =========================================================
 * 2. 保存先
 * ========================================================= */

define('APP_DATA_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'data');
define('APP_SURVEYS_FILE', APP_DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json');
define('APP_CUSTOMERS_FILE', APP_DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json');
define('APP_SETTINGS_FILE', APP_DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json');
define('APP_ANSWERS_FILE', APP_DATA_DIR . DIRECTORY_SEPARATOR . 'answers.json');
define('APP_SEND_LOG_FILE', APP_DATA_DIR . DIRECTORY_SEPARATOR . 'send_logs.json');

if (!is_dir(APP_DATA_DIR)) {
    @mkdir(APP_DATA_DIR, 0775, true);
}

/* =========================================================
 * 3. 共通関数
 * ========================================================= */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_read_file(string $file, mixed $default): mixed
{
    if (!is_file($file)) {
        return $default;
    }

    $json = @file_get_contents($file);
    if ($json === false || trim($json) === '') {
        return $default;
    }

    $data = json_decode($json, true);

    return json_last_error() === JSON_ERROR_NONE ? $data : $default;
}

function json_write_file(string $file, mixed $data): bool
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        return false;
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function now_iso(): string
{
    return date('c');
}

function new_id(string $prefix): string
{
    return $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
}

function redirect_screen(string $screen, array $params = []): never
{
    $params = array_merge(['screen' => $screen], $params);
    $query = http_build_query($params);

    header('Location: ' . basename($_SERVER['SCRIPT_NAME']) . '?' . $query);
    exit;
}

function normalize_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    ) ?? $value;

    $value = preg_replace(
        '#/.*$#',
        '',
        $value
    ) ?? $value;

    $value = preg_replace(
        '#\.cybozu\.com$#i',
        '',
        $value
    ) ?? $value;

    return trim($value, " \t\n\r\0\x0B/");
}

/**
 * kintone URL生成
 *
 * 以下の入力をすべて許容:
 *   https://xxxx.cybozu.com
 *   xxxx.cybozu.com
 *   xxxx
 *
 * xxxx.cybozu.com.cybozu.com のような二重修飾は絶対に作らない。
 */
function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = normalize_subdomain($domain);

    if ($domain === '') {
        throw new RuntimeException('kintoneサブドメインが未設定です。');
    }

    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $domain)) {
        throw new RuntimeException(
            'kintoneサブドメインの形式が正しくありません。'
        );
    }

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

function get_settings(): array
{
    $default = [
        'kintone' => [
            'subdomain' => '',
            'app_id' => '',
            'username' => '',
            'password' => '',
            'proxy' => '',
            'verify_ssl' => false,
            'last_test' => null,
            'last_sync' => null,
        ],
        'mail' => [
            'server' => '',
            'port' => '587',
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '',
            'reply_to' => '',
        ],
    ];

    $data = json_read_file(APP_SETTINGS_FILE, []);

    if (!is_array($data)) {
        return $default;
    }

    return array_replace_recursive($default, $data);
}

function save_settings(array $settings): bool
{
    return json_write_file(APP_SETTINGS_FILE, $settings);
}

function get_surveys(): array
{
    $data = json_read_file(APP_SURVEYS_FILE, []);

    return is_array($data) ? array_values($data) : [];
}

function save_surveys(array $surveys): bool
{
    return json_write_file(APP_SURVEYS_FILE, array_values($surveys));
}

function get_customers(): array
{
    $data = json_read_file(APP_CUSTOMERS_FILE, []);

    return is_array($data) ? array_values($data) : [];
}

function save_customers(array $customers): bool
{
    return json_write_file(APP_CUSTOMERS_FILE, array_values($customers));
}

function get_answers(): array
{
    $data = json_read_file(APP_ANSWERS_FILE, []);

    return is_array($data) ? array_values($data) : [];
}

function save_answers(array $answers): bool
{
    return json_write_file(APP_ANSWERS_FILE, array_values($answers));
}

function get_send_logs(): array
{
    $data = json_read_file(APP_SEND_LOG_FILE, []);

    return is_array($data) ? array_values($data) : [];
}

function save_send_logs(array $logs): bool
{
    return json_write_file(APP_SEND_LOG_FILE, array_values($logs));
}

function find_survey(string $id): ?array
{
    foreach (get_surveys() as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function normalize_survey_status(array $survey): array
{
    if (
        ($survey['status'] ?? '') === 'published' &&
        !empty($survey['endAt'])
    ) {
        $end = strtotime((string)$survey['endAt']);

        if ($end !== false && $end < time()) {
            $survey['status'] = 'ended';
        }
    }

    return $survey;
}

function status_label(string $status): string
{
    return match ($status) {
        'published' => '公開中',
        'stopped'  => '停止',
        'ended'    => '終了',
        default    => '下書き',
    };
}

function status_class(string $status): string
{
    return match ($status) {
        'published' => 'success',
        'stopped'  => 'warning',
        'ended'    => 'danger',
        default    => 'muted',
    };
}

function question_number(array $groups, string $mode): array
{
    $numbers = [];
    $global = 0;

    foreach ($groups as $gi => $group) {
        $local = 0;

        foreach (($group['questions'] ?? []) as $qi => $question) {
            $global++;
            $local++;

            $id = (string)($question['id'] ?? '');

            if ($mode === 'group') {
                $numbers[$id] = 'Q' . ($gi + 1) . '-' . $local;
            } else {
                $numbers[$id] = 'Q' . $global;
            }
        }
    }

    return $numbers;
}

function default_survey(): array
{
    return [
        'id' => new_id('survey'),
        'title' => '',
        'description' => '',
        'startAt' => '',
        'endAt' => '',
        'status' => 'draft',
        'numbering' => 'global',
        'createdAt' => now_iso(),
        'updatedAt' => now_iso(),
        'groups' => [
            [
                'id' => new_id('group'),
                'title' => '基本情報',
                'questions' => [
                    [
                        'id' => new_id('question'),
                        'text' => '',
                        'type' => 'single',
                        'required' => true,
                        'options' => ['はい', 'いいえ'],
                        'branches' => [],
                    ],
                ],
            ],
        ],
    ];
}

/* =========================================================
 * 4. kintone通信
 * ========================================================= */

/**
 * kintone REST APIへ一回だけ接続する。
 *
 * リトライは行わない。
 * curl_close() はPHP 8.5では使用しない。
 */
function kintone_request(
    array $config,
    string $endpoint,
    string $method = 'GET',
    ?array $body = null
): array {
    $subdomain = normalize_subdomain(
        (string)($config['subdomain'] ?? '')
    );

    $appId = trim((string)($config['app_id'] ?? ''));
    $username = (string)($config['username'] ?? '');
    $password = (string)($config['password'] ?? '');

    if ($subdomain === '') {
        throw new RuntimeException(
            '設定エラー：kintoneサブドメインが入力されていません。'
        );
    }

    if ($appId === '' || !ctype_digit($appId)) {
        throw new RuntimeException(
            '設定エラー：顧客管理アプリIDには数字のIDを入力してください。'
        );
    }

    if ($username === '') {
        throw new RuntimeException(
            '設定エラー：kintoneログイン名が入力されていません。'
        );
    }

    if ($password === '') {
        throw new RuntimeException(
            '設定エラー：kintoneパスワードが入力されていません。'
        );
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'システムエラー：PHP cURL拡張が利用できません。'
        );
    }

    $url = kintone_build_url($subdomain, $endpoint);

    $ch = curl_init();

    if ($ch === false) {
        throw new RuntimeException(
            'システムエラー：cURLの初期化に失敗しました。'
        );
    }

    $auth = base64_encode($username . ':' . $password);

    $headers = [
        'Accept: application/json',
        'X-Cybozu-Authorization: ' . $auth,
    ];

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if (!empty($config['proxy'])) {
        $proxy = trim((string)$config['proxy']);

        if (!preg_match('/^[^:\s]+:\d+$/', $proxy)) {
            throw new RuntimeException(
                '設定エラー：Proxyは「host:port」形式で入力してください。'
            );
        }

        $options[CURLOPT_PROXY] = $proxy;
    }

    $verifySsl = (bool)($config['verify_ssl'] ?? false);

    $options[CURLOPT_SSL_VERIFYPEER] = $verifySsl;
    $options[CURLOPT_SSL_VERIFYHOST] = $verifySsl ? 2 : 0;

    if ($body !== null) {
        $payload = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($payload === false) {
            throw new RuntimeException(
                '通信データのJSON化に失敗しました。'
            );
        }

        $options[CURLOPT_POSTFIELDS] = $payload;
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
    }

    curl_setopt_array($ch, $options);

    $started = microtime(true);

    $response = curl_exec($ch);

    $elapsed = microtime(true) - $started;

    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    /*
     * curl_close() は呼ばない。
     * PHP 8.0以降では実質的に不要であり、
     * PHP 8.5ではdeprecated。
     */

    if ($response === false) {
        throw new RuntimeException(
            '通信エラー：kintoneへ接続できませんでした。'
            . ' cURLエラー #' . $errno
            . ' / ' . ($error !== '' ? $error : '詳細不明')
        );
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        $decoded = [];
    }

    return [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'body' => $decoded,
        'raw' => $response,
        'elapsed_ms' => round($elapsed * 1000, 1),
    ];
}

function kintone_error_message(array $result): string
{
    $body = $result['body'] ?? [];

    $message = '';

    if (is_array($body)) {
        $message = trim((string)($body['message'] ?? ''));

        if ($message === '') {
            $message = trim((string)($body['error'] ?? ''));
        }

        if ($message === '') {
            $message = trim((string)($body['errors'] ?? ''));
        }
    }

    if ($message === '') {
        $message = match ((int)($result['http_code'] ?? 0)) {
            400 => '不正なリクエストです。アプリID、API URL、認証情報、権限を確認してください。',
            401 => '認証に失敗しました。ログイン名またはパスワードを確認してください。',
            403 => 'アクセス権限がありません。kintoneアプリの権限を確認してください。',
            404 => 'kintoneのアプリまたはAPI URLが見つかりません。',
            408 => 'kintoneへの接続がタイムアウトしました。',
            default => 'kintone APIからエラーが返されました。',
        };
    }

    return $message;
}

function kintone_test(array $config): array
{
    /*
     * 接続テストは app.json を使用。
     * 顧客同期とは完全に独立した操作。
     */
    $appId = trim((string)($config['app_id'] ?? ''));

    if ($appId === '' || !ctype_digit($appId)) {
        return [
            'ok' => false,
            'title' => '接続テスト失敗',
            'message' => '顧客管理アプリIDが未入力、または数字ではありません。',
            'detail' => '顧客管理アプリIDにkintoneのアプリIDを入力してください。',
        ];
    }

    try {
        $result = kintone_request(
            $config,
            '/k/v1/app.json?id=' . rawurlencode($appId)
        );

        if ($result['ok']) {
            $name = (string)($result['body']['name'] ?? '');

            return [
                'ok' => true,
                'title' => '接続成功',
                'message' => 'kintoneへの接続に成功しました。',
                'detail' =>
                    '接続先：'
                    . normalize_subdomain((string)$config['subdomain'])
                    . '.cybozu.com'
                    . ' / アプリID：'
                    . $appId
                    . ($name !== '' ? ' / アプリ名：' . $name : '')
                    . ' / 応答時間：'
                    . $result['elapsed_ms']
                    . 'ms',
                'http_code' => $result['http_code'],
            ];
        }

        return [
            'ok' => false,
            'title' => '接続テスト失敗',
            'message' => kintone_error_message($result),
            'detail' =>
                'HTTP ' . $result['http_code']
                . '。'
                . 'サブドメイン、アプリID、ログイン名、パスワード、'
                . 'アプリ権限、Proxy、SSL設定を確認してください。',
            'http_code' => $result['http_code'],
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'title' => '接続テスト失敗',
            'message' => $e->getMessage(),
            'detail' =>
                '入力値とネットワーク設定を確認してから、'
                . 'もう一度「接続テスト」を実行してください。',
        ];
    }
}

function kintone_fetch_customers(array $config): array
{
    $customers = [];
    $offset = 0;
    $limit = 500;

    while (true) {
        $endpoint =
            '/k/v1/records.json'
            . '?app=' . rawurlencode((string)$config['app_id'])
            . '&query=' . rawurlencode('limit ' . $limit . ' offset ' . $offset);

        $result = kintone_request($config, $endpoint);

        if (!$result['ok']) {
            throw new RuntimeException(
                '顧客情報の取得に失敗しました。'
                . ' HTTP ' . $result['http_code']
                . ' / ' . kintone_error_message($result)
            );
        }

        $records = $result['body']['records'] ?? [];

        if (!is_array($records)) {
            $records = [];
        }

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $customers[] = [
                'id' => (string)($record['$id']['value'] ?? ''),
                'organization' => (string)(
                    $record['organization']['value']
                    ?? $record['組織名']['value']
                    ?? ''
                ),
                'name' => (string)(
                    $record['name']['value']
                    ?? $record['氏名']['value']
                    ?? ''
                ),
                'email' => (string)(
                    $record['email']['value']
                    ?? $record['メールアドレス']['value']
                    ?? ''
                ),
                'department' => (string)(
                    $record['department']['value']
                    ?? $record['部署名']['value']
                    ?? ''
                ),
                'phone' => (string)(
                    $record['phone']['value']
                    ?? $record['電話番号']['value']
                    ?? ''
                ),
                'address' => (string)(
                    $record['address']['value']
                    ?? $record['住所']['value']
                    ?? ''
                ),
                'raw' => $record,
                'updatedAt' => now_iso(),
            ];
        }

        if (count($records) < $limit) {
            break;
        }

        $offset += $limit;

        if ($offset >= 10000) {
            break;
        }
    }

    return $customers;
}

/* =========================================================
 * 5. HTML共通部品
 * ========================================================= */

function page_header(
    string $title,
    string $screen = 'list',
    bool $admin = true
): void {
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> - アンケート管理</title>
<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --success:#16a34a;
    --warning:#d97706;
    --danger:#dc2626;
    --gray:#64748b;
    --gray-light:#f1f5f9;
    --border:#dbe2ea;
    --text:#1e293b;
    --white:#fff;
    --shadow:0 4px 18px rgba(15,23,42,.08);
    --radius:12px;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
    background:#f8fafc;
    color:var(--text);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
        "Noto Sans JP","Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
    line-height:1.6;
}
a{color:var(--primary);text-decoration:none}
button,input,select,textarea{font:inherit}
button{cursor:pointer}
.admin-header{
    background:#0f172a;
    color:#fff;
    min-height:64px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 24px;
}
.admin-header .brand{
    font-size:20px;
    font-weight:700;
}
.admin-nav{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}
.admin-nav a{
    color:#cbd5e1;
    padding:8px 12px;
    border-radius:8px;
}
.admin-nav a:hover,
.admin-nav a.active{
    background:#1e293b;
    color:#fff;
}
.container{
    max-width:1280px;
    margin:0 auto;
    padding:28px 20px 60px;
}
.page-title{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
    margin-bottom:20px;
}
.page-title h1{
    margin:0;
    font-size:28px;
}
.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:22px;
    margin-bottom:20px;
}
.grid{
    display:grid;
    grid-template-columns:repeat(12,1fr);
    gap:18px;
}
.col-12{grid-column:span 12}
.col-8{grid-column:span 8}
.col-6{grid-column:span 6}
.col-4{grid-column:span 4}
.form-row{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:16px;
}
.field{margin-bottom:16px}
.field label{
    display:block;
    font-weight:700;
    margin-bottom:6px;
}
input[type=text],
input[type=password],
input[type=email],
input[type=number],
input[type=datetime-local],
select,
textarea{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:10px 12px;
    background:#fff;
    color:var(--text);
}
textarea{min-height:120px;resize:vertical}
input:focus,select:focus,textarea:focus{
    outline:3px solid rgba(37,99,235,.12);
    border-color:var(--primary);
}
.help{
    color:var(--gray);
    font-size:13px;
    margin-top:5px;
}
.actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    align-items:center;
}
.btn{
    display:inline-flex;
    justify-content:center;
    align-items:center;
    min-height:40px;
    padding:8px 14px;
    border:1px solid transparent;
    border-radius:8px;
    font-weight:700;
}
.btn-primary{background:var(--primary);color:#fff}
.btn-primary:hover{background:var(--primary-dark)}
.btn-secondary{background:#fff;color:var(--text);border-color:#cbd5e1}
.btn-success{background:var(--success);color:#fff}
.btn-warning{background:var(--warning);color:#fff}
.btn-danger{background:var(--danger);color:#fff}
.btn-light{background:var(--gray-light);color:var(--text)}
.btn:disabled{opacity:.55;cursor:not-allowed}
.notice{
    border-radius:10px;
    padding:14px 16px;
    margin-bottom:18px;
    border:1px solid;
}
.notice-success{
    background:#f0fdf4;
    border-color:#bbf7d0;
    color:#166534;
}
.notice-error{
    background:#fef2f2;
    border-color:#fecaca;
    color:#991b1b;
}
.notice-warning{
    background:#fffbeb;
    border-color:#fde68a;
    color:#92400e;
}
.notice-info{
    background:#eff6ff;
    border-color:#bfdbfe;
    color:#1e40af;
}
.status{
    display:inline-flex;
    align-items:center;
    padding:4px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}
.status.success{background:#dcfce7;color:#166534}
.status.warning{background:#fef3c7;color:#92400e}
.status.danger{background:#fee2e2;color:#991b1b}
.status.muted{background:#e2e8f0;color:#475569}
.table-wrap{
    overflow-x:auto;
    border:1px solid var(--border);
    border-radius:10px;
}
table{
    width:100%;
    border-collapse:collapse;
    min-width:900px;
    background:#fff;
}
th,td{
    padding:12px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:top;
}
th{
    background:#f8fafc;
    font-size:13px;
    white-space:nowrap;
}
tr:last-child td{border-bottom:0}
.empty{
    text-align:center;
    padding:40px 20px;
    color:var(--gray);
}
.tabs{
    display:flex;
    gap:4px;
    border-bottom:1px solid var(--border);
    margin-bottom:20px;
}
.tabs a{
    padding:10px 14px;
    color:var(--gray);
}
.tabs a.active{
    color:var(--primary);
    font-weight:700;
    border-bottom:3px solid var(--primary);
}
.stat-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
}
.stat{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:18px;
}
.stat-label{color:var(--gray);font-size:13px}
.stat-value{font-size:28px;font-weight:800;margin-top:5px}
.kintone-result{
    border:2px solid var(--border);
    border-radius:12px;
    padding:20px;
    margin-top:18px;
}
.kintone-result.ok{
    background:#f0fdf4;
    border-color:#86efac;
}
.kintone-result.ng{
    background:#fef2f2;
    border-color:#fca5a5;
}
.result-title{
    font-size:20px;
    font-weight:800;
    margin-bottom:8px;
}
.result-message{
    font-weight:700;
    margin-bottom:8px;
}
.result-detail{
    color:#475569;
    white-space:pre-wrap;
}
.spinner{
    width:16px;
    height:16px;
    border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .8s linear infinite;
    display:inline-block;
}
@keyframes spin{to{transform:rotate(360deg)}}
.question-card{
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
    margin-bottom:12px;
    background:#fff;
}
.group-card{
    border:2px solid #e2e8f0;
    border-radius:12px;
    padding:18px;
    margin-bottom:18px;
    background:#f8fafc;
}
.drag-handle{
    cursor:grab;
    color:var(--gray);
}
.choice-row{
    display:flex;
    gap:8px;
    margin-bottom:7px;
}
.choice-row input{flex:1}
.answer-page{
    max-width:760px;
    margin:0 auto;
    padding:20px;
}
.answer-page .card{
    margin-top:20px;
}
.answer-choice{
    display:block;
    padding:14px;
    border:1px solid var(--border);
    border-radius:10px;
    margin:8px 0;
    background:#fff;
}
.answer-choice input{margin-right:8px}
@media(max-width:900px){
    .col-8,.col-6,.col-4{grid-column:span 12}
    .stat-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:640px){
    .admin-header{
        align-items:flex-start;
        flex-direction:column;
        gap:10px;
        padding:14px;
    }
    .admin-nav{width:100%}
    .container{padding:18px 12px 40px}
    .page-title{align-items:flex-start;flex-direction:column}
    .page-title h1{font-size:23px}
    .form-row{grid-template-columns:1fr}
    .stat-grid{grid-template-columns:1fr}
    .card{padding:16px}
    .btn{width:100%}
    .actions .btn{width:auto}
}
</style>
</head>
<body>
<?php if ($admin): ?>
<header class="admin-header">
    <div class="brand">アンケート管理</div>
    <nav class="admin-nav">
        <a class="<?= $screen === 'list' ? 'active' : '' ?>"
           href="?screen=list">アンケート一覧</a>
        <a class="<?= $screen === 'kintone' ? 'active' : '' ?>"
           href="?screen=kintone">kintone</a>
        <a class="<?= $screen === 'mail' ? 'active' : '' ?>"
           href="?screen=mail">メール</a>
    </nav>
</header>
<?php endif; ?>
<?php
}

function page_footer(): void
{
    ?>
<script>
document.querySelectorAll('form[data-loading]').forEach(function(form){
    form.addEventListener('submit', function(){
        const button = form.querySelector('button[type="submit"]');
        if (!button) return;

        button.disabled = true;

        const original = button.innerHTML;
        button.dataset.original = original;

        button.innerHTML =
            '<span class="spinner"></span>&nbsp;処理中...';
    });
});

document.querySelectorAll('[data-confirm]').forEach(function(el){
    el.addEventListener('click', function(e){
        const message = el.getAttribute('data-confirm');

        if (message && !window.confirm(message)) {
            e.preventDefault();
        }
    });
});
</script>
</body>
</html>
<?php
}

/* =========================================================
 * 6. POST処理
 * ========================================================= */

$screen = (string)($_GET['screen'] ?? 'list');
$id = (string)($_GET['id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    /* -----------------------------------------
     * kintone設定保存
     * ----------------------------------------- */
    if ($action === 'save_kintone') {
        $settings = get_settings();

        $settings['kintone']['subdomain'] =
            normalize_subdomain((string)($_POST['subdomain'] ?? ''));

        $settings['kintone']['app_id'] =
            trim((string)($_POST['app_id'] ?? ''));

        $settings['kintone']['username'] =
            trim((string)($_POST['username'] ?? ''));

        /*
         * パスワードは未入力の場合、
         * 既存値を維持する。
         */
        $postedPassword = (string)($_POST['password'] ?? '');

        if ($postedPassword !== '') {
            $settings['kintone']['password'] = $postedPassword;
        }

        $settings['kintone']['proxy'] =
            trim((string)($_POST['proxy'] ?? ''));

        $settings['kintone']['verify_ssl'] =
            isset($_POST['verify_ssl']);

        if (save_settings($settings)) {
            $kintoneMessage = [
                'type' => 'success',
                'title' => '設定を保存しました',
                'message' => 'kintone設定をサーバーへ保存しました。',
            ];
        } else {
            $kintoneMessage = [
                'type' => 'error',
                'title' => '設定保存失敗',
                'message' => '設定ファイルへ保存できませんでした。dataフォルダの書込権限を確認してください。',
            ];
        }

        $screen = 'kintone';
    }

    /* -----------------------------------------
     * kintone接続テスト
     * ----------------------------------------- */
    elseif ($action === 'test_kintone') {
        $settings = get_settings();

        $result = kintone_test($settings['kintone']);

        $settings['kintone']['last_test'] = [
            'at' => now_iso(),
            'ok' => $result['ok'],
            'title' => $result['title'],
            'message' => $result['message'],
            'detail' => $result['detail'] ?? '',
            'http_code' => $result['http_code'] ?? null,
        ];

        /*
         * 接続テスト結果だけを保存。
         * パスワードそのものは保存データへ追加しない。
         */
        save_settings($settings);

        $kintoneTestResult = $result;
        $screen = 'kintone';
    }

    /* -----------------------------------------
     * kintone顧客同期
     * ----------------------------------------- */
    elseif ($action === 'sync_kintone') {
        $settings = get_settings();

        try {
            $customers = kintone_fetch_customers(
                $settings['kintone']
            );

            if (!save_customers($customers)) {
                throw new RuntimeException(
                    '顧客データをサーバーへ保存できませんでした。'
                    . ' dataフォルダの書込権限を確認してください。'
                );
            }

            $settings['kintone']['last_sync'] = [
                'at' => now_iso(),
                'ok' => true,
                'count' => count($customers),
            ];

            save_settings($settings);

            $kintoneSyncResult = [
                'ok' => true,
                'count' => count($customers),
                'message' =>
                    '顧客情報の同期が完了しました。'
                    . ' 取得件数：' . count($customers) . '件',
            ];
        } catch (Throwable $e) {
            $settings['kintone']['last_sync'] = [
                'at' => now_iso(),
                'ok' => false,
                'count' => 0,
            ];

            save_settings($settings);

            $kintoneSyncResult = [
                'ok' => false,
                'count' => 0,
                'message' => $e->getMessage(),
            ];
        }

        $screen = 'kintone';
    }

    /* -----------------------------------------
     * アンケート保存
     * ----------------------------------------- */
    elseif ($action === 'save_survey') {
        $surveyId = trim((string)($_POST['survey_id'] ?? ''));
        $surveys = get_surveys();

        $existingIndex = -1;

        foreach ($surveys as $index => $survey) {
            if (($survey['id'] ?? '') === $surveyId) {
                $existingIndex = $index;
                break;
            }
        }

        if ($existingIndex >= 0) {
            $survey = $surveys[$existingIndex];
        } else {
            $survey = default_survey();
            $surveyId = $survey['id'];
        }

        $survey['title'] =
            trim((string)($_POST['title'] ?? ''));

        $survey['description'] =
            trim((string)($_POST['description'] ?? ''));

        $survey['startAt'] =
            trim((string)($_POST['startAt'] ?? ''));

        $survey['endAt'] =
            trim((string)($_POST['endAt'] ?? ''));

        $survey['numbering'] =
            ($_POST['numbering'] ?? 'global') === 'group'
                ? 'group'
                : 'global';

        if ($existingIndex < 0) {
            $survey['status'] = 'draft';
        }

        $survey['updatedAt'] = now_iso();

        /*
         * 編集画面から送られるJSON。
         */
        $groupsJson = (string)($_POST['groups_json'] ?? '');

        if ($groupsJson !== '') {
            $groups = json_decode($groupsJson, true);

            if (is_array($groups)) {
                $survey['groups'] = $groups;
            }
        }

        if ($survey['title'] === '') {
            $editError = 'アンケートタイトルを入力してください。';
            $editSurvey = $survey;
            $screen = 'edit';
            $id = $surveyId;
        } elseif ($existingIndex >= 0) {
            $surveys[$existingIndex] = $survey;

            if (save_surveys($surveys)) {
                redirect_screen('list');
            }

            $editError = 'アンケートを保存できませんでした。';
            $editSurvey = $survey;
            $screen = 'edit';
            $id = $surveyId;
        } else {
            $surveys[] = $survey;

            if (save_surveys($surveys)) {
                redirect_screen('list');
            }

            $editError = 'アンケートを保存できませんでした。';
            $editSurvey = $survey;
            $screen = 'edit';
            $id = $surveyId;
        }
    }

    /* -----------------------------------------
     * アンケート削除
     * ----------------------------------------- */
    elseif ($action === 'delete_survey') {
        $surveyId = trim((string)($_POST['survey_id'] ?? ''));

        $surveys = array_values(array_filter(
            get_surveys(),
            static fn(array $survey): bool =>
                ($survey['id'] ?? '') !== $surveyId
        ));

        save_surveys($surveys);

        redirect_screen('list');
    }

    /* -----------------------------------------
     * アンケート複製
     * ----------------------------------------- */
    elseif ($action === 'duplicate_survey') {
        $surveyId = trim((string)($_POST['survey_id'] ?? ''));
        $source = find_survey($surveyId);

        if ($source !== null) {
            $copy = $source;
            $copy['id'] = new_id('survey');
            $copy['title'] = ($source['title'] ?? '') . '（複製）';
            $copy['status'] = 'draft';
            $copy['createdAt'] = now_iso();
            $copy['updatedAt'] = now_iso();

            foreach ($copy['groups'] as &$group) {
                $group['id'] = new_id('group');

                foreach (($group['questions'] ?? []) as &$question) {
                    $question['id'] = new_id('question');
                }

                unset($question);
            }

            unset($group);

            $surveys = get_surveys();
            $surveys[] = $copy;
            save_surveys($surveys);
        }

        redirect_screen('list');
    }

    /* -----------------------------------------
     * 状態変更
     * ----------------------------------------- */
    elseif ($action === 'change_status') {
        $surveyId = trim((string)($_POST['survey_id'] ?? ''));
        $newStatus = trim((string)($_POST['status'] ?? ''));

        $allowed = ['draft', 'published', 'stopped'];

        $surveys = get_surveys();

        foreach ($surveys as &$survey) {
            if (($survey['id'] ?? '') !== $surveyId) {
                continue;
            }

            $survey = normalize_survey_status($survey);

            if (($survey['status'] ?? '') === 'ended') {
                break;
            }

            if (!in_array($newStatus, $allowed, true)) {
                break;
            }

            $survey['status'] = $newStatus;
            $survey['updatedAt'] = now_iso();

            break;
        }

        unset($survey);

        save_surveys($surveys);

        redirect_screen('list');
    }

    /* -----------------------------------------
     * 回答保存
     * ----------------------------------------- */
    elseif ($action === 'submit_answer') {
        $surveyId = trim((string)($_POST['survey_id'] ?? ''));
        $survey = find_survey($surveyId);

        if ($survey === null) {
            $answerError = '対象アンケートが見つかりません。';
            $screen = 'answer';
            $id = $surveyId;
        } else {
            $survey = normalize_survey_status($survey);

            if (($survey['status'] ?? '') !== 'published') {
                $answerError =
                    'このアンケートは現在回答を受け付けていません。';
                $screen = 'answer';
                $id = $surveyId;
            } else {
                $answers = [];

                foreach (($survey['groups'] ?? []) as $group) {
                    foreach (($group['questions'] ?? []) as $question) {
                        $qid = (string)($question['id'] ?? '');
                        $type = (string)($question['type'] ?? 'single');

                        if ($type === 'multiple') {
                            $value = $_POST['q'][$qid] ?? [];
                            $value = is_array($value) ? array_values($value) : [];
                        } else {
                            $value = (string)($_POST['q'][$qid] ?? '');
                        }

                        if (($question['required'] ?? false)) {
                            $empty =
                                is_array($value)
                                    ? count($value) === 0
                                    : trim($value) === '';

                            if ($empty) {
                                $answerError =
                                    '必須項目が未回答です。';
                                break 2;
                            }
                        }

                        $answers[$qid] = $value;
                    }
                }

                if (!empty($answerError)) {
                    $screen = 'answer';
                    $id = $surveyId;
                } else {
                    $_SESSION['answer_confirm'] = [
                        'survey_id' => $surveyId,
                        'answers' => $answers,
                    ];

                    redirect_screen(
                        'confirm',
                        ['id' => $surveyId]
                    );
                }
            }
        }
    }

    /* -----------------------------------------
     * 回答確定
     * ----------------------------------------- */
    elseif ($action === 'confirm_answer') {
        $data = $_SESSION['answer_confirm'] ?? null;

        if (
            !is_array($data) ||
            empty($data['survey_id']) ||
            !is_array($data['answers'] ?? null)
        ) {
            $answerError =
                '回答状態が失われました。最初から回答してください。';
            $screen = 'list';
        } else {
            $answers = get_answers();

            $answers[] = [
                'id' => new_id('answer'),
                'survey_id' => (string)$data['survey_id'],
                'answers' => $data['answers'],
                'createdAt' => now_iso(),
            ];

            save_answers($answers);

            unset($_SESSION['answer_confirm']);

            redirect_screen(
                'complete',
                ['id' => (string)$data['survey_id']]
            );
        }
    }
}

/* =========================================================
 * 7. 共通データ
 * ========================================================= */

$settings = get_settings();
$surveys = get_surveys();

/*
 * 表示時に終了状態を自動判定。
 */
$surveysChanged = false;

foreach ($surveys as $index => $survey) {
    $normalized = normalize_survey_status($survey);

    if ($normalized !== $survey) {
        $surveys[$index] = $normalized;
        $surveysChanged = true;
    }
}

if ($surveysChanged) {
    save_surveys($surveys);
}

/* =========================================================
 * 8. 一覧画面
 * ========================================================= */

if ($screen === 'list') {
    $search = trim((string)($_GET['q'] ?? ''));
    $filter = (string)($_GET['status'] ?? 'all');
    $sort = (string)($_GET['sort'] ?? 'updated_desc');

    $list = array_values(array_filter(
        $surveys,
        static function(array $survey) use ($search, $filter): bool {
            $title = (string)($survey['title'] ?? '');

            if ($search !== '' && mb_stripos($title, $search) === false) {
                return false;
            }

            if ($filter === 'published' &&
                ($survey['status'] ?? '') !== 'published') {
                return false;
            }

            if ($filter === 'draft' &&
                ($survey['status'] ?? '') !== 'draft') {
                return false;
            }

            if ($filter === 'stopped' &&
                ($survey['status'] ?? '') !== 'stopped') {
                return false;
            }

            if ($filter === 'ended' &&
                ($survey['status'] ?? '') !== 'ended') {
                return false;
            }

            return true;
        }
    ));

    usort(
        $list,
        static function(array $a, array $b) use ($sort): int {
            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)($a['updatedAt'] ?? ''),
                        (string)($b['updatedAt'] ?? '')
                    ),
                'answers_desc' =>
                    count(array_filter(
                        get_answers(),
                        static fn(array $x): bool =>
                            ($x['survey_id'] ?? '') === ($b['id'] ?? '')
                    ))
                    <=>
                    count(array_filter(
                        get_answers(),
                        static fn(array $x): bool =>
                            ($x['survey_id'] ?? '') === ($a['id'] ?? '')
                    )),
                'start_desc' =>
                    strcmp(
                        (string)($b['startAt'] ?? ''),
                        (string)($a['startAt'] ?? '')
                    ),
                'start_asc' =>
                    strcmp(
                        (string)($a['startAt'] ?? ''),
                        (string)($b['startAt'] ?? '')
                    ),
                default =>
                    strcmp(
                        (string)($b['updatedAt'] ?? ''),
                        (string)($a['updatedAt'] ?? '')
                    ),
            };
        }
    );

    page_header('アンケート一覧', 'list');
    ?>
<main class="container">

<div class="page-title">
    <h1>アンケート一覧</h1>
    <a class="btn btn-primary" href="?screen=edit">
        ＋ 新規アンケート
    </a>
</div>

<div class="card">
    <form method="get">
        <input type="hidden" name="screen" value="list">

        <div class="form-row">
            <div class="field">
                <label for="q">タイトル検索</label>
                <input
                    id="q"
                    name="q"
                    type="text"
                    value="<?= h($search) ?>"
                    placeholder="タイトルを入力してEnter"
                >
            </div>

            <div class="field">
                <label for="status">絞り込み</label>
                <select id="status" name="status">
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>
                        すべて
                    </option>
                    <option value="published" <?= $filter === 'published' ? 'selected' : '' ?>>
                        公開中
                    </option>
                    <option value="draft" <?= $filter === 'draft' ? 'selected' : '' ?>>
                        下書き
                    </option>
                    <option value="stopped" <?= $filter === 'stopped' ? 'selected' : '' ?>>
                        停止
                    </option>
                    <option value="ended" <?= $filter === 'ended' ? 'selected' : '' ?>>
                        終了
                    </option>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="field">
                <label for="sort">ソート</label>
                <select id="sort" name="sort">
                    <option value="updated_desc" <?= $sort === 'updated_desc' ? 'selected' : '' ?>>
                        更新日：新しい順
                    </option>
                    <option value="updated_asc" <?= $sort === 'updated_asc' ? 'selected' : '' ?>>
                        更新日：古い順
                    </option>
                    <option value="answers_desc" <?= $sort === 'answers_desc' ? 'selected' : '' ?>>
                        回答数：多い順
                    </option>
                    <option value="start_desc" <?= $sort === 'start_desc' ? 'selected' : '' ?>>
                        開始日：新しい順
                    </option>
                    <option value="start_asc" <?= $sort === 'start_asc' ? 'selected' : '' ?>>
                        開始日：古い順
                    </option>
                </select>
            </div>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">
                検索・絞り込み
            </button>
            <a class="btn btn-secondary" href="?screen=list">
                リセット
            </a>
        </div>
    </form>
</div>

<div class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
    <th>タイトル</th>
    <th>作成日</th>
    <th>更新日</th>
    <th>アンケート期間</th>
    <th>ステータス</th>
    <th>回答数</th>
    <th>操作</th>
</tr>
</thead>
<tbody>
<?php if (!$list): ?>
<tr>
<td colspan="7" class="empty">
    アンケートがありません。
</td>
</tr>
<?php endif; ?>

<?php foreach ($list as $survey): ?>
<?php
$answerCount = count(array_filter(
    get_answers(),
    static fn(array $answer): bool =>
        ($answer['survey_id'] ?? '') === ($survey['id'] ?? '')
));
$status = (string)($survey['status'] ?? 'draft');
?>
<tr>
<td>
    <strong><?= h($survey['title'] ?: '無題のアンケート') ?></strong>
</td>
<td><?= h($survey['createdAt'] ?? '') ?></td>
<td><?= h($survey['updatedAt'] ?? '') ?></td>
<td>
    <?= h($survey['startAt'] ?: '指定なし') ?>
    ～<br>
    <?= h($survey['endAt'] ?: '指定なし') ?>
</td>
<td>
    <span class="status <?= h(status_class($status)) ?>">
        <?= h(status_label($status)) ?>
    </span>
</td>
<td><?= h($answerCount) ?>件</td>
<td>
    <div class="actions">
        <a class="btn btn-secondary"
           href="?screen=edit&id=<?= urlencode((string)$survey['id']) ?>">
            確認・編集
        </a>

        <a class="btn btn-light"
           href="?screen=preview&id=<?= urlencode((string)$survey['id']) ?>">
            プレビュー
        </a>

        <a class="btn btn-light"
           href="?screen=analytics&id=<?= urlencode((string)$survey['id']) ?>">
            集計
        </a>

        <a class="btn btn-light"
           href="?screen=send&id=<?= urlencode((string)$survey['id']) ?>">
            送信
        </a>

        <?php if ($status !== 'ended'): ?>
        <?php if ($status === 'draft'): ?>
        <form method="post" style="display:inline">
            <input type="hidden" name="action" value="change_status">
            <input type="hidden" name="survey_id"
                   value="<?= h($survey['id']) ?>">
            <input type="hidden" name="status" value="published">
            <button
                class="btn btn-success"
                data-confirm="このアンケートを公開しますか？"
            >
                公開
            </button>
        </form>
        <?php elseif ($status === 'published'): ?>
        <form method="post" style="display:inline">
            <input type="hidden" name="action" value="change_status">
            <input type="hidden" name="survey_id"
                   value="<?= h($survey['id']) ?>">
            <input type="hidden" name="status" value="stopped">
            <button
                class="btn btn-warning"
                data-confirm="このアンケートを停止しますか？"
            >
                停止
            </button>
        </form>
        <?php elseif ($status === 'stopped'): ?>
        <form method="post" style="display:inline">
            <input type="hidden" name="action" value="change_status">
            <input type="hidden" name="survey_id"
                   value="<?= h($survey['id']) ?>">
            <input type="hidden" name="status" value="published">
            <button
                class="btn btn-success"
                data-confirm="このアンケートを再開しますか？"
            >
                再開
            </button>
        </form>
        <?php endif; ?>
        <?php endif; ?>

        <form method="post" style="display:inline">
            <input type="hidden" name="action" value="duplicate_survey">
            <input type="hidden" name="survey_id"
                   value="<?= h($survey['id']) ?>">
            <button
                class="btn btn-light"
                data-confirm="このアンケートを複製しますか？"
            >
                複製
            </button>
        </form>

        <form method="post" style="display:inline">
            <input type="hidden" name="action" value="delete_survey">
            <input type="hidden" name="survey_id"
                   value="<?= h($survey['id']) ?>">
            <button
                class="btn btn-danger"
                data-confirm="このアンケートを削除しますか？この操作は戻せません。"
            >
                削除
            </button>
        </form>
    </div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

</main>
<?php
    page_footer();
    exit;
}

/* =========================================================
 * 9. kintone設定画面
 * ========================================================= */

if ($screen === 'kintone') {
    $k = $settings['kintone'];

    page_header('kintone連携設定', 'kintone');
    ?>
<main class="container">

<div class="page-title">
    <h1>kintone連携設定</h1>
    <a class="btn btn-secondary" href="?screen=list">
        ← アンケート一覧
    </a>
</div>

<?php if (!empty($kintoneMessage)): ?>
<div class="notice notice-<?= h($kintoneMessage['type']) ?>">
    <strong><?= h($kintoneMessage['title']) ?></strong><br>
    <?= h($kintoneMessage['message']) ?>
</div>
<?php endif; ?>

<?php if (!empty($kintoneTestResult)): ?>
<div class="kintone-result <?= $kintoneTestResult['ok'] ? 'ok' : 'ng' ?>">
    <div class="result-title">
        <?= $kintoneTestResult['ok'] ? '✓ ' : '✕ ' ?>
        <?= h($kintoneTestResult['title']) ?>
    </div>

    <div class="result-message">
        <?= h($kintoneTestResult['message']) ?>
    </div>

    <?php if (!empty($kintoneTestResult['detail'])): ?>
    <div class="result-detail">
        <?= h($kintoneTestResult['detail']) ?>
    </div>
    <?php endif; ?>

    <?php if (isset($kintoneTestResult['http_code'])): ?>
    <div class="help">
        HTTPステータス：
        <?= h($kintoneTestResult['http_code']) ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($kintoneSyncResult)): ?>
<div class="notice <?= $kintoneSyncResult['ok']
    ? 'notice-success'
    : 'notice-error' ?>">
    <strong>
        <?= $kintoneSyncResult['ok']
            ? '同期完了'
            : '同期失敗' ?>
    </strong><br>
    <?= h($kintoneSyncResult['message']) ?>
</div>
<?php endif; ?>

<div class="card">
<h2>kintone接続設定</h2>

<form method="post" data-loading>
<input type="hidden" name="action" value="save_kintone">

<div class="form-row">

<div class="field">
<label for="subdomain">サブドメイン</label>
<input
    id="subdomain"
    name="subdomain"
    type="text"
    value="<?= h($k['subdomain']) ?>"
    placeholder="xxxx.cybozu.com"
>
<div class="help">
https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx のいずれも入力できます。
</div>
</div>

<div class="field">
<label for="app_id">顧客管理アプリID</label>
<input
    id="app_id"
    name="app_id"
    type="number"
    min="1"
    value="<?= h($k['app_id']) ?>"
    placeholder="123"
>
<div class="help">
kintoneの顧客管理アプリの数字IDです。
</div>
</div>

<div class="field">
<label for="username">ログイン名</label>
<input
    id="username"
    name="username"
    type="text"
    value="<?= h($k['username']) ?>"
    autocomplete="username"
>
</div>

<div class="field">
<label for="password">パスワード</label>
<input
    id="password"
    name="password"
    type="password"
    value=""
    autocomplete="new-password"
    placeholder="変更する場合のみ入力"
>
<div class="help">
空欄の場合は現在保存されているパスワードを維持します。
</div>
</div>

<div class="field">
<label for="proxy">Proxy</label>
<input
    id="proxy"
    name="proxy"
    type="text"
    value="<?= h($k['proxy']) ?>"
    placeholder="host:port"
>
<div class="help">
未入力の場合はProxyを使用せず直接接続します。
</div>
</div>

<div class="field">
<label>SSL証明書検証</label>
<label style="font-weight:normal">
<input
    type="checkbox"
    name="verify_ssl"
    value="1"
    <?= !empty($k['verify_ssl']) ? 'checked' : '' ?>
>
有効にする
</label>
<div class="help">
POCでは無効を初期値とします。
</div>
</div>

</div>

<div class="actions">
<button class="btn btn-primary" type="submit">
    設定保存
</button>
</div>

</form>
</div>

<div class="card">
<h2>接続確認</h2>

<p>
「接続テスト」は顧客同期とは別の操作です。
kintoneのアプリ情報APIへ実際に接続して結果を確認します。
</p>

<form method="post" data-loading>
<input type="hidden" name="action" value="test_kintone">

<button class="btn btn-primary" type="submit">
    接続テスト
</button>
</form>

<?php if (!empty($k['last_test'])): ?>
<?php $last = $k['last_test']; ?>
<div class="kintone-result <?= !empty($last['ok']) ? 'ok' : 'ng' ?>">
    <div class="result-title">
        <?= !empty($last['ok']) ? '✓ ' : '✕ ' ?>
        <?= h($last['title'] ?? '') ?>
    </div>

    <div class="result-message">
        <?= h($last['message'] ?? '') ?>
    </div>

    <?php if (!empty($last['detail'])): ?>
    <div class="result-detail">
        <?= h($last['detail']) ?>
    </div>
    <?php endif; ?>

    <div class="help">
        実行日時：
        <?= h($last['at'] ?? '') ?>
        <?php if (!empty($last['http_code'])): ?>
        / HTTP <?= h($last['http_code']) ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</div>

<div class="card">
<h2>顧客情報同期</h2>

<p>
kintoneから顧客情報を取得し、サーバー側の顧客データへ保存します。
接続テストとは別の処理です。
</p>

<form method="post" data-loading>
<input type="hidden" name="action" value="sync_kintone">

<button class="btn btn-success" type="submit">
    顧客情報を同期
</button>
</form>

<?php if (!empty($k['last_sync'])): ?>
<div class="notice <?= !empty($k['last_sync']['ok'])
    ? 'notice-success'
    : 'notice-error' ?>"
     style="margin-top:18px;margin-bottom:0">

    <strong>
        <?= !empty($k['last_sync']['ok'])
            ? '前回同期：成功'
            : '前回同期：失敗' ?>
    </strong><br>

    実行日時：
    <?= h($k['last_sync']['at'] ?? '') ?><br>

    取得件数：
    <?= h($k['last_sync']['count'] ?? 0) ?>件
</div>
<?php endif; ?>

</div>

<div class="card">
<h2>現在の状態</h2>

<div class="stat-grid">
<div class="stat">
    <div class="stat-label">保存済み顧客</div>
    <div class="stat-value">
        <?= h(count(get_customers())) ?>
    </div>
</div>

<div class="stat">
    <div class="stat-label">前回同期件数</div>
    <div class="stat-value">
        <?= h($k['last_sync']['count'] ?? 0) ?>
    </div>
</div>

<div class="stat">
    <div class="stat-label">接続テスト</div>
    <div class="stat-value" style="font-size:20px">
        <?= !empty($k['last_test']['ok'])
            ? '成功'
            : '未確認 / 失敗' ?>
    </div>
</div>

<div class="stat">
    <div class="stat-label">SSL検証</div>
    <div class="stat-value" style="font-size:20px">
        <?= !empty($k['verify_ssl'])
            ? '有効'
            : '無効' ?>
    </div>
</div>
</div>

</div>

</main>
<?php
    page_footer();
    exit;
}

/* =========================================================
 * 10. 編集画面
 * ========================================================= */

if ($screen === 'edit') {
    $survey = !empty($editSurvey)
        ? $editSurvey
        : ($id !== '' ? find_survey($id) : null);

    if ($survey === null) {
        $survey = default_survey();
    }

    $numbers = question_number(
        $survey['groups'] ?? [],
        (string)($survey['numbering'] ?? 'global')
    );

    page_header('アンケート作成・編集', 'list');
    ?>
<main class="container">

<div class="page-title">
    <h1>アンケート作成・編集</h1>

    <div class="actions">
        <a class="btn btn-secondary" href="?screen=list">
            キャンセル
        </a>

        <a class="btn btn-light"
           href="?screen=preview&id=<?= urlencode((string)$survey['id']) ?>">
            プレビュー
        </a>
    </div>
</div>

<?php if (!empty($editError)): ?>
<div class="notice notice-error">
    <?= h($editError) ?>
</div>
<?php endif; ?>

<div class="card">

<form method="post" data-loading id="survey-form">

<input type="hidden" name="action" value="save_survey">
<input type="hidden" name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden" name="groups_json"
       id="groups_json">

<div class="form-row">

<div class="field">
<label for="title">アンケートタイトル</label>
<input
    id="title"
    name="title"
    type="text"
    required
    maxlength="200"
    value="<?= h($survey['title']) ?>"
>
</div>

<div class="field">
<label for="numbering">質問番号の採番方式</label>
<select id="numbering" name="numbering">
<option value="global"
    <?= ($survey['numbering'] ?? '') === 'global'
        ? 'selected'
        : '' ?>>
    アンケート全体で通番：Q1、Q2、Q3...
</option>
<option value="group"
    <?= ($survey['numbering'] ?? '') === 'group'
        ? 'selected'
        : '' ?>>
    グループ毎：Q1-1、Q1-2、Q2-1...
</option>
</select>
</div>

<div class="field">
<label for="startAt">開始日時</label>
<input
    id="startAt"
    name="startAt"
    type="datetime-local"
    value="<?= h($survey['startAt']) ?>"
>
</div>

<div class="field">
<label for="endAt">終了日時</label>
<input
    id="endAt"
    name="endAt"
    type="datetime-local"
    value="<?= h($survey['endAt']) ?>"
>
</div>

<div class="field" style="grid-column:1/-1">
<label for="description">アンケート説明</label>
<textarea
    id="description"
    name="description"
    maxlength="5000"
><?= h($survey['description']) ?></textarea>
</div>

</div>

<div class="field">
<label>状態</label>
<span class="status <?= h(
    status_class((string)$survey['status'])
) ?>">
    <?= h(status_label((string)$survey['status'])) ?>
</span>
</div>

<div id="groups-editor"></div>

<div class="actions">
    <button
        type="button"
        class="btn btn-secondary"
        id="add-group"
    >
        ＋ グループを追加
    </button>
</div>

<hr style="border:0;border-top:1px solid var(--border);margin:24px 0">

<div class="actions">
    <a class="btn btn-secondary" href="?screen=list">
        キャンセル
    </a>
    <button class="btn btn-primary" type="submit">
        保存して一覧へ
    </button>
</div>

</form>

</div>

</main>

<script>
const initialGroups = <?= json_encode(
    $survey['groups'] ?? [],
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?>;

let groups = Array.isArray(initialGroups) ? initialGroups : [];

function uid(prefix){
    return prefix + '-' +
        Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2,8);
}

function esc(value){
    return String(value ?? '')
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
}

function normalizeGroups(){
    groups = groups.map(function(group){
        if (!Array.isArray(group.questions)){
            group.questions = [];
        }

        return group;
    });
}

function renderGroups(){
    normalizeGroups();

    const root = document.getElementById('groups-editor');
    let html = '';

    groups.forEach(function(group, gi){

        html += `
        <div class="group-card"
             draggable="true"
             data-group-index="${gi}">
            <div class="actions" style="margin-bottom:12px">
                <span class="drag-handle">☷</span>
                <strong>グループ ${gi + 1}</strong>
                <button
                    type="button"
                    class="btn btn-danger"
                    onclick="removeGroup(${gi})"
                >
                    グループ削除
                </button>
            </div>

            <div class="field">
                <label>グループタイトル</label>
                <input
                    type="text"
                    value="${esc(group.title)}"
                    onchange="groups[${gi}].title=this.value"
                >
            </div>
        `;

        (group.questions || []).forEach(function(question, qi){

            const number =
                document.getElementById('numbering').value === 'group'
                    ? 'Q' + (gi + 1) + '-' + (qi + 1)
                    : 'Q' + (
                        groups
                            .slice(0, gi)
                            .reduce((n,g) =>
                                n + (g.questions || []).length, 0)
                        + qi + 1
                    );

            const options = Array.isArray(question.options)
                ? question.options
                : [];

            html += `
            <div class="question-card"
                 draggable="true"
                 data-question-index="${qi}">
                <div class="actions">
                    <span class="drag-handle">☷</span>
                    <strong>${number}</strong>
                    <button
                        type="button"
                        class="btn btn-danger"
                        onclick="removeQuestion(${gi},${qi})"
                    >
                        質問削除
                    </button>
                </div>

                <div class="field">
                    <label>質問文</label>
                    <textarea
                        onchange="groups[${gi}].questions[${qi}].text=this.value"
                    >${esc(question.text)}</textarea>
                </div>

                <div class="form-row">

                <div class="field">
                    <label>回答形式</label>
                    <select
                        onchange="changeType(${gi},${qi},this.value)"
                    >
                        <option value="single"
                            ${question.type === 'single'
                                ? 'selected':''}>
                            単一選択
                        </option>
                        <option value="multiple"
                            ${question.type === 'multiple'
                                ? 'selected':''}>
                            複数選択
                        </option>
                        <option value="text"
                            ${question.type === 'text'
                                ? 'selected':''}>
                            自由記述
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>必須 / 任意</label>
                    <label style="font-weight:normal">
                        <input
                            type="checkbox"
                            ${question.required ? 'checked':''}
                            onchange="groups[${gi}].questions[${qi}].required=this.checked"
                        >
                        必須
                    </label>
                </div>

                </div>
            `;

            if (
                question.type === 'single' ||
                question.type === 'multiple'
            ) {
                html += `
                <div class="field">
                    <label>選択肢</label>
                    <div id="choices-${gi}-${qi}">
                `;

                options.forEach(function(option, oi){
                    html += `
                    <div class="choice-row">
                        <input
                            type="text"
                            value="${esc(option)}"
                            onchange="groups[${gi}].questions[${qi}].options[${oi}]=this.value"
                        >
                        <button
                            type="button"
                            class="btn btn-light"
                            onclick="removeChoice(${gi},${qi},${oi})"
                        >
                            削除
                        </button>
                    </div>
                    `;
                });

                html += `
                    </div>

                    <button
                        type="button"
                        class="btn btn-light"
                        onclick="addChoice(${gi},${qi})"
                    >
                        ＋ 選択肢を追加
                    </button>
                </div>
                `;
            }

            if (question.type === 'single') {
                html += `
                <div class="field">
                    <label>条件分岐</label>
                    <div class="help">
                        選択肢ごとの次質問はプレビュー時に確認できます。
                    </div>
                </div>
                `;
            }

            html += `</div>`;
        });

        html += `
            <button
                type="button"
                class="btn btn-light"
                onclick="addQuestion(${gi})"
            >
                ＋ 質問を追加
            </button>
        </div>
        `;
    });

    root.innerHTML = html;
}

function addGroup(){
    groups.push({
        id: uid('group'),
        title: '新しいグループ',
        questions: []
    });

    renderGroups();
}

function removeGroup(index){
    if (!confirm('このグループを削除しますか？')) {
        return;
    }

    groups.splice(index,1);

    if (groups.length === 0) {
        addGroup();
        return;
    }

    renderGroups();
}

function addQuestion(groupIndex){
    groups[groupIndex].questions.push({
        id: uid('question'),
        text: '',
        type: 'single',
        required: false,
        options: ['選択肢1','選択肢2'],
        branches: []
    });

    renderGroups();
}

function removeQuestion(groupIndex, questionIndex){
    if (!confirm('この質問を削除しますか？')) {
        return;
    }

    groups[groupIndex].questions.splice(questionIndex,1);

    renderGroups();
}

function changeType(groupIndex, questionIndex, type){
    groups[groupIndex].questions[questionIndex].type = type;

    if (
        (type === 'single' || type === 'multiple') &&
        !Array.isArray(
            groups[groupIndex].questions[questionIndex].options
        )
    ) {
        groups[groupIndex].questions[questionIndex].options =
            ['選択肢1','選択肢2'];
    }

    renderGroups();
}

function addChoice(groupIndex, questionIndex){
    if (!Array.isArray(
        groups[groupIndex].questions[questionIndex].options
    )) {
        groups[groupIndex].questions[questionIndex].options = [];
    }

    groups[groupIndex].questions[questionIndex].options.push(
        '選択肢'
    );

    renderGroups();
}

function removeChoice(groupIndex, questionIndex, choiceIndex){
    groups[groupIndex].questions[questionIndex].options.splice(
        choiceIndex,1
    );

    renderGroups();
}

document.getElementById('add-group')
    .addEventListener('click',addGroup);

document.getElementById('numbering')
    .addEventListener('change',renderGroups);

document.getElementById('survey-form')
    .addEventListener('submit',function(){
        document.getElementById('groups_json').value =
            JSON.stringify(groups);
    });

if (groups.length === 0) {
    addGroup();
} else {
    renderGroups();
}
</script>
<?php
    page_footer();
    exit;
}

/* =========================================================
 * 11. プレビュー
 * ========================================================= */

if ($screen === 'preview') {
    $survey = find_survey($id);

    if ($survey === null) {
        redirect_screen('list');
    }

    $survey = normalize_survey_status($survey);

    $numbers = question_number(
        $survey['groups'] ?? [],
        (string)($survey['numbering'] ?? 'global')
    );

    page_header('プレビュー', 'list');
    ?>
<main class="container">

<div class="page-title">
    <h1>プレビュー</h1>
    <a class="btn btn-secondary"
       href="?screen=edit&id=<?= urlencode($id) ?>">
        ← 編集へ戻る
    </a>
</div>

<div class="card">
<h2><?= h($survey['title']) ?></h2>

<?php if ($survey['description'] !== ''): ?>
<p><?= nl2br(h($survey['description'])) ?></p>
<?php endif; ?>

<?php foreach (($survey['groups'] ?? []) as $group): ?>
<div class="group-card">
<h3><?= h($group['title']) ?></h3>

<?php foreach (($group['questions'] ?? []) as $question): ?>
<?php $qid = (string)$question['id']; ?>
<div class="question-card">
    <div>
        <strong>
            <?= h($numbers[$qid] ?? '') ?>
            <?= h($question['text']) ?>
        </strong>

        <?php if (!empty($question['required'])): ?>
            <span class="status danger">必須</span>
        <?php else: ?>
            <span class="status muted">任意</span>
        <?php endif; ?>
    </div>

    <?php if ($question['type'] === 'text'): ?>
        <textarea placeholder="回答を入力してください"></textarea>
    <?php elseif ($question['type'] === 'multiple'): ?>
        <?php foreach (($question['options'] ?? []) as $option): ?>
        <label class="answer-choice">
            <input type="checkbox">
            <?= h($option) ?>
        </label>
        <?php endforeach; ?>
    <?php else: ?>
        <?php foreach (($question['options'] ?? []) as $option): ?>
        <label class="answer-choice">
            <input type="radio"
                   name="preview-<?= h($qid) ?>">
            <?= h($option) ?>
        </label>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<div class="notice notice-info">
    これはプレビューです。メール送信等の実処理は行いません。
</div>

</div>
</main>
<?php
    page_footer();
    exit;
}

/* =========================================================
 * 12. 回答者画面
 * ========================================================= */

if ($screen === 'answer') {
    $survey = find_survey($id);

    if ($survey === null) {
        page_header('アンケート', 'answer', false);
        ?>
<div class="answer-page">
<div class="card">
<div class="notice notice-error">
    対象アンケートが見つかりません。
</div>
</div>
</div>
<?php
        page_footer();
        exit;
    }

    $survey = normalize_survey_status($survey);

    if (($survey['status'] ?? '') !== 'published') {
        page_header('アンケート', 'answer', false);
        ?>
<div class="answer-page">
<div class="card">
<h1><?= h($survey['title']) ?></h1>
<div class="notice notice-warning">
    このアンケートは現在回答を受け付けていません。
</div>
</div>
</div>
<?php
        page_footer();
        exit;
    }

    page_header($survey['title'], 'answer', false);
    ?>
<main class="answer-page">

<div class="card">
<h1><?= h($survey['title']) ?></h1>

<?php if ($survey['description'] !== ''): ?>
<p><?= nl2br(h($survey['description'])) ?></p>
<?php endif; ?>
</div>

<?php if (!empty($answerError)): ?>
<div class="notice notice-error">
    <?= h($answerError) ?>
</div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="action" value="submit_answer">
<input type="hidden" name="survey_id"
       value="<?= h($survey['id']) ?>">

<?php
$numbers = question_number(
    $survey['groups'] ?? [],
    (string)($survey['numbering'] ?? 'global')
);
?>

<?php foreach (($survey['groups'] ?? []) as $group): ?>
<div class="card">
<h2><?= h($group['title']) ?></h2>

<?php foreach (($group['questions'] ?? []) as $question): ?>
<?php $qid = (string)$question['id']; ?>

<div class="question-card">
<label>
<strong>
<?= h($numbers[$qid] ?? '') ?>
<?= h($question['text']) ?>
</strong>

<?php if (!empty($question['required'])): ?>
<span class="status danger">必須</span>
<?php endif; ?>
</label>

<?php if (($question['type'] ?? '') === 'text'): ?>

<textarea
    name="q[<?= h($qid) ?>]"
    <?= !empty($question['required']) ? 'required' : '' ?>
></textarea>

<?php elseif (($question['type'] ?? '') === 'multiple'): ?>

<?php foreach (($question['options'] ?? []) as $option): ?>
<label class="answer-choice">
<input
    type="checkbox"
    name="q[<?= h($qid) ?>][]"
    value="<?= h($option) ?>"
>
<?= h($option) ?>
</label>
<?php endforeach; ?>

<?php else: ?>

<?php foreach (($question['options'] ?? []) as $option): ?>
<label class="answer-choice">
<input
    type="radio"
    name="q[<?= h($qid) ?>]"
    value="<?= h($option) ?>"
    <?= !empty($question['required']) ? 'required' : '' ?>
>
<?= h($option) ?>
</label>
<?php endforeach; ?>

<?php endif; ?>

</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<div class="card">
<button class="btn btn-primary" type="submit">
    回答を確認する
</button>
</div>

</form>
</main>
<?php
    page_footer();
    exit;
}

/* =========================================================
 * 13. 回答確認
 * ========================================================= */

if ($screen === 'confirm') {
    $survey = find_survey($id);
    $confirm = $_SESSION['answer_confirm'] ?? null;

    if ($survey === null || !is_array($confirm)) {
        redirect_screen('list');
    }

    $numbers = question_number(
        $survey['groups'] ?? [],
        (string)($survey['numbering'] ?? 'global')
    );

    page_header('回答確認', 'answer', false);
    ?>
<main class="answer-page">

<div class="card">
<h1>回答確認</h1>
<p><?= h($survey['title']) ?></p>
</div>

<div class="card">

<?php foreach (($survey['groups'] ?? []) as $group): ?>
<h2><?= h($group['title']) ?></h2>

<?php foreach (($group['questions'] ?? []) as $question): ?>
<?php
$qid = (string)$question['id'];
$value = $confirm['answers'][$qid] ?? '';

if (is_array($value)) {
    $display = implode('、', array_map(
        static fn($x): string => (string)$x,
        $value
    ));
} else {
    $display = (string)$value;
}
?>
<div class="question-card">
<strong>
<?= h($numbers[$qid] ?? '') ?>
<?= h($question['text']) ?>
</strong>
<div style="margin-top:8px">
<?= nl2br(h($display)) ?>
</div>
</div>
<?php endforeach; ?>

<?php endforeach; ?>

</div>

<div class="card">
<div class="actions">
<a
    class="btn btn-secondary"
    href="?screen=answer&id=<?= urlencode($id) ?>"
>
    回答を修正
</a>

<form method="post">
<input type="hidden" name="action"
       value="confirm_answer">
<button class="btn btn-primary" type="submit"
        data-confirm="回答を送信します。よろしいですか？">
    回答を送信する
</button>
</form>
</div>
</div>

</main>
<?php
    page_footer();
    exit;
}

/* =========================================================
 * 14. 回答完了
 * ========================================================= */

if ($screen === 'complete') {
    $survey = find_survey($id);

    page_header('回答完了', 'answer', false);
    ?>
<main class="answer-page">
<div class="card">
<div class="notice notice-success">
    <strong>回答ありがとうございました。</strong><br>
    回答を正常に受け付けました。
</div>

<?php if ($survey !== null): ?>
<h1><?= h($survey['title']) ?></h1>
<?php endif; ?>
</div>
</main>
<?php
    page_footer();
    exit;
}

/* =========================================================
 * 15. 集計
 * ========================================================= */

if ($screen === 'analytics') {
    $survey = find_survey($id);

    if ($survey === null) {
        redirect_screen('list');
    }

    $answers = array_values(array_filter(
        get_answers(),
        static fn(array $answer): bool =>
            ($answer['survey_id'] ?? '') === $id
    ));

    $numbers = question_number(
        $survey['groups'] ?? [],
        (string)($survey['numbering'] ?? 'global')
    );

    page_header('回答集計・分析', 'list');
    ?>
<main class="container">

<div class="page-title">
<h1>回答集計・分析</h1>
<a class="btn btn-secondary" href="?screen=list">
    ← 一覧へ
</a>
</div>

<div class="card">
<h2><?= h($survey['title']) ?></h2>

<div class="stat-grid">
<div class="stat">
<div class="stat-label">回答数</div>
<div class="stat-value"><?= h(count($answers)) ?></div>
</div>

<div class="stat">
<div class="stat-label">未登録回答数</div>
<div class="stat-value">0</div>
</div>

<div class="stat">
<div class="stat-label">未回答数</div>
<div class="stat-value">0</div>
</div>

<div class="stat">
<div class="stat-label">回答率</div>
<div class="stat-value">
<?= count($answers) > 0 ? '100%' : '0%' ?>
</div>
</div>
</div>
</div>

<div class="card">
<h2>設問別集計</h2>

<?php if (!$answers): ?>
<div class="empty">
    現在、回答データはありません
</div>
<?php endif; ?>

<?php foreach (($survey['groups'] ?? []) as $group): ?>

<h3><?= h($group['title']) ?></h3>

<?php foreach (($group['questions'] ?? []) as $question): ?>
<?php
$qid = (string)$question['id'];
$values = [];

foreach ($answers as $answer) {
    $value = $answer['answers'][$qid] ?? null;

    if (is_array($value)) {
        foreach ($value as $v) {
            $values[] = (string)$v;
        }
    } elseif ($value !== null && $value !== '') {
        $values[] = (string)$value;
    }
}

$countMap = array_count_values($values);
?>

<div class="question-card">
<strong>
<?= h($numbers[$qid] ?? '') ?>
<?= h($question['text']) ?>
</strong>

<?php if (!$values): ?>
<div class="empty">
    回答データはありません
</div>
<?php else: ?>
<table style="margin-top:12px">
<thead>
<tr>
<th>回答</th>
<th>件数</th>
</tr>
</thead>
<tbody>
<?php foreach ($countMap as $value => $count): ?>
<tr>
<td><?= h($value) ?></td>
<td><?= h($count) ?>件</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

</div>
<?php endforeach; ?>
<?php endforeach; ?>

</div>

<div class="card">
<h2>個別回答</h2>

<?php if (!$answers): ?>
<div class="empty">
    現在、回答データはありません
</div>
<?php else: ?>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>回答ID</th>
<th>回答日時</th>
<th>回答</th>
</tr>
</thead>
<tbody>

<?php foreach ($answers as $answer): ?>
<tr>
<td><?= h($answer['id'] ?? '') ?></td>
<td><?= h($answer['createdAt'] ?? '') ?></td>
<td>
<?php foreach (($answer['answers'] ?? []) as $qid => $value): ?>
<div>
<strong><?= h($numbers[$qid] ?? $qid) ?>:</strong>
<?= h(
    is_array($value)
        ? implode('、', $value)
        : $value
) ?>
</div>
<?php endforeach; ?>
</td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>

<?php endif; ?>
</div>

</main>
<?php
    page_footer();
    exit;
}

/* =========================================================
 * 16. 送信画面
 * ========================================================= */

if ($screen === 'send') {
    $survey = find_survey($id);

    if ($survey === null) {
        redirect_screen('list');
    }

    $customers = get_customers();
    $logs = array_values(array_filter(
        get_send_logs(),
        static fn(array $log): bool =>
            ($log['survey_id'] ?? '') === $id
    ));

    page_header('顧客選択・メール送信', 'list');
    ?>
<main class="container">

<div class="page-title">
<h1>顧客選択・メール送信</h1>
<a class="btn btn-secondary" href="?screen=list">
    ← 一覧へ
</a>
</div>

<div class="card">
<h2>対象アンケート</h2>
<p>
<strong><?= h($survey['title']) ?></strong>
</p>
<div class="notice notice-info">
この画面の対象アンケートは固定されています。
</div>
</div>

<div class="card">
<h2>顧客選択</h2>

<?php if (!$customers): ?>
<div class="notice notice-warning">
顧客データがありません。
先にkintone画面から「顧客情報を同期」を実行してください。
</div>
<?php else: ?>

<form method="post">
<input type="hidden" name="action" value="send_mail">
<input type="hidden" name="survey_id" value="<?= h($id) ?>">

<div class="field">
<label>検索</label>
<input
    type="text"
    id="customer-search"
    placeholder="氏名・会社名・メールアドレス"
>
</div>

<div class="table-wrap">
<table id="customer-table">
<thead>
<tr>
<th>選択</th>
<th>組織名</th>
<th>氏名</th>
<th>メールアドレス</th>
<th>部署</th>
<th>電話番号</th>
</tr>
</thead>
<tbody>
<?php foreach ($customers as $customer): ?>
<tr>
<td>
<input
    type="checkbox"
    name="customers[]"
    value="<?= h($customer['id']) ?>"
>
</td>
<td><?= h($customer['organization']) ?></td>
<td><?= h($customer['name']) ?></td>
<td><?= h($customer['email']) ?></td>
<td><?= h($customer['department']) ?></td>
<td><?= h($customer['phone']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="field" style="margin-top:18px">
<label>件名</label>
<input
    type="text"
    name="subject"
    value="<?= h($survey['title']) ?>"
>
</div>

<div class="field">
<label>本文</label>
<textarea name="body">{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}
</textarea>
</div>

<div class="notice notice-warning">
現在の構成ではSMTP設定を行ったうえで実際のSMTPサーバへ接続して送信します。
</div>

<button
    class="btn btn-primary"
    type="submit"
    data-confirm="選択した顧客へメールを送信します。よろしいですか？"
>
一括送信
</button>

</form>

<?php endif; ?>
</div>

<div class="card">
<h2>送信履歴</h2>

<?php if (!$logs): ?>
<div class="empty">
送信履歴はありません。
</div>
<?php else: ?>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>顧客</th>
<th>結果</th>
</tr>
</thead>
<tbody>
<?php foreach ($logs as $log): ?>
<tr>
<td><?= h($log['createdAt'] ?? '') ?></td>
<td><?= h($log['customer'] ?? '') ?></td>
<td>
<span class="status <?= !empty($log['ok'])
    ? 'success'
    : 'danger' ?>">
<?= !empty($log['ok']) ? '成功' : '失敗' ?>
</span>
<?= h($log['message'] ?? '') ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php endif; ?>
</div>

</main>

<script>
const customerSearch =
    document.getElementById('customer-search');

if (customerSearch) {
    customerSearch.addEventListener('input',function(){
        const keyword = this.value.toLowerCase();

        document.querySelectorAll(
            '#customer-table tbody tr'
        ).forEach(function(row){
            row.style.display =
                row.innerText.toLowerCase().includes(keyword)
                    ? ''
                    : 'none';
        });
    });
}
</script>
<?php
    page_footer();
    exit;
}

/* =========================================================
 * 17. メール設定
 * ========================================================= */

if ($screen === 'mail') {
    page_header('メールサーバ設定', 'mail');
    ?>
<main class="container">

<div class="page-title">
<h1>メールサーバ設定</h1>
<a class="btn btn-secondary" href="?screen=list">
    ← アンケート一覧
</a>
</div>

<div class="card">
<form method="post">
<input type="hidden" name="action" value="save_mail">

<div class="form-row">

<div class="field">
<label>SMTPサーバ</label>
<input type="text" name="server"
       value="<?= h($settings['mail']['server']) ?>">
</div>

<div class="field">
<label>SMTPポート</label>
<input type="number" name="port"
       value="<?= h($settings['mail']['port']) ?>">
</div>

<div class="field">
<label>暗号化方式</label>
<select name="encryption">
<option value="ssl"
    <?= $settings['mail']['encryption'] === 'ssl'
        ? 'selected' : '' ?>>SSL</option>
<option value="tls"
    <?= $settings['mail']['encryption'] === 'tls'
        ? 'selected' : '' ?>>TLS</option>
<option value="none"
    <?= $settings['mail']['encryption'] === 'none'
        ? 'selected' : '' ?>>なし</option>
</select>
</div>

<div class="field">
<label>SMTP認証</label>
<label style="font-weight:normal">
<input type="checkbox" name="auth" value="1"
    <?= !empty($settings['mail']['auth'])
        ? 'checked' : '' ?>>
認証を使用する
</label>
</div>

<div class="field">
<label>SMTPユーザー名</label>
<input type="text" name="username"
       value="<?= h($settings['mail']['username']) ?>">
</div>

<div class="field">
<label>SMTPパスワード</label>
<input type="password" name="password"
       autocomplete="new-password">
</div>

<div class="field">
<label>送信元メールアドレス</label>
<input type="email" name="from_email"
       value="<?= h($settings['mail']['from_email']) ?>">
</div>

<div class="field">
<label>送信元名</label>
<input type="text" name="from_name"
       value="<?= h($settings['mail']['from_name']) ?>">
</div>

<div class="field">
<label>返信先メールアドレス</label>
<input type="email" name="reply_to"
       value="<?= h($settings['mail']['reply_to']) ?>">
</div>

</div>

<div class="actions">
<button class="btn btn-primary" type="submit">
    設定保存
</button>
<button
    class="btn btn-secondary"
    type="button"
    onclick="alert('SMTP接続テストはSMTP通信処理を設定後に実行できます。')"
>
    接続テスト
</button>
</div>

</form>
</div>

</main>
<?php
    page_footer();
    exit;
}

/* =========================================================
 * 18. 未知screen
 * ========================================================= */

redirect_screen('list');
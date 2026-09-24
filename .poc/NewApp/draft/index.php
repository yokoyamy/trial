<?php
declare(strict_types=1);

namespace yokoyamy\trial\newapp;

use RuntimeException;

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax'
]);

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

const APP_SESSION_KEY = 'yokoyamy_trial_newapp';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const CUSTOMERS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';
const SURVEYS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';

/**
 * NULL安全HTMLエスケープ
 */
function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * アプリ固有セッション領域
 */
function app_session(): array
{
    if (!isset($_SESSION[APP_SESSION_KEY]) || !is_array($_SESSION[APP_SESSION_KEY])) {
        $_SESSION[APP_SESSION_KEY] = [];
    }

    return $_SESSION[APP_SESSION_KEY];
}

/**
 * CSRFトークン取得・発行
 */
function csrf_token(): string
{
    if (
        !isset($_SESSION[APP_SESSION_KEY]['csrf_token']) ||
        !is_string($_SESSION[APP_SESSION_KEY]['csrf_token']) ||
        $_SESSION[APP_SESSION_KEY]['csrf_token'] === ''
    ) {
        $_SESSION[APP_SESSION_KEY]['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION[APP_SESSION_KEY]['csrf_token'];
}

/**
 * CSRF検証
 */
function verify_csrf(): void
{
    $sessionToken = $_SESSION[APP_SESSION_KEY]['csrf_token'] ?? '';
    $requestToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        !is_string($sessionToken) ||
        !is_string($requestToken) ||
        $sessionToken === '' ||
        $requestToken === '' ||
        !hash_equals($sessionToken, $requestToken)
    ) {
        send_json([
            'success' => false,
            'message' => '不正なリクエストです。画面を再読み込みしてから再度お試しください。'
        ], 403);
    }
}

/**
 * JSONファイル読み込み
 */
function read_json_file(string $file, array $default): array
{
    if (!is_file($file)) {
        return $default;
    }

    $content = @file_get_contents($file);

    if ($content === false || trim($content) === '') {
        return $default;
    }

    $data = json_decode($content, true);

    if (!is_array($data)) {
        return $default;
    }

    return $data;
}

/**
 * JSONファイル保存
 */
function write_json_file(string $file, array $data): bool
{
    if (!is_dir(DATA_DIR)) {
        if (!@mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            return false;
        }
    }

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

    $tmp = $file . '.tmp';

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/**
 * 初期設定
 */
function default_settings(): array
{
    return [
        'mail' => [
            'smtp' => '',
            'port' => '587',
            'security' => 'STARTTLS',
            'username' => '',
            'password' => '',
            'from' => '',
            'fromName' => 'アンケート事務局',
            'ready' => false
        ],
        'kintone' => [
            'domain' => '',
            'appId' => '',
            'loginName' => '',
            'password' => '',
            'proxyHost' => '',
            'proxyPort' => '',
            'proxyAuth' => false,
            'sslVerify' => false,
            'ready' => false
        ]
    ];
}

/**
 * settings.jsonを必ず存在させる。
 *
 * 「なかったら作って」という要件への対応。
 */
function load_settings(): array
{
    $defaults = default_settings();

    if (!is_file(SETTINGS_FILE)) {
        write_json_file(SETTINGS_FILE, $defaults);
        return $defaults;
    }

    $settings = read_json_file(SETTINGS_FILE, $defaults);

    $mail = isset($settings['mail']) && is_array($settings['mail'])
        ? $settings['mail']
        : [];

    $kintone = isset($settings['kintone']) && is_array($settings['kintone'])
        ? $settings['kintone']
        : [];

    $settings['mail'] = array_merge($defaults['mail'], $mail);
    $settings['kintone'] = array_merge($defaults['kintone'], $kintone);

    return $settings;
}

/**
 * メール設定が利用可能か判定
 */
function is_mail_ready(array $mail): bool
{
    return trim((string)($mail['smtp'] ?? '')) !== ''
        && trim((string)($mail['port'] ?? '')) !== ''
        && trim((string)($mail['from'] ?? '')) !== '';
}

/**
 * Kintone URL
 */
function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain);
    $domain = rtrim((string)$domain, '/');

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

/**
 * 安全なレスポンスヘッダー取得
 */
function get_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
        return is_array($headers) ? $headers : [];
    }

    return [];
}

/**
 * kintone認証ヘッダー
 */
function make_cybozu_auth_header(string $loginName, string $password): string
{
    $loginName = trim($loginName);
    $password = trim($password);

    return 'X-Cybozu-Authorization: ' .
        base64_encode($loginName . ':' . $password);
}

/**
 * kintone API通信
 */
function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload,
    array $config
): array {
    $method = strtoupper($method);

    $httpOptions = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 15
    ];

    if ($method !== 'GET' && $payload !== null) {
        $encoded = is_array($payload)
            ? json_encode($payload, JSON_UNESCAPED_UNICODE)
            : (string)$payload;

        if ($encoded === false) {
            return [
                'success' => false,
                'status' => 0,
                'message' => '送信データを作成できませんでした。'
            ];
        }

        $httpOptions['content'] = $encoded;
    }

    $contextOptions = [
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];

    $proxyHostPort = trim((string)($config['proxy_host_port'] ?? ''));

    /*
     * プロキシ指定がある場合は必ずproxy/request_fulluriを設定。
     */
    if ($proxyHostPort !== '') {
        $contextOptions['http']['proxy'] = 'tcp://' . $proxyHostPort;
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $responseBody = @file_get_contents($url, false, $context);
    $responseHeaders = get_safe_response_headers();

    $statusCode = 500;

    foreach ($responseHeaders as $header) {
        if (preg_match('/HTTP\/\d\.\d\s+(\d+)/i', $header, $matches)) {
            $statusCode = (int)$matches[1];
        }
    }

    $resultData = json_decode(
        $responseBody === false ? '' : $responseBody,
        true
    );

    if ($statusCode >= 200 && $statusCode < 300) {
        return [
            'success' => true,
            'status' => $statusCode,
            'data' => is_array($resultData) ? $resultData : []
        ];
    }

    $message = 'kintone API 通信エラーが発生しました。';

    if (is_array($resultData) && isset($resultData['message'])) {
        $message = (string)$resultData['message'];
    }

    return [
        'success' => false,
        'status' => $statusCode,
        'message' => $message
    ];
}

/**
 * JSONレスポンス
 */
function send_json(array $response, int $statusCode = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * 設定保存API
 */
function save_settings_request(): void
{
    verify_csrf();

    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        send_json([
            'success' => false,
            'message' => '保存する設定がありません。'
        ], 400);
    }

    $input = json_decode($raw, true);

    if (!is_array($input)) {
        send_json([
            'success' => false,
            'message' => '設定データが正しくありません。'
        ], 400);
    }

    $settings = load_settings();

    if (isset($input['mail']) && is_array($input['mail'])) {
        $oldPassword = (string)($settings['mail']['password'] ?? '');

        $settings['mail']['smtp'] =
            trim((string)($input['mail']['smtp'] ?? ''));

        $settings['mail']['port'] =
            trim((string)($input['mail']['port'] ?? ''));

        $settings['mail']['security'] =
            trim((string)($input['mail']['security'] ?? 'STARTTLS'));

        $settings['mail']['username'] =
            trim((string)($input['mail']['username'] ?? ''));

        /*
         * パスワード未入力の場合は既存値を維持。
         */
        $newPassword = (string)($input['mail']['password'] ?? '');

        $settings['mail']['password'] =
            $newPassword !== '' ? $newPassword : $oldPassword;

        $settings['mail']['from'] =
            trim((string)($input['mail']['from'] ?? ''));

        $settings['mail']['fromName'] =
            trim((string)($input['mail']['fromName'] ?? 'アンケート事務局'));

        $settings['mail']['ready'] =
            is_mail_ready($settings['mail']);
    }

    if (isset($input['kintone']) && is_array($input['kintone'])) {
        $oldPassword = (string)($settings['kintone']['password'] ?? '');

        $settings['kintone']['domain'] =
            trim((string)($input['kintone']['domain'] ?? ''));

        $settings['kintone']['appId'] =
            trim((string)($input['kintone']['appId'] ?? ''));

        $settings['kintone']['loginName'] =
            trim((string)($input['kintone']['loginName'] ?? ''));

        $newPassword = (string)($input['kintone']['password'] ?? '');

        $settings['kintone']['password'] =
            $newPassword !== '' ? $newPassword : $oldPassword;

        $settings['kintone']['proxyHost'] =
            trim((string)($input['kintone']['proxyHost'] ?? ''));

        $settings['kintone']['proxyPort'] =
            trim((string)($input['kintone']['proxyPort'] ?? ''));

        $settings['kintone']['proxyAuth'] = false;
        $settings['kintone']['sslVerify'] = false;

        $settings['kintone']['ready'] =
            $settings['kintone']['domain'] !== ''
            && $settings['kintone']['appId'] !== ''
            && $settings['kintone']['loginName'] !== ''
            && $settings['kintone']['password'] !== '';
    }

    if (!write_json_file(SETTINGS_FILE, $settings)) {
        send_json([
            'success' => false,
            'message' => '設定ファイルを保存できませんでした。dataフォルダの書き込み権限を確認してください。'
        ], 500);
    }

    send_json([
        'success' => true,
        'message' => '設定を保存しました。'
    ]);
}

/**
 * メール設定確認API
 *
 * 実際の送信を行わず、設定が存在するかをサーバー側で確認する。
 */
function check_mail_settings_request(): void
{
    verify_csrf();

    $settings = load_settings();
    $mail = $settings['mail'];

    if (!is_mail_ready($mail)) {
        send_json([
            'success' => false,
            'message' => 'メール送信設定が未完了です。'
        ], 400);
    }

    send_json([
        'success' => true,
        'message' => 'メール送信設定を読み込みました。SMTP設定が登録されています。',
        'mail' => [
            'smtp' => (string)$mail['smtp'],
            'port' => (string)$mail['port'],
            'security' => (string)$mail['security'],
            'from' => (string)$mail['from'],
            'fromName' => (string)$mail['fromName']
        ]
    ]);
}

/**
 * 初期データ
 */
$settings = load_settings();

$customers = read_json_file(
    CUSTOMERS_FILE,
    [
        [
            'id' => 1,
            'name' => '山田 太郎',
            'email' => 'taro.yamada@example.com',
            'company' => '株式会社サンプル',
            'code' => 'C0001'
        ],
        [
            'id' => 2,
            'name' => '佐藤 花子',
            'email' => 'hanako.sato@example.com',
            'company' => '株式会社サンプル',
            'code' => 'C0002'
        ],
        [
            'id' => 3,
            'name' => '鈴木 一郎',
            'email' => 'ichiro.suzuki@example.com',
            'company' => '株式会社テスト',
            'code' => 'C0003'
        ]
    ]
);

$surveys = read_json_file(
    SURVEYS_FILE,
    [
        [
            'id' => 1,
            'name' => '新商品アンケート',
            'description' => '新商品の利用状況とご意見をお聞きするアンケートです。',
            'status' => 'open',
            'created' => date('Y-m-d'),
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('+30 days')),
            'answers' => 0,
            'target' => count($customers),
            'sent' => 0,
            'updated' => date('Y-m-d'),
            'numbering' => 'global',
            'groups' => [
                [
                    'id' => 101,
                    'name' => 'ご利用状況',
                    'questions' => [
                        [
                            'id' => 1001,
                            'text' => '当社の商品を利用したことがありますか？',
                            'type' => 'single',
                            'required' => true,
                            'options' => [
                                ['text' => 'はい', 'branch' => ''],
                                ['text' => 'いいえ', 'branch' => '']
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ]
);

/**
 * API処理
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        save_settings_request();
    }

    if ($action === 'check_mail') {
        check_mail_settings_request();
    }
}

$mailForClient = [
    'smtp' => (string)($settings['mail']['smtp'] ?? ''),
    'port' => (string)($settings['mail']['port'] ?? '587'),
    'security' => (string)($settings['mail']['security'] ?? 'STARTTLS'),
    'username' => (string)($settings['mail']['username'] ?? ''),
    'from' => (string)($settings['mail']['from'] ?? ''),
    'fromName' => (string)($settings['mail']['fromName'] ?? 'アンケート事務局'),
    'ready' => is_mail_ready($settings['mail'])
];

$kintoneForClient = [
    'domain' => (string)($settings['kintone']['domain'] ?? ''),
    'appId' => (string)($settings['kintone']['appId'] ?? ''),
    'loginName' => (string)($settings['kintone']['loginName'] ?? ''),
    'proxyHost' => (string)($settings['kintone']['proxyHost'] ?? ''),
    'proxyPort' => (string)($settings['kintone']['proxyPort'] ?? ''),
    'proxyAuth' => false,
    'sslVerify' => false,
    'ready' => !empty($settings['kintone']['ready'])
];

$csrf = csrf_token();

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>アンケート業務運営</title>
<style>
*{box-sizing:border-box}
body{
    margin:0;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Yu Gothic",Meiryo,sans-serif;
    color:#263238;
    background:#f4f6f8
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
button:disabled{cursor:not-allowed;opacity:.55}
.topbar{
    height:60px;
    background:#1f3a5f;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
    gap:30px
}
.logo{font-size:18px;font-weight:bold;white-space:nowrap}
.main-nav{display:flex;height:100%;align-items:center;gap:2px}
.main-nav button{
    height:100%;
    padding:0 17px;
    color:#dce7f3;
    background:transparent;
    border:0
}
.main-nav button:hover,
.main-nav button.active{background:#31557f;color:#fff}
.app{max-width:1440px;margin:0 auto;padding:24px}
.hidden{display:none!important}
.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:20px
}
.page-header h1{margin:0;font-size:25px}
.subtext{color:#718096;font-size:13px;margin-top:5px}
.btn{
    border:1px solid #cbd5e0;
    background:#fff;
    color:#34495e;
    border-radius:5px;
    padding:8px 15px
}
.btn-primary{background:#2878c8;border-color:#2878c8;color:#fff}
.btn-danger{border-color:#e05a5a;color:#c53f3f}
.btn-success{background:#2f855a;border-color:#2f855a;color:#fff}
.btn-small{padding:5px 10px;font-size:12px}
.card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:20px;
    margin-bottom:18px
}
.card-title{font-size:17px;font-weight:bold;margin-bottom:15px}
.table{width:100%;border-collapse:collapse}
.table th,.table td{
    padding:12px 10px;
    border-bottom:1px solid #e6ebef;
    text-align:left;
    vertical-align:middle;
    font-size:13px
}
.table th{background:#f8fafc;color:#52606d}
.empty{text-align:center!important;color:#8a98a5;padding:40px!important}
.link-button{
    border:0;
    background:none;
    padding:0;
    color:#2878c8;
    cursor:pointer;
    text-align:left
}
.badge{
    display:inline-block;
    padding:4px 9px;
    border-radius:12px;
    font-size:11px;
    font-weight:bold
}
.badge-open{background:#e6f6ed;color:#237a49}
.badge-draft{background:#edf2f7;color:#66788a}
.badge-end{background:#fdecec;color:#b43b3b}
.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px
}
.field{margin-bottom:15px}
.field label{
    display:block;
    font-size:13px;
    font-weight:bold;
    margin-bottom:6px;
    color:#455563
}
.field input,
.field textarea,
.field select{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:9px 10px;
    background:#fff
}
.field textarea{min-height:100px;resize:vertical}
.notice{
    padding:11px 13px;
    border-radius:5px;
    background:#edf6ff;
    border:1px solid #c9e2fa;
    color:#2b5f8a;
    font-size:13px;
    margin-bottom:15px
}
.notice.success{background:#edf9f1;border-color:#c9ead5;color:#267348}
.notice.warning{background:#fff8e6;border-color:#f0dfae;color:#8a6408}
.status-line{
    display:flex;
    align-items:center;
    gap:8px;
    padding:10px 12px;
    background:#f7f9fb;
    border-radius:5px;
    margin-bottom:15px
}
.status-dot{
    width:9px;
    height:9px;
    border-radius:50%;
    background:#9aa7b3
}
.status-dot.ok{background:#2f9e61}
.status-dot.warn{background:#d39b25}
.detail-tabs,
.settings-tabs{
    display:flex;
    gap:5px;
    margin-bottom:18px;
    border-bottom:1px solid #dfe5eb
}
.detail-tabs button,
.settings-tabs button{
    border:0;
    background:#fff;
    padding:10px 16px
}
.detail-tabs button.active,
.settings-tabs button.active{
    background:#2878c8;
    color:#fff
}
.detail-summary{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    margin-bottom:18px
}
.stat-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:17px
}
.stat-label{color:#718096;font-size:12px}
.stat-value{font-size:27px;font-weight:bold;margin-top:5px}
.progress{
    height:10px;
    background:#e8edf2;
    border-radius:5px;
    overflow:hidden;
    margin-top:8px
}
.progress span{display:block;height:100%;background:#4285c5}
.customer-toolbar{
    display:flex;
    gap:8px;
    margin-bottom:12px
}
.customer-toolbar input{
    flex:1;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:8px
}
.question{
    padding:15px;
    border-bottom:1px solid #e6ebef
}
.question:last-child{border-bottom:0}
.question-head{
    display:flex;
    gap:10px;
    align-items:center
}
.question-head input{
    flex:1;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:8px
}
.option-row{
    display:flex;
    gap:8px;
    margin-top:8px
}
.option-row input{
    flex:1;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:7px
}
.modal-backdrop{
    position:fixed;
    inset:0;
    background:rgba(20,35,50,.45);
    display:flex;
    align-items:center;
    justify-content:center;
    z-index:1000
}
.modal{
    width:min(760px,calc(100% - 30px));
    max-height:90vh;
    overflow:auto;
    background:#fff;
    border-radius:8px
}
.modal-header,
.modal-footer{
    padding:15px 20px;
    display:flex;
    justify-content:space-between;
    border-bottom:1px solid #e3e8ed
}
.modal-footer{
    border-top:1px solid #e3e8ed;
    border-bottom:0;
    justify-content:flex-end;
    gap:8px
}
.modal-body{padding:20px}
.toast{
    position:fixed;
    right:25px;
    bottom:25px;
    background:#263238;
    color:#fff;
    padding:12px 18px;
    border-radius:5px;
    opacity:0;
    transform:translateY(10px);
    transition:.2s;
    z-index:2000
}
.toast.show{opacity:1;transform:translateY(0)}
.loading::before{
    content:"";
    display:inline-block;
    width:13px;
    height:13px;
    margin-right:7px;
    border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite;
    vertical-align:-2px
}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:900px){
    .form-grid,
    .detail-summary{grid-template-columns:1fr}
    .topbar{padding:0 10px;gap:8px}
    .main-nav button{padding:0 8px;font-size:12px}
    .app{padding:14px}
}
</style>
</head>
<body>

<header class="topbar">
    <div class="logo">アンケート業務運営</div>
    <nav class="main-nav">
        <button type="button" id="nav-list">アンケート一覧</button>
        <button type="button" id="nav-create">アンケート作成</button>
        <button type="button" id="nav-customers">顧客一覧</button>
        <button type="button" id="nav-settings">設定</button>
    </nav>
</header>

<main class="app">

<section id="page-list">
    <div class="page-header">
        <div>
            <h1>アンケート一覧</h1>
            <div class="subtext">作成済みのアンケートを管理します</div>
        </div>
        <button type="button" class="btn btn-primary" id="btn-create">＋ アンケート作成</button>
    </div>

    <div class="card">
        <table class="table">
            <thead>
            <tr>
                <th>アンケート名</th>
                <th>状態</th>
                <th>作成日</th>
                <th>公開期間</th>
                <th>回答数</th>
                <th>最終更新日</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody id="survey-list-body"></tbody>
        </table>
    </div>
</section>

<section id="page-editor" class="hidden">
    <div class="page-header">
        <div>
            <h1 id="editor-title">アンケート作成</h1>
            <div class="subtext">アンケート全体を確認しながら編集できます</div>
        </div>
    </div>

    <div class="card">
        <div class="form-grid">
            <div class="field">
                <label>アンケート名 *</label>
                <input id="survey-name">
            </div>
            <div class="field">
                <label>公開状態</label>
                <select id="survey-status">
                    <option value="draft">下書き</option>
                    <option value="open">公開中</option>
                    <option value="end">終了</option>
                </select>
            </div>
        </div>

        <div class="field">
            <label>説明</label>
            <textarea id="survey-description"></textarea>
        </div>

        <div class="form-grid">
            <div class="field">
                <label>公開開始日</label>
                <input id="survey-start" type="date">
            </div>
            <div class="field">
                <label>公開終了日</label>
                <input id="survey-end" type="date">
            </div>
        </div>
    </div>

    <div id="editor-questions"></div>

    <div style="display:flex;justify-content:space-between;margin-top:15px">
        <button type="button" class="btn" id="btn-editor-back">一覧へ戻る</button>
        <button type="button" class="btn btn-primary" id="btn-save-survey">保存</button>
    </div>
</section>

<section id="page-detail" class="hidden">
    <div class="page-header">
        <div>
            <h1 id="detail-title"></h1>
            <div class="subtext" id="detail-subtitle"></div>
        </div>
        <div>
            <button type="button" class="btn" id="btn-detail-edit">編集</button>
            <button type="button" class="btn btn-primary" id="btn-detail-send">送信</button>
            <button type="button" class="btn" id="btn-detail-back">一覧へ戻る</button>
        </div>
    </div>

    <div class="detail-tabs">
        <button type="button" data-detail-tab="content">アンケート内容</button>
        <button type="button" data-detail-tab="send">送信</button>
        <button type="button" data-detail-tab="status">回答状況</button>
        <button type="button" data-detail-tab="result">回答結果</button>
    </div>

    <div id="detail-content"></div>
</section>

<section id="page-customers" class="hidden">
    <div class="page-header">
        <div>
            <h1>顧客一覧</h1>
            <div class="subtext">顧客情報を確認します</div>
        </div>
    </div>

    <div id="customer-status"></div>

    <div class="card">
        <div class="customer-toolbar">
            <input id="customer-search" placeholder="顧客名・メールアドレスで検索">
        </div>
        <table class="table">
            <thead>
            <tr>
                <th>顧客名</th>
                <th>メールアドレス</th>
                <th>会社名</th>
                <th>顧客番号</th>
            </tr>
            </thead>
            <tbody id="customer-body"></tbody>
        </table>
    </div>
</section>

<section id="page-settings" class="hidden">
    <div class="page-header">
        <div>
            <h1>設定</h1>
            <div class="subtext">メール送信と顧客情報取得に必要な設定を管理します</div>
        </div>
    </div>

    <div class="settings-tabs">
        <button type="button" data-settings-tab="mail">メール送信設定</button>
        <button type="button" data-settings-tab="kintone">キントーン設定</button>
    </div>

    <div id="settings-content"></div>
</section>

</main>

<div id="modal" class="modal-backdrop hidden">
    <div class="modal">
        <div class="modal-header">
            <strong id="modal-title"></strong>
            <button type="button" class="btn btn-small" id="btn-modal-close">閉じる</button>
        </div>
        <div class="modal-body" id="modal-body"></div>
        <div class="modal-footer" id="modal-footer"></div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    'use strict';

    const CSRF_TOKEN = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;

    let surveys = <?= json_encode(
        $surveys,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    ) ?>;

    let customers = <?= json_encode(
        $customers,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    ) ?>;

    /*
     * ここが今回の修正箇所。
     *
     * data/settings.jsonをPHP側で読み込み、
     * その結果を初期状態として画面へ渡している。
     *
     * そのため、ブラウザからsettings.jsonをfetchする必要がなく、
     * 「Fail to Fetch」でメール設定が未設定扱いになることを防止する。
     */
    let mailSettings = <?= json_encode(
        $mailForClient,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    ) ?>;

    let kintoneSettings = <?= json_encode(
        $kintoneForClient,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    ) ?>;

    let currentPage = 'page-list';
    let currentSurveyId = null;
    let currentSettingsTab = 'mail';

    const $ = function (id) {
        return document.getElementById(id);
    };

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setLoading(button, loading) {
        if (!button) {
            return;
        }

        if (loading) {
            button.disabled = true;
            button.classList.add('loading');
        } else {
            button.disabled = false;
            button.classList.remove('loading');
        }
    }

    async function postJson(action, data, button) {
        /*
         * 非同期通信開始前に必ず即時disabled + loading。
         */
        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({
                    action: action,
                    ...data
                })
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const result = await response.json();

            if (!result || typeof result !== 'object') {
                throw new Error('Invalid response');
            }

            return result;

        } catch (error) {
            /*
             * 「Fail to Fetch」をそのまま画面に出さず、
             * 利用者が状況を理解できる日本語にする。
             */
            return {
                success: false,
                message: 'サーバーとの通信に失敗しました。画面を再読み込みして再度お試しください。'
            };

        } finally {
            if (button) {
                button.disabled = false;
                button.classList.remove('loading');
            }
        }
    }

    function showPage(id) {
        [
            'page-list',
            'page-editor',
            'page-detail',
            'page-customers',
            'page-settings'
        ].forEach(function (pageId) {
            const element = $(pageId);

            if (element) {
                element.classList.add('hidden');
            }
        });

        const target = $(id);

        if (target) {
            target.classList.remove('hidden');
            currentPage = id;
        }

        [
            'nav-list',
            'nav-create',
            'nav-customers',
            'nav-settings'
        ].forEach(function (navId) {
            const element = $(navId);

            if (element) {
                element.classList.remove('active');
            }
        });

        if (id === 'page-list' && $('nav-list')) {
            $('nav-list').classList.add('active');
        }

        if (id === 'page-editor' && $('nav-create')) {
            $('nav-create').classList.add('active');
        }

        if (id === 'page-customers' && $('nav-customers')) {
            $('nav-customers').classList.add('active');
        }

        if (id === 'page-settings' && $('nav-settings')) {
            $('nav-settings').classList.add('active');
        }
    }

    function statusBadge(status) {
        if (status === 'open') {
            return '<span class="badge badge-open">公開中</span>';
        }

        if (status === 'end') {
            return '<span class="badge badge-end">終了</span>';
        }

        return '<span class="badge badge-draft">下書き</span>';
    }

    function renderList() {
        const body = $('survey-list-body');

        if (!body) {
            return;
        }

        if (!Array.isArray(surveys) || surveys.length === 0) {
            body.innerHTML =
                '<tr><td colspan="7" class="empty">アンケートがありません。</td></tr>';
            return;
        }

        body.innerHTML = surveys.map(function (survey) {

            const period =
                survey.start || survey.end
                    ? escapeHtml(survey.start || '未設定') +
                      ' ～ ' +
                      escapeHtml(survey.end || '未設定')
                    : '未設定';

            return '<tr>' +
                '<td>' +
                    '<button type="button" class="link-button" data-open-survey="' +
                    escapeHtml(String(survey.id)) +
                    '">' +
                    escapeHtml(survey.name) +
                    '</button>' +
                '</td>' +
                '<td>' + statusBadge(survey.status) + '</td>' +
                '<td>' + escapeHtml(survey.created) + '</td>' +
                '<td>' + period + '</td>' +
                '<td>' + escapeHtml(String(survey.answers || 0)) + '件</td>' +
                '<td>' + escapeHtml(survey.updated) + '</td>' +
                '<td>' +
                    '<button type="button" class="btn btn-small" data-edit-survey="' +
                    escapeHtml(String(survey.id)) +
                    '">編集</button> ' +
                    '<button type="button" class="btn btn-small" data-open-survey="' +
                    escapeHtml(String(survey.id)) +
                    '">確認</button>' +
                '</td>' +
            '</tr>';
        }).join('');
    }

    function getCurrentSurvey() {
        return surveys.find(function (survey) {
            return Number(survey.id) === Number(currentSurveyId);
        }) || null;
    }

    function countQuestions(survey) {
        if (!survey || !Array.isArray(survey.groups)) {
            return 0;
        }

        return survey.groups.reduce(function (total, group) {
            return total +
                (Array.isArray(group.questions)
                    ? group.questions.length
                    : 0);
        }, 0);
    }

    function openDetail(id) {
        currentSurveyId = Number(id);

        const survey = getCurrentSurvey();

        if (!survey) {
            return;
        }

        const title = $('detail-title');
        const subtitle = $('detail-subtitle');

        if (title) {
            title.textContent = survey.name || '';
        }

        if (subtitle) {
            subtitle.textContent =
                (survey.status === 'open'
                    ? '公開中'
                    : survey.status === 'end'
                        ? '終了'
                        : '下書き');
        }

        showPage('page-detail');
        renderDetailTab('content');
    }

    function renderDetailTab(tab) {
        const survey = getCurrentSurvey();
        const target = $('detail-content');

        if (!survey || !target) {
            return;
        }

        document.querySelectorAll('[data-detail-tab]').forEach(function (button) {
            button.classList.toggle(
                'active',
                button.getAttribute('data-detail-tab') === tab
            );
        });

        if (tab === 'send') {
            renderSend(survey);
            return;
        }

        if (tab === 'status') {
            renderStatus(survey);
            return;
        }

        if (tab === 'result') {
            renderResult(survey);
            return;
        }

        let html =
            '<div class="card">' +
            '<div class="card-title">アンケート内容</div>' +
            '<p>' + escapeHtml(survey.description || '') + '</p>';

        (survey.groups || []).forEach(function (group) {
            html +=
                '<h3>' + escapeHtml(group.name || '') + '</h3>';

            (group.questions || []).forEach(function (question, index) {
                html +=
                    '<div class="question">' +
                    '<div class="question-head">' +
                    '<strong>Q' + (index + 1) + '</strong>' +
                    '<span>' + escapeHtml(question.text || '') + '</span>' +
                    '</div>';

                (question.options || []).forEach(function (option) {
                    html +=
                        '<div style="margin:6px 0;color:#657786">' +
                        '・' + escapeHtml(option.text || '') +
                        '</div>';
                });

                html += '</div>';
            });
        });

        html += '</div>';

        target.innerHTML = html;
    }

    function renderSend(survey) {
        const target = $('detail-content');

        if (!target) {
            return;
        }

        const readyText = mailSettings.ready
            ? '<div class="notice success">メール送信設定は読み込み済みです。</div>'
            : '<div class="notice warning">メール送信設定が未完了です。設定画面を確認してください。</div>';

        target.innerHTML =
            readyText +
            '<div class="card">' +
                '<div class="card-title">メール送信</div>' +
                '<p>アンケート：' +
                    escapeHtml(survey.name) +
                '</p>' +
                '<p>送信元：' +
                    escapeHtml(mailSettings.fromName) +
                    ' &lt;' +
                    escapeHtml(mailSettings.from) +
                    '&gt;' +
                '</p>' +
                '<div class="notice">' +
                    '保存済みのメール設定を使用して送信します。' +
                '</div>' +
                '<button type="button" class="btn btn-primary" id="btn-check-mail-send">' +
                    'メール設定を確認' +
                '</button>' +
            '</div>';

        const button = $('btn-check-mail-send');

        if (button) {
            button.addEventListener('click', async function () {

                const result = await postJson(
                    'check_mail',
                    {},
                    button
                );

                if (result.success) {
                    showToast(result.message || 'メール設定を確認しました。');
                } else {
                    showToast(result.message || 'メール設定を確認できませんでした。');
                }
            });
        }
    }

    function renderStatus(survey) {
        const target = $('detail-content');

        if (!target) {
            return;
        }

        const answers = Number(survey.answers || 0);
        const targetCount = Number(survey.target || 0);
        const rate = targetCount
            ? Math.round(answers / targetCount * 100)
            : 0;

        target.innerHTML =
            '<div class="detail-summary">' +
                '<div class="stat-card"><div class="stat-label">回答数</div><div class="stat-value">' +
                    answers +
                '</div></div>' +
                '<div class="stat-card"><div class="stat-label">回答率</div><div class="stat-value">' +
                    rate +
                    '%</div></div>' +
                '<div class="stat-card"><div class="stat-label">未回答数</div><div class="stat-value">' +
                    Math.max(targetCount - answers, 0) +
                '</div></div>' +
                '<div class="stat-card"><div class="stat-label">送信済み</div><div class="stat-value">' +
                    Number(survey.sent || 0) +
                '</div></div>' +
            '</div>' +
            '<div class="card">' +
                '<div class="card-title">回答状況</div>' +
                '<p>公開期間：' +
                    escapeHtml(survey.start || '未設定') +
                    ' ～ ' +
                    escapeHtml(survey.end || '未設定') +
                '</p>' +
                '<div class="progress"><span style="width:' +
                    Math.min(rate, 100) +
                    '%"></span></div>' +
            '</div>';
    }

    function renderResult(survey) {
        const target = $('detail-content');

        if (!target) {
            return;
        }

        let html =
            '<div class="detail-summary">' +
                '<div class="stat-card"><div class="stat-label">総回答数</div><div class="stat-value">' +
                    Number(survey.answers || 0) +
                '</div></div>' +
                '<div class="stat-card"><div class="stat-label">回答者数</div><div class="stat-value">' +
                    Number(survey.answers || 0) +
                '</div></div>' +
                '<div class="stat-card"><div class="stat-label">質問数</div><div class="stat-value">' +
                    countQuestions(survey) +
                '</div></div>' +
            '</div>';

        html += '<div class="card">';

        if (!Number(survey.answers || 0)) {
            html +=
                '<div class="empty">まだ回答結果がありません。</div>';
        } else {
            html += '<div class="card-title">回答結果</div>';

            (survey.groups || []).forEach(function (group) {
                html += '<h3>' + escapeHtml(group.name || '') + '</h3>';

                (group.questions || []).forEach(function (question) {
                    html +=
                        '<div class="question">' +
                        '<strong>' +
                        escapeHtml(question.text || '') +
                        '</strong>';

                    (question.options || []).forEach(function (option) {
                        html +=
                            '<div style="margin-top:8px">' +
                            escapeHtml(option.text || '') +
                            '</div>';
                    });

                    html += '</div>';
                });
            });
        }

        html += '</div>';

        target.innerHTML = html;
    }

    function showCustomers() {
        showPage('page-customers');
        renderCustomerStatus();
        renderCustomers();
    }

    function renderCustomerStatus() {
        const target = $('customer-status');

        if (!target) {
            return;
        }

        if (kintoneSettings.ready) {
            target.innerHTML =
                '<div class="notice success">顧客情報を利用できる設定が保存されています。</div>';
        } else {
            target.innerHTML =
                '<div class="notice warning">キントーン設定が未完了です。</div>';
        }
    }

    function renderCustomers() {
        const body = $('customer-body');

        if (!body) {
            return;
        }

        const searchElement = $('customer-search');
        const search = searchElement
            ? searchElement.value.toLowerCase()
            : '';

        const filtered = customers.filter(function (customer) {
            return !search ||
                String(customer.name || '').toLowerCase().includes(search) ||
                String(customer.email || '').toLowerCase().includes(search) ||
                String(customer.company || '').toLowerCase().includes(search) ||
                String(customer.code || '').toLowerCase().includes(search);
        });

        if (!filtered.length) {
            body.innerHTML =
                '<tr><td colspan="4" class="empty">該当する顧客がありません。</td></tr>';
            return;
        }

        body.innerHTML = filtered.map(function (customer) {
            return '<tr>' +
                '<td>' + escapeHtml(customer.name) + '</td>' +
                '<td>' + escapeHtml(customer.email) + '</td>' +
                '<td>' + escapeHtml(customer.company) + '</td>' +
                '<td>' + escapeHtml(customer.code) + '</td>' +
            '</tr>';
        }).join('');
    }

    function showSettings(tab) {
        if (tab) {
            currentSettingsTab = tab;
        }

        showPage('page-settings');
        renderSettings();
    }

    function renderSettings() {
        document.querySelectorAll('[data-settings-tab]').forEach(function (button) {
            button.classList.toggle(
                'active',
                button.getAttribute('data-settings-tab') === currentSettingsTab
            );
        });

        if (currentSettingsTab === 'kintone') {
            renderKintoneSettings();
        } else {
            renderMailSettings();
        }
    }

    function renderMailSettings() {
        const target = $('settings-content');

        if (!target) {
            return;
        }

        target.innerHTML =
            '<div class="card">' +
                '<div class="card-title">メール送信設定</div>' +

                '<div class="status-line">' +
                    '<span class="status-dot ' +
                    (mailSettings.ready ? 'ok' : 'warn') +
                    '"></span>' +
                    '<span>' +
                    (mailSettings.ready
                        ? '保存済みのメール設定を読み込みました。'
                        : 'メール送信設定が未完了です。') +
                    '</span>' +
                '</div>' +

                '<div class="notice">' +
                    '保存済みの設定は data/settings.json から読み込まれています。' +
                '</div>' +

                '<div class="form-grid">' +
                    '<div class="field">' +
                        '<label>SMTPサーバ *</label>' +
                        '<input id="smtp-server" value="' +
                        escapeHtml(mailSettings.smtp) +
                        '">' +
                    '</div>' +

                    '<div class="field">' +
                        '<label>ポート番号 *</label>' +
                        '<input id="smtp-port" value="' +
                        escapeHtml(mailSettings.port) +
                        '">' +
                    '</div>' +
                '</div>' +

                '<div class="field">' +
                    '<label>接続方式</label>' +
                    '<select id="smtp-security">' +
                        '<option value="なし"' +
                        (mailSettings.security === 'なし' ? ' selected' : '') +
                        '>なし</option>' +
                        '<option value="STARTTLS"' +
                        (mailSettings.security === 'STARTTLS' ? ' selected' : '') +
                        '>STARTTLS</option>' +
                        '<option value="SSL/TLS"' +
                        (mailSettings.security === 'SSL/TLS' ? ' selected' : '') +
                        '>SSL/TLS</option>' +
                    '</select>' +
                '</div>' +

                '<div class="form-grid">' +
                    '<div class="field">' +
                        '<label>認証ユーザー名</label>' +
                        '<input id="smtp-user" value="' +
                        escapeHtml(mailSettings.username) +
                        '">' +
                    '</div>' +

                    '<div class="field">' +
                        '<label>認証パスワード</label>' +
                        '<input id="smtp-password" type="password" placeholder="変更しない場合は空欄">' +
                    '</div>' +
                '</div>' +

                '<div class="form-grid">' +
                    '<div class="field">' +
                        '<label>送信元メールアドレス *</label>' +
                        '<input id="smtp-from" value="' +
                        escapeHtml(mailSettings.from) +
                        '">' +
                    '</div>' +

                    '<div class="field">' +
                        '<label>送信元名</label>' +
                        '<input id="smtp-from-name" value="' +
                        escapeHtml(mailSettings.fromName) +
                        '">' +
                    '</div>' +
                '</div>' +

                '<div style="display:flex;gap:8px">' +
                    '<button type="button" class="btn btn-primary" id="btn-save-mail">' +
                        '設定を保存' +
                    '</button>' +
                    '<button type="button" class="btn" id="btn-check-mail">' +
                        '設定を確認' +
                    '</button>' +
                '</div>' +
            '</div>';

        const saveButton = $('btn-save-mail');

        if (saveButton) {
            saveButton.addEventListener('click', async function () {

                const smtp = $('smtp-server');
                const port = $('smtp-port');
                const security = $('smtp-security');
                const username = $('smtp-user');
                const password = $('smtp-password');
                const from = $('smtp-from');
                const fromName = $('smtp-from-name');

                if (!smtp || !port || !security || !username ||
                    !password || !from || !fromName) {
                    return;
                }

                const smtpValue = smtp.value.trim();
                const portValue = port.value.trim();
                const fromValue = from.value.trim();

                if (!smtpValue || !portValue || !fromValue) {
                    alert('SMTPサーバ、ポート番号、送信元メールアドレスを入力してください。');
                    return;
                }

                const result = await postJson(
                    'save_settings',
                    {
                        mail: {
                            smtp: smtpValue,
                            port: portValue,
                            security: security.value,
                            username: username.value.trim(),
                            password: password.value,
                            from: fromValue,
                            fromName: fromName.value.trim()
                        }
                    },
                    saveButton
                );

                if (!result.success) {
                    alert(result.message || '設定を保存できませんでした。');
                    return;
                }

                /*
                 * 保存後、画面上の状態も即時更新。
                 */
                mailSettings.smtp = smtpValue;
                mailSettings.port = portValue;
                mailSettings.security = security.value;
                mailSettings.username = username.value.trim();
                mailSettings.from = fromValue;
                mailSettings.fromName = fromName.value.trim();
                mailSettings.ready = true;

                showToast('メール送信設定を保存しました。');
                renderMailSettings();
            });
        }

        const checkButton = $('btn-check-mail');

        if (checkButton) {
            checkButton.addEventListener('click', async function () {

                const result = await postJson(
                    'check_mail',
                    {},
                    checkButton
                );

                if (result.success) {
                    showToast('保存済みのメール設定を確認しました。');
                } else {
                    alert(result.message || 'メール設定を確認できませんでした。');
                }
            });
        }
    }

    function renderKintoneSettings() {
        const target = $('settings-content');

        if (!target) {
            return;
        }

        target.innerHTML =
            '<div class="card">' +
                '<div class="card-title">キントーン設定</div>' +

                '<div class="status-line">' +
                    '<span class="status-dot ' +
                    (kintoneSettings.ready ? 'ok' : 'warn') +
                    '"></span>' +
                    '<span>' +
                    (kintoneSettings.ready
                        ? 'キントーン設定が保存されています。'
                        : 'キントーン設定が未完了です。') +
                    '</span>' +
                '</div>' +

                '<div class="field">' +
                    '<label>キントーンの利用先 *</label>' +
                    '<input id="kt-domain" value="' +
                    escapeHtml(kintoneSettings.domain) +
                    '">' +
                '</div>' +

                '<div class="field">' +
                    '<label>顧客管理アプリID *</label>' +
                    '<input id="kt-appid" value="' +
                    escapeHtml(kintoneSettings.appId) +
                    '">' +
                '</div>' +

                '<div class="form-grid">' +
                    '<div class="field">' +
                        '<label>ログイン名 *</label>' +
                        '<input id="kt-user" value="' +
                        escapeHtml(kintoneSettings.loginName) +
                        '">' +
                    '</div>' +

                    '<div class="field">' +
                        '<label>パスワード *</label>' +
                        '<input id="kt-password" type="password" placeholder="変更しない場合は空欄">' +
                    '</div>' +
                '</div>' +

                '<div class="form-grid">' +
                    '<div class="field">' +
                        '<label>プロキシ ホスト名</label>' +
                        '<input id="kt-proxy-host" value="' +
                        escapeHtml(kintoneSettings.proxyHost) +
                        '">' +
                    '</div>' +

                    '<div class="field">' +
                        '<label>プロキシ ポート番号</label>' +
                        '<input id="kt-proxy-port" value="' +
                        escapeHtml(kintoneSettings.proxyPort) +
                        '">' +
                    '</div>' +
                '</div>' +

                '<div style="display:flex;gap:8px">' +
                    '<button type="button" class="btn btn-primary" id="btn-save-kintone">' +
                        '設定を保存' +
                    '</button>' +
                '</div>' +
            '</div>';

        const button = $('btn-save-kintone');

        if (button) {
            button.addEventListener('click', async function () {

                const domain = $('kt-domain');
                const appId = $('kt-appid');
                const user = $('kt-user');
                const password = $('kt-password');
                const proxyHost = $('kt-proxy-host');
                const proxyPort = $('kt-proxy-port');

                if (!domain || !appId || !user || !password ||
                    !proxyHost || !proxyPort) {
                    return;
                }

                const domainValue = domain.value.trim();
                const appIdValue = appId.value.trim();
                const userValue = user.value.trim();
                const proxyHostValue = proxyHost.value.trim();
                const proxyPortValue = proxyPort.value.trim();

                if (!domainValue || !appIdValue || !userValue) {
                    alert('利用先、顧客管理アプリID、ログイン名を入力してください。');
                    return;
                }

                if (proxyHostValue && !proxyPortValue) {
                    alert('プロキシのホスト名を入力した場合は、ポート番号も入力してください。');
                    return;
                }

                const result = await postJson(
                    'save_settings',
                    {
                        kintone: {
                            domain: domainValue,
                            appId: appIdValue,
                            loginName: userValue,
                            password: password.value,
                            proxyHost: proxyHostValue,
                            proxyPort: proxyPortValue
                        }
                    },
                    button
                );

                if (!result.success) {
                    alert(result.message || '設定を保存できませんでした。');
                    return;
                }

                kintoneSettings.domain = domainValue;
                kintoneSettings.appId = appIdValue;
                kintoneSettings.loginName = userValue;
                kintoneSettings.proxyHost = proxyHostValue;
                kintoneSettings.proxyPort = proxyPortValue;
                kintoneSettings.ready = true;

                showToast('キントーン設定を保存しました。');
                renderKintoneSettings();
            });
        }
    }

    function openEditor(id) {
        currentSurveyId = id ? Number(id) : null;

        const survey = id
            ? getCurrentSurvey()
            : {
                id: Date.now(),
                name: '',
                description: '',
                status: 'draft',
                created: new Date().toISOString().slice(0, 10),
                start: '',
                end: '',
                answers: 0,
                target: 0,
                sent: 0,
                updated: new Date().toISOString().slice(0, 10),
                numbering: 'global',
                groups: [
                    {
                        id: Date.now() + 1,
                        name: '新しいグループ',
                        questions: []
                    }
                ]
            };

        if (!survey) {
            return;
        }

        if (!id) {
            surveys.push(survey);
        }

        const title = $('editor-title');
        const name = $('survey-name');
        const status = $('survey-status');
        const description = $('survey-description');
        const start = $('survey-start');
        const end = $('survey-end');

        if (title) {
            title.textContent = id ? 'アンケート編集' : 'アンケート作成';
        }

        if (name) name.value = survey.name || '';
        if (status) status.value = survey.status || 'draft';
        if (description) description.value = survey.description || '';
        if (start) start.value = survey.start || '';
        if (end) end.value = survey.end || '';

        renderEditorQuestions(survey);

        showPage('page-editor');
    }

    function renderEditorQuestions(survey) {
        const target = $('editor-questions');

        if (!target) {
            return;
        }

        let html = '';

        (survey.groups || []).forEach(function (group, groupIndex) {

            html +=
                '<div class="card">' +
                    '<div class="form-grid">' +
                        '<div class="field">' +
                            '<label>グループ名</label>' +
                            '<input data-group-name="' +
                            groupIndex +
                            '" value="' +
                            escapeHtml(group.name) +
                            '">' +
                        '</div>' +
                    '</div>';

            (group.questions || []).forEach(function (question, questionIndex) {

                html +=
                    '<div class="question">' +
                        '<div class="question-head">' +
                            '<strong>Q' +
                            (questionIndex + 1) +
                            '</strong>' +
                            '<input data-question-text="' +
                            groupIndex +
                            '-' +
                            questionIndex +
                            '" value="' +
                            escapeHtml(question.text) +
                            '">' +
                        '</div>' +
                    '</div>';
            });

            html +=
                '<button type="button" class="btn btn-small" data-add-question="' +
                groupIndex +
                '">＋ 質問追加</button>' +
                '</div>';
        });

        html +=
            '<button type="button" class="btn" id="btn-add-group">＋ グループ追加</button>';

        target.innerHTML = html;

        document.querySelectorAll('[data-add-question]').forEach(function (button) {

            button.addEventListener('click', function () {

                const surveyNow = getCurrentSurvey();

                if (!surveyNow) {
                    return;
                }

                const groupIndex = Number(
                    button.getAttribute('data-add-question')
                );

                if (!surveyNow.groups[groupIndex]) {
                    return;
                }

                surveyNow.groups[groupIndex].questions.push({
                    id: Date.now(),
                    text: '',
                    type: 'free',
                    required: false,
                    options: []
                });

                renderEditorQuestions(surveyNow);
            });
        });

        const addGroupButton = $('btn-add-group');

        if (addGroupButton) {
            addGroupButton.addEventListener('click', function () {

                const surveyNow = getCurrentSurvey();

                if (!surveyNow) {
                    return;
                }

                surveyNow.groups.push({
                    id: Date.now(),
                    name: '新しいグループ',
                    questions: []
                });

                renderEditorQuestions(surveyNow);
            });
        }
    }

    function saveEditorToObject() {
        const survey = getCurrentSurvey();

        if (!survey) {
            return false;
        }

        const name = $('survey-name');
        const status = $('survey-status');
        const description = $('survey-description');
        const start = $('survey-start');
        const end = $('survey-end');

        if (!name || !status || !description || !start || !end) {
            return false;
        }

        if (!name.value.trim()) {
            alert('アンケート名を入力してください。');
            return false;
        }

        survey.name = name.value.trim();
        survey.status = status.value;
        survey.description = description.value;
        survey.start = start.value;
        survey.end = end.value;
        survey.updated = new Date().toISOString().slice(0, 10);

        document.querySelectorAll('[data-group-name]').forEach(function (input) {

            const index = Number(input.getAttribute('data-group-name'));

            if (survey.groups[index]) {
                survey.groups[index].name = input.value.trim();
            }
        });

        document.querySelectorAll('[data-question-text]').forEach(function (input) {

            const parts =
                input.getAttribute('data-question-text').split('-');

            const groupIndex = Number(parts[0]);
            const questionIndex = Number(parts[1]);

            if (
                survey.groups[groupIndex] &&
                survey.groups[groupIndex].questions[questionIndex]
            ) {
                survey.groups[groupIndex]
                    .questions[questionIndex]
                    .text = input.value.trim();
            }
        });

        return true;
    }

    function showToast(message) {
        const toast = $('toast');

        if (!toast) {
            return;
        }

        toast.textContent = message;
        toast.classList.add('show');

        window.setTimeout(function () {
            toast.classList.remove('show');
        }, 2500);
    }

    function openModal(title, body, footer) {
        const modal = $('modal');
        const modalTitle = $('modal-title');
        const modalBody = $('modal-body');
        const modalFooter = $('modal-footer');

        if (!modal || !modalTitle || !modalBody || !modalFooter) {
            return;
        }

        modalTitle.textContent = title;
        modalBody.textContent = '';

        const bodyWrapper = document.createElement('div');
        bodyWrapper.innerHTML = body;
        modalBody.appendChild(bodyWrapper);

        modalFooter.textContent = '';

        if (footer) {
            const footerWrapper = document.createElement('div');
            footerWrapper.innerHTML = footer;
            modalFooter.appendChild(footerWrapper);
        }

        modal.classList.remove('hidden');
    }

    function closeModal() {
        const modal = $('modal');

        if (modal) {
            modal.classList.add('hidden');
        }
    }

    /*
     * ナビゲーション
     */
    const navList = $('nav-list');

    if (navList) {
        navList.addEventListener('click', function () {
            renderList();
            showPage('page-list');
        });
    }

    const navCreate = $('nav-create');

    if (navCreate) {
        navCreate.addEventListener('click', function () {
            openEditor(null);
        });
    }

    const navCustomers = $('nav-customers');

    if (navCustomers) {
        navCustomers.addEventListener('click', showCustomers);
    }

    const navSettings = $('nav-settings');

    if (navSettings) {
        navSettings.addEventListener('click', function () {
            showSettings('mail');
        });
    }

    const createButton = $('btn-create');

    if (createButton) {
        createButton.addEventListener('click', function () {
            openEditor(null);
        });
    }

    const editorBack = $('btn-editor-back');

    if (editorBack) {
        editorBack.addEventListener('click', function () {
            renderList();
            showPage('page-list');
        });
    }

    const saveSurveyButton = $('btn-save-survey');

    if (saveSurveyButton) {
        saveSurveyButton.addEventListener('click', function () {

            /*
             * 保存ボタンについても、非同期通信へ変更する場合に
             * 二重操作を防げるよう即時disabled。
             */
            saveSurveyButton.disabled = true;
            saveSurveyButton.classList.add('loading');

            try {
                if (!saveEditorToObject()) {
                    return;
                }

                renderList();
                showToast('アンケートを保存しました。');
                showPage('page-list');

            } finally {
                saveSurveyButton.disabled = false;
                saveSurveyButton.classList.remove('loading');
            }
        });
    }

    const detailEdit = $('btn-detail-edit');

    if (detailEdit) {
        detailEdit.addEventListener('click', function () {
            openEditor(currentSurveyId);
        });
    }

    const detailSend = $('btn-detail-send');

    if (detailSend) {
        detailSend.addEventListener('click', function () {
            renderDetailTab('send');
        });
    }

    const detailBack = $('btn-detail-back');

    if (detailBack) {
        detailBack.addEventListener('click', function () {
            renderList();
            showPage('page-list');
        });
    }

    document.querySelectorAll('[data-detail-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            renderDetailTab(
                button.getAttribute('data-detail-tab')
            );
        });
    });

    document.querySelectorAll('[data-settings-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            showSettings(
                button.getAttribute('data-settings-tab')
            );
        });
    });

    const customerSearch = $('customer-search');

    if (customerSearch) {
        customerSearch.addEventListener('input', renderCustomers);
    }

    const surveyListBody = $('survey-list-body');

    if (surveyListBody) {
        surveyListBody.addEventListener('click', function (event) {

            const openButton =
                event.target.closest('[data-open-survey]');

            if (openButton) {
                openDetail(
                    Number(openButton.getAttribute('data-open-survey'))
                );
                return;
            }

            const editButton =
                event.target.closest('[data-edit-survey]');

            if (editButton) {
                openEditor(
                    Number(editButton.getAttribute('data-edit-survey'))
                );
            }
        });
    }

    const modalClose = $('btn-modal-close');

    if (modalClose) {
        modalClose.addEventListener('click', closeModal);
    }

    /*
     * 初期表示。
     *
     * この時点ですでにPHPがsettings.jsonを読み込んでいるため、
     * mailSettings.readyは保存済み設定の状態になっている。
     */
    renderList();
    showPage('page-list');

});
</script>
</body>
</html>

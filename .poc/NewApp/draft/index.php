<?php
declare(strict_types=1);

namespace Yokoyamy\NewApp\SurveyManagement;

use RuntimeException;

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

const APP_SESSION_KEY = 'yokoyamy_newapp_survey_management';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const SURVEYS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';
const CUSTOMERS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';

/**
 * 安全な文字列エスケープ
 */
function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * アプリ固有セッションを取得
 */
function get_app_session(): array
{
    if (!isset($_SESSION[APP_SESSION_KEY]) || !is_array($_SESSION[APP_SESSION_KEY])) {
        $_SESSION[APP_SESSION_KEY] = [];
    }

    return $_SESSION[APP_SESSION_KEY];
}

/**
 * アプリ固有セッションへ保存
 */
function set_app_session(string $key, mixed $value): void
{
    if (!isset($_SESSION[APP_SESSION_KEY]) || !is_array($_SESSION[APP_SESSION_KEY])) {
        $_SESSION[APP_SESSION_KEY] = [];
    }

    $_SESSION[APP_SESSION_KEY][$key] = $value;
}

/**
 * CSRFトークン取得・生成
 */
function get_csrf_token(): string
{
    $session = get_app_session();

    if (
        !isset($session['csrf_token']) ||
        !is_string($session['csrf_token']) ||
        strlen($session['csrf_token']) < 64
    ) {
        $token = bin2hex(random_bytes(32));
        set_app_session('csrf_token', $token);
        return $token;
    }

    return $session['csrf_token'];
}

/**
 * CSRF検証
 */
function verify_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($token) || $token === '') {
        $input = file_get_contents('php://input');
        $json = json_decode($input ?: '', true);

        if (is_array($json) && isset($json['csrf_token']) && is_string($json['csrf_token'])) {
            $token = $json['csrf_token'];
        }
    }

    $session = get_app_session();
    $expected = $session['csrf_token'] ?? '';

    if (
        !is_string($expected) ||
        $expected === '' ||
        !is_string($token) ||
        !hash_equals($expected, $token)
    ) {
        send_json([
            'success' => false,
            'message' => '画面の有効期限が切れています。画面を再読み込みして再度お試しください。'
        ], 403);
    }
}

/**
 * JSONレスポンス
 */
function send_json(array $response, int $status = 200): never
{
    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * dataディレクトリを作成
 */
function ensure_data_directory(): void
{
    if (is_dir(DATA_DIR)) {
        return;
    }

    if (!mkdir(DATA_DIR, 0755, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('データ保存先を作成できませんでした。');
    }
}

/**
 * JSONファイルを安全に読み込む
 */
function read_json_file(string $file, array $default): array
{
    ensure_data_directory();

    if (!file_exists($file)) {
        return $default;
    }

    $contents = file_get_contents($file);

    if ($contents === false || trim($contents) === '') {
        return $default;
    }

    $data = json_decode($contents, true);

    return is_array($data) ? $data : $default;
}

/**
 * JSONファイルへ保存
 */
function write_json_file(string $file, array $data): void
{
    ensure_data_directory();

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('保存するデータを作成できませんでした。');
    }

    $temporary = $file . '.tmp';

    if (file_put_contents($temporary, $json, LOCK_EX) === false) {
        throw new RuntimeException('設定ファイルを書き込めませんでした。');
    }

    if (!rename($temporary, $file)) {
        @unlink($temporary);
        throw new RuntimeException('設定ファイルを更新できませんでした。');
    }
}

/**
 * 初期メール設定
 */
function default_mail_settings(): array
{
    return [
        'smtp' => '',
        'port' => '',
        'security' => 'なし',
        'username' => '',
        'password' => '',
        'from' => '',
        'fromName' => '',
        'ready' => false
    ];
}

/**
 * 初期キントーン設定
 */
function default_kintone_settings(): array
{
    return [
        'domain' => '',
        'appId' => '',
        'loginName' => '',
        'password' => '',
        'proxyHost' => '',
        'proxyPort' => '',
        'proxyAuth' => false,
        'sslVerify' => false,
        'ready' => false
    ];
}

/**
 * settings.jsonを読み込む
 */
function load_settings(): array
{
    $default = [
        'mail' => default_mail_settings(),
        'kintone' => default_kintone_settings()
    ];

    $settings = read_json_file(SETTINGS_FILE, $default);

    if (!isset($settings['mail']) || !is_array($settings['mail'])) {
        $settings['mail'] = default_mail_settings();
    }

    if (!isset($settings['kintone']) || !is_array($settings['kintone'])) {
        $settings['kintone'] = default_kintone_settings();
    }

    $settings['mail'] = array_merge(
        default_mail_settings(),
        $settings['mail']
    );

    $settings['kintone'] = array_merge(
        default_kintone_settings(),
        $settings['kintone']
    );

    return $settings;
}

/**
 * メール設定の保存
 */
function save_mail_settings(array $input): void
{
    $smtp = trim((string)($input['smtp'] ?? ''));
    $port = trim((string)($input['port'] ?? ''));
    $security = trim((string)($input['security'] ?? 'なし'));
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $from = trim((string)($input['from'] ?? ''));
    $fromName = trim((string)($input['fromName'] ?? ''));

    if ($smtp === '') {
        send_json([
            'success' => false,
            'message' => 'SMTPサーバを入力してください。'
        ], 422);
    }

    if ($port === '' || !ctype_digit($port)) {
        send_json([
            'success' => false,
            'message' => 'ポート番号を正しく入力してください。'
        ], 422);
    }

    $portNumber = (int)$port;

    if ($portNumber < 1 || $portNumber > 65535) {
        send_json([
            'success' => false,
            'message' => 'ポート番号は1～65535の範囲で入力してください。'
        ], 422);
    }

    if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
        send_json([
            'success' => false,
            'message' => '送信元メールアドレスを正しく入力してください。'
        ], 422);
    }

    if (!in_array($security, ['なし', 'STARTTLS', 'SSL/TLS'], true)) {
        send_json([
            'success' => false,
            'message' => '接続方式が正しくありません。'
        ], 422);
    }

    $settings = load_settings();

    /*
     * パスワードが空の場合は、既存のパスワードを維持する。
     * これにより、設定画面を再表示した際に
     * パスワードそのものを画面へ返す必要がない。
     */
    $existingPassword = (string)($settings['mail']['password'] ?? '');

    if ($password === '') {
        $password = $existingPassword;
    }

    $settings['mail'] = [
        'smtp' => $smtp,
        'port' => (string)$portNumber,
        'security' => $security,
        'username' => $username,
        'password' => $password,
        'from' => $from,
        'fromName' => $fromName,
        'ready' => true
    ];

    write_json_file(SETTINGS_FILE, $settings);
}

/**
 * キントーン設定保存
 */
function save_kintone_settings(array $input): void
{
    $domain = trim((string)($input['domain'] ?? ''));
    $appId = trim((string)($input['appId'] ?? ''));
    $loginName = trim((string)($input['loginName'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $proxyHost = trim((string)($input['proxyHost'] ?? ''));
    $proxyPort = trim((string)($input['proxyPort'] ?? ''));

    if ($domain === '' || $appId === '' || $loginName === '') {
        send_json([
            'success' => false,
            'message' => '利用先、顧客管理アプリID、ログイン名を入力してください。'
        ], 422);
    }

    $settings = load_settings();

    $existingPassword = (string)($settings['kintone']['password'] ?? '');

    if ($password === '') {
        $password = $existingPassword;
    }

    if ($password === '') {
        send_json([
            'success' => false,
            'message' => 'パスワードを入力してください。'
        ], 422);
    }

    if ($proxyHost !== '' && $proxyPort === '') {
        send_json([
            'success' => false,
            'message' => 'プロキシのホスト名を入力した場合は、ポート番号も入力してください。'
        ], 422);
    }

    if ($proxyPort !== '' && (!ctype_digit($proxyPort) || (int)$proxyPort < 1 || (int)$proxyPort > 65535)) {
        send_json([
            'success' => false,
            'message' => 'プロキシのポート番号を正しく入力してください。'
        ], 422);
    }

    $settings['kintone'] = [
        'domain' => $domain,
        'appId' => $appId,
        'loginName' => $loginName,
        'password' => $password,
        'proxyHost' => $proxyHost,
        'proxyPort' => $proxyPort,
        'proxyAuth' => false,
        'sslVerify' => false,
        'ready' => true
    ];

    write_json_file(SETTINGS_FILE, $settings);
}

/**
 * API処理
 */
function handle_api(): never
{
    $action = $_GET['action'] ?? '';

    if (!is_string($action)) {
        send_json([
            'success' => false,
            'message' => '不正な要求です。'
        ], 400);
    }

    if ($action === 'load_settings') {
        $settings = load_settings();

        /*
         * パスワードはブラウザへ返さない。
         */
        $mail = $settings['mail'];
        $kintone = $settings['kintone'];

        $mail['password'] = '';
        $kintone['password'] = '';

        send_json([
            'success' => true,
            'mail' => $mail,
            'kintone' => $kintone
        ]);
    }

    if ($action === 'save_mail_settings') {
        verify_csrf();

        $input = json_decode(file_get_contents('php://input') ?: '', true);

        if (!is_array($input)) {
            send_json([
                'success' => false,
                'message' => '送信内容を確認できませんでした。'
            ], 400);
        }

        save_mail_settings($input);

        $settings = load_settings();

        send_json([
            'success' => true,
            'message' => 'メール送信設定を保存しました。',
            'mail' => [
                'smtp' => $settings['mail']['smtp'],
                'port' => $settings['mail']['port'],
                'security' => $settings['mail']['security'],
                'username' => $settings['mail']['username'],
                'from' => $settings['mail']['from'],
                'fromName' => $settings['mail']['fromName'],
                'ready' => true
            ]
        ]);
    }

    if ($action === 'save_kintone_settings') {
        verify_csrf();

        $input = json_decode(file_get_contents('php://input') ?: '', true);

        if (!is_array($input)) {
            send_json([
                'success' => false,
                'message' => '送信内容を確認できませんでした。'
            ], 400);
        }

        save_kintone_settings($input);

        $settings = load_settings();

        send_json([
            'success' => true,
            'message' => 'キントーン設定を保存しました。',
            'kintone' => [
                'domain' => $settings['kintone']['domain'],
                'appId' => $settings['kintone']['appId'],
                'loginName' => $settings['kintone']['loginName'],
                'proxyHost' => $settings['kintone']['proxyHost'],
                'proxyPort' => $settings['kintone']['proxyPort'],
                'proxyAuth' => false,
                'sslVerify' => false,
                'ready' => true
            ]
        ]);
    }

    if ($action === 'load_surveys') {
        $surveys = read_json_file(SURVEYS_FILE, []);

        send_json([
            'success' => true,
            'surveys' => $surveys
        ]);
    }

    if ($action === 'save_surveys') {
        verify_csrf();

        $input = json_decode(file_get_contents('php://input') ?: '', true);

        if (!is_array($input) || !isset($input['surveys']) || !is_array($input['surveys'])) {
            send_json([
                'success' => false,
                'message' => 'アンケートデータを確認できませんでした。'
            ], 400);
        }

        write_json_file(SURVEYS_FILE, $input['surveys']);

        send_json([
            'success' => true,
            'message' => 'アンケートを保存しました。'
        ]);
    }

    send_json([
        'success' => false,
        'message' => '指定された処理はありません。'
    ], 404);
}

if (isset($_GET['action'])) {
    try {
        handle_api();
    } catch (\Throwable $e) {
        error_log(
            'NewApp error: ' .
            get_class($e) .
            ': ' .
            $e->getMessage()
        );

        send_json([
            'success' => false,
            'message' => 'サーバー側で処理に失敗しました。保存先の状態を確認してください。'
        ], 500);
    }
}

$csrfToken = get_csrf_token();

$settings = load_settings();

$mailForJs = $settings['mail'];
$mailForJs['password'] = '';

$kintoneForJs = $settings['kintone'];
$kintoneForJs['password'] = '';

$surveys = read_json_file(SURVEYS_FILE, []);

if ($surveys === []) {
    $surveys = [
        [
            'id' => 1,
            'name' => '新商品アンケート',
            'description' => '新商品の利用状況とご意見をお聞きするアンケートです。',
            'status' => 'open',
            'created' => date('Y-m-d'),
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('+30 days')),
            'answers' => 0,
            'target' => 0,
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
    ];

    try {
        write_json_file(SURVEYS_FILE, $surveys);
    } catch (\Throwable $e) {
        error_log('NewApp survey initialization error: ' . $e->getMessage());
    }
}

$customers = read_json_file(CUSTOMERS_FILE, []);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート業務運営</title>

<style>
*{box-sizing:border-box}

body{
    margin:0;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Yu Gothic",Meiryo,sans-serif;
    color:#263238;
    background:#f4f6f8;
}

button,input,textarea,select{font:inherit}

button{cursor:pointer}

button:disabled{
    cursor:not-allowed;
    opacity:.55;
}

.topbar{
    height:60px;
    background:#1f3a5f;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
    gap:30px;
}

.logo{
    font-size:18px;
    font-weight:bold;
    white-space:nowrap;
}

.main-nav{
    display:flex;
    height:100%;
    align-items:center;
    gap:2px;
}

.main-nav button{
    height:100%;
    padding:0 17px;
    color:#dce7f3;
    background:transparent;
    border:0;
}

.main-nav button:hover,
.main-nav button.active{
    background:#31557f;
    color:#fff;
}

.app{
    max-width:1440px;
    margin:0 auto;
    padding:24px;
}

.hidden{
    display:none!important;
}

.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:20px;
}

.page-header h1{
    margin:0;
    font-size:25px;
}

.subtext{
    color:#718096;
    font-size:13px;
    margin-top:5px;
}

.btn{
    border:1px solid #cbd5e0;
    background:#fff;
    color:#34495e;
    border-radius:5px;
    padding:8px 15px;
}

.btn:hover{
    background:#f7fafc;
}

.btn-primary{
    background:#2878c8;
    border-color:#2878c8;
    color:#fff;
}

.btn-primary:hover{
    background:#2068ad;
}

.btn-small{
    padding:5px 10px;
    font-size:12px;
}

.card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:20px;
    margin-bottom:18px;
}

.card-title{
    font-size:17px;
    font-weight:bold;
    margin-bottom:15px;
}

.table{
    width:100%;
    border-collapse:collapse;
}

.table th,
.table td{
    padding:12px 10px;
    border-bottom:1px solid #e6ebef;
    text-align:left;
    vertical-align:middle;
    font-size:13px;
}

.table th{
    background:#f8fafc;
    color:#52606d;
}

.empty{
    text-align:center!important;
    color:#8a98a5;
    padding:40px!important;
}

.badge{
    display:inline-block;
    padding:4px 9px;
    border-radius:12px;
    font-size:11px;
    font-weight:bold;
}

.badge-open{
    background:#e6f6ed;
    color:#237a49;
}

.badge-draft{
    background:#edf2f7;
    color:#66788a;
}

.badge-end{
    background:#fdecec;
    color:#b43b3b;
}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
}

.field{
    margin-bottom:15px;
}

.field label{
    display:block;
    font-size:13px;
    font-weight:bold;
    margin-bottom:6px;
    color:#455563;
}

.field input,
.field textarea,
.field select{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:9px 10px;
    background:#fff;
}

.field textarea{
    min-height:90px;
    resize:vertical;
}

.notice{
    padding:11px 13px;
    border-radius:5px;
    background:#edf6ff;
    border:1px solid #c9e2fa;
    color:#2b5f8a;
    font-size:13px;
    margin-bottom:15px;
}

.notice.success{
    background:#edf9f1;
    border-color:#c9ead5;
    color:#267348;
}

.notice.warning{
    background:#fff8e6;
    border-color:#f0dfae;
    color:#8a6408;
}

.notice.error{
    background:#fff0f0;
    border-color:#f0c5c5;
    color:#a63232;
}

.status-line{
    display:flex;
    align-items:center;
    gap:8px;
    padding:10px 12px;
    background:#f7f9fb;
    border-radius:5px;
    margin-bottom:15px;
}

.status-dot{
    width:9px;
    height:9px;
    border-radius:50%;
    background:#9aa7b3;
}

.status-dot.ok{
    background:#2f9e61;
}

.status-dot.warn{
    background:#d39b25;
}

.settings-tabs{
    display:flex;
    gap:5px;
    margin-bottom:18px;
}

.settings-tabs button{
    border:1px solid #d6dee6;
    background:#fff;
    padding:9px 16px;
    border-radius:5px;
}

.settings-tabs button.active{
    background:#2878c8;
    color:#fff;
    border-color:#2878c8;
}

.actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.spinner{
    display:inline-block;
    width:14px;
    height:14px;
    border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite;
    vertical-align:-2px;
    margin-right:6px;
}

.btn.loading{
    position:relative;
}

@keyframes spin{
    to{transform:rotate(360deg)}
}

.toast{
    position:fixed;
    right:25px;
    bottom:25px;
    background:#263238;
    color:#fff;
    padding:12px 18px;
    border-radius:5px;
    box-shadow:0 5px 20px rgba(0,0,0,.2);
    opacity:0;
    transform:translateY(10px);
    transition:.2s;
    pointer-events:none;
    z-index:2000;
}

.toast.show{
    opacity:1;
    transform:translateY(0);
}

.modal-backdrop{
    position:fixed;
    inset:0;
    background:rgba(20,35,50,.45);
    display:flex;
    align-items:center;
    justify-content:center;
    z-index:1000;
}

.modal{
    width:min(760px,calc(100% - 30px));
    max-height:90vh;
    overflow:auto;
    background:#fff;
    border-radius:8px;
    box-shadow:0 15px 50px rgba(0,0,0,.25);
}

.modal-header{
    padding:16px 20px;
    border-bottom:1px solid #e3e8ed;
    display:flex;
    justify-content:space-between;
}

.modal-body{
    padding:20px;
}

.modal-footer{
    padding:13px 20px;
    border-top:1px solid #e3e8ed;
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

@media(max-width:800px){
    .topbar{
        padding:0 10px;
        gap:8px;
    }

    .logo{
        font-size:15px;
    }

    .main-nav button{
        padding:0 8px;
        font-size:12px;
    }

    .app{
        padding:14px;
    }

    .form-grid{
        grid-template-columns:1fr;
    }
}
</style>
</head>

<body>

<header class="topbar">
    <div class="logo">アンケート業務運営</div>

    <nav class="main-nav">
        <button id="nav-list" type="button">アンケート一覧</button>
        <button id="nav-create" type="button">アンケート作成</button>
        <button id="nav-customers" type="button">顧客一覧</button>
        <button id="nav-settings" type="button">設定</button>
    </nav>
</header>

<main class="app">

<section id="page-list">
    <div class="page-header">
        <div>
            <h1>アンケート一覧</h1>
            <div class="subtext">作成済みのアンケートを管理します</div>
        </div>

        <button id="btn-create" class="btn btn-primary" type="button">
            ＋ アンケート作成
        </button>
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
                </tr>
            </thead>
            <tbody id="survey-list-body"></tbody>
        </table>
    </div>
</section>

<section id="page-editor" class="hidden">

    <div class="page-header">
        <div>
            <h1>アンケート作成</h1>
            <div class="subtext">アンケートの基本情報を設定します</div>
        </div>
    </div>

    <div class="card">

        <div class="form-grid">

            <div class="field">
                <label for="survey-name">アンケート名 *</label>
                <input id="survey-name" type="text">
            </div>

            <div class="field">
                <label for="survey-status">公開状態</label>
                <select id="survey-status">
                    <option value="draft">下書き</option>
                    <option value="open">公開中</option>
                    <option value="end">終了</option>
                </select>
            </div>

        </div>

        <div class="field">
            <label for="survey-description">説明</label>
            <textarea id="survey-description"></textarea>
        </div>

        <div class="form-grid">

            <div class="field">
                <label for="survey-start">公開開始日</label>
                <input id="survey-start" type="date">
            </div>

            <div class="field">
                <label for="survey-end">公開終了日</label>
                <input id="survey-end" type="date">
            </div>

        </div>

    </div>

    <div class="card">

        <div class="card-title">質問</div>

        <div class="field">
            <label for="question-text">質問内容</label>
            <input id="question-text" type="text">
        </div>

        <div class="field">
            <label for="question-type">回答形式</label>
            <select id="question-type">
                <option value="single">単一選択</option>
                <option value="multiple">複数選択</option>
                <option value="free">自由記述</option>
            </select>
        </div>

    </div>

    <div class="actions">

        <button id="btn-editor-back" class="btn" type="button">
            一覧へ戻る
        </button>

        <button id="btn-save-survey" class="btn btn-primary" type="button">
            保存
        </button>

    </div>

</section>

<section id="page-customers" class="hidden">

    <div class="page-header">
        <div>
            <h1>顧客一覧</h1>
            <div class="subtext">顧客管理情報を確認します</div>
        </div>
    </div>

    <div id="customer-status"></div>

    <div class="card">

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
            <div class="subtext">メール送信と顧客一覧取得に必要な設定を管理します</div>
        </div>
    </div>

    <div class="settings-tabs">

        <button
            id="settings-tab-mail"
            type="button"
            class="active">
            メール送信設定
        </button>

        <button
            id="settings-tab-kintone"
            type="button">
            キントーン設定
        </button>

    </div>

    <div id="settings-message"></div>
    <div id="settings-content"></div>

</section>

</main>

<div id="modal" class="modal-backdrop hidden">

    <div class="modal">

        <div class="modal-header">
            <strong id="modal-title"></strong>

            <button
                id="modal-close"
                class="btn btn-small"
                type="button">
                閉じる
            </button>
        </div>

        <div
            class="modal-body"
            id="modal-body">
        </div>

        <div
            class="modal-footer"
            id="modal-footer">
        </div>

    </div>

</div>

<div id="toast" class="toast"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    'use strict';

    const csrfToken = <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>;

    let mailSettings = <?php echo json_encode($mailForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    let kintoneSettings = <?php echo json_encode($kintoneForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    let surveys = <?php echo json_encode($surveys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    let customers = <?php echo json_encode($customers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    let currentSettingsTab = 'mail';
    let currentPage = 'list';

    function $(id) {
        return document.getElementById(id);
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    }

    function showPage(page) {

        const pages = [
            'page-list',
            'page-editor',
            'page-customers',
            'page-settings'
        ];

        pages.forEach(function (id) {

            const el = $(id);

            if (el) {
                el.classList.toggle('hidden', id !== 'page-' + page);
            }

        });

        currentPage = page;

        const navMap = {
            list: 'nav-list',
            editor: 'nav-create',
            customers: 'nav-customers',
            settings: 'nav-settings'
        };

        Object.keys(navMap).forEach(function (key) {

            const nav = $(navMap[key]);

            if (nav) {
                nav.classList.toggle('active', key === page);
            }

        });

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

    function setLoading(button, loading, text) {

        if (!button) {
            return;
        }

        if (loading) {

            button.disabled = true;
            button.classList.add('loading');

            button.dataset.originalText = button.textContent;

            button.textContent = '';

            const spinner = document.createElement('span');
            spinner.className = 'spinner';

            button.appendChild(spinner);
            button.appendChild(document.createTextNode(text || '処理中...'));

        } else {

            button.disabled = false;
            button.classList.remove('loading');

            if (button.dataset.originalText) {
                button.textContent = button.dataset.originalText;
            }

        }
    }

    async function api(action, payload) {

        const url = new URL(window.location.href);
        url.searchParams.set('action', action);

        const options = {
            method: payload ? 'POST' : 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        };

        if (payload) {

            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;

            options.body = JSON.stringify(
                Object.assign(
                    {},
                    payload,
                    {
                        csrf_token: csrfToken
                    }
                )
            );
        }

        let response;

        try {

            response = await fetch(url.toString(), options);

        } catch (error) {

            throw new Error(
                'サーバーとの通信に失敗しました。画面を再読み込みして再度お試しください。'
            );

        }

        let data;

        try {
            data = await response.json();
        } catch (error) {

            throw new Error(
                'サーバーから正しい応答を受け取れませんでした。'
            );

        }

        if (!response.ok || !data.success) {

            throw new Error(
                data.message ||
                '処理に失敗しました。'
            );

        }

        return data;
    }

    function renderList() {

        const body = $('survey-list-body');

        if (!body) {
            return;
        }

        body.textContent = '';

        if (!Array.isArray(surveys) || surveys.length === 0) {

            const tr = document.createElement('tr');
            const td = document.createElement('td');

            td.colSpan = 6;
            td.className = 'empty';
            td.textContent = 'アンケートがありません。';

            tr.appendChild(td);
            body.appendChild(tr);

            return;
        }

        surveys.forEach(function (survey) {

            const tr = document.createElement('tr');

            const name = document.createElement('td');
            name.textContent = survey.name || '';

            const status = document.createElement('td');

            const badge = document.createElement('span');
            badge.className = 'badge';

            if (survey.status === 'open') {

                badge.classList.add('badge-open');
                badge.textContent = '公開中';

            } else if (survey.status === 'end') {

                badge.classList.add('badge-end');
                badge.textContent = '終了';

            } else {

                badge.classList.add('badge-draft');
                badge.textContent = '下書き';

            }

            status.appendChild(badge);

            const created = document.createElement('td');
            created.textContent = survey.created || '';

            const period = document.createElement('td');

            period.textContent =
                (survey.start || '未設定') +
                ' ～ ' +
                (survey.end || '未設定');

            const answers = document.createElement('td');
            answers.textContent = String(survey.answers || 0);

            const updated = document.createElement('td');
            updated.textContent = survey.updated || '';

            tr.appendChild(name);
            tr.appendChild(status);
            tr.appendChild(created);
            tr.appendChild(period);
            tr.appendChild(answers);
            tr.appendChild(updated);

            body.appendChild(tr);

        });

    }

    function openCreate() {

        const name = $('survey-name');
        const description = $('survey-description');
        const start = $('survey-start');
        const end = $('survey-end');
        const question = $('question-text');

        if (name) name.value = '';
        if (description) description.value = '';
        if (start) start.value = '';
        if (end) end.value = '';
        if (question) question.value = '';

        showPage('editor');

    }

    function renderCustomers() {

        const body = $('customer-body');

        if (!body) {
            return;
        }

        body.textContent = '';

        if (!Array.isArray(customers) || customers.length === 0) {

            const tr = document.createElement('tr');
            const td = document.createElement('td');

            td.colSpan = 4;
            td.className = 'empty';
            td.textContent = '顧客情報がありません。';

            tr.appendChild(td);
            body.appendChild(tr);

            return;
        }

        customers.forEach(function (customer) {

            const tr = document.createElement('tr');

            [
                customer.name,
                customer.email,
                customer.company,
                customer.code
            ].forEach(function (value) {

                const td = document.createElement('td');
                td.textContent = value || '';
                tr.appendChild(td);

            });

            body.appendChild(tr);

        });

    }

    function renderCustomerStatus() {

        const status = $('customer-status');

        if (!status) {
            return;
        }

        status.textContent = '';

        const div = document.createElement('div');

        if (kintoneSettings.ready) {

            div.className = 'notice success';
            div.textContent =
                'キントーン設定が保存されています。';

        } else {

            div.className = 'notice warning';
            div.textContent =
                'キントーン設定が未完了です。設定画面から設定してください。';

        }

        status.appendChild(div);

    }

    function showSettings(tab) {

        currentSettingsTab = tab || 'mail';

        showPage('settings');
        renderSettings();

    }

    function renderSettings() {

        const mailTab = $('settings-tab-mail');
        const kintoneTab = $('settings-tab-kintone');

        if (mailTab) {
            mailTab.classList.toggle(
                'active',
                currentSettingsTab === 'mail'
            );
        }

        if (kintoneTab) {
            kintoneTab.classList.toggle(
                'active',
                currentSettingsTab === 'kintone'
            );
        }

        if (currentSettingsTab === 'mail') {
            renderMailSettings();
        } else {
            renderKintoneSettings();
        }

    }

    function renderMessage(message, type) {

        const container = $('settings-message');

        if (!container) {
            return;
        }

        container.textContent = '';

        if (!message) {
            return;
        }

        const div = document.createElement('div');
        div.className = 'notice ' + (type || 'success');
        div.textContent = message;

        container.appendChild(div);

    }

    function renderMailSettings() {

        const content = $('settings-content');

        if (!content) {
            return;
        }

        content.textContent = '';

        const card = document.createElement('div');
        card.className = 'card';

        const title = document.createElement('div');
        title.className = 'card-title';
        title.textContent = 'メール送信設定';

        card.appendChild(title);

        const status = document.createElement('div');
        status.className = 'status-line';

        const dot = document.createElement('span');
        dot.className = 'status-dot ' +
            (mailSettings.ready ? 'ok' : 'warn');

        const statusText = document.createElement('span');

        statusText.textContent =
            mailSettings.ready
                ? 'メール送信可能な設定が保存されています。'
                : 'メール送信設定が未完了です。';

        status.appendChild(dot);
        status.appendChild(statusText);

        card.appendChild(status);

        const notice = document.createElement('div');
        notice.className = 'notice';
        notice.textContent =
            'メール送信に使用するSMTPサーバと送信元情報を設定してください。';

        card.appendChild(notice);

        const grid1 = document.createElement('div');
        grid1.className = 'form-grid';

        grid1.appendChild(
            createField(
                'SMTPサーバ *',
                'smtp-server',
                'text',
                mailSettings.smtp,
                'smtp.example.com'
            )
        );

        grid1.appendChild(
            createField(
                'ポート番号 *',
                'smtp-port',
                'number',
                mailSettings.port,
                '587'
            )
        );

        card.appendChild(grid1);

        const securityField = document.createElement('div');
        securityField.className = 'field';

        const securityLabel = document.createElement('label');
        securityLabel.htmlFor = 'smtp-security';
        securityLabel.textContent = '接続方式';

        const security = document.createElement('select');
        security.id = 'smtp-security';

        ['なし', 'STARTTLS', 'SSL/TLS'].forEach(function (value) {

            const option = document.createElement('option');

            option.value = value;
            option.textContent = value;

            if (mailSettings.security === value) {
                option.selected = true;
            }

            security.appendChild(option);

        });

        securityField.appendChild(securityLabel);
        securityField.appendChild(security);

        card.appendChild(securityField);

        const grid2 = document.createElement('div');
        grid2.className = 'form-grid';

        grid2.appendChild(
            createField(
                '認証ユーザー名',
                'smtp-user',
                'text',
                mailSettings.username,
                ''
            )
        );

        const passwordField = document.createElement('div');
        passwordField.className = 'field';

        const passwordLabel = document.createElement('label');
        passwordLabel.htmlFor = 'smtp-password';
        passwordLabel.textContent = '認証パスワード';

        const password = document.createElement('input');
        password.id = 'smtp-password';
        password.type = 'password';
        password.autocomplete = 'new-password';
        password.placeholder = '変更する場合のみ入力';

        passwordField.appendChild(passwordLabel);
        passwordField.appendChild(password);

        grid2.appendChild(passwordField);

        card.appendChild(grid2);

        const grid3 = document.createElement('div');
        grid3.className = 'form-grid';

        grid3.appendChild(
            createField(
                '送信元メールアドレス *',
                'smtp-from',
                'email',
                mailSettings.from,
                'survey@example.com'
            )
        );

        grid3.appendChild(
            createField(
                '送信元名',
                'smtp-from-name',
                'text',
                mailSettings.fromName,
                ''
            )
        );

        card.appendChild(grid3);

        const actions = document.createElement('div');
        actions.className = 'actions';

        const saveButton = document.createElement('button');
        saveButton.id = 'btn-save-mail';
        saveButton.type = 'button';
        saveButton.className = 'btn btn-primary';
        saveButton.textContent = '設定を保存';

        const testButton = document.createElement('button');
        testButton.id = 'btn-test-mail';
        testButton.type = 'button';
        testButton.className = 'btn';
        testButton.textContent = '送信設定を確認';

        actions.appendChild(saveButton);
        actions.appendChild(testButton);

        card.appendChild(actions);

        content.appendChild(card);

        if (saveButton) {

            saveButton.addEventListener('click', async function () {

                /*
                 * 通信開始前に必ず即時無効化
                 */
                saveButton.disabled = true;
                saveButton.classList.add('loading');

                const originalText = saveButton.textContent;

                saveButton.textContent = '';

                const spinner = document.createElement('span');
                spinner.className = 'spinner';

                saveButton.appendChild(spinner);
                saveButton.appendChild(
                    document.createTextNode('保存中...')
                );

                renderMessage('', '');

                try {

                    const result = await api(
                        'save_mail_settings',
                        {
                            smtp: $('smtp-server')?.value.trim() || '',
                            port: $('smtp-port')?.value.trim() || '',
                            security: $('smtp-security')?.value || 'なし',
                            username: $('smtp-user')?.value.trim() || '',
                            password: $('smtp-password')?.value || '',
                            from: $('smtp-from')?.value.trim() || '',
                            fromName: $('smtp-from-name')?.value.trim() || ''
                        }
                    );

                    mailSettings = result.mail || mailSettings;

                    renderMailSettings();

                    renderMessage(
                        'メール送信設定を保存しました。',
                        'success'
                    );

                    showToast(
                        'メール送信設定を保存しました'
                    );

                } catch (error) {

                    renderMessage(
                        error instanceof Error
                            ? error.message
                            : 'メール設定の保存に失敗しました。',
                        'error'
                    );

                } finally {

                    /*
                     * 通信終了後は必ず復帰
                     */
                    saveButton.disabled = false;
                    saveButton.classList.remove('loading');
                    saveButton.textContent = originalText;

                }

            });

        }

        if (testButton) {

            testButton.addEventListener('click', function () {

                testButton.disabled = true;
                testButton.classList.add('loading');

                const originalText = testButton.textContent;

                testButton.textContent = '';

                const spinner = document.createElement('span');
                spinner.className = 'spinner';

                testButton.appendChild(spinner);
                testButton.appendChild(
                    document.createTextNode('確認中...')
                );

                window.setTimeout(function () {

                    testButton.disabled = false;
                    testButton.classList.remove('loading');
                    testButton.textContent = originalText;

                    if (!mailSettings.ready) {

                        renderMessage(
                            '先にメール送信設定を保存してください。',
                            'warning'
                        );

                        return;
                    }

                    openModal(
                        'メール送信設定の確認',
                        '保存されているメール送信設定を確認しました。',
                        '設定は保存されています。実際の送信確認はメール送信時に行われます。'
                    );

                }, 300);

            });

        }

    }

    function createField(labelText, id, type, value, placeholder) {

        const field = document.createElement('div');
        field.className = 'field';

        const label = document.createElement('label');
        label.htmlFor = id;
        label.textContent = labelText;

        const input = document.createElement('input');

        input.id = id;
        input.type = type;
        input.value = value || '';

        if (placeholder) {
            input.placeholder = placeholder;
        }

        field.appendChild(label);
        field.appendChild(input);

        return field;

    }

    function renderKintoneSettings() {

        const content = $('settings-content');

        if (!content) {
            return;
        }

        content.textContent = '';

        const card = document.createElement('div');
        card.className = 'card';

        const title = document.createElement('div');
        title.className = 'card-title';
        title.textContent = 'キントーン設定';

        card.appendChild(title);

        const status = document.createElement('div');
        status.className = 'status-line';

        const dot = document.createElement('span');
        dot.className =
            'status-dot ' +
            (kintoneSettings.ready ? 'ok' : 'warn');

        const text = document.createElement('span');
        text.textContent =
            kintoneSettings.ready
                ? '顧客一覧を取得できる設定が保存されています。'
                : 'キントーン設定が未完了です。';

        status.appendChild(dot);
        status.appendChild(text);

        card.appendChild(status);

        const grid1 = document.createElement('div');
        grid1.className = 'form-grid';

        grid1.appendChild(
            createField(
                'キントーンの利用先 *',
                'kt-domain',
                'text',
                kintoneSettings.domain,
                'example.cybozu.com'
            )
        );

        grid1.appendChild(
            createField(
                '顧客管理アプリID *',
                'kt-appid',
                'text',
                kintoneSettings.appId,
                '123'
            )
        );

        card.appendChild(grid1);

        const grid2 = document.createElement('div');
        grid2.className = 'form-grid';

        grid2.appendChild(
            createField(
                'ログイン名 *',
                'kt-user',
                'text',
                kintoneSettings.loginName,
                ''
            )
        );

        const passwordField = document.createElement('div');
        passwordField.className = 'field';

        const passwordLabel = document.createElement('label');
        passwordLabel.htmlFor = 'kt-password';
        passwordLabel.textContent = 'パスワード *';

        const password = document.createElement('input');
        password.id = 'kt-password';
        password.type = 'password';
        password.autocomplete = 'new-password';
        password.placeholder = '変更する場合のみ入力';

        passwordField.appendChild(passwordLabel);
        passwordField.appendChild(password);

        grid2.appendChild(passwordField);

        card.appendChild(grid2);

        const grid3 = document.createElement('div');
        grid3.className = 'form-grid';

        grid3.appendChild(
            createField(
                'プロキシ ホスト名',
                'kt-proxy-host',
                'text',
                kintoneSettings.proxyHost,
                'proxy.example.local'
            )
        );

        grid3.appendChild(
            createField(
                'プロキシ ポート番号',
                'kt-proxy-port',
                'number',
                kintoneSettings.proxyPort,
                '8080'
            )
        );

        card.appendChild(grid3);

        const actions = document.createElement('div');
        actions.className = 'actions';

        const saveButton = document.createElement('button');
        saveButton.id = 'btn-save-kintone';
        saveButton.type = 'button';
        saveButton.className = 'btn btn-primary';
        saveButton.textContent = '設定を保存';

        actions.appendChild(saveButton);

        card.appendChild(actions);

        content.appendChild(card);

        if (saveButton) {

            saveButton.addEventListener('click', async function () {

                saveButton.disabled = true;
                saveButton.classList.add('loading');

                const originalText = saveButton.textContent;

                saveButton.textContent = '';

                const spinner = document.createElement('span');
                spinner.className = 'spinner';

                saveButton.appendChild(spinner);
                saveButton.appendChild(
                    document.createTextNode('保存中...')
                );

                renderMessage('', '');

                try {

                    const result = await api(
                        'save_kintone_settings',
                        {
                            domain: $('kt-domain')?.value.trim() || '',
                            appId: $('kt-appid')?.value.trim() || '',
                            loginName: $('kt-user')?.value.trim() || '',
                            password: $('kt-password')?.value || '',
                            proxyHost: $('kt-proxy-host')?.value.trim() || '',
                            proxyPort: $('kt-proxy-port')?.value.trim() || ''
                        }
                    );

                    kintoneSettings =
                        result.kintone || kintoneSettings;

                    renderKintoneSettings();

                    renderMessage(
                        'キントーン設定を保存しました。',
                        'success'
                    );

                    showToast(
                        'キントーン設定を保存しました'
                    );

                } catch (error) {

                    renderMessage(
                        error instanceof Error
                            ? error.message
                            : 'キントーン設定の保存に失敗しました。',
                        'error'
                    );

                } finally {

                    saveButton.disabled = false;
                    saveButton.classList.remove('loading');
                    saveButton.textContent = originalText;

                }

            });

        }

    }

    function openModal(title, message, detail) {

        const modal = $('modal');
        const modalTitle = $('modal-title');
        const modalBody = $('modal-body');
        const modalFooter = $('modal-footer');

        if (!modal || !modalTitle || !modalBody || !modalFooter) {
            return;
        }

        modalTitle.textContent = title;

        modalBody.textContent = '';

        const notice = document.createElement('div');
        notice.className = 'notice success';
        notice.textContent = message;

        modalBody.appendChild(notice);

        if (detail) {

            const paragraph = document.createElement('p');
            paragraph.textContent = detail;

            modalBody.appendChild(paragraph);

        }

        modalFooter.textContent = '';

        modal.classList.remove('hidden');

    }

    function closeModal() {

        const modal = $('modal');

        if (modal) {
            modal.classList.add('hidden');
        }

    }

    const navList = $('nav-list');

    if (navList) {
        navList.addEventListener('click', function () {
            showPage('list');
            renderList();
        });
    }

    const navCreate = $('nav-create');

    if (navCreate) {
        navCreate.addEventListener('click', function () {
            openCreate();
        });
    }

    const navCustomers = $('nav-customers');

    if (navCustomers) {
        navCustomers.addEventListener('click', function () {

            showPage('customers');
            renderCustomers();
            renderCustomerStatus();

        });
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
            openCreate();
        });
    }

    const backButton = $('btn-editor-back');

    if (backButton) {
        backButton.addEventListener('click', function () {
            showPage('list');
            renderList();
        });
    }

    const saveSurveyButton = $('btn-save-survey');

    if (saveSurveyButton) {

        saveSurveyButton.addEventListener('click', async function () {

            saveSurveyButton.disabled = true;
            saveSurveyButton.classList.add('loading');

            const originalText =
                saveSurveyButton.textContent;

            saveSurveyButton.textContent = '';

            const spinner = document.createElement('span');
            spinner.className = 'spinner';

            saveSurveyButton.appendChild(spinner);
            saveSurveyButton.appendChild(
                document.createTextNode('保存中...')
            );

            try {

                const name =
                    $('survey-name')?.value.trim() || '';

                if (!name) {
                    throw new Error(
                        'アンケート名を入力してください。'
                    );
                }

                const questionText =
                    $('question-text')?.value.trim() || '';

                const survey = {
                    id: Date.now(),
                    name: name,
                    description:
                        $('survey-description')?.value || '',
                    status:
                        $('survey-status')?.value || 'draft',
                    created:
                        new Date().toISOString().slice(0, 10),
                    start:
                        $('survey-start')?.value || '',
                    end:
                        $('survey-end')?.value || '',
                    answers: 0,
                    target: 0,
                    sent: 0,
                    updated:
                        new Date().toISOString().slice(0, 10),
                    numbering: 'global',
                    groups: [
                        {
                            id: Date.now() + 1,
                            name: '質問',
                            questions: questionText
                                ? [
                                    {
                                        id: Date.now() + 2,
                                        text: questionText,
                                        type:
                                            $('question-type')?.value ||
                                            'single',
                                        required: false,
                                        options: []
                                    }
                                ]
                                : []
                        }
                    ]
                };

                const nextSurveys =
                    surveys.concat([survey]);

                await api(
                    'save_surveys',
                    {
                        surveys: nextSurveys
                    }
                );

                surveys = nextSurveys;

                showToast(
                    'アンケートを保存しました'
                );

                showPage('list');
                renderList();

            } catch (error) {

                showToast(
                    error instanceof Error
                        ? error.message
                        : 'アンケートの保存に失敗しました。'
                );

            } finally {

                saveSurveyButton.disabled = false;
                saveSurveyButton.classList.remove('loading');
                saveSurveyButton.textContent = originalText;

            }

        });

    }

    const mailTab = $('settings-tab-mail');

    if (mailTab) {
        mailTab.addEventListener('click', function () {
            currentSettingsTab = 'mail';
            renderSettings();
        });
    }

    const kintoneTab = $('settings-tab-kintone');

    if (kintoneTab) {
        kintoneTab.addEventListener('click', function () {
            currentSettingsTab = 'kintone';
            renderSettings();
        });
    }

    const modalClose = $('modal-close');

    if (modalClose) {
        modalClose.addEventListener('click', function () {
            closeModal();
        });
    }

    const modal = $('modal');

    if (modal) {

        modal.addEventListener('click', function (event) {

            if (event.target === modal) {
                closeModal();
            }

        });

    }

    /*
     * 初期表示
     */
    showPage('list');
    renderList();

});
</script>

</body>
</html>

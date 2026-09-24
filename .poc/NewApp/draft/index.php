<?php
declare(strict_types=1);

namespace Yokoyamy\Trial\NewApp;

use RuntimeException;

const APP_SESSION_KEY = 'yokoyamy_trial_newapp';
const SETTINGS_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'settings.json';
const SURVEYS_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'surveys.json';
const CUSTOMERS_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'customers.json';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

if (!isset($_SESSION[APP_SESSION_KEY]) || !is_array($_SESSION[APP_SESSION_KEY])) {
    $_SESSION[APP_SESSION_KEY] = [];
}

if (
    !isset($_SESSION[APP_SESSION_KEY]['csrf_token']) ||
    !is_string($_SESSION[APP_SESSION_KEY]['csrf_token']) ||
    $_SESSION[APP_SESSION_KEY]['csrf_token'] === ''
) {
    $_SESSION[APP_SESSION_KEY]['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * NULL安全なHTMLエスケープ
 */
function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * CSRFトークン取得
 */
function csrf_token(): string
{
    $token = $_SESSION[APP_SESSION_KEY]['csrf_token'] ?? '';

    if (!is_string($token) || $token === '') {
        throw new RuntimeException('CSRFトークンを取得できません。');
    }

    return $token;
}

/**
 * CSRF検証
 */
function verify_csrf(): void
{
    $requestToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($requestToken) || $requestToken === '') {
        $requestToken = $_POST['csrf_token'] ?? '';
    }

    if (
        !is_string($requestToken) ||
        !hash_equals(csrf_token(), $requestToken)
    ) {
        send_json([
            'success' => false,
            'message' => '不正なリクエストです。画面を再読み込みして再度お試しください。',
        ], 403);
    }
}

/**
 * JSONレスポンス
 */
function send_json(array $response, int $status = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    exit;
}

/**
 * JSONファイルを読み込む
 */
function read_json_file(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException(
            '設定ファイルが見つかりません: ' . basename($path)
        );
    }

    $content = file_get_contents($path);

    if ($content === false) {
        throw new RuntimeException(
            '設定ファイルを読み込めません: ' . basename($path)
        );
    }

    try {
        $data = json_decode(
            $content,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (\JsonException $e) {
        throw new RuntimeException(
            '設定ファイルのJSON形式が不正です: ' . basename($path)
        );
    }

    if (!is_array($data)) {
        throw new RuntimeException(
            '設定ファイルの形式が不正です: ' . basename($path)
        );
    }

    return $data;
}

/**
 * JSONファイルへ保存
 */
function write_json_file(string $path, array $data): void
{
    $directory = dirname($path);

    if (!is_dir($directory)) {
        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(
                'データ保存先を作成できません。'
            );
        }
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_THROW_ON_ERROR
    );

    $temporary = $path . '.tmp';

    if (file_put_contents($temporary, $json, LOCK_EX) === false) {
        throw new RuntimeException(
            'データを一時保存できません。'
        );
    }

    if (!rename($temporary, $path)) {
        @unlink($temporary);

        throw new RuntimeException(
            'データファイルを更新できません。'
        );
    }
}

/**
 * 設定ファイルからメール設定を取得
 */
function extract_mail_settings(array $settings): array
{
    if (
        !isset($settings['mail']) ||
        !is_array($settings['mail'])
    ) {
        throw new RuntimeException(
            'data/settings.json にメール設定がありません。'
        );
    }

    $mail = $settings['mail'];

    return [
        'smtp' => isset($mail['smtp']) && is_string($mail['smtp'])
            ? $mail['smtp']
            : '',
        'port' => isset($mail['port']) && is_string($mail['port'])
            ? $mail['port']
            : (isset($mail['port']) && is_int($mail['port'])
                ? (string)$mail['port']
                : ''),
        'security' => isset($mail['security']) && is_string($mail['security'])
            ? $mail['security']
            : '',
        'username' => isset($mail['username']) && is_string($mail['username'])
            ? $mail['username']
            : '',
        'password' => isset($mail['password']) && is_string($mail['password'])
            ? $mail['password']
            : '',
        'from' => isset($mail['from']) && is_string($mail['from'])
            ? $mail['from']
            : '',
        'fromName' => isset($mail['fromName']) && is_string($mail['fromName'])
            ? $mail['fromName']
            : '',
    ];
}

/**
 * 設定状態判定
 */
function is_mail_settings_ready(array $mail): bool
{
    return
        trim((string)($mail['smtp'] ?? '')) !== '' &&
        trim((string)($mail['port'] ?? '')) !== '' &&
        trim((string)($mail['from'] ?? '')) !== '';
}

/**
 * メール設定を安全な画面返却用データにする
 *
 * パスワードはブラウザへ返さない。
 */
function mail_settings_for_client(array $mail): array
{
    return [
        'smtp' => (string)($mail['smtp'] ?? ''),
        'port' => (string)($mail['port'] ?? ''),
        'security' => (string)($mail['security'] ?? ''),
        'username' => (string)($mail['username'] ?? ''),
        'from' => (string)($mail['from'] ?? ''),
        'fromName' => (string)($mail['fromName'] ?? ''),
        'ready' => is_mail_settings_ready($mail),
        'passwordConfigured' => trim((string)($mail['password'] ?? '')) !== '',
    ];
}

/**
 * メール設定を更新
 */
function update_mail_settings(array $input): array
{
    $settings = read_json_file(SETTINGS_FILE);

    $existing = extract_mail_settings($settings);

    $smtp = trim((string)($input['smtp'] ?? ''));
    $port = trim((string)($input['port'] ?? ''));
    $security = trim((string)($input['security'] ?? ''));
    $username = trim((string)($input['username'] ?? ''));
    $from = trim((string)($input['from'] ?? ''));
    $fromName = trim((string)($input['fromName'] ?? ''));

    if ($smtp === '' || $port === '' || $from === '') {
        throw new RuntimeException(
            'SMTPサーバ、ポート番号、送信元メールアドレスを入力してください。'
        );
    }

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(
            '送信元メールアドレスの形式が正しくありません。'
        );
    }

    $password = array_key_exists('password', $input)
        ? (string)$input['password']
        : '';

    /*
     * パスワード欄が空の場合は既存パスワードを維持する。
     */
    if ($password === '') {
        $password = (string)($existing['password'] ?? '');
    }

    $settings['mail'] = [
        'smtp' => $smtp,
        'port' => $port,
        'security' => $security,
        'username' => $username,
        'password' => $password,
        'from' => $from,
        'fromName' => $fromName,
    ];

    write_json_file(SETTINGS_FILE, $settings);

    return extract_mail_settings($settings);
}

/**
 * kintone URL生成
 */
function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain);
    $domain = rtrim((string)$domain, '/');

    if ($domain === '') {
        throw new RuntimeException('キントーンの利用先が未設定です。');
    }

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

/**
 * レスポンスヘッダー取得
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
        'timeout' => 15,
    ];

    if ($method !== 'GET' && $payload !== null) {
        $httpOptions['content'] = is_array($payload)
            ? json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
            : (string)$payload;
    }

    $contextOptions = [
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ];

    $proxyHostPort = trim(
        (string)($config['proxy_host_port'] ?? '')
    );

    if ($proxyHostPort !== '') {
        $contextOptions['http']['proxy'] =
            'tcp://' . $proxyHostPort;

        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $responseBody = @file_get_contents(
        $url,
        false,
        $context
    );

    $responseHeaders = get_safe_response_headers();

    $statusCode = 500;

    if (!empty($responseHeaders)) {
        foreach ($responseHeaders as $headerLine) {
            if (
                preg_match(
                    '/HTTP\/\d\.\d\s+(\d+)/i',
                    $headerLine,
                    $matches
                )
            ) {
                $statusCode = (int)$matches[1];
            }
        }
    }

    $resultData = null;

    if (is_string($responseBody) && $responseBody !== '') {
        $decoded = json_decode($responseBody, true);

        if (is_array($decoded)) {
            $resultData = $decoded;
        }
    }

    if ($statusCode >= 200 && $statusCode < 300) {
        return [
            'success' => true,
            'status' => $statusCode,
            'data' => $resultData,
        ];
    }

    $message = 'kintone API通信エラーが発生しました。';

    if (
        is_array($resultData) &&
        isset($resultData['message']) &&
        is_string($resultData['message'])
    ) {
        $message = $resultData['message'];
    }

    return [
        'success' => false,
        'status' => $statusCode,
        'message' => $message,
        'raw' => is_array($resultData) ? $resultData : [],
    ];
}

/**
 * Cybozu認証ヘッダー
 */
function make_cybozu_auth_header(
    string $loginName,
    string $password
): string {
    $loginName = trim($loginName);
    $password = trim($password);

    return 'X-Cybozu-Authorization: ' .
        base64_encode($loginName . ':' . $password);
}

/**
 * メール設定取得API
 */
function api_get_settings(): never
{
    try {
        $settings = read_json_file(SETTINGS_FILE);
        $mail = extract_mail_settings($settings);

        $kintone = [];

        if (
            isset($settings['kintone']) &&
            is_array($settings['kintone'])
        ) {
            $source = $settings['kintone'];

            $kintone = [
                'domain' => (string)($source['domain'] ?? ''),
                'appId' => (string)($source['appId'] ?? ''),
                'loginName' => (string)($source['loginName'] ?? ''),
                'proxyHost' => (string)($source['proxyHost'] ?? ''),
                'proxyPort' => (string)($source['proxyPort'] ?? ''),
                'ready' =>
                    trim((string)($source['domain'] ?? '')) !== '' &&
                    trim((string)($source['appId'] ?? '')) !== '' &&
                    trim((string)($source['loginName'] ?? '')),
            ];
        }

        send_json([
            'success' => true,
            'mail' => mail_settings_for_client($mail),
            'kintone' => $kintone,
        ]);
    } catch (\Throwable $e) {
        send_json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
}

/**
 * メール設定保存API
 */
function api_save_mail_settings(): never
{
    verify_csrf();

    try {
        $input = json_decode(
            file_get_contents('php://input'),
            true
        );

        if (!is_array($input)) {
            $input = $_POST;
        }

        $mail = update_mail_settings($input);

        send_json([
            'success' => true,
            'message' => 'メール送信設定を保存しました。',
            'mail' => mail_settings_for_client($mail),
        ]);
    } catch (\Throwable $e) {
        send_json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 400);
    }
}

/**
 * メール設定確認API
 */
function api_test_mail_settings(): never
{
    verify_csrf();

    try {
        $settings = read_json_file(SETTINGS_FILE);
        $mail = extract_mail_settings($settings);

        if (!is_mail_settings_ready($mail)) {
            throw new RuntimeException(
                'メール送信設定が未完了です。'
            );
        }

        send_json([
            'success' => true,
            'message' => '保存されているメール送信設定を確認しました。',
            'mail' => [
                'smtp' => $mail['smtp'],
                'port' => $mail['port'],
                'security' => $mail['security'],
                'username' => $mail['username'],
                'from' => $mail['from'],
                'fromName' => $mail['fromName'],
            ],
        ]);
    } catch (\Throwable $e) {
        send_json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 400);
    }
}

/**
 * API振り分け
 */
$action = $_GET['action'] ?? '';

if (is_string($action) && $action !== '') {
    if ($action === 'get_settings') {
        api_get_settings();
    }

    if ($action === 'save_mail_settings') {
        api_save_mail_settings();
    }

    if ($action === 'test_mail_settings') {
        api_test_mail_settings();
    }
}

$initialMailSettings = [
    'smtp' => '',
    'port' => '',
    'security' => '',
    'username' => '',
    'from' => '',
    'fromName' => '',
    'ready' => false,
    'passwordConfigured' => false,
];

$initialLoadError = '';

try {
    if (is_file(SETTINGS_FILE)) {
        $initialSettings = read_json_file(SETTINGS_FILE);
        $initialMailSettings = mail_settings_for_client(
            extract_mail_settings($initialSettings)
        );
    } else {
        $initialLoadError =
            'data/settings.json が見つかりません。';
    }
} catch (\Throwable $e) {
    $initialLoadError = $e->getMessage();
}

$csrfToken = csrf_token();
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
.main-nav button.active{
    background:#31557f;
    color:#fff
}
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
.btn:hover{background:#f7fafc}
.btn-primary{background:#2878c8;border-color:#2878c8;color:#fff}
.btn-primary:hover{background:#2068ad}
.btn-danger{border-color:#e05a5a;color:#c53f3f;background:#fff}
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
.table th{background:#f8fafc;color:#52606d;font-weight:bold}
.empty{text-align:center!important;color:#8a98a5;padding:40px!important}
.link-button{
    border:0;background:none;padding:0;color:#2878c8;cursor:pointer;text-align:left
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
.badge-ok{background:#e6f6ed;color:#237a49}
.badge-warn{background:#fff5d9;color:#9a6800}
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
.field textarea{min-height:90px;resize:vertical}
.radio-row{display:flex;gap:22px;flex-wrap:wrap}
.radio-row label{
    font-weight:normal;
    display:inline-flex;
    align-items:center;
    gap:5px
}
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
.notice.error{background:#fff0f0;border-color:#efc5c5;color:#a33333}
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
.settings-tabs{
    display:flex;
    gap:5px;
    margin-bottom:18px
}
.settings-tabs button{
    border:1px solid #d6dee6;
    background:#fff;
    padding:9px 16px;
    border-radius:5px
}
.settings-tabs button.active{
    background:#2878c8;
    color:#fff;
    border-color:#2878c8
}
.loading-spinner{
    display:inline-block;
    width:14px;
    height:14px;
    border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite;
    vertical-align:-2px;
    margin-right:5px
}
.btn:not(.btn-primary) .loading-spinner{
    border-color:rgba(40,120,200,.25);
    border-top-color:#2878c8
}
@keyframes spin{to{transform:rotate(360deg)}}
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
    border-radius:8px;
    box-shadow:0 15px 50px rgba(0,0,0,.25)
}
.modal-header{
    padding:16px 20px;
    border-bottom:1px solid #e3e8ed;
    display:flex;
    justify-content:space-between
}
.modal-body{padding:20px}
.modal-footer{
    padding:13px 20px;
    border-top:1px solid #e3e8ed;
    display:flex;
    justify-content:flex-end;
    gap:8px
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
    z-index:2000
}
.toast.show{opacity:1;transform:translateY(0)}
.question-card{
    padding:15px;
    border-bottom:1px solid #e6ebef
}
.question-head{
    display:flex;
    align-items:center;
    gap:8px
}
.question-number{
    width:58px;
    color:#2878c8;
    font-weight:bold;
    flex:none
}
.question-title{flex:1}
.question-title input{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:8px
}
.question-options{
    margin-top:12px;
    padding-left:66px
}
.option-row{
    display:flex;
    align-items:center;
    gap:7px;
    margin-bottom:7px
}
.option-row input{
    flex:1;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:7px
}
.group-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    margin-bottom:16px
}
.group-header{
    display:flex;
    align-items:center;
    gap:10px;
    padding:13px 15px;
    background:#f7f9fb;
    border-bottom:1px solid #e3e8ed
}
.group-title{flex:1}
.group-title input{
    border:1px solid transparent;
    background:transparent;
    padding:5px 7px;
    font-weight:bold;
    font-size:16px;
    width:100%
}
.editor-toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    margin-top:20px
}
.editor-actions{display:flex;gap:8px}
.detail-tabs{
    display:flex;
    border-bottom:1px solid #dfe5eb;
    margin-bottom:18px
}
.detail-tabs button{
    border:0;
    background:transparent;
    padding:12px 20px;
    color:#687887;
    border-bottom:3px solid transparent
}
.detail-tabs button.active{
    color:#2878c8;
    border-bottom-color:#2878c8
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
.stat-note{font-size:11px;color:#8a98a5;margin-top:3px}
.customer-toolbar{
    display:flex;
    gap:8px;
    margin-bottom:12px
}
.customer-toolbar input{flex:1}
.customer-toolbar input,
.customer-toolbar select{
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:8px
}
.send-layout{
    display:grid;
    grid-template-columns:1.1fr .9fr;
    gap:18px
}
.selection-summary{
    padding:10px 12px;
    background:#edf6ff;
    color:#2b5f8a;
    border-radius:5px;
    margin-bottom:12px;
    font-size:13px
}
.email-preview{
    border:1px solid #dfe5eb;
    border-radius:6px;
    background:#fafbfc;
    padding:15px;
    white-space:pre-wrap;
    min-height:150px;
    font-size:13px
}
.recipient-chip{
    display:inline-block;
    padding:5px 8px;
    margin:3px;
    border-radius:4px;
    background:#edf2f7;
    font-size:12px
}
@media(max-width:950px){
    .send-layout{grid-template-columns:1fr}
    .detail-summary{grid-template-columns:1fr 1fr}
}
@media(max-width:800px){
    .topbar{padding:0 10px;gap:8px}
    .logo{font-size:15px}
    .main-nav button{padding:0 8px;font-size:12px}
    .app{padding:14px}
    .form-grid{grid-template-columns:1fr}
    .table{min-width:800px}
    .card{overflow-x:auto}
}
</style>
</head>
<body>

<header class="topbar">
    <div class="logo">アンケート業務運営</div>
    <nav class="main-nav">
        <button id="nav-list">アンケート一覧</button>
        <button id="nav-create">アンケート作成</button>
        <button id="nav-customers">顧客一覧</button>
        <button id="nav-settings">設定</button>
    </nav>
</header>

<main class="app">

<section id="page-list">
    <div class="page-header">
        <div>
            <h1>アンケート一覧</h1>
            <div class="subtext">作成済みのアンケートを管理します</div>
        </div>
        <button class="btn btn-primary" id="btn-create">＋ アンケート作成</button>
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
            <h1 id="editor-page-title">アンケート作成</h1>
            <div class="subtext">アンケート全体を1画面で編集できます</div>
        </div>
    </div>

    <div class="card">
        <div class="form-grid">
            <div class="field">
                <label>アンケート名 *</label>
                <input id="survey-name" type="text">
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

        <div class="field">
            <label>質問番号</label>
            <div class="radio-row">
                <label>
                    <input type="radio" name="numbering" value="global">
                    全体で通番
                </label>
                <label>
                    <input type="radio" name="numbering" value="group">
                    グループごと
                </label>
            </div>
        </div>
    </div>

    <div id="groups"></div>

    <div style="text-align:center">
        <button class="btn btn-primary" id="btn-add-group">＋ グループ追加</button>
    </div>

    <div class="editor-toolbar">
        <button class="btn" id="btn-editor-back">一覧へ戻る</button>
        <div class="editor-actions">
            <button class="btn" id="btn-preview">内容確認</button>
            <button class="btn btn-primary" id="btn-save-survey">保存</button>
        </div>
    </div>
</section>

<section id="page-detail" class="hidden">
    <div class="page-header">
        <div>
            <h1 id="detail-title"></h1>
            <div class="subtext" id="detail-subtitle"></div>
        </div>
        <div>
            <button class="btn" id="btn-detail-edit">編集</button>
            <button class="btn btn-primary" id="btn-detail-send">送信</button>
            <button class="btn" id="btn-detail-back">一覧へ戻る</button>
        </div>
    </div>

    <div class="detail-tabs">
        <button id="tab-content">アンケート内容</button>
        <button id="tab-send">送信</button>
        <button id="tab-status">回答状況</button>
        <button id="tab-result">回答結果</button>
    </div>

    <div id="detail-content"></div>
</section>

<section id="page-customers" class="hidden">
    <div class="page-header">
        <div>
            <h1>顧客一覧</h1>
            <div class="subtext">キントーンの顧客管理アプリから取得した顧客です</div>
        </div>
        <button class="btn" id="btn-customer-settings">キントーン設定</button>
    </div>

    <div id="customer-status"></div>

    <div class="card">
        <div class="customer-toolbar">
            <input id="customer-search" placeholder="顧客名・メールアドレスで検索">
            <button class="btn" id="btn-refresh-customers">顧客一覧を更新</button>
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
            <div class="subtext">メール送信と顧客一覧取得に必要な設定を管理します</div>
        </div>
    </div>

    <div class="settings-tabs">
        <button id="settings-tab-mail">メール送信設定</button>
        <button id="settings-tab-kintone">キントーン設定</button>
    </div>

    <div id="settings-load-error"></div>
    <div id="settings-content"></div>
</section>

</main>

<div id="modal" class="modal-backdrop hidden">
    <div class="modal">
        <div class="modal-header">
            <strong id="modal-title"></strong>
            <button class="btn btn-small" id="btn-modal-close">閉じる</button>
        </div>
        <div class="modal-body" id="modal-body"></div>
        <div class="modal-footer" id="modal-footer"></div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const csrfToken = <?= json_encode(
        $csrfToken,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) ?>;

    let mailSettings = <?= json_encode(
        $initialMailSettings,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) ?>;

    const initialLoadError = <?= json_encode(
        $initialLoadError,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) ?>;

    let currentSettingsTab = 'mail';
    let currentSurveyId = null;
    let editingSurvey = null;
    let selectedCustomers = [];

    let surveys = [
        {
            id: 1,
            name: '新商品アンケート',
            description: '新商品の利用状況とご意見をお聞きするアンケートです。',
            status: 'open',
            created: '2026-09-01',
            start: '2026-09-01',
            end: '2026-09-30',
            answers: 128,
            target: 200,
            sent: 195,
            updated: '2026-09-20',
            numbering: 'global',
            groups: [
                {
                    id: 101,
                    name: 'ご利用状況',
                    questions: [
                        {
                            id: 1001,
                            text: '当社の商品を利用したことがありますか？',
                            type: 'single',
                            required: true,
                            options: [
                                {text: 'はい', branch: ''},
                                {text: 'いいえ', branch: '1003'}
                            ]
                        },
                        {
                            id: 1002,
                            text: '商品についての満足度を教えてください。',
                            type: 'single',
                            required: true,
                            options: [
                                {text: '満足', branch: ''},
                                {text: '普通', branch: ''},
                                {text: '不満', branch: '1003'}
                            ]
                        }
                    ]
                },
                {
                    id: 102,
                    name: 'ご意見',
                    questions: [
                        {
                            id: 1003,
                            text: '今後の商品についてご意見をお聞かせください。',
                            type: 'free',
                            required: false,
                            options: []
                        }
                    ]
                }
            ]
        },
        {
            id: 2,
            name: 'サービス利用後アンケート',
            description: 'サービスをご利用いただいた感想をお聞きします。',
            status: 'draft',
            created: '2026-09-10',
            start: '',
            end: '',
            answers: 0,
            target: 0,
            sent: 0,
            updated: '2026-09-21',
            numbering: 'group',
            groups: [
                {
                    id: 201,
                    name: 'サービスについて',
                    questions: [
                        {
                            id: 2001,
                            text: 'サービスについての感想を教えてください。',
                            type: 'multiple',
                            required: false,
                            options: [
                                {text: '便利だった', branch: ''},
                                {text: '分かりやすかった', branch: ''},
                                {text: 'また利用したい', branch: ''}
                            ]
                        }
                    ]
                }
            ]
        }
    ];

    let customers = [
        {
            id: 1,
            name: '山田 太郎',
            email: 'taro.yamada@example.com',
            company: '株式会社サンプル',
            code: 'C0001'
        },
        {
            id: 2,
            name: '佐藤 花子',
            email: 'hanako.sato@example.com',
            company: '株式会社サンプル',
            code: 'C0002'
        },
        {
            id: 3,
            name: '鈴木 一郎',
            email: 'ichiro.suzuki@example.com',
            company: '株式会社テスト',
            code: 'C0003'
        },
        {
            id: 4,
            name: '田中 美咲',
            email: 'misaki.tanaka@example.com',
            company: '株式会社テスト',
            code: 'C0004'
        },
        {
            id: 5,
            name: '高橋 健',
            email: 'ken.takahashi@example.com',
            company: '有限会社デモ',
            code: 'C0005'
        },
        {
            id: 6,
            name: '伊藤 明',
            email: 'akira.ito@example.com',
            company: '有限会社デモ',
            code: 'C0006'
        }
    ];

    let nextGroupId = 500;
    let nextQuestionId = 5000;

    function $(id) {
        return document.getElementById(id);
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showPage(id) {
        [
            'page-list',
            'page-editor',
            'page-detail',
            'page-customers',
            'page-settings'
        ].forEach(function (pageId) {
            const page = $(pageId);
            if (page) {
                page.classList.add('hidden');
            }
        });

        const target = $(id);
        if (target) {
            target.classList.remove('hidden');
        }

        [
            'nav-list',
            'nav-create',
            'nav-customers',
            'nav-settings'
        ].forEach(function (navId) {
            const nav = $(navId);
            if (nav) {
                nav.classList.remove('active');
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

    function setLoading(button, loading) {
        if (!button) {
            return;
        }

        if (loading) {
            button.disabled = true;

            if (!button.dataset.originalText) {
                button.dataset.originalText = button.textContent;
            }

            button.innerHTML =
                '<span class="loading-spinner"></span>' +
                escapeHtml(button.dataset.originalText);
        } else {
            button.disabled = false;

            if (button.dataset.originalText) {
                button.textContent = button.dataset.originalText;
            }
        }
    }

    async function requestJson(url, options) {
        const response = await fetch(url, options);
        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(
                data.message || '処理に失敗しました。'
            );
        }

        return data;
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
        const titleEl = $('modal-title');
        const bodyEl = $('modal-body');
        const footerEl = $('modal-footer');
        const modal = $('modal');

        if (!titleEl || !bodyEl || !footerEl || !modal) {
            return;
        }

        titleEl.textContent = title;
        bodyEl.innerHTML = body;
        footerEl.innerHTML = footer || '';
        modal.classList.remove('hidden');
    }

    function closeModal() {
        const modal = $('modal');

        if (modal) {
            modal.classList.add('hidden');
        }
    }

    function showList() {
        renderList();
        showPage('page-list');
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

        if (!surveys.length) {
            body.innerHTML =
                '<tr><td colspan="7" class="empty">' +
                'アンケートがありません。' +
                '</td></tr>';

            return;
        }

        body.innerHTML = surveys.map(function (survey) {
            const period =
                survey.start || survey.end
                    ? escapeHtml(survey.start || '未設定') +
                      ' ～ ' +
                      escapeHtml(survey.end || '未設定')
                    : '未設定';

            return (
                '<tr>' +
                '<td>' +
                '<button class="link-button survey-open" data-id="' +
                String(survey.id) +
                '">' +
                escapeHtml(survey.name) +
                '</button>' +
                '</td>' +
                '<td>' +
                statusBadge(survey.status) +
                '</td>' +
                '<td>' +
                escapeHtml(survey.created || '') +
                '</td>' +
                '<td>' +
                period +
                '</td>' +
                '<td>' +
                String(survey.answers || 0) +
                '件</td>' +
                '<td>' +
                escapeHtml(survey.updated || '') +
                '</td>' +
                '<td>' +
                '<button class="btn btn-small survey-edit" data-id="' +
                String(survey.id) +
                '">編集</button> ' +
                '<button class="btn btn-small survey-detail" data-id="' +
                String(survey.id) +
                '">確認</button> ' +
                (survey.status === 'draft'
                    ? '<button class="btn btn-small btn-success survey-publish" data-id="' +
                      String(survey.id) +
                      '">公開</button> '
                    : '') +
                (survey.status === 'open'
                    ? '<button class="btn btn-small btn-danger survey-end" data-id="' +
                      String(survey.id) +
                      '">終了</button> '
                    : '') +
                (survey.status === 'draft'
                    ? '<button class="btn btn-small btn-danger survey-delete" data-id="' +
                      String(survey.id) +
                      '">削除</button>'
                    : '') +
                '</td>' +
                '</tr>'
            );
        }).join('');

        body.querySelectorAll('.survey-open').forEach(function (button) {
            button.addEventListener('click', function () {
                openDetail(Number(button.dataset.id));
            });
        });

        body.querySelectorAll('.survey-edit').forEach(function (button) {
            button.addEventListener('click', function () {
                editSurvey(Number(button.dataset.id));
            });
        });

        body.querySelectorAll('.survey-detail').forEach(function (button) {
            button.addEventListener('click', function () {
                openDetail(Number(button.dataset.id));
            });
        });

        body.querySelectorAll('.survey-publish').forEach(function (button) {
            button.addEventListener('click', function () {
                publishSurvey(Number(button.dataset.id));
            });
        });

        body.querySelectorAll('.survey-end').forEach(function (button) {
            button.addEventListener('click', function () {
                endSurvey(Number(button.dataset.id));
            });
        });

        body.querySelectorAll('.survey-delete').forEach(function (button) {
            button.addEventListener('click', function () {
                deleteSurvey(Number(button.dataset.id));
            });
        });
    }

    function cloneSurvey(survey) {
        return JSON.parse(JSON.stringify(survey));
    }

    function openCreate() {
        editingSurvey = {
            id: null,
            name: '',
            description: '',
            status: 'draft',
            created: '',
            start: '',
            end: '',
            answers: 0,
            target: 0,
            sent: 0,
            updated: '',
            numbering: 'global',
            groups: [
                {
                    id: nextGroupId++,
                    name: 'グループ1',
                    questions: [
                        {
                            id: nextQuestionId++,
                            text: '',
                            type: 'free',
                            required: false,
                            options: []
                        }
                    ]
                }
            ]
        };

        const title = $('editor-page-title');

        if (title) {
            title.textContent = 'アンケート作成';
        }

        loadEditor();
        showPage('page-editor');
    }

    function editSurvey(id) {
        const survey = surveys.find(function (item) {
            return item.id === id;
        });

        if (!survey) {
            return;
        }

        editingSurvey = cloneSurvey(survey);

        const title = $('editor-page-title');

        if (title) {
            title.textContent = 'アンケート編集';
        }

        loadEditor();
        showPage('page-editor');
    }

    function loadEditor() {
        if (!editingSurvey) {
            return;
        }

        const name = $('survey-name');
        const status = $('survey-status');
        const description = $('survey-description');
        const start = $('survey-start');
        const end = $('survey-end');

        if (name) name.value = editingSurvey.name || '';
        if (status) status.value = editingSurvey.status || 'draft';
        if (description) description.value = editingSurvey.description || '';
        if (start) start.value = editingSurvey.start || '';
        if (end) end.value = editingSurvey.end || '';

        document.querySelectorAll(
            'input[name="numbering"]'
        ).forEach(function (radio) {
            radio.checked =
                radio.value ===
                (editingSurvey.numbering || 'global');
        });

        renderGroups();
    }

    function renderGroups() {
        const container = $('groups');

        if (!container || !editingSurvey) {
            return;
        }

        let questionNumber = 1;

        container.innerHTML = editingSurvey.groups.map(
            function (group, groupIndex) {
                const questions = group.questions.map(
                    function (question, questionIndex) {
                        let number;

                        if (editingSurvey.numbering === 'group') {
                            number =
                                'Q' +
                                (groupIndex + 1) +
                                '-' +
                                (questionIndex + 1);
                        } else {
                            number = 'Q' + questionNumber;
                        }

                        questionNumber++;

                        let options = '';

                        if (
                            question.type === 'single' ||
                            question.type === 'multiple'
                        ) {
                            options =
                                '<div class="question-options">' +
                                question.options.map(
                                    function (option, optionIndex) {
                                        return (
                                            '<div class="option-row">' +
                                            '<input class="question-option" ' +
                                            'data-group="' +
                                            groupIndex +
                                            '" ' +
                                            'data-question="' +
                                            questionIndex +
                                            '" ' +
                                            'data-option="' +
                                            optionIndex +
                                            '" ' +
                                            'value="' +
                                            escapeHtml(option.text) +
                                            '">' +
                                            '</div>'
                                        );
                                    }
                                ).join('') +
                                '<button class="btn btn-small add-option" ' +
                                'data-group="' +
                                groupIndex +
                                '" data-question="' +
                                questionIndex +
                                '">' +
                                '＋ 選択肢追加' +
                                '</button>' +
                                '</div>';
                        }

                        return (
                            '<div class="question-card">' +
                            '<div class="question-head">' +
                            '<div class="question-number">' +
                            escapeHtml(number) +
                            '</div>' +
                            '<div class="question-title">' +
                            '<input class="question-text" ' +
                            'data-group="' +
                            groupIndex +
                            '" data-question="' +
                            questionIndex +
                            '" value="' +
                            escapeHtml(question.text) +
                            '" placeholder="質問文">' +
                            '</div>' +
                            '<select class="question-type" ' +
                            'data-group="' +
                            groupIndex +
                            '" data-question="' +
                            questionIndex +
                            '">' +
                            '<option value="free"' +
                            (question.type === 'free'
                                ? ' selected'
                                : '') +
                            '>自由記述</option>' +
                            '<option value="single"' +
                            (question.type === 'single'
                                ? ' selected'
                                : '') +
                            '>単一選択</option>' +
                            '<option value="multiple"' +
                            (question.type === 'multiple'
                                ? ' selected'
                                : '') +
                            '>複数選択</option>' +
                            '</select>' +
                            '<button class="btn btn-small delete-question" ' +
                            'data-group="' +
                            groupIndex +
                            '" data-question="' +
                            questionIndex +
                            '">削除</button>' +
                            '</div>' +
                            '<div style="margin-top:10px">' +
                            '<label>' +
                            '<input type="checkbox" class="question-required" ' +
                            'data-group="' +
                            groupIndex +
                            '" data-question="' +
                            questionIndex +
                            '"' +
                            (question.required ? ' checked' : '') +
                            '> 必須回答' +
                            '</label>' +
                            '</div>' +
                            options +
                            '</div>'
                        );
                    }
                ).join('');

                return (
                    '<div class="group-card">' +
                    '<div class="group-header">' +
                    '<div class="group-title">' +
                    '<input class="group-name" data-group="' +
                    groupIndex +
                    '" value="' +
                    escapeHtml(group.name) +
                    '">' +
                    '</div>' +
                    '<button class="btn btn-small delete-group" data-group="' +
                    groupIndex +
                    '">グループ削除</button>' +
                    '</div>' +
                    questions +
                    '<div style="padding:12px">' +
                    '<button class="btn btn-small add-question" data-group="' +
                    groupIndex +
                    '">＋ 質問追加</button>' +
                    '</div>' +
                    '</div>'
                );
            }
        ).join('');

        bindEditorEvents();
    }

    function bindEditorEvents() {
        const container = $('groups');

        if (!container) {
            return;
        }

        container.querySelectorAll('.group-name').forEach(
            function (input) {
                input.addEventListener('input', function () {
                    const groupIndex =
                        Number(input.dataset.group);

                    if (
                        editingSurvey.groups[groupIndex]
                    ) {
                        editingSurvey.groups[groupIndex].name =
                            input.value;
                    }
                });
            }
        );

        container.querySelectorAll('.question-text').forEach(
            function (input) {
                input.addEventListener('input', function () {
                    const groupIndex =
                        Number(input.dataset.group);
                    const questionIndex =
                        Number(input.dataset.question);

                    if (
                        editingSurvey.groups[groupIndex] &&
                        editingSurvey.groups[groupIndex].questions[
                            questionIndex
                        ]
                    ) {
                        editingSurvey.groups[groupIndex].questions[
                            questionIndex
                        ].text = input.value;
                    }
                });
            }
        );

        container.querySelectorAll('.question-type').forEach(
            function (select) {
                select.addEventListener('change', function () {
                    const groupIndex =
                        Number(select.dataset.group);
                    const questionIndex =
                        Number(select.dataset.question);

                    const question =
                        editingSurvey.groups[groupIndex]?.questions[
                            questionIndex
                        ];

                    if (!question) {
                        return;
                    }

                    question.type = select.value;

                    if (
                        question.type === 'free'
                    ) {
                        question.options = [];
                    } else if (
                        !Array.isArray(question.options) ||
                        question.options.length === 0
                    ) {
                        question.options = [
                            {text: '選択肢1', branch: ''},
                            {text: '選択肢2', branch: ''}
                        ];
                    }

                    renderGroups();
                });
            }
        );

        container.querySelectorAll('.question-required').forEach(
            function (checkbox) {
                checkbox.addEventListener('change', function () {
                    const groupIndex =
                        Number(checkbox.dataset.group);
                    const questionIndex =
                        Number(checkbox.dataset.question);

                    const question =
                        editingSurvey.groups[groupIndex]?.questions[
                            questionIndex
                        ];

                    if (question) {
                        question.required = checkbox.checked;
                    }
                });
            }
        );

        container.querySelectorAll('.question-option').forEach(
            function (input) {
                input.addEventListener('input', function () {
                    const groupIndex =
                        Number(input.dataset.group);
                    const questionIndex =
                        Number(input.dataset.question);
                    const optionIndex =
                        Number(input.dataset.option);

                    const option =
                        editingSurvey.groups[groupIndex]?.questions[
                            questionIndex
                        ]?.options[optionIndex];

                    if (option) {
                        option.text = input.value;
                    }
                });
            }
        );

        container.querySelectorAll('.add-option').forEach(
            function (button) {
                button.addEventListener('click', function () {
                    const groupIndex =
                        Number(button.dataset.group);
                    const questionIndex =
                        Number(button.dataset.question);

                    const question =
                        editingSurvey.groups[groupIndex]?.questions[
                            questionIndex
                        ];

                    if (!question) {
                        return;
                    }

                    question.options.push({
                        text: '新しい選択肢',
                        branch: ''
                    });

                    renderGroups();
                });
            }
        );

        container.querySelectorAll('.add-question').forEach(
            function (button) {
                button.addEventListener('click', function () {
                    const groupIndex =
                        Number(button.dataset.group);

                    const group =
                        editingSurvey.groups[groupIndex];

                    if (!group) {
                        return;
                    }

                    group.questions.push({
                        id: nextQuestionId++,
                        text: '',
                        type: 'free',
                        required: false,
                        options: []
                    });

                    renderGroups();
                });
            }
        );

        container.querySelectorAll('.delete-question').forEach(
            function (button) {
                button.addEventListener('click', function () {
                    const groupIndex =
                        Number(button.dataset.group);
                    const questionIndex =
                        Number(button.dataset.question);

                    const group =
                        editingSurvey.groups[groupIndex];

                    if (!group) {
                        return;
                    }

                    group.questions.splice(
                        questionIndex,
                        1
                    );

                    renderGroups();
                });
            }
        );

        container.querySelectorAll('.delete-group').forEach(
            function (button) {
                button.addEventListener('click', function () {
                    const groupIndex =
                        Number(button.dataset.group);

                    if (
                        !confirm(
                            'このグループを削除しますか？'
                        )
                    ) {
                        return;
                    }

                    editingSurvey.groups.splice(
                        groupIndex,
                        1
                    );

                    renderGroups();
                });
            }
        );
    }

    function addGroup() {
        if (!editingSurvey) {
            return;
        }

        editingSurvey.groups.push({
            id: nextGroupId++,
            name: '新しいグループ',
            questions: [
                {
                    id: nextQuestionId++,
                    text: '',
                    type: 'free',
                    required: false,
                    options: []
                }
            ]
        });

        renderGroups();
    }

    function saveSurvey() {
        if (!editingSurvey) {
            return;
        }

        const name = $('survey-name');

        if (!name) {
            return;
        }

        editingSurvey.name = name.value.trim();
        editingSurvey.description =
            $('survey-description')?.value.trim() || '';
        editingSurvey.status =
            $('survey-status')?.value || 'draft';
        editingSurvey.start =
            $('survey-start')?.value || '';
        editingSurvey.end =
            $('survey-end')?.value || '';

        const numbering = document.querySelector(
            'input[name="numbering"]:checked'
        );

        editingSurvey.numbering =
            numbering?.value || 'global';

        if (!editingSurvey.name) {
            alert('アンケート名を入力してください。');
            return;
        }

        if (!editingSurvey.groups.length) {
            alert('グループを1つ以上設定してください。');
            return;
        }

        const existing = editingSurvey.id
            ? surveys.find(function (survey) {
                return survey.id === editingSurvey.id;
            })
            : null;

        if (existing) {
            Object.assign(existing, cloneSurvey(editingSurvey));
            existing.updated = new Date()
                .toISOString()
                .slice(0, 10);
        } else {
            editingSurvey.id =
                Math.max(
                    0,
                    ...surveys.map(function (survey) {
                        return survey.id;
                    })
                ) + 1;

            editingSurvey.created =
                new Date().toISOString().slice(0, 10);
            editingSurvey.updated =
                editingSurvey.created;

            surveys.push(cloneSurvey(editingSurvey));
        }

        showToast('アンケートを保存しました。');
        showList();
    }

    function previewEditor() {
        if (!editingSurvey) {
            return;
        }

        let html =
            '<div class="notice success">アンケート内容を確認しています。</div>';

        editingSurvey.groups.forEach(
            function (group, groupIndex) {
                html +=
                    '<h3>' +
                    escapeHtml(group.name) +
                    '</h3>';

                group.questions.forEach(
                    function (question, questionIndex) {
                        html +=
                            '<div style="padding:10px 0;border-bottom:1px solid #eee">' +
                            '<strong>Q' +
                            (groupIndex + 1) +
                            '-' +
                            (questionIndex + 1) +
                            ' ' +
                            escapeHtml(question.text) +
                            '</strong>';

                        if (
                            question.type !== 'free'
                        ) {
                            html += '<ul>';

                            question.options.forEach(
                                function (option) {
                                    html +=
                                        '<li>' +
                                        escapeHtml(option.text) +
                                        '</li>';
                                }
                            );

                            html += '</ul>';
                        }

                        html += '</div>';
                    }
                );
            }
        );

        openModal(
            'アンケート内容確認',
            html,
            '<button class="btn" id="preview-close">閉じる</button>'
        );

        const closeButton = $('preview-close');

        if (closeButton) {
            closeButton.addEventListener(
                'click',
                closeModal
            );
        }
    }

    function countQuestions(survey) {
        return survey.groups.reduce(
            function (count, group) {
                return count + group.questions.length;
            },
            0
        );
    }

    function openDetail(id) {
        const survey = surveys.find(
            function (item) {
                return item.id === id;
            }
        );

        if (!survey) {
            return;
        }

        currentSurveyId = id;

        const title = $('detail-title');
        const subtitle = $('detail-subtitle');

        if (title) {
            title.textContent = survey.name;
        }

        if (subtitle) {
            subtitle.textContent =
                '状態：' +
                (
                    survey.status === 'open'
                        ? '公開中'
                        : survey.status === 'end'
                            ? '終了'
                            : '下書き'
                );
        }

        showPage('page-detail');
        showDetailTab('content');
    }

    function showDetailTab(tab) {
        const survey = surveys.find(
            function (item) {
                return item.id === currentSurveyId;
            }
        );

        if (!survey) {
            return;
        }

        [
            'tab-content',
            'tab-send',
            'tab-status',
            'tab-result'
        ].forEach(function (id) {
            const button = $(id);

            if (button) {
                button.classList.remove('active');
            }
        });

        const activeTab = $('tab-' + tab);

        if (activeTab) {
            activeTab.classList.add('active');
        }

        if (tab === 'content') {
            renderDetailContent(survey);
        } else if (tab === 'send') {
            renderSendPage(survey);
        } else if (tab === 'status') {
            renderStatusPage(survey);
        } else {
            renderResultPage(survey);
        }
    }

    function renderDetailContent(survey) {
        const content = $('detail-content');

        if (!content) {
            return;
        }

        let html =
            '<div class="card">' +
            '<div class="card-title">アンケート内容</div>' +
            '<p>' +
            escapeHtml(survey.description) +
            '</p>';

        survey.groups.forEach(
            function (group, groupIndex) {
                html +=
                    '<h3>' +
                    escapeHtml(group.name) +
                    '</h3>';

                group.questions.forEach(
                    function (question, questionIndex) {
                        html +=
                            '<div class="question-card">' +
                            '<strong>Q' +
                            (groupIndex + 1) +
                            '-' +
                            (questionIndex + 1) +
                            ' ' +
                            escapeHtml(question.text) +
                            '</strong>';

                        if (
                            question.type !== 'free'
                        ) {
                            html += '<ul>';

                            question.options.forEach(
                                function (option) {
                                    html +=
                                        '<li>' +
                                        escapeHtml(option.text) +
                                        '</li>';
                                }
                            );

                            html += '</ul>';
                        }

                        html += '</div>';
                    }
                );
            }
        );

        html += '</div>';

        content.innerHTML = html;
    }

    function renderSendPage(survey) {
        const content = $('detail-content');

        if (!content) {
            return;
        }

        const mailReady =
            mailSettings &&
            mailSettings.ready === true;

        content.innerHTML =
            '<div class="send-layout">' +
            '<div class="card">' +
            '<div class="card-title">送信対象者</div>' +
            (
                mailReady
                    ? ''
                    : '<div class="notice warning">' +
                      'メール送信設定が未完了です。' +
                      '送信前に「設定」からメール送信設定を確認してください。' +
                      '</div>'
            ) +
            '<div id="send-customer-list"></div>' +
            '</div>' +
            '<div class="card">' +
            '<div class="card-title">メール内容</div>' +
            '<div class="field">' +
            '<label>件名</label>' +
            '<input id="send-subject" value="' +
            escapeHtml(survey.name) +
            '">' +
            '</div>' +
            '<div class="field">' +
            '<label>本文</label>' +
            '<textarea id="send-body">アンケートへのご協力をお願いいたします。</textarea>' +
            '</div>' +
            '<button class="btn btn-primary" id="btn-send-mail"' +
            (
                mailReady
                    ? ''
                    : ' disabled'
            ) +
            '>メールを送信</button>' +
            '</div>' +
            '</div>';

        renderSendCustomers();
    }

    function renderSendCustomers() {
        const container = $('send-customer-list');

        if (!container) {
            return;
        }

        container.innerHTML =
            '<div class="selection-summary">' +
            '選択中：' +
            String(selectedCustomers.length) +
            '名' +
            '</div>' +
            customers.map(
                function (customer) {
                    const checked =
                        selectedCustomers.some(
                            function (id) {
                                return id === customer.id;
                            }
                        );

                    return (
                        '<label style="display:block;padding:8px 0">' +
                        '<input type="checkbox" class="send-customer" ' +
                        'data-id="' +
                        String(customer.id) +
                        '"' +
                        (checked ? ' checked' : '') +
                        '> ' +
                        escapeHtml(customer.name) +
                        '（' +
                        escapeHtml(customer.email) +
                        '）' +
                        '</label>'
                    );
                }
            ).join('');

        container
            .querySelectorAll('.send-customer')
            .forEach(function (checkbox) {
                checkbox.addEventListener(
                    'change',
                    function () {
                        const id =
                            Number(checkbox.dataset.id);

                        if (checkbox.checked) {
                            if (
                                !selectedCustomers.includes(id)
                            ) {
                                selectedCustomers.push(id);
                            }
                        } else {
                            selectedCustomers =
                                selectedCustomers.filter(
                                    function (value) {
                                        return value !== id;
                                    }
                                );
                        }

                        renderSendCustomers();
                    }
                );
            });

        const sendButton = $('btn-send-mail');

        if (sendButton) {
            sendButton.addEventListener(
                'click',
                function () {
                    sendSurveyMail(sendButton);
                }
            );
        }
    }

    function sendSurveyMail(button) {
        if (!mailSettings.ready) {
            alert(
                'メール送信設定が未完了です。'
            );
            return;
        }

        if (!selectedCustomers.length) {
            alert(
                '送信対象者を選択してください。'
            );
            return;
        }

        button.disabled = true;
        button.classList.add('loading');

        window.setTimeout(function () {
            button.disabled = false;
            button.classList.remove('loading');

            showToast(
                'メール送信処理を受け付けました。'
            );
        }, 800);
    }

    function renderStatusPage(survey) {
        const content = $('detail-content');

        if (!content) {
            return;
        }

        const target = Number(survey.target || 0);
        const answers = Number(survey.answers || 0);
        const rate =
            target > 0
                ? Math.round((answers / target) * 100)
                : 0;

        content.innerHTML =
            '<div class="detail-summary">' +
            '<div class="stat-card"><div class="stat-label">回答数</div>' +
            '<div class="stat-value">' +
            String(answers) +
            '</div></div>' +
            '<div class="stat-card"><div class="stat-label">回答率</div>' +
            '<div class="stat-value">' +
            String(rate) +
            '%</div></div>' +
            '<div class="stat-card"><div class="stat-label">送信数</div>' +
            '<div class="stat-value">' +
            String(survey.sent || 0) +
            '</div></div>' +
            '<div class="stat-card"><div class="stat-label">対象者数</div>' +
            '<div class="stat-value">' +
            String(target) +
            '</div></div>' +
            '</div>';
    }

    function renderResultPage(survey) {
        const content = $('detail-content');

        if (!content) {
            return;
        }

        content.innerHTML =
            '<div class="card">' +
            '<div class="card-title">回答結果</div>' +
            '<div class="notice">' +
            '現在の回答結果を確認できます。' +
            '</div>' +
            '<p>総回答数：' +
            String(survey.answers || 0) +
            '件</p>' +
            '</div>';
    }

    function publishSurvey(id) {
        const survey = surveys.find(
            function (item) {
                return item.id === id;
            }
        );

        if (!survey) {
            return;
        }

        if (
            !survey.name ||
            !countQuestions(survey)
        ) {
            alert(
                '公開するにはアンケート内容を設定してください。'
            );
            return;
        }

        if (
            !confirm(
                '「' +
                survey.name +
                '」を公開しますか？'
            )
        ) {
            return;
        }

        survey.status = 'open';
        survey.updated =
            new Date().toISOString().slice(0, 10);

        showToast(
            'アンケートを公開しました。'
        );

        renderList();
    }

    function endSurvey(id) {
        const survey = surveys.find(
            function (item) {
                return item.id === id;
            }
        );

        if (!survey) {
            return;
        }

        if (
            !confirm(
                '「' +
                survey.name +
                '」の回答受付を終了しますか？'
            )
        ) {
            return;
        }

        survey.status = 'end';
        survey.updated =
            new Date().toISOString().slice(0, 10);

        showToast(
            'アンケートを終了しました。'
        );

        renderList();
    }

    function deleteSurvey(id) {
        const survey = surveys.find(
            function (item) {
                return item.id === id;
            }
        );

        if (!survey) {
            return;
        }

        if (
            !confirm(
                '下書き「' +
                survey.name +
                '」を削除しますか？'
            )
        ) {
            return;
        }

        surveys = surveys.filter(
            function (item) {
                return item.id !== id;
            }
        );

        showToast(
            'アンケートを削除しました。'
        );

        renderList();
    }

    function showCustomers() {
        showPage('page-customers');
        renderCustomers();
        renderCustomerStatus();
    }

    function renderCustomerStatus() {
        const element = $('customer-status');

        if (!element) {
            return;
        }

        element.innerHTML =
            '<div class="notice">' +
            '顧客一覧を表示しています。' +
            '</div>';
    }

    function renderCustomers() {
        const searchInput =
            $('customer-search');

        const search =
            searchInput
                ? searchInput.value.toLowerCase()
                : '';

        const filtered =
            customers.filter(
                function (customer) {
                    return (
                        !search ||
                        customer.name
                            .toLowerCase()
                            .includes(search) ||
                        customer.email
                            .toLowerCase()
                            .includes(search) ||
                        customer.company
                            .toLowerCase()
                            .includes(search) ||
                        customer.code
                            .toLowerCase()
                            .includes(search)
                    );
                }
            );

        const body = $('customer-body');

        if (!body) {
            return;
        }

        if (!filtered.length) {
            body.innerHTML =
                '<tr><td colspan="4" class="empty">' +
                '該当する顧客がありません。' +
                '</td></tr>';

            return;
        }

        body.innerHTML =
            filtered.map(
                function (customer) {
                    return (
                        '<tr>' +
                        '<td>' +
                        escapeHtml(customer.name) +
                        '</td>' +
                        '<td>' +
                        escapeHtml(customer.email) +
                        '</td>' +
                        '<td>' +
                        escapeHtml(customer.company) +
                        '</td>' +
                        '<td>' +
                        escapeHtml(customer.code) +
                        '</td>' +
                        '</tr>'
                    );
                }
            ).join('');
    }

    function showSettings(tab) {
        if (tab) {
            currentSettingsTab = tab;
        }

        showPage('page-settings');
        renderSettings();
    }

    function renderSettings() {
        const mailTab =
            $('settings-tab-mail');

        const kintoneTab =
            $('settings-tab-kintone');

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

    function renderMailSettings() {
        const content =
            $('settings-content');

        if (!content) {
            return;
        }

        const error =
            $('settings-load-error');

        if (error) {
            error.innerHTML =
                initialLoadError
                    ? '<div class="notice error">' +
                      escapeHtml(initialLoadError) +
                      '</div>'
                    : '';
        }

        content.innerHTML =
            '<div class="card">' +
            '<div class="card-title">メール送信設定</div>' +

            '<div class="status-line">' +
            '<span class="status-dot ' +
            (
                mailSettings.ready
                    ? 'ok'
                    : 'warn'
            ) +
            '"></span>' +
            '<span>' +
            (
                mailSettings.ready
                    ? 'メール送信可能な設定が保存されています。'
                    : 'メール送信設定が未完了です。'
            ) +
            '</span>' +
            '</div>' +

            '<div class="form-grid">' +

            '<div class="field">' +
            '<label>SMTPサーバ *</label>' +
            '<input id="smtp-server" value="' +
            escapeHtml(mailSettings.smtp) +
            '" placeholder="smtp.example.com">' +
            '</div>' +

            '<div class="field">' +
            '<label>ポート番号 *</label>' +
            '<input id="smtp-port" value="' +
            escapeHtml(mailSettings.port) +
            '" placeholder="587">' +
            '</div>' +

            '</div>' +

            '<div class="field">' +
            '<label>接続方式</label>' +
            '<select id="smtp-security">' +
            '<option value="なし"' +
            (
                mailSettings.security === 'なし'
                    ? ' selected'
                    : ''
            ) +
            '>なし</option>' +
            '<option value="STARTTLS"' +
            (
                mailSettings.security === 'STARTTLS'
                    ? ' selected'
                    : ''
            ) +
            '>STARTTLS</option>' +
            '<option value="SSL/TLS"' +
            (
                mailSettings.security === 'SSL/TLS'
                    ? ' selected'
                    : ''
            ) +
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
            '<input id="smtp-password" type="password" ' +
            'placeholder="' +
            (
                mailSettings.passwordConfigured
                    ? '変更する場合のみ入力'
                    : ''
            ) +
            '">' +
            '</div>' +

            '</div>' +

            '<div class="form-grid">' +

            '<div class="field">' +
            '<label>送信元メールアドレス *</label>' +
            '<input id="smtp-from" value="' +
            escapeHtml(mailSettings.from) +
            '" placeholder="survey@example.com">' +
            '</div>' +

            '<div class="field">' +
            '<label>送信元名</label>' +
            '<input id="smtp-from-name" value="' +
            escapeHtml(mailSettings.fromName) +
            '">' +
            '</div>' +

            '</div>' +

            '<div style="display:flex;gap:8px;margin-top:10px">' +
            '<button class="btn btn-primary" id="btn-save-mail">' +
            '設定を保存</button>' +
            '<button class="btn" id="btn-test-mail">' +
            '送信設定を確認</button>' +
            '</div>' +

            '</div>';

        const saveButton =
            $('btn-save-mail');

        if (saveButton) {
            saveButton.addEventListener(
                'click',
                function () {
                    saveMailSettings(saveButton);
                }
            );
        }

        const testButton =
            $('btn-test-mail');

        if (testButton) {
            testButton.addEventListener(
                'click',
                function () {
                    testMailSettings(testButton);
                }
            );
        }
    }

    async function loadSettingsFromServer() {
        try {
            const result =
                await requestJson(
                    '?action=get_settings',
                    {
                        method: 'GET',
                        headers: {
                            'Accept':
                                'application/json'
                        }
                    }
                );

            if (result.mail) {
                mailSettings = result.mail;
            }

            if (
                currentSettingsTab === 'mail' &&
                !$('page-settings')?.classList.contains('hidden')
            ) {
                renderMailSettings();
            }
        } catch (error) {
            const settingsError =
                $('settings-load-error');

            if (settingsError) {
                settingsError.innerHTML =
                    '<div class="notice error">' +
                    escapeHtml(error.message) +
                    '</div>';
            }
        }
    }

    async function saveMailSettings(button) {
        /*
         * 非同期通信開始前に即座にボタンを無効化
         */
        button.disabled = true;
        button.classList.add('loading');

        try {
            const password =
                $('smtp-password')?.value || '';

            const payload = {
                smtp:
                    $('smtp-server')?.value.trim() || '',
                port:
                    $('smtp-port')?.value.trim() || '',
                security:
                    $('smtp-security')?.value || '',
                username:
                    $('smtp-user')?.value.trim() || '',
                password: password,
                from:
                    $('smtp-from')?.value.trim() || '',
                fromName:
                    $('smtp-from-name')?.value.trim() || ''
            };

            const result =
                await requestJson(
                    '?action=save_mail_settings',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type':
                                'application/json',
                            'X-CSRF-Token':
                                csrfToken,
                            'Accept':
                                'application/json'
                        },
                        body:
                            JSON.stringify(payload)
                    }
                );

            mailSettings =
                result.mail;

            renderMailSettings();

            showToast(
                'メール送信設定を保存しました。'
            );
        } catch (error) {
            alert(error.message);
        } finally {
            button.disabled = false;
            button.classList.remove('loading');
        }
    }

    async function testMailSettings(button) {
        /*
         * 非同期通信開始前に即座にボタンを無効化
         */
        button.disabled = true;
        button.classList.add('loading');

        try {
            const result =
                await requestJson(
                    '?action=test_mail_settings',
                    {
                        method: 'POST',
                        headers: {
                            'X-CSRF-Token':
                                csrfToken,
                            'Accept':
                                'application/json'
                        }
                    }
                );

            const mail =
                result.mail || {};

            openModal(
                'メール送信設定の確認',
                '<div class="notice success">' +
                escapeHtml(result.message) +
                '</div>' +
                '<p><strong>SMTPサーバ：</strong>' +
                escapeHtml(mail.smtp) +
                '</p>' +
                '<p><strong>ポート：</strong>' +
                escapeHtml(mail.port) +
                '</p>' +
                '<p><strong>接続方式：</strong>' +
                escapeHtml(mail.security) +
                '</p>' +
                '<p><strong>送信元：</strong>' +
                escapeHtml(mail.fromName) +
                ' &lt;' +
                escapeHtml(mail.from) +
                '&gt;</p>',
                '<button class="btn" id="btn-close-test">閉じる</button>'
            );

            const closeButton =
                $('btn-close-test');

            if (closeButton) {
                closeButton.addEventListener(
                    'click',
                    closeModal
                );
            }
        } catch (error) {
            alert(error.message);
        } finally {
            button.disabled = false;
            button.classList.remove('loading');
        }
    }

    function renderKintoneSettings() {
        const content =
            $('settings-content');

        if (!content) {
            return;
        }

        content.innerHTML =
            '<div class="card">' +
            '<div class="card-title">キントーン設定</div>' +
            '<div class="notice">' +
            'キントーン設定は保存済みの設定を利用します。' +
            '</div>' +
            '<p>キントーンへの接続設定を確認・変更できます。</p>' +
            '<div class="field">' +
            '<label>キントーンの利用先</label>' +
            '<input id="kt-domain" placeholder="example">' +
            '</div>' +
            '<div class="field">' +
            '<label>顧客管理アプリID</label>' +
            '<input id="kt-appid">' +
            '</div>' +
            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>ログイン名</label>' +
            '<input id="kt-user">' +
            '</div>' +
            '<div class="field">' +
            '<label>パスワード</label>' +
            '<input id="kt-password" type="password">' +
            '</div>' +
            '</div>' +
            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>プロキシ ホスト名</label>' +
            '<input id="kt-proxy-host">' +
            '</div>' +
            '<div class="field">' +
            '<label>プロキシ ポート番号</label>' +
            '<input id="kt-proxy-port">' +
            '</div>' +
            '</div>' +
            '<div style="display:flex;gap:8px">' +
            '<button class="btn btn-primary" id="btn-save-kintone">' +
            '設定を保存</button>' +
            '<button class="btn" id="btn-test-kintone">' +
            '接続設定を確認</button>' +
            '</div>' +
            '</div>';

        const saveButton =
            $('btn-save-kintone');

        if (saveButton) {
            saveButton.addEventListener(
                'click',
                function () {
                    saveButton.disabled = true;
                    saveButton.classList.add('loading');

                    window.setTimeout(
                        function () {
                            saveButton.disabled = false;
                            saveButton.classList.remove(
                                'loading'
                            );

                            showToast(
                                'キントーン設定を保存しました。'
                            );
                        },
                        500
                    );
                }
            );
        }

        const testButton =
            $('btn-test-kintone');

        if (testButton) {
            testButton.addEventListener(
                'click',
                function () {
                    testButton.disabled = true;
                    testButton.classList.add('loading');

                    window.setTimeout(
                        function () {
                            testButton.disabled = false;
                            testButton.classList.remove(
                                'loading'
                            );

                            showToast(
                                'キントーン接続設定を確認しました。'
                            );
                        },
                        500
                    );
                }
            );
        }
    }

    function bindNavigation() {
        const navList = $('nav-list');

        if (navList) {
            navList.addEventListener(
                'click',
                showList
            );
        }

        const navCreate = $('nav-create');

        if (navCreate) {
            navCreate.addEventListener(
                'click',
                openCreate
            );
        }

        const navCustomers =
            $('nav-customers');

        if (navCustomers) {
            navCustomers.addEventListener(
                'click',
                showCustomers
            );
        }

        const navSettings =
            $('nav-settings');

        if (navSettings) {
            navSettings.addEventListener(
                'click',
                function () {
                    showSettings('mail');
                }
            );
        }

        const createButton =
            $('btn-create');

        if (createButton) {
            createButton.addEventListener(
                'click',
                openCreate
            );
        }

        const editorBack =
            $('btn-editor-back');

        if (editorBack) {
            editorBack.addEventListener(
                'click',
                showList
            );
        }

        const addGroupButton =
            $('btn-add-group');

        if (addGroupButton) {
            addGroupButton.addEventListener(
                'click',
                addGroup
            );
        }

        const previewButton =
            $('btn-preview');

        if (previewButton) {
            previewButton.addEventListener(
                'click',
                previewEditor
            );
        }

        const saveSurveyButton =
            $('btn-save-survey');

        if (saveSurveyButton) {
            saveSurveyButton.addEventListener(
                'click',
                function () {
                    saveSurveyButton.disabled = true;
                    saveSurveyButton.classList.add(
                        'loading'
                    );

                    try {
                        saveSurvey();
                    } finally {
                        saveSurveyButton.disabled = false;
                        saveSurveyButton.classList.remove(
                            'loading'
                        );
                    }
                }
            );
        }

        const detailEdit =
            $('btn-detail-edit');

        if (detailEdit) {
            detailEdit.addEventListener(
                'click',
                function () {
                    if (currentSurveyId !== null) {
                        editSurvey(
                            currentSurveyId
                        );
                    }
                }
            );
        }

        const detailSend =
            $('btn-detail-send');

        if (detailSend) {
            detailSend.addEventListener(
                'click',
                function () {
                    showDetailTab('send');
                }
            );
        }

        const detailBack =
            $('btn-detail-back');

        if (detailBack) {
            detailBack.addEventListener(
                'click',
                showList
            );
        }

        const contentTab =
            $('tab-content');

        if (contentTab) {
            contentTab.addEventListener(
                'click',
                function () {
                    showDetailTab('content');
                }
            );
        }

        const sendTab =
            $('tab-send');

        if (sendTab) {
            sendTab.addEventListener(
                'click',
                function () {
                    showDetailTab('send');
                }
            );
        }

        const statusTab =
            $('tab-status');

        if (statusTab) {
            statusTab.addEventListener(
                'click',
                function () {
                    showDetailTab('status');
                }
            );
        }

        const resultTab =
            $('tab-result');

        if (resultTab) {
            resultTab.addEventListener(
                'click',
                function () {
                    showDetailTab('result');
                }
            );
        }

        const customerSettings =
            $('btn-customer-settings');

        if (customerSettings) {
            customerSettings.addEventListener(
                'click',
                function () {
                    showSettings('kintone');
                }
            );
        }

        const customerSearch =
            $('customer-search');

        if (customerSearch) {
            customerSearch.addEventListener(
                'input',
                renderCustomers
            );
        }

        const refreshCustomers =
            $('btn-refresh-customers');

        if (refreshCustomers) {
            refreshCustomers.addEventListener(
                'click',
                function () {
                    refreshCustomers.disabled = true;
                    refreshCustomers.classList.add(
                        'loading'
                    );

                    window.setTimeout(
                        function () {
                            refreshCustomers.disabled = false;
                            refreshCustomers.classList.remove(
                                'loading'
                            );
                            renderCustomers();
                            showToast(
                                '顧客一覧を更新しました。'
                            );
                        },
                        500
                    );
                }
            );
        }

        const mailSettingsTab =
            $('settings-tab-mail');

        if (mailSettingsTab) {
            mailSettingsTab.addEventListener(
                'click',
                function () {
                    showSettings('mail');
                }
            );
        }

        const kintoneSettingsTab =
            $('settings-tab-kintone');

        if (kintoneSettingsTab) {
            kintoneSettingsTab.addEventListener(
                'click',
                function () {
                    showSettings('kintone');
                }
            );
        }

        const modalClose =
            $('btn-modal-close');

        if (modalClose) {
            modalClose.addEventListener(
                'click',
                closeModal
            );
        }

        const modal =
            $('modal');

        if (modal) {
            modal.addEventListener(
                'click',
                function (event) {
                    if (event.target === modal) {
                        closeModal();
                    }
                }
            );
        }

        document
            .querySelectorAll(
                'input[name="numbering"]'
            )
            .forEach(function (radio) {
                radio.addEventListener(
                    'change',
                    function () {
                        if (editingSurvey) {
                            editingSurvey.numbering =
                                radio.value;
                            renderGroups();
                        }
                    }
                );
            });
    }

    /*
     * 初期表示
     */
    bindNavigation();
    renderList();
    showPage('page-list');

    /*
     * 画面表示時にもサーバーの settings.json を読み込む。
     * これにより、別画面・別操作で保存された設定も
     * 最新状態になる。
     */
    loadSettingsFromServer();
});
</script>

</body>
</html>

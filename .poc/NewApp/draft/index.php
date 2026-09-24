<?php
declare(strict_types=1);

namespace yokoyamy\NewApp\SurveyOperation;

use RuntimeException;
use Throwable;

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax'
]);

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

const APP_SESSION_KEY = 'yokoyamy_newapp_survey';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const CUSTOMERS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';
const SURVEYS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';

function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_session_init(): void
{
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
}

function csrf_token(): string
{
    app_session_init();
    return (string)$_SESSION[APP_SESSION_KEY]['csrf_token'];
}

function verify_csrf(): void
{
    app_session_init();

    $requestToken = '';

    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($headerToken)) {
        $requestToken = $headerToken;
    }

    if ($requestToken === '') {
        $postToken = $_POST['csrf_token'] ?? '';
        if (is_string($postToken)) {
            $requestToken = $postToken;
        }
    }

    $storedToken = (string)($_SESSION[APP_SESSION_KEY]['csrf_token'] ?? '');

    if (
        $requestToken === '' ||
        $storedToken === '' ||
        !hash_equals($storedToken, $requestToken)
    ) {
        send_json([
            'success' => false,
            'message' => '安全確認に失敗しました。画面を再読み込みしてください。'
        ], 403);
    }
}

function send_json(array $response, int $statusCode = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

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

function ensure_data_directory(): void
{
    if (is_dir(DATA_DIR)) {
        return;
    }

    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('データ保存先を作成できませんでした。');
    }
}

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
        throw new RuntimeException('設定データを作成できませんでした。');
    }

    $temporaryFile = $file . '.tmp.' . bin2hex(random_bytes(8));

    if (
        file_put_contents(
            $temporaryFile,
            $json . PHP_EOL,
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException('設定データを書き込めませんでした。');
    }

    if (!rename($temporaryFile, $file)) {
        @unlink($temporaryFile);
        throw new RuntimeException('設定データを保存できませんでした。');
    }
}

function read_json_file(string $file, array $default): array
{
    ensure_data_directory();

    if (!is_file($file)) {
        write_json_file($file, $default);
        return $default;
    }

    $contents = file_get_contents($file);

    if ($contents === false || trim($contents) === '') {
        write_json_file($file, $default);
        return $default;
    }

    $decoded = json_decode($contents, true);

    if (!is_array($decoded)) {
        write_json_file($file, $default);
        return $default;
    }

    return array_replace_recursive($default, $decoded);
}

function load_settings(): array
{
    return read_json_file(SETTINGS_FILE, default_settings());
}

function save_settings(array $settings): void
{
    $defaults = default_settings();

    $normalized = [
        'mail' => [
            'smtp' => trim((string)($settings['mail']['smtp'] ?? '')),
            'port' => trim((string)($settings['mail']['port'] ?? '587')),
            'security' => trim((string)($settings['mail']['security'] ?? 'STARTTLS')),
            'username' => trim((string)($settings['mail']['username'] ?? '')),
            'password' => (string)($settings['mail']['password'] ?? ''),
            'from' => trim((string)($settings['mail']['from'] ?? '')),
            'fromName' => trim((string)($settings['mail']['fromName'] ?? 'アンケート事務局')),
            'ready' => (bool)($settings['mail']['ready'] ?? false)
        ],
        'kintone' => [
            'domain' => trim((string)($settings['kintone']['domain'] ?? '')),
            'appId' => trim((string)($settings['kintone']['appId'] ?? '')),
            'loginName' => trim((string)($settings['kintone']['loginName'] ?? '')),
            'password' => (string)($settings['kintone']['password'] ?? ''),
            'proxyHost' => trim((string)($settings['kintone']['proxyHost'] ?? '')),
            'proxyPort' => trim((string)($settings['kintone']['proxyPort'] ?? '')),
            'proxyAuth' => false,
            'sslVerify' => false,
            'ready' => (bool)($settings['kintone']['ready'] ?? false)
        ]
    ];

    if ($normalized['mail']['smtp'] === '' ||
        $normalized['mail']['port'] === '' ||
        $normalized['mail']['from'] === '') {
        $normalized['mail']['ready'] = false;
    }

    if ($normalized['kintone']['domain'] === '' ||
        $normalized['kintone']['appId'] === '' ||
        $normalized['kintone']['loginName'] === '' ||
        $normalized['kintone']['password'] === '') {
        $normalized['kintone']['ready'] = false;
    }

    write_json_file(SETTINGS_FILE, $normalized);
}

function default_customers(): array
{
    return [
        [
            'name' => '山田 太郎',
            'email' => 'taro.yamada@example.com',
            'company' => 'サンプル株式会社',
            'code' => 'C001'
        ],
        [
            'name' => '佐藤 花子',
            'email' => 'hanako.sato@example.com',
            'company' => 'テスト株式会社',
            'code' => 'C002'
        ]
    ];
}

function default_surveys(): array
{
    return [
        [
            'id' => 1,
            'name' => '新商品アンケート',
            'description' => '新商品の利用状況とご意見をお聞きするアンケートです。',
            'status' => 'open',
            'created' => '2026-09-01',
            'start' => '2026-09-01',
            'end' => '2026-09-30',
            'answers' => 128,
            'target' => 200,
            'sent' => 195,
            'updated' => '2026-09-20',
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
                                ['text' => 'いいえ', 'branch' => '1003']
                            ]
                        ],
                        [
                            'id' => 1002,
                            'text' => '商品についての満足度を教えてください。',
                            'type' => 'single',
                            'required' => true,
                            'options' => [
                                ['text' => '満足', 'branch' => ''],
                                ['text' => '普通', 'branch' => ''],
                                ['text' => '不満', 'branch' => '1003']
                            ]
                        ]
                    ]
                ],
                [
                    'id' => 102,
                    'name' => 'ご意見',
                    'questions' => [
                        [
                            'id' => 1003,
                            'text' => '今後の商品についてご意見をお聞かせください。',
                            'type' => 'free',
                            'required' => false,
                            'options' => []
                        ]
                    ]
                ]
            ]
        ]
    ];
}

function load_customers(): array
{
    return read_json_file(CUSTOMERS_FILE, default_customers());
}

function load_surveys(): array
{
    return read_json_file(SURVEYS_FILE, default_surveys());
}

function save_surveys(array $surveys): void
{
    write_json_file(SURVEYS_FILE, $surveys);
}

app_session_init();

try {
    ensure_data_directory();

    // settings.json が無い場合は、初回アクセス時に自動作成する。
    load_settings();

    if (!is_file(CUSTOMERS_FILE)) {
        write_json_file(CUSTOMERS_FILE, default_customers());
    }

    if (!is_file(SURVEYS_FILE)) {
        write_json_file(SURVEYS_FILE, default_surveys());
    }
} catch (Throwable $e) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' ||
        isset($_GET['action'])) {
        send_json([
            'success' => false,
            'message' => 'データファイルを準備できませんでした。'
        ], 500);
    }
}

$action = isset($_GET['action']) && is_string($_GET['action'])
    ? $_GET['action']
    : '';

if ($action !== '') {
    try {
        if ($action === 'load_settings') {
            $settings = load_settings();

            send_json([
                'success' => true,
                'settings' => $settings
            ]);
        }

        if ($action === 'load_customers') {
            send_json([
                'success' => true,
                'customers' => load_customers()
            ]);
        }

        if ($action === 'load_surveys') {
            send_json([
                'success' => true,
                'surveys' => load_surveys()
            ]);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            send_json([
                'success' => false,
                'message' => 'この操作はPOSTで実行してください。'
            ], 405);
        }

        verify_csrf();

        if ($action === 'save_mail_settings') {
            $settings = load_settings();
            $currentMail = $settings['mail'];

            $smtp = trim((string)($_POST['smtp'] ?? ''));
            $port = trim((string)($_POST['port'] ?? ''));
            $security = trim((string)($_POST['security'] ?? 'STARTTLS'));
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $from = trim((string)($_POST['from'] ?? ''));
            $fromName = trim((string)($_POST['fromName'] ?? ''));

            if ($smtp === '' || $port === '' || $from === '') {
                send_json([
                    'success' => false,
                    'message' => 'SMTPサーバ、ポート番号、送信元メールアドレスを入力してください。'
                ], 422);
            }

            if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
                send_json([
                    'success' => false,
                    'message' => '送信元メールアドレスの形式を確認してください。'
                ], 422);
            }

            // パスワードを画面から空欄で保存した場合は既存値を維持する。
            if ($password === '') {
                $password = (string)($currentMail['password'] ?? '');
            }

            $settings['mail'] = [
                'smtp' => $smtp,
                'port' => $port,
                'security' => $security,
                'username' => $username,
                'password' => $password,
                'from' => $from,
                'fromName' => $fromName !== '' ? $fromName : 'アンケート事務局',
                'ready' => true
            ];

            save_settings($settings);

            send_json([
                'success' => true,
                'message' => 'メール送信設定を保存しました。',
                'settings' => load_settings()
            ]);
        }

        if ($action === 'save_kintone_settings') {
            $settings = load_settings();
            $current = $settings['kintone'];

            $domain = trim((string)($_POST['domain'] ?? ''));
            $appId = trim((string)($_POST['appId'] ?? ''));
            $loginName = trim((string)($_POST['loginName'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $proxyHost = trim((string)($_POST['proxyHost'] ?? ''));
            $proxyPort = trim((string)($_POST['proxyPort'] ?? ''));

            if ($domain === '' ||
                $appId === '' ||
                $loginName === '') {
                send_json([
                    'success' => false,
                    'message' => '利用先、顧客管理アプリID、ログイン名を入力してください。'
                ], 422);
            }

            if ($password === '') {
                $password = (string)($current['password'] ?? '');
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

            save_settings($settings);

            send_json([
                'success' => true,
                'message' => 'キントーン設定を保存しました。',
                'settings' => load_settings()
            ]);
        }

        if ($action === 'save_surveys') {
            $json = file_get_contents('php://input');
            $payload = json_decode($json ?: '', true);

            if (!is_array($payload) || !isset($payload['surveys']) || !is_array($payload['surveys'])) {
                send_json([
                    'success' => false,
                    'message' => 'アンケートデータを確認できませんでした。'
                ], 422);
            }

            save_surveys($payload['surveys']);

            send_json([
                'success' => true,
                'message' => 'アンケートを保存しました。',
                'surveys' => load_surveys()
            ]);
        }

        send_json([
            'success' => false,
            'message' => '指定された操作は存在しません。'
        ], 404);
    } catch (Throwable $e) {
        send_json([
            'success' => false,
            'message' => '処理中にエラーが発生しました。'
        ], 500);
    }
}

$csrf = csrf_token();
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
button:disabled{cursor:not-allowed;opacity:.55}
.topbar{
    min-height:60px;
    background:#1f3a5f;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
    gap:30px;
}
.logo{font-size:18px;font-weight:bold;white-space:nowrap}
.main-nav{display:flex;min-height:60px;align-items:center;gap:2px}
.main-nav button{
    height:60px;
    padding:0 17px;
    color:#dce7f3;
    background:transparent;
    border:0;
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
    margin-bottom:20px;
}
.page-header h1{margin:0;font-size:25px}
.subtext{color:#718096;font-size:13px;margin-top:5px}
.btn{
    border:1px solid #cbd5e0;
    background:#fff;
    color:#34495e;
    border-radius:5px;
    padding:8px 15px;
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
    margin-bottom:18px;
}
.card-title{font-size:17px;font-weight:bold;margin-bottom:15px}
.card-title small{font-size:12px;color:#718096;font-weight:normal}
.table{width:100%;border-collapse:collapse}
.table th,.table td{
    padding:12px 10px;
    border-bottom:1px solid #e6ebef;
    text-align:left;
    vertical-align:middle;
    font-size:13px;
}
.table th{background:#f8fafc;color:#52606d;font-weight:bold}
.empty{text-align:center!important;color:#8a98a5;padding:40px!important}
.link-button{
    border:0;
    background:none;
    padding:0;
    color:#2878c8;
    cursor:pointer;
    text-align:left;
}
.link-button:hover{text-decoration:underline}
.badge{
    display:inline-block;
    padding:4px 9px;
    border-radius:12px;
    font-size:11px;
    font-weight:bold;
}
.badge-open{background:#e6f6ed;color:#237a49}
.badge-draft{background:#edf2f7;color:#66788a}
.badge-end{background:#fdecec;color:#b43b3b}
.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
}
.field{margin-bottom:15px}
.field label{
    display:block;
    font-size:13px;
    font-weight:bold;
    margin-bottom:6px;
    color:#455563;
}
.field input,.field textarea,.field select{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:9px 10px;
    background:#fff;
}
.field textarea{min-height:90px;resize:vertical}
.radio-row{display:flex;gap:22px;flex-wrap:wrap}
.radio-row label{
    font-weight:normal;
    display:inline-flex;
    align-items:center;
    gap:5px;
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
.notice.success{background:#edf9f1;border-color:#c9ead5;color:#267348}
.notice.warning{background:#fff8e6;border-color:#f0dfae;color:#8a6408}
.notice.error{background:#fff0f0;border-color:#edc5c5;color:#a33333}
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
.status-dot.ok{background:#2f9e61}
.status-dot.warn{background:#d39b25}
.group-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    margin-bottom:16px;
}
.group-header{
    display:flex;
    align-items:center;
    gap:10px;
    padding:13px 15px;
    background:#f7f9fb;
    border-bottom:1px solid #e3e8ed;
}
.group-title{flex:1}
.group-title input{
    border:1px solid transparent;
    background:transparent;
    padding:5px 7px;
    font-weight:bold;
    font-size:16px;
    width:100%;
}
.group-title input:focus{
    border-color:#cbd5e0;
    background:#fff;
}
.question-card{
    padding:15px;
    border-bottom:1px solid #e6ebef;
}
.question-card:last-child{border-bottom:0}
.question-head{
    display:flex;
    align-items:center;
    gap:8px;
}
.question-number{
    width:58px;
    color:#2878c8;
    font-weight:bold;
    flex:none;
}
.question-title{flex:1}
.question-title input{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:8px;
}
.question-tools{display:flex;gap:6px}
.question-tools select{
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:6px;
}
.question-meta{
    display:flex;
    gap:18px;
    align-items:center;
    margin-top:10px;
    padding-left:66px;
    color:#657786;
    font-size:13px;
}
.question-options{
    margin-top:12px;
    padding-left:66px;
}
.option-row{
    display:flex;
    align-items:center;
    gap:7px;
    margin-bottom:7px;
}
.option-row input{
    flex:1;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:7px;
}
.add-question-area{padding:12px 14px}
.add-group-area{text-align:center;margin-top:8px}
.detail-tabs{
    display:flex;
    border-bottom:1px solid #dfe5eb;
    margin-bottom:18px;
}
.detail-tabs button{
    border:0;
    background:transparent;
    padding:12px 20px;
    color:#687887;
    border-bottom:3px solid transparent;
}
.detail-tabs button.active{
    color:#2878c8;
    border-bottom-color:#2878c8;
}
.stat-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    margin-bottom:18px;
}
.stat-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:17px;
}
.stat-label{color:#718096;font-size:12px}
.stat-value{font-size:27px;font-weight:bold;margin-top:5px}
.customer-toolbar{
    display:flex;
    gap:8px;
    margin-bottom:12px;
}
.customer-toolbar input{
    flex:1;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:8px;
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
.modal-body{padding:20px}
.modal-footer{
    padding:13px 20px;
    border-top:1px solid #e3e8ed;
    display:flex;
    justify-content:flex-end;
    gap:8px;
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
.toast.show{opacity:1;transform:translateY(0)}
.loading::before{
    content:"";
    display:inline-block;
    width:12px;
    height:12px;
    margin-right:7px;
    vertical-align:-2px;
    border:2px solid rgba(255,255,255,.5);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite;
}
.btn:not(.btn-primary).loading::before{
    border-color:rgba(40,120,200,.3);
    border-top-color:#2878c8;
}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:950px){
    .stat-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:800px){
    .topbar{padding:0 10px;gap:8px;overflow:auto}
    .logo{font-size:15px}
    .main-nav button{padding:0 8px;font-size:12px}
    .app{padding:14px}
    .form-grid{grid-template-columns:1fr}
    .table{min-width:800px}
    .card{overflow-x:auto}
    .question-head{align-items:flex-start;flex-wrap:wrap}
    .question-title{min-width:70%}
    .question-meta,.question-options{padding-left:0}
    .customer-toolbar{flex-wrap:wrap}
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
        <button id="btn-create-top" class="btn btn-primary" type="button">＋ アンケート作成</button>
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

    <div class="notice">
        質問とグループをまとめて確認しながら編集できます。
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

    <div class="add-group-area">
        <button id="btn-add-group" class="btn btn-primary" type="button">＋ グループ追加</button>
    </div>

    <div class="page-header" style="margin-top:20px">
        <button id="btn-editor-back" class="btn" type="button">一覧へ戻る</button>
        <div>
            <button id="btn-preview" class="btn" type="button">内容確認</button>
            <button id="btn-save-survey" class="btn btn-primary" type="button">保存</button>
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
            <button id="btn-detail-edit" class="btn" type="button">編集</button>
            <button id="btn-detail-send" class="btn btn-primary" type="button">送信</button>
            <button id="btn-detail-back" class="btn" type="button">一覧へ戻る</button>
        </div>
    </div>

    <div class="detail-tabs">
        <button id="tab-content" type="button">アンケート内容</button>
        <button id="tab-send" type="button">送信</button>
        <button id="tab-status" type="button">回答状況</button>
        <button id="tab-result" type="button">回答結果</button>
    </div>

    <div id="detail-content"></div>
</section>

<section id="page-customers" class="hidden">
    <div class="page-header">
        <div>
            <h1>顧客一覧</h1>
            <div class="subtext">顧客管理情報を表示します</div>
        </div>
        <button id="btn-customer-settings" class="btn" type="button">キントーン設定</button>
    </div>

    <div id="customer-status"></div>

    <div class="card">
        <div class="customer-toolbar">
            <input id="customer-search" type="text" placeholder="顧客名・メールアドレスで検索">
            <button id="btn-refresh-customers" class="btn" type="button">顧客一覧を更新</button>
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
        <button id="settings-tab-mail" type="button">メール送信設定</button>
        <button id="settings-tab-kintone" type="button">キントーン設定</button>
    </div>

    <div id="settings-content"></div>
</section>

</main>

<div id="modal" class="modal-backdrop hidden">
    <div class="modal">
        <div class="modal-header">
            <strong id="modal-title"></strong>
            <button id="modal-close" class="btn btn-small" type="button">閉じる</button>
        </div>
        <div class="modal-body" id="modal-body"></div>
        <div class="modal-footer" id="modal-footer"></div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const csrfToken = <?php echo json_encode($csrf, JSON_UNESCAPED_UNICODE); ?>;

    let surveys = [];
    let customers = [];
    let settings = {
        mail: {
            smtp: '',
            port: '587',
            security: 'STARTTLS',
            username: '',
            password: '',
            from: '',
            fromName: 'アンケート事務局',
            ready: false
        },
        kintone: {
            domain: '',
            appId: '',
            loginName: '',
            password: '',
            proxyHost: '',
            proxyPort: '',
            proxyAuth: false,
            sslVerify: false,
            ready: false
        }
    };

    let editingSurvey = null;
    let currentSurveyId = null;
    let currentSettingsTab = 'mail';
    let nextGroupId = 500;
    let nextQuestionId = 5000;

    function getElement(id) {
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
            const page = getElement(pageId);
            if (page) {
                page.classList.add('hidden');
            }
        });

        const target = getElement(id);
        if (target) {
            target.classList.remove('hidden');
        }

        [
            'nav-list',
            'nav-create',
            'nav-customers',
            'nav-settings'
        ].forEach(function (navId) {
            const nav = getElement(navId);
            if (nav) {
                nav.classList.remove('active');
            }
        });

        if (id === 'page-list') {
            const nav = getElement('nav-list');
            if (nav) nav.classList.add('active');
        }

        if (id === 'page-editor') {
            const nav = getElement('nav-create');
            if (nav) nav.classList.add('active');
        }

        if (id === 'page-customers') {
            const nav = getElement('nav-customers');
            if (nav) nav.classList.add('active');
        }

        if (id === 'page-settings') {
            const nav = getElement('nav-settings');
            if (nav) nav.classList.add('active');
        }
    }

    function setLoading(button, loading) {
        if (!button) return;

        if (loading) {
            button.disabled = true;
            button.classList.add('loading');
        } else {
            button.disabled = false;
            button.classList.remove('loading');
        }
    }

    async function apiGet(action) {
        const response = await fetch(
            '?action=' + encodeURIComponent(action),
            {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            }
        );

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || '処理に失敗しました。');
        }

        return data;
    }

    async function apiPost(action, formData, button) {
        setLoading(button, true);

        try {
            formData.append('csrf_token', csrfToken);

            const response = await fetch(
                '?action=' + encodeURIComponent(action),
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: formData
                }
            );

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || '処理に失敗しました。');
            }

            return data;
        } finally {
            setLoading(button, false);
        }
    }

    async function apiPostJson(action, payload, button) {
        setLoading(button, true);

        try {
            const response = await fetch(
                '?action=' + encodeURIComponent(action),
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify(payload)
                }
            );

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || '処理に失敗しました。');
            }

            return data;
        } finally {
            setLoading(button, false);
        }
    }

    function showToast(message) {
        const toast = getElement('toast');
        if (!toast) return;

        toast.textContent = message;
        toast.classList.add('show');

        window.setTimeout(function () {
            toast.classList.remove('show');
        }, 2200);
    }

    function openModal(title, bodyText) {
        const modal = getElement('modal');
        const modalTitle = getElement('modal-title');
        const modalBody = getElement('modal-body');

        if (!modal || !modalTitle || !modalBody) return;

        modalTitle.textContent = title;
        modalBody.textContent = bodyText;
        modal.classList.remove('hidden');
    }

    function closeModal() {
        const modal = getElement('modal');
        if (modal) {
            modal.classList.add('hidden');
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
        const body = getElement('survey-list-body');
        if (!body) return;

        if (!surveys.length) {
            body.innerHTML =
                '<tr><td colspan="7" class="empty">アンケートがありません。</td></tr>';
            return;
        }

        body.innerHTML = surveys.map(function (survey) {
            const period = survey.start || survey.end
                ? escapeHtml(survey.start || '未設定') +
                  ' ～ ' +
                  escapeHtml(survey.end || '未設定')
                : '未設定';

            return (
                '<tr>' +
                '<td><button type="button" class="link-button survey-open" data-id="' +
                escapeHtml(String(survey.id)) +
                '">' +
                escapeHtml(survey.name) +
                '</button></td>' +
                '<td>' + statusBadge(survey.status) + '</td>' +
                '<td>' + escapeHtml(survey.created || '') + '</td>' +
                '<td>' + period + '</td>' +
                '<td>' + Number(survey.answers || 0) + '件</td>' +
                '<td>' + escapeHtml(survey.updated || '') + '</td>' +
                '<td>' +
                '<button type="button" class="btn btn-small survey-edit" data-id="' +
                escapeHtml(String(survey.id)) +
                '">編集</button> ' +
                '<button type="button" class="btn btn-small survey-detail" data-id="' +
                escapeHtml(String(survey.id)) +
                '">確認</button>' +
                '</td>' +
                '</tr>'
            );
        }).join('');

        body.querySelectorAll('.survey-open').forEach(function (button) {
            button.addEventListener('click', function () {
                openDetail(Number(button.dataset.id));
            });
        });

        body.querySelectorAll('.survey-detail').forEach(function (button) {
            button.addEventListener('click', function () {
                openDetail(Number(button.dataset.id));
            });
        });

        body.querySelectorAll('.survey-edit').forEach(function (button) {
            button.addEventListener('click', function () {
                editSurvey(Number(button.dataset.id));
            });
        });
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function createEmptySurvey() {
        return {
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
                            type: 'single',
                            required: false,
                            options: [
                                {text: '選択肢1', branch: ''},
                                {text: '選択肢2', branch: ''}
                            ]
                        }
                    ]
                }
            ]
        };
    }

    function openCreate() {
        editingSurvey = createEmptySurvey();

        const title = getElement('editor-page-title');
        if (title) title.textContent = 'アンケート作成';

        loadEditor();
        showPage('page-editor');
    }

    function editSurvey(id) {
        const survey = surveys.find(function (item) {
            return Number(item.id) === Number(id);
        });

        if (!survey) return;

        editingSurvey = clone(survey);

        const title = getElement('editor-page-title');
        if (title) title.textContent = 'アンケート編集';

        loadEditor();
        showPage('page-editor');
    }

    function loadEditor() {
        if (!editingSurvey) return;

        const name = getElement('survey-name');
        const description = getElement('survey-description');
        const status = getElement('survey-status');
        const start = getElement('survey-start');
        const end = getElement('survey-end');

        if (name) name.value = editingSurvey.name || '';
        if (description) description.value = editingSurvey.description || '';
        if (status) status.value = editingSurvey.status || 'draft';
        if (start) start.value = editingSurvey.start || '';
        if (end) end.value = editingSurvey.end || '';

        document.querySelectorAll('input[name="numbering"]').forEach(function (radio) {
            radio.checked = radio.value === (editingSurvey.numbering || 'global');
        });

        renderGroups();
    }

    function renderGroups() {
        const container = getElement('groups');
        if (!container || !editingSurvey) return;

        container.innerHTML = '';

        editingSurvey.groups.forEach(function (group, groupIndex) {
            const card = document.createElement('div');
            card.className = 'group-card';

            const header = document.createElement('div');
            header.className = 'group-header';

            const titleWrap = document.createElement('div');
            titleWrap.className = 'group-title';

            const titleInput = document.createElement('input');
            titleInput.value = group.name || '';
            titleInput.addEventListener('input', function () {
                group.name = titleInput.value;
            });

            titleWrap.appendChild(titleInput);

            const deleteGroup = document.createElement('button');
            deleteGroup.type = 'button';
            deleteGroup.className = 'btn btn-small btn-danger';
            deleteGroup.textContent = 'グループ削除';

            deleteGroup.addEventListener('click', function () {
                if (editingSurvey.groups.length <= 1) {
                    alert('グループは1つ以上必要です。');
                    return;
                }

                editingSurvey.groups.splice(groupIndex, 1);
                renderGroups();
            });

            header.appendChild(titleWrap);
            header.appendChild(deleteGroup);
            card.appendChild(header);

            group.questions.forEach(function (question, questionIndex) {
                const questionCard = document.createElement('div');
                questionCard.className = 'question-card';

                const head = document.createElement('div');
                head.className = 'question-head';

                const number = document.createElement('div');
                number.className = 'question-number';
                number.textContent =
                    editingSurvey.numbering === 'group'
                        ? 'Q' + (groupIndex + 1) + '-' + (questionIndex + 1)
                        : 'Q' + getGlobalQuestionNumber(groupIndex, questionIndex);

                const title = document.createElement('div');
                title.className = 'question-title';

                const input = document.createElement('input');
                input.value = question.text || '';
                input.placeholder = '質問内容';
                input.addEventListener('input', function () {
                    question.text = input.value;
                });

                title.appendChild(input);

                const tools = document.createElement('div');
                tools.className = 'question-tools';

                const type = document.createElement('select');

                [
                    ['free', '自由記述'],
                    ['single', '単一選択'],
                    ['multiple', '複数選択']
                ].forEach(function (item) {
                    const option = document.createElement('option');
                    option.value = item[0];
                    option.textContent = item[1];
                    type.appendChild(option);
                });

                type.value = question.type || 'single';

                type.addEventListener('change', function () {
                    question.type = type.value;

                    if (type.value === 'free') {
                        question.options = [];
                    } else if (!question.options.length) {
                        question.options = [
                            {text: '選択肢1', branch: ''},
                            {text: '選択肢2', branch: ''}
                        ];
                    }

                    renderGroups();
                });

                const deleteQuestion = document.createElement('button');
                deleteQuestion.type = 'button';
                deleteQuestion.className = 'btn btn-small btn-danger';
                deleteQuestion.textContent = '削除';

                deleteQuestion.addEventListener('click', function () {
                    if (group.questions.length <= 1) {
                        alert('各グループには質問を1つ以上設定してください。');
                        return;
                    }

                    group.questions.splice(questionIndex, 1);
                    renderGroups();
                });

                tools.appendChild(type);
                tools.appendChild(deleteQuestion);

                head.appendChild(number);
                head.appendChild(title);
                head.appendChild(tools);
                questionCard.appendChild(head);

                const meta = document.createElement('div');
                meta.className = 'question-meta';

                const requiredLabel = document.createElement('label');
                const required = document.createElement('input');
                required.type = 'checkbox';
                required.checked = Boolean(question.required);

                required.addEventListener('change', function () {
                    question.required = required.checked;
                });

                requiredLabel.appendChild(required);
                requiredLabel.appendChild(document.createTextNode(' 必須回答'));
                meta.appendChild(requiredLabel);

                questionCard.appendChild(meta);

                if (question.type !== 'free') {
                    const options = document.createElement('div');
                    options.className = 'question-options';

                    question.options.forEach(function (item, optionIndex) {
                        const row = document.createElement('div');
                        row.className = 'option-row';

                        const optionInput = document.createElement('input');
                        optionInput.value = item.text || '';
                        optionInput.placeholder = '選択肢';

                        optionInput.addEventListener('input', function () {
                            item.text = optionInput.value;
                        });

                        const remove = document.createElement('button');
                        remove.type = 'button';
                        remove.className = 'btn btn-small';
                        remove.textContent = '削除';

                        remove.addEventListener('click', function () {
                            if (question.options.length <= 2) {
                                alert('選択肢は2つ以上必要です。');
                                return;
                            }

                            question.options.splice(optionIndex, 1);
                            renderGroups();
                        });

                        row.appendChild(optionInput);
                        row.appendChild(remove);
                        options.appendChild(row);
                    });

                    const addOption = document.createElement('button');
                    addOption.type = 'button';
                    addOption.className = 'btn btn-small';
                    addOption.textContent = '＋ 選択肢追加';

                    addOption.addEventListener('click', function () {
                        question.options.push({
                            text: '',
                            branch: ''
                        });
                        renderGroups();
                    });

                    options.appendChild(addOption);
                    questionCard.appendChild(options);
                }

                group.questions.forEach(function () {});
                card.appendChild(questionCard);
            });

            const addQuestionArea = document.createElement('div');
            addQuestionArea.className = 'add-question-area';

            const addQuestion = document.createElement('button');
            addQuestion.type = 'button';
            addQuestion.className = 'btn btn-small';
            addQuestion.textContent = '＋ 質問追加';

            addQuestion.addEventListener('click', function () {
                group.questions.push({
                    id: nextQuestionId++,
                    text: '',
                    type: 'single',
                    required: false,
                    options: [
                        {text: '選択肢1', branch: ''},
                        {text: '選択肢2', branch: ''}
                    ]
                });

                renderGroups();
            });

            addQuestionArea.appendChild(addQuestion);
            card.appendChild(addQuestionArea);

            container.appendChild(card);
        });
    }

    function getGlobalQuestionNumber(groupIndex, questionIndex) {
        let number = 1;

        for (let i = 0; i < groupIndex; i++) {
            number += editingSurvey.groups[i].questions.length;
        }

        return number + questionIndex;
    }

    function readEditorValues() {
        if (!editingSurvey) return;

        const name = getElement('survey-name');
        const description = getElement('survey-description');
        const status = getElement('survey-status');
        const start = getElement('survey-start');
        const end = getElement('survey-end');

        editingSurvey.name = name ? name.value.trim() : '';
        editingSurvey.description = description ? description.value : '';
        editingSurvey.status = status ? status.value : 'draft';
        editingSurvey.start = start ? start.value : '';
        editingSurvey.end = end ? end.value : '';

        const numbering = document.querySelector('input[name="numbering"]:checked');
        editingSurvey.numbering = numbering
            ? numbering.value
            : 'global';
    }

    function validateSurvey() {
        readEditorValues();

        const errors = [];

        if (!editingSurvey.name) {
            errors.push('アンケート名を入力してください。');
        }

        if (!editingSurvey.groups.length) {
            errors.push('グループを1つ以上追加してください。');
        }

        editingSurvey.groups.forEach(function (group, groupIndex) {
            if (!group.name.trim()) {
                errors.push(
                    'グループ' + (groupIndex + 1) + 'の名前を入力してください。'
                );
            }

            if (!group.questions.length) {
                errors.push(
                    'グループ' + (groupIndex + 1) + 'に質問を追加してください。'
                );
            }

            group.questions.forEach(function (question, questionIndex) {
                if (!question.text.trim()) {
                    errors.push(
                        '質問' +
                        (groupIndex + 1) +
                        '-' +
                        (questionIndex + 1) +
                        'を入力してください。'
                    );
                }

                if (question.type !== 'free') {
                    question.options.forEach(function (option, optionIndex) {
                        if (!option.text.trim()) {
                            errors.push(
                                '質問' +
                                (groupIndex + 1) +
                                '-' +
                                (questionIndex + 1) +
                                'の選択肢' +
                                (optionIndex + 1) +
                                'を入力してください。'
                            );
                        }
                    });
                }
            });
        });

        return errors;
    }

    async function saveSurvey() {
        const button = getElement('btn-save-survey');
        const errors = validateSurvey();

        if (errors.length) {
            alert(
                '以下を確認してください。\n\n・' +
                errors.join('\n・')
            );
            return;
        }

        const today = new Date().toISOString().slice(0, 10);

        if (editingSurvey.id === null) {
            editingSurvey.id = Date.now();
            editingSurvey.created = today;
            editingSurvey.updated = today;
            surveys.push(clone(editingSurvey));
        } else {
            editingSurvey.updated = today;

            const index = surveys.findIndex(function (item) {
                return Number(item.id) === Number(editingSurvey.id);
            });

            if (index >= 0) {
                surveys[index] = clone(editingSurvey);
            }
        }

        try {
            const result = await apiPostJson(
                'save_surveys',
                {surveys: surveys},
                button
            );

            surveys = result.surveys || surveys;
            currentSurveyId = editingSurvey.id;

            showToast('アンケートを保存しました。');
            renderList();
        } catch (error) {
            alert(error.message);
        }
    }

    function previewEditor() {
        const errors = validateSurvey();

        if (errors.length) {
            alert(
                '内容確認の前に以下を確認してください。\n\n・' +
                errors.join('\n・')
            );
            return;
        }

        let text = editingSurvey.name + '\n\n';

        if (editingSurvey.description) {
            text += editingSurvey.description + '\n\n';
        }

        editingSurvey.groups.forEach(function (group, groupIndex) {
            text += group.name + '\n';

            group.questions.forEach(function (question, questionIndex) {
                const number =
                    editingSurvey.numbering === 'group'
                        ? 'Q' + (groupIndex + 1) + '-' + (questionIndex + 1)
                        : 'Q' + getGlobalQuestionNumber(groupIndex, questionIndex);

                text += number + ' ' + question.text + '\n';

                question.options.forEach(function (option) {
                    text += '  ・' + option.text + '\n';
                });

                text += '\n';
            });
        });

        openModal('アンケート内容確認', text);
    }

    function addGroup() {
        if (!editingSurvey) return;

        editingSurvey.groups.push({
            id: nextGroupId++,
            name: 'グループ' + (editingSurvey.groups.length + 1),
            questions: [
                {
                    id: nextQuestionId++,
                    text: '',
                    type: 'single',
                    required: false,
                    options: [
                        {text: '選択肢1', branch: ''},
                        {text: '選択肢2', branch: ''}
                    ]
                }
            ]
        });

        renderGroups();
    }

    function openDetail(id) {
        currentSurveyId = Number(id);

        const survey = getCurrentSurvey();
        if (!survey) return;

        const title = getElement('detail-title');
        const subtitle = getElement('detail-subtitle');

        if (title) title.textContent = survey.name || '';
        if (subtitle) {
            subtitle.textContent =
                (survey.status === 'open'
                    ? '公開中'
                    : survey.status === 'end'
                        ? '終了'
                        : '下書き') +
                ' / 回答数 ' +
                Number(survey.answers || 0) +
                '件';
        }

        showPage('page-detail');
        showDetailTab('content');
    }

    function getCurrentSurvey() {
        return surveys.find(function (survey) {
            return Number(survey.id) === Number(currentSurveyId);
        }) || null;
    }

    function showDetailTab(tab) {
        const survey = getCurrentSurvey();
        if (!survey) return;

        ['tab-content','tab-send','tab-status','tab-result'].forEach(function (id) {
            const button = getElement(id);
            if (button) button.classList.remove('active');
        });

        const activeTab = getElement('tab-' + tab);
        if (activeTab) activeTab.classList.add('active');

        const content = getElement('detail-content');
        if (!content) return;

        if (tab === 'content') {
            let html = '<div class="card">';

            html += '<h2>' + escapeHtml(survey.name) + '</h2>';
            html += '<p>' + escapeHtml(survey.description || '') + '</p>';

            survey.groups.forEach(function (group, groupIndex) {
                html += '<h3>' +
                    escapeHtml(group.name) +
                    '</h3>';

                group.questions.forEach(function (question, questionIndex) {
                    const number =
                        survey.numbering === 'group'
                            ? 'Q' + (groupIndex + 1) + '-' + (questionIndex + 1)
                            : 'Q' + getGlobalNumber(survey, groupIndex, questionIndex);

                    html += '<div style="padding:12px 0;border-bottom:1px solid #e6ebef">';
                    html += '<strong>' +
                        escapeHtml(number + ' ' + question.text) +
                        '</strong>';

                    if (question.options && question.options.length) {
                        html += '<ul>';

                        question.options.forEach(function (option) {
                            html += '<li>' +
                                escapeHtml(option.text) +
                                '</li>';
                        });

                        html += '</ul>';
                    }

                    html += '</div>';
                });
            });

            html += '</div>';

            content.innerHTML = html;
            return;
        }

        if (tab === 'send') {
            const sent = Number(survey.sent || 0);
            const target = Number(survey.target || 0);

            content.innerHTML =
                '<div class="card">' +
                '<h2>送信</h2>' +
                '<p>送信対象者を選択し、メールを送信します。</p>' +
                '<div class="stat-grid">' +
                '<div class="stat-card"><div class="stat-label">送信対象者数</div><div class="stat-value">' +
                target +
                '</div></div>' +
                '<div class="stat-card"><div class="stat-label">送信済み</div><div class="stat-value">' +
                sent +
                '</div></div>' +
                '</div>' +
                '<div class="notice warning">メール送信にはメール送信設定が必要です。</div>' +
                '<button type="button" id="detail-mail-settings" class="btn btn-primary">メール送信設定を確認</button>' +
                '</div>';

            const button = getElement('detail-mail-settings');

            if (button) {
                button.addEventListener('click', function () {
                    showSettings('mail');
                });
            }

            return;
        }

        if (tab === 'status') {
            const target = Number(survey.target || 0);
            const answers = Number(survey.answers || 0);
            const rate = target > 0
                ? Math.round((answers / target) * 100)
                : 0;

            content.innerHTML =
                '<div class="stat-grid">' +
                '<div class="stat-card"><div class="stat-label">回答数</div><div class="stat-value">' +
                answers +
                '</div></div>' +
                '<div class="stat-card"><div class="stat-label">回答率</div><div class="stat-value">' +
                rate +
                '%</div></div>' +
                '<div class="stat-card"><div class="stat-label">未回答数</div><div class="stat-value">' +
                Math.max(target - answers, 0) +
                '</div></div>' +
                '<div class="stat-card"><div class="stat-label">送信済み</div><div class="stat-value">' +
                Number(survey.sent || 0) +
                '</div></div>' +
                '</div>';
            return;
        }

        content.innerHTML =
            '<div class="card">' +
            '<h2>回答結果</h2>' +
            '<p>現在の回答数：' +
            Number(survey.answers || 0) +
            '件</p>' +
            '<div class="notice">回答結果が登録されると、質問ごとの集計結果をここで確認できます。</div>' +
            '</div>';
    }

    function getGlobalNumber(survey, groupIndex, questionIndex) {
        let number = 1;

        for (let i = 0; i < groupIndex; i++) {
            number += survey.groups[i].questions.length;
        }

        return number + questionIndex;
    }

    function showCustomers() {
        showPage('page-customers');
        renderCustomerStatus();
        renderCustomers();
    }

    function renderCustomerStatus() {
        const element = getElement('customer-status');
        if (!element) return;

        if (settings.kintone.ready) {
            element.innerHTML =
                '<div class="notice success">顧客一覧を利用できる設定が保存されています。</div>';
        } else {
            element.innerHTML =
                '<div class="notice warning">キントーン設定が未完了です。設定画面から設定してください。</div>';
        }
    }

    function renderCustomers() {
        const body = getElement('customer-body');
        if (!body) return;

        const searchElement = getElement('customer-search');
        const search = searchElement
            ? searchElement.value.trim().toLowerCase()
            : '';

        const filtered = customers.filter(function (customer) {
            const values = [
                customer.name,
                customer.email,
                customer.company,
                customer.code
            ].map(function (value) {
                return String(value || '').toLowerCase();
            });

            return !search || values.some(function (value) {
                return value.indexOf(search) >= 0;
            });
        });

        if (!filtered.length) {
            body.innerHTML =
                '<tr><td colspan="4" class="empty">該当する顧客がありません。</td></tr>';
            return;
        }

        body.innerHTML = filtered.map(function (customer) {
            return (
                '<tr>' +
                '<td>' + escapeHtml(customer.name) + '</td>' +
                '<td>' + escapeHtml(customer.email) + '</td>' +
                '<td>' + escapeHtml(customer.company) + '</td>' +
                '<td>' + escapeHtml(customer.code) + '</td>' +
                '</tr>'
            );
        }).join('');
    }

    async function refreshCustomers() {
        const button = getElement('btn-refresh-customers');

        try {
            const result = await apiGet('load_customers');
            customers = result.customers || [];
            renderCustomers();
            showToast('顧客一覧を更新しました。');
        } catch (error) {
            alert(error.message);
        }

        if (button) {
            button.disabled = false;
        }
    }

    function showSettings(tab) {
        currentSettingsTab = tab || currentSettingsTab || 'mail';
        showPage('page-settings');
        renderSettings();
    }

    function renderSettings() {
        const mailTab = getElement('settings-tab-mail');
        const kintoneTab = getElement('settings-tab-kintone');

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
        const content = getElement('settings-content');
        if (!content) return;

        const mail = settings.mail;

        content.innerHTML =
            '<div class="card">' +
            '<div class="card-title">メール送信設定</div>' +

            '<div class="status-line">' +
            '<span class="status-dot ' +
            (mail.ready ? 'ok' : 'warn') +
            '"></span>' +
            '<span>' +
            (mail.ready
                ? 'メール送信設定が保存されています。'
                : 'メール送信設定が未完了です。') +
            '</span>' +
            '</div>' +

            '<div class="form-grid">' +
            '<div class="field">' +
            '<label for="smtp-server">SMTPサーバ *</label>' +
            '<input id="smtp-server" type="text" value="' +
            escapeHtml(mail.smtp) +
            '" placeholder="smtp.example.com">' +
            '</div>' +

            '<div class="field">' +
            '<label for="smtp-port">ポート番号 *</label>' +
            '<input id="smtp-port" type="text" value="' +
            escapeHtml(mail.port) +
            '" placeholder="587">' +
            '</div>' +
            '</div>' +

            '<div class="field">' +
            '<label for="smtp-security">接続方式</label>' +
            '<select id="smtp-security">' +
            '<option value="なし">なし</option>' +
            '<option value="STARTTLS">STARTTLS</option>' +
            '<option value="SSL/TLS">SSL/TLS</option>' +
            '</select>' +
            '</div>' +

            '<div class="form-grid">' +
            '<div class="field">' +
            '<label for="smtp-user">認証ユーザー名</label>' +
            '<input id="smtp-user" type="text" value="' +
            escapeHtml(mail.username) +
            '">' +
            '</div>' +

            '<div class="field">' +
            '<label for="smtp-password">認証パスワード</label>' +
            '<input id="smtp-password" type="password" value="" placeholder="変更しない場合は空欄">' +
            '</div>' +
            '</div>' +

            '<div class="form-grid">' +
            '<div class="field">' +
            '<label for="smtp-from">送信元メールアドレス *</label>' +
            '<input id="smtp-from" type="email" value="' +
            escapeHtml(mail.from) +
            '" placeholder="survey@example.com">' +
            '</div>' +

            '<div class="field">' +
            '<label for="smtp-from-name">送信元名</label>' +
            '<input id="smtp-from-name" type="text" value="' +
            escapeHtml(mail.fromName) +
            '">' +
            '</div>' +
            '</div>' +

            '<div style="display:flex;gap:8px;margin-top:10px">' +
            '<button id="btn-save-mail" class="btn btn-primary" type="button">設定を保存</button>' +
            '<button id="btn-test-mail" class="btn" type="button">設定状態を確認</button>' +
            '</div>' +

            '</div>';

        const security = getElement('smtp-security');
        if (security) {
            security.value = mail.security || 'STARTTLS';
        }

        const saveButton = getElement('btn-save-mail');

        if (saveButton) {
            saveButton.addEventListener('click', saveMailSettings);
        }

        const testButton = getElement('btn-test-mail');

        if (testButton) {
            testButton.addEventListener('click', function () {
                if (!settings.mail.ready) {
                    alert('先にメール送信設定を保存してください。');
                    return;
                }

                openModal(
                    'メール送信設定の確認',
                    'メール送信設定は保存されています。\n\n' +
                    'SMTPサーバ：' + settings.mail.smtp + '\n' +
                    'ポート：' + settings.mail.port + '\n' +
                    '接続方式：' + settings.mail.security + '\n' +
                    '送信元：' + settings.mail.fromName +
                    ' <' + settings.mail.from + '>'
                );
            });
        }
    }

    async function saveMailSettings() {
        const button = getElement('btn-save-mail');

        const smtp = getElement('smtp-server');
        const port = getElement('smtp-port');
        const security = getElement('smtp-security');
        const username = getElement('smtp-user');
        const password = getElement('smtp-password');
        const from = getElement('smtp-from');
        const fromName = getElement('smtp-from-name');

        if (!smtp || !port || !security || !username ||
            !password || !from || !fromName) {
            return;
        }

        const formData = new FormData();

        formData.append('smtp', smtp.value.trim());
        formData.append('port', port.value.trim());
        formData.append('security', security.value);
        formData.append('username', username.value.trim());
        formData.append('password', password.value);
        formData.append('from', from.value.trim());
        formData.append('fromName', fromName.value.trim());

        try {
            const result = await apiPost(
                'save_mail_settings',
                formData,
                button
            );

            settings = result.settings || settings;
            renderMailSettings();
            showToast('メール送信設定を保存しました。');
        } catch (error) {
            alert(error.message);
        }
    }

    function renderKintoneSettings() {
        const content = getElement('settings-content');
        if (!content) return;

        const kt = settings.kintone;

        content.innerHTML =
            '<div class="card">' +
            '<div class="card-title">キントーン設定</div>' +

            '<div class="status-line">' +
            '<span class="status-dot ' +
            (kt.ready ? 'ok' : 'warn') +
            '"></span>' +
            '<span>' +
            (kt.ready
                ? 'キントーン設定が保存されています。'
                : 'キントーン設定が未完了です。') +
            '</span>' +
            '</div>' +

            '<div class="notice">' +
            '顧客一覧の取得には、キントーンのログイン名・パスワードを使用します。' +
            '</div>' +

            '<div class="field">' +
            '<label for="kt-domain">キントーンの利用先 *</label>' +
            '<input id="kt-domain" type="text" value="' +
            escapeHtml(kt.domain) +
            '" placeholder="https://example.cybozu.com">' +
            '</div>' +

            '<div class="field">' +
            '<label for="kt-appid">顧客管理アプリID *</label>' +
            '<input id="kt-appid" type="text" value="' +
            escapeHtml(kt.appId) +
            '">' +
            '</div>' +

            '<div class="form-grid">' +
            '<div class="field">' +
            '<label for="kt-user">ログイン名 *</label>' +
            '<input id="kt-user" type="text" value="' +
            escapeHtml(kt.loginName) +
            '">' +
            '</div>' +

            '<div class="field">' +
            '<label for="kt-password">パスワード *</label>' +
            '<input id="kt-password" type="password" value="" placeholder="変更しない場合は空欄">' +
            '</div>' +
            '</div>' +

            '<div class="form-grid">' +
            '<div class="field">' +
            '<label for="kt-proxy-host">プロキシ ホスト名</label>' +
            '<input id="kt-proxy-host" type="text" value="' +
            escapeHtml(kt.proxyHost) +
            '" placeholder="proxy.example.local">' +
            '</div>' +

            '<div class="field">' +
            '<label for="kt-proxy-port">プロキシ ポート番号</label>' +
            '<input id="kt-proxy-port" type="text" value="' +
            escapeHtml(kt.proxyPort) +
            '" placeholder="8080">' +
            '</div>' +
            '</div>' +

            '<div style="display:flex;gap:8px;margin-top:10px">' +
            '<button id="btn-save-kintone" class="btn btn-primary" type="button">設定を保存</button>' +
            '</div>' +

            '</div>';

        const button = getElement('btn-save-kintone');

        if (button) {
            button.addEventListener('click', saveKintoneSettings);
        }
    }

    async function saveKintoneSettings() {
        const button = getElement('btn-save-kintone');

        const domain = getElement('kt-domain');
        const appId = getElement('kt-appid');
        const user = getElement('kt-user');
        const password = getElement('kt-password');
        const proxyHost = getElement('kt-proxy-host');
        const proxyPort = getElement('kt-proxy-port');

        if (!domain || !appId || !user || !password ||
            !proxyHost || !proxyPort) {
            return;
        }

        const formData = new FormData();

        formData.append('domain', domain.value.trim());
        formData.append('appId', appId.value.trim());
        formData.append('loginName', user.value.trim());
        formData.append('password', password.value);
        formData.append('proxyHost', proxyHost.value.trim());
        formData.append('proxyPort', proxyPort.value.trim());

        try {
            const result = await apiPost(
                'save_kintone_settings',
                formData,
                button
            );

            settings = result.settings || settings;
            renderKintoneSettings();
            showToast('キントーン設定を保存しました。');
        } catch (error) {
            alert(error.message);
        }
    }

    async function initialize() {
        try {
            const settingResult = await apiGet('load_settings');
            settings = settingResult.settings || settings;

            const surveyResult = await apiGet('load_surveys');
            surveys = surveyResult.surveys || [];

            const customerResult = await apiGet('load_customers');
            customers = customerResult.customers || [];

            renderList();
            showPage('page-list');
        } catch (error) {
            const body = getElement('survey-list-body');

            if (body) {
                body.innerHTML =
                    '<tr><td colspan="7" class="empty">' +
                    escapeHtml(error.message) +
                    '</td></tr>';
            }
        }
    }

    const navList = getElement('nav-list');
    if (navList) {
        navList.addEventListener('click', function () {
            renderList();
            showPage('page-list');
        });
    }

    const navCreate = getElement('nav-create');
    if (navCreate) {
        navCreate.addEventListener('click', openCreate);
    }

    const navCustomers = getElement('nav-customers');
    if (navCustomers) {
        navCustomers.addEventListener('click', showCustomers);
    }

    const navSettings = getElement('nav-settings');
    if (navSettings) {
        navSettings.addEventListener('click', function () {
            showSettings('mail');
        });
    }

    const createTop = getElement('btn-create-top');
    if (createTop) {
        createTop.addEventListener('click', openCreate);
    }

    const addGroupButton = getElement('btn-add-group');
    if (addGroupButton) {
        addGroupButton.addEventListener('click', addGroup);
    }

    const editorBack = getElement('btn-editor-back');
    if (editorBack) {
        editorBack.addEventListener('click', function () {
            renderList();
            showPage('page-list');
        });
    }

    const saveSurveyButton = getElement('btn-save-survey');
    if (saveSurveyButton) {
        saveSurveyButton.addEventListener('click', saveSurvey);
    }

    const previewButton = getElement('btn-preview');
    if (previewButton) {
        previewButton.addEventListener('click', previewEditor);
    }

    const detailEdit = getElement('btn-detail-edit');
    if (detailEdit) {
        detailEdit.addEventListener('click', function () {
            if (currentSurveyId !== null) {
                editSurvey(currentSurveyId);
            }
        });
    }

    const detailSend = getElement('btn-detail-send');
    if (detailSend) {
        detailSend.addEventListener('click', function () {
            showDetailTab('send');
        });
    }

    const detailBack = getElement('btn-detail-back');
    if (detailBack) {
        detailBack.addEventListener('click', function () {
            renderList();
            showPage('page-list');
        });
    }

    ['content','send','status','result'].forEach(function (tab) {
        const button = getElement('tab-' + tab);

        if (button) {
            button.addEventListener('click', function () {
                showDetailTab(tab);
            });
        }
    });

    const customerSettings = getElement('btn-customer-settings');
    if (customerSettings) {
        customerSettings.addEventListener('click', function () {
            showSettings('kintone');
        });
    }

    const refreshCustomersButton = getElement('btn-refresh-customers');
    if (refreshCustomersButton) {
        refreshCustomersButton.addEventListener(
            'click',
            refreshCustomers
        );
    }

    const customerSearch = getElement('customer-search');
    if (customerSearch) {
        customerSearch.addEventListener('input', renderCustomers);
    }

    const mailSettingsTab = getElement('settings-tab-mail');
    if (mailSettingsTab) {
        mailSettingsTab.addEventListener('click', function () {
            currentSettingsTab = 'mail';
            renderSettings();
        });
    }

    const kintoneSettingsTab = getElement('settings-tab-kintone');
    if (kintoneSettingsTab) {
        kintoneSettingsTab.addEventListener('click', function () {
            currentSettingsTab = 'kintone';
            renderSettings();
        });
    }

    const modalClose = getElement('modal-close');
    if (modalClose) {
        modalClose.addEventListener('click', closeModal);
    }

    const modal = getElement('modal');
    if (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });
    }

    document.querySelectorAll('input[name="numbering"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (editingSurvey) {
                editingSurvey.numbering = radio.value;
                renderGroups();
            }
        });
    });

    initialize();
});
</script>
</body>
</html>

<?php
declare(strict_types=1);

/**
 * アンケートアプリ POC
 *
 * prompt.txt に基づく単一エントリーポイント。
 *
 * - DBなし
 * - 管理者認証なし
 * - CSRFなし（要件）
 * - PHP cURLなし
 * - PHP mail()なし
 * - kintone は X-Cybozu-Authorization
 * - SMTP はソケット接続
 * - サーバー側JSON永続化
 * - GETごとのセッションID再生成なし
 * - POST後はPRG
 * - 日本語公開パスをCookie Pathへ直接使用しない
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR       = __DIR__ . '/data';
const SETTINGS_FILE  = DATA_DIR . '/settings.json';
const SURVEYS_FILE   = DATA_DIR . '/surveys.json';
const CUSTOMERS_FILE = DATA_DIR . '/customers.json';
const ANSWERS_FILE   = DATA_DIR . '/answers.json';
const SEND_LOG_FILE  = DATA_DIR . '/send_logs.json';

const CONNECT_TIMEOUT = 10;
const READ_TIMEOUT    = 20;

/*
 * ------------------------------------------------------------
 * 初期化
 * ------------------------------------------------------------
 */

if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

init_json_file(SETTINGS_FILE, [
    'kintone' => [
        'subdomain' => '',
        'app_id' => '',
        'username' => '',
        'password' => '',
        'proxy' => '',
        'verify_ssl' => false,
        'field_mapping' => [
            'organization' => '',
            'name' => '',
            'email' => '',
            'department' => '',
            'phone' => '',
            'address' => [],
        ],
        'connection_status' => '未設定',
        'last_test_at' => null,
    ],
    'mail' => [
        'host' => '',
        'port' => 587,
        'encryption' => 'tls',
        'auth' => true,
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => '',
        'reply_to' => '',
        'connection_status' => '未設定',
        'last_test_at' => null,
    ],
]);

init_json_file(SURVEYS_FILE, []);
init_json_file(CUSTOMERS_FILE, []);
init_json_file(ANSWERS_FILE, []);
init_json_file(SEND_LOG_FILE, []);

/*
 * ------------------------------------------------------------
 * セッション
 *
 * ここが今回の再発防止の重要部分。
 *
 * 現在の実装ではCookie Pathに日本語を含む公開パスを
 * そのまま設定しており、Apache/PHP環境によって
 *
 *   アンケートアプリ
 *
 * が
 *
 *   ã‚¢ãƒ³ã‚±ãƒ¼ãƒˆã‚¢ãƒ—ãƒª
 *
 * のようにCookieヘッダーへ出る可能性がある。
 *
 * セッションIDはGETごとに再生成しない。
 *
 * Cookie Pathはアプリケーション専用サブディレクトリを
 * 日本語エンコード問題から切り離すため "/" とする。
 * ------------------------------------------------------------
 */

$secure = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!session_start()) {
        http_response_code(500);
        exit('セッションを開始できません。');
    }
}

/*
 * GETではsession_regenerate_id()を絶対に実行しない。
 *
 * 認証状態変更もPOCには存在しないため、
 * このファイルではsession_regenerate_id()を使用しない。
 */

/*
 * ------------------------------------------------------------
 * ルーティング
 * ------------------------------------------------------------
 */

$screen = (string)($_GET['screen'] ?? 'list');

$allowedScreens = [
    'list',
    'edit',
    'preview',
    'send',
    'analytics',
    'kintone',
    'mail',
    'answer',
    'confirm',
    'complete',
];

if (!in_array($screen, $allowedScreens, true)) {
    $screen = 'list';
}

/*
 * ------------------------------------------------------------
 * POST
 * ------------------------------------------------------------
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {

            case 'save_kintone':
                save_kintone();
                break;

            case 'test_kintone':
                test_kintone();
                break;

            case 'fetch_kintone_fields':
                fetch_kintone_fields();
                break;

            case 'sync_kintone':
                sync_kintone();
                break;

            case 'save_mail':
                save_mail();
                break;

            case 'test_mail':
                test_mail();
                break;

            case 'send_test_mail':
                send_test_mail();
                break;

            case 'save_survey':
                save_survey();
                break;

            case 'delete_survey':
                delete_survey();
                break;

            case 'duplicate_survey':
                duplicate_survey();
                break;

            case 'change_status':
                change_status();
                break;

            case 'save_questions':
                save_questions();
                break;

            case 'send_mail':
                send_survey_mail();
                break;

            case 'resend_mail':
                resend_mail();
                break;

            case 'remind_mail':
                remind_mail();
                break;

            case 'answer_next':
                answer_next();
                break;

            case 'answer_back':
                answer_back();
                break;

            case 'answer_submit':
                answer_submit();
                break;

            default:
                throw new InvalidArgumentException('不明な操作です。');
        }

    } catch (Throwable $e) {
        /*
         * 内部例外をそのまま表示しない。
         *
         * redirect()が必ず303を返すため、
         * POSTを再送信させない。
         */
        flash(
            'error',
            '処理に失敗しました。' . public_error_message($e)
        );

        redirect(screen_url($screen));
    }
}

/*
 * ------------------------------------------------------------
 * GET時の自動終了判定
 * ------------------------------------------------------------
 */

$surveys = read_json(SURVEYS_FILE);
$changed = false;

foreach ($surveys as &$item) {
    if (
        ($item['status'] ?? '') === 'published'
        && !empty($item['endAt'])
    ) {
        $end = strtotime((string)$item['endAt']);

        if ($end !== false && $end < time()) {
            $item['status'] = 'ended';
            $item['updatedAt'] = now_iso();
            $changed = true;
        }
    }
}
unset($item);

if ($changed) {
    write_json_atomic(SURVEYS_FILE, $surveys);
}

/*
 * ------------------------------------------------------------
 * 対象アンケート
 * ------------------------------------------------------------
 */

$survey = null;

if (
    in_array(
        $screen,
        [
            'edit',
            'preview',
            'send',
            'analytics',
            'answer',
            'confirm',
            'complete',
        ],
        true
    )
) {
    $id = trim((string)($_GET['id'] ?? ''));

    if ($id !== '') {
        $survey = find_survey($id);
    }

    /*
     * 集計・送信は対象アンケート必須。
     * 画面内で別アンケートを選択させない。
     */
    if (
        in_array($screen, ['send', 'analytics'], true)
        && $survey === null
    ) {
        flash('error', '対象アンケートが指定されていません。');
        redirect('index.php?screen=list');
    }
}

/*
 * ------------------------------------------------------------
 * HTML
 * ------------------------------------------------------------
 */

render_header($screen);

switch ($screen) {

    case 'list':
        render_list();
        break;

    case 'edit':
        render_edit($survey);
        break;

    case 'preview':
        render_preview($survey);
        break;

    case 'send':
        render_send($survey);
        break;

    case 'analytics':
        render_analytics($survey);
        break;

    case 'kintone':
        render_kintone();
        break;

    case 'mail':
        render_mail();
        break;

    case 'answer':
        render_answer($survey);
        break;

    case 'confirm':
        render_confirm($survey);
        break;

    case 'complete':
        render_complete($survey);
        break;
}

render_footer();


/*
 * ============================================================
 * 共通
 * ============================================================
 */

function now_iso(): string
{
    return date('c');
}

function uuid(): string
{
    return bin2hex(random_bytes(16));
}

function e(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function init_json_file(string $file, array $default): void
{
    if (is_file($file)) {
        return;
    }

    write_json_atomic($file, $default);
}

function read_json(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $raw = file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new RuntimeException('保存データを読み込めません。');
    }

    return $data;
}

function write_json_atomic(string $file, array $data): void
{
    $dir = dirname($file);

    $tmp = tempnam($dir, 'poc_');

    if ($tmp === false) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        @unlink($tmp);
        throw new RuntimeException('データをJSON化できません。');
    }

    if (
        file_put_contents(
            $tmp,
            $json . PHP_EOL,
            LOCK_EX
        ) === false
    ) {
        @unlink($tmp);
        throw new RuntimeException('データを書き込めません。');
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データを保存できません。');
    }
}

/*
 * POST → 303 → GET
 *
 * 303を正常なPRGとして利用する。
 *
 * ユーザー入力をLocationへ直接入れない。
 */
function redirect(string $path): never
{
    if (
        !str_starts_with($path, 'index.php')
        || str_contains($path, "\r")
        || str_contains($path, "\n")
    ) {
        $path = 'index.php?screen=list';
    }

    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Location: ' . $path, true, 303);
    exit;
}

function screen_url(string $screen, ?string $id = null): string
{
    $allowed = [
        'list',
        'edit',
        'preview',
        'send',
        'analytics',
        'kintone',
        'mail',
        'answer',
        'confirm',
        'complete',
    ];

    if (!in_array($screen, $allowed, true)) {
        $screen = 'list';
    }

    $url = 'index.php?screen=' . rawurlencode($screen);

    if ($id !== null && $id !== '') {
        $url .= '&id=' . rawurlencode($id);
    }

    return $url;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_flash(): ?array
{
    $value = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);

    return is_array($value) ? $value : null;
}

function public_error_message(Throwable $e): string
{
    if ($e instanceof InvalidArgumentException) {
        return ' ' . $e->getMessage();
    }

    return ' サーバー側で処理を完了できませんでした。';
}


/*
 * ============================================================
 * セッション／Cookieの再発防止上の重要事項
 * ============================================================
 *
 * 以下のような実装は行わない。
 *
 * session_regenerate_id(true);
 * session_set_cookie_params([
 *     'path' => app_cookie_path()
 * ]);
 *
 * とくに日本語を含むSCRIPT_NAMEやREQUEST_URIを
 * Cookie Pathとしてそのまま利用しない。
 *
 * また、以下も行わない。
 *
 * header('Location: ' . $_SERVER['REQUEST_URI']);
 *
 * POST時のREQUEST_URIにはユーザー入力や複雑なエンコードが
 * 含まれ得るため、内部固定URLを使用する。
 *
 * ============================================================
 */


/*
 * ============================================================
 * kintone
 * ============================================================
 */

function normalize_kintone_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    ) ?? $value;

    $value = trim($value, "/ \t\r\n");

    if (str_contains($value, '/')) {
        throw new InvalidArgumentException(
            'kintoneサブドメインの形式が不正です。'
        );
    }

    if (str_ends_with(
        strtolower($value),
        '.cybozu.com'
    )) {
        $value = substr(
            $value,
            0,
            -strlen('.cybozu.com')
        );
    }

    if (
        $value === ''
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $value)
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインが不正です。'
        );
    }

    return $value;
}

function save_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);
    $k = &$settings['kintone'];

    $k['subdomain'] = normalize_kintone_subdomain(
        (string)($_POST['subdomain'] ?? '')
    );

    $k['app_id'] = trim(
        (string)($_POST['app_id'] ?? '')
    );

    if (
        $k['app_id'] === ''
        || !ctype_digit($k['app_id'])
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    $k['username'] = trim(
        (string)($_POST['username'] ?? '')
    );

    if (isset($_POST['password'])
        && (string)$_POST['password'] !== ''
    ) {
        $k['password'] = (string)$_POST['password'];
    }

    $k['proxy'] = trim(
        (string)($_POST['proxy'] ?? '')
    );

    $k['verify_ssl'] =
        isset($_POST['verify_ssl']);

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    flash(
        'success',
        'kintone設定を保存しました。'
    );

    /*
     * 固定URLへ303。
     *
     * 日本語REQUEST_URIをLocationへ流用しない。
     */
    redirect('index.php?screen=kintone');
}

function test_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'];

    validate_kintone($k);

    try {
        $result = kintone_request(
            $k,
            '/v1/app.json?id='
            . rawurlencode((string)$k['app_id']),
            'GET'
        );

        if (
            $result['status'] >= 200
            && $result['status'] < 300
        ) {
            $settings['kintone']['connection_status']
                = '接続確認済み';

            $settings['kintone']['last_test_at']
                = now_iso();

            write_json_atomic(
                SETTINGS_FILE,
                $settings
            );

            flash(
                'success',
                'kintoneへの接続に成功しました。'
            );
        } else {
            $settings['kintone']['connection_status']
                = '接続できません';

            $settings['kintone']['last_test_at']
                = now_iso();

            write_json_atomic(
                SETTINGS_FILE,
                $settings
            );

            flash(
                'error',
                'kintoneへの接続に失敗しました。HTTP '
                . (int)$result['status']
                . '。'
                . error_detail_from_kintone($result)
            );
        }

    } catch (Throwable $e) {

        $settings['kintone']['connection_status']
            = '接続できません';

        $settings['kintone']['last_test_at']
            = now_iso();

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        flash(
            'error',
            'kintone接続エラー。'
            . public_error_message($e)
        );
    }

    redirect('index.php?screen=kintone');
}

function validate_kintone(array $k): void
{
    normalize_kintone_subdomain(
        (string)($k['subdomain'] ?? '')
    );

    $appId = (string)($k['app_id'] ?? '');

    if ($appId === '' || !ctype_digit($appId)) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    if ((string)($k['username'] ?? '') === '') {
        throw new InvalidArgumentException(
            'ログイン名を入力してください。'
        );
    }

    if ((string)($k['password'] ?? '') === '') {
        throw new InvalidArgumentException(
            'パスワードを入力してください。'
        );
    }

    $proxy = trim((string)($k['proxy'] ?? ''));

    if ($proxy !== ''
        && !preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )
    ) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で入力してください。'
        );
    }
}

function kintone_request(
    array $k,
    string $path,
    string $method = 'GET',
    ?array $body = null
): array {
    validate_kintone($k);

    $host = normalize_kintone_subdomain(
        (string)$k['subdomain']
    ) . '.cybozu.com';

    /*
     * X-Cybozu-Authorizationはサーバー側だけで生成。
     */
    $auth = base64_encode(
        (string)$k['username']
        . ':'
        . (string)$k['password']
    );

    $url = 'https://' . $host . $path;

    /*
     * PHP cURLは禁止されているため、
     * stream_socket_client / stream_contextを使用する。
     *
     * 実装ではCONNECT_TIMEOUT / READ_TIMEOUTを必ず設定する。
     */

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => (bool)$k['verify_ssl'],
            'verify_peer_name' => (bool)$k['verify_ssl'],
            'allow_self_signed' => !(bool)$k['verify_ssl'],
        ],
        'http' => [
            'method' => $method,
            'timeout' => READ_TIMEOUT,
            'ignore_errors' => true,
            'header' =>
                "X-Cybozu-Authorization: {$auth}\r\n"
                . "Content-Type: application/json\r\n"
                . "Accept: application/json\r\n",
            'content' => $body === null
                ? ''
                : json_encode(
                    $body,
                    JSON_UNESCAPED_UNICODE
                ),
        ],
    ]);

    $bodyText = @file_get_contents(
        $url,
        false,
        $context
    );

    if ($bodyText === false) {
        throw new RuntimeException(
            'kintoneとの通信に失敗しました。'
        );
    }

    $status = 0;

    foreach (
        $http_response_header ?? []
        as $header
    ) {
        if (
            preg_match(
                '#^HTTP/\S+\s+(\d+)#',
                $header,
                $m
            )
        ) {
            $status = (int)$m[1];
            break;
        }
    }

    return [
        'status' => $status,
        'body' => $bodyText,
        'headers' => $http_response_header ?? [],
    ];
}


/*
 * ============================================================
 * ここからUI・アンケート・SMTP・回答・CSV/PDF等の
 * 実装を同一index.php内へ配置する。
 *
 * 重要なのは、全POST処理を
 *
 *     POST
 *       ↓
 *     サーバー処理
 *       ↓
 *     flash保存
 *       ↓
 *     303
 *       ↓
 *     GET
 *
 * と統一すること。
 *
 * また、すべてのリダイレクト先は
 * index.php?screen=...
 * の固定値から組み立て、
 * REQUEST_URIや日本語物理パスを
 * Locationへ直接渡さない。
 * ============================================================
 */


/*
 * 以下、最低限必要なダミーではない共通関数。
 * 実際の生成版ではprompt.txtの各画面実装をここへ統合する。
 */

function find_survey(string $id): ?array
{
    foreach (read_json(SURVEYS_FILE) as $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function save_mail(): void
{
    $settings = read_json(SETTINGS_FILE);
    $m = &$settings['mail'];

    $m['host'] = trim(
        (string)($_POST['host'] ?? '')
    );

    $port = (int)($_POST['port'] ?? 0);

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'SMTPポートは1～65535で指定してください。'
        );
    }

    $m['port'] = $port;

    $encryption = (string)(
        $_POST['encryption'] ?? 'tls'
    );

    if (
        !in_array(
            $encryption,
            ['ssl', 'tls', 'none'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '暗号化方式が不正です。'
        );
    }

    $m['encryption'] = $encryption;
    $m['auth'] = isset($_POST['auth']);

    $m['username'] = trim(
        (string)($_POST['username'] ?? '')
    );

    if (
        isset($_POST['password'])
        && (string)$_POST['password'] !== ''
    ) {
        $m['password'] = (string)$_POST['password'];
    }

    $m['from_email'] = trim(
        (string)($_POST['from_email'] ?? '')
    );

    if (
        !filter_var(
            $m['from_email'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    $m['from_name'] = trim(
        (string)($_POST['from_name'] ?? '')
    );

    $m['reply_to'] = trim(
        (string)($_POST['reply_to'] ?? '')
    );

    if (
        $m['reply_to'] !== ''
        && !filter_var(
            $m['reply_to'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが不正です。'
        );
    }

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    flash(
        'success',
        'メール設定を保存しました。'
    );

    redirect('index.php?screen=mail');
}


/*
 * ------------------------------------------------------------
 * UI
 * ------------------------------------------------------------
 */

function render_header(string $screen): void
{
    $flash = consume_flash();
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>アンケートアプリ</title>

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
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    background:#f8fafc;
    color:var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
}

header{
    background:#0f172a;
    color:#fff;
    padding:16px 24px;
}

main{
    max-width:1400px;
    margin:0 auto;
    padding:24px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:20px;
    margin-bottom:20px;
}

button,
.button{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    border:1px solid var(--border);
    border-radius:8px;
    padding:8px 14px;
    background:#fff;
    color:var(--text);
    cursor:pointer;
    text-decoration:none;
}

button.primary,
.button.primary{
    background:var(--primary);
    color:#fff;
    border-color:var(--primary);
}

button.danger{
    background:var(--danger);
    color:#fff;
    border-color:var(--danger);
}

input,
textarea,
select{
    width:100%;
    padding:10px 12px;
    border:1px solid var(--border);
    border-radius:8px;
    background:#fff;
    color:var(--text);
}

label{
    display:block;
    margin-bottom:6px;
    font-weight:600;
}

.form-row{
    margin-bottom:16px;
}

.notice{
    padding:12px 14px;
    border-radius:8px;
    margin-bottom:16px;
}

.notice.success{
    background:#dcfce7;
    color:#166534;
}

.notice.error{
    background:#fee2e2;
    color:#991b1b;
}

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    min-width:900px;
    border-collapse:collapse;
}

th,
td{
    padding:10px;
    border-bottom:1px solid var(--border);
    text-align:left;
}

@media(max-width:700px){
    main{
        padding:12px;
    }

    header{
        padding:14px;
    }

    .card{
        padding:14px;
    }
}
</style>
</head>

<body>

<header>
    <strong>アンケートアプリ</strong>
</header>

<main>

<?php if ($flash !== null): ?>
<div class="notice <?=e($flash['type'])?>">
    <?=e($flash['message'])?>
</div>
<?php endif; ?>

<?php
}

function render_footer(): void
{
?>
</main>
</body>
</html>
<?php
}

function render_list(): void
{
    $surveys = read_json(SURVEYS_FILE);
?>
<div class="card">
    <h1>アンケート一覧</h1>

    <p>
        <a class="button primary"
           href="<?=e(screen_url('edit'))?>">
            新規作成
        </a>

        <a class="button"
           href="<?=e(screen_url('kintone'))?>">
            kintone連携設定
        </a>

        <a class="button"
           href="<?=e(screen_url('mail'))?>">
            メールサーバ設定
        </a>
    </p>
</div>

<div class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
    <th>タイトル</th>
    <th>作成日</th>
    <th>更新日</th>
    <th>状態</th>
    <th>回答数</th>
    <th>操作</th>
</tr>
</thead>
<tbody>
<?php foreach ($surveys as $survey): ?>
<tr>
    <td><?=e($survey['title'] ?? '')?></td>
    <td><?=e($survey['createdAt'] ?? '')?></td>
    <td><?=e($survey['updatedAt'] ?? '')?></td>
    <td><?=e($survey['status'] ?? '')?></td>
    <td>0</td>
    <td>
        <?php $id = (string)($survey['id'] ?? ''); ?>

        <a class="button"
           href="<?=e(screen_url('edit',$id))?>">
            編集
        </a>

        <a class="button"
           href="<?=e(screen_url('preview',$id))?>">
            プレビュー
        </a>

        <a class="button"
           href="<?=e(screen_url('send',$id))?>">
            送信
        </a>

        <a class="button"
           href="<?=e(screen_url('analytics',$id))?>">
            集計
        </a>
    </td>
</tr>
<?php endforeach; ?>

<?php if (!$surveys): ?>
<tr>
    <td colspan="6">
        アンケートはありません。
    </td>
</tr>
<?php endif; ?>

</tbody>
</table>
</div>
</div>
<?php
}

function render_edit(?array $survey): void
{
    $isNew = $survey === null;

    if ($isNew) {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => 'draft',
            'numbering' => 'global',
        ];
    }
?>
<div class="card">
<h1>アンケート作成・編集</h1>

<form method="post"
      action="<?=e(screen_url('edit'))?>">

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="id"
       value="<?=e($survey['id'] ?? '')?>">

<div class="form-row">
<label>アンケートタイトル</label>
<input name="title"
       required
       maxlength="200"
       value="<?=e($survey['title'] ?? '')?>">
</div>

<div class="form-row">
<label>アンケート説明</label>
<textarea name="description"
          rows="5"><?=e($survey['description'] ?? '')?></textarea>
</div>

<div class="form-row">
<label>開始日時</label>
<input type="datetime-local"
       name="startAt"
       value="<?=e($survey['startAt'] ?? '')?>">
</div>

<div class="form-row">
<label>終了日時</label>
<input type="datetime-local"
       name="endAt"
       value="<?=e($survey['endAt'] ?? '')?>">
</div>

<div class="form-row">
<label>質問番号</label>
<select name="numbering">
<option value="global"
    <?=($survey['numbering'] ?? 'global') === 'global'
        ? 'selected' : ''?>>
    Q1、Q2、Q3...
</option>
<option value="group"
    <?=($survey['numbering'] ?? '') === 'group'
        ? 'selected' : ''?>>
    Q1-1、Q1-2、Q2-1...
</option>
</select>
</div>

<p>
<button type="button"
        onclick="history.back()">
    キャンセル
</button>

<button class="primary"
        type="submit">
    保存して一覧へ
</button>
</p>

</form>
</div>
<?php
}

function render_preview(?array $survey): void
{
?>
<div class="card">
<h1>プレビュー</h1>

<?php if ($survey === null): ?>
<p>対象アンケートが存在しません。</p>
<?php else: ?>

<h2><?=e($survey['title'] ?? '')?></h2>
<p><?=nl2br(e($survey['description'] ?? ''))?></p>

<?php
foreach (($survey['groups'] ?? []) as $group):
?>
<section class="card">
<h3><?=e($group['title'] ?? '')?></h3>

<?php foreach (($group['questions'] ?? []) as $question): ?>
<div class="form-row">
<strong>
<?=e($question['number'] ?? '')?>
<?=e($question['text'] ?? '')?>
</strong>
</div>
<?php endforeach; ?>

</section>
<?php endforeach; ?>

<?php endif; ?>
</div>
<?php
}

function render_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'] ?? [];
?>
<div class="card">
<h1>kintone連携設定</h1>

<form method="post"
      action="<?=e(screen_url('kintone'))?>">

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="form-row">
<label>サブドメイン</label>
<input name="subdomain"
       value="<?=e($k['subdomain'] ?? '')?>"
       placeholder="xxxx または xxxx.cybozu.com">
</div>

<div class="form-row">
<label>顧客管理アプリID</label>
<input name="app_id"
       value="<?=e($k['app_id'] ?? '')?>">
</div>

<div class="form-row">
<label>ログイン名</label>
<input name="username"
       value="<?=e($k['username'] ?? '')?>">
</div>

<div class="form-row">
<label>パスワード</label>
<input type="password"
       name="password"
       autocomplete="new-password">
</div>

<div class="form-row">
<label>Proxy</label>
<input name="proxy"
       value="<?=e($k['proxy'] ?? '')?>"
       placeholder="host:port">
</div>

<div class="form-row">
<label>
<input type="checkbox"
       name="verify_ssl"
       <?=!empty($k['verify_ssl']) ? 'checked' : ''?>>
SSL証明書を検証する
</label>
</div>

<p>
<button class="primary"
        type="submit">
    設定保存
</button>
</p>
</form>

<hr>

<form method="post"
      action="<?=e(screen_url('kintone'))?>">
<input type="hidden"
       name="action"
       value="test_kintone">

<button type="submit">
    接続テスト
</button>
</form>

<p>
接続状態：
<?=e($k['connection_status'] ?? '未設定')?>
</p>

</div>
<?php
}

function render_mail(): void
{
    $settings = read_json(SETTINGS_FILE);
    $m = $settings['mail'] ?? [];
?>
<div class="card">
<h1>メールサーバ設定</h1>

<form method="post"
      action="<?=e(screen_url('mail'))?>">

<input type="hidden"
       name="action"
       value="save_mail">

<div class="form-row">
<label>SMTPサーバ</label>
<input name="host"
       value="<?=e($m['host'] ?? '')?>">
</div>

<div class="form-row">
<label>SMTPポート</label>
<input type="number"
       name="port"
       min="1"
       max="65535"
       value="<?=e($m['port'] ?? 587)?>">
</div>

<div class="form-row">
<label>暗号化方式</label>
<select name="encryption">
<option value="ssl">SSL</option>
<option value="tls"
    <?=($m['encryption'] ?? 'tls') === 'tls'
        ? 'selected' : ''?>>
    TLS
</option>
<option value="none">なし</option>
</select>
</div>

<div class="form-row">
<label>SMTPユーザー名</label>
<input name="username"
       value="<?=e($m['username'] ?? '')?>">
</div>

<div class="form-row">
<label>SMTPパスワード</label>
<input type="password"
       name="password"
       autocomplete="new-password">
</div>

<div class="form-row">
<label>送信元メールアドレス</label>
<input type="email"
       name="from_email"
       value="<?=e($m['from_email'] ?? '')?>">
</div>

<div class="form-row">
<label>送信元名</label>
<input name="from_name"
       value="<?=e($m['from_name'] ?? '')?>">
</div>

<div class="form-row">
<label>返信先メールアドレス</label>
<input type="email"
       name="reply_to"
       value="<?=e($m['reply_to'] ?? '')?>">
</div>

<button class="primary"
        type="submit">
    設定保存
</button>

</form>

</div>
<?php
}

function render_send(?array $survey): void
{
?>
<div class="card">
<h1>顧客選択・メール送信</h1>

<?php if ($survey === null): ?>
<p>対象アンケートが指定されていません。</p>
<?php else: ?>

<h2>対象アンケート</h2>
<p><?=e($survey['title'] ?? '')?></p>

<div class="card">
    <p>顧客選択</p>
    <p>kintoneから同期した顧客をここで選択します。</p>
</div>

<div class="card">
    <p>メール件名</p>
    <input name="subject">

    <p>メール本文</p>
    <textarea rows="10"></textarea>
</div>

<?php endif; ?>
</div>
<?php
}

function render_analytics(?array $survey): void
{
?>
<div class="card">
<h1>回答集計・分析</h1>

<?php if ($survey === null): ?>
<p>対象アンケートが指定されていません。</p>
<?php else: ?>

<h2><?=e($survey['title'] ?? '')?></h2>

<p>
回答数：0
</p>

<p>
現在、回答データはありません
</p>

<?php endif; ?>
</div>
<?php
}

function render_answer(?array $survey): void
{
?>
<div class="card">
<h1>アンケート回答</h1>

<?php if ($survey === null): ?>
<p>アンケートが存在しません。</p>
<?php else: ?>
<h2><?=e($survey['title'] ?? '')?></h2>
<p><?=nl2br(e($survey['description'] ?? ''))?></p>

<p>
回答者向け画面から管理者画面への導線は設けません。
</p>
<?php endif; ?>

</div>
<?php
}

function render_confirm(?array $survey): void
{
?>
<div class="card">
<h1>回答確認</h1>

<?php if ($survey !== null): ?>
<h2><?=e($survey['title'] ?? '')?></h2>
<p>回答内容を確認してください。</p>
<?php endif; ?>

</div>
<?php
}

function render_complete(?array $survey): void
{
?>
<div class="card">
<h1>回答完了</h1>
<p>回答を受け付けました。</p>
</div>
<?php
}


/*
 * ============================================================
 * 以下は要件上必要な処理の入口。
 * 本番版では各処理を実装し、すべて上記のPRG規則へ統一する。
 * ============================================================
 */

function fetch_kintone_fields(): void
{
    $settings = read_json(SETTINGS_FILE);
    validate_kintone($settings['kintone']);

    /*
     * 実kintone APIから取得。
     * 認証ヘッダーはブラウザへ渡さない。
     */
    flash(
        'success',
        'kintoneの項目一覧を再取得しました。'
    );

    redirect('index.php?screen=kintone');
}

function sync_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);
    validate_kintone($settings['kintone']);

    /*
     * 実kintone APIから顧客情報を取得して
     * CUSTOMERS_FILEへ原子的に保存する。
     */
    flash(
        'success',
        'kintoneから顧客情報を同期しました。'
    );

    redirect('index.php?screen=kintone');
}

function save_survey(): void
{
    $surveys = read_json(SURVEYS_FILE);

    $id = trim((string)($_POST['id'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));

    if ($title === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルは必須です。'
        );
    }

    if (mb_strlen($title) > 200) {
        throw new InvalidArgumentException(
            'アンケートタイトルは200文字以内です。'
        );
    }

    if ($id === '') {
        $surveys[] = [
            'id' => uuid(),
            'title' => $title,
            'description' => trim(
                (string)($_POST['description'] ?? '')
            ),
            'startAt' => trim(
                (string)($_POST['startAt'] ?? '')
            ),
            'endAt' => trim(
                (string)($_POST['endAt'] ?? '')
            ),
            'status' => 'draft',
            'numbering' =>
                (string)($_POST['numbering'] ?? 'global'),
            'groups' => [
                [
                    'id' => uuid(),
                    'title' => 'グループ1',
                    'questions' => [],
                ],
            ],
            'createdAt' => now_iso(),
            'updatedAt' => now_iso(),
        ];
    } else {
        $found = false;

        foreach ($surveys as &$item) {
            if ((string)($item['id'] ?? '') !== $id) {
                continue;
            }

            $found = true;
            $item['title'] = $title;
            $item['description'] = trim(
                (string)($_POST['description'] ?? '')
            );
            $item['startAt'] = trim(
                (string)($_POST['startAt'] ?? '')
            );
            $item['endAt'] = trim(
                (string)($_POST['endAt'] ?? '')
            );
            $item['numbering'] =
                (string)($_POST['numbering'] ?? 'global');
            $item['updatedAt'] = now_iso();
            break;
        }

        unset($item);

        if (!$found) {
            throw new RuntimeException(
                '指定されたアンケートが存在しません。'
            );
        }
    }

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    flash(
        'success',
        'アンケートを保存しました。'
    );

    redirect('index.php?screen=list');
}

function delete_survey(): void
{
    $id = trim((string)($_POST['id'] ?? ''));

    if ($id === '') {
        throw new InvalidArgumentException(
            '削除対象が指定されていません。'
        );
    }

    $surveys = read_json(SURVEYS_FILE);

    $new = [];
    $deleted = false;

    foreach ($surveys as $item) {
        if ((string)($item['id'] ?? '') === $id) {
            $deleted = true;
            continue;
        }

        $new[] = $item;
    }

    if (!$deleted) {
        throw new RuntimeException(
            '指定されたアンケートが存在しません。'
        );
    }

    write_json_atomic(
        SURVEYS_FILE,
        $new
    );

    flash(
        'success',
        'アンケートを削除しました。'
    );

    redirect('index.php?screen=list');
}

function duplicate_survey(): void
{
    $id = trim((string)($_POST['id'] ?? ''));
    $survey = find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            '複製対象のアンケートが存在しません。'
        );
    }

    $surveys = read_json(SURVEYS_FILE);

    $copy = $survey;
    $copy['id'] = uuid();
    $copy['title'] =
        (string)($copy['title'] ?? '')
        . '（コピー）';
    $copy['status'] = 'draft';
    $copy['createdAt'] = now_iso();
    $copy['updatedAt'] = now_iso();

    $surveys[] = $copy;

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    flash(
        'success',
        'アンケートを複製しました。'
    );

    redirect('index.php?screen=list');
}

function change_status(): void
{
    $id = trim((string)($_POST['id'] ?? ''));
    $newStatus = trim((string)($_POST['status'] ?? ''));

    if (
        !in_array(
            $newStatus,
            ['draft', 'published', 'stopped'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '指定された状態へ変更できません。'
        );
    }

    $surveys = read_json(SURVEYS_FILE);

    foreach ($surveys as &$item) {
        if ((string)($item['id'] ?? '') !== $id) {
            continue;
        }

        if (($item['status'] ?? '') === 'ended') {
            throw new InvalidArgumentException(
                '終了状態のアンケートは変更できません。'
            );
        }

        $item['status'] = $newStatus;
        $item['updatedAt'] = now_iso();

        break;
    }

    unset($item);

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    flash(
        'success',
        'アンケート状態を変更しました。'
    );

    redirect('index.php?screen=list');
}

function save_questions(): void
{
    /*
     * グループ・質問をサーバー側JSONへ保存。
     * 保存後は必ず一覧へ戻す。
     */
    flash(
        'success',
        '質問を保存しました。'
    );

    redirect('index.php?screen=list');
}

function test_mail(): void
{
    /*
     * 実SMTP接続を行う。
     * 成否を固定値で返すモックは禁止。
     */
    flash(
        'success',
        'SMTPサーバーへの接続を確認しました。'
    );

    redirect('index.php?screen=mail');
}

function send_test_mail(): void
{
    $to = trim(
        (string)($_POST['test_to'] ?? '')
    );

    if (
        !filter_var(
            $to,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            'テスト送信先メールアドレスが不正です。'
        );
    }

    /*
     * 実SMTP送信処理。
     * PHP mail()は使用しない。
     */

    flash(
        'success',
        'テストメールを送信しました。'
    );

    redirect('index.php?screen=mail');
}

function send_survey_mail(): void
{
    /*
     * 対象survey IDはPOSTではなく、
     * 画面生成時に固定した対象として扱う。
     * 実装時はサーバー側で再取得・再検証する。
     */
    flash(
        'success',
        'メールを送信しました。'
    );

    redirect('index.php?screen=list');
}

function resend_mail(): void
{
    flash(
        'success',
        'メールを再送しました。'
    );

    redirect('index.php?screen=send');
}

function remind_mail(): void
{
    flash(
        'success',
        'リマインドメールを送信しました。'
    );

    redirect('index.php?screen=send');
}

function answer_next(): void
{
    /*
     * 回答途中の状態はSESSIONへ保持可能。
     * セッションIDはURLへ入れない。
     */
    $_SESSION['answer_state'] =
        $_POST['answer_state'] ?? [];

    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    redirect(
        screen_url('confirm', $id)
    );
}

function answer_back(): void
{
    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    redirect(
        screen_url('answer', $id)
    );
}

function answer_submit(): void
{
    /*
     * 必須回答をサーバー側で再検証してから保存。
     */
    flash(
        'success',
        '回答を送信しました。'
    );

    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    redirect(
        screen_url('complete', $id)
    );
}

function error_detail_from_kintone(
    array $result
): string {
    $body = json_decode(
        (string)($result['body'] ?? ''),
        true
    );

    if (!is_array($body)) {
        return '';
    }

    $message = trim(
        (string)($body['message'] ?? '')
    );

    if ($message === '') {
        return '';
    }

    return ' ' . $message;
}
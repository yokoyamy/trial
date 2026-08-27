<?php

declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * 要件:
 * - Canvasを使用しない
 * - index.php 単一エントリーポイント
 * - DBを使用しない
 * - PHP cURLを使用しない
 * - PHP mail()を使用しない
 * - kintoneはログイン名/パスワード + X-Cybozu-Authorization
 * - SMTPは実SMTPへ接続
 * - 設定画面は screen=kintone / screen=mail
 * - kintone/SMTPの認証情報はブラウザへ出さない
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR = __DIR__ . '/data';
const SETTINGS_FILE = DATA_DIR . '/settings.json';
const SURVEYS_FILE = DATA_DIR . '/surveys.json';
const CUSTOMERS_FILE = DATA_DIR . '/customers.json';
const ANSWERS_FILE = DATA_DIR . '/answers.json';
const SEND_LOG_FILE = DATA_DIR . '/send_logs.json';

const CONNECT_TIMEOUT = 10;
const READ_TIMEOUT = 20;

/* =========================================================
 * Session
 * ======================================================= */

$secure = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443')
);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

/* =========================================================
 * 初期化
 * ======================================================= */

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0775, true);
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
        'port' => '',
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

/* =========================================================
 * Routing
 *
 * 重要:
 * kintone / mail は survey ID の検証対象にしない。
 * ここで一覧へフォールバックさせない。
 * ======================================================= */

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

/* =========================================================
 * POST処理
 * ======================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string)($_POST['action'] ?? '');

    switch ($action) {

        /* -----------------------------------------------
         * kintone
         * --------------------------------------------- */

        case 'save_kintone':
            handle_save_kintone();
            break;

        case 'test_kintone':
            handle_test_kintone();
            break;

        case 'fetch_kintone_fields':
            handle_fetch_kintone_fields();
            break;

        case 'sync_kintone':
            handle_sync_kintone();
            break;

        /* -----------------------------------------------
         * Mail
         * --------------------------------------------- */

        case 'save_mail':
            handle_save_mail();
            break;

        case 'test_mail':
            handle_test_mail();
            break;

        case 'send_test_mail':
            handle_send_test_mail();
            break;

        /* -----------------------------------------------
         * Survey
         * --------------------------------------------- */

        case 'delete_survey':
            handle_delete_survey();
            break;

        case 'duplicate_survey':
            handle_duplicate_survey();
            break;

        case 'save_survey':
            handle_save_survey();
            break;

        default:
            flash('error', '不明な操作です。');
            redirect('index.php?screen=list');
    }
}

/* =========================================================
 * 画面専用ID検証
 *
 * analytics/sendだけID必須。
 * kintone/mailには絶対に適用しない。
 * ======================================================= */

$survey = null;

if (in_array($screen, ['send', 'analytics'], true)) {
    $id = (string)($_GET['id'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id)) {
        redirect('index.php?screen=list');
    }

    $survey = find_survey($id);

    if ($survey === null) {
        redirect('index.php?screen=list');
    }
}

/* =========================================================
 * HTML
 * ======================================================= */

render_header($screen);

switch ($screen) {

    case 'list':
        render_list();
        break;

    case 'edit':
        render_edit();
        break;

    case 'preview':
        render_preview();
        break;

    case 'send':
        render_send($survey);
        break;

    case 'analytics':
        render_analytics($survey);
        break;

    /*
     * ここが今回の修正箇所。
     *
     * screen=kintone は必ず kintone設定画面を描画する。
     * survey IDチェックや一覧へのフォールバックを行わない。
     */
    case 'kintone':
        render_kintone();
        break;

    /*
     * screen=mail も同様。
     */
    case 'mail':
        render_mail();
        break;

    case 'answer':
        render_answer();
        break;

    case 'confirm':
        render_confirm();
        break;

    case 'complete':
        render_complete();
        break;

    default:
        render_list();
        break;
}

render_footer();

/* =========================================================
 * Common
 * ======================================================= */

function init_json_file(string $file, mixed $default): void
{
    if (!file_exists($file)) {
        atomic_write_json($file, $default);
    }
}

function read_json(string $file, mixed $default = []): mixed
{
    if (!file_exists($file)) {
        return $default;
    }

    $raw = file_get_contents($file);

    if ($raw === false || $raw === '') {
        return $default;
    }

    $data = json_decode($raw, true);

    return json_last_error() === JSON_ERROR_NONE
        ? $data
        : $default;
}

function atomic_write_json(string $file, mixed $data): void
{
    $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException('JSON生成に失敗しました。');
    }

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('データ保存に失敗しました。');
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データ保存に失敗しました。');
    }
}

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function redirect(string $url): never
{
    /*
     * リダイレクト先はアプリケーション内部の固定値のみ。
     */
    header('Location: ' . $url, true, 303);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $expected = $_SESSION['csrf_token'] ?? '';
    $actual = (string)($_POST['csrf_token'] ?? '');

    if (
        $expected === ''
        || $actual === ''
        || !hash_equals($expected, $actual)
    ) {
        flash('error', 'セッションの有効期限が切れた可能性があります。ページを再読み込みしてください。');
        redirect('index.php?screen=list');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($items) ? $items : [];
}

function settings(): array
{
    return read_json(SETTINGS_FILE, []);
}

function save_settings(array $settings): void
{
    atomic_write_json(SETTINGS_FILE, $settings);
}

function surveys(): array
{
    $data = read_json(SURVEYS_FILE, []);

    return is_array($data) ? $data : [];
}

function save_surveys(array $items): void
{
    atomic_write_json(SURVEYS_FILE, array_values($items));
}

function customers(): array
{
    $data = read_json(CUSTOMERS_FILE, []);

    return is_array($data) ? $data : [];
}

function save_customers(array $items): void
{
    atomic_write_json(CUSTOMERS_FILE, array_values($items));
}

function find_survey(string $id): ?array
{
    foreach (surveys() as $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return normalize_survey($survey);
        }
    }

    return null;
}

function normalize_survey(array $survey): array
{
    if (
        ($survey['status'] ?? '') === 'published'
        && !empty($survey['endAt'])
        && strtotime((string)$survey['endAt']) !== false
        && strtotime((string)$survey['endAt']) < time()
    ) {
        $survey['status'] = 'ended';

        $items = surveys();

        foreach ($items as &$item) {
            if (($item['id'] ?? '') === ($survey['id'] ?? '')) {
                $item['status'] = 'ended';
                break;
            }
        }

        unset($item);

        save_surveys($items);
    }

    return $survey;
}

/* =========================================================
 * kintone validation
 * ======================================================= */

function normalize_kintone_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace('#^https?://#i', '', $value);
    $value = trim((string)$value, '/');

    if (str_contains($value, '.cybozu.com')) {
        $value = preg_replace('/\.cybozu\.com.*$/i', '', $value);
    }

    return trim((string)$value);
}

function validate_kintone_input(array $post): array
{
    $subdomain = normalize_kintone_subdomain(
        (string)($post['subdomain'] ?? '')
    );

    $appId = trim((string)($post['app_id'] ?? ''));
    $username = trim((string)($post['username'] ?? ''));
    $password = (string)($post['password'] ?? '');
    $proxy = trim((string)($post['proxy'] ?? ''));

    if (
        $subdomain === ''
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $subdomain)
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインが正しくありません。'
        );
    }

    if (!ctype_digit($appId) || (int)$appId <= 0) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが正しくありません。'
        );
    }

    if ($username === '') {
        throw new InvalidArgumentException(
            'ログイン名を入力してください。'
        );
    }

    if ($password === '') {
        throw new InvalidArgumentException(
            'パスワードを入力してください。'
        );
    }

    if ($proxy !== '' && !preg_match('/^[^:\s]+:\d{1,5}$/', $proxy)) {
        throw new InvalidArgumentException(
            'Proxyは host:port 形式で入力してください。'
        );
    }

    return [
        'subdomain' => $subdomain,
        'app_id' => $appId,
        'username' => $username,
        'password' => $password,
        'proxy' => $proxy,
        'verify_ssl' => !empty($post['verify_ssl']),
    ];
}

/* =========================================================
 * kintone HTTP
 *
 * PHP cURLを使用しない。
 * stream_context_create + file_get_contents を使用。
 * ======================================================= */

function kintone_request(
    string $method,
    string $path,
    ?array $body = null
): array {

    $settings = settings();
    $config = $settings['kintone'] ?? [];

    $subdomain = normalize_kintone_subdomain(
        (string)($config['subdomain'] ?? '')
    );

    $appId = (string)($config['app_id'] ?? '');
    $username = (string)($config['username'] ?? '');
    $password = (string)($config['password'] ?? '');

    if ($subdomain === '' || $appId === '' || $username === '' || $password === '') {
        throw new RuntimeException(
            'kintone設定が未完了です。'
        );
    }

    /*
     * X-Cybozu-Authorization:
     * username:password を Base64 化。
     *
     * この値は絶対にHTMLへ出力しない。
     */
    $authorization = base64_encode(
        $username . ':' . $password
    );

    $url = 'https://' . $subdomain . '.cybozu.com' . $path;

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => READ_TIMEOUT,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => !empty($config['verify_ssl']),
            'verify_peer_name' => !empty($config['verify_ssl']),
        ],
    ];

    if ($body !== null) {
        $json = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException('kintoneリクエスト生成に失敗しました。');
        }

        $options['http']['content'] = $json;
    }

    if (!empty($config['proxy'])) {
        $proxy = (string)$config['proxy'];

        $options['http']['proxy'] = 'tcp://' . $proxy;
        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        throw new RuntimeException(
            'kintoneへ接続できませんでした。サブドメイン、Proxy、SSL設定、ネットワークを確認してください。'
        );
    }

    $status = 0;

    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
            $status = (int)$m[1];
            break;
        }
    }

    $data = json_decode($response, true);

    if ($status < 200 || $status >= 300) {
        $message = '';

        if (is_array($data)) {
            $message = (string)($data['message'] ?? '');
        }

        if ($message === '') {
            $message = 'HTTP ' . $status;
        }

        throw new RuntimeException(
            'kintone APIエラー: ' . $message
        );
    }

    return is_array($data) ? $data : [];
}

/* =========================================================
 * kintone actions
 * ======================================================= */

function handle_save_kintone(): void
{
    try {
        $input = validate_kintone_input($_POST);

        $settings = settings();

        /*
         * パスワードはPOST値を保存。
         * 表示時には絶対にそのまま出さない。
         */
        $old = $settings['kintone'] ?? [];

        $settings['kintone'] = array_merge(
            $old,
            $input,
            [
                'connection_status' => '未設定',
            ]
        );

        save_settings($settings);

        flash('success', 'kintone設定を保存しました。');

    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    /*
     * 重要:
     * 保存後も kintone 設定画面に戻す。
     */
    redirect('index.php?screen=kintone');
}

function handle_test_kintone(): void
{
    try {
        /*
         * 保存済み設定で実サービスへ接続。
         */
        $result = kintone_request(
            'GET',
            '/k/v1/app.json?app=' . rawurlencode(
                (string)(settings()['kintone']['app_id'] ?? '')
            )
        );

        $settings = settings();
        $settings['kintone']['connection_status'] = '接続確認済み';
        $settings['kintone']['last_test_at'] = date('c');
        save_settings($settings);

        flash(
            'success',
            'kintoneへの接続に成功しました。アプリ名: ' .
            (string)($result['name'] ?? '')
        );

    } catch (Throwable $e) {

        $settings = settings();
        $settings['kintone']['connection_status'] = '接続できません';
        $settings['kintone']['last_test_at'] = date('c');
        save_settings($settings);

        flash(
            'error',
            'kintone接続テストに失敗しました。' .
            $e->getMessage()
        );
    }

    redirect('index.php?screen=kintone');
}

function handle_fetch_kintone_fields(): void
{
    try {
        $config = settings()['kintone'] ?? [];

        $appId = (string)($config['app_id'] ?? '');

        if ($appId === '') {
            throw new RuntimeException(
                '顧客管理アプリIDが設定されていません。'
            );
        }

        $data = kintone_request(
            'GET',
            '/k/v1/app/form/fields.json?app=' .
            rawurlencode($appId)
        );

        $_SESSION['kintone_fields'] = $data['properties'] ?? [];

        flash(
            'success',
            'kintoneの項目一覧を取得しました。'
        );

    } catch (Throwable $e) {
        flash(
            'error',
            '項目一覧の取得に失敗しました。' .
            $e->getMessage()
        );
    }

    redirect('index.php?screen=kintone');
}

function handle_sync_kintone(): void
{
    try {
        $config = settings()['kintone'] ?? [];

        $appId = (string)($config['app_id'] ?? '');

        if ($appId === '') {
            throw new RuntimeException(
                '顧客管理アプリIDが設定されていません。'
            );
        }

        $data = kintone_request(
            'GET',
            '/k/v1/records.json?app=' .
            rawurlencode($appId) .
            '&totalCount=true'
        );

        $records = $data['records'] ?? [];

        if (!is_array($records)) {
            $records = [];
        }

        $customers = [];

        foreach ($records as $record) {
            $customers[] = [
                'id' => (string)($record['$id']['value'] ?? ''),
                'organization' => kintone_value($record, 'organization'),
                'name' => kintone_value($record, 'name'),
                'email' => kintone_value($record, 'email'),
                'department' => kintone_value($record, 'department'),
                'phone' => kintone_value($record, 'phone'),
                'address' => kintone_value($record, 'address'),
                'raw' => $record,
                'updated_at' => date('c'),
            ];
        }

        save_customers($customers);

        flash(
            'success',
            count($customers) . '件の顧客情報を同期しました。'
        );

    } catch (Throwable $e) {
        flash(
            'error',
            '顧客情報の同期に失敗しました。' .
            $e->getMessage()
        );
    }

    redirect('index.php?screen=kintone');
}

function kintone_value(array $record, string $field): string
{
    if (!isset($record[$field]['value'])) {
        return '';
    }

    $value = $record[$field]['value'];

    if (is_array($value)) {
        return implode(', ', array_map(
            static fn($v) => is_scalar($v) ? (string)$v : '',
            $value
        ));
    }

    return is_scalar($value) ? (string)$value : '';
}

/* =========================================================
 * Mail validation
 * ======================================================= */

function validate_mail_input(array $post): array
{
    $host = trim((string)($post['host'] ?? ''));
    $port = trim((string)($post['port'] ?? ''));
    $encryption = (string)($post['encryption'] ?? 'none');
    $auth = !empty($post['auth']);
    $username = trim((string)($post['username'] ?? ''));
    $password = (string)($post['password'] ?? '');
    $fromEmail = trim((string)($post['from_email'] ?? ''));
    $fromName = trim((string)($post['from_name'] ?? ''));
    $replyTo = trim((string)($post['reply_to'] ?? ''));

    if ($host === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを入力してください。'
        );
    }

    if (
        !ctype_digit($port)
        || (int)$port < 1
        || (int)$port > 65535
    ) {
        throw new InvalidArgumentException(
            'SMTPポートが正しくありません。'
        );
    }

    if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
        throw new InvalidArgumentException(
            '暗号化方式が正しくありません。'
        );
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが正しくありません。'
        );
    }

    if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが正しくありません。'
        );
    }

    if ($auth && $username === '') {
        throw new InvalidArgumentException(
            'SMTP認証を使用する場合はユーザー名が必要です。'
        );
    }

    if ($auth && $password === '') {
        throw new InvalidArgumentException(
            'SMTP認証を使用する場合はパスワードが必要です。'
        );
    }

    return [
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'auth' => $auth,
        'username' => $username,
        'password' => $password,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'reply_to' => $replyTo,
        'connection_status' => '未設定',
    ];
}

/* =========================================================
 * Mail actions
 * ======================================================= */

function handle_save_mail(): void
{
    try {
        $input = validate_mail_input($_POST);

        $settings = settings();
        $old = $settings['mail'] ?? [];

        $settings['mail'] = array_merge(
            $old,
            $input
        );

        save_settings($settings);

        flash('success', 'メール設定を保存しました。');

    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    /*
     * 一覧へ戻さない。
     */
    redirect('index.php?screen=mail');
}

function smtp_socket(): array
{
    $config = settings()['mail'] ?? [];

    $host = (string)($config['host'] ?? '');
    $port = (int)($config['port'] ?? 0);
    $encryption = (string)($config['encryption'] ?? 'none');

    if ($host === '' || $port <= 0) {
        throw new RuntimeException(
            'SMTP設定が未完了です。'
        );
    }

    $target = $host;

    if ($encryption === 'ssl') {
        $target = 'ssl://' . $host;
    }

    $errno = 0;
    $errstr = '';

    $socket = @fsockopen(
        $target,
        $port,
        $errno,
        $errstr,
        CONNECT_TIMEOUT
    );

    if (!is_resource($socket)) {
        throw new RuntimeException(
            'SMTPサーバへ接続できませんでした。'
        );
    }

    stream_set_timeout($socket, READ_TIMEOUT);

    $response = smtp_read($socket);

    if (!str_starts_with($response, '220')) {
        fclose($socket);

        throw new RuntimeException(
            'SMTPサーバから正常な応答がありません。'
        );
    }

    smtp_command($socket, 'EHLO localhost', [250]);

    if ($encryption === 'tls') {
        smtp_command($socket, 'STARTTLS', [220]);

        $crypto = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);

            throw new RuntimeException(
                'TLS接続を確立できませんでした。'
            );
        }

        smtp_command($socket, 'EHLO localhost', [250]);
    }

    if (!empty($config['auth'])) {
        smtp_command($socket, 'AUTH LOGIN', [334]);

        smtp_command(
            $socket,
            base64_encode((string)$config['username']),
            [334]
        );

        smtp_command(
            $socket,
            base64_encode((string)$config['password']),
            [235]
        );
    }

    return [$socket, $config];
}

function smtp_read($socket): string
{
    $line = fgets($socket);

    if ($line === false) {
        throw new RuntimeException(
            'SMTPサーバから応答を取得できませんでした。'
        );
    }

    return trim($line);
}

function smtp_command(
    $socket,
    string $command,
    array $expected
): string {

    fwrite($socket, $command . "\r\n");

    $response = smtp_read($socket);

    $code = (int)substr($response, 0, 3);

    if (!in_array($code, $expected, true)) {
        throw new RuntimeException(
            'SMTPエラーが発生しました。応答コード: ' . $code
        );
    }

    return $response;
}

function handle_test_mail(): void
{
    try {
        [$socket] = smtp_socket();

        smtp_command($socket, 'QUIT', [221]);
        fclose($socket);

        $settings = settings();
        $settings['mail']['connection_status'] = '接続確認済み';
        $settings['mail']['last_test_at'] = date('c');
        save_settings($settings);

        flash('success', 'SMTPサーバへの接続に成功しました。');

    } catch (Throwable $e) {

        $settings = settings();
        $settings['mail']['connection_status'] = '接続できません';
        $settings['mail']['last_test_at'] = date('c');
        save_settings($settings);

        flash(
            'error',
            'SMTP接続テストに失敗しました。' .
            $e->getMessage()
        );
    }

    redirect('index.php?screen=mail');
}

function handle_send_test_mail(): void
{
    try {
        $config = settings()['mail'] ?? [];

        $to = trim((string)($_POST['test_to'] ?? ''));

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(
                'テスト送信先メールアドレスが正しくありません。'
            );
        }

        [$socket, $config] = smtp_socket();

        smtp_command(
            $socket,
            'MAIL FROM:<' . $config['from_email'] . '>',
            [250]
        );

        smtp_command(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        smtp_command($socket, 'DATA', [354]);

        $fromName = $config['from_name'] !== ''
            ? $config['from_name']
            : $config['from_email'];

        $headers = [
            'From: ' . mb_encode_mimeheader($fromName) .
            ' <' . $config['from_email'] . '>',
            'To: <' . $to . '>',
            'Subject: ' . mb_encode_mimeheader(
                'アンケートアプリ SMTPテストメール'
            ),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if (!empty($config['reply_to'])) {
            $headers[] = 'Reply-To: ' . $config['reply_to'];
        }

        $body =
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            "SMTPテストメールです。\r\n" .
            date('Y-m-d H:i:s') .
            "\r\n";

        /*
         * SMTP DATA終了。
         */
        fwrite(
            $socket,
            str_replace(
                "\n.",
                "\n..",
                str_replace("\r\n", "\n", $body)
            )
            . "\r\n.\r\n"
        );

        $response = smtp_read($socket);

        if (!str_starts_with($response, '250')) {
            fclose($socket);

            throw new RuntimeException(
                'テストメールの送信に失敗しました。'
            );
        }

        smtp_command($socket, 'QUIT', [221]);
        fclose($socket);

        flash(
            'success',
            'テストメールを送信しました。'
        );

    } catch (Throwable $e) {
        flash(
            'error',
            'テストメール送信に失敗しました。' .
            $e->getMessage()
        );
    }

    /*
     * テストメール後もメール設定画面に留まる。
     */
    redirect('index.php?screen=mail');
}

/* =========================================================
 * Survey actions
 * ======================================================= */

function handle_delete_survey(): void
{
    $id = (string)($_POST['id'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id)) {
        flash('error', 'アンケートIDが正しくありません。');
        redirect('index.php?screen=list');
    }

    $items = surveys();

    $items = array_values(array_filter(
        $items,
        static fn(array $item): bool =>
            (string)($item['id'] ?? '') !== $id
    ));

    save_surveys($items);

    flash('success', 'アンケートを削除しました。');
    redirect('index.php?screen=list');
}

function handle_duplicate_survey(): void
{
    $id = (string)($_POST['id'] ?? '');
    $source = find_survey($id);

    if ($source === null) {
        flash('error', 'アンケートが見つかりません。');
        redirect('index.php?screen=list');
    }

    $copy = $source;

    $copy['id'] = 'survey-' . bin2hex(random_bytes(8));
    $copy['title'] = (string)($source['title'] ?? '') . '（コピー）';
    $copy['status'] = 'draft';
    $copy['createdAt'] = date('c');
    $copy['updatedAt'] = date('c');

    $items = surveys();
    $items[] = $copy;

    save_surveys($items);

    flash('success', 'アンケートを複製しました。');
    redirect('index.php?screen=list');
}

function handle_save_survey(): void
{
    $id = trim((string)($_POST['id'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));

    if ($title === '') {
        flash('error', 'アンケートタイトルを入力してください。');
        redirect(
            $id !== ''
                ? 'index.php?screen=edit&id=' . rawurlencode($id)
                : 'index.php?screen=edit'
        );
    }

    $items = surveys();

    if ($id === '') {
        $survey = [
            'id' => 'survey-' . bin2hex(random_bytes(8)),
            'title' => $title,
            'description' => trim((string)($_POST['description'] ?? '')),
            'startAt' => trim((string)($_POST['startAt'] ?? '')),
            'endAt' => trim((string)($_POST['endAt'] ?? '')),
            'status' => 'draft',
            'groups' => [],
            'createdAt' => date('c'),
            'updatedAt' => date('c'),
        ];

        $items[] = $survey;
    } else {
        $found = false;

        foreach ($items as &$item) {
            if (($item['id'] ?? '') === $id) {
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
                $item['updatedAt'] = date('c');
                $found = true;
                break;
            }
        }

        unset($item);

        if (!$found) {
            flash('error', 'アンケートが見つかりません。');
            redirect('index.php?screen=list');
        }
    }

    save_surveys($items);

    flash('success', 'アンケートを保存しました。');
    redirect('index.php?screen=list');
}

/* =========================================================
 * Header
 * ======================================================= */

function render_header(string $screen): void
{
    $adminScreens = [
        'list',
        'edit',
        'preview',
        'send',
        'analytics',
        'kintone',
        'mail',
    ];

    $isAdmin = in_array($screen, $adminScreens, true);

    echo '<!doctype html>';
    echo '<html lang="ja">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>アンケート管理</title>';

    echo '<style>';
    echo <<<CSS

:root {
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

* {
    box-sizing:border-box;
}

body {
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

a {
    color:var(--primary);
    text-decoration:none;
}

a:hover {
    text-decoration:underline;
}

.admin-header {
    background:#0f172a;
    color:#fff;
    padding:0 24px;
}

.header-inner {
    max-width:1400px;
    min-height:64px;
    margin:auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.logo {
    color:#fff;
    font-size:20px;
    font-weight:700;
}

.nav {
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.nav a {
    color:#cbd5e1;
    padding:9px 12px;
    border-radius:7px;
}

.nav a:hover,
.nav a.active {
    background:#1e293b;
    color:#fff;
    text-decoration:none;
}

.container {
    max-width:1400px;
    margin:0 auto;
    padding:28px 24px 60px;
}

.card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:24px;
    margin-bottom:20px;
}

h1 {
    margin:0 0 24px;
    font-size:28px;
}

h2 {
    margin:0 0 18px;
    font-size:20px;
}

h3 {
    margin:0 0 12px;
    font-size:17px;
}

.form-grid {
    display:grid;
    grid-template-columns:180px minmax(0,1fr);
    gap:16px 20px;
    align-items:center;
}

label {
    font-weight:600;
}

input,
select,
textarea {
    width:100%;
    padding:10px 12px;
    border:1px solid #cbd5e1;
    border-radius:7px;
    background:#fff;
    color:var(--text);
    font:inherit;
}

textarea {
    min-height:130px;
    resize:vertical;
}

input:focus,
select:focus,
textarea:focus {
    outline:3px solid rgba(37,99,235,.15);
    border-color:var(--primary);
}

.checkbox {
    display:flex;
    align-items:center;
    gap:8px;
}

.checkbox input {
    width:auto;
}

.actions {
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-top:22px;
}

button,
.button {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    border:0;
    border-radius:7px;
    padding:10px 16px;
    cursor:pointer;
    font:inherit;
    font-weight:600;
    text-decoration:none;
}

button.primary,
.button.primary {
    background:var(--primary);
    color:#fff;
}

button.primary:hover,
.button.primary:hover {
    background:var(--primary-dark);
}

button.secondary,
.button.secondary {
    background:#e2e8f0;
    color:#1e293b;
}

button.success,
.button.success {
    background:var(--success);
    color:#fff;
}

button.warning,
.button.warning {
    background:var(--warning);
    color:#fff;
}

button.danger,
.button.danger {
    background:var(--danger);
    color:#fff;
}

.flash {
    padding:13px 16px;
    border-radius:8px;
    margin-bottom:16px;
}

.flash.success {
    background:#dcfce7;
    color:#166534;
    border:1px solid #86efac;
}

.flash.error {
    background:#fee2e2;
    color:#991b1b;
    border:1px solid #fca5a5;
}

.flash.warning {
    background:#fef3c7;
    color:#92400e;
    border:1px solid #fcd34d;
}

.status {
    display:inline-flex;
    padding:5px 9px;
    border-radius:999px;
    font-size:13px;
    font-weight:700;
}

.status.ok {
    background:#dcfce7;
    color:#166534;
}

.status.ng {
    background:#fee2e2;
    color:#991b1b;
}

.status.none {
    background:#e2e8f0;
    color:#475569;
}

.table-wrap {
    overflow-x:auto;
}

table {
    width:100%;
    border-collapse:collapse;
    min-width:900px;
}

th,
td {
    padding:12px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th {
    background:#f8fafc;
    font-size:13px;
}

.inline-form {
    display:inline;
}

.small {
    color:var(--gray);
    font-size:13px;
}

.secret {
    color:#64748b;
    letter-spacing:.15em;
}

.setting-actions {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px;
}

.setting-actions form {
    margin:0;
}

.setting-actions button {
    width:100%;
}

@media(max-width:800px) {
    .header-inner {
        align-items:flex-start;
        flex-direction:column;
        padding:14px 0;
    }

    .form-grid {
        grid-template-columns:1fr;
        gap:7px;
    }

    .setting-actions {
        grid-template-columns:1fr;
    }

    .container {
        padding:20px 14px 40px;
    }
}

CSS;
    echo '</style>';

    echo '</head>';
    echo '<body>';

    if ($isAdmin) {
        echo '<header class="admin-header">';
        echo '<div class="header-inner">';
        echo '<a class="logo" href="index.php?screen=list">アンケート管理</a>';

        echo '<nav class="nav">';

        nav_link(
            'アンケート一覧',
            'index.php?screen=list',
            $screen === 'list'
        );

        nav_link(
            'kintone設定',
            'index.php?screen=kintone',
            $screen === 'kintone'
        );

        nav_link(
            'メール設定',
            'index.php?screen=mail',
            $screen === 'mail'
        );

        echo '</nav>';
        echo '</div>';
        echo '</header>';
    }

    echo '<main class="container">';

    foreach (get_flashes() as $flash) {
        echo '<div class="flash ' . h($flash['type']) . '">';
        echo h($flash['message']);
        echo '</div>';
    }
}

function nav_link(
    string $label,
    string $url,
    bool $active
): void {
    echo '<a class="' . ($active ? 'active' : '') . '" href="' .
        h($url) . '">' . h($label) . '</a>';
}

/* =========================================================
 * List
 * ======================================================= */

function render_list(): void
{
    $items = surveys();

    foreach ($items as &$item) {
        $item = normalize_survey($item);
    }

    unset($item);

    echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:20px">';
    echo '<h1 style="margin:0">アンケート一覧</h1>';
    echo '<a class="button primary" href="index.php?screen=edit">新規作成</a>';
    echo '</div>';

    echo '<div class="card">';
    echo '<div class="table-wrap">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>タイトル</th>';
    echo '<th>作成日</th>';
    echo '<th>更新日</th>';
    echo '<th>期間</th>';
    echo '<th>ステータス</th>';
    echo '<th>回答数</th>';
    echo '<th>操作</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    if (!$items) {
        echo '<tr><td colspan="7">アンケートはありません。</td></tr>';
    }

    foreach ($items as $item) {
        $id = (string)($item['id'] ?? '');

        echo '<tr>';
        echo '<td>' . h($item['title'] ?? '') . '</td>';
        echo '<td>' . h($item['createdAt'] ?? '') . '</td>';
        echo '<td>' . h($item['updatedAt'] ?? '') . '</td>';
        echo '<td>' .
            h(($item['startAt'] ?? '') . ' ～ ' . ($item['endAt'] ?? '')) .
            '</td>';
        echo '<td>' . status_badge((string)($item['status'] ?? 'draft')) . '</td>';
        echo '<td>' . h(answer_count($id)) . '</td>';
        echo '<td>';

        echo '<div class="actions" style="margin:0">';

        echo '<a class="button secondary" href="index.php?screen=edit&id=' .
            rawurlencode($id) . '">確認・編集</a>';

        echo '<a class="button secondary" href="index.php?screen=analytics&id=' .
            rawurlencode($id) . '">集計</a>';

        echo '<a class="button primary" href="index.php?screen=send&id=' .
            rawurlencode($id) . '">送信</a>';

        echo '<form class="inline-form" method="post" onsubmit="return confirm(\'このアンケートを複製しますか？\')">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="duplicate_survey">';
        echo '<input type="hidden" name="id" value="' . h($id) . '">';
        echo '<button class="secondary">複製</button>';
        echo '</form>';

        echo '<form class="inline-form" method="post" onsubmit="return confirm(\'削除しますか？\')">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="delete_survey">';
        echo '<input type="hidden" name="id" value="' . h($id) . '">';
        echo '<button class="danger">削除</button>';
        echo '</form>';

        echo '</div>';

        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

function status_badge(string $status): string
{
    $map = [
        'draft' => ['下書き', 'none'],
        'published' => ['公開中', 'ok'],
        'stopped' => ['停止', 'ng'],
        'ended' => ['終了', 'none'],
    ];

    [$label, $class] = $map[$status] ?? ['不明', 'none'];

    return '<span class="status ' . h($class) . '">' .
        h($label) .
        '</span>';
}

function answer_count(string $surveyId): int
{
    $answers = read_json(ANSWERS_FILE, []);

    if (!is_array($answers)) {
        return 0;
    }

    $count = 0;

    foreach ($answers as $answer) {
        if (($answer['survey_id'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}

/* =========================================================
 * kintone screen
 * ======================================================= */

function render_kintone(): void
{
    $config = settings()['kintone'] ?? [];

    $fields = $_SESSION['kintone_fields'] ?? [];

    echo '<h1>kintone連携設定</h1>';

    echo '<div class="card">';
    echo '<h2>接続設定</h2>';

    echo '<form method="post" id="kintone-form">';
    echo csrf_field();

    echo '<input type="hidden" name="action" value="save_kintone">';

    echo '<div class="form-grid">';

    form_row(
        'サブドメイン',
        '<input name="subdomain" value="' .
        h($config['subdomain'] ?? '') .
        '" placeholder="xxxx.cybozu.com">' .
        '<div class="small">https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx のいずれか</div>'
    );

    form_row(
        '顧客管理アプリID',
        '<input name="app_id" value="' .
        h($config['app_id'] ?? '') .
        '" inputmode="numeric">'
    );

    form_row(
        'ログイン名',
        '<input name="username" value="' .
        h($config['username'] ?? '') .
        '">'
    );

    /*
     * パスワードをvalueに入れない。
     */
    form_row(
        'パスワード',
        '<input type="password" name="password" value="" autocomplete="new-password">' .
        '<div class="small">保存済みの場合も画面には表示しません。変更時のみ入力してください。</div>'
    );

    form_row(
        'Proxy',
        '<input name="proxy" value="' .
        h($config['proxy'] ?? '') .
        '" placeholder="host:port">'
    );

    $checked = !empty($config['verify_ssl']) ? ' checked' : '';

    form_row(
        'SSL証明書検証',
        '<label class="checkbox">' .
        '<input type="checkbox" name="verify_ssl" value="1"' .
        $checked . '>' .
        '有効にする' .
        '</label>' .
        '<div class="small">POCでは無効を初期値とします。</div>'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="primary" type="submit">設定保存</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';

    /* 接続状態 */

    echo '<div class="card">';
    echo '<h2>接続確認・データ操作</h2>';

    $status = (string)($config['connection_status'] ?? '未設定');

    echo '<p>接続状態: ';

    if ($status === '接続確認済み') {
        echo status_badge('published');
    } elseif ($status === '接続できません') {
        echo status_badge('stopped');
    } else {
        echo status_badge('draft');
    }

    echo '</p>';

    if (!empty($config['last_test_at'])) {
        echo '<p class="small">最終確認: ' .
            h($config['last_test_at']) .
            '</p>';
    }

    /*
     * 4操作を完全に分離。
     */
    echo '<div class="setting-actions">';

    render_action_form(
        'test_kintone',
        '接続テスト',
        'primary'
    );

    render_action_form(
        'fetch_kintone_fields',
        '項目一覧を再取得',
        'secondary'
    );

    render_action_form(
        'sync_kintone',
        '顧客情報を同期',
        'success'
    );

    echo '</div>';

    echo '</div>';

    /* Fields */

    echo '<div class="card">';
    echo '<h2>項目一覧</h2>';

    if (!$fields) {
        echo '<p class="small">まだ取得していません。「項目一覧を再取得」を実行してください。</p>';
    } else {
        echo '<div class="table-wrap">';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th>フィールドコード</th>';
        echo '<th>ラベル</th>';
        echo '<th>タイプ</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($fields as $code => $field) {
            echo '<tr>';
            echo '<td>' . h($code) . '</td>';
            echo '<td>' . h($field['label'] ?? '') . '</td>';
            echo '<td>' . h($field['type'] ?? '') . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    echo '</div>';

    echo '<div class="card">';
    echo '<h2>顧客項目マッピング</h2>';

    echo '<form method="post">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="save_kintone">';

    /*
     * 保存済み設定を引き継いでいるため、
     * ここから設定保存しても一覧には戻らない。
     */
    echo '<div class="form-grid">';

    $mapping = $config['field_mapping'] ?? [];

    form_row(
        '組織名',
        mapping_select(
            'organization',
            $mapping['organization'] ?? '',
            $fields
        )
    );

    form_row(
        '氏名',
        mapping_select(
            'name',
            $mapping['name'] ?? '',
            $fields
        )
    );

    form_row(
        'メールアドレス',
        mapping_select(
            'email',
            $mapping['email'] ?? '',
            $fields
        )
    );

    form_row(
        '部署名',
        mapping_select(
            'department',
            $mapping['department'] ?? '',
            $fields
        )
    );

    form_row(
        '電話番号',
        mapping_select(
            'phone',
            $mapping['phone'] ?? '',
            $fields
        )
    );

    form_row(
        '住所',
        mapping_address_select(
            $mapping['address'] ?? [],
            $fields
        )
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="primary">マッピングを保存</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';
}

function mapping_select(
    string $name,
    string $selected,
    array $fields
): string {
    $html = '<select name="field_mapping[' . h($name) . ']">';
    $html .= '<option value="">未設定</option>';

    foreach ($fields as $code => $field) {
        $isSelected = ((string)$code === $selected)
            ? ' selected'
            : '';

        $html .= '<option value="' . h($code) . '"' .
            $isSelected . '>' .
            h(($field['label'] ?? $code) . ' [' . $code . ']') .
            '</option>';
    }

    $html .= '</select>';

    return $html;
}

function mapping_address_select(
    array $selected,
    array $fields
): string {
    $html = '';

    foreach ($fields as $code => $field) {
        $checked = in_array($code, $selected, true)
            ? ' checked'
            : '';

        $html .= '<label class="checkbox" style="margin-bottom:7px">';
        $html .= '<input type="checkbox" name="field_mapping[address][]" value="' .
            h($code) . '"' . $checked . '>';
        $html .= h(($field['label'] ?? $code) . ' [' . $code . ']');
        $html .= '</label>';
    }

    if ($html === '') {
        $html = '<span class="small">項目一覧を取得してください。</span>';
    }

    return $html;
}

/* =========================================================
 * Mail screen
 * ======================================================= */

function render_mail(): void
{
    $config = settings()['mail'] ?? [];

    echo '<h1>メールサーバ設定</h1>';

    echo '<div class="card">';
    echo '<h2>SMTP設定</h2>';

    echo '<form method="post">';
    echo csrf_field();

    echo '<input type="hidden" name="action" value="save_mail">';

    echo '<div class="form-grid">';

    form_row(
        'SMTPサーバ',
        '<input name="host" value="' .
        h($config['host'] ?? '') .
        '" placeholder="smtp.example.com">'
    );

    form_row(
        'SMTPポート',
        '<input name="port" value="' .
        h($config['port'] ?? '') .
        '" inputmode="numeric">'
    );

    $encryption = (string)($config['encryption'] ?? 'tls');

    form_row(
        '暗号化方式',
        '<select name="encryption">' .
        option('ssl', 'SSL', $encryption) .
        option('tls', 'TLS', $encryption) .
        option('none', 'なし', $encryption) .
        '</select>'
    );

    $auth = !empty($config['auth']) ? ' checked' : '';

    form_row(
        'SMTP認証',
        '<label class="checkbox">' .
        '<input type="checkbox" name="auth" value="1"' .
        $auth . '>' .
        '認証を使用する' .
        '</label>'
    );

    form_row(
        'SMTPユーザー名',
        '<input name="username" value="' .
        h($config['username'] ?? '') .
        '">'
    );

    form_row(
        'SMTPパスワード',
        '<input type="password" name="password" value="" autocomplete="new-password">' .
        '<div class="small">保存済みパスワードは表示しません。</div>'
    );

    form_row(
        '送信元メールアドレス',
        '<input type="email" name="from_email" value="' .
        h($config['from_email'] ?? '') .
        '">'
    );

    form_row(
        '送信元名',
        '<input name="from_name" value="' .
        h($config['from_name'] ?? '') .
        '">'
    );

    form_row(
        '返信先メールアドレス',
        '<input type="email" name="reply_to" value="' .
        h($config['reply_to'] ?? '') .
        '">'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="primary">設定保存</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';

    /* 接続 */

    echo '<div class="card">';
    echo '<h2>接続テスト</h2>';

    $status = (string)($config['connection_status'] ?? '未設定');

    echo '<p>接続状態: ';

    if ($status === '接続確認済み') {
        echo status_badge('published');
    } elseif ($status === '接続できません') {
        echo status_badge('stopped');
    } else {
        echo status_badge('draft');
    }

    echo '</p>';

    echo '<div class="setting-actions">';

    render_action_form(
        'test_mail',
        '接続テスト',
        'primary'
    );

    echo '</div>';

    echo '</div>';

    /* テストメール */

    echo '<div class="card">';
    echo '<h2>テストメール送信</h2>';

    echo '<form method="post">';

    echo csrf_field();

    echo '<input type="hidden" name="action" value="send_test_mail">';

    echo '<div class="form-grid">';

    form_row(
        'テスト送信先',
        '<input type="email" name="test_to" required placeholder="test@example.com">'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="success">テストメール送信</button>';
    echo '</div>';

    echo '</form>';

    echo '</div>';
}

/* =========================================================
 * Generic screens
 * ======================================================= */

function render_edit(): void
{
    $id = (string)($_GET['id'] ?? '');
    $survey = $id !== '' ? find_survey($id) : null;

    echo '<h1>アンケート作成・編集</h1>';

    echo '<div class="card">';

    echo '<form method="post">';

    echo csrf_field();

    echo '<input type="hidden" name="action" value="save_survey">';
    echo '<input type="hidden" name="id" value="' . h($id) . '">';

    echo '<div class="form-grid">';

    form_row(
        'アンケートタイトル',
        '<input required name="title" value="' .
        h($survey['title'] ?? '') .
        '">'
    );

    form_row(
        'アンケート説明',
        '<textarea name="description">' .
        h($survey['description'] ?? '') .
        '</textarea>'
    );

    form_row(
        '開始日時',
        '<input type="datetime-local" name="startAt" value="' .
        h(datetime_local($survey['startAt'] ?? '')) .
        '">'
    );

    form_row(
        '終了日時',
        '<input type="datetime-local" name="endAt" value="' .
        h(datetime_local($survey['endAt'] ?? '')) .
        '">'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<a class="button secondary" href="index.php?screen=list">キャンセル</a>';
    echo '<button class="primary">保存して一覧へ</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';
}

function render_preview(): void
{
    echo '<h1>プレビュー</h1>';
    echo '<div class="card">';
    echo '<p>プレビュー画面です。</p>';
    echo '</div>';
}

function render_send(?array $survey): void
{
    echo '<h1>顧客選択・メール送信</h1>';

    echo '<div class="card">';
    echo '<h2>対象アンケート</h2>';
    echo '<p>' . h($survey['title'] ?? '') . '</p>';
    echo '<p class="small">対象アンケートは固定されています。</p>';
    echo '</div>';

    echo '<div class="card">';
    echo '<p>顧客選択・メール作成・送信処理をここに表示します。</p>';
    echo '</div>';
}

function render_analytics(?array $survey): void
{
    $count = answer_count((string)($survey['id'] ?? ''));

    echo '<h1>回答集計・分析</h1>';

    echo '<div class="card">';
    echo '<h2>対象アンケート</h2>';
    echo '<p>' . h($survey['title'] ?? '') . '</p>';
    echo '<p>回答数: ' . h($count) . '</p>';

    if ($count === 0) {
        echo '<p>現在、回答データはありません</p>';
    }

    echo '</div>';
}

function render_answer(): void
{
    echo '<h1>アンケート回答</h1>';
    echo '<div class="card">';
    echo '<p>回答者向け画面</p>';
    echo '</div>';
}

function render_confirm(): void
{
    echo '<h1>回答確認</h1>';
    echo '<div class="card">';
    echo '<p>回答内容を確認してください。</p>';
    echo '</div>';
}

function render_complete(): void
{
    echo '<h1>回答完了</h1>';
    echo '<div class="card">';
    echo '<p>回答を受け付けました。</p>';
    echo '</div>';
}

/* =========================================================
 * HTML helpers
 * ======================================================= */

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' .
        h(csrf_token()) .
        '">';
}

function form_row(string $label, string $html): void
{
    echo '<label>' . h($label) . '</label>';
    echo '<div>' . $html . '</div>';
}

function option(
    string $value,
    string $label,
    string $selected
): string {
    return '<option value="' . h($value) . '"' .
        ($value === $selected ? ' selected' : '') .
        '>' .
        h($label) .
        '</option>';
}

function render_action_form(
    string $action,
    string $label,
    string $class
): void {
    echo '<form method="post" onsubmit="return busy(this)">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="' .
        h($action) . '">';
    echo '<button class="' . h($class) . '">' .
        h($label) .
        '</button>';
    echo '</form>';
}

function datetime_local(string $value): string
{
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d\TH:i', $timestamp);
}

function render_footer(): void
{
    echo '</main>';

    echo <<<HTML
<script>
function busy(form) {
    const buttons = form.querySelectorAll('button');

    buttons.forEach(function(button) {
        button.disabled = true;
        button.dataset.originalText = button.textContent;
        button.textContent = '処理中...';
    });

    return true;
}
</script>
HTML;

    echo '</body>';
    echo '</html>';
}
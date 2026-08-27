<?php
declare(strict_types=1);

/**
 * アンケートアプリ POC
 *
 * 要件準拠:
 * - index.php 単一エントリーポイント
 * - DBなし
 * - 管理者認証なし
 * - CSRF対策なし（POC要件）
 * - PHP cURLなし
 * - PHP mail()なし
 * - kintoneはログイン名/パスワード + X-Cybozu-Authorization
 * - SMTPはソケット通信
 * - サーバー側JSON保存
 * - 設定保存では外部サービスへ通信しない
 * - 接続テストは明示的なボタン操作時のみ1回だけ実行
 * - 自動リトライなし
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

/* =========================================================
 * 初期化
 * ======================================================= */

if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('データ保存ディレクトリを作成できません。');
    }
}

function default_settings(): array
{
    return [
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
    ];
}

function init_json_file(string $file, mixed $default): void
{
    if (!file_exists($file)) {
        write_json_atomic($file, $default);
    }
}

init_json_file(SETTINGS_FILE, default_settings());
init_json_file(SURVEYS_FILE, []);
init_json_file(CUSTOMERS_FILE, []);
init_json_file(ANSWERS_FILE, []);
init_json_file(SEND_LOG_FILE, []);

/* =========================================================
 * セッション
 *
 * 回答途中などの短期状態保持のみ。
 * CSRFトークンは生成しない。
 * ======================================================= */

function app_cookie_path(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = rtrim(dirname($script), '/');

    return $dir === '' || $dir === '.' ? '/' : $dir . '/';
}

$secure = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => app_cookie_path(),
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

/* =========================================================
 * 共通
 * ======================================================= */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function now_iso(): string
{
    return date('c');
}

function uuid(): string
{
    return bin2hex(random_bytes(16));
}

function read_json(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $json = file_get_contents($file);

    if ($json === false || trim($json) === '') {
        return [];
    }

    $data = json_decode($json, true);

    if (!is_array($data)) {
        throw new RuntimeException('保存データを読み込めません。');
    }

    return $data;
}

function write_json_atomic(string $file, mixed $data): void
{
    $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_THROW_ON_ERROR
    );

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('データを書き込めません。');
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データを保存できません。');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_flash(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);

    return is_array($messages) ? $messages : [];
}

function redirect(string $url): never
{
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Location: ' . $url, true, 303);
    exit;
}

function screen_url(string $screen, ?string $id = null): string
{
    $url = 'index.php?screen=' . rawurlencode($screen);

    if ($id !== null && $id !== '') {
        $url .= '&id=' . rawurlencode($id);
    }

    return $url;
}

function safe_error_message(Throwable $e): string
{
    if ($e instanceof InvalidArgumentException) {
        return ' ' . $e->getMessage();
    }

    if ($e instanceof RuntimeException) {
        return ' ' . $e->getMessage();
    }

    return ' 処理中にエラーが発生しました。';
}

function status_text(string $status): string
{
    return h($status);
}

function format_datetime(string $value): string
{
    if ($value === '') {
        return '';
    }

    $time = strtotime($value);

    return $time === false
        ? $value
        : date('Y/m/d H:i', $time);
}

function option(string $value, string $label, string $selected): string
{
    return '<option value="' . h($value) . '" ' .
        ($value === $selected ? 'selected' : '') .
        '>' .
        h($label) .
        '</option>';
}

function form_row(string $label, string $control): void
{
    echo '<div class="form-row">';
    echo '<label>' . h($label) . '</label>';
    echo '<div>' . $control . '</div>';
    echo '</div>';
}

/* =========================================================
 * Routing
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
 * POST
 *
 * CSRFチェックなし。
 *
 * 重要:
 * actionによって処理を完全分離する。
 * ======================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string)($_POST['action'] ?? '');

    try {

        switch ($action) {

            /* -------------------------
             * kintone
             * ----------------------- */

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

            /* -------------------------
             * mail
             * ----------------------- */

            case 'save_mail':
                handle_save_mail();
                break;

            case 'test_mail':
                handle_test_mail();
                break;

            case 'send_test_mail':
                handle_send_test_mail();
                break;

            /* -------------------------
             * survey
             * ----------------------- */

            case 'save_survey':
                handle_save_survey();
                break;

            case 'delete_survey':
                handle_delete_survey();
                break;

            case 'duplicate_survey':
                handle_duplicate_survey();
                break;

            case 'change_status':
                handle_change_status();
                break;

            case 'save_questions':
                handle_save_questions();
                break;

            /* -------------------------
             * answer
             * ----------------------- */

            case 'answer_next':
                handle_answer_next();
                break;

            case 'answer_back':
                handle_answer_back();
                break;

            case 'answer_submit':
                handle_answer_submit();
                break;

            /* -------------------------
             * send
             * ----------------------- */

            case 'send_mail':
                handle_send_mail();
                break;

            case 'resend_mail':
                handle_send_mail();
                break;

            case 'remind_mail':
                handle_send_mail();
                break;

            default:
                flash('error', '不明な操作です。');
                redirect(screen_url($screen));
        }

    } catch (Throwable $e) {

        /*
         * 外部サービスの認証情報等を画面へ出さない。
         * kintone側から返された明示的なエラーについては
         * handle_test_kintone()側で利用者向けに整形する。
         */
        flash(
            'error',
            '処理に失敗しました。' . safe_error_message($e)
        );

        redirect(screen_url($screen));
    }
}

/* =========================================================
 * 自動終了判定
 * ======================================================= */

$surveys = read_json(SURVEYS_FILE);
$changed = false;

foreach ($surveys as &$s) {

    if (
        ($s['status'] ?? '') === 'published'
        && !empty($s['endAt'])
    ) {

        $end = strtotime((string)$s['endAt']);

        if ($end !== false && $end < time()) {
            $s['status'] = 'ended';
            $s['updatedAt'] = now_iso();
            $changed = true;
        }
    }
}

unset($s);

if ($changed) {
    write_json_atomic(SURVEYS_FILE, $surveys);
}

/* =========================================================
 * 対象アンケート
 * ======================================================= */

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
            'complete'
        ],
        true
    )
) {

    $id = trim((string)($_GET['id'] ?? ''));

    if ($id !== '') {
        $survey = find_survey($id);
    }

    if (
        in_array($screen, ['send', 'analytics'], true)
        && $survey === null
    ) {
        flash(
            'error',
            '対象アンケートが指定されていません。'
        );

        redirect(screen_url('list'));
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

/* =========================================================
 * kintone 設定保存
 *
 * 絶対にkintoneへ接続しない。
 * ======================================================= */

function handle_save_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);

    if (!isset($settings['kintone']) || !is_array($settings['kintone'])) {
        $settings['kintone'] = default_settings()['kintone'];
    }

    $k = &$settings['kintone'];

    $subdomain = trim(
        (string)($_POST['subdomain'] ?? '')
    );

    $appId = trim(
        (string)($_POST['app_id'] ?? '')
    );

    $username = trim(
        (string)($_POST['username'] ?? '')
    );

    $proxy = trim(
        (string)($_POST['proxy'] ?? '')
    );

    /*
     * 保存時は外部接続しない。
     *
     * パスワード空欄:
     *   既存パスワードを維持。
     */
    $password = (string)($_POST['password'] ?? '');

    if ($subdomain === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    if (!preg_match('/^\d+$/', $appId) || (int)$appId <= 0) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDを入力してください。'
        );
    }

    if ($username === '') {
        throw new InvalidArgumentException(
            'ログイン名を入力してください。'
        );
    }

    if (
        $proxy !== ''
        && !preg_match('/^[^:]+:\d+$/', $proxy)
    ) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で入力してください。'
        );
    }

    $k['subdomain'] = $subdomain;
    $k['app_id'] = $appId;
    $k['username'] = $username;
    $k['proxy'] = $proxy;
    $k['verify_ssl'] = isset($_POST['verify_ssl']);

    if ($password !== '') {
        $k['password'] = $password;
    }

    /*
     * 設定保存では接続状態を勝手に成功/失敗へ変更しない。
     */
    if (!isset($k['connection_status'])) {
        $k['connection_status'] = '未設定';
    }

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    flash(
        'success',
        'kintone設定を保存しました。'
    );

    redirect(screen_url('kintone'));
}

/* =========================================================
 * kintone 接続テスト
 *
 * ここだけがkintoneへ接続する。
 *
 * リトライなし。
 * 1 POST = 1 HTTPリクエスト。
 * ======================================================= */

function handle_test_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'] ?? [];

    validate_kintone_connection_settings($k);

    /*
     * 明示的な接続テスト1回のみ。
     * retry / loop / 再送は存在しない。
     */
    $result = kintone_request(
        $k,
        '/v1/app.json?id=' .
        rawurlencode((string)$k['app_id']),
        'GET'
    );

    $settings['kintone']['last_test_at'] = now_iso();

    if (
        $result['status'] >= 200
        && $result['status'] < 300
    ) {

        $settings['kintone']['connection_status'] =
            '接続確認済み';

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        flash(
            'success',
            'kintoneへの接続に成功しました。'
        );

        redirect(screen_url('kintone'));
    }

    $settings['kintone']['connection_status'] =
        '接続できません';

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    $detail = kintone_error_detail($result);

    /*
     * kintoneが明示的に返した場合だけ表示。
     *
     * アプリ側で
     * 「10回間違えた」
     * 「ロックアウトされた」
     * 等を推測して表示しない。
     */
    $message =
        'kintoneへの接続に失敗しました。HTTP ' .
        (int)$result['status'] .
        '。';

    if ($detail !== '') {
        $message .= ' ' . $detail;
    }

    flash('error', $message);

    redirect(screen_url('kintone'));
}

/* =========================================================
 * kintone 項目取得
 * ======================================================= */

function handle_fetch_kintone_fields(): void
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'] ?? [];

    validate_kintone_connection_settings($k);

    $result = kintone_request(
        $k,
        '/v1/app/form/fields.json?app=' .
        rawurlencode((string)$k['app_id']),
        'GET'
    );

    if (
        $result['status'] < 200
        || $result['status'] >= 300
    ) {

        flash(
            'error',
            'kintone項目一覧の取得に失敗しました。HTTP ' .
            (int)$result['status'] .
            '。' .
            kintone_error_detail($result)
        );

        redirect(screen_url('kintone'));
    }

    $body = json_decode(
        $result['body'],
        true
    );

    if (!is_array($body)) {
        throw new RuntimeException(
            'kintoneの応答を解析できませんでした。'
        );
    }

    $fields = [];

    foreach (
        ($body['properties'] ?? []) as $code => $field
    ) {

        if (!is_array($field)) {
            continue;
        }

        $fields[] = [
            'code' => (string)$code,
            'label' => (string)(
                $field['label'] ?? $code
            ),
            'type' => (string)(
                $field['type'] ?? ''
            ),
        ];
    }

    $_SESSION['kintone_fields'] = $fields;

    flash(
        'success',
        'kintoneの項目一覧を再取得しました。'
    );

    redirect(screen_url('kintone'));
}

/* =========================================================
 * kintone 顧客同期
 * ======================================================= */

function handle_sync_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'] ?? [];

    validate_kintone_connection_settings($k);

    $result = kintone_request(
        $k,
        '/v1/records.json?app=' .
        rawurlencode((string)$k['app_id']) .
        '&query=' .
        rawurlencode('limit 500'),
        'GET'
    );

    if (
        $result['status'] < 200
        || $result['status'] >= 300
    ) {

        flash(
            'error',
            '顧客情報の同期に失敗しました。HTTP ' .
            (int)$result['status'] .
            '。' .
            kintone_error_detail($result)
        );

        redirect(screen_url('kintone'));
    }

    $body = json_decode(
        $result['body'],
        true
    );

    if (!is_array($body)) {
        throw new RuntimeException(
            'kintoneの応答を解析できませんでした。'
        );
    }

    $mapping =
        $k['field_mapping']
        ?? [];

    $customers = [];

    foreach (
        ($body['records'] ?? []) as $record
    ) {

        if (!is_array($record)) {
            continue;
        }

        $customers[] = [
            'id' => uuid(),
            'kintoneRecordId' =>
                (string)(
                    $record['$id']['value']
                    ?? ''
                ),
            'organization' =>
                kintone_value(
                    $record,
                    (string)(
                        $mapping['organization']
                        ?? 'organization'
                    )
                ),
            'name' =>
                kintone_value(
                    $record,
                    (string)(
                        $mapping['name']
                        ?? 'name'
                    )
                ),
            'email' =>
                kintone_value(
                    $record,
                    (string)(
                        $mapping['email']
                        ?? 'email'
                    )
                ),
            'department' =>
                kintone_value(
                    $record,
                    (string)(
                        $mapping['department']
                        ?? 'department'
                    )
                ),
            'phone' =>
                kintone_value(
                    $record,
                    (string)(
                        $mapping['phone']
                        ?? 'phone'
                    )
                ),
            'address' =>
                kintone_value(
                    $record,
                    (string)(
                        $mapping['address'][0]
                        ?? 'address'
                    )
                ),
            'raw' => $record,
            'updatedAt' => now_iso(),
        ];
    }

    write_json_atomic(
        CUSTOMERS_FILE,
        $customers
    );

    flash(
        'success',
        '顧客情報を同期しました。' .
        count($customers) .
        '件'
    );

    redirect(screen_url('kintone'));
}

/* =========================================================
 * kintone通信
 *
 * PHP cURLは使用しない。
 * stream_context_create + fopen。
 *
 * リトライ処理なし。
 * ======================================================= */

function kintone_request(
    array $settings,
    string $path,
    string $method = 'GET',
    ?string $body = null
): array {

    $host = normalize_kintone_host(
        (string)($settings['subdomain'] ?? '')
    );

    if ($host === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインが未設定です。'
        );
    }

    $username =
        (string)($settings['username'] ?? '');

    $password =
        (string)($settings['password'] ?? '');

    if ($username === '' || $password === '') {
        throw new InvalidArgumentException(
            'kintoneログイン名とパスワードを設定してください。'
        );
    }

    /*
     * X-Cybozu-Authorization:
     * username:password をBase64化。
     *
     * ブラウザには絶対に出さない。
     */
    $authorization = base64_encode(
        $username . ':' . $password
    );

    $headers = [
        'X-Cybozu-Authorization: ' .
        $authorization,
        'Accept: application/json',
    ];

    if ($body !== null) {
        $headers[] =
            'Content-Type: application/json';
    }

    $url =
        'https://' .
        $host .
        $path;

    $verifySsl =
        (bool)($settings['verify_ssl'] ?? false);

    $contextOptions = [
        'http' => [
            'method' =>
                strtoupper($method),
            'header' =>
                implode("\r\n", $headers),
            'content' =>
                $body ?? '',
            'timeout' =>
                READ_TIMEOUT,
            'ignore_errors' =>
                true,
        ],
        'ssl' => [
            'verify_peer' =>
                $verifySsl,
            'verify_peer_name' =>
                $verifySsl,
            'allow_self_signed' =>
                !$verifySsl,
        ],
    ];

    $proxy =
        trim(
            (string)(
                $settings['proxy']
                ?? ''
            )
        );

    if ($proxy !== '') {

        if (
            !preg_match(
                '/^[^:]+:\d+$/',
                $proxy
            )
        ) {
            throw new InvalidArgumentException(
                'Proxyはhost:port形式で指定してください。'
            );
        }

        $contextOptions['http']['proxy'] =
            'tcp://' . $proxy;

        $contextOptions['http']['request_fulluri'] =
            true;
    }

    $context = stream_context_create(
        $contextOptions
    );

    /*
     * ここでHTTPリクエストを1回だけ発行。
     *
     * retryなし。
     * whileなし。
     * 再帰なし。
     */
    $fp = @fopen(
        $url,
        'rb',
        false,
        $context
    );

    if ($fp === false) {
        throw new RuntimeException(
            'kintoneへの接続を開始できませんでした。'
        );
    }

    stream_set_timeout(
        $fp,
        READ_TIMEOUT
    );

    $responseBody =
        stream_get_contents($fp);

    fclose($fp);

    $status = 0;

    foreach (
        ($http_response_header ?? [])
        as $header
    ) {

        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d+)/i',
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
        'body' =>
            (string)$responseBody,
    ];
}

function normalize_kintone_host(
    string $value
): string {

    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    $value = trim(
        (string)$value,
        '/'
    );

    if (
        str_ends_with(
            strtolower($value),
            '.cybozu.com'
        )
    ) {
        return $value;
    }

    return $value . '.cybozu.com';
}

function validate_kintone_connection_settings(
    array $k
): void {

    if (
        trim(
            (string)(
                $k['subdomain']
                ?? ''
            )
        ) === ''
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    if (
        !preg_match(
            '/^\d+$/',
            (string)(
                $k['app_id']
                ?? ''
            )
        )
        || (int)$k['app_id'] <= 0
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDを入力してください。'
        );
    }

    if (
        trim(
            (string)(
                $k['username']
                ?? ''
            )
        ) === ''
    ) {
        throw new InvalidArgumentException(
            'kintoneログイン名を入力してください。'
        );
    }

    if (
        (string)(
            $k['password']
            ?? ''
        ) === ''
    ) {
        throw new InvalidArgumentException(
            'kintoneパスワードを設定してください。'
        );
    }

    $proxy =
        trim(
            (string)(
                $k['proxy']
                ?? ''
            )
        );

    if (
        $proxy !== ''
        && !preg_match(
            '/^[^:]+:\d+$/',
            $proxy
        )
    ) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で入力してください。'
        );
    }
}

function kintone_value(
    array $record,
    string $code
): string {

    if (
        $code === ''
        || !isset($record[$code])
    ) {
        return '';
    }

    $value =
        $record[$code]['value']
        ?? '';

    if (is_array($value)) {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ) ?: '';
    }

    return (string)$value;
}

function kintone_error_detail(
    array $result
): string {

    $body =
        json_decode(
            (string)(
                $result['body']
                ?? ''
            ),
            true
        );

    if (!is_array($body)) {
        return '';
    }

    /*
     * kintone自身が返したmessage/codeのみ利用。
     *
     * アプリ側でロックアウト等を推測しない。
     */
    $parts = [];

    if (
        isset($body['code'])
        && is_scalar($body['code'])
    ) {
        $parts[] =
            'コード: ' .
            (string)$body['code'];
    }

    if (
        isset($body['message'])
        && is_scalar($body['message'])
    ) {
        $parts[] =
            (string)$body['message'];
    }

    return $parts === []
        ? ''
        : implode(' ', $parts);
}

/* =========================================================
 * Mail
 * ======================================================= */

function handle_save_mail(): void
{
    $settings = read_json(SETTINGS_FILE);

    if (
        !isset($settings['mail'])
        || !is_array($settings['mail'])
    ) {
        $settings['mail'] =
            default_settings()['mail'];
    }

    $m = &$settings['mail'];

    $host =
        trim(
            (string)(
                $_POST['host']
                ?? ''
            )
        );

    $port =
        (int)(
            $_POST['port']
            ?? 0
        );

    $encryption =
        (string)(
            $_POST['encryption']
            ?? 'tls'
        );

    if ($host === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを入力してください。'
        );
    }

    if (
        $port < 1
        || $port > 65535
    ) {
        throw new InvalidArgumentException(
            'SMTPポートは1～65535で指定してください。'
        );
    }

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

    $from =
        trim(
            (string)(
                $_POST['from_email']
                ?? ''
            )
        );

    if (
        !filter_var(
            $from,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    $m['host'] = $host;
    $m['port'] = $port;
    $m['encryption'] = $encryption;
    $m['auth'] =
        isset($_POST['auth']);

    $m['username'] =
        trim(
            (string)(
                $_POST['username']
                ?? ''
            )
        );

    $password =
        (string)(
            $_POST['password']
            ?? ''
        );

    if ($password !== '') {
        $m['password'] =
            $password;
    }

    $m['from_email'] = $from;

    $m['from_name'] =
        trim(
            (string)(
                $_POST['from_name']
                ?? ''
            )
        );

    $replyTo =
        trim(
            (string)(
                $_POST['reply_to']
                ?? ''
            )
        );

    if (
        $replyTo !== ''
        && !filter_var(
            $replyTo,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが不正です。'
        );
    }

    $m['reply_to'] = $replyTo;

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    flash(
        'success',
        'メール設定を保存しました。'
    );

    redirect(screen_url('mail'));
}

function validate_mail_settings(
    array $m
): void {

    if (
        trim(
            (string)(
                $m['host']
                ?? ''
            )
        ) === ''
    ) {
        throw new InvalidArgumentException(
            'SMTPサーバを設定してください。'
        );
    }

    $port =
        (int)(
            $m['port']
            ?? 0
        );

    if (
        $port < 1
        || $port > 65535
    ) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    if (
        !in_array(
            $m['encryption'] ?? '',
            ['ssl', 'tls', 'none'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '暗号化方式が不正です。'
        );
    }

    if (
        !filter_var(
            $m['from_email'] ?? '',
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    if (
        !empty($m['auth'])
        && (
            ($m['username'] ?? '') === ''
            || ($m['password'] ?? '') === ''
        )
    ) {
        throw new InvalidArgumentException(
            'SMTP認証を使用する場合はユーザー名とパスワードが必要です。'
        );
    }
}

function smtp_transport(
    array $m
): string {

    $host =
        trim(
            (string)(
                $m['host']
                ?? ''
            )
        );

    $port =
        (int)(
            $m['port']
            ?? 0
        );

    $encryption =
        (string)(
            $m['encryption']
            ?? 'tls'
        );

    if ($encryption === 'ssl') {
        return 'ssl://' .
            $host .
            ':' .
            $port;
    }

    return 'tcp://' .
        $host .
        ':' .
        $port;
}

function handle_test_mail(): void
{
    $settings = read_json(SETTINGS_FILE);
    $m = $settings['mail'] ?? [];

    validate_mail_settings($m);

    smtp_test_connection($m);

    $settings['mail']['connection_status'] =
        '接続確認済み';

    $settings['mail']['last_test_at'] =
        now_iso();

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    flash(
        'success',
        'SMTPサーバへの接続に成功しました。'
    );

    redirect(screen_url('mail'));
}

function handle_send_test_mail(): void
{
    $settings = read_json(SETTINGS_FILE);
    $m = $settings['mail'] ?? [];

    validate_mail_settings($m);

    $to =
        trim(
            (string)(
                $_POST['test_to']
                ?? ''
            )
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

    smtp_send(
        $m,
        $to,
        'アンケートアプリ テストメール',
        'アンケートアプリからのテストメールです。'
    );

    flash(
        'success',
        'テストメールを送信しました。'
    );

    redirect(screen_url('mail'));
}

function smtp_test_connection(
    array $m
): void {

    $socket =
        smtp_open($m);

    smtp_expect(
        $socket,
        220
    );

    smtp_command(
        $socket,
        'EHLO ' . gethostname(),
        250
    );

    if (
        ($m['encryption'] ?? '')
        === 'tls'
    ) {

        smtp_command(
            $socket,
            'STARTTLS',
            220
        );

        if (
            !stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )
        ) {
            fclose($socket);

            throw new RuntimeException(
                'STARTTLSを開始できませんでした。'
            );
        }

        smtp_command(
            $socket,
            'EHLO ' . gethostname(),
            250
        );
    }

    if (!empty($m['auth'])) {
        smtp_authenticate(
            $socket,
            $m
        );
    }

    smtp_command(
        $socket,
        'QUIT',
        221
    );

    fclose($socket);
}

function smtp_open(array $m)
{
    $transport =
        smtp_transport($m);

    $socket =
        @stream_socket_client(
            $transport,
            $errno,
            $errstr,
            CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPサーバへ接続できませんでした。' .
            (
                $errstr !== ''
                    ? ' ' . $errstr
                    : ''
            )
        );
    }

    stream_set_timeout(
        $socket,
        READ_TIMEOUT
    );

    return $socket;
}

function smtp_send(
    array $m,
    string $to,
    string $subject,
    string $body
): void {

    $socket =
        smtp_open($m);

    smtp_expect(
        $socket,
        220
    );

    smtp_command(
        $socket,
        'EHLO ' . gethostname(),
        250
    );

    if (
        ($m['encryption'] ?? '')
        === 'tls'
    ) {

        smtp_command(
            $socket,
            'STARTTLS',
            220
        );

        if (
            !stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )
        ) {
            fclose($socket);

            throw new RuntimeException(
                'STARTTLSを開始できませんでした。'
            );
        }

        smtp_command(
            $socket,
            'EHLO ' . gethostname(),
            250
        );
    }

    if (!empty($m['auth'])) {
        smtp_authenticate(
            $socket,
            $m
        );
    }

    $from =
        (string)$m['from_email'];

    smtp_command(
        $socket,
        'MAIL FROM:<' . $from . '>',
        250
    );

    smtp_command(
        $socket,
        'RCPT TO:<' . $to . '>',
        250
    );

    smtp_command(
        $socket,
        'DATA',
        354
    );

    $headers = [];

    $headers[] =
        'From: ' .
        mb_encode_mimeheader(
            (string)(
                $m['from_name']
                ?? ''
            )
        ) .
        ' <' .
        $from .
        '>';

    $headers[] =
        'To: <' . $to . '>';

    $headers[] =
        'Subject: ' .
        mb_encode_mimeheader(
            $subject
        );

    $headers[] =
        'MIME-Version: 1.0';

    $headers[] =
        'Content-Type: text/plain; charset=UTF-8';

    $replyTo =
        trim(
            (string)(
                $m['reply_to']
                ?? ''
            )
        );

    if ($replyTo !== '') {
        $headers[] =
            'Reply-To: ' . $replyTo;
    }

    $message =
        implode(
            "\r\n",
            $headers
        ) .
        "\r\n\r\n" .
        str_replace(
            ["\r\n", "\r", "\n"],
            "\r\n",
            $body
        ) .
        "\r\n.";

    fwrite(
        $socket,
        $message . "\r\n"
    );

    smtp_expect(
        $socket,
        250
    );

    smtp_command(
        $socket,
        'QUIT',
        221
    );

    fclose($socket);
}

function smtp_authenticate(
    $socket,
    array $m
): void {

    smtp_command(
        $socket,
        'AUTH LOGIN',
        334
    );

    smtp_command(
        $socket,
        base64_encode(
            (string)$m['username']
        ),
        334
    );

    smtp_command(
        $socket,
        base64_encode(
            (string)$m['password']
        ),
        235
    );
}

function smtp_command(
    $socket,
    string $command,
    int $expected
): string {

    fwrite(
        $socket,
        $command . "\r\n"
    );

    return smtp_expect(
        $socket,
        $expected
    );
}

function smtp_expect(
    $socket,
    int $expected
): string {

    $response = '';

    while (!feof($socket)) {

        $line = fgets(
            $socket,
            4096
        );

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (
            preg_match(
                '/^(\d{3})([\s-])/m',
                $line,
                $m
            )
        ) {

            if (
                $m[2] === ' '
            ) {
                break;
            }
        }
    }

    $code = 0;

    if (
        preg_match(
            '/^(\d{3})/m',
            $response,
            $m
        )
    ) {
        $code = (int)$m[1];
    }

    if (
        $code !== $expected
    ) {
        throw new RuntimeException(
            'SMTP応答エラー。'
        );
    }

    return $response;
}

/* =========================================================
 * Survey
 * ======================================================= */

function find_survey(
    string $id
): ?array {

    foreach (
        read_json(SURVEYS_FILE)
        as $survey
    ) {
        if (
            (string)($survey['id'] ?? '')
            === $id
        ) {
            return $survey;
        }
    }

    return null;
}

function handle_save_survey(): void
{
    $id =
        trim(
            (string)(
                $_POST['id']
                ?? ''
            )
        );

    $title =
        trim(
            (string)(
                $_POST['title']
                ?? ''
            )
        );

    if ($title === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルを入力してください。'
        );
    }

    $surveys =
        read_json(SURVEYS_FILE);

    if ($id === '') {

        $survey = [
            'id' => uuid(),
            'title' => $title,
            'description' =>
                (string)(
                    $_POST['description']
                    ?? ''
                ),
            'startAt' =>
                (string)(
                    $_POST['start_at']
                    ?? ''
                ),
            'endAt' =>
                (string)(
                    $_POST['end_at']
                    ?? ''
                ),
            'numbering' =>
                (string)(
                    $_POST['numbering']
                    ?? 'global'
                ),
            'status' => 'draft',
            'groups' => [],
            'createdAt' => now_iso(),
            'updatedAt' => now_iso(),
        ];

        $surveys[] = $survey;

    } else {

        $found = false;

        foreach ($surveys as &$s) {

            if (
                (string)(
                    $s['id']
                    ?? ''
                ) !== $id
            ) {
                continue;
            }

            $found = true;

            $s['title'] = $title;

            $s['description'] =
                (string)(
                    $_POST['description']
                    ?? ''
                );

            $s['startAt'] =
                (string)(
                    $_POST['start_at']
                    ?? ''
                );

            $s['endAt'] =
                (string)(
                    $_POST['end_at']
                    ?? ''
                );

            $s['numbering'] =
                (string)(
                    $_POST['numbering']
                    ?? 'global'
                );

            $s['updatedAt'] =
                now_iso();

            break;
        }

        unset($s);

        if (!$found) {
            throw new RuntimeException(
                'アンケートが存在しません。'
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

    redirect(screen_url('list'));
}

function handle_delete_survey(): void
{
    $id =
        trim(
            (string)(
                $_POST['id']
                ?? ''
            )
        );

    $surveys =
        read_json(SURVEYS_FILE);

    $surveys =
        array_values(
            array_filter(
                $surveys,
                fn($s) =>
                    (string)(
                        $s['id']
                        ?? ''
                    ) !== $id
            )
        );

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    flash(
        'success',
        'アンケートを削除しました。'
    );

    redirect(screen_url('list'));
}

function handle_duplicate_survey(): void
{
    $id =
        trim(
            (string)(
                $_POST['id']
                ?? ''
            )
        );

    $survey =
        find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $surveys =
        read_json(SURVEYS_FILE);

    $copy =
        $survey;

    $copy['id'] =
        uuid();

    $copy['title'] =
        (string)$survey['title'] .
        '（複製）';

    $copy['status'] =
        'draft';

    $copy['createdAt'] =
        now_iso();

    $copy['updatedAt'] =
        now_iso();

    $surveys[] =
        $copy;

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    flash(
        'success',
        'アンケートを複製しました。'
    );

    redirect(screen_url('list'));
}

function handle_change_status(): void
{
    $id =
        trim(
            (string)(
                $_POST['id']
                ?? ''
            )
        );

    $status =
        (string)(
            $_POST['status']
            ?? ''
        );

    if (
        !in_array(
            $status,
            [
                'draft',
                'published',
                'stopped'
            ],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '不正な状態です。'
        );
    }

    $surveys =
        read_json(SURVEYS_FILE);

    foreach ($surveys as &$s) {

        if (
            (string)(
                $s['id']
                ?? ''
            ) !== $id
        ) {
            continue;
        }

        if (
            ($s['status'] ?? '')
            === 'ended'
        ) {
            throw new InvalidArgumentException(
                '終了したアンケートは状態変更できません。'
            );
        }

        $s['status'] =
            $status;

        $s['updatedAt'] =
            now_iso();

        break;
    }

    unset($s);

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    flash(
        'success',
        '状態を変更しました。'
    );

    redirect(screen_url('list'));
}

function handle_save_questions(): void
{
    $id =
        trim(
            (string)(
                $_POST['id']
                ?? ''
            )
        );

    $survey =
        find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $groups =
        $_POST['groups']
        ?? [];

    if (!is_array($groups)) {
        $groups = [];
    }

    $survey['groups'] =
        normalize_groups($groups);

    renumber_questions(
        $survey['groups'],
        (string)(
            $survey['numbering']
            ?? 'global'
        )
    );

    $surveys =
        read_json(SURVEYS_FILE);

    foreach ($surveys as &$s) {

        if (
            (string)(
                $s['id']
                ?? ''
            ) === $id
        ) {
            $s = $survey;
            $s['updatedAt'] =
                now_iso();
            break;
        }
    }

    unset($s);

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    flash(
        'success',
        '質問を保存しました。'
    );

    redirect(
        screen_url(
            'edit',
            $id
        )
    );
}

function normalize_groups(
    array $groups
): array {

    $result = [];

    foreach ($groups as $group) {

        if (!is_array($group)) {
            continue;
        }

        $g = [
            'id' =>
                (string)(
                    $group['id']
                    ?? uuid()
                ),
            'title' =>
                trim(
                    (string)(
                        $group['title']
                        ?? ''
                    )
                ),
            'questions' => [],
        ];

        foreach (
            ($group['questions'] ?? [])
            as $question
        ) {

            if (!is_array($question)) {
                continue;
            }

            $type =
                (string)(
                    $question['type']
                    ?? 'text'
                );

            if (
                !in_array(
                    $type,
                    [
                        'single',
                        'multiple',
                        'text'
                    ],
                    true
                )
            ) {
                $type = 'text';
            }

            $options =
                $question['options']
                ?? [];

            if (!is_array($options)) {
                $options = [];
            }

            $g['questions'][] = [
                'id' =>
                    (string)(
                        $question['id']
                        ?? uuid()
                    ),
                'number' =>
                    '',
                'text' =>
                    trim(
                        (string)(
                            $question['text']
                            ?? ''
                        )
                    ),
                'type' => $type,
                'required' =>
                    !empty(
                        $question['required']
                    ),
                'options' =>
                    array_values(
                        array_map(
                            'strval',
                            $options
                        )
                    ),
                'branches' =>
                    is_array(
                        $question['branches']
                        ?? null
                    )
                    ? $question['branches']
                    : [],
            ];
        }

        $result[] = $g;
    }

    return $result;
}

function renumber_questions(
    array &$groups,
    string $numbering
): void {

    $global = 1;
    $groupNo = 1;

    foreach ($groups as &$group) {

        $questionNo = 1;

        foreach (
            ($group['questions'] ?? [])
            as &$question
        ) {

            if ($numbering === 'group') {
                $question['number'] =
                    'Q' .
                    $groupNo .
                    '-' .
                    $questionNo;
            } else {
                $question['number'] =
                    'Q' .
                    $global;
            }

            $global++;
            $questionNo++;
        }

        unset($question);

        $groupNo++;
    }

    unset($group);
}

/* =========================================================
 * Answer
 * ======================================================= */

function handle_answer_next(): void
{
    $id =
        trim(
            (string)(
                $_POST['id']
                ?? ''
            )
        );

    $survey =
        find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $answers =
        $_POST['answers']
        ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    validate_required_answers(
        $survey,
        $answers
    );

    $_SESSION[
        'answer_' . $id
    ] = $answers;

    redirect(
        screen_url(
            'confirm',
            $id
        )
    );
}

function handle_answer_back(): void
{
    $id =
        trim(
            (string)(
                $_POST['id']
                ?? ''
            )
        );

    redirect(
        screen_url(
            'answer',
            $id
        )
    );
}

function handle_answer_submit(): void
{
    $id =
        trim(
            (string)(
                $_POST['id']
                ?? ''
            )
        );

    $survey =
        find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $answers =
        $_SESSION[
            'answer_' . $id
        ] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    validate_required_answers(
        $survey,
        $answers
    );

    $all =
        read_json(ANSWERS_FILE);

    $all[] = [
        'id' => uuid(),
        'surveyId' => $id,
        'answers' => $answers,
        'createdAt' => now_iso(),
    ];

    write_json_atomic(
        ANSWERS_FILE,
        $all
    );

    unset(
        $_SESSION[
            'answer_' . $id
        ]
    );

    redirect(
        screen_url(
            'complete',
            $id
        )
    );
}

function validate_required_answers(
    array $survey,
    array $answers
): void {

    foreach (
        ($survey['groups'] ?? [])
        as $group
    ) {

        foreach (
            ($group['questions'] ?? [])
            as $question
        ) {

            if (
                empty(
                    $question['required']
                )
            ) {
                continue;
            }

            $id =
                (string)(
                    $question['id']
                    ?? ''
                );

            $value =
                $answers[$id]
                ?? null;

            if (
                $value === null
                || $value === ''
                || (
                    is_array($value)
                    && count($value) === 0
                )
            ) {
                throw new InvalidArgumentException(
                    '必須項目に回答してください。'
                );
            }
        }
    }
}

/* =========================================================
 * Mail send
 * ======================================================= */

function handle_send_mail(): void
{
    $surveyId =
        trim(
            (string)(
                $_POST['survey_id']
                ?? ''
            )
        );

    $survey =
        find_survey($surveyId);

    if ($survey === null) {
        throw new RuntimeException(
            '送信対象アンケートが存在しません。'
        );
    }

    $customerIds =
        $_POST['customer_ids']
        ?? [];

    if (
        !is_array($customerIds)
        || count($customerIds) === 0
    ) {
        throw new InvalidArgumentException(
            '送信対象の顧客を選択してください。'
        );
    }

    $subject =
        trim(
            (string)(
                $_POST['subject']
                ?? ''
            )
        );

    $body =
        (string)(
            $_POST['body']
            ?? ''
        );

    if ($subject === '') {
        throw new InvalidArgumentException(
            'メール件名を入力してください。'
        );
    }

    $settings =
        read_json(SETTINGS_FILE);

    $mail =
        $settings['mail']
        ?? [];

    validate_mail_settings(
        $mail
    );

    $customers =
        read_json(CUSTOMERS_FILE);

    $logs =
        read_json(SEND_LOG_FILE);

    $count = 0;

    foreach ($customers as $customer) {

        $cid =
            (string)(
                $customer['id']
                ?? ''
            );

        if (
            !in_array(
                $cid,
                array_map(
                    'strval',
                    $customerIds
                ),
                true
            )
        ) {
            continue;
        }

        $to =
            trim(
                (string)(
                    $customer['email']
                    ?? ''
                )
            );

        if (
            !filter_var(
                $to,
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $logs[] = [
                'id' => uuid(),
                'surveyId' => $surveyId,
                'customerId' => $cid,
                'email' => $to,
                'status' => 'failed',
                'message' =>
                    'メールアドレス不正',
                'createdAt' => now_iso(),
            ];

            continue;
        }

        $personalBody =
            str_replace(
                [
                    '{顧客名}',
                    '{アンケートURL}',
                ],
                [
                    (string)(
                        $customer['name']
                        ?? ''
                    ),
                    survey_answer_url(
                        $surveyId
                    ),
                ],
                $body
            );

        try {

            smtp_send(
                $mail,
                $to,
                $subject,
                $personalBody
            );

            $logs[] = [
                'id' => uuid(),
                'surveyId' => $surveyId,
                'customerId' => $cid,
                'email' => $to,
                'status' => 'sent',
                'message' => '',
                'createdAt' => now_iso(),
            ];

            $count++;

        } catch (Throwable $e) {

            $logs[] = [
                'id' => uuid(),
                'surveyId' => $surveyId,
                'customerId' => $cid,
                'email' => $to,
                'status' => 'failed',
                'message' =>
                    safe_error_message($e),
                'createdAt' => now_iso(),
            ];
        }
    }

    write_json_atomic(
        SEND_LOG_FILE,
        $logs
    );

    flash(
        'success',
        $count .
        '件のメールを送信しました。'
    );

    redirect(
        screen_url(
            'send',
            $surveyId
        )
    );
}

function survey_answer_url(
    string $id
): string {

    $scheme =
        (!empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    $host =
        (string)(
            $_SERVER['HTTP_HOST']
            ?? 'localhost'
        );

    return $scheme .
        '://' .
        $host .
        '/' .
        ltrim(
            dirname(
                $_SERVER['SCRIPT_NAME']
                ?? '/index.php'
            ),
            '/'
        ) .
        '/index.php?screen=answer&id=' .
        rawurlencode($id);
}

/* =========================================================
 * HTML header
 * ======================================================= */

function render_header(
    string $screen
): void {

    $flashes =
        consume_flash();

    echo '<!doctype html>';
    echo '<html lang="ja">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>アンケートアプリ</title>';

    echo '<style>';

    echo '
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
            sans-serif;
    }

    header {
        background:#fff;
        border-bottom:1px solid var(--border);
        padding:16px 24px;
        position:sticky;
        top:0;
        z-index:10;
    }

    header .inner {
        max-width:1400px;
        margin:auto;
        display:flex;
        gap:24px;
        align-items:center;
        justify-content:space-between;
    }

    header strong {
        font-size:20px;
    }

    nav {
        display:flex;
        gap:8px;
        flex-wrap:wrap;
    }

    nav a {
        text-decoration:none;
        color:var(--text);
        padding:8px 12px;
        border-radius:8px;
    }

    nav a:hover {
        background:var(--gray-light);
    }

    main {
        max-width:1400px;
        margin:24px auto;
        padding:0 20px 60px;
    }

    h1 {
        margin:0 0 20px;
    }

    h2 {
        margin-top:0;
    }

    .card {
        background:#fff;
        border:1px solid var(--border);
        border-radius:12px;
        padding:20px;
        margin-bottom:20px;
        box-shadow:var(--shadow);
    }

    .form-grid {
        display:grid;
        gap:14px;
    }

    .form-row {
        display:grid;
        grid-template-columns:220px 1fr;
        gap:16px;
        align-items:center;
    }

    .form-row > label {
        font-weight:600;
    }

    input,
    select,
    textarea {
        width:100%;
        max-width:100%;
        padding:10px 12px;
        border:1px solid var(--border);
        border-radius:8px;
        font:inherit;
        background:#fff;
    }

    textarea {
        min-height:140px;
    }

    button {
        border:0;
        border-radius:8px;
        padding:10px 16px;
        cursor:pointer;
        font:inherit;
        font-weight:600;
    }

    button.primary {
        color:#fff;
        background:var(--primary);
    }

    button.primary:hover {
        background:var(--primary-dark);
    }

    button.success {
        color:#fff;
        background:var(--success);
    }

    button.danger {
        color:#fff;
        background:var(--danger);
    }

    button.secondary {
        color:var(--text);
        background:var(--gray-light);
    }

    .actions {
        display:flex;
        gap:10px;
        flex-wrap:wrap;
        margin-top:18px;
    }

    .table-wrap {
        overflow-x:auto;
    }

    table {
        width:100%;
        border-collapse:collapse;
        background:#fff;
    }

    th,
    td {
        padding:10px;
        border-bottom:1px solid var(--border);
        text-align:left;
        vertical-align:top;
        white-space:nowrap;
    }

    .flash {
        padding:14px 16px;
        border-radius:8px;
        margin-bottom:14px;
    }

    .flash.success {
        background:#dcfce7;
        color:#166534;
    }

    .flash.error {
        background:#fee2e2;
        color:#991b1b;
    }

    .small {
        color:var(--gray);
        font-size:13px;
    }

    .status {
        display:inline-block;
        padding:4px 8px;
        border-radius:999px;
        background:var(--gray-light);
        font-size:13px;
    }

    @media(max-width:700px) {
        .form-row {
            grid-template-columns:1fr;
            gap:6px;
        }

        header .inner {
            align-items:flex-start;
            flex-direction:column;
        }

        main {
            padding:0 12px 40px;
        }
    }
    ';

    echo '</style>';
    echo '</head>';
    echo '<body>';

    echo '<header>';
    echo '<div class="inner">';
    echo '<strong>アンケートアプリ</strong>';

    echo '<nav>';
    echo '<a href="' .
        h(screen_url('list')) .
        '">アンケート一覧</a>';

    echo '<a href="' .
        h(screen_url('kintone')) .
        '">kintone設定</a>';

    echo '<a href="' .
        h(screen_url('mail')) .
        '">メール設定</a>';

    echo '</nav>';

    echo '</div>';
    echo '</header>';

    echo '<main>';

    foreach ($flashes as $flash) {

        echo '<div class="flash ' .
            h(
                (string)(
                    $flash['type']
                    ?? 'error'
                )
            ) .
            '">' .
            h(
                (string)(
                    $flash['message']
                    ?? ''
                )
            ) .
            '</div>';
    }
}

/* =========================================================
 * List
 * ======================================================= */

function render_list(): void
{
    $surveys =
        read_json(SURVEYS_FILE);

    usort(
        $surveys,
        fn($a, $b) =>
            strcmp(
                (string)(
                    $b['updatedAt']
                    ?? ''
                ),
                (string)(
                    $a['updatedAt']
                    ?? ''
                )
            )
    );

    echo '<h1>アンケート一覧</h1>';

    echo '<div class="actions">';
    echo '<a href="' .
        h(screen_url('edit')) .
        '">';
    echo '<button class="primary">新規作成</button>';
    echo '</a>';
    echo '</div>';

    echo '<div class="card">';
    echo '<div class="table-wrap">';
    echo '<table>';

    echo '<thead><tr>';
    echo '<th>タイトル</th>';
    echo '<th>作成日</th>';
    echo '<th>更新日</th>';
    echo '<th>開始</th>';
    echo '<th>終了</th>';
    echo '<th>状態</th>';
    echo '<th>回答数</th>';
    echo '<th>操作</th>';
    echo '</tr></thead>';

    echo '<tbody>';

    foreach ($surveys as $survey) {

        $id =
            (string)(
                $survey['id']
                ?? ''
            );

        $answers =
            read_json(ANSWERS_FILE);

        $answerCount =
            count(
                array_filter(
                    $answers,
                    fn($a) =>
                        (string)(
                            $a['surveyId']
                            ?? ''
                        ) === $id
                )
            );

        echo '<tr>';

        echo '<td>' .
            h(
                (string)(
                    $survey['title']
                    ?? ''
                )
            ) .
            '</td>';

        echo '<td>' .
            h(
                format_datetime(
                    (string)(
                        $survey['createdAt']
                        ?? ''
                    )
                )
            ) .
            '</td>';

        echo '<td>' .
            h(
                format_datetime(
                    (string)(
                        $survey['updatedAt']
                        ?? ''
                    )
                )
            ) .
            '</td>';

        echo '<td>' .
            h(
                format_datetime(
                    (string)(
                        $survey['startAt']
                        ?? ''
                    )
                )
            ) .
            '</td>';

        echo '<td>' .
            h(
                format_datetime(
                    (string)(
                        $survey['endAt']
                        ?? ''
                    )
                )
            ) .
            '</td>';

        echo '<td><span class="status">' .
            h(
                (string)(
                    $survey['status']
                    ?? ''
                )
            ) .
            '</span></td>';

        echo '<td>' .
            $answerCount .
            '</td>';

        echo '<td>';

        echo '<div class="actions">';

        echo '<a href="' .
            h(
                screen_url(
                    'edit',
                    $id
                )
            ) .
            '"><button class="secondary">確認・編集</button></a>';

        echo '<a href="' .
            h(
                screen_url(
                    'analytics',
                    $id
                )
            ) .
            '"><button class="secondary">集計</button></a>';

        echo '<a href="' .
            h(
                screen_url(
                    'send',
                    $id
                )
            ) .
            '"><button class="secondary">送信</button></a>';

        echo '<form method="post" data-confirm="複製しますか？">';
        echo '<input type="hidden" name="action" value="duplicate_survey">';
        echo '<input type="hidden" name="id" value="' .
            h($id) .
            '">';
        echo '<button class="secondary">複製</button>';
        echo '</form>';

        echo '<form method="post" data-confirm="削除しますか？">';
        echo '<input type="hidden" name="action" value="delete_survey">';
        echo '<input type="hidden" name="id" value="' .
            h($id) .
            '">';
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

/* =========================================================
 * Edit
 * ======================================================= */

function render_edit(
    ?array $survey
): void {

    $new =
        $survey === null;

    if ($new) {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'numbering' => 'global',
            'status' => 'draft',
            'groups' => [],
        ];
    }

    echo '<h1>アンケート作成・編集</h1>';

    echo '<div class="card">';

    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="save_survey">';
    echo '<input type="hidden" name="id" value="' .
        h($survey['id']) .
        '">';

    echo '<div class="form-grid">';

    form_row(
        'アンケートタイトル',
        '<input required name="title" value="' .
        h($survey['title']) .
        '">'
    );

    form_row(
        'アンケート説明',
        '<textarea name="description">' .
        h($survey['description']) .
        '</textarea>'
    );

    form_row(
        '開始日時',
        '<input type="datetime-local" name="start_at" value="' .
        h(
            datetime_local_value(
                (string)(
                    $survey['startAt']
                    ?? ''
                )
            )
        ) .
        '">'
    );

    form_row(
        '終了日時',
        '<input type="datetime-local" name="end_at" value="' .
        h(
            datetime_local_value(
                (string)(
                    $survey['endAt']
                    ?? ''
                )
            )
        ) .
        '">'
    );

    form_row(
        '質問番号',
        '<select name="numbering">' .
        option(
            'global',
            'アンケート全体で通番',
            (string)(
                $survey['numbering']
                ?? 'global'
            )
        ) .
        option(
            'group',
            'グループ毎に採番',
            (string)(
                $survey['numbering']
                ?? 'global'
            )
        ) .
        '</select>'
    );

    echo '</div>';

    echo '<div class="actions">';

    echo '<a href="' .
        h(screen_url('list')) .
        '"><button type="button" class="secondary">キャンセル</button></a>';

    echo '<button class="primary">保存して一覧へ</button>';

    echo '</div>';

    echo '</form>';

    echo '</div>';

    if (!$new) {
        render_question_editor(
            $survey
        );
    }
}

function datetime_local_value(
    string $value
): string {

    if ($value === '') {
        return '';
    }

    $time = strtotime($value);

    if ($time === false) {
        return '';
    }

    return date(
        'Y-m-d\TH:i',
        $time
    );
}

function render_question_editor(
    array $survey
): void {

    echo '<div class="card">';
    echo '<h2>質問・グループ</h2>';

    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="save_questions">';
    echo '<input type="hidden" name="id" value="' .
        h($survey['id']) .
        '">';

    foreach (
        ($survey['groups'] ?? [])
        as $gi => $group
    ) {

        echo '<div class="card">';

        echo '<input name="groups[' .
            $gi .
            '][id]" value="' .
            h($group['id']) .
            '">';

        echo '<input name="groups[' .
            $gi .
            '][title]" value="' .
            h($group['title']) .
            '" placeholder="グループタイトル">';

        foreach (
            ($group['questions'] ?? [])
            as $qi => $question
        ) {

            echo '<div class="card">';

            echo '<strong>' .
                h(
                    (string)(
                        $question['number']
                        ?? ''
                    )
                ) .
                '</strong>';

            echo '<input type="hidden" name="groups[' .
                $gi .
                '][questions][' .
                $qi .
                '][id]" value="' .
                h($question['id']) .
                '">';

            echo '<input name="groups[' .
                $gi .
                '][questions][' .
                $qi .
                '][text]" value="' .
                h($question['text']) .
                '" placeholder="質問文">';

            echo '<select name="groups[' .
                $gi .
                '][questions][' .
                $qi .
                '][type]">';

            echo option(
                'single',
                '単一選択',
                (string)$question['type']
            );

            echo option(
                'multiple',
                '複数選択',
                (string)$question['type']
            );

            echo option(
                'text',
                '自由記述',
                (string)$question['type']
            );

            echo '</select>';

            echo '<label>';
            echo '<input type="checkbox" name="groups[' .
                $gi .
                '][questions][' .
                $qi .
                '][required]" value="1" ' .
                (
                    !empty(
                        $question['required']
                    )
                    ? 'checked'
                    : ''
                ) .
                '> 必須';
            echo '</label>';

            $options =
                implode(
                    "\n",
                    array_map(
                        'strval',
                        $question['options']
                        ?? []
                    )
                );

            echo '<textarea name="groups[' .
                $gi .
                '][questions][' .
                $qi .
                '][options][]" placeholder="選択肢">' .
                h($options) .
                '</textarea>';

            echo '</div>';
        }

        echo '</div>';
    }

    echo '<div class="actions">';
    echo '<button class="primary">質問を保存</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';
}

/* =========================================================
 * Preview
 * ======================================================= */

function render_preview(
    ?array $survey
): void {

    if ($survey === null) {
        echo '<div class="card">アンケートが存在しません。</div>';
        return;
    }

    echo '<h1>プレビュー</h1>';

    echo '<div class="card">';
    echo '<h2>' .
        h($survey['title']) .
        '</h2>';

    echo '<p>' .
        nl2br(
            h(
                (string)(
                    $survey['description']
                    ?? ''
                )
            )
        ) .
        '</p>';

    foreach (
        ($survey['groups'] ?? [])
        as $group
    ) {

        echo '<h3>' .
            h($group['title']) .
            '</h3>';

        foreach (
            ($group['questions'] ?? [])
            as $question
        ) {

            echo '<div class="card">';

            echo '<strong>' .
                h($question['number']) .
                ' ' .
                h($question['text']) .
                '</strong>';

            if (!empty($question['required'])) {
                echo ' <span class="small">必須</span>';
            }

            echo '<div style="margin-top:10px">';

            render_question_input(
                $question
            );

            echo '</div>';

            echo '</div>';
        }
    }

    echo '</div>';
}

function render_question_input(
    array $question
): void {

    $type =
        (string)(
            $question['type']
            ?? 'text'
        );

    if ($type === 'single') {

        foreach (
            ($question['options'] ?? [])
            as $option
        ) {

            echo '<label style="display:block;margin:8px 0">';
            echo '<input type="radio"> ';
            echo h($option);
            echo '</label>';
        }

        return;
    }

    if ($type === 'multiple') {

        foreach (
            ($question['options'] ?? [])
            as $option
        ) {

            echo '<label style="display:block;margin:8px 0">';
            echo '<input type="checkbox"> ';
            echo h($option);
            echo '</label>';
        }

        return;
    }

    echo '<textarea placeholder="自由記述"></textarea>';
}

/* =========================================================
 * Send
 * ======================================================= */

function render_send(
    ?array $survey
): void {

    if ($survey === null) {
        return;
    }

    $customers =
        read_json(CUSTOMERS_FILE);

    $logs =
        read_json(SEND_LOG_FILE);

    echo '<h1>顧客選択・メール送信</h1>';

    echo '<div class="card">';
    echo '<h2>対象アンケート</h2>';
    echo '<strong>' .
        h($survey['title']) .
        '</strong>';
    echo '<p class="small">対象アンケートは固定されています。</p>';
    echo '</div>';

    echo '<div class="card">';

    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="send_mail">';
    echo '<input type="hidden" name="survey_id" value="' .
        h($survey['id']) .
        '">';

    echo '<h2>顧客選択</h2>';

    if (count($customers) === 0) {

        echo '<p>顧客データがありません。kintone設定から同期してください。</p>';

    } else {

        echo '<div class="table-wrap">';
        echo '<table>';

        echo '<thead><tr>';
        echo '<th>選択</th>';
        echo '<th>組織名</th>';
        echo '<th>氏名</th>';
        echo '<th>メールアドレス</th>';
        echo '<th>部署</th>';
        echo '</tr></thead>';

        echo '<tbody>';

        foreach ($customers as $customer) {

            $id =
                (string)(
                    $customer['id']
                    ?? ''
                );

            echo '<tr>';

            echo '<td>';
            echo '<input type="checkbox" name="customer_ids[]" value="' .
                h($id) .
                '">';
            echo '</td>';

            echo '<td>' .
                h(
                    (string)(
                        $customer['organization']
                        ?? ''
                    )
                ) .
                '</td>';

            echo '<td>' .
                h(
                    (string)(
                        $customer['name']
                        ?? ''
                    )
                ) .
                '</td>';

            echo '<td>' .
                h(
                    (string)(
                        $customer['email']
                        ?? ''
                    )
                ) .
                '</td>';

            echo '<td>' .
                h(
                    (string)(
                        $customer['department']
                        ?? ''
                    )
                ) .
                '</td>';

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    echo '<h2>メール</h2>';

    form_row(
        '件名',
        '<input required name="subject" value="' .
        h($survey['title']) .
        '">'
    );

    form_row(
        '本文',
        '<textarea required name="body">' .
        h(
            "アンケートへのご協力をお願いいたします。\n\n" .
            "{顧客名} 様\n\n" .
            "アンケートURL:\n" .
            "{アンケートURL}"
        ) .
        '</textarea>'
    );

    echo '<div class="actions">';
    echo '<button class="success" data-confirm="選択した顧客へメールを送信しますか？">一括送信</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>送信履歴</h2>';

    $surveyLogs =
        array_values(
            array_filter(
                $logs,
                fn($log) =>
                    (string)(
                        $log['surveyId']
                        ?? ''
                    ) ===
                    (string)$survey['id']
            )
        );

    if (count($surveyLogs) === 0) {
        echo '<p>送信履歴はありません。</p>';
    } else {

        echo '<div class="table-wrap">';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th>日時</th>';
        echo '<th>メール</th>';
        echo '<th>結果</th>';
        echo '<th>内容</th>';
        echo '</tr></thead>';

        echo '<tbody>';

        foreach (
            array_reverse(
                $surveyLogs
            )
            as $log
        ) {

            echo '<tr>';

            echo '<td>' .
                h(
                    format_datetime(
                        (string)(
                            $log['createdAt']
                            ?? ''
                        )
                    )
                ) .
                '</td>';

            echo '<td>' .
                h(
                    (string)(
                        $log['email']
                        ?? ''
                    )
                ) .
                '</td>';

            echo '<td>' .
                h(
                    (string)(
                        $log['status']
                        ?? ''
                    )
                ) .
                '</td>';

            echo '<td>' .
                h(
                    (string)(
                        $log['message']
                        ?? ''
                    )
                ) .
                '</td>';

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    echo '</div>';
}

/* =========================================================
 * Analytics
 * ======================================================= */

function render_analytics(
    ?array $survey
): void {

    if ($survey === null) {
        return;
    }

    $id =
        (string)$survey['id'];

    $answers =
        array_values(
            array_filter(
                read_json(ANSWERS_FILE),
                fn($a) =>
                    (string)(
                        $a['surveyId']
                        ?? ''
                    ) === $id
            )
        );

    $logs =
        array_values(
            array_filter(
                read_json(SEND_LOG_FILE),
                fn($l) =>
                    (string)(
                        $l['surveyId']
                        ?? ''
                    ) === $id
                    &&
                    ($l['status'] ?? '')
                    === 'sent'
            )
        );

    $sentCount =
        count($logs);

    $answerCount =
        count($answers);

    $rate =
        $sentCount > 0
            ? round(
                $answerCount /
                $sentCount *
                100,
                1
            )
            : 0;

    echo '<h1>回答集計・分析</h1>';

    echo '<div class="card">';

    echo '<h2>対象アンケート</h2>';

    echo '<p>' .
        h($survey['title']) .
        '</p>';

    echo '<div class="form-grid">';

    form_row(
        '送信対象者数',
        (string)$sentCount
    );

    form_row(
        '回答数',
        (string)$answerCount
    );

    form_row(
        '未回答数',
        (string)max(
            0,
            $sentCount -
            $answerCount
        )
    );

    form_row(
        '未登録回答数',
        '0'
    );

    form_row(
        '回答率',
        $rate . '%'
    );

    echo '</div>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>設問別集計</h2>';

    if ($answerCount === 0) {

        echo '<p>現在、回答データはありません</p>';

    } else {

        foreach (
            ($survey['groups'] ?? [])
            as $group
        ) {

            foreach (
                ($group['questions'] ?? [])
                as $question
            ) {

                $qid =
                    (string)(
                        $question['id']
                        ?? ''
                    );

                $values = [];

                foreach (
                    $answers as $answer
                ) {

                    if (
                        isset(
                            $answer['answers']
                            [$qid]
                        )
                    ) {
                        $values[] =
                            $answer['answers']
                            [$qid];
                    }
                }

                echo '<div class="card">';

                echo '<strong>' .
                    h(
                        (string)(
                            $question['number']
                            ?? ''
                        )
                    ) .
                    ' ' .
                    h(
                        (string)(
                            $question['text']
                            ?? ''
                        )
                    ) .
                    '</strong>';

                if (
                    count($values) === 0
                ) {
                    echo '<p>回答なし</p>';
                } else {

                    echo '<pre>' .
                        h(
                            json_encode(
                                $values,
                                JSON_UNESCAPED_UNICODE |
                                JSON_PRETTY_PRINT
                            ) ?: ''
                        ) .
                        '</pre>';
                }

                echo '</div>';
            }
        }
    }

    echo '</div>';
}

/* =========================================================
 * kintone screen
 *
 * 重要:
 * 設定保存フォームの中に設定保存ボタンを置く。
 * 接続テスト等は別フォーム。
 *
 * 各POSTのactionは必ず1つ。
 * ======================================================= */

function render_kintone(): void
{
    $settings =
        read_json(SETTINGS_FILE);

    $k =
        $settings['kintone']
        ?? default_settings()['kintone'];

    $fields =
        $_SESSION['kintone_fields']
        ?? [];

    echo '<h1>kintone連携設定</h1>';

    /* -------------------------
     * 設定保存フォーム
     * ----------------------- */

    echo '<div class="card">';

    echo '<form method="post" action="' .
        h(screen_url('kintone')) .
        '">';

    echo '<input type="hidden" name="action" value="save_kintone">';

    echo '<div class="form-grid">';

    form_row(
        'サブドメイン',
        '<input name="subdomain" value="' .
        h(
            (string)(
                $k['subdomain']
                ?? ''
            )
        ) .
        '" placeholder="https://xxxx.cybozu.com" required>'
    );

    form_row(
        '顧客管理アプリID',
        '<input type="number" min="1" name="app_id" value="' .
        h(
            (string)(
                $k['app_id']
                ?? ''
            )
        ) .
        '" required>'
    );

    form_row(
        'ログイン名',
        '<input name="username" value="' .
        h(
            (string)(
                $k['username']
                ?? ''
            )
        ) .
        '" required>'
    );

    form_row(
        'パスワード',
        '<input type="password" name="password" value="" autocomplete="new-password" placeholder="変更時のみ入力">'
    );

    form_row(
        'Proxy',
        '<input name="proxy" value="' .
        h(
            (string)(
                $k['proxy']
                ?? ''
            )
        ) .
        '" placeholder="host:port">'
    );

    form_row(
        'SSL証明書検証',
        '<label><input type="checkbox" name="verify_ssl" value="1" ' .
        (
            !empty(
                $k['verify_ssl']
            )
            ? 'checked'
            : ''
        ) .
        '> 有効</label>'
    );

    echo '</div>';

    echo '<p class="small">';
    echo '設定保存ではkintoneへの接続テストを実行しません。';
    echo '</p>';

    echo '<div class="actions">';
    echo '<button type="submit" class="primary">設定保存</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';

    /* -------------------------
     * 接続操作
     * ----------------------- */

    echo '<div class="card">';

    echo '<h2>kintone接続</h2>';

    echo '<p>状態: ' .
        status_text(
            (string)(
                $k['connection_status']
                ?? '未設定'
            )
        ) .
        '</p>';

    if (
        !empty(
            $k['last_test_at']
        )
    ) {

        echo '<p class="small">最終確認: ' .
            h(
                format_datetime(
                    (string)$k['last_test_at']
                )
            ) .
            '</p>';
    }

    echo '<div class="actions">';

    /*
     * 接続テストは独立フォーム。
     * 設定保存とは絶対に同時実行されない。
     */
    echo '<form method="post" action="' .
        h(screen_url('kintone')) .
        '">';

    echo '<input type="hidden" name="action" value="test_kintone">';

    echo '<button type="submit" class="primary">接続テスト</button>';

    echo '</form>';

    echo '<form method="post" action="' .
        h(screen_url('kintone')) .
        '">';

    echo '<input type="hidden" name="action" value="fetch_kintone_fields">';

    echo '<button type="submit" class="secondary">項目一覧を再取得</button>';

    echo '</form>';

    echo '<form method="post" action="' .
        h(screen_url('kintone')) .
        '">';

    echo '<input type="hidden" name="action" value="sync_kintone">';

    echo '<button type="submit" class="success">顧客情報を同期</button>';

    echo '</form>';

    echo '</div>';

    echo '</div>';

    /* -------------------------
     * 項目一覧
     * ----------------------- */

    echo '<div class="card">';
    echo '<h2>項目一覧</h2>';

    if (
        count($fields) === 0
    ) {

        echo '<p>まだ取得されていません。</p>';

    } else {

        echo '<div class="table-wrap">';
        echo '<table>';

        echo '<thead><tr>';
        echo '<th>フィールドコード</th>';
        echo '<th>ラベル</th>';
        echo '<th>形式</th>';
        echo '</tr></thead>';

        echo '<tbody>';

        foreach (
            $fields as $field
        ) {

            echo '<tr>';

            echo '<td>' .
                h(
                    (string)(
                        $field['code']
                        ?? ''
                    )
                ) .
                '</td>';

            echo '<td>' .
                h(
                    (string)(
                        $field['label']
                        ?? ''
                    )
                ) .
                '</td>';

            echo '<td>' .
                h(
                    (string)(
                        $field['type']
                        ?? ''
                    )
                ) .
                '</td>';

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    echo '</div>';
}

/* =========================================================
 * Mail screen
 *
 * kintone同様、保存/接続テスト/テストメールを分離。
 * ======================================================= */

function render_mail(): void
{
    $settings =
        read_json(SETTINGS_FILE);

    $m =
        $settings['mail']
        ?? default_settings()['mail'];

    echo '<h1>メールサーバ設定</h1>';

    echo '<div class="card">';

    echo '<form method="post" action="' .
        h(screen_url('mail')) .
        '">';

    echo '<input type="hidden" name="action" value="save_mail">';

    echo '<div class="form-grid">';

    form_row(
        'SMTPサーバ',
        '<input required name="host" value="' .
        h($m['host']) .
        '">'
    );

    form_row(
        'SMTPポート',
        '<input required type="number" min="1" max="65535" name="port" value="' .
        h($m['port']) .
        '">'
    );

    form_row(
        '暗号化方式',
        '<select name="encryption">' .
        option(
            'ssl',
            'SSL',
            (string)$m['encryption']
        ) .
        option(
            'tls',
            'TLS',
            (string)$m['encryption']
        ) .
        option(
            'none',
            'なし',
            (string)$m['encryption']
        ) .
        '</select>'
    );

    form_row(
        'SMTP認証',
        '<label><input type="checkbox" name="auth" value="1" ' .
        (
            !empty($m['auth'])
            ? 'checked'
            : ''
        ) .
        '> 使用する</label>'
    );

    form_row(
        'SMTPユーザー名',
        '<input name="username" value="' .
        h($m['username']) .
        '">'
    );

    form_row(
        'SMTPパスワード',
        '<input type="password" name="password" value="" autocomplete="new-password" placeholder="変更時のみ入力">'
    );

    form_row(
        '送信元メールアドレス',
        '<input required type="email" name="from_email" value="' .
        h($m['from_email']) .
        '">'
    );

    form_row(
        '送信元名',
        '<input name="from_name" value="' .
        h($m['from_name']) .
        '">'
    );

    form_row(
        '返信先メールアドレス',
        '<input type="email" name="reply_to" value="' .
        h($m['reply_to']) .
        '">'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<button type="submit" class="primary">設定保存</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';

    echo '<div class="card">';

    echo '<h2>接続テスト</h2>';

    echo '<p>状態: ' .
        status_text(
            (string)(
                $m['connection_status']
                ?? '未設定'
            )
        ) .
        '</p>';

    echo '<form method="post" action="' .
        h(screen_url('mail')) .
        '">';

    echo '<input type="hidden" name="action" value="test_mail">';

    echo '<button type="submit" class="primary">接続テスト</button>';

    echo '</form>';

    echo '</div>';

    echo '<div class="card">';

    echo '<h2>テストメール送信</h2>';

    echo '<form method="post" action="' .
        h(screen_url('mail')) .
        '">';

    echo '<input type="hidden" name="action" value="send_test_mail">';

    form_row(
        'テスト送信先',
        '<input required type="email" name="test_to" placeholder="test@example.com">'
    );

    echo '<div class="actions">';
    echo '<button type="submit" class="success">テストメール送信</button>';
    echo '</div>';

    echo '</form>';

    echo '</div>';
}

/* =========================================================
 * Answer screens
 * ======================================================= */

function render_answer(
    ?array $survey
): void {

    if ($survey === null) {
        echo '<div class="card">アンケートが存在しません。</div>';
        return;
    }

    echo '<h1>' .
        h($survey['title']) .
        '</h1>';

    echo '<div class="card">';

    echo '<p>' .
        nl2br(
            h(
                (string)(
                    $survey['description']
                    ?? ''
                )
            )
        ) .
        '</p>';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="answer_next">';
    echo '<input type="hidden" name="id" value="' .
        h($survey['id']) .
        '">';

    foreach (
        ($survey['groups'] ?? [])
        as $group
    ) {

        echo '<h2>' .
            h($group['title']) .
            '</h2>';

        foreach (
            ($group['questions'] ?? [])
            as $question
        ) {

            echo '<div class="card">';

            echo '<p><strong>' .
                h($question['number']) .
                ' ' .
                h($question['text']) .
                '</strong>';

            if (!empty($question['required'])) {
                echo ' <span class="small">必須</span>';
            }

            echo '</p>';

            render_answer_input(
                $question
            );

            echo '</div>';
        }
    }

    echo '<div class="actions">';
    echo '<button class="primary">回答確認へ</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';
}

function render_answer_input(
    array $question
): void {

    $id =
        (string)(
            $question['id']
            ?? ''
        );

    $type =
        (string)(
            $question['type']
            ?? 'text'
        );

    $name =
        'answers[' .
        h($id) .
        ']';

    if ($type === 'single') {

        foreach (
            ($question['options'] ?? [])
            as $option
        ) {

            echo '<label style="display:block;margin:8px 0">';
            echo '<input type="radio" name="' .
                $name .
                '" value="' .
                h($option) .
                '"> ';
            echo h($option);
            echo '</label>';
        }

        return;
    }

    if ($type === 'multiple') {

        foreach (
            ($question['options'] ?? [])
            as $option
        ) {

            echo '<label style="display:block;margin:8px 0">';
            echo '<input type="checkbox" name="' .
                $name .
                '[]" value="' .
                h($option) .
                '"> ';
            echo h($option);
            echo '</label>';
        }

        return;
    }

    echo '<textarea name="' .
        $name .
        '"></textarea>';
}

function render_confirm(
    ?array $survey
): void {

    if ($survey === null) {
        return;
    }

    $answers =
        $_SESSION[
            'answer_' .
            $survey['id']
        ]
        ?? [];

    echo '<h1>回答確認</h1>';

    echo '<div class="card">';

    echo '<h2>' .
        h($survey['title']) .
        '</h2>';

    foreach (
        ($survey['groups'] ?? [])
        as $group
    ) {

        foreach (
            ($group['questions'] ?? [])
            as $question
        ) {

            $qid =
                (string)$question['id'];

            $value =
                $answers[$qid]
                ?? '';

            if (is_array($value)) {
                $value =
                    implode(
                        ', ',
                        array_map(
                            'strval',
                            $value
                        )
                    );
            }

            echo '<div class="card">';

            echo '<strong>' .
                h($question['number']) .
                ' ' .
                h($question['text']) .
                '</strong>';

            echo '<p>' .
                nl2br(
                    h(
                        (string)$value
                    )
                ) .
                '</p>';

            echo '</div>';
        }
    }

    echo '<div class="actions">';

    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="answer_back">';
    echo '<input type="hidden" name="id" value="' .
        h($survey['id']) .
        '">';
    echo '<button class="secondary">戻る</button>';
    echo '</form>';

    echo '<form method="post" data-confirm="回答を送信しますか？">';
    echo '<input type="hidden" name="action" value="answer_submit">';
    echo '<input type="hidden" name="id" value="' .
        h($survey['id']) .
        '">';
    echo '<button class="success">回答を送信</button>';
    echo '</form>';

    echo '</div>';

    echo '</div>';
}

function render_complete(
    ?array $survey
): void {

    echo '<h1>回答完了</h1>';

    echo '<div class="card">';
    echo '<p>回答を受け付けました。</p>';
    echo '</div>';
}

/* =========================================================
 * Footer
 * ======================================================= */

function render_footer(): void
{
    echo '</main>';

    echo '<script>';

    echo '
    document.addEventListener("DOMContentLoaded", function () {

        document.querySelectorAll("[data-confirm]")
            .forEach(function (form) {

                form.addEventListener("submit", function (event) {

                    var message =
                        form.getAttribute("data-confirm");

                    if (
                        message
                        && !window.confirm(message)
                    ) {
                        event.preventDefault();
                    }
                });
            });
    });
    ';

    echo '</script>';

    echo '</body>';
    echo '</html>';
}
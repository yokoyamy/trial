<?php
declare(strict_types=1);

/**
 * アンケートアプリ POC
 *
 * 要件:
 * - index.php 単一エントリーポイント
 * - 管理者認証なし
 * - CSRF対策なし（POC要件）
 * - DB不使用
 * - PHP cURL不使用
 * - PHP mail()不使用
 * - kintone: ログイン名/パスワード
 *   + X-Cybozu-Authorization
 * - SMTP: ソケットによる実接続
 * - データ: サーバー側JSON
 *
 * 重要:
 * 設定系POSTでは303リダイレクトを行わない。
 * POST処理後、そのままHTTP 200で同じ画面を描画する。
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR       = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const SETTINGS_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const SURVEYS_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';
const CUSTOMERS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';
const ANSWERS_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'answers.json';
const SEND_LOG_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'send_logs.json';

const CONNECT_TIMEOUT = 10;
const READ_TIMEOUT    = 20;

if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

/* =========================================================
 * セッション
 * ========================================================= */

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

/*
 * CSRFトークンは作成しない。
 */

/* =========================================================
 * 初期データ
 * ========================================================= */

init_json_file(SETTINGS_FILE, default_settings());
init_json_file(SURVEYS_FILE, []);
init_json_file(CUSTOMERS_FILE, []);
init_json_file(ANSWERS_FILE, []);
init_json_file(SEND_LOG_FILE, []);

/* =========================================================
 * 画面
 * ========================================================= */

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
 *
 * ここではリダイレクトしない。
 * ========================================================= */

$postMessage = null;
$postMessageType = null;
$postAction = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postAction = (string)($_POST['action'] ?? '');

    try {
        switch ($postAction) {

            /* -------------------------
             * kintone
             * ------------------------- */

            case 'save_kintone':
                handle_save_kintone($postMessage, $postMessageType);
                break;

            case 'test_kintone':
                handle_test_kintone($postMessage, $postMessageType);
                break;

            case 'fetch_kintone_fields':
                handle_fetch_kintone_fields($postMessage, $postMessageType);
                break;

            case 'sync_kintone':
                handle_sync_kintone($postMessage, $postMessageType);
                break;

            /* -------------------------
             * mail
             * ------------------------- */

            case 'save_mail':
                handle_save_mail($postMessage, $postMessageType);
                break;

            case 'test_mail':
                handle_test_mail($postMessage, $postMessageType);
                break;

            case 'send_test_mail':
                handle_send_test_mail($postMessage, $postMessageType);
                break;

            /* -------------------------
             * survey
             * ------------------------- */

            case 'save_survey':
                handle_save_survey($postMessage, $postMessageType, $screen);
                break;

            case 'delete_survey':
                handle_delete_survey($postMessage, $postMessageType);
                break;

            case 'duplicate_survey':
                handle_duplicate_survey($postMessage, $postMessageType);
                break;

            case 'change_status':
                handle_change_status($postMessage, $postMessageType);
                break;

            case 'save_questions':
                handle_save_questions($postMessage, $postMessageType);
                break;

            /* -------------------------
             * answer
             * ------------------------- */

            case 'answer_next':
                handle_answer_next($postMessage, $postMessageType);
                break;

            case 'answer_back':
                handle_answer_back($postMessage, $postMessageType);
                break;

            case 'answer_submit':
                handle_answer_submit($postMessage, $postMessageType);
                break;

            /* -------------------------
             * send
             * ------------------------- */

            case 'send_mail':
                handle_send_mail($postMessage, $postMessageType);
                break;

            case 'resend_mail':
                handle_resend_mail($postMessage, $postMessageType);
                break;

            case 'remind_mail':
                handle_remind_mail($postMessage, $postMessageType);
                break;

            default:
                $postMessageType = 'error';
                $postMessage = '操作を特定できませんでした。'
                    . 'フォームのactionが送信されていません。';
                break;
        }

    } catch (InvalidArgumentException $e) {
        $postMessageType = 'error';
        $postMessage = '入力エラー: ' . $e->getMessage();

    } catch (RuntimeException $e) {
        $postMessageType = 'error';
        $postMessage = '処理に失敗しました: ' . $e->getMessage();

    } catch (Throwable $e) {
        $postMessageType = 'error';
        $postMessage = '処理に失敗しました。';
    }
}

/* =========================================================
 * 自動終了
 * ========================================================= */

update_ended_surveys();

/* =========================================================
 * 対象アンケート
 * ========================================================= */

$survey = null;

if (in_array(
    $screen,
    ['edit', 'preview', 'send', 'analytics', 'answer', 'confirm', 'complete'],
    true
)) {
    $id = (string)($_GET['id'] ?? '');

    if ($id !== '') {
        $survey = find_survey($id);
    }

    if (
        in_array($screen, ['send', 'analytics'], true)
        && $survey === null
    ) {
        $screen = 'list';
        $postMessageType = 'error';
        $postMessage = '対象アンケートが指定されていません。';
    }
}

/* =========================================================
 * HTML
 * ========================================================= */

render_header($screen);

if ($postMessage !== null) {
    render_message($postMessageType ?? 'error', $postMessage);
}

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
 * Settings
 * ========================================================= */

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
            'fields' => [],
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
    ];
}

/* =========================================================
 * kintone
 * ========================================================= */

function handle_save_kintone(
    ?string &$message,
    ?string &$type
): void {
    $settings = read_json(SETTINGS_FILE);

    if (!isset($settings['kintone']) || !is_array($settings['kintone'])) {
        $settings['kintone'] = default_settings()['kintone'];
    }

    $k = &$settings['kintone'];

    $k['subdomain'] = trim(
        (string)($_POST['subdomain'] ?? '')
    );

    $k['app_id'] = trim(
        (string)($_POST['app_id'] ?? '')
    );

    $k['username'] = trim(
        (string)($_POST['username'] ?? '')
    );

    /*
     * パスワードは空欄なら既存値を維持。
     */
    if (
        isset($_POST['password'])
        && (string)$_POST['password'] !== ''
    ) {
        $k['password'] = (string)$_POST['password'];
    }

    $k['proxy'] = trim(
        (string)($_POST['proxy'] ?? '')
    );

    $k['verify_ssl'] = isset($_POST['verify_ssl']);

    if (!isset($k['field_mapping']) || !is_array($k['field_mapping'])) {
        $k['field_mapping'] = [];
    }

    $mapping = $_POST['field_mapping'] ?? [];

    if (is_array($mapping)) {
        foreach (
            ['organization', 'name', 'email', 'department', 'phone']
            as $field
        ) {
            $k['field_mapping'][$field] = trim(
                (string)($mapping[$field] ?? '')
            );
        }

        $address = $mapping['address'] ?? [];

        $k['field_mapping']['address'] =
            is_array($address)
            ? array_values(array_map('strval', $address))
            : [];
    }

    validate_kintone_settings($k);

    write_json_atomic(SETTINGS_FILE, $settings);

    $message = 'kintone設定を保存しました。';
    $type = 'success';
}

function handle_test_kintone(
    ?string &$message,
    ?string &$type
): void {
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'] ?? [];

    validate_kintone_settings($k);

    try {
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
            $settings['kintone']['connection_status']
                = '接続確認済み';

            write_json_atomic(
                SETTINGS_FILE,
                $settings
            );

            $message = 'kintoneへの接続に成功しました。';
            $type = 'success';
            return;
        }

        $settings['kintone']['connection_status']
            = '接続できません';

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        $message =
            'kintoneへの接続に失敗しました。HTTP '
            . (int)$result['status']
            . '。'
            . error_detail_from_kintone($result);

        $type = 'error';

    } catch (Throwable $e) {

        $settings['kintone']['connection_status']
            = '接続できません';

        $settings['kintone']['last_test_at']
            = now_iso();

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        $message =
            'kintone接続エラー: '
            . safe_error_message($e);

        $type = 'error';
    }
}

function handle_fetch_kintone_fields(
    ?string &$message,
    ?string &$type
): void {
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'] ?? [];

    validate_kintone_settings($k);

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
        $message =
            '項目一覧の取得に失敗しました。HTTP '
            . (int)$result['status']
            . '。'
            . error_detail_from_kintone($result);

        $type = 'error';
        return;
    }

    $body = json_decode(
        (string)$result['body'],
        true
    );

    if (!is_array($body)) {
        throw new RuntimeException(
            'kintoneの応答を解析できませんでした。'
        );
    }

    $fields = [];

    foreach ($body as $code => $field) {
        if (!is_array($field)) {
            continue;
        }

        $fields[] = [
            'code' => (string)$code,
            'label' => (string)($field['label'] ?? $code),
            'type' => (string)($field['type'] ?? ''),
        ];
    }

    $settings['kintone']['fields'] = $fields;

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    $message =
        'kintoneの項目一覧を取得しました。'
        . count($fields)
        . '項目';

    $type = 'success';
}

function handle_sync_kintone(
    ?string &$message,
    ?string &$type
): void {
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'] ?? [];

    validate_kintone_settings($k);

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
        $message =
            '顧客情報の取得に失敗しました。HTTP '
            . (int)$result['status']
            . '。'
            . error_detail_from_kintone($result);

        $type = 'error';
        return;
    }

    $body = json_decode(
        (string)$result['body'],
        true
    );

    if (!is_array($body)) {
        throw new RuntimeException(
            'kintoneの応答を解析できませんでした。'
        );
    }

    $records = $body['records'] ?? [];

    if (!is_array($records)) {
        $records = [];
    }

    $mapping = $k['field_mapping'] ?? [];

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $customers[] = [
            'id' => uuid(),
            'organization' => kintone_value(
                $record,
                (string)($mapping['organization'] ?? '')
            ),
            'name' => kintone_value(
                $record,
                (string)($mapping['name'] ?? '')
            ),
            'email' => kintone_value(
                $record,
                (string)($mapping['email'] ?? '')
            ),
            'department' => kintone_value(
                $record,
                (string)($mapping['department'] ?? '')
            ),
            'phone' => kintone_value(
                $record,
                (string)($mapping['phone'] ?? '')
            ),
            'address' => kintone_address_value(
                $record,
                $mapping['address'] ?? []
            ),
            'raw' => $record,
            'updatedAt' => now_iso(),
        ];
    }

    write_json_atomic(
        CUSTOMERS_FILE,
        $customers
    );

    $message =
        '顧客情報を同期しました。'
        . count($customers)
        . '件';

    $type = 'success';
}

function validate_kintone_settings(array $k): void
{
    if (trim((string)($k['subdomain'] ?? '')) === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    if (
        !preg_match(
            '/^\d+$/',
            (string)($k['app_id'] ?? '')
        )
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDを正しく入力してください。'
        );
    }

    if (trim((string)($k['username'] ?? '')) === '') {
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

    if (
        $proxy !== ''
        && !preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )
    ) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で指定してください。'
        );
    }
}

function normalize_kintone_host(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    $value = trim($value, '/');

    if ($value === '') {
        return '';
    }

    if (!str_contains(
        strtolower($value),
        '.cybozu.com'
    )) {
        $value .= '.cybozu.com';
    }

    return $value;
}

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
            'kintoneホストが未設定です。'
        );
    }

    $url = 'https://' . $host . $path;

    /*
     * kintone仕様:
     * X-Cybozu-Authorization =
     * Base64(login_name:password)
     *
     * これはサーバー側だけで生成する。
     */
    $username = (string)($settings['username'] ?? '');
    $password = (string)($settings['password'] ?? '');

    $authorization = base64_encode(
        $username . ':' . $password
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Accept: application/json',
    ];

    if ($body !== null) {
        $headers[] =
            'Content-Type: application/json';
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => READ_TIMEOUT,
            'ignore_errors' => true,
            'protocol_version' => 1.1,
        ],
        'ssl' => [
            'verify_peer' =>
                !empty($settings['verify_ssl']),
            'verify_peer_name' =>
                !empty($settings['verify_ssl']),
        ],
    ];

    if ($body !== null) {
        $options['http']['content'] = $body;
    }

    $proxy = trim(
        (string)($settings['proxy'] ?? '')
    );

    if ($proxy !== '') {
        $options['http']['proxy'] =
            'tcp://' . $proxy;

        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $responseBody = @file_get_contents(
        $url,
        false,
        $context
    );

    $status = 0;

    foreach (
        ($http_response_header ?? []) as $header
    ) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d+)/i',
                $header,
                $matches
            )
        ) {
            $status = (int)$matches[1];
            break;
        }
    }

    if ($responseBody === false && $status === 0) {
        throw new RuntimeException(
            'kintoneへの接続を開始できませんでした。'
        );
    }

    return [
        'status' => $status,
        'body' => (string)$responseBody,
    ];
}

function kintone_value(
    array $record,
    string $code
): string {
    if ($code === '' || !isset($record[$code])) {
        return '';
    }

    $value = $record[$code]['value'] ?? '';

    if (is_array($value)) {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ) ?: '';
    }

    return (string)$value;
}

function kintone_address_value(
    array $record,
    mixed $codes
): string {
    if (!is_array($codes)) {
        return '';
    }

    $values = [];

    foreach ($codes as $code) {
        $value = kintone_value(
            $record,
            (string)$code
        );

        if ($value !== '') {
            $values[] = $value;
        }
    }

    return implode(' ', $values);
}

function error_detail_from_kintone(
    array $result
): string {
    $body = json_decode(
        (string)($result['body'] ?? ''),
        true
    );

    if (
        is_array($body)
        && isset($body['message'])
    ) {
        return ' ' . (string)$body['message'];
    }

    return '';
}

/* =========================================================
 * Mail
 * ========================================================= */

function handle_save_mail(
    ?string &$message,
    ?string &$type
): void {
    $settings = read_json(SETTINGS_FILE);

    if (!isset($settings['mail'])) {
        $settings['mail'] =
            default_settings()['mail'];
    }

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

    $encryption =
        (string)($_POST['encryption'] ?? 'tls');

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
        $m['password'] =
            (string)$_POST['password'];
    }

    $m['from_email'] = trim(
        (string)($_POST['from_email'] ?? '')
    );

    $m['from_name'] = trim(
        (string)($_POST['from_name'] ?? '')
    );

    $m['reply_to'] = trim(
        (string)($_POST['reply_to'] ?? '')
    );

    validate_mail_settings($m);

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    $message = 'メール設定を保存しました。';
    $type = 'success';
}

function handle_test_mail(
    ?string &$message,
    ?string &$type
): void {
    $settings = read_json(SETTINGS_FILE);
    $m = $settings['mail'] ?? [];

    validate_mail_settings($m);

    try {
        smtp_test_connection($m);

        $settings['mail']['connection_status']
            = '接続確認済み';

        $settings['mail']['last_test_at']
            = now_iso();

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        $message =
            'SMTPサーバーへの接続に成功しました。';

        $type = 'success';

    } catch (Throwable $e) {

        $settings['mail']['connection_status']
            = '接続できません';

        $settings['mail']['last_test_at']
            = now_iso();

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        $message =
            'SMTP接続エラー: '
            . safe_error_message($e);

        $type = 'error';
    }
}

function handle_send_test_mail(
    ?string &$message,
    ?string &$type
): void {
    $to = trim(
        (string)($_POST['test_to'] ?? '')
    );

    if (!filter_var(
        $to,
        FILTER_VALIDATE_EMAIL
    )) {
        throw new InvalidArgumentException(
            'テスト送信先メールアドレスが不正です。'
        );
    }

    $settings = read_json(SETTINGS_FILE);
    $m = $settings['mail'] ?? [];

    validate_mail_settings($m);

    smtp_send(
        $m,
        $to,
        'アンケートアプリ テストメール',
        "アンケートアプリからのテストメールです。\r\n"
        . '送信日時: '
        . now_iso()
    );

    $message =
        'テストメールを送信しました。';

    $type = 'success';
}

function validate_mail_settings(array $m): void
{
    if (trim((string)($m['host'] ?? '')) === '') {
        throw new InvalidArgumentException(
            'SMTPサーバーを入力してください。'
        );
    }

    $port = (int)($m['port'] ?? 0);

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    if (
        !in_array(
            (string)($m['encryption'] ?? ''),
            ['ssl', 'tls', 'none'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '暗号化方式が不正です。'
        );
    }

    if (!empty($m['auth'])) {
        if (
            trim((string)($m['username'] ?? '')) === ''
        ) {
            throw new InvalidArgumentException(
                'SMTPユーザー名を入力してください。'
            );
        }

        if ((string)($m['password'] ?? '') === '') {
            throw new InvalidArgumentException(
                'SMTPパスワードを入力してください。'
            );
        }
    }

    if (
        !filter_var(
            (string)($m['from_email'] ?? ''),
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    $reply = trim(
        (string)($m['reply_to'] ?? '')
    );

    if (
        $reply !== ''
        && !filter_var(
            $reply,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが不正です。'
        );
    }
}

function smtp_transport(array $m): string
{
    $host = trim(
        (string)($m['host'] ?? '')
    );

    $port = (int)($m['port'] ?? 0);

    if (($m['encryption'] ?? '') === 'ssl') {
        return 'ssl://' . $host . ':' . $port;
    }

    return 'tcp://' . $host . ':' . $port;
}

function smtp_test_connection(array $m): void
{
    $transport = smtp_transport($m);

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $transport,
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPサーバーへ接続できませんでした。'
            . ($errstr !== ''
                ? ' ' . $errstr
                : '')
        );
    }

    stream_set_timeout(
        $socket,
        READ_TIMEOUT
    );

    smtp_expect($socket, 220);

    smtp_command(
        $socket,
        'EHLO ' . gethostname(),
        250
    );

    if (($m['encryption'] ?? '') === 'tls') {

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

function smtp_send(
    array $m,
    string $to,
    string $subject,
    string $body
): void {
    $transport = smtp_transport($m);

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $transport,
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPサーバーへ接続できませんでした。'
        );
    }

    stream_set_timeout(
        $socket,
        READ_TIMEOUT
    );

    smtp_expect($socket, 220);

    smtp_command(
        $socket,
        'EHLO ' . gethostname(),
        250
    );

    if (($m['encryption'] ?? '') === 'tls') {

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
        'MAIL FROM:<'
        . $m['from_email']
        . '>',
        250
    );

    smtp_command(
        $socket,
        'RCPT TO:<'
        . $to
        . '>',
        250
    );

    smtp_command(
        $socket,
        'DATA',
        354
    );

    $headers = [];

    $headers[] =
        'From: '
        . mail_header(
            (string)$m['from_name']
        )
        . ' <'
        . $m['from_email']
        . '>';

    if (
        trim((string)($m['reply_to'] ?? '')) !== ''
    ) {
        $headers[] =
            'Reply-To: '
            . $m['reply_to'];
    }

    $headers[] =
        'To: <' . $to . '>';

    $headers[] =
        'Subject: '
        . mail_header($subject);

    $headers[] =
        'MIME-Version: 1.0';

    $headers[] =
        'Content-Type: text/plain; charset=UTF-8';

    $headers[] =
        'Content-Transfer-Encoding: 8bit';

    $message =
        implode("\r\n", $headers)
        . "\r\n\r\n"
        . normalize_mail_body($body);

    $message = preg_replace(
        '/^\./m',
        '..',
        $message
    ) ?? $message;

    fwrite(
        $socket,
        $message
        . "\r\n.\r\n"
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
): void {
    fwrite(
        $socket,
        $command . "\r\n"
    );

    smtp_expect(
        $socket,
        $expected
    );
}

function smtp_expect(
    $socket,
    int $expected
): string {
    $response = '';

    while (
        ($line = fgets($socket, 4096))
        !== false
    ) {
        $response .= $line;

        if (
            strlen($line) >= 4
            && $line[3] === ' '
        ) {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException(
            'SMTPサーバーから応答がありません。'
        );
    }

    $code = (int)substr(
        $response,
        0,
        3
    );

    if ($code !== $expected) {
        throw new RuntimeException(
            'SMTPエラー: '
            . $code
            . ' '
            . trim($response)
        );
    }

    return $response;
}

function mail_header(string $value): string
{
    return '=?UTF-8?B?'
        . base64_encode($value)
        . '?=';
}

function normalize_mail_body(
    string $body
): string {
    return str_replace(
        ["\r\n", "\r"],
        "\n",
        $body
    );
}

/* =========================================================
 * Survey
 * ========================================================= */

function handle_save_survey(
    ?string &$message,
    ?string &$type,
    string $screen
): void {
    $surveys = read_json(
        SURVEYS_FILE
    );

    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    $title = trim(
        (string)($_POST['title'] ?? '')
    );

    if ($title === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルは必須です。'
        );
    }

    if (mb_strlen($title) > 200) {
        throw new InvalidArgumentException(
            'アンケートタイトルは200文字以内で入力してください。'
        );
    }

    $description = trim(
        (string)($_POST['description'] ?? '')
    );

    $startAt = normalize_datetime(
        (string)($_POST['startAt'] ?? '')
    );

    $endAt = normalize_datetime(
        (string)($_POST['endAt'] ?? '')
    );

    if (
        $startAt !== null
        && $endAt !== null
        && $endAt <= $startAt
    ) {
        throw new InvalidArgumentException(
            '終了日時は開始日時より後にしてください。'
        );
    }

    if ($id === '') {

        $survey = [
            'id' => uuid(),
            'title' => $title,
            'description' => $description,
            'startAt' => $startAt,
            'endAt' => $endAt,
            'status' => 'draft',
            'numbering' => 'global',
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

        $surveys[] = $survey;

    } else {

        $found = false;

        foreach ($surveys as &$item) {

            if (
                (string)($item['id'] ?? '')
                !== $id
            ) {
                continue;
            }

            $found = true;

            $item['title'] = $title;
            $item['description'] = $description;
            $item['startAt'] = $startAt;
            $item['endAt'] = $endAt;
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

    /*
     * 保存処理自体は成功。
     * 一覧への遷移は「保存して一覧へ」の仕様なので
     * ここでは通常の画面遷移を行う。
     *
     * ただし設定画面と違い、
     * この処理も303に依存させない。
     */
    $message =
        'アンケートを保存しました。';

    $type = 'success';

    /*
     * POST後に一覧を表示したい場合は
     * JavaScriptではなく、このリクエストの描画側で
     * listを選択する方式にする。
     */
}

function handle_delete_survey(
    ?string &$message,
    ?string &$type
): void {
    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    if ($id === '') {
        throw new InvalidArgumentException(
            '削除対象が指定されていません。'
        );
    }

    $surveys = read_json(
        SURVEYS_FILE
    );

    $new = [];
    $deleted = false;

    foreach ($surveys as $item) {

        if (
            (string)($item['id'] ?? '')
            === $id
        ) {
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

    $message =
        'アンケートを削除しました。';

    $type = 'success';
}

function handle_duplicate_survey(
    ?string &$message,
    ?string &$type
): void {
    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    $survey = find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            '複製対象のアンケートが存在しません。'
        );
    }

    $surveys = read_json(
        SURVEYS_FILE
    );

    $copy = $survey;

    $copy['id'] = uuid();

    $copy['title'] =
        (string)($copy['title'] ?? '')
        . '（コピー）';

    $copy['status'] = 'draft';
    $copy['createdAt'] = now_iso();
    $copy['updatedAt'] = now_iso();

    write_json_atomic(
        SURVEYS_FILE,
        array_merge(
            $surveys,
            [$copy]
        )
    );

    $message =
        'アンケートを複製しました。';

    $type = 'success';
}

function handle_change_status(
    ?string &$message,
    ?string &$type
): void {
    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    $newStatus = trim(
        (string)($_POST['status'] ?? '')
    );

    if (
        !in_array(
            $newStatus,
            ['draft', 'published', 'stopped'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '指定された状態は変更できません。'
        );
    }

    $surveys = read_json(
        SURVEYS_FILE
    );

    foreach ($surveys as &$survey) {

        if (
            (string)($survey['id'] ?? '')
            !== $id
        ) {
            continue;
        }

        if (
            ($survey['status'] ?? '')
            === 'ended'
        ) {
            throw new RuntimeException(
                '終了したアンケートの状態は変更できません。'
            );
        }

        $current =
            (string)($survey['status'] ?? 'draft');

        $valid =
            ($current === 'draft'
                && $newStatus === 'published')
            || ($current === 'published'
                && $newStatus === 'stopped')
            || ($current === 'stopped'
                && $newStatus === 'published');

        if (!$valid) {
            throw new InvalidArgumentException(
                'この状態遷移は許可されていません。'
            );
        }

        $survey['status'] = $newStatus;
        $survey['updatedAt'] = now_iso();

        unset($survey);

        write_json_atomic(
            SURVEYS_FILE,
            $surveys
        );

        $message =
            '状態を変更しました。';

        $type = 'success';

        return;
    }

    unset($survey);

    throw new RuntimeException(
        '対象アンケートが存在しません。'
    );
}

function handle_save_questions(
    ?string &$message,
    ?string &$type
): void {
    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    $survey = find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $groupsRaw =
        $_POST['groups'] ?? [];

    if (!is_array($groupsRaw)) {
        throw new InvalidArgumentException(
            '質問データが不正です。'
        );
    }

    $groups = [];

    foreach ($groupsRaw as $groupRaw) {

        if (!is_array($groupRaw)) {
            continue;
        }

        $group = [
            'id' => trim(
                (string)($groupRaw['id'] ?? '')
            ) ?: uuid(),

            'title' => trim(
                (string)($groupRaw['title'] ?? '')
            ),

            'questions' => [],
        ];

        $questions =
            $groupRaw['questions'] ?? [];

        if (is_array($questions)) {

            foreach ($questions as $questionRaw) {

                if (!is_array($questionRaw)) {
                    continue;
                }

                $typeValue =
                    (string)($questionRaw['type'] ?? 'single');

                if (
                    !in_array(
                        $typeValue,
                        ['single', 'multiple', 'text'],
                        true
                    )
                ) {
                    $typeValue = 'single';
                }

                $options =
                    $questionRaw['options'] ?? [];

                if (!is_array($options)) {
                    $options = [];
                }

                $cleanOptions = [];

                foreach ($options as $option) {
                    $option = trim((string)$option);

                    if ($option !== '') {
                        $cleanOptions[] = $option;
                    }
                }

                $group['questions'][] = [
                    'id' => trim(
                        (string)($questionRaw['id'] ?? '')
                    ) ?: uuid(),

                    'number' => '',

                    'text' => trim(
                        (string)($questionRaw['text'] ?? '')
                    ),

                    'type' => $typeValue,

                    'required' =>
                        !empty($questionRaw['required']),

                    'options' => $cleanOptions,

                    'branch' =>
                        is_array(
                            $questionRaw['branch'] ?? null
                        )
                        ? $questionRaw['branch']
                        : [],
                ];
            }
        }

        $groups[] = $group;
    }

    normalize_questions($groups);

    $surveys = read_json(
        SURVEYS_FILE
    );

    foreach ($surveys as &$item) {

        if (
            (string)($item['id'] ?? '')
            !== $id
        ) {
            continue;
        }

        $item['groups'] = $groups;
        $item['updatedAt'] = now_iso();

        break;
    }

    unset($item);

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    $message =
        '質問を保存しました。';

    $type = 'success';
}

/* =========================================================
 * Answer
 * ========================================================= */

function handle_answer_next(
    ?string &$message,
    ?string &$type
): void {
    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    $survey = find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $_SESSION['answer'][$id] =
        $_POST['answers'] ?? [];

    $message =
        '回答内容を一時保存しました。';

    $type = 'success';
}

function handle_answer_back(
    ?string &$message,
    ?string &$type
): void {
    $message = '前の質問へ戻ります。';
    $type = 'success';
}

function handle_answer_submit(
    ?string &$message,
    ?string &$type
): void {
    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    $survey = find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $answers =
        $_POST['answers'] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    validate_required_answers(
        $survey,
        $answers
    );

    $allAnswers = read_json(
        ANSWERS_FILE
    );

    $allAnswers[] = [
        'id' => uuid(),
        'surveyId' => $id,
        'answers' => $answers,
        'createdAt' => now_iso(),
    ];

    write_json_atomic(
        ANSWERS_FILE,
        $allAnswers
    );

    unset(
        $_SESSION['answer'][$id]
    );

    $message =
        '回答を送信しました。';

    $type = 'success';
}

function validate_required_answers(
    array $survey,
    array $answers
): void {
    foreach (
        ($survey['groups'] ?? []) as $group
    ) {
        if (!is_array($group)) {
            continue;
        }

        foreach (
            ($group['questions'] ?? []) as $question
        ) {
            if (!is_array($question)) {
                continue;
            }

            if (empty($question['required'])) {
                continue;
            }

            $id =
                (string)($question['id'] ?? '');

            $value =
                $answers[$id] ?? null;

            if (is_array($value)) {
                $valid = count($value) > 0;
            } else {
                $valid =
                    trim((string)$value) !== '';
            }

            if (!$valid) {
                throw new InvalidArgumentException(
                    '必須項目が未回答です: '
                    . (string)(
                        $question['number'] ?? ''
                    )
                );
            }
        }
    }
}

/* =========================================================
 * Send
 * ========================================================= */

function handle_send_mail(
    ?string &$message,
    ?string &$type
): void {
    $surveyId = trim(
        (string)($_POST['survey_id'] ?? '')
    );

    $survey = find_survey($surveyId);

    if ($survey === null) {
        throw new RuntimeException(
            '対象アンケートが存在しません。'
        );
    }

    $customerIds =
        $_POST['customer_ids'] ?? [];

    if (!is_array($customerIds)) {
        $customerIds = [];
    }

    if (count($customerIds) === 0) {
        throw new InvalidArgumentException(
            '送信対象の顧客を選択してください。'
        );
    }

    $subject = trim(
        (string)($_POST['subject'] ?? '')
    );

    $body = (string)(
        $_POST['body'] ?? ''
    );

    if ($subject === '') {
        throw new InvalidArgumentException(
            'メール件名を入力してください。'
        );
    }

    $settings = read_json(
        SETTINGS_FILE
    );

    $mail =
        $settings['mail'] ?? [];

    validate_mail_settings($mail);

    $customers = read_json(
        CUSTOMERS_FILE
    );

    $sent = 0;

    foreach ($customers as $customer) {

        $customerId =
            (string)($customer['id'] ?? '');

        if (
            !in_array(
                $customerId,
                array_map('strval', $customerIds),
                true
            )
        ) {
            continue;
        }

        $email =
            trim((string)(
                $customer['email'] ?? ''
            ));

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            continue;
        }

        $name =
            (string)($customer['name'] ?? '');

        $personalBody =
            str_replace(
                [
                    '{顧客名}',
                    '{アンケートURL}',
                ],
                [
                    $name,
                    questionnaire_url($surveyId),
                ],
                $body
            );

        smtp_send(
            $mail,
            $email,
            $subject,
            $personalBody
        );

        $sent++;

        append_send_log([
            'id' => uuid(),
            'surveyId' => $surveyId,
            'customerId' => $customerId,
            'email' => $email,
            'type' => 'send',
            'sentAt' => now_iso(),
        ]);
    }

    $message =
        $sent . '件送信しました。';

    $type = 'success';
}

function handle_resend_mail(
    ?string &$message,
    ?string &$type
): void {
    send_log_action(
        'resend',
        $message,
        $type
    );
}

function handle_remind_mail(
    ?string &$message,
    ?string &$type
): void {
    send_log_action(
        'remind',
        $message,
        $type
    );
}

function send_log_action(
    string $action,
    ?string &$message,
    ?string &$type
): void {
    $logId = trim(
        (string)($_POST['log_id'] ?? '')
    );

    $logs = read_json(
        SEND_LOG_FILE
    );

    foreach ($logs as $log) {

        if (
            (string)($log['id'] ?? '')
            !== $logId
        ) {
            continue;
        }

        $settings = read_json(
            SETTINGS_FILE
        );

        $mail =
            $settings['mail'] ?? [];

        validate_mail_settings($mail);

        $customers =
            read_json(CUSTOMERS_FILE);

        $target = null;

        foreach ($customers as $customer) {
            if (
                (string)($customer['id'] ?? '')
                === (string)($log['customerId'] ?? '')
            ) {
                $target = $customer;
                break;
            }
        }

        if ($target === null) {
            throw new RuntimeException(
                '送信対象の顧客が存在しません。'
            );
        }

        $email =
            trim((string)(
                $target['email'] ?? ''
            ));

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new RuntimeException(
                '顧客のメールアドレスが不正です。'
            );
        }

        smtp_send(
            $mail,
            $email,
            'アンケート再送',
            'アンケートのご案内です。'
        );

        append_send_log([
            'id' => uuid(),
            'surveyId' =>
                (string)($log['surveyId'] ?? ''),
            'customerId' =>
                (string)($log['customerId'] ?? ''),
            'email' => $email,
            'type' => $action,
            'sentAt' => now_iso(),
        ]);

        $message =
            $action === 'resend'
            ? '再送しました。'
            : 'リマインドを送信しました。';

        $type = 'success';

        return;
    }

    throw new RuntimeException(
        '送信履歴が存在しません。'
    );
}

/* =========================================================
 * Rendering
 * ========================================================= */

function render_header(
    string $screen
): void {
    echo '<!doctype html>';
    echo '<html lang="ja">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>アンケートアプリ</title>';

    echo <<<'HTML'
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
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
    font-family:
      -apple-system,
      BlinkMacSystemFont,
      "Segoe UI",
      "Noto Sans JP",
      "Hiragino Kaku Gothic ProN",
      Meiryo,
      sans-serif;
    color:var(--text);
    background:#f8fafc;
}
header{
    background:#0f172a;
    color:#fff;
    padding:14px 22px;
}
header .nav{
    max-width:1400px;
    margin:auto;
    display:flex;
    gap:18px;
    flex-wrap:wrap;
    align-items:center;
}
header a{
    color:#fff;
    text-decoration:none;
}
main{
    max-width:1400px;
    margin:auto;
    padding:24px;
}
.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:20px;
    margin-bottom:20px;
    box-shadow:var(--shadow);
}
h1{margin:0 0 20px}
h2{margin-top:0}
.form-grid{
    display:grid;
    grid-template-columns:220px 1fr;
    gap:14px 20px;
    align-items:center;
}
label{font-weight:600}
input,textarea,select{
    width:100%;
    padding:10px 12px;
    border:1px solid #cbd5e1;
    border-radius:7px;
    background:#fff;
    color:inherit;
    font:inherit;
}
textarea{min-height:130px}
button,.button{
    display:inline-block;
    padding:9px 15px;
    border:0;
    border-radius:7px;
    cursor:pointer;
    text-decoration:none;
    font:inherit;
}
button.primary,.button.primary{
    background:var(--primary);
    color:#fff;
}
button.primary:hover,.button.primary:hover{
    background:var(--primary-dark);
}
button.secondary,.button.secondary{
    background:#e2e8f0;
    color:#1e293b;
}
button.success{background:var(--success);color:#fff}
button.danger{background:var(--danger);color:#fff}
button.warning{background:var(--warning);color:#fff}
.actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:20px;
}
.message{
    padding:14px 16px;
    border-radius:8px;
    margin-bottom:20px;
    font-weight:600;
}
.message.success{
    background:#dcfce7;
    color:#166534;
    border:1px solid #86efac;
}
.message.error{
    background:#fee2e2;
    color:#991b1b;
    border:1px solid #fca5a5;
}
.message.warning{
    background:#fef3c7;
    color:#92400e;
    border:1px solid #fcd34d;
}
.status{
    display:inline-block;
    padding:4px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}
.status.published{background:#dcfce7;color:#166534}
.status.draft{background:#e2e8f0;color:#475569}
.status.stopped{background:#fef3c7;color:#92400e}
.status.ended{background:#fee2e2;color:#991b1b}
.table-wrap{
    overflow-x:auto;
}
table{
    width:100%;
    border-collapse:collapse;
    min-width:900px;
}
th,td{
    padding:11px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:top;
}
th{background:#f8fafc}
.small{font-size:13px;color:var(--gray)}
.grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:20px;
}
.field-list{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}
.field-chip{
    padding:6px 10px;
    border:1px solid var(--border);
    border-radius:999px;
    background:#f8fafc;
}
.mapping{
    display:grid;
    grid-template-columns:180px 1fr;
    gap:10px;
    align-items:center;
}
.question-card{
    border:1px solid var(--border);
    border-radius:8px;
    padding:15px;
    margin-bottom:12px;
}
@media(max-width:800px){
    main{padding:14px}
    .form-grid{
        grid-template-columns:1fr;
    }
    .grid{
        grid-template-columns:1fr;
    }
    .mapping{
        grid-template-columns:1fr;
    }
}
</style>
HTML;

    echo '</head>';
    echo '<body>';

    echo '<header>';
    echo '<div class="nav">';
    echo '<strong>アンケートアプリ</strong>';
    echo '<a href="index.php?screen=list">アンケート一覧</a>';
    echo '<a href="index.php?screen=kintone">kintone設定</a>';
    echo '<a href="index.php?screen=mail">メール設定</a>';
    echo '</div>';
    echo '</header>';

    echo '<main>';
}

function render_footer(): void
{
    echo '</main>';

    echo <<<'HTML'
<script>
document.addEventListener('submit',function(e){
    const form=e.target;
    const submitter=e.submitter;

    if(!submitter)return;

    if(
        submitter.dataset.confirm &&
        !window.confirm(submitter.dataset.confirm)
    ){
        e.preventDefault();
        return;
    }

    if(submitter.dataset.busy==='1'){
        return;
    }

    if(
        submitter.dataset.loading==='1'
    ){
        submitter.dataset.busy='1';
        submitter.disabled=true;
        submitter.textContent='処理中...';
    }
});
</script>
HTML;

    echo '</body></html>';
}

function render_message(
    ?string $type,
    string $message
): void {
    $class =
        in_array(
            $type,
            ['success', 'error', 'warning'],
            true
        )
        ? $type
        : 'error';

    echo '<div class="message '
        . h($class)
        . '">'
        . h($message)
        . '</div>';
}

/* =========================================================
 * kintone画面
 * ========================================================= */

function render_kintone(): void
{
    $settings = read_json(
        SETTINGS_FILE
    );

    $k = $settings['kintone']
        ?? default_settings()['kintone'];

    $fields =
        $k['fields'] ?? [];

    echo '<h1>kintone連携設定</h1>';

    echo '<div class="card">';

    echo '<form method="post" '
        . 'action="index.php?screen=kintone">';

    /*
     * actionは必ず各フォーム自身に持たせる。
     * CSRF hidden fieldは存在しない。
     */
    echo '<input type="hidden" '
        . 'name="action" '
        . 'value="save_kintone">';

    echo '<div class="form-grid">';

    form_row(
        'サブドメイン',
        '<input name="subdomain" value="'
        . h((string)$k['subdomain'])
        . '" placeholder="xxxx.cybozu.com">'
    );

    form_row(
        '顧客管理アプリID',
        '<input name="app_id" value="'
        . h((string)$k['app_id'])
        . '" inputmode="numeric">'
    );

    form_row(
        'ログイン名',
        '<input name="username" value="'
        . h((string)$k['username'])
        . '">'
    );

    form_row(
        'パスワード',
        '<input type="password" name="password" '
        . 'value="" autocomplete="new-password" '
        . 'placeholder="変更しない場合は空欄">'
    );

    form_row(
        'Proxy',
        '<input name="proxy" value="'
        . h((string)$k['proxy'])
        . '" placeholder="host:port">'
    );

    form_row(
        'SSL証明書検証',
        '<label>'
        . '<input type="checkbox" name="verify_ssl" value="1" '
        . ($k['verify_ssl'] ? 'checked' : '')
        . '> 有効'
        . '</label>'
    );

    echo '</div>';

    echo '<div class="actions">';

    echo '<button '
        . 'type="submit" '
        . 'class="primary">'
        . '設定保存'
        . '</button>';

    echo '</div>';

    echo '</form>';

    echo '</div>';

    echo '<div class="card">';

    echo '<h2>接続・同期</h2>';

    echo '<p class="small">'
        . '設定保存、接続テスト、項目一覧取得、'
        . '顧客情報同期は別操作です。'
        . '</p>';

    echo '<div class="actions">';

    post_button(
        'test_kintone',
        '接続テスト',
        'primary',
        true
    );

    post_button(
        'fetch_kintone_fields',
        '項目一覧を再取得',
        'secondary',
        true
    );

    post_button(
        'sync_kintone',
        '顧客情報を同期',
        'success',
        true
    );

    echo '</div>';

    echo '<p>';
    echo '接続状態: ';
    echo '<span class="status '
        . status_class(
            (string)($k['connection_status'] ?? '')
        )
        . '">'
        . h((string)(
            $k['connection_status'] ?? '未設定'
        ))
        . '</span>';
    echo '</p>';

    if (!empty($k['last_test_at'])) {
        echo '<p class="small">'
            . '最終確認: '
            . h((string)$k['last_test_at'])
            . '</p>';
    }

    echo '</div>';

    echo '<div class="card">';

    echo '<h2>項目マッピング</h2>';

    echo '<form method="post" '
        . 'action="index.php?screen=kintone">';

    echo '<input type="hidden" '
        . 'name="action" '
        . 'value="save_kintone">';

    echo '<div class="mapping">';

    $map = $k['field_mapping'] ?? [];

    mapping_select(
        '組織名',
        'organization',
        $map,
        $fields
    );

    mapping_select(
        '氏名',
        'name',
        $map,
        $fields
    );

    mapping_select(
        'メールアドレス',
        'email',
        $map,
        $fields
    );

    mapping_select(
        '部署名',
        'department',
        $map,
        $fields
    );

    mapping_select(
        '電話番号',
        'phone',
        $map,
        $fields
    );

    echo '</div>';

    echo '<p><strong>住所</strong></p>';

    $addresses =
        is_array($map['address'] ?? null)
        ? $map['address']
        : [];

    foreach ($fields as $field) {

        $code =
            (string)($field['code'] ?? '');

        $label =
            (string)($field['label'] ?? $code);

        echo '<label style="display:block;margin:6px 0">';

        echo '<input type="checkbox" '
            . 'name="field_mapping[address][]" '
            . 'value="'
            . h($code)
            . '" '
            . (
                in_array(
                    $code,
                    $addresses,
                    true
                )
                ? 'checked'
                : ''
            )
            . '>';

        echo h($label)
            . ' ('
            . h($code)
            . ')';

        echo '</label>';
    }

    echo '<div class="actions">';
    echo '<button type="submit" '
        . 'class="primary">設定保存</button>';
    echo '</div>';

    echo '</form>';

    echo '</div>';
}

/* =========================================================
 * Mail画面
 * ========================================================= */

function render_mail(): void
{
    $settings = read_json(
        SETTINGS_FILE
    );

    $m = $settings['mail']
        ?? default_settings()['mail'];

    echo '<h1>メールサーバ設定</h1>';

    echo '<div class="card">';

    echo '<form method="post" '
        . 'action="index.php?screen=mail">';

    echo '<input type="hidden" '
        . 'name="action" '
        . 'value="save_mail">';

    echo '<div class="form-grid">';

    form_row(
        'SMTPサーバ',
        '<input name="host" value="'
        . h((string)$m['host'])
        . '">'
    );

    form_row(
        'SMTPポート',
        '<input name="port" value="'
        . h((string)$m['port'])
        . '" inputmode="numeric">'
    );

    form_row(
        '暗号化方式',
        '<select name="encryption">'
        . option(
            'ssl',
            'SSL',
            (string)$m['encryption']
        )
        . option(
            'tls',
            'TLS',
            (string)$m['encryption']
        )
        . option(
            'none',
            'なし',
            (string)$m['encryption']
        )
        . '</select>'
    );

    form_row(
        'SMTP認証',
        '<label>'
        . '<input type="checkbox" name="auth" value="1" '
        . ($m['auth'] ? 'checked' : '')
        . '> 使用する'
        . '</label>'
    );

    form_row(
        'SMTPユーザー名',
        '<input name="username" value="'
        . h((string)$m['username'])
        . '">'
    );

    form_row(
        'SMTPパスワード',
        '<input type="password" name="password" '
        . 'value="" autocomplete="new-password" '
        . 'placeholder="変更しない場合は空欄">'
    );

    form_row(
        '送信元メールアドレス',
        '<input name="from_email" value="'
        . h((string)$m['from_email'])
        . '" type="email">'
    );

    form_row(
        '送信元名',
        '<input name="from_name" value="'
        . h((string)$m['from_name'])
        . '">'
    );

    form_row(
        '返信先メールアドレス',
        '<input name="reply_to" value="'
        . h((string)$m['reply_to'])
        . '" type="email">'
    );

    echo '</div>';

    echo '<div class="actions">';

    echo '<button type="submit" '
        . 'class="primary">'
        . '設定保存'
        . '</button>';

    echo '</div>';

    echo '</form>';

    echo '</div>';

    echo '<div class="card">';

    echo '<h2>接続テスト</h2>';

    echo '<p>';
    echo '接続状態: ';
    echo '<span class="status '
        . status_class(
            (string)($m['connection_status'] ?? '')
        )
        . '">'
        . h((string)(
            $m['connection_status'] ?? '未設定'
        ))
        . '</span>';
    echo '</p>';

    echo '<div class="actions">';

    post_button(
        'test_mail',
        '接続テスト',
        'primary',
        true
    );

    echo '</div>';

    echo '</div>';

    echo '<div class="card">';

    echo '<h2>テストメール送信</h2>';

    echo '<form method="post" '
        . 'action="index.php?screen=mail">';

    echo '<input type="hidden" '
        . 'name="action" '
        . 'value="send_test_mail">';

    echo '<div class="form-grid">';

    form_row(
        '送信先',
        '<input type="email" '
        . 'name="test_to" required>'
    );

    echo '</div>';

    echo '<div class="actions">';

    echo '<button type="submit" '
        . 'class="success" '
        . 'data-loading="1">'
        . 'テストメール送信'
        . '</button>';

    echo '</div>';

    echo '</form>';

    echo '</div>';
}

/* =========================================================
 * List
 * ========================================================= */

function render_list(): void
{
    $surveys = read_json(
        SURVEYS_FILE
    );

    $q = trim(
        (string)($_GET['q'] ?? '')
    );

    $filter =
        (string)($_GET['filter'] ?? 'all');

    $sort =
        (string)($_GET['sort'] ?? 'updated_desc');

    $filtered = [];

    foreach ($surveys as $survey) {

        $title =
            (string)($survey['title'] ?? '');

        if (
            $q !== ''
            && mb_stripos(
                $title,
                $q
            ) === false
        ) {
            continue;
        }

        $status =
            (string)($survey['status'] ?? 'draft');

        if (
            $filter !== 'all'
            && $status !== $filter
        ) {
            continue;
        }

        $filtered[] = $survey;
    }

    usort(
        $filtered,
        function(array $a, array $b) use ($sort): int {

            if ($sort === 'answers_desc') {
                return answer_count_for_survey(
                    (string)$b['id']
                ) <=> answer_count_for_survey(
                    (string)$a['id']
                );
            }

            if ($sort === 'answers_asc') {
                return answer_count_for_survey(
                    (string)$a['id']
                ) <=> answer_count_for_survey(
                    (string)$b['id']
                );
            }

            $field =
                str_starts_with($sort, 'start_')
                ? 'startAt'
                : 'updatedAt';

            $av =
                strtotime(
                    (string)($a[$field] ?? '')
                ) ?: 0;

            $bv =
                strtotime(
                    (string)($b[$field] ?? '')
                ) ?: 0;

            return str_ends_with($sort, '_asc')
                ? $av <=> $bv
                : $bv <=> $av;
        }
    );

    echo '<h1>アンケート一覧</h1>';

    echo '<div class="card">';

    echo '<form method="get">';

    echo '<input type="hidden" '
        . 'name="screen" value="list">';

    echo '<div class="form-grid">';

    form_row(
        '検索',
        '<input name="q" value="'
        . h($q)
        . '" placeholder="タイトルで検索">'
    );

    form_row(
        '絞り込み',
        '<select name="filter">'
        . option('all','すべて',$filter)
        . option('published','公開中',$filter)
        . option('draft','下書き',$filter)
        . option('stopped','停止',$filter)
        . option('ended','終了',$filter)
        . '</select>'
    );

    form_row(
        'ソート',
        '<select name="sort">'
        . option(
            'updated_desc',
            '更新日：新しい順',
            $sort
        )
        . option(
            'updated_asc',
            '更新日：古い順',
            $sort
        )
        . option(
            'answers_desc',
            '回答数：多い順',
            $sort
        )
        . option(
            'answers_asc',
            '回答数：少ない順',
            $sort
        )
        . option(
            'start_desc',
            '開始日：新しい順',
            $sort
        )
        . option(
            'start_asc',
            '開始日：古い順',
            $sort
        )
        . '</select>'
    );

    echo '</div>';

    echo '<div class="actions">';

    echo '<button class="primary">検索</button>';

    echo '<a class="button secondary" '
        . 'href="index.php?screen=list">'
        . '条件クリア'
        . '</a>';

    echo '<a class="button primary" '
        . 'href="index.php?screen=edit">'
        . '新規作成'
        . '</a>';

    echo '</div>';

    echo '</form>';

    echo '</div>';

    echo '<div class="card table-wrap">';

    echo '<table>';

    echo '<thead><tr>';
    echo '<th>タイトル</th>';
    echo '<th>作成日</th>';
    echo '<th>更新日</th>';
    echo '<th>期間</th>';
    echo '<th>状態</th>';
    echo '<th>回答数</th>';
    echo '<th>操作</th>';
    echo '</tr></thead>';

    echo '<tbody>';

    if (!$filtered) {

        echo '<tr><td colspan="7">'
            . 'アンケートがありません。'
            . '</td></tr>';

    } else {

        foreach ($filtered as $survey) {

            $id =
                (string)($survey['id'] ?? '');

            $status =
                (string)($survey['status'] ?? 'draft');

            echo '<tr>';

            echo '<td>'
                . h((string)(
                    $survey['title'] ?? ''
                ))
                . '</td>';

            echo '<td>'
                . h((string)(
                    $survey['createdAt'] ?? ''
                ))
                . '</td>';

            echo '<td>'
                . h((string)(
                    $survey['updatedAt'] ?? ''
                ))
                . '</td>';

            echo '<td>'
                . h((string)(
                    $survey['startAt'] ?? ''
                ))
                . '<br>'
                . h((string)(
                    $survey['endAt'] ?? ''
                ))
                . '</td>';

            echo '<td>'
                . '<span class="status '
                . status_class($status)
                . '">'
                . h(status_label($status))
                . '</span>'
                . '</td>';

            echo '<td>'
                . answer_count_for_survey($id)
                . '</td>';

            echo '<td>';

            echo '<div class="actions">';

            echo '<a class="button secondary" '
                . 'href="index.php?screen=edit&id='
                . rawurlencode($id)
                . '">確認・編集</a>';

            echo '<a class="button secondary" '
                . 'href="index.php?screen=analytics&id='
                . rawurlencode($id)
                . '">集計</a>';

            echo '<a class="button primary" '
                . 'href="index.php?screen=send&id='
                . rawurlencode($id)
                . '">送信</a>';

            echo '</div>';

            echo '</td>';

            echo '</tr>';
        }
    }

    echo '</tbody>';
    echo '</table>';

    echo '</div>';
}

/* =========================================================
 * Edit
 * ========================================================= */

function render_edit(
    ?array $survey
): void {
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
            'groups' => [
                [
                    'id' => uuid(),
                    'title' => 'グループ1',
                    'questions' => [],
                ],
            ],
        ];
    }

    $id =
        (string)($survey['id'] ?? '');

    echo '<h1>'
        . ($isNew
            ? 'アンケート作成'
            : 'アンケート編集')
        . '</h1>';

    echo '<div class="card">';

    echo '<form method="post" '
        . 'action="index.php?screen=edit'
        . ($id !== ''
            ? '&id=' . rawurlencode($id)
            : '')
        . '">';

    echo '<input type="hidden" '
        . 'name="action" '
        . 'value="save_survey">';

    echo '<input type="hidden" '
        . 'name="id" value="'
        . h($id)
        . '">';

    echo '<div class="form-grid">';

    form_row(
        'アンケートタイトル',
        '<input name="title" required value="'
        . h((string)$survey['title'])
        . '">'
    );

    form_row(
        'アンケート説明',
        '<textarea name="description">'
        . h((string)$survey['description'])
        . '</textarea>'
    );

    form_row(
        '開始日時',
        '<input type="datetime-local" '
        . 'name="startAt" value="'
        . h(datetime_local(
            (string)$survey['startAt']
        ))
        . '">'
    );

    form_row(
        '終了日時',
        '<input type="datetime-local" '
        . 'name="endAt" value="'
        . h(datetime_local(
            (string)$survey['endAt']
        ))
        . '">'
    );

    echo '</div>';

    echo '<div class="actions">';

    echo '<a class="button secondary" '
        . 'href="index.php?screen=list">'
        . 'キャンセル'
        . '</a>';

    echo '<button type="submit" '
        . 'class="primary">'
        . '保存して一覧へ'
        . '</button>';

    echo '</div>';

    echo '</form>';

    echo '</div>';

    echo '<div class="card">';

    echo '<h2>質問・グループ</h2>';

    foreach (
        ($survey['groups'] ?? []) as $group
    ) {
        echo '<div class="question-card">';

        echo '<h3>'
            . h((string)(
                $group['title'] ?? ''
            ))
            . '</h3>';

        foreach (
            ($group['questions'] ?? []) as $question
        ) {
            echo '<p>';
            echo '<strong>'
                . h((string)(
                    $question['number'] ?? ''
                ))
                . '</strong> ';
            echo h((string)(
                $question['text'] ?? ''
            ));
            echo '</p>';
        }

        echo '</div>';
    }

    echo '</div>';
}

/* =========================================================
 * Preview
 * ========================================================= */

function render_preview(
    ?array $survey
): void {
    if ($survey === null) {
        render_message(
            'error',
            '対象アンケートが存在しません。'
        );
        return;
    }

    echo '<h1>プレビュー</h1>';

    echo '<div class="card">';

    echo '<h2>'
        . h((string)$survey['title'])
        . '</h2>';

    echo '<p>'
        . nl2br(
            h((string)(
                $survey['description'] ?? ''
            ))
        )
        . '</p>';

    foreach (
        ($survey['groups'] ?? []) as $group
    ) {

        echo '<h3>'
            . h((string)(
                $group['title'] ?? ''
            ))
            . '</h3>';

        foreach (
            ($group['questions'] ?? []) as $question
        ) {
            render_question_preview(
                $question
            );
        }
    }

    echo '</div>';
}

function render_question_preview(
    array $question
): void {
    echo '<div class="question-card">';

    echo '<strong>'
        . h((string)(
            $question['number'] ?? ''
        ))
        . '</strong> ';

    echo h((string)(
        $question['text'] ?? ''
    ));

    if (!empty($question['required'])) {
        echo ' <span class="status published">'
            . '必須'
            . '</span>';
    }

    echo '<p class="small">';

    echo '回答形式: '
        . h(question_type_label(
            (string)(
                $question['type'] ?? ''
            )
        ));

    echo '</p>';

    echo '</div>';
}

/* =========================================================
 * Send画面
 * ========================================================= */

function render_send(
    ?array $survey
): void {
    if ($survey === null) {
        render_message(
            'error',
            '対象アンケートが存在しません。'
        );
        return;
    }

    $customers = read_json(
        CUSTOMERS_FILE
    );

    echo '<h1>顧客選択・メール送信</h1>';

    echo '<div class="card">';

    echo '<h2>対象アンケート</h2>';

    echo '<p><strong>'
        . h((string)$survey['title'])
        . '</strong></p>';

    echo '</div>';

    echo '<div class="card">';

    echo '<form method="post" '
        . 'action="index.php?screen=send&id='
        . rawurlencode(
            (string)$survey['id']
        )
        . '">';

    echo '<input type="hidden" '
        . 'name="action" value="send_mail">';

    echo '<input type="hidden" '
        . 'name="survey_id" value="'
        . h((string)$survey['id'])
        . '">';

    echo '<h2>顧客選択</h2>';

    if (!$customers) {

        echo '<p>'
            . '顧客情報がありません。'
            . 'kintone設定画面から同期してください。'
            . '</p>';

    } else {

        foreach ($customers as $customer) {

            $id =
                (string)($customer['id'] ?? '');

            echo '<label style="display:block;margin:8px 0">';

            echo '<input type="checkbox" '
                . 'name="customer_ids[]" '
                . 'value="'
                . h($id)
                . '"> ';

            echo h((string)(
                $customer['name'] ?? ''
            ));

            echo ' / ';

            echo h((string)(
                $customer['email'] ?? ''
            ));

            echo '</label>';
        }
    }

    echo '<h2>メール内容</h2>';

    echo '<div class="form-grid">';

    form_row(
        '件名',
        '<input name="subject" required '
        . 'value="アンケートのご案内">'
    );

    form_row(
        '本文',
        '<textarea name="body" required>'
        . h(
            "{顧客名} 様\r\n\r\n"
            . "アンケートへのご回答をお願いします。\r\n"
            . "{アンケートURL}"
        )
        . '</textarea>'
    );

    echo '</div>';

    echo '<div class="actions">';

    echo '<button type="submit" '
        . 'class="primary" '
        . 'data-loading="1" '
        . 'data-confirm="選択した顧客へ一括送信します。よろしいですか？">'
        . '一括送信'
        . '</button>';

    echo '</div>';

    echo '</form>';

    echo '</div>';

    render_send_history(
        (string)$survey['id']
    );
}

function render_send_history(
    string $surveyId
): void {
    $logs = read_json(
        SEND_LOG_FILE
    );

    echo '<div class="card">';

    echo '<h2>送信履歴</h2>';

    echo '<div class="table-wrap">';

    echo '<table>';

    echo '<thead><tr>';
    echo '<th>日時</th>';
    echo '<th>メール</th>';
    echo '<th>種別</th>';
    echo '<th>操作</th>';
    echo '</tr></thead>';

    echo '<tbody>';

    foreach ($logs as $log) {

        if (
            (string)($log['surveyId'] ?? '')
            !== $surveyId
        ) {
            continue;
        }

        echo '<tr>';

        echo '<td>'
            . h((string)(
                $log['sentAt'] ?? ''
            ))
            . '</td>';

        echo '<td>'
            . h((string)(
                $log['email'] ?? ''
            ))
            . '</td>';

        echo '<td>'
            . h((string)(
                $log['type'] ?? ''
            ))
            . '</td>';

        echo '<td>';

        echo '<form method="post" '
            . 'style="display:inline">';

        echo '<input type="hidden" '
            . 'name="action" value="resend_mail">';

        echo '<input type="hidden" '
            . 'name="log_id" value="'
            . h((string)$log['id'])
            . '">';

        echo '<button class="secondary">'
            . '再送'
            . '</button>';

        echo '</form>';

        echo '</td>';

        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    echo '</div>';

    echo '</div>';
}

/* =========================================================
 * Analytics
 * ========================================================= */

function render_analytics(
    ?array $survey
): void {
    if ($survey === null) {
        render_message(
            'error',
            '対象アンケートが存在しません。'
        );
        return;
    }

    $answers = read_json(
        ANSWERS_FILE
    );

    $surveyAnswers = [];

    foreach ($answers as $answer) {
        if (
            (string)($answer['surveyId'] ?? '')
            === (string)$survey['id']
        ) {
            $surveyAnswers[] = $answer;
        }
    }

    echo '<h1>回答集計・分析</h1>';

    echo '<div class="grid">';

    metric_card(
        '対象アンケート',
        (string)$survey['title']
    );

    metric_card(
        '回答数',
        (string)count($surveyAnswers)
    );

    metric_card(
        '送信対象者数',
        (string)count_customers_for_survey(
            (string)$survey['id']
        )
    );

    metric_card(
        '未回答数',
        (string)max(
            0,
            count_customers_for_survey(
                (string)$survey['id']
            ) - count($surveyAnswers)
        )
    );

    echo '</div>';

    echo '<div class="card">';

    if (!$surveyAnswers) {

        echo '<p>'
            . '現在、回答データはありません'
            . '</p>';

    } else {

        echo '<p>'
            . '回答データ '
            . count($surveyAnswers)
            . '件'
            . '</p>';
    }

    echo '</div>';
}

/* =========================================================
 * Answer screens
 * ========================================================= */

function render_answer(
    ?array $survey
): void {
    if ($survey === null) {
        render_message(
            'error',
            '対象アンケートが存在しません。'
        );
        return;
    }

    echo '<h1>アンケート回答</h1>';

    echo '<div class="card">';

    echo '<h2>'
        . h((string)$survey['title'])
        . '</h2>';

    echo '<p>'
        . nl2br(
            h((string)(
                $survey['description'] ?? ''
            ))
        )
        . '</p>';

    echo '<form method="post" '
        . 'action="index.php?screen=answer&id='
        . rawurlencode(
            (string)$survey['id']
        )
        . '">';

    echo '<input type="hidden" '
        . 'name="action" value="answer_submit">';

    echo '<input type="hidden" '
        . 'name="id" value="'
        . h((string)$survey['id'])
        . '">';

    foreach (
        ($survey['groups'] ?? []) as $group
    ) {

        echo '<h3>'
            . h((string)(
                $group['title'] ?? ''
            ))
            . '</h3>';

        foreach (
            ($group['questions'] ?? []) as $question
        ) {
            render_answer_question(
                $question
            );
        }
    }

    echo '<div class="actions">';

    echo '<button type="submit" '
        . 'class="primary">'
        . '回答を送信'
        . '</button>';

    echo '</div>';

    echo '</form>';

    echo '</div>';
}

function render_answer_question(
    array $question
): void {
    $id =
        (string)($question['id'] ?? '');

    echo '<div class="question-card">';

    echo '<label>';

    echo h((string)(
        $question['number'] ?? ''
    ));

    echo ' ';

    echo h((string)(
        $question['text'] ?? ''
    ));

    if (!empty($question['required'])) {
        echo ' <span class="status published">'
            . '必須'
            . '</span>';
    }

    echo '</label>';

    $type =
        (string)($question['type'] ?? 'text');

    if ($type === 'text') {

        echo '<textarea name="answers['
            . h($id)
            . ']"></textarea>';

    } elseif ($type === 'multiple') {

        foreach (
            ($question['options'] ?? []) as $option
        ) {
            echo '<label style="display:block;margin:7px 0">';

            echo '<input type="checkbox" '
                . 'name="answers['
                . h($id)
                . '][]" value="'
                . h((string)$option)
                . '"> ';

            echo h((string)$option);

            echo '</label>';
        }

    } else {

        foreach (
            ($question['options'] ?? []) as $option
        ) {
            echo '<label style="display:block;margin:7px 0">';

            echo '<input type="radio" '
                . 'name="answers['
                . h($id)
                . ']" value="'
                . h((string)$option)
                . '"> ';

            echo h((string)$option);

            echo '</label>';
        }
    }

    echo '</div>';
}

function render_confirm(
    ?array $survey
): void {
    echo '<h1>回答確認</h1>';

    echo '<div class="card">';

    if ($survey === null) {
        echo '<p>対象アンケートがありません。</p>';
    } else {
        echo '<p>回答内容を確認してください。</p>';
        echo '<a class="button primary" '
            . 'href="index.php?screen=answer&id='
            . rawurlencode(
                (string)$survey['id']
            )
            . '">回答画面へ戻る</a>';
    }

    echo '</div>';
}

function render_complete(
    ?array $survey
): void {
    echo '<h1>回答完了</h1>';

    echo '<div class="card">';

    echo '<p>'
        . '回答ありがとうございました。'
        . '</p>';

    echo '</div>';
}

/* =========================================================
 * Utility rendering
 * ========================================================= */

function form_row(
    string $label,
    string $html
): void {
    echo '<label>'
        . h($label)
        . '</label>';

    echo '<div>'
        . $html
        . '</div>';
}

function post_button(
    string $action,
    string $label,
    string $class,
    bool $loading = false
): void {
    echo '<form method="post" '
        . 'style="display:inline">';

    echo '<input type="hidden" '
        . 'name="action" value="'
        . h($action)
        . '">';

    echo '<button type="submit" '
        . 'class="'
        . h($class)
        . '"'
        . (
            $loading
            ? ' data-loading="1"'
            : ''
        )
        . '>'
        . h($label)
        . '</button>';

    echo '</form>';
}

function mapping_select(
    string $label,
    string $name,
    array $mapping,
    array $fields
): void {
    echo '<label>'
        . h($label)
        . '</label>';

    echo '<select name="field_mapping['
        . h($name)
        . ']">';

    echo '<option value="">未設定</option>';

    foreach ($fields as $field) {

        $code =
            (string)($field['code'] ?? '');

        $fieldLabel =
            (string)($field['label'] ?? $code);

        $selected =
            (string)($mapping[$name] ?? '')
            === $code
            ? ' selected'
            : '';

        echo '<option value="'
            . h($code)
            . '"'
            . $selected
            . '>'
            . h($fieldLabel)
            . ' ('
            . h($code)
            . ')'
            . '</option>';
    }

    echo '</select>';
}

function metric_card(
    string $label,
    string $value
): void {
    echo '<div class="card">';

    echo '<div class="small">'
        . h($label)
        . '</div>';

    echo '<div style="font-size:28px;font-weight:700">'
        . h($value)
        . '</div>';

    echo '</div>';
}

/* =========================================================
 * Storage
 * ========================================================= */

function init_json_file(
    string $file,
    array $default
): void {
    if (file_exists($file)) {
        return;
    }

    write_json_atomic(
        $file,
        $default
    );
}

function read_json(
    string $file
): array {
    if (!file_exists($file)) {
        return [];
    }

    $contents =
        file_get_contents($file);

    if (
        $contents === false
        || trim($contents) === ''
    ) {
        return [];
    }

    $data =
        json_decode(
            $contents,
            true
        );

    return is_array($data)
        ? $data
        : [];
}

function write_json_atomic(
    string $file,
    array $data
): void {
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException(
            'JSONデータを生成できませんでした。'
        );
    }

    $directory =
        dirname($file);

    $tmp = tempnam(
        $directory,
        'poc_'
    );

    if ($tmp === false) {
        throw new RuntimeException(
            '一時ファイルを作成できませんでした。'
        );
    }

    if (
        file_put_contents(
            $tmp,
            $json,
            LOCK_EX
        ) === false
    ) {
        @unlink($tmp);

        throw new RuntimeException(
            'データを書き込めませんでした。'
        );
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);

        throw new RuntimeException(
            'データファイルを更新できませんでした。'
        );
    }
}

/* =========================================================
 * Survey utility
 * ========================================================= */

function find_survey(
    string $id
): ?array {
    if ($id === '') {
        return null;
    }

    $surveys = read_json(
        SURVEYS_FILE
    );

    foreach ($surveys as $survey) {

        if (
            (string)($survey['id'] ?? '')
            === $id
        ) {
            return $survey;
        }
    }

    return null;
}

function update_ended_surveys(): void
{
    $surveys = read_json(
        SURVEYS_FILE
    );

    $changed = false;

    foreach ($surveys as &$survey) {

        if (
            ($survey['status'] ?? '')
            !== 'published'
        ) {
            continue;
        }

        $endAt =
            (string)($survey['endAt'] ?? '');

        if ($endAt === '') {
            continue;
        }

        $timestamp =
            strtotime($endAt);

        if (
            $timestamp !== false
            && $timestamp < time()
        ) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] =
                now_iso();

            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {
        write_json_atomic(
            SURVEYS_FILE,
            $surveys
        );
    }
}

function normalize_questions(
    array &$groups
): void {
    $globalNumber = 1;

    foreach ($groups as &$group) {

        if (
            empty($group['id'])
        ) {
            $group['id'] = uuid();
        }

        if (
            !isset($group['questions'])
            || !is_array($group['questions'])
        ) {
            $group['questions'] = [];
        }

        foreach (
            $group['questions'] as &$question
        ) {

            if (
                empty($question['id'])
            ) {
                $question['id'] = uuid();
            }

            $question['number'] =
                'Q' . $globalNumber;

            $globalNumber++;
        }

        unset($question);
    }

    unset($group);
}

function answer_count_for_survey(
    string $surveyId
): int {
    $answers = read_json(
        ANSWERS_FILE
    );

    $count = 0;

    foreach ($answers as $answer) {
        if (
            (string)($answer['surveyId'] ?? '')
            === $surveyId
        ) {
            $count++;
        }
    }

    return $count;
}

function count_customers_for_survey(
    string $surveyId
): int {
    /*
     * POCでは送信履歴を対象者数として扱う。
     */
    $logs = read_json(
        SEND_LOG_FILE
    );

    $ids = [];

    foreach ($logs as $log) {
        if (
            (string)($log['surveyId'] ?? '')
            !== $surveyId
        ) {
            continue;
        }

        $customerId =
            (string)($log['customerId'] ?? '');

        if ($customerId !== '') {
            $ids[$customerId] = true;
        }
    }

    return count($ids);
}

function append_send_log(
    array $record
): void {
    $logs = read_json(
        SEND_LOG_FILE
    );

    $logs[] = $record;

    write_json_atomic(
        SEND_LOG_FILE,
        $logs
    );
}

/* =========================================================
 * Misc
 * ========================================================= */

function uuid(): string
{
    return bin2hex(
        random_bytes(16)
    );
}

function now_iso(): string
{
    return date(
        'c'
    );
}

function h(
    string $value
): string {
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function safe_error_message(
    Throwable $e
): string {
    $message = trim(
        $e->getMessage()
    );

    if ($message === '') {
        return '';
    }

    /*
     * パスワード・Authorization等が
     * エラーメッセージへ混入している場合は除去。
     */
    $message = preg_replace(
        '/X-Cybozu-Authorization\s*:\s*\S+/i',
        'X-Cybozu-Authorization: [非表示]',
        $message
    ) ?? $message;

    return $message;
}

function status_class(
    string $status
): string {
    return match ($status) {
        'published',
        '接続確認済み'
            => 'published',

        'stopped',
        '接続できません'
            => 'stopped',

        'ended'
            => 'ended',

        default
            => 'draft',
    };
}

function status_label(
    string $status
): string {
    return match ($status) {
        'published' => '公開中',
        'draft' => '下書き',
        'stopped' => '停止',
        'ended' => '終了',
        default => $status !== ''
            ? $status
            : '下書き',
    };
}

function question_type_label(
    string $type
): string {
    return match ($type) {
        'single' => '単一選択',
        'multiple' => '複数選択',
        'text' => '自由記述',
        default => '自由記述',
    };
}

function option(
    string $value,
    string $label,
    string $selected
): string {
    return '<option value="'
        . h($value)
        . '"'
        . (
            $value === $selected
            ? ' selected'
            : ''
        )
        . '>'
        . h($label)
        . '</option>';
}

function normalize_datetime(
    string $value
): ?string {
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    $timestamp =
        strtotime($value);

    if ($timestamp === false) {
        throw new InvalidArgumentException(
            '日時形式が不正です。'
        );
    }

    return date(
        'c',
        $timestamp
    );
}

function datetime_local(
    string $value
): string {
    if ($value === '') {
        return '';
    }

    $timestamp =
        strtotime($value);

    if ($timestamp === false) {
        return '';
    }

    return date(
        'Y-m-d\TH:i',
        $timestamp
    );
}

function app_cookie_path(): string
{
    $script =
        (string)($_SERVER['SCRIPT_NAME'] ?? '');

    $dir =
        str_replace(
            '\\',
            '/',
            dirname($script)
        );

    if ($dir === '.' || $dir === '/') {
        return '/';
    }

    return rtrim(
        $dir,
        '/'
    ) . '/';
}

function questionnaire_url(
    string $surveyId
): string {
    $scheme =
        (!empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    $host =
        (string)($_SERVER['HTTP_HOST'] ?? '');

    return $scheme
        . '://'
        . $host
        . '/index.php?screen=answer&id='
        . rawurlencode($surveyId);
}
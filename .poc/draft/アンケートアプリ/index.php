<?php
declare(strict_types=1);

/**
 * アンケートアプリ POC
 *
 * 要件:
 * - index.php 単一エントリーポイント
 * - DB不使用
 * - ファイル(JSON)永続化
 * - 管理者認証なし
 * - CSRF対策なし（POC要件）
 * - PHP cURL不使用
 * - PHP mail()不使用
 * - kintone: ログイン名 + パスワード
 * - kintone認証: X-Cybozu-Authorization
 * - kintone REST API: /k/v1/*
 * - SMTP: PHPソケットによる直接接続
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
 * ========================================================= */

if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        die('データディレクトリを作成できません。');
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
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
        'fields' => [],
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
 * 共通HTTP
 * ========================================================= */

$screen = trim((string)($_GET['screen'] ?? 'list'));
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
 * POST処理
 *
 * CSRFトークンは使用しない。
 * POC要件により管理者認証も行わない。
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        switch ($action) {

            /* -------------------------
             * kintone
             * ------------------------- */

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
             * ------------------------- */

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
             * ------------------------- */

            case 'save_survey':
                handle_save_survey();
                break;

            case 'save_questions':
                handle_save_questions();
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

            /* -------------------------
             * answer
             * ------------------------- */

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
             * ------------------------- */

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
        flash('error', '処理に失敗しました。' . safe_error_message($e));
        redirect(screen_url($screen));
    }
}


/* =========================================================
 * アンケート終了状態の自動判定
 * ========================================================= */

$surveys = read_json(SURVEYS_FILE);
$changed = false;

foreach ($surveys as &$survey) {
    if (
        ($survey['status'] ?? '') === 'published'
        && !empty($survey['endAt'])
    ) {
        $end = strtotime((string)$survey['endAt']);

        if ($end !== false && $end < time()) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = now_iso();
            $changed = true;
        }
    }
}

unset($survey);

if ($changed) {
    write_json_atomic(SURVEYS_FILE, $surveys);
}


/* =========================================================
 * 対象アンケート
 * ========================================================= */

$survey = null;

if (isset($_GET['id'])) {
    $id = trim((string)$_GET['id']);

    if ($id !== '') {
        $survey = find_survey($id);
    }
}

if (
    in_array($screen, ['edit', 'preview', 'answer', 'confirm', 'complete'], true)
    && isset($_GET['id'])
    && $survey === null
) {
    flash('error', '指定されたアンケートが存在しません。');
    redirect('index.php?screen=list');
}

if (
    in_array($screen, ['send', 'analytics'], true)
    && $survey === null
) {
    flash('error', '対象アンケートが指定されていません。');
    redirect('index.php?screen=list');
}


/* =========================================================
 * 画面
 * ========================================================= */

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
 * kintone設定
 * ========================================================= */

function handle_save_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);

    $k = $settings['kintone'];

    $k['subdomain'] = trim(
        (string)($_POST['subdomain'] ?? '')
    );

    $k['app_id'] = trim(
        (string)($_POST['app_id'] ?? '')
    );

    $k['username'] = trim(
        (string)($_POST['username'] ?? '')
    );

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

    $mapping = $_POST['field_mapping'] ?? [];

    if (is_array($mapping)) {
        foreach ([
            'organization',
            'name',
            'email',
            'department',
            'phone',
        ] as $key) {
            $k['field_mapping'][$key] =
                trim((string)($mapping[$key] ?? ''));
        }

        $address = $mapping['address'] ?? [];

        $k['field_mapping']['address'] =
            is_array($address)
            ? array_values(array_map('strval', $address))
            : [];
    }

    validate_kintone_settings($k);

    $settings['kintone'] = $k;

    write_json_atomic(SETTINGS_FILE, $settings);

    flash('success', 'kintone設定を保存しました。');

    redirect('index.php?screen=kintone');
}


function handle_test_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'];

    /*
     * 接続テストは保存済み設定を使用する。
     */
    validate_kintone_settings($k);

    try {
        /*
         * 重要:
         *
         * kintone REST APIのURLは
         *
         * https://{subdomain}.cybozu.com/k/v1/...
         *
         * である。
         */
        $result = kintone_request(
            $k,
            '/k/v1/app.json?id=' .
            rawurlencode((string)$k['app_id']),
            'GET'
        );

        if (
            $result['status'] >= 200
            && $result['status'] < 300
        ) {
            $settings['kintone']['connection_status'] =
                '接続確認済み';

            $settings['kintone']['last_test_at'] =
                now_iso();

            write_json_atomic(
                SETTINGS_FILE,
                $settings
            );

            flash(
                'success',
                'kintoneへの接続に成功しました。'
            );

        } else {
            $settings['kintone']['connection_status'] =
                '接続できません';

            $settings['kintone']['last_test_at'] =
                now_iso();

            write_json_atomic(
                SETTINGS_FILE,
                $settings
            );

            flash(
                'error',
                'kintone接続に失敗しました。HTTP ' .
                (int)$result['status'] .
                '。' .
                error_detail_from_kintone($result)
            );
        }

    } catch (Throwable $e) {

        $settings['kintone']['connection_status'] =
            '接続できません';

        $settings['kintone']['last_test_at'] =
            now_iso();

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        flash(
            'error',
            'kintone接続エラー: ' .
            safe_error_message($e)
        );
    }

    redirect('index.php?screen=kintone');
}


function handle_fetch_kintone_fields(): void
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'];

    validate_kintone_settings($k);

    /*
     * 現行コードの
     *
     * /v1/app/form/fields.json
     *
     * ではなく、
     *
     * /k/v1/app/form/fields.json
     *
     * を使用する。
     */
    $result = kintone_request(
        $k,
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode((string)$k['app_id']),
        'GET'
    );

    if (
        $result['status'] < 200
        || $result['status'] >= 300
    ) {
        flash(
            'error',
            '項目一覧の取得に失敗しました。HTTP ' .
            (int)$result['status'] .
            '。' .
            error_detail_from_kintone($result)
        );

        redirect('index.php?screen=kintone');
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

    $settings['kintone']['fields'] = $fields;

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    flash(
        'success',
        'kintoneの項目一覧を取得しました。'
    );

    redirect('index.php?screen=kintone');
}


function handle_sync_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);
    $k = $settings['kintone'];

    validate_kintone_settings($k);

    $result = kintone_request(
        $k,
        '/k/v1/records.json?app=' .
        rawurlencode((string)$k['app_id']),
        'GET'
    );

    if (
        $result['status'] < 200
        || $result['status'] >= 300
    ) {
        throw new RuntimeException(
            '顧客情報の取得に失敗しました。HTTP ' .
            (int)$result['status'] .
            error_detail_from_kintone($result)
        );
    }

    $body = json_decode(
        $result['body'],
        true
    );

    if (!is_array($body)) {
        throw new RuntimeException(
            'kintoneの顧客データを解析できませんでした。'
        );
    }

    $mapping =
        $k['field_mapping'] ?? [];

    $customers = [];

    foreach (
        ($body['records'] ?? []) as $record
    ) {
        if (!is_array($record)) {
            continue;
        }

        $customers[] = [
            'id' => uuid(),

            'organization' =>
                kintone_value(
                    $record,
                    (string)(
                        $mapping['organization'] ?? ''
                    )
                ),

            'name' =>
                kintone_value(
                    $record,
                    (string)(
                        $mapping['name'] ?? ''
                    )
                ),

            'email' =>
                kintone_value(
                    $record,
                    (string)(
                        $mapping['email'] ?? ''
                    )
                ),

            'department' =>
                kintone_value(
                    $record,
                    (string)(
                        $mapping['department'] ?? ''
                    )
                ),

            'phone' =>
                kintone_value(
                    $record,
                    (string)(
                        $mapping['phone'] ?? ''
                    )
                ),

            'address' =>
                implode(
                    ' ',
                    array_filter(
                        array_map(
                            function ($code) use ($record) {
                                return kintone_value(
                                    $record,
                                    (string)$code
                                );
                            },
                            is_array(
                                $mapping['address'] ?? null
                            )
                            ? $mapping['address']
                            : []
                        ),
                        fn($v) => $v !== ''
                    )
                ),
        ];
    }

    write_json_atomic(
        CUSTOMERS_FILE,
        $customers
    );

    flash(
        'success',
        count($customers) .
        '件の顧客情報を同期しました。'
    );

    redirect('index.php?screen=kintone');
}


/* =========================================================
 * kintone通信
 * ========================================================= */

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

    if ($username === '') {
        throw new InvalidArgumentException(
            'kintoneログイン名が未設定です。'
        );
    }

    if ($password === '') {
        throw new InvalidArgumentException(
            'kintoneパスワードが未設定です。'
        );
    }

    /*
     * kintoneパスワード認証:
     *
     * X-Cybozu-Authorization:
     * Base64(LOGIN_NAME:PASSWORD)
     */
    $authorization = base64_encode(
        $username . ':' . $password
    );

    $headers = [
        'Host: ' . $host . ':443',
        'X-Cybozu-Authorization: ' .
            $authorization,
        'Accept: application/json',
        'Connection: close',
    ];

    if ($body !== null) {
        $headers[] =
            'Content-Type: application/json';
    }

    /*
     * 正しいkintone REST API URL。
     *
     * /v1/... ではなく /k/v1/...
     */
    $url =
        'https://' .
        $host .
        $path;

    $contextOptions = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode(
                "\r\n",
                $headers
            ),
            'content' => $body ?? '',
            'timeout' => READ_TIMEOUT,
            'ignore_errors' => true,
            'protocol_version' => 1.1,
        ],

        'ssl' => [
            'verify_peer' =>
                (bool)($settings['verify_ssl'] ?? false),

            'verify_peer_name' =>
                (bool)($settings['verify_ssl'] ?? false),

            'allow_self_signed' =>
                !(bool)($settings['verify_ssl'] ?? false),
        ],
    ];

    $proxy = trim(
        (string)($settings['proxy'] ?? '')
    );

    if ($proxy !== '') {

        if (!preg_match(
            '/^[^:]+:\d+$/',
            $proxy
        )) {
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

    $http_response_header = [];

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
        $http_response_header as $header
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
        'body' => (string)$responseBody,
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
        !str_ends_with(
            strtolower($value),
            '.cybozu.com'
        )
    ) {
        $value .= '.cybozu.com';
    }

    return $value;
}


function validate_kintone_settings(
    array $k
): void {

    if (
        trim((string)($k['subdomain'] ?? ''))
        === ''
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    if (
        trim((string)($k['app_id'] ?? ''))
        === ''
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDを入力してください。'
        );
    }

    if (
        !ctype_digit(
            (string)($k['app_id'] ?? '')
        )
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDは数値で入力してください。'
        );
    }

    if (
        trim((string)($k['username'] ?? ''))
        === ''
    ) {
        throw new InvalidArgumentException(
            'kintoneログイン名を入力してください。'
        );
    }

    if (
        (string)($k['password'] ?? '') === ''
    ) {
        throw new InvalidArgumentException(
            'kintoneパスワードを入力してください。'
        );
    }

    $proxy = trim(
        (string)($k['proxy'] ?? '')
    );

    if (
        $proxy !== ''
        && !preg_match(
            '/^[^:]+:\d+$/',
            $proxy
        )
    ) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で指定してください。'
        );
    }
}


function kintone_value(
    array $record,
    string $code
): string {

    if ($code === '') {
        return '';
    }

    if (!isset($record[$code])) {
        return '';
    }

    $value =
        $record[$code]['value'] ?? '';

    if (is_array($value)) {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ) ?: '';
    }

    return (string)$value;
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
        return ' ' .
            (string)$body['message'];
    }

    return '';
}


/* =========================================================
 * メール設定
 * ========================================================= */

function handle_save_mail(): void
{
    $settings = read_json(SETTINGS_FILE);

    $m = $settings['mail'];

    $m['host'] =
        trim((string)($_POST['host'] ?? ''));

    $m['port'] =
        trim((string)($_POST['port'] ?? ''));

    $m['encryption'] =
        trim((string)(
            $_POST['encryption'] ?? 'tls'
        ));

    $m['auth'] =
        isset($_POST['auth']);

    $m['username'] =
        trim((string)(
            $_POST['username'] ?? ''
        ));

    if (
        isset($_POST['password'])
        && (string)$_POST['password'] !== ''
    ) {
        $m['password'] =
            (string)$_POST['password'];
    }

    $m['from_email'] =
        trim((string)(
            $_POST['from_email'] ?? ''
        ));

    $m['from_name'] =
        trim((string)(
            $_POST['from_name'] ?? ''
        ));

    $m['reply_to'] =
        trim((string)(
            $_POST['reply_to'] ?? ''
        ));

    validate_mail_settings($m);

    $settings['mail'] = $m;

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


function handle_test_mail(): void
{
    $settings = read_json(SETTINGS_FILE);
    $m = $settings['mail'];

    try {

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
            'SMTPサーバーへの接続に成功しました。'
        );

    } catch (Throwable $e) {

        $settings['mail']['connection_status'] =
            '接続できません';

        $settings['mail']['last_test_at'] =
            now_iso();

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        flash(
            'error',
            'SMTP接続に失敗しました。' .
            safe_error_message($e)
        );
    }

    redirect('index.php?screen=mail');
}


function handle_send_test_mail(): void
{
    $settings = read_json(SETTINGS_FILE);
    $m = $settings['mail'];

    validate_mail_settings($m);

    $to = trim(
        (string)($_POST['test_email'] ?? '')
    );

    if (
        $to === ''
        || !filter_var(
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

    redirect('index.php?screen=mail');
}


function validate_mail_settings(
    array $m
): void {

    if (
        trim((string)($m['host'] ?? '')) === ''
    ) {
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
        empty($m['from_email'])
        || !filter_var(
            $m['from_email'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    if (
        !empty($m['reply_to'])
        && !filter_var(
            $m['reply_to'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが不正です。'
        );
    }

    if (
        !empty($m['auth'])
        && (
            (string)($m['username'] ?? '') === ''
            || (string)($m['password'] ?? '') === ''
        )
    ) {
        throw new InvalidArgumentException(
            'SMTP認証情報を入力してください。'
        );
    }
}


/* =========================================================
 * SMTP
 * ========================================================= */

function smtp_transport(
    array $m
): string {

    $host = trim(
        (string)($m['host'] ?? '')
    );

    $port = (int)($m['port'] ?? 0);

    if ($host === '') {
        throw new InvalidArgumentException(
            'SMTPサーバーが未設定です。'
        );
    }

    if (
        $port < 1
        || $port > 65535
    ) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    if (
        ($m['encryption'] ?? '') === 'ssl'
    ) {
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


function smtp_open(
    array $m
) {

    $transport =
        smtp_transport($m);

    $errno = 0;
    $errstr = '';

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
            'SMTPサーバーへ接続できませんでした。' .
            ($errstr !== ''
                ? ' ' . $errstr
                : '')
        );
    }

    stream_set_timeout(
        $socket,
        READ_TIMEOUT
    );

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
        ($m['encryption'] ?? '') === 'tls'
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

    return $socket;
}


function smtp_test_connection(
    array $m
): void {

    $socket = smtp_open($m);

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


function smtp_send(
    array $m,
    string $to,
    string $subject,
    string $body
): void {

    $socket = smtp_open($m);

    smtp_command(
        $socket,
        'MAIL FROM:<' .
        $m['from_email'] .
        '>',
        250
    );

    smtp_command(
        $socket,
        'RCPT TO:<' .
        $to .
        '>',
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
        mail_address(
            (string)(
                $m['from_name'] ?? ''
            ),
            (string)$m['from_email']
        );

    $headers[] =
        'To: <' . $to . '>';

    $headers[] =
        'Subject: ' .
        mime_header($subject);

    if (
        !empty($m['reply_to'])
    ) {
        $headers[] =
            'Reply-To: <' .
            $m['reply_to'] .
            '>';
    }

    $headers[] =
        'MIME-Version: 1.0';

    $headers[] =
        'Content-Type: text/plain; charset=UTF-8';

    $headers[] =
        'Content-Transfer-Encoding: 8bit';

    $message =
        implode(
            "\r\n",
            $headers
        ) .
        "\r\n\r\n" .
        normalize_mail_body($body);

    $message = preg_replace(
        '/^\./m',
        '..',
        $message
    );

    fwrite(
        $socket,
        $message .
        "\r\n.\r\n"
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
            'SMTPエラー: ' .
            $code .
            ' ' .
            trim($response)
        );
    }

    return $response;
}


function mail_address(
    string $name,
    string $email
): string {

    if ($name === '') {
        return '<' . $email . '>';
    }

    return mime_header($name) .
        ' <' .
        $email .
        '>';
}


function mime_header(
    string $value
): string {

    if ($value === '') {
        return '';
    }

    return '=?UTF-8?B?' .
        base64_encode($value) .
        '?=';
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
 * アンケート
 * ========================================================= */

function handle_save_survey(): void
{
    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    $title = trim(
        (string)($_POST['title'] ?? '')
    );

    $description = trim(
        (string)($_POST['description'] ?? '')
    );

    $startAt = trim(
        (string)($_POST['startAt'] ?? '')
    );

    $endAt = trim(
        (string)($_POST['endAt'] ?? '')
    );

    $numbering = trim(
        (string)(
            $_POST['numbering'] ?? 'global'
        )
    );

    if ($title === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルを入力してください。'
        );
    }

    if (
        !in_array(
            $numbering,
            ['global', 'group'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            '質問番号方式が不正です。'
        );
    }

    if (
        $startAt !== ''
        && strtotime($startAt) === false
    ) {
        throw new InvalidArgumentException(
            '開始日時が不正です。'
        );
    }

    if (
        $endAt !== ''
        && strtotime($endAt) === false
    ) {
        throw new InvalidArgumentException(
            '終了日時が不正です。'
        );
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && strtotime($startAt)
            > strtotime($endAt)
    ) {
        throw new InvalidArgumentException(
            '終了日時は開始日時以降にしてください。'
        );
    }

    $surveys =
        read_json(SURVEYS_FILE);

    if ($id === '') {

        $survey = [
            'id' => uuid(),
            'title' => $title,
            'description' => $description,
            'startAt' => $startAt,
            'endAt' => $endAt,
            'status' => 'draft',
            'numbering' => $numbering,
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
                ($item['id'] ?? '')
                !== $id
            ) {
                continue;
            }

            if (
                ($item['status'] ?? '')
                === 'ended'
            ) {
                throw new RuntimeException(
                    '終了したアンケートは編集できません。'
                );
            }

            $item['title'] =
                $title;

            $item['description'] =
                $description;

            $item['startAt'] =
                $startAt;

            $item['endAt'] =
                $endAt;

            $item['numbering'] =
                $numbering;

            $item['updatedAt'] =
                now_iso();

            $found = true;
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


function handle_save_questions(): void
{
    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    $survey = find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $groupsJson =
        (string)(
            $_POST['groups_json'] ?? '[]'
        );

    $groups = json_decode(
        $groupsJson,
        true
    );

    if (!is_array($groups)) {
        throw new InvalidArgumentException(
            '質問データが不正です。'
        );
    }

    normalize_questions(
        $groups,
        (string)(
            $survey['numbering']
            ?? 'global'
        )
    );

    $surveys =
        read_json(SURVEYS_FILE);

    foreach ($surveys as &$item) {

        if (
            ($item['id'] ?? '')
            !== $id
        ) {
            continue;
        }

        if (
            ($item['status'] ?? '')
            === 'ended'
        ) {
            throw new RuntimeException(
                '終了したアンケートは変更できません。'
            );
        }

        $item['groups'] =
            $groups;

        $item['updatedAt'] =
            now_iso();

        break;
    }

    unset($item);

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    flash(
        'success',
        '質問を保存しました。'
    );

    redirect(
        'index.php?screen=edit&id=' .
        rawurlencode($id)
    );
}


function handle_delete_survey(): void
{
    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    if ($id === '') {
        throw new InvalidArgumentException(
            '削除対象が指定されていません。'
        );
    }

    $surveys =
        read_json(SURVEYS_FILE);

    $new = [];
    $deleted = false;

    foreach ($surveys as $survey) {

        if (
            ($survey['id'] ?? '')
            === $id
        ) {
            $deleted = true;
            continue;
        }

        $new[] = $survey;
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


function handle_duplicate_survey(): void
{
    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    $survey =
        find_survey($id);

    if ($survey === null) {
        throw new RuntimeException(
            '複製対象のアンケートが存在しません。'
        );
    }

    $surveys =
        read_json(SURVEYS_FILE);

    $copy = $survey;

    $copy['id'] =
        uuid();

    $copy['title'] =
        (string)(
            $copy['title'] ?? ''
        ) .
        '（コピー）';

    $copy['status'] =
        'draft';

    $copy['createdAt'] =
        now_iso();

    $copy['updatedAt'] =
        now_iso();

    write_json_atomic(
        SURVEYS_FILE,
        array_merge(
            $surveys,
            [$copy]
        )
    );

    flash(
        'success',
        'アンケートを複製しました。'
    );

    redirect('index.php?screen=list');
}


function handle_change_status(): void
{
    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    $newStatus = trim(
        (string)($_POST['status'] ?? '')
    );

    $allowed = [
        'draft',
        'published',
        'stopped',
    ];

    if (
        !in_array(
            $newStatus,
            $allowed,
            true
        )
    ) {
        throw new InvalidArgumentException(
            '指定された状態は変更できません。'
        );
    }

    $surveys =
        read_json(SURVEYS_FILE);

    foreach ($surveys as &$survey) {

        if (
            ($survey['id'] ?? '')
            !== $id
        ) {
            continue;
        }

        $current =
            (string)(
                $survey['status'] ?? ''
            );

        if ($current === 'ended') {
            throw new RuntimeException(
                '終了状態から変更することはできません。'
            );
        }

        $valid =
            (
                $current === 'draft'
                && $newStatus === 'published'
            )
            ||
            (
                $current === 'published'
                && $newStatus === 'stopped'
            )
            ||
            (
                $current === 'stopped'
                && $newStatus === 'published'
            );

        if (!$valid) {
            throw new InvalidArgumentException(
                'この状態変更は許可されていません。'
            );
        }

        $survey['status'] =
            $newStatus;

        $survey['updatedAt'] =
            now_iso();

        write_json_atomic(
            SURVEYS_FILE,
            $surveys
        );

        flash(
            'success',
            '状態を変更しました。'
        );

        redirect('index.php?screen=list');
    }

    unset($survey);

    throw new RuntimeException(
        '対象アンケートが存在しません。'
    );
}


/* =========================================================
 * 回答
 * ========================================================= */

function handle_answer_next(): void
{
    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    $survey =
        find_survey($id);

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

    $_SESSION[
        'answer_' . $id
    ] = $answers;

    redirect(
        'index.php?screen=confirm&id=' .
        rawurlencode($id)
    );
}


function handle_answer_back(): void
{
    $id = trim(
        (string)($_POST['id'] ?? '')
    );

    redirect(
        'index.php?screen=answer&id=' .
        rawurlencode($id)
    );
}


function handle_answer_submit(): void
{
    $id = trim(
        (string)($_POST['id'] ?? '')
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
        'index.php?screen=complete&id=' .
        rawurlencode($id)
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
                empty($question['required'])
            ) {
                continue;
            }

            $qid =
                (string)(
                    $question['id'] ?? ''
                );

            $value =
                $answers[$qid] ?? null;

            if (
                $value === null
                || $value === ''
                || (
                    is_array($value)
                    && count($value) === 0
                )
            ) {
                throw new InvalidArgumentException(
                    '必須質問に回答してください。'
                );
            }
        }
    }
}


/* =========================================================
 * メール送信
 * ========================================================= */

function handle_send_mail(): void
{
    $surveyId = trim(
        (string)(
            $_POST['survey_id'] ?? ''
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
        $_POST['customer_ids'] ?? [];

    if (
        !is_array($customerIds)
        || count($customerIds) === 0
    ) {
        throw new InvalidArgumentException(
            '送信対象の顧客を選択してください。'
        );
    }

    $subject =
        trim((string)(
            $_POST['subject'] ?? ''
        ));

    $body =
        (string)(
            $_POST['body'] ?? ''
        );

    if ($subject === '') {
        throw new InvalidArgumentException(
            'メール件名を入力してください。'
        );
    }

    if ($body === '') {
        throw new InvalidArgumentException(
            'メール本文を入力してください。'
        );
    }

    $settings =
        read_json(SETTINGS_FILE);

    $mail =
        $settings['mail'];

    validate_mail_settings($mail);

    $customers =
        read_json(CUSTOMERS_FILE);

    $customerMap = [];

    foreach ($customers as $customer) {
        $customerMap[
            (string)($customer['id'] ?? '')
        ] = $customer;
    }

    $logs =
        read_json(SEND_LOG_FILE);

    $count = 0;

    foreach ($customerIds as $customerId) {

        $customer =
            $customerMap[
                (string)$customerId
            ] ?? null;

        if (!is_array($customer)) {
            continue;
        }

        $to =
            trim((string)(
                $customer['email'] ?? ''
            ));

        if (
            !filter_var(
                $to,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $logs[] = [
                'id' => uuid(),
                'surveyId' => $surveyId,
                'customerId' =>
                    $customer['id'] ?? '',
                'email' => $to,
                'status' => 'failed',
                'message' =>
                    'メールアドレスが不正です。',
                'createdAt' => now_iso(),
            ];

            continue;
        }

        $personalSubject =
            str_replace(
                '{顧客名}',
                (string)(
                    $customer['name'] ?? ''
                ),
                $subject
            );

        $personalBody =
            str_replace(
                [
                    '{顧客名}',
                    '{アンケートURL}',
                ],
                [
                    (string)(
                        $customer['name'] ?? ''
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
                $personalSubject,
                $personalBody
            );

            $logs[] = [
                'id' => uuid(),
                'surveyId' => $surveyId,
                'customerId' =>
                    $customer['id'] ?? '',
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
                'customerId' =>
                    $customer['id'] ?? '',
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
        'index.php?screen=send&id=' .
        rawurlencode($surveyId)
    );
}


/* =========================================================
 * 画面: 共通
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

    echo <<<HTML
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
body{
 margin:0;
 background:#f8fafc;
 color:var(--text);
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",
 "Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
}
header{
 background:#0f172a;
 color:#fff;
 padding:15px 22px;
}
header .inner{
 max-width:1500px;
 margin:auto;
 display:flex;
 gap:20px;
 align-items:center;
 flex-wrap:wrap;
}
header a{
 color:#fff;
 text-decoration:none;
}
main{
 max-width:1500px;
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
table{
 width:100%;
 border-collapse:collapse;
}
th,td{
 padding:11px;
 border-bottom:1px solid var(--border);
 text-align:left;
 vertical-align:middle;
}
.table-wrap{overflow-x:auto}
input,textarea,select{
 width:100%;
 padding:9px 10px;
 border:1px solid #cbd5e1;
 border-radius:6px;
 font:inherit;
}
textarea{min-height:120px}
button,.button{
 display:inline-block;
 border:0;
 border-radius:6px;
 padding:9px 15px;
 cursor:pointer;
 font:inherit;
 text-decoration:none;
}
button.primary,.primary{
 background:var(--primary);
 color:#fff;
}
button.success,.success{
 background:var(--success);
 color:#fff;
}
button.danger,.danger{
 background:var(--danger);
 color:#fff;
}
button.secondary,.secondary{
 background:#e2e8f0;
 color:#0f172a;
}
.actions{
 display:flex;
 gap:8px;
 flex-wrap:wrap;
 margin-top:18px;
}
.form-grid{
 display:grid;
 grid-template-columns:220px 1fr;
 gap:13px 20px;
 align-items:center;
}
.alert{
 padding:12px 15px;
 border-radius:7px;
 margin-bottom:15px;
}
.alert.success{
 background:#dcfce7;
 color:#166534;
}
.alert.error{
 background:#fee2e2;
 color:#991b1b;
}
.status{
 display:inline-block;
 padding:4px 9px;
 border-radius:999px;
 font-size:12px;
}
.status.draft{
 background:#e2e8f0;
 color:#334155;
}
.status.published{
 background:#dcfce7;
 color:#166534;
}
.status.stopped{
 background:#fef3c7;
 color:#92400e;
}
.status.ended{
 background:#fee2e2;
 color:#991b1b;
}
.question{
 border:1px solid var(--border);
 border-radius:8px;
 padding:15px;
 margin:10px 0;
}
.group{
 border:2px solid #dbeafe;
 border-radius:10px;
 padding:15px;
 margin-bottom:18px;
}
.small{
 color:var(--gray);
 font-size:13px;
}
@media(max-width:800px){
 .form-grid{
  grid-template-columns:1fr;
 }
 main{padding:15px}
 header .inner{gap:12px}
}
</style>
HTML;

    echo '</head>';
    echo '<body>';

    echo '<header>';
    echo '<div class="inner">';
    echo '<strong>アンケートアプリ</strong>';
    echo '<a href="index.php?screen=list">アンケート一覧</a>';
    echo '<a href="index.php?screen=kintone">kintone設定</a>';
    echo '<a href="index.php?screen=mail">メール設定</a>';
    echo '</div>';
    echo '</header>';

    echo '<main>';

    render_flash();
}


function render_footer(): void
{
    echo '</main>';

    echo <<<HTML
<script>
document.addEventListener('submit',function(e){
 const form=e.target;
 const button=form.querySelector('button[type="submit"]');
 if(button){
  button.disabled=true;
  button.dataset.originalText=button.textContent;
  button.textContent='処理中...';
 }
});

document.querySelectorAll('[data-confirm]').forEach(function(el){
 el.addEventListener('click',function(e){
  if(!confirm(el.dataset.confirm)){
   e.preventDefault();
  }
 });
});
</script>
HTML;

    echo '</body>';
    echo '</html>';
}


/* =========================================================
 * 一覧
 * ========================================================= */

function render_list(): void
{
    $surveys =
        read_json(SURVEYS_FILE);

    usort(
        $surveys,
        function($a,$b){
            return strcmp(
                (string)($b['updatedAt'] ?? ''),
                (string)($a['updatedAt'] ?? '')
            );
        }
    );

    $keyword =
        trim((string)(
            $_GET['q'] ?? ''
        ));

    $filter =
        trim((string)(
            $_GET['status'] ?? 'all'
        ));

    echo '<h1>アンケート一覧</h1>';

    echo '<div class="card">';
    echo '<form method="get">';
    echo '<input type="hidden" name="screen" value="list">';
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
    echo '<input name="q" value="' .
        h($keyword) .
        '" placeholder="タイトルを検索">';
    echo '<select name="status">';
    foreach([
        'all'=>'すべて',
        'published'=>'公開中',
        'draft'=>'下書き',
        'stopped'=>'停止',
        'ended'=>'終了',
    ] as $value=>$label){
        echo '<option value="' .
            h($value) .
            '"' .
            ($filter===$value?' selected':'') .
            '>' .
            h($label) .
            '</option>';
    }
    echo '</select>';
    echo '<button class="primary">検索</button>';
    echo '</div>';
    echo '</form>';
    echo '</div>';

    echo '<div class="actions">';
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
    echo '<th>状態</th>';
    echo '<th>回答数</th>';
    echo '<th>操作</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    $answers =
        read_json(ANSWERS_FILE);

    foreach($surveys as $survey){

        if(
            $keyword !== ''
            && !str_contains(
                mb_strtolower(
                    (string)($survey['title'] ?? '')
                ),
                mb_strtolower($keyword)
            )
        ){
            continue;
        }

        if(
            $filter !== 'all'
            && ($survey['status'] ?? '') !== $filter
        ){
            continue;
        }

        $count=0;

        foreach($answers as $answer){
            if(
                ($answer['surveyId'] ?? '')
                === ($survey['id'] ?? '')
            ){
                $count++;
            }
        }

        echo '<tr>';

        echo '<td><strong>' .
            h((string)(
                $survey['title'] ?? ''
            )) .
            '</strong></td>';

        echo '<td>' .
            h(format_datetime(
                (string)(
                    $survey['createdAt'] ?? ''
                )
            )) .
            '</td>';

        echo '<td>' .
            h(format_datetime(
                (string)(
                    $survey['updatedAt'] ?? ''
                )
            )) .
            '</td>';

        echo '<td>' .
            h(format_period($survey)) .
            '</td>';

        echo '<td>' .
            status_badge(
                (string)(
                    $survey['status'] ?? ''
                )
            ) .
            '</td>';

        echo '<td>' .
            $count .
            '</td>';

        echo '<td>';

        echo '<div class="actions">';

        echo '<a class="button secondary" href="' .
            'index.php?screen=edit&id=' .
            rawurlencode(
                (string)$survey['id']
            ) .
            '">確認・編集</a>';

        echo '<a class="button secondary" href="' .
            'index.php?screen=analytics&id=' .
            rawurlencode(
                (string)$survey['id']
            ) .
            '">集計</a>';

        echo '<a class="button primary" href="' .
            'index.php?screen=send&id=' .
            rawurlencode(
                (string)$survey['id']
            ) .
            '">送信</a>';

        echo '<form method="post" style="display:inline">';
        echo '<input type="hidden" name="action" value="duplicate_survey">';
        echo '<input type="hidden" name="id" value="' .
            h((string)$survey['id']) .
            '">';
        echo '<button class="secondary" data-confirm="このアンケートを複製しますか？">複製</button>';
        echo '</form>';

        echo '<form method="post" style="display:inline">';
        echo '<input type="hidden" name="action" value="delete_survey">';
        echo '<input type="hidden" name="id" value="' .
            h((string)$survey['id']) .
            '">';
        echo '<button class="danger" data-confirm="削除しますか？">削除</button>';
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
 * 編集
 * ========================================================= */

function render_edit(
    ?array $survey
): void {

    $isNew =
        $survey === null;

    echo '<h1>アンケート' .
        ($isNew?'新規作成':'作成・編集') .
        '</h1>';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="save_survey">';

    if(!$isNew){
        echo '<input type="hidden" name="id" value="' .
            h((string)$survey['id']) .
            '">';
    }

    echo '<div class="card">';

    echo '<div class="form-grid">';

    echo '<label>アンケートタイトル</label>';
    echo '<input name="title" required value="' .
        h((string)(
            $survey['title'] ?? ''
        )) .
        '">';

    echo '<label>アンケート説明</label>';
    echo '<textarea name="description">' .
        h((string)(
            $survey['description'] ?? ''
        )) .
        '</textarea>';

    echo '<label>開始日時</label>';
    echo '<input type="datetime-local" name="startAt" value="' .
        h(datetime_local_value(
            (string)(
                $survey['startAt'] ?? ''
            )
        )) .
        '">';

    echo '<label>終了日時</label>';
    echo '<input type="datetime-local" name="endAt" value="' .
        h(datetime_local_value(
            (string)(
                $survey['endAt'] ?? ''
            )
        )) .
        '">';

    echo '<label>質問番号</label>';
    echo '<select name="numbering">';
    echo '<option value="global"' .
        (($survey['numbering'] ?? 'global')==='global'
            ?' selected':'') .
        '>アンケート全体で通番 Q1,Q2...</option>';
    echo '<option value="group"' .
        (($survey['numbering'] ?? '')==='group'
            ?' selected':'') .
        '>グループ毎 Q1-1,Q1-2...</option>';
    echo '</select>';

    echo '</div>';

    echo '<div class="actions">';

    echo '<a class="button secondary" href="index.php?screen=list">キャンセル</a>';

    echo '<button class="primary">保存して一覧へ</button>';

    echo '</div>';

    echo '</div>';

    echo '</form>';

    if(!$isNew){

        echo '<div class="card">';
        echo '<h2>質問・グループ</h2>';

        render_question_editor(
            $survey
        );

        echo '</div>';

        echo '<div class="card">';
        echo '<h2>プレビュー</h2>';
        echo '<a class="button secondary" href="index.php?screen=preview&id=' .
            rawurlencode(
                (string)$survey['id']
            ) .
            '">プレビューを表示</a>';
        echo '</div>';
    }
}


function render_question_editor(
    array $survey
): void {

    echo '<form method="post" id="question-form">';

    echo '<input type="hidden" name="action" value="save_questions">';
    echo '<input type="hidden" name="id" value="' .
        h((string)$survey['id']) .
        '">';
    echo '<input type="hidden" name="groups_json" id="groups_json">';

    echo '<div id="groups">';

    foreach(
        ($survey['groups'] ?? []) as $group
    ){

        echo '<div class="group" draggable="true">';

        echo '<input class="group-title" value="' .
            h((string)(
                $group['title'] ?? ''
            )) .
            '">';

        echo '<div class="questions">';

        foreach(
            ($group['questions'] ?? []) as $question
        ){
            echo '<div class="question" draggable="true">';

            echo '<strong>' .
                h((string)(
                    $question['number'] ?? ''
                )) .
                '</strong>';

            echo '<input class="question-text" value="' .
                h((string)(
                    $question['text'] ?? ''
                )) .
                '" placeholder="質問文">';

            echo '<select class="question-type">';
            foreach([
                'single'=>'単一選択',
                'multiple'=>'複数選択',
                'text'=>'自由記述',
            ] as $type=>$label){
                echo '<option value="' .
                    h($type) .
                    '"' .
                    (($question['type'] ?? 'single')===$type
                        ?' selected':'') .
                    '>' .
                    h($label) .
                    '</option>';
            }
            echo '</select>';

            echo '<label>';
            echo '<input class="question-required" type="checkbox"' .
                (!empty($question['required'])
                    ?' checked':'') .
                '> 必須';
            echo '</label>';

            echo '<input class="question-options" value="' .
                h(implode(
                    "\n",
                    is_array(
                        $question['options'] ?? null
                    )
                    ? $question['options']
                    : []
                )) .
                '" placeholder="選択肢（改行区切り）">';

            echo '</div>';
        }

        echo '</div>';

        echo '</div>';
    }

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="success" type="submit">質問を保存</button>';
    echo '</div>';

    echo '</form>';

    echo <<<HTML
<script>
document.getElementById('question-form').addEventListener('submit',function(){
 const groups=[];
 document.querySelectorAll('#groups > .group').forEach(function(g){
  const group={
   id:crypto.randomUUID(),
   title:g.querySelector('.group-title').value,
   questions:[]
  };
  g.querySelectorAll('.question').forEach(function(q){
   group.questions.push({
    id:crypto.randomUUID(),
    text:q.querySelector('.question-text').value,
    type:q.querySelector('.question-type').value,
    required:q.querySelector('.question-required').checked,
    options:q.querySelector('.question-options').value
      .split('\\n')
      .map(v=>v.trim())
      .filter(Boolean)
   });
  });
  groups.push(group);
 });
 document.getElementById('groups_json').value=JSON.stringify(groups);
});
</script>
HTML;
}


/* =========================================================
 * プレビュー
 * ========================================================= */

function render_preview(
    ?array $survey
): void {

    echo '<h1>プレビュー</h1>';

    echo '<div class="card">';

    echo '<h2>' .
        h((string)$survey['title']) .
        '</h2>';

    echo '<p>' .
        nl2br(h(
            (string)(
                $survey['description'] ?? ''
            )
        )) .
        '</p>';

    foreach(
        ($survey['groups'] ?? []) as $group
    ){

        echo '<div class="group">';
        echo '<h3>' .
            h((string)(
                $group['title'] ?? ''
            )) .
            '</h3>';

        foreach(
            ($group['questions'] ?? []) as $question
        ){

            echo '<div class="question">';

            echo '<strong>' .
                h((string)(
                    $question['number'] ?? ''
                )) .
                ' ' .
                h((string)(
                    $question['text'] ?? ''
                )) .
                '</strong>';

            echo '<div class="small">' .
                h((string)(
                    $question['type'] ?? ''
                )) .
                (!empty($question['required'])
                    ?' / 必須':' / 任意') .
                '</div>';

            echo '</div>';
        }

        echo '</div>';
    }

    echo '</div>';

    echo '<a class="button secondary" href="index.php?screen=edit&id=' .
        rawurlencode(
            (string)$survey['id']
        ) .
        '">編集へ戻る</a>';
}


/* =========================================================
 * kintone設定画面
 * ========================================================= */

function render_kintone(): void
{
    $settings =
        read_json(SETTINGS_FILE);

    $k =
        $settings['kintone'];

    echo '<h1>kintone連携設定</h1>';

    echo '<div class="card">';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="save_kintone">';

    echo '<div class="form-grid">';

    echo '<label>サブドメイン</label>';
    echo '<input name="subdomain" value="' .
        h((string)$k['subdomain']) .
        '" placeholder="https://xxxx.cybozu.com">';

    echo '<label>顧客管理アプリID</label>';
    echo '<input name="app_id" value="' .
        h((string)$k['app_id']) .
        '">';

    echo '<label>ログイン名</label>';
    echo '<input name="username" value="' .
        h((string)$k['username']) .
        '">';

    echo '<label>パスワード</label>';
    echo '<input type="password" name="password" value="" placeholder="変更する場合のみ入力">';

    echo '<label>Proxy</label>';
    echo '<input name="proxy" value="' .
        h((string)$k['proxy']) .
        '" placeholder="host:port">';

    echo '<label>SSL証明書検証</label>';
    echo '<label><input type="checkbox" name="verify_ssl" value="1"' .
        (!empty($k['verify_ssl'])
            ?' checked':'') .
        '> 有効</label>';

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="primary">設定保存</button>';
    echo '</div>';

    echo '</form>';

    echo '</div>';

    echo '<div class="card">';
    echo '<h2>接続</h2>';

    echo '<p>状態: ' .
        status_text(
            (string)(
                $k['connection_status']
                ?? '未設定'
            )
        ) .
        '</p>';

    echo '<form method="post" class="actions">';
    echo '<input type="hidden" name="action" value="test_kintone">';
    echo '<button class="success">接続テスト</button>';
    echo '</form>';

    echo '<form method="post" class="actions">';
    echo '<input type="hidden" name="action" value="fetch_kintone_fields">';
    echo '<button class="secondary">項目一覧を再取得</button>';
    echo '</form>';

    echo '<form method="post" class="actions">';
    echo '<input type="hidden" name="action" value="sync_kintone">';
    echo '<button class="secondary">顧客情報を同期</button>';
    echo '</form>';

    echo '</div>';

    echo '<div class="card">';
    echo '<h2>項目マッピング</h2>';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="save_kintone">';

    foreach([
        'organization'=>'組織名',
        'name'=>'氏名',
        'email'=>'メールアドレス',
        'department'=>'部署名',
        'phone'=>'電話番号',
    ] as $key=>$label){

        echo '<div class="form-grid" style="margin-bottom:10px">';
        echo '<label>' . h($label) . '</label>';
        echo '<select name="field_mapping[' .
            h($key) .
            ']">';

        echo '<option value="">未設定</option>';

        foreach(
            ($k['fields'] ?? []) as $field
        ){
            echo '<option value="' .
                h((string)$field['code']) .
                '"' .
                (($k['field_mapping'][$key] ?? '') ===
                    $field['code']
                    ?' selected':'') .
                '>' .
                h((string)$field['label']) .
                ' (' .
                h((string)$field['code']) .
                ')</option>';
        }

        echo '</select>';
        echo '</div>';
    }

    echo '<button class="primary">マッピングを保存</button>';

    echo '</form>';

    echo '</div>';
}


/* =========================================================
 * メール設定画面
 * ========================================================= */

function render_mail(): void
{
    $settings =
        read_json(SETTINGS_FILE);

    $m =
        $settings['mail'];

    echo '<h1>メールサーバ設定</h1>';

    echo '<div class="card">';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="save_mail">';

    echo '<div class="form-grid">';

    echo '<label>SMTPサーバ</label>';
    echo '<input name="host" value="' .
        h((string)$m['host']) .
        '">';

    echo '<label>SMTPポート</label>';
    echo '<input name="port" value="' .
        h((string)$m['port']) .
        '">';

    echo '<label>暗号化方式</label>';
    echo '<select name="encryption">';
    foreach([
        'ssl'=>'SSL',
        'tls'=>'TLS',
        'none'=>'なし',
    ] as $value=>$label){
        echo '<option value="' .
            h($value) .
            '"' .
            (($m['encryption'] ?? '') === $value
                ?' selected':'') .
            '>' .
            h($label) .
            '</option>';
    }
    echo '</select>';

    echo '<label>SMTP認証</label>';
    echo '<label><input type="checkbox" name="auth" value="1"' .
        (!empty($m['auth'])
            ?' checked':'') .
        '> 使用する</label>';

    echo '<label>SMTPユーザー名</label>';
    echo '<input name="username" value="' .
        h((string)$m['username']) .
        '">';

    echo '<label>SMTPパスワード</label>';
    echo '<input type="password" name="password" value="" placeholder="変更する場合のみ入力">';

    echo '<label>送信元メールアドレス</label>';
    echo '<input type="email" name="from_email" value="' .
        h((string)$m['from_email']) .
        '">';

    echo '<label>送信元名</label>';
    echo '<input name="from_name" value="' .
        h((string)$m['from_name']) .
        '">';

    echo '<label>返信先メールアドレス</label>';
    echo '<input type="email" name="reply_to" value="' .
        h((string)$m['reply_to']) .
        '">';

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="primary">設定保存</button>';
    echo '</div>';

    echo '</form>';

    echo '</div>';

    echo '<div class="card">';

    echo '<h2>接続確認</h2>';

    echo '<p>状態: ' .
        status_text(
            (string)(
                $m['connection_status']
                ?? '未設定'
            )
        ) .
        '</p>';

    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="test_mail">';
    echo '<button class="success">接続テスト</button>';
    echo '</form>';

    echo '</div>';

    echo '<div class="card">';

    echo '<h2>テストメール</h2>';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="send_test_mail">';

    echo '<div class="form-grid">';
    echo '<label>送信先</label>';
    echo '<input type="email" name="test_email" required>';
    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="primary">テストメール送信</button>';
    echo '</div>';

    echo '</form>';

    echo '</div>';
}


/* =========================================================
 * 送信
 * ========================================================= */

function render_send(
    ?array $survey
): void {

    $customers =
        read_json(CUSTOMERS_FILE);

    $logs =
        read_json(SEND_LOG_FILE);

    echo '<h1>顧客選択・メール送信</h1>';

    echo '<div class="card">';
    echo '<strong>対象アンケート:</strong> ' .
        h((string)$survey['title']);
    echo '</div>';

    echo '<div class="card">';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="send_mail">';
    echo '<input type="hidden" name="survey_id" value="' .
        h((string)$survey['id']) .
        '">';

    echo '<div class="form-grid">';

    echo '<label>件名</label>';
    echo '<input name="subject" required value="アンケートのお願い">';

    echo '<label>本文</label>';
    echo '<textarea name="body" required> {顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}
</textarea>';

    echo '</div>';

    echo '<h2>顧客</h2>';

    echo '<div class="table-wrap">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>選択</th>';
    echo '<th>組織名</th>';
    echo '<th>氏名</th>';
    echo '<th>メール</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach($customers as $customer){

        echo '<tr>';

        echo '<td><input type="checkbox" name="customer_ids[]" value="' .
            h((string)$customer['id']) .
            '"></td>';

        echo '<td>' .
            h((string)(
                $customer['organization'] ?? ''
            )) .
            '</td>';

        echo '<td>' .
            h((string)(
                $customer['name'] ?? ''
            )) .
            '</td>';

        echo '<td>' .
            h((string)(
                $customer['email'] ?? ''
            )) .
            '</td>';

        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="primary" data-confirm="選択した顧客へメールを送信しますか？">一括送信</button>';
    echo '</div>';

    echo '</form>';

    echo '</div>';

    echo '<div class="card">';
    echo '<h2>送信履歴</h2>';

    foreach(
        array_reverse($logs) as $log
    ){
        if(
            ($log['surveyId'] ?? '')
            !== ($survey['id'] ?? '')
        ){
            continue;
        }

        echo '<div class="question">';

        echo h((string)(
            $log['createdAt'] ?? ''
        ));

        echo ' / ';

        echo h((string)(
            $log['email'] ?? ''
        ));

        echo ' / ';

        echo h((string)(
            $log['status'] ?? ''
        ));

        if(
            !empty($log['message'])
        ){
            echo '<br>';
            echo h((string)$log['message']);
        }

        echo '</div>';
    }

    echo '</div>';
}


/* =========================================================
 * 集計
 * ========================================================= */

function render_analytics(
    ?array $survey
): void {

    $answers =
        read_json(ANSWERS_FILE);

    $surveyAnswers =
        array_values(
            array_filter(
                $answers,
                fn($answer) =>
                    ($answer['surveyId'] ?? '')
                    === ($survey['id'] ?? '')
            )
        );

    $customers =
        read_json(CUSTOMERS_FILE);

    $sent =
        read_json(SEND_LOG_FILE);

    $sentCount=0;

    foreach($sent as $log){
        if(
            ($log['surveyId'] ?? '')
            === ($survey['id'] ?? '')
            && ($log['status'] ?? '')
            === 'sent'
        ){
            $sentCount++;
        }
    }

    echo '<h1>回答集計・分析</h1>';

    echo '<div class="card">';
    echo '<h2>' .
        h((string)$survey['title']) .
        '</h2>';

    echo '<table>';

    echo '<tr><th>送信対象者数</th><td>' .
        count($customers) .
        '</td></tr>';

    echo '<tr><th>回答数</th><td>' .
        count($surveyAnswers) .
        '</td></tr>';

    echo '<tr><th>未登録回答数</th><td>0</td></tr>';

    $unanswered =
        max(
            0,
            $sentCount -
            count($surveyAnswers)
        );

    echo '<tr><th>未回答数</th><td>' .
        $unanswered .
        '</td></tr>';

    $rate =
        $sentCount > 0
        ? round(
            count($surveyAnswers)
            / $sentCount
            * 100,
            1
        )
        : 0;

    echo '<tr><th>回答率</th><td>' .
        $rate .
        '%</td></tr>';

    echo '</table>';

    echo '</div>';

    if(count($surveyAnswers)===0){

        echo '<div class="card">';
        echo '現在、回答データはありません';
        echo '</div>';

        return;
    }

    echo '<div class="card">';
    echo '<h2>個別回答</h2>';

    foreach(
        $surveyAnswers as $answer
    ){

        echo '<div class="question">';

        echo '<strong>' .
            h((string)(
                $answer['createdAt'] ?? ''
            )) .
            '</strong>';

        foreach(
            ($answer['answers'] ?? [])
            as $qid=>$value
        ){

            echo '<p>';

            echo h((string)$qid);
            echo ': ';

            if(is_array($value)){
                echo h(
                    implode(
                        ', ',
                        array_map(
                            'strval',
                            $value
                        )
                    )
                );
            }else{
                echo h((string)$value);
            }

            echo '</p>';
        }

        echo '</div>';
    }

    echo '</div>';
}


/* =========================================================
 * 回答者画面
 * ========================================================= */

function render_answer(
    ?array $survey
): void {

    echo '<h1>' .
        h((string)$survey['title']) .
        '</h1>';

    echo '<div class="card">';

    echo '<p>' .
        nl2br(h(
            (string)(
                $survey['description'] ?? ''
            )
        )) .
        '</p>';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="answer_next">';
    echo '<input type="hidden" name="id" value="' .
        h((string)$survey['id']) .
        '">';

    foreach(
        ($survey['groups'] ?? []) as $group
    ){

        echo '<div class="group">';

        echo '<h2>' .
            h((string)(
                $group['title'] ?? ''
            )) .
            '</h2>';

        foreach(
            ($group['questions'] ?? [])
            as $question
        ){

            $qid =
                (string)(
                    $question['id'] ?? ''
                );

            echo '<div class="question">';

            echo '<label>';

            echo '<strong>' .
                h((string)(
                    $question['number'] ?? ''
                )) .
                ' ' .
                h((string)(
                    $question['text'] ?? ''
                )) .
                '</strong>';

            if(
                !empty($question['required'])
            ){
                echo ' <span style="color:#dc2626">必須</span>';
            }

            echo '</label>';

            $type =
                (string)(
                    $question['type'] ?? 'text'
                );

            if($type === 'single'){

                foreach(
                    ($question['options'] ?? [])
                    as $option
                ){
                    echo '<label style="display:block;margin-top:8px">';
                    echo '<input type="radio" name="answers[' .
                        h($qid) .
                        ']" value="' .
                        h((string)$option) .
                        '"> ' .
                        h((string)$option);
                    echo '</label>';
                }

            }elseif($type === 'multiple'){

                foreach(
                    ($question['options'] ?? [])
                    as $option
                ){
                    echo '<label style="display:block;margin-top:8px">';
                    echo '<input type="checkbox" name="answers[' .
                        h($qid) .
                        '][]" value="' .
                        h((string)$option) .
                        '"> ' .
                        h((string)$option);
                    echo '</label>';
                }

            }else{

                echo '<textarea name="answers[' .
                    h($qid) .
                    ']"></textarea>';
            }

            echo '</div>';
        }

        echo '</div>';
    }

    echo '<div class="actions">';
    echo '<button class="primary">回答確認へ</button>';
    echo '</div>';

    echo '</form>';

    echo '</div>';
}


/* =========================================================
 * 回答確認
 * ========================================================= */

function render_confirm(
    ?array $survey
): void {

    $answers =
        $_SESSION[
            'answer_' .
            $survey['id']
        ] ?? [];

    echo '<h1>回答確認</h1>';

    echo '<div class="card">';

    foreach(
        ($survey['groups'] ?? [])
        as $group
    ){

        foreach(
            ($group['questions'] ?? [])
            as $question
        ){

            $qid =
                (string)(
                    $question['id'] ?? ''
                );

            $value =
                $answers[$qid] ?? '';

            if(is_array($value)){
                $display =
                    implode(
                        ', ',
                        array_map(
                            'strval',
                            $value
                        )
                    );
            }else{
                $display =
                    (string)$value;
            }

            echo '<div class="question">';

            echo '<strong>' .
                h((string)(
                    $question['number'] ?? ''
                )) .
                ' ' .
                h((string)(
                    $question['text'] ?? ''
                )) .
                '</strong>';

            echo '<p>' .
                nl2br(h($display)) .
                '</p>';

            echo '</div>';
        }
    }

    echo '<div class="actions">';

    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="answer_back">';
    echo '<input type="hidden" name="id" value="' .
        h((string)$survey['id']) .
        '">';
    echo '<button class="secondary">修正する</button>';
    echo '</form>';

    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="answer_submit">';
    echo '<input type="hidden" name="id" value="' .
        h((string)$survey['id']) .
        '">';
    echo '<button class="primary" data-confirm="回答を送信しますか？">回答を送信</button>';
    echo '</form>';

    echo '</div>';

    echo '</div>';
}


/* =========================================================
 * 完了
 * ========================================================= */

function render_complete(
    ?array $survey
): void {

    echo '<div class="card">';
    echo '<h1>回答完了</h1>';
    echo '<p>回答を受け付けました。ご協力ありがとうございました。</p>';
    echo '</div>';
}


/* =========================================================
 * データ・質問処理
 * ========================================================= */

function normalize_questions(
    array &$groups,
    string $numbering = 'global'
): void {

    $global = 1;
    $groupNo = 1;

    foreach($groups as &$group){

        if(
            !isset($group['id'])
            || $group['id'] === ''
        ){
            $group['id'] = uuid();
        }

        if(
            !isset($group['title'])
        ){
            $group['title'] =
                'グループ' .
                $groupNo;
        }

        $questionNo = 1;

        foreach(
            ($group['questions'] ?? [])
            as &$question
        ){

            if(
                !isset($question['id'])
                || $question['id'] === ''
            ){
                $question['id'] =
                    uuid();
            }

            if(
                !isset($question['text'])
            ){
                $question['text'] = '';
            }

            if(
                !in_array(
                    $question['type'] ?? '',
                    [
                        'single',
                        'multiple',
                        'text'
                    ],
                    true
                )
            ){
                $question['type'] =
                    'text';
            }

            $question['required'] =
                !empty(
                    $question['required']
                );

            if(
                !isset($question['options'])
                || !is_array(
                    $question['options']
                )
            ){
                $question['options'] = [];
            }

            if($numbering === 'group'){
                $question['number'] =
                    'Q' .
                    $groupNo .
                    '-' .
                    $questionNo;
            }else{
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


function find_survey(
    string $id
): ?array {

    if($id === ''){
        return null;
    }

    foreach(
        read_json(SURVEYS_FILE)
        as $survey
    ){
        if(
            ($survey['id'] ?? '')
            === $id
        ){
            return $survey;
        }
    }

    return null;
}


/* =========================================================
 * JSON
 * ========================================================= */

function init_json_file(
    string $file,
    array $default
): void {

    if(!file_exists($file)){
        write_json_atomic(
            $file,
            $default
        );
    }
}


function read_json(
    string $file
): array {

    if(!file_exists($file)){
        return [];
    }

    $raw =
        file_get_contents($file);

    if($raw === false || trim($raw)===''){
        return [];
    }

    $data =
        json_decode(
            $raw,
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

    $tmp =
        $file .
        '.' .
        bin2hex(
            random_bytes(6)
        ) .
        '.tmp';

    $json =
        json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT
        );

    if($json === false){
        throw new RuntimeException(
            'JSONデータを生成できませんでした。'
        );
    }

    if(
        file_put_contents(
            $tmp,
            $json,
            LOCK_EX
        ) === false
    ){
        throw new RuntimeException(
            '一時ファイルへ保存できませんでした。'
        );
    }

    if(!rename($tmp,$file)){
        @unlink($tmp);

        throw new RuntimeException(
            'データファイルを更新できませんでした。'
        );
    }
}


/* =========================================================
 * 共通
 * ========================================================= */

function uuid(): string
{
    return sprintf(
        '%s-%s-%s-%s-%s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6))
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
        ENT_QUOTES |
        ENT_SUBSTITUTE,
        'UTF-8'
    );
}


function screen_url(
    string $screen
): string {

    return 'index.php?screen=' .
        rawurlencode($screen);
}


function survey_answer_url(
    string $id
): string {

    $scheme =
        (
            !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off'
        )
        ? 'https'
        : 'http';

    $host =
        (string)(
            $_SERVER['HTTP_HOST']
            ?? 'localhost'
        );

    $script =
        (string)(
            $_SERVER['SCRIPT_NAME']
            ?? '/index.php'
        );

    return $scheme .
        '://' .
        $host .
        $script .
        '?screen=answer&id=' .
        rawurlencode($id);
}


function redirect(
    string $url
): never {

    header(
        'Location: ' .
        $url,
        true,
        303
    );

    exit;
}


function flash(
    string $type,
    string $message
): void {

    $_SESSION['_flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}


function render_flash(): void
{
    $messages =
        $_SESSION['_flash'] ?? [];

    unset(
        $_SESSION['_flash']
    );

    foreach($messages as $message){

        echo '<div class="alert ' .
            h((string)(
                $message['type']
                ?? 'error'
            )) .
            '">' .
            h((string)(
                $message['message']
                ?? ''
            )) .
            '</div>';
    }
}


function safe_error_message(
    Throwable $e
): string {

    $message =
        trim($e->getMessage());

    if($message===''){
        return '';
    }

    /*
     * POCのため原因を表示する。
     * 認証情報そのものはここへ渡さない。
     */
    return ' ' . $message;
}


function format_datetime(
    string $value
): string {

    if($value===''){
        return '';
    }

    $timestamp =
        strtotime($value);

    if($timestamp===false){
        return $value;
    }

    return date(
        'Y/m/d H:i',
        $timestamp
    );
}


function datetime_local_value(
    string $value
): string {

    if($value===''){
        return '';
    }

    $timestamp =
        strtotime($value);

    if($timestamp===false){
        return '';
    }

    return date(
        'Y-m-d\TH:i',
        $timestamp
    );
}


function format_period(
    array $survey
): string {

    $start =
        format_datetime(
            (string)(
                $survey['startAt'] ?? ''
            )
        );

    $end =
        format_datetime(
            (string)(
                $survey['endAt'] ?? ''
            )
        );

    if(
        $start === ''
        && $end === ''
    ){
        return '指定なし';
    }

    return $start .
        ' ～ ' .
        $end;
}


function status_badge(
    string $status
): string {

    $labels = [
        'draft' =>
            '下書き',
        'published' =>
            '公開中',
        'stopped' =>
            '停止',
        'ended' =>
            '終了',
    ];

    $label =
        $labels[$status]
        ?? $status;

    return '<span class="status ' .
        h($status) .
        '">' .
        h($label) .
        '</span>';
}


function status_text(
    string $status
): string {

    return h($status);
}
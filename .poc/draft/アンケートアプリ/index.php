<?php
declare(strict_types=1);

/*
 * アンケートアプリ POC
 *
 * prompt.txt準拠:
 * - 管理者認証なし
 * - CSRF対策なし
 * - DBなし
 * - PHP cURLなし
 * - PHP mail()なし
 * - kintoneはログイン名/パスワードによる認証
 * - X-Cybozu-Authorizationはサーバー側だけで生成
 * - SMTPはソケットによる実接続
 * - データはサーバー側JSON
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
 * ---------------------------------------------------------
 * 初期化
 * ---------------------------------------------------------
 */

if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        http_response_code(500);
        exit('データディレクトリを作成できません。');
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


/*
 * ---------------------------------------------------------
 * セッション
 *
 * CSRFトークンは作らない。
 *
 * 今回の重要な修正点:
 *
 * 1. iframe + cross-site POSTに対応するため SameSite=None
 * 2. HTTPS環境ではSecure
 * 3. 日本語を含むSCRIPT_NAMEからCookie Pathを生成しない
 * 4. Cookie Pathは "/" に統一
 *
 * prompt.txtではセッション利用自体は禁止されておらず、
 * 回答途中等の状態保持に使用することが明記されている。
 * ---------------------------------------------------------
 */

session_name('questionnaire_poc_session');

$secure = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
);

/*
 * iframe / cross-site環境ではSameSite=Laxだと
 * POST時にCookieが送信されない場合がある。
 *
 * このPOCでは管理者認証を行わないため、
 * セッションは状態保持専用。
 */
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'None',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!session_start()) {
        http_response_code(500);
        exit('セッションを開始できません。');
    }
}


/*
 * ---------------------------------------------------------
 * Routing
 * ---------------------------------------------------------
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
 * ---------------------------------------------------------
 * POST
 *
 * CSRFチェックは実装しない。
 * ---------------------------------------------------------
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string)($_POST['action'] ?? '');

    try {

        switch ($action) {

            /*
             * kintone
             */

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


            /*
             * mail
             */

            case 'save_mail':
                handle_save_mail();
                break;

            case 'test_mail':
                handle_test_mail();
                break;

            case 'send_test_mail':
                handle_send_test_mail();
                break;


            /*
             * survey
             */

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


            /*
             * answer
             */

            case 'answer_next':
                handle_answer_next();
                break;

            case 'answer_back':
                handle_answer_back();
                break;

            case 'answer_submit':
                handle_answer_submit();
                break;


            /*
             * mail sending
             */

            case 'send_mail':
                handle_send_mail();
                break;

            case 'resend_mail':
                handle_resend_mail();
                break;

            case 'remind_mail':
                handle_remind_mail();
                break;


            default:
                /*
                 * action不明でも一覧へ飛ばすのではなく、
                 * 現在の画面を維持してエラーを表示する。
                 */
                flash('error', '不明な操作です。');
                redirect(screen_url($screen));
        }

    } catch (Throwable $e) {

        /*
         * パスワード、認証ヘッダー等は例外メッセージへ
         * 入れない前提。
         */
        flash(
            'error',
            '処理に失敗しました。' . safe_error_message($e)
        );

        redirect(screen_url($screen));
    }
}


/*
 * ---------------------------------------------------------
 * GET時の自動終了判定
 * ---------------------------------------------------------
 */

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


/*
 * ---------------------------------------------------------
 * 対象アンケート
 * ---------------------------------------------------------
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

    $id = (string)($_GET['id'] ?? '');

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

        redirect('index.php?screen=list');
    }
}


/*
 * ---------------------------------------------------------
 * HTML
 * ---------------------------------------------------------
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
 * =========================================================
 * kintone
 * =========================================================
 */

function handle_save_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);

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
     * パスワード空欄なら既存値を維持。
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

    $mapping = $_POST['field_mapping'] ?? [];

    if (is_array($mapping)) {

        foreach (
            [
                'organization',
                'name',
                'email',
                'department',
                'phone',
            ] as $key
        ) {

            $k['field_mapping'][$key] =
                trim(
                    (string)($mapping[$key] ?? '')
                );
        }

        $addresses = $mapping['address'] ?? [];

        $k['field_mapping']['address'] =
            is_array($addresses)
                ? array_values(
                    array_map('strval', $addresses)
                )
                : [];
    }

    validate_kintone_settings($k);

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    flash(
        'success',
        'kintone設定を保存しました。'
    );

    redirect('index.php?screen=kintone');
}


function handle_test_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);

    $k = $settings['kintone'];

    validate_kintone_settings($k);

    try {

        $result = kintone_request(
            $k,
            '/v1/app.json?id=' .
            rawurlencode((string)$k['app_id']),
            'GET'
        );

        if (
            $result['status'] >= 200
            && $result['status'] < 300
        ) {

            $settings['kintone']
                ['connection_status']
                = '接続確認済み';

            $settings['kintone']
                ['last_test_at']
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

            $settings['kintone']
                ['connection_status']
                = '接続できません';

            $settings['kintone']
                ['last_test_at']
                = now_iso();

            write_json_atomic(
                SETTINGS_FILE,
                $settings
            );

            flash(
                'error',
                'kintoneへの接続に失敗しました。HTTP ' .
                (int)$result['status'] .
                error_detail_from_kintone($result)
            );
        }

    } catch (Throwable $e) {

        $settings['kintone']
            ['connection_status']
            = '接続できません';

        $settings['kintone']
            ['last_test_at']
            = now_iso();

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

    redirect(
        'index.php?screen=kintone'
    );
}


/*
 * ---------------------------------------------------------
 * kintone communication
 * ---------------------------------------------------------
 */

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

    $authorization = base64_encode(
        $username . ':' . $password
    );

    /*
     * 認証情報はここでだけ生成する。
     * ブラウザへ返さない。
     */
    $headers = [
        'X-Cybozu-Authorization: ' .
        $authorization,
        'Accept: application/json',
    ];

    if ($body !== null) {
        $headers[] =
            'Content-Type: application/json';
    }

    $url = 'https://' . $host . $path;

    /*
     * PHP cURLは禁止。
     * PHP標準streamを使用。
     */
    $contextOptions = [
        'http' => [
            'method' => $method,
            'header' => implode(
                "\r\n",
                $headers
            ),
            'content' => $body ?? '',
            'timeout' => READ_TIMEOUT,
            'ignore_errors' => true,
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

    $proxy =
        trim((string)($settings['proxy'] ?? ''));

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
        ($http_response_header ?? []) as $header
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

    $value = trim($value, '/');

    if (str_contains(
        $value,
        '.cybozu.com'
    )) {
        return $value;
    }

    return $value . '.cybozu.com';
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


/*
 * =========================================================
 * Mail
 * =========================================================
 */

function handle_save_mail(): void
{
    $settings = read_json(SETTINGS_FILE);

    $m = &$settings['mail'];

    $m['host'] = trim(
        (string)($_POST['host'] ?? '')
    );

    $port = (int)($_POST['port'] ?? 0);

    if (
        $port < 1
        || $port > 65535
    ) {

        throw new InvalidArgumentException(
            'SMTPポートは1～65535で指定してください。'
        );
    }

    $m['port'] = $port;

    $encryption =
        (string)($_POST['encryption'] ?? 'tls');

    if (!in_array(
        $encryption,
        ['ssl', 'tls', 'none'],
        true
    )) {

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

    flash(
        'success',
        'メール設定を保存しました。'
    );

    redirect(
        'index.php?screen=mail'
    );
}


function handle_test_mail(): void
{
    $settings = read_json(SETTINGS_FILE);

    $m = $settings['mail'];

    validate_mail_settings($m);

    try {

        smtp_test_connection($m);

        $settings['mail']
            ['connection_status']
            = '接続確認済み';

        $settings['mail']
            ['last_test_at']
            = now_iso();

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        flash(
            'success',
            'SMTPサーバーへの接続に成功しました。'
        );

    } catch (Throwable $e) {

        $settings['mail']
            ['connection_status']
            = '接続できません';

        $settings['mail']
            ['last_test_at']
            = now_iso();

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        flash(
            'error',
            'SMTP接続エラー: ' .
            safe_error_message($e)
        );
    }

    redirect(
        'index.php?screen=mail'
    );
}


function smtp_test_connection(
    array $m
): void {

    $transport = smtp_transport($m);

    $socket = @stream_socket_client(
        $transport,
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {

        throw new RuntimeException(
            'SMTPサーバーへ接続できませんでした。' .
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

        if (!stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        )) {

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


function smtp_transport(
    array $m
): string {

    $host =
        trim((string)($m['host'] ?? ''));

    $port =
        (int)($m['port'] ?? 0);

    $encryption =
        (string)($m['encryption'] ?? '');

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

    if ($encryption === 'ssl') {
        return 'ssl://' .
            $host . ':' . $port;
    }

    return 'tcp://' .
        $host . ':' . $port;
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
        ($line = fgets(
            $socket,
            4096
        )) !== false
    ) {

        $response .= $line;

        if (
            preg_match(
                '/^(\d{3})(?:\s|\r?\n)/',
                $line,
                $m
            )
        ) {

            if (
                (int)$m[1] !== $expected
            ) {

                throw new RuntimeException(
                    'SMTP応答エラー: ' .
                    trim($response)
                );
            }

            return $response;
        }
    }

    throw new RuntimeException(
        'SMTPサーバーから応答がありません。'
    );
}


/*
 * =========================================================
 * Validation
 * =========================================================
 */

function validate_kintone_settings(
    array $k
): void {

    if (
        trim((string)(
            $k['subdomain'] ?? ''
        )) === ''
    ) {

        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    if (
        (int)($k['app_id'] ?? 0) <= 0
    ) {

        throw new InvalidArgumentException(
            '顧客管理アプリIDを入力してください。'
        );
    }

    if (
        trim((string)(
            $k['username'] ?? ''
        )) === ''
    ) {

        throw new InvalidArgumentException(
            'ログイン名を入力してください。'
        );
    }

    if (
        (string)($k['password'] ?? '') === ''
    ) {

        throw new InvalidArgumentException(
            'kintoneパスワードを設定してください。'
        );
    }

    $proxy =
        trim((string)($k['proxy'] ?? ''));

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


function validate_mail_settings(
    array $m
): void {

    if (
        trim((string)(
            $m['host'] ?? ''
        )) === ''
    ) {

        throw new InvalidArgumentException(
            'SMTPサーバを入力してください。'
        );
    }

    $port =
        (int)($m['port'] ?? 0);

    if (
        $port < 1
        || $port > 65535
    ) {

        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    $from =
        (string)($m['from_email'] ?? '');

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

    if (!empty($m['auth'])) {

        if (
            trim((string)(
                $m['username'] ?? ''
            )) === ''
            || (string)(
                $m['password'] ?? ''
            ) === ''
        ) {

            throw new InvalidArgumentException(
                'SMTP認証を使用する場合はユーザー名とパスワードが必要です。'
            );
        }
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
}


/*
 * =========================================================
 * Storage
 * =========================================================
 */

function init_json_file(
    string $file,
    array $default
): void {

    if (!file_exists($file)) {
        write_json_atomic(
            $file,
            $default
        );
    }
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

    $dir = dirname($file);

    if (!is_dir($dir)) {

        if (
            !mkdir(
                $dir,
                0775,
                true
            )
            && !is_dir($dir)
        ) {

            throw new RuntimeException(
                'データディレクトリを作成できません。'
            );
        }
    }

    $tmp =
        $file . '.' .
        bin2hex(random_bytes(6)) .
        '.tmp';

    $json =
        json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT
        );

    if ($json === false) {

        throw new RuntimeException(
            'データをJSON化できませんでした。'
        );
    }

    $fp = fopen(
        $tmp,
        'wb'
    );

    if ($fp === false) {

        throw new RuntimeException(
            '一時ファイルを作成できませんでした。'
        );
    }

    try {

        if (!flock(
            $fp,
            LOCK_EX
        )) {

            throw new RuntimeException(
                'データファイルをロックできませんでした。'
            );
        }

        if (
            fwrite(
                $fp,
                $json
            ) === false
        ) {

            throw new RuntimeException(
                'データを書き込めませんでした。'
            );
        }

        fflush($fp);

        flock(
            $fp,
            LOCK_UN
        );

    } finally {

        fclose($fp);
    }

    if (!rename(
        $tmp,
        $file
    )) {

        @unlink($tmp);

        throw new RuntimeException(
            'データファイルを更新できませんでした。'
        );
    }
}


/*
 * =========================================================
 * General helpers
 * =========================================================
 */

function find_survey(
    string $id
): ?array {

    if ($id === '') {
        return null;
    }

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


function now_iso(): string
{
    return date('c');
}


function uuid(): string
{
    return bin2hex(
        random_bytes(16)
    );
}


function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES |
        ENT_SUBSTITUTE,
        'UTF-8'
    );
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

    foreach (
        $messages as $message
    ) {

        echo '<div class="alert ' .
            h((string)(
                $message['type'] ?? 'error'
            )) .
            '">' .
            h((string)(
                $message['message'] ?? ''
            )) .
            '</div>';
    }
}


function redirect(
    string $url
): never {

    /*
     * 外部URLへのリダイレクトは許可しない。
     * このアプリ内で固定的に生成したURLだけを渡す。
     */
    header(
        'Location: ' . $url,
        true,
        303
    );

    exit;
}


function screen_url(
    string $screen
): string {

    return 'index.php?screen=' .
        rawurlencode($screen);
}


function safe_error_message(
    Throwable $e
): string {

    $message =
        trim($e->getMessage());

    if ($message === '') {
        return '';
    }

    /*
     * 認証情報を例外メッセージに含めない。
     */
    return ' ' . $message;
}


/*
 * =========================================================
 * 最低限のHTML
 *
 * 実際の再生成版では、prompt.txtの
 * 「確定したモックのデザイン」をここへ統合する。
 * =========================================================
 */

function render_header(
    string $screen
): void {

    echo '<!doctype html>';
    echo '<html lang="ja">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>アンケートアプリ</title>';

    echo '<style>';
    echo ':root{';
    echo '--primary:#2563eb;';
    echo '--primary-dark:#1d4ed8;';
    echo '--success:#16a34a;';
    echo '--warning:#d97706;';
    echo '--danger:#dc2626;';
    echo '--gray:#64748b;';
    echo '--gray-light:#f1f5f9;';
    echo '--border:#dbe2ea;';
    echo '--text:#1e293b;';
    echo '--white:#fff;';
    echo '--shadow:0 4px 18px rgba(15,23,42,.08)';
    echo '}';

    echo '*{box-sizing:border-box}';
    echo 'body{margin:0;background:#f8fafc;color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP","Hiragino Kaku Gothic ProN",Meiryo,sans-serif}';
    echo 'header{background:#0f172a;color:#fff;padding:16px 24px}';
    echo 'main{max-width:1400px;margin:0 auto;padding:24px}';
    echo '.card{background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow);padding:20px;margin-bottom:20px}';
    echo '.form-grid{display:grid;grid-template-columns:220px minmax(0,1fr);gap:14px;align-items:center}';
    echo 'input,select,textarea{width:100%;padding:10px;border:1px solid var(--border);border-radius:6px;font:inherit}';
    echo 'textarea{min-height:120px}';
    echo '.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}';
    echo 'button,.button{border:0;border-radius:6px;padding:10px 16px;cursor:pointer;text-decoration:none;display:inline-block}';
    echo '.primary{background:var(--primary);color:#fff}';
    echo '.secondary{background:#e2e8f0;color:#1e293b}';
    echo '.success{background:var(--success);color:#fff}';
    echo '.danger{background:var(--danger);color:#fff}';
    echo '.alert{padding:14px 16px;border-radius:8px;margin-bottom:18px;background:#e2e8f0}';
    echo '.alert.success{background:#dcfce7;color:#166534}';
    echo '.alert.error{background:#fee2e2;color:#991b1b}';
    echo '.table-wrap{overflow-x:auto}';
    echo 'table{width:100%;border-collapse:collapse;min-width:900px}';
    echo 'th,td{padding:10px;border-bottom:1px solid var(--border);text-align:left}';
    echo '@media(max-width:700px){.form-grid{grid-template-columns:1fr}main{padding:14px}}';
    echo '</style>';

    echo '</head>';
    echo '<body>';

    echo '<header>';
    echo '<strong>アンケートアプリ</strong>';
    echo '</header>';

    echo '<main>';

    render_flash();
}


function render_footer(): void
{
    echo '</main>';

    echo '<script>';
    echo 'document.querySelectorAll("form[data-busy]").forEach(function(form){';
    echo 'form.addEventListener("submit",function(){';
    echo 'var button=form.querySelector("button[type=submit],button:not([type])");';
    echo 'if(button){button.disabled=true;button.dataset.originalText=button.textContent;button.textContent="処理中...";}';
    echo '});';
    echo '});';

    /*
     * 確認ダイアログ。
     * CSRFとは無関係。
     */
    echo 'document.querySelectorAll("form[data-confirm]").forEach(function(form){';
    echo 'form.addEventListener("submit",function(e){';
    echo 'if(!window.confirm(form.dataset.confirm)){e.preventDefault();}';
    echo '});';
    echo '});';

    echo '</script>';

    echo '</body>';
    echo '</html>';
}
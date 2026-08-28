<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし
 *
 * 単一エントリーポイント:
 * index.php?screen=list
 * index.php?screen=edit&id=...
 * index.php?screen=preview&id=...
 * index.php?screen=send&id=...
 * index.php?screen=analytics&id=...
 * index.php?screen=kintone
 * index.php?screen=mail
 * index.php?screen=answer&id=...
 * index.php?screen=confirm&id=...
 * index.php?screen=complete&id=...
 *
 * 外部通信:
 * - PHP stream
 * - PHP cURL不使用
 * - PHP mail()不使用
 *
 * 重要:
 * - kintone認証情報はHTML/JS/URLへ出力しない
 * - SMTP認証情報はHTML/JS/URLへ出力しない
 * - 外部通信の302等を成功扱いしない
 * - 外部API処理と画面リダイレクトを分離する
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const MAX_TITLE = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION = 1000;
const MAX_OPTION = 500;

const CONNECT_TIMEOUT = 10;
const READ_TIMEOUT = 30;

/* =========================================================
 * 初期化
 * ========================================================= */

function start_app(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException('データ保存フォルダを作成できません。');
        }
    }

    if (!is_file(DATA_FILE)) {
        save_json(DATA_FILE, default_data());
    }

    if (!is_file(SETTINGS_FILE)) {
        save_json(SETTINGS_FILE, default_settings());
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        $https = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443
        );

        session_name('survey_app_session');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => cookie_path(),
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException('セッションを開始できません。');
        }
    }
}

function cookie_path(): string
{
    $script = str_replace(
        '\\',
        '/',
        (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')
    );

    $dir = dirname($script);

    if ($dir === '.' || $dir === '/' || $dir === '\\') {
        return '/';
    }

    return rtrim($dir, '/') . '/';
}

/* =========================================================
 * デフォルトデータ
 * ========================================================= */

function default_data(): array
{
    $now = date('Y-m-d H:i:s');

    return [
        'surveys' => [
            [
                'id' => 'survey-001',
                'title' => '顧客満足度アンケート',
                'description' => 'サービスについてのご意見をお聞かせください。',
                'startAt' => date('Y-m-d\TH:i'),
                'endAt' => date('Y-m-d\TH:i', strtotime('+30 days')),
                'status' => 'published',
                'numbering' => 'global',
                'createdAt' => $now,
                'updatedAt' => $now,
                'groups' => [
                    [
                        'id' => 'group-001',
                        'title' => '基本アンケート',
                        'questions' => [
                            [
                                'id' => 'question-001',
                                'number' => 'Q1',
                                'text' => 'サービスの満足度を教えてください。',
                                'type' => 'single',
                                'required' => true,
                                'options' => [
                                    [
                                        'id' => 'option-001',
                                        'label' => '非常に満足',
                                        'nextQuestionId' => '',
                                    ],
                                    [
                                        'id' => 'option-002',
                                        'label' => '満足',
                                        'nextQuestionId' => '',
                                    ],
                                    [
                                        'id' => 'option-003',
                                        'label' => '普通',
                                        'nextQuestionId' => '',
                                    ],
                                    [
                                        'id' => 'option-004',
                                        'label' => '不満',
                                        'nextQuestionId' => '',
                                    ],
                                ],
                            ],
                            [
                                'id' => 'question-002',
                                'number' => 'Q2',
                                'text' => 'ご意見・ご要望があれば入力してください。',
                                'type' => 'text',
                                'required' => false,
                                'options' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'answers' => [],
        'customers' => [],
        'send_history' => [],
    ];
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
            'mapping' => [
                'organization' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
            'fields' => [],
            'last_test' => null,
            'last_sync' => null,
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
            'last_test' => null,
        ],
    ];
}

/* =========================================================
 * JSON
 * ========================================================= */

function load_json(string $file, array $fallback): array
{
    if (!is_file($file)) {
        return $fallback;
    }

    $fp = @fopen($file, 'rb');

    if ($fp === false) {
        return $fallback;
    }

    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($raw === false || trim($raw) === '') {
        return $fallback;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : $fallback;
}

function save_json(string $file, array $data): void
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException('データをJSON化できません。');
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));

    $fp = @fopen($tmp, 'wb');

    if ($fp === false) {
        throw new RuntimeException('一時保存ファイルを作成できません。');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('保存ファイルをロックできません。');
        }

        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('保存ファイルを更新できません。');
        }
    } catch (Throwable $e) {
        @fclose($fp);
        @unlink($tmp);
        throw $e;
    }
}

function load_data(): array
{
    $data = load_json(DATA_FILE, default_data());

    foreach (
        ['surveys', 'answers', 'customers', 'send_history']
        as $key
    ) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }

    return $data;
}

function save_data(array $data): void
{
    save_json(DATA_FILE, $data);
}

function load_settings(): array
{
    $default = default_settings();
    $settings = load_json(SETTINGS_FILE, $default);

    $settings['kintone'] = array_replace_recursive(
        $default['kintone'],
        is_array($settings['kintone'] ?? null)
            ? $settings['kintone']
            : []
    );

    $settings['mail'] = array_replace_recursive(
        $default['mail'],
        is_array($settings['mail'] ?? null)
            ? $settings['mail']
            : []
    );

    return $settings;
}

function save_settings(array $settings): void
{
    save_json(SETTINGS_FILE, $settings);
}

/* =========================================================
 * 入出力
 * ========================================================= */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function post_string(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_scalar($value)
        ? trim((string)$value)
        : '';
}

function get_string(string $key): string
{
    $value = $_GET[$key] ?? '';

    return is_scalar($value)
        ? trim((string)$value)
        : '';
}

function post_bool(string $key): bool
{
    return isset($_POST[$key])
        && in_array(
            (string)$_POST[$key],
            ['1', 'on', 'true'],
            true
        );
}

function app_url(array $params = []): string
{
    $base = (string)($_SERVER['SCRIPT_NAME'] ?? 'index.php');

    if (!$params) {
        return $base;
    }

    return $base
        . '?'
        . http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
}

function absolute_app_url(array $params = []): string
{
    $scheme = 'http';

    if (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443
    ) {
        $scheme = 'https';
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');

    if ($host === '') {
        throw new RuntimeException(
            'アンケートURLを生成できません。'
        );
    }

    return $scheme . '://' . $host . app_url($params);
}

function redirect_screen(string $screen, array $params = []): never
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

    $params = array_merge(
        ['screen' => $screen],
        $params
    );

    header(
        'Location: ' . app_url($params),
        true,
        303
    );

    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;

    unset($_SESSION['flash']);

    return is_array($flash)
        ? $flash
        : null;
}

/* =========================================================
 * 共通データ
 * ========================================================= */

function uuid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(6));
}

function survey_index(array $surveys, string $id): int
{
    foreach ($surveys as $i => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

function survey_by_id(array $surveys, string $id): ?array
{
    $index = survey_index($surveys, $id);

    return $index >= 0
        ? $surveys[$index]
        : null;
}

function auto_update_status(array &$survey): bool
{
    if (
        ($survey['status'] ?? '') === 'published'
        && !empty($survey['endAt'])
        && strtotime((string)$survey['endAt']) !== false
        && strtotime((string)$survey['endAt']) < time()
    ) {
        $survey['status'] = 'ended';
        return true;
    }

    return false;
}

function refresh_statuses(array &$data): bool
{
    $changed = false;

    foreach ($data['surveys'] as &$survey) {
        if (auto_update_status($survey)) {
            $survey['updatedAt'] = date('Y-m-d H:i:s');
            $changed = true;
        }
    }

    unset($survey);

    return $changed;
}

function recalc_numbers(array &$survey): void
{
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            if (($survey['numbering'] ?? 'global') === 'group') {
                $question['number'] =
                    'Q' . $groupNo . '-' . $questionNo;
            } else {
                $question['number'] = 'Q' . $global;
            }

            $global++;
            $questionNo++;
        }

        unset($question);

        $groupNo++;
    }

    unset($group);
}

function status_label(string $status): string
{
    switch ($status) {
        case 'published':
            return '公開中';

        case 'stopped':
            return '停止';

        case 'ended':
            return '終了';

        default:
            return '下書き';
    }
}

function status_class(string $status): string
{
    switch ($status) {
        case 'published':
            return 'success';

        case 'stopped':
            return 'warning';

        case 'ended':
            return 'gray';

        default:
            return 'gray';
    }
}

/* =========================================================
 * SMTP
 *
 * DATA処理を通常SMTPコマンドと分離する。
 * ここが今回の500 5.5.2再発防止の重要箇所。
 * ========================================================= */

function smtp_read_response($socket, array $expected): string
{
    $response = '';

    while (($line = fgets($socket)) !== false) {
        $response .= $line;

        if (!preg_match(
            '/^(\d{3})([ -])/',
            $line,
            $m
        )) {
            continue;
        }

        $code = (int)$m[1];

        if ($m[2] === ' ') {
            if (!in_array($code, $expected, true)) {
                throw new RuntimeException(
                    'SMTPエラー: '
                    . $code
                    . ' '
                    . trim($response)
                );
            }

            return $response;
        }
    }

    if ($response === '') {
        throw new RuntimeException(
            'SMTPから応答がありません。'
        );
    }

    throw new RuntimeException(
        'SMTP応答を正しく解析できませんでした。'
    );
}

function smtp_write_command(
    $socket,
    string $command,
    array $expected
): string {
    $command = str_replace(
        ["\r", "\n"],
        '',
        $command
    );

    $written = fwrite(
        $socket,
        $command . "\r\n"
    );

    if ($written === false) {
        throw new RuntimeException(
            'SMTPコマンドを送信できません。'
        );
    }

    return smtp_read_response(
        $socket,
        $expected
    );
}

function smtp_open(array $config)
{
    $host = trim((string)($config['host'] ?? ''));
    $port = (int)($config['port'] ?? 0);
    $encryption = (string)($config['encryption'] ?? 'none');

    if ($host === '') {
        throw new RuntimeException(
            'SMTPサーバを入力してください。'
        );
    }

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException(
            'SMTPポートが不正です。'
        );
    }

    if (!in_array(
        $encryption,
        ['ssl', 'tls', 'none'],
        true
    )) {
        throw new RuntimeException(
            '暗号化方式が不正です。'
        );
    }

    $scheme = '';

    if ($encryption === 'ssl') {
        $scheme = 'ssl://';
    }

    $errno = 0;
    $errstr = '';

    $socket = @fsockopen(
        $scheme . $host,
        $port,
        $errno,
        $errstr,
        CONNECT_TIMEOUT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPサーバへ接続できません。'
            . ' '
            . $errstr
        );
    }

    stream_set_timeout(
        $socket,
        READ_TIMEOUT
    );

    try {
        smtp_read_response(
            $socket,
            [220]
        );

        smtp_write_command(
            $socket,
            'EHLO localhost',
            [250]
        );

        if ($encryption === 'tls') {
            smtp_write_command(
                $socket,
                'STARTTLS',
                [220]
            );

            $crypto = @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'SMTP TLS接続を確立できません。'
                );
            }

            smtp_write_command(
                $socket,
                'EHLO localhost',
                [250]
            );
        }

        if (!empty($config['auth'])) {
            $username = (string)(
                $config['username'] ?? ''
            );

            $password = (string)(
                $config['password'] ?? ''
            );

            if ($username === '' || $password === '') {
                throw new RuntimeException(
                    'SMTP認証情報を入力してください。'
                );
            }

            smtp_write_command(
                $socket,
                'AUTH LOGIN',
                [334]
            );

            smtp_write_command(
                $socket,
                base64_encode($username),
                [334]
            );

            smtp_write_command(
                $socket,
                base64_encode($password),
                [235]
            );
        }

        return $socket;
    } catch (Throwable $e) {
        fclose($socket);
        throw $e;
    }
}

function smtp_send_data(
    $socket,
    string $message
): void {
    /*
     * SMTP DATAのドット・スタッフィング。
     * 各行先頭の "." を ".." にする。
     */
    $message = str_replace(
        ["\r\n", "\r"],
        "\n",
        $message
    );

    $lines = explode("\n", $message);
    $stuffed = [];

    foreach ($lines as $line) {
        if ($line !== '' && $line[0] === '.') {
            $line = '.' . $line;
        }

        $stuffed[] = $line;
    }

    $message = implode(
        "\r\n",
        $stuffed
    );

    /*
     * DATA本文は必ず
     *
     * message\r\n.\r\n
     *
     * で終了する。
     */
    if (!str_ends_with($message, "\r\n")) {
        $message .= "\r\n";
    }

    $message .= ".\r\n";

    $written = fwrite(
        $socket,
        $message
    );

    if ($written === false) {
        throw new RuntimeException(
            'SMTP本文を送信できません。'
        );
    }

    smtp_read_response(
        $socket,
        [250]
    );
}

function smtp_test(array $config): void
{
    $socket = smtp_open($config);

    try {
        smtp_write_command(
            $socket,
            'QUIT',
            [221]
        );
    } finally {
        fclose($socket);
    }
}

function smtp_send(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    if (!filter_var(
        $to,
        FILTER_VALIDATE_EMAIL
    )) {
        throw new RuntimeException(
            '送信先メールアドレスが不正です。'
        );
    }

    $from = trim(
        (string)($config['from_email'] ?? '')
    );

    if (!filter_var(
        $from,
        FILTER_VALIDATE_EMAIL
    )) {
        throw new RuntimeException(
            '送信元メールアドレスが不正です。'
        );
    }

    $replyTo = trim(
        (string)($config['reply_to'] ?? '')
    );

    if (
        $replyTo !== ''
        && !filter_var(
            $replyTo,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new RuntimeException(
            '返信先メールアドレスが不正です。'
        );
    }

    $fromName = trim(
        (string)($config['from_name'] ?? '')
    );

    if ($fromName === '') {
        $fromName = $from;
    }

    $socket = smtp_open($config);

    try {
        smtp_write_command(
            $socket,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        smtp_write_command(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        smtp_write_command(
            $socket,
            'DATA',
            [354]
        );

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . mb_encode_mimeheader(
                $fromName,
                'UTF-8',
                'B'
            ) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . mb_encode_mimeheader(
                $subject,
                'UTF-8',
                'B'
            ),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if ($replyTo !== '') {
            $headers[] =
                'Reply-To: ' . $replyTo;
        }

        $message =
            implode("\r\n", $headers)
            . "\r\n\r\n"
            . $body;

        smtp_send_data(
            $socket,
            $message
        );

        smtp_write_command(
            $socket,
            'QUIT',
            [221]
        );
    } finally {
        fclose($socket);
    }
}

/* =========================================================
 * kintone
 * ========================================================= */

function normalize_kintone_subdomain(
    string $value
): string {
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    ) ?? $value;

    $value = rtrim(
        $value,
        '/'
    );

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

    return $value;
}

function validate_kintone_config(
    array $config,
    bool $requirePassword = true
): array {
    $errors = [];

    $subdomain =
        normalize_kintone_subdomain(
            (string)($config['subdomain'] ?? '')
        );

    if (
        $subdomain === ''
        || !preg_match(
            '/^[a-zA-Z0-9][a-zA-Z0-9-]*$/',
            $subdomain
        )
    ) {
        $errors[] =
            'kintoneサブドメインが不正です。';
    }

    $appId = (string)(
        $config['app_id'] ?? ''
    );

    if (
        !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        $errors[] =
            'kintoneアプリIDが不正です。';
    }

    if (
        trim((string)(
            $config['username'] ?? ''
        )) === ''
    ) {
        $errors[] =
            'kintoneログイン名を入力してください。';
    }

    if (
        $requirePassword
        && trim((string)(
            $config['password'] ?? ''
        )) === ''
    ) {
        $errors[] =
            'kintoneパスワードを入力してください。';
    }

    $proxy = trim(
        (string)($config['proxy'] ?? '')
    );

    if (
        $proxy !== ''
        && !preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )
    ) {
        $errors[] =
            'Proxyはhost:port形式で入力してください。';
    }

    return $errors;
}

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $errors = validate_kintone_config(
        $config,
        true
    );

    if ($errors) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    $subdomain =
        normalize_kintone_subdomain(
            (string)$config['subdomain']
        );

    $base =
        'https://'
        . $subdomain
        . '.cybozu.com';

    $url = $base . $path;

    $username =
        (string)$config['username'];

    $password =
        (string)$config['password'];

    /*
     * kintone仕様:
     * login:passwordをBase64化して
     * X-Cybozu-Authorizationへ設定。
     */
    $authorization = base64_encode(
        $username . ':' . $password
    );

    $headers = [
        'X-Cybozu-Authorization: '
            . $authorization,
        'Accept: application/json',
    ];

    $content = '';

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            throw new RuntimeException(
                'kintoneリクエストJSONを生成できません。'
            );
        }

        $headers[] =
            'Content-Type: application/json';
    }

    $verify = !empty(
        $config['verify_ssl']
    );

    $contextOptions = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode(
                "\r\n",
                $headers
            ),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => READ_TIMEOUT,
            /*
             * kintone API処理で302を勝手に
             * 別URLへ追跡しない。
             */
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
            'peer_name' =>
                $subdomain . '.cybozu.com',
        ],
    ];

    $proxy = trim(
        (string)($config['proxy'] ?? '')
    );

    if ($proxy !== '') {
        [$proxyHost, $proxyPort] =
            explode(':', $proxy, 2);

        $contextOptions['http']['proxy'] =
            'tcp://'
            . $proxyHost
            . ':'
            . (int)$proxyPort;

        $contextOptions['http']['request_fulluri'] =
            true;
    }

    $context = stream_context_create(
        $contextOptions
    );

    /*
     * 認証ヘッダーそのものはログに出さない。
     */
    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    $status = 0;
    $responseHeaders = [];

    foreach (
        $http_response_header ?? []
        as $header
    ) {
        $responseHeaders[] = $header;

        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})/',
                $header,
                $m
            )
        ) {
            $status = (int)$m[1];
        }
    }

    $responseBody =
        $response === false
            ? ''
            : $response;

    $json = json_decode(
        $responseBody,
        true
    );

    if (
        $status < 200
        || $status >= 300
    ) {
        $code = '';
        $message = '';
        $location = '';

        if (is_array($json)) {
            $code = (string)(
                $json['code'] ?? ''
            );

            $message = (string)(
                $json['message'] ?? ''
            );
        }

        foreach ($responseHeaders as $header) {
            if (
                stripos(
                    $header,
                    'Location:'
                ) === 0
            ) {
                $location = trim(
                    substr(
                        $header,
                        strlen('Location:')
                    )
                );
                break;
            }
        }

        /*
         * 302を画面遷移成功と混同しない。
         */
        if (
            $status >= 300
            && $status < 400
        ) {
            $detail =
                'kintone APIがリダイレクトを返しました。'
                . ' HTTP '
                . $status;

            if ($location !== '') {
                $detail .=
                    ' Location: '
                    . $location;
            }

            $detail .=
                '。サブドメイン、Proxy、'
                . '公開URL、ネットワーク設定を確認してください。';

            throw new RuntimeException(
                $detail
            );
        }

        $detail =
            'kintone APIエラー'
            . ($code !== ''
                ? ' [' . $code . ']'
                : '')
            . ($message !== ''
                ? ' ' . $message
                : '')
            . ' HTTP '
            . $status;

        throw new RuntimeException(
            $detail
        );
    }

    return [
        'status' => $status,
        'body' =>
            is_array($json)
                ? $json
                : [],
        'raw' => $responseBody,
    ];
}

function kintone_test(array $config): array
{
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?id='
        . rawurlencode(
            (string)$config['app_id']
        )
    );
}

function kintone_fields(array $config): array
{
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app='
        . rawurlencode(
            (string)$config['app_id']
        )
    );
}

function kintone_records(
    array $config
): array {
    return kintone_request(
        $config,
        'GET',
        '/k/v1/records.json?app='
        . rawurlencode(
            (string)$config['app_id']
        )
        . '&totalCount=true'
    );
}

function normalize_kintone_fields(
    array $response
): array {
    $properties =
        $response['properties'] ?? [];

    if (!is_array($properties)) {
        return [];
    }

    $result = [];

    foreach (
        $properties as $code => $field
    ) {
        if (!is_array($field)) {
            continue;
        }

        $result[] = [
            'code' => (string)$code,
            'label' => (string)(
                $field['label'] ?? $code
            ),
            'type' => (string)(
                $field['type'] ?? ''
            ),
        ];
    }

    usort(
        $result,
        static function (
            array $a,
            array $b
        ): int {
            return strnatcasecmp(
                $a['code'],
                $b['code']
            );
        }
    );

    return $result;
}

function record_value(
    array $record,
    string $code
): string {
    if (
        $code === ''
        || !isset($record[$code])
        || !is_array($record[$code])
    ) {
        return '';
    }

    $value =
        $record[$code]['value'] ?? '';

    if (!is_array($value)) {
        return (string)$value;
    }

    $parts = [];

    foreach ($value as $item) {
        if (is_array($item)) {
            if (isset($item['name'])) {
                $parts[] =
                    (string)$item['name'];
            } elseif (isset($item['value'])) {
                $parts[] =
                    (string)$item['value'];
            }
        } else {
            $parts[] = (string)$item;
        }
    }

    return implode(
        ' ',
        array_filter(
            $parts,
            static fn($v): bool => $v !== ''
        )
    );
}

/* =========================================================
 * アンケート入力
 * ========================================================= */

function validate_survey(): array
{
    $errors = [];

    $title = post_string('title');
    $description = post_string('description');
    $startAt = post_string('startAt');
    $endAt = post_string('endAt');
    $numbering = post_string('numbering');

    if ($title === '') {
        $errors[] =
            'アンケートタイトルは必須です。';
    } elseif (
        mb_strlen($title) > MAX_TITLE
    ) {
        $errors[] =
            'アンケートタイトルが長すぎます。';
    }

    if (
        mb_strlen($description)
        > MAX_DESCRIPTION
    ) {
        $errors[] =
            'アンケート説明が長すぎます。';
    }

    if (!in_array(
        $numbering,
        ['global', 'group'],
        true
    )) {
        $errors[] =
            '質問番号の採番方式が不正です。';
    }

    if (
        $startAt !== ''
        && strtotime($startAt) === false
    ) {
        $errors[] =
            '開始日時が不正です。';
    }

    if (
        $endAt !== ''
        && strtotime($endAt) === false
    ) {
        $errors[] =
            '終了日時が不正です。';
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && strtotime($startAt) !== false
        && strtotime($endAt) !== false
        && strtotime($endAt) < strtotime($startAt)
    ) {
        $errors[] =
            '終了日時は開始日時以降にしてください。';
    }

    return [
        'errors' => $errors,
        'title' => $title,
        'description' => $description,
        'startAt' => $startAt,
        'endAt' => $endAt,
        'numbering' => $numbering,
    ];
}

function build_survey_from_post(
    array $existing
): array {
    $survey = $existing;

    $survey['title'] =
        post_string('title');

    $survey['description'] =
        post_string('description');

    $survey['startAt'] =
        post_string('startAt');

    $survey['endAt'] =
        post_string('endAt');

    $numbering =
        post_string('numbering');

    if (!in_array(
        $numbering,
        ['global', 'group'],
        true
    )) {
        $numbering = 'global';
    }

    $survey['numbering'] =
        $numbering;

    /*
     * 状態はフォームから明示的に受け取る。
     * GETアクセスで勝手に変えない。
     */
    $postedStatus =
        post_string('status');

    if (
        in_array(
            $postedStatus,
            ['draft', 'published', 'stopped'],
            true
        )
    ) {
        if (
            ($existing['status'] ?? 'draft')
            !== 'ended'
        ) {
            $survey['status'] =
                $postedStatus;
        }
    }

    $groupOrder =
        $_POST['group_order'] ?? [];

    $groupTitles =
        $_POST['group_title'] ?? [];

    $questionsByGroup =
        $_POST['questions_by_group']
        ?? [];

    $questionTexts =
        $_POST['question_text']
        ?? [];

    $questionTypes =
        $_POST['question_type']
        ?? [];

    $questionRequired =
        $_POST['question_required']
        ?? [];

    $questionOptions =
        $_POST['question_option']
        ?? [];

    $branching =
        $_POST['branching']
        ?? [];

    if (!is_array($groupOrder)) {
        $groupOrder = [];
    }

    if (!$groupOrder) {
        $groupOrder = [
            uuid('group'),
        ];
    }

    $groups = [];

    foreach ($groupOrder as $groupId) {
        $groupId = trim((string)$groupId);

        if ($groupId === '') {
            continue;
        }

        $title =
            is_array($groupTitles)
            ? trim(
                (string)(
                    $groupTitles[$groupId]
                    ?? '新しいグループ'
                )
            )
            : '新しいグループ';

        if ($title === '') {
            $title = '新しいグループ';
        }

        $group = [
            'id' => $groupId,
            'title' => $title,
            'questions' => [],
        ];

        $questionIds =
            is_array($questionsByGroup)
            && isset(
                $questionsByGroup[$groupId]
            )
            && is_array(
                $questionsByGroup[$groupId]
            )
            ? $questionsByGroup[$groupId]
            : [];

        foreach ($questionIds as $questionId) {
            $questionId =
                trim((string)$questionId);

            if ($questionId === '') {
                continue;
            }

            $type =
                is_array($questionTypes)
                ? (string)(
                    $questionTypes[$questionId]
                    ?? 'text'
                )
                : 'text';

            if (!in_array(
                $type,
                ['single', 'multiple', 'text'],
                true
            )) {
                $type = 'text';
            }

            $options = [];

            if (
                $type === 'single'
                || $type === 'multiple'
            ) {
                $rawOptions =
                    is_array($questionOptions)
                    && isset(
                        $questionOptions[$questionId]
                    )
                    && is_array(
                        $questionOptions[$questionId]
                    )
                    ? $questionOptions[$questionId]
                    : [];

                foreach (
                    $rawOptions as $optionId => $label
                ) {
                    $label =
                        mb_substr(
                            trim((string)$label),
                            0,
                            MAX_OPTION
                        );

                    if ($label === '') {
                        continue;
                    }

                    $options[] = [
                        'id' => (string)$optionId,
                        'label' => $label,
                        'nextQuestionId' =>
                            is_array($branching)
                            ? (string)(
                                $branching[
                                    $questionId
                                ][
                                    (string)$optionId
                                ] ?? ''
                            )
                            : '',
                    ];
                }
            }

            $group['questions'][] = [
                'id' => $questionId,
                'number' => '',
                'text' =>
                    mb_substr(
                        trim(
                            (string)(
                                $questionTexts[
                                    $questionId
                                ] ?? ''
                            )
                        ),
                        0,
                        MAX_QUESTION
                    ),
                'type' => $type,
                'required' =>
                    is_array($questionRequired)
                    && isset(
                        $questionRequired[
                            $questionId
                        ]
                    ),
                'options' => $options,
            ];
        }

        $groups[] = $group;
    }

    $survey['groups'] = $groups;

    recalc_numbers($survey);

    return $survey;
}

/* =========================================================
 * POST処理
 * ========================================================= */

function handle_post(
    array &$data,
    array &$settings
): ?array {
    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        !== 'POST'
    ) {
        return null;
    }

    $action =
        post_string('action');

    try {
        switch ($action) {

            /* -----------------------------------------
             * アンケート保存
             * ----------------------------------------- */

            case 'save_survey':
                $input =
                    validate_survey();

                if ($input['errors']) {
                    flash(
                        'error',
                        implode(
                            "\n",
                            $input['errors']
                        )
                    );

                    return [
                        'screen' => 'edit',
                        'id' =>
                            post_string('survey_id')
                    ];
                }

                $id =
                    post_string('survey_id');

                $index =
                    survey_index(
                        $data['surveys'],
                        $id
                    );

                if ($index < 0) {
                    $survey = [
                        'id' =>
                            $id !== ''
                            ? $id
                            : uuid('survey'),
                        'title' =>
                            $input['title'],
                        'description' =>
                            $input['description'],
                        'startAt' =>
                            $input['startAt'],
                        'endAt' =>
                            $input['endAt'],
                        'status' => 'draft',
                        'numbering' =>
                            $input['numbering'],
                        'createdAt' =>
                            date('Y-m-d H:i:s'),
                        'updatedAt' =>
                            date('Y-m-d H:i:s'),
                        'groups' => [],
                    ];
                } else {
                    $survey =
                        $data['surveys'][$index];
                }

                $survey =
                    build_survey_from_post(
                        $survey
                    );

                $survey['updatedAt'] =
                    date('Y-m-d H:i:s');

                if ($index < 0) {
                    $data['surveys'][] =
                        $survey;
                } else {
                    $data['surveys'][$index] =
                        $survey;
                }

                save_data($data);

                flash(
                    'success',
                    'アンケートを保存しました。'
                );

                return [
                    'screen' => 'list'
                ];

            /* -----------------------------------------
             * グループ追加
             * ----------------------------------------- */

            case 'add_group':
                $id =
                    post_string('survey_id');

                $index =
                    survey_index(
                        $data['surveys'],
                        $id
                    );

                if ($index < 0) {
                    flash(
                        'error',
                        '対象アンケートが見つかりません。'
                    );

                    return [
                        'screen' => 'list'
                    ];
                }

                $survey =
                    $data['surveys'][$index];

                if (
                    ($survey['status'] ?? '')
                    === 'ended'
                ) {
                    flash(
                        'error',
                        '終了したアンケートは編集できません。'
                    );

                    return [
                        'screen' => 'edit',
                        'id' => $id
                    ];
                }

                $survey['groups'][] = [
                    'id' => uuid('group'),
                    'title' => '新しいグループ',
                    'questions' => [],
                ];

                recalc_numbers($survey);

                $survey['updatedAt'] =
                    date('Y-m-d H:i:s');

                $data['surveys'][$index] =
                    $survey;

                save_data($data);

                flash(
                    'success',
                    'グループを追加しました。'
                );

                return [
                    'screen' => 'edit',
                    'id' => $id
                ];

            /* -----------------------------------------
             * 質問追加
             * ----------------------------------------- */

            case 'add_question':
                $id =
                    post_string('survey_id');

                $groupId =
                    post_string('group_id');

                $index =
                    survey_index(
                        $data['surveys'],
                        $id
                    );

                if ($index < 0) {
                    flash(
                        'error',
                        '対象アンケートが見つかりません。'
                    );

                    return [
                        'screen' => 'list'
                    ];
                }

                $survey =
                    $data['surveys'][$index];

                if (
                    ($survey['status'] ?? '')
                    === 'ended'
                ) {
                    flash(
                        'error',
                        '終了したアンケートは編集できません。'
                    );

                    return [
                        'screen' => 'edit',
                        'id' => $id
                    ];
                }

                $found = false;

                foreach (
                    $survey['groups']
                    as &$group
                ) {
                    if (
                        ($group['id'] ?? '')
                        !== $groupId
                    ) {
                        continue;
                    }

                    $group['questions'][] = [
                        'id' => uuid('question'),
                        'number' => '',
                        'text' =>
                            '新しい質問',
                        'type' => 'single',
                        'required' => false,
                        'options' => [
                            [
                                'id' =>
                                    uuid('option'),
                                'label' =>
                                    '選択肢1',
                                'nextQuestionId' =>
                                    '',
                            ],
                            [
                                'id' =>
                                    uuid('option'),
                                'label' =>
                                    '選択肢2',
                                'nextQuestionId' =>
                                    '',
                            ],
                        ],
                    ];

                    $found = true;
                    break;
                }

                unset($group);

                if (!$found) {
                    flash(
                        'error',
                        'グループが見つかりません。'
                    );

                    return [
                        'screen' => 'edit',
                        'id' => $id
                    ];
                }

                recalc_numbers($survey);

                $survey['updatedAt'] =
                    date('Y-m-d H:i:s');

                $data['surveys'][$index] =
                    $survey;

                save_data($data);

                flash(
                    'success',
                    '質問を追加しました。'
                );

                return [
                    'screen' => 'edit',
                    'id' => $id
                ];

            /* -----------------------------------------
             * 状態変更
             * ----------------------------------------- */

            case 'change_status':
                $id =
                    post_string('survey_id');

                $newStatus =
                    post_string('new_status');

                if (!in_array(
                    $newStatus,
                    ['draft', 'published', 'stopped'],
                    true
                )) {
                    flash(
                        'error',
                        '指定された状態は変更できません。'
                    );

                    return [
                        'screen' => 'edit',
                        'id' => $id
                    ];
                }

                $index =
                    survey_index(
                        $data['surveys'],
                        $id
                    );

                if ($index < 0) {
                    flash(
                        'error',
                        '対象アンケートが見つかりません。'
                    );

                    return [
                        'screen' => 'list'
                    ];
                }

                if (
                    ($data['surveys'][$index]['status']
                        ?? '')
                    === 'ended'
                ) {
                    flash(
                        'error',
                        '終了したアンケートの状態は変更できません。'
                    );

                    return [
                        'screen' => 'edit',
                        'id' => $id
                    ];
                }

                $data['surveys'][$index]['status'] =
                    $newStatus;

                $data['surveys'][$index]['updatedAt'] =
                    date('Y-m-d H:i:s');

                save_data($data);

                flash(
                    'success',
                    '状態を「'
                    . status_label($newStatus)
                    . '」へ変更しました。'
                );

                return [
                    'screen' => 'edit',
                    'id' => $id
                ];

            /* -----------------------------------------
             * 削除
             * ----------------------------------------- */

            case 'delete_survey':
                $id =
                    post_string('survey_id');

                $index =
                    survey_index(
                        $data['surveys'],
                        $id
                    );

                if ($index >= 0) {
                    array_splice(
                        $data['surveys'],
                        $index,
                        1
                    );

                    save_data($data);

                    flash(
                        'success',
                        'アンケートを削除しました。'
                    );
                }

                return [
                    'screen' => 'list'
                ];

            /* -----------------------------------------
             * 複製
             * ----------------------------------------- */

            case 'duplicate_survey':
                $id =
                    post_string('survey_id');

                $survey =
                    survey_by_id(
                        $data['surveys'],
                        $id
                    );

                if ($survey === null) {
                    flash(
                        'error',
                        '対象アンケートが見つかりません。'
                    );

                    return [
                        'screen' => 'list'
                    ];
                }

                $survey['id'] =
                    uuid('survey');

                $survey['title'] =
                    $survey['title']
                    . '（コピー）';

                $survey['status'] =
                    'draft';

                $survey['createdAt'] =
                    date('Y-m-d H:i:s');

                $survey['updatedAt'] =
                    date('Y-m-d H:i:s');

                $data['surveys'][] =
                    $survey;

                save_data($data);

                flash(
                    'success',
                    'アンケートを複製しました。'
                );

                return [
                    'screen' => 'list'
                ];

            /* -----------------------------------------
             * kintone設定保存
             * ----------------------------------------- */

            case 'save_kintone':
                $current =
                    $settings['kintone'];

                $password =
                    post_string('password');

                if ($password === '') {
                    $password =
                        (string)(
                            $current['password']
                            ?? ''
                        );
                }

                $config = [
                    'subdomain' =>
                        normalize_kintone_subdomain(
                            post_string('subdomain')
                        ),
                    'app_id' =>
                        post_string('app_id'),
                    'username' =>
                        post_string('username'),
                    'password' =>
                        $password,
                    'proxy' =>
                        post_string('proxy'),
                    'verify_ssl' =>
                        post_bool('verify_ssl'),
                    'mapping' =>
                        $current['mapping'] ?? [],
                    'fields' =>
                        $current['fields'] ?? [],
                    'last_test' =>
                        $current['last_test'] ?? null,
                    'last_sync' =>
                        $current['last_sync'] ?? null,
                ];

                $errors =
                    validate_kintone_config(
                        $config,
                        true
                    );

                if ($errors) {
                    flash(
                        'error',
                        implode(
                            "\n",
                            $errors
                        )
                    );

                    return [
                        'screen' => 'kintone'
                    ];
                }

                $settings['kintone'] =
                    $config;

                save_settings($settings);

                flash(
                    'success',
                    'kintone設定を保存しました。'
                );

                return [
                    'screen' => 'kintone'
                ];

            /* -----------------------------------------
             * kintone接続テスト
             * ----------------------------------------- */

            case 'test_kintone':
                $config =
                    $settings['kintone'];

                $password =
                    post_string('password');

                if ($password !== '') {
                    $config['password'] =
                        $password;
                }

                $errors =
                    validate_kintone_config(
                        $config,
                        true
                    );

                if ($errors) {
                    flash(
                        'error',
                        implode(
                            "\n",
                            $errors
                        )
                    );

                    return [
                        'screen' => 'kintone'
                    ];
                }

                try {
                    $result =
                        kintone_test(
                            $config
                        );

                    $settings['kintone'][
                        'last_test'
                    ] =
                        date('Y-m-d H:i:s');

                    /*
                     * テスト時に入力されたパスワードは
                     * サーバー側設定へ保存する。
                     * HTMLには再出力しない。
                     */
                    if ($password !== '') {
                        $settings['kintone'][
                            'password'
                        ] = $password;
                    }

                    save_settings($settings);

                    flash(
                        'success',
                        'kintone接続成功。HTTP '
                        . $result['status']
                    );
                } catch (Throwable $e) {
                    flash(
                        'error',
                        'kintone接続失敗：'
                        . $e->getMessage()
                    );
                }

                return [
                    'screen' => 'kintone'
                ];

            /* -----------------------------------------
             * kintone項目取得
             * ----------------------------------------- */

            case 'fetch_kintone_fields':
                try {
                    $result =
                        kintone_fields(
                            $settings['kintone']
                        );

                    $fields =
                        normalize_kintone_fields(
                            $result['body']
                        );

                    if (!$fields) {
                        throw new RuntimeException(
                            'kintoneから項目を取得できませんでした。'
                        );
                    }

                    $settings['kintone'][
                        'fields'
                    ] = $fields;

                    save_settings($settings);

                    flash(
                        'success',
                        count($fields)
                        . '件の項目を取得しました。'
                    );
                } catch (Throwable $e) {
                    flash(
                        'error',
                        'kintone項目取得失敗：'
                        . $e->getMessage()
                    );
                }

                return [
                    'screen' => 'kintone'
                ];

            /* -----------------------------------------
             * kintoneマッピング
             * ----------------------------------------- */

            case 'save_kintone_mapping':
                $fields =
                    $settings['kintone']['fields']
                    ?? [];

                $validCodes = [];

                foreach ($fields as $field) {
                    if (isset($field['code'])) {
                        $validCodes[] =
                            (string)$field['code'];
                    }
                }

                $mapping = [
                    'organization' =>
                        post_string(
                            'mapping_organization'
                        ),
                    'name' =>
                        post_string(
                            'mapping_name'
                        ),
                    'email' =>
                        post_string(
                            'mapping_email'
                        ),
                    'department' =>
                        post_string(
                            'mapping_department'
                        ),
                    'phone' =>
                        post_string(
                            'mapping_phone'
                        ),
                    'address' => [],
                ];

                $address =
                    $_POST['mapping_address']
                    ?? [];

                if (is_array($address)) {
                    foreach ($address as $code) {
                        $code =
                            trim((string)$code);

                        if (
                            $code !== ''
                            && in_array(
                                $code,
                                $validCodes,
                                true
                            )
                        ) {
                            $mapping['address'][] =
                                $code;
                        }
                    }
                }

                foreach (
                    [
                        'organization',
                        'name',
                        'email',
                        'department',
                        'phone'
                    ] as $key
                ) {
                    if (
                        $mapping[$key] !== ''
                        && !in_array(
                            $mapping[$key],
                            $validCodes,
                            true
                        )
                    ) {
                        $mapping[$key] = '';
                    }
                }

                $settings['kintone'][
                    'mapping'
                ] = $mapping;

                save_settings($settings);

                flash(
                    'success',
                    'kintone項目マッピングを保存しました。'
                );

                return [
                    'screen' => 'kintone'
                ];

            /* -----------------------------------------
             * kintone同期
             * ----------------------------------------- */

            case 'sync_kintone':
                try {
                    $result =
                        kintone_records(
                            $settings['kintone']
                        );

                    $records =
                        $result['body']['records']
                        ?? [];

                    $mapping =
                        $settings['kintone']['mapping']
                        ?? [];

                    $customers = [];

                    foreach ($records as $record) {
                        $addressParts = [];

                        foreach (
                            ($mapping['address'] ?? [])
                            as $code
                        ) {
                            $value =
                                record_value(
                                    $record,
                                    (string)$code
                                );

                            if ($value !== '') {
                                $addressParts[] =
                                    $value;
                            }
                        }

                        $customers[] = [
                            'id' =>
                                uuid('customer'),
                            'organization' =>
                                record_value(
                                    $record,
                                    (string)(
                                        $mapping[
                                            'organization'
                                        ] ?? ''
                                    )
                                ),
                            'name' =>
                                record_value(
                                    $record,
                                    (string)(
                                        $mapping[
                                            'name'
                                        ] ?? ''
                                    )
                                ),
                            'email' =>
                                record_value(
                                    $record,
                                    (string)(
                                        $mapping[
                                            'email'
                                        ] ?? ''
                                    )
                                ),
                            'department' =>
                                record_value(
                                    $record,
                                    (string)(
                                        $mapping[
                                            'department'
                                        ] ?? ''
                                    )
                                ),
                            'phone' =>
                                record_value(
                                    $record,
                                    (string)(
                                        $mapping[
                                            'phone'
                                        ] ?? ''
                                    )
                                ),
                            'address' =>
                                implode(
                                    ' ',
                                    $addressParts
                                ),
                        ];
                    }

                    $data['customers'] =
                        $customers;

                    $settings['kintone'][
                        'last_sync'
                    ] =
                        date('Y-m-d H:i:s');

                    save_data($data);
                    save_settings($settings);

                    flash(
                        'success',
                        count($customers)
                        . '件の顧客情報を同期しました。'
                    );
                } catch (Throwable $e) {
                    flash(
                        'error',
                        'kintone同期失敗：'
                        . $e->getMessage()
                    );
                }

                return [
                    'screen' => 'kintone'
                ];

            /* -----------------------------------------
             * SMTP設定保存
             * ----------------------------------------- */

            case 'save_mail':
                $current =
                    $settings['mail'];

                $host =
                    post_string('server');

                $port =
                    (int)post_string('port');

                $encryption =
                    post_string('encryption');

                $auth =
                    post_bool('auth');

                $username =
                    post_string('username');

                $password =
                    post_string('password');

                if ($password === '') {
                    $password =
                        (string)(
                            $current['password']
                            ?? ''
                        );
                }

                $fromEmail =
                    post_string('from_email');

                $fromName =
                    post_string('from_name');

                $replyTo =
                    post_string('reply_to');

                $errors = [];

                if ($host === '') {
                    $errors[] =
                        'SMTPサーバを入力してください。';
                }

                if (
                    $port < 1
                    || $port > 65535
                ) {
                    $errors[] =
                        'SMTPポートが不正です。';
                }

                if (!in_array(
                    $encryption,
                    ['ssl', 'tls', 'none'],
                    true
                )) {
                    $errors[] =
                        '暗号化方式が不正です。';
                }

                if (
                    $auth
                    && (
                        $username === ''
                        || $password === ''
                    )
                ) {
                    $errors[] =
                        'SMTP認証情報を入力してください。';
                }

                if (!filter_var(
                    $fromEmail,
                    FILTER_VALIDATE_EMAIL
                )) {
                    $errors[] =
                        '送信元メールアドレスが不正です。';
                }

                if (
                    $replyTo !== ''
                    && !filter_var(
                        $replyTo,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    $errors[] =
                        '返信先メールアドレスが不正です。';
                }

                if ($errors) {
                    flash(
                        'error',
                        implode(
                            "\n",
                            $errors
                        )
                    );

                    return [
                        'screen' => 'mail'
                    ];
                }

                $settings['mail'] = [
                    'host' => $host,
                    'port' => $port,
                    'encryption' =>
                        $encryption,
                    'auth' => $auth,
                    'username' => $username,
                    'password' => $password,
                    'from_email' =>
                        $fromEmail,
                    'from_name' =>
                        $fromName,
                    'reply_to' =>
                        $replyTo,
                    'last_test' =>
                        $current['last_test']
                        ?? null,
                ];

                save_settings($settings);

                flash(
                    'success',
                    'SMTP設定を保存しました。'
                );

                return [
                    'screen' => 'mail'
                ];

            /* -----------------------------------------
             * SMTP接続テスト
             * ----------------------------------------- */

            case 'test_mail':
                try {
                    smtp_test(
                        $settings['mail']
                    );

                    $settings['mail'][
                        'last_test'
                    ] =
                        date('Y-m-d H:i:s');

                    save_settings($settings);

                    flash(
                        'success',
                        'SMTP接続テストに成功しました。'
                    );
                } catch (Throwable $e) {
                    flash(
                        'error',
                        'SMTP接続テスト失敗：'
                        . $e->getMessage()
                    );
                }

                return [
                    'screen' => 'mail'
                ];

            /* -----------------------------------------
             * テストメール
             * ----------------------------------------- */

            case 'send_test_mail':
                $to =
                    post_string('test_email');

                try {
                    smtp_send(
                        $settings['mail'],
                        $to,
                        'アンケートアプリ テストメール',
                        'SMTP設定のテストメールです。'
                    );

                    flash(
                        'success',
                        'テストメールを送信しました。'
                    );
                } catch (Throwable $e) {
                    flash(
                        'error',
                        'テストメール送信失敗：'
                        . $e->getMessage()
                    );
                }

                return [
                    'screen' => 'mail'
                ];

            /* -----------------------------------------
             * アンケートメール送信
             * ----------------------------------------- */

            case 'send_mail':
                $surveyId =
                    post_string('survey_id');

                $survey =
                    survey_by_id(
                        $data['surveys'],
                        $surveyId
                    );

                if ($survey === null) {
                    flash(
                        'error',
                        '対象アンケートが見つかりません。'
                    );

                    return [
                        'screen' => 'list'
                    ];
                }

                $selected =
                    $_POST['customer_ids']
                    ?? [];

                if (!is_array($selected)) {
                    $selected = [];
                }

                $subject =
                    post_string('subject');

                $body =
                    post_string('body');

                if (
                    $subject === ''
                    || trim($body) === ''
                ) {
                    flash(
                        'error',
                        'メール件名と本文を入力してください。'
                    );

                    return [
                        'screen' => 'send',
                        'id' => $surveyId
                    ];
                }

                $customerMap = [];

                foreach (
                    $data['customers']
                    as $customer
                ) {
                    $customerMap[
                        (string)(
                            $customer['id']
                            ?? ''
                        )
                    ] = $customer;
                }

                $sent = 0;
                $failed = 0;

                foreach ($selected as $customerId) {
                    $customer =
                        $customerMap[
                            (string)$customerId
                        ]
                        ?? null;

                    if ($customer === null) {
                        $failed++;
                        continue;
                    }

                    /*
                     * URIだけではなく、
                     * 実際にクリックできる絶対URLを生成。
                     */
                    $url =
                        absolute_app_url([
                            'screen' => 'answer',
                            'id' =>
                                $surveyId,
                        ]);

                    $mailBody =
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
                                $url,
                            ],
                            $body
                        );

                    $result = '送信成功';

                    try {
                        smtp_send(
                            $settings['mail'],
                            (string)(
                                $customer['email']
                                ?? ''
                            ),
                            $subject,
                            $mailBody
                        );

                        $sent++;
                    } catch (Throwable $e) {
                        $result = '送信失敗';
                        $failed++;
                    }

                    $data['send_history'][] = [
                        'id' =>
                            uuid('send'),
                        'survey_id' =>
                            $surveyId,
                        'customer_id' =>
                            (string)$customerId,
                        'customer_name' =>
                            (string)(
                                $customer['name']
                                ?? ''
                            ),
                        'type' =>
                            '一括送信',
                        'result' =>
                            $result,
                        'createdAt' =>
                            date('Y-m-d H:i:s'),
                    ];
                }

                save_data($data);

                flash(
                    $failed > 0
                        ? 'warning'
                        : 'success',
                    '送信結果：成功 '
                    . $sent
                    . '件 / 失敗 '
                    . $failed
                    . '件'
                );

                return [
                    'screen' => 'send',
                    'id' => $surveyId
                ];

            /* -----------------------------------------
             * 回答入力
             * ----------------------------------------- */

            case 'save_answer':
                $surveyId =
                    post_string('survey_id');

                $survey =
                    survey_by_id(
                        $data['surveys'],
                        $surveyId
                    );

                if ($survey === null) {
                    flash(
                        'error',
                        'アンケートが見つかりません。'
                    );

                    return [
                        'screen' => 'list'
                    ];
                }

                $_SESSION[
                    'answer_draft'
                ] = [
                    'survey_id' =>
                        $surveyId,
                    'answers' =>
                        $_POST['answer']
                        ?? [],
                ];

                return [
                    'screen' => 'confirm',
                    'id' => $surveyId
                ];

            /* -----------------------------------------
             * 回答送信
             * ----------------------------------------- */

            case 'submit_answer':
                $draft =
                    $_SESSION[
                        'answer_draft'
                    ]
                    ?? null;

                if (!is_array($draft)) {
                    flash(
                        'error',
                        '回答情報がありません。'
                    );

                    return [
                        'screen' => 'list'
                    ];
                }

                $surveyId =
                    (string)(
                        $draft['survey_id']
                        ?? ''
                    );

                $survey =
                    survey_by_id(
                        $data['surveys'],
                        $surveyId
                    );

                if ($survey === null) {
                    flash(
                        'error',
                        'アンケートが見つかりません。'
                    );

                    return [
                        'screen' => 'list'
                    ];
                }

                $data['answers'][] = [
                    'id' =>
                        uuid('answer'),
                    'survey_id' =>
                        $surveyId,
                    'answers' =>
                        is_array(
                            $draft['answers']
                            ?? null
                        )
                        ? $draft['answers']
                        : [],
                    'createdAt' =>
                        date('Y-m-d H:i:s'),
                ];

                save_data($data);

                unset(
                    $_SESSION['answer_draft']
                );

                return [
                    'screen' => 'complete',
                    'id' => $surveyId
                ];

            default:
                return null;
        }
    } catch (Throwable $e) {
        flash(
            'error',
            '処理に失敗しました：'
            . $e->getMessage()
        );

        return null;
    }
}

/* =========================================================
 * HTML共通
 * ========================================================= */

function render_head(
    string $title,
    bool $admin = true
): void {
    $flash = consume_flash();
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - <?= h(APP_TITLE) ?></title>

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
 font-family:
 -apple-system,
 BlinkMacSystemFont,
 "Segoe UI",
 "Noto Sans JP",
 "Hiragino Kaku Gothic ProN",
 Meiryo,
 sans-serif;
}
a{color:var(--primary);text-decoration:none}
button,input,select,textarea{
 font:inherit;
}
.admin-header{
 background:#0f172a;
 color:#fff;
 padding:14px 22px;
}
.header-inner{
 max-width:1400px;
 margin:auto;
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:16px;
}
.brand{
 color:#fff;
 font-weight:700;
 font-size:18px;
}
.nav{
 display:flex;
 flex-wrap:wrap;
 gap:6px;
}
.nav a{
 color:#cbd5e1;
 padding:8px 10px;
 border-radius:7px;
}
.nav a:hover{
 background:#1e293b;
 color:#fff;
}
.container{
 max-width:1400px;
 margin:0 auto;
 padding:28px 20px 60px;
}
.page-title{
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:16px;
 margin-bottom:20px;
}
.page-title h1{
 margin:0;
 font-size:25px;
}
.card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:12px;
 box-shadow:var(--shadow);
 margin-bottom:20px;
}
.card-body{padding:22px}
.card-title{
 font-size:18px;
 font-weight:700;
 margin:0 0 16px;
}
.grid{
 display:grid;
 grid-template-columns:repeat(2,minmax(0,1fr));
 gap:16px;
}
.field{margin-bottom:15px}
.field label{
 display:block;
 font-weight:600;
 margin-bottom:7px;
}
input[type=text],
input[type=email],
input[type=password],
input[type=number],
input[type=datetime-local],
select,
textarea{
 width:100%;
 border:1px solid var(--border);
 border-radius:8px;
 padding:10px 12px;
 background:#fff;
 color:var(--text);
}
textarea{
 min-height:110px;
 resize:vertical;
}
.btn{
 display:inline-flex;
 align-items:center;
 justify-content:center;
 gap:6px;
 border:0;
 border-radius:8px;
 padding:9px 14px;
 cursor:pointer;
 font-weight:600;
 text-decoration:none;
}
.btn-primary{
 background:var(--primary);
 color:#fff;
}
.btn-primary:hover{
 background:var(--primary-dark);
}
.btn-secondary{
 background:#e2e8f0;
 color:#1e293b;
}
.btn-success{
 background:var(--success);
 color:#fff;
}
.btn-warning{
 background:var(--warning);
 color:#fff;
}
.btn-danger{
 background:var(--danger);
 color:#fff;
}
.btn-light{
 background:#fff;
 color:var(--text);
 border:1px solid var(--border);
}
.button-row{
 display:flex;
 flex-wrap:wrap;
 gap:8px;
 align-items:center;
}
.alert{
 padding:13px 16px;
 border-radius:9px;
 margin-bottom:18px;
 white-space:pre-line;
}
.alert-success{
 background:#dcfce7;
 color:#166534;
 border:1px solid #bbf7d0;
}
.alert-error{
 background:#fee2e2;
 color:#991b1b;
 border:1px solid #fecaca;
}
.alert-warning{
 background:#fef3c7;
 color:#92400e;
 border:1px solid #fde68a;
}
.badge{
 display:inline-block;
 padding:4px 9px;
 border-radius:999px;
 font-size:12px;
 font-weight:700;
}
.badge.success{
 background:#dcfce7;
 color:#166534;
}
.badge.warning{
 background:#fef3c7;
 color:#92400e;
}
.badge.gray{
 background:#e2e8f0;
 color:#475569;
}
.table-wrap{
 overflow-x:auto;
}
table{
 width:100%;
 border-collapse:collapse;
 min-width:1000px;
}
th,td{
 padding:12px;
 border-bottom:1px solid var(--border);
 text-align:left;
 vertical-align:middle;
}
th{
 background:#f8fafc;
 font-size:13px;
}
.actions{
 display:flex;
 flex-wrap:wrap;
 gap:5px;
}
.question{
 border:1px solid var(--border);
 border-radius:10px;
 padding:16px;
 margin-top:12px;
 background:#fff;
}
.question.dragging,
.group.dragging{
 opacity:.45;
}
.drag-handle{
 cursor:grab;
 color:var(--gray);
 user-select:none;
}
.group{
 border:1px solid var(--border);
 border-radius:12px;
 padding:16px;
 margin-bottom:18px;
 background:#f8fafc;
}
.group-head{
 display:flex;
 gap:10px;
 align-items:center;
 margin-bottom:12px;
}
.group-title{
 flex:1;
}
.option-row{
 display:grid;
 grid-template-columns:1fr 180px auto;
 gap:8px;
 margin-top:8px;
}
.check{
 display:flex;
 gap:7px;
 align-items:center;
}
.kintone-map{
 display:grid;
 grid-template-columns:180px 1fr;
 gap:10px 16px;
 align-items:center;
}
.address-fields{
 display:grid;
 grid-template-columns:repeat(2,minmax(0,1fr));
 gap:8px;
}
.stat-grid{
 display:grid;
 grid-template-columns:repeat(4,minmax(0,1fr));
 gap:14px;
}
.stat{
 background:#fff;
 border:1px solid var(--border);
 border-radius:10px;
 padding:18px;
 box-shadow:var(--shadow);
}
.stat-label{
 color:var(--gray);
 font-size:13px;
}
.stat-value{
 font-size:26px;
 font-weight:700;
 margin-top:4px;
}
.answer-shell{
 max-width:760px;
 margin:40px auto;
 padding:0 16px;
}
.answer-card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:12px;
 box-shadow:var(--shadow);
 padding:26px;
 margin-bottom:20px;
}
.answer-option{
 display:flex;
 gap:10px;
 align-items:flex-start;
 padding:12px;
 border:1px solid var(--border);
 border-radius:8px;
 margin-bottom:8px;
}
small.muted{
 color:var(--gray);
}
@media(max-width:800px){
 .grid,
 .stat-grid{
  grid-template-columns:1fr;
 }
 .option-row{
  grid-template-columns:1fr;
 }
 .kintone-map{
  grid-template-columns:1fr;
 }
 .address-fields{
  grid-template-columns:1fr;
 }
 .page-title{
  align-items:flex-start;
  flex-direction:column;
 }
 .header-inner{
  align-items:flex-start;
  flex-direction:column;
 }
 .container{
  padding:20px 12px 40px;
 }
}
</style>
</head>
<body>

<?php if ($admin): ?>
<header class="admin-header">
<div class="header-inner">
<a class="brand"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
<?= h(APP_TITLE) ?>
</a>

<nav class="nav">
<a href="<?= h(app_url(['screen'=>'list'])) ?>">
アンケート一覧
</a>
<a href="<?= h(app_url(['screen'=>'kintone'])) ?>">
kintone連携
</a>
<a href="<?= h(app_url(['screen'=>'mail'])) ?>">
メール設定
</a>
</nav>
</div>
</header>
<?php endif; ?>

<main class="<?= $admin ? 'container' : '' ?>">

<?php if ($flash !== null): ?>
<div class="alert alert-<?= h(
    $flash['type'] ?? 'error'
) ?>">
<?= h($flash['message'] ?? '') ?>
</div>
<?php endif; ?>

<?php
}

function render_footer(): void
{
?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('[data-confirm]').forEach(function(el){
        el.addEventListener('click', function(ev){
            if(!window.confirm(el.dataset.confirm)){
                ev.preventDefault();
            }
        });
    });

    document.querySelectorAll('form[data-loading]').forEach(function(form){
        form.addEventListener('submit', function(){
            var buttons = form.querySelectorAll('button');
            buttons.forEach(function(button){
                button.disabled = true;
            });
        });
    });

    initDragDrop();
});

function initDragDrop(){
    var containers =
        document.querySelectorAll('[data-sort-container]');

    containers.forEach(function(container){
        var items =
            container.querySelectorAll('[draggable="true"]');

        items.forEach(function(item){
            item.addEventListener('dragstart', function(){
                item.classList.add('dragging');
            });

            item.addEventListener('dragend', function(){
                item.classList.remove('dragging');
            });

            item.addEventListener('dragover', function(ev){
                ev.preventDefault();

                var dragging =
                    container.querySelector('.dragging');

                if(!dragging || dragging === item){
                    return;
                }

                var rect = item.getBoundingClientRect();
                var after =
                    ev.clientY > rect.top + rect.height / 2;

                if(after){
                    item.after(dragging);
                }else{
                    item.before(dragging);
                }
            });
        });
    });
}
</script>

</body>
</html>
<?php
}

/* =========================================================
 * 一覧
 * ========================================================= */

function render_list(array $surveys): void
{
    $search = get_string('q');
    $filter = get_string('filter');
    $sort = get_string('sort');

    if (!in_array(
        $filter,
        ['all','published','draft','stopped','ended'],
        true
    )) {
        $filter = 'all';
    }

    if (!in_array(
        $sort,
        ['updated_desc','updated_asc','answers_desc','answers_asc','start_desc','start_asc'],
        true
    )) {
        $sort = 'updated_desc';
    }

    $rows = [];

    foreach ($surveys as $survey) {
        $status =
            (string)($survey['status'] ?? 'draft');

        if (
            $filter !== 'all'
            && $filter !== $status
        ) {
            continue;
        }

        if (
            $search !== ''
            && mb_stripos(
                (string)($survey['title'] ?? ''),
                $search
            ) === false
        ) {
            continue;
        }

        $rows[] = $survey;
    }

    usort(
        $rows,
        static function(
            array $a,
            array $b
        ) use ($sort): int {
            if ($sort === 'answers_desc') {
                return 0;
            }

            if ($sort === 'answers_asc') {
                return 0;
            }

            if ($sort === 'start_desc') {
                return strcmp(
                    (string)($b['startAt'] ?? ''),
                    (string)($a['startAt'] ?? '')
                );
            }

            if ($sort === 'start_asc') {
                return strcmp(
                    (string)($a['startAt'] ?? ''),
                    (string)($b['startAt'] ?? '')
                );
            }

            if ($sort === 'updated_asc') {
                return strcmp(
                    (string)($a['updatedAt'] ?? ''),
                    (string)($b['updatedAt'] ?? '')
                );
            }

            return strcmp(
                (string)($b['updatedAt'] ?? ''),
                (string)($a['updatedAt'] ?? '')
            );
        }
    );
?>
<div class="page-title">
<h1>アンケート一覧</h1>

<a class="btn btn-primary"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>'new'
   ])) ?>">
＋ 新規作成
</a>
</div>

<div class="card">
<div class="card-body">
<form method="get">
<input type="hidden" name="screen" value="list">

<div class="grid">
<div class="field">
<label>タイトル検索</label>
<input type="text"
       name="q"
       value="<?= h($search) ?>"
       placeholder="タイトルを入力してEnter">
</div>

<div class="field">
<label>絞り込み</label>
<select name="filter">
<option value="all"
 <?= $filter==='all'?'selected':'' ?>>
すべて
</option>
<option value="published"
 <?= $filter==='published'?'selected':'' ?>>
公開中
</option>
<option value="draft"
 <?= $filter==='draft'?'selected':'' ?>>
下書き
</option>
<option value="stopped"
 <?= $filter==='stopped'?'selected':'' ?>>
停止
</option>
<option value="ended"
 <?= $filter==='ended'?'selected':'' ?>>
終了
</option>
</select>
</div>

<div class="field">
<label>ソート</label>
<select name="sort">
<option value="updated_desc"
 <?= $sort==='updated_desc'?'selected':'' ?>>
更新日：新しい順
</option>
<option value="updated_asc"
 <?= $sort==='updated_asc'?'selected':'' ?>>
更新日：古い順
</option>
<option value="answers_desc"
 <?= $sort==='answers_desc'?'selected':'' ?>>
回答数：多い順
</option>
<option value="answers_asc"
 <?= $sort==='answers_asc'?'selected':'' ?>>
回答数：少ない順
</option>
<option value="start_desc"
 <?= $sort==='start_desc'?'selected':'' ?>>
開始日：新しい順
</option>
<option value="start_asc"
 <?= $sort==='start_asc'?'selected':'' ?>>
開始日：古い順
</option>
</select>
</div>

<div class="field"
     style="display:flex;align-items:end">
<button class="btn btn-secondary"
        type="submit">
検索・絞り込み
</button>
</div>
</div>
</form>
</div>
</div>

<div class="card">
<div class="card-body">
<div class="table-wrap">
<table>
<thead>
<tr>
<th>タイトル</th>
<th>作成日</th>
<th>更新日</th>
<th>期間</th>
<th>状態</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $survey): ?>
<?php
$answers = 0;
?>
<tr>
<td><?= h($survey['title'] ?? '') ?></td>
<td><?= h($survey['createdAt'] ?? '') ?></td>
<td><?= h($survey['updatedAt'] ?? '') ?></td>
<td>
<?= h($survey['startAt'] ?? '') ?>
<br>
～
<?= h($survey['endAt'] ?? '') ?>
</td>
<td>
<span class="badge <?= h(
    status_class(
        (string)($survey['status'] ?? 'draft')
    )
) ?>">
<?= h(
    status_label(
        (string)($survey['status'] ?? 'draft')
    )
) ?>
</span>
</td>
<td><?= $answers ?></td>
<td>
<div class="actions">
<a class="btn btn-light"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>$survey['id']
   ])) ?>">
確認・編集
</a>

<a class="btn btn-light"
   href="<?= h(app_url([
       'screen'=>'preview',
       'id'=>$survey['id']
   ])) ?>">
プレビュー
</a>

<a class="btn btn-light"
   href="<?= h(app_url([
       'screen'=>'analytics',
       'id'=>$survey['id']
   ])) ?>">
集計
</a>

<a class="btn btn-light"
   href="<?= h(app_url([
       'screen'=>'send',
       'id'=>$survey['id']
   ])) ?>">
送信
</a>

<form method="post"
      style="display:inline">
<input type="hidden"
       name="action"
       value="duplicate_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-secondary"
        type="submit"
        data-confirm="このアンケートを複製しますか？">
複製
</button>
</form>

<form method="post"
      style="display:inline">
<input type="hidden"
       name="action"
       value="delete_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-danger"
        type="submit"
        data-confirm="このアンケートを削除しますか？">
削除
</button>
</form>
</div>
</td>
</tr>
<?php endforeach; ?>

<?php if (!$rows): ?>
<tr>
<td colspan="7">
現在、該当するアンケートはありません。
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>
<?php
}

/* =========================================================
 * 編集
 * ========================================================= */

function render_edit(array $survey): void
{
?>
<div class="page-title">
<h1>アンケート作成・編集</h1>

<div class="button-row">
<a class="btn btn-secondary"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
キャンセル
</a>

<form method="post"
      style="display:inline"
      data-loading>
<input type="hidden"
       name="action"
       value="save_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-primary"
        type="submit">
保存して一覧へ
</button>
</form>
</div>
</div>

<div class="card">
<div class="card-body">

<div class="field">
<label>状態</label>

<?php if (($survey['status'] ?? '') === 'ended'): ?>
<span class="badge gray">終了</span>
<?php else: ?>
<form method="post"
      class="button-row">
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<select name="new_status">
<option value="draft"
 <?= ($survey['status'] ?? '')==='draft'
    ? 'selected' : '' ?>>
下書き
</option>
<option value="published"
 <?= ($survey['status'] ?? '')==='published'
    ? 'selected' : '' ?>>
公開中
</option>
<option value="stopped"
 <?= ($survey['status'] ?? '')==='stopped'
    ? 'selected' : '' ?>>
停止
</option>
</select>

<button class="btn btn-secondary"
        type="submit"
        data-confirm="状態を変更しますか？">
状態を変更
</button>
</form>
<?php endif; ?>
</div>

<div class="grid">
<div class="field">
<label>アンケートタイトル</label>
<input type="text"
       name="title"
       form="survey-main-form"
       value="<?= h($survey['title'] ?? '') ?>">
</div>

<div class="field">
<label>質問番号の採番方式</label>
<select name="numbering"
        form="survey-main-form">
<option value="global"
 <?= ($survey['numbering'] ?? 'global')==='global'
    ? 'selected' : '' ?>>
アンケート全体で通番：Q1、Q2...
</option>
<option value="group"
 <?= ($survey['numbering'] ?? '')==='group'
    ? 'selected' : '' ?>>
グループ毎：Q1-1、Q1-2...
</option>
</select>
</div>
</div>

<div class="field">
<label>アンケート説明</label>
<textarea name="description"
          form="survey-main-form"><?= h(
    $survey['description'] ?? ''
) ?></textarea>
</div>

<div class="grid">
<div class="field">
<label>開始日時</label>
<input type="datetime-local"
       name="startAt"
       form="survey-main-form"
       value="<?= h($survey['startAt'] ?? '') ?>">
</div>

<div class="field">
<label>終了日時</label>
<input type="datetime-local"
       name="endAt"
       form="survey-main-form"
       value="<?= h($survey['endAt'] ?? '') ?>">
</div>
</div>

<form id="survey-main-form"
      method="post"
      data-loading>
<input type="hidden"
       name="action"
       value="save_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden"
       name="status"
       value="<?= h($survey['status'] ?? 'draft') ?>">

<div id="groups"
     data-sort-container>
<?php foreach ($survey['groups'] as $group): ?>

<div class="group"
     draggable="true">

<input type="hidden"
       name="group_order[]"
       value="<?= h($group['id']) ?>">

<div class="group-head">
<span class="drag-handle">☷</span>

<input class="group-title"
       type="text"
       name="group_title[<?= h($group['id']) ?>]"
       value="<?= h($group['title']) ?>">

</div>

<div data-sort-container>
<?php foreach ($group['questions'] as $question): ?>

<div class="question"
     draggable="true">

<input type="hidden"
       name="questions_by_group[
<?= h($group['id']) ?>][]"
       value="<?= h($question['id']) ?>">

<div class="button-row">
<span class="drag-handle">☷</span>

<strong>
<?= h($question['number']) ?>
</strong>
</div>

<div class="field">
<label>質問文</label>
<textarea name="question_text[
<?= h($question['id']) ?>]"><?= h(
    $question['text']
) ?></textarea>
</div>

<div class="grid">
<div class="field">
<label>回答形式</label>
<select name="question_type[
<?= h($question['id']) ?>]">
<option value="single"
 <?= ($question['type'] ?? '')==='single'
    ? 'selected' : '' ?>>
単一選択
</option>
<option value="multiple"
 <?= ($question['type'] ?? '')==='multiple'
    ? 'selected' : '' ?>>
複数選択
</option>
<option value="text"
 <?= ($question['type'] ?? '')==='text'
    ? 'selected' : '' ?>>
自由記述
</option>
</select>
</div>

<div class="field">
<label>必須設定</label>
<label class="check">
<input type="checkbox"
       name="question_required[
<?= h($question['id']) ?>]"
       value="1"
 <?= !empty($question['required'])
    ? 'checked' : '' ?>>
必須
</label>
</div>
</div>

<?php if (
    ($question['type'] ?? '') === 'single'
    || ($question['type'] ?? '') === 'multiple'
): ?>

<div class="field">
<label>選択肢</label>

<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>

<div class="option-row">
<input type="text"
       name="question_option[
<?= h($question['id']) ?>][
<?= h($option['id']) ?>]"
       value="<?= h($option['label']) ?>">

<?php if (
    ($question['type'] ?? '') === 'single'
): ?>
<select name="branching[
<?= h($question['id']) ?>][
<?= h($option['id']) ?>]">
<option value="">次の質問を指定しない</option>
<?php foreach (
    $survey['groups']
    as $branchGroup
): ?>
<?php foreach (
    $branchGroup['questions']
    as $branchQuestion
): ?>
<?php if (
    $branchQuestion['id']
    !== $question['id']
): ?>
<option value="<?= h(
    $branchQuestion['id']
) ?>"
 <?= (
    ($option['nextQuestionId'] ?? '')
    === $branchQuestion['id']
 )
    ? 'selected'
    : '' ?>>
<?= h(
    $branchQuestion['number']
    . ' '
    . $branchQuestion['text']
) ?>
</option>
<?php endif; ?>
<?php endforeach; ?>
<?php endforeach; ?>
</select>
<?php else: ?>
<div></div>
<?php endif; ?>

</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

</div>

<?php endforeach; ?>
</div>

<div class="button-row"
     style="margin-top:14px">
<form method="post">
<input type="hidden"
       name="action"
       value="add_question">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden"
       name="group_id"
       value="<?= h($group['id']) ?>">
<button class="btn btn-secondary"
        type="submit">
＋ 質問を追加
</button>
</form>
</div>

</div>
<?php endforeach; ?>
</div>

<div class="button-row"
     style="margin-top:18px">
<button class="btn btn-secondary"
        type="button"
        onclick="this.closest('form').submit()">
保存して一覧へ
</button>
</div>

</form>

<div class="button-row">
<form method="post">
<input type="hidden"
       name="action"
       value="add_group">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-primary"
        type="submit">
＋ グループを追加
</button>
</form>

<a class="btn btn-light"
   href="<?= h(app_url([
       'screen'=>'preview',
       'id'=>$survey['id']
   ])) ?>">
プレビュー
</a>
</div>

</div>
</div>
<?php
}

/* =========================================================
 * プレビュー
 * ========================================================= */

function render_preview(array $survey): void
{
?>
<div class="page-title">
<h1>プレビュー</h1>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>$survey['id']
   ])) ?>">
編集へ戻る
</a>
</div>

<div class="card">
<div class="card-body">
<h2><?= h($survey['title']) ?></h2>
<p><?= nl2br(h($survey['description'])) ?></p>

<?php foreach ($survey['groups'] as $group): ?>
<h3><?= h($group['title']) ?></h3>

<?php foreach ($group['questions'] as $question): ?>
<div class="question">
<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
<?php if (!empty($question['required'])): ?>
<span style="color:#dc2626">＊</span>
<?php endif; ?>
</strong>

<?php if (
    ($question['type'] ?? '') === 'text'
): ?>
<textarea disabled
          placeholder="自由記述"></textarea>
<?php else: ?>
<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>
<label class="answer-option">
<input type="<?= (
    ($question['type'] ?? '') === 'multiple'
)
    ? 'checkbox'
    : 'radio' ?>">
<span><?= h($option['label']) ?></span>
</label>
<?php endforeach; ?>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php endforeach; ?>
</div>
</div>
<?php
}

/* =========================================================
 * kintone設定
 * ========================================================= */

function render_kintone(array $config): void
{
    $fields = $config['fields'] ?? [];
    $mapping = $config['mapping'] ?? [];
?>
<div class="page-title">
<h1>kintone連携設定</h1>
</div>

<div class="card">
<div class="card-body">
<h2 class="card-title">接続設定</h2>

<form method="post"
      data-loading>
<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid">

<div class="field">
<label>サブドメイン</label>
<input type="text"
       name="subdomain"
       value="<?= h($config['subdomain'] ?? '') ?>"
       placeholder="https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx">
</div>

<div class="field">
<label>顧客管理アプリID</label>
<input type="number"
       name="app_id"
       min="1"
       value="<?= h($config['app_id'] ?? '') ?>">
</div>

<div class="field">
<label>ログイン名</label>
<input type="text"
       name="username"
       value="<?= h($config['username'] ?? '') ?>">
</div>

<div class="field">
<label>パスワード</label>
<input type="password"
       name="password"
       value=""
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</div>

<div class="field">
<label>Proxy</label>
<input type="text"
       name="proxy"
       value="<?= h($config['proxy'] ?? '') ?>"
       placeholder="host:port">
</div>

<div class="field">
<label>SSL証明書検証</label>
<label class="check">
<input type="checkbox"
       name="verify_ssl"
       value="1"
 <?= !empty($config['verify_ssl'])
    ? 'checked' : '' ?>>
有効
</label>
</div>

</div>

<div class="button-row">
<button class="btn btn-primary"
        type="submit">
設定保存
</button>
</div>
</form>
</div>
</div>

<div class="card">
<div class="card-body">
<h2 class="card-title">接続・項目取得</h2>

<div class="button-row">

<form method="post"
      data-loading>
<input type="hidden"
       name="action"
       value="test_kintone">
<input type="password"
       name="password"
       placeholder="接続テスト時のみパスワードを入力可"
       autocomplete="new-password">
<button class="btn btn-secondary"
        type="submit">
接続テスト
</button>
</form>

<form method="post"
      data-loading>
<input type="hidden"
       name="action"
       value="fetch_kintone_fields">
<button class="btn btn-secondary"
        type="submit">
項目一覧を再取得
</button>
</form>

<form method="post"
      data-loading>
<input type="hidden"
       name="action"
       value="sync_kintone">
<button class="btn btn-success"
        type="submit">
顧客情報を同期
</button>
</form>

</div>

<?php if (!empty($config['last_test'])): ?>
<p>
<small class="muted">
最終接続確認：
<?= h($config['last_test']) ?>
</small>
</p>
<?php endif; ?>

<?php if (!empty($config['last_sync'])): ?>
<p>
<small class="muted">
最終同期：
<?= h($config['last_sync']) ?>
</small>
</p>
<?php endif; ?>
</div>
</div>

<div class="card">
<div class="card-body">
<h2 class="card-title">項目マッピング</h2>

<?php if (!$fields): ?>
<div class="alert alert-warning">
先に「項目一覧を再取得」を実行してください。
</div>
<?php else: ?>

<form method="post">
<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<div class="kintone-map">

<label>組織名</label>
<select name="mapping_organization">
<option value="">選択してください</option>
<?php foreach ($fields as $field): ?>
<option value="<?= h($field['code']) ?>"
 <?= ($mapping['organization'] ?? '')
    === $field['code']
    ? 'selected'
    : '' ?>>
<?= h($field['label']) ?>
（<?= h($field['code']) ?>）
</option>
<?php endforeach; ?>
</select>

<label>氏名</label>
<select name="mapping_name">
<option value="">選択してください</option>
<?php foreach ($fields as $field): ?>
<option value="<?= h($field['code']) ?>"
 <?= ($mapping['name'] ?? '')
    === $field['code']
    ? 'selected'
    : '' ?>>
<?= h($field['label']) ?>
（<?= h($field['code']) ?>）
</option>
<?php endforeach; ?>
</select>

<label>メールアドレス</label>
<select name="mapping_email">
<option value="">選択してください</option>
<?php foreach ($fields as $field): ?>
<option value="<?= h($field['code']) ?>"
 <?= ($mapping['email'] ?? '')
    === $field['code']
    ? 'selected'
    : '' ?>>
<?= h($field['label']) ?>
（<?= h($field['code']) ?>）
</option>
<?php endforeach; ?>
</select>

<label>部署名</label>
<select name="mapping_department">
<option value="">選択してください</option>
<?php foreach ($fields as $field): ?>
<option value="<?= h($field['code']) ?>"
 <?= ($mapping['department'] ?? '')
    === $field['code']
    ? 'selected'
    : '' ?>>
<?= h($field['label']) ?>
（<?= h($field['code']) ?>）
</option>
<?php endforeach; ?>
</select>

<label>電話番号</label>
<select name="mapping_phone">
<option value="">選択してください</option>
<?php foreach ($fields as $field): ?>
<option value="<?= h($field['code']) ?>"
 <?= ($mapping['phone'] ?? '')
    === $field['code']
    ? 'selected'
    : '' ?>>
<?= h($field['label']) ?>
（<?= h($field['code']) ?>）
</option>
<?php endforeach; ?>
</select>

<label>住所</label>
<div class="address-fields">
<?php foreach ($fields as $field): ?>
<label class="check">
<input type="checkbox"
       name="mapping_address[]"
       value="<?= h($field['code']) ?>"
 <?= in_array(
    $field['code'],
    $mapping['address'] ?? [],
    true
 )
    ? 'checked'
    : '' ?>>
<?= h($field['label']) ?>
（<?= h($field['code']) ?>）
</label>
<?php endforeach; ?>
</div>

</div>

<div class="button-row"
     style="margin-top:18px">
<button class="btn btn-primary"
        type="submit">
マッピングを保存
</button>
</div>
</form>

<?php endif; ?>
</div>
</div>
<?php
}

/* =========================================================
 * SMTP設定
 * ========================================================= */

function render_mail(array $config): void
{
?>
<div class="page-title">
<h1>メールサーバ設定</h1>
</div>

<div class="card">
<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_mail">

<div class="grid">

<div class="field">
<label>SMTPサーバ</label>
<input type="text"
       name="server"
       value="<?= h($config['host'] ?? '') ?>">
</div>

<div class="field">
<label>SMTPポート</label>
<input type="number"
       name="port"
       min="1"
       max="65535"
       value="<?= h($config['port'] ?? 587) ?>">
</div>

<div class="field">
<label>暗号化方式</label>
<select name="encryption">
<option value="ssl"
 <?= ($config['encryption'] ?? '')
    === 'ssl'
    ? 'selected'
    : '' ?>>
SSL
</option>
<option value="tls"
 <?= ($config['encryption'] ?? 'tls')
    === 'tls'
    ? 'selected'
    : '' ?>>
TLS
</option>
<option value="none"
 <?= ($config['encryption'] ?? '')
    === 'none'
    ? 'selected'
    : '' ?>>
なし
</option>
</select>
</div>

<div class="field">
<label>SMTP認証</label>
<label class="check">
<input type="checkbox"
       name="auth"
       value="1"
 <?= !empty($config['auth'])
    ? 'checked'
    : '' ?>>
認証する
</label>
</div>

<div class="field">
<label>SMTPユーザー名</label>
<input type="text"
       name="username"
       value="<?= h($config['username'] ?? '') ?>">
</div>

<div class="field">
<label>SMTPパスワード</label>
<input type="password"
       name="password"
       value=""
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</div>

<div class="field">
<label>送信元メールアドレス</label>
<input type="email"
       name="from_email"
       value="<?= h($config['from_email'] ?? '') ?>">
</div>

<div class="field">
<label>送信元名</label>
<input type="text"
       name="from_name"
       value="<?= h($config['from_name'] ?? '') ?>">
</div>

<div class="field">
<label>返信先メールアドレス</label>
<input type="email"
       name="reply_to"
       value="<?= h($config['reply_to'] ?? '') ?>">
</div>

</div>

<div class="button-row">
<button class="btn btn-primary"
        type="submit">
設定保存
</button>
</div>

</form>
</div>
</div>

<div class="card">
<div class="card-body">
<h2 class="card-title">接続テスト</h2>

<div class="button-row">
<form method="post"
      data-loading>
<input type="hidden"
       name="action"
       value="test_mail">
<button class="btn btn-secondary"
        type="submit">
接続テスト
</button>
</form>

<?php if (!empty($config['last_test'])): ?>
<span class="badge success">
接続確認済み
</span>
<?php else: ?>
<span class="badge gray">
未設定
</span>
<?php endif; ?>
</div>
</div>
</div>

<div class="card">
<div class="card-body">
<h2 class="card-title">テストメール送信</h2>

<form method="post"
      data-loading>
<input type="hidden"
       name="action"
       value="send_test_mail">

<div class="field">
<label>送信先メールアドレス</label>
<input type="email"
       name="test_email"
       required>
</div>

<button class="btn btn-success"
        type="submit">
テストメール送信
</button>
</form>
</div>
</div>
<?php
}

/* =========================================================
 * 送信
 * ========================================================= */

function render_send(
    array $survey,
    array $customers,
    array $history
): void {
?>
<div class="page-title">
<h1>顧客選択・メール送信</h1>
<a class="btn btn-secondary"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
一覧へ戻る
</a>
</div>

<div class="card">
<div class="card-body">
<h2 class="card-title">
対象アンケート
</h2>
<strong><?= h($survey['title']) ?></strong>
</div>
</div>

<div class="card">
<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="field">
<label>顧客選択</label>

<?php if (!$customers): ?>
<div class="alert alert-warning">
顧客情報がありません。
kintone設定から顧客情報を同期してください。
</div>
<?php else: ?>

<?php foreach ($customers as $customer): ?>
<label class="answer-option">
<input type="checkbox"
       name="customer_ids[]"
       value="<?= h($customer['id']) ?>">
<span>
<strong><?= h($customer['name'] ?? '') ?></strong>
<br>
<?= h($customer['email'] ?? '') ?>
</span>
</label>
<?php endforeach; ?>

<?php endif; ?>
</div>

<div class="field">
<label>メール件名</label>
<input type="text"
       name="subject"
       value="<?= h(
           $survey['title']
           . 'のご案内'
       ) ?>">
</div>

<div class="field">
<label>メール本文</label>
<textarea name="body"><?= h(
'{$顧客名} 様

以下のURLからアンケートへご回答ください。

{アンケートURL}

よろしくお願いいたします。'
) ?></textarea>
</div>

<div class="alert alert-warning">
使用できる変数：
{顧客名}
{アンケートURL}
</div>

<button class="btn btn-primary"
        type="submit"
        data-confirm="選択した顧客へ一括送信しますか？">
一括送信
</button>

</form>
</div>
</div>

<div class="card">
<div class="card-body">
<h2 class="card-title">送信履歴</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>顧客</th>
<th>種別</th>
<th>結果</th>
</tr>
</thead>
<tbody>
<?php foreach ($history as $item): ?>
<tr>
<td><?= h($item['createdAt'] ?? '') ?></td>
<td><?= h($item['customer_name'] ?? '') ?></td>
<td><?= h($item['type'] ?? '') ?></td>
<td><?= h($item['result'] ?? '') ?></td>
</tr>
<?php endforeach; ?>

<?php if (!$history): ?>
<tr>
<td colspan="4">
送信履歴はありません。
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>
<?php
}

/* =========================================================
 * 集計
 * ========================================================= */

function render_analytics(
    array $survey,
    array $answers,
    array $customers
): void {
    $surveyAnswers = [];

    foreach ($answers as $answer) {
        if (
            ($answer['survey_id'] ?? '')
            === $survey['id']
        ) {
            $surveyAnswers[] =
                $answer;
        }
    }

    $answerCount =
        count($surveyAnswers);

    $customerCount =
        count($customers);

    $rate =
        $customerCount > 0
        ? round(
            ($answerCount / $customerCount)
            * 100,
            1
        )
        : 0;
?>
<div class="page-title">
<h1>回答集計・分析</h1>
<a class="btn btn-secondary"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
一覧へ戻る
</a>
</div>

<div class="card">
<div class="card-body">
<h2><?= h($survey['title']) ?></h2>
</div>
</div>

<div class="stat-grid">
<div class="stat">
<div class="stat-label">送信対象者数</div>
<div class="stat-value"><?= $customerCount ?></div>
</div>

<div class="stat">
<div class="stat-label">回答数</div>
<div class="stat-value"><?= $answerCount ?></div>
</div>

<div class="stat">
<div class="stat-label">未回答数</div>
<div class="stat-value">
<?= max(0,$customerCount-$answerCount) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">回答率</div>
<div class="stat-value">
<?= h((string)$rate) ?>%
</div>
</div>
</div>

<div class="card"
     style="margin-top:20px">
<div class="card-body">
<h2 class="card-title">設問別集計</h2>

<?php if ($answerCount === 0): ?>
<div class="alert alert-warning">
現在、回答データはありません
</div>
<?php else: ?>

<?php foreach ($survey['groups'] as $group): ?>
<?php foreach ($group['questions'] as $question): ?>

<div class="question">
<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<p>
回答データ：
<?= $answerCount ?>件
</p>
</div>

<?php endforeach; ?>
<?php endforeach; ?>

<?php endif; ?>
</div>
</div>
<?php
}

/* =========================================================
 * 回答
 * ========================================================= */

function render_answer(array $survey): void
{
?>
<div class="answer-shell">

<div class="answer-card">
<h1><?= h($survey['title']) ?></h1>
<p>
<?= nl2br(
    h($survey['description'] ?? '')
) ?>
</p>
</div>

<form method="post">
<input type="hidden"
       name="action"
       value="save_answer">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<?php foreach ($survey['groups'] as $group): ?>

<div class="answer-card">
<h2><?= h($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>

<div class="field">
<label>
<?= h($question['number']) ?>
.
<?= h($question['text']) ?>

<?php if (!empty($question['required'])): ?>
<span style="color:#dc2626">＊必須</span>
<?php endif; ?>
</label>

<?php
$name =
'answer['
. $question['id']
. ']';
?>

<?php if (
    ($question['type'] ?? '')
    === 'text'
): ?>

<textarea
 name="<?= h($name) ?>"
 <?= !empty($question['required'])
    ? 'required'
    : '' ?>></textarea>

<?php elseif (
    ($question['type'] ?? '')
    === 'multiple'
): ?>

<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>
<label class="answer-option">
<input type="checkbox"
       name="<?= h($name) ?>[]"
       value="<?= h($option['id']) ?>">
<span><?= h($option['label']) ?></span>
</label>
<?php endforeach; ?>

<?php else: ?>

<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>
<label class="answer-option">
<input type="radio"
       name="<?= h($name) ?>"
       value="<?= h($option['id']) ?>"
 <?= !empty($question['required'])
    ? 'required'
    : '' ?>>
<span><?= h($option['label']) ?></span>
</label>
<?php endforeach; ?>

<?php endif; ?>
</div>

<?php endforeach; ?>
</div>

<?php endforeach; ?>

<button class="btn btn-primary"
        type="submit">
回答確認へ
</button>

</form>
</div>
<?php
}

/* =========================================================
 * 回答確認
 * ========================================================= */

function render_confirm(array $survey): void
{
    $draft =
        $_SESSION['answer_draft']
        ?? [];

    $answers =
        is_array($draft['answers'] ?? null)
        ? $draft['answers']
        : [];
?>
<div class="answer-shell">

<div class="answer-card">
<h1>回答確認</h1>

<?php foreach ($survey['groups'] as $group): ?>

<h2><?= h($group['title']) ?></h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<?php
$value =
    $answers[$question['id']]
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
?>

<div class="question">
<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<p>
<?= nl2br(h((string)$value)) ?>
</p>
</div>

<?php endforeach; ?>
<?php endforeach; ?>

<div class="button-row">
<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'answer',
       'id'=>$survey['id']
   ])) ?>">
修正する
</a>

<form method="post">
<input type="hidden"
       name="action"
       value="submit_answer">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<button class="btn btn-primary"
        type="submit"
        data-confirm="この回答を送信しますか？">
回答を送信
</button>
</form>
</div>

</div>
</div>
<?php
}

/* =========================================================
 * 完了
 * ========================================================= */

function render_complete(array $survey): void
{
?>
<div class="answer-shell">

<div class="answer-card"
     style="text-align:center;padding:55px 25px">

<h1>回答ありがとうございました</h1>

<p>
「<?= h($survey['title']) ?>」
への回答を受け付けました。
</p>

</div>

</div>
<?php
}

/* =========================================================
 * メイン
 * ========================================================= */

try {
    start_app();

    $data = load_data();
    $settings = load_settings();

    /*
     * 自動終了は業務要件上必要。
     * ただし公開中かつ終了日時経過の場合だけ。
     */
    if (refresh_statuses($data)) {
        save_data($data);
    }

    $postResult =
        handle_post(
            $data,
            $settings
        );

    if ($postResult !== null) {
        $screen =
            (string)(
                $postResult['screen']
                ?? 'list'
            );

        $params = $postResult;

        unset(
            $params['screen']
        );

        redirect_screen(
            $screen,
            $params
        );
    }

    /*
     * POST処理後は必ず最新状態を読み直す。
     */
    $data = load_data();
    $settings = load_settings();

    if (refresh_statuses($data)) {
        save_data($data);
    }

    $screen =
        get_string('screen');

    if ($screen === '') {
        $screen = 'list';
    }

    /*
     * 回答者画面。
     * 管理者ヘッダーを表示しない。
     */
    if (in_array(
        $screen,
        ['answer', 'confirm', 'complete'],
        true
    )) {
        $id =
            get_string('id');

        $survey =
            survey_by_id(
                $data['surveys'],
                $id
            );

        if ($survey === null) {
            render_head(
                'アンケート',
                false
            );

            ?>
            <div class="answer-shell">
            <div class="alert alert-error">
            アンケートが見つかりません。
            </div>
            </div>
            <?php

            render_footer();
            exit;
        }

        if ($screen === 'answer') {
            render_head(
                'アンケート回答',
                false
            );

            render_answer($survey);
            render_footer();
            exit;
        }

        if ($screen === 'confirm') {
            render_head(
                '回答確認',
                false
            );

            render_confirm($survey);
            render_footer();
            exit;
        }

        render_head(
            '回答完了',
            false
        );

        render_complete($survey);
        render_footer();
        exit;
    }

    /*
     * 管理者画面
     */
    switch ($screen) {

        case 'edit':
            $id =
                get_string('id');

            if ($id === 'new') {
                $survey = [
                    'id' =>
                        uuid('survey'),
                    'title' => '',
                    'description' => '',
                    'startAt' =>
                        date('Y-m-d\TH:i'),
                    'endAt' =>
                        date(
                            'Y-m-d\TH:i',
                            strtotime('+30 days')
                        ),
                    'status' => 'draft',
                    'numbering' => 'global',
                    'createdAt' =>
                        date('Y-m-d H:i:s'),
                    'updatedAt' =>
                        date('Y-m-d H:i:s'),
                    'groups' => [
                        [
                            'id' =>
                                uuid('group'),
                            'title' =>
                                '基本アンケート',
                            'questions' => [],
                        ],
                    ],
                ];

                render_head(
                    'アンケート作成・編集'
                );

                render_edit($survey);
                render_footer();
                break;
            }

            $survey =
                survey_by_id(
                    $data['surveys'],
                    $id
                );

            if ($survey === null) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                redirect_screen('list');
            }

            render_head(
                'アンケート作成・編集'
            );

            render_edit($survey);
            render_footer();
            break;

        case 'preview':
            $survey =
                survey_by_id(
                    $data['surveys'],
                    get_string('id')
                );

            if ($survey === null) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                redirect_screen('list');
            }

            render_head('プレビュー');
            render_preview($survey);
            render_footer();
            break;

        case 'send':
            $survey =
                survey_by_id(
                    $data['surveys'],
                    get_string('id')
                );

            if ($survey === null) {
                flash(
                    'error',
                    '対象アンケートが見つかりません。'
                );

                redirect_screen('list');
            }

            $history = array_values(
                array_filter(
                    $data['send_history'],
                    static function(
                        array $item
                    ) use ($survey): bool {
                        return (
                            ($item['survey_id'] ?? '')
                            === $survey['id']
                        );
                    }
                )
            );

            usort(
                $history,
                static function(
                    array $a,
                    array $b
                ): int {
                    return strcmp(
                        (string)(
                            $b['createdAt']
                            ?? ''
                        ),
                        (string)(
                            $a['createdAt']
                            ?? ''
                        )
                    );
                }
            );

            render_head(
                '顧客選択・メール送信'
            );

            render_send(
                $survey,
                $data['customers'],
                $history
            );

            render_footer();
            break;

        case 'analytics':
            $survey =
                survey_by_id(
                    $data['surveys'],
                    get_string('id')
                );

            if ($survey === null) {
                flash(
                    'error',
                    '対象アンケートが見つかりません。'
                );

                redirect_screen('list');
            }

            render_head(
                '回答集計・分析'
            );

            render_analytics(
                $survey,
                $data['answers'],
                $data['customers']
            );

            render_footer();
            break;

        case 'kintone':
            render_head(
                'kintone連携設定'
            );

            render_kintone(
                $settings['kintone']
            );

            render_footer();
            break;

        case 'mail':
            render_head(
                'メールサーバ設定'
            );

            render_mail(
                $settings['mail']
            );

            render_footer();
            break;

        case 'list':
        default:
            render_head(
                'アンケート一覧'
            );

            render_list(
                $data['surveys']
            );

            render_footer();
            break;
    }
} catch (Throwable $e) {
    /*
     * 白画面を禁止。
     * 内部スタックトレース、パスワード、
     * Authorizationヘッダー等は表示しない。
     */
    http_response_code(500);

    render_head(
        'システムエラー'
    );
?>
<div class="alert alert-error">
システムエラーが発生しました。
</div>

<div class="card">
<div class="card-body">
<p>
アプリケーションの処理を完了できませんでした。
</p>
<p>
設定・ファイル権限・サーバー環境・
外部サービス設定を確認してください。
</p>
</div>
</div>
<?php
    render_footer();
}
?>
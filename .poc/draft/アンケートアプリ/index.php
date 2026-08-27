<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 *
 * 実行環境:
 *   Apache 2.4
 *   PHP 8.5
 *   DBなし
 *   PHP cURLなし
 *
 * 必要な環境変数:
 *
 *   SURVEY_ADMIN_USER
 *   SURVEY_ADMIN_PASSWORD
 *   SURVEY_APP_KEY
 *
 * 任意:
 *   SURVEY_DATA_DIR
 *
 * 重要:
 *   アプリ側から http -> https の強制リダイレクトは行わない。
 *   Apache / Reverse Proxy 側でHTTPSを強制する。
 *
 *   画面は index.php?screen=... のみで制御する。
 */

/* ============================================================
 * 1. 基本設定
 * ============================================================ */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

$isHttps =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_name('survey_session');
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

const APP_NAME = 'アンケート管理システム';

$dataDir = getenv('SURVEY_DATA_DIR');

if (!$dataDir) {
    $dataDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'survey-app-data';
}

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0700, true);
}

if (!is_dir($dataDir) || !is_writable($dataDir)) {
    http_response_code(500);
    exit('データ保存ディレクトリを作成できません。');
}

$dataFile = $dataDir . DIRECTORY_SEPARATOR . 'data.json';

/* ============================================================
 * 2. 共通関数
 * ============================================================ */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now_iso(): string
{
    return date('c');
}

function redirect(string $url): never
{
    /*
     * リダイレクトは必ずここから行う。
     * 外部URLへのリダイレクトはしない。
     */
    header('Location: ' . $url, true, 303);
    exit;
}

function current_screen(): string
{
    $screen = (string)($_GET['screen'] ?? 'list');

    $allowed = [
        'login',
        'logout',
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

    return in_array($screen, $allowed, true) ? $screen : 'list';
}

function is_admin(): bool
{
    return !empty($_SESSION['admin_authenticated']);
}

function require_admin(): void
{
    /*
     * login画面にはこの関数を絶対に適用しない。
     * これが「login -> login」のリダイレクトループ防止の基本。
     */
    if (!is_admin()) {
        redirect('index.php?screen=login');
    }
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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $token = (string)($_POST['_csrf'] ?? '');

    if (
        $token === ''
        || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        exit('CSRF token validation failed.');
    }
}

function post(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function query(string $key, mixed $default = ''): mixed
{
    return $_GET[$key] ?? $default;
}

function uuid(): string
{
    return bin2hex(random_bytes(16));
}

/* ============================================================
 * 3. 暗号化
 * ============================================================ */

function app_key(): string
{
    $key = getenv('SURVEY_APP_KEY');

    if (!$key) {
        /*
         * 開発時のみ許容。
         * 本番では必ず環境変数を設定する。
         */
        return hash('sha256', 'CHANGE-ME-IN-PRODUCTION', true);
    }

    return hash('sha256', $key, true);
}

function encrypt_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    $iv = random_bytes(12);
    $tag = '';

    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        app_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cipher === false) {
        throw new RuntimeException('秘密情報の暗号化に失敗しました。');
    }

    return base64_encode($iv . $tag . $cipher);
}

function decrypt_secret(string $encoded): string
{
    if ($encoded === '') {
        return '';
    }

    $raw = base64_decode($encoded, true);

    if ($raw === false || strlen($raw) < 28) {
        return '';
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        app_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    return $plain === false ? '' : $plain;
}

/* ============================================================
 * 4. JSON永続化
 * ============================================================ */

function default_data(): array
{
    return [
        'surveys' => [
            [
                'id' => 'survey-001',
                'title' => 'サービス満足度アンケート',
                'description' => 'サービスについてのご意見をお聞かせください。',
                'startAt' => date('Y-m-d\TH:i'),
                'endAt' => date('Y-m-d\TH:i', strtotime('+30 days')),
                'status' => 'draft',
                'numbering' => 'global',
                'createdAt' => now_iso(),
                'updatedAt' => now_iso(),
                'groups' => [
                    [
                        'id' => uuid(),
                        'title' => '基本評価',
                        'questions' => [
                            [
                                'id' => uuid(),
                                'text' => 'サービスに満足していますか？',
                                'type' => 'single',
                                'required' => true,
                                'options' => ['満足', '普通', '不満'],
                                'branches' => [],
                            ],
                            [
                                'id' => uuid(),
                                'text' => 'ご意見をお聞かせください。',
                                'type' => 'text',
                                'required' => false,
                                'options' => [],
                                'branches' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'answers' => [],
        'customers' => [],
        'send_history' => [],
        'kintone' => [
            'subdomain' => '',
            'app_id' => '',
            'login' => '',
            'password' => '',
            'ssl_verify' => false,
            'proxy' => '',
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
            'from_name' => APP_NAME,
            'reply_to' => '',
            'last_test' => null,
        ],
    ];
}

function load_data(): array
{
    global $dataFile;

    if (!is_file($dataFile)) {
        $data = default_data();
        save_data($data);
        return $data;
    }

    $json = file_get_contents($dataFile);

    if ($json === false) {
        return default_data();
    }

    $data = json_decode($json, true);

    return is_array($data) ? $data : default_data();
}

function save_data(array $data): void
{
    global $dataFile;

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException('データ保存に失敗しました。');
    }

    $tmp = $dataFile . '.tmp';

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('データ一時ファイルの作成に失敗しました。');
    }

    chmod($tmp, 0600);

    if (!rename($tmp, $dataFile)) {
        @unlink($tmp);
        throw new RuntimeException('データファイルの更新に失敗しました。');
    }

    @chmod($dataFile, 0600);
}

/* ============================================================
 * 5. アンケート処理
 * ============================================================ */

function find_survey(array &$data, string $id): ?array
{
    foreach ($data['surveys'] as $survey) {
        if ((string)$survey['id'] === $id) {
            return $survey;
        }
    }

    return null;
}

function survey_index(array &$data, string $id): int
{
    foreach ($data['surveys'] as $i => $survey) {
        if ((string)$survey['id'] === $id) {
            return $i;
        }
    }

    return -1;
}

function refresh_survey_status(array &$data): void
{
    $changed = false;
    $now = time();

    foreach ($data['surveys'] as &$survey) {
        if (
            $survey['status'] === 'published'
            && !empty($survey['endAt'])
        ) {
            $end = strtotime((string)$survey['endAt']);

            if ($end !== false && $end < $now) {
                $survey['status'] = 'ended';
                $survey['updatedAt'] = now_iso();
                $changed = true;
            }
        }
    }

    unset($survey);

    if ($changed) {
        save_data($data);
    }
}

function renumber_questions(array &$survey): void
{
    $g = 1;
    $q = 1;

    foreach ($survey['groups'] as &$group) {
        $local = 1;

        foreach ($group['questions'] as &$question) {
            if (($survey['numbering'] ?? 'global') === 'group') {
                $question['number'] = 'Q' . $g . '-' . $local;
            } else {
                $question['number'] = 'Q' . $q;
            }

            $local++;
            $q++;
        }

        unset($question);

        $g++;
    }

    unset($group);
}

function normalize_survey(array $input, ?array $existing = null): array
{
    $survey = $existing ?: [
        'id' => uuid(),
        'createdAt' => now_iso(),
    ];

    $survey['title'] = trim((string)($input['title'] ?? ''));
    $survey['description'] = trim((string)($input['description'] ?? ''));
    $survey['startAt'] = (string)($input['startAt'] ?? '');
    $survey['endAt'] = (string)($input['endAt'] ?? '');
    $survey['numbering'] = in_array(
        ($input['numbering'] ?? 'global'),
        ['global', 'group'],
        true
    ) ? $input['numbering'] : 'global';

    $survey['updatedAt'] = now_iso();

    if (!$existing) {
        $survey['status'] = 'draft';
        $survey['groups'] = [];
    }

    if (empty($survey['groups'])) {
        $survey['groups'][] = [
            'id' => uuid(),
            'title' => '新しいグループ',
            'questions' => [],
        ];
    }

    renumber_questions($survey);

    return $survey;
}

function status_label(string $status): string
{
    return match ($status) {
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き',
    };
}

function status_class(string $status): string
{
    return match ($status) {
        'published' => 'badge-published',
        'stopped' => 'badge-stopped',
        'ended' => 'badge-ended',
        default => 'badge-draft',
    };
}

function answer_count(array $data, string $surveyId): int
{
    $count = 0;

    foreach ($data['answers'] as $answer) {
        if (($answer['survey_id'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}

/* ============================================================
 * 6. kintone HTTP
 * ============================================================ */

function kintone_config(array $data): array
{
    $k = $data['kintone'];

    return [
        'subdomain' => trim((string)$k['subdomain']),
        'app_id' => trim((string)$k['app_id']),
        'login' => trim((string)$k['login']),
        'password' => decrypt_secret((string)$k['password']),
        'ssl_verify' => !empty($k['ssl_verify']),
        'proxy' => trim((string)$k['proxy']),
    ];
}

function validate_host_port(string $value): bool
{
    if ($value === '') {
        return false;
    }

    if (!preg_match('/^([^:\s\/]+):([0-9]{1,5})$/', $value, $m)) {
        return false;
    }

    $port = (int)$m[2];

    return $port >= 1 && $port <= 65535;
}

function kintone_request(
    array $cfg,
    string $method,
    string $path,
    ?array $body = null
): array {
    if ($cfg['subdomain'] === '') {
        throw new RuntimeException('kintoneサブドメインが未設定です。');
    }

    if (!filter_var(
        'https://' . $cfg['subdomain'] . '.cybozu.com',
        FILTER_VALIDATE_URL
    )) {
        throw new RuntimeException('kintoneサブドメインが不正です。');
    }

    $url =
        'https://'
        . $cfg['subdomain']
        . '.cybozu.com'
        . $path;

    $headers = [
        'X-Cybozu-Authorization: '
        . base64_encode($cfg['login'] . ':' . $cfg['password']),
        'Accept: application/json',
        'User-Agent: SurveyApplication/1.0',
    ];

    $options = [
        'http' => [
            'method' => $method,
            'timeout' => 30,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers),
        ],
        'ssl' => [
            'verify_peer' => $cfg['ssl_verify'],
            'verify_peer_name' => $cfg['ssl_verify'],
            'allow_self_signed' => !$cfg['ssl_verify'],
        ],
    ];

    if ($body !== null) {
        $json = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $options['http']['content'] = $json;
        $options['http']['header'] .=
            "\r\nContent-Type: application/json";
    }

    if ($cfg['proxy'] !== '') {
        if (!validate_host_port($cfg['proxy'])) {
            throw new RuntimeException(
                'Proxyは host:port 形式で入力してください。'
            );
        }

        $options['http']['proxy'] =
            'tcp://' . $cfg['proxy'];

        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $response = @file_get_contents($url, false, $context);

    $status = 0;

    if (!empty($http_response_header[0])
        && preg_match(
            '/\s(\d{3})\s/',
            $http_response_header[0],
            $m
        )
    ) {
        $status = (int)$m[1];
    }

    if ($response === false) {
        throw new RuntimeException(
            'kintoneへのHTTP接続に失敗しました。'
        );
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        $decoded = [
            'raw' => $response,
        ];
    }

    if ($status < 200 || $status >= 300) {
        $message = $decoded['message'] ?? 'kintone APIエラー';

        throw new RuntimeException(
            'HTTP ' . $status . ': ' . $message
        );
    }

    return $decoded;
}

/* ============================================================
 * 7. kintone顧客同期
 * ============================================================ */

function kintone_fields(array $cfg): array
{
    $result = kintone_request(
        $cfg,
        'GET',
        '/k/v1/app/form/fields.json?app='
        . rawurlencode($cfg['app_id'])
        . '&lang=ja'
    );

    return $result['properties'] ?? [];
}

function kintone_records(
    array $cfg,
    string $query = ''
): array {
    $result = [];

    $cursorBody = [
        'app' => (int)$cfg['app_id'],
        'size' => 500,
    ];

    if ($query !== '') {
        $cursorBody['query'] = $query;
    }

    $cursor = kintone_request(
        $cfg,
        'POST',
        '/k/v1/records/cursor.json',
        $cursorBody
    );

    $cursorId = $cursor['id'] ?? '';

    if ($cursorId === '') {
        return [];
    }

    try {
        while (true) {
            $chunk = kintone_request(
                $cfg,
                'GET',
                '/k/v1/records/cursor.json?id='
                . rawurlencode($cursorId)
            );

            foreach (($chunk['records'] ?? []) as $record) {
                $result[] = $record;
            }

            if (empty($chunk['next'])) {
                break;
            }
        }
    } finally {
        try {
            kintone_request(
                $cfg,
                'DELETE',
                '/k/v1/records/cursor.json',
                ['id' => $cursorId]
            );
        } catch (Throwable $e) {
            /* 後処理失敗は同期結果そのものを壊さない */
        }
    }

    return $result;
}

function kintone_value(array $record, string $code): string
{
    if ($code === '' || !isset($record[$code])) {
        return '';
    }

    $value = $record[$code]['value'] ?? '';

    if (is_array($value)) {
        $values = [];

        foreach ($value as $item) {
            if (is_array($item) && isset($item['name'])) {
                $values[] = (string)$item['name'];
            } elseif (is_array($item) && isset($item['value'])) {
                $values[] = (string)$item['value'];
            } else {
                $values[] = (string)$item;
            }
        }

        return implode(' ', $values);
    }

    return (string)$value;
}

/* ============================================================
 * 8. SMTP
 * ============================================================ */

function smtp_read($fp): string
{
    $result = '';

    while (($line = fgets($fp, 512)) !== false) {
        $result .= $line;

        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    return $result;
}

function smtp_expect($fp, array $codes): void
{
    $response = smtp_read($fp);

    $code = (int)substr($response, 0, 3);

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' . trim($response)
        );
    }
}

function smtp_command($fp, string $command, array $codes): void
{
    fwrite($fp, $command . "\r\n");
    smtp_expect($fp, $codes);
}

function smtp_send_mail(
    array $cfg,
    string $to,
    string $subject,
    string $body
): void {
    $host = trim((string)$cfg['host']);
    $port = (int)$cfg['port'];
    $encryption = (string)$cfg['encryption'];

    if ($host === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException('SMTP設定が不正です。');
    }

    $transport = $encryption === 'ssl'
        ? 'ssl://'
        : 'tcp://';

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        20
    );

    if (!$fp) {
        throw new RuntimeException(
            'SMTP接続失敗: ' . $errstr
        );
    }

    stream_set_timeout($fp, 20);

    try {
        smtp_expect($fp, [220]);

        smtp_command(
            $fp,
            'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'),
            [250]
        );

        if ($encryption === 'tls') {
            smtp_command($fp, 'STARTTLS', [220]);

            $crypto = stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'SMTP STARTTLSに失敗しました。'
                );
            }

            smtp_command(
                $fp,
                'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'),
                [250]
            );
        }

        if (!empty($cfg['auth'])) {
            smtp_command($fp, 'AUTH LOGIN', [334]);
            smtp_command(
                $fp,
                base64_encode((string)$cfg['username']),
                [334]
            );
            smtp_command(
                $fp,
                base64_encode((string)$cfg['password']),
                [235]
            );
        }

        $from = (string)$cfg['from_email'];

        smtp_command(
            $fp,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        smtp_command(
            $fp,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        smtp_command($fp, 'DATA', [354]);

        $fromName = mb_encode_mimeheader(
            (string)$cfg['from_name'],
            'UTF-8'
        );

        $subjectEncoded = mb_encode_mimeheader(
            $subject,
            'UTF-8'
        );

        $headers = [
            'From: ' . $fromName . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $subjectEncoded,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if (!empty($cfg['reply_to'])) {
            $headers[] =
                'Reply-To: <' . $cfg['reply_to'] . '>';
        }

        $message =
            implode("\r\n", $headers)
            . "\r\n\r\n"
            . str_replace(
                ["\r\n", "\r", "\n"],
                "\r\n",
                $body
            )
            . "\r\n.";

        smtp_command($fp, $message, [250]);

        smtp_command($fp, 'QUIT', [221]);
    } finally {
        fclose($fp);
    }
}

/* ============================================================
 * 9. POST処理
 * ============================================================ */

$data = load_data();

refresh_survey_status($data);

verify_csrf();

$screen = current_screen();

/*
 * logoutはPOSTに限定。
 */
if ($screen === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        session_unset();
        session_destroy();

        redirect('index.php?screen=login');
    }

    redirect('index.php?screen=list');
}

/* ------------------------------------------------------------
 * ログイン
 * ------------------------------------------------------------ */

if ($screen === 'login') {
    if (is_admin()) {
        redirect('index.php?screen=list');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = (string)post('username');
        $password = (string)post('password');

        $expectedUser = getenv('SURVEY_ADMIN_USER') ?: 'admin';
        $expectedPassword =
            getenv('SURVEY_ADMIN_PASSWORD') ?: 'change-me';

        if (
            hash_equals($expectedUser, $user)
            && hash_equals($expectedPassword, $password)
        ) {
            session_regenerate_id(true);

            $_SESSION['admin_authenticated'] = true;
            $_SESSION['admin_user'] = $user;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            redirect('index.php?screen=list');
        }

        $_SESSION['login_error'] = 'ユーザー名またはパスワードが正しくありません。';
        redirect('index.php?screen=login');
    }
}

/* ------------------------------------------------------------
 * 管理者POST
 * ------------------------------------------------------------ */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $screen !== 'login') {
    require_admin();

    $action = (string)post('action');

    /* アンケート保存 */
    if ($action === 'save_survey') {
        $id = (string)post('id');
        $index = survey_index($data, $id);

        if ($index >= 0) {
            $existing = $data['surveys'][$index];

            $survey = normalize_survey(
                $_POST,
                $existing
            );

            /*
             * 編集保存では現在状態を維持。
             */
            $survey['status'] = $existing['status'];

            $data['surveys'][$index] = $survey;
        } else {
            $survey = normalize_survey($_POST);
            $data['surveys'][] = $survey;
        }

        save_data($data);

        redirect('index.php?screen=list');
    }

    /* 状態変更 */
    if ($action === 'change_status') {
        $id = (string)post('id');
        $newStatus = (string)post('status');
        $index = survey_index($data, $id);

        if ($index >= 0) {
            $current = $data['surveys'][$index]['status'];

            $valid = match ($current) {
                'draft' => $newStatus === 'published',
                'published' =>
                    $newStatus === 'stopped',
                'stopped' =>
                    $newStatus === 'published',
                default => false,
            };

            if ($valid) {
                $data['surveys'][$index]['status'] = $newStatus;
                $data['surveys'][$index]['updatedAt'] = now_iso();
                save_data($data);
            }
        }

        redirect('index.php?screen=list');
    }

    /* 削除 */
    if ($action === 'delete_survey') {
        $id = (string)post('id');
        $index = survey_index($data, $id);

        if ($index >= 0) {
            array_splice($data['surveys'], $index, 1);
            save_data($data);
        }

        redirect('index.php?screen=list');
    }

    /* 複製 */
    if ($action === 'duplicate_survey') {
        $id = (string)post('id');
        $survey = find_survey($data, $id);

        if ($survey) {
            $survey['id'] = uuid();
            $survey['title'] .= '（複製）';
            $survey['status'] = 'draft';
            $survey['createdAt'] = now_iso();
            $survey['updatedAt'] = now_iso();

            $data['surveys'][] = $survey;
            save_data($data);
        }

        redirect('index.php?screen=list');
    }

    /* グループ追加 */
    if ($action === 'add_group') {
        $id = (string)post('id');
        $index = survey_index($data, $id);

        if ($index >= 0) {
            $data['surveys'][$index]['groups'][] = [
                'id' => uuid(),
                'title' => '新しいグループ',
                'questions' => [],
            ];

            renumber_questions($data['surveys'][$index]);
            save_data($data);
        }

        redirect(
            'index.php?screen=edit&id='
            . rawurlencode($id)
        );
    }

    /* 質問追加 */
    if ($action === 'add_question') {
        $surveyId = (string)post('id');
        $groupId = (string)post('group_id');

        $index = survey_index($data, $surveyId);

        if ($index >= 0) {
            foreach (
                $data['surveys'][$index]['groups']
                as &$group
            ) {
                if ($group['id'] === $groupId) {
                    $group['questions'][] = [
                        'id' => uuid(),
                        'text' => '新しい質問',
                        'type' => 'single',
                        'required' => false,
                        'options' => ['選択肢1', '選択肢2'],
                        'branches' => [],
                    ];
                    break;
                }
            }

            unset($group);

            renumber_questions($data['surveys'][$index]);
            save_data($data);
        }

        redirect(
            'index.php?screen=edit&id='
            . rawurlencode($surveyId)
        );
    }

    /* kintone設定 */
    if ($action === 'save_kintone') {
        $data['kintone']['subdomain'] =
            trim((string)post('subdomain'));

        $data['kintone']['app_id'] =
            trim((string)post('app_id'));

        $data['kintone']['login'] =
            trim((string)post('login'));

        $password = (string)post('password');

        if ($password !== '') {
            $data['kintone']['password'] =
                encrypt_secret($password);
        }

        $data['kintone']['ssl_verify'] =
            isset($_POST['ssl_verify']);

        $proxy = trim((string)post('proxy'));

        if ($proxy !== '' && !validate_host_port($proxy)) {
            $_SESSION['flash'] =
                'Proxyは host:port 形式で入力してください。';

            redirect('index.php?screen=kintone');
        }

        $data['kintone']['proxy'] = $proxy;

        $data['kintone']['mapping'] = [
            'organization' =>
                trim((string)post('map_organization')),
            'name' =>
                trim((string)post('map_name')),
            'email' =>
                trim((string)post('map_email')),
            'department' =>
                trim((string)post('map_department')),
            'phone' =>
                trim((string)post('map_phone')),
            'address' =>
                array_values(
                    array_filter(
                        (array)post('map_address', [])
                    )
                ),
        ];

        save_data($data);

        $_SESSION['flash'] = 'kintone設定を保存しました。';

        redirect('index.php?screen=kintone');
    }

    /* kintone接続テスト */
    if ($action === 'test_kintone') {
        try {
            $cfg = kintone_config($data);

            kintone_request(
                $cfg,
                'GET',
                '/k/v1/app.json?app='
                . rawurlencode($cfg['app_id'])
            );

            $data['kintone']['last_test'] = [
                'success' => true,
                'message' => '接続成功',
                'at' => now_iso(),
            ];

            save_data($data);
        } catch (Throwable $e) {
            $data['kintone']['last_test'] = [
                'success' => false,
                'message' => $e->getMessage(),
                'at' => now_iso(),
            ];

            save_data($data);
        }

        redirect('index.php?screen=kintone');
    }

    /* kintone項目再取得 */
    if ($action === 'refresh_kintone_fields') {
        try {
            $cfg = kintone_config($data);

            $fields = kintone_fields($cfg);

            $normalized = [];

            foreach ($fields as $code => $field) {
                $normalized[] = [
                    'code' => $code,
                    'label' => $field['label'] ?? $code,
                    'type' => $field['type'] ?? '',
                ];
            }

            $data['kintone']['fields'] = $normalized;

            save_data($data);

            $_SESSION['flash'] =
                'kintone項目一覧を取得しました。';
        } catch (Throwable $e) {
            $_SESSION['flash'] =
                '項目取得失敗: ' . $e->getMessage();
        }

        redirect('index.php?screen=kintone');
    }

    /* 顧客同期 */
    if ($action === 'sync_customers') {
        try {
            $cfg = kintone_config($data);

            $records = kintone_records(
                $cfg,
                'order by $id asc'
            );

            $mapping = $data['kintone']['mapping'];

            $customers = [];

            foreach ($records as $record) {
                $address = [];

                foreach (($mapping['address'] ?? []) as $code) {
                    $v = kintone_value($record, $code);

                    if ($v !== '') {
                        $address[] = $v;
                    }
                }

                $customers[] = [
                    'id' =>
                        kintone_value($record, '$id'),
                    'organization' =>
                        kintone_value(
                            $record,
                            $mapping['organization']
                        ),
                    'name' =>
                        kintone_value(
                            $record,
                            $mapping['name']
                        ),
                    'email' =>
                        kintone_value(
                            $record,
                            $mapping['email']
                        ),
                    'department' =>
                        kintone_value(
                            $record,
                            $mapping['department']
                        ),
                    'phone' =>
                        kintone_value(
                            $record,
                            $mapping['phone']
                        ),
                    'address' =>
                        implode(' ', $address),
                ];
            }

            $data['customers'] = $customers;

            $data['kintone']['last_sync'] = [
                'success' => true,
                'count' => count($customers),
                'at' => now_iso(),
            ];

            save_data($data);

            $_SESSION['flash'] =
                count($customers)
                . '件の顧客情報を同期しました。';
        } catch (Throwable $e) {
            $data['kintone']['last_sync'] = [
                'success' => false,
                'count' => 0,
                'message' => $e->getMessage(),
                'at' => now_iso(),
            ];

            save_data($data);

            $_SESSION['flash'] =
                '顧客同期失敗: ' . $e->getMessage();
        }

        redirect('index.php?screen=kintone');
    }

    /* SMTP設定 */
    if ($action === 'save_mail') {
        $data['mail']['host'] =
            trim((string)post('host'));

        $data['mail']['port'] =
            (int)post('port', 587);

        $data['mail']['encryption'] =
            in_array(
                post('encryption'),
                ['ssl', 'tls', 'none'],
                true
            )
            ? post('encryption')
            : 'tls';

        $data['mail']['auth'] =
            isset($_POST['auth']);

        $data['mail']['username'] =
            trim((string)post('username'));

        $password = (string)post('password');

        if ($password !== '') {
            $data['mail']['password'] =
                encrypt_secret($password);
        }

        $data['mail']['from_email'] =
            trim((string)post('from_email'));

        $data['mail']['from_name'] =
            trim((string)post('from_name'));

        $data['mail']['reply_to'] =
            trim((string)post('reply_to'));

        save_data($data);

        $_SESSION['flash'] =
            'メールサーバ設定を保存しました。';

        redirect('index.php?screen=mail');
    }

    /* SMTP接続テスト */
    if ($action === 'test_mail') {
        try {
            $cfg = $data['mail'];
            $cfg['password'] =
                decrypt_secret((string)$cfg['password']);

            /*
             * 実際にSMTP接続してEHLOまで行う。
             * テストメールは送信しない。
             */
            $host = (string)$cfg['host'];
            $port = (int)$cfg['port'];

            $transport =
                $cfg['encryption'] === 'ssl'
                ? 'ssl://'
                : 'tcp://';

            $errno = 0;
            $errstr = '';

            $fp = @stream_socket_client(
                $transport . $host . ':' . $port,
                $errno,
                $errstr,
                15
            );

            if (!$fp) {
                throw new RuntimeException(
                    'SMTP接続失敗: ' . $errstr
                );
            }

            smtp_expect($fp, [220]);

            smtp_command(
                $fp,
                'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'),
                [250]
            );

            if ($cfg['encryption'] === 'tls') {
                smtp_command($fp, 'STARTTLS', [220]);

                if (
                    stream_socket_enable_crypto(
                        $fp,
                        true,
                        STREAM_CRYPTO_METHOD_TLS_CLIENT
                    ) !== true
                ) {
                    throw new RuntimeException(
                        'STARTTLSに失敗しました。'
                    );
                }
            }

            fclose($fp);

            $data['mail']['last_test'] = [
                'success' => true,
                'message' => '接続確認済み',
                'at' => now_iso(),
            ];

            save_data($data);
        } catch (Throwable $e) {
            $data['mail']['last_test'] = [
                'success' => false,
                'message' => $e->getMessage(),
                'at' => now_iso(),
            ];

            save_data($data);
        }

        redirect('index.php?screen=mail');
    }

    /* テストメール */
    if ($action === 'send_test_mail') {
        try {
            $cfg = $data['mail'];
            $cfg['password'] =
                decrypt_secret((string)$cfg['password']);

            $to = trim((string)post('test_to'));

            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException(
                    'テスト送信先メールアドレスが不正です。'
                );
            }

            smtp_send_mail(
                $cfg,
                $to,
                'アンケート管理システム テストメール',
                "SMTP接続・メール送信テストです。\r\n"
                . date('Y-m-d H:i:s')
            );

            $_SESSION['flash'] =
                'テストメールを送信しました。';
        } catch (Throwable $e) {
            $_SESSION['flash'] =
                'テストメール送信失敗: '
                . $e->getMessage();
        }

        redirect('index.php?screen=mail');
    }

    /* 実メール送信 */
    if ($action === 'send_mail') {
        $surveyId = (string)post('survey_id');
        $customerIds = array_values(
            array_filter(
                (array)post('customer_ids', [])
            )
        );

        $survey = find_survey($data, $surveyId);

        if (!$survey) {
            redirect('index.php?screen=list');
        }

        try {
            $cfg = $data['mail'];
            $cfg['password'] =
                decrypt_secret((string)$cfg['password']);

            $subject = (string)post('subject');
            $body = (string)post('body');

            $sent = 0;
            $failed = 0;

            foreach ($data['customers'] as $customer) {
                if (!in_array(
                    (string)$customer['id'],
                    $customerIds,
                    true
                )) {
                    continue;
                }

                $to = (string)$customer['email'];

                if (!filter_var(
                    $to,
                    FILTER_VALIDATE_EMAIL
                )) {
                    $failed++;
                    continue;
                }

                $url = (
                    (isset($_SERVER['HTTPS'])
                        && $_SERVER['HTTPS'] !== 'off')
                    ? 'https://'
                    : 'http://'
                )
                . ($_SERVER['HTTP_HOST'] ?? '')
                . '/index.php?screen=answer&id='
                . rawurlencode($surveyId)
                . '&customer='
                . rawurlencode((string)$customer['id']);

                $s = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [
                        $customer['name'],
                        $url,
                    ],
                    $subject
                );

                $b = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [
                        $customer['name'],
                        $url,
                    ],
                    $body
                );

                try {
                    smtp_send_mail(
                        $cfg,
                        $to,
                        $s,
                        $b
                    );

                    $sent++;

                    $data['send_history'][] = [
                        'id' => uuid(),
                        'survey_id' => $surveyId,
                        'customer_id' =>
                            $customer['id'],
                        'email' => $to,
                        'status' => 'sent',
                        'at' => now_iso(),
                    ];
                } catch (Throwable $e) {
                    $failed++;

                    $data['send_history'][] = [
                        'id' => uuid(),
                        'survey_id' => $surveyId,
                        'customer_id' =>
                            $customer['id'],
                        'email' => $to,
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                        'at' => now_iso(),
                    ];
                }
            }

            save_data($data);

            $_SESSION['flash'] =
                "送信完了: {$sent}件 / 失敗: {$failed}件";
        } catch (Throwable $e) {
            $_SESSION['flash'] =
                '送信処理失敗: ' . $e->getMessage();
        }

        redirect(
            'index.php?screen=send&id='
            . rawurlencode($surveyId)
        );
    }

    /* 回答登録 */
    if ($action === 'submit_answer') {
        $surveyId = (string)post('survey_id');

        $survey = find_survey($data, $surveyId);

        if (!$survey) {
            redirect('index.php?screen=list');
        }

        $answers = (array)post('answers', []);

        $_SESSION['pending_answer'] = [
            'survey_id' => $surveyId,
            'answers' => $answers,
            'customer_id' =>
                (string)post('customer_id'),
        ];

        redirect(
            'index.php?screen=confirm&id='
            . rawurlencode($surveyId)
        );
    }

    /* 回答確定 */
    if ($action === 'complete_answer') {
        $pending = $_SESSION['pending_answer'] ?? null;

        if (!is_array($pending)) {
            redirect('index.php?screen=list');
        }

        $surveyId = (string)$pending['survey_id'];
        $survey = find_survey($data, $surveyId);

        if (!$survey) {
            unset($_SESSION['pending_answer']);
            redirect('index.php?screen=list');
        }

        $data['answers'][] = [
            'id' => uuid(),
            'survey_id' => $surveyId,
            'customer_id' =>
                (string)($pending['customer_id'] ?? ''),
            'answers' =>
                (array)($pending['answers'] ?? []),
            'createdAt' => now_iso(),
        ];

        save_data($data);

        unset($_SESSION['pending_answer']);

        redirect(
            'index.php?screen=complete&id='
            . rawurlencode($surveyId)
        );
    }
}

/* ============================================================
 * 10. CSV / PDF
 * ============================================================ */

if (
    $screen === 'analytics'
    && query('export') === 'csv'
) {
    require_admin();

    $id = (string)query('id');
    $survey = find_survey($data, $id);

    if (!$survey) {
        redirect('index.php?screen=list');
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="answers.csv"'
    );

    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');

    fputcsv(
        $out,
        ['回答ID', '回答日時', '顧客ID', '回答データ']
    );

    foreach ($data['answers'] as $answer) {
        if ($answer['survey_id'] !== $id) {
            continue;
        }

        fputcsv(
            $out,
            [
                $answer['id'],
                $answer['createdAt'],
                $answer['customer_id'],
                json_encode(
                    $answer['answers'],
                    JSON_UNESCAPED_UNICODE
                ),
            ]
        );
    }

    fclose($out);
    exit;
}

/*
 * PDFは外部ライブラリなしで最小限のPDFを生成する。
 */
if (
    $screen === 'analytics'
    && query('export') === 'pdf'
) {
    require_admin();

    $id = (string)query('id');
    $survey = find_survey($data, $id);

    if (!$survey) {
        redirect('index.php?screen=list');
    }

    $count = answer_count($data, $id);

    $text =
        APP_NAME
        . "\n"
        . 'アンケート: '
        . $survey['title']
        . "\n"
        . '回答数: '
        . $count;

    $escaped = str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $text
    );

    $content =
        "BT\n"
        . "/F1 16 Tf\n"
        . "50 780 Td\n"
        . "("
        . $escaped
        . ") Tj\n"
        . "ET";

    $objects = [];

    $objects[] =
        "<< /Type /Catalog /Pages 2 0 R >>";

    $objects[] =
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";

    $objects[] =
        "<< /Type /Page /Parent 2 0 R "
        . "/MediaBox [0 0 595 842] "
        . "/Resources << /Font << /F1 4 0 R >> >> "
        . "/Contents 5 0 R >>";

    $objects[] =
        "<< /Type /Font /Subtype /Type1 "
        . "/BaseFont /Helvetica >>";

    $objects[] =
        "<< /Length "
        . strlen($content)
        . " >>\nstream\n"
        . $content
        . "\nendstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $i => $object) {
        $offsets[$i + 1] = strlen($pdf);

        $pdf .=
            ($i + 1)
            . " 0 obj\n"
            . $object
            . "\nendobj\n";
    }

    $xref = strlen($pdf);

    $pdf .=
        "xref\n"
        . "0 "
        . (count($objects) + 1)
        . "\n"
        . "0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf(
            "%010d 00000 n \n",
            $offsets[$i]
        );
    }

    $pdf .=
        "trailer\n"
        . "<< /Size "
        . (count($objects) + 1)
        . " /Root 1 0 R >>\n"
        . "startxref\n"
        . $xref
        . "\n%%EOF";

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="analytics.pdf"'
    );

    echo $pdf;
    exit;
}

/* ============================================================
 * 11. HTML共通
 * ============================================================ */

function page_header(
    string $title,
    bool $admin = true
): void {
    $csrf = csrf_token();
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - <?= h(APP_NAME) ?></title>
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
html,body{
 margin:0;
 padding:0;
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",
 "Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
 color:var(--text);
 background:#f8fafc;
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
a{color:var(--primary);text-decoration:none}
.hidden{display:none!important}
.admin-header{
 min-height:64px;
 background:#0f172a;
 color:#fff;
 display:flex;
 align-items:center;
 padding:0 24px;
 gap:24px;
 box-shadow:0 2px 10px rgba(0,0,0,.12);
}
.admin-logo{
 font-weight:700;
 white-space:nowrap;
 font-size:18px;
}
.admin-nav{
 display:flex;
 gap:4px;
 align-items:center;
}
.admin-nav a,
.admin-nav button{
 height:40px;
 padding:0 14px;
 border:0;
 border-radius:7px;
 color:#cbd5e1;
 background:transparent;
 display:flex;
 align-items:center;
}
.admin-nav a:hover,
.admin-nav a.active,
.admin-nav button:hover{
 background:#1e293b;
 color:#fff;
}
.admin-spacer{flex:1}
.page{
 max-width:1500px;
 margin:0 auto;
 padding:28px;
}
.page-title{
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:16px;
 margin-bottom:24px;
}
.page-title h1{
 margin:0;
 font-size:26px;
}
.page-title p{
 margin:5px 0 0;
 color:var(--gray);
 font-size:13px;
}
.card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:12px;
 box-shadow:var(--shadow);
}
.card-header{
 padding:18px 20px;
 border-bottom:1px solid var(--border);
 display:flex;
 justify-content:space-between;
 align-items:center;
 gap:12px;
}
.card-body{padding:20px}
.btn{
 border:1px solid var(--border);
 background:#fff;
 color:var(--text);
 border-radius:7px;
 padding:9px 14px;
 min-height:40px;
}
.btn:hover{background:#f8fafc}
.btn-primary{
 background:var(--primary);
 color:#fff;
 border-color:var(--primary);
}
.btn-primary:hover{background:var(--primary-dark)}
.btn-success{
 background:var(--success);
 color:#fff;
 border-color:var(--success);
}
.btn-danger{
 background:var(--danger);
 color:#fff;
 border-color:var(--danger);
}
.btn-warning{
 background:var(--warning);
 color:#fff;
 border-color:var(--warning);
}
.btn-sm{
 min-height:32px;
 padding:5px 9px;
 font-size:12px;
}
.btn:disabled{
 opacity:.45;
 cursor:not-allowed;
}
.badge{
 display:inline-flex;
 align-items:center;
 padding:4px 9px;
 border-radius:999px;
 font-size:12px;
 font-weight:600;
 white-space:nowrap;
}
.badge-draft{background:#e2e8f0;color:#475569}
.badge-published{background:#dcfce7;color:#166534}
.badge-stopped{background:#fef3c7;color:#92400e}
.badge-ended{background:#fee2e2;color:#991b1b}
.badge-success{background:#dcfce7;color:#166534}
.badge-danger{background:#fee2e2;color:#991b1b}
.form-grid{
 display:grid;
 grid-template-columns:repeat(2,minmax(0,1fr));
 gap:18px;
}
.form-group{
 display:flex;
 flex-direction:column;
 gap:7px;
}
.form-group.full{grid-column:1/-1}
label{
 font-weight:600;
 font-size:13px;
}
input[type=text],
input[type=email],
input[type=password],
input[type=datetime-local],
input[type=number],
textarea,
select{
 width:100%;
 border:1px solid #cbd5e1;
 border-radius:7px;
 padding:10px 12px;
 background:#fff;
 color:var(--text);
}
textarea{
 resize:vertical;
 min-height:100px;
}
.help{
 color:var(--gray);
 font-size:12px;
}
.actions{
 display:flex;
 align-items:center;
 gap:8px;
 flex-wrap:wrap;
}
.toolbar{
 display:flex;
 gap:10px;
 flex-wrap:wrap;
 align-items:center;
 margin-bottom:16px;
}
.search-box{
 display:flex;
 flex:1;
 min-width:260px;
}
.search-box input{
 border-radius:7px 0 0 7px;
}
.search-box button{
 border:1px solid #cbd5e1;
 border-left:0;
 background:#f8fafc;
 border-radius:0 7px 7px 0;
 padding:0 16px;
}
.table-wrap{overflow-x:auto}
table{
 width:100%;
 border-collapse:collapse;
 min-width:1100px;
}
th,td{
 padding:13px 12px;
 border-bottom:1px solid #e2e8f0;
 text-align:left;
 vertical-align:middle;
 font-size:13px;
}
th{
 background:#f8fafc;
 font-weight:700;
 color:#475569;
}
.action-grid{
 display:flex;
 flex-wrap:wrap;
 gap:5px;
}
.empty{
 padding:45px 20px;
 text-align:center;
 color:var(--gray);
}
.target-banner{
 background:#eff6ff;
 border:1px solid #bfdbfe;
 border-radius:10px;
 padding:15px 18px;
 margin-bottom:20px;
}
.target-banner .label{
 color:#1d4ed8;
 font-size:12px;
 font-weight:700;
}
.target-banner .title{
 font-size:18px;
 font-weight:700;
 margin-top:4px;
}
.group{
 margin-bottom:18px;
 border:1px solid #cbd5e1;
 border-radius:12px;
 background:#f8fafc;
}
.group-header{
 display:flex;
 align-items:center;
 gap:10px;
 padding:12px;
 background:#f1f5f9;
 border-radius:12px 12px 0 0;
}
.group-title-input{flex:1;font-weight:700}
.question-list{
 padding:12px;
 display:flex;
 flex-direction:column;
 gap:10px;
}
.question{
 background:#fff;
 border:1px solid var(--border);
 border-radius:9px;
 padding:13px;
}
.question-header{
 display:flex;
 align-items:center;
 gap:8px;
 margin-bottom:10px;
}
.question-number{
 font-weight:700;
 color:var(--primary);
 min-width:55px;
}
.question-text{flex:1}
.question-body{
 display:grid;
 grid-template-columns:1fr 180px 110px;
 gap:10px;
 align-items:start;
}
.option-row{
 display:flex;
 gap:7px;
 margin-bottom:7px;
}
.option-row input{flex:1}
.result-grid{
 display:grid;
 grid-template-columns:repeat(4,1fr);
 gap:12px;
}
.result-card{
 padding:16px;
 background:#f8fafc;
 border:1px solid var(--border);
 border-radius:9px;
}
.result-card .value{
 font-size:25px;
 font-weight:700;
}
.settings-grid{
 display:grid;
 grid-template-columns:1fr 1fr;
 gap:20px;
}
.status-box{
 margin-top:15px;
 padding:14px;
 border-radius:8px;
 background:#f8fafc;
 border:1px solid var(--border);
}
.respondent{
 min-height:100vh;
 background:#f8fafc;
}
.respondent-header{
 background:#fff;
 border-bottom:1px solid var(--border);
 padding:20px;
}
.respondent-header-inner{
 max-width:760px;
 margin:auto;
}
.respondent-main{
 max-width:760px;
 margin:25px auto;
 padding:0 16px 50px;
}
.respondent-card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:12px;
 padding:24px;
}
.respondent-question{margin:0 0 28px}
.required{
 color:var(--danger);
 font-size:12px;
 margin-left:5px;
}
.respondent-option{
 display:block;
 padding:13px;
 border:1px solid #cbd5e1;
 border-radius:8px;
 margin:8px 0;
}
.respondent-actions{
 display:flex;
 justify-content:space-between;
 gap:10px;
 margin-top:25px;
}
.alert{
 padding:13px 16px;
 border-radius:8px;
 margin-bottom:16px;
 background:#eff6ff;
 border:1px solid #bfdbfe;
}
.alert-danger{
 background:#fee2e2;
 border-color:#fecaca;
 color:#991b1b;
}
.alert-success{
 background:#dcfce7;
 border-color:#bbf7d0;
 color:#166534;
}
.login{
 min-height:100vh;
 display:flex;
 align-items:center;
 justify-content:center;
 padding:20px;
}
.login-card{
 width:min(420px,100%);
 padding:30px;
}
@media(max-width:1000px){
 .form-grid,
 .settings-grid{
  grid-template-columns:1fr;
 }
 .question-body{
  grid-template-columns:1fr;
 }
 .result-grid{
  grid-template-columns:repeat(2,1fr);
 }
}
@media(max-width:700px){
 .admin-header{
  min-height:60px;
  padding:10px 14px;
  flex-wrap:wrap;
 }
 .admin-nav{
  width:100%;
  overflow-x:auto;
 }
 .page{padding:16px}
 .result-grid{
  grid-template-columns:1fr 1fr;
 }
 .respondent-card{padding:18px}
}
</style>
</head>
<body>
<?php if ($admin && is_admin()): ?>
<header class="admin-header">
 <div class="admin-logo"><?= h(APP_NAME) ?></div>

 <nav class="admin-nav">
  <a href="index.php?screen=list">アンケート</a>
  <a href="index.php?screen=kintone">kintone</a>
  <a href="index.php?screen=mail">メール</a>
 </nav>

 <div class="admin-spacer"></div>

 <form method="post"
       action="index.php?screen=logout"
       style="margin:0">
  <input type="hidden"
         name="_csrf"
         value="<?= h($csrf) ?>">
  <button class="btn btn-sm"
          type="submit">ログアウト</button>
 </form>
</header>
<?php endif; ?>
<?php
}

function page_footer(): void
{
    ?>
</body>
</html>
<?php
}

/* ============================================================
 * 12. ログイン画面
 * ============================================================ */

if ($screen === 'login') {
    $error = $_SESSION['login_error'] ?? '';
    unset($_SESSION['login_error']);

    page_header('ログイン', false);
    ?>

<div class="login">
 <div class="card login-card">
  <h1><?= h(APP_NAME) ?></h1>
  <p class="help">管理者ログイン</p>

  <?php if ($error): ?>
   <div class="alert alert-danger">
    <?= h($error) ?>
   </div>
  <?php endif; ?>

  <form method="post"
        action="index.php?screen=login">

   <input type="hidden"
          name="_csrf"
          value="<?= h(csrf_token()) ?>">

   <div class="form-group">
    <label>ユーザー名</label>
    <input type="text"
           name="username"
           autocomplete="username"
           required>
   </div>

   <div class="form-group"
        style="margin-top:15px">
    <label>パスワード</label>
    <input type="password"
           name="password"
           autocomplete="current-password"
           required>
   </div>

   <div style="margin-top:20px">
    <button class="btn btn-primary"
            type="submit"
            style="width:100%">
     ログイン
    </button>
   </div>
  </form>
 </div>
</div>

<?php
    page_footer();
    exit;
}

/* ============================================================
 * 13. 回答者画面
 * ============================================================ */

if (
    $screen === 'answer'
    || $screen === 'confirm'
    || $screen === 'complete'
) {
    $id = (string)query('id');
    $survey = find_survey($data, $id);

    if (!$survey) {
        http_response_code(404);
        exit('アンケートが存在しません。');
    }

    if ($survey['status'] !== 'published') {
        http_response_code(403);
        exit('このアンケートは現在回答できません。');
    }

    if (
        !empty($survey['endAt'])
        && strtotime($survey['endAt']) < time()
    ) {
        http_response_code(403);
        exit('回答期間が終了しています。');
    }

    $customerId = (string)query(
        'customer',
        ''
    );

    if ($screen === 'confirm') {
        $pending =
            $_SESSION['pending_answer'] ?? null;

        if (
            !is_array($pending)
            || $pending['survey_id'] !== $id
        ) {
            redirect(
                'index.php?screen=answer&id='
                . rawurlencode($id)
            );
        }
    }

    page_header($survey['title'], false);
    ?>

<div class="respondent">
 <div class="respondent-header">
  <div class="respondent-header-inner">
   <strong><?= h($survey['title']) ?></strong>
  </div>
 </div>

 <main class="respondent-main">
  <div class="respondent-card">

<?php if ($screen === 'answer'): ?>

<h1><?= h($survey['title']) ?></h1>

<?php if ($survey['description']): ?>
<p><?= nl2br(h($survey['description'])) ?></p>
<?php endif; ?>

<form method="post"
      action="index.php?screen=answer&id=<?= h($id) ?>">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="submit_answer">

<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">

<input type="hidden"
       name="customer_id"
       value="<?= h($customerId) ?>">

<?php foreach ($survey['groups'] as $group): ?>

<h2><?= h($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>

<div class="respondent-question">

<strong>
 <?= h($question['number'] ?? '') ?>
 <?= h($question['text']) ?>

 <?php if (!empty($question['required'])): ?>
  <span class="required">必須</span>
 <?php endif; ?>
</strong>

<?php if ($question['type'] === 'single'): ?>

<?php foreach ($question['options'] as $option): ?>
<label class="respondent-option">
 <input type="radio"
        name="answers[<?= h($question['id']) ?>]"
        value="<?= h($option) ?>"
        <?= !empty($question['required'])
            ? 'required'
            : '' ?>>
 <?= h($option) ?>
</label>
<?php endforeach; ?>

<?php elseif ($question['type'] === 'multiple'): ?>

<?php foreach ($question['options'] as $option): ?>
<label class="respondent-option">
 <input type="checkbox"
        name="answers[<?= h($question['id']) ?>][]"
        value="<?= h($option) ?>">
 <?= h($option) ?>
</label>
<?php endforeach; ?>

<?php else: ?>

<textarea
 name="answers[<?= h($question['id']) ?>]"
 <?= !empty($question['required'])
     ? 'required'
     : '' ?>></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>
<?php endforeach; ?>

<div class="respondent-actions">
 <span></span>
 <button class="btn btn-primary"
         type="submit">
  回答を確認
 </button>
</div>

</form>

<?php elseif ($screen === 'confirm'): ?>

<h1>回答確認</h1>

<p>以下の内容で送信します。</p>

<?php
$pending =
    $_SESSION['pending_answer'];

foreach ($survey['groups'] as $group):
?>

<h2><?= h($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>

<div class="respondent-question">
<strong><?= h($question['number'] ?? '') ?>
 <?= h($question['text']) ?></strong>

<div style="margin-top:8px">
<?php
$value =
    $pending['answers'][$question['id']]
    ?? '';

if (is_array($value)) {
    echo nl2br(
        h(implode(', ', $value))
    );
} else {
    echo nl2br(h($value));
}
?>
</div>
</div>

<?php endforeach; ?>
<?php endforeach; ?>

<form method="post"
      action="index.php?screen=confirm&id=<?= h($id) ?>">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="complete_answer">

<button class="btn"
        type="button"
        onclick="history.back()">
 戻って修正
</button>

<button class="btn btn-primary"
        type="submit">
 回答を送信
</button>

</form>

<?php else: ?>

<h1>回答ありがとうございました</h1>

<p>
回答を受け付けました。
</p>

<?php endif; ?>

  </div>
 </main>
</div>

<?php
    page_footer();
    exit;
}

/* ============================================================
 * 14. 管理者画面
 * ============================================================ */

require_admin();

/* ============================================================
 * 一覧
 * ============================================================ */

if ($screen === 'list') {
    $search = trim((string)query('q'));
    $status = (string)query('status', 'all');
    $sort = (string)query('sort', 'updated_desc');

    $surveys = $data['surveys'];

    $surveys = array_filter(
        $surveys,
        function ($survey) use ($search, $status) {
            if (
                $search !== ''
                && mb_stripos(
                    $survey['title'],
                    $search
                ) === false
            ) {
                return false;
            }

            if (
                $status !== 'all'
                && $survey['status'] !== $status
            ) {
                return false;
            }

            return true;
        }
    );

    usort(
        $surveys,
        function ($a, $b) use ($sort) {
            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        $a['updatedAt'],
                        $b['updatedAt']
                    ),
                'answers_desc' =>
                    answer_count(
                        load_data(),
                        $b['id']
                    )
                    <=>
                    answer_count(
                        load_data(),
                        $a['id']
                    ),
                'answers_asc' =>
                    answer_count(
                        load_data(),
                        $a['id']
                    )
                    <=>
                    answer_count(
                        load_data(),
                        $b['id']
                    ),
                'start_desc' =>
                    strcmp(
                        $b['startAt'],
                        $a['startAt']
                    ),
                'start_asc' =>
                    strcmp(
                        $a['startAt'],
                        $b['startAt']
                    ),
                default =>
                    strcmp(
                        $b['updatedAt'],
                        $a['updatedAt']
                    ),
            };
        }
    );

    page_header('アンケート一覧');
    ?>

<main class="page">

<div class="page-title">
 <div>
  <h1>アンケート一覧</h1>
  <p>アンケート管理の起点です。</p>
 </div>

 <a class="btn btn-primary"
    href="index.php?screen=edit">
  新規作成
 </a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
<div class="alert alert-success">
 <?= h($_SESSION['flash']) ?>
</div>
<?php unset($_SESSION['flash']); endif; ?>

<form class="toolbar"
      method="get"
      action="index.php">

<input type="hidden"
       name="screen"
       value="list">

<div class="search-box">
 <input type="text"
        name="q"
        value="<?= h($search) ?>"
        placeholder="タイトルを検索">
 <button type="submit">検索</button>
</div>

<select name="status">
 <option value="all">すべて</option>
 <option value="published"
  <?= $status === 'published'
      ? 'selected' : '' ?>>公開中</option>
 <option value="draft"
  <?= $status === 'draft'
      ? 'selected' : '' ?>>下書き</option>
 <option value="stopped"
  <?= $status === 'stopped'
      ? 'selected' : '' ?>>停止</option>
 <option value="ended"
  <?= $status === 'ended'
      ? 'selected' : '' ?>>終了</option>
</select>

<select name="sort">
 <option value="updated_desc">更新日：新しい順</option>
 <option value="updated_asc">更新日：古い順</option>
 <option value="answers_desc">回答数：多い順</option>
 <option value="answers_asc">回答数：少ない順</option>
 <option value="start_desc">開始日：新しい順</option>
 <option value="start_asc">開始日：古い順</option>
</select>

</form>

<div class="card">
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

<?php if (!$surveys): ?>

<tr>
 <td colspan="7">
  <div class="empty">
   アンケートはありません。
  </div>
 </td>
</tr>

<?php else: ?>

<?php foreach ($surveys as $survey): ?>

<tr>

<td>
 <strong><?= h($survey['title']) ?></strong>
</td>

<td><?= h($survey['createdAt']) ?></td>
<td><?= h($survey['updatedAt']) ?></td>

<td>
 <?= h($survey['startAt']) ?>
 <br>
 ～
 <?= h($survey['endAt']) ?>
</td>

<td>
 <span class="badge <?= h(
     status_class($survey['status'])
 ) ?>">
 <?= h(status_label($survey['status'])) ?>
 </span>
</td>

<td><?= answer_count($data, $survey['id']) ?></td>

<td class="actions-cell">

<div class="action-grid">

<a class="btn btn-sm"
   href="index.php?screen=edit&id=<?= h($survey['id']) ?>">
確認・編集
</a>

<a class="btn btn-sm"
   href="index.php?screen=preview&id=<?= h($survey['id']) ?>">
プレビュー
</a>

<a class="btn btn-sm"
   href="index.php?screen=analytics&id=<?= h($survey['id']) ?>">
集計
</a>

<a class="btn btn-sm"
   href="index.php?screen=send&id=<?= h($survey['id']) ?>">
送信
</a>

<form method="post"
      style="display:inline"
      onsubmit="return confirm('複製しますか？')">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="duplicate_survey">

<input type="hidden"
       name="id"
       value="<?= h($survey['id']) ?>">

<button class="btn btn-sm"
        type="submit">
 複製
</button>

</form>

<form method="post"
      style="display:inline"
      onsubmit="return confirm('削除しますか？')">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="delete_survey">

<input type="hidden"
       name="id"
       value="<?= h($survey['id']) ?>">

<button class="btn btn-danger btn-sm"
        type="submit">
 削除
</button>

</form>

<?php if ($survey['status'] === 'draft'): ?>

<form method="post"
      style="display:inline"
      onsubmit="return confirm('公開しますか？')">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="change_status">

<input type="hidden"
       name="id"
       value="<?= h($survey['id']) ?>">

<input type="hidden"
       name="status"
       value="published">

<button class="btn btn-success btn-sm"
        type="submit">
 公開
</button>

</form>

<?php elseif ($survey['status'] === 'published'): ?>

<form method="post"
      style="display:inline"
      onsubmit="return confirm('停止しますか？')">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="change_status">

<input type="hidden"
       name="id"
       value="<?= h($survey['id']) ?>">

<input type="hidden"
       name="status"
       value="stopped">

<button class="btn btn-warning btn-sm"
        type="submit">
 停止
</button>

</form>

<?php elseif ($survey['status'] === 'stopped'): ?>

<form method="post"
      style="display:inline"
      onsubmit="return confirm('再開しますか？')">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="change_status">

<input type="hidden"
       name="id"
       value="<?= h($survey['id']) ?>">

<input type="hidden"
       name="status"
       value="published">

<button class="btn btn-success btn-sm"
        type="submit">
 再開
</button>

</form>

<?php endif; ?>

</div>
</td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

   </tbody>
  </table>
 </div>
</div>

</main>

<?php
    page_footer();
    exit;
}

/* ============================================================
 * 編集
 * ============================================================ */

if ($screen === 'edit') {
    $id = (string)query('id');
    $survey = $id !== ''
        ? find_survey($data, $id)
        : null;

    if (!$survey) {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [],
        ];
    }

    page_header(
        $id === '' ? 'アンケート作成' : 'アンケート編集'
    );
    ?>

<main class="page">

<div class="page-title">
 <div>
  <h1>
   <?= $id === ''
       ? 'アンケート作成'
       : 'アンケート編集' ?>
  </h1>
 </div>
</div>

<form method="post"
      action="index.php?screen=edit">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="id"
       value="<?= h($survey['id']) ?>">

<div class="card">
 <div class="card-body">

<div class="form-grid">

<div class="form-group full">
 <label>アンケートタイトル</label>
 <input type="text"
        name="title"
        value="<?= h($survey['title']) ?>"
        required>
</div>

<div class="form-group full">
 <label>アンケート説明</label>
 <textarea name="description"><?= h(
     $survey['description']
 ) ?></textarea>
</div>

<div class="form-group">
 <label>開始日時</label>
 <input type="datetime-local"
        name="startAt"
        value="<?= h($survey['startAt']) ?>">
</div>

<div class="form-group">
 <label>終了日時</label>
 <input type="datetime-local"
        name="endAt"
        value="<?= h($survey['endAt']) ?>">
</div>

<div class="form-group">
 <label>質問番号の採番方式</label>
 <select name="numbering">
  <option value="global"
   <?= $survey['numbering'] === 'global'
       ? 'selected' : '' ?>>
   アンケート全体で通番
  </option>
  <option value="group"
   <?= $survey['numbering'] === 'group'
       ? 'selected' : '' ?>>
   グループ毎に採番
  </option>
 </select>
</div>

<div class="form-group">
 <label>現在の状態</label>
 <div>
  <span class="badge <?= h(
      status_class($survey['status'])
  ) ?>">
   <?= h(status_label($survey['status'])) ?>
  </span>
 </div>
</div>

</div>

</div>
</div>

<div style="margin-top:20px">

<?php foreach ($survey['groups'] as $group): ?>

<div class="group">

<div class="group-header">
 <strong>グループ</strong>
 <input class="group-title-input"
        type="text"
        value="<?= h($group['title']) ?>"
        readonly>
</div>

<div class="question-list">

<?php foreach ($group['questions'] as $question): ?>

<div class="question">

<div class="question-header">
 <span class="question-number">
  <?= h($question['number'] ?? '') ?>
 </span>

 <strong>
  <?= h($question['text']) ?>
 </strong>
</div>

<div class="question-body">

<div>
 <label>質問文</label>
 <input type="text"
        value="<?= h($question['text']) ?>"
        readonly>
</div>

<div>
 <label>回答形式</label>
 <select disabled>
  <option>
   <?= match ($question['type']) {
       'single' => '単一選択',
       'multiple' => '複数選択',
       default => '自由記述'
   } ?>
  </option>
 </select>
</div>

<div>
 <label>必須</label>
 <div>
  <?= !empty($question['required'])
      ? '必須'
      : '任意' ?>
 </div>
</div>

</div>

<?php if ($question['options']): ?>

<div style="margin-top:10px">

<?php foreach ($question['options'] as $option): ?>

<div class="option-row">
 <input type="text"
        value="<?= h($option) ?>"
        readonly>
</div>

<?php endforeach; ?>

</div>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<div style="padding:0 12px 12px">

<form method="post">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="add_question">

<input type="hidden"
       name="id"
       value="<?= h($survey['id']) ?>">

<input type="hidden"
       name="group_id"
       value="<?= h($group['id']) ?>">

<button class="btn btn-sm"
        type="submit">
 質問を追加
</button>

</form>

</div>

</div>

<?php endforeach; ?>

<form method="post">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="add_group">

<input type="hidden"
       name="id"
       value="<?= h($survey['id']) ?>">

<button class="btn"
        type="submit">
 グループを追加
</button>

</form>

</div>

<div class="actions"
     style="margin-top:20px">

<a class="btn"
   href="index.php?screen=list">
 キャンセル
</a>

<?php if ($survey['id']): ?>

<a class="btn"
   href="index.php?screen=preview&id=<?= h(
       $survey['id']
   ) ?>">
 プレビュー
</a>

<?php endif; ?>

<button class="btn btn-primary"
        type="submit">
 保存して一覧へ
</button>

</div>

</form>

</main>

<?php
    page_footer();
    exit;
}

/* ============================================================
 * プレビュー
 * ============================================================ */

if ($screen === 'preview') {
    $id = (string)query('id');
    $survey = find_survey($data, $id);

    if (!$survey) {
        redirect('index.php?screen=list');
    }

    page_header('プレビュー');
    ?>

<main class="page">

<div class="page-title">
 <div>
  <h1>プレビュー</h1>
  <p>実際のメール送信は行いません。</p>
 </div>

 <a class="btn"
    href="index.php?screen=edit&id=<?= h($id) ?>">
  編集へ戻る
 </a>
</div>

<div class="card">
 <div class="card-body">

<h1><?= h($survey['title']) ?></h1>

<p><?= nl2br(h($survey['description'])) ?></p>

<?php foreach ($survey['groups'] as $group): ?>

<h2><?= h($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>

<div style="margin:25px 0">

<h3>
 <?= h($question['number'] ?? '') ?>
 <?= h($question['text']) ?>

<?php if (!empty($question['required'])): ?>
<span class="required">必須</span>
<?php endif; ?>

</h3>

<?php if ($question['type'] === 'single'): ?>

<?php foreach ($question['options'] as $option): ?>
<div class="respondent-option">
 <input type="radio" disabled>
 <?= h($option) ?>
</div>
<?php endforeach; ?>

<?php elseif ($question['type'] === 'multiple'): ?>

<?php foreach ($question['options'] as $option): ?>
<div class="respondent-option">
 <input type="checkbox" disabled>
 <?= h($option) ?>
</div>
<?php endforeach; ?>

<?php else: ?>

<textarea disabled></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>
<?php endforeach; ?>

</div>
</div>

</main>

<?php
    page_footer();
    exit;
}

/* ============================================================
 * 集計
 * ============================================================ */

if ($screen === 'analytics') {
    $id = (string)query('id');

    /*
     * 対象アンケート未指定では絶対に表示しない。
     */
    if ($id === '') {
        redirect('index.php?screen=list');
    }

    $survey = find_survey($data, $id);

    if (!$survey) {
        redirect('index.php?screen=list');
    }

    $answers = array_filter(
        $data['answers'],
        fn($a) =>
            ($a['survey_id'] ?? '') === $id
    );

    page_header('回答集計・分析');
    ?>

<main class="page">

<div class="page-title">
 <div>
  <h1>回答集計・分析</h1>
 </div>

 <div class="actions">
  <a class="btn"
     href="index.php?screen=analytics&id=<?= h($id) ?>&export=csv">
   CSV
  </a>

  <a class="btn"
     href="index.php?screen=analytics&id=<?= h($id) ?>&export=pdf">
   PDF
  </a>
 </div>
</div>

<div class="target-banner">
 <div class="label">対象アンケート</div>
 <div class="title">
  <?= h($survey['title']) ?>
 </div>
</div>

<div class="result-grid">

<div class="result-card">
 <div>送信対象者数</div>
 <div class="value">
  <?= count($data['customers']) ?>
 </div>
</div>

<div class="result-card">
 <div>回答数</div>
 <div class="value">
  <?= count($answers) ?>
 </div>
</div>

<div class="result-card">
 <div>未回答数</div>
 <div class="value">
  <?= max(
      0,
      count($data['customers'])
      - count($answers)
  ) ?>
 </div>
</div>

<div class="result-card">
 <div>回答率</div>
 <div class="value">
  <?=
  count($data['customers']) > 0
      ? round(
          count($answers)
          / count($data['customers'])
          * 100,
          1
      )
      : 0
  ?>%
 </div>
</div>

</div>

<div class="card"
     style="margin-top:20px">

<div class="card-header">
 <strong>個別回答</strong>
</div>

<div class="card-body">

<?php if (!$answers): ?>

<div class="empty">
現在、回答データはありません
</div>

<?php else: ?>

<?php foreach ($answers as $answer): ?>

<div style="
 border:1px solid #dbe2ea;
 border-radius:8px;
 padding:15px;
 margin-bottom:10px">

<strong>
<?= h($answer['createdAt']) ?>
</strong>

<pre style="white-space:pre-wrap"><?=
h(json_encode(
    $answer['answers'],
    JSON_UNESCAPED_UNICODE
    | JSON_PRETTY_PRINT
))
?></pre>

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>
</div>

</main>

<?php
    page_footer();
    exit;
}

/* ============================================================
 * 送信
 * ============================================================ */

if ($screen === 'send') {
    $id = (string)query('id');

    if ($id === '') {
        redirect('index.php?screen=list');
    }

    $survey = find_survey($data, $id);

    if (!$survey) {
        redirect('index.php?screen=list');
    }

    $history = array_filter(
        $data['send_history'],
        fn($h) =>
            ($h['survey_id'] ?? '') === $id
    );

    page_header('顧客選択・メール送信');
    ?>

<main class="page">

<div class="page-title">
 <div>
  <h1>顧客選択・メール送信</h1>
 </div>
</div>

<div class="target-banner">
 <div class="label">対象アンケート</div>
 <div class="title">
  <?= h($survey['title']) ?>
 </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
<div class="alert alert-success">
 <?= h($_SESSION['flash']) ?>
</div>
<?php unset($_SESSION['flash']); endif; ?>

<div class="card">
 <div class="card-header">
  <strong>顧客選択・送信</strong>
 </div>

 <div class="card-body">

<form method="post"
      action="index.php?screen=send&id=<?= h($id) ?>"
      onsubmit="return confirm('選択した顧客へ送信しますか？')">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">

<div class="form-grid">

<div class="form-group full">
 <label>件名</label>
 <input type="text"
        name="subject"
        value="<?= h(
            '【アンケート】'
            . $survey['title']
        ) ?>"
        required>
</div>

<div class="form-group full">
 <label>本文</label>
 <textarea name="body" required>こんにちは、{顧客名}様。

以下のURLよりアンケートにご回答ください。

{アンケートURL}</textarea>

 <div class="help">
  使用可能な変数:
  {顧客名} / {アンケートURL}
 </div>
</div>

</div>

<div class="table-wrap"
     style="margin-top:20px">

<table>
<thead>
<tr>
<th>選択</th>
<th>組織名</th>
<th>氏名</th>
<th>メール</th>
<th>部署</th>
<th>電話</th>
<th>住所</th>
</tr>
</thead>

<tbody>

<?php foreach ($data['customers'] as $customer): ?>

<tr>
<td>
 <input type="checkbox"
        name="customer_ids[]"
        value="<?= h($customer['id']) ?>">
</td>
<td><?= h($customer['organization']) ?></td>
<td><?= h($customer['name']) ?></td>
<td><?= h($customer['email']) ?></td>
<td><?= h($customer['department']) ?></td>
<td><?= h($customer['phone']) ?></td>
<td><?= h($customer['address']) ?></td>
</tr>

<?php endforeach; ?>

</tbody>
</table>

</div>

<div style="margin-top:20px">
<button class="btn btn-primary"
        type="submit">
 一括送信
</button>
</div>

</form>

</div>
</div>

<div class="card"
     style="margin-top:20px">

<div class="card-header">
 <strong>送信履歴</strong>
</div>

<div class="card-body">

<?php if (!$history): ?>

<div class="empty">
送信履歴はありません。
</div>

<?php else: ?>

<?php foreach (array_reverse($history) as $item): ?>

<div style="
 padding:10px;
 border-bottom:1px solid #e2e8f0">

<?= h($item['at']) ?>
 /
 <?= h($item['email']) ?>
 /
 <span class="badge <?= $item['status'] === 'sent'
     ? 'badge-success'
     : 'badge-danger' ?>">
  <?= $item['status'] === 'sent'
      ? '送信成功'
      : '送信失敗' ?>
 </span>

<?php if (!empty($item['error'])): ?>
<div class="help">
<?= h($item['error']) ?>
</div>
<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>
</div>

</main>

<?php
    page_footer();
    exit;
}

/* ============================================================
 * kintone設定
 * ============================================================ */

if ($screen === 'kintone') {
    $k = $data['kintone'];
    $fields = $k['fields'] ?? [];

    page_header('kintone連携設定');
    ?>

<main class="page">

<div class="page-title">
 <div>
  <h1>kintone連携設定</h1>
  <p>顧客情報取得・同期に使用します。</p>
 </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
<div class="alert alert-success">
 <?= h($_SESSION['flash']) ?>
</div>
<?php unset($_SESSION['flash']); endif; ?>

<div class="settings-grid">

<div class="card">
 <div class="card-header">
  <strong>接続設定</strong>
 </div>

 <div class="card-body">

<form method="post"
      action="index.php?screen=kintone">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="form-group">
 <label>サブドメイン</label>
 <input type="text"
        name="subdomain"
        value="<?= h($k['subdomain']) ?>"
        placeholder="example"
        required>
 <div class="help">
  https://example.cybozu.com の example 部分
 </div>
</div>

<div class="form-group"
     style="margin-top:15px">
 <label>顧客管理アプリID</label>
 <input type="number"
        name="app_id"
        value="<?= h($k['app_id']) ?>"
        required>
</div>

<div class="form-group"
     style="margin-top:15px">
 <label>ログイン名</label>
 <input type="text"
        name="login"
        value="<?= h($k['login']) ?>"
        autocomplete="username"
        required>
</div>

<div class="form-group"
     style="margin-top:15px">
 <label>パスワード</label>
 <input type="password"
        name="password"
        autocomplete="new-password">
 <div class="help">
  未入力の場合は現在の設定を維持します。
 </div>
</div>

<div class="form-group"
     style="margin-top:15px">
 <label>Proxy</label>
 <input type="text"
        name="proxy"
        value="<?= h($k['proxy']) ?>"
        placeholder="proxy.example.local:8080">
 <div class="help">
  サーバとポート番号を分けず、host:port形式で指定します。
 </div>
</div>

<div class="form-group"
     style="margin-top:15px">

<label>
 <input type="checkbox"
        name="ssl_verify"
        <?= !empty($k['ssl_verify'])
            ? 'checked'
            : '' ?>>
 SSL証明書を検証する
</label>

</div>

<div class="actions"
     style="margin-top:20px">

<button class="btn btn-primary"
        type="submit">
 設定保存
</button>

</div>

</form>

<hr>

<div class="actions">

<form method="post">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="test_kintone">

<button class="btn"
        type="submit">
 接続テスト
</button>

</form>

<form method="post">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="refresh_kintone_fields">

<button class="btn"
        type="submit">
 項目一覧を再取得
</button>

</form>

<form method="post">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="sync_customers">

<button class="btn"
        type="submit">
 顧客情報を同期
</button>

</form>

</div>

<?php if (!empty($k['last_test'])): ?>

<div class="status-box">

<strong>接続テスト</strong>

<div class="<?= !empty($k['last_test']['success'])
    ? 'badge badge-success'
    : 'badge badge-danger' ?>">

<?= h($k['last_test']['message']) ?>

</div>

<div class="help">
<?= h($k['last_test']['at']) ?>
</div>

</div>

<?php endif; ?>

<?php if (!empty($k['last_sync'])): ?>

<div class="status-box">

<strong>同期結果</strong>

<div>
<?= h(
    $k['last_sync']['count'] ?? 0
) ?>件
</div>

<?php if (!empty($k['last_sync']['message'])): ?>
<div class="alert alert-danger">
<?= h($k['last_sync']['message']) ?>
</div>
<?php endif; ?>

</div>

<?php endif; ?>

 </div>
</div>

<div class="card">
 <div class="card-header">
  <strong>顧客項目マッピング</strong>
 </div>

 <div class="card-body">

<form method="post">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="save_kintone">

<?php
$mapping = $k['mapping'];
$selectOptions =
    '<option value="">未設定</option>';

foreach ($fields as $field) {
    $selectOptions .=
        '<option value="'
        . h($field['code'])
        . '">'
        . h($field['label'])
        . ' ('
        . h($field['code'])
        . ')</option>';
}
?>

<?php
$mappingRows = [
    'organization' => '組織名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署名',
    'phone' => '電話番号',
];
?>

<?php foreach ($mappingRows as $key => $label): ?>

<div class="form-group"
     style="margin-bottom:15px">

<label><?= h($label) ?></label>

<select name="map_<?= h($key) ?>">

<option value="">未設定</option>

<?php foreach ($fields as $field): ?>

<option value="<?= h($field['code']) ?>"
 <?= ($mapping[$key] ?? '') === $field['code']
     ? 'selected'
     : '' ?>>
 <?= h($field['label']) ?>
 (<?= h($field['code']) ?>)
</option>

<?php endforeach; ?>

</select>

</div>

<?php endforeach; ?>

<div class="form-group">

<label>住所</label>

<div>

<?php foreach ($fields as $field): ?>

<label style="
 display:block;
 font-weight:400;
 margin:5px 0">

<input type="checkbox"
       name="map_address[]"
       value="<?= h($field['code']) ?>"
 <?= in_array(
     $field['code'],
     $mapping['address'] ?? [],
     true
 ) ? 'checked' : '' ?>>

<?= h($field['label']) ?>
(<?= h($field['code']) ?>)

</label>

<?php endforeach; ?>

</div>

<div class="help">
住所は複数フィールドを選択できます。
</div>

</div>

<button class="btn btn-primary"
        type="submit">
 マッピングを保存
</button>

</form>

 </div>
</div>

</div>

<div class="card"
     style="margin-top:20px">

<div class="card-header">
 <strong>取得済みkintone項目</strong>
</div>

<div class="card-body">

<?php if (!$fields): ?>

<div class="empty">
まだ項目を取得していません。
</div>

<?php else: ?>

<table>
<thead>
<tr>
<th>フィールドコード</th>
<th>表示名</th>
<th>型</th>
</tr>
</thead>
<tbody>

<?php foreach ($fields as $field): ?>

<tr>
<td><?= h($field['code']) ?></td>
<td><?= h($field['label']) ?></td>
<td><?= h($field['type']) ?></td>
</tr>

<?php endforeach; ?>

</tbody>
</table>

<?php endif; ?>

</div>
</div>

</main>

<?php
    page_footer();
    exit;
}

/* ============================================================
 * メール設定
 * ============================================================ */

if ($screen === 'mail') {
    $mail = $data['mail'];

    page_header('メールサーバ設定');
    ?>

<main class="page">

<div class="page-title">
 <div>
  <h1>メールサーバ設定</h1>
  <p>SMTPによるメール送信設定です。</p>
 </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
<div class="alert alert-success">
 <?= h($_SESSION['flash']) ?>
</div>
<?php unset($_SESSION['flash']); endif; ?>

<div class="card">
 <div class="card-body">

<form method="post">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="save_mail">

<div class="form-grid">

<div class="form-group">
 <label>SMTPサーバ</label>
 <input type="text"
        name="host"
        value="<?= h($mail['host']) ?>"
        required>
</div>

<div class="form-group">
 <label>SMTPポート</label>
 <input type="number"
        name="port"
        value="<?= h($mail['port']) ?>"
        min="1"
        max="65535"
        required>
</div>

<div class="form-group">
 <label>暗号化方式</label>
 <select name="encryption">
  <option value="ssl"
   <?= $mail['encryption'] === 'ssl'
       ? 'selected' : '' ?>>
   SSL
  </option>
  <option value="tls"
   <?= $mail['encryption'] === 'tls'
       ? 'selected' : '' ?>>
   TLS
  </option>
  <option value="none"
   <?= $mail['encryption'] === 'none'
       ? 'selected' : '' ?>>
   なし
  </option>
 </select>
</div>

<div class="form-group">
 <label>
  <input type="checkbox"
         name="auth"
         <?= !empty($mail['auth'])
             ? 'checked'
             : '' ?>>
  SMTP認証
 </label>
</div>

<div class="form-group">
 <label>SMTPユーザー名</label>
 <input type="text"
        name="username"
        value="<?= h($mail['username']) ?>">
</div>

<div class="form-group">
 <label>SMTPパスワード</label>
 <input type="password"
        name="password"
        autocomplete="new-password">
</div>

<div class="form-group">
 <label>送信元メールアドレス</label>
 <input type="email"
        name="from_email"
        value="<?= h($mail['from_email']) ?>"
        required>
</div>

<div class="form-group">
 <label>送信元名</label>
 <input type="text"
        name="from_name"
        value="<?= h($mail['from_name']) ?>">
</div>

<div class="form-group">
 <label>返信先メールアドレス</label>
 <input type="email"
        name="reply_to"
        value="<?= h($mail['reply_to']) ?>">
</div>

</div>

<div class="actions"
     style="margin-top:20px">

<button class="btn btn-primary"
        type="submit">
 設定保存
</button>

</div>

</form>

<hr>

<div class="actions">

<form method="post">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="test_mail">

<button class="btn"
        type="submit">
 接続テスト
</button>

</form>

<form method="post">

<input type="hidden"
       name="_csrf"
       value="<?= h(csrf_token()) ?>">

<input type="hidden"
       name="action"
       value="send_test_mail">

<input type="email"
       name="test_to"
       placeholder="test@example.com"
       required>

<button class="btn"
        type="submit">
 テストメール送信
</button>

</form>

</div>

<?php if (!empty($mail['last_test'])): ?>

<div class="status-box">

<strong>接続状態</strong>

<div class="<?= !empty($mail['last_test']['success'])
    ? 'badge badge-success'
    : 'badge badge-danger' ?>">

<?= h($mail['last_test']['message']) ?>

</div>

<div class="help">
<?= h($mail['last_test']['at']) ?>
</div>

</div>

<?php endif; ?>

 </div>
</div>

</main>

<?php
    page_footer();
    exit;
}

/* ============================================================
 * 未処理画面
 * ============================================================ */

redirect('index.php?screen=list');
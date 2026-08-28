<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 * Apache 2.4 / PHP 8.5
 *
 * - 単一エントリーポイント
 * - DBなし
 * - PHP cURLなし
 * - PHP mail()なし
 * - 管理者認証なし（POC）
 * - Canvasなし
 * - サーバー側JSON永続化
 *
 * 画面:
 *   list
 *   edit
 *   preview
 *   send
 *   analytics
 *   kintone
 *   mail
 *   answer
 *   confirm
 *   complete
 *
 * 外部サービス:
 *   kintone REST API
 *   SMTP
 *
 * kintone:
 *   ログイン名 + パスワード
 *   X-Cybozu-Authorization
 *
 * 重要:
 *   kintoneの設定保存・接続テスト・項目取得・同期を分離する。
 *   APIエラーを画面で確認可能にする。
 *   API通信そのものを画面遷移用リダイレクトと混同しない。
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理システム';
const DATA_DIR_NAME = 'data';

$APP_DIR  = __DIR__;
$DATA_DIR = $APP_DIR . DIRECTORY_SEPARATOR . DATA_DIR_NAME;

if (!is_dir($DATA_DIR)) {
    if (!@mkdir($DATA_DIR, 0770, true) && !is_dir($DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

/* ============================================================
 * セッション
 * ============================================================ */

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

    $path = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    $path = rtrim($path, '/');
    if ($path === '') {
        $path = '/';
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $path,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        http_response_code(500);
        exit('セッションを利用できません。');
    }
}

/* ============================================================
 * 共通関数
 * ============================================================ */

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
    return date('Y-m-d H:i:s');
}

function now_input(): string
{
    return date('Y-m-d\TH:i');
}

function get_string(string $key): string
{
    return isset($_GET[$key]) && is_scalar($_GET[$key])
        ? trim((string)$_GET[$key])
        : '';
}

function post_string(string $key): string
{
    return isset($_POST[$key]) && is_scalar($_POST[$key])
        ? trim((string)$_POST[$key])
        : '';
}

function post_array(string $key): array
{
    $value = $_POST[$key] ?? [];

    if (!is_array($value)) {
        return [];
    }

    $result = [];

    foreach ($value as $v) {
        if (is_scalar($v)) {
            $result[] = trim((string)$v);
        }
    }

    return array_values(array_unique($result));
}

function app_url(array $params = []): string
{
    $base = 'index.php';

    if (!$params) {
        return $base;
    }

    return $base . '?' . http_build_query($params);
}

function safe_id(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id);
}

function new_id(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
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
    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($value) ? $value : null;
}

function data_file(string $name): string
{
    global $DATA_DIR;
    return $DATA_DIR . DIRECTORY_SEPARATOR . $name;
}

/* ============================================================
 * JSON永続化
 * ============================================================ */

function json_read(string $file, mixed $default): mixed
{
    if (!is_file($file)) {
        return $default;
    }

    $raw = @file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);

    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        return $default;
    }

    return $decoded;
}

function json_write(string $file, mixed $data): bool
{
    $dir = dirname($file);

    if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
        return false;
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/* ============================================================
 * データ
 * ============================================================ */

function load_surveys(): array
{
    $data = json_read(data_file('surveys.json'), []);
    return is_array($data) ? $data : [];
}

function save_surveys(array $surveys): bool
{
    return json_write(
        data_file('surveys.json'),
        array_values($surveys)
    );
}

function load_customers(): array
{
    $data = json_read(data_file('customers.json'), []);
    return is_array($data) ? $data : [];
}

function save_customers(array $customers): bool
{
    return json_write(
        data_file('customers.json'),
        array_values($customers)
    );
}

function load_answers(): array
{
    $data = json_read(data_file('answers.json'), []);
    return is_array($data) ? $data : [];
}

function save_answers(array $answers): bool
{
    return json_write(
        data_file('answers.json'),
        array_values($answers)
    );
}

function load_history(): array
{
    $data = json_read(data_file('send_history.json'), []);
    return is_array($data) ? $data : [];
}

function save_history(array $history): bool
{
    return json_write(
        data_file('send_history.json'),
        array_values($history)
    );
}

function load_kintone(): array
{
    $data = json_read(data_file('kintone.json'), []);

    if (!is_array($data)) {
        $data = [];
    }

    return array_merge([
        'subdomain' => '',
        'app_id' => '',
        'username' => '',
        'password_encrypted' => '',
        'proxy' => '',
        'verify_ssl' => false,
        'fields' => [],
        'mapping' => [
            'organization' => '',
            'name' => '',
            'email' => '',
            'department' => '',
            'phone' => '',
            'address' => [],
        ],
        'status' => '未設定',
        'last_test' => '',
        'last_sync' => '',
    ], $data);
}

function save_kintone(array $config): bool
{
    return json_write(data_file('kintone.json'), $config);
}

function load_mail(): array
{
    $data = json_read(data_file('mail.json'), []);

    if (!is_array($data)) {
        $data = [];
    }

    return array_merge([
        'server' => '',
        'port' => 587,
        'encryption' => 'tls',
        'auth' => true,
        'username' => '',
        'password_encrypted' => '',
        'from_email' => '',
        'from_name' => '',
        'reply_to' => '',
        'status' => '未設定',
        'last_test' => '',
    ], $data);
}

function save_mail(array $config): bool
{
    return json_write(data_file('mail.json'), $config);
}

/* ============================================================
 * 秘密情報の保存
 *
 * 平文保存を避ける。
 * APP_ENCRYPTION_KEY環境変数があればそれを使用。
 * なければdata/.keyをサーバー側に生成する。
 * ============================================================ */

function encryption_key(): string
{
    global $DATA_DIR;

    $env = getenv('APP_ENCRYPTION_KEY');

    if (is_string($env) && strlen($env) >= 32) {
        return hash('sha256', $env, true);
    }

    $file = $DATA_DIR . DIRECTORY_SEPARATOR . '.key';

    if (is_file($file)) {
        $key = @file_get_contents($file);

        if (is_string($key) && strlen($key) >= 32) {
            return hash('sha256', $key, true);
        }
    }

    $key = bin2hex(random_bytes(32));

    if (@file_put_contents($file, $key, LOCK_EX) === false) {
        throw new RuntimeException(
            '暗号化キーを保存できません。'
        );
    }

    @chmod($file, 0600);

    return hash('sha256', $key, true);
}

function encrypt_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    $iv = random_bytes(16);
    $key = encryption_key();

    $cipher = openssl_encrypt(
        $plain,
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($cipher === false) {
        throw new RuntimeException(
            '秘密情報を暗号化できません。'
        );
    }

    $mac = hash_hmac(
        'sha256',
        $iv . $cipher,
        $key,
        true
    );

    return base64_encode($iv . $mac . $cipher);
}

function decrypt_secret(string $encoded): string
{
    if ($encoded === '') {
        return '';
    }

    $raw = base64_decode($encoded, true);

    if ($raw === false || strlen($raw) < 48) {
        return '';
    }

    $iv = substr($raw, 0, 16);
    $mac = substr($raw, 16, 32);
    $cipher = substr($raw, 48);

    $key = encryption_key();

    $expected = hash_hmac(
        'sha256',
        $iv . $cipher,
        $key,
        true
    );

    if (!hash_equals($mac, $expected)) {
        return '';
    }

    $plain = openssl_decrypt(
        $cipher,
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    return is_string($plain) ? $plain : '';
}

/* ============================================================
 * アンケート
 * ============================================================ */

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
        'published' => 'success',
        'stopped' => 'warning',
        'ended' => 'gray',
        default => 'draft',
    };
}

function refresh_survey_status(array &$survey): bool
{
    if (
        ($survey['status'] ?? 'draft') === 'published'
        && !empty($survey['endAt'])
    ) {
        $end = strtotime((string)$survey['endAt']);

        if ($end !== false && $end < time()) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = now_iso();
            return true;
        }
    }

    return false;
}

function refresh_all_statuses(array &$surveys): void
{
    $changed = false;

    foreach ($surveys as &$survey) {
        if (refresh_survey_status($survey)) {
            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {
        save_surveys($surveys);
    }
}

function find_survey(array $surveys, string $id): ?array
{
    foreach ($surveys as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function survey_index(array $surveys, string $id): int
{
    foreach ($surveys as $index => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $index;
        }
    }

    return -1;
}

function new_question(): array
{
    return [
        'id' => new_id('q'),
        'number' => '',
        'text' => '',
        'type' => 'single',
        'required' => true,
        'options' => ['選択肢1', '選択肢2'],
        'branching' => [],
    ];
}

function new_group(): array
{
    return [
        'id' => new_id('g'),
        'title' => '新しいグループ',
        'questions' => [
            new_question(),
        ],
    ];
}

function new_survey(): array
{
    return [
        'id' => new_id('survey'),
        'title' => '新しいアンケート',
        'description' => '',
        'startAt' => now_input(),
        'endAt' => '',
        'numbering' => 'global',
        'status' => 'draft',
        'createdAt' => now_iso(),
        'updatedAt' => now_iso(),
        'groups' => [
            [
                'id' => new_id('g'),
                'title' => '基本情報',
                'questions' => [
                    new_question(),
                ],
            ],
        ],
    ];
}

function recalc_question_numbers(array &$survey): void
{
    $mode = $survey['numbering'] ?? 'global';
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            if ($mode === 'group') {
                $question['number'] =
                    'Q' . $groupNo . '-' . $questionNo;
            } else {
                $question['number'] =
                    'Q' . $global;
            }

            $questionNo++;
            $global++;
        }

        unset($question);
        $groupNo++;
    }

    unset($group);
}

/* ============================================================
 * kintone
 * ============================================================ */

function normalize_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    $value = trim((string)$value, '/');

    $value = preg_replace(
        '/\.cybozu\.com.*$/i',
        '',
        $value
    );

    return trim((string)$value);
}

function kintone_host(array $config): string
{
    return normalize_subdomain(
        (string)($config['subdomain'] ?? '')
    ) . '.cybozu.com';
}

function parse_proxy(string $proxy): ?array
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (!preg_match(
        '/^([^:\/\s]+):([0-9]{1,5})$/',
        $proxy,
        $m
    )) {
        return null;
    }

    $port = (int)$m[2];

    if ($port < 1 || $port > 65535) {
        return null;
    }

    return [
        'host' => $m[1],
        'port' => $port,
    ];
}

function validate_kintone_config(array $config): array
{
    $errors = [];

    $subdomain = normalize_subdomain(
        (string)($config['subdomain'] ?? '')
    );

    $appId = trim(
        (string)($config['app_id'] ?? '')
    );

    $username = trim(
        (string)($config['username'] ?? '')
    );

    $proxy = trim(
        (string)($config['proxy'] ?? '')
    );

    if (
        $subdomain === ''
        || !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]{0,62}$/',
            $subdomain
        )
    ) {
        $errors[] =
            'サブドメインを正しく入力してください。';
    }

    if (
        $appId === ''
        || !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        $errors[] =
            '顧客管理アプリIDを正しく入力してください。';
    }

    if ($username === '') {
        $errors[] =
            'ログイン名を入力してください。';
    }

    if ($proxy !== '' && parse_proxy($proxy) === null) {
        $errors[] =
            'Proxyは「host:port」形式で入力してください。';
    }

    return [
        'subdomain' => $subdomain,
        'app_id' => $appId,
        'username' => $username,
        'proxy' => $proxy,
        'errors' => $errors,
    ];
}

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $validation = validate_kintone_config($config);

    if ($validation['errors']) {
        return [
            'ok' => false,
            'category' => '入力エラー',
            'status' => 0,
            'code' => '',
            'id' => '',
            'message' => implode(
                ' ',
                $validation['errors']
            ),
            'data' => null,
        ];
    }

    $password = '';

    if (!empty($config['password_encrypted'])) {
        $password = decrypt_secret(
            (string)$config['password_encrypted']
        );
    }

    if ($password === '' && !empty($config['password'])) {
        $password = (string)$config['password'];
    }

    if ($password === '') {
        return [
            'ok' => false,
            'category' => '設定エラー',
            'status' => 0,
            'code' => '',
            'id' => '',
            'message' =>
                'kintoneパスワードが設定されていません。',
            'data' => null,
        ];
    }

    $host = kintone_host($config);

    if (
        !preg_match(
            '/^[A-Za-z0-9-]+\.cybozu\.com$/',
            $host
        )
    ) {
        return [
            'ok' => false,
            'category' => '設定エラー',
            'status' => 0,
            'code' => '',
            'id' => '',
            'message' =>
                'kintoneサブドメインが不正です。',
            'data' => null,
        ];
    }

    /*
     * kintone REST APIのURLをここで一元生成。
     * 認証情報はURLへ含めない。
     */
    $url = 'https://' . $host . $path;

    $headers = [
        'X-Cybozu-Authorization: ' .
            base64_encode(
                (string)$config['username']
                . ':'
                . $password
            ),
        'Accept: application/json',
        'User-Agent: SurveyApp/1.0',
        'Connection: close',
    ];

    $content = null;

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            return [
                'ok' => false,
                'category' => 'データエラー',
                'status' => 0,
                'code' => '',
                'id' => '',
                'message' =>
                    'JSONリクエストを生成できません。',
                'data' => null,
            ];
        }

        $headers[] = 'Content-Type: application/json';
        $headers[] =
            'Content-Length: ' . strlen($content);
    }

    $proxy = parse_proxy(
        (string)($config['proxy'] ?? '')
    );

    $http = [
        'method' => strtoupper($method),
        'header' => implode("\r\n", $headers),
        'timeout' => 20,
        'ignore_errors' => true,
        'protocol_version' => 1.1,
        'follow_location' => 0,
        'max_redirects' => 0,
    ];

    if ($content !== null) {
        $http['content'] = $content;
    }

    if ($proxy !== null) {
        $http['proxy'] =
            'tcp://'
            . $proxy['host']
            . ':'
            . $proxy['port'];

        $http['request_fulluri'] = true;
    }

    $verifySsl = !empty($config['verify_ssl']);

    $ssl = [
        'verify_peer' => $verifySsl,
        'verify_peer_name' => $verifySsl,
        'allow_self_signed' => !$verifySsl,
        'SNI_enabled' => true,
    ];

    $context = stream_context_create([
        'http' => $http,
        'ssl' => $ssl,
    ]);

    $error = '';

    set_error_handler(
        static function (
            int $severity,
            string $message
        ) use (&$error): bool {
            $error = $message;
            return true;
        }
    );

    $response = file_get_contents(
        $url,
        false,
        $context
    );

    restore_error_handler();

    $status = 0;

    foreach ($http_response_header ?? [] as $header) {
        if (preg_match(
            '/^HTTP\/[0-9.]+\s+([0-9]{3})/',
            $header,
            $m
        )) {
            $status = (int)$m[1];
        }
    }

    if ($response === false) {
        return [
            'ok' => false,
            'category' => '通信エラー',
            'status' => $status,
            'code' => '',
            'id' => '',
            'message' =>
                $error !== ''
                    ? $error
                    : 'kintoneへ接続できませんでした。',
            'data' => null,
        ];
    }

    $decoded = json_decode(
        $response,
        true
    );

    if ($status >= 200 && $status < 300) {
        return [
            'ok' => true,
            'category' => '成功',
            'status' => $status,
            'code' => '',
            'id' => '',
            'message' => 'kintone接続に成功しました。',
            'data' =>
                is_array($decoded)
                    ? $decoded
                    : $response,
        ];
    }

    $code = '';
    $id = '';
    $message = 'kintone APIでエラーが発生しました。';

    if (is_array($decoded)) {
        $code = (string)(
            $decoded['code'] ?? ''
        );

        $id = (string)(
            $decoded['id'] ?? ''
        );

        if (!empty($decoded['message'])) {
            $message =
                (string)$decoded['message'];
        }
    }

    $category = match (true) {
        $status === 400 => '入力・APIリクエストエラー',
        $status === 401 || $status === 403
            => '認証エラー',
        $status === 404
            => '設定エラー',
        $status === 408
            => 'タイムアウト',
        $status === 429
            => '外部サービスエラー',
        $status >= 500
            => '外部サービスエラー',
        $status >= 300 && $status < 400
            => 'リダイレクトエラー',
        default
            => '通信エラー',
    };

    return [
        'ok' => false,
        'category' => $category,
        'status' => $status,
        'code' => $code,
        'id' => $id,
        'message' => $message,
        'data' => is_array($decoded)
            ? $decoded
            : null,
    ];
}

function kintone_connection_test(
    array $config
): array {
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?app='
        . rawurlencode(
            (string)$config['app_id']
        )
    );
}

function kintone_fetch_fields(
    array $config
): array {
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app='
        . rawurlencode(
            (string)$config['app_id']
        )
    );
}

function kintone_sync(
    array $config
): array {
    $result = kintone_request(
        $config,
        'GET',
        '/k/v1/records.json?'
        . http_build_query([
            'app' => (int)$config['app_id'],
            'totalCount' => 'true',
        ])
    );

    if (!$result['ok']) {
        return $result;
    }

    $records =
        $result['data']['records'] ?? [];

    if (!is_array($records)) {
        $records = [];
    }

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $get = static function (
            array $record,
            string $field
        ): string {
            if (
                !isset($record[$field])
                || !is_array($record[$field])
            ) {
                return '';
            }

            return (string)(
                $record[$field]['value'] ?? ''
            );
        };

        $customers[] = [
            'id' =>
                'customer-' . count($customers) + 1,
            'organization' =>
                $get($record, '組織名'),
            'name' =>
                $get($record, '氏名'),
            'email' =>
                $get($record, 'メールアドレス'),
            'department' =>
                $get($record, '部署名'),
            'phone' =>
                $get($record, '電話番号'),
            'address' =>
                $get($record, '住所'),
            'updatedAt' => now_iso(),
        ];
    }

    return [
        'ok' => true,
        'category' => '成功',
        'status' => $result['status'],
        'code' => '',
        'id' => '',
        'message' =>
            count($customers)
            . '件の顧客情報を取得しました。',
        'customers' => $customers,
    ];
}

/* ============================================================
 * SMTP
 * ============================================================ */

function validate_mail_config(array $config): array
{
    $errors = [];

    if (trim((string)$config['server']) === '') {
        $errors[] = 'SMTPサーバを入力してください。';
    }

    $port = (int)$config['port'];

    if ($port < 1 || $port > 65535) {
        $errors[] = 'SMTPポートが不正です。';
    }

    if (
        !empty($config['from_email'])
        && !filter_var(
            $config['from_email'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            '送信元メールアドレスが不正です。';
    }

    if (
        !empty($config['reply_to'])
        && !filter_var(
            $config['reply_to'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            '返信先メールアドレスが不正です。';
    }

    return $errors;
}

function smtp_open(array $config): array
{
    $errors = validate_mail_config($config);

    if ($errors) {
        return [
            'ok' => false,
            'message' => implode(' ', $errors),
        ];
    }

    $password = '';

    if (!empty($config['password_encrypted'])) {
        $password = decrypt_secret(
            (string)$config['password_encrypted']
        );
    }

    $server =
        (string)$config['server'];

    $port =
        (int)$config['port'];

    $encryption =
        (string)$config['encryption'];

    $transport = 'tcp://';

    if ($encryption === 'ssl') {
        $transport = 'ssl://';
    }

    $errorNo = 0;
    $errorStr = '';

    $socket = @stream_socket_client(
        $transport
        . $server
        . ':'
        . $port,
        $errorNo,
        $errorStr,
        15
    );

    if (!is_resource($socket)) {
        return [
            'ok' => false,
            'message' =>
                'SMTPへ接続できませんでした。'
                . ($errorStr !== ''
                    ? ' ' . $errorStr
                    : ''),
        ];
    }

    stream_set_timeout($socket, 15);

    $read = static function ($socket): string {
        $result = '';

        while (!feof($socket)) {
            $line = fgets($socket, 4096);

            if ($line === false) {
                break;
            }

            $result .= $line;

            if (
                isset($line[3])
                && $line[3] === ' '
            ) {
                break;
            }
        }

        return $result;
    };

    $write = static function (
        $socket,
        string $command
    ): string {
        fwrite(
            $socket,
            $command . "\r\n"
        );

        $result = '';

        while (!feof($socket)) {
            $line = fgets($socket, 4096);

            if ($line === false) {
                break;
            }

            $result .= $line;

            if (
                isset($line[3])
                && $line[3] === ' '
            ) {
                break;
            }
        }

        return $result;
    };

    $greeting = $read($socket);

    if (
        !preg_match(
            '/^2[0-9]{2}/m',
            $greeting
        )
    ) {
        fclose($socket);

        return [
            'ok' => false,
            'message' =>
                'SMTPサーバから正常な応答がありません。',
        ];
    }

    $ehlo = $write(
        $socket,
        'EHLO localhost'
    );

    if (
        !preg_match(
            '/^2[0-9]{2}/m',
            $ehlo
        )
    ) {
        fclose($socket);

        return [
            'ok' => false,
            'message' =>
                'SMTP EHLOに失敗しました。',
        ];
    }

    if ($encryption === 'tls') {
        $startTls = $write(
            $socket,
            'STARTTLS'
        );

        if (
            !preg_match(
                '/^220/m',
                $startTls
            )
        ) {
            fclose($socket);

            return [
                'ok' => false,
                'message' =>
                    'SMTP STARTTLSに失敗しました。',
            ];
        }

        $crypto = @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);

            return [
                'ok' => false,
                'message' =>
                    'TLS暗号化を開始できませんでした。',
            ];
        }

        $ehlo = $write(
            $socket,
            'EHLO localhost'
        );
    }

    if (!empty($config['auth'])) {
        if ($password === '') {
            fclose($socket);

            return [
                'ok' => false,
                'message' =>
                    'SMTPパスワードが設定されていません。',
            ];
        }

        $auth = $write(
            $socket,
            'AUTH LOGIN'
        );

        if (!preg_match('/^334/m', $auth)) {
            fclose($socket);

            return [
                'ok' => false,
                'message' =>
                    'SMTP認証を開始できません。',
            ];
        }

        $userResponse = $write(
            $socket,
            base64_encode(
                (string)$config['username']
            )
        );

        if (!preg_match('/^334/m', $userResponse)) {
            fclose($socket);

            return [
                'ok' => false,
                'message' =>
                    'SMTPユーザー名を受け付けませんでした。',
            ];
        }

        $passwordResponse = $write(
            $socket,
            base64_encode($password)
        );

        if (!preg_match('/^235/m', $passwordResponse)) {
            fclose($socket);

            return [
                'ok' => false,
                'message' =>
                    'SMTP認証に失敗しました。',
            ];
        }
    }

    return [
        'ok' => true,
        'socket' => $socket,
        'read' => $read,
        'write' => $write,
    ];
}

function smtp_test(array $config): array
{
    $result = smtp_open($config);

    if (!$result['ok']) {
        return $result;
    }

    $socket = $result['socket'];

    fwrite(
        $socket,
        "QUIT\r\n"
    );

    fclose($socket);

    return [
        'ok' => true,
        'message' =>
            'SMTP接続・認証に成功しました。',
    ];
}

/* ============================================================
 * POST処理
 *
 * 重要:
 * 設定画面のPOST後に302/303を返して処理結果を失わない。
 * 同一リクエストで画面を再描画する。
 * ============================================================ */

function handle_post(
    array &$surveys,
    array &$customers,
    array &$answers,
    array &$history,
    array &$kintone,
    array &$mail
): array {
    $action = post_string('action');

    if ($action === '') {
        return [
            'screen' => 'list',
        ];
    }

    try {
        switch ($action) {

            /* ------------------------------------------------
             * kintone設定保存
             * ------------------------------------------------ */

            case 'save_kintone':
                $subdomain =
                    normalize_subdomain(
                        post_string('subdomain')
                    );

                $appId =
                    post_string('app_id');

                $username =
                    post_string('username');

                $password =
                    post_string('password');

                $proxy =
                    post_string('proxy');

                $verifySsl =
                    isset($_POST['verify_ssl']);

                $candidate = [
                    'subdomain' => $subdomain,
                    'app_id' => $appId,
                    'username' => $username,
                    'proxy' => $proxy,
                    'verify_ssl' => $verifySsl,
                ];

                $validation =
                    validate_kintone_config(
                        $candidate
                    );

                if ($validation['errors']) {
                    throw new InvalidArgumentException(
                        implode(
                            ' ',
                            $validation['errors']
                        )
                    );
                }

                /*
                 * パスワード未入力の場合は既存値を維持。
                 */
                $encrypted =
                    (string)(
                        $kintone[
                            'password_encrypted'
                        ] ?? ''
                    );

                if ($password !== '') {
                    $encrypted =
                        encrypt_secret(
                            $password
                        );
                }

                $kintone = array_merge(
                    $kintone,
                    [
                        'subdomain' => $subdomain,
                        'app_id' => $appId,
                        'username' => $username,
                        'password_encrypted' =>
                            $encrypted,
                        'proxy' => $proxy,
                        'verify_ssl' => $verifySsl,
                    ]
                );

                if (!save_kintone($kintone)) {
                    throw new RuntimeException(
                        'kintone設定を保存できません。'
                    );
                }

                flash(
                    'success',
                    'kintone設定を保存しました。'
                );

                return [
                    'screen' => 'kintone',
                ];

            /* ------------------------------------------------
             * kintone接続テスト
             * ------------------------------------------------ */

            case 'test_kintone':
                $candidate = $kintone;

                $candidate['subdomain'] =
                    normalize_subdomain(
                        post_string('subdomain')
                    );

                $candidate['app_id'] =
                    post_string('app_id');

                $candidate['username'] =
                    post_string('username');

                $candidate['proxy'] =
                    post_string('proxy');

                $candidate['verify_ssl'] =
                    isset($_POST['verify_ssl']);

                $newPassword =
                    post_string('password');

                if ($newPassword !== '') {
                    $candidate[
                        'password_encrypted'
                    ] = encrypt_secret(
                        $newPassword
                    );
                }

                $validation =
                    validate_kintone_config(
                        $candidate
                    );

                if ($validation['errors']) {
                    $_SESSION[
                        'kintone_test_result'
                    ] = [
                        'ok' => false,
                        'category' => '入力エラー',
                        'status' => 0,
                        'code' => '',
                        'id' => '',
                        'message' =>
                            implode(
                                ' ',
                                $validation['errors']
                            ),
                    ];

                    return [
                        'screen' => 'kintone',
                    ];
                }

                $result =
                    kintone_connection_test(
                        $candidate
                    );

                $_SESSION[
                    'kintone_test_result'
                ] = $result;

                if ($result['ok']) {
                    $kintone['status'] =
                        '接続確認済み';

                    $kintone['last_test'] =
                        now_iso();

                    save_kintone($kintone);
                } else {
                    $kintone['status'] =
                        '接続できません';

                    save_kintone($kintone);
                }

                return [
                    'screen' => 'kintone',
                ];

            /* ------------------------------------------------
             * kintone項目取得
             * ------------------------------------------------ */

            case 'fetch_kintone_fields':
                $result =
                    kintone_fetch_fields(
                        $kintone
                    );

                if (!$result['ok']) {
                    $_SESSION[
                        'kintone_fields_result'
                    ] = $result;

                    return [
                        'screen' => 'kintone',
                    ];
                }

                $fields = [];

                foreach (
                    ($result['data']['properties'] ?? [])
                    as $code => $field
                ) {
                    if (!is_array($field)) {
                        continue;
                    }

                    $label =
                        (string)(
                            $field['label']
                            ?? $code
                        );

                    $fields[] = [
                        'code' => (string)$code,
                        'label' => $label,
                        'type' =>
                            (string)(
                                $field['type'] ?? ''
                            ),
                    ];
                }

                usort(
                    $fields,
                    static fn(
                        array $a,
                        array $b
                    ): int =>
                        strcmp(
                            $a['label'],
                            $b['label']
                        )
                );

                $kintone['fields'] =
                    $fields;

                save_kintone($kintone);

                $_SESSION[
                    'kintone_fields_result'
                ] = [
                    'ok' => true,
                    'category' => '成功',
                    'status' =>
                        $result['status'],
                    'message' =>
                        count($fields)
                        . '件の項目を取得しました。',
                ];

                return [
                    'screen' => 'kintone',
                ];

            /* ------------------------------------------------
             * kintoneマッピング保存
             * ------------------------------------------------ */

            case 'save_kintone_mapping':
                $kintone['mapping'] = [
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
                    'address' =>
                        post_array(
                            'mapping_address'
                        ),
                ];

                if (!save_kintone($kintone)) {
                    throw new RuntimeException(
                        'マッピングを保存できません。'
                    );
                }

                flash(
                    'success',
                    '項目マッピングを保存しました。'
                );

                return [
                    'screen' => 'kintone',
                ];

            /* ------------------------------------------------
             * kintone顧客同期
             * ------------------------------------------------ */

            case 'sync_kintone':
                $result =
                    kintone_sync(
                        $kintone
                    );

                if (!$result['ok']) {
                    $_SESSION[
                        'kintone_sync_result'
                    ] = $result;

                    return [
                        'screen' => 'kintone',
                    ];
                }

                $customers =
                    $result['customers'];

                $kintone['last_sync'] =
                    now_iso();

                save_kintone($kintone);
                save_customers($customers);

                $_SESSION[
                    'kintone_sync_result'
                ] = $result;

                return [
                    'screen' => 'kintone',
                ];

            /* ------------------------------------------------
             * メール設定保存
             * ------------------------------------------------ */

            case 'save_mail':
                $candidate = [
                    'server' =>
                        post_string('server'),
                    'port' =>
                        (int)post_string('port'),
                    'encryption' =>
                        post_string('encryption'),
                    'auth' =>
                        isset($_POST['auth']),
                    'username' =>
                        post_string('username'),
                    'from_email' =>
                        post_string('from_email'),
                    'from_name' =>
                        post_string('from_name'),
                    'reply_to' =>
                        post_string('reply_to'),
                ];

                $errors =
                    validate_mail_config(
                        $candidate
                    );

                if ($errors) {
                    throw new InvalidArgumentException(
                        implode(' ', $errors)
                    );
                }

                $password =
                    post_string('password');

                $encrypted =
                    (string)(
                        $mail['password_encrypted']
                        ?? ''
                    );

                if ($password !== '') {
                    $encrypted =
                        encrypt_secret(
                            $password
                        );
                }

                $mail = array_merge(
                    $mail,
                    $candidate,
                    [
                        'password_encrypted' =>
                            $encrypted,
                    ]
                );

                if (!save_mail($mail)) {
                    throw new RuntimeException(
                        'メール設定を保存できません。'
                    );
                }

                flash(
                    'success',
                    'メール設定を保存しました。'
                );

                return [
                    'screen' => 'mail',
                ];

            /* ------------------------------------------------
             * SMTP接続テスト
             * ------------------------------------------------ */

            case 'test_mail':
                $candidate = $mail;

                foreach (
                    [
                        'server',
                        'encryption',
                        'username',
                        'from_email',
                        'from_name',
                        'reply_to',
                    ] as $key
                ) {
                    $candidate[$key] =
                        post_string($key);
                }

                $candidate['port'] =
                    (int)post_string('port');

                $candidate['auth'] =
                    isset($_POST['auth']);

                $password =
                    post_string('password');

                if ($password !== '') {
                    $candidate[
                        'password_encrypted'
                    ] = encrypt_secret(
                        $password
                    );
                }

                $result =
                    smtp_test($candidate);

                $_SESSION[
                    'mail_test_result'
                ] = $result;

                if ($result['ok']) {
                    $mail['status'] =
                        '接続確認済み';

                    $mail['last_test'] =
                        now_iso();

                    save_mail($mail);
                } else {
                    $mail['status'] =
                        '接続できません';

                    save_mail($mail);
                }

                return [
                    'screen' => 'mail',
                ];

            /* ------------------------------------------------
             * アンケート保存
             * ------------------------------------------------ */

            case 'save_survey':
                $id =
                    post_string('survey_id');

                $title =
                    post_string('title');

                if ($title === '') {
                    throw new InvalidArgumentException(
                        'アンケートタイトルを入力してください。'
                    );
                }

                $survey =
                    $id !== ''
                        ? find_survey(
                            $surveys,
                            $id
                        )
                        : null;

                if ($survey === null) {
                    $survey =
                        new_survey();

                    if ($id !== '') {
                        $survey['id'] =
                            $id;
                    }
                }

                $survey['title'] =
                    $title;

                $survey['description'] =
                    post_string('description');

                $survey['startAt'] =
                    post_string('startAt');

                $survey['endAt'] =
                    post_string('endAt');

                $survey['numbering'] =
                    post_string('numbering')
                    ?: 'global';

                if (
                    !in_array(
                        $survey['numbering'],
                        ['global', 'group'],
                        true
                    )
                ) {
                    $survey['numbering'] =
                        'global';
                }

                $survey['updatedAt'] =
                    now_iso();

                recalc_question_numbers(
                    $survey
                );

                $index =
                    survey_index(
                        $surveys,
                        $survey['id']
                    );

                if ($index >= 0) {
                    /*
                     * 既存編集では状態を維持。
                     */
                    $survey['status'] =
                        $surveys[$index]['status']
                        ?? 'draft';

                    $surveys[$index] =
                        $survey;
                } else {
                    $survey['status'] =
                        'draft';

                    $survey['createdAt'] =
                        now_iso();

                    $surveys[] =
                        $survey;
                }

                if (!save_surveys($surveys)) {
                    throw new RuntimeException(
                        'アンケートを保存できません。'
                    );
                }

                flash(
                    'success',
                    'アンケートを保存しました。'
                );

                /*
                 * PRGではなく同一POSTで一覧を表示。
                 * 環境側の303/302に依存しない。
                 */
                return [
                    'screen' => 'list',
                ];

            /* ------------------------------------------------
             * 削除
             * ------------------------------------------------ */

            case 'delete_survey':
                $id =
                    post_string('survey_id');

                $index =
                    survey_index(
                        $surveys,
                        $id
                    );

                if ($index < 0) {
                    throw new InvalidArgumentException(
                        '削除対象が見つかりません。'
                    );
                }

                array_splice(
                    $surveys,
                    $index,
                    1
                );

                save_surveys($surveys);

                flash(
                    'success',
                    'アンケートを削除しました。'
                );

                return [
                    'screen' => 'list',
                ];

            /* ------------------------------------------------
             * 複製
             * ------------------------------------------------ */

            case 'duplicate_survey':
                $id =
                    post_string('survey_id');

                $survey =
                    find_survey(
                        $surveys,
                        $id
                    );

                if ($survey === null) {
                    throw new InvalidArgumentException(
                        '複製対象が見つかりません。'
                    );
                }

                $copy =
                    $survey;

                $copy['id'] =
                    new_id('survey');

                $copy['title'] =
                    (string)$copy['title']
                    . '（コピー）';

                $copy['status'] =
                    'draft';

                $copy['createdAt'] =
                    now_iso();

                $copy['updatedAt'] =
                    now_iso();

                $surveys[] =
                    $copy;

                save_surveys($surveys);

                flash(
                    'success',
                    'アンケートを複製しました。'
                );

                return [
                    'screen' => 'list',
                ];

            /* ------------------------------------------------
             * 状態変更
             * ------------------------------------------------ */

            case 'change_status':
                $id =
                    post_string('survey_id');

                $next =
                    post_string('next_status');

                $index =
                    survey_index(
                        $surveys,
                        $id
                    );

                if ($index < 0) {
                    throw new InvalidArgumentException(
                        '対象アンケートが見つかりません。'
                    );
                }

                $current =
                    $surveys[$index]['status']
                    ?? 'draft';

                $allowed = [
                    'draft' =>
                        ['published'],
                    'published' =>
                        ['stopped'],
                    'stopped' =>
                        ['published'],
                    'ended' =>
                        [],
                ];

                if (
                    !in_array(
                        $next,
                        $allowed[$current]
                        ?? [],
                        true
                    )
                ) {
                    throw new InvalidArgumentException(
                        '指定された状態変更はできません。'
                    );
                }

                $surveys[$index]['status'] =
                    $next;

                $surveys[$index]['updatedAt'] =
                    now_iso();

                save_surveys($surveys);

                flash(
                    'success',
                    '状態を変更しました。'
                );

                return [
                    'screen' => 'list',
                ];

            /* ------------------------------------------------
             * 回答
             * ------------------------------------------------ */

            case 'answer':
                $id =
                    post_string('survey_id');

                $survey =
                    find_survey(
                        $surveys,
                        $id
                    );

                if ($survey === null) {
                    throw new InvalidArgumentException(
                        'アンケートが見つかりません。'
                    );
                }

                if (
                    ($survey['status'] ?? '')
                    !== 'published'
                ) {
                    throw new InvalidArgumentException(
                        'このアンケートは現在回答できません。'
                    );
                }

                $answersDraft = [];

                foreach (
                    $survey['groups'] ?? []
                    as $group
                ) {
                    foreach (
                        $group['questions'] ?? []
                        as $question
                    ) {
                        $qid =
                            (string)$question['id'];

                        $value =
                            $_POST[
                                'answer_' . $qid
                            ] ?? '';

                        if (
                            ($question['type'] ?? '')
                            === 'multiple'
                        ) {
                            $value =
                                is_array($value)
                                    ? array_values(
                                        array_filter(
                                            array_map(
                                                'strval',
                                                $value
                                            )
                                        )
                                    )
                                    : [];
                        } else {
                            $value =
                                is_scalar($value)
                                    ? trim(
                                        (string)$value
                                    )
                                    : '';
                        }

                        if (
                            !empty(
                                $question['required']
                            )
                            && (
                                $value === ''
                                || $value === []
                            )
                        ) {
                            throw new InvalidArgumentException(
                                '必須項目が未回答です。'
                            );
                        }

                        $answersDraft[$qid] =
                            $value;
                    }
                }

                $_SESSION[
                    'answer_draft'
                ] = [
                    'survey_id' => $id,
                    'answers' =>
                        $answersDraft,
                ];

                return [
                    'screen' => 'confirm',
                    'id' => $id,
                ];

            /* ------------------------------------------------
             * 回答確定
             * ------------------------------------------------ */

            case 'finalize_answer':
                $draft =
                    $_SESSION[
                        'answer_draft'
                    ] ?? null;

                if (!is_array($draft)) {
                    throw new RuntimeException(
                        '回答状態が見つかりません。'
                    );
                }

                $surveyId =
                    (string)(
                        $draft['survey_id']
                        ?? ''
                    );

                if (
                    $surveyId === ''
                    || !safe_id($surveyId)
                ) {
                    throw new RuntimeException(
                        '回答対象が不正です。'
                    );
                }

                $answers[] = [
                    'id' =>
                        new_id('answer'),
                    'survey_id' =>
                        $surveyId,
                    'answers' =>
                        $draft['answers']
                        ?? [],
                    'createdAt' =>
                        now_iso(),
                ];

                save_answers($answers);

                unset(
                    $_SESSION[
                        'answer_draft'
                    ]
                );

                return [
                    'screen' => 'complete',
                ];

            /* ------------------------------------------------
             * メール送信
             * ------------------------------------------------ */

            case 'send_mail':
                $surveyId =
                    post_string('survey_id');

                $survey =
                    find_survey(
                        $surveys,
                        $surveyId
                    );

                if ($survey === null) {
                    throw new InvalidArgumentException(
                        '対象アンケートが見つかりません。'
                    );
                }

                $selected =
                    post_array(
                        'customer_ids'
                    );

                if (!$selected) {
                    throw new InvalidArgumentException(
                        '送信対象を選択してください。'
                    );
                }

                $subject =
                    post_string('subject');

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

                /*
                 * POCでも実際のSMTP接続を使用する。
                 * ここではSMTP設定を確認してから送信。
                 */
                $smtp =
                    smtp_open($mail);

                if (!$smtp['ok']) {
                    throw new RuntimeException(
                        $smtp['message']
                    );
                }

                $socket =
                    $smtp['socket'];

                $read =
                    $smtp['read'];

                $write =
                    $smtp['write'];

                $sent = 0;

                foreach ($customers as $customer) {
                    if (
                        !in_array(
                            (string)$customer['id'],
                            $selected,
                            true
                        )
                    ) {
                        continue;
                    }

                    $email =
                        trim(
                            (string)(
                                $customer['email']
                                ?? ''
                            )
                        );

                    if (
                        !filter_var(
                            $email,
                            FILTER_VALIDATE_EMAIL
                        )
                    ) {
                        continue;
                    }

                    $name =
                        (string)(
                            $customer['name']
                            ?? ''
                        );

                    $url =
                        app_url([
                            'screen' =>
                                'answer',
                            'id' =>
                                $surveyId,
                        ]);

                    $personalBody =
                        str_replace(
                            [
                                '{顧客名}',
                                '{アンケートURL}',
                            ],
                            [
                                $name,
                                $url,
                            ],
                            $body
                        );

                    $write(
                        $socket,
                        'MAIL FROM:<'
                        . $mail['from_email']
                        . '>'
                    );

                    $write(
                        $socket,
                        'RCPT TO:<'
                        . $email
                        . '>'
                    );

                    $dataResult =
                        $write(
                            $socket,
                            'DATA'
                        );

                    if (
                        !preg_match(
                            '/^354/m',
                            $dataResult
                        )
                    ) {
                        continue;
                    }

                    $headers = [];

                    $headers[] =
                        'From: '
                        . $mail['from_name']
                        . ' <'
                        . $mail['from_email']
                        . '>';

                    $headers[] =
                        'To: <'
                        . $email
                        . '>';

                    $headers[] =
                        'Subject: =?UTF-8?B?'
                        . base64_encode(
                            $subject
                        )
                        . '?=';

                    if (
                        !empty(
                            $mail['reply_to']
                        )
                    ) {
                        $headers[] =
                            'Reply-To: '
                            . $mail['reply_to'];
                    }

                    $headers[] =
                        'MIME-Version: 1.0';

                    $headers[] =
                        'Content-Type: text/plain; charset=UTF-8';

                    $message =
                        implode(
                            "\r\n",
                            $headers
                        )
                        . "\r\n\r\n"
                        . $personalBody
                        . "\r\n.";

                    $response =
                        $write(
                            $socket,
                            $message
                        );

                    if (
                        preg_match(
                            '/^250/m',
                            $response
                        )
                    ) {
                        $sent++;

                        $history[] = [
                            'id' =>
                                new_id('send'),
                            'survey_id' =>
                                $surveyId,
                            'customer_id' =>
                                $customer['id'],
                            'customer_name' =>
                                $name,
                            'email' =>
                                $email,
                            'type' =>
                                'send',
                            'status' =>
                                'sent',
                            'createdAt' =>
                                now_iso(),
                        ];
                    }
                }

                $write(
                    $socket,
                    'QUIT'
                );

                fclose($socket);

                save_history($history);

                $_SESSION[
                    'send_result'
                ] = [
                    'ok' => true,
                    'sent' => $sent,
                    'message' =>
                        $sent . '件送信しました。',
                ];

                return [
                    'screen' => 'send',
                    'id' => $surveyId,
                ];

            default:
                throw new InvalidArgumentException(
                    '不明な操作です。'
                );
        }
    } catch (
        InvalidArgumentException $e
    ) {
        flash(
            'error',
            $e->getMessage()
        );

        return [
            'screen' =>
                post_string(
                    'return_screen'
                ) ?: 'list',
            'id' =>
                post_string(
                    'survey_id'
                ),
        ];
    } catch (Throwable $e) {
        flash(
            'error',
            '処理に失敗しました。'
            . '入力値、設定値、通信状態を確認してください。'
        );

        return [
            'screen' =>
                post_string(
                    'return_screen'
                ) ?: 'list',
            'id' =>
                post_string(
                    'survey_id'
                ),
        ];
    }
}

/* ============================================================
 * POST実行
 * ============================================================ */

$surveys =
    load_surveys();

$customers =
    load_customers();

$answers =
    load_answers();

$history =
    load_history();

$kintone =
    load_kintone();

$mail =
    load_mail();

refresh_all_statuses(
    $surveys
);

$postResult = null;

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET')
    === 'POST'
) {
    $postResult =
        handle_post(
            $surveys,
            $customers,
            $answers,
            $history,
            $kintone,
            $mail
        );
}

$screen =
    is_array($postResult)
        ? (string)(
            $postResult['screen']
            ?? 'list'
        )
        : get_string('screen');

if ($screen === '') {
    $screen = 'list';
}

$id =
    is_array($postResult)
        ? (string)(
            $postResult['id']
            ?? get_string('id')
        )
        : get_string('id');

/* ============================================================
 * CSV
 * ============================================================ */

if ($screen === 'csv') {
    $survey =
        find_survey(
            $surveys,
            $id
        );

    if ($survey === null) {
        http_response_code(404);
        exit('アンケートが見つかりません。');
    }

    $rows = [];

    foreach ($answers as $answer) {
        if (
            ($answer['survey_id'] ?? '')
            !== $survey['id']
        ) {
            continue;
        }

        $row = [
            '回答日時' =>
                $answer['createdAt'] ?? '',
        ];

        foreach (
            $survey['groups'] ?? []
            as $group
        ) {
            foreach (
                $group['questions'] ?? []
                as $question
            ) {
                $qid =
                    (string)$question['id'];

                $value =
                    $answer['answers'][$qid]
                    ?? '';

                if (is_array($value)) {
                    $value =
                        implode(
                            '、',
                            $value
                        );
                }

                $row[
                    $question['number']
                    . ' '
                    . $question['text']
                ] = $value;
            }
        }

        $rows[] = $row;
    }

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="survey-'
        . rawurlencode($survey['id'])
        . '.csv"'
    );

    echo "\xEF\xBB\xBF";

    if ($rows) {
        $fp = fopen('php://output', 'w');

        fputcsv(
            $fp,
            array_keys($rows[0])
        );

        foreach ($rows as $row) {
            fputcsv(
                $fp,
                array_values($row)
            );
        }

        fclose($fp);
    }

    exit;
}

/* ============================================================
 * HTML共通
 * ============================================================ */

function render_head(
    string $title,
    bool $admin = true
): void {
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

*{
    box-sizing:border-box;
}

html,
body{
    margin:0;
    padding:0;
}

body{
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
    line-height:1.6;
}

a{
    color:var(--primary);
}

button,
input,
select,
textarea{
    font:inherit;
}

.container{
    width:min(1200px,calc(100% - 32px));
    margin:0 auto;
}

.admin-header{
    background:#0f172a;
    color:#fff;
}

.admin-header-inner{
    width:min(1200px,calc(100% - 32px));
    margin:0 auto;
    min-height:64px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.brand{
    font-size:18px;
    font-weight:800;
    white-space:nowrap;
}

.nav{
    display:flex;
    gap:4px;
    overflow-x:auto;
}

.nav a{
    color:#cbd5e1;
    text-decoration:none;
    padding:20px 14px;
    font-size:14px;
    white-space:nowrap;
}

.nav a.active,
.nav a:hover{
    color:#fff;
    background:rgba(255,255,255,.08);
}

.page{
    padding:28px 0 48px;
}

.page-title{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:16px;
    margin-bottom:20px;
}

.page-title h1{
    margin:0;
    font-size:25px;
    line-height:1.3;
}

.page-title p{
    margin:6px 0 0;
    color:var(--gray);
    font-size:14px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    margin-bottom:18px;
}

.card-header{
    padding:16px 20px;
    border-bottom:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
}

.card-header h2{
    margin:0;
    font-size:18px;
}

.card-body{
    padding:20px;
}

.grid{
    display:grid;
    gap:16px;
}

.grid-2{
    grid-template-columns:repeat(2,minmax(0,1fr));
}

.grid-3{
    grid-template-columns:repeat(3,minmax(0,1fr));
}

.form-group{
    margin-bottom:16px;
}

label{
    display:block;
    font-weight:600;
}

label > span{
    display:block;
    margin-bottom:6px;
}

input,
select,
textarea{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:10px 12px;
    color:var(--text);
    background:#fff;
}

textarea{
    min-height:120px;
    resize:vertical;
}

input:focus,
select:focus,
textarea:focus{
    outline:none;
    border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(37,99,235,.12);
}

.help{
    margin-top:5px;
    color:var(--gray);
    font-size:13px;
    font-weight:400;
}

.button-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
}

.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    min-height:40px;
    border:0;
    border-radius:8px;
    padding:9px 15px;
    cursor:pointer;
    text-decoration:none;
    font-weight:600;
}

.btn-primary{
    background:var(--primary);
    color:#fff;
}

.btn-primary:hover{
    background:var(--primary-dark);
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

.btn-secondary{
    background:#e2e8f0;
    color:#334155;
}

.btn-light{
    background:#f8fafc;
    color:#334155;
    border:1px solid var(--border);
}

.btn:disabled{
    opacity:.55;
    cursor:not-allowed;
}

.alert{
    padding:13px 15px;
    border-radius:9px;
    margin-bottom:16px;
}

.alert-success{
    color:#166534;
    background:#dcfce7;
    border:1px solid #bbf7d0;
}

.alert-error{
    color:#991b1b;
    background:#fee2e2;
    border:1px solid #fecaca;
}

.alert-warning{
    color:#92400e;
    background:#fef3c7;
    border:1px solid #fde68a;
}

.alert-info{
    color:#1e40af;
    background:#dbeafe;
    border:1px solid #bfdbfe;
}

.table-scroll{
    overflow-x:auto;
}

table{
    width:100%;
    min-width:760px;
    border-collapse:collapse;
}

th,
td{
    padding:11px 12px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th{
    background:#f8fafc;
    font-size:13px;
}

.badge{
    display:inline-block;
    border-radius:999px;
    padding:3px 9px;
    font-size:12px;
    font-weight:700;
}

.badge-success{
    color:#166534;
    background:#dcfce7;
}

.badge-warning{
    color:#92400e;
    background:#fef3c7;
}

.badge-danger{
    color:#991b1b;
    background:#fee2e2;
}

.badge-draft{
    color:#475569;
    background:#e2e8f0;
}

.badge-gray{
    color:#475569;
    background:#f1f5f9;
}

.toolbar{
    display:flex;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:16px;
}

.toolbar-left,
.toolbar-right{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    align-items:center;
}

.stat-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
    margin-bottom:18px;
}

.stat{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:18px;
    box-shadow:var(--shadow);
}

.stat-label{
    color:var(--gray);
    font-size:13px;
}

.stat-value{
    font-size:28px;
    font-weight:800;
    margin-top:4px;
}

.question-card{
    border:1px solid var(--border);
    border-radius:10px;
    background:#fff;
    margin-bottom:14px;
}

.question-head{
    display:flex;
    justify-content:space-between;
    gap:12px;
    padding:12px 14px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
}

.question-body{
    padding:16px;
}

.group-card{
    border:1px solid #cbd5e1;
    border-radius:12px;
    margin-bottom:18px;
    background:#fff;
}

.group-head{
    display:flex;
    justify-content:space-between;
    gap:12px;
    padding:14px 16px;
    background:#f1f5f9;
    border-bottom:1px solid var(--border);
    align-items:center;
}

.drag-handle{
    cursor:grab;
    color:var(--gray);
}

.option-row{
    display:flex;
    gap:8px;
    margin-bottom:8px;
}

.preview-question{
    padding:18px 0;
    border-bottom:1px solid var(--border);
}

.preview-question:last-child{
    border-bottom:0;
}

.required{
    color:var(--danger);
    font-size:12px;
    margin-left:5px;
}

.answer-option{
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding:10px 12px;
    border:1px solid var(--border);
    border-radius:8px;
    margin-bottom:8px;
    cursor:pointer;
}

.answer-option:hover{
    background:#f8fafc;
}

.loading-overlay{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.35);
    z-index:1000;
    align-items:center;
    justify-content:center;
}

.loading-box{
    background:#fff;
    border-radius:12px;
    padding:25px 30px;
    box-shadow:var(--shadow);
    text-align:center;
}

.spinner{
    width:28px;
    height:28px;
    border:3px solid #dbeafe;
    border-top-color:var(--primary);
    border-radius:50%;
    animation:spin .8s linear infinite;
    margin:0 auto 10px;
}

@keyframes spin{
    to{transform:rotate(360deg)}
}

.empty{
    padding:36px 20px;
    text-align:center;
    color:var(--gray);
}

.sticky-actions{
    position:sticky;
    bottom:0;
    z-index:10;
    background:rgba(248,250,252,.95);
    border-top:1px solid var(--border);
    padding:14px 0;
}

.kpi{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

.kpi-item{
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:8px;
    padding:10px 14px;
}

.notice{
    background:#eff6ff;
    border-left:4px solid var(--primary);
    padding:12px 14px;
    margin-bottom:16px;
}

pre.debug{
    white-space:pre-wrap;
    word-break:break-word;
    background:#0f172a;
    color:#e2e8f0;
    padding:12px;
    border-radius:8px;
    font-size:12px;
}

@media(max-width:900px){
    .grid-2,
    .grid-3,
    .stat-grid{
        grid-template-columns:1fr;
    }

    .admin-header-inner{
        align-items:flex-start;
        flex-direction:column;
        padding:10px 0;
    }

    .nav{
        width:100%;
    }
}

@media(max-width:600px){
    .container{
        width:min(100% - 20px,1200px);
    }

    .page{
        padding-top:18px;
    }

    .page-title{
        flex-direction:column;
    }

    .btn{
        min-height:44px;
    }

    input,
    select,
    textarea{
        font-size:16px;
    }

    .card-body{
        padding:15px;
    }

    .group-head,
    .question-head{
        flex-direction:column;
        align-items:flex-start;
    }
}
</style>
</head>
<body>
<?php if ($admin): ?>
<header class="admin-header">
<div class="admin-header-inner">
    <div class="brand"><?= h(APP_TITLE) ?></div>

    <nav class="nav">
        <a href="<?= h(app_url(['screen'=>'list'])) ?>">
            アンケート一覧
        </a>
        <a href="<?= h(app_url(['screen'=>'kintone'])) ?>">
            kintone設定
        </a>
        <a href="<?= h(app_url(['screen'=>'mail'])) ?>">
            メール設定
        </a>
    </nav>
</div>
</header>
<?php endif; ?>

<div class="container">
<div class="page">
<?php
}

function render_footer(): void
{
?>
</div>
</div>

<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-box">
        <div class="spinner"></div>
        <div>処理中です。しばらくお待ちください。</div>
    </div>
</div>

<script>
document.querySelectorAll('form[data-loading]')
    .forEach(function(form){
        form.addEventListener('submit',function(){
            var overlay =
                document.getElementById('loadingOverlay');

            if (overlay) {
                overlay.style.display = 'flex';
            }

            form.querySelectorAll(
                'button[type="submit"]'
            ).forEach(function(button){
                button.disabled = true;
            });
        });
    });

document.querySelectorAll('[data-confirm]')
    .forEach(function(element){
        element.addEventListener('click',function(event){
            var message =
                element.getAttribute('data-confirm');

            if (
                message
                && !window.confirm(message)
            ) {
                event.preventDefault();
            }
        });
    });

document.querySelectorAll('[data-dismiss]')
    .forEach(function(element){
        element.addEventListener('click',function(){
            element.remove();
        });
    });
</script>
</body>
</html>
<?php
}

/* ============================================================
 * 共通アラート
 * ============================================================ */

function render_flash(): void
{
    $flash = consume_flash();

    if (!$flash) {
        return;
    }

    $type =
        (string)($flash['type'] ?? 'info');

    $class =
        match ($type) {
            'success' => 'alert-success',
            'error' => 'alert-error',
            'warning' => 'alert-warning',
            default => 'alert-info',
        };
?>
<div class="alert <?= h($class) ?>">
    <?= h($flash['message'] ?? '') ?>
</div>
<?php
}

/* ============================================================
 * 一覧
 * ============================================================ */

function render_list(array $surveys): void
{
    $q =
        get_string('q');

    $status =
        get_string('status');

    $sort =
        get_string('sort');

    $filtered = [];

    foreach ($surveys as $survey) {
        if (
            $q !== ''
            && mb_stripos(
                (string)$survey['title'],
                $q
            ) === false
        ) {
            continue;
        }

        if (
            $status !== ''
            && $status !== 'all'
            && ($survey['status'] ?? 'draft')
                !== $status
        ) {
            continue;
        }

        $filtered[] =
            $survey;
    }

    usort(
        $filtered,
        static function(
            array $a,
            array $b
        ) use ($sort): int {
            return match ($sort) {
                'updated_old' =>
                    strcmp(
                        (string)$a['updatedAt'],
                        (string)$b['updatedAt']
                    ),
                'answers_desc',
                'answers_asc' => 0,
                'start_desc' =>
                    strcmp(
                        (string)$b['startAt'],
                        (string)$a['startAt']
                    ),
                'start_asc' =>
                    strcmp(
                        (string)$a['startAt'],
                        (string)$b['startAt']
                    ),
                default =>
                    strcmp(
                        (string)$b['updatedAt'],
                        (string)$a['updatedAt']
                    ),
            };
        }
    );

    render_head('アンケート一覧');
?>
<div class="page-title">
    <div>
        <h1>アンケート一覧</h1>
        <p>アンケートの作成・公開・集計・送信を管理します。</p>
    </div>

    <a class="btn btn-primary"
       href="<?= h(app_url(['screen'=>'edit'])) ?>">
        ＋ 新規作成
    </a>
</div>

<?php render_flash(); ?>

<div class="card">
<div class="card-body">

<form method="get" class="toolbar">
    <input type="hidden"
           name="screen"
           value="list">

    <div class="toolbar-left">
        <input
            type="search"
            name="q"
            value="<?= h($q) ?>"
            placeholder="タイトルを検索"
            style="min-width:260px">

        <select name="status">
            <option value="all">すべて</option>
            <option value="published"
                <?= $status==='published'?'selected':'' ?>>
                公開中
            </option>
            <option value="draft"
                <?= $status==='draft'?'selected':'' ?>>
                下書き
            </option>
            <option value="stopped"
                <?= $status==='stopped'?'selected':'' ?>>
                停止
            </option>
            <option value="ended"
                <?= $status==='ended'?'selected':'' ?>>
                終了
            </option>
        </select>

        <button class="btn btn-secondary"
                type="submit">
            検索
        </button>
    </div>

    <div class="toolbar-right">
        <select name="sort"
                onchange="this.form.submit()">
            <option value="">更新日：新しい順</option>
            <option value="updated_old"
                <?= $sort==='updated_old'?'selected':'' ?>>
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
</form>

<div class="table-scroll">
<table>
<thead>
<tr>
    <th>タイトル</th>
    <th>期間</th>
    <th>状態</th>
    <th>回答数</th>
    <th>更新日</th>
    <th>操作</th>
</tr>
</thead>
<tbody>
<?php if (!$filtered): ?>
<tr>
    <td colspan="6">
        <div class="empty">
            アンケートがありません。
        </div>
    </td>
</tr>
<?php else: ?>
<?php foreach ($filtered as $survey): ?>
<?php
    $answerCount = 0;
?>
<tr>
    <td>
        <strong><?= h($survey['title']) ?></strong>
    </td>

    <td>
        <?= h($survey['startAt'] ?? '') ?>
        <br>
        ～
        <?= h($survey['endAt'] ?? '') ?>
    </td>

    <td>
        <span class="badge badge-<?= h(
            status_class(
                (string)$survey['status']
            )
        ) ?>">
            <?= h(
                status_label(
                    (string)$survey['status']
                )
            ) ?>
        </span>
    </td>

    <td><?= h($answerCount) ?></td>

    <td>
        <?= h($survey['updatedAt'] ?? '') ?>
    </td>

    <td>
        <div class="button-row">

            <a class="btn btn-light"
               href="<?= h(app_url([
                   'screen'=>'edit',
                   'id'=>$survey['id'],
               ])) ?>">
                確認・編集
            </a>

            <a class="btn btn-light"
               href="<?= h(app_url([
                   'screen'=>'analytics',
                   'id'=>$survey['id'],
               ])) ?>">
                集計
            </a>

            <a class="btn btn-light"
               href="<?= h(app_url([
                   'screen'=>'send',
                   'id'=>$survey['id'],
               ])) ?>">
                送信
            </a>

            <form method="post"
                  data-loading
                  style="display:inline">
                <input type="hidden"
                       name="action"
                       value="duplicate_survey">
                <input type="hidden"
                       name="survey_id"
                       value="<?= h($survey['id']) ?>">

                <button
                    class="btn btn-secondary"
                    type="submit"
                    data-confirm="このアンケートを複製しますか？">
                    複製
                </button>
            </form>

            <form method="post"
                  data-loading
                  style="display:inline">
                <input type="hidden"
                       name="action"
                       value="delete_survey">
                <input type="hidden"
                       name="survey_id"
                       value="<?= h($survey['id']) ?>">

                <button
                    class="btn btn-danger"
                    type="submit"
                    data-confirm="このアンケートを削除しますか？">
                    削除
                </button>
            </form>

        </div>

        <?php if ($survey['status'] !== 'ended'): ?>
        <div class="button-row"
             style="margin-top:8px">

            <?php if ($survey['status'] === 'draft'): ?>
            <form method="post"
                  data-loading>
                <input type="hidden"
                       name="action"
                       value="change_status">
                <input type="hidden"
                       name="survey_id"
                       value="<?= h($survey['id']) ?>">
                <input type="hidden"
                       name="next_status"
                       value="published">

                <button
                    class="btn btn-success"
                    type="submit"
                    data-confirm="公開しますか？">
                    公開
                </button>
            </form>
            <?php elseif ($survey['status'] === 'published'): ?>
            <form method="post"
                  data-loading>
                <input type="hidden"
                       name="action"
                       value="change_status">
                <input type="hidden"
                       name="survey_id"
                       value="<?= h($survey['id']) ?>">
                <input type="hidden"
                       name="next_status"
                       value="stopped">

                <button
                    class="btn btn-warning"
                    type="submit"
                    data-confirm="停止しますか？">
                    停止
                </button>
            </form>
            <?php elseif ($survey['status'] === 'stopped'): ?>
            <form method="post"
                  data-loading>
                <input type="hidden"
                       name="action"
                       value="change_status">
                <input type="hidden"
                       name="survey_id"
                       value="<?= h($survey['id']) ?>">
                <input type="hidden"
                       name="next_status"
                       value="published">

                <button
                    class="btn btn-success"
                    type="submit"
                    data-confirm="再開しますか？">
                    再開
                </button>
            </form>
            <?php endif; ?>

        </div>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>

</div>
</div>
<?php
render_footer();
}

/* ============================================================
 * 編集
 * ============================================================ */

function render_edit(
    array $surveys,
    ?array $survey
): void {
    $survey =
        $survey
        ?? new_survey();

    recalc_question_numbers(
        $survey
    );

    render_head('アンケート作成・編集');
    render_flash();
?>
<div class="page-title">
    <div>
        <h1>アンケート作成・編集</h1>
        <p>質問、グループ、公開期間を設定します。</p>
    </div>
</div>

<form method="post"
      data-loading
      id="surveyForm">

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id'] ?? '') ?>">

<input type="hidden"
       name="return_screen"
       value="edit">

<div class="card">
<div class="card-body">

<div class="button-row"
     style="justify-content:space-between">

    <a class="btn btn-secondary"
       href="<?= h(app_url(['screen'=>'list'])) ?>">
        キャンセル
    </a>

    <div class="button-row">
        <span class="badge badge-<?= h(
            status_class(
                (string)$survey['status']
            )
        ) ?>">
            状態：
            <?= h(
                status_label(
                    (string)$survey['status']
                )
            ) ?>
        </span>

        <button class="btn btn-primary"
                type="submit">
            保存して一覧へ
        </button>
    </div>
</div>

<hr style="border:0;border-top:1px solid var(--border);margin:20px 0">

<div class="grid grid-2">

<div class="form-group">
<label>
<span>アンケートタイトル</span>
<input type="text"
       name="title"
       value="<?= h($survey['title']) ?>"
       maxlength="200"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>質問番号の採番方式</span>
<select name="numbering">
    <option value="global"
        <?= ($survey['numbering'] ?? 'global')
            === 'global'
            ? 'selected'
            : '' ?>>
        アンケート全体で通番
    </option>
    <option value="group"
        <?= ($survey['numbering'] ?? '')
            === 'group'
            ? 'selected'
            : '' ?>>
        グループ毎に採番
    </option>
</select>
</label>
</div>

</div>

<div class="form-group">
<label>
<span>アンケート説明</span>
<textarea name="description"><?= h(
    $survey['description']
) ?></textarea>
</label>
</div>

<div class="grid grid-2">

<div class="form-group">
<label>
<span>開始日時</span>
<input type="datetime-local"
       name="startAt"
       value="<?= h($survey['startAt']) ?>">
</label>
</div>

<div class="form-group">
<label>
<span>終了日時</span>
<input type="datetime-local"
       name="endAt"
       value="<?= h($survey['endAt']) ?>">
</label>
</div>

</div>

</div>
</div>

<div id="groups">
<?php foreach (
    $survey['groups'] as $gi => $group
): ?>

<div class="group-card"
     draggable="true"
     data-group-index="<?= h($gi) ?>">

<div class="group-head">
    <div>
        <span class="drag-handle">☷</span>
        <strong>
            グループ <?= h($gi + 1) ?>
        </strong>
    </div>

    <button
        type="button"
        class="btn btn-danger"
        onclick="removeGroup(this)">
        グループ削除
    </button>
</div>

<div class="card-body">

<div class="form-group">
<label>
<span>グループタイトル</span>
<input type="text"
       name="group_title[<?= h($group['id']) ?>]"
       value="<?= h($group['title']) ?>"
       maxlength="200">
</label>
</div>

<div class="questions">

<?php foreach (
    $group['questions'] as $qi => $question
): ?>

<div class="question-card"
     draggable="true"
     data-question-id="<?= h(
         $question['id']
     ) ?>">

<div class="question-head">
    <div>
        <span class="drag-handle">☷</span>
        <strong>
            <?= h($question['number']) ?>
        </strong>
    </div>

    <button
        type="button"
        class="btn btn-danger"
        onclick="removeQuestion(this)">
        質問削除
    </button>
</div>

<div class="question-body">

<input type="hidden"
       name="question_id[]"
       value="<?= h($question['id']) ?>">

<div class="form-group">
<label>
<span>質問文</span>
<input type="text"
       name="question_text[<?= h(
           $question['id']
       ) ?>]"
       value="<?= h($question['text']) ?>"
       maxlength="500">
</label>
</div>

<div class="grid grid-2">

<div class="form-group">
<label>
<span>回答形式</span>
<select name="question_type[<?= h(
    $question['id']
) ?>]">
    <option value="single"
        <?= ($question['type'] ?? '')
            === 'single'
            ? 'selected'
            : '' ?>>
        単一選択
    </option>
    <option value="multiple"
        <?= ($question['type'] ?? '')
            === 'multiple'
            ? 'selected'
            : '' ?>>
        複数選択
    </option>
    <option value="text"
        <?= ($question['type'] ?? '')
            === 'text'
            ? 'selected'
            : '' ?>>
        自由記述
    </option>
</select>
</label>
</div>

<div class="form-group">
<label>
<span>必須設定</span>
<select name="question_required[<?= h(
    $question['id']
) ?>]">
    <option value="1"
        <?= !empty($question['required'])
            ? 'selected'
            : '' ?>>
        必須
    </option>
    <option value="0"
        <?= empty($question['required'])
            ? 'selected'
            : '' ?>>
        任意
    </option>
</select>
</label>
</div>

</div>

<?php if (
    in_array(
        $question['type'] ?? '',
        ['single','multiple'],
        true
    )
): ?>

<div class="form-group">
<label>
<span>選択肢</span>
</label>

<div class="options">
<?php foreach (
    $question['options'] ?? []
    as $oi => $option
): ?>
<div class="option-row">
    <input type="text"
           name="question_option[<?= h(
               $question['id']
           ) ?>][]"
           value="<?= h($option) ?>">
    <button
        type="button"
        class="btn btn-light"
        onclick="this.parentElement.remove()">
        削除
    </button>
</div>
<?php endforeach; ?>
</div>

<button
    type="button"
    class="btn btn-secondary"
    onclick="addOption(this)">
    ＋ 選択肢追加
</button>
</div>

<?php endif; ?>

<?php if (
    ($question['type'] ?? '')
    === 'single'
): ?>
<div class="form-group">
<label>
<span>条件分岐</span>
<select name="branching[<?= h(
    $question['id']
) ?>]">
    <option value="">分岐なし</option>
    <?php foreach (
        $survey['groups'] as $branchGroup
    ): ?>
        <?php foreach (
            $branchGroup['questions']
            as $branchQuestion
        ): ?>
            <?php if (
                $branchQuestion['id']
                !== $question['id']
            ): ?>
            <option
                value="<?= h(
                    $branchQuestion['id']
                ) ?>">
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
</label>
</div>
<?php endif; ?>

</div>
</div>

<?php endforeach; ?>
</div>

<button
    type="button"
    class="btn btn-secondary"
    onclick="addQuestion(this)">
    ＋ 質問を追加
</button>

</div>
</div>

<?php endforeach; ?>
</div>

<div class="card">
<div class="card-body">
    <button
        type="button"
        class="btn btn-secondary"
        onclick="addGroup()">
        ＋ グループを追加
    </button>
</div>
</div>

<div class="sticky-actions">
<div class="button-row"
     style="justify-content:flex-end">

    <a class="btn btn-secondary"
       href="<?= h(app_url([
           'screen'=>'preview',
           'id'=>$survey['id'],
       ])) ?>">
        プレビュー
    </a>

    <button class="btn btn-primary"
            type="submit">
        保存して一覧へ
    </button>
</div>
</div>

</form>

<script>
function removeQuestion(button){
    if(window.confirm('この質問を削除しますか？')){
        button.closest('.question-card').remove();
    }
}

function removeGroup(button){
    if(window.confirm('このグループを削除しますか？')){
        button.closest('.group-card').remove();
    }
}

function addOption(button){
    var container =
        button.previousElementSibling;

    var row =
        document.createElement('div');

    row.className = 'option-row';

    row.innerHTML =
        '<input type="text" name="question_option[new][]">'
        + '<button type="button" class="btn btn-light"'
        + ' onclick="this.parentElement.remove()">'
        + '削除</button>';

    container.appendChild(row);
}

function addQuestion(button){
    var container =
        button.previousElementSibling;

    var id =
        'new-' + Date.now();

    var card =
        document.createElement('div');

    card.className =
        'question-card';

    card.innerHTML =
        '<div class="question-head">'
        + '<strong>新規質問</strong>'
        + '<button type="button" class="btn btn-danger"'
        + ' onclick="removeQuestion(this)">質問削除</button>'
        + '</div>'
        + '<div class="question-body">'
        + '<input type="hidden" name="question_id[]" value="'+id+'">'
        + '<div class="form-group">'
        + '<label><span>質問文</span>'
        + '<input type="text" name="question_text['+id+']">'
        + '</label></div>'
        + '<div class="grid grid-2">'
        + '<div class="form-group"><label><span>回答形式</span>'
        + '<select name="question_type['+id+']">'
        + '<option value="single">単一選択</option>'
        + '<option value="multiple">複数選択</option>'
        + '<option value="text">自由記述</option>'
        + '</select></label></div>'
        + '<div class="form-group"><label><span>必須設定</span>'
        + '<select name="question_required['+id+']">'
        + '<option value="1">必須</option>'
        + '<option value="0">任意</option>'
        + '</select></label></div>'
        + '</div>'
        + '</div>';

    container.appendChild(card);
}

function addGroup(){
    var groups =
        document.getElementById('groups');

    var group =
        document.createElement('div');

    group.className =
        'group-card';

    group.innerHTML =
        '<div class="group-head">'
        + '<strong>新しいグループ</strong>'
        + '<button type="button" class="btn btn-danger"'
        + ' onclick="removeGroup(this)">グループ削除</button>'
        + '</div>'
        + '<div class="card-body">'
        + '<div class="form-group">'
        + '<label><span>グループタイトル</span>'
        + '<input type="text" name="group_title[new-'+Date.now()+']">'
        + '</label></div>'
        + '<div class="questions"></div>'
        + '<button type="button" class="btn btn-secondary"'
        + ' onclick="addQuestion(this)">'
        + '＋ 質問を追加</button>'
        + '</div>';

    groups.appendChild(group);
}
</script>

<?php
render_footer();
}

/* ============================================================
 * プレビュー
 * ============================================================ */

function render_preview(
    ?array $survey
): void {
    if ($survey === null) {
        render_head('プレビュー');
        ?>
        <div class="alert alert-error">
            アンケートが見つかりません。
        </div>
        <?php
        render_footer();
        return;
    }

    render_head('プレビュー');
?>
<div class="page-title">
    <div>
        <h1><?= h($survey['title']) ?></h1>
        <p>アンケートプレビュー</p>
    </div>

    <a class="btn btn-secondary"
       href="<?= h(app_url([
           'screen'=>'edit',
           'id'=>$survey['id'],
       ])) ?>">
        編集へ戻る
    </a>
</div>

<div class="card">
<div class="card-body">

<?php if (
    trim((string)$survey['description'])
    !== ''
): ?>
<p><?= nl2br(
    h($survey['description'])
) ?></p>
<?php endif; ?>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<h2 style="margin-top:28px">
    <?= h($group['title']) ?>
</h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="preview-question">
    <div>
        <strong>
            <?= h($question['number']) ?>
            <?= h($question['text']) ?>
        </strong>

        <?php if (
            !empty($question['required'])
        ): ?>
        <span class="required">必須</span>
        <?php endif; ?>
    </div>

    <div style="margin-top:12px">

    <?php if (
        $question['type']
        === 'text'
    ): ?>

        <textarea
            placeholder="回答を入力してください"></textarea>

    <?php elseif (
        $question['type']
        === 'multiple'
    ): ?>

        <?php foreach (
            $question['options'] ?? []
            as $option
        ): ?>
        <label class="answer-option">
            <input type="checkbox">
            <span><?= h($option) ?></span>
        </label>
        <?php endforeach; ?>

    <?php else: ?>

        <?php foreach (
            $question['options'] ?? []
            as $option
        ): ?>
        <label class="answer-option">
            <input type="radio"
                   name="preview_<?= h(
                       $question['id']
                   ) ?>">
            <span><?= h($option) ?></span>
        </label>
        <?php endforeach; ?>

    <?php endif; ?>

    </div>
</div>

<?php endforeach; ?>
<?php endforeach; ?>

</div>
</div>
<?php
render_footer();
}

/* ============================================================
 * kintone設定
 * ============================================================ */

function render_kintone(
    array $config
): void {
    $test =
        $_SESSION[
            'kintone_test_result'
        ] ?? null;

    unset(
        $_SESSION[
            'kintone_test_result'
        ]
    );

    $fieldsResult =
        $_SESSION[
            'kintone_fields_result'
        ] ?? null;

    unset(
        $_SESSION[
            'kintone_fields_result'
        ]
    );

    $syncResult =
        $_SESSION[
            'kintone_sync_result'
        ] ?? null;

    unset(
        $_SESSION[
            'kintone_sync_result'
        ]
    );

    render_head('kintone設定');
?>
<div class="page-title">
    <div>
        <h1>kintone連携設定</h1>
        <p>顧客情報取得元となるkintoneを設定します。</p>
    </div>
</div>

<?php render_flash(); ?>

<?php if (
    is_array($test)
): ?>
<div class="alert <?= !empty($test['ok'])
    ? 'alert-success'
    : 'alert-error' ?>">

<strong>
<?= !empty($test['ok'])
    ? '✓ kintone接続テスト'
    : '✕ kintone接続テスト' ?>
</strong>

<div style="margin-top:6px">
    <?= h($test['message'] ?? '') ?>
</div>

<?php if (empty($test['ok'])): ?>
<div style="margin-top:8px">
    HTTP
    <?= h($test['status'] ?? 0) ?>

    <?php if (!empty($test['code'])): ?>
    / エラーコード:
    <?= h($test['code']) ?>
    <?php endif; ?>

    <?php if (!empty($test['id'])): ?>
    / エラーID:
    <?= h($test['id']) ?>
    <?php endif; ?>
</div>
<?php endif; ?>

</div>
<?php endif; ?>

<?php if (
    is_array($fieldsResult)
): ?>
<div class="alert <?= !empty($fieldsResult['ok'])
    ? 'alert-success'
    : 'alert-error' ?>">
    <?= h(
        $fieldsResult['message'] ?? ''
    ) ?>
</div>
<?php endif; ?>

<?php if (
    is_array($syncResult)
): ?>
<div class="alert <?= !empty($syncResult['ok'])
    ? 'alert-success'
    : 'alert-error' ?>">
    <?= h(
        $syncResult['message'] ?? ''
    ) ?>
</div>
<?php endif; ?>

<div class="card">
<div class="card-header">
    <h2>接続設定</h2>

    <span class="badge <?= h(
        $config['status'] === '接続確認済み'
            ? 'badge-success'
            : 'badge-gray'
    ) ?>">
        <?= h($config['status']) ?>
    </span>
</div>

<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid grid-2">

<div class="form-group">
<label>
<span>サブドメイン</span>
<input type="text"
       name="subdomain"
       value="<?= h(
           $config['subdomain']
       ) ?>"
       placeholder="xxxx / xxxx.cybozu.com"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>顧客管理アプリID</span>
<input type="number"
       name="app_id"
       min="1"
       value="<?= h(
           $config['app_id']
       ) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>ログイン名</span>
<input type="text"
       name="username"
       value="<?= h(
           $config['username']
       ) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>パスワード</span>
<input type="password"
       name="password"
       value=""
       autocomplete="new-password">
</label>
<div class="help">
変更しない場合は空欄のままにしてください。
</div>
</div>

<div class="form-group">
<label>
<span>Proxy</span>
<input type="text"
       name="proxy"
       value="<?= h(
           $config['proxy']
       ) ?>"
       placeholder="host:port">
</label>
</div>

<div class="form-group">
<label>
<span>SSL証明書検証</span>
<select name="verify_ssl">
    <option value=""
        <?= empty($config['verify_ssl'])
            ? 'selected'
            : '' ?>>
        無効
    </option>
    <option value="1"
        <?= !empty($config['verify_ssl'])
            ? 'selected'
            : '' ?>>
        有効
    </option>
</select>
</label>
</div>

</div>

<div class="button-row"
     style="margin-top:10px">

<button class="btn btn-primary"
        type="submit">
    設定保存
</button>

</div>

</form>

<hr style="border:0;border-top:1px solid var(--border);margin:22px 0">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="test_kintone">

<input type="hidden"
       name="subdomain"
       value="<?= h(
           $config['subdomain']
       ) ?>">

<input type="hidden"
       name="app_id"
       value="<?= h(
           $config['app_id']
       ) ?>">

<input type="hidden"
       name="username"
       value="<?= h(
           $config['username']
       ) ?>">

<input type="hidden"
       name="proxy"
       value="<?= h(
           $config['proxy']
       ) ?>">

<?php if (
    !empty($config['verify_ssl'])
): ?>
<input type="hidden"
       name="verify_ssl"
       value="1">
<?php endif; ?>

<div class="form-group">
<label>
<span>接続テスト用パスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="保存済みの場合は空欄でも可">
</label>
</div>

<button class="btn btn-secondary"
        type="submit">
    接続テスト
</button>

</form>

</div>
</div>

<div class="card">
<div class="card-header">
    <h2>顧客項目マッピング</h2>
</div>

<div class="card-body">

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

<?php if (
    !empty($config['fields'])
): ?>

<form method="post"
      data-loading
      style="margin-top:20px">

<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<div class="grid grid-2">

<?php
$mappingLabels = [
    'organization' => '組織名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署名',
    'phone' => '電話番号',
];
?>

<?php foreach (
    $mappingLabels as $key => $label
): ?>
<div class="form-group">
<label>
<span><?= h($label) ?></span>
<select name="mapping_<?= h($key) ?>">
    <option value="">未設定</option>

    <?php foreach (
        $config['fields']
        as $field
    ): ?>
    <option
        value="<?= h(
            $field['code']
        ) ?>"
        <?= (
            ($config['mapping'][$key] ?? '')
            === $field['code']
        )
            ? 'selected'
            : '' ?>>
        <?= h(
            $field['label']
            . ' ('
            . $field['code']
            . ')'
        ) ?>
    </option>
    <?php endforeach; ?>
</select>
</label>
</div>
<?php endforeach; ?>

<div class="form-group">
<label>
<span>住所</span>

<?php foreach (
    $config['fields']
    as $field
): ?>
<label style="font-weight:400;margin:7px 0">
<input type="checkbox"
       name="mapping_address[]"
       value="<?= h(
           $field['code']
       ) ?>"
       <?= in_array(
           $field['code'],
           $config['mapping']['address']
               ?? [],
           true
       )
           ? 'checked'
           : '' ?>
       style="width:auto">
<?= h($field['label']) ?>
</label>
<?php endforeach; ?>

</label>
</div>

</div>

<button class="btn btn-primary"
        type="submit">
    マッピングを保存
</button>

</form>

<?php endif; ?>

</div>
</div>

<div class="card">
<div class="card-header">
    <h2>顧客情報同期</h2>
</div>

<div class="card-body">

<p>
kintoneの顧客管理アプリから顧客情報を取得し、
サーバー側の顧客情報を更新します。
</p>

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="sync_kintone">

<button class="btn btn-primary"
        type="submit"
        data-confirm="kintoneから顧客情報を同期しますか？">
    顧客情報を同期
</button>

</form>

<?php if (
    !empty($config['last_sync'])
): ?>
<p class="help">
最終同期：
<?= h($config['last_sync']) ?>
</p>
<?php endif; ?>

</div>
</div>

<?php
render_footer();
}

/* ============================================================
 * メール設定
 * ============================================================ */

function render_mail(
    array $config
): void {
    $test =
        $_SESSION[
            'mail_test_result'
        ] ?? null;

    unset(
        $_SESSION[
            'mail_test_result'
        ]
    );

    render_head('メールサーバ設定');
?>
<div class="page-title">
    <div>
        <h1>メールサーバ設定</h1>
        <p>SMTPサーバへの接続・認証設定を行います。</p>
    </div>
</div>

<?php render_flash(); ?>

<?php if (
    is_array($test)
): ?>
<div class="alert <?= !empty($test['ok'])
    ? 'alert-success'
    : 'alert-error' ?>">
    <?= h(
        $test['message'] ?? ''
    ) ?>
</div>
<?php endif; ?>

<div class="card">
<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_mail">

<div class="grid grid-2">

<div class="form-group">
<label>
<span>SMTPサーバ</span>
<input type="text"
       name="server"
       value="<?= h(
           $config['server']
       ) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>SMTPポート</span>
<input type="number"
       name="port"
       min="1"
       max="65535"
       value="<?= h(
           $config['port']
       ) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>暗号化方式</span>
<select name="encryption">
    <option value="ssl"
        <?= $config['encryption']==='ssl'
            ? 'selected'
            : '' ?>>
        SSL
    </option>
    <option value="tls"
        <?= $config['encryption']==='tls'
            ? 'selected'
            : '' ?>>
        TLS
    </option>
    <option value="none"
        <?= $config['encryption']==='none'
            ? 'selected'
            : '' ?>>
        なし
    </option>
</select>
</label>
</div>

<div class="form-group">
<label>
<span>SMTP認証</span>
<select name="auth">
    <option value="1"
        <?= !empty($config['auth'])
            ? 'selected'
            : '' ?>>
        あり
    </option>
    <option value="0"
        <?= empty($config['auth'])
            ? 'selected'
            : '' ?>>
        なし
    </option>
</select>
</label>
</div>

<div class="form-group">
<label>
<span>SMTPユーザー名</span>
<input type="text"
       name="username"
       value="<?= h(
           $config['username']
       ) ?>">
</label>
</div>

<div class="form-group">
<label>
<span>SMTPパスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password">
</label>
<div class="help">
変更しない場合は空欄のままにしてください。
</div>
</div>

<div class="form-group">
<label>
<span>送信元メールアドレス</span>
<input type="email"
       name="from_email"
       value="<?= h(
           $config['from_email']
       ) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>送信元名</span>
<input type="text"
       name="from_name"
       value="<?= h(
           $config['from_name']
       ) ?>">
</label>
</div>

<div class="form-group">
<label>
<span>返信先メールアドレス</span>
<input type="email"
       name="reply_to"
       value="<?= h(
           $config['reply_to']
       ) ?>">
</label>
</div>

</div>

<button class="btn btn-primary"
        type="submit">
    設定保存
</button>

</form>

<hr style="border:0;border-top:1px solid var(--border);margin:22px 0">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="test_mail">

<?php foreach (
    [
        'server',
        'port',
        'encryption',
        'username',
        'from_email',
        'from_name',
        'reply_to',
    ] as $key
): ?>
<input type="hidden"
       name="<?= h($key) ?>"
       value="<?= h(
           $config[$key] ?? ''
       ) ?>">
<?php endforeach; ?>

<input type="hidden"
       name="auth"
       value="<?= !empty($config['auth'])
           ? '1'
           : '0' ?>">

<div class="form-group">
<label>
<span>接続テスト用パスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="保存済みの場合は空欄でも可">
</label>
</div>

<button class="btn btn-secondary"
        type="submit">
    接続テスト
</button>

</form>

</div>
</div>

<div class="card">
<div class="card-header">
    <h2>接続状態</h2>
</div>

<div class="card-body">

<span class="badge badge-<?= h(
    $config['status'] === '接続確認済み'
        ? 'badge-success'
        : 'badge-gray'
) ?>">
    <?= h($config['status']) ?>
</span>

<?php if (
    !empty($config['last_test'])
): ?>
<p class="help">
最終確認：
<?= h($config['last_test']) ?>
</p>
<?php endif; ?>

</div>
</div>

<?php
render_footer();
}

/* ============================================================
 * 送信
 * ============================================================ */

function render_send(
    array $survey,
    array $customers,
    array $history
): void {
    $result =
        $_SESSION['send_result']
        ?? null;

    unset(
        $_SESSION['send_result']
    );

    $q =
        get_string('q');

    render_head('顧客選択・メール送信');
?>
<div class="page-title">
    <div>
        <h1>顧客選択・メール送信</h1>
        <p>
            対象：
            <strong><?= h(
                $survey['title']
            ) ?></strong>
        </p>
    </div>

    <a class="btn btn-secondary"
       href="<?= h(app_url([
           'screen'=>'list',
       ])) ?>">
        一覧へ戻る
    </a>
</div>

<?php if (
    is_array($result)
): ?>
<div class="alert alert-success">
    <?= h($result['message'] ?? '') ?>
</div>
<?php endif; ?>

<div class="card">
<div class="card-header">
    <h2>顧客選択・メール作成</h2>
</div>

<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<div class="form-group">
<label>
<span>顧客検索</span>
<input type="search"
       value="<?= h($q) ?>"
       placeholder="氏名・組織名・メールアドレス">
</label>
</div>

<div class="table-scroll">
<table>
<thead>
<tr>
    <th>
        <input type="checkbox"
               id="selectAll">
    </th>
    <th>組織名</th>
    <th>氏名</th>
    <th>メールアドレス</th>
    <th>部署</th>
</tr>
</thead>

<tbody>
<?php foreach (
    $customers as $customer
): ?>
<tr>
    <td>
        <input type="checkbox"
               name="customer_ids[]"
               value="<?= h(
                   $customer['id']
               ) ?>"
               class="customer-check">
    </td>

    <td><?= h(
        $customer['organization']
        ?? ''
    ) ?></td>

    <td><?= h(
        $customer['name']
        ?? ''
    ) ?></td>

    <td><?= h(
        $customer['email']
        ?? ''
    ) ?></td>

    <td><?= h(
        $customer['department']
        ?? ''
    ) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="grid grid-2"
     style="margin-top:20px">

<div class="form-group">
<label>
<span>メール件名</span>
<input type="text"
       name="subject"
       value="<?= h(
           $survey['title']
       ) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>利用可能な変数</span>
<div class="notice">
{顧客名}<br>
{アンケートURL}
</div>
</label>
</div>

</div>

<div class="form-group">
<label>
<span>メール本文</span>
<textarea name="body"
          required>いつもお世話になっております。

{顧客名} 様

以下のURLよりアンケートへご回答ください。

{アンケートURL}

よろしくお願いいたします。</textarea>
</label>
</div>

<div class="button-row">
    <button
        class="btn btn-primary"
        type="submit"
        data-confirm="選択した顧客へメールを送信しますか？">
        一括送信
    </button>

    <button
        class="btn btn-secondary"
        type="button">
        再送
    </button>

    <button
        class="btn btn-secondary"
        type="button">
        リマインド
    </button>
</div>

</form>

</div>
</div>

<div class="card">
<div class="card-header">
    <h2>送信履歴</h2>
</div>

<div class="card-body">

<div class="table-scroll">
<table>
<thead>
<tr>
    <th>日時</th>
    <th>顧客名</th>
    <th>メール</th>
    <th>種別</th>
    <th>結果</th>
</tr>
</thead>
<tbody>
<?php
$found = false;

foreach (
    array_reverse($history)
    as $row
):
    if (
        ($row['survey_id'] ?? '')
        !== $survey['id']
    ) {
        continue;
    }

    $found = true;
?>
<tr>
    <td><?= h(
        $row['createdAt']
        ?? ''
    ) ?></td>
    <td><?= h(
        $row['customer_name']
        ?? ''
    ) ?></td>
    <td><?= h(
        $row['email']
        ?? ''
    ) ?></td>
    <td><?= h(
        $row['type']
        ?? ''
    ) ?></td>
    <td>
        <span class="badge badge-success">
            <?= h(
                $row['status']
                ?? ''
            ) ?>
        </span>
    </td>
</tr>
<?php endforeach; ?>

<?php if (!$found): ?>
<tr>
    <td colspan="5">
        <div class="empty">
            送信履歴はありません。
        </div>
    </td>
</tr>
<?php endif; ?>

</tbody>
</table>
</div>

</div>
</div>

<script>
document.getElementById('selectAll')
    ?.addEventListener('change',function(){
        document.querySelectorAll(
            '.customer-check'
        ).forEach(function(input){
            input.checked =
                document.getElementById(
                    'selectAll'
                ).checked;
        });
    });
</script>

<?php
render_footer();
}

/* ============================================================
 * 集計
 * ============================================================ */

function render_analytics(
    array $survey,
    array $answers,
    array $customers
): void {
    $surveyAnswers = [];

    foreach (
        $answers as $answer
    ) {
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
                $answerCount
                / $customerCount
                * 100,
                1
            )
            : 0;

    render_head('回答集計・分析');
?>
<div class="page-title">
    <div>
        <h1>回答集計・分析</h1>
        <p>
            対象：
            <strong><?= h(
                $survey['title']
            ) ?></strong>
        </p>
    </div>

    <a class="btn btn-secondary"
       href="<?= h(app_url([
           'screen'=>'list',
       ])) ?>">
        一覧へ戻る
    </a>
</div>

<div class="stat-grid">

<div class="stat">
    <div class="stat-label">
        送信対象者数
    </div>
    <div class="stat-value">
        <?= h($customerCount) ?>
    </div>
</div>

<div class="stat">
    <div class="stat-label">
        回答数
    </div>
    <div class="stat-value">
        <?= h($answerCount) ?>
    </div>
</div>

<div class="stat">
    <div class="stat-label">
        未回答数
    </div>
    <div class="stat-value">
        <?= h(
            max(
                0,
                $customerCount
                - $answerCount
            )
        ) ?>
    </div>
</div>

<div class="stat">
    <div class="stat-label">
        回答率
    </div>
    <div class="stat-value">
        <?= h($rate) ?>%
    </div>
</div>

</div>

<div class="card">
<div class="card-header">
    <h2>出力</h2>
</div>

<div class="card-body">

<div class="button-row">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'csv',
       'id'=>$survey['id'],
   ])) ?>">
    CSV出力
</a>

<button class="btn btn-secondary"
        type="button"
        onclick="window.print()">
    PDF出力
</button>

</div>

</div>
</div>

<?php if (!$surveyAnswers): ?>

<div class="card">
<div class="card-body">
    <div class="empty">
        現在、回答データはありません
    </div>
</div>
</div>

<?php else: ?>

<?php foreach (
    $survey['groups'] as $group
): ?>

<div class="card">
<div class="card-header">
    <h2><?= h(
        $group['title']
    ) ?></h2>
</div>

<div class="card-body">

<?php foreach (
    $group['questions'] as $question
): ?>

<?php
$counts = [];

foreach (
    $question['options'] ?? []
    as $option
) {
    $counts[$option] = 0;
}

$textAnswers = [];

foreach (
    $surveyAnswers as $answer
) {
    $value =
        $answer['answers'][
            $question['id']
        ] ?? '';

    if (
        is_array($value)
    ) {
        foreach ($value as $item) {
            $counts[$item] =
                ($counts[$item] ?? 0)
                + 1;
        }
    } elseif (
        $question['type'] !== 'text'
    ) {
        if ($value !== '') {
            $counts[$value] =
                ($counts[$value] ?? 0)
                + 1;
        }
    } elseif (
        $value !== ''
    ) {
        $textAnswers[] =
            $value;
    }
}
?>

<div class="preview-question">

<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<?php if (
    $question['type'] === 'text'
): ?>

<?php foreach (
    $textAnswers as $text
): ?>
<div class="notice"
     style="margin-top:10px">
    <?= nl2br(h($text)) ?>
</div>
<?php endforeach; ?>

<?php else: ?>

<div style="margin-top:12px">
<?php foreach (
    $counts as $option => $count
): ?>
<div style="margin-bottom:10px">
    <div style="
        display:flex;
        justify-content:space-between;
        gap:10px;">
        <span><?= h($option) ?></span>
        <strong><?= h($count) ?>件</strong>
    </div>

    <div style="
        height:8px;
        background:#e2e8f0;
        border-radius:99px;
        overflow:hidden;">
        <div style="
            height:100%;
            width:<?= $answerCount > 0
                ? h(
                    min(
                        100,
                        $count
                        / $answerCount
                        * 100
                    )
                )
                : 0 ?>%;
            background:var(--primary);">
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>
</div>

<?php endforeach; ?>

<div class="card">
<div class="card-header">
    <h2>個別回答</h2>
</div>

<div class="card-body">

<?php foreach (
    $surveyAnswers as $answer
): ?>

<div class="notice">

<strong>
<?= h(
    $answer['createdAt']
    ?? ''
) ?>
</strong>

<?php foreach (
    $survey['groups'] as $group
): ?>

<?php foreach (
    $group['questions'] as $question
): ?>

<?php
$value =
    $answer['answers'][
        $question['id']
    ] ?? '';

if (is_array($value)) {
    $value =
        implode(
            '、',
            $value
        );
}
?>

<div style="margin-top:8px">
    <strong>
        <?= h(
            $question['number']
        ) ?>
    </strong>
    <?= h(
        $question['text']
    ) ?>：
    <?= nl2br(h($value)) ?>
</div>

<?php endforeach; ?>
<?php endforeach; ?>

</div>

<?php endforeach; ?>

</div>
</div>

<?php endif; ?>

<?php
render_footer();
}

/* ============================================================
 * 回答者
 * ============================================================ */

function render_answer(
    array $survey
): void {
    render_head(
        $survey['title'],
        false
    );
?>
<div class="page-title">
    <div>
        <h1><?= h(
            $survey['title']
        ) ?></h1>

        <?php if (
            trim(
                (string)$survey['description']
            ) !== ''
        ): ?>
        <p><?= nl2br(
            h($survey['description'])
        ) ?></p>
        <?php endif; ?>
    </div>
</div>

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="answer">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<?php foreach (
    $survey['groups'] as $group
): ?>

<div class="card">
<div class="card-header">
    <h2><?= h(
        $group['title']
    ) ?></h2>
</div>

<div class="card-body">

<?php foreach (
    $group['questions'] as $question
): ?>

<div class="preview-question">

<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>

<?php if (
    !empty($question['required'])
): ?>
<span class="required">必須</span>
<?php endif; ?>

</strong>

<div style="margin-top:12px">

<?php if (
    $question['type'] === 'text'
): ?>

<textarea
    name="answer_<?= h(
        $question['id']
    ) ?>"
    <?= !empty($question['required'])
        ? 'required'
        : '' ?>></textarea>

<?php elseif (
    $question['type'] === 'multiple'
): ?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>
<label class="answer-option">
    <input
        type="checkbox"
        name="answer_<?= h(
            $question['id']
        ) ?>[]"
        value="<?= h($option) ?>">
    <span><?= h($option) ?></span>
</label>
<?php endforeach; ?>

<?php else: ?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>
<label class="answer-option">
    <input
        type="radio"
        name="answer_<?= h(
            $question['id']
        ) ?>"
        value="<?= h($option) ?>"
        <?= !empty($question['required'])
            ? 'required'
            : '' ?>>
    <span><?= h($option) ?></span>
</label>
<?php endforeach; ?>

<?php endif; ?>

</div>
</div>

<?php endforeach; ?>

</div>
</div>

<?php endforeach; ?>

<div class="button-row"
     style="justify-content:flex-end">

<button class="btn btn-primary"
        type="submit">
    回答を確認
</button>

</div>

</form>
<?php
render_footer();
}

/* ============================================================
 * 回答確認
 * ============================================================ */

function render_confirm(
    array $survey
): void {
    $draft =
        $_SESSION[
            'answer_draft'
        ] ?? [];

    $values =
        is_array($draft)
            ? ($draft['answers'] ?? [])
            : [];

    render_head(
        '回答確認',
        false
    );
?>
<div class="page-title">
    <div>
        <h1>回答確認</h1>
        <p><?= h(
            $survey['title']
        ) ?></p>
    </div>
</div>

<div class="card">
<div class="card-body">

<?php foreach (
    $survey['groups'] as $group
): ?>

<h2><?= h(
    $group['title']
) ?></h2>

<?php foreach (
    $group['questions'] as $question
): ?>

<?php
$value =
    $values[
        $question['id']
    ] ?? '';

if (is_array($value)) {
    $value =
        implode(
            '、',
            $value
        );
}
?>

<div class="preview-question">

<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<div class="notice"
     style="margin-top:10px">
    <?= nl2br(h($value)) ?>
</div>

</div>

<?php endforeach; ?>
<?php endforeach; ?>

<div class="button-row"
     style="justify-content:space-between;margin-top:20px">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'answer',
       'id'=>$survey['id'],
   ])) ?>">
    戻って修正
</a>

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="finalize_answer">

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
render_footer();
}

/* ============================================================
 * 完了
 * ============================================================ */

function render_complete(): void
{
    render_head(
        '回答完了',
        false
    );
?>
<div class="card">
<div class="card-body"
     style="text-align:center;padding:50px 20px">

<div style="
    width:64px;
    height:64px;
    border-radius:50%;
    background:#dcfce7;
    color:#16a34a;
    display:flex;
    align-items:center;
    justify-content:center;
    margin:0 auto 18px;
    font-size:30px;">
    ✓
</div>

<h1>回答ありがとうございました</h1>

<p>
アンケートの回答を受け付けました。
</p>

</div>
</div>
<?php
render_footer();
}

/* ============================================================
 * ルーティング
 * ============================================================ */

if (
    in_array(
        $screen,
        ['answer','confirm'],
        true
    )
) {
    $survey =
        find_survey(
            $surveys,
            $id
        );

    if ($survey === null) {
        render_head(
            'アンケート',
            false
        );

        ?>
        <div class="alert alert-error">
            アンケートが見つかりません。
        </div>
        <?php

        render_footer();
        exit;
    }

    if (
        ($survey['status'] ?? '')
        !== 'published'
    ) {
        render_head(
            'アンケート',
            false
        );

        ?>
        <div class="alert alert-warning">
            このアンケートは現在回答を受け付けていません。
        </div>
        <?php

        render_footer();
        exit;
    }

    if ($screen === 'answer') {
        render_answer($survey);
    } else {
        render_confirm($survey);
    }

    exit;
}

if ($screen === 'complete') {
    render_complete();
    exit;
}

/* ============================================================
 * 管理者画面
 * ============================================================ */

switch ($screen) {

    case 'list':
        render_list($surveys);
        break;

    case 'edit':
        $survey =
            $id !== ''
                ? find_survey(
                    $surveys,
                    $id
                )
                : null;

        if (
            $id !== ''
            && $survey === null
        ) {
            flash(
                'error',
                '指定されたアンケートが見つかりません。'
            );

            render_list(
                $surveys
            );
            break;
        }

        render_edit(
            $surveys,
            $survey
        );
        break;

    case 'preview':
        render_preview(
            find_survey(
                $surveys,
                $id
            )
        );
        break;

    case 'send':
        $survey =
            find_survey(
                $surveys,
                $id
            );

        if ($survey === null) {
            flash(
                'error',
                '送信対象のアンケートが見つかりません。'
            );

            render_list(
                $surveys
            );
            break;
        }

        /*
         * 対象アンケートを固定。
         * send画面内では別アンケートを選択しない。
         */
        render_send(
            $survey,
            $customers,
            $history
        );
        break;

    case 'analytics':
        $survey =
            find_survey(
                $surveys,
                $id
            );

        if ($survey === null) {
            flash(
                'error',
                '集計対象のアンケートが見つかりません。'
            );

            render_list(
                $surveys
            );
            break;
        }

        /*
         * 対象アンケートを固定。
         * analytics画面内では別アンケートを選択しない。
         */
        render_analytics(
            $survey,
            $answers,
            $customers
        );
        break;

    case 'kintone':
        render_kintone(
            $kintone
        );
        break;

    case 'mail':
        render_mail(
            $mail
        );
        break;

    default:
        render_list(
            $surveys
        );
        break;
}
<?php
declare(strict_types=1);

/*
 * アンケート管理システム
 *
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし / PHP mail()なし
 * index.php 単一エントリーポイント
 *
 * 重要な設計方針
 * - 保存フォームと状態変更フォームを絶対に入れ子にしない
 * - 状態変更は save_survey と完全に別POST action
 * - 公開中の終了日時経過だけを自動的に「終了」にする
 * - draft / stopped は終了日時経過だけでは変更しない
 * - 質問・グループのD&DはDOM順序とhidden inputを同期
 * - メールの {アンケートURL} は絶対URLを生成
 * - kintone通信はPHP streamのみ
 * - SMTP通信はPHP streamのみ
 * - 機密情報はブラウザへ返さない
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
 * Session
 * ============================================================ */

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $path = dirname($script);

    if ($path === '.' || $path === '/') {
        $path = '/';
    } else {
        $path = rtrim($path, '/') . '/';
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
 * Common
 * ============================================================ */

function h(mixed $v): string
{
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function now_text(): string
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
    $v = $_POST[$key] ?? [];

    if (!is_array($v)) {
        return [];
    }

    $out = [];

    foreach ($v as $item) {
        if (is_scalar($item)) {
            $out[] = trim((string)$item);
        }
    }

    return array_values(array_unique($out));
}

function new_id(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function valid_id(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{1,120}$/', $id);
}

function app_url(array $params = []): string
{
    $url = 'index.php';

    if ($params) {
        $url .= '?' . http_build_query($params);
    }

    return $url;
}

/*
 * メールに埋め込むURL。
 *
 * APP_PUBLIC_BASE_URL を設定すればそれを優先する。
 * 未設定時は現在のHTTPホストから生成する。
 */
function public_base_url(): string
{
    $configured = getenv('APP_PUBLIC_BASE_URL');

    if (is_string($configured) && trim($configured) !== '') {
        return rtrim(trim($configured), '/');
    }

    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

    $scheme = $https ? 'https' : 'http';

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');

    /*
     * Hostヘッダーをそのまま信頼してメールへ出さない。
     * 明らかに不正なホストは使用しない。
     */
    if (
        $host === ''
        || !preg_match(
            '/^(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,63}(?::[0-9]{1,5})?$|^localhost(?::[0-9]{1,5})?$/',
            $host
        )
    ) {
        $configuredHost = getenv('APP_PUBLIC_HOST');

        if (is_string($configuredHost) && trim($configuredHost) !== '') {
            $host = trim($configuredHost);
        } else {
            $host = 'localhost';
        }
    }

    return $scheme . '://' . $host;
}

function public_app_url(array $params = []): string
{
    $script = str_replace(
        '\\',
        '/',
        (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')
    );

    if ($script === '') {
        $script = '/index.php';
    }

    return public_base_url()
        . $script
        . ($params ? '?' . http_build_query($params) : '');
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function take_flash(): ?array
{
    $v = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($v) ? $v : null;
}

function redirect(array $params = []): never
{
    header(
        'Location: ' . app_url($params),
        true,
        303
    );
    exit;
}

function data_file(string $name): string
{
    global $DATA_DIR;

    return $DATA_DIR . DIRECTORY_SEPARATOR . $name;
}

/* ============================================================
 * JSON persistence
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
 * Encryption
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
        $value = @file_get_contents($file);

        if (is_string($value) && strlen($value) >= 32) {
            return hash('sha256', $value, true);
        }
    }

    $value = bin2hex(random_bytes(32));

    if (@file_put_contents($file, $value, LOCK_EX) === false) {
        throw new RuntimeException('暗号化キーを保存できません。');
    }

    @chmod($file, 0600);

    return hash('sha256', $value, true);
}

function encrypt_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    $key = encryption_key();
    $iv = random_bytes(16);

    $cipher = openssl_encrypt(
        $plain,
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($cipher === false) {
        throw new RuntimeException('秘密情報を暗号化できません。');
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
 * Data
 * ============================================================ */

function load_surveys(): array
{
    $v = json_read(data_file('surveys.json'), []);

    return is_array($v) ? $v : [];
}

function save_surveys(array $v): bool
{
    return json_write(
        data_file('surveys.json'),
        array_values($v)
    );
}

function load_customers(): array
{
    $v = json_read(data_file('customers.json'), []);

    return is_array($v) ? $v : [];
}

function save_customers(array $v): bool
{
    return json_write(
        data_file('customers.json'),
        array_values($v)
    );
}

function load_answers(): array
{
    $v = json_read(data_file('answers.json'), []);

    return is_array($v) ? $v : [];
}

function save_answers(array $v): bool
{
    return json_write(
        data_file('answers.json'),
        array_values($v)
    );
}

function load_history(): array
{
    $v = json_read(data_file('send_history.json'), []);

    return is_array($v) ? $v : [];
}

function save_history(array $v): bool
{
    return json_write(
        data_file('send_history.json'),
        array_values($v)
    );
}

function load_kintone(): array
{
    $v = json_read(data_file('kintone.json'), []);

    if (!is_array($v)) {
        $v = [];
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
    ], $v);
}

function save_kintone(array $v): bool
{
    return json_write(data_file('kintone.json'), $v);
}

function load_mail(): array
{
    $v = json_read(data_file('mail.json'), []);

    if (!is_array($v)) {
        $v = [];
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
    ], $v);
}

function save_mail(array $v): bool
{
    return json_write(data_file('mail.json'), $v);
}

/* ============================================================
 * Survey model
 * ============================================================ */

function new_question(): array
{
    return [
        'id' => new_id('q'),
        'number' => '',
        'text' => '',
        'type' => 'single',
        'required' => true,
        'options' => [
            [
                'id' => new_id('opt'),
                'label' => '選択肢1',
                'nextQuestionId' => '',
            ],
            [
                'id' => new_id('opt'),
                'label' => '選択肢2',
                'nextQuestionId' => '',
            ],
        ],
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
        'createdAt' => now_text(),
        'updatedAt' => now_text(),
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

            $questionNo++;
            $global++;
        }

        unset($question);
        $groupNo++;
    }

    unset($group);
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
    $i = survey_index($surveys, $id);

    return $i >= 0 ? $surveys[$i] : null;
}

function refresh_survey_status(array &$survey): bool
{
    /*
     * 自動終了はこの条件だけ。
     *
     * published + endAt経過 => ended
     *
     * draft / stopped は絶対に変更しない。
     */
    if (
        ($survey['status'] ?? 'draft') === 'published'
        && !empty($survey['endAt'])
    ) {
        $timestamp = strtotime((string)$survey['endAt']);

        if ($timestamp !== false && $timestamp < time()) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = now_text();
            return true;
        }
    }

    return false;
}

function refresh_all_statuses(array &$surveys): bool
{
    $changed = false;

    foreach ($surveys as &$survey) {
        if (refresh_survey_status($survey)) {
            $changed = true;
        }
    }

    unset($survey);

    return $changed;
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
        'published' => 'success',
        'stopped' => 'warning',
        'ended' => 'gray',
        default => 'draft',
    };
}

/* ============================================================
 * Validation
 * ============================================================ */

function validate_survey_input(): array
{
    $errors = [];

    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $startAt = trim((string)($_POST['startAt'] ?? ''));
    $endAt = trim((string)($_POST['endAt'] ?? ''));
    $numbering = post_string('numbering');

    if ($title === '') {
        $errors[] = 'アンケートタイトルを入力してください。';
    }

    if (mb_strlen($title) > 200) {
        $errors[] = 'アンケートタイトルは200文字以内です。';
    }

    if (mb_strlen($description) > 5000) {
        $errors[] = 'アンケート説明は5000文字以内です。';
    }

    if (!in_array($numbering, ['global', 'group'], true)) {
        $numbering = 'global';
    }

    if ($startAt !== '') {
        if (strtotime($startAt) === false) {
            $errors[] = '開始日時が不正です。';
        }
    }

    if ($endAt !== '') {
        if (strtotime($endAt) === false) {
            $errors[] = '終了日時が不正です。';
        }
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && strtotime($startAt) !== false
        && strtotime($endAt) !== false
        && strtotime($endAt) <= strtotime($startAt)
    ) {
        $errors[] = '終了日時は開始日時より後にしてください。';
    }

    return [
        'errors' => $errors,
        'title' => mb_substr($title, 0, 200),
        'description' => mb_substr($description, 0, 5000),
        'startAt' => $startAt,
        'endAt' => $endAt,
        'numbering' => $numbering,
    ];
}

/* ============================================================
 * Kintone
 * ============================================================ */

function normalize_subdomain(string $v): string
{
    $v = trim($v);

    $v = preg_replace(
        '#^https?://#i',
        '',
        $v
    );

    $v = trim((string)$v, '/');

    $v = preg_replace(
        '/\.cybozu\.com.*$/i',
        '',
        $v
    );

    return trim((string)$v);
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

    if (
        !preg_match(
            '/^([^:\/\s]+):([0-9]{1,5})$/',
            $proxy,
            $m
        )
    ) {
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

function validate_kintone(array $c): array
{
    $errors = [];

    $subdomain = normalize_subdomain(
        (string)($c['subdomain'] ?? '')
    );

    $appId = trim((string)($c['app_id'] ?? ''));
    $username = trim((string)($c['username'] ?? ''));
    $proxy = trim((string)($c['proxy'] ?? ''));

    if (
        !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]{0,62}$/',
            $subdomain
        )
    ) {
        $errors[] = 'サブドメインが不正です。';
    }

    if (
        $appId === ''
        || !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        $errors[] = '顧客管理アプリIDが不正です。';
    }

    if ($username === '') {
        $errors[] = 'ログイン名を入力してください。';
    }

    if (
        $proxy !== ''
        && parse_proxy($proxy) === null
    ) {
        $errors[] = 'Proxyはhost:port形式で入力してください。';
    }

    return [
        'errors' => $errors,
        'subdomain' => $subdomain,
        'app_id' => $appId,
        'username' => $username,
        'proxy' => $proxy,
    ];
}

function kintone_password(array $c): string
{
    if (!empty($c['password_encrypted'])) {
        $v = decrypt_secret(
            (string)$c['password_encrypted']
        );

        if ($v !== '') {
            return $v;
        }
    }

    return '';
}

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $v = validate_kintone($config);

    if ($v['errors']) {
        return [
            'ok' => false,
            'status' => 0,
            'code' => '',
            'id' => '',
            'message' => implode(' ', $v['errors']),
            'data' => null,
        ];
    }

    $password = kintone_password($config);

    if ($password === '') {
        return [
            'ok' => false,
            'status' => 0,
            'code' => '',
            'id' => '',
            'message' => 'kintoneパスワードが設定されていません。',
            'data' => null,
        ];
    }

    $host = $v['subdomain'] . '.cybozu.com';

    $url = 'https://' . $host . $path;

    $headers = [
        'X-Cybozu-Authorization: '
            . base64_encode(
                $v['username'] . ':' . $password
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
                'status' => 0,
                'code' => '',
                'id' => '',
                'message' => 'JSONを生成できません。',
                'data' => null,
            ];
        }

        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($content);
    }

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

    $proxy = parse_proxy(
        (string)($config['proxy'] ?? '')
    );

    if ($proxy !== null) {
        $http['proxy'] =
            'tcp://'
            . $proxy['host']
            . ':'
            . $proxy['port'];

        $http['request_fulluri'] = true;
    }

    $verify = !empty($config['verify_ssl']);

    $context = stream_context_create([
        'http' => $http,
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
        ],
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

    try {
        $response = file_get_contents(
            $url,
            false,
            $context
        );
    } finally {
        restore_error_handler();
    }

    $status = 0;

    foreach (($http_response_header ?? []) as $header) {
        if (
            preg_match(
                '#^HTTP/\S+\s+([0-9]{3})#i',
                $header,
                $m
            )
        ) {
            $status = (int)$m[1];
            break;
        }
    }

    if ($response === false) {
        return [
            'ok' => false,
            'status' => $status,
            'code' => '',
            'id' => '',
            'message' =>
                $error !== ''
                ? $error
                : 'kintoneへの通信に失敗しました。',
            'data' => null,
        ];
    }

    $json = json_decode($response, true);

    if (!is_array($json)) {
        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'code' => '',
            'id' => '',
            'message' =>
                $status >= 200 && $status < 300
                ? ''
                : 'kintoneからJSON以外の応答が返されました。',
            'data' => null,
        ];
    }

    $ok = $status >= 200 && $status < 300;

    return [
        'ok' => $ok,
        'status' => $status,
        'code' => (string)($json['code'] ?? ''),
        'id' => (string)($json['id'] ?? ''),
        'message' => (string)(
            $json['message']
            ?? ($ok ? '' : 'kintone APIエラー')
        ),
        'data' => $json,
    ];
}

function kintone_fields(array $config): array
{
    $appId = (int)$config['app_id'];

    return kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app='
        . rawurlencode((string)$appId)
    );
}

function kintone_test(array $config): array
{
    $appId = (int)$config['app_id'];

    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?id='
        . rawurlencode((string)$appId)
    );
}

function kintone_records(
    array $config,
    int $appId
): array {
    $all = [];
    $offset = 0;

    do {
        $path =
            '/k/v1/records.json?app='
            . rawurlencode((string)$appId)
            . '&query='
            . rawurlencode('limit 500 offset ' . $offset);

        $result = kintone_request(
            $config,
            'GET',
            $path
        );

        if (!$result['ok']) {
            return $result;
        }

        $records = $result['data']['records'] ?? [];

        if (!is_array($records)) {
            $records = [];
        }

        foreach ($records as $record) {
            $all[] = $record;
        }

        $count = count($records);
        $offset += $count;
    } while ($count === 500);

    return [
        'ok' => true,
        'status' => 200,
        'code' => '',
        'id' => '',
        'message' => '',
        'data' => [
            'records' => $all,
        ],
    ];
}

function kintone_value(array $record, string $code): string
{
    if ($code === '') {
        return '';
    }

    $field = $record[$code] ?? null;

    if (!is_array($field)) {
        return '';
    }

    $value = $field['value'] ?? '';

    if (is_array($value)) {
        $values = [];

        foreach ($value as $v) {
            if (is_array($v)) {
                $values[] = (string)($v['name'] ?? $v['code'] ?? '');
            } else {
                $values[] = (string)$v;
            }
        }

        return implode(' ', array_filter($values));
    }

    return trim((string)$value);
}

/* ============================================================
 * SMTP
 * ============================================================ */

function smtp_config_valid(array $c): array
{
    $errors = [];

    $server = trim((string)($c['server'] ?? ''));
    $port = (int)($c['port'] ?? 0);
    $encryption = (string)($c['encryption'] ?? 'tls');

    if ($server === '') {
        $errors[] = 'SMTPサーバを入力してください。';
    }

    if ($port < 1 || $port > 65535) {
        $errors[] = 'SMTPポートが不正です。';
    }

    if (!in_array(
        $encryption,
        ['ssl', 'tls', 'none'],
        true
    )) {
        $errors[] = '暗号化方式が不正です。';
    }

    if (
        ($c['from_email'] ?? '') === ''
        || !filter_var(
            $c['from_email'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] = '送信元メールアドレスが不正です。';
    }

    return $errors;
}

function smtp_connect(array $c)
{
    $errors = smtp_config_valid($c);

    if ($errors) {
        throw new RuntimeException(
            implode(' ', $errors)
        );
    }

    $server = trim((string)$c['server']);
    $port = (int)$c['port'];
    $encryption = (string)$c['encryption'];

    if ($encryption === 'ssl') {
        $target = 'ssl://' . $server . ':' . $port;
    } else {
        $target = 'tcp://' . $server . ':' . $port;
    }

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $target,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!is_resource($socket)) {
        throw new RuntimeException(
            'SMTP接続失敗: ' . $errstr
        );
    }

    stream_set_timeout($socket, 20);

    smtp_expect($socket, [220]);

    smtp_command(
        $socket,
        'EHLO localhost',
        [250]
    );

    if ($encryption === 'tls') {
        smtp_command(
            $socket,
            'STARTTLS',
            [220]
        );

        $crypto = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);
            throw new RuntimeException(
                'STARTTLSを確立できません。'
            );
        }

        smtp_command(
            $socket,
            'EHLO localhost',
            [250]
        );
    }

    if (!empty($c['auth'])) {
        $username = (string)($c['username'] ?? '');
        $password = '';

        if (!empty($c['password_encrypted'])) {
            $password = decrypt_secret(
                (string)$c['password_encrypted']
            );
        }

        if ($username === '' || $password === '') {
            fclose($socket);

            throw new RuntimeException(
                'SMTP認証情報が設定されていません。'
            );
        }

        smtp_command(
            $socket,
            'AUTH LOGIN',
            [334]
        );

        smtp_command(
            $socket,
            base64_encode($username),
            [334]
        );

        smtp_command(
            $socket,
            base64_encode($password),
            [235]
        );
    }

    return $socket;
}

function smtp_read($socket): array
{
    $lines = [];

    while (($line = fgets($socket)) !== false) {
        $lines[] = rtrim($line, "\r\n");

        if (
            strlen($line) >= 4
            && $line[3] === ' '
        ) {
            break;
        }
    }

    return $lines;
}

function smtp_expect($socket, array $codes): string
{
    $lines = smtp_read($socket);

    $last = end($lines);

    if (!is_string($last)) {
        throw new RuntimeException(
            'SMTP応答を取得できません。'
        );
    }

    $code = (int)substr($last, 0, 3);

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' . $last
        );
    }

    return implode("\n", $lines);
}

function smtp_command(
    $socket,
    string $command,
    array $codes
): string {
    fwrite($socket, $command . "\r\n");

    return smtp_expect($socket, $codes);
}

function smtp_send(
    array $c,
    string $to,
    string $subject,
    string $body
): void {
    if (
        !filter_var(
            $to,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new RuntimeException(
            '送信先メールアドレスが不正です。'
        );
    }

    $socket = smtp_connect($c);

    try {
        $from = (string)$c['from_email'];

        smtp_command(
            $socket,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        smtp_command(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        smtp_command(
            $socket,
            'DATA',
            [354]
        );

        $fromName = trim((string)(
            $c['from_name'] ?? ''
        ));

        $fromHeader = $from;

        if ($fromName !== '') {
            $fromHeader =
                mb_encode_mimeheader(
                    $fromName
                )
                . ' <'
                . $from
                . '>';
        }

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $fromHeader,
            'To: <' . $to . '>',
            'Subject: ' . mb_encode_mimeheader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if (!empty($c['reply_to'])) {
            $headers[] =
                'Reply-To: ' . $c['reply_to'];
        }

        $message =
            implode("\r\n", $headers)
            . "\r\n\r\n"
            . str_replace(
                "\n.",
                "\n..",
                str_replace(
                    "\r\n",
                    "\n",
                    $body
                )
            )
            . "\r\n.";

        smtp_command(
            $socket,
            $message,
            [250]
        );

        smtp_command(
            $socket,
            'QUIT',
            [221]
        );
    } finally {
        fclose($socket);
    }
}

/* ============================================================
 * POST handling
 * ============================================================ */

function handle_post(
    array &$surveys,
    array &$customers,
    array &$answers,
    array &$history,
    array &$kintone,
    array &$mail
): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = post_string('action');

    try {
        switch ($action) {

            /* ==================================================
             * Survey save
             *
             * 状態変更とは完全に別処理。
             * 保存時にstatusをPOSTから受け取らない。
             * ================================================== */

            case 'save_survey':
                $input = validate_survey_input();

                if ($input['errors']) {
                    flash(
                        'error',
                        implode("\n", $input['errors'])
                    );
                    return;
                }

                $id = post_string('survey_id');
                $index = survey_index($surveys, $id);

                if ($index < 0) {
                    $survey = new_survey();

                    $survey['id'] =
                        $id !== '' && valid_id($id)
                        ? $id
                        : new_id('survey');

                    $survey['title'] =
                        $input['title'];

                    $survey['description'] =
                        $input['description'];

                    $survey['startAt'] =
                        $input['startAt'];

                    $survey['endAt'] =
                        $input['endAt'];

                    $survey['numbering'] =
                        $input['numbering'];

                    $survey['status'] = 'draft';
                } else {
                    $survey = $surveys[$index];

                    /*
                     * 現在の状態を維持する。
                     * ここでは絶対に状態を変更しない。
                     */
                    $currentStatus =
                        (string)($survey['status'] ?? 'draft');

                    if (!in_array(
                        $currentStatus,
                        [
                            'draft',
                            'published',
                            'stopped',
                            'ended',
                        ],
                        true
                    )) {
                        $currentStatus = 'draft';
                    }

                    $survey['status'] = $currentStatus;

                    $survey['title'] =
                        $input['title'];

                    $survey['description'] =
                        $input['description'];

                    $survey['startAt'] =
                        $input['startAt'];

                    $survey['endAt'] =
                        $input['endAt'];

                    $survey['numbering'] =
                        $input['numbering'];

                    $survey['updatedAt'] =
                        now_text();
                }

                /*
                 * DOMの順番を唯一の正とする。
                 * group_order[] -> questions_by_group[] の順で
                 * サーバー側モデルを再構成する。
                 */
                $groupOrder = $_POST['group_order'] ?? [];
                $groupTitles = $_POST['group_title'] ?? [];
                $questionTexts = $_POST['question_text'] ?? [];
                $questionTypes = $_POST['question_type'] ?? [];
                $questionRequired =
                    $_POST['question_required'] ?? [];
                $questionOptions =
                    $_POST['question_option'] ?? [];
                $optionNext =
                    $_POST['option_next'] ?? [];

                if (!is_array($groupOrder)) {
                    $groupOrder = [];
                }

                if (!$groupOrder) {
                    flash(
                        'error',
                        'グループがありません。'
                    );
                    return;
                }

                $newGroups = [];

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
                        'title' =>
                            mb_substr($title, 0, 200),
                        'questions' => [],
                    ];

                    $questionIds =
                        $_POST['questions_by_group'][$groupId]
                        ?? [];

                    if (!is_array($questionIds)) {
                        $questionIds = [];
                    }

                    foreach ($questionIds as $qid) {
                        $qid = trim((string)$qid);

                        if ($qid === '') {
                            continue;
                        }

                        $type =
                            is_array($questionTypes)
                            ? (string)(
                                $questionTypes[$qid]
                                ?? 'single'
                            )
                            : 'single';

                        if (!in_array(
                            $type,
                            ['single', 'multiple', 'text'],
                            true
                        )) {
                            $type = 'single';
                        }

                        $text =
                            is_array($questionTexts)
                            ? trim(
                                (string)(
                                    $questionTexts[$qid]
                                    ?? ''
                                )
                            )
                            : '';

                        $required =
                            is_array($questionRequired)
                            && isset(
                                $questionRequired[$qid]
                            );

                        $question = [
                            'id' => $qid,
                            'number' => '',
                            'text' =>
                                mb_substr(
                                    $text,
                                    0,
                                    1000
                                ),
                            'type' => $type,
                            'required' => $required,
                            'options' => [],
                        ];

                        if (
                            $type === 'single'
                            || $type === 'multiple'
                        ) {
                            $raw =
                                is_array($questionOptions)
                                ? (
                                    $questionOptions[$qid]
                                    ?? []
                                )
                                : [];

                            if (!is_array($raw)) {
                                $raw = [];
                            }

                            foreach (
                                $raw as $optionIndex => $label
                            ) {
                                $label =
                                    trim((string)$label);

                                if ($label === '') {
                                    continue;
                                }

                                $next = '';

                                if (
                                    $type === 'single'
                                    && is_array($optionNext)
                                    && isset(
                                        $optionNext[$qid]
                                    )
                                    && is_array(
                                        $optionNext[$qid]
                                    )
                                ) {
                                    $next =
                                        (string)(
                                            $optionNext[$qid]
                                                [$optionIndex]
                                            ?? ''
                                        );
                                }

                                $question['options'][] = [
                                    'id' =>
                                        'opt-'
                                        . $qid
                                        . '-'
                                        . $optionIndex,
                                    'label' =>
                                        mb_substr(
                                            $label,
                                            0,
                                            500
                                        ),
                                    'nextQuestionId' =>
                                        $next,
                                ];
                            }
                        }

                        $group['questions'][] =
                            $question;
                    }

                    if (!$group['questions']) {
                        $group['questions'][] =
                            new_question();
                    }

                    $newGroups[] = $group;
                }

                if (!$newGroups) {
                    flash(
                        'error',
                        '少なくとも1つのグループが必要です。'
                    );
                    return;
                }

                $survey['groups'] = $newGroups;

                recalc_numbers($survey);

                /*
                 * 条件分岐先が存在しない場合はクリア。
                 */
                $validQuestionIds = [];

                foreach ($survey['groups'] as $g) {
                    foreach ($g['questions'] as $q) {
                        $validQuestionIds[] =
                            $q['id'];
                    }
                }

                foreach (
                    $survey['groups'] as &$g
                ) {
                    foreach (
                        $g['questions'] as &$q
                    ) {
                        foreach (
                            $q['options'] as &$o
                        ) {
                            if (
                                $o['nextQuestionId'] !== ''
                                && !in_array(
                                    $o['nextQuestionId'],
                                    $validQuestionIds,
                                    true
                                )
                            ) {
                                $o['nextQuestionId'] = '';
                            }
                        }

                        unset($o);
                    }

                    unset($q);
                }

                unset($g);

                if ($index < 0) {
                    $surveys[] = $survey;
                } else {
                    $surveys[$index] = $survey;
                }

                if (!save_surveys($surveys)) {
                    flash(
                        'error',
                        'アンケートを保存できませんでした。'
                    );
                    return;
                }

                flash(
                    'success',
                    'アンケートを保存しました。'
                );

                redirect(['screen' => 'list']);

            /* ==================================================
             * Status change
             *
             * save_surveyとは別form。
             * ================================================== */

            case 'change_status':
                $id = post_string('survey_id');
                $next = post_string('next_status');

                $index = survey_index(
                    $surveys,
                    $id
                );

                if ($index < 0) {
                    flash(
                        'error',
                        '対象アンケートが見つかりません。'
                    );
                    return;
                }

                $current =
                    (string)(
                        $surveys[$index]['status']
                        ?? 'draft'
                    );

                $allowed = [
                    'draft' => 'published',
                    'published' => 'stopped',
                    'stopped' => 'published',
                ];

                if (
                    $current === 'ended'
                    || !isset($allowed[$current])
                    || $allowed[$current] !== $next
                ) {
                    flash(
                        'error',
                        '許可されていない状態変更です。'
                    );
                    return;
                }

                $surveys[$index]['status'] =
                    $next;

                $surveys[$index]['updatedAt'] =
                    now_text();

                if (!save_surveys($surveys)) {
                    flash(
                        'error',
                        '状態を保存できませんでした。'
                    );
                    return;
                }

                flash(
                    'success',
                    '状態を「'
                    . status_label($next)
                    . '」へ変更しました。'
                );

                redirect([
                    'screen' => 'edit',
                    'id' => $id,
                ]);

            /* ==================================================
             * Duplicate
             * ================================================== */

            case 'duplicate_survey':
                $id = post_string('survey_id');
                $source = survey_by_id(
                    $surveys,
                    $id
                );

                if ($source === null) {
                    flash(
                        'error',
                        '対象アンケートが見つかりません。'
                    );
                    return;
                }

                $copy = $source;

                $copy['id'] =
                    new_id('survey');

                $copy['title'] =
                    mb_substr(
                        $source['title']
                        . '（コピー）',
                        0,
                        200
                    );

                $copy['status'] = 'draft';
                $copy['createdAt'] = now_text();
                $copy['updatedAt'] = now_text();

                /*
                 * 質問・グループIDを再生成。
                 */
                foreach (
                    $copy['groups'] as &$group
                ) {
                    $oldGroupId = $group['id'];
                    $group['id'] =
                        new_id('g');

                    foreach (
                        $group['questions'] as &$q
                    ) {
                        $oldQuestionId = $q['id'];
                        $q['id'] =
                            new_id('q');

                        foreach (
                            $q['options'] as &$o
                        ) {
                            $o['id'] =
                                new_id('opt');

                            $o['nextQuestionId'] = '';
                        }

                        unset($o);
                    }

                    unset($q);
                }

                unset($group);

                recalc_numbers($copy);

                $surveys[] = $copy;

                if (!save_surveys($surveys)) {
                    flash(
                        'error',
                        '複製できませんでした。'
                    );
                    return;
                }

                flash(
                    'success',
                    'アンケートを複製しました。'
                );

                redirect(['screen' => 'list']);

            /* ==================================================
             * Delete
             * ================================================== */

            case 'delete_survey':
                $id = post_string('survey_id');
                $index = survey_index(
                    $surveys,
                    $id
                );

                if ($index < 0) {
                    flash(
                        'error',
                        '対象アンケートが見つかりません。'
                    );
                    return;
                }

                array_splice(
                    $surveys,
                    $index,
                    1
                );

                if (!save_surveys($surveys)) {
                    flash(
                        'error',
                        '削除できませんでした。'
                    );
                    return;
                }

                flash(
                    'success',
                    'アンケートを削除しました。'
                );

                redirect(['screen' => 'list']);

            /* ==================================================
             * Kintone save
             * ================================================== */

            case 'save_kintone':
                $current = $kintone;

                $subdomain =
                    normalize_subdomain(
                        post_string('subdomain')
                    );

                $appId =
                    post_string('app_id');

                $username =
                    post_string('username');

                $proxy =
                    post_string('proxy');

                $password =
                    post_string('password');

                $verify =
                    isset($_POST['verify_ssl']);

                $config = [
                    'subdomain' => $subdomain,
                    'app_id' => $appId,
                    'username' => $username,
                    'proxy' => $proxy,
                    'verify_ssl' => $verify,
                    'password_encrypted' =>
                        $current['password_encrypted']
                        ?? '',
                    'fields' =>
                        $current['fields']
                        ?? [],
                    'mapping' =>
                        $current['mapping']
                        ?? [],
                    'status' =>
                        $current['status']
                        ?? '未設定',
                    'last_test' =>
                        $current['last_test']
                        ?? '',
                    'last_sync' =>
                        $current['last_sync']
                        ?? '',
                ];

                if ($password !== '') {
                    $config['password_encrypted'] =
                        encrypt_secret($password);
                }

                $v = validate_kintone($config);

                if ($v['errors']) {
                    flash(
                        'error',
                        implode(
                            "\n",
                            $v['errors']
                        )
                    );
                    return;
                }

                $kintone = $config;

                if (!save_kintone($kintone)) {
                    flash(
                        'error',
                        'kintone設定を保存できませんでした。'
                    );
                    return;
                }

                flash(
                    'success',
                    'kintone設定を保存しました。'
                );

                redirect([
                    'screen' => 'kintone',
                ]);

            /* ==================================================
             * Kintone test
             * ================================================== */

            case 'test_kintone':
                $password =
                    post_string('password');

                if ($password !== '') {
                    $kintone['password_encrypted'] =
                        encrypt_secret($password);

                    save_kintone($kintone);
                }

                $result =
                    kintone_test($kintone);

                if ($result['ok']) {
                    $kintone['status'] =
                        '接続確認済み';

                    $kintone['last_test'] =
                        now_text();

                    save_kintone($kintone);

                    flash(
                        'success',
                        'kintone接続に成功しました。'
                    );
                } else {
                    $detail =
                        'HTTP '
                        . $result['status'];

                    if ($result['code'] !== '') {
                        $detail .=
                            ' / エラーコード: '
                            . $result['code'];
                    }

                    if ($result['id'] !== '') {
                        $detail .=
                            ' / エラーID: '
                            . $result['id'];
                    }

                    if ($result['message'] !== '') {
                        $detail .=
                            ' / '
                            . $result['message'];
                    }

                    $kintone['status'] =
                        '接続できません';

                    save_kintone($kintone);

                    flash(
                        'error',
                        'kintone接続に失敗しました。'
                        . "\n"
                        . $detail
                    );
                }

                return;

            /* ==================================================
             * Kintone fields
             * ================================================== */

            case 'fetch_kintone_fields':
                $result =
                    kintone_fields($kintone);

                if (!$result['ok']) {
                    flash(
                        'error',
                        '項目一覧取得に失敗しました。'
                        . "\nHTTP "
                        . $result['status']
                        . ' / '
                        . $result['code']
                        . ' / '
                        . $result['message']
                    );
                    return;
                }

                $rawFields =
                    $result['data']['properties']
                    ?? [];

                $fields = [];

                if (is_array($rawFields)) {
                    foreach (
                        $rawFields as $code => $field
                    ) {
                        if (!is_array($field)) {
                            continue;
                        }

                        $fields[] = [
                            'code' =>
                                (string)$code,
                            'label' =>
                                (string)(
                                    $field['label']
                                    ?? $code
                                ),
                            'type' =>
                                (string)(
                                    $field['type']
                                    ?? ''
                                ),
                        ];
                    }
                }

                $kintone['fields'] = $fields;

                save_kintone($kintone);

                flash(
                    'success',
                    count($fields)
                    . '件の項目を取得しました。'
                );

                return;

            /* ==================================================
             * Kintone mapping
             * ================================================== */

            case 'save_kintone_mapping':
                $fields =
                    is_array($kintone['fields'] ?? [])
                    ? $kintone['fields']
                    : [];

                $validCodes = [];

                foreach ($fields as $field) {
                    if (is_array($field)) {
                        $validCodes[] =
                            (string)(
                                $field['code'] ?? ''
                            );
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

                $addresses =
                    $_POST['mapping_address']
                    ?? [];

                if (is_array($addresses)) {
                    foreach ($addresses as $code) {
                        $code = trim((string)$code);

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
                        'phone',
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

                $kintone['mapping'] =
                    $mapping;

                save_kintone($kintone);

                flash(
                    'success',
                    'kintone項目マッピングを保存しました。'
                );

                return;

            /* ==================================================
             * Kintone sync
             * ================================================== */

            case 'sync_kintone':
                $result = kintone_records(
                    $kintone,
                    (int)$kintone['app_id']
                );

                if (!$result['ok']) {
                    flash(
                        'error',
                        '顧客情報の同期に失敗しました。'
                        . "\nHTTP "
                        . $result['status']
                        . ' / '
                        . $result['code']
                        . ' / '
                        . $result['message']
                    );
                    return;
                }

                $records =
                    $result['data']['records']
                    ?? [];

                $mapping =
                    $kintone['mapping']
                    ?? [];

                $customers = [];

                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }

                    $name = kintone_value(
                        $record,
                        (string)(
                            $mapping['name'] ?? ''
                        )
                    );

                    $email = kintone_value(
                        $record,
                        (string)(
                            $mapping['email'] ?? ''
                        )
                    );

                    $addressParts = [];

                    foreach (
                        ($mapping['address'] ?? [])
                        as $code
                    ) {
                        $v = kintone_value(
                            $record,
                            (string)$code
                        );

                        if ($v !== '') {
                            $addressParts[] = $v;
                        }
                    }

                    $organization =
                        kintone_value(
                            $record,
                            (string)(
                                $mapping['organization']
                                ?? ''
                            )
                        );

                    $department =
                        kintone_value(
                            $record,
                            (string)(
                                $mapping['department']
                                ?? ''
                            )
                        );

                    $phone =
                        kintone_value(
                            $record,
                            (string)(
                                $mapping['phone']
                                ?? ''
                            )
                        );

                    $sourceId =
                        kintone_value(
                            $record,
                            '$id'
                        );

                    if ($sourceId === '') {
                        $sourceId =
                            new_id('customer');
                    }

                    $customers[] = [
                        'id' =>
                            'kintone-' . $sourceId,
                        'name' => $name,
                        'email' => $email,
                        'organization' =>
                            $organization,
                        'department' =>
                            $department,
                        'phone' => $phone,
                        'address' =>
                            implode(
                                ' ',
                                $addressParts
                            ),
                    ];
                }

                if (!save_customers($customers)) {
                    flash(
                        'error',
                        '顧客情報を保存できませんでした。'
                    );
                    return;
                }

                $kintone['last_sync'] =
                    now_text();

                save_kintone($kintone);

                flash(
                    'success',
                    count($customers)
                    . '件の顧客情報を同期しました。'
                );

                return;

            /* ==================================================
             * Mail save
             * ================================================== */

            case 'save_mail':
                $password =
                    post_string('password');

                $mail = [
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
                    'password_encrypted' =>
                        $mail['password_encrypted']
                        ?? '',
                    'from_email' =>
                        post_string('from_email'),
                    'from_name' =>
                        post_string('from_name'),
                    'reply_to' =>
                        post_string('reply_to'),
                    'status' =>
                        $mail['status']
                        ?? '未設定',
                    'last_test' =>
                        $mail['last_test']
                        ?? '',
                ];

                if ($password !== '') {
                    $mail['password_encrypted'] =
                        encrypt_secret($password);
                }

                $errors =
                    smtp_config_valid($mail);

                if ($errors) {
                    flash(
                        'error',
                        implode("\n", $errors)
                    );
                    return;
                }

                $mail['status'] =
                    '未設定';

                if (!save_mail($mail)) {
                    flash(
                        'error',
                        'SMTP設定を保存できませんでした。'
                    );
                    return;
                }

                flash(
                    'success',
                    'SMTP設定を保存しました。'
                );

                redirect([
                    'screen' => 'mail',
                ]);

            /* ==================================================
             * Mail test
             * ================================================== */

            case 'test_mail':
                $password =
                    post_string('password');

                if ($password !== '') {
                    $mail['password_encrypted'] =
                        encrypt_secret($password);

                    save_mail($mail);
                }

                try {
                    smtp_test_connection($mail);

                    $mail['status'] =
                        '接続確認済み';

                    $mail['last_test'] =
                        now_text();

                    save_mail($mail);

                    flash(
                        'success',
                        'SMTP接続・認証に成功しました。'
                    );
                } catch (Throwable $e) {
                    $mail['status'] =
                        '接続できません';

                    save_mail($mail);

                    flash(
                        'error',
                        'SMTP接続失敗：'
                        . $e->getMessage()
                    );
                }

                return;

            /* ==================================================
             * Test mail
             * ================================================== */

            case 'send_test_mail':
                $to = post_string('test_email');

                if (
                    !filter_var(
                        $to,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    flash(
                        'error',
                        'テスト送信先メールアドレスが不正です。'
                    );
                    return;
                }

                try {
                    smtp_send(
                        $mail,
                        $to,
                        'アンケートアプリ テストメール',
                        "SMTP設定のテストメールです。\n"
                        . now_text()
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

                return;

            /* ==================================================
             * Survey mail
             * ================================================== */

            case 'send_mail':
                $surveyId =
                    post_string('survey_id');

                $survey =
                    survey_by_id(
                        $surveys,
                        $surveyId
                    );

                if ($survey === null) {
                    flash(
                        'error',
                        '対象アンケートが見つかりません。'
                    );
                    return;
                }

                $ids =
                    $_POST['customer_ids']
                    ?? [];

                if (!is_array($ids) || !$ids) {
                    flash(
                        'error',
                        '顧客を選択してください。'
                    );
                    return;
                }

                $subject =
                    post_string('subject');

                $body =
                    (string)(
                        $_POST['body']
                        ?? ''
                    );

                if (
                    $subject === ''
                    || trim($body) === ''
                ) {
                    flash(
                        'error',
                        'メール件名と本文を入力してください。'
                    );
                    return;
                }

                $map = [];

                foreach ($customers as $customer) {
                    $map[
                        (string)(
                            $customer['id'] ?? ''
                        )
                    ] = $customer;
                }

                $success = 0;
                $failed = 0;

                /*
                 * ここが重要。
                 * URIだけではなく完全なURLを生成する。
                 */
                $surveyUrl =
                    public_app_url([
                        'screen' => 'answer',
                        'id' => $surveyId,
                    ]);

                foreach ($ids as $customerId) {
                    $customer =
                        $map[(string)$customerId]
                        ?? null;

                    if ($customer === null) {
                        $failed++;
                        continue;
                    }

                    $to =
                        (string)(
                            $customer['email']
                            ?? ''
                        );

                    if (
                        !filter_var(
                            $to,
                            FILTER_VALIDATE_EMAIL
                        )
                    ) {
                        $failed++;

                        $history[] = [
                            'id' =>
                                new_id('send'),
                            'survey_id' =>
                                $surveyId,
                            'customer_id' =>
                                $customerId,
                            'customer_name' =>
                                (string)(
                                    $customer['name']
                                    ?? ''
                                ),
                            'type' => '一括送信',
                            'result' =>
                                '送信失敗',
                            'createdAt' =>
                                now_text(),
                        ];

                        continue;
                    }

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
                                $surveyUrl,
                            ],
                            $body
                        );

                    try {
                        smtp_send(
                            $mail,
                            $to,
                            $subject,
                            $mailBody
                        );

                        $result =
                            '送信成功';

                        $success++;
                    } catch (Throwable $e) {
                        $result =
                            '送信失敗';

                        $failed++;
                    }

                    $history[] = [
                        'id' =>
                            new_id('send'),
                        'survey_id' =>
                            $surveyId,
                        'customer_id' =>
                            $customerId,
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
                            now_text(),
                    ];
                }

                save_history($history);

                flash(
                    $failed === 0
                    ? 'success'
                    : 'warning',
                    '送信結果：成功 '
                    . $success
                    . '件 / 失敗 '
                    . $failed
                    . '件'
                );

                /*
                 * 別画面へ遷移しない。
                 */
                return;

            /* ==================================================
             * Answer
             * ================================================== */

            case 'answer_next':
                $surveyId =
                    post_string('survey_id');

                $survey =
                    survey_by_id(
                        $surveys,
                        $surveyId
                    );

                if ($survey === null) {
                    redirect(['screen' => 'list']);
                }

                $draft = [];
                $raw =
                    $_POST['answer']
                    ?? [];

                if (is_array($raw)) {
                    foreach ($raw as $qid => $value) {
                        if (is_array($value)) {
                            $draft[$qid] =
                                array_values(
                                    array_filter(
                                        array_map(
                                            'strval',
                                            $value
                                        ),
                                        static fn($v) =>
                                            trim($v) !== ''
                                    )
                                );
                        } elseif (is_scalar($value)) {
                            $draft[$qid] =
                                trim((string)$value);
                        }
                    }
                }

                /*
                 * 必須チェック。
                 */
                $errors = [];

                foreach (
                    $survey['groups']
                    as $group
                ) {
                    foreach (
                        $group['questions']
                        as $question
                    ) {
                        if (
                            empty(
                                $question['required']
                            )
                        ) {
                            continue;
                        }

                        $value =
                            $draft[
                                $question['id']
                            ] ?? '';

                        $empty =
                            is_array($value)
                            ? count($value) === 0
                            : trim((string)$value) === '';

                        if ($empty) {
                            $errors[] =
                                $question['number']
                                . ' は必須です。';
                        }
                    }
                }

                if ($errors) {
                    $_SESSION[
                        'answer_draft'
                    ] = $draft;

                    flash(
                        'error',
                        implode("\n", $errors)
                    );

                    return;
                }

                $_SESSION[
                    'answer_draft'
                ] = $draft;

                redirect([
                    'screen' => 'confirm',
                    'id' => $surveyId,
                ]);

            /* ==================================================
             * Submit answer
             * ================================================== */

            case 'submit_answer':
                $surveyId =
                    post_string('survey_id');

                $survey =
                    survey_by_id(
                        $surveys,
                        $surveyId
                    );

                if ($survey === null) {
                    redirect(['screen' => 'list']);
                }

                $draft =
                    $_SESSION[
                        'answer_draft'
                    ] ?? [];

                $answers[] = [
                    'id' =>
                        new_id('answer'),
                    'survey_id' =>
                        $surveyId,
                    'createdAt' =>
                        now_text(),
                    'values' =>
                        is_array($draft)
                        ? $draft
                        : [],
                ];

                save_answers($answers);

                unset(
                    $_SESSION[
                        'answer_draft'
                    ]
                );

                redirect([
                    'screen' => 'complete',
                    'id' => $surveyId,
                ]);
        }
    } catch (Throwable $e) {
        /*
         * 機密情報はここへ出さない。
         */
        flash(
            'error',
            '処理に失敗しました：'
            . $e->getMessage()
        );
    }
}

function smtp_test_connection(array $mail): void
{
    $socket = smtp_connect($mail);

    smtp_command(
        $socket,
        'QUIT',
        [221]
    );

    fclose($socket);
}

/* ============================================================
 * HTML
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

*{box-sizing:border-box}

html,body{
    margin:0;
    padding:0;
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

body{min-height:100vh}

a{color:inherit}

.container{
    width:min(1400px,calc(100% - 32px));
    margin:0 auto;
}

.page{
    padding:28px 0 70px;
}

.admin-header{
    background:#0f172a;
    color:#fff;
}

.admin-header-inner{
    width:min(1400px,calc(100% - 32px));
    min-height:64px;
    margin:auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.brand{
    font-size:18px;
    font-weight:800;
}

.nav{
    display:flex;
    flex-wrap:wrap;
    gap:5px;
}

.nav a{
    color:#cbd5e1;
    text-decoration:none;
    padding:9px 12px;
    border-radius:7px;
}

.nav a:hover{
    background:#1e293b;
    color:#fff;
}

.page-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    margin-bottom:22px;
}

.page-title h1{
    margin:0 0 6px;
    font-size:28px;
}

.page-title p{
    margin:0;
    color:var(--gray);
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    margin-bottom:20px;
}

.card-header{
    padding:16px 20px;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
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
    gap:18px;
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
}

label>span,
.field-label{
    display:block;
    font-weight:700;
    margin-bottom:7px;
}

input[type=text],
input[type=email],
input[type=password],
input[type=number],
input[type=search],
input[type=datetime-local],
select,
textarea{
    width:100%;
    border:1px solid var(--border);
    border-radius:8px;
    padding:10px 12px;
    background:#fff;
    color:var(--text);
    font:inherit;
}

textarea{
    min-height:130px;
    resize:vertical;
}

input:focus,
select:focus,
textarea:focus{
    outline:none;
    border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(37,99,235,.12);
}

.check{
    display:flex;
    align-items:center;
    gap:8px;
}

.check input{
    width:18px;
    height:18px;
}

.button-row{
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:8px;
}

.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    border:1px solid transparent;
    border-radius:8px;
    padding:9px 14px;
    font:inherit;
    font-weight:700;
    cursor:pointer;
    text-decoration:none;
    white-space:nowrap;
}

.btn-primary{
    color:#fff;
    background:var(--primary);
}

.btn-primary:hover{
    background:var(--primary-dark);
}

.btn-secondary{
    color:var(--text);
    background:#fff;
    border-color:var(--border);
}

.btn-secondary:hover,
.btn-light:hover{
    background:var(--gray-light);
}

.btn-success{
    color:#fff;
    background:var(--success);
}

.btn-warning{
    color:#fff;
    background:var(--warning);
}

.btn-danger{
    color:#fff;
    background:var(--danger);
}

.btn-light{
    color:var(--text);
    background:#fff;
    border-color:var(--border);
}

.badge{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:4px 9px;
    font-size:12px;
    font-weight:800;
}

.badge-success{
    background:#dcfce7;
    color:#166534;
}

.badge-warning{
    background:#fef3c7;
    color:#92400e;
}

.badge-gray{
    background:#e2e8f0;
    color:#475569;
}

.badge-draft{
    background:#dbeafe;
    color:#1e40af;
}

.alert{
    white-space:pre-line;
    border-radius:9px;
    padding:13px 16px;
    margin-bottom:18px;
    font-weight:600;
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

.alert-info{
    background:#dbeafe;
    color:#1e40af;
    border:1px solid #bfdbfe;
}

.notice{
    background:#eff6ff;
    border:1px solid #bfdbfe;
    border-radius:9px;
    padding:12px 14px;
    color:#1e40af;
    margin-bottom:18px;
}

.table-wrap{
    width:100%;
    overflow-x:auto;
}

table{
    width:100%;
    min-width:900px;
    border-collapse:collapse;
}

th,td{
    padding:11px 12px;
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
    gap:6px;
}

.actions form{
    display:inline;
}

.empty{
    padding:35px 15px;
    text-align:center;
    color:var(--gray);
}

.sticky-actions{
    position:sticky;
    bottom:0;
    z-index:10;
    background:rgba(248,250,252,.94);
    backdrop-filter:blur(8px);
    padding:12px 0;
}

.group-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    margin-bottom:18px;
    box-shadow:var(--shadow);
    transition:.15s ease;
}

.group-card.dragging{
    opacity:.55;
    border-color:var(--primary);
}

.group-card.drag-over{
    border:2px dashed var(--primary);
    background:#eff6ff;
}

.group-head{
    padding:12px 14px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}

.group-title-line{
    display:flex;
    align-items:center;
    gap:8px;
}

.drag-handle{
    cursor:grab;
    color:var(--gray);
    user-select:none;
    font-size:20px;
}

.drag-handle:active{
    cursor:grabbing;
}

.question-card{
    border:1px solid var(--border);
    border-radius:9px;
    margin:14px 0;
    background:#fff;
    transition:.15s ease;
}

.question-card.dragging{
    opacity:.45;
}

.question-card.drag-over{
    border:2px dashed var(--primary);
    background:#eff6ff;
}

.question-head{
    padding:10px 13px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
}

.question-number{
    color:var(--primary);
    font-weight:800;
}

.option-row{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto auto;
    gap:8px;
    margin-bottom:8px;
    align-items:center;
}

.mapping-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:16px;
}

.address-map{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:8px;
    border:1px solid var(--border);
    border-radius:8px;
    padding:12px;
    background:#f8fafc;
}

.preview-question{
    border:1px solid var(--border);
    border-radius:9px;
    padding:15px;
    margin:12px 0;
    background:#fff;
}

.answer-shell{
    width:min(900px,calc(100% - 28px));
    margin:30px auto 60px;
}

.answer-option{
    display:flex;
    align-items:center;
    gap:10px;
    border:1px solid var(--border);
    border-radius:9px;
    padding:13px;
    margin:8px 0;
    cursor:pointer;
}

.answer-option:hover{
    background:#f8fafc;
}

.answer-option input{
    width:20px;
    height:20px;
}

.mobile-only-space{
    display:none;
}

@media(max-width:900px){
    .grid-2,
    .grid-3,
    .mapping-grid{
        grid-template-columns:1fr;
    }

    .admin-header-inner{
        align-items:flex-start;
        flex-direction:column;
        padding:12px 0;
    }

    .page-title{
        align-items:flex-start;
        flex-direction:column;
    }
}

@media(max-width:640px){
    .container{
        width:min(100% - 18px,1400px);
    }

    .page{
        padding:18px 0 50px;
    }

    .page-title h1{
        font-size:23px;
    }

    .card-body{
        padding:15px;
    }

    .group-head{
        align-items:flex-start;
        flex-direction:column;
    }

    .option-row{
        grid-template-columns:1fr;
    }

    .address-map{
        grid-template-columns:1fr;
    }

    .btn{
        min-height:42px;
    }

    .answer-shell{
        width:calc(100% - 18px);
        margin-top:15px;
    }

    input[type=text],
    input[type=email],
    input[type=password],
    input[type=number],
    input[type=search],
    input[type=datetime-local],
    select,
    textarea{
        font-size:16px;
    }
}
</style>
</head>

<body>

<?php if ($admin): ?>
<header class="admin-header">
<div class="admin-header-inner">
<div class="brand">
<?= h(APP_TITLE) ?>
</div>

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
<?php
}

function render_flash(): void
{
    $flash = take_flash();

    if (!$flash) {
        return;
    }

    $class = match (
        $flash['type'] ?? 'info'
    ) {
        'success' => 'alert-success',
        'error' => 'alert-error',
        'warning' => 'alert-warning',
        default => 'alert-info',
    };
?>
<div class="container" style="padding-top:18px">
<div class="alert <?= h($class) ?>">
<?= h($flash['message'] ?? '') ?>
</div>
</div>
<?php
}

function render_footer(): void
{
?>
<script>
(function(){

'use strict';

/* ============================================================
 * Common confirmation
 * ============================================================ */

document.addEventListener('click', function(e){

    const target = e.target.closest('[data-confirm]');

    if(!target){
        return;
    }

    const message =
        target.getAttribute('data-confirm');

    if(message && !window.confirm(message)){
        e.preventDefault();
        e.stopImmediatePropagation();
    }
});

/* ============================================================
 * Survey editor
 * ============================================================ */

let dragQuestion = null;
let dragGroup = null;

function uniqueId(prefix){
    return prefix + '-' +
        Date.now() + '-' +
        Math.random()
            .toString(16)
            .slice(2);
}

function esc(value){
    return String(value)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function renumberQuestions(){

    const numbering =
        document.getElementById('numbering');

    const mode =
        numbering
        ? numbering.value
        : 'global';

    let globalNo = 1;
    let groupNo = 1;

    document
        .querySelectorAll('#groups > .group-card')
        .forEach(function(group){

            let questionNo = 1;

            group
                .querySelectorAll(
                    ':scope > .card-body > .questions > .question-card'
                )
                .forEach(function(question){

                    const number =
                        question.querySelector(
                            '[data-question-number]'
                        );

                    if(number){

                        number.textContent =
                            mode === 'group'
                            ? 'Q' +
                              groupNo +
                              '-' +
                              questionNo
                            : 'Q' +
                              globalNo;
                    }

                    questionNo++;
                    globalNo++;
                });

            groupNo++;
        });
}

function questionHtml(groupId){

    const qid =
        uniqueId('q');

    return `
<div class="question-card"
     draggable="true"
     data-question-id="${esc(qid)}">

<input type="hidden"
       name="questions_by_group[${esc(groupId)}][]"
       value="${esc(qid)}">

<div class="question-head">

<div>
<span class="drag-handle">☷</span>
<span class="question-number"
      data-question-number>Q</span>
</div>

<button type="button"
        class="btn btn-danger"
        data-remove-question>
削除
</button>

</div>

<div class="card-body">

<div class="form-group">
<label>
<span>質問文</span>
<input type="text"
       name="question_text[${esc(qid)}]"
       maxlength="1000">
</label>
</div>

<div class="grid grid-2">

<div class="form-group">
<label>
<span>回答形式</span>
<select name="question_type[${esc(qid)}]"
        class="js-question-type">
<option value="single">単一選択</option>
<option value="multiple">複数選択</option>
<option value="text">自由記述</option>
</select>
</label>
</div>

<div class="form-group">
<label class="check"
       style="margin-top:30px">
<input type="checkbox"
       name="question_required[${esc(qid)}]"
       value="1"
       checked>
必須
</label>
</div>

</div>

<div class="question-options">

<div class="form-group">

<label>
<span>選択肢</span>
</label>

<div class="options">

<div class="option-row">
<input type="text"
       name="question_option[${esc(qid)}][]"
       value="選択肢1"
       maxlength="500">

<button type="button"
        class="btn btn-light"
        data-remove-option>
削除
</button>
</div>

<div class="option-row">
<input type="text"
       name="question_option[${esc(qid)}][]"
       value="選択肢2"
       maxlength="500">

<button type="button"
        class="btn btn-light"
        data-remove-option>
削除
</button>
</div>

</div>

<button type="button"
        class="btn btn-secondary"
        data-add-option>
＋ 選択肢追加
</button>

</div>

</div>

</div>
</div>
`;
}

function groupHtml(){

    const gid =
        uniqueId('g');

    return `
<div class="group-card"
     draggable="true"
     data-group-id="${esc(gid)}">

<input type="hidden"
       name="group_order[]"
       value="${esc(gid)}">

<div class="group-head">

<div class="group-title-line">
<span class="drag-handle">☷</span>
<strong>グループ</strong>
</div>

<button type="button"
        class="btn btn-danger"
        data-remove-group>
グループ削除
</button>

</div>

<div class="card-body">

<div class="form-group">
<label>
<span>グループタイトル</span>
<input type="text"
       name="group_title[${esc(gid)}]"
       value="新しいグループ"
       maxlength="200">
</label>
</div>

<div class="questions"></div>

<button type="button"
        class="btn btn-secondary"
        data-add-question>
＋ 質問を追加
</button>

</div>
</div>
`;
}

function addQuestion(group){

    if(!group){
        return;
    }

    const container =
        group.querySelector('.questions');

    const groupId =
        group.getAttribute('data-group-id');

    if(!container || !groupId){
        return;
    }

    container.insertAdjacentHTML(
        'beforeend',
        questionHtml(groupId)
    );

    renumberQuestions();
}

function addGroup(){

    const groups =
        document.getElementById('groups');

    if(!groups){
        return;
    }

    groups.insertAdjacentHTML(
        'beforeend',
        groupHtml()
    );

    const group =
        groups.lastElementChild;

    addQuestion(group);
    renumberQuestions();
}

/*
 * DOMの現在位置からhidden inputを再構成する。
 * これによりD&D後もサーバー側の保存順序と
 * 画面順序が一致する。
 */
function syncEditorOrder(){

    const groups =
        document.getElementById('groups');

    if(!groups){
        return;
    }

    groups
        .querySelectorAll(':scope > .group-card')
        .forEach(function(group){

            const gid =
                group.getAttribute('data-group-id');

            const groupInput =
                group.querySelector(
                    ':scope > input[name="group_order[]"]'
                );

            if(groupInput){
                groupInput.value = gid;
            }

            const questions =
                group.querySelector(
                    ':scope > .card-body > .questions'
                );

            if(!questions){
                return;
            }

            questions
                .querySelectorAll(
                    ':scope > .question-card'
                )
                .forEach(function(question){

                    const qid =
                        question.getAttribute(
                            'data-question-id'
                        );

                    let hidden =
                        question.querySelector(
                            ':scope > input[name^="questions_by_group["]'
                        );

                    if(!hidden){

                        hidden =
                            document.createElement('input');

                        hidden.type = 'hidden';

                        question.prepend(hidden);
                    }

                    hidden.name =
                        'questions_by_group['
                        + gid
                        + '][]';

                    hidden.value = qid;
                });
        });
}

/*
 * 質問D&D
 *
 * - 同一グループ内
 * - グループ間移動
 */
document.addEventListener(
    'dragstart',
    function(e){

        const question =
            e.target.closest('.question-card');

        if(question){

            dragQuestion = question;
            dragGroup = null;

            question.classList.add('dragging');

            if(e.dataTransfer){
                e.dataTransfer.effectAllowed =
                    'move';

                e.dataTransfer.setData(
                    'text/plain',
                    question.getAttribute(
                        'data-question-id'
                    ) || ''
                );
            }

            return;
        }

        const group =
            e.target.closest('.group-card');

        if(group){

            dragGroup = group;
            dragQuestion = null;

            group.classList.add('dragging');

            if(e.dataTransfer){
                e.dataTransfer.effectAllowed =
                    'move';

                e.dataTransfer.setData(
                    'text/plain',
                    group.getAttribute(
                        'data-group-id'
                    ) || ''
                );
            }
        }
    }
);

document.addEventListener(
    'dragend',
    function(){

        document
            .querySelectorAll(
                '.dragging,.drag-over'
            )
            .forEach(function(el){
                el.classList.remove(
                    'dragging',
                    'drag-over'
                );
            });

        dragQuestion = null;
        dragGroup = null;

        syncEditorOrder();
        renumberQuestions();
    }
);

document.addEventListener(
    'dragover',
    function(e){

        if(!dragQuestion && !dragGroup){
            return;
        }

        const targetQuestion =
            e.target.closest('.question-card');

        const targetGroup =
            e.target.closest('.group-card');

        if(
            dragQuestion
            && targetQuestion
            && targetQuestion !== dragQuestion
        ){
            e.preventDefault();

            document
                .querySelectorAll(
                    '.question-card.drag-over'
                )
                .forEach(function(el){
                    el.classList.remove(
                        'drag-over'
                    );
                });

            targetQuestion.classList.add(
                'drag-over'
            );

            return;
        }

        if(
            dragQuestion
            && targetGroup
        ){
            e.preventDefault();

            document
                .querySelectorAll(
                    '.group-card.drag-over'
                )
                .forEach(function(el){
                    el.classList.remove(
                        'drag-over'
                    );
                });

            targetGroup.classList.add(
                'drag-over'
            );

            return;
        }

        if(
            dragGroup
            && targetGroup
            && targetGroup !== dragGroup
        ){
            e.preventDefault();

            document
                .querySelectorAll(
                    '.group-card.drag-over'
                )
                .forEach(function(el){
                    el.classList.remove(
                        'drag-over'
                    );
                });

            targetGroup.classList.add(
                'drag-over'
            );
        }
    }
);

document.addEventListener(
    'drop',
    function(e){

        if(
            dragQuestion
            || dragGroup
        ){
            e.preventDefault();
        }

        if(dragQuestion){

            const targetQuestion =
                e.target.closest('.question-card');

            const targetGroup =
                e.target.closest('.group-card');

            if(
                targetQuestion
                && targetQuestion !== dragQuestion
            ){

                const targetContainer =
                    targetQuestion.parentElement;

                if(targetContainer){
                    const rect =
                        targetQuestion.getBoundingClientRect();

                    const after =
                        e.clientY >
                        rect.top +
                        rect.height / 2;

                    if(after){
                        targetContainer.insertBefore(
                            dragQuestion,
                            targetQuestion.nextSibling
                        );
                    }else{
                        targetContainer.insertBefore(
                            dragQuestion,
                            targetQuestion
                        );
                    }
                }

            }else if(targetGroup){

                const questions =
                    targetGroup.querySelector(
                        '.questions'
                    );

                if(questions){
                    questions.appendChild(
                        dragQuestion
                    );
                }
            }

            syncEditorOrder();
            renumberQuestions();
            return;
        }

        if(dragGroup){

            const targetGroup =
                e.target.closest('.group-card');

            const groups =
                document.getElementById('groups');

            if(
                targetGroup
                && groups
                && targetGroup !== dragGroup
            ){
                const rect =
                    targetGroup.getBoundingClientRect();

                const after =
                    e.clientY >
                    rect.top +
                    rect.height / 2;

                if(after){
                    groups.insertBefore(
                        dragGroup,
                        targetGroup.nextSibling
                    );
                }else{
                    groups.insertBefore(
                        dragGroup,
                        targetGroup
                    );
                }
            }

            syncEditorOrder();
            renumberQuestions();
        }
    }
);

/*
 * 編集画面ボタン。
 * JSだけに依存せず、保存は通常のPOST。
 */
document.addEventListener(
    'click',
    function(e){

        const addGroupButton =
            e.target.closest(
                '[data-add-group]'
            );

        if(addGroupButton){
            e.preventDefault();
            addGroup();
            return;
        }

        const addQuestionButton =
            e.target.closest(
                '[data-add-question]'
            );

        if(addQuestionButton){
            e.preventDefault();

            addQuestion(
                addQuestionButton.closest(
                    '.group-card'
                )
            );

            return;
        }

        const removeGroupButton =
            e.target.closest(
                '[data-remove-group]'
            );

        if(removeGroupButton){

            e.preventDefault();

            if(
                !window.confirm(
                    'このグループを削除しますか？'
                )
            ){
                return;
            }

            const groups =
                document.querySelectorAll(
                    '#groups > .group-card'
                );

            if(groups.length <= 1){
                window.alert(
                    'グループは1つ以上必要です。'
                );
                return;
            }

            const group =
                removeGroupButton.closest(
                    '.group-card'
                );

            if(group){
                group.remove();
                syncEditorOrder();
                renumberQuestions();
            }

            return;
        }

        const removeQuestionButton =
            e.target.closest(
                '[data-remove-question]'
            );

        if(removeQuestionButton){

            e.preventDefault();

            if(
                !window.confirm(
                    'この質問を削除しますか？'
                )
            ){
                return;
            }

            const question =
                removeQuestionButton.closest(
                    '.question-card'
                );

            if(question){
                question.remove();
                syncEditorOrder();
                renumberQuestions();
            }

            return;
        }

        const addOptionButton =
            e.target.closest(
                '[data-add-option]'
            );

        if(addOptionButton){

            e.preventDefault();

            const question =
                addOptionButton.closest(
                    '.question-card'
                );

            if(!question){
                return;
            }

            const options =
                question.querySelector(
                    '.options'
                );

            const qid =
                question.getAttribute(
                    'data-question-id'
                );

            if(!options || !qid){
                return;
            }

            const row =
                document.createElement('div');

            row.className =
                'option-row';

            row.innerHTML =
                '<input type="text"'
                + ' name="question_option['
                + esc(qid)
                + '][]" value="" maxlength="500">'
                + '<button type="button"'
                + ' class="btn btn-light"'
                + ' data-remove-option>削除</button>';

            options.appendChild(row);

            return;
        }

        const removeOptionButton =
            e.target.closest(
                '[data-remove-option]'
            );

        if(removeOptionButton){

            e.preventDefault();

            const row =
                removeOptionButton.closest(
                    '.option-row'
                );

            if(row){
                row.remove();
            }

            return;
        }
    }
);

document.addEventListener(
    'change',
    function(e){

        if(
            e.target.matches(
                '.js-question-type'
            )
        ){

            const question =
                e.target.closest(
                    '.question-card'
                );

            if(!question){
                return;
            }

            const options =
                question.querySelector(
                    '.question-options'
                );

            if(options){
                options.style.display =
                    e.target.value === 'text'
                    ? 'none'
                    : '';
            }
        }

        if(
            e.target.matches(
                '#numbering'
            )
        ){
            renumberQuestions();
        }
    }
);

/*
 * 保存直前にも必ず順序を同期。
 */
const surveyForm =
    document.querySelector(
        'form[data-survey-form]'
    );

if(surveyForm){

    surveyForm.addEventListener(
        'submit',
        function(){
            syncEditorOrder();
            renumberQuestions();
        }
    );
}

/* ============================================================
 * Customer search
 * ============================================================ */

const customerSearch =
    document.getElementById(
        'customerSearch'
    );

if(customerSearch){

    customerSearch.addEventListener(
        'input',
        function(){

            const value =
                customerSearch.value
                    .trim()
                    .toLowerCase();

            document
                .querySelectorAll(
                    '[data-customer-row]'
                )
                .forEach(function(row){

                    row.style.display =
                        row.textContent
                            .toLowerCase()
                            .includes(value)
                        ? ''
                        : 'none';
                });
        }
    );
}

/* ============================================================
 * Select all
 * ============================================================ */

const selectAll =
    document.getElementById(
        'selectAllCustomers'
    );

if(selectAll){

    selectAll.addEventListener(
        'change',
        function(){

            document
                .querySelectorAll(
                    '.customer-check'
                )
                .forEach(function(box){

                    const row =
                        box.closest(
                            '[data-customer-row]'
                        );

                    if(
                        !row
                        || row.style.display !== 'none'
                    ){
                        box.checked =
                            selectAll.checked;
                    }
                });
        }
    );
}

/* ============================================================
 * Initial numbering
 * ============================================================ */

renumberQuestions();
syncEditorOrder();

})();
</script>
</body>
</html>
<?php
}

/* ============================================================
 * List
 * ============================================================ */

function render_list(
    array $surveys,
    array $answers
): void {

    $search =
        get_string('q');

    $status =
        get_string('status');

    $sort =
        get_string('sort');

    if ($sort === '') {
        $sort = 'updated_desc';
    }

    $filtered =
        array_values(
            array_filter(
                $surveys,
                static function(
                    array $survey
                ) use ($search, $status): bool {

                    if(
                        $search !== ''
                        && mb_stripos(
                            (string)(
                                $survey['title']
                                ?? ''
                            ),
                            $search
                        ) === false
                    ){
                        return false;
                    }

                    if(
                        $status !== ''
                        && $status !== 'all'
                        && (
                            $survey['status']
                            ?? 'draft'
                        ) !== $status
                    ){
                        return false;
                    }

                    return true;
                }
            )
        );

    usort(
        $filtered,
        static function(
            array $a,
            array $b
        ) use ($sort): int {

            return match ($sort) {

                'updated_asc' =>
                    strcmp(
                        (string)(
                            $a['updatedAt']
                            ?? ''
                        ),
                        (string)(
                            $b['updatedAt']
                            ?? ''
                        )
                    ),

                'answers_desc' =>
                    0,

                'answers_asc' =>
                    0,

                'start_desc' =>
                    strcmp(
                        (string)(
                            $b['startAt']
                            ?? ''
                        ),
                        (string)(
                            $a['startAt']
                            ?? ''
                        )
                    ),

                'start_asc' =>
                    strcmp(
                        (string)(
                            $a['startAt']
                            ?? ''
                        ),
                        (string)(
                            $b['startAt']
                            ?? ''
                        )
                    ),

                default =>
                    strcmp(
                        (string)(
                            $b['updatedAt']
                            ?? ''
                        ),
                        (string)(
                            $a['updatedAt']
                            ?? ''
                        )
                    ),
            };
        }
    );

    /*
     * 回答数ソートは実データから計算。
     */
    if(
        $sort === 'answers_desc'
        || $sort === 'answers_asc'
    ){
        usort(
            $filtered,
            static function(
                array $a,
                array $b
            ) use ($answers, $sort): int {

                $ca = 0;
                $cb = 0;

                foreach($answers as $answer){

                    if(
                        ($answer['survey_id'] ?? '')
                        === ($a['id'] ?? '')
                    ){
                        $ca++;
                    }

                    if(
                        ($answer['survey_id'] ?? '')
                        === ($b['id'] ?? '')
                    ){
                        $cb++;
                    }
                }

                return $sort === 'answers_desc'
                    ? $cb <=> $ca
                    : $ca <=> $cb;
            }
        );
    }

    render_head('アンケート一覧');
    render_flash();
?>
<div class="container page">

<div class="page-title">

<div>
<h1>アンケート一覧</h1>
<p>アンケートの作成・公開・送信・集計を管理します。</p>
</div>

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

<input type="hidden"
       name="screen"
       value="list">

<div class="grid grid-3">

<div class="form-group">
<label>
<span>タイトル検索</span>
<input type="search"
       name="q"
       value="<?= h($search) ?>"
       placeholder="タイトルを検索">
</label>
</div>

<div class="form-group">
<label>
<span>ステータス</span>
<select name="status">
<option value="all"
<?= $status==='all'||$status===''?'selected':'' ?>>
すべて
</option>
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
</label>
</div>

<div class="form-group">
<label>
<span>ソート</span>
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
</label>
</div>

</div>

<div class="button-row">
<button class="btn btn-primary"
        type="submit">
検索
</button>
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

<?php if(!$filtered): ?>

<tr>
<td colspan="7">
<div class="empty">
アンケートがありません。
</div>
</td>
</tr>

<?php endif; ?>

<?php foreach(
    $filtered as $survey
): ?>

<?php
$statusValue =
    (string)(
        $survey['status']
        ?? 'draft'
    );

$count = 0;

foreach($answers as $answer){
    if(
        ($answer['survey_id'] ?? '')
        === ($survey['id'] ?? '')
    ){
        $count++;
    }
}
?>

<tr>

<td>
<strong>
<?= h($survey['title']) ?>
</strong>
</td>

<td>
<?= h($survey['createdAt'] ?? '') ?>
</td>

<td>
<?= h($survey['updatedAt'] ?? '') ?>
</td>

<td>
<?= h($survey['startAt'] ?? '') ?>
<br>
〜
<br>
<?= h($survey['endAt'] ?? '') ?>
</td>

<td>
<span class="badge badge-<?= h(
    status_class($statusValue)
) ?>">
<?= h(status_label($statusValue)) ?>
</span>
</td>

<td>
<?= h($count) ?>
</td>

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

<form method="post">
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

<form method="post">
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

</tbody>
</table>

</div>
</div>
</div>

</div>
<?php
}

/* ============================================================
 * Edit
 * ============================================================ */

function render_edit(
    array $survey
): void {

    recalc_numbers($survey);

    $status =
        (string)(
            $survey['status']
            ?? 'draft'
        );

    $nextStatus =
        match($status){
            'draft' => 'published',
            'published' => 'stopped',
            'stopped' => 'published',
            default => '',
        };

    $nextLabel =
        match($status){
            'draft' => '公開',
            'published' => '停止',
            'stopped' => '再開',
            default => '',
        };

    $confirm =
        match($status){
            'draft' =>
                'このアンケートを公開しますか？',
            'published' =>
                'このアンケートを停止しますか？',
            'stopped' =>
                'このアンケートを再開しますか？',
            default => '',
        };

    render_head('アンケート作成・編集');
    render_flash();
?>
<div class="container page">

<div class="page-title">
<div>
<h1>アンケート作成・編集</h1>
<p>質問、グループ、公開期間を設定します。</p>
</div>
</div>

<!--
     状態変更フォームは保存フォームの外側。
     絶対にformの中へformを入れない。
-->
<div class="card">

<div class="card-body">

<div class="button-row"
     style="justify-content:space-between">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'list'
   ])) ?>"
   data-confirm="編集内容を破棄して一覧へ戻りますか？">
キャンセル
</a>

<div class="button-row">

<?php if($status !== 'ended'): ?>

<span class="badge badge-<?= h(
    status_class($status)
) ?>">
状態：<?= h(
    status_label($status)
) ?>
</span>

<form method="post">
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden"
       name="next_status"
       value="<?= h($nextStatus) ?>">

<button class="btn <?= $status==='published'
    ? 'btn-warning'
    : 'btn-success' ?>"
        type="submit"
        data-confirm="<?= h($confirm) ?>">
<?= h($nextLabel) ?>
</button>
</form>

<?php else: ?>

<span class="badge badge-gray">
状態：終了
</span>

<?php endif; ?>

</div>
</div>

</div>
</div>

<!-- 保存フォームは単独 -->
<form method="post"
      data-survey-form>

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="card">

<div class="card-body">

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
<select name="numbering"
        id="numbering">

<option value="global"
<?= ($survey['numbering']??'global')==='global'
    ? 'selected'
    : '' ?>>
アンケート全体で通番（Q1、Q2…）
</option>

<option value="group"
<?= ($survey['numbering']??'global')==='group'
    ? 'selected'
    : '' ?>>
グループ毎（Q1-1、Q1-2…）
</option>

</select>
</label>
</div>

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

<div class="form-group">
<label>
<span>アンケート説明</span>
<textarea name="description"
          maxlength="5000"><?= h(
              $survey['description']
          ) ?></textarea>
</label>
</div>

</div>
</div>

<div id="groups">

<?php foreach(
    $survey['groups']
    as $group
): ?>

<div class="group-card"
     draggable="true"
     data-group-id="<?= h($group['id']) ?>">

<input type="hidden"
       name="group_order[]"
       value="<?= h($group['id']) ?>">

<div class="group-head">

<div class="group-title-line">
<span class="drag-handle">☷</span>
<strong>グループ</strong>
</div>

<button type="button"
        class="btn btn-danger"
        data-remove-group>
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

<?php foreach(
    $group['questions']
    as $question
): ?>

<div class="question-card"
     draggable="true"
     data-question-id="<?= h(
         $question['id']
     ) ?>">

<input type="hidden"
       name="questions_by_group[<?= h(
           $group['id']
       ) ?>][]"
       value="<?= h($question['id']) ?>">

<div class="question-head">

<div>
<span class="drag-handle">☷</span>
<span class="question-number"
      data-question-number>
<?= h($question['number']) ?>
</span>
</div>

<button type="button"
        class="btn btn-danger"
        data-remove-question>
削除
</button>

</div>

<div class="card-body">

<div class="form-group">
<label>
<span>質問文</span>
<input type="text"
       name="question_text[<?= h(
           $question['id']
       ) ?>]"
       value="<?= h(
           $question['text']
       ) ?>"
       maxlength="1000">
</label>
</div>

<div class="grid grid-2">

<div class="form-group">
<label>
<span>回答形式</span>

<select name="question_type[<?= h(
    $question['id']
) ?>]"
        class="js-question-type">

<option value="single"
<?= $question['type']==='single'
    ? 'selected'
    : '' ?>>
単一選択
</option>

<option value="multiple"
<?= $question['type']==='multiple'
    ? 'selected'
    : '' ?>>
複数選択
</option>

<option value="text"
<?= $question['type']==='text'
    ? 'selected'
    : '' ?>>
自由記述
</option>

</select>

</label>
</div>

<div class="form-group">
<label class="check"
       style="margin-top:30px">

<input type="checkbox"
       name="question_required[<?= h(
           $question['id']
       ) ?>]"
       value="1"
<?= !empty($question['required'])
    ? ' checked'
    : '' ?>>

必須

</label>
</div>

</div>

<div class="question-options"
     style="<?= $question['type']==='text'
         ? 'display:none'
         : '' ?>">

<div class="form-group">

<label>
<span>選択肢</span>
</label>

<div class="options">

<?php foreach(
    ($question['options'] ?? [])
    as $oi => $option
): ?>

<div class="option-row">

<input type="text"
       name="question_option[<?= h(
           $question['id']
       ) ?>][]"
       value="<?= h(
           $option['label']
       ) ?>"
       maxlength="500">

<?php if(
    $question['type'] === 'single'
): ?>

<select name="option_next[<?= h(
    $question['id']
) ?>][]">

<option value="">
分岐なし
</option>

<?php foreach(
    $survey['groups']
    as $targetGroup
): ?>

<?php foreach(
    $targetGroup['questions']
    as $targetQuestion
): ?>

<?php if(
    $targetQuestion['id']
    !== $question['id']
): ?>

<option value="<?= h(
    $targetQuestion['id']
) ?>"
<?= (
    ($option['nextQuestionId'] ?? '')
    === $targetQuestion['id']
)
    ? ' selected'
    : '' ?>>

<?= h(
    $targetQuestion['number']
    . ' '
    . $targetQuestion['text']
) ?>

</option>

<?php endif; ?>

<?php endforeach; ?>

<?php endforeach; ?>

</select>

<?php endif; ?>

<button type="button"
        class="btn btn-light"
        data-remove-option>
削除
</button>

</div>

<?php endforeach; ?>

</div>

<button type="button"
        class="btn btn-secondary"
        data-add-option>
＋ 選択肢追加
</button>

</div>
</div>

</div>
</div>

<?php endforeach; ?>

</div>

<button type="button"
        class="btn btn-secondary"
        data-add-question>
＋ 質問を追加
</button>

</div>
</div>

<?php endforeach; ?>

</div>

<div class="card">
<div class="card-body">

<button type="button"
        class="btn btn-secondary"
        data-add-group>
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
       'id'=>$survey['id']
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

</div>
<?php
}

/* ============================================================
 * Preview
 * ============================================================ */

function render_preview(array $survey): void
{
    recalc_numbers($survey);

    render_head(
        'プレビュー'
    );

    render_flash();
?>
<div class="container page">

<div class="page-title">

<div>
<h1><?= h($survey['title']) ?></h1>
<p>アンケートプレビュー</p>
</div>

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

<?php if(
    trim((string)$survey['description'])
    !== ''
): ?>

<p>
<?= nl2br(
    h($survey['description'])
) ?>
</p>

<?php endif; ?>

<?php foreach(
    $survey['groups']
    as $group
): ?>

<h2>
<?= h($group['title']) ?>
</h2>

<?php foreach(
    $group['questions']
    as $question
): ?>

<div class="preview-question">

<div>
<strong class="question-number">
<?= h($question['number']) ?>
</strong>

<?= h($question['text']) ?>

<?php if(
    !empty($question['required'])
): ?>

<span class="badge badge-warning">
必須
</span>

<?php endif; ?>

</div>

<?php if(
    $question['type'] === 'text'
): ?>

<textarea
        disabled
        placeholder="自由記述"></textarea>

<?php else: ?>

<?php foreach(
    $question['options']
    as $option
): ?>

<label class="answer-option">

<input type="<?= $question['type']==='single'
    ? 'radio'
    : 'checkbox' ?>"
       disabled>

<span>
<?= h($option['label']) ?>
</span>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>
</div>

</div>
<?php
}

/* ============================================================
 * Kintone
 * ============================================================ */

function render_kintone(
    array $config
): void {

    render_head(
        'kintone連携設定'
    );

    render_flash();
?>
<div class="container page">

<div class="page-title">
<div>
<h1>kintone連携設定</h1>
<p>顧客管理アプリとの接続・項目取得・同期を設定します。</p>
</div>
</div>

<div class="card">

<div class="card-header">
<h2>kintone接続設定</h2>
</div>

<div class="card-body">

<form method="post">

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
       placeholder="https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx"
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
       autocomplete="new-password"
       placeholder="変更する場合のみ入力">
</label>
</div>

<div class="form-group">
<label>
<span>Proxy</span>
<input type="text"
       name="proxy"
       value="<?= h(
           $config['proxy']
       ) ?>"
       placeholder="host:port（未入力なら直接接続）">
</label>
</div>

<div class="form-group">

<label class="check"
       style="margin-top:30px">

<input type="checkbox"
       name="verify_ssl"
       value="1"
<?= !empty($config['verify_ssl'])
    ? ' checked'
    : '' ?>>

SSL証明書を検証する

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

<hr style="border:0;border-top:1px solid var(--border);margin:24px 0">

<div class="button-row">

<form method="post">

<input type="hidden"
       name="action"
       value="test_kintone">

<div class="form-group"
     style="margin:0">

<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="接続テスト時にパスワードを変更する場合">

</div>

<button class="btn btn-secondary"
        type="submit">
接続テスト
</button>

</form>

<form method="post">

<input type="hidden"
       name="action"
       value="fetch_kintone_fields">

<button class="btn btn-secondary"
        type="submit">
項目一覧を再取得
</button>

</form>

<form method="post">

<input type="hidden"
       name="action"
       value="sync_kintone">

<button class="btn btn-primary"
        type="submit"
        data-confirm="kintoneから顧客情報を同期しますか？">
顧客情報を同期
</button>

</form>

</div>

<hr style="border:0;border-top:1px solid var(--border);margin:24px 0">

<h3>接続状態</h3>

<p>
<span class="badge badge-<?= h(
    $config['status']==='接続確認済み'
    ? 'success'
    : 'gray'
) ?>">
<?= h(
    $config['status']
) ?>
</span>
</p>

<?php if(
    !empty($config['last_test'])
): ?>

<p>
最終接続確認：
<?= h($config['last_test']) ?>
</p>

<?php endif; ?>

<?php if(
    !empty($config['last_sync'])
): ?>

<p>
最終同期：
<?= h($config['last_sync']) ?>
</p>

<?php endif; ?>

</div>
</div>

<?php if(
    !empty($config['fields'])
): ?>

<div class="card">

<div class="card-header">
<h2>顧客情報項目マッピング</h2>
</div>

<div class="card-body">

<form method="post">

<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<?php
$labels = [
    'organization' => '組織名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署名',
    'phone' => '電話番号',
];
?>

<div class="mapping-grid">

<?php foreach(
    $labels as $key => $label
): ?>

<div class="form-group">

<label>
<span><?= h($label) ?></span>

<select name="mapping_<?= h($key) ?>">

<option value="">
未設定
</option>

<?php foreach(
    $config['fields']
    as $field
): ?>

<option value="<?= h(
    $field['code']
) ?>"
<?= (
    ($config['mapping'][$key] ?? '')
    === $field['code']
)
    ? ' selected'
    : '' ?>>

<?= h(
    $field['label']
    . ' ['
    . $field['code']
    . ']'
) ?>

</option>

<?php endforeach; ?>

</select>

</label>

</div>

<?php endforeach; ?>

</div>

<div class="form-group">

<label>
<span>住所</span>
</label>

<!--
     住所は仕様どおり複数選択可能なチェックボックス。
-->
<div class="address-map">

<?php foreach(
    $config['fields']
    as $field
): ?>

<label class="check">

<input type="checkbox"
       name="mapping_address[]"
       value="<?= h(
           $field['code']
       ) ?>"
<?= in_array(
    $field['code'],
    $config['mapping']['address'] ?? [],
    true
)
    ? ' checked'
    : '' ?>>

<span>
<?= h(
    $field['label']
    . ' ['
    . $field['code']
    . ']'
) ?>
</span>

</label>

<?php endforeach; ?>

</div>

</div>

<button class="btn btn-primary"
        type="submit">
マッピングを保存
</button>

</form>

</div>
</div>

<?php endif; ?>

</div>
<?php
}

/* ============================================================
 * Mail
 * ============================================================ */

function render_mail(
    array $mail
): void {

    render_head(
        'メールサーバ設定'
    );

    render_flash();
?>
<div class="container page">

<div class="page-title">
<div>
<h1>メールサーバ設定</h1>
<p>SMTP接続・認証・テストメールを設定します。</p>
</div>
</div>

<div class="card">

<div class="card-header">
<h2>SMTP設定</h2>
</div>

<div class="card-body">

<form method="post">

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
           $mail['server']
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
           $mail['port']
       ) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>暗号化方式</span>
<select name="encryption">
<option value="ssl"
<?= $mail['encryption']==='ssl'
    ? 'selected'
    : '' ?>>
SSL
</option>
<option value="tls"
<?= $mail['encryption']==='tls'
    ? 'selected'
    : '' ?>>
TLS
</option>
<option value="none"
<?= $mail['encryption']==='none'
    ? 'selected'
    : '' ?>>
なし
</option>
</select>
</label>
</div>

<div class="form-group">
<label class="check"
       style="margin-top:30px">
<input type="checkbox"
       name="auth"
       value="1"
<?= !empty($mail['auth'])
    ? 'checked'
    : '' ?>>
SMTP認証
</label>
</div>

<div class="form-group">
<label>
<span>SMTPユーザー名</span>
<input type="text"
       name="username"
       value="<?= h(
           $mail['username']
       ) ?>">
</label>
</div>

<div class="form-group">
<label>
<span>SMTPパスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更する場合のみ入力">
</label>
</div>

<div class="form-group">
<label>
<span>送信元メールアドレス</span>
<input type="email"
       name="from_email"
       value="<?= h(
           $mail['from_email']
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
           $mail['from_name']
       ) ?>">
</label>
</div>

<div class="form-group">
<label>
<span>返信先メールアドレス</span>
<input type="email"
       name="reply_to"
       value="<?= h(
           $mail['reply_to']
       ) ?>">
</label>
</div>

</div>

<button class="btn btn-primary"
        type="submit">
設定保存
</button>

</form>

<hr style="border:0;border-top:1px solid var(--border);margin:24px 0">

<h3>接続状態</h3>

<p>
<span class="badge badge-<?= h(
    $mail['status']==='接続確認済み'
    ? 'success'
    : $mail['status']==='接続できません'
        ? 'warning'
        : 'gray'
) ?>">
<?= h($mail['status']) ?>
</span>
</p>

<div class="grid grid-2">

<div>

<form method="post">

<input type="hidden"
       name="action"
       value="test_mail">

<div class="form-group">
<label>
<span>接続テスト用パスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="保存済みなら空欄可">
</label>
</div>

<button class="btn btn-secondary"
        type="submit">
接続テスト
</button>

</form>

</div>

<div>

<form method="post">

<input type="hidden"
       name="action"
       value="send_test_mail">

<div class="form-group">
<label>
<span>テスト送信先</span>
<input type="email"
       name="test_email"
       required>
</label>
</div>

<button class="btn btn-primary"
        type="submit"
        data-confirm="テストメールを送信しますか？">
テストメール送信
</button>

</form>

</div>

</div>

</div>
</div>

</div>
<?php
}

/* ============================================================
 * Send
 * ============================================================ */

function render_send(
    array $survey,
    array $customers,
    array $history
): void {

    render_head(
        '顧客選択・メール送信'
    );

    render_flash();
?>
<div class="container page">

<div class="page-title">

<div>
<h1>顧客選択・メール送信</h1>
<p>
対象アンケート：
<strong><?= h(
    $survey['title']
) ?></strong>
</p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'list'
   ])) ?>">
一覧へ戻る
</a>

</div>

<div class="card">

<div class="card-header">
<h2>顧客選択・メール作成</h2>
</div>

<div class="card-body">

<div class="notice">
メール変数：
{顧客名} / {アンケートURL}
<br>
アンケートURLは送信時に完全なURLへ展開されます。
</div>

<form method="post">

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
       id="customerSearch"
       placeholder="氏名・組織名・メールアドレス等で検索">
</label>
</div>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>
<label class="check">
<input type="checkbox"
       id="selectAllCustomers">
全選択
</label>
</th>
<th>氏名</th>
<th>組織名</th>
<th>部署</th>
<th>メール</th>
</tr>
</thead>

<tbody>

<?php if(!$customers): ?>

<tr>
<td colspan="5">
<div class="empty">
顧客情報がありません。
<br>
先にkintoneから顧客情報を同期してください。
</div>
</td>
</tr>

<?php endif; ?>

<?php foreach(
    $customers as $customer
): ?>

<tr data-customer-row>

<td>
<input type="checkbox"
       class="customer-check"
       name="customer_ids[]"
       value="<?= h(
           $customer['id']
       ) ?>">
</td>

<td>
<?= h(
    $customer['name'] ?? ''
) ?>
</td>

<td>
<?= h(
    $customer['organization'] ?? ''
) ?>
</td>

<td>
<?= h(
    $customer['department'] ?? ''
) ?>
</td>

<td>
<?= h(
    $customer['email'] ?? ''
) ?>
</td>

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
           . 'のご案内'
       ) ?>"
       required>
</label>
</div>

<div></div>

<div class="form-group"
     style="grid-column:1/-1">

<label>
<span>メール本文</span>

<textarea name="body"
          required>「{顧客名}」様

以下のアンケートへのご回答をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>

</label>

</div>

</div>

<div class="button-row">

<button class="btn btn-primary"
        type="submit"
        data-confirm="選択した顧客へメールを送信しますか？">
一括送信
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

<?php
$surveyHistory =
    array_values(
        array_filter(
            $history,
            static fn(array $item): bool =>
                ($item['survey_id'] ?? '')
                === ($survey['id'] ?? '')
        )
    );

$surveyHistory =
    array_reverse($surveyHistory);
?>

<?php if(!$surveyHistory): ?>

<tr>
<td colspan="4">
<div class="empty">
送信履歴はありません。
</div>
</td>
</tr>

<?php endif; ?>

<?php foreach(
    $surveyHistory as $item
): ?>

<tr>

<td>
<?= h(
    $item['createdAt'] ?? ''
) ?>
</td>

<td>
<?= h(
    $item['customer_name'] ?? ''
) ?>
</td>

<td>
<?= h(
    $item['type'] ?? ''
) ?>
</td>

<td>
<?= h(
    $item['result'] ?? ''
) ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>
</div>
</div>

</div>
<?php
}

/* ============================================================
 * Analytics
 * ============================================================ */

function render_analytics(
    array $survey,
    array $answers
): void {

    recalc_numbers($survey);

    $surveyAnswers =
        array_values(
            array_filter(
                $answers,
                static fn(array $a): bool =>
                    ($a['survey_id'] ?? '')
                    === ($survey['id'] ?? '')
            )
        );

    render_head('回答集計・分析');
    render_flash();
?>
<div class="container page">

<div class="page-title">

<div>
<h1>回答集計・分析</h1>
<p>
対象アンケート：
<strong><?= h(
    $survey['title']
) ?></strong>
</p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'list'
   ])) ?>">
一覧へ戻る
</a>

</div>

<div class="grid grid-3">

<div class="card">
<div class="card-body">
<strong>回答数</strong>
<h2>
<?= h(count($surveyAnswers)) ?>
</h2>
</div>
</div>

<div class="card">
<div class="card-body">
<strong>未登録回答数</strong>
<h2>0</h2>
</div>
</div>

<div class="card">
<div class="card-body">
<strong>未回答数</strong>
<h2>0</h2>
</div>
</div>

</div>

<?php if(!$surveyAnswers): ?>

<div class="card">
<div class="card-body">
<div class="empty">
現在、回答データはありません
</div>
</div>
</div>

<?php else: ?>

<?php foreach(
    $survey['groups']
    as $group
): ?>

<div class="card">

<div class="card-header">
<h2>
<?= h($group['title']) ?>
</h2>
</div>

<div class="card-body">

<?php foreach(
    $group['questions']
    as $question
): ?>

<div class="preview-question">

<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<?php
$counts = [];

foreach(
    $surveyAnswers as $answer
){
    $value =
        $answer['values']
        [$question['id']]
        ?? '';

    if(is_array($value)){
        foreach($value as $v){
            $key = (string)$v;
            $counts[$key] =
                ($counts[$key] ?? 0) + 1;
        }
    }else{
        $key = (string)$value;

        if($key !== ''){
            $counts[$key] =
                ($counts[$key] ?? 0) + 1;
        }
    }
}
?>

<?php if(
    $question['type'] !== 'text'
): ?>

<?php foreach(
    $question['options']
    as $option
): ?>

<p>
<?= h($option['label']) ?>：
<strong>
<?= h(
    $counts[$option['label']]
    ?? 0
) ?>
件
</strong>
</p>

<?php endforeach; ?>

<?php else: ?>

<p>
自由記述回答：
<?= h(
    count($counts)
) ?>件
</p>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>
</div>

<?php endforeach; ?>

<?php endif; ?>

</div>
<?php
}

/* ============================================================
 * Answer
 * ============================================================ */

function render_answer(
    array $survey
): void {

    recalc_numbers($survey);

    $draft =
        $_SESSION['answer_draft']
        ?? [];

    render_head(
        'アンケート回答',
        false
    );
?>
<div class="answer-shell">

<div class="page-title">
<div>
<h1><?= h(
    $survey['title']
) ?></h1>

<?php if(
    trim(
        (string)$survey['description']
    ) !== ''
): ?>

<p>
<?= nl2br(
    h($survey['description'])
) ?>
</p>

<?php endif; ?>

</div>
</div>

<form method="post">

<input type="hidden"
       name="action"
       value="answer_next">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<div class="card">

<div class="card-body">

<?php foreach(
    $survey['groups']
    as $group
): ?>

<h2>
<?= h($group['title']) ?>
</h2>

<?php foreach(
    $group['questions']
    as $question
): ?>

<div class="form-group">

<div class="field-label">

<?= h(
    $question['number']
) ?>

<?= h(
    $question['text']
) ?>

<?php if(
    !empty($question['required'])
): ?>

<span class="badge badge-warning">
必須
</span>

<?php endif; ?>

</div>

<?php if(
    $question['type'] === 'text'
): ?>

<textarea
    name="answer[<?= h(
        $question['id']
    ) ?>]"
<?= !empty($question['required'])
    ? ' required'
    : '' ?>><?= h(
        is_scalar(
            $draft[$question['id']]
            ?? ''
        )
            ? (string)(
                $draft[$question['id']]
                ?? ''
            )
            : ''
    ) ?></textarea>

<?php else: ?>

<?php foreach(
    $question['options']
    as $option
): ?>

<label class="answer-option">

<input
    type="<?= $question['type']==='single'
        ? 'radio'
        : 'checkbox' ?>"
    name="answer[<?= h(
        $question['id']
    ) ?>]<?= $question['type']==='multiple'
        ? '[]'
        : '' ?>"
    value="<?= h(
        $option['label']
    ) ?>"
<?= (
    (
        is_array(
            $draft[$question['id']]
            ?? null
        )
        && in_array(
            $option['label'],
            $draft[$question['id']],
            true
        )
    )
    ||
    (
        !is_array(
            $draft[$question['id']]
            ?? null
        )
        && (
            $draft[$question['id']]
            ?? ''
        ) === $option['label']
    )
)
    ? ' checked'
    : '' ?>>

<span>
<?= h($option['label']) ?>
</span>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="button-row"
     style="justify-content:flex-end">

<button class="btn btn-primary"
        type="submit">
次へ
</button>

</div>

</div>
</div>

</form>

</div>
<?php
}

/* ============================================================
 * Confirm
 * ============================================================ */

function render_confirm(
    array $survey
): void {

    $draft =
        $_SESSION['answer_draft']
        ?? [];

    render_head(
        '回答確認',
        false
    );
?>
<div class="answer-shell">

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

<?php foreach(
    $survey['groups']
    as $group
): ?>

<h2>
<?= h($group['title']) ?>
</h2>

<?php foreach(
    $group['questions']
    as $question
): ?>

<?php
$value =
    $draft[
        $question['id']
    ] ?? '';

if(is_array($value)){
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

<div class="preview-question">

<strong>
<?= h(
    $question['number']
) ?>
<?= h(
    $question['text']
) ?>
</strong>

<p>
<?= nl2br(
    h((string)$value)
) ?>
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
       value="<?= h(
           $survey['id']
       ) ?>">

<button class="btn btn-primary"
        type="submit"
        data-confirm="この回答を送信しますか？">
回答を送信
</button>

</form>

</div>

</div>
</div>

</div>
<?php
}

/* ============================================================
 * Complete
 * ============================================================ */

function render_complete(
    array $survey
): void {

    render_head(
        '回答完了',
        false
    );
?>
<div class="answer-shell">

<div class="card">

<div class="card-body"
     style="text-align:center;padding:55px 25px">

<h1>回答ありがとうございました</h1>

<p>
「<?= h(
    $survey['title']
) ?>」への回答を受け付けました。
</p>

</div>
</div>

</div>
<?php
}

/* ============================================================
 * Main
 * ============================================================ */

try {

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

    /*
     * GET時だけでなくPOST後にも状態整合性を確認するが、
     * published + endAt経過の場合だけendedになる。
     */
    if(
        refresh_all_statuses(
            $surveys
        )
    ){
        save_surveys($surveys);
    }

    handle_post(
        $surveys,
        $customers,
        $answers,
        $history,
        $kintone,
        $mail
    );

    /*
     * POST処理でredirect()された場合はここへ来ない。
     */
    $screen =
        get_string('screen');

    if($screen === ''){
        $screen = 'list';
    }

    /*
     * 回答者画面は管理者UIを表示しない。
     */
    $answerScreens = [
        'answer',
        'confirm',
        'complete',
    ];

    if(
        in_array(
            $screen,
            $answerScreens,
            true
        )
    ){

        $id =
            get_string('id');

        $survey =
            survey_by_id(
                $surveys,
                $id
            );

        if($survey === null){
            http_response_code(404);
            render_head(
                'アンケートが見つかりません',
                false
            );
?>
<div class="answer-shell">
<div class="card">
<div class="card-body">
<h1>アンケートが見つかりません</h1>
<p>指定されたアンケートは存在しません。</p>
</div>
</div>
</div>
<?php
            render_footer();
            exit;
        }

        /*
         * 回答画面では管理者用画面を表示しない。
         */
        match($screen){
            'answer' =>
                render_answer($survey),
            'confirm' =>
                render_confirm($survey),
            'complete' =>
                render_complete($survey),
        };

        render_footer();
        exit;
    }

    /*
     * 管理者画面
     */
    switch($screen){

        case 'edit':

            $id =
                get_string('id');

            if(
                $id === ''
                || $id === 'new'
            ){
                $survey =
                    new_survey();
            }else{
                $survey =
                    survey_by_id(
                        $surveys,
                        $id
                    );

                if($survey === null){
                    redirect([
                        'screen'=>'list'
                    ]);
                }
            }

            render_edit($survey);
            break;

        case 'preview':

            $survey =
                survey_by_id(
                    $surveys,
                    get_string('id')
                );

            if($survey === null){
                redirect([
                    'screen'=>'list'
                ]);
            }

            render_preview($survey);
            break;

        case 'send':

            $survey =
                survey_by_id(
                    $surveys,
                    get_string('id')
                );

            if($survey === null){
                redirect([
                    'screen'=>'list'
                ]);
            }

            render_send(
                $survey,
                $customers,
                $history
            );

            break;

        case 'analytics':

            $survey =
                survey_by_id(
                    $surveys,
                    get_string('id')
                );

            if($survey === null){
                redirect([
                    'screen'=>'list'
                ]);
            }

            render_analytics(
                $survey,
                $answers
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

        case 'list':
        default:

            render_list(
                $surveys,
                $answers
            );

            break;
    }

    render_footer();

} catch (Throwable $e) {

    http_response_code(500);

    /*
     * 本番では詳細例外を画面に出さない。
     */
    render_head(
        'システムエラー'
    );
?>
<div class="container page">

<div class="card">

<div class="card-body">

<h1>システムエラー</h1>

<p>
処理中にエラーが発生しました。
</p>

<p>
<?= h(
    $e->getMessage()
) ?>
</p>

<a class="btn btn-secondary"
   href="<?= h(
       app_url(['screen'=>'list'])
   ) ?>">
アンケート一覧へ
</a>

</div>
</div>

</div>
<?php
    render_footer();
}
?>
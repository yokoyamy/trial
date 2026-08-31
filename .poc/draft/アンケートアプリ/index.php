<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 *
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし
 * index.php 単一エントリーポイント
 *
 * データ:
 *   _data/data.json
 *   _data/settings.json
 *   _data/.secret
 *
 * 重要:
 *   外部環境変数による暗号鍵を要求しない。
 *   kintone認証情報はサーバー側だけで扱う。
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR  = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SET_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const SECRET_FILE = DATA_DIR . DIRECTORY_SEPARATOR . '.secret';

const KINTONE_CONNECT_TIMEOUT = 15;
const KINTONE_READ_TIMEOUT    = 30;
const SMTP_CONNECT_TIMEOUT    = 15;
const SMTP_READ_TIMEOUT       = 30;

const MAX_TITLE = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION = 1000;
const MAX_OPTION = 500;

/* =========================================================
 * 基本
 * ========================================================= */

function h(mixed $v): string
{
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function id_new(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function redirect_screen(string $screen, array $params = []): never
{
    $params = array_merge(['screen' => $screen], $params);
    $qs = http_build_query($params);
    header('Location: index.php?' . $qs);
    exit;
}

function post_string(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_scalar($v) ? trim((string)$v) : $default;
}

function post_array(string $key): array
{
    return isset($_POST[$key]) && is_array($_POST[$key])
        ? $_POST[$key]
        : [];
}

function json_response(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        $body,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

/* =========================================================
 * セッション
 * ========================================================= */

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = !empty($_SERVER['HTTPS'])
        && strtolower((string)$_SERVER['HTTPS']) !== 'off';

    $path = rtrim(
        str_replace(
            '\\',
            '/',
            dirname($_SERVER['SCRIPT_NAME'] ?? '/')
        ),
        '/'
    );

    if ($path === '') {
        $path = '/';
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $path,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/* =========================================================
 * フラッシュ
 * ========================================================= */

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function flash_get(): array
{
    $v = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($v) ? $v : [];
}

/* =========================================================
 * ファイル永続化
 * ========================================================= */

function ensure_data_dir(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0770, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException(
                'データ保存領域を作成できません。'
            );
        }
    }

    /*
     * Apache設定に依存せず、可能な範囲で
     * Webから直接取得されないようにする。
     */
    $htaccess = DATA_DIR . DIRECTORY_SEPARATOR . '.htaccess';

    if (!file_exists($htaccess)) {
        @file_put_contents(
            $htaccess,
            "Require all denied\n"
        );
    }
}

function load_json(string $file, array $default): array
{
    ensure_data_dir();

    if (!is_file($file)) {
        return $default;
    }

    $raw = @file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : $default;
}

function save_json(string $file, array $data): void
{
    ensure_data_dir();

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));

    $raw = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($raw === false) {
        throw new RuntimeException('データのJSON化に失敗しました。');
    }

    $fp = @fopen($tmp, 'wb');

    if (!$fp) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('データロックに失敗しました。');
        }

        fwrite($fp, $raw);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('データ保存に失敗しました。');
        }
    } catch (Throwable $e) {
        @fclose($fp);
        @unlink($tmp);
        throw $e;
    }
}

/* =========================================================
 * 機密情報保存
 *
 * 外部環境変数には依存しない。
 * 初回にランダムなローカル秘密値を生成し、
 * _data/.secret に保存する。
 * ========================================================= */

function local_secret(): string
{
    ensure_data_dir();

    if (is_file(SECRET_FILE)) {
        $v = trim((string)@file_get_contents(SECRET_FILE));

        if ($v !== '') {
            return $v;
        }
    }

    $secret = bin2hex(random_bytes(32));

    if (@file_put_contents(
        SECRET_FILE,
        $secret,
        LOCK_EX
    ) === false) {
        throw new RuntimeException(
            '認証情報保存用のローカル領域を作成できません。'
        );
    }

    @chmod(SECRET_FILE, 0600);

    return $secret;
}

function secret_key(): string
{
    return hash(
        'sha256',
        local_secret(),
        true
    );
}

function protect_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    $key = secret_key();

    $iv = random_bytes(
        openssl_cipher_iv_length('aes-256-gcm')
    );

    $tag = '';

    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cipher === false) {
        throw new RuntimeException(
            '機密情報を保存できません。'
        );
    }

    return base64_encode(
        json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($cipher),
        ], JSON_UNESCAPED_SLASHES)
    );
}

function reveal_secret(string $value): string
{
    if ($value === '') {
        return '';
    }

    $decoded = base64_decode($value, true);

    if ($decoded === false) {
        return '';
    }

    $payload = json_decode($decoded, true);

    if (!is_array($payload)) {
        return '';
    }

    if (($payload['v'] ?? 0) !== 1) {
        return '';
    }

    $iv = base64_decode(
        (string)($payload['iv'] ?? ''),
        true
    );

    $tag = base64_decode(
        (string)($payload['tag'] ?? ''),
        true
    );

    $data = base64_decode(
        (string)($payload['data'] ?? ''),
        true
    );

    if ($iv === false || $tag === false || $data === false) {
        return '';
    }

    $plain = openssl_decrypt(
        $data,
        'aes-256-gcm',
        secret_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    return $plain === false ? '' : $plain;
}

/* =========================================================
 * データ初期値
 * ========================================================= */

function default_data(): array
{
    return [
        'surveys' => [],
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
            'fields' => [
                'organization' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
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

function load_settings(): array
{
    $base = default_settings();
    $saved = load_json(SET_FILE, $base);

    return array_replace_recursive($base, $saved);
}

function save_settings(array $settings): void
{
    save_json(SET_FILE, $settings);
}

/* =========================================================
 * アンケート
 * ========================================================= */

function survey_get(array $surveys, string $id): ?array
{
    foreach ($surveys as $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function survey_index(array $surveys, string $id): int
{
    foreach ($surveys as $i => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return (int)$i;
        }
    }

    return -1;
}

function survey_status(array &$survey): string
{
    $status = (string)($survey['status'] ?? 'draft');
    $end = (string)($survey['endAt'] ?? '');

    if (
        $status === 'published' &&
        $end !== ''
    ) {
        $ts = strtotime($end);

        if ($ts !== false && $ts < time()) {
            $survey['status'] = 'ended';
            return 'ended';
        }
    }

    return $status;
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

function normalize_type(string $type): string
{
    return match ($type) {
        'multiple' => 'multiple',
        'text' => 'text',
        default => 'single',
    };
}

function new_option(): array
{
    return [
        'id' => id_new('opt'),
        'label' => '',
        'next' => '',
    ];
}

function new_question(): array
{
    return [
        'id' => id_new('q'),
        'number' => '',
        'text' => '',
        'type' => 'single',
        'required' => false,
        'options' => [
            new_option(),
            new_option(),
        ],
    ];
}

function new_group(): array
{
    return [
        'id' => id_new('group'),
        'title' => 'グループ',
        'questions' => [
            new_question(),
        ],
    ];
}

function new_survey(): array
{
    $time = date('Y-m-d\TH:i');

    return [
        'id' => id_new('survey'),
        'title' => '',
        'description' => '',
        'startAt' => $time,
        'endAt' => '',
        'numbering' => 'global',
        'status' => 'draft',
        'createdAt' => now(),
        'updatedAt' => now(),
        'groups' => [
            new_group(),
        ],
    ];
}

function recalc_numbers(array &$survey): void
{
    $groups = $survey['groups'] ?? [];

    if (!is_array($groups)) {
        $survey['groups'] = [];
        return;
    }

    if (($survey['numbering'] ?? 'global') === 'group') {
        foreach ($groups as $gi => &$group) {
            $qi = 1;

            foreach (($group['questions'] ?? []) as &$q) {
                $q['number'] = 'Q' . ($gi + 1) . '-' . $qi;
                $qi++;
            }
            unset($q);
        }
        unset($group);
    } else {
        $n = 1;

        foreach ($groups as &$group) {
            foreach (($group['questions'] ?? []) as &$q) {
                $q['number'] = 'Q' . $n;
                $n++;
            }
            unset($q);
        }
        unset($group);
    }

    $survey['groups'] = $groups;
}

function normalize_survey_from_post(
    array $old,
    array $raw
): array {
    $survey = $old;

    $survey['title'] = trim(
        (string)($raw['title'] ?? '')
    );

    $survey['description'] = trim(
        (string)($raw['description'] ?? '')
    );

    $survey['startAt'] = trim(
        (string)($raw['startAt'] ?? '')
    );

    $survey['endAt'] = trim(
        (string)($raw['endAt'] ?? '')
    );

    $survey['numbering'] =
        (($raw['numbering'] ?? 'global') === 'group')
        ? 'group'
        : 'global';

    $groupsRaw = $raw['groups'] ?? [];
    $groups = [];

    if (is_array($groupsRaw)) {
        foreach ($groupsRaw as $groupRaw) {
            if (!is_array($groupRaw)) {
                continue;
            }

            $gid = trim(
                (string)($groupRaw['id'] ?? id_new('group'))
            );

            if ($gid === '') {
                $gid = id_new('group');
            }

            $group = [
                'id' => $gid,
                'title' => trim(
                    (string)($groupRaw['title'] ?? 'グループ')
                ),
                'questions' => [],
            ];

            $questionsRaw = $groupRaw['questions'] ?? [];

            if (is_array($questionsRaw)) {
                foreach ($questionsRaw as $qRaw) {
                    if (!is_array($qRaw)) {
                        continue;
                    }

                    $qid = trim(
                        (string)($qRaw['id'] ?? id_new('q'))
                    );

                    if ($qid === '') {
                        $qid = id_new('q');
                    }

                    $type = normalize_type(
                        (string)($qRaw['type'] ?? 'single')
                    );

                    $q = [
                        'id' => $qid,
                        'number' => '',
                        'text' => trim(
                            (string)($qRaw['text'] ?? '')
                        ),
                        'type' => $type,
                        'required' =>
                            isset($qRaw['required'])
                            && (
                                $qRaw['required'] === '1' ||
                                $qRaw['required'] === 1 ||
                                $qRaw['required'] === true
                            ),
                        'options' => [],
                    ];

                    if ($type !== 'text') {
                        $optionsRaw = $qRaw['options'] ?? [];

                        if (is_array($optionsRaw)) {
                            foreach ($optionsRaw as $oRaw) {
                                if (!is_array($oRaw)) {
                                    continue;
                                }

                                $oid = trim(
                                    (string)($oRaw['id'] ?? id_new('opt'))
                                );

                                if ($oid === '') {
                                    $oid = id_new('opt');
                                }

                                $q['options'][] = [
                                    'id' => $oid,
                                    'label' => trim(
                                        (string)($oRaw['label'] ?? '')
                                    ),
                                    'next' => trim(
                                        (string)($oRaw['next'] ?? '')
                                    ),
                                ];
                            }
                        }

                        if (!$q['options']) {
                            $q['options'][] = new_option();
                        }
                    }

                    $group['questions'][] = $q;
                }
            }

            $groups[] = $group;
        }
    }

    if (!$groups) {
        $groups[] = new_group();
    }

    $survey['groups'] = $groups;

    recalc_numbers($survey);

    return $survey;
}

function validate_survey(array $survey): array
{
    $errors = [];

    $title = trim((string)($survey['title'] ?? ''));

    if ($title === '') {
        $errors[] = 'アンケートタイトルは必須です。';
    } elseif (mb_strlen($title) > MAX_TITLE) {
        $errors[] = 'アンケートタイトルが長すぎます。';
    }

    if (
        mb_strlen(
            (string)($survey['description'] ?? '')
        ) > MAX_DESCRIPTION
    ) {
        $errors[] = 'アンケート説明が長すぎます。';
    }

    $start = (string)($survey['startAt'] ?? '');
    $end = (string)($survey['endAt'] ?? '');

    if ($start !== false && $start !== '') {
        if (strtotime($start) === false) {
            $errors[] = '開始日時が不正です。';
        }
    }

    if ($end !== '') {
        if (strtotime($end) === false) {
            $errors[] = '終了日時が不正です。';
        }
    }

    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $q) {
            if (
                trim((string)($q['text'] ?? '')) === ''
            ) {
                $errors[] =
                    '質問文が未入力の質問があります。';
            }

            if (
                mb_strlen((string)($q['text'] ?? ''))
                > MAX_QUESTION
            ) {
                $errors[] =
                    '質問文が長すぎます。';
            }

            if (
                in_array(
                    $q['type'] ?? '',
                    ['single', 'multiple'],
                    true
                )
            ) {
                foreach ($q['options'] ?? [] as $o) {
                    if (
                        mb_strlen(
                            (string)($o['label'] ?? '')
                        ) > MAX_OPTION
                    ) {
                        $errors[] =
                            '選択肢が長すぎます。';
                    }
                }
            }
        }
    }

    return $errors;
}

/* =========================================================
 * kintone
 * ========================================================= */

function normalize_kintone_host(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (!preg_match(
        '~^https?://~i',
        $value
    )) {
        $value = 'https://' . $value;
    }

    $parts = parse_url($value);

    if (!$parts || empty($parts['host'])) {
        return '';
    }

    $host = strtolower($parts['host']);

    if (!preg_match(
        '/^[a-z0-9][a-z0-9.-]*\.cybozu\.com$/i',
        $host
    )) {
        return '';
    }

    return 'https://' . $host;
}

function kintone_settings(
    array $settings
): array {
    $k = $settings['kintone'] ?? [];

    return [
        'base' => normalize_kintone_host(
            (string)($k['subdomain'] ?? '')
        ),
        'app' => (string)($k['app_id'] ?? ''),
        'username' => (string)($k['username'] ?? ''),
        'password' => reveal_secret(
            (string)($k['password'] ?? '')
        ),
        'proxy' => trim(
            (string)($k['proxy'] ?? '')
        ),
        'verify_ssl' =>
            !empty($k['verify_ssl']),
    ];
}

function validate_kintone_config(
    array $cfg
): array {
    $errors = [];

    if ($cfg['base'] === '') {
        $errors[] = 'kintoneサブドメインが不正です。';
    }

    if (
        !preg_match(
            '/^\d+$/',
            (string)$cfg['app']
        )
    ) {
        $errors[] = '顧客管理アプリIDが不正です。';
    }

    if ($cfg['username'] === '') {
        $errors[] = 'ログイン名を入力してください。';
    }

    if ($cfg['password'] === '') {
        $errors[] = 'パスワードを入力してください。';
    }

    if (
        $cfg['proxy'] !== '' &&
        !preg_match(
            '/^[^:\s]+:\d+$/',
            $cfg['proxy']
        )
    ) {
        $errors[] = 'Proxyはhost:port形式で入力してください。';
    }

    return $errors;
}

function kintone_auth_header(
    string $username,
    string $password
): string {
    return base64_encode(
        $username . ':' . $password
    );
}

function http_request(
    string $url,
    string $method = 'GET',
    array $headers = [],
    ?string $body = null,
    ?string $proxy = null,
    bool $verifySsl = false
): array {
    $parts = parse_url($url);

    if (!$parts || empty($parts['host'])) {
        return [
            'ok' => false,
            'status' => 0,
            'body' => '',
            'error' => 'URLが不正です。',
        ];
    }

    $scheme = strtolower(
        (string)($parts['scheme'] ?? 'https')
    );

    $host = $parts['host'];
    $port = (int)($parts['port'] ?? (
        $scheme === 'https' ? 443 : 80
    ));

    $path =
        ($parts['path'] ?? '/') .
        (
            isset($parts['query'])
            ? '?' . $parts['query']
            : ''
        );

    $transport =
        $scheme === 'https'
        ? 'ssl://'
        : '';

    $connectHost = $host;
    $connectPort = $port;

    if ($proxy !== null && $proxy !== '') {
        [$phost, $pport] = explode(
            ':',
            $proxy,
            2
        );

        $connectHost = $phost;
        $connectPort = (int)$pport;
        $transport = '';
    }

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $transport . $connectHost . ':' . $connectPort,
        $errno,
        $errstr,
        KINTONE_CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if (!$fp) {
        return [
            'ok' => false,
            'status' => 0,
            'body' => '',
            'error' =>
                '接続できません: ' .
                $errstr,
        ];
    }

    stream_set_timeout(
        $fp,
        KINTONE_READ_TIMEOUT
    );

    if ($proxy !== null && $proxy !== '') {
        $connectRequest =
            "CONNECT {$host}:{$port} HTTP/1.1\r\n" .
            "Host: {$host}:{$port}\r\n" .
            "Proxy-Connection: Keep-Alive\r\n" .
            "\r\n";

        fwrite($fp, $connectRequest);

        $proxyResponse = '';

        while (!feof($fp)) {
            $line = fgets($fp);

            if ($line === false) {
                break;
            }

            $proxyResponse .= $line;

            if (rtrim($line, "\r\n") === '') {
                break;
            }
        }

        if (
            !preg_match(
                '/^HTTP\/\d(?:\.\d)?\s+200\b/i',
                $proxyResponse
            )
        ) {
            fclose($fp);

            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' =>
                    'Proxy接続に失敗しました。',
            ];
        }

        if ($scheme === 'https') {
            $crypto = @stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                fclose($fp);

                return [
                    'ok' => false,
                    'status' => 0,
                    'body' => '',
                    'error' =>
                        'Proxy経由のTLS接続に失敗しました。',
                ];
            }
        }
    }

    if (
        $scheme === 'https' &&
        $proxy === null
    ) {
        /*
         * ssl://接続時の証明書検証を設定。
         * POCでは無効を選択可能。
         */
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl,
                'allow_self_signed' => !$verifySsl,
                'peer_name' => $host,
            ],
        ]);

        /*
         * 既に接続済みの場合、コンテキストを後付けできないため
         * 検証有効時はここで明示的TLSへ切り替えられない。
         * POCの標準値は無効。
         */
        unset($context);
    }

    $headers[] = 'Host: ' . $host;
    $headers[] = 'Connection: close';

    if ($body !== null) {
        $headers[] = 'Content-Length: ' .
            strlen($body);
    }

    $request =
        $method . ' ' . $path . " HTTP/1.1\r\n" .
        implode("\r\n", $headers) .
        "\r\n\r\n";

    if ($body !== null) {
        $request .= $body;
    }

    fwrite($fp, $request);

    $raw = '';

    while (!feof($fp)) {
        $chunk = fread($fp, 8192);

        if ($chunk === false) {
            break;
        }

        $raw .= $chunk;

        $meta = stream_get_meta_data($fp);

        if (!empty($meta['timed_out'])) {
            fclose($fp);

            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => '通信がタイムアウトしました。',
            ];
        }
    }

    fclose($fp);

    [$headerRaw, $bodyRaw] =
        array_pad(
            preg_split(
                "/\r\n\r\n/",
                $raw,
                2
            ),
            2,
            ''
        );

    $status = 0;

    if (preg_match(
        '/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i',
        $headerRaw,
        $m
    )) {
        $status = (int)$m[1];
    }

    return [
        'ok' =>
            $status >= 200 &&
            $status < 300,
        'status' => $status,
        'body' => $bodyRaw,
        'error' => '',
    ];
}

function kintone_request(
    array $cfg,
    string $path,
    string $method = 'GET',
    ?array $json = null
): array {
    $url = $cfg['base'] . $path;

    $headers = [
        'X-Cybozu-Authorization: ' .
            kintone_auth_header(
                $cfg['username'],
                $cfg['password']
            ),
        'Accept: application/json',
    ];

    $body = null;

    if ($json !== null) {
        $body = json_encode(
            $json,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $headers[] =
            'Content-Type: application/json';
    }

    $res = http_request(
        $url,
        $method,
        $headers,
        $body,
        $cfg['proxy'] ?: null,
        $cfg['verify_ssl']
    );

    $decoded = null;

    if ($res['body'] !== '') {
        $decoded = json_decode(
            $res['body'],
            true
        );
    }

    $message = '';

    if (is_array($decoded)) {
        $message =
            (string)($decoded['message'] ?? '');

        if ($message === '') {
            $message =
                (string)($decoded['error'] ?? '');
        }
    }

    if (!$res['ok']) {
        if ($res['status'] >= 300 && $res['status'] < 400) {
            $message =
                'kintoneからリダイレクト応答が返されました。';
        } elseif ($res['status'] === 401) {
            $message =
                'kintone認証に失敗しました。ログイン名・パスワードを確認してください。';
        } elseif ($res['status'] === 403) {
            $message =
                'kintoneへのアクセス権限がありません。';
        } elseif ($res['status'] === 404) {
            $message =
                'kintone APIまたはアプリが見つかりません。';
        } elseif ($message === '') {
            $message =
                $res['error'] !== ''
                ? $res['error']
                : 'kintone API通信に失敗しました。';
        }
    }

    return [
        'ok' => $res['ok'],
        'status' => $res['status'],
        'data' => $decoded,
        'body' => $res['body'],
        'error' => $message,
    ];
}

function kintone_test(array $cfg): array
{
    return kintone_request(
        $cfg,
        '/k/v1/app.json?id=' .
        rawurlencode($cfg['app'])
    );
}

function kintone_fields(array $cfg): array
{
    return kintone_request(
        $cfg,
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode($cfg['app'])
    );
}

function kintone_records(
    array $cfg,
    int $offset = 0
): array {
    return kintone_request(
        $cfg,
        '/k/v1/records.json?app=' .
        rawurlencode($cfg['app']) .
        '&totalCount=true' .
        '&query=' .
        rawurlencode(
            'limit 500 offset ' . $offset
        )
    );
}

function kintone_all_records(
    array $cfg
): array {
    $records = [];
    $offset = 0;

    while (true) {
        $res = kintone_records(
            $cfg,
            $offset
        );

        if (!$res['ok']) {
            return $res;
        }

        $rows = $res['data']['records'] ?? [];

        if (!is_array($rows)) {
            return [
                'ok' => false,
                'status' => $res['status'],
                'data' => null,
                'body' => $res['body'],
                'error' =>
                    'kintoneの顧客レコード形式が不正です。',
            ];
        }

        $records = array_merge(
            $records,
            $rows
        );

        if (count($rows) < 500) {
            break;
        }

        $offset += 500;

        if ($offset > 100000) {
            break;
        }
    }

    $res['data']['records'] = $records;
    return $res;
}

function kintone_field_list(
    array $fieldsResponse
): array {
    $fields =
        $fieldsResponse['data']['properties']
        ?? [];

    if (!is_array($fields)) {
        return [];
    }

    $result = [];

    foreach ($fields as $code => $field) {
        if (!is_array($field)) {
            continue;
        }

        if (
            isset($field['enabled']) &&
            $field['enabled'] === false
        ) {
            continue;
        }

        $result[] = [
            'code' => (string)$code,
            'label' =>
                (string)($field['label'] ?? $code),
            'type' =>
                (string)($field['type'] ?? ''),
        ];
    }

    return $result;
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
        $parts = [];

        foreach ($value as $v) {
            if (is_array($v)) {
                $parts[] =
                    (string)($v['name']
                    ?? $v['code']
                    ?? $v['value']
                    ?? '');
            } else {
                $parts[] = (string)$v;
            }
        }

        return implode(' ', $parts);
    }

    return trim((string)$value);
}

function sync_customers(
    array $cfg,
    array $mapping
): array {
    $res = kintone_all_records($cfg);

    if (!$res['ok']) {
        return $res;
    }

    $records = $res['data']['records'] ?? [];
    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $addressParts = [];

        foreach (
            ($mapping['address'] ?? []) as $code
        ) {
            $code = (string)$code;

            if ($code !== '') {
                $v = kintone_value(
                    $record,
                    $code
                );

                if ($v !== '') {
                    $addressParts[] = $v;
                }
            }
        }

        $customers[] = [
            'id' =>
                kintone_value(
                    $record,
                    '$id'
                ) ?: id_new('customer'),
            'organization' =>
                kintone_value(
                    $record,
                    (string)($mapping['organization'] ?? '')
                ),
            'name' =>
                kintone_value(
                    $record,
                    (string)($mapping['name'] ?? '')
                ),
            'email' =>
                kintone_value(
                    $record,
                    (string)($mapping['email'] ?? '')
                ),
            'department' =>
                kintone_value(
                    $record,
                    (string)($mapping['department'] ?? '')
                ),
            'phone' =>
                kintone_value(
                    $record,
                    (string)($mapping['phone'] ?? '')
                ),
            'address' =>
                implode(' ', $addressParts),
            'syncedAt' => now(),
        ];
    }

    return [
        'ok' => true,
        'status' => $res['status'],
        'customers' => $customers,
        'count' => count($customers),
        'error' => '',
    ];
}

/* =========================================================
 * SMTP
 * ========================================================= */

function smtp_read($fp): array
{
    $lines = [];
    $code = 0;

    while (!feof($fp)) {
        $line = fgets($fp);

        if ($line === false) {
            break;
        }

        $lines[] = rtrim($line, "\r\n");

        if (preg_match(
            '/^(\d{3})([\s-])/',
            $line,
            $m
        )) {
            $code = (int)$m[1];

            if ($m[2] === ' ') {
                break;
            }
        }
    }

    $meta = stream_get_meta_data($fp);

    if (!empty($meta['timed_out'])) {
        throw new RuntimeException(
            'SMTP通信がタイムアウトしました。'
        );
    }

    return [
        'code' => $code,
        'text' => implode("\n", $lines),
    ];
}

function smtp_command(
    $fp,
    string $command,
    array $expected
): array {
    fwrite($fp, $command . "\r\n");

    $response = smtp_read($fp);

    if (
        !in_array(
            $response['code'],
            $expected,
            true
        )
    ) {
        throw new RuntimeException(
            'SMTPエラー: ' .
            $response['code']
        );
    }

    return $response;
}

function smtp_open(
    array $cfg
) {
    $host = trim((string)$cfg['host']);
    $port = (int)$cfg['port'];
    $encryption = strtolower(
        (string)$cfg['encryption']
    );

    if ($host === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException(
            'SMTPサーバ設定が不正です。'
        );
    }

    $transport =
        $encryption === 'ssl'
        ? 'ssl://'
        : '';

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        SMTP_CONNECT_TIMEOUT
    );

    if (!$fp) {
        throw new RuntimeException(
            'SMTP接続失敗: ' . $errstr
        );
    }

    stream_set_timeout(
        $fp,
        SMTP_READ_TIMEOUT
    );

    $greeting = smtp_read($fp);

    if ($greeting['code'] !== 220) {
        fclose($fp);

        throw new RuntimeException(
            'SMTP greeting error: ' .
            $greeting['code']
        );
    }

    smtp_command(
        $fp,
        'EHLO localhost',
        [250]
    );

    if ($encryption === 'tls') {
        smtp_command(
            $fp,
            'STARTTLS',
            [220]
        );

        $ok = @stream_socket_enable_crypto(
            $fp,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($ok !== true) {
            fclose($fp);

            throw new RuntimeException(
                'SMTP TLS接続に失敗しました。'
            );
        }

        smtp_command(
            $fp,
            'EHLO localhost',
            [250]
        );
    }

    if (!empty($cfg['auth'])) {
        $username = (string)$cfg['username'];
        $password = (string)$cfg['password'];

        if ($username === '' || $password === '') {
            fclose($fp);

            throw new RuntimeException(
                'SMTP認証情報が未設定です。'
            );
        }

        smtp_command(
            $fp,
            'AUTH LOGIN',
            [334]
        );

        smtp_command(
            $fp,
            base64_encode($username),
            [334]
        );

        smtp_command(
            $fp,
            base64_encode($password),
            [235]
        );
    }

    return $fp;
}

function smtp_test(array $cfg): array
{
    try {
        $fp = smtp_open($cfg);

        smtp_command(
            $fp,
            'QUIT',
            [221]
        );

        fclose($fp);

        return [
            'ok' => true,
            'error' => '',
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }
}

function mime_header(string $value): string
{
    return '=?UTF-8?B?' .
        base64_encode($value) .
        '?=';
}

function smtp_send_mail(
    array $cfg,
    string $to,
    string $subject,
    string $body
): array {
    try {
        if (!filter_var(
            $to,
            FILTER_VALIDATE_EMAIL
        )) {
            throw new RuntimeException(
                '宛先メールアドレスが不正です。'
            );
        }

        $from = (string)$cfg['from_email'];

        if (!filter_var(
            $from,
            FILTER_VALIDATE_EMAIL
        )) {
            throw new RuntimeException(
                '送信元メールアドレスが不正です。'
            );
        }

        $fp = smtp_open($cfg);

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

        smtp_command(
            $fp,
            'DATA',
            [354]
        );

        $fromName = (string)$cfg['from_name'];
        $reply = (string)$cfg['reply_to'];

        $headers = [
            'From: ' .
                (
                    $fromName !== ''
                    ? mime_header($fromName) . ' '
                    : ''
                ) .
                '<' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . mime_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if (
            $reply !== '' &&
            filter_var(
                $reply,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $headers[] =
                'Reply-To: <' . $reply . '>';
        }

        $payload =
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            preg_replace(
                '/^\./m',
                '..',
                str_replace(
                    ["\r\n", "\r"],
                    "\n",
                    $body
                )
            ) .
            "\r\n.";

        smtp_command(
            $fp,
            $payload,
            [250]
        );

        smtp_command(
            $fp,
            'QUIT',
            [221]
        );

        fclose($fp);

        return [
            'ok' => true,
            'error' => '',
        ];
    } catch (Throwable $e) {
        if (isset($fp) && is_resource($fp)) {
            fclose($fp);
        }

        return [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }
}

/* =========================================================
 * アンケートURL
 * ========================================================= */

function survey_url(string $id): string
{
    $scheme =
        (!empty($_SERVER['HTTPS']) &&
        strtolower((string)$_SERVER['HTTPS']) !== 'off')
        ? 'https'
        : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme .
        '://' .
        $host .
        dirname($_SERVER['SCRIPT_NAME'] ?? '/')
        . '/index.php?screen=answer&id=' .
        rawurlencode($id);
}

/* =========================================================
 * 回答
 * ========================================================= */

function answer_question_list(
    array $survey
): array {
    $result = [];

    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $q) {
            $result[] = $q;
        }
    }

    return $result;
}

function answer_visible(
    array $survey,
    array $answers,
    array $question
): bool {
    /*
     * 単一選択の条件分岐:
     * option.next に質問IDが設定されている場合、
     * 次の表示対象を制御する。
     *
     * 分岐先指定のない質問は通常表示。
     */
    foreach (
        answer_question_list($survey) as $q
    ) {
        foreach ($q['options'] ?? [] as $option) {
            if (
                ($option['next'] ?? '') ===
                ($question['id'] ?? '')
            ) {
                $source = $q['id'] ?? '';

                if (
                    !array_key_exists(
                        $source,
                        $answers
                    )
                ) {
                    return false;
                }

                $value = $answers[$source];

                if (
                    is_string($value) &&
                    $value === ($option['id'] ?? '')
                ) {
                    return true;
                }
            }
        }
    }

    /*
     * 分岐先になっていない質問は表示。
     */
    $hasIncoming = false;

    foreach (
        answer_question_list($survey) as $q
    ) {
        foreach ($q['options'] ?? [] as $option) {
            if (
                ($option['next'] ?? '') ===
                ($question['id'] ?? '')
            ) {
                $hasIncoming = true;
            }
        }
    }

    return !$hasIncoming;
}

/* =========================================================
 * HTML
 * ========================================================= */

function css(): void
{
    ?>
<style>
*{box-sizing:border-box}
body{
 margin:0;
 background:#f5f7fb;
 color:#172033;
 font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
 "Hiragino Kaku Gothic ProN","Yu Gothic",Meiryo,sans-serif;
 line-height:1.6
}
a{color:#1769aa;text-decoration:none}
.container{max-width:1280px;margin:0 auto;padding:24px}
header{
 background:#182536;color:#fff;padding:14px 24px;
 display:flex;align-items:center;justify-content:space-between
}
header a{color:#fff}
nav a{margin-left:14px}
.card{
 background:#fff;border:1px solid #dce2eb;border-radius:10px;
 padding:20px;margin-bottom:18px;box-shadow:0 1px 2px #0000000a
}
.toolbar,.actions{
 display:flex;gap:8px;align-items:center;flex-wrap:wrap
}
button,.btn{
 display:inline-block;border:1px solid #b9c3d0;
 background:#fff;color:#182536;padding:8px 14px;
 border-radius:7px;cursor:pointer;font-size:14px
}
button.primary,.btn.primary{background:#1769aa;color:#fff;border-color:#1769aa}
button.danger,.btn.danger{background:#c62828;color:#fff;border-color:#c62828}
button.warn,.btn.warn{background:#e68a00;color:#fff;border-color:#e68a00}
button.small,.btn.small{padding:5px 9px;font-size:12px}
button:disabled{opacity:.45;cursor:not-allowed}
input,textarea,select{
 width:100%;padding:9px 10px;border:1px solid #bcc6d3;
 border-radius:6px;background:#fff
}
textarea{min-height:110px;resize:vertical}
label{display:block;font-weight:600;margin-bottom:5px}
.field{margin-bottom:14px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.table-wrap{overflow:auto}
table{border-collapse:collapse;width:100%;min-width:900px}
th,td{padding:10px;border-bottom:1px solid #e2e7ee;text-align:left;vertical-align:top}
th{background:#f0f3f7}
.badge{display:inline-block;padding:3px 8px;border-radius:99px;font-size:12px}
.badge.draft{background:#e8edf3}
.badge.published{background:#d9f4e2;color:#176b35}
.badge.stopped{background:#fff0cc;color:#855900}
.badge.ended{background:#eee;color:#666}
.notice{padding:12px;border-radius:7px;margin-bottom:14px}
.notice.success{background:#e2f6e9;color:#175b2c}
.notice.error{background:#fde5e5;color:#8d1e1e}
.notice.info{background:#e4f0fb;color:#164b78}
.question,.group{
 border:1px solid #d8dee8;border-radius:9px;padding:15px;
 background:#fbfcfe;margin-bottom:12px
}
.group{background:#f4f7fa}
.group-head,.question-head{
 display:flex;gap:8px;align-items:center;justify-content:space-between;
 margin-bottom:12px
}
.drag-handle{cursor:grab;color:#78879a;font-size:18px}
.option-row{display:grid;grid-template-columns:1fr 160px 70px;gap:8px;margin:6px 0}
.preview-box{max-width:850px;margin:auto}
.answer-card{max-width:720px;margin:25px auto}
.stat{
 padding:16px;border:1px solid #dce2eb;border-radius:8px;
 background:#fff
}
.stat strong{display:block;font-size:25px}
@media(max-width:800px){
 .container{padding:12px}
 .grid2,.grid3{grid-template-columns:1fr}
 .option-row{grid-template-columns:1fr}
 header{padding:12px;display:block}
 nav{margin-top:8px}
}
</style>
<?php
}

function admin_header(string $title): void
{
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - <?= h(APP_TITLE) ?></title>
<?php css(); ?>
</head>
<body>
<header>
<div>
<a href="index.php?screen=list"><strong><?= h(APP_TITLE) ?></strong></a>
</div>
<nav>
<a href="index.php?screen=list">アンケート一覧</a>
<a href="index.php?screen=kintone">kintone設定</a>
<a href="index.php?screen=mail">メール設定</a>
</nav>
</header>
<div class="container">
<?php
}

function admin_footer(): void
{
    ?>
</div>
<script>
document.querySelectorAll('[data-confirm]').forEach(function(el){
 el.addEventListener('click',function(e){
  if(!window.confirm(el.getAttribute('data-confirm'))){
   e.preventDefault();
  }
 });
});

document.querySelectorAll('form[data-busy]').forEach(function(form){
 form.addEventListener('submit',function(){
  const buttons=form.querySelectorAll('button');
  buttons.forEach(function(b){b.disabled=true});
 });
});

function addQuestion(groupId){
 const group=document.querySelector(
  '[data-group="'+CSS.escape(groupId)+'"]'
 );
 if(!group)return;
 const tpl=document.getElementById('question-template');
 const html=tpl.innerHTML.replaceAll('__GROUP__',groupId)
   .replaceAll('__QID__','q-'+Date.now()+'-'+Math.random().toString(16).slice(2));
 group.querySelector('.questions').insertAdjacentHTML('beforeend',html);
}

function addOption(qid){
 const q=document.querySelector(
  '[data-question="'+CSS.escape(qid)+'"]'
 );
 if(!q)return;
 const tpl=document.getElementById('option-template');
 const html=tpl.innerHTML.replaceAll('__QID__',qid)
   .replaceAll('__OID__','o-'+Date.now()+'-'+Math.random().toString(16).slice(2));
 q.querySelector('.options').insertAdjacentHTML('beforeend',html);
}

function removeNode(button){
 const node=button.closest('[data-removable]');
 if(node)node.remove();
}

function addGroup(){
 const tpl=document.getElementById('group-template');
 const id='group-'+Date.now()+'-'+Math.random().toString(16).slice(2);
 const html=tpl.innerHTML.replaceAll('__GROUP__',id)
   .replaceAll('__QID__','q-'+Date.now());
 document.getElementById('groups').insertAdjacentHTML('beforeend',html);
}

function toggleOptions(select){
 const q=select.closest('[data-question]');
 if(!q)return;
 const box=q.querySelector('.options-wrap');
 if(box)box.style.display=select.value==='text'?'none':'block';
}

document.querySelectorAll('.sortable').forEach(function(list){
 let dragged=null;
 list.querySelectorAll('[draggable="true"]').forEach(function(item){
  item.addEventListener('dragstart',function(){
   dragged=item;
   item.style.opacity='.45';
  });
  item.addEventListener('dragend',function(){
   item.style.opacity='';
   dragged=null;
  });
  item.addEventListener('dragover',function(e){
   e.preventDefault();
   if(!dragged || dragged===item)return;
   const rect=item.getBoundingClientRect();
   if(e.clientY<rect.top+rect.height/2){
    item.before(dragged);
   }else{
    item.after(dragged);
   }
  });
 });
});

document.querySelectorAll('[data-status-action]').forEach(function(el){
 el.addEventListener('click',function(e){
  const message=el.dataset.confirm || '状態を変更しますか？';
  if(!confirm(message))e.preventDefault();
 });
});
</script>
</body>
</html>
<?php
}

/* =========================================================
 * 一覧
 * ========================================================= */

function render_list(array &$data): void
{
    foreach ($data['surveys'] as &$survey) {
        survey_status($survey);
    }
    unset($survey);

    save_data($data);

    $q = trim(
        (string)($_GET['q'] ?? '')
    );

    $filter =
        (string)($_GET['status'] ?? 'all');

    $sort =
        (string)($_GET['sort'] ?? 'updated_desc');

    $surveys = array_values(
        array_filter(
            $data['surveys'],
            function($s) use ($q, $filter) {
                $status = (string)(
                    $s['status'] ?? 'draft'
                );

                if (
                    $filter !== 'all' &&
                    $status !== $filter
                ) {
                    return false;
                }

                if (
                    $q !== '' &&
                    mb_stripos(
                        (string)($s['title'] ?? ''),
                        $q
                    ) === false
                ) {
                    return false;
                }

                return true;
            }
        )
    );

    usort(
        $surveys,
        function($a,$b) use ($sort) {
            if ($sort === 'answers_desc' ||
                $sort === 'answers_asc') {
                $aa = (int)($a['answerCount'] ?? 0);
                $bb = (int)($b['answerCount'] ?? 0);
                $r = $aa <=> $bb;
                return $sort === 'answers_desc' ? -$r : $r;
            }

            $field =
                str_starts_with($sort,'start')
                ? 'startAt'
                : 'updatedAt';

            $aa = strtotime(
                (string)($a[$field] ?? '')
            ) ?: 0;

            $bb = strtotime(
                (string)($b[$field] ?? '')
            ) ?: 0;

            $r = $aa <=> $bb;

            return str_ends_with($sort,'desc')
                ? -$r
                : $r;
        }
    );

    admin_header('アンケート一覧');

    foreach (flash_get() as $f) {
        ?>
<div class="notice <?= h($f['type']) ?>">
<?= h($f['message']) ?>
</div>
<?php
    }

    ?>
<div class="toolbar" style="justify-content:space-between;margin-bottom:16px">
<h1>アンケート一覧</h1>
<a class="btn primary"
   href="index.php?screen=edit&new=1">
アンケート作成
</a>
</div>

<div class="card">
<form method="get">
<input type="hidden" name="screen" value="list">
<div class="grid3">
<div class="field">
<label>検索</label>
<input name="q"
       value="<?= h($q) ?>"
       placeholder="タイトル部分一致">
</div>
<div class="field">
<label>ステータス</label>
<select name="status">
<?php
$filters = [
 'all'=>'すべて',
 'published'=>'公開中',
 'draft'=>'下書き',
 'stopped'=>'停止',
 'ended'=>'終了',
];
foreach($filters as $v=>$label):
?>
<option value="<?= h($v) ?>"
 <?= $filter===$v?'selected':'' ?>>
<?= h($label) ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="field">
<label>ソート</label>
<select name="sort">
<?php
$sorts=[
 'updated_desc'=>'更新日：新しい順',
 'updated_asc'=>'更新日：古い順',
 'answers_desc'=>'回答数：多い順',
 'answers_asc'=>'回答数：少ない順',
 'start_desc'=>'開始日：新しい順',
 'start_asc'=>'開始日：古い順',
];
foreach($sorts as $v=>$label):
?>
<option value="<?= h($v) ?>"
 <?= $sort===$v?'selected':'' ?>>
<?= h($label) ?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>
<button class="primary">検索</button>
</form>
</div>

<div class="card table-wrap">
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
<?php if(!$surveys): ?>
<tr>
<td colspan="7">アンケートはありません。</td>
</tr>
<?php endif; ?>

<?php foreach($surveys as $s): ?>
<?php
$id=(string)$s['id'];
$status=(string)($s['status']??'draft');
$count=0;
foreach($data['answers'] as $a){
 if(($a['surveyId']??'')===$id)$count++;
}
?>
<tr>
<td>
<strong><?= h($s['title']) ?></strong>
</td>
<td><?= h($s['createdAt']??'') ?></td>
<td><?= h($s['updatedAt']??'') ?></td>
<td>
<?= h($s['startAt']??'') ?><br>
～ <?= h($s['endAt']??'') ?>
</td>
<td>
<span class="badge <?= h($status) ?>">
<?= h(status_label($status)) ?>
</span>
</td>
<td><?= $count ?></td>
<td>
<div class="actions">
<a class="btn small"
 href="index.php?screen=edit&id=<?= rawurlencode($id) ?>">
確認・編集
</a>
<a class="btn small"
 href="index.php?screen=preview&id=<?= rawurlencode($id) ?>">
プレビュー
</a>
<a class="btn small"
 href="index.php?screen=analytics&id=<?= rawurlencode($id) ?>">
集計
</a>
<a class="btn small"
 href="index.php?screen=send&id=<?= rawurlencode($id) ?>">
送信
</a>
<a class="btn small"
 data-confirm="このアンケートを複製しますか？"
 href="index.php?screen=list&action=duplicate&id=<?= rawurlencode($id) ?>">
複製
</a>
<a class="btn small danger"
 data-confirm="このアンケートを削除しますか？"
 href="index.php?screen=list&action=delete&id=<?= rawurlencode($id) ?>">
削除
</a>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php

    admin_footer();
}

/* =========================================================
 * 編集
 * ========================================================= */

function render_edit(
    array $survey
): void {
    $isNew =
        (($survey['createdAt'] ?? '') === '');

    admin_header(
        $isNew
        ? 'アンケート作成'
        : 'アンケート編集'
    );

    foreach (flash_get() as $f) {
        ?>
<div class="notice <?= h($f['type']) ?>">
<?= h($f['message']) ?>
</div>
<?php
    }

    $status=(string)($survey['status']??'draft');
    ?>

<form method="post"
      data-busy
      action="index.php?screen=edit&<?= $isNew ? 'new=1' : 'id='.rawurlencode((string)$survey['id']) ?>">
<input type="hidden" name="action" value="save_survey">
<input type="hidden" name="id"
       value="<?= h($survey['id']??'') ?>">

<div class="toolbar" style="justify-content:space-between">
<div>
<h1><?= $isNew ? 'アンケート作成' : 'アンケート編集' ?></h1>
</div>
<div class="actions">
<a class="btn"
   href="index.php?screen=list"
   data-confirm="編集内容を破棄して戻りますか？">
キャンセル
</a>
<button class="primary">保存して一覧へ</button>
</div>
</div>

<div class="card">
<div class="grid3">
<div>
<label>状態</label>
<select name="status"
 <?= $status==='ended'?'disabled':'' ?>>
<option value="draft"
 <?= $status==='draft'?'selected':'' ?>>下書き</option>
<?php if($status!=='ended'): ?>
<option value="published"
 <?= $status==='published'?'selected':'' ?>>公開中</option>
<option value="stopped"
 <?= $status==='stopped'?'selected':'' ?>>停止</option>
<?php endif; ?>
<?php if($status==='ended'): ?>
<option value="ended" selected>終了</option>
<?php endif; ?>
</select>
<?php if($status!=='ended'): ?>
<p style="font-size:12px;color:#68768a">
保存時に状態変更の確認を行います。
</p>
<?php endif; ?>
</div>
<div>
<label>質問番号の採番方式</label>
<select name="numbering">
<option value="global"
 <?= ($survey['numbering']??'global')==='global'
 ?'selected':'' ?>>
アンケート全体で通番（Q1,Q2,Q3）
</option>
<option value="group"
 <?= ($survey['numbering']??'')==='group'
 ?'selected':'' ?>>
グループ毎（Q1-1,Q1-2,Q2-1）
</option>
</select>
</div>
</div>

<div class="field">
<label>アンケートタイトル</label>
<input name="title"
       maxlength="<?= MAX_TITLE ?>"
       required
       value="<?= h($survey['title']??'') ?>">
</div>

<div class="field">
<label>アンケート説明</label>
<textarea name="description"
          maxlength="<?= MAX_DESCRIPTION ?>"><?= h($survey['description']??'') ?></textarea>
</div>

<div class="grid2">
<div>
<label>開始日時</label>
<input type="datetime-local"
       name="startAt"
       value="<?= h($survey['startAt']??'') ?>">
</div>
<div>
<label>終了日時</label>
<input type="datetime-local"
       name="endAt"
       value="<?= h($survey['endAt']??'') ?>">
</div>
</div>
</div>

<div class="card">
<div class="toolbar" style="justify-content:space-between">
<h2>質問・グループ</h2>
<button type="button"
        onclick="addGroup()">
グループを追加
</button>
</div>

<div id="groups" class="sortable">
<?php foreach(($survey['groups']??[]) as $group): ?>
<?php $gid=(string)$group['id']; ?>
<div class="group"
     data-group="<?= h($gid) ?>"
     draggable="true"
     data-removable>
<div class="group-head">
<div class="toolbar">
<span class="drag-handle">☷</span>
<strong>グループ</strong>
</div>
<button type="button"
        class="danger small"
        onclick="if(confirm('グループを削除しますか？'))removeNode(this)">
グループ削除
</button>
</div>

<input type="hidden"
       name="groups[<?= h($gid) ?>][id]"
       value="<?= h($gid) ?>">

<div class="field">
<label>グループタイトル</label>
<input name="groups[<?= h($gid) ?>][title]"
       value="<?= h($group['title']??'') ?>">
</div>

<div class="questions sortable">
<?php foreach(($group['questions']??[]) as $q): ?>
<?php $qid=(string)$q['id']; ?>
<div class="question"
     data-question="<?= h($qid) ?>"
     draggable="true"
     data-removable>

<div class="question-head">
<div>
<span class="drag-handle">☷</span>
<strong><?= h($q['number']??'') ?></strong>
</div>
<button type="button"
        class="danger small"
        onclick="if(confirm('質問を削除しますか？'))removeNode(this)">
質問削除
</button>
</div>

<input type="hidden"
       name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][id]"
       value="<?= h($qid) ?>">

<div class="field">
<label>質問文</label>
<textarea name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][text]"
          maxlength="<?= MAX_QUESTION ?>"
          required><?= h($q['text']??'') ?></textarea>
</div>

<div class="grid2">
<div>
<label>回答形式</label>
<select name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][type]"
        onchange="toggleOptions(this)">
<option value="single"
 <?= ($q['type']??'')==='single'?'selected':'' ?>>
単一選択
</option>
<option value="multiple"
 <?= ($q['type']??'')==='multiple'?'selected':'' ?>>
複数選択
</option>
<option value="text"
 <?= ($q['type']??'')==='text'?'selected':'' ?>>
自由記述
</option>
</select>
</div>
<div style="padding-top:30px">
<label>
<input type="checkbox"
 name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][required]"
 value="1"
 <?= !empty($q['required'])?'checked':'' ?>>
必須
</label>
</div>
</div>

<div class="options-wrap"
 style="<?= ($q['type']??'')==='text'?'display:none':'' ?>">
<div class="toolbar"
     style="justify-content:space-between">
<strong>選択肢</strong>
<button type="button"
        class="small"
        onclick="addOption('<?= h($qid) ?>')">
選択肢追加
</button>
</div>

<div class="options">
<?php foreach(($q['options']??[]) as $o): ?>
<?php $oid=(string)$o['id']; ?>
<div class="option-row"
     data-removable>
<input type="text"
       name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][options][<?= h($oid) ?>][label]"
       value="<?= h($o['label']??'') ?>"
       placeholder="選択肢">
<select name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][options][<?= h($oid) ?>][next]">
<option value="">条件分岐なし</option>
<?php foreach(
 answer_question_list($survey) as $target
): ?>
<?php if(($target['id']??'')!==$qid): ?>
<option value="<?= h($target['id']) ?>"
 <?= ($o['next']??'')===($target['id']??'')
 ?'selected':'' ?>>
<?= h($target['number'].' '.$target['text']) ?>
</option>
<?php endif; ?>
<?php endforeach; ?>
</select>
<button type="button"
        class="danger small"
        onclick="removeNode(this)">
削除
</button>
<input type="hidden"
       name="groups[<?= h($gid) ?>][questions][<?= h($qid) ?>][options][<?= h($oid) ?>][id]"
       value="<?= h($oid) ?>">
</div>
<?php endforeach; ?>
</div>
</div>
</div>
<?php endforeach; ?>
</div>

<button type="button"
        onclick="addQuestion('<?= h($gid) ?>')">
質問を追加
</button>
</div>
<?php endforeach; ?>
</div>
</div>

<div class="actions">
<a class="btn"
   href="index.php?screen=list"
   data-confirm="編集内容を破棄して戻りますか？">
キャンセル
</a>
<button class="primary">保存して一覧へ</button>
</div>
</form>

<div id="question-template" style="display:none">
<div class="question" data-question="__QID__"
 draggable="true" data-removable>
<div class="question-head">
<strong>新規質問</strong>
<button type="button" class="danger small"
 onclick="removeNode(this)">質問削除</button>
</div>
<input type="hidden"
 name="groups[__GROUP__][questions][__QID__][id]"
 value="__QID__">
<div class="field">
<label>質問文</label>
<textarea required
 name="groups[__GROUP__][questions][__QID__][text]"></textarea>
</div>
<div class="grid2">
<div>
<label>回答形式</label>
<select name="groups[__GROUP__][questions][__QID__][type]"
 onchange="toggleOptions(this)">
<option value="single">単一選択</option>
<option value="multiple">複数選択</option>
<option value="text">自由記述</option>
</select>
</div>
<div style="padding-top:30px">
<label>
<input type="checkbox"
 name="groups[__GROUP__][questions][__QID__][required]"
 value="1"> 必須
</label>
</div>
</div>
<div class="options-wrap">
<strong>選択肢</strong>
<div class="options"></div>
<button type="button" class="small"
 onclick="addOption('__QID__')">
選択肢追加
</button>
</div>
</div>
</div>

<div id="option-template" style="display:none">
<div class="option-row" data-removable>
<input type="text"
 name="groups[__GROUP__][questions][__QID__][options][__OID__][label]"
 placeholder="選択肢">
<select name="groups[__GROUP__][questions][__QID__][options][__OID__][next]">
<option value="">条件分岐なし</option>
</select>
<button type="button" class="danger small"
 onclick="removeNode(this)">削除</button>
<input type="hidden"
 name="groups[__GROUP__][questions][__QID__][options][__OID__][id]"
 value="__OID__">
</div>
</div>

<div id="group-template" style="display:none">
<div class="group" data-group="__GROUP__"
 draggable="true" data-removable>
<div class="group-head">
<strong>新規グループ</strong>
<button type="button" class="danger small"
 onclick="removeNode(this)">グループ削除</button>
</div>
<input type="hidden"
 name="groups[__GROUP__][id]"
 value="__GROUP__">
<div class="field">
<label>グループタイトル</label>
<input name="groups[__GROUP__][title]" value="グループ">
</div>
<div class="questions"></div>
<button type="button"
 onclick="addQuestion('__GROUP__')">
質問を追加
</button>
</div>
</div>
<?php

    admin_footer();
}

/* =========================================================
 * プレビュー
 * ========================================================= */

function render_preview(
    array $survey
): void {
    admin_header('アンケートプレビュー');
    ?>
<div class="toolbar">
<a class="btn"
 href="index.php?screen=edit&id=<?= rawurlencode($survey['id']) ?>">
編集へ戻る
</a>
</div>

<div class="card preview-box">
<h1><?= h($survey['title']) ?></h1>

<?php if(($survey['description']??'')!==''): ?>
<p><?= nl2br(h($survey['description'])) ?></p>
<?php endif; ?>

<?php foreach(($survey['groups']??[]) as $group): ?>
<section>
<h2><?= h($group['title']??'') ?></h2>

<?php foreach(($group['questions']??[]) as $q): ?>
<div class="question">
<h3>
<?= h($q['number']??'') ?>
<?= h($q['text']??'') ?>
<?= !empty($q['required'])?' *':'' ?>
</h3>

<?php if(($q['type']??'')==='text'): ?>
<textarea disabled placeholder="自由記述"></textarea>
<?php else: ?>
<?php foreach(($q['options']??[]) as $o): ?>
<label style="font-weight:400">
<input
 type="<?= ($q['type']??'')==='multiple'
 ? 'checkbox':'radio' ?>"
 disabled>
<?= h($o['label']??'') ?>
</label>
<?php endforeach; ?>
<?php endif; ?>
</div>
<?php endforeach; ?>
</section>
<?php endforeach; ?>
</div>
<?php
    admin_footer();
}

/* =========================================================
 * kintone設定
 * ========================================================= */

function render_kintone(
    array $settings,
    array $data
): void {
    $k=$settings['kintone'];
    $fields=$k['fields']??[];

    admin_header('kintone設定');

    foreach(flash_get() as $f):
?>
<div class="notice <?= h($f['type']) ?>">
<?= h($f['message']) ?>
</div>
<?php
    endforeach;
?>
<div class="card">
<h1>kintone連携設定</h1>

<form method="post" data-busy>
<input type="hidden" name="action" value="save_kintone">

<div class="grid2">
<div class="field">
<label>サブドメイン</label>
<input name="subdomain"
 value="<?= h($k['subdomain']??'') ?>"
 placeholder="xxxx.cybozu.com">
</div>

<div class="field">
<label>顧客管理アプリID</label>
<input name="app_id"
 value="<?= h($k['app_id']??'') ?>"
 inputmode="numeric">
</div>

<div class="field">
<label>ログイン名</label>
<input name="username"
 value="<?= h($k['username']??'') ?>"
 autocomplete="username">
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
<input name="proxy"
 value="<?= h($k['proxy']??'') ?>"
 placeholder="host:port">
</div>

<div class="field">
<label>SSL証明書検証</label>
<select name="verify_ssl">
<option value="0"
 <?= empty($k['verify_ssl'])?'selected':'' ?>>
無効（POC）
</option>
<option value="1"
 <?= !empty($k['verify_ssl'])?'selected':'' ?>>
有効
</option>
</select>
</div>
</div>

<div class="actions">
<button class="primary">設定保存</button>
</div>
</form>
</div>

<div class="card">
<h2>接続確認</h2>
<p>
設定保存と接続テストは別操作です。
</p>
<form method="post" data-busy>
<input type="hidden" name="action"
 value="test_kintone">
<button class="primary">接続テスト</button>
</form>
<?php if(!empty($k['last_test'])): ?>
<p class="notice info">
最終接続テスト：
<?= h($k['last_test']) ?>
</p>
<?php endif; ?>
</div>

<div class="card">
<h2>項目一覧を再取得</h2>
<p>
kintone顧客管理アプリのフィールド一覧を取得し、
顧客情報項目へマッピングします。
</p>
<form method="post" data-busy>
<input type="hidden" name="action"
 value="fetch_kintone_fields">
<button class="primary">項目一覧を再取得</button>
</form>

<?php
$storedFields=$k['available_fields']??[];
if($storedFields):
?>
<form method="post">
<input type="hidden" name="action"
 value="save_kintone_mapping">

<table>
<thead>
<tr><th>アプリ項目</th><th>型</th><th>顧客情報への割当</th></tr>
</thead>
<tbody>
<?php foreach($storedFields as $field): ?>
<?php
$code=(string)$field['code'];
?>
<tr>
<td>
<?= h($field['label']) ?><br>
<small><?= h($code) ?></small>
</td>
<td><?= h($field['type']) ?></td>
<td>
<label>
<input type="radio"
 name="organization"
 value="<?= h($code) ?>"
 <?= ($fields['organization']??'')===$code?'checked':'' ?>>
組織名
</label>
<label>
<input type="radio"
 name="name"
 value="<?= h($code) ?>"
 <?= ($fields['name']??'')===$code?'checked':'' ?>>
氏名
</label>
<label>
<input type="radio"
 name="email"
 value="<?= h($code) ?>"
 <?= ($fields['email']??'')===$code?'checked':'' ?>>
メールアドレス
</label>
<label>
<input type="radio"
 name="department"
 value="<?= h($code) ?>"
 <?= ($fields['department']??'')===$code?'checked':'' ?>>
部署名
</label>
<label>
<input type="radio"
 name="phone"
 value="<?= h($code) ?>"
 <?= ($fields['phone']??'')===$code?'checked':'' ?>>
電話番号
</label>
<label>
<input type="checkbox"
 name="address[]"
 value="<?= h($code) ?>"
 <?= in_array($code,$fields['address']??[],true)
 ?'checked':'' ?>>
住所
</label>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<button class="primary">マッピング保存</button>
</form>
<?php endif; ?>
</div>

<div class="card">
<h2>顧客情報を同期</h2>
<p>
kintoneの顧客管理アプリから実際に顧客情報を取得し、
同期したデータを保存して顧客一覧を表示します。
</p>
<form method="post" data-busy>
<input type="hidden" name="action"
 value="sync_kintone">
<button class="primary">顧客情報を同期</button>
</form>

<?php if(!empty($k['last_sync'])): ?>
<p class="notice info">
最終同期：
<?= h($k['last_sync']) ?>
</p>
<?php endif; ?>

<?php if($data['customers']): ?>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>組織名</th>
<th>氏名</th>
<th>メール</th>
<th>部署</th>
<th>電話</th>
<th>住所</th>
</tr>
</thead>
<tbody>
<?php foreach($data['customers'] as $c): ?>
<tr>
<td><?= h($c['organization']??'') ?></td>
<td><?= h($c['name']??'') ?></td>
<td><?= h($c['email']??'') ?></td>
<td><?= h($c['department']??'') ?></td>
<td><?= h($c['phone']??'') ?></td>
<td><?= h($c['address']??'') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<p>同期済みの顧客情報はありません。</p>
<?php endif; ?>
</div>
<?php
    admin_footer();
}

/* =========================================================
 * メール設定
 * ========================================================= */

function render_mail(
    array $settings
): void {
    $m=$settings['mail'];

    admin_header('メールサーバ設定');

    foreach(flash_get() as $f):
?>
<div class="notice <?= h($f['type']) ?>">
<?= h($f['message']) ?>
</div>
<?php endforeach; ?>

<div class="card">
<h1>メールサーバ設定</h1>

<form method="post" data-busy>
<input type="hidden" name="action"
 value="save_mail">

<div class="grid2">
<div>
<label>SMTPサーバ</label>
<input name="host"
 value="<?= h($m['host']??'') ?>">
</div>
<div>
<label>SMTPポート</label>
<input name="port"
 type="number"
 min="1" max="65535"
 value="<?= h($m['port']??587) ?>">
</div>
<div>
<label>暗号化方式</label>
<select name="encryption">
<option value="ssl"
 <?= ($m['encryption']??'')==='ssl'?'selected':'' ?>>
SSL
</option>
<option value="tls"
 <?= ($m['encryption']??'')==='tls'?'selected':'' ?>>
TLS
</option>
<option value="none"
 <?= ($m['encryption']??'')==='none'?'selected':'' ?>>
なし
</option>
</select>
</div>
<div>
<label>SMTP認証</label>
<select name="auth">
<option value="1"
 <?= !empty($m['auth'])?'selected':'' ?>>
あり
</option>
<option value="0"
 <?= empty($m['auth'])?'selected':'' ?>>
なし
</option>
</select>
</div>
<div>
<label>SMTPユーザー名</label>
<input name="username"
 value="<?= h($m['username']??'') ?>">
</div>
<div>
<label>SMTPパスワード</label>
<input type="password"
 name="password"
 autocomplete="new-password">
</div>
<div>
<label>送信元メールアドレス</label>
<input type="email"
 name="from_email"
 value="<?= h($m['from_email']??'') ?>">
</div>
<div>
<label>送信元名</label>
<input name="from_name"
 value="<?= h($m['from_name']??'') ?>">
</div>
<div>
<label>返信先メールアドレス</label>
<input type="email"
 name="reply_to"
 value="<?= h($m['reply_to']??'') ?>">
</div>
</div>

<button class="primary">設定保存</button>
</form>
</div>

<div class="card">
<h2>接続テスト</h2>
<form method="post" data-busy>
<input type="hidden" name="action"
 value="test_mail">
<button class="primary">接続テスト</button>
</form>

<?php if(!empty($m['last_test'])): ?>
<p class="notice info">
最終接続テスト：
<?= h($m['last_test']) ?>
</p>
<?php endif; ?>
</div>

<div class="card">
<h2>テストメール送信</h2>
<form method="post" data-busy>
<input type="hidden" name="action"
 value="test_mail_send">
<div class="field">
<label>送信先</label>
<input type="email"
 name="to"
 required>
</div>
<div class="field">
<label>件名</label>
<input name="subject"
 value="アンケートアプリ テストメール"
 required>
</div>
<div class="field">
<label>本文</label>
<textarea name="body">メールサーバのテスト送信です。</textarea>
</div>
<button class="primary">テストメール送信</button>
</form>
</div>
<?php
    admin_footer();
}

/* =========================================================
 * 送信
 * ========================================================= */

function render_send(
    array $data,
    string $id
): void {
    $survey=survey_get(
        $data['surveys'],
        $id
    );

    if(!$survey){
        render_error('対象アンケートが見つかりません。');
        return;
    }

    admin_header('顧客選択・メール送信');

    foreach(flash_get() as $f):
?>
<div class="notice <?= h($f['type']) ?>">
<?= h($f['message']) ?>
</div>
<?php endforeach; ?>

<div class="card">
<h1>メール送信</h1>
<p>
対象アンケート：
<strong><?= h($survey['title']) ?></strong>
</p>

<form method="post" data-busy>
<input type="hidden" name="action"
 value="send_survey">
<input type="hidden" name="survey_id"
 value="<?= h($id) ?>">

<div class="field">
<label>顧客検索</label>
<input type="search"
 name="customer_q"
 value="<?= h($_GET['customer_q']??'') ?>"
 placeholder="氏名・組織・メール">
</div>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>選択</th>
<th>組織</th>
<th>氏名</th>
<th>メール</th>
<th>部署</th>
</tr>
</thead>
<tbody>
<?php
$cq=trim((string)($_GET['customer_q']??''));
foreach($data['customers'] as $c):
$text=implode(' ',[
$c['organization']??'',
$c['name']??'',
$c['email']??'',
$c['department']??''
]);
if($cq!=='' && mb_stripos($text,$cq)===false){
continue;
}
?>
<tr>
<td>
<input type="checkbox"
 name="customers[]"
 value="<?= h($c['id']) ?>">
</td>
<td><?= h($c['organization']??'') ?></td>
<td><?= h($c['name']??'') ?></td>
<td><?= h($c['email']??'') ?></td>
<td><?= h($c['department']??'') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="field">
<label>メール件名</label>
<input name="subject"
 value="<?= h($survey['title']) ?>"
 required>
</div>

<div class="field">
<label>メール本文</label>
<textarea name="body"
 required>こんにちは、{顧客名}様

以下のURLよりアンケートへご回答ください。

{アンケートURL}

よろしくお願いいたします。</textarea>
</div>

<div class="actions">
<button class="primary"
 data-confirm="選択した顧客へ一括送信しますか？">
一括送信
</button>
<button type="submit"
 name="send_mode"
 value="remind"
 data-confirm="選択した顧客へリマインドを送信しますか？">
リマインド
</button>
<button type="submit"
 name="send_mode"
 value="resend"
 data-confirm="選択した顧客へ再送しますか？">
再送
</button>
</div>
</form>
</div>

<div class="card">
<h2>送信履歴</h2>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>顧客</th>
<th>メール</th>
<th>結果</th>
</tr>
</thead>
<tbody>
<?php
$history=array_reverse(
 array_filter(
  $data['send_history'],
  fn($x)=>($x['surveyId']??'')===$id
 )
);
?>
<?php if(!$history): ?>
<tr><td colspan="4">送信履歴はありません。</td></tr>
<?php endif; ?>
<?php foreach($history as $x): ?>
<tr>
<td><?= h($x['sentAt']??'') ?></td>
<td><?= h($x['customerName']??'') ?></td>
<td><?= h($x['email']??'') ?></td>
<td>
<?= !empty($x['success'])
?'送信成功'
:'送信失敗: '.h($x['error']??'') ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php

    admin_footer();
}

/* =========================================================
 * 集計
 * ========================================================= */

function render_analytics(
    array $data,
    string $id
): void {
    $survey=survey_get(
        $data['surveys'],
        $id
    );

    if(!$survey){
        render_error('対象アンケートが見つかりません。');
        return;
    }

    $answers=array_values(
        array_filter(
            $data['answers'],
            fn($a)=>($a['surveyId']??'')===$id
        )
    );

    admin_header('回答集計・分析');

    ?>
<div class="toolbar">
<a class="btn"
 href="index.php?screen=list">
一覧へ戻る
</a>
<a class="btn"
 href="index.php?screen=analytics&id=<?= rawurlencode($id) ?>&export=csv">
CSV出力
</a>
</div>

<div class="card">
<h1>回答集計・分析</h1>
<p>対象アンケート：
<strong><?= h($survey['title']) ?></strong>
</p>

<div class="grid3">
<div class="stat">
<span>送信対象者数</span>
<strong><?= count($data['customers']) ?></strong>
</div>
<div class="stat">
<span>回答数</span>
<strong><?= count($answers) ?></strong>
</div>
<div class="stat">
<span>未回答数</span>
<strong><?= max(0,count($data['customers'])-count($answers)) ?></strong>
</div>
</div>
</div>

<?php if(!$answers): ?>
<div class="card">
現在、回答データはありません
</div>
<?php else: ?>

<div class="card">
<h2>設問別集計</h2>
<?php foreach(
 answer_question_list($survey) as $q
): ?>
<div class="question">
<h3>
<?= h($q['number']) ?>
<?= h($q['text']) ?>
</h3>
<?php
$counts=[];
foreach($answers as $a){
$v=$a['values'][$q['id']]??null;
if(is_array($v)){
 foreach($v as $x){
  $counts[(string)$x]=
   ($counts[(string)$x]??0)+1;
 }
}else{
 $counts[(string)$v]=
  ($counts[(string)$v]??0)+1;
 }
}
?>
<?php if($q['type']==='text'): ?>
<p>自由記述回答数：
<?= count(array_filter($counts,fn($v)=>$v>0)) ?></p>
<?php else: ?>
<table>
<thead><tr><th>選択肢</th><th>回答数</th></tr></thead>
<tbody>
<?php foreach($q['options']??[] as $o): ?>
<tr>
<td><?= h($o['label']) ?></td>
<td><?= (int)($counts[$o['id']]??0) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<div class="card">
<h2>個別回答</h2>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>回答日時</th>
<?php foreach(
 answer_question_list($survey) as $q
): ?>
<th><?= h($q['number']) ?></th>
<?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php foreach($answers as $a): ?>
<tr>
<td><?= h($a['createdAt']??'') ?></td>
<?php foreach(
 answer_question_list($survey) as $q
): ?>
<?php
$v=$a['values'][$q['id']]??'';
if(is_array($v))$v=implode(', ',$v);
?>
<td><?= h($v) ?></td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php endif; ?>
<?php

    admin_footer();
}

/* =========================================================
 * 回答者
 * ========================================================= */

function render_answer(
    array $survey
): void {
    start_app_session();

    $_SESSION['answer_survey_id']=$survey['id'];

    if(
        !isset($_SESSION['answer_values']) ||
        !is_array($_SESSION['answer_values'])
    ){
        $_SESSION['answer_values']=[];
    }

    adminless_header(
        (string)$survey['title']
    );

    ?>
<div class="answer-card card">
<h1><?= h($survey['title']) ?></h1>

<?php if(($survey['description']??'')!==''): ?>
<p><?= nl2br(h($survey['description'])) ?></p>
<?php endif; ?>

<form method="post"
 action="index.php?screen=answer&id=<?= rawurlencode($survey['id']) ?>">
<input type="hidden" name="action"
 value="answer_next">

<?php foreach(($survey['groups']??[]) as $group): ?>
<h2><?= h($group['title']??'') ?></h2>

<?php foreach(($group['questions']??[]) as $q): ?>
<?php if(
 !answer_visible(
  $survey,
  $_SESSION['answer_values'],
  $q
 )
)continue;
?>
<div class="question">
<label>
<?= h($q['number']) ?>.
<?= h($q['text']) ?>
<?= !empty($q['required'])?' *':'' ?>
</label>

<?php if(($q['type']??'')==='text'): ?>
<textarea name="answers[<?= h($q['id']) ?>]"
 <?= !empty($q['required'])?'required':'' ?>><?= h(
 $_SESSION['answer_values'][$q['id']]??''
) ?></textarea>

<?php else: ?>

<?php foreach(($q['options']??[]) as $o): ?>
<label style="font-weight:400">
<input
 type="<?= $q['type']==='multiple'?'checkbox':'radio' ?>"
 name="<?= $q['type']==='multiple'
 ? 'answers['.$q['id'].'][]'
 : 'answers['.$q['id'].']' ?>"
 value="<?= h($o['id']) ?>"
 <?= (
   $q['type']==='multiple'
   ? in_array(
       $o['id'],
       $_SESSION['answer_values'][$q['id']]??[],
       true
     )
   : (
       ($_SESSION['answer_values'][$q['id']]??'')
       === $o['id']
     )
 )
 ?'checked':'' ?>
 <?= !empty($q['required'])?'required':'' ?>>
<?= h($o['label']) ?>
</label>
<?php endforeach; ?>

<?php endif; ?>
</div>
<?php endforeach; ?>
<?php endforeach; ?>

<button class="primary">回答確認へ</button>
</form>
</div>
<?php

    adminless_footer();
}

function render_confirm(
    array $survey
): void {
    start_app_session();

    $values=$_SESSION['answer_values']??[];

    adminless_header(
        '回答確認'
    );
    ?>
<div class="answer-card card">
<h1>回答確認</h1>

<?php foreach(
 answer_question_list($survey) as $q
): ?>
<?php
$v=$values[$q['id']]??'';
if(is_array($v))$v=implode(', ',$v);
$label=$v;

foreach($q['options']??[] as $o){
 if($o['id']===$v){
  $label=$o['label'];
 }
}
?>
<div class="question">
<strong><?= h($q['number']) ?></strong>
<p><?= h($q['text']) ?></p>
<div><?= nl2br(h($label)) ?></div>
</div>
<?php endforeach; ?>

<div class="actions">
<a class="btn"
 href="index.php?screen=answer&id=<?= rawurlencode($survey['id']) ?>">
戻る・修正
</a>

<form method="post"
 style="display:inline"
 action="index.php?screen=confirm&id=<?= rawurlencode($survey['id']) ?>">
<input type="hidden" name="action"
 value="submit_answer">
<button class="primary"
 data-confirm="回答を送信しますか？">
回答送信
</button>
</form>
</div>
</div>
<?php
    adminless_footer();
}

function render_complete(): void
{
    adminless_header('回答完了');
    ?>
<div class="answer-card card">
<h1>回答完了</h1>
<p>
回答を送信しました。ご協力ありがとうございました。
</p>
</div>
<?php
    adminless_footer();
}

function adminless_header(
    string $title
): void {
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
 content="width=device-width,initial-scale=1">
<title><?= h($title) ?></title>
<?php css(); ?>
</head>
<body>
<div class="container">
<?php
}

function adminless_footer(): void
{
    ?>
</div>
</body>
</html>
<?php
}

/* =========================================================
 * CSV
 * ========================================================= */

function export_csv(
    array $data,
    array $survey
): never {
    $filename =
        'survey-' .
        preg_replace(
            '/[^a-zA-Z0-9_-]/',
            '_',
            (string)$survey['id']
        ) .
        '.csv';

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );
    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );

    $fp=fopen('php://output','wb');

    fwrite($fp,"\xEF\xBB\xBF");

    $header=['回答日時'];

    foreach(
        answer_question_list($survey) as $q
    ){
        $header[]=$q['number'].' '.$q['text'];
    }

    fputcsv($fp,$header);

    foreach(
        $data['answers'] as $a
    ){
        if(($a['surveyId']??'')!==$survey['id']){
            continue;
        }

        $row=[$a['createdAt']??''];

        foreach(
            answer_question_list($survey) as $q
        ){
            $v=$a['values'][$q['id']]??'';

            if(is_array($v)){
                $v=implode(', ',$v);
            }

            $row[]=$v;
        }

        fputcsv($fp,$row);
    }

    fclose($fp);
    exit;
}

/* =========================================================
 * エラー
 * ========================================================= */

function render_error(string $message): void
{
    admin_header('エラー');
    ?>
<div class="card">
<h1>処理できません</h1>
<div class="notice error">
<?= h($message) ?>
</div>
<a class="btn"
 href="index.php?screen=list">
アンケート一覧へ戻る
</a>
</div>
<?php
    admin_footer();
}

/* =========================================================
 * POST処理
 * ========================================================= */

function handle_post(
    array &$data,
    array &$settings
): void {
    $action=post_string('action');

    if($action==='save_survey'){
        $id=post_string('id');
        $old=$id!==''
            ? survey_get($data['surveys'],$id)
            : null;

        $survey=$old ?? new_survey();

        $newSurvey=normalize_survey_from_post(
            $survey,
            $_POST
        );

        $errors=validate_survey($newSurvey);

        if($errors){
            flash_set(
                'error',
                implode("\n",$errors)
            );

            $_SESSION['edit_errors']=$errors;
            $_SESSION['edit_survey']=$newSurvey;

            redirect_screen(
                'edit',
                $id!==''
                ? ['id'=>$id]
                : ['new'=>1]
            );
        }

        $newStatus=
            (string)($_POST['status']??'draft');

        $oldStatus=
            (string)($survey['status']??'draft');

        if($old===null){
            $newStatus='draft';
        }else{
            if($oldStatus==='ended'){
                $newStatus='ended';
            }elseif(
                !in_array(
                    $newStatus,
                    ['draft','published','stopped'],
                    true
                )
            ){
                $newStatus=$oldStatus;
            }

            if(
                $newStatus!==$oldStatus
            ){
                $labels=[
                    'draft'=>'下書き',
                    'published'=>'公開中',
                    'stopped'=>'停止',
                ];

                flash_set(
                    'info',
                    '状態変更：' .
                    ($labels[$oldStatus]??$oldStatus) .
                    ' → ' .
                    ($labels[$newStatus]??$newStatus)
                );
            }
        }

        $newSurvey['status']=$newStatus;
        $newSurvey['updatedAt']=now();

        if($old===null){
            $data['surveys'][]=$newSurvey;
        }else{
            $i=survey_index(
                $data['surveys'],
                $id
            );

            if($i<0){
                flash_set(
                    'error',
                    'アンケートが見つかりません。'
                );
                redirect_screen('list');
            }

            $data['surveys'][$i]=$newSurvey;
        }

        save_data($data);

        flash_set(
            'success',
            'アンケートを保存しました。'
        );

        redirect_screen('list');
    }

    if($action==='save_kintone'){
        $k=&$settings['kintone'];

        $subdomain=post_string('subdomain');
        $appId=post_string('app_id');
        $username=post_string('username');
        $password=post_string('password');
        $proxy=post_string('proxy');

        $candidate=[
            'base'=>normalize_kintone_host($subdomain),
            'app'=>$appId,
            'username'=>$username,
            'password'=>
                $password!==''
                ?$password
                :reveal_secret(
                    (string)($k['password']??'')
                ),
            'proxy'=>$proxy,
            'verify_ssl'=>
                post_string('verify_ssl')==='1',
        ];

        $errors=validate_kintone_config(
            $candidate
        );

        if($errors){
            flash_set(
                'error',
                implode("\n",$errors)
            );
            redirect_screen('kintone');
        }

        $k['subdomain']=$candidate['base'];
        $k['app_id']=$appId;
        $k['username']=$username;
        $k['proxy']=$proxy;
        $k['verify_ssl']=
            $candidate['verify_ssl'];

        /*
         * パスワードは平文保存しない。
         * 外部環境変数や管理者設定は要求しない。
         */
        if($password!==''){
            $k['password']=protect_secret(
                $password
            );
        }

        save_settings($settings);

        flash_set(
            'success',
            'kintone設定を保存しました。'
        );

        redirect_screen('kintone');
    }

    if($action==='test_kintone'){
        try{
            $cfg=kintone_settings($settings);

            $errors=validate_kintone_config(
                $cfg
            );

            if($errors){
                throw new RuntimeException(
                    implode("\n",$errors)
                );
            }

            $res=kintone_test($cfg);

            if(!$res['ok']){
                throw new RuntimeException(
                    $res['error'] !== ''
                    ? $res['error']
                    : 'kintone接続に失敗しました。'
                );
            }

            $settings['kintone']['last_test']=now();
            save_settings($settings);

            flash_set(
                'success',
                'kintone接続成功'
            );
        }catch(Throwable $e){
            flash_set(
                'error',
                'kintone接続テスト失敗：' .
                $e->getMessage()
            );
        }

        redirect_screen('kintone');
    }

    if($action==='fetch_kintone_fields'){
        try{
            $cfg=kintone_settings($settings);

            $errors=validate_kintone_config(
                $cfg
            );

            if($errors){
                throw new RuntimeException(
                    implode("\n",$errors)
                );
            }

            $res=kintone_fields($cfg);

            if(!$res['ok']){
                throw new RuntimeException(
                    $res['error']
                );
            }

            $settings['kintone']['available_fields']=
                kintone_field_list($res);

            save_settings($settings);

            flash_set(
                'success',
                'kintoneの項目一覧を再取得しました。'
            );
        }catch(Throwable $e){
            flash_set(
                'error',
                '項目一覧の取得失敗：' .
                $e->getMessage()
            );
        }

        redirect_screen('kintone');
    }

    if($action==='save_kintone_mapping'){
        $k=&$settings['kintone'];

        $k['fields']=[
            'organization'=>post_string('organization'),
            'name'=>post_string('name'),
            'email'=>post_string('email'),
            'department'=>post_string('department'),
            'phone'=>post_string('phone'),
            'address'=>array_values(
                array_filter(
                    post_array('address'),
                    'is_scalar'
                )
            ),
        ];

        save_settings($settings);

        flash_set(
            'success',
            'kintone項目マッピングを保存しました。'
        );

        redirect_screen('kintone');
    }

    if($action==='sync_kintone'){
        try{
            $cfg=kintone_settings($settings);

            $errors=validate_kintone_config(
                $cfg
            );

            if($errors){
                throw new RuntimeException(
                    implode("\n",$errors)
                );
            }

            $mapping=
                $settings['kintone']['fields']
                ?? [];

            $res=sync_customers(
                $cfg,
                $mapping
            );

            if(!$res['ok']){
                throw new RuntimeException(
                    $res['error']
                );
            }

            $data['customers']=$res['customers'];

            $settings['kintone']['last_sync']=now();

            save_data($data);
            save_settings($settings);

            flash_set(
                'success',
                'kintone顧客同期成功：' .
                $res['count'] .
                '件を同期しました。'
            );
        }catch(Throwable $e){
            flash_set(
                'error',
                'kintone顧客同期失敗：' .
                $e->getMessage()
            );
        }

        redirect_screen('kintone');
    }

    if($action==='save_mail'){
        $m=&$settings['mail'];

        $host=post_string('host');
        $port=(int)post_string('port','587');
        $enc=post_string('encryption','tls');
        $auth=post_string('auth')==='1';
        $username=post_string('username');
        $password=post_string('password');
        $from=post_string('from_email');
        $fromName=post_string('from_name');
        $reply=post_string('reply_to');

        $errors=[];

        if($host===''){
            $errors[]='SMTPサーバを入力してください。';
        }

        if($port<1 || $port>65535){
            $errors[]='SMTPポートが不正です。';
        }

        if(
            !in_array(
                $enc,
                ['ssl','tls','none'],
                true
            )
        ){
            $errors[]='暗号化方式が不正です。';
        }

        if(!filter_var(
            $from,
            FILTER_VALIDATE_EMAIL
        )){
            $errors[]=
                '送信元メールアドレスが不正です。';
        }

        if(
            $reply!=='' &&
            !filter_var(
                $reply,
                FILTER_VALIDATE_EMAIL
            )
        ){
            $errors[]=
                '返信先メールアドレスが不正です。';
        }

        if($errors){
            flash_set(
                'error',
                implode("\n",$errors)
            );
            redirect_screen('mail');
        }

        $m['host']=$host;
        $m['port']=$port;
        $m['encryption']=$enc;
        $m['auth']=$auth;
        $m['username']=$username;
        $m['from_email']=$from;
        $m['from_name']=$fromName;
        $m['reply_to']=$reply;

        if($password!==''){
            $m['password']=protect_secret(
                $password
            );
        }

        save_settings($settings);

        flash_set(
            'success',
            'メール設定を保存しました。'
        );

        redirect_screen('mail');
    }

    if($action==='test_mail'){
        try{
            $m=$settings['mail'];

            $cfg=[
                'host'=>$m['host']??'',
                'port'=>(int)($m['port']??587),
                'encryption'=>$m['encryption']??'tls',
                'auth'=>!empty($m['auth']),
                'username'=>$m['username']??'',
                'password'=>reveal_secret(
                    $m['password']??''
                ),
                'from_email'=>$m['from_email']??'',
                'from_name'=>$m['from_name']??'',
                'reply_to'=>$m['reply_to']??'',
            ];

            $res=smtp_test($cfg);

            if(!$res['ok']){
                throw new RuntimeException(
                    $res['error']
                );
            }

            $settings['mail']['last_test']=now();
            save_settings($settings);

            flash_set(
                'success',
                'SMTP接続成功'
            );
        }catch(Throwable $e){
            flash_set(
                'error',
                'SMTP接続テスト失敗：' .
                $e->getMessage()
            );
        }

        redirect_screen('mail');
    }

    if($action==='test_mail_send'){
        $m=$settings['mail'];

        $cfg=[
            'host'=>$m['host']??'',
            'port'=>(int)($m['port']??587),
            'encryption'=>$m['encryption']??'tls',
            'auth'=>!empty($m['auth']),
            'username'=>$m['username']??'',
            'password'=>reveal_secret(
                $m['password']??''
            ),
            'from_email'=>$m['from_email']??'',
            'from_name'=>$m['from_name']??'',
            'reply_to'=>$m['reply_to']??'',
        ];

        $res=smtp_send_mail(
            $cfg,
            post_string('to'),
            post_string('subject'),
            post_string('body')
        );

        flash_set(
            $res['ok']?'success':'error',
            $res['ok']
            ?'テストメールを送信しました。'
            :'テストメール送信失敗：'.$res['error']
        );

        redirect_screen('mail');
    }

    if($action==='send_survey'){
        $surveyId=post_string('survey_id');

        $survey=survey_get(
            $data['surveys'],
            $surveyId
        );

        if(!$survey){
            flash_set(
                'error',
                '対象アンケートが見つかりません。'
            );
            redirect_screen('list');
        }

        $m=$settings['mail'];

        $cfg=[
            'host'=>$m['host']??'',
            'port'=>(int)($m['port']??587),
            'encryption'=>$m['encryption']??'tls',
            'auth'=>!empty($m['auth']),
            'username'=>$m['username']??'',
            'password'=>reveal_secret(
                $m['password']??''
            ),
            'from_email'=>$m['from_email']??'',
            'from_name'=>$m['from_name']??'',
            'reply_to'=>$m['reply_to']??'',
        ];

        $ids=array_values(
            array_filter(
                post_array('customers'),
                'is_scalar'
            )
        );

        $subject=post_string('subject');
        $body=post_string('body');

        $success=0;
        $failure=0;

        foreach($ids as $cid){
            $customer=null;

            foreach($data['customers'] as $c){
                if(($c['id']??'')===(string)$cid){
                    $customer=$c;
                    break;
                }
            }

            if(!$customer){
                continue;
            }

            $email=(string)($customer['email']??'');

            $personalBody=str_replace(
                [
                    '{顧客名}',
                    '{アンケートURL}',
                ],
                [
                    (string)($customer['name']??''),
                    survey_url($surveyId),
                ],
                $body
            );

            $res=smtp_send_mail(
                $cfg,
                $email,
                $subject,
                $personalBody
            );

            $data['send_history'][]=[
                'id'=>id_new('send'),
                'surveyId'=>$surveyId,
                'customerId'=>$cid,
                'customerName'=>$customer['name']??'',
                'email'=>$email,
                'sentAt'=>now(),
                'success'=>$res['ok'],
                'error'=>$res['error'],
                'mode'=>post_string(
                    'send_mode',
                    'send'
                ),
            ];

            if($res['ok']){
                $success++;
            }else{
                $failure++;
            }
        }

        save_data($data);

        flash_set(
            $failure===0?'success':'error',
            '送信結果：成功 ' .
            $success .
            '件 / 失敗 ' .
            $failure .
            '件'
        );

        redirect_screen(
            'send',
            ['id'=>$surveyId]
        );
    }

    if($action==='answer_next'){
        start_app_session();

        $surveyId=post_string('survey_id');

        if($surveyId===''){
            $surveyId=(string)(
                $_SESSION['answer_survey_id']??''
            );
        }

        $survey=survey_get(
            $data['surveys'],
            $surveyId
        );

        if(!$survey){
            render_error(
                '対象アンケートが見つかりません。'
            );
            return;
        }

        $raw=$_POST['answers']??[];

        if(!is_array($raw)){
            $raw=[];
        }

        $errors=[];

        foreach(
            answer_question_list($survey) as $q
        ){
            if(
                !answer_visible(
                    $survey,
                    $raw,
                    $q
                )
            ){
                continue;
            }

            if(
                !empty($q['required']) &&
                (
                    !isset($raw[$q['id']]) ||
                    $raw[$q['id']]==='' ||
                    $raw[$q['id']]===[]
                )
            ){
                $errors[]=
                    ($q['number']??'質問') .
                    ' は必須です。';
            }
        }

        if($errors){
            flash_set(
                'error',
                implode("\n",$errors)
            );

            $_SESSION['answer_values']=$raw;

            redirect_screen(
                'answer',
                ['id'=>$surveyId]
            );
        }

        $_SESSION['answer_values']=$raw;

        redirect_screen(
            'confirm',
            ['id'=>$surveyId]
        );
    }

    if($action==='submit_answer'){
        start_app_session();

        $surveyId=post_string('survey_id');

        if($surveyId===''){
            $surveyId=(string)(
                $_SESSION['answer_survey_id']??''
            );
        }

        $survey=survey_get(
            $data['surveys'],
            $surveyId
        );

        if(!$survey){
            render_error(
                '対象アンケートが見つかりません。'
            );
            return;
        }

        $data['answers'][]=[
            'id'=>id_new('answer'),
            'surveyId'=>$surveyId,
            'values'=>$_SESSION['answer_values']??[],
            'createdAt'=>now(),
        ];

        save_data($data);

        unset(
            $_SESSION['answer_values'],
            $_SESSION['answer_survey_id']
        );

        redirect_screen(
            'complete'
        );
    }
}

/* =========================================================
 * GETアクション
 * ========================================================= */

function handle_get_action(
    array &$data
): void {
    $screen=(string)(
        $_GET['screen']??'list'
    );

    $action=(string)(
        $_GET['action']??''
    );

    if(
        $screen==='list' &&
        $action==='delete'
    ){
        $id=(string)($_GET['id']??'');

        $i=survey_index(
            $data['surveys'],
            $id
        );

        if($i>=0){
            array_splice(
                $data['surveys'],
                $i,
                1
            );

            save_data($data);

            flash_set(
                'success',
                'アンケートを削除しました。'
            );
        }else{
            flash_set(
                'error',
                'アンケートが見つかりません。'
            );
        }

        redirect_screen('list');
    }

    if(
        $screen==='list' &&
        $action==='duplicate'
    ){
        $id=(string)($_GET['id']??'');

        $survey=survey_get(
            $data['surveys'],
            $id
        );

        if(!$survey){
            flash_set(
                'error',
                'アンケートが見つかりません。'
            );
            redirect_screen('list');
        }

        $copy=$survey;

        $copy['id']=id_new('survey');
        $copy['title']=
            (string)$copy['title'] .
            '（コピー）';
        $copy['status']='draft';
        $copy['createdAt']=now();
        $copy['updatedAt']=now();

        foreach($copy['groups'] as &$group){
            $group['id']=id_new('group');

            foreach($group['questions'] as &$q){
                $q['id']=id_new('q');

                foreach($q['options']??[] as &$o){
                    $o['id']=id_new('opt');
                    $o['next']='';
                }
                unset($o);
            }
            unset($q);
        }
        unset($group);

        recalc_numbers($copy);

        $data['surveys'][]=$copy;

        save_data($data);

        flash_set(
            'success',
            'アンケートを複製しました。'
        );

        redirect_screen('list');
    }

    if(
        $screen==='analytics' &&
        (string)($_GET['export']??'')==='csv'
    ){
        $id=(string)($_GET['id']??'');

        $survey=survey_get(
            $data['surveys'],
            $id
        );

        if(!$survey){
            render_error(
                '対象アンケートが見つかりません。'
            );
            return;
        }

        export_csv(
            $data,
            $survey
        );
    }
}

/* =========================================================
 * メイン
 * ========================================================= */

start_app_session();

try {
    ensure_data_dir();

    $data=load_data();
    $settings=load_settings();

    /*
     * GET時に終了状態を自動判定。
     * published + endAt経過だけをendedへ変更。
     */
    $changed=false;

    foreach($data['surveys'] as &$survey){
        $before=$survey['status']??'draft';
        $after=survey_status($survey);

        if($before!==$after){
            $changed=true;
            $survey['updatedAt']=now();
        }
    }
    unset($survey);

    if($changed){
        save_data($data);
    }

    if($_SERVER['REQUEST_METHOD']==='POST'){
        handle_post(
            $data,
            $settings
        );
        exit;
    }

    handle_get_action($data);

    $screen=(string)(
        $_GET['screen']??'list'
    );

    /*
     * 回答者画面は管理者画面と完全に分離。
     */
    if(
        in_array(
            $screen,
            ['answer','confirm','complete'],
            true
        )
    ){
        $id=(string)(
            $_GET['id']??''
        );

        if(
            $screen==='complete'
        ){
            render_complete();
            exit;
        }

        $survey=survey_get(
            $data['surveys'],
            $id
        );

        if(!$survey){
            render_error(
                '対象アンケートが見つかりません。'
            );
            exit;
        }

        $status=(string)(
            $survey['status']??'draft'
        );

        if(
            $status!=='published'
        ){
            render_error(
                'このアンケートは現在回答できません。'
            );
            exit;
        }

        if(
            $screen==='answer'
        ){
            render_answer($survey);
        }else{
            render_confirm($survey);
        }

        exit;
    }

    /*
     * 管理者画面
     */
    switch($screen){
        case 'list':
            render_list($data);
            break;

        case 'edit':
            $id=(string)(
                $_GET['id']??''
            );

            if(
                isset($_SESSION['edit_survey']) &&
                (
                    (
                        $id!=='' &&
                        ($_SESSION['edit_survey']['id']??'')
                        ===$id
                    ) ||
                    (
                        $id==='' &&
                        isset($_GET['new'])
                    )
                )
            ){
                $survey=$_SESSION['edit_survey'];
                unset($_SESSION['edit_survey']);
            }elseif($id!==''){
                $survey=survey_get(
                    $data['surveys'],
                    $id
                );

                if(!$survey){
                    render_error(
                        'アンケートが見つかりません。'
                    );
                    break;
                }
            }else{
                $survey=new_survey();
            }

            /*
             * 編集時点でも終了判定。
             */
            survey_status($survey);

            render_edit($survey);
            break;

        case 'preview':
            $id=(string)(
                $_GET['id']??''
            );

            $survey=survey_get(
                $data['surveys'],
                $id
            );

            if(!$survey){
                render_error(
                    '対象アンケートが見つかりません。'
                );
                break;
            }

            render_preview($survey);
            break;

        case 'send':
            $id=(string)(
                $_GET['id']??''
            );

            if($id===''){
                render_error(
                    '送信対象アンケートが指定されていません。'
                );
                break;
            }

            render_send(
                $data,
                $id
            );
            break;

        case 'analytics':
            $id=(string)(
                $_GET['id']??''
            );

            if($id===''){
                render_error(
                    '集計対象アンケートが指定されていません。'
                );
                break;
            }

            render_analytics(
                $data,
                $id
            );
            break;

        case 'kintone':
            render_kintone(
                $settings,
                $data
            );
            break;

        case 'mail':
            render_mail(
                $settings
            );
            break;

        default:
            render_list($data);
            break;
    }
} catch (Throwable $e) {
    /*
     * 内部詳細をそのまま画面へ出さない。
     * POCで原因確認できる範囲のメッセージだけ表示。
     */
    http_response_code(500);

    admin_header('システムエラー');
    ?>
<div class="card">
<h1>システムエラー</h1>
<div class="notice error">
処理中にエラーが発生しました。
データ保存領域やPHPのファイル権限を確認してください。
</div>
<a class="btn"
 href="index.php?screen=list">
アンケート一覧へ戻る
</a>
</div>
<?php
    admin_footer();
}

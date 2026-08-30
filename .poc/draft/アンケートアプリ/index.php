<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし
 * 単一エントリーポイント
 *
 * 外部サービス:
 *   kintone REST API
 *   SMTP
 *
 * 永続化:
 *   _data/data.json
 *   _data/settings.json
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR  = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SET_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_READ_TIMEOUT    = 30;
const SMTP_CONNECT_TIMEOUT    = 10;
const SMTP_READ_TIMEOUT       = 30;

const MAX_TITLE       = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION    = 1000;
const MAX_OPTION      = 500;

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

function post(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function post_string(string $key): string
{
    $v = post($key, '');
    return is_scalar($v) ? trim((string)$v) : '';
}

function get_string(string $key): string
{
    $v = $_GET[$key] ?? '';
    return is_scalar($v) ? trim((string)$v) : '';
}

function post_bool(string $key): bool
{
    return in_array(
        strtolower((string)post($key, '')),
        ['1', 'on', 'true', 'yes'],
        true
    );
}

function uid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function app_url(array $params = []): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    if (!$params) {
        return $script;
    }

    return $script . '?' . http_build_query(
        $params,
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}

function public_url(string $surveyId): string
{
    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

    $scheme = $https ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host .
        app_url(['screen' => 'answer', 'id' => $surveyId]);
}

/* =========================================================
 * セッション
 * ========================================================= */

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

function start_app(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException(
                'データ保存フォルダを作成できません。'
            );
        }
    }

    if (!is_file(DATA_FILE)) {
        save_json(DATA_FILE, default_data());
    }

    if (!is_file(SET_FILE)) {
        save_json(SET_FILE, default_settings());
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        $secure =
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

        session_name('survey_app_session');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => cookie_path(),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException(
                'セッションを開始できません。'
            );
        }
    }
}

/* =========================================================
 * デフォルトデータ
 * ========================================================= */

function default_data(): array
{
    $n = now();

    return [
        'surveys' => [[
            'id' => 'survey-001',
            'title' => '顧客満足度アンケート',
            'description' => 'サービスについてのご意見をお聞かせください。',
            'startAt' => date('Y-m-d\TH:i'),
            'endAt' => date('Y-m-d\TH:i', strtotime('+30 days')),
            'status' => 'draft',
            'numbering' => 'global',
            'createdAt' => $n,
            'updatedAt' => $n,
            'groups' => [[
                'id' => 'group-001',
                'title' => '基本アンケート',
                'questions' => [[
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
                ], [
                    'id' => 'question-002',
                    'number' => 'Q2',
                    'text' => 'ご意見・ご要望があれば入力してください。',
                    'type' => 'text',
                    'required' => false,
                    'options' => [],
                ]],
            ]],
        ]],
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

    if (!$fp) {
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
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('JSON保存データを生成できません。');
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));

    $fp = @fopen($tmp, 'wb');

    if (!$fp) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('ファイルをロックできません。');
        }

        if (fwrite($fp, $json) === false) {
            throw new RuntimeException('データを書き込めません。');
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('データファイルを更新できません。');
        }
    } catch (Throwable $e) {
        @fclose($fp);
        @unlink($tmp);
        throw $e;
    }
}

function load_data(): array
{
    $d = load_json(DATA_FILE, default_data());

    foreach (['surveys', 'answers', 'customers', 'send_history'] as $k) {
        if (!isset($d[$k]) || !is_array($d[$k])) {
            $d[$k] = [];
        }
    }

    return $d;
}

function save_data(array $d): void
{
    save_json(DATA_FILE, $d);
}

function load_settings(): array
{
    $def = default_settings();
    $s = load_json(SET_FILE, $def);

    foreach (['kintone', 'mail'] as $k) {
        $s[$k] = array_replace_recursive(
            $def[$k],
            is_array($s[$k] ?? null) ? $s[$k] : []
        );
    }

    return $s;
}

function save_settings(array $s): void
{
    save_json(SET_FILE, $s);
}

/* =========================================================
 * Flash
 * ========================================================= */

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function flash_get(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($f) ? $f : null;
}

/* =========================================================
 * アンケート
 * ========================================================= */

function survey_index(array $surveys, string $id): int
{
    foreach ($surveys as $i => $s) {
        if ((string)($s['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

function survey_get(array $surveys, string $id): ?array
{
    $i = survey_index($surveys, $id);

    return $i >= 0 ? $surveys[$i] : null;
}

function refresh_status(array &$data): void
{
    $changed = false;

    foreach ($data['surveys'] as &$survey) {
        if (
            ($survey['status'] ?? '') === 'published' &&
            !empty($survey['endAt'])
        ) {
            $t = strtotime((string)$survey['endAt']);

            if ($t !== false && $t < time()) {
                $survey['status'] = 'ended';
                $survey['updatedAt'] = now();
                $changed = true;
            }
        }
    }

    unset($survey);

    if ($changed) {
        save_data($data);
    }
}

function recalc_numbers(array &$survey): void
{
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            $question['number'] =
                ($survey['numbering'] ?? 'global') === 'group'
                    ? 'Q' . $groupNo . '-' . $questionNo
                    : 'Q' . $global;

            $global++;
            $questionNo++;
        }

        unset($question);
        $groupNo++;
    }

    unset($group);
}

function all_questions(array $survey): array
{
    $out = [];

    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $q) {
            $out[] = $q;
        }
    }

    return $out;
}

function question_ids(array $survey): array
{
    return array_map(
        static fn(array $q): string => (string)$q['id'],
        all_questions($survey)
    );
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
        default => 'gray',
    };
}

/* =========================================================
 * kintone
 * ========================================================= */

function normalize_kintone_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    ) ?? $value;

    $value = preg_replace(
        '#/.*$#',
        '',
        $value
    ) ?? $value;

    if (
        str_ends_with(
            strtolower($value),
            '.cybozu.com'
        )
    ) {
        $value = substr(
            $value,
            0,
            -strlen('.cybozu.com')
        );
    }

    return trim($value);
}

function validate_kintone(array $c, bool $password = true): array
{
    $e = [];

    $sub = normalize_kintone_subdomain(
        (string)($c['subdomain'] ?? '')
    );

    if (
        $sub === '' ||
        !preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $sub)
    ) {
        $e[] = 'kintoneサブドメインが不正です。';
    }

    $app = trim((string)($c['app_id'] ?? ''));

    if (!ctype_digit($app) || (int)$app < 1) {
        $e[] = '顧客管理アプリIDが不正です。';
    }

    if (trim((string)($c['username'] ?? '')) === '') {
        $e[] = 'ログイン名を入力してください。';
    }

    if (
        $password &&
        trim((string)($c['password'] ?? '')) === ''
    ) {
        $e[] = 'パスワードを入力してください。';
    }

    $proxy = trim((string)($c['proxy'] ?? ''));

    if (
        $proxy !== '' &&
        !preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )
    ) {
        $e[] = 'Proxyはhost:port形式で入力してください。';
    }

    return $e;
}

function kintone_request(
    array $c,
    string $method,
    string $path,
    ?array $body = null
): array {
    $errors = validate_kintone($c, true);

    if ($errors) {
        throw new RuntimeException(implode("\n", $errors));
    }

    $sub = normalize_kintone_subdomain(
        (string)$c['subdomain']
    );

    $url = 'https://' . $sub . '.cybozu.com' . $path;

    $auth = base64_encode(
        (string)$c['username'] . ':' .
        (string)$c['password']
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $auth,
        'Accept: application/json',
        'Connection: close',
    ];

    $content = '';

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($content === false) {
            throw new RuntimeException(
                'kintoneリクエストを生成できません。'
            );
        }

        $headers[] = 'Content-Type: application/json';
    }

    $verify = !empty($c['verify_ssl']);

    $opts = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => KINTONE_READ_TIMEOUT,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
            'peer_name' => $sub . '.cybozu.com',
        ],
    ];

    $proxy = trim((string)($c['proxy'] ?? ''));

    if ($proxy !== '') {
        [$ph, $pp] = explode(':', $proxy, 2);

        $opts['http']['proxy'] =
            'tcp://' . $ph . ':' . (int)$pp;
        $opts['http']['request_fulluri'] = true;
    }

    $ctx = stream_context_create($opts);

    $response = @file_get_contents(
        $url,
        false,
        $ctx
    );

    $status = 0;

    foreach ($http_response_header ?? [] as $header) {
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

    if ($response === false) {
        throw new RuntimeException(
            'kintoneへの通信に失敗しました。'
        );
    }

    if ($status === 302 || $status === 303) {
        throw new RuntimeException(
            'kintoneからリダイレクト応答が返されました。'
        );
    }

    if ($status < 200 || $status >= 300) {
        $j = json_decode($response, true);

        $msg = is_array($j)
            ? (string)($j['message'] ?? '')
            : '';

        $code = is_array($j)
            ? (string)($j['code'] ?? '')
            : '';

        $detail = 'kintone APIエラー HTTP ' . $status;

        if ($code !== '') {
            $detail .= ' [' . $code . ']';
        }

        if ($msg !== '') {
            $detail .= ' ' . $msg;
        }

        throw new RuntimeException($detail);
    }

    $json = json_decode($response, true);

    if (!is_array($json)) {
        throw new RuntimeException(
            'kintoneから正常なJSON応答を取得できませんでした。'
        );
    }

    return [
        'status' => $status,
        'body' => $json,
    ];
}

function kintone_test(array $c): array
{
    return kintone_request(
        $c,
        'GET',
        '/k/v1/app.json?id=' .
        rawurlencode((string)$c['app_id'])
    );
}

function kintone_fields(array $c): array
{
    return kintone_request(
        $c,
        'GET',
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode((string)$c['app_id'])
    );
}

function kintone_records(array $c): array
{
    return kintone_request(
        $c,
        'GET',
        '/k/v1/records.json?app=' .
        rawurlencode((string)$c['app_id']) .
        '&totalCount=true'
    );
}

function kintone_field_list(array $response): array
{
    $properties = $response['body']['properties'] ?? [];

    if (!is_array($properties)) {
        return [];
    }

    $out = [];

    foreach ($properties as $code => $f) {
        if (!is_array($f)) {
            continue;
        }

        $out[] = [
            'code' => (string)$code,
            'label' => (string)($f['label'] ?? $code),
            'type' => (string)($f['type'] ?? ''),
        ];
    }

    usort(
        $out,
        static fn(array $a, array $b): int =>
            strnatcasecmp($a['code'], $b['code'])
    );

    return $out;
}

function krecord(array $record, string $code): string
{
    if (
        $code === '' ||
        !isset($record[$code]) ||
        !is_array($record[$code])
    ) {
        return '';
    }

    $v = $record[$code]['value'] ?? '';

    if (!is_array($v)) {
        return (string)$v;
    }

    $out = [];

    foreach ($v as $x) {
        if (!is_array($x)) {
            $out[] = (string)$x;
        } elseif (isset($x['name'])) {
            $out[] = (string)$x['name'];
        } elseif (isset($x['value'])) {
            $out[] = (string)$x['value'];
        }
    }

    return implode(' ', array_filter(
        $out,
        static fn(string $x): bool => $x !== ''
    ));
}

/* =========================================================
 * SMTP
 * ========================================================= */

function validate_mail(array $c): array
{
    $e = [];

    if (trim((string)($c['host'] ?? '')) === '') {
        $e[] = 'SMTPサーバを入力してください。';
    }

    $port = (int)($c['port'] ?? 0);

    if ($port < 1 || $port > 65535) {
        $e[] = 'SMTPポートが不正です。';
    }

    if (!in_array(
        (string)($c['encryption'] ?? ''),
        ['ssl', 'tls', 'none'],
        true
    )) {
        $e[] = '暗号化方式が不正です。';
    }

    if (!filter_var(
        (string)($c['from_email'] ?? ''),
        FILTER_VALIDATE_EMAIL
    )) {
        $e[] = '送信元メールアドレスが不正です。';
    }

    $reply = trim((string)($c['reply_to'] ?? ''));

    if (
        $reply !== '' &&
        !filter_var($reply, FILTER_VALIDATE_EMAIL)
    ) {
        $e[] = '返信先メールアドレスが不正です。';
    }

    if (!empty($c['auth'])) {
        if (trim((string)($c['username'] ?? '')) === '') {
            $e[] = 'SMTPユーザー名を入力してください。';
        }

        if (trim((string)($c['password'] ?? '')) === '') {
            $e[] = 'SMTPパスワードを入力してください。';
        }
    }

    return $e;
}

function smtp_read($socket, array $codes): string
{
    $response = '';

    while (($line = fgets($socket)) !== false) {
        $response .= $line;

        if (
            preg_match(
                '/^(\d{3})([ -])/',
                $line,
                $m
            )
        ) {
            if ($m[2] === ' ') {
                $code = (int)$m[1];

                if (!in_array($code, $codes, true)) {
                    throw new RuntimeException(
                        'SMTPエラー: ' .
                        $code .
                        ' ' .
                        trim($response)
                    );
                }

                return $response;
            }
        }
    }

    if ($response === '') {
        throw new RuntimeException(
            'SMTPから応答がありません。'
        );
    }

    throw new RuntimeException(
        'SMTP応答を最後まで取得できませんでした。'
    );
}

function smtp_cmd(
    $socket,
    string $command,
    array $codes
): string {
    if (
        fwrite(
            $socket,
            $command . "\r\n"
        ) === false
    ) {
        throw new RuntimeException(
            'SMTPへコマンドを送信できません。'
        );
    }

    return smtp_read($socket, $codes);
}

function smtp_open(array $c)
{
    $errors = validate_mail($c);

    if ($errors) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    /*
     * SSL/TLS/平文を明確に分離する。
     *
     * ssl:
     *   最初からSSLソケット
     *
     * tls:
     *   平文接続 → EHLO → STARTTLS
     *
     * none:
     *   平文
     */
    $host = trim((string)$c['host']);
    $port = (int)$c['port'];
    $enc  = (string)$c['encryption'];

    if ($enc === 'ssl') {
        $target = 'ssl://' . $host . ':' . $port;
    } else {
        $target = 'tcp://' . $host . ':' . $port;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $target,
        $errno,
        $errstr,
        SMTP_CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($socket)) {
        /*
         * 「ssl」をホスト名として解釈させない。
         * 接続先は常に host / port から構成する。
         */
        throw new RuntimeException(
            'SMTP接続に失敗しました: ' .
            ($errstr !== '' ? $errstr : '接続できませんでした。')
        );
    }

    stream_set_timeout(
        $socket,
        SMTP_READ_TIMEOUT
    );

    try {
        smtp_read($socket, [220]);

        $ehlo = smtp_cmd(
            $socket,
            'EHLO localhost',
            [250]
        );

        if ($enc === 'tls') {
            if (
                stripos(
                    $ehlo,
                    'STARTTLS'
                ) === false
            ) {
                throw new RuntimeException(
                    'SMTPサーバがSTARTTLSに対応していません。'
                );
            }

            smtp_cmd(
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
                    'SMTP STARTTLSを確立できません。'
                );
            }

            smtp_cmd(
                $socket,
                'EHLO localhost',
                [250]
            );
        }

        if (!empty($c['auth'])) {
            smtp_cmd(
                $socket,
                'AUTH LOGIN',
                [334]
            );

            smtp_cmd(
                $socket,
                base64_encode(
                    (string)$c['username']
                ),
                [334]
            );

            smtp_cmd(
                $socket,
                base64_encode(
                    (string)$c['password']
                ),
                [235]
            );
        }

        return $socket;
    } catch (Throwable $e) {
        fclose($socket);
        throw $e;
    }
}

function mime_header(string $value): string
{
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader(
            $value,
            'UTF-8',
            'B'
        );
    }

    return $value;
}

function smtp_send(
    array $c,
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

    if (!filter_var(
        (string)$c['from_email'],
        FILTER_VALIDATE_EMAIL
    )) {
        throw new RuntimeException(
            '送信元メールアドレスが不正です。'
        );
    }

    $socket = smtp_open($c);

    try {
        $from = (string)$c['from_email'];
        $name = (string)($c['from_name'] ?? '');

        smtp_cmd(
            $socket,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        smtp_cmd(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        smtp_cmd(
            $socket,
            'DATA',
            [354]
        );

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' .
                mime_header(
                    $name !== '' ? $name : $from
                ) .
                ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . mime_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $reply = trim(
            (string)($c['reply_to'] ?? '')
        );

        if ($reply !== '') {
            $headers[] = 'Reply-To: ' . $reply;
        }

        $body = str_replace(
            ["\r\n", "\r"],
            "\n",
            $body
        );

        $body = preg_replace(
            '/^\./m',
            '..',
            $body
        ) ?? $body;

        $message =
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            str_replace(
                "\n",
                "\r\n",
                $body
            ) .
            "\r\n.";

        smtp_cmd(
            $socket,
            $message,
            [250]
        );

        smtp_cmd(
            $socket,
            'QUIT',
            [221]
        );
    } finally {
        fclose($socket);
    }
}

/* =========================================================
 * アンケート入力
 * ========================================================= */

function validate_survey_post(): array
{
    $e = [];

    $title = post_string('title');

    if ($title === '') {
        $e[] = 'アンケートタイトルを入力してください。';
    } elseif (mb_strlen($title) > MAX_TITLE) {
        $e[] = 'アンケートタイトルが長すぎます。';
    }

    if (
        mb_strlen(
            (string)post('description', '')
        ) > MAX_DESCRIPTION
    ) {
        $e[] = 'アンケート説明が長すぎます。';
    }

    $start = post_string('startAt');
    $end   = post_string('endAt');

    if ($start === '' || strtotime($start) === false) {
        $e[] = '開始日時が不正です。';
    }

    if ($end === '' || strtotime($end) === false) {
        $e[] = '終了日時が不正です。';
    }

    if (
        $start !== '' &&
        $end !== '' &&
        strtotime($start) !== false &&
        strtotime($end) !== false &&
        strtotime($start) >= strtotime($end)
    ) {
        $e[] = '終了日時は開始日時より後にしてください。';
    }

    if (!in_array(
        post_string('numbering'),
        ['global', 'group'],
        true
    )) {
        $e[] = '質問番号の採番方式が不正です。';
    }

    return $e;
}

function read_groups_from_post(): array
{
    $raw = post('groups', []);

    if (!is_array($raw)) {
        return [];
    }

    $groups = [];

    foreach ($raw as $g) {
        if (!is_array($g)) {
            continue;
        }

        $gid = trim((string)($g['id'] ?? ''));

        if ($gid === '') {
            $gid = uid('group');
        }

        $title = trim(
            (string)($g['title'] ?? '')
        );

        if ($title === '') {
            $title = '無題のグループ';
        }

        $questions = [];

        foreach (($g['questions'] ?? []) as $q) {
            if (!is_array($q)) {
                continue;
            }

            $qid = trim(
                (string)($q['id'] ?? '')
            );

            if ($qid === '') {
                $qid = uid('question');
            }

            $type = (string)($q['type'] ?? 'text');

            if (!in_array(
                $type,
                ['single', 'multiple', 'text'],
                true
            )) {
                $type = 'text';
            }

            $options = [];

            if (
                $type === 'single' ||
                $type === 'multiple'
            ) {
                foreach (($q['options'] ?? []) as $o) {
                    if (!is_array($o)) {
                        continue;
                    }

                    $oid = trim(
                        (string)($o['id'] ?? '')
                    );

                    if ($oid === '') {
                        $oid = uid('option');
                    }

                    $options[] = [
                        'id' => $oid,
                        'label' => mb_substr(
                            trim((string)($o['label'] ?? '')),
                            0,
                            MAX_OPTION
                        ),
                        'nextQuestionId' =>
                            $type === 'single'
                                ? trim(
                                    (string)(
                                        $o['nextQuestionId'] ?? ''
                                    )
                                )
                                : '',
                    ];
                }
            }

            $questions[] = [
                'id' => $qid,
                'number' => '',
                'text' => mb_substr(
                    trim((string)($q['text'] ?? '')),
                    0,
                    MAX_QUESTION
                ),
                'type' => $type,
                'required' => !empty($q['required']),
                'options' => $options,
            ];
        }

        $groups[] = [
            'id' => $gid,
            'title' => $title,
            'questions' => $questions,
        ];
    }

    return $groups;
}

/* =========================================================
 * 回答
 * ========================================================= */

function visible_questions(
    array $survey,
    array $answers
): array {
    $all = all_questions($survey);

    if (!$all) {
        return [];
    }

    $byId = [];

    foreach ($all as $q) {
        $byId[$q['id']] = $q;
    }

    $visible = [];
    $next = null;

    foreach ($all as $q) {
        if ($next !== null && $q['id'] !== $next) {
            continue;
        }

        $visible[] = $q;
        $next = null;

        if (
            ($q['type'] ?? '') === 'single'
        ) {
            $answer = $answers[$q['id']] ?? '';

            foreach ($q['options'] ?? [] as $o) {
                if (
                    (string)$o['id'] ===
                    (string)$answer
                ) {
                    $target = trim(
                        (string)($o['nextQuestionId'] ?? '')
                    );

                    if ($target !== '') {
                        $next = $target;
                    }
                    break;
                }
            }
        }
    }

    return $visible;
}

function validate_answers(
    array $survey,
    array $answers
): array {
    $errors = [];

    foreach (visible_questions(
        $survey,
        $answers
    ) as $q) {
        if (empty($q['required'])) {
            continue;
        }

        $v = $answers[$q['id']] ?? null;

        $empty =
            $v === null ||
            $v === '' ||
            (is_array($v) && !$v);

        if ($empty) {
            $errors[] =
                ($q['number'] ?? '質問') .
                ' は必須です。';
        }
    }

    return $errors;
}

/* =========================================================
 * CSV / 簡易PDF
 * ========================================================= */

function output_csv(
    array $survey,
    array $answers
): never {
    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="survey.csv"'
    );

    echo "\xEF\xBB\xBF";

    $fp = fopen('php://output', 'wb');

    fputcsv(
        $fp,
        ['回答ID', '回答日時', '質問番号', '質問', '回答']
    );

    foreach ($answers as $a) {
        foreach (($a['answers'] ?? []) as $qid => $v) {
            $question = null;

            foreach (all_questions($survey) as $q) {
                if ((string)$q['id'] === (string)$qid) {
                    $question = $q;
                    break;
                }
            }

            if (!$question) {
                continue;
            }

            if (is_array($v)) {
                $v = implode(', ', $v);
            }

            fputcsv(
                $fp,
                [
                    $a['id'] ?? '',
                    $a['createdAt'] ?? '',
                    $question['number'] ?? '',
                    $question['text'] ?? '',
                    $v,
                ]
            );
        }
    }

    fclose($fp);
    exit;
}

function output_pdf(
    array $survey,
    array $answers
): never {
    /*
     * 外部PDFライブラリを要求しない実装。
     * ブラウザ印刷可能なPDF生成用HTMLを返す。
     *
     * 実際のPDFエンジンが環境に存在しない場合でも
     * 白画面にはせず、印刷可能な結果を返す。
     */
    header('Content-Type: text/html; charset=UTF-8');

    echo '<!doctype html><html lang="ja"><head><meta charset="UTF-8">';
    echo '<title>' . h($survey['title'] ?? '') . '</title>';
    echo '<style>
        body{font-family:Arial,"Noto Sans JP",sans-serif;margin:30px}
        table{border-collapse:collapse;width:100%}
        th,td{border:1px solid #bbb;padding:6px;text-align:left}
        th{background:#eee}
    </style></head><body>';

    echo '<h1>' . h($survey['title'] ?? '') . '</h1>';

    if (!$answers) {
        echo '<p>現在、回答データはありません</p>';
    } else {
        echo '<table>';
        echo '<tr><th>回答ID</th><th>日時</th><th>回答内容</th></tr>';

        foreach ($answers as $a) {
            $lines = [];

            foreach (($a['answers'] ?? []) as $qid => $v) {
                foreach (all_questions($survey) as $q) {
                    if ((string)$q['id'] === (string)$qid) {
                        if (is_array($v)) {
                            $v = implode(', ', $v);
                        }

                        $lines[] =
                            ($q['number'] ?? '') .
                            ' ' .
                            ($q['text'] ?? '') .
                            ': ' .
                            (string)$v;
                        break;
                    }
                }
            }

            echo '<tr>';
            echo '<td>' . h($a['id'] ?? '') . '</td>';
            echo '<td>' . h($a['createdAt'] ?? '') . '</td>';
            echo '<td>' . nl2br(h(implode("\n", $lines))) . '</td>';
            echo '</tr>';
        }

        echo '</table>';
    }

    echo '<script>window.print();</script>';
    echo '</body></html>';
    exit;
}

/* =========================================================
 * POST処理
 * ========================================================= */

function handle_post(
    array &$data,
    array &$settings
): array {
    $action = post_string('action');

    switch ($action) {
        case 'save_survey':
            $errors = validate_survey_post();

            if ($errors) {
                flash('error', implode("\n", $errors));

                return [
                    'screen' => 'edit',
                    'id' => post_string('survey_id'),
                ];
            }

            $id = post_string('survey_id');
            $idx = survey_index(
                $data['surveys'],
                $id
            );

            $groups = read_groups_from_post();

            $survey = [
                'id' => $id !== '' ? $id : uid('survey'),
                'title' => post_string('title'),
                'description' => (string)post('description', ''),
                'startAt' => post_string('startAt'),
                'endAt' => post_string('endAt'),
                'status' => 'draft',
                'numbering' => post_string('numbering'),
                'createdAt' => now(),
                'updatedAt' => now(),
                'groups' => $groups,
            ];

            if ($idx >= 0) {
                $old = $data['surveys'][$idx];

                /*
                 * 編集保存では現在状態を維持。
                 * ended は手動変更不可。
                 */
                $survey['status'] =
                    (string)($old['status'] ?? 'draft');

                $survey['createdAt'] =
                    $old['createdAt'] ?? now();

                if ($survey['status'] === 'ended') {
                    $survey['status'] = 'ended';
                }

                $data['surveys'][$idx] = $survey;
            } else {
                $data['surveys'][] = $survey;
                $idx = array_key_last($data['surveys']);
            }

            recalc_numbers($data['surveys'][$idx]);

            save_data($data);

            flash(
                'success',
                'アンケートを保存しました。'
            );

            return ['screen' => 'list'];

        case 'change_status':
            $id = post_string('survey_id');
            $to = post_string('status');

            $idx = survey_index(
                $data['surveys'],
                $id
            );

            if ($idx < 0) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                return ['screen' => 'list'];
            }

            $from =
                (string)($data['surveys'][$idx]['status'] ?? '');

            $allowed =
                ($from === 'draft' && $to === 'published') ||
                ($from === 'published' && $to === 'stopped') ||
                ($from === 'stopped' && $to === 'published');

            if (
                $allowed &&
                $from !== 'ended'
            ) {
                $data['surveys'][$idx]['status'] = $to;
                $data['surveys'][$idx]['updatedAt'] = now();
                save_data($data);

                flash(
                    'success',
                    '状態を変更しました。'
                );
            } else {
                flash(
                    'error',
                    '指定された状態変更はできません。'
                );
            }

            return ['screen' => 'list'];

        case 'delete_survey':
            $id = post_string('survey_id');

            $idx = survey_index(
                $data['surveys'],
                $id
            );

            if ($idx >= 0) {
                array_splice(
                    $data['surveys'],
                    $idx,
                    1
                );

                save_data($data);

                flash(
                    'success',
                    'アンケートを削除しました。'
                );
            }

            return ['screen' => 'list'];

        case 'duplicate_survey':
            $id = post_string('survey_id');

            $survey = survey_get(
                $data['surveys'],
                $id
            );

            if (!$survey) {
                flash(
                    'error',
                    '複製元アンケートが見つかりません。'
                );

                return ['screen' => 'list'];
            }

            $survey['id'] = uid('survey');
            $survey['title'] =
                (string)$survey['title'] . '（複製）';
            $survey['status'] = 'draft';
            $survey['createdAt'] = now();
            $survey['updatedAt'] = now();

            foreach ($survey['groups'] as &$g) {
                $g['id'] = uid('group');

                foreach ($g['questions'] as &$q) {
                    $q['id'] = uid('question');

                    foreach ($q['options'] as &$o) {
                        $o['id'] = uid('option');
                    }

                    unset($o);
                }

                unset($q);
            }

            unset($g);

            recalc_numbers($survey);

            $data['surveys'][] = $survey;

            save_data($data);

            flash(
                'success',
                'アンケートを複製しました。'
            );

            return ['screen' => 'list'];

        case 'save_kintone':
            $current = $settings['kintone'];

            $password = post_string('password');

            if ($password === '') {
                $password =
                    (string)($current['password'] ?? '');
            }

            $config = [
                'subdomain' =>
                    normalize_kintone_subdomain(
                        post_string('subdomain')
                    ),
                'app_id' => post_string('app_id'),
                'username' => post_string('username'),
                'password' => $password,
                'proxy' => post_string('proxy'),
                'verify_ssl' => post_bool('verify_ssl'),
                'mapping' =>
                    $current['mapping'] ?? [],
                'fields' =>
                    $current['fields'] ?? [],
                'last_test' =>
                    $current['last_test'] ?? null,
                'last_sync' =>
                    $current['last_sync'] ?? null,
            ];

            $errors = validate_kintone(
                $config,
                true
            );

            if ($errors) {
                flash(
                    'error',
                    implode("\n", $errors)
                );

                return ['screen' => 'kintone'];
            }

            $settings['kintone'] = $config;
            save_settings($settings);

            flash(
                'success',
                'kintone設定を保存しました。'
            );

            return ['screen' => 'kintone'];

        case 'test_kintone':
            try {
                $r = kintone_test(
                    $settings['kintone']
                );

                $settings['kintone']['last_test'] = now();
                save_settings($settings);

                flash(
                    'success',
                    'kintone接続テスト成功。HTTP ' .
                    (int)$r['status']
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'kintone接続テスト失敗：' .
                    safe_external_error($e)
                );
            }

            return ['screen' => 'kintone'];

        case 'load_kintone_fields':
            try {
                $r = kintone_fields(
                    $settings['kintone']
                );

                $fields = kintone_field_list($r);

                if (!$fields) {
                    throw new RuntimeException(
                        'kintoneから項目を取得できませんでした。'
                    );
                }

                $settings['kintone']['fields'] = $fields;
                save_settings($settings);

                flash(
                    'success',
                    count($fields) .
                    '件の項目を取得しました。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'kintone項目取得失敗：' .
                    safe_external_error($e)
                );
            }

            return ['screen' => 'kintone'];

        case 'save_kintone_mapping':
            $fields =
                $settings['kintone']['fields'] ?? [];

            $valid = [];

            foreach ($fields as $f) {
                if (isset($f['code'])) {
                    $valid[] = (string)$f['code'];
                }
            }

            $mapping = [
                'organization' =>
                    post_string('mapping_organization'),
                'name' =>
                    post_string('mapping_name'),
                'email' =>
                    post_string('mapping_email'),
                'department' =>
                    post_string('mapping_department'),
                'phone' =>
                    post_string('mapping_phone'),
                'address' => [],
            ];

            $addr = post(
                'mapping_address',
                []
            );

            if (is_array($addr)) {
                foreach ($addr as $code) {
                    $code = trim((string)$code);

                    if (
                        $code !== '' &&
                        in_array($code, $valid, true)
                    ) {
                        $mapping['address'][] = $code;
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
                    $mapping[$key] !== '' &&
                    !in_array(
                        $mapping[$key],
                        $valid,
                        true
                    )
                ) {
                    $mapping[$key] = '';
                }
            }

            $settings['kintone']['mapping'] =
                $mapping;

            save_settings($settings);

            flash(
                'success',
                'kintone項目マッピングを保存しました。'
            );

            return ['screen' => 'kintone'];

        case 'sync_kintone':
            try {
                $r = kintone_records(
                    $settings['kintone']
                );

                $records =
                    $r['body']['records'] ?? [];

                if (!is_array($records)) {
                    throw new RuntimeException(
                        'kintoneレコードを取得できませんでした。'
                    );
                }

                $m =
                    $settings['kintone']['mapping']
                    ?? [];

                $customers = [];

                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }

                    $customers[] = [
                        'id' => uid('customer'),
                        'organization' =>
                            krecord(
                                $record,
                                (string)($m['organization'] ?? '')
                            ),
                        'name' =>
                            krecord(
                                $record,
                                (string)($m['name'] ?? '')
                            ),
                        'email' =>
                            krecord(
                                $record,
                                (string)($m['email'] ?? '')
                            ),
                        'department' =>
                            krecord(
                                $record,
                                (string)($m['department'] ?? '')
                            ),
                        'phone' =>
                            krecord(
                                $record,
                                (string)($m['phone'] ?? '')
                            ),
                        'address' =>
                            implode(
                                ' ',
                                array_filter(
                                    array_map(
                                        static fn($code): string =>
                                            krecord(
                                                $record,
                                                (string)$code
                                            ),
                                        $m['address'] ?? []
                                    )
                                )
                            ),
                    ];
                }

                $data['customers'] = $customers;

                $settings['kintone']['last_sync'] =
                    now();

                save_data($data);
                save_settings($settings);

                flash(
                    'success',
                    count($customers) .
                    '件の顧客情報を同期しました。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'kintone同期失敗：' .
                    safe_external_error($e)
                );
            }

            return ['screen' => 'kintone'];

        case 'save_mail':
            $current = $settings['mail'];

            $password = post_string('password');

            if ($password === '') {
                $password =
                    (string)($current['password'] ?? '');
            }

            $config = [
                'host' =>
                    post_string('server'),
                'port' =>
                    (int)post_string('port'),
                'encryption' =>
                    post_string('encryption'),
                'auth' =>
                    post_bool('auth'),
                'username' =>
                    post_string('username'),
                'password' =>
                    $password,
                'from_email' =>
                    post_string('from_email'),
                'from_name' =>
                    post_string('from_name'),
                'reply_to' =>
                    post_string('reply_to'),
                'last_test' =>
                    $current['last_test'] ?? null,
            ];

            $errors = validate_mail($config);

            if ($errors) {
                flash(
                    'error',
                    implode("\n", $errors)
                );

                return ['screen' => 'mail'];
            }

            $settings['mail'] = $config;
            save_settings($settings);

            flash(
                'success',
                'メール設定を保存しました。'
            );

            return ['screen' => 'mail'];

        case 'test_mail':
            try {
                /*
                 * 接続テストは認証まで実施。
                 * メール送信は行わない。
                 */
                $socket = smtp_open(
                    $settings['mail']
                );

                smtp_cmd(
                    $socket,
                    'QUIT',
                    [221]
                );

                fclose($socket);

                $settings['mail']['last_test'] =
                    now();

                save_settings($settings);

                flash(
                    'success',
                    'SMTP接続テスト成功。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'SMTP接続テスト失敗：' .
                    safe_external_error($e)
                );
            }

            return ['screen' => 'mail'];

        case 'send_test_mail':
            $to = post_string('test_email');

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
                    'テストメール送信失敗：' .
                    safe_external_error($e)
                );
            }

            return ['screen' => 'mail'];

        case 'send_mail':
            $surveyId =
                post_string('survey_id');

            $survey = survey_get(
                $data['surveys'],
                $surveyId
            );

            if (!$survey) {
                flash(
                    'error',
                    '対象アンケートが見つかりません。'
                );

                return ['screen' => 'list'];
            }

            $selected = post(
                'customer_ids',
                []
            );

            if (!is_array($selected) || !$selected) {
                flash(
                    'error',
                    '顧客を選択してください。'
                );

                return [
                    'screen' => 'send',
                    'id' => $surveyId,
                ];
            }

            $subject =
                post_string('subject');

            $body =
                (string)post('body', '');

            if (
                $subject === '' ||
                trim($body) === ''
            ) {
                flash(
                    'error',
                    'メール件名と本文を入力してください。'
                );

                return [
                    'screen' => 'send',
                    'id' => $surveyId,
                ];
            }

            $customerMap = [];

            foreach ($data['customers'] as $c) {
                if (is_array($c)) {
                    $customerMap[
                        (string)($c['id'] ?? '')
                    ] = $c;
                }
            }

            $sent = 0;
            $failed = 0;

            foreach ($selected as $cid) {
                $cid = (string)$cid;

                if (!isset($customerMap[$cid])) {
                    $failed++;
                    continue;
                }

                $customer = $customerMap[$cid];

                $email =
                    trim((string)($customer['email'] ?? ''));

                if (
                    !filter_var(
                        $email,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    $failed++;
                    $data['send_history'][] = [
                        'id' => uid('send'),
                        'survey_id' => $surveyId,
                        'customer_id' => $cid,
                        'email' => $email,
                        'status' => 'failed',
                        'message' => 'メールアドレス不正',
                        'createdAt' => now(),
                    ];
                    continue;
                }

                $mailBody = str_replace(
                    [
                        '{顧客名}',
                        '{アンケートURL}',
                    ],
                    [
                        (string)($customer['name'] ?? ''),
                        public_url($surveyId),
                    ],
                    $body
                );

                try {
                    smtp_send(
                        $settings['mail'],
                        $email,
                        $subject,
                        $mailBody
                    );

                    $sent++;

                    $data['send_history'][] = [
                        'id' => uid('send'),
                        'survey_id' => $surveyId,
                        'customer_id' => $cid,
                        'email' => $email,
                        'status' => 'sent',
                        'message' => '',
                        'createdAt' => now(),
                    ];
                } catch (Throwable $e) {
                    $failed++;

                    $data['send_history'][] = [
                        'id' => uid('send'),
                        'survey_id' => $surveyId,
                        'customer_id' => $cid,
                        'email' => $email,
                        'status' => 'failed',
                        'message' => safe_external_error($e),
                        'createdAt' => now(),
                    ];
                }
            }

            save_data($data);

            flash(
                $failed
                    ? 'warning'
                    : 'success',
                '送信完了：成功 ' .
                $sent .
                '件 / 失敗 ' .
                $failed .
                '件'
            );

            return [
                'screen' => 'send',
                'id' => $surveyId,
            ];

        case 'answer_confirm':
            $surveyId =
                post_string('survey_id');

            $survey = survey_get(
                $data['surveys'],
                $surveyId
            );

            if (!$survey) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                return [
                    'screen' => 'answer',
                    'id' => $surveyId,
                ];
            }

            $answers = post(
                'answers',
                []
            );

            if (!is_array($answers)) {
                $answers = [];
            }

            $errors = validate_answers(
                $survey,
                $answers
            );

            if ($errors) {
                flash(
                    'error',
                    implode("\n", $errors)
                );

                $_SESSION['answer_draft'] =
                    $answers;

                return [
                    'screen' => 'answer',
                    'id' => $surveyId,
                ];
            }

            $_SESSION['answer_draft'] =
                $answers;

            return [
                'screen' => 'confirm',
                'id' => $surveyId,
            ];

        case 'answer_back':
            return [
                'screen' => 'answer',
                'id' => post_string('survey_id'),
            ];

        case 'submit_answer':
            $surveyId =
                post_string('survey_id');

            $survey = survey_get(
                $data['surveys'],
                $surveyId
            );

            if (!$survey) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                return [
                    'screen' => 'answer',
                    'id' => $surveyId,
                ];
            }

            $draft =
                $_SESSION['answer_draft'] ?? [];

            if (!is_array($draft)) {
                $draft = [];
            }

            $errors = validate_answers(
                $survey,
                $draft
            );

            if ($errors) {
                flash(
                    'error',
                    implode("\n", $errors)
                );

                return [
                    'screen' => 'answer',
                    'id' => $surveyId,
                ];
            }

            $data['answers'][] = [
                'id' => uid('answer'),
                'survey_id' => $surveyId,
                'answers' => $draft,
                'createdAt' => now(),
            ];

            unset(
                $_SESSION['answer_draft']
            );

            save_data($data);

            /*
             * 回答者処理では管理者画面へ戻さない。
             */
            return [
                'screen' => 'complete',
                'id' => $surveyId,
            ];

        default:
            flash(
                'error',
                '不明な操作です。'
            );

            return [
                'screen' => 'list',
            ];
    }
}

/* =========================================================
 * エラー表示用
 * ========================================================= */

function safe_external_error(Throwable $e): string
{
    $m = trim($e->getMessage());

    /*
     * パスワード、Authorization、URLクエリ等を
     * エラー画面へ漏らさない。
     */
    $m = preg_replace(
        '/X-Cybozu-Authorization:\s*[^\s]+/i',
        'X-Cybozu-Authorization: [REDACTED]',
        $m
    ) ?? $m;

    $m = preg_replace(
        '/password\s*[=:]\s*[^\s]+/i',
        'password=[REDACTED]',
        $m
    ) ?? $m;

    return mb_substr($m, 0, 500);
}

/* =========================================================
 * 共通HTML
 * ========================================================= */

function admin_header(
    string $title,
    ?array $flash = null
): void {
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
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
 color:var(--text);
 background:#f8fafc;
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",
 "Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
}
a{color:var(--primary);text-decoration:none}
a:hover{text-decoration:underline}
header{
 background:#fff;
 border-bottom:1px solid var(--border);
}
.nav{
 max-width:1400px;
 margin:auto;
 padding:16px 20px;
 display:flex;
 gap:18px;
 align-items:center;
 flex-wrap:wrap;
}
.logo{
 font-size:20px;
 font-weight:700;
 margin-right:auto;
}
.nav a{
 padding:7px 10px;
 border-radius:7px;
}
.nav a:hover{
 background:var(--gray-light);
 text-decoration:none;
}
main{
 max-width:1400px;
 margin:auto;
 padding:24px 20px 60px;
}
h1{font-size:26px;margin:0 0 20px}
h2{font-size:20px;margin:0 0 16px}
h3{font-size:17px}
.card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:12px;
 padding:20px;
 margin-bottom:18px;
 box-shadow:var(--shadow);
}
.toolbar{
 display:flex;
 gap:10px;
 flex-wrap:wrap;
 align-items:center;
 margin-bottom:18px;
}
button,.btn{
 display:inline-block;
 border:1px solid var(--border);
 background:#fff;
 color:var(--text);
 border-radius:7px;
 padding:9px 14px;
 cursor:pointer;
 font-size:14px;
}
button:hover,.btn:hover{background:var(--gray-light)}
.primary{
 background:var(--primary);
 color:#fff;
 border-color:var(--primary);
}
.primary:hover{
 background:var(--primary-dark);
 color:#fff;
}
.danger{
 color:#fff;
 background:var(--danger);
 border-color:var(--danger);
}
.success{
 color:#fff;
 background:var(--success);
 border-color:var(--success);
}
.warning{
 color:#fff;
 background:var(--warning);
 border-color:var(--warning);
}
.gray{
 color:#fff;
 background:var(--gray);
 border-color:var(--gray);
}
input,select,textarea{
 width:100%;
 padding:9px 10px;
 border:1px solid #cbd5e1;
 border-radius:7px;
 background:#fff;
 font:inherit;
}
textarea{min-height:110px;resize:vertical}
label{
 display:block;
 font-weight:600;
 margin-bottom:6px;
}
.field{margin-bottom:15px}
.grid{
 display:grid;
 grid-template-columns:repeat(2,minmax(0,1fr));
 gap:16px;
}
.grid3{
 display:grid;
 grid-template-columns:repeat(3,minmax(0,1fr));
 gap:16px;
}
.table-wrap{
 overflow-x:auto;
}
table{
 width:100%;
 border-collapse:collapse;
 min-width:900px;
}
th,td{
 padding:10px;
 border-bottom:1px solid var(--border);
 text-align:left;
 vertical-align:top;
}
th{background:#f8fafc}
.badge{
 display:inline-block;
 padding:4px 9px;
 border-radius:999px;
 font-size:12px;
 font-weight:700;
 background:#e2e8f0;
 color:#334155;
}
.badge.success{background:#dcfce7;color:#166534}
.badge.warning{background:#fef3c7;color:#92400e}
.badge.gray{background:#e2e8f0;color:#475569}
.notice{
 padding:12px 14px;
 border-radius:8px;
 margin-bottom:18px;
 white-space:pre-line;
}
.notice.success{background:#dcfce7;color:#166534}
.notice.error{background:#fee2e2;color:#991b1b}
.notice.warning{background:#fef3c7;color:#92400e}
.group{
 border:1px solid var(--border);
 border-radius:10px;
 padding:16px;
 margin-bottom:16px;
 background:#fff;
}
.group.dragging,.question.dragging{
 opacity:.45;
}
.question{
 border:1px solid #e2e8f0;
 border-radius:8px;
 padding:14px;
 margin:12px 0;
 background:#f8fafc;
 cursor:grab;
}
.question-head{
 display:flex;
 gap:10px;
 align-items:center;
}
.question-number{
 font-weight:700;
 min-width:70px;
}
.option{
 display:grid;
 grid-template-columns:1fr 220px auto;
 gap:8px;
 margin:8px 0;
}
.drag-handle{
 color:#64748b;
 cursor:grab;
}
.answer-shell{
 max-width:760px;
 margin:30px auto;
 padding:0 16px;
}
.answer-card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:14px;
 padding:24px;
 box-shadow:var(--shadow);
}
.answer-question{
 margin:20px 0;
 padding:18px;
 border:1px solid var(--border);
 border-radius:10px;
}
.choice{
 display:flex;
 gap:10px;
 align-items:flex-start;
 padding:12px;
 border:1px solid var(--border);
 border-radius:8px;
 margin:8px 0;
 cursor:pointer;
}
.choice input{
 width:auto;
 margin-top:4px;
}
@media(max-width:800px){
 .grid,.grid3{grid-template-columns:1fr}
 .nav{padding:12px}
 main{padding:18px 12px 40px}
 .card{padding:15px}
 .option{grid-template-columns:1fr}
 .question-head{align-items:flex-start}
}
</style>
</head>
<body>
<header>
<nav class="nav">
 <div class="logo"><?= h(APP_TITLE) ?></div>
 <a href="<?= h(app_url(['screen'=>'list'])) ?>">アンケート一覧</a>
 <a href="<?= h(app_url(['screen'=>'kintone'])) ?>">kintone連携</a>
 <a href="<?= h(app_url(['screen'=>'mail'])) ?>">メール設定</a>
</nav>
</header>
<main>
<?php
if ($flash) {
    $type =
        ($flash['type'] ?? '') === 'success'
            ? 'success'
            : (($flash['type'] ?? '') === 'warning'
                ? 'warning'
                : 'error');

    echo '<div class="notice ' . h($type) . '">' .
        h($flash['message'] ?? '') .
        '</div>';
}
}

function admin_footer(): void
{
    ?>
</main>
</body>
</html>
<?php
}

/* =========================================================
 * 一覧
 * ========================================================= */

function render_list(array $data): void
{
    $q = get_string('q');
    $status = get_string('status');
    $sort = get_string('sort');

    $surveys = $data['surveys'];

    $surveys = array_values(
        array_filter(
            $surveys,
            static function(array $s) use ($q, $status): bool {
                if (
                    $q !== '' &&
                    mb_stripos(
                        (string)($s['title'] ?? ''),
                        $q
                    ) === false
                ) {
                    return false;
                }

                if (
                    $status !== '' &&
                    $status !== 'all' &&
                    ($s['status'] ?? '') !== $status
                ) {
                    return false;
                }

                return true;
            }
        )
    );

    usort(
        $surveys,
        static function(array $a, array $b) use ($sort): int {
            if ($sort === 'old') {
                return strcmp(
                    (string)($a['updatedAt'] ?? ''),
                    (string)($b['updatedAt'] ?? '')
                );
            }

            if ($sort === 'answers') {
                return 0;
            }

            return strcmp(
                (string)($b['updatedAt'] ?? ''),
                (string)($a['updatedAt'] ?? '')
            );
        }
    );

    admin_header('アンケート一覧');

    ?>
<h1>アンケート一覧</h1>

<div class="toolbar">
<a class="btn primary"
   href="<?= h(app_url(['screen'=>'edit'])) ?>">
 新規アンケート
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
       placeholder="タイトル">
</div>
<div class="field">
<label>ステータス</label>
<select name="status">
<option value="all">すべて</option>
<option value="published" <?= $status==='published'?'selected':'' ?>>公開中</option>
<option value="draft" <?= $status==='draft'?'selected':'' ?>>下書き</option>
<option value="stopped" <?= $status==='stopped'?'selected':'' ?>>停止</option>
<option value="ended" <?= $status==='ended'?'selected':'' ?>>終了</option>
</select>
</div>
<div class="field">
<label>ソート</label>
<select name="sort">
<option value="">更新日：新しい順</option>
<option value="old" <?= $sort==='old'?'selected':'' ?>>更新日：古い順</option>
<option value="answers" <?= $sort==='answers'?'selected':'' ?>>回答数：多い順</option>
</select>
</div>
</div>
<button class="primary">検索</button>
</form>
</div>

<div class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
<th>タイトル</th>
<th>期間</th>
<th>状態</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>
<tbody>
<?php foreach ($surveys as $s): ?>
<?php
$count = 0;

foreach ($data['answers'] as $a) {
    if (($a['survey_id'] ?? '') === ($s['id'] ?? '')) {
        $count++;
    }
}
?>
<tr>
<td>
<strong><?= h($s['title'] ?? '') ?></strong><br>
<small>
作成: <?= h($s['createdAt'] ?? '') ?><br>
更新: <?= h($s['updatedAt'] ?? '') ?>
</small>
</td>
<td>
<?= h($s['startAt'] ?? '') ?><br>
～ <?= h($s['endAt'] ?? '') ?>
</td>
<td>
<span class="badge <?= h(status_class((string)$s['status'])) ?>">
<?= h(status_label((string)$s['status'])) ?>
</span>
</td>
<td><?= h($count) ?></td>
<td>
<div class="toolbar">
<a class="btn"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>$s['id']
   ])) ?>">編集</a>

<a class="btn"
   href="<?= h(app_url([
       'screen'=>'preview',
       'id'=>$s['id']
   ])) ?>">プレビュー</a>

<a class="btn"
   href="<?= h(app_url([
       'screen'=>'analytics',
       'id'=>$s['id']
   ])) ?>">集計</a>

<a class="btn"
   href="<?= h(app_url([
       'screen'=>'send',
       'id'=>$s['id']
   ])) ?>">送信</a>
</div>

<div class="toolbar">
<form method="post" style="display:inline">
<input type="hidden" name="action"
       value="duplicate_survey">
<input type="hidden" name="survey_id"
       value="<?= h($s['id']) ?>">
<button onclick="return confirm('複製しますか？')">
複製
</button>
</form>

<form method="post" style="display:inline">
<input type="hidden" name="action"
       value="delete_survey">
<input type="hidden" name="survey_id"
       value="<?= h($s['id']) ?>">
<button class="danger"
        onclick="return confirm('削除しますか？')">
削除
</button>
</form>
</div>

<?php if ($s['status'] !== 'ended'): ?>
<form method="post">
<input type="hidden" name="action"
       value="change_status">
<input type="hidden" name="survey_id"
       value="<?= h($s['id']) ?>">

<?php if ($s['status'] === 'draft'): ?>
<input type="hidden" name="status" value="published">
<button class="success"
        onclick="return confirm('公開しますか？')">
公開
</button>
<?php elseif ($s['status'] === 'published'): ?>
<input type="hidden" name="status" value="stopped">
<button class="warning"
        onclick="return confirm('停止しますか？')">
停止
</button>
<?php elseif ($s['status'] === 'stopped'): ?>
<input type="hidden" name="status" value="published">
<button class="success"
        onclick="return confirm('再開しますか？')">
再開
</button>
<?php endif; ?>
</form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>

<?php if (!$surveys): ?>
<tr>
<td colspan="5">アンケートがありません。</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
<?php

    admin_footer();
}

/* =========================================================
 * 編集
 * ========================================================= */

function render_edit(
    array $data,
    ?string $id
): void {
    $survey =
        $id !== null && $id !== ''
            ? survey_get($data['surveys'], $id)
            : null;

    if (!$survey) {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => date('Y-m-d\TH:i'),
            'endAt' => date(
                'Y-m-d\TH:i',
                strtotime('+30 days')
            ),
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [[
                'id' => uid('group'),
                'title' => '基本グループ',
                'questions' => [],
            ]],
        ];
    }

    admin_header(
        $id ? 'アンケート編集' : 'アンケート作成'
    );

    ?>
<h1>
<?= $id ? 'アンケート編集' : 'アンケート作成' ?>
</h1>

<form method="post"
      id="surveyForm"
      onsubmit="return beforeSurveySave()">

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="card">
<div class="toolbar">
<a class="btn"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
キャンセル
</a>

<button class="primary">
保存して一覧へ
</button>

<span>
状態：
<strong>
<?= h(status_label((string)$survey['status'])) ?>
</strong>
</span>
</div>
</div>

<div class="card">
<div class="field">
<label>アンケートタイトル</label>
<input name="title"
       maxlength="<?= MAX_TITLE ?>"
       required
       value="<?= h($survey['title']) ?>">
</div>

<div class="field">
<label>アンケート説明</label>
<textarea name="description"
          maxlength="<?= MAX_DESCRIPTION ?>"><?= h($survey['description']) ?></textarea>
</div>

<div class="grid">
<div class="field">
<label>開始日時</label>
<input type="datetime-local"
       name="startAt"
       required
       value="<?= h($survey['startAt']) ?>">
</div>

<div class="field">
<label>終了日時</label>
<input type="datetime-local"
       name="endAt"
       required
       value="<?= h($survey['endAt']) ?>">
</div>
</div>

<div class="field">
<label>質問番号の採番方式</label>
<select name="numbering"
        onchange="recalcClientNumbers()">
<option value="global"
 <?= $survey['numbering']==='global'?'selected':'' ?>>
アンケート全体で通番（Q1、Q2、Q3…）
</option>
<option value="group"
 <?= $survey['numbering']==='group'?'selected':'' ?>>
グループ毎（Q1-1、Q1-2、Q2-1…）
</option>
</select>
</div>
</div>

<div id="groups">
<?php
$questionNumber = 1;
$groupNumber = 1;

foreach ($survey['groups'] as $g):
?>
<div class="group"
     draggable="true"
     data-group-id="<?= h($g['id']) ?>">

<div class="toolbar">
<span class="drag-handle">☰ グループ</span>

<input type="text"
       name="groups[<?= h($g['id']) ?>][title]"
       value="<?= h($g['title']) ?>"
       placeholder="グループタイトル">

<input type="hidden"
       name="groups[<?= h($g['id']) ?>][id]"
       value="<?= h($g['id']) ?>">

<button type="button"
        onclick="removeGroup(this)">
グループ削除
</button>
</div>

<div class="questions">
<?php
$qno = 1;

foreach ($g['questions'] as $q):
?>
<div class="question"
     draggable="true"
     data-question-id="<?= h($q['id']) ?>">

<input type="hidden"
 name="groups[<?= h($g['id']) ?>][questions][<?= h($q['id']) ?>][id]"
 value="<?= h($q['id']) ?>">

<div class="question-head">
<span class="drag-handle">☷</span>
<span class="question-number">
<?= h($q['number']) ?>
</span>
</div>

<div class="field">
<label>質問文</label>
<textarea
 name="groups[<?= h($g['id']) ?>][questions][<?= h($q['id']) ?>][text]"
 maxlength="<?= MAX_QUESTION ?>"
 required><?= h($q['text']) ?></textarea>
</div>

<div class="grid">
<div class="field">
<label>回答形式</label>
<select
 name="groups[<?= h($g['id']) ?>][questions][<?= h($q['id']) ?>][type]"
 onchange="toggleQuestionType(this)">
<option value="single"
 <?= $q['type']==='single'?'selected':'' ?>>
単一選択
</option>
<option value="multiple"
 <?= $q['type']==='multiple'?'selected':'' ?>>
複数選択
</option>
<option value="text"
 <?= $q['type']==='text'?'selected':'' ?>>
自由記述
</option>
</select>
</div>

<div class="field">
<label>必須</label>
<label>
<input type="checkbox"
 name="groups[<?= h($g['id']) ?>][questions][<?= h($q['id']) ?>][required]"
 value="1"
 <?= !empty($q['required'])?'checked':'' ?>>
 必須回答
</label>
</div>
</div>

<div class="options"
     style="<?= $q['type']==='text'?'display:none':'' ?>">

<strong>選択肢</strong>

<div class="option-list">
<?php foreach ($q['options'] as $o): ?>
<div class="option">
<input type="hidden"
 name="groups[<?= h($g['id']) ?>][questions][<?= h($q['id']) ?>][options][<?= h($o['id']) ?>][id]"
 value="<?= h($o['id']) ?>">

<input type="text"
 name="groups[<?= h($g['id']) ?>][questions][<?= h($q['id']) ?>][options][<?= h($o['id']) ?>][label]"
 value="<?= h($o['label']) ?>"
 placeholder="選択肢">

<select
 name="groups[<?= h($g['id']) ?>][questions][<?= h($q['id']) ?>][options][<?= h($o['id']) ?>][nextQuestionId]">
<option value="">次の質問へ</option>
<?php foreach (all_questions($survey) as $target): ?>
<option value="<?= h($target['id']) ?>"
 <?= ($o['nextQuestionId'] ?? '')===$target['id']?'selected':'' ?>>
<?= h($target['number'] . ' ' . $target['text']) ?>
</option>
<?php endforeach; ?>
</select>

<button type="button"
 onclick="this.closest('.option').remove()">
削除
</button>
</div>
<?php endforeach; ?>
</div>

<button type="button"
 onclick="addOption(this)">
選択肢を追加
</button>
</div>

<button type="button"
 onclick="removeQuestion(this)">
質問を削除
</button>

</div>
<?php
$qno++;
endforeach;
?>
</div>

<!-- 仕様上、質問追加はグループ末尾だけ -->
<button type="button"
        onclick="addQuestion(this)">
このグループの末尾に質問を追加
</button>

</div>
<?php
$groupNumber++;
endforeach;
?>
</div>

<!-- 仕様上、グループ追加は全体末尾だけ -->
<div class="card">
<button type="button"
        class="primary"
        onclick="addGroup()">
グループを追加
</button>
</div>

</form>

<script>
function uid(prefix){
    return prefix + '-' +
        Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2,8);
}

function addGroup(){
    const id = uid('group');
    const wrap = document.getElementById('groups');

    const el = document.createElement('div');
    el.className = 'group';
    el.draggable = true;
    el.dataset.groupId = id;

    el.innerHTML = `
<div class="toolbar">
<span class="drag-handle">☰ グループ</span>
<input type="text"
 name="groups[${id}][title]"
 value="新しいグループ"
 placeholder="グループタイトル">
<input type="hidden"
 name="groups[${id}][id]"
 value="${id}">
<button type="button"
 onclick="removeGroup(this)">
グループ削除
</button>
</div>
<div class="questions"></div>
<button type="button"
 onclick="addQuestion(this)">
このグループの末尾に質問を追加
</button>`;

    wrap.appendChild(el);

    addQuestion(
        el.querySelector('button[onclick^="addQuestion"]')
    );

    installDnD();
    recalcClientNumbers();
}

function addQuestion(button){
    const group = button.closest('.group');
    const gid = group.dataset.groupId;
    const qid = uid('question');

    const q = document.createElement('div');
    q.className = 'question';
    q.draggable = true;
    q.dataset.questionId = qid;

    q.innerHTML = `
<input type="hidden"
 name="groups[${gid}][questions][${qid}][id]"
 value="${qid}">

<div class="question-head">
<span class="drag-handle">☷</span>
<span class="question-number">Q?</span>
</div>

<div class="field">
<label>質問文</label>
<textarea
 name="groups[${gid}][questions][${qid}][text]"
 maxlength="<?= MAX_QUESTION ?>"
 required></textarea>
</div>

<div class="grid">
<div class="field">
<label>回答形式</label>
<select
 name="groups[${gid}][questions][${qid}][type]"
 onchange="toggleQuestionType(this)">
<option value="single">単一選択</option>
<option value="multiple">複数選択</option>
<option value="text">自由記述</option>
</select>
</div>
<div class="field">
<label>必須</label>
<label>
<input type="checkbox"
 name="groups[${gid}][questions][${qid}][required]"
 value="1">
 必須回答
</label>
</div>
</div>

<div class="options">
<strong>選択肢</strong>
<div class="option-list"></div>
<button type="button"
 onclick="addOption(this)">
選択肢を追加
</button>
</div>

<button type="button"
 onclick="removeQuestion(this)">
質問を削除
</button>`;

    group.querySelector('.questions').appendChild(q);

    addOption(
        q.querySelector(
            'button[onclick^="addOption"]'
        )
    );

    addOption(
        q.querySelector(
            'button[onclick^="addOption"]'
        )
    );

    installDnD();
    recalcClientNumbers();
}

function addOption(button){
    const q = button.closest('.question');
    const gid =
        q.closest('.group').dataset.groupId;
    const qid = q.dataset.questionId;
    const oid = uid('option');

    const list =
        q.querySelector('.option-list');

    const row = document.createElement('div');
    row.className = 'option';

    row.innerHTML = `
<input type="hidden"
 name="groups[${gid}][questions][${qid}][options][${oid}][id]"
 value="${oid}">

<input type="text"
 name="groups[${gid}][questions][${qid}][options][${oid}][label]"
 placeholder="選択肢">

<select
 name="groups[${gid}][questions][${qid}][options][${oid}][nextQuestionId]">
<option value="">次の質問へ</option>
</select>

<button type="button"
 onclick="this.closest('.option').remove()">
削除
</button>`;

    list.appendChild(row);
    refreshBranchTargets();
}

function removeQuestion(button){
    if (!confirm('質問を削除しますか？')) {
        return;
    }

    button.closest('.question').remove();
    recalcClientNumbers();
}

function removeGroup(button){
    const groups =
        document.querySelectorAll('.group');

    if (groups.length <= 1) {
        alert('グループは最低1つ必要です。');
        return;
    }

    if (!confirm('グループを削除しますか？')) {
        return;
    }

    button.closest('.group').remove();
    recalcClientNumbers();
    refreshBranchTargets();
}

function toggleQuestionType(select){
    const q = select.closest('.question');
    const options =
        q.querySelector('.options');

    if (select.value === 'text') {
        options.style.display = 'none';
    } else {
        options.style.display = '';
    }

    if (select.value !== 'single') {
        q.querySelectorAll(
            'select[name*="[nextQuestionId]"]'
        ).forEach(x => x.value = '');
    }
}

function recalcClientNumbers(){
    let global = 1;
    let groupNo = 1;
    const numbering =
        document.querySelector(
            'select[name="numbering"]'
        )?.value || 'global';

    document.querySelectorAll('.group')
        .forEach(group => {
            let local = 1;

            group.querySelectorAll(
                '.questions > .question'
            ).forEach(q => {
                q.querySelector(
                    '.question-number'
                ).textContent =
                    numbering === 'group'
                        ? `Q${groupNo}-${local}`
                        : `Q${global}`;

                global++;
                local++;
            });

            groupNo++;
        });

    refreshBranchTargets();
}

function refreshBranchTargets(){
    const questions =
        [...document.querySelectorAll('.question')];

    const targets = questions.map(q => ({
        id: q.dataset.questionId,
        number: q.querySelector(
            '.question-number'
        )?.textContent || 'Q?',
        text: q.querySelector(
            'textarea'
        )?.value || ''
    }));

    document.querySelectorAll(
        'select[name*="[nextQuestionId]"]'
    ).forEach(select => {
        const current = select.value;

        select.innerHTML =
            '<option value="">次の質問へ</option>';

        targets.forEach(t => {
            const opt =
                document.createElement('option');

            opt.value = t.id;
            opt.textContent =
                t.number + ' ' + t.text.slice(0,80);

            if (t.id === current) {
                opt.selected = true;
            }

            select.appendChild(opt);
        });
    });
}

function beforeSurveySave(){
    recalcClientNumbers();

    let ok = true;

    document.querySelectorAll(
        '.question textarea'
    ).forEach(x => {
        if (!x.value.trim()) {
            ok = false;
        }
    });

    if (!ok) {
        alert('質問文を入力してください。');
        return false;
    }

    return true;
}

function installDnD(){
    document.querySelectorAll(
        '.group,.question'
    ).forEach(el => {
        if (el.dataset.dndInstalled) {
            return;
        }

        el.dataset.dndInstalled = '1';

        el.addEventListener(
            'dragstart',
            e => {
                el.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData(
                    'text/plain',
                    el.dataset.questionId ||
                    el.dataset.groupId
                );
            }
        );

        el.addEventListener(
            'dragend',
            () => {
                el.classList.remove('dragging');
                recalcClientNumbers();
            }
        );

        el.addEventListener(
            'dragover',
            e => {
                e.preventDefault();
                const dragging =
                    document.querySelector('.dragging');

                if (!dragging || dragging === el) {
                    return;
                }

                if (
                    dragging.classList.contains('group') &&
                    el.classList.contains('group')
                ) {
                    el.parentNode.insertBefore(
                        dragging,
                        el
                    );
                }

                if (
                    dragging.classList.contains('question')
                ) {
                    const targetGroup =
                        el.classList.contains('group')
                            ? el
                            : el.closest('.group');

                    const questions =
                        targetGroup.querySelector('.questions');

                    if (el.classList.contains('question')) {
                        questions.insertBefore(
                            dragging,
                            el
                        );
                    } else if (questions) {
                        questions.appendChild(dragging);
                    }
                }

                recalcClientNumbers();
            }
        );
    });
}

document.addEventListener(
    'input',
    e => {
        if (
            e.target.matches(
                '.question textarea'
            )
        ) {
            refreshBranchTargets();
        }
    }
);

installDnD();
recalcClientNumbers();
</script>
<?php

    admin_footer();
}

/* =========================================================
 * プレビュー
 * ========================================================= */

function render_preview(
    array $data,
    string $id
): void {
    $survey = survey_get(
        $data['surveys'],
        $id
    );

    if (!$survey) {
        render_error(
            '対象アンケートが見つかりません。'
        );
        return;
    }

    admin_header('プレビュー');

    ?>
<h1>プレビュー</h1>

<div class="toolbar">
<a class="btn"
 href="<?= h(app_url(['screen'=>'list'])) ?>">
一覧
</a>
<a class="btn"
 href="<?= h(app_url([
     'screen'=>'edit',
     'id'=>$id
 ])) ?>">
編集
</a>
</div>

<div class="answer-shell">
<div class="answer-card">
<h1><?= h($survey['title']) ?></h1>
<p><?= nl2br(h($survey['description'])) ?></p>

<?php foreach ($survey['groups'] as $g): ?>
<h2><?= h($g['title']) ?></h2>

<?php foreach ($g['questions'] as $q): ?>
<div class="answer-question">
<strong>
<?= h($q['number']) ?>
<?= !empty($q['required']) ? ' *' : '' ?>
</strong>

<p><?= nl2br(h($q['text'])) ?></p>

<?php foreach ($q['options'] as $o): ?>
<div class="choice">
<?= $q['type']==='single'
    ? '○'
    : '□' ?>
<?= h($o['label']) ?>
<?php if (!empty($o['nextQuestionId'])): ?>
<small>
→ 条件分岐あり
</small>
<?php endif; ?>
</div>
<?php endforeach; ?>

<?php if ($q['type']==='text'): ?>
<textarea disabled></textarea>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php endforeach; ?>

</div>
</div>
<?php

    admin_footer();
}

/* =========================================================
 * 回答者画面
 * ========================================================= */

function render_answer(
    array $data,
    string $id
): void {
    $survey = survey_get(
        $data['surveys'],
        $id
    );

    if (!$survey) {
        render_error(
            'アンケートが見つかりません。'
        );
        return;
    }

    $status = (string)$survey['status'];

    if ($status !== 'published') {
        render_answer_message(
            'このアンケートは現在回答できません。'
        );
        return;
    }

    $answers =
        $_SESSION['answer_draft'] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $questions = visible_questions(
        $survey,
        $answers
    );

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= h($survey['title']) ?></title>
<style>
body{
 margin:0;
 background:#f8fafc;
 color:#1e293b;
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",Meiryo,sans-serif;
}
.wrap{
 max-width:760px;
 margin:30px auto;
 padding:0 16px;
}
.card{
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:14px;
 padding:24px;
 box-shadow:0 4px 18px rgba(15,23,42,.08);
}
.q{
 margin:20px 0;
 padding:18px;
 border:1px solid #dbe2ea;
 border-radius:10px;
}
label{
 display:block;
 font-weight:600;
 margin-bottom:7px;
}
textarea{
 width:100%;
 min-height:130px;
 padding:10px;
 box-sizing:border-box;
 border:1px solid #cbd5e1;
 border-radius:8px;
 font:inherit;
}
.choice{
 display:flex;
 gap:10px;
 padding:12px;
 margin:8px 0;
 border:1px solid #dbe2ea;
 border-radius:8px;
}
button{
 width:100%;
 padding:13px;
 background:#2563eb;
 color:#fff;
 border:0;
 border-radius:8px;
 font-size:16px;
 cursor:pointer;
}
</style>
</head>
<body>
<div class="wrap">
<div class="card">

<h1><?= h($survey['title']) ?></h1>

<p><?= nl2br(h($survey['description'])) ?></p>

<form method="post">

<input type="hidden"
 name="action"
 value="answer_confirm">

<input type="hidden"
 name="survey_id"
 value="<?= h($id) ?>">

<?php foreach ($questions as $q): ?>
<div class="q">

<label>
<?= h($q['number']) ?>
<?= !empty($q['required']) ? ' *' : '' ?>
<br>
<?= nl2br(h($q['text'])) ?>
</label>

<?php if ($q['type'] === 'single'): ?>

<?php foreach ($q['options'] as $o): ?>
<label class="choice">
<input type="radio"
 name="answers[<?= h($q['id']) ?>]"
 value="<?= h($o['id']) ?>"
 <?= (($answers[$q['id']] ?? '') === $o['id'])
    ? 'checked'
    : '' ?>>
<span><?= h($o['label']) ?></span>
</label>
<?php endforeach; ?>

<?php elseif ($q['type'] === 'multiple'): ?>

<?php
$current = $answers[$q['id']] ?? [];
if (!is_array($current)) {
    $current = [];
}
?>

<?php foreach ($q['options'] as $o): ?>
<label class="choice">
<input type="checkbox"
 name="answers[<?= h($q['id']) ?>][]"
 value="<?= h($o['id']) ?>"
 <?= in_array(
        $o['id'],
        $current,
        true
    ) ? 'checked' : '' ?>>
<span><?= h($o['label']) ?></span>
</label>
<?php endforeach; ?>

<?php else: ?>

<textarea
 name="answers[<?= h($q['id']) ?>]"
 <?= !empty($q['required']) ? 'required' : '' ?>><?= h(
     is_scalar($answers[$q['id']] ?? '')
        ? $answers[$q['id']]
        : ''
 ) ?></textarea>

<?php endif; ?>

</div>
<?php endforeach; ?>

<button type="submit">
回答を確認する
</button>

</form>

</div>
</div>
</body>
</html>
<?php
}

/* =========================================================
 * 回答確認
 * ========================================================= */

function render_confirm(
    array $data,
    string $id
): void {
    $survey = survey_get(
        $data['surveys'],
        $id
    );

    if (!$survey) {
        render_answer_message(
            'アンケートが見つかりません。'
        );
        return;
    }

    $answers =
        $_SESSION['answer_draft'] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
 content="width=device-width,initial-scale=1">
<title>回答確認</title>
<style>
body{
 margin:0;
 background:#f8fafc;
 color:#1e293b;
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",Meiryo,sans-serif;
}
.wrap{
 max-width:760px;
 margin:30px auto;
 padding:0 16px;
}
.card{
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:14px;
 padding:24px;
}
.row{
 border-bottom:1px solid #e2e8f0;
 padding:14px 0;
}
.actions{
 display:flex;
 gap:10px;
 margin-top:20px;
}
button{
 padding:12px 18px;
 border-radius:8px;
 border:1px solid #cbd5e1;
 background:#fff;
 cursor:pointer;
}
.primary{
 background:#2563eb;
 border-color:#2563eb;
 color:#fff;
}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
<h1>回答確認</h1>

<p><?= h($survey['title']) ?></p>

<?php foreach (visible_questions(
    $survey,
    $answers
) as $q): ?>

<div class="row">
<strong><?= h($q['number']) ?></strong>
<br>
<?= nl2br(h($q['text'])) ?>
<br>
<strong>
<?php
$v = $answers[$q['id']] ?? '';

if (is_array($v)) {
    $labels = [];

    foreach ($q['options'] as $o) {
        if (in_array(
            $o['id'],
            $v,
            true
        )) {
            $labels[] = $o['label'];
        }
    }

    echo h(implode(', ', $labels));
} else {
    $label = $v;

    foreach ($q['options'] as $o) {
        if ((string)$o['id'] === (string)$v) {
            $label = $o['label'];
            break;
        }
    }

    echo nl2br(h((string)$label));
}
?>
</strong>
</div>

<?php endforeach; ?>

<div class="actions">

<form method="post">
<input type="hidden"
 name="action"
 value="answer_back">
<input type="hidden"
 name="survey_id"
 value="<?= h($id) ?>">
<button>回答を修正</button>
</form>

<form method="post"
 onsubmit="return confirm('回答を送信しますか？')">
<input type="hidden"
 name="action"
 value="submit_answer">
<input type="hidden"
 name="survey_id"
 value="<?= h($id) ?>">
<button class="primary">
回答を送信する
</button>
</form>

</div>

</div>
</div>
</body>
</html>
<?php
}

/* =========================================================
 * 完了
 * ========================================================= */

function render_complete(
    array $data,
    string $id
): void {
    $survey = survey_get(
        $data['surveys'],
        $id
    );

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
 content="width=device-width,initial-scale=1">
<title>回答完了</title>
<style>
body{
 margin:0;
 background:#f8fafc;
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",Meiryo,sans-serif;
 color:#1e293b;
}
.wrap{
 max-width:640px;
 margin:60px auto;
 padding:20px;
}
.card{
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:14px;
 padding:35px;
 text-align:center;
 box-shadow:0 4px 18px rgba(15,23,42,.08);
}
.ok{
 color:#16a34a;
 font-size:48px;
}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
<div class="ok">✓</div>
<h1>回答ありがとうございました</h1>
<?php if ($survey): ?>
<p><?= h($survey['title']) ?></p>
<?php endif; ?>
<p>回答を受け付けました。</p>
</div>
</div>
</body>
</html>
<?php
}

/* =========================================================
 * kintone画面
 * ========================================================= */

function render_kintone(
    array $settings
): void {
    $c = $settings['kintone'];

    admin_header('kintone連携');

    ?>
<h1>kintone連携設定</h1>

<div class="card">
<form method="post">

<input type="hidden"
 name="action"
 value="save_kintone">

<div class="field">
<label>kintoneサブドメイン</label>
<input name="subdomain"
 value="<?= h($c['subdomain']) ?>"
 placeholder="https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx">
</div>

<div class="field">
<label>顧客管理アプリID</label>
<input name="app_id"
 value="<?= h($c['app_id']) ?>">
</div>

<div class="field">
<label>ログイン名</label>
<input name="username"
 value="<?= h($c['username']) ?>">
</div>

<div class="field">
<label>パスワード</label>
<input type="password"
 name="password"
 placeholder="変更しない場合は空欄">
</div>

<div class="field">
<label>Proxy</label>
<input name="proxy"
 value="<?= h($c['proxy']) ?>"
 placeholder="host:port">
</div>

<div class="field">
<label>
<input type="checkbox"
 name="verify_ssl"
 value="1"
 <?= !empty($c['verify_ssl'])?'checked':'' ?>>
 SSL証明書を検証する
</label>
</div>

<button class="primary">
設定保存
</button>
</form>
</div>

<div class="card">
<h2>接続確認</h2>

<form method="post">
<input type="hidden"
 name="action"
 value="test_kintone">
<button class="primary">
接続テスト
</button>
</form>
</div>

<div class="card">
<h2>項目取得</h2>

<form method="post">
<input type="hidden"
 name="action"
 value="load_kintone_fields">
<button>
項目一覧を再取得
</button>
</form>

<?php if (!empty($c['fields'])): ?>
<div class="table-wrap">
<table>
<tr>
<th>コード</th>
<th>ラベル</th>
<th>タイプ</th>
</tr>
<?php foreach ($c['fields'] as $f): ?>
<tr>
<td><?= h($f['code']) ?></td>
<td><?= h($f['label']) ?></td>
<td><?= h($f['type']) ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>
</div>

<div class="card">
<h2>顧客情報同期</h2>

<form method="post">
<input type="hidden"
 name="action"
 value="sync_kintone">
<button class="primary">
顧客情報を同期
</button>
</form>
</div>

<div class="card">
<h2>顧客項目マッピング</h2>

<form method="post">

<input type="hidden"
 name="action"
 value="save_kintone_mapping">

<?php
$map = $c['mapping'];
$fields = $c['fields'];
?>

<?php
$mapFields = [
    'organization' => '組織',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署',
    'phone' => '電話',
];
?>

<?php foreach ($mapFields as $key => $label): ?>
<div class="field">
<label><?= h($label) ?></label>
<select name="mapping_<?= h($key) ?>">
<option value="">未設定</option>
<?php foreach ($fields as $f): ?>
<option value="<?= h($f['code']) ?>"
 <?= ($map[$key] ?? '') === $f['code']
    ? 'selected'
    : '' ?>>
<?= h($f['code'] . ' / ' . $f['label']) ?>
</option>
<?php endforeach; ?>
</select>
</div>
<?php endforeach; ?>

<div class="field">
<label>住所（複数項目可）</label>
<?php foreach ($fields as $f): ?>
<label>
<input type="checkbox"
 name="mapping_address[]"
 value="<?= h($f['code']) ?>"
 <?= in_array(
        $f['code'],
        $map['address'] ?? [],
        true
    ) ? 'checked' : '' ?>>
<?= h($f['code'] . ' / ' . $f['label']) ?>
</label>
<?php endforeach; ?>
</div>

<button class="primary">
マッピング保存
</button>

</form>
</div>

<?php
    admin_footer();
}

/* =========================================================
 * メール画面
 * ========================================================= */

function render_mail(
    array $settings
): void {
    $c = $settings['mail'];

    admin_header('メール設定');

    ?>
<h1>メールサーバ設定</h1>

<div class="card">
<form method="post">

<input type="hidden"
 name="action"
 value="save_mail">

<div class="grid">
<div class="field">
<label>SMTPサーバ</label>
<input name="server"
 value="<?= h($c['host']) ?>">
</div>

<div class="field">
<label>SMTPポート</label>
<input type="number"
 name="port"
 min="1"
 max="65535"
 value="<?= h($c['port']) ?>">
</div>
</div>

<div class="field">
<label>暗号化方式</label>
<select name="encryption">
<option value="ssl"
 <?= $c['encryption']==='ssl'?'selected':'' ?>>
SSL
</option>
<option value="tls"
 <?= $c['encryption']==='tls'?'selected':'' ?>>
TLS
</option>
<option value="none"
 <?= $c['encryption']==='none'?'selected':'' ?>>
なし
</option>
</select>
</div>

<div class="field">
<label>
<input type="checkbox"
 name="auth"
 value="1"
 <?= !empty($c['auth'])?'checked':'' ?>>
 SMTP認証を使用する
</label>
</div>

<div class="grid">
<div class="field">
<label>SMTPユーザー名</label>
<input name="username"
 value="<?= h($c['username']) ?>">
</div>

<div class="field">
<label>SMTPパスワード</label>
<input type="password"
 name="password"
 placeholder="変更しない場合は空欄">
</div>
</div>

<div class="grid">
<div class="field">
<label>送信元メールアドレス</label>
<input type="email"
 name="from_email"
 value="<?= h($c['from_email']) ?>">
</div>

<div class="field">
<label>送信元名</label>
<input name="from_name"
 value="<?= h($c['from_name']) ?>">
</div>
</div>

<div class="field">
<label>返信先メールアドレス</label>
<input type="email"
 name="reply_to"
 value="<?= h($c['reply_to']) ?>">
</div>

<button class="primary">
設定保存
</button>

</form>
</div>

<div class="card">
<h2>接続テスト</h2>

<p>
接続テストではSMTP接続および認証まで行います。
</p>

<form method="post">
<input type="hidden"
 name="action"
 value="test_mail">
<button class="primary">
接続テスト
</button>
</form>
</div>

<div class="card">
<h2>テストメール</h2>

<form method="post">
<input type="hidden"
 name="action"
 value="send_test_mail">

<div class="field">
<label>送信先</label>
<input type="email"
 name="test_email"
 required>
</div>

<button class="primary">
テストメール送信
</button>
</form>
</div>

<?php
    admin_footer();
}

/* =========================================================
 * 送信画面
 * ========================================================= */

function render_send(
    array $data,
    string $id
): void {
    $survey = survey_get(
        $data['surveys'],
        $id
    );

    if (!$survey) {
        render_error(
            '対象アンケートが見つかりません。'
        );
        return;
    }

    admin_header('顧客選択・メール送信');

    $history = array_values(
        array_filter(
            $data['send_history'],
            static fn(array $h): bool =>
                (string)($h['survey_id'] ?? '') === $id
        )
    );

    ?>
<h1>
顧客選択・メール送信
</h1>

<div class="card">
<h2>対象アンケート</h2>
<strong><?= h($survey['title']) ?></strong>
</div>

<div class="card">
<form method="post"
      onsubmit="return confirm('選択した顧客へ送信しますか？')">

<input type="hidden"
 name="action"
 value="send_mail">

<input type="hidden"
 name="survey_id"
 value="<?= h($id) ?>">

<div class="field">
<label>顧客検索</label>
<input type="search"
 id="customerSearch"
 placeholder="氏名・組織・メール">
</div>

<div class="table-wrap">
<table id="customerTable">
<thead>
<tr>
<th></th>
<th>氏名</th>
<th>組織</th>
<th>メール</th>
</tr>
</thead>
<tbody>
<?php foreach ($data['customers'] as $c): ?>
<tr data-search="<?= h(
    strtolower(
        implode(' ', [
            $c['name'] ?? '',
            $c['organization'] ?? '',
            $c['email'] ?? '',
        ])
    )
) ?>">
<td>
<input type="checkbox"
 name="customer_ids[]"
 value="<?= h($c['id']) ?>">
</td>
<td><?= h($c['name'] ?? '') ?></td>
<td><?= h($c['organization'] ?? '') ?></td>
<td><?= h($c['email'] ?? '') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="field">
<label>件名</label>
<input name="subject"
 required
 value="<?= h(
     $survey['title'] . ' のご案内'
 ) ?>">
</div>

<div class="field">
<label>
本文
</label>

<p>
使用可能な変数：
<code>{顧客名}</code>
<code>{アンケートURL}</code>
</p>

<textarea name="body"
 required><?= h(
"いつもお世話になっております。

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。"
) ?></textarea>
</div>

<button class="primary">
一括送信
</button>

</form>
</div>

<div class="card">
<h2>送信履歴</h2>

<?php if (!$history): ?>
<p>送信履歴はありません。</p>
<?php else: ?>

<div class="table-wrap">
<table>
<tr>
<th>日時</th>
<th>メール</th>
<th>結果</th>
<th>内容</th>
</tr>

<?php foreach (array_reverse($history) as $h): ?>
<tr>
<td><?= h($h['createdAt'] ?? '') ?></td>
<td><?= h($h['email'] ?? '') ?></td>
<td><?= h(
    ($h['status'] ?? '') === 'sent'
        ? '送信済み'
        : '失敗'
) ?></td>
<td><?= h($h['message'] ?? '') ?></td>
</tr>
<?php endforeach; ?>

</table>
</div>

<?php endif; ?>
</div>

<script>
const search =
    document.getElementById('customerSearch');

search?.addEventListener('input', () => {
    const q =
        search.value.trim().toLowerCase();

    document.querySelectorAll(
        '#customerTable tbody tr'
    ).forEach(row => {
        row.style.display =
            !q ||
            (row.dataset.search || '').includes(q)
                ? ''
                : 'none';
    });
});
</script>
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
    $survey = survey_get(
        $data['surveys'],
        $id
    );

    if (!$survey) {
        render_error(
            '対象アンケートが見つかりません。'
        );
        return;
    }

    $answers = array_values(
        array_filter(
            $data['answers'],
            static fn(array $a): bool =>
                (string)($a['survey_id'] ?? '') === $id
        )
    );

    $sent = array_values(
        array_filter(
            $data['send_history'],
            static fn(array $s): bool =>
                (string)($s['survey_id'] ?? '') === $id &&
                ($s['status'] ?? '') === 'sent'
        )
    );

    $sentCount = count($sent);
    $answerCount = count($answers);
    $unanswered =
        max(0, $sentCount - $answerCount);

    $rate =
        $sentCount > 0
            ? round(
                $answerCount /
                $sentCount *
                100,
                1
            )
            : 0;

    admin_header('回答集計・分析');

    ?>
<h1>回答集計・分析</h1>

<div class="card">
<h2><?= h($survey['title']) ?></h2>

<div class="grid3">
<div>
<strong>送信対象者数</strong>
<p><?= h($sentCount) ?></p>
</div>

<div>
<strong>回答数</strong>
<p><?= h($answerCount) ?></p>
</div>

<div>
<strong>回答率</strong>
<p><?= h($rate) ?>%</p>
</div>
</div>

<div class="grid3">
<div>
<strong>未登録回答数</strong>
<p>0</p>
</div>

<div>
<strong>未回答数</strong>
<p><?= h($unanswered) ?></p>
</div>

<div>
<strong>回答率</strong>
<p><?= h($rate) ?>%</p>
</div>
</div>
</div>

<?php if (!$answers): ?>

<div class="card">
<p>現在、回答データはありません</p>
</div>

<?php else: ?>

<div class="card">
<div class="toolbar">
<a class="btn"
 href="<?= h(app_url([
     'screen'=>'analytics',
     'id'=>$id,
     'export'=>'csv'
 ])) ?>">
CSV
</a>

<a class="btn"
 href="<?= h(app_url([
     'screen'=>'analytics',
     'id'=>$id,
     'export'=>'pdf'
 ])) ?>">
PDF
</a>
</div>
</div>

<?php foreach (all_questions($survey) as $q): ?>

<div class="card">
<h2>
<?= h($q['number']) ?>
<?= h($q['text']) ?>
</h2>

<?php
$counts = [];

foreach ($q['options'] as $o) {
    $counts[$o['id']] = 0;
}

$textAnswers = [];

foreach ($answers as $a) {
    $v =
        $a['answers'][$q['id']]
        ?? null;

    if (is_array($v)) {
        foreach ($v as $x) {
            if (isset($counts[$x])) {
                $counts[$x]++;
            }
        }
    } elseif (
        $q['type'] === 'single' &&
        isset($counts[$v])
    ) {
        $counts[$v]++;
    } elseif (
        $q['type'] === 'text' &&
        $v !== null &&
        $v !== ''
    ) {
        $textAnswers[] = (string)$v;
    }
}
?>

<?php if ($q['type'] !== 'text'): ?>

<table>
<tr>
<th>選択肢</th>
<th>回答数</th>
</tr>

<?php foreach ($q['options'] as $o): ?>
<tr>
<td><?= h($o['label']) ?></td>
<td><?= h($counts[$o['id']] ?? 0) ?></td>
</tr>
<?php endforeach; ?>

</table>

<?php else: ?>

<?php foreach ($textAnswers as $v): ?>
<p>
<?= nl2br(h($v)) ?>
</p>
<?php endforeach; ?>

<?php endif; ?>
</div>

<?php endforeach; ?>

<div class="card">
<h2>個別回答</h2>

<?php foreach ($answers as $a): ?>
<div class="group">
<strong>
<?= h($a['createdAt'] ?? '') ?>
</strong>

<?php foreach (
    $a['answers'] ?? [] as $qid => $v
): ?>
<?php
$q = null;

foreach (all_questions($survey) as $x) {
    if ((string)$x['id'] === (string)$qid) {
        $q = $x;
        break;
    }
}

if (!$q) {
    continue;
}

if (is_array($v)) {
    $v = implode(', ', $v);
}
?>

<p>
<strong>
<?= h($q['number']) ?>
<?= h($q['text']) ?>
</strong><br>
<?= nl2br(h((string)$v)) ?>
</p>

<?php endforeach; ?>

</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php
    admin_footer();
}

/* =========================================================
 * エラー
 * ========================================================= */

function render_error(string $message): void
{
    admin_header('エラー');

    ?>
<div class="card">
<h1>エラー</h1>
<p><?= nl2br(h($message)) ?></p>
<a class="btn"
 href="<?= h(app_url(['screen'=>'list'])) ?>">
アンケート一覧へ
</a>
</div>
<?php

    admin_footer();
}

function render_answer_message(
    string $message
): void {
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
 content="width=device-width,initial-scale=1">
<title>アンケート</title>
<style>
body{
 margin:0;
 background:#f8fafc;
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",Meiryo,sans-serif;
}
.wrap{
 max-width:650px;
 margin:60px auto;
 padding:20px;
}
.card{
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:14px;
 padding:30px;
 text-align:center;
}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
<h1>アンケート</h1>
<p><?= nl2br(h($message)) ?></p>
</div>
</div>
</body>
</html>
<?php
}

/* =========================================================
 * 起動
 * ========================================================= */

try {
    start_app();

    $data = load_data();
    $settings = load_settings();

    refresh_status($data);

    /*
     * POST:
     * 業務処理 → データ保存 → 結果画面決定
     *
     * 302/303は使用しない。
     */
    $route = [
        'screen' => get_string('screen') ?: 'list',
        'id' => get_string('id'),
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $route = handle_post(
            $data,
            $settings
        );
    }

    $screen =
        (string)($route['screen'] ?? 'list');

    $id =
        (string)($route['id'] ?? '');

    /*
     * CSV/PDFは対象アンケートIDが必須。
     */
    if (
        $screen === 'analytics' &&
        $id !== '' &&
        get_string('export') === 'csv'
    ) {
        $survey = survey_get(
            $data['surveys'],
            $id
        );

        if (!$survey) {
            render_error(
                '対象アンケートが見つかりません。'
            );
            exit;
        }

        $answers = array_values(
            array_filter(
                $data['answers'],
                static fn(array $a): bool =>
                    (string)($a['survey_id'] ?? '') === $id
            )
        );

        output_csv(
            $survey,
            $answers
        );
    }

    if (
        $screen === 'analytics' &&
        $id !== '' &&
        get_string('export') === 'pdf'
    ) {
        $survey = survey_get(
            $data['surveys'],
            $id
        );

        if (!$survey) {
            render_error(
                '対象アンケートが見つかりません。'
            );
            exit;
        }

        $answers = array_values(
            array_filter(
                $data['answers'],
                static fn(array $a): bool =>
                    (string)($a['survey_id'] ?? '') === $id
            )
        );

        output_pdf(
            $survey,
            $answers
        );
    }

    /*
     * 回答者画面は管理者ヘッダーを出さない。
     */
    if ($screen === 'answer') {
        render_answer($data, $id);
        exit;
    }

    if ($screen === 'confirm') {
        render_confirm($data, $id);
        exit;
    }

    if ($screen === 'complete') {
        render_complete($data, $id);
        exit;
    }

    /*
     * 管理者画面
     */
    switch ($screen) {
        case 'list':
            render_list($data);
            break;

        case 'edit':
            render_edit(
                $data,
                $id !== '' ? $id : null
            );
            break;

        case 'preview':
            if ($id === '') {
                render_error(
                    'プレビュー対象のアンケートIDがありません。'
                );
                break;
            }

            render_preview($data, $id);
            break;

        case 'send':
            if ($id === '') {
                render_error(
                    '送信対象のアンケートIDがありません。'
                );
                break;
            }

            render_send($data, $id);
            break;

        case 'analytics':
            if ($id === '') {
                render_error(
                    '集計対象のアンケートIDがありません。'
                );
                break;
            }

            render_analytics($data, $id);
            break;

        case 'kintone':
            render_kintone($settings);
            break;

        case 'mail':
            render_mail($settings);
            break;

        default:
            render_error(
                '指定された画面は存在しません。'
            );
            break;
    }
} catch (Throwable $e) {
    /*
     * 白画面を避ける。
     * 機密情報をそのまま表示しない。
     */
    http_response_code(500);

    $message = safe_external_error($e);

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
 content="width=device-width,initial-scale=1">
<title><?= h(APP_TITLE) ?> - エラー</title>
<style>
body{
 margin:0;
 padding:30px;
 background:#f8fafc;
 color:#1e293b;
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",Meiryo,sans-serif;
}
.box{
 max-width:760px;
 margin:auto;
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:12px;
 padding:25px;
}
.error{
 color:#991b1b;
 background:#fee2e2;
 padding:15px;
 border-radius:8px;
 white-space:pre-line;
}
</style>
</head>
<body>
<div class="box">
<h1>処理中にエラーが発生しました</h1>
<div class="error"><?= h($message) ?></div>
<p>
入力内容・設定内容を確認して、もう一度実行してください。
</p>
</div>
</body>
</html>
<?php
}

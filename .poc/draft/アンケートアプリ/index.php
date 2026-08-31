<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * 正本仕様:
 * - Apache + PHP 8.5
 * - DBなし
 * - PHP cURLなし
 * - index.php 単一エントリ
 * - ?screen=... で画面切替
 * - 外部サービス通信と画面遷移を分離
 * - kintone X-Cybozu-Authorization
 * - SMTPはsocket通信
 * - 外部サービス秘密情報は Sodium secretbox
 * - 暗号文: ENC:v1:<nonce>:<ciphertext>
 * - 鍵: gojacic/.secrets/アンケートアプリ.key
 */

date_default_timezone_set('Asia/Tokyo');

const APP_NAME  = 'アンケートアプリ';
const APP_TITLE = 'アンケート管理';

const MAX_TITLE       = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION    = 1000;
const MAX_OPTION      = 500;

const KINTONE_TIMEOUT = 30;
const SMTP_TIMEOUT    = 30;

const SECRET_PREFIX = 'ENC:v1:';

/*
 * ============================================================
 * パス
 * ============================================================
 *
 * index.php の場所に依存せず、
 * プロジェクトの gojacic を基準にする。
 *
 * draft:
 *   gojacic/.poc/draft/アンケートアプリ/index.php
 *
 * published:
 *   gojacic/published/アンケートアプリ/index.php
 *
 * 共通秘密鍵:
 *   gojacic/.secrets/アンケートアプリ.key
 */

function project_base_dir(): string
{
    $dir = __DIR__;

    /*
     * index.php が
     *
     * gojacic/.poc/draft/アンケートアプリ
     *
     * または
     *
     * gojacic/published/アンケートアプリ
     *
     * にあることを前提として、
     * gojacic まで4階層上がる。
     */
    $base = realpath(
        $dir
        . DIRECTORY_SEPARATOR . '..'
        . DIRECTORY_SEPARATOR . '..'
        . DIRECTORY_SEPARATOR . '..'
        . DIRECTORY_SEPARATOR . '..'
    );

    if ($base === false) {
        throw new RuntimeException(
            'プロジェクトルートを特定できません。'
        );
    }

    return $base;
}

function secret_file(): string
{
    return project_base_dir()
        . DIRECTORY_SEPARATOR . '.secrets'
        . DIRECTORY_SEPARATOR . APP_NAME . '.key';
}

function data_dir(): string
{
    /*
     * データはアプリ自身のディレクトリ直下。
     * Web公開領域にあるため、Apache側で
     * _dataへの直接アクセスを禁止する構成が望ましい。
     */
    return __DIR__ . DIRECTORY_SEPARATOR . '_data';
}

function data_file(): string
{
    return data_dir()
        . DIRECTORY_SEPARATOR . 'data.json';
}

function settings_file(): string
{
    return data_dir()
        . DIRECTORY_SEPARATOR . 'settings.json';
}

/*
 * ============================================================
 * 共通
 * ============================================================
 */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function uid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function get_string(
    string $key,
    string $default = ''
): string {
    $value = $_GET[$key] ?? $default;

    return is_scalar($value)
        ? trim((string)$value)
        : $default;
}

function post_string(
    string $key,
    string $default = ''
): string {
    $value = $_POST[$key] ?? $default;

    return is_scalar($value)
        ? trim((string)$value)
        : $default;
}

function post_bool(string $key): bool
{
    return in_array(
        strtolower(post_string($key)),
        ['1', 'true', 'yes', 'on'],
        true
    );
}

function app_url(array $params = []): string
{
    $script = (string)(
        $_SERVER['SCRIPT_NAME'] ?? 'index.php'
    );

    if ($params === []) {
        return $script;
    }

    return $script . '?' . http_build_query(
        $params,
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}

/*
 * 業務処理確定後だけ使用する。
 */
function redirect_screen(
    string $screen,
    array $extra = []
): never {
    $params = array_merge(
        ['screen' => $screen],
        $extra
    );

    header(
        'Location: ' . app_url($params),
        true,
        303
    );

    exit;
}

/*
 * ============================================================
 * セッション
 * ============================================================
 */

function cookie_path(): string
{
    $script = str_replace(
        '\\',
        '/',
        (string)(
            $_SERVER['SCRIPT_NAME'] ?? '/index.php'
        )
    );

    $dir = dirname($script);

    if (
        $dir === '.'
        || $dir === '/'
        || $dir === '\\'
    ) {
        return '/';
    }

    return rtrim($dir, '/') . '/';
}

function start_session(): void
{
    if (
        session_status()
        === PHP_SESSION_ACTIVE
    ) {
        return;
    }

    $secure =
        (
            !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off'
        )
        || (int)(
            $_SERVER['SERVER_PORT'] ?? 80
        ) === 443;

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

function flash(
    string $type,
    string $message
): void {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function flash_get(): ?array
{
    $v = $_SESSION['flash'] ?? null;

    unset($_SESSION['flash']);

    return is_array($v) ? $v : null;
}

/*
 * ============================================================
 * JSON永続化
 * ============================================================
 */

function ensure_data_dir(): void
{
    if (is_dir(data_dir())) {
        return;
    }

    if (
        !@mkdir(
            data_dir(),
            0770,
            true
        )
        && !is_dir(data_dir())
    ) {
        throw new RuntimeException(
            'データ保存領域を作成できません。'
        );
    }
}

function load_json(
    string $file,
    array $fallback
): array {
    if (!is_file($file)) {
        return $fallback;
    }

    $fp = @fopen($file, 'rb');

    if ($fp === false) {
        throw new RuntimeException(
            'データファイルを読み込めません。'
        );
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            throw new RuntimeException(
                'データファイルをロックできません。'
            );
        }

        $raw = stream_get_contents($fp);

        flock($fp, LOCK_UN);
        fclose($fp);

        if ($raw === false) {
            throw new RuntimeException(
                'データを読み込めません。'
            );
        }

        if (trim($raw) === '') {
            return $fallback;
        }

        $decoded = json_decode(
            $raw,
            true
        );

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'JSONデータが不正です。'
            );
        }

        return $decoded;
    } catch (Throwable $e) {
        @fclose($fp);
        throw $e;
    }
}

function save_json(
    string $file,
    array $data
): void {
    ensure_data_dir();

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException(
            'JSONデータを生成できません。'
        );
    }

    $tmp =
        $file
        . '.tmp.'
        . bin2hex(random_bytes(8));

    $fp = @fopen($tmp, 'wb');

    if ($fp === false) {
        throw new RuntimeException(
            '一時ファイルを作成できません。'
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException(
                'データファイルをロックできません。'
            );
        }

        $length = strlen($json);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite(
                $fp,
                substr($json, $offset)
            );

            if (
                $written === false
                || $written === 0
            ) {
                throw new RuntimeException(
                    'データを書き込めません。'
                );
            }

            $offset += $written;
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            throw new RuntimeException(
                'データファイルを更新できません。'
            );
        }
    } catch (Throwable $e) {
        @fclose($fp);
        @unlink($tmp);
        throw $e;
    }
}

/*
 * ============================================================
 * 初期データ
 * ============================================================
 */

function default_data(): array
{
    $t = now();

    return [
        'surveys' => [[
            'id' => 'survey-001',
            'title' => '顧客満足度アンケート',
            'description' =>
                'サービスについてのご意見をお聞かせください。',
            'startAt' =>
                date('Y-m-d\TH:i'),
            'endAt' =>
                date(
                    'Y-m-d\TH:i',
                    strtotime('+30 days')
                ),
            'status' => 'draft',
            'numbering' => 'global',
            'createdAt' => $t,
            'updatedAt' => $t,
            'groups' => [[
                'id' => 'group-001',
                'title' => '基本アンケート',
                'questions' => [[
                    'id' => 'question-001',
                    'number' => 'Q1',
                    'text' =>
                        'サービスの満足度を教えてください。',
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
                    'text' =>
                        'ご意見・ご要望があれば入力してください。',
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
            'last_error' => '',
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
            'last_error' => '',
        ],
    ];
}

function load_data(): array
{
    ensure_data_dir();

    $data = load_json(
        data_file(),
        default_data()
    );

    foreach (
        [
            'surveys',
            'answers',
            'customers',
            'send_history',
        ] as $key
    ) {
        if (
            !isset($data[$key])
            || !is_array($data[$key])
        ) {
            $data[$key] = [];
        }
    }

    return $data;
}

function save_data(array $data): void
{
    save_json(
        data_file(),
        $data
    );
}

function load_settings(): array
{
    ensure_data_dir();

    $default = default_settings();

    $settings = load_json(
        settings_file(),
        $default
    );

    foreach (
        ['kintone', 'mail'] as $service
    ) {
        $settings[$service] =
            array_replace_recursive(
                $default[$service],
                is_array(
                    $settings[$service] ?? null
                )
                    ? $settings[$service]
                    : []
            );
    }

    return $settings;
}

/*
 * ============================================================
 * 秘密鍵
 * ============================================================
 *
 * 正本:
 *
 * gojacic/.secrets/アンケートアプリ.key
 *
 * 環境変数を正本としない。
 * 自動生成もしない。
 * index.phpへ鍵を書かない。
 */

function encryption_key(): string
{
    if (
        !extension_loaded('sodium')
    ) {
        throw new RuntimeException(
            'PHP Sodium拡張が利用できません。'
        );
    }

    $file = secret_file();

    if (!is_file($file)) {
        throw new RuntimeException(
            '秘密鍵ファイルが存在しません。'
            . '「.secrets/アンケートアプリ.key」を'
            . '正しい環境へ配置してください。'
        );
    }

    if (!is_readable($file)) {
        throw new RuntimeException(
            '秘密鍵ファイルを読み取れません。'
        );
    }

    $raw = file_get_contents($file);

    if ($raw === false) {
        throw new RuntimeException(
            '秘密鍵ファイルを読み取れません。'
        );
    }

    $raw = trim($raw);

    /*
     * Base64形式を正本として扱う。
     */
    $decoded = base64_decode(
        $raw,
        true
    );

    if (
        $decoded === false
        || strlen($decoded)
            !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES
    ) {
        throw new RuntimeException(
            '秘密鍵ファイルの形式または長さが不正です。'
        );
    }

    return $decoded;
}

function is_encrypted_secret(
    string $value
): bool {
    if (
        !str_starts_with(
            $value,
            SECRET_PREFIX
        )
    ) {
        return false;
    }

    $parts = explode(':', $value);

    return count($parts) === 4
        && $parts[0] === 'ENC'
        && $parts[1] === 'v1'
        && $parts[2] !== ''
        && $parts[3] !== '';
}

function encrypt_secret(
    string $plain
): string {
    if ($plain === '') {
        return '';
    }

    $key = encryption_key();

    $nonce = random_bytes(
        SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
    );

    $ciphertext =
        sodium_crypto_secretbox(
            $plain,
            $nonce,
            $key
        );

    return SECRET_PREFIX
        . base64_encode($nonce)
        . ':'
        . base64_encode($ciphertext);
}

function decrypt_secret(
    string $encrypted
): string {
    if ($encrypted === '') {
        return '';
    }

    if (!is_encrypted_secret($encrypted)) {
        throw new RuntimeException(
            '保存済み認証情報が現在の暗号化方式ではありません。'
        );
    }

    $parts = explode(
        ':',
        $encrypted
    );

    $nonce = base64_decode(
        $parts[2],
        true
    );

    $ciphertext = base64_decode(
        $parts[3],
        true
    );

    if (
        $nonce === false
        || $ciphertext === false
        || strlen($nonce)
            !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
    ) {
        throw new RuntimeException(
            '保存済み認証情報の形式が不正です。'
        );
    }

    $plain =
        sodium_crypto_secretbox_open(
            $ciphertext,
            $nonce,
            encryption_key()
        );

    if ($plain === false) {
        throw new RuntimeException(
            '保存済み認証情報を復号できません。'
            . '秘密鍵が旧環境と一致しているか確認してください。'
        );
    }

    return $plain;
}

function save_settings(
    array $settings
): void {
    foreach (
        ['kintone', 'mail'] as $service
    ) {
        $password =
            (string)(
                $settings[$service]['password']
                ?? ''
            );

        if (
            $password !== ''
            && !is_encrypted_secret($password)
        ) {
            $settings[$service]['password'] =
                encrypt_secret($password);
        }
    }

    save_json(
        settings_file(),
        $settings
    );
}

/*
 * ============================================================
 * アンケート
 * ============================================================
 */

function survey_get(
    array $surveys,
    string $id
): ?array {
    foreach ($surveys as $survey) {
        if (
            is_array($survey)
            && (string)($survey['id'] ?? '')
                === $id
        ) {
            return $survey;
        }
    }

    return null;
}

function survey_index(
    array $surveys,
    string $id
): int {
    foreach ($surveys as $i => $survey) {
        if (
            (string)($survey['id'] ?? '')
                === $id
        ) {
            return $i;
        }
    }

    return -1;
}

function renumber_questions(
    array &$survey
): void {
    $global = 0;

    foreach (
        $survey['groups'] as $gi => &$group
    ) {
        $local = 0;

        foreach (
            $group['questions'] as &$question
        ) {
            $global++;
            $local++;

            if (
                ($survey['numbering'] ?? 'global')
                === 'group'
            ) {
                $question['number'] =
                    'Q'
                    . ($gi + 1)
                    . '-'
                    . $local;
            } else {
                $question['number'] =
                    'Q' . $global;
            }
        }

        unset($question);
    }

    unset($group);
}

function refresh_status(
    array &$data
): void {
    $changed = false;
    $now = time();

    foreach (
        $data['surveys'] as &$survey
    ) {
        if (
            ($survey['status'] ?? '')
                !== 'published'
        ) {
            continue;
        }

        $end = strtotime(
            (string)(
                $survey['endAt'] ?? ''
            )
        );

        if (
            $end !== false
            && $end < $now
        ) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = now();
            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {
        save_data($data);
    }
}

function status_label(
    string $status
): string {
    return match ($status) {
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き',
    };
}

function status_class(
    string $status
): string {
    return match ($status) {
        'published' => 'success',
        'stopped' => 'warning',
        'ended' => 'gray',
        default => 'gray',
    };
}

/*
 * ============================================================
 * kintone通信
 * ============================================================
 *
 * PHP cURLは使用しない。
 * allow_url_fopenに依存しないよう、
 * stream_socket_clientを使用する。
 */

function normalize_kintone_subdomain(
    string $value
): string {
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
            $value,
            '.cybozu.com'
        )
    ) {
        $value = substr(
            $value,
            0,
            -strlen('.cybozu.com')
        );
    }

    if (
        !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
            $value
        )
    ) {
        throw new RuntimeException(
            'kintoneサブドメインの形式が不正です。'
        );
    }

    return $value;
}

function parse_proxy(
    string $proxy
): ?array {
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (
        !preg_match(
            '/^([^:]+):([0-9]{1,5})$/',
            $proxy,
            $m
        )
    ) {
        throw new RuntimeException(
            'Proxyはhost:port形式で入力してください。'
        );
    }

    $port = (int)$m[2];

    if (
        $port < 1
        || $port > 65535
    ) {
        throw new RuntimeException(
            'Proxyポート番号が不正です。'
        );
    }

    return [
        'host' => $m[1],
        'port' => $port,
    ];
}

function http_request(
    string $url,
    string $method,
    array $headers,
    ?string $body,
    int $timeout,
    bool $verifySsl,
    ?array $proxy = null
): array {
    $parts = parse_url($url);

    if (
        !is_array($parts)
        || ($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host'])
    ) {
        throw new RuntimeException(
            '外部通信先URLが不正です。'
        );
    }

    $host = (string)$parts['host'];
    $port = (int)(
        $parts['port'] ?? 443
    );

    $targetHost = $host;
    $targetPort = $port;

    if ($proxy !== null) {
        $targetHost = $proxy['host'];
        $targetPort = $proxy['port'];
    }

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => $verifySsl,
            'verify_peer_name' => $verifySsl,
            'allow_self_signed' => !$verifySsl,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ],
    ]);

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        'tcp://' . $targetHost . ':' . $targetPort,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ($fp === false) {
        throw new RuntimeException(
            '外部サービスへ接続できません。'
        );
    }

    stream_set_timeout(
        $fp,
        $timeout
    );

    $path =
        ($parts['path'] ?? '/')
        . (
            isset($parts['query'])
            ? '?' . $parts['query']
            : ''
        );

    if ($proxy !== null) {
        /*
         * HTTPS CONNECT。
         */
        $connect =
            "CONNECT "
            . $host
            . ":"
            . $port
            . " HTTP/1.1\r\n"
            . "Host: "
            . $host
            . ":"
            . $port
            . "\r\n"
            . "Connection: close\r\n\r\n";

        fwrite($fp, $connect);

        $response = '';

        while (
            !feof($fp)
            && !str_contains(
                $response,
                "\r\n\r\n"
            )
        ) {
            $line = fgets($fp);

            if ($line === false) {
                break;
            }

            $response .= $line;
        }

        if (
            !preg_match(
                '#^HTTP/\d(?:\.\d)?\s+2\d\d#',
                $response
            )
        ) {
            fclose($fp);

            throw new RuntimeException(
                'Proxy CONNECTに失敗しました。'
            );
        }

        /*
         * TLS開始。
         */
        if (
            !stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )
        ) {
            fclose($fp);

            throw new RuntimeException(
                'Proxy経由のTLS接続に失敗しました。'
            );
        }
    } else {
        if (
            !stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )
        ) {
            fclose($fp);

            throw new RuntimeException(
                'TLS接続に失敗しました。'
            );
        }
    }

    $request =
        strtoupper($method)
        . ' '
        . $path
        . " HTTP/1.1\r\n"
        . "Host: "
        . $host
        . "\r\n"
        . "Connection: close\r\n";

    foreach ($headers as $header) {
        $request .= $header . "\r\n";
    }

    if ($body !== null) {
        $request .=
            'Content-Length: '
            . strlen($body)
            . "\r\n";
    }

    $request .= "\r\n";

    if ($body !== null) {
        $request .= $body;
    }

    $written = fwrite(
        $fp,
        $request
    );

    if (
        $written === false
    ) {
        fclose($fp);

        throw new RuntimeException(
            '外部サービスへリクエストを送信できません。'
        );
    }

    $raw = '';

    while (!feof($fp)) {
        $chunk = fread(
            $fp,
            8192
        );

        if ($chunk === false) {
            fclose($fp);

            throw new RuntimeException(
                '外部サービスのレスポンスを取得できません。'
            );
        }

        if ($chunk !== '') {
            $raw .= $chunk;
        }
    }

    fclose($fp);

    $pos = strpos(
        $raw,
        "\r\n\r\n"
    );

    if ($pos === false) {
        throw new RuntimeException(
            '外部サービスのHTTPレスポンスを解析できません。'
        );
    }

    $headerText =
        substr($raw, 0, $pos);

    $responseBody =
        substr($raw, $pos + 4);

    $lines = preg_split(
        "/\r\n/",
        $headerText
    );

    $status = 0;

    if (
        isset($lines[0])
        && preg_match(
            '#^HTTP/\S+\s+(\d{3})#',
            $lines[0],
            $m
        )
    ) {
        $status = (int)$m[1];
    }

    $responseHeaders = [];

    foreach (
        array_slice($lines ?: [], 1)
        as $line
    ) {
        $p = strpos($line, ':');

        if ($p === false) {
            continue;
        }

        $name =
            strtolower(
                trim(substr($line, 0, $p))
            );

        $value =
            trim(
                substr($line, $p + 1)
            );

        $responseHeaders[$name] = $value;
    }

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => $responseBody,
    ];
}

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $subdomain =
        normalize_kintone_subdomain(
            (string)(
                $config['subdomain'] ?? ''
            )
        );

    $appId =
        (string)(
            $config['app_id'] ?? ''
        );

    if (
        !ctype_digit($appId)
        || (int)$appId <= 0
    ) {
        throw new RuntimeException(
            'kintoneアプリIDが不正です。'
        );
    }

    $username =
        (string)(
            $config['username'] ?? ''
        );

    $password =
        (string)(
            $config['password'] ?? ''
        );

    if (
        $username === ''
        || $password === ''
    ) {
        throw new RuntimeException(
            'kintone認証情報が設定されていません。'
        );
    }

    $authorization =
        base64_encode(
            $username
            . ':'
            . $password
        );

    $json = null;

    if ($body !== null) {
        $json = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException(
                'kintoneリクエストを生成できません。'
            );
        }
    }

    $headers = [
        'X-Cybozu-Authorization: '
            . $authorization,
        'Accept: application/json',
        'User-Agent: SurveyApp/1.0',
    ];

    if ($json !== null) {
        $headers[] =
            'Content-Type: application/json';
    }

    $proxy = parse_proxy(
        (string)(
            $config['proxy'] ?? ''
        )
    );

    return http_request(
        'https://'
        . $subdomain
        . '.cybozu.com'
        . $path,
        $method,
        $headers,
        $json,
        KINTONE_TIMEOUT,
        !empty($config['verify_ssl']),
        $proxy
    );
}

function assert_kintone_success(
    array $response
): void {
    $status =
        (int)($response['status'] ?? 0);

    if (
        $status >= 200
        && $status < 300
    ) {
        return;
    }

    if (
        $status === 302
        || $status === 303
    ) {
        throw new RuntimeException(
            'kintone APIからリダイレクト応答'
            . '（'
            . $status
            . '）が返されました。'
        );
    }

    $message = 'kintone APIエラー';

    $body =
        (string)(
            $response['body'] ?? ''
        );

    $decoded = json_decode(
        $body,
        true
    );

    if (is_array($decoded)) {
        $code =
            (string)(
                $decoded['code'] ?? ''
            );

        $apiMessage =
            (string)(
                $decoded['message'] ?? ''
            );

        if ($code !== '') {
            $message .=
                ' [' . $code . ']';
        }

        if ($apiMessage !== '') {
            $message .=
                ' ' . $apiMessage;
        }
    }

    $message .=
        ' HTTP ' . $status;

    throw new RuntimeException(
        $message
    );
}

/*
 * ============================================================
 * SMTP
 * ============================================================
 */

function smtp_read(
    $fp
): string {
    $response = '';

    while (!feof($fp)) {
        $line = fgets($fp);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (
            preg_match(
                '/^\d{3} /',
                $line
            )
        ) {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException(
            'SMTPレスポンスを取得できません。'
        );
    }

    return $response;
}

function smtp_expect(
    $fp,
    array $codes
): string {
    $response = smtp_read($fp);

    $code = (int)substr(
        trim($response),
        0,
        3
    );

    if (!in_array(
        $code,
        $codes,
        true
    )) {
        throw new RuntimeException(
            'SMTPエラーが返されました。'
            . ' HTTPではなくSMTP応答です。'
        );
    }

    return $response;
}

function smtp_command(
    $fp,
    string $command,
    array $codes
): string {
    fwrite(
        $fp,
        $command . "\r\n"
    );

    return smtp_expect(
        $fp,
        $codes
    );
}

function smtp_connect(
    array $config
) {
    $host =
        (string)(
            $config['host'] ?? ''
        );

    $port =
        (int)(
            $config['port'] ?? 0
        );

    if (
        $host === ''
        || $port < 1
        || $port > 65535
    ) {
        throw new RuntimeException(
            'SMTP設定が不正です。'
        );
    }

    $encryption =
        strtolower(
            (string)(
                $config['encryption']
                ?? 'none'
            )
        );

    $transport = 'tcp';

    if (
        $encryption === 'ssl'
    ) {
        $transport = 'tls';
    }

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $transport
        . '://'
        . $host
        . ':'
        . $port,
        $errno,
        $errstr,
        SMTP_TIMEOUT
    );

    if ($fp === false) {
        throw new RuntimeException(
            'SMTPサーバへ接続できません。'
        );
    }

    stream_set_timeout(
        $fp,
        SMTP_TIMEOUT
    );

    smtp_expect(
        $fp,
        [220]
    );

    smtp_command(
        $fp,
        'EHLO localhost',
        [250]
    );

    if (
        $encryption === 'tls'
    ) {
        smtp_command(
            $fp,
            'STARTTLS',
            [220]
        );

        if (
            !stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )
        ) {
            fclose($fp);

            throw new RuntimeException(
                'SMTP TLS接続を開始できません。'
            );
        }

        smtp_command(
            $fp,
            'EHLO localhost',
            [250]
        );
    }

    if (
        !empty($config['auth'])
    ) {
        $username =
            (string)(
                $config['username']
                ?? ''
            );

        $password =
            (string)(
                $config['password']
                ?? ''
            );

        if (
            $username === ''
            || $password === ''
        ) {
            fclose($fp);

            throw new RuntimeException(
                'SMTP認証情報が設定されていません。'
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

function smtp_test(
    array $config
): void {
    $fp = smtp_connect($config);

    try {
        smtp_command(
            $fp,
            'QUIT',
            [221]
        );
    } finally {
        fclose($fp);
    }
}

function smtp_send(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    $fp = smtp_connect($config);

    try {
        $from =
            (string)(
                $config['from_email']
                ?? ''
            );

        if (
            !filter_var(
                $from,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new RuntimeException(
                '送信元メールアドレスが不正です。'
            );
        }

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

        $fromName =
            (string)(
                $config['from_name']
                ?? ''
            );

        $replyTo =
            (string)(
                $config['reply_to']
                ?? ''
            );

        $headers = [];

        $headers[] =
            'From: '
            . (
                $fromName !== ''
                ? $fromName . ' '
                : ''
            )
            . '<' . $from . '>';

        $headers[] =
            'To: <' . $to . '>';

        $headers[] =
            'Subject: '
            . '=?UTF-8?B?'
            . base64_encode($subject)
            . '?=';

        $headers[] =
            'MIME-Version: 1.0';

        $headers[] =
            'Content-Type: text/plain; charset=UTF-8';

        if (
            $replyTo !== ''
        ) {
            $headers[] =
                'Reply-To: ' . $replyTo;
        }

        $message =
            implode(
                "\r\n",
                $headers
            )
            . "\r\n\r\n"
            . str_replace(
                "\n.",
                "\n..",
                $body
            )
            . "\r\n.";

        fwrite(
            $fp,
            $message . "\r\n"
        );

        smtp_expect(
            $fp,
            [250]
        );

        smtp_command(
            $fp,
            'QUIT',
            [221]
        );
    } finally {
        fclose($fp);
    }
}

/*
 * ============================================================
 * POST処理
 * ============================================================
 */

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

    if ($action === '') {
        return null;
    }

    /*
     * -----------------------------
     * アンケート保存
     * -----------------------------
     */
    if ($action === 'save_survey') {
        $id =
            post_string('id');

        $title =
            post_string('title');

        $description =
            post_string('description');

        $startAt =
            post_string('startAt');

        $endAt =
            post_string('endAt');

        $numbering =
            post_string(
                'numbering',
                'global'
            );

        $errors = [];

        if (
            $title === ''
            || mb_strlen($title) > MAX_TITLE
        ) {
            $errors[] =
                'タイトルを入力してください。';
        }

        if (
            mb_strlen($description)
            > MAX_DESCRIPTION
        ) {
            $errors[] =
                '説明文が長すぎます。';
        }

        if (
            !in_array(
                $numbering,
                ['global', 'group'],
                true
            )
        ) {
            $errors[] =
                '採番方式が不正です。';
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
            && strtotime($startAt)
                >= strtotime($endAt)
        ) {
            $errors[] =
                '終了日時は開始日時より後にしてください。';
        }

        if ($errors !== []) {
            flash(
                'error',
                implode("\n", $errors)
            );

            return [
                'screen' => 'edit',
                'id' => $id,
            ];
        }

        $groups = [];

        $rawGroups =
            $_POST['groups'] ?? [];

        if (
            is_array($rawGroups)
        ) {
            foreach (
                $rawGroups as $rawGroup
            ) {
                if (
                    !is_array($rawGroup)
                ) {
                    continue;
                }

                $group = [
                    'id' =>
                        trim(
                            (string)(
                                $rawGroup['id']
                                ?? uid('group')
                            )
                        ),
                    'title' =>
                        trim(
                            (string)(
                                $rawGroup['title']
                                ?? ''
                            )
                        ),
                    'questions' => [],
                ];

                $rawQuestions =
                    $rawGroup['questions']
                    ?? [];

                if (
                    is_array(
                        $rawQuestions
                    )
                ) {
                    foreach (
                        $rawQuestions
                        as $rawQuestion
                    ) {
                        if (
                            !is_array(
                                $rawQuestion
                            )
                        ) {
                            continue;
                        }

                        $type =
                            (string)(
                                $rawQuestion['type']
                                ?? 'text'
                            );

                        if (
                            !in_array(
                                $type,
                                [
                                    'single',
                                    'multiple',
                                    'text',
                                ],
                                true
                            )
                        ) {
                            $type = 'text';
                        }

                        $q = [
                            'id' =>
                                trim(
                                    (string)(
                                        $rawQuestion['id']
                                        ?? uid('question')
                                    )
                                ),
                            'number' => '',
                            'text' =>
                                mb_substr(
                                    trim(
                                        (string)(
                                            $rawQuestion['text']
                                            ?? ''
                                        )
                                    ),
                                    0,
                                    MAX_QUESTION
                                ),
                            'type' => $type,
                            'required' =>
                                !empty(
                                    $rawQuestion['required']
                                ),
                            'options' => [],
                        ];

                        if (
                            in_array(
                                $type,
                                [
                                    'single',
                                    'multiple',
                                ],
                                true
                            )
                            && is_array(
                                $rawQuestion['options']
                                ?? null
                            )
                        ) {
                            foreach (
                                $rawQuestion['options']
                                as $rawOption
                            ) {
                                if (
                                    !is_array(
                                        $rawOption
                                    )
                                ) {
                                    continue;
                                }

                                $q['options'][] = [
                                    'id' =>
                                        trim(
                                            (string)(
                                                $rawOption['id']
                                                ?? uid('option')
                                            )
                                        ),
                                    'label' =>
                                        mb_substr(
                                            trim(
                                                (string)(
                                                    $rawOption['label']
                                                    ?? ''
                                                )
                                            ),
                                            0,
                                            MAX_OPTION
                                        ),
                                    'nextQuestionId' =>
                                        trim(
                                            (string)(
                                                $rawOption[
                                                    'nextQuestionId'
                                                ]
                                                ?? ''
                                            )
                                        ),
                                ];
                            }
                        }

                        $group['questions'][] = $q;
                    }
                }

                $groups[] = $group;
            }
        }

        if ($groups === []) {
            $groups[] = [
                'id' => uid('group'),
                'title' => 'グループ1',
                'questions' => [],
            ];
        }

        $survey = [
            'id' =>
                $id !== ''
                ? $id
                : uid('survey'),
            'title' => $title,
            'description' => $description,
            'startAt' => $startAt,
            'endAt' => $endAt,
            'status' => 'draft',
            'numbering' => $numbering,
            'createdAt' => now(),
            'updatedAt' => now(),
            'groups' => $groups,
        ];

        if ($id !== '') {
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
                    'screen' => 'list',
                ];
            }

            $old =
                $data['surveys'][$index];

            $survey['createdAt'] =
                $old['createdAt']
                ?? now();

            $survey['status'] =
                $old['status']
                ?? 'draft';

            if (
                $survey['status'] === 'ended'
            ) {
                flash(
                    'error',
                    '終了済みアンケートは編集できません。'
                );

                return [
                    'screen' => 'list',
                ];
            }

            $data['surveys'][$index] =
                $survey;
        } else {
            $survey['status'] = 'draft';
            $data['surveys'][] =
                $survey;
        }

        renumber_questions(
            $survey
        );

        /*
         * renumber後のsurveyを再設定。
         */
        $index =
            survey_index(
                $data['surveys'],
                $survey['id']
            );

        if ($index >= 0) {
            $data['surveys'][$index] =
                $survey;
        }

        save_data($data);

        flash(
            'success',
            'アンケートを保存しました。'
        );

        return [
            'screen' => 'list',
        ];
    }

    /*
     * -----------------------------
     * 状態変更
     * -----------------------------
     */
    if (
        in_array(
            $action,
            [
                'publish',
                'stop',
                'resume',
            ],
            true
        )
    ) {
        $id =
            post_string('id');

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
                'screen' => 'list',
            ];
        }

        $status =
            $data['surveys'][$index]['status']
            ?? 'draft';

        if ($status === 'ended') {
            flash(
                'error',
                '終了状態からは変更できません。'
            );

            return [
                'screen' => 'list',
            ];
        }

        $newStatus = match ($action) {
            'publish' => 'published',
            'stop' => 'stopped',
            'resume' => 'published',
        };

        $data['surveys'][$index]['status'] =
            $newStatus;

        $data['surveys'][$index]['updatedAt'] =
            now();

        save_data($data);

        flash(
            'success',
            '状態を変更しました。'
        );

        return [
            'screen' => 'list',
        ];
    }

    /*
     * -----------------------------
     * 削除
     * -----------------------------
     */
    if ($action === 'delete_survey') {
        $id =
            post_string('id');

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
                'screen' => 'list',
            ];
        }

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

        return [
            'screen' => 'list',
        ];
    }

    /*
     * -----------------------------
     * 複製
     * -----------------------------
     */
    if ($action === 'duplicate_survey') {
        $id =
            post_string('id');

        $survey =
            survey_get(
                $data['surveys'],
                $id
            );

        if ($survey === null) {
            flash(
                'error',
                '対象アンケートが見つかりません。'
            );

            return [
                'screen' => 'list',
            ];
        }

        $survey['id'] =
            uid('survey');

        $survey['title'] =
            (string)(
                $survey['title'] ?? ''
            ) . '（複製）';

        $survey['status'] =
            'draft';

        $survey['createdAt'] =
            now();

        $survey['updatedAt'] =
            now();

        foreach (
            $survey['groups'] as &$group
        ) {
            $group['id'] =
                uid('group');

            foreach (
                $group['questions']
                as &$question
            ) {
                $question['id'] =
                    uid('question');

                foreach (
                    $question['options']
                    as &$option
                ) {
                    $option['id'] =
                        uid('option');
                }
                unset($option);
            }

            unset($question);
        }

        unset($group);

        renumber_questions(
            $survey
        );

        $data['surveys'][] =
            $survey;

        save_data($data);

        flash(
            'success',
            'アンケートを複製しました。'
        );

        return [
            'screen' => 'list',
        ];
    }

    /*
     * -----------------------------
     * kintone設定保存
     * -----------------------------
     */
    if ($action === 'save_kintone') {
        $old =
            $settings['kintone'];

        $password =
            post_string('password');

        if ($password === '') {
            $password =
                (string)(
                    $old['password']
                    ?? ''
                );
        }

        if (
            $password !== ''
            && !is_encrypted_secret(
                $password
            )
        ) {
            $password =
                encrypt_secret($password);
        }

        $config = [
            'subdomain' =>
                post_string('subdomain'),
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
                $old['mapping'] ?? [],
            'fields' =>
                $old['fields'] ?? [],
            'last_test' =>
                $old['last_test'] ?? null,
            'last_sync' =>
                $old['last_sync'] ?? null,
            'last_error' => '',
        ];

        normalize_kintone_subdomain(
            $config['subdomain']
        );

        if (
            !ctype_digit(
                $config['app_id']
            )
            || (int)$config['app_id'] <= 0
        ) {
            throw new RuntimeException(
                'kintoneアプリIDが不正です。'
            );
        }

        if (
            $config['username'] === ''
            || $config['password'] === ''
        ) {
            throw new RuntimeException(
                'kintoneログイン情報を入力してください。'
            );
        }

        parse_proxy(
            $config['proxy']
        );

        $settings['kintone'] =
            $config;

        save_settings(
            $settings
        );

        flash(
            'success',
            'kintone設定を保存しました。'
        );

        return [
            'screen' => 'kintone',
        ];
    }

    /*
     * -----------------------------
     * kintone接続テスト
     * -----------------------------
     */
    if ($action === 'test_kintone') {
        $config =
            $settings['kintone'];

        if (
            !empty(
                $config['password']
            )
            && is_encrypted_secret(
                (string)$config['password']
            )
        ) {
            $config['password'] =
                decrypt_secret(
                    $config['password']
                );
        }

        $response =
            kintone_request(
                $config,
                'GET',
                '/k/v1/app.json?id='
                . rawurlencode(
                    (string)$config['app_id']
                )
            );

        assert_kintone_success(
            $response
        );

        $settings['kintone']['last_test'] =
            now();

        $settings['kintone']['last_error'] =
            '';

        save_settings(
            $settings
        );

        flash(
            'success',
            'kintoneへの接続に成功しました。'
        );

        return [
            'screen' => 'kintone',
        ];
    }

    /*
     * -----------------------------
     * kintone項目取得
     * -----------------------------
     */
    if ($action === 'fetch_kintone_fields') {
        $config =
            $settings['kintone'];

        $config['password'] =
            decrypt_secret(
                (string)$config['password']
            );

        $response =
            kintone_request(
                $config,
                'GET',
                '/k/v1/app/form/fields.json?app='
                . rawurlencode(
                    (string)$config['app_id']
                )
            );

        assert_kintone_success(
            $response
        );

        $decoded =
            json_decode(
                (string)$response['body'],
                true
            );

        if (
            !is_array($decoded)
        ) {
            throw new RuntimeException(
                'kintone項目一覧を解析できません。'
            );
        }

        $settings['kintone']['fields'] =
            $decoded['properties']
            ?? [];

        save_settings(
            $settings
        );

        flash(
            'success',
            'kintoneの項目一覧を取得しました。'
        );

        return [
            'screen' => 'kintone',
        ];
    }

    /*
     * -----------------------------
     * SMTP設定保存
     * -----------------------------
     */
    if ($action === 'save_mail') {
        $old =
            $settings['mail'];

        $password =
            post_string('password');

        if ($password === '') {
            $password =
                (string)(
                    $old['password']
                    ?? ''
                );
        }

        if (
            $password !== ''
            && !is_encrypted_secret(
                $password
            )
        ) {
            $password =
                encrypt_secret($password);
        }

        $config = [
            'host' =>
                post_string('host'),
            'port' =>
                (int)post_string('port'),
            'encryption' =>
                post_string(
                    'encryption',
                    'tls'
                ),
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
                $old['last_test'] ?? null,
            'last_error' => '',
        ];

        if (
            $config['host'] === ''
            || $config['port'] < 1
            || $config['port'] > 65535
        ) {
            throw new RuntimeException(
                'SMTPサーバまたはポートが不正です。'
            );
        }

        if (
            !in_array(
                $config['encryption'],
                ['ssl', 'tls', 'none'],
                true
            )
        ) {
            throw new RuntimeException(
                'SMTP暗号化方式が不正です。'
            );
        }

        if (
            !filter_var(
                $config['from_email'],
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new RuntimeException(
                '送信元メールアドレスが不正です。'
            );
        }

        $settings['mail'] =
            $config;

        save_settings(
            $settings
        );

        flash(
            'success',
            'メール設定を保存しました。'
        );

        return [
            'screen' => 'mail',
        ];
    }

    /*
     * -----------------------------
     * SMTP接続テスト
     * -----------------------------
     */
    if ($action === 'test_mail') {
        $config =
            $settings['mail'];

        $config['password'] =
            $config['password'] !== ''
            ? decrypt_secret(
                $config['password']
            )
            : '';

        smtp_test(
            $config
        );

        $settings['mail']['last_test'] =
            now();

        $settings['mail']['last_error'] =
            '';

        save_settings(
            $settings
        );

        flash(
            'success',
            'SMTP接続・認証に成功しました。'
        );

        return [
            'screen' => 'mail',
        ];
    }

    /*
     * -----------------------------
     * 回答途中保存
     * -----------------------------
     */
    if ($action === 'answer_save') {
        $id =
            post_string('id');

        $survey =
            survey_get(
                $data['surveys'],
                $id
            );

        if ($survey === null) {
            throw new RuntimeException(
                '対象アンケートが見つかりません。'
            );
        }

        $_SESSION[
            'answer_' . $id
        ] =
            is_array(
                $_POST['answers']
                ?? null
            )
                ? $_POST['answers']
                : [];

        return [
            'screen' => 'confirm',
            'id' => $id,
        ];
    }

    /*
     * -----------------------------
     * 回答送信
     * -----------------------------
     */
    if ($action === 'submit_answer') {
        $id =
            post_string('id');

        $survey =
            survey_get(
                $data['surveys'],
                $id
            );

        if ($survey === null) {
            throw new RuntimeException(
                '対象アンケートが見つかりません。'
            );
        }

        if (
            ($survey['status'] ?? '')
            !== 'published'
        ) {
            throw new RuntimeException(
                '現在回答を受け付けていません。'
            );
        }

        $answers =
            $_SESSION[
                'answer_' . $id
            ] ?? [];

        if (
            !is_array($answers)
        ) {
            $answers = [];
        }

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
                    $answers[
                        $question['id']
                    ] ?? '';

                $empty =
                    is_array($value)
                    ? count($value) === 0
                    : trim(
                        (string)$value
                    ) === '';

                if ($empty) {
                    throw new RuntimeException(
                        '必須項目が未回答です。'
                    );
                }
            }
        }

        $data['answers'][] = [
            'id' => uid('answer'),
            'surveyId' => $id,
            'createdAt' => now(),
            'answers' => $answers,
        ];

        save_data($data);

        unset(
            $_SESSION[
                'answer_' . $id
            ]
        );

        return [
            'screen' => 'complete',
            'id' => $id,
        ];
    }

    return null;
}

/*
 * ============================================================
 * HTML共通
 * ============================================================
 */

function page_start(
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
<title><?= h($title) ?></title>
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
a{color:var(--primary);text-decoration:none}
a:hover{text-decoration:underline}
.header{
 background:#0f172a;
 color:#fff;
 padding:16px 24px;
}
.header-inner{
 max-width:1400px;
 margin:auto;
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:16px;
}
.brand{font-weight:700;font-size:20px}
.nav{
 display:flex;
 gap:8px;
 flex-wrap:wrap;
}
.nav a{
 color:#cbd5e1;
 padding:8px 12px;
 border-radius:7px;
}
.nav a:hover{
 background:#1e293b;
 text-decoration:none;
 color:#fff;
}
.container{
 max-width:1400px;
 margin:0 auto;
 padding:24px;
}
h1{font-size:26px;margin:0 0 20px}
h2{font-size:20px;margin:0 0 16px}
h3{font-size:17px;margin:0 0 12px}
.card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:12px;
 box-shadow:var(--shadow);
 padding:20px;
 margin-bottom:18px;
}
.grid{
 display:grid;
 grid-template-columns:repeat(4,minmax(0,1fr));
 gap:14px;
}
.form-grid{
 display:grid;
 grid-template-columns:repeat(2,minmax(0,1fr));
 gap:16px;
}
.field{margin-bottom:14px}
.field label{
 display:block;
 font-weight:600;
 margin-bottom:6px;
}
input,textarea,select{
 width:100%;
 border:1px solid #cbd5e1;
 border-radius:8px;
 padding:10px 12px;
 background:#fff;
 color:var(--text);
 font:inherit;
}
textarea{min-height:120px;resize:vertical}
button,.btn{
 display:inline-flex;
 align-items:center;
 justify-content:center;
 gap:6px;
 border:0;
 border-radius:8px;
 padding:10px 14px;
 font:inherit;
 cursor:pointer;
 text-decoration:none;
}
button:hover,.btn:hover{text-decoration:none}
.btn-primary{
 background:var(--primary);
 color:#fff;
}
.btn-primary:hover{background:var(--primary-dark)}
.btn-success{background:var(--success);color:#fff}
.btn-warning{background:var(--warning);color:#fff}
.btn-danger{background:var(--danger);color:#fff}
.btn-gray{background:#e2e8f0;color:#334155}
.actions{
 display:flex;
 flex-wrap:wrap;
 gap:8px;
 align-items:center;
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
 padding:12px 10px;
 border-bottom:1px solid var(--border);
 text-align:left;
 vertical-align:top;
}
th{
 background:#f8fafc;
 font-weight:700;
 white-space:nowrap;
}
.badge{
 display:inline-block;
 padding:4px 8px;
 border-radius:999px;
 font-size:12px;
 font-weight:700;
}
.badge.success{background:#dcfce7;color:#166534}
.badge.warning{background:#fef3c7;color:#92400e}
.badge.gray{background:#e2e8f0;color:#475569}
.flash{
 padding:12px 14px;
 border-radius:8px;
 margin-bottom:16px;
 white-space:pre-wrap;
}
.flash.success{
 background:#dcfce7;
 color:#166534;
 border:1px solid #bbf7d0;
}
.flash.error{
 background:#fee2e2;
 color:#991b1b;
 border:1px solid #fecaca;
}
.metric{
 padding:18px;
 border:1px solid var(--border);
 border-radius:10px;
 background:#fff;
}
.metric .label{
 color:var(--gray);
 font-size:13px;
}
.metric .value{
 font-size:27px;
 font-weight:700;
 margin-top:5px;
}
.question{
 border:1px solid var(--border);
 border-radius:10px;
 padding:16px;
 margin:12px 0;
 background:#fff;
}
.option{
 display:flex;
 gap:8px;
 align-items:center;
 margin:8px 0;
}
.group{
 border:2px solid #e2e8f0;
 border-radius:12px;
 padding:16px;
 margin-bottom:16px;
 background:#f8fafc;
}
.answer-wrap{
 max-width:820px;
 margin:30px auto;
}
.answer-wrap .card{padding:24px}
.mobile-choice{
 display:block;
 padding:13px;
 border:1px solid var(--border);
 border-radius:9px;
 margin:8px 0;
}
.muted{color:var(--gray)}
.small{font-size:13px}
.center{text-align:center}
.error-box{
 max-width:760px;
 margin:50px auto;
}
.drag-handle{
 cursor:grab;
 color:var(--gray);
 font-size:20px;
}
@media(max-width:900px){
 .grid{grid-template-columns:repeat(2,minmax(0,1fr))}
 .form-grid{grid-template-columns:1fr}
 .header-inner{
  align-items:flex-start;
  flex-direction:column;
 }
}
@media(max-width:600px){
 .container{padding:14px}
 .grid{grid-template-columns:1fr}
 h1{font-size:22px}
 .header{padding:14px}
 .answer-wrap{margin:10px auto}
 .answer-wrap .card{padding:16px}
}
</style>
</head>
<body>
<?php if ($admin): ?>
<header class="header">
 <div class="header-inner">
  <div class="brand"><?= h(APP_TITLE) ?></div>
  <nav class="nav">
   <a href="<?= h(app_url(['screen'=>'list'])) ?>">アンケート一覧</a>
   <a href="<?= h(app_url(['screen'=>'kintone'])) ?>">kintone連携</a>
   <a href="<?= h(app_url(['screen'=>'mail'])) ?>">メール設定</a>
  </nav>
 </div>
</header>
<?php endif; ?>
<main class="container">
<?php
}

function page_end(): void
{
    ?>
</main>
</body>
</html>
<?php
}

function render_flash(): void
{
    $flash = flash_get();

    if ($flash === null) {
        return;
    }

    ?>
<div class="flash <?= h(
    ($flash['type'] ?? 'error') === 'success'
        ? 'success'
        : 'error'
) ?>"><?= h(
    (string)($flash['message'] ?? '')
) ?></div>
<?php
}

/*
 * ============================================================
 * 一覧
 * ============================================================
 */

function render_list(
    array $data
): void {
    page_start('アンケート一覧');
    render_flash();

    $q =
        get_string('q');

    $status =
        get_string('status', 'all');

    $sort =
        get_string('sort', 'updated_desc');

    $surveys =
        $data['surveys'];

    $filtered = [];

    foreach (
        $surveys as $survey
    ) {
        $title =
            (string)(
                $survey['title'] ?? ''
            );

        if (
            $q !== ''
            && mb_stripos(
                $title,
                $q
            ) === false
        ) {
            continue;
        }

        if (
            $status !== 'all'
            && ($survey['status'] ?? 'draft')
                !== $status
        ) {
            continue;
        }

        $filtered[] = $survey;
    }

    usort(
        $filtered,
        static function(
            array $a,
            array $b
        ) use ($sort): int {
            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)$a['updatedAt'],
                        (string)$b['updatedAt']
                    ),
                'answers_desc' =>
                    count_survey_answers(
                        $a,
                        []
                    )
                    <=> count_survey_answers(
                        $b,
                        []
                    ),
                default =>
                    strcmp(
                        (string)$b['updatedAt'],
                        (string)$a['updatedAt']
                    ),
            };
        }
    );

    ?>
<div class="actions"
     style="justify-content:space-between;margin-bottom:18px">
 <h1>アンケート一覧</h1>
 <a class="btn btn-primary"
    href="<?= h(app_url(['screen'=>'edit'])) ?>">
  ＋ 新規作成
 </a>
</div>

<form method="get" class="card">
 <input type="hidden" name="screen" value="list">
 <div class="form-grid">
  <div class="field">
   <label>タイトル検索</label>
   <input name="q"
          value="<?= h($q) ?>"
          placeholder="タイトルを検索">
  </div>
  <div class="field">
   <label>ステータス</label>
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
  </div>
 </div>
 <div class="actions">
  <button class="btn btn-primary"
          type="submit">
   検索
  </button>
  <select name="sort"
          style="max-width:220px">
   <option value="updated_desc">更新日：新しい順</option>
   <option value="updated_asc">更新日：古い順</option>
   <option value="answers_desc">回答数：多い順</option>
  </select>
 </div>
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
<?php foreach ($filtered as $survey): ?>
<?php
$count = count_survey_answers(
    $survey,
    $data['answers']
);
?>
    <tr>
     <td>
      <strong><?= h($survey['title']) ?></strong>
     </td>
     <td><?= h($survey['createdAt']) ?></td>
     <td><?= h($survey['updatedAt']) ?></td>
     <td>
      <?= h($survey['startAt']) ?>
      ～
      <?= h($survey['endAt']) ?>
     </td>
     <td>
      <span class="badge <?= h(
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
     <td><?= h($count) ?></td>
     <td>
      <div class="actions">
       <a class="btn btn-gray"
          href="<?= h(app_url([
              'screen'=>'edit',
              'id'=>$survey['id']
          ])) ?>">
        確認・編集
       </a>
       <a class="btn btn-gray"
          href="<?= h(app_url([
              'screen'=>'analytics',
              'id'=>$survey['id']
          ])) ?>">
        集計
       </a>
       <a class="btn btn-gray"
          href="<?= h(app_url([
              'screen'=>'send',
              'id'=>$survey['id']
          ])) ?>">
        送信
       </a>
       <form method="post"
             style="display:inline"
             onsubmit="return confirm('このアンケートを複製しますか？')">
        <input type="hidden"
               name="action"
               value="duplicate_survey">
        <input type="hidden"
               name="id"
               value="<?= h($survey['id']) ?>">
        <button class="btn btn-gray">
         複製
        </button>
       </form>
       <form method="post"
             style="display:inline"
             onsubmit="return confirm('削除しますか？')">
        <input type="hidden"
               name="action"
               value="delete_survey">
        <input type="hidden"
               name="id"
               value="<?= h($survey['id']) ?>">
        <button class="btn btn-danger">
         削除
        </button>
       </form>
      </div>
     </td>
    </tr>
<?php endforeach; ?>
<?php if ($filtered === []): ?>
    <tr>
     <td colspan="7"
         class="center muted">
      アンケートがありません。
     </td>
    </tr>
<?php endif; ?>
   </tbody>
  </table>
 </div>
</div>
<?php
    page_end();
}

function count_survey_answers(
    array $survey,
    array $answers
): int {
    $id =
        (string)(
            $survey['id'] ?? ''
        );

    $count = 0;

    foreach (
        $answers as $answer
    ) {
        if (
            (string)(
                $answer['surveyId']
                ?? ''
            ) === $id
        ) {
            $count++;
        }
    }

    return $count;
}

/*
 * ============================================================
 * 編集
 * ============================================================
 */

function render_edit(
    array $data,
    string $id
): void {
    $survey =
        $id !== ''
        ? survey_get(
            $data['surveys'],
            $id
        )
        : null;

    if (
        $id !== ''
        && $survey === null
    ) {
        flash(
            'error',
            '対象アンケートが見つかりません。'
        );

        redirect_screen('list');
    }

    if ($survey === null) {
        $survey = default_data()['surveys'][0];
        $survey['id'] = '';
        $survey['status'] = 'draft';
        $survey['title'] = '';
        $survey['groups'] = [[
            'id' => uid('group'),
            'title' => 'グループ1',
            'questions' => [],
        ]];
    }

    page_start(
        $id === ''
            ? 'アンケート作成'
            : 'アンケート編集'
    );

    render_flash();

    ?>
<div class="actions"
     style="justify-content:space-between;margin-bottom:18px">
 <div class="actions">
  <a class="btn btn-gray"
     href="<?= h(app_url(['screen'=>'list'])) ?>">
   キャンセル
  </a>
  <button class="btn btn-primary"
          form="survey-form">
   保存して一覧へ
  </button>
 </div>
 <div>
  状態：
  <span class="badge <?= h(
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
 </div>
</div>

<form method="post"
      id="survey-form">
<input type="hidden"
       name="action"
       value="save_survey">
<input type="hidden"
       name="id"
       value="<?= h($id) ?>">

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
            maxlength="<?= MAX_DESCRIPTION ?>"><?= h(
                $survey['description']
            ) ?></textarea>
 </div>

 <div class="form-grid">
  <div class="field">
   <label>開始日時</label>
   <input type="datetime-local"
          name="startAt"
          value="<?= h($survey['startAt']) ?>">
  </div>
  <div class="field">
   <label>終了日時</label>
   <input type="datetime-local"
          name="endAt"
          value="<?= h($survey['endAt']) ?>">
  </div>
 </div>

 <div class="field">
  <label>質問番号の採番方式</label>
  <select name="numbering">
   <option value="global"
    <?= ($survey['numbering']??'global')==='global'
        ? 'selected'
        : '' ?>>
    アンケート全体で通番：Q1、Q2...
   </option>
   <option value="group"
    <?= ($survey['numbering']??'global')==='group'
        ? 'selected'
        : '' ?>>
    グループ毎：Q1-1、Q1-2...
   </option>
  </select>
 </div>
</div>

<div id="groups">
<?php foreach (
    $survey['groups']
    as $gi => $group
): ?>
<div class="group"
     draggable="true"
     data-group>
 <div class="actions">
  <span class="drag-handle">☷</span>
  <input name="groups[<?= $gi ?>][id]"
         value="<?= h($group['id']) ?>"
         type="hidden">
  <input name="groups[<?= $gi ?>][title]"
         value="<?= h($group['title']) ?>"
         placeholder="グループタイトル">
 </div>

 <div class="questions">
<?php foreach (
    $group['questions']
    as $qi => $question
): ?>
 <div class="question"
      draggable="true"
      data-question>
  <input type="hidden"
         name="groups[<?= $gi ?>][questions][<?= $qi ?>][id]"
         value="<?= h($question['id']) ?>">

  <div class="actions"
       style="justify-content:space-between">
   <strong><?= h($question['number']) ?></strong>
   <span class="drag-handle">☷</span>
  </div>

  <div class="field">
   <label>質問文</label>
   <textarea
    name="groups[<?= $gi ?>][questions][<?= $qi ?>][text]"
    maxlength="<?= MAX_QUESTION ?>"
    required><?= h($question['text']) ?></textarea>
  </div>

  <div class="form-grid">
   <div class="field">
    <label>回答形式</label>
    <select
     name="groups[<?= $gi ?>][questions][<?= $qi ?>][type]">
     <option value="single"
      <?= $question['type']==='single'
          ? 'selected':'' ?>>
      単一選択
     </option>
     <option value="multiple"
      <?= $question['type']==='multiple'
          ? 'selected':'' ?>>
      複数選択
     </option>
     <option value="text"
      <?= $question['type']==='text'
          ? 'selected':'' ?>>
      自由記述
     </option>
    </select>
   </div>
   <div class="field">
    <label>必須</label>
    <label>
     <input type="checkbox"
      name="groups[<?= $gi ?>][questions][<?= $qi ?>][required]"
      value="1"
      <?= !empty($question['required'])
          ? 'checked'
          : '' ?>>
     必須回答
    </label>
   </div>
  </div>

<?php if (
    in_array(
        $question['type'],
        ['single','multiple'],
        true
    )
): ?>
  <div class="field">
   <label>選択肢</label>
<?php foreach (
    $question['options']
    as $oi => $option
): ?>
   <div class="option">
    <input type="hidden"
     name="groups[<?= $gi ?>][questions][<?= $qi ?>][options][<?= $oi ?>][id]"
     value="<?= h($option['id']) ?>">
    <input
     name="groups[<?= $gi ?>][questions][<?= $qi ?>][options][<?= $oi ?>][label]"
     value="<?= h($option['label']) ?>"
     placeholder="選択肢">
    <input
     type="hidden"
     name="groups[<?= $gi ?>][questions][<?= $qi ?>][options][<?= $oi ?>][nextQuestionId]"
     value="<?= h($option['nextQuestionId']) ?>">
   </div>
<?php endforeach; ?>
  </div>
<?php endif; ?>
 </div>
<?php endforeach; ?>
 </div>

 <button type="button"
         class="btn btn-gray"
         onclick="addQuestion(this)">
  ＋ 質問を追加
 </button>
</div>
<?php endforeach; ?>
</div>

<div style="margin-top:16px">
 <button type="button"
         class="btn btn-gray"
         onclick="addGroup()">
  ＋ グループを追加
 </button>
</div>
</form>

<script>
function addGroup(){
 const groups=document.getElementById('groups');
 const n=groups.children.length;
 const div=document.createElement('div');
 div.className='group';
 div.setAttribute('data-group','');
 div.innerHTML=
 `<div class="actions">
   <span class="drag-handle">☷</span>
   <input type="hidden"
    name="groups[${n}][id]"
    value="group-${Date.now()}">
   <input name="groups[${n}][title]"
    placeholder="グループタイトル">
  </div>
  <div class="questions"></div>
  <button type="button"
   class="btn btn-gray"
   onclick="addQuestion(this)">
   ＋ 質問を追加
  </button>`;
 groups.appendChild(div);
}

function addQuestion(button){
 const group=button.closest('[data-group]');
 const list=group.querySelector('.questions');
 const gi=[
  ...document.querySelectorAll('[data-group]')
 ].indexOf(group);
 const qi=list.children.length;

 const div=document.createElement('div');
 div.className='question';
 div.setAttribute('data-question','');
 div.innerHTML=
 `<input type="hidden"
   name="groups[${gi}][questions][${qi}][id]"
   value="question-${Date.now()}">
  <div class="actions">
   <strong>自動採番</strong>
   <span class="drag-handle">☷</span>
  </div>
  <div class="field">
   <label>質問文</label>
   <textarea required
    name="groups[${gi}][questions][${qi}][text]"></textarea>
  </div>
  <div class="form-grid">
   <div class="field">
    <label>回答形式</label>
    <select
     name="groups[${gi}][questions][${qi}][type]">
     <option value="single">単一選択</option>
     <option value="multiple">複数選択</option>
     <option value="text">自由記述</option>
    </select>
   </div>
   <div class="field">
    <label>必須</label>
    <label>
     <input type="checkbox"
      name="groups[${gi}][questions][${qi}][required]"
      value="1">
     必須回答
    </label>
   </div>
  </div>`;
 list.appendChild(div);
}

document.querySelectorAll('[data-group],[data-question]')
 .forEach(el=>{
  el.addEventListener('dragstart',e=>{
   e.dataTransfer.setData(
    'text/plain',
    ''
   );
   el.classList.add('dragging');
  });
  el.addEventListener('dragend',()=>{
   el.classList.remove('dragging');
  });
 });
</script>
<?php
    page_end();
}

/*
 * ============================================================
 * プレビュー
 * ============================================================
 */

function render_preview(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if ($survey === null) {
        flash(
            'error',
            '対象アンケートが見つかりません。'
        );

        redirect_screen('list');
    }

    page_start(
        'プレビュー'
    );

    ?>
<div class="actions"
     style="justify-content:space-between">
 <h1>プレビュー</h1>
 <a class="btn btn-gray"
    href="<?= h(app_url([
        'screen'=>'edit',
        'id'=>$id
    ])) ?>">
  編集へ戻る
 </a>
</div>

<div class="answer-wrap">
 <div class="card">
  <h2><?= h($survey['title']) ?></h2>
  <p><?= nl2br(
      h($survey['description'])
  ) ?></p>

<?php foreach (
    $survey['groups']
    as $group
): ?>
  <div class="group">
   <h3><?= h($group['title']) ?></h3>

<?php foreach (
    $group['questions']
    as $question
): ?>
   <div class="question">
    <strong>
     <?= h($question['number']) ?>
     <?= h($question['text']) ?>
    </strong>

<?php if (
    $question['type'] === 'single'
): ?>
<?php foreach (
    $question['options']
    as $option
): ?>
    <label class="mobile-choice">
     <input type="radio"
            disabled>
     <?= h($option['label']) ?>
    </label>
<?php endforeach; ?>
<?php elseif (
    $question['type'] === 'multiple'
): ?>
<?php foreach (
    $question['options']
    as $option
): ?>
    <label class="mobile-choice">
     <input type="checkbox"
            disabled>
     <?= h($option['label']) ?>
    </label>
<?php endforeach; ?>
<?php else: ?>
    <textarea disabled></textarea>
<?php endif; ?>

<?php if (
    !empty($question['required'])
): ?>
    <span class="small muted">必須</span>
<?php endif; ?>
   </div>
<?php endforeach; ?>
  </div>
<?php endforeach; ?>
 </div>
</div>
<?php
    page_end();
}

/*
 * ============================================================
 * kintone設定
 * ============================================================
 */

function render_kintone(
    array $settings
): void {
    $s =
        $settings['kintone'];

    page_start(
        'kintone連携設定'
    );

    render_flash();

    ?>
<h1>kintone連携設定</h1>

<div class="card">
<form method="post">
<input type="hidden"
       name="action"
       value="save_kintone">

<div class="form-grid">
 <div class="field">
  <label>サブドメイン</label>
  <input name="subdomain"
   value="<?= h($s['subdomain']) ?>"
   placeholder="xxxx / xxxx.cybozu.com">
 </div>

 <div class="field">
  <label>顧客管理アプリID</label>
  <input name="app_id"
   value="<?= h($s['app_id']) ?>">
 </div>

 <div class="field">
  <label>ログイン名</label>
  <input name="username"
   value="<?= h($s['username']) ?>">
 </div>

 <div class="field">
  <label>パスワード</label>
  <input type="password"
   name="password"
   autocomplete="new-password"
   placeholder="<?= !empty($s['password'])
       ? '設定済み（変更する場合のみ入力）'
       : '' ?>">
 </div>

 <div class="field">
  <label>Proxy</label>
  <input name="proxy"
   value="<?= h($s['proxy']) ?>"
   placeholder="host:port">
 </div>

 <div class="field">
  <label>SSL証明書検証</label>
  <label>
   <input type="checkbox"
    name="verify_ssl"
    value="1"
    <?= !empty($s['verify_ssl'])
        ? 'checked'
        : '' ?>>
   有効
  </label>
 </div>
</div>

<button class="btn btn-primary">
 設定保存
</button>
</form>
</div>

<div class="card">
<h2>接続確認</h2>

<form method="post"
      style="display:inline">
 <input type="hidden"
  name="action"
  value="test_kintone">
 <button class="btn btn-gray"
  onclick="return busy(this)">
  接続テスト
 </button>
</form>

<form method="post"
      style="display:inline">
 <input type="hidden"
  name="action"
  value="fetch_kintone_fields">
 <button class="btn btn-gray"
  onclick="return busy(this)">
  項目一覧を再取得
 </button>
</form>

<?php if (
    !empty($s['last_test'])
): ?>
<p class="small">
 接続確認済み：
 <?= h($s['last_test']) ?>
</p>
<?php endif; ?>
</div>

<script>
function busy(btn){
 btn.disabled=true;
 btn.textContent='処理中...';
 return true;
}
</script>
<?php
    page_end();
}

/*
 * ============================================================
 * メール設定
 * ============================================================
 */

function render_mail(
    array $settings
): void {
    $s =
        $settings['mail'];

    page_start(
        'メールサーバ設定'
    );

    render_flash();

    ?>
<h1>メールサーバ設定</h1>

<div class="card">
<form method="post">
<input type="hidden"
       name="action"
       value="save_mail">

<div class="form-grid">
 <div class="field">
  <label>SMTPサーバ</label>
  <input name="host"
   value="<?= h($s['host']) ?>">
 </div>

 <div class="field">
  <label>SMTPポート</label>
  <input type="number"
   name="port"
   value="<?= h($s['port']) ?>">
 </div>

 <div class="field">
  <label>暗号化方式</label>
  <select name="encryption">
   <option value="ssl"
    <?= $s['encryption']==='ssl'
        ? 'selected':'' ?>>
    SSL
   </option>
   <option value="tls"
    <?= $s['encryption']==='tls'
        ? 'selected':'' ?>>
    TLS
   </option>
   <option value="none"
    <?= $s['encryption']==='none'
        ? 'selected':'' ?>>
    なし
   </option>
  </select>
 </div>

 <div class="field">
  <label>SMTP認証</label>
  <label>
   <input type="checkbox"
    name="auth"
    value="1"
    <?= !empty($s['auth'])
        ? 'checked'
        : '' ?>>
   認証を使用
  </label>
 </div>

 <div class="field">
  <label>SMTPユーザー名</label>
  <input name="username"
   value="<?= h($s['username']) ?>">
 </div>

 <div class="field">
  <label>SMTPパスワード</label>
  <input type="password"
   name="password"
   autocomplete="new-password"
   placeholder="<?= !empty($s['password'])
       ? '設定済み（変更する場合のみ入力）'
       : '' ?>">
 </div>

 <div class="field">
  <label>送信元メールアドレス</label>
  <input type="email"
   name="from_email"
   value="<?= h($s['from_email']) ?>">
 </div>

 <div class="field">
  <label>送信元名</label>
  <input name="from_name"
   value="<?= h($s['from_name']) ?>">
 </div>

 <div class="field">
  <label>返信先メールアドレス</label>
  <input type="email"
   name="reply_to"
   value="<?= h($s['reply_to']) ?>">
 </div>
</div>

<button class="btn btn-primary">
 設定保存
</button>
</form>
</div>

<div class="card">
<h2>接続テスト</h2>

<form method="post">
 <input type="hidden"
  name="action"
  value="test_mail">
 <button class="btn btn-gray"
  onclick="return busy(this)">
  接続テスト
 </button>
</form>

<?php if (
    !empty($s['last_test'])
): ?>
<p class="small">
 接続確認済み：
 <?= h($s['last_test']) ?>
</p>
<?php endif; ?>
</div>

<script>
function busy(btn){
 btn.disabled=true;
 btn.textContent='処理中...';
 return true;
}
</script>
<?php
    page_end();
}

/*
 * ============================================================
 * 顧客送信
 * ============================================================
 */

function render_send(
    array $data,
    array $survey,
    array $settings
): void {
    page_start(
        '顧客選択・メール送信'
    );

    render_flash();

    $customers =
        $data['customers'];

    ?>
<h1>顧客選択・メール送信</h1>

<div class="card">
 <strong>対象アンケート：</strong>
 <?= h($survey['title']) ?>
</div>

<div class="card">
 <h2>顧客選択</h2>

 <div class="table-wrap">
 <table>
  <thead>
   <tr>
    <th>選択</th>
    <th>顧客名</th>
    <th>メールアドレス</th>
    <th>組織</th>
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
            value="<?= h($customer['id'] ?? '') ?>"
            form="send-form">
    </td>
    <td><?= h(
        $customer['name'] ?? ''
    ) ?></td>
    <td><?= h(
        $customer['email'] ?? ''
    ) ?></td>
    <td><?= h(
        $customer['organization'] ?? ''
    ) ?></td>
   </tr>
<?php endforeach; ?>
<?php if ($customers === []): ?>
   <tr>
    <td colspan="4"
        class="muted center">
     顧客情報がありません。
    </td>
   </tr>
<?php endif; ?>
  </tbody>
 </table>
 </div>
</div>

<div class="card">
<form id="send-form"
      method="post">
 <input type="hidden"
  name="action"
  value="send_mail">
 <input type="hidden"
  name="id"
  value="<?= h($survey['id']) ?>">

 <div class="field">
  <label>件名</label>
  <input name="subject"
   value="<?= h(
       $survey['title']
       . 'のご案内'
   ) ?>">
 </div>

 <div class="field">
  <label>本文</label>
  <textarea name="body"><?= h(
      "{顧客名} 様\n\n"
      . "アンケートへのご回答をお願いいたします。\n"
      . "{アンケートURL}"
  ) ?></textarea>
 </div>

 <button class="btn btn-primary"
  onclick="return confirm('選択した顧客へ送信しますか？')">
  一括送信
 </button>
</form>
</div>
<?php
    page_end();
}

/*
 * ============================================================
 * 集計
 * ============================================================
 */

function render_analytics(
    array $data,
    array $survey
): void {
    page_start(
        '回答集計・分析'
    );

    render_flash();

    $answers = [];

    foreach (
        $data['answers'] as $answer
    ) {
        if (
            ($answer['surveyId'] ?? '')
            === $survey['id']
        ) {
            $answers[] = $answer;
        }
    }

    ?>
<h1>回答集計・分析</h1>

<div class="card">
 <h2><?= h($survey['title']) ?></h2>
</div>

<div class="grid">
 <div class="metric">
  <div class="label">送信対象者数</div>
  <div class="value">
   <?= h(count($data['customers'])) ?>
  </div>
 </div>
 <div class="metric">
  <div class="label">回答数</div>
  <div class="value">
   <?= h(count($answers)) ?>
  </div>
 </div>
 <div class="metric">
  <div class="label">未登録回答数</div>
  <div class="value">0</div>
 </div>
 <div class="metric">
  <div class="label">回答率</div>
  <div class="value">
   <?= h(
       count($data['customers']) > 0
       ? round(
           count($answers)
           / count($data['customers'])
           * 100,
           1
       ) . '%'
       : '0%'
   ) ?>
  </div>
 </div>
</div>

<?php if ($answers === []): ?>
<div class="card center muted">
 現在、回答データはありません
</div>
<?php else: ?>
<div class="card">
 <h2>個別回答</h2>
 <div class="table-wrap">
  <table>
   <thead>
    <tr>
     <th>回答日時</th>
     <th>回答</th>
    </tr>
   </thead>
   <tbody>
<?php foreach ($answers as $answer): ?>
    <tr>
     <td><?= h(
         $answer['createdAt']
     ) ?></td>
     <td>
      <?= h(
          json_encode(
              $answer['answers'] ?? [],
              JSON_UNESCAPED_UNICODE
          )
      ) ?>
     </td>
    </tr>
<?php endforeach; ?>
   </tbody>
  </table>
 </div>
</div>
<?php endif; ?>
<?php
    page_end();
}

/*
 * ============================================================
 * 回答者画面
 * ============================================================
 */

function render_answer(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if ($survey === null) {
        render_answer_error(
            'アンケートが見つかりません。'
        );
        return;
    }

    if (
        ($survey['status'] ?? '')
        !== 'published'
    ) {
        render_answer_error(
            '現在このアンケートは回答を受け付けていません。'
        );
        return;
    }

    $answers =
        $_SESSION[
            'answer_' . $id
        ] ?? [];

    page_start(
        $survey['title'],
        false
    );

    ?>
<div class="answer-wrap">
 <div class="card">
  <h1><?= h($survey['title']) ?></h1>

  <?php if (
      $survey['description'] !== ''
  ): ?>
  <p><?= nl2br(
      h($survey['description'])
  ) ?></p>
  <?php endif; ?>

<form method="post">
 <input type="hidden"
  name="action"
  value="answer_save">
 <input type="hidden"
  name="id"
  value="<?= h($id) ?>">

<?php foreach (
    $survey['groups']
    as $group
): ?>
 <div class="group">
  <h2><?= h($group['title']) ?></h2>

<?php foreach (
    $group['questions']
    as $question
): ?>
  <div class="question">
   <h3>
    <?= h($question['number']) ?>
    <?= h($question['text']) ?>
    <?php if (
        !empty($question['required'])
    ): ?>
    <span style="color:#dc2626">*</span>
    <?php endif; ?>
   </h3>

<?php if (
    $question['type'] === 'single'
): ?>
<?php foreach (
    $question['options']
    as $option
): ?>
   <label class="mobile-choice">
    <input type="radio"
     name="answers[<?= h($question['id']) ?>]"
     value="<?= h($option['id']) ?>"
     <?= (
         ($answers[$question['id']]
         ?? '') === $option['id']
     ) ? 'checked' : '' ?>>
    <?= h($option['label']) ?>
   </label>
<?php endforeach; ?>
<?php elseif (
    $question['type'] === 'multiple'
): ?>
<?php
$selected =
    is_array(
        $answers[$question['id']]
        ?? null
    )
        ? $answers[$question['id']]
        : [];
?>
<?php foreach (
    $question['options']
    as $option
): ?>
   <label class="mobile-choice">
    <input type="checkbox"
     name="answers[<?= h($question['id']) ?>][]"
     value="<?= h($option['id']) ?>"
     <?= in_array(
         $option['id'],
         $selected,
         true
     ) ? 'checked' : '' ?>>
    <?= h($option['label']) ?>
   </label>
<?php endforeach; ?>
<?php else: ?>
   <textarea
    name="answers[<?= h($question['id']) ?>]"
    placeholder="回答を入力してください"><?= h(
        $answers[$question['id']]
        ?? ''
    ) ?></textarea>
<?php endif; ?>
  </div>
<?php endforeach; ?>
 </div>
<?php endforeach; ?>

<div class="actions"
     style="justify-content:space-between">
 <a class="btn btn-gray"
    href="javascript:history.back()">
  戻る
 </a>
 <button class="btn btn-primary">
  回答確認
 </button>
</div>
</form>
 </div>
</div>
<?php
    page_end();
}

function render_confirm(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if ($survey === null) {
        render_answer_error(
            'アンケートが見つかりません。'
        );
        return;
    }

    $answers =
        $_SESSION[
            'answer_' . $id
        ] ?? [];

    page_start(
        '回答確認',
        false
    );

    ?>
<div class="answer-wrap">
 <div class="card">
  <h1>回答確認</h1>

<?php foreach (
    $survey['groups']
    as $group
): ?>
 <div class="group">
  <h2><?= h($group['title']) ?></h2>

<?php foreach (
    $group['questions']
    as $question
): ?>
  <div class="question">
   <h3>
    <?= h($question['number']) ?>
    <?= h($question['text']) ?>
   </h3>
   <div>
    <?php
    $value =
        $answers[$question['id']]
        ?? '';

    if (is_array($value)) {
        echo h(
            implode(
                ', ',
                array_map(
                    'strval',
                    $value
                )
            )
        );
    } else {
        echo nl2br(h($value));
    }
    ?>
   </div>
  </div>
<?php endforeach; ?>
 </div>
<?php endforeach; ?>

<div class="actions">
 <a class="btn btn-gray"
  href="<?= h(app_url([
      'screen'=>'answer',
      'id'=>$id
  ])) ?>">
  回答を修正
 </a>

 <form method="post">
  <input type="hidden"
   name="action"
   value="submit_answer">
  <input type="hidden"
   name="id"
   value="<?= h($id) ?>">
  <button class="btn btn-primary"
   onclick="return confirm('回答を送信しますか？')">
   回答送信
  </button>
 </form>
</div>
 </div>
</div>
<?php
    page_end();
}

function render_complete(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    page_start(
        '回答完了',
        false
    );

    ?>
<div class="answer-wrap">
 <div class="card center">
  <h1>回答ありがとうございました</h1>
  <p>
   <?= h(
       $survey['title']
       ?? 'アンケート'
   ) ?>
   の回答を受け付けました。
  </p>
  <p class="muted">
   これで回答者フローは終了です。
  </p>
 </div>
</div>
<?php
    page_end();
}

function render_answer_error(
    string $message
): void {
    http_response_code(404);

    page_start(
        'アンケート',
        false
    );

    ?>
<div class="answer-wrap">
 <div class="card center">
  <h1>アンケートを表示できません</h1>
  <p><?= h($message) ?></p>
 </div>
</div>
<?php
    page_end();
}

/*
 * ============================================================
 * CSV / PDF
 * ============================================================
 */

function output_csv(
    array $data,
    array $survey
): never {
    $filename =
        'survey-'
        . $survey['id']
        . '.csv';

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="'
        . $filename
        . '"'
    );

    $fp = fopen(
        'php://output',
        'wb'
    );

    fwrite(
        $fp,
        "\xEF\xBB\xBF"
    );

    fputcsv(
        $fp,
        ['回答日時', '回答']
    );

    foreach (
        $data['answers'] as $answer
    ) {
        if (
            ($answer['surveyId'] ?? '')
            !== $survey['id']
        ) {
            continue;
        }

        fputcsv(
            $fp,
            [
                $answer['createdAt'] ?? '',
                json_encode(
                    $answer['answers'] ?? [],
                    JSON_UNESCAPED_UNICODE
                ),
            ]
        );
    }

    fclose($fp);
    exit;
}

/*
 * ============================================================
 * エラー
 * ============================================================
 */

function safe_error(
    Throwable $e
): string {
    $message =
        trim(
            $e->getMessage()
        );

    $message =
        preg_replace(
            '/(password|authorization|x-cybozu-authorization|secret|token)\s*[:=]\s*\S+/i',
            '$1=[REDACTED]',
            $message
        )
        ?? $message;

    return mb_substr(
        $message,
        0,
        1000
    );
}

function render_system_error(
    Throwable $e
): never {
    http_response_code(500);

    $message =
        safe_error($e);

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= h(APP_TITLE) ?></title>
<style>
body{
 margin:0;
 padding:40px 20px;
 background:#f8fafc;
 color:#1e293b;
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",
 "Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
}
.box{
 max-width:760px;
 margin:auto;
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:12px;
 padding:24px;
 box-shadow:0 4px 18px rgba(15,23,42,.08);
}
h1{color:#dc2626;font-size:22px}
.detail{
 white-space:pre-wrap;
 background:#f8fafc;
 border-radius:8px;
 padding:14px;
}
a{color:#2563eb}
</style>
</head>
<body>
<div class="box">
<h1>処理中にエラーが発生しました。</h1>
<p>
処理を完了できませんでした。
</p>
<div class="detail"><?= h($message) ?></div>
<p>
<a href="<?= h(
    app_url(['screen'=>'list'])
) ?>">
アンケート一覧へ戻る
</a>
</p>
</div>
</body>
</html>
<?php
    exit;
}

/*
 * ============================================================
 * 起動
 * ============================================================
 */

try {
    start_session();

    $data =
        load_data();

    $settings =
        load_settings();

    refresh_status(
        $data
    );

    /*
     * CSV等のGET出力。
     */
    $screen =
        get_string(
            'screen',
            'list'
        );

    if (
        $screen === 'csv'
    ) {
        $id =
            get_string('id');

        $survey =
            survey_get(
                $data['surveys'],
                $id
            );

        if ($survey === null) {
            throw new RuntimeException(
                '対象アンケートが見つかりません。'
            );
        }

        output_csv(
            $data,
            $survey
        );
    }

    /*
     * POST:
     *
     * POST受信
     * ↓
     * validation
     * ↓
     * 業務処理 / 外部通信
     * ↓
     * 結果確定
     * ↓
     * 必要な場合のみ303
     *
     * 外部通信関数自身は
     * header("Location")を実行しない。
     */
    $postResult =
        handle_post(
            $data,
            $settings
        );

    if ($postResult !== null) {
        redirect_screen(
            $postResult['screen'],
            array_filter(
                [
                    'id' =>
                        $postResult['id']
                        ?? null,
                ],
                static fn($v) =>
                    $v !== null
                    && $v !== ''
            )
        );
    }

    /*
     * 回答者画面。
     *
     * 管理者ナビゲーションを一切出さない。
     */
    if (
        in_array(
            $screen,
            [
                'answer',
                'confirm',
                'complete',
            ],
            true
        )
    ) {
        $id =
            get_string('id');

        if ($id === '') {
            render_answer_error(
                'アンケートIDが指定されていません。'
            );
            exit;
        }

        match ($screen) {
            'answer' =>
                render_answer(
                    $data,
                    $id
                ),
            'confirm' =>
                render_confirm(
                    $data,
                    $id
                ),
            'complete' =>
                render_complete(
                    $data,
                    $id
                ),
        };

        exit;
    }

    /*
     * 管理者側の対象固定画面。
     */
    if (
        in_array(
            $screen,
            [
                'analytics',
                'send',
            ],
            true
        )
    ) {
        $id =
            get_string('id');

        if ($id === '') {
            flash(
                'error',
                '対象アンケートが指定されていません。'
            );

            redirect_screen(
                'list'
            );
        }

        $survey =
            survey_get(
                $data['surveys'],
                $id
            );

        if ($survey === null) {
            flash(
                'error',
                '対象アンケートが見つかりません。'
            );

            redirect_screen(
                'list'
            );
        }

        if ($screen === 'analytics') {
            render_analytics(
                $data,
                $survey
            );
        } else {
            render_send(
                $data,
                $survey,
                $settings
            );
        }

        exit;
    }

    /*
     * 通常の管理者画面。
     */
    switch ($screen) {
        case 'list':
            render_list(
                $data
            );
            break;

        case 'edit':
            render_edit(
                $data,
                get_string('id')
            );
            break;

        case 'preview':
            $id =
                get_string('id');

            render_preview(
                $data,
                $id
            );
            break;

        case 'kintone':
            /*
             * 保存済み秘密情報はHTMLへ渡さない。
             */
            $view =
                $settings;

            $view['kintone']['password'] =
                '';

            $view['kintone']['passwordConfigured'] =
                !empty(
                    $settings['kintone']['password']
                );

            render_kintone(
                $view
            );
            break;

        case 'mail':
            /*
             * 保存済み秘密情報はHTMLへ渡さない。
             */
            $view =
                $settings;

            $view['mail']['password'] =
                '';

            $view['mail']['passwordConfigured'] =
                !empty(
                    $settings['mail']['password']
                );

            render_mail(
                $view
            );
            break;

        default:
            flash(
                'error',
                '指定された画面は存在しません。'
            );

            redirect_screen(
                'list'
            );
    }
} catch (Throwable $e) {
    /*
     * 白画面禁止。
     */
    render_system_error(
        $e
    );
}

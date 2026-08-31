<?php
declare(strict_types=1);

/*
 * ============================================================
 * アンケートアプリ
 *
 * 認証情報保存方式
 * ------------------------------------------------------------
 * 1. 照合だけが必要なパスワード
 *    password_hash()
 *    password_verify()
 *
 * 2. 外部サービス接続用秘密情報
 *    Sodium secretbox
 *
 *    保存形式:
 *      ENC:v1:<nonce>:<ciphertext>
 *
 * 3. 暗号鍵
 *    index.phpには記載しない。
 *
 *    以下の優先順位で取得する。
 *      SURVEY_APP_ENCRYPTION_KEY
 *      Web公開領域外等の設定
 *
 *    環境変数等から取得できない場合、
 *    平文保存へフォールバックしない。
 *
 * 4. 旧openssl/AES方式は新方式として扱わない。
 *    既存データが旧形式の場合は、
 *    秘密情報を再入力してもらう。
 *
 * ============================================================
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';

const DATA_DIR  = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SET_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const KINTONE_TIMEOUT = 30;
const SMTP_TIMEOUT    = 30;

const MAX_TITLE       = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION    = 1000;
const MAX_OPTION      = 500;

const SECRET_PREFIX = 'ENC:v1:';

/*
 * ============================================================
 * 基本
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

function get_string(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;

    return is_scalar($value)
        ? trim((string)$value)
        : $default;
}

function post_string(string $key, string $default = ''): string
{
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
 * 業務処理結果が確定してからのみ呼び出す。
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
        $dir === '.' ||
        $dir === '/' ||
        $dir === '\\'
    ) {
        return '/';
    }

    return rtrim($dir, '/') . '/';
}

function start_session(): void
{
    if (
        session_status() ===
        PHP_SESSION_ACTIVE
    ) {
        return;
    }

    $secure =
        (
            !empty($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off'
        ) ||
        (int)(
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

/*
 * GETアクセスごとにsession_regenerate_id()
 * を実行してはいけない。
 */

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
    $value =
        $_SESSION['flash'] ?? null;

    unset($_SESSION['flash']);

    return is_array($value)
        ? $value
        : null;
}

/*
 * ============================================================
 * JSON保存
 * ============================================================
 */

function ensure_data_dir(): void
{
    if (is_dir(DATA_DIR)) {
        return;
    }

    if (
        !@mkdir(
            DATA_DIR,
            0770,
            true
        ) &&
        !is_dir(DATA_DIR)
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
        return $fallback;
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            fclose($fp);
            return $fallback;
        }

        $raw = stream_get_contents($fp);

        flock($fp, LOCK_UN);
        fclose($fp);
    } catch (Throwable) {
        @fclose($fp);
        return $fallback;
    }

    if (
        $raw === false ||
        trim($raw) === ''
    ) {
        return $fallback;
    }

    $decoded = json_decode(
        $raw,
        true
    );

    return is_array($decoded)
        ? $decoded
        : $fallback;
}

function save_json(
    string $file,
    array $data
): void {
    ensure_data_dir();

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException(
            'JSONデータを生成できません。'
        );
    }

    $tmp =
        $file .
        '.tmp.' .
        bin2hex(random_bytes(8));

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
                $written === false ||
                $written === 0
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

            /*
             * ここには暗号文のみを保存する。
             */
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

            /*
             * ここには暗号文のみを保存する。
             */
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
        DATA_FILE,
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
            !isset($data[$key]) ||
            !is_array($data[$key])
        ) {
            $data[$key] = [];
        }
    }

    return $data;
}

function save_data(
    array $data
): void {
    save_json(
        DATA_FILE,
        $data
    );
}

function load_settings(): array
{
    ensure_data_dir();

    $default =
        default_settings();

    $settings = load_json(
        SET_FILE,
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
 * 秘密情報暗号化
 * ============================================================
 *
 * 要件:
 *
 * 外部サービス接続用秘密情報はハッシュ化しない。
 * 復号が必要なのでSodium secretboxを使用する。
 *
 * 保存形式:
 *
 * ENC:v1:<nonce>:<ciphertext>
 *
 * nonce / ciphertext はBase64。
 *
 * 暗号鍵を保存データと同じ場所に置かない。
 *
 * ============================================================
 */

function encryption_key(): string
{
    /*
     * 第一候補:
     * 実行環境の環境変数。
     */
    $value =
        getenv(
            'SURVEY_APP_ENCRYPTION_KEY'
        );

    if (
        is_string($value) &&
        $value !== ''
    ) {
        /*
         * Base64形式を優先。
         */
        $decoded =
            base64_decode(
                $value,
                true
            );

        if (
            $decoded !== false &&
            strlen($decoded) ===
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        ) {
            return $decoded;
        }

        /*
         * 環境変数へ32バイトそのものを
         * 設定している環境にも対応。
         */
        if (
            strlen($value) ===
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        ) {
            return $value;
        }

        throw new RuntimeException(
            '暗号鍵の形式が不正です。'
        );
    }

    /*
     * ここでは旧実装のように
     * _data/.secret を自動生成しない。
     *
     * 要件上、暗号文と同じ保存領域へ
     * 鍵を置いてはいけないため。
     */
    throw new RuntimeException(
        '暗号化鍵が設定されていません。'
    );
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

    $parts =
        explode(
            ':',
            $value
        );

    return count($parts) === 4 &&
        $parts[0] === 'ENC' &&
        $parts[1] === 'v1' &&
        $parts[2] !== '' &&
        $parts[3] !== '';
}

function encrypt_secret(
    string $plain
): string {
    if ($plain === '') {
        return '';
    }

    if (
        !extension_loaded('sodium')
    ) {
        throw new RuntimeException(
            'PHP Sodium拡張が利用できません。'
        );
    }

    $key = encryption_key();

    $nonce =
        random_bytes(
            SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
        );

    $ciphertext =
        sodium_crypto_secretbox(
            $plain,
            $nonce,
            $key
        );

    return SECRET_PREFIX .
        base64_encode($nonce) .
        ':' .
        base64_encode($ciphertext);
}

function decrypt_secret(
    string $encrypted
): string {
    if ($encrypted === '') {
        return '';
    }

    if (
        !is_encrypted_secret(
            $encrypted
        )
    ) {
        /*
         * 旧openssl形式や平文を
         * 新方式として扱わない。
         */
        throw new RuntimeException(
            '保存済み認証情報が現在の暗号化方式ではありません。'
        );
    }

    if (
        !extension_loaded('sodium')
    ) {
        throw new RuntimeException(
            'PHP Sodium拡張が利用できません。'
        );
    }

    $parts =
        explode(
            ':',
            $encrypted
        );

    $nonce =
        base64_decode(
            $parts[2],
            true
        );

    $ciphertext =
        base64_decode(
            $parts[3],
            true
        );

    if (
        $nonce === false ||
        $ciphertext === false
    ) {
        throw new RuntimeException(
            '保存済み認証情報の形式が不正です。'
        );
    }

    if (
        strlen($nonce) !==
        SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
    ) {
        throw new RuntimeException(
            '保存済み認証情報のnonceが不正です。'
        );
    }

    try {
        $plain =
            sodium_crypto_secretbox_open(
                $ciphertext,
                $nonce,
                encryption_key()
            );
    } catch (Throwable) {
        throw new RuntimeException(
            '保存済み認証情報を復号できません。'
        );
    }

    if ($plain === false) {
        throw new RuntimeException(
            '保存済み認証情報を復号できません。'
        );
    }

    return $plain;
}

/*
 * ============================================================
 * 設定値の実行時展開
 * ============================================================
 *
 * 注意:
 * HTML表示用には使用しない。
 *
 * 外部通信直前など、実際に秘密情報が必要な処理だけが
 * この関数を使用する。
 *
 * ============================================================
 */

function runtime_settings(
    array $settings
): array {
    foreach (
        ['kintone', 'mail'] as $service
    ) {
        $password =
            (string)(
                $settings[$service]['password']
                ?? ''
            );

        if ($password === '') {
            continue;
        }

        /*
         * 新方式のみ復号する。
         */
        if (
            !is_encrypted_secret(
                $password
            )
        ) {
            /*
             * 旧方式/平文を
             * 勝手に使わない。
             */
            $settings[$service]['password'] =
                '';

            $settings[$service]['credential_error'] =
                '保存済み認証情報が旧方式です。'
                . 'パスワードを再入力して設定を保存してください。';

            continue;
        }

        $settings[$service]['password'] =
            decrypt_secret(
                $password
            );
    }

    return $settings;
}

/*
 * ============================================================
 * 設定保存
 * ============================================================
 */

function settings_for_save(
    array $settings
): array {
    foreach (
        ['kintone', 'mail'] as $service
    ) {
        $password =
            (string)(
                $settings[$service]['password']
                ?? ''
            );

        if ($password === '') {
            continue;
        }

        /*
         * すでにENC:v1ならそのまま。
         */
        if (
            is_encrypted_secret(
                $password
            )
        ) {
            continue;
        }

        /*
         * 平文を保存せず、
         * 必ずSodiumで暗号化する。
         */
        $settings[$service]['password'] =
            encrypt_secret(
                $password
            );
    }

    return $settings;
}

function save_settings(
    array $settings
): void {
    save_json(
        SET_FILE,
        settings_for_save($settings)
    );
}

/*
 * ============================================================
 * kintone
 * ============================================================
 */

function normalize_kintone_subdomain(
    string $value
): string {
    $value = trim($value);

    if ($value === '') {
        throw new RuntimeException(
            'kintoneサブドメインを入力してください。'
        );
    }

    /*
     * https://xxxx.cybozu.com
     * xxxx.cybozu.com
     * xxxx
     * を許容する。
     */
    $value =
        preg_replace(
            '#^https?://#i',
            '',
            $value
        ) ?? $value;

    $value =
        preg_replace(
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
        $value =
            substr(
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

function validate_kintone(
    array $config,
    bool $requirePassword = true
): array {
    $errors = [];

    try {
        normalize_kintone_subdomain(
            (string)(
                $config['subdomain'] ?? ''
            )
        );
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    $appId =
        (string)(
            $config['app_id'] ?? ''
        );

    if (
        $appId === '' ||
        !ctype_digit($appId) ||
        (int)$appId <= 0
    ) {
        $errors[] =
            '顧客管理アプリIDが不正です。';
    }

    if (
        trim(
            (string)(
                $config['username'] ?? ''
            )
        ) === ''
    ) {
        $errors[] =
            'ログイン名を入力してください。';
    }

    if (
        $requirePassword &&
        trim(
            (string)(
                $config['password'] ?? ''
            )
        ) === ''
    ) {
        $errors[] =
            'kintoneパスワードを入力してください。';
    }

    $proxy =
        trim(
            (string)(
                $config['proxy'] ?? ''
            )
        );

    if ($proxy !== '') {
        if (
            !preg_match(
                '/^[^:\s]+:\d{1,5}$/',
                $proxy
            )
        ) {
            $errors[] =
                'Proxyはhost:port形式で入力してください。';
        }
    }

    return $errors;
}

/*
 * kintone API共通処理。
 *
 * 画面リダイレクトは一切行わない。
 */
function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $subdomain =
        normalize_kintone_subdomain(
            (string)$config['subdomain']
        );

    $appId =
        (string)$config['app_id'];

    if (
        !ctype_digit($appId) ||
        (int)$appId <= 0
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
        $username === '' ||
        $password === ''
    ) {
        throw new RuntimeException(
            'kintone認証情報が設定されていません。'
        );
    }

    $url =
        'https://' .
        $subdomain .
        '.cybozu.com' .
        $path;

    /*
     * kintone公式仕様:
     *
     * username:password
     * をBase64化して
     * X-Cybozu-Authorizationへ設定。
     */
    $authorization =
        base64_encode(
            $username .
            ':' .
            $password
        );

    $headers = [
        'X-Cybozu-Authorization: ' .
            $authorization,
        'Accept: application/json',
        'User-Agent: SurveyApp/2.0',
        'Connection: close',
    ];

    $options = [
        'http' => [
            'method' =>
                strtoupper($method),
            'header' =>
                implode(
                    "\r\n",
                    $headers
                ),
            'ignore_errors' => true,
            'timeout' =>
                KINTONE_TIMEOUT,

            /*
             * APIの302/303を
             * アプリの画面遷移として扱わない。
             */
            'follow_location' => 0,
            'max_redirects' => 0,
        ],

        'ssl' => [
            'verify_peer' =>
                !empty(
                    $config['verify_ssl']
                ),
            'verify_peer_name' =>
                !empty(
                    $config['verify_ssl']
                ),
            'allow_self_signed' =>
                empty(
                    $config['verify_ssl']
                ),
            'SNI_enabled' => true,
            'peer_name' =>
                $subdomain .
                '.cybozu.com',
        ],
    ];

    if ($body !== null) {
        $json =
            json_encode(
                $body,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_INVALID_UTF8_SUBSTITUTE
            );

        if ($json === false) {
            throw new RuntimeException(
                'kintoneリクエストを生成できません。'
            );
        }

        $options['http']['content'] =
            $json;

        $headers[] =
            'Content-Type: application/json';

        $options['http']['header'] =
            implode(
                "\r\n",
                $headers
            );
    }

    $proxy =
        trim(
            (string)(
                $config['proxy'] ?? ''
            )
        );

    if ($proxy !== '') {
        [$proxyHost, $proxyPort] =
            explode(
                ':',
                $proxy,
                2
            );

        $proxyPort =
            (int)$proxyPort;

        if (
            $proxyHost === '' ||
            $proxyPort < 1 ||
            $proxyPort > 65535
        ) {
            throw new RuntimeException(
                'Proxy設定が不正です。'
            );
        }

        $options['http']['proxy'] =
            'tcp://' .
            $proxyHost .
            ':' .
            $proxyPort;

        $options['http']['request_fulluri'] =
            true;
    }

    $context =
        stream_context_create(
            $options
        );

    $response =
        @file_get_contents(
            $url,
            false,
            $context
        );

    $responseHeaders =
        $http_response_header ?? [];

    $status = 0;

    foreach (
        $responseHeaders as $header
    ) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})/',
                $header,
                $match
            )
        ) {
            $status =
                (int)$match[1];
        }
    }

    if ($response === false) {
        throw new RuntimeException(
            'kintoneへの通信に失敗しました。'
            . ' 接続先、Proxy、SSL設定、'
            . 'タイムアウトを確認してください。'
        );
    }

    /*
     * 302/303は成功ではない。
     */
    if (
        $status === 302 ||
        $status === 303
    ) {
        throw new RuntimeException(
            'kintoneからリダイレクト応答（HTTP ' .
            $status .
            '）が返されました。'
        );
    }

    $decoded =
        json_decode(
            $response,
            true
        );

    if (
        $status < 200 ||
        $status >= 300
    ) {
        $code = '';
        $message = '';

        if (is_array($decoded)) {
            $code =
                (string)(
                    $decoded['code'] ?? ''
                );

            $message =
                (string)(
                    $decoded['message'] ?? ''
                );
        }

        $detail =
            'kintone APIエラー HTTP ' .
            $status;

        if ($code !== '') {
            $detail .=
                ' [' .
                $code .
                ']';
        }

        if ($message !== '') {
            $detail .=
                ' ' .
                $message;
        }

        throw new RuntimeException(
            $detail
        );
    }

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'kintoneから正常なJSON応答を取得できませんでした。'
        );
    }

    return [
        'status' => $status,
        'body' => $decoded,
        'headers' => $responseHeaders,
    ];
}

function kintone_test(
    array $config
): array {
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?id=' .
        rawurlencode(
            (string)$config['app_id']
        )
    );
}

function kintone_fields(
    array $config
): array {
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode(
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
        '/k/v1/records.json?app=' .
        rawurlencode(
            (string)$config['app_id']
        )
    );
}

function kintone_field_list(
    array $response
): array {
    $properties =
        $response['body']['properties']
        ?? [];

    if (!is_array($properties)) {
        return [];
    }

    $fields = [];

    foreach (
        $properties as $code => $field
    ) {
        if (!is_array($field)) {
            continue;
        }

        $fields[] = [
            'code' =>
                (string)$code,
            'label' =>
                (string)(
                    $field['label'] ??
                    $code
                ),
            'type' =>
                (string)(
                    $field['type'] ?? ''
                ),
        ];
    }

    usort(
        $fields,
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

    return $fields;
}

/*
 * ============================================================
 * SMTP
 * ============================================================
 *
 * PHP cURL / mail() は使用しない。
 * stream_socket_client() を使用する。
 *
 * ============================================================
 */

function validate_mail(
    array $config
): array {
    $errors = [];

    $host =
        trim(
            (string)(
                $config['host'] ?? ''
            )
        );

    if ($host === '') {
        $errors[] =
            'SMTPサーバを入力してください。';
    }

    $port =
        (int)(
            $config['port'] ?? 0
        );

    if (
        $port < 1 ||
        $port > 65535
    ) {
        $errors[] =
            'SMTPポートが不正です。';
    }

    $encryption =
        (string)(
            $config['encryption'] ?? ''
        );

    if (
        !in_array(
            $encryption,
            ['ssl', 'tls', 'none'],
            true
        )
    ) {
        $errors[] =
            'SMTP暗号化方式が不正です。';
    }

    if (
        !empty($config['auth']) &&
        trim(
            (string)(
                $config['username'] ?? ''
            )
        ) === ''
    ) {
        $errors[] =
            'SMTPユーザー名を入力してください。';
    }

    if (
        !empty($config['auth']) &&
        trim(
            (string)(
                $config['password'] ?? ''
            )
        ) === ''
    ) {
        $errors[] =
            'SMTPパスワードを入力してください。';
    }

    $from =
        (string)(
            $config['from_email'] ?? ''
        );

    if (
        $from === '' ||
        filter_var(
            $from,
            FILTER_VALIDATE_EMAIL
        ) === false
    ) {
        $errors[] =
            '送信元メールアドレスが不正です。';
    }

    $reply =
        trim(
            (string)(
                $config['reply_to'] ?? ''
            )
        );

    if (
        $reply !== '' &&
        filter_var(
            $reply,
            FILTER_VALIDATE_EMAIL
        ) === false
    ) {
        $errors[] =
            '返信先メールアドレスが不正です。';
    }

    return $errors;
}

/*
 * ============================================================
 * アンケート
 * ============================================================
 */

function survey_index(
    array $surveys,
    string $id
): int {
    foreach (
        $surveys as $i => $survey
    ) {
        if (
            (string)(
                $survey['id'] ?? ''
            ) === $id
        ) {
            return $i;
        }
    }

    return -1;
}

function survey_get(
    array $surveys,
    string $id
): ?array {
    $index =
        survey_index(
            $surveys,
            $id
        );

    return $index >= 0
        ? $surveys[$index]
        : null;
}

function all_questions(
    array $survey
): array {
    $result = [];

    foreach (
        $survey['groups'] ?? [] as $group
    ) {
        foreach (
            $group['questions'] ?? [] as $question
        ) {
            $result[] =
                $question;
        }
    }

    return $result;
}

function recalc_numbers(
    array &$survey
): void {
    $global = 1;
    $groupNo = 1;

    foreach (
        $survey['groups'] as &$group
    ) {
        $questionNo = 1;

        foreach (
            $group['questions'] ?? []
            as &$question
        ) {
            if (
                ($survey['numbering'] ?? 'global')
                === 'group'
            ) {
                $question['number'] =
                    'Q' .
                    $groupNo .
                    '-' .
                    $questionNo;
            } else {
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

function refresh_status(
    array &$data
): void {
    $changed = false;

    foreach (
        $data['surveys'] as &$survey
    ) {
        if (
            ($survey['status'] ?? '') !==
            'published'
        ) {
            continue;
        }

        $endAt =
            (string)(
                $survey['endAt'] ?? ''
            );

        if ($endAt === '') {
            continue;
        }

        $end =
            strtotime($endAt);

        if (
            $end !== false &&
            $end < time()
        ) {
            $survey['status'] =
                'ended';

            $survey['updatedAt'] =
                now();

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
 * エラー表示
 * ============================================================
 */

function safe_error(
    Throwable $e
): string {
    /*
     * パスワード、Authorization等が
     * エラーメッセージへ混入しないようにする。
     */
    $message =
        trim($e->getMessage());

    $message =
        preg_replace(
            '/(password|authorization|x-cybozu-authorization|secret|token)\s*[:=]\s*\S+/i',
            '$1=[REDACTED]',
            $message
        ) ?? $message;

    return mb_substr(
        $message,
        0,
        1000
    );
}

/*
 * ============================================================
 * POST処理
 * ============================================================
 *
 * 外部サービス関数はredirectしない。
 *
 * POST
 *  ↓
 * validation
 *  ↓
 * business
 *  ↓
 * external communication
 *  ↓
 * result確定
 *  ↓
 * screen遷移
 *
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
     * --------------------------------------------------------
     * kintone設定保存
     * --------------------------------------------------------
     */
    if (
        $action ===
        'save_kintone'
    ) {
        $old =
            $settings['kintone']
            ?? default_settings()['kintone'];

        $password =
            post_string('password');

        /*
         * 空欄なら既存暗号文を維持。
         */
        if ($password === '') {
            $password =
                (string)(
                    $old['password'] ?? ''
                );
        }

        /*
         * 既存暗号文を再暗号化しない。
         */
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

        /*
         * 新規入力がない状態で旧形式のパスワードを
         * 維持することを許さない。
         */
        if (
            $password !== '' &&
            !is_encrypted_secret($password)
        ) {
            /*
             * 新規入力された平文なら暗号化して保存。
             */
            $config['password'] =
                encrypt_secret(
                    $password
                );
        }

        $runtime =
            $config;

        /*
         * 保存前検証。
         * 新規入力時にはruntimeへ平文を入れているので、
         * 検証には別途値を使う。
         */
        $validationConfig =
            $config;

        if (
            is_encrypted_secret(
                (string)$validationConfig['password']
            )
        ) {
            /*
             * 保存済み暗号文については、
             * パスワード存在確認だけ行う。
             */
            $validationConfig['password'] =
                'configured';
        }

        $errors =
            validate_kintone(
                $validationConfig,
                false
            );

        if ($errors !== []) {
            flash(
                'error',
                implode(
                    "\n",
                    $errors
                )
            );

            return [
                'screen' => 'kintone',
            ];
        }

        $settings['kintone'] =
            $config;

        save_settings(
            $settings
        );

        flash(
            'success',
            'kintone接続設定を保存しました。'
        );

        return [
            'screen' => 'kintone',
        ];
    }

    /*
     * --------------------------------------------------------
     * kintone接続テスト
     * --------------------------------------------------------
     */
    if (
        $action ===
        'test_kintone'
    ) {
        try {
            /*
             * 設定を実行時に復号。
             *
             * 旧方式ならここで明示的なエラーになる。
             */
            $runtime =
                runtime_settings(
                    $settings
                );

            if (
                !empty(
                    $runtime['kintone']
                    ['credential_error']
                    ?? ''
                )
            ) {
                throw new RuntimeException(
                    $runtime['kintone']
                    ['credential_error']
                );
            }

            $response =
                kintone_test(
                    $runtime['kintone']
                );

            if (
                ($response['status'] ?? 0)
                !== 200
            ) {
                throw new RuntimeException(
                    'kintone接続テストに失敗しました。'
                );
            }

            $settings['kintone']
                ['last_test'] =
                now();

            $settings['kintone']
                ['last_error'] =
                '';

            save_settings(
                $settings
            );

            flash(
                'success',
                'kintone接続テスト成功。'
            );
        } catch (Throwable $e) {
            $message =
                safe_error($e);

            $settings['kintone']
                ['last_error'] =
                $message;

            /*
             * 設定自体は保持する。
             * パスワードを平文で保存しない。
             */
            save_settings(
                $settings
            );

            flash(
                'error',
                'kintone接続テスト失敗：' .
                $message
            );
        }

        return [
            'screen' => 'kintone',
        ];
    }

    /*
     * --------------------------------------------------------
     * kintone項目取得
     * --------------------------------------------------------
     */
    if (
        $action ===
        'load_kintone_fields'
    ) {
        try {
            $runtime =
                runtime_settings(
                    $settings
                );

            $response =
                kintone_fields(
                    $runtime['kintone']
                );

            $fields =
                kintone_field_list(
                    $response
                );

            if ($fields === []) {
                throw new RuntimeException(
                    'kintone項目を取得できませんでした。'
                );
            }

            $settings['kintone']
                ['fields'] =
                $fields;

            $settings['kintone']
                ['last_error'] =
                '';

            save_settings(
                $settings
            );

            flash(
                'success',
                count($fields) .
                '件のkintone項目を取得しました。'
            );
        } catch (Throwable $e) {
            $message =
                safe_error($e);

            $settings['kintone']
                ['last_error'] =
                $message;

            save_settings(
                $settings
            );

            flash(
                'error',
                'kintone項目取得失敗：' .
                $message
            );
        }

        return [
            'screen' => 'kintone',
        ];
    }

    /*
     * --------------------------------------------------------
     * kintoneマッピング
     * --------------------------------------------------------
     */
    if (
        $action ===
        'save_kintone_mapping'
    ) {
        $address =
            $_POST['mapping_address']
            ?? [];

        if (!is_array($address)) {
            $address = [];
        }

        $settings['kintone']
            ['mapping'] = [
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
                    array_values(
                        array_map(
                            'strval',
                            $address
                        )
                    ),
            ];

        save_settings(
            $settings
        );

        flash(
            'success',
            '顧客項目マッピングを保存しました。'
        );

        return [
            'screen' => 'kintone',
        ];
    }

    /*
     * --------------------------------------------------------
     * kintone顧客同期
     * --------------------------------------------------------
     */
    if (
        $action ===
        'sync_kintone'
    ) {
        try {
            $runtime =
                runtime_settings(
                    $settings
                );

            $response =
                kintone_records(
                    $runtime['kintone']
                );

            $records =
                $response['body']['records']
                ?? [];

            if (!is_array($records)) {
                throw new RuntimeException(
                    'kintone顧客データの形式が不正です。'
                );
            }

            /*
             * マッピングに基づいて顧客を生成。
             */
            $mapping =
                $settings['kintone']
                ['mapping'] ?? [];

            $customers = [];

            foreach (
                $records as $record
            ) {
                if (!is_array($record)) {
                    continue;
                }

                $get = static function (
                    string $code
                ) use ($record): string {
                    if (
                        $code === '' ||
                        !isset(
                            $record[$code]
                        ) ||
                        !is_array(
                            $record[$code]
                        )
                    ) {
                        return '';
                    }

                    $value =
                        $record[$code]['value']
                        ?? '';

                    if (is_scalar($value)) {
                        return (string)$value;
                    }

                    return '';
                };

                $addressValues = [];

                foreach (
                    ($mapping['address'] ?? [])
                    as $code
                ) {
                    $value =
                        $get((string)$code);

                    if ($value !== '') {
                        $addressValues[] =
                            $value;
                    }
                }

                $customers[] = [
                    'id' =>
                        uid('customer'),
                    'organization' =>
                        $get(
                            (string)(
                                $mapping['organization']
                                ?? ''
                            )
                        ),
                    'name' =>
                        $get(
                            (string)(
                                $mapping['name']
                                ?? ''
                            )
                        ),
                    'email' =>
                        $get(
                            (string)(
                                $mapping['email']
                                ?? ''
                            )
                        ),
                    'department' =>
                        $get(
                            (string)(
                                $mapping['department']
                                ?? ''
                            )
                        ),
                    'phone' =>
                        $get(
                            (string)(
                                $mapping['phone']
                                ?? ''
                            )
                        ),
                    'address' =>
                        implode(
                            ' ',
                            $addressValues
                        ),
                    'syncedAt' =>
                        now(),
                ];
            }

            $data['customers'] =
                $customers;

            $settings['kintone']
                ['last_sync'] =
                now();

            $settings['kintone']
                ['last_error'] =
                '';

            save_data(
                $data
            );

            save_settings(
                $settings
            );

            flash(
                'success',
                count($customers) .
                '件の顧客情報を同期しました。'
            );

            return [
                'screen' => 'customers',
            ];
        } catch (Throwable $e) {
            $message =
                safe_error($e);

            $settings['kintone']
                ['last_error'] =
                $message;

            save_settings(
                $settings
            );

            flash(
                'error',
                'kintone顧客同期失敗：' .
                $message
            );

            return [
                'screen' => 'kintone',
            ];
        }
    }

    /*
     * --------------------------------------------------------
     * SMTP設定保存
     * --------------------------------------------------------
     */
    if (
        $action ===
        'save_mail'
    ) {
        $old =
            $settings['mail']
            ?? default_settings()['mail'];

        $password =
            post_string('password');

        if ($password === '') {
            $password =
                (string)(
                    $old['password'] ?? ''
                );
        }

        /*
         * 新規入力なら暗号化。
         * 既存ENC:v1ならそのまま。
         */
        if (
            $password !== '' &&
            !is_encrypted_secret(
                $password
            )
        ) {
            $password =
                encrypt_secret(
                    $password
                );
        }

        $config = [
            'host' =>
                post_string('host'),
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
                $old['last_test'] ?? null,
            'last_error' => '',
        ];

        /*
         * パスワード以外の入力を検証。
         */
        $validation =
            $config;

        if (
            is_encrypted_secret(
                (string)$validation['password']
            )
        ) {
            $validation['password'] =
                'configured';
        }

        $errors =
            validate_mail(
                $validation
            );

        if ($errors !== []) {
            flash(
                'error',
                implode(
                    "\n",
                    $errors
                )
            );

            return [
                'screen' => 'mail',
            ];
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
     * ========================================================
     * ここから先の画面処理は、
     * 外部通信結果が確定してから行う。
     *
     * 回答送信についても、
     * 完了後は必ずscreen=complete。
     * 管理者一覧へ戻さない。
     * ========================================================
     */

    return null;
}

/*
 * ============================================================
 * アプリケーション起動
 * ============================================================
 */

try {
    start_session();

    $data =
        load_data();

    $settings =
        load_settings();

    /*
     * 公開中かつ終了日時経過だけを終了へ変更。
     */
    refresh_status(
        $data
    );

    /*
     * POST処理。
     *
     * ここで業務結果を確定してから
     * 画面を決定する。
     */
    $postResult =
        handle_post(
            $data,
            $settings
        );

    if ($postResult !== null) {
        /*
         * PRGを利用する場合でも、
         * 業務処理が確定した後にのみ303。
         */
        redirect_screen(
            $postResult['screen'],
            array_filter(
                [
                    'id' =>
                        $postResult['id'] ?? null,
                ],
                static fn($v) =>
                    $v !== null &&
                    $v !== ''
            )
        );
    }

    /*
     * ========================================================
     * 画面決定
     * ========================================================
     */

    $screen =
        get_string(
            'screen',
            'list'
        );

    /*
     * 回答者画面は管理者画面と分離する。
     */
    $answerScreens = [
        'answer',
        'confirm',
        'complete',
    ];

    if (
        in_array(
            $screen,
            $answerScreens,
            true
        )
    ) {
        /*
         * 回答者画面では管理者ナビゲーションを
         * 出力しない。
         *
         * 実際の画面HTMLは既存モックを維持して
         * この分岐へ接続する。
         */
    }

    /*
     * 対象アンケート固定画面。
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
    }

    /*
     * ========================================================
     * 重要:
     *
     * kintone/mailのGET画面表示では、
     * runtime_settings()を呼び出さない。
     *
     * 保存済みパスワードを復号してHTMLへ渡さない。
     *
     * ========================================================
     */

    if (
        $screen === 'kintone'
    ) {
        $viewSettings =
            $settings;

        /*
         * HTML表示用には秘密情報を完全に除去。
         */
        $viewSettings['kintone']
            ['password'] = '';

        $viewSettings['kintone']
            ['passwordConfigured'] =
            !empty(
                $settings['kintone']
                ['password']
            );

        /*
         * ここで既存の
         * render_kintone()
         * を呼び出す場合も、
         * $settingsではなく
         * $viewSettingsを渡す。
         */

        /*
         * render_kintone($viewSettings);
         */
    }

    if (
        $screen === 'mail'
    ) {
        $viewSettings =
            $settings;

        /*
         * パスワードをHTMLへ渡さない。
         */
        $viewSettings['mail']
            ['password'] = '';

        $viewSettings['mail']
            ['passwordConfigured'] =
            !empty(
                $settings['mail']
                ['password']
            );

        /*
         * render_mail($viewSettings);
         */
    }

    /*
     * ========================================================
     * 既存モックのレンダリング
     *
     * 現行版のrender_*関数をここへ接続する。
     *
     * ========================================================
     */

    switch ($screen) {
        case 'list':
            if (function_exists('render_list')) {
                render_list(
                    $data
                );
            }
            break;

        case 'kintone':
            if (function_exists('render_kintone')) {
                render_kintone(
                    $viewSettings
                );
            }
            break;

        case 'mail':
            if (function_exists('render_mail')) {
                render_mail(
                    $viewSettings
                );
            }
            break;

        case 'customers':
            if (function_exists('render_customers')) {
                render_customers(
                    $data
                );
            }
            break;

        case 'analytics':
            if (function_exists('render_analytics')) {
                render_analytics(
                    $data,
                    $survey
                );
            }
            break;

        case 'send':
            if (function_exists('render_send')) {
                render_send(
                    $data,
                    $survey,
                    $settings
                );
            }
            break;

        case 'edit':
            if (function_exists('render_edit')) {
                render_edit(
                    $data,
                    get_string('id')
                );
            }
            break;

        case 'preview':
            if (function_exists('render_preview')) {
                render_preview(
                    $data,
                    get_string('id')
                );
            }
            break;

        case 'answer':
            if (function_exists('render_answer')) {
                render_answer(
                    $data,
                    get_string('id')
                );
            }
            break;

        case 'confirm':
            if (function_exists('render_confirm')) {
                render_confirm(
                    $data,
                    get_string('id')
                );
            }
            break;

        case 'complete':
            if (function_exists('render_complete')) {
                render_complete(
                    $data,
                    get_string('id')
                );
            }
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
     * ========================================================
     * 最終エラーハンドリング
     * ========================================================
     *
     * 白画面にしない。
     * 内部スタックトレース等は表示しない。
     */

    $message =
        safe_error($e);

    http_response_code(500);

    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="utf-8">
        <meta name="viewport"
              content="width=device-width,initial-scale=1">
        <title><?= h(APP_TITLE) ?></title>
        <style>
            body {
                margin:0;
                padding:40px 20px;
                background:#f8fafc;
                color:#1e293b;
                font-family:
                    -apple-system,
                    BlinkMacSystemFont,
                    "Segoe UI",
                    "Noto Sans JP",
                    "Hiragino Kaku Gothic ProN",
                    Meiryo,
                    sans-serif;
            }

            .error {
                max-width:760px;
                margin:0 auto;
                background:#fff;
                border:1px solid #dbe2ea;
                border-radius:12px;
                padding:24px;
                box-shadow:
                    0 4px 18px
                    rgba(15,23,42,.08);
            }

            .error h1 {
                margin-top:0;
                color:#dc2626;
                font-size:22px;
            }

            .detail {
                margin-top:16px;
                padding:14px;
                background:#f8fafc;
                border-radius:8px;
                white-space:pre-wrap;
            }

            a {
                color:#2563eb;
            }
        </style>
    </head>
    <body>
        <main class="error">
            <h1>処理中にエラーが発生しました。</h1>

            <p>
                処理を完了できませんでした。
            </p>

            <div class="detail">
                <?= h($message) ?>
            </div>

            <p>
                <a href="<?= h(app_url([
                    'screen' => 'list'
                ])) ?>">
                    アンケート一覧へ戻る
                </a>
            </p>
        </main>
    </body>
    </html>
    <?php
}

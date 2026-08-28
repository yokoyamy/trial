<?php
/*
 * ============================================================
 * アンケートアプリ
 * 再生成版の実装方針
 * ============================================================
 *
 * 重要:
 *  - index.php 単一エントリーポイント
 *  - DBなし
 *  - PHP cURLなし
 *  - PHP mail()なし
 *  - 管理者認証なし（POC）
 *  - 外部サービス認証情報はサーバー側のみ
 *  - POST処理と画面遷移を分離
 *  - kintone / SMTP の通信結果を必ず画面へ返す
 *  - 302/303をアプリケーション処理結果として利用しない
 * ============================================================
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理システム';
const DATA_DIR_NAME = 'data';

$DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . DATA_DIR_NAME;

if (!is_dir($DATA_DIR)) {
    if (!mkdir($DATA_DIR, 0770, true) && !is_dir($DATA_DIR)) {
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
 * 共通
 * ============================================================ */

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
    if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) {
        return '';
    }

    return trim((string)$_POST[$key]);
}

function post_array(string $key): array
{
    $value = $_POST[$key] ?? [];

    if (!is_array($value)) {
        return [];
    }

    $result = [];

    foreach ($value as $item) {
        if (is_scalar($item)) {
            $item = trim((string)$item);

            if ($item !== '') {
                $result[] = $item;
            }
        }
    }

    return array_values(array_unique($result));
}

function app_url(array $params = []): string
{
    if (!$params) {
        return 'index.php';
    }

    return 'index.php?' . http_build_query($params);
}

/* ============================================================
 * JSON永続化
 * ============================================================ */

function data_file(string $name): string
{
    global $DATA_DIR;

    return $DATA_DIR . DIRECTORY_SEPARATOR . $name;
}

function json_read(string $file, mixed $default): mixed
{
    if (!is_file($file)) {
        return $default;
    }

    $raw = file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $value = json_decode($raw, true);

    return json_last_error() === JSON_ERROR_NONE
        ? $value
        : $default;
}

function json_write(string $file, mixed $data): bool
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));

    if (
        file_put_contents(
            $tmp,
            $json,
            LOCK_EX
        ) === false
    ) {
        @unlink($tmp);
        return false;
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/* ============================================================
 * 秘密情報
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
        $key = file_get_contents($file);

        if (is_string($key) && strlen($key) >= 32) {
            return hash('sha256', $key, true);
        }
    }

    $key = bin2hex(random_bytes(32));

    if (file_put_contents($file, $key, LOCK_EX) === false) {
        throw new RuntimeException(
            '暗号化キーを保存できません。'
        );
    }

    @chmod($file, 0600);

    return hash('sha256', $key, true);
}

function encrypt_secret(string $value): string
{
    if ($value === '') {
        return '';
    }

    $key = encryption_key();
    $iv = random_bytes(16);

    $cipher = openssl_encrypt(
        $value,
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

    return base64_encode(
        $iv . $mac . $cipher
    );
}

function decrypt_secret(string $value): string
{
    if ($value === '') {
        return '';
    }

    $raw = base64_decode($value, true);

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
 * kintone設定
 * ============================================================ */

function load_kintone(): array
{
    $data = json_read(
        data_file('kintone.json'),
        []
    );

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
    ], is_array($data) ? $data : []);
}

function save_kintone(array $config): bool
{
    return json_write(
        data_file('kintone.json'),
        $config
    );
}

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

function validate_kintone(array $config): array
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

    if (
        $proxy !== ''
        && parse_proxy($proxy) === null
    ) {
        $errors[] =
            'Proxyは「host:port」形式で入力してください。';
    }

    return $errors;
}

/*
 * kintone通信はこの関数だけに集約する。
 *
 * 重要:
 *  - API通信でHTTPリダイレクトを追従しない
 *  - 認証情報をURLへ入れない
 *  - HTTPステータスとレスポンス本文を両方取得
 *  - kintoneのエラーコード/エラーIDを返す
 */

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {

    $errors = validate_kintone($config);

    if ($errors) {
        return [
            'ok' => false,
            'status' => 0,
            'code' => '',
            'id' => '',
            'message' => implode(' ', $errors),
            'data' => null,
        ];
    }

    $password = '';

    if (
        !empty($config['password_encrypted'])
    ) {
        $password = decrypt_secret(
            (string)$config['password_encrypted']
        );
    }

    if (
        $password === ''
        && !empty($config['password'])
    ) {
        $password = (string)$config['password'];
    }

    if ($password === '') {
        return [
            'ok' => false,
            'status' => 0,
            'code' => '',
            'id' => '',
            'message' =>
                'kintoneパスワードが設定されていません。',
            'data' => null,
        ];
    }

    $host =
        normalize_subdomain(
            (string)$config['subdomain']
        )
        . '.cybozu.com';

    $url =
        'https://'
        . $host
        . $path;

    $headers = [
        'X-Cybozu-Authorization: '
            . base64_encode(
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
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            return [
                'ok' => false,
                'status' => 0,
                'code' => '',
                'id' => '',
                'message' =>
                    'JSONリクエストを生成できません。',
                'data' => null,
            ];
        }

        $headers[] =
            'Content-Type: application/json';

        $headers[] =
            'Content-Length: '
            . strlen($content);
    }

    $proxy = parse_proxy(
        (string)($config['proxy'] ?? '')
    );

    $http = [
        'method' => strtoupper($method),
        'header' => implode(
            "\r\n",
            $headers
        ),
        'timeout' => 20,
        'ignore_errors' => true,
        'protocol_version' => 1.1,

        /*
         * kintone APIの302/303を
         * アプリケーション側で追従しない。
         */
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

    $verifySsl =
        !empty($config['verify_ssl']);

    $context = stream_context_create([
        'http' => $http,
        'ssl' => [
            'verify_peer' => $verifySsl,
            'verify_peer_name' => $verifySsl,
            'allow_self_signed' => !$verifySsl,
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

    $raw = file_get_contents(
        $url,
        false,
        $context
    );

    restore_error_handler();

    $status = 0;

    foreach (
        $http_response_header ?? []
        as $header
    ) {
        if (
            preg_match(
                '/^HTTP\/[0-9.]+\s+(\d+)/',
                $header,
                $m
            )
        ) {
            $status = (int)$m[1];
            break;
        }
    }

    if ($raw === false) {
        return [
            'ok' => false,
            'status' => $status,
            'code' => '',
            'id' => '',
            'message' =>
                $error !== ''
                    ? $error
                    : 'kintoneとの通信に失敗しました。',
            'data' => null,
        ];
    }

    $data = json_decode(
        $raw,
        true
    );

    if (
        $status >= 200
        && $status < 300
    ) {
        return [
            'ok' => true,
            'status' => $status,
            'code' => '',
            'id' => '',
            'message' => '成功しました。',
            'data' =>
                is_array($data)
                    ? $data
                    : null,
        ];
    }

    /*
     * kintoneエラーを本文から取得。
     *
     * code:
     *   CB_VA01 等
     *
     * id:
     *   kintone側のエラーID
     */
    $code =
        is_array($data)
            ? (string)($data['code'] ?? '')
            : '';

    $id =
        is_array($data)
            ? (string)($data['id'] ?? '')
            : '';

    $message =
        is_array($data)
            ? (string)($data['message'] ?? '')
            : '';

    if ($message === '') {
        $message =
            'kintone APIがHTTP '
            . $status
            . 'を返しました。';
    }

    return [
        'ok' => false,
        'status' => $status,
        'code' => $code,
        'id' => $id,
        'message' => $message,
        'data' => $data,
    ];
}

/* ============================================================
 * kintone項目取得
 * ============================================================ */

function kintone_fetch_fields(
    array $config
): array {

    /*
     * 項目一覧取得はGET /k/v1/app/form/fields.json
     *
     * アプリIDはAPIパラメータへ入れる。
     * 認証情報はURLへ入れない。
     */
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app='
        . rawurlencode(
            (string)$config['app_id']
        )
    );
}

/* ============================================================
 * kintone接続テスト
 * ============================================================ */

function kintone_connection_test(
    array $config
): array {

    /*
     * 接続テストと項目取得を混同しない。
     *
     * 接続確認はアプリ情報取得など、
     * GET APIで認証・アプリ存在を確認する。
     */
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?id='
        . rawurlencode(
            (string)$config['app_id']
        )
    );
}

/* ============================================================
 * メール設定
 * ============================================================ */

function load_mail(): array
{
    $data = json_read(
        data_file('mail.json'),
        []
    );

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
    ], is_array($data) ? $data : []);
}

function save_mail(array $config): bool
{
    return json_write(
        data_file('mail.json'),
        $config
    );
}

function validate_mail(
    array $config
): array {

    $errors = [];

    if (
        trim((string)$config['server'])
        === ''
    ) {
        $errors[] =
            'SMTPサーバを入力してください。';
    }

    $port = (int)$config['port'];

    if (
        $port < 1
        || $port > 65535
    ) {
        $errors[] =
            'SMTPポートが不正です。';
    }

    if (
        !in_array(
            $config['encryption'],
            ['ssl', 'tls', 'none'],
            true
        )
    ) {
        $errors[] =
            '暗号化方式が不正です。';
    }

    if (
        trim((string)$config['from_email'])
        !== ''
        && !filter_var(
            $config['from_email'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            '送信元メールアドレスが不正です。';
    }

    if (
        trim((string)$config['reply_to'])
        !== ''
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

/* ============================================================
 * POST処理
 * ============================================================ */

function handle_post(
    array &$kintone,
    array &$mail
): string {

    $action = post_string('action');

    try {

        /* ====================================================
         * kintone設定保存
         * ==================================================== */

        if ($action === 'save_kintone') {

            $candidate = [
                'subdomain' =>
                    normalize_subdomain(
                        post_string('subdomain')
                    ),
                'app_id' =>
                    post_string('app_id'),
                'username' =>
                    post_string('username'),
                'proxy' =>
                    post_string('proxy'),
                'verify_ssl' =>
                    post_string('verify_ssl') === '1',
            ];

            $errors =
                validate_kintone($candidate);

            if ($errors) {
                $_SESSION['flash'] = [
                    'type' => 'error',
                    'message' =>
                        implode(' ', $errors),
                ];

                return 'kintone';
            }

            $password =
                post_string('password');

            $encrypted =
                (string)(
                    $kintone[
                        'password_encrypted'
                    ] ?? ''
                );

            if ($password !== '') {
                $encrypted =
                    encrypt_secret($password);
            }

            $kintone = array_merge(
                $kintone,
                $candidate,
                [
                    'password_encrypted' =>
                        $encrypted,
                ]
            );

            if (!save_kintone($kintone)) {
                throw new RuntimeException(
                    'kintone設定を保存できません。'
                );
            }

            $_SESSION['flash'] = [
                'type' => 'success',
                'message' =>
                    'kintone設定を保存しました。',
            ];

            return 'kintone';
        }

        /* ====================================================
         * kintone接続テスト
         * ==================================================== */

        if ($action === 'test_kintone') {

            /*
             * 保存済み設定ではなく、
             * 画面上の設定をそのままテストする。
             *
             * ただしパスワードそのものは画面へ返さない。
             */
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
                post_string('verify_ssl') === '1';

            $password =
                post_string('password');

            if ($password !== '') {
                $candidate[
                    'password_encrypted'
                ] = encrypt_secret($password);
            }

            $errors =
                validate_kintone($candidate);

            if ($errors) {
                $_SESSION[
                    'kintone_test_result'
                ] = [
                    'ok' => false,
                    'status' => 0,
                    'code' => '',
                    'id' => '',
                    'message' =>
                        implode(' ', $errors),
                ];

                return 'kintone';
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
                    date('Y-m-d H:i:s');

                save_kintone($kintone);
            } else {
                $kintone['status'] =
                    '接続できません';

                save_kintone($kintone);
            }

            return 'kintone';
        }

        /* ====================================================
         * kintone項目一覧
         * ==================================================== */

        if ($action === 'fetch_kintone_fields') {

            $result =
                kintone_fetch_fields(
                    $kintone
                );

            if (!$result['ok']) {
                $_SESSION[
                    'kintone_fields_result'
                ] = $result;

                return 'kintone';
            }

            $fields = [];

            foreach (
                ($result['data']['properties'] ?? [])
                as $code => $field
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

            usort(
                $fields,
                static function (
                    array $a,
                    array $b
                ): int {
                    return strcmp(
                        $a['label'],
                        $b['label']
                    );
                }
            );

            $kintone['fields'] =
                $fields;

            save_kintone($kintone);

            $_SESSION[
                'kintone_fields_result'
            ] = [
                'ok' => true,
                'status' =>
                    $result['status'],
                'code' => '',
                'id' => '',
                'message' =>
                    count($fields)
                    . '件の項目を取得しました。',
            ];

            return 'kintone';
        }

        /* ====================================================
         * kintoneマッピング保存
         * ==================================================== */

        if (
            $action
            === 'save_kintone_mapping'
        ) {

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

                /*
                 * 住所だけ複数選択。
                 */
                'address' =>
                    post_array(
                        'mapping_address'
                    ),
            ];

            save_kintone($kintone);

            $_SESSION['flash'] = [
                'type' => 'success',
                'message' =>
                    '項目マッピングを保存しました。',
            ];

            return 'kintone';
        }

        /* ====================================================
         * SMTP設定保存
         * ==================================================== */

        if ($action === 'save_mail') {

            $candidate = [
                'server' =>
                    post_string('server'),

                'port' =>
                    (int)post_string('port'),

                'encryption' =>
                    post_string('encryption'),

                /*
                 * selectの値を明示的に判定する。
                 *
                 * isset()では
                 * auth=0 もtrueになるため使用しない。
                 */
                'auth' =>
                    post_string('auth') === '1',

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
                validate_mail(
                    $candidate
                );

            if ($errors) {
                $_SESSION['flash'] = [
                    'type' => 'error',
                    'message' =>
                        implode(' ', $errors),
                ];

                return 'mail';
            }

            $password =
                post_string('password');

            $encrypted =
                (string)(
                    $mail[
                        'password_encrypted'
                    ] ?? ''
                );

            if ($password !== '') {
                $encrypted =
                    encrypt_secret($password);
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

            $_SESSION['flash'] = [
                'type' => 'success',
                'message' =>
                    'メール設定を保存しました。',
            ];

            return 'mail';
        }

        /* ====================================================
         * SMTP接続テスト
         * ==================================================== */

        if ($action === 'test_mail') {

            /*
             * 保存済み値だけでなく、
             * フォームの現在値を使用する。
             */
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

            /*
             * auth=0を正しくfalseにする。
             */
            $candidate['auth'] =
                post_string('auth') === '1';

            $password =
                post_string('password');

            if ($password !== '') {
                $candidate[
                    'password_encrypted'
                ] = encrypt_secret($password);
            }

            $errors =
                validate_mail($candidate);

            if ($errors) {
                $_SESSION[
                    'mail_test_result'
                ] = [
                    'ok' => false,
                    'message' =>
                        implode(' ', $errors),
                ];

                return 'mail';
            }

            /*
             * smtp_test() は実際のSMTPへ接続する。
             *
             * 結果を必ずセッションへ保存し、
             * 同一リクエストの画面で表示する。
             */
            $result =
                smtp_test(
                    $candidate
                );

            $_SESSION[
                'mail_test_result'
            ] = $result;

            if ($result['ok']) {
                $mail['status'] =
                    '接続確認済み';

                $mail['last_test'] =
                    date('Y-m-d H:i:s');
            } else {
                $mail['status'] =
                    '接続できません';
            }

            save_mail($mail);

            return 'mail';
        }

        throw new InvalidArgumentException(
            '不明な操作です。'
        );

    } catch (Throwable $e) {

        /*
         * 白画面・無反応を禁止。
         *
         * 内部例外そのものは表示せず、
         * 利用者が次の対処をできるメッセージにする。
         */
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' =>
                '処理に失敗しました。'
                . '入力値、設定値、通信状態を確認してください。',
        ];

        return match ($action) {
            'save_kintone',
            'test_kintone',
            'fetch_kintone_fields',
            'save_kintone_mapping'
                => 'kintone',

            'save_mail',
            'test_mail'
                => 'mail',

            default
                => 'list',
        };
    }
}

/* ============================================================
 * kintone画面
 *
 * 重要:
 * 住所マッピングでは
 *
 *   <label>
 *      <label>
 *   </label>
 *
 * という入れ子を絶対に作らない。
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

    render_head(
        'kintone連携設定'
    );

    render_flash();

    if (is_array($test)) {
        $class =
            !empty($test['ok'])
                ? 'alert-success'
                : 'alert-error';

        echo '<div class="alert '
            . h($class)
            . '">';

        echo h(
            $test['message']
            ?? ''
        );

        if (
            !empty($test['status'])
        ) {
            echo '<br>HTTP '
                . h($test['status']);
        }

        if (
            !empty($test['code'])
        ) {
            echo '<br>エラーコード: '
                . h($test['code']);
        }

        if (
            !empty($test['id'])
        ) {
            echo '<br>エラーID: '
                . h($test['id']);
        }

        echo '</div>';
    }

    if (is_array($fieldsResult)) {
        echo '<div class="alert '
            . (
                !empty($fieldsResult['ok'])
                    ? 'alert-success'
                    : 'alert-error'
            )
            . '">';

        echo h(
            $fieldsResult['message']
            ?? ''
        );

        echo '</div>';
    }

    /*
     * 設定保存
     */
    ?>
    <div class="card">
        <div class="card-body">

            <form method="post"
                  data-loading>

                <input type="hidden"
                       name="action"
                       value="save_kintone">

                <!--
                 * ここにサブドメイン、
                 * アプリID、ログイン名、
                 * パスワード、Proxy、
                 * SSL設定を配置。
                 -->

                <button
                    class="btn btn-primary"
                    type="submit">
                    設定保存
                </button>

            </form>

            <hr>

            <!--
             * 接続テストは設定保存とは別フォーム。
             * API通信結果を同じ画面へ返す。
             -->

            <form method="post"
                  data-loading>

                <input type="hidden"
                       name="action"
                       value="test_kintone">

                <!--
                 * 保存済み設定を使用。
                 * パスワードはブラウザへ再表示しない。
                 -->

                <button
                    class="btn btn-secondary"
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

                <button
                    class="btn btn-secondary"
                    type="submit">
                    項目一覧を再取得
                </button>

            </form>

            <?php if (!empty($config['fields'])): ?>

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
                        $mappingLabels
                        as $key => $label
                    ): ?>

                    <div class="form-group">

                        <label
                            for="mapping_<?= h($key) ?>">
                            <span>
                                <?= h($label) ?>
                            </span>
                        </label>

                        <select
                            id="mapping_<?= h($key) ?>"
                            name="mapping_<?= h($key) ?>">

                            <option value="">
                                未設定
                            </option>

                            <?php foreach (
                                $config['fields']
                                as $field
                            ): ?>

                            <option
                                value="<?= h(
                                    $field['code']
                                ) ?>"
                                <?= (
                                    (
                                        $config[
                                            'mapping'
                                        ][$key] ?? ''
                                    )
                                    ===
                                    $field['code']
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

                    </div>

                    <?php endforeach; ?>

                </div>

                <!--
                 * 住所だけ複数選択。
                 *
                 * 外側labelの中にlabelを入れない。
                 * 各チェックボックスを独立したlabelにする。
                 -->

                <fieldset
                    class="form-group">

                    <legend>
                        住所
                    </legend>

                    <?php foreach (
                        $config['fields']
                        as $field
                    ): ?>

                    <label
                        class="mapping-checkbox">

                        <input
                            type="checkbox"
                            name="mapping_address[]"
                            value="<?= h(
                                $field['code']
                            ) ?>"
                            <?= in_array(
                                $field['code'],
                                $config[
                                    'mapping'
                                ]['address'] ?? [],
                                true
                            )
                                ? 'checked'
                                : '' ?>>

                        <span>
                            <?= h(
                                $field['label']
                                . ' ('
                                . $field['code']
                                . ')'
                            ) ?>
                        </span>

                    </label>

                    <?php endforeach; ?>

                </fieldset>

                <button
                    class="btn btn-primary"
                    type="submit">
                    マッピングを保存
                </button>

            </form>

            <?php endif; ?>

        </div>
    </div>

    <?php

    render_footer();
}

/* ============================================================
 * SMTP画面
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

    render_head(
        'メールサーバ設定'
    );

    render_flash();

    if (is_array($test)) {

        echo '<div class="alert '
            . (
                !empty($test['ok'])
                    ? 'alert-success'
                    : 'alert-error'
            )
            . '">';

        echo h(
            $test['message']
            ?? ''
        );

        echo '</div>';
    }

    ?>

    <div class="card">

        <div class="card-header">
            <h2>SMTPサーバ設定</h2>
        </div>

        <div class="card-body">

            <!--
             * 設定保存専用フォーム
             -->
            <form method="post"
                  data-loading>

                <input type="hidden"
                       name="action"
                       value="save_mail">

                <div class="grid grid-2">

                    <div class="form-group">

                        <label
                            for="smtp_server">
                            <span>
                                SMTPサーバ
                            </span>
                        </label>

                        <input
                            id="smtp_server"
                            type="text"
                            name="server"
                            value="<?= h(
                                $config['server']
                            ) ?>"
                            required>

                    </div>

                    <div class="form-group">

                        <label
                            for="smtp_port">
                            <span>
                                SMTPポート
                            </span>
                        </label>

                        <input
                            id="smtp_port"
                            type="number"
                            name="port"
                            min="1"
                            max="65535"
                            value="<?= h(
                                $config['port']
                            ) ?>"
                            required>

                    </div>

                    <div class="form-group">

                        <label
                            for="smtp_encryption">
                            <span>
                                暗号化方式
                            </span>
                        </label>

                        <select
                            id="smtp_encryption"
                            name="encryption">

                            <option
                                value="ssl"
                                <?= $config[
                                    'encryption'
                                ] === 'ssl'
                                    ? 'selected'
                                    : '' ?>>
                                SSL
                            </option>

                            <option
                                value="tls"
                                <?= $config[
                                    'encryption'
                                ] === 'tls'
                                    ? 'selected'
                                    : '' ?>>
                                TLS
                            </option>

                            <option
                                value="none"
                                <?= $config[
                                    'encryption'
                                ] === 'none'
                                    ? 'selected'
                                    : '' ?>>
                                なし
                            </option>

                        </select>

                    </div>

                    <div class="form-group">

                        <label
                            for="smtp_auth">
                            <span>
                                SMTP認証
                            </span>
                        </label>

                        <select
                            id="smtp_auth"
                            name="auth">

                            <option
                                value="1"
                                <?= !empty(
                                    $config['auth']
                                )
                                    ? 'selected'
                                    : '' ?>>
                                あり
                            </option>

                            <option
                                value="0"
                                <?= empty(
                                    $config['auth']
                                )
                                    ? 'selected'
                                    : '' ?>>
                                なし
                            </option>

                        </select>

                    </div>

                    <div class="form-group">

                        <label
                            for="smtp_username">
                            <span>
                                SMTPユーザー名
                            </span>
                        </label>

                        <input
                            id="smtp_username"
                            type="text"
                            name="username"
                            value="<?= h(
                                $config['username']
                            ) ?>">

                    </div>

                    <div class="form-group">

                        <label
                            for="smtp_password">
                            <span>
                                SMTPパスワード
                            </span>
                        </label>

                        <input
                            id="smtp_password"
                            type="password"
                            name="password"
                            autocomplete="new-password">

                        <div class="help">
                            変更しない場合は空欄。
                        </div>

                    </div>

                    <div class="form-group">

                        <label
                            for="from_email">
                            <span>
                                送信元メールアドレス
                            </span>
                        </label>

                        <input
                            id="from_email"
                            type="email"
                            name="from_email"
                            value="<?= h(
                                $config['from_email']
                            ) ?>"
                            required>

                    </div>

                    <div class="form-group">

                        <label
                            for="from_name">
                            <span>
                                送信元名
                            </span>
                        </label>

                        <input
                            id="from_name"
                            type="text"
                            name="from_name"
                            value="<?= h(
                                $config['from_name']
                            ) ?>">

                    </div>

                    <div class="form-group">

                        <label
                            for="reply_to">
                            <span>
                                返信先メールアドレス
                            </span>
                        </label>

                        <input
                            id="reply_to"
                            type="email"
                            name="reply_to"
                            value="<?= h(
                                $config['reply_to']
                            ) ?>">

                    </div>

                </div>

                <button
                    class="btn btn-primary"
                    type="submit">
                    設定保存
                </button>

            </form>

            <hr>

            <!--
             * 接続テスト専用フォーム
             *
             * auth=0をhiddenにする場合も
             * PHP側では値を比較してboolean化する。
             -->

            <form method="post"
                  data-loading>

                <input type="hidden"
                       name="action"
                       value="test_mail">

                <input type="hidden"
                       name="server"
                       value="<?= h(
                           $config['server']
                       ) ?>">

                <input type="hidden"
                       name="port"
                       value="<?= h(
                           $config['port']
                       ) ?>">

                <input type="hidden"
                       name="encryption"
                       value="<?= h(
                           $config['encryption']
                       ) ?>">

                <input type="hidden"
                       name="auth"
                       value="<?= !empty(
                           $config['auth']
                       )
                           ? '1'
                           : '0' ?>">

                <input type="hidden"
                       name="username"
                       value="<?= h(
                           $config['username']
                       ) ?>">

                <input type="hidden"
                       name="from_email"
                       value="<?= h(
                           $config['from_email']
                       ) ?>">

                <input type="hidden"
                       name="from_name"
                       value="<?= h(
                           $config['from_name']
                       ) ?>">

                <input type="hidden"
                       name="reply_to"
                       value="<?= h(
                           $config['reply_to']
                       ) ?>">

                <div class="form-group">

                    <label
                        for="test_password">
                        <span>
                            接続テスト用パスワード
                        </span>
                    </label>

                    <input
                        id="test_password"
                        type="password"
                        name="password"
                        autocomplete="new-password"
                        placeholder="保存済みの場合は空欄でも可">

                </div>

                <button
                    class="btn btn-secondary"
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

            <span class="badge">
                <?= h(
                    $config['status']
                ) ?>
            </span>

            <?php if (
                !empty(
                    $config['last_test']
                )
            ): ?>

            <p class="help">
                最終確認：
                <?= h(
                    $config['last_test']
                ) ?>
            </p>

            <?php endif; ?>

        </div>

    </div>

    <?php

    render_footer();
}

/* ============================================================
 * CSS
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

.admin-header{
    background:#0f172a;
    color:#fff;
}

.admin-header-inner{
    width:min(1200px,calc(100% - 32px));
    margin:auto;
    min-height:60px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.brand{
    font-weight:700;
}

.nav{
    display:flex;
    gap:18px;
}

.nav a{
    color:#cbd5e1;
    text-decoration:none;
}

.nav a:hover{
    color:#fff;
}

.container{
    width:min(1200px,calc(100% - 32px));
    margin:auto;
}

.page{
    padding:28px 0 50px;
}

.page-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    margin-bottom:20px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    margin-bottom:20px;
}

.card-header{
    padding:18px 20px;
    border-bottom:1px solid var(--border);
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

.form-group{
    margin-bottom:16px;
}

.form-group label,
.form-group legend{
    display:block;
    font-weight:600;
    margin-bottom:7px;
}

input,
select,
textarea{
    width:100%;
    min-height:42px;
    border:1px solid var(--border);
    border-radius:8px;
    padding:9px 11px;
    background:#fff;
    color:var(--text);
}

button,
.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:8px 15px;
    border:0;
    border-radius:8px;
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

.btn-secondary{
    background:#e2e8f0;
    color:var(--text);
}

.alert{
    padding:13px 15px;
    border-radius:8px;
    margin-bottom:18px;
}

.alert-success{
    background:#dcfce7;
    color:#166534;
}

.alert-error{
    background:#fee2e2;
    color:#991b1b;
}

.help{
    color:var(--gray);
    font-size:13px;
    margin-top:6px;
}

.badge{
    display:inline-flex;
    padding:5px 10px;
    border-radius:999px;
    background:#e2e8f0;
    color:#475569;
}

.mapping-checkbox{
    display:flex;
    align-items:center;
    gap:9px;
    margin:8px 0;
    font-weight:400;
    cursor:pointer;
}

.mapping-checkbox input{
    width:18px;
    min-height:18px;
    flex:0 0 auto;
}

fieldset{
    border:0;
    padding:0;
    margin:0 0 20px;
}

legend{
    padding:0;
}

hr{
    border:0;
    border-top:1px solid var(--border);
    margin:22px 0;
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
    to{
        transform:rotate(360deg);
    }
}

@media(max-width:900px){
    .grid-2{
        grid-template-columns:1fr;
    }

    .admin-header-inner{
        flex-direction:column;
        align-items:flex-start;
        padding:12px 0;
    }

    .nav{
        width:100%;
        flex-wrap:wrap;
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
        align-items:flex-start;
    }

    .btn,
    button{
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
<a href="<?= h(
    app_url(['screen'=>'list'])
) ?>">
アンケート一覧
</a>

<a href="<?= h(
    app_url(['screen'=>'kintone'])
) ?>">
kintone設定
</a>

<a href="<?= h(
    app_url(['screen'=>'mail'])
) ?>">
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

/* ============================================================
 * Flash
 * ============================================================ */

function render_flash(): void
{
    $flash =
        $_SESSION['flash']
        ?? null;

    unset(
        $_SESSION['flash']
    );

    if (!is_array($flash)) {
        return;
    }

    $class =
        ($flash['type'] ?? '')
        === 'success'
            ? 'alert-success'
            : 'alert-error';

    ?>
    <div class="alert <?= h($class) ?>">
        <?= h(
            $flash['message']
            ?? ''
        ) ?>
    </div>
    <?php
}

/* ============================================================
 * Footer
 * ============================================================ */

function render_footer(): void
{
?>
</div>
</div>

<div
    class="loading-overlay"
    id="loadingOverlay">

    <div class="loading-box">

        <div class="spinner"></div>

        <div>
            処理中です。しばらくお待ちください。
        </div>

    </div>

</div>

<script>
/*
 * submitイベントは送信をキャンセルしない。
 *
 * overlay表示後も通常のPOSTを許可する。
 * これにより「ボタンを押したが何も起きない」
 * という状態を作らない。
 */
document
    .querySelectorAll('form[data-loading]')
    .forEach(function(form){

        form.addEventListener(
            'submit',
            function(){

                var overlay =
                    document.getElementById(
                        'loadingOverlay'
                    );

                if (overlay) {
                    overlay.style.display =
                        'flex';
                }

                form
                    .querySelectorAll(
                        'button[type="submit"]'
                    )
                    .forEach(function(button){

                        button.disabled = true;

                    });
            }
        );

    });
</script>

</body>
</html>
<?php
}

/* ============================================================
 * メイン
 * ============================================================ */

$kintone =
    load_kintone();

$mail =
    load_mail();

$screen =
    isset($_GET['screen'])
    && is_scalar($_GET['screen'])
        ? trim((string)$_GET['screen'])
        : 'list';

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET')
    === 'POST'
) {

    /*
     * POST処理後に302/303へ飛ばさない。
     *
     * 処理結果をセッションへ格納し、
     * 同じリクエスト内で対象画面を描画する。
     */
    $screen =
        handle_post(
            $kintone,
            $mail
        );
}

/* ============================================================
 * 画面ルーティング
 * ============================================================ */

switch ($screen) {

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

        /*
         * ここに既存要件の
         * アンケート一覧・編集・
         * プレビュー・送信・集計・
         * 回答者画面を同じルーティング方式で実装。
         */

        render_head(
            'アンケート一覧'
        );

        render_flash();

        ?>
        <div class="page-title">
            <div>
                <h1>
                    アンケート一覧
                </h1>
            </div>

            <a
                class="btn btn-primary"
                href="<?= h(
                    app_url([
                        'screen'=>'edit'
                    ])
                ) ?>">
                ＋ 新規作成
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                アンケートがありません。
            </div>
        </div>

        <?php

        render_footer();

        break;
}
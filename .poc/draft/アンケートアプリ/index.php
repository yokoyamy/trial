<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし / PHP mail()なし
 *
 * 単一エントリーポイント:
 *   index.php?screen=list
 *   index.php?screen=edit&id=survey-001
 *   index.php?screen=preview&id=survey-001
 *   index.php?screen=send&id=survey-001
 *   index.php?screen=analytics&id=survey-001
 *   index.php?screen=kintone
 *   index.php?screen=mail
 *   index.php?screen=answer&id=survey-001
 *   index.php?screen=confirm&id=survey-001
 *   index.php?screen=complete&id=survey-001
 *
 * kintone:
 *   パスワード認証
 *   X-Cybozu-Authorization
 *
 * 重要:
 *   - kintone API仕様をAPI単位で固定
 *   - app.json は id
 *   - records.json は app
 *   - form/fields.json は app
 *   - API通信と画面リダイレクトを分離
 *   - APIエラー本文を必ず取得
 *   - APIリダイレクトは追従しない
 *   - 認証情報をHTML/URL/ログへ出さない
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_READ_TIMEOUT = 30;
const SESSION_NAME = 'survey_app_session';

/* ============================================================
 * 初期化
 * ============================================================ */

if (!is_dir(DATA_DIR)) {
    if (!@mkdir(DATA_DIR, 0770, true) && !is_dir(DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

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

    foreach ($value as $item) {
        if (is_scalar($item)) {
            $result[] = trim((string)$item);
        }
    }

    return array_values(array_unique($result));
}

function post_bool(string $key): bool
{
    return isset($_POST[$key])
        && in_array(
            $_POST[$key],
            ['1', 'on', 'true'],
            true
        );
}

function safe_id(string $id): bool
{
    return (bool)preg_match(
        '/^[A-Za-z0-9_-]{1,100}$/',
        $id
    );
}

function new_id(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

/* ============================================================
 * セッション
 * ============================================================ */

if (session_status() !== PHP_SESSION_ACTIVE) {

    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

    $path = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    $path = rtrim(str_replace('\\', '/', $path), '/');

    if ($path === '') {
        $path = '/';
    }

    session_name(SESSION_NAME);

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
 * Flash
 * ============================================================ */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function take_flash(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($items) ? $items : [];
}

/* ============================================================
 * URL
 * ============================================================ */

function app_url(array $params = []): string
{
    $base = $_SERVER['SCRIPT_NAME'] ?? 'index.php';

    if (!$params) {
        return $base;
    }

    return $base . '?' . http_build_query(
        $params,
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}

function redirect_screen(
    string $screen,
    array $params = []
): never {
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

/* ============================================================
 * JSON保存
 * ============================================================ */

function save_json(string $file, array $data): void
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException(
            'JSONを生成できません。'
        );
    }

    $tmp = $file
        . '.tmp.'
        . bin2hex(random_bytes(6));

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

        if (fwrite($fp, $json) === false) {
            throw new RuntimeException(
                'データを書き込めません。'
            );
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            throw new RuntimeException(
                '保存ファイルを更新できません。'
            );
        }

    } catch (Throwable $e) {

        @flock($fp, LOCK_UN);
        @fclose($fp);
        @unlink($tmp);

        throw $e;
    }
}

function load_json(string $file, array $fallback): array
{
    if (!is_file($file)) {
        return $fallback;
    }

    $fp = @fopen($file, 'rb');

    if ($fp === false) {
        return $fallback;
    }

    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return $fallback;
    }

    $contents = stream_get_contents($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    if ($contents === false || trim($contents) === '') {
        return $fallback;
    }

    $data = json_decode($contents, true);

    return is_array($data)
        ? $data
        : $fallback;
}

/* ============================================================
 * 初期データ
 * ============================================================ */

function default_data(): array
{
    return [
        'surveys' => [
            [
                'id' => 'survey-001',
                'title' => '顧客満足度アンケート',
                'description' =>
                    'サービスについてのご意見をお聞かせください。',
                'startAt' => now_input(),
                'endAt' => date(
                    'Y-m-d\TH:i',
                    strtotime('+30 days')
                ),
                'status' => 'published',
                'numbering' => 'global',
                'createdAt' => now(),
                'updatedAt' => now(),
                'groups' => [
                    [
                        'id' => 'group-001',
                        'title' => '基本アンケート',
                        'questions' => [
                            [
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
                            ],
                            [
                                'id' => 'question-002',
                                'number' => 'Q2',
                                'text' =>
                                    'ご意見・ご要望があれば入力してください。',
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
            'password_encrypted' => '',
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
            'server' => '',
            'port' => '587',
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'password_encrypted' => '',
            'from_email' => '',
            'from_name' => '',
            'reply_to' => '',
            'last_test' => null,
        ],
    ];
}

function load_data(): array
{
    $data = load_json(
        DATA_FILE,
        default_data()
    );

    foreach ([
        'surveys',
        'answers',
        'customers',
        'send_history',
    ] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }

    return $data;
}

function load_settings(): array
{
    $settings = load_json(
        SETTINGS_FILE,
        default_settings()
    );

    $defaults = default_settings();

    $settings['kintone'] = array_replace_recursive(
        $defaults['kintone'],
        $settings['kintone'] ?? []
    );

    $settings['mail'] = array_replace_recursive(
        $defaults['mail'],
        $settings['mail'] ?? []
    );

    return $settings;
}

/* ============================================================
 * 秘密情報
 * ============================================================ */

function secret_key(): string
{
    $keyFile = DATA_DIR
        . DIRECTORY_SEPARATOR
        . '.secret';

    if (is_file($keyFile)) {
        $key = trim((string)@file_get_contents($keyFile));

        if ($key !== '') {
            return $key;
        }
    }

    $key = base64_encode(
        random_bytes(32)
    );

    @file_put_contents(
        $keyFile,
        $key,
        LOCK_EX
    );

    @chmod($keyFile, 0600);

    return $key;
}

function encrypt_secret(string $value): string
{
    if ($value === '') {
        return '';
    }

    $key = hash(
        'sha256',
        secret_key(),
        true
    );

    $iv = random_bytes(
        openssl_cipher_iv_length('aes-256-cbc')
    );

    $encrypted = openssl_encrypt(
        $value,
        'aes-256-cbc',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($encrypted === false) {
        throw new RuntimeException(
            '秘密情報を保存できません。'
        );
    }

    return base64_encode(
        $iv . $encrypted
    );
}

function decrypt_secret(string $value): string
{
    if ($value === '') {
        return '';
    }

    $raw = base64_decode(
        $value,
        true
    );

    if ($raw === false) {
        return '';
    }

    $key = hash(
        'sha256',
        secret_key(),
        true
    );

    $ivLength =
        openssl_cipher_iv_length(
            'aes-256-cbc'
        );

    if (strlen($raw) <= $ivLength) {
        return '';
    }

    $iv = substr(
        $raw,
        0,
        $ivLength
    );

    $encrypted = substr(
        $raw,
        $ivLength
    );

    $plain = openssl_decrypt(
        $encrypted,
        'aes-256-cbc',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    return $plain === false
        ? ''
        : $plain;
}

/* ============================================================
 * kintone 設定
 * ============================================================ */

function normalize_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    $value = trim(
        (string)$value,
        '/'
    );

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

    $subdomain =
        normalize_subdomain(
            (string)($config['subdomain'] ?? '')
        );

    $appId =
        trim((string)($config['app_id'] ?? ''));

    $username =
        trim((string)($config['username'] ?? ''));

    $proxy =
        trim((string)($config['proxy'] ?? ''));

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

    return [
        'subdomain' => $subdomain,
        'app_id' => $appId,
        'username' => $username,
        'proxy' => $proxy,
        'errors' => $errors,
    ];
}

/* ============================================================
 * kintone API定義
 *
 * APIごとにパラメータ名を固定する。
 *
 * app.json:
 *   id
 *
 * form/fields.json:
 *   app
 *
 * records.json:
 *   app
 *
 * この関係を一箇所に集約し、
 * 呼び出し側で自由にパラメータを組み立てない。
 * ============================================================ */

function kintone_endpoint_app_info(
    string $appId
): string {
    return '/k/v1/app.json?'
        . http_build_query(
            ['id' => $appId],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
}

function kintone_endpoint_fields(
    string $appId
): string {
    return '/k/v1/app/form/fields.json?'
        . http_build_query(
            ['app' => $appId],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
}

function kintone_endpoint_records(
    string $appId
): string {
    return '/k/v1/records.json?'
        . http_build_query(
            [
                'app' => $appId,
                'totalCount' => 'true',
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
}

/* ============================================================
 * kintone API共通通信
 * ============================================================ */

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {

    $validation =
        validate_kintone_config($config);

    if ($validation['errors']) {
        return [
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
            'data' => null,
        ];
    }

    $password = '';

    if (!empty($config['password_encrypted'])) {
        $password = decrypt_secret(
            (string)$config['password_encrypted']
        );
    }

    if (
        $password === ''
        && !empty($config['password'])
    ) {
        $password =
            (string)$config['password'];
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

    if (!preg_match(
        '/^[A-Za-z0-9-]+\.cybozu\.com$/',
        $host
    )) {
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

    $url =
        'https://'
        . $host
        . $path;

    /*
     * 認証情報はサーバー側だけで生成。
     * ブラウザへ返さない。
     */
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

        $headers[] =
            'Content-Type: application/json';

        $headers[] =
            'Content-Length: '
            . strlen($content);
    }

    $proxy =
        parse_proxy(
            (string)($config['proxy'] ?? '')
        );

    $http = [
        'method' =>
            strtoupper($method),
        'header' =>
            implode("\r\n", $headers),
        'timeout' =>
            KINTONE_READ_TIMEOUT,
        'ignore_errors' => true,
        'protocol_version' => 1.1,

        /*
         * kintone APIからの301/302/303等を
         * PHP側で勝手に追従しない。
         *
         * リダイレクトはAPI通信エラーとして
         * 呼び出し側へ返す。
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

    $context =
        stream_context_create([
            'http' => $http,
            'ssl' => [
                'verify_peer' =>
                    $verifySsl,
                'verify_peer_name' =>
                    $verifySsl,
                'allow_self_signed' =>
                    !$verifySsl,
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

    $response = file_get_contents(
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

    $decoded =
        json_decode(
            $response,
            true
        );

    /*
     * 200系かつJSONが正常なら成功。
     */
    if (
        $status >= 200
        && $status < 300
    ) {
        return [
            'ok' => true,
            'category' => '成功',
            'status' => $status,
            'code' => '',
            'id' => '',
            'message' =>
                'kintone接続に成功しました。',
            'data' =>
                is_array($decoded)
                    ? $decoded
                    : $response,
        ];
    }

    /*
     * kintoneが返したエラー情報を保持。
     * CB_VA01等を捨てない。
     */
    $code = '';
    $id = '';
    $message =
        'kintone APIでエラーが発生しました。';

    if (is_array($decoded)) {

        $code =
            (string)(
                $decoded['code'] ?? ''
            );

        $id =
            (string)(
                $decoded['id'] ?? ''
            );

        if (
            isset($decoded['message'])
            && is_scalar(
                $decoded['message']
            )
        ) {
            $message =
                (string)$decoded['message'];
        }
    }

    $category = match (true) {

        $status === 400 =>
            '入力・APIリクエストエラー',

        $status === 401 ||
        $status === 403 =>
            '認証エラー',

        $status === 404 =>
            '設定エラー',

        $status === 408 =>
            'タイムアウト',

        $status === 429 =>
            '外部サービスエラー',

        $status >= 500 =>
            '外部サービスエラー',

        $status >= 300 &&
        $status < 400 =>
            'リダイレクトエラー',

        default =>
            '通信エラー',
    };

    return [
        'ok' => false,
        'category' => $category,
        'status' => $status,
        'code' => $code,
        'id' => $id,
        'message' => $message,
        'data' =>
            is_array($decoded)
                ? $decoded
                : null,
    ];
}

/* ============================================================
 * kintone API操作
 * ============================================================ */

function kintone_connection_test(
    array $config
): array {

    /*
     * 重要:
     *
     * /k/v1/app.json は
     * app ではなく id を使用する。
     *
     * 以前:
     *   /k/v1/app.json?app=123
     *
     * 正:
     *   /k/v1/app.json?id=123
     */
    return kintone_request(
        $config,
        'GET',
        kintone_endpoint_app_info(
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
        kintone_endpoint_fields(
            (string)$config['app_id']
        )
    );
}

function kintone_fetch_records(
    array $config
): array {

    return kintone_request(
        $config,
        'GET',
        kintone_endpoint_records(
            (string)$config['app_id']
        )
    );
}

/* ============================================================
 * kintone フィールド変換
 * ============================================================ */

function kintone_field_value(
    array $record,
    string $fieldCode
): string {

    if (
        !isset($record[$fieldCode])
        || !is_array($record[$fieldCode])
    ) {
        return '';
    }

    $value =
        $record[$fieldCode]['value']
        ?? '';

    if (is_array($value)) {

        $values = [];

        foreach ($value as $item) {

            if (
                is_array($item)
                && isset($item['name'])
            ) {
                $values[] =
                    (string)$item['name'];
            } elseif (
                is_scalar($item)
            ) {
                $values[] =
                    (string)$item;
            }
        }

        return implode(
            '、',
            $values
        );
    }

    return is_scalar($value)
        ? (string)$value
        : '';
}

function kintone_fields_to_mapping(
    array $fields
): array {

    $result = [];

    foreach ($fields as $code => $field) {

        if (!is_array($field)) {
            continue;
        }

        $label =
            (string)(
                $field['label']
                ?? $code
            );

        $result[$code] = [
            'code' => $code,
            'label' => $label,
            'type' =>
                (string)(
                    $field['type']
                    ?? ''
                ),
        ];
    }

    return $result;
}

function sync_kintone_customers(
    array $config,
    array $settings,
    array &$data
): array {

    $result =
        kintone_fetch_records(
            $config
        );

    if (!$result['ok']) {
        return $result;
    }

    $records =
        $result['data']['records']
        ?? [];

    if (!is_array($records)) {
        $records = [];
    }

    $mapping =
        $settings['mapping']
        ?? [];

    $customers = [];

    foreach ($records as $record) {

        if (!is_array($record)) {
            continue;
        }

        $getMapped =
            static function (
                array $record,
                string $key
            ) use ($mapping): string {

                $field =
                    (string)(
                        $mapping[$key]
                        ?? ''
                    );

                if ($field === '') {
                    return '';
                }

                return kintone_field_value(
                    $record,
                    $field
                );
            };

        $customers[] = [
            'id' =>
                'customer-'
                . (count($customers) + 1),

            'organization' =>
                $getMapped(
                    $record,
                    'organization'
                ),

            'name' =>
                $getMapped(
                    $record,
                    'name'
                ),

            'email' =>
                $getMapped(
                    $record,
                    'email'
                ),

            'department' =>
                $getMapped(
                    $record,
                    'department'
                ),

            'phone' =>
                $getMapped(
                    $record,
                    'phone'
                ),

            'address' =>
                $getMapped(
                    $record,
                    'address'
                ),

            'updatedAt' => now(),
        ];
    }

    $data['customers'] =
        $customers;

    return [
        'ok' => true,
        'category' => '成功',
        'status' =>
            (int)$result['status'],
        'code' => '',
        'id' => '',
        'message' =>
            count($customers)
            . '件の顧客情報を同期しました。',
        'data' => null,
    ];
}

/* ============================================================
 * アンケート
 * ============================================================ */

function find_survey(
    array &$data,
    string $id
): ?array {

    foreach (
        $data['surveys']
        as &$survey
    ) {
        if (
            isset($survey['id'])
            && $survey['id'] === $id
        ) {

            auto_end_survey($survey);

            return $survey;
        }
    }

    return null;
}

function auto_end_survey(
    array &$survey
): void {

    if (
        ($survey['status'] ?? '')
        !== 'published'
    ) {
        return;
    }

    $endAt =
        (string)(
            $survey['endAt']
            ?? ''
        );

    if ($endAt === '') {
        return;
    }

    $time =
        strtotime($endAt);

    if (
        $time !== false
        && $time < time()
    ) {
        $survey['status'] = 'ended';
        $survey['updatedAt'] = now();
    }
}

function recalc_question_numbers(
    array &$survey
): void {

    $global = 1;
    $groupNo = 1;

    foreach (
        $survey['groups']
        as &$group
    ) {

        $questionNo = 1;

        foreach (
            $group['questions']
            as &$question
        ) {

            if (
                ($survey['numbering'] ?? 'global')
                === 'group'
            ) {

                $question['number'] =
                    'Q'
                    . $groupNo
                    . '-'
                    . $questionNo;

            } else {

                $question['number'] =
                    'Q'
                    . $global;
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
 * POST処理
 * ============================================================ */

function handle_post(
    array &$data,
    array &$settings
): void {

    $action =
        post_string('action');

    if ($action === '') {
        return;
    }

    try {

        /* ----------------------------------------------------
         * kintone 設定保存
         * ---------------------------------------------------- */

        if (
            $action ===
            'kintone_save'
        ) {

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

            $verifySsl =
                post_bool('verify_ssl');

            $config = [
                'subdomain' =>
                    $subdomain,

                'app_id' =>
                    $appId,

                'username' =>
                    $username,

                'proxy' =>
                    $proxy,

                'verify_ssl' =>
                    $verifySsl,
            ];

            $validation =
                validate_kintone_config(
                    $config
                );

            if ($validation['errors']) {

                flash(
                    'error',
                    implode(
                        ' ',
                        $validation['errors']
                    )
                );

                return;
            }

            $current =
                $settings['kintone'];

            $current['subdomain'] =
                $subdomain;

            $current['app_id'] =
                $appId;

            $current['username'] =
                $username;

            $current['proxy'] =
                $proxy;

            $current['verify_ssl'] =
                $verifySsl;

            if ($password !== '') {
                $current[
                    'password_encrypted'
                ] =
                    encrypt_secret(
                        $password
                    );
            }

            $settings['kintone'] =
                $current;

            save_json(
                SETTINGS_FILE,
                $settings
            );

            flash(
                'success',
                'kintone設定を保存しました。'
            );

            redirect_screen(
                'kintone'
            );
        }

        /* ----------------------------------------------------
         * kintone 接続テスト
         * ---------------------------------------------------- */

        if (
            $action ===
            'kintone_test'
        ) {

            /*
             * 画面入力を一時設定として使用。
             * 保存処理とは分離する。
             */
            $config =
                $settings['kintone'];

            $config['subdomain'] =
                post_string(
                    'subdomain'
                );

            $config['app_id'] =
                post_string(
                    'app_id'
                );

            $config['username'] =
                post_string(
                    'username'
                );

            $config['proxy'] =
                post_string(
                    'proxy'
                );

            $config['verify_ssl'] =
                post_bool(
                    'verify_ssl'
                );

            $password =
                post_string(
                    'password'
                );

            if ($password !== '') {
                $config['password'] =
                    $password;
            }

            /*
             * 実際のkintone APIへ接続。
             *
             * ここでは画面遷移用の
             * LocationヘッダーをAPIへ渡さない。
             */
            $result =
                kintone_connection_test(
                    $config
                );

            $settings[
                'kintone'
            ][
                'last_test'
            ] = [
                'at' => now(),
                'ok' =>
                    $result['ok'],
                'status' =>
                    $result['status'],
                'code' =>
                    $result['code'],
                'id' =>
                    $result['id'],
            ];

            save_json(
                SETTINGS_FILE,
                $settings
            );

            if ($result['ok']) {

                flash(
                    'success',
                    'kintone接続テストに成功しました。'
                );

            } else {

                $detail =
                    'kintone接続テストに失敗しました。';

                if (
                    $result['status'] > 0
                ) {
                    $detail .=
                        ' HTTP '
                        . $result['status'];
                }

                if (
                    $result['code'] !== ''
                ) {
                    $detail .=
                        ' / エラーコード: '
                        . $result['code'];
                }

                if (
                    $result['id'] !== ''
                ) {
                    $detail .=
                        ' / エラーID: '
                        . $result['id'];
                }

                $detail .=
                    ' / '
                    . $result['message'];

                flash(
                    'error',
                    $detail
                );
            }

            redirect_screen(
                'kintone'
            );
        }

        /* ----------------------------------------------------
         * kintone 項目再取得
         * ---------------------------------------------------- */

        if (
            $action ===
            'kintone_fields'
        ) {

            $result =
                kintone_fetch_fields(
                    $settings['kintone']
                );

            if (!$result['ok']) {

                flash(
                    'error',
                    '項目一覧の取得に失敗しました。'
                    . ' HTTP '
                    . $result['status']
                    . ' / '
                    . $result['code']
                    . ' / '
                    . $result['message']
                );

                redirect_screen(
                    'kintone'
                );
            }

            $fields =
                $result['data']['properties']
                ?? [];

            if (!is_array($fields)) {
                $fields = [];
            }

            $settings[
                'kintone'
            ][
                'fields'
            ] =
                kintone_fields_to_mapping(
                    $fields
                );

            save_json(
                SETTINGS_FILE,
                $settings
            );

            flash(
                'success',
                'kintoneの項目一覧を取得しました。'
            );

            redirect_screen(
                'kintone'
            );
        }

        /* ----------------------------------------------------
         * kintone 顧客同期
         * ---------------------------------------------------- */

        if (
            $action ===
            'kintone_sync'
        ) {

            $result =
                sync_kintone_customers(
                    $settings['kintone'],
                    $settings['kintone'],
                    $data
                );

            if (!$result['ok']) {

                flash(
                    'error',
                    '顧客情報の同期に失敗しました。'
                    . ' HTTP '
                    . $result['status']
                    . ' / '
                    . $result['code']
                    . ' / '
                    . $result['message']
                );

            } else {

                $settings[
                    'kintone'
                ][
                    'last_sync'
                ] = [
                    'at' => now(),
                    'count' =>
                        count(
                            $data['customers']
                        ),
                ];

                save_json(
                    DATA_FILE,
                    $data
                );

                save_json(
                    SETTINGS_FILE,
                    $settings
                );

                flash(
                    'success',
                    $result['message']
                );
            }

            redirect_screen(
                'kintone'
            );
        }

        /* ----------------------------------------------------
         * アンケート保存
         * ---------------------------------------------------- */

        if (
            $action ===
            'survey_save'
        ) {

            $id =
                post_string('id');

            if (
                $id !== ''
                && !safe_id($id)
            ) {
                throw new RuntimeException(
                    'アンケートIDが不正です。'
                );
            }

            $survey = null;

            foreach (
                $data['surveys']
                as &$item
            ) {

                if (
                    $item['id'] === $id
                ) {
                    $survey =& $item;
                    break;
                }
            }

            if ($survey === null) {

                $survey = [
                    'id' =>
                        $id !== ''
                            ? $id
                            : new_id('survey'),

                    'createdAt' => now(),
                    'groups' => [],
                ];

                $data['surveys'][] =
                    $survey;

                $survey =&
                    $data['surveys'][
                        array_key_last(
                            $data['surveys']
                        )
                    ];
            }

            $survey['title'] =
                post_string('title');

            $survey['description'] =
                post_string(
                    'description'
                );

            $survey['startAt'] =
                post_string(
                    'startAt'
                );

            $survey['endAt'] =
                post_string(
                    'endAt'
                );

            $numbering =
                post_string(
                    'numbering'
                );

            $survey['numbering'] =
                in_array(
                    $numbering,
                    ['global', 'group'],
                    true
                )
                    ? $numbering
                    : 'global';

            $survey['status'] =
                $survey['status']
                ?? 'draft';

            $survey['updatedAt'] =
                now();

            recalc_question_numbers(
                $survey
            );

            save_json(
                DATA_FILE,
                $data
            );

            flash(
                'success',
                'アンケートを保存しました。'
            );

            redirect_screen(
                'list'
            );
        }

        /* ----------------------------------------------------
         * アンケート削除
         * ---------------------------------------------------- */

        if (
            $action ===
            'survey_delete'
        ) {

            $id =
                post_string('id');

            $data['surveys'] =
                array_values(
                    array_filter(
                        $data['surveys'],
                        static fn(
                            array $survey
                        ): bool =>
                            $survey['id']
                            !== $id
                    )
                );

            save_json(
                DATA_FILE,
                $data
            );

            flash(
                'success',
                'アンケートを削除しました。'
            );

            redirect_screen(
                'list'
            );
        }

        /* ----------------------------------------------------
         * アンケート複製
         * ---------------------------------------------------- */

        if (
            $action ===
            'survey_duplicate'
        ) {

            $id =
                post_string('id');

            $source = null;

            foreach (
                $data['surveys']
                as $survey
            ) {
                if (
                    $survey['id'] === $id
                ) {
                    $source = $survey;
                    break;
                }
            }

            if ($source === null) {
                throw new RuntimeException(
                    '複製対象が見つかりません。'
                );
            }

            $source['id'] =
                new_id('survey');

            $source['title'] =
                $source['title']
                . '（コピー）';

            $source['status'] =
                'draft';

            $source['createdAt'] =
                now();

            $source['updatedAt'] =
                now();

            $data['surveys'][] =
                $source;

            save_json(
                DATA_FILE,
                $data
            );

            flash(
                'success',
                'アンケートを複製しました。'
            );

            redirect_screen(
                'list'
            );
        }

        /* ----------------------------------------------------
         * 状態変更
         * ---------------------------------------------------- */

        if (
            $action ===
            'survey_status'
        ) {

            $id =
                post_string('id');

            $status =
                post_string('status');

            $allowed = [
                'draft',
                'published',
                'stopped',
            ];

            if (
                !in_array(
                    $status,
                    $allowed,
                    true
                )
            ) {
                throw new RuntimeException(
                    '状態が不正です。'
                );
            }

            foreach (
                $data['surveys']
                as &$survey
            ) {

                if (
                    $survey['id'] !== $id
                ) {
                    continue;
                }

                auto_end_survey(
                    $survey
                );

                if (
                    $survey['status']
                    === 'ended'
                ) {
                    throw new RuntimeException(
                        '終了したアンケートの状態は変更できません。'
                    );
                }

                $survey['status'] =
                    $status;

                $survey['updatedAt'] =
                    now();

                break;
            }

            unset($survey);

            save_json(
                DATA_FILE,
                $data
            );

            flash(
                'success',
                '状態を変更しました。'
            );

            redirect_screen(
                'list'
            );
        }

        /* ----------------------------------------------------
         * 回答
         * ---------------------------------------------------- */

        if (
            $action ===
            'answer_submit'
        ) {

            $surveyId =
                post_string(
                    'survey_id'
                );

            $survey =
                find_survey(
                    $data,
                    $surveyId
                );

            if ($survey === null) {
                throw new RuntimeException(
                    'アンケートが見つかりません。'
                );
            }

            $_SESSION[
                'answer_' . $surveyId
            ] =
                $_POST['answer']
                ?? [];

            redirect_screen(
                'confirm',
                [
                    'id' =>
                        $surveyId,
                ]
            );
        }

        /* ----------------------------------------------------
         * 回答確定
         * ---------------------------------------------------- */

        if (
            $action ===
            'answer_confirm'
        ) {

            $surveyId =
                post_string(
                    'survey_id'
                );

            $answer =
                $_SESSION[
                    'answer_' . $surveyId
                ]
                ?? [];

            $data['answers'][] = [
                'id' =>
                    new_id('answer'),
                'surveyId' =>
                    $surveyId,
                'answers' =>
                    $answer,
                'createdAt' =>
                    now(),
            ];

            unset(
                $_SESSION[
                    'answer_' . $surveyId
                ]
            );

            save_json(
                DATA_FILE,
                $data
            );

            redirect_screen(
                'complete',
                [
                    'id' =>
                        $surveyId,
                ]
            );
        }

    } catch (Throwable $e) {

        /*
         * システム内部情報はそのまま表示しない。
         */
        flash(
            'error',
            $e->getMessage()
        );
    }
}

/* ============================================================
 * POST処理実行
 * ============================================================ */

$data =
    load_data();

$settings =
    load_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_post(
        $data,
        $settings
    );
}

/*
 * GET時にも終了状態を自動判定。
 */
foreach (
    $data['surveys']
    as &$survey
) {
    auto_end_survey(
        $survey
    );
}
unset($survey);

save_json(
    DATA_FILE,
    $data
);

/* ============================================================
 * 画面
 * ============================================================ */

$screen =
    get_string('screen');

if ($screen === '') {
    $screen = 'list';
}

$surveyId =
    get_string('id');

$flash =
    take_flash();

/* ============================================================
 * 共通HTML
 * ============================================================ */

function render_header(
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
:root {
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

* {
    box-sizing:border-box;
}

html,
body {
    margin:0;
    padding:0;
}

body {
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

a {
    color:var(--primary);
    text-decoration:none;
}

button,
input,
textarea,
select {
    font:inherit;
}

button {
    cursor:pointer;
}

.admin-header {
    background:#0f172a;
    color:#fff;
    padding:16px 24px;
}

.admin-header-inner {
    max-width:1400px;
    margin:auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.admin-header a {
    color:#fff;
}

.brand {
    font-weight:700;
    font-size:18px;
}

.nav {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.nav a {
    padding:8px 12px;
    border-radius:8px;
    color:#cbd5e1;
}

.nav a:hover {
    background:#1e293b;
    color:#fff;
}

.container {
    max-width:1400px;
    margin:0 auto;
    padding:28px 24px 60px;
}

.answer-container {
    max-width:760px;
    margin:0 auto;
    padding:24px 16px 60px;
}

.page-title {
    margin:0 0 6px;
    font-size:26px;
}

.page-description {
    color:var(--gray);
    margin:0 0 24px;
}

.card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:22px;
    margin-bottom:20px;
}

.toolbar {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:20px;
    flex-wrap:wrap;
}

.button {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:9px 16px;
    border:1px solid transparent;
    border-radius:8px;
    background:#fff;
    color:var(--text);
}

.button.primary {
    background:var(--primary);
    color:#fff;
}

.button.primary:hover {
    background:var(--primary-dark);
}

.button.success {
    background:var(--success);
    color:#fff;
}

.button.danger {
    background:var(--danger);
    color:#fff;
}

.button.secondary {
    border-color:var(--border);
}

.button:disabled {
    opacity:.5;
    cursor:not-allowed;
}

.form-grid {
    display:grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap:18px;
}

.form-group {
    display:flex;
    flex-direction:column;
    gap:7px;
}

.form-group.full {
    grid-column:1/-1;
}

.form-label {
    font-weight:600;
}

input[type="text"],
input[type="password"],
input[type="email"],
input[type="number"],
input[type="datetime-local"],
textarea,
select {
    width:100%;
    border:1px solid var(--border);
    border-radius:8px;
    padding:10px 12px;
    background:#fff;
    color:var(--text);
}

textarea {
    min-height:110px;
    resize:vertical;
}

.actions {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-top:20px;
}

.alert {
    border-radius:10px;
    padding:13px 16px;
    margin-bottom:16px;
}

.alert.success {
    background:#ecfdf5;
    color:#166534;
    border:1px solid #bbf7d0;
}

.alert.error {
    background:#fef2f2;
    color:#991b1b;
    border:1px solid #fecaca;
}

.table-wrap {
    overflow-x:auto;
}

table {
    width:100%;
    border-collapse:collapse;
    min-width:950px;
}

th,
td {
    padding:12px 10px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th {
    background:#f8fafc;
    font-weight:700;
}

.badge {
    display:inline-flex;
    padding:4px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}

.badge.draft {
    background:#e2e8f0;
    color:#475569;
}

.badge.published {
    background:#dcfce7;
    color:#166534;
}

.badge.stopped {
    background:#fef3c7;
    color:#92400e;
}

.badge.ended {
    background:#fee2e2;
    color:#991b1b;
}

.grid {
    display:grid;
    grid-template-columns:
        repeat(3,minmax(0,1fr));
    gap:16px;
}

.stat {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:20px;
    box-shadow:var(--shadow);
}

.stat-label {
    color:var(--gray);
    font-size:13px;
}

.stat-value {
    font-size:28px;
    font-weight:700;
    margin-top:6px;
}

.question {
    border:1px solid var(--border);
    border-radius:10px;
    padding:18px;
    margin-bottom:14px;
    background:#fff;
}

.question-title {
    font-weight:700;
    margin-bottom:12px;
}

.option {
    display:flex;
    align-items:center;
    gap:10px;
    padding:8px 0;
}

.group-title {
    font-size:18px;
    font-weight:700;
    margin-bottom:16px;
}

.kintone-status {
    display:flex;
    gap:12px;
    flex-wrap:wrap;
    align-items:center;
}

.muted {
    color:var(--gray);
}

.text-danger {
    color:var(--danger);
}

.text-success {
    color:var(--success);
}

.preview-frame {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:30px;
    box-shadow:var(--shadow);
}

.mobile-preview {
    max-width:430px;
    margin:auto;
}

.empty {
    padding:50px 20px;
    text-align:center;
    color:var(--gray);
}

@media (max-width:800px) {

    .container {
        padding:20px 14px 40px;
    }

    .admin-header {
        padding:14px;
    }

    .admin-header-inner {
        align-items:flex-start;
        flex-direction:column;
    }

    .form-grid {
        grid-template-columns:1fr;
    }

    .form-group.full {
        grid-column:auto;
    }

    .grid {
        grid-template-columns:1fr;
    }

    .page-title {
        font-size:22px;
    }

    .card {
        padding:16px;
    }

    .button {
        min-height:44px;
    }
}
</style>
</head>
<body>

<?php if ($admin): ?>

<header class="admin-header">
<div class="admin-header-inner">

<a class="brand"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
    アンケート管理
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

<?php
}

function render_footer(): void
{
?>
</body>
</html>
<?php
}

/* ============================================================
 * Flash表示
 * ============================================================ */

function render_flash(
    array $flash
): void {

    foreach ($flash as $item) {

        $type =
            $item['type']
            ?? 'error';

        $message =
            $item['message']
            ?? '';

        ?>
        <div class="alert <?= h($type) ?>">
            <?= h($message) ?>
        </div>
        <?php
    }
}

/* ============================================================
 * 一覧
 * ============================================================ */

if ($screen === 'list') {

    render_header(
        'アンケート一覧'
    );
?>

<main class="container">

<h1 class="page-title">
    アンケート一覧
</h1>

<p class="page-description">
    アンケートの作成・公開・回答状況を管理します。
</p>

<?php render_flash($flash); ?>

<div class="toolbar">

<form method="get"
      style="display:flex;gap:8px;flex:1;max-width:500px">

<input type="hidden"
       name="screen"
       value="list">

<input type="text"
       name="q"
       value="<?= h(get_string('q')) ?>"
       placeholder="タイトルで検索">

<button class="button secondary">
    検索
</button>

</form>

<a class="button primary"
   href="<?= h(app_url(['screen'=>'edit'])) ?>">
    ＋ 新規作成
</a>

</div>

<div class="card">

<?php

$q =
    mb_strtolower(
        get_string('q')
    );

$surveys =
    $data['surveys'];

if ($q !== '') {

    $surveys =
        array_filter(
            $surveys,
            static function (
                array $survey
            ) use ($q): bool {

                return mb_strpos(
                    mb_strtolower(
                        (string)(
                            $survey['title']
                            ?? ''
                        )
                    ),
                    $q
                ) !== false;
            }
        );
}

?>

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
    アンケートがありません。
</div>
</td>
</tr>

<?php else: ?>

<?php foreach ($surveys as $survey): ?>

<?php
$status =
    $survey['status']
    ?? 'draft';

$statusLabel = [
    'draft' =>
        '下書き',
    'published' =>
        '公開中',
    'stopped' =>
        '停止',
    'ended' =>
        '終了',
][$status] ?? $status;

$answerCount =
    count(
        array_filter(
            $data['answers'],
            static fn(
                array $answer
            ): bool =>
                ($answer['surveyId'] ?? '')
                === ($survey['id'] ?? '')
        )
    );
?>

<tr>

<td>
<strong>
<?= h($survey['title'] ?? '') ?>
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
～
<?= h($survey['endAt'] ?? '') ?>
</td>

<td>
<span class="badge <?= h($status) ?>">
<?= h($statusLabel) ?>
</span>
</td>

<td>
<?= $answerCount ?>
</td>

<td>

<div style="display:flex;gap:6px;flex-wrap:wrap">

<a class="button secondary"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>$survey['id'],
   ])) ?>">
    確認・編集
</a>

<a class="button secondary"
   href="<?= h(app_url([
       'screen'=>'preview',
       'id'=>$survey['id'],
   ])) ?>">
    プレビュー
</a>

<a class="button secondary"
   href="<?= h(app_url([
       'screen'=>'analytics',
       'id'=>$survey['id'],
   ])) ?>">
    集計
</a>

<a class="button secondary"
   href="<?= h(app_url([
       'screen'=>'send',
       'id'=>$survey['id'],
   ])) ?>">
    送信
</a>

<form method="post"
      style="display:inline"
      onsubmit="return confirm('複製しますか？')">

<input type="hidden"
       name="action"
       value="survey_duplicate">

<input type="hidden"
       name="id"
       value="<?= h($survey['id']) ?>">

<button class="button secondary">
    複製
</button>

</form>

<form method="post"
      style="display:inline"
      onsubmit="return confirm('削除しますか？')">

<input type="hidden"
       name="action"
       value="survey_delete">

<input type="hidden"
       name="id"
       value="<?= h($survey['id']) ?>">

<button class="button danger">
    削除
</button>

</form>

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
    render_footer();
    exit;
}

/* ============================================================
 * 編集
 * ============================================================ */

if ($screen === 'edit') {

    $survey =
        $surveyId !== ''
            ? find_survey(
                $data,
                $surveyId
            )
            : null;

    if ($survey === null) {

        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => now_input(),
            'endAt' => '',
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [],
        ];
    }

    render_header(
        'アンケート作成・編集'
    );
?>

<main class="container">

<h1 class="page-title">
    アンケート作成・編集
</h1>

<p class="page-description">
    アンケートの基本情報を設定します。
</p>

<?php render_flash($flash); ?>

<form method="post">

<input type="hidden"
       name="action"
       value="survey_save">

<input type="hidden"
       name="id"
       value="<?= h($survey['id']) ?>">

<div class="card">

<div class="toolbar">

<div>
<strong>
    状態：
    <?= h([
        'draft'=>'下書き',
        'published'=>'公開中',
        'stopped'=>'停止',
        'ended'=>'終了',
    ][$survey['status']] ?? '') ?>
</strong>
</div>

<div class="actions"
     style="margin-top:0">

<a class="button secondary"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
    キャンセル
</a>

<a class="button secondary"
   href="<?= h(app_url([
       'screen'=>'preview',
       'id'=>$survey['id'],
   ])) ?>">
    プレビュー
</a>

<button class="button primary">
    保存して一覧へ
</button>

</div>

</div>

<div class="form-grid">

<div class="form-group full">

<label class="form-label">
    アンケートタイトル
</label>

<input type="text"
       name="title"
       required
       value="<?= h($survey['title']) ?>">

</div>

<div class="form-group full">

<label class="form-label">
    アンケート説明
</label>

<textarea name="description"><?= h(
    $survey['description']
) ?></textarea>

</div>

<div class="form-group">

<label class="form-label">
    開始日時
</label>

<input type="datetime-local"
       name="startAt"
       value="<?= h($survey['startAt']) ?>">

</div>

<div class="form-group">

<label class="form-label">
    終了日時
</label>

<input type="datetime-local"
       name="endAt"
       value="<?= h($survey['endAt']) ?>">

</div>

<div class="form-group">

<label class="form-label">
    質問番号の採番方式
</label>

<select name="numbering">

<option value="global"
<?= ($survey['numbering'] ?? '')
    === 'global'
    ? 'selected'
    : '' ?>>
    アンケート全体で通番
    （Q1、Q2、Q3…）
</option>

<option value="group"
<?= ($survey['numbering'] ?? '')
    === 'group'
    ? 'selected'
    : '' ?>>
    グループ毎
    （Q1-1、Q1-2…）
</option>

</select>

</div>

</div>

</div>

<div class="card">

<h2>質問・グループ</h2>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="question">

<div class="group-title">
<?= h($group['title'] ?? '') ?>
</div>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="question">

<div class="question-title">

<?= h(
    $question['number']
    ?? ''
) ?>

　
<?= h(
    $question['text']
    ?? ''
) ?>

<?php if (
    !empty($question['required'])
): ?>

<span class="badge published">
必須
</span>

<?php endif; ?>

</div>

<div class="muted">
回答形式：
<?= h([
    'single'=>'単一選択',
    'multiple'=>'複数選択',
    'text'=>'自由記述',
][$question['type'] ?? 'text']
    ?? '') ?>
</div>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

</div>

</form>

</main>

<?php
    render_footer();
    exit;
}

/* ============================================================
 * プレビュー
 * ============================================================ */

if ($screen === 'preview') {

    $survey =
        find_survey(
            $data,
            $surveyId
        );

    if ($survey === null) {
        redirect_screen('list');
    }

    render_header(
        'プレビュー'
    );
?>

<main class="container">

<div class="toolbar">

<div>
<h1 class="page-title">
    プレビュー
</h1>

<p class="page-description">
    <?= h($survey['title']) ?>
</p>
</div>

<a class="button secondary"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>$survey['id'],
   ])) ?>">
    編集へ戻る
</a>

</div>

<div class="preview-frame">

<h1>
<?= h($survey['title']) ?>
</h1>

<p>
<?= nl2br(
    h($survey['description'])
) ?>
</p>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<section class="card">

<h2>
<?= h($group['title']) ?>
</h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="question">

<div class="question-title">
<?= h($question['number']) ?>　
<?= h($question['text']) ?>
</div>

<?php if (
    $question['type'] === 'single'
): ?>

<?php foreach (
    $question['options']
    as $option
): ?>

<label class="option">
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

<label class="option">
<input type="checkbox"
       disabled>
<?= h($option['label']) ?>
</label>

<?php endforeach; ?>

<?php else: ?>

<textarea disabled></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>

</section>

<?php endforeach; ?>

</div>

</main>

<?php
    render_footer();
    exit;
}

/* ============================================================
 * kintone設定
 * ============================================================ */

if ($screen === 'kintone') {

    $config =
        $settings['kintone'];

    render_header(
        'kintone連携設定'
    );
?>

<main class="container">

<h1 class="page-title">
    kintone連携設定
</h1>

<p class="page-description">
    顧客情報を取得するkintoneを設定します。
</p>

<?php render_flash($flash); ?>

<div class="card">

<form method="post">

<input type="hidden"
       name="action"
       value="kintone_save">

<div class="form-grid">

<div class="form-group">

<label class="form-label">
    サブドメイン
</label>

<input type="text"
       name="subdomain"
       value="<?= h(
           $config['subdomain']
       ) ?>"
       placeholder="xxxx / xxxx.cybozu.com">

</div>

<div class="form-group">

<label class="form-label">
    顧客管理アプリID
</label>

<input type="number"
       name="app_id"
       min="1"
       value="<?= h(
           $config['app_id']
       ) ?>">

</div>

<div class="form-group">

<label class="form-label">
    ログイン名
</label>

<input type="text"
       name="username"
       autocomplete="username"
       value="<?= h(
           $config['username']
       ) ?>">

</div>

<div class="form-group">

<label class="form-label">
    パスワード
</label>

<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">

</div>

<div class="form-group">

<label class="form-label">
    Proxy
</label>

<input type="text"
       name="proxy"
       value="<?= h(
           $config['proxy']
       ) ?>"
       placeholder="host:port">

</div>

<div class="form-group">

<label class="form-label">
    SSL証明書検証
</label>

<select name="verify_ssl">

<option value="0"
<?= empty(
    $config['verify_ssl']
)
    ? 'selected'
    : '' ?>>
    無効
</option>

<option value="1"
<?= !empty(
    $config['verify_ssl']
)
    ? 'selected'
    : '' ?>>
    有効
</option>

</select>

</div>

</div>

<div class="actions">

<button class="button primary">
    設定保存
</button>

</div>

</form>

</div>

<div class="card">

<h2>
kintone接続
</h2>

<p class="muted">
設定保存とは独立して、実際のkintoneへ接続して確認します。
</p>

<form method="post">

<input type="hidden"
       name="action"
       value="kintone_test">

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

<input type="hidden"
       name="verify_ssl"
       value="<?= !empty(
           $config['verify_ssl']
       ) ? '1' : '0' ?>">

<div class="actions">

<button class="button primary"
        onclick="
            this.disabled=true;
            this.form.submit();
        ">
    kintone接続テスト
</button>

</div>

</form>

<?php
$lastTest =
    $config['last_test']
    ?? null;
?>

<?php if (is_array($lastTest)): ?>

<div class="kintone-status"
     style="margin-top:18px">

<?php if (
    !empty($lastTest['ok'])
): ?>

<span class="badge published">
    接続成功
</span>

<?php else: ?>

<span class="badge ended">
    接続失敗
</span>

<?php endif; ?>

<span class="muted">
<?= h(
    $lastTest['at']
    ?? ''
) ?>
</span>

<?php if (
    !empty($lastTest['code'])
): ?>

<span>
エラーコード:
<?= h(
    $lastTest['code']
) ?>
</span>

<?php endif; ?>

<?php if (
    !empty($lastTest['id'])
): ?>

<span>
エラーID:
<?= h(
    $lastTest['id']
) ?>
</span>

<?php endif; ?>

</div>

<?php endif; ?>

</div>

<div class="card">

<h2>
顧客項目
</h2>

<form method="post">

<input type="hidden"
       name="action"
       value="kintone_fields">

<button class="button secondary">
    項目一覧を再取得
</button>

</form>

<?php
$fields =
    $config['fields']
    ?? [];
?>

<?php if ($fields): ?>

<div class="table-wrap"
     style="margin-top:18px">

<table>

<thead>
<tr>
<th>フィールドコード</th>
<th>項目名</th>
<th>形式</th>
</tr>
</thead>

<tbody>

<?php foreach (
    $fields
    as $field
): ?>

<tr>

<td>
<?= h(
    $field['code']
    ?? ''
) ?>
</td>

<td>
<?= h(
    $field['label']
    ?? ''
) ?>
</td>

<td>
<?= h(
    $field['type']
    ?? ''
) ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php else: ?>

<p class="muted">
まだ項目一覧を取得していません。
</p>

<?php endif; ?>

</div>

<div class="card">

<h2>
顧客情報を同期
</h2>

<form method="post">

<input type="hidden"
       name="action"
       value="kintone_sync">

<button class="button success">
    顧客情報を同期
</button>

</form>

<?php if (
    !empty(
        $config['last_sync']
    )
): ?>

<p class="muted">
最終同期：
<?= h(
    $config['last_sync']['at']
    ?? ''
) ?>

/
<?= h(
    $config['last_sync']['count']
    ?? 0
) ?>件
</p>

<?php endif; ?>

</div>

</main>

<?php
    render_footer();
    exit;
}

/* ============================================================
 * メール設定
 * ============================================================ */

if ($screen === 'mail') {

    render_header(
        'メールサーバ設定'
    );
?>

<main class="container">

<h1 class="page-title">
    メールサーバ設定
</h1>

<p class="page-description">
    SMTPサーバを設定します。
</p>

<?php render_flash($flash); ?>

<div class="card">

<div class="form-grid">

<div class="form-group">

<label class="form-label">
SMTPサーバ
</label>

<input type="text"
       value="<?= h(
           $settings['mail']['server']
       ) ?>">

</div>

<div class="form-group">

<label class="form-label">
SMTPポート
</label>

<input type="number"
       value="<?= h(
           $settings['mail']['port']
       ) ?>">

</div>

<div class="form-group">

<label class="form-label">
暗号化方式
</label>

<select>
<option>SSL</option>
<option selected>TLS</option>
<option>なし</option>
</select>

</div>

<div class="form-group">

<label class="form-label">
SMTPユーザー名
</label>

<input type="text"
       value="<?= h(
           $settings['mail']['username']
       ) ?>">

</div>

<div class="form-group">

<label class="form-label">
送信元メールアドレス
</label>

<input type="email"
       value="<?= h(
           $settings['mail']['from_email']
       ) ?>">

</div>

<div class="form-group">

<label class="form-label">
送信元名
</label>

<input type="text"
       value="<?= h(
           $settings['mail']['from_name']
       ) ?>">

</div>

<div class="form-group">

<label class="form-label">
返信先メールアドレス
</label>

<input type="email"
       value="<?= h(
           $settings['mail']['reply_to']
       ) ?>">

</div>

</div>

<div class="actions">

<button class="button primary">
    設定保存
</button>

<button class="button secondary">
    接続テスト
</button>

<button class="button secondary">
    テストメール送信
</button>

</div>

</div>

</main>

<?php
    render_footer();
    exit;
}

/* ============================================================
 * 送信
 * ============================================================ */

if ($screen === 'send') {

    $survey =
        find_survey(
            $data,
            $surveyId
        );

    if ($survey === null) {
        redirect_screen('list');
    }

    render_header(
        '顧客選択・メール送信'
    );
?>

<main class="container">

<h1 class="page-title">
    顧客選択・メール送信
</h1>

<p class="page-description">
対象アンケート：
<strong><?= h(
    $survey['title']
) ?></strong>
</p>

<?php render_flash($flash); ?>

<div class="card">

<h2>
顧客選択
</h2>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>選択</th>
<th>組織名</th>
<th>氏名</th>
<th>メールアドレス</th>
<th>部署</th>
</tr>
</thead>

<tbody>

<?php foreach (
    $data['customers']
    as $customer
): ?>

<tr>

<td>
<input type="checkbox">
</td>

<td>
<?= h(
    $customer['organization']
    ?? ''
) ?>
</td>

<td>
<?= h(
    $customer['name']
    ?? ''
) ?>
</td>

<td>
<?= h(
    $customer['email']
    ?? ''
) ?>
</td>

<td>
<?= h(
    $customer['department']
    ?? ''
) ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

<div class="card">

<h2>
メール作成
</h2>

<div class="form-group">

<label class="form-label">
件名
</label>

<input type="text"
       value="<?= h(
           $survey['title']
       ) ?>">

</div>

<div class="form-group"
     style="margin-top:16px">

<label class="form-label">
本文
</label>

<textarea><?= h(
    "{顧客名} 様\n\n"
    . "アンケートへのご協力をお願いいたします。\n"
    . "{アンケートURL}"
) ?></textarea>

</div>

<div class="actions">

<button class="button primary"
        onclick="
            return confirm('一括送信しますか？');
        ">
    一括送信
</button>

<button class="button secondary">
    再送
</button>

<button class="button secondary">
    リマインド
</button>

</div>

</div>

<div class="card">

<h2>
送信履歴
</h2>

<?php if (
    !$data['send_history']
): ?>

<div class="empty">
    送信履歴はありません。
</div>

<?php else: ?>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>日時</th>
<th>対象</th>
<th>件数</th>
<th>結果</th>
</tr>
</thead>

<tbody>

<?php foreach (
    $data['send_history']
    as $history
): ?>

<tr>

<td>
<?= h(
    $history['createdAt']
    ?? ''
) ?>
</td>

<td>
<?= h(
    $history['surveyTitle']
    ?? ''
) ?>
</td>

<td>
<?= h(
    $history['count']
    ?? 0
) ?>
</td>

<td>
<?= h(
    $history['result']
    ?? ''
) ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php endif; ?>

</div>

</main>

<?php
    render_footer();
    exit;
}

/* ============================================================
 * 集計
 * ============================================================ */

if ($screen === 'analytics') {

    $survey =
        find_survey(
            $data,
            $surveyId
        );

    if ($survey === null) {
        redirect_screen('list');
    }

    $answers =
        array_values(
            array_filter(
                $data['answers'],
                static fn(
                    array $answer
                ): bool =>
                    ($answer['surveyId'] ?? '')
                    === $survey['id']
            )
        );

    render_header(
        '回答集計・分析'
    );
?>

<main class="container">

<h1 class="page-title">
    回答集計・分析
</h1>

<p class="page-description">
対象アンケート：
<strong><?= h(
    $survey['title']
) ?></strong>
</p>

<?php render_flash($flash); ?>

<div class="grid">

<div class="stat">
<div class="stat-label">
送信対象者数
</div>
<div class="stat-value">
<?= count(
    $data['customers']
) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">
回答数
</div>
<div class="stat-value">
<?= count($answers) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">
回答率
</div>
<div class="stat-value">
<?php
$customerCount =
    count($data['customers']);

echo $customerCount > 0
    ? h(
        number_format(
            count($answers)
            / $customerCount
            * 100,
            1
        )
    ) . '%'
    : '0%';
?>
</div>
</div>

</div>

<div class="card"
     style="margin-top:20px">

<h2>
設問別集計
</h2>

<?php if (!$answers): ?>

<div class="empty">
    現在、回答データはありません
</div>

<?php else: ?>

<p>
回答データ：
<?= count($answers) ?>件
</p>

<?php endif; ?>

</div>

<div class="card">

<h2>
個別回答
</h2>

<?php if (!$answers): ?>

<div class="empty">
    現在、回答データはありません
</div>

<?php else: ?>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>回答日時</th>
<th>回答</th>
</tr>
</thead>

<tbody>

<?php foreach (
    $answers
    as $answer
): ?>

<tr>

<td>
<?= h(
    $answer['createdAt']
    ?? ''
) ?>
</td>

<td>
<pre style="
white-space:pre-wrap;
margin:0;
font-family:inherit;
"><?= h(
    json_encode(
        $answer['answers']
        ?? [],
        JSON_UNESCAPED_UNICODE
        | JSON_PRETTY_PRINT
    )
) ?></pre>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php endif; ?>

</div>

</main>

<?php
    render_footer();
    exit;
}

/* ============================================================
 * 回答者画面
 * ============================================================ */

if ($screen === 'answer') {

    $survey =
        find_survey(
            $data,
            $surveyId
        );

    if ($survey === null) {
        http_response_code(404);
        exit('アンケートが見つかりません。');
    }

    render_header(
        $survey['title'],
        false
    );
?>

<main class="answer-container">

<div class="card">

<h1 class="page-title">
<?= h(
    $survey['title']
) ?>
</h1>

<p>
<?= nl2br(
    h(
        $survey['description']
        ?? ''
    )
) ?>
</p>

</div>

<form method="post">

<input type="hidden"
       name="action"
       value="answer_submit">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="card">

<h2>
<?= h(
    $group['title']
) ?>
</h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="question">

<div class="question-title">

<?= h(
    $question['number']
) ?>　
<?= h(
    $question['text']
) ?>

<?php if (
    !empty($question['required'])
): ?>

<span class="text-danger">
*
</span>

<?php endif; ?>

</div>

<?php if (
    $question['type']
    === 'single'
): ?>

<?php foreach (
    $question['options']
    as $option
): ?>

<label class="option">

<input type="radio"
       name="answer[<?= h(
           $question['id']
       ) ?>]"
       value="<?= h(
           $option['id']
       ) ?>"
       <?= !empty(
           $question['required']
       )
           ? 'required'
           : '' ?>>

<?= h(
    $option['label']
) ?>

</label>

<?php endforeach; ?>

<?php elseif (
    $question['type']
    === 'multiple'
): ?>

<?php foreach (
    $question['options']
    as $option
): ?>

<label class="option">

<input type="checkbox"
       name="answer[<?= h(
           $question['id']
       ) ?>][]"
       value="<?= h(
           $option['id']
       ) ?>">

<?= h(
    $option['label']
) ?>

</label>

<?php endforeach; ?>

<?php else: ?>

<textarea
    name="answer[<?= h(
        $question['id']
    ) ?>]"
    <?= !empty(
        $question['required']
    )
        ? 'required'
        : '' ?>></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<button class="button primary"
        style="width:100%;margin-top:8px">
    回答確認へ
</button>

</form>

</main>

<?php
    render_footer();
    exit;
}

/* ============================================================
 * 回答確認
 * ============================================================ */

if ($screen === 'confirm') {

    $survey =
        find_survey(
            $data,
            $surveyId
        );

    if ($survey === null) {
        http_response_code(404);
        exit('アンケートが見つかりません。');
    }

    $answer =
        $_SESSION[
            'answer_' . $surveyId
        ]
        ?? [];

    render_header(
        '回答確認',
        false
    );
?>

<main class="answer-container">

<div class="card">

<h1 class="page-title">
回答確認
</h1>

<p>
送信内容をご確認ください。
</p>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="question">

<div class="question-title">

<?= h(
    $question['number']
) ?>　
<?= h(
    $question['text']
) ?>

</div>

<div>

<?php
$value =
    $answer[
        $question['id']
    ]
    ?? '';

if (is_array($value)) {
    echo h(
        implode(
            '、',
            $value
        )
    );
} else {
    echo nl2br(
        h((string)$value)
    );
}
?>

</div>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="actions">

<a class="button secondary"
   href="<?= h(app_url([
       'screen'=>'answer',
       'id'=>$survey['id'],
   ])) ?>">
    戻って修正
</a>

<form method="post">

<input type="hidden"
       name="action"
       value="answer_confirm">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<button class="button primary"
        onclick="
            return confirm('回答を送信しますか？');
        ">
    送信する
</button>

</form>

</div>

</div>

</main>

<?php
    render_footer();
    exit;
}

/* ============================================================
 * 完了
 * ============================================================ */

if ($screen === 'complete') {

    render_header(
        '回答完了',
        false
    );
?>

<main class="answer-container">

<div class="card"
     style="text-align:center">

<h1>
回答ありがとうございました
</h1>

<p>
回答を受け付けました。
</p>

</div>

</main>

<?php
    render_footer();
    exit;
}

/* ============================================================
 * 不明画面
 * ============================================================ */

http_response_code(404);

render_header(
    'ページが見つかりません'
);

?>

<main class="container">

<div class="card">

<h1>
ページが見つかりません
</h1>

<p>
指定された画面は存在しません。
</p>

<a class="button primary"
   href="<?= h(
       app_url(['screen'=>'list'])
   ) ?>">
    アンケート一覧へ
</a>

</div>

</main>

<?php
render_footer();
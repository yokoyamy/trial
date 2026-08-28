<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 *
 * Apache 2.4 / PHP 8.5
 * DBなし
 * PHP cURLなし
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
 * 外部通信関数から画面遷移しない。
 * 外部通信とPOST処理・画面表示を分離する。
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';

const DATA_DIR      = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE     = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const SECRET_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . '.secret';

const HTTP_CONNECT_TIMEOUT = 10;
const HTTP_READ_TIMEOUT    = 30;

const MAX_TITLE       = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION    = 1000;
const MAX_OPTION      = 500;
const MAX_MAIL_SUBJECT = 500;
const MAX_MAIL_BODY    = 20000;


/* =========================================================
 * 基本
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
            ['1', 'true', 'on'],
            true
        );
}

function uuid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function app_url(array $params = []): string
{
    $script = (string)(
        $_SERVER['SCRIPT_NAME'] ?? 'index.php'
    );

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

function public_answer_url(string $surveyId): string
{
    $https =
        (!empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

    $scheme = $https ? 'https' : 'http';

    $host = (string)(
        $_SERVER['HTTP_HOST'] ?? 'localhost'
    );

    return $scheme
        . '://'
        . $host
        . app_url([
            'screen' => 'answer',
            'id' => $surveyId,
        ]);
}


/* =========================================================
 * セッション
 * ========================================================= */

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

function start_app(): void
{
    if (!is_dir(DATA_DIR)) {
        if (
            !mkdir(DATA_DIR, 0775, true)
            && !is_dir(DATA_DIR)
        ) {
            throw new RuntimeException(
                'データ保存フォルダを作成できません。'
            );
        }
    }

    if (!is_file(DATA_FILE)) {
        save_json(DATA_FILE, default_data());
    }

    if (!is_file(SETTINGS_FILE)) {
        save_json(
            SETTINGS_FILE,
            default_settings()
        );
    }

    ensure_secret();

    if (
        session_status()
        !== PHP_SESSION_ACTIVE
    ) {
        $https =
            (!empty($_SERVER['HTTPS'])
                && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 80)
                === 443;

        session_name('survey_app_session');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => cookie_path(),
            'secure' => $https,
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
 * 暗号鍵
 * ========================================================= */

function ensure_secret(): string
{
    if (is_file(SECRET_FILE)) {
        $secret = @file_get_contents(SECRET_FILE);

        if (
            is_string($secret)
            && strlen($secret) >= 32
        ) {
            return $secret;
        }
    }

    $secret = random_bytes(32);

    $fp = @fopen(SECRET_FILE, 'xb');

    if ($fp !== false) {
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, $secret);
                fflush($fp);
                flock($fp, LOCK_UN);
            }

            fclose($fp);
        } catch (Throwable $e) {
            @fclose($fp);
            throw $e;
        }

        @chmod(SECRET_FILE, 0600);

        return $secret;
    }

    $existing = @file_get_contents(SECRET_FILE);

    if (
        is_string($existing)
        && strlen($existing) >= 32
    ) {
        return $existing;
    }

    throw new RuntimeException(
        '暗号化キーを作成できません。'
    );
}

function encrypt_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    $key = ensure_secret();

    $iv = random_bytes(
        openssl_cipher_iv_length('aes-256-gcm')
    );

    $tag = '';

    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        hash('sha256', $key, true),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cipher === false) {
        throw new RuntimeException(
            '機密情報の暗号化に失敗しました。'
        );
    }

    return 'v1:'
        . base64_encode($iv)
        . ':'
        . base64_encode($tag)
        . ':'
        . base64_encode($cipher);
}

function decrypt_secret(string $encrypted): string
{
    if ($encrypted === '') {
        return '';
    }

    if (!str_starts_with($encrypted, 'v1:')) {
        /*
         * 旧版からの移行用。
         * 旧版は平文保存だったため、
         * 初回読み込み時には平文として扱う。
         */
        return $encrypted;
    }

    $parts = explode(':', $encrypted, 4);

    if (count($parts) !== 4) {
        throw new RuntimeException(
            '保存された機密情報の形式が不正です。'
        );
    }

    $iv = base64_decode(
        $parts[1],
        true
    );

    $tag = base64_decode(
        $parts[2],
        true
    );

    $cipher = base64_decode(
        $parts[3],
        true
    );

    if (
        $iv === false
        || $tag === false
        || $cipher === false
    ) {
        throw new RuntimeException(
            '保存された機密情報を復号できません。'
        );
    }

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        hash(
            'sha256',
            ensure_secret(),
            true
        ),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($plain === false) {
        throw new RuntimeException(
            '保存された機密情報を復号できません。'
        );
    }

    return $plain;
}


/* =========================================================
 * 初期データ
 * ========================================================= */

function default_data(): array
{
    $now = date('Y-m-d H:i:s');

    return [
        'surveys' => [
            [
                'id' => 'survey-001',
                'title' => '顧客満足度アンケート',
                'description' =>
                    'サービスについてのご意見をお聞かせください。',
                'startAt' => date('Y-m-d\TH:i'),
                'endAt' => date(
                    'Y-m-d\TH:i',
                    strtotime('+30 days')
                ),
                'status' => 'draft',
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

        if (
            $raw === false
            || trim($raw) === ''
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
    } catch (Throwable) {
        @fclose($fp);
        return $fallback;
    }
}

function save_json(
    string $file,
    array $data
): void {
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException(
            'データのJSON化に失敗しました。'
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
        $written = 0;

        while ($written < $length) {
            $result = fwrite(
                $fp,
                substr($json, $written)
            );

            if ($result === false) {
                throw new RuntimeException(
                    'データを書き込めません。'
                );
            }

            $written += $result;
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!rename($tmp, $file)) {
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

function load_data(): array
{
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
        DATA_FILE,
        $data
    );
}

function load_settings(): array
{
    $default = default_settings();

    $settings = load_json(
        SETTINGS_FILE,
        $default
    );

    $settings['kintone'] =
        array_replace_recursive(
            $default['kintone'],
            is_array(
                $settings['kintone'] ?? null
            )
                ? $settings['kintone']
                : []
        );

    $settings['mail'] =
        array_replace_recursive(
            $default['mail'],
            is_array(
                $settings['mail'] ?? null
            )
                ? $settings['mail']
                : []
        );

    /*
     * 旧版の平文パスワードにも対応。
     * 読み込み後、保存操作で暗号化形式へ移行する。
     */
    return $settings;
}

function save_settings(
    array $settings
): void {
    save_json(
        SETTINGS_FILE,
        $settings
    );
}


/* =========================================================
 * 設定機密情報
 * ========================================================= */

function kintone_runtime_config(
    array $config
): array {
    $config['password'] =
        decrypt_secret(
            (string)(
                $config['password'] ?? ''
            )
        );

    return $config;
}

function mail_runtime_config(
    array $config
): array {
    $config['password'] =
        decrypt_secret(
            (string)(
                $config['password'] ?? ''
            )
        );

    return $config;
}

function kintone_storage_config(
    array $config
): array {
    if (
        isset($config['password'])
        && (string)$config['password'] !== ''
        && !str_starts_with(
            (string)$config['password'],
            'v1:'
        )
    ) {
        $config['password'] =
            encrypt_secret(
                (string)$config['password']
            );
    }

    return $config;
}

function mail_storage_config(
    array $config
): array {
    if (
        isset($config['password'])
        && (string)$config['password'] !== ''
        && !str_starts_with(
            (string)$config['password'],
            'v1:'
        )
    ) {
        $config['password'] =
            encrypt_secret(
                (string)$config['password']
            );
    }

    return $config;
}


/* =========================================================
 * Flash
 * ========================================================= */

function flash(
    string $type,
    string $message
): void {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_flash(): ?array
{
    $value = $_SESSION['flash'] ?? null;

    unset($_SESSION['flash']);

    return is_array($value)
        ? $value
        : null;
}


/* =========================================================
 * アンケート
 * ========================================================= */

function survey_index(
    array $surveys,
    string $id
): int {
    foreach (
        $surveys as $index => $survey
    ) {
        if (
            (string)($survey['id'] ?? '')
            === $id
        ) {
            return $index;
        }
    }

    return -1;
}

function survey_by_id(
    array $surveys,
    string $id
): ?array {
    $index = survey_index(
        $surveys,
        $id
    );

    return $index >= 0
        ? $surveys[$index]
        : null;
}

function auto_update_status(
    array &$survey
): bool {
    if (
        ($survey['status'] ?? '')
            === 'published'
        && !empty($survey['endAt'])
        && strtotime(
            (string)$survey['endAt']
        ) !== false
        && strtotime(
            (string)$survey['endAt']
        ) < time()
    ) {
        $survey['status'] = 'ended';
        $survey['updatedAt'] =
            date('Y-m-d H:i:s');

        return true;
    }

    return false;
}

function refresh_statuses(
    array &$data
): bool {
    $changed = false;

    foreach (
        $data['surveys'] as &$survey
    ) {
        if (
            auto_update_status($survey)
        ) {
            $changed = true;
        }
    }

    unset($survey);

    return $changed;
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
            $group['questions'] as &$question
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
                    'Q' . $global;
            }

            $global++;
            $questionNo++;
        }

        unset($question);

        $groupNo++;
    }

    unset($group);
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

    $suffix = '.cybozu.com';

    if (
        str_ends_with(
            strtolower($value),
            $suffix
        )
    ) {
        $value = substr(
            $value,
            0,
            -strlen($suffix)
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
            (string)(
                $config['subdomain'] ?? ''
            )
        );

    if (
        $subdomain === ''
        || !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
            $subdomain
        )
    ) {
        $errors[] =
            'kintoneサブドメインが不正です。';
    }

    $appId =
        (string)(
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
        trim(
            (string)(
                $config['username'] ?? ''
            )
        ) === ''
    ) {
        $errors[] =
            'kintoneログイン名を入力してください。';
    }

    if (
        $requirePassword
        && trim(
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
    $config =
        kintone_runtime_config(
            $config
        );

    $errors =
        validate_kintone_config(
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

    $url =
        'https://'
        . $subdomain
        . '.cybozu.com'
        . $path;

    $authorization =
        base64_encode(
            (string)$config['username']
            . ':'
            . (string)$config['password']
        );

    $headers = [
        'X-Cybozu-Authorization: '
            . $authorization,
        'Accept: application/json',
        'Connection: close',
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

        $headers[] =
            'Content-Length: '
            . strlen($content);
    }

    $verify =
        !empty($config['verify_ssl']);

    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode(
                "\r\n",
                $headers
            ),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => HTTP_READ_TIMEOUT,
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

        $options['http']['proxy'] =
            'tcp://'
            . $proxyHost
            . ':'
            . (int)$proxyPort;

        $options['http']['request_fulluri'] =
            true;
    }

    $context =
        stream_context_create(
            $options
        );

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    $status = 0;

    foreach (
        $http_response_header ?? []
        as $header
    ) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})/',
                $header,
                $matches
            )
        ) {
            $status =
                (int)$matches[1];
        }
    }

    if ($response === false) {
        if ($status === 0) {
            throw new RuntimeException(
                'kintoneからレスポンスを取得できませんでした。'
                . ' 接続先、DNS、Proxy、SSL設定を確認してください。'
            );
        }

        throw new RuntimeException(
            'kintone通信に失敗しました。HTTP '
            . $status
        );
    }

    if ($status === 0) {
        throw new RuntimeException(
            'kintoneからHTTPステータスを取得できませんでした。'
        );
    }

    if (
        $status === 302
        || $status === 303
    ) {
        throw new RuntimeException(
            'kintoneからリダイレクト応答 HTTP '
            . $status
            . ' が返されました。'
            . ' API URL・認証方式・Basic認証・ネットワーク設定を確認してください。'
        );
    }

    $json = json_decode(
        $response,
        true
    );

    if (
        $status < 200
        || $status >= 300
    ) {
        $code = '';
        $message = '';

        if (is_array($json)) {
            $code =
                (string)(
                    $json['code'] ?? ''
                );

            $message =
                (string)(
                    $json['message'] ?? ''
                );
        }

        $detail =
            'kintone APIエラー';

        if ($code !== '') {
            $detail .=
                ' [' . $code . ']';
        }

        if ($message !== '') {
            $detail .=
                ' ' . $message;
        }

        $detail .=
            ' HTTP ' . $status;

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
        'raw' => $response,
    ];
}

function kintone_test(
    array $config
): array {
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?id='
        . rawurlencode(
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


/* =========================================================
 * SMTP
 * ========================================================= */

function validate_mail_config(
    array $config
): array {
    $errors = [];

    if (
        trim(
            (string)(
                $config['host'] ?? ''
            )
        ) === ''
    ) {
        $errors[] =
            'SMTPサーバを入力してください。';
    }

    $port =
        (int)($config['port'] ?? 0);

    if (
        $port < 1
        || $port > 65535
    ) {
        $errors[] =
            'SMTPポートが不正です。';
    }

    if (
        !in_array(
            (string)(
                $config['encryption'] ?? ''
            ),
            ['ssl', 'tls', 'none'],
            true
        )
    ) {
        $errors[] =
            '暗号化方式が不正です。';
    }

    if (
        !filter_var(
            (string)(
                $config['from_email'] ?? ''
            ),
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            '送信元メールアドレスが不正です。';
    }

    if (
        !empty($config['auth'])
        && (
            trim(
                (string)(
                    $config['username'] ?? ''
                )
            ) === ''
            || trim(
                (string)(
                    $config['password'] ?? ''
                )
            ) === ''
        )
    ) {
        $errors[] =
            'SMTP認証を使用する場合はユーザー名とパスワードが必要です。';
    }

    return $errors;
}

function smtp_expect(
    $socket,
    array $codes
): string {
    $response = '';

    while (
        ($line = fgets($socket))
        !== false
    ) {
        $response .= $line;

        if (
            preg_match(
                '/^(\d{3})([ -])/',
                $line,
                $matches
            )
        ) {
            if ($matches[2] === ' ') {
                $code =
                    (int)$matches[1];

                if (
                    !in_array(
                        $code,
                        $codes,
                        true
                    )
                ) {
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
    }

    if ($response === '') {
        throw new RuntimeException(
            'SMTPから応答がありません。'
        );
    }

    throw new RuntimeException(
        'SMTPからレスポンスを取得できませんでした。'
    );
}

function smtp_command(
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

    return smtp_expect(
        $socket,
        $codes
    );
}

function smtp_open(
    array $config
) {
    $config =
        mail_runtime_config(
            $config
        );

    $errors =
        validate_mail_config(
            $config
        );

    if ($errors) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    $host =
        trim(
            (string)$config['host']
        );

    $port =
        (int)$config['port'];

    $encryption =
        (string)$config['encryption'];

    $target =
        $encryption === 'ssl'
            ? 'ssl://' . $host
            : $host;

    $errno = 0;
    $errstr = '';

    $socket =
        @stream_socket_client(
            $target . ':' . $port,
            $errno,
            $errstr,
            HTTP_CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

    if (!is_resource($socket)) {
        throw new RuntimeException(
            'SMTP接続失敗: '
            . ($errstr !== ''
                ? $errstr
                : '接続できませんでした。')
            . ' ('
            . $errno
            . ')'
        );
    }

    stream_set_timeout(
        $socket,
        HTTP_READ_TIMEOUT
    );

    smtp_expect(
        $socket,
        [220]
    );

    $ehlo =
        smtp_command(
            $socket,
            'EHLO localhost',
            [250]
        );

    if ($encryption === 'tls') {
        if (
            stripos(
                $ehlo,
                'STARTTLS'
            ) === false
        ) {
            fclose($socket);

            throw new RuntimeException(
                'SMTPサーバがSTARTTLSを提供していません。'
            );
        }

        smtp_command(
            $socket,
            'STARTTLS',
            [220]
        );

        $crypto =
            @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

        if ($crypto !== true) {
            fclose($socket);

            throw new RuntimeException(
                'SMTP STARTTLSを確立できません。'
            );
        }

        smtp_command(
            $socket,
            'EHLO localhost',
            [250]
        );
    }

    if (!empty($config['auth'])) {
        smtp_command(
            $socket,
            'AUTH LOGIN',
            [334]
        );

        smtp_command(
            $socket,
            base64_encode(
                (string)$config['username']
            ),
            [334]
        );

        smtp_command(
            $socket,
            base64_encode(
                (string)$config['password']
            ),
            [235]
        );
    }

    return $socket;
}

function smtp_test(
    array $config
): void {
    $socket =
        smtp_open($config);

    try {
        smtp_command(
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
    $config =
        mail_runtime_config(
            $config
        );

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

    $socket =
        smtp_open($config);

    try {
        smtp_command(
            $socket,
            'MAIL FROM:<'
            . $config['from_email']
            . '>',
            [250]
        );

        smtp_command(
            $socket,
            'RCPT TO:<'
            . $to
            . '>',
            [250, 251]
        );

        smtp_command(
            $socket,
            'DATA',
            [354]
        );

        $fromName =
            trim(
                (string)(
                    $config['from_name'] ?? ''
                )
            );

        $from =
            $fromName !== ''
                ? '=?UTF-8?B?'
                    . base64_encode($fromName)
                    . '?= <'
                    . $config['from_email']
                    . '>'
                : $config['from_email'];

        $headers = [
            'From: ' . $from,
            'To: ' . $to,
            'Subject: =?UTF-8?B?'
                . base64_encode($subject)
                . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if (
            !empty($config['reply_to'])
            && filter_var(
                $config['reply_to'],
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $headers[] =
                'Reply-To: '
                . $config['reply_to'];
        }

        $message =
            implode(
                "\r\n",
                $headers
            )
            . "\r\n\r\n"
            . str_replace(
                ["\r\n", "\r"],
                "\n",
                $body
            );

        $message =
            str_replace(
                "\n",
                "\r\n",
                $message
            );

        /*
         * SMTP DATA終端。
         */
        $message =
            preg_replace(
                '/\r\n\./',
                "\r\n..",
                $message
            )
            ?? $message;

        $message .=
            "\r\n.\r\n";

        if (
            fwrite(
                $socket,
                $message
            ) === false
        ) {
            throw new RuntimeException(
                'SMTPへメール本文を送信できません。'
            );
        }

        smtp_expect(
            $socket,
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


/* =========================================================
 * 入力検証
 * ========================================================= */

function validate_survey_input(): array
{
    $errors = [];

    $title =
        post_string('title');

    $description =
        (string)(
            $_POST['description'] ?? ''
        );

    $startAt =
        post_string('startAt');

    $endAt =
        post_string('endAt');

    $numbering =
        post_string('numbering');

    if ($title === '') {
        $errors[] =
            'アンケートタイトルを入力してください。';
    }

    if (
        mb_strlen($title)
        > MAX_TITLE
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
        && strtotime($endAt)
            < strtotime($startAt)
    ) {
        $errors[] =
            '終了日時は開始日時以降にしてください。';
    }

    if (
        !in_array(
            $numbering,
            ['global', 'group'],
            true
        )
    ) {
        $numbering = 'global';
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

function build_kintone_post_config(
    array $current
): array {
    $password =
        post_string('password');

    /*
     * 接続テストではブラウザへパスワードを返さない。
     * 空欄の場合は保存済み設定をサーバー側で復号する。
     */
    if ($password === '') {
        $password =
            decrypt_secret(
                (string)(
                    $current['password'] ?? ''
                )
            );
    }

    return [
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
}

function build_mail_post_config(
    array $current
): array {
    $password =
        post_string('password');

    if ($password === '') {
        $password =
            decrypt_secret(
                (string)(
                    $current['password'] ?? ''
                )
            );
    }

    return [
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
}


/* =========================================================
 * POST処理
 *
 * 外部通信関数からheader(Location)を実行しない。
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

    /*
     * actionを明示的に許可。
     * 空POSTや未知のactionを
     * 「正常処理」として扱わない。
     */
    $allowed = [
        'save_survey',
        'change_status',
        'duplicate_survey',
        'delete_survey',

        'save_kintone',
        'test_kintone',
        'load_kintone_fields',
        'sync_kintone',

        'save_mail',
        'test_mail',
        'send_test_mail',

        'answer_confirm',
        'answer_back',
        'submit_answer',

        'send_mail',
    ];

    if (
        !in_array(
            $action,
            $allowed,
            true
        )
    ) {
        flash(
            'error',
            '不正なリクエストです。'
            . 'ページを再読み込みしてください。'
        );

        return [
            'screen' =>
                post_string('return_screen')
                ?: 'list',
            'id' =>
                post_string('survey_id'),
        ];
    }

    try {
        switch ($action) {

            /* =================================================
             * アンケート保存
             * ================================================= */

            case 'save_survey':
                $input =
                    validate_survey_input();

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
                            post_string('survey_id'),
                    ];
                }

                $surveyId =
                    post_string('survey_id');

                $index =
                    survey_index(
                        $data['surveys'],
                        $surveyId
                    );

                $groups =
                    $_POST['groups'] ?? [];

                if (!is_array($groups)) {
                    $groups = [];
                }

                $normalizedGroups = [];

                foreach (
                    $groups as $group
                ) {
                    if (!is_array($group)) {
                        continue;
                    }

                    $groupId =
                        trim(
                            (string)(
                                $group['id'] ?? ''
                            )
                        );

                    if ($groupId === '') {
                        $groupId =
                            uuid('group');
                    }

                    $groupTitle =
                        trim(
                            (string)(
                                $group['title'] ?? ''
                            )
                        );

                    if ($groupTitle === '') {
                        $groupTitle =
                            '無題のグループ';
                    }

                    $questions = [];

                    foreach (
                        ($group['questions'] ?? [])
                        as $question
                    ) {
                        if (
                            !is_array($question)
                        ) {
                            continue;
                        }

                        $questionId =
                            trim(
                                (string)(
                                    $question['id'] ?? ''
                                )
                            );

                        if (
                            $questionId === ''
                        ) {
                            $questionId =
                                uuid('question');
                        }

                        $type =
                            (string)(
                                $question['type']
                                ?? 'single'
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
                            $type = 'single';
                        }

                        $options = [];

                        foreach (
                            ($question['options'] ?? [])
                            as $option
                        ) {
                            if (
                                !is_array($option)
                            ) {
                                continue;
                            }

                            $label =
                                trim(
                                    (string)(
                                        $option['label']
                                        ?? ''
                                    )
                                );

                            if ($label === '') {
                                continue;
                            }

                            $options[] = [
                                'id' =>
                                    trim(
                                        (string)(
                                            $option['id']
                                            ?? ''
                                        )
                                    )
                                    ?: uuid('option'),
                                'label' =>
                                    mb_substr(
                                        $label,
                                        0,
                                        MAX_OPTION
                                    ),
                                'nextQuestionId' =>
                                    trim(
                                        (string)(
                                            $option[
                                                'nextQuestionId'
                                            ] ?? ''
                                        )
                                    ),
                            ];
                        }

                        $questions[] = [
                            'id' => $questionId,
                            'number' => '',
                            'text' =>
                                mb_substr(
                                    trim(
                                        (string)(
                                            $question['text']
                                            ?? ''
                                        )
                                    ),
                                    0,
                                    MAX_QUESTION
                                ),
                            'type' => $type,
                            'required' =>
                                !empty(
                                    $question['required']
                                ),
                            'options' => $options,
                        ];
                    }

                    $normalizedGroups[] = [
                        'id' => $groupId,
                        'title' =>
                            mb_substr(
                                $groupTitle,
                                0,
                                MAX_QUESTION
                            ),
                        'questions' => $questions,
                    ];
                }

                if (!$normalizedGroups) {
                    $normalizedGroups[] = [
                        'id' => uuid('group'),
                        'title' => '基本アンケート',
                        'questions' => [],
                    ];
                }

                if ($index >= 0) {
                    $survey =
                        $data['surveys'][$index];

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

                    $survey['groups'] =
                        $normalizedGroups;

                    $survey['updatedAt'] =
                        date('Y-m-d H:i:s');

                    recalc_numbers(
                        $survey
                    );

                    $data['surveys'][$index] =
                        $survey;
                } else {
                    $survey = [
                        'id' =>
                            $surveyId !== ''
                                ? $surveyId
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
                        'groups' =>
                            $normalizedGroups,
                    ];

                    recalc_numbers(
                        $survey
                    );

                    $data['surveys'][] =
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


            /* =================================================
             * 状態変更
             * ================================================= */

            case 'change_status':
                $surveyId =
                    post_string('survey_id');

                $status =
                    post_string('status');

                $index =
                    survey_index(
                        $data['surveys'],
                        $surveyId
                    );

                if ($index < 0) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
                }

                $current =
                    (string)(
                        $data['surveys'][$index]
                            ['status']
                        ?? 'draft'
                    );

                $valid = (
                    ($current === 'draft'
                        && $status === 'published')
                    || (
                        $current === 'published'
                        && $status === 'stopped'
                    )
                    || (
                        $current === 'stopped'
                        && $status === 'published'
                    )
                );

                if (!$valid) {
                    throw new RuntimeException(
                        '指定された状態変更は許可されていません。'
                    );
                }

                $data['surveys'][$index]
                    ['status'] = $status;

                $data['surveys'][$index]
                    ['updatedAt'] =
                    date('Y-m-d H:i:s');

                save_data($data);

                flash(
                    'success',
                    'アンケート状態を変更しました。'
                );

                return [
                    'screen' => 'list',
                ];


            /* =================================================
             * 複製
             * ================================================= */

            case 'duplicate_survey':
                $surveyId =
                    post_string('survey_id');

                $survey =
                    survey_by_id(
                        $data['surveys'],
                        $surveyId
                    );

                if ($survey === null) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
                }

                $survey['id'] =
                    uuid('survey');

                $survey['title'] =
                    (string)$survey['title']
                    . '（コピー）';

                $survey['status'] =
                    'draft';

                $survey['createdAt'] =
                    date('Y-m-d H:i:s');

                $survey['updatedAt'] =
                    date('Y-m-d H:i:s');

                foreach (
                    $survey['groups']
                    as &$group
                ) {
                    $group['id'] =
                        uuid('group');

                    foreach (
                        $group['questions']
                        as &$question
                    ) {
                        $question['id'] =
                            uuid('question');

                        foreach (
                            $question['options']
                            as &$option
                        ) {
                            $option['id'] =
                                uuid('option');
                        }

                        unset($option);
                    }

                    unset($question);
                }

                unset($group);

                recalc_numbers(
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


            /* =================================================
             * 削除
             * ================================================= */

            case 'delete_survey':
                $surveyId =
                    post_string('survey_id');

                $index =
                    survey_index(
                        $data['surveys'],
                        $surveyId
                    );

                if ($index < 0) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
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


            /* =================================================
             * kintone設定保存
             * ================================================= */

            case 'save_kintone':
                $current =
                    $settings['kintone'];

                $config =
                    build_kintone_post_config(
                        $current
                    );

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
                        'screen' => 'kintone',
                    ];
                }

                $config =
                    kintone_storage_config(
                        $config
                    );

                $settings['kintone'] =
                    array_replace(
                        $current,
                        $config
                    );

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


            /* =================================================
             * kintone接続テスト
             *
             * 保存処理とは完全分離。
             * ================================================= */

            case 'test_kintone':
                $current =
                    $settings['kintone'];

                /*
                 * テストはPOSTされた認証情報を
                 * 保存し直す処理ではない。
                 *
                 * 保存済み設定をサーバー側から取得する。
                 */
                $config =
                    kintone_runtime_config(
                        $current
                    );

                $errors =
                    validate_kintone_config(
                        $config,
                        true
                    );

                if ($errors) {
                    throw new RuntimeException(
                        implode(
                            "\n",
                            $errors
                        )
                    );
                }

                $result =
                    kintone_test(
                        $config
                    );

                $settings['kintone']
                    ['last_test'] =
                    date('Y-m-d H:i:s');

                save_settings(
                    $settings
                );

                flash(
                    'success',
                    'kintone接続成功。HTTP '
                    . $result['status']
                );

                return [
                    'screen' => 'kintone',
                ];


            /* =================================================
             * kintone項目取得
             * ================================================= */

            case 'load_kintone_fields':
                $config =
                    kintone_runtime_config(
                        $settings['kintone']
                    );

                $result =
                    kintone_fields(
                        $config
                    );

                $fields =
                    $result['body']['properties']
                    ?? [];

                if (!is_array($fields)) {
                    $fields = [];
                }

                $settings['kintone']
                    ['fields'] =
                    $fields;

                save_settings(
                    $settings
                );

                flash(
                    'success',
                    'kintone項目一覧を取得しました。'
                    . ' '
                    . count($fields)
                    . '項目'
                );

                return [
                    'screen' => 'kintone',
                ];


            /* =================================================
             * kintone顧客同期
             * ================================================= */

            case 'sync_kintone':
                $config =
                    kintone_runtime_config(
                        $settings['kintone']
                    );

                $mapping =
                    $settings['kintone']
                        ['mapping']
                        ?? [];

                $result =
                    kintone_records(
                        $config
                    );

                $records =
                    $result['body']['records']
                    ?? [];

                if (!is_array($records)) {
                    $records = [];
                }

                $customers = [];

                foreach (
                    $records as $record
                ) {
                    if (!is_array($record)) {
                        continue;
                    }

                    $nameCode =
                        (string)(
                            $mapping['name'] ?? ''
                        );

                    $emailCode =
                        (string)(
                            $mapping['email'] ?? ''
                        );

                    $organizationCode =
                        (string)(
                            $mapping['organization']
                            ?? ''
                        );

                    $departmentCode =
                        (string)(
                            $mapping['department']
                            ?? ''
                        );

                    $phoneCode =
                        (string)(
                            $mapping['phone'] ?? ''
                        );

                    $name =
                        $nameCode !== ''
                            ? (string)(
                                $record[
                                    $nameCode
                                ]['value']
                                ?? ''
                            )
                            : '';

                    $email =
                        $emailCode !== ''
                            ? (string)(
                                $record[
                                    $emailCode
                                ]['value']
                                ?? ''
                            )
                            : '';

                    if (
                        !filter_var(
                            $email,
                            FILTER_VALIDATE_EMAIL
                        )
                    ) {
                        continue;
                    }

                    $customers[] = [
                        'id' =>
                            uuid('customer'),
                        'kintone_record_id' =>
                            (string)(
                                $record[
                                    '$id'
                                ]['value']
                                ?? ''
                            ),
                        'name' => $name,
                        'email' => $email,
                        'organization' =>
                            $organizationCode !== ''
                                ? (string)(
                                    $record[
                                        $organizationCode
                                    ]['value']
                                    ?? ''
                                )
                                : '',
                        'department' =>
                            $departmentCode !== ''
                                ? (string)(
                                    $record[
                                        $departmentCode
                                    ]['value']
                                    ?? ''
                                )
                                : '',
                        'phone' =>
                            $phoneCode !== ''
                                ? (string)(
                                    $record[
                                        $phoneCode
                                    ]['value']
                                    ?? ''
                                )
                                : '',
                    ];
                }

                $data['customers'] =
                    $customers;

                $settings['kintone']
                    ['last_sync'] =
                    date('Y-m-d H:i:s');

                save_data($data);
                save_settings($settings);

                flash(
                    'success',
                    '顧客情報を同期しました。'
                    . count($customers)
                    . '件'
                );

                return [
                    'screen' => 'kintone',
                ];


            /* =================================================
             * SMTP設定保存
             * ================================================= */

            case 'save_mail':
                $current =
                    $settings['mail'];

                $config =
                    build_mail_post_config(
                        $current
                    );

                $errors =
                    validate_mail_config(
                        $config
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
                        'screen' => 'mail',
                    ];
                }

                $config =
                    mail_storage_config(
                        $config
                    );

                $settings['mail'] =
                    $config;

                save_settings(
                    $settings
                );

                flash(
                    'success',
                    'SMTP設定を保存しました。'
                );

                return [
                    'screen' => 'mail',
                ];


            /* =================================================
             * SMTP接続テスト
             * ================================================= */

            case 'test_mail':
                $config =
                    mail_runtime_config(
                        $settings['mail']
                    );

                smtp_test(
                    $config
                );

                $settings['mail']
                    ['last_test'] =
                    date('Y-m-d H:i:s');

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


            /* =================================================
             * SMTPテストメール
             * ================================================= */

            case 'send_test_mail':
                $to =
                    post_string('test_email');

                if (
                    !filter_var(
                        $to,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    throw new RuntimeException(
                        'テスト送信先メールアドレスが不正です。'
                    );
                }

                $config =
                    mail_runtime_config(
                        $settings['mail']
                    );

                $subject =
                    'アンケートアプリ SMTPテストメール';

                $body =
                    'このメールはアンケートアプリの'
                    . 'SMTPテスト送信です。'
                    . "\n\n"
                    . '送信日時: '
                    . date('Y-m-d H:i:s');

                smtp_send(
                    $config,
                    $to,
                    $subject,
                    $body
                );

                flash(
                    'success',
                    'テストメールを送信しました。'
                );

                return [
                    'screen' => 'mail',
                ];


            /* =================================================
             * 回答確認
             * ================================================= */

            case 'answer_confirm':
                $surveyId =
                    post_string('survey_id');

                $survey =
                    survey_by_id(
                        $data['surveys'],
                        $surveyId
                    );

                if ($survey === null) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                if (
                    ($survey['status'] ?? '')
                    !== 'published'
                ) {
                    throw new RuntimeException(
                        'このアンケートは現在回答できません。'
                    );
                }

                $answers =
                    $_POST['answer'] ?? [];

                if (!is_array($answers)) {
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
                            !empty(
                                $question['required']
                            )
                            && (
                                !isset(
                                    $answers[
                                        $question['id']
                                    ]
                                )
                                || (
                                    is_string(
                                        $answers[
                                            $question['id']
                                        ]
                                    )
                                    && trim(
                                        $answers[
                                            $question['id']
                                        ]
                                    ) === ''
                                )
                                || (
                                    is_array(
                                        $answers[
                                            $question['id']
                                        ]
                                    )
                                    && count(
                                        $answers[
                                            $question['id']
                                        ]
                                    ) === 0
                                )
                            )
                        ) {
                            throw new RuntimeException(
                                '必須項目に未回答があります。'
                            );
                        }
                    }
                }

                $_SESSION[
                    'answer_draft'
                ] = $answers;

                return [
                    'screen' => 'confirm',
                    'id' => $surveyId,
                ];


            /* =================================================
             * 回答修正
             * ================================================= */

            case 'answer_back':
                return [
                    'screen' => 'answer',
                    'id' =>
                        post_string(
                            'survey_id'
                        ),
                ];


            /* =================================================
             * 回答送信
             * ================================================= */

            case 'submit_answer':
                $surveyId =
                    post_string('survey_id');

                $survey =
                    survey_by_id(
                        $data['surveys'],
                        $surveyId
                    );

                if ($survey === null) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                $draft =
                    $_SESSION[
                        'answer_draft'
                    ] ?? [];

                if (!is_array($draft)) {
                    $draft = [];
                }

                $data['answers'][] = [
                    'id' =>
                        uuid('answer'),
                    'survey_id' =>
                        $surveyId,
                    'answers' =>
                        $draft,
                    'createdAt' =>
                        date('Y-m-d H:i:s'),
                ];

                save_data($data);

                unset(
                    $_SESSION[
                        'answer_draft'
                    ]
                );

                return [
                    'screen' => 'complete',
                    'id' => $surveyId,
                ];


            /* =================================================
             * メール送信
             * ================================================= */

            case 'send_mail':
                $surveyId =
                    post_string('survey_id');

                $survey =
                    survey_by_id(
                        $data['surveys'],
                        $surveyId
                    );

                if ($survey === null) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
                }

                $customerIds =
                    $_POST['customer_ids']
                    ?? [];

                if (
                    !is_array($customerIds)
                ) {
                    $customerIds = [];
                }

                if (!$customerIds) {
                    throw new RuntimeException(
                        '送信対象の顧客を選択してください。'
                    );
                }

                $subject =
                    mb_substr(
                        post_string('subject'),
                        0,
                        MAX_MAIL_SUBJECT
                    );

                $body =
                    (string)(
                        $_POST['body'] ?? ''
                    );

                if ($subject === '') {
                    throw new RuntimeException(
                        'メール件名を入力してください。'
                    );
                }

                if (
                    trim($body) === ''
                ) {
                    throw new RuntimeException(
                        'メール本文を入力してください。'
                    );
                }

                $config =
                    mail_runtime_config(
                        $settings['mail']
                    );

                $results = [];

                foreach (
                    $data['customers']
                    as $customer
                ) {
                    $customerId =
                        (string)(
                            $customer['id']
                            ?? ''
                        );

                    if (
                        !in_array(
                            $customerId,
                            array_map(
                                'strval',
                                $customerIds
                            ),
                            true
                        )
                    ) {
                        continue;
                    }

                    $name =
                        (string)(
                            $customer['name']
                            ?? ''
                        );

                    $personalSubject =
                        str_replace(
                            '{顧客名}',
                            $name,
                            $subject
                        );

                    $personalBody =
                        str_replace(
                            '{顧客名}',
                            $name,
                            $body
                        );

                    $personalBody =
                        str_replace(
                            '{アンケートURL}',
                            public_answer_url(
                                $surveyId
                            ),
                            $personalBody
                        );

                    try {
                        smtp_send(
                            $config,
                            (string)$customer['email'],
                            $personalSubject,
                            $personalBody
                        );

                        $results[] = [
                            'customer_id' =>
                                $customerId,
                            'email' =>
                                $customer['email'],
                            'status' =>
                                'success',
                            'message' =>
                                '送信成功',
                        ];

                        $data['send_history'][] = [
                            'id' =>
                                uuid('send'),
                            'survey_id' =>
                                $surveyId,
                            'customer_id' =>
                                $customerId,
                            'email' =>
                                $customer['email'],
                            'status' =>
                                'success',
                            'createdAt' =>
                                date('Y-m-d H:i:s'),
                        ];
                    } catch (Throwable $e) {
                        $results[] = [
                            'customer_id' =>
                                $customerId,
                            'email' =>
                                $customer['email'],
                            'status' =>
                                'error',
                            'message' =>
                                $e->getMessage(),
                        ];

                        $data['send_history'][] = [
                            'id' =>
                                uuid('send'),
                            'survey_id' =>
                                $surveyId,
                            'customer_id' =>
                                $customerId,
                            'email' =>
                                $customer['email'],
                            'status' =>
                                'error',
                            'message' =>
                                $e->getMessage(),
                            'createdAt' =>
                                date('Y-m-d H:i:s'),
                        ];
                    }
                }

                save_data($data);

                $_SESSION[
                    'send_results'
                ] = $results;

                return [
                    'screen' => 'send',
                    'id' => $surveyId,
                ];
        }

        /*
         * switchに到達しなかった場合。
         */
        throw new RuntimeException(
            '不正なリクエストです。'
            . 'ページを再読み込みしてください。'
        );

    } catch (Throwable $e) {
        $screen =
            post_string(
                'return_screen'
            );

        $id =
            post_string(
                'survey_id'
            );

        if ($screen === '') {
            $screen = 'list';
        }

        if (
            in_array(
                $action,
                [
                    'answer_confirm',
                    'answer_back',
                    'submit_answer',
                ],
                true
            )
        ) {
            $screen =
                $action === 'submit_answer'
                    ? 'confirm'
                    : 'answer';
        }

        flash(
            'error',
            '処理に失敗しました：'
            . $e->getMessage()
        );

        return [
            'screen' => $screen,
            'id' => $id,
        ];
    }
}


/* =========================================================
 * HTML
 * ========================================================= */

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
 --border:#dbe2ea;
 --text:#1e293b;
 --white:#fff;
 --bg:#f8fafc;
 --shadow:0 4px 18px rgba(15,23,42,.08);
}
*{box-sizing:border-box}
html,body{
 margin:0;
 padding:0;
 background:var(--bg);
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
 margin:auto;
}
.page{padding:28px 0 60px}
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
 justify-content:space-between;
 align-items:flex-start;
 gap:16px;
 margin-bottom:22px;
}
.page-title h1{
 margin:0 0 6px;
 font-size:26px;
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
 overflow:hidden;
}
.card-header{
 padding:16px 20px;
 border-bottom:1px solid var(--border);
}
.card-header h2{
 margin:0;
 font-size:17px;
}
.card-body{padding:20px}
.grid{
 display:grid;
 gap:18px;
}
.grid-2{
 grid-template-columns:
 repeat(2,minmax(0,1fr));
}
.grid-3{
 grid-template-columns:
 repeat(3,minmax(0,1fr));
}
label>span,
.field-label{
 display:block;
 font-weight:600;
 margin-bottom:7px;
}
input,
textarea,
select{
 width:100%;
 border:1px solid #cbd5e1;
 border-radius:8px;
 padding:10px 12px;
 background:#fff;
 color:var(--text);
 font:inherit;
}
input:focus,
textarea:focus,
select:focus{
 outline:2px solid rgba(37,99,235,.18);
 border-color:var(--primary);
}
textarea{
 min-height:130px;
 resize:vertical;
}
.button-row{
 display:flex;
 flex-wrap:wrap;
 gap:8px;
 align-items:center;
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
 cursor:pointer;
 text-decoration:none;
}
.btn:disabled{
 opacity:.5;
 cursor:not-allowed;
}
.btn-primary{
 background:var(--primary);
 color:#fff;
}
.btn-primary:hover{
 background:var(--primary-dark);
}
.btn-secondary{
 background:#fff;
 border-color:#cbd5e1;
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
.alert{
 border-radius:9px;
 padding:12px 14px;
 margin-bottom:18px;
 white-space:pre-line;
}
.alert-success{
 background:#dcfce7;
 color:#166534;
 border:1px solid #bbf7d0;
}
.alert-warning{
 background:#fef3c7;
 color:#92400e;
 border:1px solid #fde68a;
}
.alert-error{
 background:#fee2e2;
 color:#991b1b;
 border:1px solid #fecaca;
}
.alert-info{
 background:#dbeafe;
 color:#1e40af;
 border:1px solid #bfdbfe;
}
.badge{
 display:inline-block;
 padding:4px 8px;
 border-radius:999px;
 font-size:12px;
 font-weight:600;
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
.table-wrap{overflow-x:auto}
table{
 width:100%;
 border-collapse:collapse;
 min-width:900px;
}
th,td{
 padding:12px;
 border-bottom:1px solid var(--border);
 text-align:left;
 vertical-align:middle;
}
th{
 background:#f8fafc;
 white-space:nowrap;
}
.group-card,
.question-card{
 border:1px solid var(--border);
 border-radius:10px;
 background:#fff;
 margin-bottom:14px;
}
.group-header,
.question-header{
 padding:12px 14px;
 background:#f8fafc;
 border-bottom:1px solid var(--border);
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:10px;
}
.group-body,
.question-body{padding:14px}
.drag-handle{
 cursor:grab;
 user-select:none;
 color:var(--gray);
 font-size:18px;
}
.option-row{
 display:grid;
 grid-template-columns:1fr auto;
 gap:8px;
 margin-bottom:8px;
}
.help{
 color:var(--gray);
 font-size:13px;
}
.answer-shell{
 width:min(900px,calc(100% - 28px));
 margin:28px auto 60px;
}
.preview-question{
 border-bottom:1px solid var(--border);
 padding:14px 0;
}
.preview-question:last-child{
 border-bottom:0;
}
.empty{
 padding:35px;
 text-align:center;
 color:var(--gray);
}
.status-line{
 padding:10px 12px;
 background:#f8fafc;
 border:1px solid var(--border);
 border-radius:8px;
 margin-bottom:12px;
}
@media(max-width:800px){
 .grid-2,.grid-3{
  grid-template-columns:1fr;
 }
 .admin-header-inner{
  align-items:flex-start;
  flex-direction:column;
  padding:14px 0;
 }
 .page-title{
  flex-direction:column;
 }
 .button-row .btn{
  width:100%;
 }
 .answer-shell{
  width:min(100% - 20px,900px);
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

function render_footer(): void
{
?>
<script>
(function(){
'use strict';

document.addEventListener(
 'submit',
 function(e){
  var form=e.target;

  if(!form.matches('form')){
   return;
  }

  var message=form.getAttribute(
   'data-confirm'
  );

  if(message){
   if(!window.confirm(message)){
    e.preventDefault();
    return;
   }
  }

  var buttons=form.querySelectorAll(
   'button[type="submit"],button:not([type])'
  );

  buttons.forEach(function(button){
   button.disabled=true;
  });
 },
 true
);

})();
</script>
</body>
</html>
<?php
}


/* =========================================================
 * 一覧
 * ========================================================= */

function render_list(
    array $data
): void {
    $flash =
        consume_flash();

    render_head(
        'アンケート一覧'
    );
?>
<div class="page">
<div class="container">

<?php if ($flash): ?>
<div class="alert alert-<?= h(
    $flash['type'] === 'success'
        ? 'success'
        : (
            $flash['type'] === 'warning'
                ? 'warning'
                : 'error'
        )
) ?>">
<?= h($flash['message']) ?>
</div>
<?php endif; ?>

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
新規作成
</a>
</div>

<div class="card">
<div class="card-body">
<form method="get">
<input type="hidden"
       name="screen"
       value="list">

<div class="grid grid-3">
<label>
<span>検索</span>
<input type="search"
       name="q"
       value="<?= h(get_string('q')) ?>"
       placeholder="タイトルを検索">
</label>

<label>
<span>ステータス</span>
<select name="status">
<option value="">すべて</option>
<option value="published"
 <?= get_string('status') === 'published'
 ? 'selected' : '' ?>>
公開中
</option>
<option value="draft"
 <?= get_string('status') === 'draft'
 ? 'selected' : '' ?>>
下書き
</option>
<option value="stopped"
 <?= get_string('status') === 'stopped'
 ? 'selected' : '' ?>>
停止
</option>
<option value="ended"
 <?= get_string('status') === 'ended'
 ? 'selected' : '' ?>>
終了
</option>
</select>
</label>

<label>
<span>ソート</span>
<select name="sort">
<?php
$sort = get_string('sort');
if ($sort === '') {
    $sort = 'updated_desc';
}
?>
<option value="updated_desc"
 <?= $sort === 'updated_desc'
 ? 'selected' : '' ?>>
更新日：新しい順
</option>
<option value="updated_asc"
 <?= $sort === 'updated_asc'
 ? 'selected' : '' ?>>
更新日：古い順
</option>
<option value="answers_desc"
 <?= $sort === 'answers_desc'
 ? 'selected' : '' ?>>
回答数：多い順
</option>
<option value="answers_asc"
 <?= $sort === 'answers_asc'
 ? 'selected' : '' ?>>
回答数：少ない順
</option>
<option value="start_desc"
 <?= $sort === 'start_desc'
 ? 'selected' : '' ?>>
開始日：新しい順
</option>
<option value="start_asc"
 <?= $sort === 'start_asc'
 ? 'selected' : '' ?>>
開始日：古い順
</option>
</select>
</label>
</div>

<div class="button-row"
     style="margin-top:14px">
<button class="btn btn-primary"
        type="submit">
検索
</button>
</div>
</form>
</div>
</div>

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

<?php
$q = get_string('q');
$statusFilter = get_string('status');

$surveys = $data['surveys'];

usort(
    $surveys,
    function(array $a, array $b) use ($sort): int {
        $answersA = 0;
        $answersB = 0;

        foreach (
            $GLOBALS['_list_data']['answers'] ?? []
            as $answer
        ) {
            if (
                ($answer['survey_id'] ?? '')
                === ($a['id'] ?? '')
            ) {
                $answersA++;
            }

            if (
                ($answer['survey_id'] ?? '')
                === ($b['id'] ?? '')
            ) {
                $answersB++;
            }
        }

        return match ($sort) {
            'updated_asc' =>
                strcmp(
                    (string)($a['updatedAt'] ?? ''),
                    (string)($b['updatedAt'] ?? '')
                ),
            'answers_desc' =>
                $answersB <=> $answersA,
            'answers_asc' =>
                $answersA <=> $answersB,
            'start_desc' =>
                strcmp(
                    (string)($b['startAt'] ?? ''),
                    (string)($a['startAt'] ?? '')
                ),
            'start_asc' =>
                strcmp(
                    (string)($a['startAt'] ?? ''),
                    (string)($b['startAt'] ?? '')
                ),
            default =>
                strcmp(
                    (string)($b['updatedAt'] ?? ''),
                    (string)($a['updatedAt'] ?? '')
                ),
        };
    }
);

foreach ($surveys as $survey):
    if (
        $q !== ''
        && mb_stripos(
            (string)($survey['title'] ?? ''),
            $q
        ) === false
    ) {
        continue;
    }

    if (
        $statusFilter !== ''
        && ($survey['status'] ?? '')
            !== $statusFilter
    ) {
        continue;
    }

    $answerCount = 0;

    foreach (
        $data['answers'] as $answer
    ) {
        if (
            ($answer['survey_id'] ?? '')
            === ($survey['id'] ?? '')
        ) {
            $answerCount++;
        }
    }

    $status =
        (string)(
            $survey['status'] ?? 'draft'
        );
?>
<tr>
<td>
<strong><?= h(
    $survey['title'] ?? ''
) ?></strong>
</td>
<td><?= h(
    $survey['createdAt'] ?? ''
) ?></td>
<td><?= h(
    $survey['updatedAt'] ?? ''
) ?></td>
<td>
<?= h($survey['startAt'] ?? '') ?>
～
<?= h($survey['endAt'] ?? '') ?>
</td>
<td>
<span class="badge badge-<?= h(
    status_class($status)
) ?>">
<?= h(status_label($status)) ?>
</span>
</td>
<td><?= h($answerCount) ?></td>
<td>
<div class="button-row">

<a class="btn btn-secondary"
 href="<?= h(app_url([
     'screen'=>'edit',
     'id'=>$survey['id']
 ])) ?>">
確認・編集
</a>

<a class="btn btn-secondary"
 href="<?= h(app_url([
     'screen'=>'preview',
     'id'=>$survey['id']
 ])) ?>">
プレビュー
</a>

<a class="btn btn-secondary"
 href="<?= h(app_url([
     'screen'=>'analytics',
     'id'=>$survey['id']
 ])) ?>">
集計
</a>

<a class="btn btn-secondary"
 href="<?= h(app_url([
     'screen'=>'send',
     'id'=>$survey['id']
 ])) ?>">
送信
</a>

<form method="post"
      style="display:inline"
      data-confirm="このアンケートを複製しますか？">
<input type="hidden"
       name="action"
       value="duplicate_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden"
       name="return_screen"
       value="list">
<button class="btn btn-secondary">
複製
</button>
</form>

<form method="post"
      style="display:inline"
      data-confirm="このアンケートを削除しますか？">
<input type="hidden"
       name="action"
       value="delete_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden"
       name="return_screen"
       value="list">
<button class="btn btn-danger">
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
    render_footer();
}


/* =========================================================
 * kintone設定
 * ========================================================= */

function render_kintone(
    array $config
): void {
    $flash =
        consume_flash();

    render_head(
        'kintone設定'
    );
?>
<div class="page">
<div class="container">

<?php if ($flash): ?>
<div class="alert alert-<?= h(
    $flash['type'] === 'success'
        ? 'success'
        : 'error'
) ?>">
<?= h($flash['message']) ?>
</div>
<?php endif; ?>

<div class="page-title">
<div>
<h1>kintone連携設定</h1>
<p>設定・接続テスト・項目取得・顧客同期を独立して実行します。</p>
</div>
</div>

<div class="card">
<div class="card-body">

<form method="post">
<input type="hidden"
       name="action"
       value="save_kintone">
<input type="hidden"
       name="return_screen"
       value="kintone">

<div class="grid grid-2">

<label>
<span>サブドメイン</span>
<input name="subdomain"
       value="<?= h(
           normalize_kintone_subdomain(
               (string)(
                   $config['subdomain'] ?? ''
               )
           )
       ) ?>"
       placeholder="xxxx.cybozu.com"
       required>
</label>

<label>
<span>顧客管理アプリID</span>
<input name="app_id"
       inputmode="numeric"
       value="<?= h(
           $config['app_id'] ?? ''
       ) ?>"
       required>
</label>

<label>
<span>ログイン名</span>
<input name="username"
       value="<?= h(
           $config['username'] ?? ''
       ) ?>"
       required>
</label>

<label>
<span>パスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</label>

<label>
<span>Proxy</span>
<input name="proxy"
       value="<?= h(
           $config['proxy'] ?? ''
       ) ?>"
       placeholder="host:port">
</label>

<label>
<span>SSL証明書検証</span>
<select name="verify_ssl">
<option value="0"
 <?= empty($config['verify_ssl'])
 ? 'selected' : '' ?>>
無効
</option>
<option value="1"
 <?= !empty($config['verify_ssl'])
 ? 'selected' : '' ?>>
有効
</option>
</select>
</label>

</div>

<div class="button-row"
     style="margin-top:18px">
<button class="btn btn-primary">
設定保存
</button>
</div>

</form>

<hr style="
margin:25px 0;
border:0;
border-top:1px solid var(--border)">

<div class="status-line">
<strong>接続テスト</strong><br>
<?php if (!empty($config['last_test'])): ?>
最終成功:
<?= h($config['last_test']) ?>
<?php else: ?>
未実施
<?php endif; ?>
</div>

<form method="post"
      style="display:inline"
      data-confirm="kintoneへ実際に接続して認証テストを実行します。よろしいですか？">
<input type="hidden"
       name="action"
       value="test_kintone">
<input type="hidden"
       name="return_screen"
       value="kintone">
<button class="btn btn-secondary">
接続テスト
</button>
</form>

<form method="post"
      style="display:inline">
<input type="hidden"
       name="action"
       value="load_kintone_fields">
<input type="hidden"
       name="return_screen"
       value="kintone">
<button class="btn btn-secondary">
項目一覧を再取得
</button>
</form>

<form method="post"
      style="display:inline"
      data-confirm="kintoneの顧客情報を取得して同期します。よろしいですか？">
<input type="hidden"
       name="action"
       value="sync_kintone">
<input type="hidden"
       name="return_screen"
       value="kintone">
<button class="btn btn-secondary">
顧客情報を同期
</button>
</form>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>項目マッピング</h2>
</div>
<div class="card-body">

<p class="help">
「項目一覧を再取得」でkintoneアプリのフィールドを取得した後、
顧客情報の各項目へ割り当ててください。
</p>

<form method="post">
<input type="hidden"
       name="action"
       value="save_kintone">
<input type="hidden"
       name="return_screen"
       value="kintone">

<?php
$fieldList =
    $config['fields'] ?? [];

if (!is_array($fieldList)) {
    $fieldList = [];
}

$mapping =
    $config['mapping'] ?? [];

$selectField = function(
    string $name,
    string $label
) use (
    $fieldList,
    $mapping
): void {
?>
<label style="display:block;margin-bottom:16px">
<span><?= h($label) ?></span>
<select name="mapping[<?= h($name) ?>]">
<option value="">未設定</option>
<?php foreach (
    $fieldList as $code => $field
):
    if (!is_array($field)) {
        continue;
    }

    $fieldName =
        (string)(
            $field['label']
            ?? $code
        );
?>
<option value="<?= h($code) ?>"
 <?= (
     (string)(
         $mapping[$name] ?? ''
     )
     === (string)$code
 )
 ? 'selected'
 : '' ?>>
<?= h($fieldName) ?>
（<?= h($code) ?>）
</option>
<?php endforeach; ?>
</select>
</label>
<?php
};
?>

<?php
$selectField(
    'organization',
    '組織名'
);

$selectField(
    'name',
    '氏名'
);

$selectField(
    'email',
    'メールアドレス'
);

$selectField(
    'department',
    '部署名'
);

$selectField(
    'phone',
    '電話番号'
);
?>

<button class="btn btn-primary">
設定保存
</button>

</form>

</div>
</div>

</div>
</div>
<?php
    render_footer();
}


/* =========================================================
 * SMTP設定
 * ========================================================= */

function render_mail(
    array $config
): void {
    $flash =
        consume_flash();

    render_head(
        'メールサーバ設定'
    );
?>
<div class="page">
<div class="container">

<?php if ($flash): ?>
<div class="alert alert-<?= h(
    $flash['type'] === 'success'
        ? 'success'
        : 'error'
) ?>">
<?= h($flash['message']) ?>
</div>
<?php endif; ?>

<div class="page-title">
<div>
<h1>メールサーバ設定</h1>
<p>SMTP設定・接続認証・テストメール送信を分離しています。</p>
</div>
</div>

<div class="card">
<div class="card-body">

<form method="post">
<input type="hidden"
       name="action"
       value="save_mail">
<input type="hidden"
       name="return_screen"
       value="mail">

<div class="grid grid-2">

<label>
<span>SMTPサーバ</span>
<input name="server"
       value="<?= h(
           $config['host'] ?? ''
       ) ?>"
       required>
</label>

<label>
<span>SMTPポート</span>
<input name="port"
       type="number"
       min="1"
       max="65535"
       value="<?= h(
           $config['port'] ?? 587
       ) ?>"
       required>
</label>

<label>
<span>暗号化方式</span>
<select name="encryption">
<option value="tls"
 <?= ($config['encryption'] ?? '')
 === 'tls'
 ? 'selected' : '' ?>>
TLS
</option>
<option value="ssl"
 <?= ($config['encryption'] ?? '')
 === 'ssl'
 ? 'selected' : '' ?>>
SSL
</option>
<option value="none"
 <?= ($config['encryption'] ?? '')
 === 'none'
 ? 'selected' : '' ?>>
なし
</option>
</select>
</label>

<label>
<span>SMTP認証</span>
<select name="auth">
<option value="1"
 <?= !empty($config['auth'])
 ? 'selected' : '' ?>>
使用する
</option>
<option value="0"
 <?= empty($config['auth'])
 ? 'selected' : '' ?>>
使用しない
</option>
</select>
</label>

<label>
<span>SMTPユーザー名</span>
<input name="username"
       value="<?= h(
           $config['username'] ?? ''
       ) ?>">
</label>

<label>
<span>SMTPパスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</label>

<label>
<span>送信元メールアドレス</span>
<input type="email"
       name="from_email"
       value="<?= h(
           $config['from_email'] ?? ''
       ) ?>"
       required>
</label>

<label>
<span>送信元名</span>
<input name="from_name"
       value="<?= h(
           $config['from_name'] ?? ''
       ) ?>">
</label>

<label>
<span>返信先メールアドレス</span>
<input type="email"
       name="reply_to"
       value="<?= h(
           $config['reply_to'] ?? ''
       ) ?>">
</label>

</div>

<div class="button-row"
     style="margin-top:18px">
<button class="btn btn-primary">
設定保存
</button>
</div>

</form>

<hr style="
margin:25px 0;
border:0;
border-top:1px solid var(--border)">

<div class="status-line">
<strong>接続状態</strong><br>
<?php if (!empty($config['last_test'])): ?>
接続確認済み：
<?= h($config['last_test']) ?>
<?php else: ?>
未確認
<?php endif; ?>
</div>

<form method="post"
      style="display:inline"
      data-confirm="SMTPサーバへ接続して認証まで実行します。よろしいですか？">
<input type="hidden"
       name="action"
       value="test_mail">
<input type="hidden"
       name="return_screen"
       value="mail">
<button class="btn btn-secondary">
接続テスト
</button>
</form>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>テストメール送信</h2>
</div>
<div class="card-body">

<p class="help">
接続テストとは別に、実際のSMTPサーバを使用して
テストメールを送信できます。
</p>

<form method="post"
      data-confirm="実際にテストメールを送信します。よろしいですか？">

<input type="hidden"
       name="action"
       value="send_test_mail">

<input type="hidden"
       name="return_screen"
       value="mail">

<label>
<span>テスト送信先</span>
<input type="email"
       name="test_email"
       required>
</label>

<div class="button-row"
     style="margin-top:14px">
<button class="btn btn-secondary">
テストメール送信
</button>
</div>

</form>

</div>
</div>

</div>
</div>
<?php
    render_footer();
}


/* =========================================================
 * 編集
 * ========================================================= */

function render_edit(
    array $survey
): void {
    $flash =
        consume_flash();

    render_head(
        'アンケート編集'
    );
?>
<div class="page">
<div class="container">

<?php if ($flash): ?>
<div class="alert alert-error">
<?= h($flash['message']) ?>
</div>
<?php endif; ?>

<div class="page-title">
<div>
<h1>アンケート作成・編集</h1>
<p><?= h($survey['title'] ?? '') ?></p>
</div>

<div class="button-row">
<a class="btn btn-secondary"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
キャンセル
</a>
</div>
</div>

<div class="card">
<div class="card-body">

<form method="post"
      id="survey-form">

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="return_screen"
       value="edit">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id'] ?? ''
       ) ?>">

<div class="grid grid-2">

<label>
<span>アンケートタイトル</span>
<input name="title"
       maxlength="<?= MAX_TITLE ?>"
       required
       value="<?= h(
           $survey['title'] ?? ''
       ) ?>">
</label>

<label>
<span>質問番号の採番方式</span>
<select name="numbering">
<option value="global"
 <?= ($survey['numbering'] ?? 'global')
 === 'global'
 ? 'selected' : '' ?>>
アンケート全体で通番
</option>
<option value="group"
 <?= ($survey['numbering'] ?? '')
 === 'group'
 ? 'selected' : '' ?>>
グループ毎
</option>
</select>
</label>

<label style="grid-column:1/-1">
<span>アンケート説明</span>
<textarea name="description"
          maxlength="<?= MAX_DESCRIPTION ?>"><?= h(
              $survey['description'] ?? ''
          ) ?></textarea>
</label>

<label>
<span>開始日時</span>
<input type="datetime-local"
       name="startAt"
       value="<?= h(
           $survey['startAt'] ?? ''
       ) ?>">
</label>

<label>
<span>終了日時</span>
<input type="datetime-local"
       name="endAt"
       value="<?= h(
           $survey['endAt'] ?? ''
       ) ?>">
</label>

</div>

<hr style="
margin:25px 0;
border:0;
border-top:1px solid var(--border)">

<h2>質問・グループ</h2>

<?php foreach (
    ($survey['groups'] ?? [])
    as $groupIndex => $group
): ?>

<div class="group-card">

<div class="group-header">
<strong>
<?= h(
    $group['title'] ?? ''
) ?>
</strong>
</div>

<div class="group-body">

<label>
<span>グループタイトル</span>
<input name="groups[<?= $groupIndex ?>][title]"
       value="<?= h(
           $group['title'] ?? ''
       ) ?>">
</label>

<input type="hidden"
       name="groups[<?= $groupIndex ?>][id]"
       value="<?= h(
           $group['id'] ?? ''
       ) ?>">

<?php foreach (
    ($group['questions'] ?? [])
    as $questionIndex => $question
): ?>

<div class="question-card">

<div class="question-header">
<strong>
<?= h(
    $question['number'] ?? ''
) ?>
</strong>
</div>

<div class="question-body">

<input type="hidden"
       name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][id]"
       value="<?= h(
           $question['id'] ?? ''
       ) ?>">

<div class="grid grid-2">

<label>
<span>質問文</span>
<textarea
 name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][text]"
><?= h(
    $question['text'] ?? ''
) ?></textarea>
</label>

<div>

<label>
<span>回答形式</span>
<select
 name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][type]">
<option value="single"
 <?= ($question['type'] ?? '')
 === 'single'
 ? 'selected' : '' ?>>
単一選択
</option>
<option value="multiple"
 <?= ($question['type'] ?? '')
 === 'multiple'
 ? 'selected' : '' ?>>
複数選択
</option>
<option value="text"
 <?= ($question['type'] ?? '')
 === 'text'
 ? 'selected' : '' ?>>
自由記述
</option>
</select>
</label>

<label style="margin-top:14px">
<span>必須</span>
<select
 name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][required]">
<option value="0"
 <?= empty(
     $question['required']
 )
 ? 'selected' : '' ?>>
任意
</option>
<option value="1"
 <?= !empty(
     $question['required']
 )
 ? 'selected' : '' ?>>
必須
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

<div style="margin-top:16px">

<strong>選択肢</strong>

<?php foreach (
    ($question['options'] ?? [])
    as $optionIndex => $option
): ?>

<div class="option-row">

<input type="hidden"
 name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][options][<?= $optionIndex ?>][id]"
 value="<?= h(
     $option['id'] ?? ''
 ) ?>">

<input
 name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][options][<?= $optionIndex ?>][label]"
 value="<?= h(
     $option['label'] ?? ''
 ) ?>"
 placeholder="選択肢">

<input
 name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][options][<?= $optionIndex ?>][nextQuestionId]"
 value="<?= h(
     $option['nextQuestionId'] ?? ''
 ) ?>"
 placeholder="次の質問ID（任意）">

</div>

<?php endforeach; ?>

</div>
<?php endif; ?>

</div>
</div>

<?php endforeach; ?>

</div>
</div>

<?php endforeach; ?>

<div class="button-row"
     style="margin-top:20px">
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

</form>

</div>
</div>

</div>
</div>
<?php
    render_footer();
}


/* =========================================================
 * プレビュー
 * ========================================================= */

function render_preview(
    array $survey
): void {
    render_head(
        'アンケートプレビュー'
    );
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1>プレビュー</h1>
<p><?= h($survey['title'] ?? '') ?></p>
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

<h2><?= h(
    $survey['title'] ?? ''
) ?></h2>

<p>
<?= nl2br(
    h($survey['description'] ?? '')
) ?>
</p>

<?php foreach (
    ($survey['groups'] ?? [])
    as $group
): ?>

<h3><?= h(
    $group['title'] ?? ''
) ?></h3>

<?php foreach (
    ($group['questions'] ?? [])
    as $question
): ?>

<div class="preview-question">

<strong>
<?= h(
    $question['number'] ?? ''
) ?>
<?= h(
    $question['text'] ?? ''
) ?>

<?php if (
    !empty($question['required'])
): ?>
<span style="color:var(--danger)">
*
</span>
<?php endif; ?>

</strong>

<?php if (
    in_array(
        $question['type'] ?? '',
        ['single','multiple'],
        true
    )
): ?>

<ul>
<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>
<li>
<?= h(
    $option['label'] ?? ''
) ?>

<?php if (
    !empty(
        $option['nextQuestionId']
    )
): ?>
→ <?= h(
    $option['nextQuestionId']
) ?>
<?php endif; ?>

</li>
<?php endforeach; ?>
</ul>

<?php else: ?>

<div class="help">
自由記述欄
</div>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>
</div>

</div>
</div>
<?php
    render_footer();
}


/* =========================================================
 * 回答画面
 * ========================================================= */

function render_answer(
    array $survey
): void {
    render_head(
        'アンケート回答',
        false
    );
?>
<div class="answer-shell">

<div class="page-title">
<div>
<h1><?= h(
    $survey['title'] ?? ''
) ?></h1>
<p><?= nl2br(
    h($survey['description'] ?? '')
) ?></p>
</div>
</div>

<div class="card">
<div class="card-body">

<form method="post">

<input type="hidden"
       name="action"
       value="answer_confirm">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<?php foreach (
    ($survey['groups'] ?? [])
    as $group
): ?>

<h2><?= h(
    $group['title'] ?? ''
) ?></h2>

<?php foreach (
    ($group['questions'] ?? [])
    as $question
): ?>

<div class="preview-question">

<strong>
<?= h(
    $question['number'] ?? ''
) ?>
<?= h(
    $question['text'] ?? ''
) ?>

<?php if (
    !empty($question['required'])
): ?>
<span style="color:var(--danger)">
*
</span>
<?php endif; ?>

</strong>

<?php
$type =
    $question['type'] ?? 'text';

if (
    $type === 'single'
    || $type === 'multiple'
):
?>

<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>

<label style="
display:block;
padding:12px;
margin:8px 0;
border:1px solid var(--border);
border-radius:8px">

<input
 type="<?= $type === 'multiple'
     ? 'checkbox'
     : 'radio' ?>"
 name="answer[<?= h(
     $question['id']
 ) ?>]<?= $type === 'multiple'
     ? '[]'
     : '' ?>"
 value="<?= h(
     $option['label'] ?? ''
 ) ?>">

<?= h(
    $option['label'] ?? ''
) ?>

</label>

<?php endforeach; ?>

<?php else: ?>

<textarea
 name="answer[<?= h(
     $question['id']
 ) ?>]"
 style="min-height:180px"></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="button-row">
<button class="btn btn-primary"
        type="submit">
回答を確認する
</button>
</div>

</form>

</div>
</div>

</div>
<?php
    render_footer();
}


/* =========================================================
 * 回答確認
 * ========================================================= */

function render_confirm(
    array $survey
): void {
    $draft =
        $_SESSION[
            'answer_draft'
        ] ?? [];

    if (!is_array($draft)) {
        $draft = [];
    }

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
    $survey['title'] ?? ''
) ?></p>
</div>
</div>

<div class="card">
<div class="card-body">

<?php foreach (
    ($survey['groups'] ?? [])
    as $group
): ?>

<h2><?= h(
    $group['title'] ?? ''
) ?></h2>

<?php foreach (
    ($group['questions'] ?? [])
    as $question
): ?>

<?php
$value =
    $draft[
        $question['id']
    ] ?? '';

if (is_array($value)) {
    $display =
        implode(
            ', ',
            array_map(
                'strval',
                $value
            )
        );
} else {
    $display =
        (string)$value;
}
?>

<div class="preview-question">

<strong>
<?= h(
    $question['number'] ?? ''
) ?>
<?= h(
    $question['text'] ?? ''
) ?>
</strong>

<p>
<?= nl2br(
    h($display)
) ?>
</p>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="button-row">

<form method="post">
<input type="hidden"
       name="action"
       value="answer_back">
<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">
<button class="btn btn-secondary">
修正する
</button>
</form>

<form method="post"
      data-confirm="回答を送信します。よろしいですか？">
<input type="hidden"
       name="action"
       value="submit_answer">
<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">
<button class="btn btn-primary">
回答を送信する
</button>
</form>

</div>

</div>
</div>

</div>
<?php
    render_footer();
}


/* =========================================================
 * 回答完了
 * ========================================================= */

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
     style="
     text-align:center;
     padding:55px 25px">

<h1>回答ありがとうございました</h1>

<p>
「<?= h(
    $survey['title'] ?? ''
) ?>」
への回答を受け付けました。
</p>

</div>
</div>

</div>
<?php
    render_footer();
}


/* =========================================================
 * 送信画面
 * ========================================================= */

function render_send(
    array $data,
    array $survey
): void {
    $flash =
        consume_flash();

    $results =
        $_SESSION[
            'send_results'
        ] ?? [];

    unset(
        $_SESSION[
            'send_results'
        ]
    );

    render_head(
        'メール送信'
    );
?>
<div class="page">
<div class="container">

<?php if ($flash): ?>
<div class="alert alert-<?= h(
    $flash['type'] === 'success'
        ? 'success'
        : 'error'
) ?>">
<?= h($flash['message']) ?>
</div>
<?php endif; ?>

<div class="page-title">
<div>
<h1>顧客選択・メール送信</h1>
<p>
対象アンケート：
<strong><?= h(
    $survey['title'] ?? ''
) ?></strong>
</p>
</div>
</div>

<?php if ($results): ?>
<div class="card">
<div class="card-header">
<h2>送信結果</h2>
</div>
<div class="card-body">

<?php foreach (
    $results as $result
): ?>

<div class="status-line">
<?= h(
    $result['email'] ?? ''
) ?>

：
<strong>
<?= h(
    $result['status'] ?? ''
) ?>
</strong>

<?php if (
    !empty($result['message'])
): ?>
<br>
<?= h(
    $result['message']
) ?>
<?php endif; ?>

</div>

<?php endforeach; ?>

</div>
</div>
<?php endif; ?>

<div class="card">
<div class="card-body">

<form method="post">

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<label>
<span>メール件名</span>
<input name="subject"
       required
       value="<?= h(
           $survey['title']
           . ' のご案内'
       ) ?>">
</label>

<label style="display:block;margin-top:16px">
<span>メール本文</span>
<textarea name="body"
          required><?= h(
'{顧客名} 様

アンケートへのご協力をお願いいたします。

回答URL:
{アンケートURL}'
) ?></textarea>
</label>

<h2>顧客選択</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
<th></th>
<th>氏名</th>
<th>組織</th>
<th>部署</th>
<th>メール</th>
</tr>
</thead>
<tbody>

<?php foreach (
    $data['customers'] as $customer
): ?>

<tr>
<td>
<input type="checkbox"
       name="customer_ids[]"
       value="<?= h(
           $customer['id']
       ) ?>">
</td>
<td><?= h(
    $customer['name'] ?? ''
) ?></td>
<td><?= h(
    $customer['organization'] ?? ''
) ?></td>
<td><?= h(
    $customer['department'] ?? ''
) ?></td>
<td><?= h(
    $customer['email'] ?? ''
) ?></td>
</tr>

<?php endforeach; ?>

</tbody>
</table>
</div>

<div class="button-row"
     style="margin-top:18px">
<button class="btn btn-primary"
        type="submit"
        data-confirm="選択した顧客へ実際にメールを送信します。よろしいですか？">
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

<?php
$history = array_reverse(
    $data['send_history']
);

$historyShown = false;

foreach (
    $history as $item
):
    if (
        ($item['survey_id'] ?? '')
        !== ($survey['id'] ?? '')
    ) {
        continue;
    }

    $historyShown = true;
?>

<div class="status-line">
<?= h(
    $item['createdAt'] ?? ''
) ?>
　
<?= h(
    $item['email'] ?? ''
) ?>
　
<?= h(
    $item['status'] ?? ''
) ?>
<?php if (
    !empty($item['message'])
): ?>
<br>
<?= h(
    $item['message']
) ?>
<?php endif; ?>
</div>

<?php endforeach; ?>

<?php if (!$historyShown): ?>
<div class="empty">
送信履歴はありません。
</div>
<?php endif; ?>

</div>
</div>

</div>
</div>
<?php
    render_footer();
}


/* =========================================================
 * 集計
 * ========================================================= */

function render_analytics(
    array $data,
    array $survey
): void {
    render_head(
        '回答集計・分析'
    );

    $answers = [];

    foreach (
        $data['answers'] as $answer
    ) {
        if (
            ($answer['survey_id'] ?? '')
            === ($survey['id'] ?? '')
        ) {
            $answers[] = $answer;
        }
    }

    $sentCustomerIds = [];

    foreach (
        $data['send_history'] as $history
    ) {
        if (
            ($history['survey_id'] ?? '')
            === ($survey['id'] ?? '')
            && ($history['status'] ?? '')
                === 'success'
        ) {
            $sentCustomerIds[
                (string)(
                    $history['customer_id']
                    ?? ''
                )
            ] = true;
        }
    }

    $sentCount =
        count($sentCustomerIds);

    $answerCount =
        count($answers);

    $rate =
        $sentCount > 0
            ? round(
                ($answerCount / $sentCount)
                * 100,
                1
            )
            : 0;
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1>回答集計・分析</h1>
<p><?= h(
    $survey['title'] ?? ''
) ?></p>
</div>
</div>

<div class="grid grid-3">

<div class="card">
<div class="card-body">
<strong>送信対象者数</strong>
<h2><?= h($sentCount) ?></h2>
</div>
</div>

<div class="card">
<div class="card-body">
<strong>回答数</strong>
<h2><?= h($answerCount) ?></h2>
</div>
</div>

<div class="card">
<div class="card-body">
<strong>回答率</strong>
<h2><?= h($rate) ?>%</h2>
</div>
</div>

</div>

<div class="card">
<div class="card-header">
<h2>設問別集計</h2>
</div>
<div class="card-body">

<?php if (!$answers): ?>

<div class="empty">
現在、回答データはありません
</div>

<?php else: ?>

<?php foreach (
    ($survey['groups'] ?? [])
    as $group
): ?>

<h3><?= h(
    $group['title'] ?? ''
) ?></h3>

<?php foreach (
    ($group['questions'] ?? [])
    as $question
): ?>

<div class="preview-question">

<strong>
<?= h(
    $question['number'] ?? ''
) ?>
<?= h(
    $question['text'] ?? ''
) ?>
</strong>

<?php
$counts = [];

foreach (
    $answers as $answer
) {
    $value =
        $answer['answers']
        [$question['id']]
        ?? '';

    if (is_array($value)) {
        foreach (
            $value as $item
        ) {
            $item =
                (string)$item;

            $counts[$item] =
                ($counts[$item] ?? 0)
                + 1;
        }
    } else {
        $value =
            trim((string)$value);

        if ($value !== '') {
            $counts[$value] =
                ($counts[$value] ?? 0)
                + 1;
        }
    }
}
?>

<?php if (!$counts): ?>

<p>回答なし</p>

<?php else: ?>

<ul>
<?php foreach (
    $counts as $label => $count
): ?>
<li>
<?= h($label) ?>：
<?= h($count) ?>件
</li>
<?php endforeach; ?>
</ul>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<?php endif; ?>

</div>
</div>

</div>
</div>
<?php
    render_footer();
}


/* =========================================================
 * メイン
 *
 * POST
 * ↓
 * 業務処理
 * ↓
 * 結果確定
 * ↓
 * 同一リクエストでrender
 *
 * 外部通信関数からLocationを発行しない。
 * ========================================================= */

try {

    start_app();

    $data =
        load_data();

    $settings =
        load_settings();

    if (
        refresh_statuses($data)
    ) {
        save_data($data);
    }

    /*
     * 一覧ソート処理から参照できるように
     * 一時的に保持する。
     */
    $GLOBALS['_list_data'] =
        $data;

    $postResult =
        handle_post(
            $data,
            $settings
        );

    /*
     * POST後は必ず最新データを再読み込み。
     */
    $data =
        load_data();

    $settings =
        load_settings();

    if (
        refresh_statuses($data)
    ) {
        save_data($data);
    }

    $GLOBALS['_list_data'] =
        $data;

    if (
        $postResult !== null
    ) {
        $screen =
            (string)(
                $postResult['screen']
                ?? 'list'
            );

        $id =
            (string)(
                $postResult['id']
                ?? ''
            );
    } else {
        $screen =
            get_string('screen');

        if ($screen === '') {
            $screen = 'list';
        }

        $id =
            get_string('id');
    }


    /* =====================================================
     * 回答者画面
     * ===================================================== */

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

        if (
            $screen === 'answer'
        ) {
            render_answer(
                $survey
            );
            exit;
        }

        if (
            $screen === 'confirm'
        ) {
            render_confirm(
                $survey
            );
            exit;
        }

        /*
         * 回答完了後は管理者画面へ遷移しない。
         */
        render_complete(
            $survey
        );

        exit;
    }


    /* =====================================================
     * 管理者画面
     * ===================================================== */

    switch ($screen) {

        case 'edit':

            if ($id === 'new') {
                $survey = [
                    'id' =>
                        uuid('survey'),
                    'title' => '',
                    'description' => '',
                    'startAt' =>
                        date(
                            'Y-m-d\TH:i'
                        ),
                    'endAt' =>
                        date(
                            'Y-m-d\TH:i',
                            strtotime('+30 days')
                        ),
                    'status' =>
                        'draft',
                    'numbering' =>
                        'global',
                    'createdAt' =>
                        date(
                            'Y-m-d H:i:s'
                        ),
                    'updatedAt' =>
                        date(
                            'Y-m-d H:i:s'
                        ),
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

                render_edit(
                    $survey
                );

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

                render_list(
                    $data
                );

                break;
            }

            render_edit(
                $survey
            );

            break;


        case 'preview':

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

                render_list(
                    $data
                );

                break;
            }

            render_preview(
                $survey
            );

            break;


        case 'send':

            $survey =
                survey_by_id(
                    $data['surveys'],
                    $id
                );

            if ($survey === null) {
                flash(
                    'error',
                    '対象アンケートが指定されていません。'
                );

                render_list(
                    $data
                );

                break;
            }

            render_send(
                $data,
                $survey
            );

            break;


        case 'analytics':

            $survey =
                survey_by_id(
                    $data['surveys'],
                    $id
                );

            if ($survey === null) {
                flash(
                    'error',
                    '対象アンケートが指定されていません。'
                );

                render_list(
                    $data
                );

                break;
            }

            render_analytics(
                $data,
                $survey
            );

            break;


        case 'kintone':

            render_kintone(
                $settings['kintone']
            );

            break;


        case 'mail':

            render_mail(
                $settings['mail']
            );

            break;


        case 'list':
        default:

            render_list(
                $data
            );

            break;
    }

} catch (Throwable $e) {

    /*
     * 最終防衛線。
     *
     * 認証情報・スタックトレース・
     * 内部パスをブラウザへ出さない。
     */

    http_response_code(500);

    $message =
        'アプリケーションの処理を完了できませんでした。';

    $isHtml =
        !empty(
            $_SERVER['HTTP_ACCEPT']
        )
        && str_contains(
            (string)(
                $_SERVER['HTTP_ACCEPT']
            ),
            'text/html'
        );

    if (!$isHtml) {
        header(
            'Content-Type: text/plain; charset=UTF-8'
        );

        echo $message;

        exit;
    }

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>システムエラー</title>
<style>
body{
 margin:0;
 background:#f8fafc;
 color:#1e293b;
 font-family:
 -apple-system,
 BlinkMacSystemFont,
 "Segoe UI",
 "Noto Sans JP",
 Meiryo,
 sans-serif;
}
.container{
 width:min(900px,calc(100% - 32px));
 margin:60px auto;
}
.error{
 background:#fee2e2;
 color:#991b1b;
 border:1px solid #fecaca;
 border-radius:10px;
 padding:18px;
}
</style>
</head>
<body>
<div class="container">
<h1>システムエラー</h1>
<div class="error">
<?= h($message) ?>
</div>
</div>
</body>
</html>
<?php
    exit;
}

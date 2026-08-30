<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 *
 * Apache 2.4 / PHP 8.5
 * DBなし
 * PHP cURLなし
 * 単一エントリーポイント
 *
 * 外部サービス:
 *   kintone REST API
 *   SMTP
 *
 * 永続化:
 *   _data/data.json
 *   _data/settings.json
 *
 * kintone / SMTP パスワード:
 *   APP_ENCRYPTION_KEY 環境変数を利用して暗号化保存する。
 *
 * 重要:
 *   外部サービス通信関数から header("Location: ...") は実行しない。
 *   POST処理で業務結果を確定してから表示画面を決定する。
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';

const DATA_DIR  = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SET_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_READ_TIMEOUT    = 30;

const SMTP_CONNECT_TIMEOUT = 10;
const SMTP_READ_TIMEOUT    = 30;

const KINTONE_PAGE_SIZE = 500;

const MAX_TITLE       = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION    = 1000;
const MAX_OPTION      = 500;

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

function post(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function post_string(string $key): string
{
    $value = post($key, '');
    return is_scalar($value) ? trim((string)$value) : '';
}

function get_string(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
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
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host .
        app_url([
            'screen' => 'answer',
            'id' => $surveyId,
        ]);
}

function safe_external_error(Throwable $e): string
{
    $message = trim($e->getMessage());

    if ($message === '') {
        return '外部サービスとの通信中にエラーが発生しました。';
    }

    $sensitivePatterns = [
        '/X-Cybozu-Authorization/i',
        '/Authorization/i',
        '/password/i',
        '/passwd/i',
        '/SMTP_PASSWORD/i',
    ];

    foreach ($sensitivePatterns as $pattern) {
        if (preg_match($pattern, $message)) {
            return '外部サービスとの通信に失敗しました。詳細は設定と接続状態を確認してください。';
        }
    }

    return $message;
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
            'password_enc' => '',
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
            'last_sync_count' => 0,
        ],
        'mail' => [
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'password_enc' => '',
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
        throw new RuntimeException(
            'JSON保存データを生成できません。'
        );
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));

    $fp = @fopen($tmp, 'wb');

    if (!$fp) {
        throw new RuntimeException(
            '一時ファイルを作成できません。'
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException(
                'ファイルをロックできません。'
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

function save_data(array $data): void
{
    save_json(DATA_FILE, $data);
}

function load_settings(): array
{
    $default = default_settings();
    $settings = load_json(SET_FILE, $default);

    foreach (['kintone', 'mail'] as $key) {
        $settings[$key] = array_replace_recursive(
            $default[$key],
            is_array($settings[$key] ?? null)
                ? $settings[$key]
                : []
        );
    }

    return $settings;
}

function save_settings(array $settings): void
{
    save_json(SET_FILE, $settings);
}

/* =========================================================
 * パスワード暗号化
 * ========================================================= */

function encryption_key(): string
{
    $key = getenv('APP_ENCRYPTION_KEY');

    if (!is_string($key) || trim($key) === '') {
        throw new RuntimeException(
            'APP_ENCRYPTION_KEY が設定されていません。'
        );
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
        encryption_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cipher === false) {
        throw new RuntimeException(
            '機密情報を暗号化できません。'
        );
    }

    return base64_encode(
        $iv . $tag . $cipher
    );
}

function decrypt_secret(string $encoded): string
{
    if ($encoded === '') {
        return '';
    }

    $raw = base64_decode($encoded, true);

    if ($raw === false || strlen($raw) < 28) {
        throw new RuntimeException(
            '保存された機密情報を復号できません。'
        );
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        encryption_key(),
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
    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($value) ? $value : null;
}

/* =========================================================
 * アンケート
 * ========================================================= */

function survey_index(array $surveys, string $id): int
{
    foreach ($surveys as $index => $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $index;
        }
    }

    return -1;
}

function survey_get(array $surveys, string $id): ?array
{
    $index = survey_index($surveys, $id);

    return $index >= 0
        ? $surveys[$index]
        : null;
}

function refresh_status(array &$data): void
{
    $changed = false;

    foreach ($data['surveys'] as &$survey) {
        if (
            ($survey['status'] ?? '') === 'published'
            && !empty($survey['endAt'])
        ) {
            $time = strtotime((string)$survey['endAt']);

            if ($time !== false && $time < time()) {
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
    $result = [];

    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            $result[] = $question;
        }
    }

    return $result;
}

function question_ids(array $survey): array
{
    return array_map(
        static fn(array $question): string =>
            (string)($question['id'] ?? ''),
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
        'ended' => 'danger',
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

function validate_kintone(
    array $config,
    bool $requirePassword = true
): array {
    $errors = [];

    $subdomain = normalize_kintone_subdomain(
        (string)($config['subdomain'] ?? '')
    );

    if (
        $subdomain === ''
        || !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
            $subdomain
        )
    ) {
        $errors[] = 'kintoneサブドメインが不正です。';
    }

    $appId = trim(
        (string)($config['app_id'] ?? '')
    );

    if (
        !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        $errors[] = '顧客管理アプリIDが不正です。';
    }

    if (
        trim(
            (string)($config['username'] ?? '')
        ) === ''
    ) {
        $errors[] = 'ログイン名を入力してください。';
    }

    if (
        $requirePassword
        && trim(
            (string)($config['password'] ?? '')
        ) === ''
    ) {
        $errors[] = 'パスワードを入力してください。';
    }

    $proxy = trim(
        (string)($config['proxy'] ?? '')
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
    $password = (string)(
        $config['password'] ?? ''
    );

    $errors = validate_kintone(
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
        'https://' .
        $subdomain .
        '.cybozu.com' .
        $path;

    $authorization = base64_encode(
        (string)$config['username'] .
        ':' .
        $password
    );

    $headers = [
        'X-Cybozu-Authorization: ' .
            $authorization,
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

        $headers[] =
            'Content-Type: application/json';
    }

    $verify = !empty(
        $config['verify_ssl']
    );

    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode(
                "\r\n",
                $headers
            ),
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
            'peer_name' =>
                $subdomain . '.cybozu.com',
        ],
    ];

    $proxy = trim(
        (string)($config['proxy'] ?? '')
    );

    if ($proxy !== '') {
        [$proxyHost, $proxyPort] =
            explode(':', $proxy, 2);

        $options['http']['proxy'] =
            'tcp://' .
            $proxyHost .
            ':' .
            (int)$proxyPort;

        $options['http']['request_fulluri'] =
            true;
    }

    $context = stream_context_create(
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
                $match
            )
        ) {
            $status = (int)$match[1];
        }
    }

    if ($response === false) {
        throw new RuntimeException(
            'kintoneへの通信結果を取得できませんでした。'
        );
    }

    if (
        $status === 302
        || $status === 303
    ) {
        throw new RuntimeException(
            'kintoneからリダイレクト応答（HTTP ' .
            $status .
            '）が返されました。API URL・認証方式・接続設定を確認してください。'
        );
    }

    if ($status < 200 || $status >= 300) {
        $json = json_decode(
            $response,
            true
        );

        $message = is_array($json)
            ? (string)($json['message'] ?? '')
            : '';

        $code = is_array($json)
            ? (string)($json['code'] ?? '')
            : '';

        $detail =
            'kintone APIエラー HTTP ' .
            $status;

        if ($code !== '') {
            $detail .= ' [' . $code . ']';
        }

        if ($message !== '') {
            $detail .= ' ' . $message;
        }

        throw new RuntimeException(
            $detail
        );
    }

    $json = json_decode(
        $response,
        true
    );

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

function kintone_test(array $config): array
{
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?id=' .
        rawurlencode(
            (string)$config['app_id']
        )
    );
}

function kintone_fields(array $config): array
{
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode(
            (string)$config['app_id']
        )
    );
}

/*
 * kintoneレコードをページングして全件取得する。
 *
 * 旧実装:
 *   /records.json?app=xxx&totalCount=true
 *   を1回だけ呼び出していた。
 *
 * 新実装:
 *   limit / offset を利用して全ページを取得する。
 */
function kintone_records(array $config): array
{
    $all = [];
    $offset = 0;

    while (true) {
        $path =
            '/k/v1/records.json?' .
            http_build_query([
                'app' =>
                    (string)$config['app_id'],
                'query' =>
                    'order by $id asc limit ' .
                    KINTONE_PAGE_SIZE .
                    ' offset ' .
                    $offset,
                'totalCount' => 'true',
            ], '', '&', PHP_QUERY_RFC3986);

        $response = kintone_request(
            $config,
            'GET',
            $path
        );

        $records =
            $response['body']['records'] ?? [];

        if (!is_array($records)) {
            throw new RuntimeException(
                'kintoneレコードの形式が不正です。'
            );
        }

        foreach ($records as $record) {
            if (is_array($record)) {
                $all[] = $record;
            }
        }

        $count = count($records);

        if ($count < KINTONE_PAGE_SIZE) {
            break;
        }

        $offset += $count;
    }

    return [
        'status' => 200,
        'body' => [
            'records' => $all,
            'totalCount' => count($all),
        ],
    ];
}

function kintone_field_list(
    array $response
): array {
    $properties =
        $response['body']['properties'] ?? [];

    if (!is_array($properties)) {
        return [];
    }

    $result = [];

    foreach (
        $properties as $code => $field
    ) {
        if (!is_array($field)) {
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

    usort(
        $result,
        static fn(
            array $a,
            array $b
        ): int =>
            strnatcasecmp(
                $a['code'],
                $b['code']
            )
    );

    return $result;
}

function krecord(
    array $record,
    string $code
): string {
    if (
        $code === ''
        || !isset($record[$code])
        || !is_array($record[$code])
    ) {
        return '';
    }

    $value =
        $record[$code]['value'] ?? '';

    if (!is_array($value)) {
        return (string)$value;
    }

    $result = [];

    foreach ($value as $item) {
        if (!is_array($item)) {
            $result[] = (string)$item;
            continue;
        }

        if (isset($item['name'])) {
            $result[] =
                (string)$item['name'];
        } elseif (isset($item['value'])) {
            $result[] =
                (string)$item['value'];
        }
    }

    return implode(
        ' ',
        array_filter(
            $result,
            static fn(string $v): bool =>
                $v !== ''
        )
    );
}

function krecord_id(array $record): string
{
    return krecord(
        $record,
        '$id'
    );
}

/*
 * kintoneの1レコードから、
 * アプリケーション側の顧客データへ変換する。
 */
function customer_from_krecord(
    array $record,
    array $mapping
): ?array {
    $kintoneId =
        krecord_id($record);

    if ($kintoneId === '') {
        return null;
    }

    $addressValues = [];

    foreach (
        is_array($mapping['address'] ?? null)
            ? $mapping['address']
            : []
        as $code
    ) {
        $value = krecord(
            $record,
            (string)$code
        );

        if ($value !== '') {
            $addressValues[] = $value;
        }
    }

    return [
        'id' =>
            'customer-kintone-' .
            $kintoneId,
        'kintone_id' => $kintoneId,
        'organization' =>
            krecord(
                $record,
                (string)($mapping['organization'] ?? '')
            ),
        'name' =>
            krecord(
                $record,
                (string)($mapping['name'] ?? '')
            ),
        'email' =>
            krecord(
                $record,
                (string)($mapping['email'] ?? '')
            ),
        'department' =>
            krecord(
                $record,
                (string)($mapping['department'] ?? '')
            ),
        'phone' =>
            krecord(
                $record,
                (string)($mapping['phone'] ?? '')
            ),
        'address' =>
            implode(
                ' ',
                $addressValues
            ),
        'updatedAt' => now(),
    ];
}

/* =========================================================
 * SMTP
 * ========================================================= */

function validate_mail(array $config): array
{
    $errors = [];

    if (
        trim(
            (string)($config['host'] ?? '')
        ) === ''
    ) {
        $errors[] =
            'SMTPサーバを入力してください。';
    }

    $port = (int)(
        $config['port'] ?? 0
    );

    if (
        $port < 1
        || $port > 65535
    ) {
        $errors[] =
            'SMTPポートが不正です。';
    }

    if (
        !in_array(
            (string)($config['encryption'] ?? ''),
            ['ssl', 'tls', 'none'],
            true
        )
    ) {
        $errors[] =
            '暗号化方式が不正です。';
    }

    $from =
        trim(
            (string)($config['from_email'] ?? '')
        );

    if (
        !filter_var(
            $from,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            '送信元メールアドレスが不正です。';
    }

    return $errors;
}

function smtp_read(
    $socket,
    int $timeout
): string {
    stream_set_timeout(
        $socket,
        $timeout
    );

    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket, 8192);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (
            strlen($line) >= 4
            && $line[3] === ' '
        ) {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException(
            'SMTPサーバから応答を取得できませんでした。'
        );
    }

    $meta = stream_get_meta_data(
        $socket
    );

    if (!empty($meta['timed_out'])) {
        throw new RuntimeException(
            'SMTP通信がタイムアウトしました。'
        );
    }

    return $response;
}

function smtp_expect(
    $socket,
    array $codes
): string {
    $response = smtp_read(
        $socket,
        SMTP_READ_TIMEOUT
    );

    $code =
        (int)substr(
            trim($response),
            0,
            3
        );

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTPエラー: HTTPではなくSMTP応答コード ' .
            $code .
            ' が返されました。'
        );
    }

    return $response;
}

function smtp_write(
    $socket,
    string $command
): void {
    $written = fwrite(
        $socket,
        $command . "\r\n"
    );

    if ($written === false) {
        throw new RuntimeException(
            'SMTPサーバへデータを送信できませんでした。'
        );
    }
}

function smtp_open(
    array $config
) {
    $errors = validate_mail(
        $config
    );

    if ($errors) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    $host =
        (string)$config['host'];

    $port =
        (int)$config['port'];

    $encryption =
        (string)$config['encryption'];

    if ($encryption === 'ssl') {
        $target =
            'ssl://' . $host . ':' . $port;
    } else {
        $target =
            'tcp://' . $host . ':' . $port;
    }

    $errno = 0;
    $error = '';

    $socket = @stream_socket_client(
        $target,
        $errno,
        $error,
        SMTP_CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        throw new RuntimeException(
            'SMTPサーバへ接続できませんでした。' .
            ($error !== ''
                ? ' ' . $error
                : '')
        );
    }

    smtp_expect(
        $socket,
        [220]
    );

    smtp_write(
        $socket,
        'EHLO localhost'
    );

    smtp_expect(
        $socket,
        [250]
    );

    if ($encryption === 'tls') {
        smtp_write(
            $socket,
            'STARTTLS'
        );

        smtp_expect(
            $socket,
            [220]
        );

        $crypto = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            throw new RuntimeException(
                'SMTP TLS接続を確立できませんでした。'
            );
        }

        smtp_write(
            $socket,
            'EHLO localhost'
        );

        smtp_expect(
            $socket,
            [250]
        );
    }

    if (!empty($config['auth'])) {
        $username =
            (string)($config['username'] ?? '');
        $password =
            (string)($config['password'] ?? '');

        smtp_write(
            $socket,
            'AUTH LOGIN'
        );

        smtp_expect(
            $socket,
            [334]
        );

        smtp_write(
            $socket,
            base64_encode($username)
        );

        smtp_expect(
            $socket,
            [334]
        );

        smtp_write(
            $socket,
            base64_encode($password)
        );

        smtp_expect(
            $socket,
            [235]
        );
    }

    return $socket;
}

function smtp_send(
    array $config,
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

    $socket = smtp_open(
        $config
    );

    try {
        $from =
            (string)$config['from_email'];

        $fromName =
            (string)($config['from_name'] ?? '');

        smtp_write(
            $socket,
            'MAIL FROM:<' . $from . '>'
        );

        smtp_expect(
            $socket,
            [250]
        );

        smtp_write(
            $socket,
            'RCPT TO:<' . $to . '>'
        );

        smtp_expect(
            $socket,
            [250, 251]
        );

        smtp_write(
            $socket,
            'DATA'
        );

        smtp_expect(
            $socket,
            [354]
        );

        $encodedFromName =
            $fromName !== ''
                ? '=?UTF-8?B?' .
                    base64_encode($fromName) .
                    '?='
                : $from;

        $encodedSubject =
            '=?UTF-8?B?' .
            base64_encode($subject) .
            '?=';

        $headers = [
            'From: ' .
                $encodedFromName .
                ' <' .
                $from .
                '>',
            'To: <' . $to . '>',
            'Subject: ' .
                $encodedSubject,
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
                'Reply-To: ' .
                $config['reply_to'];
        }

        $message =
            implode(
                "\r\n",
                $headers
            ) .
            "\r\n\r\n" .
            str_replace(
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

        smtp_write(
            $socket,
            $message .
            "\r\n."
        );

        smtp_expect(
            $socket,
            [250]
        );

        smtp_write(
            $socket,
            'QUIT'
        );

        smtp_expect(
            $socket,
            [221]
        );
    } finally {
        fclose($socket);
    }
}

function smtp_test_connection(
    array $config
): void {
    $socket = smtp_open(
        $config
    );

    smtp_write(
        $socket,
        'QUIT'
    );

    try {
        smtp_expect(
            $socket,
            [221]
        );
    } finally {
        fclose($socket);
    }
}

/* =========================================================
 * 回答
 * ========================================================= */

function visible_questions(
    array $survey,
    array $answers
): array {
    $questions =
        all_questions($survey);

    $result = [];

    $nextId = null;

    foreach ($questions as $index => $question) {
        if (
            $nextId !== null
            && (string)$question['id'] !== $nextId
        ) {
            continue;
        }

        $result[] = $question;
        $nextId = null;

        if (
            ($question['type'] ?? '') === 'single'
        ) {
            $value =
                $answers[$question['id']]
                ?? '';

            foreach (
                $question['options'] ?? []
                as $option
            ) {
                if (
                    (string)($option['id'] ?? '')
                    === (string)$value
                ) {
                    $target =
                        trim(
                            (string)(
                                $option['nextQuestionId']
                                ?? ''
                            )
                        );

                    if ($target !== '') {
                        $nextId = $target;
                    }

                    break;
                }
            }
        }

        if ($nextId === null) {
            $next = $questions[$index + 1]
                ?? null;

            if ($next) {
                $nextId =
                    (string)$next['id'];
            }
        }
    }

    return $result;
}

function validate_answers(
    array $survey,
    array $answers
): array {
    $errors = [];

    foreach (
        visible_questions(
            $survey,
            $answers
        ) as $question
    ) {
        $id =
            (string)$question['id'];

        $value =
            $answers[$id] ?? '';

        $empty = false;

        if (is_array($value)) {
            $empty =
                count(
                    array_filter(
                        $value,
                        static fn($v): bool =>
                            trim((string)$v) !== ''
                    )
                ) === 0;
        } else {
            $empty =
                trim((string)$value) === '';
        }

        if (
            !empty($question['required'])
            && $empty
        ) {
            $errors[] =
                ($question['number'] ?? '') .
                ' は必須です。';
        }
    }

    return $errors;
}

/* =========================================================
 * POST処理
 * ========================================================= */

function handle_post(
    array &$data,
    array &$settings
): array {
    $action =
        post_string('action');

    switch ($action) {

        case 'save_survey':
            return handle_save_survey(
                $data
            );

        case 'delete_survey':
            $id =
                post_string('survey_id');

            $index =
                survey_index(
                    $data['surveys'],
                    $id
                );

            if ($index < 0) {
                flash(
                    'error',
                    '削除対象のアンケートが見つかりません。'
                );

                return [
                    'screen' => 'list',
                ];
            }

            unset(
                $data['surveys'][$index]
            );

            $data['surveys'] =
                array_values(
                    $data['surveys']
                );

            save_data($data);

            flash(
                'success',
                'アンケートを削除しました。'
            );

            return [
                'screen' => 'list',
            ];

        case 'duplicate_survey':
            $id =
                post_string('survey_id');

            $survey =
                survey_get(
                    $data['surveys'],
                    $id
                );

            if (!$survey) {
                flash(
                    'error',
                    '複製元アンケートが見つかりません。'
                );

                return [
                    'screen' => 'list',
                ];
            }

            $survey['id'] =
                uid('survey');

            $survey['title'] =
                (string)$survey['title'] .
                '（複製）';

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
                    $group['questions'] as &$question
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

        case 'change_status':
            return handle_status_change(
                $data
            );

        case 'save_kintone':
            return handle_save_kintone(
                $settings
            );

        case 'test_kintone':
            try {
                $config =
                    kintone_runtime_config(
                        $settings['kintone']
                    );

                $response =
                    kintone_test(
                        $config
                    );

                $settings['kintone']['last_test'] =
                    now();

                save_settings($settings);

                flash(
                    'success',
                    'kintone接続テスト成功。HTTP ' .
                    (int)$response['status']
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'kintone接続テスト失敗：' .
                    safe_external_error($e)
                );
            }

            return [
                'screen' => 'kintone',
            ];

        case 'load_kintone_fields':
            try {
                $config =
                    kintone_runtime_config(
                        $settings['kintone']
                    );

                $response =
                    kintone_fields(
                        $config
                    );

                $fields =
                    kintone_field_list(
                        $response
                    );

                if (!$fields) {
                    throw new RuntimeException(
                        'kintoneから項目を取得できませんでした。'
                    );
                }

                $settings['kintone']['fields'] =
                    $fields;

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

            return [
                'screen' => 'kintone',
            ];

        case 'save_kintone_mapping':
            return handle_save_kintone_mapping(
                $settings
            );

        case 'sync_kintone':
            return handle_sync_kintone(
                $data,
                $settings
            );

        case 'save_mail':
            return handle_save_mail(
                $settings
            );

        case 'test_mail':
            try {
                $config =
                    mail_runtime_config(
                        $settings['mail']
                    );

                smtp_test_connection(
                    $config
                );

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

            return [
                'screen' => 'mail',
            ];

        case 'send_test_mail':
            try {
                $to =
                    post_string('test_email');

                $config =
                    mail_runtime_config(
                        $settings['mail']
                    );

                smtp_send(
                    $config,
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

            return [
                'screen' => 'mail',
            ];

        case 'send_mail':
            return handle_send_mail(
                $data,
                $settings
            );

        case 'answer_next':
            return handle_answer_next(
                $data
            );

        case 'answer_back':
            return [
                'screen' => 'answer',
                'id' =>
                    post_string('survey_id'),
            ];

        case 'submit_answer':
            return handle_submit_answer(
                $data
            );

        default:
            flash(
                'error',
                '指定された操作は存在しません。'
            );

            return [
                'screen' => 'list',
            ];
    }
}

/* =========================================================
 * kintone runtime config
 * ========================================================= */

function kintone_runtime_config(
    array $stored
): array {
    $password =
        decrypt_secret(
            (string)(
                $stored['password_enc'] ?? ''
            )
        );

    return [
        'subdomain' =>
            (string)($stored['subdomain'] ?? ''),
        'app_id' =>
            (string)($stored['app_id'] ?? ''),
        'username' =>
            (string)($stored['username'] ?? ''),
        'password' =>
            $password,
        'proxy' =>
            (string)($stored['proxy'] ?? ''),
        'verify_ssl' =>
            !empty($stored['verify_ssl']),
    ];
}

function mail_runtime_config(
    array $stored
): array {
    $password =
        decrypt_secret(
            (string)(
                $stored['password_enc'] ?? ''
            )
        );

    return [
        'host' =>
            (string)($stored['host'] ?? ''),
        'port' =>
            (int)($stored['port'] ?? 587),
        'encryption' =>
            (string)($stored['encryption'] ?? 'tls'),
        'auth' =>
            !empty($stored['auth']),
        'username' =>
            (string)($stored['username'] ?? ''),
        'password' =>
            $password,
        'from_email' =>
            (string)($stored['from_email'] ?? ''),
        'from_name' =>
            (string)($stored['from_name'] ?? ''),
        'reply_to' =>
            (string)($stored['reply_to'] ?? ''),
    ];
}

/* =========================================================
 * POST helper
 * ========================================================= */

function handle_save_survey(
    array &$data
): array {
    $id =
        post_string('survey_id');

    $title =
        post_string('title');

    $description =
        (string)post('description', '');

    $startAt =
        post_string('startAt');

    $endAt =
        post_string('endAt');

    $numbering =
        post_string('numbering');

    if (
        $title === ''
        || mb_strlen($title) > MAX_TITLE
    ) {
        flash(
            'error',
            'アンケートタイトルを正しく入力してください。'
        );

        return [
            'screen' => 'edit',
            'id' => $id,
        ];
    }

    if (
        mb_strlen($description)
        > MAX_DESCRIPTION
    ) {
        flash(
            'error',
            'アンケート説明が長すぎます。'
        );

        return [
            'screen' => 'edit',
            'id' => $id,
        ];
    }

    if (
        $numbering !== 'global'
        && $numbering !== 'group'
    ) {
        $numbering = 'global';
    }

    if ($id === '') {
        $survey = [
            'id' => uid('survey'),
            'title' => $title,
            'description' => $description,
            'startAt' => $startAt,
            'endAt' => $endAt,
            'status' => 'draft',
            'numbering' => $numbering,
            'createdAt' => now(),
            'updatedAt' => now(),
            'groups' => [[
                'id' => uid('group'),
                'title' => '基本アンケート',
                'questions' => [],
            ]],
        ];

        $data['surveys'][] =
            $survey;
    } else {
        $index =
            survey_index(
                $data['surveys'],
                $id
            );

        if ($index < 0) {
            flash(
                'error',
                '編集対象のアンケートが見つかりません。'
            );

            return [
                'screen' => 'list',
            ];
        }

        $survey =
            $data['surveys'][$index];

        $survey['title'] =
            $title;

        $survey['description'] =
            $description;

        $survey['startAt'] =
            $startAt;

        $survey['endAt'] =
            $endAt;

        $survey['numbering'] =
            $numbering;

        $survey['updatedAt'] =
            now();

        recalc_numbers(
            $survey
        );

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

function handle_status_change(
    array &$data
): array {
    $id =
        post_string('survey_id');

    $target =
        post_string('target_status');

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

    $current =
        (string)(
            $data['surveys'][$index]['status']
            ?? 'draft'
        );

    $allowed = [
        'draft' => ['published'],
        'published' => ['stopped'],
        'stopped' => ['published'],
        'ended' => [],
    ];

    if (
        !in_array(
            $target,
            $allowed[$current] ?? [],
            true
        )
    ) {
        flash(
            'error',
            '指定された状態変更は許可されていません。'
        );

        return [
            'screen' => 'list',
        ];
    }

    $data['surveys'][$index]['status'] =
        $target;

    $data['surveys'][$index]['updatedAt'] =
        now();

    save_data($data);

    flash(
        'success',
        'アンケートの状態を変更しました。'
    );

    return [
        'screen' => 'list',
    ];
}

function handle_save_kintone(
    array &$settings
): array {
    $current =
        $settings['kintone'];

    $password =
        post_string('password');

    if ($password === '') {
        $password =
            decrypt_secret(
                (string)(
                    $current['password_enc']
                    ?? ''
                )
            );
    }

    $config = [
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
    ];

    $errors =
        validate_kintone(
            $config,
            true
        );

    if ($errors) {
        flash(
            'error',
            implode("\n", $errors)
        );

        return [
            'screen' => 'kintone',
        ];
    }

    $settings['kintone']['subdomain'] =
        $config['subdomain'];

    $settings['kintone']['app_id'] =
        $config['app_id'];

    $settings['kintone']['username'] =
        $config['username'];

    $settings['kintone']['password_enc'] =
        encrypt_secret(
            $password
        );

    $settings['kintone']['proxy'] =
        $config['proxy'];

    $settings['kintone']['verify_ssl'] =
        $config['verify_ssl'];

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

function handle_save_kintone_mapping(
    array &$settings
): array {
    $fields =
        $settings['kintone']['fields']
        ?? [];

    $validCodes = [];

    foreach ($fields as $field) {
        if (isset($field['code'])) {
            $validCodes[] =
                (string)$field['code'];
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

    $address =
        post(
            'mapping_address',
            []
        );

    if (is_array($address)) {
        foreach ($address as $code) {
            $code =
                trim((string)$code);

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

    $settings['kintone']['mapping'] =
        $mapping;

    save_settings(
        $settings
    );

    flash(
        'success',
        'kintone項目マッピングを保存しました。'
    );

    return [
        'screen' => 'kintone',
    ];
}

/*
 * 顧客同期の中心処理。
 *
 * 1. kintoneへ実通信
 * 2. 全ページ取得
 * 3. mappingに従って顧客へ変換
 * 4. サーバー側へ保存
 * 5. 同期日時・件数を保存
 * 6. 同じkintone画面を表示
 *
 * 外部通信関数自身は画面遷移を行わない。
 */
function handle_sync_kintone(
    array &$data,
    array &$settings
): array {
    try {
        $config =
            kintone_runtime_config(
                $settings['kintone']
            );

        $mapping =
            $settings['kintone']['mapping']
            ?? [];

        if (
            trim(
                (string)($mapping['name'] ?? '')
            ) === ''
            && trim(
                (string)($mapping['email'] ?? '')
            ) === ''
        ) {
            throw new RuntimeException(
                '顧客情報の「氏名」または「メールアドレス」のマッピングを設定してください。'
            );
        }

        $response =
            kintone_records(
                $config
            );

        $records =
            $response['body']['records']
            ?? [];

        if (!is_array($records)) {
            throw new RuntimeException(
                'kintoneレコードを取得できませんでした。'
            );
        }

        $customers = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $customer =
                customer_from_krecord(
                    $record,
                    $mapping
                );

            if ($customer === null) {
                continue;
            }

            $customers[] =
                $customer;
        }

        /*
         * 同期結果が空の場合も成功として黙って保存しない。
         * kintoneに0件という正常状態は「同期成功・0件」として表示する。
         */
        $data['customers'] =
            $customers;

        $settings['kintone']['last_sync'] =
            now();

        $settings['kintone']['last_sync_count'] =
            count($customers);

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
    } catch (Throwable $e) {
        flash(
            'error',
            'kintone同期失敗：' .
            safe_external_error($e)
        );
    }

    /*
     * 同期結果を同じkintone画面へ返す。
     * redirectは行わない。
     */
    return [
        'screen' => 'kintone',
    ];
}

function handle_save_mail(
    array &$settings
): array {
    $current =
        $settings['mail'];

    $password =
        post_string('password');

    if ($password === '') {
        $password =
            decrypt_secret(
                (string)(
                    $current['password_enc']
                    ?? ''
                )
            );
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
    ];

    $errors =
        validate_mail(
            $config
        );

    if ($errors) {
        flash(
            'error',
            implode("\n", $errors)
        );

        return [
            'screen' => 'mail',
        ];
    }

    $settings['mail'] = [
        'host' =>
            $config['host'],
        'port' =>
            $config['port'],
        'encryption' =>
            $config['encryption'],
        'auth' =>
            $config['auth'],
        'username' =>
            $config['username'],
        'password_enc' =>
            encrypt_secret(
                $password
            ),
        'from_email' =>
            $config['from_email'],
        'from_name' =>
            $config['from_name'],
        'reply_to' =>
            $config['reply_to'],
        'last_test' =>
            $current['last_test'] ?? null,
    ];

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

function handle_send_mail(
    array &$data,
    array &$settings
): array {
    $surveyId =
        post_string('survey_id');

    $survey =
        survey_get(
            $data['surveys'],
            $surveyId
        );

    if (!$survey) {
        flash(
            'error',
            '対象アンケートが見つかりません。'
        );

        return [
            'screen' => 'list',
        ];
    }

    $selected =
        post(
            'customer_ids',
            []
        );

    if (
        !is_array($selected)
        || !$selected
    ) {
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
        $subject === ''
        || trim($body) === ''
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

    try {
        $mailConfig =
            mail_runtime_config(
                $settings['mail']
            );
    } catch (Throwable $e) {
        flash(
            'error',
            'メール設定取得失敗：' .
            safe_external_error($e)
        );

        return [
            'screen' => 'send',
            'id' => $surveyId,
        ];
    }

    $customerMap = [];

    foreach (
        $data['customers']
        as $customer
    ) {
        if (!is_array($customer)) {
            continue;
        }

        $customerMap[
            (string)($customer['id'] ?? '')
        ] = $customer;
    }

    $sent = 0;
    $failed = 0;

    foreach ($selected as $customerId) {
        $customerId =
            (string)$customerId;

        if (
            !isset(
                $customerMap[$customerId]
            )
        ) {
            $failed++;
            continue;
        }

        $customer =
            $customerMap[$customerId];

        $email =
            trim(
                (string)(
                    $customer['email'] ?? ''
                )
            );

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $failed++;

            $data['send_history'][] = [
                'id' =>
                    uid('send'),
                'survey_id' =>
                    $surveyId,
                'customer_id' =>
                    $customerId,
                'email' =>
                    $email,
                'status' =>
                    'failed',
                'message' =>
                    'メールアドレス不正',
                'createdAt' =>
                    now(),
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
                        $customer['name'] ?? ''
                    ),
                    public_url(
                        $surveyId
                    ),
                ],
                $body
            );

        try {
            smtp_send(
                $mailConfig,
                $email,
                $subject,
                $mailBody
            );

            $sent++;

            $data['send_history'][] = [
                'id' =>
                    uid('send'),
                'survey_id' =>
                    $surveyId,
                'customer_id' =>
                    $customerId,
                'email' =>
                    $email,
                'status' =>
                    'sent',
                'message' =>
                    '',
                'createdAt' =>
                    now(),
            ];
        } catch (Throwable $e) {
            $failed++;

            $data['send_history'][] = [
                'id' =>
                    uid('send'),
                'survey_id' =>
                    $surveyId,
                'customer_id' =>
                    $customerId,
                'email' =>
                    $email,
                'status' =>
                    'failed',
                'message' =>
                    safe_external_error($e),
                'createdAt' =>
                    now(),
            ];
        }
    }

    save_data(
        $data
    );

    flash(
        'success',
        '送信結果：成功 ' .
        $sent .
        '件 / 失敗 ' .
        $failed .
        '件'
    );

    return [
        'screen' => 'send',
        'id' => $surveyId,
    ];
}

function handle_answer_next(
    array &$data
): array {
    $surveyId =
        post_string('survey_id');

    $survey =
        survey_get(
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

    $answers =
        post(
            'answers',
            []
        );

    if (!is_array($answers)) {
        $answers = [];
    }

    $errors =
        validate_answers(
            $survey,
            $answers
        );

    if ($errors) {
        flash(
            'error',
            implode(
                "\n",
                $errors
            )
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
}

function handle_submit_answer(
    array &$data
): array {
    $surveyId =
        post_string('survey_id');

    $survey =
        survey_get(
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
        $_SESSION['answer_draft']
        ?? [];

    if (!is_array($draft)) {
        $draft = [];
    }

    $errors =
        validate_answers(
            $survey,
            $draft
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
            'screen' => 'answer',
            'id' => $surveyId,
        ];
    }

    $data['answers'][] = [
        'id' =>
            uid('answer'),
        'survey_id' =>
            $surveyId,
        'answers' =>
            $draft,
        'createdAt' =>
            now(),
    ];

    save_data(
        $data
    );

    unset(
        $_SESSION['answer_draft']
    );

    /*
     * 回答者フローは必ずcompleteへ。
     * 管理者画面へ戻さない。
     */
    return [
        'screen' => 'complete',
        'id' => $surveyId,
    ];
}

/* =========================================================
 * CSS / 管理者共通
 * ========================================================= */

function admin_header(
    string $title
): void {
    $flash =
        flash_get();

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
 content="width=device-width,initial-scale=1">
<title><?= h(APP_TITLE) ?> - <?= h($title) ?></title>
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
header.admin{
 background:#0f172a;
 color:#fff;
}
header.admin .inner{
 max-width:1200px;
 margin:auto;
 padding:14px 20px;
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:20px;
}
header.admin a{
 color:#fff;
 text-decoration:none;
}
nav{
 display:flex;
 gap:8px;
 flex-wrap:wrap;
}
nav a{
 padding:8px 10px;
 border-radius:7px;
}
nav a:hover{
 background:rgba(255,255,255,.1);
}
.wrap{
 max-width:1200px;
 margin:28px auto;
 padding:0 20px;
}
.card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:14px;
 padding:22px;
 margin-bottom:18px;
 box-shadow:var(--shadow);
}
.grid{
 display:grid;
 grid-template-columns:repeat(2,minmax(0,1fr));
 gap:16px;
}
.field{
 margin:14px 0;
}
.field label{
 display:block;
 font-weight:600;
 margin-bottom:7px;
}
input,select,textarea{
 width:100%;
 padding:11px 12px;
 border:1px solid #cbd5e1;
 border-radius:8px;
 background:#fff;
 color:var(--text);
 font:inherit;
}
textarea{
 min-height:140px;
 resize:vertical;
}
button,.button{
 display:inline-block;
 padding:10px 15px;
 border-radius:8px;
 border:1px solid #cbd5e1;
 background:#fff;
 color:var(--text);
 cursor:pointer;
 text-decoration:none;
 font:inherit;
}
button.primary,.button.primary{
 background:var(--primary);
 border-color:var(--primary);
 color:#fff;
}
button.primary:hover,.button.primary:hover{
 background:var(--primary-dark);
}
button.danger,.button.danger{
 color:#fff;
 background:var(--danger);
 border-color:var(--danger);
}
button:disabled{
 opacity:.5;
 cursor:not-allowed;
}
.actions{
 display:flex;
 gap:8px;
 flex-wrap:wrap;
 align-items:center;
}
.table-wrap{
 overflow-x:auto;
}
table{
 width:100%;
 border-collapse:collapse;
 min-width:760px;
}
th,td{
 border-bottom:1px solid #e2e8f0;
 padding:10px 9px;
 text-align:left;
 vertical-align:top;
}
th{
 background:#f8fafc;
}
.badge{
 display:inline-block;
 padding:4px 9px;
 border-radius:999px;
 font-size:12px;
}
.badge.success{
 background:#dcfce7;
 color:#166534;
}
.badge.warning{
 background:#fef3c7;
 color:#92400e;
}
.badge.danger{
 background:#fee2e2;
 color:#991b1b;
}
.badge.gray{
 background:#e2e8f0;
 color:#475569;
}
.flash{
 padding:14px 16px;
 border-radius:9px;
 margin-bottom:18px;
 white-space:pre-line;
}
.flash.success{
 background:#dcfce7;
 color:#166534;
}
.flash.error{
 background:#fee2e2;
 color:#991b1b;
}
.flash.warning{
 background:#fef3c7;
 color:#92400e;
}
.muted{
 color:var(--gray);
}
.small{
 font-size:13px;
}
.empty{
 padding:30px;
 text-align:center;
 color:var(--gray);
}
.drag{
 cursor:grab;
}
@media(max-width:760px){
 .grid{
  grid-template-columns:1fr;
 }
 .wrap{
  padding:0 12px;
  margin-top:18px;
 }
 header.admin .inner{
  align-items:flex-start;
  flex-direction:column;
 }
 .card{
  padding:16px;
 }
}
</style>
</head>
<body>
<header class="admin">
<div class="inner">
<div>
<a href="<?= h(app_url(['screen'=>'list'])) ?>">
<strong><?= h(APP_TITLE) ?></strong>
</a>
</div>
<nav>
<a href="<?= h(app_url(['screen'=>'list'])) ?>">アンケート一覧</a>
<a href="<?= h(app_url(['screen'=>'kintone'])) ?>">kintone</a>
<a href="<?= h(app_url(['screen'=>'mail'])) ?>">メール設定</a>
</nav>
</div>
</header>
<div class="wrap">
<?php if ($flash): ?>
<div class="flash <?= h($flash['type']) ?>">
<?= h($flash['message']) ?>
</div>
<?php endif; ?>
<?php
}

function admin_footer(): void
{
    ?>
</div>
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
    $search =
        get_string('q');

    $status =
        get_string('status');

    $sort =
        get_string('sort');

    $surveys =
        $data['surveys'];

    $surveys =
        array_values(
            array_filter(
                $surveys,
                static function (
                    array $survey
                ) use (
                    $search,
                    $status
                ): bool {
                    if (
                        $search !== ''
                        && mb_stripos(
                            (string)($survey['title'] ?? ''),
                            $search
                        ) === false
                    ) {
                        return false;
                    }

                    if (
                        $status !== ''
                        && $status !== 'all'
                        && (string)(
                            $survey['status'] ?? ''
                        ) !== $status
                    ) {
                        return false;
                    }

                    return true;
                }
            )
        );

    usort(
        $surveys,
        static function (
            array $a,
            array $b
        ) use ($sort): int {
            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)($a['updatedAt'] ?? ''),
                        (string)($b['updatedAt'] ?? '')
                    ),
                'answers_desc' =>
                    survey_answer_count(
                        $GLOBALS['__app_data'],
                        (string)($b['id'] ?? '')
                    )
                    <=>
                    survey_answer_count(
                        $GLOBALS['__app_data'],
                        (string)($a['id'] ?? '')
                    ),
                'answers_asc' =>
                    survey_answer_count(
                        $GLOBALS['__app_data'],
                        (string)($a['id'] ?? '')
                    )
                    <=>
                    survey_answer_count(
                        $GLOBALS['__app_data'],
                        (string)($b['id'] ?? '')
                    ),
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

    admin_header('アンケート一覧');
    ?>
<h1>アンケート一覧</h1>

<div class="card">
<form method="get">
<input type="hidden"
 name="screen"
 value="list">

<div class="grid">
<div class="field">
<label>タイトル検索</label>
<input name="q"
 value="<?= h($search) ?>"
 placeholder="タイトル部分一致">
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

<div class="field">
<label>ソート</label>
<select name="sort">
<option value="updated_desc"
 <?= $sort===''||$sort==='updated_desc'?'selected':'' ?>>
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
</div>

<button class="primary">検索</button>
</form>
</div>

<div class="card">
<div class="actions">
<a class="button primary"
 href="<?= h(app_url(['screen'=>'edit'])) ?>">
アンケートを新規作成
</a>
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
<?php if (!$surveys): ?>
<tr>
<td colspan="7" class="empty">
該当するアンケートはありません。
</td>
</tr>
<?php endif; ?>

<?php foreach ($surveys as $survey): ?>
<?php
$sid =
    (string)$survey['id'];

$count =
    survey_answer_count(
        $data,
        $sid
    );

$surveyStatus =
    (string)(
        $survey['status'] ?? 'draft'
    );
?>
<tr>
<td>
<strong><?= h($survey['title']) ?></strong>
</td>
<td><?= h($survey['createdAt'] ?? '') ?></td>
<td><?= h($survey['updatedAt'] ?? '') ?></td>
<td>
<?= h($survey['startAt'] ?? '') ?>
<br>
～
<?= h($survey['endAt'] ?? '') ?>
</td>
<td>
<span class="badge <?= h(
    status_class($surveyStatus)
) ?>">
<?= h(
    status_label($surveyStatus)
) ?>
</span>
</td>
<td><?= h($count) ?></td>
<td>
<div class="actions">
<a class="button"
 href="<?= h(app_url([
     'screen'=>'edit',
     'id'=>$sid
 ])) ?>">
確認・編集
</a>

<a class="button"
 href="<?= h(app_url([
     'screen'=>'preview',
     'id'=>$sid
 ])) ?>">
プレビュー
</a>

<a class="button"
 href="<?= h(app_url([
     'screen'=>'analytics',
     'id'=>$sid
 ])) ?>">
集計
</a>

<a class="button"
 href="<?= h(app_url([
     'screen'=>'send',
     'id'=>$sid
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
 name="survey_id"
 value="<?= h($sid) ?>">
<button>複製</button>
</form>

<form method="post"
 style="display:inline"
 onsubmit="return confirm('このアンケートを削除しますか？')">
<input type="hidden"
 name="action"
 value="delete_survey">
<input type="hidden"
 name="survey_id"
 value="<?= h($sid) ?>">
<button class="danger">削除</button>
</form>
</div>
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

function survey_answer_count(
    array $data,
    string $surveyId
): int {
    $count = 0;

    foreach (
        $data['answers'] ?? []
        as $answer
    ) {
        if (
            (string)(
                $answer['survey_id'] ?? ''
            ) === $surveyId
        ) {
            $count++;
        }
    }

    return $count;
}

/* =========================================================
 * 編集
 * ========================================================= */

function render_edit(
    array $data,
    ?string $id
): void {
    $survey =
        $id !== null
            ? survey_get(
                $data['surveys'],
                $id
            )
            : null;

    if ($id !== null && !$survey) {
        render_error(
            '編集対象のアンケートが見つかりません。'
        );
        return;
    }

    if (!$survey) {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' =>
                date('Y-m-d\TH:i'),
            'endAt' =>
                date(
                    'Y-m-d\TH:i',
                    strtotime('+30 days')
                ),
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [[
                'id' => uid('group'),
                'title' => '基本アンケート',
                'questions' => [],
            ]],
        ];
    }

    admin_header(
        $survey['id'] === ''
            ? 'アンケート作成'
            : 'アンケート編集'
    );
    ?>
<h1>
<?= $survey['id']===''?'アンケート作成':'アンケート編集' ?>
</h1>

<div class="card">
<div class="actions">
<a class="button"
 href="<?= h(app_url(['screen'=>'list'])) ?>">
キャンセル
</a>
</div>
</div>

<div class="card">
<form method="post">
<input type="hidden"
 name="action"
 value="save_survey">
<input type="hidden"
 name="survey_id"
 value="<?= h($survey['id']) ?>">

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

<div class="grid">
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
    ?'selected':'' ?>>
アンケート全体で通番
</option>
<option value="group"
 <?= ($survey['numbering']??'')==='group'
    ?'selected':'' ?>>
グループ毎
</option>
</select>
</div>

<div class="actions">
<button class="primary">
保存して一覧へ
</button>
</div>
</form>
</div>

<div class="card">
<h2>質問・グループ</h2>
<p class="muted small">
現在の仕様では保存後の質問編集データは
サーバー側へ永続化されます。
質問番号は自動採番されます。
</p>

<?php foreach (
    $survey['groups'] ?? []
    as $group
): ?>
<div class="card" style="box-shadow:none">
<h3>
<?= h($group['title'] ?? '') ?>
</h3>

<?php foreach (
    $group['questions'] ?? []
    as $question
): ?>
<div class="card"
 style="box-shadow:none;background:#f8fafc">
<strong>
<?= h($question['number'] ?? '') ?>
</strong>
<br>
<?= nl2br(h($question['text'] ?? '')) ?>
<br>
<span class="muted">
<?= h($question['type'] ?? '') ?>
/
<?= !empty($question['required'])
    ? '必須'
    : '任意' ?>
</span>
</div>
<?php endforeach; ?>

</div>
<?php endforeach; ?>

<p class="muted">
質問・グループの詳細編集は、
保存データ構造を壊さない形で実装してください。
</p>
</div>

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
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if (!$survey) {
        render_error(
            'プレビュー対象のアンケートが見つかりません。'
        );
        return;
    }

    admin_header('プレビュー');
    ?>
<h1>アンケートプレビュー</h1>

<div class="card">
<h2><?= h($survey['title']) ?></h2>
<p><?= nl2br(h($survey['description'])) ?></p>
</div>

<?php foreach (
    all_questions($survey)
    as $question
): ?>
<div class="card">
<strong>
<?= h($question['number']) ?>
</strong>
<p>
<?= nl2br(h($question['text'])) ?>
</p>

<?php if (
    ($question['type'] ?? '') === 'single'
): ?>
<?php foreach (
    $question['options'] ?? []
    as $option
): ?>
<label style="display:block;margin:8px 0">
<input type="radio" disabled>
<?= h($option['label']) ?>
</label>
<?php endforeach; ?>

<?php elseif (
    ($question['type'] ?? '') === 'multiple'
): ?>
<?php foreach (
    $question['options'] ?? []
    as $option
): ?>
<label style="display:block;margin:8px 0">
<input type="checkbox" disabled>
<?= h($option['label']) ?>
</label>
<?php endforeach; ?>

<?php else: ?>
<textarea disabled></textarea>
<?php endif; ?>
</div>
<?php endforeach; ?>

<?php
admin_footer();
}

/* =========================================================
 * kintone画面
 * ========================================================= */

function render_kintone(
    array $settings,
    array $data
): void {
    $config =
        $settings['kintone'];

    $fields =
        $config['fields'] ?? [];

    $mapping =
        $config['mapping'] ?? [];

    $customers =
        $data['customers'] ?? [];

    admin_header('kintone連携');
    ?>

<h1>kintone連携設定</h1>

<div class="card">
<h2>接続設定</h2>

<form method="post">
<input type="hidden"
 name="action"
 value="save_kintone">

<div class="field">
<label>kintoneサブドメイン</label>
<input name="subdomain"
 value="<?= h($config['subdomain']) ?>"
 placeholder="https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx">
</div>

<div class="field">
<label>顧客管理アプリID</label>
<input name="app_id"
 value="<?= h($config['app_id']) ?>">
</div>

<div class="field">
<label>ログイン名</label>
<input name="username"
 value="<?= h($config['username']) ?>">
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
 value="<?= h($config['proxy']) ?>"
 placeholder="host:port">
</div>

<div class="field">
<label>
<input type="checkbox"
 name="verify_ssl"
 value="1"
 <?= !empty($config['verify_ssl'])
     ? 'checked'
     : '' ?>>
SSL証明書を検証する
</label>
</div>

<button class="primary">
設定保存
</button>
</form>
</div>

<div class="card">
<h2>接続テスト</h2>

<form method="post">
<input type="hidden"
 name="action"
 value="test_kintone">
<button class="primary">
接続テスト
</button>
</form>

<?php if (!empty($config['last_test'])): ?>
<p class="small muted">
最終接続テスト：
<?= h($config['last_test']) ?>
</p>
<?php endif; ?>
</div>

<div class="card">
<h2>項目一覧</h2>

<form method="post">
<input type="hidden"
 name="action"
 value="load_kintone_fields">
<button>
項目一覧を再取得
</button>
</form>

<?php if ($fields): ?>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>コード</th>
<th>ラベル</th>
<th>タイプ</th>
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
</div>
<?php else: ?>
<p class="empty">
まだ項目を取得していません。
</p>
<?php endif; ?>
</div>

<div class="card">
<h2>顧客項目マッピング</h2>

<form method="post">
<input type="hidden"
 name="action"
 value="save_kintone_mapping">

<?php
$mapFields = [
    'organization' => '組織名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署名',
    'phone' => '電話番号',
];
?>

<?php foreach (
    $mapFields
    as $key => $label
): ?>
<div class="field">
<label><?= h($label) ?></label>
<select name="mapping_<?= h($key) ?>">
<option value="">未設定</option>
<?php foreach ($fields as $field): ?>
<option
 value="<?= h($field['code']) ?>"
 <?= ($mapping[$key] ?? '') ===
     $field['code']
     ? 'selected'
     : '' ?>>
<?= h(
    $field['code'] .
    ' / ' .
    $field['label']
) ?>
</option>
<?php endforeach; ?>
</select>
</div>
<?php endforeach; ?>

<div class="field">
<label>住所（複数項目可）</label>

<?php foreach (
    $fields
    as $field
): ?>
<label style="display:block;margin:7px 0">
<input type="checkbox"
 name="mapping_address[]"
 value="<?= h($field['code']) ?>"
 <?= in_array(
        $field['code'],
        $mapping['address'] ?? [],
        true
    )
    ? 'checked'
    : '' ?>>
<?= h(
    $field['code'] .
    ' / ' .
    $field['label']
) ?>
</label>
<?php endforeach; ?>
</div>

<button class="primary">
マッピング保存
</button>
</form>
</div>

<div class="card">
<h2>顧客情報同期</h2>

<p>
kintoneの顧客管理アプリから顧客情報を取得し、
サーバー側の顧客データを置き換えます。
</p>

<form method="post"
 onsubmit="this.querySelector('button').disabled=true">
<input type="hidden"
 name="action"
 value="sync_kintone">
<button class="primary">
顧客情報を同期
</button>
</form>

<?php if (!empty($config['last_sync'])): ?>
<p class="small muted">
最終同期：
<?= h($config['last_sync']) ?>
/
<?= h(
    (int)($config['last_sync_count'] ?? 0)
) ?>件
</p>
<?php endif; ?>
</div>

<!--
     重要:
     ここが旧実装に存在しなかった
     「同期した顧客情報を元にした顧客一覧」。
-->
<div class="card">
<h2>同期済み顧客一覧</h2>

<?php if (!$customers): ?>
<div class="empty">
同期済みの顧客情報はありません。
</div>
<?php else: ?>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>氏名</th>
<th>組織名</th>
<th>部署名</th>
<th>メールアドレス</th>
<th>電話番号</th>
<th>住所</th>
<th>kintone ID</th>
</tr>
</thead>
<tbody>

<?php foreach (
    $customers
    as $customer
): ?>
<tr>
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

<td>
<?= h(
    $customer['phone'] ?? ''
) ?>
</td>

<td>
<?= h(
    $customer['address'] ?? ''
) ?>
</td>

<td>
<?= h(
    $customer['kintone_id'] ?? ''
) ?>
</td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>

<p class="small muted">
<?= h(count($customers)) ?>件
</p>

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
    $config =
        $settings['mail'];

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
 value="<?= h($config['host']) ?>">
</div>

<div class="field">
<label>SMTPポート</label>
<input type="number"
 name="port"
 min="1"
 max="65535"
 value="<?= h($config['port']) ?>">
</div>
</div>

<div class="field">
<label>暗号化方式</label>
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
</div>

<div class="field">
<label>
<input type="checkbox"
 name="auth"
 value="1"
 <?= !empty($config['auth'])
     ? 'checked'
     : '' ?>>
SMTP認証を使用する
</label>
</div>

<div class="grid">
<div class="field">
<label>SMTPユーザー名</label>
<input name="username"
 value="<?= h($config['username']) ?>">
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
 value="<?= h($config['from_email']) ?>">
</div>

<div class="field">
<label>送信元名</label>
<input name="from_name"
 value="<?= h($config['from_name']) ?>">
</div>
</div>

<div class="field">
<label>返信先メールアドレス</label>
<input type="email"
 name="reply_to"
 value="<?= h($config['reply_to']) ?>">
</div>

<button class="primary">
設定保存
</button>
</form>
</div>

<div class="card">
<h2>接続テスト</h2>
<p>
SMTP接続および認証まで実際に実行します。
</p>

<form method="post">
<input type="hidden"
 name="action"
 value="test_mail">
<button class="primary">
接続テスト
</button>
</form>

<?php if (!empty($config['last_test'])): ?>
<p class="small muted">
最終接続テスト：
<?= h($config['last_test']) ?>
</p>
<?php endif; ?>
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
 * 送信
 * ========================================================= */

function render_send(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if (!$survey) {
        render_error(
            '対象アンケートが見つかりません。'
        );
        return;
    }

    $history =
        array_values(
            array_filter(
                $data['send_history'],
                static fn(array $item): bool =>
                    (string)(
                        $item['survey_id'] ?? ''
                    ) === $id
            )
        );

    admin_header(
        '顧客選択・メール送信'
    );
    ?>
<h1>顧客選択・メール送信</h1>

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
 placeholder="氏名・組織・メール・部署">
</div>

<div class="table-wrap">
<table id="customerTable">
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
    $data['customers']
    as $customer
): ?>

<?php
$searchText =
    implode(
        ' ',
        [
            $customer['name'] ?? '',
            $customer['organization'] ?? '',
            $customer['department'] ?? '',
            $customer['email'] ?? '',
        ]
    );
?>

<tr data-search="<?= h(
    mb_strtolower($searchText)
) ?>">
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

<?php if (!$data['customers']): ?>
<p class="empty">
顧客情報がありません。
先にkintone画面から顧客情報を同期してください。
</p>
<?php endif; ?>

<div class="field">
<label>件名</label>
<input name="subject"
 required
 value="<?= h(
     $survey['title'] .
     ' のご案内'
 ) ?>">
</div>

<div class="field">
<label>本文</label>
<p>
使用可能な変数：
<code>{顧客名}</code>
<code>{アンケートURL}</code>
</p>

<textarea name="body"
 required>いつもお世話になっております。

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>
</div>

<button class="primary">
一括送信
</button>
</form>
</div>

<div class="card">
<h2>送信履歴</h2>

<?php if (!$history): ?>
<p class="empty">
送信履歴はありません。
</p>
<?php else: ?>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>メール</th>
<th>結果</th>
<th>内容</th>
</tr>
</thead>
<tbody>

<?php foreach (
    array_reverse($history)
    as $item
): ?>
<tr>
<td><?= h(
    $item['createdAt'] ?? ''
) ?></td>

<td><?= h(
    $item['email'] ?? ''
) ?></td>

<td>
<?= ($item['status'] ?? '') === 'sent'
    ? '送信済み'
    : '失敗' ?>
</td>

<td><?= h(
    $item['message'] ?? ''
) ?></td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>

<?php endif; ?>
</div>

<script>
const customerSearch =
    document.getElementById('customerSearch');

customerSearch?.addEventListener(
    'input',
    () => {
        const query =
            customerSearch.value
                .trim()
                .toLowerCase();

        document
            .querySelectorAll(
                '#customerTable tbody tr'
            )
            .forEach((row) => {
                const value =
                    (
                        row.dataset.search || ''
                    ).toLowerCase();

                row.style.display =
                    !query ||
                    value.includes(query)
                        ? ''
                        : 'none';
            });
    }
);
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
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if (!$survey) {
        render_error(
            '対象アンケートが見つかりません。'
        );
        return;
    }

    $answers =
        array_values(
            array_filter(
                $data['answers'],
                static fn(array $answer): bool =>
                    (string)(
                        $answer['survey_id'] ?? ''
                    ) === $id
            )
        );

    $sentCustomerIds = [];

    foreach (
        $data['send_history']
        as $history
    ) {
        if (
            (string)(
                $history['survey_id'] ?? ''
            ) !== $id
        ) {
            continue;
        }

        if (
            ($history['status'] ?? '') ===
            'sent'
        ) {
            $sentCustomerIds[
                (string)(
                    $history['customer_id']
                    ?? ''
                )
            ] = true;
        }
    }

    $sendTargetCount =
        count($sentCustomerIds);

    $answerCount =
        count($answers);

    $rate =
        $sendTargetCount > 0
            ? round(
                ($answerCount /
                    $sendTargetCount) *
                100,
                1
            )
            : 0;

    admin_header('回答集計・分析');
    ?>
<h1>回答集計・分析</h1>

<div class="card">
<h2>対象アンケート</h2>
<strong><?= h($survey['title']) ?></strong>
</div>

<div class="grid">
<div class="card">
<strong>送信対象者数</strong>
<h2><?= h($sendTargetCount) ?></h2>
</div>

<div class="card">
<strong>回答数</strong>
<h2><?= h($answerCount) ?></h2>
</div>

<div class="card">
<strong>未登録回答数</strong>
<h2>0</h2>
</div>

<div class="card">
<strong>未回答数</strong>
<h2>
<?= h(
    max(
        0,
        $sendTargetCount -
        $answerCount
    )
) ?>
</h2>
</div>

<div class="card">
<strong>回答率</strong>
<h2><?= h($rate) ?>%</h2>
</div>
</div>

<div class="card">
<div class="actions">
<a class="button"
 href="<?= h(app_url([
     'screen'=>'analytics',
     'id'=>$id,
     'export'=>'csv'
 ])) ?>">
CSV出力
</a>

<a class="button"
 href="<?= h(app_url([
     'screen'=>'analytics',
     'id'=>$id,
     'export'=>'pdf'
 ])) ?>">
PDF出力
</a>
</div>
</div>

<div class="card">
<h2>設問別集計</h2>

<?php if (!$answers): ?>
<p class="empty">
現在、回答データはありません
</p>
<?php else: ?>

<?php foreach (
    all_questions($survey)
    as $question
): ?>

<div class="card"
 style="box-shadow:none">
<strong>
<?= h($question['number']) ?>
</strong>
<p>
<?= nl2br(h($question['text'])) ?>
</p>

<?php
$counts = [];

foreach (
    $question['options'] ?? []
    as $option
) {
    $counts[
        (string)$option['id']
    ] = 0;
}

foreach (
    $answers
    as $answer
) {
    $value =
        $answer['answers'][
            $question['id']
        ] ?? '';

    if (is_array($value)) {
        foreach ($value as $item) {
            if (isset($counts[$item])) {
                $counts[$item]++;
            }
        }
    } else {
        if (isset($counts[$value])) {
            $counts[$value]++;
        }
    }
}
?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>
<div>
<?= h($option['label']) ?>：
<?= h(
    $counts[
        $option['id']
    ] ?? 0
) ?>件
</div>
<?php endforeach; ?>

</div>
<?php endforeach; ?>

<?php endif; ?>
</div>

<div class="card">
<h2>個別回答</h2>

<?php if (!$answers): ?>
<p class="empty">
現在、回答データはありません
</p>
<?php else: ?>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>回答</th>
</tr>
</thead>
<tbody>

<?php foreach (
    $answers
    as $answer
): ?>
<tr>
<td><?= h(
    $answer['createdAt'] ?? ''
) ?></td>
<td>
<?php foreach (
    all_questions($survey)
    as $question
): ?>
<div style="margin-bottom:10px">
<strong>
<?= h($question['number']) ?>
</strong>
<br>
<?php
$value =
    $answer['answers'][
        $question['id']
    ] ?? '';

if (is_array($value)) {
    $labels = [];

    foreach (
        $question['options'] ?? []
        as $option
    ) {
        if (
            in_array(
                $option['id'],
                $value,
                true
            )
        ) {
            $labels[] =
                $option['label'];
        }
    }

    echo h(
        implode(
            ', ',
            $labels
        )
    );
} else {
    echo nl2br(
        h((string)$value)
    );
}
?>
</div>
<?php endforeach; ?>
</td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>

<?php endif; ?>
</div>

<?php
admin_footer();
}

/* =========================================================
 * CSV
 * ========================================================= */

function output_csv(
    array $survey,
    array $answers
): void {
    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="survey-' .
        preg_replace(
            '/[^A-Za-z0-9_-]/',
            '_',
            (string)$survey['id']
        ) .
        '.csv"'
    );

    $fp = fopen(
        'php://output',
        'wb'
    );

    if (!$fp) {
        throw new RuntimeException(
            'CSV出力を開始できません。'
        );
    }

    fwrite(
        $fp,
        "\xEF\xBB\xBF"
    );

    $header = [
        '回答日時',
    ];

    foreach (
        all_questions($survey)
        as $question
    ) {
        $header[] =
            (string)$question['number'] .
            ' ' .
            (string)$question['text'];
    }

    fputcsv(
        $fp,
        $header
    );

    foreach (
        $answers
        as $answer
    ) {
        $row = [
            (string)(
                $answer['createdAt'] ?? ''
            ),
        ];

        foreach (
            all_questions($survey)
            as $question
        ) {
            $value =
                $answer['answers'][
                    $question['id']
                ] ?? '';

            if (is_array($value)) {
                $labels = [];

                foreach (
                    $question['options'] ?? []
                    as $option
                ) {
                    if (
                        in_array(
                            $option['id'],
                            $value,
                            true
                        )
                    ) {
                        $labels[] =
                            $option['label'];
                    }
                }

                $row[] =
                    implode(
                        ', ',
                        $labels
                    );
            } else {
                $row[] =
                    (string)$value;
            }
        }

        fputcsv(
            $fp,
            $row
        );
    }

    fclose($fp);
    exit;
}

/* =========================================================
 * PDF
 * ========================================================= */

function output_pdf(
    array $survey,
    array $answers
): void {
    /*
     * 外部PDFライブラリを要求しない環境のため、
     * 実データをHTMLとしてPDF印刷可能な文書として出力する。
     */
    header(
        'Content-Type: text/html; charset=UTF-8'
    );

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title><?= h($survey['title']) ?></title>
<style>
body{
 font-family:Arial,
 "Noto Sans JP",sans-serif;
 padding:30px;
}
.answer{
 margin-bottom:20px;
 page-break-inside:avoid;
}
</style>
</head>
<body>
<h1><?= h($survey['title']) ?></h1>

<?php foreach (
    $answers
    as $answer
): ?>
<div class="answer">
<strong>
<?= h($answer['createdAt'] ?? '') ?>
</strong>

<?php foreach (
    all_questions($survey)
    as $question
): ?>
<div>
<strong>
<?= h($question['number']) ?>
</strong>
:
<?php
$value =
    $answer['answers'][
        $question['id']
    ] ?? '';

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
    echo nl2br(
        h((string)$value)
    );
}
?>
</div>
<?php endforeach; ?>

</div>
<?php endforeach; ?>

<script>
window.print();
</script>
</body>
</html>
<?php
exit;
}

/* =========================================================
 * 回答者画面
 * ========================================================= */

function render_answer(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if (!$survey) {
        render_answer_message(
            'アンケートが見つかりません。'
        );
        return;
    }

    if (
        ($survey['status'] ?? '') !==
        'published'
    ) {
        render_answer_message(
            '現在、このアンケートは回答を受け付けていません。'
        );
        return;
    }

    $answers =
        $_SESSION['answer_draft']
        ?? [];

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
<title><?= h($survey['title']) ?></title>
<style>
body{
 margin:0;
 background:#f8fafc;
 color:#1e293b;
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",
 "Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
}
.wrap{
 max-width:760px;
 margin:30px auto;
 padding:20px;
}
.card{
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:14px;
 padding:24px;
 margin-bottom:16px;
 box-shadow:0 4px 18px rgba(15,23,42,.08);
}
input,textarea{
 width:100%;
 box-sizing:border-box;
 padding:13px;
 border:1px solid #cbd5e1;
 border-radius:8px;
 font:inherit;
}
textarea{
 min-height:150px;
}
.option{
 display:block;
 padding:12px;
 margin:8px 0;
 border:1px solid #dbe2ea;
 border-radius:8px;
}
button{
 padding:13px 20px;
 border:0;
 border-radius:8px;
 background:#2563eb;
 color:#fff;
 font:inherit;
}
</style>
</head>
<body>
<div class="wrap">

<div class="card">
<h1><?= h($survey['title']) ?></h1>
<p>
<?= nl2br(h($survey['description'])) ?>
</p>
</div>

<form method="post">
<input type="hidden"
 name="action"
 value="answer_next">

<input type="hidden"
 name="survey_id"
 value="<?= h($id) ?>">

<?php foreach (
    visible_questions(
        $survey,
        $answers
    )
    as $question
): ?>

<div class="card">
<strong>
<?= h($question['number']) ?>
</strong>

<p>
<?= nl2br(h($question['text'])) ?>
<?php if (
    !empty($question['required'])
): ?>
<span style="color:#dc2626">
必須
</span>
<?php endif; ?>
</p>

<?php
$value =
    $answers[
        $question['id']
    ] ?? '';
?>

<?php if (
    ($question['type'] ?? '') ===
    'single'
): ?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>
<label class="option">
<input type="radio"
 name="answers[<?= h($question['id']) ?>]"
 value="<?= h($option['id']) ?>"
 <?= (string)$value ===
     (string)$option['id']
     ? 'checked'
     : '' ?>>
<?= h($option['label']) ?>
</label>
<?php endforeach; ?>

<?php elseif (
    ($question['type'] ?? '') ===
    'multiple'
): ?>

<?php
$selected =
    is_array($value)
        ? $value
        : [];
?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>
<label class="option">
<input type="checkbox"
 name="answers[<?= h($question['id']) ?>][]"
 value="<?= h($option['id']) ?>"
 <?= in_array(
        $option['id'],
        $selected,
        true
    )
    ? 'checked'
    : '' ?>>
<?= h($option['label']) ?>
</label>
<?php endforeach; ?>

<?php else: ?>

<textarea
 name="answers[<?= h($question['id']) ?>]"
 <?= !empty($question['required'])
     ? 'required'
     : '' ?>><?= h(
    is_scalar($value)
        ? $value
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
</body>
</html>
<?php
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

    if (!$survey) {
        render_answer_message(
            'アンケートが見つかりません。'
        );
        return;
    }

    $answers =
        $_SESSION['answer_draft']
        ?? [];

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
 padding:16px;
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

<?php foreach (
    visible_questions(
        $survey,
        $answers
    )
    as $question
): ?>

<div class="row">
<strong>
<?= h($question['number']) ?>
</strong>
<br>
<?= nl2br(h($question['text'])) ?>
<br>
<strong>
<?php
$value =
    $answers[
        $question['id']
    ] ?? '';

if (is_array($value)) {
    $labels = [];

    foreach (
        $question['options'] ?? []
        as $option
    ) {
        if (
            in_array(
                $option['id'],
                $value,
                true
            )
        ) {
            $labels[] =
                $option['label'];
        }
    }

    echo h(
        implode(', ', $labels)
    );
} else {
    $label =
        $value;

    foreach (
        $question['options'] ?? []
        as $option
    ) {
        if (
            (string)$option['id'] ===
            (string)$value
        ) {
            $label =
                $option['label'];
            break;
        }
    }

    echo nl2br(
        h((string)$label)
    );
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
<button>
回答を修正
</button>
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

function render_complete(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
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
 color:#1e293b;
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",Meiryo,sans-serif;
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
 * エラー
 * ========================================================= */

function render_error(
    string $message
): void {
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
<div class="error">
<?= h($message) ?>
</div>
<p>
入力内容・設定内容を確認して、もう一度実行してください。
</p>
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

    $data =
        load_data();

    $settings =
        load_settings();

    /*
     * 一覧等の表示前に自動終了状態を反映。
     */
    refresh_status(
        $data
    );

    /*
     * render_list() のソート処理で利用する。
     */
    $GLOBALS['__app_data'] =
        $data;

    $route = [
        'screen' =>
            get_string('screen')
            ?: 'list',
        'id' =>
            get_string('id'),
    ];

    /*
     * POST:
     *   受信
     *   ↓
     *   検証
     *   ↓
     *   業務処理
     *   ↓
     *   保存
     *   ↓
     *   結果画面決定
     *
     * 外部サービス関数から
     * header("Location") は行わない。
     */
    if (
        $_SERVER['REQUEST_METHOD']
        === 'POST'
    ) {
        $route =
            handle_post(
                $data,
                $settings
            );

        $GLOBALS['__app_data'] =
            $data;
    }

    $screen =
        (string)(
            $route['screen']
            ?? 'list'
        );

    $id =
        (string)(
            $route['id']
            ?? ''
        );

    /*
     * CSV
     */
    if (
        $screen === 'analytics'
        && $id !== ''
        && get_string('export') === 'csv'
    ) {
        $survey =
            survey_get(
                $data['surveys'],
                $id
            );

        if (!$survey) {
            render_error(
                '対象アンケートが見つかりません。'
            );
            exit;
        }

        $answers =
            array_values(
                array_filter(
                    $data['answers'],
                    static fn(array $answer): bool =>
                        (string)(
                            $answer['survey_id']
                            ?? ''
                        ) === $id
                )
            );

        output_csv(
            $survey,
            $answers
        );
    }

    /*
     * PDF
     */
    if (
        $screen === 'analytics'
        && $id !== ''
        && get_string('export') === 'pdf'
    ) {
        $survey =
            survey_get(
                $data['surveys'],
                $id
            );

        if (!$survey) {
            render_error(
                '対象アンケートが見つかりません。'
            );
            exit;
        }

        $answers =
            array_values(
                array_filter(
                    $data['answers'],
                    static fn(array $answer): bool =>
                        (string)(
                            $answer['survey_id']
                            ?? ''
                        ) === $id
                )
            );

        output_pdf(
            $survey,
            $answers
        );
    }

    /*
     * 回答者画面。
     *
     * 管理者画面へ戻さない。
     */
    if ($screen === 'answer') {
        render_answer(
            $data,
            $id
        );
        exit;
    }

    if ($screen === 'confirm') {
        render_confirm(
            $data,
            $id
        );
        exit;
    }

    if ($screen === 'complete') {
        render_complete(
            $data,
            $id
        );
        exit;
    }

    /*
     * 管理者画面
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
                $id !== ''
                    ? $id
                    : null
            );
            break;

        case 'preview':
            if ($id === '') {
                render_error(
                    'プレビュー対象のアンケートIDがありません。'
                );
                break;
            }

            render_preview(
                $data,
                $id
            );
            break;

        case 'send':
            if ($id === '') {
                render_error(
                    '送信対象のアンケートIDがありません。'
                );
                break;
            }

            render_send(
                $data,
                $id
            );
            break;

        case 'analytics':
            if ($id === '') {
                render_error(
                    '集計対象のアンケートIDがありません。'
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
            render_error(
                '指定された画面は存在しません。'
            );
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);

    render_error(
        safe_external_error($e)
    );
}

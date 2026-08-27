<?php
declare(strict_types=1);

/**
 * アンケートアプリ 完成版 index.php
 *
 * - 単一エントリーポイント
 * - PHP 8.5 / Apache 2.4
 * - DBなし
 * - PHP cURLなし
 * - kintone：ログイン名 + パスワード + X-Cybozu-Authorization
 * - APIトークン認証は使用しない
 * - X-Cybozu-Authorization はサーバー側だけで生成
 * - kintone接続テストはセッション状態に依存しない
 * - GET→POSTでセッションを必要とする処理のみセッションを利用
 * - POST処理を無期限に待機させない
 * - kintone認証情報をHTML / JavaScript / URL / エラーへ出力しない
 *
 * 要件上の画面：
 * list
 * edit
 * preview
 * send
 * analytics
 * kintone
 * mail
 * answer
 * confirm
 * complete
 */

date_default_timezone_set('Asia/Tokyo');

const APP_VERSION = '2026.08.27';
const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_READ_TIMEOUT = 20;
const SMTP_TIMEOUT = 15;

$APP_DIR  = __DIR__;
$DATA_DIR = $APP_DIR . DIRECTORY_SEPARATOR . 'data';

if (!is_dir($DATA_DIR)) {
    if (!@mkdir($DATA_DIR, 0770, true) && !is_dir($DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
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

function now_iso(): string
{
    return date('Y-m-d H:i:s');
}

function base_url(): string
{
    return 'index.php';
}

function current_screen(): string
{
    $screen = (string)($_GET['screen'] ?? 'list');

    $allowed = [
        'list',
        'edit',
        'preview',
        'send',
        'analytics',
        'kintone',
        'mail',
        'answer',
        'confirm',
        'complete',
    ];

    return in_array($screen, $allowed, true)
        ? $screen
        : 'list';
}

function redirect_screen(string $screen, array $params = []): never
{
    $allowed = [
        'list',
        'edit',
        'preview',
        'send',
        'analytics',
        'kintone',
        'mail',
        'answer',
        'confirm',
        'complete',
    ];

    if (!in_array($screen, $allowed, true)) {
        $screen = 'list';
    }

    $query = ['screen' => $screen];

    foreach ($params as $key => $value) {
        $query[$key] = $value;
    }

    header(
        'Location: ' . base_url() . '?' . http_build_query($query),
        true,
        303
    );
    exit;
}

function safe_id(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{1,120}$/', $id);
}

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

    $raw = @file_get_contents($file);

    if ($raw === false || $raw === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);

    if (
        $decoded === null
        && json_last_error() !== JSON_ERROR_NONE
    ) {
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

    try {
        $suffix = bin2hex(random_bytes(8));
    } catch (Throwable) {
        $suffix = uniqid('', true);
    }

    $tmp = $file . '.tmp.' . $suffix;

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    if (
        @file_put_contents(
            $tmp,
            $json,
            LOCK_EX
        ) === false
    ) {
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
 * セッション
 *
 * 重要：
 * kintone接続テストそのものはセッションに依存させない。
 *
 * セッションCookieが既存の壊れた状態でも、
 * kintone POSTの通信処理まで巻き込んで400にしない。
 * ============================================================ */

function session_cookie_path(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = str_replace('\\', '/', dirname($script));

    if ($dir === '.' || $dir === '') {
        return '/';
    }

    return rtrim($dir, '/') . '/';
}

function session_prepare(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }

    if (
        !function_exists('session_start')
        || headers_sent()
    ) {
        return false;
    }

    $https =
        (
            !empty($_SERVER['HTTPS'])
            && strtolower((string)$_SERVER['HTTPS']) !== 'off'
        )
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    $params = [
        'lifetime' => 0,
        'path' => session_cookie_path(),
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    @session_set_cookie_params($params);

    $ok = @session_start();

    return $ok && session_status() === PHP_SESSION_ACTIVE;
}

function flash_set(string $type, string $message): void
{
    if (!session_prepare()) {
        return;
    }

    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function flash_get(): ?array
{
    if (!session_prepare()) {
        return null;
    }

    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($value) ? $value : null;
}

/* ============================================================
 * データ
 * ============================================================ */

function load_surveys(): array
{
    $data = json_read(data_file('surveys.json'), []);
    return is_array($data) ? $data : [];
}

function save_surveys(array $data): bool
{
    return json_write(
        data_file('surveys.json'),
        array_values($data)
    );
}

function load_customers(): array
{
    $data = json_read(data_file('customers.json'), []);
    return is_array($data) ? $data : [];
}

function save_customers(array $data): bool
{
    return json_write(
        data_file('customers.json'),
        array_values($data)
    );
}

function load_answers(): array
{
    $data = json_read(data_file('answers.json'), []);
    return is_array($data) ? $data : [];
}

function save_answers(array $data): bool
{
    return json_write(
        data_file('answers.json'),
        array_values($data)
    );
}

function load_send_history(): array
{
    $data = json_read(data_file('send_history.json'), []);
    return is_array($data) ? $data : [];
}

function save_send_history(array $data): bool
{
    return json_write(
        data_file('send_history.json'),
        array_values($data)
    );
}

function load_kintone(): array
{
    $data = json_read(data_file('kintone.json'), []);

    if (!is_array($data)) {
        $data = [];
    }

    return array_merge(
        [
            'subdomain' => '',
            'app_id' => '',
            'username' => '',
            'password' => '',
            'proxy' => '',
            'verify_ssl' => true,
            'fields' => [],
            'address_mapping' => [],
            'status' => '未設定',
            'last_test' => '',
            'last_sync' => '',
        ],
        $data
    );
}

function save_kintone(array $data): bool
{
    return json_write(
        data_file('kintone.json'),
        $data
    );
}

function load_mail(): array
{
    $data = json_read(data_file('mail.json'), []);

    if (!is_array($data)) {
        $data = [];
    }

    return array_merge(
        [
            'server' => '',
            'port' => 587,
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '',
            'reply_to' => '',
            'status' => '未設定',
            'last_test' => '',
        ],
        $data
    );
}

function save_mail(array $data): bool
{
    return json_write(
        data_file('mail.json'),
        $data
    );
}

/* ============================================================
 * アンケート
 * ============================================================ */

function new_question(): array
{
    return [
        'id' => 'q-' . bin2hex(random_bytes(8)),
        'number' => '',
        'text' => '',
        'type' => 'single',
        'required' => true,
        'options' => [
            '選択肢1',
            '選択肢2',
        ],
        'branching' => [],
    ];
}

function new_group(): array
{
    return [
        'id' => 'g-' . bin2hex(random_bytes(8)),
        'title' => '新しいグループ',
        'questions' => [
            new_question(),
        ],
    ];
}

function new_survey(): array
{
    $survey = [
        'id' => 'survey-' . bin2hex(random_bytes(8)),
        'title' => '',
        'description' => '',
        'startAt' => '',
        'endAt' => '',
        'status' => 'draft',
        'numbering' => 'global',
        'groups' => [
            new_group(),
        ],
        'createdAt' => now_iso(),
        'updatedAt' => now_iso(),
    ];

    recalc_question_numbers($survey);

    return $survey;
}

function recalc_question_numbers(array &$survey): void
{
    $mode = ($survey['numbering'] ?? 'global') === 'group'
        ? 'group'
        : 'global';

    $global = 1;
    $groupNo = 1;

    if (!isset($survey['groups']) || !is_array($survey['groups'])) {
        $survey['groups'] = [];
    }

    foreach ($survey['groups'] as &$group) {
        if (!is_array($group)) {
            continue;
        }

        if (!isset($group['questions']) || !is_array($group['questions'])) {
            $group['questions'] = [];
        }

        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            if (!is_array($question)) {
                continue;
            }

            $question['number'] =
                $mode === 'group'
                    ? 'Q' . $groupNo . '-' . $questionNo
                    : 'Q' . $global;

            $questionNo++;
            $global++;
        }

        unset($question);
        $groupNo++;
    }

    unset($group);
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
        if (
            is_array($survey)
            && refresh_survey_status($survey)
        ) {
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
        if (
            is_array($survey)
            && (string)($survey['id'] ?? '') === $id
        ) {
            return $survey;
        }
    }

    return null;
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
        'published' => 'published',
        'stopped' => 'stopped',
        'ended' => 'ended',
        default => 'draft',
    };
}

/* ============================================================
 * kintone
 *
 * 認証の重要ポイント：
 *
 * X-Cybozu-Authorization =
 * Base64("ログイン名:パスワード")
 *
 * ヘッダーはこの関数でのみ生成する。
 * ブラウザには絶対に渡さない。
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
        (string)$value
    );

    return trim((string)$value);
}

function kintone_host(array $config): string
{
    $subdomain = normalize_subdomain(
        (string)($config['subdomain'] ?? '')
    );

    return $subdomain . '.cybozu.com';
}

function kintone_auth_header(array $config): string
{
    $username = (string)($config['username'] ?? '');
    $password = (string)($config['password'] ?? '');

    /*
     * PHPの文字列はUTF-8のバイト列として扱われるため、
     * login:password をそのままBase64化する。
     *
     * trim() はパスワードには絶対に行わない。
     * パスワード末尾の空白等も認証情報の一部になり得る。
     */
    return base64_encode(
        $username . ':' . $password
    );
}

function parse_proxy(string $proxy): ?array
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (
        !preg_match(
            '/^([A-Za-z0-9._-]+):([0-9]{1,5})$/',
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

function kintone_result(
    bool $ok,
    string $category,
    string $message,
    int $status = 0,
    mixed $data = null
): array {
    return [
        'ok' => $ok,
        'category' => $category,
        'message' => $message,
        'status' => $status,
        'data' => $data,
    ];
}

function validate_kintone_config(array $input): array
{
    $subdomain = normalize_subdomain(
        (string)($input['subdomain'] ?? '')
    );

    $appId = trim(
        (string)($input['app_id'] ?? '')
    );

    $username = trim(
        (string)($input['username'] ?? '')
    );

    $proxy = trim(
        (string)($input['proxy'] ?? '')
    );

    $errors = [];

    if (
        $subdomain === ''
        || !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]{0,62}$/',
            $subdomain
        )
    ) {
        $errors[] =
            'kintoneサブドメインを正しく入力してください。';
    }

    if (
        $appId === ''
        || !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        $errors[] =
            'kintoneアプリIDを正しく入力してください。';
    }

    if ($username === '') {
        $errors[] =
            'kintoneログイン名を入力してください。';
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

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $validation = validate_kintone_config($config);

    if ($validation['errors']) {
        return kintone_result(
            false,
            '入力エラー',
            implode(' ', $validation['errors'])
        );
    }

    $password = (string)($config['password'] ?? '');

    if ($password === '') {
        return kintone_result(
            false,
            '設定エラー',
            'kintoneパスワードが未設定です。'
        );
    }

    $host = kintone_host($config);

    if (
        !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]{0,62}\.cybozu\.com$/',
            $host
        )
    ) {
        return kintone_result(
            false,
            '設定エラー',
            'kintone接続先が正しくありません。'
        );
    }

    if (
        $path === ''
        || $path[0] !== '/'
        || str_contains($path, "\r")
        || str_contains($path, "\n")
    ) {
        return kintone_result(
            false,
            '設定エラー',
            'kintone APIパスが不正です。'
        );
    }

    $url = 'https://' . $host . $path;

    /*
     * kintoneのパスワード認証は
     * X-Cybozu-Authorization に
     * Base64(username:password) を設定する。
     */
    $headers = [
        'X-Cybozu-Authorization: ' .
            kintone_auth_header($config),
        'Accept: application/json',
        'User-Agent: SurveyApp/' . APP_VERSION,
    ];

    $content = null;

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            return kintone_result(
                false,
                'データエラー',
                'kintoneへの送信データを作成できません。'
            );
        }

        $headers[] =
            'Content-Type: application/json';

        $headers[] =
            'Content-Length: ' . strlen($content);
    }

    /*
     * SSL検証は原則有効。
     * 本番環境で証明書検証を無効化しない。
     */
    $verifySsl =
        array_key_exists('verify_ssl', $config)
            ? (bool)$config['verify_ssl']
            : true;

    $http = [
        'method' => strtoupper($method),
        'header' => implode("\r\n", $headers),
        'timeout' => KINTONE_CONNECT_TIMEOUT,
        'ignore_errors' => true,
        'protocol_version' => 1.1,
    ];

    if ($content !== null) {
        $http['content'] = $content;
    }

    $proxy = parse_proxy(
        (string)($config['proxy'] ?? '')
    );

    if ($proxy !== null) {
        $http['proxy'] =
            'tcp://' .
            $proxy['host'] .
            ':' .
            $proxy['port'];

        $http['request_fulluri'] = true;
    }

    $context = stream_context_create([
        'http' => $http,
        'ssl' => [
            'verify_peer' => $verifySsl,
            'verify_peer_name' => $verifySsl,
            'allow_self_signed' => !$verifySsl,
            'SNI_enabled' => true,
        ],
    ]);

    $errorMessage = '';

    set_error_handler(
        static function (
            int $severity,
            string $message
        ) use (&$errorMessage): bool {
            $errorMessage = $message;
            return true;
        }
    );

    $response = file_get_contents(
        $url,
        false,
        $context
    );

    restore_error_handler();

    if ($response === false) {
        return kintone_result(
            false,
            '通信エラー',
            $errorMessage !== ''
                ? 'kintoneへ接続できませんでした。'
                : 'kintoneへ接続できませんでした。'
        );
    }

    $statusCode = 0;

    foreach ($http_response_header ?? [] as $header) {
        if (
            preg_match(
                '/^HTTP\/[0-9.]+\s+([0-9]{3})/',
                $header,
                $m
            )
        ) {
            $statusCode = (int)$m[1];
        }
    }

    $decoded = json_decode(
        $response,
        true
    );

    if (
        $statusCode >= 200
        && $statusCode < 300
    ) {
        return kintone_result(
            true,
            '成功',
            'kintone接続成功',
            $statusCode,
            is_array($decoded)
                ? $decoded
                : $response
        );
    }

    /*
     * 認証失敗時にパスワード等を表示しない。
     */
    if (
        $statusCode === 401
        || $statusCode === 403
    ) {
        $message =
            'kintone認証に失敗しました。'
            . 'ログイン名・パスワード・'
            . 'kintone側の利用権限を確認してください。';

        /*
         * kintoneが返したエラーIDだけは診断に有用なので、
         * エラー本文から抽出して表示する。
         * 認証ヘッダー自体は絶対に表示しない。
         */
        if (is_array($decoded)) {
            $id = (string)($decoded['id'] ?? '');

            if (
                $id !== ''
                && preg_match(
                    '/^[A-Za-z0-9_-]{1,100}$/',
                    $id
                )
            ) {
                $message .=
                    '（エラーID: ' . $id . '）';
            }
        }

        return kintone_result(
            false,
            '認証エラー',
            $message,
            $statusCode,
            null
        );
    }

    if ($statusCode === 404) {
        return kintone_result(
            false,
            '設定エラー',
            'kintone APIまたはアプリが見つかりません。'
            . 'サブドメインとアプリIDを確認してください。',
            $statusCode,
            null
        );
    }

    if ($statusCode >= 500) {
        return kintone_result(
            false,
            '外部サービスエラー',
            'kintone側でエラーが発生しました。'
            . '時間をおいて再試行してください。',
            $statusCode,
            null
        );
    }

    $message =
        'kintoneからエラーが返されました。';

    if (is_array($decoded)) {
        $apiMessage =
            (string)($decoded['message'] ?? '');

        if ($apiMessage !== '') {
            /*
             * 認証情報を含む可能性があるため、
             * APIエラー本文を無制限には表示しない。
             */
            $message .=
                ' ' .
                mb_substr(
                    $apiMessage,
                    0,
                    300
                );
        }

        $id = (string)($decoded['id'] ?? '');

        if (
            $id !== ''
            && preg_match(
                '/^[A-Za-z0-9_-]{1,100}$/',
                $id
            )
        ) {
            $message .=
                '（エラーID: ' . $id . '）';
        }
    }

    return kintone_result(
        false,
        '通信エラー',
        $message,
        $statusCode,
        null
    );
}

function kintone_connection_test(array $config): array
{
    $validation = validate_kintone_config($config);

    if ($validation['errors']) {
        return kintone_result(
            false,
            '入力エラー',
            implode(
                ' ',
                $validation['errors']
            )
        );
    }

    if (
        (string)($config['password'] ?? '') === ''
    ) {
        return kintone_result(
            false,
            '設定エラー',
            'パスワードが未入力です。'
        );
    }

    $appId = (int)$validation['app_id'];

    /*
     * 接続テストはアプリ情報取得。
     *
     * セッションを使用しない。
     * これにより、壊れた/期限切れセッションCookieが
     * kintone接続テストを400にすることを防ぐ。
     */
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?app=' .
            rawurlencode((string)$appId)
    );
}

function kintone_fetch_fields(array $config): array
{
    $validation = validate_kintone_config($config);

    if ($validation['errors']) {
        return kintone_result(
            false,
            '入力エラー',
            implode(
                ' ',
                $validation['errors']
            )
        );
    }

    if (
        (string)($config['password'] ?? '') === ''
    ) {
        return kintone_result(
            false,
            '設定エラー',
            'パスワードが未設定です。'
        );
    }

    $appId = (int)$validation['app_id'];

    return kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app=' .
            rawurlencode((string)$appId)
    );
}

function kintone_sync_customers(array $config): array
{
    $validation = validate_kintone_config($config);

    if ($validation['errors']) {
        return kintone_result(
            false,
            '入力エラー',
            implode(
                ' ',
                $validation['errors']
            )
        );
    }

    if (
        (string)($config['password'] ?? '') === ''
    ) {
        return kintone_result(
            false,
            '設定エラー',
            'パスワードが未設定です。'
        );
    }

    $appId = (int)$validation['app_id'];

    /*
     * offset/limitを利用して全レコードを取得する。
     * 1回で大量取得してタイムアウトすることを防ぐ。
     */
    $limit = 500;
    $offset = 0;
    $customers = [];

    do {
        $query = http_build_query([
            'app' => $appId,
            'totalCount' => 'false',
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $result = kintone_request(
            $config,
            'GET',
            '/k/v1/records.json?' . $query
        );

        if (!$result['ok']) {
            return $result;
        }

        $records =
            $result['data']['records'] ?? [];

        if (!is_array($records)) {
            $records = [];
        }

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $values = [];

            foreach ($record as $code => $field) {
                if (
                    is_array($field)
                    && array_key_exists(
                        'value',
                        $field
                    )
                ) {
                    $values[(string)$code] =
                        $field['value'];
                }
            }

            $id =
                (string)(
                    $values['$id'] ?? ''
                );

            if ($id === '') {
                $id =
                    'k-' .
                    bin2hex(
                        random_bytes(8)
                    );
            }

            $customers[] = [
                'id' => $id,
                'organization' =>
                    (string)(
                        $values['organization']
                        ?? $values['組織名']
                        ?? $values['会社名']
                        ?? ''
                    ),
                'name' =>
                    (string)(
                        $values['name']
                        ?? $values['氏名']
                        ?? $values['顧客名']
                        ?? ''
                    ),
                'email' =>
                    (string)(
                        $values['email']
                        ?? $values['メールアドレス']
                        ?? ''
                    ),
                'department' =>
                    (string)(
                        $values['department']
                        ?? $values['部署名']
                        ?? ''
                    ),
                'phone' =>
                    (string)(
                        $values['phone']
                        ?? $values['電話番号']
                        ?? ''
                    ),
                'address' =>
                    (string)(
                        $values['address']
                        ?? $values['住所']
                        ?? ''
                    ),
                'source' => 'kintone',
                'updatedAt' => now_iso(),
            ];
        }

        $count = count($records);
        $offset += $count;

        if ($count < $limit) {
            break;
        }
    } while ($offset < 100000);

    return [
        'ok' => true,
        'category' => '成功',
        'status' => 200,
        'message' =>
            count($customers) .
            '件の顧客情報を取得しました。',
        'customers' => $customers,
    ];
}

/* ============================================================
 * SMTP
 * ============================================================ */

function smtp_read($socket): array
{
    $lines = [];

    while (!feof($socket)) {
        $line = fgets($socket, 8192);

        if ($line === false) {
            break;
        }

        $lines[] =
            rtrim(
                $line,
                "\r\n"
            );

        if (
            isset($line[3])
            && $line[3] === ' '
        ) {
            break;
        }
    }

    $last = end($lines);
    $code = 0;

    if (
        is_string($last)
        && preg_match(
            '/^([0-9]{3})/',
            $last,
            $m
        )
    ) {
        $code = (int)$m[1];
    }

    return [
        'code' => $code,
        'lines' => $lines,
    ];
}

function smtp_write(
    $socket,
    string $line
): void {
    fwrite(
        $socket,
        $line . "\r\n"
    );
}

function smtp_expect(
    $socket,
    array $codes
): array {
    $response = smtp_read($socket);

    if (
        !in_array(
            $response['code'],
            $codes,
            true
        )
    ) {
        throw new RuntimeException(
            'SMTP応答エラー'
        );
    }

    return $response;
}

function smtp_test(array $config): array
{
    $server =
        trim(
            (string)($config['server'] ?? '')
        );

    $port =
        (int)($config['port'] ?? 0);

    $encryption =
        strtolower(
            (string)(
                $config['encryption']
                ?? 'none'
            )
        );

    if ($server === '') {
        return [
            'ok' => false,
            'category' => '設定エラー',
            'message' =>
                'SMTPサーバを入力してください。',
        ];
    }

    if ($port < 1 || $port > 65535) {
        return [
            'ok' => false,
            'category' => '設定エラー',
            'message' =>
                'SMTPポートが不正です。',
        ];
    }

    if (
        !in_array(
            $encryption,
            ['none', 'tls', 'ssl'],
            true
        )
    ) {
        return [
            'ok' => false,
            'category' => '設定エラー',
            'message' =>
                'SMTP暗号化方式が不正です。',
        ];
    }

    $transport =
        $encryption === 'ssl'
            ? 'ssl://'
            : 'tcp://';

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $transport .
        $server .
        ':' .
        $port,
        $errno,
        $errstr,
        SMTP_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        return [
            'ok' => false,
            'category' => '通信エラー',
            'message' =>
                'SMTPサーバへ接続できませんでした。',
        ];
    }

    stream_set_timeout(
        $socket,
        SMTP_TIMEOUT
    );

    try {
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

            $crypto =
                @stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'TLS接続を確立できませんでした。'
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
                (string)(
                    $config['username'] ?? ''
                );

            $password =
                (string)(
                    $config['password'] ?? ''
                );

            if ($username === '') {
                throw new RuntimeException(
                    'SMTPユーザー名が未設定です。'
                );
            }

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

        smtp_write(
            $socket,
            'QUIT'
        );

        fclose($socket);

        return [
            'ok' => true,
            'category' => '成功',
            'message' =>
                'SMTP接続確認済み',
        ];
    } catch (Throwable) {
        @fclose($socket);

        return [
            'ok' => false,
            'category' => '通信エラー',
            'message' =>
                'SMTP接続に失敗しました。',
        ];
    }
}

/* ============================================================
 * POST処理
 *
 * 重要：
 * - actionを明示的にhiddenで送信
 * - screenはGET値
 * - kintone接続テストはセッション不要
 * - 接続テストはredirectしない
 * - その他POSTはPRG
 * ============================================================ */

$screen = current_screen();

$method =
    strtoupper(
        (string)(
            $_SERVER['REQUEST_METHOD']
            ?? 'GET'
        )
    );

$action =
    (string)(
        $_POST['action'] ?? ''
    );

$kintoneTestResult = null;
$kintoneFieldResult = null;
$kintoneSyncResult = null;

/* ------------------------------------------------------------
 * kintone接続テスト
 * ------------------------------------------------------------ */

if (
    $method === 'POST'
    && $screen === 'kintone'
    && $action === 'test_kintone'
) {
    /*
     * POSTデータをサーバー側で正規化。
     *
     * パスワードだけはtrimしない。
     * これが認証ヘッダー誤生成防止の重要ポイント。
     */
    $saved = load_kintone();

    $password =
        array_key_exists(
            'password',
            $_POST
        )
            ? (string)$_POST['password']
            : '';

    /*
     * 空欄なら保存済みパスワードを利用。
     * 画面からは既存パスワードそのものを返さない。
     */
    if ($password === '') {
        $password =
            (string)(
                $saved['password'] ?? ''
            );
    }

    $testConfig = [
        'subdomain' =>
            normalize_subdomain(
                (string)(
                    $_POST['subdomain']
                    ?? $saved['subdomain']
                    ?? ''
                )
            ),
        'app_id' =>
            trim(
                (string)(
                    $_POST['app_id']
                    ?? $saved['app_id']
                    ?? ''
                )
            ),
        'username' =>
            trim(
                (string)(
                    $_POST['username']
                    ?? $saved['username']
                    ?? ''
                )
            ),
        'password' => $password,
        'proxy' =>
            trim(
                (string)(
                    $_POST['proxy']
                    ?? $saved['proxy']
                    ?? ''
                )
            ),
        'verify_ssl' =>
            isset($_POST['verify_ssl'])
                ? true
                : (bool)(
                    $saved['verify_ssl']
                    ?? true
                ),
    ];

    /*
     * セッションをここでは開始しない。
     *
     * これにより、
     * 「ブラウザのセッションCookie不整合」
     * 「セッション保存領域の一時的問題」
     * 「kintone接続テスト」
     * を分離する。
     */
    $kintoneTestResult =
        kintone_connection_test(
            $testConfig
        );

    /*
     * 設定値は保存するが、
     * パスワードは空欄送信時に既存値を保持する。
     */
    $saved['subdomain'] =
        $testConfig['subdomain'];

    $saved['app_id'] =
        $testConfig['app_id'];

    $saved['username'] =
        $testConfig['username'];

    $saved['proxy'] =
        $testConfig['proxy'];

    $saved['verify_ssl'] =
        $testConfig['verify_ssl'];

    if ($password !== '') {
        $saved['password'] = $password;
    }

    $saved['status'] =
        $kintoneTestResult['ok']
            ? '接続確認済み'
            : '接続できません';

    $saved['last_test'] = now_iso();

    save_kintone($saved);
}

/* ------------------------------------------------------------
 * kintone設定保存
 * ------------------------------------------------------------ */

if (
    $method === 'POST'
    && $screen === 'kintone'
    && $action === 'save_kintone'
) {
    $old = load_kintone();

    $password =
        array_key_exists(
            'password',
            $_POST
        )
            ? (string)$_POST['password']
            : '';

    if ($password === '') {
        $password =
            (string)(
                $old['password'] ?? ''
            );
    }

    $config = [
        'subdomain' =>
            normalize_subdomain(
                (string)(
                    $_POST['subdomain'] ?? ''
                )
            ),
        'app_id' =>
            trim(
                (string)(
                    $_POST['app_id'] ?? ''
                )
            ),
        'username' =>
            trim(
                (string)(
                    $_POST['username'] ?? ''
                )
            ),
        'password' => $password,
        'proxy' =>
            trim(
                (string)(
                    $_POST['proxy'] ?? ''
                )
            ),
        'verify_ssl' =>
            isset($_POST['verify_ssl']),
        'fields' =>
            $old['fields'] ?? [],
        'address_mapping' =>
            is_array(
                $_POST['address_mapping'] ?? null
            )
                ? $_POST['address_mapping']
                : (
                    $old['address_mapping']
                    ?? []
                ),
        'status' =>
            $old['status'] ?? '未設定',
        'last_test' =>
            $old['last_test'] ?? '',
        'last_sync' =>
            $old['last_sync'] ?? '',
    ];

    $validation =
        validate_kintone_config(
            $config
        );

    if ($validation['errors']) {
        flash_set(
            'error',
            implode(
                ' ',
                $validation['errors']
            )
        );

        redirect_screen('kintone');
    }

    if (!save_kintone($config)) {
        flash_set(
            'error',
            'kintone設定を保存できませんでした。'
        );

        redirect_screen('kintone');
    }

    flash_set(
        'success',
        'kintone設定を保存しました。'
    );

    redirect_screen('kintone');
}

/* ------------------------------------------------------------
 * kintone項目一覧
 * ------------------------------------------------------------ */

if (
    $method === 'POST'
    && $screen === 'kintone'
    && $action === 'fetch_kintone_fields'
) {
    $config = load_kintone();

    $kintoneFieldResult =
        kintone_fetch_fields(
            $config
        );

    if ($kintoneFieldResult['ok']) {
        $fields = [];

        $properties =
            $kintoneFieldResult['data']
                ['properties']
            ?? [];

        if (is_array($properties)) {
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

        $config['fields'] = $fields;

        if (save_kintone($config)) {
            $kintoneFieldResult['message'] =
                count($fields) .
                '件の項目を取得しました。';
        }
    }
}

/* ------------------------------------------------------------
 * kintone顧客同期
 * ------------------------------------------------------------ */

if (
    $method === 'POST'
    && $screen === 'kintone'
    && $action === 'sync_kintone'
) {
    $config = load_kintone();

    $kintoneSyncResult =
        kintone_sync_customers(
            $config
        );

    if ($kintoneSyncResult['ok']) {
        $customers =
            $kintoneSyncResult['customers']
            ?? [];

        if (!is_array($customers)) {
            $customers = [];
        }

        if (save_customers($customers)) {
            $config['last_sync'] =
                now_iso();

            save_kintone($config);

            $kintoneSyncResult['message'] =
                count($customers) .
                '件の顧客情報を同期しました。';
        } else {
            $kintoneSyncResult = [
                'ok' => false,
                'category' => 'データエラー',
                'message' =>
                    '顧客情報を保存できませんでした。',
            ];
        }
    }
}

/* ------------------------------------------------------------
 * アンケート保存
 * ------------------------------------------------------------ */

if (
    $method === 'POST'
    && $screen === 'edit'
    && $action === 'save_survey'
) {
    $surveys = load_surveys();

    $id =
        trim(
            (string)(
                $_POST['id'] ?? ''
            )
        );

    $survey = $id !== ''
        ? find_survey(
            $surveys,
            $id
        )
        : null;

    if ($survey === null) {
        $survey = new_survey();
    }

    $survey['title'] =
        trim(
            (string)(
                $_POST['title'] ?? ''
            )
        );

    $survey['description'] =
        (string)(
            $_POST['description'] ?? ''
        );

    $survey['startAt'] =
        trim(
            (string)(
                $_POST['startAt'] ?? ''
            )
        );

    $survey['endAt'] =
        trim(
            (string)(
                $_POST['endAt'] ?? ''
            )
        );

    $numbering =
        (string)(
            $_POST['numbering'] ?? 'global'
        );

    $survey['numbering'] =
        in_array(
            $numbering,
            ['global', 'group'],
            true
        )
            ? $numbering
            : 'global';

    if ($id === '') {
        $survey['status'] = 'draft';
        $survey['createdAt'] = now_iso();
    } else {
        $old = find_survey(
            $surveys,
            $id
        );

        $survey['status'] =
            $old['status']
            ?? 'draft';
    }

    $postedGroups =
        $_POST['groups'] ?? [];

    $groups = [];

    if (is_array($postedGroups)) {
        foreach (
            $postedGroups as $groupIndex => $postedGroup
        ) {
            if (!is_array($postedGroup)) {
                continue;
            }

            $groupId =
                trim(
                    (string)(
                        $postedGroup['id']
                        ?? ''
                    )
                );

            if (
                !safe_id($groupId)
            ) {
                $groupId =
                    'g-' .
                    bin2hex(
                        random_bytes(8)
                    );
            }

            $group = [
                'id' => $groupId,
                'title' =>
                    trim(
                        (string)(
                            $postedGroup['title']
                            ?? 'グループ'
                        )
                    ),
                'questions' => [],
            ];

            $postedQuestions =
                $postedGroup['questions']
                ?? [];

            if (is_array($postedQuestions)) {
                foreach (
                    $postedQuestions
                    as $postedQuestion
                ) {
                    if (
                        !is_array(
                            $postedQuestion
                        )
                    ) {
                        continue;
                    }

                    $questionId =
                        trim(
                            (string)(
                                $postedQuestion['id']
                                ?? ''
                            )
                        );

                    if (
                        !safe_id($questionId)
                    ) {
                        $questionId =
                            'q-' .
                            bin2hex(
                                random_bytes(8)
                            );
                    }

                    $type =
                        (string)(
                            $postedQuestion['type']
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

                    $postedOptions =
                        $postedQuestion['options']
                        ?? [];

                    if (is_array($postedOptions)) {
                        foreach (
                            $postedOptions
                            as $option
                        ) {
                            $option =
                                trim(
                                    (string)$option
                                );

                            if ($option !== '') {
                                $options[] =
                                    $option;
                            }
                        }
                    }

                    if (
                        $type !== 'text'
                        && !$options
                    ) {
                        $options = [
                            '選択肢1',
                            '選択肢2',
                        ];
                    }

                    $branching =
                        $postedQuestion['branching']
                        ?? [];

                    if (!is_array($branching)) {
                        $branching = [];
                    }

                    $group['questions'][] = [
                        'id' => $questionId,
                        'number' => '',
                        'text' =>
                            trim(
                                (string)(
                                    $postedQuestion['text']
                                    ?? ''
                                )
                            ),
                        'type' => $type,
                        'required' =>
                            isset(
                                $postedQuestion['required']
                            ),
                        'options' => $options,
                        'branching' =>
                            $branching,
                    ];
                }
            }

            $groups[] = $group;
        }
    }

    if (!$groups) {
        $groups = [
            new_group(),
        ];
    }

    $survey['groups'] = $groups;
    $survey['updatedAt'] = now_iso();

    recalc_question_numbers($survey);

    $replaced = false;

    foreach ($surveys as &$existing) {
        if (
            is_array($existing)
            && ($existing['id'] ?? '') ===
                $survey['id']
        ) {
            $existing = $survey;
            $replaced = true;
            break;
        }
    }

    unset($existing);

    if (!$replaced) {
        $surveys[] = $survey;
    }

    if (!save_surveys($surveys)) {
        flash_set(
            'error',
            'アンケートを保存できませんでした。'
        );

        redirect_screen(
            'edit',
            ['id' => $survey['id']]
        );
    }

    flash_set(
        'success',
        'アンケートを保存しました。'
    );

    redirect_screen('list');
}

/* ------------------------------------------------------------
 * 状態変更
 * ------------------------------------------------------------ */

if (
    $method === 'POST'
    && $screen === 'edit'
    && $action === 'change_status'
) {
    $id =
        trim(
            (string)(
                $_POST['id'] ?? ''
            )
        );

    $newStatus =
        (string)(
            $_POST['status'] ?? ''
        );

    $allowed =
        [
            'draft',
            'published',
            'stopped',
        ];

    if (
        safe_id($id)
        && in_array(
            $newStatus,
            $allowed,
            true
        )
    ) {
        $surveys = load_surveys();

        foreach ($surveys as &$survey) {
            if (
                ($survey['id'] ?? '') !== $id
            ) {
                continue;
            }

            refresh_survey_status($survey);

            $current =
                (string)(
                    $survey['status']
                    ?? 'draft'
                );

            $valid = match ($current) {
                'draft' =>
                    $newStatus === 'published',
                'published' =>
                    $newStatus === 'stopped',
                'stopped' =>
                    $newStatus === 'published',
                default => false,
            };

            if ($valid) {
                $survey['status'] =
                    $newStatus;

                $survey['updatedAt'] =
                    now_iso();

                flash_set(
                    'success',
                    '状態を変更しました。'
                );
            } else {
                flash_set(
                    'error',
                    '指定された状態変更は実行できません。'
                );
            }

            break;
        }

        unset($survey);

        save_surveys($surveys);
    }

    redirect_screen(
        'edit',
        ['id' => $id]
    );
}

/* ------------------------------------------------------------
 * 複製
 * ------------------------------------------------------------ */

if (
    $method === 'POST'
    && $screen === 'list'
    && $action === 'duplicate'
) {
    $id =
        trim(
            (string)(
                $_POST['id'] ?? ''
            )
        );

    $surveys = load_surveys();

    $source =
        find_survey(
            $surveys,
            $id
        );

    if ($source !== null) {
        $source['id'] =
            'survey-' .
            bin2hex(
                random_bytes(8)
            );

        $source['title'] =
            (string)(
                $source['title'] ?? ''
            ) .
            '（コピー）';

        $source['status'] = 'draft';
        $source['createdAt'] =
            now_iso();
        $source['updatedAt'] =
            now_iso();

        $surveys[] = $source;

        save_surveys($surveys);

        flash_set(
            'success',
            'アンケートを複製しました。'
        );
    }

    redirect_screen('list');
}

/* ------------------------------------------------------------
 * 削除
 * ------------------------------------------------------------ */

if (
    $method === 'POST'
    && $screen === 'list'
    && $action === 'delete'
) {
    $id =
        trim(
            (string)(
                $_POST['id'] ?? ''
            )
        );

    if (safe_id($id)) {
        $surveys =
            load_surveys();

        $surveys =
            array_values(
                array_filter(
                    $surveys,
                    static function (
                        $survey
                    ) use ($id): bool {
                        return
                            is_array($survey)
                            && (
                                $survey['id']
                                ?? ''
                            ) !== $id;
                    }
                )
            );

        save_surveys($surveys);

        flash_set(
            'success',
            'アンケートを削除しました。'
        );
    }

    redirect_screen('list');
}

/* ============================================================
 * 表示用データ
 * ============================================================ */

$surveys = load_surveys();

refresh_all_statuses(
    $surveys
);

$surveys = load_surveys();

$flash =
    $method === 'POST'
        ? flash_get()
        : flash_get();

$currentId =
    trim(
        (string)(
            $_GET['id'] ?? ''
        )
    );

$currentSurvey = null;

if (
    $currentId !== ''
    && safe_id($currentId)
) {
    $currentSurvey =
        find_survey(
            $surveys,
            $currentId
        );
}

/*
 * 集計・送信は対象アンケートID必須。
 * 存在しないIDでは一覧へ戻す。
 */
if (
    in_array(
        $screen,
        ['analytics', 'send'],
        true
    )
) {
    if (
        $currentSurvey === null
        || $currentId === ''
    ) {
        redirect_screen('list');
    }
}

/* ============================================================
 * HTML
 * ============================================================ */

$kintone = load_kintone();
$mail = load_mail();

?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>
<title><?= h(
    match ($screen) {
        'kintone' => 'kintone連携設定',
        'mail' => 'メールサーバ設定',
        'edit' => 'アンケート編集',
        'preview' => 'アンケートプレビュー',
        'send' => '顧客選択・メール送信',
        'analytics' => '回答集計・分析',
        'answer' => 'アンケート回答',
        'confirm' => '回答確認',
        'complete' => '回答完了',
        default => 'アンケート一覧',
    }
) ?></title>

<style>
* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        sans-serif;
    color: #1f2937;
    background: #f5f7fb;
}

body {
    min-height: 100vh;
}

a {
    color: #2563eb;
    text-decoration: none;
}

button,
input,
textarea,
select {
    font: inherit;
}

button {
    cursor: pointer;
}

.header {
    background: #111827;
    color: #fff;
}

.header-inner {
    max-width: 1280px;
    margin: auto;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
}

.logo {
    font-weight: 700;
    font-size: 20px;
}

.nav {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.nav a {
    color: #dbeafe;
    padding: 8px 12px;
    border-radius: 6px;
}

.nav a:hover {
    background: #1f2937;
}

.container {
    max-width: 1280px;
    margin: 0 auto;
    padding: 24px 20px 60px;
}

.card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 2px rgba(0,0,0,.03);
}

h1 {
    margin: 0 0 20px;
    font-size: 26px;
}

h2 {
    margin: 0 0 16px;
    font-size: 20px;
}

h3 {
    margin: 0 0 12px;
    font-size: 17px;
}

label {
    display: block;
    font-weight: 600;
    margin-bottom: 7px;
}

input[type="text"],
input[type="password"],
input[type="email"],
input[type="datetime-local"],
input[type="number"],
textarea,
select {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    padding: 10px 12px;
    background: #fff;
}

textarea {
    min-height: 120px;
    resize: vertical;
}

.form-row {
    margin-bottom: 16px;
}

.grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 16px;
}

.grid-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0,1fr));
    gap: 16px;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}

.btn {
    border: 0;
    border-radius: 7px;
    padding: 9px 14px;
    background: #2563eb;
    color: #fff;
}

.btn:hover {
    filter: brightness(.95);
}

.btn-secondary {
    background: #64748b;
}

.btn-success {
    background: #059669;
}

.btn-danger {
    background: #dc2626;
}

.btn-light {
    background: #e5e7eb;
    color: #111827;
}

.btn-warning {
    background: #d97706;
}

.notice {
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 18px;
}

.notice-success {
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}

.notice-error {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.notice-info {
    background: #eff6ff;
    color: #1e40af;
    border: 1px solid #bfdbfe;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px;
}

th,
td {
    border-bottom: 1px solid #e5e7eb;
    padding: 12px 10px;
    text-align: left;
    vertical-align: top;
}

th {
    background: #f8fafc;
    white-space: nowrap;
}

.badge {
    display: inline-block;
    border-radius: 999px;
    padding: 4px 9px;
    font-size: 12px;
    font-weight: 700;
}

.badge.draft {
    background: #e5e7eb;
    color: #374151;
}

.badge.published {
    background: #dcfce7;
    color: #166534;
}

.badge.stopped {
    background: #fef3c7;
    color: #92400e;
}

.badge.ended {
    background: #fee2e2;
    color: #991b1b;
}

.group {
    border: 1px solid #dbeafe;
    background: #f8fbff;
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 16px;
}

.question {
    border: 1px solid #e5e7eb;
    background: #fff;
    border-radius: 8px;
    padding: 14px;
    margin: 10px 0;
}

.question-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.q-number {
    font-weight: 700;
    color: #2563eb;
}

.option {
    display: flex;
    gap: 8px;
    margin: 8px 0;
}

.option input {
    flex: 1;
}

.kv {
    display: grid;
    grid-template-columns: 180px 1fr;
    border-top: 1px solid #e5e7eb;
}

.kv > div {
    padding: 10px;
    border-bottom: 1px solid #e5e7eb;
}

.kv > div:nth-child(odd) {
    background: #f8fafc;
    font-weight: 600;
}

.muted {
    color: #64748b;
}

.small {
    font-size: 13px;
}

.loading {
    display: none;
    margin-left: 8px;
    color: #2563eb;
}

.loading.active {
    display: inline-block;
}

@media (max-width: 800px) {
    .grid-2,
    .grid-3 {
        grid-template-columns: 1fr;
    }

    .header-inner {
        align-items: flex-start;
        flex-direction: column;
    }

    .container {
        padding: 16px 12px 40px;
    }

    h1 {
        font-size: 22px;
    }

    .kv {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<header class="header">
    <div class="header-inner">
        <div class="logo">アンケートアプリ</div>

        <nav class="nav">
            <a href="?screen=list">一覧</a>
            <a href="?screen=kintone">kintone</a>
            <a href="?screen=mail">メール設定</a>
        </nav>
    </div>
</header>

<main class="container">

<?php if ($flash !== null): ?>
    <div class="notice <?= h(
        ($flash['type'] ?? '') === 'success'
            ? 'notice-success'
            : 'notice-error'
    ) ?>">
        <?= h($flash['message'] ?? '') ?>
    </div>
<?php endif; ?>

<?php if ($screen === 'list'): ?>

    <h1>アンケート一覧</h1>

    <div class="card">
        <form method="get">
            <input
                type="hidden"
                name="screen"
                value="list"
            >

            <div class="grid-3">
                <div>
                    <label>タイトル検索</label>
                    <input
                        type="text"
                        name="q"
                        value="<?= h(
                            $_GET['q'] ?? ''
                        ) ?>"
                        placeholder="タイトルを入力"
                    >
                </div>

                <div>
                    <label>ステータス</label>
                    <select name="status">
                        <option value="">すべて</option>
                        <option
                            value="published"
                            <?= (
                                ($_GET['status'] ?? '')
                                === 'published'
                            ) ? 'selected' : '' ?>
                        >公開中</option>
                        <option
                            value="draft"
                            <?= (
                                ($_GET['status'] ?? '')
                                === 'draft'
                            ) ? 'selected' : '' ?>
                        >下書き</option>
                        <option
                            value="stopped"
                            <?= (
                                ($_GET['status'] ?? '')
                                === 'stopped'
                            ) ? 'selected' : '' ?>
                        >停止</option>
                        <option
                            value="ended"
                            <?= (
                                ($_GET['status'] ?? '')
                                === 'ended'
                            ) ? 'selected' : '' ?>
                        >終了</option>
                    </select>
                </div>

                <div>
                    <label>ソート</label>
                    <select name="sort">
                        <?php
                        $sort =
                            (string)(
                                $_GET['sort']
                                ?? 'updated_desc'
                            );
                        ?>
                        <option
                            value="updated_desc"
                            <?= $sort === 'updated_desc'
                                ? 'selected'
                                : '' ?>
                        >更新日：新しい順</option>
                        <option
                            value="updated_asc"
                            <?= $sort === 'updated_asc'
                                ? 'selected'
                                : '' ?>
                        >更新日：古い順</option>
                        <option
                            value="answers_desc"
                            <?= $sort === 'answers_desc'
                                ? 'selected'
                                : '' ?>
                        >回答数：多い順</option>
                        <option
                            value="answers_asc"
                            <?= $sort === 'answers_asc'
                                ? 'selected'
                                : '' ?>
                        >回答数：少ない順</option>
                        <option
                            value="start_desc"
                            <?= $sort === 'start_desc'
                                ? 'selected'
                                : '' ?>
                        >開始日：新しい順</option>
                        <option
                            value="start_asc"
                            <?= $sort === 'start_asc'
                                ? 'selected'
                                : '' ?>
                        >開始日：古い順</option>
                    </select>
                </div>
            </div>

            <div class="actions">
                <button
                    class="btn"
                    type="submit"
                >検索</button>

                <a
                    class="btn btn-success"
                    href="?screen=edit"
                >＋ 新規作成</a>
            </div>
        </form>
    </div>

    <?php
    $q = trim(
        (string)(
            $_GET['q'] ?? ''
        )
    );

    $statusFilter =
        (string)(
            $_GET['status'] ?? ''
        );

    $filtered = [];

    foreach ($surveys as $survey) {
        if (!is_array($survey)) {
            continue;
        }

        if (
            $q !== ''
            && !str_contains(
                mb_strtolower(
                    (string)(
                        $survey['title']
                        ?? ''
                    )
                ),
                mb_strtolower($q)
            )
        ) {
            continue;
        }

        if (
            $statusFilter !== ''
            && (
                $survey['status']
                ?? 'draft'
            ) !== $statusFilter
        ) {
            continue;
        }

        $filtered[] = $survey;
    }

    usort(
        $filtered,
        static function (
            array $a,
            array $b
        ) use ($sort): int {
            $av = match ($sort) {
                'answers_desc',
                'answers_asc' =>
                    count(
                        array_filter(
                            load_answers(),
                            static fn($row) =>
                                is_array($row)
                                && (
                                    $row['surveyId']
                                    ?? ''
                                ) === (
                                    $a['id'] ?? ''
                                )
                        )
                    ),
                'start_desc',
                'start_asc' =>
                    strtotime(
                        (string)(
                            $a['startAt'] ?? ''
                        )
                    ) ?: 0,
                default =>
                    strtotime(
                        (string)(
                            $a['updatedAt'] ?? ''
                        )
                    ) ?: 0,
            };

            $bv = match ($sort) {
                'answers_desc',
                'answers_asc' =>
                    count(
                        array_filter(
                            load_answers(),
                            static fn($row) =>
                                is_array($row)
                                && (
                                    $row['surveyId']
                                    ?? ''
                                ) === (
                                    $b['id'] ?? ''
                                )
                        )
                    ),
                'start_desc',
                'start_asc' =>
                    strtotime(
                        (string)(
                            $b['startAt'] ?? ''
                        )
                    ) ?: 0,
                default =>
                    strtotime(
                        (string)(
                            $b['updatedAt'] ?? ''
                        )
                    ) ?: 0,
            };

            if (
                in_array(
                    $sort,
                    [
                        'updated_asc',
                        'answers_asc',
                        'start_asc',
                    ],
                    true
                )
            ) {
                return $av <=> $bv;
            }

            return $bv <=> $av;
        }
    );

    $allAnswers = load_answers();
    ?>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>タイトル</th>
                    <th>作成日</th>
                    <th>更新日</th>
                    <th>アンケート期間</th>
                    <th>ステータス</th>
                    <th>回答数</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>

                <?php if (!$filtered): ?>
                    <tr>
                        <td colspan="7">
                            該当するアンケートはありません。
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($filtered as $survey): ?>
                    <?php
                    $sid =
                        (string)(
                            $survey['id'] ?? ''
                        );

                    $answerCount = 0;

                    foreach (
                        $allAnswers as $answer
                    ) {
                        if (
                            is_array($answer)
                            && (
                                $answer['surveyId']
                                ?? ''
                            ) === $sid
                        ) {
                            $answerCount++;
                        }
                    }

                    $surveyStatus =
                        (string)(
                            $survey['status']
                            ?? 'draft'
                        );
                    ?>

                    <tr>
                        <td>
                            <strong><?= h(
                                $survey['title']
                                ?? '無題'
                            ) ?></strong>
                        </td>

                        <td>
                            <?= h(
                                $survey['createdAt']
                                ?? ''
                            ) ?>
                        </td>

                        <td>
                            <?= h(
                                $survey['updatedAt']
                                ?? ''
                            ) ?>
                        </td>

                        <td>
                            <?= h(
                                $survey['startAt']
                                ?? ''
                            ) ?>
                            ～
                            <?= h(
                                $survey['endAt']
                                ?? ''
                            ) ?>
                        </td>

                        <td>
                            <span class="badge <?= h(
                                status_class(
                                    $surveyStatus
                                )
                            ) ?>">
                                <?= h(
                                    status_label(
                                        $surveyStatus
                                    )
                                ) ?>
                            </span>
                        </td>

                        <td>
                            <?= h($answerCount) ?>
                        </td>

                        <td>
                            <div class="actions">
                                <a
                                    class="btn btn-light"
                                    href="?screen=edit&id=<?= rawurlencode($sid) ?>"
                                >確認・編集</a>

                                <a
                                    class="btn btn-light"
                                    href="?screen=analytics&id=<?= rawurlencode($sid) ?>"
                                >集計</a>

                                <a
                                    class="btn btn-light"
                                    href="?screen=send&id=<?= rawurlencode($sid) ?>"
                                >送信</a>

                                <a
                                    class="btn btn-light"
                                    href="?screen=preview&id=<?= rawurlencode($sid) ?>"
                                >プレビュー</a>

                                <form
                                    method="post"
                                    style="display:inline"
                                    onsubmit="
                                        return confirm(
                                            'このアンケートを複製しますか？'
                                        );
                                    "
                                >
                                    <input
                                        type="hidden"
                                        name="action"
                                        value="duplicate"
                                    >
                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= h($sid) ?>"
                                    >
                                    <button
                                        class="btn btn-secondary"
                                        type="submit"
                                    >複製</button>
                                </form>

                                <form
                                    method="post"
                                    style="display:inline"
                                    onsubmit="
                                        return confirm(
                                            'このアンケートを削除しますか？'
                                        );
                                    "
                                >
                                    <input
                                        type="hidden"
                                        name="action"
                                        value="delete"
                                    >
                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= h($sid) ?>"
                                    >
                                    <button
                                        class="btn btn-danger"
                                        type="submit"
                                    >削除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($screen === 'kintone'): ?>

    <h1>kintone連携設定</h1>

    <div class="notice notice-info">
        kintone認証はサーバー側でのみ実行します。
        X-Cybozu-Authorizationおよびパスワードは
        ブラウザのJavaScriptへ渡しません。
    </div>

    <?php if ($kintoneTestResult !== null): ?>
        <div class="notice <?= $kintoneTestResult['ok']
            ? 'notice-success'
            : 'notice-error' ?>">
            <strong>
                <?= h(
                    $kintoneTestResult['category']
                    ?? ''
                ) ?>
            </strong>
            ：
            <?= h(
                $kintoneTestResult['message']
                ?? ''
            ) ?>
        </div>
    <?php endif; ?>

    <?php if ($kintoneFieldResult !== null): ?>
        <div class="notice <?= $kintoneFieldResult['ok']
            ? 'notice-success'
            : 'notice-error' ?>">
            <strong>
                <?= h(
                    $kintoneFieldResult['category']
                    ?? ''
                ) ?>
            </strong>
            ：
            <?= h(
                $kintoneFieldResult['message']
                ?? ''
            ) ?>
        </div>
    <?php endif; ?>

    <?php if ($kintoneSyncResult !== null): ?>
        <div class="notice <?= $kintoneSyncResult['ok']
            ? 'notice-success'
            : 'notice-error' ?>">
            <strong>
                <?= h(
                    $kintoneSyncResult['category']
                    ?? ''
                ) ?>
            </strong>
            ：
            <?= h(
                $kintoneSyncResult['message']
                ?? ''
            ) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>接続設定</h2>

        <form
            method="post"
            action="index.php?screen=kintone"
            id="kintoneForm"
        >
            <input
                type="hidden"
                name="action"
                id="kintoneAction"
                value="save_kintone"
            >

            <div class="grid-2">

                <div class="form-row">
                    <label>
                        kintoneサブドメイン
                    </label>

                    <input
                        type="text"
                        name="subdomain"
                        value="<?= h(
                            $kintone['subdomain']
                            ?? ''
                        ) ?>"
                        placeholder="example"
                        autocomplete="off"
                        required
                    >

                    <div class="small muted">
                        https://example.cybozu.com/
                        の「example」部分
                    </div>
                </div>

                <div class="form-row">
                    <label>
                        アプリID
                    </label>

                    <input
                        type="number"
                        name="app_id"
                        min="1"
                        value="<?= h(
                            $kintone['app_id']
                            ?? ''
                        ) ?>"
                        required
                    >
                </div>

            </div>

            <div class="grid-2">

                <div class="form-row">
                    <label>
                        ログイン名
                    </label>

                    <input
                        type="text"
                        name="username"
                        value="<?= h(
                            $kintone['username']
                            ?? ''
                        ) ?>"
                        autocomplete="username"
                        required
                    >
                </div>

                <div class="form-row">
                    <label>
                        パスワード
                    </label>

                    <input
                        type="password"
                        name="password"
                        value=""
                        placeholder="変更しない場合は空欄"
                        autocomplete="new-password"
                    >

                    <div class="small muted">
                        空欄の場合はサーバー保存済みの
                        パスワードを使用します。
                    </div>
                </div>

            </div>

            <div class="grid-2">

                <div class="form-row">
                    <label>
                        Proxy
                    </label>

                    <input
                        type="text"
                        name="proxy"
                        value="<?= h(
                            $kintone['proxy']
                            ?? ''
                        ) ?>"
                        placeholder="proxy.example:8080"
                    >
                </div>

                <div class="form-row">
                    <label>
                        TLS証明書を検証する
                    </label>

                    <label
                        style="
                            display:flex;
                            align-items:center;
                            gap:8px;
                            font-weight:400
                        "
                    >
                        <input
                            type="checkbox"
                            name="verify_ssl"
                            value="1"
                            <?= !empty(
                                $kintone['verify_ssl']
                            )
                                ? 'checked'
                                : '' ?>
                        >
                        有効
                    </label>
                </div>

            </div>

            <div class="actions">

                <button
                    class="btn btn-success"
                    type="submit"
                    onclick="
                        document.getElementById(
                            'kintoneAction'
                        ).value='save_kintone';
                    "
                >
                    設定を保存
                </button>

                <button
                    class="btn"
                    type="submit"
                    onclick="
                        document.getElementById(
                            'kintoneAction'
                        ).value='test_kintone';
                        this.form.dataset.test='1';
                    "
                >
                    接続テスト
                </button>

                <span
                    class="loading"
                    id="kintoneLoading"
                >
                    接続中…
                </span>

            </div>
        </form>
    </div>

    <div class="card">
        <h2>kintoneデータ操作</h2>

        <div class="actions">

            <form
                method="post"
                action="index.php?screen=kintone"
                style="display:inline"
                onsubmit="
                    document.getElementById(
                        'fieldLoading'
                    ).classList.add('active');
                "
            >
                <input
                    type="hidden"
                    name="action"
                    value="fetch_kintone_fields"
                >

                <button
                    class="btn btn-secondary"
                    type="submit"
                >
                    項目一覧を取得
                </button>

                <span
                    class="loading"
                    id="fieldLoading"
                >
                    取得中…
                </span>
            </form>

            <form
                method="post"
                action="index.php?screen=kintone"
                style="display:inline"
                onsubmit="
                    return confirm(
                        'kintoneから顧客情報を同期しますか？'
                    );
                "
            >
                <input
                    type="hidden"
                    name="action"
                    value="sync_kintone"
                >

                <button
                    class="btn btn-warning"
                    type="submit"
                >
                    顧客情報同期
                </button>
            </form>

        </div>
    </div>

    <div class="card">
        <h2>接続状態</h2>

        <div class="kv">
            <div>状態</div>
            <div><?= h(
                $kintone['status']
                ?? '未設定'
            ) ?></div>

            <div>最終接続テスト</div>
            <div><?= h(
                $kintone['last_test']
                ?? ''
            ) ?></div>

            <div>最終同期</div>
            <div><?= h(
                $kintone['last_sync']
                ?? ''
            ) ?></div>

            <div>取得済み項目数</div>
            <div><?= h(
                is_array(
                    $kintone['fields']
                    ?? null
                )
                    ? count(
                        $kintone['fields']
                    )
                    : 0
            ) ?></div>
        </div>
    </div>

    <?php
    $fields =
        is_array(
            $kintone['fields'] ?? null
        )
            ? $kintone['fields']
            : [];
    ?>

    <?php if ($fields): ?>
        <div class="card">
            <h2>kintone項目一覧</h2>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>フィールドコード</th>
                        <th>表示名</th>
                        <th>タイプ</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($fields as $field): ?>
                        <tr>
                            <td><?= h(
                                $field['code']
                                ?? ''
                            ) ?></td>
                            <td><?= h(
                                $field['label']
                                ?? ''
                            ) ?></td>
                            <td><?= h(
                                $field['type']
                                ?? ''
                            ) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($screen === 'mail'): ?>

    <h1>メールサーバ設定</h1>

    <div class="card">
        <form
            method="post"
            action="index.php?screen=mail"
        >
            <input
                type="hidden"
                name="action"
                value="save_mail"
            >

            <div class="grid-2">

                <div class="form-row">
                    <label>SMTPサーバ</label>
                    <input
                        type="text"
                        name="server"
                        value="<?= h(
                            $mail['server']
                            ?? ''
                        ) ?>"
                    >
                </div>

                <div class="form-row">
                    <label>ポート</label>
                    <input
                        type="number"
                        name="port"
                        value="<?= h(
                            $mail['port']
                            ?? 587
                        ) ?>"
                        min="1"
                        max="65535"
                    >
                </div>

                <div class="form-row">
                    <label>暗号化</label>
                    <select name="encryption">
                        <?php
                        $enc =
                            $mail['encryption']
                            ?? 'tls';
                        ?>
                        <option
                            value="none"
                            <?= $enc === 'none'
                                ? 'selected'
                                : '' ?>
                        >なし</option>
                        <option
                            value="tls"
                            <?= $enc === 'tls'
                                ? 'selected'
                                : '' ?>
                        >STARTTLS</option>
                        <option
                            value="ssl"
                            <?= $enc === 'ssl'
                                ? 'selected'
                                : '' ?>
                        >SSL/TLS</option>
                    </select>
                </div>

                <div class="form-row">
                    <label>認証</label>

                    <label
                        style="
                            display:flex;
                            align-items:center;
                            gap:8px;
                            font-weight:400
                        "
                    >
                        <input
                            type="checkbox"
                            name="auth"
                            value="1"
                            <?= !empty(
                                $mail['auth']
                            )
                                ? 'checked'
                                : '' ?>
                        >
                        SMTP認証を使用
                    </label>
                </div>

                <div class="form-row">
                    <label>ユーザー名</label>
                    <input
                        type="text"
                        name="username"
                        value="<?= h(
                            $mail['username']
                            ?? ''
                        ) ?>"
                        autocomplete="username"
                    >
                </div>

                <div class="form-row">
                    <label>パスワード</label>
                    <input
                        type="password"
                        name="password"
                        value=""
                        placeholder="変更しない場合は空欄"
                        autocomplete="new-password"
                    >
                </div>

                <div class="form-row">
                    <label>送信元メールアドレス</label>
                    <input
                        type="email"
                        name="from_email"
                        value="<?= h(
                            $mail['from_email']
                            ?? ''
                        ) ?>"
                    >
                </div>

                <div class="form-row">
                    <label>送信元名</label>
                    <input
                        type="text"
                        name="from_name"
                        value="<?= h(
                            $mail['from_name']
                            ?? ''
                        ) ?>"
                    >
                </div>

                <div class="form-row">
                    <label>Reply-To</label>
                    <input
                        type="email"
                        name="reply_to"
                        value="<?= h(
                            $mail['reply_to']
                            ?? ''
                        ) ?>"
                    >
                </div>

            </div>

            <div class="actions">
                <button
                    class="btn btn-success"
                    type="submit"
                >保存</button>
            </div>
        </form>
    </div>

<?php elseif ($screen === 'edit'): ?>

    <?php
    $editSurvey =
        $currentSurvey
        ?? new_survey();

    if (
        $editSurvey !== null
        && is_array($editSurvey)
    ) {
        recalc_question_numbers(
            $editSurvey
        );
    }

    $editStatus =
        (string)(
            $editSurvey['status']
            ?? 'draft'
        );
    ?>

    <h1>
        <?= $currentSurvey !== null
            ? 'アンケート編集'
            : 'アンケート作成' ?>
    </h1>

    <div class="card">
        <div class="actions">

            <a
                class="btn btn-light"
                href="?screen=list"
            >キャンセル</a>

            <form
                method="post"
                action="index.php?screen=edit"
                style="display:inline"
            >
                <input
                    type="hidden"
                    name="action"
                    value="save_survey"
                >

                <input
                    type="hidden"
                    name="id"
                    value="<?= h(
                        $editSurvey['id']
                        ?? ''
                    ) ?>"
                >

                <button
                    class="btn btn-success"
                    type="submit"
                >
                    保存して一覧へ
                </button>
            </form>

            <?php if ($currentSurvey !== null): ?>

                <?php
                $nextStatus = match ($editStatus) {
                    'draft' => 'published',
                    'published' => 'stopped',
                    'stopped' => 'published',
                    default => '',
                };

                $nextLabel = match ($editStatus) {
                    'draft' => '公開',
                    'published' => '停止',
                    'stopped' => '再開',
                    default => '',
                };
                ?>

                <?php if ($nextStatus !== ''): ?>
                    <form
                        method="post"
                        action="index.php?screen=edit&id=<?= rawurlencode(
                            (string)$editSurvey['id']
                        ) ?>"
                        style="display:inline"
                        onsubmit="
                            return confirm(
                                '状態を変更しますか？'
                            );
                        "
                    >
                        <input
                            type="hidden"
                            name="action"
                            value="change_status"
                        >

                        <input
                            type="hidden"
                            name="id"
                            value="<?= h(
                                $editSurvey['id']
                            ) ?>"
                        >

                        <input
                            type="hidden"
                            name="status"
                            value="<?= h(
                                $nextStatus
                            ) ?>"
                        >

                        <button
                            class="btn btn-warning"
                            type="submit"
                        >
                            <?= h($nextLabel) ?>
                        </button>
                    </form>
                <?php endif; ?>

            <?php endif; ?>

        </div>
    </div>

    <form
        method="post"
        action="index.php?screen=edit"
        id="surveyEditForm"
    >
        <input
            type="hidden"
            name="action"
            value="save_survey"
        >

        <input
            type="hidden"
            name="id"
            value="<?= h(
                $editSurvey['id']
                ?? ''
            ) ?>"
        >

        <div class="card">
            <h2>基本情報</h2>

            <div class="form-row">
                <label>アンケートタイトル</label>
                <input
                    type="text"
                    name="title"
                    value="<?= h(
                        $editSurvey['title']
                        ?? ''
                    ) ?>"
                    required
                >
            </div>

            <div class="form-row">
                <label>アンケート説明</label>
                <textarea
                    name="description"
                ><?= h(
                    $editSurvey['description']
                    ?? ''
                ) ?></textarea>
            </div>

            <div class="grid-2">

                <div class="form-row">
                    <label>開始日時</label>
                    <input
                        type="datetime-local"
                        name="startAt"
                        value="<?= h(
                            $editSurvey['startAt']
                            ?? ''
                        ) ?>"
                    >
                </div>

                <div class="form-row">
                    <label>終了日時</label>
                    <input
                        type="datetime-local"
                        name="endAt"
                        value="<?= h(
                            $editSurvey['endAt']
                            ?? ''
                        ) ?>"
                    >
                </div>

            </div>

            <div class="form-row">
                <label>質問番号の採番方式</label>

                <select name="numbering">
                    <option
                        value="global"
                        <?= (
                            ($editSurvey['numbering']
                            ?? 'global')
                            === 'global'
                        )
                            ? 'selected'
                            : '' ?>
                    >
                        アンケート全体で通番
                        （Q1、Q2、Q3…）
                    </option>

                    <option
                        value="group"
                        <?= (
                            ($editSurvey['numbering']
                            ?? 'global')
                            === 'group'
                        )
                            ? 'selected'
                            : '' ?>
                    >
                        グループ毎
                        （Q1-1、Q1-2、Q2-1…）
                    </option>
                </select>
            </div>

            <div>
                状態：
                <span class="badge <?= h(
                    status_class(
                        $editStatus
                    )
                ) ?>">
                    <?= h(
                        status_label(
                            $editStatus
                        )
                    ) ?>
                </span>
            </div>
        </div>

        <div class="card">
            <h2>質問・グループ</h2>

            <div id="groups">

                <?php
                $groups =
                    is_array(
                        $editSurvey['groups']
                        ?? null
                    )
                        ? $editSurvey['groups']
                        : [];
                ?>

                <?php foreach (
                    $groups as $gi => $group
                ): ?>

                    <div
                        class="group"
                        draggable="true"
                    >

                        <div class="question-head">
                            <h3>グループ</h3>

                            <button
                                class="btn btn-danger"
                                type="button"
                                onclick="removeGroup(this)"
                            >
                                グループ削除
                            </button>
                        </div>

                        <input
                            type="hidden"
                            name="groups[<?= $gi ?>][id]"
                            value="<?= h(
                                $group['id']
                                ?? ''
                            ) ?>"
                        >

                        <div class="form-row">
                            <label>グループタイトル</label>
                            <input
                                type="text"
                                name="groups[<?= $gi ?>][title]"
                                value="<?= h(
                                    $group['title']
                                    ?? ''
                                ) ?>"
                            >
                        </div>

                        <div class="questions">

                            <?php
                            $questions =
                                is_array(
                                    $group['questions']
                                    ?? null
                                )
                                    ? $group['questions']
                                    : [];
                            ?>

                            <?php foreach (
                                $questions as $qi => $question
                            ): ?>

                                <div
                                    class="question"
                                    draggable="true"
                                >

                                    <div class="question-head">
                                        <span
                                            class="q-number"
                                        >
                                            <?= h(
                                                $question['number']
                                                ?? ''
                                            ) ?>
                                        </span>

                                        <button
                                            class="btn btn-danger"
                                            type="button"
                                            onclick="
                                                removeQuestion(
                                                    this
                                                )
                                            "
                                        >
                                            削除
                                        </button>
                                    </div>

                                    <input
                                        type="hidden"
                                        name="groups[<?= $gi ?>][questions][<?= $qi ?>][id]"
                                        value="<?= h(
                                            $question['id']
                                            ?? ''
                                        ) ?>"
                                    >

                                    <div class="form-row">
                                        <label>質問文</label>
                                        <input
                                            type="text"
                                            name="groups[<?= $gi ?>][questions][<?= $qi ?>][text]"
                                            value="<?= h(
                                                $question['text']
                                                ?? ''
                                            ) ?>"
                                        >
                                    </div>

                                    <div class="grid-2">

                                        <div class="form-row">
                                            <label>
                                                回答形式
                                            </label>

                                            <select
                                                name="groups[<?= $gi ?>][questions][<?= $qi ?>][type]"
                                                onchange="
                                                    updateQuestionType(
                                                        this
                                                    )
                                                "
                                            >
                                                <option
                                                    value="single"
                                                    <?= (
                                                        ($question['type']
                                                        ?? 'single')
                                                        === 'single'
                                                    )
                                                        ? 'selected'
                                                        : '' ?>
                                                >
                                                    単一選択
                                                </option>

                                                <option
                                                    value="multiple"
                                                    <?= (
                                                        ($question['type']
                                                        ?? '')
                                                        === 'multiple'
                                                    )
                                                        ? 'selected'
                                                        : '' ?>
                                                >
                                                    複数選択
                                                </option>

                                                <option
                                                    value="text"
                                                    <?= (
                                                        ($question['type']
                                                        ?? '')
                                                        === 'text'
                                                    )
                                                        ? 'selected'
                                                        : '' ?>
                                                >
                                                    自由記述
                                                </option>
                                            </select>
                                        </div>

                                        <div class="form-row">
                                            <label>
                                                必須設定
                                            </label>

                                            <label
                                                style="
                                                    display:flex;
                                                    gap:8px;
                                                    align-items:center;
                                                    font-weight:400
                                                "
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="groups[<?= $gi ?>][questions][<?= $qi ?>][required]"
                                                    value="1"
                                                    <?= !empty(
                                                        $question['required']
                                                    )
                                                        ? 'checked'
                                                        : '' ?>
                                                >
                                                必須
                                            </label>
                                        </div>

                                    </div>

                                    <div
                                        class="options-area"
                                        style="<?= (
                                            ($question['type']
                                            ?? 'single')
                                            === 'text'
                                        )
                                            ? 'display:none'
                                            : '' ?>"
                                    >
                                        <label>
                                            選択肢
                                        </label>

                                        <?php
                                        $options =
                                            is_array(
                                                $question['options']
                                                ?? null
                                            )
                                                ? $question['options']
                                                : [];
                                        ?>

                                        <div class="options">
                                            <?php foreach (
                                                $options as $oi => $option
                                            ): ?>

                                                <div class="option">
                                                    <input
                                                        type="text"
                                                        name="groups[<?= $gi ?>][questions][<?= $qi ?>][options][]"
                                                        value="<?= h(
                                                            $option
                                                        ) ?>"
                                                    >

                                                    <button
                                                        class="btn btn-light"
                                                        type="button"
                                                        onclick="
                                                            this.parentElement.remove()
                                                        "
                                                    >
                                                        削除
                                                    </button>
                                                </div>

                                            <?php endforeach; ?>
                                        </div>

                                        <button
                                            class="btn btn-secondary"
                                            type="button"
                                            onclick="
                                                addOption(this)
                                            "
                                        >
                                            ＋ 選択肢を追加
                                        </button>
                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                        <button
                            class="btn btn-secondary"
                            type="button"
                            onclick="addQuestion(this)"
                        >
                            ＋ 質問を追加
                        </button>

                    </div>

                <?php endforeach; ?>

            </div>

            <button
                class="btn"
                type="button"
                onclick="addGroup()"
            >
                ＋ グループを追加
            </button>
        </div>

        <div class="card">
            <button
                class="btn btn-success"
                type="submit"
            >
                保存して一覧へ
            </button>
        </div>
    </form>

<?php elseif ($screen === 'preview'): ?>

    <?php if ($currentSurvey === null): ?>
        <?php redirect_screen('list'); ?>
    <?php endif; ?>

    <h1>プレビュー</h1>

    <div class="card">
        <h2><?= h(
            $currentSurvey['title']
            ?? ''
        ) ?></h2>

        <p>
            <?= nl2br(
                h(
                    $currentSurvey['description']
                    ?? ''
                )
            ) ?>
        </p>

        <?php foreach (
            $currentSurvey['groups']
            ?? [] as $group
        ): ?>

            <div class="group">
                <h3><?= h(
                    $group['title']
                    ?? ''
                ) ?></h3>

                <?php foreach (
                    $group['questions']
                    ?? [] as $question
                ): ?>

                    <div class="question">

                        <div>
                            <span class="q-number">
                                <?= h(
                                    $question['number']
                                    ?? ''
                                ) ?>
                            </span>
                            <?= h(
                                $question['text']
                                ?? ''
                            ) ?>

                            <?php if (
                                !empty(
                                    $question['required']
                                )
                            ): ?>
                                <span class="badge published">
                                    必須
                                </span>
                            <?php endif; ?>
                        </div>

                        <div style="margin-top:12px">

                            <?php
                            $type =
                                $question['type']
                                ?? 'single';
                            ?>

                            <?php if (
                                $type === 'single'
                            ): ?>

                                <?php foreach (
                                    $question['options']
                                    ?? [] as $option
                                ): ?>
                                    <label
                                        style="
                                            font-weight:400;
                                            margin:8px 0
                                        "
                                    >
                                        <input
                                            type="radio"
                                            disabled
                                        >
                                        <?= h($option) ?>
                                    </label>
                                <?php endforeach; ?>

                            <?php elseif (
                                $type === 'multiple'
                            ): ?>

                                <?php foreach (
                                    $question['options']
                                    ?? [] as $option
                                ): ?>
                                    <label
                                        style="
                                            font-weight:400;
                                            margin:8px 0
                                        "
                                    >
                                        <input
                                            type="checkbox"
                                            disabled
                                        >
                                        <?= h($option) ?>
                                    </label>
                                <?php endforeach; ?>

                            <?php else: ?>

                                <textarea
                                    disabled
                                ></textarea>

                            <?php endif; ?>

                        </div>
                    </div>

                <?php endforeach; ?>
            </div>

        <?php endforeach; ?>
    </div>

<?php elseif ($screen === 'analytics'): ?>

    <h1>
        回答集計・分析：
        <?= h(
            $currentSurvey['title']
            ?? ''
        ) ?>
    </h1>

    <?php
    $answers =
        array_values(
            array_filter(
                load_answers(),
                static function (
                    $answer
                ) use ($currentId): bool {
                    return
                        is_array($answer)
                        && (
                            $answer['surveyId']
                            ?? ''
                        ) === $currentId;
                }
            )
        );
    ?>

    <div class="card">
        <h2>回答数</h2>
        <div style="font-size:36px;font-weight:700">
            <?= h(count($answers)) ?>
        </div>
    </div>

    <div class="card">
        <h2>回答データ</h2>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>回答日時</th>
                    <th>回答内容</th>
                </tr>
                </thead>

                <tbody>
                <?php foreach (
                    $answers as $answer
                ): ?>
                    <tr>
                        <td>
                            <?= h(
                                $answer['createdAt']
                                ?? ''
                            ) ?>
                        </td>
                        <td>
                            <pre
                                style="
                                    white-space:pre-wrap;
                                    margin:0
                                "
                            ><?= h(
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

                <?php if (!$answers): ?>
                    <tr>
                        <td colspan="2">
                            回答はありません。
                        </td>
                    </tr>
                <?php endif; ?>

                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($screen === 'send'): ?>

    <h1>
        顧客選択・メール送信：
        <?= h(
            $currentSurvey['title']
            ?? ''
        ) ?>
    </h1>

    <div class="card">
        <h2>対象アンケート</h2>

        <div class="kv">
            <div>タイトル</div>
            <div><?= h(
                $currentSurvey['title']
                ?? ''
            ) ?></div>

            <div>公開状態</div>
            <div>
                <span class="badge <?= h(
                    status_class(
                        $currentSurvey['status']
                        ?? 'draft'
                    )
                ) ?>">
                    <?= h(
                        status_label(
                            $currentSurvey['status']
                            ?? 'draft'
                        )
                    ) ?>
                </span>
            </div>

            <div>回答URL</div>
            <div>
                <?= h(
                    'index.php?screen=answer&id=' .
                    $currentId
                ) ?>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>顧客選択</h2>

        <?php
        $customers =
            load_customers();
        ?>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>選択</th>
                    <th>会社</th>
                    <th>氏名</th>
                    <th>メール</th>
                    <th>部署</th>
                </tr>
                </thead>

                <tbody>

                <?php foreach (
                    $customers as $customer
                ): ?>

                    <tr>
                        <td>
                            <input
                                type="checkbox"
                                form="sendForm"
                                name="customer_ids[]"
                                value="<?= h(
                                    $customer['id']
                                    ?? ''
                                ) ?>"
                            >
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

                <?php if (!$customers): ?>
                    <tr>
                        <td colspan="5">
                            顧客データがありません。
                            kintone設定画面から
                            顧客情報を同期してください。
                        </td>
                    </tr>
                <?php endif; ?>

                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>メール内容</h2>

        <form
            method="post"
            action="index.php?screen=send&id=<?= rawurlencode(
                $currentId
            ) ?>"
            id="sendForm"
            onsubmit="
                return confirm(
                    '選択した顧客へ送信しますか？'
                );
            "
        >
            <input
                type="hidden"
                name="action"
                value="send_mail"
            >

            <div class="form-row">
                <label>件名</label>
                <input
                    type="text"
                    name="subject"
                    value="<?= h(
                        ($currentSurvey['title']
                        ?? 'アンケート')
                        . 'のご案内'
                    ) ?>"
                >
            </div>

            <div class="form-row">
                <label>本文</label>
                <textarea
                    name="body"
                ><?= h(
                    "ご担当者様\n\n"
                    . "アンケートへのご協力をお願いいたします。\n\n"
                    . "{アンケートURL}\n\n"
                    . "よろしくお願いいたします。"
                ) ?></textarea>
            </div>

            <div class="small muted">
                利用可能な変数：
                {顧客名} / {アンケートURL}
            </div>

            <div class="actions" style="margin-top:14px">
                <button
                    class="btn btn-success"
                    type="submit"
                >
                    一括送信
                </button>
            </div>
        </form>
    </div>

<?php elseif (
    $screen === 'answer'
    || $screen === 'confirm'
    || $screen === 'complete'
): ?>

    <?php
    /*
     * 回答者画面は管理者ナビゲーションを表示しない。
     *
     * 既存回答途中データはセッションへ保存できる。
     */
    ?>

    <?php
    if (!session_prepare()) {
        /*
         * 回答フローではセッション必須。
         */
        http_response_code(503);
        ?>
        <div class="notice notice-error">
            セッションを利用できないため、
            回答画面を表示できません。
        </div>
        <?php
    } else:
    ?>

        <?php if ($currentSurvey === null): ?>

            <div class="notice notice-error">
                アンケートが見つかりません。
            </div>

        <?php elseif (
            ($currentSurvey['status'] ?? '')
            !== 'published'
            && $screen === 'answer'
        ): ?>

            <div class="notice notice-error">
                このアンケートは現在回答できません。
            </div>

        <?php elseif ($screen === 'complete'): ?>

            <div class="card">
                <h1>回答完了</h1>

                <p>
                    ご回答ありがとうございました。
                </p>
            </div>

        <?php elseif ($screen === 'confirm'): ?>

            <?php
            $draft =
                $_SESSION[
                    'answer_draft_' . $currentId
                ]
                ?? [];

            if (!is_array($draft)) {
                $draft = [];
            }
            ?>

            <div class="card">
                <h1>回答確認</h1>

                <p>
                    以下の内容で送信します。
                </p>

                <?php foreach (
                    $currentSurvey['groups']
                    ?? [] as $group
                ): ?>

                    <div class="group">
                        <h3><?= h(
                            $group['title']
                            ?? ''
                        ) ?></h3>

                        <?php foreach (
                            $group['questions']
                            ?? [] as $question
                        ): ?>

                            <div class="question">
                                <strong>
                                    <?= h(
                                        $question['number']
                                        ?? ''
                                    ) ?>
                                    <?= h(
                                        $question['text']
                                        ?? ''
                                    ) ?>
                                </strong>

                                <div style="margin-top:8px">
                                    <?= nl2br(
                                        h(
                                            is_array(
                                                $draft[
                                                    $question['id']
                                                    ?? ''
                                                ] ?? null
                                            )
                                                ? implode(
                                                    ', ',
                                                    $draft[
                                                        $question['id']
                                                        ?? ''
                                                    ]
                                                )
                                                : (
                                                    $draft[
                                                        $question['id']
                                                        ?? ''
                                                    ]
                                                    ?? ''
                                                )
                                        )
                                    ) ?>
                                </div>
                            </div>

                        <?php endforeach; ?>
                    </div>

                <?php endforeach; ?>

                <form
                    method="post"
                    action="index.php?screen=answer&id=<?= rawurlencode(
                        $currentId
                    ) ?>"
                    onsubmit="
                        return confirm(
                            '回答を送信しますか？'
                        );
                    "
                >
                    <input
                        type="hidden"
                        name="action"
                        value="complete_answer"
                    >

                    <div class="actions">
                        <a
                            class="btn btn-light"
                            href="?screen=answer&id=<?= rawurlencode(
                                $currentId
                            ) ?>"
                        >
                            戻る
                        </a>

                        <button
                            class="btn btn-success"
                            type="submit"
                        >
                            回答を送信
                        </button>
                    </div>
                </form>
            </div>

        <?php else: ?>

            <div class="card">
                <h1>
                    <?= h(
                        $currentSurvey['title']
                        ?? ''
                    ) ?>
                </h1>

                <p>
                    <?= nl2br(
                        h(
                            $currentSurvey['description']
                            ?? ''
                        )
                    ) ?>
                </p>
            </div>

            <form
                method="post"
                action="index.php?screen=answer&id=<?= rawurlencode(
                    $currentId
                ) ?>"
            >
                <input
                    type="hidden"
                    name="action"
                    value="prepare_answer"
                >

                <?php foreach (
                    $currentSurvey['groups']
                    ?? [] as $group
                ): ?>

                    <div class="card">
                        <h2><?= h(
                            $group['title']
                            ?? ''
                        ) ?></h2>

                        <?php foreach (
                            $group['questions']
                            ?? [] as $question
                        ): ?>

                            <?php
                            $qid =
                                (string)(
                                    $question['id']
                                    ?? ''
                                );

                            $type =
                                (string)(
                                    $question['type']
                                    ?? 'single'
                                );

                            $required =
                                !empty(
                                    $question['required']
                                );
                            ?>

                            <div class="question">

                                <div class="form-row">
                                    <label>
                                        <span
                                            class="q-number"
                                        >
                                            <?= h(
                                                $question['number']
                                                ?? ''
                                            ) ?>
                                        </span>
                                        <?= h(
                                            $question['text']
                                            ?? ''
                                        ) ?>

                                        <?php if ($required): ?>
                                            <span
                                                class="badge published"
                                            >
                                                必須
                                            </span>
                                        <?php endif; ?>
                                    </label>

                                    <?php if (
                                        $type === 'single'
                                    ): ?>

                                        <?php foreach (
                                            $question['options']
                                            ?? [] as $option
                                        ): ?>

                                            <label
                                                style="
                                                    font-weight:400;
                                                    margin:9px 0
                                                "
                                            >
                                                <input
                                                    type="radio"
                                                    name="answers[<?= h(
                                                        $qid
                                                    ) ?>]"
                                                    value="<?= h(
                                                        $option
                                                    ) ?>"
                                                    <?= (
                                                        (
                                                            $_SESSION[
                                                                'answer_draft_'
                                                                . $currentId
                                                            ][$qid]
                                                            ?? ''
                                                        )
                                                        === $option
                                                    )
                                                        ? 'checked'
                                                        : '' ?>
                                                >
                                                <?= h(
                                                    $option
                                                ) ?>
                                            </label>

                                        <?php endforeach; ?>

                                    <?php elseif (
                                        $type === 'multiple'
                                    ): ?>

                                        <?php
                                        $oldMultiple =
                                            $_SESSION[
                                                'answer_draft_'
                                                . $currentId
                                            ][$qid]
                                            ?? [];

                                        if (!is_array(
                                            $oldMultiple
                                        )) {
                                            $oldMultiple = [];
                                        }
                                        ?>

                                        <?php foreach (
                                            $question['options']
                                            ?? [] as $option
                                        ): ?>

                                            <label
                                                style="
                                                    font-weight:400;
                                                    margin:9px 0
                                                "
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="answers[<?= h(
                                                        $qid
                                                    ) ?>][]"
                                                    value="<?= h(
                                                        $option
                                                    ) ?>"
                                                    <?= in_array(
                                                        $option,
                                                        $oldMultiple,
                                                        true
                                                    )
                                                        ? 'checked'
                                                        : '' ?>
                                                >
                                                <?= h(
                                                    $option
                                                ) ?>
                                            </label>

                                        <?php endforeach; ?>

                                    <?php else: ?>

                                        <textarea
                                            name="answers[<?= h(
                                                $qid
                                            ) ?>]"
                                            rows="5"
                                        ><?= h(
                                            $_SESSION[
                                                'answer_draft_'
                                                . $currentId
                                            ][$qid]
                                            ?? ''
                                        ) ?></textarea>

                                    <?php endif; ?>

                                </div>
                            </div>

                        <?php endforeach; ?>
                    </div>

                <?php endforeach; ?>

                <div class="card">
                    <button
                        class="btn btn-success"
                        type="submit"
                    >
                        回答を確認する
                    </button>
                </div>
            </form>

        <?php endif; ?>

    <?php endif; ?>

<?php endif; ?>

</main>

<script>
(function () {
    "use strict";

    const kintoneForm =
        document.getElementById("kintoneForm");

    if (kintoneForm) {
        kintoneForm.addEventListener(
            "submit",
            function () {
                const action =
                    document.getElementById(
                        "kintoneAction"
                    );

                const loading =
                    document.getElementById(
                        "kintoneLoading"
                    );

                if (
                    action
                    && action.value === "test_kintone"
                    && loading
                ) {
                    loading.classList.add("active");
                }
            }
        );
    }

    const numbering =
        document.querySelector(
            '[name="numbering"]'
        );

    if (numbering) {
        numbering.addEventListener(
            "change",
            recalcClientNumbers
        );
    }

    recalcClientNumbers();
})();

function recalcClientNumbers() {
    const groups =
        document.querySelectorAll(
            "#groups .group"
        );

    let globalNo = 1;

    groups.forEach(
        function (group, groupIndex) {
            const questions =
                group.querySelectorAll(
                    ".question"
                );

            questions.forEach(
                function (question, questionIndex) {
                    const number =
                        question.querySelector(
                            ".q-number"
                        );

                    if (!number) {
                        return;
                    }

                    const numbering =
                        document.querySelector(
                            '[name="numbering"]'
                        );

                    if (
                        numbering
                        && numbering.value === "group"
                    ) {
                        number.textContent =
                            "Q"
                            + (groupIndex + 1)
                            + "-"
                            + (questionIndex + 1);
                    } else {
                        number.textContent =
                            "Q"
                            + globalNo;
                    }

                    globalNo++;
                }
            );
        }
    );
}

function reindexFormNames() {
    const groups =
        document.querySelectorAll(
            "#groups .group"
        );

    groups.forEach(
        function (group, gi) {
            const groupId =
                group.querySelector(
                    'input[name$="[id]"]'
                );

            if (groupId) {
                groupId.name =
                    "groups[" + gi + "][id]";
            }

            const title =
                group.querySelector(
                    'input[name$="[title]"]'
                );

            if (title) {
                title.name =
                    "groups[" + gi + "][title]";
            }

            const questions =
                group.querySelectorAll(
                    ".question"
                );

            questions.forEach(
                function (question, qi) {
                    const inputs =
                        question.querySelectorAll(
                            "input, select, textarea"
                        );

                    inputs.forEach(
                        function (input) {
                            const name =
                                input.getAttribute(
                                    "name"
                                );

                            if (!name) {
                                return;
                            }

                            const matches =
                                name.match(
                                    /groups\[\d+\]\[questions\]\[\d+\]\[(.+?)(?:\]\[\])?\]/
                                );

                            if (!matches) {
                                return;
                            }

                            const field =
                                matches[1];

                            if (
                                name.endsWith(
                                    "[options][]"
                                )
                            ) {
                                input.name =
                                    "groups["
                                    + gi
                                    + "][questions]["
                                    + qi
                                    + "][options][]";
                            } else {
                                input.name =
                                    "groups["
                                    + gi
                                    + "][questions]["
                                    + qi
                                    + "]["
                                    + field
                                    + "]";
                            }
                        }
                    );
                }
            );
        }
    );
}

function removeGroup(button) {
    if (
        !confirm(
            "このグループを削除しますか？"
        )
    ) {
        return;
    }

    const group =
        button.closest(".group");

    if (!group) {
        return;
    }

    group.remove();

    ensureOneGroup();

    reindexFormNames();
    recalcClientNumbers();
}

function ensureOneGroup() {
    const groups =
        document.querySelectorAll(
            "#groups .group"
        );

    if (groups.length > 0) {
        return;
    }

    addGroup();
}

function removeQuestion(button) {
    if (
        !confirm(
            "この質問を削除しますか？"
        )
    ) {
        return;
    }

    const question =
        button.closest(".question");

    if (!question) {
        return;
    }

    const group =
        question.closest(".group");

    question.remove();

    if (group) {
        const questions =
            group.querySelectorAll(
                ".question"
            );

        if (questions.length === 0) {
            addQuestion(
                group.querySelector(
                    ".btn.btn-secondary"
                )
            );
        }
    }

    reindexFormNames();
    recalcClientNumbers();
}

function addOption(button) {
    const area =
        button.closest(
            ".options-area"
        );

    if (!area) {
        return;
    }

    const options =
        area.querySelector(
            ".options"
        );

    if (!options) {
        return;
    }

    const row =
        document.createElement("div");

    row.className = "option";

    const input =
        document.createElement("input");

    input.type = "text";
    input.name =
        "TEMP_OPTIONS[]";

    const remove =
        document.createElement("button");

    remove.type = "button";
    remove.className =
        "btn btn-light";
    remove.textContent =
        "削除";

    remove.onclick =
        function () {
            row.remove();
        };

    row.appendChild(input);
    row.appendChild(remove);
    options.appendChild(row);

    reindexFormNames();
}

function updateQuestionType(select) {
    const question =
        select.closest(
            ".question"
        );

    if (!question) {
        return;
    }

    const area =
        question.querySelector(
            ".options-area"
        );

    if (!area) {
        return;
    }

    if (select.value === "text") {
        area.style.display = "none";
    } else {
        area.style.display = "";
    }
}

function addQuestion(button) {
    const group =
        button.closest(".group");

    if (!group) {
        return;
    }

    const questions =
        group.querySelector(
            ".questions"
        );

    if (!questions) {
        return;
    }

    const question =
        document.createElement("div");

    question.className =
        "question";

    question.draggable = true;

    question.innerHTML = `
        <div class="question-head">
            <span class="q-number">Q</span>
            <button
                class="btn btn-danger"
                type="button"
                onclick="removeQuestion(this)"
            >削除</button>
        </div>

        <input
            type="hidden"
            data-field="id"
            value="q-${Date.now()}-${Math.random().toString(16).slice(2)}"
        >

        <div class="form-row">
            <label>質問文</label>
            <input
                type="text"
                data-field="text"
                value=""
                placeholder="質問文"
            >
        </div>

        <div class="grid-2">
            <div class="form-row">
                <label>回答形式</label>
                <select
                    data-field="type"
                    onchange="updateQuestionType(this)"
                >
                    <option value="single">単一選択</option>
                    <option value="multiple">複数選択</option>
                    <option value="text">自由記述</option>
                </select>
            </div>

            <div class="form-row">
                <label>必須設定</label>
                <label
                    style="
                        display:flex;
                        gap:8px;
                        align-items:center;
                        font-weight:400
                    "
                >
                    <input
                        type="checkbox"
                        data-field="required"
                        value="1"
                        checked
                    >
                    必須
                </label>
            </div>
        </div>

        <div class="options-area">
            <label>選択肢</label>

            <div class="options">
                <div class="option">
                    <input
                        type="text"
                        data-option="1"
                        value="選択肢1"
                    >
                    <button
                        class="btn btn-light"
                        type="button"
                        onclick="this.parentElement.remove()"
                    >削除</button>
                </div>

                <div class="option">
                    <input
                        type="text"
                        data-option="1"
                        value="選択肢2"
                    >
                    <button
                        class="btn btn-light"
                        type="button"
                        onclick="this.parentElement.remove()"
                    >削除</button>
                </div>
            </div>

            <button
                class="btn btn-secondary"
                type="button"
                onclick="addOption(this)"
            >＋ 選択肢を追加</button>
        </div>
    `;

    questions.appendChild(question);

    normalizeDynamicNames();
    recalcClientNumbers();
}

function addGroup() {
    const groups =
        document.getElementById(
            "groups"
        );

    if (!groups) {
        return;
    }

    const group =
        document.createElement("div");

    group.className = "group";
    group.draggable = true;

    group.innerHTML = `
        <div class="question-head">
            <h3>グループ</h3>

            <button
                class="btn btn-danger"
                type="button"
                onclick="removeGroup(this)"
            >グループ削除</button>
        </div>

        <input
            type="hidden"
            data-group-id="1"
            value="g-${Date.now()}-${Math.random().toString(16).slice(2)}"
        >

        <div class="form-row">
            <label>グループタイトル</label>
            <input
                type="text"
                data-group-title="1"
                value="新しいグループ"
            >
        </div>

        <div class="questions"></div>

        <button
            class="btn btn-secondary"
            type="button"
            onclick="addQuestion(this)"
        >＋ 質問を追加</button>
    `;

    groups.appendChild(group);

    addQuestion(
        group.querySelector(
            ".btn.btn-secondary"
        )
    );

    normalizeDynamicNames();
    recalcClientNumbers();
}

function normalizeDynamicNames() {
    const groups =
        document.querySelectorAll(
            "#groups .group"
        );

    groups.forEach(
        function (group, gi) {
            const groupId =
                group.querySelector(
                    "[data-group-id]"
                );

            if (groupId) {
                groupId.name =
                    "groups[" + gi + "][id]";
            }

            const groupTitle =
                group.querySelector(
                    "[data-group-title]"
                );

            if (groupTitle) {
                groupTitle.name =
                    "groups[" + gi + "][title]";
            }

            const questions =
                group.querySelectorAll(
                    ".question"
                );

            questions.forEach(
                function (question, qi) {
                    const id =
                        question.querySelector(
                            '[data-field="id"]'
                        );

                    const text =
                        question.querySelector(
                            '[data-field="text"]'
                        );

                    const type =
                        question.querySelector(
                            '[data-field="type"]'
                        );

                    const required =
                        question.querySelector(
                            '[data-field="required"]'
                        );

                    if (id) {
                        id.name =
                            "groups["
                            + gi
                            + "][questions]["
                            + qi
                            + "][id]";
                    }

                    if (text) {
                        text.name =
                            "groups["
                            + gi
                            + "][questions]["
                            + qi
                            + "][text]";
                    }

                    if (type) {
                        type.name =
                            "groups["
                            + gi
                            + "][questions]["
                            + qi
                            + "][type]";
                    }

                    if (required) {
                        required.name =
                            "groups["
                            + gi
                            + "][questions]["
                            + qi
                            + "][required]";
                    }

                    const options =
                        question.querySelectorAll(
                            "[data-option]"
                        );

                    options.forEach(
                        function (option) {
                            option.name =
                                "groups["
                                + gi
                                + "][questions]["
                                + qi
                                + "][options][]";
                        }
                    );

                    /*
                     * 既存HTMLの通常inputにも対応。
                     */
                    const existingOptions =
                        question.querySelectorAll(
                            ".options input[type=text]"
                        );

                    existingOptions.forEach(
                        function (option) {
                            option.name =
                                "groups["
                                + gi
                                + "][questions]["
                                + qi
                                + "][options][]";
                        }
                    );
                }
            );
        }
    );
}

const surveyForm =
    document.getElementById(
        "surveyEditForm"
    );

if (surveyForm) {
    surveyForm.addEventListener(
        "submit",
        function () {
            normalizeDynamicNames();
            reindexFormNames();
        }
    );
}

document.addEventListener(
    "change",
    function (event) {
        if (
            event.target
            && event.target.matches(
                'select[data-field="type"]'
            )
        ) {
            updateQuestionType(
                event.target
            );
        }
    }
);
</script>

</body>
</html>
<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 * Apache 2.4 / PHP 8.5
 *
 * 単一エントリーポイント
 * DBなし / PHP cURLなし / PHP mail()なし
 *
 * 要件に基づく全差し替え版。
 *
 * kintone認証の重要事項:
 * - 認証情報はサーバー側のみで保持
 * - X-Cybozu-Authorization はサーバー側で毎回生成
 * - ブラウザへ認証ヘッダーを渡さない
 * - URLへ認証情報を入れない
 * - 「設定保存」と「接続テスト」を分離
 * - 接続テスト、項目取得、顧客同期は必ず保存済み設定を使用
 * - パスワード入力欄が空でも保存済みパスワードを消去しない
 * - password は trim() しない
 * - kintone顧客取得はCursor APIで全件取得
 * - cURLは使用しない
 */

date_default_timezone_set('Asia/Tokyo');

const APP_VERSION = '2.0.0';

const HTTP_CONNECT_TIMEOUT = 10;
const HTTP_TIMEOUT = 20;

const SMTP_CONNECT_TIMEOUT = 15;
const SMTP_TIMEOUT = 15;

const MAX_TITLE_LENGTH = 200;
const MAX_DESCRIPTION_LENGTH = 10000;
const MAX_QUESTION_LENGTH = 2000;
const MAX_OPTION_LENGTH = 500;

$APP_DIR = __DIR__;
$DATA_DIR = $APP_DIR . DIRECTORY_SEPARATOR . 'data';

/* ============================================================
 * 初期化
 * ============================================================ */

if (!is_dir($DATA_DIR)) {
    if (!@mkdir($DATA_DIR, 0770, true) && !is_dir($DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

if (!is_writable($DATA_DIR)) {
    http_response_code(500);
    exit('データ保存領域へ書き込めません。');
}

/*
 * セッションは回答途中等の短期状態保持に使用。
 * GETアクセスごとのsession_regenerate_id()は禁止。
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
    $cookiePath = dirname($scriptName);

    if ($cookiePath === '.' || $cookiePath === '') {
        $cookiePath = '/';
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        http_response_code(500);
        exit('セッションを利用できません。');
    }
}

/* ============================================================
 * 共通関数
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

function data_file(string $name): string
{
    global $DATA_DIR;
    return $DATA_DIR . DIRECTORY_SEPARATOR . $name;
}

function random_id(string $prefix): string
{
    return $prefix . bin2hex(random_bytes(10));
}

function json_read(string $file, mixed $default = []): mixed
{
    if (!is_file($file)) {
        return $default;
    }

    $json = @file_get_contents($file);

    if ($json === false || trim($json) === '') {
        return $default;
    }

    $data = json_decode($json, true);

    return json_last_error() === JSON_ERROR_NONE
        ? $data
        : $default;
}

function json_write(string $file, mixed $data): bool
{
    $dir = dirname($file);

    if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
        return false;
    }

    try {
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));
    } catch (Throwable) {
        return false;
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        @unlink($tmp);
        return false;
    }

    $fp = @fopen($tmp, 'wb');

    if ($fp === false) {
        return false;
    }

    $ok = false;

    try {
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            @unlink($tmp);
            return false;
        }

        $written = fwrite($fp, $json);

        if ($written === false || $written < strlen($json)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($tmp);
            return false;
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $ok = @rename($tmp, $file);

        if (!$ok) {
            @unlink($tmp);
        }
    } catch (Throwable) {
        @fclose($fp);
        @unlink($tmp);
        $ok = false;
    }

    return $ok;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

function verify_csrf(): void
{
    $token = (string)($_POST['_csrf'] ?? '');

    if (
        $token === ''
        || !hash_equals(csrf_token(), $token)
    ) {
        http_response_code(400);
        exit('セッションエラー：不正なリクエストです。');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function take_flash(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);

    return is_array($items) ? $items : [];
}

function redirect_screen(
    string $screen,
    array $params = []
): never {
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
        if (
            !is_string($key)
            || !preg_match('/^[A-Za-z0-9_]+$/', $key)
        ) {
            continue;
        }

        if (
            is_string($value)
            || is_int($value)
            || is_float($value)
        ) {
            $query[$key] = (string)$value;
        }
    }

    header(
        'Location: index.php?'
        . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
        true,
        303
    );
    exit;
}

function safe_id(string $id): string
{
    return preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id)
        ? $id
        : '';
}

function valid_datetime(string $value): bool
{
    if ($value === '') {
        return true;
    }

    $timestamp = strtotime($value);

    return $timestamp !== false;
}

function valid_email(string $value): bool
{
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

/* ============================================================
 * データ読み書き
 * ============================================================ */

function load_surveys(): array
{
    $data = json_read(data_file('surveys.json'), []);

    return is_array($data) ? array_values($data) : [];
}

function save_surveys(array $rows): bool
{
    return json_write(
        data_file('surveys.json'),
        array_values($rows)
    );
}

function load_customers(): array
{
    $data = json_read(data_file('customers.json'), []);

    return is_array($data) ? array_values($data) : [];
}

function save_customers(array $rows): bool
{
    return json_write(
        data_file('customers.json'),
        array_values($rows)
    );
}

function load_answers(): array
{
    $data = json_read(data_file('answers.json'), []);

    return is_array($data) ? array_values($data) : [];
}

function save_answers(array $rows): bool
{
    return json_write(
        data_file('answers.json'),
        array_values($rows)
    );
}

function load_send_history(): array
{
    $data = json_read(data_file('send_history.json'), []);

    return is_array($data) ? array_values($data) : [];
}

function save_send_history(array $rows): bool
{
    return json_write(
        data_file('send_history.json'),
        array_values($rows)
    );
}

/* ============================================================
 * kintone設定
 * ============================================================ */

function default_kintone(): array
{
    return [
        'subdomain' => '',
        'app_id' => '',
        'username' => '',
        'password' => '',
        'proxy' => '',
        'verify_ssl' => false,
        'fields' => [],
        'address_mapping' => [],
        'status' => '未設定',
        'last_test' => '',
        'last_sync' => '',
    ];
}

function load_kintone(): array
{
    $data = json_read(data_file('kintone.json'), []);

    if (!is_array($data)) {
        $data = [];
    }

    return array_merge(default_kintone(), $data);
}

function save_kintone(array $config): bool
{
    return json_write(
        data_file('kintone.json'),
        $config
    );
}

/*
 * 重要:
 * パスワードを画面表示用データに混ぜない。
 * 保存時はサーバー側ファイルへのみ保存する。
 */
function kintone_display_config(array $config): array
{
    return [
        'subdomain' => (string)($config['subdomain'] ?? ''),
        'app_id' => (string)($config['app_id'] ?? ''),
        'username' => (string)($config['username'] ?? ''),
        'proxy' => (string)($config['proxy'] ?? ''),
        'verify_ssl' => !empty($config['verify_ssl']),
        'fields' => is_array($config['fields'] ?? null)
            ? $config['fields']
            : [],
        'address_mapping' => is_array(
            $config['address_mapping'] ?? null
        )
            ? $config['address_mapping']
            : [],
        'status' => (string)($config['status'] ?? '未設定'),
        'last_test' => (string)($config['last_test'] ?? ''),
        'last_sync' => (string)($config['last_sync'] ?? ''),
        'password_set' =>
            (string)($config['password'] ?? '') !== '',
    ];
}

/* ============================================================
 * メール設定
 * ============================================================ */

function default_mail(): array
{
    return [
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
    ];
}

function load_mail(): array
{
    $data = json_read(data_file('mail.json'), []);

    if (!is_array($data)) {
        $data = [];
    }

    return array_merge(default_mail(), $data);
}

function save_mail(array $config): bool
{
    return json_write(
        data_file('mail.json'),
        $config
    );
}

/* ============================================================
 * アンケート構造
 * ============================================================ */

function new_question(): array
{
    return [
        'id' => random_id('q-'),
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
        'id' => random_id('g-'),
        'title' => '新しいグループ',
        'questions' => [
            new_question(),
        ],
    ];
}

function new_survey(): array
{
    $survey = [
        'id' => random_id('survey-'),
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

function normalize_survey(array $survey): array
{
    $survey['id'] = safe_id(
        (string)($survey['id'] ?? '')
    ) ?: random_id('survey-');

    $survey['title'] =
        (string)($survey['title'] ?? '');

    $survey['description'] =
        (string)($survey['description'] ?? '');

    $survey['startAt'] =
        (string)($survey['startAt'] ?? '');

    $survey['endAt'] =
        (string)($survey['endAt'] ?? '');

    $status = (string)($survey['status'] ?? 'draft');

    if (!in_array(
        $status,
        ['draft', 'published', 'stopped', 'ended'],
        true
    )) {
        $status = 'draft';
    }

    $survey['status'] = $status;

    $survey['numbering'] =
        ($survey['numbering'] ?? 'global') === 'group'
            ? 'group'
            : 'global';

    if (!is_array($survey['groups'] ?? null)) {
        $survey['groups'] = [];
    }

    foreach ($survey['groups'] as &$group) {
        if (!is_array($group)) {
            $group = new_group();
            continue;
        }

        $group['id'] = safe_id(
            (string)($group['id'] ?? '')
        ) ?: random_id('g-');

        $group['title'] =
            (string)($group['title'] ?? '新しいグループ');

        if (!is_array($group['questions'] ?? null)) {
            $group['questions'] = [];
        }

        foreach ($group['questions'] as &$question) {
            if (!is_array($question)) {
                $question = new_question();
                continue;
            }

            $question['id'] = safe_id(
                (string)($question['id'] ?? '')
            ) ?: random_id('q-');

            $question['number'] =
                (string)($question['number'] ?? '');

            $question['text'] =
                (string)($question['text'] ?? '');

            $type = (string)($question['type'] ?? 'single');

            if (!in_array(
                $type,
                ['single', 'multiple', 'text'],
                true
            )) {
                $type = 'single';
            }

            $question['type'] = $type;
            $question['required'] =
                !empty($question['required']);

            if (!is_array($question['options'] ?? null)) {
                $question['options'] = [];
            }

            $question['options'] = array_values(
                array_map(
                    static fn($v): string => (string)$v,
                    $question['options']
                )
            );

            if (!is_array($question['branching'] ?? null)) {
                $question['branching'] = [];
            }
        }

        unset($question);
    }

    unset($group);

    if (!$survey['groups']) {
        $survey['groups'] = [new_group()];
    }

    recalc_question_numbers($survey);

    return $survey;
}

function recalc_question_numbers(array &$survey): void
{
    $mode = $survey['numbering'] ?? 'global';
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            if ($mode === 'group') {
                $question['number'] =
                    'Q' . $groupNo . '-' . $questionNo;
            } else {
                $question['number'] =
                    'Q' . $global;
            }

            $questionNo++;
            $global++;
        }

        unset($question);
        $groupNo++;
    }

    unset($group);
}

function find_survey(
    array $surveys,
    string $id
): ?array {
    foreach ($surveys as $survey) {
        if (
            is_array($survey)
            && ($survey['id'] ?? '') === $id
        ) {
            return normalize_survey($survey);
        }
    }

    return null;
}

function count_questions(array $survey): int
{
    $count = 0;

    foreach ($survey['groups'] ?? [] as $group) {
        $count += count($group['questions'] ?? []);
    }

    return $count;
}

function question_map(array $survey): array
{
    $map = [];

    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            if (!empty($question['id'])) {
                $map[$question['id']] = $question;
            }
        }
    }

    return $map;
}

/* ============================================================
 * アンケート状態
 * ============================================================ */

function refresh_survey_status(
    array &$survey
): bool {
    if (
        ($survey['status'] ?? 'draft') === 'published'
        && !empty($survey['endAt'])
    ) {
        $end = strtotime((string)$survey['endAt']);

        if (
            $end !== false
            && $end < time()
        ) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = now_iso();

            return true;
        }
    }

    return false;
}

function refresh_all_statuses(
    array &$surveys
): void {
    $changed = false;

    foreach ($surveys as &$survey) {
        $survey = normalize_survey($survey);

        if (refresh_survey_status($survey)) {
            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {
        save_surveys($surveys);
    }
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
        'published' => 'status-published',
        'stopped' => 'status-stopped',
        'ended' => 'status-ended',
        default => 'status-draft',
    };
}

/* ============================================================
 * kintone
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

    /*
     * xxxx.cybozu.com
     * https://xxxx.cybozu.com
     * xxxx
     * のいずれもxxxxへ正規化。
     */
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

function parse_proxy(
    string $proxy
): ?array {
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

function validate_kintone_config(
    array $config
): array {
    $errors = [];

    $subdomain = normalize_subdomain(
        (string)($config['subdomain'] ?? '')
    );

    if (
        $subdomain === ''
        || !preg_match(
            '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/',
            $subdomain
        )
    ) {
        $errors[] =
            'サブドメインが正しくありません。';
    }

    $appId = trim(
        (string)($config['app_id'] ?? '')
    );

    if (
        $appId === ''
        || !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        $errors[] =
            '顧客管理アプリIDが正しくありません。';
    }

    /*
     * usernameはtrimして入力値の前後空白を除去。
     * passwordは絶対にtrimしない。
     */
    $username = trim(
        (string)($config['username'] ?? '')
    );

    if ($username === '') {
        $errors[] = 'ログイン名を入力してください。';
    }

    if (
        (string)($config['password'] ?? '') === ''
    ) {
        $errors[] = 'パスワードが設定されていません。';
    }

    $proxy = trim(
        (string)($config['proxy'] ?? '')
    );

    if (
        $proxy !== ''
        && parse_proxy($proxy) === null
    ) {
        $errors[] =
            'Proxyは host:port 形式で入力してください。';
    }

    return [
        'errors' => $errors,
    ];
}

/*
 * X-Cybozu-Authorizationの生成はこの関数だけで行う。
 *
 * username:password
 * をUTF-8バイト列としてBase64化する。
 *
 * passwordはtrimしない。
 */
function kintone_auth_header(
    array $config
): string {
    $username = trim(
        (string)($config['username'] ?? '')
    );

    $password = (string)(
        $config['password'] ?? ''
    );

    return base64_encode(
        $username . ':' . $password
    );
}

function kintone_context(
    array $config,
    string $method,
    string $content = ''
): array {
    $verifySsl =
        !empty($config['verify_ssl']);

    $headers = [
        'Accept: application/json',
        'X-Cybozu-Authorization: '
            . kintone_auth_header($config),
    ];

    if ($content !== '') {
        $headers[] =
            'Content-Type: application/json';
        $headers[] =
            'Content-Length: ' . strlen($content);
    }

    $http = [
        'method' => strtoupper($method),
        'header' => implode("\r\n", $headers),
        'timeout' => HTTP_TIMEOUT,
        'ignore_errors' => true,
        'protocol_version' => 1.1,
        'follow_location' => 0,
        'max_redirects' => 0,
    ];

    if ($content !== '') {
        $http['content'] = $content;
    }

    $ssl = [
        'verify_peer' => $verifySsl,
        'verify_peer_name' => $verifySsl,
        'allow_self_signed' => !$verifySsl,
        'SNI_enabled' => true,
        'peer_name' => kintone_host($config),
    ];

    $proxy = parse_proxy(
        (string)($config['proxy'] ?? '')
    );

    if ($proxy !== null) {
        $http['proxy'] =
            'tcp://'
            . $proxy['host']
            . ':'
            . $proxy['port'];

        /*
         * HTTPS over HTTP proxy requires the complete
         * destination URI for PHP streams.
         */
        $http['request_fulluri'] = true;
    }

    return [
        'http' => $http,
        'ssl' => $ssl,
    ];
}

function parse_http_headers(
    array $headers
): array {
    $status = 0;

    foreach ($headers as $line) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+([0-9]{3})/',
                $line,
                $m
            )
        ) {
            $status = (int)$m[1];
        }
    }

    return [
        'status' => $status,
    ];
}

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
            'category' => '設定エラー',
            'status' => 0,
            'message' => implode(
                ' ',
                $validation['errors']
            ),
            'data' => null,
        ];
    }

    $host = kintone_host($config);

    if (
        !preg_match(
            '/^[A-Za-z0-9.-]+\.cybozu\.com$/',
            $host
        )
    ) {
        return [
            'ok' => false,
            'category' => '設定エラー',
            'status' => 0,
            'message' =>
                'kintoneサブドメインが正しくありません。',
            'data' => null,
        ];
    }

    $url =
        'https://'
        . $host
        . $path;

    $content = '';

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($content === false) {
            return [
                'ok' => false,
                'category' => 'データエラー',
                'status' => 0,
                'message' =>
                    'リクエストデータを作成できません。',
                'data' => null,
            ];
        }
    }

    $context = kintone_context(
        $config,
        $method,
        $content
    );

    $contextResource =
        stream_context_create($context);

    $errorNo = 0;
    $errorString = '';

    /*
     * stream_socket_clientを直接使うとCONNECT/SSL/proxy処理が
     * 複雑になるため、PHP標準HTTP streamを使用。
     *
     * timeoutはHTTPレイヤーに設定し、無期限待機を禁止。
     */
    $start = microtime(true);

    set_error_handler(
        static function (
            int $severity,
            string $message
        ) use (&$errorNo, &$errorString): bool {
            $errorString = $message;
            $errorNo = $severity;

            return true;
        }
    );

    try {
        $responseBody = @file_get_contents(
            $url,
            false,
            $contextResource
        );

        $responseHeaders =
            $http_response_header ?? [];
    } finally {
        restore_error_handler();
    }

    $elapsed =
        microtime(true) - $start;

    if (
        $responseBody === false
        && $responseHeaders === []
    ) {
        $message =
            'kintoneへ接続できませんでした。';

        if (
            $errorString !== ''
            && strlen($errorString) < 300
        ) {
            $message .=
                ' 通信詳細: '
                . preg_replace(
                    '/[\r\n]+/',
                    ' ',
                    $errorString
                );
        }

        return [
            'ok' => false,
            'category' =>
                $elapsed >= HTTP_TIMEOUT
                    ? 'タイムアウト'
                    : '通信エラー',
            'status' => 0,
            'message' => $message,
            'data' => null,
        ];
    }

    $headerInfo =
        parse_http_headers($responseHeaders);

    $status = $headerInfo['status'];

    $decoded = null;

    if (
        is_string($responseBody)
        && trim($responseBody) !== ''
    ) {
        $decoded = json_decode(
            $responseBody,
            true
        );
    }

    if (
        $status >= 200
        && $status < 300
    ) {
        return [
            'ok' => true,
            'category' => '成功',
            'status' => $status,
            'message' => 'kintone処理が成功しました。',
            'data' =>
                is_array($decoded)
                    ? $decoded
                    : [],
        ];
    }

    /*
     * kintoneのエラー本文から
     * code / message / id を抽出。
     *
     * 認証情報そのものは絶対に表示しない。
     */
    $errorCode = '';
    $errorMessage = '';
    $errorId = '';

    if (is_array($decoded)) {
        $errorCode = (string)(
            $decoded['code'] ?? ''
        );

        $errorMessage = (string)(
            $decoded['message'] ?? ''
        );

        $errorId = (string)(
            $decoded['id'] ?? ''
        );
    }

    $category = '外部サービスエラー';

    if (
        $status === 401
        || $status === 403
        || in_array(
            strtoupper($errorCode),
            [
                'CB_AU01',
                'CB_IJ01',
                'CB_NO01',
            ],
            true
        )
    ) {
        $category = '認証エラー';
    } elseif ($status === 0) {
        $category = '通信エラー';
    }

    $message =
        $errorMessage !== ''
            ? $errorMessage
            : 'kintoneからエラーが返されました。';

    if ($errorCode !== '') {
        $message .=
            '（コード: '
            . $errorCode
            . '）';
    }

    if ($errorId !== '') {
        $message .=
            '（エラーID: '
            . $errorId
            . '）';
    }

    return [
        'ok' => false,
        'category' => $category,
        'status' => $status,
        'message' => $message,
        'data' =>
            is_array($decoded)
                ? $decoded
                : null,
    ];
}

/*
 * 接続テスト専用。
 *
 * 設定保存・項目取得・顧客同期とは完全に分離。
 * GET records等を行わず、app/form.jsonを使用。
 */
function kintone_connection_test(
    array $config
): array {
    $validation =
        validate_kintone_config($config);

    if ($validation['errors']) {
        return [
            'ok' => false,
            'category' => '設定エラー',
            'status' => 0,
            'message' => implode(
                ' ',
                $validation['errors']
            ),
        ];
    }

    $result = kintone_request(
        $config,
        'GET',
        '/k/v1/app/form.json?app='
            . rawurlencode(
                (string)$config['app_id']
            )
    );

    if (!$result['ok']) {
        return [
            'ok' => false,
            'category' => $result['category'],
            'status' => $result['status'],
            'message' => $result['message'],
        ];
    }

    return [
        'ok' => true,
        'category' => '成功',
        'status' => $result['status'],
        'message' =>
            'kintoneへの接続に成功しました。'
            . ' 認証情報、アプリID、通信経路を確認できました。',
    ];
}

function kintone_fetch_fields(
    array $config
): array {
    $result = kintone_request(
        $config,
        'GET',
        '/k/v1/app/form.json?app='
            . rawurlencode(
                (string)$config['app_id']
            )
    );

    if (!$result['ok']) {
        return $result;
    }

    $properties =
        $result['data']['properties'] ?? [];

    if (!is_array($properties)) {
        return [
            'ok' => false,
            'category' => 'データエラー',
            'status' => $result['status'],
            'message' =>
                'kintoneの項目情報を解釈できませんでした。',
            'data' => null,
        ];
    }

    $fields = [];

    foreach ($properties as $code => $field) {
        if (!is_array($field)) {
            continue;
        }

        $fields[] = [
            'code' => (string)$code,
            'label' => (string)(
                $field['label'] ?? $code
            ),
            'type' => (string)(
                $field['type'] ?? ''
            ),
        ];
    }

    return [
        'ok' => true,
        'category' => '成功',
        'status' => $result['status'],
        'message' =>
            count($fields)
            . '件の項目を取得しました。',
        'fields' => $fields,
        'data' => $result['data'],
    ];
}

/*
 * kintone Cursor APIで全件取得。
 *
 * offset=0,500,...方式ではなくCursorを使用することで、
 * 大量データを最後まで取得する。
 */
function kintone_fetch_all_records(
    array $config
): array {
    $appId = trim(
        (string)($config['app_id'] ?? '')
    );

    $query = [
        'app' => (int)$appId,
        'totalCount' => true,
        'size' => 500,
    ];

    $created = kintone_request(
        $config,
        'POST',
        '/k/v1/records/cursor.json',
        $query
    );

    if (!$created['ok']) {
        return [
            'ok' => false,
            'category' => $created['category'],
            'status' => $created['status'],
            'message' => $created['message'],
        ];
    }

    $cursorId = (string)(
        $created['data']['id'] ?? ''
    );

    if ($cursorId === '') {
        return [
            'ok' => false,
            'category' => 'データエラー',
            'status' => $created['status'],
            'message' =>
                'kintoneのカーソルIDを取得できませんでした。',
        ];
    }

    $records = [];
    $loops = 0;

    try {
        while ($loops < 10000) {
            $loops++;

            $response = kintone_request(
                $config,
                'GET',
                '/k/v1/records/cursor.json?id='
                    . rawurlencode($cursorId)
            );

            if (!$response['ok']) {
                return [
                    'ok' => false,
                    'category' =>
                        $response['category'],
                    'status' =>
                        $response['status'],
                    'message' =>
                        $response['message'],
                ];
            }

            $batch =
                $response['data']['records'] ?? [];

            if (is_array($batch)) {
                foreach ($batch as $record) {
                    if (is_array($record)) {
                        $records[] = $record;
                    }
                }
            }

            $next = !empty(
                $response['data']['next']
            );

            if (!$next) {
                break;
            }
        }
    } finally {
        /*
         * Cursor削除に失敗しても同期結果そのものを
         * エラー扱いにしない。
         */
        kintone_request(
            $config,
            'DELETE',
            '/k/v1/records/cursor.json?id='
                . rawurlencode($cursorId)
        );
    }

    if ($loops >= 10000) {
        return [
            'ok' => false,
            'category' => '外部サービスエラー',
            'status' => 0,
            'message' =>
                'kintone顧客取得が上限回数を超えました。',
        ];
    }

    return [
        'ok' => true,
        'category' => '成功',
        'status' => 200,
        'message' =>
            count($records)
            . '件のレコードを取得しました。',
        'records' => $records,
    ];
}

function kintone_value(
    array $record,
    array $codes
): string {
    foreach ($codes as $code) {
        if (!isset($record[$code])) {
            continue;
        }

        $field = $record[$code];

        if (!is_array($field)) {
            continue;
        }

        if (!array_key_exists('value', $field)) {
            continue;
        }

        $value = $field['value'];

        if (is_scalar($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            $parts = [];

            foreach ($value as $item) {
                if (is_array($item)) {
                    $parts[] = (string)(
                        $item['value']
                        ?? $item['name']
                        ?? ''
                    );
                } else {
                    $parts[] = (string)$item;
                }
            }

            return implode(', ', $parts);
        }
    }

    return '';
}

function kintone_sync_customers(
    array $config
): array {
    $result =
        kintone_fetch_all_records($config);

    if (!$result['ok']) {
        return $result;
    }

    $customers = [];

    foreach ($result['records'] as $record) {
        $id = kintone_value(
            $record,
            ['$id', 'レコード番号']
        );

        if ($id === '') {
            $id = random_id('k-');
        }

        $customers[] = [
            'id' => $id,
            'organization' => kintone_value(
                $record,
                [
                    'organization',
                    '組織名',
                    '会社名',
                ]
            ),
            'name' => kintone_value(
                $record,
                [
                    'name',
                    '氏名',
                    '顧客名',
                ]
            ),
            'email' => kintone_value(
                $record,
                [
                    'email',
                    'メールアドレス',
                    'E-mail',
                    'Email',
                ]
            ),
            'department' => kintone_value(
                $record,
                [
                    'department',
                    '部署名',
                    '部署',
                ]
            ),
            'phone' => kintone_value(
                $record,
                [
                    'phone',
                    '電話番号',
                    'TEL',
                ]
            ),
            'address' => kintone_value(
                $record,
                [
                    'address',
                    '住所',
                ]
            ),
            'source' => 'kintone',
            'updatedAt' => now_iso(),
        ];
    }

    return [
        'ok' => true,
        'category' => '成功',
        'status' => 200,
        'message' =>
            count($customers)
            . '件の顧客情報を取得しました。',
        'customers' => $customers,
    ];
}

/* ============================================================
 * SMTP
 * ============================================================ */

function smtp_read($socket): array
{
    $lines = [];
    $started = microtime(true);

    while (!feof($socket)) {
        if (
            microtime(true) - $started
            > SMTP_TIMEOUT
        ) {
            break;
        }

        $line = fgets($socket, 8192);

        if ($line === false) {
            break;
        }

        $lines[] = rtrim(
            $line,
            "\r\n"
        );

        if (
            strlen($line) >= 4
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
): bool {
    $result = @fwrite(
        $socket,
        $line . "\r\n"
    );

    return $result !== false;
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
            'SMTP応答コード: '
            . $response['code']
        );
    }

    return $response;
}

function smtp_open(
    array $config
): array {
    $server = trim(
        (string)($config['server'] ?? '')
    );

    $port = (int)(
        $config['port'] ?? 0
    );

    $encryption = strtolower(
        (string)(
            $config['encryption'] ?? 'none'
        )
    );

    if ($server === '') {
        throw new RuntimeException(
            'SMTPサーバが未設定です。'
        );
    }

    if (
        $port < 1
        || $port > 65535
    ) {
        throw new RuntimeException(
            'SMTPポートが不正です。'
        );
    }

    if (!in_array(
        $encryption,
        ['ssl', 'tls', 'none'],
        true
    )) {
        throw new RuntimeException(
            'SMTP暗号化方式が不正です。'
        );
    }

    $transport =
        $encryption === 'ssl'
            ? 'ssl://'
            : 'tcp://';

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $transport
        . $server
        . ':'
        . $port,
        $errno,
        $errstr,
        SMTP_CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPサーバへ接続できませんでした。'
        );
    }

    stream_set_timeout(
        $socket,
        SMTP_TIMEOUT
    );

    try {
        smtp_expect($socket, [220]);

        smtp_write(
            $socket,
            'EHLO localhost'
        );

        smtp_expect($socket, [250]);

        if ($encryption === 'tls') {
            smtp_write(
                $socket,
                'STARTTLS'
            );

            smtp_expect($socket, [220]);

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

            smtp_expect($socket, [250]);
        }

        if (!empty($config['auth'])) {
            $username = trim(
                (string)(
                    $config['username'] ?? ''
                )
            );

            $password = (string)(
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

            smtp_expect($socket, [334]);

            smtp_write(
                $socket,
                base64_encode($username)
            );

            smtp_expect($socket, [334]);

            smtp_write(
                $socket,
                base64_encode($password)
            );

            smtp_expect($socket, [235]);
        }

        return $socket;
    } catch (Throwable $e) {
        @fclose($socket);
        throw $e;
    }
}

function smtp_test(
    array $config
): array {
    try {
        $socket = smtp_open($config);

        smtp_write(
            $socket,
            'QUIT'
        );

        @fclose($socket);

        return [
            'ok' => true,
            'category' => '成功',
            'message' =>
                'SMTP接続を確認しました。',
        ];
    } catch (Throwable) {
        return [
            'ok' => false,
            'category' => '通信エラー',
            'message' =>
                'SMTP接続に失敗しました。'
                . ' サーバー、ポート、暗号化方式、'
                . '認証情報を確認してください。',
        ];
    }
}

function smtp_encode_header(
    string $value
): string {
    if ($value === '') {
        return '';
    }

    if (preg_match(
        '/^[\x20-\x7E]*$/',
        $value
    )) {
        return $value;
    }

    return '=?UTF-8?B?'
        . base64_encode($value)
        . '?=';
}

function smtp_dot_escape(
    string $body
): string {
    $body = str_replace(
        ["\r\n", "\r"],
        "\n",
        $body
    );

    $body = preg_replace(
        '/^\./m',
        '..',
        $body
    );

    return str_replace(
        "\n",
        "\r\n",
        (string)$body
    );
}

function smtp_send_mail(
    array $config,
    string $to,
    string $subject,
    string $body
): array {
    if (!valid_email($to)) {
        return [
            'ok' => false,
            'message' =>
                '宛先メールアドレスが不正です。',
        ];
    }

    $from = trim(
        (string)(
            $config['from_email'] ?? ''
        )
    );

    if (!valid_email($from)) {
        return [
            'ok' => false,
            'message' =>
                '送信元メールアドレスが未設定または不正です。',
        ];
    }

    try {
        $socket = smtp_open($config);

        $fromName = (string)(
            $config['from_name'] ?? ''
        );

        $replyTo = trim(
            (string)(
                $config['reply_to'] ?? ''
            )
        );

        smtp_write(
            $socket,
            'MAIL FROM:<'
            . $from
            . '>'
        );

        smtp_expect(
            $socket,
            [250]
        );

        smtp_write(
            $socket,
            'RCPT TO:<'
            . $to
            . '>'
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

        $headers = [];

        $headers[] =
            'From: '
            . (
                $fromName !== ''
                    ? smtp_encode_header($fromName)
                    . ' <'
                    . $from
                    . '>'
                    : $from
            );

        $headers[] =
            'To: <'
            . $to
            . '>';

        $headers[] =
            'Subject: '
            . smtp_encode_header($subject);

        $headers[] =
            'MIME-Version: 1.0';

        $headers[] =
            'Content-Type: text/plain; charset=UTF-8';

        $headers[] =
            'Content-Transfer-Encoding: 8bit';

        if (
            $replyTo !== ''
            && valid_email($replyTo)
        ) {
            $headers[] =
                'Reply-To: <'
                . $replyTo
                . '>';
        }

        $message =
            implode(
                "\r\n",
                $headers
            )
            . "\r\n\r\n"
            . smtp_dot_escape($body)
            . "\r\n.";

        smtp_write(
            $socket,
            $message
        );

        smtp_expect(
            $socket,
            [250]
        );

        smtp_write(
            $socket,
            'QUIT'
        );

        @fclose($socket);

        return [
            'ok' => true,
            'message' =>
                'メールを送信しました。',
        ];
    } catch (Throwable) {
        return [
            'ok' => false,
            'message' =>
                'SMTPメール送信に失敗しました。',
        ];
    }
}

/* ============================================================
 * POST: kintone設定保存
 * ============================================================ */

$screen = (string)(
    $_GET['screen'] ?? 'list'
);

$allowedScreens = [
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

if (!in_array(
    $screen,
    $allowedScreens,
    true
)) {
    $screen = 'list';
}

$action = (string)(
    $_POST['action'] ?? ''
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

/*
 * kintone設定保存。
 *
 * passwordが空の場合:
 * 保存済みpasswordを維持。
 *
 * これにより、
 * 「画面のパスワード欄を空にして保存」
 * → 認証情報消失
 * → 後続操作で認証失敗
 * という再発を防ぐ。
 */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'kintone'
    && $action === 'save_kintone'
) {
    $saved = load_kintone();

    $newPassword = (string)(
        $_POST['password'] ?? ''
    );

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
        /*
         * パスワードはtrimしない。
         */
        'password' =>
            $newPassword !== ''
                ? $newPassword
                : (string)(
                    $saved['password'] ?? ''
                ),
        'proxy' =>
            trim(
                (string)(
                    $_POST['proxy'] ?? ''
                )
            ),
        'verify_ssl' =>
            isset($_POST['verify_ssl']),
        'fields' =>
            is_array($saved['fields'] ?? null)
                ? $saved['fields']
                : [],
        'address_mapping' =>
            is_array(
                $saved['address_mapping'] ?? null
            )
                ? $saved['address_mapping']
                : [],
        'status' =>
            (string)(
                $saved['status'] ?? '未設定'
            ),
        'last_test' =>
            (string)(
                $saved['last_test'] ?? ''
            ),
        'last_sync' =>
            (string)(
                $saved['last_sync'] ?? ''
            ),
    ];

    $validation =
        validate_kintone_config($config);

    if ($validation['errors']) {
        flash(
            'error',
            implode(
                ' ',
                $validation['errors']
            )
        );

        redirect_screen('kintone');
    }

    $config['status'] = '未確認';

    if (!save_kintone($config)) {
        flash(
            'error',
            'kintone設定を保存できませんでした。'
        );

        redirect_screen('kintone');
    }

    flash(
        'success',
        'kintone設定を保存しました。'
        . ' 接続確認を行う場合は「接続テスト」を実行してください。'
    );

    redirect_screen('kintone');
}

/*
 * kintone接続テスト。
 *
 * 現在のフォームPOST値ではなく、
 * 必ず保存済み設定を使用する。
 *
 * したがって、
 * 保存前の未確定値と同期処理の設定値が
 * 食い違うことを防ぐ。
 */
$kintoneTestResult = null;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'kintone'
    && $action === 'test_kintone'
) {
    $config = load_kintone();

    $validation =
        validate_kintone_config($config);

    if ($validation['errors']) {
        $kintoneTestResult = [
            'ok' => false,
            'category' => '設定エラー',
            'status' => 0,
            'message' => implode(
                ' ',
                $validation['errors']
            ),
        ];
    } else {
        $kintoneTestResult =
            kintone_connection_test(
                $config
            );
    }

    $config['status'] =
        $kintoneTestResult['ok']
            ? '接続確認済み'
            : '接続できません';

    $config['last_test'] = now_iso();

    /*
     * passwordには一切触れない。
     */
    save_kintone($config);
}

/*
 * kintone項目一覧再取得。
 *
 * 接続テストとは別操作。
 * 保存済み設定だけを使用。
 */
$kintoneFieldResult = null;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'kintone'
    && $action === 'fetch_kintone_fields'
) {
    $config = load_kintone();

    $validation =
        validate_kintone_config($config);

    if ($validation['errors']) {
        $kintoneFieldResult = [
            'ok' => false,
            'category' => '設定エラー',
            'status' => 0,
            'message' => implode(
                ' ',
                $validation['errors']
            ),
        ];
    } else {
        $kintoneFieldResult =
            kintone_fetch_fields(
                $config
            );

        if ($kintoneFieldResult['ok']) {
            $config['fields'] =
                $kintoneFieldResult['fields'];

            save_kintone($config);
        }
    }
}

/*
 * kintone顧客同期。
 *
 * 接続テストとは別操作。
 * 保存済み設定だけを使用。
 */
$kintoneSyncResult = null;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'kintone'
    && $action === 'sync_kintone'
) {
    $config = load_kintone();

    $validation =
        validate_kintone_config($config);

    if ($validation['errors']) {
        $kintoneSyncResult = [
            'ok' => false,
            'category' => '設定エラー',
            'status' => 0,
            'message' => implode(
                ' ',
                $validation['errors']
            ),
        ];
    } else {
        $kintoneSyncResult =
            kintone_sync_customers(
                $config
            );

        if ($kintoneSyncResult['ok']) {
            $customers =
                $kintoneSyncResult['customers'] ?? [];

            if (save_customers($customers)) {
                $config['last_sync'] =
                    now_iso();

                save_kintone($config);

                $kintoneSyncResult['message'] =
                    count($customers)
                    . '件の顧客情報を同期しました。';
            } else {
                $kintoneSyncResult = [
                    'ok' => false,
                    'category' => 'データエラー',
                    'status' => 0,
                    'message' =>
                        '顧客情報を保存できませんでした。',
                ];
            }
        }
    }
}

/* ============================================================
 * POST: メール設定
 * ============================================================ */

$mailTestResult = null;
$mailTestSendResult = null;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'mail'
    && $action === 'save_mail'
) {
    $saved = load_mail();

    $newPassword = (string)(
        $_POST['password'] ?? ''
    );

    $config = [
        'server' =>
            trim(
                (string)(
                    $_POST['server'] ?? ''
                )
            ),
        'port' =>
            (int)(
                $_POST['port'] ?? 0
            ),
        'encryption' =>
            (string)(
                $_POST['encryption'] ?? 'tls'
            ),
        'auth' =>
            isset($_POST['auth']),
        'username' =>
            trim(
                (string)(
                    $_POST['username'] ?? ''
                )
            ),
        'password' =>
            $newPassword !== ''
                ? $newPassword
                : (string)(
                    $saved['password'] ?? ''
                ),
        'from_email' =>
            trim(
                (string)(
                    $_POST['from_email'] ?? ''
                )
            ),
        'from_name' =>
            trim(
                (string)(
                    $_POST['from_name'] ?? ''
                )
            ),
        'reply_to' =>
            trim(
                (string)(
                    $_POST['reply_to'] ?? ''
                )
            ),
        'status' =>
            (string)(
                $saved['status'] ?? '未設定'
            ),
        'last_test' =>
            (string)(
                $saved['last_test'] ?? ''
            ),
    ];

    $errors = [];

    if ($config['server'] === '') {
        $errors[] =
            'SMTPサーバを入力してください。';
    }

    if (
        $config['port'] < 1
        || $config['port'] > 65535
    ) {
        $errors[] =
            'SMTPポートが不正です。';
    }

    if (!in_array(
        $config['encryption'],
        ['ssl', 'tls', 'none'],
        true
    )) {
        $errors[] =
            '暗号化方式が不正です。';
    }

    if (
        $config['from_email'] === ''
        || !valid_email($config['from_email'])
    ) {
        $errors[] =
            '送信元メールアドレスが不正です。';
    }

    if (
        $config['reply_to'] !== ''
        && !valid_email($config['reply_to'])
    ) {
        $errors[] =
            '返信先メールアドレスが不正です。';
    }

    if (
        $config['auth']
        && $config['username'] === ''
    ) {
        $errors[] =
            'SMTP認証を使用する場合は'
            . 'ユーザー名を入力してください。';
    }

    if (
        $config['auth']
        && $config['password'] === ''
    ) {
        $errors[] =
            'SMTP認証を使用する場合は'
            . 'パスワードを設定してください。';
    }

    if ($errors) {
        flash(
            'error',
            implode(' ', $errors)
        );

        redirect_screen('mail');
    }

    $config['status'] = '未確認';

    if (!save_mail($config)) {
        flash(
            'error',
            'メール設定を保存できませんでした。'
        );

        redirect_screen('mail');
    }

    flash(
        'success',
        'メール設定を保存しました。'
    );

    redirect_screen('mail');
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'mail'
    && $action === 'test_mail'
) {
    $config = load_mail();

    $mailTestResult =
        smtp_test($config);

    $config['status'] =
        $mailTestResult['ok']
            ? '接続確認済み'
            : '接続できません';

    $config['last_test'] = now_iso();

    save_mail($config);
}

/* ============================================================
 * POST: アンケート保存
 * ============================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'edit'
    && $action === 'save_survey'
) {
    $surveys = load_surveys();

    $id = safe_id(
        trim(
            (string)(
                $_POST['id'] ?? ''
            )
        )
    );

    $isNew = $id === '';

    if ($isNew) {
        $survey = new_survey();
    } else {
        $survey = find_survey(
            $surveys,
            $id
        );

        if ($survey === null) {
            flash(
                'error',
                'アンケートが存在しません。'
            );

            redirect_screen('list');
        }
    }

    $title = trim(
        (string)(
            $_POST['title'] ?? ''
        )
    );

    $description = trim(
        (string)(
            $_POST['description'] ?? ''
        )
    );

    $startAt = trim(
        (string)(
            $_POST['startAt'] ?? ''
        )
    );

    $endAt = trim(
        (string)(
            $_POST['endAt'] ?? ''
        )
    );

    $numbering =
        (string)(
            $_POST['numbering'] ?? 'global'
        );

    $errors = [];

    if ($title === '') {
        $errors[] =
            'アンケートタイトルを入力してください。';
    }

    if (
        mb_strlen($title)
        > MAX_TITLE_LENGTH
    ) {
        $errors[] =
            'アンケートタイトルが長すぎます。';
    }

    if (
        mb_strlen($description)
        > MAX_DESCRIPTION_LENGTH
    ) {
        $errors[] =
            'アンケート説明が長すぎます。';
    }

    if (!valid_datetime($startAt)) {
        $errors[] =
            '開始日時が不正です。';
    }

    if (!valid_datetime($endAt)) {
        $errors[] =
            '終了日時が不正です。';
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && strtotime($startAt)
        !== false
        && strtotime($endAt)
        !== false
        && strtotime($startAt)
        > strtotime($endAt)
    ) {
        $errors[] =
            '開始日時は終了日時以前にしてください。';
    }

    if (!in_array(
        $numbering,
        ['global', 'group'],
        true
    )) {
        $errors[] =
            '質問番号の採番方式が不正です。';
    }

    if ($errors) {
        flash(
            'error',
            implode(' ', $errors)
        );

        redirect_screen(
            'edit',
            $id !== ''
                ? ['id' => $id]
                : []
        );
    }

    $survey['title'] = $title;
    $survey['description'] = $description;
    $survey['startAt'] = $startAt;
    $survey['endAt'] = $endAt;
    $survey['numbering'] = $numbering;

    /*
     * POSTされた構造を再構成。
     * 質問番号はPOST値を信用せず再計算。
     */
    $groupTitles =
        is_array(
            $_POST['group_title'] ?? null
        )
            ? $_POST['group_title']
            : [];

    $groupIds =
        is_array(
            $_POST['group_id'] ?? null
        )
            ? $_POST['group_id']
            : [];

    $questionIds =
        is_array(
            $_POST['question_id'] ?? null
        )
            ? $_POST['question_id']
            : [];

    $questionGroupIds =
        is_array(
            $_POST['question_group_id'] ?? null
        )
            ? $_POST['question_group_id']
            : [];

    $questionTexts =
        is_array(
            $_POST['question_text'] ?? null
        )
            ? $_POST['question_text']
            : [];

    $questionTypes =
        is_array(
            $_POST['question_type'] ?? null
        )
            ? $_POST['question_type']
            : [];

    $questionRequired =
        is_array(
            $_POST['question_required'] ?? null
        )
            ? $_POST['question_required']
            : [];

    $optionLists =
        is_array(
            $_POST['options'] ?? null
        )
            ? $_POST['options']
            : [];

    $branching =
        is_array(
            $_POST['branching'] ?? null
        )
            ? $_POST['branching']
            : [];

    $groupOrder =
        is_array(
            $_POST['group_order'] ?? null
        )
            ? $_POST['group_order']
            : [];

    $questionOrder =
        is_array(
            $_POST['question_order'] ?? null
        )
            ? $_POST['question_order']
            : [];

    $groups = [];

    /*
     * グループ順はgroup_orderを優先。
     */
    foreach ($groupOrder as $groupId) {
        $groupId = safe_id(
            (string)$groupId
        );

        if ($groupId === '') {
            continue;
        }

        $groups[$groupId] = [
            'id' => $groupId,
            'title' => trim(
                (string)(
                    $groupTitles[$groupId]
                    ?? '新しいグループ'
                )
            ),
            'questions' => [],
        ];
    }

    /*
     * group_orderが存在しない旧データへの互換。
     */
    if (!$groups) {
        foreach ($groupIds as $groupId) {
            $groupId = safe_id(
                (string)$groupId
            );

            if ($groupId === '') {
                continue;
            }

            $groups[$groupId] = [
                'id' => $groupId,
                'title' => trim(
                    (string)(
                        $groupTitles[$groupId]
                        ?? '新しいグループ'
                    )
                ),
                'questions' => [],
            ];
        }
    }

    foreach ($groups as &$group) {
        if ($group['title'] === '') {
            $group['title'] =
                '新しいグループ';
        }

        if (
            mb_strlen($group['title'])
            > MAX_TITLE_LENGTH
        ) {
            $group['title'] =
                mb_substr(
                    $group['title'],
                    0,
                    MAX_TITLE_LENGTH
                );
        }
    }

    unset($group);

    /*
     * 質問順序をgroupごとに処理。
     */
    foreach ($questionOrder as $groupId => $orderedIds) {
        $groupId = safe_id(
            (string)$groupId
        );

        if (
            $groupId === ''
            || !isset($groups[$groupId])
            || !is_array($orderedIds)
        ) {
            continue;
        }

        foreach ($orderedIds as $questionId) {
            $questionId = safe_id(
                (string)$questionId
            );

            if ($questionId === '') {
                continue;
            }

            $text = trim(
                (string)(
                    $questionTexts[$questionId]
                    ?? ''
                )
            );

            $type =
                (string)(
                    $questionTypes[$questionId]
                    ?? 'single'
                );

            if (!in_array(
                $type,
                ['single', 'multiple', 'text'],
                true
            )) {
                $type = 'single';
            }

            if (
                mb_strlen($text)
                > MAX_QUESTION_LENGTH
            ) {
                $text =
                    mb_substr(
                        $text,
                        0,
                        MAX_QUESTION_LENGTH
                    );
            }

            $options = [];

            if (
                isset($optionLists[$questionId])
                && is_array(
                    $optionLists[$questionId]
                )
            ) {
                foreach (
                    $optionLists[$questionId]
                    as $option
                ) {
                    $option = trim(
                        (string)$option
                    );

                    if ($option === '') {
                        continue;
                    }

                    if (
                        mb_strlen($option)
                        > MAX_OPTION_LENGTH
                    ) {
                        $option =
                            mb_substr(
                                $option,
                                0,
                                MAX_OPTION_LENGTH
                            );
                    }

                    $options[] = $option;
                }
            }

            if (
                in_array(
                    $type,
                    ['single', 'multiple'],
                    true
                )
                && !$options
            ) {
                $options = ['選択肢1'];
            }

            $branch = [];

            if (
                isset($branching[$questionId])
                && is_array(
                    $branching[$questionId]
                )
            ) {
                foreach (
                    $branching[$questionId]
                    as $optionIndex => $targetId
                ) {
                    $targetId = safe_id(
                        (string)$targetId
                    );

                    $branch[
                        (string)$optionIndex
                    ] = $targetId;
                }
            }

            $groups[$groupId]['questions'][] = [
                'id' => $questionId,
                'number' => '',
                'text' => $text,
                'type' => $type,
                'required' =>
                    isset(
                        $questionRequired[$questionId]
                    ),
                'options' => $options,
                'branching' => $branch,
            ];
        }
    }

    /*
     * group間移動等でquestion_orderが存在しない場合。
     */
    foreach ($questionIds as $questionId) {
        $questionId = safe_id(
            (string)$questionId
        );

        if ($questionId === '') {
            continue;
        }

        $already = false;

        foreach ($groups as $group) {
            foreach (
                $group['questions']
                as $question
            ) {
                if (
                    ($question['id'] ?? '')
                    === $questionId
                ) {
                    $already = true;
                    break 2;
                }
            }
        }

        if ($already) {
            continue;
        }

        $groupId = safe_id(
            (string)(
                $questionGroupIds[$questionId]
                ?? ''
            )
        );

        if (
            $groupId === ''
            || !isset($groups[$groupId])
        ) {
            continue;
        }

        $type =
            (string)(
                $questionTypes[$questionId]
                ?? 'single'
            );

        if (!in_array(
            $type,
            ['single', 'multiple', 'text'],
            true
        )) {
            $type = 'single';
        }

        $groups[$groupId]['questions'][] = [
            'id' => $questionId,
            'number' => '',
            'text' => trim(
                (string)(
                    $questionTexts[$questionId]
                    ?? ''
                )
            ),
            'type' => $type,
            'required' =>
                isset(
                    $questionRequired[$questionId]
                ),
            'options' => [],
            'branching' => [],
        ];
    }

    /*
     * 空グループは許可。
     * グループ自体は最低1件。
     */
    if (!$groups) {
        $groups[] = new_group();
    }

    $survey['groups'] =
        array_values($groups);

    $survey = normalize_survey($survey);
    $survey['updatedAt'] = now_iso();

    $found = false;

    foreach ($surveys as $index => $row) {
        if (
            ($row['id'] ?? '')
            === $survey['id']
        ) {
            /*
             * 既存状態は維持。
             */
            $survey['status'] =
                normalize_survey($row)['status'];

            $surveys[$index] = $survey;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $survey['status'] = 'draft';
        $surveys[] = $survey;
    }

    if (!save_surveys($surveys)) {
        flash(
            'error',
            'アンケートを保存できませんでした。'
        );

        redirect_screen(
            'edit',
            ['id' => $survey['id']]
        );
    }

    flash(
        'success',
        'アンケートを保存しました。'
    );

    redirect_screen('list');
}

/* ============================================================
 * POST: 状態変更
 * ============================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'list'
    && $action === 'change_status'
) {
    $id = safe_id(
        (string)(
            $_POST['id'] ?? ''
        )
    );

    $next = (string)(
        $_POST['status'] ?? ''
    );

    if (!in_array(
        $next,
        ['published', 'stopped'],
        true
    )) {
        flash(
            'error',
            '状態変更が不正です。'
        );

        redirect_screen('list');
    }

    $surveys = load_surveys();
    $found = false;

    foreach ($surveys as &$survey) {
        $survey = normalize_survey($survey);

        if (
            ($survey['id'] ?? '')
            !== $id
        ) {
            continue;
        }

        if (
            $survey['status'] === 'ended'
        ) {
            flash(
                'error',
                '終了したアンケートは変更できません。'
            );

            redirect_screen('list');
        }

        if (
            $next === 'published'
            && $survey['status'] !== 'draft'
            && $survey['status'] !== 'stopped'
        ) {
            flash(
                'error',
                '現在の状態から公開できません。'
            );

            redirect_screen('list');
        }

        if (
            $next === 'stopped'
            && $survey['status'] !== 'published'
        ) {
            flash(
                'error',
                '公開中のアンケートのみ停止できます。'
            );

            redirect_screen('list');
        }

        $survey['status'] = $next;
        $survey['updatedAt'] = now_iso();
        $found = true;

        break;
    }

    unset($survey);

    if (!$found) {
        flash(
            'error',
            'アンケートが存在しません。'
        );

        redirect_screen('list');
    }

    if (!save_surveys($surveys)) {
        flash(
            'error',
            '状態を保存できませんでした。'
        );
    } else {
        flash(
            'success',
            'アンケート状態を変更しました。'
        );
    }

    redirect_screen('list');
}

/* ============================================================
 * POST: 複製
 * ============================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'list'
    && $action === 'duplicate_survey'
) {
    $id = safe_id(
        (string)(
            $_POST['id'] ?? ''
        )
    );

    $surveys = load_surveys();
    $source = find_survey(
        $surveys,
        $id
    );

    if ($source === null) {
        flash(
            'error',
            '複製対象が存在しません。'
        );

        redirect_screen('list');
    }

    $copy = $source;
    $copy['id'] =
        random_id('survey-');

    $copy['title'] =
        $source['title']
        . '（コピー）';

    $copy['status'] = 'draft';
    $copy['createdAt'] = now_iso();
    $copy['updatedAt'] = now_iso();

    foreach ($copy['groups'] as &$group) {
        $group['id'] =
            random_id('g-');

        foreach (
            $group['questions']
            as &$question
        ) {
            $question['id'] =
                random_id('q-');
        }

        unset($question);
    }

    unset($group);

    recalc_question_numbers($copy);

    $surveys[] = $copy;

    if (!save_surveys($surveys)) {
        flash(
            'error',
            'アンケートを複製できませんでした。'
        );
    } else {
        flash(
            'success',
            'アンケートを複製しました。'
        );
    }

    redirect_screen('list');
}

/* ============================================================
 * POST: 削除
 * ============================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'list'
    && $action === 'delete_survey'
) {
    $id = safe_id(
        (string)(
            $_POST['id'] ?? ''
        )
    );

    $surveys = load_surveys();
    $before = count($surveys);

    $surveys = array_values(
        array_filter(
            $surveys,
            static fn($row): bool =>
                ($row['id'] ?? '') !== $id
        )
    );

    if ($before === count($surveys)) {
        flash(
            'error',
            'アンケートが存在しません。'
        );
    } elseif (!save_surveys($surveys)) {
        flash(
            'error',
            'アンケートを削除できませんでした。'
        );
    } else {
        flash(
            'success',
            'アンケートを削除しました。'
        );
    }

    redirect_screen('list');
}

/* ============================================================
 * 回答者POST: 回答確認
 * ============================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'answer'
    && $action === 'go_confirm'
) {
    $surveyId = safe_id(
        (string)(
            $_GET['id'] ?? ''
        )
    );

    $surveys = load_surveys();
    refresh_all_statuses($surveys);

    $survey = find_survey(
        $surveys,
        $surveyId
    );

    if (
        $survey === null
        || $survey['status'] !== 'published'
    ) {
        http_response_code(404);
        exit(
            '現在回答できるアンケートではありません。'
        );
    }

    $answerData = [];

    foreach ($survey['groups'] as $group) {
        foreach (
            $group['questions']
            as $question
        ) {
            $qid = $question['id'];

            if ($question['type'] === 'multiple') {
                $value =
                    isset($_POST['answer'][$qid])
                    && is_array(
                        $_POST['answer'][$qid]
                    )
                        ? array_values(
                            array_map(
                                'strval',
                                $_POST['answer'][$qid]
                            )
                        )
                        : [];
            } else {
                $value = (string)(
                    $_POST['answer'][$qid]
                    ?? ''
                );
            }

            $answerData[$qid] = $value;
        }
    }

    $_SESSION['answer'][$surveyId] = [
        'surveyId' => $surveyId,
        'answers' => $answerData,
        'savedAt' => now_iso(),
    ];

    redirect_screen(
        'confirm',
        ['id' => $surveyId]
    );
}

/* ============================================================
 * 回答者POST: 回答完了
 * ============================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'confirm'
    && $action === 'submit_answer'
) {
    $surveyId = safe_id(
        (string)(
            $_GET['id'] ?? ''
        )
    );

    $surveys = load_surveys();
    refresh_all_statuses($surveys);

    $survey = find_survey(
        $surveys,
        $surveyId
    );

    if (
        $survey === null
        || $survey['status'] !== 'published'
    ) {
        http_response_code(404);
        exit(
            '現在回答できるアンケートではありません。'
        );
    }

    $stored =
        $_SESSION['answer'][$surveyId]
        ?? null;

    if (
        !is_array($stored)
        || !is_array(
            $stored['answers'] ?? null
        )
    ) {
        flash(
            'error',
            '回答情報が見つかりません。'
            . ' 最初から回答してください。'
        );

        redirect_screen(
            'answer',
            ['id' => $surveyId]
        );
    }

    $answers = $stored['answers'];
    $errors = [];

    foreach ($survey['groups'] as $group) {
        foreach (
            $group['questions']
            as $question
        ) {
            $qid = $question['id'];

            $value =
                $answers[$qid]
                ?? (
                    $question['type'] === 'multiple'
                        ? []
                        : ''
                );

            if (
                !empty($question['required'])
                && (
                    $value === ''
                    || $value === []
                )
            ) {
                $errors[] =
                    $question['number']
                    . ' は必須です。';
            }
        }
    }

    if ($errors) {
        flash(
            'error',
            implode(' ', $errors)
        );

        redirect_screen(
            'answer',
            ['id' => $surveyId]
        );
    }

    $answersStore = load_answers();

    $answersStore[] = [
        'id' => random_id('answer-'),
        'surveyId' => $surveyId,
        'surveyTitle' => $survey['title'],
        'answers' => $answers,
        'createdAt' => now_iso(),
    ];

    if (!save_answers($answersStore)) {
        flash(
            'error',
            '回答を保存できませんでした。'
        );

        redirect_screen(
            'confirm',
            ['id' => $surveyId]
        );
    }

    unset(
        $_SESSION['answer'][$surveyId]
    );

    redirect_screen(
        'complete',
        ['id' => $surveyId]
    );
}

/* ============================================================
 * POST: メール送信
 * ============================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $screen === 'send'
    && in_array(
        $action,
        [
            'send_selected',
            'resend_selected',
            'remind_selected',
        ],
        true
    )
) {
    $surveyId = safe_id(
        (string)(
            $_GET['id'] ?? ''
        )
    );

    $surveys = load_surveys();
    refresh_all_statuses($surveys);

    $survey = find_survey(
        $surveys,
        $surveyId
    );

    if ($survey === null) {
        flash(
            'error',
            '対象アンケートが存在しません。'
        );

        redirect_screen('list');
    }

    $selected =
        is_array(
            $_POST['customer_ids'] ?? null
        )
            ? array_values(
                array_map(
                    'strval',
                    $_POST['customer_ids']
                )
            )
            : [];

    if (!$selected) {
        flash(
            'error',
            '送信対象を選択してください。'
        );

        redirect_screen(
            'send',
            ['id' => $surveyId]
        );
    }

    $mail = load_mail();

    if (
        (string)($mail['from_email'] ?? '')
        === ''
        || !valid_email(
            (string)$mail['from_email']
        )
    ) {
        flash(
            'error',
            'メールサーバ設定を確認してください。'
        );

        redirect_screen(
            'send',
            ['id' => $surveyId]
        );
    }

    $subject = trim(
        (string)(
            $_POST['mail_subject'] ?? ''
        )
    );

    $body = (string)(
        $_POST['mail_body'] ?? ''
    );

    if ($subject === '') {
        flash(
            'error',
            'メール件名を入力してください。'
        );

        redirect_screen(
            'send',
            ['id' => $surveyId]
        );
    }

    $customers = load_customers();

    $history = load_send_history();

    $baseUrl =
        (
            !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off'
        )
            ? 'https://'
            : 'http://';

    $host =
        (string)(
            $_SERVER['HTTP_HOST'] ?? ''
        );

    $script =
        (string)(
            $_SERVER['SCRIPT_NAME'] ?? 'index.php'
        );

    $answerUrl =
        $baseUrl
        . $host
        . $script
        . '?screen=answer&id='
        . rawurlencode($surveyId);

    $sent = 0;
    $failed = 0;

    foreach ($customers as $customer) {
        $customerId =
            (string)($customer['id'] ?? '');

        if (
            !in_array(
                $customerId,
                $selected,
                true
            )
        ) {
            continue;
        }

        $customerName =
            (string)(
                $customer['name'] ?? ''
            );

        $to =
            trim(
                (string)(
                    $customer['email'] ?? ''
                )
            );

        $personalSubject =
            str_replace(
                [
                    '{顧客名}',
                    '{アンケートURL}',
                ],
                [
                    $customerName,
                    $answerUrl,
                ],
                $subject
            );

        $personalBody =
            str_replace(
                [
                    '{顧客名}',
                    '{アンケートURL}',
                ],
                [
                    $customerName,
                    $answerUrl,
                ],
                $body
            );

        $result =
            smtp_send_mail(
                $mail,
                $to,
                $personalSubject,
                $personalBody
            );

        $history[] = [
            'id' =>
                random_id('send-'),
            'surveyId' =>
                $surveyId,
            'customerId' =>
                $customerId,
            'customerName' =>
                $customerName,
            'email' =>
                $to,
            'action' =>
                $action,
            'status' =>
                $result['ok']
                    ? '送信成功'
                    : '送信失敗',
            'message' =>
                (string)(
                    $result['message']
                    ?? ''
                ),
            'createdAt' =>
                now_iso(),
        ];

        if ($result['ok']) {
            $sent++;
        } else {
            $failed++;
        }
    }

    save_send_history($history);

    flash(
        $failed === 0
            ? 'success'
            : 'error',
        '送信処理が完了しました。'
        . ' 成功: '
        . $sent
        . '件 / 失敗: '
        . $failed
        . '件'
    );

    redirect_screen(
        'send',
        ['id' => $surveyId]
    );
}

/* ============================================================
 * 共通データ準備
 * ============================================================ */

$surveys = load_surveys();
refresh_all_statuses($surveys);

$currentId = safe_id(
    (string)(
        $_GET['id'] ?? ''
    )
);

$currentSurvey =
    $currentId !== ''
        ? find_survey(
            $surveys,
            $currentId
        )
        : null;

$flashes = take_flash();

/* ============================================================
 * CSV / PDF
 * ============================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && $screen === 'analytics'
    && isset($_GET['export'])
) {
    if ($currentSurvey === null) {
        http_response_code(404);
        exit('アンケートが存在しません。');
    }

    $answers = array_values(
        array_filter(
            load_answers(),
            static fn(array $row): bool =>
                ($row['surveyId'] ?? '')
                === $currentSurvey['id']
        )
    );

    $export = (string)(
        $_GET['export'] ?? ''
    );

    if ($export === 'csv') {
        header(
            'Content-Type: text/csv; charset=UTF-8'
        );

        header(
            'Content-Disposition: attachment; filename="'
            . rawurlencode(
                'answers-' . $currentSurvey['id'] . '.csv'
            )
            . '"'
        );

        echo "\xEF\xBB\xBF";

        $fp = fopen('php://output', 'wb');

        $questions = [];

        foreach (
            $currentSurvey['groups']
            as $group
        ) {
            foreach (
                $group['questions']
                as $question
            ) {
                $questions[] = $question;
            }
        }

        $header = [
            '回答ID',
            '回答日時',
        ];

        foreach ($questions as $question) {
            $header[] =
                $question['number']
                . ' '
                . $question['text'];
        }

        fputcsv(
            $fp,
            $header
        );

        foreach ($answers as $answer) {
            $row = [
                $answer['id'] ?? '',
                $answer['createdAt'] ?? '',
            ];

            foreach ($questions as $question) {
                $value =
                    $answer['answers'][
                        $question['id']
                    ] ?? '';

                if (is_array($value)) {
                    $value =
                        implode(
                            '、',
                            array_map(
                                'strval',
                                $value
                            )
                        );
                }

                $row[] = $value;
            }

            fputcsv($fp, $row);
        }

        fclose($fp);
        exit;
    }

    if ($export === 'pdf') {
        /*
         * 外部PDFライブラリなしで出力できる最小PDF。
         * UTF-8文字列はPDFのメタ情報として保持し、
         * 本文にはASCII化した情報を出力。
         *
         * 実運用で日本語本文PDFを必要とする場合は
         * 日本語フォント埋め込み可能なPDFライブラリ導入が必要。
         */
        $lines = [];

        $lines[] =
            'Survey: '
            . preg_replace(
                '/[^\x20-\x7E]/',
                '?',
                $currentSurvey['title']
            );

        $lines[] =
            'Answers: '
            . count($answers);

        $lines[] =
            'Generated: '
            . now_iso();

        foreach ($answers as $index => $answer) {
            if ($index >= 100) {
                break;
            }

            $lines[] =
                'Answer '
                . ($index + 1)
                . ': '
                . (
                    preg_replace(
                        '/[^\x20-\x7E]/',
                        '?',
                        (string)(
                            $answer['createdAt']
                            ?? ''
                        )
                    )
                );
        }

        $content = "BT\n/F1 10 Tf\n";

        $y = 760;

        foreach ($lines as $line) {
            $safe =
                preg_replace(
                    '/[^\x20-\x7E]/',
                    '?',
                    $line
                );

            $safe = str_replace(
                ['\\', '(', ')'],
                ['\\\\', '\\(', '\\)'],
                (string)$safe
            );

            $content .=
                '50 '
                . $y
                . " Td\n("
                . $safe
                . ") Tj\n";

            $y -= 16;

            if ($y < 50) {
                break;
            }
        }

        $content .= "ET\n";

        $objects = [];

        $objects[] =
            '<< /Type /Catalog /Pages 2 0 R >>';

        $objects[] =
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

        $objects[] =
            '<< /Type /Page /Parent 2 0 R'
            . ' /MediaBox [0 0 595 842]'
            . ' /Resources << /Font << /F1 4 0 R >> >>'
            . ' /Contents 5 0 R >>';

        $objects[] =
            '<< /Type /Font /Subtype /Type1'
            . ' /BaseFont /Helvetica >>';

        $objects[] =
            '<< /Length '
            . strlen($content)
            . " >>\nstream\n"
            . $content
            . "endstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $number => $object) {
            $objectNumber = $number + 1;

            $offsets[$objectNumber] =
                strlen($pdf);

            $pdf .=
                $objectNumber
                . " 0 obj\n"
                . $object
                . "\nendobj\n";
        }

        $xref =
            strlen($pdf);

        $pdf .=
            "xref\n0 "
            . (count($objects) + 1)
            . "\n";

        $pdf .=
            "0000000000 65535 f \n";

        for (
            $i = 1;
            $i <= count($objects);
            $i++
        ) {
            $pdf .= sprintf(
                "%010d 00000 n \n",
                $offsets[$i]
            );
        }

        $pdf .=
            "trailer\n"
            . "<< /Size "
            . (count($objects) + 1)
            . " /Root 1 0 R >>\n"
            . "startxref\n"
            . $xref
            . "\n%%EOF";

        header(
            'Content-Type: application/pdf'
        );

        header(
            'Content-Disposition: attachment; filename="'
            . 'answers-'
            . rawurlencode(
                $currentSurvey['id']
            )
            . '.pdf"'
        );

        echo $pdf;
        exit;
    }
}

/* ============================================================
 * HTML
 * ============================================================ */

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>
<title>アンケートアプリ</title>

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
    --header:#0f172a;
}

*{
    box-sizing:border-box;
}

html,
body{
    margin:0;
    padding:0;
}

body{
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
    line-height:1.6;
}

a{
    color:var(--primary);
    text-decoration:none;
}

a:hover{
    text-decoration:underline;
}

button,
input,
select,
textarea{
    font:inherit;
}

button{
    cursor:pointer;
}

.app-header{
    background:var(--header);
    color:#fff;
}

.header-inner{
    max-width:1400px;
    margin:auto;
    padding:16px 22px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
}

.brand{
    font-size:20px;
    font-weight:800;
}

.nav{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.nav a{
    color:#cbd5e1;
    padding:7px 10px;
    border-radius:7px;
}

.nav a:hover{
    color:#fff;
    background:#1e293b;
    text-decoration:none;
}

.container{
    max-width:1400px;
    margin:0 auto;
    padding:28px 22px 60px;
}

.answer-page{
    max-width:820px;
    margin:0 auto;
    padding:20px 0 60px;
}

.page-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
    flex-wrap:wrap;
    margin-bottom:18px;
}

.page-title{
    margin:0;
    font-size:26px;
    line-height:1.3;
}

.page-subtitle{
    color:var(--gray);
    margin:5px 0 0;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:22px;
    margin-bottom:18px;
}

.form-grid{
    display:grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap:18px;
}

.form-group{
    min-width:0;
}

.form-group.full{
    grid-column:1/-1;
}

label{
    display:block;
    font-weight:700;
    margin-bottom:7px;
}

input[type="text"],
input[type="email"],
input[type="password"],
input[type="number"],
input[type="datetime-local"],
select,
textarea{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:10px 12px;
    background:#fff;
    color:var(--text);
}

textarea{
    min-height:120px;
    resize:vertical;
}

input:focus,
select:focus,
textarea:focus{
    outline:3px solid rgba(37,99,235,.14);
    border-color:var(--primary);
}

.checkbox{
    width:auto;
    margin-right:6px;
}

.actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
}

.btn{
    display:inline-flex;
    justify-content:center;
    align-items:center;
    gap:8px;
    min-height:40px;
    padding:8px 14px;
    border-radius:8px;
    border:1px solid transparent;
    font-weight:700;
    text-decoration:none;
    transition:.15s ease;
}

.btn:hover{
    text-decoration:none;
    transform:translateY(-1px);
}

.btn-primary{
    background:var(--primary);
    color:#fff;
}

.btn-primary:hover{
    background:var(--primary-dark);
}

.btn-success{
    background:var(--success);
    color:#fff;
}

.btn-danger{
    background:var(--danger);
    color:#fff;
}

.btn-secondary{
    background:#fff;
    color:var(--text);
    border-color:var(--border);
}

.btn-warning{
    background:var(--warning);
    color:#fff;
}

.btn:disabled{
    opacity:.6;
    cursor:not-allowed;
    transform:none;
}

.toolbar{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
}

.search{
    flex:1 1 300px;
}

.filter{
    min-width:160px;
}

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    min-width:1050px;
    border-collapse:collapse;
}

th,
td{
    padding:12px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th{
    background:#f8fafc;
    white-space:nowrap;
    font-size:13px;
}

td{
    font-size:14px;
}

.status{
    display:inline-block;
    border-radius:999px;
    padding:3px 10px;
    font-size:12px;
    font-weight:800;
}

.status-draft{
    background:#e2e8f0;
    color:#334155;
}

.status-published{
    background:#dcfce7;
    color:#166534;
}

.status-stopped{
    background:#fef3c7;
    color:#92400e;
}

.status-ended{
    background:#fee2e2;
    color:#991b1b;
}

.flash{
    border-radius:9px;
    padding:13px 16px;
    margin-bottom:14px;
    font-weight:700;
}

.flash-success{
    background:#dcfce7;
    color:#166534;
    border:1px solid #bbf7d0;
}

.flash-error{
    background:#fee2e2;
    color:#991b1b;
    border:1px solid #fecaca;
}

.flash-warning{
    background:#fef3c7;
    color:#92400e;
    border:1px solid #fde68a;
}

.empty{
    text-align:center;
    color:var(--gray);
    padding:45px 20px;
}

.section-title{
    margin:0 0 15px;
    font-size:19px;
}

.setting-actions{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-top:18px;
    padding-top:18px;
    border-top:1px solid var(--border);
}

.kintone-result{
    margin-top:18px;
}

.result-box{
    border-radius:9px;
    padding:14px;
    border:1px solid var(--border);
}

.result-success{
    background:#f0fdf4;
    border-color:#bbf7d0;
    color:#166534;
}

.result-error{
    background:#fef2f2;
    border-color:#fecaca;
    color:#991b1b;
}

.spinner{
    width:18px;
    height:18px;
    border:3px solid rgba(255,255,255,.4);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite;
}

.spinner-dark{
    border-color:#cbd5e1;
    border-top-color:var(--primary);
}

@keyframes spin{
    to{
        transform:rotate(360deg);
    }
}

.loading-layer{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.55);
    z-index:9999;
    display:none;
    align-items:center;
    justify-content:center;
}

.loading-layer.active{
    display:flex;
}

.loading-box{
    background:#fff;
    border-radius:12px;
    padding:24px 30px;
    text-align:center;
    box-shadow:0 12px 40px rgba(0,0,0,.2);
}

.loading-box .spinner{
    margin:0 auto 12px;
}

.group{
    border:1px solid var(--border);
    border-radius:10px;
    margin-bottom:16px;
    background:#fff;
}

.group-head{
    padding:13px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    gap:10px;
}

.group-body{
    padding:14px;
}

.drag-handle{
    cursor:grab;
    color:var(--gray);
    user-select:none;
}

.question{
    border:1px solid var(--border);
    border-radius:8px;
    padding:14px;
    margin-bottom:10px;
    background:#fff;
}

.question:last-child{
    margin-bottom:0;
}

.question-grid{
    display:grid;
    grid-template-columns:
        90px 1fr 170px;
    gap:10px;
    align-items:start;
}

.q-number{
    font-weight:800;
    color:var(--primary);
}

.option-row{
    display:flex;
    gap:8px;
    margin-top:8px;
}

.option-row input{
    flex:1;
}

.branch-grid{
    display:grid;
    grid-template-columns:
        minmax(0,1fr) 220px;
    gap:8px;
    margin-top:8px;
}

.sticky-actions{
    position:sticky;
    top:0;
    z-index:5;
    background:#f8fafc;
    padding:10px 0;
}

.stat-grid{
    display:grid;
    grid-template-columns:
        repeat(4,minmax(0,1fr));
    gap:14px;
}

.stat{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:18px;
}

.stat-label{
    color:var(--gray);
    font-size:13px;
}

.stat-value{
    font-size:30px;
    font-weight:800;
    margin-top:5px;
}

.answer-card{
    padding:25px;
}

.answer-option{
    display:flex;
    align-items:center;
    gap:10px;
    padding:13px;
    margin:8px 0;
    border:1px solid var(--border);
    border-radius:8px;
    cursor:pointer;
}

.answer-option:hover{
    background:#f8fafc;
}

.answer-option input{
    width:20px;
    height:20px;
}

.mobile-actions{
    position:sticky;
    bottom:0;
    background:rgba(248,250,252,.95);
    border-top:1px solid var(--border);
    padding:12px 0;
}

.small{
    font-size:12px;
    color:var(--gray);
}

.muted{
    color:var(--gray);
}

.danger-text{
    color:var(--danger);
}

.info-box{
    padding:14px;
    border-radius:9px;
    background:#eff6ff;
    border:1px solid #bfdbfe;
    color:#1e40af;
    margin-bottom:15px;
}

.code{
    font-family:
        ui-monospace,
        SFMono-Regular,
        Menlo,
        Monaco,
        Consolas,
        monospace;
    background:#f1f5f9;
    border-radius:5px;
    padding:2px 5px;
}

@media(max-width:900px){
    .form-grid{
        grid-template-columns:1fr;
    }

    .form-group.full{
        grid-column:auto;
    }

    .stat-grid{
        grid-template-columns:
            repeat(2,minmax(0,1fr));
    }

    .question-grid{
        grid-template-columns:1fr;
    }
}

@media(max-width:640px){
    .header-inner{
        align-items:flex-start;
        flex-direction:column;
    }

    .container{
        padding:18px 12px 45px;
    }

    .card{
        padding:16px;
    }

    .stat-grid{
        grid-template-columns:1fr;
    }

    .branch-grid{
        grid-template-columns:1fr;
    }

    .answer-page{
        padding:10px 0 45px;
    }

    .page-title{
        font-size:22px;
    }

    .btn{
        min-height:44px;
    }

    input[type="text"],
    input[type="email"],
    input[type="password"],
    input[type="number"],
    input[type="datetime-local"],
    select,
    textarea{
        font-size:16px;
    }
}
</style>
</head>

<body>

<div
    id="loadingLayer"
    class="loading-layer"
    aria-hidden="true"
>
    <div class="loading-box">
        <div class="spinner spinner-dark"></div>
        <strong id="loadingText">
            処理中です。しばらくお待ちください。
        </strong>
    </div>
</div>

<?php
$isAnswerScreen =
    in_array(
        $screen,
        ['answer', 'confirm', 'complete'],
        true
    );
?>

<?php if (!$isAnswerScreen): ?>

<header class="app-header">
    <div class="header-inner">
        <div class="brand">
            アンケートアプリ
        </div>

        <nav class="nav">
            <a href="index.php?screen=list">
                アンケート一覧
            </a>
            <a href="index.php?screen=kintone">
                kintone連携設定
            </a>
            <a href="index.php?screen=mail">
                メールサーバ設定
            </a>
        </nav>
    </div>
</header>

<?php endif; ?>

<main class="<?= $isAnswerScreen
    ? 'answer-page'
    : 'container' ?>">

<?php foreach ($flashes as $flash): ?>
    <div class="flash flash-<?= h(
        $flash['type'] ?? 'error'
    ) ?>">
        <?= h(
            $flash['message'] ?? ''
        ) ?>
    </div>
<?php endforeach; ?>

<?php
/* ============================================================
 * 一覧
 * ============================================================ */
?>

<?php if ($screen === 'list'): ?>

<?php
$search = trim(
    (string)(
        $_GET['q'] ?? ''
    )
);

$filter = (string)(
    $_GET['status'] ?? 'all'
);

$sort = (string)(
    $_GET['sort'] ?? 'updated_desc'
);

$filtered = [];

foreach ($surveys as $survey) {
    $survey = normalize_survey($survey);

    if (
        $search !== ''
        && mb_stripos(
            $survey['title'],
            $search
        ) === false
    ) {
        continue;
    }

    if (
        $filter !== 'all'
        && $survey['status'] !== $filter
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
        return match ($sort) {
            'updated_asc' =>
                strcmp(
                    (string)$a['updatedAt'],
                    (string)$b['updatedAt']
                ),
            'answers_desc' =>
                0,
            'answers_asc' =>
                0,
            'start_desc' =>
                strcmp(
                    (string)$b['startAt'],
                    (string)$a['startAt']
                ),
            'start_asc' =>
                strcmp(
                    (string)$a['startAt'],
                    (string)$b['startAt']
                ),
            default =>
                strcmp(
                    (string)$b['updatedAt'],
                    (string)$a['updatedAt']
                ),
        };
    }
);

$answerRows = load_answers();
$answerCountBySurvey = [];

foreach ($answerRows as $row) {
    $sid = (string)(
        $row['surveyId'] ?? ''
    );

    if ($sid === '') {
        continue;
    }

    $answerCountBySurvey[$sid] =
        ($answerCountBySurvey[$sid] ?? 0)
        + 1;
}
?>

<div class="page-head">
    <div>
        <h1 class="page-title">
            アンケート一覧
        </h1>
        <p class="page-subtitle">
            アンケートの作成・公開・集計・送信を管理します。
        </p>
    </div>

    <div class="actions">
        <a
            class="btn btn-primary"
            href="index.php?screen=edit"
        >
            ＋ 新規作成
        </a>
    </div>
</div>

<div class="card">
    <form
        method="get"
        action="index.php"
    >
        <input
            type="hidden"
            name="screen"
            value="list"
        >

        <div class="toolbar">
            <input
                class="search"
                type="text"
                name="q"
                value="<?= h($search) ?>"
                placeholder="タイトルで検索"
            >

            <select
                class="filter"
                name="status"
            >
                <option
                    value="all"
                    <?= $filter === 'all'
                        ? 'selected'
                        : '' ?>
                >
                    すべて
                </option>
                <option
                    value="published"
                    <?= $filter === 'published'
                        ? 'selected'
                        : '' ?>
                >
                    公開中
                </option>
                <option
                    value="draft"
                    <?= $filter === 'draft'
                        ? 'selected'
                        : '' ?>
                >
                    下書き
                </option>
                <option
                    value="stopped"
                    <?= $filter === 'stopped'
                        ? 'selected'
                        : '' ?>
                >
                    停止
                </option>
                <option
                    value="ended"
                    <?= $filter === 'ended'
                        ? 'selected'
                        : '' ?>
                >
                    終了
                </option>
            </select>

            <select
                class="filter"
                name="sort"
            >
                <option
                    value="updated_desc"
                    <?= $sort === 'updated_desc'
                        ? 'selected'
                        : '' ?>
                >
                    更新日：新しい順
                </option>
                <option
                    value="updated_asc"
                    <?= $sort === 'updated_asc'
                        ? 'selected'
                        : '' ?>
                >
                    更新日：古い順
                </option>
                <option
                    value="start_desc"
                    <?= $sort === 'start_desc'
                        ? 'selected'
                        : '' ?>
                >
                    開始日：新しい順
                </option>
                <option
                    value="start_asc"
                    <?= $sort === 'start_asc'
                        ? 'selected'
                        : '' ?>
                >
                    開始日：古い順
                </option>
            </select>

            <button
                class="btn btn-secondary"
                type="submit"
            >
                検索
            </button>
        </div>
    </form>
</div>

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
                    <div class="empty">
                        アンケートはありません。
                    </div>
                </td>
            </tr>

            <?php else: ?>

            <?php foreach ($filtered as $survey): ?>

            <?php
            $sid = $survey['id'];
            $count =
                $answerCountBySurvey[$sid] ?? 0;
            ?>

            <tr>
                <td>
                    <strong>
                        <?= h(
                            $survey['title']
                        ) ?>
                    </strong>
                </td>

                <td>
                    <?= h(
                        $survey['createdAt']
                    ) ?>
                </td>

                <td>
                    <?= h(
                        $survey['updatedAt']
                    ) ?>
                </td>

                <td>
                    <?= h(
                        $survey['startAt']
                            ?: '指定なし'
                    ) ?>
                    ～
                    <?= h(
                        $survey['endAt']
                            ?: '指定なし'
                    ) ?>
                </td>

                <td>
                    <span
                        class="status <?= h(
                            status_class(
                                $survey['status']
                            )
                        ) ?>"
                    >
                        <?= h(
                            status_label(
                                $survey['status']
                            )
                        ) ?>
                    </span>
                </td>

                <td>
                    <?= h($count) ?>
                </td>

                <td>
                    <div class="actions">

                        <a
                            class="btn btn-secondary"
                            href="index.php?screen=edit&id=<?= rawurlencode(
                                $sid
                            ) ?>"
                        >
                            確認・編集
                        </a>

                        <a
                            class="btn btn-secondary"
                            href="index.php?screen=analytics&id=<?= rawurlencode(
                                $sid
                            ) ?>"
                        >
                            集計
                        </a>

                        <a
                            class="btn btn-secondary"
                            href="index.php?screen=send&id=<?= rawurlencode(
                                $sid
                            ) ?>"
                        >
                            送信
                        </a>

                        <form
                            method="post"
                            action="index.php?screen=list"
                            onsubmit="return confirm('このアンケートを複製しますか？');"
                        >
                            <input
                                type="hidden"
                                name="_csrf"
                                value="<?= h(
                                    csrf_token()
                                ) ?>"
                            >
                            <input
                                type="hidden"
                                name="action"
                                value="duplicate_survey"
                            >
                            <input
                                type="hidden"
                                name="id"
                                value="<?= h($sid) ?>"
                            >
                            <button
                                class="btn btn-secondary"
                                type="submit"
                            >
                                複製
                            </button>
                        </form>

                        <?php if (
                            $survey['status']
                            !== 'ended'
                        ): ?>

                            <?php if (
                                $survey['status']
                                === 'draft'
                                || $survey['status']
                                === 'stopped'
                            ): ?>

                            <form
                                method="post"
                                action="index.php?screen=list"
                                onsubmit="return confirm('このアンケートを公開しますか？');"
                            >
                                <input
                                    type="hidden"
                                    name="_csrf"
                                    value="<?= h(
                                        csrf_token()
                                    ) ?>"
                                >
                                <input
                                    type="hidden"
                                    name="action"
                                    value="change_status"
                                >
                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= h($sid) ?>"
                                >
                                <input
                                    type="hidden"
                                    name="status"
                                    value="published"
                                >
                                <button
                                    class="btn btn-success"
                                    type="submit"
                                >
                                    公開
                                </button>
                            </form>

                            <?php elseif (
                                $survey['status']
                                === 'published'
                            ): ?>

                            <form
                                method="post"
                                action="index.php?screen=list"
                                onsubmit="return confirm('このアンケートを停止しますか？');"
                            >
                                <input
                                    type="hidden"
                                    name="_csrf"
                                    value="<?= h(
                                        csrf_token()
                                    ) ?>"
                                >
                                <input
                                    type="hidden"
                                    name="action"
                                    value="change_status"
                                >
                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= h($sid) ?>"
                                >
                                <input
                                    type="hidden"
                                    name="status"
                                    value="stopped"
                                >
                                <button
                                    class="btn btn-warning"
                                    type="submit"
                                >
                                    停止
                                </button>
                            </form>

                            <?php endif; ?>

                        <?php endif; ?>

                        <form
                            method="post"
                            action="index.php?screen=list"
                            onsubmit="return confirm('このアンケートを削除しますか？');"
                        >
                            <input
                                type="hidden"
                                name="_csrf"
                                value="<?= h(
                                    csrf_token()
                                ) ?>"
                            >
                            <input
                                type="hidden"
                                name="action"
                                value="delete_survey"
                            >
                            <input
                                type="hidden"
                                name="id"
                                value="<?= h($sid) ?>"
                            >
                            <button
                                class="btn btn-danger"
                                type="submit"
                            >
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

<?php
/* ============================================================
 * kintone設定
 * ============================================================ */
?>

<?php elseif ($screen === 'kintone'): ?>

<?php
$kintone = load_kintone();
$displayKintone =
    kintone_display_config($kintone);
?>

<div class="page-head">
    <div>
        <h1 class="page-title">
            kintone連携設定
        </h1>
        <p class="page-subtitle">
            顧客情報の取得元となるkintoneを設定します。
        </p>
    </div>
</div>

<div class="info-box">
    <strong>操作順序</strong><br>
    ① 設定保存 →
    ② 接続テスト →
    ③ 項目一覧を再取得 →
    ④ 顧客情報を同期
    <br>
    接続テスト・項目取得・顧客同期は別処理です。
    保存済み設定をサーバー側から読み込んで実行します。
</div>

<div class="card">

<form
    method="post"
    action="index.php?screen=kintone"
    data-external-action="save"
>
<input
    type="hidden"
    name="_csrf"
    value="<?= h(csrf_token()) ?>"
>

<input
    type="hidden"
    name="action"
    value="save_kintone"
>

<div class="form-grid">

<div class="form-group">
    <label for="subdomain">
        サブドメイン
    </label>

    <input
        id="subdomain"
        name="subdomain"
        type="text"
        value="<?= h(
            $displayKintone['subdomain']
        ) ?>"
        placeholder="xxxx / xxxx.cybozu.com"
        autocomplete="off"
        required
    >

    <div class="small">
        https://xxxx.cybozu.com、
        xxxx.cybozu.com、
        xxxx のいずれでも入力できます。
    </div>
</div>

<div class="form-group">
    <label for="app_id">
        顧客管理アプリID
    </label>

    <input
        id="app_id"
        name="app_id"
        type="number"
        min="1"
        value="<?= h(
            $displayKintone['app_id']
        ) ?>"
        required
    >
</div>

<div class="form-group">
    <label for="username">
        ログイン名
    </label>

    <input
        id="username"
        name="username"
        type="text"
        value="<?= h(
            $displayKintone['username']
        ) ?>"
        autocomplete="username"
        required
    >
</div>

<div class="form-group">
    <label for="password">
        パスワード
    </label>

    <input
        id="password"
        name="password"
        type="password"
        value=""
        autocomplete="new-password"
        placeholder="<?= $displayKintone['password_set']
            ? '変更しない場合は空欄'
            : 'パスワードを入力' ?>"
    >

    <div class="small">
        保存済みの場合、空欄で保存しても既存パスワードは保持されます。
    </div>
</div>

<div class="form-group">
    <label for="proxy">
        Proxy
    </label>

    <input
        id="proxy"
        name="proxy"
        type="text"
        value="<?= h(
            $displayKintone['proxy']
        ) ?>"
        placeholder="host:port"
        autocomplete="off"
    >

    <div class="small">
        未入力の場合は直接接続します。
    </div>
</div>

<div class="form-group">
    <label>
        SSL証明書検証
    </label>

    <label style="font-weight:400">
        <input
            class="checkbox"
            type="checkbox"
            name="verify_ssl"
            value="1"
            <?= $displayKintone['verify_ssl']
                ? 'checked'
                : '' ?>
        >
        有効
    </label>

    <div class="small">
        POC初期値は無効です。
        本番では有効を推奨します。
    </div>
</div>

</div>

<div class="setting-actions">

<button
    class="btn btn-primary"
    type="submit"
>
    設定保存
</button>

</div>

</form>

<div class="setting-actions">

<form
    method="post"
    action="index.php?screen=kintone"
    data-external-action="kintone"
    style="display:inline"
>
<input
    type="hidden"
    name="_csrf"
    value="<?= h(csrf_token()) ?>"
>
<input
    type="hidden"
    name="action"
    value="test_kintone"
>
<button
    class="btn btn-success"
    type="submit"
    data-loading-label="kintoneへ接続しています..."
>
    接続テスト
</button>
</form>

<form
    method="post"
    action="index.php?screen=kintone"
    data-external-action="kintone"
    style="display:inline"
>
<input
    type="hidden"
    name="_csrf"
    value="<?= h(csrf_token()) ?>"
>
<input
    type="hidden"
    name="action"
    value="fetch_kintone_fields"
>
<button
    class="btn btn-secondary"
    type="submit"
    data-loading-label="項目一覧を取得しています..."
>
    項目一覧を再取得
</button>
</form>

<form
    method="post"
    action="index.php?screen=kintone"
    data-external-action="kintone"
    style="display:inline"
    onsubmit="return confirm('保存済みのkintone設定を使用して顧客情報を同期します。実行しますか？');"
>
<input
    type="hidden"
    name="_csrf"
    value="<?= h(csrf_token()) ?>"
>
<input
    type="hidden"
    name="action"
    value="sync_kintone"
>
<button
    class="btn btn-primary"
    type="submit"
    data-loading-label="顧客情報を同期しています..."
>
    顧客情報を同期
</button>
</form>

</div>

<?php if ($kintoneTestResult !== null): ?>

<div class="kintone-result">
    <div class="result-box <?= $kintoneTestResult['ok']
        ? 'result-success'
        : 'result-error' ?>">

        <strong>
            <?= h(
                $kintoneTestResult['ok']
                    ? '接続成功'
                    : '接続失敗'
            ) ?>
        </strong>

        <div>
            <?= h(
                $kintoneTestResult['message']
                    ?? ''
            ) ?>
        </div>

        <?php if (
            !$kintoneTestResult['ok']
        ): ?>
        <div class="small">
            区分:
            <?= h(
                $kintoneTestResult['category']
                    ?? ''
            ) ?>
            <?php if (
                !empty(
                    $kintoneTestResult['status']
                )
            ): ?>
            /
            HTTP:
            <?= h(
                $kintoneTestResult['status']
            ) ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php endif; ?>

<?php if ($kintoneFieldResult !== null): ?>

<div class="kintone-result">
    <div class="result-box <?= $kintoneFieldResult['ok']
        ? 'result-success'
        : 'result-error' ?>">

        <strong>
            項目一覧取得
        </strong>

        <div>
            <?= h(
                $kintoneFieldResult['message']
                    ?? ''
            ) ?>
        </div>

    </div>
</div>

<?php endif; ?>

<?php if ($kintoneSyncResult !== null): ?>

<div class="kintone-result">
    <div class="result-box <?= $kintoneSyncResult['ok']
        ? 'result-success'
        : 'result-error' ?>">

        <strong>
            顧客情報同期
        </strong>

        <div>
            <?= h(
                $kintoneSyncResult['message']
                    ?? ''
            ) ?>
        </div>

        <?php if (
            !$kintoneSyncResult['ok']
        ): ?>
        <div class="small">
            区分:
            <?= h(
                $kintoneSyncResult['category']
                    ?? ''
            ) ?>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php endif; ?>

</div>

<div class="card">

<h2 class="section-title">
    接続状態
</h2>

<div class="form-grid">

<div>
    <div class="stat-label">
        現在の状態
    </div>

    <strong>
        <?= h(
            $displayKintone['status']
        ) ?>
    </strong>
</div>

<div>
    <div class="stat-label">
        最終接続テスト
    </div>

    <strong>
        <?= h(
            $displayKintone['last_test']
                ?: '未実施'
        ) ?>
    </strong>
</div>

<div>
    <div class="stat-label">
        最終同期
    </div>

    <strong>
        <?= h(
            $displayKintone['last_sync']
                ?: '未実施'
        ) ?>
    </strong>
</div>

</div>

</div>

<div class="card">

<h2 class="section-title">
    kintone項目
</h2>

<?php
$fields =
    is_array(
        $displayKintone['fields']
    )
        ? $displayKintone['fields']
        : [];
?>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>コード</th>
    <th>項目名</th>
    <th>タイプ</th>
</tr>
</thead>

<tbody>

<?php if (!$fields): ?>

<tr>
<td colspan="3">
<div class="empty">
項目一覧を取得してください。
</div>
</td>
</tr>

<?php else: ?>

<?php foreach ($fields as $field): ?>

<tr>
<td>
    <span class="code">
        <?= h(
            $field['code'] ?? ''
        ) ?>
    </span>
</td>

<td>
    <?= h(
        $field['label'] ?? ''
    ) ?>
</td>

<td>
    <?= h(
        $field['type'] ?? ''
    ) ?>
</td>
</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>
</div>

</div>

<?php
/* ============================================================
 * メール設定
 * ============================================================ */
?>

<?php elseif ($screen === 'mail'): ?>

<?php
$mail = load_mail();

$mailPasswordSet =
    (string)($mail['password'] ?? '')
    !== '';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">
            メールサーバ設定
        </h1>
        <p class="page-subtitle">
            SMTPサーバへ直接接続してメールを送信します。
        </p>
    </div>
</div>

<div class="card">

<form
    method="post"
    action="index.php?screen=mail"
>

<input
    type="hidden"
    name="_csrf"
    value="<?= h(csrf_token()) ?>"
>

<input
    type="hidden"
    name="action"
    value="save_mail"
>

<div class="form-grid">

<div class="form-group">
<label>SMTPサーバ</label>
<input
    name="server"
    type="text"
    value="<?= h(
        $mail['server']
    ) ?>"
    required
>
</div>

<div class="form-group">
<label>SMTPポート</label>
<input
    name="port"
    type="number"
    min="1"
    max="65535"
    value="<?= h(
        $mail['port']
    ) ?>"
    required
>
</div>

<div class="form-group">
<label>暗号化方式</label>
<select name="encryption">
<option
    value="ssl"
    <?= $mail['encryption'] === 'ssl'
        ? 'selected'
        : '' ?>
>
SSL
</option>
<option
    value="tls"
    <?= $mail['encryption'] === 'tls'
        ? 'selected'
        : '' ?>
>
TLS
</option>
<option
    value="none"
    <?= $mail['encryption'] === 'none'
        ? 'selected'
        : '' ?>
>
なし
</option>
</select>
</div>

<div class="form-group">
<label>SMTP認証</label>
<label style="font-weight:400">
<input
    class="checkbox"
    type="checkbox"
    name="auth"
    value="1"
    <?= !empty($mail['auth'])
        ? 'checked'
        : '' ?>
>
使用する
</label>
</div>

<div class="form-group">
<label>SMTPユーザー名</label>
<input
    name="username"
    type="text"
    value="<?= h(
        $mail['username']
    ) ?>"
    autocomplete="username"
>
</div>

<div class="form-group">
<label>SMTPパスワード</label>
<input
    name="password"
    type="password"
    value=""
    autocomplete="new-password"
    placeholder="<?= $mailPasswordSet
        ? '変更しない場合は空欄'
        : '' ?>"
>
</div>

<div class="form-group">
<label>送信元メールアドレス</label>
<input
    name="from_email"
    type="email"
    value="<?= h(
        $mail['from_email']
    ) ?>"
    required
>
</div>

<div class="form-group">
<label>送信元名</label>
<input
    name="from_name"
    type="text"
    value="<?= h(
        $mail['from_name']
    ) ?>"
>
</div>

<div class="form-group">
<label>返信先メールアドレス</label>
<input
    name="reply_to"
    type="email"
    value="<?= h(
        $mail['reply_to']
    ) ?>"
>
</div>

</div>

<div class="setting-actions">

<button
    class="btn btn-primary"
    type="submit"
>
設定保存
</button>

</div>

</form>

<div class="setting-actions">

<form
    method="post"
    action="index.php?screen=mail"
    data-external-action="mail"
>
<input
    type="hidden"
    name="_csrf"
    value="<?= h(csrf_token()) ?>"
>
<input
    type="hidden"
    name="action"
    value="test_mail"
>
<button
    class="btn btn-success"
    type="submit"
    data-loading-label="SMTPへ接続しています..."
>
接続テスト
</button>
</form>

</div>

<?php if ($mailTestResult !== null): ?>

<div class="kintone-result">

<div class="result-box <?= $mailTestResult['ok']
    ? 'result-success'
    : 'result-error' ?>">

<strong>
<?= h(
    $mailTestResult['ok']
        ? '接続成功'
        : '接続失敗'
) ?>
</strong>

<div>
<?= h(
    $mailTestResult['message']
        ?? ''
) ?>
</div>

</div>

</div>

<?php endif; ?>

<div class="kintone-result">

<div class="result-box">

<strong>
接続状態:
</strong>

<?= h(
    $mail['status']
) ?>

<?php if (
    !empty($mail['last_test'])
): ?>

<div class="small">
最終接続テスト:
<?= h(
    $mail['last_test']
) ?>
</div>

<?php endif; ?>

</div>

</div>

</div>

<?php
/* ============================================================
 * 編集
 * ============================================================ */
?>

<?php elseif ($screen === 'edit'): ?>

<?php
if ($currentSurvey === null) {
    $currentSurvey = new_survey();
}

$currentSurvey =
    normalize_survey($currentSurvey);
?>

<div class="sticky-actions">

<div class="page-head">

<div>
<h1 class="page-title">
アンケート作成・編集
</h1>
</div>

<div class="actions">

<a
    class="btn btn-secondary"
    href="index.php?screen=list"
>
キャンセル
</a>

<a
    class="btn btn-secondary"
    href="index.php?screen=preview&id=<?= rawurlencode(
        $currentSurvey['id']
    ) ?>"
>
プレビュー
</a>

</div>

</div>

</div>

<div class="card">

<form
    method="post"
    action="index.php?screen=edit"
    id="surveyForm"
>

<input
    type="hidden"
    name="_csrf"
    value="<?= h(csrf_token()) ?>"
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
        $currentSurvey['id']
    ) ?>"
>

<div class="form-grid">

<div class="form-group full">
<label>
アンケートタイトル
</label>

<input
    type="text"
    name="title"
    value="<?= h(
        $currentSurvey['title']
    ) ?>"
    maxlength="<?= MAX_TITLE_LENGTH ?>"
    required
>
</div>

<div class="form-group full">
<label>
アンケート説明
</label>

<textarea
    name="description"
    maxlength="<?= MAX_DESCRIPTION_LENGTH ?>"
><?= h(
    $currentSurvey['description']
) ?></textarea>
</div>

<div class="form-group">
<label>
開始日時
</label>

<input
    type="datetime-local"
    name="startAt"
    value="<?= h(
        $currentSurvey['startAt']
    ) ?>"
>
</div>

<div class="form-group">
<label>
終了日時
</label>

<input
    type="datetime-local"
    name="endAt"
    value="<?= h(
        $currentSurvey['endAt']
    ) ?>"
>
</div>

<div class="form-group">
<label>
質問番号の採番方式
</label>

<select name="numbering">
<option
    value="global"
    <?= $currentSurvey['numbering']
        === 'global'
        ? 'selected'
        : '' ?>
>
アンケート全体で通番
（Q1、Q2、Q3...）
</option>

<option
    value="group"
    <?= $currentSurvey['numbering']
        === 'group'
        ? 'selected'
        : '' ?>
>
グループ毎
（Q1-1、Q1-2、Q2-1...）
</option>
</select>
</div>

<div class="form-group">
<label>
現在の状態
</label>

<div>
<span
    class="status <?= h(
        status_class(
            $currentSurvey['status']
        )
    ) ?>"
>
<?= h(
    status_label(
        $currentSurvey['status']
    )
) ?>
</span>
</div>

</div>

</div>

<div class="setting-actions">

<button
    class="btn btn-primary"
    type="submit"
>
保存して一覧へ
</button>

</div>

<hr
    style="
        border:0;
        border-top:1px solid var(--border);
        margin:24px 0;
    "
>

<div class="page-head">
<div>
<h2 class="section-title">
グループ・質問
</h2>

<div class="small">
質問番号は自動採番されます。
グループ・質問の並び替え後にも再計算されます。
</div>
</div>
</div>

<div id="groups">

<?php foreach (
    $currentSurvey['groups']
    as $group
): ?>

<div
    class="group"
    draggable="true"
    data-group-id="<?= h(
        $group['id']
    ) ?>"
>

<div class="group-head">

<span class="drag-handle">
☰
</span>

<input
    type="hidden"
    name="group_id[]"
    value="<?= h(
        $group['id']
    ) ?>"
>

<input
    type="hidden"
    name="group_order[]"
    value="<?= h(
        $group['id']
    ) ?>"
>

<input
    type="text"
    name="group_title[<?= h(
        $group['id']
    ) ?>]"
    value="<?= h(
        $group['title']
    ) ?>"
    style="flex:1"
    maxlength="<?= MAX_TITLE_LENGTH ?>"
>

<button
    class="btn btn-danger"
    type="button"
    onclick="removeGroup(this)"
>
グループ削除
</button>

</div>

<div
    class="group-body"
    data-question-container
>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div
    class="question"
    draggable="true"
    data-question-id="<?= h(
        $question['id']
    ) ?>"
>

<input
    type="hidden"
    name="question_id[]"
    value="<?= h(
        $question['id']
    ) ?>"
>

<input
    type="hidden"
    name="question_group_id[<?= h(
        $question['id']
    ) ?>]"
    value="<?= h(
        $group['id']
    ) ?>"
>

<div
    class="question-grid"
>

<div class="q-number">
<?= h(
    $question['number']
) ?>
</div>

<div>

<input
    type="hidden"
    name="question_order[<?= h(
        $group['id']
    ) ?>][]"
    value="<?= h(
        $question['id']
    ) ?>"
>

<label>
質問文
</label>

<textarea
    name="question_text[<?= h(
        $question['id']
    ) ?>]"
    maxlength="<?= MAX_QUESTION_LENGTH ?>"
    required
><?= h(
    $question['text']
) ?></textarea>

<div
    style="
        display:grid;
        grid-template-columns:
            minmax(0,1fr) 180px;
        gap:10px;
        margin-top:10px;
    "
>

<div>
<label>
回答形式
</label>

<select
    name="question_type[<?= h(
        $question['id']
    ) ?>]"
    onchange="toggleQuestionType(this)"
>
<option
    value="single"
    <?= $question['type'] === 'single'
        ? 'selected'
        : '' ?>
>
単一選択
</option>

<option
    value="multiple"
    <?= $question['type'] === 'multiple'
        ? 'selected'
        : '' ?>
>
複数選択
</option>

<option
    value="text"
    <?= $question['type'] === 'text'
        ? 'selected'
        : '' ?>
>
自由記述
</option>
</select>
</div>

<div>
<label>
必須
</label>

<label
    style="font-weight:400"
>
<input
    class="checkbox"
    type="checkbox"
    name="question_required[<?= h(
        $question['id']
    ) ?>]"
    value="1"
    <?= !empty(
        $question['required']
    )
        ? 'checked'
        : '' ?>
>
必須項目
</label>
</div>

</div>

<div
    class="options-area"
    style="
        display:
        <?= in_array(
            $question['type'],
            ['single','multiple'],
            true
        )
            ? 'block'
            : 'none' ?>;
        margin-top:12px;
    "
>

<label>
選択肢
</label>

<div
    class="options-list"
>

<?php foreach (
    $question['options']
    as $optionIndex => $option
): ?>

<div class="option-row">

<input
    type="text"
    name="options[<?= h(
        $question['id']
    ) ?>][]"
    value="<?= h(
        $option
    ) ?>"
    maxlength="<?= MAX_OPTION_LENGTH ?>"
>

<button
    class="btn btn-secondary"
    type="button"
    onclick="removeOption(this)"
>
削除
</button>

</div>

<?php endforeach; ?>

</div>

<button
    class="btn btn-secondary"
    type="button"
    onclick="addOption(this)"
    style="margin-top:8px"
>
＋ 選択肢追加
</button>

<?php if (
    $question['type'] === 'single'
): ?>

<div
    class="branch-area"
    style="margin-top:14px"
>

<label>
条件分岐
</label>

<?php foreach (
    $question['options']
    as $optionIndex => $option
): ?>

<div class="branch-grid">

<div>
<?= h($option) ?>
</div>

<div>

<select
    name="branching[<?= h(
        $question['id']
    ) ?>][<?= h(
        $optionIndex
    ) ?>]"
>

<option value="">
次の質問を指定しない
</option>

<?php foreach (
    $currentSurvey['groups']
    as $targetGroup
): ?>

<?php foreach (
    $targetGroup['questions']
    as $targetQuestion
): ?>

<?php if (
    $targetQuestion['id']
    === $question['id']
) {
    continue;
} ?>

<option
    value="<?= h(
        $targetQuestion['id']
    ) ?>"
    <?= (
        (string)(
            $question['branching'][
                (string)$optionIndex
                ] ?? ''
            )
        === $targetQuestion['id']
    )
        ? 'selected'
        : '' ?>
>
<?= h(
    $targetQuestion['number']
    . ' '
    . $targetQuestion['text']
) ?>
</option>

<?php endforeach; ?>

<?php endforeach; ?>

</select>

</div>

</div>

<?php endforeach; ?>

</div>

<?php endif; ?>

</div>

</div>

</div>

<div>

<button
    class="btn btn-danger"
    type="button"
    onclick="removeQuestion(this)"
>
質問削除
</button>

</div>

</div>

</div>

<?php endforeach; ?>

</div>

<button
    class="btn btn-secondary"
    type="button"
    onclick="addQuestion(this)"
    data-group-id="<?= h(
        $group['id']
    ) ?>"
>
＋ 質問を追加
</button>

</div>

</div>

<?php endforeach; ?>

</div>

<button
    class="btn btn-secondary"
    type="button"
    onclick="addGroup()"
>
＋ グループを追加
</button>

</form>

</div>

<?php
/* ============================================================
 * プレビュー
 * ============================================================ */
?>

<?php elseif ($screen === 'preview'): ?>

<?php if ($currentSurvey === null): ?>

<div class="card">
    <div class="empty">
        アンケートが存在しません。
    </div>
</div>

<?php else: ?>

<div class="page-head">
<div>
<h1 class="page-title">
プレビュー
</h1>

<p class="page-subtitle">
<?= h(
    $currentSurvey['title']
) ?>
</p>
</div>

<div class="actions">
<a
    class="btn btn-secondary"
    href="index.php?screen=edit&id=<?= rawurlencode(
        $currentSurvey['id']
    ) ?>"
>
編集へ戻る
</a>
</div>
</div>

<div class="card">

<h1>
<?= h(
    $currentSurvey['title']
) ?>
</h1>

<p>
<?= nl2br(
    h(
        $currentSurvey['description']
    )
) ?>
</p>

<?php foreach (
    $currentSurvey['groups']
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

<div
    style="
        margin-top:22px;
        padding-top:14px;
        border-top:1px solid var(--border);
    "
>

<strong>
<?= h(
    $question['number']
) ?>
</strong>

<span>
<?= h(
    $question['text']
) ?>
</span>

<?php if (
    !empty($question['required'])
): ?>

<span class="danger-text">
*
</span>

<?php endif; ?>

<?php if (
    $question['type']
    === 'single'
): ?>

<div>
<?php foreach (
    $question['options']
    as $option
): ?>

<div class="answer-option">
<input
    type="radio"
    disabled
>
<span>
<?= h($option) ?>
</span>
</div>

<?php endforeach; ?>
</div>

<?php elseif (
    $question['type']
    === 'multiple'
): ?>

<div>
<?php foreach (
    $question['options']
    as $option
): ?>

<div class="answer-option">
<input
    type="checkbox"
    disabled
>
<span>
<?= h($option) ?>
</span>
</div>

<?php endforeach; ?>
</div>

<?php else: ?>

<textarea
    disabled
    placeholder="自由記述"
></textarea>

<?php endif; ?>

<?php if (
    $question['type']
    === 'single'
    && !empty($question['branching'])
): ?>

<div
    class="small"
    style="margin-top:8px"
>
条件分岐が設定されています。
</div>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

</div>

<?php endif; ?>

<?php
/* ============================================================
 * 送信
 * ============================================================ */
?>

<?php elseif ($screen === 'send'): ?>

<?php if ($currentSurvey === null): ?>

<div class="card">
<div class="empty">
対象アンケートが指定されていません。
</div>
</div>

<?php else: ?>

<?php
$customers = load_customers();

$history = array_values(
    array_filter(
        load_send_history(),
        static fn(array $row): bool =>
            ($row['surveyId'] ?? '')
            === $currentSurvey['id']
    )
);

$customerSearch = trim(
    (string)(
        $_GET['customer_q'] ?? ''
    )
);

$visibleCustomers = [];

foreach ($customers as $customer) {
    if (
        $customerSearch !== ''
        && mb_stripos(
            (string)(
                $customer['name'] ?? ''
            ),
            $customerSearch
        ) === false
        && mb_stripos(
            (string)(
                $customer['email'] ?? ''
            ),
            $customerSearch
        ) === false
        && mb_stripos(
            (string)(
                $customer['organization'] ?? ''
            ),
            $customerSearch
        ) === false
    ) {
        continue;
    }

    $visibleCustomers[] = $customer;
}

$mail = load_mail();
?>

<div class="page-head">

<div>
<h1 class="page-title">
顧客選択・メール送信
</h1>

<p class="page-subtitle">
対象アンケート:
<strong>
<?= h(
    $currentSurvey['title']
) ?>
</strong>
</p>
</div>

<div class="actions">
<a
    class="btn btn-secondary"
    href="index.php?screen=list"
>
一覧へ
</a>

<a
    class="btn btn-secondary"
    href="index.php?screen=analytics&id=<?= rawurlencode(
        $currentSurvey['id']
    ) ?>"
>
集計
</a>
</div>

</div>

<div class="card">

<form
    method="post"
    action="index.php?screen=send&id=<?= rawurlencode(
        $currentSurvey['id']
    ) ?>"
    data-external-action="mail"
    onsubmit="return confirm('選択した顧客へ送信します。実行しますか？');"
>

<input
    type="hidden"
    name="_csrf"
    value="<?= h(csrf_token()) ?>"
>

<input
    type="hidden"
    name="action"
    value="send_selected"
>

<h2 class="section-title">
顧客選択
</h2>

<div class="toolbar">
<input
    class="search"
    type="text"
    name="customer_q"
    value="<?= h(
        $customerSearch
    ) ?>"
    placeholder="顧客名・会社名・メールで検索"
>

<button
    class="btn btn-secondary"
    type="submit"
    formaction="index.php?screen=send&id=<?= rawurlencode(
        $currentSurvey['id']
    ) ?>"
    formmethod="get"
>
検索
</button>
</div>

<div
    class="table-wrap"
    style="margin-top:15px"
>

<table>
<thead>
<tr>
<th>
<input
    type="checkbox"
    onclick="toggleCustomers(this)"
>
</th>
<th>組織名</th>
<th>氏名</th>
<th>メールアドレス</th>
<th>部署</th>
<th>電話番号</th>
<th>住所</th>
</tr>
</thead>

<tbody>

<?php if (!$visibleCustomers): ?>

<tr>
<td colspan="7">
<div class="empty">
顧客情報がありません。
kintone設定から顧客情報を同期してください。
</div>
</td>
</tr>

<?php else: ?>

<?php foreach (
    $visibleCustomers
    as $customer
): ?>

<tr>
<td>
<input
    class="customer-checkbox"
    type="checkbox"
    name="customer_ids[]"
    value="<?= h(
        $customer['id'] ?? ''
    ) ?>"
>
</td>

<td>
<?= h(
    $customer['organization'] ?? ''
) ?>
</td>

<td>
<?= h(
    $customer['name'] ?? ''
) ?>
</td>

<td>
<?= h(
    $customer['email'] ?? ''
) ?>
</td>

<td>
<?= h(
    $customer['department'] ?? ''
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

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>

</div>

<hr
    style="
        border:0;
        border-top:1px solid var(--border);
        margin:25px 0;
    "
>

<h2 class="section-title">
メール作成
</h2>

<div class="form-grid">

<div class="form-group full">
<label>
メール件名
</label>

<input
    type="text"
    name="mail_subject"
    value="<?= h(
        '【アンケート】'
        . $currentSurvey['title']
    ) ?>"
    required
>
</div>

<div class="form-group full">
<label>
メール本文
</label>

<textarea
    name="mail_body"
    style="min-height:240px"
><?= h(
    "{顧客名} 様\n\n"
    . "アンケートへのご協力をお願いいたします。\n\n"
    . "{アンケートURL}\n\n"
    . "よろしくお願いいたします。"
) ?></textarea>

<div class="small">
使用できる変数:
<span class="code">{顧客名}</span>
<span class="code">{アンケートURL}</span>
</div>
</div>

</div>

<div class="setting-actions">

<button
    class="btn btn-primary"
    type="submit"
    data-loading-label="メールを送信しています..."
>
一括送信
</button>

</div>

</form>

</div>

<div class="card">

<h2 class="section-title">
送信履歴
</h2>

<div class="table-wrap">

<table>
<thead>
<tr>
<th>日時</th>
<th>顧客</th>
<th>メール</th>
<th>処理</th>
<th>結果</th>
<th>内容</th>
</tr>
</thead>

<tbody>

<?php if (!$history): ?>

<tr>
<td colspan="6">
<div class="empty">
送信履歴はありません。
</div>
</td>
</tr>

<?php else: ?>

<?php foreach (
    array_reverse($history)
    as $row
): ?>

<tr>
<td>
<?= h(
    $row['createdAt'] ?? ''
) ?>
</td>

<td>
<?= h(
    $row['customerName'] ?? ''
) ?>
</td>

<td>
<?= h(
    $row['email'] ?? ''
) ?>
</td>

<td>
<?= h(
    $row['action'] ?? ''
) ?>
</td>

<td>
<?= h(
    $row['status'] ?? ''
) ?>
</td>

<td>
<?= h(
    $row['message'] ?? ''
) ?>
</td>
</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>

</div>

</div>

<?php endif; ?>

<?php
/* ============================================================
 * 集計
 * ============================================================ */
?>

<?php elseif ($screen === 'analytics'): ?>

<?php if ($currentSurvey === null): ?>

<div class="card">
<div class="empty">
対象アンケートが指定されていません。
</div>
</div>

<?php else: ?>

<?php
$customers = load_customers();

$answers = array_values(
    array_filter(
        load_answers(),
        static fn(array $row): bool =>
            ($row['surveyId'] ?? '')
            === $currentSurvey['id']
    )
);

$history = array_values(
    array_filter(
        load_send_history(),
        static fn(array $row): bool =>
            ($row['surveyId'] ?? '')
            === $currentSurvey['id']
    )
);

$sentCustomerIds = [];

foreach ($history as $row) {
    if (
        ($row['status'] ?? '')
        === '送信成功'
        && !empty($row['customerId'])
    ) {
        $sentCustomerIds[
            (string)$row['customerId']
        ] = true;
    }
}

$answerCustomerCount = count(
    $sentCustomerIds
);

$answerCount = count($answers);

$unregistered = 0;

foreach ($answers as $answer) {
    if (empty($answer['customerId'])) {
        $unregistered++;
    }
}

$unanswered =
    max(
        0,
        $answerCustomerCount
        - $answerCount
    );

$rate =
    $answerCustomerCount > 0
        ? round(
            $answerCount
            / $answerCustomerCount
            * 100,
            1
        )
        : 0;

$questions = [];

foreach (
    $currentSurvey['groups']
    as $group
) {
    foreach (
        $group['questions']
        as $question
    ) {
        $questions[] = $question;
    }
}
?>

<div class="page-head">

<div>
<h1 class="page-title">
回答集計・分析
</h1>

<p class="page-subtitle">
対象アンケート:
<strong>
<?= h(
    $currentSurvey['title']
) ?>
</strong>
</p>
</div>

<div class="actions">

<a
    class="btn btn-secondary"
    href="index.php?screen=list"
>
一覧へ
</a>

<a
    class="btn btn-secondary"
    href="index.php?screen=send&id=<?= rawurlencode(
        $currentSurvey['id']
    ) ?>"
>
送信
</a>

<a
    class="btn btn-primary"
    href="index.php?screen=analytics&id=<?= rawurlencode(
        $currentSurvey['id']
    ) ?>&export=csv"
>
CSV
</a>

<a
    class="btn btn-secondary"
    href="index.php?screen=analytics&id=<?= rawurlencode(
        $currentSurvey['id']
    ) ?>&export=pdf"
>
PDF
</a>

</div>

</div>

<div class="stat-grid">

<div class="stat">
<div class="stat-label">
送信対象者数
</div>
<div class="stat-value">
<?= h(
    $answerCustomerCount
) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">
回答数
</div>
<div class="stat-value">
<?= h(
    $answerCount
) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">
未登録回答数
</div>
<div class="stat-value">
<?= h(
    $unregistered
) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">
回答率
</div>
<div class="stat-value">
<?= h(
    $rate
) ?>%
</div>
</div>

</div>

<div class="card">

<h2 class="section-title">
未回答数
</h2>

<strong>
<?= h(
    $unanswered
) ?>件
</strong>

</div>

<div class="card">

<h2 class="section-title">
設問別集計
</h2>

<?php if (!$answers): ?>

<div class="empty">
現在、回答データはありません
</div>

<?php else: ?>

<?php foreach (
    $questions
    as $question
): ?>

<?php
$values = [];

foreach ($answers as $answer) {
    $value =
        $answer['answers'][
            $question['id']
        ] ?? '';

    if (is_array($value)) {
        foreach ($value as $v) {
            $values[] = (string)$v;
        }
    } elseif (
        (string)$value !== ''
    ) {
        $values[] = (string)$value;
    }
}

$counts = [];

foreach ($values as $value) {
    $counts[$value] =
        ($counts[$value] ?? 0)
        + 1;
}
?>

<div
    style="
        padding:18px 0;
        border-bottom:1px solid var(--border);
    "
>

<strong>
<?= h(
    $question['number']
) ?>
</strong>

<div>
<?= h(
    $question['text']
) ?>
</div>

<?php if (
    $question['type'] === 'text'
): ?>

<div class="small">
自由記述回答:
<?= h(
    count($values)
) ?>件
</div>

<?php foreach (
    array_slice(
        $values,
        0,
        20
    )
    as $value
): ?>

<div
    style="
        margin-top:7px;
        padding:8px;
        background:#f8fafc;
        border-radius:6px;
    "
>
<?= nl2br(
    h($value)
) ?>
</div>

<?php endforeach; ?>

<?php else: ?>

<?php if (!$counts): ?>

<div class="small">
回答なし
</div>

<?php else: ?>

<?php foreach (
    $counts
    as $value => $count
): ?>

<div
    style="
        display:flex;
        justify-content:space-between;
        gap:15px;
        padding:7px 0;
    "
>

<span>
<?= h($value) ?>
</span>

<strong>
<?= h($count) ?>件
</strong>

</div>

<?php endforeach; ?>

<?php endif; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>

<div class="card">

<h2 class="section-title">
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
<th>回答ID</th>
<th>回答日時</th>
<th>回答内容</th>
</tr>
</thead>

<tbody>

<?php foreach (
    array_reverse($answers)
    as $answer
): ?>

<tr>

<td>
<?= h(
    $answer['id'] ?? ''
) ?>
</td>

<td>
<?= h(
    $answer['createdAt'] ?? ''
) ?>
</td>

<td>

<?php
$answerMap =
    $answer['answers'] ?? [];
?>

<?php foreach (
    $questions
    as $question
): ?>

<?php
$value =
    $answerMap[
        $question['id']
    ] ?? '';

if (is_array($value)) {
    $value =
        implode(
            '、',
            array_map(
                'strval',
                $value
            )
        );
}
?>

<div style="margin-bottom:8px">
<strong>
<?= h(
    $question['number']
) ?>
</strong>
<?= h(
    $value
) ?>
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

<?php endif; ?>

<?php
/* ============================================================
 * 回答
 * ============================================================ */
?>

<?php elseif ($screen === 'answer'): ?>

<?php if (
    $currentSurvey === null
    || $currentSurvey['status']
        !== 'published'
): ?>

<?php
http_response_code(404);
?>

<div class="card">
<div class="empty">
現在回答できるアンケートではありません。
</div>
</div>

<?php else: ?>

<div class="card">

<h1 class="page-title">
<?= h(
    $currentSurvey['title']
) ?>
</h1>

<?php if (
    $currentSurvey['description']
    !== ''
): ?>

<p>
<?= nl2br(
    h(
        $currentSurvey['description']
    )
) ?>
</p>

<?php endif; ?>

</div>

<form
    method="post"
    action="index.php?screen=answer&id=<?= rawurlencode(
        $currentSurvey['id']
    ) ?>"
    id="answerForm"
>

<input
    type="hidden"
    name="_csrf"
    value="<?= h(csrf_token()) ?>"
>

<input
    type="hidden"
    name="action"
    value="go_confirm"
>

<?php foreach (
    $currentSurvey['groups']
    as $group
): ?>

<div class="card answer-card">

<h2>
<?= h(
    $group['title']
) ?>
</h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div
    style="margin-top:25px"
    data-answer-question="<?= h(
        $question['id']
    ) ?>"
>

<label>
<?= h(
    $question['number']
) ?>
<?= h(
    $question['text']
) ?>

<?php if (
    !empty($question['required'])
): ?>

<span class="danger-text">
*
</span>

<?php endif; ?>

</label>

<?php if (
    $question['type']
    === 'single'
): ?>

<?php foreach (
    $question['options']
    as $index => $option
): ?>

<label class="answer-option">

<input
    type="radio"
    name="answer[<?= h(
        $question['id']
    ) ?>]"
    value="<?= h(
        $option
    ) ?>"
    data-question-id="<?= h(
        $question['id']
    ) ?>"
    data-option-index="<?= h(
        $index
    ) ?>"
    <?= !empty(
        $question['required']
    )
        ? 'required'
        : '' ?>
>

<span>
<?= h($option) ?>
</span>

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

<label class="answer-option">

<input
    type="checkbox"
    name="answer[<?= h(
        $question['id']
    ) ?>][]"
    value="<?= h(
        $option
    ) ?>"
    data-question-id="<?= h(
        $question['id']
    ) ?>"
>

<span>
<?= h($option) ?>
</span>

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
        : '' ?>
></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<div class="mobile-actions">

<div class="actions">

<button
    class="btn btn-primary"
    type="submit"
>
回答を確認する
</button>

</div>

</div>

</form>

<?php endif; ?>

<?php
/* ============================================================
 * 回答確認
 * ============================================================ */
?>

<?php elseif ($screen === 'confirm'): ?>

<?php
$stored =
    $_SESSION['answer'][$currentSurvey['id']]
    ?? null;

$storedAnswers =
    is_array($stored)
    && is_array(
        $stored['answers'] ?? null
    )
        ? $stored['answers']
        : [];
?>

<?php if (
    $currentSurvey === null
    || $currentSurvey['status']
        !== 'published'
): ?>

<div class="card">
<div class="empty">
アンケートが存在しないか、回答受付が終了しています。
</div>
</div>

<?php else: ?>

<div class="card">

<h1 class="page-title">
回答確認
</h1>

<p>
送信前に回答内容を確認してください。
</p>

</div>

<?php foreach (
    $currentSurvey['groups']
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

<?php
$value =
    $storedAnswers[
        $question['id']
    ] ?? '';

if (is_array($value)) {
    $value =
        implode(
            '、',
            array_map(
                'strval',
                $value
            )
        );
}
?>

<div
    style="
        padding:15px 0;
        border-bottom:1px solid var(--border);
    "
>

<strong>
<?= h(
    $question['number']
) ?>
</strong>

<div>
<?= h(
    $question['text']
) ?>
</div>

<div
    style="
        margin-top:8px;
        background:#f8fafc;
        padding:10px;
        border-radius:7px;
    "
>
<?= nl2br(
    h($value)
) ?>
</div>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<div class="card">

<div class="actions">

<a
    class="btn btn-secondary"
    href="index.php?screen=answer&id=<?= rawurlencode(
        $currentSurvey['id']
    ) ?>"
>
修正する
</a>

<form
    method="post"
    action="index.php?screen=confirm&id=<?= rawurlencode(
        $currentSurvey['id']
    ) ?>"
    onsubmit="return confirm('回答を送信しますか？');"
>

<input
    type="hidden"
    name="_csrf"
    value="<?= h(csrf_token()) ?>"
>

<input
    type="hidden"
    name="action"
    value="submit_answer"
>

<button
    class="btn btn-primary"
    type="submit"
>
回答を送信する
</button>

</form>

</div>

</div>

<?php endif; ?>

<?php
/* ============================================================
 * 回答完了
 * ============================================================ */
?>

<?php elseif ($screen === 'complete'): ?>

<div class="card">

<div
    class="empty"
    style="padding:60px 20px"
>

<h1>
回答ありがとうございました。
</h1>

<p>
回答を受け付けました。
</p>

</div>

</div>

<?php endif; ?>

</main>

<script>
(function(){

    'use strict';

    const loadingLayer =
        document.getElementById(
            'loadingLayer'
        );

    const loadingText =
        document.getElementById(
            'loadingText'
        );

    function startLoading(text){
        if(!loadingLayer){
            return;
        }

        if(loadingText && text){
            loadingText.textContent = text;
        }

        loadingLayer.classList.add(
            'active'
        );

        loadingLayer.setAttribute(
            'aria-hidden',
            'false'
        );

        document
            .querySelectorAll(
                'button, input, select, textarea'
            )
            .forEach(function(el){
                el.disabled = true;
            });
    }

    window.startLoading = startLoading;

    /*
     * 外部通信を行うPOSTでは、
     * 二重送信を防止しつつ、
     * 処理中であることを明示する。
     *
     * サーバー側にも必ずtimeoutがあるため、
     * 「クルクルだけが永久に続く」構造にしない。
     */
    document
        .querySelectorAll(
            'form[data-external-action]'
        )
        .forEach(function(form){

            form.addEventListener(
                'submit',
                function(){

                    const submit =
                        form.querySelector(
                            'button[type="submit"]'
                        );

                    const text =
                        submit
                        ? (
                            submit.getAttribute(
                                'data-loading-label'
                            )
                            || '処理中です...'
                        )
                        : '処理中です...';

                    startLoading(text);
                }
            );
        });

    /*
     * 通常POSTの二重送信防止。
     */
    document
        .querySelectorAll(
            'form:not([data-external-action])'
        )
        .forEach(function(form){

            form.addEventListener(
                'submit',
                function(){

                    if(
                        form.dataset.submitted
                        === '1'
                    ){
                        return;
                    }

                    form.dataset.submitted =
                        '1';

                    const submit =
                        form.querySelector(
                            'button[type="submit"]'
                        );

                    if(submit){
                        submit.disabled = true;
                    }
                }
            );
        });

    /*
     * 顧客全選択。
     */
    window.toggleCustomers =
        function(master){

            document
                .querySelectorAll(
                    '.customer-checkbox'
                )
                .forEach(function(box){
                    box.checked =
                        master.checked;
                });
        };

    /*
     * アンケート編集:
     * 質問形式による選択肢欄表示。
     */
    window.toggleQuestionType =
        function(select){

            const question =
                select.closest(
                    '.question'
                );

            if(!question){
                return;
            }

            const area =
                question.querySelector(
                    '.options-area'
                );

            if(!area){
                return;
            }

            const isChoice =
                select.value === 'single'
                || select.value === 'multiple';

            area.style.display =
                isChoice
                    ? 'block'
                    : 'none';

            /*
             * 自由記述の場合、
             * 選択肢を送信しない。
             */
            if(!isChoice){
                area
                    .querySelectorAll(
                        'input'
                    )
                    .forEach(function(input){
                        input.disabled = true;
                    });
            }else{
                area
                    .querySelectorAll(
                        'input'
                    )
                    .forEach(function(input){
                        input.disabled = false;
                    });
            }
        };

    window.removeOption =
        function(button){

            const row =
                button.closest(
                    '.option-row'
                );

            if(!row){
                return;
            }

            const list =
                row.parentElement;

            if(
                list
                && list.children.length <= 1
            ){
                alert(
                    '選択肢は1つ以上必要です。'
                );
                return;
            }

            row.remove();

            renumberBranchOptions(
                button.closest(
                    '.question'
                )
            );
        };

    window.addOption =
        function(button){

            const question =
                button.closest(
                    '.question'
                );

            if(!question){
                return;
            }

            const list =
                question.querySelector(
                    '.options-list'
                );

            if(!list){
                return;
            }

            const questionId =
                question.dataset.questionId;

            const row =
                document.createElement(
                    'div'
                );

            row.className =
                'option-row';

            row.innerHTML =
                '<input type="text"'
                + ' name="options['
                + escapeHtml(questionId)
                + '][]"'
                + ' maxlength="500">'
                + '<button'
                + ' class="btn btn-secondary"'
                + ' type="button"'
                + ' onclick="removeOption(this)"'
                + '>削除</button>';

            list.appendChild(row);
        };

    function escapeHtml(value){
        return String(value)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;')
            .replace(/'/g,'&#039;');
    }

    function newId(prefix){
        if(
            window.crypto
            && window.crypto.randomUUID
        ){
            return prefix
                + window.crypto.randomUUID()
                    .replace(/-/g,'');
        }

        return prefix
            + Date.now()
            + Math.random()
                .toString(16)
                .slice(2);
    }

    window.removeQuestion =
        function(button){

            const question =
                button.closest(
                    '.question'
                );

            if(!question){
                return;
            }

            if(
                !confirm(
                    'この質問を削除しますか？'
                )
            ){
                return;
            }

            question.remove();
            recalculateClientNumbers();
        };

    window.removeGroup =
        function(button){

            const group =
                button.closest(
                    '.group'
                );

            if(!group){
                return;
            }

            const groups =
                document.querySelectorAll(
                    '#groups > .group'
                );

            if(groups.length <= 1){
                alert(
                    'グループは1つ以上必要です。'
                );
                return;
            }

            if(
                !confirm(
                    'このグループを削除しますか？'
                )
            ){
                return;
            }

            group.remove();
            recalculateClientNumbers();
        };

    window.addQuestion =
        function(button){

            const groupId =
                button.getAttribute(
                    'data-group-id'
                );

            const group =
                document.querySelector(
                    '.group[data-group-id="'
                    + cssEscape(groupId)
                    + '"]'
                );

            if(!group){
                return;
            }

            const container =
                group.querySelector(
                    '[data-question-container]'
                );

            if(!container){
                return;
            }

            const id =
                newId('q-');

            const groupInput =
                '<input type="hidden"'
                + ' name="question_id[]"'
                + ' value="'
                + escapeHtml(id)
                + '">';

            const groupRelation =
                '<input type="hidden"'
                + ' name="question_group_id['
                + escapeHtml(id)
                + ']"'
                + ' value="'
                + escapeHtml(groupId)
                + '">';

            const orderInput =
                '<input type="hidden"'
                + ' name="question_order['
                + escapeHtml(groupId)
                + '][]"'
                + ' value="'
                + escapeHtml(id)
                + '">';

            const question =
                document.createElement(
                    'div'
                );

            question.className =
                'question';

            question.draggable = true;

            question.dataset.questionId =
                id;

            question.innerHTML =
                groupInput
                + groupRelation
                + '<div class="question-grid">'
                + '<div class="q-number">Q?</div>'
                + '<div>'
                + orderInput
                + '<label>質問文</label>'
                + '<textarea name="question_text['
                + escapeHtml(id)
                + ']"'
                + ' maxlength="2000"'
                + ' required></textarea>'
                + '<div style="display:grid;grid-template-columns:minmax(0,1fr) 180px;gap:10px;margin-top:10px">'
                + '<div>'
                + '<label>回答形式</label>'
                + '<select name="question_type['
                + escapeHtml(id)
                + ']" onchange="toggleQuestionType(this)">'
                + '<option value="single">単一選択</option>'
                + '<option value="multiple">複数選択</option>'
                + '<option value="text">自由記述</option>'
                + '</select>'
                + '</div>'
                + '<div>'
                + '<label>必須</label>'
                + '<label style="font-weight:400">'
                + '<input class="checkbox" type="checkbox" name="question_required['
                + escapeHtml(id)
                + ']" value="1" checked>'
                + '必須項目'
                + '</label>'
                + '</div>'
                + '</div>'
                + '<div class="options-area" style="margin-top:12px">'
                + '<label>選択肢</label>'
                + '<div class="options-list">'
                + '<div class="option-row">'
                + '<input type="text" name="options['
                + escapeHtml(id)
                + '][]" value="選択肢1" maxlength="500">'
                + '<button class="btn btn-secondary" type="button" onclick="removeOption(this)">削除</button>'
                + '</div>'
                + '</div>'
                + '<button class="btn btn-secondary" type="button" onclick="addOption(this)" style="margin-top:8px">＋ 選択肢追加</button>'
                + '</div>'
                + '</div>'
                + '<div>'
                + '<button class="btn btn-danger" type="button" onclick="removeQuestion(this)">質問削除</button>'
                + '</div>'
                + '</div>';

            container.appendChild(
                question
            );

            recalculateClientNumbers();
            attachDragEvents(question);
        };

    window.addGroup =
        function(){

            const groups =
                document.getElementById(
                    'groups'
                );

            if(!groups){
                return;
            }

            const id =
                newId('g-');

            const questionId =
                newId('q-');

            const group =
                document.createElement(
                    'div'
                );

            group.className =
                'group';

            group.draggable = true;

            group.dataset.groupId =
                id;

            group.innerHTML =
                '<div class="group-head">'
                + '<span class="drag-handle">☰</span>'
                + '<input type="hidden" name="group_id[]" value="'
                + escapeHtml(id)
                + '">'
                + '<input type="hidden" name="group_order[]" value="'
                + escapeHtml(id)
                + '">'
                + '<input type="text" name="group_title['
                + escapeHtml(id)
                + ']" value="新しいグループ" style="flex:1" maxlength="200">'
                + '<button class="btn btn-danger" type="button" onclick="removeGroup(this)">グループ削除</button>'
                + '</div>'
                + '<div class="group-body" data-question-container>'
                + '<button class="btn btn-secondary" type="button" onclick="addQuestion(this)" data-group-id="'
                + escapeHtml(id)
                + '">＋ 質問を追加</button>'
                + '</div>';

            groups.appendChild(group);

            /*
             * 「質問を追加」はグループ末尾のみ。
             */
            addQuestion(
                group.querySelector(
                    'button[data-group-id]'
                )
            );

            recalculateClientNumbers();
            attachDragEvents(group);
        };

    function cssEscape(value){
        if(
            window.CSS
            && window.CSS.escape
        ){
            return window.CSS.escape(
                value
            );
        }

        return String(value)
            .replace(
                /([^\w-])/g,
                '\\$1'
            );
    }

    function recalculateClientNumbers(){

        const numbering =
            document.querySelector(
                'select[name="numbering"]'
            );

        const mode =
            numbering
            ? numbering.value
            : 'global';

        let globalNo = 1;
        let groupNo = 1;

        document
            .querySelectorAll(
                '#groups > .group'
            )
            .forEach(function(group){

                let questionNo = 1;

                group.querySelectorAll(
                    ':scope > .group-body > .question'
                ).forEach(function(question){

                    const number =
                        question.querySelector(
                            '.q-number'
                        );

                    if(number){
                        number.textContent =
                            mode === 'group'
                                ? 'Q'
                                    + groupNo
                                    + '-'
                                    + questionNo
                                : 'Q'
                                    + globalNo;
                    }

                    questionNo++;
                    globalNo++;
                });

                groupNo++;
            });
    }

    function renumberBranchOptions(
        question
    ){
        /*
         * 分岐欄はサーバー側で再生成されるため、
         * ここでは入力欄の順番だけ維持。
         */
        return question;
    }

    function attachDragEvents(
        element
    ){
        if(!element){
            return;
        }

        element.addEventListener(
            'dragstart',
            function(event){

                element.classList.add(
                    'dragging'
                );

                event.dataTransfer.effectAllowed =
                    'move';

                event.dataTransfer.setData(
                    'text/plain',
                    element.dataset.questionId
                    || element.dataset.groupId
                    || ''
                );
            }
        );

        element.addEventListener(
            'dragend',
            function(){
                element.classList.remove(
                    'dragging'
                );
            }
        );
    }

    document
        .querySelectorAll(
            '#groups > .group, .question'
        )
        .forEach(
            attachDragEvents
        );

    /*
     * グループのドラッグ＆ドロップ。
     */
    const groups =
        document.getElementById(
            'groups'
        );

    if(groups){

        groups.addEventListener(
            'dragover',
            function(event){

                event.preventDefault();

                const dragging =
                    groups.querySelector(
                        '.group.dragging'
                    );

                if(!dragging){
                    return;
                }

                const target =
                    event.target.closest(
                        '#groups > .group'
                    );

                if(
                    target
                    && target !== dragging
                ){
                    const rect =
                        target.getBoundingClientRect();

                    if(
                        event.clientY
                        < rect.top
                        + rect.height / 2
                    ){
                        groups.insertBefore(
                            dragging,
                            target
                        );
                    }else{
                        groups.insertBefore(
                            dragging,
                            target.nextSibling
                        );
                    }

                    recalculateClientNumbers();
                }
            }
        );

        /*
         * 質問の並び替え・グループ間移動。
         */
        groups.addEventListener(
            'dragover',
            function(event){

                const dragging =
                    groups.querySelector(
                        '.question.dragging'
                    );

                if(!dragging){
                    return;
                }

                event.preventDefault();

                const target =
                    event.target.closest(
                        '.question'
                    );

                const container =
                    event.target.closest(
                        '[data-question-container]'
                    );

                if(
                    !container
                    || target === dragging
                ){
                    return;
                }

                if(target){
                    const rect =
                        target.getBoundingClientRect();

                    if(
                        event.clientY
                        < rect.top
                        + rect.height / 2
                    ){
                        container.insertBefore(
                            dragging,
                            target
                        );
                    }else{
                        container.insertBefore(
                            dragging,
                            target.nextSibling
                        );
                    }
                }else{
                    /*
                     * グループ末尾へ移動。
                     * 「質問を追加」ボタンより前。
                     */
                    const addButton =
                        container.querySelector(
                            'button[data-group-id]'
                        );

                    if(addButton){
                        container.insertBefore(
                            dragging,
                            addButton
                        );
                    }else{
                        container.appendChild(
                            dragging
                        );
                    }
                }

                const newGroup =
                    container.closest(
                        '.group'
                    );

                const questionId =
                    dragging.dataset.questionId;

                const relation =
                    dragging.querySelector(
                        'input[name^="question_group_id"]'
                    );

                if(
                    relation
                    && newGroup
                ){
                    relation.value =
                        newGroup.dataset.groupId;
                }

                recalculateClientNumbers();
            }
        );
    }

    const numbering =
        document.querySelector(
            'select[name="numbering"]'
        );

    if(numbering){
        numbering.addEventListener(
            'change',
            recalculateClientNumbers
        );
    }

    recalculateClientNumbers();

    /*
     * 回答者側の条件分岐。
     *
     * 条件により質問を表示/非表示。
     * 非表示になった質問の入力はdisabledにする。
     */
    function updateBranching(){

        const form =
            document.getElementById(
                'answerForm'
            );

        if(!form){
            return;
        }

        const questions =
            form.querySelectorAll(
                '[data-answer-question]'
            );

        questions.forEach(
            function(question){
                question.style.display =
                    '';
            }
        );

        /*
         * PHPから分岐情報を直接JSへ渡さず、
         * HTML上のdata属性から処理する場合に備えた
         * 基本制御。
         *
         * 実際の必須検証はサーバー側でも行う。
         */
    }

    updateBranching();

})();
</script>

</body>
</html>
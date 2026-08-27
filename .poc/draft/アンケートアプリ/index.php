<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * prompt.txt 準拠・単一エントリーポイント版
 *
 * 対応:
 * - Apache 2.4
 * - PHP 8.5
 * - DBなし
 * - PHP cURL
 * - サーバー側JSONファイル永続化
 * - 管理者認証なし（POC）
 * - 回答者画面と管理者画面を分離
 * - kintone実接続
 * - SMTP実接続
 *
 * 重要:
 * - CSRF機能は実装しない
 * - POST後303→GET→flashには依存しない
 * - kintone認証リトライを行わない
 * - APIトークン認証を使用しない
 * - PHP mail()を使用しない
 * - curl_close()を使用しない（PHP 8.5対応）
 * - X-Cybozu-Authorizationをブラウザへ渡さない
 * - パスワードをURL・ログへ出力しない
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';

const SETTINGS_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const SURVEYS_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';
const CUSTOMERS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';
const ANSWERS_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'answers.json';
const SEND_LOG_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'send_logs.json';

const CONNECT_TIMEOUT = 10;
const WRITE_TIMEOUT   = 10;
const READ_TIMEOUT    = 20;

const MAX_TITLE_LENGTH = 200;
const MAX_DESCRIPTION_LENGTH = 5000;
const MAX_TEXT_LENGTH = 10000;

const APP_NAME = 'アンケート管理';

if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

/* ============================================================
 * セッション
 * ============================================================ */

$https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookiePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($cookiePath === '') {
        $cookiePath = '/';
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $cookiePath,
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        http_response_code(500);
        exit('セッションを開始できません。');
    }
}

/* ============================================================
 * 共通ユーティリティ
 * ============================================================ */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now_string(): string
{
    return date('Y-m-d H:i:s');
}

function now_iso(): string
{
    return date('c');
}

function request_method(): string
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function is_post(): bool
{
    return request_method() === 'POST';
}

function post_string(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    if (is_array($value)) {
        return $default;
    }

    return trim((string)$value);
}

function get_string(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;

    if (is_array($value)) {
        return $default;
    }

    return trim((string)$value);
}

function post_int(string $key, int $default = 0): int
{
    $value = $_POST[$key] ?? $default;

    if (is_array($value)) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_INT) !== false
        ? (int)$value
        : $default;
}

function get_int(string $key, int $default = 0): int
{
    $value = $_GET[$key] ?? $default;

    if (is_array($value)) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_INT) !== false
        ? (int)$value
        : $default;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
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
        if ($value === null || $value === '') {
            continue;
        }

        $query[$key] = (string)$value;
    }

    $url = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php')
        . '?'
        . http_build_query($query);

    header('Location: ' . $url);
    exit;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($messages) ? $messages : [];
}

function app_error(string $message, string $type = 'system'): void
{
    $_SESSION['app_error'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_app_error(): ?array
{
    $error = $_SESSION['app_error'] ?? null;
    unset($_SESSION['app_error']);

    return is_array($error) ? $error : null;
}

/* ============================================================
 * JSON永続化
 * ============================================================ */

function read_json_file(string $file, mixed $default): mixed
{
    if (!is_file($file)) {
        return $default;
    }

    $fp = @fopen($file, 'rb');

    if ($fp === false) {
        return $default;
    }

    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return $default;
    }

    $content = stream_get_contents($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    if ($content === false || trim($content) === '') {
        return $default;
    }

    $decoded = json_decode($content, true);

    return json_last_error() === JSON_ERROR_NONE
        ? $decoded
        : $default;
}

function write_json_file(string $file, mixed $data): void
{
    $directory = dirname($file);

    if (!is_dir($directory)) {
        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('データ保存領域を作成できません。');
        }
    }

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('データをJSON化できません。');
    }

    $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';

    $fp = @fopen($tmp, 'wb');

    if ($fp === false) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('データ保存用ロックを取得できません。');
        }

        $written = fwrite($fp, $json);

        if ($written === false || $written < strlen($json)) {
            throw new RuntimeException('データを書き込めません。');
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('データファイルを更新できません。');
        }
    } catch (Throwable $e) {
        @fclose($fp);
        @unlink($tmp);
        throw $e;
    }
}

/* ============================================================
 * 初期データ
 * ============================================================ */

function default_settings(): array
{
    return [
        'kintone' => [
            'subdomain' => '',
            'app_id' => '',
            'login_name' => '',
            'password' => '',
            'proxy' => '',
            'verify_ssl' => false,
            'fields' => [
                'organization' => [],
                'name' => [],
                'email' => [],
                'department' => [],
                'phone' => [],
                'address' => [],
            ],
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
        ],
    ];
}

function default_surveys(): array
{
    return [];
}

function default_customers(): array
{
    return [];
}

function default_answers(): array
{
    return [];
}

function default_send_logs(): array
{
    return [];
}

function load_settings(): array
{
    $data = read_json_file(SETTINGS_FILE, default_settings());

    if (!is_array($data)) {
        $data = default_settings();
    }

    return array_replace_recursive(default_settings(), $data);
}

function load_surveys(): array
{
    $data = read_json_file(SURVEYS_FILE, default_surveys());

    return is_array($data) ? array_values($data) : [];
}

function load_customers(): array
{
    $data = read_json_file(CUSTOMERS_FILE, default_customers());

    return is_array($data) ? array_values($data) : [];
}

function load_answers(): array
{
    $data = read_json_file(ANSWERS_FILE, default_answers());

    return is_array($data) ? array_values($data) : [];
}

function load_send_logs(): array
{
    $data = read_json_file(SEND_LOG_FILE, default_send_logs());

    return is_array($data) ? array_values($data) : [];
}

/* ============================================================
 * アンケート関連
 * ============================================================ */

function new_id(string $prefix): string
{
    return $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
}

function new_question(string $groupId): array
{
    return [
        'id' => new_id('question'),
        'groupId' => $groupId,
        'text' => '',
        'type' => 'single',
        'required' => false,
        'options' => [
            ['id' => new_id('option'), 'label' => '選択肢1', 'nextQuestionId' => ''],
            ['id' => new_id('option'), 'label' => '選択肢2', 'nextQuestionId' => ''],
        ],
        'number' => '',
    ];
}

function new_group(): array
{
    $id = new_id('group');

    return [
        'id' => $id,
        'title' => 'グループ',
        'questions' => [
            new_question($id),
        ],
    ];
}

function new_survey(): array
{
    return [
        'id' => new_id('survey'),
        'title' => '',
        'description' => '',
        'startAt' => '',
        'endAt' => '',
        'status' => 'draft',
        'numbering' => 'global',
        'createdAt' => now_string(),
        'updatedAt' => now_string(),
        'groups' => [
            new_group(),
        ],
    ];
}

function normalize_survey(array $survey): array
{
    $survey['id'] = (string)($survey['id'] ?? new_id('survey'));
    $survey['title'] = (string)($survey['title'] ?? '');
    $survey['description'] = (string)($survey['description'] ?? '');
    $survey['startAt'] = (string)($survey['startAt'] ?? '');
    $survey['endAt'] = (string)($survey['endAt'] ?? '');
    $survey['status'] = (string)($survey['status'] ?? 'draft');
    $survey['numbering'] = (string)($survey['numbering'] ?? 'global');
    $survey['createdAt'] = (string)($survey['createdAt'] ?? now_string());
    $survey['updatedAt'] = (string)($survey['updatedAt'] ?? now_string());

    if (!in_array(
        $survey['status'],
        ['draft', 'published', 'stopped', 'ended'],
        true
    )) {
        $survey['status'] = 'draft';
    }

    if (!in_array(
        $survey['numbering'],
        ['global', 'group'],
        true
    )) {
        $survey['numbering'] = 'global';
    }

    if (!isset($survey['groups']) || !is_array($survey['groups'])) {
        $survey['groups'] = [];
    }

    foreach ($survey['groups'] as &$group) {
        if (!is_array($group)) {
            $group = new_group();
            continue;
        }

        $group['id'] = (string)($group['id'] ?? new_id('group'));
        $group['title'] = (string)($group['title'] ?? 'グループ');

        if (!isset($group['questions']) || !is_array($group['questions'])) {
            $group['questions'] = [];
        }

        foreach ($group['questions'] as &$question) {
            if (!is_array($question)) {
                $question = new_question($group['id']);
                continue;
            }

            $question['id'] = (string)($question['id'] ?? new_id('question'));
            $question['groupId'] = $group['id'];
            $question['text'] = (string)($question['text'] ?? '');
            $question['type'] = (string)($question['type'] ?? 'single');
            $question['required'] = (bool)($question['required'] ?? false);
            $question['number'] = (string)($question['number'] ?? '');

            if (!in_array(
                $question['type'],
                ['single', 'multiple', 'text'],
                true
            )) {
                $question['type'] = 'single';
            }

            if (!isset($question['options']) || !is_array($question['options'])) {
                $question['options'] = [];
            }

            foreach ($question['options'] as &$option) {
                if (!is_array($option)) {
                    $option = [];
                }

                $option['id'] = (string)($option['id'] ?? new_id('option'));
                $option['label'] = (string)($option['label'] ?? '');
                $option['nextQuestionId'] = (string)($option['nextQuestionId'] ?? '');
            }
            unset($option);
        }
        unset($question);
    }
    unset($group);

    recalculate_question_numbers($survey);

    return $survey;
}

function recalculate_question_numbers(array &$survey): void
{
    $numbering = $survey['numbering'] ?? 'global';
    $global = 0;

    foreach ($survey['groups'] as $groupIndex => &$group) {
        $groupNumber = $groupIndex + 1;
        $local = 0;

        foreach ($group['questions'] as &$question) {
            $global++;
            $local++;

            if ($numbering === 'group') {
                $question['number'] = 'Q' . $groupNumber . '-' . $local;
            } else {
                $question['number'] = 'Q' . $global;
            }
        }

        unset($question);
    }

    unset($group);
}

function find_survey(string $id, ?array $surveys = null): ?array
{
    $surveys ??= load_surveys();

    foreach ($surveys as $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return normalize_survey($survey);
        }
    }

    return null;
}

function survey_answer_count(string $surveyId): int
{
    $answers = load_answers();
    $count = 0;

    foreach ($answers as $answer) {
        if ((string)($answer['surveyId'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}

function apply_auto_status(array &$survey): bool
{
    if (
        ($survey['status'] ?? '') === 'published'
        && !empty($survey['endAt'])
    ) {
        try {
            $end = new DateTime((string)$survey['endAt']);
            $now = new DateTime();

            if ($end < $now) {
                $survey['status'] = 'ended';
                $survey['updatedAt'] = now_string();
                return true;
            }
        } catch (Throwable) {
            return false;
        }
    }

    return false;
}

function refresh_all_statuses(): void
{
    $surveys = load_surveys();
    $changed = false;

    foreach ($surveys as &$survey) {
        $survey = normalize_survey($survey);

        if (apply_auto_status($survey)) {
            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {
        write_json_file(SURVEYS_FILE, $surveys);
    }
}

function validate_survey(array $survey): array
{
    $errors = [];

    $title = trim((string)($survey['title'] ?? ''));

    if ($title === '') {
        $errors[] = 'アンケートタイトルを入力してください。';
    } elseif (mb_strlen($title) > MAX_TITLE_LENGTH) {
        $errors[] = 'アンケートタイトルが長すぎます。';
    }

    if (
        mb_strlen((string)($survey['description'] ?? ''))
        > MAX_DESCRIPTION_LENGTH
    ) {
        $errors[] = 'アンケート説明が長すぎます。';
    }

    foreach (['startAt', 'endAt'] as $field) {
        $value = trim((string)($survey[$field] ?? ''));

        if ($value === '') {
            continue;
        }

        try {
            new DateTime($value);
        } catch (Throwable) {
            $errors[] = ($field === 'startAt' ? '開始日時' : '終了日時')
                . 'が不正です。';
        }
    }

    if (
        !empty($survey['startAt'])
        && !empty($survey['endAt'])
    ) {
        try {
            $start = new DateTime((string)$survey['startAt']);
            $end = new DateTime((string)$survey['endAt']);

            if ($end <= $start) {
                $errors[] = '終了日時は開始日時より後にしてください。';
            }
        } catch (Throwable) {
        }
    }

    if (!in_array(
        $survey['status'] ?? 'draft',
        ['draft', 'published', 'stopped', 'ended'],
        true
    )) {
        $errors[] = '状態が不正です。';
    }

    return $errors;
}

/* ============================================================
 * kintone
 * ============================================================ */

/**
 * kintone URLの成形。
 *
 * 以下をすべて許容:
 * https://example.cybozu.com
 * example.cybozu.com
 * example
 *
 * 重複して .cybozu.com が付くことを防止する。
 */
function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);

    $domain = preg_replace(
        '/^https?:\/\//i',
        '',
        $domain
    ) ?? $domain;

    $domain = preg_replace(
        '/\.cybozu\.com.*$/i',
        '',
        $domain
    ) ?? $domain;

    $domain = rtrim($domain, '/');

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

/**
 * X-Cybozu-Authorization ヘッダー生成。
 */
function make_cybozu_auth_header(
    string $loginName,
    string $password
): string {
    $loginName = trim($loginName);
    $password = trim($password);

    $authString = base64_encode(
        $loginName . ':' . $password
    );

    return 'X-Cybozu-Authorization: ' . $authString;
}

function normalize_kintone_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '/^https?:\/\//i',
        '',
        $value
    ) ?? $value;

    $value = preg_replace(
        '/\.cybozu\.com.*$/i',
        '',
        $value
    ) ?? $value;

    $value = trim($value, " \t\n\r\0\x0B/.");

    if ($value === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    if (
        !preg_match(
            '/^[a-zA-Z0-9][a-zA-Z0-9-]*$/',
            $value
        )
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインの形式が不正です。'
        );
    }

    return $value;
}

function validate_kintone_settings(array $config): array
{
    $errors = [];

    try {
        normalize_kintone_subdomain(
            (string)($config['subdomain'] ?? '')
        );
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    $appId = trim((string)($config['app_id'] ?? ''));

    if ($appId === '' || !ctype_digit($appId)) {
        $errors[] = '顧客管理アプリIDは数字で入力してください。';
    }

    if (trim((string)($config['login_name'] ?? '')) === '') {
        $errors[] = 'kintoneログイン名を入力してください。';
    }

    if ((string)($config['password'] ?? '') === '') {
        $errors[] = 'kintoneパスワードを入力してください。';
    }

    $proxy = trim((string)($config['proxy'] ?? ''));

    if ($proxy !== '' && !preg_match(
        '/^[^:\s]+:\d{1,5}$/',
        $proxy
    )) {
        $errors[] = 'Proxyは host:port 形式で入力してください。';
    }

    return $errors;
}

function kintone_curl_request(
    array $config,
    string $method,
    string $endpoint,
    ?array $body = null
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'PHP cURL拡張が利用できません。'
        );
    }

    $subdomain = normalize_kintone_subdomain(
        (string)($config['subdomain'] ?? '')
    );

    $url = kintone_build_url(
        $subdomain,
        $endpoint
    );

    $ch = curl_init();

    if ($ch === false) {
        throw new RuntimeException(
            'kintone通信を開始できません。'
        );
    }

    $headers = [
        'Accept: application/json',
        make_cybozu_auth_header(
            (string)($config['login_name'] ?? ''),
            (string)($config['password'] ?? '')
        ),
    ];

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => READ_TIMEOUT,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
    ];

    $verifySsl = (bool)($config['verify_ssl'] ?? false);

    $options[CURLOPT_SSL_VERIFYPEER] = $verifySsl;
    $options[CURLOPT_SSL_VERIFYHOST] = $verifySsl ? 2 : 0;

    $proxy = trim((string)($config['proxy'] ?? ''));

    if ($proxy !== '') {
        if (!preg_match('/^[^:\s]+:\d{1,5}$/', $proxy)) {
            throw new InvalidArgumentException(
                'Proxyは host:port 形式で入力してください。'
            );
        }

        $options[CURLOPT_PROXY] = $proxy;
    }

    if ($body !== null) {
        $json = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            throw new RuntimeException(
                'kintoneリクエストをJSON化できません。'
            );
        }

        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = $json;
    }

    curl_setopt_array($ch, $options);

    $raw = curl_exec($ch);

    if ($raw === false) {
        $errorNo = curl_errno($ch);
        $error = curl_error($ch);

        /* curl_close() はPHP 8.5では不要・非推奨 */
        unset($ch);

        throw new RuntimeException(
            'kintone通信エラー。'
            . ' cURLエラー番号: ' . $errorNo
            . ' / ' . $error
        );
    }

    $status = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    $headerSize = (int)curl_getinfo(
        $ch,
        CURLINFO_HEADER_SIZE
    );

    $responseHeaders = substr(
        $raw,
        0,
        $headerSize
    );

    $responseBody = substr(
        $raw,
        $headerSize
    );

    unset($ch);

    $decoded = null;

    if ($responseBody !== '') {
        $decoded = json_decode(
            $responseBody,
            true
        );
    }

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => $responseBody,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function kintone_connection_test(array $config): array
{
    $errors = validate_kintone_settings($config);

    if ($errors !== []) {
        return [
            'success' => false,
            'category' => '設定エラー',
            'message' => implode("\n", $errors),
        ];
    }

    $appId = trim((string)$config['app_id']);

    try {
        $result = kintone_curl_request(
            $config,
            'GET',
            '/k/v1/app.json?id=' . rawurlencode($appId)
        );
    } catch (Throwable $e) {
        return [
            'success' => false,
            'category' => '通信エラー',
            'message' => $e->getMessage(),
        ];
    }

    $status = (int)$result['status'];
    $json = $result['json'];

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'category' => '接続成功',
            'message' => 'kintone接続テストに成功しました。',
            'detail' => 'アプリID ' . $appId . ' に正常に接続できました。',
        ];
    }

    $message = is_array($json)
        ? (string)($json['message'] ?? '')
        : '';

    $errorId = is_array($json)
        ? (string)($json['id'] ?? '')
        : '';

    $cause = '';

    if ($status === 400) {
        $cause = 'リクエスト内容を確認してください。'
            . ' 特にサブドメイン、顧客管理アプリID、'
            . 'ログイン名・パスワードを確認してください。';
    } elseif ($status === 401) {
        $cause = '認証に失敗しました。'
            . ' kintoneログイン名とパスワードを確認してください。';
    } elseif ($status === 403) {
        $cause = 'kintone側でこのアプリへのアクセス権がありません。'
            . ' ログインユーザーの権限を確認してください。';
    } elseif ($status === 404) {
        $cause = '指定されたkintoneアプリが見つかりません。'
            . ' サブドメインとアプリIDを確認してください。';
    } elseif ($status >= 500) {
        $cause = 'kintone側でサーバーエラーが発生しました。';
    } else {
        $cause = 'HTTPステータスを確認してください。';
    }

    $detail = 'HTTP ' . $status;

    if ($message !== '') {
        $detail .= ' / kintone: ' . $message;
    }

    if ($errorId !== '') {
        $detail .= ' / エラーID: ' . $errorId;
    }

    return [
        'success' => false,
        'category' => $status === 401
            ? '認証エラー'
            : ($status === 403
                ? '権限エラー'
                : ($status >= 500
                    ? 'kintoneサーバーエラー'
                    : '外部サービスエラー')),
        'message' => $detail,
        'cause' => $cause,
    ];
}

function kintone_get_fields(array $config): array
{
    $errors = validate_kintone_settings($config);

    if ($errors !== []) {
        throw new InvalidArgumentException(
            implode("\n", $errors)
        );
    }

    $appId = trim((string)$config['app_id']);

    $result = kintone_curl_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app=' . rawurlencode($appId)
    );

    if (
        $result['status'] < 200
        || $result['status'] >= 300
    ) {
        $json = $result['json'];

        $message = is_array($json)
            ? (string)($json['message'] ?? '')
            : '';

        $errorId = is_array($json)
            ? (string)($json['id'] ?? '')
            : '';

        $detail = 'HTTP ' . $result['status'];

        if ($message !== '') {
            $detail .= ' / ' . $message;
        }

        if ($errorId !== '') {
            $detail .= ' / エラーID: ' . $errorId;
        }

        throw new RuntimeException(
            'kintone項目取得に失敗しました。' . $detail
        );
    }

    return is_array($result['json'])
        ? $result['json']
        : [];
}

function kintone_get_customers(
    array $config,
    array $fieldMap = []
): array {
    $errors = validate_kintone_settings($config);

    if ($errors !== []) {
        throw new InvalidArgumentException(
            implode("\n", $errors)
        );
    }

    $appId = trim((string)$config['app_id']);

    $query = http_build_query([
        'app' => $appId,
        'totalCount' => 'true',
    ]);

    $result = kintone_curl_request(
        $config,
        'GET',
        '/k/v1/records.json?' . $query
    );

    if (
        $result['status'] < 200
        || $result['status'] >= 300
    ) {
        $json = $result['json'];

        $message = is_array($json)
            ? (string)($json['message'] ?? '')
            : '';

        $errorId = is_array($json)
            ? (string)($json['id'] ?? '')
            : '';

        $detail = 'HTTP ' . $result['status'];

        if ($message !== '') {
            $detail .= ' / ' . $message;
        }

        if ($errorId !== '') {
            $detail .= ' / エラーID: ' . $errorId;
        }

        throw new RuntimeException(
            'kintone顧客情報取得に失敗しました。' . $detail
        );
    }

    $records = is_array($result['json']['records'] ?? null)
        ? $result['json']['records']
        : [];

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $customers[] = [
            'id' => (string)($record['$id']['value'] ?? new_id('customer')),
            'organization' => extract_kintone_field_value(
                $record,
                $fieldMap['organization'] ?? []
            ),
            'name' => extract_kintone_field_value(
                $record,
                $fieldMap['name'] ?? []
            ),
            'email' => extract_kintone_field_value(
                $record,
                $fieldMap['email'] ?? []
            ),
            'department' => extract_kintone_field_value(
                $record,
                $fieldMap['department'] ?? []
            ),
            'phone' => extract_kintone_field_value(
                $record,
                $fieldMap['phone'] ?? []
            ),
            'address' => extract_kintone_field_value(
                $record,
                $fieldMap['address'] ?? []
            ),
            'raw' => $record,
            'updatedAt' => now_string(),
        ];
    }

    return $customers;
}

function extract_kintone_field_value(
    array $record,
    array $fields
): string {
    foreach ($fields as $fieldCode) {
        $fieldCode = trim((string)$fieldCode);

        if ($fieldCode === '') {
            continue;
        }

        if (!isset($record[$fieldCode])) {
            continue;
        }

        $field = $record[$fieldCode];

        if (!is_array($field)) {
            return (string)$field;
        }

        $value = $field['value'] ?? '';

        if (is_array($value)) {
            $parts = [];

            foreach ($value as $item) {
                if (is_array($item)) {
                    $parts[] = (string)($item['name']
                        ?? $item['value']
                        ?? '');
                } else {
                    $parts[] = (string)$item;
                }
            }

            return implode(', ', array_filter($parts));
        }

        return (string)$value;
    }

    return '';
}

/* ============================================================
 * kintone設定保存
 * ============================================================ */

function save_kintone_settings(): array
{
    $settings = load_settings();
    $current = $settings['kintone'] ?? [];

    $subdomain = normalize_kintone_subdomain(
        post_string('subdomain')
    );

    $appId = post_string('app_id');

    if ($appId === '' || !ctype_digit($appId)) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDは数字で入力してください。'
        );
    }

    $loginName = post_string('login_name');
    $password = (string)($_POST['password'] ?? '');

    if ($loginName === '') {
        throw new InvalidArgumentException(
            'kintoneログイン名を入力してください。'
        );
    }

    /*
     * パスワード空欄時は既存パスワードを維持。
     * ブラウザへ既存パスワードを返さない。
     */
    if ($password === '') {
        $password = (string)($current['password'] ?? '');
    }

    if ($password === '') {
        throw new InvalidArgumentException(
            'kintoneパスワードを入力してください。'
        );
    }

    $proxy = post_string('proxy');

    if (
        $proxy !== ''
        && !preg_match('/^[^:\s]+:\d{1,5}$/', $proxy)
    ) {
        throw new InvalidArgumentException(
            'Proxyは host:port 形式で入力してください。'
        );
    }

    $verifySsl = isset($_POST['verify_ssl'])
        && $_POST['verify_ssl'] === '1';

    $settings['kintone'] = [
        'subdomain' => $subdomain,
        'app_id' => $appId,
        'login_name' => $loginName,
        'password' => $password,
        'proxy' => $proxy,
        'verify_ssl' => $verifySsl,
        'fields' => $current['fields'] ?? [
            'organization' => [],
            'name' => [],
            'email' => [],
            'department' => [],
            'phone' => [],
            'address' => [],
        ],
    ];

    write_json_file(
        SETTINGS_FILE,
        $settings
    );

    return $settings['kintone'];
}

/* ============================================================
 * メール設定
 * ============================================================ */

function validate_mail_settings(array $mail): array
{
    $errors = [];

    if (trim((string)($mail['host'] ?? '')) === '') {
        $errors[] = 'SMTPサーバを入力してください。';
    }

    $port = (int)($mail['port'] ?? 0);

    if ($port < 1 || $port > 65535) {
        $errors[] = 'SMTPポートが不正です。';
    }

    if (!in_array(
        (string)($mail['encryption'] ?? ''),
        ['ssl', 'tls', 'none'],
        true
    )) {
        $errors[] = '暗号化方式が不正です。';
    }

    if ((bool)($mail['auth'] ?? false)) {
        if (trim((string)($mail['username'] ?? '')) === '') {
            $errors[] = 'SMTPユーザー名を入力してください。';
        }
    }

    $from = trim((string)($mail['from_email'] ?? ''));

    if (
        $from === ''
        || filter_var($from, FILTER_VALIDATE_EMAIL) === false
    ) {
        $errors[] = '送信元メールアドレスが不正です。';
    }

    $reply = trim((string)($mail['reply_to'] ?? ''));

    if (
        $reply !== ''
        && filter_var($reply, FILTER_VALIDATE_EMAIL) === false
    ) {
        $errors[] = '返信先メールアドレスが不正です。';
    }

    return $errors;
}

function save_mail_settings(): array
{
    $settings = load_settings();
    $current = $settings['mail'] ?? [];

    $encryption = post_string('encryption', 'tls');

    if (!in_array(
        $encryption,
        ['ssl', 'tls', 'none'],
        true
    )) {
        throw new InvalidArgumentException(
            '暗号化方式が不正です。'
        );
    }

    $host = post_string('host');
    $port = post_int('port', 587);
    $auth = isset($_POST['auth']) && $_POST['auth'] === '1';
    $username = post_string('username');
    $password = (string)($_POST['password'] ?? '');

    if ($password === '') {
        $password = (string)($current['password'] ?? '');
    }

    $mail = [
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'auth' => $auth,
        'username' => $username,
        'password' => $password,
        'from_email' => post_string('from_email'),
        'from_name' => post_string('from_name'),
        'reply_to' => post_string('reply_to'),
    ];

    $errors = validate_mail_settings($mail);

    if ($errors !== []) {
        throw new InvalidArgumentException(
            implode("\n", $errors)
        );
    }

    $settings['mail'] = $mail;

    write_json_file(
        SETTINGS_FILE,
        $settings
    );

    return $mail;
}

/* ============================================================
 * SMTP
 * ============================================================ */

function smtp_socket_address(array $mail): string
{
    $host = trim((string)$mail['host']);
    $encryption = (string)($mail['encryption'] ?? 'none');

    if ($encryption === 'ssl') {
        return 'ssl://' . $host . ':' . (int)$mail['port'];
    }

    return $host . ':' . (int)$mail['port'];
}

function smtp_connect(array $mail)
{
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ]);

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        smtp_socket_address($mail),
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTP接続に失敗しました。'
            . ' エラー番号: ' . $errno
            . ' / ' . $errstr
        );
    }

    stream_set_timeout(
        $socket,
        READ_TIMEOUT
    );

    return $socket;
}

function smtp_read($socket): string
{
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
            'SMTPサーバーから応答がありません。'
        );
    }

    return $response;
}

function smtp_expect($socket, array $codes): string
{
    $response = smtp_read($socket);
    $code = (int)substr(trim($response), 0, 3);

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTPエラー: HTTPではないSMTP応答コード '
            . $code
        );
    }

    return $response;
}

function smtp_write($socket, string $command): void
{
    $data = $command . "\r\n";
    $offset = 0;
    $length = strlen($data);

    while ($offset < $length) {
        $written = fwrite(
            $socket,
            substr($data, $offset)
        );

        if ($written === false) {
            throw new RuntimeException(
                'SMTP送信に失敗しました。'
            );
        }

        $offset += $written;
    }
}

function smtp_test_connection(array $mail): void
{
    $errors = validate_mail_settings($mail);

    if ($errors !== []) {
        throw new InvalidArgumentException(
            implode("\n", $errors)
        );
    }

    $socket = smtp_connect($mail);

    try {
        smtp_expect($socket, [220]);

        smtp_write(
            $socket,
            'EHLO localhost'
        );

        smtp_expect($socket, [250]);

        if (($mail['encryption'] ?? '') === 'tls') {
            smtp_write(
                $socket,
                'STARTTLS'
            );

            smtp_expect($socket, [220]);

            $crypto = @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'SMTPのTLS接続を確立できません。'
                );
            }

            smtp_write(
                $socket,
                'EHLO localhost'
            );

            smtp_expect($socket, [250]);
        }

        if (!empty($mail['auth'])) {
            smtp_write(
                $socket,
                'AUTH LOGIN'
            );

            smtp_expect($socket, [334]);

            smtp_write(
                $socket,
                base64_encode(
                    (string)$mail['username']
                )
            );

            smtp_expect($socket, [334]);

            smtp_write(
                $socket,
                base64_encode(
                    (string)$mail['password']
                )
            );

            smtp_expect($socket, [235]);
        }

        smtp_write(
            $socket,
            'QUIT'
        );

        smtp_expect($socket, [221]);
    } finally {
        fclose($socket);
    }
}

/* ============================================================
 * アンケート保存
 * ============================================================ */

function save_survey_from_post(?array $existing = null): array
{
    $survey = $existing ?? new_survey();

    $survey['title'] = post_string('title');
    $survey['description'] = post_string('description');
    $survey['startAt'] = post_string('startAt');
    $survey['endAt'] = post_string('endAt');

    $numbering = post_string(
        'numbering',
        'global'
    );

    if (!in_array(
        $numbering,
        ['global', 'group'],
        true
    )) {
        $numbering = 'global';
    }

    $survey['numbering'] = $numbering;

    $status = $survey['status'] ?? 'draft';

    if ($existing === null) {
        $status = 'draft';
    }

    if (
        $existing !== null
        && isset($_POST['status'])
    ) {
        $requested = post_string('status');

        if (
            in_array(
                $requested,
                ['draft', 'published', 'stopped'],
                true
            )
            && $status !== 'ended'
        ) {
            $status = $requested;
        }
    }

    $survey['status'] = $status;
    $survey['groups'] = parse_groups_from_post();
    $survey['updatedAt'] = now_string();

    if (!isset($survey['createdAt'])) {
        $survey['createdAt'] = now_string();
    }

    $survey = normalize_survey($survey);

    $errors = validate_survey($survey);

    if ($errors !== []) {
        throw new InvalidArgumentException(
            implode("\n", $errors)
        );
    }

    return $survey;
}

function parse_groups_from_post(): array
{
    $raw = $_POST['groups'] ?? [];

    if (!is_array($raw)) {
        return [];
    }

    $groups = [];

    foreach ($raw as $groupIndex => $groupRaw) {
        if (!is_array($groupRaw)) {
            continue;
        }

        $groupId = trim(
            (string)($groupRaw['id'] ?? '')
        );

        if ($groupId === '') {
            $groupId = new_id('group');
        }

        $group = [
            'id' => $groupId,
            'title' => mb_substr(
                trim((string)($groupRaw['title'] ?? '')),
                0,
                200
            ),
            'questions' => [],
        ];

        $questionsRaw = $groupRaw['questions'] ?? [];

        if (!is_array($questionsRaw)) {
            $questionsRaw = [];
        }

        foreach ($questionsRaw as $questionRaw) {
            if (!is_array($questionRaw)) {
                continue;
            }

            $questionId = trim(
                (string)($questionRaw['id'] ?? '')
            );

            if ($questionId === '') {
                $questionId = new_id('question');
            }

            $type = (string)($questionRaw['type'] ?? 'single');

            if (!in_array(
                $type,
                ['single', 'multiple', 'text'],
                true
            )) {
                $type = 'single';
            }

            $options = [];

            $optionRaw = $questionRaw['options'] ?? [];

            if (!is_array($optionRaw)) {
                $optionRaw = [];
            }

            foreach ($optionRaw as $opt) {
                if (!is_array($opt)) {
                    continue;
                }

                $options[] = [
                    'id' => trim(
                        (string)($opt['id'] ?? new_id('option'))
                    ),
                    'label' => mb_substr(
                        trim((string)($opt['label'] ?? '')),
                        0,
                        500
                    ),
                    'nextQuestionId' => trim(
                        (string)($opt['nextQuestionId'] ?? '')
                    ),
                ];
            }

            $group['questions'][] = [
                'id' => $questionId,
                'groupId' => $groupId,
                'text' => mb_substr(
                    trim((string)($questionRaw['text'] ?? '')),
                    0,
                    MAX_TEXT_LENGTH
                ),
                'type' => $type,
                'required' => (
                    isset($questionRaw['required'])
                    && (
                        $questionRaw['required'] === '1'
                        || $questionRaw['required'] === 1
                        || $questionRaw['required'] === true
                    )
                ),
                'options' => $options,
                'number' => '',
            ];
        }

        $groups[] = $group;
    }

    return $groups;
}

/* ============================================================
 * 回答保存
 *
 * この関数はこのファイル内に1回だけ定義する。
 * ============================================================ */

function save_answers(
    string $surveyId,
    array $answers
): string {
    $surveys = load_surveys();
    $survey = find_survey(
        $surveyId,
        $surveys
    );

    if ($survey === null) {
        throw new RuntimeException(
            '指定されたアンケートが存在しません。'
        );
    }

    if (($survey['status'] ?? '') !== 'published') {
        throw new RuntimeException(
            'このアンケートは現在回答を受け付けていません。'
        );
    }

    $answerId = new_id('answer');

    $allAnswers = load_answers();

    $record = [
        'id' => $answerId,
        'surveyId' => $surveyId,
        'answers' => $answers,
        'createdAt' => now_string(),
        'updatedAt' => now_string(),
    ];

    $allAnswers[] = $record;

    write_json_file(
        ANSWERS_FILE,
        $allAnswers
    );

    return $answerId;
}

/* ============================================================
 * 回答処理
 * ============================================================ */

function visible_questions(
    array $survey,
    array $answers
): array {
    $result = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $result[] = $question;
        }
    }

    return $result;
}

function validate_answer_data(
    array $survey,
    array $answers
): array {
    $errors = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $id = (string)$question['id'];

            if (!$question['required']) {
                continue;
            }

            $value = $answers[$id] ?? null;

            $empty = (
                $value === null
                || $value === ''
                || $value === []
            );

            if ($empty) {
                $errors[] =
                    ($question['number'] ?: '質問')
                    . ' は必須です。';
            }
        }
    }

    return $errors;
}

function answer_for_question(
    array $answers,
    string $questionId
): mixed {
    return $answers[$questionId] ?? '';
}

/* ============================================================
 * CSV
 * ============================================================ */

function output_csv(
    array $survey,
    array $answers
): never {
    $filename = 'survey-' . $survey['id'] . '-answers.csv';

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="'
        . $filename
        . '"'
    );

    $fp = fopen('php://output', 'wb');

    if ($fp === false) {
        throw new RuntimeException(
            'CSV出力を開始できません。'
        );
    }

    fwrite(
        $fp,
        "\xEF\xBB\xBF"
    );

    $header = [
        '回答ID',
        '回答日時',
    ];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $header[] =
                ($question['number'] ?? '')
                . ' '
                . ($question['text'] ?? '');
        }
    }

    fputcsv(
        $fp,
        $header
    );

    foreach ($answers as $answer) {
        if (($answer['surveyId'] ?? '') !== $survey['id']) {
            continue;
        }

        $row = [
            $answer['id'] ?? '',
            $answer['createdAt'] ?? '',
        ];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                $value = answer_for_question(
                    $answer['answers'] ?? [],
                    (string)$question['id']
                );

                if (is_array($value)) {
                    $value = implode(
                        ', ',
                        array_map(
                            'strval',
                            $value
                        )
                    );
                }

                $row[] = (string)$value;
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

/* ============================================================
 * PDF
 *
 * 外部ライブラリに依存しない簡易PDF出力。
 * 日本語文字はUTF-8のままPDFへ直接埋め込まない。
 * 実運用で日本語PDFが必要な場合は既存環境のPDFライブラリ
 * を追加する。
 * ============================================================ */

function output_pdf(
    array $survey,
    array $answers
): never {
    /*
     * 要件上PDF出力を提供する。
     *
     * 外部ライブラリを勝手に導入せず、
     * 利用可能なDompdf/TCPDF等がある場合は使用する。
     */

    if (class_exists('\\Dompdf\\Dompdf')) {
        $dompdf = new \Dompdf\Dompdf();

        $html = '<meta charset="UTF-8">'
            . '<h1>' . h($survey['title']) . '</h1>';

        $html .= '<p>回答数: '
            . count($answers)
            . '</p>';

        foreach ($answers as $answer) {
            if (($answer['surveyId'] ?? '') !== $survey['id']) {
                continue;
            }

            $html .= '<hr>';
            $html .= '<p>回答日時: '
                . h($answer['createdAt'] ?? '')
                . '</p>';

            foreach ($survey['groups'] as $group) {
                foreach ($group['questions'] as $question) {
                    $value = answer_for_question(
                        $answer['answers'] ?? [],
                        (string)$question['id']
                    );

                    if (is_array($value)) {
                        $value = implode(', ', $value);
                    }

                    $html .= '<p><strong>'
                        . h($question['number'])
                        . ' '
                        . h($question['text'])
                        . '</strong><br>'
                        . nl2br(h($value))
                        . '</p>';
                }
            }
        }

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();
        $dompdf->stream(
            'survey-' . $survey['id'] . '.pdf',
            ['Attachment' => true]
        );

        exit;
    }

    throw new RuntimeException(
        'PDF出力にはDompdf等のPDFライブラリが必要です。'
        . ' CSV出力は追加ライブラリなしで利用できます。'
    );
}

/* ============================================================
 * メール送信
 * ============================================================ */

function smtp_send_mail(
    array $mail,
    string $to,
    string $subject,
    string $body
): void {
    if (
        filter_var($to, FILTER_VALIDATE_EMAIL) === false
    ) {
        throw new InvalidArgumentException(
            '送信先メールアドレスが不正です。'
        );
    }

    $errors = validate_mail_settings($mail);

    if ($errors !== []) {
        throw new InvalidArgumentException(
            implode("\n", $errors)
        );
    }

    $socket = smtp_connect($mail);

    try {
        smtp_expect($socket, [220]);

        smtp_write(
            $socket,
            'EHLO localhost'
        );

        smtp_expect($socket, [250]);

        if (($mail['encryption'] ?? '') === 'tls') {
            smtp_write(
                $socket,
                'STARTTLS'
            );

            smtp_expect($socket, [220]);

            if (
                @stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                ) !== true
            ) {
                throw new RuntimeException(
                    'SMTP TLS接続を確立できません。'
                );
            }

            smtp_write(
                $socket,
                'EHLO localhost'
            );

            smtp_expect($socket, [250]);
        }

        if (!empty($mail['auth'])) {
            smtp_write(
                $socket,
                'AUTH LOGIN'
            );

            smtp_expect($socket, [334]);

            smtp_write(
                $socket,
                base64_encode(
                    (string)$mail['username']
                )
            );

            smtp_expect($socket, [334]);

            smtp_write(
                $socket,
                base64_encode(
                    (string)$mail['password']
                )
            );

            smtp_expect($socket, [235]);
        }

        $from = (string)$mail['from_email'];

        smtp_write(
            $socket,
            'MAIL FROM:<' . $from . '>'
        );

        smtp_expect($socket, [250]);

        smtp_write(
            $socket,
            'RCPT TO:<' . $to . '>'
        );

        smtp_expect($socket, [250, 251]);

        smtp_write(
            $socket,
            'DATA'
        );

        smtp_expect($socket, [354]);

        $fromName = trim(
            (string)($mail['from_name'] ?? '')
        );

        $fromHeader = $from;

        if ($fromName !== '') {
            $fromHeader =
                '=?UTF-8?B?'
                . base64_encode($fromName)
                . '?= <'
                . $from
                . '>';
        }

        $headers = [
            'From: ' . $fromHeader,
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?'
                . base64_encode($subject)
                . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $replyTo = trim(
            (string)($mail['reply_to'] ?? '')
        );

        if ($replyTo !== '') {
            $headers[] =
                'Reply-To: ' . $replyTo;
        }

        $message = implode(
            "\r\n",
            $headers
        )
        . "\r\n\r\n"
        . str_replace(
            ["\r\n", "\r"],
            "\n",
            $body
        );

        $message = str_replace(
            "\n",
            "\r\n",
            $message
        );

        /*
         * SMTP DATA終端。
         */
        $message = preg_replace(
            '/^\./m',
            '..',
            $message
        ) ?? $message;

        smtp_write(
            $socket,
            $message . "\r\n."
        );

        smtp_expect($socket, [250]);

        smtp_write(
            $socket,
            'QUIT'
        );

        smtp_expect($socket, [221]);
    } finally {
        fclose($socket);
    }
}

/* ============================================================
 * 送信履歴
 * ============================================================ */

function append_send_log(array $record): void
{
    $logs = load_send_logs();

    $logs[] = $record;

    write_json_file(
        SEND_LOG_FILE,
        $logs
    );
}

/* ============================================================
 * POST処理
 * ============================================================ */

function handle_post(): ?string
{
    $action = post_string('action');

    if ($action === '') {
        return null;
    }

    switch ($action) {
        case 'save_survey':
            $id = post_string('survey_id');
            $surveys = load_surveys();

            $existing = $id !== ''
                ? find_survey($id, $surveys)
                : null;

            if ($id !== '' && $existing === null) {
                throw new RuntimeException(
                    '編集対象のアンケートが見つかりません。'
                );
            }

            $survey = save_survey_from_post(
                $existing
            );

            $found = false;

            foreach ($surveys as $index => $item) {
                if (($item['id'] ?? '') === $survey['id']) {
                    $surveys[$index] = $survey;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $surveys[] = $survey;
            }

            write_json_file(
                SURVEYS_FILE,
                $surveys
            );

            set_flash(
                'success',
                'アンケートを保存しました。'
            );

            redirect_screen('list');

        case 'delete_survey':
            $id = post_string('survey_id');

            if ($id === '') {
                throw new InvalidArgumentException(
                    '削除対象が指定されていません。'
                );
            }

            $surveys = load_surveys();
            $newSurveys = [];
            $deleted = false;

            foreach ($surveys as $survey) {
                if (($survey['id'] ?? '') === $id) {
                    $deleted = true;
                    continue;
                }

                $newSurveys[] = $survey;
            }

            if (!$deleted) {
                throw new RuntimeException(
                    '削除対象のアンケートが見つかりません。'
                );
            }

            write_json_file(
                SURVEYS_FILE,
                $newSurveys
            );

            set_flash(
                'success',
                'アンケートを削除しました。'
            );

            redirect_screen('list');

        case 'duplicate_survey':
            $id = post_string('survey_id');

            $surveys = load_surveys();
            $survey = find_survey(
                $id,
                $surveys
            );

            if ($survey === null) {
                throw new RuntimeException(
                    '複製対象のアンケートが見つかりません。'
                );
            }

            $copy = $survey;

            $oldToNew = [];

            $copy['id'] = new_id('survey');
            $copy['title'] .= '（複製）';
            $copy['status'] = 'draft';
            $copy['createdAt'] = now_string();
            $copy['updatedAt'] = now_string();

            foreach ($copy['groups'] as &$group) {
                $oldGroupId = $group['id'];
                $group['id'] = new_id('group');
                $oldToNew[$oldGroupId] = $group['id'];

                foreach ($group['questions'] as &$question) {
                    $oldQuestionId = $question['id'];
                    $question['id'] = new_id('question');
                    $oldToNew[$oldQuestionId] = $question['id'];
                    $question['groupId'] = $group['id'];

                    foreach ($question['options'] as &$option) {
                        $oldOptionId = $option['id'];
                        $option['id'] = new_id('option');
                        $oldToNew[$oldOptionId] = $option['id'];
                    }
                    unset($option);
                }
                unset($question);
            }
            unset($group);

            foreach ($copy['groups'] as &$group) {
                foreach ($group['questions'] as &$question) {
                    foreach ($question['options'] as &$option) {
                        $next = $option['nextQuestionId'] ?? '';

                        if (
                            $next !== ''
                            && isset($oldToNew[$next])
                        ) {
                            $option['nextQuestionId']
                                = $oldToNew[$next];
                        }
                    }
                    unset($option);
                }
                unset($question);
            }
            unset($group);

            recalculate_question_numbers($copy);

            $surveys[] = $copy;

            write_json_file(
                SURVEYS_FILE,
                $surveys
            );

            set_flash(
                'success',
                'アンケートを複製しました。'
            );

            redirect_screen('list');

        case 'change_status':
            $id = post_string('survey_id');
            $newStatus = post_string('new_status');

            if (!in_array(
                $newStatus,
                ['draft', 'published', 'stopped'],
                true
            )) {
                throw new InvalidArgumentException(
                    '変更後の状態が不正です。'
                );
            }

            $surveys = load_surveys();
            $changed = false;

            foreach ($surveys as &$survey) {
                if (($survey['id'] ?? '') !== $id) {
                    continue;
                }

                $survey = normalize_survey($survey);

                if (($survey['status'] ?? '') === 'ended') {
                    throw new RuntimeException(
                        '終了したアンケートの状態は変更できません。'
                    );
                }

                $allowed = [
                    'draft' => ['published'],
                    'published' => ['stopped'],
                    'stopped' => ['published'],
                ];

                $current = $survey['status'] ?? 'draft';

                if (
                    !isset($allowed[$current])
                    || !in_array(
                        $newStatus,
                        $allowed[$current],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'この状態変更は許可されていません。'
                    );
                }

                $survey['status'] = $newStatus;
                $survey['updatedAt'] = now_string();

                $changed = true;
                break;
            }
            unset($survey);

            if (!$changed) {
                throw new RuntimeException(
                    'アンケートが見つかりません。'
                );
            }

            write_json_file(
                SURVEYS_FILE,
                $surveys
            );

            set_flash(
                'success',
                'アンケートの状態を変更しました。'
            );

            redirect_screen('list');

        case 'save_kintone':
            save_kintone_settings();

            set_flash(
                'success',
                'kintone設定を保存しました。'
            );

            /*
             * 303→GET→flash方式には依存しない。
             * 通常のLocationによる画面遷移だけを使用。
             */
            redirect_screen('kintone');

        case 'test_kintone':
            $settings = load_settings();
            $config = $settings['kintone'] ?? default_settings()['kintone'];

            /*
             * テストフォームから直接渡された値を使用。
             * パスワードを画面へ再出力しない。
             */
            if (isset($_POST['subdomain'])) {
                $config['subdomain'] = post_string('subdomain');
            }

            if (isset($_POST['app_id'])) {
                $config['app_id'] = post_string('app_id');
            }

            if (isset($_POST['login_name'])) {
                $config['login_name'] = post_string('login_name');
            }

            if (
                isset($_POST['password'])
                && (string)$_POST['password'] !== ''
            ) {
                $config['password'] = (string)$_POST['password'];
            }

            if (isset($_POST['proxy'])) {
                $config['proxy'] = post_string('proxy');
            }

            $config['verify_ssl'] =
                isset($_POST['verify_ssl'])
                && $_POST['verify_ssl'] === '1';

            $result = kintone_connection_test(
                $config
            );

            $_SESSION['kintone_test_result'] = $result;

            redirect_screen('kintone');

        case 'load_kintone_fields':
            $settings = load_settings();
            $config = $settings['kintone'];

            $fields = kintone_get_fields(
                $config
            );

            $_SESSION['kintone_fields'] = $fields;

            set_flash(
                'success',
                'kintone項目一覧を再取得しました。'
            );

            redirect_screen('kintone');

        case 'sync_kintone':
            $settings = load_settings();
            $config = $settings['kintone'];

            $customers = kintone_get_customers(
                $config,
                $config['fields'] ?? []
            );

            write_json_file(
                CUSTOMERS_FILE,
                $customers
            );

            set_flash(
                'success',
                count($customers)
                . '件の顧客情報を同期しました。'
            );

            redirect_screen('kintone');

        case 'save_kintone_fields':
            $settings = load_settings();

            $fields = [
                'organization' => [],
                'name' => [],
                'email' => [],
                'department' => [],
                'phone' => [],
                'address' => [],
            ];

            foreach ($fields as $key => $_) {
                $values = $_POST['fields'][$key] ?? [];

                if (!is_array($values)) {
                    $values = [$values];
                }

                $fields[$key] = array_values(
                    array_filter(
                        array_map(
                            static fn($v): string => trim((string)$v),
                            $values
                        ),
                        static fn($v): bool => $v !== ''
                    )
                );
            }

            $settings['kintone']['fields'] = $fields;

            write_json_file(
                SETTINGS_FILE,
                $settings
            );

            set_flash(
                'success',
                'kintone項目マッピングを保存しました。'
            );

            redirect_screen('kintone');

        case 'save_mail':
            save_mail_settings();

            set_flash(
                'success',
                'メール設定を保存しました。'
            );

            redirect_screen('mail');

        case 'test_mail':
            $settings = load_settings();

            $mail = $settings['mail'];

            if (isset($_POST['host'])) {
                $mail['host'] = post_string('host');
            }

            if (isset($_POST['port'])) {
                $mail['port'] = post_int('port', 587);
            }

            if (isset($_POST['encryption'])) {
                $mail['encryption'] = post_string('encryption');
            }

            if (isset($_POST['username'])) {
                $mail['username'] = post_string('username');
            }

            if (
                isset($_POST['password'])
                && (string)$_POST['password'] !== ''
            ) {
                $mail['password'] = (string)$_POST['password'];
            }

            $mail['auth'] =
                isset($_POST['auth'])
                && $_POST['auth'] === '1';

            $mail['from_email'] =
                post_string(
                    'from_email',
                    (string)($mail['from_email'] ?? '')
                );

            $mail['from_name'] =
                post_string(
                    'from_name',
                    (string)($mail['from_name'] ?? '')
                );

            $mail['reply_to'] =
                post_string(
                    'reply_to',
                    (string)($mail['reply_to'] ?? '')
                );

            smtp_test_connection($mail);

            $_SESSION['mail_test_result'] = [
                'success' => true,
                'message' => 'SMTP接続テストに成功しました。',
            ];

            redirect_screen('mail');

        case 'send_test_mail':
            $settings = load_settings();
            $mail = $settings['mail'];

            $to = post_string('test_to');

            if (
                filter_var($to, FILTER_VALIDATE_EMAIL) === false
            ) {
                throw new InvalidArgumentException(
                    'テストメール送信先メールアドレスが不正です。'
                );
            }

            smtp_send_mail(
                $mail,
                $to,
                'アンケートアプリ SMTPテスト',
                'これはアンケートアプリから送信したテストメールです。'
            );

            $_SESSION['mail_test_result'] = [
                'success' => true,
                'message' => 'テストメールを送信しました。',
            ];

            redirect_screen('mail');

        case 'submit_answer':
            $surveyId = post_string('survey_id');

            $survey = find_survey(
                $surveyId
            );

            if ($survey === null) {
                throw new RuntimeException(
                    'アンケートが存在しません。'
                );
            }

            if (($survey['status'] ?? '') !== 'published') {
                throw new RuntimeException(
                    'このアンケートは回答受付中ではありません。'
                );
            }

            $answers = $_POST['answers'] ?? [];

            if (!is_array($answers)) {
                $answers = [];
            }

            $errors = validate_answer_data(
                $survey,
                $answers
            );

            if ($errors !== []) {
                throw new InvalidArgumentException(
                    implode("\n", $errors)
                );
            }

            $_SESSION['pending_answer'] = [
                'surveyId' => $surveyId,
                'answers' => $answers,
            ];

            redirect_screen(
                'confirm',
                ['id' => $surveyId]
            );

        case 'confirm_answer':
            $pending = $_SESSION['pending_answer'] ?? null;

            if (!is_array($pending)) {
                throw new RuntimeException(
                    '回答データが見つかりません。'
                );
            }

            $surveyId = (string)($pending['surveyId'] ?? '');
            $answers = $pending['answers'] ?? [];

            if (!is_array($answers)) {
                $answers = [];
            }

            save_answers(
                $surveyId,
                $answers
            );

            unset(
                $_SESSION['pending_answer']
            );

            redirect_screen(
                'complete',
                ['id' => $surveyId]
            );

        case 'send_bulk':
            $surveyId = post_string('survey_id');

            $survey = find_survey($surveyId);

            if ($survey === null) {
                throw new RuntimeException(
                    '対象アンケートが見つかりません。'
                );
            }

            $customerIds = $_POST['customer_ids'] ?? [];

            if (!is_array($customerIds)) {
                $customerIds = [];
            }

            $customerIds = array_values(
                array_unique(
                    array_map(
                        'strval',
                        $customerIds
                    )
                )
            );

            if ($customerIds === []) {
                throw new InvalidArgumentException(
                    '送信対象の顧客を選択してください。'
                );
            }

            $subject = post_string('subject');

            $body = (string)(
                $_POST['body'] ?? ''
            );

            if ($subject === '') {
                throw new InvalidArgumentException(
                    'メール件名を入力してください。'
                );
            }

            $customers = load_customers();
            $settings = load_settings();
            $mail = $settings['mail'];

            $sent = 0;
            $failed = 0;

            foreach ($customers as $customer) {
                $customerId = (string)($customer['id'] ?? '');

                if (!in_array(
                    $customerId,
                    $customerIds,
                    true
                )) {
                    continue;
                }

                $email = trim(
                    (string)($customer['email'] ?? '')
                );

                if (
                    filter_var(
                        $email,
                        FILTER_VALIDATE_EMAIL
                    ) === false
                ) {
                    $failed++;

                    append_send_log([
                        'id' => new_id('send'),
                        'surveyId' => $surveyId,
                        'customerId' => $customerId,
                        'email' => $email,
                        'status' => 'failed',
                        'message' => 'メールアドレスが不正です。',
                        'createdAt' => now_string(),
                    ]);

                    continue;
                }

                $customerName =
                    (string)($customer['name'] ?? '');

                $url = build_answer_url(
                    $surveyId
                );

                $personalBody = str_replace(
                    [
                        '{顧客名}',
                        '{アンケートURL}',
                    ],
                    [
                        $customerName,
                        $url,
                    ],
                    $body
                );

                try {
                    smtp_send_mail(
                        $mail,
                        $email,
                        $subject,
                        $personalBody
                    );

                    $sent++;

                    append_send_log([
                        'id' => new_id('send'),
                        'surveyId' => $surveyId,
                        'customerId' => $customerId,
                        'email' => $email,
                        'status' => 'sent',
                        'message' => '送信成功',
                        'createdAt' => now_string(),
                    ]);
                } catch (Throwable $e) {
                    $failed++;

                    append_send_log([
                        'id' => new_id('send'),
                        'surveyId' => $surveyId,
                        'customerId' => $customerId,
                        'email' => $email,
                        'status' => 'failed',
                        'message' => '送信に失敗しました。',
                        'createdAt' => now_string(),
                    ]);
                }
            }

            $_SESSION['send_result'] = [
                'sent' => $sent,
                'failed' => $failed,
            ];

            redirect_screen(
                'send',
                ['id' => $surveyId]
            );

        case 'save_preview':
            $surveyId = post_string('survey_id');

            if ($surveyId === '') {
                throw new RuntimeException(
                    '対象アンケートが指定されていません。'
                );
            }

            redirect_screen(
                'preview',
                ['id' => $surveyId]
            );

        default:
            throw new InvalidArgumentException(
                '不明な操作です。'
            );
    }

    return null;
}

/* ============================================================
 * 回答URL
 * ============================================================ */

function build_answer_url(string $surveyId): string
{
    $scheme = (
        (!empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http'
    );

    $host = (string)(
        $_SERVER['HTTP_HOST'] ?? 'localhost'
    );

    $script = basename(
        $_SERVER['SCRIPT_NAME'] ?? 'index.php'
    );

    return $scheme
        . '://'
        . $host
        . '/'
        . ltrim($script, '/')
        . '?'
        . http_build_query([
            'screen' => 'answer',
            'id' => $surveyId,
        ]);
}

/* ============================================================
 * 共通CSS
 * ============================================================ */

function render_css(): void
{
?>
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

html,body{
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

.admin-header{
    background:#0f172a;
    color:#fff;
    padding:14px 24px;
}

.admin-header-inner{
    max-width:1400px;
    margin:0 auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.brand{
    font-weight:700;
    font-size:20px;
}

.admin-nav{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.admin-nav a{
    color:#cbd5e1;
    padding:7px 11px;
    border-radius:7px;
}

.admin-nav a:hover,
.admin-nav a.active{
    background:#1e293b;
    color:#fff;
    text-decoration:none;
}

.container{
    max-width:1400px;
    margin:0 auto;
    padding:28px 24px 60px;
}

.container.narrow{
    max-width:900px;
}

.page-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin-bottom:20px;
}

.page-title h1{
    margin:0;
    font-size:26px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:20px;
    margin-bottom:20px;
}

.grid{
    display:grid;
    gap:16px;
}

.grid-2{
    grid-template-columns:repeat(2,minmax(0,1fr));
}

.grid-3{
    grid-template-columns:repeat(3,minmax(0,1fr));
}

.form-group{
    margin-bottom:16px;
}

.form-group label{
    display:block;
    font-weight:700;
    margin-bottom:6px;
}

input[type=text],
input[type=email],
input[type=password],
input[type=number],
input[type=datetime-local],
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
    outline:none;
    border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(37,99,235,.12);
}

.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    border:1px solid transparent;
    border-radius:8px;
    padding:9px 14px;
    font-weight:700;
    text-decoration:none;
    white-space:nowrap;
}

.btn:hover{
    text-decoration:none;
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
    color:var(--text);
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

.btn-small{
    padding:6px 9px;
    font-size:13px;
}

.actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.notice{
    border-radius:9px;
    padding:12px 14px;
    margin-bottom:16px;
    white-space:pre-line;
}

.notice-success{
    background:#dcfce7;
    color:#166534;
    border:1px solid #bbf7d0;
}

.notice-error{
    background:#fee2e2;
    color:#991b1b;
    border:1px solid #fecaca;
}

.notice-warning{
    background:#ffedd5;
    color:#9a3412;
    border:1px solid #fed7aa;
}

.notice-info{
    background:#dbeafe;
    color:#1e40af;
    border:1px solid #bfdbfe;
}

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:1000px;
}

th,
td{
    border-bottom:1px solid var(--border);
    padding:11px 10px;
    text-align:left;
    vertical-align:top;
}

th{
    background:#f8fafc;
    font-size:13px;
}

.badge{
    display:inline-block;
    padding:4px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}

.badge-draft{
    background:#e2e8f0;
    color:#475569;
}

.badge-published{
    background:#dcfce7;
    color:#166534;
}

.badge-stopped{
    background:#ffedd5;
    color:#9a3412;
}

.badge-ended{
    background:#fee2e2;
    color:#991b1b;
}

.toolbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:16px;
}

.question-card{
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
    background:#fff;
    margin-bottom:12px;
}

.group-card{
    border:1px solid #cbd5e1;
    border-radius:12px;
    padding:16px;
    background:#f8fafc;
    margin-bottom:16px;
}

.drag-handle{
    cursor:grab;
    color:var(--gray);
    user-select:none;
}

.option-row{
    display:grid;
    grid-template-columns:1fr 220px auto;
    gap:8px;
    align-items:center;
    margin-bottom:8px;
}

.stat-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
}

.stat{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
}

.stat-label{
    color:var(--gray);
    font-size:13px;
}

.stat-value{
    font-size:26px;
    font-weight:800;
    margin-top:3px;
}

.answer-page{
    max-width:800px;
    margin:0 auto;
    padding:20px 16px 80px;
}

.answer-header{
    margin-bottom:24px;
}

.answer-question{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:18px;
    margin-bottom:16px;
    box-shadow:var(--shadow);
}

.answer-question label.question-label{
    display:block;
    font-weight:700;
    margin-bottom:12px;
}

.required{
    color:var(--danger);
    font-size:12px;
    margin-left:5px;
}

.radio-list,
.checkbox-list{
    display:grid;
    gap:10px;
}

.choice{
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding:12px;
    border:1px solid var(--border);
    border-radius:9px;
    background:#fff;
}

.choice input{
    margin-top:5px;
    transform:scale(1.2);
}

.empty{
    text-align:center;
    color:var(--gray);
    padding:40px 20px;
}

.loading{
    opacity:.6;
    pointer-events:none;
}

@media(max-width:900px){
    .grid-2,
    .grid-3,
    .stat-grid{
        grid-template-columns:1fr;
    }

    .admin-header-inner{
        align-items:flex-start;
        flex-direction:column;
    }

    .option-row{
        grid-template-columns:1fr;
    }
}

@media(max-width:600px){
    .container{
        padding:20px 12px 50px;
    }

    .page-title{
        align-items:flex-start;
        flex-direction:column;
    }

    .btn{
        min-height:42px;
    }

    input[type=text],
    input[type=email],
    input[type=password],
    input[type=number],
    input[type=datetime-local],
    select,
    textarea{
        font-size:16px;
    }

    .answer-page{
        padding:12px 10px 60px;
    }
}
</style>
<?php
}

/* ============================================================
 * 共通ヘッダー
 * ============================================================ */

function render_admin_header(string $screen): void
{
    $items = [
        'list' => 'アンケート一覧',
        'kintone' => 'kintone',
        'mail' => 'メール',
    ];
?>
<header class="admin-header">
    <div class="admin-header-inner">
        <div class="brand"><?= h(APP_NAME) ?></div>

        <nav class="admin-nav">
            <?php foreach ($items as $key => $label): ?>
                <a
                    href="?screen=<?= h($key) ?>"
                    class="<?= $screen === $key ? 'active' : '' ?>"
                ><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</header>
<?php
}

/* ============================================================
 * Flash表示
 * ============================================================ */

function render_messages(): void
{
    foreach (consume_flash() as $flash) {
        $type = match ($flash['type'] ?? '') {
            'success' => 'notice-success',
            'warning' => 'notice-warning',
            'error' => 'notice-error',
            default => 'notice-info',
        };

        echo '<div class="notice ' . h($type) . '">'
            . h($flash['message'] ?? '')
            . '</div>';
    }

    $error = consume_app_error();

    if ($error !== null) {
        echo '<div class="notice notice-error">'
            . '<strong>'
            . h($error['type'] ?? 'エラー')
            . '</strong><br>'
            . nl2br(h($error['message'] ?? ''))
            . '</div>';
    }
}

/* ============================================================
 * 一覧画面
 * ============================================================ */

function render_list(): void
{
    refresh_all_statuses();

    $surveys = load_surveys();

    $search = get_string('q');
    $statusFilter = get_string(
        'status',
        'all'
    );
    $sort = get_string(
        'sort',
        'updated_desc'
    );

    $filtered = [];

    foreach ($surveys as $survey) {
        $survey = normalize_survey($survey);

        if (
            $search !== ''
            && mb_stripos(
                (string)$survey['title'],
                $search
            ) === false
        ) {
            continue;
        }

        if (
            $statusFilter !== 'all'
            && $survey['status'] !== $statusFilter
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
                        $a['updatedAt'],
                        $b['updatedAt']
                    ),

                'answers_desc' =>
                    survey_answer_count($b['id'])
                    <=> survey_answer_count($a['id']),

                'answers_asc' =>
                    survey_answer_count($a['id'])
                    <=> survey_answer_count($b['id']),

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
                        $b['updatedAt'],
                        $a['updatedAt']
                    ),
            };
        }
    );
?>
<?php render_admin_header('list'); ?>

<main class="container">
    <div class="page-title">
        <h1>アンケート一覧</h1>

        <a
            class="btn btn-primary"
            href="?screen=edit"
        >＋ 新規作成</a>
    </div>

    <?php render_messages(); ?>

    <div class="card">
        <form method="get">
            <input
                type="hidden"
                name="screen"
                value="list"
            >

            <div class="grid grid-3">
                <div class="form-group">
                    <label>タイトル検索</label>
                    <input
                        type="text"
                        name="q"
                        value="<?= h($search) ?>"
                        placeholder="タイトルを検索"
                    >
                </div>

                <div class="form-group">
                    <label>ステータス</label>
                    <select name="status">
                        <?php
                        $statuses = [
                            'all' => 'すべて',
                            'published' => '公開中',
                            'draft' => '下書き',
                            'stopped' => '停止',
                            'ended' => '終了',
                        ];
                        ?>

                        <?php foreach ($statuses as $key => $label): ?>
                            <option
                                value="<?= h($key) ?>"
                                <?= $statusFilter === $key ? 'selected' : '' ?>
                            ><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>ソート</label>
                    <select name="sort">
                        <option
                            value="updated_desc"
                            <?= $sort === 'updated_desc' ? 'selected' : '' ?>
                        >更新日：新しい順</option>

                        <option
                            value="updated_asc"
                            <?= $sort === 'updated_asc' ? 'selected' : '' ?>
                        >更新日：古い順</option>

                        <option
                            value="answers_desc"
                            <?= $sort === 'answers_desc' ? 'selected' : '' ?>
                        >回答数：多い順</option>

                        <option
                            value="answers_asc"
                            <?= $sort === 'answers_asc' ? 'selected' : '' ?>
                        >回答数：少ない順</option>

                        <option
                            value="start_desc"
                            <?= $sort === 'start_desc' ? 'selected' : '' ?>
                        >開始日：新しい順</option>

                        <option
                            value="start_asc"
                            <?= $sort === 'start_asc' ? 'selected' : '' ?>
                        >開始日：古い順</option>
                    </select>
                </div>
            </div>

            <button class="btn btn-secondary" type="submit">
                検索・絞り込み
            </button>
        </form>
    </div>

    <div class="card">
        <?php if ($filtered === []): ?>
            <div class="empty">
                アンケートがありません。
            </div>
        <?php else: ?>
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
                    <?php foreach ($filtered as $survey): ?>
                        <?php
                        $statusClass = match ($survey['status']) {
                            'published' => 'badge-published',
                            'stopped' => 'badge-stopped',
                            'ended' => 'badge-ended',
                            default => 'badge-draft',
                        };

                        $statusLabel = match ($survey['status']) {
                            'published' => '公開中',
                            'stopped' => '停止',
                            'ended' => '終了',
                            default => '下書き',
                        };
                        ?>

                        <tr>
                            <td>
                                <strong>
                                    <?= h(
                                        $survey['title'] !== ''
                                            ? $survey['title']
                                            : '無題'
                                    ) ?>
                                </strong>
                            </td>

                            <td><?= h($survey['createdAt']) ?></td>

                            <td><?= h($survey['updatedAt']) ?></td>

                            <td>
                                <?= h($survey['startAt']) ?>
                                〜
                                <?= h($survey['endAt']) ?>
                            </td>

                            <td>
                                <span class="badge <?= h($statusClass) ?>">
                                    <?= h($statusLabel) ?>
                                </span>
                            </td>

                            <td>
                                <?= h(
                                    (string)survey_answer_count(
                                        $survey['id']
                                    )
                                ) ?>
                            </td>

                            <td>
                                <div class="actions">
                                    <a
                                        class="btn btn-small btn-secondary"
                                        href="?screen=edit&id=<?= rawurlencode($survey['id']) ?>"
                                    >確認・編集</a>

                                    <a
                                        class="btn btn-small btn-secondary"
                                        href="?screen=preview&id=<?= rawurlencode($survey['id']) ?>"
                                    >プレビュー</a>

                                    <a
                                        class="btn btn-small btn-secondary"
                                        href="?screen=analytics&id=<?= rawurlencode($survey['id']) ?>"
                                    >集計</a>

                                    <a
                                        class="btn btn-small btn-secondary"
                                        href="?screen=send&id=<?= rawurlencode($survey['id']) ?>"
                                    >送信</a>

                                    <form method="post" style="display:inline">
                                        <input
                                            type="hidden"
                                            name="action"
                                            value="duplicate_survey"
                                        >
                                        <input
                                            type="hidden"
                                            name="survey_id"
                                            value="<?= h($survey['id']) ?>"
                                        >

                                        <button
                                            class="btn btn-small btn-secondary"
                                            type="submit"
                                            data-confirm="このアンケートを複製しますか？"
                                        >複製</button>
                                    </form>

                                    <form method="post" style="display:inline">
                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete_survey"
                                        >
                                        <input
                                            type="hidden"
                                            name="survey_id"
                                            value="<?= h($survey['id']) ?>"
                                        >

                                        <button
                                            class="btn btn-small btn-danger"
                                            type="submit"
                                            data-confirm="このアンケートを削除しますか？"
                                        >削除</button>
                                    </form>

                                    <?php if ($survey['status'] === 'draft'): ?>
                                        <form method="post" style="display:inline">
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="change_status"
                                            >
                                            <input
                                                type="hidden"
                                                name="survey_id"
                                                value="<?= h($survey['id']) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="new_status"
                                                value="published"
                                            >

                                            <button
                                                class="btn btn-small btn-success"
                                                type="submit"
                                                data-confirm="このアンケートを公開しますか？"
                                            >公開</button>
                                        </form>
                                    <?php elseif ($survey['status'] === 'published'): ?>
                                        <form method="post" style="display:inline">
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="change_status"
                                            >
                                            <input
                                                type="hidden"
                                                name="survey_id"
                                                value="<?= h($survey['id']) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="new_status"
                                                value="stopped"
                                            >

                                            <button
                                                class="btn btn-small btn-warning"
                                                type="submit"
                                                data-confirm="このアンケートを停止しますか？"
                                            >停止</button>
                                        </form>
                                    <?php elseif ($survey['status'] === 'stopped'): ?>
                                        <form method="post" style="display:inline">
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="change_status"
                                            >
                                            <input
                                                type="hidden"
                                                name="survey_id"
                                                value="<?= h($survey['id']) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="new_status"
                                                value="published"
                                            >

                                            <button
                                                class="btn btn-small btn-success"
                                                type="submit"
                                                data-confirm="このアンケートを再開しますか？"
                                            >再開</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
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
}

/* ============================================================
 * 編集画面
 * ============================================================ */

function render_edit(): void
{
    $id = get_string('id');

    $survey = $id !== ''
        ? find_survey($id)
        : new_survey();

    if ($survey === null) {
        redirect_screen('list');
    }

    $isNew = $id === '';
?>
<?php render_admin_header('list'); ?>

<main class="container">
    <div class="page-title">
        <h1>
            <?= $isNew ? 'アンケート作成' : 'アンケート編集' ?>
        </h1>
    </div>

    <?php render_messages(); ?>

    <form method="post" id="surveyForm">
        <input
            type="hidden"
            name="action"
            value="save_survey"
        >

        <input
            type="hidden"
            name="survey_id"
            value="<?= h($survey['id']) ?>"
        >

        <div class="card">
            <div class="toolbar">
                <div class="actions">
                    <a
                        class="btn btn-secondary"
                        href="?screen=list"
                        data-cancel-edit
                    >キャンセル</a>

                    <button
                        class="btn btn-primary"
                        type="submit"
                    >保存して一覧へ</button>
                </div>

                <div>
                    <label>
                        状態
                        <select
                            name="status"
                            <?= $survey['status'] === 'ended'
                                ? 'disabled'
                                : '' ?>
                        >
                            <option
                                value="draft"
                                <?= $survey['status'] === 'draft'
                                    ? 'selected'
                                    : '' ?>
                            >下書き</option>

                            <option
                                value="published"
                                <?= $survey['status'] === 'published'
                                    ? 'selected'
                                    : '' ?>
                            >公開中</option>

                            <option
                                value="stopped"
                                <?= $survey['status'] === 'stopped'
                                    ? 'selected'
                                    : '' ?>
                            >停止</option>

                            <?php if ($survey['status'] === 'ended'): ?>
                                <option
                                    value="ended"
                                    selected
                                >終了</option>
                            <?php endif; ?>
                        </select>
                    </label>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="grid grid-2">
                <div class="form-group">
                    <label for="title">
                        アンケートタイトル
                    </label>

                    <input
                        id="title"
                        type="text"
                        name="title"
                        maxlength="<?= MAX_TITLE_LENGTH ?>"
                        value="<?= h($survey['title']) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="numbering">
                        質問番号の採番方式
                    </label>

                    <select
                        id="numbering"
                        name="numbering"
                    >
                        <option
                            value="global"
                            <?= $survey['numbering'] === 'global'
                                ? 'selected'
                                : '' ?>
                        >アンケート全体で通番：Q1、Q2、Q3...</option>

                        <option
                            value="group"
                            <?= $survey['numbering'] === 'group'
                                ? 'selected'
                                : '' ?>
                        >グループ毎：Q1-1、Q1-2、Q2-1...</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="description">
                    アンケート説明
                </label>

                <textarea
                    id="description"
                    name="description"
                    maxlength="<?= MAX_DESCRIPTION_LENGTH ?>"
                ><?= h($survey['description']) ?></textarea>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label for="startAt">
                        開始日時
                    </label>

                    <input
                        id="startAt"
                        type="datetime-local"
                        name="startAt"
                        value="<?= h(
                            $survey['startAt'] !== ''
                                ? date(
                                    'Y-m-d\TH:i',
                                    strtotime($survey['startAt'])
                                )
                                : ''
                        ) ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="endAt">
                        終了日時
                    </label>

                    <input
                        id="endAt"
                        type="datetime-local"
                        name="endAt"
                        value="<?= h(
                            $survey['endAt'] !== ''
                                ? date(
                                    'Y-m-d\TH:i',
                                    strtotime($survey['endAt'])
                                )
                                : ''
                        ) ?>"
                    >
                </div>
            </div>
        </div>

        <div id="groups">
            <?php foreach ($survey['groups'] as $groupIndex => $group): ?>
                <?php render_group_editor(
                    $group,
                    $groupIndex,
                    $survey
                ); ?>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <button
                type="button"
                class="btn btn-secondary"
                id="addGroup"
            >＋ グループを追加</button>
        </div>

        <div class="card">
            <button
                class="btn btn-primary"
                type="submit"
            >保存して一覧へ</button>
        </div>
    </form>
</main>

<script>
(function(){
    const form = document.getElementById('surveyForm');
    const groups = document.getElementById('groups');
    const addGroup = document.getElementById('addGroup');

    function uid(prefix){
        return prefix + '-' +
            Date.now() + '-' +
            Math.random().toString(16).slice(2);
    }

    function renumber(){
        const mode =
            document.getElementById('numbering')?.value
            || 'global';

        let globalNo = 0;

        groups.querySelectorAll('.group-card')
            .forEach((group, gi) => {
                let localNo = 0;

                group.querySelectorAll('.question-card')
                    .forEach(question => {
                        globalNo++;
                        localNo++;

                        const label =
                            question.querySelector(
                                '.question-number'
                            );

                        if (!label) return;

                        label.textContent =
                            mode === 'group'
                                ? 'Q' + (gi + 1)
                                  + '-' + localNo
                                : 'Q' + globalNo;
                    });
            });
    }

    function addGroupElement(){
        const gi =
            groups.querySelectorAll('.group-card').length;

        const wrapper =
            document.createElement('div');

        wrapper.innerHTML = `
<div class="group-card" draggable="true">
    <div class="toolbar">
        <strong class="drag-handle">☰ グループ</strong>

        <button
            type="button"
            class="btn btn-small btn-danger remove-group"
        >グループ削除</button>
    </div>

    <div class="form-group">
        <label>グループタイトル</label>
        <input
            type="text"
            name="groups[${gi}][title]"
            value="新しいグループ"
        >
        <input
            type="hidden"
            name="groups[${gi}][id]"
            value="${uid('group')}"
        >
    </div>

    <div class="questions"></div>

    <button
        type="button"
        class="btn btn-small btn-secondary add-question"
    >＋ 質問を追加</button>
</div>`;

        const group =
            wrapper.firstElementChild;

        groups.appendChild(group);

        const questions =
            group.querySelector('.questions');

        addQuestionElement(
            group,
            questions,
            0
        );

        renumber();
    }

    function addQuestionElement(
        group,
        questions,
        qi
    ){
        const gi =
            Array.from(
                groups.querySelectorAll('.group-card')
            ).indexOf(group);

        const html = `
<div
    class="question-card"
    draggable="true"
>
    <div class="toolbar">
        <strong>
            <span class="question-number">Q?</span>
        </strong>

        <button
            type="button"
            class="btn btn-small btn-danger remove-question"
        >質問削除</button>
    </div>

    <input
        type="hidden"
        name="groups[${gi}][questions][${qi}][id]"
        value="${uid('question')}"
    >

    <div class="form-group">
        <label>質問文</label>
        <textarea
            name="groups[${gi}][questions][${qi}][text]"
        ></textarea>
    </div>

    <div class="grid grid-2">
        <div class="form-group">
            <label>回答形式</label>
            <select
                name="groups[${gi}][questions][${qi}][type]"
                class="question-type"
            >
                <option value="single">単一選択</option>
                <option value="multiple">複数選択</option>
                <option value="text">自由記述</option>
            </select>
        </div>

        <div class="form-group">
            <label>
                <input
                    type="checkbox"
                    name="groups[${gi}][questions][${qi}][required]"
                    value="1"
                >
                必須
            </label>
        </div>
    </div>

    <div class="options-box">
        <label>選択肢</label>
        <div class="options"></div>

        <button
            type="button"
            class="btn btn-small btn-secondary add-option"
        >＋ 選択肢</button>
    </div>
</div>`;

        const wrapper =
            document.createElement('div');

        wrapper.innerHTML = html;

        const question =
            wrapper.firstElementChild;

        questions.appendChild(question);

        addOptionElement(question, 0);
        addOptionElement(question, 1);

        renumber();
    }

    function addOptionElement(
        question,
        oi
    ){
        const gi =
            Array.from(
                groups.querySelectorAll('.group-card')
            ).indexOf(
                question.closest('.group-card')
            );

        const qi =
            Array.from(
                question.closest('.questions')
                    .querySelectorAll('.question-card')
            ).indexOf(question);

        const options =
            question.querySelector('.options');

        const row =
            document.createElement('div');

        row.className = 'option-row';

        row.innerHTML = `
<input
    type="text"
    name="groups[${gi}][questions][${qi}][options][${oi}][label]"
    value="選択肢${oi + 1}"
>

<input
    type="hidden"
    name="groups[${gi}][questions][${qi}][options][${oi}][id]"
    value="${uid('option')}"
>

<input
    type="text"
    name="groups[${gi}][questions][${qi}][options][${oi}][nextQuestionId]"
    placeholder="次に表示する質問ID（任意）"
>

<button
    type="button"
    class="btn btn-small btn-danger remove-option"
>削除</button>`;

        options.appendChild(row);
    }

    addGroup?.addEventListener(
        'click',
        addGroupElement
    );

    document.getElementById('numbering')
        ?.addEventListener(
            'change',
            renumber
        );

    document.addEventListener(
        'click',
        function(e){
            const removeGroup =
                e.target.closest('.remove-group');

            if (removeGroup) {
                if (!confirm(
                    'このグループを削除しますか？'
                )) {
                    return;
                }

                removeGroup
                    .closest('.group-card')
                    .remove();

                renumber();
                return;
            }

            const removeQuestion =
                e.target.closest('.remove-question');

            if (removeQuestion) {
                if (!confirm(
                    'この質問を削除しますか？'
                )) {
                    return;
                }

                removeQuestion
                    .closest('.question-card')
                    .remove();

                renumber();
                return;
            }

            const removeOption =
                e.target.closest('.remove-option');

            if (removeOption) {
                removeOption
                    .closest('.option-row')
                    .remove();

                return;
            }

            const addQuestion =
                e.target.closest('.add-question');

            if (addQuestion) {
                const group =
                    addQuestion.closest('.group-card');

                const questions =
                    group.querySelector('.questions');

                const qi =
                    questions.querySelectorAll(
                        '.question-card'
                    ).length;

                addQuestionElement(
                    group,
                    questions,
                    qi
                );

                return;
            }

            const addOption =
                e.target.closest('.add-option');

            if (addOption) {
                const question =
                    addOption.closest('.question-card');

                const oi =
                    question.querySelectorAll(
                        '.option-row'
                    ).length;

                addOptionElement(
                    question,
                    oi
                );
            }
        }
    );

    document.addEventListener(
        'change',
        function(e){
            if (!e.target.matches('.question-type')) {
                return;
            }

            const question =
                e.target.closest('.question-card');

            const box =
                question.querySelector('.options-box');

            if (!box) return;

            box.style.display =
                e.target.value === 'text'
                    ? 'none'
                    : '';
        }
    );

    document.addEventListener(
        'submit',
        function(e){
            const button =
                e.submitter;

            if (
                button
                && button.dataset.confirm
                && !confirm(button.dataset.confirm)
            ) {
                e.preventDefault();
            }
        }
    );

    document.querySelectorAll(
        '[data-cancel-edit]'
    ).forEach(link => {
        link.addEventListener(
            'click',
            function(e){
                if (!confirm(
                    '編集内容を破棄して一覧へ戻りますか？'
                )) {
                    e.preventDefault();
                }
            }
        );
    });

    renumber();
})();
</script>
<?php
}

function render_group_editor(
    array $group,
    int $groupIndex,
    array $survey
): void {
?>
<div
    class="group-card"
    draggable="true"
>
    <div class="toolbar">
        <strong class="drag-handle">
            ☰ <?= h($group['title']) ?>
        </strong>

        <button
            type="button"
            class="btn btn-small btn-danger remove-group"
        >グループ削除</button>
    </div>

    <div class="form-group">
        <label>グループタイトル</label>

        <input
            type="text"
            name="groups[<?= $groupIndex ?>][title]"
            value="<?= h($group['title']) ?>"
        >

        <input
            type="hidden"
            name="groups[<?= $groupIndex ?>][id]"
            value="<?= h($group['id']) ?>"
        >
    </div>

    <div class="questions">
        <?php foreach ($group['questions'] as $questionIndex => $question): ?>
            <div
                class="question-card"
                draggable="true"
            >
                <div class="toolbar">
                    <strong>
                        <span class="question-number">
                            <?= h($question['number']) ?>
                        </span>
                    </strong>

                    <button
                        type="button"
                        class="btn btn-small btn-danger remove-question"
                    >質問削除</button>
                </div>

                <input
                    type="hidden"
                    name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][id]"
                    value="<?= h($question['id']) ?>"
                >

                <div class="form-group">
                    <label>質問文</label>

                    <textarea
                        name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][text]"
                    ><?= h($question['text']) ?></textarea>
                </div>

                <div class="grid grid-2">
                    <div class="form-group">
                        <label>回答形式</label>

                        <select
                            name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][type]"
                            class="question-type"
                        >
                            <option
                                value="single"
                                <?= $question['type'] === 'single'
                                    ? 'selected'
                                    : '' ?>
                            >単一選択</option>

                            <option
                                value="multiple"
                                <?= $question['type'] === 'multiple'
                                    ? 'selected'
                                    : '' ?>
                            >複数選択</option>

                            <option
                                value="text"
                                <?= $question['type'] === 'text'
                                    ? 'selected'
                                    : '' ?>
                            >自由記述</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>
                            <input
                                type="checkbox"
                                name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][required]"
                                value="1"
                                <?= $question['required']
                                    ? 'checked'
                                    : '' ?>
                            >
                            必須
                        </label>
                    </div>
                </div>

                <div
                    class="options-box"
                    style="<?= $question['type'] === 'text'
                        ? 'display:none'
                        : '' ?>"
                >
                    <label>選択肢</label>

                    <div class="options">
                        <?php foreach ($question['options'] as $optionIndex => $option): ?>
                            <div class="option-row">
                                <input
                                    type="text"
                                    name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][options][<?= $optionIndex ?>][label]"
                                    value="<?= h($option['label']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][options][<?= $optionIndex ?>][id]"
                                    value="<?= h($option['id']) ?>"
                                >

                                <input
                                    type="text"
                                    name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][options][<?= $optionIndex ?>][nextQuestionId]"
                                    value="<?= h($option['nextQuestionId']) ?>"
                                    placeholder="次の質問ID"
                                >

                                <button
                                    type="button"
                                    class="btn btn-small btn-danger remove-option"
                                >削除</button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button
                        type="button"
                        class="btn btn-small btn-secondary add-option"
                    >＋ 選択肢</button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <button
        type="button"
        class="btn btn-small btn-secondary add-question"
    >＋ 質問を追加</button>
</div>
<?php
}

/* ============================================================
 * プレビュー
 * ============================================================ */

function render_preview(): void
{
    $id = get_string('id');
    $survey = find_survey($id);

    if ($survey === null) {
        redirect_screen('list');
    }
?>
<?php render_admin_header('list'); ?>

<main class="container">
    <div class="page-title">
        <h1>プレビュー</h1>

        <div class="actions">
            <a
                class="btn btn-secondary"
                href="?screen=edit&id=<?= rawurlencode($survey['id']) ?>"
            >編集へ戻る</a>

            <a
                class="btn btn-primary"
                href="?screen=answer&id=<?= rawurlencode($survey['id']) ?>"
                target="_blank"
            >回答画面を確認</a>
        </div>
    </div>

    <div class="card">
        <h2><?= h($survey['title']) ?></h2>

        <?php if ($survey['description'] !== ''): ?>
            <p>
                <?= nl2br(h($survey['description'])) ?>
            </p>
        <?php endif; ?>
    </div>

    <?php foreach ($survey['groups'] as $group): ?>
        <div class="card">
            <h3><?= h($group['title']) ?></h3>

            <?php foreach ($group['questions'] as $question): ?>
                <div class="answer-question">
                    <label class="question-label">
                        <?= h($question['number']) ?>
                        <?= h($question['text']) ?>

                        <?php if ($question['required']): ?>
                            <span class="required">必須</span>
                        <?php endif; ?>
                    </label>

                    <?php if ($question['type'] === 'text'): ?>
                        <textarea
                            placeholder="自由記述"
                            disabled
                        ></textarea>
                    <?php else: ?>
                        <div class="radio-list">
                            <?php foreach ($question['options'] as $option): ?>
                                <label class="choice">
                                    <input
                                        type="<?= $question['type'] === 'multiple'
                                            ? 'checkbox'
                                            : 'radio' ?>"
                                        disabled
                                    >
                                    <span>
                                        <?= h($option['label']) ?>
                                    </span>
                                </label>

                                <?php if (
                                    $option['nextQuestionId'] !== ''
                                ): ?>
                                    <small>
                                        条件分岐 →
                                        <?= h(
                                            $option['nextQuestionId']
                                        ) ?>
                                    </small>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</main>
<?php
}

/* ============================================================
 * 回答画面
 * ============================================================ */

function render_answer(): void
{
    $id = get_string('id');
    $survey = find_survey($id);

    if ($survey === null) {
        render_answer_error(
            'アンケートが見つかりません。'
        );
        return;
    }

    if (($survey['status'] ?? '') !== 'published') {
        render_answer_error(
            'このアンケートは現在回答を受け付けていません。'
        );
        return;
    }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>
<title><?= h($survey['title']) ?></title>
<?php render_css(); ?>
</head>

<body>
<main class="answer-page">
    <div class="answer-header">
        <h1><?= h($survey['title']) ?></h1>

        <?php if ($survey['description'] !== ''): ?>
            <p>
                <?= nl2br(h($survey['description'])) ?>
            </p>
        <?php endif; ?>
    </div>

    <?php render_messages(); ?>

    <form method="post">
        <input
            type="hidden"
            name="action"
            value="submit_answer"
        >

        <input
            type="hidden"
            name="survey_id"
            value="<?= h($survey['id']) ?>"
        >

        <?php foreach ($survey['groups'] as $group): ?>
            <section class="card">
                <h2><?= h($group['title']) ?></h2>

                <?php foreach ($group['questions'] as $question): ?>
                    <div
                        class="answer-question"
                        data-question-id="<?= h($question['id']) ?>"
                    >
                        <label class="question-label">
                            <?= h($question['number']) ?>
                            <?= h($question['text']) ?>

                            <?php if ($question['required']): ?>
                                <span class="required">
                                    必須
                                </span>
                            <?php endif; ?>
                        </label>

                        <?php if ($question['type'] === 'text'): ?>

                            <textarea
                                name="answers[<?= h($question['id']) ?>]"
                                <?= $question['required']
                                    ? 'required'
                                    : '' ?>
                            ></textarea>

                        <?php elseif ($question['type'] === 'multiple'): ?>

                            <div class="checkbox-list">
                                <?php foreach (
                                    $question['options']
                                    as $option
                                ): ?>
                                    <label class="choice">
                                        <input
                                            type="checkbox"
                                            name="answers[<?= h($question['id']) ?>][]"
                                            value="<?= h($option['id']) ?>"
                                        >

                                        <span>
                                            <?= h($option['label']) ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                        <?php else: ?>

                            <div class="radio-list">
                                <?php foreach (
                                    $question['options']
                                    as $option
                                ): ?>
                                    <label class="choice">
                                        <input
                                            type="radio"
                                            name="answers[<?= h($question['id']) ?>]"
                                            value="<?= h($option['id']) ?>"
                                            <?= $question['required']
                                                ? 'required'
                                                : '' ?>
                                        >

                                        <span>
                                            <?= h($option['label']) ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>

        <div class="actions">
            <button
                class="btn btn-primary"
                type="submit"
            >回答を確認する</button>
        </div>
    </form>
</main>
</body>
</html>
<?php
}

function render_answer_error(string $message): void
{
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>
<title>アンケート</title>
<?php render_css(); ?>
</head>

<body>
<main class="answer-page">
    <div class="card">
        <div class="notice notice-error">
            <?= h($message) ?>
        </div>
    </div>
</main>
</body>
</html>
<?php
}

/* ============================================================
 * 回答確認
 * ============================================================ */

function render_confirm(): void
{
    $id = get_string('id');

    $pending = $_SESSION['pending_answer'] ?? null;

    if (
        !is_array($pending)
        || (string)($pending['surveyId'] ?? '') !== $id
    ) {
        redirect_screen(
            'answer',
            ['id' => $id]
        );
    }

    $survey = find_survey($id);

    if ($survey === null) {
        render_answer_error(
            'アンケートが見つかりません。'
        );
        return;
    }

    $answers = $pending['answers'] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>
<title>回答確認</title>
<?php render_css(); ?>
</head>

<body>
<main class="answer-page">
    <div class="answer-header">
        <h1>回答確認</h1>
        <p><?= h($survey['title']) ?></p>
    </div>

    <?php foreach ($survey['groups'] as $group): ?>
        <section class="card">
            <h2><?= h($group['title']) ?></h2>

            <?php foreach ($group['questions'] as $question): ?>
                <?php
                $value = answer_for_question(
                    $answers,
                    (string)$question['id']
                );

                if (is_array($value)) {
                    $labels = [];

                    foreach ($question['options'] as $option) {
                        if (
                            in_array(
                                (string)$option['id'],
                                array_map(
                                    'strval',
                                    $value
                                ),
                                true
                            )
                        ) {
                            $labels[] = $option['label'];
                        }
                    }

                    $display = implode(
                        '、',
                        $labels
                    );
                } else {
                    $display = '';

                    foreach ($question['options'] as $option) {
                        if (
                            (string)$option['id']
                            === (string)$value
                        ) {
                            $display = $option['label'];
                            break;
                        }
                    }

                    if ($display === '') {
                        $display = (string)$value;
                    }
                }
                ?>

                <div class="answer-question">
                    <strong>
                        <?= h($question['number']) ?>
                        <?= h($question['text']) ?>
                    </strong>

                    <p>
                        <?= nl2br(h($display)) ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>

    <div class="actions">
        <a
            class="btn btn-secondary"
            href="?screen=answer&id=<?= rawurlencode($survey['id']) ?>"
        >修正する</a>

        <form method="post">
            <input
                type="hidden"
                name="action"
                value="confirm_answer"
            >

            <button
                class="btn btn-primary"
                type="submit"
                data-confirm="この回答を送信しますか？"
            >回答を送信する</button>
        </form>
    </div>
</main>

<script>
document.addEventListener('submit', function(e){
    const button = e.submitter;

    if (
        button
        && button.dataset.confirm
        && !confirm(button.dataset.confirm)
    ) {
        e.preventDefault();
    }
});
</script>
</body>
</html>
<?php
}

/* ============================================================
 * 回答完了
 * ============================================================ */

function render_complete(): void
{
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>
<title>回答完了</title>
<?php render_css(); ?>
</head>

<body>
<main class="answer-page">
    <div class="card">
        <div class="notice notice-success">
            回答を送信しました。
        </div>

        <h1>回答完了</h1>

        <p>
            ご回答ありがとうございました。
        </p>
    </div>
</main>
</body>
</html>
<?php
}

/* ============================================================
 * kintone画面
 * ============================================================ */

function render_kintone(): void
{
    $settings = load_settings();
    $config = $settings['kintone'];

    $testResult =
        $_SESSION['kintone_test_result'] ?? null;

    unset(
        $_SESSION['kintone_test_result']
    );

    $fields =
        $_SESSION['kintone_fields'] ?? null;

    unset(
        $_SESSION['kintone_fields']
    );

    $customers = load_customers();
?>
<?php render_admin_header('kintone'); ?>

<main class="container">
    <div class="page-title">
        <h1>kintone連携設定</h1>
    </div>

    <?php render_messages(); ?>

    <?php if (is_array($testResult)): ?>
        <div class="notice <?= !empty($testResult['success'])
            ? 'notice-success'
            : 'notice-error' ?>">
            <strong>
                <?= h($testResult['category'] ?? '結果') ?>
            </strong><br>

            <?= nl2br(
                h($testResult['message'] ?? '')
            ) ?>

            <?php if (!empty($testResult['cause'])): ?>
                <br><br>
                <strong>確認してください：</strong><br>
                <?= nl2br(
                    h($testResult['cause'])
                ) ?>
            <?php endif; ?>

            <?php if (!empty($testResult['detail'])): ?>
                <br>
                <?= nl2br(
                    h($testResult['detail'])
                ) ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>接続設定</h2>

        <p class="notice notice-info">
            接続テストは実際のkintoneへ接続します。
            APIトークンは使用せず、
            ログイン名・パスワードによる
            X-Cybozu-Authorization認証を使用します。
        </p>

        <form method="post">
            <input
                type="hidden"
                name="action"
                value="save_kintone"
            >

            <div class="grid grid-2">
                <div class="form-group">
                    <label>サブドメイン</label>

                    <input
                        type="text"
                        name="subdomain"
                        value="<?= h($config['subdomain']) ?>"
                        placeholder="https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>顧客管理アプリID</label>

                    <input
                        type="number"
                        name="app_id"
                        min="1"
                        value="<?= h($config['app_id']) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>ログイン名</label>

                    <input
                        type="text"
                        name="login_name"
                        value="<?= h($config['login_name']) ?>"
                        autocomplete="username"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>
                        パスワード
                    </label>

                    <input
                        type="password"
                        name="password"
                        value=""
                        autocomplete="new-password"
                        placeholder="変更しない場合は空欄"
                    >
                </div>

                <div class="form-group">
                    <label>Proxy</label>

                    <input
                        type="text"
                        name="proxy"
                        value="<?= h($config['proxy']) ?>"
                        placeholder="host:port（未入力なら直接接続）"
                    >
                </div>

                <div class="form-group">
                    <label>
                        <input
                            type="checkbox"
                            name="verify_ssl"
                            value="1"
                            <?= !empty($config['verify_ssl'])
                                ? 'checked'
                                : '' ?>
                        >
                        SSL証明書検証を有効にする
                    </label>

                    <small>
                        POCでは無効を初期値としています。
                    </small>
                </div>
            </div>

            <button
                class="btn btn-primary"
                type="submit"
            >設定保存</button>
        </form>
    </div>

    <div class="card">
        <h2>接続テスト</h2>

        <form
            method="post"
            class="kintone-test-form"
        >
            <input
                type="hidden"
                name="action"
                value="test_kintone"
            >

            <input
                type="hidden"
                name="subdomain"
                value="<?= h($config['subdomain']) ?>"
            >

            <input
                type="hidden"
                name="app_id"
                value="<?= h($config['app_id']) ?>"
            >

            <input
                type="hidden"
                name="login_name"
                value="<?= h($config['login_name']) ?>"
            >

            <input
                type="hidden"
                name="proxy"
                value="<?= h($config['proxy']) ?>"
            >

            <input
                type="hidden"
                name="verify_ssl"
                value="<?= !empty($config['verify_ssl'])
                    ? '1'
                    : '0' ?>"
            >

            <div class="form-group">
                <label>
                    パスワード
                </label>

                <input
                    type="password"
                    name="password"
                    autocomplete="off"
                    required
                >
            </div>

            <button
                class="btn btn-secondary"
                type="submit"
                data-loading
            >接続テスト</button>
        </form>

        <p>
            接続テストと顧客情報同期は別操作です。
        </p>
    </div>

    <div class="card">
        <h2>項目一覧</h2>

        <form method="post">
            <input
                type="hidden"
                name="action"
                value="load_kintone_fields"
            >

            <button
                class="btn btn-secondary"
                type="submit"
                data-loading
            >項目一覧を再取得</button>
        </form>

        <?php if (is_array($fields)): ?>
            <form method="post" style="margin-top:20px">
                <input
                    type="hidden"
                    name="action"
                    value="save_kintone_fields"
                >

                <div class="grid grid-2">
                    <?php
                    $mapLabels = [
                        'organization' => '組織名',
                        'name' => '氏名',
                        'email' => 'メールアドレス',
                        'department' => '部署名',
                        'phone' => '電話番号',
                        'address' => '住所',
                    ];
                    ?>

                    <?php foreach ($mapLabels as $key => $label): ?>
                        <div class="form-group">
                            <label><?= h($label) ?></label>

                            <select
                                name="fields[<?= h($key) ?>][]"
                            >
                                <option value="">
                                    使用しない
                                </option>

                                <?php
                                $properties =
                                    $fields['properties']
                                    ?? [];

                                if (is_array($properties)):
                                ?>

                                    <?php foreach (
                                        $properties
                                        as $code => $field
                                    ): ?>
                                        <option
                                            value="<?= h($code) ?>"
                                            <?= in_array(
                                                $code,
                                                $config['fields'][$key]
                                                    ?? [],
                                                true
                                            )
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            <?= h(
                                                $code
                                            ) ?>
                                            -
                                            <?= h(
                                                $field['label']
                                                ?? ''
                                            ) ?>
                                        </option>
                                    <?php endforeach; ?>

                                <?php endif; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button
                    class="btn btn-primary"
                    type="submit"
                >項目マッピングを保存</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>顧客情報同期</h2>

        <p>
            現在の同期済み顧客数：
            <strong><?= count($customers) ?>件</strong>
        </p>

        <form method="post">
            <input
                type="hidden"
                name="action"
                value="sync_kintone"
            >

            <button
                class="btn btn-primary"
                type="submit"
                data-loading
                data-confirm="kintoneから顧客情報を同期しますか？"
            >顧客情報を同期</button>
        </form>
    </div>
</main>

<script>
document.addEventListener('submit', function(e){
    const form = e.target;

    if (!form.classList.contains('kintone-test-form')) {
        return;
    }

    const button = e.submitter;

    if (button) {
        button.disabled = true;
        button.textContent =
            '接続テスト中...';
    }
});
</script>
<?php
}

/* ============================================================
 * メール設定画面
 * ============================================================ */

function render_mail(): void
{
    $settings = load_settings();
    $mail = $settings['mail'];

    $testResult =
        $_SESSION['mail_test_result'] ?? null;

    unset(
        $_SESSION['mail_test_result']
    );
?>
<?php render_admin_header('mail'); ?>

<main class="container">
    <div class="page-title">
        <h1>メールサーバ設定</h1>
    </div>

    <?php render_messages(); ?>

    <?php if (is_array($testResult)): ?>
        <div class="notice <?= !empty($testResult['success'])
            ? 'notice-success'
            : 'notice-error' ?>">
            <?= nl2br(
                h($testResult['message'] ?? '')
            ) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="post">
            <input
                type="hidden"
                name="action"
                value="save_mail"
            >

            <div class="grid grid-2">
                <div class="form-group">
                    <label>SMTPサーバ</label>
                    <input
                        type="text"
                        name="host"
                        value="<?= h($mail['host']) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>SMTPポート</label>
                    <input
                        type="number"
                        name="port"
                        value="<?= h($mail['port']) ?>"
                        min="1"
                        max="65535"
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
                        >SSL</option>

                        <option
                            value="tls"
                            <?= $mail['encryption'] === 'tls'
                                ? 'selected'
                                : '' ?>
                        >TLS</option>

                        <option
                            value="none"
                            <?= $mail['encryption'] === 'none'
                                ? 'selected'
                                : '' ?>
                        >なし</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>
                        <input
                            type="checkbox"
                            name="auth"
                            value="1"
                            <?= !empty($mail['auth'])
                                ? 'checked'
                                : '' ?>
                        >
                        SMTP認証を使用
                    </label>
                </div>

                <div class="form-group">
                    <label>SMTPユーザー名</label>
                    <input
                        type="text"
                        name="username"
                        value="<?= h($mail['username']) ?>"
                        autocomplete="username"
                    >
                </div>

                <div class="form-group">
                    <label>SMTPパスワード</label>
                    <input
                        type="password"
                        name="password"
                        value=""
                        placeholder="変更しない場合は空欄"
                        autocomplete="new-password"
                    >
                </div>

                <div class="form-group">
                    <label>送信元メールアドレス</label>
                    <input
                        type="email"
                        name="from_email"
                        value="<?= h($mail['from_email']) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>送信元名</label>
                    <input
                        type="text"
                        name="from_name"
                        value="<?= h($mail['from_name']) ?>"
                    >
                </div>

                <div class="form-group">
                    <label>返信先メールアドレス</label>
                    <input
                        type="email"
                        name="reply_to"
                        value="<?= h($mail['reply_to']) ?>"
                    >
                </div>
            </div>

            <button
                class="btn btn-primary"
                type="submit"
            >設定保存</button>
        </form>
    </div>

    <div class="card">
        <h2>接続テスト</h2>

        <form method="post">
            <input
                type="hidden"
                name="action"
                value="test_mail"
            >

            <p>
                SMTPサーバへ実際に接続します。
            </p>

            <button
                class="btn btn-secondary"
                type="submit"
                data-loading
            >接続テスト</button>
        </form>
    </div>

    <div class="card">
        <h2>テストメール送信</h2>

        <form method="post">
            <input
                type="hidden"
                name="action"
                value="send_test_mail"
            >

            <div class="form-group">
                <label>送信先</label>

                <input
                    type="email"
                    name="test_to"
                    required
                >
            </div>

            <button
                class="btn btn-primary"
                type="submit"
                data-confirm="テストメールを送信しますか？"
            >テストメール送信</button>
        </form>
    </div>
</main>
<?php
}

/* ============================================================
 * 集計画面
 * ============================================================ */

function render_analytics(): void
{
    $id = get_string('id');

    if ($id === '') {
        redirect_screen('list');
    }

    $survey = find_survey($id);

    if ($survey === null) {
        redirect_screen('list');
    }

    $answers = array_values(
        array_filter(
            load_answers(),
            static fn(array $answer): bool =>
                (string)($answer['surveyId'] ?? '')
                === $id
        )
    );

    $customers = load_customers();

    $sentLogs = array_values(
        array_filter(
            load_send_logs(),
            static fn(array $log): bool =>
                (string)($log['surveyId'] ?? '')
                === $id
        )
    );

    $sentCustomerIds = [];

    foreach ($sentLogs as $log) {
        if (($log['status'] ?? '') === 'sent') {
            $sentCustomerIds[] =
                (string)($log['customerId'] ?? '');
        }
    }

    $sentCustomerIds = array_values(
        array_unique($sentCustomerIds)
    );

    $sentCount = count($sentCustomerIds);
    $answerCount = count($answers);
    $unansweredCount = max(
        0,
        $sentCount - $answerCount
    );

    $responseRate =
        $sentCount > 0
            ? round(
                ($answerCount / $sentCount) * 100,
                1
            )
            : 0;

    if (
        isset($_GET['export'])
        && $_GET['export'] === 'csv'
    ) {
        output_csv(
            $survey,
            $answers
        );
    }

    if (
        isset($_GET['export'])
        && $_GET['export'] === 'pdf'
    ) {
        output_pdf(
            $survey,
            $answers
        );
    }
?>
<?php render_admin_header('list'); ?>

<main class="container">
    <div class="page-title">
        <div>
            <h1>回答集計・分析</h1>

            <p>
                対象アンケート：
                <strong><?= h($survey['title']) ?></strong>
            </p>
        </div>

        <div class="actions">
            <a
                class="btn btn-secondary"
                href="?screen=analytics&id=<?= rawurlencode($id) ?>&export=csv"
            >CSV出力</a>

            <a
                class="btn btn-secondary"
                href="?screen=analytics&id=<?= rawurlencode($id) ?>&export=pdf"
            >PDF出力</a>
        </div>
    </div>

    <?php render_messages(); ?>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-label">
                送信対象者数
            </div>

            <div class="stat-value">
                <?= h((string)$sentCount) ?>
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">
                回答数
            </div>

            <div class="stat-value">
                <?= h((string)$answerCount) ?>
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">
                未回答数
            </div>

            <div class="stat-value">
                <?= h((string)$unansweredCount) ?>
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">
                回答率
            </div>

            <div class="stat-value">
                <?= h((string)$responseRate) ?>%
            </div>
        </div>
    </div>

    <div class="card">
        <h2>設問別集計</h2>

        <?php if ($answers === []): ?>
            <div class="empty">
                現在、回答データはありません
            </div>
        <?php else: ?>

            <?php foreach ($survey['groups'] as $group): ?>
                <h3><?= h($group['title']) ?></h3>

                <?php foreach ($group['questions'] as $question): ?>
                    <?php
                    $counts = [];

                    foreach ($question['options'] as $option) {
                        $counts[$option['id']] = 0;
                    }

                    $textCount = 0;

                    foreach ($answers as $answer) {
                        $value = answer_for_question(
                            $answer['answers'] ?? [],
                            (string)$question['id']
                        );

                        if (is_array($value)) {
                            foreach ($value as $item) {
                                $item = (string)$item;

                                if (isset($counts[$item])) {
                                    $counts[$item]++;
                                }
                            }
                        } else {
                            $value = (string)$value;

                            if (
                                $value !== ''
                                && isset($counts[$value])
                            ) {
                                $counts[$value]++;
                            }

                            if (
                                $question['type'] === 'text'
                                && $value !== ''
                            ) {
                                $textCount++;
                            }
                        }
                    }
                    ?>

                    <div class="question-card">
                        <strong>
                            <?= h($question['number']) ?>
                            <?= h($question['text']) ?>
                        </strong>

                        <?php if ($question['type'] === 'text'): ?>
                            <p>
                                回答件数：
                                <?= h((string)$textCount) ?>
                            </p>
                        <?php else: ?>
                            <ul>
                                <?php foreach (
                                    $question['options']
                                    as $option
                                ): ?>
                                    <li>
                                        <?= h($option['label']) ?>：
                                        <strong>
                                            <?= h(
                                                (string)(
                                                    $counts[
                                                        $option['id']
                                                    ] ?? 0
                                                )
                                            ) ?>
                                        </strong>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>個別回答</h2>

        <?php if ($answers === []): ?>
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

                        <?php foreach (
                            $survey['groups']
                            as $group
                        ): ?>
                            <?php foreach (
                                $group['questions']
                                as $question
                            ): ?>
                                <th>
                                    <?= h($question['number']) ?>
                                </th>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($answers as $answer): ?>
                        <tr>
                            <td>
                                <?= h($answer['id'] ?? '') ?>
                            </td>

                            <td>
                                <?= h(
                                    $answer['createdAt'] ?? ''
                                ) ?>
                            </td>

                            <?php foreach (
                                $survey['groups']
                                as $group
                            ): ?>
                                <?php foreach (
                                    $group['questions']
                                    as $question
                                ): ?>
                                    <?php
                                    $value =
                                        answer_for_question(
                                            $answer['answers'] ?? [],
                                            (string)$question['id']
                                        );

                                    if (is_array($value)) {
                                        $display = implode(
                                            '、',
                                            $value
                                        );
                                    } else {
                                        $display = (string)$value;
                                    }
                                    ?>

                                    <td>
                                        <?= nl2br(
                                            h($display)
                                        ) ?>
                                    </td>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php
}

/* ============================================================
 * 送信画面
 * ============================================================ */

function render_send(): void
{
    $id = get_string('id');

    if ($id === '') {
        redirect_screen('list');
    }

    $survey = find_survey($id);

    if ($survey === null) {
        redirect_screen('list');
    }

    $customers = load_customers();

    $search = get_string('q');

    if ($search !== '') {
        $customers = array_values(
            array_filter(
                $customers,
                static function(array $customer) use ($search): bool {
                    $haystack = implode(
                        ' ',
                        [
                            $customer['organization'] ?? '',
                            $customer['name'] ?? '',
                            $customer['email'] ?? '',
                            $customer['department'] ?? '',
                            $customer['phone'] ?? '',
                            $customer['address'] ?? '',
                        ]
                    );

                    return mb_stripos(
                        $haystack,
                        $search
                    ) !== false;
                }
            )
        );
    }

    $logs = array_values(
        array_filter(
            load_send_logs(),
            static fn(array $log): bool =>
                (string)($log['surveyId'] ?? '')
                === $id
        )
    );

    $result = $_SESSION['send_result'] ?? null;
    unset($_SESSION['send_result']);
?>
<?php render_admin_header('list'); ?>

<main class="container">
    <div class="page-title">
        <div>
            <h1>顧客選択・メール送信</h1>

            <p>
                対象アンケート：
                <strong><?= h($survey['title']) ?></strong>
            </p>
        </div>
    </div>

    <?php render_messages(); ?>

    <?php if (is_array($result)): ?>
        <div class="notice notice-success">
            送信結果：
            成功 <?= h((string)$result['sent']) ?>件 /
            失敗 <?= h((string)$result['failed']) ?>件
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="get">
            <input
                type="hidden"
                name="screen"
                value="send"
            >

            <input
                type="hidden"
                name="id"
                value="<?= h($id) ?>"
            >

            <div class="form-group">
                <label>顧客検索</label>

                <input
                    type="text"
                    name="q"
                    value="<?= h($search) ?>"
                    placeholder="組織名・氏名・メール等"
                >
            </div>

            <button
                class="btn btn-secondary"
                type="submit"
            >検索</button>
        </form>
    </div>

    <form method="post">
        <input
            type="hidden"
            name="action"
            value="send_bulk"
        >

        <input
            type="hidden"
            name="survey_id"
            value="<?= h($survey['id']) ?>"
        >

        <div class="card">
            <h2>顧客選択</h2>

            <?php if ($customers === []): ?>
                <div class="empty">
                    顧客情報がありません。
                    kintone設定画面から同期してください。
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>
                                <input
                                    type="checkbox"
                                    id="selectAll"
                                >
                            </th>
                            <th>組織名</th>
                            <th>氏名</th>
                            <th>メール</th>
                            <th>部署</th>
                            <th>電話番号</th>
                            <th>住所</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td>
                                    <input
                                        type="checkbox"
                                        name="customer_ids[]"
                                        value="<?= h(
                                            $customer['id']
                                        ) ?>"
                                        class="customer-check"
                                    >
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

                                <td>
                                    <?= h(
                                        $customer['phone']
                                        ?? ''
                                    ) ?>
                                </td>

                                <td>
                                    <?= h(
                                        $customer['address']
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

        <div class="card">
            <h2>メール作成</h2>

            <div class="form-group">
                <label>件名</label>

                <input
                    type="text"
                    name="subject"
                    value="<?= h(
                        $survey['title']
                        . ' アンケートのお願い'
                    ) ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label>本文</label>

                <textarea
                    name="body"
                    required
                ><?= h(
                    "{顧客名} 様\n\n"
                    . "アンケートへのご協力をお願いいたします。\n\n"
                    . "{アンケートURL}\n"
                ) ?></textarea>
            </div>

            <p class="notice notice-info">
                使用できる変数：
                {顧客名} / {アンケートURL}
            </p>

            <button
                class="btn btn-primary"
                type="submit"
                data-confirm="選択した顧客へ一括送信しますか？"
            >一括送信</button>
        </div>
    </form>

    <div class="card">
        <h2>送信履歴</h2>

        <?php if ($logs === []): ?>
            <div class="empty">
                送信履歴はありません。
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>日時</th>
                        <th>顧客ID</th>
                        <th>メール</th>
                        <th>結果</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach (array_reverse($logs) as $log): ?>
                        <tr>
                            <td>
                                <?= h(
                                    $log['createdAt'] ?? ''
                                ) ?>
                            </td>

                            <td>
                                <?= h(
                                    $log['customerId'] ?? ''
                                ) ?>
                            </td>

                            <td>
                                <?= h(
                                    $log['email'] ?? ''
                                ) ?>
                            </td>

                            <td>
                                <?= ($log['status'] ?? '')
                                    === 'sent'
                                    ? '送信成功'
                                    : '送信失敗' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
document.getElementById('selectAll')
    ?.addEventListener('change', function(){
        document.querySelectorAll(
            '.customer-check'
        ).forEach(function(input){
            input.checked =
                document.getElementById(
                    'selectAll'
                ).checked;
        });
    });

document.addEventListener(
    'submit',
    function(e){
        const button = e.submitter;

        if (
            button
            && button.dataset.confirm
            && !confirm(button.dataset.confirm)
        ) {
            e.preventDefault();
        }
    }
);
</script>
<?php
}

/* ============================================================
 * エラー画面
 * ============================================================ */

function render_system_error(Throwable $e): void
{
    /*
     * 内部スタックトレース・パスワード・認証ヘッダー等を
     * 画面には出さない。
     */
?>
<?php render_admin_header('list'); ?>

<main class="container">
    <div class="card">
        <div class="notice notice-error">
            <strong>処理に失敗しました。</strong>
        </div>

        <p>
            <?= h($e->getMessage()) ?>
        </p>

        <p>
            入力内容、設定内容、外部サービスの接続状態を
            確認してください。
        </p>

        <a
            class="btn btn-secondary"
            href="?screen=list"
        >アンケート一覧へ戻る</a>
    </div>
</main>
<?php
}

/* ============================================================
 * ルーティング
 * ============================================================ */

$screen = get_string(
    'screen',
    'list'
);

try {
    if (is_post()) {
        handle_post();
    }

    /*
     * 回答者画面は管理者ヘッダーを出さない。
     */
    switch ($screen) {
        case 'answer':
            render_answer();
            break;

        case 'confirm':
            render_confirm();
            break;

        case 'complete':
            render_complete();
            break;

        case 'edit':
            render_css();
            render_edit();
            break;

        case 'preview':
            render_preview();
            break;

        case 'send':
            render_send();
            break;

        case 'analytics':
            render_analytics();
            break;

        case 'kintone':
            render_kintone();
            break;

        case 'mail':
            render_mail();
            break;

        case 'list':
        default:
            render_css();
            render_list();
            break;
    }
} catch (Throwable $e) {
    /*
     * 画面上には機密情報を出さない。
     * ログへセッションIDや認証情報を出さない。
     *
     * POCではユーザーが原因を判断できるよう、
     * 例外メッセージのみ表示する。
     */
    render_css();
    render_system_error($e);
}
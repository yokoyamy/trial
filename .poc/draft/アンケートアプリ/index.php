<?php
declare(strict_types=1);

/**
 * ============================================================
 * アンケートアプリ
 * 単一ファイル版 index.php
 *
 * PHP 8.5 / Apache 2.4
 * DBなし
 * PHP cURLなし
 *
 * 外部通信:
 *   kintone : PHP標準Stream HTTP
 *   SMTP    : PHP標準ソケット
 *
 * 画面:
 *   ?screen=list
 *   ?screen=edit&id=...
 *   ?screen=preview&id=...
 *   ?screen=send&id=...
 *   ?screen=analytics&id=...
 *   ?screen=kintone
 *   ?screen=mail
 *   ?screen=answer&id=...
 *   ?screen=confirm&id=...
 *   ?screen=complete&id=...
 * ============================================================
 */


/* ============================================================
 * 1. 基本設定
 * ============================================================
 */

const APP_NAME = 'アンケート管理';

const DATA_DIR_NAME = 'アンケートアプリ_data';

const SESSION_NAME = 'survey_app_session';

const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_READ_TIMEOUT    = 20;

const SMTP_CONNECT_TIMEOUT = 10;
const SMTP_READ_TIMEOUT    = 20;


/* ============================================================
 * 2. セッション
 * ============================================================
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secureCookie = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    );

    session_name(SESSION_NAME);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => get_app_cookie_path(),
        'secure'   => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}


/* ============================================================
 * 3. 共通関数
 * ============================================================
 */

/**
 * アプリケーションの公開パスに合わせたCookie Path。
 */
function get_app_cookie_path(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = str_replace('\\', '/', dirname($script));

    if ($dir === '/' || $dir === '.' || $dir === '\\') {
        return '/';
    }

    return rtrim($dir, '/') . '/';
}


/**
 * HTMLエスケープ
 */
function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


/**
 * 現在時刻
 */
function now_datetime(): string
{
    return date('Y-m-d H:i:s');
}


/**
 * 現在日付
 */
function today_string(): string
{
    return date('Y-m-d');
}


/**
 * ID生成
 */
function generate_id(string $prefix = 'id'): string
{
    return $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(5));
}


/**
 * JSON文字列を安全にデコード
 */
function json_decode_array(string $json, array $default = []): array
{
    $data = json_decode($json, true);

    return is_array($data) ? $data : $default;
}


/**
 * ファイルを原子的に保存
 */
function atomic_write_json(string $file, array $data): bool
{
    $dir = dirname($file);

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));

    $fp = @fopen($tmp, 'wb');

    if ($fp === false) {
        return false;
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            @unlink($tmp);
            return false;
        }

        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        return true;
    } catch (Throwable $e) {
        @fclose($fp);
        @unlink($tmp);
        return false;
    }
}


/**
 * JSONファイル読み込み
 */
function read_json_file(string $file, array $default = []): array
{
    if (!is_file($file)) {
        return $default;
    }

    $fp = @fopen($file, 'rb');

    if ($fp === false) {
        return $default;
    }

    try {
        flock($fp, LOCK_SH);
        $contents = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($contents === false || trim($contents) === '') {
            return $default;
        }

        return json_decode_array($contents, $default);
    } catch (Throwable $e) {
        @fclose($fp);
        return $default;
    }
}


/**
 * データディレクトリ。
 *
 * Web公開ディレクトリそのものではなく、
 * index.phpの親ディレクトリ側に保存する。
 */
function data_dir(): string
{
    static $dir = null;

    if ($dir !== null) {
        return $dir;
    }

    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . DATA_DIR_NAME;

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}


/**
 * データファイル
 */
function data_file(string $name): string
{
    return data_dir() . DIRECTORY_SEPARATOR . $name . '.json';
}


/**
 * セッションメッセージ
 */
function set_flash(string $type, string $message, array $details = []): void
{
    $_SESSION['_flash'] = [
        'type'    => $type,
        'message' => $message,
        'details' => $details,
    ];
}


/**
 * セッションメッセージ取得
 */
function get_flash(): ?array
{
    if (!isset($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
        return null;
    }

    $flash = $_SESSION['_flash'];
    unset($_SESSION['_flash']);

    return $flash;
}


/**
 * CSRFトークン
 */
function csrf_token(): string
{
    if (
        !isset($_SESSION['_csrf'])
        || !is_string($_SESSION['_csrf'])
        || strlen($_SESSION['_csrf']) < 32
    ) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}


/**
 * CSRFチェック
 */
function verify_csrf(): bool
{
    $posted = (string)($_POST['_csrf'] ?? '');
    $stored = (string)($_SESSION['_csrf'] ?? '');

    return $posted !== ''
        && $stored !== ''
        && hash_equals($stored, $posted);
}


/**
 * 安全なリダイレクト
 */
function redirect_to(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}


/**
 * アプリURL
 */
function app_url(array $params = []): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');

    if ($params === []) {
        return $script;
    }

    return $script . '?' . http_build_query($params);
}


/**
 * POST値
 */
function post_string(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    if (is_array($value)) {
        return $default;
    }

    return trim((string)$value);
}


/**
 * POST整数
 */
function post_int(string $key, int $default = 0): int
{
    $value = $_POST[$key] ?? null;

    if (is_array($value)) {
        return $default;
    }

    if ($value === null || $value === '') {
        return $default;
    }

    return (int)$value;
}


/**
 * POST配列
 */
function post_array(string $key): array
{
    $value = $_POST[$key] ?? [];

    return is_array($value) ? $value : [];
}


/**
 * 日時入力をDB用形式へ
 */
function normalize_datetime(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d H:i:s', $timestamp);
}


/**
 * 日時表示
 */
function format_datetime(string $value): string
{
    if ($value === '') {
        return '-';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return h($value);
    }

    return date('Y/m/d H:i', $timestamp);
}


/**
 * URL形式チェック
 */
function valid_url(string $url): bool
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}


/**
 * メール形式チェック
 */
function valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}


/**
 * kintoneサブドメイン正規化
 */
function normalize_kintone_host(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $value = preg_replace('#^https?://#i', '', $value);
    $value = preg_replace('#/.*$#', '', $value);

    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (!str_contains($value, '.cybozu.com')) {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $value)) {
            $value .= '.cybozu.com';
        }
    }

    return strtolower($value);
}


/**
 * Proxyを検証。
 *
 * 正常:
 *   空文字
 *   proxy.example.local:8080
 *   192.168.1.10:3128
 *
 * 不正:
 *   host
 *   host:
 *   host:abc
 *   :8080
 *   http://host:8080
 */
function validate_proxy(string $proxy): array
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return [
            'ok'     => true,
            'value'  => '',
            'host'   => '',
            'port'   => null,
            'message'=> '',
        ];
    }

    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $proxy)) {
        return [
            'ok'      => false,
            'value'   => $proxy,
            'host'    => '',
            'port'    => null,
            'message' => 'Proxyは「host:port」形式で入力してください。http:// は付けません。',
        ];
    }

    if (!preg_match('/^([^:]+):([0-9]+)$/', $proxy, $m)) {
        return [
            'ok'      => false,
            'value'   => $proxy,
            'host'    => '',
            'port'    => null,
            'message' => 'Proxyは「host:port」形式で入力してください。例：proxy.example.local:8080',
        ];
    }

    $host = trim($m[1]);
    $port = (int)$m[2];

    if ($host === '') {
        return [
            'ok'      => false,
            'value'   => $proxy,
            'host'    => '',
            'port'    => null,
            'message' => 'Proxyのホスト名が入力されていません。',
        ];
    }

    if ($port < 1 || $port > 65535) {
        return [
            'ok'      => false,
            'value'   => $proxy,
            'host'    => $host,
            'port'    => $port,
            'message' => 'Proxyのポート番号は1～65535で指定してください。',
        ];
    }

    return [
        'ok'      => true,
        'value'   => $proxy,
        'host'    => $host,
        'port'    => $port,
        'message' => '',
    ];
}


/* ============================================================
 * 4. データ読み書き
 * ============================================================
 */

function load_surveys(): array
{
    $surveys = read_json_file(data_file('surveys'), []);

    foreach ($surveys as &$survey) {
        update_survey_auto_status($survey);
    }
    unset($survey);

    atomic_write_json(data_file('surveys'), $surveys);

    return $surveys;
}


function save_surveys(array $surveys): bool
{
    return atomic_write_json(data_file('surveys'), $surveys);
}


function load_customers(): array
{
    return read_json_file(data_file('customers'), []);
}


function save_customers(array $customers): bool
{
    return atomic_write_json(data_file('customers'), $customers);
}


function load_answers(): array
{
    return read_json_file(data_file('answers'), []);
}


function save_answers_data(array $answers): bool
{
    return atomic_write_json(data_file('answers'), $answers);
}


function load_send_history(): array
{
    return read_json_file(data_file('send_history'), []);
}


function save_send_history(array $history): bool
{
    return atomic_write_json(data_file('send_history'), $history);
}


function load_settings(): array
{
    $defaults = [
        'kintone' => [
            'subdomain'        => '',
            'app_id'           => '',
            'login_name'       => '',
            'password'         => '',
            'proxy'            => '',
            'verify_ssl'       => false,
            'last_test'        => null,
            'last_sync'        => null,
        ],
        'mail' => [
            'smtp_host'        => '',
            'smtp_port'        => 587,
            'encryption'       => 'tls',
            'auth'             => true,
            'username'         => '',
            'password'         => '',
            'from_email'       => '',
            'from_name'        => '',
            'reply_to'         => '',
            'status'           => '未設定',
            'last_test'        => null,
        ],
    ];

    $settings = read_json_file(data_file('settings'), $defaults);

    $settings['kintone'] = array_merge(
        $defaults['kintone'],
        is_array($settings['kintone'] ?? null)
            ? $settings['kintone']
            : []
    );

    $settings['mail'] = array_merge(
        $defaults['mail'],
        is_array($settings['mail'] ?? null)
            ? $settings['mail']
            : []
    );

    return $settings;
}


function save_settings(array $settings): bool
{
    return atomic_write_json(data_file('settings'), $settings);
}


/* ============================================================
 * 5. 初期データ
 * ============================================================
 */

function ensure_initial_data(): void
{
    if (!is_file(data_file('surveys'))) {
        $sample = [
            [
                'id'          => 'survey-001',
                'title'       => 'サービス満足度アンケート',
                'description' => 'サービスをご利用いただいた皆様へ、今後の改善のためのアンケートです。',
                'startAt'     => date('Y-m-d 00:00:00'),
                'endAt'       => date('Y-m-d 23:59:59', strtotime('+30 days')),
                'status'      => 'draft',
                'numbering'   => 'global',
                'createdAt'   => now_datetime(),
                'updatedAt'   => now_datetime(),
                'groups'      => [
                    [
                        'id'    => 'group-001',
                        'title' => '基本情報',
                        'questions' => [
                            [
                                'id'       => 'question-001',
                                'text'      => 'サービスの満足度を教えてください。',
                                'type'      => 'single',
                                'required'  => true,
                                'options'   => [
                                    ['id' => 'opt-001', 'label' => 'とても満足'],
                                    ['id' => 'opt-002', 'label' => '満足'],
                                    ['id' => 'opt-003', 'label' => '普通'],
                                    ['id' => 'opt-004', 'label' => 'やや不満'],
                                    ['id' => 'opt-005', 'label' => '不満'],
                                ],
                                'branches'  => [],
                            ],
                            [
                                'id'       => 'question-002',
                                'text'      => '改善してほしい点があれば教えてください。',
                                'type'      => 'text',
                                'required'  => false,
                                'options'   => [],
                                'branches'  => [],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        save_surveys($sample);
    }

    if (!is_file(data_file('customers'))) {
        save_customers([]);
    }

    if (!is_file(data_file('answers'))) {
        save_answers_data([]);
    }

    if (!is_file(data_file('send_history'))) {
        save_send_history([]);
    }

    if (!is_file(data_file('settings'))) {
        save_settings(load_settings());
    }
}


ensure_initial_data();


/* ============================================================
 * 6. アンケート関連
 * ============================================================
 */

function find_survey(array $surveys, string $id): ?array
{
    foreach ($surveys as $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}


function find_survey_index(array $surveys, string $id): int
{
    foreach ($surveys as $index => $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return (int)$index;
        }
    }

    return -1;
}


function update_survey_auto_status(array &$survey): void
{
    $status = (string)($survey['status'] ?? 'draft');
    $endAt  = (string)($survey['endAt'] ?? '');

    if (
        $status === 'published'
        && $endAt !== ''
        && strtotime($endAt) !== false
        && strtotime($endAt) < time()
    ) {
        $survey['status'] = 'ended';
        $survey['updatedAt'] = now_datetime();
    }
}


function survey_status_label(string $status): string
{
    return match ($status) {
        'published' => '公開中',
        'stopped'   => '停止',
        'ended'     => '終了',
        default     => '下書き',
    };
}


function survey_status_class(string $status): string
{
    return match ($status) {
        'published' => 'status-success',
        'stopped'   => 'status-warning',
        'ended'     => 'status-muted',
        default     => 'status-draft',
    };
}


function flatten_questions(array $survey): array
{
    $result = [];

    foreach (($survey['groups'] ?? []) as $groupIndex => $group) {
        foreach (($group['questions'] ?? []) as $questionIndex => $question) {
            $question['_groupIndex'] = $groupIndex;
            $question['_questionIndex'] = $questionIndex;
            $question['_groupId'] = $group['id'] ?? '';
            $result[] = $question;
        }
    }

    return $result;
}


function recalculate_question_numbers(array &$survey): void
{
    $numbering = (string)($survey['numbering'] ?? 'global');

    foreach (($survey['groups'] ?? []) as $gIndex => &$group) {
        foreach (($group['questions'] ?? []) as $qIndex => &$question) {
            if ($numbering === 'group') {
                $question['number'] = 'Q' . ($gIndex + 1) . '-' . ($qIndex + 1);
            } else {
                $counter = 0;

                for ($gi = 0; $gi <= $gIndex; $gi++) {
                    $counter += count($survey['groups'][$gi]['questions'] ?? []);

                    if ($gi === $gIndex) {
                        $counter = $counter - count($survey['groups'][$gi]['questions'] ?? []) + $qIndex + 1;
                    }
                }

                $question['number'] = 'Q' . $counter;
            }
        }
        unset($question);
    }
    unset($group);
}


function normalize_question(array $question): array
{
    $type = (string)($question['type'] ?? 'single');

    if (!in_array($type, ['single', 'multiple', 'text'], true)) {
        $type = 'single';
    }

    $options = [];

    if ($type !== 'text') {
        foreach (($question['options'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }

            $label = trim((string)($option['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            $options[] = [
                'id'    => (string)($option['id'] ?? generate_id('opt')),
                'label' => $label,
            ];
        }
    }

    return [
        'id'       => (string)($question['id'] ?? generate_id('question')),
        'number'   => (string)($question['number'] ?? ''),
        'text'     => trim((string)($question['text'] ?? '')),
        'type'     => $type,
        'required' => !empty($question['required']),
        'options'  => $options,
        'branches' => is_array($question['branches'] ?? null)
            ? $question['branches']
            : [],
    ];
}


/* ============================================================
 * 7. 回答関連
 * ============================================================
 */

function answers_for_survey(string $surveyId): array
{
    $answers = load_answers();

    return array_values(array_filter(
        $answers,
        static function ($answer) use ($surveyId): bool {
            return (string)($answer['surveyId'] ?? '') === $surveyId;
        }
    ));
}


function answer_count(string $surveyId): int
{
    return count(answers_for_survey($surveyId));
}


function customer_count(): int
{
    return count(load_customers());
}


/**
 * 条件分岐を考慮して表示すべき質問を返す。
 */
function visible_questions(array $survey, array $answers): array
{
    $questions = flatten_questions($survey);

    $visible = [];

    foreach ($questions as $question) {
        $show = true;

        foreach ($questions as $parent) {
            $branches = $parent['branches'] ?? [];

            if (!is_array($branches)) {
                continue;
            }

            foreach ($branches as $branch) {
                if (
                    !is_array($branch)
                    || (string)($branch['questionId'] ?? '') !== (string)($question['id'] ?? '')
                ) {
                    continue;
                }

                $parentId = (string)($parent['id'] ?? '');
                $selected = $answers[$parentId] ?? null;
                $expected = (string)($branch['optionId'] ?? '');

                if (is_array($selected)) {
                    if (!in_array($expected, array_map('strval', $selected), true)) {
                        $show = false;
                    }
                } else {
                    if ((string)$selected !== $expected) {
                        $show = false;
                    }
                }
            }
        }

        if ($show) {
            $visible[] = $question;
        }
    }

    return $visible;
}


/* ============================================================
 * 8. kintone通信
 *
 * PHP cURLは使用しない。
 * stream_context_create() + file_get_contents() を使用する。
 * ============================================================
 */

/**
 * kintone API用の認証ヘッダー。
 *
 * X-Cybozu-Authorization:
 * Base64(login_name:password)
 */
function kintone_auth_header(string $loginName, string $password): string
{
    return 'X-Cybozu-Authorization: ' . base64_encode(
        $loginName . ':' . $password
    );
}


/**
 * kintone API通信。
 *
 * 戻り値:
 * [
 *   ok => bool,
 *   http_status => int,
 *   body => string,
 *   json => array|null,
 *   error => string,
 *   duration_ms => int,
 * ]
 */
function kintone_request(
    string $method,
    string $host,
    string $path,
    string $loginName,
    string $password,
    bool $verifySsl,
    string $proxy = '',
    ?array $body = null
): array {
    $started = microtime(true);

    $host = normalize_kintone_host($host);

    if ($host === '') {
        return [
            'ok' => false,
            'http_status' => 0,
            'body' => '',
            'json' => null,
            'error' => 'kintoneサブドメインが設定されていません。',
            'duration_ms' => 0,
        ];
    }

    $url = 'https://' . $host . $path;

    if (!valid_url($url)) {
        return [
            'ok' => false,
            'http_status' => 0,
            'body' => '',
            'json' => null,
            'error' => 'kintone URLを生成できませんでした。',
            'duration_ms' => 0,
        ];
    }

    $headers = [
        'Accept: application/json',
        'X-Requested-With: XMLHttpRequest',
        kintone_auth_header($loginName, $password),
    ];

    $content = null;

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            return [
                'ok' => false,
                'http_status' => 0,
                'body' => '',
                'json' => null,
                'error' => 'kintoneリクエストデータを生成できませんでした。',
                'duration_ms' => 0,
            ];
        }

        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($content);
    }

    $proxyInfo = validate_proxy($proxy);

    if (!$proxyInfo['ok']) {
        return [
            'ok' => false,
            'http_status' => 0,
            'body' => '',
            'json' => null,
            'error' => $proxyInfo['message'],
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }

    $httpOptions = [
        'method'           => strtoupper($method),
        'header'           => implode("\r\n", $headers),
        'ignore_errors'    => true,
        'timeout'          => KINTONE_CONNECT_TIMEOUT + KINTONE_READ_TIMEOUT,
        'protocol_version' => 1.1,
    ];

    if ($content !== null) {
        $httpOptions['content'] = $content;
    }

    if ($proxyInfo['ok'] && $proxyInfo['value'] !== '') {
        $httpOptions['proxy'] = 'tcp://' . $proxyInfo['host'] . ':' . $proxyInfo['port'];
        $httpOptions['request_fulluri'] = true;
    }

    $sslOptions = [
        'verify_peer'      => $verifySsl,
        'verify_peer_name' => $verifySsl,
        'allow_self_signed'=> !$verifySsl,
    ];

    $context = stream_context_create([
        'http' => $httpOptions,
        'ssl'  => $sslOptions,
    ]);

    $errorMessage = '';

    set_error_handler(
        static function (int $severity, string $message) use (&$errorMessage): bool {
            $errorMessage = $message;
            return true;
        }
    );

    try {
        $response = file_get_contents($url, false, $context);
    } catch (Throwable $e) {
        $response = false;
        $errorMessage = '通信中に例外が発生しました。';
    }

    restore_error_handler();

    $status = 0;

    $responseHeaders = $http_response_header ?? [];

    if (is_array($responseHeaders)) {
        foreach ($responseHeaders as $headerLine) {
            if (preg_match(
                '/^HTTP\/\S+\s+(\d{3})/',
                (string)$headerLine,
                $m
            )) {
                $status = (int)$m[1];
            }
        }
    }

    $bodyText = $response === false ? '' : (string)$response;

    $json = null;

    if ($bodyText !== '') {
        $decoded = json_decode($bodyText, true);

        if (is_array($decoded)) {
            $json = $decoded;
        }
    }

    $ok = $response !== false
        && $status >= 200
        && $status < 300;

    $duration = (int)round(
        (microtime(true) - $started) * 1000
    );

    if (!$ok && $errorMessage === '') {
        if ($status === 0) {
            $errorMessage = 'kintoneへ接続できませんでした。DNS、ネットワーク、Proxy、SSL設定を確認してください。';
        } elseif ($status === 401 || $status === 403) {
            $errorMessage = 'kintoneの認証に失敗しました。ログイン名・パスワード・権限を確認してください。';
        } elseif ($status === 404) {
            $errorMessage = 'kintone APIまたはアプリが見つかりません。サブドメインとアプリIDを確認してください。';
        } elseif ($status === 400) {
            $errorMessage = 'kintoneがリクエストを不正と判断しました。アプリID、認証情報、APIパラメータを確認してください。';
        } else {
            $errorMessage = 'kintone APIからHTTP ' . $status . ' が返されました。';
        }
    }

    return [
        'ok'          => $ok,
        'http_status' => $status,
        'body'        => $bodyText,
        'json'        => $json,
        'error'       => $errorMessage,
        'duration_ms' => $duration,
    ];
}


/**
 * kintone接続テスト。
 *
 * 接続テストと同期は完全に分離。
 */
function test_kintone_connection(array $config): array
{
    $host      = normalize_kintone_host((string)($config['subdomain'] ?? ''));
    $appId     = trim((string)($config['app_id'] ?? ''));
    $loginName = (string)($config['login_name'] ?? '');
    $password  = (string)($config['password'] ?? '');
    $proxy     = (string)($config['proxy'] ?? '');
    $verifySsl = !empty($config['verify_ssl']);

    if ($host === '') {
        return [
            'ok' => false,
            'stage' => '入力確認',
            'message' => 'kintoneサブドメインを入力してください。',
            'details' => [],
        ];
    }

    if ($appId === '' || !ctype_digit($appId)) {
        return [
            'ok' => false,
            'stage' => '入力確認',
            'message' => '顧客管理アプリIDは数字で入力してください。',
            'details' => [],
        ];
    }

    if ($loginName === '') {
        return [
            'ok' => false,
            'stage' => '入力確認',
            'message' => 'ログイン名を入力してください。',
            'details' => [],
        ];
    }

    if ($password === '') {
        return [
            'ok' => false,
            'stage' => '入力確認',
            'message' => 'パスワードを入力してください。',
            'details' => [],
        ];
    }

    $proxyCheck = validate_proxy($proxy);

    if (!$proxyCheck['ok']) {
        return [
            'ok' => false,
            'stage' => '入力確認',
            'message' => $proxyCheck['message'],
            'details' => [],
        ];
    }

    $result = kintone_request(
        'GET',
        $host,
        '/k/v1/app.json?app=' . rawurlencode($appId),
        $loginName,
        $password,
        $verifySsl,
        $proxy
    );

    $details = [
        '接続先' => 'https://' . $host,
        'API' => '/k/v1/app.json',
        'HTTPステータス' => $result['http_status'] ?: '取得できず',
        '通信時間' => $result['duration_ms'] . ' ms',
        'Proxy' => $proxy === '' ? '使用しない' : '使用する',
        'SSL証明書検証' => $verifySsl ? '有効' : '無効',
    ];

    if ($result['ok']) {
        $appName = '';

        if (is_array($result['json'])) {
            $appName = (string)($result['json']['name'] ?? '');
        }

        $message = 'kintoneへの接続に成功しました。';

        if ($appName !== '') {
            $message .= ' アプリ名：' . $appName;
        }

        return [
            'ok' => true,
            'stage' => '接続完了',
            'message' => $message,
            'details' => $details,
        ];
    }

    $apiMessage = '';

    if (is_array($result['json'])) {
        $apiMessage = trim((string)(
            $result['json']['message']
            ?? $result['json']['errors'][0]['message']
            ?? ''
        ));
    }

    if ($apiMessage !== '') {
        $details['kintoneエラー'] = $apiMessage;
    }

    if ($result['http_status'] > 0) {
        $details['HTTPレスポンス'] = 'HTTP ' . $result['http_status'];
    }

    if ($result['error'] !== '') {
        $details['通信情報'] = $result['error'];
    }

    return [
        'ok' => false,
        'stage' => '接続失敗',
        'message' => $result['error'] !== ''
            ? $result['error']
            : 'kintoneへの接続に失敗しました。',
        'details' => $details,
    ];
}


/**
 * kintoneレコード取得。
 *
 * 顧客同期専用。
 */
function fetch_kintone_records(array $config): array
{
    $host      = normalize_kintone_host((string)($config['subdomain'] ?? ''));
    $appId     = trim((string)($config['app_id'] ?? ''));
    $loginName = (string)($config['login_name'] ?? '');
    $password  = (string)($config['password'] ?? '');
    $proxy     = (string)($config['proxy'] ?? '');
    $verifySsl = !empty($config['verify_ssl']);

    $allRecords = [];
    $offset = 0;
    $limit = 500;

    for ($loop = 0; $loop < 100; $loop++) {
        $query = 'limit ' . $limit . ' offset ' . $offset;

        $body = [
            'app'   => (int)$appId,
            'query' => $query,
        ];

        $result = kintone_request(
            'POST',
            $host,
            '/k/v1/records.json',
            $loginName,
            $password,
            $verifySsl,
            $proxy,
            $body
        );

        if (!$result['ok']) {
            return [
                'ok' => false,
                'records' => [],
                'count' => count($allRecords),
                'error' => $result['error'],
                'http_status' => $result['http_status'],
            ];
        }

        $records = [];

        if (is_array($result['json'])) {
            $records = $result['json']['records'] ?? [];
        }

        if (!is_array($records)) {
            $records = [];
        }

        $allRecords = array_merge($allRecords, $records);

        if (count($records) < $limit) {
            break;
        }

        $offset += $limit;
    }

    return [
        'ok' => true,
        'records' => $allRecords,
        'count' => count($allRecords),
        'error' => '',
        'http_status' => 200,
    ];
}


/**
 * kintoneフィールド一覧。
 */
function fetch_kintone_fields(array $config): array
{
    $host      = normalize_kintone_host((string)($config['subdomain'] ?? ''));
    $appId     = trim((string)($config['app_id'] ?? ''));
    $loginName = (string)($config['login_name'] ?? '');
    $password  = (string)($config['password'] ?? '');
    $proxy     = (string)($config['proxy'] ?? '');
    $verifySsl = !empty($config['verify_ssl']);

    $result = kintone_request(
        'GET',
        $host,
        '/k/v1/app/form/fields.json?app=' . rawurlencode($appId),
        $loginName,
        $password,
        $verifySsl,
        $proxy
    );

    if (!$result['ok']) {
        return [
            'ok' => false,
            'fields' => [],
            'error' => $result['error'],
            'http_status' => $result['http_status'],
        ];
    }

    $fields = [];

    if (is_array($result['json'])) {
        $properties = $result['json']['properties'] ?? [];

        if (is_array($properties)) {
            foreach ($properties as $fieldCode => $field) {
                if (!is_array($field)) {
                    continue;
                }

                $fields[] = [
                    'code' => (string)$fieldCode,
                    'label' => (string)($field['label'] ?? ''),
                    'type' => (string)($field['type'] ?? ''),
                ];
            }
        }
    }

    return [
        'ok' => true,
        'fields' => $fields,
        'error' => '',
        'http_status' => 200,
    ];
}


/**
 * kintoneフィールド値取得補助
 */
function kintone_field_value(array $record, string $code): string
{
    if ($code === '') {
        return '';
    }

    $field = $record[$code] ?? null;

    if (!is_array($field)) {
        return '';
    }

    $value = $field['value'] ?? '';

    if (is_array($value)) {
        $values = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $values[] = (string)($item['value'] ?? '');
            } else {
                $values[] = (string)$item;
            }
        }

        return implode(' / ', $values);
    }

    return trim((string)$value);
}


/**
 * kintoneレコードを顧客データへ変換。
 *
 * 既知フィールドコードを優先し、
 * 設定されたマッピングがあればそれを利用する。
 */
function map_kintone_customer(
    array $record,
    array $mapping = []
): array {
    $orgCode   = (string)($mapping['organization'] ?? 'organization');
    $nameCode  = (string)($mapping['name'] ?? 'name');
    $mailCode  = (string)($mapping['email'] ?? 'email');
    $deptCode  = (string)($mapping['department'] ?? 'department');
    $phoneCode = (string)($mapping['phone'] ?? 'phone');

    $addressCodes = $mapping['address'] ?? [];

    if (!is_array($addressCodes)) {
        $addressCodes = [];
    }

    $addressParts = [];

    foreach ($addressCodes as $code) {
        $value = kintone_field_value($record, (string)$code);

        if ($value !== '') {
            $addressParts[] = $value;
        }
    }

    $name = kintone_field_value($record, $nameCode);
    $email = kintone_field_value($record, $mailCode);

    return [
        'id' => generate_id('customer'),
        'kintoneId' => kintone_field_value($record, '$id'),
        'organization' => kintone_field_value($record, $orgCode),
        'name' => $name,
        'email' => $email,
        'department' => kintone_field_value($record, $deptCode),
        'phone' => kintone_field_value($record, $phoneCode),
        'address' => implode(' ', $addressParts),
        'source' => 'kintone',
        'updatedAt' => now_datetime(),
    ];
}


/* ============================================================
 * 9. SMTP通信
 * ============================================================
 */

/**
 * SMTPの一行応答を読む。
 */
function smtp_read($socket): array
{
    $lines = [];

    while (!feof($socket)) {
        $line = fgets($socket, 4096);

        if ($line === false) {
            break;
        }

        $lines[] = rtrim($line, "\r\n");

        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    $last = end($lines);

    $code = 0;

    if (is_string($last) && preg_match('/^(\d{3})/', $last, $m)) {
        $code = (int)$m[1];
    }

    return [
        'code' => $code,
        'lines' => $lines,
    ];
}


/**
 * SMTPコマンド。
 */
function smtp_command($socket, string $command, array $accepted = [250]): array
{
    fwrite($socket, $command . "\r\n");

    $response = smtp_read($socket);

    return [
        'ok' => in_array($response['code'], $accepted, true),
        'response' => $response,
    ];
}


/**
 * SMTP接続テスト。
 */
function test_smtp_connection(array $config): array
{
    $host = trim((string)($config['smtp_host'] ?? ''));
    $port = (int)($config['smtp_port'] ?? 0);
    $encryption = (string)($config['encryption'] ?? 'tls');

    if ($host === '') {
        return [
            'ok' => false,
            'message' => 'SMTPサーバを入力してください。',
            'details' => [],
        ];
    }

    if ($port < 1 || $port > 65535) {
        return [
            'ok' => false,
            'message' => 'SMTPポートは1～65535で指定してください。',
            'details' => [],
        ];
    }

    $transport = 'tcp://' . $host . ':' . $port;

    if ($encryption === 'ssl') {
        $transport = 'ssl://' . $host . ':' . $port;
    }

    $errno = 0;
    $errstr = '';

    $started = microtime(true);

    $socket = @stream_socket_client(
        $transport,
        $errno,
        $errstr,
        SMTP_CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        return [
            'ok' => false,
            'message' => 'SMTPサーバへ接続できませんでした。',
            'details' => [
                'サーバ' => $host,
                'ポート' => $port,
                '暗号化' => $encryption,
                '通信時間' => (int)round((microtime(true) - $started) * 1000) . ' ms',
            ],
        ];
    }

    stream_set_timeout($socket, SMTP_READ_TIMEOUT);

    $greeting = smtp_read($socket);

    if ($greeting['code'] < 200 || $greeting['code'] >= 400) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'SMTPサーバから正常な応答がありませんでした。',
            'details' => [
                'SMTP応答' => $greeting['code'],
            ],
        ];
    }

    $helo = smtp_command(
        $socket,
        'EHLO localhost',
        [250]
    );

    if (!$helo['ok']) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'SMTP EHLOに失敗しました。',
            'details' => [
                'SMTP応答' => $helo['response']['code'],
            ],
        ];
    }

    if ($encryption === 'tls') {
        $tls = smtp_command(
            $socket,
            'STARTTLS',
            [220]
        );

        if (!$tls['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'SMTP STARTTLSに失敗しました。',
                'details' => [
                    'SMTP応答' => $tls['response']['code'],
                ],
            ];

            // 実際のTLS有効化は送信処理側でも行う。
        }
    }

    @fwrite($socket, "QUIT\r\n");
    @fclose($socket);

    return [
        'ok' => true,
        'message' => 'SMTPサーバへの接続に成功しました。',
        'details' => [
            'サーバ' => $host,
            'ポート' => $port,
            '暗号化' => $encryption,
            '通信時間' => (int)round((microtime(true) - $started) * 1000) . ' ms',
        ],
    ];
}


/* ============================================================
 * 10. POSTアクション
 *
 * すべてのPOSTをここで処理。
 * 個別画面で関数を再定義しない。
 * ============================================================
 */

function handle_post_action(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    if (!verify_csrf()) {
        set_flash(
            'error',
            'セッションエラー：フォームの有効期限が切れています。ページを再読み込みして、もう一度操作してください。'
        );

        redirect_to(app_url(['screen' => 'list']));
    }

    $action = post_string('action');

    switch ($action) {

        /* ----------------------------------------------------
         * kintone設定保存
         * ----------------------------------------------------
         */
        case 'save_kintone_settings':
            handle_save_kintone_settings();
            break;


        /* ----------------------------------------------------
         * kintone接続テスト
         * ----------------------------------------------------
         */
        case 'test_kintone':
            handle_test_kintone();
            break;


        /* ----------------------------------------------------
         * kintoneフィールド再取得
         * ----------------------------------------------------
         */
        case 'refresh_kintone_fields':
            handle_refresh_kintone_fields();
            break;


        /* ----------------------------------------------------
         * kintone同期
         * ----------------------------------------------------
         */
        case 'sync_kintone':
            handle_sync_kintone();
            break;


        /* ----------------------------------------------------
         * メール設定保存
         * ----------------------------------------------------
         */
        case 'save_mail_settings':
            handle_save_mail_settings();
            break;


        /* ----------------------------------------------------
         * SMTP接続テスト
         * ----------------------------------------------------
         */
        case 'test_smtp':
            handle_test_smtp();
            break;


        /* ----------------------------------------------------
         * アンケート保存
         * ----------------------------------------------------
         */
        case 'save_survey':
            handle_save_survey();
            break;


        /* ----------------------------------------------------
         * アンケート削除
         * ----------------------------------------------------
         */
        case 'delete_survey':
            handle_delete_survey();
            break;


        /* ----------------------------------------------------
         * アンケート複製
         * ----------------------------------------------------
         */
        case 'duplicate_survey':
            handle_duplicate_survey();
            break;


        /* ----------------------------------------------------
         * 状態変更
         * ----------------------------------------------------
         */
        case 'change_status':
            handle_change_status();
            break;


        /* ----------------------------------------------------
         * 回答保存
         * ----------------------------------------------------
         */
        case 'save_answer':
            handle_save_answer();
            break;


        /* ----------------------------------------------------
         * メール送信
         * ----------------------------------------------------
         */
        case 'send_mail':
            handle_send_mail();
            break;


        /* ----------------------------------------------------
         * CSV
         * ----------------------------------------------------
         */
        case 'export_csv':
            handle_export_csv();
            break;


        default:
            set_flash(
                'error',
                '不正なリクエストです。操作を確認してください。'
            );

            redirect_to(app_url(['screen' => 'list']));
    }
}


/**
 * kintone設定保存。
 */
function handle_save_kintone_settings(): never
{
    $settings = load_settings();

    $subdomain = post_string('subdomain');
    $appId = post_string('app_id');
    $loginName = post_string('login_name');
    $password = post_string('password');
    $proxy = post_string('proxy');
    $verifySsl = post_string('verify_ssl') === '1';

    $errors = [];

    if ($subdomain === '') {
        $errors[] = 'サブドメインを入力してください。';
    }

    $normalizedHost = normalize_kintone_host($subdomain);

    if ($normalizedHost === '') {
        $errors[] = 'サブドメインの形式が不正です。';
    }

    if ($appId === '' || !ctype_digit($appId)) {
        $errors[] = '顧客管理アプリIDは数字で入力してください。';
    }

    if ($loginName === '') {
        $errors[] = 'ログイン名を入力してください。';
    }

    /*
     * パスワード空欄は、
     * 「既存パスワードを維持」の意味にする。
     *
     * 初回設定の場合のみ必須。
     */
    $existingPassword = (string)(
        $settings['kintone']['password'] ?? ''
    );

    if ($password === '' && $existingPassword === '') {
        $errors[] = 'パスワードを入力してください。';
    }

    $proxyCheck = validate_proxy($proxy);

    if (!$proxyCheck['ok']) {
        $errors[] = $proxyCheck['message'];
    }

    if ($errors !== []) {
        set_flash(
            'error',
            '入力内容を確認してください。',
            $errors
        );

        redirect_to(app_url(['screen' => 'kintone']));
    }

    if ($password === '') {
        $password = $existingPassword;
    }

    $settings['kintone'] = array_merge(
        $settings['kintone'],
        [
            'subdomain'  => $normalizedHost,
            'app_id'     => $appId,
            'login_name' => $loginName,
            'password'   => $password,
            'proxy'      => $proxy,
            'verify_ssl' => $verifySsl,
        ]
    );

    /*
     * 保存後に接続テストは自動実行しない。
     * 設定保存と接続テストは別操作。
     */
    if (!save_settings($settings)) {
        set_flash(
            'error',
            '設定を保存できませんでした。',
            [
                '保存先' => 'サーバー側データ領域',
                '確認事項' => 'PHPプロセスにデータ保存先への書き込み権限があるか確認してください。',
            ]
        );

        redirect_to(app_url(['screen' => 'kintone']));
    }

    set_flash(
        'success',
        'kintone設定を保存しました。接続テストは「接続テスト」ボタンから別途実行してください。'
    );

    redirect_to(app_url(['screen' => 'kintone']));
}


/**
 * kintone接続テスト。
 */
function handle_test_kintone(): never
{
    $settings = load_settings();

    /*
     * 画面から最新設定を受け取り、
     * 保存済み設定を直接信用しすぎない。
     *
     * ただしパスワード空欄なら保存済みを使用。
     */
    $config = $settings['kintone'];

    $postedPassword = post_string('password');

    if ($postedPassword !== '') {
        $config['password'] = $postedPassword;
    }

    foreach (
        [
            'subdomain',
            'app_id',
            'login_name',
            'proxy',
        ] as $field
    ) {
        $value = post_string($field);

        if ($value !== '') {
            $config[$field] = $value;
        }
    }

    $config['verify_ssl'] = post_string('verify_ssl') === '1';

    $result = test_kintone_connection($config);

    if ($result['ok']) {
        $settings['kintone']['last_test'] = [
            'ok' => true,
            'at' => now_datetime(),
            'message' => $result['message'],
            'details' => $result['details'],
        ];

        /*
         * 接続テストで入力された設定は
         * 成功時のみ保存する。
         */
        $settings['kintone']['subdomain'] =
            normalize_kintone_host((string)$config['subdomain']);

        $settings['kintone']['app_id'] =
            (string)$config['app_id'];

        $settings['kintone']['login_name'] =
            (string)$config['login_name'];

        if ($postedPassword !== '') {
            $settings['kintone']['password'] = $postedPassword;
        }

        $settings['kintone']['proxy'] =
            (string)$config['proxy'];

        $settings['kintone']['verify_ssl'] =
            !empty($config['verify_ssl']);

        save_settings($settings);

        set_flash(
            'success',
            '接続成功：' . $result['message'],
            $result['details']
        );
    } else {
        $settings['kintone']['last_test'] = [
            'ok' => false,
            'at' => now_datetime(),
            'message' => $result['message'],
            'details' => $result['details'],
        ];

        /*
         * 接続失敗時も「最後のテスト結果」は保存する。
         * パスワードそのものは保存しない。
         */
        save_settings($settings);

        set_flash(
            'error',
            '接続テスト失敗：' . $result['message'],
            $result['details']
        );
    }

    redirect_to(app_url(['screen' => 'kintone']));
}


/**
 * kintoneフィールド再取得。
 */
function handle_refresh_kintone_fields(): never
{
    $settings = load_settings();
    $config = $settings['kintone'];

    $result = fetch_kintone_fields($config);

    if (!$result['ok']) {
        set_flash(
            'error',
            '項目一覧の取得に失敗しました。',
            [
                'HTTPステータス' => $result['http_status'],
                '原因' => $result['error'],
            ]
        );

        redirect_to(app_url(['screen' => 'kintone']));
    }

    $_SESSION['kintone_fields'] = $result['fields'];

    set_flash(
        'success',
        'kintoneの項目一覧を再取得しました。取得件数：' . count($result['fields']) . '件'
    );

    redirect_to(app_url(['screen' => 'kintone']));
}


/**
 * kintone顧客同期。
 */
function handle_sync_kintone(): never
{
    $settings = load_settings();
    $config = $settings['kintone'];

    $result = fetch_kintone_records($config);

    if (!$result['ok']) {
        set_flash(
            'error',
            'kintone同期に失敗しました。',
            [
                'HTTPステータス' => $result['http_status'],
                '原因' => $result['error'],
            ]
        );

        redirect_to(app_url(['screen' => 'kintone']));
    }

    $mapping = [
        'organization' => post_string('map_organization'),
        'name'         => post_string('map_name'),
        'email'        => post_string('map_email'),
        'department'   => post_string('map_department'),
        'phone'        => post_string('map_phone'),
        'address'      => post_array('map_address'),
    ];

    $customers = [];

    foreach ($result['records'] as $record) {
        if (!is_array($record)) {
            continue;
        }

        $customer = map_kintone_customer($record, $mapping);

        /*
         * メールアドレスがないレコードも保存する。
         * 後から確認・編集できるようにする。
         */
        $customers[] = $customer;
    }

    if (!save_customers($customers)) {
        set_flash(
            'error',
            'kintoneからの取得は成功しましたが、顧客データの保存に失敗しました。'
        );

        redirect_to(app_url(['screen' => 'kintone']));
    }

    $settings['kintone']['last_sync'] = [
        'ok' => true,
        'at' => now_datetime(),
        'count' => count($customers),
    ];

    save_settings($settings);

    set_flash(
        'success',
        'kintone同期が完了しました。同期件数：' . count($customers) . '件',
        [
            '取得件数' => count($result['records']),
            '保存件数' => count($customers),
        ]
    );

    redirect_to(app_url(['screen' => 'kintone']));
}


/**
 * メール設定保存。
 */
function handle_save_mail_settings(): never
{
    $settings = load_settings();

    $host = post_string('smtp_host');
    $port = post_int('smtp_port');
    $encryption = post_string('encryption', 'tls');
    $auth = post_string('auth') === '1';
    $username = post_string('username');
    $password = post_string('password');
    $fromEmail = post_string('from_email');
    $fromName = post_string('from_name');
    $replyTo = post_string('reply_to');

    $errors = [];

    if ($host === '') {
        $errors[] = 'SMTPサーバを入力してください。';
    }

    if ($port < 1 || $port > 65535) {
        $errors[] = 'SMTPポートは1～65535で指定してください。';
    }

    if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
        $errors[] = '暗号化方式が不正です。';
    }

    if ($fromEmail !== '' && !valid_email($fromEmail)) {
        $errors[] = '送信元メールアドレスの形式が不正です。';
    }

    if ($replyTo !== '' && !valid_email($replyTo)) {
        $errors[] = '返信先メールアドレスの形式が不正です。';
    }

    if ($errors !== []) {
        set_flash(
            'error',
            '入力内容を確認してください。',
            $errors
        );

        redirect_to(app_url(['screen' => 'mail']));
    }

    if ($password === '') {
        $password = (string)(
            $settings['mail']['password'] ?? ''
        );
    }

    $settings['mail'] = array_merge(
        $settings['mail'],
        [
            'smtp_host'   => $host,
            'smtp_port'   => $port,
            'encryption'  => $encryption,
            'auth'        => $auth,
            'username'    => $username,
            'password'    => $password,
            'from_email'  => $fromEmail,
            'from_name'   => $fromName,
            'reply_to'    => $replyTo,
        ]
    );

    if (!save_settings($settings)) {
        set_flash(
            'error',
            'メール設定を保存できませんでした。サーバーの書き込み権限を確認してください。'
        );

        redirect_to(app_url(['screen' => 'mail']));
    }

    set_flash(
        'success',
        'メール設定を保存しました。接続テストは別途実行してください。'
    );

    redirect_to(app_url(['screen' => 'mail']));
}


/**
 * SMTPテスト。
 */
function handle_test_smtp(): never
{
    $settings = load_settings();

    $config = $settings['mail'];

    foreach (
        [
            'smtp_host',
            'smtp_port',
            'encryption',
            'username',
            'from_email',
            'from_name',
            'reply_to',
        ] as $field
    ) {
        if (isset($_POST[$field]) && !is_array($_POST[$field])) {
            $config[$field] = trim((string)$_POST[$field]);
        }
    }

    $config['smtp_port'] = (int)$config['smtp_port'];
    $config['auth'] = post_string('auth') === '1';

    $password = post_string('password');

    if ($password !== '') {
        $config['password'] = $password;
    }

    $result = test_smtp_connection($config);

    $settings['mail']['last_test'] = [
        'ok' => $result['ok'],
        'at' => now_datetime(),
        'message' => $result['message'],
        'details' => $result['details'],
    ];

    save_settings($settings);

    set_flash(
        $result['ok'] ? 'success' : 'error',
        $result['message'],
        $result['details']
    );

    redirect_to(app_url(['screen' => 'mail']));
}


/**
 * アンケート保存。
 */
function handle_save_survey(): never
{
    $surveys = load_surveys();

    $id = post_string('id');

    $title = post_string('title');
    $description = post_string('description');

    $startAt = normalize_datetime(
        post_string('start_at')
    );

    $endAt = normalize_datetime(
        post_string('end_at')
    );

    $numbering = post_string('numbering', 'global');
    $status = post_string('status', 'draft');

    $errors = [];

    if ($title === '') {
        $errors[] = 'アンケートタイトルを入力してください。';
    }

    if (mb_strlen($title) > 200) {
        $errors[] = 'アンケートタイトルは200文字以内で入力してください。';
    }

    if ($startAt !== '' && $endAt !== '') {
        if (strtotime($endAt) <= strtotime($startAt)) {
            $errors[] = '終了日時は開始日時より後にしてください。';
        }
    }

    if (!in_array($numbering, ['global', 'group'], true)) {
        $numbering = 'global';
    }

    if (!in_array(
        $status,
        ['draft', 'published', 'stopped', 'ended'],
        true
    )) {
        $status = 'draft';
    }

    if ($errors !== []) {
        set_flash(
            'error',
            'アンケートを保存できません。',
            $errors
        );

        if ($id !== '') {
            redirect_to(app_url([
                'screen' => 'edit',
                'id' => $id,
            ]));
        }

        redirect_to(app_url(['screen' => 'edit']));
    }

    $groupsRaw = post_array('groups');

    $groups = [];

    foreach ($groupsRaw as $groupRaw) {
        if (!is_array($groupRaw)) {
            continue;
        }

        $groupId = trim((string)($groupRaw['id'] ?? ''));

        if ($groupId === '') {
            $groupId = generate_id('group');
        }

        $groupTitle = trim(
            (string)($groupRaw['title'] ?? 'グループ')
        );

        $questions = [];

        $questionsRaw = $groupRaw['questions'] ?? [];

        if (!is_array($questionsRaw)) {
            $questionsRaw = [];
        }

        foreach ($questionsRaw as $questionRaw) {
            if (!is_array($questionRaw)) {
                continue;
            }

            $question = normalize_question($questionRaw);

            if ($question['text'] === '') {
                continue;
            }

            $questions[] = $question;
        }

        $groups[] = [
            'id' => $groupId,
            'title' => $groupTitle !== '' ? $groupTitle : 'グループ',
            'questions' => $questions,
        ];
    }

    /*
     * グループが一つもない場合でも編集画面を壊さない。
     */
    if ($groups === []) {
        $groups[] = [
            'id' => generate_id('group'),
            'title' => '基本情報',
            'questions' => [],
        ];
    }

    if ($id !== '') {
        $index = find_survey_index($surveys, $id);

        if ($index < 0) {
            set_flash(
                'error',
                '指定されたアンケートが見つかりません。'
            );

            redirect_to(app_url(['screen' => 'list']));
        }

        $current = $surveys[$index];

        /*
         * endedは手動変更禁止。
         */
        if (($current['status'] ?? '') === 'ended') {
            $status = 'ended';
        }

        $survey = [
            'id'          => $current['id'],
            'title'       => $title,
            'description' => $description,
            'startAt'     => $startAt,
            'endAt'       => $endAt,
            'status'      => $status,
            'numbering'   => $numbering,
            'createdAt'   => $current['createdAt'] ?? now_datetime(),
            'updatedAt'   => now_datetime(),
            'groups'      => $groups,
        ];

        recalculate_question_numbers($survey);

        $surveys[$index] = $survey;
    } else {
        $survey = [
            'id'          => generate_id('survey'),
            'title'       => $title,
            'description' => $description,
            'startAt'     => $startAt,
            'endAt'       => $endAt,
            'status'      => 'draft',
            'numbering'   => $numbering,
            'createdAt'   => now_datetime(),
            'updatedAt'   => now_datetime(),
            'groups'      => $groups,
        ];

        recalculate_question_numbers($survey);

        $surveys[] = $survey;
    }

    if (!save_surveys($surveys)) {
        set_flash(
            'error',
            'アンケートを保存できませんでした。サーバーの書き込み権限を確認してください。'
        );

        redirect_to(app_url([
            'screen' => 'edit',
            'id' => $id,
        ]));
    }

    set_flash(
        'success',
        'アンケートを保存しました。'
    );

    redirect_to(app_url(['screen' => 'list']));
}


/**
 * 削除。
 */
function handle_delete_survey(): never
{
    $id = post_string('id');

    $surveys = load_surveys();

    $index = find_survey_index($surveys, $id);

    if ($index < 0) {
        set_flash(
            'error',
            '削除対象のアンケートが見つかりません。'
        );

        redirect_to(app_url(['screen' => 'list']));
    }

    array_splice($surveys, $index, 1);

    if (!save_surveys($surveys)) {
        set_flash(
            'error',
            'アンケートを削除できませんでした。'
        );

        redirect_to(app_url(['screen' => 'list']));
    }

    set_flash(
        'success',
        'アンケートを削除しました。'
    );

    redirect_to(app_url(['screen' => 'list']));
}


/**
 * 複製。
 */
function handle_duplicate_survey(): never
{
    $id = post_string('id');

    $surveys = load_surveys();

    $survey = find_survey($surveys, $id);

    if ($survey === null) {
        set_flash(
            'error',
            '複製対象のアンケートが見つかりません。'
        );

        redirect_to(app_url(['screen' => 'list']));
    }

    $survey['id'] = generate_id('survey');
    $survey['title'] .= '（複製）';
    $survey['status'] = 'draft';
    $survey['createdAt'] = now_datetime();
    $survey['updatedAt'] = now_datetime();

    $surveys[] = $survey;

    if (!save_surveys($surveys)) {
        set_flash(
            'error',
            'アンケートを複製できませんでした。'
        );

        redirect_to(app_url(['screen' => 'list']));
    }

    set_flash(
        'success',
        'アンケートを複製しました。下書きとして追加されています。'
    );

    redirect_to(app_url(['screen' => 'list']));
}


/**
 * 状態変更。
 */
function handle_change_status(): never
{
    $id = post_string('id');
    $newStatus = post_string('new_status');

    $allowed = [
        'draft',
        'published',
        'stopped',
    ];

    if (!in_array($newStatus, $allowed, true)) {
        set_flash(
            'error',
            '指定された状態変更は許可されていません。'
        );

        redirect_to(app_url(['screen' => 'list']));
    }

    $surveys = load_surveys();
    $index = find_survey_index($surveys, $id);

    if ($index < 0) {
        set_flash(
            'error',
            'アンケートが見つかりません。'
        );

        redirect_to(app_url(['screen' => 'list']));
    }

    if (($surveys[$index]['status'] ?? '') === 'ended') {
        set_flash(
            'error',
            '終了したアンケートの状態は変更できません。'
        );

        redirect_to(app_url(['screen' => 'list']));
    }

    $current = (string)($surveys[$index]['status'] ?? 'draft');

    $validTransition = match ($current) {
        'draft' => $newStatus === 'published',
        'published' => $newStatus === 'stopped',
        'stopped' => $newStatus === 'published',
        default => false,
    };

    if (!$validTransition) {
        set_flash(
            'error',
            'この状態から指定状態への変更はできません。'
        );

        redirect_to(app_url(['screen' => 'list']));
    }

    $surveys[$index]['status'] = $newStatus;
    $surveys[$index]['updatedAt'] = now_datetime();

    if (!save_surveys($surveys)) {
        set_flash(
            'error',
            '状態変更を保存できませんでした。'
        );

        redirect_to(app_url(['screen' => 'list']));
    }

    set_flash(
        'success',
        'アンケート状態を「' . survey_status_label($newStatus) . '」へ変更しました。'
    );

    redirect_to(app_url(['screen' => 'list']));
}


/**
 * 回答保存。
 */
function handle_save_answer(): never
{
    $surveyId = post_string('survey_id');

    $surveys = load_surveys();
    $survey = find_survey($surveys, $surveyId);

    if ($survey === null) {
        set_flash(
            'error',
            'アンケートが見つかりません。'
        );

        redirect_to(app_url(['screen' => 'list']));
    }

    update_survey_auto_status($survey);

    if (($survey['status'] ?? '') !== 'published') {
        set_flash(
            'error',
            'このアンケートは現在回答できません。'
        );

        redirect_to(app_url([
            'screen' => 'answer',
            'id' => $surveyId,
        ]));
    }

    $postedAnswers = post_array('answers');

    $questions = visible_questions($survey, $postedAnswers);

    $errors = [];

    foreach ($questions as $question) {
        if (empty($question['required'])) {
            continue;
        }

        $qid = (string)$question['id'];
        $value = $postedAnswers[$qid] ?? '';

        $empty = false;

        if (is_array($value)) {
            $empty = count($value) === 0;
        } else {
            $empty = trim((string)$value) === '';
        }

        if ($empty) {
            $errors[] =
                ($question['number'] ?? '')
                . ' '
                . ($question['text'] ?? '')
                . ' は必須です。';
        }
    }

    if ($errors !== []) {
        $_SESSION['answer_draft'] = [
            'survey_id' => $surveyId,
            'answers' => $postedAnswers,
        ];

        set_flash(
            'error',
            '未回答の必須項目があります。',
            $errors
        );

        redirect_to(app_url([
            'screen' => 'answer',
            'id' => $surveyId,
        ]));
    }

    /*
     * いきなり確定保存せず、
     * 確認画面へ。
     */
    $_SESSION['answer_confirm'] = [
        'survey_id' => $surveyId,
        'answers' => $postedAnswers,
    ];

    redirect_to(app_url([
        'screen' => 'confirm',
        'id' => $surveyId,
    ]));
}


/**
 * メール送信。
 *
 * 実SMTP送信部分は環境依存が大きいため、
 * ここでは送信対象・履歴管理を中心にする。
 */
function handle_send_mail(): never
{
    $surveyId = post_string('survey_id');

    $surveys = load_surveys();

    $survey = find_survey($surveys, $surveyId);

    if ($survey === null) {
        set_flash(
            'error',
            '対象アンケートが見つかりません。'
        );

        redirect_to(app_url(['screen' => 'list']));
    }

    $customerIds = post_array('customer_ids');

    $subject = post_string('subject');
    $body = post_string('body');

    if ($subject === '') {
        set_flash(
            'error',
            'メール件名を入力してください。'
        );

        redirect_to(app_url([
            'screen' => 'send',
            'id' => $surveyId,
        ]));
    }

    if ($body === '') {
        set_flash(
            'error',
            'メール本文を入力してください。'
        );

        redirect_to(app_url([
            'screen' => 'send',
            'id' => $surveyId,
        ]));
    }

    if ($customerIds === []) {
        set_flash(
            'error',
            '送信対象の顧客を選択してください。'
        );

        redirect_to(app_url([
            'screen' => 'send',
            'id' => $surveyId,
        ]));
    }

    /*
     * 実際のSMTP送信を行う。
     *
     * このPOCでは送信処理に失敗した場合、
     * 履歴へ「失敗」として残す。
     */
    $settings = load_settings();
    $mailConfig = $settings['mail'];

    $customers = load_customers();

    $history = load_send_history();

    $success = 0;
    $failure = 0;

    foreach ($customers as $customer) {
        $customerId = (string)($customer['id'] ?? '');

        if (!in_array($customerId, array_map('strval', $customerIds), true)) {
            continue;
        }

        $email = (string)($customer['email'] ?? '');

        $record = [
            'id' => generate_id('send'),
            'surveyId' => $surveyId,
            'customerId' => $customerId,
            'customerName' => (string)($customer['name'] ?? ''),
            'email' => $email,
            'subject' => $subject,
            'status' => 'pending',
            'sentAt' => null,
            'error' => '',
            'createdAt' => now_datetime(),
        ];

        if (!valid_email($email)) {
            $record['status'] = 'failed';
            $record['error'] = 'メールアドレスが設定されていません。';
            $failure++;
        } else {
            /*
             * SMTP送信実装を呼び出す。
             */
            $sendResult = smtp_send_mail(
                $mailConfig,
                $email,
                $subject,
                str_replace(
                    [
                        '{顧客名}',
                        '{アンケートURL}',
                    ],
                    [
                        (string)($customer['name'] ?? ''),
                        absolute_app_url([
                            'screen' => 'answer',
                            'id' => $surveyId,
                        ]),
                    ],
                    $body
                )
            );

            if ($sendResult['ok']) {
                $record['status'] = 'sent';
                $record['sentAt'] = now_datetime();
                $success++;
            } else {
                $record['status'] = 'failed';
                $record['error'] = $sendResult['message'];
                $failure++;
            }
        }

        $history[] = $record;
    }

    save_send_history($history);

    set_flash(
        $failure === 0 ? 'success' : 'warning',
        'メール送信処理が完了しました。',
        [
            '成功' => $success . '件',
            '失敗' => $failure . '件',
            '合計' => ($success + $failure) . '件',
        ]
    );

    redirect_to(app_url([
        'screen' => 'send',
        'id' => $surveyId,
    ]));
}


/**
 * 実SMTP送信。
 *
 * PHP mail()は使用しない。
 */
function smtp_send_mail(
    array $config,
    string $to,
    string $subject,
    string $body
): array {
    $host = trim((string)($config['smtp_host'] ?? ''));
    $port = (int)($config['smtp_port'] ?? 0);
    $encryption = (string)($config['encryption'] ?? 'tls');

    $username = (string)($config['username'] ?? '');
    $password = (string)($config['password'] ?? '');

    $fromEmail = trim((string)($config['from_email'] ?? ''));
    $fromName = trim((string)($config['from_name'] ?? ''));
    $replyTo = trim((string)($config['reply_to'] ?? ''));

    if ($host === '' || $port < 1 || $port > 65535) {
        return [
            'ok' => false,
            'message' => 'SMTP設定が正しくありません。',
        ];
    }

    if (!valid_email($fromEmail)) {
        return [
            'ok' => false,
            'message' => '送信元メールアドレスが正しくありません。',
        ];
    }

    $transport = 'tcp://' . $host . ':' . $port;

    if ($encryption === 'ssl') {
        $transport = 'ssl://' . $host . ':' . $port;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $transport,
        $errno,
        $errstr,
        SMTP_CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        return [
            'ok' => false,
            'message' => 'SMTPサーバへ接続できませんでした。',
        ];
    }

    stream_set_timeout($socket, SMTP_READ_TIMEOUT);

    $greeting = smtp_read($socket);

    if ($greeting['code'] < 200 || $greeting['code'] >= 400) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'SMTPサーバから正常な応答がありませんでした。',
        ];
    }

    $ehlo = smtp_command(
        $socket,
        'EHLO localhost',
        [250]
    );

    if (!$ehlo['ok']) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'SMTP EHLOに失敗しました。',
        ];
    }

    if ($encryption === 'tls') {
        fwrite($socket, "STARTTLS\r\n");

        $tlsResponse = smtp_read($socket);

        if ($tlsResponse['code'] !== 220) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'SMTP STARTTLSに失敗しました。',
            ];
        }

        $cryptoOk = @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($cryptoOk !== true) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'TLS暗号化を有効化できませんでした。',
            ];
        }

        $ehlo = smtp_command(
            $socket,
            'EHLO localhost',
            [250]
        );

        if (!$ehlo['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'TLS開始後のEHLOに失敗しました。',
            ];
        }
    }

    if (!empty($config['auth'])) {
        if ($username === '' || $password === '') {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'SMTP認証を使用する設定ですが、ユーザー名またはパスワードが未設定です。',
            ];
        }

        $auth = smtp_command(
            $socket,
            'AUTH LOGIN',
            [334]
        );

        if (!$auth['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'SMTP AUTH LOGINを開始できませんでした。',
            ];
        }

        $userResult = smtp_command(
            $socket,
            base64_encode($username),
            [334]
        );

        if (!$userResult['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'SMTPユーザー認証に失敗しました。',
            ];
        }

        $passResult = smtp_command(
            $socket,
            base64_encode($password),
            [235]
        );

        if (!$passResult['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'SMTPパスワード認証に失敗しました。',
            ];
        }
    }

    $mailFrom = smtp_command(
        $socket,
        'MAIL FROM:<' . $fromEmail . '>',
        [250]
    );

    if (!$mailFrom['ok']) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => '送信元メールアドレスをSMTPサーバが受け付けませんでした。',
        ];
    }

    $rcpt = smtp_command(
        $socket,
        'RCPT TO:<' . $to . '>',
        [250, 251]
    );

    if (!$rcpt['ok']) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => '宛先メールアドレスをSMTPサーバが受け付けませんでした。',
        ];
    }

    $data = smtp_command(
        $socket,
        'DATA',
        [354]
    );

    if (!$data['ok']) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'SMTP DATAを開始できませんでした。',
        ];
    }

    $encodedSubject = '=?UTF-8?B?'
        . base64_encode($subject)
        . '?=';

    $encodedFromName = $fromName !== ''
        ? '=?UTF-8?B?' . base64_encode($fromName) . '?='
        : '';

    $fromHeader = $encodedFromName !== ''
        ? $encodedFromName . ' <' . $fromEmail . '>'
        : $fromEmail;

    $headers = [
        'From: ' . $fromHeader,
        'To: ' . $to,
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    if ($replyTo !== '' && valid_email($replyTo)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $message = implode("\r\n", $headers)
        . "\r\n\r\n"
        . preg_replace('/^\./m', '..', $body)
        . "\r\n.";

    fwrite($socket, $message . "\r\n");

    $sent = smtp_read($socket);

    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    if ($sent['code'] < 200 || $sent['code'] >= 300) {
        return [
            'ok' => false,
            'message' => 'SMTPサーバがメール送信を受け付けませんでした。',
        ];
    }

    return [
        'ok' => true,
        'message' => '送信しました。',
    ];
}


/**
 * 絶対URL
 */
function absolute_app_url(array $params): string
{
    $scheme = 'http';

    if (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (
            isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'
        )
    ) {
        $scheme = 'https';
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host . app_url($params);
}


/**
 * CSV出力。
 */
function handle_export_csv(): never
{
    $surveyId = post_string('survey_id');

    $surveys = load_surveys();

    $survey = find_survey($surveys, $surveyId);

    if ($survey === null) {
        set_flash(
            'error',
            '対象アンケートが見つかりません。'
        );

        redirect_to(app_url(['screen' => 'list']));
    }

    $answers = answers_for_survey($surveyId);

    $questions = flatten_questions($survey);

    $filename = 'survey-' . preg_replace(
        '/[^a-zA-Z0-9_-]/',
        '_',
        $surveyId
    ) . '-answers.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' . $filename . '"'
    );

    $out = fopen('php://output', 'wb');

    fwrite($out, "\xEF\xBB\xBF");

    $header = [
        '回答ID',
        '回答日時',
    ];

    foreach ($questions as $question) {
        $header[] = (string)($question['number'] ?? '');
        $header[] = (string)($question['text'] ?? '');
    }

    fputcsv($out, $header);

    foreach ($answers as $answer) {
        $row = [
            (string)($answer['id'] ?? ''),
            (string)($answer['createdAt'] ?? ''),
        ];

        $values = $answer['answers'] ?? [];

        foreach ($questions as $question) {
            $qid = (string)($question['id'] ?? '');

            $value = $values[$qid] ?? '';

            if (is_array($value)) {
                $value = implode(' / ', array_map('strval', $value));
            }

            $row[] = $value;
            $row[] = '';
        }

        fputcsv($out, $row);
    }

    fclose($out);
    exit;
}


/* ============================================================
 * 11. POST処理実行
 * ============================================================
 */

handle_post_action();


/* ============================================================
 * 12. 画面共通処理
 * ============================================================
 */

$screen = (string)($_GET['screen'] ?? 'list');

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

if (!in_array($screen, $allowedScreens, true)) {
    $screen = 'list';
}

$surveys = load_surveys();

$flash = get_flash();

$currentSurvey = null;

if (isset($_GET['id']) && !is_array($_GET['id'])) {
    $currentSurvey = find_survey(
        $surveys,
        (string)$_GET['id']
    );
}


/**
 * 対象ID必須画面。
 */
if (
    in_array(
        $screen,
        ['edit', 'preview', 'send', 'analytics', 'answer', 'confirm', 'complete'],
        true
    )
    && $currentSurvey === null
) {
    redirect_to(app_url(['screen' => 'list']));
}


/* ============================================================
 * 13. 一覧フィルタ
 * ============================================================
 */

$listSearch = '';

if (isset($_GET['q']) && !is_array($_GET['q'])) {
    $listSearch = trim((string)$_GET['q']);
}

$listStatus = '';

if (isset($_GET['status']) && !is_array($_GET['status'])) {
    $listStatus = trim((string)$_GET['status']);
}

$listSort = 'updated_desc';

if (isset($_GET['sort']) && !is_array($_GET['sort'])) {
    $listSort = trim((string)$_GET['sort']);
}

$filteredSurveys = array_values(
    array_filter(
        $surveys,
        static function (array $survey) use ($listSearch, $listStatus): bool {
            if ($listSearch !== '') {
                if (
                    !str_contains(
                        mb_strtolower((string)($survey['title'] ?? '')),
                        mb_strtolower($listSearch)
                    )
                ) {
                    return false;
                }
            }

            if ($listStatus !== '') {
                if ((string)($survey['status'] ?? '') !== $listStatus) {
                    return false;
                }
            }

            return true;
        }
    )
);

usort(
    $filteredSurveys,
    static function (array $a, array $b) use ($listSort): int {
        return match ($listSort) {
            'updated_asc' =>
                strcmp(
                    (string)($a['updatedAt'] ?? ''),
                    (string)($b['updatedAt'] ?? '')
                ),

            'answers_desc' =>
                answer_count((string)$b['id'])
                <=> answer_count((string)$a['id']),

            'answers_asc' =>
                answer_count((string)$a['id'])
                <=> answer_count((string)$b['id']),

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


/* ============================================================
 * 14. kintone設定表示用
 * ============================================================
 */

$settings = load_settings();

$kintoneSettings = $settings['kintone'];

$mailSettings = $settings['mail'];

$kintoneFields = $_SESSION['kintone_fields'] ?? [];

if (!is_array($kintoneFields)) {
    $kintoneFields = [];
}

$customers = load_customers();


/* ============================================================
 * 15. 回答者画面用
 * ============================================================
 */

$answerDraft = [];

if (
    isset($_SESSION['answer_draft'])
    && is_array($_SESSION['answer_draft'])
    && $currentSurvey !== null
    && (string)($_SESSION['answer_draft']['survey_id'] ?? '') ===
        (string)$currentSurvey['id']
) {
    $answerDraft = is_array(
        $_SESSION['answer_draft']['answers'] ?? null
    )
        ? $_SESSION['answer_draft']['answers']
        : [];
}


/* ============================================================
 * 16. HTML
 * ============================================================
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(APP_NAME) ?></title>

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
    --bg:#f8fafc;
    --header:#0f172a;
}

* {
    box-sizing:border-box;
}

html,
body {
    margin:0;
    padding:0;
    min-height:100%;
}

body {
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
    font-size:14px;
    line-height:1.65;
}

a {
    color:var(--primary);
    text-decoration:none;
}

a:hover {
    text-decoration:underline;
}

button,
input,
select,
textarea {
    font:inherit;
}

button {
    cursor:pointer;
}

.app-header {
    background:var(--header);
    color:#fff;
    min-height:64px;
    display:flex;
    align-items:center;
    padding:0 24px;
}

.header-inner {
    width:100%;
    max-width:1440px;
    margin:0 auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.logo {
    color:#fff;
    font-weight:700;
    font-size:18px;
    letter-spacing:.02em;
}

.header-nav {
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.header-nav a {
    color:#cbd5e1;
    padding:8px 12px;
    border-radius:8px;
}

.header-nav a:hover,
.header-nav a.active {
    background:#1e293b;
    color:#fff;
    text-decoration:none;
}

.container {
    width:100%;
    max-width:1440px;
    margin:0 auto;
    padding:28px 24px 56px;
}

.page-title {
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:20px;
    margin-bottom:24px;
}

.page-title h1 {
    margin:0;
    font-size:26px;
    line-height:1.3;
}

.page-title p {
    margin:6px 0 0;
    color:var(--gray);
}

.actions {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    align-items:center;
}

.btn {
    border:1px solid var(--border);
    background:#fff;
    color:var(--text);
    border-radius:8px;
    padding:9px 15px;
    min-height:40px;
    font-weight:600;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    text-decoration:none;
}

.btn:hover {
    text-decoration:none;
    background:#f8fafc;
}

.btn-primary {
    background:var(--primary);
    border-color:var(--primary);
    color:#fff;
}

.btn-primary:hover {
    background:var(--primary-dark);
    border-color:var(--primary-dark);
}

.btn-success {
    background:var(--success);
    border-color:var(--success);
    color:#fff;
}

.btn-warning {
    background:var(--warning);
    border-color:var(--warning);
    color:#fff;
}

.btn-danger {
    background:var(--danger);
    border-color:var(--danger);
    color:#fff;
}

.btn-small {
    padding:6px 10px;
    min-height:34px;
    font-size:13px;
}

.card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    margin-bottom:20px;
}

.card-header {
    padding:18px 20px;
    border-bottom:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
}

.card-header h2,
.card-header h3 {
    margin:0;
    font-size:17px;
}

.card-body {
    padding:20px;
}

.card-footer {
    padding:16px 20px;
    border-top:1px solid var(--border);
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

.form-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
}

.form-grid-3 {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:18px;
}

.form-group {
    margin-bottom:16px;
}

.form-group.full {
    grid-column:1/-1;
}

label {
    display:block;
    font-weight:700;
    margin-bottom:6px;
}

.required {
    color:var(--danger);
    margin-left:4px;
}

input[type="text"],
input[type="password"],
input[type="email"],
input[type="number"],
input[type="datetime-local"],
select,
textarea {
    width:100%;
    border:1px solid #cbd5e1;
    background:#fff;
    color:var(--text);
    border-radius:8px;
    padding:10px 12px;
    outline:none;
}

input:focus,
select:focus,
textarea:focus {
    border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(37,99,235,.12);
}

textarea {
    min-height:130px;
    resize:vertical;
}

.help {
    color:var(--gray);
    font-size:12px;
    margin-top:5px;
}

.alert {
    border-radius:10px;
    padding:14px 16px;
    margin-bottom:18px;
    border:1px solid;
}

.alert-success {
    background:#f0fdf4;
    color:#166534;
    border-color:#bbf7d0;
}

.alert-error {
    background:#fef2f2;
    color:#991b1b;
    border-color:#fecaca;
}

.alert-warning {
    background:#fffbeb;
    color:#92400e;
    border-color:#fde68a;
}

.alert-info {
    background:#eff6ff;
    color:#1e40af;
    border-color:#bfdbfe;
}

.alert-title {
    font-weight:800;
    margin-bottom:4px;
}

.alert ul {
    margin:8px 0 0 20px;
    padding:0;
}

.result-box {
    border:1px solid var(--border);
    border-radius:10px;
    background:#f8fafc;
    padding:16px;
    margin-top:16px;
}

.result-box.success {
    background:#f0fdf4;
    border-color:#bbf7d0;
}

.result-box.error {
    background:#fef2f2;
    border-color:#fecaca;
}

.result-title {
    font-size:16px;
    font-weight:800;
    margin-bottom:10px;
}

.result-detail {
    display:grid;
    grid-template-columns:180px minmax(0,1fr);
    border-top:1px solid rgba(100,116,139,.15);
    padding:7px 0;
}

.result-detail:first-of-type {
    border-top:0;
}

.muted {
    color:var(--gray);
}

.table-wrap {
    overflow-x:auto;
}

table {
    width:100%;
    border-collapse:collapse;
    min-width:900px;
}

th,
td {
    padding:12px 10px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:top;
}

th {
    background:#f8fafc;
    font-size:12px;
    white-space:nowrap;
}

tbody tr:hover {
    background:#f8fafc;
}

.status {
    display:inline-flex;
    align-items:center;
    padding:4px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
}

.status-success {
    color:#166534;
    background:#dcfce7;
}

.status-warning {
    color:#92400e;
    background:#fef3c7;
}

.status-muted {
    color:#475569;
    background:#e2e8f0;
}

.status-draft {
    color:#1e40af;
    background:#dbeafe;
}

.toolbar {
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
}

.search-form {
    display:flex;
    gap:8px;
    flex:1;
}

.search-form input {
    max-width:360px;
}

.filter-select {
    width:auto;
    min-width:150px;
}

.empty {
    text-align:center;
    padding:60px 20px;
    color:var(--gray);
}

.stats {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
    margin-bottom:20px;
}

.stat {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:18px;
    box-shadow:var(--shadow);
}

.stat-label {
    color:var(--gray);
    font-size:12px;
}

.stat-value {
    margin-top:4px;
    font-size:26px;
    font-weight:800;
}

.tabs {
    display:flex;
    border-bottom:1px solid var(--border);
    gap:4px;
    margin-bottom:20px;
    overflow-x:auto;
}

.tabs a {
    padding:11px 15px;
    border-bottom:3px solid transparent;
    color:var(--gray);
    white-space:nowrap;
}

.tabs a.active {
    color:var(--primary);
    border-bottom-color:var(--primary);
    font-weight:700;
}

.question-editor {
    border:1px solid var(--border);
    border-radius:10px;
    margin-bottom:14px;
    background:#fff;
}

.group-editor {
    border:2px solid #dbeafe;
    border-radius:12px;
    margin-bottom:20px;
    background:#fff;
}

.group-header {
    background:#eff6ff;
    padding:12px 14px;
    display:flex;
    gap:10px;
    align-items:center;
}

.group-header input {
    flex:1;
    font-weight:700;
}

.group-body {
    padding:14px;
}

.question-header {
    display:flex;
    gap:10px;
    align-items:center;
    padding:12px 14px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
}

.question-number {
    min-width:58px;
    font-weight:800;
    color:var(--primary);
}

.question-body {
    padding:14px;
}

.option-row {
    display:flex;
    gap:8px;
    margin-bottom:8px;
}

.option-row input {
    flex:1;
}

.drag-handle {
    cursor:grab;
    color:var(--gray);
    user-select:none;
}

.dragging {
    opacity:.5;
}

.drop-target {
    outline:2px dashed var(--primary);
    outline-offset:2px;
}

.preview-question {
    padding:18px;
    border-bottom:1px solid var(--border);
}

.preview-question:last-child {
    border-bottom:0;
}

.preview-number {
    color:var(--primary);
    font-size:12px;
    font-weight:800;
}

.preview-text {
    font-weight:700;
    font-size:16px;
    margin:5px 0 12px;
}

.choice {
    display:flex;
    align-items:center;
    gap:8px;
    margin:8px 0;
}

.choice input {
    width:18px;
    height:18px;
}

.answer-actions {
    display:flex;
    justify-content:space-between;
    gap:10px;
    margin-top:24px;
}

.mail-layout {
    display:grid;
    grid-template-columns:minmax(320px,1fr) minmax(400px,1.2fr);
    gap:20px;
}

.customer-list {
    max-height:500px;
    overflow:auto;
    border:1px solid var(--border);
    border-radius:8px;
}

.customer-row {
    display:flex;
    gap:10px;
    padding:11px 12px;
    border-bottom:1px solid var(--border);
}

.customer-row:last-child {
    border-bottom:0;
}

.customer-info {
    min-width:0;
}

.customer-name {
    font-weight:700;
}

.customer-email {
    color:var(--gray);
    font-size:12px;
    word-break:break-all;
}

.kpi-grid {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
}

.kpi {
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
}

.kpi-label {
    color:var(--gray);
    font-size:12px;
}

.kpi-value {
    font-size:24px;
    font-weight:800;
}

.mapping-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:14px;
}

.mapping-row {
    display:grid;
    grid-template-columns:140px 1fr;
    gap:10px;
    align-items:center;
}

.loading {
    display:none;
    align-items:center;
    gap:10px;
    padding:12px 14px;
    margin-top:10px;
    border-radius:8px;
    background:#eff6ff;
    color:#1e40af;
}

.loading.show {
    display:flex;
}

.spinner {
    width:18px;
    height:18px;
    border:3px solid #bfdbfe;
    border-top-color:var(--primary);
    border-radius:50%;
    animation:spin .8s linear infinite;
}

@keyframes spin {
    to {
        transform:rotate(360deg);
    }
}

.mobile-only {
    display:none;
}

.answer-container {
    max-width:820px;
    margin:0 auto;
}

.answer-header {
    text-align:center;
    margin-bottom:24px;
}

.answer-header h1 {
    font-size:26px;
    margin-bottom:8px;
}

.confirm-answer {
    display:grid;
    grid-template-columns:180px 1fr;
    gap:0;
}

.confirm-answer > div {
    padding:12px;
    border-bottom:1px solid var(--border);
}

.confirm-label {
    background:#f8fafc;
    font-weight:700;
}

.footer {
    text-align:center;
    color:var(--gray);
    font-size:12px;
    padding:30px 20px;
}

@media (max-width:1000px) {
    .form-grid,
    .form-grid-3,
    .mapping-grid {
        grid-template-columns:1fr;
    }

    .stats,
    .kpi-grid {
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .mail-layout {
        grid-template-columns:1fr;
    }
}

@media (max-width:700px) {
    .app-header {
        padding:0 14px;
    }

    .header-inner {
        align-items:flex-start;
        padding:10px 0;
        flex-direction:column;
    }

    .header-nav {
        width:100%;
        overflow-x:auto;
        flex-wrap:nowrap;
    }

    .container {
        padding:20px 14px 40px;
    }

    .page-title {
        flex-direction:column;
    }

    .page-title .actions {
        width:100%;
    }

    .page-title .actions .btn {
        flex:1;
    }

    .stats,
    .kpi-grid {
        grid-template-columns:1fr 1fr;
    }

    .card-body {
        padding:15px;
    }

    .result-detail {
        grid-template-columns:1fr;
        gap:2px;
    }

    .confirm-answer {
        grid-template-columns:1fr;
    }

    .answer-actions {
        position:sticky;
        bottom:0;
        background:#fff;
        padding:10px 0;
    }

    .mobile-only {
        display:block;
    }
}
</style>
</head>

<body>

<?php if ($screen !== 'answer' && $screen !== 'confirm' && $screen !== 'complete'): ?>

<header class="app-header">
    <div class="header-inner">

        <a
            href="<?= h(app_url(['screen' => 'list'])) ?>"
            class="logo"
        >
            <?= h(APP_NAME) ?>
        </a>

        <nav class="header-nav">

            <a
                href="<?= h(app_url(['screen' => 'list'])) ?>"
                class="<?= $screen === 'list' || $screen === 'edit' || $screen === 'preview' || $screen === 'analytics' || $screen === 'send' ? 'active' : '' ?>"
            >
                アンケート
            </a>

            <a
                href="<?= h(app_url(['screen' => 'kintone'])) ?>"
                class="<?= $screen === 'kintone' ? 'active' : '' ?>"
            >
                kintone
            </a>

            <a
                href="<?= h(app_url(['screen' => 'mail'])) ?>"
                class="<?= $screen === 'mail' ? 'active' : '' ?>"
            >
                メール
            </a>

        </nav>

    </div>
</header>

<?php endif; ?>


<main class="container">

<?php if ($flash !== null): ?>

<div class="alert <?= $flash['type'] === 'success'
    ? 'alert-success'
    : ($flash['type'] === 'warning'
        ? 'alert-warning'
        : 'alert-error') ?>">

    <div class="alert-title">
        <?= $flash['type'] === 'success' ? '✓ 完了' : '✕ ' . ($flash['type'] === 'warning' ? '注意' : '失敗') ?>
    </div>

    <div>
        <?= h($flash['message']) ?>
    </div>

    <?php if (!empty($flash['details']) && is_array($flash['details'])): ?>

        <div class="result-box <?= $flash['type'] === 'success' ? 'success' : 'error' ?>">

            <?php foreach ($flash['details'] as $key => $value): ?>

                <div class="result-detail">
                    <strong><?= h($key) ?></strong>
                    <span><?= h(is_array($value) ? implode(', ', $value) : $value) ?></span>
                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>

<?php endif; ?>


<?php
/* ============================================================
 * 17. 一覧
 * ============================================================
 */
?>

<?php if ($screen === 'list'): ?>

<div class="page-title">

    <div>
        <h1>アンケート一覧</h1>
        <p>アンケートの作成・公開・集計・送信を管理します。</p>
    </div>

    <div class="actions">

        <a
            class="btn btn-primary"
            href="<?= h(app_url(['screen' => 'edit'])) ?>"
        >
            ＋ 新規作成
        </a>

    </div>

</div>


<div class="card">

    <div class="card-body">

        <form
            method="get"
            class="toolbar"
        >

            <input
                type="hidden"
                name="screen"
                value="list"
            >

            <div class="search-form">

                <input
                    type="text"
                    name="q"
                    value="<?= h($listSearch) ?>"
                    placeholder="アンケートタイトルを検索"
                >

                <button
                    class="btn"
                    type="submit"
                >
                    検索
                </button>

            </div>

            <select
                name="status"
                class="filter-select"
                onchange="this.form.submit()"
            >
                <option value="">すべて</option>
                <option value="published" <?= $listStatus === 'published' ? 'selected' : '' ?>>公開中</option>
                <option value="draft" <?= $listStatus === 'draft' ? 'selected' : '' ?>>下書き</option>
                <option value="stopped" <?= $listStatus === 'stopped' ? 'selected' : '' ?>>停止</option>
                <option value="ended" <?= $listStatus === 'ended' ? 'selected' : '' ?>>終了</option>
            </select>

            <select
                name="sort"
                class="filter-select"
                onchange="this.form.submit()"
            >
                <option value="updated_desc" <?= $listSort === 'updated_desc' ? 'selected' : '' ?>>更新日：新しい順</option>
                <option value="updated_asc" <?= $listSort === 'updated_asc' ? 'selected' : '' ?>>更新日：古い順</option>
                <option value="answers_desc" <?= $listSort === 'answers_desc' ? 'selected' : '' ?>>回答数：多い順</option>
                <option value="answers_asc" <?= $listSort === 'answers_asc' ? 'selected' : '' ?>>回答数：少ない順</option>
                <option value="start_desc" <?= $listSort === 'start_desc' ? 'selected' : '' ?>>開始日：新しい順</option>
                <option value="start_asc" <?= $listSort === 'start_asc' ? 'selected' : '' ?>>開始日：古い順</option>
            </select>

        </form>

    </div>

</div>


<div class="card">

    <div class="card-header">

        <h2>アンケート <?= count($filteredSurveys) ?>件</h2>

    </div>

    <div class="table-wrap">

        <?php if ($filteredSurveys === []): ?>

            <div class="empty">
                該当するアンケートはありません。
            </div>

        <?php else: ?>

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

            <?php foreach ($filteredSurveys as $survey): ?>

                <?php
                $sid = (string)$survey['id'];
                $status = (string)($survey['status'] ?? 'draft');
                ?>

                <tr>

                    <td>
                        <strong><?= h($survey['title'] ?? '') ?></strong>

                        <?php if (!empty($survey['description'])): ?>
                            <div class="muted">
                                <?= h(mb_strimwidth(
                                    (string)$survey['description'],
                                    0,
                                    80,
                                    '…',
                                    'UTF-8'
                                )) ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?= h(format_datetime((string)($survey['createdAt'] ?? ''))) ?>
                    </td>

                    <td>
                        <?= h(format_datetime((string)($survey['updatedAt'] ?? ''))) ?>
                    </td>

                    <td>
                        <?= h(format_datetime((string)($survey['startAt'] ?? ''))) ?>
                        <br>
                        ～
                        <?= h(format_datetime((string)($survey['endAt'] ?? ''))) ?>
                    </td>

                    <td>
                        <span class="status <?= h(survey_status_class($status)) ?>">
                            <?= h(survey_status_label($status)) ?>
                        </span>
                    </td>

                    <td>
                        <?= answer_count($sid) ?>件
                    </td>

                    <td>

                        <div class="actions">

                            <a
                                class="btn btn-small"
                                href="<?= h(app_url([
                                    'screen' => 'edit',
                                    'id' => $sid,
                                ])) ?>"
                            >
                                確認・編集
                            </a>

                            <a
                                class="btn btn-small"
                                href="<?= h(app_url([
                                    'screen' => 'analytics',
                                    'id' => $sid,
                                ])) ?>"
                            >
                                集計
                            </a>

                            <a
                                class="btn btn-small"
                                href="<?= h(app_url([
                                    'screen' => 'send',
                                    'id' => $sid,
                                ])) ?>"
                            >
                                送信
                            </a>

                            <?php if ($status !== 'ended'): ?>

                                <?php if ($status === 'draft'): ?>

                                    <form method="post">
                                        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="change_status">
                                        <input type="hidden" name="id" value="<?= h($sid) ?>">
                                        <input type="hidden" name="new_status" value="published">

                                        <button
                                            type="submit"
                                            class="btn btn-small btn-success"
                                            data-confirm="このアンケートを公開しますか？"
                                        >
                                            公開
                                        </button>
                                    </form>

                                <?php elseif ($status === 'published'): ?>

                                    <form method="post">
                                        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="change_status">
                                        <input type="hidden" name="id" value="<?= h($sid) ?>">
                                        <input type="hidden" name="new_status" value="stopped">

                                        <button
                                            type="submit"
                                            class="btn btn-small btn-warning"
                                            data-confirm="このアンケートを停止しますか？"
                                        >
                                            停止
                                        </button>
                                    </form>

                                <?php elseif ($status === 'stopped'): ?>

                                    <form method="post">
                                        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="change_status">
                                        <input type="hidden" name="id" value="<?= h($sid) ?>">
                                        <input type="hidden" name="new_status" value="published">

                                        <button
                                            type="submit"
                                            class="btn btn-small btn-success"
                                            data-confirm="このアンケートを再開しますか？"
                                        >
                                            再開
                                        </button>
                                    </form>

                                <?php endif; ?>

                            <?php endif; ?>

                            <form method="post">

                                <input
                                    type="hidden"
                                    name="_csrf"
                                    value="<?= h(csrf_token()) ?>"
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
                                    type="submit"
                                    class="btn btn-small"
                                    data-confirm="このアンケートを複製しますか？"
                                >
                                    複製
                                </button>

                            </form>

                            <form method="post">

                                <input
                                    type="hidden"
                                    name="_csrf"
                                    value="<?= h(csrf_token()) ?>"
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
                                    type="submit"
                                    class="btn btn-small btn-danger"
                                    data-confirm="このアンケートを削除しますか？この操作は元に戻せません。"
                                >
                                    削除
                                </button>

                            </form>

                        </div>

                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

        <?php endif; ?>

    </div>

</div>


<?php
/* ============================================================
 * 18. 編集
 * ============================================================
 */
?>

<?php elseif ($screen === 'edit'): ?>

<?php

$isNew = $currentSurvey === null;

if ($isNew) {
    $editSurvey = [
        'id' => '',
        'title' => '',
        'description' => '',
        'startAt' => date('Y-m-d 00:00:00'),
        'endAt' => date('Y-m-d 23:59:59', strtotime('+30 days')),
        'status' => 'draft',
        'numbering' => 'global',
        'groups' => [
            [
                'id' => generate_id('group'),
                'title' => '基本情報',
                'questions' => [],
            ],
        ],
    ];
} else {
    $editSurvey = $currentSurvey;
}

?>

<div class="page-title">

    <div>
        <h1>
            <?= $isNew ? 'アンケート作成' : 'アンケート編集' ?>
        </h1>

        <p>
            質問、回答形式、必須設定、条件分岐を設定します。
        </p>
    </div>

    <div class="actions">

        <a
            class="btn"
            href="<?= h(app_url(['screen' => 'list'])) ?>"
        >
            キャンセル
        </a>

        <a
            class="btn"
            href="<?= h(app_url([
                'screen' => 'preview',
                'id' => $editSurvey['id'],
            ])) ?>"
            <?= $isNew ? 'style="display:none"' : '' ?>
        >
            プレビュー
        </a>

    </div>

</div>


<form
    method="post"
    id="surveyForm"
    onsubmit="return prepareSurveyForm();"
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
    value="<?= h($editSurvey['id']) ?>"
>

<div class="card">

    <div class="card-header">

        <h2>基本設定</h2>

        <div class="status <?= h(survey_status_class((string)$editSurvey['status'])) ?>">
            <?= h(survey_status_label((string)$editSurvey['status'])) ?>
        </div>

    </div>

    <div class="card-body">

        <div class="form-grid">

            <div class="form-group full">

                <label>
                    アンケートタイトル
                    <span class="required">*</span>
                </label>

                <input
                    type="text"
                    name="title"
                    maxlength="200"
                    required
                    value="<?= h($editSurvey['title']) ?>"
                >

            </div>

            <div class="form-group full">

                <label>アンケート説明</label>

                <textarea
                    name="description"
                ><?= h($editSurvey['description']) ?></textarea>

            </div>

            <div class="form-group">

                <label>開始日時</label>

                <input
                    type="datetime-local"
                    name="start_at"
                    value="<?= h(
                        $editSurvey['startAt'] !== ''
                            ? date('Y-m-d\TH:i', strtotime($editSurvey['startAt']))
                            : ''
                    ) ?>"
                >

            </div>

            <div class="form-group">

                <label>終了日時</label>

                <input
                    type="datetime-local"
                    name="end_at"
                    value="<?= h(
                        $editSurvey['endAt'] !== ''
                            ? date('Y-m-d\TH:i', strtotime($editSurvey['endAt']))
                            : ''
                    ) ?>"
                >

            </div>

            <div class="form-group">

                <label>質問番号の採番方式</label>

                <select name="numbering">

                    <option
                        value="global"
                        <?= ($editSurvey['numbering'] ?? 'global') === 'global' ? 'selected' : '' ?>
                    >
                        アンケート全体で通番（Q1、Q2、Q3…）
                    </option>

                    <option
                        value="group"
                        <?= ($editSurvey['numbering'] ?? '') === 'group' ? 'selected' : '' ?>
                    >
                        グループ毎（Q1-1、Q1-2、Q2-1…）
                    </option>

                </select>

            </div>

            <div class="form-group">

                <label>状態</label>

                <?php if (($editSurvey['status'] ?? '') === 'ended'): ?>

                    <input
                        type="hidden"
                        name="status"
                        value="ended"
                    >

                    <input
                        type="text"
                        value="終了"
                        disabled
                    >

                    <div class="help">
                        終了状態は自動設定され、手動変更できません。
                    </div>

                <?php else: ?>

                    <select name="status">

                        <option
                            value="draft"
                            <?= ($editSurvey['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>
                        >
                            下書き
                        </option>

                        <option
                            value="published"
                            <?= ($editSurvey['status'] ?? '') === 'published' ? 'selected' : '' ?>
                        >
                            公開中
                        </option>

                        <option
                            value="stopped"
                            <?= ($editSurvey['status'] ?? '') === 'stopped' ? 'selected' : '' ?>
                        >
                            停止
                        </option>

                    </select>

                    <div class="help">
                        終了は終了日時を過ぎた公開中アンケートに自動設定されます。
                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>

</div>


<div class="card">

    <div class="card-header">

        <h2>質問・グループ</h2>

    </div>

    <div class="card-body" id="groupsContainer">

        <?php foreach (($editSurvey['groups'] ?? []) as $gIndex => $group): ?>

            <div
                class="group-editor"
                draggable="true"
                data-group
                data-group-index="<?= $gIndex ?>"
            >

                <div class="group-header">

                    <span class="drag-handle">
                        ↕
                    </span>

                    <input
                        type="text"
                        data-group-title
                        value="<?= h($group['title'] ?? 'グループ') ?>"
                    >

                    <button
                        type="button"
                        class="btn btn-small btn-danger"
                        onclick="deleteGroup(this)"
                    >
                        グループ削除
                    </button>

                </div>

                <div class="group-body">

                    <div class="questions-container">

                        <?php foreach (($group['questions'] ?? []) as $qIndex => $question): ?>

                            <div
                                class="question-editor"
                                draggable="true"
                                data-question
                            >

                                <div class="question-header">

                                    <span class="drag-handle">
                                        ↕
                                    </span>

                                    <span
                                        class="question-number"
                                        data-question-number
                                    >
                                        <?= h($question['number'] ?? 'Q') ?>
                                    </span>

                                    <strong>
                                        質問
                                    </strong>

                                    <button
                                        type="button"
                                        class="btn btn-small btn-danger"
                                        onclick="deleteQuestion(this)"
                                        style="margin-left:auto"
                                    >
                                        質問削除
                                    </button>

                                </div>

                                <div class="question-body">

                                    <div class="form-group">

                                        <label>質問文</label>

                                        <input
                                            type="text"
                                            data-question-text
                                            value="<?= h($question['text'] ?? '') ?>"
                                        >

                                    </div>

                                    <div class="form-grid">

                                        <div class="form-group">

                                            <label>回答形式</label>

                                            <select
                                                data-question-type
                                                onchange="changeQuestionType(this)"
                                            >

                                                <option
                                                    value="single"
                                                    <?= ($question['type'] ?? '') === 'single' ? 'selected' : '' ?>
                                                >
                                                    単一選択
                                                </option>

                                                <option
                                                    value="multiple"
                                                    <?= ($question['type'] ?? '') === 'multiple' ? 'selected' : '' ?>
                                                >
                                                    複数選択
                                                </option>

                                                <option
                                                    value="text"
                                                    <?= ($question['type'] ?? '') === 'text' ? 'selected' : '' ?>
                                                >
                                                    自由記述
                                                </option>

                                            </select>

                                        </div>

                                        <div class="form-group">

                                            <label>必須設定</label>

                                            <label class="choice">

                                                <input
                                                    type="checkbox"
                                                    data-question-required
                                                    <?= !empty($question['required']) ? 'checked' : '' ?>
                                                >

                                                必須回答

                                            </label>

                                        </div>

                                    </div>

                                    <div
                                        data-options
                                        style="<?= ($question['type'] ?? '') === 'text' ? 'display:none' : '' ?>"
                                    >

                                        <label>選択肢</label>

                                        <?php foreach (($question['options'] ?? []) as $option): ?>

                                            <div class="option-row">

                                                <input
                                                    type="text"
                                                    data-option
                                                    value="<?= h($option['label'] ?? '') ?>"
                                                >

                                                <button
                                                    type="button"
                                                    class="btn btn-small btn-danger"
                                                    onclick="this.parentElement.remove();"
                                                >
                                                    削除
                                                </button>

                                            </div>

                                        <?php endforeach; ?>

                                        <button
                                            type="button"
                                            class="btn btn-small"
                                            onclick="addOption(this)"
                                        >
                                            ＋ 選択肢追加
                                        </button>

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                    <button
                        type="button"
                        class="btn btn-small"
                        onclick="addQuestion(this)"
                    >
                        ＋ 質問を追加
                    </button>

                </div>

            </div>

        <?php endforeach; ?>

        <button
            type="button"
            class="btn btn-primary"
            onclick="addGroup()"
        >
            ＋ グループを追加
        </button>

    </div>

</div>


<div class="card">

    <div class="card-footer">

        <a
            class="btn"
            href="<?= h(app_url(['screen' => 'list'])) ?>"
        >
            キャンセル
        </a>

        <button
            type="submit"
            class="btn btn-primary"
        >
            保存して一覧へ
        </button>

    </div>

</div>

</form>


<?php
/* ============================================================
 * 19. プレビュー
 * ============================================================
 */
?>

<?php elseif ($screen === 'preview'): ?>

<div class="page-title">

    <div>

        <h1>プレビュー</h1>

        <p>
            <?= h($currentSurvey['title']) ?>
        </p>

    </div>

    <div class="actions">

        <a
            class="btn"
            href="<?= h(app_url([
                'screen' => 'edit',
                'id' => $currentSurvey['id'],
            ])) ?>"
        >
            編集へ戻る
        </a>

    </div>

</div>


<div class="answer-container">

<div class="card">

    <div class="card-body">

        <div class="answer-header">

            <h1><?= h($currentSurvey['title']) ?></h1>

            <?php if ($currentSurvey['description'] !== ''): ?>

                <p class="muted">
                    <?= nl2br(h($currentSurvey['description'])) ?>
                </p>

            <?php endif; ?>

        </div>


        <?php foreach ($currentSurvey['groups'] as $group): ?>

            <h2>
                <?= h($group['title']) ?>
            </h2>

            <?php foreach ($group['questions'] as $question): ?>

                <div class="preview-question">

                    <div class="preview-number">
                        <?= h($question['number'] ?? '') ?>
                        <?= !empty($question['required']) ? ' 必須' : ' 任意' ?>
                    </div>

                    <div class="preview-text">
                        <?= h($question['text'] ?? '') ?>
                    </div>

                    <?php if (($question['type'] ?? '') === 'text'): ?>

                        <textarea
                            disabled
                            placeholder="自由記述"
                        ></textarea>

                    <?php else: ?>

                        <?php foreach (($question['options'] ?? []) as $option): ?>

                            <label class="choice">

                                <input
                                    type="<?= ($question['type'] ?? '') === 'multiple' ? 'checkbox' : 'radio' ?>"
                                    disabled
                                >

                                <?= h($option['label'] ?? '') ?>

                            </label>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php endforeach; ?>

    </div>

</div>

</div>


<?php
/* ============================================================
 * 20. kintone設定
 * ============================================================
 */
?>

<?php elseif ($screen === 'kintone'): ?>

<div class="page-title">

    <div>

        <h1>kintone連携設定</h1>

        <p>
            kintoneを顧客情報の取得元として設定します。
        </p>

    </div>

</div>


<div class="card">

    <div class="card-header">

        <h2>kintone設定</h2>

        <?php if (!empty($kintoneSettings['last_test']['ok'])): ?>

            <span class="status status-success">
                接続確認済み
            </span>

        <?php else: ?>

            <span class="status status-muted">
                未確認
            </span>

        <?php endif; ?>

    </div>

    <div class="card-body">

        <form
            method="post"
            id="kintoneForm"
        >

            <input
                type="hidden"
                name="_csrf"
                value="<?= h(csrf_token()) ?>"
            >

            <div class="form-grid">

                <div class="form-group">

                    <label>
                        サブドメイン
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        name="subdomain"
                        value="<?= h($kintoneSettings['subdomain']) ?>"
                        placeholder="xxxx.cybozu.com"
                        required
                    >

                    <div class="help">
                        https://xxxx.cybozu.com、xxxx.cybozu.com、xxxx のいずれでも入力できます。
                    </div>

                </div>

                <div class="form-group">

                    <label>
                        顧客管理アプリID
                        <span class="required">*</span>
                    </label>

                    <input
                        type="number"
                        name="app_id"
                        min="1"
                        value="<?= h($kintoneSettings['app_id']) ?>"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>
                        ログイン名
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        name="login_name"
                        value="<?= h($kintoneSettings['login_name']) ?>"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>
                        パスワード
                        <span class="required">*</span>
                    </label>

                    <input
                        type="password"
                        name="password"
                        value=""
                        autocomplete="new-password"
                        placeholder="変更しない場合は空欄"
                    >

                    <div class="help">
                        保存済みパスワードを変更しない場合は空欄のままにしてください。
                    </div>

                </div>

                <div class="form-group full">

                    <label>Proxy</label>

                    <input
                        type="text"
                        name="proxy"
                        value="<?= h($kintoneSettings['proxy']) ?>"
                        placeholder="proxy.example.local:8080"
                    >

                    <div class="help">
                        Proxyを使用しない場合は空欄。使用する場合は「host:port」の1項目だけです。
                    </div>

                </div>

                <div class="form-group full">

                    <label>SSL証明書検証</label>

                    <label class="choice">

                        <input
                            type="checkbox"
                            name="verify_ssl"
                            value="1"
                            <?= !empty($kintoneSettings['verify_ssl']) ? 'checked' : '' ?>
                        >

                        SSL証明書を検証する

                    </label>

                    <div class="help">
                        POCでは無効を基本とします。安全な本番環境では有効化してください。
                    </div>

                </div>

            </div>


            <div class="actions">

                <button
                    type="submit"
                    class="btn btn-primary"
                    name="action"
                    value="save_kintone_settings"
                >
                    設定保存
                </button>

                <button
                    type="submit"
                    class="btn"
                    name="action"
                    value="test_kintone"
                    data-loading-button
                >
                    接続テスト
                </button>

            </div>

            <div
                class="loading"
                data-loading
            >
                <span class="spinner"></span>
                <span>
                    kintoneへ接続しています。しばらくお待ちください…
                </span>
            </div>

        </form>

    </div>

</div>


<?php if (!empty($kintoneSettings['last_test'])): ?>

<div class="card">

    <div class="card-header">

        <h2>最後の接続テスト結果</h2>

        <span class="muted">
            <?= h(format_datetime(
                (string)($kintoneSettings['last_test']['at'] ?? '')
            )) ?>
        </span>

    </div>

    <div class="card-body">

        <?php $lastTest = $kintoneSettings['last_test']; ?>

        <div class="result-box <?= !empty($lastTest['ok']) ? 'success' : 'error' ?>">

            <div class="result-title">

                <?= !empty($lastTest['ok'])
                    ? '✓ 接続成功'
                    : '✕ 接続失敗' ?>

            </div>

            <div>
                <?= h($lastTest['message'] ?? '') ?>
            </div>

            <?php if (!empty($lastTest['details']) && is_array($lastTest['details'])): ?>

                <div style="margin-top:12px">

                    <?php foreach ($lastTest['details'] as $key => $value): ?>

                        <div class="result-detail">

                            <strong>
                                <?= h($key) ?>
                            </strong>

                            <span>
                                <?= h(is_array($value)
                                    ? implode(', ', $value)
                                    : $value) ?>
                            </span>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<?php endif; ?>


<div class="card">

    <div class="card-header">

        <h2>kintone項目・顧客同期</h2>

    </div>

    <div class="card-body">

        <div class="actions">

            <form method="post">

                <input
                    type="hidden"
                    name="_csrf"
                    value="<?= h(csrf_token()) ?>"
                >

                <button
                    type="submit"
                    name="action"
                    value="refresh_kintone_fields"
                    class="btn"
                >
                    項目一覧を再取得
                </button>

            </form>

        </div>


        <?php if ($kintoneFields !== []): ?>

        <div style="margin-top:20px">

            <div class="alert alert-info">

                kintone項目を
                <?= count($kintoneFields) ?>件
                取得しています。

            </div>

        </div>


        <form method="post">

            <input
                type="hidden"
                name="_csrf"
                value="<?= h(csrf_token()) ?>"
            >

            <div class="mapping-grid">

                <?php
                $mappingFields = [
                    'map_organization' => '組織名',
                    'map_name'         => '氏名',
                    'map_email'        => 'メールアドレス',
                    'map_department'   => '部署名',
                    'map_phone'        => '電話番号',
                ];
                ?>

                <?php foreach ($mappingFields as $fieldName => $label): ?>

                    <div class="mapping-row">

                        <strong><?= h($label) ?></strong>

                        <select name="<?= h($fieldName) ?>">

                            <option value="">
                                -- 未指定 --
                            </option>

                            <?php foreach ($kintoneFields as $field): ?>

                                <option
                                    value="<?= h($field['code']) ?>"
                                >
                                    <?= h(
                                        $field['label']
                                        . ' [' . $field['code'] . ']'
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                <?php endforeach; ?>

            </div>


            <div style="margin-top:18px">

                <strong>
                    住所マッピング
                </strong>

                <div class="help">
                    複数のフィールドを選択できます。
                </div>

                <div
                    style="
                        display:grid;
                        grid-template-columns:repeat(auto-fill,minmax(220px,1fr));
                        gap:8px;
                        margin-top:10px;
                    "
                >

                    <?php foreach ($kintoneFields as $field): ?>

                        <label class="choice">

                            <input
                                type="checkbox"
                                name="map_address[]"
                                value="<?= h($field['code']) ?>"
                            >

                            <?= h($field['label']) ?>

                        </label>

                    <?php endforeach; ?>

                </div>

            </div>


            <div style="margin-top:20px">

                <button
                    type="submit"
                    name="action"
                    value="sync_kintone"
                    class="btn btn-primary"
                    data-confirm="kintoneから顧客情報を取得して同期しますか？"
                    data-loading-button
                >
                    顧客情報を同期
                </button>

            </div>

            <div
                class="loading"
                data-loading
            >
                <span class="spinner"></span>
                <span>
                    kintoneから顧客情報を取得・同期しています…
                </span>
            </div>

        </form>

        <?php else: ?>

            <div class="empty">
                まず「項目一覧を再取得」を実行してください。
            </div>

        <?php endif; ?>

    </div>

</div>


<div class="card">

    <div class="card-header">

        <h2>同期状態</h2>

    </div>

    <div class="card-body">

        <div class="kpi-grid">

            <div class="kpi">

                <div class="kpi-label">
                    現在の顧客件数
                </div>

                <div class="kpi-value">
                    <?= customer_count() ?>
                </div>

            </div>

            <div class="kpi">

                <div class="kpi-label">
                    最終同期
                </div>

                <div class="kpi-value" style="font-size:16px">

                    <?= !empty($kintoneSettings['last_sync']['at'])
                        ? h(format_datetime(
                            (string)$kintoneSettings['last_sync']['at']
                        ))
                        : '未実行' ?>

                </div>

            </div>

            <div class="kpi">

                <div class="kpi-label">
                    最終同期件数
                </div>

                <div class="kpi-value">

                    <?= h(
                        (string)(
                            $kintoneSettings['last_sync']['count']
                            ?? 0
                        )
                    ) ?>

                </div>

            </div>

            <div class="kpi">

                <div class="kpi-label">
                    接続テスト
                </div>

                <div class="kpi-value" style="font-size:16px">

                    <?= !empty($kintoneSettings['last_test']['ok'])
                        ? '成功'
                        : '未確認' ?>

                </div>

            </div>

        </div>

    </div>

</div>


<?php
/* ============================================================
 * 21. メール設定
 * ============================================================
 */
?>

<?php elseif ($screen === 'mail'): ?>

<div class="page-title">

    <div>

        <h1>メールサーバ設定</h1>

        <p>
            SMTPサーバを設定します。PHP mail()は使用しません。
        </p>

    </div>

</div>


<div class="card">

    <div class="card-header">

        <h2>SMTP設定</h2>

        <span class="status status-muted">
            <?= h($mailSettings['status'] ?? '未設定') ?>
        </span>

    </div>

    <div class="card-body">

        <form method="post">

            <input
                type="hidden"
                name="_csrf"
                value="<?= h(csrf_token()) ?>"
            >

            <div class="form-grid">

                <div class="form-group">

                    <label>
                        SMTPサーバ
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        name="smtp_host"
                        value="<?= h($mailSettings['smtp_host']) ?>"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>
                        SMTPポート
                        <span class="required">*</span>
                    </label>

                    <input
                        type="number"
                        name="smtp_port"
                        min="1"
                        max="65535"
                        value="<?= h($mailSettings['smtp_port']) ?>"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>
                        暗号化方式
                    </label>

                    <select name="encryption">

                        <option
                            value="ssl"
                            <?= $mailSettings['encryption'] === 'ssl' ? 'selected' : '' ?>
                        >
                            SSL
                        </option>

                        <option
                            value="tls"
                            <?= $mailSettings['encryption'] === 'tls' ? 'selected' : '' ?>
                        >
                            TLS
                        </option>

                        <option
                            value="none"
                            <?= $mailSettings['encryption'] === 'none' ? 'selected' : '' ?>
                        >
                            なし
                        </option>

                    </select>

                </div>

                <div class="form-group">

                    <label>
                        SMTP認証
                    </label>

                    <label class="choice">

                        <input
                            type="checkbox"
                            name="auth"
                            value="1"
                            <?= !empty($mailSettings['auth']) ? 'checked' : '' ?>
                        >

                        SMTP認証を使用する

                    </label>

                </div>

                <div class="form-group">

                    <label>
                        SMTPユーザー名
                    </label>

                    <input
                        type="text"
                        name="username"
                        value="<?= h($mailSettings['username']) ?>"
                    >

                </div>

                <div class="form-group">

                    <label>
                        SMTPパスワード
                    </label>

                    <input
                        type="password"
                        name="password"
                        autocomplete="new-password"
                        placeholder="変更しない場合は空欄"
                    >

                </div>

                <div class="form-group">

                    <label>
                        送信元メールアドレス
                    </label>

                    <input
                        type="email"
                        name="from_email"
                        value="<?= h($mailSettings['from_email']) ?>"
                    >

                </div>

                <div class="form-group">

                    <label>
                        送信元名
                    </label>

                    <input
                        type="text"
                        name="from_name"
                        value="<?= h($mailSettings['from_name']) ?>"
                    >

                </div>

                <div class="form-group">

                    <label>
                        返信先メールアドレス
                    </label>

                    <input
                        type="email"
                        name="reply_to"
                        value="<?= h($mailSettings['reply_to']) ?>"
                    >

                </div>

            </div>


            <div class="actions">

                <button
                    type="submit"
                    name="action"
                    value="save_mail_settings"
                    class="btn btn-primary"
                >
                    設定保存
                </button>

                <button
                    type="submit"
                    name="action"
                    value="test_smtp"
                    class="btn"
                    data-loading-button
                >
                    接続テスト
                </button>

            </div>

            <div
                class="loading"
                data-loading
            >
                <span class="spinner"></span>
                <span>
                    SMTPサーバへ接続しています…
                </span>
            </div>

        </form>

    </div>

</div>


<?php
/* ============================================================
 * 22. 集計
 * ============================================================
 */
?>

<?php elseif ($screen === 'analytics'): ?>

<?php

$surveyId = (string)$currentSurvey['id'];
$surveyAnswers = answers_for_survey($surveyId);
$sendHistory = load_send_history();

$surveySendHistory = array_values(
    array_filter(
        $sendHistory,
        static function ($row) use ($surveyId): bool {
            return (string)($row['surveyId'] ?? '') === $surveyId;
        }
    )
);

$sentTargets = count($surveySendHistory);
$answerTotal = count($surveyAnswers);

$unanswered = max(
    0,
    $sentTargets - $answerTotal
);

$answerRate = $sentTargets > 0
    ? round(($answerTotal / $sentTargets) * 100, 1)
    : 0;

?>

<div class="page-title">

    <div>

        <h1>回答集計・分析</h1>

        <p>
            対象アンケート：
            <strong><?= h($currentSurvey['title']) ?></strong>
        </p>

    </div>

    <div class="actions">

        <form method="post">

            <input
                type="hidden"
                name="_csrf"
                value="<?= h(csrf_token()) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="export_csv"
            >

            <input
                type="hidden"
                name="survey_id"
                value="<?= h($surveyId) ?>"
            >

            <button
                class="btn"
                type="submit"
            >
                CSV出力
            </button>

        </form>

    </div>

</div>


<div class="stats">

    <div class="stat">

        <div class="stat-label">
            送信対象者数
        </div>

        <div class="stat-value">
            <?= $sentTargets ?>
        </div>

    </div>

    <div class="stat">

        <div class="stat-label">
            回答数
        </div>

        <div class="stat-value">
            <?= $answerTotal ?>
        </div>

    </div>

    <div class="stat">

        <div class="stat-label">
            未回答数
        </div>

        <div class="stat-value">
            <?= $unanswered ?>
        </div>

    </div>

    <div class="stat">

        <div class="stat-label">
            回答率
        </div>

        <div class="stat-value">
            <?= h($answerRate) ?>%
        </div>

    </div>

</div>


<div class="card">

    <div class="card-header">

        <h2>設問別集計</h2>

    </div>

    <div class="card-body">

        <?php if ($answerTotal === 0): ?>

            <div class="empty">
                現在、回答データはありません
            </div>

        <?php else: ?>

            <?php foreach ($currentSurvey['groups'] as $group): ?>

                <h3>
                    <?= h($group['title']) ?>
                </h3>

                <?php foreach ($group['questions'] as $question): ?>

                    <?php
                    $qid = (string)$question['id'];

                    $counts = [];

                    foreach (($question['options'] ?? []) as $option) {
                        $counts[(string)$option['id']] = 0;
                    }

                    $textAnswers = [];

                    foreach ($surveyAnswers as $answer) {

                        $value = $answer['answers'][$qid] ?? null;

                        if (is_array($value)) {

                            foreach ($value as $selected) {
                                $selected = (string)$selected;

                                if (isset($counts[$selected])) {
                                    $counts[$selected]++;
                                }
                            }

                        } elseif ($value !== null && $value !== '') {

                            $valueString = (string)$value;

                            if (isset($counts[$valueString])) {
                                $counts[$valueString]++;
                            } else {
                                $textAnswers[] = $valueString;
                            }
                        }
                    }
                    ?>

                    <div class="card" style="box-shadow:none">

                        <div class="card-body">

                            <strong>
                                <?= h($question['number'] ?? '') ?>
                                <?= h($question['text'] ?? '') ?>
                            </strong>

                            <?php if (($question['type'] ?? '') === 'text'): ?>

                                <?php if ($textAnswers === []): ?>

                                    <div class="muted" style="margin-top:10px">
                                        回答なし
                                    </div>

                                <?php else: ?>

                                    <div style="margin-top:10px">

                                        <?php foreach ($textAnswers as $text): ?>

                                            <div
                                                style="
                                                    padding:10px;
                                                    background:#f8fafc;
                                                    border:1px solid var(--border);
                                                    border-radius:8px;
                                                    margin-bottom:7px;
                                                "
                                            >
                                                <?= nl2br(h($text)) ?>
                                            </div>

                                        <?php endforeach; ?>

                                    </div>

                                <?php endif; ?>

                            <?php else: ?>

                                <div style="margin-top:12px">

                                    <?php foreach (($question['options'] ?? []) as $option): ?>

                                        <?php
                                        $optionId = (string)$option['id'];
                                        $count = $counts[$optionId] ?? 0;
                                        $percent = $answerTotal > 0
                                            ? round(($count / $answerTotal) * 100, 1)
                                            : 0;
                                        ?>

                                        <div style="margin-bottom:12px">

                                            <div
                                                style="
                                                    display:flex;
                                                    justify-content:space-between;
                                                    gap:10px;
                                                "
                                            >

                                                <span>
                                                    <?= h($option['label']) ?>
                                                </span>

                                                <strong>
                                                    <?= $count ?>件
                                                    (<?= h($percent) ?>%)
                                                </strong>

                                            </div>

                                            <div
                                                style="
                                                    height:8px;
                                                    background:#e2e8f0;
                                                    border-radius:999px;
                                                    overflow:hidden;
                                                    margin-top:5px;
                                                "
                                            >

                                                <div
                                                    style="
                                                        width:<?= h($percent) ?>%;
                                                        height:100%;
                                                        background:var(--primary);
                                                    "
                                                ></div>

                                            </div>

                                        </div>

                                    <?php endforeach; ?>

                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</div>


<?php
/* ============================================================
 * 23. 送信
 * ============================================================
 */
?>

<?php elseif ($screen === 'send'): ?>

<?php

$sendSurveyId = (string)$currentSurvey['id'];

$history = load_send_history();

$surveyHistory = array_values(
    array_filter(
        $history,
        static function ($row) use ($sendSurveyId): bool {
            return (string)($row['surveyId'] ?? '') === $sendSurveyId;
        }
    )
);

?>

<div class="page-title">

    <div>

        <h1>顧客選択・メール送信</h1>

        <p>
            対象アンケート：
            <strong><?= h($currentSurvey['title']) ?></strong>
        </p>

    </div>

</div>


<div class="mail-layout">

<div class="card">

    <div class="card-header">

        <h2>顧客選択</h2>

        <span class="muted">
            <?= count($customers) ?>件
        </span>

    </div>

    <div class="card-body">

        <?php if ($customers === []): ?>

            <div class="empty">
                顧客データがありません。<br>
                kintone設定画面から顧客情報を同期してください。
            </div>

        <?php else: ?>

            <div class="customer-list">

                <?php foreach ($customers as $customer): ?>

                    <label class="customer-row">

                        <input
                            type="checkbox"
                            name="customer_ids[]"
                            value="<?= h($customer['id'] ?? '') ?>"
                            form="sendForm"
                        >

                        <div class="customer-info">

                            <div class="customer-name">
                                <?= h($customer['name'] ?? '') ?>
                            </div>

                            <div class="customer-email">
                                <?= h($customer['email'] ?? '') ?>
                            </div>

                            <?php if (!empty($customer['organization'])): ?>

                                <div class="muted">
                                    <?= h($customer['organization']) ?>
                                </div>

                            <?php endif; ?>

                        </div>

                    </label>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>


<div>

<div class="card">

    <div class="card-header">

        <h2>メール作成</h2>

    </div>

    <div class="card-body">

        <form
            method="post"
            id="sendForm"
        >

            <input
                type="hidden"
                name="_csrf"
                value="<?= h(csrf_token()) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="send_mail"
            >

            <input
                type="hidden"
                name="survey_id"
                value="<?= h($sendSurveyId) ?>"
            >

            <div class="form-group">

                <label>
                    件名
                    <span class="required">*</span>
                </label>

                <input
                    type="text"
                    name="subject"
                    value="<?= h($currentSurvey['title']) ?>"
                    required
                >

            </div>

            <div class="form-group">

                <label>
                    本文
                    <span class="required">*</span>
                </label>

                <textarea
                    name="body"
                    required
                ><?= h(
                    "{顧客名} 様\n\n"
                    . "アンケートへのご協力をお願いいたします。\n\n"
                    . "{アンケートURL}\n"
                ) ?></textarea>

                <div class="help">
                    使用可能な変数：{顧客名}、{アンケートURL}
                </div>

            </div>

            <button
                type="submit"
                class="btn btn-primary"
                data-confirm="選択した顧客へアンケートメールを一括送信しますか？"
                data-loading-button
            >
                一括送信
            </button>

            <div
                class="loading"
                data-loading
            >
                <span class="spinner"></span>
                <span>
                    メールを送信しています…
                </span>
            </div>

        </form>

    </div>

</div>


<div class="card">

    <div class="card-header">

        <h2>送信履歴</h2>

    </div>

    <div class="card-body">

        <?php if ($surveyHistory === []): ?>

            <div class="empty">
                送信履歴はありません。
            </div>

        <?php else: ?>

            <div class="table-wrap">

                <table>

                    <thead>

                        <tr>
                            <th>送信日時</th>
                            <th>顧客</th>
                            <th>メールアドレス</th>
                            <th>状態</th>
                            <th>エラー</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach (array_reverse($surveyHistory) as $row): ?>

                        <tr>

                            <td>
                                <?= h(format_datetime(
                                    (string)($row['sentAt'] ?? $row['createdAt'] ?? '')
                                )) ?>
                            </td>

                            <td>
                                <?= h($row['customerName'] ?? '') ?>
                            </td>

                            <td>
                                <?= h($row['email'] ?? '') ?>
                            </td>

                            <td>

                                <?php if (($row['status'] ?? '') === 'sent'): ?>

                                    <span class="status status-success">
                                        送信済み
                                    </span>

                                <?php elseif (($row['status'] ?? '') === 'failed'): ?>

                                    <span class="status status-warning">
                                        失敗
                                    </span>

                                <?php else: ?>

                                    <span class="status status-muted">
                                        処理中
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>
                                <?= h($row['error'] ?? '') ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</div>

</div>

</div>


<?php
/* ============================================================
 * 24. 回答者
 * ============================================================
 */
?>

<?php elseif ($screen === 'answer'): ?>

<?php

$answerSurvey = $currentSurvey;

update_survey_auto_status($answerSurvey);

if (($answerSurvey['status'] ?? '') !== 'published'):

?>

<div class="answer-container">

    <div class="card">

        <div class="card-body">

            <div class="empty">

                <h2>
                    このアンケートは現在回答できません。
                </h2>

                <p>
                    公開期間をご確認ください。
                </p>

            </div>

        </div>

    </div>

</div>

<?php else: ?>

<div class="answer-container">

    <div class="answer-header">

        <h1>
            <?= h($answerSurvey['title']) ?>
        </h1>

        <?php if ($answerSurvey['description'] !== ''): ?>

            <p class="muted">
                <?= nl2br(h($answerSurvey['description'])) ?>
            </p>

        <?php endif; ?>

    </div>


    <form
        method="post"
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
            value="save_answer"
        >

        <input
            type="hidden"
            name="survey_id"
            value="<?= h($answerSurvey['id']) ?>"
        >


        <?php foreach ($answerSurvey['groups'] as $group): ?>

            <div class="card">

                <div class="card-header">

                    <h2>
                        <?= h($group['title']) ?>
                    </h2>

                </div>

                <div class="card-body">

                    <?php foreach ($group['questions'] as $question): ?>

                        <?php
                        $qid = (string)$question['id'];
                        $currentValue = $answerDraft[$qid] ?? '';
                        ?>

                        <div
                            class="preview-question"
                            data-answer-question
                            data-question-id="<?= h($qid) ?>"
                        >

                            <div class="preview-number">

                                <?= h($question['number'] ?? '') ?>

                                <?php if (!empty($question['required'])): ?>

                                    <span class="required">
                                        必須
                                    </span>

                                <?php else: ?>

                                    <span class="muted">
                                        任意
                                    </span>

                                <?php endif; ?>

                            </div>

                            <div class="preview-text">
                                <?= h($question['text'] ?? '') ?>
                            </div>


                            <?php if (($question['type'] ?? '') === 'text'): ?>

                                <textarea
                                    name="answers[<?= h($qid) ?>]"
                                    <?= !empty($question['required']) ? 'required' : '' ?>
                                ><?= h(is_array($currentValue) ? '' : $currentValue) ?></textarea>


                            <?php elseif (($question['type'] ?? '') === 'multiple'): ?>

                                <?php
                                $currentArray = is_array($currentValue)
                                    ? array_map('strval', $currentValue)
                                    : [];
                                ?>

                                <?php foreach (($question['options'] ?? []) as $option): ?>

                                    <?php
                                    $optionId = (string)$option['id'];
                                    ?>

                                    <label class="choice">

                                        <input
                                            type="checkbox"
                                            name="answers[<?= h($qid) ?>][]"
                                            value="<?= h($optionId) ?>"
                                            <?= in_array($optionId, $currentArray, true) ? 'checked' : '' ?>
                                        >

                                        <?= h($option['label'] ?? '') ?>

                                    </label>

                                <?php endforeach; ?>


                            <?php else: ?>

                                <?php
                                $currentString = is_array($currentValue)
                                    ? ''
                                    : (string)$currentValue;
                                ?>

                                <?php foreach (($question['options'] ?? []) as $option): ?>

                                    <?php
                                    $optionId = (string)$option['id'];
                                    ?>

                                    <label class="choice">

                                        <input
                                            type="radio"
                                            name="answers[<?= h($qid) ?>]"
                                            value="<?= h($optionId) ?>"
                                            <?= $currentString === $optionId ? 'checked' : '' ?>
                                            <?= !empty($question['required']) ? 'required' : '' ?>
                                        >

                                        <?= h($option['label'] ?? '') ?>

                                    </label>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        <?php endforeach; ?>


        <div class="answer-actions">

            <span></span>

            <button
                type="submit"
                class="btn btn-primary"
            >
                回答を確認する
            </button>

        </div>

    </form>

</div>

<?php endif; ?>


<?php
/* ============================================================
 * 25. 回答確認
 * ============================================================
 */
?>

<?php elseif ($screen === 'confirm'): ?>

<?php

$confirmData = $_SESSION['answer_confirm'] ?? null;

if (
    !is_array($confirmData)
    || (string)($confirmData['survey_id'] ?? '') !==
        (string)$currentSurvey['id']
) {

    redirect_to(app_url([
        'screen' => 'answer',
        'id' => $currentSurvey['id'],
    ]));

}

$confirmAnswers = is_array(
    $confirmData['answers'] ?? null
)
    ? $confirmData['answers']
    : [];

$confirmQuestions = flatten_questions($currentSurvey);

?>

<div class="answer-container">

    <div class="answer-header">

        <h1>回答確認</h1>

        <p>
            送信する回答内容をご確認ください。
        </p>

    </div>


    <div class="card">

        <div class="card-body">

            <?php foreach ($confirmQuestions as $question): ?>

                <?php
                $qid = (string)$question['id'];
                $value = $confirmAnswers[$qid] ?? '';

                if (is_array($value)) {
                    $displayValue = [];

                    foreach ($value as $selectedId) {

                        foreach (($question['options'] ?? []) as $option) {

                            if ((string)$option['id'] === (string)$selectedId) {
                                $displayValue[] = (string)$option['label'];
                                break;
                            }

                        }

                    }

                    $displayValue = implode('、', $displayValue);

                } else {

                    $displayValue = '';

                    foreach (($question['options'] ?? []) as $option) {

                        if (
                            (string)$option['id']
                            === (string)$value
                        ) {
                            $displayValue = (string)$option['label'];
                            break;
                        }

                    }

                    if ($displayValue === '') {
                        $displayValue = (string)$value;
                    }

                }
                ?>

                <div class="confirm-answer">

                    <div class="confirm-label">

                        <?= h($question['number'] ?? '') ?>

                        <br>

                        <?= h($question['text'] ?? '') ?>

                    </div>

                    <div>
                        <?= nl2br(h($displayValue)) ?>
                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </div>


    <div class="answer-actions">

        <a
            class="btn"
            href="<?= h(app_url([
                'screen' => 'answer',
                'id' => $currentSurvey['id'],
            ])) ?>"
        >
            戻って修正
        </a>

        <form method="post">

            <input
                type="hidden"
                name="_csrf"
                value="<?= h(csrf_token()) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="confirm_answer_final"
            >

            <button
                type="submit"
                class="btn btn-primary"
                data-confirm="回答を送信します。よろしいですか？"
            >
                回答を送信する
            </button>

        </form>

    </div>

</div>


<?php
/* ============================================================
 * 26. 完了
 * ============================================================
 */
?>

<?php elseif ($screen === 'complete'): ?>

<div class="answer-container">

    <div class="card">

        <div class="card-body">

            <div class="empty">

                <div
                    style="
                        width:64px;
                        height:64px;
                        margin:0 auto 18px;
                        border-radius:50%;
                        background:#dcfce7;
                        color:#16a34a;
                        display:flex;
                        align-items:center;
                        justify-content:center;
                        font-size:30px;
                        font-weight:800;
                    "
                >
                    ✓
                </div>

                <h1>
                    回答ありがとうございました
                </h1>

                <p>
                    回答を受け付けました。
                </p>

            </div>

        </div>

    </div>

</div>


<?php endif; ?>

</main>


<?php if ($screen !== 'answer' && $screen !== 'confirm' && $screen !== 'complete'): ?>

<footer class="footer">
    <?= h(APP_NAME) ?>
</footer>

<?php endif; ?>


<script>
(function () {

    'use strict';


    /*
     * Enterキー検索
     */
    document.addEventListener('keydown', function (event) {

        if (
            event.key === 'Enter'
            && event.target
            && event.target.matches('input[name="q"]')
        ) {
            const form = event.target.closest('form');

            if (form) {
                form.submit();
            }
        }

    });


    /*
     * 共通確認ダイアログ
     */
    document.querySelectorAll('[data-confirm]').forEach(function (element) {

        element.addEventListener('click', function (event) {

            const message = element.getAttribute('data-confirm');

            if (message && !window.confirm(message)) {
                event.preventDefault();
                event.stopPropagation();
            }

        });

    });


    /*
     * 外部通信ボタン。
     *
     * 「静かに終わる」状態を避けるため、
     * クリック直後に処理中表示へ切り替える。
     */
    document.querySelectorAll('[data-loading-button]').forEach(function (button) {

        button.addEventListener('click', function () {

            const form = button.closest('form');

            if (!form) {
                return;
            }

            const loading = form.querySelector('[data-loading]');

            if (loading) {
                loading.classList.add('show');
            }

            form.querySelectorAll('button').forEach(function (item) {
                item.disabled = true;
            });

        });

    });


    /*
     * フォーム送信時の二重送信防止。
     */
    document.querySelectorAll('form').forEach(function (form) {

        form.addEventListener('submit', function (event) {

            if (
                form.dataset.submitting === '1'
                && !form.querySelector('[data-allow-repeat]')
            ) {
                event.preventDefault();
                return;
            }

            form.dataset.submitting = '1';

        });

    });


    /*
     * 回答フォームの条件分岐。
     *
     * prompt.txtで指定された条件分岐に対応するための
     * 基本フック。
     */
    const answerForm = document.getElementById('answerForm');

    if (answerForm) {

        answerForm.addEventListener('change', function () {

            /*
             * 将来、question.branchesをHTMLへ埋め込んで
             * サーバー定義に合わせた動的表示へ拡張可能。
             *
             * 必須チェックはサーバー側で最終的に実施する。
             */
        });

    }

})();


/* ============================================================
 * アンケート編集 JavaScript
 * ============================================================
 */

let groupSequence = 1000;
let questionSequence = 1000;
let optionSequence = 1000;


function addGroup() {

    const container = document.getElementById('groupsContainer');

    if (!container) {
        return;
    }

    const addButton = container.querySelector(
        ':scope > button:last-child'
    );

    const group = document.createElement('div');

    group.className = 'group-editor';

    group.setAttribute('draggable', 'true');
    group.setAttribute('data-group', '');

    group.innerHTML = `
        <div class="group-header">

            <span class="drag-handle">↕</span>

            <input
                type="text"
                data-group-title
                value="新しいグループ"
            >

            <button
                type="button"
                class="btn btn-small btn-danger"
                onclick="deleteGroup(this)"
            >
                グループ削除
            </button>

        </div>

        <div class="group-body">

            <div class="questions-container"></div>

            <button
                type="button"
                class="btn btn-small"
                onclick="addQuestion(this)"
            >
                ＋ 質問を追加
            </button>

        </div>
    `;

    if (addButton) {
        container.insertBefore(group, addButton);
    } else {
        container.appendChild(group);
    }

    enableDragDrop();
    renumberQuestions();
}


function deleteGroup(button) {

    if (!window.confirm(
        'このグループを削除しますか？'
    )) {
        return;
    }

    const group = button.closest('[data-group]');

    if (group) {
        group.remove();
    }

    renumberQuestions();
}


function addQuestion(button) {

    const group = button.closest('[data-group]');

    if (!group) {
        return;
    }

    const container = group.querySelector(
        '.questions-container'
    );

    if (!container) {
        return;
    }

    const question = document.createElement('div');

    question.className = 'question-editor';

    question.setAttribute('draggable', 'true');
    question.setAttribute('data-question', '');

    question.innerHTML = `
        <div class="question-header">

            <span class="drag-handle">↕</span>

            <span
                class="question-number"
                data-question-number
            >
                Q
            </span>

            <strong>質問</strong>

            <button
                type="button"
                class="btn btn-small btn-danger"
                onclick="deleteQuestion(this)"
                style="margin-left:auto"
            >
                質問削除
            </button>

        </div>

        <div class="question-body">

            <div class="form-group">

                <label>質問文</label>

                <input
                    type="text"
                    data-question-text
                    value=""
                >

            </div>

            <div class="form-grid">

                <div class="form-group">

                    <label>回答形式</label>

                    <select
                        data-question-type
                        onchange="changeQuestionType(this)"
                    >
                        <option value="single">
                            単一選択
                        </option>

                        <option value="multiple">
                            複数選択
                        </option>

                        <option value="text">
                            自由記述
                        </option>

                    </select>

                </div>

                <div class="form-group">

                    <label>必須設定</label>

                    <label class="choice">

                        <input
                            type="checkbox"
                            data-question-required
                        >

                        必須回答

                    </label>

                </div>

            </div>

            <div data-options>

                <label>選択肢</label>

                <div class="option-row">

                    <input
                        type="text"
                        data-option
                        value="選択肢1"
                    >

                    <button
                        type="button"
                        class="btn btn-small btn-danger"
                        onclick="this.parentElement.remove()"
                    >
                        削除
                    </button>

                </div>

                <div class="option-row">

                    <input
                        type="text"
                        data-option
                        value="選択肢2"
                    >

                    <button
                        type="button"
                        class="btn btn-small btn-danger"
                        onclick="this.parentElement.remove()"
                    >
                        削除
                    </button>

                </div>

                <button
                    type="button"
                    class="btn btn-small"
                    onclick="addOption(this)"
                >
                    ＋ 選択肢追加
                </button>

            </div>

        </div>
    `;

    container.appendChild(question);

    enableDragDrop();
    renumberQuestions();
}


function deleteQuestion(button) {

    if (!window.confirm(
        'この質問を削除しますか？'
    )) {
        return;
    }

    const question = button.closest('[data-question]');

    if (question) {
        question.remove();
    }

    renumberQuestions();
}


function addOption(button) {

    const options = button.closest('[data-options]');

    if (!options) {
        return;
    }

    const row = document.createElement('div');

    row.className = 'option-row';

    row.innerHTML = `
        <input
            type="text"
            data-option
            value=""
        >

        <button
            type="button"
            class="btn btn-small btn-danger"
            onclick="this.parentElement.remove()"
        >
            削除
        </button>
    `;

    options.insertBefore(row, button);

}


function changeQuestionType(select) {

    const question = select.closest('[data-question]');

    if (!question) {
        return;
    }

    const options = question.querySelector('[data-options]');

    if (!options) {
        return;
    }

    if (select.value === 'text') {
        options.style.display = 'none';
    } else {
        options.style.display = '';
    }

}


function renumberQuestions() {

    const form = document.getElementById('surveyForm');

    if (!form) {
        return;
    }

    const numberingSelect = form.querySelector(
        'select[name="numbering"]'
    );

    const mode = numberingSelect
        ? numberingSelect.value
        : 'global';

    const groups = form.querySelectorAll(
        '[data-group]'
    );

    let globalNumber = 1;

    groups.forEach(function (group, groupIndex) {

        const questions = group.querySelectorAll(
            '[data-question]'
        );

        questions.forEach(function (question, questionIndex) {

            const number = mode === 'group'
                ? 'Q' + (groupIndex + 1) + '-' + (questionIndex + 1)
                : 'Q' + globalNumber;

            const target = question.querySelector(
                '[data-question-number]'
            );

            if (target) {
                target.textContent = number;
            }

            globalNumber++;

        });

    });

}


function prepareSurveyForm() {

    const form = document.getElementById('surveyForm');

    if (!form) {
        return false;
    }

    /*
     * 既存の動的なname属性を一度削除。
     */
    form.querySelectorAll(
        '[data-generated]'
    ).forEach(function (element) {
        element.remove();
    });


    const groups = form.querySelectorAll(
        '[data-group]'
    );

    groups.forEach(function (group, groupIndex) {

        const groupId =
            group.getAttribute('data-group-id')
            || ('group-' + Date.now() + '-' + groupIndex);

        group.setAttribute(
            'data-group-id',
            groupId
        );

        addHidden(
            form,
            `groups[${groupIndex}][id]`,
            groupId
        );

        const title = group.querySelector(
            '[data-group-title]'
        );

        addHidden(
            form,
            `groups[${groupIndex}][title]`,
            title ? title.value : 'グループ'
        );


        const questions = group.querySelectorAll(
            '[data-question]'
        );

        questions.forEach(function (question, questionIndex) {

            const qid =
                question.getAttribute('data-question-id')
                || (
                    'question-'
                    + Date.now()
                    + '-'
                    + groupIndex
                    + '-'
                    + questionIndex
                );

            question.setAttribute(
                'data-question-id',
                qid
            );

            const numberElement =
                question.querySelector('[data-question-number]');

            const textElement =
                question.querySelector('[data-question-text]');

            const typeElement =
                question.querySelector('[data-question-type]');

            const requiredElement =
                question.querySelector('[data-question-required]');


            addHidden(
                form,
                `groups[${groupIndex}][questions][${questionIndex}][id]`,
                qid
            );

            addHidden(
                form,
                `groups[${groupIndex}][questions][${questionIndex}][number]`,
                numberElement ? numberElement.textContent : ''
            );

            addHidden(
                form,
                `groups[${groupIndex}][questions][${questionIndex}][text]`,
                textElement ? textElement.value : ''
            );

            addHidden(
                form,
                `groups[${groupIndex}][questions][${questionIndex}][type]`,
                typeElement ? typeElement.value : 'single'
            );

            addHidden(
                form,
                `groups[${groupIndex}][questions][${questionIndex}][required]`,
                requiredElement && requiredElement.checked ? '1' : '0'
            );


            if (
                typeElement
                && typeElement.value !== 'text'
            ) {

                const options =
                    question.querySelectorAll('[data-option]');

                let optionIndex = 0;

                options.forEach(function (option) {

                    const label =
                        option.value.trim();

                    if (label === '') {
                        return;
                    }

                    addHidden(
                        form,
                        `groups[${groupIndex}][questions][${questionIndex}][options][${optionIndex}][id]`,
                        'opt-' + Date.now() + '-' + optionIndex
                    );

                    addHidden(
                        form,
                        `groups[${groupIndex}][questions][${questionIndex}][options][${optionIndex}][label]`,
                        label
                    );

                    optionIndex++;

                });

            }

        });

    });


    return true;
}


function addHidden(form, name, value) {

    const input = document.createElement('input');

    input.type = 'hidden';
    input.name = name;
    input.value = value;

    input.setAttribute(
        'data-generated',
        '1'
    );

    form.appendChild(input);
}


/*
 * numbering変更時
 */
document.addEventListener('change', function (event) {

    if (
        event.target
        && event.target.matches(
            '#surveyForm select[name="numbering"]'
        )
    ) {
        renumberQuestions();
    }

});


/*
 * 初期採番
 */
document.addEventListener('DOMContentLoaded', function () {

    renumberQuestions();
    enableDragDrop();

});


/* ============================================================
 * HTML5 Drag & Drop
 * ============================================================
 */

function enableDragDrop() {

    document.querySelectorAll(
        '[data-group], [data-question]'
    ).forEach(function (element) {

        if (element.dataset.dragReady === '1') {
            return;
        }

        element.dataset.dragReady = '1';

        element.addEventListener(
            'dragstart',
            function (event) {

                event.dataTransfer.effectAllowed = 'move';

                element.classList.add('dragging');

                window.__dragElement = element;

            }
        );

        element.addEventListener(
            'dragend',
            function () {

                element.classList.remove('dragging');

                document.querySelectorAll(
                    '.drop-target'
                ).forEach(function (target) {
                    target.classList.remove('drop-target');
                });

                window.__dragElement = null;

                renumberQuestions();

            }
        );

        element.addEventListener(
            'dragover',
            function (event) {

                event.preventDefault();

                const dragging =
                    window.__dragElement;

                if (
                    !dragging
                    || dragging === element
                ) {
                    return;
                }

                /*
                 * グループと質問は同種のみ並び替える。
                 */
                const sameType =
                    (
                        dragging.matches('[data-group]')
                        && element.matches('[data-group]')
                    )
                    ||
                    (
                        dragging.matches('[data-question]')
                        && element.matches('[data-question]')
                    );

                if (!sameType) {
                    return;
                }

                element.classList.add('drop-target');

            }
        );

        element.addEventListener(
            'dragleave',
            function () {
                element.classList.remove('drop-target');
            }
        );

        element.addEventListener(
            'drop',
            function (event) {

                event.preventDefault();

                const dragging =
                    window.__dragElement;

                if (
                    !dragging
                    || dragging === element
                ) {
                    return;
                }

                const sameType =
                    (
                        dragging.matches('[data-group]')
                        && element.matches('[data-group]')
                    )
                    ||
                    (
                        dragging.matches('[data-question]')
                        && element.matches('[data-question]')
                    );

                if (!sameType) {
                    return;
                }

                const parent =
                    element.parentNode;

                if (!parent) {
                    return;
                }

                const rect =
                    element.getBoundingClientRect();

                const after =
                    event.clientY > rect.top + rect.height / 2;

                if (after) {
                    parent.insertBefore(
                        dragging,
                        element.nextSibling
                    );
                } else {
                    parent.insertBefore(
                        dragging,
                        element
                    );
                }

                element.classList.remove(
                    'drop-target'
                );

                renumberQuestions();

            }
        );

    });

}
</script>

</body>
</html>
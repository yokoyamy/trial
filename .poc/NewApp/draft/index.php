<?php
/**
 * アンケート業務運営アプリ
 * 実行環境: Apache 2.4 + PHP 8.4/8.5, DBなし, JSONファイル永続化
 */

declare(strict_types=1);

namespace App\Survey;

// =====================================================
// 定数・パス定義
// =====================================================

const APP_ROOT = __DIR__;
const DATA_DIR = APP_ROOT . '/data';

const FILE_SETTINGS = DATA_DIR . '/settings.json';
const FILE_SURVEYS = DATA_DIR . '/surveys.json';
const FILE_GROUPS = DATA_DIR . '/groups.json';
const FILE_QUESTIONS = DATA_DIR . '/questions.json';
const FILE_OPTIONS = DATA_DIR . '/options.json';
const FILE_CUSTOMERS = DATA_DIR . '/customers.json';
const FILE_RECIPIENTS = DATA_DIR . '/recipients.json';
const FILE_MAIL_LOGS = DATA_DIR . '/mail_logs.json';
const FILE_RESPONSES = DATA_DIR . '/responses.json';
const FILE_RESPONSE_ANSWERS = DATA_DIR . '/response_answers.json';

const SESSION_KEY = '__survey_app_session';

// =====================================================
// セキュリティヘッダー・セッション初期化
// =====================================================

function init_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
    if (!isset($_SESSION[SESSION_KEY]) || !is_array($_SESSION[SESSION_KEY])) {
        $_SESSION[SESSION_KEY] = [];
    }
}

function set_security_headers(): void
{
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
}

function get_csrf_token(): string
{
    if (empty($_SESSION[SESSION_KEY]['csrf'])) {
        $_SESSION[SESSION_KEY]['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION[SESSION_KEY]['csrf'];
}

function verify_csrf_token(?string $token): bool
{
    if (!is_string($token) || $token === '') {
        return false;
    }
    $stored = $_SESSION[SESSION_KEY]['csrf'] ?? '';
    if (!is_string($stored) || $stored === '') {
        return false;
    }
    return hash_equals($stored, $token);
}

// =====================================================
// ヘルパー関数
// =====================================================

function h(?string $str): string
{
    if ($str === null) {
        return '';
    }
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function gen_id(): string
{
    return bin2hex(random_bytes(16));
}

function now_string(): string
{
    return date('Y-m-d H:i:s');
}

function http_get_last_response_headers(): array
{
    $headers = [];
    if (function_exists('http_get_response_headers')) {
        // no-op placeholder guard, not actually used
    }
    if (isset($GLOBALS['__last_response_headers']) && is_array($GLOBALS['__last_response_headers'])) {
        return $GLOBALS['__last_response_headers'];
    }
    return $headers;
}

// =====================================================
// JSONファイル読み書き（排他制御込み）
// =====================================================

class DataStoreException extends \RuntimeException
{
}

function ensure_data_dir(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new DataStoreException('データディレクトリの作成に失敗しました');
        }
    }
}

function read_json_file(string $path): array
{
    ensure_data_dir();
    if (!file_exists($path)) {
        return [];
    }
    if (!is_readable($path)) {
        throw new DataStoreException('ファイルを読み込めません: ' . basename($path));
    }
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        throw new DataStoreException('ファイルオープンに失敗しました: ' . basename($path));
    }
    try {
        if (!flock($fp, LOCK_SH)) {
            throw new DataStoreException('ファイルロックに失敗しました: ' . basename($path));
        }
        $size = filesize($path);
        $content = $size > 0 ? fread($fp, $size) : '';
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
    if ($content === '' || $content === false) {
        return [];
    }
    $decoded = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        throw new DataStoreException('JSONの解析に失敗しました: ' . basename($path));
    }
    return $decoded;
}

function write_json_file(string $path, array $data): void
{
    ensure_data_dir();
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new DataStoreException('JSONの生成に失敗しました: ' . basename($path));
    }
    $tmpPath = $path . '.tmp_' . bin2hex(random_bytes(8));
    $fp = fopen($tmpPath, 'wb');
    if ($fp === false) {
        throw new DataStoreException('一時ファイル作成に失敗しました: ' . basename($path));
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            throw new DataStoreException('ファイルロックに失敗しました: ' . basename($path));
        }
        $written = fwrite($fp, $json);
        if ($written === false) {
            throw new DataStoreException('ファイル書き込みに失敗しました: ' . basename($path));
        }
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    // 実ファイルへのロックを取得してから置き換え（同時更新対策）
    $lockPath = $path . '.lock';
    $lockFp = fopen($lockPath, 'c');
    if ($lockFp === false) {
        @unlink($tmpPath);
        throw new DataStoreException('ロックファイル作成に失敗しました: ' . basename($path));
    }
    try {
        if (!flock($lockFp, LOCK_EX)) {
            @unlink($tmpPath);
            throw new DataStoreException('排他ロック取得に失敗しました: ' . basename($path));
        }
        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new DataStoreException('ファイルの置き換えに失敗しました: ' . basename($path));
        }
        flock($lockFp, LOCK_UN);
    } finally {
        fclose($lockFp);
    }
}

// =====================================================
// リポジトリ層（各データファイルのCRUD）
// =====================================================

function find_by_id(array $rows, string $id): ?array
{
    foreach ($rows as $row) {
        if (is_array($row) && isset($row['id']) && $row['id'] === $id) {
            return $row;
        }
    }
    return null;
}

function upsert_row(array $rows, array $row): array
{
    $found = false;
    foreach ($rows as $idx => $r) {
        if (is_array($r) && isset($r['id']) && isset($row['id']) && $r['id'] === $row['id']) {
            $rows[$idx] = $row;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $rows[] = $row;
    }
    return $rows;
}

function remove_by_id(array $rows, string $id): array
{
    return array_values(array_filter($rows, static function ($r) use ($id) {
        return !(is_array($r) && isset($r['id']) && $r['id'] === $id);
    }));
}

// -------- Settings --------

function load_settings(): array
{
    $rows = read_json_file(FILE_SETTINGS);
    if (isset($rows['smtp']) || isset($rows['kintone'])) {
        return $rows;
    }
    return [
        'smtp' => [
            'host' => '',
            'port' => '',
            'encryption' => 'None',
            'username' => '',
            'password' => '',
            'from_address' => '',
            'from_name' => '',
            'last_test_ok' => null,
            'last_test_at' => null,
            'last_test_message' => '',
        ],
        'kintone' => [
            'subdomain' => '',
            'app_id' => '',
            'login_name' => '',
            'password' => '',
            'field_name' => '',
            'field_email' => '',
            'proxy' => '',
            'ssl_verify' => false,
            'last_test_ok' => null,
            'last_test_at' => null,
            'last_test_message' => '',
        ],
    ];
}

function save_settings(array $settings): void
{
    write_json_file(FILE_SETTINGS, $settings);
}

// -------- Surveys / Groups / Questions / Options --------

function load_surveys(): array
{
    return read_json_file(FILE_SURVEYS);
}

function save_surveys(array $rows): void
{
    write_json_file(FILE_SURVEYS, $rows);
}

function load_groups(): array
{
    return read_json_file(FILE_GROUPS);
}

function save_groups(array $rows): void
{
    write_json_file(FILE_GROUPS, $rows);
}

function load_questions(): array
{
    return read_json_file(FILE_QUESTIONS);
}

function save_questions(array $rows): void
{
    write_json_file(FILE_QUESTIONS, $rows);
}

function load_options(): array
{
    return read_json_file(FILE_OPTIONS);
}

function save_options(array $rows): void
{
    write_json_file(FILE_OPTIONS, $rows);
}

function load_customers(): array
{
    return read_json_file(FILE_CUSTOMERS);
}

function save_customers(array $rows): void
{
    write_json_file(FILE_CUSTOMERS, $rows);
}

function load_recipients(): array
{
    return read_json_file(FILE_RECIPIENTS);
}

function save_recipients(array $rows): void
{
    write_json_file(FILE_RECIPIENTS, $rows);
}

function load_mail_logs(): array
{
    return read_json_file(FILE_MAIL_LOGS);
}

function save_mail_logs(array $rows): void
{
    write_json_file(FILE_MAIL_LOGS, $rows);
}

function load_responses(): array
{
    return read_json_file(FILE_RESPONSES);
}

function save_responses(array $rows): void
{
    write_json_file(FILE_RESPONSES, $rows);
}

function load_response_answers(): array
{
    return read_json_file(FILE_RESPONSE_ANSWERS);
}

function save_response_answers(array $rows): void
{
    write_json_file(FILE_RESPONSE_ANSWERS, $rows);
}

// =====================================================
// バリデーション関数群
// =====================================================

function validate_survey_basic(array $input): array
{
    $errors = [];
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        $errors['name'] = 'アンケート名は必須です';
    } elseif (mb_strlen($name) > 200) {
        $errors['name'] = 'アンケート名は200文字以内で入力してください';
    }

    $desc = (string)($input['description'] ?? '');
    if (mb_strlen($desc) > 2000) {
        $errors['description'] = '説明文は2000文字以内で入力してください';
    }

    $start = (string)($input['start_at'] ?? '');
    $end = (string)($input['end_at'] ?? '');
    if ($start !== '' && $end !== '') {
        $ts1 = strtotime($start);
        $ts2 = strtotime($end);
        if ($ts1 === false || $ts2 === false) {
            $errors['period'] = '公開期間の形式が不正です';
        } elseif ($ts1 >= $ts2) {
            $errors['period'] = '公開終了日時は開始日時より後にしてください';
        }
    }

    $status = (string)($input['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'open', 'closed'], true)) {
        $errors['status'] = '状態の値が不正です';
    }

    $numberFormat = (string)($input['number_format'] ?? 'sequential');
    if (!in_array($numberFormat, ['sequential', 'grouped'], true)) {
        $errors['number_format'] = '質問番号形式の値が不正です';
    }

    return $errors;
}

function validate_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validate_port(string $port): bool
{
    if (!ctype_digit($port)) {
        return false;
    }
    $n = (int)$port;
    return $n >= 1 && $n <= 65535;
}

function validate_smtp_settings(array $input): array
{
    $errors = [];
    $host = trim((string)($input['host'] ?? ''));
    if ($host === '') {
        $errors['host'] = 'SMTPサーバホスト名は必須です';
    }
    $port = trim((string)($input['port'] ?? ''));
    if ($port === '' || !validate_port($port)) {
        $errors['port'] = 'ポート番号は1〜65535の数値で指定してください';
    }
    $enc = (string)($input['encryption'] ?? 'None');
    if (!in_array($enc, ['None', 'SSL', 'TLS'], true)) {
        $errors['encryption'] = '暗号化方式の値が不正です';
    }
    $from = trim((string)($input['from_address'] ?? ''));
    if ($from === '' || !validate_email($from)) {
        $errors['from_address'] = '送信元メールアドレスの形式が不正です';
    }
    return $errors;
}

function validate_kintone_settings(array $input): array
{
    $errors = [];
    $subdomain = trim((string)($input['subdomain'] ?? ''));
    if ($subdomain === '') {
        $errors['subdomain'] = 'キントーンURL（サブドメイン）は必須です';
    } elseif (str_contains($subdomain, 'https://') || str_contains($subdomain, '.cybozu.com')) {
        $errors['subdomain'] = 'サブドメイン名のみを入力してください（https:// や .cybozu.com は不要です）';
    }
    $appId = trim((string)($input['app_id'] ?? ''));
    if ($appId === '' || !ctype_digit($appId)) {
        $errors['app_id'] = 'アプリIDは数値で入力してください';
    }
    $loginName = trim((string)($input['login_name'] ?? ''));
    if ($loginName === '') {
        $errors['login_name'] = 'ログイン名は必須です';
    }
    $fieldName = trim((string)($input['field_name'] ?? ''));
    if ($fieldName === '') {
        $errors['field_name'] = '顧客名フィールドコードは必須です';
    }
    $fieldEmail = trim((string)($input['field_email'] ?? ''));
    if ($fieldEmail === '') {
        $errors['field_email'] = 'メールアドレスフィールドコードは必須です';
    }
    $proxy = trim((string)($input['proxy'] ?? ''));
    if ($proxy !== '' && !preg_match('/^[^:\s]+:\d{1,5}$/', $proxy)) {
        $errors['proxy'] = 'プロキシは host:port 形式で入力してください';
    }
    return $errors;
}

/**
 * 質問・選択肢・分岐の整合性検証
 * - 存在しない分岐先や循環する分岐を検出する
 */
function validate_survey_structure(string $surveyId, array $groups, array $questions, array $options): array
{
    $errors = [];

    $groupIds = array_map(static fn($g) => $g['id'], $groups);
    $questionIds = array_map(static fn($q) => $q['id'], $questions);

    foreach ($questions as $q) {
        if (!in_array($q['group_id'], $groupIds, true)) {
            $errors[] = '質問「' . $q['text'] . '」の所属グループが存在しません';
        }
        if (trim((string)$q['text']) === '') {
            $errors[] = '質問文が未入力の項目があります';
        }
        if (!in_array($q['type'], ['text', 'single', 'multi'], true)) {
            $errors[] = '質問「' . $q['text'] . '」の回答形式が不正です';
        }
    }

    // 選択肢の検証
    foreach ($options as $opt) {
        if (!in_array($opt['question_id'], $questionIds, true)) {
            $errors[] = '選択肢が存在しない質問を参照しています';
            continue;
        }
        if (trim((string)$opt['text']) === '') {
            $errors[] = '選択肢の文言が未入力の項目があります';
        }
        $branch = $opt['branch_target'] ?? 'next';
        if ($branch !== 'next' && $branch !== 'end') {
            if (!in_array($branch, $questionIds, true)) {
                $errors[] = '選択肢「' . $opt['text'] . '」の分岐先質問が存在しません';
            }
        }
    }

    // 循環分岐の検証（単一選択のみ分岐対象）
    $questionMap = [];
    foreach ($questions as $q) {
        $questionMap[$q['id']] = $q;
    }
    $optionsByQuestion = [];
    foreach ($options as $opt) {
        $optionsByQuestion[$opt['question_id']][] = $opt;
    }

    foreach ($questions as $q) {
        if ($q['type'] !== 'single') {
            continue;
        }
        $visited = [];
        $current = $q['id'];
        $optsHere = $optionsByQuestion[$current] ?? [];
        foreach ($optsHere as $opt) {
            $target = $opt['branch_target'] ?? 'next';
            if ($target === 'next' || $target === 'end') {
                continue;
            }
            $chainVisited = [$q['id'] => true];
            $cursor = $target;
            $depth = 0;
            while ($cursor !== null && $depth < 1000) {
                if (isset($chainVisited[$cursor])) {
                    $errors[] = '質問「' . $q['text'] . '」の分岐設定に循環参照があります';
                    break;
                }
                $chainVisited[$cursor] = true;
                $cursorQ = $questionMap[$cursor] ?? null;
                if ($cursorQ === null || $cursorQ['type'] !== 'single') {
                    break;
                }
                $nextOpts = $optionsByQuestion[$cursor] ?? [];
                $jumpFound = false;
                foreach ($nextOpts as $no) {
                    $t = $no['branch_target'] ?? 'next';
                    if ($t !== 'next' && $t !== 'end') {
                        $cursor = $t;
                        $jumpFound = true;
                        break;
                    }
                }
                if (!$jumpFound) {
                    break;
                }
                $depth++;
            }
        }
    }

    return array_values(array_unique($errors));
}

// =====================================================
// アンケート番号生成（表示用）
// =====================================================

function build_question_numbers(array $groups, array $questions, string $numberFormat): array
{
    usort($groups, static fn($a, $b) => $a['order'] <=> $b['order']);
    $numbers = [];
    $seq = 1;
    foreach ($groups as $gi => $group) {
        $qs = array_values(array_filter($questions, static fn($q) => $q['group_id'] === $group['id']));
        usort($qs, static fn($a, $b) => $a['order'] <=> $b['order']);
        foreach ($qs as $qi => $q) {
            if ($numberFormat === 'grouped') {
                $numbers[$q['id']] = 'Q' . ($gi + 1) . '-' . ($qi + 1);
            } else {
                $numbers[$q['id']] = 'Q' . $seq;
                $seq++;
            }
        }
    }
    return $numbers;
}

// =====================================================
// kintone連携（cURL不使用、stream_context_create + file_get_contents）
// =====================================================

function build_kintone_base_url(string $subdomain): string
{
    $subdomain = trim($subdomain);
    $subdomain = preg_replace('#^https?://#i', '', $subdomain);
    $subdomain = preg_replace('/\.cybozu\.com.*$/i', '', $subdomain);
    $subdomain = rtrim($subdomain, '/');
    return 'https://' . $subdomain . '.cybozu.com';
}

function build_stream_context(array $kintoneSettings, string $method, array $headers, ?string $body): array
{
    $opts = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => 15,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => !empty($kintoneSettings['ssl_verify']),
            'verify_peer_name' => !empty($kintoneSettings['ssl_verify']),
        ],
    ];
    if ($body !== null) {
        $opts['http']['content'] = $body;
    }

    $proxy = trim((string)($kintoneSettings['proxy'] ?? ''));
    if ($proxy !== '') {
        $opts['http']['proxy'] = 'tcp://' . $proxy;
        $opts['http']['request_fulluri'] = true;
    }

    return $opts;
}

/**
 * kintoneへのHTTPリクエスト実行
 * @return array{ok:bool, status:int, body:?array, error:?string}
 */
function kintone_request(array $kintoneSettings, string $method, string $path, array $query = [], ?array $jsonBody = null): array
{
    $baseUrl = build_kintone_base_url((string)($kintoneSettings['subdomain'] ?? ''));
    $url = $baseUrl . $path;

    $authHeader = 'X-Cybozu-Authorization: ' . base64_encode(
        ($kintoneSettings['login_name'] ?? '') . ':' . ($kintoneSettings['password'] ?? '')
    );

    $headers = [$authHeader];
    $body = null;

    if ($method === 'GET') {
        if (!empty($query)) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
    } else {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($jsonBody ?? [], JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'リクエストボディの生成に失敗しました'];
        }
    }

    $context = stream_context_create(build_stream_context($kintoneSettings, $method, $headers, $body));

    $result = @file_get_contents($url, false, $context);

    $statusCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        $GLOBALS['__last_response_headers'] = $http_response_header;
        foreach ($http_response_header as $hLine) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $hLine, $m)) {
                $statusCode = (int)$m[1];
                break;
            }
        }
    }

    if ($result === false) {
        return ['ok' => false, 'status' => $statusCode, 'body' => null, 'error' => '通信に失敗しました（接続先・プロキシ設定をご確認ください）'];
    }

    $decoded = json_decode($result, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        ret
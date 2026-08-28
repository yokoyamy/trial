<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * Apache 2.4 / PHP 8.5
 * DBなし
 * PHP cURLなし
 *
 * index.php 単一エントリーポイント
 * サーバー側 JSON 永続化
 *
 * 主な機能:
 * - アンケート一覧
 * - アンケート作成・編集
 * - プレビュー
 * - 公開 / 停止 / 再開
 * - 複製 / 削除
 * - 回答
 * - 回答確認 / 完了
 * - 集計
 * - CSV出力
 * - 顧客選択・メール送信
 * - 送信履歴
 * - kintone設定
 * - kintone接続テスト
 * - kintone項目取得
 * - kintone顧客同期
 * - SMTP設定
 * - SMTP接続テスト
 *
 * 禁止事項:
 * - DB
 * - PHP cURL
 * - PHP mail()
 * - kintone API token
 * - 管理者認証
 * - セッションIDのURL埋め込み
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const CONNECT_TIMEOUT = 10;
const READ_TIMEOUT = 30;

const MAX_TITLE = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION = 1000;
const MAX_OPTION = 500;
const MAX_MAIL_BODY = 50000;

/* =========================================================
 * 基本
 * ========================================================= */

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post_string(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_scalar($v) ? trim((string)$v) : '';
}

function get_string(string $key): string
{
    $v = $_GET[$key] ?? '';
    return is_scalar($v) ? trim((string)$v) : '';
}

function uuid(string $prefix): string
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

function public_answer_url(string $surveyId): string
{
    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host . app_url([
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

    if (!is_file(SETTINGS_FILE)) {
        save_json(SETTINGS_FILE, default_settings());
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        $https =
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

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

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function csrf(): string
{
    return h((string)($_SESSION['csrf'] ?? ''));
}

function check_csrf(): void
{
    $token = post_string('csrf');

    if (
        $token === ''
        || !hash_equals(
            (string)($_SESSION['csrf'] ?? ''),
            $token
        )
    ) {
        throw new RuntimeException(
            '不正なリクエストです。ページを再読み込みしてください。'
        );
    }
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

        if ($raw === false || trim($raw) === '') {
            return $fallback;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded)
            ? $decoded
            : $fallback;
    } catch (Throwable) {
        @fclose($fp);
        return $fallback;
    }
}

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
            'データのJSON化に失敗しました。'
        );
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));

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

        $written = fwrite($fp, $json);

        if ($written === false || $written < strlen($json)) {
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

/* =========================================================
 * 初期データ
 * ========================================================= */

function default_data(): array
{
    $t = now();

    return [
        'surveys' => [
            [
                'id' => 'survey-001',
                'title' => '顧客満足度アンケート',
                'description' => 'サービスについてのご意見をお聞かせください。',
                'startAt' => date('Y-m-d\TH:i'),
                'endAt' => date('Y-m-d\TH:i', strtotime('+30 days')),
                'status' => 'draft',
                'numbering' => 'global',
                'createdAt' => $t,
                'updatedAt' => $t,
                'groups' => [
                    [
                        'id' => 'group-001',
                        'title' => '基本アンケート',
                        'questions' => [
                            [
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
            'verify_ssl' => true,
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

function load_data(): array
{
    $d = load_json(DATA_FILE, default_data());

    foreach ([
        'surveys',
        'answers',
        'customers',
        'send_history',
    ] as $key) {
        if (!isset($d[$key]) || !is_array($d[$key])) {
            $d[$key] = [];
        }
    }

    return $d;
}

function save_data(array $data): void
{
    save_json(DATA_FILE, $data);
}

function load_settings(): array
{
    $default = default_settings();
    $s = load_json(SETTINGS_FILE, $default);

    $s['kintone'] = array_replace_recursive(
        $default['kintone'],
        is_array($s['kintone'] ?? null)
            ? $s['kintone']
            : []
    );

    $s['mail'] = array_replace_recursive(
        $default['mail'],
        is_array($s['mail'] ?? null)
            ? $s['mail']
            : []
    );

    return $s;
}

function save_settings(array $settings): void
{
    save_json(SETTINGS_FILE, $settings);
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

function consume_flash(): ?array
{
    $v = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($v) ? $v : null;
}

/* =========================================================
 * Survey
 * ========================================================= */

function survey_index(array $surveys, string $id): int
{
    foreach ($surveys as $i => $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

function survey_by_id(array $surveys, string $id): ?array
{
    $i = survey_index($surveys, $id);

    return $i >= 0 ? $surveys[$i] : null;
}

function auto_update_status(array &$survey): bool
{
    if (
        ($survey['status'] ?? '') === 'published'
        && !empty($survey['endAt'])
        && ($end = strtotime((string)$survey['endAt'])) !== false
        && $end < time()
    ) {
        $survey['status'] = 'ended';
        $survey['updatedAt'] = now();

        return true;
    }

    return false;
}

function refresh_statuses(array &$data): bool
{
    $changed = false;

    foreach ($data['surveys'] as &$survey) {
        if (auto_update_status($survey)) {
            $changed = true;
        }
    }

    unset($survey);

    return $changed;
}

function recalc_numbers(array &$survey): void
{
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            if (($survey['numbering'] ?? 'global') === 'group') {
                $question['number'] =
                    'Q' . $groupNo . '-' . $questionNo;
            } else {
                $question['number'] = 'Q' . $global;
            }

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
        foreach ($group['questions'] ?? [] as $q) {
            $result[] = $q;
        }
    }

    return $result;
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
        'ended' => 'gray',
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

    $value = rtrim($value, '/');

    if (str_ends_with(
        strtolower($value),
        '.cybozu.com'
    )) {
        $value = substr(
            $value,
            0,
            -strlen('.cybozu.com')
        );
    }

    return $value;
}

function validate_kintone_config(
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

    $appId = (string)($config['app_id'] ?? '');

    if (!ctype_digit($appId) || (int)$appId < 1) {
        $errors[] = 'kintoneアプリIDが不正です。';
    }

    if (trim((string)($config['username'] ?? '')) === '') {
        $errors[] = 'kintoneログイン名を入力してください。';
    }

    if (
        $requirePassword
        && trim((string)($config['password'] ?? '')) === ''
    ) {
        $errors[] = 'kintoneパスワードを入力してください。';
    }

    $proxy = trim((string)($config['proxy'] ?? ''));

    if (
        $proxy !== ''
        && !preg_match('/^[^:\s]+:\d{1,5}$/', $proxy)
    ) {
        $errors[] = 'Proxyはhost:port形式で入力してください。';
    }

    return $errors;
}

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $errors = validate_kintone_config($config, true);

    if ($errors) {
        throw new RuntimeException(implode("\n", $errors));
    }

    $subdomain = normalize_kintone_subdomain(
        (string)$config['subdomain']
    );

    $url =
        'https://'
        . $subdomain
        . '.cybozu.com'
        . $path;

    $authorization = base64_encode(
        (string)$config['username']
        . ':'
        . (string)$config['password']
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
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
                'kintoneリクエスト生成に失敗しました。'
            );
        }

        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($content);
    }

    $verify = !empty($config['verify_ssl']);

    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => READ_TIMEOUT,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
            'peer_name' => $subdomain . '.cybozu.com',
        ],
    ];

    $proxy = trim((string)($config['proxy'] ?? ''));

    if ($proxy !== '') {
        [$host, $port] = explode(':', $proxy, 2);

        $options['http']['proxy'] =
            'tcp://' . $host . ':' . (int)$port;

        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    $status = 0;

    foreach ($http_response_header ?? [] as $header) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})/',
                $header,
                $m
            )
        ) {
            $status = (int)$m[1];
        }
    }

    if ($response === false) {
        throw new RuntimeException(
            $status === 0
                ? 'kintoneからレスポンスを取得できませんでした。'
                : 'kintone通信に失敗しました。HTTP ' . $status
        );
    }

    if ($status === 0) {
        throw new RuntimeException(
            'kintoneからHTTPステータスを取得できませんでした。'
        );
    }

    $json = json_decode($response, true);

    if ($status === 302 || $status === 303) {
        throw new RuntimeException(
            'kintoneがリダイレクト応答 '
            . $status
            . ' を返しました。API URL・認証方式・ネットワーク設定を確認してください。'
        );
    }

    if ($status < 200 || $status >= 300) {
        $code = is_array($json)
            ? (string)($json['code'] ?? '')
            : '';

        $message = is_array($json)
            ? (string)($json['message'] ?? '')
            : '';

        $detail = 'kintone APIエラー';

        if ($code !== '') {
            $detail .= ' [' . $code . ']';
        }

        if ($message !== '') {
            $detail .= ' ' . $message;
        }

        $detail .= ' HTTP ' . $status;

        throw new RuntimeException($detail);
    }

    return [
        'status' => $status,
        'body' => $response,
        'json' => is_array($json) ? $json : [],
    ];
}

function kintone_test(array $config): array
{
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?id='
        . rawurlencode((string)$config['app_id'])
    );
}

function kintone_get_fields(array $config): array
{
    $result = kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app='
        . rawurlencode((string)$config['app_id'])
    );

    return $result['json'];
}

function kintone_get_records(
    array $config,
    int $offset = 0,
    int $limit = 500
): array {
    $query = http_build_query([
        'app' => (int)$config['app_id'],
        'query' => 'order by $id asc limit ' . $limit
            . ' offset ' . $offset,
    ]);

    return kintone_request(
        $config,
        'GET',
        '/k/v1/records.json?' . $query
    )['json'];
}

function extract_field_value(array $record, string $fieldCode): string
{
    if ($fieldCode === '' || !isset($record[$fieldCode])) {
        return '';
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
                $parts[] =
                    (string)($item['name']
                        ?? $item['code']
                        ?? $item['value']
                        ?? '');
            } else {
                $parts[] = (string)$item;
            }
        }

        return implode(', ', $parts);
    }

    return (string)$value;
}

function sync_kintone_customers(
    array $config
): array {
    $all = [];
    $offset = 0;

    do {
        $result = kintone_get_records(
            $config,
            $offset,
            500
        );

        $records = $result['records'] ?? [];

        if (!is_array($records)) {
            throw new RuntimeException(
                'kintoneレコード取得結果が不正です。'
            );
        }

        foreach ($records as $record) {
            if (is_array($record)) {
                $all[] = $record;
            }
        }

        $count = count($records);
        $offset += $count;
    } while ($count === 500);

    return $all;
}

/* =========================================================
 * SMTP
 * ========================================================= */

function validate_mail_config(
    array $config
): array {
    $errors = [];

    if (trim((string)($config['host'] ?? '')) === '') {
        $errors[] = 'SMTPホストを入力してください。';
    }

    $port = (int)($config['port'] ?? 0);

    if ($port < 1 || $port > 65535) {
        $errors[] = 'SMTPポートが不正です。';
    }

    if (
        !in_array(
            (string)($config['encryption'] ?? ''),
            ['none', 'tls', 'ssl'],
            true
        )
    ) {
        $errors[] = 'SMTP暗号化方式が不正です。';
    }

    if (
        trim((string)($config['from_email'] ?? '')) === ''
        || !filter_var(
            $config['from_email'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] = '送信元メールアドレスが不正です。';
    }

    if (!empty($config['auth'])) {
        if (trim((string)($config['username'] ?? '')) === '') {
            $errors[] = 'SMTPユーザー名を入力してください。';
        }

        if (trim((string)($config['password'] ?? '')) === '') {
            $errors[] = 'SMTPパスワードを入力してください。';
        }
    }

    return $errors;
}

function smtp_read($socket): string
{
    $result = '';

    while (($line = fgets($socket, 515)) !== false) {
        $result .= $line;

        if (
            strlen($line) >= 4
            && $line[3] === ' '
        ) {
            break;
        }
    }

    if ($result === '') {
        throw new RuntimeException(
            'SMTPレスポンスを取得できませんでした。'
        );
    }

    return $result;
}

function smtp_expect(
    $socket,
    array $expected
): string {
    $response = smtp_read($socket);

    $code = (int)substr($response, 0, 3);

    if (!in_array($code, $expected, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' . trim($response)
        );
    }

    return $response;
}

function smtp_command(
    $socket,
    string $command,
    array $expected
): string {
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException(
            'SMTPコマンド送信に失敗しました。'
        );
    }

    return smtp_expect($socket, $expected);
}

function smtp_connect(array $config)
{
    $errors = validate_mail_config($config);

    if ($errors) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    $host = (string)$config['host'];
    $port = (int)$config['port'];
    $encryption = (string)$config['encryption'];

    if ($encryption === 'ssl') {
        $target = 'ssl://' . $host;
    } else {
        $target = $host;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $target . ':' . $port,
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($socket)) {
        throw new RuntimeException(
            'SMTP接続失敗: '
            . $errstr
            . ' ('
            . $errno
            . ')'
        );
    }

    stream_set_timeout($socket, READ_TIMEOUT);

    smtp_expect($socket, [220]);

    $ehlo = smtp_command(
        $socket,
        'EHLO localhost',
        [250]
    );

    if (
        $encryption === 'tls'
        && stripos($ehlo, 'STARTTLS') !== false
    ) {
        smtp_command(
            $socket,
            'STARTTLS',
            [220]
        );

        $crypto = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            throw new RuntimeException(
                'SMTP STARTTLSの確立に失敗しました。'
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

function smtp_test(array $config): void
{
    $socket = smtp_connect($config);

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
    if (
        !filter_var(
            $to,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new RuntimeException(
            '宛先メールアドレスが不正です。'
        );
    }

    $socket = smtp_connect($config);

    try {
        $from = (string)$config['from_email'];

        smtp_command(
            $socket,
            'MAIL FROM:<'
            . $from
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
            (string)($config['from_name'] ?? '');

        $headers = [
            'From: '
            . ($fromName !== ''
                ? mb_encode_mimeheader(
                    $fromName,
                    'UTF-8'
                ) . ' <' . $from . '>'
                : $from),
            'To: ' . $to,
            'Subject: '
            . mb_encode_mimeheader(
                $subject,
                'UTF-8'
            ),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if (
            trim((string)($config['reply_to'] ?? '')) !== ''
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
            implode("\r\n", $headers)
            . "\r\n\r\n"
            . preg_replace(
                "/(?<!\r)\n/",
                "\r\n",
                $body
            )
            . "\r\n.";

        smtp_command(
            $socket,
            $message,
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
 * 入力
 * ========================================================= */

function validate_survey_input(): array
{
    $errors = [];

    $title = post_string('title');
    $description = (string)($_POST['description'] ?? '');
    $startAt = post_string('startAt');
    $endAt = post_string('endAt');

    $numbering = post_string('numbering');

    if ($title === '') {
        $errors[] = 'アンケートタイトルを入力してください。';
    }

    if (mb_strlen($title) > MAX_TITLE) {
        $errors[] = 'アンケートタイトルが長すぎます。';
    }

    if (mb_strlen($description) > MAX_DESCRIPTION) {
        $errors[] = 'アンケート説明が長すぎます。';
    }

    if (
        $startAt !== ''
        && strtotime($startAt) === false
    ) {
        $errors[] = '開始日時が不正です。';
    }

    if (
        $endAt !== ''
        && strtotime($endAt) === false
    ) {
        $errors[] = '終了日時が不正です。';
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && strtotime($startAt) !== false
        && strtotime($endAt) !== false
        && strtotime($endAt) < strtotime($startAt)
    ) {
        $errors[] =
            '終了日時は開始日時以降にしてください。';
    }

    if (!in_array(
        $numbering,
        ['global', 'group'],
        true
    )) {
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

/* =========================================================
 * POST
 * ========================================================= */

function handle_post(
    array &$data,
    array &$settings
): ?array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return null;
    }

    check_csrf();

    $action = post_string('action');

    switch ($action) {
        /* -------------------------------------------------
         * kintone設定保存
         * ------------------------------------------------- */
        case 'save_kintone':
            $old = $settings['kintone'];

            $password = post_string('password');

            $settings['kintone'] = [
                'subdomain' => normalize_kintone_subdomain(
                    post_string('subdomain')
                ),
                'app_id' => post_string('app_id'),
                'username' => post_string('username'),
                'password' =>
                    $password !== ''
                        ? $password
                        : (string)($old['password'] ?? ''),
                'proxy' => post_string('proxy'),
                'verify_ssl' => post_string('verify_ssl') === '1',
                'mapping' =>
                    is_array($old['mapping'] ?? null)
                        ? $old['mapping']
                        : default_settings()['kintone']['mapping'],
                'fields' =>
                    is_array($old['fields'] ?? null)
                        ? $old['fields']
                        : [],
                'last_test' =>
                    $old['last_test'] ?? null,
                'last_sync' =>
                    $old['last_sync'] ?? null,
            ];

            $errors = validate_kintone_config(
                $settings['kintone'],
                true
            );

            if ($errors) {
                throw new RuntimeException(
                    implode("\n", $errors)
                );
            }

            save_settings($settings);

            flash(
                'success',
                'kintone設定を保存しました。'
            );

            return [
                'screen' => 'kintone',
            ];

        /* -------------------------------------------------
         * kintone接続テスト
         *
         * 保存済み設定を使用する。
         * パスワードをhiddenで再送しない。
         * ------------------------------------------------- */
        case 'test_kintone':
            $config = $settings['kintone'];

            kintone_test($config);

            $settings['kintone']['last_test'] = [
                'at' => now(),
                'result' => 'success',
            ];

            save_settings($settings);

            flash(
                'success',
                'kintone接続テストに成功しました。'
            );

            return [
                'screen' => 'kintone',
            ];

        /* -------------------------------------------------
         * kintone項目取得
         * ------------------------------------------------- */
        case 'fetch_kintone_fields':
            $fields = kintone_get_fields(
                $settings['kintone']
            );

            $settings['kintone']['fields'] =
                is_array($fields['properties'] ?? null)
                    ? $fields['properties']
                    : [];

            save_settings($settings);

            flash(
                'success',
                'kintoneの項目情報を取得しました。'
            );

            return [
                'screen' => 'kintone',
            ];

        /* -------------------------------------------------
         * kintone顧客同期
         * ------------------------------------------------- */
        case 'sync_kintone':
            $records = sync_kintone_customers(
                $settings['kintone']
            );

            $mapping =
                $settings['kintone']['mapping']
                ?? [];

            $customers = [];

            foreach ($records as $record) {
                $name = extract_field_value(
                    $record,
                    (string)($mapping['name'] ?? '')
                );

                $email = extract_field_value(
                    $record,
                    (string)($mapping['email'] ?? '')
                );

                if ($email === '') {
                    continue;
                }

                $customers[] = [
                    'id' => uuid('customer'),
                    'source' => 'kintone',
                    'organization' =>
                        extract_field_value(
                            $record,
                            (string)($mapping['organization'] ?? '')
                        ),
                    'name' => $name,
                    'email' => $email,
                    'department' =>
                        extract_field_value(
                            $record,
                            (string)($mapping['department'] ?? '')
                        ),
                    'phone' =>
                        extract_field_value(
                            $record,
                            (string)($mapping['phone'] ?? '')
                        ),
                    'address' =>
                        extract_field_value(
                            $record,
                            (string)($mapping['address'] ?? '')
                        ),
                    'raw' => $record,
                    'updatedAt' => now(),
                ];
            }

            $data['customers'] = $customers;

            $settings['kintone']['last_sync'] = [
                'at' => now(),
                'count' => count($customers),
                'result' => 'success',
            ];

            save_data($data);
            save_settings($settings);

            flash(
                'success',
                count($customers)
                . '件の顧客情報を同期しました。'
            );

            return [
                'screen' => 'kintone',
            ];

        /* -------------------------------------------------
         * kintoneマッピング保存
         * ------------------------------------------------- */
        case 'save_kintone_mapping':
            $settings['kintone']['mapping'] = [
                'organization' => post_string('map_organization'),
                'name' => post_string('map_name'),
                'email' => post_string('map_email'),
                'department' => post_string('map_department'),
                'phone' => post_string('map_phone'),
                'address' => post_string('map_address'),
            ];

            save_settings($settings);

            flash(
                'success',
                'kintone項目マッピングを保存しました。'
            );

            return [
                'screen' => 'kintone',
            ];

        /* -------------------------------------------------
         * SMTP保存
         * ------------------------------------------------- */
        case 'save_mail':
            $old = $settings['mail'];

            $password = post_string('password');

            $settings['mail'] = [
                'host' => post_string('host'),
                'port' => (int)post_string('port'),
                'encryption' => post_string('encryption'),
                'auth' => post_string('auth') === '1',
                'username' => post_string('username'),
                'password' =>
                    $password !== ''
                        ? $password
                        : (string)($old['password'] ?? ''),
                'from_email' => post_string('from_email'),
                'from_name' => post_string('from_name'),
                'reply_to' => post_string('reply_to'),
                'last_test' => $old['last_test'] ?? null,
            ];

            $errors = validate_mail_config(
                $settings['mail']
            );

            if ($errors) {
                throw new RuntimeException(
                    implode("\n", $errors)
                );
            }

            save_settings($settings);

            flash(
                'success',
                'メールサーバ設定を保存しました。'
            );

            return [
                'screen' => 'mail',
            ];

        /* -------------------------------------------------
         * SMTP接続テスト
         *
         * 保存済みパスワードを使用。
         * ------------------------------------------------- */
        case 'test_mail':
            smtp_test($settings['mail']);

            $settings['mail']['last_test'] = [
                'at' => now(),
                'result' => 'success',
            ];

            save_settings($settings);

            flash(
                'success',
                'SMTP接続テストに成功しました。'
            );

            return [
                'screen' => 'mail',
            ];

        /* -------------------------------------------------
         * アンケート保存
         * ------------------------------------------------- */
        case 'save_survey':
            $input = validate_survey_input();

            if ($input['errors']) {
                throw new RuntimeException(
                    implode("\n", $input['errors'])
                );
            }

            $id = post_string('survey_id');
            $index = survey_index(
                $data['surveys'],
                $id
            );

            $groups = [];

            $rawGroups = $_POST['groups'] ?? [];

            if (is_array($rawGroups)) {
                foreach ($rawGroups as $rawGroup) {
                    if (!is_array($rawGroup)) {
                        continue;
                    }

                    $groupId =
                        trim((string)($rawGroup['id'] ?? ''));

                    if ($groupId === '') {
                        $groupId = uuid('group');
                    }

                    $group = [
                        'id' => $groupId,
                        'title' =>
                            trim((string)(
                                $rawGroup['title'] ?? ''
                            )),
                        'questions' => [],
                    ];

                    $rawQuestions =
                        $rawGroup['questions']
                        ?? [];

                    if (is_array($rawQuestions)) {
                        foreach ($rawQuestions as $rawQuestion) {
                            if (!is_array($rawQuestion)) {
                                continue;
                            }

                            $qId =
                                trim((string)(
                                    $rawQuestion['id'] ?? ''
                                ));

                            if ($qId === '') {
                                $qId = uuid('question');
                            }

                            $type =
                                (string)(
                                    $rawQuestion['type']
                                    ?? 'single'
                                );

                            if (!in_array(
                                $type,
                                [
                                    'single',
                                    'multiple',
                                    'text',
                                ],
                                true
                            )) {
                                $type = 'single';
                            }

                            $question = [
                                'id' => $qId,
                                'number' => '',
                                'text' =>
                                    trim((string)(
                                        $rawQuestion['text']
                                        ?? ''
                                    )),
                                'type' => $type,
                                'required' =>
                                    !empty(
                                        $rawQuestion['required']
                                    ),
                                'options' => [],
                            ];

                            $rawOptions =
                                $rawQuestion['options']
                                ?? [];

                            if (
                                $type !== 'text'
                                && is_array($rawOptions)
                            ) {
                                foreach ($rawOptions as $rawOption) {
                                    if (!is_array($rawOption)) {
                                        continue;
                                    }

                                    $optionId =
                                        trim((string)(
                                            $rawOption['id']
                                            ?? ''
                                        ));

                                    if ($optionId === '') {
                                        $optionId =
                                            uuid('option');
                                    }

                                    $question['options'][] = [
                                        'id' => $optionId,
                                        'label' =>
                                            trim((string)(
                                                $rawOption['label']
                                                ?? ''
                                            )),
                                        'nextQuestionId' =>
                                            trim((string)(
                                                $rawOption[
                                                    'nextQuestionId'
                                                ] ?? ''
                                            )),
                                    ];
                                }
                            }

                            $group['questions'][] =
                                $question;
                        }
                    }

                    $groups[] = $group;
                }
            }

            if (!$groups) {
                $groups[] = [
                    'id' => uuid('group'),
                    'title' => '基本アンケート',
                    'questions' => [],
                ];
            }

            $survey = [
                'id' =>
                    $id !== ''
                        ? $id
                        : uuid('survey'),
                'title' => $input['title'],
                'description' => $input['description'],
                'startAt' => $input['startAt'],
                'endAt' => $input['endAt'],
                'status' =>
                    $index >= 0
                        ? $data['surveys'][$index]['status']
                        : 'draft',
                'numbering' => $input['numbering'],
                'createdAt' =>
                    $index >= 0
                        ? $data['surveys'][$index]['createdAt']
                        : now(),
                'updatedAt' => now(),
                'groups' => $groups,
            ];

            if (
                $index >= 0
                && ($data['surveys'][$index]['status'] ?? '')
                    === 'ended'
            ) {
                $survey['status'] = 'ended';
            }

            recalc_numbers($survey);

            if ($index >= 0) {
                $data['surveys'][$index] = $survey;
            } else {
                $data['surveys'][] = $survey;
            }

            save_data($data);

            flash(
                'success',
                'アンケートを保存しました。'
            );

            return [
                'screen' => 'list',
            ];

        /* -------------------------------------------------
         * 公開
         * ------------------------------------------------- */
        case 'publish':
            $id = post_string('survey_id');
            $index = survey_index($data['surveys'], $id);

            if ($index < 0) {
                throw new RuntimeException(
                    'アンケートが見つかりません。'
                );
            }

            if (
                ($data['surveys'][$index]['status'] ?? '')
                === 'ended'
            ) {
                throw new RuntimeException(
                    '終了したアンケートは公開できません。'
                );
            }

            $data['surveys'][$index]['status'] = 'published';
            $data['surveys'][$index]['updatedAt'] = now();

            save_data($data);

            flash(
                'success',
                'アンケートを公開しました。'
            );

            return ['screen' => 'list'];

        /* -------------------------------------------------
         * 停止
         * ------------------------------------------------- */
        case 'stop':
            $id = post_string('survey_id');
            $index = survey_index($data['surveys'], $id);

            if ($index < 0) {
                throw new RuntimeException(
                    'アンケートが見つかりません。'
                );
            }

            if (
                ($data['surveys'][$index]['status'] ?? '')
                !== 'published'
            ) {
                throw new RuntimeException(
                    '公開中のアンケートだけ停止できます。'
                );
            }

            $data['surveys'][$index]['status'] = 'stopped';
            $data['surveys'][$index]['updatedAt'] = now();

            save_data($data);

            flash(
                'success',
                'アンケートを停止しました。'
            );

            return ['screen' => 'list'];

        /* -------------------------------------------------
         * 再開
         * ------------------------------------------------- */
        case 'resume':
            $id = post_string('survey_id');
            $index = survey_index($data['surveys'], $id);

            if ($index < 0) {
                throw new RuntimeException(
                    'アンケートが見つかりません。'
                );
            }

            if (
                ($data['surveys'][$index]['status'] ?? '')
                !== 'stopped'
            ) {
                throw new RuntimeException(
                    '停止中のアンケートだけ再開できます。'
                );
            }

            $data['surveys'][$index]['status'] = 'published';
            $data['surveys'][$index]['updatedAt'] = now();

            save_data($data);

            flash(
                'success',
                'アンケートを再開しました。'
            );

            return ['screen' => 'list'];

        /* -------------------------------------------------
         * 複製
         * ------------------------------------------------- */
        case 'duplicate':
            $id = post_string('survey_id');
            $survey = survey_by_id(
                $data['surveys'],
                $id
            );

            if ($survey === null) {
                throw new RuntimeException(
                    'アンケートが見つかりません。'
                );
            }

            $survey['id'] = uuid('survey');
            $survey['title'] .= '（複製）';
            $survey['status'] = 'draft';
            $survey['createdAt'] = now();
            $survey['updatedAt'] = now();

            foreach ($survey['groups'] as &$group) {
                $group['id'] = uuid('group');

                foreach ($group['questions'] as &$question) {
                    $oldQuestionId = $question['id'];
                    $question['id'] = uuid('question');

                    foreach ($question['options'] as &$option) {
                        $option['id'] = uuid('option');
                        $option['nextQuestionId'] = '';
                    }

                    unset($option);
                }

                unset($question);
            }

            unset($group);

            recalc_numbers($survey);

            $data['surveys'][] = $survey;

            save_data($data);

            flash(
                'success',
                'アンケートを複製しました。'
            );

            return ['screen' => 'list'];

        /* -------------------------------------------------
         * 削除
         * ------------------------------------------------- */
        case 'delete':
            $id = post_string('survey_id');
            $index = survey_index($data['surveys'], $id);

            if ($index < 0) {
                throw new RuntimeException(
                    'アンケートが見つかりません。'
                );
            }

            if (
                ($data['surveys'][$index]['status'] ?? '')
                === 'published'
            ) {
                throw new RuntimeException(
                    '公開中のアンケートは削除できません。'
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

            return ['screen' => 'list'];

        /* -------------------------------------------------
         * 回答確認
         * ------------------------------------------------- */
        case 'answer_confirm':
            $surveyId = post_string('survey_id');

            $survey = survey_by_id(
                $data['surveys'],
                $surveyId
            );

            if ($survey === null) {
                throw new RuntimeException(
                    'アンケートが見つかりません。'
                );
            }

            $answers = $_POST['answers'] ?? [];

            if (!is_array($answers)) {
                $answers = [];
            }

            $_SESSION['pending_answer'] = [
                'survey_id' => $surveyId,
                'answers' => $answers,
            ];

            return [
                'screen' => 'confirm',
                'id' => $surveyId,
            ];

        /* -------------------------------------------------
         * 回答修正
         * ------------------------------------------------- */
        case 'answer_back':
            $surveyId = post_string('survey_id');

            return [
                'screen' => 'answer',
                'id' => $surveyId,
            ];

        /* -------------------------------------------------
         * 回答送信
         * ------------------------------------------------- */
        case 'submit_answer':
            $surveyId = post_string('survey_id');

            $survey = survey_by_id(
                $data['surveys'],
                $surveyId
            );

            if ($survey === null) {
                throw new RuntimeException(
                    'アンケートが見つかりません。'
                );
            }

            $pending =
                $_SESSION['pending_answer']
                ?? null;

            if (
                !is_array($pending)
                || ($pending['survey_id'] ?? '') !== $surveyId
            ) {
                throw new RuntimeException(
                    '回答情報がありません。最初から回答してください。'
                );
            }

            $data['answers'][] = [
                'id' => uuid('answer'),
                'survey_id' => $surveyId,
                'answers' =>
                    is_array($pending['answers'] ?? null)
                        ? $pending['answers']
                        : [],
                'submittedAt' => now(),
            ];

            unset($_SESSION['pending_answer']);

            save_data($data);

            return [
                'screen' => 'complete',
                'id' => $surveyId,
            ];

        /* -------------------------------------------------
         * メール送信
         * ------------------------------------------------- */
        case 'send_mail':
            $surveyId = post_string('survey_id');

            $survey = survey_by_id(
                $data['surveys'],
                $surveyId
            );

            if ($survey === null) {
                throw new RuntimeException(
                    'アンケートが見つかりません。'
                );
            }

            $selected = $_POST['customers'] ?? [];

            if (!is_array($selected)) {
                $selected = [];
            }

            $selected = array_values(array_unique(
                array_map(
                    'strval',
                    $selected
                )
            ));

            if (!$selected) {
                throw new RuntimeException(
                    '送信対象の顧客を選択してください。'
                );
            }

            $subject = post_string('subject');
            $body = (string)($_POST['body'] ?? '');

            if ($subject === '') {
                throw new RuntimeException(
                    'メール件名を入力してください。'
                );
            }

            if (mb_strlen($body) > MAX_MAIL_BODY) {
                throw new RuntimeException(
                    'メール本文が長すぎます。'
                );
            }

            $count = 0;
            $errors = [];

            foreach ($data['customers'] as $customer) {
                $customerId =
                    (string)($customer['id'] ?? '');

                if (!in_array(
                    $customerId,
                    $selected,
                    true
                )) {
                    continue;
                }

                $email =
                    (string)($customer['email'] ?? '');

                if (
                    !filter_var(
                        $email,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    $errors[] =
                        ($customer['name'] ?? '')
                        . ': メールアドレス不正';

                    continue;
                }

                try {
                    $personalizedBody = str_replace(
                        [
                            '{{name}}',
                            '{{organization}}',
                        ],
                        [
                            (string)($customer['name'] ?? ''),
                            (string)($customer['organization'] ?? ''),
                        ],
                        $body
                    );

                    $personalizedBody .=
                        "\n\n回答URL:\n"
                        . public_answer_url($surveyId);

                    smtp_send(
                        $settings['mail'],
                        $email,
                        $subject,
                        $personalizedBody
                    );

                    $data['send_history'][] = [
                        'id' => uuid('send'),
                        'survey_id' => $surveyId,
                        'customer_id' => $customerId,
                        'email' => $email,
                        'subject' => $subject,
                        'sentAt' => now(),
                        'status' => 'success',
                    ];

                    $count++;
                } catch (Throwable $e) {
                    $errors[] =
                        ($customer['name'] ?? '')
                        . ': '
                        . $e->getMessage();

                    $data['send_history'][] = [
                        'id' => uuid('send'),
                        'survey_id' => $surveyId,
                        'customer_id' => $customerId,
                        'email' => $email,
                        'subject' => $subject,
                        'sentAt' => now(),
                        'status' => 'error',
                    ];
                }
            }

            save_data($data);

            if ($errors) {
                flash(
                    $count > 0 ? 'success' : 'error',
                    '送信成功 '
                    . $count
                    . '件。'
                    . implode(' / ', $errors)
                );
            } else {
                flash(
                    'success',
                    $count . '件送信しました。'
                );
            }

            return [
                'screen' => 'send',
                'id' => $surveyId,
            ];

        default:
            throw new RuntimeException(
                '未対応の操作です。'
            );
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
    --success:#15803d;
    --warning:#b45309;
    --danger:#b91c1c;
    --border:#dbe1ea;
    --bg:#f5f7fb;
    --text:#1f2937;
    --muted:#6b7280;
    --card:#fff;
}
*{box-sizing:border-box}
body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:
        -apple-system,BlinkMacSystemFont,
        "Segoe UI","Hiragino Kaku Gothic ProN",
        "Yu Gothic",Meiryo,sans-serif;
    line-height:1.6;
}
a{color:var(--primary)}
.container{
    max-width:1200px;
    margin:auto;
    padding:24px;
}
.header{
    background:#111827;
    color:#fff;
}
.header-inner{
    max-width:1200px;
    margin:auto;
    padding:16px 24px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
}
.header a{
    color:#fff;
    text-decoration:none;
}
.nav{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}
.nav a{
    padding:7px 10px;
    border-radius:6px;
}
.nav a:hover{background:#374151}
.page-title{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    margin-bottom:20px;
}
.card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:10px;
    margin-bottom:20px;
    overflow:hidden;
}
.card-body{padding:22px}
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
label{
    display:block;
    font-weight:600;
}
label span{
    display:block;
    margin-bottom:5px;
}
input,select,textarea{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:7px;
    padding:9px 11px;
    background:#fff;
    color:#111827;
    font:inherit;
}
textarea{
    min-height:150px;
    resize:vertical;
}
.btn{
    display:inline-block;
    border:0;
    border-radius:7px;
    padding:9px 14px;
    cursor:pointer;
    text-decoration:none;
    font-weight:600;
    font:inherit;
}
.btn-primary{
    background:var(--primary);
    color:#fff;
}
.btn-primary:hover{
    background:var(--primary-dark);
}
.btn-secondary{
    background:#475569;
    color:#fff;
}
.btn-danger{
    background:var(--danger);
    color:#fff;
}
.btn-success{
    background:var(--success);
    color:#fff;
}
.btn-warning{
    background:var(--warning);
    color:#fff;
}
.button-row{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
}
.alert{
    border-radius:8px;
    padding:12px 15px;
    margin-bottom:18px;
    white-space:pre-line;
}
.alert-success{
    background:#dcfce7;
    color:#166534;
}
.alert-error{
    background:#fee2e2;
    color:#991b1b;
}
.alert-warning{
    background:#fef3c7;
    color:#92400e;
}
table{
    width:100%;
    border-collapse:collapse;
}
th,td{
    border-bottom:1px solid var(--border);
    padding:10px;
    text-align:left;
    vertical-align:top;
}
th{background:#f8fafc}
.table-wrap{
    overflow-x:auto;
}
.badge{
    display:inline-block;
    border-radius:999px;
    padding:3px 9px;
    font-size:.85rem;
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
    background:#e5e7eb;
    color:#374151;
}
.muted{color:var(--muted)}
.question-card{
    border:1px solid var(--border);
    border-radius:8px;
    padding:15px;
    margin-bottom:12px;
}
.option{
    padding:6px 0;
}
.answer-shell{
    max-width:760px;
    margin:auto;
    padding:20px;
}
.answer-nav{
    display:flex;
    justify-content:space-between;
    gap:10px;
}
.small{font-size:.9rem}
pre{
    overflow:auto;
    background:#111827;
    color:#e5e7eb;
    padding:12px;
    border-radius:7px;
}
@media(max-width:800px){
    .grid-2,.grid-3{
        grid-template-columns:1fr;
    }
    .header-inner{
        align-items:flex-start;
        flex-direction:column;
    }
    .page-title{
        align-items:flex-start;
        flex-direction:column;
    }
}
</style>
</head>
<body>
<?php if ($admin): ?>
<header class="header">
<div class="header-inner">
    <strong>
        <a href="<?= h(app_url()) ?>">
            <?= h(APP_TITLE) ?>
        </a>
    </strong>
    <nav class="nav">
        <a href="<?= h(app_url()) ?>">一覧</a>
        <a href="<?= h(app_url([
            'screen' => 'edit'
        ])) ?>">新規作成</a>
        <a href="<?= h(app_url([
            'screen' => 'kintone'
        ])) ?>">kintone設定</a>
        <a href="<?= h(app_url([
            'screen' => 'mail'
        ])) ?>">メール設定</a>
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
document.addEventListener('submit', function(e){
    const form = e.target;
    const message = form.dataset.confirm;

    if (message && !window.confirm(message)) {
        e.preventDefault();
    }
});

document.addEventListener('change', function(e){
    if (!e.target.matches('[data-toggle-required]')) {
        return;
    }

    const target = document.querySelector(
        e.target.dataset.toggleRequired
    );

    if (target) {
        target.disabled = !e.target.checked;
    }
});
</script>
</body>
</html>
<?php
}

/* =========================================================
 * 一覧
 * ========================================================= */

function render_list(array $data): void
{
    $flash = consume_flash();

    render_head('アンケート一覧');
?>
<main class="container">

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
<h1>アンケート一覧</h1>
<p class="muted">
アンケートの作成・公開・集計・送信を管理します。
</p>
</div>
<a class="btn btn-primary"
   href="<?= h(app_url([
       'screen' => 'edit'
   ])) ?>">
新規作成
</a>
</div>

<div class="card">
<div class="card-body">
<form method="get" class="grid grid-3">
<input type="hidden" name="screen" value="list">

<label>
<span>検索</span>
<input name="q"
       value="<?= h(get_string('q')) ?>"
       placeholder="タイトル">
</label>

<label>
<span>状態</span>
<select name="status">
<option value="">すべて</option>
<?php foreach ([
    'draft' => '下書き',
    'published' => '公開中',
    'stopped' => '停止',
    'ended' => '終了',
] as $value => $label): ?>
<option value="<?= h($value) ?>"
 <?= get_string('status') === $value
    ? 'selected'
    : '' ?>>
<?= h($label) ?>
</option>
<?php endforeach; ?>
</select>
</label>

<div style="align-self:end">
<button class="btn btn-secondary">
検索
</button>
</div>
</form>
</div>
</div>

<div class="card">
<div class="card-body">
<div class="table-wrap">
<table>
<thead>
<tr>
<th>タイトル</th>
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

foreach ($data['surveys'] as $survey):
    $title = (string)($survey['title'] ?? '');

    if (
        $q !== ''
        && mb_stripos($title, $q) === false
    ) {
        continue;
    }

    $status =
        (string)($survey['status'] ?? 'draft');

    if (
        $statusFilter !== ''
        && $status !== $statusFilter
    ) {
        continue;
    }

    $count = 0;

    foreach ($data['answers'] as $answer) {
        if (
            ($answer['survey_id'] ?? '')
            === ($survey['id'] ?? '')
        ) {
            $count++;
        }
    }
?>
<tr>
<td>
<strong><?= h($title) ?></strong>
</td>
<td>
<?= h($survey['startAt'] ?? '') ?><br>
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
<td><?= h($count) ?></td>
<td>
<div class="button-row">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'edit',
       'id' => $survey['id'],
   ])) ?>">
編集
</a>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'analytics',
       'id' => $survey['id'],
   ])) ?>">
集計
</a>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'send',
       'id' => $survey['id'],
   ])) ?>">
送信
</a>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'preview',
       'id' => $survey['id'],
   ])) ?>">
プレビュー
</a>

<?php if ($status === 'draft'): ?>
<form method="post"
      data-confirm="このアンケートを公開しますか？">
<input type="hidden"
       name="csrf"
       value="<?= csrf() ?>">
<input type="hidden"
       name="action"
       value="publish">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-success">
公開
</button>
</form>
<?php elseif ($status === 'published'): ?>
<form method="post"
      data-confirm="このアンケートを停止しますか？">
<input type="hidden"
       name="csrf"
       value="<?= csrf() ?>">
<input type="hidden"
       name="action"
       value="stop">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-warning">
停止
</button>
</form>
<?php elseif ($status === 'stopped'): ?>
<form method="post"
      data-confirm="このアンケートを再開しますか？">
<input type="hidden"
       name="csrf"
       value="<?= csrf() ?>">
<input type="hidden"
       name="action"
       value="resume">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-success">
再開
</button>
</form>
<?php endif; ?>

<form method="post"
      data-confirm="このアンケートを複製しますか？">
<input type="hidden"
       name="csrf"
       value="<?= csrf() ?>">
<input type="hidden"
       name="action"
       value="duplicate">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-secondary">
複製
</button>
</form>

<?php if ($status !== 'published'): ?>
<form method="post"
      data-confirm="このアンケートを削除しますか？">
<input type="hidden"
       name="csrf"
       value="<?= csrf() ?>">
<input type="hidden"
       name="action"
       value="delete">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-danger">
削除
</button>
</form>
<?php endif; ?>

</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</div>

</main>
<?php
render_footer();
}

/* =========================================================
 * 編集
 * ========================================================= */

function render_edit(?array $survey): void
{
    $isNew = $survey === null;

    if ($isNew) {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => date('Y-m-d\TH:i'),
            'endAt' => date(
                'Y-m-d\TH:i',
                strtotime('+30 days')
            ),
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [
                [
                    'id' => uuid('group'),
                    'title' => '基本アンケート',
                    'questions' => [],
                ],
            ],
        ];
    }

    render_head('アンケート作成・編集');
?>
<main class="container">

<div class="page-title">
<div>
<h1><?= $isNew
    ? 'アンケート作成'
    : 'アンケート編集' ?></h1>
<p class="muted">
質問番号は保存時に自動採番されます。
</p>
</div>
</div>

<form method="post">
<input type="hidden"
       name="csrf"
       value="<?= csrf() ?>">
<input type="hidden"
       name="action"
       value="save_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id'] ?? '') ?>">

<div class="card">
<div class="card-body">

<div class="grid grid-2">

<label>
<span>タイトル</span>
<input name="title"
       required
       maxlength="<?= MAX_TITLE ?>"
       value="<?= h($survey['title'] ?? '') ?>">
</label>

<label>
<span>採番方式</span>
<select name="numbering">
<option value="global"
 <?= ($survey['numbering'] ?? '')
    === 'global'
    ? 'selected'
    : '' ?>>
アンケート全体で通番
</option>
<option value="group"
 <?= ($survey['numbering'] ?? '')
    === 'group'
    ? 'selected'
    : '' ?>>
グループ毎
</option>
</select>
</label>

<label>
<span>開始日時</span>
<input type="datetime-local"
       name="startAt"
       value="<?= h($survey['startAt'] ?? '') ?>">
</label>

<label>
<span>終了日時</span>
<input type="datetime-local"
       name="endAt"
       value="<?= h($survey['endAt'] ?? '') ?>">
</label>

<label style="grid-column:1/-1">
<span>説明</span>
<textarea name="description"
          maxlength="<?= MAX_DESCRIPTION ?>"><?= h(
    $survey['description'] ?? ''
) ?></textarea>
</label>

</div>

</div>
</div>

<?php foreach (
    $survey['groups'] ?? []
    as $gi => $group
): ?>

<div class="card">
<div class="card-body">

<h2>グループ <?= h($gi + 1) ?></h2>

<input type="hidden"
       name="groups[<?= $gi ?>][id]"
       value="<?= h($group['id']) ?>">

<label>
<span>グループタイトル</span>
<input name="groups[<?= $gi ?>][title]"
       value="<?= h($group['title'] ?? '') ?>">
</label>

<div style="margin-top:18px">

<?php foreach (
    $group['questions'] ?? []
    as $qi => $question
): ?>

<div class="question-card">

<input type="hidden"
       name="groups[<?= $gi ?>][questions][<?= $qi ?>][id]"
       value="<?= h($question['id']) ?>">

<div class="grid grid-2">

<label>
<span>質問 <?= h($question['number'] ?? '') ?></span>
<textarea name="groups[<?= $gi ?>][questions][<?= $qi ?>][text]"
          maxlength="<?= MAX_QUESTION ?>"
          required><?= h(
    $question['text'] ?? ''
) ?></textarea>
</label>

<label>
<span>回答形式</span>
<select name="groups[<?= $gi ?>][questions][<?= $qi ?>][type]">
<option value="single"
 <?= ($question['type'] ?? '')
    === 'single'
    ? 'selected'
    : '' ?>>
単一選択
</option>
<option value="multiple"
 <?= ($question['type'] ?? '')
    === 'multiple'
    ? 'selected'
    : '' ?>>
複数選択
</option>
<option value="text"
 <?= ($question['type'] ?? '')
    === 'text'
    ? 'selected'
    : '' ?>>
自由記述
</option>
</select>

<label>
<span>必須</span>
<input type="checkbox"
       name="groups[<?= $gi ?>][questions][<?= $qi ?>][required]"
       value="1"
       <?= !empty($question['required'])
          ? 'checked'
          : '' ?>>
回答必須
</label>

</label>

</div>

<?php if (
    ($question['type'] ?? '')
    !== 'text'
): ?>

<h3>選択肢</h3>

<?php foreach (
    $question['options'] ?? []
    as $oi => $option
): ?>

<div class="grid grid-2 option">

<label>
<span>選択肢</span>
<input name="groups[<?= $gi ?>][questions][<?= $qi ?>][options][<?= $oi ?>][label]"
       value="<?= h($option['label'] ?? '') ?>">
</label>

<label>
<span>次の質問ID</span>
<input name="groups[<?= $gi ?>][questions][<?= $qi ?>][options][<?= $oi ?>][nextQuestionId]"
       value="<?= h(
           $option['nextQuestionId'] ?? ''
       ) ?>"
       placeholder="空欄=次の質問">
</label>

<input type="hidden"
       name="groups[<?= $gi ?>][questions][<?= $qi ?>][options][<?= $oi ?>][id]"
       value="<?= h($option['id']) ?>">

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

</div>
</div>

<?php endforeach; ?>

<div class="button-row">
<button class="btn btn-primary">
保存
</button>

<a class="btn btn-secondary"
   href="<?= h(app_url()) ?>">
キャンセル
</a>
</div>

</form>

</main>
<?php
render_footer();
}

/* =========================================================
 * プレビュー
 * ========================================================= */

function render_preview(array $survey): void
{
    render_head('プレビュー');
?>
<main class="container">

<div class="page-title">
<div>
<h1>プレビュー</h1>
<p><?= h($survey['title']) ?></p>
</div>
</div>

<div class="card">
<div class="card-body">

<p><?= nl2br(h(
    $survey['description'] ?? ''
)) ?></p>

<?php foreach (
    $survey['groups'] ?? []
    as $group
): ?>

<h2><?= h($group['title'] ?? '') ?></h2>

<?php foreach (
    $group['questions'] ?? []
    as $question
): ?>

<div class="question-card">
<strong>
<?= h($question['number'] ?? '') ?>
.
<?= h($question['text'] ?? '') ?>
</strong>

<?php if (
    ($question['type'] ?? '')
    === 'single'
): ?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>
<div class="option">
<label>
<input type="radio" disabled>
<?= h($option['label']) ?>
</label>
</div>
<?php endforeach; ?>

<?php elseif (
    ($question['type'] ?? '')
    === 'multiple'
): ?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>
<div class="option">
<label>
<input type="checkbox" disabled>
<?= h($option['label']) ?>
</label>
</div>
<?php endforeach; ?>

<?php else: ?>

<textarea disabled></textarea>

<?php endif; ?>

<?php if (!empty($question['required'])): ?>
<div class="small muted">必須</div>
<?php endif; ?>

</div>

<?php endforeach; ?>
<?php endforeach; ?>

</div>
</div>

</main>
<?php
render_footer();
}

/* =========================================================
 * 回答
 * ========================================================= */

function render_answer(array $survey): void
{
    render_head('アンケート回答', false);
?>
<div class="answer-shell">

<div class="card">
<div class="card-body">

<h1><?= h($survey['title']) ?></h1>

<p>
<?= nl2br(h(
    $survey['description'] ?? ''
)) ?>
</p>

<form method="post">

<input type="hidden"
       name="csrf"
       value="<?= csrf() ?>">

<input type="hidden"
       name="action"
       value="answer_confirm">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<?php foreach (
    $survey['groups'] ?? []
    as $group
): ?>

<h2><?= h($group['title'] ?? '') ?></h2>

<?php foreach (
    $group['questions'] ?? []
    as $question
): ?>

<div class="question-card">

<label>
<span>
<?= h($question['number'] ?? '') ?>.
<?= h($question['text'] ?? '') ?>

<?php if (!empty($question['required'])): ?>
<span style="display:inline;color:#b91c1c">
（必須）
</span>
<?php endif; ?>

</span>
</label>

<?php
$name =
    'answers['
    . $question['id']
    . ']';
?>

<?php if (
    ($question['type'] ?? '')
    === 'single'
): ?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>

<div class="option">
<label>
<input type="radio"
       name="<?= h($name) ?>"
       value="<?= h($option['id']) ?>"
       <?= !empty($question['required'])
          ? 'required'
          : '' ?>>
<?= h($option['label']) ?>
</label>
</div>

<?php endforeach; ?>

<?php elseif (
    ($question['type'] ?? '')
    === 'multiple'
): ?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>

<div class="option">
<label>
<input type="checkbox"
       name="<?= h($name) ?>[]"
       value="<?= h($option['id']) ?>">
<?= h($option['label']) ?>
</label>
</div>

<?php endforeach; ?>

<?php else: ?>

<textarea name="<?= h($name) ?>"
          <?= !empty($question['required'])
             ? 'required'
             : '' ?>></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>
<?php endforeach; ?>

<button class="btn btn-primary">
回答を確認する
</button>

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

function render_confirm(array $survey): void
{
    $pending =
        $_SESSION['pending_answer']
        ?? [];

    $answers =
        is_array($pending['answers'] ?? null)
            ? $pending['answers']
            : [];

    render_head('回答確認', false);
?>
<div class="answer-shell">

<div class="card">
<div class="card-body">

<h1>回答確認</h1>

<h2><?= h($survey['title']) ?></h2>

<?php foreach (
    $survey['groups'] ?? []
    as $group
): ?>

<h3><?= h($group['title'] ?? '') ?></h3>

<?php foreach (
    $group['questions'] ?? []
    as $question
): ?>

<?php
$value =
    $answers[$question['id']]
    ?? '';

$labels = [];

foreach (
    $question['options'] ?? []
    as $option
) {
    if (
        is_array($value)
        && in_array(
            $option['id'],
            $value,
            true
        )
    ) {
        $labels[] = $option['label'];
    } elseif (
        !is_array($value)
        && (string)$option['id']
            === (string)$value
    ) {
        $labels[] = $option['label'];
    }
}
?>

<div class="question-card">
<strong>
<?= h($question['number'] ?? '') ?>.
<?= h($question['text'] ?? '') ?>
</strong>

<p>
<?php if ($labels): ?>
<?= h(implode('、', $labels)) ?>
<?php else: ?>
<?= nl2br(h(
    is_array($value)
        ? implode('、', $value)
        : (string)$value
)) ?>
<?php endif; ?>
</p>
</div>

<?php endforeach; ?>
<?php endforeach; ?>

<div class="button-row">

<form method="post">
<input type="hidden"
       name="csrf"
       value="<?= csrf() ?>">
<input type="hidden"
       name="action"
       value="answer_back">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="btn btn-secondary">
修正する
</button>
</form>

<form method="post"
      data-confirm="回答を送信します。よろしいですか？">
<input type="hidden"
       name="csrf"
       value="<?= csrf() ?>">
<input type="hidden"
       name="action"
       value="submit_answer">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
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
 * 完了
 * ========================================================= */

function render_complete(array $survey): void
{
    render_head('回答完了', false);
?>
<div class="answer-shell">

<div class="card">
<div class="card-body"
     style="text-align:center;padding:55px 25px">

<h1>回答ありがとうございました</h1>

<p>
「<?= h($survey['title']) ?>」
への回答を受け付けました。
</p>

</div>
</div>

</div>
<?php
render_footer();
}

/* =========================================================
 * kintone設定
 * ========================================================= */

function render_kintone(array $config): void
{
    $flash = consume_flash();

    render_head('kintone設定');
?>
<main class="container">

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
<p class="muted">
設定保存、接続テスト、項目取得、顧客同期を独立して実行できます。
</p>
</div>
</div>

<div class="card">
<div class="card-body">

<h2>接続設定</h2>

<form method="post">

<input type="hidden"
       name="csrf"
       value="<?= csrf() ?>">

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid grid-2">

<label>
<span>サブドメイン</span>
<input name="subdomain"
       required
       value="<?= h(
           $config['subdomain'] ?? ''
       ) ?>"
       placeholder="xxxx.cybozu.com">
</label>

<label>
<span>顧客管理アプリID</span>
<input name="app_id"
       required
       inputmode="numeric"
       value="<?= h(
           $config['app_id'] ?? ''
       ) ?>">
</label>

<label>
<span>ログイン名</span>
<input name="username"
       required
       value="<?= h(
           $config['username'] ?? ''
       ) ?>">
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
    ? 'selected'
    : '' ?>>
無効
</option>
<option value="1"
 <?= !empty($config['verify_ssl'])
    ? 'selected'
    : '' ?>>
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

<hr>

<h2>kintone連携操作</h2>

<div class="button-row">

<form method="post">
<input type="hidden"
       name="csrf"
       value="<?= csrf() ?>">
<input type="hidden"
       name="action"
       value="test_kintone">
<button class="btn btn-secondary">
接続テスト
</button>
</form>

<form method="post"
      data-confirm="kintoneから項目情報を取得します。よろしいですか？">
<input type="hidden"
       name="csrf"
       value="<?= csrf() ?>">
<input type="hidden"
       name="action"
       value="fetch_kintone_fields">
<button class="btn btn-secondary">
項目取得
</button>
</form>

<form method="post"
      data-confirm="kintoneから顧客情報を同期します。よろしいですか？">
<input type="hidden"
       name="csrf"
       value="<?= csrf() ?>">
<input type="hidden"
       name="action"
       value="sync_kintone">
<button class="btn btn-success">
顧客同期
</button>
</form>

</div>

<?php if (!empty($config['last_test'])): ?>
<p class="small muted">
最終接続テスト:
<?= h($config['last_test']['at'] ?? '') ?>
</p>
<?php endif; ?>

<?php if (!empty($config['last_sync'])): ?>
<p class="small muted">
最終顧客同期:
<?= h($config['last_sync']['at'] ?? '') ?>
/
<?= h($config['last_sync']['count'] ?? 0) ?>件
</p>
<?php endif; ?>

</div>
</div>

<div class="card">
<div class="card-body">

<h2>顧客項目マッピング</h2>

<p class="muted">
先に「項目取得」を実行すると、
取得したフィールドコードを確認できます。
</p>

<?php
$fields =
    is_array($config['fields'] ?? null)
        ? $config['fields']
        : [];

$mapping =
    is_array($config['mapping'] ?? null)
        ? $config['mapping']
        : [];
?>

<?php if ($fields): ?>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>フィールドコード</th>
<th>ラベル</th>
<th>種類</th>
</tr>
</thead>
<tbody>
<?php foreach (
    $fields as $code => $field
):
    if (!is_array($field)) {
        continue;
    }
?>
<tr>
<td><?= h($code) ?></td>
<td><?= h($field['label'] ?? '') ?></td>
<td><?= h($field['type'] ?? '') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<form method="post"
      style="margin-top:20px">

<input type="hidden"
       name="csrf"
       value="<?= csrf() ?>">

<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<div class="grid grid-2">

<?php foreach ([
    'organization' => '会社名 / 組織名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署',
    'phone' => '電話番号',
    'address' => '住所',
] as $key => $label): ?>

<label>
<span><?= h($label) ?></span>
<select name="map_<?= h($key) ?>">
<option value="">未設定</option>
<?php foreach (
    $fields as $code => $field
):
    if (!is_array($field)) {
        continue;
    }
?>
<option value="<?= h($code) ?>"
 <?= (string)($mapping[$key] ?? '')
    === (string)$code
    ? 'selected'
    : '' ?>>
<?= h($code) ?>
 /
<?= h($field['label'] ?? '') ?>
</option>
<?php endforeach; ?>
</select>
</label>

<?php endforeach; ?>

</div>

<div class="button-row"
     style="margin-top:18px">
<button class="btn btn-primary">
マッピング保存
</button>
</div>

</form>

</div>
</div>

</main>
<?php
render_footer();
}

/* =========================================================
 * メール設定
 * ========================================================= */

function render_mail(array $config): void
{
    $flash = consume_flash();

    render_head('メールサーバ設定');
?>
<main class="container">

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
<p class="muted">
SMTP設定の保存と接続テストを独立して実行できます。
</p>
</div>
</div>

<div class="card">
<div class="card-body">

<form method="post">

<input type="hidden"
       name="csrf"
       value="<?= csrf() ?>">

<input type="hidden"
       name="action"
       value="save_mail">

<div class="grid grid-2">

<label>
<span>SMTPホスト</span>
<input name="host"
       required
       value="<?= h(
           $config['host'] ?? ''
       ) ?>">
</label>

<label>
<span>SMTPポート</span>
<input name="port"
       type="number"
       min="1"
       max="65535"
       value="<?= h(
           $config['port'] ?? 587
       ) ?>">
</label>

<label>
<span>暗号化方式</span>
<select name="encryption">
<?php foreach ([
    'none' => 'なし',
    'tls' => 'TLS',
    'ssl' => 'SSL',
] as $value => $label): ?>
<option value="<?= h($value) ?>"
 <?= ($config['encryption'] ?? '')
    === $value
    ? 'selected'
    : '' ?>>
<?= h($label) ?>
</option>
<?php endforeach; ?>
</select>
</label>

<label>
<span>SMTP認証</span>
<select name="auth">
<option value="1"
 <?= !empty($config['auth'])
    ? 'selected'
    : '' ?>>
使用する
</option>
<option value="0"
 <?= empty($config['auth'])
    ? 'selected'
    : '' ?>>
使用しない
</option>
</select>
</label>

<label>
<span>ユーザー名</span>
<input name="username"
       value="<?= h(
           $config['username'] ?? ''
       ) ?>">
</label>

<label>
<span>パスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</label>

<label>
<span>送信元メールアドレス</span>
<input type="email"
       name="from_email"
       required
       value="<?= h(
           $config['from_email'] ?? ''
       ) ?>">
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

<hr>

<form method="post">

<input type="hidden"
       name="csrf"
       value="<?= csrf() ?>">

<input type="hidden"
       name="action"
       value="test_mail">

<button class="btn btn-secondary">
接続テスト
</button>

</form>

<?php if (!empty($config['last_test'])): ?>
<p class="small muted">
最終接続テスト:
<?= h($config['last_test']['at'] ?? '') ?>
</p>
<?php endif; ?>

</div>
</div>

</main>
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
    $answers = [];

    foreach ($data['answers'] as $answer) {
        if (
            ($answer['survey_id'] ?? '')
            === ($survey['id'] ?? '')
        ) {
            $answers[] = $answer;
        }
    }

    render_head('回答集計・分析');
?>
<main class="container">

<div class="page-title">
<div>
<h1>回答集計・分析</h1>
<p><?= h($survey['title']) ?></p>
</div>
</div>

<div class="card">
<div class="card-body">

<p>
回答数:
<strong><?= h(count($answers)) ?></strong>
</p>

<?php foreach (
    $survey['groups'] ?? []
    as $group
): ?>

<h2><?= h($group['title'] ?? '') ?></h2>

<?php foreach (
    $group['questions'] ?? []
    as $question
): ?>

<?php
$counts = [];

foreach (
    $question['options'] ?? []
    as $option
) {
    $counts[$option['id']] = 0;
}

$textCount = 0;

foreach ($answers as $answer) {
    $value =
        $answer['answers'][$question['id']]
        ?? null;

    if (is_array($value)) {
        foreach ($value as $v) {
            if (isset($counts[$v])) {
                $counts[$v]++;
            }
        }
    } elseif (
        $value !== null
        && isset($counts[$value])
    ) {
        $counts[$value]++;
    } elseif (
        $value !== null
        && $value !== ''
    ) {
        $textCount++;
    }
}
?>

<div class="question-card">

<strong>
<?= h($question['number'] ?? '') ?>.
<?= h($question['text'] ?? '') ?>
</strong>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>

<p>
<?= h($option['label']) ?>:
<strong>
<?= h($counts[$option['id']] ?? 0) ?>
</strong>
</p>

<?php endforeach; ?>

<?php if (
    ($question['type'] ?? '')
    === 'text'
): ?>
<p>
回答:
<strong><?= h($textCount) ?></strong>
</p>
<?php endif; ?>

</div>

<?php endforeach; ?>
<?php endforeach; ?>

</div>
</div>

</main>
<?php
render_footer();
}

/* =========================================================
 * CSV
 * ========================================================= */

function output_csv(
    array $data,
    array $survey
): never {
    $filename =
        'survey-'
        . preg_replace(
            '/[^A-Za-z0-9_-]+/',
            '_',
            (string)$survey['id']
        )
        . '.csv';

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

    fwrite($fp, "\xEF\xBB\xBF");

    $questions = all_questions($survey);

    $header = ['回答ID', '回答日時'];

    foreach ($questions as $q) {
        $header[] =
            (string)($q['number'] ?? '')
            . ' '
            . (string)($q['text'] ?? '');
    }

    fputcsv($fp, $header);

    foreach ($data['answers'] as $answer) {
        if (
            ($answer['survey_id'] ?? '')
            !== ($survey['id'] ?? '')
        ) {
            continue;
        }

        $row = [
            (string)($answer['id'] ?? ''),
            (string)($answer['submittedAt'] ?? ''),
        ];

        foreach ($questions as $q) {
            $value =
                $answer['answers'][$q['id']]
                ?? '';

            if (is_array($value)) {
                $labels = [];

                foreach (
                    $q['options'] ?? []
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

                $value = implode(
                    '、',
                    $labels
                );
            } else {
                foreach (
                    $q['options'] ?? []
                    as $option
                ) {
                    if (
                        (string)$option['id']
                        === (string)$value
                    ) {
                        $value =
                            $option['label'];
                        break;
                    }
                }
            }

            $row[] = (string)$value;
        }

        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}

/* =========================================================
 * 送信画面
 * ========================================================= */

function render_send(
    array $data,
    array $survey
): void {
    $flash = consume_flash();

    render_head('顧客選択・メール送信');
?>
<main class="container">

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
対象アンケート:
<strong><?= h($survey['title']) ?></strong>
</p>
</div>
</div>

<div class="card">
<div class="card-body">

<h2>顧客選択</h2>

<form method="post">

<input type="hidden"
       name="csrf"
       value="<?= csrf() ?>">

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="table-wrap">
<table>
<thead>
<tr>
<th>選択</th>
<th>組織</th>
<th>氏名</th>
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
       name="customers[]"
       value="<?= h($customer['id']) ?>">
</td>
<td><?= h(
    $customer['organization'] ?? ''
) ?></td>
<td><?= h(
    $customer['name'] ?? ''
) ?></td>
<td><?= h(
    $customer['department'] ?? ''
) ?></td>
<td><?= h(
    $customer['email'] ?? ''
) ?></td>
</tr>

<?php endforeach; ?>

<?php if (!$data['customers']): ?>
<tr>
<td colspan="5">
顧客がありません。
kintone設定画面から顧客同期を実行してください。
</td>
</tr>
<?php endif; ?>

</tbody>
</table>
</div>

<h2>メール作成</h2>

<div class="grid">

<label>
<span>件名</span>
<input name="subject"
       required
       value="<?= h(
           'アンケートご協力のお願い'
       ) ?>">
</label>

<label>
<span>本文</span>
<textarea name="body"
          required>いつもお世話になっております。

以下のアンケートへのご協力をお願いいたします。

{{name}} 様

回答URLはメール送信時に自動的に付加されます。</textarea>
</label>

</div>

<div class="button-row"
     style="margin-top:18px">

<button class="btn btn-primary"
        data-confirm="">
送信する
</button>

</div>

</form>

</div>
</div>

<div class="card">
<div class="card-body">

<h2>送信履歴</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>宛先</th>
<th>件名</th>
<th>結果</th>
</tr>
</thead>
<tbody>

<?php foreach (
    array_reverse(
        $data['send_history']
    ) as $history
): ?>

<?php if (
    ($history['survey_id'] ?? '')
    !== ($survey['id'] ?? '')
) {
    continue;
} ?>

<tr>
<td><?= h(
    $history['sentAt'] ?? ''
) ?></td>
<td><?= h(
    $history['email'] ?? ''
) ?></td>
<td><?= h(
    $history['subject'] ?? ''
) ?></td>
<td><?= h(
    $history['status'] ?? ''
) ?></td>
</tr>

<?php endforeach; ?>

</tbody>
</table>
</div>

</div>
</div>

</main>
<?php
render_footer();
}

/* =========================================================
 * メイン
 * ========================================================= */

try {
    start_app();

    $data = load_data();
    $settings = load_settings();

    if (refresh_statuses($data)) {
        save_data($data);
    }

    $postResult =
        handle_post(
            $data,
            $settings
        );

    /*
     * POST処理後は必ず最新データを再読込。
     */
    $data = load_data();
    $settings = load_settings();

    if (refresh_statuses($data)) {
        save_data($data);
    }

    if ($postResult !== null) {
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
        $screen = get_string('screen');

        if ($screen === '') {
            $screen = 'list';
        }

        $id = get_string('id');
    }

    /*
     * -----------------------------------------------------
     * 回答者画面
     * -----------------------------------------------------
     *
     * 管理者画面とは完全に分離する。
     */
    if (in_array(
        $screen,
        [
            'answer',
            'confirm',
            'complete',
        ],
        true
    )) {
        $survey = survey_by_id(
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

        if ($screen === 'answer') {
            render_answer($survey);
            exit;
        }

        if ($screen === 'confirm') {
            render_confirm($survey);
            exit;
        }

        render_complete($survey);
        exit;
    }

    /*
     * -----------------------------------------------------
     * CSV
     * -----------------------------------------------------
     */
    if ($screen === 'csv') {
        $survey = survey_by_id(
            $data['surveys'],
            $id
        );

        if ($survey === null) {
            throw new RuntimeException(
                'アンケートが見つかりません。'
            );
        }

        output_csv(
            $data,
            $survey
        );
    }

    /*
     * -----------------------------------------------------
     * 管理者画面
     * -----------------------------------------------------
     */
    switch ($screen) {
        case 'edit':
            $survey =
                $id !== ''
                    ? survey_by_id(
                        $data['surveys'],
                        $id
                    )
                    : null;

            if ($id !== '' && $survey === null) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                render_list($data);
                break;
            }

            render_edit($survey);
            break;

        case 'preview':
            $survey = survey_by_id(
                $data['surveys'],
                $id
            );

            if ($survey === null) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                render_list($data);
                break;
            }

            render_preview($survey);
            break;

        case 'analytics':
            if ($id === '') {
                render_list($data);
                break;
            }

            $survey = survey_by_id(
                $data['surveys'],
                $id
            );

            if ($survey === null) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                render_list($data);
                break;
            }

            render_analytics(
                $data,
                $survey
            );
            break;

        case 'send':
            if ($id === '') {
                render_list($data);
                break;
            }

            $survey = survey_by_id(
                $data['surveys'],
                $id
            );

            if ($survey === null) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                render_list($data);
                break;
            }

            render_send(
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
            render_list($data);
            break;
    }
} catch (Throwable $e) {
    /*
     * 機密情報を例外メッセージへ混入させない。
     * 外部サービス関数側でも認証情報を出力しない。
     */
    $message = $e->getMessage();

    render_head(
        'エラー'
    );
?>
<main class="container">

<div class="card">
<div class="card-body">

<div class="alert alert-error">
<?= h($message) ?>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url()) ?>">
アンケート一覧へ戻る
</a>

</div>
</div>

</main>
<?php
    render_footer();
}

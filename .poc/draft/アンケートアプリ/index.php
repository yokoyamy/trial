<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし
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
 * 重要:
 * - 外部通信はPHP標準streamのみ。cURL不使用。
 * - kintone認証情報はHTML/JS/URLへ出力しない。
 * - 設定系POSTは不要な303リダイレクトに依存しない。
 * - ボタン操作はJavaScript必須ではない。
 * - JSはUI補助のみ。主要業務処理はPHP POSTで実行。
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_READ_TIMEOUT = 30;
const MAX_TITLE = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION = 1000;
const MAX_OPTION = 500;

/* =========================================================
 * 初期化
 * ========================================================= */

function start_app(): void
{
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('データ保存フォルダを作成できません。');
    }

    if (!is_file(DATA_FILE)) {
        save_json(DATA_FILE, default_data());
    }

    if (!is_file(SETTINGS_FILE)) {
        save_json(SETTINGS_FILE, default_settings());
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        $https = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443
        );

        session_name('survey_app_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => cookie_path(),
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException('セッションを開始できません。');
        }
    }
}

function cookie_path(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = dirname($script);

    if ($dir === '.' || $dir === '/' || $dir === '\\') {
        return '/';
    }

    return rtrim($dir, '/') . '/';
}

/* =========================================================
 * デフォルトデータ
 * ========================================================= */

function default_data(): array
{
    $now = date('Y-m-d H:i:s');

    return [
        'surveys' => [[
            'id' => 'survey-001',
            'title' => '顧客満足度アンケート',
            'description' => 'サービスについてのご意見をお聞かせください。',
            'startAt' => date('Y-m-d\TH:i'),
            'endAt' => date('Y-m-d\TH:i', strtotime('+30 days')),
            'status' => 'published',
            'numbering' => 'global',
            'createdAt' => $now,
            'updatedAt' => $now,
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
                        ['id' => 'option-001', 'label' => '非常に満足', 'nextQuestionId' => ''],
                        ['id' => 'option-002', 'label' => '満足', 'nextQuestionId' => ''],
                        ['id' => 'option-003', 'label' => '普通', 'nextQuestionId' => ''],
                        ['id' => 'option-004', 'label' => '不満', 'nextQuestionId' => ''],
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
 * JSON永続化
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
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException('データの保存に失敗しました。');
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));
    $fp = @fopen($tmp, 'wb');

    if ($fp === false) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    try {
        flock($fp, LOCK_EX);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('保存ファイルを更新できません。');
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

    foreach (['surveys', 'answers', 'customers', 'send_history'] as $key) {
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
    $settings = load_json(SETTINGS_FILE, $default);

    $settings['kintone'] = array_replace_recursive(
        $default['kintone'],
        is_array($settings['kintone'] ?? null) ? $settings['kintone'] : []
    );

    $settings['mail'] = array_replace_recursive(
        $default['mail'],
        is_array($settings['mail'] ?? null) ? $settings['mail'] : []
    );

    return $settings;
}

function save_settings(array $settings): void
{
    save_json(SETTINGS_FILE, $settings);
}

/* =========================================================
 * 入出力
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
    return is_scalar($value) ? trim((string)$value) : '';
}

function get_string(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
}

function post_bool(string $key): bool
{
    return isset($_POST[$key])
        && in_array((string)$_POST[$key], ['1', 'on', 'true'], true);
}

function app_url(array $params = []): string
{
    $base = (string)($_SERVER['SCRIPT_NAME'] ?? 'index.php');

    return $params
        ? $base . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986)
        : $base;
}

function redirect_screen(string $screen, array $params = []): never
{
    $params = array_merge(['screen' => $screen], $params);

    header('Location: ' . app_url($params));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

/* =========================================================
 * 共通データ処理
 * ========================================================= */

function uuid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(6));
}

function survey_by_id(array &$surveys, string $id): ?array
{
    foreach ($surveys as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function survey_index(array &$surveys, string $id): int
{
    foreach ($surveys as $i => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

function auto_update_status(array &$survey): bool
{
    if (
        ($survey['status'] ?? '') === 'published'
        && !empty($survey['endAt'])
        && strtotime((string)$survey['endAt']) !== false
        && strtotime((string)$survey['endAt']) < time()
    ) {
        $survey['status'] = 'ended';
        return true;
    }

    return false;
}

function refresh_statuses(array &$data): bool
{
    $changed = false;

    foreach ($data['surveys'] as &$survey) {
        if (auto_update_status($survey)) {
            $survey['updatedAt'] = date('Y-m-d H:i:s');
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
 * バリデーション
 * ========================================================= */

function validate_survey_input(): array
{
    $title = post_string('title');
    $description = post_string('description');
    $startAt = post_string('startAt');
    $endAt = post_string('endAt');
    $numbering = post_string('numbering');

    $errors = [];

    if ($title === '') {
        $errors[] = 'アンケートタイトルは必須です。';
    } elseif (mb_strlen($title) > MAX_TITLE) {
        $errors[] = 'アンケートタイトルが長すぎます。';
    }

    if (mb_strlen($description) > MAX_DESCRIPTION) {
        $errors[] = 'アンケート説明が長すぎます。';
    }

    if (!in_array($numbering, ['global', 'group'], true)) {
        $errors[] = '質問番号の採番方式が不正です。';
    }

    if ($startAt !== '' && strtotime($startAt) === false) {
        $errors[] = '開始日時が不正です。';
    }

    if ($endAt !== '' && strtotime($endAt) === false) {
        $errors[] = '終了日時が不正です。';
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && strtotime($startAt) !== false
        && strtotime($endAt) !== false
        && strtotime($endAt) < strtotime($startAt)
    ) {
        $errors[] = '終了日時は開始日時以降にしてください。';
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

    if (str_ends_with(strtolower($value), '.cybozu.com')) {
        return substr($value, 0, -strlen('.cybozu.com'));
    }

    return $value;
}

function validate_kintone_config(array $config, bool $requirePassword = false): array
{
    $errors = [];

    $subdomain = normalize_kintone_subdomain(
        (string)($config['subdomain'] ?? '')
    );

    if (
        $subdomain === ''
        || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]*$/', $subdomain)
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
        && !preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )
    ) {
        $errors[] = 'Proxyはhost:port形式で入力してください。';
    }

    return $errors;
}

function kintone_password(array $config): string
{
    return (string)($config['password'] ?? '');
}

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $errors = validate_kintone_config(
        $config,
        trim(kintone_password($config)) !== ''
    );

    if ($errors) {
        throw new RuntimeException(implode("\n", $errors));
    }

    $subdomain = normalize_kintone_subdomain(
        (string)$config['subdomain']
    );

    $appId = (int)$config['app_id'];
    $url = 'https://' . $subdomain . '.cybozu.com' . $path;

    $username = (string)$config['username'];
    $password = kintone_password($config);

    if ($password === '') {
        throw new RuntimeException(
            'kintoneパスワードが設定されていません。'
        );
    }

    /*
     * kintone仕様:
     * ログイン名:パスワードをBase64化して
     * X-Cybozu-Authorizationへ設定する。
     */
    $authorization = base64_encode(
        $username . ':' . $password
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Accept: application/json',
    ];

    $content = null;

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            throw new RuntimeException('kintoneリクエストを生成できません。');
        }

        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($content);
    }

    $verify = !empty($config['verify_ssl']);

    $ssl = [
        'verify_peer' => $verify,
        'verify_peer_name' => $verify,
        'allow_self_signed' => !$verify,
        'SNI_enabled' => true,
        'peer_name' => $subdomain . '.cybozu.com',
    ];

    $contextOptions = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content ?? '',
            'ignore_errors' => true,
            'timeout' => KINTONE_READ_TIMEOUT,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => $ssl,
    ];

    $proxy = trim((string)($config['proxy'] ?? ''));

    if ($proxy !== '') {
        [$proxyHost, $proxyPort] = explode(':', $proxy, 2);

        $contextOptions['http']['proxy'] =
            'tcp://' . $proxyHost . ':' . (int)$proxyPort;
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    $status = 0;

    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) {
            $status = (int)$m[1];
            break;
        }
    }

    $responseBody = $response === false ? '' : $response;
    $json = json_decode($responseBody, true);

    /*
     * HTTPステータスだけで判断しない。
     * kintoneのcode / messageを必ず取得する。
     */
    if ($status < 200 || $status >= 300) {
        $code = is_array($json)
            ? (string)($json['code'] ?? '')
            : '';

        $message = is_array($json)
            ? (string)($json['message'] ?? '')
            : '';

        throw new RuntimeException(
            'kintone APIエラー'
            . ($code !== '' ? ' [' . $code . ']' : '')
            . ($message !== '' ? ' ' . $message : '')
            . ' HTTP ' . $status
        );
    }

    return [
        'status' => $status,
        'body' => is_array($json) ? $json : [],
        'raw' => $responseBody,
        'app_id' => $appId,
    ];
}

function kintone_test(array $config): array
{
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?id=' . rawurlencode((string)$config['app_id'])
    );
}

function kintone_fields(array $config): array
{
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app='
        . rawurlencode((string)$config['app_id'])
    );
}

function kintone_records(
    array $config,
    int $appId
): array {
    $result = kintone_request(
        $config,
        'GET',
        '/k/v1/records.json?app='
        . rawurlencode((string)$appId)
        . '&totalCount=true'
    );

    return $result['body'];
}

function normalize_kintone_fields(array $response): array
{
    $fields = $response['properties'] ?? [];

    if (!is_array($fields)) {
        return [];
    }

    $result = [];

    foreach ($fields as $code => $field) {
        if (!is_array($field)) {
            continue;
        }

        $type = (string)($field['type'] ?? '');

        /*
         * システム項目等も項目一覧として保持する。
         * マッピング可能性は画面側で選択させる。
         */
        $result[] = [
            'code' => (string)$code,
            'label' => (string)($field['label'] ?? $code),
            'type' => $type,
        ];
    }

    usort(
        $result,
        static fn(array $a, array $b): int =>
            strnatcasecmp($a['code'], $b['code'])
    );

    return $result;
}

function record_value(array $record, string $code): string
{
    if (!isset($record[$code]) || !is_array($record[$code])) {
        return '';
    }

    $value = $record[$code]['value'] ?? '';

    if (is_array($value)) {
        /*
         * kintoneの複数値フィールドも文字列化。
         * 住所マッピングでは複数項目を結合する。
         */
        $parts = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                if (isset($item['name'])) {
                    $parts[] = (string)$item['name'];
                } elseif (isset($item['value'])) {
                    $parts[] = (string)$item['value'];
                }
            } else {
                $parts[] = (string)$item;
            }
        }

        return implode(' ', array_filter($parts, static fn($v) => $v !== ''));
    }

    return (string)$value;
}

/* =========================================================
 * SMTP
 * ========================================================= */

function smtp_open(array $config)
{
    $host = trim((string)$config['host']);
    $port = (int)$config['port'];
    $encryption = (string)$config['encryption'];

    if ($host === '') {
        throw new RuntimeException('SMTPサーバを入力してください。');
    }

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('SMTPポートが不正です。');
    }

    $scheme = $encryption === 'ssl'
        ? 'ssl://'
        : '';

    $errno = 0;
    $errstr = '';

    $socket = @fsockopen(
        $scheme . $host,
        $port,
        $errno,
        $errstr,
        10
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPサーバへ接続できません。'
        );
    }

    stream_set_timeout($socket, 30);

    smtp_expect($socket, [220]);

    smtp_command($socket, 'EHLO localhost', [250]);

    if ($encryption === 'tls') {
        smtp_command($socket, 'STARTTLS', [220]);

        $crypto = @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);
            throw new RuntimeException(
                'SMTP TLS接続を確立できません。'
            );
        }

        smtp_command($socket, 'EHLO localhost', [250]);
    }

    if (!empty($config['auth'])) {
        $username = (string)($config['username'] ?? '');
        $password = (string)($config['password'] ?? '');

        if ($username === '' || $password === '') {
            fclose($socket);
            throw new RuntimeException(
                'SMTP認証情報を入力してください。'
            );
        }

        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($username), [334]);
        smtp_command($socket, base64_encode($password), [235]);
    }

    return $socket;
}

function smtp_expect($socket, array $codes): string
{
    $response = '';

    while (($line = fgets($socket)) !== false) {
        $response .= $line;

        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            if ($m[2] === ' ') {
                $code = (int)$m[1];

                if (!in_array($code, $codes, true)) {
                    throw new RuntimeException(
                        'SMTPエラー: ' . $code
                    );
                }

                break;
            }
        }
    }

    if ($response === '') {
        throw new RuntimeException('SMTPから応答がありません。');
    }

    return $response;
}

function smtp_command($socket, string $command, array $codes): string
{
    fwrite($socket, $command . "\r\n");
    return smtp_expect($socket, $codes);
}

function smtp_test(array $config): void
{
    $socket = smtp_open($config);
    smtp_command($socket, 'QUIT', [221]);
    fclose($socket);
}

function smtp_send(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('送信先メールアドレスが不正です。');
    }

    $from = trim((string)$config['from_email']);

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('送信元メールアドレスが不正です。');
    }

    $socket = smtp_open($config);

    smtp_command(
        $socket,
        'MAIL FROM:<' . $from . '>',
        [250]
    );

    smtp_command(
        $socket,
        'RCPT TO:<' . $to . '>',
        [250, 251]
    );

    smtp_command($socket, 'DATA', [354]);

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . mb_encode_mimeheader(
            (string)($config['from_name'] ?: $from)
        ) . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . mb_encode_mimeheader($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    if (!empty($config['reply_to'])) {
        $headers[] = 'Reply-To: ' . $config['reply_to'];
    }

    $message = implode("\r\n", $headers)
        . "\r\n\r\n"
        . str_replace("\n.", "\n..", $body)
        . "\r\n.";

    smtp_command($socket, $message, [250]);
    smtp_command($socket, 'QUIT', [221]);

    fclose($socket);
}

/* =========================================================
 * POST処理
 * ========================================================= */

function handle_post(array &$data, array &$settings): ?string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return null;
    }

    $action = post_string('action');

    try {
        switch ($action) {

            /* -----------------------------------------
             * アンケート保存
             * ----------------------------------------- */

            case 'save_survey':
                $input = validate_survey_input();

                if ($input['errors']) {
                    flash('error', implode("\n", $input['errors']));
                    return null;
                }

                $id = post_string('survey_id');
                $index = survey_index($data['surveys'], $id);

                if ($index < 0) {
                    $survey = [
                        'id' => uuid('survey'),
                        'title' => $input['title'],
                        'description' => $input['description'],
                        'startAt' => $input['startAt'],
                        'endAt' => $input['endAt'],
                        'status' => 'draft',
                        'numbering' => $input['numbering'],
                        'createdAt' => date('Y-m-d H:i:s'),
                        'updatedAt' => date('Y-m-d H:i:s'),
                        'groups' => [],
                    ];
                } else {
                    $survey = $data['surveys'][$index];

                    if (($survey['status'] ?? '') === 'ended') {
                        $input['title'] = $survey['title'];
                    }

                    $survey['title'] = $input['title'];
                    $survey['description'] = $input['description'];
                    $survey['startAt'] = $input['startAt'];
                    $survey['endAt'] = $input['endAt'];
                    $survey['numbering'] = $input['numbering'];
                    $survey['updatedAt'] = date('Y-m-d H:i:s');
                }

                $survey['groups'] = [];

                $groupOrder = $_POST['group_order'] ?? [];
                $groupTitles = $_POST['group_title'] ?? [];
                $questionTexts = $_POST['question_text'] ?? [];
                $questionTypes = $_POST['question_type'] ?? [];
                $questionRequired = $_POST['question_required'] ?? [];
                $questionOptions = $_POST['question_option'] ?? [];
                $branching = $_POST['branching'] ?? [];

                if (!is_array($groupOrder)) {
                    $groupOrder = [];
                }

                if (!$groupOrder) {
                    $groupOrder = ['new-group-' . bin2hex(random_bytes(3))];
                }

                foreach ($groupOrder as $groupId) {
                    $groupId = trim((string)$groupId);

                    if ($groupId === '') {
                        continue;
                    }

                    $group = [
                        'id' => $groupId,
                        'title' => trim(
                            (string)($groupTitles[$groupId] ?? '新しいグループ')
                        ),
                        'questions' => [],
                    ];

                    if ($group['title'] === '') {
                        $group['title'] = '新しいグループ';
                    }

                    $questionIds = $_POST['questions_by_group'][$groupId] ?? [];

                    if (!is_array($questionIds)) {
                        $questionIds = [];
                    }

                    foreach ($questionIds as $questionId) {
                        $questionId = trim((string)$questionId);

                        if ($questionId === '') {
                            continue;
                        }

                        $type = (string)($questionTypes[$questionId] ?? 'single');

                        if (!in_array($type, ['single', 'multiple', 'text'], true)) {
                            $type = 'single';
                        }

                        $options = [];

                        if (
                            $type === 'single'
                            || $type === 'multiple'
                        ) {
                            $rawOptions =
                                $questionOptions[$questionId] ?? [];

                            if (is_array($rawOptions)) {
                                foreach ($rawOptions as $optionIndex => $label) {
                                    $label = trim((string)$label);

                                    if ($label === '') {
                                        continue;
                                    }

                                    $options[] = [
                                        'id' => 'option-' . $questionId . '-' . $optionIndex,
                                        'label' => mb_substr($label, 0, MAX_OPTION),
                                        'nextQuestionId' =>
                                            $type === 'single'
                                            ? (string)(
                                                $_POST['option_next']
                                                    [$questionId]
                                                    [$optionIndex]
                                                    ?? ''
                                            )
                                            : '',
                                    ];
                                }
                            }
                        }

                        $group['questions'][] = [
                            'id' => $questionId,
                            'number' => '',
                            'text' => mb_substr(
                                trim((string)($questionTexts[$questionId] ?? '')),
                                0,
                                MAX_QUESTION
                            ),
                            'type' => $type,
                            'required' =>
                                isset($questionRequired[$questionId]),
                            'options' => $options,
                            'branching' =>
                                (string)($branching[$questionId] ?? ''),
                        ];
                    }

                    $survey['groups'][] = $group;
                }

                if (!$survey['groups']) {
                    $survey['groups'][] = [
                        'id' => uuid('group'),
                        'title' => '基本アンケート',
                        'questions' => [],
                    ];
                }

                recalc_numbers($survey);

                if ($index < 0) {
                    $data['surveys'][] = $survey;
                } else {
                    $data['surveys'][$index] = $survey;
                }

                save_data($data);

                flash('success', 'アンケートを保存しました。');
                return 'list';

            /* -----------------------------------------
             * 状態変更
             * ----------------------------------------- */

            case 'change_status':
                $id = post_string('survey_id');
                $next = post_string('next_status');
                $index = survey_index($data['surveys'], $id);

                if ($index < 0) {
                    flash('error', 'アンケートが見つかりません。');
                    return 'list';
                }

                $current = $data['surveys'][$index]['status'] ?? 'draft';

                $allowed = [
                    'draft' => 'published',
                    'published' => 'stopped',
                    'stopped' => 'published',
                ];

                if (
                    $current === 'ended'
                    || !isset($allowed[$current])
                    || $allowed[$current] !== $next
                ) {
                    flash('error', '状態変更できません。');
                    return null;
                }

                $data['surveys'][$index]['status'] = $next;
                $data['surveys'][$index]['updatedAt'] = date('Y-m-d H:i:s');

                save_data($data);

                flash('success', '状態を変更しました。');
                return null;

            /* -----------------------------------------
             * 複製
             * ----------------------------------------- */

            case 'duplicate_survey':
                $id = post_string('survey_id');
                $survey = survey_by_id($data['surveys'], $id);

                if ($survey === null) {
                    flash('error', 'アンケートが見つかりません。');
                    return null;
                }

                $survey['id'] = uuid('survey');
                $survey['title'] .= '（コピー）';
                $survey['status'] = 'draft';
                $survey['createdAt'] = date('Y-m-d H:i:s');
                $survey['updatedAt'] = date('Y-m-d H:i:s');

                $data['surveys'][] = $survey;
                save_data($data);

                flash('success', 'アンケートを複製しました。');
                return 'list';

            /* -----------------------------------------
             * 削除
             * ----------------------------------------- */

            case 'delete_survey':
                $id = post_string('survey_id');
                $index = survey_index($data['surveys'], $id);

                if ($index < 0) {
                    flash('error', 'アンケートが見つかりません。');
                    return null;
                }

                array_splice($data['surveys'], $index, 1);
                save_data($data);

                flash('success', 'アンケートを削除しました。');
                return 'list';

            /* -----------------------------------------
             * 回答
             * ----------------------------------------- */

            case 'answer_next':
                $surveyId = post_string('survey_id');
                $survey = survey_by_id($data['surveys'], $surveyId);

                if ($survey === null) {
                    flash('error', 'アンケートが見つかりません。');
                    return 'list';
                }

                $answers = $_POST['answer'] ?? [];
                if (!is_array($answers)) {
                    $answers = [];
                }

                $validated = [];

                foreach ($survey['groups'] as $group) {
                    foreach ($group['questions'] as $question) {
                        $qid = $question['id'];
                        $value = $answers[$qid] ?? '';

                        if ($question['required']) {
                            $empty = false;

                            if (is_array($value)) {
                                $empty = count(array_filter(
                                    $value,
                                    static fn($v) => trim((string)$v) !== ''
                                )) === 0;
                            } else {
                                $empty = trim((string)$value) === '';
                            }

                            if ($empty) {
                                flash(
                                    'error',
                                    $question['number']
                                    . ' は必須項目です。'
                                );

                                $_SESSION['answer_draft'] = $answers;

                                return null;
                            }
                        }

                        $validated[$qid] = $value;
                    }
                }

                $_SESSION['answer_draft'] = $validated;

                return 'confirm';

            /* -----------------------------------------
             * 回答確定
             * ----------------------------------------- */

            case 'submit_answer':
                $surveyId = post_string('survey_id');
                $survey = survey_by_id($data['surveys'], $surveyId);

                if ($survey === null) {
                    flash('error', 'アンケートが見つかりません。');
                    return 'list';
                }

                $draft = $_SESSION['answer_draft'] ?? [];

                $data['answers'][] = [
                    'id' => uuid('answer'),
                    'survey_id' => $surveyId,
                    'answers' => is_array($draft) ? $draft : [],
                    'createdAt' => date('Y-m-d H:i:s'),
                ];

                unset($_SESSION['answer_draft']);

                save_data($data);

                return 'complete';

            /* -----------------------------------------
             * kintone設定保存
             * ----------------------------------------- */

            case 'save_kintone':
                $current = $settings['kintone'];

                $newPassword = post_string('password');

                $config = [
                    'subdomain' => normalize_kintone_subdomain(
                        post_string('subdomain')
                    ),
                    'app_id' => post_string('app_id'),
                    'username' => post_string('username'),
                    'password' =>
                        $newPassword !== ''
                        ? $newPassword
                        : (string)($current['password'] ?? ''),
                    'proxy' => post_string('proxy'),
                    'verify_ssl' => post_bool('verify_ssl'),
                    'mapping' => $current['mapping'] ?? [],
                    'fields' => $current['fields'] ?? [],
                    'last_test' => $current['last_test'] ?? null,
                    'last_sync' => $current['last_sync'] ?? null,
                ];

                $errors = validate_kintone_config($config, true);

                if ($errors) {
                    flash('error', implode("\n", $errors));
                    return null;
                }

                /*
                 * パスワードはサーバー側settings.jsonのみ。
                 * HTMLへ再出力しない。
                 */
                $settings['kintone'] = $config;
                save_settings($settings);

                flash('success', 'kintone設定を保存しました。');
                return null;

            /* -----------------------------------------
             * kintone接続テスト
             * ----------------------------------------- */

            case 'test_kintone':
                $config = $settings['kintone'];

                $password = post_string('password');

                if ($password !== '') {
                    $config['password'] = $password;
                }

                $errors = validate_kintone_config($config, true);

                if ($errors) {
                    flash('error', implode("\n", $errors));
                    return null;
                }

                try {
                    $result = kintone_test($config);

                    $settings['kintone']['last_test'] =
                        date('Y-m-d H:i:s');

                    /*
                     * 入力された接続テスト用パスワードを
                     * 必要に応じて設定へ保存。
                     */
                    if ($password !== '') {
                        $settings['kintone']['password'] = $password;
                    }

                    save_settings($settings);

                    flash(
                        'success',
                        'kintone接続成功。HTTP '
                        . $result['status']
                    );
                } catch (Throwable $e) {
                    flash(
                        'error',
                        'kintone接続失敗：'
                        . $e->getMessage()
                    );
                }

                return null;

            /* -----------------------------------------
             * kintone項目取得
             * ----------------------------------------- */

            case 'fetch_kintone_fields':
                $config = $settings['kintone'];

                try {
                    $result = kintone_fields($config);

                    $fields = normalize_kintone_fields(
                        $result['body']
                    );

                    if (!$fields) {
                        throw new RuntimeException(
                            'kintoneから項目を取得できませんでした。'
                        );
                    }

                    $settings['kintone']['fields'] = $fields;

                    save_settings($settings);

                    flash(
                        'success',
                        count($fields)
                        . '件の項目を取得しました。'
                    );
                } catch (Throwable $e) {
                    flash(
                        'error',
                        'kintone項目取得失敗：'
                        . $e->getMessage()
                    );
                }

                return null;

            /* -----------------------------------------
             * kintoneマッピング保存
             * ----------------------------------------- */

            case 'save_kintone_mapping':
                $fields = $settings['kintone']['fields'] ?? [];

                $validCodes = [];

                foreach ($fields as $field) {
                    if (isset($field['code'])) {
                        $validCodes[] = (string)$field['code'];
                    }
                }

                $mapping = [
                    'organization' => post_string('mapping_organization'),
                    'name' => post_string('mapping_name'),
                    'email' => post_string('mapping_email'),
                    'department' => post_string('mapping_department'),
                    'phone' => post_string('mapping_phone'),
                    'address' => [],
                ];

                $address = $_POST['mapping_address'] ?? [];

                if (is_array($address)) {
                    foreach ($address as $code) {
                        $code = trim((string)$code);

                        if (
                            $code !== ''
                            && in_array($code, $validCodes, true)
                        ) {
                            $mapping['address'][] = $code;
                        }
                    }
                }

                foreach (
                    ['organization', 'name', 'email', 'department', 'phone']
                    as $key
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

                $settings['kintone']['mapping'] = $mapping;
                save_settings($settings);

                flash('success', 'kintone項目マッピングを保存しました。');
                return null;

            /* -----------------------------------------
             * kintone同期
             * ----------------------------------------- */

            case 'sync_kintone':
                $config = $settings['kintone'];

                try {
                    $records = kintone_records(
                        $config,
                        (int)$config['app_id']
                    );

                    $mapping = $config['mapping'] ?? [];

                    $customers = [];

                    foreach (($records['records'] ?? []) as $record) {
                        $addressParts = [];

                        foreach (($mapping['address'] ?? []) as $code) {
                            $value = record_value(
                                $record,
                                (string)$code
                            );

                            if ($value !== '') {
                                $addressParts[] = $value;
                            }
                        }

                        $customers[] = [
                            'id' => uuid('customer'),
                            'organization' => record_value(
                                $record,
                                (string)($mapping['organization'] ?? '')
                            ),
                            'name' => record_value(
                                $record,
                                (string)($mapping['name'] ?? '')
                            ),
                            'email' => record_value(
                                $record,
                                (string)($mapping['email'] ?? '')
                            ),
                            'department' => record_value(
                                $record,
                                (string)($mapping['department'] ?? '')
                            ),
                            'phone' => record_value(
                                $record,
                                (string)($mapping['phone'] ?? '')
                            ),
                            'address' => implode(
                                ' ',
                                $addressParts
                            ),
                        ];
                    }

                    $data['customers'] = $customers;

                    $settings['kintone']['last_sync'] =
                        date('Y-m-d H:i:s');

                    save_data($data);
                    save_settings($settings);

                    flash(
                        'success',
                        count($customers)
                        . '件の顧客情報を同期しました。'
                    );
                } catch (Throwable $e) {
                    flash(
                        'error',
                        'kintone同期失敗：'
                        . $e->getMessage()
                    );
                }

                return null;

            /* -----------------------------------------
             * SMTP設定保存
             * ----------------------------------------- */

            case 'save_mail':
                $current = $settings['mail'];

                $host = post_string('server');
                $port = (int)post_string('port');
                $encryption = post_string('encryption');
                $username = post_string('username');
                $newPassword = post_string('password');
                $fromEmail = post_string('from_email');
                $fromName = post_string('from_name');
                $replyTo = post_string('reply_to');

                $errors = [];

                if ($host === '') {
                    $errors[] = 'SMTPサーバを入力してください。';
                }

                if ($port < 1 || $port > 65535) {
                    $errors[] = 'SMTPポートが不正です。';
                }

                if (
                    !in_array(
                        $encryption,
                        ['ssl', 'tls', 'none'],
                        true
                    )
                ) {
                    $errors[] = '暗号化方式が不正です。';
                }

                if (
                    !filter_var(
                        $fromEmail,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    $errors[] =
                        '送信元メールアドレスが不正です。';
                }

                if (
                    $replyTo !== ''
                    && !filter_var(
                        $replyTo,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    $errors[] =
                        '返信先メールアドレスが不正です。';
                }

                if ($errors) {
                    flash('error', implode("\n", $errors));
                    return null;
                }

                $settings['mail'] = [
                    'host' => $host,
                    'port' => $port,
                    'encryption' => $encryption,
                    'auth' => post_bool('auth'),
                    'username' => $username,
                    'password' =>
                        $newPassword !== ''
                        ? $newPassword
                        : (string)($current['password'] ?? ''),
                    'from_email' => $fromEmail,
                    'from_name' => $fromName,
                    'reply_to' => $replyTo,
                    'last_test' => $current['last_test'] ?? null,
                ];

                save_settings($settings);

                flash('success', 'SMTP設定を保存しました。');
                return null;

            /* -----------------------------------------
             * SMTP接続テスト
             * ----------------------------------------- */

            case 'test_mail':
                $config = $settings['mail'];

                $password = post_string('password');

                if ($password !== '') {
                    $config['password'] = $password;
                }

                try {
                    smtp_test($config);

                    $settings['mail']['last_test'] =
                        date('Y-m-d H:i:s');

                    if ($password !== '') {
                        $settings['mail']['password'] = $password;
                    }

                    save_settings($settings);

                    flash(
                        'success',
                        'SMTP接続・認証に成功しました。'
                    );
                } catch (Throwable $e) {
                    flash(
                        'error',
                        'SMTP接続失敗：'
                        . $e->getMessage()
                    );
                }

                return null;

            /* -----------------------------------------
             * テストメール
             * ----------------------------------------- */

            case 'send_test_mail':
                $to = post_string('test_email');

                try {
                    smtp_send(
                        $settings['mail'],
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
                        'テストメール送信失敗：'
                        . $e->getMessage()
                    );
                }

                return null;

            /* -----------------------------------------
             * アンケートメール送信
             * ----------------------------------------- */

            case 'send_mail':
                $surveyId = post_string('survey_id');
                $survey = survey_by_id(
                    $data['surveys'],
                    $surveyId
                );

                if ($survey === null) {
                    flash('error', '対象アンケートが見つかりません。');
                    return 'list';
                }

                $selected = $_POST['customer_ids'] ?? [];

                if (!is_array($selected) || !$selected) {
                    flash('error', '顧客を選択してください。');
                    return null;
                }

                $subject = post_string('subject');
                $body = (string)($_POST['body'] ?? '');

                if ($subject === '' || trim($body) === '') {
                    flash(
                        'error',
                        'メール件名と本文を入力してください。'
                    );
                    return null;
                }

                $customerMap = [];

                foreach ($data['customers'] as $customer) {
                    $customerMap[(string)$customer['id']] = $customer;
                }

                $sent = 0;
                $failed = 0;

                foreach ($selected as $customerId) {
                    $customer = $customerMap[(string)$customerId] ?? null;

                    if ($customer === null) {
                        $failed++;
                        continue;
                    }

                    $url = app_url([
                        'screen' => 'answer',
                        'id' => $surveyId,
                    ]);

                    $mailBody = str_replace(
                        ['{顧客名}', '{アンケートURL}'],
                        [
                            (string)($customer['name'] ?? ''),
                            $url,
                        ],
                        $body
                    );

                    try {
                        smtp_send(
                            $settings['mail'],
                            (string)$customer['email'],
                            $subject,
                            $mailBody
                        );

                        $result = '送信成功';
                        $sent++;
                    } catch (Throwable $e) {
                        $result = '送信失敗';
                        $failed++;
                    }

                    $data['send_history'][] = [
                        'id' => uuid('send'),
                        'survey_id' => $surveyId,
                        'customer_id' => $customerId,
                        'customer_name' =>
                            (string)($customer['name'] ?? ''),
                        'type' => '一括送信',
                        'result' => $result,
                        'createdAt' => date('Y-m-d H:i:s'),
                    ];
                }

                save_data($data);

                flash(
                    $failed === 0 ? 'success' : 'warning',
                    '送信結果：成功 '
                    . $sent
                    . '件 / 失敗 '
                    . $failed
                    . '件'
                );

                return null;

            default:
                return null;
        }
    } catch (Throwable $e) {
        flash(
            'error',
            '処理に失敗しました：'
            . $e->getMessage()
        );

        return null;
    }
}

/* =========================================================
 * HTMLヘッダー
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
<meta name="viewport" content="width=device-width,initial-scale=1">
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

*{box-sizing:border-box}

html,body{
    margin:0;
    padding:0;
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

body{
    min-height:100vh;
}

a{
    color:inherit;
}

.container{
    width:min(1400px,calc(100% - 32px));
    margin:0 auto;
}

.page{
    padding:28px 0 60px;
}

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
    background:#fff;
}

.card-header h2{
    margin:0;
    font-size:17px;
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

.grid-3{
    grid-template-columns:repeat(3,minmax(0,1fr));
}

.form-group{
    margin-bottom:16px;
}

.form-group:last-child{
    margin-bottom:0;
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

input[type=checkbox],
input[type=radio]{
    width:auto;
}

.check{
    display:flex;
    align-items:center;
    gap:8px;
    font-weight:500;
    cursor:pointer;
}

.button-row{
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:8px;
}

.btn{
    appearance:none;
    border:1px solid transparent;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:8px 14px;
    border-radius:8px;
    font:inherit;
    font-weight:600;
    text-decoration:none;
    cursor:pointer;
    transition:.15s;
}

.btn:hover{
    transform:translateY(-1px);
}

.btn:disabled{
    opacity:.55;
    cursor:not-allowed;
    transform:none;
}

.btn-primary{
    background:var(--primary);
    color:#fff;
}

.btn-primary:hover{
    background:var(--primary-dark);
}

.btn-secondary,
.btn-light{
    background:#fff;
    border-color:var(--border);
    color:var(--text);
}

.btn-secondary:hover,
.btn-light:hover{
    background:var(--gray-light);
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

.badge{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:5px 10px;
    font-size:13px;
    font-weight:700;
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

.alert{
    white-space:pre-line;
    border-radius:10px;
    padding:13px 15px;
    margin-bottom:18px;
    border:1px solid;
}

.alert-success{
    background:#f0fdf4;
    color:#166534;
    border-color:#bbf7d0;
}

.alert-error{
    background:#fef2f2;
    color:#991b1b;
    border-color:#fecaca;
}

.alert-warning{
    background:#fffbeb;
    color:#92400e;
    border-color:#fde68a;
}

.notice{
    background:#eff6ff;
    border:1px solid #bfdbfe;
    color:#1e40af;
    padding:12px 14px;
    border-radius:8px;
    margin-bottom:18px;
}

.help{
    color:var(--gray);
    font-size:13px;
}

.table-wrap{
    width:100%;
    overflow-x:auto;
}

table{
    width:100%;
    min-width:950px;
    border-collapse:collapse;
}

th,
td{
    padding:12px 13px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th{
    background:#f8fafc;
    white-space:nowrap;
    font-size:13px;
}

.actions{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
}

.group-card{
    border:1px solid var(--border);
    border-radius:10px;
    background:#fff;
    margin-bottom:18px;
}

.group-head{
    padding:13px 16px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
}

.group-title-line{
    display:flex;
    align-items:center;
    gap:8px;
}

.drag-handle{
    color:var(--gray);
    cursor:grab;
}

.question-card{
    border:1px solid var(--border);
    border-radius:9px;
    margin:14px 0;
    background:#fff;
}

.question-head{
    padding:10px 13px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    gap:10px;
}

.question-number{
    color:var(--primary);
    font-weight:800;
}

.option-row{
    display:flex;
    gap:7px;
    margin-bottom:8px;
}

.mapping-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:16px;
}

.mapping-address{
    border:1px solid var(--border);
    border-radius:8px;
    padding:13px;
    max-height:260px;
    overflow:auto;
}

.mapping-address label{
    display:flex;
    align-items:center;
    gap:8px;
    padding:6px 0;
}

.sticky-actions{
    position:sticky;
    bottom:0;
    z-index:10;
    background:rgba(255,255,255,.96);
    border-top:1px solid var(--border);
    padding:13px 0;
    backdrop-filter:blur(8px);
}

.answer-shell{
    width:min(760px,calc(100% - 28px));
    margin:0 auto;
    padding:30px 0 50px;
}

.answer-shell .card{
    box-shadow:0 3px 14px rgba(15,23,42,.07);
}

.answer-option{
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding:13px;
    border:1px solid var(--border);
    border-radius:8px;
    margin-bottom:8px;
    cursor:pointer;
}

.answer-option:hover{
    background:#f8fafc;
}

.empty{
    text-align:center;
    padding:45px 20px;
    color:var(--gray);
}

.preview-question{
    padding:18px 0;
    border-bottom:1px solid var(--border);
}

.preview-question:last-child{
    border-bottom:0;
}

@media(max-width:800px){
    .container{
        width:min(100% - 20px,1400px);
    }

    .admin-header-inner{
        width:calc(100% - 20px);
        padding:10px 0;
        align-items:flex-start;
        flex-direction:column;
    }

    .grid-2,
    .grid-3,
    .mapping-grid{
        grid-template-columns:1fr;
    }

    .page-title{
        flex-direction:column;
    }

    .card-body{
        padding:15px;
    }

    .btn{
        min-height:44px;
    }

    .answer-shell{
        width:calc(100% - 18px);
        padding-top:18px;
    }
}
</style>
</head>
<body>

<?php if ($admin): ?>
<header class="admin-header">
<div class="admin-header-inner">
<div class="brand"><?= h(APP_TITLE) ?></div>

<nav class="nav">
<a href="<?= h(app_url(['screen'=>'list'])) ?>">アンケート一覧</a>
<a href="<?= h(app_url(['screen'=>'kintone'])) ?>">kintone設定</a>
<a href="<?= h(app_url(['screen'=>'mail'])) ?>">メール設定</a>
</nav>
</div>
</header>
<?php endif; ?>
<?php
}

function render_flash(): void
{
    $flash = consume_flash();

    if (!$flash) {
        return;
    }

    $class = match ($flash['type'] ?? 'info') {
        'success' => 'alert-success',
        'error' => 'alert-error',
        'warning' => 'alert-warning',
        default => 'alert-info',
    };
?>
<div class="alert <?= h($class) ?>"><?= h($flash['message'] ?? '') ?></div>
<?php
}

function render_footer(): void
{
?>
<script>
(function(){
    /*
     * JavaScriptはUI補助のみ。
     * POST処理そのものには依存しない。
     *
     * 旧版のように全フォームを一律でdisabled化して
     * 操作不能にする処理は行わない。
     */

    document.addEventListener('click', function(event){
        const confirmTarget =
            event.target.closest('[data-confirm]');

        if(confirmTarget){
            const message =
                confirmTarget.getAttribute('data-confirm');

            if(message && !window.confirm(message)){
                event.preventDefault();
                event.stopPropagation();
                return;
            }
        }

        const addGroup =
            event.target.closest('[data-add-group]');

        if(addGroup){
            event.preventDefault();
            addGroupFromTemplate();
            return;
        }

        const addQuestion =
            event.target.closest('[data-add-question]');

        if(addQuestion){
            event.preventDefault();
            addQuestionToGroup(
                addQuestion.closest('.group-card')
            );
            return;
        }

        const removeGroup =
            event.target.closest('[data-remove-group]');

        if(removeGroup){
            event.preventDefault();

            if(!window.confirm('このグループを削除しますか？')){
                return;
            }

            const group =
                removeGroup.closest('.group-card');

            if(group){
                const groups =
                    document.querySelectorAll('.group-card');

                if(groups.length <= 1){
                    window.alert('グループは1つ以上必要です。');
                    return;
                }

                group.remove();
                renumberQuestions();
            }

            return;
        }

        const removeQuestion =
            event.target.closest('[data-remove-question]');

        if(removeQuestion){
            event.preventDefault();

            if(!window.confirm('この質問を削除しますか？')){
                return;
            }

            const question =
                removeQuestion.closest('.question-card');

            if(question){
                question.remove();
                renumberQuestions();
            }

            return;
        }

        const addOption =
            event.target.closest('[data-add-option]');

        if(addOption){
            event.preventDefault();

            const question =
                addOption.closest('.question-card');

            if(question){
                addOptionToQuestion(question);
            }

            return;
        }

        const removeOption =
            event.target.closest('[data-remove-option]');

        if(removeOption){
            event.preventDefault();

            const row =
                removeOption.closest('.option-row');

            if(row){
                row.remove();
            }

            return;
        }
    });

    document.addEventListener('change', function(event){
        if(event.target.matches('.js-question-type')){
            const question =
                event.target.closest('.question-card');

            if(!question){
                return;
            }

            const options =
                question.querySelector('.question-options');

            if(options){
                options.style.display =
                    event.target.value === 'text'
                        ? 'none'
                        : '';
            }
        }

        if(event.target.matches('#numbering')){
            renumberQuestions();
        }
    });

    function uniqueId(prefix){
        return prefix + '-' +
            Date.now() + '-' +
            Math.random().toString(16).slice(2);
    }

    function esc(value){
        return String(value)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;')
            .replace(/'/g,'&#039;');
    }

    function questionHtml(groupId){
        const qid = uniqueId('question');

        return `
<div class="question-card" draggable="true"
     data-question-id="${esc(qid)}">

<input type="hidden"
       name="questions_by_group[${esc(groupId)}][]"
       value="${esc(qid)}">

<div class="question-head">
<div>
<span class="drag-handle">☷</span>
<span class="question-number"
      data-question-number>Q</span>
</div>

<button type="button"
        class="btn btn-danger"
        data-remove-question>
削除
</button>
</div>

<div class="card-body">

<div class="form-group">
<label>
<span>質問文</span>
<input type="text"
       name="question_text[${esc(qid)}]"
       maxlength="1000">
</label>
</div>

<div class="grid grid-2">

<div class="form-group">
<label>
<span>回答形式</span>
<select name="question_type[${esc(qid)}]"
        class="js-question-type">
<option value="single">単一選択</option>
<option value="multiple">複数選択</option>
<option value="text">自由記述</option>
</select>
</label>
</div>

<div class="form-group">
<label class="check" style="margin-top:30px">
<input type="checkbox"
       name="question_required[${esc(qid)}]"
       value="1">
必須
</label>
</div>

</div>

<div class="question-options">

<div class="form-group">
<label>
<span>選択肢</span>
</label>

<div class="options">
<div class="option-row">
<input type="text"
       name="question_option[${esc(qid)}][]"
       value="選択肢1">
<button type="button"
        class="btn btn-light"
        data-remove-option>
削除
</button>
</div>

<div class="option-row">
<input type="text"
       name="question_option[${esc(qid)}][]"
       value="選択肢2">
<button type="button"
        class="btn btn-light"
        data-remove-option>
削除
</button>
</div>
</div>

<button type="button"
        class="btn btn-secondary"
        data-add-option>
＋ 選択肢追加
</button>
</div>

</div>

</div>
</div>`;
    }

    function groupHtml(){
        const gid = uniqueId('group');

        return `
<div class="group-card"
     draggable="true"
     data-group-id="${esc(gid)}">

<input type="hidden"
       name="group_order[]"
       value="${esc(gid)}">

<div class="group-head">
<div class="group-title-line">
<span class="drag-handle">☷</span>
<strong>新しいグループ</strong>
</div>

<button type="button"
        class="btn btn-danger"
        data-remove-group>
グループ削除
</button>
</div>

<div class="card-body">

<div class="form-group">
<label>
<span>グループタイトル</span>
<input type="text"
       name="group_title[${esc(gid)}]"
       value="新しいグループ"
       maxlength="200">
</label>
</div>

<div class="questions"></div>

<button type="button"
        class="btn btn-secondary"
        data-add-question>
＋ 質問を追加
</button>

</div>
</div>`;
    }

    function addGroupFromTemplate(){
        const container =
            document.getElementById('groups');

        if(!container){
            return;
        }

        container.insertAdjacentHTML(
            'beforeend',
            groupHtml()
        );

        const group =
            container.lastElementChild;

        if(group){
            addQuestionToGroup(group);
        }

        renumberQuestions();
    }

    function addQuestionToGroup(group){
        if(!group){
            return;
        }

        const groupId =
            group.getAttribute('data-group-id');

        const questions =
            group.querySelector('.questions');

        if(!questions || !groupId){
            return;
        }

        questions.insertAdjacentHTML(
            'beforeend',
            questionHtml(groupId)
        );

        renumberQuestions();
    }

    function addOptionToQuestion(question){
        const options =
            question.querySelector('.options');

        const qid =
            question.getAttribute('data-question-id');

        if(!options || !qid){
            return;
        }

        const row =
            document.createElement('div');

        row.className = 'option-row';

        row.innerHTML =
            '<input type="text"'
            + ' name="question_option['
            + esc(qid)
            + '][]" value="">'
            + '<button type="button"'
            + ' class="btn btn-light"'
            + ' data-remove-option>'
            + '削除'
            + '</button>';

        options.appendChild(row);
    }

    function renumberQuestions(){
        const numbering =
            document.getElementById('numbering');

        const mode =
            numbering
                ? numbering.value
                : 'global';

        let globalNo = 1;
        let groupNo = 1;

        document
            .querySelectorAll(
                '#groups > .group-card'
            )
            .forEach(function(group){

                let questionNo = 1;

                group
                    .querySelectorAll(
                        ':scope .questions > .question-card'
                    )
                    .forEach(function(question){

                        const target =
                            question.querySelector(
                                '[data-question-number]'
                            );

                        if(target){
                            target.textContent =
                                mode === 'group'
                                    ? 'Q' +
                                      groupNo +
                                      '-' +
                                      questionNo
                                    : 'Q' +
                                      globalNo;
                        }

                        globalNo++;
                        questionNo++;
                    });

                groupNo++;
            });
    }

    const search =
        document.getElementById('customerSearch');

    if(search){
        search.addEventListener('input', function(){
            const q =
                search.value.trim().toLowerCase();

            document
                .querySelectorAll('[data-customer-row]')
                .forEach(function(row){
                    row.style.display =
                        row.textContent
                            .toLowerCase()
                            .includes(q)
                            ? ''
                            : 'none';
                });
        });
    }

    const selectAll =
        document.getElementById('selectAllCustomers');

    if(selectAll){
        selectAll.addEventListener('change', function(){
            document
                .querySelectorAll('.customer-check')
                .forEach(function(input){
                    input.checked = selectAll.checked;
                });
        });
    }

    renumberQuestions();
})();
</script>
</body>
</html>
<?php
}

/* =========================================================
 * List
 * ========================================================= */

function render_list(array $surveys): void
{
    $q = get_string('q');
    $status = get_string('status');
    $sort = get_string('sort');

    $filtered = array_values(array_filter(
        $surveys,
        static function(array $survey) use ($q, $status): bool {
            if (
                $q !== ''
                && mb_stripos(
                    (string)($survey['title'] ?? ''),
                    $q
                ) === false
            ) {
                return false;
            }

            if (
                $status !== ''
                && $status !== 'all'
                && ($survey['status'] ?? 'draft') !== $status
            ) {
                return false;
            }

            return true;
        }
    ));

    $answers = load_data()['answers'];

    usort(
        $filtered,
        static function(array $a, array $b) use ($sort, $answers): int {
            $countA = count(array_filter(
                $answers,
                static fn(array $answer): bool =>
                    ($answer['survey_id'] ?? '') === ($a['id'] ?? '')
            ));

            $countB = count(array_filter(
                $answers,
                static fn(array $answer): bool =>
                    ($answer['survey_id'] ?? '') === ($b['id'] ?? '')
            ));

            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)$a['updatedAt'],
                        (string)$b['updatedAt']
                    ),
                'answers_desc' => $countB <=> $countA,
                'answers_asc' => $countA <=> $countB,
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

    render_head('アンケート一覧');
    render_flash();
?>
<div class="page-title">
<div>
<h1>アンケート一覧</h1>
<p>アンケートの作成・公開・集計・送信を管理します。</p>
</div>

<a class="btn btn-primary"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>'new'
   ])) ?>">
＋ 新規作成
</a>
</div>

<div class="card">
<div class="card-body">

<form method="get" class="grid grid-3">
<input type="hidden" name="screen" value="list">

<div class="form-group">
<label>
<span>タイトル検索</span>
<input type="search"
       name="q"
       value="<?= h($q) ?>"
       placeholder="タイトルを検索">
</label>
</div>

<div class="form-group">
<label>
<span>ステータス</span>
<select name="status">
<option value="all">すべて</option>
<option value="published" <?= $status==='published'?'selected':'' ?>>公開中</option>
<option value="draft" <?= $status==='draft'?'selected':'' ?>>下書き</option>
<option value="stopped" <?= $status==='stopped'?'selected':'' ?>>停止</option>
<option value="ended" <?= $status==='ended'?'selected':'' ?>>終了</option>
</select>
</label>
</div>

<div class="form-group">
<label>
<span>ソート</span>
<select name="sort">
<option value="updated_desc" <?= $sort===''||$sort==='updated_desc'?'selected':'' ?>>更新日：新しい順</option>
<option value="updated_asc" <?= $sort==='updated_asc'?'selected':'' ?>>更新日：古い順</option>
<option value="answers_desc" <?= $sort==='answers_desc'?'selected':'' ?>>回答数：多い順</option>
<option value="answers_asc" <?= $sort==='answers_asc'?'selected':'' ?>>回答数：少ない順</option>
<option value="start_desc" <?= $sort==='start_desc'?'selected':'' ?>>開始日：新しい順</option>
<option value="start_asc" <?= $sort==='start_asc'?'selected':'' ?>>開始日：古い順</option>
</select>
</label>
</div>

<div class="button-row">
<button class="btn btn-primary" type="submit">検索・絞り込み</button>
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
<th>作成日</th>
<th>更新日</th>
<th>期間</th>
<th>状態</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>

<tbody>

<?php if(!$filtered): ?>
<tr>
<td colspan="7">
<div class="empty">
該当するアンケートはありません。
</div>
</td>
</tr>
<?php endif; ?>

<?php foreach($filtered as $survey): ?>
<?php
$count = count(array_filter(
    $answers,
    static fn(array $answer): bool =>
        ($answer['survey_id'] ?? '') === ($survey['id'] ?? '')
));
$statusValue = (string)($survey['status'] ?? 'draft');
?>
<tr>

<td>
<strong><?= h($survey['title']) ?></strong>
</td>

<td><?= h($survey['createdAt']) ?></td>
<td><?= h($survey['updatedAt']) ?></td>

<td>
<?= h($survey['startAt']) ?><br>
〜 <?= h($survey['endAt']) ?>
</td>

<td>
<span class="badge badge-<?= h(status_class($statusValue)) ?>">
<?= h(status_label($statusValue)) ?>
</span>
</td>

<td><?= h($count) ?></td>

<td>
<div class="actions">

<a class="btn btn-light"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>$survey['id']
   ])) ?>">
確認・編集
</a>

<a class="btn btn-light"
   href="<?= h(app_url([
       'screen'=>'analytics',
       'id'=>$survey['id']
   ])) ?>">
集計
</a>

<a class="btn btn-light"
   href="<?= h(app_url([
       'screen'=>'send',
       'id'=>$survey['id']
   ])) ?>">
送信
</a>

<form method="post">
<input type="hidden" name="action" value="duplicate_survey">
<input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">
<button class="btn btn-secondary"
        type="submit"
        data-confirm="このアンケートを複製しますか？">
複製
</button>
</form>

<form method="post">
<input type="hidden" name="action" value="delete_survey">
<input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">
<button class="btn btn-danger"
        type="submit"
        data-confirm="このアンケートを削除しますか？">
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
<?php
    render_footer();
}

/* =========================================================
 * Edit
 * ========================================================= */

function render_edit(array $survey): void
{
    recalc_numbers($survey);

    $status = (string)($survey['status'] ?? 'draft');

    $nextStatus = match($status){
        'draft' => 'published',
        'published' => 'stopped',
        'stopped' => 'published',
        default => '',
    };

    $nextLabel = match($status){
        'draft' => '公開',
        'published' => '停止',
        'stopped' => '再開',
        default => '',
    };

    $confirm = match($status){
        'draft' => '公開しますか？',
        'published' => '停止しますか？',
        'stopped' => '再開しますか？',
        default => '',
    };

    render_head('アンケート作成・編集');
    render_flash();
?>
<div class="page-title">
<div>
<h1>アンケート作成・編集</h1>
<p>質問、グループ、公開期間を設定します。</p>
</div>
</div>

<form method="post">

<input type="hidden" name="action" value="save_survey">
<input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">

<div class="card">
<div class="card-body">

<div class="button-row" style="justify-content:space-between">

<a class="btn btn-secondary"
   href="<?= h(app_url(['screen'=>'list'])) ?>"
   data-confirm="編集内容を破棄して一覧へ戻りますか？">
キャンセル
</a>

<div class="button-row">

<?php if($status !== 'ended'): ?>

<span class="badge badge-<?= h(status_class($status)) ?>">
状態：<?= h(status_label($status)) ?>
</span>

<form method="post">
<input type="hidden" name="action" value="change_status">
<input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">
<input type="hidden" name="next_status" value="<?= h($nextStatus) ?>">

<button class="btn <?= $status==='published'?'btn-warning':'btn-success' ?>"
        type="submit"
        data-confirm="<?= h($confirm) ?>">
<?= h($nextLabel) ?>
</button>
</form>

<?php else: ?>

<span class="badge badge-gray">状態：終了</span>

<?php endif; ?>

<button class="btn btn-primary" type="submit">
保存して一覧へ
</button>

</div>
</div>

<hr style="border:0;border-top:1px solid var(--border);margin:20px 0">

<div class="grid grid-2">

<div class="form-group">
<label>
<span>アンケートタイトル</span>
<input type="text"
       name="title"
       value="<?= h($survey['title']) ?>"
       maxlength="200"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>質問番号の採番方式</span>
<select name="numbering" id="numbering">
<option value="global" <?= ($survey['numbering']??'global')==='global'?'selected':'' ?>>
アンケート全体で通番（Q1、Q2…）
</option>
<option value="group" <?= ($survey['numbering']??'global')==='group'?'selected':'' ?>>
グループ毎（Q1-1、Q1-2…）
</option>
</select>
</label>
</div>

<div class="form-group">
<label>
<span>開始日時</span>
<input type="datetime-local"
       name="startAt"
       value="<?= h($survey['startAt']) ?>">
</label>
</div>

<div class="form-group">
<label>
<span>終了日時</span>
<input type="datetime-local"
       name="endAt"
       value="<?= h($survey['endAt']) ?>">
</label>
</div>

</div>

<div class="form-group">
<label>
<span>アンケート説明</span>
<textarea name="description"
          maxlength="5000"><?= h($survey['description']) ?></textarea>
</label>
</div>

</div>
</div>

<div id="groups">

<?php foreach($survey['groups'] as $group): ?>

<div class="group-card"
     draggable="true"
     data-group-id="<?= h($group['id']) ?>">

<input type="hidden"
       name="group_order[]"
       value="<?= h($group['id']) ?>">

<div class="group-head">

<div class="group-title-line">
<span class="drag-handle">☷</span>
<strong>グループ</strong>
</div>

<button type="button"
        class="btn btn-danger"
        data-remove-group>
グループ削除
</button>

</div>

<div class="card-body">

<div class="form-group">
<label>
<span>グループタイトル</span>
<input type="text"
       name="group_title[<?= h($group['id']) ?>]"
       value="<?= h($group['title']) ?>"
       maxlength="200">
</label>
</div>

<div class="questions">

<?php foreach($group['questions'] as $question): ?>

<div class="question-card"
     draggable="true"
     data-question-id="<?= h($question['id']) ?>">

<input type="hidden"
       name="questions_by_group[<?= h($group['id']) ?>][]"
       value="<?= h($question['id']) ?>">

<div class="question-head">

<div>
<span class="drag-handle">☷</span>
<span class="question-number"
      data-question-number><?= h($question['number']) ?></span>
</div>

<button type="button"
        class="btn btn-danger"
        data-remove-question>
削除
</button>

</div>

<div class="card-body">

<div class="form-group">
<label>
<span>質問文</span>
<input type="text"
       name="question_text[<?= h($question['id']) ?>]"
       value="<?= h($question['text']) ?>"
       maxlength="1000">
</label>
</div>

<div class="grid grid-2">

<div class="form-group">
<label>
<span>回答形式</span>
<select name="question_type[<?= h($question['id']) ?>]"
        class="js-question-type">
<option value="single" <?= $question['type']==='single'?'selected':'' ?>>
単一選択
</option>
<option value="multiple" <?= $question['type']==='multiple'?'selected':'' ?>>
複数選択
</option>
<option value="text" <?= $question['type']==='text'?'selected':'' ?>>
自由記述
</option>
</select>
</label>
</div>

<div class="form-group">
<label class="check" style="margin-top:30px">
<input type="checkbox"
       name="question_required[<?= h($question['id']) ?>]"
       value="1"
       <?= !empty($question['required'])?'checked':'' ?>>
必須
</label>
</div>

</div>

<div class="question-options"
     style="<?= $question['type']==='text'?'display:none':'' ?>">

<div class="form-group">
<label>
<span>選択肢</span>
</label>

<div class="options">

<?php foreach(($question['options'] ?? []) as $index=>$option): ?>

<div class="option-row">

<input type="text"
       name="question_option[<?= h($question['id']) ?>][]"
       value="<?= h($option['label']) ?>"
       maxlength="500">

<?php if($question['type']==='single'): ?>
<select name="option_next[<?= h($question['id']) ?>][]"
        style="max-width:260px">
<option value="">分岐なし</option>

<?php foreach($survey['groups'] as $bg): ?>
<?php foreach($bg['questions'] as $bq): ?>
<?php if($bq['id'] !== $question['id']): ?>
<option value="<?= h($bq['id']) ?>"
<?= (($option['nextQuestionId']??'')===$bq['id'])?'selected':'' ?>>
<?= h($bq['number'].' '.$bq['text']) ?>
</option>
<?php endif; ?>
<?php endforeach; ?>
<?php endforeach; ?>

</select>
<?php endif; ?>

<button type="button"
        class="btn btn-light"
        data-remove-option>
削除
</button>

</div>

<?php endforeach; ?>

</div>

<button type="button"
        class="btn btn-secondary"
        data-add-option>
＋ 選択肢追加
</button>

</div>
</div>

<?php if($question['type']==='single'): ?>
<div class="form-group">
<label>
<span>条件分岐</span>
<select name="branching[<?= h($question['id']) ?>]">
<option value="">分岐なし</option>

<?php foreach($survey['groups'] as $bg): ?>
<?php foreach($bg['questions'] as $bq): ?>
<?php if($bq['id'] !== $question['id']): ?>
<option value="<?= h($bq['id']) ?>"
<?= (($question['branching']??'')===$bq['id'])?'selected':'' ?>>
<?= h($bq['number'].' '.$bq['text']) ?>
</option>
<?php endif; ?>
<?php endforeach; ?>
<?php endforeach; ?>

</select>
</label>
</div>
<?php endif; ?>

</div>
</div>

<?php endforeach; ?>

</div>

<button type="button"
        class="btn btn-secondary"
        data-add-question>
＋ 質問を追加
</button>

</div>
</div>

<?php endforeach; ?>

</div>

<div class="card">
<div class="card-body">

<button type="button"
        class="btn btn-secondary"
        data-add-group>
＋ グループを追加
</button>

</div>
</div>

<div class="sticky-actions">
<div class="button-row" style="justify-content:flex-end">

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
</div>

</form>
<?php
    render_footer();
}

/* =========================================================
 * Preview
 * ========================================================= */

function render_preview(array $survey): void
{
    recalc_numbers($survey);

    render_head('プレビュー');
    render_flash();
?>
<div class="page-title">
<div>
<h1><?= h($survey['title']) ?></h1>
<p>アンケートプレビュー</p>
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

<?php if(trim((string)$survey['description'])!==''): ?>
<p><?= nl2br(h($survey['description'])) ?></p>
<?php endif; ?>

<?php foreach($survey['groups'] as $group): ?>

<h2><?= h($group['title']) ?></h2>

<?php foreach($group['questions'] as $question): ?>

<div class="preview-question">

<div>
<strong class="question-number">
<?= h($question['number']) ?>
</strong>
<?= h($question['text']) ?>

<?php if($question['required']): ?>
<span class="badge badge-warning">必須</span>
<?php endif; ?>
</div>

<?php if($question['type']==='text'): ?>

<textarea disabled placeholder="自由記述"></textarea>

<?php else: ?>

<?php foreach($question['options'] as $option): ?>
<label class="answer-option">
<input
    type="<?= $question['type']==='single'?'radio':'checkbox' ?>"
    disabled>
<span><?= h($option['label']) ?></span>
</label>
<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>
</div>
<?php
    render_footer();
}

/* =========================================================
 * Kintone
 * ========================================================= */

function render_kintone(array $config): void
{
    render_head('kintone連携設定');
    render_flash();
?>
<div class="page-title">
<div>
<h1>kintone連携設定</h1>
<p>顧客管理アプリから顧客情報を取得します。</p>
</div>
</div>

<div class="card">
<div class="card-header">
<h2>kintone接続設定</h2>
</div>

<div class="card-body">

<form method="post">

<input type="hidden" name="action" value="save_kintone">

<div class="grid grid-2">

<div class="form-group">
<label>
<span>サブドメイン</span>
<input type="text"
       name="subdomain"
       value="<?= h($config['subdomain']) ?>"
       placeholder="https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>顧客管理アプリID</span>
<input type="number"
       name="app_id"
       min="1"
       value="<?= h($config['app_id']) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>ログイン名</span>
<input type="text"
       name="username"
       value="<?= h($config['username']) ?>"
       autocomplete="username"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>パスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更時のみ入力">
</label>
</div>

<div class="form-group">
<label>
<span>Proxy</span>
<input type="text"
       name="proxy"
       value="<?= h($config['proxy']) ?>"
       placeholder="host:port">
</label>
</div>

<div class="form-group">
<label class="check" style="margin-top:30px">
<input type="checkbox"
       name="verify_ssl"
       value="1"
       <?= !empty($config['verify_ssl'])?'checked':'' ?>>
SSL証明書を検証する
</label>

<p class="help">
POCでは未チェックを初期状態とします。
</p>
</div>

</div>

<div class="button-row">

<button class="btn btn-primary"
        type="submit">
設定保存
</button>

</div>
</form>

<hr style="border:0;border-top:1px solid var(--border);margin:22px 0">

<form method="post">

<input type="hidden"
       name="action"
       value="test_kintone">

<div class="form-group">
<label>
<span>接続テスト用パスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="保存済みの場合は空欄でも可">
</label>
</div>

<button class="btn btn-secondary"
        type="submit">
接続テスト
</button>

</form>

<?php if(!empty($config['last_test'])): ?>
<p class="help">
最終接続確認：
<?= h($config['last_test']) ?>
</p>
<?php endif; ?>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>顧客項目マッピング</h2>
</div>

<div class="card-body">

<form method="post">

<input type="hidden"
       name="action"
       value="fetch_kintone_fields">

<button class="btn btn-secondary"
        type="submit">
項目一覧を再取得
</button>

</form>

<?php if(!empty($config['fields'])): ?>

<form method="post" style="margin-top:20px">

<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<div class="mapping-grid">

<?php
$labels = [
    'organization'=>'組織名',
    'name'=>'氏名',
    'email'=>'メールアドレス',
    'department'=>'部署名',
    'phone'=>'電話番号',
];

foreach($labels as $key=>$label):
?>

<div class="form-group">
<label>
<span><?= h($label) ?></span>

<select name="mapping_<?= h($key) ?>">

<option value="">未設定</option>

<?php foreach($config['fields'] as $field): ?>

<option value="<?= h($field['code']) ?>"
<?= (($config['mapping'][$key]??'')===$field['code'])?'selected':'' ?>>
<?= h($field['label']) ?>
（<?= h($field['code']) ?> / <?= h($field['type']) ?>）
</option>

<?php endforeach; ?>

</select>
</label>
</div>

<?php endforeach; ?>

</div>

<div class="form-group">
<div class="field-label">
住所
</div>

<div class="mapping-address">

<p class="help">
住所は複数のkintone項目を選択できます。
</p>

<?php foreach($config['fields'] as $field): ?>

<label>
<input type="checkbox"
       name="mapping_address[]"
       value="<?= h($field['code']) ?>"
       <?= in_array(
           $field['code'],
           $config['mapping']['address'] ?? [],
           true
       )?'checked':'' ?>>

<span>
<?= h($field['label']) ?>
（<?= h($field['code']) ?> / <?= h($field['type']) ?>）
</span>
</label>

<?php endforeach; ?>

</div>
</div>

<div class="button-row">

<button class="btn btn-primary"
        type="submit">
マッピングを保存
</button>

</div>

</form>

<?php else: ?>

<div class="notice">
先に「項目一覧を再取得」を実行してください。
取得後、組織名・氏名・メールアドレス・部署名・電話番号・住所を設定できます。
</div>

<?php endif; ?>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>顧客情報同期</h2>
</div>

<div class="card-body">

<p>
kintoneの顧客管理アプリから顧客情報を取得し、
サーバー側の顧客情報を更新します。
</p>

<form method="post">

<input type="hidden"
       name="action"
       value="sync_kintone">

<button class="btn btn-primary"
        type="submit"
        data-confirm="kintoneから顧客情報を同期しますか？">
顧客情報を同期
</button>

</form>

<?php if(!empty($config['last_sync'])): ?>
<p class="help">
最終同期：
<?= h($config['last_sync']) ?>
</p>
<?php endif; ?>

</div>
</div>
<?php
    render_footer();
}

/* =========================================================
 * Mail
 * ========================================================= */

function render_mail(array $config): void
{
    render_head('メールサーバ設定');
    render_flash();
?>
<div class="page-title">
<div>
<h1>メールサーバ設定</h1>
<p>SMTPサーバへの接続・認証設定を行います。</p>
</div>
</div>

<div class="card">
<div class="card-header">
<h2>SMTP設定</h2>
</div>

<div class="card-body">

<form method="post">

<input type="hidden"
       name="action"
       value="save_mail">

<div class="grid grid-2">

<div class="form-group">
<label>
<span>SMTPサーバ</span>
<input type="text"
       name="server"
       value="<?= h($config['host']) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>SMTPポート</span>
<input type="number"
       name="port"
       min="1"
       max="65535"
       value="<?= h($config['port']) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>暗号化方式</span>
<select name="encryption">
<option value="ssl" <?= $config['encryption']==='ssl'?'selected':'' ?>>SSL</option>
<option value="tls" <?= $config['encryption']==='tls'?'selected':'' ?>>TLS</option>
<option value="none" <?= $config['encryption']==='none'?'selected':'' ?>>なし</option>
</select>
</label>
</div>

<div class="form-group">
<label class="check" style="margin-top:30px">
<input type="checkbox"
       name="auth"
       value="1"
       <?= !empty($config['auth'])?'checked':'' ?>>
SMTP認証を使用
</label>
</div>

<div class="form-group">
<label>
<span>SMTPユーザー名</span>
<input type="text"
       name="username"
       value="<?= h($config['username']) ?>"
       autocomplete="username">
</label>
</div>

<div class="form-group">
<label>
<span>SMTPパスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更時のみ入力">
</label>
</div>

<div class="form-group">
<label>
<span>送信元メールアドレス</span>
<input type="email"
       name="from_email"
       value="<?= h($config['from_email']) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>送信元名</span>
<input type="text"
       name="from_name"
       value="<?= h($config['from_name']) ?>">
</label>
</div>

<div class="form-group">
<label>
<span>返信先メールアドレス</span>
<input type="email"
       name="reply_to"
       value="<?= h($config['reply_to']) ?>">
</label>
</div>

</div>

<div class="button-row">

<button class="btn btn-primary"
        type="submit">
設定保存
</button>

</div>

</form>

<hr style="border:0;border-top:1px solid var(--border);margin:22px 0">

<h3>接続状態</h3>

<p>
<?php if(!empty($config['last_test'])): ?>
<span class="badge badge-success">
接続確認済み
</span>
<?php else: ?>
<span class="badge badge-gray">
未設定
</span>
<?php endif; ?>
</p>

<form method="post">

<input type="hidden"
       name="action"
       value="test_mail">

<div class="form-group">
<label>
<span>接続テスト用パスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="保存済みの場合は空欄でも可">
</label>
</div>

<button class="btn btn-secondary"
        type="submit">
接続テスト
</button>

</form>

<hr style="border:0;border-top:1px solid var(--border);margin:22px 0">

<form method="post">

<input type="hidden"
       name="action"
       value="send_test_mail">

<div class="form-group">
<label>
<span>テスト送信先メールアドレス</span>
<input type="email"
       name="test_email"
       required>
</label>
</div>

<button class="btn btn-primary"
        type="submit"
        data-confirm="テストメールを送信しますか？">
テストメール送信
</button>

</form>

</div>
</div>
<?php
    render_footer();
}

/* =========================================================
 * Send
 * ========================================================= */

function render_send(
    array $survey,
    array $customers,
    array $history
): void {
    render_head('顧客選択・メール送信');
    render_flash();
?>
<div class="page-title">
<div>
<h1>顧客選択・メール送信</h1>
<p>
対象アンケート：
<strong><?= h($survey['title']) ?></strong>
</p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
一覧へ戻る
</a>
</div>

<div class="card">
<div class="card-header">
<h2>顧客選択・メール作成</h2>
</div>

<div class="card-body">

<div class="notice">
メール変数：
{顧客名} / {アンケートURL}
</div>

<form method="post">

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="form-group">
<label>
<span>顧客検索</span>
<input type="search"
       id="customerSearch"
       placeholder="氏名・組織名・メールアドレス等で検索">
</label>
</div>

<div class="table-wrap">

<table>
<thead>
<tr>
<th>
<label class="check">
<input type="checkbox"
       id="selectAllCustomers">
全選択
</label>
</th>
<th>氏名</th>
<th>組織名</th>
<th>部署</th>
<th>メール</th>
</tr>
</thead>

<tbody>

<?php foreach($customers as $customer): ?>

<tr data-customer-row>

<td>
<input type="checkbox"
       class="customer-check"
       name="customer_ids[]"
       value="<?= h($customer['id']) ?>">
</td>

<td><?= h($customer['name']) ?></td>
<td><?= h($customer['organization']) ?></td>
<td><?= h($customer['department']) ?></td>
<td><?= h($customer['email']) ?></td>

</tr>

<?php endforeach; ?>

<?php if(!$customers): ?>
<tr>
<td colspan="5">
<div class="empty">
顧客情報がありません。
先にkintoneから顧客情報を同期してください。
</div>
</td>
</tr>
<?php endif; ?>

</tbody>
</table>

</div>

<div class="grid grid-2" style="margin-top:20px">

<div class="form-group">
<label>
<span>メール件名</span>
<input type="text"
       name="subject"
       value="<?= h($survey['title'].'のご案内') ?>"
       required>
</label>
</div>

<div></div>

<div class="form-group" style="grid-column:1/-1">
<label>
<span>メール本文</span>
<textarea name="body"
          required>「{顧客名}」様

以下のアンケートへのご回答をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>
</label>
</div>

</div>

<div class="button-row">

<button class="btn btn-primary"
        type="submit"
        data-confirm="選択した顧客へメールを送信しますか？">
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

<div class="table-wrap">

<table>
<thead>
<tr>
<th>日時</th>
<th>顧客</th>
<th>種別</th>
<th>結果</th>
</tr>
</thead>

<tbody>

<?php if(!$history): ?>

<tr>
<td colspan="4">
<div class="empty">
送信履歴はありません。
</div>
</td>
</tr>

<?php endif; ?>

<?php foreach($history as $item): ?>

<tr>
<td><?= h($item['createdAt'] ?? '') ?></td>
<td><?= h($item['customer_name'] ?? '') ?></td>
<td><?= h($item['type'] ?? '') ?></td>
<td><?= h($item['result'] ?? '') ?></td>
</tr>

<?php endforeach; ?>

</tbody>
</table>

</div>

</div>
</div>
<?php
    render_footer();
}

/* =========================================================
 * Analytics
 * ========================================================= */

function render_analytics(
    array $survey,
    array $answers,
    array $customers
): void {
    $surveyAnswers = array_values(array_filter(
        $answers,
        static fn(array $answer): bool =>
            ($answer['survey_id'] ?? '') === $survey['id']
    ));

    $answerCount = count($surveyAnswers);

    render_head('回答集計・分析');
    render_flash();
?>
<div class="page-title">
<div>
<h1>回答集計・分析</h1>
<p>
対象アンケート：
<strong><?= h($survey['title']) ?></strong>
</p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
一覧へ戻る
</a>
</div>

<div class="grid grid-3">

<div class="card">
<div class="card-body">
<div class="help">送信対象者数</div>
<h2><?= h(count($customers)) ?></h2>
</div>
</div>

<div class="card">
<div class="card-body">
<div class="help">回答数</div>
<h2><?= h($answerCount) ?></h2>
</div>
</div>

<div class="card">
<div class="card-body">
<div class="help">未回答数</div>
<h2><?= h(max(0,count($customers)-$answerCount)) ?></h2>
</div>
</div>

</div>

<?php if($answerCount===0): ?>

<div class="card">
<div class="card-body">
<div class="empty">
現在、回答データはありません
</div>
</div>
</div>

<?php else: ?>

<div class="card">
<div class="card-header">
<h2>設問別集計</h2>
</div>

<div class="card-body">

<?php foreach($survey['groups'] as $group): ?>

<h3><?= h($group['title']) ?></h3>

<?php foreach($group['questions'] as $question): ?>

<?php
$counts = [];

foreach($question['options'] ?? [] as $option){
    $counts[$option['label']] = 0;
}

foreach($surveyAnswers as $answer){
    $value =
        $answer['answers'][$question['id']]
        ?? '';

    $values = is_array($value)
        ? $value
        : [$value];

    foreach($values as $value){
        $value = (string)$value;

        if(isset($counts[$value])){
            $counts[$value]++;
        }
    }
}
?>

<div class="preview-question">

<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<?php if($question['type']==='text'): ?>

<p class="help">自由記述</p>

<?php else: ?>

<?php foreach($counts as $label=>$count): ?>

<div style="margin:10px 0">

<div style="display:flex;justify-content:space-between">
<span><?= h($label) ?></span>
<strong><?= h($count) ?></strong>
</div>

<div style="height:8px;background:#e2e8f0;border-radius:999px">
<div style="
height:100%;
width:<?= $answerCount>0
    ? min(100,($count/$answerCount)*100)
    : 0 ?>%;
background:var(--primary);
border-radius:999px"></div>
</div>

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>
<?php endforeach; ?>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>個別回答</h2>
</div>

<div class="card-body">

<?php foreach($surveyAnswers as $index=>$answer): ?>

<div class="preview-question">

<strong>回答 <?= h($index+1) ?></strong>

<?php foreach($survey['groups'] as $group): ?>
<?php foreach($group['questions'] as $question): ?>

<?php
$value =
    $answer['answers'][$question['id']]
    ?? '';

if(is_array($value)){
    $value = implode(', ', array_map('strval',$value));
}
?>

<p>
<strong><?= h($question['number']) ?></strong>
<?= h((string)$value) ?>
</p>

<?php endforeach; ?>
<?php endforeach; ?>

</div>

<?php endforeach; ?>

</div>
</div>

<?php endif; ?>
<?php
    render_footer();
}

/* =========================================================
 * Answer
 * ========================================================= */

function render_answer(array $survey): void
{
    recalc_numbers($survey);

    $draft = $_SESSION['answer_draft'] ?? [];

    render_head(
        'アンケート回答',
        false
    );
?>
<div class="answer-shell">

<div class="page-title">
<div>
<h1><?= h($survey['title']) ?></h1>
<p><?= nl2br(h($survey['description'])) ?></p>
</div>
</div>

<form method="post">

<input type="hidden"
       name="action"
       value="answer_next">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="card">
<div class="card-body">

<?php foreach($survey['groups'] as $group): ?>

<h2><?= h($group['title']) ?></h2>

<?php foreach($group['questions'] as $question): ?>

<div class="form-group"
     data-answer-question="<?= h($question['id']) ?>">

<div class="field-label">

<?= h($question['number']) ?>
<?= h($question['text']) ?>

<?php if($question['required']): ?>
<span class="badge badge-warning">必須</span>
<?php endif; ?>

</div>

<?php if($question['type']==='text'): ?>

<textarea name="answer[<?= h($question['id']) ?>]"
          <?= $question['required']?'required':'' ?>><?= h(
              is_scalar($draft[$question['id']]??'')
              ? (string)($draft[$question['id']]??'')
              : ''
          ) ?></textarea>

<?php else: ?>

<?php foreach($question['options'] as $option): ?>

<label class="answer-option">

<input
    type="<?= $question['type']==='single'?'radio':'checkbox' ?>"
    name="answer[<?= h($question['id']) ?>]<?= $question['type']==='multiple'?'[]':'' ?>"
    value="<?= h($option['label']) ?>"
    <?= (
        (
            is_array($draft[$question['id']]??null)
            && in_array(
                $option['label'],
                $draft[$question['id']],
                true
            )
        )
        ||
        (
            !is_array($draft[$question['id']]??null)
            && ($draft[$question['id']]??'') === $option['label']
        )
    )?'checked':'' ?>
    <?= $question['required']?'required':'' ?>>

<span><?= h($option['label']) ?></span>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>
</div>

<div class="button-row" style="justify-content:flex-end">

<button class="btn btn-primary"
        type="submit">
次へ
</button>

</div>

</form>

</div>
<?php
    render_footer();
}

/* =========================================================
 * Confirm
 * ========================================================= */

function render_confirm(array $survey): void
{
    $draft = $_SESSION['answer_draft'] ?? [];

    render_head(
        '回答確認',
        false
    );
?>
<div class="answer-shell">

<div class="page-title">
<div>
<h1>回答確認</h1>
<p><?= h($survey['title']) ?></p>
</div>
</div>

<div class="card">
<div class="card-body">

<?php foreach($survey['groups'] as $group): ?>

<h2><?= h($group['title']) ?></h2>

<?php foreach($group['questions'] as $question): ?>

<?php
$value = $draft[$question['id']] ?? '';

if(is_array($value)){
    $value = implode(', ', array_map('strval',$value));
}
?>

<div class="preview-question">

<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<p><?= nl2br(h((string)$value)) ?></p>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="button-row">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'answer',
       'id'=>$survey['id']
   ])) ?>">
修正する
</a>

<form method="post">

<input type="hidden"
       name="action"
       value="submit_answer">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<button class="btn btn-primary"
        type="submit"
        data-confirm="この回答を送信しますか？">
回答を送信
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
 * Complete
 * ========================================================= */

function render_complete(array $survey): void
{
    render_head(
        '回答完了',
        false
    );
?>
<div class="answer-shell">

<div class="card">
<div class="card-body"
     style="text-align:center;padding:55px 25px">

<h1>回答ありがとうございました</h1>

<p>
「<?= h($survey['title']) ?>」への回答を受け付けました。
</p>

</div>
</div>

</div>
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

    $redirect = handle_post($data, $settings);

    if ($redirect !== null) {
        if (
            $redirect === 'confirm'
            && isset($_POST['survey_id'])
        ) {
            redirect_screen(
                'confirm',
                ['id'=>post_string('survey_id')]
            );
        }

        redirect_screen($redirect);
    }

    /*
     * POST後も最新データを再ロード。
     * 同一リクエスト内で設定保存→画面表示した場合の
     * 古い配列参照を防止する。
     */
    $data = load_data();
    $settings = load_settings();

    if (refresh_statuses($data)) {
        save_data($data);
    }

    $screen = get_string('screen');

    if ($screen === '') {
        $screen = 'list';
    }

    /*
     * 回答者画面
     * 管理者ヘッダー・メニューを絶対に表示しない。
     */

    if (
        in_array(
            $screen,
            ['answer','confirm','complete'],
            true
        )
    ) {
        $id = get_string('id');
        $survey = survey_by_id(
            $data['surveys'],
            $id
        );

        if ($survey === null) {
            render_head('アンケート', false);
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
     * 管理者画面
     */

    switch ($screen) {

        case 'edit':
            $id = get_string('id');

            if ($id === 'new') {
                $survey = [
                    'id' => uuid('survey'),
                    'title' => '',
                    'description' => '',
                    'startAt' => date('Y-m-d\TH:i'),
                    'endAt' => date(
                        'Y-m-d\TH:i',
                        strtotime('+30 days')
                    ),
                    'status' => 'draft',
                    'numbering' => 'global',
                    'createdAt' => date('Y-m-d H:i:s'),
                    'updatedAt' => date('Y-m-d H:i:s'),
                    'groups' => [[
                        'id' => uuid('group'),
                        'title' => '基本アンケート',
                        'questions' => [],
                    ]],
                ];

                render_edit($survey);
                break;
            }

            $survey = survey_by_id(
                $data['surveys'],
                $id
            );

            if ($survey === null) {
                flash('error','アンケートが見つかりません。');
                redirect_screen('list');
            }

            render_edit($survey);
            break;

        case 'preview':
            $survey = survey_by_id(
                $data['surveys'],
                get_string('id')
            );

            if ($survey === null) {
                flash('error','アンケートが見つかりません。');
                redirect_screen('list');
            }

            render_preview($survey);
            break;

        case 'send':
            $survey = survey_by_id(
                $data['surveys'],
                get_string('id')
            );

            if ($survey === null) {
                flash('error','対象アンケートが見つかりません。');
                redirect_screen('list');
            }

            $history = array_values(array_filter(
                $data['send_history'],
                static fn(array $item): bool =>
                    ($item['survey_id'] ?? '') === $survey['id']
            ));

            usort(
                $history,
                static fn(array $a,array $b): int =>
                    strcmp(
                        (string)($b['createdAt']??''),
                        (string)($a['createdAt']??'')
                    )
            );

            render_send(
                $survey,
                $data['customers'],
                $history
            );
            break;

        case 'analytics':
            $survey = survey_by_id(
                $data['surveys'],
                get_string('id')
            );

            if ($survey === null) {
                flash('error','対象アンケートが見つかりません。');
                redirect_screen('list');
            }

            render_analytics(
                $survey,
                $data['answers'],
                $data['customers']
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
                $data['surveys']
            );
            break;
    }

} catch (Throwable $e) {
    /*
     * 白画面を禁止。
     * 内部スタックトレースや認証情報は表示しない。
     */
    http_response_code(500);

    render_head(
        'システムエラー'
    );
?>
<div class="alert alert-error">
システムエラーが発生しました。
</div>

<div class="card">
<div class="card-body">
<p>
アプリケーションの処理を完了できませんでした。
設定・ファイル権限・サーバー環境を確認してください。
</p>
</div>
</div>
<?php
    render_footer();
}
?>
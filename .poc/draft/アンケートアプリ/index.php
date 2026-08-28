<?php
declare(strict_types=1);


/*
 * アンケートアプリ
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし
 *
 * 重要:
 * - 単一エントリーポイント
 * - POST後のLocationリダイレクトを使用しない
 * - kintone / SMTPの処理結果は同一リクエストで画面表示
 * - 外部サービスの認証情報はHTML/JS/URLへ出力しない
 * - データはサーバー側JSONへ永続化
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
    $v = $_POST[$key] ?? '';
    return is_scalar($v) ? trim((string)$v) : '';
}

function get_string(string $key): string
{
    $v = $_GET[$key] ?? '';
    return is_scalar($v) ? trim((string)$v) : '';
}

function post_bool(string $key): bool
{
    return isset($_POST[$key])
        && in_array((string)$_POST[$key], ['1', 'on', 'true'], true);
}

function uuid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function app_url(array $params = []): string
{
    $base = (string)($_SERVER['SCRIPT_NAME'] ?? 'index.php');

    if (!$params) {
        return $base;
    }

    return $base . '?' .
        http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
}

function public_answer_url(string $surveyId): string
{
    $scheme = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443
    ) ? 'https' : 'http';

    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host .
        app_url([
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
    $now = date('Y-m-d H:i:s');

    return [
        'surveys' => [[
            'id' => 'survey-001',
            'title' => '顧客満足度アンケート',
            'description' => 'サービスについてのご意見をお聞かせください。',
            'startAt' => date('Y-m-d\TH:i'),
            'endAt' => date(
                'Y-m-d\TH:i',
                strtotime('+30 days')
            ),
            'status' => 'draft',
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
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
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

        fwrite($fp, $json);
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
    save_json(DATA_FILE, $data);
}

function load_settings(): array
{
    $default = default_settings();
    $settings = load_json(SETTINGS_FILE, $default);

    $settings['kintone'] = array_replace_recursive(
        $default['kintone'],
        is_array($settings['kintone'] ?? null)
            ? $settings['kintone']
            : []
    );

    $settings['mail'] = array_replace_recursive(
        $default['mail'],
        is_array($settings['mail'] ?? null)
            ? $settings['mail']
            : []
    );

    return $settings;
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
 * アンケート共通
 * ========================================================= */

function survey_index(array $surveys, string $id): int
{
    foreach ($surveys as $i => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

function survey_by_id(array $surveys, string $id): ?array
{
    $index = survey_index($surveys, $id);

    if ($index < 0) {
        return null;
    }

    return $surveys[$index];
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
        $survey['updatedAt'] = date('Y-m-d H:i:s');
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

function status_label(string $status): string
{
    switch ($status) {
        case 'published':
            return '公開中';

        case 'stopped':
            return '停止';

        case 'ended':
            return '終了';

        default:
            return '下書き';
    }
}

function status_class(string $status): string
{
    switch ($status) {
        case 'published':
            return 'success';

        case 'stopped':
            return 'warning';

        default:
            return 'gray';
    }
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

    if (
        str_ends_with(
            strtolower($value),
            '.cybozu.com'
        )
    ) {
        return substr(
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
            '/^[a-zA-Z0-9][a-zA-Z0-9-]*$/',
            $subdomain
        )
    ) {
        $errors[] =
            'kintoneサブドメインが不正です。';
    }

    $appId = (string)($config['app_id'] ?? '');

    if (
        !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        $errors[] =
            'kintoneアプリIDが不正です。';
    }

    if (
        trim((string)($config['username'] ?? '')) === ''
    ) {
        $errors[] =
            'kintoneログイン名を入力してください。';
    }

    if (
        $requirePassword
        && trim((string)($config['password'] ?? '')) === ''
    ) {
        $errors[] =
            'kintoneパスワードを入力してください。';
    }

    $proxy = trim((string)($config['proxy'] ?? ''));

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
    $errors = validate_kintone_config(
        $config,
        true
    );

    if ($errors) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    $subdomain = normalize_kintone_subdomain(
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
        (string)$config['password']
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
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            throw new RuntimeException(
                'kintoneリクエストを生成できません。'
            );
        }

        $headers[] = 'Content-Type: application/json';
        $headers[] =
            'Content-Length: ' . strlen($content);
    }

    $verify = !empty($config['verify_ssl']);

    $ssl = [
        'verify_peer' => $verify,
        'verify_peer_name' => $verify,
        'allow_self_signed' => !$verify,
        'SNI_enabled' => true,
        'peer_name' =>
            $subdomain . '.cybozu.com',
    ];

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => KINTONE_READ_TIMEOUT,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => $ssl,
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

        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

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
                $m
            )
        ) {
            $status = (int)$m[1];
        }
    }

    if ($response === false) {
        $response = '';
    }

    $json = json_decode(
        $response,
        true
    );

    if ($status < 200 || $status >= 300) {
        $code = is_array($json)
            ? (string)($json['code'] ?? '')
            : '';

        $message = is_array($json)
            ? (string)($json['message'] ?? '')
            : '';

        $detail =
            'kintone APIエラー';

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
        'body' => is_array($json)
            ? $json
            : [],
        'raw' => $response,
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

function kintone_records(array $config): array
{
    return kintone_request(
        $config,
        'GET',
        '/k/v1/records.json?app=' .
        rawurlencode(
            (string)$config['app_id']
        ) .
        '&totalCount=true'
    );
}

function normalize_kintone_fields(
    array $response
): array {
    $fields = $response['properties'] ?? [];

    if (!is_array($fields)) {
        return [];
    }

    $result = [];

    foreach ($fields as $code => $field) {
        if (!is_array($field)) {
            continue;
        }

        $result[] = [
            'code' => (string)$code,
            'label' => (string)(
                $field['label'] ?? $code
            ),
            'type' => (string)(
                $field['type'] ?? ''
            ),
        ];
    }

    usort(
        $result,
        static function (
            array $a,
            array $b
        ): int {
            return strnatcasecmp(
                $a['code'],
                $b['code']
            );
        }
    );

    return $result;
}

function record_value(
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

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                if (isset($item['name'])) {
                    $parts[] =
                        (string)$item['name'];
                } elseif (isset($item['value'])) {
                    $parts[] =
                        (string)$item['value'];
                }
            } else {
                $parts[] = (string)$item;
            }
        }

        return implode(
            ' ',
            array_filter(
                $parts,
                static fn($v) => $v !== ''
            )
        );
    }

    return (string)$value;
}


/* =========================================================
 * SMTP
 * ========================================================= */

function validate_mail_config(array $config): array
{
    $errors = [];

    $host = trim(
        (string)($config['host'] ?? '')
    );

    if ($host === '') {
        $errors[] =
            'SMTPサーバを入力してください。';
    }

    $port = (int)($config['port'] ?? 0);

    if ($port < 1 || $port > 65535) {
        $errors[] =
            'SMTPポートが不正です。';
    }

    $encryption =
        (string)($config['encryption'] ?? '');

    if (
        !in_array(
            $encryption,
            ['ssl', 'tls', 'none'],
            true
        )
    ) {
        $errors[] =
            '暗号化方式が不正です。';
    }

    if (
        !filter_var(
            (string)($config['from_email'] ?? ''),
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            '送信元メールアドレスが不正です。';
    }

    $reply =
        trim((string)($config['reply_to'] ?? ''));

    if (
        $reply !== ''
        && !filter_var(
            $reply,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            '返信先メールアドレスが不正です。';
    }

    return $errors;
}

function smtp_open(array $config)
{
    $errors = validate_mail_config($config);

    if ($errors) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    $host = trim(
        (string)$config['host']
    );

    $port = (int)$config['port'];

    $encryption =
        (string)$config['encryption'];

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
        10,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($socket)) {
        throw new RuntimeException(
            'SMTP接続失敗: ' .
            $errstr .
            ' (' .
            $errno .
            ')'
        );
    }

    stream_set_timeout($socket, 30);

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
        $username =
            (string)($config['username'] ?? '');

        $password =
            (string)($config['password'] ?? '');

        if ($username === '' || $password === '') {
            throw new RuntimeException(
                'SMTP認証情報が設定されていません。'
            );
        }

        smtp_command(
            $socket,
            'AUTH LOGIN',
            [334]
        );

        smtp_command(
            $socket,
            base64_encode($username),
            [334]
        );

        smtp_command(
            $socket,
            base64_encode($password),
            [235]
        );
    }

    return $socket;
}

function smtp_expect(
    $socket,
    array $codes
): string {
    $response = '';

    while (($line = fgets($socket)) !== false) {
        $response .= $line;

        if (
            preg_match(
                '/^(\d{3})([ -])/',
                $line,
                $m
            )
        ) {
            if ($m[2] === ' ') {
                $code = (int)$m[1];

                if (!in_array(
                    $code,
                    $codes,
                    true
                )) {
                    throw new RuntimeException(
                        'SMTPエラー: ' .
                        $code .
                        ' ' .
                        trim($response)
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

    return $response;
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

function smtp_test(array $config): void
{
    $socket = smtp_open($config);

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

function smtp_header_encode(string $value): string
{
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader(
            $value,
            'UTF-8',
            'B'
        );
    }

    return $value;
}

function smtp_send(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    if (!filter_var(
        $to,
        FILTER_VALIDATE_EMAIL
    )) {
        throw new RuntimeException(
            '送信先メールアドレスが不正です。'
        );
    }

    $from =
        trim((string)$config['from_email']);

    if (!filter_var(
        $from,
        FILTER_VALIDATE_EMAIL
    )) {
        throw new RuntimeException(
            '送信元メールアドレスが不正です。'
        );
    }

    $socket = smtp_open($config);

    try {
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

        smtp_command(
            $socket,
            'DATA',
            [354]
        );

        $fromName =
            (string)($config['from_name'] ?? '');

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' .
                smtp_header_encode(
                    $fromName !== ''
                        ? $fromName
                        : $from
                ) .
                ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' .
                smtp_header_encode($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $reply =
            trim((string)($config['reply_to'] ?? ''));

        if ($reply !== '') {
            $headers[] =
                'Reply-To: ' . $reply;
        }

        $body = str_replace(
            ["\r\n", "\r"],
            "\n",
            $body
        );

        $body = preg_replace(
            '/^\./m',
            '..',
            $body
        ) ?? $body;

        $message =
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            str_replace(
                "\n",
                "\r\n",
                $body
            ) .
            "\r\n.";

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
    $description =
        (string)($_POST['description'] ?? '');

    $startAt = post_string('startAt');
    $endAt = post_string('endAt');

    $numbering =
        post_string('numbering');

    if ($title === '') {
        $errors[] =
            'アンケートタイトルを入力してください。';
    }

    if (mb_strlen($title) > MAX_TITLE) {
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
 * POST処理
 *
 * ここではLocationヘッダーを絶対に出さない。
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

    $action = post_string('action');

    try {
        switch ($action) {

            /* -----------------------------------------
             * アンケート保存
             * ----------------------------------------- */

            case 'save_survey':
                $input = validate_survey_input();

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
                        'id' => post_string(
                            'survey_id'
                        ),
                    ];
                }

                $id =
                    post_string('survey_id');

                $index = survey_index(
                    $data['surveys'],
                    $id
                );

                if ($index < 0) {
                    $survey = [
                        'id' => uuid('survey'),
                        'title' => $input['title'],
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
                        'groups' => [],
                    ];
                } else {
                    $survey =
                        $data['surveys'][$index];

                    /*
                     * 保存時は状態を勝手に変更しない。
                     */
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

                    $survey['updatedAt'] =
                        date('Y-m-d H:i:s');
                }

                $survey['groups'] = [];

                $groupOrder =
                    $_POST['group_order'] ?? [];

                $groupTitles =
                    $_POST['group_title'] ?? [];

                $questionTexts =
                    $_POST['question_text'] ?? [];

                $questionTypes =
                    $_POST['question_type'] ?? [];

                $questionRequired =
                    $_POST['question_required'] ?? [];

                $questionOptions =
                    $_POST['question_option'] ?? [];

                $optionNext =
                    $_POST['option_next'] ?? [];

                if (!is_array($groupOrder)) {
                    $groupOrder = [];
                }

                foreach ($groupOrder as $groupId) {
                    $groupId = trim(
                        (string)$groupId
                    );

                    if ($groupId === '') {
                        continue;
                    }

                    $group = [
                        'id' => $groupId,
                        'title' => trim(
                            (string)(
                                $groupTitles[$groupId]
                                ?? '新しいグループ'
                            )
                        ),
                        'questions' => [],
                    ];

                    if (
                        $group['title'] === ''
                    ) {
                        $group['title'] =
                            '新しいグループ';
                    }

                    $questionIds =
                        $_POST[
                            'questions_by_group'
                        ][$groupId] ?? [];

                    if (!is_array(
                        $questionIds
                    )) {
                        $questionIds = [];
                    }

                    foreach ($questionIds as $qid) {
                        $qid = trim((string)$qid);

                        if ($qid === '') {
                            continue;
                        }

                        $type =
                            (string)(
                                $questionTypes[$qid]
                                ?? 'single'
                            );

                        if (!in_array(
                            $type,
                            ['single', 'multiple', 'text'],
                            true
                        )) {
                            $type = 'single';
                        }

                        $options = [];

                        $rawOptions =
                            $questionOptions[$qid]
                            ?? [];

                        if (!is_array(
                            $rawOptions
                        )) {
                            $rawOptions = [];
                        }

                        foreach (
                            array_values(
                                $rawOptions
                            ) as $oi => $label
                        ) {
                            $label = trim(
                                (string)$label
                            );

                            if ($label === '') {
                                continue;
                            }

                            $next = '';

                            if (
                                $type === 'single'
                                && isset(
                                    $optionNext[$qid]
                                )
                                && is_array(
                                    $optionNext[$qid]
                                )
                            ) {
                                $next =
                                    (string)(
                                        $optionNext[$qid][$oi]
                                        ?? ''
                                    );
                            }

                            $options[] = [
                                'id' =>
                                    uuid('option'),
                                'label' =>
                                    mb_substr(
                                        $label,
                                        0,
                                        MAX_OPTION
                                    ),
                                'nextQuestionId' =>
                                    $next,
                            ];
                        }

                        $group['questions'][] = [
                            'id' => $qid,
                            'number' => '',
                            'text' =>
                                mb_substr(
                                    trim(
                                        (string)(
                                            $questionTexts[$qid]
                                            ?? ''
                                        )
                                    ),
                                    0,
                                    MAX_QUESTION
                                ),
                            'type' => $type,
                            'required' =>
                                isset(
                                    $questionRequired[$qid]
                                ),
                            'options' => $options,
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
                    $data['surveys'][$index] =
                        $survey;
                }

                save_data($data);

                flash(
                    'success',
                    'アンケートを保存しました。'
                );

                /*
                 * リダイレクトせず一覧を直接表示。
                 */
                return [
                    'screen' => 'list',
                ];


            /* -----------------------------------------
             * 状態変更
             * ----------------------------------------- */

            case 'change_status':
                $id =
                    post_string('survey_id');

                $next =
                    post_string('next_status');

                $index = survey_index(
                    $data['surveys'],
                    $id
                );

                if ($index < 0) {
                    flash(
                        'error',
                        'アンケートが見つかりません。'
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
                    !isset($allowed[$current])
                    || !in_array(
                        $next,
                        $allowed[$current],
                        true
                    )
                ) {
                    flash(
                        'error',
                        '許可されていない状態変更です。'
                    );

                    return [
                        'screen' => 'edit',
                        'id' => $id,
                    ];
                }

                $data['surveys'][$index]['status'] =
                    $next;

                $data['surveys'][$index]['updatedAt'] =
                    date('Y-m-d H:i:s');

                save_data($data);

                flash(
                    'success',
                    '状態を変更しました。'
                );

                return [
                    'screen' => 'edit',
                    'id' => $id,
                ];


            /* -----------------------------------------
             * 複製
             * ----------------------------------------- */

            case 'duplicate_survey':
                $id =
                    post_string('survey_id');

                $survey = survey_by_id(
                    $data['surveys'],
                    $id
                );

                if ($survey === null) {
                    flash(
                        'error',
                        'アンケートが見つかりません。'
                    );

                    return [
                        'screen' => 'list',
                    ];
                }

                $survey['id'] =
                    uuid('survey');

                $survey['title'] =
                    $survey['title'] .
                    '（複製）';

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

                recalc_numbers($survey);

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


            /* -----------------------------------------
             * 削除
             * ----------------------------------------- */

            case 'delete_survey':
                $id =
                    post_string('survey_id');

                $index = survey_index(
                    $data['surveys'],
                    $id
                );

                if ($index < 0) {
                    flash(
                        'error',
                        'アンケートが見つかりません。'
                    );

                    return [
                        'screen' => 'list',
                    ];
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


            /* -----------------------------------------
             * 回答 → 確認
             * ----------------------------------------- */

            case 'answer_next':
                $surveyId =
                    post_string('survey_id');

                $survey = survey_by_id(
                    $data['surveys'],
                    $surveyId
                );

                if ($survey === null) {
                    flash(
                        'error',
                        'アンケートが見つかりません。'
                    );

                    return [
                        'screen' => 'answer',
                        'id' => $surveyId,
                    ];
                }

                $answers = [];

                foreach (
                    $survey['groups']
                    as $group
                ) {
                    foreach (
                        $group['questions']
                        as $question
                    ) {
                        $qid =
                            (string)$question['id'];

                        if (
                            $question['type']
                            === 'multiple'
                        ) {
                            $value =
                                $_POST['answer'][$qid]
                                ?? [];

                            $value =
                                is_array($value)
                                    ? array_values(
                                        array_map(
                                            'strval',
                                            $value
                                        )
                                    )
                                    : [];

                        } else {
                            $value =
                                (string)(
                                    $_POST[
                                        'answer'
                                    ][$qid]
                                    ?? ''
                                );
                        }

                        if (
                            $question['required']
                            && (
                                $value === ''
                                || (
                                    is_array($value)
                                    && !$value
                                )
                            )
                        ) {
                            flash(
                                'error',
                                $question['number'] .
                                ' は必須です。'
                            );

                            return [
                                'screen' =>
                                    'answer',
                                'id' =>
                                    $surveyId,
                            ];
                        }

                        $answers[$qid] =
                            $value;
                    }
                }

                $_SESSION['answer_draft'] =
                    $answers;

                return [
                    'screen' => 'confirm',
                    'id' => $surveyId,
                ];


            /* -----------------------------------------
             * 回答修正
             * ----------------------------------------- */

            case 'answer_back':
                return [
                    'screen' => 'answer',
                    'id' => post_string(
                        'survey_id'
                    ),
                ];


            /* -----------------------------------------
             * 回答送信
             * ----------------------------------------- */

            case 'submit_answer':
                $surveyId =
                    post_string('survey_id');

                $survey = survey_by_id(
                    $data['surveys'],
                    $surveyId
                );

                if ($survey === null) {
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

                $data['answers'][] = [
                    'id' =>
                        uuid('answer'),
                    'survey_id' =>
                        $surveyId,
                    'answers' =>
                        is_array($draft)
                            ? $draft
                            : [],
                    'createdAt' =>
                        date('Y-m-d H:i:s'),
                ];

                unset(
                    $_SESSION['answer_draft']
                );

                save_data($data);

                return [
                    'screen' => 'complete',
                    'id' => $surveyId,
                ];


            /* -----------------------------------------
             * kintone設定保存
             * ----------------------------------------- */

            case 'save_kintone':
                $current =
                    $settings['kintone'];

                $newPassword =
                    post_string('password');

                $password =
                    $newPassword !== ''
                        ? $newPassword
                        : (string)(
                            $current['password']
                            ?? ''
                        );

                $config = [
                    'subdomain' =>
                        normalize_kintone_subdomain(
                            post_string(
                                'subdomain'
                            )
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
                        $current['mapping']
                        ?? [],
                    'fields' =>
                        $current['fields']
                        ?? [],
                    'last_test' =>
                        $current['last_test']
                        ?? null,
                    'last_sync' =>
                        $current['last_sync']
                        ?? null,
                ];

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

                $settings['kintone'] =
                    $config;

                save_settings($settings);

                flash(
                    'success',
                    'kintone設定を保存しました。'
                );

                return [
                    'screen' => 'kintone',
                ];


            /* -----------------------------------------
             * kintone接続テスト
             *
             * POSTされた設定をそのまま使用する。
             * 保存→リダイレクト→再読込という経路を作らない。
             * ----------------------------------------- */

            case 'test_kintone':
                $current =
                    $settings['kintone'];

                $password =
                    post_string('password');

                if ($password === '') {
                    $password =
                        (string)(
                            $current['password']
                            ?? ''
                        );
                }

                $config = [
                    'subdomain' =>
                        normalize_kintone_subdomain(
                            post_string(
                                'subdomain'
                            )
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

                try {
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
                        kintone_test($config);

                    /*
                     * 接続テスト成功時だけ設定へ反映。
                     */
                    $settings['kintone'] =
                        array_replace(
                            $settings['kintone'],
                            $config
                        );

                    $settings['kintone'][
                        'last_test'
                    ] =
                        date('Y-m-d H:i:s');

                    save_settings(
                        $settings
                    );

                    flash(
                        'success',
                        'kintone接続成功。HTTP ' .
                        $result['status']
                    );
                } catch (Throwable $e) {
                    flash(
                        'error',
                        'kintone接続失敗：' .
                        $e->getMessage()
                    );
                }

                return [
                    'screen' => 'kintone',
                ];


            /* -----------------------------------------
             * kintone項目取得
             * ----------------------------------------- */

            case 'fetch_kintone_fields':
                $current =
                    $settings['kintone'];

                $password =
                    post_string('password');

                if ($password === '') {
                    $password =
                        (string)(
                            $current['password']
                            ?? ''
                        );
                }

                $config = [
                    'subdomain' =>
                        normalize_kintone_subdomain(
                            post_string(
                                'subdomain'
                            )
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

                try {
                    $result =
                        kintone_fields(
                            $config
                        );

                    $fields =
                        normalize_kintone_fields(
                            $result['body']
                        );

                    if (!$fields) {
                        throw new RuntimeException(
                            'kintoneから項目を取得できませんでした。'
                        );
                    }

                    $settings['kintone'] =
                        array_replace(
                            $settings['kintone'],
                            $config
                        );

                    $settings['kintone'][
                        'fields'
                    ] = $fields;

                    save_settings(
                        $settings
                    );

                    flash(
                        'success',
                        count($fields) .
                        '件の項目を取得しました。'
                    );
                } catch (Throwable $e) {
                    flash(
                        'error',
                        'kintone項目取得失敗：' .
                        $e->getMessage()
                    );
                }

                return [
                    'screen' => 'kintone',
                ];


            /* -----------------------------------------
             * kintoneマッピング
             * ----------------------------------------- */

            case 'save_kintone_mapping':
                $fields =
                    $settings['kintone']['fields']
                    ?? [];

                $validCodes = [];

                foreach ($fields as $field) {
                    if (
                        isset($field['code'])
                    ) {
                        $validCodes[] =
                            (string)$field['code'];
                    }
                }

                $mapping = [
                    'organization' =>
                        post_string(
                            'mapping_organization'
                        ),
                    'name' =>
                        post_string(
                            'mapping_name'
                        ),
                    'email' =>
                        post_string(
                            'mapping_email'
                        ),
                    'department' =>
                        post_string(
                            'mapping_department'
                        ),
                    'phone' =>
                        post_string(
                            'mapping_phone'
                        ),
                    'address' => [],
                ];

                $address =
                    $_POST['mapping_address']
                    ?? [];

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

                $settings['kintone'][
                    'mapping'
                ] = $mapping;

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


            /* -----------------------------------------
             * kintone同期
             * ----------------------------------------- */

            case 'sync_kintone':
                try {
                    $records =
                        kintone_records(
                            $settings['kintone']
                        );

                    $mapping =
                        $settings['kintone'][
                            'mapping'
                        ] ?? [];

                    $customers = [];

                    foreach (
                        ($records['records'] ?? [])
                        as $record
                    ) {
                        $addressParts = [];

                        foreach (
                            ($mapping['address']
                                ?? [])
                            as $code
                        ) {
                            $value =
                                record_value(
                                    $record,
                                    (string)$code
                                );

                            if ($value !== '') {
                                $addressParts[] =
                                    $value;
                            }
                        }

                        $customers[] = [
                            'id' =>
                                uuid('customer'),
                            'organization' =>
                                record_value(
                                    $record,
                                    (string)(
                                        $mapping[
                                            'organization'
                                        ] ?? ''
                                    )
                                ),
                            'name' =>
                                record_value(
                                    $record,
                                    (string)(
                                        $mapping[
                                            'name'
                                        ] ?? ''
                                    )
                                ),
                            'email' =>
                                record_value(
                                    $record,
                                    (string)(
                                        $mapping[
                                            'email'
                                        ] ?? ''
                                    )
                                ),
                            'department' =>
                                record_value(
                                    $record,
                                    (string)(
                                        $mapping[
                                            'department'
                                        ] ?? ''
                                    )
                                ),
                            'phone' =>
                                record_value(
                                    $record,
                                    (string)(
                                        $mapping[
                                            'phone'
                                        ] ?? ''
                                    )
                                ),
                            'address' =>
                                implode(
                                    ' ',
                                    $addressParts
                                ),
                        ];
                    }

                    $data['customers'] =
                        $customers;

                    $settings['kintone'][
                        'last_sync'
                    ] =
                        date('Y-m-d H:i:s');

                    save_data($data);
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
                        $e->getMessage()
                    );
                }

                return [
                    'screen' => 'kintone',
                ];


            /* -----------------------------------------
             * SMTP保存
             * ----------------------------------------- */

            case 'save_mail':
                $current =
                    $settings['mail'];

                $password =
                    post_string('password');

                if ($password === '') {
                    $password =
                        (string)(
                            $current['password']
                            ?? ''
                        );
                }

                $config = [
                    'host' =>
                        post_string('server'),
                    'port' =>
                        (int)post_string('port'),
                    'encryption' =>
                        post_string(
                            'encryption'
                        ),
                    'auth' =>
                        post_bool('auth'),
                    'username' =>
                        post_string('username'),
                    'password' =>
                        $password,
                    'from_email' =>
                        post_string(
                            'from_email'
                        ),
                    'from_name' =>
                        post_string(
                            'from_name'
                        ),
                    'reply_to' =>
                        post_string(
                            'reply_to'
                        ),
                    'last_test' =>
                        $current['last_test']
                        ?? null,
                ];

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


            /* -----------------------------------------
             * SMTP接続テスト
             * ----------------------------------------- */

            case 'test_mail':
                $current =
                    $settings['mail'];

                $password =
                    post_string('password');

                if ($password === '') {
                    $password =
                        (string)(
                            $current['password']
                            ?? ''
                        );
                }

                $config = [
                    'host' =>
                        post_string('server'),
                    'port' =>
                        (int)post_string('port'),
                    'encryption' =>
                        post_string(
                            'encryption'
                        ),
                    'auth' =>
                        post_bool('auth'),
                    'username' =>
                        post_string('username'),
                    'password' =>
                        $password,
                    'from_email' =>
                        post_string(
                            'from_email'
                        ),
                    'from_name' =>
                        post_string(
                            'from_name'
                        ),
                    'reply_to' =>
                        post_string(
                            'reply_to'
                        ),
                ];

                try {
                    smtp_test($config);

                    $settings['mail'] =
                        array_replace(
                            $settings['mail'],
                            $config
                        );

                    $settings['mail'][
                        'last_test'
                    ] =
                        date('Y-m-d H:i:s');

                    save_settings(
                        $settings
                    );

                    flash(
                        'success',
                        'SMTP接続・認証に成功しました。'
                    );
                } catch (Throwable $e) {
                    flash(
                        'error',
                        'SMTP接続失敗：' .
                        $e->getMessage()
                    );
                }

                return [
                    'screen' => 'mail',
                ];


            /* -----------------------------------------
             * テストメール
             * ----------------------------------------- */

            case 'send_test_mail':
                $to =
                    post_string('test_email');

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
                        'テストメール送信失敗：' .
                        $e->getMessage()
                    );
                }

                return [
                    'screen' => 'mail',
                ];


            /* -----------------------------------------
             * アンケートメール送信
             * ----------------------------------------- */

            case 'send_mail':
                $surveyId =
                    post_string('survey_id');

                $survey = survey_by_id(
                    $data['surveys'],
                    $surveyId
                );

                if ($survey === null) {
                    flash(
                        'error',
                        '対象アンケートが見つかりません。'
                    );

                    return [
                        'screen' => 'list',
                    ];
                }

                $selected =
                    $_POST['customer_ids']
                    ?? [];

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
                    (string)(
                        $_POST['body'] ?? ''
                    );

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

                $customerMap = [];

                foreach (
                    $data['customers']
                    as $customer
                ) {
                    $customerMap[
                        (string)$customer['id']
                    ] = $customer;
                }

                $sent = 0;
                $failed = 0;

                foreach (
                    $selected as $customerId
                ) {
                    $customer =
                        $customerMap[
                            (string)$customerId
                        ] ?? null;

                    if ($customer === null) {
                        $failed++;
                        continue;
                    }

                    $url =
                        public_answer_url(
                            $surveyId
                        );

                    $mailBody =
                        str_replace(
                            [
                                '{顧客名}',
                                '{アンケートURL}',
                            ],
                            [
                                (string)(
                                    $customer['name']
                                    ?? ''
                                ),
                                $url,
                            ],
                            $body
                        );

                    try {
                        smtp_send(
                            $settings['mail'],
                            (string)(
                                $customer['email']
                                ?? ''
                            ),
                            $subject,
                            $mailBody
                        );

                        $result =
                            '送信成功';

                        $sent++;
                    } catch (Throwable $e) {
                        $result =
                            '送信失敗';

                        $failed++;
                    }

                    $data['send_history'][] = [
                        'id' =>
                            uuid('send'),
                        'survey_id' =>
                            $surveyId,
                        'customer_id' =>
                            $customerId,
                        'customer_name' =>
                            (string)(
                                $customer['name']
                                ?? ''
                            ),
                        'type' =>
                            '一括送信',
                        'result' =>
                            $result,
                        'createdAt' =>
                            date('Y-m-d H:i:s'),
                    ];
                }

                save_data($data);

                flash(
                    $failed === 0
                        ? 'success'
                        : 'warning',
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

        return null;
    } catch (Throwable $e) {
        flash(
            'error',
            '処理に失敗しました：' .
            $e->getMessage()
        );

        return [
            'screen' =>
                get_string('screen') !== ''
                    ? get_string('screen')
                    : 'list',
            'id' =>
                post_string('survey_id'),
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
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",
 "Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
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
.brand{font-weight:700;font-size:18px}
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
 grid-template-columns:repeat(2,minmax(0,1fr));
}
.grid-3{
 grid-template-columns:repeat(3,minmax(0,1fr));
}
.form-group{margin-bottom:16px}
.form-group:last-child{margin-bottom:0}
label>span,.field-label{
 display:block;
 font-weight:600;
 margin-bottom:7px;
}
input,textarea,select{
 width:100%;
 border:1px solid #cbd5e1;
 border-radius:8px;
 padding:10px 12px;
 background:#fff;
 color:var(--text);
 font:inherit;
}
input:focus,textarea:focus,select:focus{
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
.btn:hover{transform:translateY(-1px)}
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
.btn-secondary{
 background:#fff;
 border-color:var(--border);
 color:var(--text);
}
.btn-secondary:hover{background:var(--gray-light)}
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
.table-wrap{
 overflow-x:auto;
}
table{
 width:100%;
 min-width:1000px;
 border-collapse:collapse;
}
th,td{
 padding:12px 10px;
 border-bottom:1px solid var(--border);
 text-align:left;
 vertical-align:middle;
}
th{
 background:#f8fafc;
 font-size:13px;
}
.actions{
 display:flex;
 flex-wrap:wrap;
 gap:6px;
}
.small{
 font-size:13px;
 color:var(--gray);
}
.help{
 color:var(--gray);
 font-size:13px;
 line-height:1.7;
}
.empty{
 padding:40px 20px;
 text-align:center;
 color:var(--gray);
}
.edit-toolbar{
 display:flex;
 flex-wrap:wrap;
 justify-content:space-between;
 align-items:center;
 gap:10px;
 margin-bottom:20px;
}
.group-card{
 border:1px solid var(--border);
 border-radius:10px;
 margin-bottom:18px;
 background:#fff;
}
.group-head{
 display:flex;
 align-items:center;
 gap:10px;
 padding:14px;
 background:#f8fafc;
 border-bottom:1px solid var(--border);
}
.drag-handle{
 cursor:grab;
 user-select:none;
 color:var(--gray);
 font-size:18px;
}
.question-card{
 margin:12px;
 padding:15px;
 border:1px solid var(--border);
 border-radius:9px;
 background:#fff;
}
.question-card.dragging,
.group-card.dragging{
 opacity:.45;
}
.question-top{
 display:grid;
 grid-template-columns:auto 1fr 180px auto;
 gap:10px;
 align-items:start;
}
.option-row{
 display:grid;
 grid-template-columns:1fr 250px auto;
 gap:8px;
 margin:7px 0;
}
.preview-question{
 padding:16px 0;
 border-bottom:1px solid var(--border);
}
.preview-question:last-child{
 border-bottom:0;
}
.answer-shell{
 width:min(860px,calc(100% - 28px));
 margin:0 auto;
 padding:28px 0 60px;
}
.answer-shell .card{box-shadow:0 2px 12px rgba(15,23,42,.06)}
.kintone-field-list{
 max-height:380px;
 overflow:auto;
 border:1px solid var(--border);
 border-radius:8px;
}
.field-item{
 padding:9px 12px;
 border-bottom:1px solid var(--border);
}
.field-item:last-child{border-bottom:0}
.status-line{
 display:flex;
 align-items:center;
 gap:10px;
 flex-wrap:wrap;
}
.spinner{
 width:16px;
 height:16px;
 border:2px solid #bfdbfe;
 border-top-color:var(--primary);
 border-radius:50%;
 animation:spin .7s linear infinite;
 display:none;
}
.loading .spinner{display:inline-block}
@keyframes spin{
 to{transform:rotate(360deg)}
}
@media(max-width:900px){
 .grid-2,.grid-3{
  grid-template-columns:1fr;
 }
 .question-top{
  grid-template-columns:auto 1fr;
 }
 .question-top>*:nth-child(3),
 .question-top>*:nth-child(4){
  grid-column:2;
 }
 .option-row{
  grid-template-columns:1fr;
 }
}
@media(max-width:640px){
 .container,
 .admin-header-inner{
  width:min(100% - 20px,1400px);
 }
 .page{padding-top:18px}
 .page-title{
  flex-direction:column;
 }
 .admin-header-inner{
  align-items:flex-start;
  flex-direction:column;
  padding:12px 0;
 }
 .nav{width:100%}
 .nav a{font-size:13px}
 .card-body{padding:15px}
 .button-row .btn{
  width:100%;
 }
 .answer-shell{
  width:min(100% - 20px,860px);
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
<script>
(function(){
 "use strict";

 function confirmAction(message){
   return window.confirm(message);
 }

 document.querySelectorAll(
   'form[data-confirm]'
 ).forEach(function(form){
   form.addEventListener('submit',function(e){
     var message =
       form.getAttribute('data-confirm') || '';
     if(message && !confirmAction(message)){
       e.preventDefault();
     }
   });
 });

 document.querySelectorAll(
   'form[data-loading]'
 ).forEach(function(form){
   form.addEventListener('submit',function(){
     form.classList.add('loading');
     form.querySelectorAll(
       'button,input[type=submit]'
     ).forEach(function(el){
       el.disabled = true;
     });
   });
 });

 /*
  * 質問・グループ追加。
  * 画面上で即時に追加できるようにし、
  * 保存時はhidden order情報をPOSTする。
  */
 var groupCounter = 0;
 var questionCounter = 0;

 function newId(prefix){
   if(window.crypto && crypto.randomUUID){
     return prefix + '-' +
       crypto.randomUUID().replace(/-/g,'');
   }
   return prefix + '-' +
     Date.now() + '-' +
     Math.random().toString(16).slice(2);
 }

 function renumber(){
   var numbering =
     document.querySelector(
       'select[name="numbering"]'
     );

   var global = 1;
   var groupNo = 1;

   document.querySelectorAll(
     '.group-card'
   ).forEach(function(group){
     var qno = 1;

     group.querySelectorAll(
       '.question-card'
     ).forEach(function(question){
       var target =
         question.querySelector(
           '.question-number'
         );

       if(!target) return;

       if(
         numbering &&
         numbering.value === 'group'
       ){
         target.textContent =
           'Q' + groupNo + '-' + qno;
       }else{
         target.textContent =
           'Q' + global;
       }

       global++;
       qno++;
     });

     groupNo++;
   });
 }

 function syncOrder(){
   document.querySelectorAll(
     '.group-card'
   ).forEach(function(group){
     var gid =
       group.getAttribute('data-group-id');

     var order =
       document.querySelector(
         'input[name="group_order[]"][value="' +
         CSS.escape(gid) + '"]'
       );

     if(!order){
       order = document.createElement('input');
       order.type='hidden';
       order.name='group_order[]';
       order.value=gid;
       order.dataset.dynamicOrder='1';
       document.querySelector(
         '#survey-editor'
       ).appendChild(order);
     }

     var ids =
       group.querySelectorAll(
         '.question-card'
       );

     var holder =
       group.querySelector(
         '.question-order-holder'
       );

     if(holder){
       holder.innerHTML='';

       ids.forEach(function(q){
         var input =
           document.createElement('input');
         input.type='hidden';
         input.name =
           'questions_by_group[' +
           gid + '][]';
         input.value =
           q.getAttribute(
             'data-question-id'
           );
         holder.appendChild(input);
       });
     }
   });
 }

 function makeQuestion(group){
   var gid =
     group.getAttribute('data-group-id');

   var qid = newId('question');

   var card =
     document.createElement('div');

   card.className='question-card';
   card.draggable=true;
   card.setAttribute(
     'data-question-id',
     qid
   );

   card.innerHTML =
     '<div class="question-top">' +
     '<span class="drag-handle">☷</span>' +
     '<div>' +
     '<div class="small">質問番号</div>' +
     '<strong class="question-number">Q?</strong>' +
     '</div>' +
     '<select name="question_type[' +
     qid +
     ']" class="question-type">' +
     '<option value="single">単一選択</option>' +
     '<option value="multiple">複数選択</option>' +
     '<option value="text">自由記述</option>' +
     '</select>' +
     '<button type="button" ' +
     'class="btn btn-danger remove-question">' +
     '削除</button>' +
     '</div>' +
     '<div class="form-group" style="margin-top:12px">' +
     '<label><span>質問文</span>' +
     '<textarea name="question_text[' +
     qid +
     ']" required></textarea>' +
     '</label>' +
     '</div>' +
     '<label class="check">' +
     '<input type="checkbox" ' +
     'name="question_required[' +
     qid +
     ']" value="1">' +
     '必須' +
     '</label>' +
     '<div class="options-area"></div>';

   var area =
     card.querySelector('.options-area');

   function renderOptions(){
     var type =
       card.querySelector(
         '.question-type'
       ).value;

     area.innerHTML='';

     if(type === 'text'){
       return;
     }

     var title =
       document.createElement('div');
     title.className='field-label';
     title.textContent='選択肢';
     area.appendChild(title);

     var list =
       document.createElement('div');
     list.className='option-list';
     area.appendChild(list);

     function addOption(){
       var index =
         list.children.length;

       var row =
         document.createElement('div');
       row.className='option-row';

       var next =
         type === 'single'
           ? '<select name="option_next[' +
             qid + '][]">' +
             '<option value="">次の質問を指定しない</option>' +
             '</select>'
           : '<div></div>';

       row.innerHTML =
         '<input type="text" ' +
         'name="question_option[' +
         qid + '][]" ' +
         'placeholder="選択肢">' +
         next +
         '<button type="button" ' +
         'class="btn btn-secondary remove-option">' +
         '削除</button>';

       list.appendChild(row);

       updateBranchTargets();
     }

     var add =
       document.createElement('button');
     add.type='button';
     add.className='btn btn-secondary';
     add.textContent='選択肢を追加';
     add.addEventListener(
       'click',
       addOption
     );

     area.appendChild(add);

     addOption();
     addOption();
   }

   card.querySelector(
     '.question-type'
   ).addEventListener(
     'change',
     renderOptions
   );

   card.addEventListener(
     'click',
     function(e){
       if(
         e.target.closest(
           '.remove-question'
         )
       ){
         if(
           window.confirm(
             'この質問を削除しますか？'
           )
         ){
           card.remove();
           renumber();
           syncOrder();
         }
       }

       if(
         e.target.closest(
           '.remove-option'
         )
       ){
         var row =
           e.target.closest(
             '.option-row'
           );
         if(row) row.remove();
       }
     }
   );

   setupQuestionDrag(card);

   group.querySelector(
     '.questions'
   ).appendChild(card);

   renderOptions();
   renumber();
   syncOrder();
 }

 function makeGroup(){
   var gid = newId('group');

   var group =
     document.createElement('div');

   group.className='group-card';
   group.draggable=true;
   group.setAttribute(
     'data-group-id',
     gid
   );

   group.innerHTML =
     '<div class="group-head">' +
     '<span class="drag-handle">☷</span>' +
     '<input type="text" ' +
     'name="group_title[' +
     gid +
     ']" ' +
     'value="新しいグループ">' +
     '<button type="button" ' +
     'class="btn btn-danger remove-group">' +
     'グループ削除</button>' +
     '</div>' +
     '<div class="card-body">' +
     '<div class="questions"></div>' +
     '<div class="question-order-holder"></div>' +
     '<button type="button" ' +
     'class="btn btn-secondary add-question">' +
     '＋ 質問を追加</button>' +
     '</div>';

   setupGroupDrag(group);

   group.querySelector(
     '.add-question'
   ).addEventListener(
     'click',
     function(){
       makeQuestion(group);
     }
   );

   group.querySelector(
     '.remove-group'
   ).addEventListener(
     'click',
     function(){
       if(
         window.confirm(
           'このグループを削除しますか？'
         )
       ){
         group.remove();
         renumber();
         syncOrder();
       }
     }
   );

   document.querySelector(
     '#groups'
   ).appendChild(group);

   makeQuestion(group);
   syncOrder();
 }

 function setupQuestionDrag(card){
   card.addEventListener(
     'dragstart',
     function(){
       card.classList.add('dragging');
     }
   );

   card.addEventListener(
     'dragend',
     function(){
       card.classList.remove('dragging');
       renumber();
       syncOrder();
     }
   );

   card.addEventListener(
     'dragover',
     function(e){
       e.preventDefault();

       var dragging =
         document.querySelector(
           '.question-card.dragging'
         );

       if(
         dragging &&
         dragging !== card
       ){
         var rect =
           card.getBoundingClientRect();

         var before =
           e.clientY <
           rect.top +
           rect.height / 2;

         var parent =
           card.parentNode;

         if(before){
           parent.insertBefore(
             dragging,
             card
           );
         }else{
           parent.insertBefore(
             dragging,
             card.nextSibling
           );
         }
       }
     }
   );
 }

 function setupGroupDrag(group){
   group.addEventListener(
     'dragstart',
     function(e){
       if(
         e.target.closest(
           '.question-card'
         )
       ){
         e.preventDefault();
         return;
       }

       group.classList.add('dragging');
     }
   );

   group.addEventListener(
     'dragend',
     function(){
       group.classList.remove('dragging');
       renumber();
       syncOrder();
     }
   );

   group.addEventListener(
     'dragover',
     function(e){
       e.preventDefault();

       var dragging =
         document.querySelector(
           '.group-card.dragging'
         );

       if(
         dragging &&
         dragging !== group
       ){
         var rect =
           group.getBoundingClientRect();

         var before =
           e.clientY <
           rect.top +
           rect.height / 2;

         var parent =
           group.parentNode;

         if(before){
           parent.insertBefore(
             dragging,
             group
           );
         }else{
           parent.insertBefore(
             dragging,
             group.nextSibling
           );
         }
       }
     }
   );
 }

 function updateBranchTargets(){
   var selects =
     document.querySelectorAll(
       'select[name^="option_next["]'
     );

   var options=[];

   document.querySelectorAll(
     '.question-card'
   ).forEach(function(q){
     var id =
       q.getAttribute(
         'data-question-id'
       );

     var number =
       q.querySelector(
         '.question-number'
       );

     options.push({
       id:id,
       label:number
         ? number.textContent
         : id
     });
   });

   selects.forEach(function(select){
     var current =
       select.value;

     select.innerHTML =
       '<option value="">' +
       '次の質問を指定しない' +
       '</option>';

     options.forEach(function(o){
       var option =
         document.createElement(
           'option'
         );
       option.value=o.id;
       option.textContent=o.label;

       if(o.id === current){
         option.selected=true;
       }

       select.appendChild(option);
     });
   });
 }

 var editor =
   document.querySelector(
     '#survey-editor'
   );

 if(editor){
   var addGroup =
     document.querySelector(
       '#add-group'
     );

   if(addGroup){
     addGroup.addEventListener(
       'click',
       makeGroup
     );
   }

   var numbering =
     document.querySelector(
       'select[name="numbering"]'
     );

   if(numbering){
     numbering.addEventListener(
       'change',
       function(){
         renumber();
         updateBranchTargets();
         syncOrder();
       }
     );
   }

   editor.addEventListener(
     'submit',
     function(){
       syncOrder();
     }
   );

   renumber();
   syncOrder();
   updateBranchTargets();
 }

 /*
  * 状態変更。
  * 保存フォームとは別POSTにする。
  */
 document.querySelectorAll(
   '.status-form'
 ).forEach(function(form){
   form.addEventListener(
     'submit',
     function(e){
       var select =
         form.querySelector(
           'select[name="next_status"]'
         );

       if(!select || !select.value){
         e.preventDefault();
         return;
       }

       var label =
         select.options[
           select.selectedIndex
         ].textContent;

       if(
         !window.confirm(
           '状態を「' +
           label +
           '」に変更しますか？'
         )
       ){
         e.preventDefault();
       }
     }
   );
 });

 /*
  * 外部通信中のボタン制御。
  */
 document.querySelectorAll(
   'form.external-action'
 ).forEach(function(form){
   form.addEventListener(
     'submit',
     function(){
       form.classList.add('loading');
     }
   );
 });

 /*
  * Enter検索
  */
 document.querySelectorAll(
   'form.search-form input'
 ).forEach(function(input){
   input.addEventListener(
     'keydown',
     function(e){
       if(e.key === 'Enter'){
         e.currentTarget.form.submit();
       }
     }
   );
 });
})();
</script>
</body>
</html>
<?php
}


/* =========================================================
 * Flash表示
 * ========================================================= */

function render_flash(): void
{
    $flash = consume_flash();

    if (!$flash) {
        return;
    }

    $class =
        $flash['type'] === 'success'
            ? 'alert-success'
            : (
                $flash['type'] === 'warning'
                    ? 'alert-warning'
                    : 'alert-error'
            );
?>
<div class="alert <?= h($class) ?>">
<?= h($flash['message']) ?>
</div>
<?php
}


/* =========================================================
 * 一覧
 * ========================================================= */

function render_list(array $data): void
{
    $search =
        get_string('q');

    $filter =
        get_string('filter');

    $sort =
        get_string('sort');

    if ($sort === '') {
        $sort = 'updated_desc';
    }

    $rows = [];

    foreach ($data['surveys'] as $survey) {
        if (
            $search !== ''
            && mb_stripos(
                (string)$survey['title'],
                $search
            ) === false
        ) {
            continue;
        }

        $status =
            (string)(
                $survey['status'] ?? 'draft'
            );

        if (
            $filter !== ''
            && $filter !== 'all'
            && $filter !== $status
        ) {
            continue;
        }

        $rows[] = $survey;
    }

    usort(
        $rows,
        static function(
            array $a,
            array $b
        ) use ($sort): int {
            switch ($sort) {
                case 'updated_asc':
                    return strcmp(
                        (string)$a['updatedAt'],
                        (string)$b['updatedAt']
                    );

                case 'answers_desc':
                    return 0;

                case 'answers_asc':
                    return 0;

                case 'start_desc':
                    return strcmp(
                        (string)$b['startAt'],
                        (string)$a['startAt']
                    );

                case 'start_asc':
                    return strcmp(
                        (string)$a['startAt'],
                        (string)$b['startAt']
                    );

                default:
                    return strcmp(
                        (string)$b['updatedAt'],
                        (string)$a['updatedAt']
                    );
            }
        }
    );

    render_head('アンケート一覧');
    render_flash();
?>
<div class="page">
<div class="container">

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
＋ 新規作成
</a>
</div>

<div class="card">
<div class="card-body">

<form class="search-form"
      method="get">

<input type="hidden"
       name="screen"
       value="list">

<div class="grid grid-3">

<label>
<span>タイトル検索</span>
<input type="text"
       name="q"
       value="<?= h($search) ?>"
       placeholder="タイトルを入力">
</label>

<label>
<span>ステータス</span>
<select name="filter">
<option value="all">すべて</option>
<option value="published"
<?= $filter==='published'
    ? 'selected'
    : '' ?>>公開中</option>
<option value="draft"
<?= $filter==='draft'
    ? 'selected'
    : '' ?>>下書き</option>
<option value="stopped"
<?= $filter==='stopped'
    ? 'selected'
    : '' ?>>停止</option>
<option value="ended"
<?= $filter==='ended'
    ? 'selected'
    : '' ?>>終了</option>
</select>
</label>

<label>
<span>ソート</span>
<select name="sort">
<option value="updated_desc"
<?= $sort==='updated_desc'
    ? 'selected'
    : '' ?>>更新日：新しい順</option>
<option value="updated_asc"
<?= $sort==='updated_asc'
    ? 'selected'
    : '' ?>>更新日：古い順</option>
<option value="start_desc"
<?= $sort==='start_desc'
    ? 'selected'
    : '' ?>>開始日：新しい順</option>
<option value="start_asc"
<?= $sort==='start_asc'
    ? 'selected'
    : '' ?>>開始日：古い順</option>
</select>
</label>

</div>

<div class="button-row"
     style="margin-top:14px">
<button class="btn btn-primary"
        type="submit">
検索・絞り込み
</button>
</div>

</form>

</div>
</div>

<div class="card">
<div class="card-body table-wrap">

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

<?php if (!$rows): ?>

<tr>
<td colspan="7">
<div class="empty">
該当するアンケートはありません。
</div>
</td>
</tr>

<?php else: ?>

<?php foreach ($rows as $survey): ?>

<?php
$status =
    (string)(
        $survey['status'] ?? 'draft'
    );

$answerCount = 0;
?>

<tr>

<td>
<strong>
<?= h($survey['title']) ?>
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
<span class="badge badge-<?= h(
    status_class($status)
) ?>">
<?= h(status_label($status)) ?>
</span>
</td>

<td>
<?= h($answerCount) ?>
</td>

<td>
<div class="actions">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>$survey['id']
   ])) ?>">
確認・編集
</a>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'analytics',
       'id'=>$survey['id']
   ])) ?>">
集計
</a>

<a class="btn btn-primary"
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

<button class="btn btn-secondary"
        type="submit">
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

<button class="btn btn-danger"
        type="submit">
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

</div>
</div>
<?php
render_footer();
}


/* =========================================================
 * 編集
 * ========================================================= */

function render_edit(array $survey): void
{
    recalc_numbers($survey);

    render_head(
        'アンケート作成・編集'
    );

    render_flash();
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1>
アンケート作成・編集
</h1>
<p>
質問・グループ・公開状態を管理します。
</p>
</div>
</div>

<div class="edit-toolbar">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'list'
   ])) ?>"
   onclick="return confirm('編集内容を破棄して一覧へ戻りますか？')">
キャンセル
</a>

<form id="survey-editor"
      method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<?php foreach (
    $survey['groups']
    as $group
): ?>

<input type="hidden"
       name="group_order[]"
       value="<?= h($group['id']) ?>">

<?php endforeach; ?>

<div class="button-row">
<button class="btn btn-primary"
        type="submit">
保存して一覧へ
</button>
</div>

</div>

<div class="card">
<div class="card-header">
<h2>基本情報</h2>
</div>
<div class="card-body">

<div class="grid grid-2">

<div>

<div class="form-group">
<label>
<span>アンケートタイトル</span>
<input type="text"
       name="title"
       value="<?= h($survey['title']) ?>"
       maxlength="<?= MAX_TITLE ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>アンケート説明</span>
<textarea name="description"
          maxlength="<?= MAX_DESCRIPTION ?>"><?= h($survey['description']) ?></textarea>
</label>
</div>

</div>

<div>

<div class="grid grid-2">

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
<span>質問番号の採番方式</span>
<select name="numbering">
<option value="global"
<?= ($survey['numbering'] ?? 'global')
    === 'global'
    ? 'selected'
    : '' ?>>
アンケート全体で通番：Q1、Q2、Q3...
</option>
<option value="group"
<?= ($survey['numbering'] ?? '')
    === 'group'
    ? 'selected'
    : '' ?>>
グループ毎：Q1-1、Q1-2、Q2-1...
</option>
</select>
</label>
</div>

</div>

</div>

</div>
</div>

<div class="card">
<div class="card-header">

<div style="
display:flex;
justify-content:space-between;
align-items:center;
gap:10px;
flex-wrap:wrap">

<h2>状態</h2>

<div class="status-line">

<span class="badge badge-<?= h(
    status_class(
        (string)$survey['status']
    )
) ?>">
<?= h(
    status_label(
        (string)$survey['status']
    )
) ?>
</span>

<?php
$status =
    (string)$survey['status'];
?>

<?php if ($status !== 'ended'): ?>

<form method="post"
      class="status-form">

<input type="hidden"
       name="action"
       value="change_status">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<select name="next_status">

<option value="">
状態を変更</option>

<?php if ($status === 'draft'): ?>
<option value="published">
公開中
</option>
<?php elseif ($status === 'published'): ?>
<option value="stopped">
停止
</option>
<?php elseif ($status === 'stopped'): ?>
<option value="published">
公開中
</option>
<?php endif; ?>

</select>

<button class="btn btn-secondary"
        type="submit">
変更
</button>

</form>

<?php else: ?>

<span class="small">
終了状態は変更できません。
</span>

<?php endif; ?>

</div>
</div>

</div>
</div>

<div id="groups">

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="group-card"
     draggable="true"
     data-group-id="<?= h($group['id']) ?>">

<div class="group-head">

<span class="drag-handle">
☷
</span>

<input type="text"
       name="group_title[<?= h($group['id']) ?>]"
       value="<?= h($group['title']) ?>">

<button type="button"
        class="btn btn-danger remove-group">
グループ削除
</button>

</div>

<div class="card-body">

<div class="questions">

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="question-card"
     draggable="true"
     data-question-id="<?= h($question['id']) ?>">

<div class="question-top">

<span class="drag-handle">
☷
</span>

<div>
<div class="small">
質問番号
</div>
<strong class="question-number">
<?= h($question['number']) ?>
</strong>
</div>

<select
 name="question_type[<?= h($question['id']) ?>]"
 class="question-type">

<option value="single"
<?= $question['type']==='single'
    ? 'selected'
    : '' ?>>
単一選択
</option>

<option value="multiple"
<?= $question['type']==='multiple'
    ? 'selected'
    : '' ?>>
複数選択
</option>

<option value="text"
<?= $question['type']==='text'
    ? 'selected'
    : '' ?>>
自由記述
</option>

</select>

<button type="button"
        class="btn btn-danger remove-question">
削除
</button>

</div>

<div class="form-group"
     style="margin-top:12px">

<label>
<span>質問文</span>

<textarea
 name="question_text[<?= h($question['id']) ?>]"
 required><?= h($question['text']) ?></textarea>

</label>

</div>

<label class="check">
<input type="checkbox"
 name="question_required[<?= h($question['id']) ?>]"
 value="1"
<?= !empty($question['required'])
    ? 'checked'
    : '' ?>>
必須
</label>

<?php if (
    $question['type'] !== 'text'
): ?>

<div style="margin-top:14px">

<div class="field-label">
選択肢
</div>

<?php foreach (
    $question['options']
    as $oi => $option
): ?>

<div class="option-row">

<input type="text"
 name="question_option[<?= h($question['id']) ?>][]"
 value="<?= h($option['label']) ?>">

<?php if (
    $question['type'] === 'single'
): ?>

<select
 name="option_next[<?= h($question['id']) ?>][]">

<option value="">
次の質問を指定しない
</option>

<?php foreach (
    $survey['groups']
    as $g2
): ?>

<?php foreach (
    $g2['questions']
    as $q2
): ?>

<?php if (
    $q2['id']
    !== $question['id']
): ?>

<option value="<?= h($q2['id']) ?>"
<?= ($option['nextQuestionId'] ?? '')
    === $q2['id']
    ? 'selected'
    : '' ?>>
<?= h($q2['number']) ?>
<?= h($q2['text']) ?>
</option>

<?php endif; ?>

<?php endforeach; ?>
<?php endforeach; ?>

</select>

<?php else: ?>

<div></div>

<?php endif; ?>

<button type="button"
        class="btn btn-secondary remove-option">
削除
</button>

</div>

<?php endforeach; ?>

</div>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<div class="question-order-holder">

<?php foreach (
    $group['questions']
    as $question
): ?>

<input type="hidden"
       name="questions_by_group[<?= h($group['id']) ?>][]"
       value="<?= h($question['id']) ?>">

<?php endforeach; ?>

</div>

<button type="button"
        class="btn btn-secondary add-question">
＋ 質問を追加
</button>

</div>
</div>

<?php endforeach; ?>

</div>

<div class="button-row"
     style="margin:18px 0 30px">

<button type="button"
        id="add-group"
        class="btn btn-secondary">
＋ グループを追加
</button>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'preview',
       'id'=>$survey['id']
   ])) ?>">
プレビュー
</a>

</div>

</form>

</div>
</div>
<?php
render_footer();
}


/* =========================================================
 * プレビュー
 * ========================================================= */

function render_preview(array $survey): void
{
    recalc_numbers($survey);

    render_head('プレビュー');
    render_flash();
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1>プレビュー</h1>
<p>
<?= h($survey['title']) ?>
</p>
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

<h2><?= h($survey['title']) ?></h2>

<p>
<?= nl2br(
    h($survey['description'])
) ?>
</p>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<h3><?= h($group['title']) ?></h3>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="preview-question">

<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<?php if (
    !empty($question['required'])
): ?>
<span class="badge badge-warning">
必須
</span>
<?php endif; ?>

<?php if (
    $question['type'] === 'text'
): ?>

<textarea disabled
          placeholder="自由記述"></textarea>

<?php elseif (
    $question['type'] === 'single'
): ?>

<?php foreach (
    $question['options']
    as $option
): ?>

<label class="check"
       style="margin:10px 0">
<input type="radio"
       disabled>
<?= h($option['label']) ?>
</label>

<?php endforeach; ?>

<?php else: ?>

<?php foreach (
    $question['options']
    as $option
): ?>

<label class="check"
       style="margin:10px 0">
<input type="checkbox"
       disabled>
<?= h($option['label']) ?>
</label>

<?php endforeach; ?>

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
 * kintone画面
 * ========================================================= */

function render_kintone(array $config): void
{
    render_head('kintone連携設定');
    render_flash();

    $fields =
        is_array($config['fields'] ?? null)
            ? $config['fields']
            : [];

    $mapping =
        is_array($config['mapping'] ?? null)
            ? $config['mapping']
            : [];

    $address =
        is_array($mapping['address'] ?? null)
            ? $mapping['address']
            : [];
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1>kintone連携設定</h1>
<p>
顧客管理アプリとの接続・項目取得・同期を行います。
</p>
</div>
</div>

<div class="card">
<div class="card-header">
<h2>接続設定</h2>
</div>
<div class="card-body">

<form method="post"
      class="external-action"
      data-loading>

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid grid-2">

<label>
<span>サブドメイン</span>
<input type="text"
       name="subdomain"
       value="<?= h(
           $config['subdomain'] ?? ''
       ) ?>"
       placeholder="xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com"
       required>
</label>

<label>
<span>顧客管理アプリID</span>
<input type="number"
       name="app_id"
       min="1"
       value="<?= h(
           $config['app_id'] ?? ''
       ) ?>"
       required>
</label>

<label>
<span>ログイン名</span>
<input type="text"
       name="username"
       value="<?= h(
           $config['username'] ?? ''
       ) ?>"
       required>
</label>

<label>
<span>パスワード</span>
<input type="password"
       name="password"
       value=""
       placeholder="変更しない場合は空欄">
</label>

<label>
<span>Proxy</span>
<input type="text"
       name="proxy"
       value="<?= h(
           $config['proxy'] ?? ''
       ) ?>"
       placeholder="host:port">
</label>

<label class="check"
       style="align-self:end">
<input type="checkbox"
       name="verify_ssl"
       value="1"
<?= !empty($config['verify_ssl'])
    ? 'checked'
    : '' ?>>
SSL証明書を検証する
</label>

</div>

<div class="button-row"
     style="margin-top:18px">

<button class="btn btn-primary"
        type="submit">
設定保存
</button>

</div>

</form>

<hr style="
border:0;
border-top:1px solid var(--border);
margin:22px 0">

<form method="post"
      class="external-action"
      data-loading>

<input type="hidden"
       name="action"
       value="test_kintone">

<input type="hidden"
       name="subdomain"
       value="<?= h(
           $config['subdomain'] ?? ''
       ) ?>">

<input type="hidden"
       name="app_id"
       value="<?= h(
           $config['app_id'] ?? ''
       ) ?>">

<input type="hidden"
       name="username"
       value="<?= h(
           $config['username'] ?? ''
       ) ?>">

<input type="hidden"
       name="password"
       value="">

<input type="hidden"
       name="proxy"
       value="<?= h(
           $config['proxy'] ?? ''
       ) ?>">

<input type="hidden"
       name="verify_ssl"
       value="<?= !empty(
           $config['verify_ssl']
       ) ? '1' : '0' ?>">

<button class="btn btn-success"
        type="submit">
<span class="spinner"></span>
接続テスト
</button>

<span class="small">
実際のkintoneへ接続して認証まで確認します。
</span>

</form>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>項目一覧</h2>
</div>
<div class="card-body">

<form method="post"
      class="external-action"
      data-loading>

<input type="hidden"
       name="action"
       value="fetch_kintone_fields">

<input type="hidden"
       name="subdomain"
       value="<?= h(
           $config['subdomain'] ?? ''
       ) ?>">

<input type="hidden"
       name="app_id"
       value="<?= h(
           $config['app_id'] ?? ''
       ) ?>">

<input type="hidden"
       name="username"
       value="<?= h(
           $config['username'] ?? ''
       ) ?>">

<input type="hidden"
       name="password"
       value="">

<input type="hidden"
       name="proxy"
       value="<?= h(
           $config['proxy'] ?? ''
       ) ?>">

<input type="hidden"
       name="verify_ssl"
       value="<?= !empty(
           $config['verify_ssl']
       ) ? '1' : '0' ?>">

<button class="btn btn-secondary"
        type="submit">
項目一覧を再取得
</button>

</form>

<?php if ($fields): ?>

<div class="kintone-field-list"
     style="margin-top:16px">

<?php foreach (
    $fields
    as $field
): ?>

<div class="field-item">
<strong>
<?= h($field['label']) ?>
</strong>

<span class="small">
コード：
<?= h($field['code']) ?>
/
型：
<?= h($field['type']) ?>
</span>
</div>

<?php endforeach; ?>

</div>

<?php else: ?>

<div class="empty">
まだkintone項目を取得していません。
</div>

<?php endif; ?>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>顧客情報マッピング</h2>
</div>
<div class="card-body">

<?php if (!$fields): ?>

<div class="alert alert-warning">
先に「項目一覧を再取得」を実行してください。
</div>

<?php else: ?>

<form method="post">

<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<div class="grid grid-2">

<?php
$mappingFields = [
    'organization' => '組織名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署名',
    'phone' => '電話番号',
];
?>

<?php foreach (
    $mappingFields
    as $key => $label
): ?>

<label>
<span><?= h($label) ?></span>

<select name="mapping_<?= h($key) ?>">

<option value="">
-- 未設定 --
</option>

<?php foreach (
    $fields
    as $field
): ?>

<option value="<?= h(
    $field['code']
) ?>"
<?= (
    (string)(
        $mapping[$key] ?? ''
    )
    === (string)$field['code']
)
    ? 'selected'
    : '' ?>>
<?= h($field['label']) ?>
（<?= h($field['code']) ?>）
</option>

<?php endforeach; ?>

</select>
</label>

<?php endforeach; ?>

</div>

<div class="form-group"
     style="margin-top:18px">

<div class="field-label">
住所
</div>

<div class="help">
住所は都道府県・市区町村・番地等を複数項目から組み合わせられます。
</div>

<?php foreach (
    $fields
    as $field
): ?>

<label class="check"
       style="margin:9px 0">

<input type="checkbox"
       name="mapping_address[]"
       value="<?= h(
           $field['code']
       ) ?>"
<?= in_array(
    (string)$field['code'],
    $address,
    true
)
    ? 'checked'
    : '' ?>>

<?= h($field['label']) ?>
（<?= h($field['code']) ?>）

</label>

<?php endforeach; ?>

</div>

<div class="button-row">
<button class="btn btn-primary"
        type="submit">
マッピングを保存
</button>
</div>

</form>

<?php endif; ?>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>顧客情報同期</h2>
</div>
<div class="card-body">

<form method="post"
      class="external-action"
      data-loading>

<input type="hidden"
       name="action"
       value="sync_kintone">

<button class="btn btn-primary"
        type="submit">
<span class="spinner"></span>
顧客情報を同期
</button>

</form>

<?php if (
    !empty($config['last_sync'])
): ?>

<p class="small">
最終同期：
<?= h($config['last_sync']) ?>
</p>

<?php endif; ?>

</div>
</div>

</div>
</div>
<?php
render_footer();
}


/* =========================================================
 * メール設定
 * ========================================================= */

function render_mail(array $config): void
{
    render_head('メールサーバ設定');
    render_flash();

    $state =
        !empty($config['last_test'])
            ? '接続確認済み'
            : '未設定';
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1>メールサーバ設定</h1>
<p>SMTPサーバの設定と接続テストを行います。</p>
</div>
</div>

<div class="card">
<div class="card-header">
<h2>
接続状態：
<?= h($state) ?>
</h2>
</div>

<div class="card-body">

<form method="post"
      class="external-action"
      data-loading>

<input type="hidden"
       name="action"
       value="save_mail">

<div class="grid grid-2">

<label>
<span>SMTPサーバ</span>
<input type="text"
       name="server"
       value="<?= h(
           $config['host'] ?? ''
       ) ?>"
       required>
</label>

<label>
<span>SMTPポート</span>
<input type="number"
       name="port"
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
<option value="ssl"
<?= ($config['encryption'] ?? '')
    === 'ssl'
    ? 'selected'
    : '' ?>>
SSL
</option>
<option value="tls"
<?= ($config['encryption'] ?? '')
    === 'tls'
    ? 'selected'
    : '' ?>>
TLS
</option>
<option value="none"
<?= ($config['encryption'] ?? '')
    === 'none'
    ? 'selected'
    : '' ?>>
なし
</option>
</select>
</label>

<label class="check"
       style="align-self:end">
<input type="checkbox"
       name="auth"
       value="1"
<?= !empty($config['auth'])
    ? 'checked'
    : '' ?>>
SMTP認証
</label>

<label>
<span>SMTPユーザー名</span>
<input type="text"
       name="username"
       value="<?= h(
           $config['username'] ?? ''
       ) ?>">
</label>

<label>
<span>SMTPパスワード</span>
<input type="password"
       name="password"
       value=""
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
<input type="text"
       name="from_name"
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

<button class="btn btn-primary"
        type="submit">
設定保存
</button>

</div>

</form>

<hr style="
border:0;
border-top:1px solid var(--border);
margin:24px 0">

<form method="post"
      class="external-action"
      data-loading>

<input type="hidden"
       name="action"
       value="test_mail">

<input type="hidden"
       name="server"
       value="<?= h(
           $config['host'] ?? ''
       ) ?>">

<input type="hidden"
       name="port"
       value="<?= h(
           $config['port'] ?? 587
       ) ?>">

<input type="hidden"
       name="encryption"
       value="<?= h(
           $config['encryption'] ?? 'tls'
       ) ?>">

<input type="hidden"
       name="auth"
       value="<?= !empty(
           $config['auth']
       ) ? '1' : '0' ?>">

<input type="hidden"
       name="username"
       value="<?= h(
           $config['username'] ?? ''
       ) ?>">

<input type="hidden"
       name="password"
       value="">

<input type="hidden"
       name="from_email"
       value="<?= h(
           $config['from_email'] ?? ''
       ) ?>">

<input type="hidden"
       name="from_name"
       value="<?= h(
           $config['from_name'] ?? ''
       ) ?>">

<input type="hidden"
       name="reply_to"
       value="<?= h(
           $config['reply_to'] ?? ''
       ) ?>">

<button class="btn btn-success"
        type="submit">
<span class="spinner"></span>
接続テスト
</button>

</form>

<?php if (
    !empty($config['last_test'])
): ?>

<p class="small">
最終接続確認：
<?= h($config['last_test']) ?>
</p>

<?php endif; ?>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>テストメール送信</h2>
</div>

<div class="card-body">

<form method="post"
      class="external-action"
      data-loading>

<input type="hidden"
       name="action"
       value="send_test_mail">

<label>
<span>テスト送信先</span>
<input type="email"
       name="test_email"
       required>
</label>

<div class="button-row"
     style="margin-top:14px">

<button class="btn btn-primary"
        type="submit">
<span class="spinner"></span>
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
 * 送信
 * ========================================================= */

function render_send(
    array $survey,
    array $customers,
    array $history
): void {
    render_head('顧客選択・メール送信');
    render_flash();
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1>顧客選択・メール送信</h1>
<p>
対象アンケート：
<strong>
<?= h($survey['title']) ?>
</strong>
</p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'list'
   ])) ?>">
一覧へ戻る
</a>
</div>

<div class="card">
<div class="card-header">
<h2>顧客選択・メール作成</h2>
</div>

<div class="card-body">

<form method="post"
      data-confirm="選択した顧客へメールを送信します。よろしいですか？"
      data-loading>

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="form-group">
<div class="field-label">
顧客
</div>

<div style="
max-height:320px;
overflow:auto;
border:1px solid var(--border);
border-radius:8px">

<?php if (!$customers): ?>

<div class="empty">
顧客データがありません。
先にkintoneから同期してください。
</div>

<?php else: ?>

<?php foreach (
    $customers
    as $customer
): ?>

<label class="check"
       style="
       padding:10px 12px;
       border-bottom:1px solid var(--border)">

<input type="checkbox"
       name="customer_ids[]"
       value="<?= h(
           $customer['id']
       ) ?>">

<span>
<?= h($customer['name'] ?? '') ?>
<?php if (
    !empty($customer['email'])
): ?>
（<?= h($customer['email']) ?>）
<?php endif; ?>
</span>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>
</div>

<div class="grid grid-2">

<label>
<span>メール件名</span>
<input type="text"
       name="subject"
       value="<?= h(
           '「' .
           $survey['title'] .
           '」のご回答をお願いします'
       ) ?>"
       required>
</label>

<div class="help">
メール変数：
<br>
<code>{顧客名}</code>
<br>
<code>{アンケートURL}</code>
</div>

</div>

<div class="form-group">
<label>
<span>本文</span>
<textarea name="body"
          required> {顧客名} 様

以下のアンケートへのご回答をお願いします。

{アンケートURL}

よろしくお願いいたします。</textarea>
</label>
</div>

<button class="btn btn-primary"
        type="submit">
<span class="spinner"></span>
一括送信
</button>

</form>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>送信履歴</h2>
</div>

<div class="card-body table-wrap">

<?php if (!$history): ?>

<div class="empty">
送信履歴はありません。
</div>

<?php else: ?>

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

<?php foreach (
    $history
    as $item
): ?>

<tr>
<td>
<?= h($item['createdAt'] ?? '') ?>
</td>
<td>
<?= h(
    $item['customer_name'] ?? ''
) ?>
</td>
<td>
<?= h($item['type'] ?? '') ?>
</td>
<td>
<?= h($item['result'] ?? '') ?>
</td>
</tr>

<?php endforeach; ?>

</tbody>
</table>

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
    array $survey,
    array $answers,
    array $customers
): void {
    $surveyAnswers =
        array_values(
            array_filter(
                $answers,
                static function(
                    array $answer
                ) use ($survey): bool {
                    return (
                        ($answer['survey_id'] ?? '')
                        === $survey['id']
                    );
                }
            )
        );

    $answerCount =
        count($surveyAnswers);

    render_head('回答集計・分析');
    render_flash();
?>
<div class="page">
<div class="container">

<div class="page-title">
<div>
<h1>回答集計・分析</h1>
<p>
対象アンケート：
<strong>
<?= h($survey['title']) ?>
</strong>
</p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'list'
   ])) ?>">
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
<h2><?= h(
    max(
        0,
        count($customers)
        - $answerCount
    )
) ?></h2>
</div>
</div>

</div>

<?php if ($answerCount === 0): ?>

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

<?php foreach (
    $survey['groups']
    as $group
): ?>

<h3><?= h($group['title']) ?></h3>

<?php foreach (
    $group['questions']
    as $question
): ?>

<?php
$counts = [];

foreach (
    $question['options']
    ?? []
    as $option
) {
    $counts[
        $option['label']
    ] = 0;
}

foreach (
    $surveyAnswers
    as $answer
) {
    $value =
        $answer['answers'][
            $question['id']
        ] ?? '';

    $values =
        is_array($value)
            ? $value
            : [$value];

    foreach ($values as $value) {
        $value = (string)$value;

        if (isset($counts[$value])) {
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

<?php if (
    $question['type'] === 'text'
): ?>

<p class="help">
自由記述
</p>

<?php else: ?>

<?php foreach (
    $counts
    as $label => $count
): ?>

<div style="margin:10px 0">

<div style="
display:flex;
justify-content:space-between">

<span><?= h($label) ?></span>
<strong><?= h($count) ?></strong>

</div>

<div style="
height:8px;
background:#e2e8f0;
border-radius:999px">

<div style="
height:100%;
width:<?= $answerCount > 0
    ? min(
        100,
        ($count / $answerCount) * 100
    )
    : 0 ?>%;
background:var(--primary);
border-radius:999px">
</div>

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

<?php foreach (
    $surveyAnswers
    as $index => $answer
): ?>

<div class="preview-question">

<strong>
回答 <?= h($index + 1) ?>
</strong>

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
    $answer['answers'][
        $question['id']
    ] ?? '';

if (is_array($value)) {
    $value =
        implode(
            ', ',
            array_map(
                'strval',
                $value
            )
        );
}
?>

<p>
<strong>
<?= h($question['number']) ?>
</strong>
<?= h((string)$value) ?>
</p>

<?php endforeach; ?>
<?php endforeach; ?>

</div>

<?php endforeach; ?>

</div>
</div>

<?php endif; ?>

</div>
</div>
<?php
render_footer();
}


/* =========================================================
 * 回答
 * ========================================================= */

function render_answer(array $survey): void
{
    recalc_numbers($survey);

    $draft =
        $_SESSION['answer_draft']
        ?? [];

    render_head(
        'アンケート回答',
        false
    );
?>
<div class="answer-shell">

<div class="page-title">
<div>
<h1><?= h($survey['title']) ?></h1>
<p>
<?= nl2br(
    h($survey['description'])
) ?>
</p>
</div>
</div>

<form method="post">

<input type="hidden"
       name="action"
       value="answer_next">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="card">
<div class="card-body">

<h2><?= h($group['title']) ?></h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="form-group">

<div class="field-label">

<?= h($question['number']) ?>

<?= h($question['text']) ?>

<?php if (
    !empty($question['required'])
): ?>

<span class="badge badge-warning">
必須
</span>

<?php endif; ?>

</div>

<?php
$value =
    $draft[
        $question['id']
    ] ?? '';
?>

<?php if (
    $question['type'] === 'text'
): ?>

<textarea
 name="answer[<?= h($question['id']) ?>]"><?= h(
    is_scalar($value)
        ? (string)$value
        : ''
) ?></textarea>

<?php elseif (
    $question['type'] === 'single'
): ?>

<?php foreach (
    $question['options']
    as $option
): ?>

<label class="check"
       style="
       padding:12px;
       border:1px solid var(--border);
       border-radius:8px;
       margin:8px 0">

<input type="radio"
       name="answer[<?= h($question['id']) ?>]"
       value="<?= h($option['label']) ?>"
<?= (
    (string)$value
    === (string)$option['label']
)
    ? 'checked'
    : '' ?>>

<?= h($option['label']) ?>

</label>

<?php endforeach; ?>

<?php else: ?>

<?php
$selected =
    is_array($value)
        ? $value
        : [];
?>

<?php foreach (
    $question['options']
    as $option
): ?>

<label class="check"
       style="
       padding:12px;
       border:1px solid var(--border);
       border-radius:8px;
       margin:8px 0">

<input type="checkbox"
       name="answer[<?= h($question['id']) ?>][]"
       value="<?= h($option['label']) ?>"
<?= in_array(
    (string)$option['label'],
    $selected,
    true
)
    ? 'checked'
    : '' ?>>

<?= h($option['label']) ?>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>
</div>

<?php endforeach; ?>

<div class="button-row">

<button class="btn btn-primary"
        type="submit">
回答を確認する
</button>

</div>

</form>

</div>
<?php
render_footer();
}


/* =========================================================
 * 確認
 * ========================================================= */

function render_confirm(
    array $survey
): void {
    $draft =
        $_SESSION['answer_draft']
        ?? [];

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

<?php foreach (
    $survey['groups']
    as $group
): ?>

<h2><?= h($group['title']) ?></h2>

<?php foreach (
    $group['questions']
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
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<p>
<?= nl2br(h($display)) ?>
</p>

</div>

<?php endforeach; ?>
<?php endforeach; ?>

<div class="button-row"
     style="margin-top:20px">

<form method="post">

<input type="hidden"
       name="action"
       value="answer_back">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<button class="btn btn-secondary"
        type="submit">
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
       value="<?= h($survey['id']) ?>">

<button class="btn btn-primary"
        type="submit">
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
 * メイン
 *
 * ここが今回の再発防止ポイント。
 *
 * POST → Location → GET
 * ではなく
 *
 * POST → 結果を保存 → 対象画面を直接render
 *
 * とする。
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
     * POST処理後の最新状態を使用。
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
        $screen =
            get_string('screen');

        if ($screen === '') {
            $screen = 'list';
        }

        $id =
            get_string('id');
    }

    /*
     * -----------------------------------------
     * 回答者画面
     * -----------------------------------------
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
     * -----------------------------------------
     * 管理者画面
     * -----------------------------------------
     */

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
                    'groups' => [[
                        'id' =>
                            uuid('group'),
                        'title' =>
                            '基本アンケート',
                        'questions' => [],
                    ]],
                ];

                render_edit($survey);
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
                    $data['surveys']
                );

                break;
            }

            render_edit($survey);
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
                    $data['surveys']
                );

                break;
            }

            render_preview($survey);
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
                    '対象アンケートが見つかりません。'
                );

                render_list(
                    $data['surveys']
                );

                break;
            }

            $history =
                array_values(
                    array_filter(
                        $data['send_history'],
                        static function(
                            array $item
                        ) use ($survey): bool {
                            return (
                                ($item['survey_id']
                                    ?? '')
                                === $survey['id']
                            );
                        }
                    )
                );

            usort(
                $history,
                static function(
                    array $a,
                    array $b
                ): int {
                    return strcmp(
                        (string)(
                            $b['createdAt']
                            ?? ''
                        ),
                        (string)(
                            $a['createdAt']
                            ?? ''
                        )
                    );
                }
            );

            render_send(
                $survey,
                $data['customers'],
                $history
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
                    '対象アンケートが見つかりません。'
                );

                render_list(
                    $data['surveys']
                );

                break;
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
                $data
            );

            break;
    }

} catch (Throwable $e) {

    /*
     * 白画面にしない。
     * 認証情報・スタックトレースは表示しない。
     */
    http_response_code(500);

    render_head(
        'システムエラー'
    );
?>
<div class="page">
<div class="container">

<div class="alert alert-error">
システムエラーが発生しました。
</div>

<div class="card">
<div class="card-body">

<p>
アプリケーションの処理を完了できませんでした。
</p>

<p class="help">
データ保存先の権限、PHPバージョン、
外部サービス設定、ネットワーク設定を確認してください。
</p>

</div>
</div>

</div>
</div>
<?php
    render_footer();
}
?>

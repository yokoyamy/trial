<?php
declare(strict_types=1);

/*
 * ============================================================
 * アンケートアプリ
 * Apache 2.4 / PHP 8.5
 *
 * 単一エントリーポイント: index.php
 *
 * DB             : 使用しない
 * PHP cURL       : 使用しない
 * PHP mail()     : 使用しない
 * Canvas         : 使用しない
 * 管理者認証     : 実装しない
 * CSRF           : 要件にないため実装しない
 * ============================================================
 */

date_default_timezone_set('Asia/Tokyo');

$APP_DIR  = __DIR__;
$DATA_DIR = $APP_DIR . DIRECTORY_SEPARATOR . 'data';

if (!is_dir($DATA_DIR)) {
    if (!@mkdir($DATA_DIR, 0770, true) && !is_dir($DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

/*
 * セッション
 *
 * 認証には使用しない。
 * 回答途中データ等の一時状態保持に使用する。
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    );

    $path = dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    if ($path === '.' || $path === '') {
        $path = '/';
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $path,
        'secure'   => $https,
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
 * ============================================================
 */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function now_text(): string
{
    return date('Y-m-d H:i:s');
}

function uid(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(8));
}

function redirect_to(string $screen, array $params = []): never
{
    $query = ['screen' => $screen];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $query[$key] = $value;
    }

    header(
        'Location: index.php?' . http_build_query($query),
        true,
        303
    );
    exit;
}

function valid_id(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id);
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

function flash_get(): ?array
{
    $v = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($v) ? $v : null;
}

function post_string(string $name, string $default = ''): string
{
    $v = $_POST[$name] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function post_bool(string $name): bool
{
    return isset($_POST[$name]) && (string)$_POST[$name] === '1';
}

function post_array(string $name): array
{
    $v = $_POST[$name] ?? [];

    if (!is_array($v)) {
        return [];
    }

    $out = [];

    foreach ($v as $item) {
        if (is_string($item)) {
            $out[] = trim($item);
        }
    }

    return $out;
}

/* ============================================================
 * ファイル永続化
 * ============================================================
 */

function data_path(string $name): string
{
    global $DATA_DIR;

    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
        throw new RuntimeException('不正なデータ名です。');
    }

    return $DATA_DIR . DIRECTORY_SEPARATOR . $name . '.php';
}

function read_data(string $name, array $default = []): array
{
    $file = data_path($name);

    if (!is_file($file)) {
        return $default;
    }

    $raw = @file_get_contents($file);

    if ($raw === false) {
        return $default;
    }

    $marker = "__PAYLOAD__\n";
    $pos = strpos($raw, $marker);

    if ($pos === false) {
        return $default;
    }

    $json = substr(
        $raw,
        $pos + strlen($marker)
    );

    $data = json_decode($json, true);

    return is_array($data) ? $data : $default;
}

function write_data(string $name, array $data): bool
{
    $file = data_path($name);
    $tmp  = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $content =
        "<?php http_response_code(404); exit; ?>\n" .
        "__PAYLOAD__\n" .
        $json;

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

        $written = fwrite($fp, $content);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($written === false || $written < strlen($content)) {
            @unlink($tmp);
            return false;
        }

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

/* ============================================================
 * データロード
 * ============================================================
 */

function surveys(): array
{
    return read_data('surveys', []);
}

function save_surveys(array $data): void
{
    if (!write_data('surveys', $data)) {
        throw new RuntimeException(
            'アンケートデータを保存できませんでした。'
        );
    }
}

function answers(): array
{
    return read_data('answers', []);
}

function save_answers(array $data): void
{
    if (!write_data('answers', $data)) {
        throw new RuntimeException(
            '回答データを保存できませんでした。'
        );
    }
}

function customers(): array
{
    return read_data('customers', []);
}

function save_customers(array $data): void
{
    if (!write_data('customers', $data)) {
        throw new RuntimeException(
            '顧客データを保存できませんでした。'
        );
    }
}

function send_history(): array
{
    return read_data('send_history', []);
}

function save_send_history(array $data): void
{
    if (!write_data('send_history', $data)) {
        throw new RuntimeException(
            '送信履歴を保存できませんでした。'
        );
    }
}

function default_settings(): array
{
    return [
        'kintone' => [
            'subdomain'        => '',
            'app_id'           => '',
            'username'         => '',
            'password'         => '',
            'proxy'            => '',
            'verify_ssl'       => false,
            'connection_status'=> '未設定',
            'fields'           => [],
            'mapping'          => [
                'organization' => [],
                'name'         => '',
                'email'        => '',
                'department'   => '',
                'phone'        => '',
                'address'      => [],
            ],
        ],
        'mail' => [
            'server'           => '',
            'port'             => 587,
            'encryption'       => 'tls',
            'auth'             => true,
            'username'         => '',
            'password'         => '',
            'from_email'       => '',
            'from_name'        => '',
            'reply_to'         => '',
            'connection_status'=> '未設定',
        ],
    ];
}

function settings(): array
{
    $saved = read_data('settings', []);
    $base  = default_settings();

    if (isset($saved['kintone']) && is_array($saved['kintone'])) {
        $base['kintone'] = array_replace(
            $base['kintone'],
            $saved['kintone']
        );
    }

    if (isset($saved['mail']) && is_array($saved['mail'])) {
        $base['mail'] = array_replace(
            $base['mail'],
            $saved['mail']
        );
    }

    if (!isset($base['kintone']['mapping']) || !is_array($base['kintone']['mapping'])) {
        $base['kintone']['mapping'] = default_settings()['kintone']['mapping'];
    }

    return $base;
}

function save_settings(array $data): void
{
    if (!write_data('settings', $data)) {
        throw new RuntimeException(
            '設定を保存できませんでした。'
        );
    }
}

/* ============================================================
 * アンケート構造
 * ============================================================
 */

function new_choice(string $text): array
{
    return [
        'id'   => uid('choice'),
        'text' => $text,
    ];
}

function new_question(): array
{
    return [
        'id'       => uid('question'),
        'number'   => '',
        'text'     => '',
        'type'     => 'single',
        'required' => false,
        'choices'  => [
            new_choice('選択肢1'),
            new_choice('選択肢2'),
        ],
        'branch'   => [],
    ];
}

function new_group(): array
{
    return [
        'id'        => uid('group'),
        'title'     => '新しいグループ',
        'questions' => [],
    ];
}

function new_survey(): array
{
    $group = new_group();
    $group['title'] = '基本情報';
    $group['questions'][] = new_question();

    $survey = [
        'id'          => uid('survey'),
        'title'       => '新しいアンケート',
        'description' => '',
        'startAt'     => date('Y-m-d\TH:i'),
        'endAt'       => date('Y-m-d\TH:i', strtotime('+30 days')),
        'numbering'   => 'global',
        'status'      => 'draft',
        'createdAt'   => now_text(),
        'updatedAt'   => now_text(),
        'groups'      => [$group],
    ];

    renumber($survey);

    return $survey;
}

function find_survey(string $id): ?array
{
    foreach (surveys() as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function renumber(array &$survey): void
{
    $mode = $survey['numbering'] ?? 'global';

    if ($mode === 'group') {
        $gno = 1;

        foreach ($survey['groups'] as &$group) {
            $qno = 1;

            foreach ($group['questions'] as &$question) {
                $question['number'] = 'Q' . $gno . '-' . $qno;
                $qno++;
            }

            unset($question);
            $gno++;
        }

        unset($group);
        return;
    }

    $n = 1;

    foreach ($survey['groups'] as &$group) {
        foreach ($group['questions'] as &$question) {
            $question['number'] = 'Q' . $n;
            $n++;
        }

        unset($question);
    }

    unset($group);
}

function normalize_survey(array $survey): array
{
    $survey['title'] = trim((string)($survey['title'] ?? ''));
    $survey['description'] = trim((string)($survey['description'] ?? ''));

    $survey['numbering'] =
        (($survey['numbering'] ?? 'global') === 'group')
            ? 'group'
            : 'global';

    if (!isset($survey['groups']) || !is_array($survey['groups'])) {
        $survey['groups'] = [];
    }

    foreach ($survey['groups'] as &$group) {
        $group['id'] = (string)($group['id'] ?? uid('group'));
        $group['title'] = trim((string)($group['title'] ?? ''));

        if ($group['title'] === '') {
            $group['title'] = 'グループ';
        }

        if (!isset($group['questions']) || !is_array($group['questions'])) {
            $group['questions'] = [];
        }

        foreach ($group['questions'] as &$question) {
            $question['id'] =
                (string)($question['id'] ?? uid('question'));

            $type = (string)($question['type'] ?? 'single');

            if (!in_array(
                $type,
                ['single', 'multiple', 'text'],
                true
            )) {
                $type = 'single';
            }

            $question['type'] = $type;
            $question['text'] =
                trim((string)($question['text'] ?? ''));
            $question['required'] =
                !empty($question['required']);

            if (!isset($question['choices']) || !is_array($question['choices'])) {
                $question['choices'] = [];
            }

            if ($type === 'text') {
                $question['choices'] = [];
            } else {
                foreach ($question['choices'] as &$choice) {
                    $choice['id'] =
                        (string)($choice['id'] ?? uid('choice'));
                    $choice['text'] =
                        trim((string)($choice['text'] ?? ''));
                }

                unset($choice);
            }

            if (!isset($question['branch']) || !is_array($question['branch'])) {
                $question['branch'] = [];
            }
        }

        unset($question);
    }

    unset($group);

    renumber($survey);

    return $survey;
}

/* ============================================================
 * 状態
 * ============================================================
 */

function apply_auto_status(array $survey): array
{
    if (
        ($survey['status'] ?? '') === 'published'
        && !empty($survey['endAt'])
    ) {
        $end = strtotime((string)$survey['endAt']);

        if ($end !== false && $end < time()) {
            $survey['status'] = 'ended';
        }
    }

    return $survey;
}

function status_label(string $status): string
{
    switch ($status) {
        case 'draft':
            return '下書き';
        case 'published':
            return '公開中';
        case 'stopped':
            return '停止';
        case 'ended':
            return '終了';
        default:
            return '不明';
    }
}

function status_class(string $status): string
{
    switch ($status) {
        case 'published':
            return 'success';
        case 'stopped':
            return 'warning';
        case 'ended':
            return 'danger';
        default:
            return 'gray';
    }
}

function status_transition_allowed(
    string $from,
    string $to
): bool {
    if ($from === 'draft' && $to === 'published') {
        return true;
    }

    if ($from === 'published' && $to === 'stopped') {
        return true;
    }

    if ($from === 'stopped' && $to === 'published') {
        return true;
    }

    return false;
}

/* ============================================================
 * kintone
 *
 * PHP cURLは使用しない。
 * X-Cybozu-Authorizationはサーバー側のみ。
 * ============================================================
 */

function normalize_kintone_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    $value = trim($value, "/ \t\r\n");

    if (str_ends_with($value, '.cybozu.com')) {
        return $value;
    }

    if ($value === '') {
        throw new RuntimeException(
            'kintoneサブドメインを入力してください。'
        );
    }

    if (!preg_match('/^[A-Za-z0-9-]+$/', $value)) {
        throw new RuntimeException(
            'kintoneサブドメインの形式が不正です。'
        );
    }

    return $value . '.cybozu.com';
}

function kintone_proxy_context(string $proxy): ?array
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (!preg_match(
        '/^([^:\/\s]+):([0-9]{1,5})$/',
        $proxy,
        $m
    )) {
        throw new RuntimeException(
            'Proxyは host:port 形式で入力してください。'
        );
    }

    $port = (int)$m[2];

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException(
            'Proxyポート番号が不正です。'
        );
    }

    return [
        'http' => [
            'proxy'           => 'tcp://' . $m[1] . ':' . $port,
            'request_fulluri' => true,
            'timeout'         => 10,
        ],
    ];
}

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $host = normalize_kintone_subdomain(
        (string)($config['subdomain'] ?? '')
    );

    $appId = (int)($config['app_id'] ?? 0);

    if ($appId < 1) {
        throw new RuntimeException(
            'kintoneアプリIDが不正です。'
        );
    }

    $username = (string)($config['username'] ?? '');
    $password = (string)($config['password'] ?? '');

    if ($username === '' || $password === '') {
        throw new RuntimeException(
            'kintoneのログイン名・パスワードを設定してください。'
        );
    }

    $url = 'https://' . $host . $path;

    $auth = base64_encode(
        $username . ':' . $password
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $auth,
        'Accept: application/json',
        'User-Agent: QuestionnairePOC/1.0',
    ];

    $options = [
        'method'           => strtoupper($method),
        'header'           => implode("\r\n", $headers),
        'ignore_errors'    => true,
        'timeout'          => 20,
        'follow_location'  => false,
        'max_redirects'    => 0,
        'content'          => '',
    ];

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $options['header'] = implode("\r\n", $headers);
        $options['content'] = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
    }

    $ssl = [
        'verify_peer'      => !empty($config['verify_ssl']),
        'verify_peer_name' => !empty($config['verify_ssl']),
        'allow_self_signed'=> empty($config['verify_ssl']),
    ];

    $proxyContext = kintone_proxy_context(
        (string)($config['proxy'] ?? '')
    );

    $context = [
        'http' => $options,
        'ssl'  => $ssl,
    ];

    if ($proxyContext !== null) {
        $context['http'] = array_merge(
            $context['http'],
            $proxyContext['http']
        );
    }

    $stream = stream_context_create($context);

    $error = '';
    set_error_handler(
        function (
            int $severity,
            string $message
        ) use (&$error): bool {
            $error = $message;
            return true;
        }
    );

    $response = file_get_contents(
        $url,
        false,
        $stream
    );

    restore_error_handler();

    if ($response === false) {
        throw new RuntimeException(
            'kintoneへの通信に失敗しました。'
            . ($error !== '' ? ' 詳細: ' . $error : '')
        );
    }

    $status = 0;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match(
                '#^HTTP/\S+\s+([0-9]{3})#',
                $headerLine,
                $m
            )) {
                $status = (int)$m[1];
                break;
            }
        }
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        $decoded = [];
    }

    if ($status < 200 || $status >= 300) {
        $message = (string)(
            $decoded['message']
            ?? 'kintone APIがエラーを返しました。'
        );

        throw new RuntimeException(
            'HTTP ' . $status . ': ' . $message
        );
    }

    return $decoded;
}

function kintone_test(array $config): array
{
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?app=' .
        rawurlencode((string)$config['app_id'])
    );
}

function kintone_fields(array $config): array
{
    $result = kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode((string)$config['app_id'])
    );

    if (isset($result['properties']) && is_array($result['properties'])) {
        return $result['properties'];
    }

    $result = kintone_request(
        $config,
        'GET',
        '/k/v1/app/fields.json?app=' .
        rawurlencode((string)$config['app_id'])
    );

    return is_array($result['properties'] ?? null)
        ? $result['properties']
        : [];
}

function kintone_records(array $config): array
{
    $query = 'limit 500';

    $result = kintone_request(
        $config,
        'GET',
        '/k/v1/records.json?app=' .
        rawurlencode((string)$config['app_id']) .
        '&query=' .
        rawurlencode($query)
    );

    return is_array($result['records'] ?? null)
        ? $result['records']
        : [];
}

function kintone_field_value(array $record, string $code): string
{
    if ($code === '') {
        return '';
    }

    $value = $record[$code]['value'] ?? '';

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                if (isset($item['name'])) {
                    $parts[] = (string)$item['name'];
                } elseif (isset($item['value'])) {
                    $parts[] = (string)$item['value'];
                } else {
                    $parts[] = json_encode(
                        $item,
                        JSON_UNESCAPED_UNICODE
                    );
                }
            } else {
                $parts[] = (string)$item;
            }
        }

        return implode(' ', $parts);
    }

    return (string)$value;
}

/* ============================================================
 * SMTP
 *
 * PHP mail()を使用しない。
 * 標準ソケットのみでSMTP通信。
 * ============================================================
 */

function smtp_read($socket): string
{
    $lines = [];

    while (($line = fgets($socket, 8192)) !== false) {
        $lines[] = rtrim($line, "\r\n");

        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }

    if (!$lines) {
        throw new RuntimeException(
            'SMTPサーバから応答がありません。'
        );
    }

    return implode("\n", $lines);
}

function smtp_expect(
    $socket,
    array $codes
): string {
    $response = smtp_read($socket);

    $last = '';
    $parts = preg_split(
        '/\n/',
        $response
    );

    foreach ($parts as $part) {
        $last = trim($part);
    }

    $code = (int)substr($last, 0, 3);

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' . $code
        );
    }

    return $response;
}

function smtp_command(
    $socket,
    string $command,
    array $codes
): string {
    fwrite($socket, $command . "\r\n");
    return smtp_expect($socket, $codes);
}

function smtp_open(array $cfg)
{
    $server = trim((string)($cfg['server'] ?? ''));
    $port   = (int)($cfg['port'] ?? 0);

    if ($server === '') {
        throw new RuntimeException(
            'SMTPサーバを設定してください。'
        );
    }

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException(
            'SMTPポートが不正です。'
        );
    }

    $encryption = (string)($cfg['encryption'] ?? 'none');

    if ($encryption === 'ssl') {
        $target = 'ssl://' . $server . ':' . $port;
    } else {
        $target = 'tcp://' . $server . ':' . $port;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $target,
        $errno,
        $errstr,
        10,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTP接続に失敗しました。'
            . ' ' . $errstr
        );
    }

    stream_set_timeout($socket, 20);

    smtp_expect($socket, [220]);

    smtp_command(
        $socket,
        'EHLO localhost',
        [250]
    );

    if ($encryption === 'tls') {
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
                'SMTP TLS接続を確立できませんでした。'
            );
        }

        smtp_command(
            $socket,
            'EHLO localhost',
            [250]
        );
    }

    if (!empty($cfg['auth'])) {
        $username = (string)($cfg['username'] ?? '');
        $password = (string)($cfg['password'] ?? '');

        if ($username === '' || $password === '') {
            fclose($socket);

            throw new RuntimeException(
                'SMTP認証情報を設定してください。'
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

function smtp_close($socket): void
{
    try {
        smtp_command(
            $socket,
            'QUIT',
            [221]
        );
    } catch (Throwable $e) {
        /* 終了時の応答エラーは無視 */
    }

    fclose($socket);
}

function mime_header(string $value): string
{
    return '=?UTF-8?B?' .
        base64_encode($value) .
        '?=';
}

function smtp_send_mail(
    array $cfg,
    string $to,
    string $subject,
    string $body
): void {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(
            '送信先メールアドレスが不正です。'
        );
    }

    $from = (string)($cfg['from_email'] ?? '');

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(
            '送信元メールアドレスが不正です。'
        );
    }

    $socket = smtp_open($cfg);

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

        $headers = [
            'From: ' .
            (
                ($cfg['from_name'] ?? '') !== ''
                    ? mime_header((string)$cfg['from_name'])
                    . ' <' . $from . '>'
                    : $from
            ),
            'To: <' . $to . '>',
            'Subject: ' . mime_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if (!empty($cfg['reply_to'])) {
            $headers[] =
                'Reply-To: ' .
                (string)$cfg['reply_to'];
        }

        $message =
            implode("\r\n", $headers)
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

        $message = preg_replace(
            '/^\./m',
            '..',
            $message
        );

        fwrite(
            $socket,
            $message . "\r\n.\r\n"
        );

        smtp_expect(
            $socket,
            [250]
        );
    } finally {
        smtp_close($socket);
    }
}

/* ============================================================
 * HTML
 * ============================================================
 */

function page_start(
    string $title,
    bool $admin = true
): void {
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - アンケートアプリ</title>
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
html,body{margin:0;padding:0}
body{
 background:#f8fafc;
 color:var(--text);
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",
 "Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
}
a{color:var(--primary)}
.header{
 background:#0f172a;
 color:#fff;
 min-height:60px;
}
.header-inner{
 max-width:1400px;
 margin:auto;
 padding:0 22px;
 min-height:60px;
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:20px;
}
.brand{
 color:#fff;
 text-decoration:none;
 font-weight:800;
 white-space:nowrap;
}
.nav{
 display:flex;
 flex-wrap:wrap;
 gap:5px;
}
.nav a{
 color:#cbd5e1;
 text-decoration:none;
 padding:8px 10px;
 border-radius:6px;
 font-size:13px;
}
.nav a:hover{
 background:#1e293b;
 color:#fff;
}
.container{
 max-width:1400px;
 margin:0 auto;
 padding:28px 22px 60px;
}
.narrow{
 max-width:900px;
}
.page-title{
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:16px;
 margin-bottom:22px;
}
.page-title h1{
 margin:0;
 font-size:25px;
}
.card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:12px;
 box-shadow:var(--shadow);
 padding:22px;
 margin-bottom:20px;
}
.grid{
 display:grid;
 grid-template-columns:repeat(2,minmax(0,1fr));
 gap:18px;
}
.grid-3{
 display:grid;
 grid-template-columns:repeat(3,minmax(0,1fr));
 gap:18px;
}
.field{margin-bottom:16px}
.field label{
 display:block;
 font-weight:700;
 margin-bottom:7px;
}
input[type=text],
input[type=password],
input[type=email],
input[type=number],
input[type=datetime-local],
textarea,
select{
 width:100%;
 border:1px solid var(--border);
 border-radius:8px;
 padding:10px 12px;
 background:#fff;
 color:var(--text);
 font-size:14px;
}
textarea{
 min-height:110px;
 resize:vertical;
}
button,.btn{
 border:0;
 border-radius:8px;
 padding:10px 15px;
 font-size:14px;
 font-weight:700;
 cursor:pointer;
 text-decoration:none;
 display:inline-flex;
 align-items:center;
 justify-content:center;
 gap:7px;
}
button:disabled{
 opacity:.55;
 cursor:not-allowed;
}
.btn-primary{
 background:var(--primary);
 color:#fff;
}
.btn-primary:hover{background:var(--primary-dark)}
.btn-secondary{
 background:var(--gray-light);
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
.btn-outline{
 background:#fff;
 color:var(--text);
 border:1px solid var(--border);
}
.buttons{
 display:flex;
 flex-wrap:wrap;
 gap:9px;
}
.toolbar{
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:12px;
 flex-wrap:wrap;
}
.table-wrap{
 width:100%;
 overflow-x:auto;
}
table{
 width:100%;
 border-collapse:collapse;
 min-width:900px;
}
th,td{
 border-bottom:1px solid var(--border);
 padding:11px 10px;
 text-align:left;
 vertical-align:top;
}
th{
 background:#f8fafc;
 font-size:13px;
 white-space:nowrap;
}
.badge{
 display:inline-flex;
 align-items:center;
 padding:4px 9px;
 border-radius:999px;
 font-size:12px;
 font-weight:700;
}
.badge-gray{
 background:#e2e8f0;
 color:#475569;
}
.badge-success{
 background:#dcfce7;
 color:#166534;
}
.badge-warning{
 background:#fef3c7;
 color:#92400e;
}
.badge-danger{
 background:#fee2e2;
 color:#991b1b;
}
.notice{
 border-radius:9px;
 padding:12px 15px;
 margin-bottom:18px;
 white-space:pre-wrap;
}
.notice-success{
 background:#dcfce7;
 color:#166534;
}
.notice-error{
 background:#fee2e2;
 color:#991b1b;
}
.notice-warning{
 background:#fef3c7;
 color:#92400e;
}
.muted{color:var(--gray)}
.small{font-size:12px}
.stat{
 padding:18px;
 border:1px solid var(--border);
 border-radius:10px;
 background:#fff;
}
.stat-label{
 color:var(--gray);
 font-size:13px;
}
.stat-value{
 font-size:28px;
 font-weight:800;
 margin-top:4px;
}
.group{
 border:1px solid var(--border);
 border-radius:10px;
 margin-bottom:16px;
 background:#fff;
}
.group-head{
 padding:13px 15px;
 background:#f8fafc;
 border-bottom:1px solid var(--border);
 display:flex;
 align-items:center;
 gap:10px;
}
.group-body{
 padding:15px;
}
.question{
 border:1px solid var(--border);
 border-radius:9px;
 padding:15px;
 margin-bottom:12px;
 background:#fff;
}
.question:last-child{margin-bottom:0}
.drag-handle{
 cursor:grab;
 color:var(--gray);
 user-select:none;
}
.choice-row{
 display:flex;
 gap:8px;
 align-items:center;
 margin-bottom:8px;
}
.choice-row input{flex:1}
.preview-question{
 padding:18px 0;
 border-bottom:1px solid var(--border);
}
.answer-choice{
 display:block;
 padding:13px;
 margin:8px 0;
 border:1px solid var(--border);
 border-radius:9px;
 background:#fff;
 cursor:pointer;
}
.answer-choice:hover{
 border-color:var(--primary);
 background:#eff6ff;
}
.sticky-actions{
 position:sticky;
 bottom:0;
 z-index:10;
 background:rgba(248,250,252,.96);
 border-top:1px solid var(--border);
 padding:13px 0;
 margin-top:18px;
}
.spinner{
 display:none;
 width:14px;
 height:14px;
 border:2px solid rgba(255,255,255,.5);
 border-top-color:#fff;
 border-radius:50%;
 animation:spin .7s linear infinite;
}
.loading .spinner{display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}
.kv{
 display:grid;
 grid-template-columns:180px 1fr;
 gap:8px 15px;
}
.kv div{
 padding:7px 0;
 border-bottom:1px solid var(--border);
}
.answer-mobile{
 max-width:760px;
 margin:auto;
}
@media(max-width:800px){
 .grid,.grid-3{grid-template-columns:1fr}
 .container{padding:18px 12px 45px}
 .header-inner{
  align-items:flex-start;
  flex-direction:column;
  padding:12px;
 }
 .page-title{
  align-items:flex-start;
  flex-direction:column;
 }
 .kv{grid-template-columns:1fr}
 .buttons button,.buttons .btn{
  width:100%;
 }
}
</style>
</head>
<body>
<?php if ($admin): ?>
<header class="header">
 <div class="header-inner">
  <a class="brand" href="index.php?screen=list">
   アンケート管理
  </a>
  <nav class="nav">
   <a href="index.php?screen=list">アンケート一覧</a>
   <a href="index.php?screen=kintone">kintone連携設定</a>
   <a href="index.php?screen=mail">メールサーバ設定</a>
  </nav>
 </div>
</header>
<?php endif; ?>
<div class="container<?= $admin ? '' : ' answer-mobile' ?>">
<?php
}

function page_end(): void
{
    ?>
</div>
<script>
document.querySelectorAll('form[data-loading]').forEach(function(form){
    form.addEventListener('submit',function(){
        if(form.dataset.confirm){
            if(!window.confirm(form.dataset.confirm)){
                event.preventDefault();
                return;
            }
        }
        form.classList.add('loading');
        form.querySelectorAll('button').forEach(function(button){
            button.disabled=true;
        });
    });
});

document.querySelectorAll('[data-confirm]').forEach(function(el){
    if(el.tagName.toLowerCase()==='form') return;
    el.addEventListener('click',function(e){
        if(!window.confirm(el.dataset.confirm)){
            e.preventDefault();
        }
    });
});
</script>
</body>
</html>
<?php
}

function notice_html(?array $flash): void
{
    if (!$flash) {
        return;
    }

    $type = in_array(
        $flash['type'] ?? '',
        ['success','error','warning'],
        true
    )
        ? $flash['type']
        : 'error';

    echo '<div class="notice notice-' . h($type) . '">'
        . nl2br(h($flash['message'] ?? ''))
        . '</div>';
}

/* ============================================================
 * アンケート保存用POST
 * ============================================================
 */

function build_survey_from_post(array $existing): array
{
    $survey = $existing;

    $title = post_string('title');
    $description = post_string('description');
    $startAt = post_string('startAt');
    $endAt = post_string('endAt');
    $numbering = post_string('numbering', 'global');

    if ($title === '') {
        throw new RuntimeException(
            'アンケートタイトルを入力してください。'
        );
    }

    if (mb_strlen($title) > 200) {
        throw new RuntimeException(
            'アンケートタイトルは200文字以内で入力してください。'
        );
    }

    if ($description !== '' && mb_strlen($description) > 5000) {
        throw new RuntimeException(
            'アンケート説明は5000文字以内で入力してください。'
        );
    }

    if ($startAt === '' || $endAt === '') {
        throw new RuntimeException(
            '開始日時と終了日時を入力してください。'
        );
    }

    $startTs = strtotime($startAt);
    $endTs   = strtotime($endAt);

    if ($startTs === false || $endTs === false) {
        throw new RuntimeException(
            '日時の形式が不正です。'
        );
    }

    if ($endTs <= $startTs) {
        throw new RuntimeException(
            '終了日時は開始日時より後にしてください。'
        );
    }

    if (!in_array(
        $numbering,
        ['global','group'],
        true
    )) {
        $numbering = 'global';
    }

    $survey['title'] = $title;
    $survey['description'] = $description;
    $survey['startAt'] = $startAt;
    $survey['endAt'] = $endAt;
    $survey['numbering'] = $numbering;
    $survey['updatedAt'] = now_text();

    $groupTitles = $_POST['group_title'] ?? [];
    $questionText = $_POST['question_text'] ?? [];
    $questionType = $_POST['question_type'] ?? [];
    $questionRequired = $_POST['question_required'] ?? [];
    $choiceText = $_POST['choice_text'] ?? [];

    if (!is_array($groupTitles)) {
        $groupTitles = [];
    }
    if (!is_array($questionText)) {
        $questionText = [];
    }
    if (!is_array($questionType)) {
        $questionType = [];
    }
    if (!is_array($questionRequired)) {
        $questionRequired = [];
    }
    if (!is_array($choiceText)) {
        $choiceText = [];
    }

    foreach ($survey['groups'] as &$group) {
        $gid = (string)$group['id'];

        if (isset($groupTitles[$gid]) && is_string($groupTitles[$gid])) {
            $group['title'] = trim($groupTitles[$gid]);
        }

        foreach ($group['questions'] as &$question) {
            $qid = (string)$question['id'];

            if (isset($questionText[$qid]) && is_string($questionText[$qid])) {
                $question['text'] = trim($questionText[$qid]);
            }

            $type = 'single';

            if (isset($questionType[$qid]) && is_string($questionType[$qid])) {
                $type = $questionType[$qid];
            }

            if (!in_array(
                $type,
                ['single','multiple','text'],
                true
            )) {
                $type = 'single';
            }

            $question['type'] = $type;
            $question['required'] =
                isset($questionRequired[$qid]);

            if ($type === 'text') {
                $question['choices'] = [];
            } else {
                $question['choices'] = [];

                if (isset($choiceText[$qid]) && is_array($choiceText[$qid])) {
                    foreach ($choiceText[$qid] as $cid => $text) {
                        if (!is_string($text)) {
                            continue;
                        }

                        $text = trim($text);

                        if ($text === '') {
                            continue;
                        }

                        $question['choices'][] = [
                            'id'   => is_string($cid)
                                ? $cid
                                : uid('choice'),
                            'text' => $text,
                        ];
                    }
                }
            }
        }

        unset($question);
    }

    unset($group);

    renumber($survey);

    return normalize_survey($survey);
}

/* ============================================================
 * POST処理
 *
 * CSRF検証は実装しない。
 * ============================================================
 */

$screen = post_string('screen');

if ($screen === '') {
    $screen = is_string($_GET['screen'] ?? null)
        ? (string)$_GET['screen']
        : 'list';
}

$action = post_string('action');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {

        /* --------------------------------------------------------
         * アンケート保存
         * --------------------------------------------------------
         */
        if ($action === 'save_survey') {
            $id = post_string('survey_id');

            $all = surveys();

            if ($id !== '' && valid_id($id)) {
                $found = false;

                foreach ($all as $index => $survey) {
                    if (($survey['id'] ?? '') === $id) {
                        $all[$index] =
                            build_survey_from_post($survey);
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
                }
            } else {
                $new = new_survey();
                $new = build_survey_from_post($new);
                $all[] = $new;
            }

            save_surveys($all);
            flash_set(
                'success',
                'アンケートを保存しました。'
            );
            redirect_to('list');
        }

        /* --------------------------------------------------------
         * アンケート削除
         * --------------------------------------------------------
         */
        if ($action === 'delete_survey') {
            $id = post_string('survey_id');

            if (!valid_id($id)) {
                throw new RuntimeException(
                    'アンケートIDが不正です。'
                );
            }

            $all = surveys();
            $next = [];
            $deleted = false;

            foreach ($all as $survey) {
                if (($survey['id'] ?? '') === $id) {
                    $deleted = true;
                    continue;
                }

                $next[] = $survey;
            }

            if (!$deleted) {
                throw new RuntimeException(
                    '対象アンケートが見つかりません。'
                );
            }

            save_surveys($next);

            flash_set(
                'success',
                'アンケートを削除しました。'
            );

            redirect_to('list');
        }

        /* --------------------------------------------------------
         * 複製
         * --------------------------------------------------------
         */
        if ($action === 'duplicate_survey') {
            $id = post_string('survey_id');

            $survey = find_survey($id);

            if ($survey === null) {
                throw new RuntimeException(
                    '複製対象アンケートが見つかりません。'
                );
            }

            $copy = $survey;
            $copy['id'] = uid('survey');
            $copy['title'] =
                (string)$survey['title'] . '（複製）';
            $copy['status'] = 'draft';
            $copy['createdAt'] = now_text();
            $copy['updatedAt'] = now_text();

            foreach ($copy['groups'] as &$group) {
                $group['id'] = uid('group');

                foreach ($group['questions'] as &$question) {
                    $question['id'] = uid('question');

                    foreach ($question['choices'] as &$choice) {
                        $choice['id'] = uid('choice');
                    }

                    unset($choice);
                }

                unset($question);
            }

            unset($group);

            renumber($copy);

            $all = surveys();
            $all[] = $copy;
            save_surveys($all);

            flash_set(
                'success',
                'アンケートを下書きとして複製しました。'
            );

            redirect_to('list');
        }

        /* --------------------------------------------------------
         * 状態変更
         * --------------------------------------------------------
         */
        if ($action === 'change_status') {
            $id = post_string('survey_id');
            $to = post_string('to_status');

            if (!valid_id($id)) {
                throw new RuntimeException(
                    'アンケートIDが不正です。'
                );
            }

            $all = surveys();
            $changed = false;

            foreach ($all as &$survey) {
                if (($survey['id'] ?? '') !== $id) {
                    continue;
                }

                $survey = apply_auto_status($survey);

                $from = (string)($survey['status'] ?? '');

                if (!status_transition_allowed($from, $to)) {
                    throw new RuntimeException(
                        'この状態変更は許可されていません。'
                    );
                }

                $survey['status'] = $to;
                $survey['updatedAt'] = now_text();
                $changed = true;
                break;
            }

            unset($survey);

            if (!$changed) {
                throw new RuntimeException(
                    '対象アンケートが見つかりません。'
                );
            }

            save_surveys($all);

            flash_set(
                'success',
                'アンケートの状態を変更しました。'
            );

            redirect_to('list');
        }

        /* --------------------------------------------------------
         * グループ追加
         * --------------------------------------------------------
         */
        if ($action === 'add_group') {
            $id = post_string('survey_id');

            $all = surveys();

            foreach ($all as &$survey) {
                if (($survey['id'] ?? '') !== $id) {
                    continue;
                }

                $survey['groups'][] = new_group();
                $survey['updatedAt'] = now_text();
                renumber($survey);
                break;
            }

            unset($survey);

            save_surveys($all);
            redirect_to('edit', ['id' => $id]);
        }

        /* --------------------------------------------------------
         * 質問追加
         * --------------------------------------------------------
         */
        if ($action === 'add_question') {
            $id = post_string('survey_id');
            $gid = post_string('group_id');

            $all = surveys();

            foreach ($all as &$survey) {
                if (($survey['id'] ?? '') !== $id) {
                    continue;
                }

                foreach ($survey['groups'] as &$group) {
                    if (($group['id'] ?? '') !== $gid) {
                        continue;
                    }

                    $group['questions'][] = new_question();
                    break;
                }

                unset($group);

                $survey['updatedAt'] = now_text();
                renumber($survey);
                break;
            }

            unset($survey);

            save_surveys($all);
            redirect_to('edit', ['id' => $id]);
        }

        /* --------------------------------------------------------
         * グループ削除
         * --------------------------------------------------------
         */
        if ($action === 'delete_group') {
            $id = post_string('survey_id');
            $gid = post_string('group_id');

            $all = surveys();

            foreach ($all as &$survey) {
                if (($survey['id'] ?? '') !== $id) {
                    continue;
                }

                $next = [];

                foreach ($survey['groups'] as $group) {
                    if (($group['id'] ?? '') !== $gid) {
                        $next[] = $group;
                    }
                }

                $survey['groups'] = $next;
                $survey['updatedAt'] = now_text();
                renumber($survey);
                break;
            }

            unset($survey);

            save_surveys($all);
            redirect_to('edit', ['id' => $id]);
        }

        /* --------------------------------------------------------
         * 質問削除
         * --------------------------------------------------------
         */
        if ($action === 'delete_question') {
            $id = post_string('survey_id');
            $qid = post_string('question_id');

            $all = surveys();

            foreach ($all as &$survey) {
                if (($survey['id'] ?? '') !== $id) {
                    continue;
                }

                foreach ($survey['groups'] as &$group) {
                    $next = [];

                    foreach ($group['questions'] as $question) {
                        if (($question['id'] ?? '') !== $qid) {
                            $next[] = $question;
                        }
                    }

                    $group['questions'] = $next;
                }

                unset($group);

                $survey['updatedAt'] = now_text();
                renumber($survey);
                break;
            }

            unset($survey);

            save_surveys($all);
            redirect_to('edit', ['id' => $id]);
        }

        /* --------------------------------------------------------
         * kintone設定保存
         * --------------------------------------------------------
         */
        if ($action === 'save_kintone') {
            $s = settings();

            $subdomain = post_string('subdomain');
            $appId = post_string('app_id');
            $username = post_string('username');
            $password = post_string('password');
            $proxy = post_string('proxy');

            if ($subdomain === '') {
                throw new RuntimeException(
                    'kintoneサブドメインを入力してください。'
                );
            }

            if (!ctype_digit($appId) || (int)$appId < 1) {
                throw new RuntimeException(
                    '顧客管理アプリIDが不正です。'
                );
            }

            if ($username === '') {
                throw new RuntimeException(
                    'ログイン名を入力してください。'
                );
            }

            if ($proxy !== '') {
                kintone_proxy_context($proxy);
            }

            $s['kintone']['subdomain'] =
                $subdomain;
            $s['kintone']['app_id'] =
                (string)(int)$appId;
            $s['kintone']['username'] =
                $username;
            $s['kintone']['proxy'] =
                $proxy;
            $s['kintone']['verify_ssl'] =
                post_bool('verify_ssl');

            if ($password !== '') {
                $s['kintone']['password'] = $password;
            }

            save_settings($s);

            flash_set(
                'success',
                'kintone設定を保存しました。'
            );

            redirect_to('kintone');
        }

        /* --------------------------------------------------------
         * kintone接続テスト
         * --------------------------------------------------------
         */
        if ($action === 'test_kintone') {
            $s = settings();

            $result = kintone_test(
                $s['kintone']
            );

            $s['kintone']['connection_status'] =
                '接続確認済み';

            save_settings($s);

            flash_set(
                'success',
                'kintoneへの接続に成功しました。'
                . "\nアプリ名: "
                . (string)($result['name'] ?? '取得済み')
            );

            redirect_to('kintone');
        }

        /* --------------------------------------------------------
         * kintone項目再取得
         * --------------------------------------------------------
         */
        if ($action === 'refresh_kintone_fields') {
            $s = settings();

            $fields = kintone_fields(
                $s['kintone']
            );

            $s['kintone']['fields'] = $fields;
            $s['kintone']['connection_status'] =
                '接続確認済み';

            save_settings($s);

            flash_set(
                'success',
                'kintone項目一覧を再取得しました。'
                . "\n取得項目数: "
                . count($fields)
            );

            redirect_to('kintone');
        }

        /* --------------------------------------------------------
         * kintone同期
         * --------------------------------------------------------
         */
        if ($action === 'sync_kintone') {
            $s = settings();

            $records = kintone_records(
                $s['kintone']
            );

            $map = $s['kintone']['mapping'] ?? [];

            $synced = [];

            foreach ($records as $record) {
                $organizationParts = [];

                foreach (($map['organization'] ?? []) as $code) {
                    $organizationParts[] =
                        kintone_field_value(
                            $record,
                            (string)$code
                        );
                }

                $addressParts = [];

                foreach (($map['address'] ?? []) as $code) {
                    $addressParts[] =
                        kintone_field_value(
                            $record,
                            (string)$code
                        );
                }

                $id =
                    kintone_field_value(
                        $record,
                        '$id'
                    );

                if ($id === '') {
                    $id = uid('customer');
                }

                $synced[] = [
                    'id' => $id,
                    'organization' =>
                        trim(implode(' ', $organizationParts)),
                    'name' =>
                        kintone_field_value(
                            $record,
                            (string)($map['name'] ?? '')
                        ),
                    'email' =>
                        kintone_field_value(
                            $record,
                            (string)($map['email'] ?? '')
                        ),
                    'department' =>
                        kintone_field_value(
                            $record,
                            (string)($map['department'] ?? '')
                        ),
                    'phone' =>
                        kintone_field_value(
                            $record,
                            (string)($map['phone'] ?? '')
                        ),
                    'address' =>
                        trim(implode(' ', $addressParts)),
                    'updatedAt' => now_text(),
                ];
            }

            save_customers($synced);

            flash_set(
                'success',
                '顧客情報を同期しました。'
                . "\n同期件数: "
                . count($synced)
            );

            redirect_to('kintone');
        }

        /* --------------------------------------------------------
         * kintoneマッピング保存
         * --------------------------------------------------------
         */
        if ($action === 'save_kintone_mapping') {
            $s = settings();

            $fields = $s['kintone']['fields'] ?? [];

            $org = post_array('organization');
            $address = post_array('address_fields');

            $name = post_string('name_field');
            $email = post_string('email_field');
            $department = post_string('department_field');
            $phone = post_string('phone_field');

            $validCodes = array_keys($fields);

            foreach ($org as $code) {
                if (!in_array($code, $validCodes, true)) {
                    throw new RuntimeException(
                        '組織名マッピングに不正な項目があります。'
                    );
                }
            }

            foreach ($address as $code) {
                if (!in_array($code, $validCodes, true)) {
                    throw new RuntimeException(
                        '住所マッピングに不正な項目があります。'
                    );
                }
            }

            foreach ([
                'name'       => $name,
                'email'      => $email,
                'department' => $department,
                'phone'      => $phone,
            ] as $label => $code) {
                if (
                    $code !== ''
                    && !in_array($code, $validCodes, true)
                ) {
                    throw new RuntimeException(
                        $label . 'マッピングが不正です。'
                    );
                }
            }

            $s['kintone']['mapping'] = [
                'organization' => $org,
                'name'         => $name,
                'email'        => $email,
                'department'   => $department,
                'phone'        => $phone,
                'address'      => $address,
            ];

            save_settings($s);

            flash_set(
                'success',
                'kintone項目マッピングを保存しました。'
            );

            redirect_to('kintone');
        }

        /* --------------------------------------------------------
         * メール設定保存
         * --------------------------------------------------------
         */
        if ($action === 'save_mail') {
            $s = settings();

            $server = post_string('server');
            $port = post_string('port');
            $encryption = post_string('encryption');
            $username = post_string('username');
            $password = post_string('password');
            $fromEmail = post_string('from_email');
            $fromName = post_string('from_name');
            $replyTo = post_string('reply_to');

            if ($server === '') {
                throw new RuntimeException(
                    'SMTPサーバを入力してください。'
                );
            }

            if (!ctype_digit($port)) {
                throw new RuntimeException(
                    'SMTPポートが不正です。'
                );
            }

            $portInt = (int)$port;

            if ($portInt < 1 || $portInt > 65535) {
                throw new RuntimeException(
                    'SMTPポートが不正です。'
                );
            }

            if (!in_array(
                $encryption,
                ['ssl','tls','none'],
                true
            )) {
                throw new RuntimeException(
                    '暗号化方式が不正です。'
                );
            }

            if (!filter_var(
                $fromEmail,
                FILTER_VALIDATE_EMAIL
            )) {
                throw new RuntimeException(
                    '送信元メールアドレスが不正です。'
                );
            }

            if ($replyTo !== '' && !filter_var(
                $replyTo,
                FILTER_VALIDATE_EMAIL
            )) {
                throw new RuntimeException(
                    '返信先メールアドレスが不正です。'
                );
            }

            $s['mail']['server'] = $server;
            $s['mail']['port'] = $portInt;
            $s['mail']['encryption'] = $encryption;
            $s['mail']['auth'] = post_bool('auth');
            $s['mail']['username'] = $username;
            $s['mail']['from_email'] = $fromEmail;
            $s['mail']['from_name'] = $fromName;
            $s['mail']['reply_to'] = $replyTo;

            if ($password !== '') {
                $s['mail']['password'] = $password;
            }

            save_settings($s);

            flash_set(
                'success',
                'メールサーバ設定を保存しました。'
            );

            redirect_to('mail');
        }

        /* --------------------------------------------------------
         * SMTP接続テスト
         * --------------------------------------------------------
         */
        if ($action === 'test_mail_connection') {
            $s = settings();

            $socket = smtp_open(
                $s['mail']
            );

            smtp_close($socket);

            $s['mail']['connection_status'] =
                '接続確認済み';

            save_settings($s);

            flash_set(
                'success',
                'SMTPサーバへの接続に成功しました。'
            );

            redirect_to('mail');
        }

        /* --------------------------------------------------------
         * テストメール
         * --------------------------------------------------------
         */
        if ($action === 'send_test_mail') {
            $to = post_string('test_email');

            if (!filter_var(
                $to,
                FILTER_VALIDATE_EMAIL
            )) {
                throw new RuntimeException(
                    'テスト送信先メールアドレスが不正です。'
                );
            }

            $s = settings();

            smtp_send_mail(
                $s['mail'],
                $to,
                'アンケートアプリ テストメール',
                "アンケートアプリからのテストメールです。\n"
                . now_text()
            );

            $s['mail']['connection_status'] =
                '接続確認済み';

            save_settings($s);

            flash_set(
                'success',
                'テストメールを送信しました。'
            );

            redirect_to('mail');
        }

        /* --------------------------------------------------------
         * 一括送信
         * --------------------------------------------------------
         */
        if ($action === 'send_bulk_mail') {
            $surveyId = post_string('survey_id');
            $ids = post_array('customer_ids');
            $sendType = post_string('send_type', 'initial');

            $survey = find_survey($surveyId);

            if ($survey === null) {
                throw new RuntimeException(
                    '対象アンケートが見つかりません。'
                );
            }

            if (!in_array(
                $sendType,
                ['initial','reminder','resend'],
                true
            )) {
                throw new RuntimeException(
                    '送信種別が不正です。'
                );
            }

            if (!$ids) {
                throw new RuntimeException(
                    '送信対象の顧客を選択してください。'
                );
            }

            $subject = post_string('subject');
            $body = post_string('body');

            if ($subject === '') {
                throw new RuntimeException(
                    'メール件名を入力してください。'
                );
            }

            if ($body === '') {
                throw new RuntimeException(
                    'メール本文を入力してください。'
                );
            }

            $settingsData = settings();
            $customerList = customers();

            $map = [];

            foreach ($customerList as $customer) {
                $map[(string)($customer['id'] ?? '')] =
                    $customer;
            }

            $baseUrl =
                (
                    (!empty($_SERVER['HTTPS'])
                        && $_SERVER['HTTPS'] !== 'off')
                    ? 'https'
                    : 'http'
                )
                . '://'
                . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                . rtrim(
                    dirname(
                        (string)$_SERVER['SCRIPT_NAME']
                    ),
                    '/'
                );

            $answerUrl =
                $baseUrl .
                '/index.php?screen=answer&id=' .
                rawurlencode($surveyId);

            $history = send_history();
            $sent = 0;
            $failed = 0;

            foreach ($ids as $customerId) {
                if (!isset($map[$customerId])) {
                    continue;
                }

                $customer = $map[$customerId];

                $email =
                    (string)($customer['email'] ?? '');

                if (!filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )) {
                    $failed++;

                    $history[] = [
                        'id' => uid('send'),
                        'surveyId' => $surveyId,
                        'customerId' => $customerId,
                        'customerName' =>
                            (string)($customer['name'] ?? ''),
                        'email' => $email,
                        'type' => $sendType,
                        'status' => 'failed',
                        'message' =>
                            'メールアドレスが不正です。',
                        'sentAt' => now_text(),
                    ];

                    continue;
                }

                $name =
                    (string)($customer['name'] ?? '');

                $personalSubject =
                    str_replace(
                        '{顧客名}',
                        $name,
                        $subject
                    );

                $personalSubject =
                    str_replace(
                        '{アンケートURL}',
                        $answerUrl,
                        $personalSubject
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
                        $answerUrl,
                        $personalBody
                    );

                try {
                    smtp_send_mail(
                        $settingsData['mail'],
                        $email,
                        $personalSubject,
                        $personalBody
                    );

                    $sent++;

                    $history[] = [
                        'id' => uid('send'),
                        'surveyId' => $surveyId,
                        'customerId' => $customerId,
                        'customerName' => $name,
                        'email' => $email,
                        'type' => $sendType,
                        'status' => 'sent',
                        'message' => '送信成功',
                        'sentAt' => now_text(),
                    ];
                } catch (Throwable $e) {
                    $failed++;

                    $history[] = [
                        'id' => uid('send'),
                        'surveyId' => $surveyId,
                        'customerId' => $customerId,
                        'customerName' => $name,
                        'email' => $email,
                        'type' => $sendType,
                        'status' => 'failed',
                        'message' => $e->getMessage(),
                        'sentAt' => now_text(),
                    ];
                }
            }

            save_send_history($history);

            flash_set(
                $failed > 0 ? 'warning' : 'success',
                '送信処理が完了しました。'
                . "\n送信成功: " . $sent
                . "\n送信失敗: " . $failed
            );

            redirect_to(
                'send',
                ['id' => $surveyId]
            );
        }

        /* --------------------------------------------------------
         * 回答確認
         * --------------------------------------------------------
         */
        if ($action === 'answer_confirm') {
            $surveyId = post_string('survey_id');

            $survey = find_survey($surveyId);

            if ($survey === null) {
                throw new RuntimeException(
                    '対象アンケートが見つかりません。'
                );
            }

            $survey = apply_auto_status($survey);

            if (($survey['status'] ?? '') !== 'published') {
                throw new RuntimeException(
                    'このアンケートは現在回答できません。'
                );
            }

            $answerData = [];

            foreach ($survey['groups'] as $group) {
                foreach ($group['questions'] as $question) {
                    $qid = (string)$question['id'];
                    $value = $_POST['answer'][$qid] ?? null;

                    if (
                        !empty($question['required'])
                        && (
                            $value === null
                            || $value === ''
                            || $value === []
                        )
                    ) {
                        throw new RuntimeException(
                            ($question['number'] ?? '')
                            . ' '
                            . ($question['text'] ?? '')
                            . "\n必須項目を回答してください。"
                        );
                    }

                    if (is_array($value)) {
                        $clean = [];

                        foreach ($value as $v) {
                            if (is_string($v)) {
                                $clean[] = trim($v);
                            }
                        }

                        $answerData[$qid] = $clean;
                    } else {
                        $answerData[$qid] =
                            is_string($value)
                                ? trim($value)
                                : '';
                    }
                }
            }

            $_SESSION['answer_draft'] = [
                'surveyId' => $surveyId,
                'answers' => $answerData,
            ];

            redirect_to(
                'confirm',
                ['id' => $surveyId]
            );
        }

        /* --------------------------------------------------------
         * 回答送信
         * --------------------------------------------------------
         */
        if ($action === 'submit_answer') {
            $surveyId = post_string('survey_id');

            $survey = find_survey($surveyId);

            if ($survey === null) {
                throw new RuntimeException(
                    '対象アンケートが見つかりません。'
                );
            }

            $draft = $_SESSION['answer_draft'] ?? null;

            if (
                !is_array($draft)
                || ($draft['surveyId'] ?? '') !== $surveyId
            ) {
                throw new RuntimeException(
                    '回答途中のデータを取得できません。'
                );
            }

            $answerList = answers();

            $answerList[] = [
                'id' => uid('answer'),
                'surveyId' => $surveyId,
                'answers' => $draft['answers'] ?? [],
                'answeredAt' => now_text(),
            ];

            save_answers($answerList);

            unset($_SESSION['answer_draft']);

            redirect_to(
                'complete',
                ['id' => $surveyId]
            );
        }

        /* --------------------------------------------------------
         * 回答修正
         * --------------------------------------------------------
         */
        if ($action === 'answer_back') {
            $surveyId = post_string('survey_id');

            redirect_to(
                'answer',
                ['id' => $surveyId]
            );
        }

        /* --------------------------------------------------------
         * 送信履歴から再送
         * --------------------------------------------------------
         */
        if ($action === 'resend_mail') {
            $historyId = post_string('history_id');

            $history = send_history();
            $target = null;

            foreach ($history as $item) {
                if (($item['id'] ?? '') === $historyId) {
                    $target = $item;
                    break;
                }
            }

            if (!is_array($target)) {
                throw new RuntimeException(
                    '送信履歴が見つかりません。'
                );
            }

            $surveyId = (string)($target['surveyId'] ?? '');
            $survey = find_survey($surveyId);

            if ($survey === null) {
                throw new RuntimeException(
                    '対象アンケートが見つかりません。'
                );
            }

            $customerName =
                (string)($target['customerName'] ?? '');

            $email =
                (string)($target['email'] ?? '');

            $mailSettings = settings()['mail'];

            $baseUrl =
                (
                    (!empty($_SERVER['HTTPS'])
                        && $_SERVER['HTTPS'] !== 'off')
                    ? 'https'
                    : 'http'
                )
                . '://'
                . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                . rtrim(
                    dirname(
                        (string)$_SERVER['SCRIPT_NAME']
                    ),
                    '/'
                );

            $answerUrl =
                $baseUrl .
                '/index.php?screen=answer&id=' .
                rawurlencode($surveyId);

            smtp_send_mail(
                $mailSettings,
                $email,
                '【再送】' . (string)$survey['title'],
                $customerName . " 様\n\n"
                . "アンケートURL:\n"
                . $answerUrl
            );

            $history[] = [
                'id' => uid('send'),
                'surveyId' => $surveyId,
                'customerId' =>
                    (string)($target['customerId'] ?? ''),
                'customerName' => $customerName,
                'email' => $email,
                'type' => 'resend',
                'status' => 'sent',
                'message' => '再送成功',
                'sentAt' => now_text(),
            ];

            save_send_history($history);

            flash_set(
                'success',
                'メールを再送しました。'
            );

            redirect_to(
                'send',
                ['id' => $surveyId]
            );
        }

    } catch (Throwable $e) {
        /*
         * 内部情報・認証情報は表示しない。
         * 外部通信処理から返された利用者向けエラーのみ表示。
         */
        flash_set(
            'error',
            $e->getMessage()
        );

        if ($screen === 'answer') {
            redirect_to(
                'answer',
                ['id' => post_string('survey_id')]
            );
        }

        if ($screen === 'confirm') {
            redirect_to(
                'confirm',
                ['id' => post_string('survey_id')]
            );
        }

        if (
            in_array(
                $action,
                [
                    'save_kintone',
                    'test_kintone',
                    'refresh_kintone_fields',
                    'sync_kintone',
                    'save_kintone_mapping',
                ],
                true
            )
        ) {
            redirect_to('kintone');
        }

        if (
            in_array(
                $action,
                [
                    'save_mail',
                    'test_mail_connection',
                    'send_test_mail',
                ],
                true
            )
        ) {
            redirect_to('mail');
        }

        if (
            in_array(
                $action,
                [
                    'send_bulk_mail',
                    'resend_mail',
                ],
                true
            )
        ) {
            redirect_to(
                'send',
                ['id' => post_string('survey_id')]
            );
        }

        redirect_to(
            'list'
        );
    }
}

/* ============================================================
 * 自動終了の永続化
 * ============================================================
 */

$allSurveys = surveys();
$autoChanged = false;

foreach ($allSurveys as &$survey) {
    $before = (string)($survey['status'] ?? '');

    $survey = apply_auto_status($survey);

    if ($before !== (string)$survey['status']) {
        $survey['updatedAt'] = now_text();
        $autoChanged = true;
    }
}

unset($survey);

if ($autoChanged) {
    save_surveys($allSurveys);
}

/* ============================================================
 * GET パラメータ
 * ============================================================
 */

$screen = is_string($_GET['screen'] ?? null)
    ? (string)$_GET['screen']
    : 'list';

$id = is_string($_GET['id'] ?? null)
    ? (string)$_GET['id']
    : '';

$flash = flash_get();

/* ============================================================
 * 一覧
 * ============================================================
 */

if ($screen === 'list') {
    $list = [];

    foreach (surveys() as $survey) {
        $survey = apply_auto_status($survey);
        $list[] = $survey;
    }

    $keyword = is_string($_GET['q'] ?? null)
        ? trim((string)$_GET['q'])
        : '';

    $filter = is_string($_GET['filter'] ?? null)
        ? (string)$_GET['filter']
        : 'all';

    $sort = is_string($_GET['sort'] ?? null)
        ? (string)$_GET['sort']
        : 'updated_desc';

    $filtered = [];

    foreach ($list as $survey) {
        $title = (string)($survey['title'] ?? '');

        if (
            $keyword !== ''
            && mb_stripos($title, $keyword) === false
        ) {
            continue;
        }

        $status = (string)($survey['status'] ?? '');

        if (
            $filter !== 'all'
            && $status !== $filter
        ) {
            continue;
        }

        $filtered[] = $survey;
    }

    usort(
        $filtered,
        function (array $a, array $b) use ($sort): int {
            if ($sort === 'updated_asc') {
                return strcmp(
                    (string)($a['updatedAt'] ?? ''),
                    (string)($b['updatedAt'] ?? '')
                );
            }

            if ($sort === 'answers_desc') {
                return answer_count_for_survey(
                    (string)$b['id']
                ) <=> answer_count_for_survey(
                    (string)$a['id']
                );
            }

            if ($sort === 'answers_asc') {
                return answer_count_for_survey(
                    (string)$a['id']
                ) <=> answer_count_for_survey(
                    (string)$b['id']
                );
            }

            if ($sort === 'start_desc') {
                return strcmp(
                    (string)($b['startAt'] ?? ''),
                    (string)($a['startAt'] ?? '')
                );
            }

            if ($sort === 'start_asc') {
                return strcmp(
                    (string)($a['startAt'] ?? ''),
                    (string)($b['startAt'] ?? '')
                );
            }

            return strcmp(
                (string)($b['updatedAt'] ?? ''),
                (string)($a['updatedAt'] ?? '')
            );
        }
    );

    page_start('アンケート一覧');
    notice_html($flash);
    ?>
<div class="page-title">
 <h1>アンケート一覧</h1>
 <a class="btn btn-primary" href="index.php?screen=edit&new=1">
  ＋ 新規作成
 </a>
</div>

<div class="card">
 <form method="get">
  <input type="hidden" name="screen" value="list">
  <div class="grid">
   <div class="field">
    <label>タイトル検索</label>
    <input
     type="text"
     name="q"
     value="<?= h($keyword) ?>"
     placeholder="タイトル部分一致">
   </div>
   <div class="field">
    <label>絞り込み</label>
    <select name="filter">
     <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>すべて</option>
     <option value="published" <?= $filter === 'published' ? 'selected' : '' ?>>公開中</option>
     <option value="draft" <?= $filter === 'draft' ? 'selected' : '' ?>>下書き</option>
     <option value="stopped" <?= $filter === 'stopped' ? 'selected' : '' ?>>停止</option>
     <option value="ended" <?= $filter === 'ended' ? 'selected' : '' ?>>終了</option>
    </select>
   </div>
  </div>
  <div class="grid">
   <div class="field">
    <label>ソート</label>
    <select name="sort">
     <option value="updated_desc" <?= $sort === 'updated_desc' ? 'selected' : '' ?>>更新日：新しい順</option>
     <option value="updated_asc" <?= $sort === 'updated_asc' ? 'selected' : '' ?>>更新日：古い順</option>
     <option value="answers_desc" <?= $sort === 'answers_desc' ? 'selected' : '' ?>>回答数：多い順</option>
     <option value="answers_asc" <?= $sort === 'answers_asc' ? 'selected' : '' ?>>回答数：少ない順</option>
     <option value="start_desc" <?= $sort === 'start_desc' ? 'selected' : '' ?>>開始日：新しい順</option>
     <option value="start_asc" <?= $sort === 'start_asc' ? 'selected' : '' ?>>開始日：古い順</option>
    </select>
   </div>
   <div class="field" style="display:flex;align-items:end">
    <button class="btn btn-primary" type="submit">検索</button>
   </div>
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
     <td colspan="7">アンケートがありません。</td>
    </tr>
<?php else: ?>
<?php foreach ($filtered as $survey): ?>
<?php
$count = answer_count_for_survey(
    (string)$survey['id']
);
$status = (string)$survey['status'];
?>
    <tr>
     <td>
      <strong><?= h($survey['title']) ?></strong>
     </td>
     <td><?= h($survey['createdAt'] ?? '') ?></td>
     <td><?= h($survey['updatedAt'] ?? '') ?></td>
     <td>
      <?= h($survey['startAt'] ?? '') ?><br>
      ～ <?= h($survey['endAt'] ?? '') ?>
     </td>
     <td>
      <span class="badge badge-<?= h(status_class($status)) ?>">
       <?= h(status_label($status)) ?>
      </span>
     </td>
     <td><?= h((string)$count) ?></td>
     <td>
      <div class="buttons">
       <a
        class="btn btn-outline"
        href="index.php?screen=edit&id=<?= h($survey['id']) ?>">
        確認・編集
       </a>
       <a
        class="btn btn-outline"
        href="index.php?screen=analytics&id=<?= h($survey['id']) ?>">
        集計
       </a>
       <a
        class="btn btn-outline"
        href="index.php?screen=send&id=<?= h($survey['id']) ?>">
        送信
       </a>
       <form method="post" data-loading data-confirm="このアンケートを複製しますか？">
        <input type="hidden" name="action" value="duplicate_survey">
        <input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">
        <button class="btn btn-secondary" type="submit">複製</button>
       </form>
       <form method="post" data-loading data-confirm="このアンケートを削除しますか？">
        <input type="hidden" name="action" value="delete_survey">
        <input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">
        <button class="btn btn-danger" type="submit">削除</button>
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
    page_end();
    exit;
}

/* ============================================================
 * アンケート編集
 * ============================================================
 */

if ($screen === 'edit') {
    if ($id !== '' && valid_id($id)) {
        $survey = find_survey($id);

        if ($survey === null) {
            redirect_to('list');
        }
    } else {
        $survey = new_survey();
    }

    $survey = normalize_survey($survey);
    $isNew = $id === '';

    page_start(
        'アンケート作成・編集'
    );
    notice_html($flash);
    ?>
<div class="page-title">
 <h1>アンケート作成・編集</h1>
 <div class="buttons">
  <a
   class="btn btn-secondary"
   href="index.php?screen=list">
   キャンセル
  </a>
  <form method="post">
   <input type="hidden" name="action" value="save_survey">
   <input type="hidden" name="survey_id" value="<?= h($survey['id'] ?? '') ?>">
   <button class="btn btn-primary" type="submit">
    保存して一覧へ
   </button>
  </form>
 </div>
</div>

<div class="card">
 <div class="toolbar">
  <strong>状態</strong>
  <div>
<?php
$status = (string)($survey['status'] ?? 'draft');
?>
   <span class="badge badge-<?= h(status_class($status)) ?>">
    <?= h(status_label($status)) ?>
   </span>
  </div>
 </div>

 <div class="buttons" style="margin-top:12px">
<?php if ($status === 'draft'): ?>
 <form method="post" data-loading data-confirm="このアンケートを公開しますか？">
  <input type="hidden" name="action" value="change_status">
  <input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">
  <input type="hidden" name="to_status" value="published">
  <button class="btn btn-success" type="submit">公開</button>
 </form>
<?php elseif ($status === 'published'): ?>
 <form method="post" data-loading data-confirm="このアンケートを停止しますか？">
  <input type="hidden" name="action" value="change_status">
  <input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">
  <input type="hidden" name="to_status" value="stopped">
  <button class="btn btn-warning" type="submit">停止</button>
 </form>
<?php elseif ($status === 'stopped'): ?>
 <form method="post" data-loading data-confirm="このアンケートを再開しますか？">
  <input type="hidden" name="action" value="change_status">
  <input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">
  <input type="hidden" name="to_status" value="published">
  <button class="btn btn-success" type="submit">再開</button>
 </form>
<?php endif; ?>
 </div>
</div>

<form method="post">
<input type="hidden" name="action" value="save_survey">
<input type="hidden" name="survey_id" value="<?= h($survey['id'] ?? '') ?>">

<div class="card">
 <div class="grid">
  <div class="field">
   <label>アンケートタイトル</label>
   <input
    type="text"
    name="title"
    maxlength="200"
    required
    value="<?= h($survey['title']) ?>">
  </div>

  <div class="field">
   <label>質問番号の採番方式</label>
   <select name="numbering">
    <option value="global" <?= ($survey['numbering'] ?? '') === 'global' ? 'selected' : '' ?>>
     アンケート全体で通番：Q1、Q2、Q3...
    </option>
    <option value="group" <?= ($survey['numbering'] ?? '') === 'group' ? 'selected' : '' ?>>
     グループ毎に採番：Q1-1、Q1-2、Q2-1...
    </option>
   </select>
  </div>
 </div>

 <div class="field">
  <label>アンケート説明</label>
  <textarea name="description" maxlength="5000"><?= h($survey['description']) ?></textarea>
 </div>

 <div class="grid">
  <div class="field">
   <label>開始日時</label>
   <input
    type="datetime-local"
    name="startAt"
    required
    value="<?= h($survey['startAt']) ?>">
  </div>

  <div class="field">
   <label>終了日時</label>
   <input
    type="datetime-local"
    name="endAt"
    required
    value="<?= h($survey['endAt']) ?>">
  </div>
 </div>
</div>

<div class="card">
 <div class="toolbar">
  <h2 style="margin:0">グループ・質問</h2>
  <span class="muted small">
   ドラッグ＆ドロップで並び替えできます
  </span>
 </div>

 <div id="groups">
<?php foreach ($survey['groups'] as $group): ?>
 <div
  class="group"
  draggable="true"
  data-group-id="<?= h($group['id']) ?>">

  <div class="group-head">
   <span class="drag-handle">☰</span>
   <input
    type="text"
    name="group_title[<?= h($group['id']) ?>]"
    value="<?= h($group['title']) ?>"
    style="flex:1">

   <form
    method="post"
    data-loading
    data-confirm="このグループを削除しますか？">
    <input type="hidden" name="action" value="delete_group">
    <input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">
    <input type="hidden" name="group_id" value="<?= h($group['id']) ?>">
    <button class="btn btn-danger" type="submit">削除</button>
   </form>
  </div>

  <div
   class="group-body question-drop-zone"
   data-group-id="<?= h($group['id']) ?>">

<?php foreach ($group['questions'] as $question): ?>
   <div
    class="question"
    draggable="true"
    data-question-id="<?= h($question['id']) ?>">

    <div class="toolbar">
     <strong><?= h($question['number']) ?></strong>
     <span class="drag-handle">☰ 質問</span>
    </div>

    <div class="field" style="margin-top:12px">
     <label>質問文</label>
     <input
      type="text"
      name="question_text[<?= h($question['id']) ?>]"
      value="<?= h($question['text']) ?>"
      maxlength="1000">
    </div>

    <div class="grid">
     <div class="field">
      <label>回答形式</label>
      <select
       name="question_type[<?= h($question['id']) ?>]"
       class="question-type">
       <option
        value="single"
        <?= $question['type'] === 'single' ? 'selected' : '' ?>>
        単一選択
       </option>
       <option
        value="multiple"
        <?= $question['type'] === 'multiple' ? 'selected' : '' ?>>
        複数選択
       </option>
       <option
        value="text"
        <?= $question['type'] === 'text' ? 'selected' : '' ?>>
        自由記述
       </option>
      </select>
     </div>

     <div class="field">
      <label>必須設定</label>
      <label style="font-weight:400">
       <input
        type="checkbox"
        name="question_required[<?= h($question['id']) ?>]"
        value="1"
        <?= !empty($question['required']) ? 'checked' : '' ?>>
       必須
      </label>
     </div>
    </div>

<?php if ($question['type'] !== 'text'): ?>
    <div class="field">
     <label>選択肢</label>

<?php foreach ($question['choices'] as $choice): ?>
     <div class="choice-row">
      <input
       type="text"
       name="choice_text[<?= h($question['id']) ?>][<?= h($choice['id']) ?>]"
       value="<?= h($choice['text']) ?>"
       maxlength="500">
     </div>
<?php endforeach; ?>

    </div>

    <div class="field">
     <label>条件分岐</label>

<?php foreach ($question['choices'] as $choice): ?>
     <div class="grid" style="margin-bottom:8px">
      <div>
       <span class="small">
        <?= h($choice['text']) ?>
       </span>
      </div>
      <select
       name="branch[<?= h($question['id']) ?>][<?= h($choice['id']) ?>]">
       <option value="">次の質問へ</option>
<?php foreach ($survey['groups'] as $bg): ?>
<?php foreach ($bg['questions'] as $bq): ?>
<?php if (($bq['id'] ?? '') !== ($question['id'] ?? '')): ?>
       <option
        value="<?= h($bq['id']) ?>"
        <?= (($question['branch'][$choice['id']] ?? '') === $bq['id']) ? 'selected' : '' ?>>
        <?= h($bq['number'] . ' ' . $bq['text']) ?>
       </option>
<?php endif; ?>
<?php endforeach; ?>
<?php endforeach; ?>
      </select>
     </div>
<?php endforeach; ?>
    </div>
<?php endif; ?>

    <div class="buttons">
     <form
      method="post"
      data-loading
      data-confirm="この質問を削除しますか？">
      <input type="hidden" name="action" value="delete_question">
      <input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">
      <input type="hidden" name="question_id" value="<?= h($question['id']) ?>">
      <button class="btn btn-danger" type="submit">質問を削除</button>
     </form>
    </div>
   </div>
<?php endforeach; ?>

   <div style="margin-top:14px">
    <form method="post">
     <input type="hidden" name="action" value="add_question">
     <input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">
     <input type="hidden" name="group_id" value="<?= h($group['id']) ?>">
     <button class="btn btn-secondary" type="submit">
      ＋ 質問を追加
     </button>
    </form>
   </div>
  </div>
 </div>
<?php endforeach; ?>
 </div>

 <div style="margin-top:14px">
  <form method="post">
   <input type="hidden" name="action" value="add_group">
   <input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">
   <button class="btn btn-secondary" type="submit">
    ＋ グループを追加
   </button>
  </form>
 </div>
</div>

<div class="sticky-actions">
 <div class="buttons">
  <a
   class="btn btn-outline"
   href="index.php?screen=preview&id=<?= h($survey['id']) ?>">
   プレビュー
  </a>
  <button class="btn btn-primary" type="submit">
   保存して一覧へ
  </button>
 </div>
</div>

</form>

<script>
(function(){
    const groups = document.getElementById('groups');
    if(!groups) return;

    let dragged = null;

    groups.querySelectorAll('.group').forEach(function(el){
        el.addEventListener('dragstart',function(e){
            dragged = el;
            e.dataTransfer.effectAllowed = 'move';
        });

        el.addEventListener('dragover',function(e){
            e.preventDefault();
        });

        el.addEventListener('drop',function(e){
            e.preventDefault();

            if(!dragged || dragged === el) return;

            const rect = el.getBoundingClientRect();
            const before = e.clientY < rect.top + rect.height / 2;

            if(before){
                groups.insertBefore(dragged,el);
            }else{
                groups.insertBefore(dragged,el.nextSibling);
            }
        });
    });

    groups.querySelectorAll('.question').forEach(function(el){
        el.addEventListener('dragstart',function(e){
            e.stopPropagation();
            dragged = el;
            el.dataset.dragQuestion = '1';
            e.dataTransfer.effectAllowed = 'move';
        });

        el.addEventListener('dragend',function(){
            delete el.dataset.dragQuestion;
        });
    });

    groups.querySelectorAll('.question-drop-zone').forEach(function(zone){
        zone.addEventListener('dragover',function(e){
            e.preventDefault();
        });

        zone.addEventListener('drop',function(e){
            e.preventDefault();

            if(!dragged) return;
            if(!dragged.classList.contains('question')) return;

            zone.appendChild(dragged);
        });
    });
})();
</script>
<?php
    page_end();
    exit;
}

/* ============================================================
 * プレビュー
 * ============================================================
 */

if ($screen === 'preview') {
    if (!valid_id($id)) {
        redirect_to('list');
    }

    $survey = find_survey($id);

    if ($survey === null) {
        redirect_to('list');
    }

    $survey = normalize_survey($survey);

    page_start('プレビュー');
    notice_html($flash);
    ?>
<div class="page-title">
 <h1>プレビュー</h1>
 <a
  class="btn btn-secondary"
  href="index.php?screen=edit&id=<?= h($id) ?>">
  編集へ戻る
 </a>
</div>

<div class="card">
 <h2><?= h($survey['title']) ?></h2>
 <p><?= nl2br(h($survey['description'])) ?></p>
</div>

<div class="card">
<?php foreach ($survey['groups'] as $group): ?>
 <div class="group">
  <div class="group-head">
   <strong><?= h($group['title']) ?></strong>
  </div>
  <div class="group-body">
<?php foreach ($group['questions'] as $question): ?>
   <div class="preview-question">
    <strong>
     <?= h($question['number']) ?>
     <?= h($question['text']) ?>
     <?php if (!empty($question['required'])): ?>
      <span style="color:#dc2626">*</span>
     <?php endif; ?>
    </strong>

<?php if ($question['type'] === 'single'): ?>
<?php foreach ($question['choices'] as $choice): ?>
    <label class="answer-choice">
     <input type="radio" disabled>
     <?= h($choice['text']) ?>
    </label>
<?php endforeach; ?>
<?php elseif ($question['type'] === 'multiple'): ?>
<?php foreach ($question['choices'] as $choice): ?>
    <label class="answer-choice">
     <input type="checkbox" disabled>
     <?= h($choice['text']) ?>
    </label>
<?php endforeach; ?>
<?php else: ?>
    <textarea disabled placeholder="自由記述"></textarea>
<?php endif; ?>
   </div>
<?php endforeach; ?>
  </div>
 </div>
<?php endforeach; ?>
</div>

<div class="card">
 <div class="buttons">
  <strong>プレビューでは送信処理を行いません。</strong>
 </div>
</div>
<?php
    page_end();
    exit;
}

/* ============================================================
 * 顧客送信
 * ============================================================
 */

if ($screen === 'send') {
    if (!valid_id($id)) {
        redirect_to('list');
    }

    $survey = find_survey($id);

    if ($survey === null) {
        redirect_to('list');
    }

    $customerList = customers();
    $history = send_history();

    $surveyHistory = [];

    foreach ($history as $item) {
        if (($item['surveyId'] ?? '') === $id) {
            $surveyHistory[] = $item;
        }
    }

    $mail = settings()['mail'];

    page_start('顧客選択・メール送信');
    notice_html($flash);
    ?>
<div class="page-title">
 <h1>顧客選択・メール送信</h1>
</div>

<div class="card">
 <strong>対象アンケート</strong>
 <div style="font-size:20px;margin-top:6px">
  <?= h($survey['title']) ?>
 </div>
 <div class="muted small">
  対象ID: <?= h($survey['id']) ?>
 </div>
</div>

<div class="card">
 <div class="toolbar">
  <h2 style="margin:0">顧客選択・送信</h2>
  <input
   type="text"
   id="customer-search"
   placeholder="顧客検索"
   style="max-width:300px">
 </div>

 <form
  method="post"
  data-loading
  data-confirm="選択した顧客へメールを一括送信しますか？">

  <input type="hidden" name="action" value="send_bulk_mail">
  <input type="hidden" name="survey_id" value="<?= h($id) ?>">

  <div class="table-wrap" style="margin-top:18px">
   <table id="customer-table">
    <thead>
     <tr>
      <th>
       <input type="checkbox" id="check-all">
      </th>
      <th>組織名</th>
      <th>氏名</th>
      <th>メールアドレス</th>
      <th>部署名</th>
      <th>電話番号</th>
      <th>住所</th>
     </tr>
    </thead>
    <tbody>
<?php foreach ($customerList as $customer): ?>
<?php
$searchText = implode(' ', [
    $customer['organization'] ?? '',
    $customer['name'] ?? '',
    $customer['email'] ?? '',
    $customer['department'] ?? '',
    $customer['phone'] ?? '',
    $customer['address'] ?? '',
]);
?>
     <tr data-search="<?= h($searchText) ?>">
      <td>
       <input
        type="checkbox"
        name="customer_ids[]"
        value="<?= h($customer['id'] ?? '') ?>">
      </td>
      <td><?= h($customer['organization'] ?? '') ?></td>
      <td><?= h($customer['name'] ?? '') ?></td>
      <td><?= h($customer['email'] ?? '') ?></td>
      <td><?= h($customer['department'] ?? '') ?></td>
      <td><?= h($customer['phone'] ?? '') ?></td>
      <td><?= h($customer['address'] ?? '') ?></td>
     </tr>
<?php endforeach; ?>
    </tbody>
   </table>
  </div>

  <div class="grid" style="margin-top:20px">
   <div class="field">
    <label>送信種別</label>
    <select name="send_type">
     <option value="initial">初回送信</option>
     <option value="reminder">リマインド</option>
     <option value="resend">再送</option>
    </select>
   </div>
  </div>

  <div class="field">
   <label>メール件名</label>
   <input
    type="text"
    name="subject"
    value="<?= h($survey['title']) ?>"
    required>
  </div>

  <div class="field">
   <label>メール本文</label>
   <textarea
    name="body"
    required> {顧客名} 様

アンケートへのご回答をお願いいたします。

アンケートURL:
{アンケートURL}</textarea>
  </div>

  <button class="btn btn-primary" type="submit">
   <span class="spinner"></span>
   一括送信
  </button>
 </form>
</div>

<div class="card">
 <div class="toolbar">
  <h2 style="margin:0">送信履歴</h2>
  <span class="muted">
   <?= h((string)count($surveyHistory)) ?> 件
  </span>
 </div>

 <div class="table-wrap" style="margin-top:15px">
  <table>
   <thead>
    <tr>
     <th>日時</th>
     <th>氏名</th>
     <th>メール</th>
     <th>種別</th>
     <th>結果</th>
     <th>操作</th>
    </tr>
   </thead>
   <tbody>
<?php if (!$surveyHistory): ?>
    <tr>
     <td colspan="6">送信履歴はありません。</td>
    </tr>
<?php else: ?>
<?php foreach (array_reverse($surveyHistory) as $item): ?>
    <tr>
     <td><?= h($item['sentAt'] ?? '') ?></td>
     <td><?= h($item['customerName'] ?? '') ?></td>
     <td><?= h($item['email'] ?? '') ?></td>
     <td><?= h($item['type'] ?? '') ?></td>
     <td>
      <span class="badge badge-<?= ($item['status'] ?? '') === 'sent' ? 'success' : 'danger' ?>">
       <?= ($item['status'] ?? '') === 'sent' ? '送信成功' : '送信失敗' ?>
      </span>
      <?php if (($item['message'] ?? '') !== ''): ?>
       <div class="small muted">
        <?= h($item['message']) ?>
       </div>
      <?php endif; ?>
     </td>
     <td>
<?php if (($item['status'] ?? '') === 'sent'): ?>
      <form
       method="post"
       data-loading
       data-confirm="このメールを再送しますか？">
       <input type="hidden" name="action" value="resend_mail">
       <input type="hidden" name="history_id" value="<?= h($item['id']) ?>">
       <button class="btn btn-secondary" type="submit">再送</button>
      </form>
<?php endif; ?>
     </td>
    </tr>
<?php endforeach; ?>
<?php endif; ?>
   </tbody>
  </table>
 </div>
</div>

<script>
(function(){
    const search = document.getElementById('customer-search');
    const rows = document.querySelectorAll('#customer-table tbody tr');
    const all = document.getElementById('check-all');

    if(search){
        search.addEventListener('input',function(){
            const q = search.value.trim().toLowerCase();

            rows.forEach(function(row){
                const text = (row.dataset.search || '').toLowerCase();
                row.style.display =
                    !q || text.indexOf(q) !== -1
                    ? ''
                    : 'none';
            });
        });
    }

    if(all){
        all.addEventListener('change',function(){
            document.querySelectorAll(
                '#customer-table tbody input[type="checkbox"]'
            ).forEach(function(box){
                box.checked = all.checked;
            });
        });
    }
})();
</script>
<?php
    page_end();
    exit;
}

/* ============================================================
 * 集計
 * ============================================================
 */

if ($screen === 'analytics') {
    if (!valid_id($id)) {
        redirect_to('list');
    }

    $survey = find_survey($id);

    if ($survey === null) {
        redirect_to('list');
    }

    $answerList = [];

    foreach (answers() as $answer) {
        if (($answer['surveyId'] ?? '') === $id) {
            $answerList[] = $answer;
        }
    }

    $customerList = customers();
    $history = send_history();

    $sentCustomerIds = [];

    foreach ($history as $item) {
        if (
            ($item['surveyId'] ?? '') === $id
            && ($item['status'] ?? '') === 'sent'
        ) {
            $sentCustomerIds[
                (string)($item['customerId'] ?? '')
            ] = true;
        }
    }

    $registeredAnswerCount = count($answerList);
    $unregistered = 0;

    foreach ($answerList as $answer) {
        $found = false;

        foreach ($customerList as $customer) {
            if (
                isset($customer['id'])
                && ($answer['customerId'] ?? '') === $customer['id']
            ) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            $unregistered++;
        }
    }

    $sentCount = count($sentCustomerIds);
    $unanswered = max(
        0,
        $sentCount - $registeredAnswerCount
    );

    $rate = $sentCount > 0
        ? round(
            $registeredAnswerCount /
            $sentCount *
            100,
            1
        )
        : 0;

    page_start('回答集計・分析');
    notice_html($flash);
    ?>
<div class="page-title">
 <h1>回答集計・分析</h1>
 <div class="buttons">
  <a
   class="btn btn-secondary"
   href="index.php?screen=analytics&id=<?= h($id) ?>&export=csv">
   CSV
  </a>
  <a
   class="btn btn-secondary"
   href="index.php?screen=analytics&id=<?= h($id) ?>&export=pdf">
   PDF
  </a>
 </div>
</div>

<div class="card">
 <strong>対象アンケート</strong>
 <div style="font-size:20px;margin-top:6px">
  <?= h($survey['title']) ?>
 </div>
</div>

<div class="grid-3">
 <div class="stat">
  <div class="stat-label">送信対象者数</div>
  <div class="stat-value"><?= h((string)$sentCount) ?></div>
 </div>
 <div class="stat">
  <div class="stat-label">回答数</div>
  <div class="stat-value"><?= h((string)$registeredAnswerCount) ?></div>
 </div>
 <div class="stat">
  <div class="stat-label">未回答数</div>
  <div class="stat-value"><?= h((string)$unanswered) ?></div>
 </div>
 <div class="stat">
  <div class="stat-label">未登録回答数</div>
  <div class="stat-value"><?= h((string)$unregistered) ?></div>
 </div>
 <div class="stat">
  <div class="stat-label">回答率</div>
  <div class="stat-value"><?= h((string)$rate) ?>%</div>
 </div>
</div>

<div class="card">
 <h2>設問別集計</h2>

<?php if (!$answerList): ?>
 <p>現在、回答データはありません</p>
<?php else: ?>
<?php foreach ($survey['groups'] as $group): ?>
<?php foreach ($group['questions'] as $question): ?>
<?php
$qid = (string)$question['id'];
$values = [];

foreach ($answerList as $answer) {
    $v = $answer['answers'][$qid] ?? '';

    if (is_array($v)) {
        foreach ($v as $one) {
            $values[] = (string)$one;
        }
    } else {
        $values[] = (string)$v;
    }
}

$countValues = array_count_values($values);
?>
 <div class="preview-question">
  <strong>
   <?= h($question['number']) ?>
   <?= h($question['text']) ?>
  </strong>

<?php if ($question['type'] === 'text'): ?>
  <div style="margin-top:10px">
<?php foreach ($values as $value): ?>
   <?php if ($value !== ''): ?>
    <div style="padding:8px 0;border-bottom:1px solid var(--border)">
     <?= nl2br(h($value)) ?>
    </div>
   <?php endif; ?>
<?php endforeach; ?>
  </div>
<?php else: ?>
  <div style="margin-top:10px">
<?php foreach ($question['choices'] as $choice): ?>
<?php
$c = (string)($choice['text'] ?? '');
$n = (int)($countValues[$c] ?? 0);
?>
   <div style="margin:8px 0">
    <div class="toolbar">
     <span><?= h($c) ?></span>
     <strong><?= h((string)$n) ?></strong>
    </div>
    <div style="height:8px;background:#e2e8f0;border-radius:99px">
     <div
      style="height:8px;background:#2563eb;border-radius:99px;width:<?= $registeredAnswerCount > 0 ? min(100, ($n / $registeredAnswerCount) * 100) : 0 ?>%">
     </div>
    </div>
   </div>
<?php endforeach; ?>
  </div>
<?php endif; ?>
 </div>
<?php endforeach; ?>
<?php endforeach; ?>
<?php endif; ?>
</div>

<div class="card">
 <h2>個別回答</h2>

<?php if (!$answerList): ?>
 <p>現在、回答データはありません</p>
<?php else: ?>
<?php foreach (array_reverse($answerList) as $answer): ?>
 <div style="border-bottom:1px solid var(--border);padding:15px 0">
  <div class="muted small">
   <?= h($answer['answeredAt'] ?? '') ?>
  </div>

<?php foreach ($survey['groups'] as $group): ?>
<?php foreach ($group['questions'] as $question): ?>
<?php
$qid = (string)$question['id'];
$v = $answer['answers'][$qid] ?? '';

if (is_array($v)) {
    $display = implode(', ', $v);
} else {
    $display = (string)$v;
}
?>
  <div style="margin-top:8px">
   <strong>
    <?= h($question['number']) ?>
    <?= h($question['text']) ?>
   </strong>
   <div><?= nl2br(h($display)) ?></div>
  </div>
<?php endforeach; ?>
<?php endforeach; ?>
 </div>
<?php endforeach; ?>
<?php endif; ?>
</div>
<?php
    page_end();
    exit;
}

/* ============================================================
 * kintone設定
 * ============================================================
 */

if ($screen === 'kintone') {
    $s = settings();
    $k = $s['kintone'];
    $fields = is_array($k['fields'] ?? null)
        ? $k['fields']
        : [];

    page_start('kintone連携設定');
    notice_html($flash);
    ?>
<div class="page-title">
 <h1>kintone連携設定</h1>
</div>

<div class="card">
 <div style="margin-bottom:18px">
  接続状態：
  <span class="badge badge-<?= ($k['connection_status'] ?? '') === '接続確認済み' ? 'success' : 'gray' ?>">
   <?= h($k['connection_status'] ?? '未設定') ?>
  </span>
 </div>

<form method="post" data-loading>
 <input type="hidden" name="action" value="save_kintone">

 <div class="grid">
  <div class="field">
   <label>サブドメイン</label>
   <input
    type="text"
    name="subdomain"
    required
    value="<?= h($k['subdomain'] ?? '') ?>"
    placeholder="https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx">
  </div>

  <div class="field">
   <label>顧客管理アプリID</label>
   <input
    type="number"
    name="app_id"
    min="1"
    required
    value="<?= h($k['app_id'] ?? '') ?>">
  </div>

  <div class="field">
   <label>ログイン名</label>
   <input
    type="text"
    name="username"
    required
    autocomplete="username"
    value="<?= h($k['username'] ?? '') ?>">
  </div>

  <div class="field">
   <label>パスワード</label>
   <input
    type="password"
    name="password"
    autocomplete="new-password"
    placeholder="変更しない場合は空欄">
   <div class="muted small">
    保存済みパスワードは表示しません。
   </div>
  </div>

  <div class="field">
   <label>Proxy</label>
   <input
    type="text"
    name="proxy"
    value="<?= h($k['proxy'] ?? '') ?>"
    placeholder="host:port">
   <div class="muted small">
    未入力の場合は直接接続します。
   </div>
  </div>

  <div class="field">
   <label>SSL証明書検証</label>
   <label style="font-weight:400">
    <input
     type="checkbox"
     name="verify_ssl"
     value="1"
     <?= !empty($k['verify_ssl']) ? 'checked' : '' ?>>
    有効
   </label>
   <div class="muted small">
    POC初期値は無効です。
   </div>
  </div>
 </div>

 <button class="btn btn-primary" type="submit">
  設定保存
 </button>
</form>

<div class="buttons" style="margin-top:12px">
 <form method="post" data-loading>
  <input type="hidden" name="action" value="test_kintone">
  <button class="btn btn-success" type="submit">
   <span class="spinner"></span>
   接続テスト
  </button>
 </form>

 <form method="post" data-loading>
  <input type="hidden" name="action" value="refresh_kintone_fields">
  <button class="btn btn-secondary" type="submit">
   <span class="spinner"></span>
   項目一覧を再取得
  </button>
 </form>

 <form
  method="post"
  data-loading
  data-confirm="kintoneから顧客情報を取得して同期しますか？">
  <input type="hidden" name="action" value="sync_kintone">
  <button class="btn btn-secondary" type="submit">
   <span class="spinner"></span>
   顧客情報を同期
  </button>
 </form>
</div>
</div>

<?php if ($fields): ?>
<div class="card">
 <h2>kintone項目マッピング</h2>

<form method="post">
 <input type="hidden" name="action" value="save_kintone_mapping">

 <div class="field">
  <label>組織名</label>
<?php foreach ($fields as $code => $field): ?>
<?php
$selected =
    $k['mapping']['organization'] ?? [];
?>
  <label style="font-weight:400">
   <input
    type="checkbox"
    name="organization[]"
    value="<?= h($code) ?>"
    <?= in_array(
        $code,
        $selected,
        true
    ) ? 'checked' : '' ?>>
   <?= h(
       ($field['label'] ?? $code)
       . ' [' . $code . ']'
   ) ?>
  </label>
<?php endforeach; ?>
 </div>

<?php
$mapFields = [
    'name_field'       => ['name', '氏名'],
    'email_field'      => ['email', 'メールアドレス'],
    'department_field' => ['department', '部署名'],
    'phone_field'     => ['phone', '電話番号'],
];
?>

<?php foreach ($mapFields as $postName => $info): ?>
 <div class="field">
  <label><?= h($info[1]) ?></label>
  <select name="<?= h($postName) ?>">
   <option value="">未設定</option>
<?php foreach ($fields as $code => $field): ?>
   <option
    value="<?= h($code) ?>"
    <?= ($k['mapping'][$info[0]] ?? '') === $code ? 'selected' : '' ?>>
    <?= h(
        ($field['label'] ?? $code)
        . ' [' . $code . ']'
    ) ?>
   </option>
<?php endforeach; ?>
  </select>
 </div>
<?php endforeach; ?>

 <div class="field">
  <label>住所</label>
<?php
$addressSelected =
    $k['mapping']['address'] ?? [];
?>
<?php foreach ($fields as $code => $field): ?>
  <label style="font-weight:400">
   <input
    type="checkbox"
    name="address_fields[]"
    value="<?= h($code) ?>"
    <?= in_array(
        $code,
        $addressSelected,
        true
    ) ? 'checked' : '' ?>>
   <?= h(
       ($field['label'] ?? $code)
       . ' [' . $code . ']'
   ) ?>
  </label>
<?php endforeach; ?>
 </div>

 <button class="btn btn-primary" type="submit">
  マッピングを保存
 </button>
</form>
</div>
<?php endif; ?>

<?php
    page_end();
    exit;
}

/* ============================================================
 * メール設定
 * ============================================================
 */

if ($screen === 'mail') {
    $m = settings()['mail'];

    page_start('メールサーバ設定');
    notice_html($flash);
    ?>
<div class="page-title">
 <h1>メールサーバ設定</h1>
</div>

<div class="card">
 <div style="margin-bottom:18px">
  接続状態：
  <span class="badge badge-<?= ($m['connection_status'] ?? '') === '接続確認済み' ? 'success' : 'gray' ?>">
   <?= h($m['connection_status'] ?? '未設定') ?>
  </span>
 </div>

<form method="post" data-loading>
 <input type="hidden" name="action" value="save_mail">

 <div class="grid">
  <div class="field">
   <label>SMTPサーバ</label>
   <input
    type="text"
    name="server"
    required
    value="<?= h($m['server'] ?? '') ?>">
  </div>

  <div class="field">
   <label>SMTPポート</label>
   <input
    type="number"
    name="port"
    min="1"
    max="65535"
    required
    value="<?= h($m['port'] ?? 587) ?>">
  </div>

  <div class="field">
   <label>暗号化方式</label>
   <select name="encryption">
    <option value="ssl" <?= ($m['encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
    <option value="tls" <?= ($m['encryption'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS</option>
    <option value="none" <?= ($m['encryption'] ?? '') === 'none' ? 'selected' : '' ?>>なし</option>
   </select>
  </div>

  <div class="field">
   <label>SMTP認証</label>
   <label style="font-weight:400">
    <input
     type="checkbox"
     name="auth"
     value="1"
     <?= !empty($m['auth']) ? 'checked' : '' ?>>
    SMTP認証を使用
   </label>
  </div>

  <div class="field">
   <label>SMTPユーザー名</label>
   <input
    type="text"
    name="username"
    autocomplete="username"
    value="<?= h($m['username'] ?? '') ?>">
  </div>

  <div class="field">
   <label>SMTPパスワード</label>
   <input
    type="password"
    name="password"
    autocomplete="new-password"
    placeholder="変更しない場合は空欄">
  </div>

  <div class="field">
   <label>送信元メールアドレス</label>
   <input
    type="email"
    name="from_email"
    required
    value="<?= h($m['from_email'] ?? '') ?>">
  </div>

  <div class="field">
   <label>送信元名</label>
   <input
    type="text"
    name="from_name"
    value="<?= h($m['from_name'] ?? '') ?>">
  </div>

  <div class="field">
   <label>返信先メールアドレス</label>
   <input
    type="email"
    name="reply_to"
    value="<?= h($m['reply_to'] ?? '') ?>">
  </div>
 </div>

 <button class="btn btn-primary" type="submit">
  設定保存
 </button>
</form>

<div class="buttons" style="margin-top:12px">
 <form method="post" data-loading>
  <input
   type="hidden"
   name="action"
   value="test_mail_connection">
  <button class="btn btn-success" type="submit">
   <span class="spinner"></span>
   接続テスト
  </button>
 </form>
</div>
</div>

<div class="card">
 <h2>テストメール送信</h2>

<form method="post" data-loading>
 <input type="hidden" name="action" value="send_test_mail">

 <div class="field">
  <label>テスト送信先</label>
  <input
   type="email"
   name="test_email"
   required
   placeholder="test@example.com">
 </div>

 <button class="btn btn-primary" type="submit">
  <span class="spinner"></span>
  テストメール送信
 </button>
</form>
</div>
<?php
    page_end();
    exit;
}

/* ============================================================
 * 回答者
 * ============================================================
 */

if ($screen === 'answer') {
    if (!valid_id($id)) {
        page_start('アンケート', false);
        ?>
<div class="card">
 <h1>アンケートが見つかりません</h1>
 <p>指定されたアンケートは存在しません。</p>
</div>
<?php
        page_end();
        exit;
    }

    $survey = find_survey($id);

    if ($survey === null) {
        page_start('アンケート', false);
        ?>
<div class="card">
 <h1>アンケートが見つかりません</h1>
</div>
<?php
        page_end();
        exit;
    }

    $survey = apply_auto_status($survey);

    if (($survey['status'] ?? '') !== 'published') {
        page_start('アンケート', false);
        ?>
<div class="card">
 <h1>回答できません</h1>
 <p>このアンケートは現在回答を受け付けていません。</p>
</div>
<?php
        page_end();
        exit;
    }

    page_start(
        'アンケート回答',
        false
    );
    notice_html($flash);
    ?>
<div class="card">
 <h1><?= h($survey['title']) ?></h1>
 <p><?= nl2br(h($survey['description'])) ?></p>
</div>

<form method="post">
 <input type="hidden" name="action" value="answer_confirm">
 <input type="hidden" name="survey_id" value="<?= h($id) ?>">

<?php foreach ($survey['groups'] as $group): ?>
<div class="card">
 <h2><?= h($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>
<?php
$qid = (string)$question['id'];
?>
<div class="field">
 <label>
  <?= h($question['number']) ?>
  <?= h($question['text']) ?>
<?php if (!empty($question['required'])): ?>
  <span style="color:#dc2626">*</span>
<?php endif; ?>
 </label>

<?php if ($question['type'] === 'single'): ?>
<?php foreach ($question['choices'] as $choice): ?>
 <label class="answer-choice">
  <input
   type="radio"
   name="answer[<?= h($qid) ?>]"
   value="<?= h($choice['text']) ?>">
  <?= h($choice['text']) ?>
 </label>
<?php endforeach; ?>

<?php elseif ($question['type'] === 'multiple'): ?>
<?php foreach ($question['choices'] as $choice): ?>
 <label class="answer-choice">
  <input
   type="checkbox"
   name="answer[<?= h($qid) ?>][]"
   value="<?= h($choice['text']) ?>">
  <?= h($choice['text']) ?>
 </label>
<?php endforeach; ?>

<?php else: ?>
 <textarea
  name="answer[<?= h($qid) ?>]"
  rows="5"></textarea>
<?php endif; ?>

</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<div class="sticky-actions">
 <button class="btn btn-primary" type="submit">
  次へ
 </button>
</div>
</form>
<?php
    page_end();
    exit;
}

/* ============================================================
 * 回答確認
 * ============================================================
 */

if ($screen === 'confirm') {
    if (!valid_id($id)) {
        redirect_to('list');
    }

    $survey = find_survey($id);

    if ($survey === null) {
        redirect_to('list');
    }

    $draft = $_SESSION['answer_draft'] ?? null;

    if (
        !is_array($draft)
        || ($draft['surveyId'] ?? '') !== $id
    ) {
        redirect_to('answer', ['id' => $id]);
    }

    $draftAnswers =
        is_array($draft['answers'] ?? null)
            ? $draft['answers']
            : [];

    page_start(
        '回答確認',
        false
    );
    notice_html($flash);
    ?>
<div class="card">
 <h1>回答確認</h1>
 <p>
  以下の内容で送信します。
  修正する場合は「戻る」を押してください。
 </p>
</div>

<div class="card">
 <h2><?= h($survey['title']) ?></h2>

<?php foreach ($survey['groups'] as $group): ?>
 <h3><?= h($group['title']) ?></h3>

<?php foreach ($group['questions'] as $question): ?>
<?php
$qid = (string)$question['id'];
$v = $draftAnswers[$qid] ?? '';

if (is_array($v)) {
    $display = implode(', ', $v);
} else {
    $display = (string)$v;
}
?>
 <div style="padding:13px 0;border-bottom:1px solid var(--border)">
  <strong>
   <?= h($question['number']) ?>
   <?= h($question['text']) ?>
  </strong>
  <div style="margin-top:5px">
   <?= nl2br(h($display)) ?>
  </div>
 </div>
<?php endforeach; ?>
<?php endforeach; ?>
</div>

<div class="buttons">
 <form method="post">
  <input type="hidden" name="action" value="answer_back">
  <input type="hidden" name="survey_id" value="<?= h($id) ?>">
  <button class="btn btn-secondary" type="submit">
   戻る
  </button>
 </form>

 <form
  method="post"
  data-loading
  data-confirm="回答を送信しますか？">
  <input type="hidden" name="action" value="submit_answer">
  <input type="hidden" name="survey_id" value="<?= h($id) ?>">
  <button class="btn btn-primary" type="submit">
   回答を送信
  </button>
 </form>
</div>
<?php
    page_end();
    exit;
}

/* ============================================================
 * 回答完了
 * ============================================================
 */

if ($screen === 'complete') {
    if (!valid_id($id)) {
        page_start('回答完了', false);
        ?>
<div class="card">
 <h1>回答が完了しました</h1>
 <p>ご回答ありがとうございました。</p>
</div>
<?php
        page_end();
        exit;
    }

    $survey = find_survey($id);

    page_start(
        '回答完了',
        false
    );
    ?>
<div class="card" style="text-align:center;padding:45px 25px">
 <div style="font-size:50px;color:#16a34a">✓</div>
 <h1>回答ありがとうございました</h1>
 <p>
  アンケートへの回答を受け付けました。
 </p>
</div>
<?php
    page_end();
    exit;
}

/* ============================================================
 * 不明な画面
 * ============================================================
 */

redirect_to('list');

/* ============================================================
 * 補助関数
 * ============================================================
 */

function answer_count_for_survey(string $surveyId): int
{
    $count = 0;

    foreach (answers() as $answer) {
        if (($answer['surveyId'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}
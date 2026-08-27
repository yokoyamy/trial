<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 *
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし
 *
 * 重要:
 * - 実運用では DATA_DIR をWeb公開ディレクトリ外に設定することを推奨
 * - 管理者パスワードは環境変数 ADMIN_PASSWORD で設定する
 * - kintone/SMTP設定はサーバー側ファイルへ保存する
 */

session_set_cookie_params([
    'httponly' => true,
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'samesite' => 'Lax',
]);
session_start();

/* =========================================================
 * 基本設定
 * ========================================================= */

const APP_NAME = 'アンケート管理システム';

$dataDir = getenv('SURVEY_DATA_DIR');
if (!$dataDir) {
    $dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
}

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0700, true);
}

/*
 * Apacheから直接dataディレクトリへアクセスされる環境向け。
 * 可能ならDATA_DIRをWeb公開ディレクトリ外へ設定してください。
 */
if (is_dir($dataDir)) {
    $htaccess = $dataDir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents(
            $htaccess,
            "Require all denied\n"
        );
    }
}

$storageFile = $dataDir . DIRECTORY_SEPARATOR . 'app.json';

$adminUser = getenv('ADMIN_USER') ?: 'admin';
$adminPassword = getenv('ADMIN_PASSWORD') ?: 'change-me-now';

/* =========================================================
 * 共通関数
 * ========================================================= */

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function baseUrl(array $params = []): string
{
    $base = strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?');
    if (!$base) {
        $base = 'index.php';
    }

    if (!$params) {
        return $base;
    }

    return $base . '?' . http_build_query($params);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function uuid(): string
{
    return bin2hex(random_bytes(12));
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $token = (string)($_POST['csrf_token'] ?? '');

    if (
        $token === '' ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        exit('CSRF validation failed.');
    }
}

function isAdmin(): bool
{
    return !empty($_SESSION['admin_authenticated']);
}

function requireAdmin(): void
{
    if (!isAdmin()) {
        redirect(baseUrl(['screen' => 'login']));
    }
}

function flash(string $message, string $type = 'info'): void
{
    $_SESSION['flash'][] = [
        'message' => $message,
        'type'    => $type,
    ];
}

function consumeFlash(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function readData(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $raw = @file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function writeData(string $file, array $data): bool
{
    $dir = dirname($file);

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $fp = @fopen($tmp, 'wb');

    if (!$fp) {
        return false;
    }

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
}

function defaultData(): array
{
    return [
        'surveys' => [],
        'answers' => [],
        'customers' => [],
        'send_history' => [],
        'settings' => [
            'kintone' => [
                'subdomain' => '',
                'app_id' => '',
                'login_name' => '',
                'password' => '',
                'proxy' => '',
                'verify_ssl' => true,
                'mapping' => [
                    'organization' => '',
                    'name' => '',
                    'email' => '',
                    'department' => '',
                    'phone' => '',
                    'address_fields' => [],
                ],
                'status' => '未設定',
            ],
            'mail' => [
                'smtp_host' => '',
                'smtp_port' => 587,
                'encryption' => 'tls',
                'auth' => true,
                'username' => '',
                'password' => '',
                'from_email' => '',
                'from_name' => '',
                'reply_to' => '',
                'status' => '未設定',
            ],
        ],
    ];
}

$data = readData($storageFile);

if (!$data) {
    $data = defaultData();
    writeData($storageFile, $data);
}

$data['surveys'] = $data['surveys'] ?? [];
$data['answers'] = $data['answers'] ?? [];
$data['customers'] = $data['customers'] ?? [];
$data['send_history'] = $data['send_history'] ?? [];
$data['settings'] = array_replace_recursive(
    defaultData()['settings'],
    $data['settings'] ?? []
);

function saveApp(): void
{
    global $data, $storageFile;

    if (!writeData($storageFile, $data)) {
        throw new RuntimeException('データ保存に失敗しました。');
    }
}

/* =========================================================
 * アンケート関連
 * ========================================================= */

function statusLabel(string $status): string
{
    return match ($status) {
        'draft'     => '下書き',
        'published' => '公開中',
        'stopped'   => '停止',
        'ended'     => '終了',
        default     => $status,
    };
}

function statusClass(string $status): string
{
    return match ($status) {
        'draft'     => 'badge-draft',
        'published' => 'badge-published',
        'stopped'   => 'badge-stopped',
        'ended'     => 'badge-ended',
        default     => 'badge-info',
    };
}

function normalizeSurvey(array $survey): array
{
    $survey['groups'] = $survey['groups'] ?? [];

    foreach ($survey['groups'] as &$group) {
        $group['id'] = $group['id'] ?? uuid();
        $group['title'] = $group['title'] ?? 'グループ';
        $group['questions'] = $group['questions'] ?? [];

        foreach ($group['questions'] as &$question) {
            $question['id'] = $question['id'] ?? uuid();
            $question['text'] = $question['text'] ?? '';
            $question['type'] = $question['type'] ?? 'single';
            $question['required'] = !empty($question['required']);
            $question['options'] = $question['options'] ?? [];
            $question['branches'] = $question['branches'] ?? [];
            $question['number'] = $question['number'] ?? '';
        }

        unset($question);
    }

    unset($group);

    return $survey;
}

function renumberQuestions(array &$survey): void
{
    $mode = $survey['numbering'] ?? 'global';

    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            if ($mode === 'group') {
                $question['number'] = 'Q' . $groupNo . '-' . $questionNo;
            } else {
                $question['number'] = 'Q' . $global;
            }

            $global++;
            $questionNo++;
        }

        $groupNo++;
    }

    unset($group, $question);
}

function getSurvey(string $id): ?array
{
    global $data;

    foreach ($data['surveys'] as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return normalizeSurvey($survey);
        }
    }

    return null;
}

function saveSurvey(array $survey): void
{
    global $data;

    foreach ($data['surveys'] as $i => $old) {
        if (($old['id'] ?? '') === $survey['id']) {
            $data['surveys'][$i] = $survey;
            saveApp();
            return;
        }
    }

    $data['surveys'][] = $survey;
    saveApp();
}

function updateAutomaticStatus(array &$survey): bool
{
    if (
        ($survey['status'] ?? '') === 'published' &&
        !empty($survey['end_at']) &&
        strtotime($survey['end_at']) !== false &&
        strtotime($survey['end_at']) < time()
    ) {
        $survey['status'] = 'ended';
        $survey['updated_at'] = now();
        return true;
    }

    return false;
}

function allSurveys(): array
{
    global $data;

    $changed = false;

    foreach ($data['surveys'] as &$survey) {
        if (updateAutomaticStatus($survey)) {
            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {
        saveApp();
    }

    foreach ($data['surveys'] as &$survey) {
        $survey = normalizeSurvey($survey);
    }

    unset($survey);

    return $data['surveys'];
}

function surveyAnswerCount(string $surveyId): int
{
    global $data;

    $count = 0;

    foreach ($data['answers'] as $answer) {
        if (($answer['survey_id'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}

function surveyPublicUrl(string $surveyId): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';

    return $scheme . '://' . $host .
        $script . '?' .
        http_build_query([
            'screen' => 'answer',
            'id' => $surveyId,
        ]);
}

/* =========================================================
 * kintone HTTP
 *
 * PHP cURLを使わず、stream_socket_clientを使用。
 * Proxyはhost:port形式。
 * ========================================================= */

function parseHostPort(string $value): ?array
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    if (!preg_match(
        '/^([a-zA-Z0-9._-]+|\[[0-9a-fA-F:]+\]):([0-9]{1,5})$/',
        $value,
        $m
    )) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で入力してください。'
        );
    }

    $port = (int)$m[2];

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'Proxyのポート番号が不正です。'
        );
    }

    return [
        'host' => trim($m[1], '[]'),
        'port' => $port,
    ];
}

function httpRequest(
    string $method,
    string $url,
    array $headers = [],
    ?string $body = null,
    ?array $proxy = null,
    bool $verifySsl = true,
    int $timeout = 20
): array {
    $parts = parse_url($url);

    if (!$parts || empty($parts['host'])) {
        throw new RuntimeException('URLが不正です。');
    }

    $scheme = strtolower($parts['scheme'] ?? 'http');
    $host = $parts['host'];
    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $path = ($parts['path'] ?? '/') .
        (!empty($parts['query']) ? '?' . $parts['query'] : '');

    $targetHost = $proxy['host'] ?? $host;
    $targetPort = $proxy['port'] ?? $port;

    $transport = 'tcp://' . $targetHost . ':' . $targetPort;

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => $verifySsl,
            'verify_peer_name' => $verifySsl,
            'allow_self_signed' => !$verifySsl,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ],
    ]);

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $transport,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$fp) {
        throw new RuntimeException(
            'HTTP接続に失敗しました: ' . $errstr
        );
    }

    stream_set_timeout($fp, $timeout);

    /*
     * HTTPS + HTTP Proxyの場合はCONNECTを行う。
     */
    if ($scheme === 'https' && $proxy) {
        $connectRequest =
            "CONNECT {$host}:{$port} HTTP/1.1\r\n" .
            "Host: {$host}:{$port}\r\n" .
            "Connection: close\r\n\r\n";

        fwrite($fp, $connectRequest);

        $responseHeader = '';

        while (!feof($fp)) {
            $line = fgets($fp);

            if ($line === false) {
                break;
            }

            $responseHeader .= $line;

            if (rtrim($line, "\r\n") === '') {
                break;
            }
        }

        if (!preg_match('/^HTTP\/\d(?:\.\d)?\s+200\b/m', $responseHeader)) {
            fclose($fp);
            throw new RuntimeException(
                'Proxy CONNECTに失敗しました。'
            );
        }

        $cryptoMethod = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
            ? STREAM_CRYPTO_METHOD_TLS_CLIENT
            : STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;

        if (!stream_socket_enable_crypto($fp, true, $cryptoMethod)) {
            fclose($fp);
            throw new RuntimeException(
                'TLS接続の確立に失敗しました。'
            );
        }

        $requestTarget = $path;
    } elseif ($scheme === 'https') {
        if (!stream_socket_enable_crypto(
            $fp,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        )) {
            fclose($fp);
            throw new RuntimeException(
                'TLS接続の確立に失敗しました。'
            );
        }

        $requestTarget = $path;
    } else {
        /*
         * HTTP Proxyの場合はabsolute-form。
         */
        $requestTarget = $proxy
            ? $url
            : $path;
    }

    $headerMap = [];

    foreach ($headers as $header) {
        $headerMap[] = $header;
    }

    $headerMap[] = 'Host: ' . $host;
    $headerMap[] = 'Connection: close';

    if ($body !== null) {
        $headerMap[] = 'Content-Length: ' . strlen($body);
    }

    $request =
        strtoupper($method) . ' ' . $requestTarget . " HTTP/1.1\r\n" .
        implode("\r\n", $headerMap) .
        "\r\n\r\n";

    if ($body !== null) {
        $request .= $body;
    }

    fwrite($fp, $request);

    $response = '';

    while (!feof($fp)) {
        $chunk = fread($fp, 8192);

        if ($chunk === false) {
            break;
        }

        $response .= $chunk;
    }

    fclose($fp);

    $pos = strpos($response, "\r\n\r\n");

    if ($pos === false) {
        throw new RuntimeException('HTTPレスポンスが不正です。');
    }

    $headerText = substr($response, 0, $pos);
    $bodyText = substr($response, $pos + 4);

    $lines = preg_split('/\r\n/', $headerText);

    $statusLine = array_shift($lines);

    preg_match(
        '/HTTP\/\d(?:\.\d)?\s+(\d{3})/',
        (string)$statusLine,
        $m
    );

    $status = (int)($m[1] ?? 0);

    /*
     * chunked transfer encoding。
     */
    $transferEncoding = '';

    foreach ($lines as $line) {
        if (stripos($line, 'Transfer-Encoding:') === 0) {
            $transferEncoding = trim(
                substr($line, strlen('Transfer-Encoding:'))
            );
        }
    }

    if (stripos($transferEncoding, 'chunked') !== false) {
        $decoded = '';
        $offset = 0;

        while (true) {
            $lineEnd = strpos($bodyText, "\r\n", $offset);

            if ($lineEnd === false) {
                break;
            }

            $sizeHex = substr(
                $bodyText,
                $offset,
                $lineEnd - $offset
            );

            $size = hexdec(trim($sizeHex));

            if ($size === 0) {
                break;
            }

            $offset = $lineEnd + 2;
            $decoded .= substr($bodyText, $offset, $size);
            $offset += $size + 2;
        }

        $bodyText = $decoded;
    }

    return [
        'status' => $status,
        'headers' => $headerText,
        'body' => $bodyText,
    ];
}

function kintoneBaseUrl(array $settings): string
{
    $subdomain = trim((string)$settings['subdomain']);

    if ($subdomain === '') {
        throw new RuntimeException('kintoneサブドメインが未設定です。');
    }

    if (!preg_match('/^[a-zA-Z0-9-]+$/', $subdomain)) {
        throw new RuntimeException('kintoneサブドメインが不正です。');
    }

    return 'https://' . $subdomain . '.cybozu.com';
}

function kintoneHeaders(array $settings): array
{
    $login = (string)$settings['login_name'];
    $password = (string)$settings['password'];

    if ($login === '' || $password === '') {
        throw new RuntimeException(
            'kintoneログイン名またはパスワードが未設定です。'
        );
    }

    /*
     * X-Cybozu-Authorization:
     * login_name:password をBase64化。
     *
     * この値はブラウザへ返さない。
     * ログにも出さない。
     */
    $authorization = base64_encode($login . ':' . $password);

    return [
        'X-Cybozu-Authorization: ' . $authorization,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
}

function kintoneRequest(
    string $method,
    string $path,
    array $settings,
    ?array $payload = null
): array {
    $url = kintoneBaseUrl($settings) . $path;

    $proxy = parseHostPort((string)$settings['proxy']);

    $body = null;

    if ($payload !== null) {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($body === false) {
            throw new RuntimeException('JSON生成に失敗しました。');
        }
    }

    $response = httpRequest(
        $method,
        $url,
        kintoneHeaders($settings),
        $body,
        $proxy,
        !empty($settings['verify_ssl']),
        20
    );

    if ($response['status'] < 200 || $response['status'] >= 300) {
        $decoded = json_decode($response['body'], true);

        $message = $decoded['message'] ?? 'kintone APIエラー';

        throw new RuntimeException(
            'kintone APIエラー (' .
            $response['status'] .
            '): ' .
            $message
        );
    }

    $json = json_decode($response['body'], true);

    if (!is_array($json)) {
        throw new RuntimeException(
            'kintoneから不正なレスポンスが返されました。'
        );
    }

    return $json;
}

function kintoneGetFields(array $settings): array
{
    $appId = (int)$settings['app_id'];

    if ($appId <= 0) {
        throw new RuntimeException('kintoneアプリIDが不正です。');
    }

    return kintoneRequest(
        'GET',
        '/k/v1/app/form/fields.json?app=' . $appId,
        $settings
    );
}

function kintoneGetRecords(
    array $settings,
    string $query = ''
): array {
    $appId = (int)$settings['app_id'];

    if ($appId <= 0) {
        throw new RuntimeException('kintoneアプリIDが不正です。');
    }

    $records = [];
    $offset = 0;

    do {
        $q = $query;

        if ($q !== '') {
            $q .= ' ';
        }

        $q .= 'limit 500 offset ' . $offset;

        $params = http_build_query([
            'app' => $appId,
            'query' => $q,
        ]);

        $result = kintoneRequest(
            'GET',
            '/k/v1/records.json?' . $params,
            $settings
        );

        $batch = $result['records'] ?? [];

        foreach ($batch as $record) {
            $records[] = $record;
        }

        $offset += count($batch);

        if (count($batch) < 500) {
            break;
        }
    } while ($offset < 10000);

    return $records;
}

function kintoneValue(array $record, string $code): string
{
    if ($code === '' || !isset($record[$code])) {
        return '';
    }

    $value = $record[$code]['value'] ?? '';

    if (is_array($value)) {
        $values = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                if (isset($item['name'])) {
                    $values[] = (string)$item['name'];
                } elseif (isset($item['code'])) {
                    $values[] = (string)$item['code'];
                } else {
                    $values[] = json_encode(
                        $item,
                        JSON_UNESCAPED_UNICODE
                    );
                }
            } else {
                $values[] = (string)$item;
            }
        }

        return implode(', ', $values);
    }

    return (string)$value;
}

function syncCustomersFromKintone(): int
{
    global $data;

    $settings = $data['settings']['kintone'];

    $records = kintoneGetRecords($settings);

    $mapping = $settings['mapping'];

    $customers = [];

    foreach ($records as $record) {
        $customer = [
            'id' => uuid(),
            'organization' =>
                kintoneValue($record, $mapping['organization']),
            'name' =>
                kintoneValue($record, $mapping['name']),
            'email' =>
                kintoneValue($record, $mapping['email']),
            'department' =>
                kintoneValue($record, $mapping['department']),
            'phone' =>
                kintoneValue($record, $mapping['phone']),
            'address' => '',
            'kintone_record' => $record,
            'synced_at' => now(),
        ];

        $addresses = [];

        foreach (
            ($mapping['address_fields'] ?? []) as $code
        ) {
            $v = kintoneValue($record, (string)$code);

            if ($v !== '') {
                $addresses[] = $v;
            }
        }

        $customer['address'] = implode(' ', $addresses);

        if (
            $customer['name'] === '' &&
            $customer['email'] === ''
        ) {
            continue;
        }

        $customers[] = $customer;
    }

    $data['customers'] = $customers;

    saveApp();

    return count($customers);
}

/* =========================================================
 * SMTP
 *
 * PHP mail()を使用せずSMTPへ直接接続。
 * ========================================================= */

function smtpRead($fp): string
{
    $response = '';

    while (!feof($fp)) {
        $line = fgets($fp, 8192);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (
            isset($line[3]) &&
            $line[3] === ' '
        ) {
            break;
        }
    }

    return $response;
}

function smtpCode(string $response): int
{
    return (int)substr(trim($response), 0, 3);
}

function smtpExpect($fp, array $codes): void
{
    $response = smtpRead($fp);
    $code = smtpCode($response);

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' . trim($response)
        );
    }
}

function smtpCommand(
    $fp,
    string $command,
    array $codes
): void {
    fwrite($fp, $command . "\r\n");
    smtpExpect($fp, $codes);
}

function smtpSend(
    array $settings,
    string $to,
    string $subject,
    string $body
): void {
    $host = trim((string)$settings['smtp_host']);
    $port = (int)$settings['smtp_port'];
    $encryption = (string)$settings['encryption'];

    if ($host === '') {
        throw new RuntimeException(
            'SMTPサーバが未設定です。'
        );
    }

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException(
            'SMTPポートが不正です。'
        );
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(
            '宛先メールアドレスが不正です。'
        );
    }

    $transportHost = $host;

    if ($encryption === 'ssl') {
        $transportHost = 'ssl://' . $host;
    }

    $errno = 0;
    $errstr = '';

    $fp = @fsockopen(
        $transportHost,
        $port,
        $errno,
        $errstr,
        15
    );

    if (!$fp) {
        throw new RuntimeException(
            'SMTP接続失敗: ' . $errstr
        );
    }

    stream_set_timeout($fp, 15);

    smtpExpect($fp, [220]);

    smtpCommand(
        $fp,
        'EHLO localhost',
        [250]
    );

    if ($encryption === 'tls') {
        smtpCommand(
            $fp,
            'STARTTLS',
            [220]
        );

        $cryptoMethod =
            STREAM_CRYPTO_METHOD_TLS_CLIENT;

        if (!stream_socket_enable_crypto(
            $fp,
            true,
            $cryptoMethod
        )) {
            fclose($fp);
            throw new RuntimeException(
                'SMTP TLS確立に失敗しました。'
            );
        }

        smtpCommand(
            $fp,
            'EHLO localhost',
            [250]
        );
    }

    if (!empty($settings['auth'])) {
        $username = (string)$settings['username'];
        $password = (string)$settings['password'];

        if ($username === '' || $password === '') {
            fclose($fp);
            throw new RuntimeException(
                'SMTP認証情報が未設定です。'
            );
        }

        smtpCommand(
            $fp,
            'AUTH LOGIN',
            [334]
        );

        smtpCommand(
            $fp,
            base64_encode($username),
            [334]
        );

        smtpCommand(
            $fp,
            base64_encode($password),
            [235]
        );
    }

    $from = (string)$settings['from_email'];

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        fclose($fp);
        throw new RuntimeException(
            '送信元メールアドレスが不正です。'
        );
    }

    smtpCommand(
        $fp,
        'MAIL FROM:<' . $from . '>',
        [250]
    );

    smtpCommand(
        $fp,
        'RCPT TO:<' . $to . '>',
        [250, 251]
    );

    smtpCommand(
        $fp,
        'DATA',
        [354]
    );

    $fromName = (string)$settings['from_name'];

    $encodedSubject =
        '=?UTF-8?B?' .
        base64_encode($subject) .
        '?=';

    $headers = [];

    if ($fromName !== '') {
        $encodedName =
            '=?UTF-8?B?' .
            base64_encode($fromName) .
            '?=';

        $headers[] =
            'From: ' .
            $encodedName .
            ' <' .
            $from .
            '>';
    } else {
        $headers[] = 'From: <' . $from . '>';
    }

    $headers[] = 'To: <' . $to . '>';
    $headers[] = 'Subject: ' . $encodedSubject;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';

    $replyTo = trim((string)$settings['reply_to']);

    if (
        $replyTo !== '' &&
        filter_var($replyTo, FILTER_VALIDATE_EMAIL)
    ) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $mail =
        implode("\r\n", $headers) .
        "\r\n\r\n" .
        preg_replace('/^\./m', '..', $body) .
        "\r\n.";

    fwrite($fp, $mail . "\r\n");

    smtpExpect($fp, [250]);

    smtpCommand(
        $fp,
        'QUIT',
        [221]
    );

    fclose($fp);
}

/* =========================================================
 * CSV / PDF
 * ========================================================= */

function outputCsv(string $surveyId): never
{
    global $data;

    $survey = getSurvey($surveyId);

    if (!$survey) {
        http_response_code(404);
        exit('Survey not found');
    }

    $answers = array_values(array_filter(
        $data['answers'],
        fn($a) => ($a['survey_id'] ?? '') === $surveyId
    ));

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' .
        rawurlencode($survey['title']) .
        '-answers.csv"'
    );

    echo "\xEF\xBB\xBF";

    $fp = fopen('php://output', 'w');

    $questions = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $questions[] = $question;
        }
    }

    $header = [
        '回答日時',
        '回答者',
    ];

    foreach ($questions as $question) {
        $header[] = $question['number'];
        $header[] = $question['text'];
    }

    fputcsv($fp, $header);

    foreach ($answers as $answer) {
        $row = [
            $answer['created_at'] ?? '',
            $answer['respondent'] ?? '未登録',
        ];

        $values = $answer['answers'] ?? [];

        foreach ($questions as $question) {
            $value = $values[$question['id']] ?? '';

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $row[] = $question['number'];
            $row[] = $value;
        }

        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}

function pdfEscape(string $text): string
{
    $text = preg_replace('/[^\x20-\x7E]/', '?', $text);
    $text = str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $text
    );

    return $text;
}

function outputPdf(string $surveyId): never
{
    global $data;

    $survey = getSurvey($surveyId);

    if (!$survey) {
        http_response_code(404);
        exit('Survey not found');
    }

    $answers = array_values(array_filter(
        $data['answers'],
        fn($a) => ($a['survey_id'] ?? '') === $surveyId
    ));

    /*
     * 外部PDFライブラリを使用しない簡易PDF。
     * 日本語フォント埋め込みは行わずASCII化する。
     */

    $lines = [];

    $lines[] = 'Survey Report';
    $lines[] = 'Title: ' . ($survey['title'] ?? '');
    $lines[] = 'Answers: ' . count($answers);
    $lines[] = '';

    foreach ($survey['groups'] as $group) {
        $lines[] = 'Group: ' . ($group['title'] ?? '');

        foreach ($group['questions'] as $question) {
            $lines[] =
                ($question['number'] ?? '') .
                ' ' .
                ($question['text'] ?? '');

            $count = 0;

            foreach ($answers as $answer) {
                $value = $answer['answers'][$question['id']] ?? '';

                if (is_array($value)) {
                    $value = implode(', ', $value);
                }

                if ($value !== '') {
                    $count++;
                }
            }

            $lines[] = 'Answered: ' . $count;
            $lines[] = '';
        }
    }

    $content = "BT\n/F1 10 Tf\n50 790 Td\n";

    foreach ($lines as $line) {
        $content .=
            '(' .
            pdfEscape($line) .
            ") Tj\n0 -16 Td\n";
    }

    $content .= "ET";

    $objects = [];

    $objects[] =
        "<< /Type /Catalog /Pages 2 0 R >>";

    $objects[] =
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";

    $objects[] =
        "<< /Type /Page /Parent 2 0 R " .
        "/MediaBox [0 0 595 842] " .
        "/Resources << /Font << /F1 4 0 R >> >> " .
        "/Contents 5 0 R >>";

    $objects[] =
        "<< /Type /Font /Subtype /Type1 " .
        "/BaseFont /Helvetica >>";

    $objects[] =
        "<< /Length " .
        strlen($content) .
        " >>\nstream\n" .
        $content .
        "\nendstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $i => $object) {
        $offsets[$i + 1] = strlen($pdf);

        $pdf .=
            ($i + 1) .
            " 0 obj\n" .
            $object .
            "\nendobj\n";
    }

    $xref = strlen($pdf);

    $pdf .=
        "xref\n0 " .
        (count($objects) + 1) .
        "\n0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf(
            "%010d 00000 n \n",
            $offsets[$i]
        );
    }

    $pdf .=
        "trailer\n" .
        "<< /Size " .
        (count($objects) + 1) .
        " /Root 1 0 R >>\n" .
        "startxref\n" .
        $xref .
        "\n%%EOF";

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="survey-' .
        $surveyId .
        '.pdf"'
    );

    echo $pdf;
    exit;
}

/* =========================================================
 * POST処理
 * ========================================================= */

verifyCsrf();

$screen = $_GET['screen'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* -------------------------
     * ログイン
     * ------------------------- */

    if ($action === 'login') {
        $user = (string)($_POST['username'] ?? '');
        $pass = (string)($_POST['password'] ?? '');

        if (
            hash_equals($adminUser, $user) &&
            hash_equals($adminPassword, $pass)
        ) {
            session_regenerate_id(true);

            $_SESSION['admin_authenticated'] = true;
            $_SESSION['admin_user'] = $user;

            /*
             * セッションID再生成後もCSRFトークンを
             * 再生成する。
             */
            $_SESSION['csrf_token'] =
                bin2hex(random_bytes(32));

            flash('ログインしました。', 'success');

            redirect(baseUrl(['screen' => 'list']));
        }

        flash('ユーザー名またはパスワードが正しくありません。', 'danger');

        redirect(baseUrl(['screen' => 'login']));
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);

        redirect(baseUrl(['screen' => 'login']));
    }

    /* -------------------------
     * 回答送信
     * ------------------------- */

    if ($action === 'submit_answer') {
        $surveyId = (string)($_POST['survey_id'] ?? '');

        $survey = getSurvey($surveyId);

        if (!$survey) {
            http_response_code(404);
            exit('アンケートが存在しません。');
        }

        if (($survey['status'] ?? '') !== 'published') {
            exit('このアンケートは回答できません。');
        }

        if (
            !empty($survey['end_at']) &&
            strtotime($survey['end_at']) < time()
        ) {
            exit('回答期間が終了しています。');
        }

        $answers = $_POST['answers'] ?? [];

        if (!is_array($answers)) {
            $answers = [];
        }

        $errors = [];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                if (!$question['required']) {
                    continue;
                }

                $value = $answers[$question['id']] ?? '';

                if (is_array($value)) {
                    $value = array_filter(
                        $value,
                        fn($v) => trim((string)$v) !== ''
                    );
                }

                if (
                    $value === '' ||
                    $value === null ||
                    $value === []
                ) {
                    $errors[] =
                        ($question['number'] ?? '') .
                        ' は必須項目です。';
                }
            }
        }

        if ($errors) {
            $_SESSION['answer_errors'] = $errors;
            $_SESSION['answer_values'] = $answers;

            redirect(baseUrl([
                'screen' => 'answer',
                'id' => $surveyId,
            ]));
        }

        $respondent = trim(
            (string)($_POST['respondent'] ?? '')
        );

        $data['answers'][] = [
            'id' => uuid(),
            'survey_id' => $surveyId,
            'respondent' =>
                $respondent !== ''
                    ? $respondent
                    : '未登録',
            'answers' => $answers,
            'created_at' => now(),
        ];

        saveApp();

        $_SESSION['completed_answer'] = true;

        redirect(baseUrl([
            'screen' => 'complete',
            'id' => $surveyId,
        ]));
    }

    /* 以下は管理者処理 */
    requireAdmin();

    /* -------------------------
     * アンケート保存
     * ------------------------- */

    if ($action === 'save_survey') {
        $id = trim((string)($_POST['id'] ?? ''));

        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim(
            (string)($_POST['description'] ?? '')
        );

        if ($title === '') {
            flash(
                'アンケートタイトルを入力してください。',
                'danger'
            );

            redirect(baseUrl([
                'screen' => 'edit',
                'id' => $id,
            ]));
        }

        if ($id !== '') {
            $survey = getSurvey($id);

            if (!$survey) {
                flash('アンケートが存在しません。', 'danger');
                redirect(baseUrl(['screen' => 'list']));
            }
        } else {
            $survey = [
                'id' => uuid(),
                'title' => '',
                'description' => '',
                'start_at' => '',
                'end_at' => '',
                'status' => 'draft',
                'numbering' => 'global',
                'groups' => [],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $survey['title'] = $title;
        $survey['description'] = $description;
        $survey['start_at'] =
            trim((string)($_POST['start_at'] ?? ''));
        $survey['end_at'] =
            trim((string)($_POST['end_at'] ?? ''));
        $survey['numbering'] =
            $_POST['numbering'] === 'group'
                ? 'group'
                : 'global';

        $survey['updated_at'] = now();

        if (empty($survey['groups'])) {
            $survey['groups'][] = [
                'id' => uuid(),
                'title' => '基本情報',
                'questions' => [
                    [
                        'id' => uuid(),
                        'text' => 'ご意見を入力してください。',
                        'type' => 'text',
                        'required' => false,
                        'options' => [],
                        'branches' => [],
                        'number' => '',
                    ],
                ],
            ];
        }

        renumberQuestions($survey);

        saveSurvey($survey);

        flash(
            'アンケートを保存しました。',
            'success'
        );

        redirect(baseUrl(['screen' => 'list']));
    }

    /* -------------------------
     * 状態変更
     * ------------------------- */

    if ($action === 'change_status') {
        $id = (string)($_POST['id'] ?? '');
        $newStatus = (string)($_POST['status'] ?? '');

        $survey = getSurvey($id);

        if (!$survey) {
            flash('アンケートが存在しません。', 'danger');
            redirect(baseUrl(['screen' => 'list']));
        }

        $current = $survey['status'];

        $allowed = [
            'draft' => ['published'],
            'published' => ['stopped'],
            'stopped' => ['published'],
            'ended' => [],
        ];

        if (
            !isset($allowed[$current]) ||
            !in_array($newStatus, $allowed[$current], true)
        ) {
            flash(
                '許可されていない状態変更です。',
                'danger'
            );

            redirect(baseUrl(['screen' => 'list']));
        }

        $survey['status'] = $newStatus;
        $survey['updated_at'] = now();

        saveSurvey($survey);

        flash(
            '状態を変更しました。',
            'success'
        );

        redirect(baseUrl(['screen' => 'list']));
    }

    /* -------------------------
     * 削除
     * ------------------------- */

    if ($action === 'delete_survey') {
        $id = (string)($_POST['id'] ?? '');

        foreach ($data['surveys'] as $i => $survey) {
            if (($survey['id'] ?? '') === $id) {
                array_splice($data['surveys'], $i, 1);
                break;
            }
        }

        saveApp();

        flash(
            'アンケートを削除しました。',
            'success'
        );

        redirect(baseUrl(['screen' => 'list']));
    }

    /* -------------------------
     * 複製
     * ------------------------- */

    if ($action === 'duplicate_survey') {
        $id = (string)($_POST['id'] ?? '');

        $survey = getSurvey($id);

        if ($survey) {
            $survey['id'] = uuid();
            $survey['title'] .= '（複製）';
            $survey['status'] = 'draft';
            $survey['created_at'] = now();
            $survey['updated_at'] = now();

            foreach ($survey['groups'] as &$group) {
                $group['id'] = uuid();

                foreach ($group['questions'] as &$question) {
                    $question['id'] = uuid();
                }

                unset($question);
            }

            unset($group);

            saveSurvey($survey);

            flash(
                'アンケートを複製しました。',
                'success'
            );
        }

        redirect(baseUrl(['screen' => 'list']));
    }

    /* -------------------------
     * kintone設定保存
     * ------------------------- */

    if ($action === 'save_kintone') {
        $s =& $data['settings']['kintone'];

        $s['subdomain'] =
            trim((string)($_POST['subdomain'] ?? ''));

        $s['app_id'] =
            (string)(int)($_POST['app_id'] ?? 0);

        $s['login_name'] =
            trim((string)($_POST['login_name'] ?? ''));

        /*
         * パスワードが空欄なら既存値を維持。
         */
        $password =
            (string)($_POST['password'] ?? '');

        if ($password !== '') {
            $s['password'] = $password;
        }

        $s['proxy'] =
            trim((string)($_POST['proxy'] ?? ''));

        $s['verify_ssl'] =
            !empty($_POST['verify_ssl']);

        $s['mapping']['organization'] =
            trim((string)($_POST['mapping_organization'] ?? ''));

        $s['mapping']['name'] =
            trim((string)($_POST['mapping_name'] ?? ''));

        $s['mapping']['email'] =
            trim((string)($_POST['mapping_email'] ?? ''));

        $s['mapping']['department'] =
            trim((string)($_POST['mapping_department'] ?? ''));

        $s['mapping']['phone'] =
            trim((string)($_POST['mapping_phone'] ?? ''));

        $s['mapping']['address_fields'] =
            array_values(
                array_filter(
                    (array)($_POST['address_fields'] ?? [])
                )
            );

        if ($s['proxy'] !== '') {
            parseHostPort($s['proxy']);
        }

        saveApp();

        flash(
            'kintone設定を保存しました。',
            'success'
        );

        redirect(baseUrl(['screen' => 'kintone']));
    }

    /* -------------------------
     * kintone接続テスト
     * ------------------------- */

    if ($action === 'test_kintone') {
        try {
            $result = kintoneGetFields(
                $data['settings']['kintone']
            );

            $data['settings']['kintone']['status'] =
                '接続確認済み';

            saveApp();

            flash(
                'kintoneへの接続に成功しました。項目数: ' .
                count($result['properties'] ?? []),
                'success'
            );
        } catch (Throwable $e) {
            /*
             * 認証情報そのものはエラー表示しない。
             */
            flash(
                'kintone接続に失敗しました: ' .
                $e->getMessage(),
                'danger'
            );
        }

        redirect(baseUrl(['screen' => 'kintone']));
    }

    /* -------------------------
     * kintone項目再取得
     * ------------------------- */

    if ($action === 'refresh_kintone_fields') {
        try {
            $result = kintoneGetFields(
                $data['settings']['kintone']
            );

            $_SESSION['kintone_fields'] =
                $result['properties'] ?? [];

            flash(
                'kintone項目を取得しました。',
                'success'
            );
        } catch (Throwable $e) {
            flash(
                '項目取得に失敗しました: ' .
                $e->getMessage(),
                'danger'
            );
        }

        redirect(baseUrl(['screen' => 'kintone']));
    }

    /* -------------------------
     * kintone顧客同期
     * ------------------------- */

    if ($action === 'sync_kintone') {
        try {
            $count = syncCustomersFromKintone();

            $data['settings']['kintone']['status'] =
                '接続確認済み';

            saveApp();

            flash(
                '顧客情報を同期しました。件数: ' .
                $count,
                'success'
            );
        } catch (Throwable $e) {
            flash(
                '顧客同期に失敗しました: ' .
                $e->getMessage(),
                'danger'
            );
        }

        redirect(baseUrl(['screen' => 'kintone']));
    }

    /* -------------------------
     * SMTP設定保存
     * ------------------------- */

    if ($action === 'save_mail') {
        $s =& $data['settings']['mail'];

        $s['smtp_host'] =
            trim((string)($_POST['smtp_host'] ?? ''));

        $s['smtp_port'] =
            (int)($_POST['smtp_port'] ?? 587);

        $s['encryption'] =
            in_array(
                $_POST['encryption'] ?? '',
                ['ssl', 'tls', 'none'],
                true
            )
                ? $_POST['encryption']
                : 'tls';

        $s['auth'] =
            !empty($_POST['auth']);

        $s['username'] =
            trim((string)($_POST['username'] ?? ''));

        $password =
            (string)($_POST['mail_password'] ?? '');

        if ($password !== '') {
            $s['password'] = $password;
        }

        $s['from_email'] =
            trim((string)($_POST['from_email'] ?? ''));

        $s['from_name'] =
            trim((string)($_POST['from_name'] ?? ''));

        $s['reply_to'] =
            trim((string)($_POST['reply_to'] ?? ''));

        saveApp();

        flash(
            'メール設定を保存しました。',
            'success'
        );

        redirect(baseUrl(['screen' => 'mail']));
    }

    /* -------------------------
     * SMTP接続テスト / テストメール
     * ------------------------- */

    if ($action === 'test_mail') {
        try {
            $to = trim(
                (string)($_POST['test_email'] ?? '')
            );

            smtpSend(
                $data['settings']['mail'],
                $to,
                'アンケート管理システム テストメール',
                "SMTP接続テストに成功しました。\n\n" .
                '送信日時: ' . now()
            );

            $data['settings']['mail']['status'] =
                '接続確認済み';

            saveApp();

            flash(
                'テストメールを送信しました。',
                'success'
            );
        } catch (Throwable $e) {
            $data['settings']['mail']['status'] =
                '接続できません';

            saveApp();

            flash(
                'メール送信に失敗しました: ' .
                $e->getMessage(),
                'danger'
            );
        }

        redirect(baseUrl(['screen' => 'mail']));
    }

    /* -------------------------
     * アンケート送信
     * ------------------------- */

    if ($action === 'send_mail') {
        $surveyId = (string)($_POST['survey_id'] ?? '');

        $survey = getSurvey($surveyId);

        if (!$survey) {
            flash(
                'アンケートが存在しません。',
                'danger'
            );

            redirect(baseUrl(['screen' => 'list']));
        }

        $customerIds =
            array_values(
                array_filter(
                    (array)($_POST['customer_ids'] ?? [])
                )
            );

        $subject =
            trim((string)($_POST['subject'] ?? ''));

        $body =
            (string)($_POST['body'] ?? '');

        if ($subject === '') {
            flash(
                'メール件名を入力してください。',
                'danger'
            );

            redirect(baseUrl([
                'screen' => 'send',
                'id' => $surveyId,
            ]));
        }

        if (!$customerIds) {
            flash(
                '送信対象を選択してください。',
                'danger'
            );

            redirect(baseUrl([
                'screen' => 'send',
                'id' => $surveyId,
            ]));
        }

        $success = 0;
        $failed = 0;

        foreach ($data['customers'] as $customer) {
            if (
                !in_array(
                    $customer['id'],
                    $customerIds,
                    true
                )
            ) {
                continue;
            }

            $email = trim(
                (string)($customer['email'] ?? '')
            );

            if (!filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )) {
                $failed++;

                $data['send_history'][] = [
                    'id' => uuid(),
                    'survey_id' => $surveyId,
                    'customer_id' => $customer['id'],
                    'email' => $email,
                    'status' => 'failed',
                    'message' => 'メールアドレス不正',
                    'created_at' => now(),
                ];

                continue;
            }

            $mailBody = str_replace(
                [
                    '{顧客名}',
                    '{アンケートURL}',
                ],
                [
                    (string)($customer['name'] ?? ''),
                    surveyPublicUrl($surveyId),
                ],
                $body
            );

            try {
                smtpSend(
                    $data['settings']['mail'],
                    $email,
                    $subject,
                    $mailBody
                );

                $success++;

                $data['send_history'][] = [
                    'id' => uuid(),
                    'survey_id' => $surveyId,
                    'customer_id' => $customer['id'],
                    'email' => $email,
                    'status' => 'sent',
                    'message' => '送信成功',
                    'created_at' => now(),
                ];
            } catch (Throwable $e) {
                $failed++;

                $data['send_history'][] = [
                    'id' => uuid(),
                    'survey_id' => $surveyId,
                    'customer_id' => $customer['id'],
                    'email' => $email,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                    'created_at' => now(),
                ];
            }
        }

        saveApp();

        flash(
            '送信完了：成功 ' .
            $success .
            '件 / 失敗 ' .
            $failed .
            '件',
            $failed > 0 ? 'warning' : 'success'
        );

        redirect(baseUrl([
            'screen' => 'send',
            'id' => $surveyId,
        ]));
    }

    /* -------------------------
     * 設問編集
     * ------------------------- */

    if ($action === 'save_structure') {
        $surveyId = (string)($_POST['survey_id'] ?? '');

        $survey = getSurvey($surveyId);

        if (!$survey) {
            flash('アンケートが存在しません。', 'danger');
            redirect(baseUrl(['screen' => 'list']));
        }

        $rawGroups = $_POST['groups'] ?? [];

        if (!is_array($rawGroups)) {
            $rawGroups = [];
        }

        $groups = [];

        foreach ($rawGroups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $groupTitle =
                trim((string)($group['title'] ?? ''));

            if ($groupTitle === '') {
                $groupTitle = 'グループ';
            }

            $newGroup = [
                'id' =>
                    (string)($group['id'] ?? uuid()),
                'title' => $groupTitle,
                'questions' => [],
            ];

            foreach (
                ($group['questions'] ?? []) as $question
            ) {
                if (!is_array($question)) {
                    continue;
                }

                $type =
                    (string)($question['type'] ?? 'single');

                if (!in_array(
                    $type,
                    ['single', 'multiple', 'text'],
                    true
                )) {
                    $type = 'single';
                }

                $options = [];

                foreach (
                    ($question['options'] ?? []) as $option
                ) {
                    $option = trim((string)$option);

                    if ($option !== '') {
                        $options[] = $option;
                    }
                }

                $newGroup['questions'][] = [
                    'id' =>
                        (string)($question['id'] ?? uuid()),
                    'text' =>
                        trim((string)($question['text'] ?? '')),
                    'type' => $type,
                    'required' =>
                        !empty($question['required']),
                    'options' => $options,
                    'branches' =>
                        is_array($question['branches'] ?? null)
                            ? $question['branches']
                            : [],
                    'number' => '',
                ];
            }

            $groups[] = $newGroup;
        }

        $survey['groups'] = $groups;

        renumberQuestions($survey);

        $survey['updated_at'] = now();

        saveSurvey($survey);

        flash(
            '質問構成を保存しました。',
            'success'
        );

        redirect(baseUrl([
            'screen' => 'edit',
            'id' => $surveyId,
        ]));
    }

    /* -------------------------
     * 公開URL再送等
     * ------------------------- */
}

/* =========================================================
 * CSV / PDF
 * ========================================================= */

requireAdmin();

if ($screen === 'csv') {
    outputCsv(
        (string)($_GET['id'] ?? '')
    );
}

if ($screen === 'pdf') {
    outputPdf(
        (string)($_GET['id'] ?? '')
    );
}

/* =========================================================
 * HTML共通
 * ========================================================= */

$flashes = consumeFlash();

function renderHeader(
    string $title,
    string $active = 'list'
): void {
    global $flashes;

    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta
            name="viewport"
            content="width=device-width, initial-scale=1"
        >
        <title>
            <?= e($title) ?> - <?= e(APP_NAME) ?>
        </title>

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
          font-family:
            -apple-system,
            BlinkMacSystemFont,
            "Segoe UI",
            "Noto Sans JP",
            "Hiragino Kaku Gothic ProN",
            Meiryo,
            sans-serif;
          color:var(--text);
          background:#f8fafc;
        }

        button,input,textarea,select{
          font:inherit;
        }

        button{
          cursor:pointer;
        }

        .hidden{
          display:none!important;
        }

        .admin-header{
          position:sticky;
          top:0;
          z-index:50;
          min-height:64px;
          background:#0f172a;
          color:#fff;
          display:flex;
          align-items:center;
          padding:0 24px;
          gap:28px;
          box-shadow:0 2px 10px rgba(0,0,0,.12);
        }

        .admin-logo{
          font-weight:700;
          white-space:nowrap;
          font-size:18px;
        }

        .admin-nav{
          display:flex;
          gap:4px;
          height:100%;
          align-items:center;
        }

        .admin-nav a{
          height:40px;
          padding:10px 14px;
          border-radius:7px;
          color:#cbd5e1;
          text-decoration:none;
          white-space:nowrap;
        }

        .admin-nav a:hover,
        .admin-nav a.active{
          background:#1e293b;
          color:#fff;
        }

        .admin-spacer{
          flex:1;
        }

        .page{
          max-width:1500px;
          margin:0 auto;
          padding:28px;
        }

        .page-title{
          display:flex;
          align-items:center;
          justify-content:space-between;
          gap:16px;
          margin-bottom:24px;
        }

        .page-title h1{
          margin:0;
          font-size:26px;
        }

        .page-title p{
          margin:5px 0 0;
          color:var(--gray);
          font-size:13px;
        }

        .card{
          background:#fff;
          border:1px solid var(--border);
          border-radius:12px;
          box-shadow:var(--shadow);
        }

        .card-header{
          padding:18px 20px;
          border-bottom:1px solid var(--border);
          display:flex;
          justify-content:space-between;
          align-items:center;
          gap:12px;
        }

        .card-body{
          padding:20px;
        }

        .btn{
          border:1px solid var(--border);
          background:#fff;
          color:var(--text);
          border-radius:7px;
          padding:9px 14px;
          min-height:40px;
          text-decoration:none;
          display:inline-flex;
          align-items:center;
          justify-content:center;
        }

        .btn:hover{
          background:#f8fafc;
        }

        .btn-primary{
          background:var(--primary);
          color:#fff;
          border-color:var(--primary);
        }

        .btn-primary:hover{
          background:var(--primary-dark);
        }

        .btn-success{
          background:var(--success);
          color:#fff;
          border-color:var(--success);
        }

        .btn-danger{
          background:var(--danger);
          color:#fff;
          border-color:var(--danger);
        }

        .btn-warning{
          background:var(--warning);
          color:#fff;
          border-color:var(--warning);
        }

        .btn-sm{
          min-height:32px;
          padding:5px 9px;
          font-size:12px;
        }

        .badge{
          display:inline-flex;
          align-items:center;
          padding:4px 9px;
          border-radius:999px;
          font-size:12px;
          font-weight:600;
          white-space:nowrap;
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
          background:#fef3c7;
          color:#92400e;
        }

        .badge-ended{
          background:#fee2e2;
          color:#991b1b;
        }

        .badge-success{
          background:#dcfce7;
          color:#166534;
        }

        .badge-danger{
          background:#fee2e2;
          color:#991b1b;
        }

        .badge-info{
          background:#dbeafe;
          color:#1d4ed8;
        }

        .form-grid{
          display:grid;
          grid-template-columns:
            repeat(2,minmax(0,1fr));
          gap:18px;
        }

        .form-group{
          display:flex;
          flex-direction:column;
          gap:7px;
        }

        .form-group.full{
          grid-column:1/-1;
        }

        label{
          font-weight:600;
          font-size:13px;
        }

        input[type=text],
        input[type=email],
        input[type=password],
        input[type=datetime-local],
        input[type=number],
        textarea,
        select{
          width:100%;
          border:1px solid #cbd5e1;
          border-radius:7px;
          padding:10px 12px;
          background:#fff;
          color:var(--text);
        }

        textarea{
          resize:vertical;
          min-height:100px;
        }

        .help{
          color:var(--gray);
          font-size:12px;
        }

        .actions{
          display:flex;
          align-items:center;
          gap:8px;
          flex-wrap:wrap;
        }

        .toolbar{
          display:flex;
          gap:10px;
          flex-wrap:wrap;
          align-items:center;
          margin-bottom:16px;
        }

        .search-box{
          display:flex;
          flex:1;
          min-width:260px;
        }

        .search-box input{
          border-radius:7px 0 0 7px;
        }

        .search-box button{
          border:1px solid #cbd5e1;
          border-left:0;
          background:#f8fafc;
          border-radius:0 7px 7px 0;
          padding:0 16px;
        }

        .table-wrap{
          overflow-x:auto;
        }

        table{
          width:100%;
          border-collapse:collapse;
          min-width:1100px;
        }

        th,td{
          padding:13px 12px;
          border-bottom:1px solid #e2e8f0;
          text-align:left;
          vertical-align:middle;
          font-size:13px;
        }

        th{
          background:#f8fafc;
          font-weight:700;
          color:#475569;
        }

        tbody tr:hover{
          background:#f8fafc;
        }

        .action-grid{
          display:flex;
          flex-wrap:wrap;
          gap:5px;
        }

        .empty{
          padding:45px 20px;
          text-align:center;
          color:var(--gray);
        }

        .editor-topbar{
          position:sticky;
          top:64px;
          z-index:20;
          background:#fff;
          border:1px solid var(--border);
          border-radius:10px;
          padding:14px 16px;
          margin-bottom:20px;
          display:flex;
          align-items:center;
          gap:12px;
          box-shadow:var(--shadow);
        }

        .editor-topbar .state-area{
          margin-left:auto;
          display:flex;
          align-items:center;
          gap:8px;
        }

        .editor-topbar select{
          width:auto;
          min-width:145px;
        }

        .section{
          margin-bottom:20px;
        }

        .section-title{
          margin:0 0 15px;
          font-size:18px;
        }

        .group{
          margin-bottom:14px;
          border:1px solid var(--border);
          border-radius:10px;
          background:#f8fafc;
        }

        .group-header{
          padding:12px;
          display:flex;
          gap:8px;
          align-items:center;
          border-bottom:1px solid var(--border);
        }

        .drag-handle{
          cursor:grab;
          color:#64748b;
          font-size:18px;
        }

        .group-title-input{
          flex:1;
          font-weight:700;
        }

        .question-list{
          padding:12px;
          display:flex;
          flex-direction:column;
          gap:10px;
        }

        .question{
          background:#fff;
          border:1px solid var(--border);
          border-radius:9px;
          padding:13px;
        }

        .question-header{
          display:flex;
          align-items:center;
          gap:8px;
          margin-bottom:10px;
        }

        .question-number{
          font-weight:700;
          color:var(--primary);
          min-width:55px;
        }

        .question-text{
          flex:1;
        }

        .question-body{
          display:grid;
          grid-template-columns:
            1fr 180px 110px;
          gap:10px;
          align-items:start;
        }

        .question-options{
          margin-top:10px;
          padding-left:63px;
        }

        .option-row{
          display:flex;
          gap:7px;
          margin-bottom:7px;
        }

        .option-row input{
          flex:1;
        }

        .branch-box{
          margin-top:10px;
          margin-left:63px;
          padding:10px;
          background:#eff6ff;
          border:1px solid #bfdbfe;
          border-radius:7px;
        }

        .add-area{
          padding:0 12px 12px;
        }

        .group-add{
          margin-top:10px;
        }

        .target-banner{
          background:#eff6ff;
          border:1px solid #bfdbfe;
          border-radius:10px;
          padding:15px 18px;
          margin-bottom:20px;
        }

        .target-banner .label{
          color:#1d4ed8;
          font-size:12px;
          font-weight:700;
        }

        .target-banner .title{
          font-size:18px;
          font-weight:700;
          margin-top:4px;
        }

        .send-tabs{
          display:flex;
          border-bottom:1px solid var(--border);
          margin-bottom:18px;
        }

        .send-tab{
          border:0;
          background:none;
          padding:12px 18px;
          border-bottom:3px solid transparent;
          color:#64748b;
          text-decoration:none;
        }

        .send-tab.active{
          color:var(--primary);
          border-bottom-color:var(--primary);
          font-weight:700;
        }

        .customer-table{
          min-width:1200px;
        }

        .template-grid{
          display:grid;
          grid-template-columns:1fr 1fr;
          gap:18px;
        }

        .mail-preview{
          white-space:pre-wrap;
          background:#f8fafc;
          border:1px solid var(--border);
          border-radius:8px;
          padding:15px;
          min-height:170px;
        }

        .history-detail{
          background:#f8fafc;
          padding:15px;
          border-radius:8px;
          margin-top:10px;
        }

        .summary-grid{
          display:grid;
          grid-template-columns:
            repeat(5,1fr);
          gap:12px;
          margin-bottom:20px;
        }

        .summary-card{
          background:#fff;
          border:1px solid var(--border);
          border-radius:10px;
          padding:16px;
        }

        .summary-card .number{
          font-size:27px;
          font-weight:700;
          margin-top:5px;
        }

        .bar{
          height:22px;
          border-radius:5px;
          background:#e2e8f0;
          overflow:hidden;
        }

        .bar>span{
          display:block;
          height:100%;
          background:var(--primary);
        }

        .answer-list{
          display:flex;
          flex-direction:column;
          gap:10px;
        }

        .answer-item{
          border:1px solid var(--border);
          border-radius:8px;
          padding:12px;
        }

        .settings-grid{
          display:grid;
          grid-template-columns:1fr 1fr;
          gap:20px;
        }

        .mapping{
          display:grid;
          grid-template-columns:180px 1fr;
          gap:10px;
          align-items:center;
        }

        .address-checks{
          display:grid;
          grid-template-columns:
            repeat(2,1fr);
          gap:8px;
        }

        .address-checks label{
          font-weight:400;
          display:flex;
          gap:7px;
        }

        .status-box{
          margin-top:15px;
          padding:14px;
          border-radius:8px;
          background:#f8fafc;
          border:1px solid var(--border);
        }

        .preview-device{
          max-width:900px;
          margin:0 auto;
          background:#fff;
          border:1px solid var(--border);
          border-radius:12px;
          box-shadow:var(--shadow);
          padding:30px;
        }

        .preview-device.mobile{
          max-width:390px;
        }

        .preview-question{
          margin:25px 0;
        }

        .preview-option{
          display:block;
          padding:12px;
          border:1px solid #cbd5e1;
          border-radius:8px;
          margin-bottom:8px;
        }

        .respondent{
          min-height:100vh;
          background:#f8fafc;
        }

        .respondent-header{
          background:#fff;
          border-bottom:1px solid var(--border);
          padding:20px;
        }

        .respondent-header-inner{
          max-width:760px;
          margin:auto;
        }

        .respondent-main{
          max-width:760px;
          margin:25px auto;
          padding:0 16px 50px;
        }

        .respondent-card{
          background:#fff;
          border:1px solid var(--border);
          border-radius:12px;
          padding:25px;
          box-shadow:var(--shadow);
        }

        .respondent-question{
          margin:0 0 28px;
        }

        .required{
          color:var(--danger);
          font-size:12px;
          margin-left:5px;
        }

        .respondent-option{
          display:block;
          padding:13px;
          border:1px solid #cbd5e1;
          border-radius:8px;
          margin:8px 0;
        }

        .respondent-actions{
          display:flex;
          justify-content:space-between;
          gap:10px;
          margin-top:25px;
        }

        .alert{
          padding:13px 16px;
          border-radius:8px;
          margin-bottom:12px;
        }

        .alert-success{
          background:#dcfce7;
          color:#166534;
        }

        .alert-danger{
          background:#fee2e2;
          color:#991b1b;
        }

        .alert-warning{
          background:#fef3c7;
          color:#92400e;
        }

        .alert-info{
          background:#dbeafe;
          color:#1d4ed8;
        }

        .login-wrap{
          min-height:100vh;
          display:flex;
          justify-content:center;
          align-items:center;
          padding:20px;
        }

        .login-card{
          width:min(430px,100%);
          padding:28px;
          background:#fff;
          border:1px solid var(--border);
          border-radius:12px;
          box-shadow:var(--shadow);
        }

        .login-card h1{
          margin-top:0;
        }

        @media(max-width:1000px){
          .summary-grid{
            grid-template-columns:repeat(2,1fr);
          }

          .settings-grid,
          .template-grid,
          .form-grid{
            grid-template-columns:1fr;
          }

          .question-body{
            grid-template-columns:1fr;
          }

          .question-options,
          .branch-box{
            margin-left:0;
            padding-left:0;
          }
        }

        @media(max-width:700px){
          .admin-header{
            min-height:60px;
            padding:10px 14px;
            flex-wrap:wrap;
            gap:7px;
          }

          .admin-nav{
            order:3;
            width:100%;
            overflow-x:auto;
          }

          .page{
            padding:16px;
          }

          .editor-topbar{
            top:0;
            flex-wrap:wrap;
          }

          .editor-topbar .state-area{
            margin-left:0;
            width:100%;
          }

          .summary-grid{
            grid-template-columns:1fr 1fr;
          }

          .respondent-card{
            padding:18px;
          }
        }
        </style>
    </head>

    <body>

    <header class="admin-header">
        <div class="admin-logo">
            <?= e(APP_NAME) ?>
        </div>

        <nav class="admin-nav">
            <a
                class="<?= $active === 'list' ? 'active' : '' ?>"
                href="<?= e(baseUrl(['screen'=>'list'])) ?>"
            >
                アンケート
            </a>

            <a
                class="<?= $active === 'kintone' ? 'active' : '' ?>"
                href="<?= e(baseUrl(['screen'=>'kintone'])) ?>"
            >
                kintone
            </a>

            <a
                class="<?= $active === 'mail' ? 'active' : '' ?>"
                href="<?= e(baseUrl(['screen'=>'mail'])) ?>"
            >
                メール
            </a>
        </nav>

        <div class="admin-spacer"></div>

        <form
            method="post"
            action="<?= e(baseUrl()) ?>"
            onsubmit="return confirm('ログアウトしますか？')"
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrfToken()) ?>"
            >
            <input
                type="hidden"
                name="action"
                value="logout"
            >
            <button
                class="btn btn-sm"
                type="submit"
            >
                ログアウト
            </button>
        </form>
    </header>

    <main class="page">

    <?php foreach ($flashes as $flash): ?>
        <div
            class="alert alert-<?= e($flash['type']) ?>"
        >
            <?= e($flash['message']) ?>
        </div>
    <?php endforeach; ?>

    <?php
}

function renderFooter(): void
{
    ?>
    </main>

    <script>
    function confirmAction(message) {
        return window.confirm(message);
    }

    function filterTable(inputId, tableId) {
        const input =
            document.getElementById(inputId);

        const table =
            document.getElementById(tableId);

        if (!input || !table) {
            return;
        }

        const value =
            input.value.toLowerCase();

        table
            .querySelectorAll('tbody tr')
            .forEach(function(row) {
                row.style.display =
                    row.innerText
                        .toLowerCase()
                        .includes(value)
                        ? ''
                        : 'none';
            });
    }

    document
        .querySelectorAll('[data-confirm]')
        .forEach(function(el) {
            el.addEventListener('click', function(ev) {
                if (
                    !window.confirm(
                        el.dataset.confirm
                    )
                ) {
                    ev.preventDefault();
                }
            });
        });
    </script>

    </body>
    </html>
    <?php
}

/* =========================================================
 * ログイン画面
 * ========================================================= */

if (!isAdmin()) {
    renderLogin($flashes);
    exit;
}

function renderLogin(array $flashes): void
{
    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta
            name="viewport"
            content="width=device-width,initial-scale=1"
        >
        <title><?= e(APP_NAME) ?></title>

        <style>
        *{box-sizing:border-box}

        body{
            margin:0;
            min-height:100vh;
            background:#f8fafc;
            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                "Noto Sans JP",
                Meiryo,
                sans-serif;
            color:#1e293b;
        }

        .wrap{
            min-height:100vh;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:20px;
        }

        .card{
            width:min(430px,100%);
            background:#fff;
            padding:30px;
            border-radius:12px;
            border:1px solid #dbe2ea;
            box-shadow:0 4px 18px rgba(15,23,42,.08);
        }

        h1{
            margin-top:0;
            font-size:23px;
        }

        label{
            display:block;
            font-weight:600;
            margin:16px 0 7px;
        }

        input{
            width:100%;
            padding:11px;
            border:1px solid #cbd5e1;
            border-radius:7px;
        }

        button{
            width:100%;
            margin-top:20px;
            padding:12px;
            border:0;
            border-radius:7px;
            background:#2563eb;
            color:#fff;
            font-weight:700;
            cursor:pointer;
        }

        .alert{
            padding:12px;
            border-radius:8px;
            margin-bottom:10px;
            background:#fee2e2;
            color:#991b1b;
        }
        </style>
    </head>

    <body>
    <div class="wrap">
        <div class="card">

            <h1><?= e(APP_NAME) ?></h1>

            <?php foreach ($flashes as $flash): ?>
                <div class="alert">
                    <?= e($flash['message']) ?>
                </div>
            <?php endforeach; ?>

            <form method="post">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrfToken()) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="login"
                >

                <label>ユーザー名</label>
                <input
                    type="text"
                    name="username"
                    autocomplete="username"
                    required
                >

                <label>パスワード</label>
                <input
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >

                <button type="submit">
                    ログイン
                </button>
            </form>

        </div>
    </div>
    </body>
    </html>
    <?php
}

/* =========================================================
 * 一覧
 * ========================================================= */

if ($screen === 'list') {
    $surveys = allSurveys();

    $keyword = trim(
        (string)($_GET['q'] ?? '')
    );

    $filter =
        (string)($_GET['filter'] ?? 'all');

    $sort =
        (string)($_GET['sort'] ?? 'updated_desc');

    if ($keyword !== '') {
        $surveys = array_filter(
            $surveys,
            fn($survey) =>
                mb_stripos(
                    $survey['title'] ?? '',
                    $keyword
                ) !== false
        );
    }

    if ($filter !== 'all') {
        $surveys = array_filter(
            $surveys,
            fn($survey) =>
                ($survey['status'] ?? '') === $filter
        );
    }

    usort(
        $surveys,
        function($a, $b) use ($sort) {
            $av = match ($sort) {
                'answers_desc',
                'answers_asc' =>
                    surveyAnswerCount($a['id']),
                'start_desc',
                'start_asc' =>
                    strtotime($a['start_at'] ?? '') ?: 0,
                default =>
                    strtotime($a['updated_at'] ?? '') ?: 0,
            };

            $bv = match ($sort) {
                'answers_desc',
                'answers_asc' =>
                    surveyAnswerCount($b['id']),
                'start_desc',
                'start_asc' =>
                    strtotime($b['start_at'] ?? '') ?: 0,
                default =>
                    strtotime($b['updated_at'] ?? '') ?: 0,
            };

            if (
                in_array(
                    $sort,
                    ['answers_asc', 'start_asc'],
                    true
                )
            ) {
                return $av <=> $bv;
            }

            return $bv <=> $av;
        }
    );

    renderHeader('アンケート一覧', 'list');
    ?>

    <div class="page-title">
        <div>
            <h1>アンケート一覧</h1>
            <p>
                アンケート運用の起点です。
            </p>
        </div>

        <a
            class="btn btn-primary"
            href="<?= e(baseUrl(['screen'=>'edit'])) ?>"
        >
            ＋ 新規作成
        </a>
    </div>

    <div class="card">
        <div class="card-body">

            <form
                class="toolbar"
                method="get"
            >
                <input
                    type="hidden"
                    name="screen"
                    value="list"
                >

                <div class="search-box">
                    <input
                        id="surveySearch"
                        type="text"
                        name="q"
                        value="<?= e($keyword) ?>"
                        placeholder="タイトルを検索"
                    >
                    <button type="submit">
                        検索
                    </button>
                </div>

                <select name="filter">
                    <option value="all"
                        <?= $filter === 'all' ? 'selected' : '' ?>>
                        すべて
                    </option>

                    <option value="published"
                        <?= $filter === 'published' ? 'selected' : '' ?>>
                        公開中
                    </option>

                    <option value="draft"
                        <?= $filter === 'draft' ? 'selected' : '' ?>>
                        下書き
                    </option>

                    <option value="stopped"
                        <?= $filter === 'stopped' ? 'selected' : '' ?>>
                        停止
                    </option>

                    <option value="ended"
                        <?= $filter === 'ended' ? 'selected' : '' ?>>
                        終了
                    </option>
                </select>

                <select name="sort">
                    <option value="updated_desc"
                        <?= $sort === 'updated_desc' ? 'selected' : '' ?>>
                        更新日：新しい順
                    </option>

                    <option value="updated_asc"
                        <?= $sort === 'updated_asc' ? 'selected' : '' ?>>
                        更新日：古い順
                    </option>

                    <option value="answers_desc"
                        <?= $sort === 'answers_desc' ? 'selected' : '' ?>>
                        回答数：多い順
                    </option>

                    <option value="answers_asc"
                        <?= $sort === 'answers_asc' ? 'selected' : '' ?>>
                        回答数：少ない順
                    </option>

                    <option value="start_desc"
                        <?= $sort === 'start_desc' ? 'selected' : '' ?>>
                        開始日：新しい順
                    </option>

                    <option value="start_asc"
                        <?= $sort === 'start_asc' ? 'selected' : '' ?>>
                        開始日：古い順
                    </option>
                </select>
            </form>

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
                            <td colspan="7">
                                <div class="empty">
                                    アンケートはありません。
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($surveys as $survey): ?>
                        <tr>
                            <td>
                                <strong>
                                    <?= e($survey['title']) ?>
                                </strong>
                            </td>

                            <td>
                                <?= e($survey['created_at']) ?>
                            </td>

                            <td>
                                <?= e($survey['updated_at']) ?>
                            </td>

                            <td>
                                <?= e($survey['start_at']) ?>
                                ～
                                <?= e($survey['end_at']) ?>
                            </td>

                            <td>
                                <span
                                    class="badge <?= e(
                                        statusClass(
                                            $survey['status']
                                        )
                                    ) ?>"
                                >
                                    <?= e(
                                        statusLabel(
                                            $survey['status']
                                        )
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <?= surveyAnswerCount(
                                    $survey['id']
                                ) ?>
                            </td>

                            <td>
                                <div class="action-grid">

                                    <a
                                        class="btn btn-sm"
                                        href="<?= e(baseUrl([
                                            'screen'=>'edit',
                                            'id'=>$survey['id']
                                        ])) ?>"
                                    >
                                        確認・編集
                                    </a>

                                    <a
                                        class="btn btn-sm"
                                        href="<?= e(baseUrl([
                                            'screen'=>'analytics',
                                            'id'=>$survey['id']
                                        ])) ?>"
                                    >
                                        集計
                                    </a>

                                    <a
                                        class="btn btn-sm"
                                        href="<?= e(baseUrl([
                                            'screen'=>'send',
                                            'id'=>$survey['id']
                                        ])) ?>"
                                    >
                                        送信
                                    </a>

                                    <form
                                        method="post"
                                        style="display:inline"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e(csrfToken()) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="duplicate_survey"
                                        >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= e(
                                                $survey['id']
                                            ) ?>"
                                        >

                                        <button
                                            class="btn btn-sm"
                                            type="submit"
                                            onclick="
                                                return confirmAction(
                                                    'このアンケートを複製しますか？'
                                                )
                                            "
                                        >
                                            複製
                                        </button>
                                    </form>

                                    <form
                                        method="post"
                                        style="display:inline"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e(csrfToken()) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete_survey"
                                        >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= e(
                                                $survey['id']
                                            ) ?>"
                                        >

                                        <button
                                            class="btn btn-danger btn-sm"
                                            type="submit"
                                            onclick="
                                                return confirmAction(
                                                    '削除しますか？'
                                                )
                                            "
                                        >
                                            削除
                                        </button>
                                    </form>

                                    <?php
                                    $nextStatus = match (
                                        $survey['status']
                                    ) {
                                        'draft' => 'published',
                                        'published' => 'stopped',
                                        'stopped' => 'published',
                                        default => '',
                                    };
                                    ?>

                                    <?php if ($nextStatus !== ''): ?>
                                        <form
                                            method="post"
                                            style="display:inline"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e(
                                                    csrfToken()
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
                                                value="<?= e(
                                                    $survey['id']
                                                ) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="status"
                                                value="<?= e(
                                                    $nextStatus
                                                ) ?>"
                                            >

                                            <button
                                                class="btn btn-sm"
                                                type="submit"
                                                onclick="
                                                    return confirmAction(
                                                        '状態を変更しますか？'
                                                    )
                                                "
                                            >
                                                <?= e(
                                                    $nextStatus ===
                                                    'published'
                                                        ? '公開'
                                                        : '停止'
                                                ) ?>
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

    <?php
    renderFooter();
    exit;
}

/* =========================================================
 * 編集
 * ========================================================= */

if ($screen === 'edit') {
    $id = (string)($_GET['id'] ?? '');

    if ($id !== '') {
        $survey = getSurvey($id);

        if (!$survey) {
            flash(
                'アンケートが存在しません。',
                'danger'
            );

            redirect(baseUrl(['screen'=>'list']));
        }
    } else {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'start_at' => '',
            'end_at' => '',
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [
                [
                    'id' => uuid(),
                    'title' => '基本情報',
                    'questions' => [],
                ],
            ],
            'created_at' => '',
            'updated_at' => '',
        ];
    }

    renderHeader(
        $survey['title'] !== ''
            ? $survey['title']
            : 'アンケート作成',
        'list'
    );
    ?>

    <div class="editor-topbar">

        <a
            class="btn"
            href="<?= e(baseUrl(['screen'=>'list'])) ?>"
        >
            キャンセル
        </a>

        <form method="post">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrfToken()) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="save_survey"
            >

            <input
                type="hidden"
                name="id"
                value="<?= e($survey['id']) ?>"
            >

            <input
                type="hidden"
                id="hiddenTitle"
                name="title"
                value=""
            >

            <input
                type="hidden"
                id="hiddenDescription"
                name="description"
                value=""
            >

            <input
                type="hidden"
                id="hiddenStart"
                name="start_at"
                value=""
            >

            <input
                type="hidden"
                id="hiddenEnd"
                name="end_at"
                value=""
            >

            <input
                type="hidden"
                id="hiddenNumbering"
                name="numbering"
                value=""
            >

            <button
                class="btn btn-primary"
                type="submit"
                onclick="return prepareSurveySave()"
            >
                保存して一覧へ
            </button>
        </form>

        <?php if ($survey['id'] !== ''): ?>
            <a
                class="btn"
                href="<?= e(baseUrl([
                    'screen'=>'preview',
                    'id'=>$survey['id']
                ])) ?>"
            >
                プレビュー
            </a>
        <?php endif; ?>

        <div class="state-area">

            <span>状態：</span>

            <span
                class="badge <?= e(
                    statusClass($survey['status'])
                ) ?>"
            >
                <?= e(
                    statusLabel($survey['status'])
                ) ?>
            </span>

        </div>

    </div>

    <form id="surveyForm">

        <div class="card section">
            <div class="card-body">

                <div class="form-grid">

                    <div class="form-group full">
                        <label>アンケートタイトル</label>
                        <input
                            id="title"
                            type="text"
                            value="<?= e($survey['title']) ?>"
                            required
                        >
                    </div>

                    <div class="form-group full">
                        <label>アンケート説明</label>
                        <textarea
                            id="description"
                        ><?= e($survey['description']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>開始日時</label>
                        <input
                            id="start_at"
                            type="datetime-local"
                            value="<?= e(
                                $survey['start_at']
                                    ? date(
                                        'Y-m-d\TH:i',
                                        strtotime(
                                            $survey['start_at']
                                        )
                                    )
                                    : ''
                            ) ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label>終了日時</label>
                        <input
                            id="end_at"
                            type="datetime-local"
                            value="<?= e(
                                $survey['end_at']
                                    ? date(
                                        'Y-m-d\TH:i',
                                        strtotime(
                                            $survey['end_at']
                                        )
                                    )
                                    : ''
                            ) ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label>質問番号の採番方式</label>

                        <select id="numbering">
                            <option
                                value="global"
                                <?= $survey['numbering'] === 'global'
                                    ? 'selected'
                                    : '' ?>
                            >
                                アンケート全体で通番
                            </option>

                            <option
                                value="group"
                                <?= $survey['numbering'] === 'group'
                                    ? 'selected'
                                    : '' ?>
                            >
                                グループ毎に採番
                            </option>
                        </select>
                    </div>

                </div>

            </div>
        </div>

        <div class="section">
            <h2 class="section-title">
                グループ・質問
            </h2>

            <div id="groups">

            <?php foreach ($survey['groups'] as $group): ?>

                <div
                    class="group"
                    draggable="true"
                    data-group-id="<?= e($group['id']) ?>"
                >

                    <div class="group-header">

                        <span class="drag-handle">
                            ⋮⋮
                        </span>

                        <input
                            class="group-title-input"
                            type="text"
                            value="<?= e($group['title']) ?>"
                        >

                        <button
                            type="button"
                            class="btn btn-sm"
                            onclick="deleteGroup(this)"
                        >
                            グループ削除
                        </button>

                    </div>

                    <div class="question-list">

                    <?php foreach ($group['questions'] as $question): ?>

                        <div
                            class="question"
                            draggable="true"
                            data-question-id="<?= e(
                                $question['id']
                            ) ?>"
                        >

                            <div class="question-header">

                                <span
                                    class="drag-handle"
                                >
                                    ⋮⋮
                                </span>

                                <span
                                    class="question-number"
                                >
                                    <?= e(
                                        $question['number']
                                    ) ?>
                                </span>

                                <input
                                    class="question-text"
                                    type="text"
                                    value="<?= e(
                                        $question['text']
                                    ) ?>"
                                    placeholder="質問文"
                                >

                                <button
                                    type="button"
                                    class="btn btn-danger btn-sm"
                                    onclick="
                                        deleteQuestion(this)
                                    "
                                >
                                    削除
                                </button>

                            </div>

                            <div class="question-body">

                                <div></div>

                                <select
                                    class="question-type"
                                    onchange="
                                        toggleQuestionType(this)
                                    "
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

                                <label
                                    style="
                                        display:flex;
                                        align-items:center;
                                        gap:5px;
                                    "
                                >
                                    <input
                                        class="question-required"
                                        type="checkbox"
                                        <?= $question['required']
                                            ? 'checked'
                                            : '' ?>
                                    >
                                    必須
                                </label>

                            </div>

                            <div
                                class="question-options
                                    <?= $question['type'] === 'text'
                                        ? 'hidden'
                                        : '' ?>"
                            >

                                <?php
                                $options =
                                    $question['options'] ?: [''];
                                ?>

                                <?php foreach ($options as $option): ?>

                                    <div class="option-row">
                                        <input
                                            type="text"
                                            value="<?= e($option) ?>"
                                            placeholder="選択肢"
                                        >

                                        <button
                                            type="button"
                                            class="btn btn-sm"
                                            onclick="
                                                removeOption(this)
                                            "
                                        >
                                            削除
                                        </button>
                                    </div>

                                <?php endforeach; ?>

                                <button
                                    type="button"
                                    class="btn btn-sm"
                                    onclick="addOption(this)"
                                >
                                    ＋ 選択肢追加
                                </button>

                            </div>

                        </div>

                    <?php endforeach; ?>

                    </div>

                    <div class="add-area">
                        <button
                            type="button"
                            class="btn btn-sm btn-primary"
                            onclick="addQuestion(this)"
                        >
                            ＋ 質問を追加
                        </button>
                    </div>

                </div>

            <?php endforeach; ?>

            </div>

            <div class="group-add">
                <button
                    type="button"
                    class="btn"
                    onclick="addGroup()"
                >
                    ＋ グループを追加
                </button>
            </div>

        </div>

    </form>

    <script>
    let numberingMode =
        <?= json_encode(
            $survey['numbering'],
            JSON_UNESCAPED_UNICODE
        ) ?>;

    function renumber() {
        const groups =
            document.querySelectorAll('#groups > .group');

        let globalNo = 1;

        groups.forEach(function(group, gi) {
            const questions =
                group.querySelectorAll(
                    '.question'
                );

            questions.forEach(function(q, qi) {
                const number =
                    numberingMode === 'group'
                        ? 'Q' + (gi + 1) +
                          '-' + (qi + 1)
                        : 'Q' + globalNo;

                q.querySelector(
                    '.question-number'
                ).textContent = number;

                globalNo++;
            });
        });
    }

    document
        .getElementById('numbering')
        .addEventListener('change', function() {
            numberingMode = this.value;
            renumber();
        });

    function addGroup() {
        const wrapper =
            document.getElementById('groups');

        const group =
            document.createElement('div');

        group.className = 'group';
        group.draggable = true;

        group.innerHTML = `
            <div class="group-header">
                <span class="drag-handle">⋮⋮</span>
                <input
                    class="group-title-input"
                    type="text"
                    value="新しいグループ"
                >
                <button
                    type="button"
                    class="btn btn-sm"
                    onclick="deleteGroup(this)"
                >
                    グループ削除
                </button>
            </div>

            <div class="question-list"></div>

            <div class="add-area">
                <button
                    type="button"
                    class="btn btn-sm btn-primary"
                    onclick="addQuestion(this)"
                >
                    ＋ 質問を追加
                </button>
            </div>
        `;

        wrapper.appendChild(group);

        addQuestion(
            group.querySelector('.add-area button')
        );

        renumber();
    }

    function addQuestion(button) {
        const group =
            button.closest('.group');

        const list =
            group.querySelector('.question-list');

        const question =
            document.createElement('div');

        question.className = 'question';
        question.draggable = true;

        question.innerHTML = `
            <div class="question-header">
                <span class="drag-handle">⋮⋮</span>
                <span class="question-number"></span>

                <input
                    class="question-text"
                    type="text"
                    placeholder="質問文"
                >

                <button
                    type="button"
                    class="btn btn-danger btn-sm"
                    onclick="deleteQuestion(this)"
                >
                    削除
                </button>
            </div>

            <div class="question-body">
                <div></div>

                <select
                    class="question-type"
                    onchange="toggleQuestionType(this)"
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

                <label
                    style="
                        display:flex;
                        align-items:center;
                        gap:5px;
                    "
                >
                    <input
                        class="question-required"
                        type="checkbox"
                    >
                    必須
                </label>
            </div>

            <div class="question-options">
                <div class="option-row">
                    <input
                        type="text"
                        placeholder="選択肢"
                    >

                    <button
                        type="button"
                        class="btn btn-sm"
                        onclick="removeOption(this)"
                    >
                        削除
                    </button>
                </div>

                <button
                    type="button"
                    class="btn btn-sm"
                    onclick="addOption(this)"
                >
                    ＋ 選択肢追加
                </button>
            </div>
        `;

        list.appendChild(question);

        renumber();
    }

    function deleteGroup(button) {
        if (
            !confirm(
                'このグループを削除しますか？'
            )
        ) {
            return;
        }

        button.closest('.group').remove();

        renumber();
    }

    function deleteQuestion(button) {
        if (
            !confirm(
                'この質問を削除しますか？'
            )
        ) {
            return;
        }

        button.closest('.question').remove();

        renumber();
    }

    function addOption(button) {
        const wrapper =
            button.closest('.question-options');

        const row =
            document.createElement('div');

        row.className = 'option-row';

        row.innerHTML = `
            <input
                type="text"
                placeholder="選択肢"
            >

            <button
                type="button"
                class="btn btn-sm"
                onclick="removeOption(this)"
            >
                削除
            </button>
        `;

        wrapper.insertBefore(row, button);
    }

    function removeOption(button) {
        const row =
            button.closest('.option-row');

        row.remove();
    }

    function toggleQuestionType(select) {
        const question =
            select.closest('.question');

        const options =
            question.querySelector(
                '.question-options'
            );

        options.classList.toggle(
            'hidden',
            select.value === 'text'
        );
    }

    function prepareSurveySave() {
        const form =
            document.getElementById('surveyForm');

        const title =
            document.getElementById('title').value;

        if (!title.trim()) {
            alert(
                'アンケートタイトルを入力してください。'
            );

            return false;
        }

        document.getElementById(
            'hiddenTitle'
        ).value = title;

        document.getElementById(
            'hiddenDescription'
        ).value =
            document.getElementById(
                'description'
            ).value;

        document.getElementById(
            'hiddenStart'
        ).value =
            document.getElementById(
                'start_at'
            ).value;

        document.getElementById(
            'hiddenEnd'
        ).value =
            document.getElementById(
                'end_at'
            ).value;

        document.getElementById(
            'hiddenNumbering'
        ).value =
            document.getElementById(
                'numbering'
            ).value;

        /*
         * 編集画面の構造は別POSTとして送る。
         * 保存本体後に構造保存を実行する必要があるため、
         * この画面では現在の仕様上タイトル等を保存する。
         *
         * 質問構造は下部の別送信処理を使用する。
         */
        return true;
    }

    /*
     * 質問構造を保存するため、ページ離脱前ではなく
     * 「保存して一覧へ」の処理に合わせて、
     * hidden JSONを生成する。
     *
     * 実際のサーバー側save_structureへ送るフォームを
     * 動的生成する。
     */
    const originalSave =
        document.querySelector(
            '.editor-topbar form'
        );

    if (originalSave) {
        originalSave.addEventListener(
            'submit',
            function(ev) {
                ev.preventDefault();

                const form =
                    document.getElementById(
                        'surveyForm'
                    );

                const groups = [];

                document
                    .querySelectorAll(
                        '#groups > .group'
                    )
                    .forEach(function(group) {
                        const questions = [];

                        group
                            .querySelectorAll(
                                '.question'
                            )
                            .forEach(function(q) {
                                const options = [];

                                q.querySelectorAll(
                                    '.question-options .option-row input'
                                ).forEach(function(input) {
                                    if (
                                        input.value.trim()
                                    ) {
                                        options.push(
                                            input.value
                                        );
                                    }
                                });

                                questions.push({
                                    id:
                                        q.dataset.questionId ||
                                        crypto.randomUUID(),
                                    text:
                                        q.querySelector(
                                            '.question-text'
                                        ).value,
                                    type:
                                        q.querySelector(
                                            '.question-type'
                                        ).value,
                                    required:
                                        q.querySelector(
                                            '.question-required'
                                        ).checked,
                                    options:
                                        options
                                });
                            });

                        groups.push({
                            id:
                                group.dataset.groupId ||
                                crypto.randomUUID(),
                            title:
                                group.querySelector(
                                    '.group-title-input'
                                ).value,
                            questions:
                                questions
                        });
                    });

                const hidden =
                    document.createElement(
                        'input'
                    );

                hidden.type = 'hidden';
                hidden.name = 'structure_json';
                hidden.value =
                    JSON.stringify(groups);

                originalSave.appendChild(hidden);

                /*
                 * structure_jsonも同時に保存する。
                 */
                const structureInput =
                    document.createElement(
                        'input'
                    );

                structureInput.type = 'hidden';
                structureInput.name =
                    'save_structure_together';
                structureInput.value = '1';

                originalSave.appendChild(
                    structureInput
                );

                /*
                 * サーバー側save_surveyでは
                 * structure_jsonを処理する。
                 */
                originalSave.submit();
            }
        );
    }

    /*
     * ドラッグ＆ドロップ。
     * グループ、質問とも並び替え可能。
     */
    let dragElement = null;

    document.addEventListener(
        'dragstart',
        function(e) {
            const target =
                e.target.closest(
                    '.group, .question'
                );

            if (!target) {
                return;
            }

            dragElement = target;
            target.style.opacity = '.45';
        }
    );

    document.addEventListener(
        'dragend',
        function(e) {
            const target =
                e.target.closest(
                    '.group, .question'
                );

            if (target) {
                target.style.opacity = '';
            }

            dragElement = null;
            renumber();
        }
    );

    document.addEventListener(
        'dragover',
        function(e) {
            if (!dragElement) {
                return;
            }

            const target =
                e.target.closest(
                    '.group, .question'
                );

            if (!target || target === dragElement) {
                return;
            }

            /*
             * 質問は質問リスト内、
             * グループはグループ一覧内で移動。
             */
            if (
                dragElement.classList.contains(
                    'question'
                ) &&
                target.classList.contains(
                    'question'
                )
            ) {
                e.preventDefault();

                const list =
                    target.closest(
                        '.question-list'
                    );

                if (
                    list &&
                    dragElement.closest(
                        '.question-list'
                    )
                ) {
                    const rect =
                        target.getBoundingClientRect();

                    const after =
                        e.clientY >
                        rect.top +
                        rect.height / 2;

                    list.insertBefore(
                        dragElement,
                        after
                            ? target.nextSibling
                            : target
                    );
                }
            }

            if (
                dragElement.classList.contains(
                    'group'
                ) &&
                target.classList.contains(
                    'group'
                )
            ) {
                e.preventDefault();

                const list =
                    target.parentElement;

                const rect =
                    target.getBoundingClientRect();

                const after =
                    e.clientY >
                    rect.top +
                    rect.height / 2;

                list.insertBefore(
                    dragElement,
                    after
                        ? target.nextSibling
                        : target
                );
            }
        }
    );

    renumber();
    </script>

    <?php
    renderFooter();
    exit;
}

/* =========================================================
 * プレビュー
 * ========================================================= */

if ($screen === 'preview') {
    $id = (string)($_GET['id'] ?? '');

    $survey = getSurvey($id);

    if (!$survey) {
        flash(
            'アンケートが存在しません。',
            'danger'
        );

        redirect(baseUrl(['screen'=>'list']));
    }

    renderHeader(
        'プレビュー',
        'list'
    );
    ?>

    <div class="page-title">
        <div>
            <h1>プレビュー</h1>
            <p>
                <?= e($survey['title']) ?>
            </p>
        </div>

        <div class="actions">
            <a
                class="btn"
                href="<?= e(baseUrl([
                    'screen'=>'edit',
                    'id'=>$id
                ])) ?>"
            >
                編集へ戻る
            </a>
        </div>
    </div>

    <div class="actions"
         style="justify-content:center;margin-bottom:18px">

        <button
            class="btn"
            type="button"
            onclick="
                document
                    .getElementById('preview')
                    .classList.remove('mobile')
            "
        >
            PC
        </button>

        <button
            class="btn"
            type="button"
            onclick="
                document
                    .getElementById('preview')
                    .classList.add('mobile')
            "
        >
            スマートフォン
        </button>

    </div>

    <div
        id="preview"
        class="preview-device"
    >

        <h1>
            <?= e($survey['title']) ?>
        </h1>

        <p>
            <?= nl2br(
                e($survey['description'])
            ) ?>
        </p>

        <?php foreach ($survey['groups'] as $group): ?>

            <h2>
                <?= e($group['title']) ?>
            </h2>

            <?php foreach ($group['questions'] as $question): ?>

                <div class="preview-question">

                    <h3>
                        <?= e(
                            $question['number']
                        ) ?>
                        <?= e(
                            $question['text']
                        ) ?>

                        <?php if ($question['required']): ?>
                            <span class="required">
                                必須
                            </span>
                        <?php endif; ?>
                    </h3>

                    <?php if (
                        $question['type'] === 'text'
                    ): ?>

                        <textarea
                            placeholder="回答を入力"
                        ></textarea>

                    <?php else: ?>

                        <?php foreach (
                            $question['options']
                            as $i => $option
                        ): ?>

                            <label class="preview-option">

                                <input
                                    type="<?= $question['type'] === 'single'
                                        ? 'radio'
                                        : 'checkbox' ?>"
                                    name="preview_<?= e(
                                        $question['id']
                                    ) ?>"
                                >

                                <?= e($option) ?>

                            </label>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php endforeach; ?>

    </div>

    <?php
    renderFooter();
    exit;
}

/* =========================================================
 * 回答者
 * ========================================================= */

if (
    in_array(
        $screen,
        ['answer', 'confirm', 'complete'],
        true
    )
) {
    /*
     * 回答者画面は管理者ナビを表示しない。
     */

    $id = (string)($_GET['id'] ?? '');
    $survey = getSurvey($id);

    if (!$survey) {
        http_response_code(404);
        exit('アンケートが存在しません。');
    }

    if (
        $screen === 'answer' &&
        (
            ($survey['status'] ?? '') !== 'published' ||
            (
                !empty($survey['end_at']) &&
                strtotime($survey['end_at']) < time()
            )
        )
    ) {
        ?>
        <!doctype html>
        <html lang="ja">
        <head>
            <meta charset="UTF-8">
            <meta
                name="viewport"
                content="width=device-width,initial-scale=1"
            >
            <title><?= e($survey['title']) ?></title>
        </head>
        <body>
        <div class="respondent">
            <div class="respondent-main">
                <div class="respondent-card">
                    <h1>回答できません</h1>
                    <p>
                        このアンケートは現在回答できません。
                    </p>
                </div>
            </div>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">

        <meta
            name="viewport"
            content="width=device-width,initial-scale=1"
        >

        <title>
            <?= e($survey['title']) ?>
        </title>

        <style>
        :root{
            --primary:#2563eb;
            --danger:#dc2626;
            --border:#dbe2ea;
            --text:#1e293b;
        }

        *{box-sizing:border-box}

        body{
            margin:0;
            color:var(--text);
            background:#f8fafc;
            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                "Noto Sans JP",
                Meiryo,
                sans-serif;
        }

        .respondent{
            min-height:100vh;
        }

        .respondent-header{
            background:#fff;
            border-bottom:1px solid var(--border);
            padding:20px;
        }

        .respondent-header-inner{
            max-width:760px;
            margin:auto;
        }

        .respondent-main{
            max-width:760px;
            margin:25px auto;
            padding:0 16px 50px;
        }

        .respondent-card{
            background:#fff;
            border:1px solid var(--border);
            border-radius:12px;
            padding:25px;
            box-shadow:
                0 4px 18px rgba(15,23,42,.08);
        }

        .respondent-question{
            margin-bottom:28px;
        }

        .respondent-option{
            display:block;
            padding:13px;
            border:1px solid #cbd5e1;
            border-radius:8px;
            margin:8px 0;
        }

        input[type=text],
        textarea{
            width:100%;
            padding:12px;
            border:1px solid #cbd5e1;
            border-radius:8px;
        }

        textarea{
            min-height:130px;
        }

        .required{
            color:var(--danger);
            font-size:12px;
        }

        button{
            border:0;
            border-radius:8px;
            background:var(--primary);
            color:#fff;
            padding:12px 18px;
            cursor:pointer;
        }

        .error{
            background:#fee2e2;
            color:#991b1b;
            padding:12px;
            border-radius:8px;
            margin-bottom:15px;
        }
        </style>
    </head>

    <body>

    <div class="respondent">

        <header class="respondent-header">
            <div class="respondent-header-inner">
                <strong>
                    <?= e($survey['title']) ?>
                </strong>
            </div>
        </header>

        <main class="respondent-main">

            <div class="respondent-card">

            <?php if ($screen === 'complete'): ?>

                <h1>回答完了</h1>

                <p>
                    ご回答ありがとうございました。
                </p>

            <?php elseif ($screen === 'confirm'): ?>

                <h1>回答確認</h1>

                <p>
                    以下の内容で送信します。
                </p>

                <?php
                $answerValues =
                    $_SESSION['answer_values'] ?? [];
                ?>

                <?php foreach (
                    $survey['groups']
                    as $group
                ): ?>

                    <h2>
                        <?= e($group['title']) ?>
                    </h2>

                    <?php foreach (
                        $group['questions']
                        as $question
                    ): ?>

                        <?php
                        $value =
                            $answerValues[
                                $question['id']
                            ] ?? '';

                        if (is_array($value)) {
                            $value =
                                implode(
                                    ', ',
                                    $value
                                );
                        }
                        ?>

                        <div
                            class="respondent-question"
                        >
                            <strong>
                                <?= e(
                                    $question['number']
                                ) ?>
                                <?= e(
                                    $question['text']
                                ) ?>
                            </strong>

                            <p>
                                <?= nl2br(
                                    e($value)
                                ) ?>
                            </p>
                        </div>

                    <?php endforeach; ?>

                <?php endforeach; ?>

                <form
                    method="post"
                    action="<?= e(baseUrl()) ?>"
                >
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(
                            csrfToken()
                        ) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="submit_answer"
                    >

                    <input
                        type="hidden"
                        name="survey_id"
                        value="<?= e($id) ?>"
                    >

                    <?php foreach (
                        $answerValues
                        as $questionId => $value
                    ): ?>

                        <?php if (is_array($value)): ?>

                            <?php foreach ($value as $v): ?>

                                <input
                                    type="hidden"
                                    name="answers[
                                        <?= e($questionId) ?>][]
                                    "
                                    value="<?= e($v) ?>"
                                >

                            <?php endforeach; ?>

                        <?php else: ?>

                            <input
                                type="hidden"
                                name="answers[
                                    <?= e($questionId) ?>
                                ]"
                                value="<?= e($value) ?>"
                            >

                        <?php endif; ?>

                    <?php endforeach; ?>

                    <input
                        type="hidden"
                        name="respondent"
                        value="<?= e(
                            $_SESSION['answer_respondent']
                            ?? ''
                        ) ?>"
                    >

                    <button type="submit">
                        この内容で送信
                    </button>
                </form>

            <?php else: ?>

                <?php
                $errors =
                    $_SESSION['answer_errors']
                    ?? [];

                $values =
                    $_SESSION['answer_values']
                    ?? [];

                unset(
                    $_SESSION['answer_errors'],
                    $_SESSION['answer_values']
                );
                ?>

                <?php foreach ($errors as $error): ?>
                    <div class="error">
                        <?= e($error) ?>
                    </div>
                <?php endforeach; ?>

                <h1>
                    <?= e($survey['title']) ?>
                </h1>

                <p>
                    <?= nl2br(
                        e($survey['description'])
                    ) ?>
                </p>

                <form
                    method="post"
                    action="<?= e(baseUrl([
                        'screen'=>'confirm',
                        'id'=>$id
                    ])) ?>"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(
                            csrfToken()
                        ) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="prepare_answer"
                    >

                    <div class="respondent-question">

                        <label>
                            回答者名
                        </label>

                        <input
                            type="text"
                            name="respondent"
                            value="<?= e(
                                $_SESSION[
                                    'answer_respondent'
                                ] ?? ''
                            ) ?>"
                        >

                    </div>

                    <?php foreach (
                        $survey['groups']
                        as $group
                    ): ?>

                        <h2>
                            <?= e($group['title']) ?>
                        </h2>

                        <?php foreach (
                            $group['questions']
                            as $question
                        ): ?>

                            <div
                                class="respondent-question"
                                data-question-id="<?= e(
                                    $question['id']
                                ) ?>"
                            >

                                <h3>
                                    <?= e(
                                        $question['number']
                                    ) ?>

                                    <?= e(
                                        $question['text']
                                    ) ?>

                                    <?php if (
                                        $question['required']
                                    ): ?>
                                        <span
                                            class="required"
                                        >
                                            必須
                                        </span>
                                    <?php endif; ?>
                                </h3>

                                <?php
                                $current =
                                    $values[
                                        $question['id']
                                    ] ?? '';
                                ?>

                                <?php if (
                                    $question['type']
                                    === 'text'
                                ): ?>

                                    <textarea
                                        name="answers[
                                            <?= e(
                                                $question['id']
                                            ) ?>
                                        ]"
                                    ><?= e(
                                        is_string($current)
                                            ? $current
                                            : ''
                                    ) ?></textarea>

                                <?php else: ?>

                                    <?php foreach (
                                        $question['options']
                                        as $option
                                    ): ?>

                                        <?php
                                        $checked = false;

                                        if (
                                            $question['type']
                                            === 'single'
                                        ) {
                                            $checked =
                                                (string)$current
                                                ===
                                                (string)$option;
                                        } elseif (
                                            is_array($current)
                                        ) {
                                            $checked =
                                                in_array(
                                                    $option,
                                                    $current,
                                                    true
                                                );
                                        }
                                        ?>

                                        <label
                                            class="respondent-option"
                                        >

                                            <input
                                                type="<?= $question['type']
                                                    === 'single'
                                                        ? 'radio'
                                                        : 'checkbox' ?>"
                                                name="<?= $question['type']
                                                    === 'single'
                                                        ? 'answers[' .
                                                          e($question['id']) .
                                                          ']'
                                                        : 'answers[' .
                                                          e($question['id']) .
                                                          '][]' ?>"
                                                value="<?= e(
                                                    $option
                                                ) ?>"
                                                <?= $checked
                                                    ? 'checked'
                                                    : '' ?>
                                            >

                                            <?= e($option) ?>

                                        </label>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </div>

                        <?php endforeach; ?>

                    <?php endforeach; ?>

                    <button type="submit">
                        回答を確認する
                    </button>

                </form>

            <?php endif; ?>

            </div>

        </main>
    </div>

    </body>
    </html>
    <?php

    exit;
}

/* =========================================================
 * 回答確認準備
 * ========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    $screen === 'confirm'
) {
    /*
     * POSTでのconfirmを想定しているため、
     * GET直接アクセス時は回答画面へ戻す。
     */
    redirect(baseUrl([
        'screen' => 'answer',
        'id' => (string)($_GET['id'] ?? ''),
    ]));
}

/* =========================================================
 * kintone設定
 * ========================================================= */

if ($screen === 'kintone') {
    $s = $data['settings']['kintone'];

    $fields =
        $_SESSION['kintone_fields'] ?? [];

    renderHeader(
        'kintone連携設定',
        'kintone'
    );
    ?>

    <div class="page-title">
        <div>
            <h1>kintone連携設定</h1>
            <p>
                顧客情報の取得元を設定します。
            </p>
        </div>
    </div>

    <div class="settings-grid">

        <div class="card">
            <div class="card-header">
                <strong>接続設定</strong>
            </div>

            <div class="card-body">

                <form method="post">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrfToken()) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="save_kintone"
                    >

                    <div class="form-group">
                        <label>
                            サブドメイン
                        </label>

                        <input
                            type="text"
                            name="subdomain"
                            value="<?= e(
                                $s['subdomain']
                            ) ?>"
                            placeholder="example"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>
                            顧客管理アプリID
                        </label>

                        <input
                            type="number"
                            name="app_id"
                            value="<?= e(
                                $s['app_id']
                            ) ?>"
                            min="1"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>
                            ログイン名
                        </label>

                        <input
                            type="text"
                            name="login_name"
                            value="<?= e(
                                $s['login_name']
                            ) ?>"
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
                            placeholder="変更する場合のみ入力"
                        >

                        <span class="help">
                            保存済みパスワードは画面へ表示しません。
                        </span>
                    </div>

                    <div class="form-group">
                        <label>
                            Proxyサーバ
                        </label>

                        <input
                            type="text"
                            name="proxy"
                            value="<?= e(
                                $s['proxy']
                            ) ?>"
                            placeholder="proxy.example.local:8080"
                        >

                        <span class="help">
                            host:port形式。未入力の場合は直接接続。
                        </span>
                    </div>

                    <div class="form-group">

                        <label
                            style="
                                display:flex;
                                align-items:center;
                                gap:8px;
                            "
                        >
                            <input
                                type="checkbox"
                                name="verify_ssl"
                                value="1"
                                <?= !empty($s['verify_ssl'])
                                    ? 'checked'
                                    : '' ?>
                            >

                            SSL証明書を検証する
                        </label>

                    </div>

                    <hr>

                    <h3>
                        顧客項目マッピング
                    </h3>

                    <div class="mapping">
                        <label>組織名</label>
                        <input
                            type="text"
                            name="mapping_organization"
                            value="<?= e(
                                $s['mapping']['organization']
                            ) ?>"
                        >

                        <label>氏名</label>
                        <input
                            type="text"
                            name="mapping_name"
                            value="<?= e(
                                $s['mapping']['name']
                            ) ?>"
                        >

                        <label>メール</label>
                        <input
                            type="text"
                            name="mapping_email"
                            value="<?= e(
                                $s['mapping']['email']
                            ) ?>"
                        >

                        <label>部署名</label>
                        <input
                            type="text"
                            name="mapping_department"
                            value="<?= e(
                                $s['mapping']['department']
                            ) ?>"
                        >

                        <label>電話番号</label>
                        <input
                            type="text"
                            name="mapping_phone"
                            value="<?= e(
                                $s['mapping']['phone']
                            ) ?>"
                        >
                    </div>

                    <?php if ($fields): ?>

                        <div
                            style="
                                margin-top:20px;
                                padding:15px;
                                background:#f8fafc;
                                border-radius:8px;
                            "
                        >

                            <strong>
                                住所項目
                            </strong>

                            <div class="address-checks">

                                <?php foreach (
                                    $fields
                                    as $code => $field
                                ): ?>

                                    <?php
                                    $label =
                                        $field['label']
                                        ?? $code;
                                    ?>

                                    <label>

                                        <input
                                            type="checkbox"
                                            name="address_fields[]"
                                            value="<?= e(
                                                $code
                                            ) ?>"
                                            <?= in_array(
                                                $code,
                                                $s['mapping'][
                                                    'address_fields'
                                                ],
                                                true
                                            )
                                                ? 'checked'
                                                : '' ?>
                                        >

                                        <?= e($label) ?>
                                        (<?= e($code) ?>)

                                    </label>

                                <?php endforeach; ?>

                            </div>

                        </div>

                    <?php endif; ?>

                    <div class="actions"
                         style="margin-top:20px">

                        <button
                            class="btn btn-primary"
                            type="submit"
                        >
                            設定保存
                        </button>

                    </div>

                </form>

            </div>
        </div>

        <div class="card">

            <div class="card-header">
                <strong>接続・同期</strong>
            </div>

            <div class="card-body">

                <div class="status-box">

                    <strong>
                        接続状態
                    </strong>

                    <p>
                        <?= e($s['status']) ?>
                    </p>

                </div>

                <form
                    method="post"
                    style="margin-top:12px"
                >
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrfToken()) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="test_kintone"
                    >

                    <button
                        class="btn"
                        type="submit"
                    >
                        接続テスト
                    </button>
                </form>

                <form
                    method="post"
                    style="margin-top:12px"
                >
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrfToken()) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="refresh_kintone_fields"
                    >

                    <button
                        class="btn"
                        type="submit"
                    >
                        項目一覧を再取得
                    </button>
                </form>

                <form
                    method="post"
                    style="margin-top:12px"
                    onsubmit="
                        return confirm(
                            'kintoneから顧客情報を同期しますか？'
                        )
                    "
                >
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrfToken()) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="sync_kintone"
                    >

                    <button
                        class="btn btn-primary"
                        type="submit"
                    >
                        顧客情報を同期
                    </button>
                </form>

                <div class="help"
                     style="margin-top:20px">

                    kintone REST APIへの認証情報は
                    PHP側だけで扱います。
                    X-Cybozu-Authorizationを
                    ブラウザへ返したり、
                    ログへ出力したりしません。

                </div>

            </div>

        </div>

    </div>

    <?php
    renderFooter();
    exit;
}

/* =========================================================
 * メール設定
 * ========================================================= */

if ($screen === 'mail') {
    $s = $data['settings']['mail'];

    renderHeader(
        'メールサーバ設定',
        'mail'
    );
    ?>

    <div class="page-title">
        <div>
            <h1>メールサーバ設定</h1>
            <p>
                SMTPサーバへ直接接続してメールを送信します。
            </p>
        </div>
    </div>

    <div class="settings-grid">

        <div class="card">

            <div class="card-header">
                <strong>SMTP設定</strong>
            </div>

            <div class="card-body">

                <form method="post">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrfToken()) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="save_mail"
                    >

                    <div class="form-group">
                        <label>
                            SMTPサーバ
                        </label>

                        <input
                            type="text"
                            name="smtp_host"
                            value="<?= e(
                                $s['smtp_host']
                            ) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>
                            SMTPポート
                        </label>

                        <input
                            type="number"
                            name="smtp_port"
                            value="<?= e(
                                $s['smtp_port']
                            ) ?>"
                            min="1"
                            max="65535"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>
                            暗号化方式
                        </label>

                        <select name="encryption">

                            <option
                                value="tls"
                                <?= $s['encryption'] === 'tls'
                                    ? 'selected'
                                    : '' ?>
                            >
                                TLS
                            </option>

                            <option
                                value="ssl"
                                <?= $s['encryption'] === 'ssl'
                                    ? 'selected'
                                    : '' ?>
                            >
                                SSL
                            </option>

                            <option
                                value="none"
                                <?= $s['encryption'] === 'none'
                                    ? 'selected'
                                    : '' ?>
                            >
                                なし
                            </option>

                        </select>
                    </div>

                    <div class="form-group">

                        <label
                            style="
                                display:flex;
                                gap:8px;
                                align-items:center;
                            "
                        >
                            <input
                                type="checkbox"
                                name="auth"
                                value="1"
                                <?= !empty($s['auth'])
                                    ? 'checked'
                                    : '' ?>
                            >

                            SMTP認証を使用
                        </label>

                    </div>

                    <div class="form-group">
                        <label>
                            SMTPユーザー名
                        </label>

                        <input
                            type="text"
                            name="username"
                            value="<?= e(
                                $s['username']
                            ) ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label>
                            SMTPパスワード
                        </label>

                        <input
                            type="password"
                            name="mail_password"
                            placeholder="変更する場合のみ入力"
                        >
                    </div>

                    <div class="form-group">
                        <label>
                            送信元メールアドレス
                        </label>

                        <input
                            type="email"
                            name="from_email"
                            value="<?= e(
                                $s['from_email']
                            ) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>
                            送信元名
                        </label>

                        <input
                            type="text"
                            name="from_name"
                            value="<?= e(
                                $s['from_name']
                            ) ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label>
                            返信先メールアドレス
                        </label>

                        <input
                            type="email"
                            name="reply_to"
                            value="<?= e(
                                $s['reply_to']
                            ) ?>"
                        >
                    </div>

                    <button
                        class="btn btn-primary"
                        type="submit"
                    >
                        設定保存
                    </button>

                </form>

            </div>
        </div>

        <div class="card">

            <div class="card-header">
                <strong>接続確認</strong>
            </div>

            <div class="card-body">

                <div class="status-box">
                    <strong>
                        接続状態
                    </strong>

                    <p>
                        <?= e($s['status']) ?>
                    </p>
                </div>

                <form
                    method="post"
                    style="margin-top:20px"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrfToken()) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="test_mail"
                    >

                    <div class="form-group">
                        <label>
                            テスト送信先
                        </label>

                        <input
                            type="email"
                            name="test_email"
                            required
                            placeholder="test@example.com"
                        >
                    </div>

                    <button
                        class="btn btn-primary"
                        type="submit"
                    >
                        テストメール送信
                    </button>

                </form>

            </div>

        </div>

    </div>

    <?php
    renderFooter();
    exit;
}

/* =========================================================
 * 送信
 * ========================================================= */

if ($screen === 'send') {
    $id = (string)($_GET['id'] ?? '');

    $survey = getSurvey($id);

    if (!$survey) {
        flash(
            'アンケートが存在しません。',
            'danger'
        );

        redirect(baseUrl(['screen'=>'list']));
    }

    $customers = $data['customers'];

    $customerSearch =
        trim((string)($_GET['q'] ?? ''));

    if ($customerSearch !== '') {
        $customers = array_filter(
            $customers,
            function($customer) use ($customerSearch) {
                return
                    mb_stripos(
                        ($customer['name'] ?? '') .
                        ' ' .
                        ($customer['email'] ?? '') .
                        ' ' .
                        ($customer['organization'] ?? ''),
                        $customerSearch
                    ) !== false;
            }
        );
    }

    $history = array_reverse(
        array_values(
            array_filter(
                $data['send_history'],
                fn($item) =>
                    ($item['survey_id'] ?? '') === $id
            )
        )
    );

    renderHeader(
        '顧客選択・メール送信',
        'list'
    );
    ?>

    <div class="page-title">
        <div>
            <h1>顧客選択・メール送信</h1>
        </div>
    </div>

    <div class="target-banner">
        <div class="label">
            対象アンケート
        </div>

        <div class="title">
            <?= e($survey['title']) ?>
        </div>
    </div>

    <div class="card">

        <div class="card-body">

            <div class="send-tabs">

                <a
                    class="send-tab active"
                    href="<?= e(baseUrl([
                        'screen'=>'send',
                        'id'=>$id
                    ])) ?>"
                >
                    顧客選択・送信
                </a>

                <a
                    class="send-tab"
                    href="#history"
                >
                    送信履歴
                </a>

            </div>

            <form method="get"
                  style="margin-bottom:15px">

                <input
                    type="hidden"
                    name="screen"
                    value="send"
                >

                <input
                    type="hidden"
                    name="id"
                    value="<?= e($id) ?>"
                >

                <div class="search-box">
                    <input
                        type="text"
                        name="q"
                        value="<?= e(
                            $customerSearch
                        ) ?>"
                        placeholder="顧客検索"
                    >

                    <button type="submit">
                        検索
                    </button>
                </div>

            </form>

            <form method="post">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrfToken()) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="send_mail"
                >

                <input
                    type="hidden"
                    name="survey_id"
                    value="<?= e($id) ?>"
                >

                <div class="table-wrap">

                    <table
                        class="customer-table"
                    >
                        <thead>
                        <tr>
                            <th>
                                <input
                                    type="checkbox"
                                    onclick="
                                        document
                                        .querySelectorAll(
                                            '.customer-check'
                                        )
                                        .forEach(
                                            c => c.checked =
                                                this.checked
                                        )
                                    "
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

                        <?php foreach (
                            $customers
                            as $customer
                        ): ?>

                            <tr>
                                <td>
                                    <input
                                        class="customer-check"
                                        type="checkbox"
                                        name="customer_ids[]"
                                        value="<?= e(
                                            $customer['id']
                                        ) ?>"
                                    >
                                </td>

                                <td>
                                    <?= e(
                                        $customer['organization']
                                        ?? ''
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $customer['name']
                                        ?? ''
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $customer['email']
                                        ?? ''
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $customer['department']
                                        ?? ''
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $customer['phone']
                                        ?? ''
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $customer['address']
                                        ?? ''
                                    ) ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                        </tbody>
                    </table>

                </div>

                <div
                    class="template-grid"
                    style="margin-top:20px"
                >

                    <div>

                        <div class="form-group">
                            <label>
                                メール件名
                            </label>

                            <input
                                type="text"
                                name="subject"
                                value="<?= e(
                                    $survey['title'] .
                                    ' のご案内'
                                ) ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label>
                                メール本文
                            </label>

                            <textarea
                                name="body"
                                style="min-height:230px"
                            >{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>
                        </div>

                    </div>

                    <div>

                        <label>
                            送信文確認
                        </label>

                        <div class="mail-preview">
{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。
                        </div>

                        <p class="help">
                            利用可能な変数：
                            {顧客名} / {アンケートURL}
                        </p>

                    </div>

                </div>

                <div
                    class="actions"
                    style="margin-top:20px"
                >
                    <button
                        class="btn btn-primary"
                        type="submit"
                        onclick="
                            return confirmAction(
                                '選択した顧客へメールを送信しますか？'
                            )
                        "
                    >
                        一括送信
                    </button>
                </div>

            </form>

        </div>
    </div>

    <div
        id="history"
        class="card"
        style="margin-top:20px"
    >

        <div class="card-header">
            <strong>
                送信履歴
            </strong>
        </div>

        <div class="card-body">

            <?php if (!$history): ?>

                <div class="empty">
                    送信履歴はありません。
                </div>

            <?php else: ?>

                <div class="table-wrap">

                    <table>
                        <thead>
                        <tr>
                            <th>日時</th>
                            <th>メール</th>
                            <th>状態</th>
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
                                    <?= e(
                                        $item['created_at']
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $item['email']
                                    ) ?>
                                </td>

                                <td>
                                    <?php if (
                                        $item['status']
                                        === 'sent'
                                    ): ?>

                                        <span
                                            class="badge badge-success"
                                        >
                                            送信済み
                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="badge badge-danger"
                                        >
                                            失敗
                                        </span>

                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= e(
                                        $item['message']
                                    ) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>
                    </table>

                </div>

            <?php endif; ?>

        </div>
    </div>

    <?php
    renderFooter();
    exit;
}

/* =========================================================
 * 集計
 * ========================================================= */

if ($screen === 'analytics') {
    $id = (string)($_GET['id'] ?? '');

    $survey = getSurvey($id);

    if (!$survey) {
        flash(
            'アンケートが存在しません。',
            'danger'
        );

        redirect(baseUrl(['screen'=>'list']));
    }

    $answers = array_values(
        array_filter(
            $data['answers'],
            fn($answer) =>
                ($answer['survey_id'] ?? '') === $id
        )
    );

    $sentCount = count(
        array_filter(
            $data['send_history'],
            fn($item) =>
                ($item['survey_id'] ?? '') === $id &&
                ($item['status'] ?? '') === 'sent'
        )
    );

    $answerCount = count($answers);

    $customerEmails = [];

    foreach ($data['customers'] as $customer) {
        if (!empty($customer['email'])) {
            $customerEmails[
                strtolower(
                    trim($customer['email'])
                )
            ] = true;
        }
    }

    $unregistered = 0;

    foreach ($answers as $answer) {
        $respondent =
            strtolower(
                trim(
                    (string)(
                        $answer['respondent']
                        ?? ''
                    )
                )
            );

        if (
            $respondent !== '' &&
            !isset($customerEmails[$respondent])
        ) {
            $unregistered++;
        }
    }

    $unanswered =
        max(
            0,
            $sentCount - $answerCount
        );

    $rate =
        $sentCount > 0
            ? round(
                ($answerCount / $sentCount) * 100,
                1
            )
            : 0;

    renderHeader(
        '回答集計・分析',
        'list'
    );
    ?>

    <div class="page-title">
        <div>
            <h1>回答集計・分析</h1>
        </div>

        <div class="actions">

            <a
                class="btn"
                href="<?= e(baseUrl([
                    'screen'=>'csv',
                    'id'=>$id
                ])) ?>"
            >
                CSV
            </a>

            <a
                class="btn"
                href="<?= e(baseUrl([
                    'screen'=>'pdf',
                    'id'=>$id
                ])) ?>"
            >
                PDF
            </a>

        </div>
    </div>

    <div class="target-banner">
        <div class="label">
            対象アンケート
        </div>

        <div class="title">
            <?= e($survey['title']) ?>
        </div>
    </div>

    <div class="summary-grid">

        <div class="summary-card">
            送信対象者数
            <div class="number">
                <?= e($sentCount) ?>
            </div>
        </div>

        <div class="summary-card">
            回答数
            <div class="number">
                <?= e($answerCount) ?>
            </div>
        </div>

        <div class="summary-card">
            未登録回答数
            <div class="number">
                <?= e($unregistered) ?>
            </div>
        </div>

        <div class="summary-card">
            未回答数
            <div class="number">
                <?= e($unanswered) ?>
            </div>
        </div>

        <div class="summary-card">
            回答率
            <div class="number">
                <?= e($rate) ?>%
            </div>
        </div>

    </div>

    <?php if ($answerCount === 0): ?>

        <div class="card">
            <div class="empty">
                現在、回答データはありません
            </div>
        </div>

    <?php else: ?>

        <?php
        $questions = [];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                $questions[] = $question;
            }
        }
        ?>

        <div class="card">

            <div class="card-header">
                <strong>
                    設問別集計
                </strong>
            </div>

            <div class="card-body">

                <?php foreach (
                    $questions
                    as $question
                ): ?>

                    <?php
                    $counts = [];

                    foreach (
                        $question['options']
                        as $option
                    ) {
                        $counts[$option] = 0;
                    }

                    $answered = 0;

                    foreach (
                        $answers
                        as $answer
                    ) {
                        $value =
                            $answer['answers'][
                                $question['id']
                            ] ?? '';

                        if (
                            $value !== '' &&
                            $value !== []
                        ) {
                            $answered++;
                        }

                        if (is_array($value)) {
                            foreach ($value as $v) {
                                if (
                                    isset(
                                        $counts[$v]
                                    )
                                ) {
                                    $counts[$v]++;
                                }
                            }
                        } elseif (
                            isset($counts[$value])
                        ) {
                            $counts[$value]++;
                        }
                    }
                    ?>

                    <div
                        style="
                            margin-bottom:30px;
                        "
                    >

                        <h3>
                            <?= e(
                                $question['number']
                            ) ?>
                            <?= e(
                                $question['text']
                            ) ?>
                        </h3>

                        <?php if (
                            $question['type']
                            === 'text'
                        ): ?>

                            <p>
                                回答件数：
                                <?= e($answered) ?>
                            </p>

                        <?php else: ?>

                            <?php foreach (
                                $counts
                                as $option => $count
                            ): ?>

                                <?php
                                $percent =
                                    $answerCount > 0
                                        ? (
                                            $count /
                                            $answerCount
                                        ) * 100
                                        : 0;
                                ?>

                                <div
                                    style="
                                        margin-bottom:10px;
                                    "
                                >
                                    <div>
                                        <?= e($option) ?>
                                        ：
                                        <?= e($count) ?>
                                        件
                                        （<?= e(
                                            round(
                                                $percent,
                                                1
                                            )
                                        ) ?>%）
                                    </div>

                                    <div class="bar">
                                        <span
                                            style="
                                                width:
                                                <?= e(
                                                    $percent
                                                ) ?>%;
                                            "
                                        ></span>
                                    </div>
                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

        <div
            class="card"
            style="margin-top:20px"
        >

            <div class="card-header">
                <strong>
                    個別回答
                </strong>
            </div>

            <div class="card-body">

                <div class="answer-list">

                <?php foreach (
                    $answers
                    as $answer
                ): ?>

                    <div class="answer-item">

                        <strong>
                            <?= e(
                                $answer['respondent']
                            ) ?>
                        </strong>

                        <span class="help">
                            <?= e(
                                $answer['created_at']
                            ) ?>
                        </span>

                        <hr>

                        <?php foreach (
                            $questions
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
                                        $value
                                    );
                            }
                            ?>

                            <p>
                                <strong>
                                    <?= e(
                                        $question['number']
                                    ) ?>
                                </strong>

                                <?= e(
                                    $question['text']
                                ) ?>

                                <br>

                                <?= nl2br(
                                    e($value)
                                ) ?>
                            </p>

                        <?php endforeach; ?>

                    </div>

                <?php endforeach; ?>

                </div>

            </div>

        </div>

    <?php endif; ?>

    <?php
    renderFooter();
    exit;
}

/* =========================================================
 * 未知画面
 * ========================================================= */

redirect(baseUrl(['screen'=>'list']));
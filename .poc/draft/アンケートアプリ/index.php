<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし
 *
 * 重要:
 * - 管理者ログイン認証はPOCでは実装しない
 * - 管理系POSTにはCSRFを要求する
 * - kintoneは実接続
 * - SMTPは実接続
 * - データはWeb公開領域外を優先して保存
 *
 * 推奨配置:
 *
 * /var/www/
 *   survey/
 *     index.php
 *   survey-data/
 *
 * または SURVEY_DATA_DIR 環境変数で保存先を指定する。
 */

session_name('survey_app_session');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

date_default_timezone_set('Asia/Tokyo');

/* ============================================================
 * 基本設定
 * ============================================================ */

const APP_NAME = 'アンケート管理システム';
const APP_VERSION = '1.0.0';

$defaultDataDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'survey-data';
$dataDir = getenv('SURVEY_DATA_DIR') ?: $defaultDataDir;

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0700, true);
}

if (!is_dir($dataDir)) {
    http_response_code(500);
    exit('データ保存ディレクトリを作成できません。SURVEY_DATA_DIRを確認してください。');
}

$files = [
    'surveys'   => $dataDir . '/surveys.json',
    'answers'   => $dataDir . '/answers.json',
    'customers' => $dataDir . '/customers.json',
    'settings'  => $dataDir . '/settings.json',
    'mail_log'  => $dataDir . '/mail_log.json',
];

foreach ($files as $file) {
    if (!file_exists($file)) {
        @file_put_contents($file, "{}\n", LOCK_EX);
        @chmod($file, 0600);
    }
}

/* ============================================================
 * 共通関数
 * ============================================================ */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function nowIso(): string
{
    return date('c');
}

function uuid(): string
{
    return bin2hex(random_bytes(16));
}

function readJsonFile(string $file, mixed $default = []): mixed
{
    $fp = @fopen($file, 'rb');

    if (!$fp) {
        return $default;
    }

    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return $default;
    }

    $contents = stream_get_contents($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    if ($contents === false || trim($contents) === '') {
        return $default;
    }

    $data = json_decode($contents, true);

    return json_last_error() === JSON_ERROR_NONE ? $data : $default;
}

function writeJsonFile(string $file, mixed $data): bool
{
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
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

    $ok = fwrite($fp, $json . "\n") !== false;

    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if (!$ok) {
        @unlink($tmp);
        return false;
    }

    @chmod($tmp, 0600);

    return @rename($tmp, $file);
}

function loadStore(string $name): array
{
    global $files;

    $data = readJsonFile($files[$name], []);

    return is_array($data) ? $data : [];
}

function saveStore(string $name, array $data): bool
{
    global $files;

    return writeJsonFile($files[$name], $data);
}

/* ============================================================
 * CSRF
 * ============================================================ */

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrfToken()) . '">';
}

function verifyCsrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $provided = (string)($_POST['_csrf'] ?? '');
    $expected = (string)($_SESSION['csrf_token'] ?? '');

    if (
        $expected === '' ||
        $provided === '' ||
        !hash_equals($expected, $provided)
    ) {
        http_response_code(403);
        exit('CSRFトークンが不正です。ページを再読み込みして再実行してください。');
    }
}

/* ============================================================
 * 入力値
 * ============================================================ */

function postString(string $name, string $default = ''): string
{
    $value = $_POST[$name] ?? $default;

    if (is_array($value)) {
        return $default;
    }

    return trim((string)$value);
}

function postInt(string $name, int $default = 0): int
{
    $value = postString($name);

    return filter_var($value, FILTER_VALIDATE_INT) !== false
        ? (int)$value
        : $default;
}

function validateEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validatePort(string $port): bool
{
    return preg_match('/^[0-9]{1,5}$/', $port) === 1
        && (int)$port >= 1
        && (int)$port <= 65535;
}

/**
 * host:port
 *
 * IPv4 / hostname / [IPv6]:port に対応。
 */
function parseHostPort(string $value): ?array
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    if (preg_match('/^\[([0-9a-fA-F:]+)\]:([0-9]{1,5})$/', $value, $m)) {
        if (!validatePort($m[2])) {
            return null;
        }

        return [
            'host' => $m[1],
            'port' => (int)$m[2],
        ];
    }

    if (preg_match('/^([^:\/\s]+):([0-9]{1,5})$/', $value, $m)) {
        if (!validatePort($m[2])) {
            return null;
        }

        return [
            'host' => $m[1],
            'port' => (int)$m[2],
        ];
    }

    return null;
}

function parseUrlHost(string $url): ?array
{
    $parts = parse_url($url);

    if (!$parts || empty($parts['host'])) {
        return null;
    }

    return [
        'scheme' => strtolower($parts['scheme'] ?? 'https'),
        'host'   => $parts['host'],
        'port'   => (int)($parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80)),
        'path'   => ($parts['path'] ?? '/') .
                    (isset($parts['query']) ? '?' . $parts['query'] : ''),
    ];
}

/* ============================================================
 * アンケート
 * ============================================================ */

function defaultSurvey(): array
{
    return [
        'id' => 'survey-' . uuid(),
        'title' => '',
        'description' => '',
        'startAt' => '',
        'endAt' => '',
        'status' => 'draft',
        'numbering' => 'global',
        'createdAt' => nowIso(),
        'updatedAt' => nowIso(),
        'groups' => [
            [
                'id' => 'group-' . uuid(),
                'title' => 'グループ1',
                'questions' => [
                    [
                        'id' => 'question-' . uuid(),
                        'number' => 'Q1',
                        'text' => '',
                        'type' => 'single',
                        'required' => false,
                        'options' => [
                            ['id' => 'option-' . uuid(), 'text' => '選択肢1'],
                            ['id' => 'option-' . uuid(), 'text' => '選択肢2'],
                        ],
                        'branches' => [],
                    ],
                ],
            ],
        ],
    ];
}

function surveyById(string $id): ?array
{
    $surveys = loadStore('surveys');

    return $surveys[$id] ?? null;
}

function surveyStatusLabel(string $status): string
{
    return match ($status) {
        'draft' => '下書き',
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => $status,
    };
}

function surveyStatusClass(string $status): string
{
    return match ($status) {
        'published' => 'success',
        'stopped' => 'warning',
        'ended' => 'gray',
        default => 'draft',
    };
}

function updateAutomaticStatus(array &$survey): bool
{
    if (
        ($survey['status'] ?? '') === 'published' &&
        !empty($survey['endAt'])
    ) {
        $end = strtotime((string)$survey['endAt']);

        if ($end !== false && $end < time()) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = nowIso();
            return true;
        }
    }

    return false;
}

function renumberQuestions(array &$survey): void
{
    $global = 1;

    foreach ($survey['groups'] as $gi => &$group) {
        $local = 1;

        foreach ($group['questions'] as &$question) {
            if (($survey['numbering'] ?? 'global') === 'group') {
                $question['number'] = 'Q' . ($gi + 1) . '-' . $local;
            } else {
                $question['number'] = 'Q' . $global;
            }

            $local++;
            $global++;
        }
    }

    unset($group, $question);
}

/* ============================================================
 * 回答
 * ============================================================ */

function loadAnswers(): array
{
    return loadStore('answers');
}

function saveAnswer(array $answer): bool
{
    $answers = loadAnswers();

    $id = $answer['id'];

    $answers[$id] = $answer;

    return saveStore('answers', $answers);
}

function surveyAnswers(string $surveyId): array
{
    $answers = loadAnswers();

    return array_values(array_filter(
        $answers,
        static fn($answer) => ($answer['surveyId'] ?? '') === $surveyId
    ));
}

function answerCount(string $surveyId): int
{
    return count(surveyAnswers($surveyId));
}

/* ============================================================
 * kintone設定
 * ============================================================ */

function defaultSettings(): array
{
    return [
        'kintone' => [
            'subdomain' => '',
            'app_id' => '',
            'login_name' => '',
            'password' => '',
            'proxy' => '',
            'verify_ssl' => true,
            'field_org' => '組織名',
            'field_name' => '氏名',
            'field_email' => 'メールアドレス',
            'field_department' => '部署名',
            'field_phone' => '電話番号',
            'field_address' => '住所',
        ],
        'mail' => [
            'server' => '',
            'port' => '587',
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => APP_NAME,
            'reply_to' => '',
        ],
    ];
}

function loadSettings(): array
{
    $settings = loadStore('settings');

    $defaults = defaultSettings();

    return array_replace_recursive($defaults, $settings);
}

function saveSettings(array $settings): bool
{
    return saveStore('settings', $settings);
}

/* ============================================================
 * kintone HTTP
 *
 * PHP cURLは使用しない。
 * stream_socket_client()を使用する。
 * HTTPS Proxyの場合はCONNECTを使用する。
 * ============================================================ */

function kintoneHttpRequest(
    string $method,
    string $url,
    array $headers,
    string $body = '',
    ?array $proxy = null,
    bool $verifySsl = true,
    int $timeout = 15
): array {
    $parsed = parseUrlHost($url);

    if ($parsed === null) {
        throw new RuntimeException('kintone URLが不正です。');
    }

    $targetHost = $parsed['host'];
    $targetPort = $parsed['port'];
    $targetPath = $parsed['path'];

    $sslOptions = [
        'verify_peer' => $verifySsl,
        'verify_peer_name' => $verifySsl,
        'allow_self_signed' => !$verifySsl,
        'SNI_enabled' => true,
        'peer_name' => $targetHost,
    ];

    $context = stream_context_create([
        'ssl' => $sslOptions,
    ]);

    $transportHost = $targetHost;
    $transportPort = $targetPort;

    if ($proxy !== null) {
        $transportHost = $proxy['host'];
        $transportPort = $proxy['port'];
    }

    $socket = @stream_socket_client(
        'tcp://' . $transportHost . ':' . $transportPort,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        throw new RuntimeException(
            '接続できません: ' . $errstr . ' (' . $errno . ')'
        );
    }

    stream_set_timeout($socket, $timeout);

    $isHttps = $parsed['scheme'] === 'https';

    if ($proxy !== null) {
        $connect =
            "CONNECT {$targetHost}:{$targetPort} HTTP/1.1\r\n" .
            "Host: {$targetHost}:{$targetPort}\r\n" .
            "Proxy-Connection: Keep-Alive\r\n\r\n";

        fwrite($socket, $connect);

        $response = '';

        while (!feof($socket)) {
            $line = fgets($socket);

            if ($line === false) {
                break;
            }

            $response .= $line;

            if (str_ends_with($response, "\r\n\r\n")) {
                break;
            }
        }

        if (!preg_match('/^HTTP\/\d\.\d\s+2\d\d\b/i', $response)) {
            fclose($socket);
            throw new RuntimeException(
                'Proxy CONNECTに失敗しました。'
            );
        }
    }

    if ($isHttps) {
        $crypto = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);
            throw new RuntimeException(
                'TLS接続に失敗しました。SSL証明書検証設定を確認してください。'
            );
        }
    }

    $request =
        $method . ' ' . $targetPath . " HTTP/1.1\r\n" .
        'Host: ' . $targetHost . "\r\n" .
        "Connection: close\r\n";

    foreach ($headers as $name => $value) {
        $request .= $name . ': ' . $value . "\r\n";
    }

    $request .=
        'Content-Length: ' . strlen($body) . "\r\n" .
        "\r\n";

    if ($body !== '') {
        $request .= $body;
    }

    fwrite($socket, $request);

    $raw = '';

    while (!feof($socket)) {
        $chunk = fread($socket, 8192);

        if ($chunk === false || $chunk === '') {
            break;
        }

        $raw .= $chunk;

        if (strlen($raw) > 20 * 1024 * 1024) {
            fclose($socket);
            throw new RuntimeException('レスポンスサイズが大きすぎます。');
        }
    }

    fclose($socket);

    [$headerText, $responseBody] = array_pad(
        preg_split("/\r\n\r\n/", $raw, 2),
        2,
        ''
    );

    $lines = preg_split("/\r\n/", $headerText);

    $statusCode = 0;

    if (!empty($lines[0]) &&
        preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $lines[0], $m)
    ) {
        $statusCode = (int)$m[1];
    }

    $responseHeaders = [];

    foreach ($lines as $index => $line) {
        if ($index === 0) {
            continue;
        }

        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }
    }

    return [
        'status' => $statusCode,
        'headers' => $responseHeaders,
        'body' => $responseBody,
    ];
}

function kintoneAuthorization(string $login, string $password): string
{
    return base64_encode($login . ':' . $password);
}

function kintoneRequest(
    string $method,
    string $path,
    array $payload = []
): array {
    $settings = loadSettings()['kintone'];

    if (
        $settings['subdomain'] === '' ||
        $settings['app_id'] === '' ||
        $settings['login_name'] === '' ||
        $settings['password'] === ''
    ) {
        throw new RuntimeException(
            'kintone設定が不足しています。'
        );
    }

    $subdomain = trim($settings['subdomain']);

    $subdomain = preg_replace(
        '#^https?://#i',
        '',
        $subdomain
    );

    $subdomain = trim($subdomain, '/');

    $url = 'https://' . $subdomain . '.cybozu.com' . $path;

    $proxy = null;

    if (trim((string)$settings['proxy']) !== '') {
        $proxy = parseHostPort((string)$settings['proxy']);

        if ($proxy === null) {
            throw new RuntimeException(
                'Proxyはhost:port形式で入力してください。'
            );
        }
    }

    $headers = [
        'X-Cybozu-Authorization' =>
            kintoneAuthorization(
                (string)$settings['login_name'],
                (string)$settings['password']
            ),
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];

    $body = $payload !== []
        ? json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )
        : '';

    if ($body === false) {
        throw new RuntimeException('kintoneリクエストJSON生成に失敗しました。');
    }

    $response = kintoneHttpRequest(
        $method,
        $url,
        $headers,
        $body,
        $proxy,
        (bool)$settings['verify_ssl'],
        15
    );

    $decoded = json_decode($response['body'], true);

    if (
        $response['status'] < 200 ||
        $response['status'] >= 300
    ) {
        $message = 'kintone APIエラー';

        if (is_array($decoded) && !empty($decoded['message'])) {
            $message .= ': ' . $decoded['message'];
        }

        throw new RuntimeException($message);
    }

    return is_array($decoded) ? $decoded : [];
}

function testKintoneConnection(): array
{
    $settings = loadSettings()['kintone'];

    $appId = (int)$settings['app_id'];

    $response = kintoneRequest(
        'GET',
        '/k/v1/app.json?id=' . rawurlencode((string)$appId)
    );

    return [
        'success' => true,
        'message' => 'kintoneへの接続に成功しました。',
        'app' => $response,
    ];
}

function syncKintoneCustomers(): array
{
    $settings = loadSettings()['kintone'];

    $appId = (int)$settings['app_id'];

    $fieldCodes = [
        $settings['field_org'],
        $settings['field_name'],
        $settings['field_email'],
        $settings['field_department'],
        $settings['field_phone'],
        $settings['field_address'],
    ];

    $fieldCodes = array_values(array_filter(
        array_unique($fieldCodes),
        static fn($v) => trim((string)$v) !== ''
    ));

    $query = [
        'app' => $appId,
        'query' => 'order by $id asc limit 500',
        'fields' => $fieldCodes,
    ];

    $result = kintoneRequest(
        'GET',
        '/k/v1/records.json?' . http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        )
    );

    $records = $result['records'] ?? [];

    $customers = [];

    foreach ($records as $record) {
        $get = static function (
            array $record,
            string $field
        ): string {
            if ($field === '' || !isset($record[$field])) {
                return '';
            }

            $value = $record[$field]['value'] ?? '';

            if (is_array($value)) {
                return implode(', ', array_map(
                    static fn($v) => is_scalar($v) ? (string)$v : '',
                    $value
                ));
            }

            return (string)$value;
        };

        $customers[] = [
            'id' => uuid(),
            'kintone_id' => $record['$id']['value'] ?? '',
            'organization' => $get($record, $settings['field_org']),
            'name' => $get($record, $settings['field_name']),
            'email' => $get($record, $settings['field_email']),
            'department' => $get($record, $settings['field_department']),
            'phone' => $get($record, $settings['field_phone']),
            'address' => $get($record, $settings['field_address']),
            'syncedAt' => nowIso(),
        ];
    }

    $data = [];

    foreach ($customers as $customer) {
        $data[$customer['id']] = $customer;
    }

    if (!saveStore('customers', $data)) {
        throw new RuntimeException(
            '顧客データの保存に失敗しました。'
        );
    }

    return [
        'count' => count($customers),
    ];
}

/* ============================================================
 * SMTP
 *
 * 外部ライブラリなし。
 * AUTH LOGIN / AUTH PLAINに対応。
 * SSL / TLS / 平文。
 * ============================================================ */

function smtpRead($socket): array
{
    $lines = [];

    while (!feof($socket)) {
        $line = fgets($socket, 8192);

        if ($line === false) {
            break;
        }

        $lines[] = rtrim($line, "\r\n");

        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }

    if ($lines === []) {
        throw new RuntimeException('SMTPサーバーから応答がありません。');
    }

    $last = end($lines);

    if (!preg_match('/^(\d{3})/', $last, $m)) {
        throw new RuntimeException('SMTP応答を解析できません。');
    }

    return [
        'code' => (int)$m[1],
        'lines' => $lines,
    ];
}

function smtpExpect($socket, array $codes): array
{
    $response = smtpRead($socket);

    if (!in_array($response['code'], $codes, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' .
            implode(' / ', $response['lines'])
        );
    }

    return $response;
}

function smtpCommand($socket, string $command, array $codes): array
{
    fwrite($socket, $command . "\r\n");

    return smtpExpect($socket, $codes);
}

function smtpConnect(array $config)
{
    $server = trim((string)$config['server']);
    $port = (int)$config['port'];
    $encryption = strtolower((string)$config['encryption']);

    if ($server === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException('SMTPサーバまたはポートが不正です。');
    }

    $transport = $encryption === 'ssl'
        ? 'ssl://'
        : 'tcp://';

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $server,
        ],
    ]);

    $socket = @stream_socket_client(
        $transport . $server . ':' . $port,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        throw new RuntimeException(
            'SMTP接続失敗: ' . $errstr . ' (' . $errno . ')'
        );
    }

    stream_set_timeout($socket, 15);

    smtpExpect($socket, [220]);

    $hostname = gethostname() ?: 'localhost';

    smtpCommand(
        $socket,
        'EHLO ' . $hostname,
        [250]
    );

    if ($encryption === 'tls') {
        smtpCommand($socket, 'STARTTLS', [220]);

        $crypto = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);
            throw new RuntimeException('SMTP STARTTLSに失敗しました。');
        }

        smtpCommand(
            $socket,
            'EHLO ' . $hostname,
            [250]
        );
    }

    if ((bool)$config['auth']) {
        $username = (string)$config['username'];
        $password = (string)$config['password'];

        smtpCommand(
            $socket,
            'AUTH LOGIN',
            [334]
        );

        smtpCommand(
            $socket,
            base64_encode($username),
            [334]
        );

        smtpCommand(
            $socket,
            base64_encode($password),
            [235]
        );
    }

    return $socket;
}

function smtpSendMail(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    if (!validateEmail($to)) {
        throw new RuntimeException(
            '送信先メールアドレスが不正です。'
        );
    }

    $from = trim((string)$config['from_email']);

    if (!validateEmail($from)) {
        throw new RuntimeException(
            '送信元メールアドレスが不正です。'
        );
    }

    $socket = smtpConnect($config);

    try {
        smtpCommand(
            $socket,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        smtpCommand(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        smtpCommand(
            $socket,
            'DATA',
            [354]
        );

        $fromName = trim((string)$config['from_name']);

        $headers = [];

        $headers[] = 'From: ' .
            ($fromName !== ''
                ? '=?UTF-8?B?' .
                  base64_encode($fromName) .
                  '?= '
                : '') .
            '<' . $from . '>';

        $headers[] = 'To: <' . $to . '>';

        $headers[] = 'Subject: =?UTF-8?B?' .
            base64_encode($subject) .
            '?=';

        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        $replyTo = trim((string)$config['reply_to']);

        if ($replyTo !== '' && validateEmail($replyTo)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $message = implode(
            "\r\n",
            $headers
        ) . "\r\n\r\n";

        $message .= preg_replace(
            "/(?<!\r)\n/",
            "\r\n",
            $body
        );

        $message = preg_replace(
            '/^\./m',
            '..',
            $message
        );

        fwrite($socket, $message . "\r\n.\r\n");

        smtpExpect($socket, [250]);

        smtpCommand($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function smtpTest(): void
{
    $settings = loadSettings()['mail'];

    $testTo = trim((string)($_POST['test_to'] ?? ''));

    if (!validateEmail($testTo)) {
        throw new RuntimeException(
            'テスト送信先メールアドレスを正しく入力してください。'
        );
    }

    smtpSendMail(
        $settings,
        $testTo,
        '【テスト】' . APP_NAME,
        "SMTP接続テストです。\n\nこのメールが届けばSMTP設定は正常です。"
    );
}

/* ============================================================
 * CSV
 * ============================================================ */

function outputCsv(array $survey): never
{
    $answers = surveyAnswers($survey['id']);

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' .
        rawurlencode($survey['id']) .
        '-answers.csv"'
    );

    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');

    $headers = [
        '回答ID',
        '回答日時',
        'メールアドレス',
        '顧客名',
    ];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $headers[] = $question['number'] . ' ' . $question['text'];
        }
    }

    fputcsv($out, $headers);

    foreach ($answers as $answer) {
        $row = [
            $answer['id'] ?? '',
            $answer['createdAt'] ?? '',
            $answer['email'] ?? '',
            $answer['customerName'] ?? '',
        ];

        $values = $answer['values'] ?? [];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                $value = $values[$question['id']] ?? '';

                if (is_array($value)) {
                    $value = implode(', ', $value);
                }

                $row[] = $value;
            }
        }

        fputcsv($out, $row);
    }

    fclose($out);
    exit;
}

/* ============================================================
 * 簡易PDF
 *
 * 外部ライブラリなし。
 * 日本語フォントを埋め込まないため、ASCII中心の簡易帳票。
 * ============================================================ */

function pdfEscape(string $text): string
{
    return str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $text
    );
}

function outputSimplePdf(array $survey): never
{
    $answers = surveyAnswers($survey['id']);

    $lines = [];

    $lines[] = 'Survey Report';
    $lines[] = 'Title: ' . preg_replace('/[^\x20-\x7E]/', '?', $survey['title']);
    $lines[] = 'Answers: ' . count($answers);
    $lines[] = '';

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $lines[] =
                $question['number'] . ': ' .
                preg_replace(
                    '/[^\x20-\x7E]/',
                    '?',
                    $question['text']
                );
        }
    }

    $lines = array_slice($lines, 0, 45);

    $content = "BT\n/F1 10 Tf\n50 780 Td\n";

    foreach ($lines as $index => $line) {
        if ($index > 0) {
            $content .= "0 -16 Td\n";
        }

        $content .= '(' .
            pdfEscape($line) .
            ") Tj\n";
    }

    $content .= "ET\n";

    $objects = [];

    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[] =
        "<< /Type /Page /Parent 2 0 R " .
        "/MediaBox [0 0 595 842] " .
        "/Resources << /Font << /F1 5 0 R >> >> " .
        "/Contents 4 0 R >>";
    $objects[] =
        "<< /Length " . strlen($content) . " >>\nstream\n" .
        $content .
        "endstream";
    $objects[] =
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $i => $object) {
        $num = $i + 1;

        $offsets[$num] = strlen($pdf);

        $pdf .=
            $num . " 0 obj\n" .
            $object .
            "\nendobj\n";
    }

    $xref = strlen($pdf);

    $pdf .=
        "xref\n" .
        "0 " . (count($objects) + 1) . "\n" .
        "0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf(
            "%010d 00000 n \n",
            $offsets[$i]
        );
    }

    $pdf .=
        "trailer\n" .
        "<< /Size " . (count($objects) + 1) .
        " /Root 1 0 R >>\n" .
        "startxref\n" .
        $xref .
        "\n%%EOF";

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="' .
        rawurlencode($survey['id']) .
        '-report.pdf"'
    );

    echo $pdf;
    exit;
}

/* ============================================================
 * 共通HTML
 * ============================================================ */

function pageStart(
    string $title,
    string $screen = 'list'
): void {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - <?= h(APP_NAME) ?></title>

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

button,
input,
textarea,
select{
    font:inherit;
}

button{
    cursor:pointer;
}

.header{
    background:#0f172a;
    color:#fff;
    min-height:64px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 24px;
}

.header-title{
    font-weight:700;
    font-size:18px;
}

.header-nav{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.header-nav a{
    color:#cbd5e1;
    padding:7px 10px;
    border-radius:7px;
}

.header-nav a:hover,
.header-nav a.active{
    color:#fff;
    background:#1e293b;
}

.container{
    width:min(1400px,calc(100% - 32px));
    margin:28px auto 60px;
}

.page-heading{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
    margin-bottom:20px;
}

.page-heading h1{
    font-size:24px;
    margin:0;
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
    gap:20px;
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

label{
    display:block;
    font-weight:600;
    margin-bottom:7px;
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
    min-height:110px;
    resize:vertical;
}

input:focus,
select:focus,
textarea:focus{
    outline:3px solid rgba(37,99,235,.13);
    border-color:var(--primary);
}

.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:1px solid transparent;
    border-radius:8px;
    padding:9px 14px;
    font-weight:600;
    gap:6px;
    background:#fff;
    color:var(--text);
    text-decoration:none;
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

.btn-warning{
    background:var(--warning);
    color:#fff;
}

.btn-danger{
    background:var(--danger);
    color:#fff;
}

.btn-secondary{
    border-color:var(--border);
}

.btn-sm{
    padding:6px 9px;
    font-size:13px;
}

.actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    align-items:center;
}

.alert{
    padding:12px 15px;
    border-radius:8px;
    margin-bottom:18px;
}

.alert-success{
    background:#dcfce7;
    color:#166534;
}

.alert-error{
    background:#fee2e2;
    color:#991b1b;
}

.alert-info{
    background:#dbeafe;
    color:#1e40af;
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
    padding:11px 12px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th{
    background:#f8fafc;
    white-space:nowrap;
}

.badge{
    display:inline-flex;
    padding:3px 9px;
    border-radius:999px;
    font-size:12px;
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

.badge-draft{
    background:#dbeafe;
    color:#1e40af;
}

.section-title{
    font-size:18px;
    margin:0 0 15px;
}

.question-card,
.group-card{
    border:1px solid var(--border);
    border-radius:10px;
    background:#fff;
}

.group-card{
    margin-bottom:18px;
    overflow:hidden;
}

.group-header{
    padding:14px;
    background:#f8fafc;
    display:flex;
    gap:10px;
    align-items:center;
}

.group-body{
    padding:14px;
}

.question-card{
    padding:15px;
    margin-bottom:12px;
}

.drag-handle{
    cursor:grab;
    color:var(--gray);
    user-select:none;
}

.question-grid{
    display:grid;
    grid-template-columns:90px 1fr 180px 100px;
    gap:12px;
    align-items:start;
}

.option-row{
    display:grid;
    grid-template-columns:1fr auto;
    gap:8px;
    margin-bottom:7px;
}

.stat-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
}

.stat{
    border:1px solid var(--border);
    border-radius:10px;
    padding:15px;
    background:#fff;
}

.stat-label{
    font-size:13px;
    color:var(--gray);
}

.stat-value{
    font-size:25px;
    font-weight:700;
    margin-top:4px;
}

.empty{
    padding:35px;
    text-align:center;
    color:var(--gray);
}

.preview-question{
    margin-bottom:25px;
}

.preview-number{
    font-size:13px;
    color:var(--gray);
    font-weight:700;
}

.required{
    color:var(--danger);
}

.mobile-answer{
    max-width:720px;
    margin:0 auto;
}

.choice{
    display:block;
    border:1px solid var(--border);
    border-radius:10px;
    padding:13px;
    margin:8px 0;
    cursor:pointer;
}

.choice:hover{
    border-color:var(--primary);
    background:#eff6ff;
}

.notice{
    color:var(--gray);
    font-size:13px;
}

details{
    margin-top:15px;
}

summary{
    cursor:pointer;
    font-weight:700;
}

@media(max-width:900px){
    .grid-2,
    .grid-3,
    .stat-grid{
        grid-template-columns:1fr;
    }

    .question-grid{
        grid-template-columns:1fr;
    }

    .header{
        align-items:flex-start;
        flex-direction:column;
        gap:10px;
        padding:15px;
    }

    .container{
        width:min(100% - 20px,1400px);
        margin-top:15px;
    }

    .page-heading{
        align-items:flex-start;
        flex-direction:column;
    }
}

@media(max-width:600px){
    .card{
        padding:15px;
        border-radius:9px;
    }

    .btn{
        width:100%;
    }

    .actions .btn{
        flex:1 1 auto;
    }
}
</style>
</head>

<body>

<?php if ($screen !== 'answer' && $screen !== 'confirm' && $screen !== 'complete'): ?>
<header class="header">
    <div class="header-title"><?= h(APP_NAME) ?></div>

    <nav class="header-nav">
        <a href="index.php?screen=list"
           class="<?= $screen === 'list' ? 'active' : '' ?>">
            アンケート
        </a>

        <a href="index.php?screen=kintone"
           class="<?= $screen === 'kintone' ? 'active' : '' ?>">
            kintone
        </a>

        <a href="index.php?screen=mail"
           class="<?= $screen === 'mail' ? 'active' : '' ?>">
            メール
        </a>
    </nav>
</header>
<?php endif; ?>

<main class="<?= $screen === 'answer' || $screen === 'confirm' || $screen === 'complete'
    ? 'container mobile-answer'
    : 'container' ?>">

<?php if (is_array($flash)): ?>
    <div class="alert <?= ($flash['type'] ?? '') === 'error'
        ? 'alert-error'
        : 'alert-success' ?>">
        <?= h($flash['message'] ?? '') ?>
    </div>
<?php endif; ?>

<?php
}

function pageEnd(): void
{
    ?>
</main>

<script>
function confirmSubmit(form, message){
    if(confirm(message)){
        form.submit();
    }
    return false;
}

function confirmAction(message){
    return window.confirm(message);
}

function addOption(button){
    const container = button.closest('.options-editor');
    const row = document.createElement('div');
    row.className = 'option-row';

    row.innerHTML =
        '<input type="text" name="' +
        button.dataset.name +
        '[]" value="">' +
        '<button type="button" class="btn btn-sm btn-danger" ' +
        'onclick="this.parentElement.remove()">削除</button>';

    container.insertBefore(row, button);
}
</script>

</body>
</html>
<?php
}

/* ============================================================
 * POST処理
 * ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = postString('action');

    try {
        switch ($action) {

            /* ------------------------------------------------
             * アンケート保存
             * ------------------------------------------------ */

            case 'save_survey':

                $id = postString('id');

                $surveys = loadStore('surveys');

                if ($id !== '' && isset($surveys[$id])) {
                    $survey = $surveys[$id];
                } else {
                    $survey = defaultSurvey();
                    $id = $survey['id'];
                }

                $survey['title'] = postString('title');
                $survey['description'] = postString('description');
                $survey['startAt'] = postString('startAt');
                $survey['endAt'] = postString('endAt');
                $survey['numbering'] = in_array(
                    postString('numbering'),
                    ['global', 'group'],
                    true
                )
                    ? postString('numbering')
                    : 'global';

                if ($survey['title'] === '') {
                    throw new RuntimeException(
                        'アンケートタイトルは必須です。'
                    );
                }

                if (
                    $survey['startAt'] !== '' &&
                    $survey['endAt'] !== ''
                ) {
                    $start = strtotime($survey['startAt']);
                    $end = strtotime($survey['endAt']);

                    if (
                        $start !== false &&
                        $end !== false &&
                        $end <= $start
                    ) {
                        throw new RuntimeException(
                            '終了日時は開始日時より後にしてください。'
                        );
                    }
                }

                $groups = $_POST['groups'] ?? [];

                if (!is_array($groups)) {
                    $groups = [];
                }

                $normalizedGroups = [];

                foreach ($groups as $groupData) {

                    if (!is_array($groupData)) {
                        continue;
                    }

                    $groupId = (string)($groupData['id'] ?? '');

                    if ($groupId === '') {
                        $groupId = 'group-' . uuid();
                    }

                    $group = [
                        'id' => $groupId,
                        'title' => trim(
                            (string)($groupData['title'] ?? '')
                        ),
                        'questions' => [],
                    ];

                    $questions = $groupData['questions'] ?? [];

                    if (!is_array($questions)) {
                        $questions = [];
                    }

                    foreach ($questions as $questionData) {

                        if (!is_array($questionData)) {
                            continue;
                        }

                        $questionId =
                            (string)($questionData['id'] ?? '');

                        if ($questionId === '') {
                            $questionId =
                                'question-' . uuid();
                        }

                        $type = (string)(
                            $questionData['type'] ?? 'single'
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
                            $questionData['options'] ?? [];

                        if (is_array($rawOptions)) {
                            foreach ($rawOptions as $optionData) {
                                if (is_array($optionData)) {
                                    $optionText =
                                        trim(
                                            (string)(
                                                $optionData['text'] ?? ''
                                            )
                                        );

                                    $optionId =
                                        (string)(
                                            $optionData['id'] ?? ''
                                        );

                                    if ($optionId === '') {
                                        $optionId =
                                            'option-' . uuid();
                                    }

                                    if ($optionText !== '') {
                                        $options[] = [
                                            'id' => $optionId,
                                            'text' => $optionText,
                                        ];
                                    }
                                } elseif (is_scalar($optionData)) {
                                    $text = trim(
                                        (string)$optionData
                                    );

                                    if ($text !== '') {
                                        $options[] = [
                                            'id' => 'option-' . uuid(),
                                            'text' => $text,
                                        ];
                                    }
                                }
                            }
                        }

                        $branches =
                            $questionData['branches'] ?? [];

                        if (!is_array($branches)) {
                            $branches = [];
                        }

                        $group['questions'][] = [
                            'id' => $questionId,
                            'number' => '',
                            'text' => trim(
                                (string)(
                                    $questionData['text'] ?? ''
                                )
                            ),
                            'type' => $type,
                            'required' =>
                                !empty($questionData['required']),
                            'options' => $options,
                            'branches' => $branches,
                        ];
                    }

                    $normalizedGroups[] = $group;
                }

                if ($normalizedGroups === []) {
                    $normalizedGroups[] = [
                        'id' => 'group-' . uuid(),
                        'title' => 'グループ1',
                        'questions' => [],
                    ];
                }

                $survey['groups'] = $normalizedGroups;

                if (
                    ($survey['status'] ?? 'draft') === ''
                ) {
                    $survey['status'] = 'draft';
                }

                if ($survey['status'] === 'ended') {
                    // 終了状態を維持。
                }

                renumberQuestions($survey);

                $survey['updatedAt'] = nowIso();

                if (empty($survey['createdAt'])) {
                    $survey['createdAt'] = nowIso();
                }

                $surveys[$id] = $survey;

                if (!saveStore('surveys', $surveys)) {
                    throw new RuntimeException(
                        'アンケートの保存に失敗しました。'
                    );
                }

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => 'アンケートを保存しました。',
                ];

                redirect('index.php?screen=list');

            /* ------------------------------------------------
             * 状態変更
             * ------------------------------------------------ */

            case 'change_status':

                $id = postString('id');
                $newStatus = postString('new_status');

                $surveys = loadStore('surveys');

                if (!isset($surveys[$id])) {
                    throw new RuntimeException(
                        '対象アンケートが存在しません。'
                    );
                }

                $survey = $surveys[$id];

                updateAutomaticStatus($survey);

                $current = $survey['status'];

                $allowed = [
                    'draft' => ['published'],
                    'published' => ['stopped'],
                    'stopped' => ['published'],
                    'ended' => [],
                ];

                if (
                    !isset($allowed[$current]) ||
                    !in_array(
                        $newStatus,
                        $allowed[$current],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        '指定された状態変更はできません。'
                    );
                }

                $survey['status'] = $newStatus;
                $survey['updatedAt'] = nowIso();

                $surveys[$id] = $survey;

                if (!saveStore('surveys', $surveys)) {
                    throw new RuntimeException(
                        '状態変更を保存できませんでした。'
                    );
                }

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' =>
                        '状態を「' .
                        surveyStatusLabel($newStatus) .
                        '」に変更しました。',
                ];

                redirect('index.php?screen=list');

            /* ------------------------------------------------
             * 削除
             * ------------------------------------------------ */

            case 'delete_survey':

                $id = postString('id');

                $surveys = loadStore('surveys');

                if (!isset($surveys[$id])) {
                    throw new RuntimeException(
                        '対象アンケートが存在しません。'
                    );
                }

                unset($surveys[$id]);

                if (!saveStore('surveys', $surveys)) {
                    throw new RuntimeException(
                        '削除に失敗しました。'
                    );
                }

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => 'アンケートを削除しました。',
                ];

                redirect('index.php?screen=list');

            /* ------------------------------------------------
             * 複製
             * ------------------------------------------------ */

            case 'duplicate_survey':

                $id = postString('id');

                $surveys = loadStore('surveys');

                if (!isset($surveys[$id])) {
                    throw new RuntimeException(
                        '対象アンケートが存在しません。'
                    );
                }

                $copy = $surveys[$id];

                $copy['id'] = 'survey-' . uuid();
                $copy['title'] =
                    ($copy['title'] ?: 'アンケート') . '（コピー）';
                $copy['status'] = 'draft';
                $copy['createdAt'] = nowIso();
                $copy['updatedAt'] = nowIso();

                foreach ($copy['groups'] as &$group) {
                    $group['id'] = 'group-' . uuid();

                    foreach ($group['questions'] as &$question) {
                        $question['id'] = 'question-' . uuid();

                        foreach ($question['options'] as &$option) {
                            $option['id'] = 'option-' . uuid();
                        }
                    }
                }

                unset($group, $question, $option);

                renumberQuestions($copy);

                $surveys[$copy['id']] = $copy;

                if (!saveStore('surveys', $surveys)) {
                    throw new RuntimeException(
                        '複製に失敗しました。'
                    );
                }

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => 'アンケートを複製しました。',
                ];

                redirect('index.php?screen=list');

            /* ------------------------------------------------
             * kintone設定
             * ------------------------------------------------ */

            case 'save_kintone':

                $settings = loadSettings();

                $settings['kintone']['subdomain'] =
                    postString('subdomain');

                $settings['kintone']['app_id'] =
                    postString('app_id');

                $settings['kintone']['login_name'] =
                    postString('login_name');

                $password = postString('password');

                if ($password !== '') {
                    $settings['kintone']['password'] =
                        $password;
                }

                $settings['kintone']['proxy'] =
                    postString('proxy');

                $settings['kintone']['verify_ssl'] =
                    isset($_POST['verify_ssl']);

                $settings['kintone']['field_org'] =
                    postString('field_org');

                $settings['kintone']['field_name'] =
                    postString('field_name');

                $settings['kintone']['field_email'] =
                    postString('field_email');

                $settings['kintone']['field_department'] =
                    postString('field_department');

                $settings['kintone']['field_phone'] =
                    postString('field_phone');

                $settings['kintone']['field_address'] =
                    postString('field_address');

                if (
                    $settings['kintone']['proxy'] !== '' &&
                    parseHostPort(
                        $settings['kintone']['proxy']
                    ) === null
                ) {
                    throw new RuntimeException(
                        'Proxyはhost:port形式で入力してください。'
                    );
                }

                if (!preg_match(
                    '/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/',
                    $settings['kintone']['subdomain']
                )) {
                    throw new RuntimeException(
                        'kintoneサブドメインが不正です。'
                    );
                }

                if (
                    filter_var(
                        $settings['kintone']['app_id'],
                        FILTER_VALIDATE_INT
                    ) === false ||
                    (int)$settings['kintone']['app_id'] <= 0
                ) {
                    throw new RuntimeException(
                        'kintoneアプリIDが不正です。'
                    );
                }

                if (
                    $settings['kintone']['login_name'] === ''
                ) {
                    throw new RuntimeException(
                        'kintoneログイン名を入力してください。'
                    );
                }

                if (
                    $settings['kintone']['password'] === ''
                ) {
                    throw new RuntimeException(
                        'kintoneパスワードを入力してください。'
                    );
                }

                if (!saveSettings($settings)) {
                    throw new RuntimeException(
                        'kintone設定を保存できませんでした。'
                    );
                }

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => 'kintone設定を保存しました。',
                ];

                redirect('index.php?screen=kintone');

            /* ------------------------------------------------
             * kintone接続テスト
             * ------------------------------------------------ */

            case 'test_kintone':

                $result = testKintoneConnection();

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => $result['message'],
                ];

                redirect('index.php?screen=kintone');

            /* ------------------------------------------------
             * kintone同期
             * ------------------------------------------------ */

            case 'sync_kintone':

                $result = syncKintoneCustomers();

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' =>
                        'kintoneから' .
                        $result['count'] .
                        '件の顧客情報を同期しました。',
                ];

                redirect('index.php?screen=kintone');

            /* ------------------------------------------------
             * メール設定
             * ------------------------------------------------ */

            case 'save_mail':

                $settings = loadSettings();

                $mail =& $settings['mail'];

                $mail['server'] =
                    postString('server');

                $mail['port'] =
                    postString('port');

                $mail['encryption'] =
                    postString('encryption');

                $mail['auth'] =
                    isset($_POST['auth']);

                $mail['username'] =
                    postString('username');

                $password = postString('password');

                if ($password !== '') {
                    $mail['password'] = $password;
                }

                $mail['from_email'] =
                    postString('from_email');

                $mail['from_name'] =
                    postString('from_name');

                $mail['reply_to'] =
                    postString('reply_to');

                if (
                    $mail['server'] === '' ||
                    !validatePort($mail['port'])
                ) {
                    throw new RuntimeException(
                        'SMTPサーバとポートを正しく入力してください。'
                    );
                }

                if (!validateEmail($mail['from_email'])) {
                    throw new RuntimeException(
                        '送信元メールアドレスが不正です。'
                    );
                }

                if (
                    $mail['reply_to'] !== '' &&
                    !validateEmail($mail['reply_to'])
                ) {
                    throw new RuntimeException(
                        '返信先メールアドレスが不正です。'
                    );
                }

                if (!in_array(
                    $mail['encryption'],
                    ['ssl', 'tls', 'none'],
                    true
                )) {
                    throw new RuntimeException(
                        '暗号化方式が不正です。'
                    );
                }

                if (!saveSettings($settings)) {
                    throw new RuntimeException(
                        'メール設定を保存できませんでした。'
                    );
                }

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => 'メール設定を保存しました。',
                ];

                redirect('index.php?screen=mail');

            /* ------------------------------------------------
             * SMTPテスト
             * ------------------------------------------------ */

            case 'test_mail':

                smtpTest();

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' =>
                        'テストメールを送信しました。',
                ];

                redirect('index.php?screen=mail');

            /* ------------------------------------------------
             * メール一括送信
             * ------------------------------------------------ */

            case 'send_bulk_mail':

                $surveyId = postString('survey_id');

                $survey = surveyById($surveyId);

                if ($survey === null) {
                    throw new RuntimeException(
                        '対象アンケートが存在しません。'
                    );
                }

                if (
                    $survey['status'] !== 'published'
                ) {
                    throw new RuntimeException(
                        '公開中のアンケートだけ送信できます。'
                    );
                }

                $customerIds =
                    $_POST['customer_ids'] ?? [];

                if (!is_array($customerIds)) {
                    $customerIds = [];
                }

                $customers = loadStore('customers');
                $settings = loadSettings();

                $subject =
                    postString('subject');

                $body =
                    postString('body');

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

                if ($customerIds === []) {
                    throw new RuntimeException(
                        '送信対象顧客を選択してください。'
                    );
                }

                $logs = loadStore('mail_log');

                $successCount = 0;
                $failureCount = 0;

                foreach ($customerIds as $customerId) {

                    if (!isset($customers[$customerId])) {
                        continue;
                    }

                    $customer = $customers[$customerId];

                    $email = trim(
                        (string)($customer['email'] ?? '')
                    );

                    if (!validateEmail($email)) {
                        $failureCount++;

                        $logs[] = [
                            'id' => uuid(),
                            'surveyId' => $surveyId,
                            'customerId' => $customerId,
                            'email' => $email,
                            'status' => 'failed',
                            'message' => 'メールアドレス不正',
                            'createdAt' => nowIso(),
                        ];

                        continue;
                    }

                    $surveyUrl =
                        (
                            (!empty($_SERVER['HTTPS']) &&
                             $_SERVER['HTTPS'] !== 'off')
                                ? 'https'
                                : 'http'
                        ) .
                        '://' .
                        ($_SERVER['HTTP_HOST'] ?? 'localhost') .
                        '/index.php?screen=answer&id=' .
                        rawurlencode($surveyId);

                    $replace = [
                        '{顧客名}' =>
                            (string)($customer['name'] ?? ''),
                        '{アンケートURL}' =>
                            $surveyUrl,
                    ];

                    $mailSubject =
                        strtr($subject, $replace);

                    $mailBody =
                        strtr($body, $replace);

                    try {
                        smtpSendMail(
                            $settings['mail'],
                            $email,
                            $mailSubject,
                            $mailBody
                        );

                        $successCount++;

                        $logs[] = [
                            'id' => uuid(),
                            'surveyId' => $surveyId,
                            'customerId' => $customerId,
                            'email' => $email,
                            'status' => 'sent',
                            'message' => '',
                            'createdAt' => nowIso(),
                        ];
                    } catch (Throwable $e) {
                        $failureCount++;

                        $logs[] = [
                            'id' => uuid(),
                            'surveyId' => $surveyId,
                            'customerId' => $customerId,
                            'email' => $email,
                            'status' => 'failed',
                            'message' => $e->getMessage(),
                            'createdAt' => nowIso(),
                        ];
                    }
                }

                saveStore('mail_log', $logs);

                $_SESSION['flash'] = [
                    'type' => $failureCount > 0
                        ? 'error'
                        : 'success',
                    'message' =>
                        '送信完了: 成功 ' .
                        $successCount .
                        '件 / 失敗 ' .
                        $failureCount .
                        '件',
                ];

                redirect(
                    'index.php?screen=send&id=' .
                    rawurlencode($surveyId)
                );

            /* ------------------------------------------------
             * 回答保存
             * ------------------------------------------------ */

            case 'submit_answer':

                $surveyId = postString('survey_id');

                $survey = surveyById($surveyId);

                if ($survey === null) {
                    throw new RuntimeException(
                        '対象アンケートが存在しません。'
                    );
                }

                $updated = updateAutomaticStatus($survey);

                if ($updated) {
                    $surveys = loadStore('surveys');
                    $surveys[$surveyId] = $survey;
                    saveStore('surveys', $surveys);
                }

                if ($survey['status'] !== 'published') {
                    throw new RuntimeException(
                        'このアンケートは現在回答できません。'
                    );
                }

                $values = $_POST['answer'] ?? [];

                if (!is_array($values)) {
                    $values = [];
                }

                foreach ($survey['groups'] as $group) {
                    foreach ($group['questions'] as $question) {

                        $qid = $question['id'];

                        $value = $values[$qid] ?? '';

                        if (
                            $question['required'] &&
                            (
                                $value === '' ||
                                $value === [] ||
                                $value === null
                            )
                        ) {
                            throw new RuntimeException(
                                $question['number'] .
                                ' は必須項目です。'
                            );
                        }

                        if ($question['type'] === 'multiple') {
                            if (!is_array($value)) {
                                $value = [];
                            }

                            $allowed = array_column(
                                $question['options'],
                                'id'
                            );

                            $value = array_values(
                                array_intersect(
                                    $value,
                                    $allowed
                                )
                            );

                            $values[$qid] = $value;
                        } elseif (
                            $question['type'] === 'single'
                        ) {
                            $allowed = array_column(
                                $question['options'],
                                'id'
                            );

                            if (
                                $value !== '' &&
                                !in_array(
                                    $value,
                                    $allowed,
                                    true
                                )
                            ) {
                                $values[$qid] = '';
                            }
                        } else {
                            $values[$qid] =
                                mb_substr(
                                    (string)$value,
                                    0,
                                    5000
                                );
                        }
                    }
                }

                $_SESSION['answer_draft'] = [
                    'surveyId' => $surveyId,
                    'values' => $values,
                    'email' => postString('email'),
                    'customerName' => postString('customer_name'),
                ];

                redirect(
                    'index.php?screen=confirm&id=' .
                    rawurlencode($surveyId)
                );

            /* ------------------------------------------------
             * 回答確定
             * ------------------------------------------------ */

            case 'confirm_answer':

                $surveyId = postString('survey_id');

                $survey = surveyById($surveyId);

                if ($survey === null) {
                    throw new RuntimeException(
                        '対象アンケートが存在しません。'
                    );
                }

                $draft = $_SESSION['answer_draft'] ?? null;

                if (
                    !is_array($draft) ||
                    ($draft['surveyId'] ?? '') !== $surveyId
                ) {
                    throw new RuntimeException(
                        '回答データがありません。'
                    );
                }

                $answer = [
                    'id' => 'answer-' . uuid(),
                    'surveyId' => $surveyId,
                    'values' => $draft['values'] ?? [],
                    'email' => $draft['email'] ?? '',
                    'customerName' =>
                        $draft['customerName'] ?? '',
                    'createdAt' => nowIso(),
                ];

                if (!saveAnswer($answer)) {
                    throw new RuntimeException(
                        '回答を保存できませんでした。'
                    );
                }

                unset($_SESSION['answer_draft']);

                redirect(
                    'index.php?screen=complete&id=' .
                    rawurlencode($surveyId)
                );

            default:
                throw new RuntimeException(
                    '不明な処理です。'
                );
        }
    } catch (Throwable $e) {

        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => $e->getMessage(),
        ];

        $fallback = 'index.php?screen=list';

        if (
            in_array(
                $action,
                [
                    'save_kintone',
                    'test_kintone',
                    'sync_kintone',
                ],
                true
            )
        ) {
            $fallback = 'index.php?screen=kintone';
        }

        if (
            in_array(
                $action,
                [
                    'save_mail',
                    'test_mail',
                ],
                true
            )
        ) {
            $fallback = 'index.php?screen=mail';
        }

        if (
            in_array(
                $action,
                [
                    'send_bulk_mail',
                ],
                true
            )
        ) {
            $id = postString('survey_id');

            $fallback =
                'index.php?screen=send&id=' .
                rawurlencode($id);
        }

        if (
            in_array(
                $action,
                [
                    'submit_answer',
                    'confirm_answer',
                ],
                true
            )
        ) {
            $id = postString('survey_id');

            $fallback =
                'index.php?screen=answer&id=' .
                rawurlencode($id);
        }

        redirect($fallback);
    }
}

/* ============================================================
 * 画面ルーティング
 * ============================================================ */

$screen = postString('screen');

if ($screen === '') {
    $screen = (string)($_GET['screen'] ?? 'list');
}

$id = postString('id');

if ($id === '') {
    $id = (string)($_GET['id'] ?? '');
}

/* ------------------------------------------------------------
 * 自動終了
 * ------------------------------------------------------------ */

if ($id !== '') {
    $survey = surveyById($id);

    if ($survey !== null && updateAutomaticStatus($survey)) {
        $surveys = loadStore('surveys');
        $surveys[$id] = $survey;
        saveStore('surveys', $surveys);
    }
}

/* ============================================================
 * アンケート一覧
 * ============================================================ */

if ($screen === 'list') {

    $surveys = loadStore('surveys');

    foreach ($surveys as &$survey) {
        updateAutomaticStatus($survey);
    }

    unset($survey);

    saveStore('surveys', $surveys);

    $keyword = trim(
        (string)($_GET['q'] ?? '')
    );

    $statusFilter =
        (string)($_GET['status'] ?? 'all');

    $sort =
        (string)($_GET['sort'] ?? 'updated_desc');

    $items = array_values($surveys);

    $items = array_values(
        array_filter(
            $items,
            static function ($survey) use (
                $keyword,
                $statusFilter
            ) {
                if (
                    $keyword !== '' &&
                    !str_contains(
                        mb_strtolower(
                            (string)$survey['title']
                        ),
                        mb_strtolower($keyword)
                    )
                ) {
                    return false;
                }

                if (
                    $statusFilter !== 'all' &&
                    ($survey['status'] ?? '') !== $statusFilter
                ) {
                    return false;
                }

                return true;
            }
        )
    );

    usort(
        $items,
        static function ($a, $b) use ($sort) {
            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)$a['updatedAt'],
                        (string)$b['updatedAt']
                    ),
                'answers_desc' =>
                    answerCount($b['id']) <=>
                    answerCount($a['id']),
                'answers_asc' =>
                    answerCount($a['id']) <=>
                    answerCount($b['id']),
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

    pageStart('アンケート一覧', 'list');
    ?>

<div class="page-heading">
    <div>
        <h1>アンケート一覧</h1>
        <div class="notice">
            アンケート運用の起点です。
        </div>
    </div>

    <a
        class="btn btn-primary"
        href="index.php?screen=edit"
    >
        ＋ 新規作成
    </a>
</div>

<div class="card">
    <form method="get">
        <input type="hidden" name="screen" value="list">

        <div class="grid grid-3">

            <div class="form-group">
                <label>タイトル検索</label>
                <input
                    type="text"
                    name="q"
                    value="<?= h($keyword) ?>"
                    placeholder="タイトルを検索"
                    autofocus
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

                    <?php foreach ($statuses as $value => $label): ?>
                        <option
                            value="<?= h($value) ?>"
                            <?= $statusFilter === $value
                                ? 'selected'
                                : '' ?>
                        >
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>ソート</label>
                <select name="sort">
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
                        value="answers_desc"
                        <?= $sort === 'answers_desc'
                            ? 'selected'
                            : '' ?>
                    >
                        回答数：多い順
                    </option>
                    <option
                        value="answers_asc"
                        <?= $sort === 'answers_asc'
                            ? 'selected'
                            : '' ?>
                    >
                        回答数：少ない順
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
            </div>

        </div>

        <button class="btn btn-primary">
            検索
        </button>
    </form>
</div>

<div class="card">
    <div class="table-wrap">

        <?php if ($items === []): ?>

            <div class="empty">
                アンケートがありません。
            </div>

        <?php else: ?>

        <table>
            <thead>
            <tr>
                <th>タイトル</th>
                <th>期間</th>
                <th>ステータス</th>
                <th>回答数</th>
                <th>更新日</th>
                <th>操作</th>
            </tr>
            </thead>

            <tbody>

            <?php foreach ($items as $survey): ?>

                <?php
                $surveyId = $survey['id'];
                $status = $survey['status'];
                ?>

                <tr>

                    <td>
                        <strong>
                            <?= h(
                                $survey['title'] ?: '無題'
                            ) ?>
                        </strong>
                    </td>

                    <td>
                        <?= h($survey['startAt']) ?>
                        ～
                        <?= h($survey['endAt']) ?>
                    </td>

                    <td>
                        <span class="badge badge-<?= h(
                            surveyStatusClass($status)
                        ) ?>">
                            <?= h(
                                surveyStatusLabel($status)
                            ) ?>
                        </span>
                    </td>

                    <td>
                        <?= answerCount($surveyId) ?>
                    </td>

                    <td>
                        <?= h($survey['updatedAt']) ?>
                    </td>

                    <td>

                        <div class="actions">

                            <a
                                class="btn btn-secondary btn-sm"
                                href="index.php?screen=edit&id=<?= rawurlencode($surveyId) ?>"
                            >
                                編集
                            </a>

                            <a
                                class="btn btn-secondary btn-sm"
                                href="index.php?screen=preview&id=<?= rawurlencode($surveyId) ?>"
                            >
                                プレビュー
                            </a>

                            <a
                                class="btn btn-secondary btn-sm"
                                href="index.php?screen=analytics&id=<?= rawurlencode($surveyId) ?>"
                            >
                                集計
                            </a>

                            <a
                                class="btn btn-secondary btn-sm"
                                href="index.php?screen=send&id=<?= rawurlencode($surveyId) ?>"
                            >
                                送信
                            </a>

                            <form
                                method="post"
                                style="display:inline"
                                onsubmit="return confirmSubmit(this,'このアンケートを複製しますか？')"
                            >
                                <?= csrfField() ?>
                                <input
                                    type="hidden"
                                    name="action"
                                    value="duplicate_survey"
                                >
                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= h($surveyId) ?>"
                                >
                                <button
                                    class="btn btn-secondary btn-sm"
                                >
                                    複製
                                </button>
                            </form>

                            <form
                                method="post"
                                style="display:inline"
                                onsubmit="return confirmSubmit(this,'このアンケートを削除しますか？')"
                            >
                                <?= csrfField() ?>
                                <input
                                    type="hidden"
                                    name="action"
                                    value="delete_survey"
                                >
                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= h($surveyId) ?>"
                                >
                                <button
                                    class="btn btn-danger btn-sm"
                                >
                                    削除
                                </button>
                            </form>

                        </div>

                        <?php if ($status !== 'ended'): ?>

                        <div
                            class="actions"
                            style="margin-top:8px"
                        >

                            <?php if ($status === 'draft'): ?>

                                <form method="post">
                                    <?= csrfField() ?>
                                    <input
                                        type="hidden"
                                        name="action"
                                        value="change_status"
                                    >
                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= h($surveyId) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="new_status"
                                        value="published"
                                    >
                                    <button
                                        class="btn btn-success btn-sm"
                                        onclick="return confirm('公開しますか？')"
                                    >
                                        公開
                                    </button>
                                </form>

                            <?php elseif ($status === 'published'): ?>

                                <form method="post">
                                    <?= csrfField() ?>
                                    <input
                                        type="hidden"
                                        name="action"
                                        value="change_status"
                                    >
                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= h($surveyId) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="new_status"
                                        value="stopped"
                                    >
                                    <button
                                        class="btn btn-warning btn-sm"
                                        onclick="return confirm('停止しますか？')"
                                    >
                                        停止
                                    </button>
                                </form>

                            <?php elseif ($status === 'stopped'): ?>

                                <form method="post">
                                    <?= csrfField() ?>
                                    <input
                                        type="hidden"
                                        name="action"
                                        value="change_status"
                                    >
                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= h($surveyId) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="new_status"
                                        value="published"
                                    >
                                    <button
                                        class="btn btn-success btn-sm"
                                        onclick="return confirm('再開しますか？')"
                                    >
                                        再開
                                    </button>
                                </form>

                            <?php endif; ?>

                        </div>

                        <?php endif; ?>

                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>
        </table>

        <?php endif; ?>

    </div>
</div>

<?php
    pageEnd();
    exit;
}

/* ============================================================
 * 作成・編集
 * ============================================================ */

if ($screen === 'edit') {

    $survey = $id !== ''
        ? surveyById($id)
        : null;

    if ($survey === null) {
        $survey = defaultSurvey();
    }

    updateAutomaticStatus($survey);

    $status = $survey['status'];

    pageStart('アンケート作成・編集', 'edit');
    ?>

<div class="page-heading">
    <div>
        <h1>
            <?= $id !== ''
                ? 'アンケート編集'
                : 'アンケート新規作成' ?>
        </h1>
    </div>

    <div class="actions">
        <a
            class="btn btn-secondary"
            href="index.php?screen=list"
        >
            キャンセル
        </a>

        <?php if ($survey['id'] !== ''): ?>
            <a
                class="btn btn-secondary"
                href="index.php?screen=preview&id=<?= rawurlencode($survey['id']) ?>"
            >
                プレビュー
            </a>
        <?php endif; ?>
    </div>
</div>

<form method="post" id="surveyForm">

<?= csrfField() ?>

<input
    type="hidden"
    name="action"
    value="save_survey"
>

<input
    type="hidden"
    name="id"
    value="<?= h($survey['id']) ?>"
>

<div class="card">

    <div class="grid grid-2">

        <div class="form-group">
            <label>アンケートタイトル</label>

            <input
                type="text"
                name="title"
                maxlength="200"
                required
                value="<?= h($survey['title']) ?>"
            >
        </div>

        <div class="form-group">
            <label>ステータス</label>

            <input
                type="text"
                value="<?= h(
                    surveyStatusLabel($status)
                ) ?>"
                disabled
            >
        </div>

    </div>

    <div class="form-group">
        <label>アンケート説明</label>

        <textarea
            name="description"
            maxlength="5000"
        ><?= h($survey['description']) ?></textarea>
    </div>

    <div class="grid grid-2">

        <div class="form-group">
            <label>開始日時</label>

            <input
                type="datetime-local"
                name="startAt"
                value="<?= h(
                    $survey['startAt']
                ) ?>"
            >
        </div>

        <div class="form-group">
            <label>終了日時</label>

            <input
                type="datetime-local"
                name="endAt"
                value="<?= h(
                    $survey['endAt']
                ) ?>"
            >
        </div>

    </div>

    <div class="form-group">
        <label>質問番号の採番方式</label>

        <select name="numbering">
            <option
                value="global"
                <?= $survey['numbering'] === 'global'
                    ? 'selected'
                    : '' ?>
            >
                アンケート全体で通番（Q1、Q2、Q3...）
            </option>

            <option
                value="group"
                <?= $survey['numbering'] === 'group'
                    ? 'selected'
                    : '' ?>
            >
                グループ毎（Q1-1、Q1-2、Q2-1...）
            </option>
        </select>
    </div>

</div>

<div class="card">

    <h2 class="section-title">
        質問・グループ
    </h2>

    <div id="groups">

    <?php foreach ($survey['groups'] as $gi => $group): ?>

        <div
            class="group-card"
            draggable="true"
            data-group-index="<?= $gi ?>"
        >

            <div class="group-header">

                <span class="drag-handle">
                    ☰
                </span>

                <input
                    type="hidden"
                    name="groups[<?= $gi ?>][id]"
                    value="<?= h($group['id']) ?>"
                >

                <input
                    type="text"
                    name="groups[<?= $gi ?>][title]"
                    value="<?= h($group['title']) ?>"
                    placeholder="グループタイトル"
                    style="flex:1"
                >

                <button
                    type="button"
                    class="btn btn-danger btn-sm"
                    onclick="if(confirm('グループを削除しますか？')) this.closest('.group-card').remove();"
                >
                    グループ削除
                </button>

            </div>

            <div class="group-body">

                <div class="questions">

                <?php foreach (
                    $group['questions']
                    as $qi => $question
                ): ?>

                    <div
                        class="question-card"
                        draggable="true"
                    >

                        <input
                            type="hidden"
                            name="groups[<?= $gi ?>][questions][<?= $qi ?>][id]"
                            value="<?= h($question['id']) ?>"
                        >

                        <div class="question-grid">

                            <div>
                                <label>番号</label>

                                <input
                                    type="text"
                                    value="<?= h(
                                        $question['number']
                                    ) ?>"
                                    disabled
                                >
                            </div>

                            <div>
                                <label>質問文</label>

                                <textarea
                                    name="groups[<?= $gi ?>][questions][<?= $qi ?>][text]"
                                    maxlength="2000"
                                ><?= h(
                                    $question['text']
                                ) ?></textarea>
                            </div>

                            <div>
                                <label>回答形式</label>

                                <select
                                    name="groups[<?= $gi ?>][questions][<?= $qi ?>][type]"
                                    onchange="toggleOptions(this)"
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
                                <label>設定</label>

                                <label
                                    style="font-weight:400"
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

                                <button
                                    type="button"
                                    class="btn btn-danger btn-sm"
                                    onclick="if(confirm('質問を削除しますか？')) this.closest('.question-card').remove();"
                                >
                                    削除
                                </button>
                            </div>

                        </div>

                        <div
                            class="options-editor"
                            style="<?= in_array(
                                $question['type'],
                                ['single','multiple'],
                                true
                            )
                                ? ''
                                : 'display:none;' ?>"
                        >

                            <label>
                                選択肢
                            </label>

                            <?php foreach (
                                $question['options']
                                as $oi => $option
                            ): ?>

                                <div class="option-row">

                                    <input
                                        type="hidden"
                                        name="groups[<?= $gi ?>][questions][<?= $qi ?>][options][<?= $oi ?>][id]"
                                        value="<?= h(
                                            $option['id']
                                        ) ?>"
                                    >

                                    <input
                                        type="text"
                                        name="groups[<?= $gi ?>][questions][<?= $qi ?>][options][<?= $oi ?>][text]"
                                        value="<?= h(
                                            $option['text']
                                        ) ?>"
                                    >

                                    <button
                                        type="button"
                                        class="btn btn-danger btn-sm"
                                        onclick="this.parentElement.remove()"
                                    >
                                        削除
                                    </button>

                                </div>

                            <?php endforeach; ?>

                            <button
                                type="button"
                                class="btn btn-secondary btn-sm"
                                onclick="addExistingOption(this)"
                            >
                                ＋ 選択肢追加
                            </button>

                        </div>

                    </div>

                <?php endforeach; ?>

                </div>

                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="addQuestion(this)"
                >
                    ＋ 質問を追加
                </button>

            </div>

        </div>

    <?php endforeach; ?>

    </div>

    <button
        type="button"
        class="btn btn-secondary"
        onclick="addGroup()"
    >
        ＋ グループを追加
    </button>

</div>

<div class="card">

    <div class="actions">

        <a
            class="btn btn-secondary"
            href="index.php?screen=list"
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

<script>
function toggleOptions(select){
    const card = select.closest('.question-card');
    const editor = card.querySelector('.options-editor');

    if(select.value === 'single' || select.value === 'multiple'){
        editor.style.display = '';
    }else{
        editor.style.display = 'none';
    }
}

function addExistingOption(button){
    const editor = button.closest('.options-editor');
    const question = button.closest('.question-card');

    const input = question.querySelector(
        'textarea[name*="[text]"]'
    );

    const nameMatches = button
        .closest('.options-editor')
        .querySelectorAll(
            'input[name*="[options]"][name$="[text]"]'
        );

    const first = nameMatches[0];

    if(!first){
        return;
    }

    const match = first.name.match(
        /groups\[(\d+)\]\[questions\]\[(\d+)\]/
    );

    if(!match){
        return;
    }

    const gi = match[1];
    const qi = match[2];

    const index =
        editor.querySelectorAll(
            'input[name*="[options]"][name$="[text]"]'
        ).length;

    const row = document.createElement('div');

    row.className = 'option-row';

    row.innerHTML =
        '<input type="hidden" name="groups[' +
        gi +
        '][questions][' +
        qi +
        '][options][' +
        index +
        '][id]" value="option-' +
        crypto.randomUUID() +
        '">' +

        '<input type="text" name="groups[' +
        gi +
        '][questions][' +
        qi +
        '][options][' +
        index +
        '][text]" value="">' +

        '<button type="button" class="btn btn-danger btn-sm" ' +
        'onclick="this.parentElement.remove()">削除</button>';

    editor.insertBefore(row, button);
}

function addQuestion(button){

    const group = button.closest('.group-card');
    const questions = group.querySelector('.questions');

    const groupIndex =
        [...document.querySelectorAll('.group-card')]
        .indexOf(group);

    const qi =
        questions.querySelectorAll('.question-card').length;

    const id = crypto.randomUUID();

    const div = document.createElement('div');

    div.className = 'question-card';
    div.draggable = true;

    div.innerHTML = `
        <input
            type="hidden"
            name="groups[${groupIndex}][questions][${qi}][id]"
            value="question-${id}"
        >

        <div class="question-grid">

            <div>
                <label>番号</label>
                <input type="text" value="自動" disabled>
            </div>

            <div>
                <label>質問文</label>
                <textarea
                    name="groups[${groupIndex}][questions][${qi}][text]"
                    maxlength="2000"
                ></textarea>
            </div>

            <div>
                <label>回答形式</label>
                <select
                    name="groups[${groupIndex}][questions][${qi}][type]"
                    onchange="toggleOptions(this)"
                >
                    <option value="single">単一選択</option>
                    <option value="multiple">複数選択</option>
                    <option value="text">自由記述</option>
                </select>
            </div>

            <div>
                <label>設定</label>

                <label style="font-weight:400">
                    <input
                        type="checkbox"
                        name="groups[${groupIndex}][questions][${qi}][required]"
                        value="1"
                    >
                    必須
                </label>

                <button
                    type="button"
                    class="btn btn-danger btn-sm"
                    onclick="if(confirm('質問を削除しますか？')) this.closest('.question-card').remove();"
                >
                    削除
                </button>
            </div>

        </div>

        <div class="options-editor">

            <label>選択肢</label>

            <div class="option-row">
                <input
                    type="hidden"
                    name="groups[${groupIndex}][questions][${qi}][options][0][id]"
                    value="option-${crypto.randomUUID()}"
                >

                <input
                    type="text"
                    name="groups[${groupIndex}][questions][${qi}][options][0][text]"
                    value="選択肢1"
                >

                <button
                    type="button"
                    class="btn btn-danger btn-sm"
                    onclick="this.parentElement.remove()"
                >
                    削除
                </button>
            </div>

            <button
                type="button"
                class="btn btn-secondary btn-sm"
                onclick="addExistingOption(this)"
            >
                ＋ 選択肢追加
            </button>

        </div>
    `;

    questions.appendChild(div);
}

function addGroup(){

    const groups =
        document.getElementById('groups');

    const gi =
        groups.querySelectorAll('.group-card').length;

    const id = crypto.randomUUID();

    const div = document.createElement('div');

    div.className = 'group-card';

    div.innerHTML = `
        <div class="group-header">

            <span class="drag-handle">☰</span>

            <input
                type="hidden"
                name="groups[${gi}][id]"
                value="group-${id}"
            >

            <input
                type="text"
                name="groups[${gi}][title]"
                value="グループ${gi + 1}"
                style="flex:1"
            >

            <button
                type="button"
                class="btn btn-danger btn-sm"
                onclick="if(confirm('グループを削除しますか？')) this.closest('.group-card').remove();"
            >
                グループ削除
            </button>

        </div>

        <div class="group-body">

            <div class="questions"></div>

            <button
                type="button"
                class="btn btn-secondary"
                onclick="addQuestion(this)"
            >
                ＋ 質問を追加
            </button>

        </div>
    `;

    groups.appendChild(div);

    div.querySelector(
        'button.btn-secondary'
    ).click();
}
</script>

<?php
    pageEnd();
    exit;
}

/* ============================================================
 * プレビュー
 * ============================================================ */

if ($screen === 'preview') {

    $survey = surveyById($id);

    if ($survey === null) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => '対象アンケートが存在しません。',
        ];

        redirect('index.php?screen=list');
    }

    pageStart('プレビュー', 'preview');
    ?>

<div class="page-heading">
    <div>
        <h1>プレビュー</h1>
        <div class="notice">
            実際のメール送信は行いません。
        </div>
    </div>

    <div class="actions">
        <a
            class="btn btn-secondary"
            href="index.php?screen=edit&id=<?= rawurlencode($id) ?>"
        >
            編集へ戻る
        </a>

        <a
            class="btn btn-primary"
            href="index.php?screen=answer&id=<?= rawurlencode($id) ?>"
        >
            回答画面を見る
        </a>
    </div>
</div>

<div class="card">

    <h1><?= h($survey['title']) ?></h1>

    <p>
        <?= nl2br(h($survey['description'])) ?>
    </p>

    <?php foreach ($survey['groups'] as $group): ?>

        <div class="card">

            <h2 class="section-title">
                <?= h($group['title']) ?>
            </h2>

            <?php foreach ($group['questions'] as $question): ?>

                <div class="preview-question">

                    <div class="preview-number">
                        <?= h($question['number']) ?>
                    </div>

                    <h3>
                        <?= h($question['text']) ?>

                        <?php if ($question['required']): ?>
                            <span class="required">
                                *
                            </span>
                        <?php endif; ?>
                    </h3>

                    <?php if (
                        $question['type'] === 'text'
                    ): ?>

                        <textarea
                            disabled
                            placeholder="自由記述"
                        ></textarea>

                    <?php else: ?>

                        <?php foreach (
                            $question['options']
                            as $option
                        ): ?>

                            <label class="choice">

                                <input
                                    type="<?= $question['type'] === 'single'
                                        ? 'radio'
                                        : 'checkbox' ?>"
                                    disabled
                                >

                                <?= h($option['text']) ?>

                            </label>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endforeach; ?>

</div>

<?php
    pageEnd();
    exit;
}

/* ============================================================
 * 回答画面
 * ============================================================ */

if ($screen === 'answer') {

    $survey = surveyById($id);

    if ($survey === null) {
        http_response_code(404);
        exit('アンケートが存在しません。');
    }

    if (updateAutomaticStatus($survey)) {
        $surveys = loadStore('surveys');
        $surveys[$id] = $survey;
        saveStore('surveys', $surveys);
    }

    pageStart(
        'アンケート回答',
        'answer'
    );

    if ($survey['status'] !== 'published'):

        ?>

        <div class="card">
            <h1>アンケートを回答できません</h1>

            <p>
                このアンケートは現在公開されていません。
            </p>
        </div>

        <?php

        pageEnd();
        exit;

    endif;
    ?>

<div class="card">

    <h1><?= h($survey['title']) ?></h1>

    <p>
        <?= nl2br(h($survey['description'])) ?>
    </p>

</div>

<form method="post">

<?= csrfField() ?>

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

<div class="card">

    <div class="form-group">
        <label>
            氏名
        </label>

        <input
            type="text"
            name="customer_name"
            maxlength="200"
        >
    </div>

    <div class="form-group">
        <label>
            メールアドレス
        </label>

        <input
            type="email"
            name="email"
            maxlength="320"
        >
    </div>

</div>

<?php foreach ($survey['groups'] as $group): ?>

<div class="card">

    <h2 class="section-title">
        <?= h($group['title']) ?>
    </h2>

    <?php foreach ($group['questions'] as $question): ?>

        <div class="preview-question">

            <div class="preview-number">
                <?= h($question['number']) ?>
            </div>

            <h3>
                <?= h($question['text']) ?>

                <?php if ($question['required']): ?>
                    <span class="required">
                        *
                    </span>
                <?php endif; ?>
            </h3>

            <?php if (
                $question['type'] === 'text'
            ): ?>

                <textarea
                    name="answer[<?= h($question['id']) ?>]"
                    maxlength="5000"
                    <?= $question['required']
                        ? 'required'
                        : '' ?>
                ></textarea>

            <?php elseif (
                $question['type'] === 'single'
            ): ?>

                <?php foreach (
                    $question['options']
                    as $option
                ): ?>

                    <label class="choice">

                        <input
                            type="radio"
                            name="answer[<?= h($question['id']) ?>]"
                            value="<?= h($option['id']) ?>"
                            <?= $question['required']
                                ? 'required'
                                : '' ?>
                        >

                        <?= h($option['text']) ?>

                    </label>

                <?php endforeach; ?>

            <?php else: ?>

                <?php foreach (
                    $question['options']
                    as $option
                ): ?>

                    <label class="choice">

                        <input
                            type="checkbox"
                            name="answer[<?= h($question['id']) ?>][]"
                            value="<?= h($option['id']) ?>"
                        >

                        <?= h($option['text']) ?>

                    </label>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    <?php endforeach; ?>

</div>

<?php endforeach; ?>

<div class="card">

    <button class="btn btn-primary">
        回答を確認する
    </button>

</div>

</form>

<?php
    pageEnd();
    exit;
}

/* ============================================================
 * 回答確認
 * ============================================================ */

if ($screen === 'confirm') {

    $survey = surveyById($id);
    $draft = $_SESSION['answer_draft'] ?? null;

    if (
        $survey === null ||
        !is_array($draft) ||
        ($draft['surveyId'] ?? '') !== $id
    ) {
        redirect(
            'index.php?screen=answer&id=' .
            rawurlencode($id)
        );
    }

    pageStart(
        '回答確認',
        'confirm'
    );
    ?>

<div class="page-heading">
    <div>
        <h1>回答確認</h1>
    </div>
</div>

<div class="card">

    <h1><?= h($survey['title']) ?></h1>

    <div class="form-group">
        <strong>氏名</strong><br>
        <?= h($draft['customerName'] ?? '') ?>
    </div>

    <div class="form-group">
        <strong>メールアドレス</strong><br>
        <?= h($draft['email'] ?? '') ?>
    </div>

</div>

<?php
$values = $draft['values'] ?? [];

foreach ($survey['groups'] as $group):
?>

<div class="card">

    <h2 class="section-title">
        <?= h($group['title']) ?>
    </h2>

    <?php foreach ($group['questions'] as $question): ?>

        <?php
        $value = $values[$question['id']] ?? '';

        if (is_array($value)) {
            $labels = [];

            foreach ($question['options'] as $option) {
                if (in_array(
                    $option['id'],
                    $value,
                    true
                )) {
                    $labels[] = $option['text'];
                }
            }

            $displayValue =
                implode(', ', $labels);
        } elseif (
            $question['type'] === 'single'
        ) {
            $displayValue = '';

            foreach ($question['options'] as $option) {
                if (
                    $option['id'] === $value
                ) {
                    $displayValue =
                        $option['text'];
                    break;
                }
            }
        } else {
            $displayValue = (string)$value;
        }
        ?>

        <div class="form-group">

            <div class="preview-number">
                <?= h($question['number']) ?>
            </div>

            <strong>
                <?= h($question['text']) ?>
            </strong>

            <div style="margin-top:6px">
                <?= nl2br(h($displayValue)) ?>
            </div>

        </div>

    <?php endforeach; ?>

</div>

<?php endforeach; ?>

<div class="card">

    <div class="actions">

        <a
            class="btn btn-secondary"
            href="index.php?screen=answer&id=<?= rawurlencode($id) ?>"
        >
            修正する
        </a>

        <form method="post">
            <?= csrfField() ?>

            <input
                type="hidden"
                name="action"
                value="confirm_answer"
            >

            <input
                type="hidden"
                name="survey_id"
                value="<?= h($id) ?>"
            >

            <button class="btn btn-primary">
                回答を送信する
            </button>
        </form>

    </div>

</div>

<?php
    pageEnd();
    exit;
}

/* ============================================================
 * 回答完了
 * ============================================================ */

if ($screen === 'complete') {

    pageStart(
        '回答完了',
        'complete'
    );
    ?>

<div class="card" style="text-align:center">

    <h1>回答ありがとうございました</h1>

    <p>
        回答を正常に受け付けました。
    </p>

</div>

<?php
    pageEnd();
    exit;
}

/* ============================================================
 * kintone設定
 * ============================================================ */

if ($screen === 'kintone') {

    $settings = loadSettings()['kintone'];

    pageStart(
        'kintone連携設定',
        'kintone'
    );
    ?>

<div class="page-heading">
    <div>
        <h1>kintone連携設定</h1>
        <div class="notice">
            接続テストと顧客同期は独立して実行します。
        </div>
    </div>
</div>

<form method="post">

<?= csrfField() ?>

<input
    type="hidden"
    name="action"
    value="save_kintone"
>

<div class="card">

    <h2 class="section-title">
        接続設定
    </h2>

    <div class="grid grid-2">

        <div class="form-group">
            <label>サブドメイン</label>

            <input
                type="text"
                name="subdomain"
                value="<?= h(
                    $settings['subdomain']
                ) ?>"
                placeholder="example"
                required
            >

            <div class="notice">
                https://example.cybozu.com の example 部分
            </div>
        </div>

        <div class="form-group">
            <label>顧客管理アプリID</label>

            <input
                type="number"
                name="app_id"
                min="1"
                value="<?= h(
                    $settings['app_id']
                ) ?>"
                required
            >
        </div>

        <div class="form-group">
            <label>ログイン名</label>

            <input
                type="text"
                name="login_name"
                value="<?= h(
                    $settings['login_name']
                ) ?>"
                required
            >
        </div>

        <div class="form-group">
            <label>パスワード</label>

            <input
                type="password"
                name="password"
                value=""
                autocomplete="new-password"
                placeholder="変更する場合のみ入力"
            >

            <div class="notice">
                空欄の場合は保存済みパスワードを維持します。
            </div>
        </div>

        <div class="form-group">

            <label>
                Proxyサーバ
            </label>

            <input
                type="text"
                name="proxy"
                value="<?= h(
                    $settings['proxy']
                ) ?>"
                placeholder="proxy.example.local:8080"
            >

            <div class="notice">
                host:port形式。未入力の場合は直接接続します。
            </div>

        </div>

        <div class="form-group">

            <label>
                SSL証明書検証
            </label>

            <label style="font-weight:400">

                <input
                    type="checkbox"
                    name="verify_ssl"
                    value="1"
                    <?= !empty(
                        $settings['verify_ssl']
                    )
                        ? 'checked'
                        : '' ?>
                >

                SSL証明書を検証する

            </label>

        </div>

    </div>

</div>

<div class="card">

    <h2 class="section-title">
        顧客情報フィールド
    </h2>

    <div class="notice" style="margin-bottom:15px">
        kintoneの実際のフィールドコードを指定してください。
    </div>

    <div class="grid grid-2">

        <?php
        $fieldSettings = [
            'field_org' =>
                '組織名フィールドコード',
            'field_name' =>
                '氏名フィールドコード',
            'field_email' =>
                'メールアドレスフィールドコード',
            'field_department' =>
                '部署名フィールドコード',
            'field_phone' =>
                '電話番号フィールドコード',
            'field_address' =>
                '住所フィールドコード',
        ];
        ?>

        <?php foreach (
            $fieldSettings as $field => $label
        ): ?>

            <div class="form-group">

                <label>
                    <?= h($label) ?>
                </label>

                <input
                    type="text"
                    name="<?= h($field) ?>"
                    value="<?= h(
                        $settings[$field] ?? ''
                    ) ?>"
                >

            </div>

        <?php endforeach; ?>

    </div>

</div>

<div class="card">

    <div class="actions">

        <button
            class="btn btn-primary"
        >
            設定保存
        </button>

    </div>

</div>

</form>

<div class="card">

    <h2 class="section-title">
        kintone接続
    </h2>

    <div class="actions">

        <form method="post">
            <?= csrfField() ?>

            <input
                type="hidden"
                name="action"
                value="test_kintone"
            >

            <button
                class="btn btn-secondary"
                onclick="return confirm('実際のkintoneへ接続テストを実行しますか？')"
            >
                接続テスト
            </button>
        </form>

        <form method="post">
            <?= csrfField() ?>

            <input
                type="hidden"
                name="action"
                value="sync_kintone"
            >

            <button
                class="btn btn-primary"
                onclick="return confirm('kintoneから顧客情報を同期しますか？')"
            >
                顧客情報を同期
            </button>
        </form>

    </div>

</div>

<?php
    pageEnd();
    exit;
}

/* ============================================================
 * メール設定
 * ============================================================ */

if ($screen === 'mail') {

    $settings = loadSettings()['mail'];

    pageStart(
        'メールサーバ設定',
        'mail'
    );
    ?>

<div class="page-heading">
    <div>
        <h1>メールサーバ設定</h1>
        <div class="notice">
            PHP mail()は使用せずSMTPへ直接接続します。
        </div>
    </div>
</div>

<form method="post">

<?= csrfField() ?>

<input
    type="hidden"
    name="action"
    value="save_mail"
>

<div class="card">

    <div class="grid grid-2">

        <div class="form-group">

            <label>SMTPサーバ</label>

            <input
                type="text"
                name="server"
                value="<?= h(
                    $settings['server']
                ) ?>"
                required
            >

        </div>

        <div class="form-group">

            <label>SMTPポート</label>

            <input
                type="number"
                name="port"
                value="<?= h(
                    $settings['port']
                ) ?>"
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
                    <?= $settings['encryption'] === 'ssl'
                        ? 'selected'
                        : '' ?>
                >
                    SSL
                </option>

                <option
                    value="tls"
                    <?= $settings['encryption'] === 'tls'
                        ? 'selected'
                        : '' ?>
                >
                    TLS / STARTTLS
                </option>

                <option
                    value="none"
                    <?= $settings['encryption'] === 'none'
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
                    type="checkbox"
                    name="auth"
                    value="1"
                    <?= !empty($settings['auth'])
                        ? 'checked'
                        : '' ?>
                >

                認証を使用する

            </label>

        </div>

        <div class="form-group">

            <label>SMTPユーザー名</label>

            <input
                type="text"
                name="username"
                value="<?= h(
                    $settings['username']
                ) ?>"
            >

        </div>

        <div class="form-group">

            <label>SMTPパスワード</label>

            <input
                type="password"
                name="password"
                value=""
                autocomplete="new-password"
                placeholder="変更する場合のみ入力"
            >

        </div>

        <div class="form-group">

            <label>送信元メールアドレス</label>

            <input
                type="email"
                name="from_email"
                value="<?= h(
                    $settings['from_email']
                ) ?>"
                required
            >

        </div>

        <div class="form-group">

            <label>送信元名</label>

            <input
                type="text"
                name="from_name"
                value="<?= h(
                    $settings['from_name']
                ) ?>"
            >

        </div>

        <div class="form-group">

            <label>返信先メールアドレス</label>

            <input
                type="email"
                name="reply_to"
                value="<?= h(
                    $settings['reply_to']
                ) ?>"
            >

        </div>

    </div>

</div>

<div class="card">

    <button class="btn btn-primary">
        設定保存
    </button>

</div>

</form>

<div class="card">

    <h2 class="section-title">
        テストメール
    </h2>

    <form method="post">

        <?= csrfField() ?>

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
                name="test_to"
                required
            >

        </div>

        <button
            class="btn btn-secondary"
            onclick="return confirm('実際にメールを送信しますか？')"
        >
            テストメール送信
        </button>

    </form>

</div>

<?php
    pageEnd();
    exit;
}

/* ============================================================
 * 送信画面
 * ============================================================ */

if ($screen === 'send') {

    $survey = surveyById($id);

    if ($survey === null) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => '対象アンケートが存在しません。',
        ];

        redirect('index.php?screen=list');
    }

    $customers = loadStore('customers');

    $mailLogs = loadStore('mail_log');

    $customerKeyword =
        trim((string)($_GET['q'] ?? ''));

    $customerItems =
        array_values($customers);

    if ($customerKeyword !== '') {
        $customerItems =
            array_values(
                array_filter(
                    $customerItems,
                    static function ($customer) use (
                        $customerKeyword
                    ) {
                        $haystack =
                            ($customer['name'] ?? '') .
                            ' ' .
                            ($customer['email'] ?? '') .
                            ' ' .
                            ($customer['organization'] ?? '') .
                            ' ' .
                            ($customer['department'] ?? '');

                        return str_contains(
                            mb_strtolower($haystack),
                            mb_strtolower($customerKeyword)
                        );
                    }
                )
            );
    }

    $logs = array_values(
        array_filter(
            $mailLogs,
            static fn($log) =>
                ($log['surveyId'] ?? '') === $id
        )
    );

    usort(
        $logs,
        static fn($a, $b) =>
            strcmp(
                (string)($b['createdAt'] ?? ''),
                (string)($a['createdAt'] ?? '')
            )
    );

    pageStart(
        '顧客選択・メール送信',
        'send'
    );
    ?>

<div class="page-heading">

    <div>

        <h1>
            顧客選択・メール送信
        </h1>

        <div class="notice">
            対象：
            <strong>
                <?= h($survey['title']) ?>
            </strong>
        </div>

    </div>

    <a
        class="btn btn-secondary"
        href="index.php?screen=list"
    >
        一覧へ戻る
    </a>

</div>

<div class="card">

    <h2 class="section-title">
        顧客選択
    </h2>

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

        <div class="actions">

            <input
                type="text"
                name="q"
                value="<?= h(
                    $customerKeyword
                ) ?>"
                placeholder="氏名・メール・組織・部署"
                style="flex:1"
            >

            <button class="btn btn-secondary">
                検索
            </button>

        </div>

    </form>

    <form
        method="post"
        style="margin-top:20px"
        onsubmit="return confirmSubmit(this,'選択した顧客へ実際にメールを一括送信しますか？')"
    >

        <?= csrfField() ?>

        <input
            type="hidden"
            name="action"
            value="send_bulk_mail"
        >

        <input
            type="hidden"
            name="survey_id"
            value="<?= h($id) ?>"
        >

        <div class="table-wrap">

            <table>

                <thead>

                <tr>

                    <th>
                        <input
                            type="checkbox"
                            onclick="document.querySelectorAll('.customer-check').forEach(x => x.checked = this.checked)"
                        >
                    </th>

                    <th>氏名</th>
                    <th>メールアドレス</th>
                    <th>組織名</th>
                    <th>部署名</th>

                </tr>

                </thead>

                <tbody>

                <?php foreach (
                    $customerItems as $customer
                ): ?>

                    <tr>

                        <td>

                            <input
                                class="customer-check"
                                type="checkbox"
                                name="customer_ids[]"
                                value="<?= h(
                                    $customer['id']
                                ) ?>"
                            >

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
                                $customer['organization'] ?? ''
                            ) ?>
                        </td>

                        <td>
                            <?= h(
                                $customer['department'] ?? ''
                            ) ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

        <?php if ($customerItems === []): ?>

            <div class="empty">
                顧客情報がありません。
                kintoneから顧客情報を同期してください。
            </div>

        <?php endif; ?>

        <div class="grid grid-2" style="margin-top:20px">

            <div class="form-group">

                <label>
                    メール件名
                </label>

                <input
                    type="text"
                    name="subject"
                    value="アンケートご回答のお願い"
                    maxlength="200"
                    required
                >

            </div>

            <div class="form-group">

                <label>
                    本文
                </label>

                <textarea
                    name="body"
                    required
                >{顧客名} 様

アンケートへのご回答をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>

                <div class="notice">
                    使用可能な変数：
                    {顧客名} / {アンケートURL}
                </div>

            </div>

        </div>

        <button class="btn btn-primary">
            一括送信
        </button>

    </form>

</div>

<div class="card">

    <h2 class="section-title">
        送信履歴
    </h2>

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
                    <th>メールアドレス</th>
                    <th>結果</th>
                    <th>メッセージ</th>
                </tr>

                </thead>

                <tbody>

                <?php foreach ($logs as $log): ?>

                    <tr>

                        <td>
                            <?= h(
                                $log['createdAt'] ?? ''
                            ) ?>
                        </td>

                        <td>
                            <?= h(
                                $log['email'] ?? ''
                            ) ?>
                        </td>

                        <td>

                            <?php if (
                                ($log['status'] ?? '') === 'sent'
                            ): ?>

                                <span
                                    class="badge badge-success"
                                >
                                    送信成功
                                </span>

                            <?php else: ?>

                                <span
                                    class="badge badge-warning"
                                >
                                    送信失敗
                                </span>

                            <?php endif; ?>

                        </td>

                        <td>
                            <?= h(
                                $log['message'] ?? ''
                            ) ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

</div>

<?php
    pageEnd();
    exit;
}

/* ============================================================
 * 集計
 * ============================================================ */

if ($screen === 'analytics') {

    $survey = surveyById($id);

    if ($survey === null) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => '対象アンケートが存在しません。',
        ];

        redirect('index.php?screen=list');
    }

    $answers = surveyAnswers($id);

    pageStart(
        '回答集計・分析',
        'analytics'
    );
    ?>

<div class="page-heading">

    <div>

        <h1>
            回答集計・分析
        </h1>

        <div class="notice">
            対象：
            <strong>
                <?= h($survey['title']) ?>
            </strong>
        </div>

    </div>

    <div class="actions">

        <a
            class="btn btn-secondary"
            href="index.php?screen=list"
        >
            一覧へ戻る
        </a>

        <a
            class="btn btn-secondary"
            href="index.php?screen=analytics&id=<?= rawurlencode($id) ?>&export=csv"
        >
            CSV
        </a>

        <a
            class="btn btn-secondary"
            href="index.php?screen=analytics&id=<?= rawurlencode($id) ?>&export=pdf"
        >
            PDF
        </a>

    </div>

</div>

<?php

$export =
    (string)($_GET['export'] ?? '');

if ($export === 'csv') {
    outputCsv($survey);
}

if ($export === 'pdf') {
    outputSimplePdf($survey);
}

?>

<div class="stat-grid">

    <div class="stat">
        <div class="stat-label">
            送信対象者数
        </div>
        <div class="stat-value">
            <?= count(loadStore('customers')) ?>
        </div>
    </div>

    <div class="stat">
        <div class="stat-label">
            回答数
        </div>
        <div class="stat-value">
            <?= count($answers) ?>
        </div>
    </div>

    <div class="stat">
        <div class="stat-label">
            未登録回答数
        </div>
        <div class="stat-value">
            <?= count(array_filter(
                $answers,
                static fn($a) =>
                    trim((string)($a['email'] ?? '')) === ''
            )) ?>
        </div>
    </div>

    <div class="stat">
        <div class="stat-label">
            回答率
        </div>
        <div class="stat-value">
            <?php
            $customerCount =
                count(loadStore('customers'));

            echo $customerCount > 0
                ? h(
                    number_format(
                        count($answers) /
                        $customerCount *
                        100,
                        1
                    )
                ) . '%'
                : '0%';
            ?>
        </div>
    </div>

</div>

<?php if ($answers === []): ?>

<div class="card">

    <div class="empty">
        現在、回答データはありません
    </div>

</div>

<?php else: ?>

<?php foreach ($survey['groups'] as $group): ?>

<div class="card">

    <h2 class="section-title">
        <?= h($group['title']) ?>
    </h2>

    <?php foreach ($group['questions'] as $question): ?>

        <div class="card">

            <div class="preview-number">
                <?= h($question['number']) ?>
            </div>

            <h3>
                <?= h($question['text']) ?>
            </h3>

            <?php if (
                $question['type'] === 'text'
            ): ?>

                <?php
                $textAnswers = [];

                foreach ($answers as $answer) {
                    $value =
                        $answer['values'][$question['id']]
                        ?? '';

                    if ($value !== '') {
                        $textAnswers[] =
                            (string)$value;
                    }
                }
                ?>

                <?php if ($textAnswers === []): ?>

                    <div class="notice">
                        回答なし
                    </div>

                <?php else: ?>

                    <?php foreach (
                        $textAnswers as $text
                    ): ?>

                        <div
                            class="card"
                            style="box-shadow:none"
                        >
                            <?= nl2br(h($text)) ?>
                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            <?php else: ?>

                <?php
                $counts = [];

                foreach ($question['options'] as $option) {
                    $counts[$option['id']] = 0;
                }

                foreach ($answers as $answer) {

                    $value =
                        $answer['values'][$question['id']]
                        ?? '';

                    if (is_array($value)) {

                        foreach ($value as $selected) {

                            if (
                                isset($counts[$selected])
                            ) {
                                $counts[$selected]++;
                            }
                        }

                    } elseif (
                        isset($counts[$value])
                    ) {
                        $counts[$value]++;
                    }
                }
                ?>

                <?php foreach (
                    $question['options']
                    as $option
                ): ?>

                    <div
                        style="
                            display:grid;
                            grid-template-columns:1fr 100px;
                            gap:10px;
                            margin:8px 0;
                        "
                    >

                        <div>
                            <?= h(
                                $option['text']
                            ) ?>
                        </div>

                        <strong>
                            <?= $counts[
                                $option['id']
                            ] ?? 0 ?>
                        </strong>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    <?php endforeach; ?>

</div>

<?php endforeach; ?>

<div class="card">

    <h2 class="section-title">
        個別回答
    </h2>

    <div class="table-wrap">

        <table>

            <thead>

            <tr>
                <th>回答日時</th>
                <th>氏名</th>
                <th>メールアドレス</th>
            </tr>

            </thead>

            <tbody>

            <?php foreach ($answers as $answer): ?>

                <tr>

                    <td>
                        <?= h(
                            $answer['createdAt']
                        ) ?>
                    </td>

                    <td>
                        <?= h(
                            $answer['customerName']
                        ) ?>
                    </td>

                    <td>
                        <?= h(
                            $answer['email']
                        ) ?>
                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

<?php endif; ?>

<?php
    pageEnd();
    exit;
}

/* ============================================================
 * 不明な画面
 * ============================================================ */

http_response_code(404);

pageStart(
    'ページが見つかりません',
    ''
);
?>

<div class="card">

    <h1>404</h1>

    <p>
        指定された画面は存在しません。
    </p>

    <a
        class="btn btn-primary"
        href="index.php?screen=list"
    >
        アンケート一覧へ
    </a>

</div>

<?php
pageEnd();
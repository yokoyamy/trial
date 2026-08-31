<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 * Single index.php / Apache 2.4 / PHP 8.5
 *
 * - DBなし
 * - PHP cURLなし
 * - PHP mail()なし
 * - 管理者認証なし（POC）
 * - サーバー側ファイル保存
 * - kintone REST API / SMTP 実接続
 * - Sodiumによる秘密情報暗号化
 */

const APP_NAME = 'アンケート管理システム';
const DATA_DIR_NAME = '.survey-data';
const SECRET_RELATIVE = '.secrets/アンケートアプリ/secret.key';

date_default_timezone_set('Asia/Tokyo');

/* =========================================================
 * 共通
 * ======================================================= */

function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function uuid(): string
{
    return bin2hex(random_bytes(16));
}

function redirect(string $screen, array $params = []): never
{
    $q = http_build_query(array_merge(['screen' => $screen], $params));
    header('Location: index.php?' . $q);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    $v = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return $v;
}

function failPage(string $message, int $status = 400): never
{
    http_response_code($status);
    renderErrorPage($message);
    exit;
}

/* =========================================================
 * セッション
 * ======================================================= */

$https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
);

session_set_cookie_params([
    'httponly' => true,
    'secure'   => $https,
    'samesite' => 'Lax',
    'path'     => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') ?: '/',
]);

session_start();

/* =========================================================
 * 保存領域
 *
 * Web公開領域外を優先。
 * 例:
 *   /var/www/app/index.php
 *   /var/www/.survey-data
 *
 * .secrets もWeb公開領域外に配置することを推奨。
 * ======================================================= */

$appDir = dirname(__FILE__);
$parentDir = dirname($appDir);

$dataDir = $parentDir . DIRECTORY_SEPARATOR . DATA_DIR_NAME;

if (!is_dir($dataDir) && !@mkdir($dataDir, 0700, true)) {
    failPage('データ保存領域を作成できません。', 500);
}

if (!is_writable($dataDir)) {
    failPage('データ保存領域へ書き込めません。', 500);
}

/* =========================================================
 * ファイル保存
 * ======================================================= */

function dataFile(string $name): string
{
    global $dataDir;
    return $dataDir . DIRECTORY_SEPARATOR . $name . '.json';
}

function readJson(string $name, mixed $default = []): mixed
{
    $file = dataFile($name);

    if (!is_file($file)) {
        return $default;
    }

    $raw = @file_get_contents($file);

    if ($raw === false || $raw === '') {
        return $default;
    }

    $data = json_decode($raw, true);

    return json_last_error() === JSON_ERROR_NONE ? $data : $default;
}

function writeJson(string $name, mixed $data): bool
{
    $file = dataFile($name);
    $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    @chmod($tmp, 0600);

    return @rename($tmp, $file);
}

function appendJsonRecord(string $name, array $record): bool
{
    $data = readJson($name, []);
    if (!is_array($data)) {
        $data = [];
    }

    $data[] = $record;
    return writeJson($name, $data);
}

/* =========================================================
 * 初期データ
 * ======================================================= */

function surveys(): array
{
    return readJson('surveys', []);
}

function customers(): array
{
    return readJson('customers', []);
}

function answers(): array
{
    return readJson('answers', []);
}

function sendLogs(): array
{
    return readJson('send_logs', []);
}

function appSettings(): array
{
    return readJson('settings', [
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
        ],
        'smtp' => [
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
        ],
    ]);
}

/* =========================================================
 * Sodium
 * ======================================================= */

function secretKeyPath(): string
{
    global $appDir;

    /*
     * 指定された
     * .secrets/アンケートアプリ/secret.key
     *
     * index.phpと同階層に.secretsを置く場合:
     *   $appDir . '/.secrets/...'
     *
     * 公開領域外運用を優先するため親階層を第一候補にする。
     */
    $candidates = [
        dirname($appDir) . DIRECTORY_SEPARATOR . SECRET_RELATIVE,
        $appDir . DIRECTORY_SEPARATOR . SECRET_RELATIVE,
    ];

    foreach ($candidates as $p) {
        if (is_file($p)) {
            return $p;
        }
    }

    return $candidates[0];
}

function encryptionKey(): string
{
    $file = secretKeyPath();

    if (!is_file($file)) {
        throw new RuntimeException(
            '暗号鍵が存在しません。.secrets/アンケートアプリ/secret.key を配置してください。'
        );
    }

    $key = @file_get_contents($file);

    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('暗号鍵の形式が不正です。');
    }

    return $key;
}

function encryptSecret(string $plain): string
{
    $key = encryptionKey();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, $key);

    return 'ENC:v1:' .
        base64_encode($nonce) . ':' .
        base64_encode($cipher);
}

function decryptSecret(string $value): string
{
    if (!str_starts_with($value, 'ENC:v1:')) {
        throw new RuntimeException('暗号化データの形式が不正です。');
    }

    $parts = explode(':', $value, 4);

    if (count($parts) !== 4) {
        throw new RuntimeException('暗号化データの形式が不正です。');
    }

    $nonce = base64_decode($parts[2], true);
    $cipher = base64_decode($parts[3], true);

    if ($nonce === false || $cipher === false) {
        throw new RuntimeException('暗号化データを復号できません。');
    }

    return sodium_crypto_secretbox_open(
        $cipher,
        $nonce,
        encryptionKey()
    );
}

/* =========================================================
 * CSRF
 * ======================================================= */

function csrfToken(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

function verifyCsrf(): void
{
    $token = $_POST['_csrf'] ?? '';

    if (
        !is_string($token)
        || !hash_equals($_SESSION['_csrf'] ?? '', $token)
    ) {
        failPage('セッションの有効期限が切れたか、不正なリクエストです。', 400);
    }
}

/* =========================================================
 * 入力検証
 * ======================================================= */

function requireString(string $key, int $max = 5000): string
{
    $v = trim((string)($_POST[$key] ?? ''));

    if ($v === '') {
        throw new InvalidArgumentException($key . 'は必須です。');
    }

    if (mb_strlen($v) > $max) {
        throw new InvalidArgumentException($key . 'が長すぎます。');
    }

    return $v;
}

function optionalString(string $key, int $max = 5000): string
{
    $v = trim((string)($_POST[$key] ?? ''));

    if (mb_strlen($v) > $max) {
        throw new InvalidArgumentException($key . 'が長すぎます。');
    }

    return $v;
}

function validSurveyId(string $id): bool
{
    return (bool)preg_match('/^[a-f0-9]{32}$/', $id);
}

function validEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/* =========================================================
 * アンケート処理
 * ======================================================= */

function findSurvey(string $id): ?array
{
    foreach (surveys() as $s) {
        if (($s['id'] ?? '') === $id) {
            return $s;
        }
    }

    return null;
}

function saveSurvey(array $survey): bool
{
    $all = surveys();
    $found = false;

    foreach ($all as $i => $s) {
        if (($s['id'] ?? '') === $survey['id']) {
            $all[$i] = $survey;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $all[] = $survey;
    }

    return writeJson('surveys', array_values($all));
}

function deleteSurvey(string $id): bool
{
    $all = array_values(array_filter(
        surveys(),
        fn(array $s): bool => ($s['id'] ?? '') !== $id
    ));

    return writeJson('surveys', $all);
}

function normalizeStatus(array $survey): array
{
    if (
        ($survey['status'] ?? '') === '公開中'
        && !empty($survey['end_at'])
        && strtotime((string)$survey['end_at']) !== false
        && strtotime((string)$survey['end_at']) < time()
    ) {
        $survey['status'] = '終了';
        $survey['updated_at'] = now();
    }

    return $survey;
}

function refreshSurveyStatuses(): void
{
    $all = surveys();
    $changed = false;

    foreach ($all as $i => $survey) {
        $new = normalizeStatus($survey);

        if ($new !== $survey) {
            $all[$i] = $new;
            $changed = true;
        }
    }

    if ($changed) {
        writeJson('surveys', $all);
    }
}

function statusClass(string $status): string
{
    return match ($status) {
        '公開中' => 'badge-published',
        '停止' => 'badge-stopped',
        '終了' => 'badge-ended',
        default => 'badge-draft',
    };
}

function canTransition(string $from, string $to): bool
{
    return match ($from) {
        '下書き' => $to === '公開中',
        '公開中' => $to === '停止',
        '停止' => $to === '公開中',
        default => false,
    };
}

function recalcNumbers(array &$survey): void
{
    $global = 0;

    foreach ($survey['groups'] as $gi => &$group) {
        $group['order'] = $gi + 1;

        foreach ($group['questions'] as $qi => &$question) {
            $global++;

            if (($survey['numbering'] ?? 'global') === 'group') {
                $question['number'] = 'Q' . ($gi + 1) . '-' . ($qi + 1);
            } else {
                $question['number'] = 'Q' . $global;
            }

            $question['order'] = $qi + 1;

            if (
                ($question['type'] ?? '') !== 'single'
                && isset($question['branch'])
            ) {
                unset($question['branch']);
            }
        }
        unset($question);
    }
    unset($group);
}

function newQuestion(): array
{
    return [
        'id' => uuid(),
        'number' => '',
        'order' => 1,
        'text' => '新しい質問',
        'type' => 'single',
        'required' => true,
        'options' => ['はい', 'いいえ'],
        'branch' => [],
    ];
}

function newGroup(): array
{
    return [
        'id' => uuid(),
        'order' => 1,
        'title' => '新しいグループ',
        'questions' => [newQuestion()],
    ];
}

function newSurvey(): array
{
    $survey = [
        'id' => uuid(),
        'title' => '',
        'description' => '',
        'start_at' => date('Y-m-d\TH:i'),
        'end_at' => date('Y-m-d\TH:i', strtotime('+30 days')),
        'numbering' => 'global',
        'status' => '下書き',
        'created_at' => now(),
        'updated_at' => now(),
        'groups' => [newGroup()],
    ];

    recalcNumbers($survey);
    return $survey;
}

function duplicateSurvey(array $source): array
{
    $copy = $source;
    $copy['id'] = uuid();
    $copy['title'] = ($source['title'] ?? 'アンケート') . '（複製）';
    $copy['status'] = '下書き';
    $copy['created_at'] = now();
    $copy['updated_at'] = now();

    foreach ($copy['groups'] as &$group) {
        $group['id'] = uuid();

        foreach ($group['questions'] as &$q) {
            $q['id'] = uuid();
        }
        unset($q);
    }
    unset($group);

    recalcNumbers($copy);
    return $copy;
}

/* =========================================================
 * 回答
 * ======================================================= */

function answerCount(string $surveyId): int
{
    return count(array_filter(
        answers(),
        fn(array $a): bool => ($a['survey_id'] ?? '') === $surveyId
    ));
}

function visibleQuestions(array $survey, array $answerData): array
{
    $result = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $q) {
            $visible = true;

            foreach ($survey['groups'] as $g2) {
                foreach ($g2['questions'] as $parent) {
                    foreach (($parent['branch'] ?? []) as $option => $target) {
                        if (
                            isset($answerData[$parent['id']])
                            && (
                                (
                                    is_array($answerData[$parent['id']])
                                    && in_array($option, $answerData[$parent['id']], true)
                                )
                                || (
                                    !is_array($answerData[$parent['id']])
                                    && (string)$answerData[$parent['id']] === (string)$option
                                )
                            )
                        ) {
                            if (($target ?? '') === 'none') {
                                if ($q['id'] !== $parent['id']) {
                                    $visible = false;
                                }
                            } elseif (($target ?? '') !== $q['id']) {
                                /*
                                 * 簡潔な分岐モデル:
                                 * 指定された質問以外を後続質問から除外する。
                                 */
                                if ($q['order'] > $parent['order']) {
                                    $visible = false;
                                }
                            }
                        }
                    }
                }
            }

            if ($visible) {
                $result[] = $q;
            }
        }
    }

    return $result;
}

/* =========================================================
 * kintone
 * PHP cURLを使わずHTTP stream wrapperで通信
 * ======================================================= */

function normalizeKintoneHost(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        throw new InvalidArgumentException('kintoneサブドメインは必須です。');
    }

    if (!preg_match('#^https?://#i', $value)) {
        $value = 'https://' . $value;
    }

    $url = parse_url($value);

    if (!$url || empty($url['host'])) {
        throw new InvalidArgumentException('kintoneサブドメインが不正です。');
    }

    $host = strtolower($url['host']);

    if (!preg_match('/^[a-z0-9.-]+$/', $host)) {
        throw new InvalidArgumentException('kintoneホスト名が不正です。');
    }

    return 'https://' . $host;
}

function httpRequest(
    string $url,
    array $headers = [],
    ?string $body = null,
    string $method = 'GET',
    bool $verifySsl = true,
    string $proxy = ''
): array {
    $headerText = '';

    foreach ($headers as $key => $value) {
        $headerText .= $key . ': ' . $value . "\r\n";
    }

    $httpOptions = [
        'method' => $method,
        'header' => $headerText,
        'ignore_errors' => true,
        'timeout' => 20,
        'follow_location' => 0,
        'max_redirects' => 0,
    ];

    if ($body !== null) {
        $httpOptions['content'] = $body;
    }

    $sslOptions = [
        'verify_peer' => $verifySsl,
        'verify_peer_name' => $verifySsl,
        'allow_self_signed' => !$verifySsl,
    ];

    $contextOptions = [
        'http' => $httpOptions,
        'ssl' => $sslOptions,
    ];

    if ($proxy !== '') {
        if (!preg_match('/^[^:\s]+:\d+$/', $proxy)) {
            throw new InvalidArgumentException('Proxyはhost:port形式で指定してください。');
        }

        $contextOptions['http']['proxy'] = 'tcp://' . $proxy;
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $error = null;

    set_error_handler(function ($severity, $message) use (&$error): bool {
        $error = $message;
        return true;
    });

    $response = @file_get_contents($url, false, $context);

    restore_error_handler();

    $headersReceived = $http_response_header ?? [];
    $status = 0;

    if (!empty($headersReceived[0])
        && preg_match('#HTTP/\S+\s+(\d{3})#', $headersReceived[0], $m)
    ) {
        $status = (int)$m[1];
    }

    if ($response === false) {
        return [
            'ok' => false,
            'status' => $status,
            'body' => '',
            'headers' => $headersReceived,
            'error' => $error ?: 'レスポンスを取得できませんでした。',
        ];
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $response,
        'headers' => $headersReceived,
        'error' => null,
    ];
}

function kintoneSettings(): array
{
    return appSettings()['kintone'];
}

function kintoneAuth(array $cfg): string
{
    if (
        empty($cfg['username'])
        || empty($cfg['password'])
    ) {
        throw new RuntimeException('kintoneログイン情報が未設定です。');
    }

    return base64_encode(
        $cfg['username'] . ':' . decryptSecret($cfg['password'])
    );
}

function kintoneRequest(
    string $path,
    string $method = 'GET',
    ?string $body = null
): array {
    $cfg = kintoneSettings();

    $host = normalizeKintoneHost((string)$cfg['subdomain']);

    $headers = [
        'X-Cybozu-Authorization' => kintoneAuth($cfg),
        'Accept' => 'application/json',
    ];

    if ($body !== null) {
        $headers['Content-Type'] = 'application/json';
    }

    return httpRequest(
        $host . $path,
        $headers,
        $body,
        $method,
        (bool)$cfg['verify_ssl'],
        (string)$cfg['proxy']
    );
}

function kintoneErrorMessage(array $res): string
{
    if (!empty($res['body'])) {
        $json = json_decode($res['body'], true);

        if (is_array($json)) {
            $code = $json['code'] ?? '';
            $message = $json['message'] ?? '';

            if ($code || $message) {
                return trim($code . ' ' . $message);
            }
        }
    }

    return match ((int)$res['status']) {
        401, 403 => '認証に失敗しました。',
        404 => '指定されたkintoneアプリが見つかりません。',
        408 => 'kintoneへの接続がタイムアウトしました。',
        301, 302, 303, 307, 308 => 'kintoneからリダイレクトが返されました。成功扱いにはしません。',
        default => $res['error'] ?: ('HTTPエラー: ' . $res['status']),
    };
}

function testKintone(): array
{
    $res = kintoneRequest('/k/v1/app.json?id=' . rawurlencode(
        (string)kintoneSettings()['app_id']
    ));

    return $res['ok']
        ? ['ok' => true, 'message' => 'kintoneへの接続・認証に成功しました。']
        : ['ok' => false, 'message' => kintoneErrorMessage($res)];
}

function getKintoneFields(): array
{
    $cfg = kintoneSettings();

    $res = kintoneRequest(
        '/k/v1/app/form/fields.json?app=' . rawurlencode((string)$cfg['app_id'])
    );

    if (!$res['ok']) {
        throw new RuntimeException(kintoneErrorMessage($res));
    }

    $json = json_decode($res['body'], true);

    if (!is_array($json) || !isset($json['properties'])) {
        throw new RuntimeException('kintone項目一覧の形式が不正です。');
    }

    return $json['properties'];
}

function getKintoneRecords(): array
{
    $cfg = kintoneSettings();
    $all = [];
    $offset = 0;

    do {
        $query = http_build_query([
            'app' => $cfg['app_id'],
            'query' => 'order by $id asc limit 500 offset ' . $offset,
        ]);

        $res = kintoneRequest('/k/v1/records.json?' . $query);

        if (!$res['ok']) {
            throw new RuntimeException(kintoneErrorMessage($res));
        }

        $json = json_decode($res['body'], true);

        if (!is_array($json) || !isset($json['records'])) {
            throw new RuntimeException('kintone顧客情報の形式が不正です。');
        }

        $records = $json['records'];
        $all = array_merge($all, $records);

        $count = count($records);
        $offset += $count;
    } while ($count === 500);

    return $all;
}

function fieldValue(array $record, string $code): string
{
    if ($code === '' || !isset($record[$code])) {
        return '';
    }

    $v = $record[$code]['value'] ?? '';

    if (is_array($v)) {
        $parts = [];

        foreach ($v as $item) {
            if (is_array($item) && isset($item['name'])) {
                $parts[] = (string)$item['name'];
            } elseif (is_array($item) && isset($item['value'])) {
                $parts[] = (string)$item['value'];
            } else {
                $parts[] = (string)$item;
            }
        }

        return implode(' ', $parts);
    }

    return (string)$v;
}

function syncCustomers(): int
{
    $cfg = kintoneSettings();

    if (
        empty($cfg['mapping']['name'])
        || empty($cfg['mapping']['email'])
    ) {
        throw new RuntimeException('氏名とメールアドレスのマッピングは必須です。');
    }

    $records = getKintoneRecords();
    $result = [];

    foreach ($records as $record) {
        $address = [];

        foreach (($cfg['mapping']['address'] ?? []) as $code) {
            $v = fieldValue($record, (string)$code);
            if ($v !== '') {
                $address[] = $v;
            }
        }

        $result[] = [
            'id' => fieldValue($record, '$id') ?: uuid(),
            'organization' => fieldValue($record, (string)($cfg['mapping']['organization'] ?? '')),
            'name' => fieldValue($record, (string)$cfg['mapping']['name']),
            'email' => fieldValue($record, (string)$cfg['mapping']['email']),
            'department' => fieldValue($record, (string)($cfg['mapping']['department'] ?? '')),
            'phone' => fieldValue($record, (string)($cfg['mapping']['phone'] ?? '')),
            'address' => implode(' ', $address),
            'synced_at' => now(),
        ];
    }

    if (!writeJson('customers', $result)) {
        throw new RuntimeException('顧客情報を保存できませんでした。');
    }

    return count($result);
}

/* =========================================================
 * SMTP
 * PHP mail()を使用せずSMTPソケットで通信
 * ======================================================= */

function smtpSettings(): array
{
    return appSettings()['smtp'];
}

function smtpRead($socket): array
{
    $lines = [];

    while (($line = fgets($socket, 515)) !== false) {
        $lines[] = rtrim($line, "\r\n");

        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }

    if (!$lines) {
        return [0, ''];
    }

    $last = end($lines);

    return [
        (int)substr($last, 0, 3),
        implode("\n", $lines),
    ];
}

function smtpWrite($socket, string $command): void
{
    fwrite($socket, $command . "\r\n");
}

function smtpExpect($socket, array $codes): string
{
    [$code, $text] = smtpRead($socket);

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTP応答エラー: ' . $code
        );
    }

    return $text;
}

function smtpConnect(bool $authenticate = true)
{
    $cfg = smtpSettings();

    if (empty($cfg['server'])) {
        throw new RuntimeException('SMTPサーバが未設定です。');
    }

    $port = (int)$cfg['port'];

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('SMTPポートが不正です。');
    }

    $server = (string)$cfg['server'];
    $encryption = (string)$cfg['encryption'];

    $transport = 'tcp://';

    if ($encryption === 'ssl') {
        $transport = 'ssl://';
    }

    $socket = @stream_socket_client(
        $transport . $server . ':' . $port,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPへ接続できません: ' . $errstr
        );
    }

    stream_set_timeout($socket, 20);

    smtpExpect($socket, [220]);

    smtpWrite($socket, 'EHLO localhost');
    smtpExpect($socket, [250]);

    if ($encryption === 'tls') {
        smtpWrite($socket, 'STARTTLS');
        smtpExpect($socket, [220]);

        if (!stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        )) {
            throw new RuntimeException('SMTP TLS接続を確立できません。');
        }

        smtpWrite($socket, 'EHLO localhost');
        smtpExpect($socket, [250]);
    }

    if ($authenticate && (bool)$cfg['auth']) {
        if (
            empty($cfg['username'])
            || empty($cfg['password'])
        ) {
            throw new RuntimeException('SMTP認証情報が未設定です。');
        }

        smtpWrite($socket, 'AUTH LOGIN');
        smtpExpect($socket, [334]);

        smtpWrite(
            $socket,
            base64_encode((string)$cfg['username'])
        );
        smtpExpect($socket, [334]);

        smtpWrite(
            $socket,
            base64_encode(decryptSecret((string)$cfg['password']))
        );
        smtpExpect($socket, [235]);
    }

    return $socket;
}

function smtpSend(
    string $to,
    string $subject,
    string $body
): void {
    $cfg = smtpSettings();

    if (!validEmail($to)) {
        throw new InvalidArgumentException('送信先メールアドレスが不正です。');
    }

    if (empty($cfg['from_email']) || !validEmail((string)$cfg['from_email'])) {
        throw new RuntimeException('送信元メールアドレスが未設定または不正です。');
    }

    $socket = smtpConnect(true);

    try {
        smtpWrite($socket, 'MAIL FROM:<' . $cfg['from_email'] . '>');
        smtpExpect($socket, [250]);

        smtpWrite($socket, 'RCPT TO:<' . $to . '>');
        smtpExpect($socket, [250, 251]);

        smtpWrite($socket, 'DATA');
        smtpExpect($socket, [354]);

        $headers = [];
        $headers[] = 'From: ' . mb_encode_mimeheader(
            (string)$cfg['from_name'],
            'UTF-8'
        ) . ' <' . $cfg['from_email'] . '>';
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8');
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        if (!empty($cfg['reply_to']) && validEmail((string)$cfg['reply_to'])) {
            $headers[] = 'Reply-To: ' . $cfg['reply_to'];
        }

        $message = implode("\r\n", $headers)
            . "\r\n\r\n"
            . preg_replace('/^\./m', '..', $body)
            . "\r\n.";

        smtpWrite($socket, $message);
        smtpExpect($socket, [250]);

        smtpWrite($socket, 'QUIT');
        smtpRead($socket);
    } finally {
        fclose($socket);
    }
}

function smtpTest(): array
{
    $socket = smtpConnect(true);

    smtpWrite($socket, 'QUIT');
    smtpRead($socket);
    fclose($socket);

    return [
        'ok' => true,
        'message' => 'SMTPへの接続・認証に成功しました。',
    ];
}

/* =========================================================
 * CSV
 * ======================================================= */

function csvDownload(string $surveyId): never
{
    $survey = findSurvey($surveyId);

    if (!$survey) {
        failPage('対象アンケートがありません。', 404);
    }

    $rows = [];

    foreach (answers() as $answer) {
        if (($answer['survey_id'] ?? '') !== $surveyId) {
            continue;
        }

        $row = [
            '回答ID' => $answer['id'],
            '回答日時' => $answer['created_at'],
        ];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $q) {
                $v = $answer['answers'][$q['id']] ?? '';

                if (is_array($v)) {
                    $v = implode(', ', $v);
                }

                $row[$q['number'] . ' ' . $q['text']] = $v;
            }
        }

        $rows[] = $row;
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey-' .
        rawurlencode($surveyId) . '.csv"'
    );

    echo "\xEF\xBB\xBF";

    if (!$rows) {
        echo "回答ID,回答日時\r\n";
        exit;
    }

    $fp = fopen('php://output', 'w');

    fputcsv($fp, array_keys($rows[0]));

    foreach ($rows as $row) {
        fputcsv($fp, array_values($row));
    }

    fclose($fp);
    exit;
}

/* =========================================================
 * PDF
 *
 * 外部ライブラリを使わないPOC。
 * ブラウザ印刷用HTMLをPDF出力相当として提供。
 * ======================================================= */

function pdfDownload(string $surveyId): never
{
    $survey = findSurvey($surveyId);

    if (!$survey) {
        failPage('対象アンケートがありません。', 404);
    }

    header('Content-Type: text/html; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey-' .
        rawurlencode($surveyId) . '.html"'
    );

    echo '<!doctype html><html lang="ja"><meta charset="UTF-8">';
    echo '<title>' . e($survey['title']) . '</title>';
    echo '<style>body{font-family:sans-serif}table{border-collapse:collapse;width:100%}td,th{border:1px solid #999;padding:6px}</style>';
    echo '<h1>' . e($survey['title']) . '</h1>';

    foreach (answers() as $answer) {
        if (($answer['survey_id'] ?? '') !== $surveyId) {
            continue;
        }

        echo '<h2>回答 ' . e($answer['created_at']) . '</h2>';
        echo '<table>';

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $q) {
                $v = $answer['answers'][$q['id']] ?? '';

                if (is_array($v)) {
                    $v = implode(', ', $v);
                }

                echo '<tr><th>' . e($q['number'] . ' ' . $q['text']) .
                    '</th><td>' . nl2br(e($v)) . '</td></tr>';
            }
        }

        echo '</table>';
    }

    echo '<script>window.print()</script>';
    echo '</html>';
    exit;
}

/* =========================================================
 * POST処理
 * ======================================================= */

$screen = (string)($_GET['screen'] ?? 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();

        $action = (string)($_POST['action'] ?? '');

        switch ($action) {

            /* ---------------------------------------------
             * アンケート保存
             * ------------------------------------------- */
            case 'save_survey':
                $id = (string)($_POST['id'] ?? '');

                $survey = $id !== '' ? findSurvey($id) : null;

                if ($id !== '' && (!$survey || !validSurveyId($id))) {
                    throw new InvalidArgumentException('アンケートIDが不正です。');
                }

                if (!$survey) {
                    $survey = newSurvey();
                }

                $survey['title'] = requireString('title', 200);
                $survey['description'] = optionalString('description', 5000);

                $survey['start_at'] = optionalString('start_at', 30);
                $survey['end_at'] = optionalString('end_at', 30);

                if (
                    $survey['start_at'] !== ''
                    && strtotime($survey['start_at']) === false
                ) {
                    throw new InvalidArgumentException('開始日時が不正です。');
                }

                if (
                    $survey['end_at'] !== ''
                    && strtotime($survey['end_at']) === false
                ) {
                    throw new InvalidArgumentException('終了日時が不正です。');
                }

                if (
                    $survey['start_at'] !== ''
                    && $survey['end_at'] !== ''
                    && strtotime($survey['start_at']) > strtotime($survey['end_at'])
                ) {
                    throw new InvalidArgumentException(
                        '開始日時は終了日時以前にしてください。'
                    );
                }

                $numbering = (string)($_POST['numbering'] ?? 'global');

                if (!in_array($numbering, ['global', 'group'], true)) {
                    throw new InvalidArgumentException('採番方式が不正です。');
                }

                $survey['numbering'] = $numbering;

                /*
                 * 新規は下書き。
                 * 既存編集は状態維持。
                 */
                if (empty($survey['created_at'])) {
                    $survey['created_at'] = now();
                }

                $survey['updated_at'] = now();

                /*
                 * JSONで送られた質問編集データ。
                 */
                $groupsJson = (string)($_POST['groups_json'] ?? '');

                if ($groupsJson !== '') {
                    $groups = json_decode($groupsJson, true);

                    if (!is_array($groups)) {
                        throw new InvalidArgumentException(
                            '質問データが不正です。'
                        );
                    }

                    $normalizedGroups = [];

                    foreach ($groups as $gi => $g) {
                        if (!is_array($g)) {
                            continue;
                        }

                        $group = [
                            'id' => validSurveyId((string)($g['id'] ?? ''))
                                ? $g['id']
                                : uuid(),
                            'order' => $gi + 1,
                            'title' => mb_substr(
                                trim((string)($g['title'] ?? '')),
                                0,
                                200
                            ),
                            'questions' => [],
                        ];

                        foreach (($g['questions'] ?? []) as $qi => $q) {
                            if (!is_array($q)) {
                                continue;
                            }

                            $type = (string)($q['type'] ?? 'single');

                            if (!in_array(
                                $type,
                                ['single', 'multiple', 'text'],
                                true
                            )) {
                                $type = 'single';
                            }

                            $options = [];

                            foreach (($q['options'] ?? []) as $option) {
                                $option = trim((string)$option);

                                if ($option !== '') {
                                    $options[] = mb_substr($option, 0, 500);
                                }
                            }

                            if ($type === 'single' || $type === 'multiple') {
                                if (!$options) {
                                    $options = ['選択肢1'];
                                }
                            }

                            $question = [
                                'id' => validSurveyId((string)($q['id'] ?? ''))
                                    ? $q['id']
                                    : uuid(),
                                'number' => '',
                                'order' => $qi + 1,
                                'text' => mb_substr(
                                    trim((string)($q['text'] ?? '')),
                                    0,
                                    2000
                                ),
                                'type' => $type,
                                'required' => !empty($q['required']),
                                'options' => $options,
                                'branch' => [],
                            ];

                            foreach (($q['branch'] ?? []) as $option => $target) {
                                $question['branch'][(string)$option] =
                                    (string)$target;
                            }

                            $group['questions'][] = $question;
                        }

                        $normalizedGroups[] = $group;
                    }

                    if (!$normalizedGroups) {
                        $normalizedGroups[] = newGroup();
                    }

                    $survey['groups'] = $normalizedGroups;
                }

                recalcNumbers($survey);

                if (!saveSurvey($survey)) {
                    throw new RuntimeException(
                        'アンケートを保存できませんでした。'
                    );
                }

                flash('success', 'アンケートを保存しました。');
                redirect('list');
                break;

            /* ---------------------------------------------
             * 状態変更
             * ------------------------------------------- */
            case 'change_status':
                $id = (string)($_POST['id'] ?? '');
                $to = (string)($_POST['status'] ?? '');

                $survey = findSurvey($id);

                if (!$survey) {
                    throw new InvalidArgumentException('対象アンケートがありません。');
                }

                $survey = normalizeStatus($survey);

                if (!canTransition(
                    (string)$survey['status'],
                    $to
                )) {
                    throw new InvalidArgumentException(
                        '許可されていない状態遷移です。'
                    );
                }

                $survey['status'] = $to;
                $survey['updated_at'] = now();

                if (!saveSurvey($survey)) {
                    throw new RuntimeException('状態を保存できませんでした。');
                }

                flash('success', '状態を変更しました。');
                redirect('edit', ['id' => $id]);
                break;

            /* ---------------------------------------------
             * 複製
             * ------------------------------------------- */
            case 'duplicate':
                $survey = findSurvey((string)($_POST['id'] ?? ''));

                if (!$survey) {
                    throw new InvalidArgumentException('対象アンケートがありません。');
                }

                if (!saveSurvey(duplicateSurvey($survey))) {
                    throw new RuntimeException('アンケートを複製できませんでした。');
                }

                flash('success', 'アンケートを複製しました。');
                redirect('list');
                break;

            /* ---------------------------------------------
             * 削除
             * ------------------------------------------- */
            case 'delete':
                $id = (string)($_POST['id'] ?? '');

                if (!findSurvey($id)) {
                    throw new InvalidArgumentException('対象アンケートがありません。');
                }

                if (!deleteSurvey($id)) {
                    throw new RuntimeException('アンケートを削除できませんでした。');
                }

                flash('success', 'アンケートを削除しました。');
                redirect('list');
                break;

            /* ---------------------------------------------
             * 回答
             * ------------------------------------------- */
            case 'answer_confirm':
                $id = (string)($_POST['survey_id'] ?? '');
                $survey = findSurvey($id);

                if (!$survey) {
                    throw new InvalidArgumentException('アンケートがありません。');
                }

                $survey = normalizeStatus($survey);

                if ($survey['status'] !== '公開中') {
                    throw new InvalidArgumentException(
                        'このアンケートは現在回答できません。'
                    );
                }

                $answerData = $_POST['answer'] ?? [];

                if (!is_array($answerData)) {
                    throw new InvalidArgumentException('回答データが不正です。');
                }

                $visible = visibleQuestions($survey, $answerData);

                foreach ($visible as $q) {
                    if (!$q['required']) {
                        continue;
                    }

                    $v = $answerData[$q['id']] ?? '';

                    $empty = is_array($v)
                        ? count($v) === 0
                        : trim((string)$v) === '';

                    if ($empty) {
                        throw new InvalidArgumentException(
                            $q['number'] . ' は必須です。'
                        );
                    }
                }

                $_SESSION['answer_' . $id] = $answerData;

                redirect('confirm', ['id' => $id]);
                break;

            case 'answer_submit':
                $id = (string)($_POST['survey_id'] ?? '');
                $survey = findSurvey($id);

                if (!$survey) {
                    throw new InvalidArgumentException('アンケートがありません。');
                }

                $survey = normalizeStatus($survey);

                if ($survey['status'] !== '公開中') {
                    throw new InvalidArgumentException(
                        'このアンケートは現在回答できません。'
                    );
                }

                $answerData = $_SESSION['answer_' . $id] ?? null;

                if (!is_array($answerData)) {
                    throw new RuntimeException(
                        '回答情報が見つかりません。最初からやり直してください。'
                    );
                }

                appendJsonRecord('answers', [
                    'id' => uuid(),
                    'survey_id' => $id,
                    'answers' => $answerData,
                    'created_at' => now(),
                ]);

                unset($_SESSION['answer_' . $id]);

                /*
                 * 回答完了後は管理者画面へ遷移しない。
                 */
                redirect('complete', ['id' => $id]);
                break;

            /* ---------------------------------------------
             * SMTP設定保存
             * ------------------------------------------- */
            case 'save_smtp':
                $settings = appSettings();

                $server = requireString('smtp_server', 255);
                $port = (int)($_POST['smtp_port'] ?? 0);

                if ($port < 1 || $port > 65535) {
                    throw new InvalidArgumentException('SMTPポートが不正です。');
                }

                $encryption = (string)($_POST['smtp_encryption'] ?? 'tls');

                if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
                    throw new InvalidArgumentException('暗号化方式が不正です。');
                }

                $fromEmail = requireString('smtp_from_email', 320);

                if (!validEmail($fromEmail)) {
                    throw new InvalidArgumentException(
                        '送信元メールアドレスが不正です。'
                    );
                }

                $replyTo = optionalString('smtp_reply_to', 320);

                if ($replyTo !== '' && !validEmail($replyTo)) {
                    throw new InvalidArgumentException(
                        '返信先メールアドレスが不正です。'
                    );
                }

                $password = optionalString('smtp_password', 1000);

                $settings['smtp']['server'] = $server;
                $settings['smtp']['port'] = $port;
                $settings['smtp']['encryption'] = $encryption;
                $settings['smtp']['auth'] = !empty($_POST['smtp_auth']);
                $settings['smtp']['username'] =
                    optionalString('smtp_username', 320);
                $settings['smtp']['from_email'] = $fromEmail;
                $settings['smtp']['from_name'] =
                    optionalString('smtp_from_name', 200);
                $settings['smtp']['reply_to'] = $replyTo;

                if ($password !== '') {
                    $settings['smtp']['password'] = encryptSecret($password);
                }

                $settings['smtp']['status'] = '未設定';

                if (!writeJson('settings', $settings)) {
                    throw new RuntimeException('SMTP設定を保存できませんでした。');
                }

                flash('success', 'SMTP設定を保存しました。');
                redirect('mail');
                break;

            /* ---------------------------------------------
             * SMTP接続テスト
             * ------------------------------------------- */
            case 'test_smtp':
                $result = smtpTest();

                $settings = appSettings();
                $settings['smtp']['status'] = '接続確認済み';
                writeJson('settings', $settings);

                flash('success', $result['message']);
                redirect('mail');
                break;

            /* ---------------------------------------------
             * テストメール
             * ------------------------------------------- */
            case 'send_test_mail':
                $to = requireString('test_mail_to', 320);

                if (!validEmail($to)) {
                    throw new InvalidArgumentException(
                        'テストメール宛先が不正です。'
                    );
                }

                smtpSend(
                    $to,
                    'アンケートアプリ テストメール',
                    'SMTP接続テストメールです。'
                );

                flash('success', 'テストメールを送信しました。');
                redirect('mail');
                break;

            /* ---------------------------------------------
             * kintone設定
             * ------------------------------------------- */
            case 'save_kintone':
                $settings = appSettings();

                $subdomain = normalizeKintoneHost(
                    requireString('k_subdomain', 255)
                );

                $appId = (int)($_POST['k_app_id'] ?? 0);

                if ($appId < 1) {
                    throw new InvalidArgumentException(
                        'kintoneアプリIDが不正です。'
                    );
                }

                $username = requireString('k_username', 320);
                $password = optionalString('k_password', 1000);
                $proxy = optionalString('k_proxy', 255);

                if (
                    $proxy !== ''
                    && !preg_match('/^[^:\s]+:\d+$/', $proxy)
                ) {
                    throw new InvalidArgumentException(
                        'Proxyはhost:port形式で指定してください。'
                    );
                }

                $settings['kintone']['subdomain'] = $subdomain;
                $settings['kintone']['app_id'] = $appId;
                $settings['kintone']['username'] = $username;
                $settings['kintone']['proxy'] = $proxy;
                $settings['kintone']['verify_ssl'] =
                    !empty($_POST['k_verify_ssl']);

                if ($password !== '') {
                    $settings['kintone']['password'] =
                        encryptSecret($password);
                }

                if (!writeJson('settings', $settings)) {
                    throw new RuntimeException(
                        'kintone設定を保存できませんでした。'
                    );
                }

                flash('success', 'kintone設定を保存しました。');
                redirect('kintone');
                break;

            /* ---------------------------------------------
             * kintone接続テスト
             * ------------------------------------------- */
            case 'test_kintone':
                $result = testKintone();

                if (!$result['ok']) {
                    throw new RuntimeException($result['message']);
                }

                flash('success', $result['message']);
                redirect('kintone');
                break;

            /* ---------------------------------------------
             * kintone項目取得
             * ------------------------------------------- */
            case 'fetch_kintone_fields':
                $fields = getKintoneFields();

                $settings = appSettings();
                $settings['kintone']['fields'] = $fields;

                if (!writeJson('settings', $settings)) {
                    throw new RuntimeException(
                        'kintone項目一覧を保存できませんでした。'
                    );
                }

                flash('success', 'kintone項目一覧を取得しました。');
                redirect('kintone');
                break;

            /* ---------------------------------------------
             * kintoneマッピング保存
             * ------------------------------------------- */
            case 'save_mapping':
                $settings = appSettings();

                $address = $_POST['map_address'] ?? [];

                if (!is_array($address)) {
                    $address = [];
                }

                $settings['kintone']['mapping'] = [
                    'organization' => optionalString('map_organization', 255),
                    'name' => requireString('map_name', 255),
                    'email' => requireString('map_email', 255),
                    'department' => optionalString('map_department', 255),
                    'phone' => optionalString('map_phone', 255),
                    'address' => array_values(array_map(
                        'strval',
                        $address
                    )),
                ];

                if (!writeJson('settings', $settings)) {
                    throw new RuntimeException(
                        'マッピングを保存できませんでした。'
                    );
                }

                flash('success', '項目マッピングを保存しました。');
                redirect('kintone');
                break;

            /* ---------------------------------------------
             * 顧客同期
             * ------------------------------------------- */
            case 'sync_customers':
                $count = syncCustomers();

                flash(
                    'success',
                    $count . '件の顧客情報を同期しました。'
                );

                redirect('kintone');
                break;

            /* ---------------------------------------------
             * メール送信
             * ------------------------------------------- */
            case 'send_mails':
                $surveyId = (string)($_POST['survey_id'] ?? '');
                $survey = findSurvey($surveyId);

                if (!$survey) {
                    throw new InvalidArgumentException(
                        '対象アンケートがありません。'
                    );
                }

                $selected = $_POST['customers'] ?? [];

                if (!is_array($selected) || !$selected) {
                    throw new InvalidArgumentException(
                        '送信対象を選択してください。'
                    );
                }

                $subject = requireString('mail_subject', 500);
                $body = requireString('mail_body', 10000);

                $baseUrl =
                    (
                        $https ? 'https://' : 'http://'
                    )
                    . ($_SERVER['HTTP_HOST'] ?? '')
                    . dirname($_SERVER['SCRIPT_NAME'] ?? '/');

                $surveyUrl =
                    rtrim($baseUrl, '/') .
                    '/index.php?screen=answer&id=' .
                    rawurlencode($surveyId);

                $customerMap = [];

                foreach (customers() as $c) {
                    $customerMap[(string)$c['id']] = $c;
                }

                $success = 0;
                $failed = 0;

                foreach ($selected as $customerId) {
                    $customerId = (string)$customerId;

                    if (!isset($customerMap[$customerId])) {
                        $failed++;
                        continue;
                    }

                    $customer = $customerMap[$customerId];

                    $personalSubject = str_replace(
                        ['{顧客名}', '{アンケートURL}'],
                        [
                            (string)$customer['name'],
                            $surveyUrl,
                        ],
                        $subject
                    );

                    $personalBody = str_replace(
                        ['{顧客名}', '{アンケートURL}'],
                        [
                            (string)$customer['name'],
                            $surveyUrl,
                        ],
                        $body
                    );

                    try {
                        smtpSend(
                            (string)$customer['email'],
                            $personalSubject,
                            $personalBody
                        );

                        $status = '送信成功';
                        $success++;
                    } catch (Throwable $ex) {
                        /*
                         * ログへ秘密情報を出さない。
                         */
                        $status = '送信失敗';
                        $failed++;
                    }

                    appendJsonRecord('send_logs', [
                        'id' => uuid(),
                        'survey_id' => $surveyId,
                        'customer_id' => $customerId,
                        'customer_name' => (string)$customer['name'],
                        'email' => (string)$customer['email'],
                        'status' => $status,
                        'created_at' => now(),
                    ]);
                }

                flash(
                    $failed > 0 ? 'danger' : 'success',
                    "送信結果：成功 {$success}件 / 失敗 {$failed}件"
                );

                /*
                 * 同じ送信画面へ戻る。
                 */
                redirect('send', ['id' => $surveyId]);
                break;
        }

    } catch (Throwable $ex) {
        flash(
            'danger',
            $ex instanceof InvalidArgumentException
                ? $ex->getMessage()
                : '処理に失敗しました。設定・入力内容・外部サービスの状態を確認してください。'
        );

        $returnScreen = (string)($_POST['return_screen'] ?? 'list');
        $returnId = (string)($_POST['return_id'] ?? '');

        if ($returnId !== '') {
            redirect($returnScreen, ['id' => $returnId]);
        }

        redirect($returnScreen);
    }
}

/* =========================================================
 * ダウンロード
 * ======================================================= */

if ($screen === 'csv') {
    csvDownload((string)($_GET['id'] ?? ''));
}

if ($screen === 'pdf') {
    pdfDownload((string)($_GET['id'] ?? ''));
}

/* =========================================================
 * ステータス自動更新
 * ======================================================= */

refreshSurveyStatuses();

/* =========================================================
 * 共通HTML
 * ======================================================= */

function renderHead(string $title, bool $admin = true): void
{
    global $https;

    echo '<!DOCTYPE html>';
    echo '<html lang="ja">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1.0">';
    echo '<meta name="robots" content="noindex,nofollow">';
    echo '<title>' . e($title) . ' - ' . APP_NAME . '</title>';

    echo <<<CSS
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
 font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",
 "Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
 color:var(--text);
 background:#f8fafc;
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
a{color:var(--primary);text-decoration:none}
a:hover{text-decoration:underline}
.hidden{display:none!important}
.admin-header{
 position:sticky;top:0;z-index:50;height:64px;
 background:#0f172a;color:#fff;display:flex;align-items:center;
 padding:0 24px;gap:28px;box-shadow:0 2px 10px rgba(0,0,0,.12)
}
.admin-logo{font-weight:700;white-space:nowrap;font-size:18px}
.admin-nav{display:flex;gap:4px;height:100%;align-items:center}
.admin-nav a{
 height:40px;padding:0 14px;border-radius:7px;color:#cbd5e1;
 display:flex;align-items:center
}
.admin-nav a:hover,.admin-nav a.active{background:#1e293b;color:#fff;text-decoration:none}
.admin-spacer{flex:1}
.admin-note{color:#cbd5e1;font-size:12px}
.page{max-width:1500px;margin:0 auto;padding:28px}
.page-title{
 display:flex;align-items:center;justify-content:space-between;
 gap:16px;margin-bottom:24px
}
.page-title h1{margin:0;font-size:26px}
.page-title p{margin:5px 0 0;color:var(--gray);font-size:13px}
.card{
 background:#fff;border:1px solid var(--border);
 border-radius:12px;box-shadow:var(--shadow);margin-bottom:20px
}
.card-header{
 padding:18px 20px;border-bottom:1px solid var(--border);
 display:flex;justify-content:space-between;align-items:center;gap:12px
}
.card-body{padding:20px}
.btn{
 border:1px solid var(--border);background:#fff;color:var(--text);
 border-radius:7px;padding:9px 14px;min-height:40px
}
.btn:hover{background:#f8fafc}
.btn-primary{background:var(--primary);color:#fff;border-color:var(--primary)}
.btn-primary:hover{background:var(--primary-dark)}
.btn-success{background:var(--success);color:#fff;border-color:var(--success)}
.btn-danger{color:#fff;background:var(--danger);border-color:var(--danger)}
.btn-warning{color:#fff;background:var(--warning);border-color:var(--warning)}
.btn-sm{min-height:32px;padding:5px 9px;font-size:12px}
.btn:disabled{opacity:.45;cursor:not-allowed}
.badge{
 display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;
 font-size:12px;font-weight:600;white-space:nowrap
}
.badge-draft{background:#e2e8f0;color:#475569}
.badge-published{background:#dcfce7;color:#166534}
.badge-stopped{background:#fef3c7;color:#92400e}
.badge-ended{background:#fee2e2;color:#991b1b}
.badge-success{background:#dcfce7;color:#166534}
.badge-danger{background:#fee2e2;color:#991b1b}
.badge-info{background:#dbeafe;color:#1d4ed8}
.form-grid{
 display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px
}
.form-group{display:flex;flex-direction:column;gap:7px}
.form-group.full{grid-column:1/-1}
label{font-weight:600;font-size:13px}
input[type=text],input[type=email],input[type=password],
input[type=datetime-local],input[type=number],textarea,select{
 width:100%;border:1px solid #cbd5e1;border-radius:7px;
 padding:10px 12px;background:#fff;color:var(--text)
}
textarea{resize:vertical;min-height:100px}
.help{color:var(--gray);font-size:12px}
.actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.alert{border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:13px}
.alert-success{color:#166534;background:#dcfce7;border:1px solid #bbf7d0}
.alert-danger{color:#991b1b;background:#fee2e2;border:1px solid #fecaca}
.alert-warning{color:#92400e;background:#fef3c7;border:1px solid #fde68a}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:900px}
th,td{
 padding:12px 10px;border-bottom:1px solid var(--border);
 text-align:left;vertical-align:middle;font-size:13px
}
th{background:#f8fafc;white-space:nowrap}
.searchbar{display:grid;grid-template-columns:1fr 180px 180px auto;gap:10px}
.toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.stats{
 display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px
}
.stat{
 background:#fff;border:1px solid var(--border);border-radius:10px;
 padding:18px
}
.stat .label{color:var(--gray);font-size:12px}
.stat .value{font-size:26px;font-weight:700;margin-top:4px}
.group-card{border:1px solid var(--border);border-radius:10px;margin-bottom:18px}
.group-head{
 background:#f8fafc;padding:12px 14px;display:flex;
 align-items:center;gap:10px;border-bottom:1px solid var(--border)
}
.group-head input{font-weight:700}
.question-card{
 margin:14px;padding:16px;border:1px solid var(--border);
 border-radius:9px;background:#fff
}
.question-head{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.question-number{font-weight:700;color:var(--primary);min-width:55px}
.option-row{display:flex;gap:8px;margin-top:8px}
.option-row input{flex:1}
.drag-handle{cursor:grab;color:#94a3b8}
.preview-box{
 background:#fff;border:1px solid var(--border);border-radius:10px;
 padding:28px;max-width:760px;margin:0 auto
}
.answer-page{min-height:100vh;background:#f8fafc}
.answer-header{
 background:#0f172a;color:#fff;padding:22px 18px
}
.answer-main{max-width:760px;margin:0 auto;padding:22px 14px}
.answer-title{font-size:25px;margin:0 0 8px}
.answer-description{color:#cbd5e1}
.answer-question{
 background:#fff;border:1px solid var(--border);
 border-radius:10px;padding:18px;margin-bottom:16px
}
.answer-question h3{font-size:16px;margin:0 0 12px}
.answer-option{
 display:flex;align-items:center;gap:10px;padding:12px;
 border:1px solid #e2e8f0;border-radius:8px;margin:8px 0;
 cursor:pointer
}
.answer-option:hover{background:#f8fafc}
.answer-actions{
 position:sticky;bottom:0;background:rgba(248,250,252,.95);
 padding:12px 0;display:flex;justify-content:flex-end
}
.empty{
 padding:45px 20px;text-align:center;color:var(--gray)
}
.modal{
 position:fixed;inset:0;background:rgba(15,23,42,.55);
 display:flex;align-items:center;justify-content:center;padding:20px;z-index:100
}
.modal-box{
 background:#fff;border-radius:12px;max-width:500px;width:100%;
 padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.2)
}
.loading{
 position:fixed;inset:0;background:rgba(255,255,255,.75);
 z-index:200;display:none;align-items:center;justify-content:center
}
.loading.show{display:flex}
.spinner{
 width:42px;height:42px;border:4px solid #dbeafe;
 border-top-color:var(--primary);border-radius:50%;
 animation:spin .8s linear infinite
}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:900px){
 .admin-header{padding:0 12px;gap:10px;overflow-x:auto}
 .admin-nav a{padding:0 9px;font-size:12px}
 .page{padding:18px 12px}
 .form-grid{grid-template-columns:1fr}
 .searchbar{grid-template-columns:1fr}
 .stats{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:600px){
 .admin-header{height:auto;min-height:58px;flex-wrap:wrap;padding:8px}
 .admin-nav{height:auto;overflow-x:auto}
 .admin-note{display:none}
 .page-title{align-items:flex-start;flex-direction:column}
 .stats{grid-template-columns:1fr 1fr}
 .card-body{padding:14px}
 .answer-main{padding:16px 10px}
 .answer-title{font-size:21px}
}
</style>
CSS;

    echo '</head><body>';

    if ($admin) {
        echo '<header class="admin-header">';
        echo '<div class="admin-logo">' . APP_NAME . '</div>';
        echo '<nav class="admin-nav">';
        echo '<a href="index.php?screen=list">アンケート</a>';
        echo '<a href="index.php?screen=kintone">kintone</a>';
        echo '<a href="index.php?screen=mail">メール設定</a>';
        echo '</nav>';
        echo '<div class="admin-spacer"></div>';
        echo '<div class="admin-note">POC / 管理者認証なし</div>';
        echo '</header>';
    }
}

function renderFoot(): void
{
    echo <<<HTML
<script>
document.addEventListener('submit',function(e){
 const form=e.target;
 if(form.dataset.loading==='1'){
   document.getElementById('loading')?.classList.add('show');
 }
});

function confirmAction(message){
 return window.confirm(message);
}

function togglePassword(id){
 const el=document.getElementById(id);
 if(el) el.type=el.type==='password'?'text':'password';
}

function addOption(btn){
 const wrap=btn.closest('.question-card').querySelector('.options');
 const row=document.createElement('div');
 row.className='option-row';
 row.innerHTML='<input type="text" value=""><button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove()">削除</button>';
 wrap.appendChild(row);
}

function buildSurveyJson(){
 const groups=[];
 document.querySelectorAll('.group-card').forEach((gc)=>{
   const group={
     id:gc.dataset.id,
     title:gc.querySelector('.group-title').value,
     questions:[]
   };

   gc.querySelectorAll('.question-card').forEach((qc)=>{
     const q={
       id:qc.dataset.id,
       text:qc.querySelector('.question-text').value,
       type:qc.querySelector('.question-type').value,
       required:qc.querySelector('.question-required').checked,
       options:[]
     };

     qc.querySelectorAll('.options input').forEach(i=>{
       if(i.value.trim()!=='') q.options.push(i.value);
     });

     q.branch={};

     qc.querySelectorAll('.branch-select').forEach(sel=>{
       const option=sel.dataset.option;
       q.branch[option]=sel.value;
     });

     group.questions.push(q);
   });

   groups.push(group);
 });

 document.getElementById('groups_json').value=JSON.stringify(groups);
 return true;
}

function addGroup(){
 const tpl=document.getElementById('group-template');
 const area=document.getElementById('groups-area');
 const node=tpl.content.cloneNode(true);
 const id=crypto.randomUUID().replaceAll('-','');
 node.querySelector('.group-card').dataset.id=id;
 node.querySelector('.group-title').value='新しいグループ';
 area.appendChild(node);
}

function addQuestion(btn){
 const gc=btn.closest('.group-card');
 const tpl=document.getElementById('question-template');
 const node=tpl.content.cloneNode(true);
 const id=crypto.randomUUID().replaceAll('-','');
 node.querySelector('.question-card').dataset.id=id;
 gc.querySelector('.questions').appendChild(node);
}

function updateBranches(){
 const all=[...document.querySelectorAll('.question-card')];
 document.querySelectorAll('.question-card').forEach(qc=>{
   const type=qc.querySelector('.question-type').value;
   const branchArea=qc.querySelector('.branch-area');

   if(type!=='single'){
     branchArea.innerHTML='';
     return;
   }

   const options=[...qc.querySelectorAll('.options input')].map(x=>x.value).filter(Boolean);
   branchArea.innerHTML='';

   if(!options.length) return;

   options.forEach(opt=>{
     const label=document.createElement('label');
     label.style.marginTop='8px';
     label.innerHTML='<span>'+escapeHtml(opt)+'</span>';

     const select=document.createElement('select');
     select.className='branch-select';
     select.dataset.option=opt;

     const none=document.createElement('option');
     none.value='';
     none.textContent='次の質問へ';
     select.appendChild(none);

     all.forEach(target=>{
       if(target===qc) return;
       const number=target.querySelector('.question-number')?.textContent || '';
       const text=target.querySelector('.question-text')?.value || '';
       const o=document.createElement('option');
       o.value=target.dataset.id;
       o.textContent=number+' '+text;
       select.appendChild(o);
     });

     label.appendChild(select);
     branchArea.appendChild(label);
   });
 });
}

function escapeHtml(v){
 return v.replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}

document.addEventListener('change',e=>{
 if(e.target.classList.contains('question-type')) updateBranches();
});
</script>
<div class="loading" id="loading"><div class="spinner"></div></div>
</body></html>
HTML;
}

/* =========================================================
 * エラー
 * ======================================================= */

function renderErrorPage(string $message): void
{
    renderHead('エラー', false);

    echo '<main class="answer-page">';
    echo '<div class="answer-main">';
    echo '<div class="card"><div class="card-body">';
    echo '<h1>処理できませんでした</h1>';
    echo '<div class="alert alert-danger">' . e($message) . '</div>';
    echo '<a class="btn" href="index.php?screen=list">管理画面へ戻る</a>';
    echo '</div></div>';
    echo '</div></main>';

    renderFoot();
}

/* =========================================================
 * Flash
 * ======================================================= */

function renderFlash(): void
{
    $flash = getFlash();

    if (!$flash) {
        return;
    }

    $class = $flash['type'] === 'success'
        ? 'alert-success'
        : 'alert-danger';

    echo '<div class="alert ' . $class . '">' .
        e($flash['message']) .
        '</div>';
}

/* =========================================================
 * 管理者：一覧
 * ======================================================= */

function renderList(): void
{
    $all = array_map('normalizeStatus', surveys());

    $keyword = trim((string)($_GET['q'] ?? ''));
    $status = (string)($_GET['status'] ?? '');
    $sort = (string)($_GET['sort'] ?? 'updated_desc');

    if ($keyword !== '') {
        $all = array_filter(
            $all,
            fn(array $s): bool =>
                mb_stripos((string)$s['title'], $keyword) !== false
        );
    }

    if (
        $status !== ''
        && in_array($status, ['公開中','下書き','停止','終了'], true)
    ) {
        $all = array_filter(
            $all,
            fn(array $s): bool => ($s['status'] ?? '') === $status
        );
    }

    usort($all, function (array $a, array $b) use ($sort): int {
        $av = match ($sort) {
            'updated_asc', 'updated_desc' =>
                strtotime((string)$a['updated_at']) ?: 0,
            'answers_desc', 'answers_asc' =>
                answerCount((string)$a['id']),
            'start_desc', 'start_asc' =>
                strtotime((string)$a['start_at']) ?: 0,
            default => strtotime((string)$a['updated_at']) ?: 0,
        };

        $bv = match ($sort) {
            'updated_asc', 'updated_desc' =>
                strtotime((string)$b['updated_at']) ?: 0,
            'answers_desc', 'answers_asc' =>
                answerCount((string)$b['id']),
            'start_desc', 'start_asc' =>
                strtotime((string)$b['start_at']) ?: 0,
            default => strtotime((string)$b['updated_at']) ?: 0,
        };

        $desc = str_ends_with($sort, '_desc')
            || $sort === 'updated_desc';

        return $desc ? $bv <=> $av : $av <=> $bv;
    });

    renderHead('アンケート一覧');

    echo '<main class="page">';
    echo '<div class="page-title">';
    echo '<div><h1>アンケート一覧</h1>';
    echo '<p>アンケートの作成・公開・送信・集計を管理します。</p></div>';
    echo '<a class="btn btn-primary" href="index.php?screen=edit">＋ 新規作成</a>';
    echo '</div>';

    renderFlash();

    echo '<div class="card"><div class="card-body">';
    echo '<form method="get" class="searchbar">';
    echo '<input type="hidden" name="screen" value="list">';
    echo '<input type="text" name="q" value="' . e($keyword) .
        '" placeholder="タイトルで検索">';
    echo '<select name="status">';
    echo '<option value="">すべて</option>';

    foreach (['公開中','下書き','停止','終了'] as $s) {
        echo '<option value="' . e($s) . '"' .
            ($status === $s ? ' selected' : '') . '>' .
            e($s) . '</option>';
    }

    echo '</select>';

    echo '<select name="sort">';
    $sorts = [
        'updated_desc' => '更新日：新しい順',
        'updated_asc' => '更新日：古い順',
        'answers_desc' => '回答数：多い順',
        'answers_asc' => '回答数：少ない順',
        'start_desc' => '開始日：新しい順',
        'start_asc' => '開始日：古い順',
    ];

    foreach ($sorts as $key => $label) {
        echo '<option value="' . e($key) . '"' .
            ($sort === $key ? ' selected' : '') . '>' .
            e($label) . '</option>';
    }

    echo '</select>';
    echo '<button class="btn btn-primary" type="submit">検索</button>';
    echo '</form>';
    echo '</div></div>';

    echo '<div class="card"><div class="table-wrap">';

    if (!$all) {
        echo '<div class="empty">アンケートがありません。</div>';
    } else {
        echo '<table><thead><tr>';
        echo '<th>タイトル</th><th>作成日</th><th>更新日</th>';
        echo '<th>アンケート期間</th><th>ステータス</th>';
        echo '<th>回答数</th><th>操作</th>';
        echo '</tr></thead><tbody>';

        foreach ($all as $s) {
            $id = (string)$s['id'];

            echo '<tr>';
            echo '<td><strong>' . e($s['title']) . '</strong></td>';
            echo '<td>' . e($s['created_at']) . '</td>';
            echo '<td>' . e($s['updated_at']) . '</td>';
            echo '<td>' . e($s['start_at']) . '<br>〜 ' .
                e($s['end_at']) . '</td>';
            echo '<td><span class="badge ' .
                statusClass((string)$s['status']) . '">' .
                e($s['status']) . '</span></td>';
            echo '<td>' . answerCount($id) . '</td>';

            echo '<td><div class="actions">';

            echo '<a class="btn btn-sm" href="index.php?screen=edit&id=' .
                e($id) . '">確認・編集</a>';

            echo '<a class="btn btn-sm" href="index.php?screen=preview&id=' .
                e($id) . '">プレビュー</a>';

            echo '<a class="btn btn-sm" href="index.php?screen=analytics&id=' .
                e($id) . '">集計</a>';

            echo '<a class="btn btn-sm" href="index.php?screen=send&id=' .
                e($id) . '">送信</a>';

            echo '<form method="post" style="display:inline">';
            echo '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
            echo '<input type="hidden" name="action" value="duplicate">';
            echo '<input type="hidden" name="id" value="' . e($id) . '">';
            echo '<button class="btn btn-sm" type="submit">複製</button>';
            echo '</form>';

            echo '<form method="post" style="display:inline" ';
            echo 'onsubmit="return confirmAction(\'削除しますか？\')">';
            echo '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
            echo '<input type="hidden" name="action" value="delete">';
            echo '<input type="hidden" name="id" value="' . e($id) . '">';
            echo '<button class="btn btn-sm btn-danger" type="submit">削除</button>';
            echo '</form>';

            echo '</div></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '</div></div>';
    echo '</main>';

    renderFoot();
}

/* =========================================================
 * 管理者：編集
 * ======================================================= */

function renderEdit(string $id = ''): void
{
    $survey = $id !== '' ? findSurvey($id) : null;

    if ($id !== '' && !$survey) {
        failPage('アンケートがありません。', 404);
    }

    if (!$survey) {
        $survey = newSurvey();
    }

    $survey = normalizeStatus($survey);

    renderHead(
        $id === '' ? 'アンケート作成' : 'アンケート編集'
    );

    echo '<main class="page">';

    echo '<div class="page-title">';
    echo '<div><h1>' .
        ($id === '' ? 'アンケート作成' : 'アンケート編集') .
        '</h1>';
    echo '<p>質問・グループ・条件分岐を設定します。</p></div>';

    echo '<div class="actions">';
    echo '<a class="btn" href="index.php?screen=list">キャンセル</a>';

    if ($id !== '') {
        echo '<a class="btn" href="index.php?screen=preview&id=' .
            e($survey['id']) . '">プレビュー</a>';
    }

    echo '<button class="btn btn-primary" type="submit" form="survey-form">';
    echo '保存して一覧へ';
    echo '</button>';
    echo '</div>';
    echo '</div>';

    renderFlash();

    echo '<form id="survey-form" method="post" data-loading="1" ';
    echo 'onsubmit="buildSurveyJson();">';
    echo '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="save_survey">';
    echo '<input type="hidden" name="id" value="' . e($survey['id']) . '">';
    echo '<input type="hidden" name="groups_json" id="groups_json">';

    echo '<div class="card"><div class="card-header">';
    echo '<strong>基本情報</strong>';

    echo '<div class="actions">';
    echo '<span class="badge ' . statusClass((string)$survey['status']) .
        '">' . e($survey['status']) . '</span>';

    /*
     * 終了は手動選択不可。
     */
    if ($survey['status'] !== '終了') {
        echo '<form method="post" style="display:inline" ';
        echo 'onsubmit="return confirmAction(\'状態を変更しますか？\')">';
        echo '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
        echo '<input type="hidden" name="action" value="change_status">';
        echo '<input type="hidden" name="id" value="' . e($survey['id']) . '">';
        echo '<input type="hidden" name="return_screen" value="edit">';
        echo '<input type="hidden" name="return_id" value="' . e($survey['id']) . '">';

        if ($survey['status'] === '下書き') {
            echo '<input type="hidden" name="status" value="公開中">';
            echo '<button class="btn btn-sm btn-success">公開する</button>';
        } elseif ($survey['status'] === '公開中') {
            echo '<input type="hidden" name="status" value="停止">';
            echo '<button class="btn btn-sm btn-warning">停止する</button>';
        } elseif ($survey['status'] === '停止') {
            echo '<input type="hidden" name="status" value="公開中">';
            echo '<button class="btn btn-sm btn-success">再公開する</button>';
        }

        echo '</form>';
    }

    echo '</div>';
    echo '</div><div class="card-body">';

    echo '<div class="form-grid">';

    echo '<div class="form-group full">';
    echo '<label>アンケートタイトル *</label>';
    echo '<input type="text" name="title" maxlength="200" required value="' .
        e($survey['title']) . '">';
    echo '</div>';

    echo '<div class="form-group full">';
    echo '<label>アンケート説明</label>';
    echo '<textarea name="description">' .
        e($survey['description']) .
        '</textarea>';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label>開始日時</label>';
    echo '<input type="datetime-local" name="start_at" value="' .
        e(str_replace(' ', 'T', substr((string)$survey['start_at'], 0, 16))) .
        '">';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label>終了日時</label>';
    echo '<input type="datetime-local" name="end_at" value="' .
        e(str_replace(' ', 'T', substr((string)$survey['end_at'], 0, 16))) .
        '">';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label>質問番号の採番方式</label>';
    echo '<select name="numbering">';
    echo '<option value="global"' .
        (($survey['numbering'] ?? '') === 'global' ? ' selected' : '') .
        '>全体通番：Q1、Q2、Q3...</option>';
    echo '<option value="group"' .
        (($survey['numbering'] ?? '') === 'group' ? ' selected' : '') .
        '>グループ単位：Q1-1、Q1-2...</option>';
    echo '</select>';
    echo '</div>';

    echo '</div>';
    echo '</div></div>';

    echo '<div id="groups-area">';

    foreach ($survey['groups'] as $group) {
        echo '<div class="group-card" data-id="' .
            e($group['id']) . '">';

        echo '<div class="group-head">';
        echo '<span class="drag-handle">☷</span>';
        echo '<input class="group-title" type="text" maxlength="200" value="' .
            e($group['title']) . '">';
        echo '<button type="button" class="btn btn-sm btn-danger" ';
        echo 'onclick="this.closest(\'.group-card\').remove();updateBranches()">削除</button>';
        echo '</div>';

        echo '<div class="questions">';

        foreach ($group['questions'] as $q) {
            echo '<div class="question-card" data-id="' . e($q['id']) . '">';

            echo '<div class="question-head">';
            echo '<span class="drag-handle">☷</span>';
            echo '<span class="question-number">' .
                e($q['number']) . '</span>';
            echo '<button type="button" class="btn btn-sm btn-danger" ';
            echo 'onclick="this.closest(\'.question-card\').remove();updateBranches()">削除</button>';
            echo '</div>';

            echo '<div class="form-grid">';

            echo '<div class="form-group full">';
            echo '<label>質問文</label>';
            echo '<input class="question-text" type="text" maxlength="2000" value="' .
                e($q['text']) . '">';
            echo '</div>';

            echo '<div class="form-group">';
            echo '<label>回答形式</label>';
            echo '<select class="question-type">';
            $types = [
                'single' => '単一選択',
                'multiple' => '複数選択',
                'text' => '自由記述',
            ];

            foreach ($types as $k => $label) {
                echo '<option value="' . e($k) . '"' .
                    (($q['type'] ?? '') === $k ? ' selected' : '') .
                    '>' . e($label) . '</option>';
            }

            echo '</select>';
            echo '</div>';

            echo '<div class="form-group">';
            echo '<label>必須設定</label>';
            echo '<label style="font-weight:400">';
            echo '<input class="question-required" type="checkbox"' .
                (!empty($q['required']) ? ' checked' : '') .
                '> 必須回答';
            echo '</label>';
            echo '</div>';

            echo '<div class="form-group full options">';
            echo '<label>選択肢</label>';

            foreach (($q['options'] ?? []) as $option) {
                echo '<div class="option-row">';
                echo '<input type="text" value="' . e($option) . '">';
                echo '<button type="button" class="btn btn-sm btn-danger" ';
                echo 'onclick="this.parentElement.remove();updateBranches()">削除</button>';
                echo '</div>';
            }

            echo '<button type="button" class="btn btn-sm" onclick="addOption(this)">＋ 選択肢追加</button>';
            echo '</div>';

            echo '<div class="form-group full branch-area">';
            echo '<label>条件分岐</label>';

            if (($q['type'] ?? '') === 'single') {
                foreach (($q['options'] ?? []) as $option) {
                    echo '<label style="font-weight:400">';
                    echo e($option);
                    echo '<select class="branch-select" data-option="' .
                        e($option) . '">';
                    echo '<option value="">次の質問へ</option>';

                    foreach ($survey['groups'] as $g2) {
                        foreach ($g2['questions'] as $target) {
                            if ($target['id'] === $q['id']) {
                                continue;
                            }

                            echo '<option value="' . e($target['id']) . '"' .
                                (($q['branch'][$option] ?? '') === $target['id']
                                    ? ' selected'
                                    : '') . '>' .
                                e($target['number'] . ' ' . $target['text']) .
                                '</option>';
                        }
                    }

                    echo '</select>';
                    echo '</label>';
                }
            }

            echo '</div>';
            echo '</div>';
            echo '</div>';
        }

        echo '</div>';

        echo '<div style="padding:0 14px 14px">';
        echo '<button type="button" class="btn btn-sm" onclick="addQuestion(this)">';
        echo '＋ 質問を追加';
        echo '</button>';
        echo '</div>';

        echo '</div>';
    }

    echo '</div>';

    echo '<div class="actions" style="margin-top:14px">';
    echo '<button type="button" class="btn" onclick="addGroup();updateBranches()">';
    echo '＋ グループを追加';
    echo '</button>';
    echo '</div>';

    echo '</form>';

    /*
     * JSテンプレート
     */
    echo <<<HTML
<template id="group-template">
<div class="group-card" data-id="">
 <div class="group-head">
  <span class="drag-handle">☷</span>
  <input class="group-title" type="text" maxlength="200" value="新しいグループ">
  <button type="button" class="btn btn-sm btn-danger"
   onclick="this.closest('.group-card').remove();updateBranches()">削除</button>
 </div>
 <div class="questions"></div>
 <div style="padding:0 14px 14px">
  <button type="button" class="btn btn-sm"
   onclick="addQuestion(this)">＋ 質問を追加</button>
 </div>
</div>
</template>

<template id="question-template">
<div class="question-card" data-id="">
 <div class="question-head">
  <span class="drag-handle">☷</span>
  <span class="question-number">自動採番</span>
  <button type="button" class="btn btn-sm btn-danger"
   onclick="this.closest('.question-card').remove();updateBranches()">削除</button>
 </div>
 <div class="form-grid">
  <div class="form-group full">
   <label>質問文</label>
   <input class="question-text" type="text" maxlength="2000" value="新しい質問">
  </div>
  <div class="form-group">
   <label>回答形式</label>
   <select class="question-type">
    <option value="single">単一選択</option>
    <option value="multiple">複数選択</option>
    <option value="text">自由記述</option>
   </select>
  </div>
  <div class="form-group">
   <label>必須設定</label>
   <label style="font-weight:400">
    <input class="question-required" type="checkbox" checked> 必須回答
   </label>
  </div>
  <div class="form-group full options">
   <label>選択肢</label>
   <div class="option-row">
    <input type="text" value="はい">
    <button type="button" class="btn btn-sm btn-danger"
     onclick="this.parentElement.remove();updateBranches()">削除</button>
   </div>
   <div class="option-row">
    <input type="text" value="いいえ">
    <button type="button" class="btn btn-sm btn-danger"
     onclick="this.parentElement.remove();updateBranches()">削除</button>
   </div>
   <button type="button" class="btn btn-sm"
    onclick="addOption(this)">＋ 選択肢追加</button>
  </div>
  <div class="form-group full branch-area"></div>
 </div>
</div>
</template>
HTML;

    echo '</main>';

    renderFoot();
}

/* =========================================================
 * プレビュー
 * ======================================================= */

function renderPreview(string $id): void
{
    $survey = findSurvey($id);

    if (!$survey) {
        failPage('アンケートがありません。', 404);
    }

    renderHead('プレビュー');

    echo '<main class="page">';
    echo '<div class="page-title">';
    echo '<div><h1>プレビュー</h1>';
    echo '<p>PC・スマートフォン表示を確認できます。</p></div>';
    echo '<div class="actions">';
    echo '<a class="btn" href="index.php?screen=edit&id=' . e($id) . '">編集へ戻る</a>';
    echo '</div></div>';

    echo '<div class="preview-box">';
    echo '<h1>' . e($survey['title']) . '</h1>';
    echo '<p>' . nl2br(e($survey['description'])) . '</p>';

    foreach ($survey['groups'] as $group) {
        echo '<h2>' . e($group['title']) . '</h2>';

        foreach ($group['questions'] as $q) {
            echo '<div class="answer-question">';
            echo '<h3>' . e($q['number'] . ' ' . $q['text']);

            if ($q['required']) {
                echo ' <span style="color:#dc2626">*</span>';
            }

            echo '</h3>';

            if ($q['type'] === 'text') {
                echo '<textarea disabled></textarea>';
            } elseif ($q['type'] === 'single') {
                foreach ($q['options'] as $option) {
                    echo '<label class="answer-option">';
                    echo '<input type="radio" disabled>';
                    echo e($option);
                    echo '</label>';
                }
            } else {
                foreach ($q['options'] as $option) {
                    echo '<label class="answer-option">';
                    echo '<input type="checkbox" disabled>';
                    echo e($option);
                    echo '</label>';
                }
            }

            if ($q['type'] === 'single' && !empty($q['branch'])) {
                echo '<div class="help">条件分岐設定あり</div>';
            }

            echo '</div>';
        }
    }

    echo '</div>';
    echo '</main>';

    renderFoot();
}

/* =========================================================
 * 回答画面
 * ======================================================= */

function renderAnswer(string $id): void
{
    $survey = findSurvey($id);

    if (!$survey) {
        renderAnswerMessage('アンケートが見つかりません。');
        return;
    }

    $survey = normalizeStatus($survey);

    if ($survey['status'] !== '公開中') {
        renderAnswerMessage('このアンケートは現在回答できません。');
        return;
    }

    $answerData = $_SESSION['answer_' . $id] ?? [];

    renderHead($survey['title'], false);

    echo '<main class="answer-page">';
    echo '<header class="answer-header">';
    echo '<div class="answer-main" style="padding-top:0;padding-bottom:0">';
    echo '<h1 class="answer-title">' . e($survey['title']) . '</h1>';
    echo '<div class="answer-description">' .
        nl2br(e($survey['description'])) .
        '</div>';
    echo '</div>';
    echo '</header>';

    echo '<div class="answer-main">';

    echo '<form method="post">';
    echo '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="answer_confirm">';
    echo '<input type="hidden" name="survey_id" value="' . e($id) . '">';

    foreach ($survey['groups'] as $group) {
        echo '<section class="card">';
        echo '<div class="card-header"><strong>' .
            e($group['title']) . '</strong></div>';
        echo '<div class="card-body">';

        foreach ($group['questions'] as $q) {
            echo '<div class="answer-question">';
            echo '<h3>' .
                e($q['number'] . ' ' . $q['text']);

            if ($q['required']) {
                echo ' <span style="color:#dc2626">*</span>';
            }

            echo '</h3>';

            $value = $answerData[$q['id']] ?? '';

            if ($q['type'] === 'text') {
                echo '<textarea name="answer[' . e($q['id']) .
                    ']" ' . ($q['required'] ? 'required' : '') . '>' .
                    e(is_array($value) ? '' : $value) .
                    '</textarea>';
            } elseif ($q['type'] === 'single') {
                foreach ($q['options'] as $option) {
                    echo '<label class="answer-option">';
                    echo '<input type="radio" name="answer[' .
                        e($q['id']) . ']" value="' . e($option) . '"' .
                        ((string)$value === (string)$option ? ' checked' : '') .
                        ($q['required'] ? ' required' : '') . '>';
                    echo e($option);
                    echo '</label>';
                }
            } else {
                $selected = is_array($value) ? $value : [];

                foreach ($q['options'] as $option) {
                    echo '<label class="answer-option">';
                    echo '<input type="checkbox" name="answer[' .
                        e($q['id']) . '][]" value="' . e($option) . '"' .
                        (in_array($option, $selected, true) ? ' checked' : '') .
                        '>';
                    echo e($option);
                    echo '</label>';
                }
            }

            echo '</div>';
        }

        echo '</div></section>';
    }

    echo '<div class="answer-actions">';
    echo '<button class="btn btn-primary" type="submit">回答を確認する</button>';
    echo '</div>';

    echo '</form>';
    echo '</div></main>';

    renderFoot();
}

function renderAnswerMessage(string $message): void
{
    renderHead('アンケート', false);

    echo '<main class="answer-page">';
    echo '<div class="answer-main">';
    echo '<div class="card"><div class="card-body">';
    echo '<h1>アンケート</h1>';
    echo '<div class="alert alert-warning">' . e($message) . '</div>';
    echo '</div></div>';
    echo '</div></main>';

    renderFoot();
}

/* =========================================================
 * 回答確認
 * ======================================================= */

function renderConfirm(string $id): void
{
    $survey = findSurvey($id);

    if (!$survey) {
        renderAnswerMessage('アンケートが見つかりません。');
        return;
    }

    $data = $_SESSION['answer_' . $id] ?? null;

    if (!is_array($data)) {
        redirect('answer', ['id' => $id]);
    }

    renderHead('回答確認', false);

    echo '<main class="answer-page">';
    echo '<header class="answer-header">';
    echo '<div class="answer-main" style="padding-top:0;padding-bottom:0">';
    echo '<h1 class="answer-title">回答確認</h1>';
    echo '<div class="answer-description">' . e($survey['title']) . '</div>';
    echo '</div></header>';

    echo '<div class="answer-main">';

    foreach ($survey['groups'] as $group) {
        echo '<div class="card"><div class="card-header"><strong>' .
            e($group['title']) . '</strong></div>';
        echo '<div class="card-body">';

        foreach ($group['questions'] as $q) {
            $v = $data[$q['id']] ?? '';

            if (is_array($v)) {
                $v = implode(', ', $v);
            }

            echo '<div style="margin-bottom:18px">';
            echo '<div class="help">' . e($q['number']) . '</div>';
            echo '<strong>' . e($q['text']) . '</strong>';
            echo '<div style="margin-top:6px;white-space:pre-wrap">' .
                e($v) . '</div>';
            echo '</div>';
        }

        echo '</div></div>';
    }

    echo '<div class="actions" style="justify-content:space-between">';
    echo '<a class="btn" href="index.php?screen=answer&id=' . e($id) .
        '">回答を修正する</a>';

    echo '<form method="post">';
    echo '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="answer_submit">';
    echo '<input type="hidden" name="survey_id" value="' . e($id) . '">';
    echo '<button class="btn btn-primary" type="submit" ';
    echo 'onclick="return confirmAction(\'この回答を送信しますか？\')">';
    echo '回答を送信する</button>';
    echo '</form>';
    echo '</div>';

    echo '</div></main>';

    renderFoot();
}

/* =========================================================
 * 回答完了
 * ======================================================= */

function renderComplete(string $id): void
{
    $survey = findSurvey($id);

    renderHead('回答完了', false);

    echo '<main class="answer-page">';
    echo '<div class="answer-main">';
    echo '<div class="card"><div class="card-body" style="text-align:center;padding:50px 24px">';
    echo '<div style="font-size:52px;color:#16a34a">✓</div>';
    echo '<h1>回答ありがとうございました</h1>';
    echo '<p>回答は正常に送信されました。</p>';
    echo '<p class="help">この画面で回答者フローは終了します。</p>';
    echo '</div></div>';
    echo '</div></main>';

    /*
     * 管理者画面への導線を置かない。
     */
    renderFoot();
}

/* =========================================================
 * 送信画面
 * ======================================================= */

function renderSend(string $id): void
{
    $survey = findSurvey($id);

    if (!$survey) {
        failPage('対象アンケートがありません。', 404);
    }

    $customerList = customers();
    $logs = array_reverse(array_filter(
        sendLogs(),
        fn(array $l): bool => ($l['survey_id'] ?? '') === $id
    ));

    $keyword = trim((string)($_GET['q'] ?? ''));

    if ($keyword !== '') {
        $customerList = array_filter(
            $customerList,
            fn(array $c): bool =>
                mb_stripos((string)($c['name'] ?? ''), $keyword) !== false
                || mb_stripos((string)($c['organization'] ?? ''), $keyword) !== false
                || mb_stripos((string)($c['email'] ?? ''), $keyword) !== false
        );
    }

    renderHead('顧客選択・メール送信');

    echo '<main class="page">';
    echo '<div class="page-title">';
    echo '<div><h1>顧客選択・メール送信</h1>';
    echo '<p>対象アンケート：<strong>' .
        e($survey['title']) . '</strong></p></div>';
    echo '<a class="btn" href="index.php?screen=list">一覧へ</a>';
    echo '</div>';

    renderFlash();

    echo '<div class="card"><div class="card-body">';
    echo '<form method="get" class="toolbar">';
    echo '<input type="hidden" name="screen" value="send">';
    echo '<input type="hidden" name="id" value="' . e($id) . '">';
    echo '<input type="text" name="q" value="' . e($keyword) .
        '" placeholder="顧客名・組織名・メールアドレス">';
    echo '<button class="btn" type="submit">検索</button>';
    echo '</form>';
    echo '</div></div>';

    echo '<form method="post" data-loading="1">';
    echo '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="send_mails">';
    echo '<input type="hidden" name="survey_id" value="' . e($id) . '">';

    echo '<div class="card"><div class="card-header">';
    echo '<strong>顧客選択</strong>';
    echo '<span class="help">' . count($customerList) . '件</span>';
    echo '</div><div class="table-wrap">';

    if (!$customerList) {
        echo '<div class="empty">顧客情報がありません。kintoneから同期してください。</div>';
    } else {
        echo '<table><thead><tr>';
        echo '<th></th><th>組織名</th><th>氏名</th>';
        echo '<th>メールアドレス</th><th>部署</th><th>電話</th>';
        echo '</tr></thead><tbody>';

        foreach ($customerList as $c) {
            echo '<tr>';
            echo '<td><input type="checkbox" name="customers[]" value="' .
                e($c['id']) . '"></td>';
            echo '<td>' . e($c['organization']) . '</td>';
            echo '<td>' . e($c['name']) . '</td>';
            echo '<td>' . e($c['email']) . '</td>';
            echo '<td>' . e($c['department']) . '</td>';
            echo '<td>' . e($c['phone']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '</div></div>';

    echo '<div class="card"><div class="card-header"><strong>メール内容</strong></div>';
    echo '<div class="card-body">';
    echo '<div class="form-grid">';

    echo '<div class="form-group full">';
    echo '<label>件名 *</label>';
    echo '<input type="text" name="mail_subject" required ';
    echo 'value="' . e('【アンケート】' . $survey['title']) . '">';
    echo '</div>';

    echo '<div class="form-group full">';
    echo '<label>本文 *</label>';
    echo '<textarea name="mail_body" required>';
    echo e("{顧客名} 様\n\nアンケートへのご協力をお願いいたします。\n\n回答URL：{アンケートURL}");
    echo '</textarea>';
    echo '<div class="help">{顧客名}、{アンケートURL} が利用できます。</div>';
    echo '</div>';

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="btn btn-primary" type="submit">選択した顧客へ一括送信</button>';
    echo '</div>';

    echo '</div></div>';

    echo '</form>';

    echo '<div class="card"><div class="card-header">';
    echo '<strong>送信履歴</strong>';
    echo '</div><div class="table-wrap">';

    if (!$logs) {
        echo '<div class="empty">送信履歴はありません。</div>';
    } else {
        echo '<table><thead><tr>';
        echo '<th>日時</th><th>顧客</th><th>メール</th><th>結果</th>';
        echo '</tr></thead><tbody>';

        foreach ($logs as $log) {
            echo '<tr>';
            echo '<td>' . e($log['created_at']) . '</td>';
            echo '<td>' . e($log['customer_name']) . '</td>';
            echo '<td>' . e($log['email']) . '</td>';
            echo '<td><span class="badge ' .
                ($log['status'] === '送信成功'
                    ? 'badge-success'
                    : 'badge-danger') .
                '">' . e($log['status']) . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '</div></div>';

    echo '</main>';

    renderFoot();
}

/* =========================================================
 * 集計
 * ======================================================= */

function renderAnalytics(string $id): void
{
    $survey = findSurvey($id);

    if (!$survey) {
        failPage('対象アンケートがありません。', 404);
    }

    $allAnswers = array_values(array_filter(
        answers(),
        fn(array $a): bool => ($a['survey_id'] ?? '') === $id
    ));

    $answerCount = count($allAnswers);

    $sentCustomerIds = [];

    foreach (sendLogs() as $log) {
        if (
            ($log['survey_id'] ?? '') === $id
            && ($log['status'] ?? '') === '送信成功'
        ) {
            $sentCustomerIds[(string)$log['customer_id']] = true;
        }
    }

    $targetCount = count($sentCustomerIds);
    $rate = $targetCount > 0
        ? round($answerCount / $targetCount * 100, 1)
        : 0;

    renderHead('回答集計・分析');

    echo '<main class="page">';
    echo '<div class="page-title">';
    echo '<div><h1>回答集計・分析</h1>';
    echo '<p>対象アンケート：<strong>' . e($survey['title']) . '</strong></p>';
    echo '</div>';

    echo '<div class="actions">';
    echo '<a class="btn" href="index.php?screen=csv&id=' . e($id) . '">CSV出力</a>';
    echo '<a class="btn" href="index.php?screen=pdf&id=' . e($id) . '">PDF出力</a>';
    echo '<a class="btn" href="index.php?screen=list">一覧へ</a>';
    echo '</div>';
    echo '</div>';

    renderFlash();

    echo '<div class="stats">';
    $statData = [
        ['送信対象者数', $targetCount],
        ['回答数', $answerCount],
        ['未登録回答数', 0],
        ['未回答数', max(0, $targetCount - $answerCount)],
        ['回答率', $rate . '%'],
    ];

    foreach ($statData as [$label, $value]) {
        echo '<div class="stat">';
        echo '<div class="label">' . e($label) . '</div>';
        echo '<div class="value">' . e((string)$value) . '</div>';
        echo '</div>';
    }

    echo '</div>';

    if ($answerCount === 0) {
        echo '<div class="card"><div class="empty">';
        echo '現在、回答データはありません';
        echo '</div></div>';
        echo '</main>';
        renderFoot();
        return;
    }

    echo '<div class="card"><div class="card-header"><strong>設問別集計</strong></div>';
    echo '<div class="card-body">';

    foreach ($survey['groups'] as $group) {
        echo '<h2>' . e($group['title']) . '</h2>';

        foreach ($group['questions'] as $q) {
            echo '<div style="margin-bottom:24px">';
            echo '<strong>' . e($q['number'] . ' ' . $q['text']) .
                '</strong>';

            $counts = [];

            foreach ($q['options'] ?? [] as $option) {
                $counts[$option] = 0;
            }

            $textCount = 0;

            foreach ($allAnswers as $answer) {
                $v = $answer['answers'][$q['id']] ?? '';

                if ($q['type'] === 'text') {
                    if (trim((string)$v) !== '') {
                        $textCount++;
                    }
                } elseif (is_array($v)) {
                    foreach ($v as $selected) {
                        $counts[(string)$selected] =
                            ($counts[(string)$selected] ?? 0) + 1;
                    }
                } else {
                    if ((string)$v !== '') {
                        $counts[(string)$v] =
                            ($counts[(string)$v] ?? 0) + 1;
                    }
                }
            }

            if ($q['type'] === 'text') {
                echo '<div class="help">回答あり：' .
                    e((string)$textCount) . '件</div>';
            } else {
                foreach ($counts as $option => $count) {
                    $percent = $answerCount > 0
                        ? round($count / $answerCount * 100, 1)
                        : 0;

                    echo '<div style="margin-top:12px">';
                    echo '<div style="display:flex;justify-content:space-between">';
                    echo '<span>' . e($option) . '</span>';
                    echo '<span>' . e((string)$count) . '件 / ' .
                        e((string)$percent) . '%</span>';
                    echo '</div>';
                    echo '<div style="height:8px;background:#e2e8f0;border-radius:99px;margin-top:4px">';
                    echo '<div style="height:8px;width:' .
                        min(100, $percent) .
                        '%;background:#2563eb;border-radius:99px"></div>';
                    echo '</div>';
                    echo '</div>';
                }
            }

            echo '</div>';
        }
    }

    echo '</div></div>';

    echo '<div class="card"><div class="card-header">';
    echo '<strong>個別回答</strong>';
    echo '</div><div class="card-body">';

    foreach ($allAnswers as $answer) {
        echo '<div style="border-bottom:1px solid #dbe2ea;padding:15px 0">';
        echo '<div class="help">回答日時：' .
            e($answer['created_at']) . '</div>';

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $q) {
                $v = $answer['answers'][$q['id']] ?? '';

                if (is_array($v)) {
                    $v = implode(', ', $v);
                }

                echo '<div style="margin-top:8px">';
                echo '<strong>' . e($q['number']) . '</strong> ';
                echo e($q['text']) . '<br>';
                echo '<span style="white-space:pre-wrap">' .
                    e($v) . '</span>';
                echo '</div>';
            }
        }

        echo '</div>';
    }

    echo '</div></div>';

    echo '</main>';

    renderFoot();
}

/* =========================================================
 * kintone設定
 * ======================================================= */

function renderKintone(): void
{
    $cfg = appSettings()['kintone'];
    $fields = $cfg['fields'] ?? [];
    $mapping = $cfg['mapping'] ?? [];

    renderHead('kintone設定');

    echo '<main class="page">';
    echo '<div class="page-title">';
    echo '<div><h1>kintone設定</h1>';
    echo '<p>顧客情報の取得元を設定します。</p></div>';
    echo '</div>';

    renderFlash();

    echo '<div class="card"><div class="card-header"><strong>接続設定</strong></div>';
    echo '<div class="card-body">';

    echo '<form method="post" data-loading="1">';
    echo '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="save_kintone">';

    echo '<div class="form-grid">';

    echo '<div class="form-group full">';
    echo '<label>サブドメイン *</label>';
    echo '<input type="text" name="k_subdomain" required value="' .
        e($cfg['subdomain']) . '" placeholder="xxxx.cybozu.com">';
    echo '<div class="help">https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx を許容</div>';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label>顧客管理アプリID *</label>';
    echo '<input type="number" name="k_app_id" min="1" required value="' .
        e((string)$cfg['app_id']) . '">';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label>ログイン名 *</label>';
    echo '<input type="text" name="k_username" value="' .
        e($cfg['username']) . '" required>';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label>パスワード</label>';
    echo '<div style="display:flex;gap:8px">';
    echo '<input id="k_password" type="password" name="k_password" ';
    echo 'placeholder="' . (!empty($cfg['password']) ? '設定済み（変更時のみ入力）' : '') . '">';
    echo '<button type="button" class="btn" onclick="togglePassword(\'k_password\')">表示</button>';
    echo '</div>';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label>Proxy</label>';
    echo '<input type="text" name="k_proxy" value="' .
        e($cfg['proxy']) . '" placeholder="host:port">';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label>SSL証明書検証</label>';
    echo '<label style="font-weight:400">';
    echo '<input type="checkbox" name="k_verify_ssl"' .
        (!empty($cfg['verify_ssl']) ? ' checked' : '') .
        '> 有効';
    echo '</label>';
    echo '<div class="help">POCでは無効を許容します。</div>';
    echo '</div>';

    echo '</div>';

    echo '<div class="actions" style="margin-top:18px">';
    echo '<button class="btn btn-primary" type="submit">設定保存</button>';
    echo '</div>';

    echo '</form>';

    echo '<hr style="border:0;border-top:1px solid #dbe2ea;margin:24px 0">';

    echo '<div class="actions">';

    echo '<form method="post" data-loading="1">';
    echo '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="test_kintone">';
    echo '<button class="btn" type="submit">接続テスト</button>';
    echo '</form>';

    echo '<form method="post" data-loading="1">';
    echo '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="fetch_kintone_fields">';
    echo '<button class="btn" type="submit">項目一覧を再取得</button>';
    echo '</form>';

    echo '<form method="post" data-loading="1">';
    echo '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="sync_customers">';
    echo '<button class="btn btn-success" type="submit">顧客情報を同期</button>';
    echo '</form>';

    echo '</div>';
    echo '</div></div>';

    echo '<div class="card"><div class="card-header">';
    echo '<strong>顧客情報マッピング</strong>';
    echo '</div><div class="card-body">';

    if (!$fields) {
        echo '<div class="alert alert-warning">';
        echo '項目一覧がありません。「項目一覧を再取得」を実行してください。';
        echo '</div>';
    }

    echo '<form method="post">';
    echo '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="save_mapping">';

    echo '<div class="form-grid">';

    $mapFields = [
        'map_organization' => ['組織名', 'organization'],
        'map_name' => ['氏名', 'name'],
        'map_email' => ['メールアドレス', 'email'],
        'map_department' => ['部署名', 'department'],
        'map_phone' => ['電話番号', 'phone'],
    ];

    foreach ($mapFields as $name => [$label, $key]) {
        echo '<div class="form-group">';
        echo '<label>' . e($label) .
            ($key === 'name' || $key === 'email' ? ' *' : '') .
            '</label>';
        echo '<select name="' . e($name) . '">';
        echo '<option value="">-- 選択 --</option>';

        foreach ($fields as $code => $field) {
            $type = $field['type'] ?? '';

            echo '<option value="' . e($code) . '"' .
                (($mapping[$key] ?? '') === $code ? ' selected' : '') .
                '>' .
                e($code . ' / ' . ($field['label'] ?? '') . ' / ' . $type) .
                '</option>';
        }

        echo '</select>';
        echo '</div>';
    }

    echo '<div class="form-group full">';
    echo '<label>住所（複数選択可）</label>';

    foreach ($fields as $code => $field) {
        $checked = in_array(
            $code,
            $mapping['address'] ?? [],
            true
        );

        echo '<label style="font-weight:400;margin-right:16px;display:inline-block">';
        echo '<input type="checkbox" name="map_address[]" value="' .
            e($code) . '"' . ($checked ? ' checked' : '') . '>';
        echo e($code . ' / ' . ($field['label'] ?? ''));
        echo '</label>';
    }

    echo '</div>';

    echo '</div>';

    echo '<div class="actions" style="margin-top:18px">';
    echo '<button class="btn btn-primary" type="submit">マッピング保存</button>';
    echo '</div>';

    echo '</form>';
    echo '</div></div>';

    echo '</main>';

    renderFoot();
}

/* =========================================================
 * メール設定
 * ======================================================= */

function renderMail(): void
{
    $cfg = appSettings()['smtp'];

    renderHead('メールサーバ設定');

    echo '<main class="page">';
    echo '<div class="page-title">';
    echo '<div><h1>メールサーバ設定</h1>';
    echo '<p>SMTPサーバへの接続・認証・メール送信を設定します。</p></div>';
    echo '<span class="badge ' .
        ($cfg['status'] === '接続確認済み'
            ? 'badge-success'
            : ($cfg['status'] === '接続できません'
                ? 'badge-danger'
                : 'badge-draft')) .
        '">' . e($cfg['status']) . '</span>';
    echo '</div>';

    renderFlash();

    echo '<div class="card"><div class="card-body">';

    echo '<form method="post" data-loading="1">';
    echo '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="save_smtp">';

    echo '<div class="form-grid">';

    echo '<div class="form-group">';
    echo '<label>SMTPサーバ *</label>';
    echo '<input type="text" name="smtp_server" required value="' .
        e($cfg['server']) . '">';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label>SMTPポート *</label>';
    echo '<input type="number" name="smtp_port" min="1" max="65535" required value="' .
        e((string)$cfg['port']) . '">';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label>暗号化方式</label>';
    echo '<select name="smtp_encryption">';

    foreach ([
        'ssl' => 'SSL',
        'tls' => 'TLS',
        'none' => 'なし',
    ] as $key => $label) {
        echo '<option value="' . e($key) . '"' .
            (($cfg['encryption'] ?? '') === $key ? ' selected' : '') .
            '>' . e($label) . '</option>';
    }

    echo '</select>';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label>SMTP認証</label>';
    echo '<label style="font-weight:400">';
    echo '<input type="checkbox" name="smtp_auth"' .
        (!empty($cfg['auth']) ? ' checked' : '') .
        '> 認証を使用';
    echo '</label>';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label>SMTPユーザー名</label>';
    echo '<input type="text" name="smtp_username" value="' .
        e($cfg['username']) . '">';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label>SMTPパスワード</label>';
    echo '<div style="display:flex;gap:8px">';
    echo '<input id="smtp_password" type="password" name="smtp_password" ';
    echo 'placeholder="' .
        (!empty($cfg['password']) ? '設定済み（変更時のみ入力）' : '') .
        '">';
    echo '<button type="button" class="btn" onclick="togglePassword(\'smtp_password\')">表示</button>';
    echo '</div>';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label>送信元メールアドレス *</label>';
    echo '<input type="email" name="smtp_from_email" required value="' .
        e($cfg['from_email']) . '">';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label>送信元名</label>';
    echo '<input type="text" name="smtp_from_name" value="' .
        e($cfg['from_name']) . '">';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label>返信先メールアドレス</label>';
    echo '<input type="email" name="smtp_reply_to" value="' .
        e($cfg['reply_to']) . '">';
    echo '</div>';

    echo '</div>';

    echo '<div class="actions" style="margin-top:18px">';
    echo '<button class="btn btn-primary" type="submit">設定保存</button>';
    echo '</div>';

    echo '</form>';

    echo '<hr style="border:0;border-top:1px solid #dbe2ea;margin:24px 0">';

    echo '<div class="actions">';

    echo '<form method="post" data-loading="1">';
    echo '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="test_smtp">';
    echo '<button class="btn" type="submit">接続テスト</button>';
    echo '</form>';

    echo '</div>';

    echo '<div style="margin-top:20px">';
    echo '<form method="post" data-loading="1">';
    echo '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="send_test_mail">';
    echo '<div class="form-grid">';
    echo '<div class="form-group">';
    echo '<label>テストメール宛先</label>';
    echo '<input type="email" name="test_mail_to" required>';
    echo '</div>';
    echo '<div class="form-group" style="justify-content:end">';
    echo '<button class="btn btn-success" type="submit">テストメール送信</button>';
    echo '</div>';
    echo '</div>';
    echo '</form>';
    echo '</div>';

    echo '</div></div>';
    echo '</main>';

    renderFoot();
}

/* =========================================================
 * 画面ルーティング
 * ======================================================= */

try {
    switch ($screen) {

        /* 管理者 */
        case 'list':
            renderList();
            break;

        case 'edit':
            renderEdit((string)($_GET['id'] ?? ''));
            break;

        case 'preview':
            $id = (string)($_GET['id'] ?? '');

            if (!validSurveyId($id)) {
                failPage('対象アンケートが指定されていません。', 400);
            }

            renderPreview($id);
            break;

        case 'send':
            $id = (string)($_GET['id'] ?? '');

            if (!validSurveyId($id)) {
                failPage('対象アンケートが指定されていません。', 400);
            }

            renderSend($id);
            break;

        case 'analytics':
            $id = (string)($_GET['id'] ?? '');

            if (!validSurveyId($id)) {
                failPage('対象アンケートが指定されていません。', 400);
            }

            renderAnalytics($id);
            break;

        case 'kintone':
            renderKintone();
            break;

        case 'mail':
            renderMail();
            break;

        /* 回答者 */
        case 'answer':
            $id = (string)($_GET['id'] ?? '');

            if (!validSurveyId($id)) {
                renderAnswerMessage('アンケートURLが不正です。');
                break;
            }

            renderAnswer($id);
            break;

        case 'confirm':
            $id = (string)($_GET['id'] ?? '');

            if (!validSurveyId($id)) {
                renderAnswerMessage('アンケートURLが不正です。');
                break;
            }

            renderConfirm($id);
            break;

        case 'complete':
            $id = (string)($_GET['id'] ?? '');

            if (!validSurveyId($id)) {
                renderAnswerMessage('アンケートURLが不正です。');
                break;
            }

            renderComplete($id);
            break;

        default:
            redirect('list');
    }

} catch (Throwable $ex) {
    /*
     * 秘密情報・内部パス・例外スタック等を表示しない。
     */
    renderErrorPage(
        'システムエラーが発生しました。設定またはシステム管理者へ確認してください。',
        500
    );
}
?>

<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * 業務要件・実装要件に基づく単一 index.php
 *
 * - Apache 2.4
 * - PHP 8.5
 * - DBなし
 * - PHP cURLなし
 * - PHP mail()なし
 * - 管理者認証なし（POC）
 * - サーバー側ファイル保存
 * - kintone REST API 実接続
 * - SMTP 実接続
 * - Sodium secretbox
 * - CSRF保護
 * - アプリ専用セッションCookie
 */

const APP_NAME = 'アンケート管理システム';
const DATA_DIR_NAME = '.survey-data';
const SECRET_RELATIVE = '.secrets/アンケートアプリ/secret.key';
const SESSION_NAME = 'SURVEY_APP_SESSION';
const SESSION_COOKIE_PATH = '/';
const SESSION_LIFETIME = 0;

date_default_timezone_set('Asia/Tokyo');

/* =========================================================
 * 基本
 * ======================================================= */

function e(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function uuid(): string
{
    return bin2hex(random_bytes(16));
}

function isHttps(): bool
{
    return (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    );
}

/**
 * URLパスをSCRIPT_NAMEのdirnameから生成しない。
 *
 * 日本語を含む物理パスでは、PHPが認識するパスと
 * ブラウザが保持するCookie Pathの対応に依存しない。
 *
 * アプリのセッションCookieは専用Cookie名を持つため、
 * Path=/でも他アプリとのCookie名衝突を起こさない。
 */
function startApplicationSession(): void
{
    $secure = isHttps();

    session_name(SESSION_NAME);

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => SESSION_COOKIE_PATH,
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (!session_start()) {
            throw new RuntimeException(
                'セッションを開始できませんでした。'
            );
        }
    }
}

startApplicationSession();

/* =========================================================
 * 保存領域
 * ======================================================= */

$appDir = __DIR__;
$parentDir = dirname($appDir);

/*
 * Web公開領域外を優先。
 */
$dataDir = $parentDir . DIRECTORY_SEPARATOR . DATA_DIR_NAME;

if (!is_dir($dataDir)) {
    if (!@mkdir($dataDir, 0700, true)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

if (!is_writable($dataDir)) {
    http_response_code(500);
    exit('データ保存領域へ書き込めません。');
}

/* =========================================================
 * JSON保存
 * ======================================================= */

function dataFile(string $name): string
{
    global $dataDir;

    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
        throw new InvalidArgumentException(
            'データファイル名が不正です。'
        );
    }

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

    $decoded = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException(
            '保存データを読み込めませんでした。'
        );
    }

    return $decoded;
}

function writeJson(string $name, mixed $data): void
{
    $file = dataFile($name);

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
        | JSON_THROW_ON_ERROR
    );

    $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';

    $fp = @fopen($tmp, 'wb');

    if ($fp === false) {
        throw new RuntimeException(
            '一時ファイルを作成できません。'
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException(
                '保存ファイルをロックできません。'
            );
        }

        if (fwrite($fp, $json) === false) {
            throw new RuntimeException(
                'データを書き込めません。'
            );
        }

        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    @chmod($tmp, 0600);

    if (!@rename($tmp, $file)) {
        @unlink($tmp);

        throw new RuntimeException(
            '保存ファイルを更新できません。'
        );
    }

    @chmod($file, 0600);
}

function appendJsonRecord(string $name, array $record): void
{
    $file = dataFile($name);

    $fp = @fopen($file, 'c+');

    if ($fp === false) {
        throw new RuntimeException(
            '保存ファイルを開けません。'
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException(
                '保存ファイルをロックできません。'
            );
        }

        $raw = stream_get_contents($fp);

        if ($raw === false || trim($raw) === '') {
            $records = [];
        } else {
            $records = json_decode($raw, true);

            if (!is_array($records)) {
                throw new RuntimeException(
                    '保存データの形式が不正です。'
                );
            }
        }

        $records[] = $record;

        $json = json_encode(
            $records,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT
            | JSON_THROW_ON_ERROR
        );

        rewind($fp);
        ftruncate($fp, 0);

        if (fwrite($fp, $json) === false) {
            throw new RuntimeException(
                'データを書き込めません。'
            );
        }

        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    @chmod($file, 0600);
}

/* =========================================================
 * データ
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

function defaultSettings(): array
{
    return [
        'kintone' => [
            'subdomain' => '',
            'app_id' => '',
            'username' => '',
            'password' => '',
            'proxy' => '',
            'verify_ssl' => false,
            'fields' => [],
            'mapping' => [
                'organization' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
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
    ];
}

function appSettings(): array
{
    $settings = readJson('settings', defaultSettings());

    if (!is_array($settings)) {
        return defaultSettings();
    }

    return array_replace_recursive(
        defaultSettings(),
        $settings
    );
}

/* =========================================================
 * CSRF
 * ======================================================= */

function csrfToken(): string
{
    if (
        !isset($_SESSION['_csrf'])
        || !is_string($_SESSION['_csrf'])
        || strlen($_SESSION['_csrf']) !== 64
    ) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

/**
 * すべてのPOST処理の最初に実行する。
 *
 * セッションIDそのものをURLやフォームへ埋め込まない。
 */
function verifyCsrf(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException(
            'セッションを開始できません。'
        );
    }

    $sessionToken = $_SESSION['_csrf'] ?? null;
    $requestToken = $_POST['_csrf'] ?? null;

    if (
        !is_string($sessionToken)
        || !is_string($requestToken)
        || $sessionToken === ''
        || $requestToken === ''
        || !hash_equals($sessionToken, $requestToken)
    ) {
        throw new InvalidArgumentException(
            'セッションの有効期限が切れたか、不正なリクエストです。'
        );
    }
}

/* =========================================================
 * Flash
 * ======================================================= */

function flash(string $type, string $message): void
{
    $_SESSION['_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function getFlash(): ?array
{
    $flash = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);

    return is_array($flash) ? $flash : null;
}

/* =========================================================
 * Redirect
 * ======================================================= */

function applicationPath(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');

    /*
     * Locationにはユーザー入力を使用しない。
     * SCRIPT_NAMEはサーバーが決定する値のみ使用する。
     */
    return str_replace(
        '\\',
        '/',
        dirname($script)
    );
}

function redirect(string $screen, array $params = []): never
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

    $query = http_build_query(
        array_merge(
            ['screen' => $screen],
            $params
        ),
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    /*
     * 業務処理を完了してからのみ呼び出す。
     */
    header(
        'Location: index.php?' . $query,
        true,
        303
    );

    exit;
}

/* =========================================================
 * エラー
 * ======================================================= */

function renderErrorPage(
    string $message,
    int $status = 400
): never {
    http_response_code($status);

    echo '<!doctype html>';
    echo '<html lang="ja"><head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . e(APP_NAME) . '</title>';
    echo '<style>
        body{
            margin:0;
            background:#f8fafc;
            color:#0f172a;
            font-family:-apple-system,BlinkMacSystemFont,
                "Segoe UI","Noto Sans JP",sans-serif;
        }
        .error{
            max-width:680px;
            margin:80px auto;
            padding:0 20px;
        }
        .card{
            background:#fff;
            border:1px solid #dbe2ea;
            border-radius:12px;
            padding:28px;
            box-shadow:0 4px 16px rgba(15,23,42,.06);
        }
        h1{font-size:22px;margin-top:0}
        .message{
            background:#fef2f2;
            border:1px solid #fecaca;
            color:#991b1b;
            border-radius:8px;
            padding:14px;
        }
    </style>';
    echo '</head><body>';
    echo '<main class="error">';
    echo '<div class="card">';
    echo '<h1>処理できませんでした</h1>';
    echo '<div class="message">' . e($message) . '</div>';
    echo '</div>';
    echo '</main>';
    echo '</body></html>';

    exit;
}

/* =========================================================
 * 入力検証
 * ======================================================= */

function requirePostString(
    string $name,
    int $max = 5000
): string {
    $value = $_POST[$name] ?? null;

    if (!is_string($value)) {
        throw new InvalidArgumentException(
            $name . 'は必須です。'
        );
    }

    $value = trim($value);

    if ($value === '') {
        throw new InvalidArgumentException(
            $name . 'は必須です。'
        );
    }

    if (mb_strlen($value) > $max) {
        throw new InvalidArgumentException(
            $name . 'が長すぎます。'
        );
    }

    return $value;
}

function optionalPostString(
    string $name,
    int $max = 5000
): string {
    $value = $_POST[$name] ?? '';

    if (!is_string($value)) {
        return '';
    }

    $value = trim($value);

    if (mb_strlen($value) > $max) {
        throw new InvalidArgumentException(
            $name . 'が長すぎます。'
        );
    }

    return $value;
}

function validEmail(string $email): bool
{
    return filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    ) !== false;
}

function validSurveyId(string $id): bool
{
    return (bool)preg_match(
        '/^[a-f0-9]{32}$/',
        $id
    );
}

/* =========================================================
 * 暗号化
 * ======================================================= */

function secretKeyPath(): string
{
    global $appDir;

    /*
     * Web公開領域外を第一候補。
     * 自動生成は禁止。
     */
    return dirname($appDir)
        . DIRECTORY_SEPARATOR
        . SECRET_RELATIVE;
}

function encryptionKey(): string
{
    $path = secretKeyPath();

    if (!is_file($path)) {
        throw new RuntimeException(
            '暗号鍵が存在しません。'
        );
    }

    $key = @file_get_contents($path);

    if (
        $key === false
        || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES
    ) {
        throw new RuntimeException(
            '暗号鍵の形式が不正です。'
        );
    }

    return $key;
}

function encryptSecret(string $plain): string
{
    $key = encryptionKey();

    $nonce = random_bytes(
        SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
    );

    $cipher = sodium_crypto_secretbox(
        $plain,
        $nonce,
        $key
    );

    return 'ENC:v1:'
        . base64_encode($nonce)
        . ':'
        . base64_encode($cipher);
}

function decryptSecret(string $value): string
{
    $parts = explode(':', $value, 4);

    if (
        count($parts) !== 4
        || $parts[0] !== 'ENC'
        || $parts[1] !== 'v1'
    ) {
        throw new RuntimeException(
            '暗号化データの形式が不正です。'
        );
    }

    $nonce = base64_decode(
        $parts[2],
        true
    );

    $cipher = base64_decode(
        $parts[3],
        true
    );

    if (
        $nonce === false
        || $cipher === false
        || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
    ) {
        throw new RuntimeException(
            '暗号化データを復号できません。'
        );
    }

    $plain = sodium_crypto_secretbox_open(
        $cipher,
        $nonce,
        encryptionKey()
    );

    if ($plain === false) {
        throw new RuntimeException(
            '秘密情報を復号できません。'
        );
    }

    return $plain;
}

/* =========================================================
 * アンケート
 * ======================================================= */

function findSurvey(string $id): ?array
{
    foreach (surveys() as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function recalcNumbers(array &$survey): void
{
    $global = 0;

    foreach ($survey['groups'] as $gi => &$group) {
        $group['order'] = $gi + 1;

        foreach (
            $group['questions']
            as $qi => &$question
        ) {
            $global++;

            if (
                ($survey['numbering'] ?? 'global')
                === 'group'
            ) {
                $question['number'] =
                    'Q'
                    . ($gi + 1)
                    . '-'
                    . ($qi + 1);
            } else {
                $question['number'] =
                    'Q' . $global;
            }

            $question['order'] = $qi + 1;

            if (
                ($question['type'] ?? '')
                !== 'single'
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
        'options' => [
            'はい',
            'いいえ',
        ],
        'branch' => [],
    ];
}

function newGroup(): array
{
    return [
        'id' => uuid(),
        'order' => 1,
        'title' => '新しいグループ',
        'questions' => [
            newQuestion(),
        ],
    ];
}

function newSurvey(): array
{
    $survey = [
        'id' => uuid(),
        'title' => '',
        'description' => '',
        'start_at' => date('Y-m-d\TH:i'),
        'end_at' => date(
            'Y-m-d\TH:i',
            strtotime('+30 days')
        ),
        'numbering' => 'global',
        'status' => '下書き',
        'created_at' => now(),
        'updated_at' => now(),
        'groups' => [
            newGroup(),
        ],
    ];

    recalcNumbers($survey);

    return $survey;
}

function saveSurvey(array $survey): void
{
    $all = surveys();
    $found = false;

    foreach ($all as $index => $item) {
        if (
            ($item['id'] ?? '')
            === ($survey['id'] ?? '')
        ) {
            $all[$index] = $survey;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $all[] = $survey;
    }

    writeJson('surveys', array_values($all));
}

function deleteSurvey(string $id): void
{
    $all = array_values(
        array_filter(
            surveys(),
            static fn(array $survey): bool =>
                ($survey['id'] ?? '') !== $id
        )
    );

    writeJson('surveys', $all);
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

    foreach ($all as $index => $survey) {
        $normalized = normalizeStatus($survey);

        if ($normalized !== $survey) {
            $all[$index] = $normalized;
            $changed = true;
        }
    }

    if ($changed) {
        writeJson('surveys', $all);
    }
}

function canTransition(
    string $from,
    string $to
): bool {
    return match ($from) {
        '下書き' => $to === '公開中',
        '公開中' => $to === '停止',
        '停止' => $to === '公開中',
        default => false,
    };
}

/* =========================================================
 * 回答
 * ======================================================= */

function answerCount(string $surveyId): int
{
    return count(
        array_filter(
            answers(),
            static fn(array $answer): bool =>
                ($answer['survey_id'] ?? '')
                === $surveyId
        )
    );
}

function flattenQuestions(array $survey): array
{
    $result = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $result[] = $question;
        }
    }

    return $result;
}

/**
 * 条件分岐：
 *
 * 単一選択質問の選択肢ごとに、
 * 次に表示する質問IDを指定する。
 *
 * none は以降を表示しない。
 */
function calculateVisibleQuestions(
    array $survey,
    array $answers
): array {
    $questions = flattenQuestions($survey);
    $visible = [];

    $skipUntil = null;

    foreach ($questions as $index => $question) {
        if (
            $skipUntil !== null
            && $question['id'] !== $skipUntil
        ) {
            continue;
        }

        $visible[] = $question;

        $branch = $question['branch'] ?? [];

        if (
            ($question['type'] ?? '')
            !== 'single'
        ) {
            continue;
        }

        $answer = $answers[$question['id']] ?? null;

        if (!is_string($answer)) {
            continue;
        }

        if (!array_key_exists($answer, $branch)) {
            continue;
        }

        $target = (string)$branch[$answer];

        if ($target === 'none') {
            break;
        }

        $targetIndex = null;

        foreach (
            $questions as $targetPosition => $candidate
        ) {
            if (
                ($candidate['id'] ?? '')
                === $target
            ) {
                $targetIndex = $targetPosition;
                break;
            }
        }

        if (
            $targetIndex !== null
            && $targetIndex > $index
        ) {
            $skipUntil = $target;
        }
    }

    /*
     * 上記の単純な分岐だけでは複数分岐が重なるため、
     * 実際の回答画面ではサーバー側でも
     * visible questionを再計算して検証する。
     */

    return $visible;
}

/* =========================================================
 * HTTP通信
 * PHP cURLは使用しない
 * ======================================================= */

function httpRequest(
    string $url,
    array $headers = [],
    ?string $body = null,
    string $method = 'GET',
    bool $verifySsl = true,
    string $proxy = ''
): array {
    if (
        !preg_match(
            '#^https://#i',
            $url
        )
    ) {
        throw new InvalidArgumentException(
            'HTTPS URLのみ許可されています。'
        );
    }

    $headerText = '';

    foreach ($headers as $name => $value) {
        $headerText .=
            $name . ': ' . $value . "\r\n";
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => $headerText,
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 20,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => [
            'verify_peer' => $verifySsl,
            'verify_peer_name' => $verifySsl,
            'allow_self_signed' => !$verifySsl,
        ],
    ];

    if ($proxy !== '') {
        if (
            !preg_match(
                '/^[^:\s]+:\d{1,5}$/',
                $proxy
            )
        ) {
            throw new InvalidArgumentException(
                'Proxyはhost:port形式で指定してください。'
            );
        }

        $options['http']['proxy'] =
            'tcp://' . $proxy;

        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $error = null;

    set_error_handler(
        static function (
            int $severity,
            string $message
        ) use (&$error): bool {
            $error = $message;
            return true;
        }
    );

    try {
        $response = file_get_contents(
            $url,
            false,
            $context
        );
    } finally {
        restore_error_handler();
    }

    $headersReceived =
        $http_response_header ?? [];

    $status = 0;

    if (
        isset($headersReceived[0])
        && preg_match(
            '#HTTP/\S+\s+(\d{3})#',
            $headersReceived[0],
            $match
        )
    ) {
        $status = (int)$match[1];
    }

    if ($response === false) {
        return [
            'ok' => false,
            'status' => $status,
            'body' => '',
            'headers' => $headersReceived,
            'error' =>
                $error
                ?: 'レスポンスを取得できませんでした。',
        ];
    }

    /*
     * 2xxのみ成功。
     * 3xxは絶対に成功扱いしない。
     */
    return [
        'ok' => (
            $status >= 200
            && $status < 300
        ),
        'status' => $status,
        'body' => $response,
        'headers' => $headersReceived,
        'error' => null,
    ];
}

/* =========================================================
 * kintone
 * ======================================================= */

function normalizeKintoneHost(
    string $input
): string {
    $input = trim($input);

    if ($input === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインは必須です。'
        );
    }

    if (
        !preg_match(
            '#^https?://#i',
            $input
        )
    ) {
        $input = 'https://' . $input;
    }

    $parsed = parse_url($input);

    if (
        !is_array($parsed)
        || empty($parsed['host'])
    ) {
        throw new InvalidArgumentException(
            'kintoneサブドメインが不正です。'
        );
    }

    $host = strtolower(
        (string)$parsed['host']
    );

    if (
        !preg_match(
            '/^[a-z0-9][a-z0-9.-]*$/',
            $host
        )
    ) {
        throw new InvalidArgumentException(
            'kintoneホスト名が不正です。'
        );
    }

    return 'https://' . $host;
}

function kintoneSettings(): array
{
    return appSettings()['kintone'];
}

function kintoneAuth(
    array $config
): string {
    if (
        empty($config['username'])
        || empty($config['password'])
    ) {
        throw new RuntimeException(
            'kintoneログイン情報が未設定です。'
        );
    }

    $password = decryptSecret(
        (string)$config['password']
    );

    return base64_encode(
        (string)$config['username']
        . ':'
        . $password
    );
}

function kintoneRequest(
    string $path,
    string $method = 'GET',
    ?string $body = null
): array {
    $config = kintoneSettings();

    $host = normalizeKintoneHost(
        (string)$config['subdomain']
    );

    $headers = [
        'X-Cybozu-Authorization' =>
            kintoneAuth($config),
        'Accept' =>
            'application/json',
    ];

    if ($body !== null) {
        $headers['Content-Type'] =
            'application/json';
    }

    return httpRequest(
        $host . $path,
        $headers,
        $body,
        $method,
        (bool)$config['verify_ssl'],
        (string)$config['proxy']
    );
}

function kintoneError(
    array $response
): string {
    $body = (string)(
        $response['body'] ?? ''
    );

    if ($body !== '') {
        $json = json_decode(
            $body,
            true
        );

        if (is_array($json)) {
            $code =
                (string)($json['code'] ?? '');

            $message =
                (string)($json['message'] ?? '');

            if (
                $code !== ''
                || $message !== ''
            ) {
                return trim(
                    $code . ' ' . $message
                );
            }
        }
    }

    return match (
        (int)($response['status'] ?? 0)
    ) {
        301, 302, 303, 307, 308 =>
            'kintoneからリダイレクトが返されました。成功扱いにはしません。',
        401, 403 =>
            'kintoneの認証に失敗しました。',
        404 =>
            '指定されたkintoneアプリが見つかりません。',
        408 =>
            'kintoneへの接続がタイムアウトしました。',
        default =>
            (string)($response['error'] ?? '')
            ?: 'kintone通信に失敗しました。',
    };
}

/* =========================================================
 * POSTルーティング
 *
 * 重要：
 *
 * 1. POST受信
 * 2. CSRF確認
 * 3. 入力検証
 * 4. 業務処理
 * 5. 保存
 * 6. 結果確定
 * 7. redirect
 *
 * の順序を厳守する。
 * ======================================================= */

$screen = (string)(
    $_GET['screen'] ?? 'list'
);

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {
    try {
        /*
         * POSTの最初でCSRF検証。
         *
         * ここで失敗した場合は業務処理を実行しない。
         */
        verifyCsrf();

        $action = (string)(
            $_POST['action'] ?? ''
        );

        switch ($action) {

            /* -----------------------------------------
             * kintone設定保存
             * --------------------------------------- */
            case 'save_kintone':
                $settings = appSettings();

                $subdomain =
                    normalizeKintoneHost(
                        requirePostString(
                            'k_subdomain',
                            255
                        )
                    );

                $appId = filter_var(
                    $_POST['k_app_id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                if (
                    $appId === false
                    || $appId < 1
                ) {
                    throw new InvalidArgumentException(
                        'kintoneアプリIDが不正です。'
                    );
                }

                $username =
                    requirePostString(
                        'k_username',
                        320
                    );

                $password =
                    optionalPostString(
                        'k_password',
                        1000
                    );

                $proxy =
                    optionalPostString(
                        'k_proxy',
                        255
                    );

                if (
                    $proxy !== ''
                    && !preg_match(
                        '/^[^:\s]+:\d{1,5}$/',
                        $proxy
                    )
                ) {
                    throw new InvalidArgumentException(
                        'Proxyはhost:port形式で指定してください。'
                    );
                }

                $settings['kintone'][
                    'subdomain'
                ] = $subdomain;

                $settings['kintone'][
                    'app_id'
                ] = $appId;

                $settings['kintone'][
                    'username'
                ] = $username;

                $settings['kintone'][
                    'proxy'
                ] = $proxy;

                $settings['kintone'][
                    'verify_ssl'
                ] = isset(
                    $_POST['k_verify_ssl']
                );

                /*
                 * 空欄の場合は既存秘密情報を保持。
                 * 入力された場合のみ暗号化して置換。
                 */
                if ($password !== '') {
                    $settings['kintone'][
                        'password'
                    ] = encryptSecret(
                        $password
                    );
                }

                writeJson(
                    'settings',
                    $settings
                );

                flash(
                    'success',
                    'kintone設定を保存しました。'
                );

                /*
                 * 保存結果確定後にだけredirect。
                 */
                redirect('kintone');

            /* -----------------------------------------
             * kintone接続テスト
             * --------------------------------------- */
            case 'test_kintone':
                $response =
                    kintoneRequest(
                        '/k/v1/app.json?id='
                        . rawurlencode(
                            (string)(
                                kintoneSettings()
                                ['app_id']
                            )
                        )
                    );

                if (
                    !$response['ok']
                ) {
                    throw new RuntimeException(
                        kintoneError($response)
                    );
                }

                flash(
                    'success',
                    'kintoneへの接続・認証に成功しました。'
                );

                redirect('kintone');

            /* -----------------------------------------
             * SMTP設定保存
             * --------------------------------------- */
            case 'save_smtp':
                $settings = appSettings();

                $server =
                    requirePostString(
                        'smtp_server',
                        255
                    );

                $port = filter_var(
                    $_POST['smtp_port'] ?? null,
                    FILTER_VALIDATE_INT
                );

                if (
                    $port === false
                    || $port < 1
                    || $port > 65535
                ) {
                    throw new InvalidArgumentException(
                        'SMTPポートが不正です。'
                    );
                }

                $encryption =
                    (string)(
                        $_POST[
                            'smtp_encryption'
                        ] ?? ''
                    );

                if (
                    !in_array(
                        $encryption,
                        [
                            'ssl',
                            'tls',
                            'none',
                        ],
                        true
                    )
                ) {
                    throw new InvalidArgumentException(
                        'SMTP暗号化方式が不正です。'
                    );
                }

                $fromEmail =
                    requirePostString(
                        'smtp_from_email',
                        320
                    );

                if (
                    !validEmail($fromEmail)
                ) {
                    throw new InvalidArgumentException(
                        '送信元メールアドレスが不正です。'
                    );
                }

                $replyTo =
                    optionalPostString(
                        'smtp_reply_to',
                        320
                    );

                if (
                    $replyTo !== ''
                    && !validEmail($replyTo)
                ) {
                    throw new InvalidArgumentException(
                        '返信先メールアドレスが不正です。'
                    );
                }

                $password =
                    optionalPostString(
                        'smtp_password',
                        1000
                    );

                $settings['smtp'][
                    'server'
                ] = $server;

                $settings['smtp'][
                    'port'
                ] = $port;

                $settings['smtp'][
                    'encryption'
                ] = $encryption;

                $settings['smtp'][
                    'auth'
                ] = isset(
                    $_POST['smtp_auth']
                );

                $settings['smtp'][
                    'username'
                ] = optionalPostString(
                    'smtp_username',
                    320
                );

                $settings['smtp'][
                    'from_email'
                ] = $fromEmail;

                $settings['smtp'][
                    'from_name'
                ] = optionalPostString(
                    'smtp_from_name',
                    200
                );

                $settings['smtp'][
                    'reply_to'
                ] = $replyTo;

                if ($password !== '') {
                    $settings['smtp'][
                        'password'
                    ] = encryptSecret(
                        $password
                    );
                }

                /*
                 * 設定変更後は接続確認済みを無効化。
                 */
                $settings['smtp'][
                    'status'
                ] = '未設定';

                writeJson(
                    'settings',
                    $settings
                );

                flash(
                    'success',
                    'SMTP設定を保存しました。'
                );

                redirect('mail');

            default:
                throw new InvalidArgumentException(
                    '不正な操作です。'
                );
        }

    } catch (
        InvalidArgumentException $exception
    ) {
        /*
         * CSRFエラーも含め、ユーザー側で
         * 再試行可能な400系として処理する。
         */
        renderErrorPage(
            $exception->getMessage(),
            400
        );

    } catch (
        Throwable $exception
    ) {
        /*
         * 内部パス、パスワード、API認証情報、
         * スタックトレース等は表示しない。
         */
        renderErrorPage(
            'システムエラーが発生しました。設定またはシステム管理者へ確認してください。',
            500
        );
    }
}

/* =========================================================
 * Clipboard
 *
 * PHPセッション・POSTとは完全に独立。
 * ======================================================= */

function renderClientScript(): void
{
    ?>
<script>
'use strict';

/**
 * Clipboard APIが使えない、またはDocumentがfocusされて
 * いない場合でもコピーできるようにする。
 */
async function copyPromptToClipboard(text, button) {
    const originalText = button
        ? button.textContent
        : '';

    try {
        if (
            document.hasFocus() &&
            navigator.clipboard &&
            typeof navigator.clipboard.writeText === 'function'
        ) {
            await navigator.clipboard.writeText(text);

            if (button) {
                button.textContent = 'コピーしました';
                setTimeout(() => {
                    button.textContent = originalText;
                }, 1200);
            }

            return true;
        }
    } catch (error) {
        /*
         * Clipboard API失敗はアプリケーションエラーではない。
         * DOMフォールバックへ移行する。
         */
    }

    try {
        const textarea =
            document.createElement('textarea');

        textarea.value = text;
        textarea.setAttribute(
            'readonly',
            ''
        );

        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        textarea.style.top = '0';
        textarea.style.opacity = '0';

        document.body.appendChild(
            textarea
        );

        textarea.focus();
        textarea.select();
        textarea.setSelectionRange(
            0,
            textarea.value.length
        );

        const copied =
            document.execCommand('copy');

        document.body.removeChild(
            textarea
        );

        if (!copied) {
            throw new Error(
                'コピーに失敗しました。'
            );
        }

        if (button) {
            button.textContent = 'コピーしました';

            setTimeout(() => {
                button.textContent =
                    originalText;
            }, 1200);
        }

        return true;

    } catch (error) {
        if (button) {
            button.textContent =
                'コピーできませんでした';

            setTimeout(() => {
                button.textContent =
                    originalText;
            }, 1600);
        }

        return false;
    }
}
</script>
<?php
}

/*
 * 以降：
 *
 * - アンケート一覧
 * - 作成・編集
 * - プレビュー
 * - 回答
 * - 回答確認
 * - 回答完了
 * - 顧客選択・メール送信
 * - 回答集計
 * - CSV
 * - PDF
 * - kintone設定
 * - SMTP設定
 * - SMTP実装
 * - 画面レンダリング
 * - CSS
 * - JavaScript
 *
 * をこの共通基盤の上に実装する。
 *
 * 重要なのは、従来版の
 *
 * session_set_cookie_params([
 *     'path' => dirname($_SERVER['SCRIPT_NAME'])
 * ]);
 *
 * という構造には戻さないこと。
 */

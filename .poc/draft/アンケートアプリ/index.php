<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 * Single Entry Point: index.php
 *
 * PHP 8.5 / Apache 2.4
 * DBなし / cURLなし / mail()なし
 *
 * 重要:
 * - kintone / SMTP パスワードは永続保存しない。
 * - パスワードは対象POST処理中だけ使用する。
 * - パスワードをGET、303、HTML、JavaScript、Cookie等へ移送しない。
 * - CSRF対策・管理者認証は実装しない（POC要件）。
 * - 外部302/303は成功扱いしない。
 * - アプリ自身の303は処理確定後のPRGにのみ使用する。
 */

const APP_VERSION = '2.0.0';
const DATA_DIR = __DIR__ . '/data';
const DATA_FILE = DATA_DIR . '/app.json';
const DATA_TMP = DATA_DIR . '/app.json.tmp';

const ALLOWED_SCREENS = [
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

const STATUS_DRAFT = '下書き';
const STATUS_OPEN = '公開中';
const STATUS_STOPPED = '停止';
const STATUS_FINISHED = '終了';

const QUESTION_SINGLE = 'single';
const QUESTION_MULTI = 'multi';
const QUESTION_TEXT = 'text';

date_default_timezone_set('Asia/Tokyo');

/* ============================================================
 * 基本ユーティリティ
 * ========================================================== */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nowIso(): string
{
    return date('Y-m-d\TH:i:s');
}

function nowDisplay(): string
{
    return date('Y/m/d H:i');
}

function postString(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function postRawString(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

function postInt(string $key, int $default = 0): int
{
    $value = $_POST[$key] ?? null;

    if (is_int($value)) {
        return $value;
    }

    if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
        return (int)$value;
    }

    return $default;
}

function getString(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function isPost(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function baseUrl(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    return $script !== '' ? $script : '/index.php';
}

function appUrl(string $screen, array $params = []): string
{
    if (!in_array($screen, ALLOWED_SCREENS, true)) {
        $screen = 'list';
    }

    $query = ['screen' => $screen];

    foreach ($params as $key => $value) {
        if ($value !== null && $value !== '') {
            $query[$key] = (string)$value;
        }
    }

    return baseUrl() . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function redirect303(string $screen, array $params = []): never
{
    $url = appUrl($screen, $params);

    header('Cache-Control: no-store');
    header('Location: ' . $url, true, 303);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consumeFlash(): ?array
{
    $flash = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);

    return is_array($flash) ? $flash : null;
}

function setPageError(string $message): void
{
    $_SESSION['_page_error'] = $message;
}

function consumePageError(): ?string
{
    $message = $_SESSION['_page_error'] ?? null;
    unset($_SESSION['_page_error']);

    return is_string($message) ? $message : null;
}

/* ============================================================
 * セッション
 * ========================================================== */

function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

startAppSession();

/* ============================================================
 * デフォルトデータ
 * ========================================================== */

function defaultData(): array
{
    return [
        'version' => 2,
        'surveys' => [],
        'answers' => [],
        'customers' => [],
        'sendHistory' => [],
        'kintone' => [
            'subdomain' => '',
            'appId' => '',
            'loginName' => '',
            'proxy' => '',
            'verifySsl' => true,
            'fieldMap' => [
                'organization' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
            'status' => '未設定',
        ],
        'mailSettings' => [
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'fromEmail' => '',
            'fromName' => '',
            'replyTo' => '',
            'status' => '未設定',
        ],
    ];
}

/*
 * 秘密情報を旧データから除去する。
 * 今回の仕様では保存済みパスワード自体を持たない。
 */
function sanitizePersistentData(array $data): array
{
    unset($data['kintone']['password']);
    unset($data['kintone']['passwordEncrypted']);
    unset($data['kintone']['secret']);
    unset($data['mailSettings']['password']);
    unset($data['mailSettings']['passwordEncrypted']);
    unset($data['mailSettings']['secret']);

    if (isset($data['kintone']['fieldMap']['address'])
        && !is_array($data['kintone']['fieldMap']['address'])) {
        $data['kintone']['fieldMap']['address'] = [];
    }

    return $data;
}

/* ============================================================
 * ファイル保存
 * ========================================================== */

function ensureDataDir(): void
{
    if (is_dir(DATA_DIR)) {
        return;
    }

    if (!mkdir(DATA_DIR, 0770, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('データ保存領域を作成できませんでした。');
    }
}

function loadData(): array
{
    ensureDataDir();

    if (!is_file(DATA_FILE)) {
        return defaultData();
    }

    $fp = @fopen(DATA_FILE, 'rb');

    if ($fp === false) {
        throw new RuntimeException('データファイルを読み込めませんでした。');
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            throw new RuntimeException('データファイルをロックできませんでした。');
        }

        $json = stream_get_contents($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    if ($json === false || $json === '') {
        throw new RuntimeException('データファイルが空です。');
    }

    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        throw new RuntimeException('データファイルの形式が不正です。');
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('データファイルの形式が不正です。');
    }

    $data = array_replace_recursive(defaultData(), $decoded);

    return sanitizePersistentData($data);
}

function saveData(array $data): bool
{
    ensureDataDir();

    $data = sanitizePersistentData($data);

    try {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT
            | JSON_THROW_ON_ERROR
        );
    } catch (Throwable) {
        return false;
    }

    $fp = @fopen(DATA_FILE, 'c+b');

    if ($fp === false) {
        return false;
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            return false;
        }

        $tmp = DATA_FILE . '.tmp.' . bin2hex(random_bytes(8));

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        /*
         * renameは同一ファイルシステム上で原子的に行われる。
         * 正式ファイルへ途中データを書き込まない。
         */
        if (!@rename($tmp, DATA_FILE)) {
            @unlink($tmp);
            return false;
        }

        fflush($fp);
        flock($fp, LOCK_UN);

        return true;
    } catch (Throwable) {
        return false;
    } finally {
        fclose($fp);
    }
}

/* ============================================================
 * 入力検証
 * ========================================================== */

function validateId(string $id): bool
{
    return $id !== ''
        && strlen($id) <= 100
        && preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1;
}

function validateEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validatePort(int $port): bool
{
    return $port >= 1 && $port <= 65535;
}

function normalizeDatetime(string $value): string
{
    if ($value === '') {
        return '';
    }

    $ts = strtotime($value);

    if ($ts === false) {
        return '';
    }

    return date('Y-m-d\TH:i:s', $ts);
}

function validateSurveyInput(array $input): array
{
    $errors = [];

    $title = trim((string)($input['title'] ?? ''));

    if ($title === '') {
        $errors[] = 'タイトルを入力してください。';
    } elseif (mb_strlen($title) > 200) {
        $errors[] = 'タイトルは200文字以内で入力してください。';
    }

    $description = trim((string)($input['description'] ?? ''));

    if (mb_strlen($description) > 5000) {
        $errors[] = '説明は5000文字以内で入力してください。';
    }

    $start = normalizeDatetime((string)($input['startAt'] ?? ''));
    $end = normalizeDatetime((string)($input['endAt'] ?? ''));

    if (($input['startAt'] ?? '') !== '' && $start === '') {
        $errors[] = '開始日時が不正です。';
    }

    if (($input['endAt'] ?? '') !== '' && $end === '') {
        $errors[] = '終了日時が不正です。';
    }

    if ($start !== '' && $end !== '' && strtotime($start) >= strtotime($end)) {
        $errors[] = '終了日時は開始日時より後にしてください。';
    }

    $numbering = (string)($input['numbering'] ?? 'global');

    if (!in_array($numbering, ['global', 'group'], true)) {
        $errors[] = '質問番号方式が不正です。';
    }

    return [$errors, $start, $end, $numbering];
}

/* ============================================================
 * 質問・グループ
 * ========================================================== */

function newQuestion(): array
{
    return [
        'id' => 'q_' . bin2hex(random_bytes(6)),
        'text' => '',
        'type' => QUESTION_SINGLE,
        'required' => false,
        'options' => ['選択肢1'],
        'branches' => [],
        'number' => '',
    ];
}

function newGroup(): array
{
    return [
        'id' => 'g_' . bin2hex(random_bytes(6)),
        'title' => '新しいグループ',
        'questions' => [newQuestion()],
    ];
}

function normalizeQuestion(array $question): array
{
    $type = (string)($question['type'] ?? QUESTION_SINGLE);

    if (!in_array($type, [
        QUESTION_SINGLE,
        QUESTION_MULTI,
        QUESTION_TEXT,
    ], true)) {
        $type = QUESTION_SINGLE;
    }

    $options = $question['options'] ?? [];

    if (!is_array($options)) {
        $options = [];
    }

    $options = array_values(array_filter(
        array_map(
            static fn($v) => is_scalar($v) ? trim((string)$v) : '',
            $options
        ),
        static fn($v) => $v !== ''
    ));

    if ($type === QUESTION_TEXT) {
        $options = [];
    }

    $branches = $question['branches'] ?? [];

    if (!is_array($branches)) {
        $branches = [];
    }

    return [
        'id' => validateId((string)($question['id'] ?? ''))
            ? (string)$question['id']
            : 'q_' . bin2hex(random_bytes(6)),
        'text' => mb_substr(trim((string)($question['text'] ?? '')), 0, 5000),
        'type' => $type,
        'required' => !empty($question['required']),
        'options' => $options,
        'branches' => $branches,
        'number' => '',
    ];
}

function normalizeGroups(array $groups): array
{
    $result = [];

    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $questions = [];

        foreach (($group['questions'] ?? []) as $question) {
            if (is_array($question)) {
                $questions[] = normalizeQuestion($question);
            }
        }

        $result[] = [
            'id' => validateId((string)($group['id'] ?? ''))
                ? (string)$group['id']
                : 'g_' . bin2hex(random_bytes(6)),
            'title' => mb_substr(
                trim((string)($group['title'] ?? '')),
                0,
                200
            ),
            'questions' => $questions,
        ];
    }

    return $result;
}

function recalculateNumbers(array &$groups, string $numbering): void
{
    $global = 0;

    foreach ($groups as $gi => &$group) {
        $local = 0;

        foreach ($group['questions'] as &$question) {
            $global++;
            $local++;

            if ($numbering === 'group') {
                $question['number'] = 'Q' . ($gi + 1) . '-' . $local;
            } else {
                $question['number'] = 'Q' . $global;
            }
        }

        unset($question);
    }

    unset($group);
}

/* ============================================================
 * アンケート
 * ========================================================== */

function findSurvey(array &$data, string $id): ?array
{
    foreach ($data['surveys'] as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function surveyIndex(array $data, string $id): int
{
    foreach ($data['surveys'] as $i => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

function surveyAnswerCount(array $data, string $surveyId): int
{
    $count = 0;

    foreach ($data['answers'] as $answer) {
        if (($answer['surveyId'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}

function updateAutomaticStatuses(array &$data): bool
{
    $changed = false;
    $now = time();

    foreach ($data['surveys'] as &$survey) {
        if (($survey['status'] ?? '') !== STATUS_OPEN) {
            continue;
        }

        $end = (string)($survey['endAt'] ?? '');

        if ($end !== '' && strtotime($end) !== false && strtotime($end) < $now) {
            $survey['status'] = STATUS_FINISHED;
            $survey['updatedAt'] = nowIso();
            $changed = true;
        }
    }

    unset($survey);

    return $changed;
}

function allowedStatusTransition(string $from, string $to): bool
{
    return match ($from) {
        STATUS_DRAFT => $to === STATUS_OPEN,
        STATUS_OPEN => $to === STATUS_STOPPED,
        STATUS_STOPPED => $to === STATUS_OPEN,
        STATUS_FINISHED => false,
        default => false,
    };
}

/* ============================================================
 * HTTP通信
 * PHP cURLなし。
 * stream_socket / fopen系のみ。
 * ========================================================== */

function httpRequest(
    string $method,
    string $url,
    array $headers = [],
    ?string $body = null,
    int $timeout = 15,
    bool $verifySsl = true,
    string $proxy = ''
): array {
    $parts = parse_url($url);

    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return [
            'ok' => false,
            'category' => 'input_error',
            'status' => null,
            'body' => '',
            'error' => 'URLが不正です。',
        ];
    }

    $scheme = strtolower((string)$parts['scheme']);

    if (!in_array($scheme, ['http', 'https'], true)) {
        return [
            'ok' => false,
            'category' => 'input_error',
            'status' => null,
            'body' => '',
            'error' => 'HTTP/HTTPS以外のURLは使用できません。',
        ];
    }

    $host = (string)$parts['host'];
    $port = isset($parts['port'])
        ? (int)$parts['port']
        : ($scheme === 'https' ? 443 : 80);

    $path = ($parts['path'] ?? '/') ?: '/';

    if (isset($parts['query']) && $parts['query'] !== '') {
        $path .= '?' . $parts['query'];
    }

    $transport = $scheme === 'https' ? 'tls' : 'tcp';

    $contextOptions = [
        'http' => [
            'method' => strtoupper($method),
            'protocol_version' => 1.1,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => [
            'verify_peer' => $verifySsl,
            'verify_peer_name' => $verifySsl,
            'allow_self_signed' => !$verifySsl,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ],
    ];

    if ($proxy !== '') {
        if (!preg_match('/^[^:\/\s]+:\d+$/', $proxy)) {
            return [
                'ok' => false,
                'category' => 'input_error',
                'status' => null,
                'body' => '',
                'error' => 'Proxyはhost:port形式で指定してください。',
            ];
        }

        [$proxyHost, $proxyPort] = explode(':', $proxy, 2);

        $contextOptions['http']['proxy'] =
            'tcp://' . $proxyHost . ':' . (int)$proxyPort;

        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $errorNo = 0;
    $errorMessage = '';

    set_error_handler(
        static function (int $severity, string $message) use (&$errorNo, &$errorMessage): bool {
            $errorNo = $severity;
            $errorMessage = $message;
            return true;
        }
    );

    $fp = @fopen($url, 'rb', false, $context);

    restore_error_handler();

    if ($fp === false) {
        return [
            'ok' => false,
            'category' => 'connection_error',
            'status' => null,
            'body' => '',
            'error' => $errorMessage !== ''
                ? '外部サービスへ接続できませんでした。'
                : '外部サービスへの接続に失敗しました。',
        ];
    }

    stream_set_timeout($fp, $timeout);

    $bodyResponse = '';

    while (!feof($fp)) {
        $chunk = fread($fp, 8192);

        if ($chunk === false) {
            fclose($fp);

            return [
                'ok' => false,
                'category' => 'read_error',
                'status' => null,
                'body' => '',
                'error' => 'レスポンスを取得できませんでした。',
            ];
        }

        $bodyResponse .= $chunk;
    }

    $meta = stream_get_meta_data($fp);
    fclose($fp);

    if (!empty($meta['timed_out'])) {
        return [
            'ok' => false,
            'category' => 'timeout',
            'status' => null,
            'body' => '',
            'error' => '外部サービスへの通信がタイムアウトしました。',
        ];
    }

    $status = null;

    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) {
            $status = (int)$m[1];
            break;
        }
    }

    if ($status === null) {
        return [
            'ok' => false,
            'category' => 'unknown',
            'status' => null,
            'body' => '',
            'error' => 'HTTPレスポンスを取得できませんでした。',
        ];
    }

    if ($status >= 300 && $status < 400) {
        return [
            'ok' => false,
            'category' => 'redirect',
            'status' => $status,
            'body' => $bodyResponse,
            'error' => '外部サービスからリダイレクト応答が返されました。',
        ];
    }

    if ($status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'category' => 'http_error',
            'status' => $status,
            'body' => $bodyResponse,
            'error' => '外部サービスからエラー応答が返されました。',
        ];
    }

    return [
        'ok' => true,
        'category' => 'success',
        'status' => $status,
        'body' => $bodyResponse,
        'error' => '',
    ];
}

/* ============================================================
 * kintone
 * ========================================================== */

function normalizeKintoneHost(string $subdomain): string
{
    $subdomain = trim($subdomain);

    if ($subdomain === '') {
        return '';
    }

    if (!preg_match('~^https?://~i', $subdomain)) {
        $subdomain = 'https://' . $subdomain;
    }

    $parts = parse_url($subdomain);

    if ($parts === false || empty($parts['host'])) {
        return '';
    }

    $host = strtolower((string)$parts['host']);

    if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $host)) {
        return '';
    }

    return 'https://' . $host;
}

function kintoneAuthHeader(string $loginName, string $password): string
{
    return base64_encode($loginName . ':' . $password);
}

function kintoneRequest(
    array $config,
    string $password,
    string $method,
    string $path,
    ?array $payload = null
): array {
    $base = normalizeKintoneHost((string)($config['subdomain'] ?? ''));

    if ($base === '') {
        return [
            'ok' => false,
            'category' => 'input_error',
            'message' => 'kintoneのサブドメインが不正です。',
            'body' => '',
        ];
    }

    $appId = (string)($config['appId'] ?? '');
    $login = (string)($config['loginName'] ?? '');

    if (!preg_match('/^\d+$/', $appId)) {
        return [
            'ok' => false,
            'category' => 'input_error',
            'message' => 'kintoneアプリIDが不正です。',
            'body' => '',
        ];
    }

    if ($login === '' || $password === '') {
        return [
            'ok' => false,
            'category' => 'auth_error',
            'message' => 'ログイン名とパスワードを入力してください。',
            'body' => '',
        ];
    }

    $url = $base . '/k/v1/' . ltrim($path, '/');

    $headers = [
        'X-Cybozu-Authorization: ' . kintoneAuthHeader($login, $password),
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: SurveyPOC/' . APP_VERSION,
        'Connection: close',
    ];

    $body = null;

    if ($payload !== null) {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($body === false) {
            return [
                'ok' => false,
                'category' => 'input_error',
                'message' => 'kintoneリクエストを作成できませんでした。',
                'body' => '',
            ];
        }
    }

    $response = httpRequest(
        $method,
        $url,
        $headers,
        $body,
        15,
        !empty($config['verifySsl']),
        trim((string)($config['proxy'] ?? ''))
    );

    if (!$response['ok']) {
        $status = $response['status'];

        if ($status === 401 || $status === 403) {
            $response['category'] = 'auth_error';
            $response['error'] = 'kintoneの認証情報を確認してください。';
        }

        $response['message'] = $response['error'];

        return $response;
    }

    $decoded = json_decode($response['body'], true);

    if ($decoded === null && trim($response['body']) !== 'null') {
        return [
            'ok' => false,
            'category' => 'response_error',
            'message' => 'kintoneのレスポンスを解釈できませんでした。',
            'body' => '',
        ];
    }

    if (is_array($decoded) && isset($decoded['code'])) {
        return [
            'ok' => false,
            'category' => 'api_error',
            'message' => 'kintone APIエラーが発生しました。',
            'body' => '',
            'apiCode' => (string)$decoded['code'],
        ];
    }

    return [
        'ok' => true,
        'category' => 'success',
        'message' => 'kintoneとの通信に成功しました。',
        'body' => $response['body'],
        'data' => is_array($decoded) ? $decoded : [],
        'status' => $response['status'],
    ];
}

function kintoneTest(array $config, string $password): array
{
    $appId = (int)($config['appId'] ?? 0);

    return kintoneRequest(
        $config,
        $password,
        'GET',
        'app.json?id=' . $appId
    );
}

function kintoneFields(array $config, string $password): array
{
    $appId = (int)($config['appId'] ?? 0);

    return kintoneRequest(
        $config,
        $password,
        'GET',
        'app/form/fields.json?app=' . $appId
    );
}

function kintoneRecords(
    array $config,
    string $password,
    int $offset = 0
): array {
    $appId = (int)($config['appId'] ?? 0);

    return kintoneRequest(
        $config,
        $password,
        'GET',
        'records.json?app=' . $appId . '&totalCount=true&query='
        . rawurlencode('limit 500 offset ' . max(0, $offset))
    );
}

function fieldValue(array $record, string $code): string
{
    if ($code === '' || !isset($record[$code])) {
        return '';
    }

    $value = $record[$code]['value'] ?? '';

    if (is_scalar($value)) {
        return trim((string)$value);
    }

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $parts[] = (string)$item;
            } elseif (is_array($item) && isset($item['name'])) {
                $parts[] = (string)$item['name'];
            }
        }

        return implode(', ', $parts);
    }

    return '';
}

/* ============================================================
 * SMTP
 * ========================================================== */

function smtpConnect(
    string $host,
    int $port,
    string $encryption,
    string $username,
    string $password,
    bool $auth
): array {
    if ($host === '' || !validatePort($port)) {
        return [
            'ok' => false,
            'category' => 'input_error',
            'message' => 'SMTPサーバまたはポートが不正です。',
        ];
    }

    if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
        return [
            'ok' => false,
            'category' => 'input_error',
            'message' => 'SMTP暗号化方式が不正です。',
        ];
    }

    $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT
    );

    if ($fp === false) {
        return [
            'ok' => false,
            'category' => 'connection_error',
            'message' => 'SMTPサーバへ接続できませんでした。',
        ];
    }

    stream_set_timeout($fp, 15);

    $read = static function () use ($fp): string {
        $response = '';

        while (!feof($fp)) {
            $line = fgets($fp, 8192);

            if ($line === false) {
                break;
            }

            $response .= $line;

            if (preg_match('/^\d{3}\s/', $line)) {
                break;
            }
        }

        return $response;
    };

    $write = static function (string $command) use ($fp): bool {
        return fwrite($fp, $command . "\r\n") !== false;
    };

    $greeting = $read();

    if (!preg_match('/^220\b/m', $greeting)) {
        fclose($fp);

        return [
            'ok' => false,
            'category' => 'smtp_error',
            'message' => 'SMTPサーバから正常な応答を取得できませんでした。',
        ];
    }

    if (!$write('EHLO localhost')) {
        fclose($fp);

        return [
            'ok' => false,
            'category' => 'smtp_error',
            'message' => 'SMTP EHLOに失敗しました。',
        ];
    }

    $ehlo = $read();

    if (!preg_match('/^250\b/m', $ehlo)) {
        fclose($fp);

        return [
            'ok' => false,
            'category' => 'smtp_error',
            'message' => 'SMTP EHLOが拒否されました。',
        ];
    }

    if ($encryption === 'tls') {
        if (!$write('STARTTLS')) {
            fclose($fp);

            return [
                'ok' => false,
                'category' => 'smtp_error',
                'message' => 'STARTTLSを開始できませんでした。',
            ];
        }

        $startTls = $read();

        if (!preg_match('/^220\b/m', $startTls)) {
            fclose($fp);

            return [
                'ok' => false,
                'category' => 'smtp_error',
                'message' => 'STARTTLSが拒否されました。',
            ];
        }

        $crypto = @stream_socket_enable_crypto(
            $fp,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($fp);

            return [
                'ok' => false,
                'category' => 'tls_error',
                'message' => 'TLS通信を確立できませんでした。',
            ];
        }

        $write('EHLO localhost');
        $ehlo = $read();
    }

    if ($auth) {
        if ($username === '' || $password === '') {
            fclose($fp);

            return [
                'ok' => false,
                'category' => 'auth_error',
                'message' => 'SMTP認証情報を入力してください。',
            ];
        }

        if (!$write('AUTH LOGIN')) {
            fclose($fp);

            return [
                'ok' => false,
                'category' => 'auth_error',
                'message' => 'SMTP認証を開始できませんでした。',
            ];
        }

        $authReply = $read();

        if (!preg_match('/^334\b/m', $authReply)) {
            fclose($fp);

            return [
                'ok' => false,
                'category' => 'auth_error',
                'message' => 'SMTP認証を開始できませんでした。',
            ];
        }

        $write(base64_encode($username));
        $authReply = $read();

        if (!preg_match('/^334\b/m', $authReply)) {
            fclose($fp);

            return [
                'ok' => false,
                'category' => 'auth_error',
                'message' => 'SMTPユーザー名が拒否されました。',
            ];
        }

        /*
         * パスワードはここでのみ使用。
         * HTML、ログ、セッション、永続データへコピーしない。
         */
        $write(base64_encode($password));
        $authReply = $read();

        if (!preg_match('/^235\b/m', $authReply)) {
            fclose($fp);

            return [
                'ok' => false,
                'category' => 'auth_error',
                'message' => 'SMTP認証に失敗しました。',
            ];
        }
    }

    return [
        'ok' => true,
        'category' => 'success',
        'message' => 'SMTP接続・認証に成功しました。',
        'socket' => $fp,
        'read' => $read,
        'write' => $write,
    ];
}

function smtpSend(
    array $settings,
    string $password,
    string $to,
    string $subject,
    string $body,
    string $replyTo = ''
): array {
    if (!validateEmail($to)) {
        return [
            'ok' => false,
            'category' => 'input_error',
            'message' => '宛先メールアドレスが不正です。',
        ];
    }

    $connection = smtpConnect(
        (string)($settings['host'] ?? ''),
        (int)($settings['port'] ?? 0),
        (string)($settings['encryption'] ?? 'tls'),
        (string)($settings['username'] ?? ''),
        $password,
        !empty($settings['auth'])
    );

    if (!$connection['ok']) {
        return $connection;
    }

    $fp = $connection['socket'];
    $read = $connection['read'];
    $write = $connection['write'];

    $from = (string)($settings['fromEmail'] ?? '');

    if (!validateEmail($from)) {
        fclose($fp);

        return [
            'ok' => false,
            'category' => 'input_error',
            'message' => '送信元メールアドレスが不正です。',
        ];
    }

    $fromName = (string)($settings['fromName'] ?? '');

    $encodedSubject = '=?UTF-8?B?'
        . base64_encode($subject)
        . '?=';

    $headers = [
        'From: ' . ($fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?= '
            : '')
            . '<' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    if ($replyTo !== '' && validateEmail($replyTo)) {
        $headers[] = 'Reply-To: <' . $replyTo . '>';
    }

    if (!$write('MAIL FROM:<' . $from . '>')) {
        fclose($fp);

        return [
            'ok' => false,
            'category' => 'smtp_error',
            'message' => 'SMTP MAIL FROMに失敗しました。',
        ];
    }

    $reply = $read();

    if (!preg_match('/^250\b/m', $reply)) {
        fclose($fp);

        return [
            'ok' => false,
            'category' => 'smtp_error',
            'message' => 'SMTPサーバが送信元を拒否しました。',
        ];
    }

    if (!$write('RCPT TO:<' . $to . '>')) {
        fclose($fp);

        return [
            'ok' => false,
            'category' => 'smtp_error',
            'message' => 'SMTP RCPT TOに失敗しました。',
        ];
    }

    $reply = $read();

    if (!preg_match('/^250\b/m', $reply)) {
        fclose($fp);

        return [
            'ok' => false,
            'category' => 'smtp_error',
            'message' => 'SMTPサーバが宛先を拒否しました。',
        ];
    }

    if (!$write('DATA')) {
        fclose($fp);

        return [
            'ok' => false,
            'category' => 'smtp_error',
            'message' => 'SMTP DATAに失敗しました。',
        ];
    }

    $reply = $read();

    if (!preg_match('/^354\b/m', $reply)) {
        fclose($fp);

        return [
            'ok' => false,
            'category' => 'smtp_error',
            'message' => 'SMTPサーバがメール本文を受け付けませんでした。',
        ];
    }

    $safeBody = preg_replace('/^\./m', '..', $body) ?? $body;

    $message = implode("\r\n", $headers)
        . "\r\n\r\n"
        . $safeBody
        . "\r\n.";

    if (!$write($message)) {
        fclose($fp);

        return [
            'ok' => false,
            'category' => 'smtp_error',
            'message' => 'SMTPメール本文の送信に失敗しました。',
        ];
    }

    $reply = $read();

    if (!preg_match('/^250\b/m', $reply)) {
        fclose($fp);

        return [
            'ok' => false,
            'category' => 'smtp_error',
            'message' => 'SMTPメール送信結果を成功と確認できませんでした。',
        ];
    }

    $write('QUIT');
    $read();
    fclose($fp);

    return [
        'ok' => true,
        'category' => 'success',
        'message' => 'メール送信に成功しました。',
    ];
}

/* ============================================================
 * フォーム処理
 * ========================================================== */

function processPost(array &$data): ?array
{
    $action = postString('action');

    if ($action === '') {
        return null;
    }

    /*
     * --------------------------------------------------------
     * アンケート保存
     * --------------------------------------------------------
     */
    if ($action === 'save_survey') {
        $id = postString('id');
        $isNew = $id === '';

        $input = [
            'title' => postString('title'),
            'description' => postRawString('description'),
            'startAt' => postString('startAt'),
            'endAt' => postString('endAt'),
            'numbering' => postString('numbering', 'global'),
        ];

        [$errors, $start, $end, $numbering] =
            validateSurveyInput($input);

        if ($errors !== []) {
            return [
                'screen' => 'edit',
                'id' => $id,
                'error' => implode(' ', $errors),
            ];
        }

        $groupsJson = postRawString('groupsJson', '[]');
        $groups = json_decode($groupsJson, true);

        if (!is_array($groups)) {
            return [
                'screen' => 'edit',
                'id' => $id,
                'error' => '質問データが不正です。',
            ];
        }

        $groups = normalizeGroups($groups);
        recalculateNumbers($groups, $numbering);

        if ($isNew) {
            $survey = [
                'id' => 'survey_' . bin2hex(random_bytes(8)),
                'title' => $input['title'],
                'description' => $input['description'],
                'startAt' => $start,
                'endAt' => $end,
                'numbering' => $numbering,
                'status' => STATUS_DRAFT,
                'groups' => $groups,
                'createdAt' => nowIso(),
                'updatedAt' => nowIso(),
            ];

            $data['surveys'][] = $survey;
        } else {
            $index = surveyIndex($data, $id);

            if ($index < 0) {
                return [
                    'screen' => 'list',
                    'error' => '指定されたアンケートが存在しません。',
                ];
            }

            $currentStatus = (string)$data['surveys'][$index]['status'];

            $data['surveys'][$index]['title'] = $input['title'];
            $data['surveys'][$index]['description'] = $input['description'];
            $data['surveys'][$index]['startAt'] = $start;
            $data['surveys'][$index]['endAt'] = $end;
            $data['surveys'][$index]['numbering'] = $numbering;
            $data['surveys'][$index]['groups'] = $groups;
            $data['surveys'][$index]['status'] = $currentStatus;
            $data['surveys'][$index]['updatedAt'] = nowIso();
        }

        if (!saveData($data)) {
            return [
                'screen' => 'edit',
                'id' => $id,
                'error' => 'アンケートを保存できませんでした。',
            ];
        }

        flash('success', 'アンケートを保存しました。');
        redirect303('list');
    }

    /*
     * --------------------------------------------------------
     * ステータス変更
     * --------------------------------------------------------
     */
    if ($action === 'change_status') {
        $id = postString('id');
        $to = postString('status');

        if (!validateId($id)) {
            return [
                'screen' => 'list',
                'error' => 'アンケートIDが不正です。',
            ];
        }

        $index = surveyIndex($data, $id);

        if ($index < 0) {
            return [
                'screen' => 'list',
                'error' => 'アンケートが存在しません。',
            ];
        }

        $from = (string)$data['surveys'][$index]['status'];

        if (!allowedStatusTransition($from, $to)) {
            return [
                'screen' => 'list',
                'error' => '許可されていない状態遷移です。',
            ];
        }

        $data['surveys'][$index]['status'] = $to;
        $data['surveys'][$index]['updatedAt'] = nowIso();

        if (!saveData($data)) {
            return [
                'screen' => 'list',
                'error' => '状態を保存できませんでした。',
            ];
        }

        flash('success', 'ステータスを変更しました。');
        redirect303('list');
    }

    /*
     * --------------------------------------------------------
     * 複製
     * --------------------------------------------------------
     */
    if ($action === 'duplicate_survey') {
        $id = postString('id');
        $survey = findSurvey($data, $id);

        if ($survey === null) {
            return [
                'screen' => 'list',
                'error' => '複製対象が存在しません。',
            ];
        }

        $survey['id'] = 'survey_' . bin2hex(random_bytes(8));
        $survey['title'] = $survey['title'] . '（コピー）';
        $survey['status'] = STATUS_DRAFT;
        $survey['createdAt'] = nowIso();
        $survey['updatedAt'] = nowIso();

        $data['surveys'][] = $survey;

        if (!saveData($data)) {
            return [
                'screen' => 'list',
                'error' => 'アンケートを複製できませんでした。',
            ];
        }

        flash('success', 'アンケートを複製しました。');
        redirect303('list');
    }

    /*
     * --------------------------------------------------------
     * 削除
     * --------------------------------------------------------
     */
    if ($action === 'delete_survey') {
        $id = postString('id');
        $index = surveyIndex($data, $id);

        if ($index < 0) {
            return [
                'screen' => 'list',
                'error' => '削除対象が存在しません。',
            ];
        }

        array_splice($data['surveys'], $index, 1);

        if (!saveData($data)) {
            return [
                'screen' => 'list',
                'error' => 'アンケートを削除できませんでした。',
            ];
        }

        flash('success', 'アンケートを削除しました。');
        redirect303('list');
    }

    /*
     * --------------------------------------------------------
     * kintone設定保存
     *
     * パスワードは一切保存しない。
     * --------------------------------------------------------
     */
    if ($action === 'save_kintone') {
        $subdomain = postString('subdomain');
        $appId = postString('appId');
        $loginName = postString('loginName');
        $proxy = postString('proxy');
        $verifySsl = isset($_POST['verifySsl']);

        if (normalizeKintoneHost($subdomain) === '') {
            return [
                'screen' => 'kintone',
                'error' => 'kintoneサブドメインが不正です。',
            ];
        }

        if (!preg_match('/^\d+$/', $appId)) {
            return [
                'screen' => 'kintone',
                'error' => 'アプリIDは数値で入力してください。',
            ];
        }

        if ($loginName === '') {
            return [
                'screen' => 'kintone',
                'error' => 'ログイン名を入力してください。',
            ];
        }

        if ($proxy !== '' && !preg_match('/^[^:\/\s]+:\d+$/', $proxy)) {
            return [
                'screen' => 'kintone',
                'error' => 'Proxyはhost:port形式で入力してください。',
            ];
        }

        $data['kintone']['subdomain'] = $subdomain;
        $data['kintone']['appId'] = $appId;
        $data['kintone']['loginName'] = $loginName;
        $data['kintone']['proxy'] = $proxy;
        $data['kintone']['verifySsl'] = $verifySsl;

        /*
         * 念のため旧形式の秘密情報も削除。
         */
        unset($data['kintone']['password']);
        unset($data['kintone']['passwordEncrypted']);

        if (!saveData($data)) {
            return [
                'screen' => 'kintone',
                'error' => 'kintone設定を保存できませんでした。',
            ];
        }

        flash('success', 'kintone設定を保存しました。パスワードは保存していません。');
        redirect303('kintone');
    }

    /*
     * --------------------------------------------------------
     * kintone接続テスト
     * --------------------------------------------------------
     */
    if ($action === 'test_kintone') {
        $password = postRawString('password');

        /*
         * passwordはこの関数呼び出しのためだけに使用。
         * セッション、GET、HTML、ログへ保存しない。
         */
        $result = kintoneTest($data['kintone'], $password);

        unset($password);

        if (!$result['ok']) {
            return [
                'screen' => 'kintone',
                'error' => $result['message'] ?? 'kintoneへ接続できませんでした。',
            ];
        }

        $data['kintone']['status'] = '接続確認済み';

        if (!saveData($data)) {
            return [
                'screen' => 'kintone',
                'error' => '接続結果を保存できませんでした。',
            ];
        }

        flash('success', 'kintoneへの接続・認証を確認しました。');
        redirect303('kintone');
    }

    /*
     * --------------------------------------------------------
     * kintone項目取得
     * --------------------------------------------------------
     */
    if ($action === 'fetch_kintone_fields') {
        $password = postRawString('password');

        $result = kintoneFields($data['kintone'], $password);

        unset($password);

        if (!$result['ok']) {
            return [
                'screen' => 'kintone',
                'error' => $result['message'] ?? '項目一覧を取得できませんでした。',
            ];
        }

        $fields = $result['data']['properties'] ?? [];

        if (!is_array($fields)) {
            return [
                'screen' => 'kintone',
                'error' => '項目一覧の形式が不正です。',
            ];
        }

        $_SESSION['kintone_fields'] = $fields;

        flash('success', 'kintoneの項目一覧を取得しました。');
        redirect303('kintone');
    }

    /*
     * --------------------------------------------------------
     * kintone顧客同期
     * --------------------------------------------------------
     */
    if ($action === 'sync_kintone') {
        $password = postRawString('password');

        $fieldMap = [
            'organization' => postString('organizationField'),
            'name' => postString('nameField'),
            'email' => postString('emailField'),
            'department' => postString('departmentField'),
            'phone' => postString('phoneField'),
            'address' => array_values(
                array_filter(
                    $_POST['addressFields'] ?? [],
                    static fn($v) => is_string($v) && $v !== ''
                )
            ),
        ];

        $result = kintoneRecords($data['kintone'], $password);

        unset($password);

        if (!$result['ok']) {
            return [
                'screen' => 'kintone',
                'error' => $result['message'] ?? '顧客情報を取得できませんでした。',
            ];
        }

        $records = $result['data']['records'] ?? [];

        if (!is_array($records)) {
            return [
                'screen' => 'kintone',
                'error' => '顧客情報の形式が不正です。',
            ];
        }

        $customers = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $addressParts = [];

            foreach ($fieldMap['address'] as $field) {
                $value = fieldValue($record, $field);

                if ($value !== '') {
                    $addressParts[] = $value;
                }
            }

            $email = fieldValue($record, $fieldMap['email']);

            if ($email === '' || !validateEmail($email)) {
                continue;
            }

            $customers[] = [
                'id' => 'customer_' . bin2hex(random_bytes(6)),
                'organization' => fieldValue($record, $fieldMap['organization']),
                'name' => fieldValue($record, $fieldMap['name']),
                'email' => $email,
                'department' => fieldValue($record, $fieldMap['department']),
                'phone' => fieldValue($record, $fieldMap['phone']),
                'address' => implode(' ', $addressParts),
                'updatedAt' => nowIso(),
            ];
        }

        $data['customers'] = $customers;
        $data['kintone']['fieldMap'] = $fieldMap;

        if (!saveData($data)) {
            return [
                'screen' => 'kintone',
                'error' => '顧客データを保存できませんでした。',
            ];
        }

        flash('success', count($customers) . '件の顧客情報を同期しました。');
        redirect303('kintone');
    }

    /*
     * --------------------------------------------------------
     * メール設定保存
     * パスワードは保存しない。
     * --------------------------------------------------------
     */
    if ($action === 'save_mail') {
        $host = postString('host');
        $port = postInt('port', 587);
        $encryption = postString('encryption', 'tls');
        $auth = isset($_POST['auth']);
        $username = postString('username');
        $fromEmail = postString('fromEmail');
        $fromName = postString('fromName');
        $replyTo = postString('replyTo');

        if ($host === '') {
            return [
                'screen' => 'mail',
                'error' => 'SMTPサーバを入力してください。',
            ];
        }

        if (!validatePort($port)) {
            return [
                'screen' => 'mail',
                'error' => 'SMTPポートが不正です。',
            ];
        }

        if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
            return [
                'screen' => 'mail',
                'error' => '暗号化方式が不正です。',
            ];
        }

        if (!validateEmail($fromEmail)) {
            return [
                'screen' => 'mail',
                'error' => '送信元メールアドレスが不正です。',
            ];
        }

        if ($replyTo !== '' && !validateEmail($replyTo)) {
            return [
                'screen' => 'mail',
                'error' => '返信先メールアドレスが不正です。',
            ];
        }

        $data['mailSettings'] = [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'auth' => $auth,
            'username' => $username,
            'fromEmail' => $fromEmail,
            'fromName' => $fromName,
            'replyTo' => $replyTo,
            'status' => '未設定',
        ];

        unset($data['mailSettings']['password']);
        unset($data['mailSettings']['passwordEncrypted']);

        if (!saveData($data)) {
            return [
                'screen' => 'mail',
                'error' => 'メール設定を保存できませんでした。',
            ];
        }

        flash('success', 'メール設定を保存しました。パスワードは保存していません。');
        redirect303('mail');
    }

    /*
     * --------------------------------------------------------
     * SMTP接続テスト
     * --------------------------------------------------------
     */
    if ($action === 'test_mail') {
        $password = postRawString('password');

        $settings = $data['mailSettings'];

        $result = smtpConnect(
            (string)$settings['host'],
            (int)$settings['port'],
            (string)$settings['encryption'],
            (string)$settings['username'],
            $password,
            !empty($settings['auth'])
        );

        if (isset($result['socket']) && is_resource($result['socket'])) {
            @fwrite($result['socket'], "QUIT\r\n");
            @fclose($result['socket']);
        }

        unset($password);

        if (!$result['ok']) {
            return [
                'screen' => 'mail',
                'error' => $result['message'] ?? 'SMTPへ接続できませんでした。',
            ];
        }

        $data['mailSettings']['status'] = '接続確認済み';

        if (!saveData($data)) {
            return [
                'screen' => 'mail',
                'error' => 'SMTP接続状態を保存できませんでした。',
            ];
        }

        flash('success', 'SMTP接続・認証を確認しました。');
        redirect303('mail');
    }

    /*
     * --------------------------------------------------------
     * テストメール
     * --------------------------------------------------------
     */
    if ($action === 'send_test_mail') {
        $password = postRawString('password');
        $to = postString('to');

        $settings = $data['mailSettings'];

        $result = smtpSend(
            $settings,
            $password,
            $to,
            'アンケートアプリ テストメール',
            "これはアンケートアプリからのテストメールです。\n"
            . nowDisplay(),
            (string)$settings['replyTo']
        );

        unset($password);

        if (!$result['ok']) {
            return [
                'screen' => 'mail',
                'error' => $result['message'] ?? 'テストメールを送信できませんでした。',
            ];
        }

        flash('success', 'テストメールを送信しました。');
        redirect303('mail');
    }

    /*
     * --------------------------------------------------------
     * 回答確認
     * --------------------------------------------------------
     */
    if ($action === 'confirm_answer') {
        $surveyId = postString('surveyId');
        $survey = findSurvey($data, $surveyId);

        if ($survey === null) {
            return [
                'screen' => 'answer',
                'id' => $surveyId,
                'error' => 'アンケートが存在しません。',
            ];
        }

        if (($survey['status'] ?? '') !== STATUS_OPEN) {
            return [
                'screen' => 'answer',
                'id' => $surveyId,
                'error' => 'このアンケートは現在回答できません。',
            ];
        }

        $answers = $_POST['answers'] ?? [];

        if (!is_array($answers)) {
            $answers = [];
        }

        $visible = visibleQuestions($survey, $answers);
        $errors = validateAnswers($visible, $answers);

        if ($errors !== []) {
            return [
                'screen' => 'answer',
                'id' => $surveyId,
                'error' => implode(' ', $errors),
            ];
        }

        $_SESSION['answer_draft'] = [
            'surveyId' => $surveyId,
            'answers' => normalizeAnswers($answers),
        ];

        redirect303('confirm', ['id' => $surveyId]);
    }

    /*
     * --------------------------------------------------------
     * 回答送信
     * --------------------------------------------------------
     */
    if ($action === 'submit_answer') {
        $surveyId = postString('surveyId');
        $survey = findSurvey($data, $surveyId);

        if ($survey === null) {
            return [
                'screen' => 'answer',
                'id' => $surveyId,
                'error' => 'アンケートが存在しません。',
            ];
        }

        if (($survey['status'] ?? '') !== STATUS_OPEN) {
            return [
                'screen' => 'answer',
                'id' => $surveyId,
                'error' => 'このアンケートは現在回答できません。',
            ];
        }

        $draft = $_SESSION['answer_draft'] ?? null;

        if (!is_array($draft)
            || ($draft['surveyId'] ?? '') !== $surveyId
            || !is_array($draft['answers'] ?? null)) {
            return [
                'screen' => 'answer',
                'id' => $surveyId,
                'error' => '回答内容を確認できませんでした。もう一度回答してください。',
            ];
        }

        $answer = [
            'id' => 'answer_' . bin2hex(random_bytes(8)),
            'surveyId' => $surveyId,
            'answers' => $draft['answers'],
            'createdAt' => nowIso(),
        ];

        $data['answers'][] = $answer;

        if (!saveData($data)) {
            return [
                'screen' => 'confirm',
                'id' => $surveyId,
                'error' => '回答を保存できませんでした。もう一度お試しください。',
            ];
        }

        unset($_SESSION['answer_draft']);

        flash('success', '回答を送信しました。');
        redirect303('complete', ['id' => $surveyId]);
    }

    /*
     * --------------------------------------------------------
     * 回答修正
     * --------------------------------------------------------
     */
    if ($action === 'edit_answer') {
        $surveyId = postString('surveyId');

        redirect303('answer', ['id' => $surveyId]);
    }

    /*
     * --------------------------------------------------------
     * メール一括送信 / 再送 / リマインド
     * --------------------------------------------------------
     */
    if ($action === 'send_campaign') {
        $surveyId = postString('surveyId');
        $survey = findSurvey($data, $surveyId);

        if ($survey === null) {
            return [
                'screen' => 'send',
                'id' => $surveyId,
                'error' => '対象アンケートが存在しません。',
            ];
        }

        $customerIds = $_POST['customerIds'] ?? [];

        if (!is_array($customerIds)) {
            $customerIds = [];
        }

        $customerIds = array_values(
            array_filter(
                $customerIds,
                static fn($id) => is_string($id) && $id !== ''
            )
        );

        if ($customerIds === []) {
            return [
                'screen' => 'send',
                'id' => $surveyId,
                'error' => '送信対象を選択してください。',
            ];
        }

        $subject = postString('subject');
        $bodyTemplate = postRawString('body');
        $mode = postString('mode', 'send');

        $password = postRawString('password');

        if ($subject === '') {
            unset($password);

            return [
                'screen' => 'send',
                'id' => $surveyId,
                'error' => 'メール件名を入力してください。',
            ];
        }

        if ($bodyTemplate === '') {
            unset($password);

            return [
                'screen' => 'send',
                'id' => $surveyId,
                'error' => 'メール本文を入力してください。',
            ];
        }

        /*
         * SMTPパスワードはこの送信処理だけで使用。
         * 送信履歴には絶対に入れない。
         */
        $success = 0;
        $failed = 0;

        foreach ($data['customers'] as $customer) {
            if (!in_array($customer['id'] ?? '', $customerIds, true)) {
                continue;
            }

            $name = (string)($customer['name'] ?? '');
            $email = (string)($customer['email'] ?? '');

            $surveyUrl = baseUrl()
                . '?screen=answer&id='
                . rawurlencode($surveyId);

            $body = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [$name, $surveyUrl],
                $bodyTemplate
            );

            $result = smtpSend(
                $data['mailSettings'],
                $password,
                $email,
                $subject,
                $body,
                (string)$data['mailSettings']['replyTo']
            );

            /*
             * SMTP結果を確認してから履歴へ記録。
             */
            if ($result['ok']) {
                $success++;
                $resultStatus = 'success';
            } else {
                $failed++;
                $resultStatus = 'failed';
            }

            $data['sendHistory'][] = [
                'id' => 'send_' . bin2hex(random_bytes(8)),
                'surveyId' => $surveyId,
                'customerId' => (string)$customer['id'],
                'email' => $email,
                'mode' => in_array(
                    $mode,
                    ['send', 'resend', 'reminder'],
                    true
                ) ? $mode : 'send',
                'status' => $resultStatus,
                'createdAt' => nowIso(),
                'message' => $result['ok']
                    ? '送信成功'
                    : '送信失敗',
            ];
        }

        unset($password);

        /*
         * SMTP結果が確定した後にのみ保存。
         */
        if (!saveData($data)) {
            return [
                'screen' => 'send',
                'id' => $surveyId,
                'error' => '送信履歴を保存できませんでした。送信結果を成功として確定できません。',
            ];
        }

        flash(
            $failed > 0 ? 'warning' : 'success',
            "送信結果：成功 {$success}件 / 失敗 {$failed}件"
        );

        redirect303('send', ['id' => $surveyId]);
    }

    return null;
}

/* ============================================================
 * 回答ロジック
 * ========================================================== */

function flattenQuestions(array $survey): array
{
    $questions = [];

    foreach (($survey['groups'] ?? []) as $group) {
        foreach (($group['questions'] ?? []) as $question) {
            $questions[] = $question;
        }
    }

    return $questions;
}

function visibleQuestions(array $survey, array $answers): array
{
    $questions = flattenQuestions($survey);
    $visible = [];

    $indexById = [];

    foreach ($questions as $index => $question) {
        $indexById[(string)$question['id']] = $index;
    }

    $skipUntil = null;

    foreach ($questions as $question) {
        $id = (string)$question['id'];

        if ($skipUntil !== null && $id !== $skipUntil) {
            continue;
        }

        $visible[] = $question;

        $skipUntil = null;

        if (($question['type'] ?? '') !== QUESTION_SINGLE) {
            continue;
        }

        $answer = $answers[$id] ?? null;

        if (is_array($answer)) {
            $answer = reset($answer);
        }

        if (!is_string($answer)) {
            continue;
        }

        $next = $question['branches'][$answer] ?? null;

        if ($next !== null && isset($indexById[$next])) {
            $targetIndex = $indexById[$next];

            /*
             * 指定された質問へ到達するまでの質問を表示する。
             * それ以前の質問は通常の順序で処理済み。
             */
            if ($targetIndex > 0) {
                $visible = array_slice($questions, 0, $targetIndex + 1);
            }
        }
    }

    return $visible;
}

function normalizeAnswers(array $answers): array
{
    $normalized = [];

    foreach ($answers as $key => $value) {
        if (!is_string($key)) {
            continue;
        }

        if (is_array($value)) {
            $normalized[$key] = array_values(
                array_filter(
                    array_map(
                        static fn($v) => is_scalar($v) ? trim((string)$v) : '',
                        $value
                    ),
                    static fn($v) => $v !== ''
                )
            );
        } elseif (is_scalar($value)) {
            $normalized[$key] = trim((string)$value);
        }
    }

    return $normalized;
}

function validateAnswers(array $questions, array $answers): array
{
    $errors = [];

    foreach ($questions as $question) {
        $id = (string)($question['id'] ?? '');
        $required = !empty($question['required']);
        $value = $answers[$id] ?? null;

        $empty = $value === null
            || $value === ''
            || (is_array($value) && count($value) === 0);

        if ($required && $empty) {
            $errors[] = ($question['number'] ?? '質問') . ' は必須です。';
            continue;
        }

        if ($value === null) {
            continue;
        }

        $type = (string)($question['type'] ?? '');

        if ($type === QUESTION_SINGLE) {
            $allowed = $question['options'] ?? [];

            if (!is_string($value) || !in_array($value, $allowed, true)) {
                $errors[] = ($question['number'] ?? '質問')
                    . ' の回答が不正です。';
            }
        }

        if ($type === QUESTION_MULTI) {
            if (!is_array($value)) {
                $errors[] = ($question['number'] ?? '質問')
                    . ' の回答が不正です。';
                continue;
            }

            $allowed = $question['options'] ?? [];

            foreach ($value as $item) {
                if (!in_array($item, $allowed, true)) {
                    $errors[] = ($question['number'] ?? '質問')
                        . ' の回答が不正です。';
                    break;
                }
            }
        }

        if ($type === QUESTION_TEXT) {
            if (!is_string($value) || mb_strlen($value) > 10000) {
                $errors[] = ($question['number'] ?? '質問')
                    . ' の入力が不正です。';
            }
        }
    }

    return $errors;
}

/* ============================================================
 * 初期データロード・POST処理
 * ========================================================== */

$data = defaultData();
$globalError = null;

try {
    $data = loadData();

    if (updateAutomaticStatuses($data)) {
        if (!saveData($data)) {
            $globalError = 'アンケート状態の自動更新を保存できませんでした。';
        }
    }

    if (isPost()) {
        processPost($data);
    }
} catch (Throwable $e) {
    /*
     * 内部例外をそのまま表示しない。
     * パスワード等の秘密情報も出力しない。
     */
    $globalError = '処理中にエラーが発生しました。入力内容と設定を確認してください。';
}

/* ============================================================
 * GET画面決定
 * ========================================================== */

$screen = getString('screen', 'list');

if (!in_array($screen, ALLOWED_SCREENS, true)) {
    $screen = 'list';
}

$id = getString('id');

if (
    in_array($screen, ['edit', 'preview', 'send', 'analytics', 'answer', 'confirm', 'complete'], true)
    && !validateId($id)
) {
    if ($screen === 'answer' || $screen === 'confirm' || $screen === 'complete') {
        $globalError = 'アンケートIDが不正です。';
    } else {
        $screen = 'list';
    }
}

$survey = null;

if ($id !== '') {
    $survey = findSurvey($data, $id);
}

if (
    in_array($screen, ['edit', 'preview', 'send', 'analytics', 'answer', 'confirm', 'complete'], true)
    && $survey === null
) {
    $globalError = '指定されたアンケートが存在しません。';

    if ($screen !== 'answer'
        && $screen !== 'confirm'
        && $screen !== 'complete') {
        $screen = 'list';
    }
}

$flash = consumeFlash();
$pageError = consumePageError();

if ($globalError === null && $pageError !== null) {
    $globalError = $pageError;
}

/* ============================================================
 * 共通表示
 * ========================================================== */

function renderHead(string $title, bool $admin = true): void
{
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> - アンケートアプリ</title>
<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --bg:#f5f7fb;
    --surface:#fff;
    --text:#1f2937;
    --muted:#6b7280;
    --border:#e5e7eb;
    --success:#15803d;
    --warning:#b45309;
    --danger:#dc2626;
    --shadow:0 2px 12px rgba(15,23,42,.06);
    --radius:10px;
}
*{box-sizing:border-box}
body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
        "Noto Sans JP","Yu Gothic",Meiryo,sans-serif;
    line-height:1.6;
}
a{color:var(--primary);text-decoration:none}
a:hover{text-decoration:underline}
button,input,select,textarea{font:inherit}
button{cursor:pointer}
.app-header{
    background:#fff;
    border-bottom:1px solid var(--border);
    position:sticky;
    top:0;
    z-index:20;
}
.header-inner{
    max-width:1280px;
    margin:auto;
    min-height:64px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    padding:0 20px;
}
.logo{
    font-size:20px;
    font-weight:700;
    color:var(--text);
}
.nav{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}
.nav a{
    padding:8px 12px;
    border-radius:8px;
    color:#4b5563;
}
.nav a:hover,.nav a.active{
    background:#eff6ff;
    color:var(--primary);
    text-decoration:none;
}
.container{
    width:min(1280px,calc(100% - 32px));
    margin:28px auto 60px;
}
.admin-main{}
.answer-main{
    width:min(760px,calc(100% - 28px));
    margin:24px auto 60px;
}
.page-title{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:20px;
    margin-bottom:20px;
}
.page-title h1{
    margin:0;
    font-size:26px;
}
.page-title p{
    margin:4px 0 0;
    color:var(--muted);
}
.card{
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:20px;
    margin-bottom:18px;
}
.grid{
    display:grid;
    grid-template-columns:repeat(12,minmax(0,1fr));
    gap:16px;
}
.col-12{grid-column:span 12}
.col-8{grid-column:span 8}
.col-6{grid-column:span 6}
.col-4{grid-column:span 4}
.col-3{grid-column:span 3}
label{
    display:block;
    font-weight:600;
    margin-bottom:6px;
}
input[type=text],
input[type=email],
input[type=password],
input[type=number],
input[type=datetime-local],
select,
textarea{
    width:100%;
    border:1px solid #d1d5db;
    border-radius:8px;
    background:#fff;
    padding:10px 12px;
    color:var(--text);
}
textarea{min-height:120px;resize:vertical}
input:focus,select:focus,textarea:focus{
    outline:3px solid rgba(37,99,235,.12);
    border-color:var(--primary);
}
.help{
    color:var(--muted);
    font-size:13px;
    margin-top:5px;
}
.actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
}
.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    border:1px solid transparent;
    border-radius:8px;
    padding:8px 14px;
    text-decoration:none;
    font-weight:600;
}
.btn:hover{text-decoration:none}
.btn-primary{
    background:var(--primary);
    color:#fff;
}
.btn-primary:hover{background:var(--primary-dark)}
.btn-secondary{
    background:#fff;
    border-color:#d1d5db;
    color:#374151;
}
.btn-danger{
    background:#fff;
    border-color:#fecaca;
    color:var(--danger);
}
.btn-success{
    background:#16a34a;
    color:#fff;
}
.btn-warning{
    background:#f59e0b;
    color:#fff;
}
.alert{
    padding:12px 14px;
    border-radius:8px;
    margin-bottom:18px;
}
.alert-success{
    background:#ecfdf5;
    color:#166534;
    border:1px solid #bbf7d0;
}
.alert-warning{
    background:#fffbeb;
    color:#92400e;
    border:1px solid #fde68a;
}
.alert-error{
    background:#fef2f2;
    color:#991b1b;
    border:1px solid #fecaca;
}
.badge{
    display:inline-flex;
    padding:4px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
}
.badge-draft{background:#f3f4f6;color:#4b5563}
.badge-open{background:#dcfce7;color:#166534}
.badge-stop{background:#fef3c7;color:#92400e}
.badge-finished{background:#e5e7eb;color:#374151}
.table-wrap{
    width:100%;
    overflow-x:auto;
}
table{
    width:100%;
    border-collapse:collapse;
    min-width:980px;
}
th,td{
    border-bottom:1px solid var(--border);
    padding:12px 10px;
    text-align:left;
    vertical-align:top;
}
th{
    color:#4b5563;
    font-size:13px;
    background:#fafafa;
}
tr:last-child td{border-bottom:0}
.toolbar{
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
    margin-bottom:18px;
}
.toolbar .search{flex:1 1 280px}
.question-card{
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
    background:#fff;
    margin-top:12px;
}
.question-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:12px;
}
.question-number{
    font-weight:800;
    color:var(--primary);
}
.group-head{
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:center;
}
.group-head h3{margin:0}
.option-row{
    display:grid;
    grid-template-columns:1fr auto;
    gap:8px;
    margin-bottom:8px;
}
.preview-question{
    padding:18px 0;
    border-bottom:1px solid var(--border);
}
.preview-question:last-child{border-bottom:0}
.preview-question h3{
    margin:0 0 10px;
    font-size:17px;
}
.answer-option{
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding:11px 12px;
    margin:7px 0;
    border:1px solid var(--border);
    border-radius:8px;
    background:#fff;
}
.answer-option input{
    margin-top:5px;
}
.metric-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:12px;
}
.metric{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
}
.metric-label{
    color:var(--muted);
    font-size:13px;
}
.metric-value{
    font-size:28px;
    font-weight:800;
    margin-top:4px;
}
.empty{
    padding:40px 20px;
    text-align:center;
    color:var(--muted);
}
.footer{
    color:var(--muted);
    text-align:center;
    font-size:12px;
    padding:20px;
}
.inline-form{display:inline}
.status-form{display:inline-flex;gap:5px}
@media(max-width:900px){
    .col-8,.col-6,.col-4,.col-3{grid-column:span 12}
    .metric-grid{grid-template-columns:repeat(2,1fr)}
    .header-inner{align-items:flex-start;padding-top:12px;padding-bottom:12px}
}
@media(max-width:600px){
    .container{width:min(100% - 20px,1280px);margin-top:18px}
    .answer-main{width:min(100% - 20px,760px)}
    .page-title{display:block}
    .page-title .actions{margin-top:12px}
    .card{padding:15px}
    .metric-grid{grid-template-columns:1fr 1fr}
    .nav a{padding:7px 8px;font-size:13px}
    .btn{width:auto}
}
</style>
</head>
<body>
<?php if ($admin): ?>
<header class="app-header">
<div class="header-inner">
    <a class="logo" href="<?= h(appUrl('list')) ?>">アンケートアプリ</a>
    <nav class="nav">
        <a class="<?= $title === 'アンケート一覧' ? 'active' : '' ?>"
           href="<?= h(appUrl('list')) ?>">アンケート一覧</a>
        <a class="<?= $title === 'kintone設定' ? 'active' : '' ?>"
           href="<?= h(appUrl('kintone')) ?>">kintone設定</a>
        <a class="<?= $title === 'メールサーバ設定' ? 'active' : '' ?>"
           href="<?= h(appUrl('mail')) ?>">メール設定</a>
    </nav>
</div>
</header>
<?php endif; ?>
<?php
}

function renderFooter(): void
{
    ?>
<footer class="footer">アンケートアプリ POC</footer>
</body>
</html>
<?php
}

function renderMessages(?array $flash, ?string $error): void
{
    if ($flash !== null) {
        $class = ($flash['type'] ?? '') === 'warning'
            ? 'alert-warning'
            : 'alert-success';

        echo '<div class="alert ' . h($class) . '">'
            . h($flash['message'] ?? '')
            . '</div>';
    }

    if ($error !== null && $error !== '') {
        echo '<div class="alert alert-error">'
            . h($error)
            . '</div>';
    }
}

/* ============================================================
 * 一覧
 * ========================================================== */

function renderList(array $data, ?array $flash, ?string $error): void
{
    $keyword = getString('q');
    $status = getString('status', 'all');
    $sort = getString('sort', 'updated_desc');

    $surveys = $data['surveys'];

    $filtered = [];

    foreach ($surveys as $item) {
        if ($keyword !== ''
            && mb_stripos((string)$item['title'], $keyword) === false) {
            continue;
        }

        if ($status !== 'all') {
            $map = [
                'open' => STATUS_OPEN,
                'draft' => STATUS_DRAFT,
                'stopped' => STATUS_STOPPED,
                'finished' => STATUS_FINISHED,
            ];

            if (isset($map[$status])
                && ($item['status'] ?? '') !== $map[$status]) {
                continue;
            }
        }

        $filtered[] = $item;
    }

    usort($filtered, static function (array $a, array $b) use ($sort): int {
        return match ($sort) {
            'updated_asc' => strcmp(
                (string)$a['updatedAt'],
                (string)$b['updatedAt']
            ),
            'answers_desc' => surveyAnswerCount(
                ['answers' => []],
                ''
            ) <=> 0,
            default => strcmp(
                (string)$b['updatedAt'],
                (string)$a['updatedAt']
            ),
        };
    });

    /*
     * 回答数・開始日はクロージャ内でdataを参照する必要があるため再ソート。
     */
    if ($sort === 'answers_desc' || $sort === 'answers_asc') {
        usort($filtered, static function (array $a, array $b) use ($data, $sort): int {
            $aa = surveyAnswerCount($data, (string)$a['id']);
            $bb = surveyAnswerCount($data, (string)$b['id']);

            return $sort === 'answers_desc'
                ? $bb <=> $aa
                : $aa <=> $bb;
        });
    }

    if ($sort === 'start_desc' || $sort === 'start_asc') {
        usort($filtered, static function (array $a, array $b) use ($sort): int {
            $aa = (string)($a['startAt'] ?? '');
            $bb = (string)($b['startAt'] ?? '');

            $result = strcmp($bb, $aa);

            return $sort === 'start_desc' ? $result : -$result;
        });
    }

    renderHead('アンケート一覧');
    ?>
<main class="container admin-main">
<div class="page-title">
    <div>
        <h1>アンケート一覧</h1>
        <p>アンケート管理の起点です。</p>
    </div>
    <div class="actions">
        <a class="btn btn-primary"
           href="<?= h(appUrl('edit')) ?>">＋ 新規作成</a>
    </div>
</div>

<?php renderMessages($flash, $error); ?>

<div class="card">
<form method="get" class="toolbar">
    <input type="hidden" name="screen" value="list">
    <div class="search">
        <input type="text"
               name="q"
               value="<?= h($keyword) ?>"
               placeholder="タイトルで検索"
               aria-label="タイトルで検索">
    </div>

    <select name="status" aria-label="ステータス">
        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>すべて</option>
        <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>公開中</option>
        <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>下書き</option>
        <option value="stopped" <?= $status === 'stopped' ? 'selected' : '' ?>>停止</option>
        <option value="finished" <?= $status === 'finished' ? 'selected' : '' ?>>終了</option>
    </select>

    <select name="sort" aria-label="並び順">
        <option value="updated_desc" <?= $sort === 'updated_desc' ? 'selected' : '' ?>>更新日：新しい順</option>
        <option value="updated_asc" <?= $sort === 'updated_asc' ? 'selected' : '' ?>>更新日：古い順</option>
        <option value="answers_desc" <?= $sort === 'answers_desc' ? 'selected' : '' ?>>回答数：多い順</option>
        <option value="answers_asc" <?= $sort === 'answers_asc' ? 'selected' : '' ?>>回答数：少ない順</option>
        <option value="start_desc" <?= $sort === 'start_desc' ? 'selected' : '' ?>>開始日：新しい順</option>
        <option value="start_asc" <?= $sort === 'start_asc' ? 'selected' : '' ?>>開始日：古い順</option>
    </select>

    <button class="btn btn-secondary" type="submit">検索</button>
</form>

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
<?php if ($filtered === []): ?>
<tr><td colspan="7" class="empty">アンケートがありません。</td></tr>
<?php else: ?>
<?php foreach ($filtered as $item): ?>
<?php
$st = (string)$item['status'];
$badge = match ($st) {
    STATUS_OPEN => 'badge-open',
    STATUS_DRAFT => 'badge-draft',
    STATUS_STOPPED => 'badge-stop',
    default => 'badge-finished',
};
?>
<tr>
<td><strong><?= h($item['title']) ?></strong></td>
<td><?= h(str_replace('T', ' ', substr((string)$item['createdAt'], 0, 16))) ?></td>
<td><?= h(str_replace('T', ' ', substr((string)$item['updatedAt'], 0, 16))) ?></td>
<td>
<?= h($item['startAt'] !== ''
    ? str_replace('T', ' ', substr((string)$item['startAt'], 0, 16))
    : '—') ?>
<br>
<?= h($item['endAt'] !== ''
    ? str_replace('T', ' ', substr((string)$item['endAt'], 0, 16))
    : '—') ?>
</td>
<td><span class="badge <?= h($badge) ?>"><?= h($st) ?></span></td>
<td><?= h((string)surveyAnswerCount($data, (string)$item['id'])) ?></td>
<td>
<div class="actions">
<a class="btn btn-secondary"
   href="<?= h(appUrl('edit', ['id' => $item['id']])) ?>">確認・編集</a>
<a class="btn btn-secondary"
   href="<?= h(appUrl('preview', ['id' => $item['id']])) ?>">プレビュー</a>
<a class="btn btn-secondary"
   href="<?= h(appUrl('analytics', ['id' => $item['id']])) ?>">集計</a>
<a class="btn btn-primary"
   href="<?= h(appUrl('send', ['id' => $item['id']])) ?>">送信</a>

<form method="post" class="inline-form">
<input type="hidden" name="action" value="duplicate_survey">
<input type="hidden" name="id" value="<?= h($item['id']) ?>">
<button class="btn btn-secondary" type="submit">複製</button>
</form>

<form method="post" class="inline-form"
      onsubmit="return confirm('このアンケートを削除しますか？');">
<input type="hidden" name="action" value="delete_survey">
<input type="hidden" name="id" value="<?= h($item['id']) ?>">
<button class="btn btn-danger" type="submit">削除</button>
</form>

<?php if ($st === STATUS_DRAFT || $st === STATUS_STOPPED): ?>
<form method="post" class="inline-form">
<input type="hidden" name="action" value="change_status">
<input type="hidden" name="id" value="<?= h($item['id']) ?>">
<input type="hidden" name="status" value="<?= h(STATUS_OPEN) ?>">
<button class="btn btn-success" type="submit">公開</button>
</form>
<?php elseif ($st === STATUS_OPEN): ?>
<form method="post" class="inline-form">
<input type="hidden" name="action" value="change_status">
<input type="hidden" name="id" value="<?= h($item['id']) ?>">
<input type="hidden" name="status" value="<?= h(STATUS_STOPPED) ?>">
<button class="btn btn-warning" type="submit">停止</button>
</form>
<?php endif; ?>
</div>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</main>
<?php
    renderFooter();
}

/* ============================================================
 * 編集
 * ========================================================== */

function renderEdit(array $data, ?array $flash, ?string $error, ?array $survey): void
{
    $isNew = $survey === null;

    if ($isNew) {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'numbering' => 'global',
            'status' => STATUS_DRAFT,
            'groups' => [newGroup()],
        ];
    }

    $groups = normalizeGroups($survey['groups'] ?? []);

    if ($groups === []) {
        $groups = [newGroup()];
    }

    recalculateNumbers($groups, (string)$survey['numbering']);

    renderHead($isNew ? 'アンケート作成' : 'アンケート編集');
    ?>
<main class="container admin-main">
<div class="page-title">
    <div>
        <h1><?= $isNew ? 'アンケート作成' : 'アンケート編集' ?></h1>
        <p>質問・グループ・条件分岐を設定します。</p>
    </div>
    <div class="actions">
        <a class="btn btn-secondary"
           href="<?= h(appUrl('list')) ?>">一覧へ戻る</a>
        <?php if (!$isNew): ?>
        <a class="btn btn-secondary"
           href="<?= h(appUrl('preview', ['id' => $survey['id']])) ?>">プレビュー</a>
        <?php endif; ?>
    </div>
</div>

<?php renderMessages($flash, $error); ?>

<form method="post" id="surveyForm">
<input type="hidden" name="action" value="save_survey">
<input type="hidden" name="id" value="<?= h($survey['id']) ?>">
<input type="hidden" name="groupsJson" id="groupsJson">

<div class="card">
<div class="grid">
<div class="col-12">
<label for="title">タイトル</label>
<input id="title" name="title" type="text"
       maxlength="200" required
       value="<?= h($survey['title']) ?>">
</div>

<div class="col-12">
<label for="description">説明</label>
<textarea id="description"
          name="description"
          maxlength="5000"><?= h($survey['description']) ?></textarea>
</div>

<div class="col-4">
<label for="startAt">開始日時</label>
<input id="startAt" name="startAt"
       type="datetime-local"
       value="<?= h(substr((string)$survey['startAt'], 0, 16)) ?>">
</div>

<div class="col-4">
<label for="endAt">終了日時</label>
<input id="endAt" name="endAt"
       type="datetime-local"
       value="<?= h(substr((string)$survey['endAt'], 0, 16)) ?>">
</div>

<div class="col-4">
<label for="numbering">質問番号</label>
<select id="numbering" name="numbering">
<option value="global" <?= $survey['numbering'] === 'global' ? 'selected' : '' ?>>
全体通番：Q1、Q2、Q3...
</option>
<option value="group" <?= $survey['numbering'] === 'group' ? 'selected' : '' ?>>
グループ単位：Q1-1、Q1-2...
</option>
</select>
</div>
</div>
</div>

<div id="groupsContainer"></div>

<div class="card">
<div class="actions">
<button class="btn btn-primary" type="submit">保存して一覧へ</button>
<a class="btn btn-secondary"
   href="<?= h(appUrl('list')) ?>"
   onclick="return confirm('編集内容を破棄して一覧へ戻りますか？');">
キャンセル
</a>
</div>
</div>
</form>
</main>

<script>
const initialGroups = <?= json_encode(
    $groups,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

let groups = initialGroups;

function esc(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function uid(prefix) {
    return prefix + '_' + Math.random().toString(36).slice(2, 12);
}

function makeQuestion() {
    return {
        id: uid('q'),
        text: '',
        type: 'single',
        required: false,
        options: ['選択肢1'],
        branches: {},
        number: ''
    };
}

function makeGroup() {
    return {
        id: uid('g'),
        title: '新しいグループ',
        questions: [makeQuestion()]
    };
}

function recalc() {
    const mode = document.getElementById('numbering').value;
    let global = 0;

    groups.forEach((group, gi) => {
        let local = 0;

        group.questions.forEach(question => {
            global++;
            local++;
            question.number =
                mode === 'group'
                    ? `Q${gi + 1}-${local}`
                    : `Q${global}`;
        });
    });
}

function render() {
    recalc();

    const root = document.getElementById('groupsContainer');

    root.innerHTML = groups.map((group, gi) => `
        <div class="card" data-group="${gi}">
            <div class="group-head">
                <h3>グループ ${gi + 1}</h3>
                <div class="actions">
                    ${groups.length > 1 ? `
                    <button type="button"
                            class="btn btn-danger"
                            onclick="removeGroup(${gi})">
                        グループ削除
                    </button>` : ''}
                    ${gi > 0 ? `
                    <button type="button"
                            class="btn btn-secondary"
                            onclick="moveGroup(${gi}, -1)">
                        ↑
                    </button>` : ''}
                    ${gi < groups.length - 1 ? `
                    <button type="button"
                            class="btn btn-secondary"
                            onclick="moveGroup(${gi}, 1)">
                        ↓
                    </button>` : ''}
                </div>
            </div>

            <div style="margin-top:12px">
                <label>グループタイトル</label>
                <input type="text"
                       value="${esc(group.title)}"
                       maxlength="200"
                       oninput="groups[${gi}].title=this.value">
            </div>

            <div>
            ${group.questions.map((question, qi) =>
                renderQuestion(group, question, gi, qi)
            ).join('')}
            </div>

            <div class="actions" style="margin-top:12px">
                <button type="button"
                        class="btn btn-secondary"
                        onclick="addQuestion(${gi})">
                    ＋ 質問を追加
                </button>
            </div>
        </div>
    `).join('') + `
        <div class="card">
            <button type="button"
                    class="btn btn-secondary"
                    onclick="addGroup()">
                ＋ グループを追加
            </button>
        </div>
    `;
}

function renderQuestion(group, question, gi, qi) {
    const options = Array.isArray(question.options)
        ? question.options
        : [];

    return `
    <div class="question-card">
        <div class="question-head">
            <div class="question-number">${esc(question.number)}</div>
            <div class="actions">
                ${qi > 0 ? `
                <button type="button"
                        class="btn btn-secondary"
                        onclick="moveQuestion(${gi},${qi},-1)">↑</button>` : ''}
                ${qi < group.questions.length - 1 ? `
                <button type="button"
                        class="btn btn-secondary"
                        onclick="moveQuestion(${gi},${qi},1)">↓</button>` : ''}
                ${group.questions.length > 1 ? `
                <button type="button"
                        class="btn btn-danger"
                        onclick="removeQuestion(${gi},${qi})">削除</button>` : ''}
            </div>
        </div>

        <label>質問文</label>
        <textarea maxlength="5000"
                  oninput="groups[${gi}].questions[${qi}].text=this.value"
                  required>${esc(question.text)}</textarea>

        <div class="grid" style="margin-top:12px">
            <div class="col-6">
                <label>回答形式</label>
                <select onchange="changeType(${gi},${qi},this.value)">
                    <option value="single"
                        ${question.type === 'single' ? 'selected' : ''}>
                        単一選択
                    </option>
                    <option value="multi"
                        ${question.type === 'multi' ? 'selected' : ''}>
                        複数選択
                    </option>
                    <option value="text"
                        ${question.type === 'text' ? 'selected' : ''}>
                        自由記述
                    </option>
                </select>
            </div>

            <div class="col-6">
                <label>必須設定</label>
                <label style="font-weight:400">
                    <input type="checkbox"
                        ${question.required ? 'checked' : ''}
                        onchange="groups[${gi}].questions[${qi}].required=this.checked">
                    必須
                </label>
            </div>
        </div>

        ${question.type === 'single' || question.type === 'multi'
            ? `
            <div style="margin-top:14px">
                <label>選択肢</label>
                ${options.map((option, oi) => `
                    <div class="option-row">
                        <input type="text"
                               value="${esc(option)}"
                               oninput="groups[${gi}].questions[${qi}].options[${oi}]=this.value">
                        <button type="button"
                                class="btn btn-danger"
                                onclick="removeOption(${gi},${qi},${oi})">
                            削除
                        </button>
                    </div>
                `).join('')}
                <button type="button"
                        class="btn btn-secondary"
                        onclick="addOption(${gi},${qi})">
                    ＋ 選択肢を追加
                </button>
            </div>
            `
            : ''}

        ${question.type === 'single'
            ? `
            <div style="margin-top:14px">
                <label>条件分岐</label>
                <div class="help">
                    選択肢ごとに次に表示する質問を指定できます。
                </div>
                ${options.map(option => `
                    <div class="grid" style="margin-top:8px">
                        <div class="col-6">
                            <input type="text"
                                   value="${esc(option)}"
                                   readonly>
                        </div>
                        <div class="col-6">
                            <select onchange="setBranch(${gi},${qi},'${esc(option)}',this.value)">
                                <option value="">通常の順序</option>
                                ${allQuestionTargets().map(target => `
                                    <option value="${esc(target.id)}"
                                        ${question.branches?.[option] === target.id
                                            ? 'selected' : ''}>
                                        ${esc(target.number)}：${esc(target.text)}
                                    </option>
                                `).join('')}
                            </select>
                        </div>
                    </div>
                `).join('')}
            </div>
            `
            : ''}
    </div>`;
}

function allQuestionTargets() {
    return groups.flatMap(group => group.questions);
}

function addGroup() {
    groups.push(makeGroup());
    render();
}

function removeGroup(index) {
    if (!confirm('このグループを削除しますか？')) return;
    groups.splice(index, 1);
    if (!groups.length) groups.push(makeGroup());
    render();
}

function moveGroup(index, direction) {
    const target = index + direction;
    if (target < 0 || target >= groups.length) return;
    [groups[index], groups[target]] =
        [groups[target], groups[index]];
    render();
}

function addQuestion(gi) {
    groups[gi].questions.push(makeQuestion());
    render();
}

function removeQuestion(gi, qi) {
    if (!confirm('この質問を削除しますか？')) return;
    groups[gi].questions.splice(qi, 1);
    if (!groups[gi].questions.length) {
        groups[gi].questions.push(makeQuestion());
    }
    render();
}

function moveQuestion(gi, qi, direction) {
    const target = qi + direction;
    const list = groups[gi].questions;
    if (target < 0 || target >= list.length) return;
    [list[qi], list[target]] = [list[target], list[qi]];
    render();
}

function changeType(gi, qi, type) {
    groups[gi].questions[qi].type = type;

    if (type === 'text') {
        groups[gi].questions[qi].options = [];
        groups[gi].questions[qi].branches = {};
    } else if (!groups[gi].questions[qi].options.length) {
        groups[gi].questions[qi].options = ['選択肢1'];
    }

    render();
}

function addOption(gi, qi) {
    groups[gi].questions[qi].options.push(
        `選択肢${groups[gi].questions[qi].options.length + 1}`
    );
    render();
}

function removeOption(gi, qi, oi) {
    groups[gi].questions[qi].options.splice(oi, 1);
    render();
}

function setBranch(gi, qi, option, target) {
    if (!groups[gi].questions[qi].branches) {
        groups[gi].questions[qi].branches = {};
    }

    if (target === '') {
        delete groups[gi].questions[qi].branches[option];
    } else {
        groups[gi].questions[qi].branches[option] = target;
    }
}

document.getElementById('numbering')
    .addEventListener('change', render);

document.getElementById('surveyForm')
    .addEventListener('submit', function() {
        recalc();
        document.getElementById('groupsJson').value =
            JSON.stringify(groups);
    });

render();
</script>
<?php
    renderFooter();
}

/* ============================================================
 * プレビュー
 * ========================================================== */

function renderPreview(array $survey): void
{
    renderHead('プレビュー');
    ?>
<main class="container admin-main">
<div class="page-title">
    <div>
        <h1>プレビュー</h1>
        <p>回答データは保存されません。実メール送信も行いません。</p>
    </div>
    <div class="actions">
        <a class="btn btn-secondary"
           href="<?= h(appUrl('list')) ?>">一覧へ戻る</a>
        <a class="btn btn-primary"
           href="<?= h(appUrl('edit', ['id' => $survey['id']])) ?>">編集へ戻る</a>
    </div>
</div>

<div class="card">
<h2><?= h($survey['title']) ?></h2>
<?php if ($survey['description'] !== ''): ?>
<p><?= nl2br(h($survey['description'])) ?></p>
<?php endif; ?>
</div>

<?php foreach ($survey['groups'] as $group): ?>
<div class="card">
<h3><?= h($group['title']) ?></h3>

<?php foreach ($group['questions'] as $question): ?>
<div class="preview-question">
<h3>
<?= h($question['number']) ?>　
<?= h($question['text']) ?>
<?php if (!empty($question['required'])): ?>
<span class="badge badge-open">必須</span>
<?php endif; ?>
</h3>

<?php if ($question['type'] === QUESTION_TEXT): ?>
<textarea disabled placeholder="自由記述"></textarea>
<?php elseif ($question['type'] === QUESTION_SINGLE): ?>
<?php foreach ($question['options'] as $option): ?>
<div class="answer-option">
<input type="radio" disabled>
<span><?= h($option) ?></span>
</div>
<?php endforeach; ?>
<?php else: ?>
<?php foreach ($question['options'] as $option): ?>
<div class="answer-option">
<input type="checkbox" disabled>
<span><?= h($option) ?></span>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($question['branches'])): ?>
<div class="help">
条件分岐あり：
<?php foreach ($question['branches'] as $option => $target): ?>
<?= h($option) ?> → <?= h($target) ?>　
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
</main>
<?php
    renderFooter();
}

/* ============================================================
 * kintone設定
 * ========================================================== */

function renderKintone(array $data, ?array $flash, ?string $error): void
{
    $config = $data['kintone'];
    $fields = $_SESSION['kintone_fields'] ?? [];

    renderHead('kintone設定');
    ?>
<main class="container admin-main">
<div class="page-title">
<div>
<h1>kintone設定</h1>
<p>パスワードは保存せず、各処理のPOST時に入力します。</p>
</div>
<div class="actions">
<a class="btn btn-secondary"
   href="<?= h(appUrl('list')) ?>">一覧へ戻る</a>
</div>
</div>

<?php renderMessages($flash, $error); ?>

<div class="card">
<h2>接続設定</h2>
<form method="post">
<input type="hidden" name="action" value="save_kintone">

<div class="grid">
<div class="col-6">
<label>サブドメイン</label>
<input type="text"
       name="subdomain"
       value="<?= h($config['subdomain']) ?>"
       placeholder="example.cybozu.com">
<div class="help">URL付き・ドメインのみ・サブドメインのみを指定できます。</div>
</div>

<div class="col-6">
<label>顧客管理アプリID</label>
<input type="number"
       name="appId"
       min="1"
       value="<?= h($config['appId']) ?>">
</div>

<div class="col-6">
<label>ログイン名</label>
<input type="text"
       name="loginName"
       value="<?= h($config['loginName']) ?>">
</div>

<div class="col-6">
<label>Proxy</label>
<input type="text"
       name="proxy"
       value="<?= h($config['proxy']) ?>"
       placeholder="proxy.example:8080">
</div>

<div class="col-12">
<label>
<input type="checkbox"
       name="verifySsl"
       <?= !empty($config['verifySsl']) ? 'checked' : '' ?>>
SSL証明書を検証する
</label>
<div class="help">
POCでは必要に応じて検証を無効化できます。
</div>
</div>
</div>

<div class="actions" style="margin-top:16px">
<button class="btn btn-primary" type="submit">設定保存</button>
</div>
</form>
</div>

<div class="card">
<h2>接続テスト</h2>
<p>パスワードはこのPOST処理中のみ使用し、保存しません。</p>
<form method="post">
<input type="hidden" name="action" value="test_kintone">
<label>kintoneパスワード</label>
<input type="password"
       name="password"
       autocomplete="current-password"
       required>
<div class="actions" style="margin-top:12px">
<button class="btn btn-primary" type="submit">接続テスト</button>
</div>
</form>
</div>

<div class="card">
<h2>項目一覧</h2>
<form method="post">
<input type="hidden" name="action" value="fetch_kintone_fields">
<label>kintoneパスワード</label>
<input type="password" name="password" required>
<div class="actions" style="margin-top:12px">
<button class="btn btn-secondary" type="submit">項目一覧を再取得</button>
</div>
</form>

<?php if (is_array($fields) && $fields !== []): ?>
<div class="table-wrap" style="margin-top:18px">
<table>
<thead>
<tr><th>フィールドコード</th><th>ラベル</th><th>タイプ</th></tr>
</thead>
<tbody>
<?php foreach ($fields as $code => $field): ?>
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
</div>

<div class="card">
<h2>顧客情報同期</h2>
<form method="post">
<input type="hidden" name="action" value="sync_kintone">

<div class="grid">
<div class="col-6">
<label>組織名</label>
<select name="organizationField">
<option value="">指定なし</option>
<?php foreach ($fields as $code => $field): ?>
<option value="<?= h($code) ?>"
 <?= $config['fieldMap']['organization'] === $code ? 'selected' : '' ?>>
<?= h(($field['label'] ?? $code) . ' [' . $code . ']') ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="col-6">
<label>氏名</label>
<select name="nameField">
<option value="">指定なし</option>
<?php foreach ($fields as $code => $field): ?>
<option value="<?= h($code) ?>"
 <?= $config['fieldMap']['name'] === $code ? 'selected' : '' ?>>
<?= h(($field['label'] ?? $code) . ' [' . $code . ']') ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="col-6">
<label>メールアドレス</label>
<select name="emailField" required>
<option value="">指定してください</option>
<?php foreach ($fields as $code => $field): ?>
<option value="<?= h($code) ?>"
 <?= $config['fieldMap']['email'] === $code ? 'selected' : '' ?>>
<?= h(($field['label'] ?? $code) . ' [' . $code . ']') ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="col-6">
<label>部署名</label>
<select name="departmentField">
<option value="">指定なし</option>
<?php foreach ($fields as $code => $field): ?>
<option value="<?= h($code) ?>"
 <?= $config['fieldMap']['department'] === $code ? 'selected' : '' ?>>
<?= h(($field['label'] ?? $code) . ' [' . $code . ']') ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="col-6">
<label>電話番号</label>
<select name="phoneField">
<option value="">指定なし</option>
<?php foreach ($fields as $code => $field): ?>
<option value="<?= h($code) ?>"
 <?= $config['fieldMap']['phone'] === $code ? 'selected' : '' ?>>
<?= h(($field['label'] ?? $code) . ' [' . $code . ']') ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="col-6">
<label>住所</label>
<?php foreach ($fields as $code => $field): ?>
<label style="font-weight:400">
<input type="checkbox"
       name="addressFields[]"
       value="<?= h($code) ?>"
       <?= in_array($code, $config['fieldMap']['address'], true)
            ? 'checked' : '' ?>>
<?= h(($field['label'] ?? $code) . ' [' . $code . ']') ?>
</label>
<?php endforeach; ?>
</div>

<div class="col-12">
<label>kintoneパスワード</label>
<input type="password" name="password" required>
</div>
</div>

<div class="actions" style="margin-top:16px">
<button class="btn btn-primary" type="submit">顧客情報を同期</button>
</div>
</form>
</div>
</main>
<?php
    renderFooter();
}

/* ============================================================
 * メール設定
 * ========================================================== */

function renderMail(array $data, ?array $flash, ?string $error): void
{
    $config = $data['mailSettings'];

    renderHead('メールサーバ設定');
    ?>
<main class="container admin-main">
<div class="page-title">
<div>
<h1>メールサーバ設定</h1>
<p>SMTPパスワードは保存しません。</p>
</div>
<div class="actions">
<a class="btn btn-secondary"
   href="<?= h(appUrl('list')) ?>">一覧へ戻る</a>
</div>
</div>

<?php renderMessages($flash, $error); ?>

<div class="card">
<form method="post">
<input type="hidden" name="action" value="save_mail">

<div class="grid">
<div class="col-8">
<label>SMTPサーバ</label>
<input type="text" name="host"
       value="<?= h($config['host']) ?>" required>
</div>

<div class="col-4">
<label>SMTPポート</label>
<input type="number" name="port"
       min="1" max="65535"
       value="<?= h($config['port']) ?>" required>
</div>

<div class="col-4">
<label>暗号化方式</label>
<select name="encryption">
<option value="ssl" <?= $config['encryption'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
<option value="tls" <?= $config['encryption'] === 'tls' ? 'selected' : '' ?>>TLS</option>
<option value="none" <?= $config['encryption'] === 'none' ? 'selected' : '' ?>>なし</option>
</select>
</div>

<div class="col-8">
<label>
<input type="checkbox"
       name="auth"
       <?= !empty($config['auth']) ? 'checked' : '' ?>>
SMTP認証を使用する
</label>
</div>

<div class="col-6">
<label>SMTPユーザー名</label>
<input type="text" name="username"
       value="<?= h($config['username']) ?>">
</div>

<div class="col-6">
<label>送信元メールアドレス</label>
<input type="email" name="fromEmail"
       value="<?= h($config['fromEmail']) ?>" required>
</div>

<div class="col-6">
<label>送信元名</label>
<input type="text" name="fromName"
       value="<?= h($config['fromName']) ?>">
</div>

<div class="col-6">
<label>返信先メールアドレス</label>
<input type="email" name="replyTo"
       value="<?= h($config['replyTo']) ?>">
</div>
</div>

<div class="actions" style="margin-top:16px">
<button class="btn btn-primary" type="submit">設定保存</button>
</div>
</form>
</div>

<div class="card">
<h2>接続テスト</h2>
<form method="post">
<input type="hidden" name="action" value="test_mail">

<label>SMTPパスワード</label>
<input type="password" name="password" required>

<div class="actions" style="margin-top:12px">
<button class="btn btn-primary" type="submit">接続テスト</button>
</div>
</form>
</div>

<div class="card">
<h2>テストメール</h2>
<form method="post">
<input type="hidden" name="action" value="send_test_mail">

<label>送信先</label>
<input type="email" name="to" required>

<label style="margin-top:12px">SMTPパスワード</label>
<input type="password" name="password" required>

<div class="actions" style="margin-top:12px">
<button class="btn btn-primary" type="submit">テストメール送信</button>
</div>
</form>
</div>
</main>
<?php
    renderFooter();
}

/* ============================================================
 * 送信
 * ========================================================== */

function renderSend(array $data, array $survey, ?array $flash, ?string $error): void
{
    $customers = $data['customers'] ?? [];
    $history = [];

    foreach ($data['sendHistory'] ?? [] as $item) {
        if (($item['surveyId'] ?? '') === $survey['id']) {
            $history[] = $item;
        }
    }

    renderHead('顧客選択・メール送信');
    ?>
<main class="container admin-main">
<div class="page-title">
<div>
<h1>顧客選択・メール送信</h1>
<p>対象アンケート：<?= h($survey['title']) ?></p>
</div>
<div class="actions">
<a class="btn btn-secondary"
   href="<?= h(appUrl('list')) ?>">一覧へ戻る</a>
</div>
</div>

<?php renderMessages($flash, $error); ?>

<div class="card">
<h2>送信内容</h2>
<form method="post" id="sendForm">
<input type="hidden" name="action" value="send_campaign">
<input type="hidden" name="surveyId" value="<?= h($survey['id']) ?>">

<div class="grid">
<div class="col-12">
<label>件名</label>
<input type="text" name="subject"
       value="<?= h($survey['title'] . ' のご回答のお願い') ?>"
       required>
</div>

<div class="col-12">
<label>本文</label>
<textarea name="body" required>{顧客名} 様

以下のアンケートへのご回答をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>
<div class="help">{顧客名} と {アンケートURL} が使用できます。</div>
</div>

<div class="col-6">
<label>処理種別</label>
<select name="mode">
<option value="send">一括送信</option>
<option value="resend">再送</option>
<option value="reminder">リマインド</option>
</select>
</div>

<div class="col-6">
<label>SMTPパスワード</label>
<input type="password" name="password" required>
</div>
</div>

<h3 style="margin-top:24px">顧客選択</h3>

<?php if ($customers === []): ?>
<div class="empty">
顧客データがありません。kintone設定から同期してください。
</div>
<?php else: ?>
<div class="toolbar">
<input type="text"
       id="customerSearch"
       placeholder="顧客名・組織名・メールアドレスで絞り込み">
</div>

<div class="table-wrap">
<table id="customerTable">
<thead>
<tr>
<th><input type="checkbox" id="selectAll"></th>
<th>組織名</th>
<th>氏名</th>
<th>メールアドレス</th>
<th>部署</th>
</tr>
</thead>
<tbody>
<?php foreach ($customers as $customer): ?>
<tr data-search="<?= h(
    ($customer['organization'] ?? '') . ' '
    . ($customer['name'] ?? '') . ' '
    . ($customer['email'] ?? '') . ' '
    . ($customer['department'] ?? '')
) ?>">
<td>
<input type="checkbox"
       name="customerIds[]"
       value="<?= h($customer['id']) ?>"
       class="customer-check">
</td>
<td><?= h($customer['organization']) ?></td>
<td><?= h($customer['name']) ?></td>
<td><?= h($customer['email']) ?></td>
<td><?= h($customer['department']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<div class="actions" style="margin-top:18px">
<button class="btn btn-primary" type="submit">
送信内容を確認して送信
</button>
</div>
</form>
</div>

<div class="card">
<h2>送信履歴</h2>
<?php if ($history === []): ?>
<div class="empty">送信履歴はありません。</div>
<?php else: ?>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>メールアドレス</th>
<th>種別</th>
<th>結果</th>
</tr>
</thead>
<tbody>
<?php foreach (array_reverse($history) as $item): ?>
<tr>
<td><?= h(str_replace('T', ' ', substr((string)$item['createdAt'], 0, 16))) ?></td>
<td><?= h($item['email']) ?></td>
<td><?= h($item['mode']) ?></td>
<td><?= h($item['status'] === 'success' ? '成功' : '失敗') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
</main>

<script>
const search = document.getElementById('customerSearch');
const all = document.getElementById('selectAll');

if (search) {
    search.addEventListener('input', function() {
        const value = this.value.toLowerCase();

        document.querySelectorAll('#customerTable tbody tr')
            .forEach(row => {
                row.style.display =
                    row.dataset.search.toLowerCase().includes(value)
                        ? ''
                        : 'none';
            });
    });
}

if (all) {
    all.addEventListener('change', function() {
        document.querySelectorAll('.customer-check')
            .forEach(check => {
                check.checked = this.checked;
            });
    });
}
</script>
<?php
    renderFooter();
}

/* ============================================================
 * 集計
 * ========================================================== */

function renderAnalytics(array $data, array $survey, ?array $flash, ?string $error): void
{
    $answers = array_values(
        array_filter(
            $data['answers'],
            static fn($answer) =>
                ($answer['surveyId'] ?? '') === $survey['id']
        )
    );

    $customerCount = count($data['customers']);

    $sentCustomerIds = [];

    foreach ($data['sendHistory'] as $history) {
        if (($history['surveyId'] ?? '') !== $survey['id']) {
            continue;
        }

        if (($history['status'] ?? '') === 'success') {
            $sentCustomerIds[$history['customerId'] ?? ''] = true;
        }
    }

    $sentCount = count($sentCustomerIds);
    $answerCount = count($answers);
    $unregistered = 0;

    foreach ($answers as $answer) {
        $matched = false;

        foreach ($data['customers'] as $customer) {
            if (($customer['id'] ?? '') === ($answer['customerId'] ?? '')) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            $unregistered++;
        }
    }

    $unanswered = max(0, $sentCount - $answerCount);
    $rate = $sentCount > 0
        ? round(($answerCount / $sentCount) * 100, 1)
        : 0;

    renderHead('回答集計・分析');
    ?>
<main class="container admin-main">
<div class="page-title">
<div>
<h1>回答集計・分析</h1>
<p>対象アンケート：<?= h($survey['title']) ?></p>
</div>
<div class="actions">
<a class="btn btn-secondary"
   href="<?= h(appUrl('list')) ?>">一覧へ戻る</a>
</div>
</div>

<?php renderMessages($flash, $error); ?>

<div class="metric-grid">
<div class="metric">
<div class="metric-label">送信対象者数</div>
<div class="metric-value"><?= h($sentCount) ?></div>
</div>
<div class="metric">
<div class="metric-label">回答数</div>
<div class="metric-value"><?= h($answerCount) ?></div>
</div>
<div class="metric">
<div class="metric-label">未回答数</div>
<div class="metric-value"><?= h($unanswered) ?></div>
</div>
<div class="metric">
<div class="metric-label">回答率</div>
<div class="metric-value"><?= h($rate) ?>%</div>
</div>
</div>

<div class="card" style="margin-top:18px">
<h2>設問別集計</h2>

<?php if ($answers === []): ?>
<div class="empty">現在、回答データはありません</div>
<?php else: ?>

<?php foreach (flattenQuestions($survey) as $question): ?>
<?php
$counts = [];

foreach ($question['options'] ?? [] as $option) {
    $counts[$option] = 0;
}

foreach ($answers as $answer) {
    $value = $answer['answers'][$question['id']] ?? null;

    if (is_array($value)) {
        foreach ($value as $item) {
            if (isset($counts[$item])) {
                $counts[$item]++;
            }
        }
    } elseif (is_string($value) && isset($counts[$value])) {
        $counts[$value]++;
    }
}
?>
<div class="preview-question">
<h3><?= h($question['number']) ?>　<?= h($question['text']) ?></h3>

<?php if ($question['type'] === QUESTION_TEXT): ?>
<p class="help">自由記述回答</p>
<?php else: ?>
<table style="min-width:0">
<thead><tr><th>選択肢</th><th>回答数</th></tr></thead>
<tbody>
<?php foreach ($counts as $option => $count): ?>
<tr>
<td><?= h($option) ?></td>
<td><?= h($count) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>
</div>

<div class="card">
<h2>個別回答</h2>

<?php if ($answers === []): ?>
<div class="empty">現在、回答データはありません</div>
<?php else: ?>
<?php foreach ($answers as $answer): ?>
<div class="question-card">
<div class="help">
回答ID：<?= h($answer['id']) ?>
　
<?= h(str_replace('T', ' ', substr((string)$answer['createdAt'], 0, 16))) ?>
</div>

<?php foreach (flattenQuestions($survey) as $question): ?>
<?php
$value = $answer['answers'][$question['id']] ?? '';
if (is_array($value)) {
    $value = implode(', ', $value);
}
?>
<p>
<strong><?= h($question['number']) ?>　<?= h($question['text']) ?></strong><br>
<?= nl2br(h($value)) ?>
</p>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</main>
<?php
    renderFooter();
}

/* ============================================================
 * 回答
 * ========================================================== */

function renderAnswer(array $survey, ?string $error): void
{
    $draft = $_SESSION['answer_draft'] ?? [];
    $answers = (
        is_array($draft)
        && ($draft['surveyId'] ?? '') === $survey['id']
        && is_array($draft['answers'] ?? null)
    ) ? $draft['answers'] : [];

    $questions = visibleQuestions($survey, $answers);

    renderHead('アンケート回答', false);
    ?>
<main class="answer-main">
<div class="page-title">
<div>
<h1><?= h($survey['title']) ?></h1>
<?php if ($survey['description'] !== ''): ?>
<p><?= nl2br(h($survey['description'])) ?></p>
<?php endif; ?>
</div>
</div>

<?php if ($error !== null): ?>
<div class="alert alert-error"><?= h($error) ?></div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="action" value="confirm_answer">
<input type="hidden" name="surveyId" value="<?= h($survey['id']) ?>">

<div class="card">
<?php foreach ($questions as $question): ?>
<?php
$id = (string)$question['id'];
$value = $answers[$id] ?? '';
?>
<div class="preview-question">
<h3>
<?= h($question['number']) ?>　
<?= h($question['text']) ?>
<?php if (!empty($question['required'])): ?>
<span class="badge badge-open">必須</span>
<?php endif; ?>
</h3>

<?php if ($question['type'] === QUESTION_TEXT): ?>
<textarea name="answers[<?= h($id) ?>]"
          maxlength="10000"><?= h(is_string($value) ? $value : '') ?></textarea>

<?php elseif ($question['type'] === QUESTION_SINGLE): ?>

<?php foreach ($question['options'] as $option): ?>
<label class="answer-option">
<input type="radio"
       name="answers[<?= h($id) ?>]"
       value="<?= h($option) ?>"
       <?= $value === $option ? 'checked' : '' ?>>
<span><?= h($option) ?></span>
</label>
<?php endforeach; ?>

<?php else: ?>

<?php
$current = is_array($value) ? $value : [];
?>
<?php foreach ($question['options'] as $option): ?>
<label class="answer-option">
<input type="checkbox"
       name="answers[<?= h($id) ?>][]"
       value="<?= h($option) ?>"
       <?= in_array($option, $current, true) ? 'checked' : '' ?>>
<span><?= h($option) ?></span>
</label>
<?php endforeach; ?>

<?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<div class="actions">
<button class="btn btn-primary" type="submit">回答を確認</button>
</div>
</form>
</main>
<?php
    renderFooter();
}

/* ============================================================
 * 回答確認
 * ========================================================== */

function renderConfirm(array $survey, ?string $error): void
{
    $draft = $_SESSION['answer_draft'] ?? null;

    if (!is_array($draft)
        || ($draft['surveyId'] ?? '') !== $survey['id']) {
        $draft = [
            'surveyId' => $survey['id'],
            'answers' => [],
        ];
    }

    $answers = is_array($draft['answers'] ?? null)
        ? $draft['answers']
        : [];

    $questions = visibleQuestions($survey, $answers);

    renderHead('回答確認', false);
    ?>
<main class="answer-main">
<div class="page-title">
<div>
<h1>回答確認</h1>
<p><?= h($survey['title']) ?></p>
</div>
</div>

<?php if ($error !== null): ?>
<div class="alert alert-error"><?= h($error) ?></div>
<?php endif; ?>

<div class="card">
<?php foreach ($questions as $question): ?>
<?php
$value = $answers[$question['id']] ?? '';
if (is_array($value)) {
    $value = implode('、', $value);
}
?>
<div class="preview-question">
<h3><?= h($question['number']) ?>　<?= h($question['text']) ?></h3>
<p><?= nl2br(h($value)) ?></p>
</div>
<?php endforeach; ?>
</div>

<div class="actions">
<form method="post">
<input type="hidden" name="action" value="edit_answer">
<input type="hidden" name="surveyId" value="<?= h($survey['id']) ?>">
<button class="btn btn-secondary" type="submit">修正する</button>
</form>

<form method="post">
<input type="hidden" name="action" value="submit_answer">
<input type="hidden" name="surveyId" value="<?= h($survey['id']) ?>">
<button class="btn btn-primary" type="submit">回答を送信</button>
</form>
</div>
</main>
<?php
    renderFooter();
}

/* ============================================================
 * 完了
 * ========================================================== */

function renderComplete(array $survey, ?array $flash, ?string $error): void
{
    renderHead('回答完了', false);
    ?>
<main class="answer-main">
<div class="card" style="text-align:center;padding:48px 24px">
<div style="font-size:48px">✓</div>
<h1>回答ありがとうございました</h1>
<p><?= h($survey['title']) ?></p>

<?php if ($error !== null): ?>
<div class="alert alert-error"><?= h($error) ?></div>
<?php else: ?>
<p>回答を正常に受け付けました。</p>
<?php endif; ?>

<p class="help">
この回答フローはここで終了します。
</p>
</div>
</main>
<?php
    renderFooter();
}

/* ============================================================
 * 画面ディスパッチ
 * ========================================================== */

switch ($screen) {
    case 'list':
        renderList($data, $flash, $globalError);
        break;

    case 'edit':
        renderEdit($data, $flash, $globalError, $survey);
        break;

    case 'preview':
        if ($survey !== null) {
            renderPreview($survey);
        } else {
            renderList($data, $flash, $globalError);
        }
        break;

    case 'send':
        if ($survey !== null) {
            renderSend($data, $survey, $flash, $globalError);
        } else {
            renderList($data, $flash, $globalError);
        }
        break;

    case 'analytics':
        if ($survey !== null) {
            renderAnalytics($data, $survey, $flash, $globalError);
        } else {
            renderList($data, $flash, $globalError);
        }
        break;

    case 'kintone':
        renderKintone($data, $flash, $globalError);
        break;

    case 'mail':
        renderMail($data, $flash, $globalError);
        break;

    case 'answer':
        if ($survey !== null) {
            if (($survey['status'] ?? '') !== STATUS_OPEN) {
                renderHead('アンケート回答', false);
                ?>
<main class="answer-main">
<div class="card">
<h1>回答できません</h1>
<p>このアンケートは現在回答を受け付けていません。</p>
</div>
</main>
<?php
                renderFooter();
            } else {
                renderAnswer($survey, $globalError);
            }
        } else {
            renderHead('アンケート回答', false);
            ?>
<main class="answer-main">
<div class="card">
<h1>アンケートが見つかりません</h1>
<p>指定されたアンケートは存在しません。</p>
</div>
</main>
<?php
            renderFooter();
        }
        break;

    case 'confirm':
        if ($survey !== null) {
            renderConfirm($survey, $globalError);
        } else {
            renderHead('回答確認', false);
            ?>
<main class="answer-main">
<div class="card">
<h1>アンケートが見つかりません</h1>
</div>
</main>
<?php
            renderFooter();
        }
        break;

    case 'complete':
        if ($survey !== null) {
            renderComplete($survey, $flash, $globalError);
        } else {
            renderHead('回答完了', false);
            ?>
<main class="answer-main">
<div class="card">
<h1>回答完了</h1>
<p>指定されたアンケートが見つかりません。</p>
</div>
</main>
<?php
            renderFooter();
        }
        break;

    default:
        renderList($data, $flash, $globalError);
        break;
}

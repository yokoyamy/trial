<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 *
 * Apache 2.4 / PHP 8.5
 * DBなし
 * PHP cURLなし
 * PHP mail()なし
 * 単一 index.php
 *
 * 外部サービス:
 *   kintone REST API
 *   SMTP
 *
 * kintone認証:
 *   X-Cybozu-Authorization
 *
 * 重要:
 *   GET + URLクエリのkintone APIには
 *   Content-Type: application/json を付けない。
 */

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'app.dat.php';
const TIMEZONE = 'Asia/Tokyo';

date_default_timezone_set(TIMEZONE);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$scriptDir = str_replace(
    '\\',
    '/',
    dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))
);

$cookiePath = ($scriptDir === '.' || $scriptDir === '')
    ? '/'
    : rtrim($scriptDir, '/') . '/';

$isHttps = !empty($_SERVER['HTTPS'])
    && strtolower((string)$_SERVER['HTTPS']) !== 'off';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => $cookiePath,
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

/* =========================================================
 * 共通
 * ========================================================= */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function jsonEncode(mixed $value): string
{
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('データをJSON化できません。');
    }

    return $json;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

function uid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function getString(string $name, string $default = ''): string
{
    if (!isset($_GET[$name]) || !is_scalar($_GET[$name])) {
        return $default;
    }

    return trim((string)$_GET[$name]);
}

function postString(string $name, string $default = ''): string
{
    if (!isset($_POST[$name]) || !is_scalar($_POST[$name])) {
        return $default;
    }

    return trim((string)$_POST[$name]);
}

function postArray(string $name): array
{
    return isset($_POST[$name]) && is_array($_POST[$name])
        ? $_POST[$name]
        : [];
}

function validateId(string $id): bool
{
    return preg_match(
        '/^[A-Za-z0-9_-]{1,100}$/',
        $id
    ) === 1;
}

function validEmail(string $email): bool
{
    return filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    ) !== false;
}

function validDateTime(string $value): bool
{
    if ($value === '') {
        return true;
    }

    $dt = DateTimeImmutable::createFromFormat(
        'Y-m-d\TH:i',
        $value
    );

    return $dt !== false
        && $dt->format('Y-m-d\TH:i') === $value;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function takeFlash(): ?array
{
    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($value) ? $value : null;
}

function redirectTo(
    string $screen,
    array $params = []
): never {
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

    $query = array_merge(
        ['screen' => $screen],
        $params
    );

    $url = (string)(
        $_SERVER['SCRIPT_NAME'] ?? 'index.php'
    );

    $url .= '?'
        . http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

    header('Location: ' . $url, true, 303);
    exit;
}

/* =========================================================
 * データ保存
 * ========================================================= */

function defaultData(): array
{
    return [
        'surveys' => [],
        'answers' => [],
        'customers' => [],
        'sendHistory' => [],

        'kintone' => [
            'subdomain' => '',
            'appId' => '',
            'username' => '',
            'proxy' => '',
            'sslVerify' => true,
            'connection' => '未設定',
            'connectionDetail' => '',
            'fields' => [],
            'mappings' => [
                'org' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
        ],

        'mailSettings' => [
            'server' => '',
            'port' => 587,
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'fromEmail' => '',
            'fromName' => '',
            'replyTo' => '',
            'connection' => '未設定',
            'connectionDetail' => '',
        ],
    ];
}

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0770, true)
            && !is_dir(DATA_DIR)) {
            throw new RuntimeException(
                'データ保存ディレクトリを作成できません。'
            );
        }
    }

    if (!is_writable(DATA_DIR)) {
        throw new RuntimeException(
            'データ保存ディレクトリに書き込み権限がありません。'
        );
    }

    $htaccess = DATA_DIR
        . DIRECTORY_SEPARATOR
        . '.htaccess';

    if (!is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "Options -Indexes\n"
            . "<FilesMatch \"\\.(dat|json|tmp)(\\.php)?$\">\n"
            . "Require all denied\n"
            . "</FilesMatch>\n",
            LOCK_EX
        );
    }
}

function readData(): array
{
    ensureDataDir();

    if (!is_file(DATA_FILE)) {
        return defaultData();
    }

    $fp = @fopen(DATA_FILE, 'rb');

    if ($fp === false) {
        throw new RuntimeException(
            'データファイルを開けません。'
        );
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            throw new RuntimeException(
                'データファイルをロックできません。'
            );
        }

        $contents = stream_get_contents($fp);

        flock($fp, LOCK_UN);
        fclose($fp);
    } catch (Throwable $e) {
        @fclose($fp);
        throw $e;
    }

    if ($contents === false
        || trim($contents) === '') {
        return defaultData();
    }

    $data = json_decode(
        $contents,
        true
    );

    if (!is_array($data)) {
        throw new RuntimeException(
            '保存データが破損しています。'
        );
    }

    return array_replace_recursive(
        defaultData(),
        $data
    );
}

function saveData(array $data): void
{
    ensureDataDir();

    /*
     * kintone/SMTPパスワードは保存しない。
     */
    unset($data['kintone']['password']);
    unset($data['mailSettings']['password']);

    $json = jsonEncode($data);

    $tmp = DATA_FILE
        . '.'
        . bin2hex(random_bytes(8))
        . '.tmp';

    $fp = @fopen($tmp, 'xb');

    if ($fp === false) {
        throw new RuntimeException(
            'データ保存用の一時ファイルを作成できません。'
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException(
                'データ保存用ファイルをロックできません。'
            );
        }

        $length = strlen($json);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite(
                $fp,
                substr($json, $offset)
            );

            if ($written === false || $written === 0) {
                throw new RuntimeException(
                    'データを書き込めません。'
                );
            }

            $offset += $written;
        }

        if (!fflush($fp)) {
            throw new RuntimeException(
                'データの書き込みを確定できません。'
            );
        }

        if (function_exists('fsync')) {
            @fsync($fp);
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        if (!@rename($tmp, DATA_FILE)) {
            throw new RuntimeException(
                'データファイルを更新できません。'
            );
        }

        if (!is_file(DATA_FILE)) {
            throw new RuntimeException(
                'データ保存結果を確認できません。'
            );
        }
    } catch (Throwable $e) {
        @fclose($fp);
        @unlink($tmp);
        throw $e;
    }
}

/* =========================================================
 * アンケート
 * ========================================================= */

function normalizeQuestion(array $q): array
{
    $type = (string)($q['type'] ?? 'single');

    if (!in_array(
        $type,
        ['single', 'multiple', 'free'],
        true
    )) {
        $type = 'single';
    }

    $options = [];

    foreach (($q['options'] ?? []) as $option) {
        if (!is_scalar($option)) {
            continue;
        }

        $option = trim((string)$option);

        if ($option !== '') {
            $options[] = $option;
        }
    }

    if ($type === 'free') {
        $options = [];
    }

    $branches = [];

    if (is_array($q['branches'] ?? null)) {
        foreach ($q['branches'] as $option => $target) {
            if (!is_scalar($target)) {
                continue;
            }

            $target = trim((string)$target);

            if ($target !== ''
                && validateId($target)) {
                $branches[(string)$option] = $target;
            }
        }
    }

    return [
        'id' => validateId(
            (string)($q['id'] ?? '')
        )
            ? (string)$q['id']
            : uid('q'),

        'number' => '',
        'text' => trim(
            (string)($q['text'] ?? '')
        ),
        'type' => $type,
        'required' => !empty($q['required']),
        'options' => array_values($options),
        'branches' => $branches,
    ];
}

function normalizeSurvey(array $survey): array
{
    $groups = [];

    foreach (($survey['groups'] ?? []) as $group) {
        if (!is_array($group)) {
            continue;
        }

        $questions = [];

        foreach (($group['questions'] ?? []) as $question) {
            if (is_array($question)) {
                $questions[] =
                    normalizeQuestion($question);
            }
        }

        $groups[] = [
            'id' => validateId(
                (string)($group['id'] ?? '')
            )
                ? (string)$group['id']
                : uid('g'),

            'title' => trim(
                (string)($group['title'] ?? '')
            ),

            'questions' => $questions,
        ];
    }

    $status = (string)(
        $survey['status'] ?? 'draft'
    );

    if (!in_array(
        $status,
        ['draft', 'published', 'stopped', 'ended'],
        true
    )) {
        $status = 'draft';
    }

    $result = [
        'id' => validateId(
            (string)($survey['id'] ?? '')
        )
            ? (string)$survey['id']
            : uid('survey'),

        'createdAt' => (string)(
            $survey['createdAt'] ?? today()
        ),

        'updatedAt' => (string)(
            $survey['updatedAt'] ?? today()
        ),

        'title' => trim(
            (string)($survey['title'] ?? '')
        ),

        'description' => trim(
            (string)($survey['description'] ?? '')
        ),

        'startAt' => (string)(
            $survey['startAt'] ?? ''
        ),

        'endAt' => (string)(
            $survey['endAt'] ?? ''
        ),

        'status' => $status,

        'numbering' =>
            (($survey['numbering'] ?? 'global') === 'group')
                ? 'group'
                : 'global',

        'groups' => $groups,
    ];

    renumberSurvey($result);

    return $result;
}

function renumberSurvey(array &$survey): void
{
    $global = 1;

    foreach ($survey['groups'] as $gi => &$group) {
        foreach (
            $group['questions']
            as $qi => &$question
        ) {
            if ($survey['numbering'] === 'group') {
                $question['number'] =
                    'Q'
                    . ($gi + 1)
                    . '-'
                    . ($qi + 1);
            } else {
                $question['number'] =
                    'Q' . $global;
            }

            $global++;
        }

        unset($question);
    }

    unset($group);
}

function surveyIndex(
    array $data,
    string $id
): int {
    foreach ($data['surveys'] as $i => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

function surveyById(
    array $data,
    string $id
): ?array {
    $index = surveyIndex($data, $id);

    return $index >= 0
        ? $data['surveys'][$index]
        : null;
}

function allQuestions(array $survey): array
{
    $result = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $result[] = $question;
        }
    }

    return $result;
}

function questionMap(array $survey): array
{
    $map = [];

    foreach (allQuestions($survey) as $question) {
        $map[$question['id']] = $question;
    }

    return $map;
}

function canTransition(
    string $from,
    string $to
): bool {
    return match ($from) {
        'draft' => $to === 'published',
        'published' => $to === 'stopped',
        'stopped' => $to === 'published',
        default => false,
    };
}

function updateAutomaticStatus(
    array &$data
): void {
    $changed = false;
    $current = new DateTimeImmutable();

    foreach ($data['surveys'] as &$survey) {
        if (($survey['status'] ?? '') !== 'published') {
            continue;
        }

        $endAt = (string)(
            $survey['endAt'] ?? ''
        );

        if ($endAt === '') {
            continue;
        }

        $end = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            $endAt
        );

        if ($end !== false
            && $current > $end) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = today();
            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {
        saveData($data);
    }
}

function surveyAvailable(array $survey): bool
{
    if (($survey['status'] ?? '') !== 'published') {
        return false;
    }

    $current = new DateTimeImmutable();

    if (!empty($survey['startAt'])) {
        $start = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            (string)$survey['startAt']
        );

        if ($start !== false && $current < $start) {
            return false;
        }
    }

    if (!empty($survey['endAt'])) {
        $end = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            (string)$survey['endAt']
        );

        if ($end !== false && $current > $end) {
            return false;
        }
    }

    return true;
}

/* =========================================================
 * 分岐・回答検証
 * ========================================================= */

function visibleQuestionIds(
    array $survey,
    array $answers
): array {
    $questions = allQuestions($survey);
    $rules = [];

    foreach ($questions as $parent) {
        if (($parent['type'] ?? '') !== 'single') {
            continue;
        }

        foreach (($parent['branches'] ?? []) as $option => $target) {
            if (validateId((string)$target)) {
                $rules[(string)$target] = [
                    'parent' => $parent['id'],
                    'option' => (string)$option,
                ];
            }
        }
    }

    $visible = [];

    foreach ($questions as $question) {
        $id = (string)$question['id'];

        if (!isset($rules[$id])) {
            $visible[] = $id;
            continue;
        }

        $rule = $rules[$id];

        $answer = $answers[
            $rule['parent']
        ] ?? '';

        if ((string)$answer === $rule['option']) {
            $visible[] = $id;
        }
    }

    return array_values(
        array_unique($visible)
    );
}

function validateAnswers(
    array $survey,
    array $answers
): array {
    $errors = [];
    $map = questionMap($survey);

    $visible = visibleQuestionIds(
        $survey,
        $answers
    );

    foreach ($visible as $id) {
        if (!isset($map[$id])) {
            continue;
        }

        $question = $map[$id];
        $value = $answers[$id] ?? '';

        if (is_array($value)) {
            $value = array_values(
                array_map(
                    static fn($v): string =>
                        trim((string)$v),
                    $value
                )
            );
        }

        $empty = is_array($value)
            ? count($value) === 0
            : trim((string)$value) === '';

        if (!empty($question['required'])
            && $empty) {
            $errors[] =
                $question['number']
                . '「'
                . $question['text']
                . '」は必須です。';

            continue;
        }

        if ($empty) {
            continue;
        }

        if ($question['type'] === 'single') {
            if (!is_string($value)
                || !in_array(
                    $value,
                    $question['options'],
                    true
                )) {
                $errors[] =
                    $question['number']
                    . 'の選択値が不正です。';
            }
        }

        if ($question['type'] === 'multiple') {
            if (!is_array($value)) {
                $errors[] =
                    $question['number']
                    . 'の回答形式が不正です。';

                continue;
            }

            foreach ($value as $item) {
                if (!in_array(
                    (string)$item,
                    $question['options'],
                    true
                )) {
                    $errors[] =
                        $question['number']
                        . 'の選択値が不正です。';

                    break;
                }
            }
        }
    }

    return $errors;
}

/* =========================================================
 * HTTP
 *
 * PHP cURLを使用しない。
 * PHP標準stream wrapperを使用する。
 * ========================================================= */

function httpRequest(
    string $url,
    string $method = 'GET',
    array $headers = [],
    ?string $body = null,
    int $timeout = 20,
    bool $verifyTls = true,
    ?string $proxy = null
): array {
    if (!preg_match('#^https://#i', $url)) {
        throw new InvalidArgumentException(
            'HTTPS URLのみ許可されています。'
        );
    }

    $parts = parse_url($url);

    if (!$parts || empty($parts['host'])) {
        throw new InvalidArgumentException(
            '接続先URLが不正です。'
        );
    }

    $httpOptions = [
        'method' => strtoupper($method),
        'timeout' => $timeout,
        'ignore_errors' => true,
        'follow_location' => 0,
        'protocol_version' => 1.1,
    ];

    /*
     * GETのクエリパラメータ方式では
     * Content-Typeを勝手に付与しない。
     */
    if ($headers !== []) {
        $httpOptions['header'] =
            implode("\r\n", $headers);
    }

    if ($body !== null) {
        $httpOptions['content'] = $body;
    }

    $contextOptions = [
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' => $verifyTls,
            'verify_peer_name' => $verifyTls,
            'allow_self_signed' => !$verifyTls,
            'SNI_enabled' => true,
            'peer_name' => $parts['host'],
        ],
    ];

    if ($proxy !== null && $proxy !== '') {
        if (!preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )) {
            throw new InvalidArgumentException(
                'Proxyはhost:port形式で指定してください。'
            );
        }

        $contextOptions['http']['proxy'] =
            'tcp://' . $proxy;

        $contextOptions['http']['request_fulluri'] =
            true;
    }

    $context = stream_context_create(
        $contextOptions
    );

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
        $fp = fopen(
            $url,
            'rb',
            false,
            $context
        );
    } finally {
        restore_error_handler();
    }

    if ($fp === false) {
        return [
            'ok' => false,
            'category' => 'connection_error',
            'status' => 0,
            'body' => '',
            'headers' => [],
            'error' =>
                '外部サービスへ接続できません。',
        ];
    }

    stream_set_timeout(
        $fp,
        $timeout
    );

    $responseBody =
        stream_get_contents($fp);

    $meta =
        stream_get_meta_data($fp);

    $headersOut = [];
    $status = 0;

    foreach (
        ($meta['wrapper_data'] ?? [])
        as $line
    ) {
        if (preg_match(
            '#^HTTP/\S+\s+(\d{3})#i',
            $line,
            $m
        )) {
            $status = (int)$m[1];
        } elseif (str_contains($line, ':')) {
            [$key, $value] =
                explode(':', $line, 2);

            $headersOut[
                strtolower(trim($key))
            ] = trim($value);
        }
    }

    fclose($fp);

    if ($responseBody === false) {
        return [
            'ok' => false,
            'category' => 'response_error',
            'status' => $status,
            'body' => '',
            'headers' => $headersOut,
            'error' =>
                'レスポンスを取得できませんでした。',
        ];
    }

    if (!empty($meta['timed_out'])) {
        return [
            'ok' => false,
            'category' => 'timeout',
            'status' => $status,
            'body' => $responseBody,
            'headers' => $headersOut,
            'error' =>
                '外部サービスへの通信がタイムアウトしました。',
        ];
    }

    if ($status >= 300 && $status < 400) {
        return [
            'ok' => false,
            'category' => 'redirect',
            'status' => $status,
            'body' => $responseBody,
            'headers' => $headersOut,
            'error' =>
                '外部サービスからHTTP '
                . $status
                . ' リダイレクトが返されました。',
        ];
    }

    if ($status >= 200 && $status < 300) {
        return [
            'ok' => true,
            'category' => 'success',
            'status' => $status,
            'body' => $responseBody,
            'headers' => $headersOut,
            'error' => '',
        ];
    }

    return [
        'ok' => false,
        'category' => 'http_error',
        'status' => $status,
        'body' => $responseBody,
        'headers' => $headersOut,
        'error' =>
            '外部サービスからHTTP '
            . $status
            . 'エラーが返されました。',
    ];
}

/* =========================================================
 * kintone
 * ========================================================= */

function normalizeKintoneHost(
    string $input
): string {
    $input = trim($input);

    $input = preg_replace(
        '#^https?://#i',
        '',
        $input
    );

    $input = preg_replace(
        '#/.*$#',
        '',
        $input
    );

    $input = trim((string)$input);

    if ($input === ''
        || !preg_match(
            '/^[A-Za-z0-9.-]+$/',
            $input
        )) {
        throw new InvalidArgumentException(
            'kintoneサブドメインが不正です。'
        );
    }

    if (str_ends_with(
        strtolower($input),
        '.cybozu.com'
    )) {
        return $input;
    }

    return $input . '.cybozu.com';
}

function kintoneAuthorization(
    string $username,
    string $password
): string {
    if ($username === ''
        || $password === '') {
        throw new InvalidArgumentException(
            'kintoneログイン名とパスワードを入力してください。'
        );
    }

    return base64_encode(
        $username . ':' . $password
    );
}

/*
 * kintone REST API共通通信処理。
 *
 * ここが今回のCB_IL02対策の中心。
 *
 * GET + query:
 *   Content-Typeなし
 *
 * JSON body:
 *   Content-Type: application/json
 */
function kintoneRequest(
    array $settings,
    string $path,
    string $method,
    string $password,
    ?array $body = null
): array {
    $host = normalizeKintoneHost(
        (string)($settings['subdomain'] ?? '')
    );

    $appId = (string)(
        $settings['appId'] ?? ''
    );

    if (!ctype_digit($appId)
        || (int)$appId < 1) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    $authorization =
        kintoneAuthorization(
            (string)(
                $settings['username'] ?? ''
            ),
            $password
        );

    /*
     * Authorizationはこの関数内だけで使用する。
     */
    $headers = [
        'X-Cybozu-Authorization: '
            . $authorization,
        'Accept: application/json',
        'User-Agent: SurveyPOC/4.0',
    ];

    $requestBody = null;

    /*
     * GETクエリ方式ではContent-Typeを設定しない。
     */
    if ($body !== null) {
        $headers[] =
            'Content-Type: application/json';

        $requestBody =
            jsonEncode($body);
    }

    try {
        return httpRequest(
            'https://' . $host . $path,
            $method,
            $headers,
            $requestBody,
            20,
            !empty($settings['sslVerify']),
            !empty($settings['proxy'])
                ? (string)$settings['proxy']
                : null
        );
    } finally {
        unset($authorization);
        unset($requestBody);
    }
}

function kintoneErrorMessage(
    array $response
): string {
    $body = json_decode(
        (string)($response['body'] ?? ''),
        true
    );

    if (is_array($body)) {
        $code = (string)(
            $body['code'] ?? ''
        );

        $message = (string)(
            $body['message'] ?? ''
        );

        if ($code !== '' || $message !== '') {
            return 'kintoneエラー'
                . ($code !== ''
                    ? ' [' . $code . ']'
                    : '')
                . ($message !== ''
                    ? ': ' . $message
                    : '');
        }
    }

    return (string)(
        $response['error']
        ?? 'kintone通信に失敗しました。'
    );
}

function kintoneTest(
    array $settings,
    string $password
): array {
    return kintoneRequest(
        $settings,
        '/k/v1/app.json?id='
            . rawurlencode(
                (string)$settings['appId']
            ),
        'GET',
        $password
    );
}

function kintoneFields(
    array $settings,
    string $password
): array {
    return kintoneRequest(
        $settings,
        '/k/v1/app/form/fields.json?app='
            . rawurlencode(
                (string)$settings['appId']
            ),
        'GET',
        $password
    );
}

function kintoneRecords(
    array $settings,
    string $password
): array {
    return kintoneRequest(
        $settings,
        '/k/v1/records.json?app='
            . rawurlencode(
                (string)$settings['appId']
            )
            . '&totalCount=true',
        'GET',
        $password
    );
}

/* =========================================================
 * kintone POST処理
 * ========================================================= */

function saveKintoneAction(
    array &$data
): void {
    $rawSubdomain =
        postString('subdomain');

    $subdomain =
        $rawSubdomain !== ''
            ? normalizeKintoneHost(
                $rawSubdomain
            )
            : '';

    $appId =
        postString('appId');

    if ($appId !== ''
        && (!ctype_digit($appId)
            || (int)$appId < 1)) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    $username =
        postString('username');

    $proxy =
        postString('proxy');

    if ($proxy !== ''
        && !preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で指定してください。'
        );
    }

    $sslVerify =
        postString(
            'sslVerify',
            '1'
        ) === '1';

    $data['kintone']['subdomain'] =
        $subdomain;

    $data['kintone']['appId'] =
        $appId;

    $data['kintone']['username'] =
        $username;

    $data['kintone']['proxy'] =
        $proxy;

    $data['kintone']['sslVerify'] =
        $sslVerify;

    $data['kintone']['connection'] =
        '未設定';

    $data['kintone']['connectionDetail'] =
        '';

    saveData($data);

    flash(
        'success',
        'kintone設定を保存しました。'
    );

    redirectTo('kintone');
}

function testKintoneAction(
    array &$data
): void {
    $password =
        postString('password');

    if ($password === '') {
        throw new InvalidArgumentException(
            'kintoneパスワードを入力してください。'
        );
    }

    try {
        $response = kintoneTest(
            $data['kintone'],
            $password
        );
    } finally {
        unset($password);
    }

    if (!$response['ok']) {
        $message =
            kintoneErrorMessage(
                $response
            );

        $data['kintone']['connection'] =
            '接続できません';

        $data['kintone']['connectionDetail'] =
            $message;

        saveData($data);

        throw new RuntimeException(
            $message
        );
    }

    $data['kintone']['connection'] =
        '接続確認済み';

    $data['kintone']['connectionDetail'] =
        'kintoneへの接続と認証に成功しました。';

    saveData($data);

    flash(
        'success',
        'kintone接続テストに成功しました。'
    );

    redirectTo('kintone');
}

function fetchKintoneFieldsAction(
    array &$data
): void {
    $password =
        postString('password');

    if ($password === '') {
        throw new InvalidArgumentException(
            'kintoneパスワードを入力してください。'
        );
    }

    try {
        $response = kintoneFields(
            $data['kintone'],
            $password
        );
    } finally {
        unset($password);
    }

    if (!$response['ok']) {
        throw new RuntimeException(
            kintoneErrorMessage(
                $response
            )
        );
    }

    $json = json_decode(
        (string)$response['body'],
        true
    );

    if (!is_array($json)
        || !is_array(
            $json['properties'] ?? null
        )) {
        throw new RuntimeException(
            'kintone項目一覧を取得できませんでした。'
        );
    }

    $fields = [];

    foreach (
        $json['properties']
        as $code => $field
    ) {
        if (!is_array($field)) {
            continue;
        }

        $fields[(string)$code] = [
            'label' => (string)(
                $field['label'] ?? ''
            ),
            'type' => (string)(
                $field['type'] ?? ''
            ),
        ];
    }

    $data['kintone']['fields'] =
        $fields;

    saveData($data);

    flash(
        'success',
        'kintone項目一覧を再取得しました。'
    );

    redirectTo('kintone');
}

function syncKintoneAction(
    array &$data
): void {
    $password =
        postString('password');

    if ($password === '') {
        throw new InvalidArgumentException(
            'kintoneパスワードを入力してください。'
        );
    }

    try {
        $response = kintoneRecords(
            $data['kintone'],
            $password
        );
    } finally {
        unset($password);
    }

    if (!$response['ok']) {
        throw new RuntimeException(
            kintoneErrorMessage(
                $response
            )
        );
    }

    $json = json_decode(
        (string)$response['body'],
        true
    );

    if (!is_array($json)
        || !is_array(
            $json['records'] ?? null
        )) {
        throw new RuntimeException(
            'kintone顧客情報を取得できませんでした。'
        );
    }

    $mapping =
        $data['kintone']['mappings']
        ?? [];

    $customers = [];

    $readField =
        static function (
            array $record,
            string $code
        ): string {
            if ($code === ''
                || !isset($record[$code])) {
                return '';
            }

            $value =
                $record[$code]['value']
                ?? '';

            if (is_array($value)) {
                $parts = [];

                foreach ($value as $item) {
                    if (is_scalar($item)) {
                        $parts[] = (string)$item;
                    }
                }

                $value = implode(' ', $parts);
            }

            return trim((string)$value);
        };

    foreach ($json['records'] as $record) {
        if (!is_array($record)) {
            continue;
        }

        $customers[] = [
            'id' => uid('customer'),

            'org' => $readField(
                $record,
                (string)(
                    $mapping['org'] ?? ''
                )
            ),

            'name' => $readField(
                $record,
                (string)(
                    $mapping['name'] ?? ''
                )
            ),

            'email' => $readField(
                $record,
                (string)(
                    $mapping['email'] ?? ''
                )
            ),

            'department' => $readField(
                $record,
                (string)(
                    $mapping['department'] ?? ''
                )
            ),

            'phone' => $readField(
                $record,
                (string)(
                    $mapping['phone'] ?? ''
                )
            ),

            'address' => $readField(
                $record,
                (string)(
                    $mapping['address'] ?? ''
                )
            ),
        ];
    }

    $data['customers'] =
        $customers;

    saveData($data);

    flash(
        'success',
        count($customers)
        . '件の顧客情報を同期しました。'
    );

    redirectTo('kintone');
}

/* =========================================================
 * アンケートPOST
 * ========================================================= */

function saveSurveyAction(
    array &$data
): void {
    $id = postString('id');

    if ($id !== '' && !validateId($id)) {
        throw new InvalidArgumentException(
            'アンケートIDが不正です。'
        );
    }

    $title =
        postString('title');

    if ($title === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルを入力してください。'
        );
    }

    $description =
        postString('description');

    $startAt =
        postString('startAt');

    $endAt =
        postString('endAt');

    if (!validDateTime($startAt)
        || !validDateTime($endAt)) {
        throw new InvalidArgumentException(
            '公開期間の日時形式が不正です。'
        );
    }

    if ($startAt !== ''
        && $endAt !== ''
        && $startAt > $endAt) {
        throw new InvalidArgumentException(
            '終了日時は開始日時以降にしてください。'
        );
    }

    $numbering =
        postString(
            'numbering',
            'global'
        );

    if (!in_array(
        $numbering,
        ['global', 'group'],
        true
    )) {
        $numbering = 'global';
    }

    $groupsInput =
        postArray('groups');

    $groups = [];

    foreach ($groupsInput as $groupInput) {
        if (!is_array($groupInput)) {
            continue;
        }

        $questions = [];

        foreach (
            ($groupInput['questions'] ?? [])
            as $questionInput
        ) {
            if (!is_array($questionInput)) {
                continue;
            }

            $questions[] =
                normalizeQuestion(
                    $questionInput
                );
        }

        $groups[] = [
            'id' => validateId(
                (string)($groupInput['id'] ?? '')
            )
                ? (string)$groupInput['id']
                : uid('g'),

            'title' => trim(
                (string)($groupInput['title'] ?? '')
            ),

            'questions' => $questions,
        ];
    }

    if ($id !== '') {
        $index =
            surveyIndex($data, $id);

        if ($index < 0) {
            throw new RuntimeException(
                '更新対象のアンケートが存在しません。'
            );
        }

        $old =
            $data['surveys'][$index];

        $survey = normalizeSurvey([
            'id' => $id,
            'createdAt' =>
                $old['createdAt'] ?? today(),
            'updatedAt' => today(),
            'title' => $title,
            'description' => $description,
            'startAt' => $startAt,
            'endAt' => $endAt,
            'status' =>
                $old['status'] ?? 'draft',
            'numbering' => $numbering,
            'groups' => $groups,
        ]);

        $data['surveys'][$index] =
            $survey;
    } else {
        $survey = normalizeSurvey([
            'id' => uid('survey'),
            'createdAt' => today(),
            'updatedAt' => today(),
            'title' => $title,
            'description' => $description,
            'startAt' => $startAt,
            'endAt' => $endAt,
            'status' => 'draft',
            'numbering' => $numbering,
            'groups' => $groups,
        ]);

        $data['surveys'][] =
            $survey;
    }

    saveData($data);

    flash(
        'success',
        'アンケートを保存しました。'
    );

    redirectTo('list');
}

function deleteSurveyAction(
    array &$data
): void {
    $id = postString('id');

    if (!validateId($id)) {
        throw new InvalidArgumentException(
            'アンケートIDが不正です。'
        );
    }

    $index =
        surveyIndex($data, $id);

    if ($index < 0) {
        throw new RuntimeException(
            '削除対象のアンケートが存在しません。'
        );
    }

    array_splice(
        $data['surveys'],
        $index,
        1
    );

    saveData($data);

    flash(
        'success',
        'アンケートを削除しました。'
    );

    redirectTo('list');
}

function duplicateSurveyAction(
    array &$data
): void {
    $id = postString('id');

    $survey =
        surveyById($data, $id);

    if ($survey === null) {
        throw new RuntimeException(
            '複製対象のアンケートが存在しません。'
        );
    }

    $survey['id'] =
        uid('survey');

    $survey['title'] =
        $survey['title']
        . '（コピー）';

    $survey['status'] =
        'draft';

    $survey['createdAt'] =
        today();

    $survey['updatedAt'] =
        today();

    $data['surveys'][] =
        normalizeSurvey($survey);

    saveData($data);

    flash(
        'success',
        'アンケートを複製しました。'
    );

    redirectTo('list');
}

function transitionSurveyAction(
    array &$data
): void {
    $id =
        postString('id');

    $to =
        postString('to');

    $index =
        surveyIndex($data, $id);

    if ($index < 0) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $from =
        (string)(
            $data['surveys'][$index]['status']
            ?? 'draft'
        );

    if (!canTransition($from, $to)) {
        throw new InvalidArgumentException(
            '指定された状態変更は許可されていません。'
        );
    }

    $data['surveys'][$index]['status'] =
        $to;

    $data['surveys'][$index]['updatedAt'] =
        today();

    saveData($data);

    flash(
        'success',
        'アンケートの状態を変更しました。'
    );

    redirectTo('list');
}

/* =========================================================
 * 回答
 * ========================================================= */

function saveAnswerToSession(
    string $surveyId,
    array $answers
): void {
    $_SESSION['answerDraft'][$surveyId] =
        $answers;
}

function getAnswerDraft(
    string $surveyId
): array {
    $value =
        $_SESSION['answerDraft'][$surveyId]
        ?? [];

    return is_array($value)
        ? $value
        : [];
}

function clearAnswerDraft(
    string $surveyId
): void {
    unset(
        $_SESSION['answerDraft'][$surveyId]
    );
}

function confirmAnswerAction(
    array &$data
): void {
    $surveyId =
        postString('surveyId');

    $survey =
        surveyById(
            $data,
            $surveyId
        );

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    if (!surveyAvailable($survey)) {
        throw new RuntimeException(
            'このアンケートは現在回答できません。'
        );
    }

    $answers =
        postArray('answers');

    $errors =
        validateAnswers(
            $survey,
            $answers
        );

    if ($errors !== []) {
        saveAnswerToSession(
            $surveyId,
            $answers
        );

        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    saveAnswerToSession(
        $surveyId,
        $answers
    );

    redirectTo(
        'confirm',
        ['id' => $surveyId]
    );
}

function completeAnswerAction(
    array &$data
): void {
    $surveyId =
        postString('surveyId');

    $survey =
        surveyById(
            $data,
            $surveyId
        );

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $answers =
        getAnswerDraft(
            $surveyId
        );

    $errors =
        validateAnswers(
            $survey,
            $answers
        );

    if ($errors !== []) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    $data['answers'][] = [
        'id' => uid('answer'),
        'surveyId' => $surveyId,
        'createdAt' => now(),
        'answers' => $answers,
    ];

    saveData($data);

    clearAnswerDraft(
        $surveyId
    );

    redirectTo(
        'complete',
        ['id' => $surveyId]
    );
}

/* =========================================================
 * メール設定
 * ========================================================= */

function saveMailAction(
    array &$data
): void {
    $server =
        postString('server');

    $port =
        postString('port', '587');

    if (!ctype_digit($port)
        || (int)$port < 1
        || (int)$port > 65535) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    $encryption =
        postString(
            'encryption',
            'tls'
        );

    if (!in_array(
        $encryption,
        ['ssl', 'tls', 'none'],
        true
    )) {
        throw new InvalidArgumentException(
            'SMTP暗号化方式が不正です。'
        );
    }

    $username =
        postString('username');

    $fromEmail =
        postString('fromEmail');

    if ($fromEmail !== ''
        && !validEmail($fromEmail)) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    $replyTo =
        postString('replyTo');

    if ($replyTo !== ''
        && !validEmail($replyTo)) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが不正です。'
        );
    }

    $data['mailSettings'] =
        array_replace(
            $data['mailSettings'],
            [
                'server' => $server,
                'port' => (int)$port,
                'encryption' => $encryption,
                'auth' =>
                    postString('auth', '1') === '1',
                'username' => $username,
                'fromEmail' => $fromEmail,
                'fromName' =>
                    postString('fromName'),
                'replyTo' => $replyTo,
                'connection' => '未設定',
                'connectionDetail' => '',
            ]
        );

    saveData($data);

    flash(
        'success',
        'メール設定を保存しました。'
    );

    redirectTo('mail');
}

/* =========================================================
 * SMTP
 *
 * PHP mail()を使わない。
 * パスワードはPOSTされた処理中のみ使用する。
 * ========================================================= */

function smtpConnect(
    array $settings,
    string $password
): array {
    $server =
        (string)($settings['server'] ?? '');

    if ($server === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを設定してください。'
        );
    }

    $port =
        (int)($settings['port'] ?? 587);

    $encryption =
        (string)($settings['encryption'] ?? 'tls');

    $host = $server;

    if ($encryption === 'ssl') {
        $host = 'ssl://' . $server;
    }

    $timeout = 20;

    $errorCode = 0;
    $errorMessage = '';

    $fp = @stream_socket_client(
        $host . ':' . $port,
        $errorCode,
        $errorMessage,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if ($fp === false) {
        return [
            'ok' => false,
            'category' => 'connection_error',
            'message' =>
                'SMTPサーバへ接続できません。',
        ];
    }

    stream_set_timeout(
        $fp,
        $timeout
    );

    $read =
        smtpReadResponse($fp);

    if (!$read['ok']
        || $read['code'] !== 220) {
        fclose($fp);

        return [
            'ok' => false,
            'category' => 'smtp_error',
            'message' =>
                'SMTPサーバから正常な応答を取得できませんでした。',
        ];
    }

    $hostname =
        gethostname();

    if (!is_string($hostname)
        || $hostname === '') {
        $hostname = 'localhost';
    }

    $result =
        smtpCommand(
            $fp,
            'EHLO ' . $hostname,
            250
        );

    if (!$result['ok']) {
        fclose($fp);

        return [
            'ok' => false,
            'category' => 'smtp_error',
            'message' =>
                'SMTPサーバとの通信に失敗しました。',
        ];
    }

    if ($encryption === 'tls') {
        $result =
            smtpCommand(
                $fp,
                'STARTTLS',
                220
            );

        if (!$result['ok']) {
            fclose($fp);

            return [
                'ok' => false,
                'category' => 'smtp_error',
                'message' =>
                    'SMTP TLS接続を開始できません。',
            ];
        }

        $crypto =
            @stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

        if ($crypto !== true) {
            fclose($fp);

            return [
                'ok' => false,
                'category' => 'smtp_error',
                'message' =>
                    'SMTP TLS通信を確立できません。',
            ];
        }

        $result =
            smtpCommand(
                $fp,
                'EHLO ' . $hostname,
                250
            );

        if (!$result['ok']) {
            fclose($fp);

            return [
                'ok' => false,
                'category' => 'smtp_error',
                'message' =>
                    'SMTPサーバとの通信に失敗しました。',
            ];
        }
    }

    if (!empty($settings['auth'])) {
        if ($password === '') {
            fclose($fp);

            return [
                'ok' => false,
                'category' => 'authentication_error',
                'message' =>
                    'SMTPパスワードを入力してください。',
            ];
        }

        $auth =
            smtpCommand(
                $fp,
                'AUTH LOGIN',
                334
            );

        if (!$auth['ok']) {
            fclose($fp);

            return [
                'ok' => false,
                'category' => 'authentication_error',
                'message' =>
                    'SMTP認証を開始できません。',
            ];
        }

        $auth =
            smtpCommand(
                $fp,
                base64_encode(
                    (string)(
                        $settings['username'] ?? ''
                    )
                ),
                334
            );

        if (!$auth['ok']) {
            fclose($fp);

            return [
                'ok' => false,
                'category' => 'authentication_error',
                'message' =>
                    'SMTP認証に失敗しました。',
            ];
        }

        $auth =
            smtpCommand(
                $fp,
                base64_encode($password),
                235
            );

        if (!$auth['ok']) {
            fclose($fp);

            return [
                'ok' => false,
                'category' => 'authentication_error',
                'message' =>
                    'SMTP認証に失敗しました。',
            ];
        }
    }

    return [
        'ok' => true,
        'socket' => $fp,
    ];
}

function smtpReadResponse($fp): array
{
    $lines = [];

    while (($line = fgets($fp)) !== false) {
        $line = rtrim(
            $line,
            "\r\n"
        );

        $lines[] = $line;

        if (preg_match(
            '/^(\d{3})([ -])/',
            $line,
            $m
        )) {
            if ($m[2] === ' ') {
                return [
                    'ok' => true,
                    'code' => (int)$m[1],
                    'lines' => $lines,
                ];
            }
        }
    }

    return [
        'ok' => false,
        'code' => 0,
        'lines' => $lines,
    ];
}

function smtpCommand(
    $fp,
    string $command,
    int $expected
): array {
    fwrite(
        $fp,
        $command . "\r\n"
    );

    $response =
        smtpReadResponse($fp);

    return [
        'ok' =>
            $response['ok']
            && $response['code'] === $expected,
        'code' =>
            $response['code'],
    ];
}

function smtpTestAction(
    array &$data
): void {
    $password =
        postString('password');

    try {
        $result =
            smtpConnect(
                $data['mailSettings'],
                $password
            );
    } finally {
        unset($password);
    }

    if (!$result['ok']) {
        $data['mailSettings']['connection'] =
            '接続できません';

        $data['mailSettings']['connectionDetail'] =
            $result['message']
            ?? 'SMTP接続に失敗しました。';

        saveData($data);

        throw new RuntimeException(
            $data['mailSettings']['connectionDetail']
        );
    }

    $fp =
        $result['socket'];

    smtpCommand(
        $fp,
        'QUIT',
        221
    );

    fclose($fp);

    $data['mailSettings']['connection'] =
        '接続確認済み';

    $data['mailSettings']['connectionDetail'] =
        'SMTPへの接続と認証に成功しました。';

    saveData($data);

    flash(
        'success',
        'SMTP接続テストに成功しました。'
    );

    redirectTo('mail');
}

/* =========================================================
 * POST dispatcher
 * ========================================================= */

function processPost(
    array &$data
): void {
    $action =
        postString('action');

    switch ($action) {
        case 'save_kintone':
            saveKintoneAction($data);
            return;

        case 'test_kintone':
            testKintoneAction($data);
            return;

        case 'fetch_kintone_fields':
            fetchKintoneFieldsAction($data);
            return;

        case 'sync_kintone':
            syncKintoneAction($data);
            return;

        case 'save_survey':
            saveSurveyAction($data);
            return;

        case 'delete_survey':
            deleteSurveyAction($data);
            return;

        case 'duplicate_survey':
            duplicateSurveyAction($data);
            return;

        case 'transition_survey':
            transitionSurveyAction($data);
            return;

        case 'confirm_answer':
            confirmAnswerAction($data);
            return;

        case 'complete_answer':
            completeAnswerAction($data);
            return;

        case 'save_mail':
            saveMailAction($data);
            return;

        case 'test_mail':
            smtpTestAction($data);
            return;

        default:
            throw new InvalidArgumentException(
                '不正な処理が指定されました。'
            );
    }
}

/* =========================================================
 * HTML
 * ========================================================= */

function renderHeader(
    string $title
): string {
    return '<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>'
        . h($title)
        . '</title>
<style>
*{box-sizing:border-box}
body{
 margin:0;
 font-family:
   -apple-system,BlinkMacSystemFont,
   "Segoe UI","Noto Sans JP",sans-serif;
 color:#263238;
 background:#f5f7fa;
 line-height:1.6;
}
header{
 background:#263238;
 color:#fff;
 padding:16px 24px;
}
header a{
 color:#fff;
 text-decoration:none;
}
nav{
 display:flex;
 gap:10px;
 flex-wrap:wrap;
 margin-top:8px;
}
nav a{
 padding:6px 10px;
 border-radius:6px;
 background:#37474f;
}
main{
 max-width:1200px;
 margin:0 auto;
 padding:24px;
}
.card{
 background:#fff;
 border:1px solid #e1e6eb;
 border-radius:10px;
 padding:20px;
 margin-bottom:20px;
 box-shadow:0 2px 8px rgba(0,0,0,.04);
}
table{
 width:100%;
 border-collapse:collapse;
 background:#fff;
}
th,td{
 padding:10px;
 border-bottom:1px solid #e5e9ed;
 text-align:left;
 vertical-align:top;
}
th{
 background:#f7f9fb;
}
input,textarea,select{
 width:100%;
 padding:9px 10px;
 border:1px solid #cbd3da;
 border-radius:6px;
 background:#fff;
}
textarea{
 min-height:100px;
 resize:vertical;
}
button,.button{
 display:inline-block;
 padding:9px 14px;
 border:0;
 border-radius:6px;
 background:#1976d2;
 color:#fff;
 cursor:pointer;
 text-decoration:none;
}
button.secondary,.button.secondary{
 background:#607d8b;
}
button.danger,.button.danger{
 background:#c62828;
}
button.success,.button.success{
 background:#2e7d32;
}
.actions{
 display:flex;
 flex-wrap:wrap;
 gap:8px;
}
.flash{
 padding:12px 14px;
 border-radius:7px;
 margin-bottom:16px;
}
.flash.success{
 background:#e8f5e9;
 color:#1b5e20;
}
.flash.error{
 background:#ffebee;
 color:#b71c1c;
 white-space:pre-line;
}
.muted{
 color:#667781;
}
.status{
 display:inline-block;
 padding:3px 8px;
 border-radius:999px;
 background:#eceff1;
 font-size:.9em;
}
.grid{
 display:grid;
 grid-template-columns:
   repeat(auto-fit,minmax(280px,1fr));
 gap:16px;
}
.question{
 border:1px solid #e0e5e9;
 border-radius:8px;
 padding:14px;
 margin-bottom:12px;
}
.option{
 display:flex;
 gap:8px;
 align-items:center;
 margin:7px 0;
}
.option input{
 width:auto;
}
@media(max-width:700px){
 main{padding:12px}
 th,td{
   padding:7px;
   font-size:.92em;
 }
}
</style>
</head>
<body>
<header>
<strong>アンケートアプリ</strong>
<nav>
<a href="?screen=list">アンケート一覧</a>
<a href="?screen=kintone">kintone設定</a>
<a href="?screen=mail">メール設定</a>
</nav>
</header>
<main>';
}

function renderFooter(): string
{
    return '</main>
</body>
</html>';
}

function renderFlash(): string
{
    $flash = takeFlash();

    if ($flash === null) {
        return '';
    }

    $class =
        ($flash['type'] ?? '') === 'success'
            ? 'success'
            : 'error';

    return '<div class="flash '
        . h($class)
        . '">'
        . h($flash['message'] ?? '')
        . '</div>';
}

function renderList(
    array $data
): string {
    $html =
        renderHeader('アンケート一覧');

    $html .= renderFlash();

    $html .= '
<div class="card">
<h1>アンケート一覧</h1>
<p>
<a class="button"
   href="?screen=edit">
新規作成
</a>
</p>';

    if ($data['surveys'] === []) {
        $html .=
            '<p class="muted">アンケートはありません。</p>';
    } else {
        $html .= '
<div style="overflow-x:auto">
<table>
<thead>
<tr>
<th>タイトル</th>
<th>状態</th>
<th>更新日</th>
<th>操作</th>
</tr>
</thead>
<tbody>';

        foreach ($data['surveys'] as $survey) {
            $id =
                (string)$survey['id'];

            $html .= '<tr>
<td>'
                . h($survey['title'])
                . '</td>
<td><span class="status">'
                . h($survey['status'])
                . '</span></td>
<td>'
                . h($survey['updatedAt'])
                . '</td>
<td>
<div class="actions">
<a class="button secondary"
   href="?screen=edit&id='
                . rawurlencode($id)
                . '">
編集
</a>
<a class="button secondary"
   href="?screen=preview&id='
                . rawurlencode($id)
                . '">
プレビュー
</a>
<a class="button secondary"
   href="?screen=analytics&id='
                . rawurlencode($id)
                . '">
集計
</a>
<a class="button secondary"
   href="?screen=send&id='
                . rawurlencode($id)
                . '">
送信
</a>';

            if ($survey['status'] === 'draft'
                || $survey['status'] === 'stopped') {
                $html .= '
<form method="post">
<input type="hidden"
       name="action"
       value="transition_survey">
<input type="hidden"
       name="id"
       value="'
                    . h($id)
                    . '">
<input type="hidden"
       name="to"
       value="published">
<button class="success">
公開
</button>
</form>';
            }

            if ($survey['status'] === 'published') {
                $html .= '
<form method="post">
<input type="hidden"
       name="action"
       value="transition_survey">
<input type="hidden"
       name="id"
       value="'
                    . h($id)
                    . '">
<input type="hidden"
       name="to"
       value="stopped">
<button class="secondary">
停止
</button>
</form>';
            }

            $html .= '
<form method="post">
<input type="hidden"
       name="action"
       value="duplicate_survey">
<input type="hidden"
       name="id"
       value="'
                . h($id)
                . '">
<button class="secondary">
複製
</button>
</form>

<form method="post"
      onsubmit="return confirm(\'削除しますか？\');">
<input type="hidden"
       name="action"
       value="delete_survey">
<input type="hidden"
       name="id"
       value="'
                . h($id)
                . '">
<button class="danger">
削除
</button>
</form>
</div>
</td>
</tr>';
        }

        $html .= '
</tbody>
</table>
</div>';
    }

    $html .= '
</div>';

    return $html
        . renderFooter();
}

function renderEdit(
    array $data,
    ?array $survey
): string {
    $html =
        renderHeader(
            $survey === null
                ? 'アンケート作成'
                : 'アンケート編集'
        );

    $html .= renderFlash();

    $id =
        (string)($survey['id'] ?? '');

    $html .= '
<div class="card">
<h1>'
        . h(
            $survey === null
                ? 'アンケート作成'
                : 'アンケート編集'
        )
        . '</h1>

<form method="post">
<input type="hidden"
       name="action"
       value="save_survey">
<input type="hidden"
       name="id"
       value="'
        . h($id)
        . '">

<div class="grid">
<div>
<label>タイトル</label>
<input name="title"
       required
       value="'
        . h($survey['title'] ?? '')
        . '">
</div>

<div>
<label>番号付け</label>
<select name="numbering">
<option value="global"'
        . (($survey['numbering'] ?? 'global')
            === 'global'
            ? ' selected'
            : '')
        . '>全体連番</option>
<option value="group"'
        . (($survey['numbering'] ?? '')
            === 'group'
            ? ' selected'
            : '')
        . '>グループ単位</option>
</select>
</div>

<div>
<label>開始日時</label>
<input type="datetime-local"
       name="startAt"
       value="'
        . h($survey['startAt'] ?? '')
        . '">
</div>

<div>
<label>終了日時</label>
<input type="datetime-local"
       name="endAt"
       value="'
        . h($survey['endAt'] ?? '')
        . '">
</div>
</div>

<div>
<label>説明</label>
<textarea name="description">'
        . h($survey['description'] ?? '')
        . '</textarea>
</div>';

    $groups =
        $survey['groups'] ?? [];

    if ($groups === []) {
        $groups = [[
            'id' => uid('g'),
            'title' => '',
            'questions' => [],
        ]];
    }

    foreach ($groups as $gi => $group) {
        $html .= '
<div class="card">
<h2>グループ '
            . ($gi + 1)
            . '</h2>

<input type="hidden"
       name="groups['
            . $gi
            . '][id]"
       value="'
            . h($group['id'])
            . '">

<label>グループ名</label>
<input name="groups['
            . $gi
            . '][title]"
       value="'
            . h($group['title'])
            . '">';

        $questions =
            $group['questions'] ?? [];

        foreach ($questions as $qi => $question) {
            $html .= '
<div class="question">
<strong>'
                . h($question['number'])
                . '</strong>

<input type="hidden"
       name="groups['
                . $gi
                . '][questions]['
                . $qi
                . '][id]"
       value="'
                . h($question['id'])
                . '">

<p>
<label>質問文</label>
<input name="groups['
                . $gi
                . '][questions]['
                . $qi
                . '][text]"
       value="'
                . h($question['text'])
                . '">
</p>

<p>
<label>形式</label>
<select name="groups['
                . $gi
                . '][questions]['
                . $qi
                . '][type]">
<option value="single"'
                . ($question['type'] === 'single'
                    ? ' selected'
                    : '')
                . '>単一選択</option>
<option value="multiple"'
                . ($question['type'] === 'multiple'
                    ? ' selected'
                    : '')
                . '>複数選択</option>
<option value="free"'
                . ($question['type'] === 'free'
                    ? ' selected'
                    : '')
                . '>自由記述</option>
</select>
</p>

<label>
<input type="checkbox"
       name="groups['
                . $gi
                . '][questions]['
                . $qi
                . '][required]"
       value="1"'
                . (!empty($question['required'])
                    ? ' checked'
                    : '')
                . '>
必須
</label>';

            foreach (
                ($question['options'] ?? [])
                as $oi => $option
            ) {
                $html .= '
<p>
<label>選択肢 '
                    . ($oi + 1)
                    . '</label>
<input name="groups['
                    . $gi
                    . '][questions]['
                    . $qi
                    . '][options]['
                    . $oi
                    . ']"
       value="'
                    . h($option)
                    . '">
</p>';
            }

            $html .= '
</div>';
        }

        $html .= '
</div>';
    }

    $html .= '
<p class="muted">
質問やグループを追加する場合は、
保存済みデータを基に編集してください。
</p>

<div class="actions">
<button class="success">
保存
</button>

<a class="button secondary"
   href="?screen=list">
キャンセル
</a>
</div>

</form>
</div>';

    return $html
        . renderFooter();
}

function renderPreview(
    array $survey
): string {
    $html =
        renderHeader('プレビュー');

    $html .= '
<div class="card">
<h1>'
        . h($survey['title'])
        . '</h1>
<p>'
        . nl2br(
            h($survey['description'])
        )
        . '</p>';

    foreach ($survey['groups'] as $group) {
        $html .= '
<div class="card">
<h2>'
            . h($group['title'])
            . '</h2>';

        foreach ($group['questions'] as $question) {
            $html .= '
<div class="question">
<h3>'
                . h($question['number'])
                . ' '
                . h($question['text'])
                . '</h3>';

            if ($question['type'] === 'free') {
                $html .=
                    '<textarea disabled></textarea>';
            } else {
                foreach (
                    $question['options']
                    as $option
                ) {
                    $html .= '
<div class="option">
<input type="'
                        . (
                            $question['type']
                            === 'multiple'
                                ? 'checkbox'
                                : 'radio'
                        )
                        . '" disabled>
<span>'
                        . h($option)
                        . '</span>
</div>';
                }
            }

            $html .= '
</div>';
        }

        $html .= '
</div>';
    }

    $html .= '
<a class="button secondary"
   href="?screen=list">
一覧へ戻る
</a>
</div>';

    return $html
        . renderFooter();
}

function renderAnswer(
    array $survey,
    array $answers
): string {
    $html =
        renderHeader('アンケート回答');

    $html .= '
<div class="card">
<h1>'
        . h($survey['title'])
        . '</h1>
<p>'
        . nl2br(
            h($survey['description'])
        )
        . '</p>

<form method="post">
<input type="hidden"
       name="action"
       value="confirm_answer">
<input type="hidden"
       name="surveyId"
       value="'
        . h($survey['id'])
        . '">';

    foreach ($survey['groups'] as $group) {
        $html .= '
<div class="card">
<h2>'
            . h($group['title'])
            . '</h2>';

        foreach ($group['questions'] as $question) {
            $id =
                $question['id'];

            $value =
                $answers[$id]
                ?? '';

            $html .= '
<div class="question">
<h3>'
                . h($question['number'])
                . ' '
                . h($question['text'])
                . ($question['required']
                    ? ' <span>*</span>'
                    : '')
                . '</h3>';

            if ($question['type'] === 'free') {
                $html .= '
<textarea name="answers['
                    . h($id)
                    . ']">'
                    . h($value)
                    . '</textarea>';
            } else {
                foreach (
                    $question['options']
                    as $option
                ) {
                    $checked = false;

                    if ($question['type'] === 'single') {
                        $checked =
                            (string)$value
                            === (string)$option;
                    } elseif (
                        is_array($value)
                    ) {
                        $checked =
                            in_array(
                                $option,
                                $value,
                                true
                            );
                    }

                    $html .= '
<div class="option">
<input type="'
                        . (
                            $question['type']
                            === 'multiple'
                                ? 'checkbox'
                                : 'radio'
                        )
                        . '"
 name="answers['
                        . h($id)
                        . ']'
                        . (
                            $question['type']
                            === 'multiple'
                                ? '[]'
                                : ''
                        )
                        . '"
 value="'
                        . h($option)
                        . '"'
                        . (
                            $checked
                                ? ' checked'
                                : ''
                        )
                        . '>
<span>'
                        . h($option)
                        . '</span>
</div>';
                }
            }

            $html .= '
</div>';
        }

        $html .= '
</div>';
    }

    $html .= '
<button class="success">
確認画面へ
</button>
</form>
</div>';

    return $html
        . renderFooter();
}

function renderConfirm(
    array $survey,
    array $answers
): string {
    $map =
        questionMap($survey);

    $html =
        renderHeader('回答確認');

    $html .= '
<div class="card">
<h1>回答確認</h1>';

    foreach (
        visibleQuestionIds(
            $survey,
            $answers
        ) as $id
    ) {
        if (!isset($map[$id])) {
            continue;
        }

        $question =
            $map[$id];

        $value =
            $answers[$id]
            ?? '';

        if (is_array($value)) {
            $value =
                implode(
                    '、',
                    array_map(
                        'strval',
                        $value
                    )
                );
        }

        $html .= '
<div class="question">
<strong>'
            . h($question['number'])
            . ' '
            . h($question['text'])
            . '</strong>
<p>'
            . nl2br(
                h($value)
            )
            . '</p>
</div>';
    }

    $html .= '
<form method="post">
<input type="hidden"
       name="action"
       value="complete_answer">
<input type="hidden"
       name="surveyId"
       value="'
        . h($survey['id'])
        . '">
<button class="success">
回答を送信する
</button>
</form>

<p>
<a class="button secondary"
   href="?screen=answer&id='
        . rawurlencode(
            $survey['id']
        )
        . '">
戻る
</a>
</p>
</div>';

    return $html
        . renderFooter();
}

function renderComplete(
    array $survey
): string {
    return renderHeader('回答完了')
        . '
<div class="card">
<h1>回答ありがとうございました。</h1>
<p>'
        . h($survey['title'])
        . 'への回答を受け付けました。</p>
</div>'
        . renderFooter();
}

function renderAnalytics(
    array $data,
    array $survey
): string {
    $answers = [];

    foreach ($data['answers'] as $answer) {
        if (($answer['surveyId'] ?? '')
            === $survey['id']) {
            $answers[] = $answer;
        }
    }

    $html =
        renderHeader('回答集計');

    $html .= '
<div class="card">
<h1>'
        . h($survey['title'])
        . '</h1>
<p>回答数: '
        . count($answers)
        . '件</p>';

    foreach (
        allQuestions($survey)
        as $question
    ) {
        if ($question['type'] === 'free') {
            continue;
        }

        $counts = [];

        foreach (
            $question['options']
            as $option
        ) {
            $counts[$option] = 0;
        }

        foreach ($answers as $answer) {
            $value =
                $answer['answers'][
                    $question['id']
                ] ?? '';

            if (is_array($value)) {
                foreach ($value as $item) {
                    if (isset($counts[$item])) {
                        $counts[$item]++;
                    }
                }
            } elseif (
                isset($counts[$value])
            ) {
                $counts[$value]++;
            }
        }

        $html .= '
<div class="card">
<h2>'
            . h($question['number'])
            . ' '
            . h($question['text'])
            . '</h2>
<table>
<thead>
<tr>
<th>選択肢</th>
<th>回答数</th>
</tr>
</thead>
<tbody>';

        foreach ($counts as $option => $count) {
            $html .= '
<tr>
<td>'
                . h($option)
                . '</td>
<td>'
                . h($count)
                . '</td>
</tr>';
        }

        $html .= '
</tbody>
</table>
</div>';
    }

    $html .= '
<a class="button secondary"
   href="?screen=list">
一覧へ戻る
</a>
</div>';

    return $html
        . renderFooter();
}

function renderKintone(
    array $data
): string {
    $k =
        $data['kintone'];

    $html =
        renderHeader('kintone設定');

    $html .= renderFlash();

    $html .= '
<div class="card">
<h1>kintone設定</h1>

<form method="post">
<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid">
<div>
<label>サブドメイン</label>
<input name="subdomain"
       value="'
        . h($k['subdomain'])
        . '"
       placeholder="example.cybozu.com">
</div>

<div>
<label>顧客管理アプリID</label>
<input name="appId"
       value="'
        . h($k['appId'])
        . '">
</div>

<div>
<label>ログイン名</label>
<input name="username"
       value="'
        . h($k['username'])
        . '">
</div>

<div>
<label>Proxy</label>
<input name="proxy"
       value="'
        . h($k['proxy'])
        . '"
       placeholder="host:port">
</div>
</div>

<label>
<input type="checkbox"
       name="sslVerify"
       value="1"'
        . (!empty($k['sslVerify'])
            ? ' checked'
            : '')
        . '>
TLS証明書を検証する
</label>

<p>
<button class="success">
設定を保存
</button>
</p>
</form>
</div>

<div class="card">
<h2>接続テスト</h2>
<p class="muted">
パスワードは保存されません。
この操作で入力された値だけを
サーバー側処理に使用します。
</p>

<form method="post">
<input type="hidden"
       name="action"
       value="test_kintone">

<label>kintoneパスワード</label>
<input type="password"
       name="password"
       autocomplete="off"
       required>

<p>
<button>
接続テスト
</button>
</p>
</form>

<p>
接続状態:
<strong>'
        . h($k['connection'])
        . '</strong>
</p>';

    if ($k['connectionDetail'] !== '') {
        $html .= '
<p class="muted">'
            . h($k['connectionDetail'])
            . '</p>';
    }

    $html .= '
</div>

<div class="card">
<h2>項目取得</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="fetch_kintone_fields">

<label>kintoneパスワード</label>
<input type="password"
       name="password"
       autocomplete="off"
       required>

<p>
<button>
項目一覧を取得
</button>
</p>
</form>';

    if ($k['fields'] !== []) {
        $html .= '
<div style="overflow-x:auto">
<table>
<thead>
<tr>
<th>フィールドコード</th>
<th>ラベル</th>
<th>型</th>
</tr>
</thead>
<tbody>';

        foreach (
            $k['fields']
            as $code => $field
        ) {
            $html .= '
<tr>
<td>'
                . h($code)
                . '</td>
<td>'
                . h($field['label'] ?? '')
                . '</td>
<td>'
                . h($field['type'] ?? '')
                . '</td>
</tr>';
        }

        $html .= '
</tbody>
</table>
</div>';
    }

    $html .= '
</div>

<div class="card">
<h2>顧客同期</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="sync_kintone">

<label>kintoneパスワード</label>
<input type="password"
       name="password"
       autocomplete="off"
       required>

<p>
<button class="success">
顧客情報を同期
</button>
</p>
</form>

<p>
現在の顧客件数:
'
        . count($data['customers'])
        . '件
</p>
</div>';

    return $html
        . renderFooter();
}

function renderMail(
    array $data
): string {
    $s =
        $data['mailSettings'];

    $html =
        renderHeader('メール設定');

    $html .= renderFlash();

    $html .= '
<div class="card">
<h1>メールサーバ設定</h1>

<p class="muted">
SMTPパスワードは保存しません。
接続テスト・メール送信時に入力してください。
</p>

<form method="post">
<input type="hidden"
       name="action"
       value="save_mail">

<div class="grid">
<div>
<label>SMTPサーバ</label>
<input name="server"
       value="'
        . h($s['server'])
        . '">
</div>

<div>
<label>SMTPポート</label>
<input name="port"
       value="'
        . h($s['port'])
        . '">
</div>

<div>
<label>暗号化</label>
<select name="encryption">
<option value="ssl"'
        . ($s['encryption'] === 'ssl'
            ? ' selected'
            : '')
        . '>SSL</option>
<option value="tls"'
        . ($s['encryption'] === 'tls'
            ? ' selected'
            : '')
        . '>TLS</option>
<option value="none"'
        . ($s['encryption'] === 'none'
            ? ' selected'
            : '')
        . '>なし</option>
</select>
</div>

<div>
<label>SMTPユーザー名</label>
<input name="username"
       value="'
        . h($s['username'])
        . '">
</div>

<div>
<label>送信元メール</label>
<input name="fromEmail"
       value="'
        . h($s['fromEmail'])
        . '">
</div>

<div>
<label>送信元名</label>
<input name="fromName"
       value="'
        . h($s['fromName'])
        . '">
</div>

<div>
<label>返信先</label>
<input name="replyTo"
       value="'
        . h($s['replyTo'])
        . '">
</div>
</div>

<label>
<input type="checkbox"
       name="auth"
       value="1"'
        . (!empty($s['auth'])
            ? ' checked'
            : '')
        . '>
SMTP認証を使用する
</label>

<p>
<button class="success">
設定を保存
</button>
</p>
</form>
</div>

<div class="card">
<h2>SMTP接続テスト</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="test_mail">

<label>SMTPパスワード</label>
<input type="password"
       name="password"
       autocomplete="off"
       required>

<p>
<button>
接続テスト
</button>
</p>
</form>

<p>
接続状態:
<strong>'
        . h($s['connection'])
        . '</strong>
</p>

<p class="muted">'
        . h($s['connectionDetail'])
        . '</p>
</div>';

    return $html
        . renderFooter();
}

function renderSend(
    array $data,
    array $survey
): string {
    $html =
        renderHeader('顧客選択・メール送信');

    $html .= renderFlash();

    $html .= '
<div class="card">
<h1>顧客選択・メール送信</h1>
<p>
対象アンケート:
<strong>'
        . h($survey['title'])
        . '</strong>
</p>
<p class="muted">
送信処理の実装ではSMTP通信結果を確認した後に
送信結果を確定してください。
</p>

<div style="overflow-x:auto">
<table>
<thead>
<tr>
<th>選択</th>
<th>組織</th>
<th>氏名</th>
<th>メール</th>
</tr>
</thead>
<tbody>';

    foreach (
        $data['customers']
        as $customer
    ) {
        $html .= '
<tr>
<td>
<input type="checkbox"
       disabled>
</td>
<td>'
            . h($customer['org'])
            . '</td>
<td>'
            . h($customer['name'])
            . '</td>
<td>'
            . h($customer['email'])
            . '</td>
</tr>';
    }

    $html .= '
</tbody>
</table>
</div>

<p class="muted">
メール本文生成・大量送信を実運用する場合は、
SMTP送信結果を1件ずつ確定してから履歴へ保存してください。
</p>
</div>';

    return $html
        . renderFooter();
}

/* =========================================================
 * Main
 * ========================================================= */

$data = null;

try {
    $data =
        readData();

    updateAutomaticStatus(
        $data
    );

    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        === 'POST'
    ) {
        /*
         * POCではCSRF対策を実装しない。
         */
        processPost(
            $data
        );

        throw new RuntimeException(
            '処理結果を確定できませんでした。'
        );
    }

    $screen =
        getString(
            'screen',
            'list'
        );

    switch ($screen) {
        case 'list':
            echo renderList($data);
            break;

        case 'edit':
            $id =
                getString('id');

            $survey =
                $id !== ''
                    ? surveyById(
                        $data,
                        $id
                    )
                    : null;

            if ($id !== ''
                && $survey === null) {
                throw new RuntimeException(
                    'アンケートが存在しません。'
                );
            }

            echo renderEdit(
                $data,
                $survey
            );
            break;

        case 'preview':
            $id =
                getString('id');

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if ($survey === null) {
                throw new RuntimeException(
                    'アンケートが存在しません。'
                );
            }

            echo renderPreview(
                $survey
            );
            break;

        case 'answer':
            $id =
                getString('id');

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if ($survey === null) {
                throw new RuntimeException(
                    'アンケートが存在しません。'
                );
            }

            if (!surveyAvailable($survey)) {
                throw new RuntimeException(
                    'このアンケートは現在回答できません。'
                );
            }

            echo renderAnswer(
                $survey,
                getAnswerDraft($id)
            );
            break;

        case 'confirm':
            $id =
                getString('id');

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if ($survey === null) {
                throw new RuntimeException(
                    'アンケートが存在しません。'
                );
            }

            echo renderConfirm(
                $survey,
                getAnswerDraft($id)
            );
            break;

        case 'complete':
            $id =
                getString('id');

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if ($survey === null) {
                throw new RuntimeException(
                    'アンケートが存在しません。'
                );
            }

            echo renderComplete(
                $survey
            );
            break;

        case 'analytics':
            $id =
                getString('id');

            if (!validateId($id)) {
                throw new RuntimeException(
                    '集計対象アンケートIDが指定されていません。'
                );
            }

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if ($survey === null) {
                throw new RuntimeException(
                    'アンケートが存在しません。'
                );
            }

            echo renderAnalytics(
                $data,
                $survey
            );
            break;

        case 'send':
            $id =
                getString('id');

            if (!validateId($id)) {
                throw new RuntimeException(
                    '送信対象アンケートIDが指定されていません。'
                );
            }

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if ($survey === null) {
                throw new RuntimeException(
                    'アンケートが存在しません。'
                );
            }

            echo renderSend(
                $data,
                $survey
            );
            break;

        case 'kintone':
            echo renderKintone(
                $data
            );
            break;

        case 'mail':
            echo renderMail(
                $data
            );
            break;

        default:
            throw new RuntimeException(
                '指定された画面は存在しません。'
            );
    }
} catch (Throwable $e) {
    /*
     * 内部例外をそのままブラウザへ出さない。
     * パスワード・認証ヘッダー等も表示しない。
     */
    $message =
        trim($e->getMessage());

    if ($message === '') {
        $message =
            '処理中にエラーが発生しました。';
    }

    $unsafePatterns = [
        '/password\s*[=:]/i',
        '/authorization\s*[=:]/i',
        '/x-cybozu-authorization/i',
        '/cookie\s*[=:]/i',
        '/session\s*[=:]/i',
    ];

    foreach ($unsafePatterns as $pattern) {
        if (preg_match(
            $pattern,
            $message
        )) {
            $message =
                '外部サービスとの通信または設定処理に失敗しました。'
                . '設定内容を確認してください。';

            break;
        }
    }

    echo renderHeader('エラー');

    echo '<div class="flash error">'
        . nl2br(h($message))
        . '</div>';

    echo '<p>
<a class="button secondary"
   href="?screen=list">
アンケート一覧へ戻る
</a>
</p>';

    echo renderFooter();
}

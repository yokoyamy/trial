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
 * 重要仕様
 * - 管理者認証なし
 * - CSRF対策なし
 * - kintoneパスワードは永続保存しない
 * - SMTPパスワードは永続保存しない
 * - kintone認証は X-Cybozu-Authorization
 * - Authorization: Basic と混同しない
 * - 外部302/303は成功扱いしない
 * - 外部通信処理から画面リダイレクトしない
 * - アプリ自身の303はPRG専用
 * - 顧客同期結果はサーバー側へ保存する
 * - 同期済み顧客をkintone設定画面へ表示する
 * - 同期済み顧客を送信画面の顧客選択に利用する
 */

const DATA_DIR  = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'app.dat.php';
const TIMEZONE  = 'Asia/Tokyo';

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

function redirectTo(string $screen, array $params = []): never
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
 * 永続化
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

    if ($contents === false || trim($contents) === '') {
        return defaultData();
    }

    $data = json_decode($contents, true);

    if (!is_array($data)) {
        throw new RuntimeException(
            '保存データが破損しています。'
        );
    }

    $data = array_replace_recursive(
        defaultData(),
        $data
    );

    unset($data['kintone']['password']);
    unset($data['mailSettings']['password']);

    return $data;
}

function saveData(array $data): void
{
    ensureDataDir();

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

            if ($target !== '' && validateId($target)) {
                $branches[(string)$option] = $target;
            }
        }
    }

    return [
        'id' => validateId((string)($q['id'] ?? ''))
            ? (string)$q['id']
            : uid('q'),
        'number' => '',
        'text' => trim((string)($q['text'] ?? '')),
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
            ($survey['numbering'] ?? 'global') === 'group'
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
        foreach ($group['questions'] as $qi => &$question) {
            if ($survey['numbering'] === 'group') {
                $question['number'] =
                    'Q' . ($gi + 1) . '-' . ($qi + 1);
            } else {
                $question['number'] = 'Q' . $global;
            }

            $global++;
        }

        unset($question);
    }

    unset($group);
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

function surveyById(array $data, string $id): ?array
{
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

function canTransition(string $from, string $to): bool
{
    return match ($from) {
        'draft' => $to === 'published',
        'published' => $to === 'stopped',
        'stopped' => $to === 'published',
        default => false,
    };
}

function updateAutomaticStatus(array &$data): void
{
    $changed = false;
    $current = new DateTimeImmutable();

    foreach ($data['surveys'] as &$survey) {
        if (($survey['status'] ?? '') !== 'published') {
            continue;
        }

        $endAt = (string)($survey['endAt'] ?? '');

        if ($endAt === '') {
            continue;
        }

        $end = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            $endAt
        );

        if ($end !== false && $current > $end) {
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
 * 条件分岐
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
        $answer = $answers[$rule['parent']] ?? '';

        if ((string)$answer === $rule['option']) {
            $visible[] = $id;
        }
    }

    return array_values(array_unique($visible));
}

function validateAnswers(
    array $survey,
    array $answers
): array {
    $errors = [];
    $map = questionMap($survey);

    foreach (
        visibleQuestionIds($survey, $answers)
        as $id
    ) {
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

        if (!empty($question['required']) && $empty) {
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
            if (
                !is_string($value)
                || !in_array(
                    $value,
                    $question['options'],
                    true
                )
            ) {
                $errors[] =
                    $question['number']
                    . 'の選択値が不正です。';
            }
        }

        if ($question['type'] === 'multiple') {
            if (!is_array($value)) {
                $errors[] =
                    $question['number']
                    . 'の回答形式が不正です.';
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
 * 共通HTTP
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

    $method = strtoupper($method);

    $options = [
        'http' => [
            'method' => $method,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'protocol_version' => 1.1,
            'header' => implode("\r\n", $headers),
        ],
        'ssl' => [
            'verify_peer' => $verifyTls,
            'verify_peer_name' => $verifyTls,
            'allow_self_signed' => !$verifyTls,
            'SNI_enabled' => true,
            'peer_name' => $parts['host'],
        ],
    ];

    if ($body !== null) {
        $options['http']['content'] = $body;
    }

    if ($proxy !== null && $proxy !== '') {
        if (!preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )) {
            throw new InvalidArgumentException(
                'Proxyはhost:port形式で指定してください。'
            );
        }

        $options['http']['proxy'] =
            'tcp://' . $proxy;

        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);
    $warning = null;

    set_error_handler(
        static function (
            int $severity,
            string $message
        ) use (&$warning): bool {
            $warning = $message;
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
            'error' => '外部サービスへ接続できません。',
        ];
    }

    stream_set_timeout($fp, $timeout);

    $responseBody = stream_get_contents($fp);
    $meta = stream_get_meta_data($fp);

    $responseHeaders = [];
    $status = 0;

    foreach (($meta['wrapper_data'] ?? []) as $line) {
        if (preg_match(
            '#^HTTP/\S+\s+(\d{3})#i',
            $line,
            $m
        )) {
            $status = (int)$m[1];
        } elseif (str_contains($line, ':')) {
            [$key, $value] =
                explode(':', $line, 2);

            $responseHeaders[
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
            'headers' => $responseHeaders,
            'error' => 'レスポンスを取得できませんでした。',
        ];
    }

    if (!empty($meta['timed_out'])) {
        return [
            'ok' => false,
            'category' => 'timeout',
            'status' => $status,
            'body' => $responseBody,
            'headers' => $responseHeaders,
            'error' => '外部サービスへの通信がタイムアウトしました。',
        ];
    }

    if ($status >= 300 && $status < 400) {
        return [
            'ok' => false,
            'category' => 'redirect',
            'status' => $status,
            'body' => $responseBody,
            'headers' => $responseHeaders,
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
            'headers' => $responseHeaders,
            'error' => '',
        ];
    }

    return [
        'ok' => false,
        'category' => 'http_error',
        'status' => $status,
        'body' => $responseBody,
        'headers' => $responseHeaders,
        'error' =>
            '外部サービスからHTTP '
            . $status
            . 'エラーが返されました。',
    ];
}

/* =========================================================
 * kintone
 * ========================================================= */

function normalizeKintoneHost(string $input): string
{
    $input = trim($input);

    $input = preg_replace(
        '#^https?://#i',
        '',
        $input
    );

    $input = preg_replace(
        '#/.*$#',
        '',
        (string)$input
    );

    $input = trim((string)$input);

    if (
        $input === ''
        || !preg_match(
            '/^[A-Za-z0-9.-]+$/',
            $input
        )
    ) {
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

function validateKintoneSettings(
    array $settings
): void {
    normalizeKintoneHost(
        (string)($settings['subdomain'] ?? '')
    );

    $appId = (string)(
        $settings['appId'] ?? ''
    );

    if (
        !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    if (
        trim((string)($settings['username'] ?? ''))
        === ''
    ) {
        throw new InvalidArgumentException(
            'kintoneログイン名を入力してください。'
        );
    }

    $proxy = trim(
        (string)($settings['proxy'] ?? '')
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
}

function kintoneAuthorization(
    string $username,
    string $password
): string {
    if ($username === '' || $password === '') {
        throw new InvalidArgumentException(
            'kintoneログイン名とパスワードを入力してください。'
        );
    }

    return base64_encode(
        $username . ':' . $password
    );
}

function kintoneRequest(
    array $settings,
    string $path,
    string $method,
    string $password
): array {
    validateKintoneSettings($settings);

    if (
        $path === ''
        || $path[0] !== '/'
        || !str_starts_with($path, '/k/v1/')
    ) {
        throw new InvalidArgumentException(
            'kintone APIパスが不正です。'
        );
    }

    $host = normalizeKintoneHost(
        (string)$settings['subdomain']
    );

    $authorization =
        kintoneAuthorization(
            (string)$settings['username'],
            $password
        );

    /*
     * kintone REST APIのパスワード認証。
     *
     * Authorization: Basic ...
     * とは別物である。
     */
    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Accept: application/json',
        'User-Agent: SurveyPOC/4.0',
    ];

    try {
        return httpRequest(
            'https://' . $host . $path,
            $method,
            $headers,
            null,
            20,
            !empty($settings['sslVerify']),
            !empty($settings['proxy'])
                ? (string)$settings['proxy']
                : null
        );
    } finally {
        unset($authorization);
    }
}

function kintoneResponseJson(
    array $response
): array {
    $body = json_decode(
        (string)($response['body'] ?? ''),
        true
    );

    if (!is_array($body)) {
        throw new RuntimeException(
            'kintone APIレスポンスのJSON解析に失敗しました。'
        );
    }

    return $body;
}

function kintoneErrorMessage(
    array $response
): string {
    $body = json_decode(
        (string)($response['body'] ?? ''),
        true
    );

    if (is_array($body)) {
        $code = (string)($body['code'] ?? '');
        $message = (string)($body['message'] ?? '');

        if ($code !== '' || $message !== '') {
            return 'kintone APIエラー'
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
    $appId = (string)$settings['appId'];

    return kintoneRequest(
        $settings,
        '/k/v1/app.json?id='
        . rawurlencode($appId),
        'GET',
        $password
    );
}

function kintoneFields(
    array $settings,
    string $password
): array {
    $appId = (string)$settings['appId'];

    return kintoneRequest(
        $settings,
        '/k/v1/app/form/fields.json?app='
        . rawurlencode($appId),
        'GET',
        $password
    );
}

function kintoneRecordsPage(
    array $settings,
    string $password,
    int $offset = 0,
    int $limit = 500
): array {
    $appId = (string)$settings['appId'];

    if ($limit < 1 || $limit > 500) {
        $limit = 500;
    }

    $query =
        'limit ' . $limit
        . ' offset ' . $offset;

    return kintoneRequest(
        $settings,
        '/k/v1/records.json'
        . '?app=' . rawurlencode($appId)
        . '&totalCount=true'
        . '&query=' . rawurlencode($query),
        'GET',
        $password
    );
}

function kintoneAllRecords(
    array $settings,
    string $password
): array {
    $records = [];
    $offset = 0;
    $limit = 500;
    $totalCount = null;

    while (true) {
        $response = kintoneRecordsPage(
            $settings,
            $password,
            $offset,
            $limit
        );

        if (!$response['ok']) {
            throw new RuntimeException(
                kintoneErrorMessage($response)
            );
        }

        $json = kintoneResponseJson($response);

        if (
            !isset($json['records'])
            || !is_array($json['records'])
        ) {
            throw new RuntimeException(
                'kintone顧客情報を取得できませんでした。'
            );
        }

        if (
            $totalCount === null
            && isset($json['totalCount'])
        ) {
            $totalCount =
                (int)$json['totalCount'];
        }

        foreach ($json['records'] as $record) {
            if (is_array($record)) {
                $records[] = $record;
            }
        }

        $count = count($json['records']);

        if ($count < $limit) {
            break;
        }

        $offset += $count;

        if (
            $totalCount !== null
            && $offset >= $totalCount
        ) {
            break;
        }
    }

    return $records;
}

/* =========================================================
 * kintone POST
 * ========================================================= */

function saveKintoneAction(
    array &$data
): void {
    $rawSubdomain =
        postString('subdomain');

    $subdomain =
        normalizeKintoneHost(
            $rawSubdomain
        );

    $appId =
        postString('appId');

    if (
        !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    $username =
        postString('username');

    if ($username === '') {
        throw new InvalidArgumentException(
            'kintoneログイン名を入力してください。'
        );
    }

    $proxy =
        postString('proxy');

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

    /*
     * 設定変更時には、過去の接続確認を
     * 新設定に対する確認済みとは扱わない。
     */
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
        $response =
            kintoneTest(
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

        /*
         * 接続失敗は明示的に失敗状態へ更新する。
         * 成功状態へ変更しない。
         */
        $data['kintone']['connection'] =
            '接続できません';

        $data['kintone']['connectionDetail'] =
            $message;

        saveData($data);

        throw new RuntimeException(
            $message
        );
    }

    /*
     * HTTP 2xx + JSON解析まで成功して初めて
     * 接続確認済みとする。
     */
    $json =
        kintoneResponseJson(
            $response
        );

    if (!isset($json['id'])) {
        throw new RuntimeException(
            'kintoneアプリ情報のレスポンスを検証できませんでした。'
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
        $response =
            kintoneFields(
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

    $json =
        kintoneResponseJson(
            $response
        );

    if (
        !isset($json['properties'])
        || !is_array($json['properties'])
    ) {
        throw new RuntimeException(
            'kintone項目一覧を取得できませんでした。'
        );
    }

    $fields = [];

    foreach ($json['properties'] as $code => $field) {
        if (!is_array($field)) {
            continue;
        }

        $fields[(string)$code] = [
            'label' =>
                (string)($field['label'] ?? ''),
            'type' =>
                (string)($field['type'] ?? ''),
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

function readKintoneRecordValue(
    array $record,
    string $fieldCode
): string {
    if (
        $fieldCode === ''
        || !isset($record[$fieldCode])
        || !is_array($record[$fieldCode])
    ) {
        return '';
    }

    $value =
        $record[$fieldCode]['value']
        ?? '';

    if (is_scalar($value)) {
        return trim((string)$value);
    }

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $parts[] = (string)$item;
                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            if (
                isset($item['name'])
                && is_scalar($item['name'])
            ) {
                $parts[] =
                    (string)$item['name'];
            } elseif (
                isset($item['code'])
                && is_scalar($item['code'])
            ) {
                $parts[] =
                    (string)$item['code'];
            }
        }

        return trim(implode(' ', $parts));
    }

    return '';
}

function mapKintoneCustomer(
    array $record,
    array $mapping
): array {
    $address = [];

    foreach (
        (array)($mapping['address'] ?? [])
        as $fieldCode
    ) {
        $value =
            readKintoneRecordValue(
                $record,
                (string)$fieldCode
            );

        if ($value !== '') {
            $address[] = $value;
        }
    }

    return [
        'id' => uid('customer'),

        'org' =>
            readKintoneRecordValue(
                $record,
                (string)(
                    $mapping['org'] ?? ''
                )
            ),

        'name' =>
            readKintoneRecordValue(
                $record,
                (string)(
                    $mapping['name'] ?? ''
                )
            ),

        'email' =>
            readKintoneRecordValue(
                $record,
                (string)(
                    $mapping['email'] ?? ''
                )
            ),

        'department' =>
            readKintoneRecordValue(
                $record,
                (string)(
                    $mapping['department'] ?? ''
                )
            ),

        'phone' =>
            readKintoneRecordValue(
                $record,
                (string)(
                    $mapping['phone'] ?? ''
                )
            ),

        'address' =>
            implode(' ', $address),
    ];
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
        /*
         * 1回だけではなく、全ページを取得する。
         */
        $records =
            kintoneAllRecords(
                $data['kintone'],
                $password
            );
    } finally {
        unset($password);
    }

    $mapping =
        is_array(
            $data['kintone']['mappings']
            ?? null
        )
            ? $data['kintone']['mappings']
            : [];

    $customers = [];

    foreach ($records as $record) {
        $customer =
            mapKintoneCustomer(
                $record,
                $mapping
            );

        /*
         * 顧客選択・メール送信に利用できる
         * 最低限の識別情報を検証する。
         */
        if (
            $customer['email'] !== ''
            && !validEmail($customer['email'])
        ) {
            $customer['email'] = '';
        }

        $customers[] = $customer;
    }

    /*
     * API通信とレスポンス解析、
     * 顧客データ変換が完了してから保存する。
     */
    $data['customers'] =
        $customers;

    saveData($data);

    /*
     * 同期成功結果を確定してから303。
     */
    $data['kintone']['connection'] =
        '接続確認済み';

    $data['kintone']['connectionDetail'] =
        '顧客情報の同期に成功しました。';

    saveData($data);

    flash(
        'success',
        count($customers)
        . '件の顧客情報を同期しました。'
    );

    redirectTo('kintone');
}

/* =========================================================
 * Survey POST
 * ========================================================= */

function saveSurveyAction(
    array &$data
): void {
    $id = postString('id');

    if (
        $id !== ''
        && !validateId($id)
    ) {
        throw new InvalidArgumentException(
            'アンケートIDが不正です。'
        );
    }

    $groupsPost =
        postArray('groups');

    $groups = [];

    foreach ($groupsPost as $groupPost) {
        if (!is_array($groupPost)) {
            continue;
        }

        $questions = [];

        foreach (
            ($groupPost['questions'] ?? [])
            as $question
        ) {
            if (is_array($question)) {
                $questions[] =
                    normalizeQuestion(
                        $question
                    );
            }
        }

        $groups[] = [
            'id' =>
                validateId(
                    (string)($groupPost['id'] ?? '')
                )
                    ? (string)$groupPost['id']
                    : uid('g'),

            'title' =>
                trim(
                    (string)(
                        $groupPost['title'] ?? ''
                    )
                ),

            'questions' =>
                $questions,
        ];
    }

    $survey =
        normalizeSurvey([
            'id' => $id,
            'createdAt' => today(),
            'updatedAt' => today(),
            'title' => postString('title'),
            'description' =>
                postString('description'),
            'startAt' =>
                postString('startAt'),
            'endAt' =>
                postString('endAt'),
            'status' =>
                postString(
                    'status',
                    'draft'
                ),
            'numbering' =>
                postString(
                    'numbering',
                    'global'
                ),
            'groups' =>
                $groups,
        ]);

    if ($survey['title'] === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルを入力してください。'
        );
    }

    if (
        !validDateTime($survey['startAt'])
        || !validDateTime($survey['endAt'])
    ) {
        throw new InvalidArgumentException(
            '公開期間の日時形式が不正です。'
        );
    }

    if (
        $survey['startAt'] !== ''
        && $survey['endAt'] !== ''
        && $survey['startAt'] > $survey['endAt']
    ) {
        throw new InvalidArgumentException(
            '終了日時は開始日時以降にしてください。'
        );
    }

    $index =
        surveyIndex(
            $data,
            $survey['id']
        );

    if ($index >= 0) {
        $survey['createdAt'] =
            $data['surveys'][$index]['createdAt']
            ?? today();

        $data['surveys'][$index] =
            $survey;
    } else {
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

function duplicateSurveyAction(
    array &$data
): void {
    $id = postString('id');

    if (!validateId($id)) {
        throw new InvalidArgumentException(
            '複製対象IDが不正です。'
        );
    }

    $survey =
        surveyById(
            $data,
            $id
        );

    if (!$survey) {
        throw new RuntimeException(
            '複製対象アンケートが存在しません。'
        );
    }

    $survey['id'] =
        uid('survey');

    $survey['title'] =
        $survey['title'] . '（複製）';

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

function deleteSurveyAction(
    array &$data
): void {
    $id = postString('id');

    if (!validateId($id)) {
        throw new InvalidArgumentException(
            '削除対象IDが不正です。'
        );
    }

    $index =
        surveyIndex(
            $data,
            $id
        );

    if ($index < 0) {
        throw new RuntimeException(
            '削除対象アンケートが存在しません。'
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

function changeSurveyStatusAction(
    array &$data
): void {
    $id =
        postString('id');

    $to =
        postString('status');

    if (!validateId($id)) {
        throw new InvalidArgumentException(
            'アンケートIDが不正です。'
        );
    }

    $index =
        surveyIndex(
            $data,
            $id
        );

    if ($index < 0) {
        throw new RuntimeException(
            '対象アンケートが存在しません。'
        );
    }

    $from =
        (string)(
            $data['surveys'][$index]['status']
            ?? 'draft'
        );

    if (!canTransition($from, $to)) {
        throw new InvalidArgumentException(
            '許可されていない状態遷移です。'
        );
    }

    $data['surveys'][$index]['status'] =
        $to;

    $data['surveys'][$index]['updatedAt'] =
        today();

    saveData($data);

    flash(
        'success',
        'アンケート状態を変更しました。'
    );

    redirectTo('list');
}

/* =========================================================
 * 回答
 * ========================================================= */

function answerDraftAction(
    array &$data
): void {
    $surveyId =
        postString('surveyId');

    $survey =
        surveyById(
            $data,
            $surveyId
        );

    if (
        !$survey
        || !surveyAvailable($survey)
    ) {
        throw new RuntimeException(
            '回答可能なアンケートではありません。'
        );
    }

    $answers =
        postArray('answers');

    $errors =
        validateAnswers(
            $survey,
            $answers
        );

    if ($errors) {
        $_SESSION['answer_draft'] =
            $answers;

        $_SESSION['answer_errors'] =
            $errors;

        redirectTo(
            'answer',
            ['id' => $surveyId]
        );
    }

    $_SESSION['answer_draft'] =
        $answers;

    $_SESSION['answer_survey'] =
        $surveyId;

    $_SESSION['answer_customer'] =
        postString('customerId');

    redirectTo(
        'confirm',
        ['id' => $surveyId]
    );
}

function answerSubmitAction(
    array &$data
): void {
    $surveyId =
        postString('surveyId');

    $survey =
        surveyById(
            $data,
            $surveyId
        );

    if (
        !$survey
        || !surveyAvailable($survey)
    ) {
        throw new RuntimeException(
            '回答可能なアンケートではありません。'
        );
    }

    $answers =
        $_SESSION['answer_draft']
        ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $errors =
        validateAnswers(
            $survey,
            $answers
        );

    if ($errors) {
        $_SESSION['answer_errors'] =
            $errors;

        redirectTo(
            'answer',
            ['id' => $surveyId]
        );
    }

    $customerId =
        (string)(
            $_SESSION['answer_customer']
            ?? ''
        );

    if (!validateId($customerId)) {
        $customerId = '';
    }

    $customer = null;

    foreach (
        $data['customers']
        as $candidate
    ) {
        if (
            ($candidate['id'] ?? '')
            === $customerId
        ) {
            $customer = $candidate;
            break;
        }
    }

    $data['answers'][$surveyId] ??= [];

    $data['answers'][$surveyId][] = [
        'id' => uid('answer'),
        'customerId' => $customerId,
        'customer' =>
            $customer['name']
            ?? '未登録回答者',
        'org' =>
            $customer['org']
            ?? '',
        'email' =>
            $customer['email']
            ?? '',
        'date' => now(),
        'values' => $answers,
    ];

    saveData($data);

    unset(
        $_SESSION['answer_draft'],
        $_SESSION['answer_errors'],
        $_SESSION['answer_survey'],
        $_SESSION['answer_customer']
    );

    redirectTo(
        'complete',
        ['id' => $surveyId]
    );
}

/* =========================================================
 * SMTP
 * ========================================================= */

function smtpRead(
    $socket,
    int $timeout = 20
): string {
    stream_set_timeout(
        $socket,
        $timeout
    );

    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (
            strlen($line) >= 4
            && $line[3] === ' '
        ) {
            break;
        }
    }

    return $response;
}

function smtpCode(string $response): int
{
    if (
        preg_match(
            '/^(\d{3})/m',
            $response,
            $m
        )
    ) {
        return (int)$m[1];
    }

    return 0;
}

function smtpCommand(
    $socket,
    string $command,
    array $expected
): string {
    fwrite(
        $socket,
        $command . "\r\n"
    );

    $response =
        smtpRead($socket);

    $code =
        smtpCode($response);

    if (!in_array($code, $expected, true)) {
        throw new RuntimeException(
            'SMTPサーバーとの通信に失敗しました。'
        );
    }

    return $response;
}

function smtpConnect(
    array $settings,
    string $password
): array {
    $server =
        trim((string)(
            $settings['server'] ?? ''
        ));

    $port =
        (int)(
            $settings['port'] ?? 587
        );

    $encryption =
        strtolower((string)(
            $settings['encryption'] ?? 'tls'
        ));

    if (
        $server === ''
        || $port < 1
        || $port > 65535
    ) {
        throw new InvalidArgumentException(
            'SMTP設定が不正です。'
        );
    }

    $transport =
        $encryption === 'ssl'
            ? 'ssl://'
            : 'tcp://';

    $errno = 0;
    $errstr = '';

    $socket =
        @fsockopen(
            $transport . $server,
            $port,
            $errno,
            $errstr,
            20
        );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPサーバーへ接続できません。'
        );
    }

    try {
        $greeting =
            smtpRead($socket);

        if (smtpCode($greeting) !== 220) {
            throw new RuntimeException(
                'SMTPサーバーの応答が不正です。'
            );
        }

        smtpCommand(
            $socket,
            'EHLO localhost',
            [250]
        );

        if ($encryption === 'tls') {
            smtpCommand(
                $socket,
                'STARTTLS',
                [220]
            );

            $crypto =
                stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'SMTP TLS接続を確立できません。'
                );
            }

            smtpCommand(
                $socket,
                'EHLO localhost',
                [250]
            );
        }

        if (!empty($settings['auth'])) {
            $username =
                (string)(
                    $settings['username'] ?? ''
                );

            if (
                $username === ''
                || $password === ''
            ) {
                throw new InvalidArgumentException(
                    'SMTP認証情報を入力してください。'
                );
            }

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

        return [
            'socket' => $socket,
            'server' => $server,
        ];
    } catch (Throwable $e) {
        fclose($socket);
        throw $e;
    }
}

function smtpSend(
    array $settings,
    string $password,
    string $to,
    string $subject,
    string $body
): array {
    if (!validEmail($to)) {
        throw new InvalidArgumentException(
            '送信先メールアドレスが不正です。'
        );
    }

    $connection =
        smtpConnect(
            $settings,
            $password
        );

    $socket =
        $connection['socket'];

    try {
        $from =
            trim((string)(
                $settings['fromEmail'] ?? ''
            ));

        if (!validEmail($from)) {
            throw new InvalidArgumentException(
                '送信元メールアドレスが不正です。'
            );
        }

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

        $fromName =
            trim((string)(
                $settings['fromName'] ?? ''
            ));

        $headers = [
            'From: '
                . ($fromName !== ''
                    ? '=?UTF-8?B?'
                    . base64_encode($fromName)
                    . '?= '
                    : '')
                . '<' . $from . '>',

            'To: <' . $to . '>',

            'Subject: =?UTF-8?B?'
                . base64_encode($subject)
                . '?=',

            'MIME-Version: 1.0',

            'Content-Type: text/plain; charset=UTF-8',
        ];

        $replyTo =
            trim((string)(
                $settings['replyTo'] ?? ''
            ));

        if ($replyTo !== '' && validEmail($replyTo)) {
            $headers[] =
                'Reply-To: <' . $replyTo . '>';
        }

        $payload =
            implode(
                "\r\n",
                $headers
            )
            . "\r\n\r\n"
            . str_replace(
                "\n.",
                "\n..",
                str_replace(
                    "\r\n",
                    "\n",
                    $body
                )
            )
            . "\r\n.";

        smtpCommand(
            $socket,
            $payload,
            [250]
        );

        smtpCommand(
            $socket,
            'QUIT',
            [221]
        );

        return [
            'ok' => true,
            'category' => 'success',
        ];
    } finally {
        fclose($socket);
    }
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
        (int)postString(
            'port',
            '587'
        );

    $encryption =
        postString(
            'encryption',
            'tls'
        );

    $auth =
        postString(
            'auth',
            '1'
        ) === '1';

    $username =
        postString('username');

    $fromEmail =
        postString('fromEmail');

    $fromName =
        postString('fromName');

    $replyTo =
        postString('replyTo');

    if ($server === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを入力してください。'
        );
    }

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    if (
        !in_array(
            $encryption,
            ['ssl', 'tls', 'none'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            'SMTP暗号化方式が不正です。'
        );
    }

    if (
        $fromEmail === ''
        || !validEmail($fromEmail)
    ) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    if (
        $replyTo !== ''
        && !validEmail($replyTo)
    ) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが不正です。'
        );
    }

    $data['mailSettings'] = [
        'server' => $server,
        'port' => $port,
        'encryption' => $encryption,
        'auth' => $auth,
        'username' => $username,
        'fromEmail' => $fromEmail,
        'fromName' => $fromName,
        'replyTo' => $replyTo,
        'connection' => '未設定',
        'connectionDetail' => '',
    ];

    saveData($data);

    flash(
        'success',
        'メール設定を保存しました。'
    );

    redirectTo('mail');
}

function testMailAction(
    array &$data
): void {
    $password =
        postString('password');

    if ($password === '') {
        throw new InvalidArgumentException(
            'SMTPパスワードを入力してください。'
        );
    }

    try {
        $connection =
            smtpConnect(
                $data['mailSettings'],
                $password
            );

        fclose(
            $connection['socket']
        );
    } finally {
        unset($password);
    }

    $data['mailSettings']['connection'] =
        '接続確認済み';

    $data['mailSettings']['connectionDetail'] =
        'SMTP接続と認証に成功しました。';

    saveData($data);

    flash(
        'success',
        'SMTP接続テストに成功しました。'
    );

    redirectTo('mail');
}

function sendMailAction(
    array &$data
): void {
    $surveyId =
        postString('surveyId');

    if (!validateId($surveyId)) {
        throw new InvalidArgumentException(
            'アンケートIDが不正です。'
        );
    }

    $survey =
        surveyById(
            $data,
            $surveyId
        );

    if (!$survey) {
        throw new RuntimeException(
            '対象アンケートが存在しません。'
        );
    }

    $selected =
        postArray('customerIds');

    $subject =
        postString('subject');

    $body =
        postString('body');

    $password =
        postString('password');

    if ($subject === '') {
        throw new InvalidArgumentException(
            'メール件名を入力してください。'
        );
    }

    if ($body === '') {
        throw new InvalidArgumentException(
            'メール本文を入力してください。'
        );
    }

    if ($password === '') {
        throw new InvalidArgumentException(
            'SMTPパスワードを入力してください。'
        );
    }

    $targets = [];

    foreach ($selected as $id) {
        $id = trim((string)$id);

        if (!validateId($id)) {
            continue;
        }

        foreach (
            $data['customers']
            as $customer
        ) {
            if (
                ($customer['id'] ?? '')
                === $id
                && validEmail(
                    (string)(
                        $customer['email']
                        ?? ''
                    )
                )
            ) {
                $targets[] = $customer;
                break;
            }
        }
    }

    if (!$targets) {
        throw new InvalidArgumentException(
            '送信対象の顧客を選択してください。'
        );
    }

    $success = 0;
    $failed = 0;
    $results = [];

    try {
        foreach ($targets as $customer) {
            $personalBody =
                str_replace(
                    '{顧客名}',
                    (string)(
                        $customer['name']
                        ?? ''
                    ),
                    $body
                );

            $url =
                rtrim(
                    currentBaseUrl(),
                    '/'
                )
                . '?screen=answer&id='
                . rawurlencode($surveyId);

            $personalBody =
                str_replace(
                    '{アンケートURL}',
                    $url,
                    $personalBody
                );

            try {
                smtpSend(
                    $data['mailSettings'],
                    $password,
                    (string)$customer['email'],
                    $subject,
                    $personalBody
                );

                $success++;

                $results[] = [
                    'customerId' =>
                        $customer['id'],
                    'email' =>
                        $customer['email'],
                    'status' =>
                        'success',
                    'date' =>
                        now(),
                ];
            } catch (Throwable $e) {
                $failed++;

                $results[] = [
                    'customerId' =>
                        $customer['id'],
                    'email' =>
                        $customer['email'],
                    'status' =>
                        'failed',
                    'date' =>
                        now(),
                    'message' =>
                        safeErrorMessage($e),
                ];
            }
        }
    } finally {
        unset($password);
    }

    /*
     * SMTP通信結果がすべて確定してから履歴保存。
     */
    foreach ($results as $result) {
        $data['sendHistory'][] = [
            'id' => uid('send'),
            'surveyId' => $surveyId,
            'customerId' =>
                $result['customerId'],
            'email' =>
                $result['email'],
            'status' =>
                $result['status'],
            'date' =>
                $result['date'],
            'message' =>
                $result['message'] ?? '',
        ];
    }

    saveData($data);

    $_SESSION['send_result'] = [
        'success' => $success,
        'failed' => $failed,
    ];

    /*
     * 送信後も専用履歴画面へ移動しない。
     * 同じsend画面へPRGする。
     */
    redirectTo(
        'send',
        ['id' => $surveyId]
    );
}

function currentBaseUrl(): string
{
    $scheme =
        (
            !empty($_SERVER['HTTPS'])
            && strtolower(
                (string)$_SERVER['HTTPS']
            ) !== 'off'
        )
            ? 'https'
            : 'http';

    $host =
        (string)(
            $_SERVER['HTTP_HOST']
            ?? 'localhost'
        );

    $script =
        (string)(
            $_SERVER['SCRIPT_NAME']
            ?? '/index.php'
        );

    return $scheme . '://' . $host . $script;
}

/* =========================================================
 * POST dispatch
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

        case 'save_mail':
            saveMailAction($data);
            return;

        case 'test_mail':
            testMailAction($data);
            return;

        case 'save_survey':
            saveSurveyAction($data);
            return;

        case 'duplicate':
            duplicateSurveyAction($data);
            return;

        case 'delete_survey':
            deleteSurveyAction($data);
            return;

        case 'change_status':
            changeSurveyStatusAction($data);
            return;

        case 'answer_draft':
            answerDraftAction($data);
            return;

        case 'answer_submit':
            answerSubmitAction($data);
            return;

        case 'send_mail':
            sendMailAction($data);
            return;

        default:
            throw new InvalidArgumentException(
                '不正なPOST処理です。'
            );
    }
}

/* =========================================================
 * HTML
 * ========================================================= */

function renderHeader(
    string $title,
    bool $admin = true
): string {
    $nav = '';

    if ($admin) {
        $nav = '
<nav class="nav">
<a href="?screen=list">アンケート一覧</a>
<a href="?screen=kintone">kintone設定</a>
<a href="?screen=mail">メール設定</a>
</nav>';
    }

    return '<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
 content="width=device-width,initial-scale=1">
<title>'
        . h($title)
        . '</title>
<style>
:root{
 --bg:#f5f7fb;
 --card:#fff;
 --text:#263238;
 --muted:#68737d;
 --border:#dfe4ea;
 --primary:#2563eb;
 --primary2:#1d4ed8;
 --danger:#dc2626;
 --success:#15803d;
 --shadow:0 2px 12px rgba(0,0,0,.06);
 --radius:10px;
}
*{box-sizing:border-box}
body{
 margin:0;
 background:var(--bg);
 color:var(--text);
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",sans-serif;
 line-height:1.6;
}
header{
 background:#fff;
 border-bottom:1px solid var(--border);
}
.header-inner{
 max-width:1200px;
 margin:auto;
 padding:16px 20px;
 display:flex;
 justify-content:space-between;
 gap:20px;
 align-items:center;
}
.header-inner h1{
 margin:0;
 font-size:20px;
}
.nav{
 display:flex;
 gap:8px;
 flex-wrap:wrap;
}
.nav a{
 color:#334155;
 text-decoration:none;
 padding:7px 10px;
 border-radius:7px;
}
.nav a:hover{background:#eef2ff}
main{
 max-width:1200px;
 margin:0 auto;
 padding:24px 20px 60px;
}
.card{
 background:var(--card);
 border:1px solid var(--border);
 border-radius:var(--radius);
 box-shadow:var(--shadow);
 padding:20px;
 margin-bottom:18px;
}
h2,h3{margin-top:0}
.field{margin-bottom:15px}
label{
 display:block;
 font-weight:600;
 margin-bottom:5px;
}
input,textarea,select{
 width:100%;
 padding:10px 12px;
 border:1px solid #cbd5e1;
 border-radius:7px;
 background:#fff;
 font:inherit;
}
textarea{min-height:120px}
button,.btn{
 display:inline-block;
 padding:9px 14px;
 border:1px solid #cbd5e1;
 border-radius:7px;
 background:#fff;
 color:#1e293b;
 text-decoration:none;
 cursor:pointer;
 font:inherit;
}
button:hover,.btn:hover{background:#f8fafc}
button.primary,.btn.primary{
 background:var(--primary);
 color:#fff;
 border-color:var(--primary);
}
button.primary:hover,.btn.primary:hover{
 background:var(--primary2);
}
button.danger,.btn.danger{
 color:#fff;
 background:var(--danger);
 border-color:var(--danger);
}
.actions{
 display:flex;
 flex-wrap:wrap;
 gap:8px;
 margin-top:15px;
}
.grid2{
 display:grid;
 grid-template-columns:repeat(2,minmax(0,1fr));
 gap:15px;
}
.grid3{
 display:grid;
 grid-template-columns:repeat(3,minmax(0,1fr));
 gap:15px;
}
.table-wrap{
 overflow-x:auto;
}
table{
 width:100%;
 border-collapse:collapse;
 background:#fff;
}
th,td{
 border-bottom:1px solid var(--border);
 padding:10px;
 text-align:left;
 vertical-align:top;
 white-space:nowrap;
}
th{background:#f8fafc}
.muted{color:var(--muted)}
.alert{
 border-radius:8px;
 padding:12px 15px;
 margin-bottom:15px;
 white-space:pre-line;
}
.alert.success{
 color:#166534;
 background:#dcfce7;
 border:1px solid #bbf7d0;
}
.alert.error{
 color:#991b1b;
 background:#fee2e2;
 border:1px solid #fecaca;
}
.status{
 display:inline-block;
 padding:3px 8px;
 border-radius:999px;
 font-size:12px;
}
.status.draft{background:#e2e8f0}
.status.published{background:#dcfce7;color:#166534}
.status.stopped{background:#fef3c7;color:#92400e}
.status.ended{background:#fee2e2;color:#991b1b}
.question{
 border:1px solid var(--border);
 border-radius:8px;
 padding:15px;
 margin-bottom:12px;
 background:#fff;
}
.option{
 display:flex;
 gap:8px;
 align-items:center;
 margin:7px 0;
}
.option input{width:auto}
footer{
 max-width:1200px;
 margin:auto;
 padding:0 20px 30px;
 color:var(--muted);
}
@media(max-width:700px){
 .header-inner{
  display:block;
 }
 .nav{
  margin-top:10px;
 }
 main{padding:15px 12px 40px}
 .grid2,.grid3{
  grid-template-columns:1fr;
 }
 .card{padding:15px}
}
</style>
</head>
<body>
<header>
<div class="header-inner">
<h1>アンケートアプリ</h1>
'
        . $nav
        . '
</div>
</header>
<main>';
}

function renderFooter(): string
{
    return '</main>
<footer>アンケートアプリ</footer>
</body>
</html>';
}

function renderList(array $data): string
{
    $html =
        '<h1>アンケート一覧</h1>';

    $html .= '<div class="actions">
<a class="btn primary"
 href="?screen=edit">
新規作成
</a>
<a class="btn"
 href="?screen=kintone">
kintone設定
</a>
<a class="btn"
 href="?screen=mail">
メール設定
</a>
</div>';

    $html .= '<div class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
<th>タイトル</th>
<th>更新日</th>
<th>公開期間</th>
<th>状態</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>
<tbody>';

    foreach ($data['surveys'] as $survey) {
        $id =
            (string)$survey['id'];

        $status =
            (string)(
                $survey['status']
                ?? 'draft'
            );

        $count =
            count(
                $data['answers'][$id]
                ?? []
            );

        $html .= '<tr>
<td>'
            . h($survey['title'])
            . '</td>
<td>'
            . h($survey['updatedAt'] ?? '')
            . '</td>
<td>'
            . h($survey['startAt'] ?? '')
            . ' ～ '
            . h($survey['endAt'] ?? '')
            . '</td>
<td><span class="status '
            . h($status)
            . '">'
            . h(match ($status) {
                'draft' => '下書き',
                'published' => '公開中',
                'stopped' => '停止',
                'ended' => '終了',
                default => $status,
            })
            . '</span></td>
<td>'
            . h($count)
            . '</td>
<td>
<div class="actions">
<a class="btn"
 href="?screen=edit&id='
            . rawurlencode($id)
            . '">編集</a>
<a class="btn"
 href="?screen=preview&id='
            . rawurlencode($id)
            . '">プレビュー</a>
<a class="btn"
 href="?screen=send&id='
            . rawurlencode($id)
            . '">送信</a>
<a class="btn"
 href="?screen=analytics&id='
            . rawurlencode($id)
            . '">集計</a>';

        if ($status === 'draft') {
            $html .= '
<form method="post" style="display:inline">
<input type="hidden"
 name="action"
 value="change_status">
<input type="hidden"
 name="id"
 value="'
                . h($id)
                . '">
<input type="hidden"
 name="status"
 value="published">
<button>公開</button>
</form>';
        } elseif ($status === 'published') {
            $html .= '
<form method="post" style="display:inline">
<input type="hidden"
 name="action"
 value="change_status">
<input type="hidden"
 name="id"
 value="'
                . h($id)
                . '">
<input type="hidden"
 name="status"
 value="stopped">
<button>停止</button>
</form>';
        } elseif ($status === 'stopped') {
            $html .= '
<form method="post" style="display:inline">
<input type="hidden"
 name="action"
 value="change_status">
<input type="hidden"
 name="id"
 value="'
                . h($id)
                . '">
<input type="hidden"
 name="status"
 value="published">
<button>再公開</button>
</form>';
        }

        $html .= '
<form method="post" style="display:inline">
<input type="hidden"
 name="action"
 value="duplicate">
<input type="hidden"
 name="id"
 value="'
            . h($id)
            . '">
<button>複製</button>
</form>

<form method="post"
 style="display:inline"
 onsubmit="return confirm(\'削除しますか？\')">
<input type="hidden"
 name="action"
 value="delete_survey">
<input type="hidden"
 name="id"
 value="'
            . h($id)
            . '">
<button class="danger">削除</button>
</form>
</div>
</td>
</tr>';
    }

    if (!$data['surveys']) {
        $html .= '<tr>
<td colspan="6">
現在、アンケートはありません。
</td>
</tr>';
    }

    $html .= '</tbody>
</table>
</div>
</div>';

    return $html;
}

function renderEdit(
    array $data,
    ?array $survey
): string {
    if ($survey === null) {
        $survey = normalizeSurvey([
            'id' => uid('survey'),
            'createdAt' => today(),
            'updatedAt' => today(),
            'title' => '',
            'description' => '',
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [
                [
                    'id' => uid('g'),
                    'title' => '',
                    'questions' => [
                        [
                            'id' => uid('q'),
                            'text' => '',
                            'type' => 'single',
                            'required' => false,
                            'options' => [''],
                            'branches' => [],
                        ],
                    ],
                ],
            ],
        ]);
    }

    $html =
        '<h1>アンケート作成・編集</h1>';

    $html .= '<form method="post">
<input type="hidden"
 name="action"
 value="save_survey">
<input type="hidden"
 name="id"
 value="'
        . h($survey['id'])
        . '">

<div class="card">
<div class="field">
<label>タイトル</label>
<input name="title"
 required
 value="'
        . h($survey['title'])
        . '">
</div>

<div class="field">
<label>説明</label>
<textarea name="description">'
        . h($survey['description'])
        . '</textarea>
</div>

<div class="grid2">
<div class="field">
<label>開始日時</label>
<input type="datetime-local"
 name="startAt"
 value="'
        . h($survey['startAt'])
        . '">
</div>

<div class="field">
<label>終了日時</label>
<input type="datetime-local"
 name="endAt"
 value="'
        . h($survey['endAt'])
        . '">
</div>
</div>

<div class="field">
<label>質問番号</label>
<select name="numbering">
<option value="global" '
        . ($survey['numbering'] === 'global'
            ? 'selected'
            : '')
        . '>全体連番</option>
<option value="group" '
        . ($survey['numbering'] === 'group'
            ? 'selected'
            : '')
        . '>グループ別</option>
</select>
</div>
</div>';

    foreach (
        $survey['groups']
        as $gi => $group
    ) {
        $html .= '<div class="card">
<h2>グループ '
            . h($gi + 1)
            . '</h2>

<input type="hidden"
 name="groups['
            . h($gi)
            . '][id]"
 value="'
            . h($group['id'])
            . '">

<div class="field">
<label>グループ名</label>
<input name="groups['
            . h($gi)
            . '][title]"
 value="'
            . h($group['title'])
            . '">
</div>';

        foreach (
            $group['questions']
            as $qi => $question
        ) {
            $html .= '<div class="question">
<input type="hidden"
 name="groups['
                . h($gi)
                . '][questions]['
                . h($qi)
                . '][id]"
 value="'
                . h($question['id'])
                . '">

<div class="field">
<label>質問文</label>
<input name="groups['
                . h($gi)
                . '][questions]['
                . h($qi)
                . '][text]"
 value="'
                . h($question['text'])
                . '">
</div>

<div class="grid2">
<div class="field">
<label>回答形式</label>
<select name="groups['
                . h($gi)
                . '][questions]['
                . h($qi)
                . '][type]">
<option value="single" '
                . ($question['type'] === 'single'
                    ? 'selected'
                    : '')
                . '>単一選択</option>
<option value="multiple" '
                . ($question['type'] === 'multiple'
                    ? 'selected'
                    : '')
                . '>複数選択</option>
<option value="free" '
                . ($question['type'] === 'free'
                    ? 'selected'
                    : '')
                . '>自由記述</option>
</select>
</div>

<div class="field">
<label>必須</label>
<select name="groups['
                . h($gi)
                . '][questions]['
                . h($qi)
                . '][required]">
<option value="0">任意</option>
<option value="1" '
                . (!empty($question['required'])
                    ? 'selected'
                    : '')
                . '>必須</option>
</select>
</div>
</div>';

            if ($question['type'] !== 'free') {
                foreach (
                    $question['options']
                    as $oi => $option
                ) {
                    $html .= '<div class="field">
<label>選択肢 '
                        . h($oi + 1)
                        . '</label>
<input name="groups['
                        . h($gi)
                        . '][questions]['
                        . h($qi)
                        . '][options]['
                        . h($oi)
                        . ']"
 value="'
                        . h($option)
                        . '">
</div>';
                }
            }

            $html .= '</div>';
        }

        $html .= '</div>';
    }

    $html .= '<div class="actions">
<button class="primary">
保存して一覧へ
</button>
<a class="btn"
 href="?screen=list">
キャンセル
</a>
</div>
</form>';

    return $html;
}

function renderPreview(
    array $survey
): string {
    $html =
        '<h1>プレビュー</h1>';

    $html .= '<div class="card">
<h2>'
        . h($survey['title'])
        . '</h2>';

    if ($survey['description'] !== '') {
        $html .= '<p>'
            . nl2br(
                h($survey['description'])
            )
            . '</p>';
    }

    foreach (
        $survey['groups']
        as $group
    ) {
        if ($group['title'] !== '') {
            $html .= '<h3>'
                . h($group['title'])
                . '</h3>';
        }

        foreach (
            $group['questions']
            as $question
        ) {
            $html .= '<div class="question">
<strong>'
                . h($question['number'])
                . '</strong>
<p>'
                . h($question['text'])
                . '</p>';

            if ($question['type'] === 'free') {
                $html .= '<textarea disabled></textarea>';
            } else {
                foreach (
                    $question['options']
                    as $option
                ) {
                    $html .= '<div class="option">
<input type="'
                        . (
                            $question['type'] === 'single'
                                ? 'radio'
                                : 'checkbox'
                        )
                        . '" disabled>
<span>'
                        . h($option)
                        . '</span>
</div>';
                }
            }

            $html .= '</div>';
        }
    }

    $html .= '</div>';

    return $html;
}

/* =========================================================
 * kintone画面
 *
 * 今回の根本原因を解消する中心部分。
 * 同期済み$data['customers']を必ず描画する。
 * ========================================================= */

function renderKintoneCustomerList(
    array $data
): string {
    $customers =
        is_array(
            $data['customers'] ?? null
        )
            ? $data['customers']
            : [];

    $html =
        '<div class="card">
<h2>同期済み顧客一覧</h2>';

    if (!$customers) {
        return $html
            . '<p class="muted">
現在、同期済みの顧客情報はありません。
kintoneから顧客情報を同期してください。
</p>
</div>';
    }

    $html .= '<p class="muted">
同期済み顧客：'
        . h(count($customers))
        . '件
</p>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>組織名</th>
<th>氏名</th>
<th>メールアドレス</th>
<th>部署名</th>
<th>電話番号</th>
<th>住所</th>
</tr>
</thead>
<tbody>';

    foreach ($customers as $customer) {
        if (!is_array($customer)) {
            continue;
        }

        $html .= '<tr>
<td>'
            . h($customer['org'] ?? '')
            . '</td>
<td>'
            . h($customer['name'] ?? '')
            . '</td>
<td>'
            . h($customer['email'] ?? '')
            . '</td>
<td>'
            . h($customer['department'] ?? '')
            . '</td>
<td>'
            . h($customer['phone'] ?? '')
            . '</td>
<td>'
            . h($customer['address'] ?? '')
            . '</td>
</tr>';
    }

    $html .= '</tbody>
</table>
</div>
</div>';

    return $html;
}

function renderKintone(
    array $data
): string {
    $settings =
        $data['kintone'];

    $html =
        '<h1>kintone設定</h1>';

    $html .= '<div class="card">
<form method="post">
<input type="hidden"
 name="action"
 value="save_kintone">

<div class="grid2">
<div class="field">
<label>サブドメイン</label>
<input name="subdomain"
 value="'
        . h($settings['subdomain'])
        . '"
 placeholder="example または example.cybozu.com"
 required>
</div>

<div class="field">
<label>顧客管理アプリID</label>
<input name="appId"
 value="'
        . h($settings['appId'])
        . '"
 inputmode="numeric"
 required>
</div>

<div class="field">
<label>ログイン名</label>
<input name="username"
 value="'
        . h($settings['username'])
        . '"
 required>
</div>

<div class="field">
<label>Proxy</label>
<input name="proxy"
 value="'
        . h($settings['proxy'])
        . '"
 placeholder="host:port">
</div>
</div>

<div class="field">
<label>SSL証明書検証</label>
<select name="sslVerify">
<option value="1" '
        . ($settings['sslVerify']
            ? 'selected'
            : '')
        . '>有効</option>
<option value="0" '
        . (!$settings['sslVerify']
            ? 'selected'
            : '')
        . '>無効（POC）</option>
</select>
</div>

<p class="muted">
kintoneパスワードは設定保存しません。
接続テスト・項目取得・顧客同期時にその都度入力してください。
</p>

<button class="primary">
設定保存
</button>
</form>
</div>';

    $html .= '<div class="card">
<h2>接続テスト</h2>
<form method="post">
<input type="hidden"
 name="action"
 value="test_kintone">
<div class="field">
<label>kintoneパスワード</label>
<input type="password"
 name="password"
 autocomplete="new-password"
 required>
</div>
<button class="primary">
接続テスト
</button>
</form>
</div>';

    $html .= '<div class="card">
<h2>項目一覧再取得</h2>
<form method="post">
<input type="hidden"
 name="action"
 value="fetch_kintone_fields">
<div class="field">
<label>kintoneパスワード</label>
<input type="password"
 name="password"
 autocomplete="new-password"
 required>
</div>
<button>
項目一覧再取得
</button>
</form>';

    if (!empty($settings['fields'])) {
        $html .= '<hr>
<h3>取得済み項目</h3>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>フィールドコード</th>
<th>ラベル</th>
<th>形式</th>
</tr>
</thead>
<tbody>';

        foreach (
            $settings['fields']
            as $code => $field
        ) {
            $html .= '<tr>
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

        $html .= '</tbody>
</table>
</div>';
    }

    $html .= '</div>';

    /*
     * フィールドマッピング。
     */
    $fields =
        $settings['fields'] ?? [];

    $mapping =
        $settings['mappings'] ?? [];

    $html .= '<div class="card">
<h2>顧客情報マッピング</h2>
<form method="post">
<input type="hidden"
 name="action"
 value="save_kintone_mapping">';

    $mappingItems = [
        'org' => '組織名',
        'name' => '氏名',
        'email' => 'メールアドレス',
        'department' => '部署名',
        'phone' => '電話番号',
    ];

    foreach ($mappingItems as $key => $label) {
        $html .= '<div class="field">
<label>'
            . h($label)
            . '</label>
<select name="mapping['
            . h($key)
            . ']">
<option value="">未指定</option>';

        foreach ($fields as $code => $field) {
            $selected =
                (
                    (string)(
                        $mapping[$key] ?? ''
                    )
                    === (string)$code
                )
                    ? ' selected'
                    : '';

            $html .= '<option value="'
                . h($code)
                . '"'
                . $selected
                . '>'
                . h($field['label'] ?? $code)
                . ' ('
                . h($code)
                . ')</option>';
        }

        $html .= '</select>
</div>';
    }

    $html .= '<div class="field">
<label>住所</label>';

    $addressMapping =
        (array)(
            $mapping['address']
            ?? []
        );

    for ($i = 0; $i < 3; $i++) {
        $html .= '<select name="mapping[address][]">
<option value="">未指定</option>';

        foreach ($fields as $code => $field) {
            $selected =
                (
                    (string)(
                        $addressMapping[$i]
                        ?? ''
                    )
                    === (string)$code
                )
                    ? ' selected'
                    : '';

            $html .= '<option value="'
                . h($code)
                . '"'
                . $selected
                . '>'
                . h($field['label'] ?? $code)
                . ' ('
                . h($code)
                . ')</option>';
        }

        $html .= '</select><br><br>';
    }

    $html .= '</div>
<button>
マッピング保存
</button>
</form>
</div>';

    $html .= '<div class="card">
<h2>顧客情報同期</h2>
<p class="muted">
kintoneのrecords APIから顧客情報を取得し、
サーバー側へ同期保存します。
同期後の顧客は顧客選択・メール送信でも利用できます。
</p>

<form method="post">
<input type="hidden"
 name="action"
 value="sync_kintone">

<div class="field">
<label>kintoneパスワード</label>
<input type="password"
 name="password"
 autocomplete="new-password"
 required>
</div>

<button class="primary">
顧客情報同期
</button>
</form>
</div>';

    /*
     * ★今回の不具合に対する重要部分
     *
     * 同期処理が保存した$data['customers']を、
     * GET画面で実際に描画する。
     */
    $html .=
        renderKintoneCustomerList(
            $data
        );

    $html .= '<div class="card">
<h2>接続状態</h2>
<p><strong>'
        . h($settings['connection'])
        . '</strong></p>';

    if (
        ($settings['connectionDetail'] ?? '')
        !== ''
    ) {
        $html .= '<p class="muted">'
            . h(
                $settings['connectionDetail']
            )
            . '</p>';
    }

    $html .= '</div>';

    return $html;
}

function saveKintoneMappingAction(
    array &$data
): void {
    $mapping =
        postArray('mapping');

    $allowed = [
        'org',
        'name',
        'email',
        'department',
        'phone',
    ];

    foreach ($allowed as $key) {
        $value =
            $mapping[$key]
            ?? '';

        if (!is_scalar($value)) {
            $value = '';
        }

        $data['kintone']['mappings'][$key] =
            trim((string)$value);
    }

    $address = [];

    foreach (
        ($mapping['address'] ?? [])
        as $value
    ) {
        if (!is_scalar($value)) {
            continue;
        }

        $value =
            trim((string)$value);

        if ($value !== '') {
            $address[] = $value;
        }
    }

    $data['kintone']['mappings']['address'] =
        array_values($address);

    saveData($data);

    flash(
        'success',
        'kintone顧客情報マッピングを保存しました。'
    );

    redirectTo('kintone');
}

/* =========================================================
 * 回答画面
 * ========================================================= */

function renderAnswer(
    array $survey
): string {
    $draft =
        $_SESSION['answer_draft']
        ?? [];

    if (!is_array($draft)) {
        $draft = [];
    }

    $errors =
        $_SESSION['answer_errors']
        ?? [];

    unset(
        $_SESSION['answer_errors']
    );

    $visible =
        visibleQuestionIds(
            $survey,
            $draft
        );

    $map =
        questionMap($survey);

    $html =
        '<h1>'
        . h($survey['title'])
        . '</h1>';

    if ($errors) {
        $html .= '<div class="alert error">'
            . h(
                implode(
                    "\n",
                    $errors
                )
            )
            . '</div>';
    }

    $html .= '<form method="post">
<input type="hidden"
 name="action"
 value="answer_draft">
<input type="hidden"
 name="surveyId"
 value="'
        . h($survey['id'])
        . '">';

    foreach ($visible as $id) {
        if (!isset($map[$id])) {
            continue;
        }

        $question =
            $map[$id];

        $html .= '<div class="card">
<div class="question">
<strong>'
            . h($question['number'])
            . '</strong>
<p>'
            . h($question['text'])
            . '</p>';

        if ($question['type'] === 'free') {
            $html .= '<textarea
name="answers['
                . h($question['id'])
                . ']">'
                . h(
                    is_scalar(
                        $draft[$question['id']]
                        ?? ''
                    )
                        ? $draft[$question['id']]
                        : ''
                )
                . '</textarea>';
        } elseif (
            $question['type'] === 'single'
        ) {
            foreach (
                $question['options']
                as $option
            ) {
                $checked =
                    (
                        (string)(
                            $draft[
                                $question['id']
                            ]
                            ?? ''
                        )
                        === (string)$option
                    )
                        ? ' checked'
                        : '';

                $html .= '<div class="option">
<label>
<input type="radio"
 name="answers['
                    . h($question['id'])
                    . ']"
 value="'
                    . h($option)
                    . '"'
                    . $checked
                    . '>
'
                    . h($option)
                    . '
</label>
</div>';
            }
        } else {
            $selected =
                is_array(
                    $draft[
                        $question['id']
                    ] ?? null
                )
                    ? $draft[
                        $question['id']
                    ]
                    : [];

            foreach (
                $question['options']
                as $option
            ) {
                $checked =
                    in_array(
                        $option,
                        $selected,
                        true
                    )
                        ? ' checked'
                        : '';

                $html .= '<div class="option">
<label>
<input type="checkbox"
 name="answers['
                    . h($question['id'])
                    . '][]"
 value="'
                    . h($option)
                    . '"'
                    . $checked
                    . '>
'
                    . h($option)
                    . '
</label>
</div>';
            }
        }

        $html .= '</div>
</div>';
    }

    $html .= '<div class="actions">
<button class="primary">
回答確認へ
</button>
</div>
</form>';

    return $html;
}

function renderConfirm(
    array $survey
): string {
    $answers =
        $_SESSION['answer_draft']
        ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $map =
        questionMap($survey);

    $html =
        '<h1>回答確認</h1>
<div class="card">';

    foreach ($map as $id => $question) {
        if (!array_key_exists($id, $answers)) {
            continue;
        }

        $value =
            $answers[$id];

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

        $html .= '<div class="question">
<strong>'
            . h($question['number'])
            . '</strong>
<p>'
            . h($question['text'])
            . '</p>
<p>'
            . nl2br(
                h($value)
            )
            . '</p>
</div>';
    }

    $html .= '</div>

<div class="actions">
<a class="btn"
 href="?screen=answer&id='
        . rawurlencode($survey['id'])
        . '">
回答を修正
</a>

<form method="post">
<input type="hidden"
 name="action"
 value="answer_submit">
<input type="hidden"
 name="surveyId"
 value="'
        . h($survey['id'])
        . '">
<button class="primary">
回答送信
</button>
</form>
</div>';

    return $html;
}

function renderComplete(): string
{
    return '<h1>回答完了</h1>
<div class="card">
<p>アンケートへの回答を受け付けました。</p>
<p class="muted">
ご回答ありがとうございました。
</p>
</div>';
}

/* =========================================================
 * 送信
 * ========================================================= */

function renderSend(
    array $data,
    array $survey
): string {
    $result =
        $_SESSION['send_result']
        ?? null;

    unset(
        $_SESSION['send_result']
    );

    $html =
        '<h1>顧客選択・メール送信</h1>';

    if (is_array($result)) {
        $html .= '<div class="alert '
            . (
                ((int)(
                    $result['failed']
                    ?? 0
                ) > 0)
                    ? 'error'
                    : 'success'
            )
            . '">
送信成功: '
            . h($result['success'] ?? 0)
            . '件
送信失敗: '
            . h($result['failed'] ?? 0)
            . '件
</div>';
    }

    $html .= '<div class="card">
<h2>'
        . h($survey['title'])
        . '</h2>

<form method="post">
<input type="hidden"
 name="action"
 value="send_mail">
<input type="hidden"
 name="surveyId"
 value="'
        . h($survey['id'])
        . '">

<div class="field">
<label>顧客検索</label>
<input id="customerSearch"
 type="search"
 placeholder="組織名・氏名・メールアドレス等">
</div>

<div class="table-wrap">
<table id="customerTable">
<thead>
<tr>
<th>選択</th>
<th>組織名</th>
<th>氏名</th>
<th>メールアドレス</th>
<th>部署</th>
<th>電話番号</th>
</tr>
</thead>
<tbody>';

    foreach (
        $data['customers']
        as $customer
    ) {
        if (!is_array($customer)) {
            continue;
        }

        $email =
            (string)(
                $customer['email']
                ?? ''
            );

        if (!validEmail($email)) {
            continue;
        }

        $search =
            implode(
                ' ',
                [
                    $customer['org'] ?? '',
                    $customer['name'] ?? '',
                    $email,
                    $customer['department'] ?? '',
                    $customer['phone'] ?? '',
                    $customer['address'] ?? '',
                ]
            );

        $html .= '<tr data-search="'
            . h(
                strtolower(
                    $search
                )
            )
            . '">
<td>
<input type="checkbox"
 name="customerIds[]"
 value="'
            . h($customer['id'])
            . '">
</td>
<td>'
            . h($customer['org'] ?? '')
            . '</td>
<td>'
            . h($customer['name'] ?? '')
            . '</td>
<td>'
            . h($email)
            . '</td>
<td>'
            . h($customer['department'] ?? '')
            . '</td>
<td>'
            . h($customer['phone'] ?? '')
            . '</td>
</tr>';
    }

    $html .= '</tbody>
</table>
</div>

<div class="field">
<label>メール件名</label>
<input name="subject"
 value="'
        . h(
            '【アンケート】'
            . $survey['title']
        )
        . '"
 required>
</div>

<div class="field">
<label>メール本文</label>
<textarea name="body"
 required>'
        . h(
            '{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}'
        )
        . '</textarea>
</div>

<div class="field">
<label>SMTPパスワード</label>
<input type="password"
 name="password"
 autocomplete="new-password"
 required>
</div>

<button class="primary">
一括送信
</button>
</form>
</div>';

    $html .= '<div class="card">
<h2>送信履歴</h2>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>メール</th>
<th>状態</th>
</tr>
</thead>
<tbody>';

    $history =
        array_reverse(
            $data['sendHistory']
            ?? []
        );

    foreach (
        $history
        as $item
    ) {
        if (
            ($item['surveyId'] ?? '')
            !== $survey['id']
        ) {
            continue;
        }

        $html .= '<tr>
<td>'
            . h($item['date'] ?? '')
            . '</td>
<td>'
            . h($item['email'] ?? '')
            . '</td>
<td>'
            . h(
                ($item['status'] ?? '')
                === 'success'
                    ? '送信成功'
                    : '送信失敗'
            )
            . '</td>
</tr>';
    }

    $html .= '</tbody>
</table>
</div>
</div>

<script>
const search =
 document.getElementById("customerSearch");

if (search) {
 search.addEventListener("input", function(){
   const q =
     this.value.toLowerCase().trim();

   document
     .querySelectorAll("#customerTable tbody tr")
     .forEach(function(row){
       const text =
         row.dataset.search || "";

       row.style.display =
         !q || text.includes(q)
           ? ""
           : "none";
     });
 });
}
</script>';

    return $html;
}

/* =========================================================
 * 集計
 * ========================================================= */

function renderAnalytics(
    array $data,
    array $survey
): string {
    $answers =
        $data['answers'][
            $survey['id']
        ]
        ?? [];

    $html =
        '<h1>回答集計・分析</h1>

<div class="card">
<h2>'
        . h($survey['title'])
        . '</h2>
<p>回答数: '
        . h(count($answers))
        . '</p>';

    if (!$answers) {
        return $html
            . '<p class="muted">
現在、回答データはありません
</p>
</div>';
    }

    $targetCount = 0;

    foreach (
        $data['sendHistory']
        as $history
    ) {
        if (
            ($history['surveyId'] ?? '')
            === $survey['id']
            && ($history['status'] ?? '')
            === 'success'
        ) {
            $targetCount++;
        }
    }

    $answerCount =
        count($answers);

    $rate =
        $targetCount > 0
            ? round(
                $answerCount
                / $targetCount
                * 100,
                1
            )
            : 0;

    $html .= '<p>
送信成功対象者数: '
        . h($targetCount)
        . '</p>
<p>
回答率: '
        . h($rate)
        . '%
</p>
</div>';

    foreach (
        allQuestions($survey)
        as $question
    ) {
        if (!in_array(
            $question['type'],
            ['single', 'multiple'],
            true
        )) {
            continue;
        }

        $counts = [];

        foreach (
            $question['options']
            as $option
        ) {
            $counts[$option] = 0;
        }

        foreach (
            $answers
            as $answer
        ) {
            $value =
                $answer['values'][
                    $question['id']
                ]
                ?? null;

            if (is_array($value)) {
                foreach ($value as $item) {
                    if (isset($counts[$item])) {
                        $counts[$item]++;
                    }
                }
            } elseif (
                isset($counts[(string)$value])
            ) {
                $counts[(string)$value]++;
            }
        }

        $html .= '<div class="card">
<h3>'
            . h($question['number'])
            . ' '
            . h($question['text'])
            . '</h3>
<table>
<thead>
<tr>
<th>選択肢</th>
<th>回答数</th>
</tr>
</thead>
<tbody>';

        foreach (
            $counts
            as $option => $count
        ) {
            $html .= '<tr>
<td>'
                . h($option)
                . '</td>
<td>'
                . h($count)
                . '</td>
</tr>';
        }

        $html .= '</tbody>
</table>
</div>';
    }

    $html .= '<div class="card">
<h2>個別回答</h2>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>回答者</th>
<th>組織</th>
</tr>
</thead>
<tbody>';

    foreach (
        $answers
        as $answer
    ) {
        $html .= '<tr>
<td>'
            . h($answer['date'] ?? '')
            . '</td>
<td>'
            . h($answer['customer'] ?? '')
            . '</td>
<td>'
            . h($answer['org'] ?? '')
            . '</td>
</tr>';
    }

    $html .= '</tbody>
</table>
</div>
</div>';

    return $html;
}

/* =========================================================
 * メール設定画面
 * ========================================================= */

function renderMail(
    array $data
): string {
    $s =
        $data['mailSettings'];

    return '<h1>メールサーバ設定</h1>

<div class="card">
<form method="post">
<input type="hidden"
 name="action"
 value="save_mail">

<div class="grid2">

<div class="field">
<label>SMTPサーバ</label>
<input name="server"
 value="'
        . h($s['server'])
        . '"
 required>
</div>

<div class="field">
<label>SMTPポート</label>
<input type="number"
 name="port"
 value="'
        . h($s['port'])
        . '"
 min="1"
 max="65535"
 required>
</div>

<div class="field">
<label>暗号化方式</label>
<select name="encryption">
<option value="tls" '
        . ($s['encryption'] === 'tls'
            ? 'selected'
            : '')
        . '>TLS</option>
<option value="ssl" '
        . ($s['encryption'] === 'ssl'
            ? 'selected'
            : '')
        . '>SSL</option>
<option value="none" '
        . ($s['encryption'] === 'none'
            ? 'selected'
            : '')
        . '>なし</option>
</select>
</div>

<div class="field">
<label>SMTP認証</label>
<select name="auth">
<option value="1" '
        . ($s['auth']
            ? 'selected'
            : '')
        . '>使用する</option>
<option value="0" '
        . (!$s['auth']
            ? 'selected'
            : '')
        . '>使用しない</option>
</select>
</div>

<div class="field">
<label>SMTPユーザー名</label>
<input name="username"
 value="'
        . h($s['username'])
        . '">
</div>

<div class="field">
<label>送信元メールアドレス</label>
<input type="email"
 name="fromEmail"
 value="'
        . h($s['fromEmail'])
        . '"
 required>
</div>

<div class="field">
<label>送信元名</label>
<input name="fromName"
 value="'
        . h($s['fromName'])
        . '">
</div>

<div class="field">
<label>返信先メールアドレス</label>
<input type="email"
 name="replyTo"
 value="'
        . h($s['replyTo'])
        . '">
</div>

</div>

<p class="muted">
SMTPパスワードは設定保存しません。
</p>

<button class="primary">
設定保存
</button>
</form>
</div>

<div class="card">
<h2>SMTP接続テスト</h2>
<form method="post">
<input type="hidden"
 name="action"
 value="test_mail">

<div class="field">
<label>SMTPパスワード</label>
<input type="password"
 name="password"
 autocomplete="new-password"
 required>
</div>

<button class="primary">
接続テスト
</button>
</form>

<p class="muted">
接続テストではSMTP接続および認証まで確認します。
</p>
</div>

<div class="card">
<h2>接続状態</h2>
<p><strong>'
        . h($s['connection'])
        . '</strong></p>
<p class="muted">'
        . h($s['connectionDetail'])
        . '</p>
</div>';
}

/* =========================================================
 * エラー
 * ========================================================= */

function safeErrorMessage(
    Throwable $e
): string {
    $message =
        trim($e->getMessage());

    if ($message === '') {
        return '処理中にエラーが発生しました。';
    }

    $unsafe = [
        '/password\s*[=:]/i',
        '/authorization\s*[=:]/i',
        '/x-cybozu-authorization/i',
        '/cookie\s*[=:]/i',
        '/session\s*[=:]/i',
        '/secret\s*[=:]/i',
    ];

    foreach ($unsafe as $pattern) {
        if (preg_match($pattern, $message)) {
            return
                '外部サービスとの通信または設定処理に失敗しました。'
                . '設定内容を確認してください。';
        }
    }

    return $message;
}

/* =========================================================
 * Main
 * ========================================================= */

$data = null;

try {
    $data = readData();

    updateAutomaticStatus($data);

    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        === 'POST'
    ) {
        processPost($data);

        /*
         * processPost()は成功時に必ず303する。
         * ここへ到達した場合は結果不明。
         */
        throw new RuntimeException(
            '処理結果を確定できませんでした。'
        );
    }

    $screen =
        getString(
            'screen',
            'list'
        );

    $allowedScreens = [
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

    if (
        !in_array(
            $screen,
            $allowedScreens,
            true
        )
    ) {
        throw new InvalidArgumentException(
            '指定された画面は存在しません。'
        );
    }

    /*
     * kintoneマッピング保存専用POST。
     * screen遷移ではなくPOST actionとして扱う。
     */
    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        === 'POST'
        && postString('action')
        === 'save_kintone_mapping'
    ) {
        saveKintoneMappingAction($data);
    }

    $title = 'アンケート一覧';
    $content = '';

    switch ($screen) {
        case 'list':
            $title =
                'アンケート一覧';

            $content =
                renderList($data);
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

            if (
                $id !== ''
                && $survey === null
            ) {
                throw new RuntimeException(
                    '対象アンケートが存在しません。'
                );
            }

            $title =
                'アンケート編集';

            $content =
                renderEdit(
                    $data,
                    $survey
                );
            break;

        case 'preview':
            $id =
                getString('id');

            if (!validateId($id)) {
                throw new InvalidArgumentException(
                    'プレビュー対象アンケートIDが必要です。'
                );
            }

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if (!$survey) {
                throw new RuntimeException(
                    '対象アンケートが存在しません。'
                );
            }

            $title =
                'プレビュー';

            $content =
                renderPreview(
                    $survey
                );
            break;

        case 'send':
            $id =
                getString('id');

            if (!validateId($id)) {
                throw new InvalidArgumentException(
                    '送信対象アンケートIDが必要です。'
                );
            }

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if (!$survey) {
                throw new RuntimeException(
                    '対象アンケートが存在しません。'
                );
            }

            $title =
                '顧客選択・メール送信';

            $content =
                renderSend(
                    $data,
                    $survey
                );
            break;

        case 'analytics':
            $id =
                getString('id');

            if (!validateId($id)) {
                throw new InvalidArgumentException(
                    '集計対象アンケートIDが必要です。'
                );
            }

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if (!$survey) {
                throw new RuntimeException(
                    '対象アンケートが存在しません。'
                );
            }

            $title =
                '回答集計・分析';

            $content =
                renderAnalytics(
                    $data,
                    $survey
                );
            break;

        case 'kintone':
            $title =
                'kintone設定';

            $content =
                renderKintone(
                    $data
                );
            break;

        case 'mail':
            $title =
                'メール設定';

            $content =
                renderMail(
                    $data
                );
            break;

        case 'answer':
            $id =
                getString('id');

            if (!validateId($id)) {
                throw new InvalidArgumentException(
                    '回答対象アンケートIDが必要です。'
                );
            }

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if (
                !$survey
                || !surveyAvailable($survey)
            ) {
                throw new RuntimeException(
                    '回答可能なアンケートではありません。'
                );
            }

            echo renderHeader(
                $survey['title'],
                false
            );

            echo renderAnswer(
                $survey
            );

            echo renderFooter();

            exit;

        case 'confirm':
            $id =
                getString('id');

            if (!validateId($id)) {
                throw new InvalidArgumentException(
                    '回答対象アンケートIDが必要です。'
                );
            }

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if (
                !$survey
                || !surveyAvailable($survey)
            ) {
                throw new RuntimeException(
                    '回答可能なアンケートではありません。'
                );
            }

            $title =
                '回答確認';

            $content =
                renderConfirm(
                    $survey
                );
            break;

        case 'complete':
            $id =
                getString('id');

            if (!validateId($id)) {
                throw new InvalidArgumentException(
                    '回答対象アンケートIDが必要です。'
                );
            }

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if (!$survey) {
                throw new RuntimeException(
                    '対象アンケートが存在しません。'
                );
            }

            echo renderHeader(
                $survey['title'],
                false
            );

            echo renderComplete();

            echo renderFooter();

            exit;
    }

    $flash =
        takeFlash();

    echo renderHeader(
        $title,
        true
    );

    if ($flash) {
        echo '<div class="alert '
            . h($flash['type'])
            . '">'
            . h($flash['message'])
            . '</div>';
    }

    echo $content;

    echo renderFooter();

} catch (Throwable $e) {
    $message =
        safeErrorMessage($e);

    http_response_code(500);

    echo renderHeader(
        'エラー',
        true
    );

    echo '<div class="alert error">'
        . h($message)
        . '</div>

<div class="actions">
<a class="btn"
 href="?screen=list">
アンケート一覧へ戻る
</a>

<a class="btn"
 href="?screen=kintone">
kintone設定
</a>
</div>';

    echo renderFooter();
}
?>

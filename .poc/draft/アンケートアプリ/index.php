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
 * 仕様:
 * - 管理者認証なし
 * - CSRF対策なし
 * - kintoneパスワードは設定保存しない
 * - SMTPパスワードも設定保存しない
 * - 外部302/303は成功扱いしない
 * - 外部通信処理からリダイレクトしない
 * - アプリ自身の303はPRG専用
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

$cookiePath = $scriptDir === '.' || $scriptDir === ''
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
        throw new RuntimeException(
            'データをJSON化できません。'
        );
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

function getString(
    string $name,
    string $default = ''
): string {
    if (!isset($_GET[$name]) || !is_scalar($_GET[$name])) {
        return $default;
    }

    return trim((string)$_GET[$name]);
}

function postString(
    string $name,
    string $default = ''
): string {
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

function flash(
    string $type,
    string $message
): void {
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

    header(
        'Location: ' . $url,
        true,
        303
    );

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
    if (is_dir(DATA_DIR)) {
        if (!is_writable(DATA_DIR)) {
            throw new RuntimeException(
                'データ保存ディレクトリに書き込み権限がありません。'
            );
        }
    } else {
        if (!mkdir(DATA_DIR, 0770, true)
            && !is_dir(DATA_DIR)) {
            throw new RuntimeException(
                'データ保存ディレクトリを作成できません。'
            );
        }

        if (!is_writable(DATA_DIR)) {
            throw new RuntimeException(
                'データ保存ディレクトリに書き込み権限がありません。'
            );
        }
    }

    /*
     * Apache設定に依存せず、データファイルを直接取得されにくくする。
     */
    $htaccess = DATA_DIR . DIRECTORY_SEPARATOR . '.htaccess';

    if (!is_file($htaccess)) {
        $content =
            "Options -Indexes\n"
            . "<FilesMatch \"\\.(dat|json|tmp)(\\.php)?$\">\n"
            . "Require all denied\n"
            . "</FilesMatch>\n";

        @file_put_contents(
            $htaccess,
            $content,
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

    $data = array_replace_recursive(
        defaultData(),
        $data
    );

    /*
     * 外部サービスパスワードはこのPOCでは
     * 永続保存しない。
     */
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

    /*
     * 一時ファイルは必ずDATA_FILEと同じディレクトリに作る。
     * rename()の原子性を維持するためである。
     */
    $tmp = DATA_FILE
        . '.'
        . bin2hex(random_bytes(8))
        . '.tmp';

    $fp = @fopen($tmp, 'xb');

    if ($fp === false) {
        throw new RuntimeException(
            'データ保存用の一時ファイルを作成できません。'
            . 'dataディレクトリの書き込み権限を確認してください。'
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

            if ($written === false
                || $written === 0) {
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

        /*
         * 同一ファイルシステム内のrenameで
         * 書き込み途中のファイルが正式データになることを防ぐ。
         */
        if (!@rename($tmp, DATA_FILE)) {
            throw new RuntimeException(
                'データファイルを更新できません。'
                . 'dataディレクトリと既存ファイルの権限を確認してください。'
            );
        }

        /*
         * 実際に新ファイルが存在することまで確認する。
         */
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

function surveyAvailable(
    array $survey
): bool {
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
    if (!preg_match(
        '#^https://#i',
        $url
    )) {
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

    $contextOptions = [
        'http' => [
            'method' => strtoupper($method),
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'protocol_version' => 1.1,
            'header' => implode(
                "\r\n",
                $headers
            ),
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
        $contextOptions['http']['content'] =
            $body;
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
                '外部サービスへ接続できません。'
                . ($error !== null
                    ? ''
                    : ''),
        ];
    }

    stream_set_timeout(
        $fp,
        $timeout
    );

    $responseBody =
        stream_get_contents($fp);

    $meta = stream_get_meta_data($fp);

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

function kintoneRequest(
    array $settings,
    string $path,
    string $method,
    string $password
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
     * Authorizationはこの関数内だけで生成・使用する。
     * ブラウザ・ログ・画面には返さない。
     */
    $headers = [
        'X-Cybozu-Authorization: '
        . $authorization,
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: SurveyPOC/3.0',
    ];

    $response = httpRequest(
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

    unset($authorization);

    return $response;
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
 * kintone POST
 * ========================================================= */

function saveKintoneAction(
    array &$data
): void {
    /*
     * ここではkintoneへ接続しない。
     * 設定保存と接続テストを完全に分離する。
     *
     * パスワードも保存しない。
     */
    $rawSubdomain =
        postString('subdomain');

    $subdomain = $rawSubdomain !== ''
        ? normalizeKintoneHost(
            $rawSubdomain
        )
        : '';

    $appId = postString('appId');

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

    /*
     * 設定保存では接続状態を勝手に
     * 「接続確認済み」にしない。
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

    foreach ($json['records'] as $record) {
        if (!is_array($record)) {
            continue;
        }

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
                    $value = implode(
                        ' ',
                        array_map(
                            'strval',
                            $value
                        )
                    );
                }

                return trim(
                    (string)$value
                );
            };

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
            'address' => implode(
                ' ',
                array_filter([
                    $readField(
                        $record,
                        (string)(
                            $mapping['address'][0]
                            ?? ''
                        )
                    ),
                    $readField(
                        $record,
                        (string)(
                            $mapping['address'][1]
                            ?? ''
                        )
                    ),
                ])
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
 * Survey POST
 * ========================================================= */

function saveSurveyAction(
    array &$data
): void {
    $id = postString('id');

    if ($id !== ''
        && !validateId($id)) {
        throw new InvalidArgumentException(
            'アンケートIDが不正です。'
        );
    }

    $title =
        postString('title');

    if ($title === '') {
        throw new InvalidArgumentException(
            'タイトルを入力してください。'
        );
    }

    if (mb_strlen($title) > 200) {
        throw new InvalidArgumentException(
            'タイトルは200文字以内で入力してください。'
        );
    }

    $startAt =
        postString('startAt');

    $endAt =
        postString('endAt');

    if (!validDateTime($startAt)
        || !validDateTime($endAt)) {
        throw new InvalidArgumentException(
            '日時の形式が不正です。'
        );
    }

    if ($startAt !== ''
        && $endAt !== ''
        && $startAt >= $endAt) {
        throw new InvalidArgumentException(
            '終了日時は開始日時より後にしてください。'
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

    $existing =
        $id !== ''
            ? surveyById($data, $id)
            : null;

    $survey = normalizeSurvey([
        'id' =>
            $existing['id']
            ?? ($id !== ''
                ? $id
                : uid('survey')),

        'createdAt' =>
            $existing['createdAt']
            ?? today(),

        'updatedAt' => today(),

        'title' => $title,

        'description' =>
            postString('description'),

        'startAt' => $startAt,

        'endAt' => $endAt,

        'status' =>
            $existing['status']
            ?? 'draft',

        'numbering' => $numbering,

        'groups' =>
            postArray('groups'),
    ]);

    if ($existing !== null) {
        $index =
            surveyIndex(
                $data,
                $survey['id']
            );

        if ($index < 0) {
            throw new RuntimeException(
                'アンケートが存在しません。'
            );
        }

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

function transitionAction(
    array &$data
): void {
    $id =
        postString('id');

    $to =
        postString('to');

    $index =
        surveyIndex(
            $data,
            $id
        );

    if ($index < 0) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $from =
        (string)(
            $data['surveys'][$index]['status']
            ?? ''
        );

    if (!canTransition(
        $from,
        $to
    )) {
        throw new InvalidArgumentException(
            '指定された状態遷移は許可されていません。'
        );
    }

    $data['surveys'][$index]['status'] =
        $to;

    $data['surveys'][$index]['updatedAt'] =
        today();

    saveData($data);

    flash(
        'success',
        '状態を変更しました。'
    );

    redirectTo('list');
}

function duplicateAction(
    array &$data
): void {
    $id =
        postString('id');

    $survey =
        surveyById(
            $data,
            $id
        );

    if (!$survey) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $survey['id'] =
        uid('survey');

    $survey['title'] .=
        '（コピー）';

    $survey['createdAt'] =
        today();

    $survey['updatedAt'] =
        today();

    $survey['status'] =
        'draft';

    foreach (
        $survey['groups']
        as &$group
    ) {
        $group['id'] =
            uid('g');

        foreach (
            $group['questions']
            as &$question
        ) {
            $question['id'] =
                uid('q');

            $question['branches'] =
                [];
        }
    }

    unset(
        $group,
        $question
    );

    renumberSurvey($survey);

    $data['surveys'][] =
        $survey;

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
    $id =
        postString('id');

    $index =
        surveyIndex(
            $data,
            $id
        );

    if ($index < 0) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    array_splice(
        $data['surveys'],
        $index,
        1
    );

    unset(
        $data['answers'][$id]
    );

    saveData($data);

    flash(
        'success',
        'アンケートを削除しました。'
    );

    redirectTo('list');
}

/* =========================================================
 * 回答
 * ========================================================= */

function answerConfirmAction(
    array &$data
): void {
    $surveyId =
        postString('surveyId');

    $survey =
        surveyById(
            $data,
            $surveyId
        );

    if (!$survey
        || !surveyAvailable($survey)) {
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

    if (!$survey
        || !surveyAvailable($survey)) {
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
        if (($candidate['id'] ?? '')
            === $customerId) {
            $customer = $candidate;
            break;
        }
    }

    $data['answers'][$surveyId] ??=
        [];

    $data['answers'][$surveyId][] = [
        'id' => uid('answer'),
        'customerId' => $customerId,
        'customer' =>
            $customer['name']
            ?? '未登録回答者',
        'org' =>
            $customer['org']
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
 * POST router
 * ========================================================= */

function processPost(
    array &$data
): void {
    $action =
        postString('action');

    switch ($action) {
        case 'save_survey':
            saveSurveyAction($data);
            return;

        case 'transition':
            transitionAction($data);
            return;

        case 'duplicate':
            duplicateAction($data);
            return;

        case 'delete_survey':
            deleteSurveyAction($data);
            return;

        case 'answer_confirm':
            answerConfirmAction($data);
            return;

        case 'answer_submit':
            answerSubmitAction($data);
            return;

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

        default:
            throw new InvalidArgumentException(
                '指定された操作は利用できません。'
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
<meta charset="utf-8">
<meta name="viewport"
 content="width=device-width,initial-scale=1">
<title>' . h($title) . '</title>
<style>
*{box-sizing:border-box}
body{
 margin:0;
 background:#f5f7fa;
 color:#263238;
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",sans-serif;
 line-height:1.6
}
header{
 background:#263238;
 color:#fff;
 padding:14px 20px
}
header .inner{
 max-width:1200px;
 margin:auto;
 display:flex;
 justify-content:space-between;
 align-items:center;
 gap:20px
}
header a{
 color:#fff;
 text-decoration:none
}
main{
 max-width:1200px;
 margin:28px auto;
 padding:0 16px
}
.nav{
 display:flex;
 flex-wrap:wrap;
 gap:16px
}
.card{
 background:#fff;
 border:1px solid #dce2e8;
 border-radius:10px;
 padding:20px;
 margin-bottom:18px;
 box-shadow:0 2px 8px rgba(0,0,0,.04)
}
.grid2{
 display:grid;
 grid-template-columns:repeat(2,minmax(0,1fr));
 gap:16px
}
.field{margin-bottom:16px}
label{
 display:block;
 font-weight:600;
 margin-bottom:5px
}
input,textarea,select{
 width:100%;
 border:1px solid #c7d0d9;
 border-radius:6px;
 padding:9px 10px;
 font:inherit;
 background:#fff
}
textarea{min-height:110px}
button,.btn{
 display:inline-block;
 border:1px solid #c7d0d9;
 border-radius:6px;
 padding:9px 15px;
 background:#fff;
 color:#263238;
 cursor:pointer;
 text-decoration:none
}
button.primary,.btn.primary{
 background:#1976d2;
 color:#fff;
 border-color:#1976d2
}
button.danger{
 color:#c62828
}
.actions{
 display:flex;
 flex-wrap:wrap;
 gap:8px;
 margin-top:16px
}
.alert{
 border-radius:7px;
 padding:12px 15px;
 margin-bottom:18px;
 white-space:pre-line
}
.alert.success{
 background:#e8f5e9;
 color:#1b5e20
}
.alert.error{
 background:#ffebee;
 color:#b71c1c
}
.muted{color:#6b7785}
.table-wrap{overflow-x:auto}
table{
 width:100%;
 border-collapse:collapse
}
th,td{
 padding:9px;
 border-bottom:1px solid #e5e9ed;
 text-align:left;
 vertical-align:top
}
.status{
 display:inline-block;
 padding:3px 9px;
 border-radius:999px;
 font-size:.85em
}
.status.draft{background:#eceff1}
.status.published{background:#e3f2fd;color:#1565c0}
.status.stopped{background:#fff3e0;color:#e65100}
.status.ended{background:#ffebee;color:#b71c1c}
.question{
 border:1px solid #dce2e8;
 border-radius:8px;
 padding:16px;
 margin-bottom:12px
}
@media(max-width:700px){
 .grid2{grid-template-columns:1fr}
 header .inner{align-items:flex-start;flex-direction:column}
 main{margin-top:16px}
}
</style>
</head>
<body>
<header>
<div class="inner">
<strong><a href="?screen=list">アンケートアプリ</a></strong>
' . $nav . '
</div>
</header>
<main>';
}

function renderFooter(): string
{
    return '</main>
</body>
</html>';
}

function renderList(
    array $data
): string {
    $surveys =
        $data['surveys'];

    usort(
        $surveys,
        static function (
            array $a,
            array $b
        ): int {
            return strcmp(
                (string)($b['updatedAt'] ?? ''),
                (string)($a['updatedAt'] ?? '')
            );
        }
    );

    $html =
        '<h1>アンケート一覧</h1>';

    $html .= '
<div class="actions">
<a class="btn primary"
 href="?screen=edit">新規作成</a>
</div>';

    $html .= '<div class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
<th>タイトル</th>
<th>更新日</th>
<th>期間</th>
<th>状態</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>
<tbody>';

    foreach ($surveys as $survey) {
        $id =
            (string)$survey['id'];

        $count =
            count(
                $data['answers'][$id]
                ?? []
            );

        $status =
            (string)(
                $survey['status']
                ?? 'draft'
            );

        $html .= '<tr>
<td>' . h(
            $survey['title']
        ) . '</td>
<td>' . h(
            $survey['updatedAt']
            ?? ''
        ) . '</td>
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
<td>' . h($count) . '</td>
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
            . '">集計</a>
<form method="post" style="display:inline">
<input type="hidden"
 name="action" value="duplicate">
<input type="hidden"
 name="id" value="'
            . h($id)
            . '">
<button>複製</button>
</form>
<form method="post" style="display:inline"
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

    if (!$surveys) {
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
    $new =
        $survey === null;

    if ($new) {
        $survey = normalizeSurvey([
            'id' => uid('survey'),
            'createdAt' => today(),
            'updatedAt' => today(),
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [
                [
                    'id' => uid('g'),
                    'title' => '',
                    'questions' => [],
                ],
            ],
        ]);
    }

    $html =
        '<h1>'
        . ($new
            ? 'アンケート作成'
            : 'アンケート編集')
        . '</h1>';

    $html .= '<form method="post">
<input type="hidden"
 name="action"
 value="save_survey">
<input type="hidden"
 name="id"
 value="' . h($survey['id']) . '">';

    $html .= '<div class="card">
<div class="field">
<label>タイトル</label>
<input name="title"
 value="' . h($survey['title']) . '"
 required>
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
        . '>全体通番</option>
<option value="group" '
        . ($survey['numbering'] === 'group'
            ? 'selected'
            : '')
        . '>グループ単位</option>
</select>
</div>
</div>';

    foreach (
        $survey['groups']
        as $gi => $group
    ) {
        $html .= '<div class="card">
<h2>グループ '
            . ($gi + 1)
            . '</h2>

<div class="field">
<label>グループタイトル</label>
<input name="groups['
            . $gi
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
<strong>'
                . h($question['number'])
                . '</strong>

<div class="field">
<label>質問文</label>
<input name="groups['
                . $gi
                . '][questions]['
                . $qi
                . '][text]"
 value="'
                . h($question['text'])
                . '">
</div>

<div class="grid2">
<div class="field">
<label>回答形式</label>
<select name="groups['
                . $gi
                . '][questions]['
                . $qi
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
                . $gi
                . '][questions]['
                . $qi
                . '][required]">
<option value="0">任意</option>
<option value="1" '
                . ($question['required']
                    ? 'selected'
                    : '')
                . '>必須</option>
</select>
</div>
</div>

<div class="field">
<label>選択肢</label>
<textarea
 name="groups['
                . $gi
                . '][questions]['
                . $qi
                . '][options_text]">'
                . h(
                    implode(
                        "\n",
                        $question['options']
                    )
                )
                . '</textarea>
</div>

<input type="hidden"
 name="groups['
                . $gi
                . '][questions]['
                . $qi
                . '][id]"
 value="'
                . h($question['id'])
                . '">
</div>';
        }

        $html .= '<div class="actions">
<button type="button"
 onclick="addQuestion('
            . $gi
            . ')">
質問を追加
</button>
</div>
</div>';
    }

    $html .= '<div class="actions">
<button class="primary">
保存して一覧へ
</button>
<a class="btn"
 href="?screen=list">キャンセル</a>
</div>
</form>';

    $html .= '
<script>
function addQuestion(groupIndex){
    alert("質問追加は次回編集時に反映してください。");
}
</script>';

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
<div><strong>'
                . h($question['number'])
                . '</strong></div>
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
                    $html .= '<label>
<input type="'
                        . ($question['type'] === 'single'
                            ? 'radio'
                            : 'checkbox')
                        . '" disabled>
'
                        . h($option)
                        . '</label><br>';
                }
            }

            $html .= '</div>';
        }
    }

    $html .= '</div>';

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
 placeholder="example または example.cybozu.com">
</div>

<div class="field">
<label>顧客管理アプリID</label>
<input name="appId"
 value="'
        . h($settings['appId'])
        . '"
 inputmode="numeric">
</div>

<div class="field">
<label>ログイン名</label>
<input name="username"
 value="'
        . h($settings['username'])
        . '">
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

    $html .= '<div class="card">
<h2>顧客情報同期</h2>
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

    $html .= '<div class="card">
<h2>接続状態</h2>
<p><strong>'
        . h($settings['connection'])
        . '</strong></p>';

    if ($settings['connectionDetail'] !== '') {
        $html .= '<p class="muted">'
            . h(
                $settings['connectionDetail']
            )
            . '</p>';
    }

    $html .= '</div>';

    return $html;
}

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
 value="answer_confirm">
<input type="hidden"
 name="surveyId"
 value="'
        . h($survey['id'])
        . '">';

    foreach (
        $visible
        as $qid
    ) {
        $q =
            $map[$qid]
            ?? null;

        if (!$q) {
            continue;
        }

        $value =
            $draft[$qid]
            ?? '';

        $html .= '<div class="card">
<div>
<strong>'
            . h($q['number'])
            . '</strong>
</div>
<p><strong>'
            . h($q['text'])
            . '</strong>';

        if ($q['required']) {
            $html .=
                ' <span class="muted">（必須）</span>';
        }

        $html .= '</p>';

        if ($q['type'] === 'single') {
            foreach (
                $q['options']
                as $option
            ) {
                $html .= '<label>
<input type="radio"
 name="answers['
                    . h($qid)
                    . ']"
 value="'
                    . h($option)
                    . '" '
                    . ((string)$value === $option
                        ? 'checked'
                        : '')
                    . '>
'
                    . h($option)
                    . '</label><br>';
            }
        } elseif (
            $q['type'] === 'multiple'
        ) {
            $values =
                is_array($value)
                    ? $value
                    : [];

            foreach (
                $q['options']
                as $option
            ) {
                $html .= '<label>
<input type="checkbox"
 name="answers['
                    . h($qid)
                    . '][]"
 value="'
                    . h($option)
                    . '" '
                    . (in_array(
                        $option,
                        $values,
                        true
                    )
                        ? 'checked'
                        : '')
                    . '>
'
                    . h($option)
                    . '</label><br>';
            }
        } else {
            $html .= '<textarea name="answers['
                . h($qid)
                . ']">'
                . h(
                    is_scalar($value)
                        ? $value
                        : ''
                )
                . '</textarea>';
        }

        $html .= '</div>';
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
        '<h1>回答確認</h1>';

    foreach (
        $answers
        as $qid => $value
    ) {
        if (!isset($map[$qid])) {
            continue;
        }

        $question =
            $map[$qid];

        $display =
            is_array($value)
                ? implode(
                    '、',
                    array_map(
                        'strval',
                        $value
                    )
                )
                : (string)$value;

        $html .= '<div class="card">
<strong>'
            . h($question['number'])
            . '</strong>
<p>'
            . h($question['text'])
            . '</p>
<p>'
            . nl2br(h($display))
            . '</p>
</div>';
    }

    $html .= '<div class="actions">
<a class="btn"
 href="?screen=answer&id='
        . rawurlencode($survey['id'])
        . '">修正</a>

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

function renderComplete(
    array $survey
): string {
    return '<h1>回答完了</h1>
<div class="card">
<p>アンケートへの回答を受け付けました。</p>
<p class="muted">
ご回答ありがとうございました。
</p>
</div>';
}

function renderAnalytics(
    array $data,
    array $survey
): string {
    $id =
        $survey['id'];

    $answers =
        $data['answers'][$id]
        ?? [];

    $html =
        '<h1>回答集計・分析</h1>';

    $html .= '<div class="card">
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
</p></div>';
    }

    $html .= '</div>';

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

    return $html;
}

function renderMail(
    array $data
): string {
    $s =
        $data['mailSettings'];

    return '<h1>メールサーバ設定</h1>
<div class="card">
<p>
SMTP設定画面は、kintone設定と同様に
パスワードを設定保存しない設計です。
</p>
<p class="muted">
実際のSMTP送信処理では、送信処理時に
パスワードを入力してください。
</p>
<table>
<tr><th>SMTPサーバ</th>
<td>' . h($s['server']) . '</td></tr>
<tr><th>ポート</th>
<td>' . h($s['port']) . '</td></tr>
<tr><th>暗号化</th>
<td>' . h($s['encryption']) . '</td></tr>
<tr><th>ユーザー名</th>
<td>' . h($s['username']) . '</td></tr>
<tr><th>送信元</th>
<td>' . h($s['fromEmail']) . '</td></tr>
</table>
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

    /*
     * 秘密情報・内部情報が混入した場合は
     * 汎用メッセージへ置換する。
     */
    $unsafe = [
        '/password\s*[=:]/i',
        '/authorization\s*[=:]/i',
        '/x-cybozu-authorization/i',
        '/secret\s*[=:]/i',
        '/session\s*[=:]/i',
        '/cookie\s*[=:]/i',
    ];

    foreach ($unsafe as $pattern) {
        if (preg_match(
            $pattern,
            $message
        )) {
            return '外部サービスとの通信または設定処理に失敗しました。'
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
         * 本POCではCSRF対策を実装しない。
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

            if ($id !== ''
                && $survey === null) {
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

            if (!$survey
                || !surveyAvailable($survey)) {
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

            if (!$survey
                || !surveyAvailable($survey)) {
                throw new RuntimeException(
                    '回答可能なアンケートではありません。'
                );
            }

            echo renderHeader(
                '回答確認',
                false
            );

            echo renderConfirm(
                $survey
            );

            echo renderFooter();

            exit;

        case 'complete':
            $id =
                getString('id');

            if (!validateId($id)) {
                throw new InvalidArgumentException(
                    'アンケートIDが不正です。'
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
                '回答完了',
                false
            );

            echo renderComplete(
                $survey
            );

            echo renderFooter();

            exit;

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
                '<h1>顧客選択・メール送信</h1>
<div class="card">
<p>対象アンケート:</p>
<strong>'
                . h($survey['title'])
                . '</strong>
<p class="muted">
送信対象アンケートはURLのIDによって固定されています。
</p>
</div>';

            break;

        default:
            throw new InvalidArgumentException(
                '指定された画面は利用できません。'
            );
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

    /*
     * POSTエラーであっても白画面にしない。
     * 外部通信結果や保存結果が未確定の状態で
     * 成功303を返さない。
     */
    http_response_code(500);

    echo renderHeader(
        '処理エラー',
        true
    );

    echo '<div class="alert error">'
        . h($message)
        . '</div>';

    echo '<div class="actions">
<a class="btn"
 href="?screen=list">
アンケート一覧へ戻る
</a>';

    if (
        ($screen ?? '')
        === 'kintone'
    ) {
        echo '<a class="btn"
 href="?screen=kintone">
kintone設定へ戻る
</a>';
    }

    echo '</div>';

    echo renderFooter();
}

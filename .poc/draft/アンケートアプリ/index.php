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
 * prompt.txt の仕様に従い、
 * - 管理者認証なし
 * - CSRFなし
 * - サーバー側ファイル保存
 * - 外部通信と画面遷移を分離
 * - 外部302/303を成功扱いしない
 * - パスワードをURL/HTML/JS/ログへ出さない
 * - POST成功後のみアプリ自身の303
 * - エラー時も白画面にしない
 * を実装する。
 */

const DATA_DIR  = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'app.dat.php';
const TIMEZONE  = 'Asia/Tokyo';

date_default_timezone_set(TIMEZONE);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$scriptName = str_replace(
    '\\',
    '/',
    (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')
);

$scriptDir = str_replace(
    '\\',
    '/',
    dirname($scriptName)
);

if ($scriptDir === '.' || $scriptDir === '') {
    $scriptDir = '';
}

$cookiePath = $scriptDir === ''
    ? '/'
    : rtrim($scriptDir, '/') . '/';

$isHttps =
    !empty($_SERVER['HTTPS'])
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
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
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

/**
 * アプリケーション内部で許可する画面だけへ303する。
 */
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
 * 永続データ
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
        if (
            !mkdir(
                DATA_DIR,
                0770,
                true
            )
            && !is_dir(DATA_DIR)
        ) {
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

    if (
        $contents === false
        || trim($contents) === ''
    ) {
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
     * 外部サービスパスワードをデータファイルへ
     * 入れない。
     */
    unset(
        $data['kintone']['password'],
        $data['mailSettings']['password']
    );

    $json = jsonEncode($data);

    $tmp = DATA_FILE
        . '.'
        . bin2hex(random_bytes(8))
        . '.tmp';

    $fp = @fopen($tmp, 'xb');

    if ($fp === false) {
        throw new RuntimeException(
            'データ保存用一時ファイルを作成できません。'
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException(
                'データ保存用ファイルをロックできません。'
            );
        }

        $offset = 0;
        $length = strlen($json);

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

    foreach (
        is_array($q['options'] ?? null)
            ? $q['options']
            : []
        as $option
    ) {
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
        foreach (
            $q['branches'] as $option => $target
        ) {
            if (
                is_scalar($target)
                && validateId((string)$target)
            ) {
                $branches[(string)$option] =
                    (string)$target;
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

function renumberSurvey(array &$survey): void
{
    $global = 1;

    foreach (
        $survey['groups'] as $gi => &$group
    ) {
        foreach (
            $group['questions'] as $qi => &$question
        ) {
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

            $global++;
        }

        unset($question);
    }

    unset($group);
}

function normalizeSurvey(array $survey): array
{
    $groups = [];

    foreach (
        is_array($survey['groups'] ?? null)
            ? $survey['groups']
            : []
        as $group
    ) {
        if (!is_array($group)) {
            continue;
        }

        $questions = [];

        foreach (
            is_array($group['questions'] ?? null)
                ? $group['questions']
                : []
            as $question
        ) {
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
            ($survey['numbering'] ?? 'global')
            === 'group'
                ? 'group'
                : 'global',

        'groups' => $groups,
    ];

    renumberSurvey($result);

    return $result;
}

function surveyIndex(
    array $data,
    string $id
): int {
    foreach (
        $data['surveys'] as $i => $survey
    ) {
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

    foreach (
        allQuestions($survey) as $question
    ) {
        $map[$question['id']] = $question;
    }

    return $map;
}

function canTransition(
    string $from,
    string $to
): bool {
    return match ($from) {
        'draft' =>
            $to === 'published',

        'published' =>
            $to === 'stopped',

        'stopped' =>
            $to === 'published',

        default =>
            false,
    };
}

function updateAutomaticStatus(
    array &$data
): void {
    $changed = false;
    $current = new DateTimeImmutable();

    foreach (
        $data['surveys'] as &$survey
    ) {
        if (
            ($survey['status'] ?? '')
            !== 'published'
        ) {
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

        if (
            $end !== false
            && $current > $end
        ) {
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
    if (
        ($survey['status'] ?? '')
        !== 'published'
    ) {
        return false;
    }

    $current = new DateTimeImmutable();

    if (!empty($survey['startAt'])) {
        $start = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            (string)$survey['startAt']
        );

        if (
            $start !== false
            && $current < $start
        ) {
            return false;
        }
    }

    if (!empty($survey['endAt'])) {
        $end = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            (string)$survey['endAt']
        );

        if (
            $end !== false
            && $current > $end
        ) {
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
        if (
            ($parent['type'] ?? '')
            !== 'single'
        ) {
            continue;
        }

        foreach (
            ($parent['branches'] ?? [])
            as $option => $target
        ) {
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

        $answer =
            $answers[$rule['parent']] ?? '';

        if (
            (string)$answer
            === $rule['option']
        ) {
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

        if (
            !empty($question['required'])
            && $empty
        ) {
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

        if (
            $question['type']
            === 'single'
        ) {
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

        if (
            $question['type']
            === 'multiple'
        ) {
            if (!is_array($value)) {
                $errors[] =
                    $question['number']
                    . 'の回答形式が不正です。';

                continue;
            }

            foreach ($value as $item) {
                if (
                    !in_array(
                        (string)$item,
                        $question['options'],
                        true
                    )
                ) {
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
 * 外部HTTP
 * ========================================================= */

/**
 * 外部HTTP通信結果を必ず構造化して返す。
 *
 * 外部通信関数からheader()/Locationを実行しない。
 */
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

    if (
        !$parts
        || empty($parts['host'])
    ) {
        throw new InvalidArgumentException(
            '接続先URLが不正です。'
        );
    }

    $headerText = implode(
        "\r\n",
        $headers
    );

    $options = [
        'http' => [
            'method' =>
                strtoupper($method),

            'timeout' =>
                $timeout,

            'ignore_errors' =>
                true,

            'follow_location' =>
                0,

            'max_redirects' =>
                0,

            'protocol_version' =>
                1.1,

            'header' =>
                $headerText,
        ],

        'ssl' => [
            'verify_peer' =>
                $verifyTls,

            'verify_peer_name' =>
                $verifyTls,

            'allow_self_signed' =>
                !$verifyTls,

            'SNI_enabled' =>
                true,

            'peer_name' =>
                $parts['host'],

            'capture_peer_cert' =>
                false,
        ],
    ];

    if ($body !== null) {
        $options['http']['content'] = $body;
    }

    if (
        $proxy !== null
        && $proxy !== ''
    ) {
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

        $options['http']['request_fulluri'] =
            true;
    }

    $context = stream_context_create(
        $options
    );

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
            'category' =>
                'connection_error',
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

    $bodyResult =
        stream_get_contents($fp);

    $meta =
        stream_get_meta_data($fp);

    $rawHeaders =
        $meta['wrapper_data'] ?? [];

    $headersOut = [];
    $status = 0;

    if (is_array($rawHeaders)) {
        foreach ($rawHeaders as $line) {
            if (
                preg_match(
                    '#^HTTP/\S+\s+(\d{3})#i',
                    (string)$line,
                    $m
                )
            ) {
                $status = (int)$m[1];
            } elseif (
                str_contains(
                    (string)$line,
                    ':'
                )
            ) {
                [
                    $key,
                    $value
                ] = explode(
                    ':',
                    (string)$line,
                    2
                );

                $headersOut[
                    strtolower(
                        trim($key)
                    )
                ] = trim($value);
            }
        }
    }

    fclose($fp);

    if ($bodyResult === false) {
        return [
            'ok' => false,
            'category' =>
                'response_error',
            'status' => $status,
            'body' => '',
            'headers' => $headersOut,
            'error' =>
                '外部サービスのレスポンスを取得できませんでした。',
        ];
    }

    if (!empty($meta['timed_out'])) {
        return [
            'ok' => false,
            'category' => 'timeout',
            'status' => $status,
            'body' => $bodyResult,
            'headers' => $headersOut,
            'error' =>
                '外部サービスへの通信がタイムアウトしました。',
        ];
    }

    if (
        $status >= 300
        && $status < 400
    ) {
        return [
            'ok' => false,
            'category' => 'redirect',
            'status' => $status,
            'body' => $bodyResult,
            'headers' => $headersOut,
            'error' =>
                '外部サービスからHTTP '
                . $status
                . ' リダイレクトが返されました。',
        ];
    }

    if (
        $status >= 200
        && $status < 300
    ) {
        return [
            'ok' => true,
            'category' => 'success',
            'status' => $status,
            'body' => $bodyResult,
            'headers' => $headersOut,
            'error' => '',
        ];
    }

    if (
        $status >= 400
        && $status < 500
    ) {
        return [
            'ok' => false,
            'category' =>
                'http_error',
            'status' => $status,
            'body' => $bodyResult,
            'headers' => $headersOut,
            'error' =>
                '外部サービスからHTTP '
                . $status
                . ' エラーが返されました。',
        ];
    }

    if ($status >= 500) {
        return [
            'ok' => false,
            'category' =>
                'http_error',
            'status' => $status,
            'body' => $bodyResult,
            'headers' => $headersOut,
            'error' =>
                '外部サービスでサーバーエラーが発生しました。',
        ];
    }

    return [
        'ok' => false,
        'category' => 'unknown',
        'status' => 0,
        'body' => $bodyResult,
        'headers' => $headersOut,
        'error' =>
            '外部サービスの通信結果を確定できませんでした。',
    ];
}

/* =========================================================
 * kintone
 * ========================================================= */

function normalizeKintoneHost(
    string $input
): string {
    $input = trim($input);

    if ($input === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

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

    if (
        str_ends_with(
            strtolower($input),
            '.cybozu.com'
        )
    ) {
        return $input;
    }

    return $input . '.cybozu.com';
}

function kintoneAuthorization(
    string $username,
    string $password
): string {
    if (
        $username === ''
        || $password === ''
    ) {
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
        (string)(
            $settings['subdomain'] ?? ''
        )
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

    $authorization =
        kintoneAuthorization(
            (string)(
                $settings['username'] ?? ''
            ),
            $password
        );

    /*
     * この関数内でのみAuthorizationを生成する。
     */
    $headers = [
        'X-Cybozu-Authorization: '
            . $authorization,

        'Accept: application/json',

        'Content-Type: application/json',

        'User-Agent: SurveyPOC/4.0',
    ];

    try {
        return httpRequest(
            'https://' . $host . $path,
            $method,
            $headers,
            null,
            20,
            !empty(
                $settings['sslVerify']
            ),
            !empty(
                $settings['proxy']
            )
                ? (string)$settings['proxy']
                : null
        );
    } finally {
        unset($authorization);
    }
}

function kintoneErrorMessage(
    array $response
): string {
    $body = json_decode(
        (string)(
            $response['body'] ?? ''
        ),
        true
    );

    if (is_array($body)) {
        $code = trim(
            (string)(
                $body['code'] ?? ''
            )
        );

        $message = trim(
            (string)(
                $body['message'] ?? ''
            )
        );

        /*
         * kintone APIのエラーコード・メッセージは
         * 利用者に原因を理解してもらうため使用する。
         * 認証情報そのものは出力しない。
         */
        if (
            $code !== ''
            || $message !== ''
        ) {
            return 'kintone通信エラー'
                . (
                    $code !== ''
                        ? ' [' . $code . ']'
                        : ''
                )
                . (
                    $message !== ''
                        ? ': ' . $message
                        : ''
                );
        }
    }

    return 'kintone通信に失敗しました。'
        . (
            !empty($response['error'])
                ? ' '
                . (string)$response['error']
                : ''
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

    $data['kintone'][
        'subdomain'
    ] = $subdomain;

    $data['kintone'][
        'appId'
    ] = $appId;

    $data['kintone'][
        'username'
    ] = $username;

    $data['kintone'][
        'proxy'
    ] = $proxy;

    $data['kintone'][
        'sslVerify'
    ] = $sslVerify;

    $data['kintone'][
        'connection'
    ] = '未設定';

    $data['kintone'][
        'connectionDetail'
    ] = '';

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

    try {
        if ($password === '') {
            throw new InvalidArgumentException(
                'kintoneパスワードを入力してください。'
            );
        }

        $response =
            kintoneTest(
                $data['kintone'],
                $password
            );

        if (!$response['ok']) {
            $message =
                kintoneErrorMessage(
                    $response
                );

            /*
             * 外部通信結果を確定してから保存する。
             */
            $data['kintone'][
                'connection'
            ] = '接続できません';

            $data['kintone'][
                'connectionDetail'
            ] = $message;

            saveData($data);

            flash(
                'error',
                $message
            );

            redirectTo('kintone');
        }

        $data['kintone'][
            'connection'
        ] = '接続確認済み';

        $data['kintone'][
            'connectionDetail'
        ] =
            'kintoneへの接続と認証に成功しました。';

        saveData($data);

        flash(
            'success',
            'kintone接続テストに成功しました。'
        );

        redirectTo('kintone');
    } finally {
        unset($password);
    }
}

function fetchKintoneFieldsAction(
    array &$data
): void {
    $password =
        postString('password');

    try {
        if ($password === '') {
            throw new InvalidArgumentException(
                'kintoneパスワードを入力してください。'
            );
        }

        $response =
            kintoneFields(
                $data['kintone'],
                $password
            );

        if (!$response['ok']) {
            $message =
                kintoneErrorMessage(
                    $response
                );

            flash(
                'error',
                $message
            );

            redirectTo('kintone');
        }

        $json = json_decode(
            (string)$response['body'],
            true
        );

        if (
            !is_array($json)
            || !is_array(
                $json['properties'] ?? null
            )
        ) {
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
                'label' =>
                    (string)(
                        $field['label']
                        ?? ''
                    ),

                'type' =>
                    (string)(
                        $field['type']
                        ?? ''
                    ),
            ];
        }

        if (!$fields) {
            throw new RuntimeException(
                'kintone項目一覧が空でした。'
            );
        }

        $data['kintone'][
            'fields'
        ] = $fields;

        saveData($data);

        flash(
            'success',
            'kintone項目一覧を取得しました。'
        );

        redirectTo('kintone');
    } finally {
        unset($password);
    }
}

function syncKintoneAction(
    array &$data
): void {
    $password =
        postString('password');

    try {
        if ($password === '') {
            throw new InvalidArgumentException(
                'kintoneパスワードを入力してください。'
            );
        }

        $response =
            kintoneRecords(
                $data['kintone'],
                $password
            );

        if (!$response['ok']) {
            $message =
                kintoneErrorMessage(
                    $response
                );

            flash(
                'error',
                $message
            );

            redirectTo('kintone');
        }

        $json = json_decode(
            (string)$response['body'],
            true
        );

        if (
            !is_array($json)
            || !is_array(
                $json['records'] ?? null
            )
        ) {
            throw new RuntimeException(
                'kintone顧客情報を取得できませんでした。'
            );
        }

        $mapping =
            $data['kintone'][
                'mappings'
            ] ?? [];

        $readField =
            static function (
                array $record,
                string $code
            ): string {
                if (
                    $code === ''
                    || !isset($record[$code])
                ) {
                    return '';
                }

                $value =
                    $record[$code]['value']
                    ?? '';

                if (is_array($value)) {
                    $parts = [];

                    foreach (
                        $value as $v
                    ) {
                        if (is_scalar($v)) {
                            $parts[] =
                                trim((string)$v);
                        }
                    }

                    $value =
                        implode(
                            ' ',
                            $parts
                        );
                }

                return trim(
                    (string)$value
                );
            };

        $customers = [];

        foreach (
            $json['records'] as $record
        ) {
            if (!is_array($record)) {
                continue;
            }

            $addressParts = [];

            foreach (
                is_array(
                    $mapping['address']
                    ?? null
                )
                    ? $mapping['address']
                    : []
                as $code
            ) {
                $value =
                    $readField(
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
                    uid('customer'),

                'org' =>
                    $readField(
                        $record,
                        (string)(
                            $mapping['org']
                            ?? ''
                        )
                    ),

                'name' =>
                    $readField(
                        $record,
                        (string)(
                            $mapping['name']
                            ?? ''
                        )
                    ),

                'email' =>
                    $readField(
                        $record,
                        (string)(
                            $mapping['email']
                            ?? ''
                        )
                    ),

                'department' =>
                    $readField(
                        $record,
                        (string)(
                            $mapping['department']
                            ?? ''
                        )
                    ),

                'phone' =>
                    $readField(
                        $record,
                        (string)(
                            $mapping['phone']
                            ?? ''
                        )
                    ),

                'address' =>
                    implode(
                        ' ',
                        $addressParts
                    ),
            ];
        }

        /*
         * kintoneレスポンスを完全に検証した後、
         * はじめて顧客データを置き換える。
         */
        $data['customers'] =
            $customers;

        saveData($data);

        flash(
            'success',
            count($customers)
            . '件の顧客情報を同期しました。'
        );

        redirectTo('kintone');
    } finally {
        unset($password);
    }
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

    $description =
        postString('description');

    $startAt =
        postString('startAt');

    $endAt =
        postString('endAt');

    if (
        !validDateTime($startAt)
        || !validDateTime($endAt)
    ) {
        throw new InvalidArgumentException(
            '日時の形式が不正です。'
        );
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && $startAt >= $endAt
    ) {
        throw new InvalidArgumentException(
            '終了日時は開始日時より後にしてください。'
        );
    }

    $numbering =
        postString(
            'numbering',
            'global'
        );

    if (
        !in_array(
            $numbering,
            ['global', 'group'],
            true
        )
    ) {
        $numbering = 'global';
    }

    $existing =
        $id !== ''
            ? surveyById(
                $data,
                $id
            )
            : null;

    $groupsRaw =
        postArray('groups');

    $groups = [];

    foreach (
        $groupsRaw as $groupRaw
    ) {
        if (!is_array($groupRaw)) {
            continue;
        }

        $groupId =
            validateId(
                (string)(
                    $groupRaw['id'] ?? ''
                )
            )
                ? (string)$groupRaw['id']
                : uid('g');

        $questions = [];

        foreach (
            is_array(
                $groupRaw['questions']
                ?? null
            )
                ? $groupRaw['questions']
                : []
            as $qRaw
        ) {
            if (!is_array($qRaw)) {
                continue;
            }

            $optionsText =
                (string)(
                    $qRaw['options_text']
                    ?? ''
                );

            $options =
                preg_split(
                    '/\R/u',
                    $optionsText
                );

            if (!is_array($options)) {
                $options = [];
            }

            $branches = [];

            if (
                is_array(
                    $qRaw['branches']
                    ?? null
                )
            ) {
                foreach (
                    $qRaw['branches']
                    as $option => $target
                ) {
                    if (
                        is_scalar($target)
                        && validateId(
                            (string)$target
                        )
                    ) {
                        $branches[
                            (string)$option
                        ] =
                            (string)$target;
                    }
                }
            }

            $questions[] = [
                'id' =>
                    validateId(
                        (string)(
                            $qRaw['id'] ?? ''
                        )
                    )
                        ? (string)$qRaw['id']
                        : uid('q'),

                'text' =>
                    trim(
                        (string)(
                            $qRaw['text'] ?? ''
                        )
                    ),

                'type' =>
                    (string)(
                        $qRaw['type']
                        ?? 'single'
                    ),

                'required' =>
                    !empty(
                        $qRaw['required']
                    ),

                'options' =>
                    $options,

                'branches' =>
                    $branches,
            ];
        }

        $groups[] = [
            'id' =>
                $groupId,

            'title' =>
                trim(
                    (string)(
                        $groupRaw['title']
                        ?? ''
                    )
                ),

            'questions' =>
                $questions,
        ];
    }

    $survey =
        normalizeSurvey([
            'id' =>
                $existing['id']
                ?? (
                    $id !== ''
                        ? $id
                        : uid('survey')
                ),

            'createdAt' =>
                $existing['createdAt']
                ?? today(),

            'updatedAt' =>
                today(),

            'title' =>
                $title,

            'description' =>
                $description,

            'startAt' =>
                $startAt,

            'endAt' =>
                $endAt,

            'status' =>
                $existing['status']
                ?? 'draft',

            'numbering' =>
                $numbering,

            'groups' =>
                $groups,
        ]);

    /*
     * 新規はdraft。
     * 編集時は現在状態を維持。
     */
    if (!$existing) {
        $survey['status'] = 'draft';
    }

    $index =
        $id !== ''
            ? surveyIndex(
                $data,
                $id
            )
            : -1;

    if ($index >= 0) {
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
            'アンケートが存在しません。'
        );
    }

    $from =
        (string)(
            $data['surveys'][$index]['status']
            ?? ''
        );

    if (
        !canTransition(
            $from,
            $to
        )
    ) {
        throw new InvalidArgumentException(
            '指定された状態遷移は許可されていません。'
        );
    }

    $data['surveys'][$index][
        'status'
    ] = $to;

    $data['surveys'][$index][
        'updatedAt'
    ] = today();

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

    $survey['createdAt'] =
        today();

    $survey['updatedAt'] =
        today();

    $survey['status'] =
        'draft';

    foreach (
        $survey['groups'] as &$group
    ) {
        $group['id'] =
            uid('g');

        foreach (
            $group['questions']
            as &$question
        ) {
            $question['id'] =
                uid('q');
        }

        unset($question);
    }

    unset($group);

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
        $_SESSION[
            'answer_draft'
        ] = $answers;

        $_SESSION[
            'answer_errors'
        ] = $errors;

        redirectTo(
            'answer',
            ['id' => $surveyId]
        );
    }

    $_SESSION[
        'answer_draft'
    ] = $answers;

    $_SESSION[
        'answer_survey'
    ] = $surveyId;

    $customerId =
        postString('customerId');

    if (
        $customerId !== ''
        && validateId($customerId)
    ) {
        $_SESSION[
            'answer_customer'
        ] = $customerId;
    } else {
        unset(
            $_SESSION['answer_customer']
        );
    }

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

    if (
        !$survey
        || !surveyAvailable($survey)
    ) {
        throw new RuntimeException(
            '回答可能なアンケートではありません。'
        );
    }

    $answers =
        $_SESSION[
            'answer_draft'
        ] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $errors =
        validateAnswers(
            $survey,
            $answers
        );

    if ($errors) {
        $_SESSION[
            'answer_errors'
        ] = $errors;

        redirectTo(
            'answer',
            ['id' => $surveyId]
        );
    }

    $customerId =
        (string)(
            $_SESSION[
                'answer_customer'
            ] ?? ''
        );

    $customer = null;

    if (validateId($customerId)) {
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
    }

    if (!isset(
        $data['answers'][$surveyId]
    )) {
        $data['answers'][$surveyId] =
            [];
    }

    $data['answers'][$surveyId][] = [
        'id' =>
            uid('answer'),

        'customerId' =>
            $customerId,

        'customer' =>
            $customer['name']
            ?? '未登録回答者',

        'org' =>
            $customer['org']
            ?? '',

        'date' =>
            now(),

        'values' =>
            $answers,
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
 * POST Router
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
        $nav =
            '<nav class="nav">'
            . '<a href="?screen=list">アンケート一覧</a>'
            . '<a href="?screen=kintone">kintone設定</a>'
            . '<a href="?screen=mail">メール設定</a>'
            . '</nav>';
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
*{box-sizing:border-box}
body{
 margin:0;
 background:#f4f6f8;
 color:#263238;
 font-family:
  -apple-system,BlinkMacSystemFont,
  "Segoe UI","Noto Sans JP",sans-serif;
 line-height:1.6
}
header{
 background:#263238;
 color:#fff;
 padding:14px 20px
}
header .head{
 max-width:1200px;
 margin:auto;
 display:flex;
 justify-content:space-between;
 align-items:center;
 gap:20px;
 flex-wrap:wrap
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
 grid-template-columns:
  repeat(2,minmax(0,1fr));
 gap:16px
}
.field{
 margin-bottom:16px
}
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
 padding:12px 15px;
 border-radius:7px;
 margin-bottom:18px
}
.alert.success{
 background:#e8f5e9;
 color:#256029
}
.alert.error{
 background:#ffebee;
 color:#b71c1c
}
.muted{
 color:#68747c
}
table{
 width:100%;
 border-collapse:collapse;
 background:#fff
}
th,td{
 border-bottom:1px solid #e2e6e9;
 padding:9px;
 text-align:left;
 vertical-align:top
}
th{
 background:#f7f9fa
}
.question{
 border:1px solid #dce2e8;
 border-radius:8px;
 padding:15px;
 margin-bottom:12px
}
.option{
 padding:5px 0
}
.badge{
 display:inline-block;
 padding:2px 8px;
 border-radius:999px;
 background:#eceff1;
 font-size:12px
}
@media(max-width:700px){
 .grid2{
  grid-template-columns:1fr
 }
 table{
  display:block;
  overflow-x:auto
 }
}
</style>
</head>
<body>
<header>
<div class="head">
<div><strong>アンケートアプリ</strong></div>'
        . $nav
        . '</div>
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
    $search =
        getString('q');

    $status =
        getString(
            'status',
            'all'
        );

    $sort =
        getString(
            'sort',
            'updated_desc'
        );

    $surveys =
        array_values(
            array_filter(
                $data['surveys'],
                static function (
                    array $survey
                ) use (
                    $search,
                    $status
                ): bool {
                    if (
                        $search !== ''
                        && !str_contains(
                            mb_strtolower(
                                (string)(
                                    $survey['title']
                                    ?? ''
                                )
                            ),
                            mb_strtolower(
                                $search
                            )
                        )
                    ) {
                        return false;
                    }

                    if (
                        $status !== 'all'
                        && (
                            $survey['status']
                            ?? ''
                        ) !== $status
                    ) {
                        return false;
                    }

                    return true;
                }
            )
        );

    usort(
        $surveys,
        static function (
            array $a,
            array $b
        ) use ($sort): int {
            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)(
                            $a['updatedAt']
                            ?? ''
                        ),
                        (string)(
                            $b['updatedAt']
                            ?? ''
                        )
                    ),

                'answers_desc' =>
                    count(
                        $GLOBALS['data']['answers'][
                            $b['id']
                        ] ?? []
                    )
                    <=>
                    count(
                        $GLOBALS['data']['answers'][
                            $a['id']
                        ] ?? []
                    ),

                'answers_asc' =>
                    count(
                        $GLOBALS['data']['answers'][
                            $a['id']
                        ] ?? []
                    )
                    <=>
                    count(
                        $GLOBALS['data']['answers'][
                            $b['id']
                        ] ?? []
                    ),

                'start_desc' =>
                    strcmp(
                        (string)(
                            $b['startAt']
                            ?? ''
                        ),
                        (string)(
                            $a['startAt']
                            ?? ''
                        )
                    ),

                'start_asc' =>
                    strcmp(
                        (string)(
                            $a['startAt']
                            ?? ''
                        ),
                        (string)(
                            $b['startAt']
                            ?? ''
                        )
                    ),

                default =>
                    strcmp(
                        (string)(
                            $b['updatedAt']
                            ?? ''
                        ),
                        (string)(
                            $a['updatedAt']
                            ?? ''
                        )
                    ),
            };
        }
    );

    $html =
        '<h1>アンケート一覧</h1>';

    $html .= '<div class="card">
<form method="get">
<input type="hidden" name="screen" value="list">
<div class="grid2">
<div class="field">
<label>タイトル検索</label>
<input name="q"
 value="'
        . h($search)
        . '"
 placeholder="タイトル">
</div>
<div class="field">
<label>ステータス</label>
<select name="status">
<option value="all">すべて</option>
<option value="published" '
        . (
            $status === 'published'
                ? 'selected'
                : ''
        )
        . '>公開中</option>
<option value="draft" '
        . (
            $status === 'draft'
                ? 'selected'
                : ''
        )
        . '>下書き</option>
<option value="stopped" '
        . (
            $status === 'stopped'
                ? 'selected'
                : ''
        )
        . '>停止</option>
<option value="ended" '
        . (
            $status === 'ended'
                ? 'selected'
                : ''
        )
        . '>終了</option>
</select>
</div>
</div>
<div class="field">
<label>ソート</label>
<select name="sort">
<option value="updated_desc">更新日：新しい順</option>
<option value="updated_asc">更新日：古い順</option>
<option value="answers_desc">回答数：多い順</option>
<option value="answers_asc">回答数：少ない順</option>
<option value="start_desc">開始日：新しい順</option>
<option value="start_asc">開始日：古い順</option>
</select>
</div>
<button class="primary">検索</button>
</form>
</div>';

    $html .= '<div class="actions">
<a class="btn primary"
 href="?screen=edit">
新規作成
</a>
</div>';

    if (!$surveys) {
        return $html
            . '<div class="card">
<p>アンケートはありません。</p>
</div>';
    }

    $html .= '<div class="card">
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
<tbody>';

    foreach ($surveys as $survey) {
        $id =
            (string)$survey['id'];

        $answers =
            $data['answers'][$id]
            ?? [];

        $html .= '<tr>
<td>'
            . h($survey['title'])
            . '</td>
<td>'
            . h($survey['createdAt'])
            . '</td>
<td>'
            . h($survey['updatedAt'])
            . '</td>
<td>'
            . h(
                ($survey['startAt'] ?: '-')
                . ' ～ '
                . ($survey['endAt'] ?: '-')
            )
            . '</td>
<td><span class="badge">'
            . h($survey['status'])
            . '</span></td>
<td>'
            . h(count($answers))
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
 href="?screen=analytics&id='
            . rawurlencode($id)
            . '">集計</a>
<a class="btn"
 href="?screen=send&id='
            . rawurlencode($id)
            . '">送信</a>
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
</form>';

        if (
            ($survey['status'] ?? '')
            === 'draft'
        ) {
            $html .= '
<form method="post" style="display:inline">
<input type="hidden"
 name="action"
 value="transition">
<input type="hidden"
 name="id"
 value="'
                . h($id)
                . '">
<input type="hidden"
 name="to"
 value="published">
<button class="primary">公開</button>
</form>';
        } elseif (
            ($survey['status'] ?? '')
            === 'published'
        ) {
            $html .= '
<form method="post" style="display:inline">
<input type="hidden"
 name="action"
 value="transition">
<input type="hidden"
 name="id"
 value="'
                . h($id)
                . '">
<input type="hidden"
 name="to"
 value="stopped">
<button>停止</button>
</form>';
        } elseif (
            ($survey['status'] ?? '')
            === 'stopped'
        ) {
            $html .= '
<form method="post" style="display:inline">
<input type="hidden"
 name="action"
 value="transition">
<input type="hidden"
 name="id"
 value="'
                . h($id)
                . '">
<input type="hidden"
 name="to"
 value="published">
<button class="primary">再公開</button>
</form>';
        }

        $html .= '</div>
</td>
</tr>';
    }

    $html .= '</tbody>
</table>
</div>';

    return $html;
}

function renderEdit(
    array $data,
    ?array $survey
): string {
    $survey ??= normalizeSurvey([
        'title' => '',
        'description' => '',
        'startAt' => '',
        'endAt' => '',
        'numbering' => 'global',
        'groups' => [
            [
                'title' => 'グループ1',
                'questions' => [],
            ],
        ],
    ]);

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
 maxlength="200"
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
        . (
            $survey['numbering']
            === 'global'
                ? 'selected'
                : ''
        )
        . '>全体通番（Q1、Q2…）</option>
<option value="group" '
        . (
            $survey['numbering']
            === 'group'
                ? 'selected'
                : ''
        )
        . '>グループ単位（Q1-1、Q1-2…）</option>
</select>
</div>
</div>';

    foreach (
        $survey['groups'] as $gi => $group
    ) {
        $html .= '<div class="card">
<h2>グループ '
            . h($gi + 1)
            . '</h2>
<div class="field">
<label>グループタイトル</label>
<input name="groups['
            . h($gi)
            . '][title]"
 value="'
            . h($group['title'])
            . '">
<input type="hidden"
 name="groups['
            . h($gi)
            . '][id]"
 value="'
            . h($group['id'])
            . '">
</div>';

        foreach (
            $group['questions']
            as $qi => $question
        ) {
            $html .= '<div class="question">
<h3>'
                . h($question['number'])
                . '</h3>

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
<textarea name="groups['
                . h($gi)
                . '][questions]['
                . h($qi)
                . '][text]"
 required>'
                . h($question['text'])
                . '</textarea>
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
                . (
                    $question['type']
                    === 'single'
                        ? 'selected'
                        : ''
                )
                . '>単一選択</option>
<option value="multiple" '
                . (
                    $question['type']
                    === 'multiple'
                        ? 'selected'
                        : ''
                )
                . '>複数選択</option>
<option value="free" '
                . (
                    $question['type']
                    === 'free'
                        ? 'selected'
                        : ''
                )
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
                . (
                    $question['required']
                        ? 'selected'
                        : ''
                )
                . '>必須</option>
</select>
</div>
</div>

<div class="field">
<label>選択肢（1行1項目）</label>
<textarea name="groups['
                . h($gi)
                . '][questions]['
                . h($qi)
                . '][options_text]">'
                . h(
                    implode(
                        "\n",
                        $question['options']
                    )
                )
                . '</textarea>
</div>
</div>';
        }

        $html .= '</div>';
    }

    $html .= '<div class="actions">
<button class="primary">
保存して一覧へ
</button>
<a class="btn"
 href="?screen=list"
 onclick="return confirm(\'編集内容を破棄しますか？\')">
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
        . '</h2>
<p>'
        . nl2br(
            h($survey['description'])
        )
        . '</p>
<p class="muted">
プレビューでは回答データを保存しません。
</p>
</div>';

    foreach (
        $survey['groups'] as $group
    ) {
        $html .= '<div class="card">
<h2>'
            . h($group['title'])
            . '</h2>';

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
                . (
                    $question['required']
                        ? ' <span class="muted">（必須）</span>'
                        : ''
                )
                . '</p>';

            foreach (
                $question['options']
                as $option
            ) {
                $html .= '<div class="option">'
                    . h($option)
                    . '</div>';
            }

            if (
                $question['type']
                === 'free'
            ) {
                $html .= '<div class="muted">
自由記述欄
</div>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';
    }

    return $html;
}

function renderKintone(
    array $data
): string {
    $k =
        $data['kintone'];

    $html =
        '<h1>kintone設定</h1>';

    $html .= '<div class="card">
<form method="post">
<input type="hidden"
 name="action"
 value="save_kintone">

<div class="field">
<label>サブドメイン</label>
<input name="subdomain"
 value="'
        . h($k['subdomain'])
        . '"
 placeholder="example / example.cybozu.com">
</div>

<div class="field">
<label>顧客管理アプリID</label>
<input name="appId"
 inputmode="numeric"
 value="'
        . h($k['appId'])
        . '">
</div>

<div class="field">
<label>ログイン名</label>
<input name="username"
 value="'
        . h($k['username'])
        . '">
</div>

<div class="field">
<label>Proxy</label>
<input name="proxy"
 value="'
        . h($k['proxy'])
        . '"
 placeholder="host:port">
</div>

<div class="field">
<label>SSL証明書検証</label>
<select name="sslVerify">
<option value="1" '
        . (
            !empty($k['sslVerify'])
                ? 'selected'
                : ''
        )
        . '>有効</option>
<option value="0" '
        . (
            empty($k['sslVerify'])
                ? 'selected'
                : ''
        )
        . '>無効</option>
</select>
</div>

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
 autocomplete="off"
 required>
</div>

<button class="primary">
接続テスト
</button>
</form>
</div>';

    $html .= '<div class="card">
<h2>項目一覧取得</h2>
<form method="post">
<input type="hidden"
 name="action"
 value="fetch_kintone_fields">

<div class="field">
<label>kintoneパスワード</label>
<input type="password"
 name="password"
 autocomplete="off"
 required>
</div>

<button class="primary">
項目一覧を再取得
</button>
</form>
</div>';

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
 autocomplete="off"
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
        . h($k['connection'])
        . '</strong></p>';

    if (
        !empty(
            $k['connectionDetail']
        )
    ) {
        $html .= '<p>'
            . h(
                $k['connectionDetail']
            )
            . '</p>';
    }

    $html .= '</div>';

    if (!empty($k['fields'])) {
        $html .= '<div class="card">
<h2>kintone項目</h2>
<table>
<thead>
<tr>
<th>コード</th>
<th>ラベル</th>
<th>型</th>
</tr>
</thead>
<tbody>';

        foreach (
            $k['fields'] as $code => $field
        ) {
            $html .= '<tr>
<td>'
                . h($code)
                . '</td>
<td>'
                . h(
                    $field['label']
                    ?? ''
                )
                . '</td>
<td>'
                . h(
                    $field['type']
                    ?? ''
                )
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
SMTP設定ではパスワードを画面に再表示・保存しません。
</p>
<table>
<tr>
<th>SMTPサーバ</th>
<td>'
        . h($s['server'])
        . '</td>
</tr>
<tr>
<th>ポート</th>
<td>'
        . h($s['port'])
        . '</td>
</tr>
<tr>
<th>暗号化</th>
<td>'
        . h($s['encryption'])
        . '</td>
</tr>
<tr>
<th>ユーザー名</th>
<td>'
        . h($s['username'])
        . '</td>
</tr>
<tr>
<th>送信元</th>
<td>'
        . h($s['fromEmail'])
        . '</td>
</tr>
</table>
</div>';
}

function renderAnswer(
    array $survey
): string {
    $draft =
        $_SESSION[
            'answer_draft'
        ] ?? [];

    if (!is_array($draft)) {
        $draft = [];
    }

    $errors =
        $_SESSION[
            'answer_errors'
        ] ?? [];

    unset(
        $_SESSION['answer_errors']
    );

    $html =
        '<h1>'
        . h($survey['title'])
        . '</h1>';

    if ($errors) {
        $html .= '<div class="alert error">
<ul>';

        foreach ($errors as $error) {
            $html .= '<li>'
                . h($error)
                . '</li>';
        }

        $html .= '</ul>
</div>';
    }

    $visible =
        visibleQuestionIds(
            $survey,
            $draft
        );

    $map =
        questionMap($survey);

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
        $visible as $qid
    ) {
        if (!isset($map[$qid])) {
            continue;
        }

        $q =
            $map[$qid];

        $value =
            $draft[$qid] ?? '';

        $html .= '<div class="card">
<strong>'
            . h($q['number'])
            . '</strong>
<p><strong>'
            . h($q['text'])
            . '</strong>';

        if ($q['required']) {
            $html .=
                ' <span class="muted">（必須）</span>';
        }

        $html .= '</p>';

        if (
            $q['type']
            === 'single'
        ) {
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
                    . (
                        (string)$value
                        === $option
                            ? 'checked'
                            : ''
                    )
                    . '>
'
                    . h($option)
                    . '</label>';
            }
        } elseif (
            $q['type']
            === 'multiple'
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
                    . (
                        in_array(
                            $option,
                            $values,
                            true
                        )
                            ? 'checked'
                            : ''
                    )
                    . '>
'
                    . h($option)
                    . '</label>';
            }
        } else {
            $html .= '<textarea
 name="answers['
                . h($qid)
                . ']">'
                . h($value)
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
        $_SESSION[
            'answer_draft'
        ] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $map =
        questionMap($survey);

    $html =
        '<h1>回答確認</h1>';

    foreach (
        visibleQuestionIds(
            $survey,
            $answers
        ) as $qid
    ) {
        if (!isset($map[$qid])) {
            continue;
        }

        $question =
            $map[$qid];

        $value =
            $answers[$qid] ?? '';

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
        . rawurlencode(
            $survey['id']
        )
        . '">
修正
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
<p>
アンケートへの回答を受け付けました。
</p>
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

    $customers =
        $data['customers']
        ?? [];

    $sent = 0;

    foreach (
        $data['sendHistory']
        ?? [] as $history
    ) {
        if (
            ($history['surveyId'] ?? '')
            === $id
            && !empty(
                $history['success']
            )
        ) {
            $sent++;
        }
    }

    $unregistered = 0;

    foreach (
        $answers as $answer
    ) {
        if (
            ($answer['customer'] ?? '')
            === '未登録回答者'
        ) {
            $unregistered++;
        }
    }

    $answered =
        count($answers);

    $responseRate =
        $sent > 0
            ? round(
                ($answered / $sent)
                * 100,
                1
            )
            : null;

    $html =
        '<h1>回答集計・分析</h1>';

    $html .= '<div class="card">
<h2>'
        . h($survey['title'])
        . '</h2>
<table>
<tr>
<th>送信対象者数</th>
<td>'
        . h($sent)
        . '</td>
</tr>
<tr>
<th>回答数</th>
<td>'
        . h($answered)
        . '</td>
</tr>
<tr>
<th>未登録回答数</th>
<td>'
        . h($unregistered)
        . '</td>
</tr>
<tr>
<th>未回答数</th>
<td>'
        . h(
            max(
                0,
                $sent - $answered
            )
        )
        . '</td>
</tr>
<tr>
<th>回答率</th>
<td>'
        . h(
            $responseRate === null
                ? '-'
                : $responseRate . '%'
        )
        . '</td>
</tr>
</table>
</div>';

    if (!$answers) {
        return $html
            . '<div class="card">
<p>現在、回答データはありません</p>
</div>';
    }

    foreach (
        allQuestions($survey)
        as $question
    ) {
        if (
            !in_array(
                $question['type'],
                ['single', 'multiple'],
                true
            )
        ) {
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
            $answers as $answer
        ) {
            $value =
                $answer['values'][
                    $question['id']
                ] ?? null;

            if (is_array($value)) {
                foreach ($value as $item) {
                    if (
                        isset(
                            $counts[$item]
                        )
                    ) {
                        $counts[$item]++;
                    }
                }
            } elseif (
                isset(
                    $counts[(string)$value]
                )
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
            $counts as $option => $count
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
<table>
<thead>
<tr>
<th>日時</th>
<th>組織</th>
<th>回答者</th>
<th>回答</th>
</tr>
</thead>
<tbody>';

    $map =
        questionMap($survey);

    foreach (
        $answers as $answer
    ) {
        $values = [];

        foreach (
            $answer['values']
            as $qid => $value
        ) {
            if (!isset($map[$qid])) {
                continue;
            }

            $values[] =
                $map[$qid]['number']
                . ': '
                . (
                    is_array($value)
                        ? implode(
                            '、',
                            array_map(
                                'strval',
                                $value
                            )
                        )
                        : (string)$value
                );
        }

        $html .= '<tr>
<td>'
            . h($answer['date'] ?? '')
            . '</td>
<td>'
            . h($answer['org'] ?? '')
            . '</td>
<td>'
            . h($answer['customer'] ?? '')
            . '</td>
<td>'
            . nl2br(
                h(
                    implode(
                        "\n",
                        $values
                    )
                )
            )
            . '</td>
</tr>';
    }

    return $html
        . '</tbody>
</table>
</div>';
}

function renderSend(
    array $data,
    array $survey
): string {
    $customers =
        $data['customers']
        ?? [];

    $html =
        '<h1>顧客選択・メール送信</h1>';

    $html .= '<div class="card">
<p>対象アンケート:</p>
<strong>'
        . h($survey['title'])
        . '</strong>
<p class="muted">
対象アンケートIDはURLで固定されています。
</p>
</div>';

    $html .= '<div class="card">
<h2>顧客</h2>';

    if (!$customers) {
        $html .= '<p>
顧客データがありません。
kintone設定から顧客同期を実行してください。
</p>';
    } else {
        $html .= '<table>
<thead>
<tr>
<th>組織</th>
<th>氏名</th>
<th>メール</th>
<th>部署</th>
</tr>
</thead>
<tbody>';

        foreach (
            $customers as $customer
        ) {
            $html .= '<tr>
<td>'
                . h(
                    $customer['org']
                    ?? ''
                )
                . '</td>
<td>'
                . h(
                    $customer['name']
                    ?? ''
                )
                . '</td>
<td>'
                . h(
                    $customer['email']
                    ?? ''
                )
                . '</td>
<td>'
                . h(
                    $customer['department']
                    ?? ''
                )
                . '</td>
</tr>';
        }

        $html .= '</tbody>
</table>';
    }

    $html .= '</div>';

    $history =
        $data['sendHistory']
        ?? [];

    $html .= '<div class="card">
<h2>送信履歴</h2>';

    $found = false;

    foreach (
        array_reverse($history)
        as $item
    ) {
        if (
            ($item['surveyId'] ?? '')
            !== $survey['id']
        ) {
            continue;
        }

        $found = true;

        $html .= '<div class="question">
<strong>'
            . h($item['date'] ?? '')
            . '</strong>
<p>'
            . h(
                $item['email']
                ?? ''
            )
            . '</p>
<p>'
            . h(
                !empty($item['success'])
                    ? '送信成功'
                    : '送信失敗'
            )
            . '</p>
</div>';
    }

    if (!$found) {
        $html .= '<p class="muted">
送信履歴はありません。
</p>';
    }

    return $html
        . '</div>';
}

/* =========================================================
 * 安全なエラー表示
 * ========================================================= */

function safeErrorMessage(
    Throwable $e
): string {
    $message =
        trim(
            $e->getMessage()
        );

    if ($message === '') {
        return '処理中にエラーが発生しました。';
    }

    /*
     * 秘密情報・内部情報が含まれる可能性が
     * ある例外は汎用メッセージへ変換する。
     */
    $unsafePatterns = [
        '/password\s*[=:]/i',
        '/authorization\s*[=:]/i',
        '/x-cybozu-authorization/i',
        '/secret\s*[=:]/i',
        '/session\s*[=:]/i',
        '/cookie\s*[=:]/i',
        '/PHPSESSID/i',
    ];

    foreach (
        $unsafePatterns as $pattern
    ) {
        if (
            preg_match(
                $pattern,
                $message
            )
        ) {
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
$screen = 'list';

try {
    $data =
        readData();

    updateAutomaticStatus(
        $data
    );

    /*
     * POST
     *
     * 各POST処理が成功すれば、その処理自身が
     * アプリケーション内303を発行する。
     *
     * 外部通信処理自身はリダイレクトしない。
     */
    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        === 'POST'
    ) {
        processPost($data);

        /*
         * 到達した場合は、POST処理が結果を確定して
         * 303する責務を果たしていない。
         *
         * これは成功扱いしない。
         */
        throw new RuntimeException(
            'POST処理の結果を確定できませんでした。'
        );
    }

    $screen =
        getString(
            'screen',
            'list'
        );

    /*
     * 許可されたscreen以外はアプリケーション外へ
     * 遷移させない。
     */
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
            '指定された画面は利用できません。'
        );
    }

    $title =
        'アンケート一覧';

    $content =
        '';

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

            /*
             * confirmはanswer_draftを使うため、
             * セッションのsurvey IDも確認する。
             */
            if (
                ($_SESSION[
                    'answer_survey'
                ] ?? '')
                !== $id
            ) {
                throw new RuntimeException(
                    '回答確認データがありません。'
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

            /*
             * complete画面には管理者メニューを出さない。
             */
            echo renderHeader(
                '回答完了',
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
            . h(
                $flash['type']
            )
            . '">'
            . h(
                $flash['message']
            )
            . '</div>';
    }

    echo $content;

    echo renderFooter();

} catch (Throwable $e) {
    /*
     * エラー処理自身で新たな例外を発生させない。
     */
    $message =
        safeErrorMessage($e);

    /*
     * POSTエラーでも白画面にはしない。
     *
     * 重要：
     * 現行版のように「常に500を返す」のではなく、
     * 業務エラーは処理開始画面へ303で戻す。
     *
     * ただし、結果未確定の外部通信を成功扱いしない。
     */
    $requestMethod =
        $_SERVER['REQUEST_METHOD']
        ?? 'GET';

    if (
        $requestMethod === 'POST'
        && is_array($data)
    ) {
        /*
         * パスワードを含まないエラー情報だけを
         * セッションへ保存する。
         */
        flash(
            'error',
            $message
        );

        $action =
            postString('action');

        /*
         * 操作ごとの開始画面を固定する。
         * ユーザー入力を任意URLとして使用しない。
         */
        $errorScreen = 'list';
        $params = [];

        switch ($action) {
            case 'save_kintone':
            case 'test_kintone':
            case 'fetch_kintone_fields':
            case 'sync_kintone':
                $errorScreen =
                    'kintone';
                break;

            case 'save_survey':
                $errorScreen =
                    'edit';

                $id =
                    postString('id');

                if (
                    $id !== ''
                    && validateId($id)
                ) {
                    $params['id'] =
                        $id;
                }
                break;

            case 'answer_confirm':
            case 'answer_submit':
                $errorScreen =
                    'answer';

                $id =
                    postString('surveyId');

                if (
                    validateId($id)
                ) {
                    $params['id'] =
                        $id;
                }
                break;

            default:
                $errorScreen =
                    'list';
                break;
        }

        /*
         * エラー結果はflashへ保存済み。
         * パスワードはURLへ移送しない。
         */
        redirectTo(
            $errorScreen,
            $params
        );
    }

    /*
     * GET側のシステムエラーは白画面にせず、
     * 安全なエラー画面を表示する。
     *
     * GETのエラーだけはHTTP 500として扱う。
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
        $screen === 'kintone'
    ) {
        echo '<a class="btn"
 href="?screen=kintone">
kintone設定へ戻る
</a>';
    }

    echo '</div>';

    echo renderFooter();
}

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
 * 重要:
 * - kintone GETにはContent-Typeを付与しない
 * - kintone POSTを使用する場合のみContent-Type: application/json
 * - 外部302/303は成功扱いしない
 * - 外部通信処理からリダイレクトしない
 * - アプリ自身の303はPRGとして結果確定後のみ使用
 * - kintone/SMTPパスワードは永続保存しない
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

    if ($contents === false || trim($contents) === '') {
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
     * 外部サービス用パスワードは保存しない。
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

    if (is_array($q['options'] ?? null)) {
        foreach ($q['options'] as $option) {
            if (!is_scalar($option)) {
                continue;
            }

            $option = trim((string)$option);

            if ($option !== '') {
                $options[] = $option;
            }
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

    $numbering =
        ($survey['numbering'] ?? 'global') === 'group'
            ? 'group'
            : 'global';

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
        'numbering' => $numbering,
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

        $answer = $answers[
            $rule['parent']
        ] ?? '';

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
 * PHP cURLを使わず、PHP標準stream wrapperを使用する。
 * GETとPOSTでContent-Typeを厳密に分離する。
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
    $method = strtoupper($method);

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

    /*
     * Content-Typeは呼び出し側で指定する。
     *
     * GET + URL query:
     *   Content-Typeなし
     *
     * POST + JSON body:
     *   Content-Type: application/json
     */
    $headerText = implode(
        "\r\n",
        $headers
    );

    $contextOptions = [
        'http' => [
            'method' => $method,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'protocol_version' => 1.1,
            'header' => $headerText,
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
        $contextOptions['http']['content'] = $body;
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

    $errorMessage = null;

    set_error_handler(
        static function (
            int $severity,
            string $message
        ) use (&$errorMessage): bool {
            $errorMessage = $message;
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
                '外部サービスへ接続できませんでした。',
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
            (string)$line,
            $m
        )) {
            $status = (int)$m[1];
            continue;
        }

        if (str_contains((string)$line, ':')) {
            [$key, $value] =
                explode(':', (string)$line, 2);

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
                '外部サービスのレスポンスを取得できませんでした。',
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

    /*
     * 外部302/303を絶対に成功扱いしない。
     */
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

    /*
     * ポートやユーザー入力による任意URLを許可しない。
     */
    if (!preg_match(
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
    if ($username === '' || $password === '') {
        throw new InvalidArgumentException(
            'kintoneログイン名とパスワードを入力してください。'
        );
    }

    return base64_encode(
        $username . ':' . $password
    );
}

/*
 * kintone REST API共通通信関数。
 *
 * GET:
 *   URLにパラメータを入れる。
 *   Content-Typeは付けない。
 *
 * POST:
 *   JSON bodyを使用する場合だけ
 *   Content-Type: application/jsonを付ける。
 */
function kintoneRequest(
    array $settings,
    string $path,
    string $method,
    string $password
): array {
    $method = strtoupper($method);

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
     * 認証ヘッダーはサーバー側でのみ生成する。
     */
    $headers = [
        'X-Cybozu-Authorization: '
            . $authorization,
        'Accept: application/json',
        'User-Agent: SurveyPOC/4.0',
    ];

    /*
     * GETにはContent-Typeを付けない。
     *
     * これが今回のCB_IL02再発防止の核心。
     */
    if ($method !== 'GET'
        && $method !== 'HEAD') {
        $headers[] =
            'Content-Type: application/json';
    }

    try {
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
    } finally {
        unset($authorization);
    }

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

    $data['kintone']['connection'] =
        '未設定';

    $data['kintone']['connectionDetail'] =
        '';

    /*
     * パスワードは一切保存しない。
     */
    unset($data['kintone']['password']);

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

    /*
     * HTTPステータス + 本文の両方で成否を確定。
     */
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

    $body = json_decode(
        (string)$response['body'],
        true
    );

    if (!is_array($body)) {
        $data['kintone']['connection'] =
            '接続できません';

        $data['kintone']['connectionDetail'] =
            'kintoneから有効なJSONレスポンスを取得できませんでした。';

        saveData($data);

        throw new RuntimeException(
            'kintoneから有効なJSONレスポンスを取得できませんでした。'
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

    /*
     * パスワードは303へ絶対に引き継がない。
     */
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

    $rawGroups =
        postArray('groups');

    $groups = [];

    foreach ($rawGroups as $rawGroup) {
        if (!is_array($rawGroup)) {
            continue;
        }

        $group = [
            'id' => validateId(
                (string)($rawGroup['id'] ?? '')
            )
                ? (string)$rawGroup['id']
                : uid('g'),
            'title' => trim(
                (string)($rawGroup['title'] ?? '')
            ),
            'questions' => [],
        ];

        $rawQuestions =
            $rawGroup['questions'] ?? [];

        if (is_array($rawQuestions)) {
            foreach ($rawQuestions as $rawQuestion) {
                if (is_array($rawQuestion)) {
                    $group['questions'][] =
                        normalizeQuestion(
                            $rawQuestion
                        );
                }
            }
        }

        $groups[] = $group;
    }

    $survey = normalizeSurvey([
        'id' => $id !== ''
            ? $id
            : uid('survey'),
        'createdAt' => $existing['createdAt']
            ?? today(),
        'updatedAt' => today(),
        'title' => $title,
        'description' => postString(
            'description'
        ),
        'startAt' => $startAt,
        'endAt' => $endAt,
        'status' => $existing['status']
            ?? 'draft',
        'numbering' => $numbering,
        'groups' => $groups,
    ]);

    $index = surveyIndex(
        $data,
        $survey['id']
    );

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

function transitionSurveyAction(
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
            '対象アンケートが存在しません。'
        );
    }

    $survey =
        $data['surveys'][$index];

    $from =
        (string)(
            $survey['status'] ?? 'draft'
        );

    if (!canTransition($from, $to)) {
        throw new InvalidArgumentException(
            '指定された状態遷移は許可されていません。'
        );
    }

    $survey['status'] =
        $to;

    $survey['updatedAt'] =
        today();

    $data['surveys'][$index] =
        normalizeSurvey($survey);

    saveData($data);

    flash(
        'success',
        'アンケート状態を変更しました。'
    );

    redirectTo('list');
}

function deleteSurveyAction(
    array &$data
): void {
    $id =
        postString('id');

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
    $id =
        postString('id');

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

    $copy =
        normalizeSurvey([
            'id' => uid('survey'),
            'createdAt' => today(),
            'updatedAt' => today(),
            'title' =>
                $survey['title']
                . '（複製）',
            'description' =>
                $survey['description'],
            'startAt' =>
                $survey['startAt'],
            'endAt' =>
                $survey['endAt'],
            'status' => 'draft',
            'numbering' =>
                $survey['numbering'],
            'groups' =>
                $survey['groups'],
        ]);

    $data['surveys'][] =
        $copy;

    saveData($data);

    flash(
        'success',
        'アンケートを複製しました。'
    );

    redirectTo('list');
}

/* =========================================================
 * 回答
 * ========================================================= */

function saveAnswerAction(
    array &$data
): void {
    $id =
        postString('id');

    if (!validateId($id)) {
        throw new InvalidArgumentException(
            '回答対象アンケートIDが不正です。'
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

    $answers =
        postArray('answers');

    $errors =
        validateAnswers(
            $survey,
            $answers
        );

    if ($errors) {
        throw new InvalidArgumentException(
            implode(
                ' ',
                $errors
            )
        );
    }

    $_SESSION['answer_draft'] = [
        'surveyId' => $id,
        'answers' => $answers,
    ];

    redirectTo(
        'confirm',
        ['id' => $id]
    );
}

function confirmAnswerAction(
    array &$data
): void {
    $id =
        postString('id');

    if (!validateId($id)) {
        throw new InvalidArgumentException(
            '回答対象アンケートIDが不正です。'
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

    $draft =
        $_SESSION['answer_draft']
        ?? null;

    if (!is_array($draft)
        || ($draft['surveyId'] ?? '')
            !== $id
        || !is_array(
            $draft['answers'] ?? null
        )) {
        throw new RuntimeException(
            '回答データが確認できません。'
        );
    }

    $answers =
        $draft['answers'];

    $errors =
        validateAnswers(
            $survey,
            $answers
        );

    if ($errors) {
        throw new InvalidArgumentException(
            implode(
                ' ',
                $errors
            )
        );
    }

    $data['answers'][] = [
        'id' => uid('answer'),
        'surveyId' => $id,
        'createdAt' => now(),
        'answers' => $answers,
    ];

    saveData($data);

    unset(
        $_SESSION['answer_draft']
    );

    /*
     * 保存成功が確定した後だけ完了画面へ進む。
     */
    redirectTo(
        'complete',
        ['id' => $id]
    );
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
<title>'
        . h($title)
        . '</title>
<style>
*{box-sizing:border-box}
body{
 margin:0;
 background:#f5f7fa;
 color:#222;
 font-family:
  -apple-system,
  BlinkMacSystemFont,
  "Segoe UI",
  sans-serif;
 line-height:1.6;
}
header{
 background:#17365d;
 color:#fff;
 padding:16px 20px;
}
header .inner{
 max-width:1100px;
 margin:auto;
 display:flex;
 justify-content:space-between;
 gap:20px;
 align-items:center;
}
main{
 max-width:1100px;
 margin:24px auto;
 padding:0 16px 50px;
}
.nav{
 display:flex;
 flex-wrap:wrap;
 gap:10px;
 margin:0 auto;
 max-width:1100px;
 padding:0 16px 12px;
}
.nav a{
 color:#fff;
 text-decoration:none;
 background:#315985;
 padding:6px 12px;
 border-radius:6px;
}
.card{
 background:#fff;
 border:1px solid #dce1e7;
 border-radius:10px;
 padding:20px;
 margin-bottom:18px;
 box-shadow:0 1px 2px rgba(0,0,0,.04);
}
table{
 width:100%;
 border-collapse:collapse;
 background:#fff;
}
th,td{
 border-bottom:1px solid #e3e7eb;
 padding:10px;
 text-align:left;
 vertical-align:top;
}
th{
 background:#f1f4f7;
}
input,textarea,select{
 width:100%;
 padding:9px 10px;
 border:1px solid #bcc6d1;
 border-radius:6px;
 font:inherit;
 background:#fff;
}
textarea{
 min-height:100px;
 resize:vertical;
}
button,.btn{
 display:inline-block;
 border:0;
 border-radius:6px;
 padding:9px 14px;
 background:#2868a6;
 color:#fff;
 text-decoration:none;
 cursor:pointer;
 font:inherit;
}
button.secondary,.btn.secondary{
 background:#66788a;
}
button.danger,.btn.danger{
 background:#bd3b3b;
}
button.success,.btn.success{
 background:#2e7d4f;
}
.actions{
 display:flex;
 flex-wrap:wrap;
 gap:8px;
 margin-top:14px;
}
.alert{
 border-radius:8px;
 padding:12px 15px;
 margin-bottom:16px;
 background:#e9f2ff;
 border:1px solid #b9d3f5;
}
.alert.success{
 background:#e8f6ed;
 border-color:#b6dec4;
}
.alert.error{
 background:#fff0f0;
 border-color:#e4b4b4;
}
.muted{
 color:#6c7783;
}
.badge{
 display:inline-block;
 padding:3px 8px;
 border-radius:999px;
 background:#e7ebef;
 font-size:.9em;
}
.badge.published{
 background:#d9f1e2;
 color:#176436;
}
.badge.draft{
 background:#e7edf5;
}
.badge.stopped{
 background:#fff0d8;
 color:#805300;
}
.badge.ended{
 background:#eee;
 color:#555;
}
.grid{
 display:grid;
 grid-template-columns:
  repeat(2,minmax(0,1fr));
 gap:16px;
}
@media(max-width:700px){
 .grid{grid-template-columns:1fr}
 table{font-size:.9em}
 th,td{padding:7px}
}
.question{
 border:1px solid #d8dee5;
 border-radius:8px;
 padding:14px;
 margin:12px 0;
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
code{
 background:#f0f2f5;
 padding:2px 5px;
 border-radius:4px;
}
</style>
</head>
<body>
<header>
<div class="inner">
<strong>アンケートアプリ</strong>
<span>'
        . h($title)
        . '</span>
</div>
</header>'
        . $nav
        . '<main>';
}

function renderFooter(): string
{
    return '</main>
</body>
</html>';
}

/* =========================================================
 * List
 * ========================================================= */

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
<a class="btn success"
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

    $html .= '
<div class="card">
<form method="get">
<input type="hidden" name="screen" value="list">
<label>
タイトル検索
<input name="q"
 value="'
        . h(getString('q'))
        . '"
 placeholder="タイトルを入力してEnter">
</label>
</form>
</div>';

    $q =
        mb_strtolower(
            getString('q')
        );

    if ($q !== '') {
        $surveys =
            array_values(
                array_filter(
                    $surveys,
                    static fn(array $survey): bool =>
                        mb_strpos(
                            mb_strtolower(
                                (string)(
                                    $survey['title']
                                    ?? ''
                                )
                            ),
                            $q
                        ) !== false
                )
            );
    }

    if (!$surveys) {
        $html .= '
<div class="card">
<p>アンケートはありません。</p>
</div>';

        return $html;
    }

    $html .= '
<div class="card">
<table>
<thead>
<tr>
<th>タイトル</th>
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

        $answerCount = 0;

        foreach ($data['answers'] as $answer) {
            if (($answer['surveyId'] ?? '')
                === $id) {
                $answerCount++;
            }
        }

        $status =
            (string)(
                $survey['status']
                ?? 'draft'
            );

        $html .= '<tr>
<td>'
            . h($survey['title'])
            . '<br><small class="muted">'
            . h($survey['updatedAt'])
            . '</small></td>
<td>'
            . h($survey['startAt'] ?: '-')
            . ' ～ '
            . h($survey['endAt'] ?: '-')
            . '</td>
<td><span class="badge '
            . h($status)
            . '">'
            . h(match ($status) {
                'published' => '公開中',
                'stopped' => '停止',
                'ended' => '終了',
                default => '下書き',
            })
            . '</span></td>
<td>'
            . h($answerCount)
            . '</td>
<td>
<div class="actions">
<a class="btn"
 href="?screen=edit&id='
            . rawurlencode($id)
            . '">編集</a>
<a class="btn secondary"
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
            . '">送信</a>';

        if ($status !== 'ended') {
            if ($status === 'draft'
                || $status === 'stopped') {
                $html .= '
<form method="post" style="display:inline">
<input type="hidden"
 name="action"
 value="transitionSurvey">
<input type="hidden"
 name="id"
 value="'
                    . h($id)
                    . '">
<input type="hidden"
 name="to"
 value="published">
<button class="success"
 type="submit">
公開
</button>
</form>';
            } elseif ($status === 'published') {
                $html .= '
<form method="post" style="display:inline">
<input type="hidden"
 name="action"
 value="transitionSurvey">
<input type="hidden"
 name="id"
 value="'
                    . h($id)
                    . '">
<input type="hidden"
 name="to"
 value="stopped">
<button class="secondary"
 type="submit">
停止
</button>
</form>';
            }
        }

        $html .= '
<form method="post" style="display:inline">
<input type="hidden"
 name="action"
 value="duplicateSurvey">
<input type="hidden"
 name="id"
 value="'
            . h($id)
            . '">
<button type="submit">
複製
</button>
</form>

<form method="post"
 style="display:inline"
 onsubmit="return confirm(\'削除しますか？\')">
<input type="hidden"
 name="action"
 value="deleteSurvey">
<input type="hidden"
 name="id"
 value="'
            . h($id)
            . '">
<button class="danger"
 type="submit">
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

    return $html;
}

/* =========================================================
 * Edit
 * ========================================================= */

function renderEdit(
    array $data,
    ?array $survey
): string {
    $isNew =
        $survey === null;

    $survey =
        $survey ?? [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'numbering' => 'global',
            'groups' => [
                [
                    'id' => uid('g'),
                    'title' => '',
                    'questions' => [],
                ],
            ],
            'status' => 'draft',
        ];

    $html =
        '<h1>アンケート'
        . ($isNew ? '作成' : '編集')
        . '</h1>';

    $html .= '
<form method="post"
 id="surveyForm">
<input type="hidden"
 name="action"
 value="saveSurvey">
<input type="hidden"
 name="id"
 value="'
        . h($survey['id'])
        . '">

<div class="card">
<div class="grid">
<div>
<label>タイトル
<input name="title"
 required maxlength="200"
 value="'
        . h($survey['title'])
        . '">
</label>
</div>
<div>
<label>採番方式
<select name="numbering">
<option value="global"'
        . ($survey['numbering'] === 'global'
            ? ' selected' : '')
        . '>全体通番（Q1、Q2...）</option>
<option value="group"'
        . ($survey['numbering'] === 'group'
            ? ' selected' : '')
        . '>グループ単位（Q1-1、Q1-2...）</option>
</select>
</label>
</div>
<div>
<label>開始日時
<input type="datetime-local"
 name="startAt"
 value="'
        . h($survey['startAt'])
        . '">
</label>
</div>
<div>
<label>終了日時
<input type="datetime-local"
 name="endAt"
 value="'
        . h($survey['endAt'])
        . '">
</label>
</div>
</div>

<label>説明
<textarea name="description">'
        . h($survey['description'])
        . '</textarea>
</label>
</div>';

    $html .= '
<div id="groups">';

    foreach (
        $survey['groups']
        as $gi => $group
    ) {
        $html .= renderEditGroup(
            $group,
            $gi
        );
    }

    $html .= '
</div>

<div class="actions">
<button type="button"
 onclick="addGroup()">
グループを追加
</button>
<button type="submit"
 class="success">
保存して一覧へ
</button>
<a class="btn secondary"
 href="?screen=list"
 onclick="return confirm(\'編集内容を破棄しますか？\')">
キャンセル
</a>
</div>
</form>';

    $html .= '
<script>
function addGroup(){
 const groups=document.getElementById("groups");
 const gi=groups.children.length;
 groups.insertAdjacentHTML("beforeend",
  groupTemplate(gi));
 renumber();
}
function addQuestion(groupIndex){
 const box=document.querySelector(
  ".group[data-index=\\"" + groupIndex + "\\"] .questions");
 const qi=box.children.length;
 box.insertAdjacentHTML("beforeend",
  questionTemplate(groupIndex,qi));
 renumber();
}
function removeGroup(button){
 const groups=document.getElementById("groups");
 if(groups.children.length<=1){
  alert("グループは1つ以上必要です。");
  return;
 }
 button.closest(".group").remove();
 rebuildIndexes();
}
function removeQuestion(button){
 button.closest(".question").remove();
 rebuildIndexes();
}
function rebuildIndexes(){
 document.querySelectorAll(".group")
  .forEach((g,gi)=>{
   g.dataset.index=gi;
   g.querySelectorAll(
    "input,textarea,select"
   ).forEach(el=>{
    if(el.name){
     el.name=el.name
      .replace(/groups\\[\\d+\\]/,"groups["+gi+"]")
      .replace(/questions\\[\\d+\\]/,
               function(m){
                return m;
               });
    }
   });
  });
 renumber();
}
function renumber(){
 let global=1;
 document.querySelectorAll(".group")
  .forEach((g,gi)=>{
   g.querySelectorAll(".question")
    .forEach((q,qi)=>{
     const n=q.querySelector(".question-number");
     const numbering=document.querySelector(
      "select[name=numbering]"
     ).value;
     n.textContent=numbering==="group"
      ? "Q"+(gi+1)+"-"+(qi+1)
      : "Q"+global;
     global++;
    });
  });
}
function groupTemplate(gi){
 return `<div class="card group" data-index="${gi}">
<h2>グループ</h2>
<input type="hidden"
 name="groups[${gi}][id]"
 value="">
<label>グループタイトル
<input name="groups[${gi}][title]">
</label>
<div class="questions"></div>
<div class="actions">
<button type="button"
 onclick="addQuestion(${gi})">
質問を追加
</button>
<button type="button"
 class="danger"
 onclick="removeGroup(this)">
グループ削除
</button>
</div>
</div>`;
}
function questionTemplate(gi,qi){
 return `<div class="question">
<strong class="question-number">Q</strong>
<input type="hidden"
 name="groups[${gi}][questions][${qi}][id]"
 value="">
<label>質問文
<input name="groups[${gi}][questions][${qi}][text]">
</label>
<label>回答形式
<select name="groups[${gi}][questions][${qi}][type]">
<option value="single">単一選択</option>
<option value="multiple">複数選択</option>
<option value="free">自由記述</option>
</select>
</label>
<label>
<input type="checkbox"
 name="groups[${gi}][questions][${qi}][required]"
 value="1"
 style="width:auto">
必須
</label>
<label>選択肢
<textarea
 name="groups[${gi}][questions][${qi}][options][]"
 placeholder="1行に1選択肢"></textarea>
</label>
<button type="button"
 class="danger"
 onclick="removeQuestion(this)">
質問削除
</button>
</div>`;
}
document.querySelector(
 "select[name=numbering]"
)?.addEventListener(
 "change",renumber
);
renumber();
</script>';

    return $html;
}

function renderEditGroup(
    array $group,
    int $gi
): string {
    $html =
        '<div class="card group"
 data-index="'
        . h($gi)
        . '">
<h2>グループ '
        . h($gi + 1)
        . '</h2>
<input type="hidden"
 name="groups['
        . $gi
        . '][id]"
 value="'
        . h($group['id'])
        . '">
<label>グループタイトル
<input name="groups['
        . $gi
        . '][title]"
 value="'
        . h($group['title'])
        . '">
</label>
<div class="questions">';

    foreach (
        $group['questions']
        as $qi => $question
    ) {
        $html .= '
<div class="question">
<strong class="question-number">'
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

<label>質問文
<input name="groups['
            . $gi
            . '][questions]['
            . $qi
            . '][text]"
 value="'
            . h($question['text'])
            . '">
</label>

<label>回答形式
<select name="groups['
            . $gi
            . '][questions]['
            . $qi
            . '][type]">
<option value="single"'
            . ($question['type'] === 'single'
                ? ' selected' : '')
            . '>単一選択</option>
<option value="multiple"'
            . ($question['type'] === 'multiple'
                ? ' selected' : '')
            . '>複数選択</option>
<option value="free"'
            . ($question['type'] === 'free'
                ? ' selected' : '')
            . '>自由記述</option>
</select>
</label>

<label>
<input type="checkbox"
 name="groups['
            . $gi
            . '][questions]['
            . $qi
            . '][required]"
 value="1"
 style="width:auto"'
            . (!empty($question['required'])
                ? ' checked' : '')
            . '>
必須
</label>';

        if ($question['type'] !== 'free') {
            $html .= '
<label>選択肢
<textarea name="groups['
                . $gi
                . '][questions]['
                . $qi
                . '][options][]">'
                . h(
                    implode(
                        "\n",
                        $question['options']
                    )
                )
                . '</textarea>
</label>';
        }

        $html .= '
<button type="button"
 class="danger"
 onclick="removeQuestion(this)">
質問削除
</button>
</div>';
    }

    $html .= '
</div>
<div class="actions">
<button type="button"
 onclick="addQuestion('
        . $gi
        . ')">
質問を追加
</button>
<button type="button"
 class="danger"
 onclick="removeGroup(this)">
グループ削除
</button>
</div>
</div>';

    return $html;
}

/* =========================================================
 * Preview
 * ========================================================= */

function renderPreview(
    array $survey
): string {
    $html =
        '<h1>プレビュー</h1>';

    $html .= '
<div class="card">
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
        $survey['groups']
        as $group
    ) {
        $html .= '
<div class="card">
<h3>'
            . h($group['title'])
            . '</h3>';

        foreach (
            $group['questions']
            as $question
        ) {
            $html .= '
<div class="question">
<h4>'
                . h($question['number'])
                . ' '
                . h($question['text'])
                . ($question['required']
                    ? ' <span class="badge">必須</span>'
                    : '')
                . '</h4>';

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

            $html .= '</div>';
        }

        $html .= '</div>';
    }

    $html .= '
<div class="actions">
<a class="btn secondary"
 href="?screen=list">
一覧へ戻る
</a>
</div>';

    return $html;
}

/* =========================================================
 * kintone screen
 * ========================================================= */

function renderKintone(
    array $data
): string {
    $s =
        $data['kintone'];

    $html =
        '<h1>kintone設定</h1>';

    $html .= '
<div class="card">
<p>
設定保存、接続テスト、項目一覧再取得、
顧客情報同期は独立した操作です。
</p>
<p class="muted">
パスワードは各POST処理中だけ使用し、
保存・URL・HTML・JavaScriptには保持しません。
</p>
</div>';

    $html .= '
<div class="card">
<h2>設定保存</h2>
<form method="post">
<input type="hidden"
 name="action"
 value="saveKintone">

<div class="grid">
<div>
<label>サブドメイン
<input name="subdomain"
 value="'
        . h($s['subdomain'])
        . '"
 placeholder="example または example.cybozu.com"
 required>
</label>
</div>

<div>
<label>顧客管理アプリID
<input name="appId"
 inputmode="numeric"
 value="'
        . h($s['appId'])
        . '"
 required>
</label>
</div>

<div>
<label>ログイン名
<input name="username"
 value="'
        . h($s['username'])
        . '"
 required>
</label>
</div>

<div>
<label>Proxy
<input name="proxy"
 value="'
        . h($s['proxy'])
        . '"
 placeholder="host:port">
</label>
</div>
</div>

<label>
<input type="checkbox"
 name="sslVerify"
 value="1"
 style="width:auto"'
        . (!empty($s['sslVerify'])
            ? ' checked' : '')
        . '>
SSL証明書を検証する
</label>

<div class="actions">
<button class="success"
 type="submit">
設定保存
</button>
</div>
</form>
</div>';

    $html .= '
<div class="card">
<h2>接続テスト</h2>
<form method="post">
<input type="hidden"
 name="action"
 value="testKintone">

<label>kintoneパスワード
<input type="password"
 name="password"
 autocomplete="current-password"
 required>
</label>

<div class="actions">
<button type="submit">
接続テスト
</button>
</div>
</form>
</div>';

    $html .= '
<div class="card">
<h2>項目一覧再取得</h2>
<form method="post">
<input type="hidden"
 name="action"
 value="fetchKintoneFields">

<label>kintoneパスワード
<input type="password"
 name="password"
 autocomplete="current-password"
 required>
</label>

<div class="actions">
<button type="submit">
項目一覧再取得
</button>
</div>
</form>';

    $html .= '
</div>';

    $html .= '
<div class="card">
<h2>顧客情報同期</h2>
<form method="post">
<input type="hidden"
 name="action"
 value="syncKintone">

<label>kintoneパスワード
<input type="password"
 name="password"
 autocomplete="current-password"
 required>
</label>

<div class="actions">
<button type="submit">
顧客情報同期
</button>
</div>
</form>
</div>';

    $html .= '
<div class="card">
<h2>接続状態</h2>
<p><strong>'
        . h($s['connection'])
        . '</strong></p>';

    if (!empty($s['connectionDetail'])) {
        $html .= '
<p>'
            . h($s['connectionDetail'])
            . '</p>';
    }

    $html .= '
</div>';

    if (!empty($s['fields'])) {
        $html .= '
<div class="card">
<h2>kintone項目マッピング</h2>
<form method="post">
<input type="hidden"
 name="action"
 value="saveKintoneMapping">

<table>
<thead>
<tr>
<th>用途</th>
<th>項目</th>
</tr>
</thead>
<tbody>';

        $mapping =
            $s['mappings'];

        $definitions = [
            'org' =>
                '組織名',
            'name' =>
                '氏名',
            'email' =>
                'メールアドレス',
            'department' =>
                '部署名',
            'phone' =>
                '電話番号',
        ];

        foreach (
            $definitions
            as $key => $label
        ) {
            $html .= '<tr>
<td>'
                . h($label)
                . '</td>
<td>
<select name="mapping['
                . h($key)
                . ']">
<option value="">未指定</option>';

            foreach (
                $s['fields']
                as $code => $field
            ) {
                $selected =
                    ($mapping[$key] ?? '')
                    === $code
                        ? ' selected'
                        : '';

                $html .= '
<option value="'
                    . h($code)
                    . '"'
                    . $selected
                    . '>'
                    . h(
                        $field['label']
                        . ' [' . $code . ']'
                    )
                    . '</option>';
            }

            $html .= '
</select>
</td>
</tr>';
        }

        $html .= '
</tbody>
</table>

<div class="actions">
<button type="submit"
 class="success">
マッピング保存
</button>
</div>
</form>
</div>';
    }

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
        $data['kintone']['mappings'][$key] =
            isset($mapping[$key])
            && is_scalar($mapping[$key])
                ? trim((string)$mapping[$key])
                : '';
    }

    saveData($data);

    flash(
        'success',
        'kintone項目マッピングを保存しました。'
    );

    redirectTo('kintone');
}

/* =========================================================
 * Mail
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
 value="saveMail">

<div class="grid">
<div>
<label>SMTPサーバ
<input name="server"
 value="'
        . h($s['server'])
        . '">
</label>
</div>

<div>
<label>ポート
<input name="port"
 inputmode="numeric"
 value="'
        . h($s['port'])
        . '">
</label>
</div>

<div>
<label>暗号化
<select name="encryption">
<option value="none"'
        . ($s['encryption'] === 'none'
            ? ' selected' : '')
        . '>なし</option>
<option value="tls"'
        . ($s['encryption'] === 'tls'
            ? ' selected' : '')
        . '>TLS</option>
<option value="ssl"'
        . ($s['encryption'] === 'ssl'
            ? ' selected' : '')
        . '>SSL</option>
</select>
</label>
</div>

<div>
<label>SMTPユーザー名
<input name="username"
 value="'
        . h($s['username'])
        . '">
</label>
</div>

<div>
<label>送信元メール
<input name="fromEmail"
 type="email"
 value="'
        . h($s['fromEmail'])
        . '">
</label>
</div>

<div>
<label>送信元名
<input name="fromName"
 value="'
        . h($s['fromName'])
        . '">
</label>
</div>

<div>
<label>返信先
<input name="replyTo"
 type="email"
 value="'
        . h($s['replyTo'])
        . '">
</label>
</div>
</div>

<label>
<input type="checkbox"
 name="auth"
 value="1"
 style="width:auto"'
        . (!empty($s['auth'])
            ? ' checked' : '')
        . '>
SMTP認証を使用する
</label>

<div class="actions">
<button class="success"
 type="submit">
設定保存
</button>
</div>
</form>
</div>

<div class="card">
<h2>接続状態</h2>
<p>'
        . h($s['connection'])
        . '</p>
<p>'
        . h($s['connectionDetail'])
        . '</p>
<p class="muted">
SMTPパスワードは保存されません。
</p>
</div>';
}

function saveMailAction(
    array &$data
): void {
    $server =
        postString('server');

    $port =
        postString('port', '587');

    if ($server !== ''
        && !preg_match(
            '/^[A-Za-z0-9.-]+$/',
            $server
        )) {
        throw new InvalidArgumentException(
            'SMTPサーバ名が不正です。'
        );
    }

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
        ['none', 'tls', 'ssl'],
        true
    )) {
        throw new InvalidArgumentException(
            '暗号化方式が不正です。'
        );
    }

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

    $data['mailSettings']['server'] =
        $server;

    $data['mailSettings']['port'] =
        (int)$port;

    $data['mailSettings']['encryption'] =
        $encryption;

    $data['mailSettings']['auth'] =
        postString('auth') === '1';

    $data['mailSettings']['username'] =
        postString('username');

    $data['mailSettings']['fromEmail'] =
        $fromEmail;

    $data['mailSettings']['fromName'] =
        postString('fromName');

    $data['mailSettings']['replyTo'] =
        $replyTo;

    $data['mailSettings']['connection'] =
        '未設定';

    $data['mailSettings']['connectionDetail'] =
        '';

    unset(
        $data['mailSettings']['password']
    );

    saveData($data);

    flash(
        'success',
        'メール設定を保存しました。'
    );

    redirectTo('mail');
}

/* =========================================================
 * Answer screens
 * ========================================================= */

function renderAnswer(
    array $survey,
    array $answers = []
): string {
    $html =
        '<h1>'
        . h($survey['title'])
        . '</h1>';

    $html .= '
<form method="post">
<input type="hidden"
 name="action"
 value="saveAnswer">
<input type="hidden"
 name="id"
 value="'
        . h($survey['id'])
        . '">';

    foreach (
        $survey['groups']
        as $group
    ) {
        $html .= '
<div class="card">
<h2>'
            . h($group['title'])
            . '</h2>';

        foreach (
            $group['questions']
            as $question
        ) {
            $qid =
                $question['id'];

            $html .= '
<div class="question">
<h3>'
                . h($question['number'])
                . ' '
                . h($question['text'])
                . ($question['required']
                    ? ' <span class="badge">必須</span>'
                    : '')
                . '</h3>';

            if ($question['type'] === 'free') {
                $value =
                    is_scalar(
                        $answers[$qid]
                        ?? ''
                    )
                        ? (string)(
                            $answers[$qid]
                            ?? ''
                        )
                        : '';

                $html .= '
<textarea name="answers['
                    . h($qid)
                    . ']">'
                    . h($value)
                    . '</textarea>';
            } elseif (
                $question['type'] === 'single'
            ) {
                $selected =
                    (string)(
                        $answers[$qid]
                        ?? ''
                    );

                foreach (
                    $question['options']
                    as $option
                ) {
                    $html .= '
<div class="option">
<input type="radio"
 name="answers['
                        . h($qid)
                        . ']"
 value="'
                        . h($option)
                        . '"'
                        . ($selected === $option
                            ? ' checked'
                            : '')
                        . '>
<span>'
                        . h($option)
                        . '</span>
</div>';
                }
            } else {
                $selected =
                    is_array(
                        $answers[$qid]
                        ?? null
                    )
                        ? $answers[$qid]
                        : [];

                foreach (
                    $question['options']
                    as $option
                ) {
                    $html .= '
<div class="option">
<input type="checkbox"
 name="answers['
                        . h($qid)
                        . '][]"
 value="'
                        . h($option)
                        . '"'
                        . (
                            in_array(
                                $option,
                                $selected,
                                true
                            )
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

            $html .= '</div>';
        }

        $html .= '</div>';
    }

    $html .= '
<div class="actions">
<button class="success"
 type="submit">
回答を確認する
</button>
</div>
</form>';

    return $html;
}

function renderConfirm(
    array $survey
): string {
    $draft =
        $_SESSION['answer_draft']
        ?? [];

    $answers =
        is_array($draft['answers'] ?? null)
            ? $draft['answers']
            : [];

    $map =
        questionMap($survey);

    $html =
        '<h1>回答確認</h1>';

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
            $display =
                implode(
                    '、',
                    array_map(
                        'strval',
                        $value
                    )
                );
        } else {
            $display =
                (string)$value;
        }

        $html .= '
<div class="card">
<h3>'
            . h($question['number'])
            . ' '
            . h($question['text'])
            . '</h3>
<p>'
            . nl2br(
                h($display)
            )
            . '</p>
</div>';
    }

    $html .= '
<div class="actions">
<a class="btn secondary"
 href="?screen=answer&id='
        . rawurlencode(
            (string)$survey['id']
        )
        . '">
修正する
</a>

<form method="post">
<input type="hidden"
 name="action"
 value="confirmAnswer">
<input type="hidden"
 name="id"
 value="'
        . h($survey['id'])
        . '">
<button class="success"
 type="submit">
回答を送信する
</button>
</form>
</div>';

    return $html;
}

function renderComplete(
    array $survey
): string {
    return '<div class="card">
<h1>回答完了</h1>
<p>
「'
        . h($survey['title'])
        . '」への回答を受け付けました。
</p>
<p>
ご回答ありがとうございました。
</p>
</div>';
}

/* =========================================================
 * Analytics
 * ========================================================= */

function renderAnalytics(
    array $data,
    array $survey
): string {
    /*
     * 対象surveyは呼び出し側でIDを確定してから渡す。
     */
    $surveyId =
        (string)$survey['id'];

    $answers =
        array_values(
            array_filter(
                $data['answers'],
                static fn(array $answer): bool =>
                    (string)(
                        $answer['surveyId']
                        ?? ''
                    ) === $surveyId
            )
        );

    $html =
        '<h1>回答集計・分析</h1>';

    $html .= '
<div class="card">
<h2>'
        . h($survey['title'])
        . '</h2>
<p>回答数: '
        . h(count($answers))
        . '</p>';

    if (!$answers) {
        $html .= '
<p>現在、回答データはありません。</p>
</div>';

        return $html;
    }

    $html .= '
</div>';

    $questions =
        allQuestions($survey);

    foreach ($questions as $question) {
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
<h3>'
            . h($question['number'])
            . ' '
            . h($question['text'])
            . '</h3>';

        if ($question['type'] === 'free') {
            $html .= '<ul>';

            foreach ($answers as $answer) {
                $value =
                    $answer['answers'][
                        $question['id']
                    ] ?? '';

                if ((string)$value !== '') {
                    $html .= '<li>'
                        . h($value)
                        . '</li>';
                }
            }

            $html .= '</ul>';
        } else {
            $html .= '
<table>
<thead>
<tr>
<th>選択肢</th>
<th>回答数</th>
</tr>
</thead>
<tbody>';

            foreach ($counts as $option => $count) {
                $html .= '<tr>
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
</table>';
        }

        $html .= '
</div>';
    }

    return $html;
}

/* =========================================================
 * Send
 * ========================================================= */

function renderSend(
    array $data,
    array $survey
): string {
    $customers =
        $data['customers'];

    $html =
        '<h1>顧客選択・メール送信</h1>';

    $html .= '
<div class="card">
<p>対象アンケート:</p>
<strong>'
        . h($survey['title'])
        . '</strong>
<p class="muted">
対象アンケートIDはURLから固定されています。
この画面では別アンケートを選択できません。
</p>
</div>';

    $html .= '
<div class="card">
<h2>顧客一覧</h2>';

    if (!$customers) {
        $html .= '
<p>
同期済みの顧客情報がありません。
kintone設定から顧客情報を同期してください。
</p>';
    } else {
        $html .= '
<table>
<thead>
<tr>
<th>選択</th>
<th>組織名</th>
<th>氏名</th>
<th>メール</th>
<th>部署</th>
</tr>
</thead>
<tbody>';

        foreach ($customers as $customer) {
            $html .= '<tr>
<td>
<input type="checkbox"
 name="customers[]"
 value="'
                . h($customer['id'])
                . '"
 form="sendForm">
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
<td>'
                . h($customer['department'])
                . '</td>
</tr>';
        }

        $html .= '
</tbody>
</table>';
    }

    $html .= '
</div>

<div class="card">
<form method="post"
 id="sendForm">
<input type="hidden"
 name="action"
 value="sendMail">
<input type="hidden"
 name="surveyId"
 value="'
        . h($survey['id'])
        . '">

<label>メール件名
<input name="subject">
</label>

<label>メール本文
<textarea name="body"
 placeholder="{顧客名} と {アンケートURL} が使用できます。"></textarea>
</label>

<label>SMTPパスワード
<input type="password"
 name="password"
 autocomplete="current-password">
</label>

<div class="actions">
<button type="submit"
 class="success">
一括送信
</button>
</div>
</form>
</div>

<div class="card">
<h2>送信履歴</h2>';

    $history =
        array_reverse(
            $data['sendHistory']
        );

    foreach ($history as $item) {
        if (($item['surveyId'] ?? '')
            !== $survey['id']) {
            continue;
        }

        $html .= '<p>'
            . h($item['createdAt'] ?? '')
            . ' '
            . h($item['status'] ?? '')
            . ' '
            . h($item['email'] ?? '')
            . '</p>';
    }

    $html .= '
</div>';

    return $html;
}

/* =========================================================
 * Simple SMTP
 * ========================================================= */

function smtpRead(
    $socket,
    int $timeout = 20
): string {
    stream_set_timeout(
        $socket,
        $timeout
    );

    $result = '';

    while (!feof($socket)) {
        $line =
            fgets($socket, 515);

        if ($line === false) {
            break;
        }

        $result .= $line;

        if (preg_match(
            '/^\d{3} /',
            $line
        )) {
            break;
        }
    }

    return $result;
}

function smtpExpect(
    $socket,
    array $codes
): string {
    $response =
        smtpRead($socket);

    if ($response === ''
        || !preg_match(
            '/^(\d{3})/',
            $response,
            $m
        )) {
        throw new RuntimeException(
            'SMTPサーバーから応答を取得できませんでした。'
        );
    }

    $code =
        (int)$m[1];

    if (!in_array(
        $code,
        $codes,
        true
    )) {
        throw new RuntimeException(
            'SMTP通信に失敗しました。'
        );
    }

    return $response;
}

function smtpWrite(
    $socket,
    string $command
): void {
    $length =
        strlen($command);

    $offset = 0;

    while ($offset < $length) {
        $written =
            fwrite(
                $socket,
                substr(
                    $command,
                    $offset
                )
            );

        if ($written === false
            || $written === 0) {
            throw new RuntimeException(
                'SMTPサーバーへ送信できませんでした。'
            );
        }

        $offset += $written;
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
        return [
            'ok' => false,
            'category' => 'validation',
            'error' => '宛先メールアドレスが不正です。',
        ];
    }

    $server =
        (string)(
            $settings['server'] ?? ''
        );

    $port =
        (int)(
            $settings['port'] ?? 587
        );

    if ($server === ''
        || $port < 1
        || $port > 65535) {
        return [
            'ok' => false,
            'category' => 'configuration',
            'error' => 'SMTP設定が不正です。',
        ];
    }

    $encryption =
        (string)(
            $settings['encryption']
            ?? 'tls'
        );

    $transport =
        match ($encryption) {
            'ssl' => 'ssl://',
            default => 'tcp://',
        };

    $errno = 0;
    $errstr = '';

    $socket =
        @stream_socket_client(
            $transport
            . $server
            . ':'
            . $port,
            $errno,
            $errstr,
            20
        );

    if ($socket === false) {
        return [
            'ok' => false,
            'category' => 'connection_error',
            'error' => 'SMTPサーバーへ接続できませんでした。',
        ];
    }

    try {
        smtpExpect(
            $socket,
            [220]
        );

        smtpWrite(
            $socket,
            "EHLO localhost\r\n"
        );

        smtpExpect(
            $socket,
            [250]
        );

        if ($encryption === 'tls') {
            smtpWrite(
                $socket,
                "STARTTLS\r\n"
            );

            smtpExpect(
                $socket,
                [220]
            );

            if (!stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )) {
                throw new RuntimeException(
                    'SMTP TLS接続を確立できませんでした。'
                );
            }

            smtpWrite(
                $socket,
                "EHLO localhost\r\n"
            );

            smtpExpect(
                $socket,
                [250]
            );
        }

        if (!empty($settings['auth'])) {
            if (
                (string)(
                    $settings['username']
                    ?? ''
                ) === ''
                || $password === ''
            ) {
                throw new RuntimeException(
                    'SMTP認証情報を入力してください。'
                );
            }

            smtpWrite(
                $socket,
                "AUTH LOGIN\r\n"
            );

            smtpExpect(
                $socket,
                [334]
            );

            smtpWrite(
                $socket,
                base64_encode(
                    (string)$settings['username']
                )
                . "\r\n"
            );

            smtpExpect(
                $socket,
                [334]
            );

            smtpWrite(
                $socket,
                base64_encode(
                    $password
                )
                . "\r\n"
            );

            smtpExpect(
                $socket,
                [235]
            );
        }

        $from =
            (string)(
                $settings['fromEmail']
                ?? ''
            );

        if (!validEmail($from)) {
            throw new RuntimeException(
                '送信元メールアドレスが設定されていません。'
            );
        }

        smtpWrite(
            $socket,
            'MAIL FROM: <'
            . $from
            . ">\r\n"
        );

        smtpExpect(
            $socket,
            [250]
        );

        smtpWrite(
            $socket,
            'RCPT TO: <'
            . $to
            . ">\r\n"
        );

        smtpExpect(
            $socket,
            [250, 251]
        );

        smtpWrite(
            $socket,
            "DATA\r\n"
        );

        smtpExpect(
            $socket,
            [354]
        );

        $fromName =
            (string)(
                $settings['fromName']
                ?? ''
            );

        $fromHeader =
            $fromName !== ''
                ? '=?UTF-8?B?'
                    . base64_encode(
                        $fromName
                    )
                    . '?= <'
                    . $from
                    . '>'
                : $from;

        $subjectHeader =
            '=?UTF-8?B?'
            . base64_encode(
                $subject
            )
            . '?=';

        $mail =
            'From: '
            . $fromHeader
            . "\r\n"
            . 'To: <'
            . $to
            . ">\r\n"
            . 'Subject: '
            . $subjectHeader
            . "\r\n"
            . 'MIME-Version: 1.0'
            . "\r\n"
            . 'Content-Type: text/plain; charset=UTF-8'
            . "\r\n"
            . "\r\n"
            . str_replace(
                "\n.",
                "\n..",
                str_replace(
                    "\r\n",
                    "\n",
                    $body
                )
            )
            . "\r\n.\r\n";

        smtpWrite(
            $socket,
            $mail
        );

        smtpExpect(
            $socket,
            [250]
        );

        smtpWrite(
            $socket,
            "QUIT\r\n"
        );

        smtpExpect(
            $socket,
            [221]
        );

        fclose($socket);

        return [
            'ok' => true,
            'category' => 'success',
            'error' => '',
        ];
    } catch (Throwable $e) {
        fclose($socket);

        return [
            'ok' => false,
            'category' => 'smtp_error',
            'error' =>
                'SMTP通信に失敗しました。',
        ];
    }
}

/* =========================================================
 * Mail send
 * ========================================================= */

function sendMailAction(
    array &$data
): void {
    $surveyId =
        postString('surveyId');

    if (!validateId($surveyId)) {
        throw new InvalidArgumentException(
            '送信対象アンケートIDが不正です。'
        );
    }

    /*
     * 画面表示時だけではなく、
     * POSTデータ取得時にも対象IDからsurveyを再取得する。
     */
    $survey =
        surveyById(
            $data,
            $surveyId
        );

    if (!$survey) {
        throw new RuntimeException(
            '送信対象アンケートが存在しません。'
        );
    }

    $subject =
        postString('subject');

    $body =
        postString('body');

    $password =
        postString('password');

    if ($subject === ''
        || $body === '') {
        throw new InvalidArgumentException(
            'メール件名と本文を入力してください。'
        );
    }

    $selected =
        postArray('customers');

    $selectedIds = [];

    foreach ($selected as $id) {
        if (is_scalar($id)) {
            $selectedIds[] =
                (string)$id;
        }
    }

    $selectedIds =
        array_values(
            array_unique(
                $selectedIds
            )
        );

    if (!$selectedIds) {
        throw new InvalidArgumentException(
            '送信対象顧客を選択してください。'
        );
    }

    $baseUrl =
        (
            (!empty($_SERVER['HTTPS'])
                && strtolower(
                    (string)$_SERVER['HTTPS']
                ) !== 'off'
            )
                ? 'https'
                : 'http'
        )
        . '://'
        . (
            string)(
                $_SERVER['HTTP_HOST']
                ?? 'localhost'
            )
        )
        . (
            string)(
                $_SERVER['SCRIPT_NAME']
                ?? '/index.php'
            );

    foreach ($data['customers'] as $customer) {
        if (!in_array(
            (string)$customer['id'],
            $selectedIds,
            true
        )) {
            continue;
        }

        $to =
            (string)(
                $customer['email']
                ?? ''
            );

        if (!validEmail($to)) {
            $data['sendHistory'][] = [
                'id' => uid('send'),
                'surveyId' => $surveyId,
                'customerId' =>
                    $customer['id'],
                'email' => $to,
                'status' => '失敗',
                'createdAt' => now(),
            ];

            continue;
        }

        $personalBody =
            str_replace(
                [
                    '{顧客名}',
                    '{アンケートURL}',
                ],
                [
                    (string)(
                        $customer['name']
                        ?? ''
                    ),
                    $baseUrl
                    . '?screen=answer&id='
                    . rawurlencode(
                        $surveyId
                    ),
                ],
                $body
            );

        $result =
            smtpSend(
                $data['mailSettings'],
                $password,
                $to,
                $subject,
                $personalBody
            );

        /*
         * SMTP結果確定後にのみ履歴保存。
         */
        $data['sendHistory'][] = [
            'id' => uid('send'),
            'surveyId' => $surveyId,
            'customerId' =>
                $customer['id'],
            'email' => $to,
            'status' =>
                $result['ok']
                    ? '送信成功'
                    : '送信失敗',
            'createdAt' => now(),
        ];
    }

    unset($password);

    saveData($data);

    flash(
        'success',
        'メール送信処理が完了しました。'
    );

    /*
     * パスワードはURLへ渡さない。
     */
    redirectTo(
        'send',
        ['id' => $surveyId]
    );
}

/* =========================================================
 * POST dispatcher
 * ========================================================= */

function handlePost(
    array &$data
): void {
    $action =
        postString('action');

    if ($action === '') {
        throw new InvalidArgumentException(
            'POST処理が指定されていません。'
        );
    }

    switch ($action) {
        case 'saveSurvey':
            saveSurveyAction($data);
            return;

        case 'transitionSurvey':
            transitionSurveyAction($data);
            return;

        case 'deleteSurvey':
            deleteSurveyAction($data);
            return;

        case 'duplicateSurvey':
            duplicateSurveyAction($data);
            return;

        case 'saveKintone':
            saveKintoneAction($data);
            return;

        case 'testKintone':
            testKintoneAction($data);
            return;

        case 'fetchKintoneFields':
            fetchKintoneFieldsAction($data);
            return;

        case 'syncKintone':
            syncKintoneAction($data);
            return;

        case 'saveKintoneMapping':
            saveKintoneMappingAction($data);
            return;

        case 'saveMail':
            saveMailAction($data);
            return;

        case 'saveAnswer':
            saveAnswerAction($data);
            return;

        case 'confirmAnswer':
            confirmAnswerAction($data);
            return;

        case 'sendMail':
            sendMailAction($data);
            return;

        default:
            throw new InvalidArgumentException(
                '指定されたPOST処理は利用できません。'
            );
    }
}

/* =========================================================
 * Error
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
     * 秘密情報・内部情報が混入する可能性のある
     * 例外メッセージは利用者へそのまま出さない。
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

$data = [];

$screen =
    getString(
        'screen',
        'list'
    );

try {
    $data =
        readData();

    /*
     * 公開中 + 終了日時経過だけを自動終了。
     */
    updateAutomaticStatus(
        $data
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handlePost(
            $data
        );

        /*
         * 正常なPOST処理は各action側で
         * 結果確定後に303する。
         */
        throw new RuntimeException(
            'POST処理が予期せず終了しました。'
        );
    }

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

            /*
             * 画面表示時に対象IDを確定。
             */
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

            /*
             * データ取得時にも対象IDを確定。
             */
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

            $draft =
                $_SESSION['answer_draft']
                ?? [];

            $answers =
                is_array(
                    $draft['answers'] ?? null
                )
                    ? $draft['answers']
                    : [];

            echo renderAnswer(
                $survey,
                $answers
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
        safeErrorMessage(
            $e
        );

    /*
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

    if (
        ($screen ?? '')
        === 'mail'
    ) {
        echo '<a class="btn"
 href="?screen=mail">
メール設定へ戻る
</a>';
    }

    echo '</div>';

    echo renderFooter();
}

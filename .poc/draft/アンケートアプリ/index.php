<?php
declare(strict_types=1);

/*
 * アンケートアプリ POC
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし / PHP mail()なし
 * 単一 index.php
 *
 * kintone CB_IL02 修正版
 *
 * 重要:
 *   GET の kintone API に Content-Type: application/json を付けない。
 *   JSON body を送信するときだけ Content-Type を付与する。
 */

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const TZ = 'Asia/Tokyo';

date_default_timezone_set(TZ);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$scriptDir = str_replace(
    '\\',
    '/',
    dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))
);

$cookiePath = (
    $scriptDir === '.' || $scriptDir === ''
)
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

function jsonOut(mixed $value): string
{
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    return $json === false ? '' : $json;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function uid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(6));
}

function getString(
    string $name,
    string $default = ''
): string {
    return isset($_GET[$name]) && is_scalar($_GET[$name])
        ? trim((string)$_GET[$name])
        : $default;
}

function postString(
    string $name,
    string $default = ''
): string {
    return isset($_POST[$name]) && is_scalar($_POST[$name])
        ? trim((string)$_POST[$name])
        : $default;
}

function postArray(string $name): array
{
    return isset($_POST[$name]) && is_array($_POST[$name])
        ? $_POST[$name]
        : [];
}

function redirectTo(
    string $screen,
    array $params = []
): never {
    $params = array_merge(
        ['screen' => $screen],
        $params
    );

    $url =
        (string)($_SERVER['SCRIPT_NAME'] ?? 'index.php')
        . '?'
        . http_build_query(
            $params,
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

function flash(
    string $type,
    string $message,
    string $detail = ''
): void {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
        'detail' => $detail,
    ];
}

function takeFlash(): ?array
{
    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($value)
        ? $value
        : null;
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

function statusLabel(string $status): string
{
    return match ($status) {
        'draft' => '下書き',
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => $status,
    };
}

function statusClass(string $status): string
{
    return match ($status) {
        'draft' => 'draft',
        'published' => 'published',
        'stopped' => 'stopped',
        'ended' => 'ended',
        default => '',
    };
}

function typeLabel(string $type): string
{
    return match ($type) {
        'single' => '単一選択',
        'multiple' => '複数選択',
        'free' => '自由記述',
        default => $type,
    };
}

/* =========================================================
 * 永続化
 * ========================================================= */

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR)) {
        if (
            !mkdir(DATA_DIR, 0770, true)
            && !is_dir(DATA_DIR)
        ) {
            throw new RuntimeException(
                'データ保存ディレクトリを作成できません。'
            );
        }
    }

    $htaccess =
        DATA_DIR
        . DIRECTORY_SEPARATOR
        . '.htaccess';

    if (!is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "Options -Indexes\n"
            . "<FilesMatch \"\\.dat\\.php$\">\n"
            . "Require all denied\n"
            . "</FilesMatch>\n"
        );
    }
}

function dataPath(string $name): string
{
    if (!preg_match(
        '/^[A-Za-z0-9_-]+$/',
        $name
    )) {
        throw new InvalidArgumentException(
            '不正なデータ名です。'
        );
    }

    ensureDataDir();

    return DATA_DIR
        . DIRECTORY_SEPARATOR
        . $name
        . '.dat.php';
}

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
            'sslVerify' => true,
            'proxy' => '',
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
            'password' => '',
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
            'password' => '',
        ],
    ];
}

function readData(): array
{
    $path = dataPath('app');

    if (!is_file($path)) {
        return defaultData();
    }

    $fp = @fopen($path, 'rb');

    if ($fp === false) {
        throw new RuntimeException(
            'データファイルを開けません。'
        );
    }

    if (!flock($fp, LOCK_SH)) {
        fclose($fp);

        throw new RuntimeException(
            'データファイルをロックできません。'
        );
    }

    $contents = stream_get_contents($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    if ($contents === false || $contents === '') {
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

function saveApp(array $data): void
{
    $path = dataPath('app');

    $tmp =
        $path
        . '.'
        . bin2hex(random_bytes(4))
        . '.tmp';

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException(
            'データをJSON化できません。'
        );
    }

    $fp = @fopen($tmp, 'wb');

    if ($fp === false) {
        throw new RuntimeException(
            '一時データファイルを作成できません。'
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException(
                'データファイルをロックできません。'
            );
        }

        if (fwrite($fp, $json) === false) {
            throw new RuntimeException(
                'データを書き込めません。'
            );
        }

        fflush($fp);

        if (function_exists('fsync')) {
            @fsync($fp);
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException(
                'データファイルを更新できません。'
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
    $type = in_array(
        ($q['type'] ?? 'single'),
        ['single', 'multiple', 'free'],
        true
    )
        ? (string)$q['type']
        : 'single';

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

    return [
        'id' =>
            validateId((string)($q['id'] ?? ''))
            ? (string)$q['id']
            : uid('q'),

        'number' =>
            (string)($q['number'] ?? ''),

        'text' =>
            trim((string)($q['text'] ?? '')),

        'type' => $type,

        'required' =>
            !empty($q['required']),

        'options' =>
            array_values($options),

        'branches' =>
            is_array($q['branches'] ?? null)
            ? $q['branches']
            : [],
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
            'id' =>
                validateId((string)($group['id'] ?? ''))
                ? (string)$group['id']
                : uid('g'),

            'title' =>
                trim((string)($group['title'] ?? '')),

            'questions' =>
                $questions,
        ];
    }

    $survey = [
        'id' =>
            validateId((string)($survey['id'] ?? ''))
            ? (string)$survey['id']
            : uid('survey'),

        'createdAt' =>
            (string)($survey['createdAt'] ?? date('Y-m-d')),

        'updatedAt' =>
            (string)($survey['updatedAt'] ?? date('Y-m-d')),

        'title' =>
            trim((string)($survey['title'] ?? '')),

        'description' =>
            trim((string)($survey['description'] ?? '')),

        'startAt' =>
            (string)($survey['startAt'] ?? ''),

        'endAt' =>
            (string)($survey['endAt'] ?? ''),

        'status' =>
            in_array(
                ($survey['status'] ?? 'draft'),
                [
                    'draft',
                    'published',
                    'stopped',
                    'ended',
                ],
                true
            )
                ? (string)$survey['status']
                : 'draft',

        'numbering' =>
            ($survey['numbering'] ?? 'global') === 'group'
                ? 'group'
                : 'global',

        'groups' => $groups,
    ];

    renumberSurvey($survey);

    return $survey;
}

function renumberSurvey(array &$survey): void
{
    $global = 1;

    foreach ($survey['groups'] as $gi => &$group) {
        foreach (
            $group['questions']
            as $qi => &$question
        ) {
            $question['number'] =
                $survey['numbering'] === 'group'
                ? 'Q' . ($gi + 1) . '-' . ($qi + 1)
                : 'Q' . $global;

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
    $i = surveyIndex($data, $id);

    return $i >= 0
        ? $data['surveys'][$i]
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
        'ended' => false,
        default => false,
    };
}

function updateAutomaticStatus(
    array &$data
): void {
    $changed = false;
    $now = new DateTimeImmutable();

    foreach ($data['surveys'] as &$survey) {
        if (($survey['status'] ?? '') !== 'published') {
            continue;
        }

        if (empty($survey['endAt'])) {
            continue;
        }

        $end = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            (string)$survey['endAt']
        );

        if ($end !== false && $now > $end) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = date('Y-m-d');
            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {
        saveApp($data);
    }
}

function surveyAvailableForAnswer(
    array $survey
): bool {
    if (($survey['status'] ?? '') !== 'published') {
        return false;
    }

    $now = new DateTimeImmutable();

    if (!empty($survey['startAt'])) {
        $start = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            (string)$survey['startAt']
        );

        if ($start !== false && $now < $start) {
            return false;
        }
    }

    if (!empty($survey['endAt'])) {
        $end = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            (string)$survey['endAt']
        );

        if ($end !== false && $now > $end) {
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
    $visible = [];

    foreach ($questions as $question) {
        $show = true;

        foreach ($questions as $parent) {
            if (($parent['type'] ?? '') !== 'single') {
                continue;
            }

            $branches = $parent['branches'] ?? [];

            if (!is_array($branches)) {
                continue;
            }

            $answer =
                $answers[$parent['id']] ?? null;

            if (
                $answer === null
                || $answer === ''
            ) {
                continue;
            }

            $target =
                $branches[(string)$answer] ?? null;

            if (
                $target === null
                || $target === ''
            ) {
                continue;
            }

            if (
                $target === $question['id']
            ) {
                $show = true;
                break;
            }

            $targets = array_values($branches);

            if (
                in_array(
                    $question['id'],
                    $targets,
                    true
                )
            ) {
                $show = false;
            }
        }

        if ($show) {
            $visible[] = $question['id'];
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
    $visible =
        visibleQuestionIds(
            $survey,
            $answers
        );

    foreach ($visible as $questionId) {
        if (!isset($map[$questionId])) {
            continue;
        }

        $question = $map[$questionId];
        $value = $answers[$questionId] ?? '';

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

        if ($question['type'] === 'single') {
            if (
                !in_array(
                    (string)$value,
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
 * HTTP通信
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
    $method = strtoupper(trim($method));

    if (!in_array(
        $method,
        ['GET', 'POST', 'PUT', 'DELETE'],
        true
    )) {
        throw new InvalidArgumentException(
            'HTTPメソッドが不正です。'
        );
    }

    if (!preg_match(
        '#^https://#i',
        $url
    )) {
        throw new InvalidArgumentException(
            'HTTPS URLのみ許可されています。'
        );
    }

    $parts = parse_url($url);

    if (
        $parts === false
        || empty($parts['host'])
    ) {
        throw new InvalidArgumentException(
            '接続先URLが不正です。'
        );
    }

    $context = [
        'http' => [
            'method' => $method,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
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
        $context['http']['content'] = $body;
    }

    if (
        $proxy !== null
        && $proxy !== ''
    ) {
        if (!preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )) {
            throw new InvalidArgumentException(
                'Proxyはhost:port形式で指定してください。'
            );
        }

        $context['http']['proxy'] =
            'tcp://' . $proxy;

        $context['http']['request_fulluri'] = true;
    }

    $ctx =
        stream_context_create($context);

    $fp = @fopen(
        $url,
        'rb',
        false,
        $ctx
    );

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

    fclose($fp);

    if ($responseBody === false) {
        return [
            'ok' => false,
            'category' => 'response_error',
            'status' => 0,
            'body' => '',
            'headers' => [],
            'error' =>
                'レスポンスを取得できませんでした。',
        ];
    }

    $headersOut = [];
    $status = 0;

    foreach (
        ($meta['wrapper_data'] ?? [])
        as $line
    ) {
        if (
            preg_match(
                '#^HTTP/\S+\s+(\d{3})#i',
                $line,
                $m
            )
        ) {
            $status = (int)$m[1];
        } elseif (str_contains($line, ':')) {
            [$key, $value] =
                explode(':', $line, 2);

            $headersOut[
                strtolower(trim($key))
            ] = trim($value);
        }
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

    if (
        $status >= 300
        && $status < 400
    ) {
        return [
            'ok' => false,
            'category' => 'redirect',
            'status' => $status,
            'body' => $responseBody,
            'headers' => $headersOut,
            'error' =>
                '外部サービスからリダイレクト応答が返されました。',
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
            'HTTPエラーが返されました。',
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

    return str_ends_with(
        strtolower($input),
        '.cybozu.com'
    )
        ? $input
        : $input . '.cybozu.com';
}

function kintoneAuth(
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

/*
 * ★ CB_IL02の根本修正箇所
 *
 * GET:
 *   Content-Typeなし
 *
 * POST/PUT:
 *   JSON bodyがある場合だけ
 *   Content-Type: application/json
 */
function kintoneRequest(
    array $settings,
    string $path,
    string $method,
    string $password,
    ?array $payload = null
): array {
    $host = normalizeKintoneHost(
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

    $method =
        strtoupper(trim($method));

    if (!in_array(
        $method,
        ['GET', 'POST', 'PUT', 'DELETE'],
        true
    )) {
        throw new InvalidArgumentException(
            'kintone APIのHTTPメソッドが不正です。'
        );
    }

    $headers = [
        'X-Cybozu-Authorization: '
            . kintoneAuth(
                (string)(
                    $settings['username'] ?? ''
                ),
                $password
            ),

        'Accept: application/json',

        'User-Agent: SurveyPOC/1.0',
    ];

    $body = null;

    if ($payload !== null) {
        $body = jsonOut($payload);

        if ($body === '') {
            throw new RuntimeException(
                'kintone APIリクエストをJSON化できません。'
            );
        }

        $headers[] =
            'Content-Type: application/json';
    }

    return httpRequest(
        'https://' . $host . $path,
        $method,
        $headers,
        $body,
        20,
        !empty($settings['sslVerify']),
        (
            ($settings['proxy'] ?? '') !== ''
        )
            ? (string)$settings['proxy']
            : null
    );
}

function kintoneErrorMessage(
    array $response
): string {
    $body = json_decode(
        (string)($response['body'] ?? ''),
        true
    );

    if (is_array($body)) {
        $code =
            trim((string)(
                $body['code'] ?? ''
            ));

        $message =
            trim((string)(
                $body['message'] ?? ''
            ));

        if (
            $code !== ''
            && $message !== ''
        ) {
            return
                'kintoneエラー ['
                . $code
                . ']: '
                . $message;
        }

        if ($message !== '') {
            return
                'kintoneエラー: '
                . $message;
        }
    }

    return trim(
        (string)($response['error'] ?? '')
    ) ?: 'kintone APIでエラーが発生しました。';
}

function kintoneTest(
    array $settings,
    string $password
): array {
    return kintoneRequest(
        $settings,
        '/k/v1/app.json?id='
        . (int)$settings['appId'],
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
        . (int)$settings['appId'],
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
        . (int)$settings['appId']
        . '&totalCount=true',
        'GET',
        $password
    );
}

/* =========================================================
 * 秘密情報
 * ========================================================= */

function secretKey(): string
{
    return hash(
        'sha256',
        __FILE__ . '|' . PHP_VERSION,
        true
    );
}

function encryptSecret(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException(
            '秘密情報を暗号化するためのOpenSSLが利用できません。'
        );
    }

    $iv = random_bytes(12);
    $tag = '';

    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        secretKey(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cipher === false) {
        throw new RuntimeException(
            '秘密情報を暗号化できません。'
        );
    }

    return base64_encode(
        $iv . $tag . $cipher
    );
}

function decryptSecret(string $encoded): string
{
    if ($encoded === '') {
        return '';
    }

    $raw = base64_decode(
        $encoded,
        true
    );

    if (
        $raw === false
        || strlen($raw) < 29
    ) {
        throw new RuntimeException(
            '保存された秘密情報を復号できません。'
        );
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        secretKey(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($plain === false) {
        throw new RuntimeException(
            '保存された秘密情報を復号できません。'
        );
    }

    return $plain;
}

/* =========================================================
 * SMTP
 * ========================================================= */

function smtpRead(
    $fp,
    array $expected
): string {
    $line = '';

    while (!feof($fp)) {
        $part = fgets($fp, 515);

        if ($part === false) {
            break;
        }

        $line .= $part;

        if (
            strlen($part) >= 4
            && $part[3] === ' '
        ) {
            break;
        }
    }

    if (
        $line === ''
        || !preg_match(
            '/^(\d{3})/',
            $line,
            $m
        )
    ) {
        throw new RuntimeException(
            'SMTPレスポンスを取得できません。'
        );
    }

    $code = (int)$m[1];

    if (!in_array(
        $code,
        $expected,
        true
    )) {
        throw new RuntimeException(
            'SMTPエラーが返されました。'
        );
    }

    return $line;
}

function smtpWrite(
    $fp,
    string $command
): void {
    if (
        fwrite(
            $fp,
            $command . "\r\n"
        ) === false
    ) {
        throw new RuntimeException(
            'SMTP通信を書き込めません。'
        );
    }
}

function smtpConnect(
    array $settings,
    string $password
) {
    $server =
        trim((string)($settings['server'] ?? ''));

    $port =
        (int)($settings['port'] ?? 587);

    $encryption =
        (string)($settings['encryption'] ?? 'tls');

    if (
        $server === ''
        || $port < 1
        || $port > 65535
    ) {
        throw new InvalidArgumentException(
            'SMTP設定が不正です。'
        );
    }

    $prefix = match ($encryption) {
        'ssl' => 'ssl://',
        default => '',
    };

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $prefix . $server . ':' . $port,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT
    );

    if ($fp === false) {
        throw new RuntimeException(
            'SMTPサーバへ接続できません。'
        );
    }

    stream_set_timeout($fp, 20);

    smtpRead($fp, [220]);

    smtpWrite(
        $fp,
        'EHLO survey-poc'
    );

    smtpRead($fp, [250]);

    if ($encryption === 'tls') {
        smtpWrite(
            $fp,
            'STARTTLS'
        );

        smtpRead($fp, [220]);

        $ok = stream_socket_enable_crypto(
            $fp,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($ok !== true) {
            throw new RuntimeException(
                'SMTP TLS接続を確立できません。'
            );
        }

        smtpWrite(
            $fp,
            'EHLO survey-poc'
        );

        smtpRead($fp, [250]);
    }

    if (!empty($settings['auth'])) {
        $username =
            (string)($settings['username'] ?? '');

        if (
            $username === ''
            || $password === ''
        ) {
            throw new InvalidArgumentException(
                'SMTP認証情報を入力してください。'
            );
        }

        smtpWrite(
            $fp,
            'AUTH LOGIN'
        );

        smtpRead($fp, [334]);

        smtpWrite(
            $fp,
            base64_encode($username)
        );

        smtpRead($fp, [334]);

        smtpWrite(
            $fp,
            base64_encode($password)
        );

        smtpRead($fp, [235]);
    }

    return $fp;
}

function smtpSend(
    array $settings,
    string $password,
    string $to,
    string $subject,
    string $body
): void {
    if (!validEmail($to)) {
        throw new InvalidArgumentException(
            '宛先メールアドレスが不正です。'
        );
    }

    $from =
        trim((string)(
            $settings['fromEmail'] ?? ''
        ));

    if (!validEmail($from)) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    $fp = smtpConnect(
        $settings,
        $password
    );

    try {
        smtpWrite(
            $fp,
            'MAIL FROM:<' . $from . '>'
        );

        smtpRead($fp, [250]);

        smtpWrite(
            $fp,
            'RCPT TO:<' . $to . '>'
        );

        smtpRead($fp, [250, 251]);

        smtpWrite(
            $fp,
            'DATA'
        );

        smtpRead($fp, [354]);

        $headers = [];

        $headers[] =
            'From: '
            . $from;

        if (
            !empty($settings['fromName'])
        ) {
            $headers[] =
                'From: '
                . '=?UTF-8?B?'
                . base64_encode(
                    (string)$settings['fromName']
                )
                . '?= <'
                . $from
                . '>';
        }

        if (
            !empty($settings['replyTo'])
            && validEmail(
                (string)$settings['replyTo']
            )
        ) {
            $headers[] =
                'Reply-To: '
                . $settings['replyTo'];
        }

        $headers[] =
            'To: ' . $to;

        $headers[] =
            'Subject: =?UTF-8?B?'
            . base64_encode($subject)
            . '?=';

        $headers[] =
            'MIME-Version: 1.0';

        $headers[] =
            'Content-Type: text/plain; charset=UTF-8';

        $message =
            implode(
                "\r\n",
                $headers
            )
            . "\r\n\r\n"
            . $body
            . "\r\n.";

        smtpWrite(
            $fp,
            $message
        );

        smtpRead($fp, [250]);

        smtpWrite(
            $fp,
            'QUIT'
        );

        smtpRead(
            $fp,
            [221]
        );
    } finally {
        fclose($fp);
    }
}

/* =========================================================
 * POST処理
 * ========================================================= */

function createSurveyFromPost(): array
{
    $groups = [];

    foreach (
        postArray('groups')
        as $group
    ) {
        if (!is_array($group)) {
            continue;
        }

        $questions = [];

        foreach (
            ($group['questions'] ?? [])
            as $question
        ) {
            if (is_array($question)) {
                $questions[] =
                    normalizeQuestion($question);
            }
        }

        $groups[] = [
            'id' =>
                validateId(
                    (string)($group['id'] ?? '')
                )
                    ? (string)$group['id']
                    : uid('g'),

            'title' =>
                trim(
                    (string)(
                        $group['title'] ?? ''
                    )
                ),

            'questions' =>
                $questions,
        ];
    }

    return normalizeSurvey([
        'id' => postString('id'),
        'createdAt' =>
            postString(
                'createdAt',
                date('Y-m-d')
            ),
        'updatedAt' => date('Y-m-d'),
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
        'groups' => $groups,
    ]);
}

function processPost(
    array &$data
): ?string {
    $action = postString('action');

    if ($action === '') {
        return null;
    }

    switch ($action) {
        case 'save_survey':
            $survey =
                createSurveyFromPost();

            if (
                $survey['title'] === ''
            ) {
                throw new InvalidArgumentException(
                    'アンケートタイトルを入力してください。'
                );
            }

            if (
                !validDateTime(
                    $survey['startAt']
                )
                || !validDateTime(
                    $survey['endAt']
                )
            ) {
                throw new InvalidArgumentException(
                    'アンケート期間が不正です。'
                );
            }

            if (
                $survey['startAt'] !== ''
                && $survey['endAt'] !== ''
                && $survey['startAt']
                    > $survey['endAt']
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
                    $data['surveys'][$index]['createdAt'];

                $data['surveys'][$index] =
                    $survey;
            } else {
                $data['surveys'][] =
                    $survey;
            }

            saveApp($data);

            flash(
                'success',
                'アンケートを保存しました。'
            );

            redirectTo(
                'list'
            );

        case 'transition':
            $id = postString('id');
            $to = postString('to');

            $index =
                surveyIndex(
                    $data,
                    $id
                );

            if ($index < 0) {
                throw new InvalidArgumentException(
                    'アンケートが存在しません。'
                );
            }

            $from =
                (string)$data['surveys'][$index]['status'];

            if (!canTransition($from, $to)) {
                throw new InvalidArgumentException(
                    '許可されていない状態遷移です。'
                );
            }

            $data['surveys'][$index]['status'] =
                $to;

            $data['surveys'][$index]['updatedAt'] =
                date('Y-m-d');

            saveApp($data);

            flash(
                'success',
                'ステータスを変更しました。'
            );

            redirectTo('list');

        case 'duplicate':
            $id = postString('id');

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if (!$survey) {
                throw new InvalidArgumentException(
                    'アンケートが存在しません。'
                );
            }

            $survey['id'] =
                uid('survey');

            $survey['title'] .= '（複製）';
            $survey['status'] = 'draft';
            $survey['createdAt'] =
                date('Y-m-d');
            $survey['updatedAt'] =
                date('Y-m-d');

            foreach (
                $survey['groups']
                as &$group
            ) {
                $group['id'] = uid('g');

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

            saveApp($data);

            flash(
                'success',
                'アンケートを複製しました。'
            );

            redirectTo('list');

        case 'delete':
            $id = postString('id');

            $index =
                surveyIndex(
                    $data,
                    $id
                );

            if ($index < 0) {
                throw new InvalidArgumentException(
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

            saveApp($data);

            flash(
                'success',
                'アンケートを削除しました。'
            );

            redirectTo('list');

        case 'answer_confirm':
            $id =
                postString('surveyId');

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if (
                !$survey
                || !surveyAvailableForAnswer(
                    $survey
                )
            ) {
                throw new InvalidArgumentException(
                    '現在回答できるアンケートではありません。'
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
                $_SESSION['answer_errors'] =
                    $errors;

                $_SESSION['answer_draft'] =
                    $answers;

                redirectTo(
                    'answer',
                    ['id' => $id]
                );
            }

            $_SESSION['answer_survey'] =
                $id;

            $_SESSION['answer_draft'] =
                $answers;

            $_SESSION['answer_customer'] =
                postString('customerId');

            redirectTo(
                'confirm',
                ['id' => $id]
            );

        case 'answer_submit':
            $id =
                postString('surveyId');

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if (
                !$survey
                || !surveyAvailableForAnswer(
                    $survey
                )
            ) {
                throw new InvalidArgumentException(
                    '現在回答できるアンケートではありません。'
                );
            }

            if (
                ($_SESSION['answer_survey'] ?? '')
                !== $id
            ) {
                throw new InvalidArgumentException(
                    '回答途中データが存在しません。'
                );
            }

            $answers =
                $_SESSION['answer_draft']
                ?? [];

            $errors =
                validateAnswers(
                    $survey,
                    $answers
                );

            if ($errors) {
                throw new InvalidArgumentException(
                    implode("\n", $errors)
                );
            }

            $customerId =
                (string)(
                    $_SESSION['answer_customer']
                    ?? ''
                );

            $customer =
                null;

            foreach (
                $data['customers']
                as $candidate
            ) {
                if (
                    ($candidate['id'] ?? '')
                    === $customerId
                ) {
                    $customer =
                        $candidate;

                    break;
                }
            }

            $data['answers'][$id][] = [
                'id' => uid('answer'),
                'customerId' =>
                    $customerId,
                'customer' =>
                    $customer['name']
                    ?? '未登録回答者',
                'org' =>
                    $customer['org']
                    ?? '',
                'date' => now(),
                'values' =>
                    $answers,
            ];

            if ($customer) {
                foreach (
                    $data['customers']
                    as &$candidate
                ) {
                    if (
                        ($candidate['id'] ?? '')
                        === $customerId
                    ) {
                        $candidate['answerStatus'] =
                            'answered';

                        break;
                    }
                }

                unset($candidate);
            }

            saveApp($data);

            unset(
                $_SESSION['answer_survey'],
                $_SESSION['answer_draft'],
                $_SESSION['answer_customer'],
                $_SESSION['answer_errors']
            );

            redirectTo(
                'complete',
                ['id' => $id]
            );

        case 'save_kintone':
            $data['kintone']['subdomain'] =
                postString('subdomain');

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

            $data['kintone']['appId'] =
                $appId;

            $data['kintone']['username'] =
                postString('username');

            $data['kintone']['sslVerify'] =
                postString('sslVerify', '1')
                === '1';

            $data['kintone']['proxy'] =
                postString('proxy');

            $password =
                postString('password');

            if ($password !== '') {
                $data['kintone']['password'] =
                    encryptSecret(
                        $password
                    );
            }

            saveApp($data);

            flash(
                'success',
                'kintone設定を保存しました。'
            );

            redirectTo('kintone');

        case 'test_kintone':
            $settings =
                $data['kintone'];

            $password =
                postString('password');

            if ($password === '') {
                $password =
                    decryptSecret(
                        (string)(
                            $settings['password']
                            ?? ''
                        )
                    );
            }

            $response =
                kintoneTest(
                    $settings,
                    $password
                );

            if (!$response['ok']) {
                $data['kintone']['connection'] =
                    '接続できません';

                $data['kintone']['connectionDetail'] =
                    kintoneErrorMessage(
                        $response
                    );

                saveApp($data);

                flash(
                    'error',
                    $data['kintone']['connectionDetail']
                );

                redirectTo('kintone');
            }

            $data['kintone']['connection'] =
                '接続確認済み';

            $data['kintone']['connectionDetail'] =
                'HTTP '
                . $response['status'];

            if ($password !== '') {
                $data['kintone']['password'] =
                    encryptSecret(
                        $password
                    );
            }

            saveApp($data);

            flash(
                'success',
                'kintone接続を確認しました。'
            );

            redirectTo('kintone');

        case 'fetch_kintone_fields':
            $settings =
                $data['kintone'];

            $password =
                postString('password');

            if ($password === '') {
                $password =
                    decryptSecret(
                        (string)(
                            $settings['password']
                            ?? ''
                        )
                    );
            }

            $response =
                kintoneFields(
                    $settings,
                    $password
                );

            if (!$response['ok']) {
                throw new RuntimeException(
                    kintoneErrorMessage(
                        $response
                    )
                );
            }

            $json =
                json_decode(
                    $response['body'],
                    true
                );

            if (
                !is_array($json)
                || !is_array(
                    $json['properties'] ?? null
                )
            ) {
                throw new RuntimeException(
                    'kintone項目情報を取得できません。'
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
                            $field['label'] ?? ''
                        ),
                    'type' =>
                        (string)(
                            $field['type'] ?? ''
                        ),
                ];
            }

            $data['kintone']['fields'] =
                $fields;

            saveApp($data);

            flash(
                'success',
                'kintone項目一覧を取得しました。'
            );

            redirectTo('kintone');

        case 'sync_kintone':
            $settings =
                $data['kintone'];

            $password =
                postString('password');

            if ($password === '') {
                $password =
                    decryptSecret(
                        (string)(
                            $settings['password']
                            ?? ''
                        )
                    );
            }

            $response =
                kintoneRecords(
                    $settings,
                    $password
                );

            if (!$response['ok']) {
                throw new RuntimeException(
                    kintoneErrorMessage(
                        $response
                    )
                );
            }

            $json =
                json_decode(
                    $response['body'],
                    true
                );

            if (
                !is_array($json)
                || !is_array(
                    $json['records'] ?? null
                )
            ) {
                throw new RuntimeException(
                    'kintoneレコード情報を取得できません。'
                );
            }

            $mapping =
                $data['kintone']['mappings'];

            $customers = [];

            foreach (
                $json['records']
                as $record
            ) {
                if (!is_array($record)) {
                    continue;
                }

                $get = static function (
                    string $code
                ) use ($record): string {
                    if (
                        $code === ''
                        || !isset(
                            $record[$code]
                        )
                    ) {
                        return '';
                    }

                    $value =
                        $record[$code]['value']
                        ?? '';

                    if (is_array($value)) {
                        return implode(
                            ' ',
                            array_map(
                                'strval',
                                $value
                            )
                        );
                    }

                    return (string)$value;
                };

                $customers[] = [
                    'id' =>
                        uid('customer'),

                    'org' =>
                        $get(
                            (string)(
                                $mapping['org']
                                ?? ''
                            )
                        ),

                    'name' =>
                        $get(
                            (string)(
                                $mapping['name']
                                ?? ''
                            )
                        ),

                    'email' =>
                        $get(
                            (string)(
                                $mapping['email']
                                ?? ''
                            )
                        ),

                    'department' =>
                        $get(
                            (string)(
                                $mapping['department']
                                ?? ''
                            )
                        ),

                    'phone' =>
                        $get(
                            (string)(
                                $mapping['phone']
                                ?? ''
                            )
                        ),

                    'address' =>
                        $get(
                            (string)(
                                ($mapping['address'][0]
                                ?? '')
                            )
                        ),

                    'answerStatus' =>
                        'unanswered',
                ];
            }

            $data['customers'] =
                $customers;

            saveApp($data);

            flash(
                'success',
                count($customers)
                . '件の顧客を同期しました。'
            );

            redirectTo('kintone');

        case 'save_mail':
            $mail =
                &$data['mailSettings'];

            $mail['server'] =
                postString('server');

            $mail['port'] =
                max(
                    1,
                    min(
                        65535,
                        (int)postString(
                            'port',
                            '587'
                        )
                    )
                );

            $mail['encryption'] =
                in_array(
                    postString(
                        'encryption',
                        'tls'
                    ),
                    ['ssl', 'tls', 'none'],
                    true
                )
                    ? postString(
                        'encryption',
                        'tls'
                    )
                    : 'tls';

            $mail['auth'] =
                postString(
                    'auth',
                    '1'
                ) === '1';

            $mail['username'] =
                postString('username');

            $mail['fromEmail'] =
                postString('fromEmail');

            $mail['fromName'] =
                postString('fromName');

            $mail['replyTo'] =
                postString('replyTo');

            $password =
                postString('password');

            if ($password !== '') {
                $mail['password'] =
                    encryptSecret(
                        $password
                    );
            }

            saveApp($data);

            flash(
                'success',
                'メール設定を保存しました。'
            );

            redirectTo('mail');

        case 'test_mail':
            $mail =
                $data['mailSettings'];

            $password =
                postString('password');

            if ($password === '') {
                $password =
                    decryptSecret(
                        (string)(
                            $mail['password']
                            ?? ''
                        )
                    );
            }

            $fp =
                smtpConnect(
                    $mail,
                    $password
                );

            fclose($fp);

            $data['mailSettings']['connection'] =
                '接続確認済み';

            $data['mailSettings']['connectionDetail'] =
                'SMTP認証まで確認済み';

            saveApp($data);

            flash(
                'success',
                'SMTP接続・認証を確認しました。'
            );

            redirectTo('mail');

        case 'send_test_mail':
            $mail =
                $data['mailSettings'];

            $password =
                postString('password');

            if ($password === '') {
                $password =
                    decryptSecret(
                        (string)(
                            $mail['password']
                            ?? ''
                        )
                    );
            }

            $to =
                postString('to');

            smtpSend(
                $mail,
                $password,
                $to,
                'アンケートアプリ テストメール',
                'SMTPテストメールです。'
            );

            flash(
                'success',
                'テストメールを送信しました。'
            );

            redirectTo('mail');

        case 'send_survey':
            $surveyId =
                postString('surveyId');

            $survey =
                surveyById(
                    $data,
                    $surveyId
                );

            if (!$survey) {
                throw new InvalidArgumentException(
                    '対象アンケートが存在しません。'
                );
            }

            $mail =
                $data['mailSettings'];

            $password =
                postString('password');

            if ($password === '') {
                $password =
                    decryptSecret(
                        (string)(
                            $mail['password']
                            ?? ''
                        )
                    );
            }

            $selected =
                postArray('customers');

            if (!$selected) {
                throw new InvalidArgumentException(
                    '送信対象を選択してください。'
                );
            }

            $sent = 0;

            foreach (
                $selected as $customerId
            ) {
                foreach (
                    $data['customers']
                    as $customer
                ) {
                    if (
                        ($customer['id'] ?? '')
                        !== (string)$customerId
                    ) {
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

                    $url =
                        answerUrl(
                            $surveyId,
                            (string)$customer['id']
                        );

                    $body =
                        $survey['title']
                        . "\n\n"
                        . "以下のURLからアンケートへ回答してください。\n"
                        . $url
                        . "\n";

                    smtpSend(
                        $mail,
                        $password,
                        $email,
                        $survey['title'],
                        $body
                    );

                    $data['sendHistory'][] = [
                        'id' => uid('send'),
                        'surveyId' =>
                            $surveyId,
                        'customerId' =>
                            $customer['id'],
                        'date' => now(),
                    ];

                    $sent++;
                }
            }

            saveApp($data);

            flash(
                'success',
                $sent
                . '件のメールを送信しました。'
            );

            redirectTo(
                'send',
                ['id' => $surveyId]
            );

        default:
            throw new InvalidArgumentException(
                '不正な操作です。'
            );
    }

    return null;
}

/* =========================================================
 * URL
 * ========================================================= */

function answerUrl(
    string $surveyId,
    ?string $customerId = null
): string {
    if (!validateId($surveyId)) {
        throw new InvalidArgumentException(
            'アンケートIDが不正です。'
        );
    }

    $scheme =
        !empty($_SERVER['HTTPS'])
        && strtolower(
            (string)$_SERVER['HTTPS']
        ) !== 'off'
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

    $url =
        $scheme
        . '://'
        . $host
        . $script
        . '?screen=answer&id='
        . rawurlencode($surveyId);

    if (
        $customerId !== null
        && validateId($customerId)
    ) {
        $url .=
            '&customer='
            . rawurlencode($customerId);
    }

    return $url;
}

/* =========================================================
 * 画面
 * ========================================================= */

function layout(
    string $title,
    string $body,
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
:root{
 --primary:#2563eb;
 --border:#dbe2ea;
 --muted:#64748b;
 --bg:#f5f7fb;
 --danger:#b91c1c;
 --success:#166534;
}
*{box-sizing:border-box}
body{
 margin:0;
 background:var(--bg);
 color:#172033;
 font-family:
   -apple-system,
   BlinkMacSystemFont,
   "Segoe UI",
   sans-serif;
}
header{
 background:#fff;
 border-bottom:1px solid var(--border);
}
.header-inner{
 max-width:1200px;
 margin:auto;
 padding:18px 20px;
 display:flex;
 justify-content:space-between;
 align-items:center;
 gap:20px;
}
.container{
 max-width:1200px;
 margin:auto;
 padding:24px 20px 60px;
}
.nav{
 display:flex;
 gap:8px;
 flex-wrap:wrap;
}
.nav a{
 color:#334155;
 text-decoration:none;
 padding:8px 10px;
 border-radius:7px;
}
.nav a:hover{background:#eef2ff}
h1{margin-top:0}
.card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:12px;
 padding:20px;
 margin-bottom:16px;
 box-shadow:0 2px 8px rgba(15,23,42,.04);
}
.grid2{
 display:grid;
 grid-template-columns:
   repeat(2,minmax(0,1fr));
 gap:16px;
}
.field{margin-bottom:14px}
.field label{
 display:block;
 font-weight:600;
 margin-bottom:6px;
}
input,textarea,select{
 width:100%;
 padding:10px 11px;
 border:1px solid #cbd5e1;
 border-radius:8px;
 background:#fff;
 font:inherit;
}
textarea{min-height:120px}
button,.btn{
 display:inline-block;
 border:1px solid #cbd5e1;
 background:#fff;
 color:#172033;
 padding:9px 13px;
 border-radius:8px;
 text-decoration:none;
 cursor:pointer;
}
button:hover,.btn:hover{background:#f8fafc}
.primary{
 background:var(--primary);
 border-color:var(--primary);
 color:#fff;
}
.danger{
 color:#fff;
 background:#dc2626;
 border-color:#dc2626;
}
.actions{
 display:flex;
 gap:8px;
 flex-wrap:wrap;
 align-items:center;
}
.alert{
 padding:13px 15px;
 border-radius:8px;
 margin-bottom:16px;
 white-space:pre-line;
}
.alert.success{
 background:#dcfce7;
 color:var(--success);
}
.alert.error{
 background:#fee2e2;
 color:var(--danger);
}
.table-wrap{
 overflow-x:auto;
}
table{
 width:100%;
 border-collapse:collapse;
 min-width:760px;
}
th,td{
 text-align:left;
 padding:10px;
 border-bottom:1px solid var(--border);
 vertical-align:top;
}
.badge{
 display:inline-block;
 padding:3px 8px;
 border-radius:999px;
 background:#e2e8f0;
 font-size:12px;
}
.badge.published{
 background:#dcfce7;
 color:#166534;
}
.badge.draft{
 background:#e0e7ff;
 color:#3730a3;
}
.badge.stopped{
 background:#fef3c7;
 color:#92400e;
}
.badge.ended{
 background:#fee2e2;
 color:#991b1b;
}
.group,.question{
 border:1px solid var(--border);
 border-radius:10px;
 padding:16px;
 margin-bottom:14px;
 background:#fafbfe;
}
.question{background:#fff}
.question-head{
 display:flex;
 justify-content:space-between;
 align-items:center;
 gap:10px;
 margin-bottom:12px;
}
.option-row{
 display:grid;
 grid-template-columns:1fr auto;
 gap:8px;
 margin-bottom:8px;
}
.answer-option{
 display:flex;
 align-items:center;
 gap:8px;
 padding:10px;
 border:1px solid var(--border);
 border-radius:8px;
 margin-bottom:8px;
 background:#fff;
}
.answer-option input{width:auto}
.stats{
 display:grid;
 grid-template-columns:
   repeat(4,minmax(0,1fr));
 gap:12px;
}
.stat{
 border:1px solid var(--border);
 border-radius:10px;
 padding:15px;
 background:#fff;
}
.stat strong{
 display:block;
 font-size:24px;
}
@media(max-width:800px){
 .grid2{grid-template-columns:1fr}
 .stats{grid-template-columns:
   repeat(2,minmax(0,1fr))}
 .header-inner{
   flex-direction:column;
   align-items:flex-start;
 }
}
@media(max-width:520px){
 .container{padding:16px 12px 40px}
 .stats{grid-template-columns:1fr}
 button,.btn{min-height:42px}
}
</style>
</head>
<body>
<header>
<div class="header-inner">
<strong>アンケートアプリ</strong>
' . $nav . '
</div>
</header>
<main class="container">
' . $body . '
</main>
</body>
</html>';
}

function renderList(
    array $data
): string {
    $q =
        getString('q');

    $status =
        getString('status', 'all');

    $sort =
        getString('sort', 'updated_desc');

    $surveys =
        $data['surveys'];

    $surveys = array_values(
        array_filter(
            $surveys,
            static function (
                array $survey
            ) use ($q, $status): bool {
                if (
                    $q !== ''
                    && mb_stripos(
                        (string)$survey['title'],
                        $q
                    ) === false
                ) {
                    return false;
                }

                if (
                    $status !== 'all'
                    && ($survey['status'] ?? '')
                    !== $status
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
            $field =
                match ($sort) {
                    'answers_desc',
                    'answers_asc' => 'answers',
                    'start_desc',
                    'start_asc' => 'startAt',
                    default => 'updatedAt',
                };

            if ($field === 'answers') {
                $av =
                    count(
                        $GLOBALS['__APP_DATA']['answers']
                        [$a['id']] ?? []
                    );

                $bv =
                    count(
                        $GLOBALS['__APP_DATA']['answers']
                        [$b['id']] ?? []
                    );
            } else {
                $av =
                    (string)($a[$field] ?? '');

                $bv =
                    (string)($b[$field] ?? '');
            }

            $result =
                $av <=> $bv;

            return str_ends_with(
                $sort,
                '_desc'
            )
                ? -$result
                : $result;
        }
    );

    ob_start();
    ?>
<h1>アンケート一覧</h1>

<div class="card">
<form method="get">
<input type="hidden" name="screen" value="list">
<div class="grid2">
<div class="field">
<label>タイトル検索</label>
<input name="q"
       value="<?=h($q)?>"
       placeholder="タイトル">
</div>

<div class="field">
<label>ステータス</label>
<select name="status">
<?php foreach([
 'all'=>'すべて',
 'published'=>'公開中',
 'draft'=>'下書き',
 'stopped'=>'停止',
 'ended'=>'終了'
] as $k=>$v): ?>
<option value="<?=h($k)?>"
 <?= $status===$k?'selected':'' ?>>
 <?=h($v)?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="field">
<label>ソート</label>
<select name="sort">
<?php foreach([
 'updated_desc'=>'更新日：新しい順',
 'updated_asc'=>'更新日：古い順',
 'answers_desc'=>'回答数：多い順',
 'answers_asc'=>'回答数：少ない順',
 'start_desc'=>'開始日：新しい順',
 'start_asc'=>'開始日：古い順'
] as $k=>$v): ?>
<option value="<?=h($k)?>"
 <?= $sort===$k?'selected':'' ?>>
 <?=h($v)?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>

<div class="actions">
<button class="primary">検索</button>
<a class="btn"
   href="?screen=edit">新規作成</a>
</div>
</form>
</div>

<div class="card">
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
<?php foreach($surveys as $survey): ?>
<?php
$count=count(
 $data['answers'][$survey['id']]??[]
);
?>
<tr>
<td><?=h($survey['title'])?></td>
<td><?=h($survey['createdAt'])?></td>
<td><?=h($survey['updatedAt'])?></td>
<td>
<?=h($survey['startAt'])?>
～
<?=h($survey['endAt'])?>
</td>
<td>
<span class="badge <?=h(
 statusClass($survey['status'])
)?>">
<?=h(statusLabel($survey['status']))?>
</span>
</td>
<td><?=h($count)?></td>
<td>
<div class="actions">
<a class="btn"
 href="?screen=edit&id=<?=rawurlencode($survey['id'])?>">
確認・編集
</a>
<a class="btn"
 href="?screen=preview&id=<?=rawurlencode($survey['id'])?>">
プレビュー
</a>
<a class="btn"
 href="?screen=analytics&id=<?=rawurlencode($survey['id'])?>">
集計
</a>
<a class="btn"
 href="?screen=send&id=<?=rawurlencode($survey['id'])?>">
送信
</a>

<?php if($survey['status']==='draft'): ?>
<form method="post">
<input type="hidden"
 name="action"
 value="transition">
<input type="hidden"
 name="id"
 value="<?=h($survey['id'])?>">
<input type="hidden"
 name="to"
 value="published">
<button>公開</button>
</form>
<?php elseif($survey['status']==='published'): ?>
<form method="post">
<input type="hidden"
 name="action"
 value="transition">
<input type="hidden"
 name="id"
 value="<?=h($survey['id'])?>">
<input type="hidden"
 name="to"
 value="stopped">
<button>停止</button>
</form>
<?php elseif($survey['status']==='stopped'): ?>
<form method="post">
<input type="hidden"
 name="action"
 value="transition">
<input type="hidden"
 name="id"
 value="<?=h($survey['id'])?>">
<input type="hidden"
 name="to"
 value="published">
<button>再公開</button>
</form>
<?php endif; ?>

<form method="post">
<input type="hidden"
 name="action"
 value="duplicate">
<input type="hidden"
 name="id"
 value="<?=h($survey['id'])?>">
<button>複製</button>
</form>

<form method="post"
 onsubmit="return confirm('削除しますか？');">
<input type="hidden"
 name="action"
 value="delete">
<input type="hidden"
 name="id"
 value="<?=h($survey['id'])?>">
<button class="danger">削除</button>
</form>
</div>
</td>
</tr>
<?php endforeach; ?>
<?php if(!$surveys): ?>
<tr>
<td colspan="7">
該当するアンケートはありません。
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
<?php
    return (string)ob_get_clean();
}

function renderEdit(
    array $data,
    ?array $survey
): string {
    if (!$survey) {
        $survey = normalizeSurvey([
            'id' => uid('survey'),
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [[
                'id' => uid('g'),
                'title' => '',
                'questions' => [[
                    'id' => uid('q'),
                    'text' => '',
                    'type' => 'single',
                    'required' => false,
                    'options' => [''],
                    'branches' => [],
                ]],
            ]],
        ]);
    }

    ob_start();
    ?>
<h1>アンケート作成・編集</h1>

<form method="post"
      id="surveyForm">
<input type="hidden"
 name="action"
 value="save_survey">
<input type="hidden"
 name="id"
 value="<?=h($survey['id'])?>">
<input type="hidden"
 name="createdAt"
 value="<?=h($survey['createdAt'])?>">
<input type="hidden"
 name="status"
 value="<?=h($survey['status'])?>">

<div class="card">
<div class="grid2">

<div class="field">
<label>タイトル</label>
<input name="title"
       value="<?=h($survey['title'])?>"
       required>
</div>

<div class="field">
<label>採番方式</label>
<select name="numbering"
        onchange="reindex()">
<option value="global"
 <?= $survey['numbering']==='global'
 ?'selected':'' ?>>
全体通番
</option>
<option value="group"
 <?= $survey['numbering']==='group'
 ?'selected':'' ?>>
グループ単位
</option>
</select>
</div>

<div class="field">
<label>開始日時</label>
<input type="datetime-local"
 name="startAt"
 value="<?=h($survey['startAt'])?>">
</div>

<div class="field">
<label>終了日時</label>
<input type="datetime-local"
 name="endAt"
 value="<?=h($survey['endAt'])?>">
</div>
</div>

<div class="field">
<label>説明</label>
<textarea name="description"><?=h(
$survey['description']
)?></textarea>
</div>
</div>

<div id="groups">
<?php foreach($survey['groups'] as $gi=>$group): ?>
<div class="group"
     data-group>
<div class="question-head">
<h2>グループ <?=($gi+1)?></h2>
<button type="button"
 onclick="removeGroup(this)">
グループを削除
</button>
</div>

<input type="hidden"
 name="groups[<?=$gi?>][id]"
 value="<?=h($group['id'])?>">

<div class="field">
<label>グループタイトル</label>
<input name="groups[<?=$gi?>][title]"
 value="<?=h($group['title'])?>">
</div>

<div class="questions">
<?php foreach($group['questions'] as $qi=>$question): ?>
<div class="question"
     data-question>

<div class="question-head">
<strong class="qnumber">
<?=h($question['number'])?>
</strong>

<button type="button"
 onclick="removeQuestion(this)">
質問を削除
</button>
</div>

<input type="hidden"
 name="groups[<?=$gi?>][questions][<?=$qi?>][id]"
 value="<?=h($question['id'])?>">

<div class="field">
<label>質問文</label>
<input
 name="groups[<?=$gi?>][questions][<?=$qi?>][text]"
 value="<?=h($question['text'])?>">
</div>

<div class="grid2">
<div class="field">
<label>回答形式</label>
<select
 name="groups[<?=$gi?>][questions][<?=$qi?>][type]"
 onchange="toggleOptions(this)">
<option value="single"
 <?= $question['type']==='single'
 ?'selected':'' ?>>
単一選択
</option>
<option value="multiple"
 <?= $question['type']==='multiple'
 ?'selected':'' ?>>
複数選択
</option>
<option value="free"
 <?= $question['type']==='free'
 ?'selected':'' ?>>
自由記述
</option>
</select>
</div>

<div class="field">
<label>
<input type="checkbox"
 name="groups[<?=$gi?>][questions][<?=$qi?>][required]"
 value="1"
 <?= !empty($question['required'])
 ?'checked':'' ?>>
必須
</label>
</div>
</div>

<div class="options">
<?php foreach($question['options'] as $oi=>$option): ?>
<div class="option-row">
<input
 name="groups[<?=$gi?>][questions][<?=$qi?>][options][<?=$oi?>]"
 value="<?=h($option)?>">
<button type="button"
 onclick="this.parentNode.remove()">
削除
</button>
</div>
<?php endforeach; ?>
<button type="button"
 onclick="addOption(this)">
＋ 選択肢
</button>
</div>

</div>
<?php endforeach; ?>
</div>

<button type="button"
 onclick="addQuestion(this)">
＋ 質問を追加
</button>
</div>
<?php endforeach; ?>
</div>

<div class="card">
<div class="actions">
<button type="button"
 onclick="addGroup()">
＋ グループを追加
</button>

<button class="primary">
保存
</button>

<a class="btn"
 href="?screen=list">
戻る
</a>
</div>
</div>
</form>

<script>
function reindex(){
 const numbering =
  document.querySelector(
   '[name="numbering"]'
  ).value;

 document.querySelectorAll(
  '[data-group]'
 ).forEach((g,gi)=>{
  g.querySelector('h2').textContent =
   'グループ '+(gi+1);

  g.querySelectorAll(
   '[data-question]'
  ).forEach((q,qi)=>{
   q.querySelector('.qnumber')
    .textContent =
    numbering==='group'
    ? 'Q'+(gi+1)+'-'+(qi+1)
    : 'Q'+(
      [...document.querySelectorAll(
       '[data-question]'
      )].indexOf(q)+1
    );
  });
 });
}

function addOption(btn){
 const q=btn.closest('[data-question]');
 const inputs=q.querySelectorAll(
  '.options input'
 );
 const input=document.createElement('input');

 input.name=inputs.length
  ? inputs[0].name.replace(
    /\[\d+\]$/,
    '['+inputs.length+']'
   )
  : '';

 const row=document.createElement('div');
 row.className='option-row';

 row.innerHTML=
  '<input name="'+input.name+'" value="">'
  +'<button type="button"'
  +' onclick="this.parentNode.remove()">'
  +'削除</button>';

 btn.parentNode.insertBefore(
  row,
  btn
 );
}

function addQuestion(btn){
 const group=btn.closest('[data-group]');
 const gi=[
  ...document.querySelectorAll('[data-group]')
 ].indexOf(group);

 const questions=
  group.querySelector('.questions');

 const qi=
  questions.querySelectorAll(
   '[data-question]'
  ).length;

 const box=
  document.createElement('div');

 box.className='question';
 box.setAttribute(
  'data-question',
  ''
 );

 box.innerHTML=`
<div class="question-head">
<strong class="qnumber"></strong>
<button type="button"
 onclick="removeQuestion(this)">
質問を削除
</button>
</div>

<input type="hidden"
 name="groups[${gi}][questions][${qi}][id]"
 value="">

<div class="field">
<label>質問文</label>
<input
 name="groups[${gi}][questions][${qi}][text]"
 value="">
</div>

<div class="grid2">
<div class="field">
<label>回答形式</label>
<select
 name="groups[${gi}][questions][${qi}][type]"
 onchange="toggleOptions(this)">
<option value="single">単一選択</option>
<option value="multiple">複数選択</option>
<option value="free">自由記述</option>
</select>
</div>

<div class="field">
<label>
<input type="checkbox"
 name="groups[${gi}][questions][${qi}][required]"
 value="1">
必須
</label>
</div>
</div>

<div class="options">
<div class="option-row">
<input
 name="groups[${gi}][questions][${qi}][options][0]"
 value="">
<button type="button"
 onclick="this.parentNode.remove()">
削除
</button>
</div>

<button type="button"
 onclick="addOption(this)">
＋ 選択肢
</button>
</div>`;

 questions.appendChild(box);
 reindex();
}

function addGroup(){
 const groups=
  document.getElementById('groups');

 const gi=
  groups.querySelectorAll(
   '[data-group]'
  ).length;

 const box=
  document.createElement('div');

 box.className='group';
 box.setAttribute(
  'data-group',
  ''
 );

 box.innerHTML=`
<div class="question-head">
<h2>グループ</h2>
<button type="button"
 onclick="removeGroup(this)">
グループを削除
</button>
</div>

<input type="hidden"
 name="groups[${gi}][id]"
 value="">

<div class="field">
<label>グループタイトル</label>
<input
 name="groups[${gi}][title]"
 value="">
</div>

<div class="questions"></div>

<button type="button"
 onclick="addQuestion(this)">
＋ 質問を追加
</button>`;

 groups.appendChild(box);

 addQuestion(
  box.querySelector(
   'button:last-child'
  )
 );

 reindex();
}

function removeGroup(btn){
 const group=
  btn.closest('[data-group]');

 if(
  document.querySelectorAll(
   '[data-group]'
  ).length<=1
 ){
  alert(
   '最低1グループ必要です。'
  );
  return;
 }

 group.remove();
 reindex();
}

function removeQuestion(btn){
 const q=
  btn.closest('[data-question]');

 const group=
  q.closest('[data-group]');

 if(
  group.querySelectorAll(
   '[data-question]'
  ).length<=1
 ){
  alert(
   '最低1質問必要です。'
  );
  return;
 }

 q.remove();
 reindex();
}

function toggleOptions(select){
 const q=
  select.closest('[data-question]');

 const options=
  q.querySelector('.options');

 options.style.display =
  select.value==='free'
  ? 'none'
  : '';
}

reindex();

document.querySelectorAll(
 '[data-question] select'
).forEach(toggleOptions);
</script>
<?php
    return (string)ob_get_clean();
}

function renderPreview(
    array $survey
): string {
    ob_start();
    ?>
<h1>プレビュー</h1>

<div class="card">
<h2><?=h($survey['title'])?></h2>

<?php if($survey['description']!==''): ?>
<p><?=nl2br(
 h($survey['description'])
)?></p>
<?php endif; ?>

<?php foreach($survey['groups'] as $group): ?>
<div class="group">
<h2><?=h($group['title'])?></h2>

<?php foreach($group['questions'] as $question): ?>
<div class="question">
<div class="question-head">
<strong>
<?=h($question['number'])?>
</strong>
<?php if($question['required']): ?>
<span class="badge">必須</span>
<?php endif; ?>
</div>

<p><?=h($question['text'])?></p>

<?php if($question['type']==='single'): ?>
<?php foreach($question['options'] as $option): ?>
<div class="answer-option">
<input type="radio" disabled>
<span><?=h($option)?></span>
</div>
<?php endforeach; ?>

<?php elseif($question['type']==='multiple'): ?>
<?php foreach($question['options'] as $option): ?>
<div class="answer-option">
<input type="checkbox" disabled>
<span><?=h($option)?></span>
</div>
<?php endforeach; ?>

<?php else: ?>
<textarea disabled></textarea>
<?php endif; ?>

</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
</div>

<div class="actions">
<a class="btn"
 href="?screen=edit&id=<?=rawurlencode($survey['id'])?>">
編集
</a>
<a class="btn"
 href="?screen=list">
一覧
</a>
</div>
<?php
    return (string)ob_get_clean();
}

function renderAnswer(
    array $data,
    array $survey
): string {
    $draft =
        $_SESSION['answer_draft']
        ?? [];

    $errors =
        $_SESSION['answer_errors']
        ?? [];

    unset(
        $_SESSION['answer_errors']
    );

    $customerId =
        getString('customer');

    $visible =
        visibleQuestionIds(
            $survey,
            $draft
        );

    ob_start();
    ?>
<h1><?=h($survey['title'])?></h1>

<div class="card">
<?php if($survey['description']!==''): ?>
<p><?=nl2br(
 h($survey['description'])
)?></p>
<?php endif; ?>

<?php if($errors): ?>
<div class="alert error">
<?php foreach($errors as $error): ?>
<div><?=h($error)?></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post">
<input type="hidden"
 name="action"
 value="answer_confirm">
<input type="hidden"
 name="surveyId"
 value="<?=h($survey['id'])?>">
<input type="hidden"
 name="customerId"
 value="<?=h($customerId)?>">

<?php foreach($survey['groups'] as $group): ?>
<div class="group">
<h2><?=h($group['title'])?></h2>

<?php foreach($group['questions'] as $question): ?>
<?php if(!in_array(
 $question['id'],
 $visible,
 true
)) continue; ?>

<div class="question">
<div class="question-head">
<strong>
<?=h($question['number'])?>
</strong>
<?php if($question['required']): ?>
<span class="badge">必須</span>
<?php endif; ?>
</div>

<p><?=h($question['text'])?></p>

<?php
$value =
 $draft[$question['id']]
 ?? '';
?>

<?php if($question['type']==='single'): ?>

<?php foreach($question['options'] as $option): ?>
<label class="answer-option">
<input type="radio"
 name="answers[<?=h($question['id'])?>]"
 value="<?=h($option)?>"
 <?= (string)$value===$option
 ?'checked':'' ?>>
<?=h($option)?>
</label>
<?php endforeach; ?>

<?php elseif($question['type']==='multiple'): ?>

<?php
$values =
 is_array($value)
 ? $value
 : [];
?>

<?php foreach($question['options'] as $option): ?>
<label class="answer-option">
<input type="checkbox"
 name="answers[<?=h($question['id'])?>][]"
 value="<?=h($option)?>"
 <?=in_array(
  $option,
  $values,
  true
 )?'checked':''?>>
<?=h($option)?>
</label>
<?php endforeach; ?>

<?php else: ?>

<textarea
 name="answers[<?=h($question['id'])?>]"
 ><?=h($value)?></textarea>

<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<button class="primary">
回答を確認
</button>
</form>
</div>
<?php
    return (string)ob_get_clean();
}

function renderConfirm(
    array $survey
): string {
    $answers =
        $_SESSION['answer_draft']
        ?? [];

    $map =
        questionMap($survey);

    ob_start();
    ?>
<h1>回答確認</h1>

<div class="card">
<?php foreach($answers as $qid=>$value): ?>
<?php
$q =
 $map[$qid] ?? null;

if(!$q) continue;
?>
<div class="question">
<div class="question-head">
<strong>
<?=h($q['number'])?>
</strong>
</div>

<p><?=h($q['text'])?></p>

<p>
<?=h(
 is_array($value)
 ? implode('、',$value)
 : (string)$value
)?>
</p>
</div>
<?php endforeach; ?>

<div class="actions">
<a class="btn"
 href="?screen=answer&id=<?=rawurlencode($survey['id'])?>">
戻って修正
</a>

<form method="post">
<input type="hidden"
 name="action"
 value="answer_submit">
<input type="hidden"
 name="surveyId"
 value="<?=h($survey['id'])?>">
<button class="primary">
回答を送信
</button>
</form>
</div>
</div>
<?php
    return (string)ob_get_clean();
}

function renderAnalytics(
    array $data,
    array $survey
): string {
    $answers =
        $data['answers'][$survey['id']]
        ?? [];

    ob_start();
    ?>
<h1>回答集計・分析</h1>

<div class="card">
<div class="stats">
<div class="stat">
回答数
<strong><?=count($answers)?></strong>
</div>
<div class="stat">
質問数
<strong><?=count(
 allQuestions($survey)
)?></strong>
</div>
</div>
</div>

<?php foreach(
 allQuestions($survey)
 as $question
): ?>

<?php
$countMap=[];

foreach(
 $question['options']
 as $option
){
 $countMap[$option]=0;
}

foreach(
 $answers
 as $answer
){
 $value =
  $answer['values']
  [$question['id']]
  ?? null;

 if(is_array($value)){
  foreach($value as $item){
   if(isset($countMap[$item])){
    $countMap[$item]++;
   }
  }
 }elseif(
  isset($countMap[(string)$value])
 ){
  $countMap[(string)$value]++;
 }
}
?>

<div class="card">
<h2>
<?=h($question['number'])?>
<?=h($question['text'])?>
</h2>

<?php if(
 $question['type']==='free'
): ?>

<?php foreach($answers as $answer): ?>
<?php
$value =
 $answer['values']
 [$question['id']]
 ?? '';
?>
<?php if((string)$value!==''): ?>
<div class="question">
<?=nl2br(h((string)$value))?>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php else: ?>

<table>
<thead>
<tr>
<th>選択肢</th>
<th>回答数</th>
</tr>
</thead>
<tbody>
<?php foreach(
 $countMap
 as $option=>$count
): ?>
<tr>
<td><?=h($option)?></td>
<td><?=h($count)?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php endif; ?>
</div>

<?php endforeach; ?>

<div class="card">
<a class="btn"
 href="?screen=list">
一覧へ戻る
</a>
</div>
<?php
    return (string)ob_get_clean();
}

function renderSend(
    array $data,
    array $survey
): string {
    ob_start();
    ?>
<h1>顧客選択・メール送信</h1>

<div class="card">
<form method="post">
<input type="hidden"
 name="action"
 value="send_survey">
<input type="hidden"
 name="surveyId"
 value="<?=h($survey['id'])?>">

<div class="field">
<label>SMTPパスワード</label>
<input type="password"
 name="password"
 autocomplete="new-password">
</div>

<div class="table-wrap">
<table>
<thead>
<tr>
<th></th>
<th>組織</th>
<th>氏名</th>
<th>メール</th>
<th>回答状態</th>
</tr>
</thead>
<tbody>
<?php foreach(
 $data['customers']
 as $customer
): ?>
<tr>
<td>
<input type="checkbox"
 name="customers[]"
 value="<?=h($customer['id'])?>">
</td>
<td><?=h($customer['org'])?></td>
<td><?=h($customer['name'])?></td>
<td><?=h($customer['email'])?></td>
<td><?=h(
 ($customer['answerStatus'] ?? '')
 === 'answered'
 ? '回答済み'
 : '未回答'
)?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<br>

<button class="primary">
メール送信
</button>
</form>
</div>
<?php
    return (string)ob_get_clean();
}

function renderKintone(
    array $data
): string {
    $settings =
        $data['kintone'];

    ob_start();
    ?>
<h1>kintone設定</h1>

<div class="card">
<form method="post">
<input type="hidden"
 name="action"
 value="save_kintone">

<div class="grid2">
<div class="field">
<label>サブドメイン</label>
<input name="subdomain"
 value="<?=h(
  $settings['subdomain']
 )?>"
 placeholder="example">
</div>

<div class="field">
<label>顧客管理アプリID</label>
<input name="appId"
 value="<?=h(
  $settings['appId']
 )?>">
</div>

<div class="field">
<label>ログイン名</label>
<input name="username"
 value="<?=h(
  $settings['username']
 )?>">
</div>

<div class="field">
<label>パスワード</label>
<input type="password"
 name="password"
 autocomplete="new-password">
</div>

<div class="field">
<label>SSL検証</label>
<select name="sslVerify">
<option value="1"
 <?=!empty(
  $settings['sslVerify']
 )?'selected':''?>>
有効
</option>
<option value="0"
 <?=empty(
  $settings['sslVerify']
 )?'selected':''?>>
無効
</option>
</select>
</div>

<div class="field">
<label>Proxy</label>
<input name="proxy"
 value="<?=h(
  $settings['proxy']
 )?>"
 placeholder="host:port">
</div>
</div>

<button class="primary">
設定保存
</button>
</form>
</div>

<div class="card">
<p>
接続状態：
<strong>
<?=h(
 $settings['connection']
)?>
</strong>
</p>

<?php if(
 $settings['connectionDetail']!==''
): ?>
<p><?=h(
 $settings['connectionDetail']
)?></p>
<?php endif; ?>

<form method="post"
      class="actions">
<input type="hidden"
 name="action"
 value="test_kintone">

<div>
<input type="password"
 name="password"
 placeholder="パスワード"
 autocomplete="new-password">
</div>

<button>
接続テスト
</button>
</form>

<form method="post"
      class="actions">
<input type="hidden"
 name="action"
 value="fetch_kintone_fields">

<div>
<input type="password"
 name="password"
 placeholder="パスワード"
 autocomplete="new-password">
</div>

<button>
項目取得
</button>
</form>

<form method="post"
      class="actions">
<input type="hidden"
 name="action"
 value="sync_kintone">

<div>
<input type="password"
 name="password"
 placeholder="パスワード"
 autocomplete="new-password">
</div>

<button>
顧客同期
</button>
</form>
</div>

<div class="card">
<h2>kintone項目</h2>

<?php if(
 !$settings['fields']
): ?>
<p>まだ取得されていません。</p>
<?php else: ?>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>フィールドコード</th>
<th>ラベル</th>
<th>タイプ</th>
</tr>
</thead>
<tbody>
<?php foreach(
 $settings['fields']
 as $code=>$field
): ?>
<tr>
<td><?=h($code)?></td>
<td><?=h(
 $field['label'] ?? ''
)?></td>
<td><?=h(
 $field['type'] ?? ''
)?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
<?php
    return (string)ob_get_clean();
}

function renderMail(
    array $data
): string {
    $mail =
        $data['mailSettings'];

    ob_start();
    ?>
<h1>メールサーバ設定</h1>

<div class="card">
<form method="post">
<input type="hidden"
 name="action"
 value="save_mail">

<div class="grid2">
<div class="field">
<label>SMTPサーバ</label>
<input name="server"
 value="<?=h(
  $mail['server']
 )?>">
</div>

<div class="field">
<label>SMTPポート</label>
<input name="port"
 value="<?=h(
  $mail['port']
 )?>">
</div>

<div class="field">
<label>暗号化方式</label>
<select name="encryption">
<option value="ssl"
 <?=($mail['encryption']??'')
 ==='ssl'?'selected':''?>>
SSL
</option>
<option value="tls"
 <?=($mail['encryption']??'')
 ==='tls'?'selected':''?>>
TLS
</option>
<option value="none"
 <?=($mail['encryption']??'')
 ==='none'?'selected':''?>>
なし
</option>
</select>
</div>

<div class="field">
<label>SMTP認証</label>
<select name="auth">
<option value="1"
 <?=!empty($mail['auth'])
 ?'selected':''?>>
あり
</option>
<option value="0"
 <?=empty($mail['auth'])
 ?'selected':''?>>
なし
</option>
</select>
</div>

<div class="field">
<label>SMTPユーザー名</label>
<input name="username"
 value="<?=h(
  $mail['username']
 )?>">
</div>

<div class="field">
<label>SMTPパスワード</label>
<input type="password"
 name="password"
 autocomplete="new-password">
</div>

<div class="field">
<label>送信元メールアドレス</label>
<input name="fromEmail"
 value="<?=h(
  $mail['fromEmail']
 )?>">
</div>

<div class="field">
<label>送信元名</label>
<input name="fromName"
 value="<?=h(
  $mail['fromName']
 )?>">
</div>

<div class="field">
<label>返信先</label>
<input name="replyTo"
 value="<?=h(
  $mail['replyTo']
 )?>">
</div>
</div>

<button class="primary">
設定保存
</button>
</form>
</div>

<div class="card">
<p>
接続状態：
<strong>
<?=h(
 $mail['connection']
)?>
</strong>
</p>

<form method="post"
 class="actions">
<input type="hidden"
 name="action"
 value="test_mail">
<input type="password"
 name="password"
 placeholder="SMTPパスワード"
 autocomplete="new-password">
<button>
接続テスト
</button>
</form>

<form method="post"
 class="actions">
<input type="hidden"
 name="action"
 value="send_test_mail">
<input type="password"
 name="password"
 placeholder="SMTPパスワード"
 autocomplete="new-password">
<input type="email"
 name="to"
 placeholder="テスト送信先">
<button>
テストメール送信
</button>
</form>
</div>
<?php
    return (string)ob_get_clean();
}

/* =========================================================
 * 実行
 * ========================================================= */

$data = defaultData();
$flashMessage = null;

try {
    $data = readData();

    updateAutomaticStatus($data);

    /*
     * POST:
     *   入力検証
     *   ↓
     *   保存/外部通信
     *   ↓
     *   結果確定
     *   ↓
     *   redirect
     *
     * 外部通信関数自身はredirectしない。
     */
    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        === 'POST'
    ) {
        processPost($data);
    }

    $flashMessage = takeFlash();
} catch (Throwable $e) {
    /*
     * 秘密情報や内部パスを画面に出さない。
     */
    $message = $e->getMessage();

    if (
        $message === ''
        || str_contains(
            strtolower($message),
            'password'
        )
    ) {
        $message =
            '処理中にエラーが発生しました。';
    }

    flash(
        'error',
        $message
    );

    $flashMessage =
        takeFlash();
}

$screen =
    getString('screen', 'list');

if (
    !in_array(
        $screen,
        [
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
        ],
        true
    )
) {
    $screen = 'list';
}

/*
 * 回答者画面には管理者ナビを出さない。
 */
$isAnswerer =
    in_array(
        $screen,
        [
            'answer',
            'confirm',
            'complete',
        ],
        true
    );

if (
    $flashMessage !== null
) {
    $flashHtml =
        '<div class="alert '
        . h(
            $flashMessage['type']
            ?? 'error'
        )
        . '">'
        . h(
            $flashMessage['message']
            ?? ''
        )
        . '</div>';
} else {
    $flashHtml = '';
}

if ($screen === 'list') {
    $GLOBALS['__APP_DATA'] =
        $data;

    echo layout(
        'アンケート一覧',
        $flashHtml
        . renderList($data),
        true
    );

    exit;
}

if ($screen === 'edit') {
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
        && !$survey
    ) {
        http_response_code(404);

        echo layout(
            'エラー',
            $flashHtml
            . '<div class="card">
                <h1>アンケートが存在しません。</h1>
              </div>',
            true
        );

        exit;
    }

    echo layout(
        'アンケート作成・編集',
        $flashHtml
        . renderEdit(
            $data,
            $survey
        ),
        true
    );

    exit;
}

if (
    $screen === 'preview'
    || $screen === 'send'
    || $screen === 'analytics'
) {
    $id =
        getString('id');

    if (!validateId($id)) {
        http_response_code(400);

        echo layout(
            'エラー',
            $flashHtml
            . '<div class="card">
                <h1>対象アンケートを指定してください。</h1>
              </div>',
            true
        );

        exit;
    }

    $survey =
        surveyById(
            $data,
            $id
        );

    if (!$survey) {
        http_response_code(404);

        echo layout(
            'エラー',
            $flashHtml
            . '<div class="card">
                <h1>対象アンケートが存在しません。</h1>
              </div>',
            true
        );

        exit;
    }

    if ($screen === 'preview') {
        echo layout(
            'プレビュー',
            $flashHtml
            . renderPreview($survey),
            true
        );

        exit;
    }

    if ($screen === 'send') {
        echo layout(
            '顧客選択・メール送信',
            $flashHtml
            . renderSend(
                $data,
                $survey
            ),
            true
        );

        exit;
    }

    echo layout(
        '回答集計・分析',
        $flashHtml
        . renderAnalytics(
            $data,
            $survey
        ),
        true
    );

    exit;
}

if ($screen === 'kintone') {
    echo layout(
        'kintone設定',
        $flashHtml
        . renderKintone($data),
        true
    );

    exit;
}

if ($screen === 'mail') {
    echo layout(
        'メール設定',
        $flashHtml
        . renderMail($data),
        true
    );

    exit;
}

if (
    $screen === 'answer'
    || $screen === 'confirm'
    || $screen === 'complete'
) {
    $id =
        getString('id');

    if (!validateId($id)) {
        http_response_code(400);

        echo layout(
            'エラー',
            '<div class="card">
              <h1>アンケートを指定してください。</h1>
              </div>',
            false
        );

        exit;
    }

    $survey =
        surveyById(
            $data,
            $id
        );

    if (
        !$survey
        || !surveyAvailableForAnswer(
            $survey
        )
    ) {
        http_response_code(404);

        echo layout(
            '回答できません',
            '<div class="card">
              <h1>
              現在回答できるアンケートではありません。
              </h1>
              </div>',
            false
        );

        exit;
    }

    if ($screen === 'answer') {
        echo layout(
            'アンケート回答',
            renderAnswer(
                $data,
                $survey
            ),
            false
        );

        exit;
    }

    if ($screen === 'confirm') {
        if (
            ($_SESSION['answer_survey'] ?? '')
            !== $id
        ) {
            redirectTo(
                'answer',
                ['id' => $id]
            );
        }

        echo layout(
            '回答確認',
            renderConfirm($survey),
            false
        );

        exit;
    }

    echo layout(
        '回答完了',
        '<div class="card">
          <h1>回答ありがとうございました。</h1>
          <p>
          回答は正常に送信されました。
          </p>
          </div>',
        false
    );

    exit;
}

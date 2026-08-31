<?php
declare(strict_types=1);

/*
 * アンケートアプリ POC
 *
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし / PHP mail()なし
 * 単一 index.php
 *
 * 重要:
 * - 管理者認証は実装しない
 * - CSRF対策は実装しない
 * - 外部サービスの秘密情報は保存しない
 * - 外部302/303は成功扱いしない
 * - アプリ自身の303はPRG専用
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
 * 1. 共通
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
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    return $json === false ? '' : $json;
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
    return isset($_GET[$name]) && is_scalar($_GET[$name])
        ? trim((string)$_GET[$name])
        : $default;
}

function postString(string $name, string $default = ''): string
{
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

/*
 * アプリ自身のPRG専用303。
 *
 * 外部サービス通信処理からは絶対に呼び出さない。
 */
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

    $params = array_merge(['screen' => $screen], $params);

    $url = (string)($_SERVER['SCRIPT_NAME'] ?? 'index.php')
        . '?'
        . http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

    header('Location: ' . $url, true, 303);
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
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
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
 * 2. 永続化
 * ========================================================= */

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

    $htaccess = DATA_DIR . DIRECTORY_SEPARATOR . '.htaccess';

    if (!is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "Options -Indexes\n"
            . "<FilesMatch \"\\.(dat|json)(\\.php)?$\">\n"
            . "Require all denied\n"
            . "</FilesMatch>\n"
        );
    }
}

function dataPath(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
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

    /*
     * 旧データに秘密情報が残っている場合でも、
     * 新仕様では読み込んだ時点で永続データから除去する。
     */
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

function saveApp(array $data): void
{
    /*
     * 保存直前にも秘密情報を除去する。
     * 呼び出し側のミスによる保存を二重に防止する。
     */
    unset($data['kintone']['password']);
    unset($data['mailSettings']['password']);

    $path = dataPath('app');

    $tmp = $path
        . '.'
        . bin2hex(random_bytes(8))
        . '.tmp';

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
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

        $length = strlen($json);
        $written = 0;

        while ($written < $length) {
            $n = fwrite(
                $fp,
                substr($json, $written)
            );

            if ($n === false || $n === 0) {
                throw new RuntimeException(
                    'データを書き込めません。'
                );
            }

            $written += $n;
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
 * 3. アンケートデータ
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

    $branches = [];

    if (is_array($q['branches'] ?? null)) {
        foreach ($q['branches'] as $key => $target) {
            if (!is_scalar($target)) {
                continue;
            }

            $target = trim((string)$target);

            if ($target !== '' && validateId($target)) {
                $branches[(string)$key] = $target;
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
                $questions[] = normalizeQuestion($question);
            }
        }

        $groups[] = [
            'id' => validateId((string)($group['id'] ?? ''))
                ? (string)$group['id']
                : uid('g'),
            'title' => trim((string)($group['title'] ?? '')),
            'questions' => $questions,
        ];
    }

    $status = (string)($survey['status'] ?? 'draft');

    if (!in_array(
        $status,
        ['draft', 'published', 'stopped', 'ended'],
        true
    )) {
        $status = 'draft';
    }

    $result = [
        'id' => validateId((string)($survey['id'] ?? ''))
            ? (string)$survey['id']
            : uid('survey'),
        'createdAt' => (string)(
            $survey['createdAt'] ?? today()
        ),
        'updatedAt' => (string)(
            $survey['updatedAt'] ?? today()
        ),
        'title' => trim((string)($survey['title'] ?? '')),
        'description' => trim(
            (string)($survey['description'] ?? '')
        ),
        'startAt' => (string)($survey['startAt'] ?? ''),
        'endAt' => (string)($survey['endAt'] ?? ''),
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

function updateAutomaticStatus(array &$data): void
{
    $changed = false;
    $now = new DateTimeImmutable();

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

        if ($end !== false && $now > $end) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = today();
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
            $survey['startAt']
        );

        if ($start !== false && $now < $start) {
            return false;
        }
    }

    if (!empty($survey['endAt'])) {
        $end = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            $survey['endAt']
        );

        if ($end !== false && $now > $end) {
            return false;
        }
    }

    return true;
}

/* =========================================================
 * 4. 条件分岐・回答検証
 * ========================================================= */

function visibleQuestionIds(
    array $survey,
    array $answers
): array {
    $questions = allQuestions($survey);

    /*
     * 基本表示を全質問とする。
     * 条件分岐先になっていない質問は常時表示。
     * 条件分岐先は親回答に応じて表示。
     */
    $conditionalTargets = [];

    foreach ($questions as $parent) {
        if (($parent['type'] ?? '') !== 'single') {
            continue;
        }

        foreach (($parent['branches'] ?? []) as $option => $target) {
            if (validateId((string)$target)) {
                $conditionalTargets[(string)$target] = [
                    'parent' => $parent['id'],
                    'option' => (string)$option,
                ];
            }
        }
    }

    $visible = [];

    foreach ($questions as $question) {
        $qid = (string)$question['id'];

        if (!isset($conditionalTargets[$qid])) {
            $visible[] = $qid;
            continue;
        }

        $rule = $conditionalTargets[$qid];
        $value = $answers[$rule['parent']] ?? '';

        if ((string)$value === $rule['option']) {
            $visible[] = $qid;
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

    foreach ($visible as $questionId) {
        if (!isset($map[$questionId])) {
            continue;
        }

        $question = $map[$questionId];
        $value = $answers[$questionId] ?? '';

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
                        . 'の選択値が不正です.';
                    break;
                }
            }
        }
    }

    return $errors;
}

function answerUrl(
    string $surveyId,
    ?string $customerId = null
): string {
    if (!validateId($surveyId)) {
        throw new InvalidArgumentException(
            'アンケートIDが不正です。'
        );
    }

    $scheme = !empty($_SERVER['HTTPS'])
        && strtolower((string)$_SERVER['HTTPS']) !== 'off'
        ? 'https'
        : 'http';

    $host = (string)(
        $_SERVER['HTTP_HOST'] ?? 'localhost'
    );

    $script = (string)(
        $_SERVER['SCRIPT_NAME'] ?? '/index.php'
    );

    $url = $scheme
        . '://'
        . $host
        . $script
        . '?screen=answer&id='
        . rawurlencode($surveyId);

    if ($customerId !== null
        && validateId($customerId)) {
        $url .= '&customer='
            . rawurlencode($customerId);
    }

    return $url;
}

/* =========================================================
 * 5. HTTPストリーム通信
 * ========================================================= */

/*
 * PHP cURLを使わない共通HTTP通信。
 *
 * follow_location = 0 とし、
 * 外部302/303をアプリ自身の303と混同しない。
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

    if (!$parts || empty($parts['host'])) {
        throw new InvalidArgumentException(
            '接続先URLが不正です。'
        );
    }

    $context = [
        'http' => [
            'method' => strtoupper($method),
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
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
        $context['http']['content'] = $body;
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

        $context['http']['proxy'] =
            'tcp://' . $proxy;

        $context['http']['request_fulluri'] = true;
    }

    $ctx = stream_context_create($context);

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
                '外部サービスへ接続できません。'
                . '接続先またはネットワーク設定を確認してください。',
        ];
    }

    stream_set_timeout($fp, $timeout);

    $responseBody = stream_get_contents($fp);
    $meta = stream_get_meta_data($fp);

    fclose($fp);

    $headersOut = [];
    $status = 0;

    foreach (($meta['wrapper_data'] ?? []) as $line) {
        if (preg_match(
            '#^HTTP/\S+\s+(\d{3})#i',
            $line,
            $m
        )) {
            $status = (int)$m[1];
        } elseif (str_contains($line, ':')) {
            [$key, $value] = explode(
                ':',
                $line,
                2
            );

            $headersOut[
                strtolower(trim($key))
            ] = trim($value);
        }
    }

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
                '外部サービスへの通信が'
                . 'タイムアウトしました。',
        ];
    }

    /*
     * 外部3xxは絶対に成功扱いしない。
     * LocationヘッダーをアプリのLocationとして流用しない。
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
 * 6. kintone
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

    if (!preg_match(
        '/^[A-Za-z0-9.-]+$/',
        $input
    )) {
        throw new InvalidArgumentException(
            'kintoneサブドメインが不正です。'
        );
    }

    return str_ends_with(
        $input,
        '.cybozu.com'
    )
        ? $input
        : $input . '.cybozu.com';
}

function kintoneAuth(
    string $username,
    string $password
): string {
    if ($username === ''
        || $password === '') {
        throw new InvalidArgumentException(
            'kintoneログイン名とパスワードを入力してください。'
        );
    }

    /*
     * パスワードはここで一時的にAuthorizationへ使用する。
     * ブラウザへ返さない。
     */
    return base64_encode(
        $username . ':' . $password
    );
}

function kintoneRequest(
    array $settings,
    string $path,
    string $method,
    string $password,
    ?array $payload = null
): array {
    $host = normalizeKintoneHost(
        (string)$settings['subdomain']
    );

    $appId = (string)$settings['appId'];

    if (!ctype_digit($appId)
        || (int)$appId < 1) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    $url = 'https://'
        . $host
        . $path;

    $headers = [
        'X-Cybozu-Authorization: '
        . kintoneAuth(
            (string)$settings['username'],
            $password
        ),
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: SurveyPOC/2.0',
    ];

    return httpRequest(
        $url,
        $method,
        $headers,
        $payload === null
            ? null
            : jsonOut($payload),
        20,
        !empty($settings['sslVerify']),
        (($settings['proxy'] ?? '') !== '')
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
        $code = (string)($body['code'] ?? '');
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

function syncCustomersFromKintone(
    array $settings,
    string $password
): array {
    $response = kintoneRecords(
        $settings,
        $password
    );

    if (!$response['ok']) {
        throw new RuntimeException(
            kintoneErrorMessage($response)
        );
    }

    $body = json_decode(
        (string)$response['body'],
        true
    );

    if (!is_array($body)
        || !is_array($body['records'] ?? null)) {
        throw new RuntimeException(
            'kintone顧客情報を取得できませんでした。'
        );
    }

    $records = [];

    foreach ($body['records'] as $record) {
        if (!is_array($record)) {
            continue;
        }

        $get = static function (
            string $key
        ) use ($record): string {
            $value = $record[$key]['value'] ?? '';

            if (is_array($value)) {
                $value = implode(
                    ' ',
                    array_map(
                        'strval',
                        $value
                    )
                );
            }

            return trim((string)$value);
        };

        /*
         * 項目マッピングは後段の設定値を利用。
         * kintoneフィールドコードが未設定の場合は
         * 一般的なコードを試す。
         */
        $name = $get('name');
        $email = $get('email');
        $org = $get('org');
        $department = $get('department');
        $phone = $get('phone');

        $addressParts = [];

        foreach ([
            'postalCode',
            'prefecture',
            'city',
            'address',
            'building',
        ] as $key) {
            $v = $get($key);

            if ($v !== '') {
                $addressParts[] = $v;
            }
        }

        $records[] = [
            'id' => uid('customer'),
            'externalId' => $get('$id'),
            'org' => $org,
            'name' => $name,
            'email' => $email,
            'department' => $department,
            'phone' => $phone,
            'address' => implode(
                ' ',
                $addressParts
            ),
            'answerStatus' => 'unanswered',
            'updatedAt' => now(),
        ];
    }

    return $records;
}

/* =========================================================
 * 7. SMTP
 * ========================================================= */

function smtpRead($fp): array
{
    $lines = [];

    while (!feof($fp)) {
        $line = fgets($fp);

        if ($line === false) {
            break;
        }

        $line = rtrim(
            $line,
            "\r\n"
        );

        $lines[] = $line;

        if (preg_match(
            '/^\d{3}\s/',
            $line
        )) {
            break;
        }
    }

    if (!$lines) {
        throw new RuntimeException(
            'SMTPレスポンスを取得できませんでした。'
        );
    }

    $last = end($lines);

    return [
        'code' => (int)substr(
            (string)$last,
            0,
            3
        ),
        'lines' => $lines,
    ];
}

function smtpExpect(
    $fp,
    array $expected
): array {
    $response = smtpRead($fp);

    if (!in_array(
        $response['code'],
        $expected,
        true
    )) {
        throw new RuntimeException(
            'SMTPサーバから予期しない応答 '
            . $response['code']
            . ' が返されました。'
        );
    }

    return $response;
}

function smtpWrite(
    $fp,
    string $line
): void {
    $payload = $line . "\r\n";
    $length = strlen($payload);
    $written = 0;

    while ($written < $length) {
        $n = fwrite(
            $fp,
            substr($payload, $written)
        );

        if ($n === false || $n === 0) {
            throw new RuntimeException(
                'SMTPへデータを送信できません。'
            );
        }

        $written += $n;
    }
}

function smtpConnect(
    array $settings,
    string $password
) {
    $server = trim(
        (string)($settings['server'] ?? '')
    );

    $port = (int)(
        $settings['port'] ?? 0
    );

    $encryption = (string)(
        $settings['encryption'] ?? 'none'
    );

    if ($server === ''
        || $port < 1
        || $port > 65535) {
        throw new InvalidArgumentException(
            'SMTPサーバまたはポートが不正です。'
        );
    }

    if (!in_array(
        $encryption,
        ['ssl', 'tls', 'none'],
        true
    )) {
        throw new InvalidArgumentException(
            'SMTP暗号化方式が不正です。'
        );
    }

    $host = $encryption === 'ssl'
        ? 'ssl://' . $server
        : 'tcp://' . $server;

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $host . ':' . $port,
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

    try {
        smtpExpect($fp, [220]);

        $ehlo = preg_replace(
            '/[^A-Za-z0-9.-]/',
            '',
            (string)(
                $_SERVER['SERVER_NAME']
                ?? 'localhost'
            )
        );

        if ($ehlo === '') {
            $ehlo = 'localhost';
        }

        smtpWrite(
            $fp,
            'EHLO ' . $ehlo
        );

        smtpExpect($fp, [250]);

        if ($encryption === 'tls') {
            smtpWrite(
                $fp,
                'STARTTLS'
            );

            smtpExpect($fp, [220]);

            $crypto =
                stream_socket_enable_crypto(
                    $fp,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'SMTP TLS接続を確立できません。'
                );
            }

            smtpWrite(
                $fp,
                'EHLO ' . $ehlo
            );

            smtpExpect($fp, [250]);
        }

        $auth = !empty(
            $settings['auth']
        );

        if ($auth) {
            $username = trim(
                (string)(
                    $settings['username']
                    ?? ''
                )
            );

            if ($username === ''
                || $password === '') {
                throw new InvalidArgumentException(
                    'SMTP認証情報を入力してください。'
                );
            }

            smtpWrite(
                $fp,
                'AUTH LOGIN'
            );

            smtpExpect($fp, [334]);

            smtpWrite(
                $fp,
                base64_encode($username)
            );

            smtpExpect($fp, [334]);

            smtpWrite(
                $fp,
                base64_encode($password)
            );

            smtpExpect($fp, [235]);
        }

        return $fp;
    } catch (Throwable $e) {
        @fclose($fp);
        throw $e;
    }
}

function smtpEncodeHeader(
    string $value
): string {
    if ($value === '') {
        return '';
    }

    if (preg_match(
        '/[^\x20-\x7E]/',
        $value
    )) {
        return '=?UTF-8?B?'
            . base64_encode($value)
            . '?=';
    }

    return $value;
}

function smtpDotStuff(
    string $body
): string {
    $body = str_replace(
        ["\r\n", "\r"],
        "\n",
        $body
    );

    $body = str_replace(
        "\n",
        "\r\n",
        $body
    );

    return preg_replace(
        '/^\./m',
        '..',
        $body
    ) ?? $body;
}

function smtpSendOne(
    array $settings,
    string $password,
    string $to,
    string $subject,
    string $body
): array {
    if (!validEmail($to)) {
        return [
            'ok' => false,
            'message' =>
                'メールアドレスが不正です。',
        ];
    }

    $from = trim(
        (string)(
            $settings['fromEmail'] ?? ''
        )
    );

    if (!validEmail($from)) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    $fp = null;

    try {
        $fp = smtpConnect(
            $settings,
            $password
        );

        $fromName = smtpEncodeHeader(
            (string)(
                $settings['fromName'] ?? ''
            )
        );

        $fromHeader = $fromName !== ''
            ? $fromName . ' <' . $from . '>'
            : $from;

        smtpWrite(
            $fp,
            'MAIL FROM:<' . $from . '>'
        );

        smtpExpect($fp, [250]);

        smtpWrite(
            $fp,
            'RCPT TO:<' . $to . '>'
        );

        smtpExpect(
            $fp,
            [250, 251]
        );

        smtpWrite(
            $fp,
            'DATA'
        );

        smtpExpect($fp, [354]);

        $headers = [
            'From: ' . $fromHeader,
            'To: <' . $to . '>',
            'Subject: '
                . smtpEncodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $replyTo = trim(
            (string)(
                $settings['replyTo'] ?? ''
            )
        );

        if ($replyTo !== '') {
            if (!validEmail($replyTo)) {
                throw new InvalidArgumentException(
                    '返信先メールアドレスが不正です。'
                );
            }

            $headers[] =
                'Reply-To: <'
                . $replyTo
                . '>';
        }

        $mailBody =
            implode(
                "\r\n",
                $headers
            )
            . "\r\n\r\n"
            . smtpDotStuff($body)
            . "\r\n.";

        smtpWrite(
            $fp,
            $mailBody
        );

        smtpExpect($fp, [250]);

        smtpWrite(
            $fp,
            'QUIT'
        );

        /*
         * QUITへの応答を可能な範囲で読む。
         * DATA後の250を取得できた時点で送信成功を確定する。
         */
        @smtpRead($fp);

        fclose($fp);
        $fp = null;

        return [
            'ok' => true,
            'message' => '送信しました。',
        ];
    } catch (Throwable $e) {
        if (is_resource($fp)) {
            @fclose($fp);
        }

        return [
            'ok' => false,
            'message' =>
                $e->getMessage()
                !== ''
                    ? $e->getMessage()
                    : 'SMTP送信に失敗しました。',
        ];
    }
}

/* =========================================================
 * 8. POST処理
 * ========================================================= */

function requirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RuntimeException(
            'POSTリクエストが必要です。'
        );
    }
}

function processPost(
    array &$data
): void {
    requirePost();

    $action = postString('action');

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

        case 'save_mail':
            saveMailAction($data);
            return;

        case 'test_mail':
            testMailAction($data);
            return;

        case 'send_mail':
            sendMailAction($data);
            return;

        default:
            throw new InvalidArgumentException(
                '指定された操作は利用できません。'
            );
    }
}

function saveSurveyAction(
    array &$data
): void {
    $id = postString('id');

    if ($id !== '' && !validateId($id)) {
        throw new InvalidArgumentException(
            'アンケートIDが不正です。'
        );
    }

    $title = postString('title');

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

    $startAt = postString('startAt');
    $endAt = postString('endAt');

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

    $numbering = postString(
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

    $existing = $id !== ''
        ? surveyById($data, $id)
        : null;

    $survey = normalizeSurvey([
        'id' => $existing['id'] ?? (
            $id !== '' ? $id : uid('survey')
        ),
        'createdAt' => $existing['createdAt']
            ?? today(),
        'updatedAt' => today(),
        'title' => $title,
        'description' => postString('description'),
        'startAt' => $startAt,
        'endAt' => $endAt,
        'status' => $existing['status']
            ?? 'draft',
        'numbering' => $numbering,
        'groups' => postArray('groups'),
    ]);

    if ($existing !== null) {
        $index = surveyIndex(
            $data,
            $survey['id']
        );

        if ($index < 0) {
            throw new RuntimeException(
                'アンケートが存在しません。'
            );
        }

        $data['surveys'][$index] = $survey;
    } else {
        $data['surveys'][] = $survey;
    }

    saveApp($data);

    flash(
        'success',
        'アンケートを保存しました。'
    );

    redirectTo('list');
}

function transitionAction(
    array &$data
): void {
    $id = postString('id');
    $to = postString('to');

    $index = surveyIndex($data, $id);

    if ($index < 0) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $from = (string)(
        $data['surveys'][$index]['status']
        ?? ''
    );

    if (!canTransition($from, $to)) {
        throw new InvalidArgumentException(
            '指定された状態遷移は許可されていません。'
        );
    }

    $data['surveys'][$index]['status'] = $to;
    $data['surveys'][$index]['updatedAt'] = today();

    saveApp($data);

    flash(
        'success',
        '状態を変更しました。'
    );

    redirectTo('list');
}

function duplicateAction(
    array &$data
): void {
    $id = postString('id');
    $survey = surveyById($data, $id);

    if (!$survey) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $survey['id'] = uid('survey');
    $survey['title'] .= '（コピー）';
    $survey['createdAt'] = today();
    $survey['updatedAt'] = today();
    $survey['status'] = 'draft';

    foreach ($survey['groups'] as &$group) {
        $group['id'] = uid('g');

        foreach ($group['questions'] as &$question) {
            $question['id'] = uid('q');
            $question['branches'] = [];
        }
    }

    unset($group, $question);

    renumberSurvey($survey);

    $data['surveys'][] = $survey;

    saveApp($data);

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
    $index = surveyIndex($data, $id);

    if ($index < 0) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    if (($data['surveys'][$index]['status'] ?? '')
        === 'published') {
        throw new InvalidArgumentException(
            '公開中のアンケートは削除できません。'
        );
    }

    array_splice(
        $data['surveys'],
        $index,
        1
    );

    unset($data['answers'][$id]);

    saveApp($data);

    flash(
        'success',
        'アンケートを削除しました。'
    );

    redirectTo('list');
}

/* =========================================================
 * 9. 回答POST
 * ========================================================= */

function answerConfirmAction(
    array &$data
): void {
    $surveyId = postString('surveyId');
    $survey = surveyById(
        $data,
        $surveyId
    );

    if (!$survey
        || !surveyAvailableForAnswer($survey)) {
        throw new RuntimeException(
            '回答可能なアンケートではありません。'
        );
    }

    $answers = postArray('answers');

    $errors = validateAnswers(
        $survey,
        $answers
    );

    if ($errors) {
        $_SESSION['answer_draft'] = $answers;
        $_SESSION['answer_errors'] = $errors;

        redirectTo(
            'answer',
            ['id' => $surveyId]
        );
    }

    $_SESSION['answer_draft'] = $answers;
    $_SESSION['answer_survey'] = $surveyId;

    redirectTo(
        'confirm',
        ['id' => $surveyId]
    );
}

function answerSubmitAction(
    array &$data
): void {
    $surveyId = postString('surveyId');

    $survey = surveyById(
        $data,
        $surveyId
    );

    if (!$survey
        || !surveyAvailableForAnswer($survey)) {
        throw new RuntimeException(
            '回答可能なアンケートではありません。'
        );
    }

    $answers = $_SESSION['answer_draft'] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $errors = validateAnswers(
        $survey,
        $answers
    );

    if ($errors) {
        $_SESSION['answer_errors'] = $errors;

        redirectTo(
            'answer',
            ['id' => $surveyId]
        );
    }

    $customerId = postString('customerId');

    if (!validateId($customerId)) {
        $customerId = '';
    }

    $customer = null;

    foreach ($data['customers'] as $candidate) {
        if (($candidate['id'] ?? '')
            === $customerId) {
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
        'date' => now(),
        'values' => $answers,
    ];

    if ($customer) {
        foreach ($data['customers'] as &$candidate) {
            if (($candidate['id'] ?? '')
                === $customerId) {
                $candidate['answerStatus'] =
                    'answered';
                break;
            }
        }

        unset($candidate);
    }

    /*
     * 保存完了を確認してから画面遷移。
     */
    saveApp($data);

    unset(
        $_SESSION['answer_draft'],
        $_SESSION['answer_errors'],
        $_SESSION['answer_survey']
    );

    redirectTo(
        'complete',
        ['id' => $surveyId]
    );
}

/* =========================================================
 * 10. kintone POST
 * ========================================================= */

/*
 * 設定保存では秘密情報を絶対に保存しない。
 */
function saveKintoneAction(
    array &$data
): void {
    $subdomain = postString(
        'subdomain'
    );

    if ($subdomain !== '') {
        normalizeKintoneHost(
            $subdomain
        );
    }

    $appId = postString('appId');

    if ($appId !== ''
        && (!ctype_digit($appId)
            || (int)$appId < 1)) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    $proxy = postString('proxy');

    if ($proxy !== ''
        && !preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で指定してください。'
        );
    }

    $data['kintone']['subdomain'] =
        $subdomain;

    $data['kintone']['appId'] =
        $appId;

    $data['kintone']['username'] =
        postString('username');

    $data['kintone']['proxy'] =
        $proxy;

    $data['kintone']['sslVerify'] =
        postString(
            'sslVerify',
            '1'
        ) === '1';

    /*
     * passwordは意図的に取得・保存しない。
     *
     * これにより設定保存POSTにpasswordが含まれていても、
     * 永続データには残らない。
     */
    saveApp($data);

    flash(
        'success',
        'kintone設定を保存しました。'
    );

    redirectTo('kintone');
}

function testKintoneAction(
    array &$data
): void {
    /*
     * パスワードはこのPOST処理のためだけに受け取る。
     * 保存済みパスワードの復号・再利用はしない。
     */
    $password = postString('password');

    if ($password === '') {
        throw new InvalidArgumentException(
            'kintoneパスワードを入力してください。'
        );
    }

    $response = kintoneTest(
        $data['kintone'],
        $password
    );

    /*
     * ここで外部結果を確定。
     * 302/303を含む非2xxは成功にしない。
     */
    if (!$response['ok']) {
        $detail = kintoneErrorMessage(
            $response
        );

        $data['kintone']['connection'] =
            '接続できません';

        $data['kintone']['connectionDetail'] =
            $detail;

        saveApp($data);

        throw new RuntimeException($detail);
    }

    $data['kintone']['connection'] =
        '接続確認済み';

    $data['kintone']['connectionDetail'] =
        'kintoneへの接続と認証に成功しました。';

    saveApp($data);

    unset($password);

    flash(
        'success',
        'kintone接続テストに成功しました。'
    );

    /*
     * 外部通信成功確定・保存完了後の
     * アプリ自身の303。
     */
    redirectTo('kintone');
}

function fetchKintoneFieldsAction(
    array &$data
): void {
    $password = postString('password');

    if ($password === '') {
        throw new InvalidArgumentException(
            'kintoneパスワードを入力してください。'
        );
    }

    $response = kintoneFields(
        $data['kintone'],
        $password
    );

    if (!$response['ok']) {
        throw new RuntimeException(
            kintoneErrorMessage($response)
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

    $data['kintone']['fields'] = [];

    foreach (
        $json['properties'] as $code => $field
    ) {
        if (!is_array($field)) {
            continue;
        }

        $data['kintone']['fields'][
            (string)$code
        ] = [
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

    saveApp($data);

    unset($password);

    flash(
        'success',
        'kintone項目一覧を再取得しました。'
    );

    redirectTo('kintone');
}

function syncKintoneAction(
    array &$data
): void {
    $password = postString('password');

    if ($password === '') {
        throw new InvalidArgumentException(
            'kintoneパスワードを入力してください。'
        );
    }

    $customers = syncCustomersFromKintone(
        $data['kintone'],
        $password
    );

    $data['customers'] = $customers;

    saveApp($data);

    unset($password);

    flash(
        'success',
        count($customers)
        . '件の顧客情報を同期しました。'
    );

    redirectTo('kintone');
}

/* =========================================================
 * 11. SMTP POST
 * ========================================================= */

function saveMailAction(
    array &$data
): void {
    $server = postString('server');
    $port = (int)postString('port', '587');
    $encryption = postString(
        'encryption',
        'tls'
    );

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

    if (!in_array(
        $encryption,
        ['ssl', 'tls', 'none'],
        true
    )) {
        throw new InvalidArgumentException(
            '暗号化方式が不正です。'
        );
    }

    $fromEmail = postString(
        'fromEmail'
    );

    if (!validEmail($fromEmail)) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    $replyTo = postString(
        'replyTo'
    );

    if ($replyTo !== ''
        && !validEmail($replyTo)) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが不正です。'
        );
    }

    $data['mailSettings']['server'] =
        $server;

    $data['mailSettings']['port'] =
        $port;

    $data['mailSettings']['encryption'] =
        $encryption;

    $data['mailSettings']['auth'] =
        postString('auth', '1') === '1';

    $data['mailSettings']['username'] =
        postString('username');

    $data['mailSettings']['fromEmail'] =
        $fromEmail;

    $data['mailSettings']['fromName'] =
        postString('fromName');

    $data['mailSettings']['replyTo'] =
        $replyTo;

    /*
     * SMTP passwordは保存しない。
     */
    saveApp($data);

    flash(
        'success',
        'メールサーバ設定を保存しました。'
    );

    redirectTo('mail');
}

function testMailAction(
    array &$data
): void {
    $password = postString('password');
    $testTo = postString('testTo');

    if (!validEmail($testTo)) {
        throw new InvalidArgumentException(
            'テスト送信先メールアドレスが不正です。'
        );
    }

    if (!empty(
        $data['mailSettings']['auth']
    ) && $password === '') {
        throw new InvalidArgumentException(
            'SMTPパスワードを入力してください。'
        );
    }

    $result = smtpSendOne(
        $data['mailSettings'],
        $password,
        $testTo,
        'アンケートアプリ SMTP テスト',
        'SMTP接続テストメールです。'
    );

    if (!$result['ok']) {
        $data['mailSettings']['connection'] =
            '接続できません';

        $data['mailSettings']['connectionDetail'] =
            $result['message'];

        saveApp($data);

        throw new RuntimeException(
            $result['message']
        );
    }

    $data['mailSettings']['connection'] =
        '接続確認済み';

    $data['mailSettings']['connectionDetail'] =
        'SMTP認証およびテストメール送信に成功しました。';

    saveApp($data);

    unset($password);

    flash(
        'success',
        'テストメールを送信しました。'
    );

    redirectTo('mail');
}

function sendMailAction(
    array &$data
): void {
    $surveyId = postString(
        'surveyId'
    );

    $survey = surveyById(
        $data,
        $surveyId
    );

    if (!$survey) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $customerIds = postArray(
        'customers'
    );

    if (!$customerIds) {
        throw new InvalidArgumentException(
            '送信する顧客を選択してください。'
        );
    }

    $subject = postString(
        'subject'
    );

    $body = postString(
        'body'
    );

    if ($subject === '') {
        throw new InvalidArgumentException(
            '件名を入力してください。'
        );
    }

    if ($body === '') {
        throw new InvalidArgumentException(
            '本文を入力してください。'
        );
    }

    $password = postString(
        'password'
    );

    if (!empty(
        $data['mailSettings']['auth']
    ) && $password === '') {
        throw new InvalidArgumentException(
            'SMTPパスワードを入力してください。'
        );
    }

    $sent = 0;
    $failed = 0;
    $errors = [];

    $ids = array_map(
        'strval',
        $customerIds
    );

    foreach ($data['customers'] as &$customer) {
        $customerId = (string)(
            $customer['id'] ?? ''
        );

        if (!in_array(
            $customerId,
            $ids,
            true
        )) {
            continue;
        }

        $email = trim(
            (string)(
                $customer['email'] ?? ''
            )
        );

        $name = (string)(
            $customer['name'] ?? ''
        );

        if (!validEmail($email)) {
            $failed++;

            $errors[] =
                $name
                . ': メールアドレスが不正です。';

            continue;
        }

        $url = answerUrl(
            $surveyId,
            $customerId
        );

        $message = str_replace(
            [
                '{顧客名}',
                '{アンケートURL}',
            ],
            [
                $name,
                $url,
            ],
            $body
        );

        $result = smtpSendOne(
            $data['mailSettings'],
            $password,
            $email,
            $subject,
            $message
        );

        if ($result['ok']) {
            $sent++;

            $data['sendHistory'][] = [
                'id' => uid('send'),
                'surveyId' => $surveyId,
                'customerId' => $customerId,
                'customer' => $name,
                'email' => $email,
                'date' => now(),
                'status' => 'sent',
            ];
        } else {
            $failed++;

            $errors[] =
                $name
                . ': '
                . $result['message'];

            $data['sendHistory'][] = [
                'id' => uid('send'),
                'surveyId' => $surveyId,
                'customerId' => $customerId,
                'customer' => $name,
                'email' => $email,
                'date' => now(),
                'status' => 'failed',
                'message' =>
                    $result['message'],
            ];
        }
    }

    unset($customer);

    /*
     * SMTP通信結果をすべて確定してから保存する。
     * passwordはdata配列へ入れていない。
     */
    saveApp($data);

    unset($password);

    $detail =
        '成功: ' . $sent . '件 / '
        . '失敗: ' . $failed . '件';

    if ($errors) {
        $detail .= "\n"
            . implode("\n", $errors);
    }

    flash(
        $failed === 0
            ? 'success'
            : 'error',
        $failed === 0
            ? 'メール送信が完了しました。'
            : 'メール送信が完了しましたが、一部失敗しました。',
        $detail
    );

    redirectTo(
        'send',
        ['id' => $surveyId]
    );
}

/* =========================================================
 * 12. 共通HTML
 * ========================================================= */

function renderLayout(
    string $content,
    bool $admin = true
): string {
    $flash = takeFlash();

    ob_start();
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>アンケートアプリ</title>
<style>
:root{
 --primary:#4f46e5;
 --primary-dark:#4338ca;
 --text:#111827;
 --muted:#64748b;
 --border:#e2e8f0;
 --card:#fff;
 --danger:#dc2626;
 --bg:#f8fafc;
}
*{box-sizing:border-box}
body{
 margin:0;
 background:var(--bg);
 color:var(--text);
 font-family:
  -apple-system,BlinkMacSystemFont,
  "Segoe UI","Noto Sans JP",sans-serif;
 line-height:1.6;
}
header{
 background:#fff;
 border-bottom:1px solid var(--border);
 padding:14px 20px;
}
.header-inner{
 max-width:1200px;
 margin:auto;
 display:flex;
 gap:20px;
 align-items:center;
 justify-content:space-between;
}
.brand{
 font-weight:700;
 color:var(--text);
 text-decoration:none;
}
nav{
 display:flex;
 gap:8px;
 flex-wrap:wrap;
}
nav a{
 color:#374151;
 text-decoration:none;
 padding:6px 10px;
 border-radius:6px;
}
nav a:hover{background:#eef2ff}
main{
 max-width:1200px;
 margin:0 auto;
 padding:24px 20px 60px;
}
h1{
 font-size:26px;
 margin:0 0 20px;
}
h2{
 font-size:19px;
 margin:0 0 14px;
}
h3{
 margin-top:0;
}
.card{
 background:var(--card);
 border:1px solid var(--border);
 border-radius:12px;
 padding:20px;
 margin-bottom:18px;
 box-shadow:0 2px 8px rgba(15,23,42,.04);
}
.grid2{
 display:grid;
 grid-template-columns:
  repeat(2,minmax(0,1fr));
 gap:16px;
}
.field{margin-bottom:14px}
label{
 display:block;
 font-weight:600;
 margin-bottom:5px;
}
input,textarea,select{
 width:100%;
 border:1px solid #cbd5e1;
 border-radius:7px;
 padding:9px 10px;
 background:#fff;
 color:var(--text);
 font:inherit;
}
textarea{
 min-height:100px;
 resize:vertical;
}
button,.btn{
 border:1px solid #cbd5e1;
 background:#fff;
 color:#374151;
 padding:8px 13px;
 border-radius:7px;
 cursor:pointer;
 font:inherit;
 text-decoration:none;
 display:inline-block;
}
button:hover,.btn:hover{
 background:#f8fafc;
}
button.primary,.primary{
 background:var(--primary);
 border-color:var(--primary);
 color:#fff;
}
button.primary:hover,.primary:hover{
 background:var(--primary-dark);
}
button.danger{
 color:#fff;
 background:var(--danger);
 border-color:var(--danger);
}
.actions{
 display:flex;
 gap:8px;
 flex-wrap:wrap;
 margin-top:16px;
}
.alert{
 padding:12px 14px;
 border-radius:8px;
 margin-bottom:16px;
 white-space:pre-wrap;
}
.alert.success{
 background:#ecfdf5;
 color:#166534;
 border:1px solid #bbf7d0;
}
.alert.error{
 background:#fef2f2;
 color:#991b1b;
 border:1px solid #fecaca;
}
.table-wrap{overflow-x:auto}
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
th{background:#f8fafc}
.badge{
 display:inline-block;
 padding:3px 8px;
 border-radius:999px;
 font-size:12px;
 background:#e5e7eb;
}
.badge.published{
 background:#dcfce7;
 color:#166534;
}
.badge.draft{
 background:#e5e7eb;
 color:#374151;
}
.badge.stopped{
 background:#fef3c7;
 color:#92400e;
}
.badge.ended{
 background:#fee2e2;
 color:#991b1b;
}
.muted{
 color:var(--muted);
}
.group{
 border:1px solid var(--border);
 border-radius:10px;
 padding:16px;
 margin-bottom:16px;
 background:#fff;
}
.question{
 border:1px solid var(--border);
 border-radius:8px;
 padding:14px;
 margin-bottom:12px;
 background:#fafafa;
}
.question-head{
 display:flex;
 align-items:center;
 gap:8px;
 justify-content:space-between;
 margin-bottom:10px;
}
.qnumber{font-weight:700}
.answer-option{
 display:flex;
 gap:8px;
 align-items:center;
 margin:8px 0;
}
.answer-option input{
 width:auto;
}
.stats{
 display:grid;
 grid-template-columns:
  repeat(4,minmax(0,1fr));
 gap:12px;
 margin-bottom:18px;
}
.stat{
 background:#fff;
 border:1px solid var(--border);
 border-radius:10px;
 padding:16px;
}
.stat strong{
 display:block;
 font-size:24px;
 margin-top:4px;
}
@media(max-width:800px){
 .grid2,.stats{
  grid-template-columns:1fr;
 }
 .header-inner{
  align-items:flex-start;
  flex-direction:column;
 }
 main{
  padding:18px 12px 40px;
 }
 .card{padding:15px}
}
</style>
</head>
<body>
<?php if ($admin): ?>
<header>
<div class="header-inner">
<a class="brand" href="?screen=list">
アンケートアプリ
</a>
<nav>
<a href="?screen=list">アンケート一覧</a>
<a href="?screen=kintone">kintone設定</a>
<a href="?screen=mail">メール設定</a>
</nav>
</div>
</header>
<?php endif; ?>

<main>
<?php if ($flash): ?>
<div class="alert <?=h(
    $flash['type'] ?? 'success'
)?>">
<strong><?=h(
    $flash['message'] ?? ''
)?></strong>
<?php if (!empty($flash['detail'])): ?>

<?=h($flash['detail'])?>
<?php endif; ?>
</div>
<?php endif; ?>

<?=$content?>
</main>
</body>
</html>
<?php
    return (string)ob_get_clean();
}

/* =========================================================
 * 13. 一覧
 * ========================================================= */

function renderList(
    array $data
): string {
    $search = getString('q');
    $status = getString(
        'status',
        'all'
    );
    $sort = getString(
        'sort',
        'updated_desc'
    );

    $surveys = array_values(
        array_filter(
            $data['surveys'],
            static function (
                $survey
            ) use (
                $search,
                $status
            ): bool {
                if ($search !== ''
                    && mb_stripos(
                        (string)$survey['title'],
                        $search
                    ) === false) {
                    return false;
                }

                if ($status !== 'all'
                    && ($survey['status'] ?? '')
                    !== $status) {
                    return false;
                }

                return true;
            }
        )
    );

    usort(
        $surveys,
        static function (
            $a,
            $b
        ) use (
            $sort,
            $data
        ): int {
            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)$a['updatedAt'],
                        (string)$b['updatedAt']
                    ),

                'answers_desc' =>
                    count(
                        $data['answers'][
                            $b['id']
                        ] ?? []
                    )
                    <=>
                    count(
                        $data['answers'][
                            $a['id']
                        ] ?? []
                    ),

                'answers_asc' =>
                    count(
                        $data['answers'][
                            $a['id']
                        ] ?? []
                    )
                    <=>
                    count(
                        $data['answers'][
                            $b['id']
                        ] ?? []
                    ),

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

    ob_start();
    ?>
<h1>アンケート一覧</h1>

<div class="card">
<form method="get">
<input type="hidden"
       name="screen"
       value="list">

<div class="grid2">
<div class="field">
<label>タイトル検索</label>
<input name="q"
       value="<?=h($search)?>"
       placeholder="タイトルを入力してEnter">
</div>

<div class="field">
<label>ステータス</label>
<select name="status">
<option value="all"
 <?=$status==='all'?'selected':''?>>
すべて
</option>
<option value="published"
 <?=$status==='published'?'selected':''?>>
公開中
</option>
<option value="draft"
 <?=$status==='draft'?'selected':''?>>
下書き
</option>
<option value="stopped"
 <?=$status==='stopped'?'selected':''?>>
停止
</option>
<option value="ended"
 <?=$status==='ended'?'selected':''?>>
終了
</option>
</select>
</div>
</div>

<div class="field">
<label>ソート</label>
<select name="sort">
<option value="updated_desc"
 <?=$sort==='updated_desc'?'selected':''?>>
更新日：新しい順
</option>
<option value="updated_asc"
 <?=$sort==='updated_asc'?'selected':''?>>
更新日：古い順
</option>
<option value="answers_desc"
 <?=$sort==='answers_desc'?'selected':''?>>
回答数：多い順
</option>
<option value="answers_asc"
 <?=$sort==='answers_asc'?'selected':''?>>
回答数：少ない順
</option>
<option value="start_desc"
 <?=$sort==='start_desc'?'selected':''?>>
開始日：新しい順
</option>
<option value="start_asc"
 <?=$sort==='start_asc'?'selected':''?>>
開始日：古い順
</option>
</select>
</div>

<button class="primary">
検索
</button>
</form>
</div>

<div class="actions">
<a class="btn primary"
   href="?screen=edit">
新規作成
</a>
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
<th>ステータス</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>
<tbody>

<?php if (!$surveys): ?>
<tr>
<td colspan="7">
該当するアンケートはありません。
</td>
</tr>
<?php endif; ?>

<?php foreach ($surveys as $survey): ?>
<?php
$answerCount = count(
    $data['answers'][$survey['id']] ?? []
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
    statusClass(
        $survey['status']
    )
)?>">
<?=h(
    statusLabel(
        $survey['status']
    )
)?>
</span>
</td>
<td><?=h($answerCount)?></td>
<td>
<div class="actions">

<a class="btn"
 href="?screen=edit&id=<?=rawurlencode(
     $survey['id']
 )?>">
確認・編集
</a>

<a class="btn"
 href="?screen=preview&id=<?=rawurlencode(
     $survey['id']
 )?>">
プレビュー
</a>

<a class="btn"
 href="?screen=analytics&id=<?=rawurlencode(
     $survey['id']
 )?>">
集計
</a>

<a class="btn"
 href="?screen=send&id=<?=rawurlencode(
     $survey['id']
 )?>">
送信
</a>

<form method="post">
<input type="hidden"
 name="action"
 value="duplicate">
<input type="hidden"
 name="id"
 value="<?=h($survey['id'])?>">
<button>複製</button>
</form>

<?php if ($survey['status'] === 'draft'): ?>
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
<button class="primary">
公開
</button>
</form>
<?php elseif (
    $survey['status'] === 'published'
): ?>
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
<button>
停止
</button>
</form>
<?php elseif (
    $survey['status'] === 'stopped'
): ?>
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
<button class="primary">
再公開
</button>
</form>
<?php endif; ?>

<?php if (
    $survey['status'] !== 'published'
    && $survey['status'] !== 'ended'
): ?>
<form method="post"
      onsubmit="return confirm('削除しますか？');">
<input type="hidden"
 name="action"
 value="delete_survey">
<input type="hidden"
 name="id"
 value="<?=h($survey['id'])?>">
<button class="danger">
削除
</button>
</form>
<?php endif; ?>

</div>
</td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>
</div>
<?php

    return (string)ob_get_clean();
}

/* =========================================================
 * 14. 作成・編集
 * ========================================================= */

function renderEdit(
    array $data,
    ?array $survey
): string {
    $survey ??= normalizeSurvey([
        'id' => uid('survey'),
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
                'questions' => [
                    [
                        'id' => uid('q'),
                        'text' => '',
                        'type' => 'single',
                        'required' => false,
                        'options' => [
                            '選択肢1',
                            '選択肢2',
                        ],
                        'branches' => [],
                    ],
                ],
            ],
        ],
    ]);

    ob_start();
    ?>
<h1>アンケート作成・編集</h1>

<form method="post">
<input type="hidden"
       name="action"
       value="save_survey">
<input type="hidden"
       name="id"
       value="<?=h($survey['id'])?>">

<div class="card">
<div class="grid2">

<div class="field">
<label>タイトル</label>
<input name="title"
       required
       maxlength="200"
       value="<?=h($survey['title'])?>">
</div>

<div class="field">
<label>質問番号の採番方式</label>
<select name="numbering">
<option value="global"
 <?=$survey['numbering']==='global'
     ?'selected':''?>>
全体通番：Q1、Q2、Q3...
</option>
<option value="group"
 <?=$survey['numbering']==='group'
     ?'selected':''?>>
グループ単位：Q1-1、Q1-2...
</option>
</select>
</div>

</div>

<div class="field">
<label>説明</label>
<textarea name="description"><?=h(
    $survey['description']
)?></textarea>
</div>

<div class="grid2">
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

<div class="muted">
現在の状態：
<?=h(
    statusLabel(
        $survey['status']
    )
)?>
</div>
</div>

<div id="groups">
<?php foreach (
    $survey['groups'] as $gi => $group
): ?>
<div class="group"
     data-group>

<div class="question-head">
<h2>
グループ <?=h($gi + 1)?>
</h2>

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
<?php foreach (
    $group['questions'] as $qi => $question
): ?>
<div class="question"
     data-question>

<div class="question-head">
<span class="qnumber">
<?=h($question['number'])?>
</span>
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
<textarea
 name="groups[<?=$gi?>][questions][<?=$qi?>][text]"
 required><?=h(
    $question['text']
)?></textarea>
</div>

<div class="grid2">
<div class="field">
<label>回答形式</label>
<select
 name="groups[<?=$gi?>][questions][<?=$qi?>][type]"
 onchange="toggleOptions(this)">
<option value="single"
 <?=$question['type']==='single'
     ?'selected':''?>>
単一選択
</option>
<option value="multiple"
 <?=$question['type']==='multiple'
     ?'selected':''?>>
複数選択
</option>
<option value="free"
 <?=$question['type']==='free'
     ?'selected':''?>>
自由記述
</option>
</select>
</div>

<div class="field">
<label>必須</label>
<select
 name="groups[<?=$gi?>][questions][<?=$qi?>][required]">
<option value="0"
 <?=empty($question['required'])
     ?'selected':''?>>
任意
</option>
<option value="1"
 <?=!empty($question['required'])
     ?'selected':''?>>
必須
</option>
</select>
</div>
</div>

<div class="options">
<label>選択肢</label>

<?php foreach (
    $question['options'] as $oi => $option
): ?>
<div class="grid2 option-row">
<input
 name="groups[<?=$gi?>][questions][<?=$qi?>][options][<?=$oi?>]"
 value="<?=h($option)?>">
<button type="button"
 onclick="this.parentElement.remove()">
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

<div class="actions">
<button type="button"
 onclick="addGroup()">
＋ グループを追加
</button>

<button class="primary">
保存して一覧へ
</button>

<a class="btn"
 href="?screen=list">
キャンセル
</a>
</div>
</form>

<script>
function reindex(){
 const groups=document.querySelectorAll(
   '#groups>[data-group]'
 );
 groups.forEach((g,gi)=>{
   g.querySelector('.question-head h2')
    .textContent='グループ '+(gi+1);

   const questions=g.querySelectorAll(
     '[data-question]'
   );

   questions.forEach((q,qi)=>{
     const number=q.querySelector(
       '.qnumber'
     );

     if(number){
       number.textContent='Q'+(gi+1)
         +'-'+(qi+1);
     }

     q.querySelectorAll('[name]').forEach(
       el=>{
         el.name=el.name.replace(
           /groups\[\d+\]/,
           'groups['+gi+']'
         ).replace(
           /questions\[\d+\]/,
           'questions['+qi+']'
         );
       }
     );
   });

   g.querySelectorAll('[name]').forEach(
     el=>{
       el.name=el.name.replace(
         /groups\[\d+\]/,
         'groups['+gi+']'
       );
     }
   );
 });
}

function removeQuestion(button){
 const q=button.closest(
   '[data-question]'
 );

 if(q) q.remove();

 reindex();
}

function removeGroup(button){
 const g=button.closest(
   '[data-group]'
 );

 if(g) g.remove();

 reindex();
}

function addOption(button){
 const q=button.closest(
   '[data-question]'
 );
 const options=q.querySelector(
   '.options'
 );
 const rows=options.querySelectorAll(
   '.option-row'
 );

 const div=document.createElement('div');
 div.className='grid2 option-row';

 div.innerHTML=
   '<input value="">'
   + '<button type="button">'
   + '削除'
   + '</button>';

 const input=div.querySelector('input');
 const remove=div.querySelector('button');

 remove.onclick=()=>div.remove();

 options.insertBefore(div,button);
 reindex();
}

function addQuestion(button){
 const g=button.closest(
   '[data-group]'
 );
 const gi=[
   ...document.querySelectorAll(
     '#groups>[data-group]'
   )
 ].indexOf(g);

 const qs=g.querySelector(
   '.questions'
 );
 const qi=qs.children.length;

 const box=document.createElement('div');
 box.className='question';
 box.setAttribute('data-question','');

 box.innerHTML=
 '<div class="question-head">'
 + '<span class="qnumber"></span>'
 + '<button type="button"'
 + ' onclick="removeQuestion(this)">'
 + '質問を削除'
 + '</button>'
 + '</div>'
 + '<input type="hidden"'
 + ' name="groups['+gi+'][questions]['+qi+'][id]"'
 + ' value="">'
 + '<div class="field">'
 + '<label>質問文</label>'
 + '<textarea required'
 + ' name="groups['+gi+'][questions]['+qi+'][text]"></textarea>'
 + '</div>'
 + '<div class="grid2">'
 + '<div class="field">'
 + '<label>回答形式</label>'
 + '<select name="groups['+gi+'][questions]['+qi+'][type]"'
 + ' onchange="toggleOptions(this)">'
 + '<option value="single">単一選択</option>'
 + '<option value="multiple">複数選択</option>'
 + '<option value="free">自由記述</option>'
 + '</select>'
 + '</div>'
 + '<div class="field">'
 + '<label>必須</label>'
 + '<select name="groups['+gi+'][questions]['+qi+'][required]">'
 + '<option value="0">任意</option>'
 + '<option value="1">必須</option>'
 + '</select>'
 + '</div>'
 + '</div>'
 + '<div class="options">'
 + '<label>選択肢</label>'
 + '<div class="grid2 option-row">'
 + '<input name="groups['+gi+'][questions]['+qi+'][options][0]"'
 + ' value="選択肢1">'
 + '<button type="button"'
 + ' onclick="this.parentElement.remove()">削除</button>'
 + '</div>'
 + '<button type="button" onclick="addOption(this)">'
 + '＋ 選択肢'
 + '</button>'
 + '</div>';

 qs.appendChild(box);
 reindex();
}

function addGroup(){
 const groups=document.getElementById(
   'groups'
 );
 const gi=groups.children.length;

 const box=document.createElement('div');
 box.className='group';
 box.setAttribute('data-group','');

 box.innerHTML=
 '<div class="question-head">'
 + '<h2>グループ '+(gi+1)+'</h2>'
 + '<button type="button"'
 + ' onclick="removeGroup(this)">'
 + 'グループを削除'
 + '</button>'
 + '</div>'
 + '<input type="hidden"'
 + ' name="groups['+gi+'][id]" value="">'
 + '<div class="field">'
 + '<label>グループタイトル</label>'
 + '<input name="groups['+gi+'][title]" value="">'
 + '</div>'
 + '<div class="questions"></div>'
 + '<button type="button"'
 + ' onclick="addQuestion(this)">'
 + '＋ 質問を追加'
 + '</button>';

 groups.appendChild(box);

 addQuestion(
   box.querySelector(
     'button:last-child'
   )
 );

 reindex();
}

function toggleOptions(select){
 const q=select.closest(
   '[data-question]'
 );

 const options=q.querySelector(
   '.options'
 );

 options.style.display=
   select.value==='free'
   ? 'none'
   : 'block';
}

document.querySelectorAll(
 '[data-question] select'
).forEach(
 el=>{
   if(el.name.endsWith('[type]')){
     toggleOptions(el);
   }
 }
);

reindex();
</script>
<?php

    return (string)ob_get_clean();
}

/* =========================================================
 * 15. プレビュー
 * ========================================================= */

function renderPreview(
    array $survey
): string {
    ob_start();
    ?>
<h1>プレビュー</h1>

<div class="card">
<h2><?=h($survey['title'])?></h2>

<?php if (
    $survey['description'] !== ''
): ?>
<p><?=nl2br(
    h($survey['description'])
)?></p>
<?php endif; ?>

<?php foreach (
    $survey['groups'] as $group
): ?>
<div class="group">
<h2><?=h($group['title'])?></h2>

<?php foreach (
    $group['questions'] as $question
): ?>
<div class="question">

<div class="question-head">
<span class="qnumber">
<?=h($question['number'])?>
</span>
<span class="badge">
<?=h(
    typeLabel(
        $question['type']
    )
)?>
</span>
</div>

<p>
<strong><?=h(
    $question['text']
)?></strong>

<?php if (
    $question['required']
): ?>
<span class="muted">（必須）</span>
<?php endif; ?>
</p>

<?php if (
    $question['type'] === 'single'
): ?>

<?php foreach (
    $question['options'] as $option
): ?>
<div class="answer-option">
<input type="radio" disabled>
<span><?=h($option)?></span>
</div>
<?php endforeach; ?>

<?php elseif (
    $question['type'] === 'multiple'
): ?>

<?php foreach (
    $question['options'] as $option
): ?>
<div class="answer-option">
<input type="checkbox" disabled>
<span><?=h($option)?></span>
</div>
<?php endforeach; ?>

<?php else: ?>

<textarea disabled
 placeholder="自由記述"></textarea>

<?php endif; ?>

</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
</div>

<div class="actions">
<a class="btn"
 href="?screen=edit&id=<?=rawurlencode(
     $survey['id']
 )?>">
編集へ戻る
</a>
</div>
<?php

    return (string)ob_get_clean();
}

/* =========================================================
 * 16. 回答
 * ========================================================= */

function renderAnswer(
    array $data,
    array $survey
): string {
    $draft = $_SESSION[
        'answer_draft'
    ] ?? [];

    if (!is_array($draft)) {
        $draft = [];
    }

    $errors = $_SESSION[
        'answer_errors'
    ] ?? [];

    unset(
        $_SESSION['answer_errors']
    );

    $customerId = getString(
        'customer'
    );

    $visible = visibleQuestionIds(
        $survey,
        $draft
    );

    $map = questionMap($survey);

    ob_start();
    ?>
<h1><?=h($survey['title'])?></h1>

<?php if (
    $survey['description'] !== ''
): ?>
<div class="card">
<p><?=nl2br(
    h($survey['description'])
)?></p>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert error">
<?=h(
    implode(
        "\n",
        $errors
    )
)?>
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

<?php foreach (
    $visible as $qid
): ?>
<?php
$q = $map[$qid] ?? null;
if (!$q) continue;
$value = $draft[$qid] ?? '';
?>
<div class="card">

<div class="question-head">
<span class="qnumber">
<?=h($q['number'])?>
</span>
</div>

<p>
<strong><?=h($q['text'])?></strong>
<?php if ($q['required']): ?>
<span class="muted">（必須）</span>
<?php endif; ?>
</p>

<?php if (
    $q['type'] === 'single'
): ?>

<?php foreach (
    $q['options'] as $option
): ?>
<label class="answer-option">
<input type="radio"
 name="answers[<?=h($q['id'])?>]"
 value="<?=h($option)?>"
 <?=((string)$value === $option)
     ? 'checked'
     : ''?>>
<span><?=h($option)?></span>
</label>
<?php endforeach; ?>

<?php elseif (
    $q['type'] === 'multiple'
): ?>

<?php
$values = is_array($value)
    ? $value
    : [];
?>

<?php foreach (
    $q['options'] as $option
): ?>
<label class="answer-option">
<input type="checkbox"
 name="answers[<?=h($q['id'])?>][]"
 value="<?=h($option)?>"
 <?=in_array(
     $option,
     $values,
     true
 )
     ? 'checked'
     : ''?>>
<span><?=h($option)?></span>
</label>
<?php endforeach; ?>

<?php else: ?>

<textarea
 name="answers[<?=h($q['id'])?>]"
 <?= $q['required']
     ? 'required'
     : ''?>><?=h(
    is_scalar($value)
        ? $value
        : ''
)?></textarea>

<?php endif; ?>

</div>
<?php endforeach; ?>

<div class="actions">
<button class="primary">
回答確認へ
</button>
</div>
</form>
<?php

    return (string)ob_get_clean();
}

function renderConfirm(
    array $data,
    array $survey
): string {
    $answers = $_SESSION[
        'answer_draft'
    ] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $map = questionMap($survey);

    ob_start();
    ?>
<h1>回答確認</h1>

<div class="card">
<?php foreach (
    $map as $qid => $question
): ?>
<?php
if (!array_key_exists(
    $qid,
    $answers
)) {
    continue;
}

$value = $answers[$qid];

if (is_array($value)) {
    $display = implode(
        '、',
        array_map(
            'strval',
            $value
        )
    );
} else {
    $display = (string)$value;
}

if (trim($display) === '') {
    continue;
}
?>

<div class="question">
<div class="qnumber">
<?=h($question['number'])?>
</div>

<p>
<strong><?=h(
    $question['text']
)?></strong>
</p>

<p><?=nl2br(
    h($display)
)?></p>
</div>
<?php endforeach; ?>
</div>

<div class="actions">
<a class="btn"
 href="?screen=answer&id=<?=rawurlencode(
     $survey['id']
 )?>">
修正する
</a>

<form method="post">
<input type="hidden"
 name="action"
 value="answer_submit">

<input type="hidden"
 name="surveyId"
 value="<?=h($survey['id'])?>">

<input type="hidden"
 name="customerId"
 value="<?=h(
     getString('customer')
)?>">

<button class="primary">
回答送信
</button>
</form>
</div>
<?php

    return (string)ob_get_clean();
}

function renderComplete(
    array $survey
): string {
    ob_start();
    ?>
<h1>回答完了</h1>

<div class="card">
<p>
アンケートへの回答を受け付けました。
</p>
<p class="muted">
ご回答ありがとうございました。
</p>
</div>
<?php

    return (string)ob_get_clean();
}

/* =========================================================
 * 17. kintone設定
 * ========================================================= */

function renderKintone(
    array $data
): string {
    $settings = $data['kintone'];

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
 placeholder="example または example.cybozu.com">
</div>

<div class="field">
<label>顧客管理アプリID</label>
<input name="appId"
 value="<?=h(
     $settings['appId']
 )?>"
 inputmode="numeric">
</div>

<div class="field">
<label>ログイン名</label>
<input name="username"
 value="<?=h(
     $settings['username']
 )?>">
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

<div class="field">
<label>
SSL証明書検証
</label>

<select name="sslVerify">
<option value="1"
 <?=$settings['sslVerify']
     ?'selected':''?>>
有効
</option>
<option value="0"
 <?=!$settings['sslVerify']
     ?'selected':''?>>
無効（POC）
</option>
</select>
</div>

<p class="muted">
kintoneパスワードは保存しません。
接続テスト・項目取得・顧客同期時に
その都度入力してください。
</p>

<button class="primary">
設定保存
</button>
</form>
</div>

<div class="card">
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
</div>

<div class="card">
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
</form>

<?php if (
    !empty($settings['fields'])
): ?>
<hr>
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
<tbody>
<?php foreach (
    $settings['fields'] as $code => $field
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

<div class="card">
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
</div>

<div class="card">
<h2>接続状態</h2>
<p>
<strong><?=h(
    $settings['connection']
)?></strong>
</p>

<?php if (
    $settings['connectionDetail'] !== ''
): ?>
<p class="muted">
<?=h(
    $settings['connectionDetail']
)?>
</p>
<?php endif; ?>
</div>
<?php

    return (string)ob_get_clean();
}

/* =========================================================
 * 18. メール設定
 * ========================================================= */

function renderMail(
    array $data
): string {
    $settings = $data['mailSettings'];

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
     $settings['server']
 )?>">
</div>

<div class="field">
<label>SMTPポート</label>
<input type="number"
 name="port"
 value="<?=h(
     $settings['port']
 )?>">
</div>

<div class="field">
<label>暗号化方式</label>
<select name="encryption">
<option value="ssl"
 <?=$settings['encryption']==='ssl'
     ?'selected':''?>>
SSL
</option>
<option value="tls"
 <?=$settings['encryption']==='tls'
     ?'selected':''?>>
TLS
</option>
<option value="none"
 <?=$settings['encryption']==='none'
     ?'selected':''?>>
なし
</option>
</select>
</div>

<div class="field">
<label>SMTP認証</label>
<select name="auth">
<option value="1"
 <?=$settings['auth']
     ?'selected':''?>>
あり
</option>
<option value="0"
 <?=!$settings['auth']
     ?'selected':''?>>
なし
</option>
</select>
</div>

<div class="field">
<label>SMTPユーザー名</label>
<input name="username"
 value="<?=h(
     $settings['username']
 )?>">
</div>

<div class="field">
<label>送信元メールアドレス</label>
<input type="email"
 name="fromEmail"
 value="<?=h(
     $settings['fromEmail']
 )?>">
</div>

<div class="field">
<label>送信元名</label>
<input name="fromName"
 value="<?=h(
     $settings['fromName']
 )?>">
</div>

<div class="field">
<label>返信先メールアドレス</label>
<input type="email"
 name="replyTo"
 value="<?=h(
     $settings['replyTo']
 )?>">
</div>

</div>

<p class="muted">
SMTPパスワードは保存しません。
メール送信・接続テスト時にその都度入力してください。
</p>

<button class="primary">
設定保存
</button>
</form>
</div>

<div class="card">
<h2>接続テスト・テストメール</h2>

<form method="post">
<input type="hidden"
 name="action"
 value="test_mail">

<div class="field">
<label>SMTPパスワード</label>
<input type="password"
 name="password"
 autocomplete="new-password"
 <?=!empty($settings['auth'])
     ? 'required'
     : ''?>>
</div>

<div class="field">
<label>テスト送信先</label>
<input type="email"
 name="testTo"
 required>
</div>

<button class="primary">
テストメール送信
</button>
</form>
</div>

<div class="card">
<h2>接続状態</h2>
<p>
<strong><?=h(
    $settings['connection']
)?></strong>
</p>

<?php if (
    $settings['connectionDetail'] !== ''
): ?>
<p class="muted">
<?=h(
    $settings['connectionDetail']
)?>
</p>
<?php endif; ?>
</div>
<?php

    return (string)ob_get_clean();
}

/* =========================================================
 * 19. 送信
 * ========================================================= */

function renderSend(
    array $data,
    array $survey
): string {
    $customers = $data['customers'];

    ob_start();
    ?>
<h1>顧客選択・メール送信</h1>

<div class="card">
<h2>対象アンケート</h2>
<p>
<strong><?=h(
    $survey['title']
)?></strong>
</p>
</div>

<div class="card">
<form method="post">
<input type="hidden"
 name="action"
 value="send_mail">

<input type="hidden"
 name="surveyId"
 value="<?=h($survey['id'])?>">

<div class="field">
<label>メール件名</label>
<input name="subject"
 value="アンケートのご案内"
 required>
</div>

<div class="field">
<label>メール本文</label>
<textarea name="body"
 required> {顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}</textarea>
</div>

<div class="field">
<label>SMTPパスワード</label>
<input type="password"
 name="password"
 autocomplete="new-password"
 <?=!empty(
     $data['mailSettings']['auth']
 )
     ? 'required'
     : ''?>>
</div>

<h2>顧客</h2>

<?php if (!$customers): ?>
<p>
顧客情報がありません。
kintone設定から同期してください。
</p>
<?php else: ?>

<?php foreach (
    $customers as $customer
): ?>
<label class="answer-option">
<input type="checkbox"
 name="customers[]"
 value="<?=h(
     $customer['id']
 )?>">
<span>
<?=h(
    $customer['name'] ?? ''
)?>
<?php if (
    !empty($customer['email'])
): ?>
（<?=h(
    $customer['email']
)?>）
<?php endif; ?>
</span>
</label>
<?php endforeach; ?>

<?php endif; ?>

<div class="actions">
<button class="primary">
一括送信
</button>
</div>
</form>
</div>

<div class="card">
<h2>送信履歴</h2>

<?php
$history = array_values(
    array_filter(
        $data['sendHistory'],
        static fn($item): bool =>
            ($item['surveyId'] ?? '')
            === $survey['id']
    )
);

if (!$history):
?>
<p>送信履歴はありません。</p>
<?php else: ?>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>顧客</th>
<th>メール</th>
<th>結果</th>
</tr>
</thead>
<tbody>
<?php foreach (
    array_reverse($history)
    as $item
): ?>
<tr>
<td><?=h(
    $item['date'] ?? ''
)?></td>
<td><?=h(
    $item['customer'] ?? ''
)?></td>
<td><?=h(
    $item['email'] ?? ''
)?></td>
<td><?=h(
    ($item['status'] ?? '') === 'sent'
        ? '成功'
        : '失敗'
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

/* =========================================================
 * 20. 集計
 * ========================================================= */

function renderAnalytics(
    array $data,
    array $survey
): string {
    $answers =
        $data['answers'][$survey['id']]
        ?? [];

    $answerCount = count($answers);

    $sentCustomers = [];

    foreach (
        $data['sendHistory'] as $history
    ) {
        if (
            ($history['surveyId'] ?? '')
            !== $survey['id']
        ) {
            continue;
        }

        if (
            ($history['status'] ?? '')
            !== 'sent'
        ) {
            continue;
        }

        $sentCustomers[
            (string)(
                $history['customerId']
                ?? ''
            )
        ] = true;
    }

    $sentCount = count(
        array_filter(
            array_keys($sentCustomers),
            static fn($id): bool =>
                $id !== ''
        )
    );

    $unregistered = count(
        array_filter(
            $answers,
            static fn($answer): bool =>
                empty(
                    $answer['customerId']
                )
        )
    );

    $rate = $sentCount > 0
        ? round(
            $answerCount
            / $sentCount
            * 100,
            1
        )
        : 0;

    ob_start();
    ?>
<h1>回答集計・分析</h1>

<div class="card">
<h2>対象アンケート</h2>
<p>
<strong><?=h(
    $survey['title']
)?></strong>
</p>
</div>

<div class="stats">
<div class="stat">
<span class="muted">
送信対象者数
</span>
<strong><?=h(
    $sentCount
)?></strong>
</div>

<div class="stat">
<span class="muted">
回答数
</span>
<strong><?=h(
    $answerCount
)?></strong>
</div>

<div class="stat">
<span class="muted">
未登録回答数
</span>
<strong><?=h(
    $unregistered
)?></strong>
</div>

<div class="stat">
<span class="muted">
回答率
</span>
<strong><?=h(
    $rate
)?>%</strong>
</div>
</div>

<div class="card">
<div class="actions">
<a class="btn"
 href="?screen=analytics&id=<?=rawurlencode(
     $survey['id']
 )?>&export=csv">
CSV出力
</a>

<a class="btn"
 href="?screen=analytics&id=<?=rawurlencode(
     $survey['id']
 )?>&export=pdf">
PDF出力
</a>
</div>
</div>

<?php if (
    $answerCount === 0
): ?>

<div class="card">
<p>
現在、回答データはありません
</p>
</div>

<?php else: ?>

<div class="card">
<h2>設問別集計</h2>

<?php foreach (
    allQuestions($survey) as $question
): ?>

<div class="question">
<div class="qnumber">
<?=h($question['number'])?>
</div>

<h3><?=h(
    $question['text']
)?></h3>

<?php if (
    $question['type'] === 'free'
): ?>

<p class="muted">
自由記述回答
</p>

<?php foreach (
    $answers as $answer
): ?>

<?php
$value =
    $answer['values'][
        $question['id']
    ] ?? '';
?>

<?php if (
    trim((string)$value) !== ''
): ?>

<p><?=nl2br(
    h((string)$value)
)?></p>
<hr>

<?php endif; ?>

<?php endforeach; ?>

<?php else: ?>

<?php
$countMap = [];

foreach (
    $question['options']
    as $option
) {
    $countMap[$option] = 0;
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
            if (isset(
                $countMap[$item]
            )) {
                $countMap[$item]++;
            }
        }
    } elseif (
        isset(
            $countMap[(string)$value]
        )
    ) {
        $countMap[
            (string)$value
        ]++;
    }
}
?>

<table>
<thead>
<tr>
<th>選択肢</th>
<th>回答数</th>
</tr>
</thead>
<tbody>
<?php foreach (
    $countMap as $option => $count
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
</div>

<div class="card">
<h2>個別回答</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>回答者</th>
<th>組織</th>
<th>回答</th>
</tr>
</thead>
<tbody>

<?php foreach (
    $answers as $answer
): ?>

<tr>
<td><?=h(
    $answer['date'] ?? ''
)?></td>

<td><?=h(
    $answer['customer']
    ?? '未登録回答者'
)?></td>

<td><?=h(
    $answer['org'] ?? ''
)?></td>

<td>
<?php foreach (
    $answer['values'] ?? []
    as $qid => $value
): ?>

<?php
$q =
    questionMap($survey)[$qid]
    ?? null;

if (!$q) continue;

$display = is_array($value)
    ? implode('、', $value)
    : (string)$value;
?>

<div>
<strong><?=h(
    $q['number']
)?></strong>
：
<?=h($display)?>
</div>

<?php endforeach; ?>
</td>
</tr>

<?php endforeach; ?>

</tbody>
</table>
</div>
</div>

<?php endif; ?>
<?php

    return (string)ob_get_clean();
}

/* =========================================================
 * 21. CSV
 * ========================================================= */

function outputCsv(
    array $data,
    string $surveyId
): never {
    $survey = surveyById(
        $data,
        $surveyId
    );

    if (!$survey) {
        http_response_code(404);
        exit(
            '対象アンケートが存在しません。'
        );
    }

    $answers =
        $data['answers'][$surveyId]
        ?? [];

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="survey-'
        . rawurlencode($surveyId)
        . '.csv"'
    );

    echo "\xEF\xBB\xBF";

    $fp = fopen(
        'php://output',
        'wb'
    );

    if ($fp === false) {
        http_response_code(500);
        exit(
            'CSV出力を開始できません。'
        );
    }

    fputcsv(
        $fp,
        [
            '回答日時',
            '回答者',
            '組織',
            '質問番号',
            '質問文',
            '回答',
        ]
    );

    $map = questionMap($survey);

    foreach ($answers as $answer) {
        foreach (
            ($answer['values'] ?? [])
            as $qid => $value
        ) {
            if (!isset($map[$qid])) {
                continue;
            }

            $question = $map[$qid];

            fputcsv(
                $fp,
                [
                    $answer['date'] ?? '',
                    $answer['customer']
                        ?? '',
                    $answer['org'] ?? '',
                    $question['number'],
                    $question['text'],
                    is_array($value)
                        ? implode(
                            '、',
                            $value
                        )
                        : (string)$value,
                ]
            );
        }
    }

    fclose($fp);
    exit;
}

/* =========================================================
 * 22. 簡易PDF出力
 * ========================================================= */

function outputPdf(
    array $data,
    string $surveyId
): never {
    /*
     * 外部PDFライブラリを要求しないPOC用。
     * 日本語はUTF-8をそのままPDF文字列へ入れず、
     * ASCII主体の概要情報を出力する。
     *
     * 実運用で日本語PDFが必要な場合は、
     * 別途PDFライブラリ導入を要件化する。
     */
    $survey = surveyById(
        $data,
        $surveyId
    );

    if (!$survey) {
        http_response_code(404);
        exit(
            '対象アンケートが存在しません。'
        );
    }

    $answers =
        $data['answers'][$surveyId]
        ?? [];

    $lines = [
        'Survey Report',
        'Survey ID: ' . $surveyId,
        'Answer Count: ' . count($answers),
        'Generated: ' . now(),
    ];

    $content =
        "BT\n"
        . "/F1 12 Tf\n"
        . "50 760 Td\n";

    foreach ($lines as $line) {
        $line = preg_replace(
            '/[^\x20-\x7E]/',
            '?',
            $line
        ) ?? '';

        $line = str_replace(
            ['\\', '(', ')'],
            ['\\\\', '\\(', '\\)'],
            $line
        );

        $content .=
            '(' . $line . ") Tj\n"
            . "0 -20 Td\n";
    }

    $content .= "ET";

    $objects = [];

    $objects[] =
        '<< /Type /Catalog /Pages 2 0 R >>';

    $objects[] =
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

    $objects[] =
        '<< /Type /Page /Parent 2 0 R '
        . '/MediaBox [0 0 612 792] '
        . '/Resources << /Font << '
        . '/F1 4 0 R >> >> '
        . '/Contents 5 0 R >>';

    $objects[] =
        '<< /Type /Font /Subtype /Type1 '
        . '/BaseFont /Helvetica >>';

    $objects[] =
        '<< /Length '
        . strlen($content)
        . " >>\nstream\n"
        . $content
        . "\nendstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $i => $object) {
        $offsets[$i + 1] = strlen($pdf);

        $pdf .=
            ($i + 1)
            . " 0 obj\n"
            . $object
            . "\nendobj\n";
    }

    $xref = strlen($pdf);

    $pdf .=
        "xref\n"
        . "0 "
        . (count($objects) + 1)
        . "\n"
        . "0000000000 65535 f \n";

    for (
        $i = 1;
        $i <= count($objects);
        $i++
    ) {
        $pdf .= sprintf(
            "%010d 00000 n \n",
            $offsets[$i]
        );
    }

    $pdf .=
        "trailer\n"
        . "<< /Size "
        . (count($objects) + 1)
        . " /Root 1 0 R >>\n"
        . "startxref\n"
        . $xref
        . "\n%%EOF";

    header(
        'Content-Type: application/pdf'
    );

    header(
        'Content-Disposition: attachment; filename="survey-'
        . rawurlencode($surveyId)
        . '.pdf"'
    );

    echo $pdf;
    exit;
}

/* =========================================================
 * 23. エラー表示
 * ========================================================= */

function renderError(
    Throwable $e
): string {
    $message = $e->getMessage();

    if ($message === '') {
        $message =
            '処理中にエラーが発生しました。';
    }

    /*
     * 内部例外情報をそのまま出さない。
     * パスワード、Authorization等を
     * メッセージに含めない。
     */
    $unsafePatterns = [
        '/password\s*[=:]/i',
        '/authorization\s*[=:]/i',
        '/x-cybozu-authorization/i',
        '/secret/i',
        '/token/i',
        '/session/i',
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

    ob_start();
    ?>
<h1>処理エラー</h1>

<div class="alert error">
<?=h($message)?>
</div>

<div class="actions">
<a class="btn"
 href="?screen=list">
アンケート一覧へ戻る
</a>
</div>
<?php

    return (string)ob_get_clean();
}

/* =========================================================
 * 24. 画面ルーティング
 * ========================================================= */

$data = null;
$content = '';

try {
    $data = readData();

    /*
     * 公開中かつ終了日時を超過したものだけ自動終了。
     */
    updateAutomaticStatus($data);

    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        === 'POST'
    ) {
        /*
         * CSRF検証は行わない。
         * 本POC要件どおり、
         * POST処理はactionで直接分岐する。
         */
        processPost($data);

        /*
         * 各actionは成功時にredirectTo()するため、
         * 通常ここには到達しない。
         */
        $content = renderError(
            new RuntimeException(
                '処理結果を確定できませんでした。'
            )
        );
    } else {
        $screen = getString(
            'screen',
            'list'
        );

        switch ($screen) {
            case 'list':
                $content = renderList(
                    $data
                );
                break;

            case 'edit':
                $id = getString('id');

                $survey = $id !== ''
                    ? surveyById(
                        $data,
                        $id
                    )
                    : null;

                if ($id !== '' && !$survey) {
                    throw new RuntimeException(
                        '対象アンケートが存在しません。'
                    );
                }

                $content = renderEdit(
                    $data,
                    $survey
                );
                break;

            case 'preview':
                $id = getString('id');
                $survey = surveyById(
                    $data,
                    $id
                );

                if (!$survey) {
                    throw new RuntimeException(
                        '対象アンケートが存在しません。'
                    );
                }

                $content = renderPreview(
                    $survey
                );
                break;

            case 'send':
                $id = getString('id');

                if (!validateId($id)) {
                    throw new InvalidArgumentException(
                        '送信対象アンケートIDが必要です。'
                    );
                }

                $survey = surveyById(
                    $data,
                    $id
                );

                if (!$survey) {
                    throw new RuntimeException(
                        '対象アンケートが存在しません。'
                    );
                }

                $content = renderSend(
                    $data,
                    $survey
                );
                break;

            case 'analytics':
                $id = getString('id');

                if (!validateId($id)) {
                    throw new InvalidArgumentException(
                        '集計対象アンケートIDが必要です。'
                    );
                }

                $survey = surveyById(
                    $data,
                    $id
                );

                if (!$survey) {
                    throw new RuntimeException(
                        '対象アンケートが存在しません。'
                    );
                }

                $export = getString(
                    'export'
                );

                if ($export === 'csv') {
                    outputCsv(
                        $data,
                        $id
                    );
                }

                if ($export === 'pdf') {
                    outputPdf(
                        $data,
                        $id
                    );
                }

                $content = renderAnalytics(
                    $data,
                    $survey
                );
                break;

            case 'kintone':
                $content = renderKintone(
                    $data
                );
                break;

            case 'mail':
                $content = renderMail(
                    $data
                );
                break;

            case 'answer':
                $id = getString('id');

                if (!validateId($id)) {
                    throw new InvalidArgumentException(
                        '回答対象アンケートIDが必要です。'
                    );
                }

                $survey = surveyById(
                    $data,
                    $id
                );

                if (!$survey
                    || !surveyAvailableForAnswer(
                        $survey
                    )) {
                    throw new RuntimeException(
                        '回答可能なアンケートではありません。'
                    );
                }

                /*
                 * 回答者画面には管理者ナビを表示しない。
                 */
                $content = renderAnswer(
                    $data,
                    $survey
                );

                echo renderLayout(
                    $content,
                    false
                );
                exit;

            case 'confirm':
                $id = getString('id');

                if (!validateId($id)) {
                    throw new InvalidArgumentException(
                        '回答対象アンケートIDが必要です。'
                    );
                }

                $survey = surveyById(
                    $data,
                    $id
                );

                if (!$survey
                    || !surveyAvailableForAnswer(
                        $survey
                    )) {
                    throw new RuntimeException(
                        '回答可能なアンケートではありません。'
                    );
                }

                $content = renderConfirm(
                    $data,
                    $survey
                );

                echo renderLayout(
                    $content,
                    false
                );
                exit;

            case 'complete':
                $id = getString('id');

                if (!validateId($id)) {
                    throw new InvalidArgumentException(
                        'アンケートIDが不正です。'
                    );
                }

                $survey = surveyById(
                    $data,
                    $id
                );

                if (!$survey) {
                    throw new RuntimeException(
                        '対象アンケートが存在しません。'
                    );
                }

                $content = renderComplete(
                    $survey
                );

                /*
                 * 回答完了後も管理者画面へ遷移しない。
                 */
                echo renderLayout(
                    $content,
                    false
                );
                exit;

            default:
                throw new InvalidArgumentException(
                    '指定された画面は利用できません。'
                );
        }
    }
} catch (Throwable $e) {
    /*
     * エラー表示処理自身が例外を起こさないよう、
     * 最小限の処理だけを行う。
     */
    try {
        $content = renderError($e);
    } catch (Throwable) {
        $content =
            '<h1>処理エラー</h1>'
            . '<div class="alert error">'
            . '処理中にエラーが発生しました。'
            . '</div>';
    }
}

echo renderLayout(
    $content,
    true
);

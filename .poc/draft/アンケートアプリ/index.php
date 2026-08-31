<?php
declare(strict_types=1);

/*
 * アンケートアプリ POC
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし / PHP mail()なし
 * 単一 index.php
 */

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const TZ = 'Asia/Tokyo';

date_default_timezone_set(TZ);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
$cookiePath = ($scriptDir === '.' || $scriptDir === '') ? '/' : rtrim($scriptDir, '/') . '/';
$isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';

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
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonOut(mixed $value): string
{
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
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
    if (!isset($_POST[$name]) || !is_array($_POST[$name])) {
        return [];
    }
    return $_POST[$name];
}

function redirectTo(string $screen, array $params = []): never
{
    $params = array_merge(['screen' => $screen], $params);
    $url = (string)($_SERVER['SCRIPT_NAME'] ?? 'index.php')
        . '?'
        . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    header('Location: ' . $url, true, 303);
    exit;
}

function flash(string $type, string $message, string $detail = ''): void
{
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
    return preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id) === 1;
}

function validEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validDateTime(string $value): bool
{
    if ($value === '') {
        return true;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
    return $dt !== false && $dt->format('Y-m-d\TH:i') === $value;
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
        if (!mkdir(DATA_DIR, 0770, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException('データ保存ディレクトリを作成できません。');
        }
    }

    $htaccess = DATA_DIR . DIRECTORY_SEPARATOR . '.htaccess';
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
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
        throw new InvalidArgumentException('不正なデータ名です。');
    }

    ensureDataDir();
    return DATA_DIR . DIRECTORY_SEPARATOR . $name . '.dat.php';
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
        throw new RuntimeException('データファイルを開けません。');
    }

    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        throw new RuntimeException('データファイルをロックできません。');
    }

    $contents = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($contents === false || $contents === '') {
        return defaultData();
    }

    $data = json_decode($contents, true);
    if (!is_array($data)) {
        throw new RuntimeException('保存データが破損しています。');
    }

    return array_replace_recursive(defaultData(), $data);
}

function saveApp(array $data): void
{
    $path = dataPath('app');
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('データをJSON化できません。');
    }

    $fp = @fopen($tmp, 'wb');
    if ($fp === false) {
        throw new RuntimeException('一時データファイルを作成できません。');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('データファイルをロックできません。');
        }

        if (fwrite($fp, $json) === false) {
            throw new RuntimeException('データを書き込めません。');
        }

        fflush($fp);
        if (function_exists('fsync')) {
            @fsync($fp);
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('データファイルを更新できません。');
        }
    } catch (Throwable $e) {
        @fclose($fp);
        @unlink($tmp);
        throw $e;
    }
}

/* =========================================================
 * アンケートデータ
 * ========================================================= */

function normalizeQuestion(array $q): array
{
    $type = in_array(($q['type'] ?? 'single'), ['single', 'multiple', 'free'], true)
        ? (string)$q['type']
        : 'single';

    $options = [];
    foreach (($q['options'] ?? []) as $option) {
        if (is_scalar($option)) {
            $option = trim((string)$option);
            if ($option !== '') {
                $options[] = $option;
            }
        }
    }

    if ($type === 'free') {
        $options = [];
    }

    return [
        'id' => validateId((string)($q['id'] ?? '')) ? (string)$q['id'] : uid('q'),
        'number' => (string)($q['number'] ?? ''),
        'text' => trim((string)($q['text'] ?? '')),
        'type' => $type,
        'required' => !empty($q['required']),
        'options' => array_values($options),
        'branches' => is_array($q['branches'] ?? null) ? $q['branches'] : [],
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
            'id' => validateId((string)($group['id'] ?? '')) ? (string)$group['id'] : uid('g'),
            'title' => trim((string)($group['title'] ?? '')),
            'questions' => $questions,
        ];
    }

    $survey = [
        'id' => validateId((string)($survey['id'] ?? '')) ? (string)$survey['id'] : uid('survey'),
        'createdAt' => (string)($survey['createdAt'] ?? date('Y-m-d')),
        'updatedAt' => (string)($survey['updatedAt'] ?? date('Y-m-d')),
        'title' => trim((string)($survey['title'] ?? '')),
        'description' => trim((string)($survey['description'] ?? '')),
        'startAt' => (string)($survey['startAt'] ?? ''),
        'endAt' => (string)($survey['endAt'] ?? ''),
        'status' => in_array(
            ($survey['status'] ?? 'draft'),
            ['draft', 'published', 'stopped', 'ended'],
            true
        ) ? (string)$survey['status'] : 'draft',
        'numbering' => ($survey['numbering'] ?? 'global') === 'group' ? 'group' : 'global',
        'groups' => $groups,
    ];

    renumberSurvey($survey);
    return $survey;
}

function renumberSurvey(array &$survey): void
{
    $global = 1;

    foreach ($survey['groups'] as $gi => &$group) {
        foreach ($group['questions'] as $qi => &$question) {
            if ($survey['numbering'] === 'group') {
                $question['number'] = 'Q' . ($gi + 1) . '-' . ($qi + 1);
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
    $i = surveyIndex($data, $id);
    return $i >= 0 ? $data['surveys'][$i] : null;
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

        $end = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $endAt);
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

function surveyAvailableForAnswer(array $survey): bool
{
    if (($survey['status'] ?? '') !== 'published') {
        return false;
    }

    $now = new DateTimeImmutable();

    if (!empty($survey['startAt'])) {
        $start = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $survey['startAt']);
        if ($start !== false && $now < $start) {
            return false;
        }
    }

    if (!empty($survey['endAt'])) {
        $end = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $survey['endAt']);
        if ($end !== false && $now > $end) {
            return false;
        }
    }

    return true;
}

/* =========================================================
 * 条件分岐・回答検証
 * ========================================================= */

function visibleQuestionIds(array $survey, array $answers): array
{
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

            $answer = $answers[$parent['id']] ?? null;
            if ($answer === null || $answer === '') {
                continue;
            }

            $target = $branches[(string)$answer] ?? null;
            if ($target === null || $target === '') {
                continue;
            }

            if ($target === $question['id']) {
                $show = true;
                break;
            }

            $targets = array_values($branches);
            if (in_array($question['id'], $targets, true)) {
                $show = false;
            }
        }

        if ($show) {
            $visible[] = $question['id'];
        }
    }

    return array_values(array_unique($visible));
}

function validateAnswers(array $survey, array $answers): array
{
    $errors = [];
    $map = questionMap($survey);
    $visible = visibleQuestionIds($survey, $answers);

    foreach ($visible as $questionId) {
        if (!isset($map[$questionId])) {
            continue;
        }

        $question = $map[$questionId];
        $value = $answers[$questionId] ?? '';

        $empty = is_array($value)
            ? count($value) === 0
            : trim((string)$value) === '';

        if (!empty($question['required']) && $empty) {
            $errors[] = $question['number'] . '「' . $question['text'] . '」は必須です。';
            continue;
        }

        if ($empty) {
            continue;
        }

        if ($question['type'] === 'single') {
            if (!in_array((string)$value, $question['options'], true)) {
                $errors[] = $question['number'] . 'の選択値が不正です。';
            }
        } elseif ($question['type'] === 'multiple') {
            if (!is_array($value)) {
                $errors[] = $question['number'] . 'の回答形式が不正です。';
                continue;
            }

            foreach ($value as $item) {
                if (!in_array((string)$item, $question['options'], true)) {
                    $errors[] = $question['number'] . 'の選択値が不正です。';
                    break;
                }
            }
        }
    }

    return $errors;
}

function answerUrl(string $surveyId, ?string $customerId = null): string
{
    if (!validateId($surveyId)) {
        throw new InvalidArgumentException('アンケートIDが不正です。');
    }

    $scheme = !empty($_SERVER['HTTPS'])
        && strtolower((string)$_SERVER['HTTPS']) !== 'off'
        ? 'https'
        : 'http';

    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');

    $url = $scheme . '://' . $host . $script
        . '?screen=answer&id=' . rawurlencode($surveyId);

    if ($customerId !== null && validateId($customerId)) {
        $url .= '&customer=' . rawurlencode($customerId);
    }

    return $url;
}

/* =========================================================
 * HTTPストリーム通信
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
        throw new InvalidArgumentException('HTTPS URLのみ許可されています。');
    }

    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        throw new InvalidArgumentException('接続先URLが不正です。');
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
        if (!preg_match('/^[^:\s]+:\d{1,5}$/', $proxy)) {
            throw new InvalidArgumentException('Proxyはhost:port形式で指定してください。');
        }

        $context['http']['proxy'] = 'tcp://' . $proxy;
        $context['http']['request_fulluri'] = true;
    }

    $ctx = stream_context_create($context);

    $fp = @fopen($url, 'rb', false, $ctx);
    if ($fp === false) {
        throw new RuntimeException('外部サービスへ接続できません。接続先またはネットワーク設定を確認してください。');
    }

    stream_set_timeout($fp, $timeout);

    $responseBody = stream_get_contents($fp);
    $meta = stream_get_meta_data($fp);
    fclose($fp);

    if ($responseBody === false) {
        return [
            'ok' => false,
            'category' => 'response_error',
            'status' => 0,
            'body' => '',
            'headers' => [],
            'error' => 'レスポンスを取得できませんでした。',
        ];
    }

    $headersOut = [];
    $status = 0;

    foreach (($meta['wrapper_data'] ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) {
            $status = (int)$m[1];
        } elseif (str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $headersOut[strtolower(trim($key))] = trim($value);
        }
    }

    if (!empty($meta['timed_out'])) {
        return [
            'ok' => false,
            'category' => 'timeout',
            'status' => $status,
            'body' => $responseBody,
            'headers' => $headersOut,
            'error' => '外部サービスへの通信がタイムアウトしました。',
        ];
    }

    if ($status >= 300 && $status < 400) {
        return [
            'ok' => false,
            'category' => 'redirect',
            'status' => $status,
            'body' => $responseBody,
            'headers' => $headersOut,
            'error' => '外部サービスからリダイレクト応答が返されました。',
        ];
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'category' => ($status >= 200 && $status < 300) ? 'success' : 'http_error',
        'status' => $status,
        'body' => $responseBody,
        'headers' => $headersOut,
        'error' => ($status >= 200 && $status < 300) ? '' : 'HTTPエラーが返されました。',
    ];
}

/* =========================================================
 * kintone
 * ========================================================= */

function normalizeKintoneHost(string $input): string
{
    $input = trim($input);
    $input = preg_replace('#^https?://#i', '', $input);
    $input = preg_replace('#/.*$#', '', $input);
    $input = trim((string)$input);

    if (!preg_match('/^[A-Za-z0-9.-]+$/', $input)) {
        throw new InvalidArgumentException('kintoneサブドメインが不正です。');
    }

    return str_ends_with($input, '.cybozu.com')
        ? $input
        : $input . '.cybozu.com';
}

function kintoneAuth(string $username, string $password): string
{
    if ($username === '' || $password === '') {
        throw new InvalidArgumentException('kintoneログイン名とパスワードを入力してください。');
    }

    return base64_encode($username . ':' . $password);
}

function kintoneRequest(
    array $settings,
    string $path,
    string $method,
    string $password,
    ?array $payload = null
): array {
    $host = normalizeKintoneHost((string)$settings['subdomain']);
    $appId = (string)$settings['appId'];

    if (!ctype_digit($appId) || (int)$appId < 1) {
        throw new InvalidArgumentException('顧客管理アプリIDが不正です。');
    }

    $url = 'https://' . $host . $path;

    $headers = [
        'X-Cybozu-Authorization: ' . kintoneAuth(
            (string)$settings['username'],
            $password
        ),
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: SurveyPOC/1.0',
    ];

    return httpRequest(
        $url,
        $method,
        $headers,
        $payload === null ? null : jsonOut($payload),
        20,
        !empty($settings['sslVerify']),
        (($settings['proxy'] ?? '') !== '') ? (string)$settings['proxy'] : null
    );
}

function kintoneErrorMessage(array $response): string
{
    $body = json_decode((string)($response['body'] ?? ''), true);

    if (is_array($body)) {
        $code = (string)($body['code'] ?? '');
        $message = (string)($body['message'] ?? '');

        if ($code !== '' || $message !== '') {
            return 'kintoneエラー'
                . ($code !== '' ? ' [' . $code . ']' : '')
                . ($message !== '' ? ': ' . $message : '');
        }
    }

    return (string)($response['error'] ?? 'kintone通信に失敗しました。');
}

function kintoneTest(array $settings, string $password): array
{
    return kintoneRequest(
        $settings,
        '/k/v1/app.json?id=' . (int)$settings['appId'],
        'GET',
        $password
    );
}

function kintoneFields(array $settings, string $password): array
{
    return kintoneRequest(
        $settings,
        '/k/v1/app/form/fields.json?app=' . (int)$settings['appId'],
        'GET',
        $password
    );
}

function kintoneRecords(array $settings, string $password): array
{
    return kintoneRequest(
        $settings,
        '/k/v1/records.json?app=' . (int)$settings['appId'] . '&totalCount=true',
        'GET',
        $password
    );
}

function syncCustomersFromKintone(array $settings, string $password): array
{
    $response = kintoneRecords($settings, $password);

    if (!$response['ok']) {
        throw new RuntimeException(kintoneErrorMessage($response));
    }

    $json = json_decode((string)$response['body'], true);

    if (!is_array($json) || !is_array($json['records'] ?? null)) {
        throw new RuntimeException('kintoneの顧客レコードを取得できませんでした。');
    }

    $mapping = $settings['mappings'] ?? [];
    $customers = [];

    foreach ($json['records'] as $record) {
        if (!is_array($record)) {
            continue;
        }

        $get = static function (string $code) use ($record): string {
            if ($code === '') {
                return '';
            }

            $value = $record[$code]['value'] ?? '';
            return is_scalar($value) ? (string)$value : '';
        };

        $address = [];
        foreach (($mapping['address'] ?? []) as $code) {
            $value = $get((string)$code);
            if ($value !== '') {
                $address[] = $value;
            }
        }

        $email = $get((string)($mapping['email'] ?? ''));
        if ($email === '' || !validEmail($email)) {
            continue;
        }

        $name = $get((string)($mapping['name'] ?? ''));
        if ($name === '') {
            $name = '氏名未設定';
        }

        $recordId = $get('$id');

        $customers[] = [
            'id' => $recordId !== '' ? 'k-' . $recordId : uid('customer'),
            'org' => $get((string)($mapping['org'] ?? '')),
            'name' => $name,
            'email' => $email,
            'department' => $get((string)($mapping['department'] ?? '')),
            'phone' => $get((string)($mapping['phone'] ?? '')),
            'address' => implode(' ', $address),
            'lastSent' => '',
            'sendCount' => 0,
            'answerStatus' => 'unsent',
            'kintone' => true,
        ];
    }

    return $customers;
}
/* =========================================================
 * SMTP
 * ========================================================= */

function smtpRead($fp, int $timeout = 20): array
{
    stream_set_timeout($fp, $timeout);
    $text = '';

    while (($line = fgets($fp, 8192)) !== false) {
        $text .= $line;

        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    if ($text === '') {
        $meta = stream_get_meta_data($fp);

        return [
            'ok' => false,
            'code' => 0,
            'text' => !empty($meta['timed_out'])
                ? 'タイムアウト'
                : '応答を取得できません。',
        ];
    }

    return [
        'ok' => preg_match('/^[2-3]\d\d/', $text) === 1,
        'code' => (int)substr($text, 0, 3),
        'text' => trim($text),
    ];
}

function smtpWrite($fp, string $command): void
{
    $data = $command . "\r\n";

    if (fwrite($fp, $data) === false) {
        throw new RuntimeException('SMTPへデータを送信できません。');
    }
}

function smtpExpect($fp, array $codes, int $timeout = 20): array
{
    $response = smtpRead($fp, $timeout);

    if (!$response['ok'] || !in_array($response['code'], $codes, true)) {
        throw new RuntimeException(
            'SMTPエラー（' . $response['code'] . '）が返されました。'
        );
    }

    return $response;
}

function smtpOpen(array $settings)
{
    $server = trim((string)($settings['server'] ?? ''));
    $port = (int)($settings['port'] ?? 0);
    $encryption = (string)($settings['encryption'] ?? 'none');

    if ($server === '') {
        throw new InvalidArgumentException('SMTPサーバを入力してください。');
    }

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException('SMTPポートが不正です。');
    }

    if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
        throw new InvalidArgumentException('暗号化方式が不正です。');
    }

    $host = $encryption === 'ssl'
        ? 'ssl://' . $server
        : $server;

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        'tcp://' . $host . ':' . $port,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT
    );

    if ($fp === false) {
        throw new RuntimeException('SMTPサーバへ接続できません。');
    }

    stream_set_timeout($fp, 20);

    try {
        smtpExpect($fp, [220]);

        $hostname = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
        smtpWrite($fp, 'EHLO ' . $hostname);
        smtpExpect($fp, [250]);

        if ($encryption === 'tls') {
            smtpWrite($fp, 'STARTTLS');
            smtpExpect($fp, [220]);

            $crypto = stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException('SMTP TLS接続を確立できません。');
            }

            smtpWrite($fp, 'EHLO ' . $hostname);
            smtpExpect($fp, [250]);
        }

        $auth = !empty($settings['auth']);
        if ($auth) {
            $username = (string)($settings['username'] ?? '');
            if ($username === '') {
                throw new InvalidArgumentException('SMTPユーザー名を入力してください。');
            }

            $password = func_get_arg(1);
            if (!is_string($password) || $password === '') {
                throw new InvalidArgumentException('SMTPパスワードを入力してください。');
            }

            smtpWrite($fp, 'AUTH LOGIN');
            smtpExpect($fp, [334]);

            smtpWrite($fp, base64_encode($username));
            smtpExpect($fp, [334]);

            smtpWrite($fp, base64_encode($password));
            smtpExpect($fp, [235]);
        }

        return $fp;
    } catch (Throwable $e) {
        @fclose($fp);
        throw $e;
    }
}

function smtpEncodeHeader(string $value): string
{
    if ($value === '') {
        return '';
    }

    if (preg_match('/[^\x20-\x7E]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    return $value;
}

function smtpDotStuff(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = str_replace("\n", "\r\n", $body);
    return preg_replace('/^\./m', '..', $body) ?? $body;
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
            'message' => 'メールアドレスが不正です。',
        ];
    }

    $from = trim((string)($settings['fromEmail'] ?? ''));
    if (!validEmail($from)) {
        throw new InvalidArgumentException('送信元メールアドレスが不正です。');
    }

    $fp = null;

    try {
        /*
         * smtpOpen()へ秘密情報を渡すため、関数内で明示的に
         * 2番目の引数を受け取る構造にする。
         */
        $server = trim((string)($settings['server'] ?? ''));
        $port = (int)($settings['port'] ?? 0);
        $encryption = (string)($settings['encryption'] ?? 'none');

        $host = $encryption === 'ssl' ? 'ssl://' . $server : $server;

        $errno = 0;
        $errstr = '';

        $fp = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $errstr,
            20,
            STREAM_CLIENT_CONNECT
        );

        if ($fp === false) {
            throw new RuntimeException('SMTPサーバへ接続できません。');
        }

        stream_set_timeout($fp, 20);
        smtpExpect($fp, [220]);

        $ehlo = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
        smtpWrite($fp, 'EHLO ' . $ehlo);
        smtpExpect($fp, [250]);

        if ($encryption === 'tls') {
            smtpWrite($fp, 'STARTTLS');
            smtpExpect($fp, [220]);

            if (stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            ) !== true) {
                throw new RuntimeException('SMTP TLS接続を確立できません。');
            }

            smtpWrite($fp, 'EHLO ' . $ehlo);
            smtpExpect($fp, [250]);
        }

        if (!empty($settings['auth'])) {
            if ((string)($settings['username'] ?? '') === '' || $password === '') {
                throw new InvalidArgumentException('SMTP認証情報を入力してください。');
            }

            smtpWrite($fp, 'AUTH LOGIN');
            smtpExpect($fp, [334]);

            smtpWrite($fp, base64_encode((string)$settings['username']));
            smtpExpect($fp, [334]);

            smtpWrite($fp, base64_encode($password));
            smtpExpect($fp, [235]);
        }

        smtpWrite($fp, 'MAIL FROM:<' . $from . '>');
        smtpExpect($fp, [250]);

        smtpWrite($fp, 'RCPT TO:<' . $to . '>');
        smtpExpect($fp, [250, 251]);

        smtpWrite($fp, 'DATA');
        smtpExpect($fp, [354]);

        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . smtpEncodeHeader((string)($settings['fromName'] ?? ''))
            . ' <' . $from . '>';
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: ' . smtpEncodeHeader($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        $replyTo = trim((string)($settings['replyTo'] ?? ''));
        if ($replyTo !== '') {
            if (!validEmail($replyTo)) {
                throw new InvalidArgumentException('返信先メールアドレスが不正です。');
            }
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $message = implode("\r\n", $headers)
            . "\r\n\r\n"
            . smtpDotStuff($body)
            . "\r\n.";

        if (fwrite($fp, $message . "\r\n") === false) {
            throw new RuntimeException('メール本文を送信できません。');
        }

        smtpExpect($fp, [250]);

        smtpWrite($fp, 'QUIT');
        @smtpRead($fp, 20);
        @fclose($fp);

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
            'message' => $e->getMessage(),
        ];
    }
}

function encryptSecret(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    /*
     * 外部サービス用パスワードは復号して接続に使う必要があるため、
     * 一方向ハッシュではなく、PHP OpenSSLが利用可能な環境では
     * authenticated encryptionを利用する。
     */
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('秘密情報を安全に保存するためのOpenSSLが利用できません。');
    }

    $key = hash('sha256', __FILE__ . '|' . PHP_VERSION, true);
    $iv = random_bytes(12);
    $tag = '';

    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cipher === false) {
        throw new RuntimeException('秘密情報を暗号化できません。');
    }

    return base64_encode($iv . $tag . $cipher);
}

function decryptSecret(string $encoded): string
{
    if ($encoded === '') {
        return '';
    }

    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('秘密情報を復号するためのOpenSSLが利用できません。');
    }

    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 29) {
        throw new RuntimeException('保存された秘密情報を復号できません。');
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);

    $key = hash('sha256', __FILE__ . '|' . PHP_VERSION, true);

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($plain === false) {
        throw new RuntimeException('保存された秘密情報を復号できません。');
    }

    return $plain;
}

/* =========================================================
 * POST処理
 * ========================================================= */

function validateSurveyInput(): void
{
    $title = postString('title');

    if ($title === '') {
        throw new InvalidArgumentException('タイトルを入力してください。');
    }

    if (mb_strlen($title) > 200) {
        throw new InvalidArgumentException('タイトルは200文字以内で入力してください。');
    }

    if (mb_strlen(postString('description')) > 5000) {
        throw new InvalidArgumentException('説明は5000文字以内で入力してください。');
    }

    $startAt = postString('startAt');
    $endAt = postString('endAt');

    if (!validDateTime($startAt) || !validDateTime($endAt)) {
        throw new InvalidArgumentException('日時の形式が不正です。');
    }

    if ($startAt !== '' && $endAt !== '' && $startAt > $endAt) {
        throw new InvalidArgumentException('終了日時は開始日時以降にしてください。');
    }
}

function buildSurveyFromPost(array $data): array
{
    validateSurveyInput();

    $id = postString('id');
    $old = $id !== '' ? surveyById($data, $id) : null;

    if ($id !== '' && !$old) {
        throw new RuntimeException('アンケートが存在しません。');
    }

    $groupsPost = postArray('groups');
    $groups = [];

    foreach ($groupsPost as $groupPost) {
        if (!is_array($groupPost)) {
            continue;
        }

        $questions = [];

        foreach (($groupPost['questions'] ?? []) as $questionPost) {
            if (!is_array($questionPost)) {
                continue;
            }

            $text = trim((string)($questionPost['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $options = [];
            foreach (($questionPost['options'] ?? []) as $option) {
                $option = trim((string)$option);
                if ($option !== '') {
                    $options[] = $option;
                }
            }

            $type = in_array(
                ($questionPost['type'] ?? 'single'),
                ['single', 'multiple', 'free'],
                true
            ) ? (string)$questionPost['type'] : 'single';

            $questions[] = [
                'id' => validateId((string)($questionPost['id'] ?? ''))
                    ? (string)$questionPost['id']
                    : uid('q'),
                'text' => $text,
                'type' => $type,
                'required' => (string)($questionPost['required'] ?? '0') === '1',
                'options' => $type === 'free' ? [] : $options,
                'branches' => is_array($questionPost['branches'] ?? null)
                    ? $questionPost['branches']
                    : [],
            ];
        }

        $groups[] = [
            'id' => validateId((string)($groupPost['id'] ?? ''))
                ? (string)$groupPost['id']
                : uid('g'),
            'title' => trim((string)($groupPost['title'] ?? '')),
            'questions' => $questions,
        ];
    }

    $survey = [
        'id' => $old ? $old['id'] : uid('survey'),
        'createdAt' => $old ? $old['createdAt'] : date('Y-m-d'),
        'updatedAt' => date('Y-m-d'),
        'title' => postString('title'),
        'description' => postString('description'),
        'startAt' => postString('startAt'),
        'endAt' => postString('endAt'),
        'status' => $old ? $old['status'] : 'draft',
        'numbering' => postString('numbering', 'global') === 'group'
            ? 'group'
            : 'global',
        'groups' => $groups,
    ];

    return normalizeSurvey($survey);
}

function processPost(array &$data): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $action = postString('action');

    if ($action === '') {
        return;
    }

    switch ($action) {
        case 'save_survey':
            $survey = buildSurveyFromPost($data);
            $index = surveyIndex($data, $survey['id']);

            if ($index >= 0) {
                $data['surveys'][$index] = $survey;
            } else {
                $data['surveys'][] = $survey;
            }

            saveApp($data);
            flash('success', 'アンケートを保存しました。');
            redirectTo('list');

        case 'transition':
            $id = postString('id');
            $to = postString('to');
            $index = surveyIndex($data, $id);

            if ($index < 0) {
                throw new RuntimeException('アンケートが存在しません。');
            }

            $from = (string)$data['surveys'][$index]['status'];

            if (!canTransition($from, $to)) {
                throw new InvalidArgumentException('指定された状態遷移は許可されていません。');
            }

            $data['surveys'][$index]['status'] = $to;
            $data['surveys'][$index]['updatedAt'] = date('Y-m-d');
            saveApp($data);

            flash('success', '状態を変更しました。');
            redirectTo('list');

        case 'duplicate':
            $id = postString('id');
            $survey = surveyById($data, $id);

            if (!$survey) {
                throw new RuntimeException('アンケートが存在しません。');
            }

            $survey['id'] = uid('survey');
            $survey['title'] .= '（コピー）';
            $survey['createdAt'] = date('Y-m-d');
            $survey['updatedAt'] = date('Y-m-d');
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

            flash('success', 'アンケートを複製しました。');
            redirectTo('list');

        case 'delete_survey':
            $id = postString('id');
            $index = surveyIndex($data, $id);

            if ($index < 0) {
                throw new RuntimeException('アンケートが存在しません。');
            }

            if (($data['surveys'][$index]['status'] ?? '') === 'published') {
                throw new InvalidArgumentException('公開中のアンケートは削除できません。');
            }

            array_splice($data['surveys'], $index, 1);
            unset($data['answers'][$id]);

            saveApp($data);
            flash('success', 'アンケートを削除しました。');
            redirectTo('list');

        case 'answer_confirm':
            $surveyId = postString('surveyId');
            $survey = surveyById($data, $surveyId);

            if (!$survey || !surveyAvailableForAnswer($survey)) {
                throw new RuntimeException('回答可能なアンケートではありません。');
            }

            $answers = postArray('answers');
            $errors = validateAnswers($survey, $answers);

            if ($errors) {
                $_SESSION['answer_errors'] = $errors;
                $_SESSION['answer_draft'] = $answers;
                redirectTo('answer', ['id' => $surveyId]);
            }

            $_SESSION['answer_draft'] = $answers;
            $_SESSION['answer_survey'] = $surveyId;
            redirectTo('confirm', ['id' => $surveyId]);

        case 'answer_submit':
            $surveyId = postString('surveyId');
            $survey = surveyById($data, $surveyId);

            if (!$survey || !surveyAvailableForAnswer($survey)) {
                throw new RuntimeException('回答可能なアンケートではありません。');
            }

            $answers = $_SESSION['answer_draft'] ?? [];
            if (!is_array($answers)) {
                $answers = [];
            }

            $errors = validateAnswers($survey, $answers);
            if ($errors) {
                $_SESSION['answer_errors'] = $errors;
                redirectTo('answer', ['id' => $surveyId]);
            }

            $customerId = postString('customerId');
            if (!validateId($customerId)) {
                $customerId = '';
            }

            $customer = null;

            foreach ($data['customers'] as $candidate) {
                if (($candidate['id'] ?? '') === $customerId) {
                    $customer = $candidate;
                    break;
                }
            }

            $data['answers'][$surveyId] ??= [];

            $data['answers'][$surveyId][] = [
                'id' => uid('answer'),
                'customerId' => $customerId,
                'customer' => $customer['name'] ?? '未登録回答者',
                'org' => $customer['org'] ?? '',
                'date' => now(),
                'values' => $answers,
            ];

            if ($customer) {
                foreach ($data['customers'] as &$candidate) {
                    if (($candidate['id'] ?? '') === $customerId) {
                        $candidate['answerStatus'] = 'answered';
                        break;
                    }
                }
                unset($candidate);
            }

            saveApp($data);

            unset(
                $_SESSION['answer_draft'],
                $_SESSION['answer_errors'],
                $_SESSION['answer_survey']
            );

            redirectTo('complete', ['id' => $surveyId]);

        case 'save_kintone':
            $subdomain = postString('subdomain');
            if ($subdomain !== '') {
                normalizeKintoneHost($subdomain);
            }

            $appId = postString('appId');
            if ($appId !== '' && (!ctype_digit($appId) || (int)$appId < 1)) {
                throw new InvalidArgumentException('顧客管理アプリIDが不正です。');
            }

            $data['kintone']['subdomain'] = $subdomain;
            $data['kintone']['appId'] = $appId;
            $data['kintone']['username'] = postString('username');
            $data['kintone']['proxy'] = postString('proxy');
            $data['kintone']['sslVerify'] = postString('sslVerify', '1') === '1';

            if ($data['kintone']['proxy'] !== ''
                && !preg_match('/^[^:\s]+:\d{1,5}$/', $data['kintone']['proxy'])) {
                throw new InvalidArgumentException('Proxyはhost:port形式で指定してください。');
            }

            $password = postString('password');
            if ($password !== '') {
                $data['kintone']['password'] = encryptSecret($password);
            }

            saveApp($data);
            flash('success', 'kintone設定を保存しました。');
            redirectTo('kintone');

        case 'test_kintone':
            $password = postString('password');
            if ($password === '' && !empty($data['kintone']['password'])) {
                $password = decryptSecret((string)$data['kintone']['password']);
            }

            $response = kintoneTest($data['kintone'], $password);

            if (!$response['ok']) {
                $data['kintone']['connection'] = '接続できません';
                $data['kintone']['connectionDetail'] = kintoneErrorMessage($response);
                saveApp($data);

                throw new RuntimeException($data['kintone']['connectionDetail']);
            }

            $data['kintone']['connection'] = '接続確認済み';
            $data['kintone']['connectionDetail'] = 'kintoneへの接続と認証に成功しました。';
            saveApp($data);

            flash('success', 'kintone接続テストに成功しました。');
            redirectTo('kintone');

        case 'fetch_kintone_fields':
            $password = postString('password');
            if ($password === '' && !empty($data['kintone']['password'])) {
                $password = decryptSecret((string)$data['kintone']['password']);
            }

            $response = kintoneFields($data['kintone'], $password);

            if (!$response['ok']) {
                throw new RuntimeException(kintoneErrorMessage($response));
            }

            $json = json_decode((string)$response['body'], true);
            if (!is_array($json) || !is_array($json['properties'] ?? null)) {
                throw new RuntimeException('kintone項目一覧を取得できませんでした。');
            }

            $data['kintone']['fields'] = [];

            foreach ($json['properties'] as $code => $field) {
                if (!is_array($field)) {
                    continue;
                }

                $data['kintone']['fields'][(string)$code] = [
                    'label' => (string)($field['label'] ?? ''),
                    'type' => (string)($field['type'] ?? ''),
                ];
            }

            saveApp($data);
            flash('success', 'kintone項目一覧を再取得しました。');
            redirectTo('kintone');

        case 'save_kintone_mapping':
            foreach (['org', 'name', 'email', 'department', 'phone'] as $key) {
                $code = postString('map_' . $key);
                if ($code !== '' && !isset($data['kintone']['fields'][$code])) {
                    throw new InvalidArgumentException('kintone項目マッピングが不正です。');
                }
                $data['kintone']['mappings'][$key] = $code;
            }

            $addresses = postArray('map_address');
            $validAddresses = [];

            foreach ($addresses as $code) {
                $code = (string)$code;
                if (isset($data['kintone']['fields'][$code])) {
                    $validAddresses[] = $code;
                }
            }

            $data['kintone']['mappings']['address'] = array_values(array_unique($validAddresses));
            saveApp($data);

            flash('success', 'kintone項目マッピングを保存しました。');
            redirectTo('kintone');

        case 'sync_kintone':
            $password = postString('password');
            if ($password === '' && !empty($data['kintone']['password'])) {
                $password = decryptSecret((string)$data['kintone']['password']);
            }

            $data['customers'] = syncCustomersFromKintone(
                $data['kintone'],
                $password
            );

            saveApp($data);
            flash('success', count($data['customers']) . '件の顧客情報を同期しました。');
            redirectTo('kintone');

        case 'save_mail':
            $server = postString('server');
            $port = (int)postString('port', '587');
            $encryption = postString('encryption', 'tls');

            if ($server === '') {
                throw new InvalidArgumentException('SMTPサーバを入力してください。');
            }

            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException('SMTPポートが不正です。');
            }

            if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
                throw new InvalidArgumentException('暗号化方式が不正です。');
            }

            $fromEmail = postString('fromEmail');
            if (!validEmail($fromEmail)) {
                throw new InvalidArgumentException('送信元メールアドレスが不正です。');
            }

            $replyTo = postString('replyTo');
            if ($replyTo !== '' && !validEmail($replyTo)) {
                throw new InvalidArgumentException('返信先メールアドレスが不正です。');
            }

            $data['mailSettings']['server'] = $server;
            $data['mailSettings']['port'] = $port;
            $data['mailSettings']['encryption'] = $encryption;
            $data['mailSettings']['auth'] = postString('auth', '1') === '1';
            $data['mailSettings']['username'] = postString('username');
            $data['mailSettings']['fromEmail'] = $fromEmail;
            $data['mailSettings']['fromName'] = postString('fromName');
            $data['mailSettings']['replyTo'] = $replyTo;

            $password = postString('password');
            if ($password !== '') {
                $data['mailSettings']['password'] = encryptSecret($password);
            }

            saveApp($data);
            flash('success', 'メールサーバ設定を保存しました。');
            redirectTo('mail');

        case 'test_mail':
            $password = postString('password');
            if ($password === '' && !empty($data['mailSettings']['password'])) {
                $password = decryptSecret((string)$data['mailSettings']['password']);
            }

            $testTo = postString('testTo');
            if (!validEmail($testTo)) {
                throw new InvalidArgumentException('テスト送信先メールアドレスが不正です。');
            }

            $result = smtpSendOne(
                $data['mailSettings'],
                $password,
                $testTo,
                'アンケートアプリ SMTP テスト',
                'SMTP接続テストメールです。'
            );

            if (!$result['ok']) {
                $data['mailSettings']['connection'] = '接続できません';
                $data['mailSettings']['connectionDetail'] = $result['message'];
                saveApp($data);
                throw new RuntimeException($result['message']);
            }

            $data['mailSettings']['connection'] = '接続確認済み';
            $data['mailSettings']['connectionDetail'] = 'SMTP認証およびテストメール送信に成功しました。';
            saveApp($data);

            flash('success', 'テストメールを送信しました。');
            redirectTo('mail');

        case 'send_mail':
            processSend($data);
            return;

        default:
            throw new InvalidArgumentException('指定された操作は利用できません。');
    }
}

function processSend(array &$data): void
{
    $surveyId = postString('surveyId');
    $survey = surveyById($data, $surveyId);

    if (!$survey) {
        throw new RuntimeException('アンケートが存在しません。');
    }

    $customerIds = postArray('customers');
    if (!$customerIds) {
        throw new InvalidArgumentException('送信する顧客を選択してください。');
    }

    $subject = postString('subject');
    $body = postString('body');

    if ($subject === '') {
        throw new InvalidArgumentException('件名を入力してください。');
    }

    if ($body === '') {
        throw new InvalidArgumentException('本文を入力してください。');
    }

    $password = postString('password');

    if ($password === '' && !empty($data['mailSettings']['password'])) {
        $password = decryptSecret((string)$data['mailSettings']['password']);
    }

    $sent = 0;
    $failed = 0;
    $errors = [];
    $selectedNames = [];

    foreach ($data['customers'] as &$customer) {
        $customerId = (string)($customer['id'] ?? '');

        if (!in_array($customerId, array_map('strval', $customerIds), true)) {
            continue;
        }

        $selectedNames[] = (string)($customer['name'] ?? '');
        $url = answerUrl($surveyId, $customerId);

        $message = str_replace(
            ['{顧客名}', '{アンケートURL}'],
            [(string)($customer['name'] ?? ''), $url],
            $body
        );

        $result = smtpSendOne(
            $data['mailSettings'],
            $password,
            (string)$customer['email'],
            $subject,
            $message
        );

        if ($result['ok']) {
            $sent++;
            $customer['lastSent'] = now();
            $customer['sendCount'] = (int)($customer['sendCount'] ?? 0) + 1;
            $customer['answerStatus'] = 'sent';
        } else {
            $failed++;
            $errors[] = ($customer['name'] ?? '顧客')
                . '：'
                . $result['message'];
        }
    }
    unset($customer);

    if ($sent > 0) {
        $mode = postString('sendMode');
        $type = match ($mode) {
            'remind' => 'リマインド',
            'resend' => '再送',
            default => '一括送信',
        };

        $data['sendHistory'][] = [
            'id' => uid('history'),
            'surveyId' => $surveyId,
            'date' => now(),
            'type' => $type,
            'count' => $sent,
            'subject' => $subject,
            'executor' => '管理者',
            'customers' => $selectedNames,
        ];
    }

    saveApp($data);

    if ($failed > 0) {
        flash(
            'error',
            $sent . '件送信、' . $failed . '件失敗しました。',
            implode("\n", $errors)
        );
    } else {
        flash('success', $sent . '件送信しました。');
    }

    redirectTo('send', ['id' => $surveyId]);
}

/* =========================================================
 * 画面HTML共通
 * ========================================================= */

function layout(
    string $title,
    string $content,
    bool $admin = true,
    ?array $flashData = null
): string {
    $flashHtml = '';

    if (is_array($flashData)) {
        $class = ($flashData['type'] ?? '') === 'error'
            ? 'alert error'
            : 'alert success';

        $flashHtml .= '<div class="' . h($class) . '">'
            . '<strong>' . h($flashData['message'] ?? '') . '</strong>';

        if (($flashData['detail'] ?? '') !== '') {
            $flashHtml .= '<pre>' . h($flashData['detail']) . '</pre>';
        }

        $flashHtml .= '</div>';
    }

    ob_start();
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($title)?> - アンケートアプリ</title>
<style>
:root{
 --primary:#2563eb;
 --primary-dark:#1d4ed8;
 --bg:#f5f7fb;
 --card:#fff;
 --text:#1f2937;
 --muted:#6b7280;
 --border:#dbe1ea;
 --danger:#dc2626;
 --success:#15803d;
}
*{box-sizing:border-box}
body{
 margin:0;
 background:var(--bg);
 color:var(--text);
 font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;
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
nav{display:flex;gap:8px;flex-wrap:wrap}
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
h1{font-size:26px;margin:0 0 20px}
h2{font-size:19px;margin:0 0 14px}
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
 grid-template-columns:repeat(2,minmax(0,1fr));
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
textarea{min-height:100px;resize:vertical}
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
button:hover,.btn:hover{background:#f8fafc}
button.primary,.primary{
 background:var(--primary);
 border-color:var(--primary);
 color:#fff;
}
button.primary:hover,.primary:hover{background:var(--primary-dark)}
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
.alert.success{background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}
.alert.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.alert pre{white-space:pre-wrap}
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
th{background:#f8fafc}
.badge{
 display:inline-block;
 padding:3px 8px;
 border-radius:999px;
 font-size:12px;
 background:#e5e7eb;
}
.badge.published{background:#dcfce7;color:#166534}
.badge.draft{background:#e5e7eb;color:#374151}
.badge.stopped{background:#fef3c7;color:#92400e}
.badge.ended{background:#fee2e2;color:#991b1b}
.muted{color:var(--muted)}
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
.qnumber{
 font-weight:700;
 color:var(--primary);
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
}
.answer-option input{width:auto}
.stats{
 display:grid;
 grid-template-columns:repeat(4,minmax(0,1fr));
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
 .grid2,.stats{grid-template-columns:1fr}
 .header-inner{align-items:flex-start;flex-direction:column}
 main{padding:18px 12px 40px}
 .card{padding:15px}
}
</style>
</head>
<body>
<?php if ($admin): ?>
<header>
 <div class="header-inner">
  <a class="brand" href="?screen=list">アンケートアプリ</a>
  <nav>
   <a href="?screen=list">アンケート一覧</a>
   <a href="?screen=kintone">kintone設定</a>
   <a href="?screen=mail">メール設定</a>
  </nav>
 </div>
</header>
<?php endif; ?>
<main>
 <?=$flashHtml?>
 <?=$content?>
</main>
</body>
</html>
<?php
    return (string)ob_get_clean();
}

/* =========================================================
 * 一覧
 * ========================================================= */

function renderList(array $data): string
{
    $search = getString('q');
    $status = getString('status', 'all');
    $sort = getString('sort', 'updated_desc');

    $surveys = array_values(array_filter(
        $data['surveys'],
        static function ($survey) use ($search, $status): bool {
            if ($search !== ''
                && mb_stripos((string)$survey['title'], $search) === false) {
                return false;
            }

            if ($status !== 'all' && ($survey['status'] ?? '') !== $status) {
                return false;
            }

            return true;
        }
    ));

    usort($surveys, static function ($a, $b) use ($sort): int {
        return match ($sort) {
            'updated_asc' => strcmp(
                (string)$a['updatedAt'],
                (string)$b['updatedAt']
            ),
            'answers_desc' => count($GLOBALS['__app']['answers'][$b['id']] ?? [])
                <=> count($GLOBALS['__app']['answers'][$a['id']] ?? []),
            'answers_asc' => count($GLOBALS['__app']['answers'][$a['id']] ?? [])
                <=> count($GLOBALS['__app']['answers'][$b['id']] ?? []),
            'start_desc' => strcmp(
                (string)$b['startAt'],
                (string)$a['startAt']
            ),
            'start_asc' => strcmp(
                (string)$a['startAt'],
                (string)$b['startAt']
            ),
            default => strcmp(
                (string)$b['updatedAt'],
                (string)$a['updatedAt']
            ),
        };
    });

    ob_start();
    ?>
<h1>アンケート一覧</h1>

<div class="card">
<form method="get">
<input type="hidden" name="screen" value="list">
<div class="grid2">
 <div class="field">
  <label>タイトル検索</label>
  <input name="q" value="<?=h($search)?>" placeholder="タイトルを入力してEnter">
 </div>
 <div class="field">
  <label>ステータス</label>
  <select name="status">
   <option value="all" <?=$status==='all'?'selected':''?>>すべて</option>
   <option value="published" <?=$status==='published'?'selected':''?>>公開中</option>
   <option value="draft" <?=$status==='draft'?'selected':''?>>下書き</option>
   <option value="stopped" <?=$status==='stopped'?'selected':''?>>停止</option>
   <option value="ended" <?=$status==='ended'?'selected':''?>>終了</option>
  </select>
 </div>
</div>
<div class="field">
 <label>ソート</label>
 <select name="sort">
  <option value="updated_desc" <?=$sort==='updated_desc'?'selected':''?>>更新日：新しい順</option>
  <option value="updated_asc" <?=$sort==='updated_asc'?'selected':''?>>更新日：古い順</option>
  <option value="answers_desc" <?=$sort==='answers_desc'?'selected':''?>>回答数：多い順</option>
  <option value="answers_asc" <?=$sort==='answers_asc'?'selected':''?>>回答数：少ない順</option>
  <option value="start_desc" <?=$sort==='start_desc'?'selected':''?>>開始日：新しい順</option>
  <option value="start_asc" <?=$sort==='start_asc'?'selected':''?>>開始日：古い順</option>
 </select>
</div>
<button class="primary">検索</button>
<a class="btn" href="?screen=edit">新規作成</a>
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
<th>ステータス</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>
<tbody>
<?php if (!$surveys): ?>
<tr><td colspan="7">アンケートはありません。</td></tr>
<?php else: ?>
<?php foreach ($surveys as $survey): ?>
<?php $answerCount=count($data['answers'][$survey['id']]??[]); ?>
<tr>
<td><?=h($survey['title'])?></td>
<td><?=h($survey['createdAt'])?></td>
<td><?=h($survey['updatedAt'])?></td>
<td><?=h($survey['startAt'])?> ～ <?=h($survey['endAt'])?></td>
<td><span class="badge <?=h(statusClass($survey['status']))?>"><?=h(statusLabel($survey['status']))?></span></td>
<td><?=h($answerCount)?></td>
<td>
<div class="actions">
<a class="btn" href="?screen=edit&id=<?=rawurlencode($survey['id'])?>">確認・編集</a>
<a class="btn" href="?screen=preview&id=<?=rawurlencode($survey['id'])?>">プレビュー</a>
<a class="btn" href="?screen=analytics&id=<?=rawurlencode($survey['id'])?>">集計</a>
<a class="btn" href="?screen=send&id=<?=rawurlencode($survey['id'])?>">送信</a>

<?php if ($survey['status']==='draft'): ?>
<form method="post" style="display:inline">
<input type="hidden" name="action" value="transition">
<input type="hidden" name="id" value="<?=h($survey['id'])?>">
<input type="hidden" name="to" value="published">
<button>公開</button>
</form>
<?php elseif ($survey['status']==='published'): ?>
<form method="post" style="display:inline">
<input type="hidden" name="action" value="transition">
<input type="hidden" name="id" value="<?=h($survey['id'])?>">
<input type="hidden" name="to" value="stopped">
<button>停止</button>
</form>
<?php elseif ($survey['status']==='stopped'): ?>
<form method="post" style="display:inline">
<input type="hidden" name="action" value="transition">
<input type="hidden" name="id" value="<?=h($survey['id'])?>">
<input type="hidden" name="to" value="published">
<button>再公開</button>
</form>
<?php endif; ?>

<form method="post" style="display:inline">
<input type="hidden" name="action" value="duplicate">
<input type="hidden" name="id" value="<?=h($survey['id'])?>">
<button>複製</button>
</form>

<?php if ($survey['status']!=='published'): ?>
<form method="post" style="display:inline" onsubmit="return confirm('削除しますか？')">
<input type="hidden" name="action" value="delete_survey">
<input type="hidden" name="id" value="<?=h($survey['id'])?>">
<button class="danger">削除</button>
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
<?php
    return (string)ob_get_clean();
}

/* =========================================================
 * 編集画面
 * ========================================================= */

function renderEdit(array $data, ?array $survey): string
{
    if ($survey === null) {
        $survey = normalizeSurvey([
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [[
                'id' => uid('g'),
                'title' => 'グループ1',
                'questions' => [[
                    'id' => uid('q'),
                    'text' => '',
                    'type' => 'single',
                    'required' => false,
                    'options' => ['選択肢1', '選択肢2'],
                    'branches' => [],
                ]],
            ]],
        ]);
    }

    ob_start();
    ?>
<h1><?=empty($survey['title'])?'アンケート作成':'アンケート編集'?></h1>

<div class="card">
<form method="post" id="surveyForm">
<input type="hidden" name="action" value="save_survey">
<input type="hidden" name="id" value="<?=h($survey['id'])?>">

<div class="field">
<label>タイトル</label>
<input name="title" value="<?=h($survey['title'])?>" required maxlength="200">
</div>

<div class="field">
<label>説明</label>
<textarea name="description" maxlength="5000"><?=h($survey['description'])?></textarea>
</div>

<div class="grid2">
<div class="field">
<label>開始日時</label>
<input type="datetime-local" name="startAt" value="<?=h($survey['startAt'])?>">
</div>
<div class="field">
<label>終了日時</label>
<input type="datetime-local" name="endAt" value="<?=h($survey['endAt'])?>">
</div>
</div>

<div class="field">
<label>質問番号</label>
<select name="numbering" id="numbering" onchange="reindex()">
<option value="global" <?=$survey['numbering']==='global'?'selected':''?>>全体通番：Q1、Q2、Q3…</option>
<option value="group" <?=$survey['numbering']==='group'?'selected':''?>>グループ単位：Q1-1、Q1-2、Q2-1…</option>
</select>
</div>

<div id="groups">
<?php foreach ($survey['groups'] as $gi=>$group): ?>
<div class="group" data-group>
<div class="question-head">
<h2>グループ <?=($gi+1)?></h2>
<button type="button" onclick="removeGroup(this)">グループを削除</button>
</div>

<input type="hidden" name="groups[<?=$gi?>][id]" value="<?=h($group['id'])?>">

<div class="field">
<label>グループタイトル</label>
<input name="groups[<?=$gi?>][title]" value="<?=h($group['title'])?>">
</div>

<div class="questions">
<?php foreach ($group['questions'] as $qi=>$question): ?>
<div class="question" data-question>

<div class="question-head">
<span class="qnumber"><?=h($question['number'])?></span>
<button type="button" onclick="removeQuestion(this)">質問を削除</button>
</div>

<input type="hidden"
 name="groups[<?=$gi?>][questions][<?=$qi?>][id]"
 value="<?=h($question['id'])?>">

<div class="field">
<label>質問文</label>
<textarea
 name="groups[<?=$gi?>][questions][<?=$qi?>][text]"
 required><?=h($question['text'])?></textarea>
</div>

<div class="grid2">
<div class="field">
<label>回答形式</label>
<select
 name="groups[<?=$gi?>][questions][<?=$qi?>][type]"
 onchange="toggleOptions(this)">
<option value="single" <?=$question['type']==='single'?'selected':''?>>単一選択</option>
<option value="multiple" <?=$question['type']==='multiple'?'selected':''?>>複数選択</option>
<option value="free" <?=$question['type']==='free'?'selected':''?>>自由記述</option>
</select>
</div>

<div class="field">
<label>必須</label>
<select name="groups[<?=$gi?>][questions][<?=$qi?>][required]">
<option value="0" <?=empty($question['required'])?'selected':''?>>任意</option>
<option value="1" <?=!empty($question['required'])?'selected':''?>>必須</option>
</select>
</div>
</div>

<div class="options" style="<?=$question['type']==='free'?'display:none':''?>">
<label>選択肢</label>

<?php foreach ($question['options'] as $oi=>$option): ?>
<div class="option-row">
<input
 name="groups[<?=$gi?>][questions][<?=$qi?>][options][<?=$oi?>]"
 value="<?=h($option)?>">
<button type="button" onclick="this.parentElement.remove()">削除</button>
</div>
<?php endforeach; ?>

<button type="button" onclick="addOption(this)">＋ 選択肢</button>
</div>

</div>
<?php endforeach; ?>
</div>

<button type="button" onclick="addQuestion(this)">＋ 質問を追加</button>
</div>
<?php endforeach; ?>
</div>

<div class="actions">
<button type="button" onclick="addGroup()">＋ グループを追加</button>
<button class="primary">保存して一覧へ</button>
<button type="button" onclick="if(confirm('編集内容を破棄しますか？'))location.href='?screen=list'">キャンセル</button>
</div>
</form>
</div>

<script>
function reindex(){
 const groups=[...document.querySelectorAll('[data-group]')];
 let global=1;

 groups.forEach((g,gi)=>{
   const title=g.querySelector(':scope > .question-head h2');
   if(title) title.textContent='グループ '+(gi+1);

   const questions=[...g.querySelectorAll(':scope .questions > [data-question]')];

   questions.forEach((q,qi)=>{
     const number=document.getElementById('numbering').value==='group'
       ? 'Q'+(gi+1)+'-'+(qi+1)
       : 'Q'+global++;

     const numberNode=q.querySelector('.qnumber');
     if(numberNode) numberNode.textContent=number;

     q.querySelectorAll('input,select,textarea').forEach(el=>{
       const name=el.getAttribute('name');
       if(!name)return;

       const match=name.match(/^groups\[\d+\]\[questions\]\[\d+\](.*)$/);
       if(match){
         el.name='groups['+gi+'][questions]['+qi+']'+match[1];
       }
     });
   });

   const groupId=g.querySelector(':scope > input[name$="[id]"]');
   if(groupId) groupId.name='groups['+gi+'][id]';

   const groupTitle=g.querySelector(':scope > .field input[name$="[title]"]');
   if(groupTitle) groupTitle.name='groups['+gi+'][title]';
 });
}

function removeGroup(button){
 if(!confirm('このグループを削除しますか？'))return;
 button.closest('[data-group]').remove();
 reindex();
}

function removeQuestion(button){
 if(!confirm('この質問を削除しますか？'))return;
 button.closest('[data-question]').remove();
 reindex();
}

function toggleOptions(select){
 const box=select.closest('[data-question]').querySelector('.options');
 box.style.display=select.value==='free'?'none':'block';
}

function addOption(button){
 const question=button.closest('[data-question]');
 const options=question.querySelector('.options');
 const inputs=options.querySelectorAll('.option-row input');
 const index=inputs.length;

 const row=document.createElement('div');
 row.className='option-row';
 row.innerHTML='<input><button type="button">削除</button>';

 const input=row.querySelector('input');
 input.name='groups[0][questions][0][options]['+index+']';
 row.querySelector('button').onclick=()=>row.remove();

 options.insertBefore(row,button);
 reindex();
}

function addQuestion(button){
 const group=button.closest('[data-group]');
 const gi=[...document.querySelectorAll('[data-group]')].indexOf(group);
 const questions=group.querySelector('.questions');
 const qi=questions.querySelectorAll('[data-question]').length;

 const box=document.createElement('div');
 box.className='question';
 box.setAttribute('data-question','');

 box.innerHTML=`
 <div class="question-head">
   <span class="qnumber"></span>
   <button type="button" onclick="removeQuestion(this)">質問を削除</button>
 </div>
 <input type="hidden" name="groups[${gi}][questions][${qi}][id]" value="">
 <div class="field">
   <label>質問文</label>
   <textarea name="groups[${gi}][questions][${qi}][text]" required></textarea>
 </div>
 <div class="grid2">
   <div class="field">
     <label>回答形式</label>
     <select name="groups[${gi}][questions][${qi}][type]" onchange="toggleOptions(this)">
       <option value="single">単一選択</option>
       <option value="multiple">複数選択</option>
       <option value="free">自由記述</option>
     </select>
   </div>
   <div class="field">
     <label>必須</label>
     <select name="groups[${gi}][questions][${qi}][required]">
       <option value="0">任意</option>
       <option value="1">必須</option>
     </select>
   </div>
 </div>
 <div class="options">
   <label>選択肢</label>
   <button type="button" onclick="addOption(this)">＋ 選択肢</button>
 </div>`;

 questions.appendChild(box);
 reindex();
}

function addGroup(){
 const groups=document.getElementById('groups');
 const gi=groups.querySelectorAll('[data-group]').length;

 const box=document.createElement('div');
 box.className='group';
 box.setAttribute('data-group','');

 box.innerHTML=`
 <div class="question-head">
   <h2>グループ ${gi+1}</h2>
   <button type="button" onclick="removeGroup(this)">グループを削除</button>
 </div>
 <input type="hidden" name="groups[${gi}][id]" value="">
 <div class="field">
   <label>グループタイトル</label>
   <input name="groups[${gi}][title]" value="">
 </div>
 <div class="questions"></div>
 <button type="button" onclick="addQuestion(this)">＋ 質問を追加</button>`;

 groups.appendChild(box);
 addQuestion(box.querySelector('button:last-child'));
 reindex();
}

reindex();
</script>
<?php
    return (string)ob_get_clean();
}

/* =========================================================
 * プレビュー
 * ========================================================= */

function renderPreview(array $survey): string
{
    ob_start();
    ?>
<h1>プレビュー</h1>

<div class="card">
<h2><?=h($survey['title'])?></h2>
<?php if ($survey['description']!==''): ?>
<p><?=nl2br(h($survey['description']))?></p>
<?php endif; ?>

<?php foreach ($survey['groups'] as $group): ?>
<div class="group">
<h2><?=h($group['title'])?></h2>

<?php foreach ($group['questions'] as $question): ?>
<div class="question">
<div class="question-head">
<span class="qnumber"><?=h($question['number'])?></span>
<span class="badge"><?=h(typeLabel($question['type']))?></span>
</div>

<p><strong><?=h($question['text'])?></strong>
<?php if ($question['required']): ?> <span class="muted">（必須）</span><?php endif; ?>
</p>

<?php if ($question['type']==='single'): ?>
<?php foreach ($question['options'] as $option): ?>
<div class="answer-option">
<input type="radio" disabled>
<span><?=h($option)?></span>
</div>
<?php endforeach; ?>
<?php elseif ($question['type']==='multiple'): ?>
<?php foreach ($question['options'] as $option): ?>
<div class="answer-option">
<input type="checkbox" disabled>
<span><?=h($option)?></span>
</div>
<?php endforeach; ?>
<?php else: ?>
<textarea disabled placeholder="自由記述"></textarea>
<?php endif; ?>

</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
</div>
<?php
    return (string)ob_get_clean();
}

/* =========================================================
 * 回答画面
 * ========================================================= */

function renderAnswer(array $data, array $survey): string
{
    $draft = $_SESSION['answer_draft'] ?? [];
    if (!is_array($draft)) {
        $draft = [];
    }

    $errors = $_SESSION['answer_errors'] ?? [];
    unset($_SESSION['answer_errors']);

    $customerId = getString('customer');
    $visible = visibleQuestionIds($survey, $draft);
    $map = questionMap($survey);

    ob_start();
    ?>
<h1><?=h($survey['title'])?></h1>

<div class="card">
<?php if ($survey['description']!==''): ?>
<p><?=nl2br(h($survey['description']))?></p>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert error">
<?php foreach ($errors as $error): ?>
<div><?=h($error)?></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="action" value="answer_confirm">
<input type="hidden" name="surveyId" value="<?=h($survey['id'])?>">
<input type="hidden" name="customerId" value="<?=h($customerId)?>">

<?php foreach ($survey['groups'] as $group): ?>
<div class="group">
<h2><?=h($group['title'])?></h2>

<?php foreach ($group['questions'] as $question): ?>
<?php if (!in_array($question['id'],$visible,true)) continue; ?>

<div class="question">
<div class="question-head">
<span class="qnumber"><?=h($question['number'])?></span>
<?php if ($question['required']): ?>
<span class="badge">必須</span>
<?php endif; ?>
</div>

<label><?=h($question['text'])?></label>

<?php $value=$draft[$question['id']]??''; ?>

<?php if ($question['type']==='single'): ?>
<?php foreach ($question['options'] as $option): ?>
<label class="answer-option">
<input
 type="radio"
 name="answers[<?=h($question['id'])?>]"
 value="<?=h($option)?>"
 <?=((string)$value===$option)?'checked':''?>>
<?=h($option)?>
</label>
<?php endforeach; ?>

<?php elseif ($question['type']==='multiple'): ?>
<?php
$values=is_array($value)?$value:[];
?>
<?php foreach ($question['options'] as $option): ?>
<label class="answer-option">
<input
 type="checkbox"
 name="answers[<?=h($question['id'])?>][]"
 value="<?=h($option)?>"
 <?=in_array($option,$values,true)?'checked':''?>>
<?=h($option)?>
</label>
<?php endforeach; ?>

<?php else: ?>
<textarea
 name="answers[<?=h($question['id'])?>]"
 maxlength="10000"><?=h((string)$value)?></textarea>
<?php endif; ?>

</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<div class="actions">
<button class="primary">回答確認</button>
</div>
</form>
</div>
<?php
    return (string)ob_get_clean();
}

/* =========================================================
 * 回答確認
 * ========================================================= */

function renderConfirm(array $data, array $survey): string
{
    $answers = $_SESSION['answer_draft'] ?? [];
    if (!is_array($answers)) {
        $answers = [];
    }

    $map = questionMap($survey);
    $customerId = getString('customer');

    ob_start();
    ?>
<h1>回答確認</h1>

<div class="card">
<h2><?=h($survey['title'])?></h2>

<?php foreach ($survey['groups'] as $group): ?>
<div class="group">
<h2><?=h($group['title'])?></h2>

<?php foreach ($group['questions'] as $question): ?>
<?php
$value=$answers[$question['id']]??'';
if(is_array($value)){
    $display=implode('、',array_map('strval',$value));
}else{
    $display=(string)$value;
}
?>
<div class="question">
<div class="qnumber"><?=h($question['number'])?></div>
<p><strong><?=h($question['text'])?></strong></p>
<p><?=nl2br(h($display))?></p>
</div>
<?php endforeach; ?>

</div>
<?php endforeach; ?>

<div class="actions">
<a class="btn" href="?screen=answer&id=<?=rawurlencode($survey['id'])?><?= $customerId!=='' ? '&customer='.rawurlencode($customerId) : '' ?>">修正する</a>

<form method="post" style="display:inline">
<input type="hidden" name="action" value="answer_submit">
<input type="hidden" name="surveyId" value="<?=h($survey['id'])?>">
<input type="hidden" name="customerId" value="<?=h($customerId)?>">
<button class="primary">回答を送信</button>
</form>
</div>
</div>
<?php
    return (string)ob_get_clean();
}

function renderComplete(array $survey): string
{
    return '<div class="card">'
        . '<h1>回答完了</h1>'
        . '<p>回答を正常に送信しました。</p>'
        . '<p>ご回答ありがとうございました。</p>'
        . '</div>';
}

/* =========================================================
 * 送信画面
 *
 * 重要：
 * renderSend() の関数定義はテンプレートの外側に完全に分離する。
 * これにより、元ファイルで発生していた
 * 「PHP関数定義がHTML/PHPブロック途中へ混入する構造破損」
 * を根本的に排除する。
 * ========================================================= */

function renderSend(
    array $data,
    array $survey
): string {
    $search = getString('customerQ');
    $customers = $data['customers'];

    if ($search !== '') {
        $customers = array_values(array_filter(
            $customers,
            static function ($customer) use ($search): bool {
                $text = implode(' ', [
                    (string)($customer['name'] ?? ''),
                    (string)($customer['org'] ?? ''),
                    (string)($customer['email'] ?? ''),
                ]);

                return mb_stripos($text, $search) !== false;
            }
        ));
    }

    $history = array_reverse(array_values(array_filter(
        $data['sendHistory'],
        static fn($item): bool =>
            (string)($item['surveyId'] ?? '') === (string)$survey['id']
    )));

    ob_start();
    ?>
<h1>顧客選択・メール送信</h1>

<div class="card">
<h2>対象アンケート</h2>
<p><strong><?=h($survey['title'])?></strong></p>
<p class="muted">対象アンケートはこの画面で固定されています。</p>
</div>

<div class="card">
<h2>顧客検索</h2>

<form method="get">
<input type="hidden" name="screen" value="send">
<input type="hidden" name="id" value="<?=h($survey['id'])?>">

<div class="field">
<label>検索</label>
<input name="customerQ" value="<?=h($search)?>" placeholder="氏名・組織名・メールアドレス">
</div>

<button class="primary">検索</button>
</form>
</div>

<div class="card">
<h2>メール送信</h2>

<form method="post">
<input type="hidden" name="action" value="send_mail">
<input type="hidden" name="surveyId" value="<?=h($survey['id'])?>">

<div class="field">
<label>送信対象</label>

<div class="table-wrap">
<table>
<thead>
<tr>
<th></th>
<th>氏名</th>
<th>組織</th>
<th>メール</th>
<th>送信状況</th>
<th>回答状況</th>
</tr>
</thead>
<tbody>

<?php if (!$customers): ?>
<tr><td colspan="6">顧客がありません。</td></tr>
<?php else: ?>
<?php foreach ($customers as $customer): ?>
<tr>
<td>
<input
 type="checkbox"
 name="customers[]"
 value="<?=h($customer['id'])?>">
</td>
<td><?=h($customer['name'])?></td>
<td><?=h($customer['org'])?></td>
<td><?=h($customer['email'])?></td>
<td><?=h($customer['lastSent'] ?? '未送信')?></td>
<td><?=h(($customer['answerStatus'] ?? 'unsent') === 'answered' ? '回答済み' : '未回答')?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>

</tbody>
</table>
</div>
</div>

<div class="field">
<label>件名</label>
<input name="subject" value="<?=h($survey['title'].' ご回答のお願い')?>">
</div>

<div class="field">
<label>本文</label>
<textarea name="body">{顧客名} 様

アンケートへのご回答をお願いいたします。

回答URL：
{アンケートURL}

よろしくお願いいたします。</textarea>
</div>

<div class="field">
<label>SMTPパスワード</label>
<input type="password" name="password" autocomplete="off">
<p class="muted">保存済みパスワードを使用する場合は空欄にしてください。</p>
</div>

<div class="actions">
<button
 class="primary"
 name="sendMode"
 value="send">一括送信</button>

<button
 name="sendMode"
 value="remind">リマインド送信</button>

<button
 name="sendMode"
 value="resend">再送</button>
</div>
</form>
</div>

<div class="card">
<h2>送信履歴</h2>

<?php if (!$history): ?>
<p class="muted">送信履歴はありません。</p>
<?php else: ?>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>種別</th>
<th>件数</th>
<th>件名</th>
<th>実行者</th>
</tr>
</thead>
<tbody>

<?php foreach ($history as $item): ?>
<tr>
<td><?=h($item['date'] ?? '')?></td>
<td><?=h($item['type'] ?? '')?></td>
<td><?=h($item['count'] ?? 0)?></td>
<td><?=h($item['subject'] ?? '')?></td>
<td><?=h($item['executor'] ?? '管理者')?></td>
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
 * 集計
 * ========================================================= */

function renderAnalytics(array $data, array $survey): string
{
    $answers = $data['answers'][$survey['id']] ?? [];
    $answerCount = count($answers);

    $sentCount = 0;

    foreach ($data['customers'] as $customer) {
        if (($customer['answerStatus'] ?? '') !== 'unsent') {
            $sentCount++;
        }
    }

    $unanswered = max(0, $sentCount - $answerCount);
    $rate = $sentCount > 0
        ? round(($answerCount / $sentCount) * 100, 1)
        : 0;

    ob_start();
    ?>
<h1>回答集計・分析</h1>

<div class="card">
<h2>対象アンケート</h2>
<p><strong><?=h($survey['title'])?></strong></p>
</div>

<div class="stats">
<div class="stat">
<span class="muted">送信対象者数</span>
<strong><?=h($sentCount)?></strong>
</div>

<div class="stat">
<span class="muted">回答数</span>
<strong><?=h($answerCount)?></strong>
</div>

<div class="stat">
<span class="muted">未登録回答数</span>
<strong>
<?=h(count(array_filter(
    $answers,
    static fn($answer): bool =>
        empty($answer['customerId'])
)))?>
</strong>
</div>

<div class="stat">
<span class="muted">回答率</span>
<strong><?=h($rate)?>%</strong>
</div>
</div>

<div class="card">
<div class="actions">
<a class="btn" href="?screen=analytics&id=<?=rawurlencode($survey['id'])?>&export=csv">CSV出力</a>
<a class="btn" href="?screen=analytics&id=<?=rawurlencode($survey['id'])?>&export=pdf">PDF出力</a>
</div>
</div>

<?php if ($answerCount===0): ?>
<div class="card">
<p>現在、回答データはありません</p>
</div>
<?php else: ?>

<div class="card">
<h2>設問別集計</h2>

<?php foreach (allQuestions($survey) as $question): ?>
<?php
$countMap=[];
foreach($question['options'] as $option){
    $countMap[$option]=0;
}
foreach($answers as $answer){
    $value=$answer['values'][$question['id']]??null;
    if(is_array($value)){
        foreach($value as $item){
            if(isset($countMap[$item]))$countMap[$item]++;
        }
    }elseif(isset($countMap[(string)$value])){
        $countMap[(string)$value]++;
    }
}
?>
<div class="question">
<div class="qnumber"><?=h($question['number'])?></div>
<h3><?=h($question['text'])?></h3>

<?php if ($question['type']==='free'): ?>
<p class="muted">自由記述回答</p>
<?php foreach ($answers as $answer): ?>
<?php $value=$answer['values'][$question['id']]??''; ?>
<?php if (trim((string)$value)!==''): ?>
<p><?=nl2br(h((string)$value))?></p>
<hr>
<?php endif; ?>
<?php endforeach; ?>

<?php else: ?>
<table>
<thead><tr><th>選択肢</th><th>回答数</th></tr></thead>
<tbody>
<?php foreach ($countMap as $option=>$count): ?>
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

<?php foreach ($answers as $answer): ?>
<tr>
<td><?=h($answer['date']??'')?></td>
<td><?=h($answer['customer']??'未登録回答者')?></td>
<td><?=h($answer['org']??'')?></td>
<td>
<?php foreach (($answer['values']??[]) as $qid=>$value): ?>
<?php $question=questionMap($survey)[$qid]??null; ?>
<?php if($question): ?>
<div>
<strong><?=h($question['number'])?></strong>
：
<?=h(is_array($value)?implode('、',$value):(string)$value)?>
</div>
<?php endif; ?>
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
 * kintone画面
 * ========================================================= */

function renderKintone(array $data): string
{
    $settings = $data['kintone'];
    $fields = $settings['fields'] ?? [];
    $mapping = $settings['mappings'] ?? [];

    ob_start();
    ?>
<h1>kintone設定</h1>

<div class="card">
<form method="post">
<input type="hidden" name="action" value="save_kintone">

<div class="grid2">
<div class="field">
<label>サブドメイン</label>
<input name="subdomain" value="<?=h($settings['subdomain'])?>" placeholder="example / example.cybozu.com">
</div>

<div class="field">
<label>顧客管理アプリID</label>
<input name="appId" value="<?=h($settings['appId'])?>">
</div>

<div class="field">
<label>ログイン名</label>
<input name="username" value="<?=h($settings['username'])?>">
</div>

<div class="field">
<label>Proxy</label>
<input name="proxy" value="<?=h($settings['proxy'])?>" placeholder="host:port">
</div>
</div>

<div class="field">
<label>SSL証明書検証</label>
<select name="sslVerify">
<option value="1" <?=$settings['sslVerify']?'selected':''?>>有効</option>
<option value="0" <?=!$settings['sslVerify']?'selected':''?>>無効（POC）</option>
</select>
</div>

<div class="field">
<label>パスワード</label>
<input type="password" name="password" autocomplete="new-password">
</div>

<button class="primary">設定保存</button>
</form>
</div>

<div class="card">
<h2>接続テスト</h2>
<p>状態：
<strong><?=h($settings['connection'])?></strong>
</p>

<?php if($settings['connectionDetail']!==''): ?>
<p class="muted"><?=h($settings['connectionDetail'])?></p>
<?php endif; ?>

<form method="post">
<input type="hidden" name="action" value="test_kintone">
<div class="field">
<label>パスワード</label>
<input type="password" name="password" autocomplete="off">
</div>
<button>接続テスト</button>
</form>
</div>

<div class="card">
<h2>項目一覧</h2>

<form method="post">
<input type="hidden" name="action" value="fetch_kintone_fields">
<div class="field">
<label>パスワード</label>
<input type="password" name="password" autocomplete="off">
</div>
<button>項目一覧再取得</button>
</form>

<?php if($fields): ?>
<div class="table-wrap">
<table>
<thead>
<tr><th>フィールドコード</th><th>ラベル</th><th>型</th></tr>
</thead>
<tbody>
<?php foreach($fields as $code=>$field): ?>
<tr>
<td><?=h($code)?></td>
<td><?=h($field['label']??'')?></td>
<td><?=h($field['type']??'')?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>

<div class="card">
<h2>顧客情報マッピング</h2>

<form method="post">
<input type="hidden" name="action" value="save_kintone_mapping">

<?php foreach([
 'org'=>'組織名',
 'name'=>'氏名',
 'email'=>'メールアドレス',
 'department'=>'部署名',
 'phone'=>'電話番号'
] as $key=>$label): ?>

<div class="field">
<label><?=h($label)?></label>
<select name="map_<?=h($key)?>">
<option value="">未設定</option>
<?php foreach($fields as $code=>$field): ?>
<option
 value="<?=h($code)?>"
 <?=$mapping[$key]===$code?'selected':''?>>
<?=h($code.' / '.($field['label']??''))?>
</option>
<?php endforeach; ?>
</select>
</div>

<?php endforeach; ?>

<div class="field">
<label>住所（複数可）</label>

<?php foreach($fields as $code=>$field): ?>
<label style="font-weight:400">
<input
 type="checkbox"
 name="map_address[]"
 value="<?=h($code)?>"
 <?=in_array($code,$mapping['address']??[],true)?'checked':''?>
 style="width:auto">
<?=h($code.' / '.($field['label']??''))?>
</label>
<?php endforeach; ?>

</div>

<button class="primary">マッピング保存</button>
</form>
</div>

<div class="card">
<h2>顧客情報同期</h2>

<form method="post">
<input type="hidden" name="action" value="sync_kintone">

<div class="field">
<label>パスワード</label>
<input type="password" name="password" autocomplete="off">
</div>

<button class="primary">顧客情報同期</button>
</form>

<p class="muted">現在 <?=h(count($data['customers']))?> 件の顧客情報を保持しています。</p>
</div>
<?php
    return (string)ob_get_clean();
}

/* =========================================================
 * メール設定画面
 * ========================================================= */

function renderMail(array $data): string
{
    $settings = $data['mailSettings'];

    ob_start();
    ?>
<h1>メールサーバ設定</h1>

<div class="card">
<form method="post">
<input type="hidden" name="action" value="save_mail">

<div class="grid2">

<div class="field">
<label>SMTPサーバ</label>
<input name="server" value="<?=h($settings['server'])?>">
</div>

<div class="field">
<label>SMTPポート</label>
<input type="number" name="port" value="<?=h($settings['port'])?>">
</div>

<div class="field">
<label>暗号化方式</label>
<select name="encryption">
<option value="ssl" <?=$settings['encryption']==='ssl'?'selected':''?>>SSL</option>
<option value="tls" <?=$settings['encryption']==='tls'?'selected':''?>>TLS</option>
<option value="none" <?=$settings['encryption']==='none'?'selected':''?>>なし</option>
</select>
</div>

<div class="field">
<label>SMTP認証</label>
<select name="auth">
<option value="1" <?=$settings['auth']?'selected':''?>>あり</option>
<option value="0" <?=!$settings['auth']?'selected':''?>>なし</option>
</select>
</div>

<div class="field">
<label>SMTPユーザー名</label>
<input name="username" value="<?=h($settings['username'])?>">
</div>

<div class="field">
<label>送信元メールアドレス</label>
<input type="email" name="fromEmail" value="<?=h($settings['fromEmail'])?>">
</div>

<div class="field">
<label>送信元名</label>
<input name="fromName" value="<?=h($settings['fromName'])?>">
</div>

<div class="field">
<label>返信先メールアドレス</label>
<input type="email" name="replyTo" value="<?=h($settings['replyTo'])?>">
</div>

</div>

<div class="field">
<label>パスワード</label>
<input type="password" name="password" autocomplete="new-password">
</div>

<button class="primary">設定保存</button>
</form>
</div>

<div class="card">
<h2>接続状態</h2>

<p>
<strong><?=h($settings['connection'])?></strong>
</p>

<?php if($settings['connectionDetail']!==''): ?>
<p class="muted"><?=h($settings['connectionDetail'])?></p>
<?php endif; ?>

<h2>接続テスト・テストメール</h2>

<form method="post">
<input type="hidden" name="action" value="test_mail">

<div class="field">
<label>パスワード</label>
<input type="password" name="password" autocomplete="off">
</div>

<div class="field">
<label>テスト送信先</label>
<input type="email" name="testTo" required>
</div>

<button class="primary">テストメール送信</button>
</form>
</div>
<?php
    return (string)ob_get_clean();
}

/* =========================================================
 * CSV
 * ========================================================= */

function outputCsv(array $data, string $surveyId): never
{
    $survey = surveyById($data, $surveyId);

    if (!$survey) {
        http_response_code(404);
        exit('対象アンケートが存在しません。');
    }

    $answers = $data['answers'][$surveyId] ?? [];

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey-'
        . rawurlencode($surveyId)
        . '.csv"'
    );

    echo "\xEF\xBB\xBF";

    $fp = fopen('php://output', 'wb');

    fputcsv($fp, [
        '回答日時',
        '回答者',
        '組織',
        '質問番号',
        '質問文',
        '回答',
    ]);

    $map = questionMap($survey);

    foreach ($answers as $answer) {
        foreach (($answer['values'] ?? []) as $qid => $value) {
            if (!isset($map[$qid])) {
                continue;
            }

            $question = $map[$qid];

            fputcsv($fp, [
                $answer['date'] ?? '',
                $answer['customer'] ?? '',
                $answer['org'] ?? '',
                $question['number'],
                $question['text'],
                is_array($value) ? implode('、', $value) : (string)$value,
            ]);
        }
    }

    fclose($fp);
    exit;
}

/* =========================================================
 * 最小PDF生成
 *
 * 外部ライブラリに依存せずPDF形式として出力する。
 * 日本語本文は標準14フォントで完全表示できないため、
 * PDFにはASCII化した管理情報・集計値を格納する。
 * 詳細な日本語帳票が必要な場合はフォント埋め込みが必要。
 * ========================================================= */

function pdfEscape(string $text): string
{
    $text = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
    return str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $text
    );
}

function makeSimplePdf(array $lines): string
{
    $objects = [];

    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

    $objects[] =
        '<< /Type /Page /Parent 2 0 R '
        . '/MediaBox [0 0 595 842] '
        . '/Resources << /Font << /F1 4 0 R >> >> '
        . '/Contents 5 0 R >>';

    $objects[] =
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $content = "BT\n/F1 9 Tf\n50 790 Td\n";

    $first = true;
    foreach ($lines as $line) {
        if (!$first) {
            $content .= "0 -14 Td\n";
        }
        $first = false;
        $content .= '(' . pdfEscape((string)$line) . ") Tj\n";
    }

    $content .= "ET";

    $objects[] =
        '<< /Length ' . strlen($content) . " >>\nstream\n"
        . $content
        . "\nendstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $i => $object) {
        $offsets[$i + 1] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n"
            . $object
            . "\nendobj\n";
    }

    $xref = strlen($pdf);

    $pdf .= "xref\n";
    $pdf .= "0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }

    $pdf .= "trailer\n";
    $pdf .= "<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n";
    $pdf .= $xref . "\n";
    $pdf .= "%%EOF";

    return $pdf;
}

function outputPdf(array $data, string $surveyId): never
{
    $survey = surveyById($data, $surveyId);

    if (!$survey) {
        http_response_code(404);
        exit('対象アンケートが存在しません。');
    }

    $answers = $data['answers'][$surveyId] ?? [];

    $lines = [
        'Survey Analytics',
        'Survey ID: ' . $survey['id'],
        'Title: ' . $survey['title'],
        'Answers: ' . count($answers),
        'Generated: ' . now(),
        '',
    ];

    foreach (allQuestions($survey) as $question) {
        $count = 0;

        foreach ($answers as $answer) {
            if (array_key_exists(
                $question['id'],
                $answer['values'] ?? []
            )) {
                $count++;
            }
        }

        $lines[] = $question['number']
            . ' '
            . $question['text']
            . ' / answered='
            . $count;
    }

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="survey-'
        . rawurlencode($surveyId)
        . '.pdf"'
    );

    echo makeSimplePdf($lines);
    exit;
}

/* =========================================================
 * メイン
 * ========================================================= */

$data = defaultData();

try {
    ensureDataDir();

    $data = readData();
    updateAutomaticStatus($data);

    /*
     * renderList()内のソート処理などから現在データを参照する場合に
     * 利用する。秘密情報そのものをHTMLへ出力する用途には使用しない。
     */
    $GLOBALS['__app'] = $data;

    processPost($data);

    $GLOBALS['__app'] = $data;

    $screen = getString('screen', 'list');

    /*
     * 回答者画面は管理者ナビゲーションを表示しない。
     * screenの値だけで画面を切り替え、物理パス分割は行わない。
     */

    if ($screen === 'answer') {
        $id = getString('id');

        if (!validateId($id)) {
            http_response_code(400);
            echo layout(
                'エラー',
                '<div class="card"><h1>アンケートを指定してください。</h1></div>',
                false
            );
            exit;
        }

        $survey = surveyById($data, $id);

        if (!$survey || !surveyAvailableForAnswer($survey)) {
            http_response_code(404);
            echo layout(
                '回答できません',
                '<div class="card"><h1>現在回答できるアンケートではありません。</h1></div>',
                false
            );
            exit;
        }

        echo layout(
            'アンケート回答',
            renderAnswer($data, $survey),
            false
        );
        exit;
    }

    if ($screen === 'confirm') {
        $id = getString('id');

        if (!validateId($id)) {
            http_response_code(400);
            echo layout(
                'エラー',
                '<div class="card"><h1>アンケートを指定してください。</h1></div>',
                false
            );
            exit;
        }

        $survey = surveyById($data, $id);

        if (!$survey || !surveyAvailableForAnswer($survey)) {
            http_response_code(404);
            echo layout(
                '回答できません',
                '<div class="card"><h1>現在回答できるアンケートではありません。</h1></div>',
                false
            );
            exit;
        }

        if (($_SESSION['answer_survey'] ?? '') !== $id) {
            redirectTo('answer', ['id' => $id]);
        }

        echo layout(
            '回答確認',
            renderConfirm($data, $survey),
            false
        );
        exit;
    }

    if ($screen === 'complete') {
        $id = getString('id');
        $survey = validateId($id) ? surveyById($data, $id) : null;

        if (!$survey) {
            http_response_code(404);
            echo layout(
                'エラー',
                '<div class="card"><h1>アンケートが存在しません。</h1></div>',
                false
            );
            exit;
        }

        echo layout(
            '回答完了',
            renderComplete($survey),
            false
        );
        exit;
    }

    /*
     * 以下は管理者画面。
     * POC要件どおり管理者ログイン・認証・認可は実装しない。
     */

    if ($screen === 'list') {
        echo layout(
            'アンケート一覧',
            renderList($data),
            true,
            takeFlash()
        );
        exit;
    }

    if ($screen === 'edit') {
        $id = getString('id');
        $survey = $id !== '' ? surveyById($data, $id) : null;

        if ($id !== '' && !$survey) {
            http_response_code(404);
            echo layout(
                'エラー',
                '<div class="card"><h1>アンケートが存在しません。</h1></div>'
            );
            exit;
        }

        echo layout(
            'アンケート編集',
            renderEdit($data, $survey),
            true,
            takeFlash()
        );
        exit;
    }

    if ($screen === 'preview') {
        $id = getString('id');
        $survey = surveyById($data, $id);

        if (!$survey) {
            http_response_code(404);
            echo layout(
                'エラー',
                '<div class="card"><h1>アンケートが存在しません。</h1></div>'
            );
            exit;
        }

        echo layout(
            'プレビュー',
            renderPreview($survey),
            true,
            takeFlash()
        );
        exit;
    }

    if ($screen === 'analytics') {
        $id = getString('id');

        if (!validateId($id)) {
            http_response_code(400);
            echo layout(
                'エラー',
                '<div class="card"><h1>対象アンケートを指定してください。</h1></div>'
            );
            exit;
        }

        if (getString('export') === 'csv') {
            outputCsv($data, $id);
        }

        if (getString('export') === 'pdf') {
            outputPdf($data, $id);
        }

        $survey = surveyById($data, $id);

        if (!$survey) {
            http_response_code(404);
            echo layout(
                'エラー',
                '<div class="card"><h1>アンケートが存在しません。</h1></div>'
            );
            exit;
        }

        echo layout(
            '回答集計・分析',
            renderAnalytics($data, $survey),
            true,
            takeFlash()
        );
        exit;
    }

    if ($screen === 'send') {
        $id = getString('id');

        if (!validateId($id)) {
            http_response_code(400);
            echo layout(
                'エラー',
                '<div class="card"><h1>対象アンケートを指定してください。</h1></div>'
            );
            exit;
        }

        $survey = surveyById($data, $id);

        if (!$survey) {
            http_response_code(404);
            echo layout(
                'エラー',
                '<div class="card"><h1>アンケートが存在しません。</h1></div>'
            );
            exit;
        }

        echo layout(
            '顧客選択・メール送信',
            renderSend($data, $survey),
            true,
            takeFlash()
        );
        exit;
    }

    if ($screen === 'kintone') {
        echo layout(
            'kintone設定',
            renderKintone($data),
            true,
            takeFlash()
        );
        exit;
    }

    if ($screen === 'mail') {
        echo layout(
            'メールサーバ設定',
            renderMail($data),
            true,
            takeFlash()
        );
        exit;
    }

    http_response_code(404);

    echo layout(
        'エラー',
        '<div class="card">'
        . '<h1>画面が見つかりません</h1>'
        . '<p>指定されたscreenは利用できません。</p>'
        . '</div>'
    );

} catch (Throwable $e) {
    /*
     * エラー処理自身が例外を起こさないよう、
     * ここでは固定的な表示を中心とする。
     */
    error_log(
        'SurveyPOC error: '
        . get_class($e)
        . ': '
        . $e->getMessage()
    );

    http_response_code(500);

    $detail = '';

    /*
     * display_errors=0が通常なので秘密情報・内部情報を
     * ブラウザへ露出させない。
     */
    if ((string)ini_get('display_errors') !== ''
        && (bool)ini_get('display_errors')) {
        $detail = $e->getMessage();
    }

    try {
        echo layout(
            '処理エラー',
            '<div class="card">'
            . '<h1>処理を完了できませんでした</h1>'
            . '<p>入力値、設定値、外部サービスの接続状態を確認して再度お試しください。</p>'
            . ($detail !== ''
                ? '<pre>' . h($detail) . '</pre>'
                : '')
            . '</div>'
        );
    } catch (Throwable $renderError) {
        /*
         * エラー表示処理自身が失敗しても白画面にはしない。
         */
        echo '<!doctype html><html lang="ja"><meta charset="utf-8">'
            . '<title>処理エラー</title>'
            . '<h1>処理を完了できませんでした</h1>'
            . '<p>入力値、設定値、外部サービスの接続状態を確認して再度お試しください。</p>'
            . '</html>';
    }
}


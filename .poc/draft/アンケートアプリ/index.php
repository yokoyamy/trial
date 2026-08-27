<?php
declare(strict_types=1);

/*
 * アンケートアプリ POC
 * PHP 8.5 / Apache 2.4 / DBなし / cURLなし
 * すべて index.php で完結
 */

session_start();

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0775, true);
}

date_default_timezone_set('Asia/Tokyo');

/* =========================================================
 * 共通
 * ========================================================= */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function id(): string
{
    return 'survey-' . bin2hex(random_bytes(8));
}

function uid(string $prefix = 'id'): string
{
    return $prefix . '-' . bin2hex(random_bytes(6));
}

function dataFile(string $name): string
{
    return DATA_DIR . DIRECTORY_SEPARATOR . $name . '.json';
}

function readJson(string $name, mixed $default = []): mixed
{
    $file = dataFile($name);

    if (!is_file($file)) {
        return $default;
    }

    $json = @file_get_contents($file);

    if ($json === false || trim($json) === '') {
        return $default;
    }

    $data = json_decode($json, true);

    return json_last_error() === JSON_ERROR_NONE ? $data : $default;
}

function writeJson(string $name, mixed $data): bool
{
    $file = dataFile($name);
    $tmp  = $file . '.tmp';

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $fp = @fopen($tmp, 'wb');

    if (!$fp) {
        return false;
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }

        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return @rename($tmp, $file);
    } catch (Throwable) {
        fclose($fp);
        @unlink($tmp);
        return false;
    }
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function query(string $key, string $default = ''): string
{
    return isset($_GET[$key]) && is_string($_GET[$key])
        ? trim($_GET[$key])
        : $default;
}

function post(string $key, string $default = ''): string
{
    return isset($_POST[$key]) && is_string($_POST[$key])
        ? trim($_POST[$key])
        : $default;
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function getFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

/* =========================================================
 * アンケートデータ
 * ========================================================= */

function defaultSurvey(): array
{
    $surveyId = id();

    return [
        'id' => $surveyId,
        'title' => '',
        'description' => '',
        'start_at' => '',
        'end_at' => '',
        'status' => 'draft',
        'numbering' => 'global',
        'created_at' => now(),
        'updated_at' => now(),
        'groups' => [
            [
                'id' => uid('group'),
                'title' => 'グループ1',
                'questions' => [],
            ],
        ],
    ];
}

/*
 * ここが今回の重要部分。
 *
 * 既存 surveys.json に id が存在しないレコードがあっても、
 * 一覧処理まで NULL のまま流さない。
 *
 * normalizeSurvey() を通った時点で、
 * 必ず string の id を持つアンケートにする。
 */
function normalizeSurvey(array $survey): array
{
    if (
        !isset($survey['id']) ||
        !is_string($survey['id']) ||
        trim($survey['id']) === ''
    ) {
        $survey['id'] = id();
    }

    $survey['title'] = isset($survey['title'])
        ? (string)$survey['title']
        : '';

    $survey['description'] = isset($survey['description'])
        ? (string)$survey['description']
        : '';

    $survey['start_at'] = isset($survey['start_at'])
        ? (string)$survey['start_at']
        : '';

    $survey['end_at'] = isset($survey['end_at'])
        ? (string)$survey['end_at']
        : '';

    $survey['created_at'] = isset($survey['created_at'])
        ? (string)$survey['created_at']
        : now();

    $survey['updated_at'] = isset($survey['updated_at'])
        ? (string)$survey['updated_at']
        : $survey['created_at'];

    $survey['status'] = in_array(
        $survey['status'] ?? '',
        ['draft', 'published', 'stopped', 'ended'],
        true
    )
        ? $survey['status']
        : 'draft';

    $survey['numbering'] = ($survey['numbering'] ?? 'global') === 'group'
        ? 'group'
        : 'global';

    if (!isset($survey['groups']) || !is_array($survey['groups'])) {
        $survey['groups'] = [];
    }

    foreach ($survey['groups'] as $gi => &$group) {
        if (!is_array($group)) {
            $group = [];
        }

        if (
            !isset($group['id']) ||
            !is_string($group['id']) ||
            $group['id'] === ''
        ) {
            $group['id'] = uid('group');
        }

        $group['title'] = isset($group['title'])
            ? (string)$group['title']
            : '';

        if (!isset($group['questions']) || !is_array($group['questions'])) {
            $group['questions'] = [];
        }

        foreach ($group['questions'] as &$question) {
            if (!is_array($question)) {
                $question = [];
            }

            if (
                !isset($question['id']) ||
                !is_string($question['id']) ||
                $question['id'] === ''
            ) {
                $question['id'] = uid('question');
            }

            $question['text'] = isset($question['text'])
                ? (string)$question['text']
                : '';

            $question['type'] = in_array(
                $question['type'] ?? '',
                ['single', 'multiple', 'text'],
                true
            )
                ? $question['type']
                : 'single';

            $question['required'] = !empty($question['required']);

            if (!isset($question['options']) || !is_array($question['options'])) {
                $question['options'] = [];
            }

            foreach ($question['options'] as &$option) {
                if (!is_array($option)) {
                    $option = ['id' => uid('option'), 'label' => (string)$option];
                }

                if (
                    !isset($option['id']) ||
                    !is_string($option['id']) ||
                    $option['id'] === ''
                ) {
                    $option['id'] = uid('option');
                }

                $option['label'] = isset($option['label'])
                    ? (string)$option['label']
                    : '';
            }
            unset($option);

            if (
                !isset($question['branches']) ||
                !is_array($question['branches'])
            ) {
                $question['branches'] = [];
            }
        }
        unset($question);
    }
    unset($group);

    renumberQuestions($survey);

    return $survey;
}

function loadSurveys(): array
{
    $surveys = readJson('surveys', []);

    if (!is_array($surveys)) {
        $surveys = [];
    }

    $changed = false;
    $normalized = [];

    foreach ($surveys as $survey) {
        if (!is_array($survey)) {
            continue;
        }

        $before = $survey;
        $survey = normalizeSurvey($survey);

        if ($before !== $survey) {
            $changed = true;
        }

        $normalized[] = $survey;
    }

    /*
     * 既存データの id 欠落を一度だけではなく、
     * 正規化後の surveys.json に保存して恒久的に修正。
     */
    if ($changed) {
        writeJson('surveys', $normalized);
    }

    return $normalized;
}

function saveSurveys(array $surveys): bool
{
    $normalized = [];

    foreach ($surveys as $survey) {
        if (is_array($survey)) {
            $normalized[] = normalizeSurvey($survey);
        }
    }

    return writeJson('surveys', $normalized);
}

function findSurvey(string $surveyId): ?array
{
    if ($surveyId === '') {
        return null;
    }

    foreach (loadSurveys() as $survey) {
        if (
            isset($survey['id']) &&
            is_string($survey['id']) &&
            hash_equals($survey['id'], $surveyId)
        ) {
            return $survey;
        }
    }

    return null;
}

function findSurveyIndex(array $surveys, string $surveyId): int
{
    foreach ($surveys as $i => $survey) {
        if (
            is_array($survey) &&
            isset($survey['id']) &&
            is_string($survey['id']) &&
            $survey['id'] === $surveyId
        ) {
            return $i;
        }
    }

    return -1;
}

/* =========================================================
 * ステータス
 * ========================================================= */

function effectiveStatus(array $survey): string
{
    $status = $survey['status'] ?? 'draft';

    /*
     * 終了は「公開中」かつ終了日時を過ぎた場合だけ。
     * 下書き・停止は終了日時を過ぎても終了にしない。
     */
    if (
        $status === 'published' &&
        !empty($survey['end_at'])
    ) {
        $end = strtotime((string)$survey['end_at']);

        if ($end !== false && $end < time()) {
            return 'ended';
        }
    }

    return in_array(
        $status,
        ['draft', 'published', 'stopped', 'ended'],
        true
    )
        ? $status
        : 'draft';
}

function statusLabel(string $status): string
{
    return match ($status) {
        'draft' => '下書き',
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き',
    };
}

function statusClass(string $status): string
{
    return match ($status) {
        'published' => 'published',
        'stopped' => 'stopped',
        'ended' => 'ended',
        default => 'draft',
    };
}

/* =========================================================
 * 質問番号
 * ========================================================= */

function renumberQuestions(array &$survey): void
{
    $global = 1;

    foreach ($survey['groups'] as $gi => &$group) {
        $local = 1;

        foreach ($group['questions'] as &$question) {
            $question['number'] = ($survey['numbering'] ?? 'global') === 'group'
                ? 'Q' . ($gi + 1) . '-' . $local
                : 'Q' . $global;

            $local++;
            $global++;
        }

        unset($question);
    }

    unset($group);
}

/* =========================================================
 * 回答
 * ========================================================= */

function loadResponses(): array
{
    $responses = readJson('responses', []);
    return is_array($responses) ? $responses : [];
}

function saveResponses(array $responses): bool
{
    return writeJson('responses', array_values($responses));
}

function surveyResponses(string $surveyId): array
{
    if ($surveyId === '') {
        return [];
    }

    $result = [];

    foreach (loadResponses() as $response) {
        if (
            is_array($response) &&
            isset($response['survey_id']) &&
            is_string($response['survey_id']) &&
            $response['survey_id'] === $surveyId
        ) {
            $result[] = $response;
        }
    }

    return $result;
}

/* =========================================================
 * 顧客
 * ========================================================= */

function loadCustomers(): array
{
    $customers = readJson('customers', []);
    return is_array($customers) ? $customers : [];
}

function saveCustomers(array $customers): bool
{
    return writeJson('customers', array_values($customers));
}

/* =========================================================
 * 送信履歴
 * ========================================================= */

function loadMailLogs(): array
{
    $logs = readJson('mail_logs', []);
    return is_array($logs) ? $logs : [];
}

function saveMailLogs(array $logs): bool
{
    return writeJson('mail_logs', array_values($logs));
}

/* =========================================================
 * kintone / SMTP 設定
 * ========================================================= */

function loadConfig(string $name): array
{
    $data = readJson($name, []);
    return is_array($data) ? $data : [];
}

function saveConfig(string $name, array $data): bool
{
    return writeJson($name, $data);
}

/* =========================================================
 * kintone
 *
 * cURLは使用しない。
 * PHP標準の stream_context_create() を使用する。
 * ========================================================= */

function kintoneRequest(
    string $method,
    string $url,
    array $headers = [],
    ?array $body = null,
    bool $verifySsl = true
): array {
    $headerText = '';

    foreach ($headers as $header) {
        $headerText .= $header . "\r\n";
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => $headerText,
            'ignore_errors' => true,
            'timeout' => 15,
        ],
        'ssl' => [
            'verify_peer' => $verifySsl,
            'verify_peer_name' => $verifySsl,
        ],
    ];

    if ($body !== null) {
        $options['http']['content'] = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);

    $status = 0;

    if (isset($http_response_header[0])) {
        if (preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
    }

    return [
        'status' => $status,
        'body' => $result === false ? '' : $result,
    ];
}

function kintoneSettings(): array
{
    return loadConfig('kintone');
}

function kintoneAuthorization(string $login, string $password): string
{
    return base64_encode($login . ':' . $password);
}

function kintoneTest(array $config): array
{
    $subdomain = trim((string)($config['subdomain'] ?? ''));
    $appId = trim((string)($config['app_id'] ?? ''));
    $login = (string)($config['login'] ?? '');
    $password = (string)($config['password'] ?? '');

    if ($subdomain === '' || $appId === '' || $login === '' || $password === '') {
        return [
            'ok' => false,
            'message' => 'kintone設定が不足しています。',
        ];
    }

    $url = 'https://' . $subdomain .
        '.cybozu.com/k/v1/app.json?id=' .
        rawurlencode($appId);

    $result = kintoneRequest(
        'GET',
        $url,
        [
            'X-Cybozu-Authorization: ' .
            kintoneAuthorization($login, $password),
            'Content-Type: application/json',
        ],
        null,
        !empty($config['verify_ssl'])
    );

    if ($result['status'] >= 200 && $result['status'] < 300) {
        return [
            'ok' => true,
            'message' => 'kintoneへの接続に成功しました。',
        ];
    }

    return [
        'ok' => false,
        'message' => 'kintone接続に失敗しました。HTTP ' .
            $result['status'],
    ];
}

/* =========================================================
 * SMTP
 *
 * 外部SMTPへ接続するための最低限のSMTPクライアント。
 * cURLは使用しない。
 * ========================================================= */

function smtpSend(
    array $config,
    string $to,
    string $toName,
    string $subject,
    string $body
): array {
    $host = trim((string)($config['server'] ?? ''));
    $port = (int)($config['port'] ?? 587);
    $user = (string)($config['username'] ?? '');
    $pass = (string)($config['password'] ?? '');
    $from = trim((string)($config['from_email'] ?? ''));
    $fromName = (string)($config['from_name'] ?? '');
    $reply = trim((string)($config['reply_to'] ?? ''));
    $encryption = (string)($config['encryption'] ?? 'tls');

    if ($host === '' || $from === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [
            'ok' => false,
            'message' => 'SMTP設定または送信先メールアドレスが不正です。',
        ];
    }

    $target = $host . ':' . $port;

    if ($encryption === 'ssl') {
        $target = 'ssl://' . $target;
    }

    $socket = @stream_socket_client(
        $target,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        return [
            'ok' => false,
            'message' => 'SMTPサーバーへ接続できませんでした。',
        ];
    }

    stream_set_timeout($socket, 15);

    $read = function () use ($socket): string {
        $response = '';

        while (($line = fgets($socket, 8192)) !== false) {
            $response .= $line;

            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        return $response;
    };

    $command = function (string $cmd, array $codes) use ($socket, $read): bool {
        fwrite($socket, $cmd . "\r\n");

        $response = $read();

        $code = (int)substr($response, 0, 3);

        return in_array($code, $codes, true);
    };

    $greeting = $read();

    if ((int)substr($greeting, 0, 3) !== 220) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'SMTPサーバーの応答が不正です。',
        ];
    }

    if (!$command('EHLO localhost', [250])) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'SMTP EHLOに失敗しました。',
        ];
    }

    if ($encryption === 'tls') {
        if (!$command('STARTTLS', [220])) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'SMTP STARTTLSに失敗しました。',
            ];
        }

        $crypto = @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'TLS接続に失敗しました。',
            ];
        }

        if (!$command('EHLO localhost', [250])) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'TLS後のEHLOに失敗しました。',
            ];
        }
    }

    if ($user !== '') {
        if (!$command('AUTH LOGIN', [334])) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'SMTP認証開始に失敗しました。',
            ];
        }

        if (!$command(base64_encode($user), [334])) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'SMTPユーザー認証に失敗しました。',
            ];
        }

        if (!$command(base64_encode($pass), [235])) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'SMTPパスワード認証に失敗しました。',
            ];
        }
    }

    if (!$command('MAIL FROM:<' . $from . '>', [250])) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'MAIL FROMに失敗しました。',
        ];
    }

    if (!$command('RCPT TO:<' . $to . '>', [250, 251])) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'RCPT TOに失敗しました。',
        ];
    }

    if (!$command('DATA', [354])) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'SMTP DATAに失敗しました。',
        ];
    }

    $encodedSubject = '=?UTF-8?B?' .
        base64_encode($subject) .
        '?=';

    $encodedFromName = '=?UTF-8?B?' .
        base64_encode($fromName) .
        '?=';

    $headers = [
        'From: ' . $encodedFromName . ' <' . $from . '>',
        'To: ' . ($toName !== '' ? '=?UTF-8?B?' .
            base64_encode($toName) .
            '?= ' : '') . '<' . $to . '>',
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    if ($reply !== '') {
        $headers[] = 'Reply-To: <' . $reply . '>';
    }

    $message = implode("\r\n", $headers) .
        "\r\n\r\n" .
        preg_replace('/^\./m', '..', $body) .
        "\r\n.";

    fwrite($socket, $message . "\r\n");

    $response = $read();

    $ok = (int)substr($response, 0, 3) === 250;

    @fwrite($socket, "QUIT\r\n");
    fclose($socket);

    return [
        'ok' => $ok,
        'message' => $ok
            ? 'メールを送信しました。'
            : 'メール送信に失敗しました。',
    ];
}

/* =========================================================
 * HTML
 * ========================================================= */

function pageStart(string $title): void
{
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - アンケート</title>
<style>
*{box-sizing:border-box}
body{
    margin:0;
    background:#f3f6fa;
    color:#172033;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
        "Noto Sans JP","Yu Gothic",sans-serif;
}
a{color:#2563eb;text-decoration:none}
button,input,textarea,select{font:inherit}
.header{
    background:#172554;
    color:#fff;
    padding:18px 28px;
}
.header-inner{
    max-width:1200px;
    margin:auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}
.logo{font-weight:700;font-size:20px}
.container{max-width:1200px;margin:28px auto;padding:0 20px}
.card{
    background:#fff;
    border-radius:12px;
    padding:22px;
    margin-bottom:20px;
    box-shadow:0 2px 8px rgba(15,23,42,.06);
}
h1{font-size:26px;margin:0 0 20px}
h2{font-size:19px;margin:0 0 16px}
h3{font-size:16px}
.grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
}
.field{margin-bottom:16px}
.field label{
    display:block;
    font-weight:600;
    margin-bottom:7px;
}
input[type=text],
input[type=datetime-local],
input[type=email],
input[type=number],
input[type=password],
select,
textarea{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:10px 12px;
    background:#fff;
}
textarea{min-height:120px;resize:vertical}
.actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    align-items:center;
}
.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:0;
    border-radius:8px;
    padding:9px 15px;
    cursor:pointer;
    background:#2563eb;
    color:#fff;
}
.btn:hover{background:#1d4ed8}
.btn.secondary{background:#64748b}
.btn.light{background:#e2e8f0;color:#172033}
.btn.danger{background:#dc2626}
.btn.green{background:#16a34a}
.badge{
    display:inline-block;
    border-radius:999px;
    padding:4px 9px;
    font-size:12px;
    font-weight:700;
}
.badge.draft{background:#e2e8f0;color:#334155}
.badge.published{background:#dcfce7;color:#166534}
.badge.stopped{background:#fee2e2;color:#991b1b}
.badge.ended{background:#ede9fe;color:#5b21b6}
table{
    width:100%;
    border-collapse:collapse;
}
th,td{
    text-align:left;
    padding:12px 10px;
    border-bottom:1px solid #e2e8f0;
    vertical-align:top;
}
th{font-size:13px;color:#475569;background:#f8fafc}
.notice{
    padding:13px 15px;
    border-radius:8px;
    margin-bottom:16px;
    background:#eff6ff;
    color:#1e40af;
}
.notice.error{background:#fef2f2;color:#991b1b}
.notice.success{background:#f0fdf4;color:#166534}
.question{
    border:1px solid #dbe3ed;
    border-radius:10px;
    padding:18px;
    margin:12px 0;
}
.question-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}
.option-row{
    display:grid;
    grid-template-columns:1fr auto;
    gap:8px;
    margin:7px 0;
}
.empty{
    text-align:center;
    padding:40px 20px;
    color:#64748b;
}
.stats{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
}
.stat{
    background:#f8fafc;
    border-radius:10px;
    padding:18px;
}
.stat-value{font-size:26px;font-weight:700}
.small{font-size:12px;color:#64748b}
@media(max-width:800px){
    .grid,.stats{grid-template-columns:1fr}
    table{display:block;overflow-x:auto}
}
</style>
</head>
<body>
<header class="header">
<div class="header-inner">
<div class="logo">アンケートアプリ</div>
<a href="?screen=list" style="color:#fff">アンケート一覧</a>
</div>
</header>
<main class="container">
<?php
}

function pageEnd(): void
{
    ?>
</main>
</body>
</html>
<?php
}

function showFlash(): void
{
    $flash = getFlash();

    if ($flash) {
        ?>
        <div class="notice <?= h($flash['type']) ?>">
            <?= h($flash['message']) ?>
        </div>
        <?php
    }
}

/* =========================================================
 * 一覧
 * ========================================================= */

function screenList(): void
{
    $surveys = loadSurveys();

    $keyword = query('q');
    $statusFilter = query('status', 'all');
    $sort = query('sort', 'updated_desc');

    $rows = [];

    foreach ($surveys as $survey) {
        $status = effectiveStatus($survey);

        if ($keyword !== '' &&
            mb_stripos((string)$survey['title'], $keyword) === false) {
            continue;
        }

        if ($statusFilter !== 'all' && $status !== $statusFilter) {
            continue;
        }

        /*
         * surveyResponses() に NULL を渡さない。
         * normalizeSurvey() 後なので id は必ず存在する。
         */
        $surveyId = (string)$survey['id'];
        $responses = surveyResponses($surveyId);

        $survey['_status'] = $status;
        $survey['_response_count'] = count($responses);

        $rows[] = $survey;
    }

    usort($rows, function (array $a, array $b) use ($sort): int {
        return match ($sort) {
            'updated_asc' =>
                strcmp((string)$a['updated_at'], (string)$b['updated_at']),
            'responses_desc' =>
                $b['_response_count'] <=> $a['_response_count'],
            'responses_asc' =>
                $a['_response_count'] <=> $b['_response_count'],
            'start_desc' =>
                strcmp((string)$b['start_at'], (string)$a['start_at']),
            'start_asc' =>
                strcmp((string)$a['start_at'], (string)$b['start_at']),
            default =>
                strcmp((string)$b['updated_at'], (string)$a['updated_at']),
        };
    });

    pageStart('アンケート一覧');
    showFlash();
    ?>
    <div class="actions" style="justify-content:space-between;margin-bottom:18px">
        <h1>アンケート一覧</h1>
        <div class="actions">
            <a class="btn" href="?screen=edit">新規作成</a>
            <a class="btn secondary" href="?screen=kintone">kintone設定</a>
            <a class="btn secondary" href="?screen=mail">メール設定</a>
        </div>
    </div>

    <div class="card">
        <form method="get">
            <input type="hidden" name="screen" value="list">
            <div class="grid">
                <div class="field">
                    <label>タイトル検索</label>
                    <input
                        type="text"
                        name="q"
                        value="<?= h($keyword) ?>"
                        placeholder="タイトルを入力してEnter"
                    >
                </div>
                <div class="field">
                    <label>ステータス</label>
                    <select name="status">
                        <option value="all" <?= $statusFilter==='all'?'selected':'' ?>>すべて</option>
                        <option value="published" <?= $statusFilter==='published'?'selected':'' ?>>公開中</option>
                        <option value="draft" <?= $statusFilter==='draft'?'selected':'' ?>>下書き</option>
                        <option value="stopped" <?= $statusFilter==='stopped'?'selected':'' ?>>停止</option>
                        <option value="ended" <?= $statusFilter==='ended'?'selected':'' ?>>終了</option>
                    </select>
                </div>
            </div>

            <div class="actions">
                <select name="sort">
                    <option value="updated_desc" <?= $sort==='updated_desc'?'selected':'' ?>>更新日：新しい順</option>
                    <option value="updated_asc" <?= $sort==='updated_asc'?'selected':'' ?>>更新日：古い順</option>
                    <option value="responses_desc" <?= $sort==='responses_desc'?'selected':'' ?>>回答数：多い順</option>
                    <option value="responses_asc" <?= $sort==='responses_asc'?'selected':'' ?>>回答数：少ない順</option>
                    <option value="start_desc" <?= $sort==='start_desc'?'selected':'' ?>>開始日：新しい順</option>
                    <option value="start_asc" <?= $sort==='start_asc'?'selected':'' ?>>開始日：古い順</option>
                </select>
                <button class="btn" type="submit">検索・絞り込み</button>
            </div>
        </form>
    </div>

    <div class="card">
    <?php if (!$rows): ?>
        <div class="empty">アンケートがありません。</div>
    <?php else: ?>
        <table>
        <thead>
        <tr>
            <th>タイトル</th>
            <th>作成日</th>
            <th>更新日</th>
            <th>開始日時</th>
            <th>終了日時</th>
            <th>状態</th>
            <th>回答数</th>
            <th>操作</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $survey): ?>
            <tr>
                <td>
                    <strong><?= h($survey['title'] ?: '無題のアンケート') ?></strong>
                </td>
                <td><?= h($survey['created_at']) ?></td>
                <td><?= h($survey['updated_at']) ?></td>
                <td><?= h($survey['start_at']) ?></td>
                <td><?= h($survey['end_at']) ?></td>
                <td>
                    <span class="badge <?= h(statusClass($survey['_status'])) ?>">
                        <?= h(statusLabel($survey['_status'])) ?>
                    </span>
                </td>
                <td><?= (int)$survey['_response_count'] ?></td>
                <td>
                    <div class="actions">
                        <a href="?screen=edit&id=<?= rawurlencode($survey['id']) ?>">編集</a>
                        <a href="?screen=preview&id=<?= rawurlencode($survey['id']) ?>">プレビュー</a>
                        <a href="?screen=analytics&id=<?= rawurlencode($survey['id']) ?>">集計</a>
                        <a href="?screen=send&id=<?= rawurlencode($survey['id']) ?>">送信</a>
                        <a href="?screen=answer&id=<?= rawurlencode($survey['id']) ?>" target="_blank">回答URL</a>
                    </div>
                    <form method="post" style="margin-top:8px">
                        <input type="hidden" name="action" value="duplicate">
                        <input type="hidden" name="id" value="<?= h($survey['id']) ?>">
                        <button class="btn light" type="submit">複製</button>
                    </form>
                    <form method="post" style="margin-top:8px"
                          onsubmit="return confirm('削除しますか？')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= h($survey['id']) ?>">
                        <button class="btn danger" type="submit">削除</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
    <?php endif; ?>
    </div>
    <?php

    pageEnd();
}

/* =========================================================
 * 編集
 * ========================================================= */

function screenEdit(): void
{
    $surveyId = query('id');

    if ($surveyId !== '') {
        $survey = findSurvey($surveyId);

        if ($survey === null) {
            pageStart('エラー');
            ?>
            <div class="notice error">対象アンケートを特定できません。</div>
            <a class="btn" href="?screen=list">一覧へ戻る</a>
            <?php
            pageEnd();
            return;
        }
    } else {
        $survey = defaultSurvey();
    }

    pageStart($surveyId === '' ? '新規作成' : 'アンケート編集');
    showFlash();
    ?>

    <div class="actions" style="justify-content:space-between">
        <h1><?= $surveyId === '' ? 'アンケート作成' : 'アンケート編集' ?></h1>
        <div class="actions">
            <a class="btn light"
               href="?screen=list"
               onclick="return confirm('編集内容を破棄しますか？')">キャンセル</a>

            <?php if ($surveyId !== ''): ?>
                <a class="btn secondary"
                   href="?screen=preview&id=<?= rawurlencode($survey['id']) ?>">
                    プレビュー
                </a>
            <?php endif; ?>
        </div>
    </div>

    <form method="post">
        <input type="hidden" name="action" value="save_survey">
        <input type="hidden" name="id" value="<?= h($survey['id']) ?>">

        <div class="card">
            <div class="grid">
                <div class="field">
                    <label>タイトル *</label>
                    <input type="text" name="title"
                           value="<?= h($survey['title']) ?>"
                           required maxlength="200">
                </div>

                <div class="field">
                    <label>状態</label>
                    <div>
                        <span class="badge <?= h(statusClass(effectiveStatus($survey))) ?>">
                            <?= h(statusLabel(effectiveStatus($survey))) ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="field">
                <label>説明</label>
                <textarea name="description"><?= h($survey['description']) ?></textarea>
            </div>

            <div class="grid">
                <div class="field">
                    <label>開始日時</label>
                    <input type="datetime-local" name="start_at"
                           value="<?= h($survey['start_at']) ?>">
                </div>

                <div class="field">
                    <label>終了日時</label>
                    <input type="datetime-local" name="end_at"
                           value="<?= h($survey['end_at']) ?>">
                </div>
            </div>

            <div class="field">
                <label>質問番号の採番方式</label>
                <select name="numbering">
                    <option value="global"
                        <?= $survey['numbering']==='global'?'selected':'' ?>>
                        アンケート全体で通番（Q1、Q2、Q3…）
                    </option>
                    <option value="group"
                        <?= $survey['numbering']==='group'?'selected':'' ?>>
                        グループ単位（Q1-1、Q1-2、Q2-1…）
                    </option>
                </select>
            </div>
        </div>

        <div class="card">
            <div class="actions" style="justify-content:space-between">
                <h2>グループ・質問</h2>
                <button class="btn" type="submit"
                        name="command" value="add_group">
                    グループを追加
                </button>
            </div>

            <?php foreach ($survey['groups'] as $gi => $group): ?>
                <div class="question">
                    <div class="field">
                        <label>グループタイトル</label>
                        <input type="text"
                               name="groups[<?= $gi ?>][title]"
                               value="<?= h($group['title']) ?>">
                        <input type="hidden"
                               name="groups[<?= $gi ?>][id]"
                               value="<?= h($group['id']) ?>">
                    </div>

                    <?php foreach ($group['questions'] as $qi => $question): ?>
                        <div class="question" style="background:#f8fafc">
                            <div class="question-head">
                                <strong><?= h($question['number']) ?></strong>
                                <button class="btn danger"
                                        type="submit"
                                        name="delete_question"
                                        value="<?= h($group['id'] . '|' . $question['id']) ?>">
                                    質問削除
                                </button>
                            </div>

                            <input type="hidden"
                                   name="groups[<?= $gi ?>][questions][<?= $qi ?>][id]"
                                   value="<?= h($question['id']) ?>">

                            <div class="field">
                                <label>質問文</label>
                                <input type="text"
                                       name="groups[<?= $gi ?>][questions][<?= $qi ?>][text]"
                                       value="<?= h($question['text']) ?>">
                            </div>

                            <div class="grid">
                                <div class="field">
                                    <label>回答形式</label>
                                    <select name="groups[<?= $gi ?>][questions][<?= $qi ?>][type]">
                                        <option value="single" <?= $question['type']==='single'?'selected':'' ?>>単一選択</option>
                                        <option value="multiple" <?= $question['type']==='multiple'?'selected':'' ?>>複数選択</option>
                                        <option value="text" <?= $question['type']==='text'?'selected':'' ?>>自由記述</option>
                                    </select>
                                </div>

                                <div class="field">
                                    <label>必須</label>
                                    <label style="font-weight:normal">
                                        <input type="checkbox"
                                               name="groups[<?= $gi ?>][questions][<?= $qi ?>][required]"
                                               value="1"
                                               <?= $question['required']?'checked':'' ?>>
                                        必須回答
                                    </label>
                                </div>
                            </div>

                            <?php if ($question['type'] !== 'text'): ?>
                                <label>選択肢</label>

                                <?php foreach ($question['options'] as $oi => $option): ?>
                                    <div class="option-row">
                                        <input type="hidden"
                                               name="groups[<?= $gi ?>][questions][<?= $qi ?>][options][<?= $oi ?>][id]"
                                               value="<?= h($option['id']) ?>">

                                        <input type="text"
                                               name="groups[<?= $gi ?>][questions][<?= $qi ?>][options][<?= $oi ?>][label]"
                                               value="<?= h($option['label']) ?>">

                                        <button class="btn light"
                                                type="submit"
                                                name="delete_option"
                                                value="<?= h($group['id'].'|'.$question['id'].'|'.$option['id']) ?>">
                                            削除
                                        </button>
                                    </div>
                                <?php endforeach; ?>

                                <button class="btn light"
                                        type="submit"
                                        name="add_option"
                                        value="<?= h($group['id'].'|'.$question['id']) ?>">
                                    選択肢追加
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <button class="btn secondary"
                            type="submit"
                            name="add_question"
                            value="<?= h($group['id']) ?>">
                        質問を追加
                    </button>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="actions">
            <button class="btn green" type="submit">保存して一覧へ</button>
        </div>
    </form>
    <?php

    pageEnd();
}

/* =========================================================
 * プレビュー
 * ========================================================= */

function renderQuestions(array $survey, bool $interactive = false): void
{
    foreach ($survey['groups'] as $group):
        ?>
        <div class="card">
            <h2><?= h($group['title']) ?></h2>

            <?php foreach ($group['questions'] as $question): ?>
                <div class="question">
                    <h3>
                        <?= h($question['number']) ?>
                        <?= $question['required'] ? ' *' : '' ?>
                    </h3>

                    <p><?= nl2br(h($question['text'])) ?></p>

                    <?php if ($question['type'] === 'text'): ?>
                        <?php if ($interactive): ?>
                            <textarea
                                name="answers[<?= h($question['id']) ?>]"
                                <?= $question['required'] ? 'required' : '' ?>
                            ></textarea>
                        <?php else: ?>
                            <textarea disabled></textarea>
                        <?php endif; ?>

                    <?php elseif ($question['type'] === 'single'): ?>

                        <?php foreach ($question['options'] as $option): ?>
                            <label style="display:block;margin:8px 0">
                                <input
                                    type="radio"
                                    name="answers[<?= h($question['id']) ?>]"
                                    value="<?= h($option['id']) ?>"
                                    <?= $interactive && $question['required'] ? 'required' : '' ?>
                                    <?= !$interactive ? 'disabled' : '' ?>
                                >
                                <?= h($option['label']) ?>
                            </label>
                        <?php endforeach; ?>

                    <?php else: ?>

                        <?php foreach ($question['options'] as $option): ?>
                            <label style="display:block;margin:8px 0">
                                <input
                                    type="checkbox"
                                    name="answers[<?= h($question['id']) ?>][]"
                                    value="<?= h($option['id']) ?>"
                                    <?= !$interactive ? 'disabled' : '' ?>
                                >
                                <?= h($option['label']) ?>
                            </label>
                        <?php endforeach; ?>

                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    endforeach;
}

function screenPreview(): void
{
    $surveyId = query('id');
    $survey = findSurvey($surveyId);

    if ($survey === null) {
        pageStart('エラー');
        ?>
        <div class="notice error">
            対象アンケートを特定できないため、プレビューを表示できません。
        </div>
        <a class="btn" href="?screen=list">一覧へ戻る</a>
        <?php
        pageEnd();
        return;
    }

    pageStart('プレビュー');
    ?>
    <div class="actions" style="justify-content:space-between">
        <h1>プレビュー</h1>
        <a class="btn light"
           href="?screen=edit&id=<?= rawurlencode($survey['id']) ?>">
            編集へ戻る
        </a>
    </div>

    <div class="card">
        <h1><?= h($survey['title']) ?></h1>
        <p><?= nl2br(h($survey['description'])) ?></p>
    </div>

    <?php renderQuestions($survey, false); ?>

    <?php
    pageEnd();
}

/* =========================================================
 * 回答
 * ========================================================= */

function screenAnswer(): void
{
    $surveyId = query('id');
    $survey = findSurvey($surveyId);

    if ($survey === null) {
        pageStart('回答');
        ?>
        <div class="notice error">
            対象アンケートを特定できません。
        </div>
        <?php
        pageEnd();
        return;
    }

    $status = effectiveStatus($survey);

    if ($status !== 'published') {
        pageStart('回答');
        ?>
        <div class="notice error">
            このアンケートは現在回答できません。
        </div>
        <?php
        pageEnd();
        return;
    }

    if (post('action') === 'answer_prepare') {
        $answers = $_POST['answers'] ?? [];

        if (!is_array($answers)) {
            $answers = [];
        }

        $errors = [];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                if (!$question['required']) {
                    continue;
                }

                $value = $answers[$question['id']] ?? null;

                if (
                    $value === null ||
                    $value === '' ||
                    $value === []
                ) {
                    $errors[] = $question['number'] . ' は必須です。';
                }
            }
        }

        if (!$errors) {
            $_SESSION['answer_draft'] = [
                'survey_id' => $survey['id'],
                'answers' => $answers,
            ];

            redirect(
                '?screen=confirm&id=' .
                rawurlencode($survey['id'])
            );
        }

        pageStart('回答');
        ?>
        <div class="notice error">
            <?php foreach ($errors as $error): ?>
                <div><?= h($error) ?></div>
            <?php endforeach; ?>
        </div>
        <?php
    } else {
        pageStart('アンケート回答');
    }
    ?>

    <div class="card">
        <h1><?= h($survey['title']) ?></h1>
        <p><?= nl2br(h($survey['description'])) ?></p>
    </div>

    <form method="post">
        <input type="hidden" name="action" value="answer_prepare">
        <?php renderQuestions($survey, true); ?>

        <button class="btn" type="submit">回答を確認する</button>
    </form>

    <?php
    pageEnd();
}

/* =========================================================
 * 確認
 * ========================================================= */

function screenConfirm(): void
{
    $surveyId = query('id');
    $survey = findSurvey($surveyId);

    $draft = $_SESSION['answer_draft'] ?? null;

    if (
        $survey === null ||
        !is_array($draft) ||
        ($draft['survey_id'] ?? '') !== $surveyId
    ) {
        pageStart('回答確認');
        ?>
        <div class="notice error">
            回答内容を確認できません。
        </div>
        <?php
        pageEnd();
        return;
    }

    $answers = is_array($draft['answers'] ?? null)
        ? $draft['answers']
        : [];

    if (post('action') === 'submit_answer') {
        $responses = loadResponses();

        $responses[] = [
            'id' => uid('response'),
            'survey_id' => $survey['id'],
            'answers' => $answers,
            'customer_id' => null,
            'submitted_at' => now(),
        ];

        if (!saveResponses($responses)) {
            pageStart('回答完了');
            ?>
            <div class="notice error">
                回答を保存できませんでした。
            </div>
            <?php
            pageEnd();
            return;
        }

        unset($_SESSION['answer_draft']);

        redirect(
            '?screen=complete&id=' .
            rawurlencode($survey['id'])
        );
    }

    pageStart('回答確認');
    ?>

    <div class="card">
        <h1>回答確認</h1>
        <p>以下の内容で送信します。</p>
    </div>

    <?php foreach ($survey['groups'] as $group): ?>
        <div class="card">
            <h2><?= h($group['title']) ?></h2>

            <?php foreach ($group['questions'] as $question): ?>
                <div class="question">
                    <strong><?= h($question['number']) ?></strong>
                    <p><?= nl2br(h($question['text'])) ?></p>

                    <?php
                    $value = $answers[$question['id']] ?? '';
                    ?>

                    <?php if (is_array($value)): ?>
                        <?php foreach ($value as $v): ?>
                            <div><?= h($v) ?></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div><?= nl2br(h((string)$value)) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <div class="actions">
        <a class="btn light"
           href="?screen=answer&id=<?= rawurlencode($survey['id']) ?>">
            修正する
        </a>

        <form method="post">
            <input type="hidden" name="action" value="submit_answer">
            <button class="btn green" type="submit">送信する</button>
        </form>
    </div>

    <?php
    pageEnd();
}

/* =========================================================
 * 完了
 * ========================================================= */

function screenComplete(): void
{
    $surveyId = query('id');
    $survey = findSurvey($surveyId);

    pageStart('回答完了');
    ?>
    <div class="card" style="text-align:center;padding:50px 20px">
        <h1>回答ありがとうございました</h1>

        <?php if ($survey): ?>
            <p>
                「<?= h($survey['title']) ?>」への回答を受け付けました。
            </p>
        <?php endif; ?>
    </div>
    <?php
    pageEnd();
}

/* =========================================================
 * 集計
 * ========================================================= */

function screenAnalytics(): void
{
    $surveyId = query('id');

    /*
     * 対象アンケートが特定できない場合は表示しない。
     */
    if ($surveyId === '') {
        pageStart('集計');
        ?>
        <div class="notice error">
            対象アンケートを特定できません。
        </div>
        <a class="btn" href="?screen=list">一覧へ戻る</a>
        <?php
        pageEnd();
        return;
    }

    $survey = findSurvey($surveyId);

    if ($survey === null) {
        pageStart('集計');
        ?>
        <div class="notice error">
            対象アンケートを特定できません。
        </div>
        <a class="btn" href="?screen=list">一覧へ戻る</a>
        <?php
        pageEnd();
        return;
    }

    $responses = surveyResponses($survey['id']);
    $customers = loadCustomers();

    $registered = 0;

    foreach ($responses as $response) {
        if (!empty($response['customer_id'])) {
            $registered++;
        }
    }

    $count = count($responses);
    $customerCount = count($customers);
    $unregistered = $count - $registered;
    $unanswered = max(0, $customerCount - $registered);

    $rate = $customerCount > 0
        ? round(($registered / $customerCount) * 100, 1)
        : 0;

    if (query('format') === 'csv') {
        exportCsv($survey, $responses);
        return;
    }

    if (query('format') === 'pdf') {
        exportPdf($survey, $responses);
        return;
    }

    pageStart('回答集計・分析');
    ?>

    <div class="actions" style="justify-content:space-between">
        <h1>回答集計・分析</h1>

        <div class="actions">
            <a class="btn light"
               href="?screen=list">一覧</a>

            <a class="btn"
               href="?screen=analytics&id=<?= rawurlencode($survey['id']) ?>&format=csv">
                CSV
            </a>

            <a class="btn secondary"
               href="?screen=analytics&id=<?= rawurlencode($survey['id']) ?>&format=pdf">
                PDF
            </a>
        </div>
    </div>

    <div class="card">
        <h2><?= h($survey['title']) ?></h2>

        <div class="stats">
            <div class="stat">
                <div class="small">送信対象者数</div>
                <div class="stat-value"><?= $customerCount ?></div>
            </div>

            <div class="stat">
                <div class="small">回答数</div>
                <div class="stat-value"><?= $count ?></div>
            </div>

            <div class="stat">
                <div class="small">未登録回答数</div>
                <div class="stat-value"><?= $unregistered ?></div>
            </div>

            <div class="stat">
                <div class="small">回答率</div>
                <div class="stat-value"><?= h($rate) ?>%</div>
            </div>
        </div>
    </div>

    <?php if ($count === 0): ?>
        <div class="card">
            <div class="empty">現在、回答データはありません</div>
        </div>
    <?php else: ?>

        <?php foreach ($survey['groups'] as $group): ?>
            <div class="card">
                <h2><?= h($group['title']) ?></h2>

                <?php foreach ($group['questions'] as $question): ?>
                    <div class="question">
                        <strong>
                            <?= h($question['number']) ?>
                            <?= h($question['text']) ?>
                        </strong>

                        <?php if ($question['type'] === 'text'): ?>
                            <?php foreach ($responses as $response): ?>
                                <?php
                                $value = $response['answers'][$question['id']] ?? '';
                                ?>
                                <?php if ($value !== ''): ?>
                                    <div style="margin-top:8px">
                                        <?= nl2br(h((string)$value)) ?>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php
                            $counts = [];

                            foreach ($question['options'] as $option) {
                                $counts[$option['id']] = 0;
                            }

                            foreach ($responses as $response) {
                                $answer =
                                    $response['answers'][$question['id']] ?? null;

                                if (is_array($answer)) {
                                    foreach ($answer as $v) {
                                        if (isset($counts[$v])) {
                                            $counts[$v]++;
                                        }
                                    }
                                } elseif (isset($counts[$answer])) {
                                    $counts[$answer]++;
                                }
                            }
                            ?>

                            <?php foreach ($question['options'] as $option): ?>
                                <div style="margin:10px 0">
                                    <div>
                                        <?= h($option['label']) ?>
                                        ：<?= (int)$counts[$option['id']] ?>件
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>

    <?php
    pageEnd();
}

/* =========================================================
 * CSV
 * ========================================================= */

function exportCsv(array $survey, array $responses): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey-' .
        preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$survey['id']) .
        '.csv"'
    );

    $fp = fopen('php://output', 'wb');

    fwrite($fp, "\xEF\xBB\xBF");

    $header = ['回答ID', '回答日時'];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $header[] = $question['number'] . ' ' . $question['text'];
        }
    }

    fputcsv($fp, $header);

    foreach ($responses as $response) {
        $row = [
            $response['id'] ?? '',
            $response['submitted_at'] ?? '',
        ];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                $value =
                    $response['answers'][$question['id']] ?? '';

                if (is_array($value)) {
                    $value = implode(', ', $value);
                }

                $row[] = $value;
            }
        }

        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}

/* =========================================================
 * PDF
 *
 * 外部ライブラリなしの簡易PDF出力。
 * ========================================================= */

function exportPdf(array $survey, array $responses): never
{
    /*
     * POCでは日本語フォント埋め込みライブラリを追加せず、
     * まず印刷可能なPDFとして最低限の結果を生成する。
     */
    $lines = [];

    $lines[] = 'Survey Report';
    $lines[] = (string)$survey['title'];
    $lines[] = 'Responses: ' . count($responses);

    foreach ($survey['groups'] as $group) {
        $lines[] = '';
        $lines[] = $group['title'];

        foreach ($group['questions'] as $question) {
            $lines[] =
                $question['number'] . ' ' .
                preg_replace('/[\r\n]+/', ' ', $question['text']);
        }
    }

    /*
     * 簡易PDF。
     * ASCII化できる範囲を出力する。
     */
    $content = "BT\n/F1 12 Tf\n50 780 Td\n";

    foreach ($lines as $line) {
        $line = preg_replace('/[^\x20-\x7E]/', '?', $line);
        $line = str_replace(
            ['\\', '(', ')'],
            ['\\\\', '\\(', '\\)'],
            $line
        );

        $content .= '(' . $line . ") Tj\n0 -18 Td\n";
    }

    $content .= "ET\n";

    $objects = [];

    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[] =
        "<< /Type /Page /Parent 2 0 R " .
        "/MediaBox [0 0 595 842] " .
        "/Resources << /Font << /F1 4 0 R >> >> " .
        "/Contents 5 0 R >>";
    $objects[] =
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[] =
        "<< /Length " . strlen($content) . " >>\nstream\n" .
        $content .
        "endstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $i => $object) {
        $offsets[$i + 1] = strlen($pdf);

        $pdf .= ($i + 1) . " 0 obj\n" .
            $object .
            "\nendobj\n";
    }

    $xref = strlen($pdf);

    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf(
            "%010d 00000 n \n",
            $offsets[$i]
        );
    }

    $pdf .=
        "trailer\n<< /Size " .
        (count($objects) + 1) .
        " /Root 1 0 R >>\n" .
        "startxref\n" .
        $xref .
        "\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="survey.pdf"');

    echo $pdf;
    exit;
}

/* =========================================================
 * 送信
 * ========================================================= */

function screenSend(): void
{
    $surveyId = query('id');

    if ($surveyId === '') {
        pageStart('送信');
        ?>
        <div class="notice error">
            対象アンケートを特定できません。
        </div>
        <a class="btn" href="?screen=list">一覧へ戻る</a>
        <?php
        pageEnd();
        return;
    }

    $survey = findSurvey($surveyId);

    if ($survey === null) {
        pageStart('送信');
        ?>
        <div class="notice error">
            対象アンケートを特定できません。
        </div>
        <?php
        pageEnd();
        return;
    }

    $customers = loadCustomers();
    $logs = loadMailLogs();

    $message = post('message');
    $subject = post('subject');

    if ($subject === '') {
        $subject = $survey['title'];
    }

    if ($message === '') {
        $message =
            "{顧客名} 様\n\n" .
            "アンケートへのご回答をお願いいたします。\n\n" .
            "{アンケートURL}";
    }

    if (post('action') === 'send_mail') {
        $customerId = post('customer_id');

        foreach ($customers as $customer) {
            if (($customer['id'] ?? '') !== $customerId) {
                continue;
            }

            $name = (string)($customer['name'] ?? '');
            $email = (string)($customer['email'] ?? '');

            $url =
                (
                    (!empty($_SERVER['HTTPS']) &&
                    $_SERVER['HTTPS'] !== 'off')
                    ? 'https'
                    : 'http'
                ) .
                '://' .
                $_SERVER['HTTP_HOST'] .
                rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') .
                '/index.php?screen=answer&id=' .
                rawurlencode($survey['id']);

            $body = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [$name, $url],
                $message
            );

            $config = loadConfig('smtp');
            $result = smtpSend(
                $config,
                $email,
                $name,
                $subject,
                $body
            );

            $logs[] = [
                'id' => uid('mail'),
                'survey_id' => $survey['id'],
                'customer_id' => $customerId,
                'email' => $email,
                'subject' => $subject,
                'type' => 'send',
                'status' => $result['ok'] ? 'success' : 'failed',
                'message' => $result['message'],
                'sent_at' => now(),
            ];

            saveMailLogs($logs);

            flash($result['message'], $result['ok'] ? 'success' : 'error');

            redirect(
                '?screen=send&id=' .
                rawurlencode($survey['id'])
            );
        }
    }

    pageStart('顧客選択・メール送信');
    showFlash();
    ?>

    <div class="actions" style="justify-content:space-between">
        <h1>顧客選択・メール送信</h1>
        <a class="btn light" href="?screen=list">一覧</a>
    </div>

    <div class="card">
        <h2>対象アンケート</h2>
        <strong><?= h($survey['title']) ?></strong>
    </div>

    <div class="card">
        <h2>メール作成</h2>

        <form method="post">
            <input type="hidden" name="action" value="send_mail">

            <div class="field">
                <label>顧客</label>
                <select name="customer_id" required>
                    <option value="">選択してください</option>

                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= h($customer['id'] ?? '') ?>">
                            <?= h($customer['name'] ?? '') ?>
                            -
                            <?= h($customer['email'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>件名</label>
                <input type="text"
                       name="subject"
                       value="<?= h($subject) ?>">
            </div>

            <div class="field">
                <label>本文</label>
                <textarea name="message"><?= h($message) ?></textarea>
            </div>

            <div class="small">
                使用可能な変数：
                {顧客名} / {アンケートURL}
            </div>

            <br>

            <button class="btn" type="submit">送信</button>
        </form>
    </div>

    <div class="card">
        <h2>送信履歴</h2>

        <?php
        $surveyLogs = array_filter(
            $logs,
            fn($log) =>
                is_array($log) &&
                ($log['survey_id'] ?? '') === $survey['id']
        );
        ?>

        <?php if (!$surveyLogs): ?>
            <div class="empty">送信履歴はありません。</div>
        <?php else: ?>
            <table>
            <thead>
            <tr>
                <th>日時</th>
                <th>送信先</th>
                <th>件名</th>
                <th>結果</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach (array_reverse($surveyLogs) as $log): ?>
                <tr>
                    <td><?= h($log['sent_at'] ?? '') ?></td>
                    <td><?= h($log['email'] ?? '') ?></td>
                    <td><?= h($log['subject'] ?? '') ?></td>
                    <td><?= h($log['status'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php
    pageEnd();
}

/* =========================================================
 * kintone設定
 * ========================================================= */

function screenKintone(): void
{
    $config = loadConfig('kintone');

    if (post('action') === 'save_kintone') {
        $config = [
            'subdomain' => post('subdomain'),
            'app_id' => post('app_id'),
            'login' => post('login'),
            'password' => post('password'),
            'proxy' => post('proxy'),
            'verify_ssl' => isset($_POST['verify_ssl']),
            'mapping' => [
                'organization' => post('map_organization'),
                'name' => post('map_name'),
                'email' => post('map_email'),
                'department' => post('map_department'),
                'phone' => post('map_phone'),
                'address' => post('map_address'),
            ],
        ];

        saveConfig('kintone', $config);

        flash('kintone設定を保存しました。');
        redirect('?screen=kintone');
    }

    if (post('action') === 'test_kintone') {
        $result = kintoneTest($config);

        flash(
            $result['message'],
            $result['ok'] ? 'success' : 'error'
        );

        redirect('?screen=kintone');
    }

    pageStart('kintone設定');
    showFlash();
    ?>

    <h1>kintone設定</h1>

    <div class="card">
        <form method="post">
            <input type="hidden" name="action" value="save_kintone">

            <div class="grid">
                <div class="field">
                    <label>サブドメイン</label>
                    <input type="text"
                           name="subdomain"
                           value="<?= h($config['subdomain'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>顧客管理アプリID</label>
                    <input type="number"
                           name="app_id"
                           value="<?= h($config['app_id'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>ログイン名</label>
                    <input type="text"
                           name="login"
                           value="<?= h($config['login'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>パスワード</label>
                    <input type="password"
                           name="password"
                           value="<?= h($config['password'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>Proxy</label>
                    <input type="text"
                           name="proxy"
                           value="<?= h($config['proxy'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>SSL証明書検証</label>
                    <label style="font-weight:normal">
                        <input type="checkbox"
                               name="verify_ssl"
                               value="1"
                               <?= !empty($config['verify_ssl'])?'checked':'' ?>>
                        有効
                    </label>
                </div>
            </div>

            <h2>顧客項目マッピング</h2>

            <?php
            $mapping = $config['mapping'] ?? [];
            ?>

            <div class="grid">
                <div class="field">
                    <label>組織名</label>
                    <input type="text" name="map_organization"
                           value="<?= h($mapping['organization'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>氏名</label>
                    <input type="text" name="map_name"
                           value="<?= h($mapping['name'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>メールアドレス</label>
                    <input type="text" name="map_email"
                           value="<?= h($mapping['email'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>部署名</label>
                    <input type="text" name="map_department"
                           value="<?= h($mapping['department'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>電話番号</label>
                    <input type="text" name="map_phone"
                           value="<?= h($mapping['phone'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>住所</label>
                    <input type="text" name="map_address"
                           value="<?= h($mapping['address'] ?? '') ?>">
                </div>
            </div>

            <div class="actions">
                <button class="btn" type="submit">設定保存</button>
            </div>
        </form>

        <form method="post" style="margin-top:10px">
            <input type="hidden" name="action" value="test_kintone">
            <button class="btn secondary" type="submit">
                接続テスト
            </button>
        </form>
    </div>

    <?php
    pageEnd();
}

/* =========================================================
 * メール設定
 * ========================================================= */

function screenMail(): void
{
    $config = loadConfig('smtp');

    if (post('action') === 'save_smtp') {
        $config = [
            'server' => post('server'),
            'port' => (int)post('port', '587'),
            'encryption' => post('encryption', 'tls'),
            'username' => post('username'),
            'password' => post('password'),
            'from_email' => post('from_email'),
            'from_name' => post('from_name'),
            'reply_to' => post('reply_to'),
        ];

        saveConfig('smtp', $config);

        flash('SMTP設定を保存しました。');
        redirect('?screen=mail');
    }

    pageStart('メール設定');
    showFlash();
    ?>

    <h1>メール設定</h1>

    <div class="card">
        <form method="post">
            <input type="hidden" name="action" value="save_smtp">

            <div class="grid">
                <div class="field">
                    <label>SMTPサーバ</label>
                    <input type="text" name="server"
                           value="<?= h($config['server'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>SMTPポート</label>
                    <input type="number" name="port"
                           value="<?= h($config['port'] ?? 587) ?>">
                </div>

                <div class="field">
                    <label>暗号化方式</label>
                    <select name="encryption">
                        <option value="tls"
                            <?= ($config['encryption'] ?? '')==='tls'?'selected':'' ?>>
                            STARTTLS
                        </option>
                        <option value="ssl"
                            <?= ($config['encryption'] ?? '')==='ssl'?'selected':'' ?>>
                            SSL/TLS
                        </option>
                        <option value="none"
                            <?= ($config['encryption'] ?? '')==='none'?'selected':'' ?>>
                            なし
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>SMTPユーザー名</label>
                    <input type="text" name="username"
                           value="<?= h($config['username'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>SMTPパスワード</label>
                    <input type="password" name="password"
                           value="<?= h($config['password'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>送信元メールアドレス</label>
                    <input type="email" name="from_email"
                           value="<?= h($config['from_email'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>送信元名</label>
                    <input type="text" name="from_name"
                           value="<?= h($config['from_name'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>返信先メールアドレス</label>
                    <input type="email" name="reply_to"
                           value="<?= h($config['reply_to'] ?? '') ?>">
                </div>
            </div>

            <button class="btn" type="submit">
                設定保存
            </button>
        </form>
    </div>

    <?php
    pageEnd();
}

/* =========================================================
 * POST処理
 * ========================================================= */

function handlePost(): void
{
    $action = post('action');

    if ($action === '') {
        return;
    }

    if ($action === 'delete') {
        $surveyId = post('id');

        if ($surveyId === '') {
            flash('アンケートIDがありません。', 'error');
            redirect('?screen=list');
        }

        $surveys = loadSurveys();
        $index = findSurveyIndex($surveys, $surveyId);

        if ($index < 0) {
            flash('対象アンケートを特定できません。', 'error');
            redirect('?screen=list');
        }

        array_splice($surveys, $index, 1);
        saveSurveys($surveys);

        flash('アンケートを削除しました。');
        redirect('?screen=list');
    }

    if ($action === 'duplicate') {
        $surveyId = post('id');
        $surveys = loadSurveys();

        $index = findSurveyIndex($surveys, $surveyId);

        if ($index < 0) {
            flash('対象アンケートを特定できません。', 'error');
            redirect('?screen=list');
        }

        $copy = $surveys[$index];

        $copy['id'] = id();
        $copy['title'] .= '（コピー）';
        $copy['status'] = 'draft';
        $copy['created_at'] = now();
        $copy['updated_at'] = now();

        $copy = normalizeSurvey($copy);

        $surveys[] = $copy;

        saveSurveys($surveys);

        flash('アンケートを複製しました。');
        redirect('?screen=list');
    }

    if ($action === 'save_survey') {
        $surveyId = post('id');
        $surveys = loadSurveys();

        if ($surveyId === '') {
            $survey = defaultSurvey();
            $surveyId = $survey['id'];
        } else {
            $survey = findSurvey($surveyId);

            if ($survey === null) {
                flash('対象アンケートを特定できません。', 'error');
                redirect('?screen=list');
            }
        }

        $title = post('title');

        if ($title === '') {
            flash('タイトルは必須です。', 'error');
            redirect(
                '?screen=edit&id=' .
                rawurlencode($surveyId)
            );
        }

        $survey['title'] = $title;
        $survey['description'] = post('description');
        $survey['start_at'] = post('start_at');
        $survey['end_at'] = post('end_at');

        $numbering = post('numbering', 'global');

        if (!in_array($numbering, ['global', 'group'], true)) {
            $numbering = 'global';
        }

        $survey['numbering'] = $numbering;

        /*
         * 新規作成は必ず下書き。
         * 既存編集では現在状態を維持。
         */
        if ($surveyId === '') {
            $survey['status'] = 'draft';
        }

        $survey['updated_at'] = now();

        $rawGroups = $_POST['groups'] ?? [];

        if (is_array($rawGroups)) {
            $groups = [];

            foreach ($rawGroups as $rawGroup) {
                if (!is_array($rawGroup)) {
                    continue;
                }

                $group = [
                    'id' =>
                        isset($rawGroup['id']) &&
                        is_string($rawGroup['id']) &&
                        $rawGroup['id'] !== ''
                            ? $rawGroup['id']
                            : uid('group'),

                    'title' => (string)($rawGroup['title'] ?? ''),
                    'questions' => [],
                ];

                $rawQuestions = $rawGroup['questions'] ?? [];

                if (is_array($rawQuestions)) {
                    foreach ($rawQuestions as $rawQuestion) {
                        if (!is_array($rawQuestion)) {
                            continue;
                        }

                        $question = [
                            'id' =>
                                isset($rawQuestion['id']) &&
                                is_string($rawQuestion['id']) &&
                                $rawQuestion['id'] !== ''
                                    ? $rawQuestion['id']
                                    : uid('question'),

                            'text' => (string)($rawQuestion['text'] ?? ''),
                            'type' => in_array(
                                $rawQuestion['type'] ?? '',
                                ['single', 'multiple', 'text'],
                                true
                            )
                                ? $rawQuestion['type']
                                : 'single',

                            'required' =>
                                isset($rawQuestion['required']),

                            'options' => [],
                            'branches' => [],
                        ];

                        $rawOptions = $rawQuestion['options'] ?? [];

                        if (is_array($rawOptions)) {
                            foreach ($rawOptions as $rawOption) {
                                if (!is_array($rawOption)) {
                                    continue;
                                }

                                $question['options'][] = [
                                    'id' =>
                                        isset($rawOption['id']) &&
                                        is_string($rawOption['id']) &&
                                        $rawOption['id'] !== ''
                                            ? $rawOption['id']
                                            : uid('option'),

                                    'label' =>
                                        (string)($rawOption['label'] ?? ''),
                                ];
                            }
                        }

                        $group['questions'][] = $question;
                    }
                }

                $groups[] = $group;
            }

            $survey['groups'] = $groups;
        }

        $survey = normalizeSurvey($survey);

        $index = findSurveyIndex($surveys, $surveyId);

        if ($index >= 0) {
            $surveys[$index] = $survey;
        } else {
            $surveys[] = $survey;
        }

        saveSurveys($surveys);

        flash('アンケートを保存しました。');
        redirect('?screen=list');
    }

    if ($action === 'add_group' ||
        isset($_POST['add_question']) ||
        isset($_POST['add_option']) ||
        isset($_POST['delete_question']) ||
        isset($_POST['delete_option'])) {

        /*
         * 編集画面の構造操作。
         * 現在値を読み込み、操作してから保存。
         */
        $surveyId = post('id');

        if ($surveyId === '') {
            /*
             * save_survey と同じPOSTでない構造操作の場合は
             * 現在フォームから生成する。
             */
            $survey = defaultSurvey();
        } else {
            $survey = findSurvey($surveyId);

            if ($survey === null) {
                flash('対象アンケートを特定できません。', 'error');
                redirect('?screen=list');
            }
        }

        $rawGroups = $_POST['groups'] ?? [];

        if (is_array($rawGroups)) {
            $survey['groups'] = [];

            foreach ($rawGroups as $rawGroup) {
                if (!is_array($rawGroup)) {
                    continue;
                }

                $group = [
                    'id' => (string)($rawGroup['id'] ?? uid('group')),
                    'title' => (string)($rawGroup['title'] ?? ''),
                    'questions' => [],
                ];

                foreach (($rawGroup['questions'] ?? []) as $rawQuestion) {
                    if (!is_array($rawQuestion)) {
                        continue;
                    }

                    $question = [
                        'id' => (string)($rawQuestion['id'] ?? uid('question')),
                        'text' => (string)($rawQuestion['text'] ?? ''),
                        'type' => in_array(
                            $rawQuestion['type'] ?? '',
                            ['single','multiple','text'],
                            true
                        )
                            ? $rawQuestion['type']
                            : 'single',
                        'required' => isset($rawQuestion['required']),
                        'options' => [],
                        'branches' => [],
                    ];

                    foreach (($rawQuestion['options'] ?? []) as $rawOption) {
                        if (!is_array($rawOption)) {
                            continue;
                        }

                        $question['options'][] = [
                            'id' => (string)($rawOption['id'] ?? uid('option')),
                            'label' => (string)($rawOption['label'] ?? ''),
                        ];
                    }

                    $group['questions'][] = $question;
                }

                $survey['groups'][] = $group;
            }
        }

        if ($action === 'add_group') {
            $survey['groups'][] = [
                'id' => uid('group'),
                'title' => '新しいグループ',
                'questions' => [],
            ];
        }

        if (isset($_POST['add_question'])) {
            $targetGroup = (string)$_POST['add_question'];

            foreach ($survey['groups'] as &$group) {
                if ($group['id'] !== $targetGroup) {
                    continue;
                }

                $group['questions'][] = [
                    'id' => uid('question'),
                    'text' => '',
                    'type' => 'single',
                    'required' => false,
                    'options' => [
                        [
                            'id' => uid('option'),
                            'label' => '選択肢1',
                        ],
                        [
                            'id' => uid('option'),
                            'label' => '選択肢2',
                        ],
                    ],
                    'branches' => [],
                ];

                break;
            }

            unset($group);
        }

        if (isset($_POST['add_option'])) {
            [$groupId, $questionId] =
                array_pad(
                    explode('|', (string)$_POST['add_option'], 2),
                    2,
                    ''
                );

            foreach ($survey['groups'] as &$group) {
                if ($group['id'] !== $groupId) {
                    continue;
                }

                foreach ($group['questions'] as &$question) {
                    if ($question['id'] !== $questionId) {
                        continue;
                    }

                    $question['options'][] = [
                        'id' => uid('option'),
                        'label' => '',
                    ];

                    break;
                }

                unset($question);
                break;
            }

            unset($group);
        }

        if (isset($_POST['delete_question'])) {
            [$groupId, $questionId] =
                array_pad(
                    explode('|', (string)$_POST['delete_question'], 2),
                    2,
                    ''
                );

            foreach ($survey['groups'] as &$group) {
                if ($group['id'] !== $groupId) {
                    continue;
                }

                $group['questions'] = array_values(
                    array_filter(
                        $group['questions'],
                        fn($question) =>
                            ($question['id'] ?? '') !== $questionId
                    )
                );

                break;
            }

            unset($group);
        }

        if (isset($_POST['delete_option'])) {
            [$groupId, $questionId, $optionId] =
                array_pad(
                    explode('|', (string)$_POST['delete_option'], 3),
                    3,
                    ''
                );

            foreach ($survey['groups'] as &$group) {
                if ($group['id'] !== $groupId) {
                    continue;
                }

                foreach ($group['questions'] as &$question) {
                    if ($question['id'] !== $questionId) {
                        continue;
                    }

                    $question['options'] = array_values(
                        array_filter(
                            $question['options'],
                            fn($option) =>
                                ($option['id'] ?? '') !== $optionId
                        )
                    );

                    break;
                }

                unset($question);
                break;
            }

            unset($group);
        }

        $survey['updated_at'] = now();
        $survey = normalizeSurvey($survey);

        $surveys = loadSurveys();
        $index = findSurveyIndex($surveys, $survey['id']);

        if ($index >= 0) {
            $surveys[$index] = $survey;
        } else {
            $surveys[] = $survey;
        }

        saveSurveys($surveys);

        redirect(
            '?screen=edit&id=' .
            rawurlencode($survey['id'])
        );
    }
}

/* =========================================================
 * 状態変更
 * ========================================================= */

function handleStatusAction(): void
{
    $action = post('status_action');

    if ($action === '') {
        return;
    }

    $surveyId = post('id');
    $surveys = loadSurveys();
    $index = findSurveyIndex($surveys, $surveyId);

    if ($index < 0) {
        flash('対象アンケートを特定できません。', 'error');
        redirect('?screen=list');
    }

    $survey = $surveys[$index];
    $current = effectiveStatus($survey);

    if ($current === 'ended') {
        flash('終了したアンケートは変更できません。', 'error');
        redirect('?screen=edit&id=' . rawurlencode($surveyId));
    }

    $newStatus = match ($action) {
        'publish' => 'published',
        'stop' => 'stopped',
        'resume' => 'published',
        default => null,
    };

    if ($newStatus === null) {
        return;
    }

    $survey['status'] = $newStatus;
    $survey['updated_at'] = now();

    $surveys[$index] = normalizeSurvey($survey);
    saveSurveys($surveys);

    flash('状態を変更しました。');

    redirect('?screen=edit&id=' . rawurlencode($surveyId));
}

/* =========================================================
 * ルーティング
 * ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['status_action'])) {
        handleStatusAction();
    }

    handlePost();
}

$screen = query('screen', 'list');

switch ($screen) {
    case 'edit':
        screenEdit();
        break;

    case 'preview':
        screenPreview();
        break;

    case 'answer':
        screenAnswer();
        break;

    case 'confirm':
        screenConfirm();
        break;

    case 'complete':
        screenComplete();
        break;

    case 'analytics':
        screenAnalytics();
        break;

    case 'send':
        screenSend();
        break;

    case 'kintone':
        screenKintone();
        break;

    case 'mail':
        screenMail();
        break;

    case 'list':
    default:
        screenList();
        break;
}
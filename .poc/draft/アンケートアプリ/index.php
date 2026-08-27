<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * 実行環境:
 *   Apache 2.4
 *   PHP 8.5
 *   DBなし
 *   PHP cURLなし
 *
 * 方針:
 *   - 管理者ログインなし
 *   - PHPセッションはCSRF等に使用
 *   - CSRFトークンはセッションに保持し、POST時に検証
 *   - 認証を理由とするリダイレクトなし
 *   - kintoneは実接続
 *   - kintone認証はX-Cybozu-Authorization
 *   - Proxyはhost:portの1欄
 *   - SMTPは実接続
 *   - DBなし
 *
 * 保存先:
 *   Web公開ディレクトリの1階層上に
 *   アプリ用データディレクトリを作成する。
 *
 * 例:
 *   /var/www/
 *       data/
 *       html/
 *           index.php
 */

// ============================================================
// 基本設定
// ============================================================

date_default_timezone_set('Asia/Tokyo');

const APP_NAME = 'アンケートアプリ';
const DATA_DIR_NAME = 'アンケートアプリ-data';

const DATA_FILE_SURVEYS   = 'surveys.json';
const DATA_FILE_CUSTOMERS  = 'customers.json';
const DATA_FILE_SETTINGS   = 'settings.json';
const DATA_FILE_RESPONSES  = 'responses.json';
const DATA_FILE_MAIL_LOG   = 'mail_logs.json';

const SESSION_CSRF_KEY = '_survey_csrf_token';
const SESSION_ANSWER_KEY = '_survey_answer';

const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_READ_TIMEOUT    = 30;

const SMTP_CONNECT_TIMEOUT = 10;
const SMTP_READ_TIMEOUT    = 30;


// ============================================================
// セッション
// ============================================================

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    ),
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();


// ============================================================
// 共通関数
// ============================================================

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nowIso(): string
{
    return date('c');
}

function redirect(string $url): never
{
    /*
     * 外部URLへリダイレクトしない。
     * このアプリでは内部URLのみを使用する。
     */
    header('Location: ' . $url, true, 303);
    exit;
}

function currentUrl(string $screen = 'list', array $params = []): string
{
    $query = array_merge(['screen' => $screen], $params);
    return 'index.php?' . http_build_query($query);
}

function post(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $default;
}

function get(string $key, mixed $default = null): mixed
{
    return $_GET[$key] ?? $default;
}

function isPost(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function fail(string $message, int $status = 400): never
{
    http_response_code($status);

    echo '<!doctype html>';
    echo '<html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h(APP_NAME) . '</title>';
    echo '<style>
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",Meiryo,sans-serif;
        background:#f8fafc;color:#1e293b;padding:40px}
        .box{max-width:700px;margin:auto;background:#fff;border:1px solid #dbe2ea;
        border-radius:12px;padding:28px;box-shadow:0 4px 18px rgba(15,23,42,.08)}
        .error{color:#b91c1c;background:#fef2f2;padding:14px;border-radius:8px}
        a{color:#2563eb}
    </style></head><body>';
    echo '<div class="box">';
    echo '<h1>処理できません</h1>';
    echo '<div class="error">' . h($message) . '</div>';
    echo '<p><a href="' . h(currentUrl()) . '">アンケート一覧へ戻る</a></p>';
    echo '</div></body></html>';
    exit;
}

function dataDir(): string
{
    /*
     * Web公開ディレクトリ外に保存する。
     *
     * 例:
     * /var/www/html/index.php
     * /var/www/アンケートアプリ-data/
     */
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . DATA_DIR_NAME;
}

function ensureDataDir(): void
{
    $dir = dataDir();

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            fail('データ保存ディレクトリを作成できません。');
        }
    }

    @chmod($dir, 0700);
}

function dataPath(string $file): string
{
    ensureDataDir();
    return dataDir() . DIRECTORY_SEPARATOR . $file;
}

function readJson(string $file, mixed $default): mixed
{
    $path = dataPath($file);

    if (!file_exists($path)) {
        return $default;
    }

    $fp = fopen($path, 'rb');

    if ($fp === false) {
        fail('データファイルを読み込めません。');
    }

    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        fail('データファイルをロックできません。');
    }

    $contents = stream_get_contents($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    if ($contents === false || trim($contents) === '') {
        return $default;
    }

    $decoded = json_decode($contents, true);

    if (!is_array($decoded)) {
        fail('保存データの形式が不正です。');
    }

    return $decoded;
}

function writeJson(string $file, array $data): void
{
    $path = dataPath($file);
    $tmp  = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        fail('データをJSONへ変換できません。');
    }

    $fp = fopen($tmp, 'wb');

    if ($fp === false) {
        fail('一時ファイルを作成できません。');
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        @unlink($tmp);
        fail('データファイルをロックできません。');
    }

    $written = fwrite($fp, $json);

    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($written === false) {
        @unlink($tmp);
        fail('データを書き込めません。');
    }

    @chmod($tmp, 0600);

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        fail('データファイルを更新できません。');
    }

    @chmod($path, 0600);
}

function randomId(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}


// ============================================================
// CSRF
// ============================================================

function csrfToken(): string
{
    /*
     * 重要:
     * GETアクセスごとに再生成しない。
     * セッションに存在しない場合だけ生成する。
     */
    if (
        empty($_SESSION[SESSION_CSRF_KEY]) ||
        !is_string($_SESSION[SESSION_CSRF_KEY])
    ) {
        $_SESSION[SESSION_CSRF_KEY] = bin2hex(random_bytes(32));
    }

    return $_SESSION[SESSION_CSRF_KEY];
}

function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' .
        h(csrfToken()) .
        '">';
}

function verifyCsrf(): void
{
    if (!isPost()) {
        return;
    }

    $sessionToken = $_SESSION[SESSION_CSRF_KEY] ?? '';
    $postToken    = $_POST['_csrf'] ?? '';

    if (
        !is_string($sessionToken) ||
        !is_string($postToken) ||
        $sessionToken === '' ||
        $postToken === '' ||
        !hash_equals($sessionToken, $postToken)
    ) {
        fail(
            'CSRFトークンが不正です。ページを再読み込みして再実行してください。',
            403
        );
    }
}


// ============================================================
// データ初期化
// ============================================================

function surveys(): array
{
    return readJson(DATA_FILE_SURVEYS, []);
}

function customers(): array
{
    return readJson(DATA_FILE_CUSTOMERS, []);
}

function responses(): array
{
    return readJson(DATA_FILE_RESPONSES, []);
}

function mailLogs(): array
{
    return readJson(DATA_FILE_MAIL_LOG, []);
}

function settings(): array
{
    return readJson(DATA_FILE_SETTINGS, [
        'kintone' => [
            'subdomain' => '',
            'app_id' => '',
            'login_name' => '',
            'password' => '',
            'proxy' => '',
            'verify_ssl' => true,
        ],
        'smtp' => [
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '',
            'reply_to' => '',
        ],
    ]);
}


// ============================================================
// アンケート状態
// ============================================================

function normalizeSurveyStatus(array &$survey): void
{
    if (
        ($survey['status'] ?? '') === 'published' &&
        !empty($survey['end_at'])
    ) {
        $end = strtotime((string)$survey['end_at']);

        if ($end !== false && $end < time()) {
            $survey['status'] = 'ended';
        }
    }
}

function normalizeAllSurveys(array &$items): bool
{
    $changed = false;

    foreach ($items as &$survey) {
        $before = $survey['status'] ?? '';
        normalizeSurveyStatus($survey);

        if ($before !== ($survey['status'] ?? '')) {
            $changed = true;
        }
    }

    unset($survey);

    return $changed;
}

function statusLabel(string $status): string
{
    return match ($status) {
        'draft'     => '下書き',
        'published' => '公開中',
        'stopped'   => '停止',
        'ended'     => '終了',
        default     => '不明',
    };
}

function statusClass(string $status): string
{
    return match ($status) {
        'draft'     => 'badge-draft',
        'published' => 'badge-published',
        'stopped'   => 'badge-stopped',
        'ended'     => 'badge-ended',
        default     => 'badge-draft',
    };
}

function statusCanChange(string $status): bool
{
    return in_array($status, ['draft', 'published', 'stopped'], true);
}

function validStatusTransition(string $from, string $to): bool
{
    return match ($from) {
        'draft'     => $to === 'published',
        'published' => $to === 'stopped',
        'stopped'   => $to === 'published',
        default     => false,
    };
}


// ============================================================
// 質問・グループ
// ============================================================

function defaultQuestion(): array
{
    return [
        'id' => randomId('question'),
        'number' => '',
        'text' => '',
        'type' => 'single',
        'required' => false,
        'options' => [
            ['id' => randomId('option'), 'label' => '選択肢1'],
            ['id' => randomId('option'), 'label' => '選択肢2'],
        ],
        'branching' => [],
    ];
}

function defaultGroup(): array
{
    return [
        'id' => randomId('group'),
        'title' => '新しいグループ',
        'questions' => [
            defaultQuestion(),
        ],
    ];
}

function recalculateQuestionNumbers(array &$survey): void
{
    $mode = $survey['numbering_mode'] ?? 'global';

    if ($mode === 'group') {
        foreach ($survey['groups'] as $gi => &$group) {
            foreach ($group['questions'] as $qi => &$question) {
                $question['number'] = 'Q' . ($gi + 1) . '-' . ($qi + 1);
            }
            unset($question);
        }
        unset($group);
        return;
    }

    $number = 1;

    foreach ($survey['groups'] as &$group) {
        foreach ($group['questions'] as &$question) {
            $question['number'] = 'Q' . $number++;
        }
        unset($question);
    }
    unset($group);
}

function allQuestions(array $survey): array
{
    $result = [];

    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            $result[] = $question;
        }
    }

    return $result;
}

function questionById(array $survey, string $questionId): ?array
{
    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            if (($question['id'] ?? '') === $questionId) {
                return $question;
            }
        }
    }

    return null;
}


// ============================================================
// 入力検証
// ============================================================

function requiredString(mixed $value, string $label, int $max = 1000): string
{
    $value = trim((string)$value);

    if ($value === '') {
        throw new RuntimeException($label . 'は必須です。');
    }

    if (mb_strlen($value) > $max) {
        throw new RuntimeException($label . 'が長すぎます。');
    }

    return $value;
}

function validateProxy(string $proxy): string
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return '';
    }

    if (preg_match(
        '/^(?:(?:[a-zA-Z0-9-]+\.)*[a-zA-Z0-9-]+|\d{1,3}(?:\.\d{1,3}){3}):([1-9]\d{0,4})$/',
        $proxy,
        $matches
    ) !== 1) {
        throw new RuntimeException(
            'Proxyはhost:port形式で入力してください。'
        );
    }

    $port = (int)$matches[1];

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('Proxyのポート番号が不正です。');
    }

    return $proxy;
}

function validateEmail(string $email, string $label): string
{
    $email = trim($email);

    if ($email === '') {
        throw new RuntimeException($label . 'は必須です。');
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException($label . 'の形式が不正です。');
    }

    return $email;
}

function validateSurveyPayload(): array
{
    $title = requiredString(post('title'), 'アンケートタイトル', 200);
    $description = trim((string)post('description', ''));

    if (mb_strlen($description) > 5000) {
        throw new RuntimeException('アンケート説明が長すぎます。');
    }

    $startAt = trim((string)post('start_at', ''));
    $endAt   = trim((string)post('end_at', ''));

    if ($startAt !== '' && strtotime($startAt) === false) {
        throw new RuntimeException('開始日時が不正です。');
    }

    if ($endAt !== '' && strtotime($endAt) === false) {
        throw new RuntimeException('終了日時が不正です。');
    }

    if (
        $startAt !== '' &&
        $endAt !== '' &&
        strtotime($startAt) > strtotime($endAt)
    ) {
        throw new RuntimeException('終了日時は開始日時より後にしてください。');
    }

    $numberingMode = post('numbering_mode', 'global');

    if (!in_array($numberingMode, ['global', 'group'], true)) {
        throw new RuntimeException('採番方式が不正です。');
    }

    $groups = json_decode(
        (string)post('groups_json', '[]'),
        true
    );

    if (!is_array($groups)) {
        throw new RuntimeException('質問データが不正です。');
    }

    $normalizedGroups = [];

    foreach ($groups as $group) {
        $groupId = (string)($group['id'] ?? randomId('group'));
        $groupTitle = trim((string)($group['title'] ?? ''));

        if ($groupTitle === '') {
            $groupTitle = 'グループ';
        }

        if (mb_strlen($groupTitle) > 200) {
            throw new RuntimeException('グループ名が長すぎます。');
        }

        $questions = [];

        foreach (($group['questions'] ?? []) as $question) {
            $questionId = (string)($question['id'] ?? randomId('question'));
            $text = trim((string)($question['text'] ?? ''));

            if ($text === '') {
                throw new RuntimeException('質問文は必須です。');
            }

            if (mb_strlen($text) > 2000) {
                throw new RuntimeException('質問文が長すぎます。');
            }

            $type = (string)($question['type'] ?? 'single');

            if (!in_array($type, ['single', 'multiple', 'text'], true)) {
                throw new RuntimeException('回答形式が不正です。');
            }

            $options = [];

            if (in_array($type, ['single', 'multiple'], true)) {
                foreach (($question['options'] ?? []) as $option) {
                    $label = trim((string)($option['label'] ?? ''));

                    if ($label === '') {
                        continue;
                    }

                    if (mb_strlen($label) > 500) {
                        throw new RuntimeException('選択肢が長すぎます。');
                    }

                    $options[] = [
                        'id' => (string)($option['id'] ?? randomId('option')),
                        'label' => $label,
                    ];
                }

                if (count($options) < 1) {
                    throw new RuntimeException(
                        '選択式質問には少なくとも1つの選択肢が必要です。'
                    );
                }
            }

            $branching = [];

            if ($type === 'single') {
                foreach (($question['branching'] ?? []) as $optionId => $targetId) {
                    $branching[(string)$optionId] =
                        (string)$targetId;
                }
            }

            $questions[] = [
                'id' => $questionId,
                'number' => '',
                'text' => $text,
                'type' => $type,
                'required' => !empty($question['required']),
                'options' => $options,
                'branching' => $branching,
            ];
        }

        $normalizedGroups[] = [
            'id' => $groupId,
            'title' => $groupTitle,
            'questions' => $questions,
        ];
    }

    if (count($normalizedGroups) < 1) {
        throw new RuntimeException('グループを1つ以上作成してください。');
    }

    $survey = [
        'title' => $title,
        'description' => $description,
        'start_at' => $startAt,
        'end_at' => $endAt,
        'numbering_mode' => $numberingMode,
        'groups' => $normalizedGroups,
    ];

    recalculateQuestionNumbers($survey);

    return $survey;
}


// ============================================================
// kintone
// ============================================================

function base64Auth(string $loginName, string $password): string
{
    return base64_encode($loginName . ':' . $password);
}

function parseProxy(string $proxy): ?array
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (
        preg_match(
            '/^(.*):([1-9]\d{0,4})$/',
            $proxy,
            $m
        ) !== 1
    ) {
        throw new RuntimeException('Proxyはhost:port形式で指定してください。');
    }

    return [
        'host' => $m[1],
        'port' => (int)$m[2],
    ];
}

function kintoneRequest(
    string $method,
    string $path,
    array $settings,
    ?array $body = null
): array {
    $k = $settings['kintone'] ?? [];

    $subdomain = trim((string)($k['subdomain'] ?? ''));
    $appId     = trim((string)($k['app_id'] ?? ''));
    $loginName = (string)($k['login_name'] ?? '');
    $password  = (string)($k['password'] ?? '');

    if ($subdomain === '') {
        throw new RuntimeException('kintoneサブドメインが未設定です。');
    }

    if ($appId === '' || !ctype_digit($appId)) {
        throw new RuntimeException('kintoneアプリIDが不正です。');
    }

    if ($loginName === '' || $password === '') {
        throw new RuntimeException('kintoneログイン情報が未設定です。');
    }

    $host = $subdomain . '.cybozu.com';
    $url = 'https://' . $host . $path;

    $headers = [
        'X-Cybozu-Authorization: ' .
            base64Auth($loginName, $password),
        'Accept: application/json',
    ];

    $content = '';

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            throw new RuntimeException('kintoneリクエストを生成できません。');
        }

        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($content);
    }

    $proxy = parseProxy((string)($k['proxy'] ?? ''));
    $verifySsl = !empty($k['verify_ssl']);

    $contextOptions = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'timeout' => KINTONE_READ_TIMEOUT,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => $verifySsl,
            'verify_peer_name' => $verifySsl,
            'allow_self_signed' => !$verifySsl,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ],
    ];

    if ($proxy !== null) {
        /*
         * PHP stream wrapperのHTTPS Proxy指定。
         * CONNECT方式を明示的に行う必要がある環境では
         * stream wrapperだけでは対応できないため、
         * このアプリではProxyサーバへHTTPSリクエストを
         * HTTPS形式で送る方式を使用する。
         *
         * 一般的なHTTP CONNECT Proxyの場合は
         * Proxy側の仕様に応じてサーバー環境へ合わせる。
         */
        $contextOptions['http']['proxy'] =
            'tcp://' . $proxy['host'] . ':' . $proxy['port'];
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $start = microtime(true);

    $fp = @fopen($url, 'rb', false, $context);

    if ($fp === false) {
        throw new RuntimeException(
            'kintoneへ接続できませんでした。'
        );
    }

    $response = stream_get_contents($fp);

    $meta = stream_get_meta_data($fp);

    fclose($fp);

    if ($response === false) {
        throw new RuntimeException(
            'kintoneからレスポンスを取得できませんでした。'
        );
    }

    $status = 0;

    foreach (($meta['wrapper_data'] ?? []) as $header) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})/',
                (string)$header,
                $m
            )
        ) {
            $status = (int)$m[1];
        }
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        $decoded = [
            'raw' => $response,
        ];
    }

    if ($status < 200 || $status >= 300) {
        $message = $decoded['message'] ??
            ('kintone APIエラー HTTP ' . $status);

        throw new RuntimeException((string)$message);
    }

    return $decoded;
}

function kintoneGetRecords(array $settings): array
{
    $k = $settings['kintone'] ?? [];
    $appId = (int)$k['app_id'];

    $query = urlencode('');
    $fields = [
        '組織名',
        '氏名',
        'メールアドレス',
        '部署名',
        '電話番号',
        '住所',
    ];

    $params = [
        'app' => $appId,
        'totalCount' => 'true',
        'query' => '',
        'fields' => $fields,
    ];

    $queryParts = [];

    foreach ($params as $key => $value) {
        if ($key === 'fields' && is_array($value)) {
            foreach ($value as $field) {
                $queryParts[] =
                    rawurlencode($key) . '[]=' .
                    rawurlencode($field);
            }
        } else {
            $queryParts[] =
                rawurlencode($key) . '=' .
                rawurlencode((string)$value);
        }
    }

    $result = kintoneRequest(
        'GET',
        '/k/v1/records.json?' . implode('&', $queryParts),
        $settings
    );

    return $result['records'] ?? [];
}

function kintoneRecordValue(array $record, string $field): string
{
    $value = $record[$field]['value'] ?? '';

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $parts[] = (string)($item['name'] ?? '');
            } else {
                $parts[] = (string)$item;
            }
        }

        return implode(', ', array_filter($parts));
    }

    return (string)$value;
}

function mapKintoneCustomers(array $records): array
{
    $result = [];

    foreach ($records as $record) {
        $result[] = [
            'id' => randomId('customer'),
            'kintone_id' => (string)($record['$id']['value'] ?? ''),
            'organization' => kintoneRecordValue($record, '組織名'),
            'name' => kintoneRecordValue($record, '氏名'),
            'email' => kintoneRecordValue($record, 'メールアドレス'),
            'department' => kintoneRecordValue($record, '部署名'),
            'phone' => kintoneRecordValue($record, '電話番号'),
            'address' => kintoneRecordValue($record, '住所'),
            'updated_at' => nowIso(),
        ];
    }

    return $result;
}


// ============================================================
// SMTP
// ============================================================

function smtpRead($fp, int $timeout): string
{
    stream_set_timeout($fp, $timeout);

    $response = '';

    while (($line = fgets($fp, 8192)) !== false) {
        $response .= $line;

        if (
            strlen($line) >= 4 &&
            $line[3] === ' '
        ) {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException('SMTPサーバから応答がありません。');
    }

    return $response;
}

function smtpExpect($fp, array $codes): string
{
    $response = smtpRead($fp, SMTP_READ_TIMEOUT);

    $code = (int)substr(trim($response), 0, 3);

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' . trim($response)
        );
    }

    return $response;
}

function smtpCommand(
    $fp,
    string $command,
    array $codes
): string {
    fwrite($fp, $command . "\r\n");
    return smtpExpect($fp, $codes);
}

function smtpOpen(array $smtp)
{
    $host = trim((string)($smtp['host'] ?? ''));
    $port = (int)($smtp['port'] ?? 587);
    $encryption = strtolower((string)($smtp['encryption'] ?? 'tls'));

    if ($host === '') {
        throw new RuntimeException('SMTPサーバが未設定です。');
    }

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('SMTPポートが不正です。');
    }

    $scheme = 'tcp://';

    if ($encryption === 'ssl') {
        $scheme = 'ssl://';
    }

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $scheme . $host . ':' . $port,
        $errno,
        $errstr,
        SMTP_CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($fp === false) {
        throw new RuntimeException(
            'SMTPへ接続できません: ' . $errstr
        );
    }

    stream_set_timeout($fp, SMTP_READ_TIMEOUT);

    smtpExpect($fp, [220]);

    smtpCommand(
        $fp,
        'EHLO localhost',
        [250]
    );

    if ($encryption === 'tls') {
        smtpCommand($fp, 'STARTTLS', [220]);

        $crypto = stream_socket_enable_crypto(
            $fp,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($fp);
            throw new RuntimeException(
                'SMTP TLS接続を確立できません。'
            );
        }

        smtpCommand($fp, 'EHLO localhost', [250]);
    }

    $auth = !empty($smtp['auth']);

    if ($auth) {
        $username = (string)($smtp['username'] ?? '');
        $password = (string)($smtp['password'] ?? '');

        if ($username === '' || $password === '') {
            fclose($fp);
            throw new RuntimeException(
                'SMTP認証情報が未設定です。'
            );
        }

        smtpCommand($fp, 'AUTH LOGIN', [334]);
        smtpCommand($fp, base64_encode($username), [334]);
        smtpCommand($fp, base64_encode($password), [235]);
    }

    return $fp;
}

function smtpSend(
    array $smtp,
    string $to,
    string $subject,
    string $body
): void {
    $from = trim((string)($smtp['from_email'] ?? ''));
    $fromName = trim((string)($smtp['from_name'] ?? ''));
    $replyTo = trim((string)($smtp['reply_to'] ?? ''));

    if (
        $from === '' ||
        filter_var($from, FILTER_VALIDATE_EMAIL) === false
    ) {
        throw new RuntimeException(
            '送信元メールアドレスが不正です。'
        );
    }

    $fp = smtpOpen($smtp);

    try {
        smtpCommand($fp, 'MAIL FROM:<' . $from . '>', [250]);
        smtpCommand($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtpCommand($fp, 'DATA', [354]);

        $encodedSubject = '=?UTF-8?B?' .
            base64_encode($subject) .
            '?=';

        $encodedFromName = $fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?='
            : $from;

        $headers = [
            'From: ' . $encodedFromName . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if ($replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $mail = implode("\r\n", $headers) .
            "\r\n\r\n" .
            normalizeMailBody($body);

        /*
         * SMTP dot-stuffing
         */
        $mail = preg_replace(
            '/^\./m',
            '..',
            $mail
        );

        fwrite($fp, $mail . "\r\n.\r\n");

        smtpExpect($fp, [250]);

        smtpCommand($fp, 'QUIT', [221]);
    } finally {
        fclose($fp);
    }
}

function normalizeMailBody(string $body): string
{
    return str_replace(
        ["\r\n", "\r"],
        "\n",
        $body
    );
}


// ============================================================
// CSV
// ============================================================

function outputCsv(array $rows, string $filename): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' .
        rawurlencode($filename) . '"'
    );

    /*
     * Excel等でUTF-8 CSVとして扱いやすくする。
     */
    echo "\xEF\xBB\xBF";

    $fp = fopen('php://output', 'wb');

    foreach ($rows as $row) {
        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}


// ============================================================
// 簡易PDF
// ============================================================

function pdfEscape(string $text): string
{
    return str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $text
    );
}

function makeSimplePdf(string $title, array $lines): string
{
    /*
     * 外部PDFライブラリなしで生成する最小PDF。
     *
     * 日本語フォント埋め込みは行わないため、
     * ASCII中心の出力を想定する。
     *
     * 日本語PDFが必要な本番環境では、
     * TCPDF / mPDF等の導入を推奨する。
     */

    $content = "BT\n";
    $content .= "/F1 12 Tf\n";
    $content .= "50 780 Td\n";

    $content .= '(' .
        pdfEscape($title) .
        ") Tj\n";

    $content .= "0 -20 Td\n";

    foreach ($lines as $line) {
        $ascii = preg_replace(
            '/[^\x20-\x7E]/',
            '?',
            (string)$line
        );

        $content .= '(' .
            pdfEscape($ascii) .
            ") Tj\n";
        $content .= "0 -16 Td\n";
    }

    $content .= "ET\n";

    $objects = [];

    $objects[] =
        '<< /Type /Catalog /Pages 2 0 R >>';

    $objects[] =
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

    $objects[] =
        '<< /Type /Page /Parent 2 0 R ' .
        '/MediaBox [0 0 595 842] ' .
        '/Resources << /Font << /F1 5 0 R >> >> ' .
        '/Contents 4 0 R >>';

    $objects[] =
        '<< /Length ' . strlen($content) . " >>\n" .
        "stream\n" .
        $content .
        "endstream";

    $objects[] =
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $i => $object) {
        $number = $i + 1;

        $offsets[$number] = strlen($pdf);

        $pdf .= $number . " 0 obj\n";
        $pdf .= $object . "\n";
        $pdf .= "endobj\n";
    }

    $xref = strlen($pdf);

    $pdf .= "xref\n";
    $pdf .= "0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf(
            "%010d 00000 n \n",
            $offsets[$i]
        );
    }

    $pdf .= "trailer\n";
    $pdf .= "<< /Size " .
        (count($objects) + 1) .
        " /Root 1 0 R >>\n";
    $pdf .= "startxref\n";
    $pdf .= $xref . "\n";
    $pdf .= "%%EOF";

    return $pdf;
}


// ============================================================
// 共通ヘッダー
// ============================================================

function renderHeader(
    string $title,
    string $active = 'list'
): void {
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - <?= h(APP_NAME) ?></title>
<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --success:#16a34a;
    --warning:#d97706;
    --danger:#dc2626;
    --gray:#64748b;
    --gray-light:#f1f5f9;
    --border:#dbe2ea;
    --text:#1e293b;
    --white:#fff;
    --shadow:0 4px 18px rgba(15,23,42,.08);
}

*{box-sizing:border-box}

body{
    margin:0;
    background:#f8fafc;
    color:var(--text);
    font-family:-apple-system,BlinkMacSystemFont,
        "Segoe UI","Noto Sans JP",
        "Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
}

header{
    background:#0f172a;
    color:#fff;
    padding:16px 24px;
}

.header-inner{
    max-width:1400px;
    margin:auto;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
}

.brand{
    font-weight:700;
    font-size:20px;
}

.nav{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.nav a{
    color:#cbd5e1;
    text-decoration:none;
    padding:8px 12px;
    border-radius:7px;
    font-size:14px;
}

.nav a:hover,
.nav a.active{
    color:#fff;
    background:#1e293b;
}

main{
    max-width:1400px;
    margin:28px auto;
    padding:0 20px;
}

h1{
    font-size:26px;
    margin:0 0 20px;
}

h2{
    font-size:19px;
    margin:0 0 16px;
}

h3{
    font-size:16px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:20px;
    margin-bottom:18px;
}

.toolbar{
    display:flex;
    gap:10px;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
    margin-bottom:18px;
}

.toolbar-left,
.toolbar-right{
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
}

button,
.btn{
    border:0;
    border-radius:8px;
    padding:9px 14px;
    font-size:14px;
    cursor:pointer;
    text-decoration:none;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    background:#e2e8f0;
    color:#1e293b;
}

button:hover,
.btn:hover{
    opacity:.9;
}

.btn-primary{
    background:var(--primary);
    color:#fff;
}

.btn-primary:hover{
    background:var(--primary-dark);
}

.btn-danger{
    background:var(--danger);
    color:#fff;
}

.btn-success{
    background:var(--success);
    color:#fff;
}

.btn-warning{
    background:var(--warning);
    color:#fff;
}

.btn-small{
    padding:6px 9px;
    font-size:12px;
}

input,
textarea,
select{
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

label{
    display:block;
    font-weight:600;
    font-size:14px;
    margin-bottom:6px;
}

.form-group{
    margin-bottom:18px;
}

.form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:16px;
}

.help{
    color:var(--gray);
    font-size:12px;
    margin-top:5px;
}

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:900px;
}

th,
td{
    border-bottom:1px solid var(--border);
    padding:11px 10px;
    text-align:left;
    vertical-align:middle;
    font-size:14px;
}

th{
    background:#f8fafc;
    font-weight:700;
}

.badge{
    display:inline-block;
    padding:4px 8px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}

.badge-draft{
    color:#475569;
    background:#e2e8f0;
}

.badge-published{
    color:#166534;
    background:#dcfce7;
}

.badge-stopped{
    color:#92400e;
    background:#fef3c7;
}

.badge-ended{
    color:#991b1b;
    background:#fee2e2;
}

.alert{
    padding:12px 14px;
    border-radius:8px;
    margin-bottom:16px;
}

.alert-success{
    background:#ecfdf5;
    color:#166534;
    border:1px solid #bbf7d0;
}

.alert-error{
    background:#fef2f2;
    color:#991b1b;
    border:1px solid #fecaca;
}

.stats{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
}

.stat{
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
    background:#fff;
}

.stat-label{
    color:var(--gray);
    font-size:12px;
}

.stat-value{
    font-size:28px;
    font-weight:700;
    margin-top:4px;
}

.group{
    border:1px solid var(--border);
    border-radius:10px;
    margin-bottom:16px;
    background:#fff;
}

.group-header{
    padding:12px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
    display:flex;
    gap:10px;
    align-items:center;
}

.group-title{
    flex:1;
}

.group-body{
    padding:12px;
}

.question{
    border:1px solid #e2e8f0;
    border-radius:9px;
    padding:14px;
    margin-bottom:10px;
    background:#fff;
}

.question.dragging,
.group.dragging{
    opacity:.5;
}

.question-top{
    display:flex;
    align-items:center;
    gap:10px;
}

.drag{
    cursor:grab;
    color:var(--gray);
}

.question-no{
    font-weight:700;
    min-width:50px;
}

.question-body{
    margin-top:12px;
}

.option-row{
    display:flex;
    gap:8px;
    margin:6px 0;
}

.option-row input{
    flex:1;
}

.action-row{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:14px;
}

.sticky-actions{
    position:sticky;
    bottom:0;
    z-index:10;
    background:rgba(248,250,252,.95);
    border-top:1px solid var(--border);
    padding:12px 0;
}

.preview-question{
    padding:18px 0;
    border-bottom:1px solid var(--border);
}

.required{
    color:var(--danger);
}

.empty{
    color:var(--gray);
    text-align:center;
    padding:40px 20px;
}

.inline{
    display:flex;
    align-items:center;
    gap:8px;
}

.inline input[type="checkbox"]{
    width:auto;
}

.tabs{
    display:flex;
    gap:6px;
    margin-bottom:16px;
    flex-wrap:wrap;
}

.tab{
    border:1px solid var(--border);
    padding:8px 12px;
    border-radius:7px;
    text-decoration:none;
    color:var(--text);
    background:#fff;
}

.tab.active{
    background:var(--primary);
    color:#fff;
    border-color:var(--primary);
}

.search-form{
    display:grid;
    grid-template-columns:2fr 1fr 1fr 1fr auto;
    gap:8px;
}

@media(max-width:900px){
    .form-grid,
    .stats{
        grid-template-columns:1fr 1fr;
    }

    .search-form{
        grid-template-columns:1fr 1fr;
    }
}

@media(max-width:600px){
    header{
        padding:12px;
    }

    .header-inner{
        align-items:flex-start;
        flex-direction:column;
    }

    main{
        margin:18px auto;
        padding:0 12px;
    }

    .form-grid,
    .stats,
    .search-form{
        grid-template-columns:1fr;
    }

    h1{
        font-size:22px;
    }

    .card{
        padding:14px;
    }
}
</style>
</head>
<body>
<header>
<div class="header-inner">
<div class="brand"><?= h(APP_NAME) ?></div>

<nav class="nav">
<a class="<?= $active === 'list' ? 'active' : '' ?>"
   href="<?= h(currentUrl('list')) ?>">アンケート</a>
<a class="<?= $active === 'kintone' ? 'active' : '' ?>"
   href="<?= h(currentUrl('kintone')) ?>">kintone</a>
<a class="<?= $active === 'mail' ? 'active' : '' ?>"
   href="<?= h(currentUrl('mail')) ?>">メール</a>
</nav>
</div>
</header>
<?php
}

function renderFooter(): void
{
    ?>
</body>
</html>
<?php
}


// ============================================================
// アンケート一覧
// ============================================================

function screenList(): void
{
    $items = surveys();

    if (normalizeAllSurveys($items)) {
        writeJson(DATA_FILE_SURVEYS, $items);
    }

    $keyword = trim((string)get('q', ''));
    $status = (string)get('status', '');
    $sort = (string)get('sort', 'updated_desc');

    if ($keyword !== '') {
        $items = array_values(array_filter(
            $items,
            static function ($survey) use ($keyword) {
                return mb_stripos(
                    (string)($survey['title'] ?? ''),
                    $keyword
                ) !== false;
            }
        ));
    }

    if (
        in_array(
            $status,
            ['draft', 'published', 'stopped', 'ended'],
            true
        )
    ) {
        $items = array_values(array_filter(
            $items,
            static fn($survey) =>
                ($survey['status'] ?? '') === $status
        ));
    }

    usort($items, static function ($a, $b) use ($sort) {
        $av = match ($sort) {
            'updated_asc', 'updated_desc' =>
                strtotime((string)($a['updated_at'] ?? '')) ?: 0,
            'answers_asc', 'answers_desc' =>
                countResponses((string)($a['id'] ?? '')),
            'start_asc', 'start_desc' =>
                strtotime((string)($a['start_at'] ?? '')) ?: 0,
            default => 0,
        };

        $bv = match ($sort) {
            'updated_asc', 'updated_desc' =>
                strtotime((string)($b['updated_at'] ?? '')) ?: 0,
            'answers_asc', 'answers_desc' =>
                countResponses((string)($b['id'] ?? '')),
            'start_asc', 'start_desc' =>
                strtotime((string)($b['start_at'] ?? '')) ?: 0,
            default => 0,
        };

        if ($av === $bv) {
            return 0;
        }

        $desc = in_array(
            $sort,
            ['updated_desc', 'answers_desc', 'start_desc'],
            true
        );

        return $desc
            ? ($av < $bv ? 1 : -1)
            : ($av < $bv ? -1 : 1);
    });

    renderHeader('アンケート一覧', 'list');
    ?>

<main>
<div class="toolbar">
<div>
<h1>アンケート一覧</h1>
</div>

<div class="toolbar-right">
<a class="btn btn-primary"
   href="<?= h(currentUrl('edit', ['id' => 'new'])) ?>">
    ＋ 新規アンケート
</a>
</div>
</div>

<div class="card">
<form class="search-form" method="get">
<input type="hidden" name="screen" value="list">

<input
    type="search"
    name="q"
    value="<?= h($keyword) ?>"
    placeholder="タイトルを検索">

<select name="status">
<option value="">すべて</option>
<option value="published" <?= $status === 'published' ? 'selected' : '' ?>>
    公開中
</option>
<option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>
    下書き
</option>
<option value="stopped" <?= $status === 'stopped' ? 'selected' : '' ?>>
    停止
</option>
<option value="ended" <?= $status === 'ended' ? 'selected' : '' ?>>
    終了
</option>
</select>

<select name="sort">
<option value="updated_desc" <?= $sort === 'updated_desc' ? 'selected' : '' ?>>
    更新日：新しい順
</option>
<option value="updated_asc" <?= $sort === 'updated_asc' ? 'selected' : '' ?>>
    更新日：古い順
</option>
<option value="answers_desc" <?= $sort === 'answers_desc' ? 'selected' : '' ?>>
    回答数：多い順
</option>
<option value="answers_asc" <?= $sort === 'answers_asc' ? 'selected' : '' ?>>
    回答数：少ない順
</option>
<option value="start_desc" <?= $sort === 'start_desc' ? 'selected' : '' ?>>
    開始日：新しい順
</option>
<option value="start_asc" <?= $sort === 'start_asc' ? 'selected' : '' ?>>
    開始日：古い順
</option>
</select>

<button class="btn-primary" type="submit">検索</button>
</form>
</div>

<div class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
<th>タイトル</th>
<th>期間</th>
<th>ステータス</th>
<th>回答数</th>
<th>更新日</th>
<th>操作</th>
</tr>
</thead>
<tbody>

<?php if (!$items): ?>
<tr>
<td colspan="6">
<div class="empty">
アンケートがありません。
</div>
</td>
</tr>
<?php else: ?>

<?php foreach ($items as $survey): ?>
<?php
$id = (string)$survey['id'];
$count = countResponses($id);
?>
<tr>
<td>
<strong><?= h($survey['title']) ?></strong>
</td>

<td>
<?= h(formatDateTime($survey['start_at'] ?? '')) ?>
～
<?= h(formatDateTime($survey['end_at'] ?? '')) ?>
</td>

<td>
<span class="badge <?= h(statusClass($survey['status'])) ?>">
<?= h(statusLabel($survey['status'])) ?>
</span>
</td>

<td><?= h((string)$count) ?></td>

<td>
<?= h(formatDateTime($survey['updated_at'] ?? '')) ?>
</td>

<td>
<div class="action-row">
<a class="btn btn-small"
   href="<?= h(currentUrl('edit', ['id' => $id])) ?>">
確認・編集
</a>

<a class="btn btn-small"
   href="<?= h(currentUrl('preview', ['id' => $id])) ?>">
プレビュー
</a>

<a class="btn btn-small"
   href="<?= h(currentUrl('analytics', ['id' => $id])) ?>">
集計
</a>

<a class="btn btn-small"
   href="<?= h(currentUrl('send', ['id' => $id])) ?>">
送信
</a>

<form method="post"
      style="display:inline"
      onsubmit="return confirm('このアンケートを複製しますか？');">
<?= csrfField() ?>
<input type="hidden" name="action" value="duplicate">
<input type="hidden" name="id" value="<?= h($id) ?>">
<button class="btn btn-small" type="submit">複製</button>
</form>

<form method="post"
      style="display:inline"
      onsubmit="return confirm('このアンケートを削除しますか？');">
<?= csrfField() ?>
<input type="hidden" name="action" value="delete">
<input type="hidden" name="id" value="<?= h($id) ?>">
<button class="btn btn-danger btn-small" type="submit">削除</button>
</form>
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


// ============================================================
// 作成・編集
// ============================================================

function screenEdit(): void
{
    $id = (string)get('id', 'new');

    $items = surveys();

    if ($id === 'new') {
        $survey = [
            'id' => randomId('survey'),
            'title' => '',
            'description' => '',
            'start_at' => '',
            'end_at' => '',
            'status' => 'draft',
            'numbering_mode' => 'global',
            'groups' => [
                [
                    'id' => randomId('group'),
                    'title' => 'グループ1',
                    'questions' => [
                        defaultQuestion(),
                    ],
                ],
            ],
            'created_at' => nowIso(),
            'updated_at' => nowIso(),
        ];
    } else {
        $survey = findSurvey($items, $id);

        if ($survey === null) {
            fail('アンケートが見つかりません。', 404);
        }

        normalizeSurveyStatus($survey);
    }

    renderHeader(
        $id === 'new' ? 'アンケート作成' : 'アンケート編集',
        'list'
    );
    ?>

<main>

<div class="toolbar">
<h1>
<?= $id === 'new' ? 'アンケート作成' : 'アンケート編集' ?>
</h1>

<div class="toolbar-right">
<a class="btn"
   href="<?= h(currentUrl('list')) ?>">
キャンセル
</a>

<button
    form="survey-form"
    type="submit"
    class="btn btn-primary">
保存して一覧へ
</button>
</div>
</div>

<form
    id="survey-form"
    method="post"
    onsubmit="return prepareSurvey();">

<?= csrfField() ?>

<input type="hidden" name="action" value="save_survey">
<input type="hidden" name="id" value="<?= h($survey['id']) ?>">
<input type="hidden" name="groups_json" id="groups_json">

<div class="card">
<div class="form-grid">

<div class="form-group">
<label>アンケートタイトル</label>
<input
    name="title"
    required
    maxlength="200"
    value="<?= h($survey['title']) ?>">
</div>

<div class="form-group">
<label>質問番号の採番方式</label>
<select name="numbering_mode" id="numbering_mode">
<option
    value="global"
    <?= ($survey['numbering_mode'] ?? 'global') === 'global'
        ? 'selected' : '' ?>>
    アンケート全体で通番（Q1、Q2…）
</option>
<option
    value="group"
    <?= ($survey['numbering_mode'] ?? '') === 'group'
        ? 'selected' : '' ?>>
    グループ毎（Q1-1、Q1-2…）
</option>
</select>
</div>

<div class="form-group">
<label>開始日時</label>
<input
    type="datetime-local"
    name="start_at"
    value="<?= h(toDatetimeLocal($survey['start_at'] ?? '')) ?>">
</div>

<div class="form-group">
<label>終了日時</label>
<input
    type="datetime-local"
    name="end_at"
    value="<?= h(toDatetimeLocal($survey['end_at'] ?? '')) ?>">
</div>

</div>

<div class="form-group">
<label>アンケート説明</label>
<textarea
    name="description"
    maxlength="5000"><?= h($survey['description']) ?></textarea>
</div>

<div>
<strong>状態：</strong>

<span class="badge <?= h(statusClass($survey['status'])) ?>">
<?= h(statusLabel($survey['status'])) ?>
</span>

<?php if (statusCanChange($survey['status'])): ?>

<div class="action-row">

<?php if ($survey['status'] === 'draft'): ?>
<form method="post"
      onsubmit="return confirm('公開しますか？');">
<?= csrfField() ?>
<input type="hidden" name="action" value="change_status">
<input type="hidden" name="id" value="<?= h($survey['id']) ?>">
<input type="hidden" name="to" value="published">
<button class="btn btn-success" type="submit">
公開
</button>
</form>
<?php elseif ($survey['status'] === 'published'): ?>
<form method="post"
      onsubmit="return confirm('停止しますか？');">
<?= csrfField() ?>
<input type="hidden" name="action" value="change_status">
<input type="hidden" name="id" value="<?= h($survey['id']) ?>">
<input type="hidden" name="to" value="stopped">
<button class="btn btn-warning" type="submit">
停止
</button>
</form>
<?php elseif ($survey['status'] === 'stopped'): ?>
<form method="post"
      onsubmit="return confirm('再開しますか？');">
<?= csrfField() ?>
<input type="hidden" name="action" value="change_status">
<input type="hidden" name="id" value="<?= h($survey['id']) ?>">
<input type="hidden" name="to" value="published">
<button class="btn btn-success" type="submit">
再開
</button>
</form>
<?php endif; ?>

</div>

<?php endif; ?>

</div>
</div>

<div id="groups-container"></div>

<div class="card">
<button
    type="button"
    class="btn"
    onclick="addGroup()">
＋ グループを追加
</button>
</div>

<div class="sticky-actions">
<div class="toolbar" style="margin:0">
<div>
<a class="btn"
   href="<?= h(currentUrl('list')) ?>">
キャンセル
</a>
</div>

<div>
<button
    type="submit"
    class="btn btn-primary">
保存して一覧へ
</button>
</div>
</div>
</div>

</form>
</main>

<script>
let surveyGroups = <?= json_encode(
    $survey['groups'],
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) ?>;

function uid(prefix){
    return prefix + '-' +
        Math.random().toString(36).slice(2) +
        Date.now().toString(36);
}

function addGroup(){
    surveyGroups.push({
        id:uid('group'),
        title:'新しいグループ',
        questions:[newQuestion()]
    });

    renderGroups();
}

function newQuestion(){
    return {
        id:uid('question'),
        number:'',
        text:'',
        type:'single',
        required:false,
        options:[
            {id:uid('option'),label:'選択肢1'},
            {id:uid('option'),label:'選択肢2'}
        ],
        branching:{}
    };
}

function addQuestion(groupIndex){
    surveyGroups[groupIndex].questions.push(newQuestion());
    renderGroups();
}

function removeGroup(index){
    if(!confirm('このグループを削除しますか？')) return;

    surveyGroups.splice(index,1);

    if(surveyGroups.length === 0){
        surveyGroups.push({
            id:uid('group'),
            title:'グループ1',
            questions:[newQuestion()]
        });
    }

    renderGroups();
}

function removeQuestion(groupIndex, questionIndex){
    if(!confirm('この質問を削除しますか？')) return;

    surveyGroups[groupIndex].questions.splice(
        questionIndex,
        1
    );

    renderGroups();
}

function addOption(groupIndex, questionIndex){
    surveyGroups[groupIndex]
        .questions[questionIndex]
        .options.push({
            id:uid('option'),
            label:'新しい選択肢'
        });

    renderGroups();
}

function removeOption(groupIndex, questionIndex, optionIndex){
    surveyGroups[groupIndex]
        .questions[questionIndex]
        .options.splice(optionIndex,1);

    renderGroups();
}

function renderGroups(){
    const container =
        document.getElementById('groups-container');

    const mode =
        document.getElementById('numbering_mode').value;

    let globalNo = 1;

    container.innerHTML = '';

    surveyGroups.forEach((group, gi) => {
        const groupNo = gi + 1;

        const groupEl = document.createElement('div');
        groupEl.className = 'card group';
        groupEl.draggable = true;
        groupEl.dataset.index = gi;

        groupEl.addEventListener('dragstart', () => {
            groupEl.classList.add('dragging');
        });

        groupEl.addEventListener('dragend', () => {
            groupEl.classList.remove('dragging');
        });

        groupEl.innerHTML = `
            <div class="group-header">
                <span class="drag">☷</span>
                <input
                    class="group-title"
                    value="${escapeHtml(group.title)}"
                    onchange="surveyGroups[${gi}].title=this.value">
                <button
                    type="button"
                    class="btn btn-danger btn-small"
                    onclick="removeGroup(${gi})">
                    グループ削除
                </button>
            </div>
            <div class="group-body"
                 data-group-index="${gi}"></div>
        `;

        const body =
            groupEl.querySelector('.group-body');

        group.questions.forEach((question, qi) => {
            let number;

            if(mode === 'group'){
                number = 'Q' + groupNo + '-' + (qi + 1);
            }else{
                number = 'Q' + globalNo++;
            }

            question.number = number;

            const q = document.createElement('div');
            q.className = 'question';
            q.draggable = true;

            q.addEventListener('dragstart', () => {
                q.classList.add('dragging');
                q.dataset.group = gi;
                q.dataset.question = qi;
            });

            q.addEventListener('dragend', () => {
                q.classList.remove('dragging');
            });

            let optionsHtml = '';

            if(
                question.type === 'single' ||
                question.type === 'multiple'
            ){
                question.options =
                    question.options || [];

                optionsHtml =
                    '<div style="margin-top:10px">' +
                    '<strong>選択肢</strong>';

                question.options.forEach((option, oi) => {
                    optionsHtml += `
                        <div class="option-row">
                            <input
                                value="${escapeHtml(option.label)}"
                                onchange="surveyGroups[${gi}].questions[${qi}].options[${oi}].label=this.value">
                            <button
                                type="button"
                                class="btn btn-small"
                                onclick="removeOption(${gi},${qi},${oi})">
                                削除
                            </button>
                        </div>
                    `;
                });

                optionsHtml += `
                    <button
                        type="button"
                        class="btn btn-small"
                        onclick="addOption(${gi},${qi})">
                        ＋ 選択肢
                    </button>
                    </div>
                `;
            }

            q.innerHTML = `
                <div class="question-top">
                    <span class="drag">☷</span>
                    <span class="question-no">${number}</span>
                    <select
                        onchange="surveyGroups[${gi}].questions[${qi}].type=this.value;renderGroups()">
                        <option value="single"
                            ${question.type==='single'?'selected':''}>
                            単一選択
                        </option>
                        <option value="multiple"
                            ${question.type==='multiple'?'selected':''}>
                            複数選択
                        </option>
                        <option value="text"
                            ${question.type==='text'?'selected':''}>
                            自由記述
                        </option>
                    </select>

                    <label class="inline">
                        <input
                            type="checkbox"
                            ${question.required?'checked':''}
                            onchange="surveyGroups[${gi}].questions[${qi}].required=this.checked">
                        必須
                    </label>

                    <button
                        type="button"
                        class="btn btn-danger btn-small"
                        onclick="removeQuestion(${gi},${qi})">
                        削除
                    </button>
                </div>

                <div class="question-body">
                    <input
                        placeholder="質問文"
                        value="${escapeHtml(question.text)}"
                        onchange="surveyGroups[${gi}].questions[${qi}].text=this.value">
                    ${optionsHtml}
                </div>
            `;

            body.appendChild(q);
        });

        const addButton = document.createElement('button');
        addButton.type = 'button';
        addButton.className = 'btn';
        addButton.textContent = '＋ 質問を追加';
        addButton.onclick = () => addQuestion(gi);

        body.appendChild(addButton);

        container.appendChild(groupEl);
    });

    enableGroupDrop();
    enableQuestionDrop();
}

function enableGroupDrop(){
    const groups =
        [...document.querySelectorAll('.group')];

    groups.forEach(target => {
        target.addEventListener('dragover', e => {
            e.preventDefault();

            const dragging =
                document.querySelector('.group.dragging');

            if(!dragging || dragging === target) return;

            const from =
                Number(dragging.dataset.index);

            const to =
                Number(target.dataset.index);

            if(from === to) return;

            const moved =
                surveyGroups.splice(from,1)[0];

            surveyGroups.splice(to,0,moved);

            renderGroups();
        });
    });
}

function enableQuestionDrop(){
    const questions =
        [...document.querySelectorAll('.question')];

    questions.forEach(target => {
        target.addEventListener('dragover', e => {
            e.preventDefault();

            const dragging =
                document.querySelector('.question.dragging');

            if(!dragging || dragging === target) return;

            const fromGroup =
                Number(dragging.dataset.group);

            const fromQuestion =
                Number(dragging.dataset.question);

            const toGroup =
                Number(target.closest('.group-body')
                    .dataset.groupIndex);

            const toQuestion =
                [...target.parentNode.children]
                    .indexOf(target);

            const moved =
                surveyGroups[fromGroup]
                    .questions
                    .splice(fromQuestion,1)[0];

            surveyGroups[toGroup]
                .questions
                .splice(toQuestion,0,moved);

            renderGroups();
        });
    });
}

function escapeHtml(value){
    return String(value)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function prepareSurvey(){
    document.getElementById('groups_json').value =
        JSON.stringify(surveyGroups);

    return true;
}

document.getElementById('numbering_mode')
    .addEventListener('change',renderGroups);

renderGroups();
</script>

<?php
renderFooter();
}


// ============================================================
// プレビュー
// ============================================================

function screenPreview(): void
{
    $id = (string)get('id', '');

    $survey = findSurvey(surveys(), $id);

    if ($survey === null) {
        fail('アンケートが見つかりません。', 404);
    }

    normalizeSurveyStatus($survey);

    renderHeader('プレビュー', 'list');
    ?>

<main>

<div class="toolbar">
<h1>プレビュー</h1>

<a class="btn"
   href="<?= h(currentUrl('edit', ['id' => $id])) ?>">
編集画面へ戻る
</a>
</div>

<div class="card">
<h2><?= h($survey['title']) ?></h2>

<?php if (($survey['description'] ?? '') !== ''): ?>
<p><?= nl2br(h($survey['description'])) ?></p>
<?php endif; ?>

<p class="help">
<?= h(formatDateTime($survey['start_at'] ?? '')) ?>
～
<?= h(formatDateTime($survey['end_at'] ?? '')) ?>
</p>
</div>

<div class="card">

<?php foreach ($survey['groups'] as $group): ?>

<h2><?= h($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>

<div class="preview-question">

<div>
<strong><?= h($question['number']) ?>.
<?= h($question['text']) ?></strong>

<?php if (!empty($question['required'])): ?>
<span class="required">＊必須</span>
<?php endif; ?>
</div>

<div style="margin-top:12px">

<?php if ($question['type'] === 'single'): ?>

<?php foreach ($question['options'] as $option): ?>
<label class="inline" style="margin:8px 0">
<input type="radio" disabled>
<?= h($option['label']) ?>
</label>
<?php endforeach; ?>

<?php elseif ($question['type'] === 'multiple'): ?>

<?php foreach ($question['options'] as $option): ?>
<label class="inline" style="margin:8px 0">
<input type="checkbox" disabled>
<?= h($option['label']) ?>
</label>
<?php endforeach; ?>

<?php else: ?>

<textarea disabled></textarea>

<?php endif; ?>

</div>
</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>

</main>

<?php
renderFooter();
}


// ============================================================
// 回答者
// ============================================================

function screenAnswer(): void
{
    $id = (string)get('id', '');

    $survey = findSurvey(surveys(), $id);

    if ($survey === null) {
        fail('アンケートが見つかりません。', 404);
    }

    normalizeSurveyStatus($survey);

    if (($survey['status'] ?? '') !== 'published') {
        fail('このアンケートは現在回答できません。');
    }

    renderHeader('アンケート回答', '');
    ?>

<main>

<div class="card">
<h1><?= h($survey['title']) ?></h1>

<?php if ($survey['description'] !== ''): ?>
<p><?= nl2br(h($survey['description'])) ?></p>
<?php endif; ?>
</div>

<form method="post">

<?= csrfField() ?>

<input type="hidden"
       name="action"
       value="answer_confirm">

<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">

<div class="card">

<?php foreach ($survey['groups'] as $group): ?>

<h2><?= h($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>

<div
    class="preview-question"
    data-question-id="<?= h($question['id']) ?>">

<label>
<?= h($question['number']) ?>.
<?= h($question['text']) ?>

<?php if (!empty($question['required'])): ?>
<span class="required">＊必須</span>
<?php endif; ?>
</label>

<?php if ($question['type'] === 'single'): ?>

<?php foreach ($question['options'] as $option): ?>
<label class="inline" style="margin:10px 0">
<input
    type="radio"
    name="answers[<?= h($question['id']) ?>]"
    value="<?= h($option['id']) ?>"
    data-question="<?= h($question['id']) ?>"
    data-target="<?= h($question['branching'][$option['id']] ?? '') ?>">
<?= h($option['label']) ?>
</label>
<?php endforeach; ?>

<?php elseif ($question['type'] === 'multiple'): ?>

<?php foreach ($question['options'] as $option): ?>
<label class="inline" style="margin:10px 0">
<input
    type="checkbox"
    name="answers[<?= h($question['id']) ?>][]"
    value="<?= h($option['id']) ?>">
<?= h($option['label']) ?>
</label>
<?php endforeach; ?>

<?php else: ?>

<textarea
    name="answers[<?= h($question['id']) ?>]"
    <?= !empty($question['required']) ? 'required' : '' ?>></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>

<div class="sticky-actions">
<div style="text-align:right">
<button class="btn btn-primary"
        type="submit">
回答を確認する
</button>
</div>
</div>

</form>

</main>

<?php
renderFooter();
}


// ============================================================
// 回答確認
// ============================================================

function screenConfirm(): void
{
    $id = (string)($_SESSION[SESSION_ANSWER_KEY]['survey_id'] ?? '');

    $survey = findSurvey(surveys(), $id);

    if ($survey === null) {
        fail('回答情報が見つかりません。');
    }

    $answers =
        $_SESSION[SESSION_ANSWER_KEY]['answers'] ?? [];

    renderHeader('回答確認', '');
    ?>

<main>

<div class="toolbar">
<h1>回答確認</h1>
</div>

<form method="post">

<?= csrfField() ?>

<input type="hidden"
       name="action"
       value="answer_complete">

<div class="card">
<h2><?= h($survey['title']) ?></h2>

<?php foreach ($survey['groups'] as $group): ?>

<h3><?= h($group['title']) ?></h3>

<?php foreach ($group['questions'] as $question): ?>

<?php
$value = $answers[$question['id']] ?? '';
$display = '';

if (is_array($value)) {
    $labels = [];

    foreach ($question['options'] as $option) {
        if (in_array($option['id'], $value, true)) {
            $labels[] = $option['label'];
        }
    }

    $display = implode('、', $labels);
} elseif ($question['type'] === 'single') {
    foreach ($question['options'] as $option) {
        if ($option['id'] === $value) {
            $display = $option['label'];
            break;
        }
    }
} else {
    $display = (string)$value;
}
?>

<div class="preview-question">
<strong><?= h($question['number']) ?>.
<?= h($question['text']) ?></strong>

<div style="margin-top:8px">
<?= nl2br(h($display)) ?>
</div>
</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>

<div class="action-row">
<a class="btn"
   href="<?= h(currentUrl('answer', ['id' => $id])) ?>">
回答を修正
</a>

<button class="btn btn-primary"
        type="submit"
        onclick="return confirm('回答を送信しますか？');">
回答を送信
</button>
</div>

</form>

</main>

<?php
renderFooter();
}


// ============================================================
// 回答完了
// ============================================================

function screenComplete(): void
{
    $id = (string)get('id', '');

    renderHeader('回答完了', '');
    ?>

<main>

<div class="card"
     style="text-align:center;padding:50px 20px">

<h1>回答ありがとうございました</h1>

<p>
アンケートの回答を受け付けました。
</p>

</div>

</main>

<?php
renderFooter();
}


// ============================================================
// 送信画面
// ============================================================

function screenSend(): void
{
    $id = (string)get('id', '');

    $survey = findSurvey(surveys(), $id);

    if ($survey === null) {
        redirect(currentUrl('list'));
    }

    $customerItems = customers();

    $q = trim((string)get('q', ''));

    if ($q !== '') {
        $customerItems = array_values(array_filter(
            $customerItems,
            static function ($customer) use ($q) {
                return mb_stripos(
                    implode(' ', [
                        $customer['organization'] ?? '',
                        $customer['name'] ?? '',
                        $customer['email'] ?? '',
                        $customer['department'] ?? '',
                    ]),
                    $q
                ) !== false;
            }
        ));
    }

    $logs = array_values(array_filter(
        mailLogs(),
        static fn($log) =>
            ($log['survey_id'] ?? '') === $id
    ));

    renderHeader('顧客選択・メール送信', 'list');
    ?>

<main>

<div class="toolbar">
<div>
<h1>顧客選択・メール送信</h1>
<p>
対象：
<strong><?= h($survey['title']) ?></strong>
</p>
</div>

<a class="btn"
   href="<?= h(currentUrl('list')) ?>">
一覧へ戻る
</a>
</div>

<div class="card">

<div class="tabs">
<a class="tab active"
   href="<?= h(currentUrl('send', ['id' => $id])) ?>">
顧客選択・送信
</a>

<a class="tab"
   href="<?= h(currentUrl('send', [
       'id' => $id,
       'tab' => 'history'
   ])) ?>">
送信履歴
</a>
</div>

<?php if (get('tab') === 'history'): ?>

<h2>送信履歴</h2>

<?php if (!$logs): ?>

<div class="empty">
送信履歴はありません。
</div>

<?php else: ?>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>顧客</th>
<th>メール</th>
<th>結果</th>
<th>メッセージ</th>
</tr>
</thead>

<tbody>

<?php foreach ($logs as $log): ?>
<tr>
<td><?= h(formatDateTime($log['created_at'] ?? '')) ?></td>
<td><?= h($log['customer_name'] ?? '') ?></td>
<td><?= h($log['email'] ?? '') ?></td>
<td>
<span class="badge <?= ($log['success'] ?? false)
    ? 'badge-published'
    : 'badge-ended' ?>">
<?= ($log['success'] ?? false)
    ? '成功'
    : '失敗' ?>
</span>
</td>
<td><?= h($log['message'] ?? '') ?></td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>

<?php endif; ?>

<?php else: ?>

<form method="get" style="margin-bottom:18px">
<input type="hidden" name="screen" value="send">
<input type="hidden" name="id" value="<?= h($id) ?>">

<div class="inline">
<input
    type="search"
    name="q"
    value="<?= h($q) ?>"
    placeholder="顧客名・組織名・メール等を検索">
<button class="btn" type="submit">検索</button>
</div>
</form>

<form method="post">

<?= csrfField() ?>

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">

<div class="form-group">
<label>メール件名</label>
<input
    name="subject"
    required
    value="<?= h($survey['title'] . ' のご案内') ?>">
</div>

<div class="form-group">
<label>メール本文</label>
<textarea
    name="body"
    rows="12"
    required><?= h(
        "{顧客名} 様\n\n" .
        "アンケートへのご協力をお願いいたします。\n\n" .
        "{アンケートURL}\n"
    ) ?></textarea>

<div class="help">
使用可能な変数：{顧客名}、{アンケートURL}
</div>
</div>

<h2>顧客</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>
<input
    type="checkbox"
    onclick="toggleCustomers(this)">
</th>
<th>組織名</th>
<th>氏名</th>
<th>メールアドレス</th>
<th>部署</th>
</tr>
</thead>

<tbody>

<?php if (!$customerItems): ?>

<tr>
<td colspan="5">
<div class="empty">
顧客データがありません。
kintoneから同期してください。
</div>
</td>
</tr>

<?php else: ?>

<?php foreach ($customerItems as $customer): ?>

<tr>
<td>
<input
    class="customer-check"
    type="checkbox"
    name="customer_ids[]"
    value="<?= h($customer['id']) ?>">
</td>

<td><?= h($customer['organization'] ?? '') ?></td>
<td><?= h($customer['name'] ?? '') ?></td>
<td><?= h($customer['email'] ?? '') ?></td>
<td><?= h($customer['department'] ?? '') ?></td>
</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>
</div>

<div class="action-row">
<button
    class="btn btn-primary"
    type="submit"
    onclick="return confirm('選択した顧客へメールを送信しますか？');">
一括送信
</button>
</div>

</form>

<?php endif; ?>

</div>

</main>

<script>
function toggleCustomers(master){
    document
        .querySelectorAll('.customer-check')
        .forEach(function(el){
            el.checked = master.checked;
        });
}
</script>

<?php
renderFooter();
}


// ============================================================
// 集計
// ============================================================

function screenAnalytics(): void
{
    $id = (string)get('id', '');

    $survey = findSurvey(surveys(), $id);

    if ($survey === null) {
        redirect(currentUrl('list'));
    }

    $allResponses = array_values(array_filter(
        responses(),
        static fn($r) =>
            ($r['survey_id'] ?? '') === $id
    ));

    $customerItems = customers();

    $registeredEmails = [];

    foreach ($customerItems as $customer) {
        $email = strtolower(
            trim((string)($customer['email'] ?? ''))
        );

        if ($email !== '') {
            $registeredEmails[$email] = true;
        }
    }

    $registeredCount = 0;
    $unregisteredCount = 0;

    foreach ($allResponses as $response) {
        $email = strtolower(
            trim((string)($response['email'] ?? ''))
        );

        if (
            $email !== '' &&
            isset($registeredEmails[$email])
        ) {
            $registeredCount++;
        } else {
            $unregisteredCount++;
        }
    }

    $sendLogs = array_values(array_filter(
        mailLogs(),
        static fn($log) =>
            ($log['survey_id'] ?? '') === $id &&
            ($log['success'] ?? false)
    ));

    $targetCount = count(array_unique(
        array_map(
            static fn($log) => $log['email'] ?? '',
            $sendLogs
        )
    ));

    $answerCount = count($allResponses);

    $answerRate =
        $targetCount > 0
            ? round(
                ($answerCount / $targetCount) * 100,
                1
            )
            : 0;

    renderHeader('回答集計・分析', 'list');
    ?>

<main>

<div class="toolbar">
<h1>回答集計・分析</h1>

<a class="btn"
   href="<?= h(currentUrl('list')) ?>">
一覧へ戻る
</a>
</div>

<div class="card">
<p>
対象アンケート：
<strong><?= h($survey['title']) ?></strong>
</p>
</div>

<div class="stats">

<div class="stat">
<div class="stat-label">送信対象者数</div>
<div class="stat-value"><?= h($targetCount) ?></div>
</div>

<div class="stat">
<div class="stat-label">回答数</div>
<div class="stat-value"><?= h($answerCount) ?></div>
</div>

<div class="stat">
<div class="stat-label">未登録回答数</div>
<div class="stat-value"><?= h($unregisteredCount) ?></div>
</div>

<div class="stat">
<div class="stat-label">回答率</div>
<div class="stat-value"><?= h($answerRate) ?>%</div>
</div>

</div>

<?php if ($answerCount === 0): ?>

<div class="card">
<div class="empty">
現在、回答データはありません
</div>
</div>

<?php else: ?>

<div class="card">

<div class="toolbar">
<h2>出力</h2>

<div class="toolbar-right">

<a class="btn"
   href="<?= h(currentUrl('analytics', [
       'id' => $id,
       'export' => 'csv'
   ])) ?>">
CSV
</a>

<a class="btn"
   href="<?= h(currentUrl('analytics', [
       'id' => $id,
       'export' => 'pdf'
   ])) ?>">
PDF
</a>

</div>
</div>

</div>

<div class="card">

<h2>設問別集計</h2>

<?php foreach ($survey['groups'] as $group): ?>

<h3><?= h($group['title']) ?></h3>

<?php foreach ($group['questions'] as $question): ?>

<?php
$questionAnswers = [];

foreach ($allResponses as $response) {
    if (array_key_exists(
        $question['id'],
        $response['answers'] ?? []
    )) {
        $questionAnswers[] =
            $response['answers'][$question['id']];
    }
}

$total = count($questionAnswers);
?>

<div class="preview-question">

<strong>
<?= h($question['number']) ?>.
<?= h($question['text']) ?>
</strong>

<?php if (
    $question['type'] === 'single' ||
    $question['type'] === 'multiple'
): ?>

<?php foreach ($question['options'] as $option): ?>

<?php
$count = 0;

foreach ($questionAnswers as $answer) {
    if (is_array($answer)) {
        if (in_array(
            $option['id'],
            $answer,
            true
        )) {
            $count++;
        }
    } elseif (
        (string)$answer ===
        (string)$option['id']
    ) {
        $count++;
    }
}

$percent =
    $total > 0
        ? round(($count / $total) * 100, 1)
        : 0;
?>

<div style="margin-top:8px">
<?= h($option['label']) ?>：
<strong><?= h($count) ?></strong>
（<?= h($percent) ?>%）
</div>

<?php endforeach; ?>

<?php else: ?>

<div class="help">
自由記述回答 <?= h($total) ?>件
</div>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>

<div class="card">

<h2>個別回答</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>回答日時</th>
<th>メール</th>
<th>回答内容</th>
</tr>
</thead>

<tbody>

<?php foreach ($allResponses as $response): ?>

<tr>
<td><?= h(formatDateTime(
    $response['created_at'] ?? ''
)) ?></td>

<td>
<?= h($response['email'] ?? '未登録') ?>
</td>

<td>
<?php foreach ($survey['groups'] as $group): ?>

<?php foreach ($group['questions'] as $question): ?>

<?php
$value =
    $response['answers'][$question['id']]
    ?? '';

$display = '';

if (is_array($value)) {
    $labels = [];

    foreach ($question['options'] as $option) {
        if (in_array(
            $option['id'],
            $value,
            true
        )) {
            $labels[] = $option['label'];
        }
    }

    $display = implode('、', $labels);
} elseif ($question['type'] === 'single') {
    foreach ($question['options'] as $option) {
        if ($option['id'] === $value) {
            $display = $option['label'];
            break;
        }
    }
} else {
    $display = (string)$value;
}
?>

<div>
<strong><?= h($question['number']) ?>:</strong>
<?= nl2br(h($display)) ?>
</div>

<?php endforeach; ?>

<?php endforeach; ?>
</td>
</tr>

<?php endforeach; ?>

</tbody>
</table>
</div>

</div>

<?php endif; ?>

</main>

<?php
renderFooter();
}


// ============================================================
// kintone設定
// ============================================================

function screenKintone(): void
{
    $settings = settings();
    $k = $settings['kintone'];

    renderHeader('kintone連携設定', 'kintone');
    ?>

<main>

<h1>kintone連携設定</h1>

<div class="card">

<form method="post">

<?= csrfField() ?>

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="form-grid">

<div class="form-group">
<label>サブドメイン</label>
<input
    name="subdomain"
    value="<?= h($k['subdomain'] ?? '') ?>"
    placeholder="example">
<div class="help">
https://example.cybozu.com の「example」
</div>
</div>

<div class="form-group">
<label>顧客管理アプリID</label>
<input
    name="app_id"
    inputmode="numeric"
    value="<?= h($k['app_id'] ?? '') ?>">
</div>

<div class="form-group">
<label>ログイン名</label>
<input
    name="login_name"
    autocomplete="off"
    value="<?= h($k['login_name'] ?? '') ?>">
</div>

<div class="form-group">
<label>パスワード</label>
<input
    type="password"
    name="password"
    autocomplete="new-password"
    value="<?= h($k['password'] ?? '') ?>">
</div>

<div class="form-group">
<label>Proxy</label>
<input
    name="proxy"
    value="<?= h($k['proxy'] ?? '') ?>"
    placeholder="proxy.example.local:8080">
<div class="help">
host:port形式。サーバとポート番号は分けません。
未入力の場合は直接接続します。
</div>
</div>

<div class="form-group">
<label>SSL証明書検証</label>
<label class="inline">
<input
    type="checkbox"
    name="verify_ssl"
    value="1"
    <?= !empty($k['verify_ssl'])
        ? 'checked'
        : '' ?>>
有効
</label>
<div class="help">
本番環境では有効にしてください。
</div>
</div>

</div>

<div class="action-row">
<button
    class="btn btn-primary"
    type="submit">
設定を保存
</button>
</div>

</form>

</div>

<div class="card">

<h2>接続テスト</h2>

<p class="help">
設定保存と接続テストは別操作です。
接続テストでは実際のkintone APIへ接続します。
</p>

<form method="post">

<?= csrfField() ?>

<input type="hidden"
       name="action"
       value="test_kintone">

<button
    class="btn"
    type="submit">
kintone接続テスト
</button>

</form>

</div>

<div class="card">

<h2>顧客データ同期</h2>

<p class="help">
実際のkintoneから顧客情報を取得します。
</p>

<form method="post">

<?= csrfField() ?>

<input type="hidden"
       name="action"
       value="sync_kintone">

<button
    class="btn btn-success"
    type="submit">
kintoneから顧客を同期
</button>

</form>

</div>

</main>

<?php
renderFooter();
}


// ============================================================
// メール設定
// ============================================================

function screenMail(): void
{
    $settings = settings();
    $smtp = $settings['smtp'];

    renderHeader('メールサーバ設定', 'mail');
    ?>

<main>

<h1>メールサーバ設定</h1>

<div class="card">

<form method="post">

<?= csrfField() ?>

<input type="hidden"
       name="action"
       value="save_smtp">

<div class="form-grid">

<div class="form-group">
<label>SMTPサーバ</label>
<input
    name="host"
    value="<?= h($smtp['host'] ?? '') ?>">
</div>

<div class="form-group">
<label>SMTPポート</label>
<input
    type="number"
    name="port"
    min="1"
    max="65535"
    value="<?= h($smtp['port'] ?? 587) ?>">
</div>

<div class="form-group">
<label>暗号化方式</label>
<select name="encryption">
<option
    value="ssl"
    <?= ($smtp['encryption'] ?? '') === 'ssl'
        ? 'selected'
        : '' ?>>
SSL
</option>

<option
    value="tls"
    <?= ($smtp['encryption'] ?? 'tls') === 'tls'
        ? 'selected'
        : '' ?>>
TLS
</option>

<option
    value="none"
    <?= ($smtp['encryption'] ?? '') === 'none'
        ? 'selected'
        : '' ?>>
なし
</option>
</select>
</div>

<div class="form-group">
<label>SMTP認証</label>
<label class="inline">
<input
    type="checkbox"
    name="auth"
    value="1"
    <?= !empty($smtp['auth'])
        ? 'checked'
        : '' ?>>
使用する
</label>
</div>

<div class="form-group">
<label>SMTPユーザー名</label>
<input
    name="username"
    autocomplete="off"
    value="<?= h($smtp['username'] ?? '') ?>">
</div>

<div class="form-group">
<label>SMTPパスワード</label>
<input
    type="password"
    name="password"
    autocomplete="new-password"
    value="<?= h($smtp['password'] ?? '') ?>">
</div>

<div class="form-group">
<label>送信元メールアドレス</label>
<input
    type="email"
    name="from_email"
    value="<?= h($smtp['from_email'] ?? '') ?>">
</div>

<div class="form-group">
<label>送信元名</label>
<input
    name="from_name"
    value="<?= h($smtp['from_name'] ?? '') ?>">
</div>

<div class="form-group">
<label>返信先メールアドレス</label>
<input
    type="email"
    name="reply_to"
    value="<?= h($smtp['reply_to'] ?? '') ?>">
</div>

</div>

<button
    class="btn btn-primary"
    type="submit">
設定を保存
</button>

</form>

</div>

<div class="card">

<h2>接続確認</h2>

<form method="post">

<?= csrfField() ?>

<input type="hidden"
       name="action"
       value="test_smtp">

<button
    class="btn"
    type="submit">
SMTP接続テスト
</button>

</form>

</div>

<div class="card">

<h2>テストメール</h2>

<form method="post">

<?= csrfField() ?>

<input type="hidden"
       name="action"
       value="test_mail">

<div class="form-group">
<label>送信先</label>
<input
    type="email"
    name="to"
    required>
</div>

<button
    class="btn btn-success"
    type="submit">
テストメール送信
</button>

</form>

</div>

</main>

<?php
renderFooter();
}


// ============================================================
// ユーティリティ
// ============================================================

function findSurvey(array $items, string $id): ?array
{
    foreach ($items as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function findSurveyIndex(array $items, string $id): int
{
    foreach ($items as $index => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $index;
        }
    }

    return -1;
}

function countResponses(string $surveyId): int
{
    return count(array_filter(
        responses(),
        static fn($response) =>
            ($response['survey_id'] ?? '') === $surveyId
    ));
}

function formatDateTime(string $value): string
{
    if ($value === '') {
        return '-';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return $value;
    }

    return date('Y/m/d H:i', $timestamp);
}

function toDatetimeLocal(string $value): string
{
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d\TH:i', $timestamp);
}

function surveyPublicUrl(string $id): string
{
    $scheme =
        (
            (!empty($_SERVER['HTTPS']) &&
             $_SERVER['HTTPS'] !== 'off')
            ||
            (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        )
        ? 'https'
        : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' .
        $host .
        rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') .
        '/index.php?screen=answer&id=' .
        rawurlencode($id);
}


// ============================================================
// POST処理
// ============================================================

function handlePost(): void
{
    /*
     * POST処理は必ず最初にCSRF検証する。
     *
     * ここより前に業務処理を実行しない。
     */
    verifyCsrf();

    $action = (string)post('action', '');

    try {

        switch ($action) {

            // ------------------------------------------------
            // アンケート保存
            // ------------------------------------------------

            case 'save_survey':

                $id = (string)post('id', '');

                if ($id === '') {
                    throw new RuntimeException(
                        'アンケートIDがありません。'
                    );
                }

                $payload = validateSurveyPayload();

                $items = surveys();

                $index = findSurveyIndex($items, $id);

                if ($index === -1) {

                    $survey = array_merge(
                        $payload,
                        [
                            'id' => $id,
                            'status' => 'draft',
                            'created_at' => nowIso(),
                            'updated_at' => nowIso(),
                        ]
                    );

                    $items[] = $survey;

                } else {

                    $current = $items[$index];

                    /*
                     * 保存時に状態を勝手に変更しない。
                     */
                    $payload['id'] = $current['id'];
                    $payload['status'] =
                        $current['status'] ?? 'draft';
                    $payload['created_at'] =
                        $current['created_at'] ?? nowIso();
                    $payload['updated_at'] = nowIso();

                    if ($payload['status'] === 'ended') {
                        /*
                         * 終了状態から編集しても終了状態を維持。
                         */
                        $payload['status'] = 'ended';
                    }

                    $items[$index] = $payload;
                }

                writeJson(DATA_FILE_SURVEYS, $items);

                redirect(currentUrl('list'));

            // ------------------------------------------------
            // 削除
            // ------------------------------------------------

            case 'delete':

                $id = (string)post('id', '');

                $items = surveys();
                $index = findSurveyIndex($items, $id);

                if ($index === -1) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                array_splice($items, $index, 1);

                writeJson(DATA_FILE_SURVEYS, $items);

                redirect(currentUrl('list'));

            // ------------------------------------------------
            // 複製
            // ------------------------------------------------

            case 'duplicate':

                $id = (string)post('id', '');

                $items = surveys();

                $source = findSurvey($items, $id);

                if ($source === null) {
                    throw new RuntimeException(
                        '複製元アンケートが見つかりません。'
                    );
                }

                $source['id'] = randomId('survey');
                $source['title'] .= '（コピー）';
                $source['status'] = 'draft';
                $source['created_at'] = nowIso();
                $source['updated_at'] = nowIso();

                foreach ($source['groups'] as &$group) {

                    $group['id'] = randomId('group');

                    foreach ($group['questions'] as &$question) {

                        $question['id'] =
                            randomId('question');

                        foreach (
                            $question['options'] as
                            &$option
                        ) {
                            $option['id'] =
                                randomId('option');
                        }

                        $question['branching'] = [];
                    }

                    unset($question);
                }

                unset($group);

                recalculateQuestionNumbers($source);

                $items[] = $source;

                writeJson(DATA_FILE_SURVEYS, $items);

                redirect(currentUrl('list'));

            // ------------------------------------------------
            // 状態変更
            // ------------------------------------------------

            case 'change_status':

                $id = (string)post('id', '');
                $to = (string)post('to', '');

                $items = surveys();

                $index = findSurveyIndex($items, $id);

                if ($index === -1) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                normalizeSurveyStatus($items[$index]);

                $from = $items[$index]['status'];

                if (!validStatusTransition($from, $to)) {
                    throw new RuntimeException(
                        '許可されていない状態変更です。'
                    );
                }

                $items[$index]['status'] = $to;
                $items[$index]['updated_at'] = nowIso();

                writeJson(DATA_FILE_SURVEYS, $items);

                redirect(currentUrl('edit', [
                    'id' => $id
                ]));

            // ------------------------------------------------
            // kintone設定保存
            // ------------------------------------------------

            case 'save_kintone':

                $settings = settings();

                $subdomain = trim(
                    (string)post('subdomain', '')
                );

                $appId = trim(
                    (string)post('app_id', '')
                );

                $loginName = trim(
                    (string)post('login_name', '')
                );

                $password = (string)post('password', '');

                $proxy = validateProxy(
                    (string)post('proxy', '')
                );

                if (
                    $subdomain !== '' &&
                    preg_match(
                        '/^[a-zA-Z0-9][a-zA-Z0-9-]*$/',
                        $subdomain
                    ) !== 1
                ) {
                    throw new RuntimeException(
                        'kintoneサブドメインが不正です。'
                    );
                }

                if (
                    $appId !== '' &&
                    !ctype_digit($appId)
                ) {
                    throw new RuntimeException(
                        'kintoneアプリIDが不正です。'
                    );
                }

                $settings['kintone'] = [
                    'subdomain' => $subdomain,
                    'app_id' => $appId,
                    'login_name' => $loginName,
                    'password' => $password,
                    'proxy' => $proxy,
                    'verify_ssl' =>
                        !empty($_POST['verify_ssl']),
                ];

                writeJson(DATA_FILE_SETTINGS, $settings);

                redirect(currentUrl('kintone'));

            // ------------------------------------------------
            // kintone接続テスト
            // ------------------------------------------------

            case 'test_kintone':

                $settings = settings();

                /*
                 * records APIへ実接続。
                 * モック値は返さない。
                 */
                kintoneGetRecords($settings);

                redirect(
                    currentUrl('kintone', [
                        'result' => 'success'
                    ])
                );

            // ------------------------------------------------
            // kintone同期
            // ------------------------------------------------

            case 'sync_kintone':

                $settings = settings();

                $records = kintoneGetRecords($settings);

                $mapped = mapKintoneCustomers($records);

                writeJson(
                    DATA_FILE_CUSTOMERS,
                    $mapped
                );

                redirect(
                    currentUrl('kintone', [
                        'result' => 'synced',
                        'count' => count($mapped)
                    ])
                );

            // ------------------------------------------------
            // SMTP設定保存
            // ------------------------------------------------

            case 'save_smtp':

                $settings = settings();

                $host = trim(
                    (string)post('host', '')
                );

                $port = (int)post('port', 587);

                $encryption =
                    strtolower(
                        (string)post(
                            'encryption',
                            'tls'
                        )
                    );

                if ($host === '') {
                    throw new RuntimeException(
                        'SMTPサーバは必須です。'
                    );
                }

                if (
                    $port < 1 ||
                    $port > 65535
                ) {
                    throw new RuntimeException(
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
                    throw new RuntimeException(
                        '暗号化方式が不正です。'
                    );
                }

                $settings['smtp'] = [
                    'host' => $host,
                    'port' => $port,
                    'encryption' => $encryption,
                    'auth' => !empty($_POST['auth']),
                    'username' =>
                        trim(
                            (string)post(
                                'username',
                                ''
                            )
                        ),
                    'password' =>
                        (string)post(
                            'password',
                            ''
                        ),
                    'from_email' =>
                        validateEmail(
                            (string)post(
                                'from_email',
                                ''
                            ),
                            '送信元メールアドレス'
                        ),
                    'from_name' =>
                        trim(
                            (string)post(
                                'from_name',
                                ''
                            )
                        ),
                    'reply_to' =>
                        trim(
                            (string)post(
                                'reply_to',
                                ''
                            )
                        ),
                ];

                if (
                    $settings['smtp']['reply_to'] !== '' &&
                    filter_var(
                        $settings['smtp']['reply_to'],
                        FILTER_VALIDATE_EMAIL
                    ) === false
                ) {
                    throw new RuntimeException(
                        '返信先メールアドレスが不正です。'
                    );
                }

                writeJson(
                    DATA_FILE_SETTINGS,
                    $settings
                );

                redirect(currentUrl('mail'));

            // ------------------------------------------------
            // SMTP接続テスト
            // ------------------------------------------------

            case 'test_smtp':

                $settings = settings();

                $fp = smtpOpen(
                    $settings['smtp']
                );

                smtpCommand(
                    $fp,
                    'QUIT',
                    [221]
                );

                fclose($fp);

                redirect(
                    currentUrl('mail', [
                        'result' => 'success'
                    ])
                );

            // ------------------------------------------------
            // テストメール
            // ------------------------------------------------

            case 'test_mail':

                $to = validateEmail(
                    (string)post('to', ''),
                    '送信先メールアドレス'
                );

                $settings = settings();

                smtpSend(
                    $settings['smtp'],
                    $to,
                    'アンケートアプリ テストメール',
                    "これはアンケートアプリからのテストメールです。\n"
                );

                redirect(
                    currentUrl('mail', [
                        'result' => 'mail_sent'
                    ])
                );

            // ------------------------------------------------
            // メール一括送信
            // ------------------------------------------------

            case 'send_mail':

                $surveyId = (string)post(
                    'survey_id',
                    ''
                );

                $survey = findSurvey(
                    surveys(),
                    $surveyId
                );

                if ($survey === null) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
                }

                $subject = requiredString(
                    post('subject'),
                    'メール件名',
                    500
                );

                $body = requiredString(
                    post('body'),
                    'メール本文',
                    20000
                );

                $selectedIds =
                    $_POST['customer_ids'] ?? [];

                if (!is_array($selectedIds)) {
                    $selectedIds = [];
                }

                if (!$selectedIds) {
                    throw new RuntimeException(
                        '送信先を選択してください。'
                    );
                }

                $settings = settings();
                $customers = customers();
                $logs = mailLogs();

                foreach ($selectedIds as $customerId) {

                    $customer = null;

                    foreach ($customers as $item) {
                        if (
                            ($item['id'] ?? '') ===
                            (string)$customerId
                        ) {
                            $customer = $item;
                            break;
                        }
                    }

                    if ($customer === null) {
                        continue;
                    }

                    $email = trim(
                        (string)(
                            $customer['email'] ?? ''
                        )
                    );

                    $customerName =
                        (string)(
                            $customer['name'] ?? ''
                        );

                    $mailSubject =
                        str_replace(
                            [
                                '{顧客名}',
                                '{アンケートURL}',
                            ],
                            [
                                $customerName,
                                surveyPublicUrl(
                                    $surveyId
                                ),
                            ],
                            $subject
                        );

                    $mailBody =
                        str_replace(
                            [
                                '{顧客名}',
                                '{アンケートURL}',
                            ],
                            [
                                $customerName,
                                surveyPublicUrl(
                                    $surveyId
                                ),
                            ],
                            $body
                        );

                    $log = [
                        'id' => randomId('mail'),
                        'survey_id' => $surveyId,
                        'customer_id' =>
                            $customer['id'],
                        'customer_name' =>
                            $customerName,
                        'email' => $email,
                        'created_at' => nowIso(),
                        'success' => false,
                        'message' => '',
                    ];

                    if (
                        filter_var(
                            $email,
                            FILTER_VALIDATE_EMAIL
                        ) === false
                    ) {
                        $log['message'] =
                            'メールアドレスが不正です。';
                    } else {
                        try {
                            smtpSend(
                                $settings['smtp'],
                                $email,
                                $mailSubject,
                                $mailBody
                            );

                            $log['success'] = true;
                            $log['message'] = '送信成功';

                        } catch (
                            Throwable $e
                        ) {
                            $log['message'] =
                                safeExternalError(
                                    $e->getMessage()
                                );
                        }
                    }

                    $logs[] = $log;
                }

                writeJson(
                    DATA_FILE_MAIL_LOG,
                    $logs
                );

                redirect(
                    currentUrl('send', [
                        'id' => $surveyId
                    ])
                );

            // ------------------------------------------------
            // 回答確認
            // ------------------------------------------------

            case 'answer_confirm':

                $surveyId = (string)post(
                    'survey_id',
                    ''
                );

                $survey = findSurvey(
                    surveys(),
                    $surveyId
                );

                if ($survey === null) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                normalizeSurveyStatus($survey);

                if (
                    ($survey['status'] ?? '') !==
                    'published'
                ) {
                    throw new RuntimeException(
                        'このアンケートは現在回答できません。'
                    );
                }

                $answers =
                    $_POST['answers'] ?? [];

                if (!is_array($answers)) {
                    $answers = [];
                }

                /*
                 * サーバー側で必須・形式を再検証する。
                 */
                foreach ($survey['groups'] as $group) {
                    foreach (
                        $group['questions'] as
                        $question
                    ) {

                        $qid = $question['id'];

                        $value =
                            $answers[$qid] ?? null;

                        if (
                            !empty($question['required']) &&
                            (
                                $value === null ||
                                $value === '' ||
                                $value === []
                            )
                        ) {
                            throw new RuntimeException(
                                $question['number'] .
                                ' は必須です。'
                            );
                        }

                        if (
                            $value !== null &&
                            $question['type'] ===
                            'single' &&
                            is_array($value)
                        ) {
                            throw new RuntimeException(
                                '回答形式が不正です。'
                            );
                        }

                        if (
                            $question['type'] ===
                            'multiple' &&
                            $value !== null &&
                            !is_array($value)
                        ) {
                            throw new RuntimeException(
                                '回答形式が不正です。'
                            );
                        }

                        if (
                            in_array(
                                $question['type'],
                                ['single', 'multiple'],
                                true
                            )
                        ) {
                            $allowed = array_map(
                                static fn($o) =>
                                    (string)$o['id'],
                                $question['options']
                            );

                            $checkValues =
                                is_array($value)
                                    ? $value
                                    : [$value];

                            foreach (
                                $checkValues as
                                $answerValue
                            ) {
                                if (
                                    !in_array(
                                        (string)$answerValue,
                                        $allowed,
                                        true
                                    )
                                ) {
                                    throw new RuntimeException(
                                        '選択肢が不正です。'
                                    );
                                }
                            }
                        }
                    }
                }

                $_SESSION[SESSION_ANSWER_KEY] = [
                    'survey_id' => $surveyId,
                    'answers' => $answers,
                ];

                redirect(currentUrl('confirm'));

            // ------------------------------------------------
            // 回答完了
            // ------------------------------------------------

            case 'answer_complete':

                $data =
                    $_SESSION[SESSION_ANSWER_KEY]
                    ?? null;

                if (
                    !is_array($data) ||
                    empty($data['survey_id'])
                ) {
                    throw new RuntimeException(
                        '回答情報がありません。'
                    );
                }

                $surveyId =
                    (string)$data['survey_id'];

                $survey = findSurvey(
                    surveys(),
                    $surveyId
                );

                if ($survey === null) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                $responseItems = responses();

                $responseItems[] = [
                    'id' => randomId('response'),
                    'survey_id' => $surveyId,
                    'email' => '',
                    'answers' =>
                        $data['answers'] ?? [],
                    'created_at' => nowIso(),
                ];

                writeJson(
                    DATA_FILE_RESPONSES,
                    $responseItems
                );

                unset(
                    $_SESSION[SESSION_ANSWER_KEY]
                );

                redirect(
                    currentUrl(
                        'complete',
                        ['id' => $surveyId]
                    )
                );

            default:

                throw new RuntimeException(
                    '不明な操作です。'
                );
        }

    } catch (Throwable $e) {

        /*
         * 機密情報をエラー画面へ出さない。
         */
        $message = safeExternalError(
            $e->getMessage()
        );

        fail($message);
    }
}


// ============================================================
// 外部サービスエラーの安全な表示
// ============================================================

function safeExternalError(string $message): string
{
    /*
     * パスワード・Authorization等が
     * 例外メッセージに含まれた場合に備える。
     */
    $patterns = [
        '/X-Cybozu-Authorization\s*:\s*[^\s]+/i',
        '/Authorization\s*:\s*[^\s]+/i',
        '/password\s*[:=]\s*[^\s]+/i',
    ];

    foreach ($patterns as $pattern) {
        $message = preg_replace(
            $pattern,
            '[REDACTED]',
            $message
        ) ?? $message;
    }

    return mb_substr($message, 0, 1000);
}


// ============================================================
// CSV / PDF 出力
// ============================================================

function handleExport(): void
{
    if (get('screen') !== 'analytics') {
        return;
    }

    $export = (string)get('export', '');

    if (!in_array($export, ['csv', 'pdf'], true)) {
        return;
    }

    $id = (string)get('id', '');

    $survey = findSurvey(
        surveys(),
        $id
    );

    if ($survey === null) {
        fail('アンケートが見つかりません。', 404);
    }

    $items = array_values(array_filter(
        responses(),
        static fn($r) =>
            ($r['survey_id'] ?? '') === $id
    ));

    $rows = [
        ['回答日時', 'メール']
    ];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $rows[0][] =
                $question['number'] .
                ' ' .
                $question['text'];
        }
    }

    foreach ($items as $response) {

        $row = [
            $response['created_at'] ?? '',
            $response['email'] ?? '',
        ];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {

                $value =
                    $response['answers']
                    [$question['id']]
                    ?? '';

                if (is_array($value)) {
                    $labels = [];

                    foreach (
                        $question['options']
                        as $option
                    ) {
                        if (
                            in_array(
                                $option['id'],
                                $value,
                                true
                            )
                        ) {
                            $labels[] =
                                $option['label'];
                        }
                    }

                    $value =
                        implode('、', $labels);

                } elseif (
                    $question['type'] === 'single'
                ) {
                    foreach (
                        $question['options']
                        as $option
                    ) {
                        if (
                            $option['id'] ===
                            $value
                        ) {
                            $value =
                                $option['label'];
                            break;
                        }
                    }
                }

                $row[] = (string)$value;
            }
        }

        $rows[] = $row;
    }

    if ($export === 'csv') {
        outputCsv(
            $rows,
            'survey-' . $id . '-responses.csv'
        );
    }

    $lines = [];

    foreach ($rows as $row) {
        $lines[] = implode(
            ' | ',
            array_map(
                static fn($v) =>
                    (string)$v,
                $row
            )
        );
    }

    $pdf = makeSimplePdf(
        'Survey Results',
        $lines
    );

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="survey-' .
        rawurlencode($id) .
        '-responses.pdf"'
    );

    echo $pdf;
    exit;
}


// ============================================================
// GET結果メッセージ
// ============================================================

function renderResultMessage(): void
{
    $result = (string)get('result', '');

    if ($result === '') {
        return;
    }

    $message = match ($result) {
        'success' =>
            '接続確認に成功しました。',
        'synced' =>
            'kintoneから顧客データを同期しました。',
        'mail_sent' =>
            'テストメールを送信しました。',
        default =>
            '',
    };

    if ($message !== '') {
        echo '<div class="alert alert-success">' .
            h($message);

        if ($result === 'synced') {
            echo ' ' .
                h((string)get('count', 0)) .
                '件取得しました。';
        }

        echo '</div>';
    }
}


// ============================================================
// 起動
// ============================================================

ensureDataDir();

/*
 * 重要:
 *
 * 管理者ログイン判定を行わない。
 * 認証画面へのリダイレクトもしない。
 *
 * CSRFトークンだけはセッションへ保持する。
 */
csrfToken();

if (isPost()) {
    handlePost();
}

handleExport();

$screen = (string)get('screen', 'list');

switch ($screen) {

    case 'list':
        screenList();
        break;

    case 'edit':
        screenEdit();
        break;

    case 'preview':
        screenPreview();
        break;

    case 'send':
        screenSend();
        break;

    case 'analytics':
        screenAnalytics();
        break;

    case 'kintone':
        renderHeader(
            'kintone連携設定',
            'kintone'
        );
        echo '<main>';
        renderResultMessage();
        echo '</main>';
        renderFooter();

        /*
         * 結果表示後に設定画面本体を表示するため、
         * 改めて画面を出す。
         */
        screenKintone();
        break;

    case 'mail':
        renderHeader(
            'メールサーバ設定',
            'mail'
        );
        echo '<main>';
        renderResultMessage();
        echo '</main>';
        renderFooter();

        screenMail();
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

    default:
        screenList();
        break;
}
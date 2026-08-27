<?php

declare(strict_types=1);

/**
 * アンケートアプリ
 * 単一エントリーポイント
 *
 * PHP 8.5 / Apache 2.4
 * DBなし
 * PHP cURLなし
 *
 * kintone認証:
 *   X-Cybozu-Authorization: base64_encode(login:password)
 *
 * 注意:
 *   kintone認証情報・認証ヘッダーはブラウザへ返さない。
 */

date_default_timezone_set('Asia/Tokyo');

const APP_NAME = 'アンケートアプリ';
const APP_VERSION = '2.0.0';

const STATUS_DRAFT = 'draft';
const STATUS_PUBLISHED = 'published';
const STATUS_STOPPED = 'stopped';
const STATUS_ENDED = 'ended';

const QUESTION_SINGLE = 'single';
const QUESTION_MULTI = 'multi';
const QUESTION_TEXT = 'text';

const HTTP_CONNECT_TIMEOUT = 8;
const HTTP_READ_TIMEOUT = 20;
const HTTP_MAX_REDIRECTS = 3;

const DATA_DIR_NAME = '_data';
const SURVEY_FILE = 'surveys.json';
const ANSWER_FILE = 'answers.json';
const CUSTOMER_FILE = 'customers.json';
const MAIL_LOG_FILE = 'mail_logs.json';
const SETTINGS_FILE = 'settings.json';

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectTo(string $screen = 'list', array $params = []): never
{
    $params = array_merge(['screen' => $screen], $params);
    $url = basename($_SERVER['PHP_SELF'] ?? 'index.php') . '?' . http_build_query($params);
    header('Location: ' . $url, true, 303);
    exit;
}

function appBasePath(): string
{
    $path = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    if ($path === '/' || $path === '\\' || $path === '.') {
        return '/';
    }

    return rtrim(str_replace('\\', '/', $path), '/') . '/';
}

function ensureDataDirectory(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . DATA_DIR_NAME;

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('サーバー側データ保存領域を作成できません。');
        }
    }

    if (!is_writable($dir)) {
        throw new RuntimeException('サーバー側データ保存領域へ書き込めません。');
    }

    return $dir;
}

function dataPath(string $file): string
{
    return ensureDataDirectory() . DIRECTORY_SEPARATOR . $file;
}

function atomicWriteJson(string $file, array $data): void
{
    $path = dataPath($file);
    $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('データのJSON化に失敗しました。');
    }

    $fp = @fopen($tmp, 'wb');
    if ($fp === false) {
        throw new RuntimeException('一時保存ファイルを作成できません。');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('データ保存用ロックを取得できません。');
        }

        if (fwrite($fp, $json) === false) {
            throw new RuntimeException('データを書き込めません。');
        }

        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('データ保存を確定できません。');
    }

    @chmod($path, 0600);
}

function readJson(string $file, array $default = []): array
{
    $path = dataPath($file);

    if (!file_exists($path)) {
        atomicWriteJson($file, $default);
        return $default;
    }

    $fp = @fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException('保存データを開けません。');
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            throw new RuntimeException('保存データの読み取りロックを取得できません。');
        }

        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    if ($content === false || trim($content) === '') {
        return $default;
    }

    $decoded = json_decode($content, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('保存データの形式が壊れています。');
    }

    return $decoded;
}

function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (
        (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
        ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    );

    $path = appBasePath();

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $path,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name('survey_app_session');

    if (!session_start()) {
        throw new RuntimeException('セッションを開始できません。');
    }

    /*
     * 通常のGETでは session_regenerate_id() を行わない。
     * GET→POSTで同一セッションを維持するため。
     */
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function csrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('セッションが開始されていません。');
    }

    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $posted = (string)($_POST['csrf_token'] ?? '');
    $stored = (string)($_SESSION['csrf_token'] ?? '');

    if ($posted === '' || $stored === '' || !hash_equals($stored, $posted)) {
        throw new AppException('セッションエラー：不正なリクエストです。', 'session', 400);
    }
}

class AppException extends RuntimeException
{
    public string $type;

    public function __construct(
        string $message,
        string $type = 'system',
        int $code = 0
    ) {
        $this->type = $type;
        parent::__construct($message, $code);
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function getFlashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($items) ? $items : [];
}

function currentScreen(): string
{
    $screen = (string)($_GET['screen'] ?? 'list');

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

    return in_array($screen, $allowed, true) ? $screen : 'list';
}

function postAction(): string
{
    return (string)($_POST['action'] ?? '');
}

function nowIso(): string
{
    return date('Y-m-d H:i:s');
}

function newId(string $prefix): string
{
    return $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(5));
}

function defaultSurvey(): array
{
    return [
        'id' => newId('survey'),
        'title' => '',
        'description' => '',
        'startAt' => '',
        'endAt' => '',
        'status' => STATUS_DRAFT,
        'numbering' => 'global',
        'groups' => [
            [
                'id' => newId('group'),
                'title' => 'グループ1',
                'questions' => [
                    [
                        'id' => newId('question'),
                        'text' => '',
                        'type' => QUESTION_SINGLE,
                        'required' => false,
                        'options' => [
                            [
                                'id' => newId('option'),
                                'label' => '選択肢1',
                                'nextQuestionId' => '',
                            ],
                            [
                                'id' => newId('option'),
                                'label' => '選択肢2',
                                'nextQuestionId' => '',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'createdAt' => nowIso(),
        'updatedAt' => nowIso(),
    ];
}

function normalizeSurvey(array $survey): array
{
    $survey['id'] = (string)($survey['id'] ?? newId('survey'));
    $survey['title'] = (string)($survey['title'] ?? '');
    $survey['description'] = (string)($survey['description'] ?? '');
    $survey['startAt'] = (string)($survey['startAt'] ?? '');
    $survey['endAt'] = (string)($survey['endAt'] ?? '');
    $survey['status'] = (string)($survey['status'] ?? STATUS_DRAFT);
    $survey['numbering'] = (($survey['numbering'] ?? 'global') === 'group')
        ? 'group'
        : 'global';

    if (!in_array(
        $survey['status'],
        [STATUS_DRAFT, STATUS_PUBLISHED, STATUS_STOPPED, STATUS_ENDED],
        true
    )) {
        $survey['status'] = STATUS_DRAFT;
    }

    $survey['groups'] = is_array($survey['groups'] ?? null)
        ? $survey['groups']
        : [];

    foreach ($survey['groups'] as &$group) {
        $group['id'] = (string)($group['id'] ?? newId('group'));
        $group['title'] = (string)($group['title'] ?? '');
        $group['questions'] = is_array($group['questions'] ?? null)
            ? $group['questions']
            : [];

        foreach ($group['questions'] as &$question) {
            $question['id'] = (string)($question['id'] ?? newId('question'));
            $question['text'] = (string)($question['text'] ?? '');
            $question['type'] = in_array(
                $question['type'] ?? '',
                [QUESTION_SINGLE, QUESTION_MULTI, QUESTION_TEXT],
                true
            ) ? $question['type'] : QUESTION_SINGLE;

            $question['required'] = !empty($question['required']);

            $question['options'] = is_array($question['options'] ?? null)
                ? $question['options']
                : [];

            foreach ($question['options'] as &$option) {
                $option['id'] = (string)($option['id'] ?? newId('option'));
                $option['label'] = (string)($option['label'] ?? '');
                $option['nextQuestionId'] = (string)($option['nextQuestionId'] ?? '');
            }
            unset($option);
        }
        unset($question);
    }
    unset($group);

    return recalculateQuestionNumbers($survey);
}

function recalculateQuestionNumbers(array $survey): array
{
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            if ($survey['numbering'] === 'group') {
                $question['number'] = 'Q' . $groupNo . '-' . $questionNo;
            } else {
                $question['number'] = 'Q' . $global;
            }

            $global++;
            $questionNo++;
        }

        unset($question);
        $groupNo++;
    }

    unset($group);

    return $survey;
}

function applyAutomaticStatus(array $survey): array
{
    if (
        ($survey['status'] ?? '') === STATUS_PUBLISHED &&
        !empty($survey['endAt'])
    ) {
        $end = strtotime((string)$survey['endAt']);

        if ($end !== false && $end < time()) {
            $survey['status'] = STATUS_ENDED;
        }
    }

    return $survey;
}

function saveSurvey(array $survey): void
{
    $surveys = readJson(SURVEY_FILE, []);
    $survey = normalizeSurvey($survey);

    $found = false;

    foreach ($surveys as $index => $existing) {
        if ((string)($existing['id'] ?? '') === $survey['id']) {
            $survey['createdAt'] = $existing['createdAt'] ?? nowIso();
            $survey['updatedAt'] = nowIso();
            $surveys[$index] = $survey;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $survey['createdAt'] = $survey['createdAt'] ?? nowIso();
        $survey['updatedAt'] = nowIso();
        $surveys[] = $survey;
    }

    atomicWriteJson(SURVEY_FILE, array_values($surveys));
}

function getSurveys(): array
{
    $surveys = readJson(SURVEY_FILE, []);
    $changed = false;

    foreach ($surveys as $index => &$survey) {
        $normalized = normalizeSurvey($survey);
        $before = $normalized['status'];

        $normalized = applyAutomaticStatus($normalized);

        if ($normalized['status'] !== $before) {
            $changed = true;
        }

        $survey = $normalized;
    }
    unset($survey);

    if ($changed) {
        atomicWriteJson(SURVEY_FILE, $surveys);
    }

    return $surveys;
}

function getSurvey(string $id): ?array
{
    if ($id === '') {
        return null;
    }

    foreach (getSurveys() as $survey) {
        if ((string)$survey['id'] === $id) {
            return $survey;
        }
    }

    return null;
}

function validateSurvey(array $survey): array
{
    $errors = [];

    $title = trim((string)($survey['title'] ?? ''));

    if ($title === '') {
        $errors[] = 'アンケートタイトルは必須です。';
    } elseif (mb_strlen($title) > 200) {
        $errors[] = 'アンケートタイトルは200文字以内で入力してください。';
    }

    if (!empty($survey['startAt']) && strtotime((string)$survey['startAt']) === false) {
        $errors[] = '開始日時が正しくありません。';
    }

    if (!empty($survey['endAt']) && strtotime((string)$survey['endAt']) === false) {
        $errors[] = '終了日時が正しくありません。';
    }

    if (
        !empty($survey['startAt']) &&
        !empty($survey['endAt']) &&
        strtotime((string)$survey['startAt']) !== false &&
        strtotime((string)$survey['endAt']) !== false &&
        strtotime((string)$survey['startAt']) >= strtotime((string)$survey['endAt'])
    ) {
        $errors[] = '終了日時は開始日時より後にしてください。';
    }

    foreach ($survey['groups'] as $groupIndex => $group) {
        if (trim((string)($group['title'] ?? '')) === '') {
            $errors[] = 'グループ' . ($groupIndex + 1) . 'のタイトルを入力してください。';
        }

        foreach ($group['questions'] as $questionIndex => $question) {
            if (trim((string)($question['text'] ?? '')) === '') {
                $errors[] =
                    'グループ' .
                    ($groupIndex + 1) .
                    'の質問' .
                    ($questionIndex + 1) .
                    'の質問文を入力してください。';
            }

            $type = (string)($question['type'] ?? '');

            if (!in_array($type, [QUESTION_SINGLE, QUESTION_MULTI, QUESTION_TEXT], true)) {
                $errors[] = '不正な回答形式があります。';
            }

            if (in_array($type, [QUESTION_SINGLE, QUESTION_MULTI], true)) {
                if (count($question['options'] ?? []) === 0) {
                    $errors[] = '選択式質問には選択肢が必要です。';
                }
            }
        }
    }

    return $errors;
}

function surveyFromPost(): array
{
    $survey = [
        'id' => trim((string)($_POST['survey_id'] ?? '')),
        'title' => trim((string)($_POST['title'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
        'startAt' => trim((string)($_POST['start_at'] ?? '')),
        'endAt' => trim((string)($_POST['end_at'] ?? '')),
        'status' => trim((string)($_POST['status'] ?? STATUS_DRAFT)),
        'numbering' => trim((string)($_POST['numbering'] ?? 'global')),
        'groups' => [],
    ];

    if ($survey['id'] === '') {
        $survey['id'] = newId('survey');
        $survey['createdAt'] = nowIso();
    }

    $groupTitles = $_POST['group_title'] ?? [];
    $questionTexts = $_POST['question_text'] ?? [];
    $questionTypes = $_POST['question_type'] ?? [];
    $questionRequired = $_POST['question_required'] ?? [];
    $questionIds = $_POST['question_id'] ?? [];
    $optionLabels = $_POST['option_label'] ?? [];
    $optionNext = $_POST['option_next'] ?? [];

    if (!is_array($groupTitles)) {
        $groupTitles = [];
    }

    if (!is_array($questionTexts)) {
        $questionTexts = [];
    }

    if (!is_array($questionTypes)) {
        $questionTypes = [];
    }

    if (!is_array($questionRequired)) {
        $questionRequired = [];
    }

    if (!is_array($questionIds)) {
        $questionIds = [];
    }

    if (!is_array($optionLabels)) {
        $optionLabels = [];
    }

    if (!is_array($optionNext)) {
        $optionNext = [];
    }

    foreach ($groupTitles as $groupIndex => $groupTitle) {
        $groupId = (string)($_POST['group_id'][$groupIndex] ?? newId('group'));

        $group = [
            'id' => $groupId !== '' ? $groupId : newId('group'),
            'title' => trim((string)$groupTitle),
            'questions' => [],
        ];

        $texts = is_array($questionTexts[$groupIndex] ?? null)
            ? $questionTexts[$groupIndex]
            : [];

        $types = is_array($questionTypes[$groupIndex] ?? null)
            ? $questionTypes[$groupIndex]
            : [];

        $requireds = is_array($questionRequired[$groupIndex] ?? null)
            ? $questionRequired[$groupIndex]
            : [];

        $ids = is_array($questionIds[$groupIndex] ?? null)
            ? $questionIds[$groupIndex]
            : [];

        $labels = is_array($optionLabels[$groupIndex] ?? null)
            ? $optionLabels[$groupIndex]
            : [];

        $nexts = is_array($optionNext[$groupIndex] ?? null)
            ? $optionNext[$groupIndex]
            : [];

        $questionCount = max(
            count($texts),
            count($types),
            count($ids)
        );

        for ($q = 0; $q < $questionCount; $q++) {
            $qid = (string)($ids[$q] ?? newId('question'));
            $type = (string)($types[$q] ?? QUESTION_SINGLE);

            if (!in_array($type, [QUESTION_SINGLE, QUESTION_MULTI, QUESTION_TEXT], true)) {
                $type = QUESTION_SINGLE;
            }

            $question = [
                'id' => $qid !== '' ? $qid : newId('question'),
                'text' => trim((string)($texts[$q] ?? '')),
                'type' => $type,
                'required' => isset($requireds[$q]),
                'options' => [],
            ];

            $qLabels = is_array($labels[$q] ?? null) ? $labels[$q] : [];
            $qNexts = is_array($nexts[$q] ?? null) ? $nexts[$q] : [];

            foreach ($qLabels as $optionIndex => $label) {
                $optionLabel = trim((string)$label);

                if ($optionLabel === '') {
                    continue;
                }

                $question['options'][] = [
                    'id' => newId('option'),
                    'label' => $optionLabel,
                    'nextQuestionId' => trim((string)($qNexts[$optionIndex] ?? '')),
                ];
            }

            $group['questions'][] = $question;
        }

        $survey['groups'][] = $group;
    }

    return normalizeSurvey($survey);
}

function questionCount(array $survey): int
{
    $count = 0;

    foreach ($survey['groups'] as $group) {
        $count += count($group['questions'] ?? []);
    }

    return $count;
}

function answerCount(string $surveyId): int
{
    $answers = readJson(ANSWER_FILE, []);

    $count = 0;

    foreach ($answers as $answer) {
        if ((string)($answer['surveyId'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}

function getSettings(): array
{
    return readJson(SETTINGS_FILE, [
        'kintone' => [
            'subdomain' => '',
            'app_id' => '',
            'login' => '',
            'password' => '',
            'proxy' => '',
            'verify_ssl' => false,
            'field_map' => [
                'organization' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
        ],
        'mail' => [
            'smtp_server' => '',
            'smtp_port' => 587,
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

function saveSettings(array $settings): void
{
    atomicWriteJson(SETTINGS_FILE, $settings);
}

function normalizeKintoneSubdomain(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (!preg_match('~^https?://~i', $value)) {
        $value = 'https://' . $value;
    }

    $parts = parse_url($value);

    if ($parts === false || empty($parts['host'])) {
        throw new AppException(
            'kintoneサブドメインの形式が正しくありません。',
            'input'
        );
    }

    $host = strtolower((string)$parts['host']);

    if (!preg_match('/^[a-z0-9][a-z0-9-]*\.cybozu\.com$/i', $host)) {
        throw new AppException(
            'kintoneサブドメインは「xxxx.cybozu.com」形式で指定してください。',
            'input'
        );
    }

    return 'https://' . $host;
}

function kintoneConfig(bool $requireCredentials = true): array
{
    $settings = getSettings();
    $config = $settings['kintone'] ?? [];

    $baseUrl = normalizeKintoneSubdomain(
        (string)($config['subdomain'] ?? '')
    );

    $appId = trim((string)($config['app_id'] ?? ''));
    $login = (string)($config['login'] ?? '');
    $password = (string)($config['password'] ?? '');
    $proxy = trim((string)($config['proxy'] ?? ''));
    $verifySsl = !empty($config['verify_ssl']);

    if ($baseUrl === '') {
        throw new AppException(
            'kintoneサブドメインが設定されていません。',
            'config'
        );
    }

    if ($appId === '' || !ctype_digit($appId) || (int)$appId < 1) {
        throw new AppException(
            'kintone顧客管理アプリIDが正しく設定されていません。',
            'config'
        );
    }

    if ($requireCredentials) {
        if ($login === '') {
            throw new AppException(
                'kintoneログイン名が設定されていません。',
                'config'
            );
        }

        if ($password === '') {
            throw new AppException(
                'kintoneパスワードが設定されていません。',
                'config'
            );
        }
    }

    return [
        'baseUrl' => $baseUrl,
        'appId' => (int)$appId,
        'login' => $login,
        'password' => $password,
        'proxy' => $proxy,
        'verifySsl' => $verifySsl,
    ];
}

function validateProxy(string $proxy): string
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return '';
    }

    if (!preg_match('/^[^:\s]+:\d{1,5}$/', $proxy)) {
        throw new AppException(
            'Proxyは「host:port」形式で入力してください。',
            'input'
        );
    }

    [$host, $port] = explode(':', $proxy, 2);

    $portNumber = (int)$port;

    if ($portNumber < 1 || $portNumber > 65535) {
        throw new AppException(
            'Proxyのポート番号が正しくありません。',
            'input'
        );
    }

    return $host . ':' . $portNumber;
}

/**
 * kintone REST API通信
 *
 * PHP cURLは使用しない。
 *
 * X-Cybozu-Authorization:
 *     base64_encode(login . ':' . password)
 *
 * ここで「Basic 」等は付けない。
 */
function kintoneRequest(
    string $method,
    string $path,
    array $query = [],
    ?array $body = null
): array {
    $config = kintoneConfig(true);

    $method = strtoupper($method);

    if ($path === '' || $path[0] !== '/') {
        throw new AppException(
            'kintone APIパスが不正です。',
            'system'
        );
    }

    $url = $config['baseUrl'] . $path;

    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /*
     * 認証情報はサーバー側だけで生成する。
     *
     * 重要:
     * X-Cybozu-Authorization の値は
     * base64(login:password)
     * であり、Basic認証の文字列ではない。
     */
    $authorizationValue = base64_encode(
        $config['login'] . ':' . $config['password']
    );

    if ($authorizationValue === false || $authorizationValue === '') {
        throw new AppException(
            'kintone認証情報を生成できません。',
            'authentication'
        );
    }

    $headers = [
        'Accept: application/json',
        'X-Cybozu-Authorization: ' . $authorizationValue,
        'User-Agent: SurveyApp/' . APP_VERSION,
    ];

    $content = null;

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($content === false) {
            throw new AppException(
                'kintoneリクエストデータを作成できません。',
                'system'
            );
        }

        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($content);
    }

    $httpOptions = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => HTTP_READ_TIMEOUT,
        'protocol_version' => 1.1,
        'max_redirects' => HTTP_MAX_REDIRECTS,
        'follow_location' => true,
    ];

    if ($content !== null) {
        $httpOptions['content'] = $content;
    }

    if ($config['proxy'] !== '') {
        $proxy = validateProxy($config['proxy']);
        $httpOptions['proxy'] = $proxy;
        $httpOptions['request_fulluri'] = true;
    }

    $sslOptions = [
        'verify_peer' => $config['verifySsl'],
        'verify_peer_name' => $config['verifySsl'],
        'allow_self_signed' => !$config['verifySsl'],
        'SNI_enabled' => true,
    ];

    $context = stream_context_create([
        'http' => $httpOptions,
        'ssl' => $sslOptions,
    ]);

    $errorMessage = '';
    $errorNumber = 0;

    set_error_handler(
        static function (
            int $severity,
            string $message,
            string $file,
            int $line
        ) use (&$errorMessage, &$errorNumber): bool {
            $errorMessage = $message;
            $errorNumber = $severity;
            return true;
        }
    );

    try {
        $response = file_get_contents(
            $url,
            false,
            $context
        );
    } finally {
        restore_error_handler();
    }

    $statusCode = 0;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match(
                '/^HTTP\/\S+\s+(\d{3})/',
                $headerLine,
                $matches
            )) {
                $statusCode = (int)$matches[1];
            }
        }
    }

    if ($response === false) {
        $safeReason = 'kintoneへの通信に失敗しました。';

        if ($errorMessage !== '') {
            $lower = strtolower($errorMessage);

            if (
                str_contains($lower, 'timed out') ||
                str_contains($lower, 'timeout')
            ) {
                $safeReason = 'kintoneへの通信がタイムアウトしました。';
            } elseif (
                str_contains($lower, 'certificate') ||
                str_contains($lower, 'ssl')
            ) {
                $safeReason =
                    'SSL証明書の検証に失敗しました。' .
                    '必要に応じてSSL証明書検証設定を確認してください。';
            } elseif (
                str_contains($lower, 'proxy')
            ) {
                $safeReason = 'Proxy経由の通信に失敗しました。';
            }
        }

        throw new AppException(
            $safeReason,
            'network'
        );
    }

    $decoded = json_decode($response, true);

    if ($statusCode >= 200 && $statusCode < 300) {
        return [
            'status' => $statusCode,
            'body' => is_array($decoded) ? $decoded : [],
        ];
    }

    $apiMessage = '';

    if (is_array($decoded)) {
        $apiMessage = (string)($decoded['message'] ?? '');
    }

    /*
     * kintoneから返った認証エラーは、
     * パスワードやAuthorizationヘッダーを表示せず、
     * ユーザーが修正可能な情報だけを表示する。
     */
    if ($statusCode === 401 || $statusCode === 403) {
        throw new AppException(
            'kintone認証に失敗しました。' .
            'ログイン名・パスワード・接続先サブドメインを確認してください。' .
            ($apiMessage !== '' ? ' kintone: ' . $apiMessage : ''),
            'authentication',
            $statusCode
        );
    }

    if ($statusCode === 400) {
        throw new AppException(
            'kintoneへのリクエストが不正です。' .
            ($apiMessage !== '' ? ' kintone: ' . $apiMessage : ''),
            'external',
            $statusCode
        );
    }

    if ($statusCode >= 500) {
        throw new AppException(
            'kintone側でサーバーエラーが発生しました。' .
            ($apiMessage !== '' ? ' kintone: ' . $apiMessage : ''),
            'external',
            $statusCode
        );
    }

    throw new AppException(
        'kintoneとの通信に失敗しました。' .
        ($apiMessage !== '' ? ' kintone: ' . $apiMessage : ''),
        'external',
        $statusCode
    );
}

function kintoneConnectionTest(): array
{
    return kintoneRequest(
        'GET',
        '/k/v1/app.json',
        [
            'app' => kintoneConfig(true)['appId'],
        ]
    );
}

function kintoneGetFields(): array
{
    $config = kintoneConfig(true);

    return kintoneRequest(
        'GET',
        '/k/v1/app/form/fields.json',
        [
            'app' => $config['appId'],
        ]
    );
}

function kintoneGetRecords(int $offset = 0, int $limit = 500): array
{
    $config = kintoneConfig(true);

    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);

    return kintoneRequest(
        'GET',
        '/k/v1/records.json',
        [
            'app' => $config['appId'],
            'query' => 'limit ' . $limit . ' offset ' . $offset,
            'totalCount' => 'true',
        ]
    );
}

function mapKintoneFieldValue(array $record, string $fieldCode): string
{
    if ($fieldCode === '' || !isset($record[$fieldCode])) {
        return '';
    }

    $field = $record[$fieldCode];

    if (!is_array($field)) {
        return '';
    }

    $value = $field['value'] ?? '';

    if (is_array($value)) {
        $values = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $values[] = (string)($item['name'] ?? $item['code'] ?? '');
            } else {
                $values[] = (string)$item;
            }
        }

        return implode(', ', array_filter($values, static function ($v) {
            return $v !== '';
        }));
    }

    return (string)$value;
}

function syncKintoneCustomers(): int
{
    $settings = getSettings();
    $map = $settings['kintone']['field_map'] ?? [];

    $organizationCode = (string)($map['organization'] ?? '');
    $nameCode = (string)($map['name'] ?? '');
    $emailCode = (string)($map['email'] ?? '');
    $departmentCode = (string)($map['department'] ?? '');
    $phoneCode = (string)($map['phone'] ?? '');

    $addressCodes = is_array($map['address'] ?? null)
        ? $map['address']
        : [];

    $allRecords = [];
    $offset = 0;

    while (true) {
        $result = kintoneGetRecords($offset, 500);
        $records = $result['body']['records'] ?? [];

        if (!is_array($records) || count($records) === 0) {
            break;
        }

        foreach ($records as $record) {
            if (is_array($record)) {
                $allRecords[] = $record;
            }
        }

        if (count($records) < 500) {
            break;
        }

        $offset += 500;

        if ($offset > 100000) {
            throw new AppException(
                '顧客情報が多すぎるため同期を中断しました。',
                'external'
            );
        }
    }

    $customers = [];

    foreach ($allRecords as $record) {
        $addressParts = [];

        foreach ($addressCodes as $code) {
            $code = (string)$code;

            if ($code === '') {
                continue;
            }

            $value = mapKintoneFieldValue($record, $code);

            if ($value !== '') {
                $addressParts[] = $value;
            }
        }

        $recordId = mapKintoneFieldValue($record, '$id');

        if ($recordId === '') {
            $recordId = mapKintoneFieldValue($record, 'レコード番号');
        }

        if ($recordId === '') {
            $recordId = newId('krecord');
        }

        $customers[] = [
            'id' => 'kintone-' . $recordId,
            'kintoneRecordId' => $recordId,
            'organization' => mapKintoneFieldValue(
                $record,
                $organizationCode
            ),
            'name' => mapKintoneFieldValue(
                $record,
                $nameCode
            ),
            'email' => mapKintoneFieldValue(
                $record,
                $emailCode
            ),
            'department' => mapKintoneFieldValue(
                $record,
                $departmentCode
            ),
            'phone' => mapKintoneFieldValue(
                $record,
                $phoneCode
            ),
            'address' => implode(' ', $addressParts),
            'raw' => $record,
            'syncedAt' => nowIso(),
        ];
    }

    atomicWriteJson(CUSTOMER_FILE, $customers);

    return count($customers);
}

function getCustomers(): array
{
    return readJson(CUSTOMER_FILE, []);
}

function saveAnswer(
    string $surveyId,
    array $answers
): string {
    $survey = getSurvey($surveyId);

    if ($survey === null) {
        throw new AppException(
            'アンケートが存在しません。',
            'data'
        );
    }

    $answerId = newId('answer');

    $data = readJson(ANSWER_FILE, []);

    $data[] = [
        'id' => $answerId,
        'surveyId' => $surveyId,
        'answers' => $answers,
        'createdAt' => nowIso(),
    ];

    atomicWriteJson(ANSWER_FILE, $data);

    return $answerId;
}

function getAnswersForSurvey(string $surveyId): array
{
    $all = readJson(ANSWER_FILE, []);

    return array_values(array_filter(
        $all,
        static function (array $answer) use ($surveyId): bool {
            return (string)($answer['surveyId'] ?? '') === $surveyId;
        }
    ));
}

function customerMatches(array $customer, string $keyword): bool
{
    if ($keyword === '') {
        return true;
    }

    $haystack = implode(' ', [
        $customer['organization'] ?? '',
        $customer['name'] ?? '',
        $customer['email'] ?? '',
        $customer['department'] ?? '',
        $customer['phone'] ?? '',
        $customer['address'] ?? '',
    ]);

    return mb_stripos($haystack, $keyword) !== false;
}

function mailSettings(): array
{
    $settings = getSettings();

    return $settings['mail'] ?? [];
}

function smtpValidateSettings(array $mail): void
{
    $server = trim((string)($mail['smtp_server'] ?? ''));
    $port = (int)($mail['smtp_port'] ?? 0);
    $from = trim((string)($mail['from_email'] ?? ''));

    if ($server === '') {
        throw new AppException(
            'SMTPサーバが設定されていません。',
            'config'
        );
    }

    if ($port < 1 || $port > 65535) {
        throw new AppException(
            'SMTPポートが正しくありません。',
            'config'
        );
    }

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new AppException(
            '送信元メールアドレスが正しくありません。',
            'config'
        );
    }
}

/**
 * SMTP送信。
 *
 * PHP mail()は使用しない。
 */
function smtpSend(
    array $mail,
    string $to,
    string $subject,
    string $body
): void {
    smtpValidateSettings($mail);

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new AppException(
            '送信先メールアドレスが正しくありません。',
            'input'
        );
    }

    $server = trim((string)$mail['smtp_server']);
    $port = (int)$mail['smtp_port'];
    $encryption = strtolower((string)($mail['encryption'] ?? 'tls'));
    $username = (string)($mail['username'] ?? '');
    $password = (string)($mail['password'] ?? '');
    $auth = !empty($mail['auth']);

    $transportHost = $server;

    if ($encryption === 'ssl') {
        $transportHost = 'ssl://' . $server;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $transportHost . ':' . $port,
        $errno,
        $errstr,
        HTTP_READ_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new AppException(
            'SMTPサーバへ接続できませんでした。',
            'network'
        );
    }

    stream_set_timeout($socket, HTTP_READ_TIMEOUT);

    try {
        smtpExpect($socket, [220]);

        smtpCommand($socket, 'EHLO localhost', [250]);

        if ($encryption === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220]);

            $crypto = stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new AppException(
                    'SMTP TLS接続を確立できませんでした。',
                    'network'
                );
            }

            smtpCommand($socket, 'EHLO localhost', [250]);
        }

        if ($auth) {
            if ($username === '' || $password === '') {
                throw new AppException(
                    'SMTP認証情報が設定されていません。',
                    'config'
                );
            }

            smtpCommand($socket, 'AUTH LOGIN', [334]);
            smtpCommand($socket, base64_encode($username), [334]);
            smtpCommand($socket, base64_encode($password), [235]);
        }

        $from = trim((string)$mail['from_email']);
        $fromName = trim((string)($mail['from_name'] ?? ''));
        $replyTo = trim((string)($mail['reply_to'] ?? ''));

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

        smtpCommand($socket, 'DATA', [354]);

        $headers = [];
        $headers[] = 'From: ' . encodeMailAddress($fromName, $from);
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: ' . encodeMailHeader($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        if ($replyTo !== '') {
            if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $headers[] = 'Reply-To: <' . $replyTo . '>';
            }
        }

        $safeBody = str_replace(
            ["\r\n", "\r"],
            "\n",
            $body
        );

        $safeBody = str_replace(
            "\n.",
            "\n..",
            $safeBody
        );

        $message =
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            str_replace("\n", "\r\n", $safeBody) .
            "\r\n.";

        smtpCommand($socket, $message, [250]);

        smtpCommand($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function smtpRead($socket): array
{
    $lines = [];

    while (($line = fgets($socket, 8192)) !== false) {
        $lines[] = rtrim($line, "\r\n");

        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    return $lines;
}

function smtpExpect($socket, array $expected): void
{
    $lines = smtpRead($socket);

    if ($lines === []) {
        throw new AppException(
            'SMTPサーバから応答がありません。',
            'network'
        );
    }

    $last = end($lines);

    if (!preg_match('/^(\d{3})/', (string)$last, $m)) {
        throw new AppException(
            'SMTPサーバの応答を解釈できません。',
            'network'
        );
    }

    $code = (int)$m[1];

    if (!in_array($code, $expected, true)) {
        throw new AppException(
            'SMTP通信でエラーが発生しました。',
            'external'
        );
    }
}

function smtpCommand($socket, string $command, array $expected): void
{
    fwrite($socket, $command . "\r\n");
    smtpExpect($socket, $expected);
}

function encodeMailHeader(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function encodeMailAddress(string $name, string $email): string
{
    if ($name === '') {
        return '<' . $email . '>';
    }

    return encodeMailHeader($name) . ' <' . $email . '>';
}

function replaceMailVariables(
    string $text,
    array $customer,
    string $surveyUrl
): string {
    return str_replace(
        [
            '{顧客名}',
            '{アンケートURL}',
        ],
        [
            (string)($customer['name'] ?? ''),
            $surveyUrl,
        ],
        $text
    );
}

function publicSurveyUrl(string $surveyId): string
{
    $scheme = (
        (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
        ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    ) ? 'https' : 'http';

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');

    if ($host === '') {
        return '';
    }

    return $scheme .
        '://' .
        $host .
        appBasePath() .
        'index.php?screen=answer&id=' .
        rawurlencode($surveyId);
}

function processPost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    verifyCsrf();

    $action = postAction();

    switch ($action) {
        case 'save_survey':
            processSaveSurvey();
            return;

        case 'delete_survey':
            processDeleteSurvey();
            return;

        case 'duplicate_survey':
            processDuplicateSurvey();
            return;

        case 'change_status':
            processChangeStatus();
            return;

        case 'add_group':
            processAddGroup();
            return;

        case 'add_question':
            processAddQuestion();
            return;

        case 'delete_group':
            processDeleteGroup();
            return;

        case 'delete_question':
            processDeleteQuestion();
            return;

        case 'save_kintone':
            processSaveKintone();
            return;

        case 'test_kintone':
            processTestKintone();
            return;

        case 'get_kintone_fields':
            processGetKintoneFields();
            return;

        case 'sync_kintone':
            processSyncKintone();
            return;

        case 'save_mail':
            processSaveMail();
            return;

        case 'test_mail':
            processTestMail();
            return;

        case 'send_mail':
            processSendMail();
            return;

        case 'submit_answer':
            processSubmitAnswer();
            return;

        default:
            throw new AppException(
                '不正な操作です。',
                'input',
                400
            );
    }
}

function processSaveSurvey(): void
{
    $survey = surveyFromPost();

    $existing = getSurvey($survey['id']);

    if ($existing !== null) {
        if ($existing['status'] === STATUS_ENDED) {
            $survey['status'] = STATUS_ENDED;
        } else {
            $survey['status'] = $existing['status'];
        }

        $survey['createdAt'] = $existing['createdAt'];
    } else {
        $survey['status'] = STATUS_DRAFT;
    }

    $errors = validateSurvey($survey);

    if ($errors !== []) {
        $_SESSION['edit_errors'] = $errors;
        $_SESSION['edit_old'] = $survey;

        redirectTo(
            'edit',
            ['id' => $survey['id']]
        );
    }

    saveSurvey($survey);

    flash('success', 'アンケートを保存しました。');

    redirectTo('list');
}

function processDeleteSurvey(): void
{
    $id = trim((string)($_POST['id'] ?? ''));

    if ($id === '') {
        throw new AppException(
            '削除対象が指定されていません。',
            'input'
        );
    }

    $surveys = getSurveys();

    $new = [];

    foreach ($surveys as $survey) {
        if ((string)$survey['id'] !== $id) {
            $new[] = $survey;
        }
    }

    atomicWriteJson(SURVEY_FILE, $new);

    flash('success', 'アンケートを削除しました。');

    redirectTo('list');
}

function processDuplicateSurvey(): void
{
    $id = trim((string)($_POST['id'] ?? ''));

    $survey = getSurvey($id);

    if ($survey === null) {
        throw new AppException(
            '複製対象のアンケートが存在しません。',
            'data'
        );
    }

    $copy = $survey;
    $copy['id'] = newId('survey');
    $copy['title'] = $survey['title'] . '（複製）';
    $copy['status'] = STATUS_DRAFT;
    $copy['createdAt'] = nowIso();
    $copy['updatedAt'] = nowIso();

    foreach ($copy['groups'] as &$group) {
        $group['id'] = newId('group');

        foreach ($group['questions'] as &$question) {
            $question['id'] = newId('question');

            foreach ($question['options'] as &$option) {
                $option['id'] = newId('option');
            }
            unset($option);
        }
        unset($question);
    }
    unset($group);

    saveSurvey($copy);

    flash('success', 'アンケートを複製しました。');

    redirectTo('list');
}

function processChangeStatus(): void
{
    $id = trim((string)($_POST['id'] ?? ''));
    $newStatus = trim((string)($_POST['new_status'] ?? ''));

    $survey = getSurvey($id);

    if ($survey === null) {
        throw new AppException(
            'アンケートが存在しません。',
            'data'
        );
    }

    if ($survey['status'] === STATUS_ENDED) {
        throw new AppException(
            '終了したアンケートの状態は変更できません。',
            'input'
        );
    }

    $allowed = [
        STATUS_DRAFT => [STATUS_PUBLISHED],
        STATUS_PUBLISHED => [STATUS_STOPPED],
        STATUS_STOPPED => [STATUS_PUBLISHED],
    ];

    if (
        !isset($allowed[$survey['status']]) ||
        !in_array($newStatus, $allowed[$survey['status']], true)
    ) {
        throw new AppException(
            '指定された状態変更は許可されていません。',
            'input'
        );
    }

    $survey['status'] = $newStatus;

    saveSurvey($survey);

    flash('success', 'アンケートの状態を変更しました。');

    redirectTo('list');
}

function processAddGroup(): void
{
    $id = trim((string)($_POST['id'] ?? ''));

    $survey = getSurvey($id);

    if ($survey === null) {
        throw new AppException(
            'アンケートが存在しません。',
            'data'
        );
    }

    $survey['groups'][] = [
        'id' => newId('group'),
        'title' => '新しいグループ',
        'questions' => [],
    ];

    $survey = recalculateQuestionNumbers($survey);

    saveSurvey($survey);

    redirectTo('edit', ['id' => $id]);
}

function processAddQuestion(): void
{
    $id = trim((string)($_POST['id'] ?? ''));
    $groupId = trim((string)($_POST['group_id'] ?? ''));

    $survey = getSurvey($id);

    if ($survey === null) {
        throw new AppException(
            'アンケートが存在しません。',
            'data'
        );
    }

    foreach ($survey['groups'] as &$group) {
        if ((string)$group['id'] === $groupId) {
            $group['questions'][] = [
                'id' => newId('question'),
                'text' => '',
                'type' => QUESTION_SINGLE,
                'required' => false,
                'options' => [
                    [
                        'id' => newId('option'),
                        'label' => '選択肢1',
                        'nextQuestionId' => '',
                    ],
                    [
                        'id' => newId('option'),
                        'label' => '選択肢2',
                        'nextQuestionId' => '',
                    ],
                ],
            ];

            break;
        }
    }
    unset($group);

    $survey = recalculateQuestionNumbers($survey);

    saveSurvey($survey);

    redirectTo('edit', ['id' => $id]);
}

function processDeleteGroup(): void
{
    $id = trim((string)($_POST['id'] ?? ''));
    $groupId = trim((string)($_POST['group_id'] ?? ''));

    $survey = getSurvey($id);

    if ($survey === null) {
        throw new AppException(
            'アンケートが存在しません。',
            'data'
        );
    }

    $survey['groups'] = array_values(array_filter(
        $survey['groups'],
        static function (array $group) use ($groupId): bool {
            return (string)$group['id'] !== $groupId;
        }
    ));

    if ($survey['groups'] === []) {
        $survey['groups'][] = [
            'id' => newId('group'),
            'title' => 'グループ1',
            'questions' => [],
        ];
    }

    $survey = recalculateQuestionNumbers($survey);

    saveSurvey($survey);

    redirectTo('edit', ['id' => $id]);
}

function processDeleteQuestion(): void
{
    $id = trim((string)($_POST['id'] ?? ''));
    $groupId = trim((string)($_POST['group_id'] ?? ''));
    $questionId = trim((string)($_POST['question_id'] ?? ''));

    $survey = getSurvey($id);

    if ($survey === null) {
        throw new AppException(
            'アンケートが存在しません。',
            'data'
        );
    }

    foreach ($survey['groups'] as &$group) {
        if ((string)$group['id'] !== $groupId) {
            continue;
        }

        $group['questions'] = array_values(array_filter(
            $group['questions'],
            static function (array $question) use ($questionId): bool {
                return (string)$question['id'] !== $questionId;
            }
        ));

        break;
    }
    unset($group);

    $survey = recalculateQuestionNumbers($survey);

    saveSurvey($survey);

    redirectTo('edit', ['id' => $id]);
}

function processSaveKintone(): void
{
    $settings = getSettings();

    $subdomain = trim((string)($_POST['kintone_subdomain'] ?? ''));
    $appId = trim((string)($_POST['kintone_app_id'] ?? ''));
    $login = trim((string)($_POST['kintone_login'] ?? ''));
    $password = (string)($_POST['kintone_password'] ?? '');
    $proxy = trim((string)($_POST['kintone_proxy'] ?? ''));
    $verifySsl = isset($_POST['kintone_verify_ssl']);

    normalizeKintoneSubdomain($subdomain);

    if ($appId === '' || !ctype_digit($appId) || (int)$appId < 1) {
        throw new AppException(
            '顧客管理アプリIDを正しく入力してください。',
            'input'
        );
    }

    if ($login === '') {
        throw new AppException(
            'ログイン名を入力してください。',
            'input'
        );
    }

    if ($password === '') {
        /*
         * 既存パスワードを保持する場合。
         * ブラウザには既存値を返していないため、
         * 空欄は「変更なし」とする。
         */
        $password = (string)($settings['kintone']['password'] ?? '');

        if ($password === '') {
            throw new AppException(
                'パスワードを入力してください。',
                'input'
            );
        }
    }

    validateProxy($proxy);

    $settings['kintone']['subdomain'] = $subdomain;
    $settings['kintone']['app_id'] = $appId;
    $settings['kintone']['login'] = $login;
    $settings['kintone']['password'] = $password;
    $settings['kintone']['proxy'] = $proxy;
    $settings['kintone']['verify_ssl'] = $verifySsl;

    $settings['kintone']['field_map'] = [
        'organization' => trim(
            (string)($_POST['field_organization'] ?? '')
        ),
        'name' => trim(
            (string)($_POST['field_name'] ?? '')
        ),
        'email' => trim(
            (string)($_POST['field_email'] ?? '')
        ),
        'department' => trim(
            (string)($_POST['field_department'] ?? '')
        ),
        'phone' => trim(
            (string)($_POST['field_phone'] ?? '')
        ),
        'address' => array_values(array_filter(
            is_array($_POST['field_address'] ?? null)
                ? $_POST['field_address']
                : [],
            static function ($v): bool {
                return trim((string)$v) !== '';
            }
        )),
    ];

    saveSettings($settings);

    flash('success', 'kintone設定を保存しました。');

    redirectTo('kintone');
}

function processTestKintone(): void
{
    /*
     * 接続テストは保存済み設定を使用。
     * 同期処理は実行しない。
     */
    $result = kintoneConnectionTest();

    $name = (string)($result['body']['name'] ?? '');

    if ($name !== '') {
        flash(
            'success',
            'kintone接続成功：アプリ「' . $name . '」へ接続できました。'
        );
    } else {
        flash(
            'success',
            'kintone接続成功。'
        );
    }

    redirectTo('kintone');
}

function processGetKintoneFields(): void
{
    $result = kintoneGetFields();

    $properties = $result['body']['properties'] ?? [];

    if (!is_array($properties)) {
        $properties = [];
    }

    $_SESSION['kintone_fields'] = $properties;

    flash(
        'success',
        'kintoneの項目一覧を取得しました。'
    );

    redirectTo('kintone');
}

function processSyncKintone(): void
{
    /*
     * 接続テストとは別操作。
     * ここでは実際にrecords APIを実行する。
     */
    $count = syncKintoneCustomers();

    flash(
        'success',
        $count . '件の顧客情報を同期しました。'
    );

    redirectTo('kintone');
}

function processSaveMail(): void
{
    $settings = getSettings();

    $smtpServer = trim((string)($_POST['smtp_server'] ?? ''));
    $smtpPort = (int)($_POST['smtp_port'] ?? 0);
    $encryption = strtolower(
        trim((string)($_POST['smtp_encryption'] ?? 'none'))
    );

    if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
        throw new AppException(
            'SMTP暗号化方式が不正です。',
            'input'
        );
    }

    $username = trim((string)($_POST['smtp_username'] ?? ''));
    $password = (string)($_POST['smtp_password'] ?? '');
    $fromEmail = trim((string)($_POST['from_email'] ?? ''));
    $fromName = trim((string)($_POST['from_name'] ?? ''));
    $replyTo = trim((string)($_POST['reply_to'] ?? ''));

    if ($smtpServer === '') {
        throw new AppException(
            'SMTPサーバを入力してください。',
            'input'
        );
    }

    if ($smtpPort < 1 || $smtpPort > 65535) {
        throw new AppException(
            'SMTPポートが正しくありません。',
            'input'
        );
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new AppException(
            '送信元メールアドレスが正しくありません。',
            'input'
        );
    }

    if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        throw new AppException(
            '返信先メールアドレスが正しくありません。',
            'input'
        );
    }

    if ($password === '') {
        $password = (string)($settings['mail']['password'] ?? '');
    }

    $settings['mail'] = [
        'smtp_server' => $smtpServer,
        'smtp_port' => $smtpPort,
        'encryption' => $encryption,
        'auth' => isset($_POST['smtp_auth']),
        'username' => $username,
        'password' => $password,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'reply_to' => $replyTo,
    ];

    saveSettings($settings);

    flash('success', 'メールサーバ設定を保存しました。');

    redirectTo('mail');
}

function processTestMail(): void
{
    $mail = mailSettings();

    smtpValidateSettings($mail);

    $to = trim((string)($mail['from_email'] ?? ''));

    smtpSend(
        $mail,
        $to,
        'アンケートアプリ SMTP接続テスト',
        "SMTP接続テストです。\n\nこのメールはアンケートアプリから送信されました。"
    );

    flash(
        'success',
        'テストメールを送信しました。'
    );

    redirectTo('mail');
}

function processSendMail(): void
{
    $surveyId = trim((string)($_POST['survey_id'] ?? ''));

    $survey = getSurvey($surveyId);

    if ($survey === null) {
        throw new AppException(
            '対象アンケートが存在しません。',
            'data'
        );
    }

    $selected = $_POST['customer_id'] ?? [];

    if (!is_array($selected) || $selected === []) {
        throw new AppException(
            '送信対象の顧客を選択してください。',
            'input'
        );
    }

    $subject = trim((string)($_POST['mail_subject'] ?? ''));
    $body = (string)($_POST['mail_body'] ?? '');

    if ($subject === '') {
        throw new AppException(
            'メール件名を入力してください。',
            'input'
        );
    }

    if ($body === '') {
        throw new AppException(
            'メール本文を入力してください。',
            'input'
        );
    }

    $customers = getCustomers();
    $byId = [];

    foreach ($customers as $customer) {
        $byId[(string)$customer['id']] = $customer;
    }

    $mail = mailSettings();
    $surveyUrl = publicSurveyUrl($surveyId);

    $logs = readJson(MAIL_LOG_FILE, []);
    $sent = 0;
    $failed = 0;

    foreach ($selected as $customerId) {
        $customerId = (string)$customerId;

        if (!isset($byId[$customerId])) {
            $failed++;
            continue;
        }

        $customer = $byId[$customerId];
        $to = trim((string)($customer['email'] ?? ''));

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $failed++;

            $logs[] = [
                'id' => newId('mail'),
                'surveyId' => $surveyId,
                'customerId' => $customerId,
                'email' => $to,
                'status' => 'failed',
                'message' => 'メールアドレスが不正です。',
                'createdAt' => nowIso(),
            ];

            continue;
        }

        $actualSubject = replaceMailVariables(
            $subject,
            $customer,
            $surveyUrl
        );

        $actualBody = replaceMailVariables(
            $body,
            $customer,
            $surveyUrl
        );

        try {
            smtpSend(
                $mail,
                $to,
                $actualSubject,
                $actualBody
            );

            $sent++;

            $logs[] = [
                'id' => newId('mail'),
                'surveyId' => $surveyId,
                'customerId' => $customerId,
                'email' => $to,
                'status' => 'sent',
                'message' => '',
                'createdAt' => nowIso(),
            ];
        } catch (Throwable $e) {
            $failed++;

            $logs[] = [
                'id' => newId('mail'),
                'surveyId' => $surveyId,
                'customerId' => $customerId,
                'email' => $to,
                'status' => 'failed',
                'message' => safeExceptionMessage($e),
                'createdAt' => nowIso(),
            ];
        }
    }

    atomicWriteJson(MAIL_LOG_FILE, $logs);

    flash(
        $failed === 0 ? 'success' : 'warning',
        '送信完了：成功 ' .
        $sent .
        '件 / 失敗 ' .
        $failed .
        '件'
    );

    redirectTo('send', ['id' => $surveyId]);
}

function processSubmitAnswer(): void
{
    $surveyId = trim((string)($_POST['survey_id'] ?? ''));

    $survey = getSurvey($surveyId);

    if ($survey === null) {
        throw new AppException(
            'アンケートが存在しません。',
            'data'
        );
    }

    $survey = applyAutomaticStatus($survey);

    if ($survey['status'] !== STATUS_PUBLISHED) {
        throw new AppException(
            'このアンケートは現在回答を受け付けていません。',
            'data'
        );
    }

    $answers = $_POST['answer'] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $visibleQuestionIds = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $visibleQuestionIds[] = (string)$question['id'];
        }
    }

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $qid = (string)$question['id'];

            if (!$question['required']) {
                continue;
            }

            $value = $answers[$qid] ?? '';

            $empty = false;

            if (is_array($value)) {
                $empty = count(array_filter(
                    $value,
                    static function ($v): bool {
                        return trim((string)$v) !== '';
                    }
                )) === 0;
            } else {
                $empty = trim((string)$value) === '';
            }

            if ($empty) {
                throw new AppException(
                    $question['number'] .
                    '「' .
                    $question['text'] .
                    '」は必須項目です。',
                    'input'
                );
            }
        }
    }

    $cleanAnswers = [];

    foreach ($visibleQuestionIds as $qid) {
        if (!array_key_exists($qid, $answers)) {
            continue;
        }

        if (is_array($answers[$qid])) {
            $cleanAnswers[$qid] = array_values(array_map(
                static function ($v): string {
                    return trim((string)$v);
                },
                $answers[$qid]
            ));
        } else {
            $cleanAnswers[$qid] = trim((string)$answers[$qid]);
        }
    }

    $_SESSION['pending_answer'] = [
        'surveyId' => $surveyId,
        'answers' => $cleanAnswers,
    ];

    redirectTo('confirm', ['id' => $surveyId]);
}

function safeExceptionMessage(Throwable $e): string
{
    if ($e instanceof AppException) {
        return $e->getMessage();
    }

    return '処理中にエラーが発生しました。';
}

function renderHeader(
    string $title,
    bool $admin = true
): void {
    $csrf = h(csrfToken());

    echo '<!doctype html>';
    echo '<html lang="ja">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' - ' . h(APP_NAME) . '</title>';

    echo '<style>';
    echo ':root{';
    echo '--primary:#2563eb;';
    echo '--primary-dark:#1d4ed8;';
    echo '--success:#16a34a;';
    echo '--warning:#d97706;';
    echo '--danger:#dc2626;';
    echo '--gray:#64748b;';
    echo '--gray-light:#f1f5f9;';
    echo '--border:#dbe2ea;';
    echo '--text:#1e293b;';
    echo '--white:#fff;';
    echo '--shadow:0 4px 18px rgba(15,23,42,.08);';
    echo '}';
    echo '*{box-sizing:border-box}';
    echo 'body{margin:0;background:#f8fafc;color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif}';
    echo 'a{color:var(--primary);text-decoration:none}';
    echo 'a:hover{text-decoration:underline}';
    echo '.top{background:#fff;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:20}';
    echo '.top-inner{max-width:1400px;margin:auto;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px}';
    echo '.brand{font-weight:800;font-size:20px;color:#0f172a}';
    echo '.nav{display:flex;gap:8px;flex-wrap:wrap}';
    echo '.nav a{padding:8px 12px;border-radius:8px;color:#334155}';
    echo '.nav a:hover{background:#eff6ff;text-decoration:none}';
    echo '.container{max-width:1400px;margin:0 auto;padding:24px 20px}';
    echo '.container.answer{max-width:850px}';
    echo '.card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:20px;box-shadow:var(--shadow);margin-bottom:18px}';
    echo '.title-row{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:20px;flex-wrap:wrap}';
    echo 'h1{font-size:26px;margin:0}';
    echo 'h2{font-size:20px;margin:0 0 16px}';
    echo 'h3{font-size:17px;margin:0 0 12px}';
    echo '.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:8px;padding:9px 14px;background:#e2e8f0;color:#0f172a;cursor:pointer;font-weight:600;text-decoration:none;font-size:14px}';
    echo '.btn:hover{text-decoration:none;filter:brightness(.97)}';
    echo '.btn-primary{background:var(--primary);color:#fff}';
    echo '.btn-success{background:var(--success);color:#fff}';
    echo '.btn-danger{background:var(--danger);color:#fff}';
    echo '.btn-warning{background:var(--warning);color:#fff}';
    echo '.btn-small{padding:6px 9px;font-size:12px}';
    echo 'button:disabled{opacity:.5;cursor:not-allowed}';
    echo 'form.inline{display:inline}';
    echo '.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:16px}';
    echo '.col-12{grid-column:span 12}.col-8{grid-column:span 8}.col-6{grid-column:span 6}.col-4{grid-column:span 4}.col-3{grid-column:span 3}';
    echo 'label{display:block;font-weight:700;font-size:14px;margin-bottom:6px}';
    echo 'input,textarea,select{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:10px 11px;font:inherit;background:#fff;color:#0f172a}';
    echo 'textarea{min-height:110px;resize:vertical}';
    echo 'input:focus,textarea:focus,select:focus{outline:3px solid #dbeafe;border-color:#60a5fa}';
    echo '.check{display:flex;align-items:center;gap:8px;font-weight:500}';
    echo '.check input{width:auto}';
    echo '.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}';
    echo '.table-wrap{overflow:auto}';
    echo 'table{width:100%;border-collapse:collapse;min-width:900px}';
    echo 'th,td{padding:11px 10px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}';
    echo 'th{background:#f8fafc;font-size:13px;white-space:nowrap}';
    echo '.badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:700}';
    echo '.badge-draft{background:#e2e8f0;color:#334155}';
    echo '.badge-published{background:#dcfce7;color:#166534}';
    echo '.badge-stopped{background:#fef3c7;color:#92400e}';
    echo '.badge-ended{background:#fee2e2;color:#991b1b}';
    echo '.alert{border-radius:10px;padding:12px 14px;margin-bottom:14px}';
    echo '.alert-success{background:#dcfce7;color:#166534}';
    echo '.alert-warning{background:#fef3c7;color:#92400e}';
    echo '.alert-danger{background:#fee2e2;color:#991b1b}';
    echo '.alert-info{background:#dbeafe;color:#1e40af}';
    echo '.group{border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px;background:#fff}';
    echo '.question{border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-top:12px;background:#f8fafc}';
    echo '.option{display:grid;grid-template-columns:1fr 220px auto;gap:8px;margin-top:8px;align-items:center}';
    echo '.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}';
    echo '.stat{background:#fff;border:1px solid var(--border);border-radius:10px;padding:16px}';
    echo '.stat-value{font-size:28px;font-weight:800;margin-top:5px}';
    echo '.muted{color:var(--gray);font-size:13px}';
    echo '.center{text-align:center}';
    echo '.empty{padding:50px 20px;text-align:center;color:var(--gray)}';
    echo '.spinner{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.5);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}';
    echo 'form.loading .spinner{display:inline-block}';
    echo 'form.loading .loading-label{display:none}';
    echo '@keyframes spin{to{transform:rotate(360deg)}}';
    echo '.answer-card{padding:22px}';
    echo '.answer-question{margin-bottom:25px}';
    echo '.answer-option{display:block;padding:13px;border:1px solid var(--border);border-radius:9px;margin:8px 0;cursor:pointer;background:#fff}';
    echo '.answer-option:hover{background:#f8fafc}';
    echo '.answer-option input{width:auto;margin-right:8px}';
    echo '.mobile-actions{position:sticky;bottom:0;background:rgba(255,255,255,.96);border-top:1px solid var(--border);padding:12px;display:flex;justify-content:space-between;gap:8px}';
    echo '.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#f1f5f9;padding:3px 6px;border-radius:5px;font-size:12px}';
    echo '@media(max-width:900px){.col-8,.col-6,.col-4,.col-3{grid-column:span 12}.stat-grid{grid-template-columns:repeat(2,1fr)}.top-inner{align-items:flex-start;flex-direction:column}.option{grid-template-columns:1fr}.container{padding:16px}.card{padding:15px}}';
    echo '@media(max-width:600px){.stat-grid{grid-template-columns:1fr}.title-row{align-items:flex-start}.actions .btn{width:100%}.actions form{width:100%}.actions form .btn{width:100%}}';
    echo '</style>';

    echo '</head>';
    echo '<body>';

    if ($admin) {
        echo '<header class="top">';
        echo '<div class="top-inner">';
        echo '<div class="brand">' . h(APP_NAME) . '</div>';
        echo '<nav class="nav">';
        echo '<a href="index.php?screen=list">アンケート一覧</a>';
        echo '<a href="index.php?screen=kintone">kintone</a>';
        echo '<a href="index.php?screen=mail">メール</a>';
        echo '</nav>';
        echo '</div>';
        echo '</header>';
    }

    echo '<main class="container' . ($admin ? '' : ' answer') . '">';

    $flashes = getFlashes();

    foreach ($flashes as $item) {
        $type = (string)($item['type'] ?? 'info');

        if (!in_array(
            $type,
            ['success', 'warning', 'danger', 'info'],
            true
        )) {
            $type = 'info';
        }

        echo '<div class="alert alert-' . h($type) . '">';
        echo h((string)($item['message'] ?? ''));
        echo '</div>';
    }
}

function renderFooter(): void
{
    echo '</main>';

    echo '<script>';
    echo 'document.addEventListener("submit",function(e){';
    echo 'var f=e.target;';
    echo 'if(f.matches("form[data-loading]")){';
    echo 'if(f.dataset.confirm && !window.confirm(f.dataset.confirm)){e.preventDefault();return;}';
    echo 'if(f.classList.contains("loading")){e.preventDefault();return;}';
    echo 'f.classList.add("loading");';
    echo 'var buttons=f.querySelectorAll("button");';
    echo 'buttons.forEach(function(b){b.disabled=true;});';
    echo '}';
    echo '});';

    echo 'document.addEventListener("change",function(e){';
    echo 'if(e.target.matches("[data-question-type]")){';
    echo 'var box=e.target.closest(".question");';
    echo 'if(!box)return;';
    echo 'var options=box.querySelector("[data-options]");';
    echo 'if(options){options.style.display=e.target.value==="text"?"none":"block";}';
    echo '}';
    echo '});';

    echo '</script>';

    echo '</body>';
    echo '</html>';
}

function renderList(): void
{
    $surveys = getSurveys();

    $keyword = trim((string)($_GET['q'] ?? ''));
    $statusFilter = trim((string)($_GET['status'] ?? 'all'));
    $sort = trim((string)($_GET['sort'] ?? 'updated_desc'));

    $surveys = array_values(array_filter(
        $surveys,
        static function (array $survey) use (
            $keyword,
            $statusFilter
        ): bool {
            if (
                $keyword !== '' &&
                mb_stripos(
                    (string)$survey['title'],
                    $keyword
                ) === false
            ) {
                return false;
            }

            if (
                $statusFilter !== 'all' &&
                (string)$survey['status'] !== $statusFilter
            ) {
                return false;
            }

            return true;
        }
    ));

    usort(
        $surveys,
        static function (array $a, array $b) use ($sort): int {
            if ($sort === 'updated_asc') {
                return strcmp(
                    (string)$a['updatedAt'],
                    (string)$b['updatedAt']
                );
            }

            if ($sort === 'answers_desc') {
                return answerCount((string)$b['id']) <=> answerCount((string)$a['id']);
            }

            if ($sort === 'answers_asc') {
                return answerCount((string)$a['id']) <=> answerCount((string)$b['id']);
            }

            if ($sort === 'start_desc') {
                return strcmp(
                    (string)$b['startAt'],
                    (string)$a['startAt']
                );
            }

            if ($sort === 'start_asc') {
                return strcmp(
                    (string)$a['startAt'],
                    (string)$b['startAt']
                );
            }

            return strcmp(
                (string)$b['updatedAt'],
                (string)$a['updatedAt']
            );
        }
    );

    echo '<div class="title-row">';
    echo '<div>';
    echo '<h1>アンケート一覧</h1>';
    echo '<div class="muted">アンケートの作成・公開・送信・集計を管理します。</div>';
    echo '</div>';
    echo '<a class="btn btn-primary" href="index.php?screen=edit">＋ 新規作成</a>';
    echo '</div>';

    echo '<div class="card">';
    echo '<form method="get">';
    echo '<input type="hidden" name="screen" value="list">';
    echo '<div class="grid">';
    echo '<div class="col-6">';
    echo '<label for="q">タイトル検索</label>';
    echo '<input id="q" name="q" value="' . h($keyword) . '" placeholder="タイトル部分一致">';
    echo '</div>';
    echo '<div class="col-3">';
    echo '<label for="status">絞り込み</label>';
    echo '<select id="status" name="status">';
    echo '<option value="all">すべて</option>';
    echo '<option value="published"' . ($statusFilter === 'published' ? ' selected' : '') . '>公開中</option>';
    echo '<option value="draft"' . ($statusFilter === 'draft' ? ' selected' : '') . '>下書き</option>';
    echo '<option value="stopped"' . ($statusFilter === 'stopped' ? ' selected' : '') . '>停止</option>';
    echo '<option value="ended"' . ($statusFilter === 'ended' ? ' selected' : '') . '>終了</option>';
    echo '</select>';
    echo '</div>';
    echo '<div class="col-3">';
    echo '<label for="sort">ソート</label>';
    echo '<select id="sort" name="sort">';
    echo '<option value="updated_desc"' . ($sort === 'updated_desc' ? ' selected' : '') . '>更新日：新しい順</option>';
    echo '<option value="updated_asc"' . ($sort === 'updated_asc' ? ' selected' : '') . '>更新日：古い順</option>';
    echo '<option value="answers_desc"' . ($sort === 'answers_desc' ? ' selected' : '') . '>回答数：多い順</option>';
    echo '<option value="answers_asc"' . ($sort === 'answers_asc' ? ' selected' : '') . '>回答数：少ない順</option>';
    echo '<option value="start_desc"' . ($sort === 'start_desc' ? ' selected' : '') . '>開始日：新しい順</option>';
    echo '<option value="start_asc"' . ($sort === 'start_asc' ? ' selected' : '') . '>開始日：古い順</option>';
    echo '</select>';
    echo '</div>';
    echo '</div>';
    echo '<div style="margin-top:12px"><button class="btn btn-primary">検索</button></div>';
    echo '</form>';
    echo '</div>';

    echo '<div class="card">';
    echo '<div class="table-wrap">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>タイトル</th>';
    echo '<th>作成日</th>';
    echo '<th>更新日</th>';
    echo '<th>期間</th>';
    echo '<th>ステータス</th>';
    echo '<th>回答数</th>';
    echo '<th>操作</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    if ($surveys === []) {
        echo '<tr><td colspan="7" class="empty">アンケートがありません。</td></tr>';
    }

    foreach ($surveys as $survey) {
        $status = (string)$survey['status'];
        $statusText = [
            'draft' => '下書き',
            'published' => '公開中',
            'stopped' => '停止',
            'ended' => '終了',
        ][$status] ?? $status;

        echo '<tr>';
        echo '<td><strong>' . h($survey['title'] ?: '無題') . '</strong></td>';
        echo '<td>' . h($survey['createdAt']) . '</td>';
        echo '<td>' . h($survey['updatedAt']) . '</td>';
        echo '<td>' .
            h($survey['startAt'] ?: '-') .
            '<br>〜<br>' .
            h($survey['endAt'] ?: '-') .
            '</td>';
        echo '<td><span class="badge badge-' . h($status) . '">' . h($statusText) . '</span></td>';
        echo '<td>' . h(answerCount((string)$survey['id'])) . '</td>';
        echo '<td>';
        echo '<div class="actions">';
        echo '<a class="btn btn-small" href="index.php?screen=edit&id=' . rawurlencode($survey['id']) . '">確認・編集</a>';
        echo '<a class="btn btn-small" href="index.php?screen=preview&id=' . rawurlencode($survey['id']) . '">プレビュー</a>';
        echo '<a class="btn btn-small" href="index.php?screen=analytics&id=' . rawurlencode($survey['id']) . '">集計</a>';
        echo '<a class="btn btn-small" href="index.php?screen=send&id=' . rawurlencode($survey['id']) . '">送信</a>';

        echo '<form class="inline" method="post" data-loading data-confirm="このアンケートを複製しますか？">';
        echo '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
        echo '<input type="hidden" name="action" value="duplicate_survey">';
        echo '<input type="hidden" name="id" value="' . h($survey['id']) . '">';
        echo '<button class="btn btn-small">複製</button>';
        echo '</form>';

        echo '<form class="inline" method="post" data-loading data-confirm="このアンケートを削除しますか？">';
        echo '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
        echo '<input type="hidden" name="action" value="delete_survey">';
        echo '<input type="hidden" name="id" value="' . h($survey['id']) . '">';
        echo '<button class="btn btn-small btn-danger">削除</button>';
        echo '</form>';

        echo '</div>';

        if ($status !== STATUS_ENDED) {
            echo '<div style="margin-top:8px" class="actions">';

            if ($status === STATUS_DRAFT) {
                renderStatusButton(
                    $survey['id'],
                    STATUS_PUBLISHED,
                    '公開'
                );
            } elseif ($status === STATUS_PUBLISHED) {
                renderStatusButton(
                    $survey['id'],
                    STATUS_STOPPED,
                    '停止'
                );
            } elseif ($status === STATUS_STOPPED) {
                renderStatusButton(
                    $survey['id'],
                    STATUS_PUBLISHED,
                    '再開'
                );
            }

            echo '</div>';
        }

        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

function renderStatusButton(
    string $id,
    string $status,
    string $label
): void {
    $confirm = $label . 'しますか？';

    echo '<form class="inline" method="post" data-loading data-confirm="' . h($confirm) . '">';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="change_status">';
    echo '<input type="hidden" name="id" value="' . h($id) . '">';
    echo '<input type="hidden" name="new_status" value="' . h($status) . '">';
    echo '<button class="btn btn-small">' . h($label) . '</button>';
    echo '</form>';
}

function renderEdit(): void
{
    $id = trim((string)($_GET['id'] ?? ''));

    $survey = null;

    if ($id !== '') {
        $survey = getSurvey($id);
    }

    if ($survey === null) {
        $survey = defaultSurvey();
    }

    if (isset($_SESSION['edit_old'])) {
        $old = $_SESSION['edit_old'];
        unset($_SESSION['edit_old']);

        if (is_array($old)) {
            $survey = normalizeSurvey($old);
        }
    }

    $errors = $_SESSION['edit_errors'] ?? [];
    unset($_SESSION['edit_errors']);

    echo '<div class="title-row">';
    echo '<div>';
    echo '<h1>アンケート作成・編集</h1>';
    echo '</div>';
    echo '<div class="actions">';
    echo '<a class="btn" href="index.php?screen=list">キャンセル</a>';
    echo '<a class="btn" href="index.php?screen=preview&id=' . rawurlencode($survey['id']) . '">プレビュー</a>';
    echo '</div>';
    echo '</div>';

    foreach ($errors as $error) {
        echo '<div class="alert alert-danger">' . h($error) . '</div>';
    }

    echo '<form method="post" data-loading>';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="save_survey">';
    echo '<input type="hidden" name="survey_id" value="' . h($survey['id']) . '">';

    echo '<div class="card">';
    echo '<div class="grid">';

    echo '<div class="col-12">';
    echo '<label>アンケートタイトル</label>';
    echo '<input name="title" value="' . h($survey['title']) . '" required maxlength="200">';
    echo '</div>';

    echo '<div class="col-12">';
    echo '<label>アンケート説明</label>';
    echo '<textarea name="description">' . h($survey['description']) . '</textarea>';
    echo '</div>';

    echo '<div class="col-4">';
    echo '<label>開始日時</label>';
    echo '<input type="datetime-local" name="start_at" value="' . h(toDatetimeLocal($survey['startAt'])) . '">';
    echo '</div>';

    echo '<div class="col-4">';
    echo '<label>終了日時</label>';
    echo '<input type="datetime-local" name="end_at" value="' . h(toDatetimeLocal($survey['endAt'])) . '">';
    echo '</div>';

    echo '<div class="col-4">';
    echo '<label>質問番号の採番方式</label>';
    echo '<select name="numbering">';
    echo '<option value="global"' . ($survey['numbering'] === 'global' ? ' selected' : '') . '>アンケート全体で通番（Q1、Q2…）</option>';
    echo '<option value="group"' . ($survey['numbering'] === 'group' ? ' selected' : '') . '>グループ毎（Q1-1、Q1-2…）</option>';
    echo '</select>';
    echo '</div>';

    echo '</div>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>グループ・質問</h2>';

    foreach ($survey['groups'] as $groupIndex => $group) {
        echo '<div class="group">';
        echo '<div class="grid">';
        echo '<div class="col-8">';
        echo '<label>グループタイトル</label>';
        echo '<input type="hidden" name="group_id[' . $groupIndex . ']" value="' . h($group['id']) . '">';
        echo '<input name="group_title[' . $groupIndex . ']" value="' . h($group['title']) . '" required>';
        echo '</div>';
        echo '<div class="col-4" style="display:flex;align-items:end">';
        echo '<button type="button" class="btn btn-danger" onclick="submitAction(\'delete-group-' . $groupIndex . '\')">グループ削除</button>';
        echo '</div>';
        echo '</div>';

        echo '<div id="delete-group-' . $groupIndex . '" style="display:none"></div>';

        foreach ($group['questions'] as $questionIndex => $question) {
            echo '<div class="question">';
            echo '<div class="grid">';

            echo '<div class="col-8">';
            echo '<label>' . h($question['number'] ?? '') . ' 質問文</label>';
            echo '<input type="hidden" name="question_id[' . $groupIndex . '][]" value="' . h($question['id']) . '">';
            echo '<input name="question_text[' . $groupIndex . '][]" value="' . h($question['text']) . '" required>';
            echo '</div>';

            echo '<div class="col-3">';
            echo '<label>回答形式</label>';
            echo '<select name="question_type[' . $groupIndex . '][]" data-question-type>';
            echo '<option value="single"' . ($question['type'] === QUESTION_SINGLE ? ' selected' : '') . '>単一選択</option>';
            echo '<option value="multi"' . ($question['type'] === QUESTION_MULTI ? ' selected' : '') . '>複数選択</option>';
            echo '<option value="text"' . ($question['type'] === QUESTION_TEXT ? ' selected' : '') . '>自由記述</option>';
            echo '</select>';
            echo '</div>';

            echo '<div class="col-1">';
            echo '<label>必須</label>';
            echo '<label class="check"><input type="checkbox" name="question_required[' . $groupIndex . '][' . $questionIndex . ']" value="1"' . ($question['required'] ? ' checked' : '') . '> 必須</label>';
            echo '</div>';

            echo '</div>';

            echo '<div data-options style="display:' . ($question['type'] === QUESTION_TEXT ? 'none' : 'block') . '">';
            echo '<label style="margin-top:12px">選択肢・条件分岐</label>';

            foreach ($question['options'] as $optionIndex => $option) {
                echo '<div class="option">';
                echo '<input name="option_label[' . $groupIndex . '][' . $questionIndex . '][]" value="' . h($option['label']) . '" placeholder="選択肢">';
                echo '<input name="option_next[' . $groupIndex . '][' . $questionIndex . '][]" value="' . h($option['nextQuestionId']) . '" placeholder="次に表示する質問ID（任意）">';
                echo '<span class="code">分岐</span>';
                echo '</div>';
            }

            if ($question['options'] === []) {
                echo '<div class="muted">自由記述では選択肢は使用しません。</div>';
            }

            echo '</div>';

            echo '<div style="margin-top:12px">';
            echo '<button type="button" class="btn btn-danger btn-small" onclick="deleteQuestionConfirm(this)">質問削除</button>';
            echo '<input type="hidden" name="delete_question_marker[]" value="">';
            echo '</div>';

            echo '</div>';
        }

        echo '<div style="margin-top:14px">';
        echo '<button type="button" class="btn btn-small" onclick="addQuestionClient(this)">＋ 質問を追加</button>';
        echo '</div>';

        echo '</div>';
    }

    echo '<div class="actions">';
    echo '<button type="button" class="btn" onclick="addGroupClient()">＋ グループを追加</button>';
    echo '</div>';

    echo '</div>';

    echo '<div class="card">';
    echo '<div class="actions">';
    echo '<a class="btn" href="index.php?screen=list">キャンセル</a>';
    echo '<button class="btn btn-primary" type="submit">';
    echo '<span class="loading-label">保存して一覧へ</span>';
    echo '<span class="spinner"></span>';
    echo '</button>';
    echo '</div>';
    echo '</div>';

    echo '</form>';

    echo '<script>';
    echo 'function deleteQuestionConfirm(btn){';
    echo 'if(!confirm("この質問を削除しますか？"))return;';
    echo 'var q=btn.closest(".question");';
    echo 'q.remove();';
    echo '}';
    echo 'function addQuestionClient(btn){';
    echo 'var g=btn.closest(".group");';
    echo 'var container=document.createElement("div");';
    echo 'container.className="question";';
    echo 'container.innerHTML="<div class=\\"muted\\">新しい質問を追加しました。保存後に編集できます。</div>";';
    echo 'g.insertBefore(container,btn.parentElement);';
    echo '}';
    echo 'function addGroupClient(){';
    echo 'alert("グループ追加は保存済みデータへ安全に反映するため、保存後に再度編集してください。");';
    echo '}';
    echo '</script>';
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

function renderPreview(): void
{
    $id = trim((string)($_GET['id'] ?? ''));

    $survey = getSurvey($id);

    if ($survey === null) {
        flash('danger', 'プレビュー対象のアンケートが存在しません。');
        redirectTo('list');
    }

    echo '<div class="title-row">';
    echo '<div>';
    echo '<h1>プレビュー</h1>';
    echo '<div class="muted">' . h($survey['title']) . '</div>';
    echo '</div>';
    echo '<a class="btn" href="index.php?screen=edit&id=' . rawurlencode($id) . '">編集へ戻る</a>';
    echo '</div>';

    echo '<div class="card answer-card">';
    echo '<h2>' . h($survey['title']) . '</h2>';

    if ($survey['description'] !== '') {
        echo '<p>' . nl2br(h($survey['description'])) . '</p>';
    }

    foreach ($survey['groups'] as $group) {
        echo '<section style="margin-top:28px">';
        echo '<h3>' . h($group['title']) . '</h3>';

        foreach ($group['questions'] as $question) {
            echo '<div class="answer-question">';
            echo '<label>';
            echo h($question['number']) .
                ' ' .
                h($question['text']);

            if ($question['required']) {
                echo ' <span style="color:#dc2626">必須</span>';
            }

            echo '</label>';

            if ($question['type'] === QUESTION_TEXT) {
                echo '<textarea disabled placeholder="自由記述"></textarea>';
            } else {
                foreach ($question['options'] as $option) {
                    $type = $question['type'] === QUESTION_MULTI
                        ? 'checkbox'
                        : 'radio';

                    echo '<label class="answer-option">';
                    echo '<input type="' . h($type) . '" disabled>';
                    echo h($option['label']);
                    echo '</label>';
                }
            }

            echo '</div>';
        }

        echo '</section>';
    }

    echo '<div class="alert alert-info">';
    echo 'これはプレビューです。メール送信等は実行されません。';
    echo '</div>';

    echo '</div>';
}

function renderKintone(): void
{
    $settings = getSettings();
    $k = $settings['kintone'] ?? [];

    $fields = $_SESSION['kintone_fields'] ?? [];

    if (!is_array($fields)) {
        $fields = [];
    }

    echo '<div class="title-row">';
    echo '<div>';
    echo '<h1>kintone連携設定</h1>';
    echo '<div class="muted">顧客情報の取得元を設定します。</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="card">';
    echo '<div class="alert alert-info">';
    echo '<strong>認証情報について</strong><br>';
    echo 'ログイン名・パスワードはサーバー側でのみ使用します。';
    echo 'X-Cybozu-Authorizationもブラウザへ公開しません。';
    echo '</div>';

    echo '<form method="post" data-loading>';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="save_kintone">';

    echo '<div class="grid">';

    echo '<div class="col-6">';
    echo '<label>サブドメイン</label>';
    echo '<input name="kintone_subdomain" value="' . h($k['subdomain'] ?? '') . '" placeholder="https://xxxx.cybozu.com">';
    echo '</div>';

    echo '<div class="col-3">';
    echo '<label>顧客管理アプリID</label>';
    echo '<input name="kintone_app_id" value="' . h($k['app_id'] ?? '') . '" inputmode="numeric">';
    echo '</div>';

    echo '<div class="col-3">';
    echo '<label>Proxy</label>';
    echo '<input name="kintone_proxy" value="' . h($k['proxy'] ?? '') . '" placeholder="host:port">';
    echo '</div>';

    echo '<div class="col-6">';
    echo '<label>ログイン名</label>';
    echo '<input name="kintone_login" value="' . h($k['login'] ?? '') . '" autocomplete="username">';
    echo '</div>';

    echo '<div class="col-6">';
    echo '<label>パスワード</label>';
    echo '<input type="password" name="kintone_password" value="" placeholder="変更しない場合は空欄" autocomplete="new-password">';
    echo '</div>';

    echo '<div class="col-12">';
    echo '<label class="check">';
    echo '<input type="checkbox" name="kintone_verify_ssl" value="1"' .
        (!empty($k['verify_ssl']) ? ' checked' : '') .
        '> SSL証明書を検証する';
    echo '</label>';
    echo '<div class="muted">POC初期値は無効です。</div>';
    echo '</div>';

    echo '</div>';

    echo '<hr style="margin:24px 0;border:0;border-top:1px solid var(--border)">';

    echo '<h2>顧客項目マッピング</h2>';

    $map = $k['field_map'] ?? [];

    renderFieldMapInput(
        'field_organization',
        '組織名',
        (string)($map['organization'] ?? ''),
        $fields
    );

    renderFieldMapInput(
        'field_name',
        '氏名',
        (string)($map['name'] ?? ''),
        $fields
    );

    renderFieldMapInput(
        'field_email',
        'メールアドレス',
        (string)($map['email'] ?? ''),
        $fields
    );

    renderFieldMapInput(
        'field_department',
        '部署名',
        (string)($map['department'] ?? ''),
        $fields
    );

    renderFieldMapInput(
        'field_phone',
        '電話番号',
        (string)($map['phone'] ?? ''),
        $fields
    );

    echo '<div style="margin-top:16px">';
    echo '<label>住所（複数選択可）</label>';

    $selectedAddresses = is_array($map['address'] ?? null)
        ? $map['address']
        : [];

    if ($fields === []) {
        echo '<div class="muted">先に「項目一覧を再取得」を実行してください。</div>';
    } else {
        echo '<div class="grid">';

        foreach ($fields as $code => $field) {
            $type = (string)($field['type'] ?? '');

            if ($type === 'SUBTABLE') {
                continue;
            }

            $checked = in_array(
                (string)$code,
                $selectedAddresses,
                true
            );

            echo '<div class="col-4">';
            echo '<label class="check">';
            echo '<input type="checkbox" name="field_address[]" value="' . h($code) . '"' .
                ($checked ? ' checked' : '') .
                '>';
            echo h((string)($field['label'] ?? $code));
            echo ' <span class="code">' . h($code) . '</span>';
            echo '</label>';
            echo '</div>';
        }

        echo '</div>';
    }

    echo '</div>';

    echo '<div class="actions" style="margin-top:20px">';
    echo '<button class="btn btn-primary" type="submit">';
    echo '<span class="loading-label">設定保存</span>';
    echo '<span class="spinner"></span>';
    echo '</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>接続・データ操作</h2>';

    echo '<div class="actions">';

    echo '<form class="inline" method="post" data-loading>';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="test_kintone">';
    echo '<button class="btn btn-success">';
    echo '<span class="loading-label">接続テスト</span>';
    echo '<span class="spinner"></span>';
    echo '</button>';
    echo '</form>';

    echo '<form class="inline" method="post" data-loading>';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="get_kintone_fields">';
    echo '<button class="btn">';
    echo '<span class="loading-label">項目一覧を再取得</span>';
    echo '<span class="spinner"></span>';
    echo '</button>';
    echo '</form>';

    echo '<form class="inline" method="post" data-loading data-confirm="kintoneから顧客情報を同期します。よろしいですか？">';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="sync_kintone">';
    echo '<button class="btn btn-primary">';
    echo '<span class="loading-label">顧客情報を同期</span>';
    echo '<span class="spinner"></span>';
    echo '</button>';
    echo '</form>';

    echo '</div>';

    echo '<div class="muted" style="margin-top:12px">';
    echo '接続テスト、項目一覧取得、顧客情報同期は別々の操作として実行します。';
    echo '</div>';

    echo '</div>';

    $customers = getCustomers();

    echo '<div class="card">';
    echo '<h2>同期済み顧客</h2>';

    if ($customers === []) {
        echo '<div class="empty">顧客情報はまだ同期されていません。</div>';
    } else {
        echo '<div class="table-wrap">';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th>組織名</th>';
        echo '<th>氏名</th>';
        echo '<th>メール</th>';
        echo '<th>部署</th>';
        echo '<th>電話</th>';
        echo '<th>住所</th>';
        echo '<th>同期日時</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach (array_slice($customers, 0, 100) as $customer) {
            echo '<tr>';
            echo '<td>' . h($customer['organization'] ?? '') . '</td>';
            echo '<td>' . h($customer['name'] ?? '') . '</td>';
            echo '<td>' . h($customer['email'] ?? '') . '</td>';
            echo '<td>' . h($customer['department'] ?? '') . '</td>';
            echo '<td>' . h($customer['phone'] ?? '') . '</td>';
            echo '<td>' . h($customer['address'] ?? '') . '</td>';
            echo '<td>' . h($customer['syncedAt'] ?? '') . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';

        if (count($customers) > 100) {
            echo '<div class="muted" style="margin-top:10px">先頭100件を表示しています。</div>';
        }
    }

    echo '</div>';
}

function renderFieldMapInput(
    string $name,
    string $label,
    string $value,
    array $fields
): void {
    echo '<div style="margin-bottom:14px">';
    echo '<label>' . h($label) . '</label>';

    if ($fields !== []) {
        echo '<select name="' . h($name) . '">';
        echo '<option value="">未設定</option>';

        foreach ($fields as $code => $field) {
            if (($field['type'] ?? '') === 'SUBTABLE') {
                continue;
            }

            echo '<option value="' . h($code) . '"' .
                ($value === (string)$code ? ' selected' : '') .
                '>' .
                h((string)($field['label'] ?? $code)) .
                ' [' .
                h((string)$code) .
                ']</option>';
        }

        echo '</select>';
    } else {
        echo '<input name="' . h($name) . '" value="' . h($value) . '" placeholder="kintoneフィールドコード">';
    }

    echo '</div>';
}

function renderMail(): void
{
    $mail = mailSettings();

    echo '<div class="title-row">';
    echo '<div>';
    echo '<h1>メールサーバ設定</h1>';
    echo '<div class="muted">SMTPサーバへ直接接続してメールを送信します。</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="card">';
    echo '<form method="post" data-loading>';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="save_mail">';

    echo '<div class="grid">';

    echo '<div class="col-8">';
    echo '<label>SMTPサーバ</label>';
    echo '<input name="smtp_server" value="' . h($mail['smtp_server'] ?? '') . '">';
    echo '</div>';

    echo '<div class="col-4">';
    echo '<label>SMTPポート</label>';
    echo '<input type="number" name="smtp_port" value="' . h($mail['smtp_port'] ?? 587) . '" min="1" max="65535">';
    echo '</div>';

    echo '<div class="col-4">';
    echo '<label>暗号化方式</label>';
    echo '<select name="smtp_encryption">';
    foreach (['ssl' => 'SSL', 'tls' => 'TLS', 'none' => 'なし'] as $value => $label) {
        echo '<option value="' . h($value) . '"' .
            (($mail['encryption'] ?? 'tls') === $value ? ' selected' : '') .
            '>' . h($label) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="col-4">';
    echo '<label class="check" style="margin-top:30px">';
    echo '<input type="checkbox" name="smtp_auth" value="1"' .
        (!empty($mail['auth']) ? ' checked' : '') .
        '> SMTP認証';
    echo '</label>';
    echo '</div>';

    echo '<div class="col-4"></div>';

    echo '<div class="col-6">';
    echo '<label>SMTPユーザー名</label>';
    echo '<input name="smtp_username" value="' . h($mail['username'] ?? '') . '" autocomplete="username">';
    echo '</div>';

    echo '<div class="col-6">';
    echo '<label>SMTPパスワード</label>';
    echo '<input type="password" name="smtp_password" value="" placeholder="変更しない場合は空欄" autocomplete="new-password">';
    echo '</div>';

    echo '<div class="col-6">';
    echo '<label>送信元メールアドレス</label>';
    echo '<input type="email" name="from_email" value="' . h($mail['from_email'] ?? '') . '">';
    echo '</div>';

    echo '<div class="col-6">';
    echo '<label>送信元名</label>';
    echo '<input name="from_name" value="' . h($mail['from_name'] ?? '') . '">';
    echo '</div>';

    echo '<div class="col-6">';
    echo '<label>返信先メールアドレス</label>';
    echo '<input type="email" name="reply_to" value="' . h($mail['reply_to'] ?? '') . '">';
    echo '</div>';

    echo '</div>';

    echo '<div class="actions" style="margin-top:20px">';
    echo '<button class="btn btn-primary">';
    echo '<span class="loading-label">設定保存</span>';
    echo '<span class="spinner"></span>';
    echo '</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>接続確認</h2>';

    echo '<form method="post" data-loading>';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="test_mail">';
    echo '<button class="btn btn-success">';
    echo '<span class="loading-label">テストメール送信</span>';
    echo '<span class="spinner"></span>';
    echo '</button>';
    echo '</form>';

    echo '</div>';
}

function renderSend(): void
{
    $id = trim((string)($_GET['id'] ?? ''));

    $survey = getSurvey($id);

    if ($survey === null) {
        flash('danger', '対象アンケートが存在しません。');
        redirectTo('list');
    }

    $keyword = trim((string)($_GET['q'] ?? ''));
    $customers = array_values(array_filter(
        getCustomers(),
        static function (array $customer) use ($keyword): bool {
            return customerMatches($customer, $keyword);
        }
    ));

    $logs = readJson(MAIL_LOG_FILE, []);

    $surveyLogs = array_values(array_filter(
        $logs,
        static function (array $log) use ($id): bool {
            return (string)($log['surveyId'] ?? '') === $id;
        }
    ));

    echo '<div class="title-row">';
    echo '<div>';
    echo '<h1>顧客選択・メール送信</h1>';
    echo '<div class="muted">対象アンケート：<strong>' . h($survey['title']) . '</strong></div>';
    echo '</div>';
    echo '<a class="btn" href="index.php?screen=list">一覧へ戻る</a>';
    echo '</div>';

    echo '<div class="card">';
    echo '<form method="get">';
    echo '<input type="hidden" name="screen" value="send">';
    echo '<input type="hidden" name="id" value="' . h($id) . '">';
    echo '<label>顧客検索</label>';
    echo '<div class="actions">';
    echo '<input style="flex:1;min-width:250px" name="q" value="' . h($keyword) . '" placeholder="組織名・氏名・メール等">';
    echo '<button class="btn btn-primary">検索</button>';
    echo '</div>';
    echo '</form>';
    echo '</div>';

    echo '<form method="post" data-loading data-confirm="選択した顧客へ一括送信します。よろしいですか？">';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="send_mail">';
    echo '<input type="hidden" name="survey_id" value="' . h($id) . '">';

    echo '<div class="card">';
    echo '<h2>メール作成</h2>';

    echo '<div class="grid">';
    echo '<div class="col-12">';
    echo '<label>件名</label>';
    echo '<input name="mail_subject" value="アンケートご協力のお願い" required>';
    echo '</div>';

    echo '<div class="col-12">';
    echo '<label>本文</label>';
    echo '<textarea name="mail_body" required> {顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>';
    echo '</div>';
    echo '</div>';

    echo '<div class="muted" style="margin-top:8px">使用可能な変数：{顧客名} / {アンケートURL}</div>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>顧客選択</h2>';

    if ($customers === []) {
        echo '<div class="empty">対象となる顧客がありません。kintoneから顧客情報を同期してください。</div>';
    } else {
        echo '<div class="table-wrap">';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th><input type="checkbox" onclick="toggleAllCustomers(this)"></th>';
        echo '<th>組織名</th>';
        echo '<th>氏名</th>';
        echo '<th>メール</th>';
        echo '<th>部署</th>';
        echo '<th>電話</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($customers as $customer) {
            echo '<tr>';
            echo '<td><input class="customer-check" type="checkbox" name="customer_id[]" value="' . h($customer['id']) . '"></td>';
            echo '<td>' . h($customer['organization'] ?? '') . '</td>';
            echo '<td>' . h($customer['name'] ?? '') . '</td>';
            echo '<td>' . h($customer['email'] ?? '') . '</td>';
            echo '<td>' . h($customer['department'] ?? '') . '</td>';
            echo '<td>' . h($customer['phone'] ?? '') . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    echo '<div style="margin-top:16px">';
    echo '<button class="btn btn-primary" type="submit">';
    echo '<span class="loading-label">一括送信</span>';
    echo '<span class="spinner"></span>';
    echo '</button>';
    echo '</div>';

    echo '</div>';
    echo '</form>';

    echo '<div class="card">';
    echo '<h2>送信履歴</h2>';

    if ($surveyLogs === []) {
        echo '<div class="empty">送信履歴はありません。</div>';
    } else {
        echo '<div class="table-wrap">';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th>日時</th>';
        echo '<th>メール</th>';
        echo '<th>結果</th>';
        echo '<th>内容</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach (array_reverse($surveyLogs) as $log) {
            echo '<tr>';
            echo '<td>' . h($log['createdAt'] ?? '') . '</td>';
            echo '<td>' . h($log['email'] ?? '') . '</td>';
            echo '<td>' . h(($log['status'] ?? '') === 'sent' ? '送信済み' : '失敗') . '</td>';
            echo '<td>' . h($log['message'] ?? '') . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    echo '</div>';

    echo '<script>';
    echo 'function toggleAllCustomers(el){';
    echo 'document.querySelectorAll(".customer-check").forEach(function(x){x.checked=el.checked;});';
    echo '}';
    echo '</script>';
}

function renderAnalytics(): void
{
    $id = trim((string)($_GET['id'] ?? ''));

    $survey = getSurvey($id);

    if ($survey === null) {
        flash('danger', '対象アンケートが存在しません。');
        redirectTo('list');
    }

    $answers = getAnswersForSurvey($id);
    $customers = getCustomers();

    $sentCustomerIds = [];

    foreach (readJson(MAIL_LOG_FILE, []) as $log) {
        if (
            (string)($log['surveyId'] ?? '') === $id &&
            (string)($log['status'] ?? '') === 'sent'
        ) {
            $sentCustomerIds[(string)($log['customerId'] ?? '')] = true;
        }
    }

    $answerCount = count($answers);
    $targetCount = count($sentCustomerIds);
    $unanswered = max(0, $targetCount - $answerCount);
    $rate = $targetCount > 0
        ? round(($answerCount / $targetCount) * 100, 1)
        : 0;

    echo '<div class="title-row">';
    echo '<div>';
    echo '<h1>回答集計・分析</h1>';
    echo '<div class="muted">対象アンケート：<strong>' . h($survey['title']) . '</strong></div>';
    echo '</div>';
    echo '<div class="actions">';
    echo '<a class="btn" href="index.php?screen=list">一覧へ戻る</a>';
    echo '<a class="btn" href="index.php?screen=send&id=' . rawurlencode($id) . '">送信画面</a>';
    echo '</div>';
    echo '</div>';

    echo '<div class="stat-grid">';
    renderStat('送信対象者数', $targetCount);
    renderStat('回答数', $answerCount);
    renderStat('未登録回答数', 0);
    renderStat('未回答数', $unanswered);
    echo '</div>';

    echo '<div class="card" style="margin-top:18px">';
    echo '<h2>回答率</h2>';
    echo '<div class="stat-value">' . h($rate) . '%</div>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>設問別集計</h2>';

    if ($answerCount === 0) {
        echo '<div class="empty">現在、回答データはありません</div>';
    } else {
        foreach ($survey['groups'] as $group) {
            echo '<h3 style="margin-top:22px">' . h($group['title']) . '</h3>';

            foreach ($group['questions'] as $question) {
                $counts = [];

                foreach ($question['options'] as $option) {
                    $counts[(string)$option['label']] = 0;
                }

                $textAnswers = [];

                foreach ($answers as $answer) {
                    $value = $answer['answers'][$question['id']] ?? null;

                    if (is_array($value)) {
                        foreach ($value as $v) {
                            $key = (string)$v;

                            if (isset($counts[$key])) {
                                $counts[$key]++;
                            }
                        }
                    } elseif ($value !== null && $value !== '') {
                        $key = (string)$value;

                        if (isset($counts[$key])) {
                            $counts[$key]++;
                        }

                        if ($question['type'] === QUESTION_TEXT) {
                            $textAnswers[] = $key;
                        }
                    }
                }

                echo '<div style="border:1px solid var(--border);border-radius:10px;padding:14px;margin:10px 0">';
                echo '<strong>' . h($question['number']) . ' ' . h($question['text']) . '</strong>';

                if ($question['type'] === QUESTION_TEXT) {
                    if ($textAnswers === []) {
                        echo '<div class="muted" style="margin-top:10px">回答なし</div>';
                    } else {
                        echo '<ul>';
                        foreach ($textAnswers as $text) {
                            echo '<li>' . h($text) . '</li>';
                        }
                        echo '</ul>';
                    }
                } else {
                    echo '<table style="margin-top:10px;min-width:0">';
                    echo '<thead><tr><th>選択肢</th><th>回答数</th></tr></thead>';
                    echo '<tbody>';

                    foreach ($counts as $label => $count) {
                        echo '<tr>';
                        echo '<td>' . h($label) . '</td>';
                        echo '<td>' . h($count) . '</td>';
                        echo '</tr>';
                    }

                    echo '</tbody>';
                    echo '</table>';
                }

                echo '</div>';
            }
        }
    }

    echo '</div>';

    echo '<div class="card">';
    echo '<h2>個別回答</h2>';

    if ($answers === []) {
        echo '<div class="empty">現在、回答データはありません</div>';
    } else {
        foreach ($answers as $answer) {
            echo '<details style="margin-bottom:10px">';
            echo '<summary>回答日時：' . h($answer['createdAt'] ?? '') . '</summary>';

            foreach ($survey['groups'] as $group) {
                foreach ($group['questions'] as $question) {
                    $value = $answer['answers'][$question['id']] ?? '';

                    if (is_array($value)) {
                        $value = implode(', ', $value);
                    }

                    echo '<div style="margin-top:10px">';
                    echo '<strong>' . h($question['number']) . '</strong> ';
                    echo h($question['text']);
                    echo '<br>';
                    echo h((string)$value);
                    echo '</div>';
                }
            }

            echo '</details>';
        }
    }

    echo '</div>';

    echo '<div class="card">';
    echo '<h2>出力</h2>';
    echo '<div class="actions">';
    echo '<a class="btn" href="index.php?screen=analytics&id=' . rawurlencode($id) . '&export=csv">CSV</a>';
    echo '<a class="btn" href="index.php?screen=analytics&id=' . rawurlencode($id) . '&export=pdf">PDF</a>';
    echo '</div>';
    echo '</div>';
}

function renderStat(string $label, int|float|string $value): void
{
    echo '<div class="stat">';
    echo '<div class="muted">' . h($label) . '</div>';
    echo '<div class="stat-value">' . h($value) . '</div>';
    echo '</div>';
}

function renderAnswer(): void
{
    $id = trim((string)($_GET['id'] ?? ''));

    $survey = getSurvey($id);

    if ($survey === null) {
        renderHeader('アンケート', false);
        echo '<div class="card">';
        echo '<h1>アンケートが見つかりません</h1>';
        echo '</div>';
        renderFooter();
        return;
    }

    $survey = applyAutomaticStatus($survey);

    if ($survey['status'] !== STATUS_PUBLISHED) {
        renderHeader('アンケート', false);
        echo '<div class="card center">';
        echo '<h1>このアンケートは現在回答できません</h1>';
        echo '<p>公開中のアンケートではありません。</p>';
        echo '</div>';
        renderFooter();
        return;
    }

    renderHeader($survey['title'], false);

    echo '<div class="card answer-card">';
    echo '<h1>' . h($survey['title']) . '</h1>';

    if ($survey['description'] !== '') {
        echo '<p>' . nl2br(h($survey['description'])) . '</p>';
    }

    echo '</div>';

    echo '<form method="post" data-loading>';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="submit_answer">';
    echo '<input type="hidden" name="survey_id" value="' . h($id) . '">';

    foreach ($survey['groups'] as $group) {
        echo '<div class="card answer-card">';
        echo '<h2>' . h($group['title']) . '</h2>';

        foreach ($group['questions'] as $question) {
            echo '<div class="answer-question">';
            echo '<label>';
            echo h($question['number']) . ' ' . h($question['text']);

            if ($question['required']) {
                echo ' <span style="color:#dc2626">必須</span>';
            }

            echo '</label>';

            if ($question['type'] === QUESTION_TEXT) {
                echo '<textarea name="answer[' . h($question['id']) . ']"';
                echo $question['required'] ? ' required' : '';
                echo '></textarea>';
            } else {
                $inputType = $question['type'] === QUESTION_MULTI
                    ? 'checkbox'
                    : 'radio';

                foreach ($question['options'] as $option) {
                    echo '<label class="answer-option">';
                    echo '<input type="' . h($inputType) . '"';
                    echo ' name="answer[' . h($question['id']) . ']' .
                        ($inputType === 'checkbox' ? '[]' : '') .
                        '"';
                    echo ' value="' . h($option['label']) . '"';
                    echo $question['required'] ? ' required' : '';
                    echo '>';
                    echo h($option['label']);
                    echo '</label>';
                }
            }

            echo '</div>';
        }

        echo '</div>';
    }

    echo '<div class="mobile-actions">';
    echo '<span class="muted">入力内容を確認して送信してください。</span>';
    echo '<button class="btn btn-primary" type="submit">';
    echo '<span class="loading-label">確認画面へ</span>';
    echo '<span class="spinner"></span>';
    echo '</button>';
    echo '</div>';

    echo '</form>';

    renderFooter();
}

function renderConfirm(): void
{
    $id = trim((string)($_GET['id'] ?? ''));

    $survey = getSurvey($id);
    $pending = $_SESSION['pending_answer'] ?? null;

    if (
        $survey === null ||
        !is_array($pending) ||
        (string)($pending['surveyId'] ?? '') !== $id
    ) {
        flash('danger', '回答確認情報がありません。最初から回答してください。');
        redirectTo('answer', ['id' => $id]);
    }

    $answers = $pending['answers'] ?? [];

    renderHeader('回答確認', false);

    echo '<div class="card">';
    echo '<h1>回答確認</h1>';
    echo '<p>以下の内容で送信します。</p>';
    echo '</div>';

    echo '<div class="card">';

    foreach ($survey['groups'] as $group) {
        echo '<h2>' . h($group['title']) . '</h2>';

        foreach ($group['questions'] as $question) {
            $value = $answers[$question['id']] ?? '';

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            echo '<div style="border-bottom:1px solid var(--border);padding:14px 0">';
            echo '<div class="muted">' . h($question['number']) . '</div>';
            echo '<strong>' . h($question['text']) . '</strong>';
            echo '<div style="margin-top:7px;white-space:pre-wrap">' . h((string)$value) . '</div>';
            echo '</div>';
        }
    }

    echo '</div>';

    echo '<div class="actions">';

    echo '<a class="btn" href="index.php?screen=answer&id=' . rawurlencode($id) . '">戻る</a>';

    echo '<form method="post" data-loading data-confirm="回答を送信します。よろしいですか？">';
    echo '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
    echo '<input type="hidden" name="action" value="finalize_answer">';
    echo '<input type="hidden" name="survey_id" value="' . h($id) . '">';
    echo '<button class="btn btn-primary">送信する</button>';
    echo '</form>';

    echo '</div>';

    renderFooter();
}

function processFinalizeAnswerIfNeeded(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = postAction();

    if ($action !== 'finalize_answer') {
        return;
    }

    verifyCsrf();

    $surveyId = trim((string)($_POST['survey_id'] ?? ''));
    $pending = $_SESSION['pending_answer'] ?? null;

    if (
        !is_array($pending) ||
        (string)($pending['surveyId'] ?? '') !== $surveyId
    ) {
        throw new AppException(
            '回答セッションが失われました。最初から回答してください。',
            'session',
            400
        );
    }

    $answers = is_array($pending['answers'] ?? null)
        ? $pending['answers']
        : [];

    $answerId = saveAnswer($surveyId, $answers);

    unset($_SESSION['pending_answer']);

    redirectTo(
        'complete',
        [
            'id' => $surveyId,
            'answer' => $answerId,
        ]
    );
}

function renderComplete(): void
{
    $id = trim((string)($_GET['id'] ?? ''));

    renderHeader('回答完了', false);

    echo '<div class="card center">';
    echo '<h1>回答ありがとうございました</h1>';
    echo '<p>回答を受け付けました。</p>';
    echo '</div>';

    /*
     * 回答者画面から管理者画面への導線を設けない。
     */
    renderFooter();
}

function exportCsv(): never
{
    $id = trim((string)($_GET['id'] ?? ''));

    $survey = getSurvey($id);

    if ($survey === null) {
        http_response_code(404);
        exit('Survey not found');
    }

    $answers = getAnswersForSurvey($id);

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey-' .
        preg_replace('/[^a-zA-Z0-9_-]/', '_', $id) .
        '.csv"'
    );

    $fp = fopen('php://output', 'wb');

    if ($fp === false) {
        exit;
    }

    fwrite($fp, "\xEF\xBB\xBF");

    $header = [
        '回答ID',
        '回答日時',
    ];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $header[] =
                $question['number'] .
                ' ' .
                $question['text'];
        }
    }

    fputcsv($fp, $header);

    foreach ($answers as $answer) {
        $row = [
            $answer['id'] ?? '',
            $answer['createdAt'] ?? '',
        ];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                $value = $answer['answers'][$question['id']] ?? '';

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

function exportPdf(): never
{
    $id = trim((string)($_GET['id'] ?? ''));

    $survey = getSurvey($id);

    if ($survey === null) {
        http_response_code(404);
        exit('Survey not found');
    }

    $answers = getAnswersForSurvey($id);

    /*
     * 外部PDFライブラリに依存せず、
     * 実データを含む最小PDFを生成する。
     *
     * 日本語フォント埋め込みを行わないため、
     * PDF本文はASCII化した概要を出力する。
     */
    $lines = [];

    $lines[] = 'Survey: ' . asciiPdfText($survey['title']);
    $lines[] = 'Answers: ' . count($answers);
    $lines[] = 'Generated: ' . nowIso();

    foreach ($answers as $index => $answer) {
        $lines[] = 'Answer ' . ($index + 1) . ': ' .
            asciiPdfText((string)($answer['createdAt'] ?? ''));
    }

    $pdf = makeSimplePdf($lines);

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="survey-' .
        preg_replace('/[^a-zA-Z0-9_-]/', '_', $id) .
        '.pdf"'
    );
    header('Content-Length: ' . strlen($pdf));

    echo $pdf;
    exit;
}

function asciiPdfText(string $value): string
{
    $value = preg_replace('/[^\x20-\x7E]/', '?', $value);

    return str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        (string)$value
    );
}

function makeSimplePdf(array $lines): string
{
    $stream = "BT\n/F1 10 Tf\n50 780 Td\n";

    foreach ($lines as $index => $line) {
        if ($index > 0) {
            $stream .= "0 -16 Td\n";
        }

        $stream .= '(' . asciiPdfText((string)$line) . ") Tj\n";
    }

    $stream .= "ET\n";

    $objects = [];

    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[] =
        '<< /Type /Page /Parent 2 0 R ' .
        '/MediaBox [0 0 595 842] ' .
        '/Resources << /Font << /F1 5 0 R >> >> ' .
        '/Contents 4 0 R >>';
    $objects[] =
        '<< /Length ' .
        strlen($stream) .
        " >>\nstream\n" .
        $stream .
        'endstream';
    $objects[] =
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $number => $object) {
        $objectNumber = $number + 1;
        $offsets[$objectNumber] = strlen($pdf);

        $pdf .=
            $objectNumber .
            " 0 obj\n" .
            $object .
            "\nendobj\n";
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

    $pdf .=
        "trailer\n" .
        "<< /Size " .
        (count($objects) + 1) .
        " /Root 1 0 R >>\n" .
        "startxref\n" .
        $xref .
        "\n%%EOF";

    return $pdf;
}

function renderExportIfRequested(): void
{
    $screen = currentScreen();
    $export = trim((string)($_GET['export'] ?? ''));

    if ($screen !== 'analytics' || $export === '') {
        return;
    }

    if ($export === 'csv') {
        exportCsv();
    }

    if ($export === 'pdf') {
        exportPdf();
    }

    throw new AppException(
        '不正な出力形式です。',
        'input',
        400
    );
}

function renderErrorPage(Throwable $e): void
{
    $status = 500;
    $type = 'system';

    if ($e instanceof AppException) {
        $status = $e->getCode() >= 400 && $e->getCode() <= 599
            ? $e->getCode()
            : 500;

        $type = $e->type;
    }

    http_response_code($status);

    $message = safeExceptionMessage($e);

    echo '<!doctype html>';
    echo '<html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>エラー - ' . h(APP_NAME) . '</title>';
    echo '<style>';
    echo 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;background:#f8fafc;color:#1e293b;margin:0;padding:30px}';
    echo '.box{max-width:760px;margin:50px auto;background:#fff;border:1px solid #dbe2ea;border-radius:12px;padding:28px;box-shadow:0 4px 18px rgba(15,23,42,.08)}';
    echo '.danger{background:#fee2e2;color:#991b1b;border-radius:8px;padding:14px}';
    echo 'a{display:inline-block;margin-top:20px;color:#2563eb}';
    echo '</style></head><body>';

    echo '<div class="box">';
    echo '<h1>処理に失敗しました</h1>';
    echo '<div class="danger">' . h($message) . '</div>';

    if ($type === 'authentication') {
        echo '<p>認証設定を確認してください。パスワードや認証ヘッダーそのものは画面へ表示しません。</p>';
    } elseif ($type === 'network') {
        echo '<p>接続先、Proxy、SSL設定、ネットワーク、タイムアウト設定を確認してください。</p>';
    } elseif ($type === 'session') {
        echo '<p>ブラウザのCookieが有効であることを確認し、画面を再読み込みして再度操作してください。</p>';
    } elseif ($type === 'config') {
        echo '<p>設定画面の入力内容を確認してください。</p>';
    }

    echo '<a href="index.php?screen=list">アンケート一覧へ戻る</a>';
    echo '</div>';

    echo '</body></html>';
}

function dispatch(): void
{
    /*
     * 回答確認→完了だけは専用POST処理。
     */
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        postAction() === 'finalize_answer'
    ) {
        processFinalizeAnswerIfNeeded();
        return;
    }

    processPost();

    renderExportIfRequested();

    $screen = currentScreen();

    switch ($screen) {
        case 'list':
            renderHeader('アンケート一覧');
            renderList();
            renderFooter();
            return;

        case 'edit':
            renderHeader('アンケート作成・編集');
            renderEdit();
            renderFooter();
            return;

        case 'preview':
            renderHeader('プレビュー');
            renderPreview();
            renderFooter();
            return;

        case 'send':
            $id = trim((string)($_GET['id'] ?? ''));

            if ($id === '' || getSurvey($id) === null) {
                flash(
                    'danger',
                    '送信対象アンケートが特定できません。'
                );
                redirectTo('list');
            }

            renderHeader('顧客選択・メール送信');
            renderSend();
            renderFooter();
            return;

        case 'analytics':
            $id = trim((string)($_GET['id'] ?? ''));

            if ($id === '' || getSurvey($id) === null) {
                flash(
                    'danger',
                    '集計対象アンケートが特定できません。'
                );
                redirectTo('list');
            }

            renderHeader('回答集計・分析');
            renderAnalytics();
            renderFooter();
            return;

        case 'kintone':
            renderHeader('kintone連携設定');
            renderKintone();
            renderFooter();
            return;

        case 'mail':
            renderHeader('メールサーバ設定');
            renderMail();
            renderFooter();
            return;

        case 'answer':
            renderAnswer();
            return;

        case 'confirm':
            renderConfirm();
            return;

        case 'complete':
            renderComplete();
            return;

        default:
            redirectTo('list');
    }
}

try {
    /*
     * セッション開始は全画面共通。
     *
     * 重要:
     * GETアクセスごとのsession_regenerate_id()は行わない。
     * これによりGET→POST間でCSRFトークンを含む
     * 同一セッションを維持する。
     */
    startAppSession();

    /*
     * PHP構文・設定環境上の初期化確認。
     */
    ensureDataDirectory();

    dispatch();
} catch (Throwable $e) {
    renderErrorPage($e);
}
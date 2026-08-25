<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 * PHP 8.5 / Apache 2.4
 * DB不使用 / index.php + JSON
 */

const SURVEY_STORAGE_DIRECTORY = __DIR__ . '/survey_storage';
const SURVEY_STORAGE_FILE      = SURVEY_STORAGE_DIRECTORY . '/survey_data.json';
const SURVEY_ADMIN_SESSION     = 'survey_admin_session_v1';

const APP_NAME = 'アンケート管理システム';
const TEST_MAIL_SUBJECT = 'アンケート管理システム SMTP送信テスト';

const ALLOWED_SURVEY_STATUSES = ['draft', 'active', 'ended'];

date_default_timezone_set('Asia/Tokyo');

ini_set('display_errors', '0');
ini_set('log_errors', '1');

session_name(SURVEY_ADMIN_SESSION);
session_set_cookie_params([
    'httponly' => true,
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'samesite' => 'Lax',
    'path'     => '/',
]);
session_start();

set_exception_handler(function (Throwable $e): void {
    if (is_api_request()) {
        send_json([
            'ok' => false,
            'message' => 'サーバー側で処理に失敗しました。',
            'error_type' => 'server_error',
        ], 500);
    }

    http_response_code(500);
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><title>'
        . h(APP_NAME)
        . '</title></head><body><h1>'
        . h(APP_NAME)
        . '</h1><p>サーバー側で処理に失敗しました。</p></body></html>';
    exit;
});

/* =========================================================
 * 基本ユーティリティ
 * ======================================================= */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_api_request(): bool
{
    return isset($_GET['action']) || isset($_POST['action']);
}

function current_api_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = $_SERVER['SCRIPT_NAME'] ?? '/index.php';

    return $scheme . '://' . $host . $path;
}

function send_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function request_input(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');

        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            send_json([
                'ok' => false,
                'message' => 'リクエストデータが正しくありません。',
                'error_type' => 'invalid_json',
            ], 400);
        }

        return $decoded;
    }

    return $_POST;
}

function random_id(string $prefix = ''): string
{
    return $prefix . bin2hex(random_bytes(12));
}

function now_iso(): string
{
    return date('c');
}

function safe_string(mixed $value, int $max = 10000): string
{
    $value = trim((string)$value);

    if (mb_strlen($value) > $max) {
        $value = mb_substr($value, 0, $max);
    }

    return $value;
}

function safe_int(mixed $value, int $default = 0): int
{
    if (is_int($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int)$value;
    }

    return $default;
}

/* =========================================================
 * セッション・認証・CSRF
 * ======================================================= */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $input = request_input();

    $token = $input['csrf_token']
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'] ?? '', $token)
    ) {
        send_json([
            'ok' => false,
            'message' => 'セキュリティ確認に失敗しました。画面を再読み込みしてください。',
            'error_type' => 'csrf',
        ], 403);
    }
}

function admin_is_authenticated(): bool
{
    return !empty($_SESSION['admin_authenticated']);
}

function admin_login(string $username, string $password): bool
{
    /*
     * 本番環境では以下の環境変数を設定する。
     *
     * SURVEY_ADMIN_USER
     * SURVEY_ADMIN_PASSWORD
     *
     * 未設定の場合はログイン不能とする。
     */
    $expectedUser = getenv('SURVEY_ADMIN_USER');
    $expectedPass = getenv('SURVEY_ADMIN_PASSWORD');

    if ($expectedUser === false || $expectedPass === false) {
        return false;
    }

    if (
        hash_equals((string)$expectedUser, $username) &&
        hash_equals((string)$expectedPass, $password)
    ) {
        session_regenerate_id(true);
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_user'] = $username;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        return true;
    }

    return false;
}

function require_admin(): void
{
    if (!admin_is_authenticated()) {
        send_json([
            'ok' => false,
            'message' => '管理画面への認証が必要です。',
            'error_type' => 'authentication',
        ], 401);
    }
}

/* =========================================================
 * JSONストレージ
 * ======================================================= */

function initial_data(): array
{
    return [
        'surveys' => [],
        'responses' => [],
        'customers' => [],
        'settings' => [
            'kintone' => [],
            'smtp' => [],
        ],
        'mail_logs' => [],
    ];
}

function validate_data_structure(array $data): array
{
    $required = [
        'surveys',
        'responses',
        'customers',
        'settings',
        'mail_logs',
    ];

    foreach ($required as $key) {
        if (!array_key_exists($key, $data)) {
            throw new RuntimeException('JSONデータの必須キーが不足しています。');
        }
    }

    if (!is_array($data['surveys'])) {
        throw new RuntimeException('surveysの形式が不正です。');
    }

    if (!is_array($data['responses'])) {
        throw new RuntimeException('responsesの形式が不正です。');
    }

    if (!is_array($data['customers'])) {
        throw new RuntimeException('customersの形式が不正です。');
    }

    if (!is_array($data['settings'])) {
        throw new RuntimeException('settingsの形式が不正です。');
    }

    if (!isset($data['settings']['kintone']) || !is_array($data['settings']['kintone'])) {
        $data['settings']['kintone'] = [];
    }

    if (!isset($data['settings']['smtp']) || !is_array($data['settings']['smtp'])) {
        $data['settings']['smtp'] = [];
    }

    if (!is_array($data['mail_logs'])) {
        throw new RuntimeException('mail_logsの形式が不正です。');
    }

    return $data;
}

function ensure_storage_directory(): void
{
    if (is_dir(SURVEY_STORAGE_DIRECTORY)) {
        return;
    }

    if (!mkdir(SURVEY_STORAGE_DIRECTORY, 0750, true) && !is_dir(SURVEY_STORAGE_DIRECTORY)) {
        throw new RuntimeException('データ保存ディレクトリを作成できません。');
    }
}

function load_data(bool $allowCreate = true): array
{
    if (!file_exists(SURVEY_STORAGE_FILE)) {
        if (!$allowCreate) {
            throw new RuntimeException('JSONファイルが存在しません。');
        }

        ensure_storage_directory();

        $data = initial_data();
        save_data($data);

        return $data;
    }

    if (!is_readable(SURVEY_STORAGE_FILE)) {
        throw new RuntimeException('JSONファイルを読み込めません。');
    }

    $raw = file_get_contents(SURVEY_STORAGE_FILE);

    if ($raw === false) {
        throw new RuntimeException('JSONファイルの読み込みに失敗しました。');
    }

    if (trim($raw) === '') {
        throw new RuntimeException('JSONファイルが空です。既存データを初期化していません。');
    }

    $data = json_decode($raw, true);

    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('JSONファイルが破損しています。既存データを初期化していません。');
    }

    return validate_data_structure($data);
}

function save_data(array $data): void
{
    $data = validate_data_structure($data);
    ensure_storage_directory();

    $encoded = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE |
        JSON_THROW_ON_ERROR
    );

    $verify = json_decode($encoded, true);

    if (!is_array($verify) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('保存前のJSON検証に失敗しました。');
    }

    $lockFile = SURVEY_STORAGE_FILE . '.lock';

    $lock = fopen($lockFile, 'c');

    if ($lock === false) {
        throw new RuntimeException('排他制御用ファイルを開けません。');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('排他ロックを取得できません。');
        }

        $tmp = tempnam(SURVEY_STORAGE_DIRECTORY, 'survey_');

        if ($tmp === false) {
            throw new RuntimeException('一時ファイルを作成できません。');
        }

        try {
            $written = file_put_contents(
                $tmp,
                $encoded,
                LOCK_EX
            );

            if ($written === false || $written !== strlen($encoded)) {
                throw new RuntimeException('一時ファイルへの保存に失敗しました。');
            }

            $checkRaw = file_get_contents($tmp);

            if ($checkRaw === false || trim($checkRaw) === '') {
                throw new RuntimeException('一時ファイルの検証に失敗しました。');
            }

            $check = json_decode($checkRaw, true);

            if (!is_array($check) || json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('一時ファイルのJSON検証に失敗しました。');
            }

            /*
             * rename() が同一ファイルシステム上で原子的に行われることを前提とする。
             */
            if (!rename($tmp, SURVEY_STORAGE_FILE)) {
                throw new RuntimeException('正式ファイルへの置換に失敗しました。');
            }

            @chmod(SURVEY_STORAGE_FILE, 0640);
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }

        flock($lock, LOCK_UN);
    } finally {
        fclose($lock);
    }
}

/* =========================================================
 * 秘匿情報除去
 * ======================================================= */

function public_settings(array $settings): array
{
    $k = $settings['kintone'] ?? [];
    $s = $settings['smtp'] ?? [];

    $kPublic = $k;
    $sPublic = $s;

    foreach ([
        'password',
        'api_token',
        'token',
        'authorization',
    ] as $key) {
        unset($kPublic[$key]);
    }

    foreach ([
        'password',
        'api_token',
        'token',
        'authorization',
    ] as $key) {
        unset($sPublic[$key]);
    }

    $kPublic['password_configured'] = !empty($k['password']);
    $sPublic['password_configured'] = !empty($s['password']);

    if (isset($kPublic['login_name'])) {
        $kPublic['login_name'] = (string)$kPublic['login_name'];
    }

    return [
        'kintone' => $kPublic,
        'smtp' => $sPublic,
    ];
}

function public_initial_data(array $data): array
{
    $copy = $data;
    $copy['settings'] = public_settings($data['settings']);

    return $copy;
}

/* =========================================================
 * アンケート構造
 * ======================================================= */

function normalize_question(array $question): array
{
    $type = $question['type'] ?? 'text';

    $allowedTypes = [
        'text',
        'textarea',
        'single',
        'multiple',
        'number',
        'email',
        'date',
    ];

    if (!in_array($type, $allowedTypes, true)) {
        $type = 'text';
    }

    $choices = [];

    if (is_array($question['choices'] ?? null)) {
        foreach ($question['choices'] as $choice) {
            if (!is_array($choice)) {
                continue;
            }

            $choiceId = safe_string($choice['id'] ?? '');

            if ($choiceId === '') {
                $choiceId = random_id('choice_');
            }

            $choices[] = [
                'id' => $choiceId,
                'label' => safe_string($choice['label'] ?? '', 1000),
                'branch_to' => safe_string($choice['branch_to'] ?? ''),
            ];
        }
    }

    return [
        'id' => safe_string($question['id'] ?? '') ?: random_id('q_'),
        'number' => safe_string($question['number'] ?? ''),
        'text' => safe_string($question['text'] ?? '', 5000),
        'type' => $type,
        'required' => !empty($question['required']),
        'choices' => $choices,
        'help' => safe_string($question['help'] ?? '', 5000),
    ];
}

function normalize_group(array $group): array
{
    $questions = [];

    if (is_array($group['questions'] ?? null)) {
        foreach ($group['questions'] as $question) {
            if (is_array($question)) {
                $questions[] = normalize_question($question);
            }
        }
    }

    return [
        'id' => safe_string($group['id'] ?? '') ?: random_id('g_'),
        'name' => safe_string($group['name'] ?? '', 1000),
        'description' => safe_string($group['description'] ?? '', 5000),
        'questions' => $questions,
    ];
}

function normalize_survey(array $survey): array
{
    $status = safe_string($survey['status'] ?? 'draft');

    if (!in_array($status, ALLOWED_SURVEY_STATUSES, true)) {
        $status = 'draft';
    }

    $groups = [];

    if (is_array($survey['groups'] ?? null)) {
        foreach ($survey['groups'] as $group) {
            if (is_array($group)) {
                $groups[] = normalize_group($group);
            }
        }
    }

    if (!$groups) {
        $groups[] = [
            'id' => random_id('g_'),
            'name' => 'グループ1',
            'description' => '',
            'questions' => [],
        ];
    }

    $normalized = [
        'id' => safe_string($survey['id'] ?? '') ?: random_id('survey_'),
        'title' => safe_string($survey['title'] ?? '', 500),
        'start_at' => safe_string($survey['start_at'] ?? ''),
        'end_at' => safe_string($survey['end_at'] ?? ''),
        'status' => $status,
        'numbering_mode' => safe_string($survey['numbering_mode'] ?? 'simple'),
        'allow_general_response' => !empty($survey['allow_general_response']),
        'allow_reresponse' => array_key_exists('allow_reresponse', $survey)
            ? !empty($survey['allow_reresponse'])
            : true,
        'groups' => $groups,
        'settings' => is_array($survey['settings'] ?? null)
            ? $survey['settings']
            : [],
        'deleted' => !empty($survey['deleted']),
        'created_at' => safe_string($survey['created_at'] ?? '') ?: now_iso(),
        'updated_at' => now_iso(),
    ];

    return renumber_survey($normalized);
}

function flatten_questions(array $survey): array
{
    $result = [];

    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            $result[] = $question;
        }
    }

    return $result;
}

function renumber_survey(array $survey): array
{
    $groups = $survey['groups'] ?? [];
    $simple = (($survey['numbering_mode'] ?? 'simple') !== 'group');

    $questionIndex = 0;

    foreach ($groups as $groupIndex => &$group) {
        $groupNumber = $groupIndex + 1;

        foreach ($group['questions'] ?? [] as &$question) {
            $questionIndex++;

            $question['number'] = $simple
                ? 'Q' . $questionIndex
                : 'Q' . $groupNumber . '-' . count(
                    array_filter(
                        array_slice(
                            $group['questions'],
                            0,
                            array_search($question, $group['questions'], true) + 1
                        )
                    )
                );
        }

        unset($question);
    }

    unset($group);

    $validIds = [];

    foreach ($groups as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            $validIds[$question['id']] = true;
        }
    }

    /*
     * 分岐先は後続質問のみ有効とする。
     */
    $flat = flatten_questions($survey);
    $position = [];

    foreach ($flat as $index => $question) {
        $position[$question['id']] = $index;
    }

    foreach ($groups as &$group) {
        foreach ($group['questions'] as &$question) {
            if (($question['type'] ?? '') !== 'single') {
                foreach ($question['choices'] as &$choice) {
                    $choice['branch_to'] = '';
                }
                unset($choice);
                continue;
            }

            $currentPos = $position[$question['id']] ?? -1;

            foreach ($question['choices'] as &$choice) {
                $target = $choice['branch_to'] ?? '';

                if (
                    $target === '' ||
                    !isset($position[$target]) ||
                    $position[$target] <= $currentPos
                ) {
                    $choice['branch_to'] = '';
                }
            }

            unset($choice);
        }

        unset($question);
    }

    unset($group);

    $survey['groups'] = $groups;

    return $survey;
}

function find_survey(array $data, string $id): ?array
{
    foreach ($data['surveys'] as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

/* =========================================================
 * 分岐計算
 * ======================================================= */

function response_value_selected(mixed $answer, string $choiceId): bool
{
    if (is_array($answer)) {
        return in_array($choiceId, $answer, true);
    }

    return (string)$answer === $choiceId;
}

function calculate_visible_questions(array $survey, array $answers): array
{
    $questions = flatten_questions($survey);

    $visible = [];
    $nextIndex = 0;

    while ($nextIndex < count($questions)) {
        $question = $questions[$nextIndex];
        $id = $question['id'];

        $visible[$id] = true;

        $nextIndex++;

        if (($question['type'] ?? '') !== 'single') {
            continue;
        }

        $answer = $answers[$id] ?? null;

        if ($answer === null || $answer === '') {
            continue;
        }

        $branchTarget = '';

        foreach ($question['choices'] ?? [] as $choice) {
            if (response_value_selected($answer, $choice['id'])) {
                if (!empty($choice['branch_to'])) {
                    $branchTarget = $choice['branch_to'];
                    break;
                }
            }
        }

        if ($branchTarget === '') {
            continue;
        }

        $targetIndex = null;

        foreach ($questions as $i => $candidate) {
            if (($candidate['id'] ?? '') === $branchTarget) {
                $targetIndex = $i;
                break;
            }
        }

        if ($targetIndex !== null && $targetIndex > $nextIndex - 1) {
            for ($i = $nextIndex; $i < $targetIndex; $i++) {
                $visible[$questions[$i]['id']] = false;
            }

            $nextIndex = $targetIndex;
        }
    }

    foreach ($questions as $question) {
        if (!array_key_exists($question['id'], $visible)) {
            $visible[$question['id']] = true;
        }
    }

    return $visible;
}

function validate_response(array $survey, array $answers): array
{
    $visible = calculate_visible_questions($survey, $answers);
    $errors = [];

    foreach (flatten_questions($survey) as $question) {
        $id = $question['id'];

        if (($visible[$id] ?? true) === false) {
            continue;
        }

        if (!empty($question['required'])) {
            $value = $answers[$id] ?? null;

            $empty = $value === null
                || $value === ''
                || (is_array($value) && count($value) === 0);

            if ($empty) {
                $errors[$id] = 'この質問は必須です。';
                continue;
            }
        }

        $value = $answers[$id] ?? null;

        if ($value === null || $value === '') {
            continue;
        }

        switch ($question['type']) {
            case 'email':
                if (!filter_var((string)$value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$id] = 'メールアドレスの形式が正しくありません。';
                }
                break;

            case 'number':
                if (!is_numeric($value)) {
                    $errors[$id] = '数値を入力してください。';
                }
                break;

            case 'single':
                $allowed = array_column($question['choices'] ?? [], 'id');

                if (!in_array((string)$value, $allowed, true)) {
                    $errors[$id] = '選択肢が正しくありません。';
                }
                break;

            case 'multiple':
                if (!is_array($value)) {
                    $errors[$id] = '選択肢が正しくありません。';
                    break;
                }

                $allowed = array_column($question['choices'] ?? [], 'id');

                foreach ($value as $selected) {
                    if (!in_array((string)$selected, $allowed, true)) {
                        $errors[$id] = '選択肢が正しくありません。';
                        break;
                    }
                }
                break;
        }
    }

    return [
        'ok' => count($errors) === 0,
        'errors' => $errors,
        'visible' => $visible,
    ];
}

/* =========================================================
 * kintone
 * ======================================================= */

function normalize_kintone_host(string $input): string
{
    $input = trim($input);

    if ($input === '') {
        throw new InvalidArgumentException('kintoneサブドメインが設定されていません。');
    }

    if (!preg_match('~^https?://~i', $input)) {
        $input = 'https://' . $input;
    }

    $url = parse_url($input);

    if (!is_array($url) || empty($url['host'])) {
        throw new InvalidArgumentException('kintoneサブドメインの形式が正しくありません。');
    }

    $host = strtolower($url['host']);

    if (!preg_match('/^[a-z0-9][a-z0-9-]*\.cybozu\.com$/i', $host)) {
        /*
         * xxxx の形式にも対応。
         */
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/i', $host)) {
            $host .= '.cybozu.com';
        } else {
            throw new InvalidArgumentException('kintoneサブドメインの形式が正しくありません。');
        }
    }

    return 'https://' . $host;
}

function classify_http_error(?int $httpCode, string $curlError = ''): string
{
    if ($curlError !== '') {
        $lower = strtolower($curlError);

        if (str_contains($lower, 'timed out')) {
            return 'timeout';
        }

        if (
            str_contains($lower, 'could not resolve') ||
            str_contains($lower, 'name or service not known')
        ) {
            return 'dns';
        }

        if (
            str_contains($lower, 'ssl') ||
            str_contains($lower, 'certificate') ||
            str_contains($lower, 'tls')
        ) {
            return 'tls';
        }

        return 'connection';
    }

    if ($httpCode !== null) {
        if ($httpCode >= 400 && $httpCode < 500) {
            return 'http_4xx';
        }

        if ($httpCode >= 500) {
            return 'http_5xx';
        }
    }

    return 'api';
}

function kintone_request(
    array $settings,
    string $method,
    string $path,
    ?array $payload = null
): array {
    $base = normalize_kintone_host((string)($settings['subdomain'] ?? ''));
    $url = $base . $path;

    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('kintone通信を初期化できません。');
    }

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    /*
     * kintone APIではAPIトークンを優先して利用できるようにする。
     * 仕様上のログイン名・パスワードもBasic認証として利用可能。
     */
    if (!empty($settings['api_token'])) {
        $headers[] = 'X-Cybozu-API-Token: ' . $settings['api_token'];
    } elseif (
        !empty($settings['login_name']) &&
        !empty($settings['password'])
    ) {
        curl_setopt(
            $ch,
            CURLOPT_USERPWD,
            $settings['login_name'] . ':' . $settings['password']
        );
    }

    $verifySsl = !empty($settings['ssl_verify']);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    ]);

    if (!empty($settings['proxy'])) {
        $proxy = trim((string)$settings['proxy']);

        if (!preg_match('/^[^:\s]+:\d+$/', $proxy)) {
            curl_close($ch);
            throw new InvalidArgumentException('Proxyの形式はhost:portで指定してください。');
        }

        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    }

    if ($payload !== null) {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_THROW_ON_ERROR
        );

        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException(
            json_encode([
                'type' => classify_http_error($httpCode ?: null, $curlError),
                'summary' => 'kintoneサーバーへの通信に失敗しました。',
                'http_status' => $httpCode ?: null,
            ], JSON_UNESCAPED_UNICODE)
        );
    }

    $decoded = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        $errorId = is_array($decoded) ? ($decoded['id'] ?? '') : '';

        throw new RuntimeException(
            json_encode([
                'type' => classify_http_error($httpCode, ''),
                'summary' => 'kintone APIからエラー応答が返されました。',
                'http_status' => $httpCode,
                'error_id' => $errorId,
            ], JSON_UNESCAPED_UNICODE)
        );
    }

    if (!is_array($decoded)) {
        throw new RuntimeException(
            json_encode([
                'type' => 'api',
                'summary' => 'kintoneから正しいJSON応答を取得できませんでした。',
                'http_status' => $httpCode,
            ], JSON_UNESCAPED_UNICODE)
        );
    }

    return [
        'http_status' => $httpCode,
        'data' => $decoded,
    ];
}

function safe_external_error(Throwable $e, string $fallbackType): array
{
    $message = $e->getMessage();

    $decoded = json_decode($message, true);

    if (is_array($decoded)) {
        return [
            'error_type' => $decoded['type'] ?? $fallbackType,
            'error_summary' => $decoded['summary'] ?? '外部サービスとの通信に失敗しました。',
            'http_status' => $decoded['http_status'] ?? null,
        ];
    }

    return [
        'error_type' => $fallbackType,
        'error_summary' => '外部サービスとの通信に失敗しました。',
        'http_status' => null,
    ];
}

/* =========================================================
 * SMTP
 * ======================================================= */

function smtp_setting(array $settings, string $key, mixed $default = null): mixed
{
    return array_key_exists($key, $settings) ? $settings[$key] : $default;
}

function smtp_read($socket, int $timeout): array
{
    $response = '';
    $code = 0;

    stream_set_timeout($socket, $timeout);

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;

        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            $code = (int)$m[1];

            if ($m[2] === ' ') {
                break;
            }
        }
    }

    return [
        'code' => $code,
        'response' => trim($response),
    ];
}

function smtp_write($socket, string $command): void
{
    $result = fwrite($socket, $command . "\r\n");

    if ($result === false) {
        throw new RuntimeException('SMTPコマンドの送信に失敗しました。');
    }
}

function smtp_expect($socket, int $timeout, array $codes): array
{
    $result = smtp_read($socket, $timeout);

    if (!in_array($result['code'], $codes, true)) {
        throw new RuntimeException(
            json_encode([
                'type' => 'smtp_response',
                'summary' => 'SMTPサーバーから想定外の応答が返されました。',
                'smtp_code' => $result['code'],
            ], JSON_UNESCAPED_UNICODE)
        );
    }

    return $result;
}

function smtp_connect(array $settings): array
{
    $server = trim((string)smtp_setting($settings, 'server', ''));
    $port = safe_int(smtp_setting($settings, 'port', 587), 587);
    $encryption = strtolower(
        trim((string)smtp_setting($settings, 'encryption', 'starttls'))
    );
    $timeout = safe_int(smtp_setting($settings, 'timeout', 10), 10);

    if ($server === '') {
        throw new InvalidArgumentException('SMTPサーバーが設定されていません。');
    }

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException('SMTPポートが正しくありません。');
    }

    if (!in_array($encryption, ['none', 'starttls', 'ssl'], true)) {
        throw new InvalidArgumentException('SMTP暗号化方式が正しくありません。');
    }

    $host = $server;

    if ($encryption === 'ssl') {
        $host = 'ssl://' . $server;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        'tcp://' . $host . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            json_encode([
                'type' => 'connection',
                'summary' => 'SMTPサーバーへ接続できませんでした。',
                'smtp_code' => null,
            ], JSON_UNESCAPED_UNICODE)
        );
    }

    try {
        $greeting = smtp_expect($socket, $timeout, [220]);

        $hostname = $_SERVER['SERVER_NAME'] ?? 'localhost';

        smtp_write($socket, 'EHLO ' . $hostname);

        $ehlo = smtp_read($socket, $timeout);

        if ($ehlo['code'] !== 250) {
            smtp_write($socket, 'HELO ' . $hostname);
            smtp_expect($socket, $timeout, [250]);
        }

        if ($encryption === 'starttls') {
            smtp_write($socket, 'STARTTLS');
            smtp_expect($socket, $timeout, [220]);

            $cryptoOk = @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($cryptoOk !== true) {
                throw new RuntimeException(
                    json_encode([
                        'type' => 'tls',
                        'summary' => 'SMTPのTLS接続を確立できませんでした。',
                    ], JSON_UNESCAPED_UNICODE)
                );
            }

            smtp_write($socket, 'EHLO ' . $hostname);
            smtp_expect($socket, $timeout, [250]);
        }

        $username = (string)smtp_setting($settings, 'username', '');
        $password = (string)smtp_setting($settings, 'password', '');
        $auth = !empty($settings['authentication']);

        if ($auth) {
            if ($username === '' || $password === '') {
                throw new InvalidArgumentException(
                    'SMTP認証を使用する場合はユーザー名とパスワードが必要です。'
                );
            }

            smtp_write($socket, 'AUTH LOGIN');
            smtp_expect($socket, $timeout, [334]);

            smtp_write($socket, base64_encode($username));
            smtp_expect($socket, $timeout, [334]);

            smtp_write($socket, base64_encode($password));
            smtp_expect($socket, $timeout, [235]);
        }

        return [
            'socket' => $socket,
            'timeout' => $timeout,
            'greeting_code' => $greeting['code'],
        ];
    } catch (Throwable $e) {
        fclose($socket);
        throw $e;
    }
}

function smtp_quit($socket, int $timeout): void
{
    try {
        smtp_write($socket, 'QUIT');
        smtp_read($socket, $timeout);
    } catch (Throwable) {
        // 終了処理のエラーは無視する。
    }

    fclose($socket);
}

function smtp_send_message(
    array $settings,
    string $to,
    string $subject,
    string $body
): array {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('宛先メールアドレスが正しくありません。');
    }

    $from = trim((string)smtp_setting($settings, 'from_email', ''));

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('送信元メールアドレスが正しくありません。');
    }

    $fromName = trim((string)smtp_setting($settings, 'from_name', APP_NAME));

    $conn = smtp_connect($settings);
    $socket = $conn['socket'];
    $timeout = $conn['timeout'];

    try {
        smtp_write($socket, 'MAIL FROM:<' . $from . '>');
        smtp_expect($socket, $timeout, [250]);

        smtp_write($socket, 'RCPT TO:<' . $to . '>');
        smtp_expect($socket, $timeout, [250, 251]);

        smtp_write($socket, 'DATA');
        smtp_expect($socket, $timeout, [354]);

        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

        $headers = [];
        $headers[] = 'From: ' . $encodedFromName . ' <' . $from . '>';
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        $message = implode("\r\n", $headers)
            . "\r\n\r\n"
            . preg_replace('/\r?\n/', "\r\n", $body);

        /*
         * DATA終端処理。
         */
        $message = preg_replace('/^\./m', '..', $message);

        smtp_write($socket, $message . "\r\n.");
        $result = smtp_expect($socket, $timeout, [250]);

        smtp_quit($socket, $timeout);

        return [
            'smtp_code' => $result['code'],
        ];
    } catch (Throwable $e) {
        fclose($socket);
        throw $e;
    }
}

/* =========================================================
 * API補助
 * ======================================================= */

function require_post(): void
{
    if (request_method() !== 'POST') {
        send_json([
            'ok' => false,
            'message' => 'この操作はPOSTで実行してください。',
            'error_type' => 'http',
        ], 405);
    }
}

function require_state_change_security(): array
{
    require_post();
    require_admin();
    verify_csrf();

    return request_input();
}

function find_question(array $survey, string $questionId): ?array
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

function sanitize_response_answers(array $survey, array $answers): array
{
    $result = [];

    foreach (flatten_questions($survey) as $question) {
        $id = $question['id'];

        if (!array_key_exists($id, $answers)) {
            continue;
        }

        $value = $answers[$id];

        if (is_array($value)) {
            $clean = [];

            foreach ($value as $item) {
                $clean[] = safe_string($item, 2000);
            }

            $result[$id] = array_values(array_unique($clean));
        } else {
            $result[$id] = safe_string($value, 10000);
        }
    }

    return $result;
}

/* =========================================================
 * API Action
 * ======================================================= */

function handle_api(): never
{
    $action = safe_string($_GET['action'] ?? $_POST['action'] ?? '');

    switch ($action) {

        /* -------------------------------------------------
         * 認証
         * ------------------------------------------------ */

        case 'login':
            require_post();

            $input = request_input();

            $username = safe_string($input['username'] ?? '', 200);
            $password = (string)($input['password'] ?? '');

            if (!admin_login($username, $password)) {
                send_json([
                    'ok' => false,
                    'message' => 'ユーザー名またはパスワードが正しくありません。',
                    'error_type' => 'authentication',
                ], 401);
            }

            send_json([
                'ok' => true,
                'csrf_token' => csrf_token(),
            ]);
            break;

        case 'logout':
            require_post();

            $_SESSION = [];

            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();

                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'] ?? '',
                    $params['secure'],
                    $params['httponly']
                );
            }

            session_destroy();

            send_json(['ok' => true]);
            break;

        case 'get_initial_data':
            /*
             * 公開回答画面でも利用できるよう、読み取り自体は公開。
             * ただし秘匿設定は必ず除去する。
             */
            if (request_method() !== 'GET' && request_method() !== 'POST') {
                send_json([
                    'ok' => false,
                    'message' => 'HTTPメソッドが正しくありません。',
                    'error_type' => 'http',
                ], 405);
            }

            try {
                $data = load_data(true);

                send_json([
                    'ok' => true,
                    'data' => public_initial_data($data),
                    'csrf_token' => csrf_token(),
                    'authenticated' => admin_is_authenticated(),
                ]);
            } catch (Throwable $e) {
                send_json([
                    'ok' => false,
                    'message' => '初期データを読み込めませんでした。',
                    'error_type' => 'configuration',
                    'error_summary' => 'データファイルを確認してください。',
                ], 500);
            }
            break;

        /* -------------------------------------------------
         * アンケート
         * ------------------------------------------------ */

        case 'save_survey':
            $input = require_state_change_security();

            $survey = $input['survey'] ?? null;

            if (!is_array($survey)) {
                send_json([
                    'ok' => false,
                    'message' => 'アンケートデータが正しくありません。',
                    'error_type' => 'validation',
                ], 422);
            }

            $status = safe_string($survey['status'] ?? 'draft');

            if (!in_array($status, ALLOWED_SURVEY_STATUSES, true)) {
                send_json([
                    'ok' => false,
                    'message' => 'アンケートのステータスが正しくありません。',
                    'error_type' => 'validation',
                ], 422);
            }

            $data = load_data(false);

            $normalized = normalize_survey($survey);
            $existingIndex = null;

            foreach ($data['surveys'] as $i => $item) {
                if (($item['id'] ?? '') === $normalized['id']) {
                    $existingIndex = $i;
                    break;
                }
            }

            if ($existingIndex === null) {
                $normalized['created_at'] = now_iso();
                $data['surveys'][] = $normalized;
            } else {
                $normalized['created_at'] =
                    $data['surveys'][$existingIndex]['created_at'] ?? now_iso();

                $data['surveys'][$existingIndex] = $normalized;
            }

            save_data($data);

            send_json([
                'ok' => true,
                'survey' => $normalized,
            ]);
            break;

        case 'delete_survey':
            $input = require_state_change_security();

            $id = safe_string($input['survey_id'] ?? '');

            if ($id === '') {
                send_json([
                    'ok' => false,
                    'message' => 'アンケートIDが指定されていません。',
                    'error_type' => 'validation',
                ], 422);
            }

            $data = load_data(false);
            $found = false;

            foreach ($data['surveys'] as &$survey) {
                if (($survey['id'] ?? '') === $id) {
                    $survey['deleted'] = true;
                    $survey['status'] = 'ended';
                    $survey['updated_at'] = now_iso();
                    $found = true;
                    break;
                }
            }

            unset($survey);

            if (!$found) {
                send_json([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。',
                    'error_type' => 'not_found',
                ], 404);
            }

            save_data($data);

            send_json(['ok' => true]);
            break;

        case 'duplicate_survey':
            $input = require_state_change_security();

            $id = safe_string($input['survey_id'] ?? '');

            $data = load_data(false);
            $source = find_survey($data, $id);

            if ($source === null) {
                send_json([
                    'ok' => false,
                    'message' => '複製元アンケートが見つかりません。',
                    'error_type' => 'not_found',
                ], 404);
            }

            $copy = $source;
            $copy['id'] = random_id('survey_');
            $copy['title'] = ($source['title'] ?? '') . '（複製）';
            $copy['status'] = 'draft';
            $copy['deleted'] = false;
            $copy['created_at'] = now_iso();
            $copy['updated_at'] = now_iso();

            foreach ($copy['groups'] as &$group) {
                $group['id'] = random_id('g_');

                foreach ($group['questions'] as &$question) {
                    $oldId = $question['id'];
                    $question['id'] = random_id('q_');

                    foreach ($question['choices'] as &$choice) {
                        $choice['id'] = random_id('choice_');
                        $choice['branch_to'] = '';
                    }

                    unset($choice);
                }

                unset($question);
            }

            unset($group);

            $copy = renumber_survey($copy);
            $data['surveys'][] = $copy;

            save_data($data);

            send_json([
                'ok' => true,
                'survey' => $copy,
            ]);
            break;

        /* -------------------------------------------------
         * 回答
         * ------------------------------------------------ */

        case 'get_public_survey':
            $id = safe_string($_GET['survey_id'] ?? '');

            $data = load_data(false);
            $survey = find_survey($data, $id);

            if ($survey === null || !empty($survey['deleted'])) {
                send_json([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。',
                    'error_type' => 'not_found',
                ], 404);
            }

            if (($survey['status'] ?? '') !== 'active') {
                send_json([
                    'ok' => false,
                    'message' => 'このアンケートは現在公開されていません。',
                    'error_type' => 'not_available',
                ], 403);
            }

            send_json([
                'ok' => true,
                'survey' => $survey,
            ]);
            break;

        case 'save_response_draft':
            require_post();

            $input = request_input();

            $surveyId = safe_string($input['survey_id'] ?? '');
            $answers = $input['answers'] ?? [];
            $respondent = $input['respondent'] ?? [];

            if (!is_array($answers)) {
                $answers = [];
            }

            if (!is_array($respondent)) {
                $respondent = [];
            }

            $data = load_data(false);
            $survey = find_survey($data, $surveyId);

            if ($survey === null) {
                send_json([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。',
                    'error_type' => 'not_found',
                ], 404);
            }

            $draftId = safe_string($input['draft_id'] ?? '') ?: random_id('draft_');

            $data['responses'] = array_values(
                array_filter(
                    $data['responses'],
                    static fn($r) => ($r['id'] ?? '') !== $draftId
                )
            );

            $data['responses'][] = [
                'id' => $draftId,
                'survey_id' => $surveyId,
                'status' => 'draft',
                'respondent' => [
                    'name' => safe_string($respondent['name'] ?? '', 500),
                    'email' => safe_string($respondent['email'] ?? '', 500),
                ],
                'answers' => sanitize_response_answers($survey, $answers),
                'created_at' => now_iso(),
                'updated_at' => now_iso(),
            ];

            save_data($data);

            send_json([
                'ok' => true,
                'draft_id' => $draftId,
            ]);
            break;

        case 'get_response_draft':
            require_post();

            $input = request_input();

            $draftId = safe_string($input['draft_id'] ?? '');

            $data = load_data(false);
            $found = null;

            foreach ($data['responses'] as $response) {
                if (
                    ($response['id'] ?? '') === $draftId &&
                    ($response['status'] ?? '') === 'draft'
                ) {
                    $found = $response;
                    break;
                }
            }

            if ($found === null) {
                send_json([
                    'ok' => false,
                    'message' => '回答途中データが見つかりません。',
                    'error_type' => 'not_found',
                ], 404);
            }

            send_json([
                'ok' => true,
                'response' => $found,
            ]);
            break;

        case 'validate_response':
            require_post();

            $input = request_input();

            $surveyId = safe_string($input['survey_id'] ?? '');
            $answers = $input['answers'] ?? [];

            if (!is_array($answers)) {
                $answers = [];
            }

            $data = load_data(false);
            $survey = find_survey($data, $surveyId);

            if ($survey === null) {
                send_json([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。',
                    'error_type' => 'not_found',
                ], 404);
            }

            $validation = validate_response($survey, $answers);

            send_json([
                'ok' => true,
                'valid' => $validation['ok'],
                'errors' => $validation['errors'],
                'visible' => $validation['visible'],
            ]);
            break;

        case 'submit_response':
            require_post();

            $input = request_input();

            $surveyId = safe_string($input['survey_id'] ?? '');
            $answers = $input['answers'] ?? [];
            $respondent = $input['respondent'] ?? [];
            $draftId = safe_string($input['draft_id'] ?? '');

            if (!is_array($answers)) {
                $answers = [];
            }

            if (!is_array($respondent)) {
                $respondent = [];
            }

            $data = load_data(false);
            $survey = find_survey($data, $surveyId);

            if ($survey === null || !empty($survey['deleted'])) {
                send_json([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。',
                    'error_type' => 'not_found',
                ], 404);
            }

            if (($survey['status'] ?? '') !== 'active') {
                send_json([
                    'ok' => false,
                    'message' => 'このアンケートは現在回答できません。',
                    'error_type' => 'not_available',
                ], 403);
            }

            $answers = sanitize_response_answers($survey, $answers);
            $validation = validate_response($survey, $answers);

            if (!$validation['ok']) {
                send_json([
                    'ok' => false,
                    'message' => '必須項目または回答内容を確認してください。',
                    'error_type' => 'validation',
                    'errors' => $validation['errors'],
                ], 422);
            }

            $responseId = random_id('response_');

            if ($draftId !== '') {
                $data['responses'] = array_values(
                    array_filter(
                        $data['responses'],
                        static fn($r) => ($r['id'] ?? '') !== $draftId
                    )
                );
            }

            $data['responses'][] = [
                'id' => $responseId,
                'survey_id' => $surveyId,
                'status' => 'completed',
                'respondent' => [
                    'name' => safe_string($respondent['name'] ?? '', 500),
                    'email' => safe_string($respondent['email'] ?? '', 500),
                ],
                'answers' => $answers,
                'created_at' => now_iso(),
                'completed_at' => now_iso(),
            ];

            save_data($data);

            send_json([
                'ok' => true,
                'response_id' => $responseId,
            ]);
            break;

        /* -------------------------------------------------
         * kintone設定
         * ------------------------------------------------ */

        case 'save_kintone_settings':
            $input = require_state_change_security();

            $settings = $input['settings'] ?? [];

            if (!is_array($settings)) {
                send_json([
                    'ok' => false,
                    'message' => 'kintone設定が正しくありません。',
                    'error_type' => 'validation',
                ], 422);
            }

            $data = load_data(false);
            $old = $data['settings']['kintone'] ?? [];

            $new = [
                'subdomain' => safe_string($settings['subdomain'] ?? '', 500),
                'login_name' => safe_string($settings['login_name'] ?? '', 500),
                'app_id' => safe_int($settings['app_id'] ?? 0),
                'ssl_verify' => !empty($settings['ssl_verify']),
                'proxy' => safe_string($settings['proxy'] ?? '', 500),
                'field_mapping' => is_array($settings['field_mapping'] ?? null)
                    ? $settings['field_mapping']
                    : [],
            ];

            /*
             * パスワード空欄は変更なし。
             */
            $password = (string)($settings['password'] ?? '');

            if ($password !== '') {
                $new['password'] = $password;
            } elseif (!empty($old['password'])) {
                $new['password'] = $old['password'];
            }

            /*
             * APIトークンを使用する実装にも対応。
             */
            $apiToken = (string)($settings['api_token'] ?? '');

            if ($apiToken !== '') {
                $new['api_token'] = $apiToken;
            } elseif (!empty($old['api_token'])) {
                $new['api_token'] = $old['api_token'];
            }

            /*
             * 設定保存自体ではkintone通信を実行しない。
             */
            $data['settings']['kintone'] = $new;

            save_data($data);

            send_json([
                'ok' => true,
                'settings' => public_settings($data['settings'])['kintone'],
            ]);
            break;

        case 'connect_kintone':
            require_post();
            require_admin();
            verify_csrf();

            $data = load_data(false);
            $settings = $data['settings']['kintone'] ?? [];

            try {
                $result = kintone_request(
                    $settings,
                    'GET',
                    '/k/v1/app.json?id=' . rawurlencode((string)($settings['app_id'] ?? ''))
                );

                send_json([
                    'ok' => true,
                    'message' => 'kintoneへの接続に成功しました。',
                    'result' => [
                        'target' => normalize_kintone_host((string)$settings['subdomain']),
                        'http_status' => $result['http_status'],
                        'error_type' => null,
                        'error_summary' => null,
                    ],
                ]);
            } catch (Throwable $e) {
                $error = safe_external_error($e, 'connection');

                send_json([
                    'ok' => false,
                    'message' => 'kintoneへの接続に失敗しました。',
                    'error_type' => $error['error_type'],
                    'error_summary' => $error['error_summary'],
                    'http_status' => $error['http_status'],
                    'recommended' => 'サブドメイン、アプリID、認証情報、SSL証明書検証、Proxy設定を確認してください。',
                ], 502);
            }
            break;

        case 'fetch_kintone_fields':
            require_post();
            require_admin();
            verify_csrf();

            $data = load_data(false);
            $settings = $data['settings']['kintone'] ?? [];

            try {
                $result = kintone_request(
                    $settings,
                    'GET',
                    '/k/v1/app/form/fields.json?app=' .
                    rawurlencode((string)($settings['app_id'] ?? ''))
                );

                $fields = [];

                foreach (($result['data']['properties'] ?? []) as $code => $field) {
                    $fields[] = [
                        'code' => $code,
                        'label' => $field['label'] ?? '',
                        'type' => $field['type'] ?? '',
                    ];
                }

                send_json([
                    'ok' => true,
                    'fields' => $fields,
                    'http_status' => $result['http_status'],
                ]);
            } catch (Throwable $e) {
                $error = safe_external_error($e, 'api');

                send_json([
                    'ok' => false,
                    'message' => 'kintoneのフィールド取得に失敗しました。',
                    'error_type' => $error['error_type'],
                    'error_summary' => $error['error_summary'],
                    'http_status' => $error['http_status'],
                    'recommended' => 'アプリIDとkintone権限を確認してください。',
                ], 502);
            }
            break;

        case 'sync_customers':
            require_post();
            require_admin();
            verify_csrf();

            $data = load_data(false);
            $settings = $data['settings']['kintone'] ?? [];

            try {
                $appId = safe_int($settings['app_id'] ?? 0);

                if ($appId <= 0) {
                    throw new InvalidArgumentException('顧客管理アプリIDが設定されていません。');
                }

                $result = kintone_request(
                    $settings,
                    'GET',
                    '/k/v1/records.json?app=' . rawurlencode((string)$appId)
                    . '&totalCount=true'
                );

                $records = $result['data']['records'] ?? [];

                if (!is_array($records)) {
                    $records = [];
                }

                $mapping = $settings['field_mapping'] ?? [];

                $inserted = 0;
                $updated = 0;
                $skipped = 0;
                $errors = 0;

                $existingIndex = [];

                foreach ($data['customers'] as $index => $customer) {
                    if (!empty($customer['kintone_record_id'])) {
                        $existingIndex[(string)$customer['kintone_record_id']] = $index;
                    }
                }

                foreach ($records as $record) {
                    try {
                        $recordId = (string)($record['$id']['value'] ?? '');

                        if ($recordId === '') {
                            $skipped++;
                            continue;
                        }

                        $customer = [
                            'kintone_record_id' => $recordId,
                            'updated_at' => now_iso(),
                        ];

                        foreach ($mapping as $localKey => $kintoneCode) {
                            if (!is_string($kintoneCode) || $kintoneCode === '') {
                                continue;
                            }

                            $customer[$localKey] =
                                $record[$kintoneCode]['value'] ?? '';
                        }

                        if (isset($existingIndex[$recordId])) {
                            $data['customers'][$existingIndex[$recordId]] =
                                array_merge(
                                    $data['customers'][$existingIndex[$recordId]],
                                    $customer
                                );

                            $updated++;
                        } else {
                            $customer['id'] = random_id('customer_');
                            $customer['created_at'] = now_iso();
                            $data['customers'][] = $customer;
                            $inserted++;
                        }
                    } catch (Throwable) {
                        $errors++;
                    }
                }

                save_data($data);

                send_json([
                    'ok' => true,
                    'message' => '顧客データの同期が完了しました。',
                    'result' => [
                        'count' => count($records),
                        'inserted' => $inserted,
                        'updated' => $updated,
                        'skipped' => $skipped,
                        'errors' => $errors,
                    ],
                ]);
            } catch (Throwable $e) {
                $error = safe_external_error($e, 'api');

                send_json([
                    'ok' => false,
                    'message' => '顧客データの同期に失敗しました。',
                    'error_type' => $error['error_type'],
                    'error_summary' => $error['error_summary'],
                    'http_status' => $error['http_status'],
                    'recommended' => 'kintone設定、アプリID、権限、通信環境を確認してください。',
                ], 502);
            }
            break;

        /* -------------------------------------------------
         * SMTP設定
         * ------------------------------------------------ */

        case 'save_smtp_settings':
            $input = require_state_change_security();

            $settings = $input['settings'] ?? [];

            if (!is_array($settings)) {
                send_json([
                    'ok' => false,
                    'message' => 'SMTP設定が正しくありません。',
                    'error_type' => 'validation',
                ], 422);
            }

            $data = load_data(false);
            $old = $data['settings']['smtp'] ?? [];

            $encryption = strtolower(
                safe_string($settings['encryption'] ?? 'starttls')
            );

            if (!in_array($encryption, ['none', 'starttls', 'ssl'], true)) {
                send_json([
                    'ok' => false,
                    'message' => 'SMTP暗号化方式が正しくありません。',
                    'error_type' => 'validation',
                ], 422);
            }

            $new = [
                'server' => safe_string($settings['server'] ?? '', 500),
                'port' => safe_int($settings['port'] ?? 587, 587),
                'encryption' => $encryption,
                'authentication' => !empty($settings['authentication']),
                'username' => safe_string($settings['username'] ?? '', 500),
                'from_email' => safe_string($settings['from_email'] ?? '', 500),
                'from_name' => safe_string($settings['from_name'] ?? APP_NAME, 500),
                'timeout' => safe_int($settings['timeout'] ?? 10, 10),
            ];

            $password = (string)($settings['password'] ?? '');

            if ($password !== '') {
                $new['password'] = $password;
            } elseif (!empty($old['password'])) {
                $new['password'] = $old['password'];
            }

            $data['settings']['smtp'] = $new;

            save_data($data);

            send_json([
                'ok' => true,
                'settings' => public_settings($data['settings'])['smtp'],
            ]);
            break;

        case 'test_smtp_connection':
            require_post();
            require_admin();
            verify_csrf();

            $data = load_data(false);
            $settings = $data['settings']['smtp'] ?? [];

            try {
                $conn = smtp_connect($settings);

                smtp_quit(
                    $conn['socket'],
                    $conn['timeout']
                );

                send_json([
                    'ok' => true,
                    'message' => 'SMTPサーバーへの接続に成功しました。',
                    'result' => [
                        'target' => $settings['server'] ?? '',
                        'port' => $settings['port'] ?? null,
                        'smtp_code' => $conn['greeting_code'] ?? null,
                        'error_type' => null,
                    ],
                ]);
            } catch (Throwable $e) {
                $error = safe_external_error($e, 'connection');

                send_json([
                    'ok' => false,
                    'message' => 'SMTPサーバーへの接続に失敗しました。',
                    'error_type' => $error['error_type'],
                    'error_summary' => $error['error_summary'],
                    'smtp_code' => $error['smtp_code'] ?? null,
                    'recommended' => 'SMTPサーバー、ポート、暗号化方式、認証情報、ファイアウォール設定を確認してください。',
                ], 502);
            }
            break;

        case 'send_smtp_test':
            require_post();
            require_admin();
            verify_csrf();

            $input = request_input();
            $to = safe_string($input['to'] ?? '', 500);

            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                send_json([
                    'ok' => false,
                    'message' => 'テストメール宛先が正しくありません。',
                    'error_type' => 'validation',
                ], 422);
            }

            $data = load_data(false);
            $settings = $data['settings']['smtp'] ?? [];

            try {
                $result = smtp_send_message(
                    $settings,
                    $to,
                    TEST_MAIL_SUBJECT,
                    APP_NAME . " のSMTPテストメールです。\r\n\r\n"
                    . 'このメールはSMTP設定確認のために送信されました。'
                );

                $data['mail_logs'][] = [
                    'id' => random_id('mail_'),
                    'type' => 'smtp_test',
                    'to' => $to,
                    'subject' => TEST_MAIL_SUBJECT,
                    'status' => 'sent',
                    'smtp_code' => $result['smtp_code'],
                    'created_at' => now_iso(),
                ];

                save_data($data);

                send_json([
                    'ok' => true,
                    'message' => 'テストメールを送信しました。',
                    'smtp_code' => $result['smtp_code'],
                ]);
            } catch (Throwable $e) {
                $error = safe_external_error($e, 'smtp_protocol');

                /*
                 * 認証情報はログに残さない。
                 */
                $data['mail_logs'][] = [
                    'id' => random_id('mail_'),
                    'type' => 'smtp_test',
                    'to' => $to,
                    'subject' => TEST_MAIL_SUBJECT,
                    'status' => 'failed',
                    'error_type' => $error['error_type'],
                    'smtp_code' => $error['smtp_code'] ?? null,
                    'created_at' => now_iso(),
                ];

                save_data($data);

                send_json([
                    'ok' => false,
                    'message' => 'テストメールの送信に失敗しました。',
                    'error_type' => $error['error_type'],
                    'error_summary' => $error['error_summary'],
                    'smtp_code' => $error['smtp_code'] ?? null,
                    'recommended' => 'SMTP設定、認証情報、暗号化方式、宛先、送信元アドレスを確認してください。',
                ], 502);
            }
            break;

        /* -------------------------------------------------
         * メール送信
         * ------------------------------------------------ */

        case 'send_mail':
            $input = require_state_change_security();

            $customerIds = $input['customer_ids'] ?? [];
            $subject = safe_string($input['subject'] ?? '', 500);
            $body = safe_string($input['body'] ?? '', 30000);

            if (!is_array($customerIds)) {
                $customerIds = [];
            }

            if ($subject === '' || $body === '') {
                send_json([
                    'ok' => false,
                    'message' => '件名と本文を入力してください。',
                    'error_type' => 'validation',
                ], 422);
            }

            $data = load_data(false);
            $settings = $data['settings']['smtp'] ?? [];

            $sent = 0;
            $failed = 0;
            $details = [];

            foreach ($data['customers'] as $customer) {
                $customerId = (string)($customer['id'] ?? '');

                if (!in_array($customerId, $customerIds, true)) {
                    continue;
                }

                $email = (string)($customer['email'] ?? '');

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $failed++;

                    $details[] = [
                        'customer_id' => $customerId,
                        'status' => 'failed',
                        'error_type' => 'validation',
                    ];

                    continue;
                }

                try {
                    $result = smtp_send_message(
                        $settings,
                        $email,
                        $subject,
                        $body
                    );

                    $sent++;

                    $data['mail_logs'][] = [
                        'id' => random_id('mail_'),
                        'type' => 'bulk',
                        'customer_id' => $customerId,
                        'to' => $email,
                        'subject' => $subject,
                        'status' => 'sent',
                        'smtp_code' => $result['smtp_code'],
                        'created_at' => now_iso(),
                    ];

                    $details[] = [
                        'customer_id' => $customerId,
                        'status' => 'sent',
                    ];
                } catch (Throwable $e) {
                    $failed++;

                    $error = safe_external_error($e, 'smtp_protocol');

                    $data['mail_logs'][] = [
                        'id' => random_id('mail_'),
                        'type' => 'bulk',
                        'customer_id' => $customerId,
                        'to' => $email,
                        'subject' => $subject,
                        'status' => 'failed',
                        'error_type' => $error['error_type'],
                        'created_at' => now_iso(),
                    ];

                    $details[] = [
                        'customer_id' => $customerId,
                        'status' => 'failed',
                        'error_type' => $error['error_type'],
                    ];
                }
            }

            save_data($data);

            send_json([
                'ok' => true,
                'message' => 'メール送信処理が完了しました。',
                'result' => [
                    'sent' => $sent,
                    'failed' => $failed,
                    'details' => $details,
                ],
            ]);
            break;

        case 'resend_mail':
            $input = require_state_change_security();

            $mailId = safe_string($input['mail_id'] ?? '');

            $data = load_data(false);
            $target = null;

            foreach ($data['mail_logs'] as $log) {
                if (($log['id'] ?? '') === $mailId) {
                    $target = $log;
                    break;
                }
            }

            if ($target === null) {
                send_json([
                    'ok' => false,
                    'message' => '送信履歴が見つかりません。',
                    'error_type' => 'not_found',
                ], 404);
            }

            $settings = $data['settings']['smtp'] ?? [];

            try {
                $result = smtp_send_message(
                    $settings,
                    (string)$target['to'],
                    (string)$target['subject'],
                    (string)($input['body'] ?? '')
                );

                $data['mail_logs'][] = [
                    'id' => random_id('mail_'),
                    'type' => 'resend',
                    'original_mail_id' => $mailId,
                    'to' => $target['to'],
                    'subject' => $target['subject'],
                    'status' => 'sent',
                    'smtp_code' => $result['smtp_code'],
                    'created_at' => now_iso(),
                ];

                save_data($data);

                send_json([
                    'ok' => true,
                    'message' => 'メールを再送しました。',
                ]);
            } catch (Throwable $e) {
                $error = safe_external_error($e, 'smtp_protocol');

                send_json([
                    'ok' => false,
                    'message' => 'メールの再送に失敗しました。',
                    'error_type' => $error['error_type'],
                    'error_summary' => $error['error_summary'],
                ], 502);
            }
            break;

        case 'send_reminder':
            $input = require_state_change_security();

            $customerIds = $input['customer_ids'] ?? [];
            $subject = safe_string($input['subject'] ?? 'アンケート回答のお願い', 500);
            $body = safe_string($input['body'] ?? '', 30000);

            if (!is_array($customerIds)) {
                $customerIds = [];
            }

            $data = load_data(false);
            $settings = $data['settings']['smtp'] ?? [];

            $sent = 0;
            $failed = 0;

            foreach ($data['customers'] as $customer) {
                $customerId = (string)($customer['id'] ?? '');

                if (!in_array($customerId, $customerIds, true)) {
                    continue;
                }

                $email = (string)($customer['email'] ?? '');

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $failed++;
                    continue;
                }

                try {
                    $result = smtp_send_message(
                        $settings,
                        $email,
                        $subject,
                        $body
                    );

                    $sent++;

                    $data['mail_logs'][] = [
                        'id' => random_id('mail_'),
                        'type' => 'reminder',
                        'customer_id' => $customerId,
                        'to' => $email,
                        'subject' => $subject,
                        'status' => 'sent',
                        'smtp_code' => $result['smtp_code'],
                        'created_at' => now_iso(),
                    ];
                } catch (Throwable $e) {
                    $failed++;

                    $error = safe_external_error($e, 'smtp_protocol');

                    $data['mail_logs'][] = [
                        'id' => random_id('mail_'),
                        'type' => 'reminder',
                        'customer_id' => $customerId,
                        'to' => $email,
                        'subject' => $subject,
                        'status' => 'failed',
                        'error_type' => $error['error_type'],
                        'created_at' => now_iso(),
                    ];
                }
            }

            save_data($data);

            send_json([
                'ok' => true,
                'result' => [
                    'sent' => $sent,
                    'failed' => $failed,
                ],
            ]);
            break;

        /* -------------------------------------------------
         * CSV
         * ------------------------------------------------ */

        case 'export_responses_csv':
            require_admin();

            $data = load_data(false);

            $surveyId = safe_string($_GET['survey_id'] ?? '');

            $survey = find_survey($data, $surveyId);

            if ($survey === null) {
                http_response_code(404);
                exit('アンケートが見つかりません。');
            }

            $questions = flatten_questions($survey);

            $filename = 'responses_' . preg_replace(
                '/[^a-zA-Z0-9_-]/',
                '_',
                $surveyId
            ) . '.csv';

            header('Content-Type: text/csv; charset=UTF-8');
            header(
                'Content-Disposition: attachment; filename="' .
                $filename .
                '"'
            );

            $out = fopen('php://output', 'wb');

            /*
             * Excel向けUTF-8 BOM。
             */
            fwrite($out, "\xEF\xBB\xBF");

            $header = [
                '回答ID',
                '回答日時',
                '氏名',
                'メールアドレス',
            ];

            foreach ($questions as $question) {
                $header[] = $question['number'] . ' ' . $question['text'];
            }

            fputcsv($out, $header);

            foreach ($data['responses'] as $response) {
                if (
                    ($response['survey_id'] ?? '') !== $surveyId ||
                    ($response['status'] ?? '') !== 'completed'
                ) {
                    continue;
                }

                $row = [
                    $response['id'] ?? '',
                    $response['completed_at'] ?? $response['created_at'] ?? '',
                    $response['respondent']['name'] ?? '',
                    $response['respondent']['email'] ?? '',
                ];

                foreach ($questions as $question) {
                    $value = $response['answers'][$question['id']] ?? '';

                    if (is_array($value)) {
                        $value = implode(', ', $value);
                    }

                    $row[] = $value;
                }

                fputcsv($out, $row);
            }

            fclose($out);
            exit;

        default:
            send_json([
                'ok' => false,
                'message' => '指定されたAPI Actionは存在しません。',
                'error_type' => 'not_found',
            ], 404);
    }
}

/* =========================================================
 * API実行
 * ======================================================= */

if (is_api_request()) {
    handle_api();
}

/* =========================================================
 * SPA HTML
 * ======================================================= */
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h(APP_NAME) ?></title>

<style>
:root {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --danger: #dc2626;
    --success: #15803d;
    --warning: #b45309;
    --text: #1f2937;
    --muted: #6b7280;
    --border: #d1d5db;
    --bg: #f3f4f6;
    --card: #ffffff;
    --shadow: 0 2px 12px rgba(0,0,0,.06);
}

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    background: var(--bg);
    color: var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        sans-serif;
}

button,
input,
textarea,
select {
    font: inherit;
}

button {
    cursor: pointer;
}

a {
    color: var(--primary);
}

.app-shell {
    min-height: 100vh;
}

.topbar {
    background: #111827;
    color: #fff;
    padding: 14px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
}

.topbar-title {
    font-size: 20px;
    font-weight: 700;
}

.topbar-user {
    display: flex;
    align-items: center;
    gap: 10px;
}

.layout {
    display: flex;
    min-height: calc(100vh - 60px);
}

.sidebar {
    width: 230px;
    background: #fff;
    border-right: 1px solid var(--border);
    padding: 16px;
}

.nav-button {
    display: block;
    width: 100%;
    text-align: left;
    border: 0;
    background: transparent;
    padding: 11px 12px;
    border-radius: 8px;
    margin-bottom: 5px;
    color: var(--text);
}

.nav-button:hover,
.nav-button.active {
    background: #eff6ff;
    color: var(--primary);
}

.main {
    flex: 1;
    min-width: 0;
    padding: 24px;
}

.container {
    max-width: 1400px;
    margin: 0 auto;
}

.card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 18px;
    box-shadow: var(--shadow);
}

h1,
h2,
h3 {
    margin-top: 0;
}

h1 {
    font-size: 26px;
}

h2 {
    font-size: 21px;
}

h3 {
    font-size: 17px;
}

.toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-bottom: 18px;
}

.btn {
    border: 1px solid var(--border);
    border-radius: 7px;
    background: #fff;
    padding: 9px 14px;
    color: var(--text);
}

.btn:hover {
    background: #f9fafb;
}

.btn-primary {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
}

.btn-primary:hover {
    background: var(--primary-dark);
}

.btn-danger {
    background: var(--danger);
    border-color: var(--danger);
    color: #fff;
}

.btn-success {
    background: var(--success);
    border-color: var(--success);
    color: #fff;
}

.btn-warning {
    background: var(--warning);
    border-color: var(--warning);
    color: #fff;
}

.btn-small {
    padding: 5px 9px;
    font-size: 13px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 16px;
}

.form-group {
    margin-bottom: 14px;
}

.form-group.full {
    grid-column: 1 / -1;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
}

input[type="text"],
input[type="email"],
input[type="password"],
input[type="number"],
input[type="datetime-local"],
input[type="date"],
select,
textarea {
    width: 100%;
    border: 1px solid var(--border);
    border-radius: 7px;
    padding: 9px 10px;
    background: #fff;
}

textarea {
    min-height: 100px;
    resize: vertical;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
}

th,
td {
    border-bottom: 1px solid var(--border);
    padding: 10px;
    text-align: left;
    vertical-align: top;
}

th {
    background: #f9fafb;
    white-space: nowrap;
}

.badge {
    display: inline-block;
    border-radius: 999px;
    padding: 4px 9px;
    font-size: 12px;
    font-weight: 700;
}

.badge-draft {
    background: #e5e7eb;
    color: #374151;
}

.badge-active {
    background: #dcfce7;
    color: #166534;
}

.badge-ended {
    background: #fee2e2;
    color: #991b1b;
}

.notice {
    padding: 12px 14px;
    border-radius: 8px;
    background: #eff6ff;
    color: #1e40af;
    margin-bottom: 15px;
}

.notice-error {
    background: #fef2f2;
    color: #991b1b;
}

.notice-success {
    background: #f0fdf4;
    color: #166534;
}

.notice-warning {
    background: #fffbeb;
    color: #92400e;
}

.error-screen {
    max-width: 720px;
    margin: 100px auto;
    padding: 30px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow);
}

.loading-screen {
    display: flex;
    min-height: 70vh;
    align-items: center;
    justify-content: center;
}

.spinner {
    width: 34px;
    height: 34px;
    border: 4px solid #dbeafe;
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin .8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.group-card {
    border: 1px solid var(--border);
    border-radius: 9px;
    margin-bottom: 18px;
    background: #fafafa;
}

.group-header {
    padding: 13px;
    background: #f3f4f6;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}

.question-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 15px;
    margin: 12px;
}

.choice-row {
    display: grid;
    grid-template-columns: minmax(0,1fr) 220px auto;
    gap: 8px;
    align-items: center;
    margin-bottom: 8px;
}

.answer-question {
    border-bottom: 1px solid var(--border);
    padding-bottom: 18px;
    margin-bottom: 18px;
}

.required {
    color: var(--danger);
}

.modal {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.5);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 1000;
}

.modal.open {
    display: flex;
}

.modal-content {
    background: #fff;
    width: min(1000px, 100%);
    max-height: 90vh;
    overflow: auto;
    border-radius: 10px;
    padding: 22px;
}

.kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap: 14px;
}

.kpi {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 9px;
    padding: 16px;
}

.kpi-value {
    font-size: 28px;
    font-weight: 700;
    margin-top: 4px;
}

.muted {
    color: var(--muted);
}

.login-screen {
    max-width: 420px;
    margin: 80px auto;
    padding: 30px;
    background: #fff;
    border-radius: 12px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
}

.hidden {
    display: none !important;
}

@media (max-width: 900px) {
    .layout {
        display: block;
    }

    .sidebar {
        width: 100%;
        border-right: 0;
        border-bottom: 1px solid var(--border);
        display: flex;
        overflow-x: auto;
    }

    .nav-button {
        width: auto;
        white-space: nowrap;
    }

    .form-grid,
    .kpi-grid {
        grid-template-columns: 1fr;
    }

    .choice-row {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<div id="app"></div>

<script>
"use strict";

window.App = (() => {
    const state = {
        initialization: "uninitialized",
        apiUrl: "",
        csrfToken: "",
        authenticated: false,
        data: null,
        screen: "surveys",
        editingSurvey: null,
        selectedSurveyId: null,
        responseSurveyId: null,
        responseAnswers: {},
        responseRespondent: {},
        responseDraftId: "",
        previewOpen: false,
        responseModalOpen: false,
        notice: null,
        loginRequired: false,
        error: null
    };

    const App = {
        state,

        render,
        api,
        actions: {},
        utils: {},
        init,
        initSortable
    };

    /* =====================================================
     * Utils
     * =================================================== */

    App.utils.escapeHtml = function(value) {
        return String(value ?? "")
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;")
            .replaceAll("'", "&#039;");
    };

    App.utils.escapeAttr = App.utils.escapeHtml;

    App.utils.statusLabel = function(status) {
        return {
            draft: "下書き",
            active: "公開中",
            ended: "終了"
        }[status] || status;
    };

    App.utils.statusBadge = function(status) {
        return `<span class="badge badge-${App.utils.escapeAttr(status)}">${
            App.utils.escapeHtml(App.utils.statusLabel(status))
        }</span>`;
    };

    App.utils.newId = function(prefix) {
        return prefix + crypto.randomUUID().replaceAll("-", "");
    };

    App.utils.toDateTimeLocal = function(value) {
        if (!value) return "";

        const d = new Date(value);

        if (Number.isNaN(d.getTime())) {
            return value.replace(" ", "T").slice(0,16);
        }

        const pad = n => String(n).padStart(2, "0");

        return d.getFullYear()
            + "-"
            + pad(d.getMonth() + 1)
            + "-"
            + pad(d.getDate())
            + "T"
            + pad(d.getHours())
            + ":"
            + pad(d.getMinutes());
    };

    App.utils.confirmStatusChange = function(oldStatus, newStatus) {
        if (oldStatus === newStatus) return true;

        if (newStatus === "ended") {
            return window.confirm(
                "アンケートを終了します。\nよろしいですか？"
            );
        }

        if (oldStatus === "ended" && newStatus === "active") {
            return window.confirm(
                "終了したアンケートを再公開します。\nよろしいですか？"
            );
        }

        return true;
    };

    App.utils.normalizeSurvey = function(survey) {
        return JSON.parse(JSON.stringify(survey));
    };

    /* =====================================================
     * API
     * =================================================== */

    async function api(action, options = {}) {
        const method = options.method || "GET";
        const payload = options.data || null;

        const url = state.apiUrl + "?action=" + encodeURIComponent(action);

        const controller = new AbortController();
        const timeoutMs = options.timeout || 30000;

        const timer = setTimeout(() => controller.abort(), timeoutMs);

        let response;

        try {
            const fetchOptions = {
                method,
                credentials: "same-origin",
                signal: controller.signal,
                headers: {}
            };

            if (method !== "GET") {
                fetchOptions.headers["Content-Type"] = "application/json";
                fetchOptions.headers["X-CSRF-Token"] = state.csrfToken;

                fetchOptions.body = JSON.stringify({
                    ...(payload || {}),
                    csrf_token: state.csrfToken
                });
            }

            response = await fetch(url, fetchOptions);
        } catch (error) {
            clearTimeout(timer);

            if (error && error.name === "AbortError") {
                throw {
                    type: "timeout",
                    message: "サーバーからの応答が時間内に返りませんでした。"
                };
            }

            throw {
                type: "network",
                message: "サーバーへ接続できませんでした。通信環境を確認してください。"
            };
        }

        clearTimeout(timer);

        const contentType =
            response.headers.get("Content-Type") || "";

        const text = await response.text();

        if (!response.ok) {
            let parsed = null;

            if (contentType.includes("application/json") && text.trim()) {
                try {
                    parsed = JSON.parse(text);
                } catch (_) {
                    parsed = null;
                }
            }

            throw {
                type:
                    parsed?.error_type ||
                    (response.status >= 500 ? "server_error" : "http"),
                status: response.status,
                message:
                    parsed?.message ||
                    `サーバー処理に失敗しました。（HTTP ${response.status}）`,
                data: parsed
            };
        }

        if (!contentType.toLowerCase().includes("application/json")) {
            throw {
                type: "content_type",
                status: response.status,
                message: "サーバーからJSONではない応答が返されました。"
            };
        }

        if (!text.trim()) {
            throw {
                type: "empty_response",
                status: response.status,
                message: "サーバーから空の応答が返されました。"
            };
        }

        let json;

        try {
            json = JSON.parse(text);
        } catch (_) {
            throw {
                type: "invalid_json",
                status: response.status,
                message: "サーバーから正しいJSONを取得できませんでした。"
            };
        }

        if (!json || typeof json !== "object") {
            throw {
                type: "invalid_json",
                status: response.status,
                message: "サーバー応答の構造が正しくありません。"
            };
        }

        if (json.ok !== true) {
            throw {
                type: json.error_type || "api_error",
                status: response.status,
                message: json.message || "API処理に失敗しました。",
                data: json
            };
        }

        return json;
    }

    App.api = api;

    /* =====================================================
     * 初期化
     * =================================================== */

    async function init() {
        if (
            state.initialization === "initializing" ||
            state.initialization === "success"
        ) {
            return;
        }

        state.initialization = "initializing";

        state.apiUrl =
            new URL(
                "index.php",
                window.location.href
            ).href;

        render();

        try {
            const result = await api(
                "get_initial_data",
                {
                    method: "GET",
                    timeout: 20000
                }
            );

            if (
                !result.data ||
                typeof result.data !== "object"
            ) {
                throw {
                    type: "invalid_json",
                    message: "初期データの構造が正しくありません。"
                };
            }

            const requiredKeys = [
                "surveys",
                "responses",
                "customers",
                "settings",
                "mail_logs"
            ];

            for (const key of requiredKeys) {
                if (!(key in result.data)) {
                    throw {
                        type: "invalid_json",
                        message: "初期データに必要な項目がありません。"
                    };
                }
            }

            state.data = result.data;
            state.csrfToken = result.csrf_token || "";
            state.authenticated = !!result.authenticated;
            state.initialization = "success";
            state.error = null;

            if (!state.authenticated) {
                state.screen = "login";
            }

            render();
        } catch (error) {
            state.initialization = "failed";
            state.error = error;
            render();
        }
    }

    /* =====================================================
     * State更新
     * =================================================== */

    function replaceData(data) {
        state.data = data;
    }

    async function refreshData() {
        const result = await api("get_initial_data", {
            method: "GET",
            timeout: 20000
        });

        replaceData(result.data);
        state.csrfToken = result.csrf_token || state.csrfToken;
        state.authenticated = !!result.authenticated;
    }

    /* =====================================================
     * アクション
     * =================================================== */

    App.actions.login = async function(event) {
        event.preventDefault();

        const form = event.currentTarget;

        const username = form.username.value;
        const password = form.password.value;

        try {
            const result = await api("login", {
                method: "POST",
                data: {
                    username,
                    password
                }
            });

            state.csrfToken = result.csrf_token;
            state.authenticated = true;
            state.screen = "surveys";

            await refreshData();

            render();
        } catch (error) {
            showNotice(
                error.message || "ログインに失敗しました。",
                "error"
            );
        }
    };

    App.actions.logout = async function() {
        try {
            await api("logout", {
                method: "POST",
                data: {}
            });
        } catch (_) {
            // ログアウト時の通信失敗はローカル状態を破棄する。
        }

        state.authenticated = false;
        state.screen = "login";
        state.data = state.data || null;

        render();
    };

    App.actions.newSurvey = function() {
        if (!state.authenticated) {
            state.screen = "login";
            render();
            return;
        }

        state.editingSurvey = {
            id: App.utils.newId("survey_"),
            title: "",
            start_at: "",
            end_at: "",
            status: "draft",
            numbering_mode: "simple",
            allow_general_response: true,
            allow_reresponse: true,
            groups: [
                {
                    id: App.utils.newId("g_"),
                    name: "グループ1",
                    description: "",
                    questions: []
                }
            ],
            settings: {},
            deleted: false,
            created_at: new Date().toISOString(),
            updated_at: new Date().toISOString()
        };

        state.screen = "survey_edit";
        render();
        App.initSortable();
    };

    App.actions.editSurvey = function(id) {
        const survey = state.data.surveys.find(
            item => item.id === id
        );

        if (!survey) return;

        state.editingSurvey = App.utils.normalizeSurvey(survey);
        state.screen = "survey_edit";

        render();
        App.initSortable();
    };

    App.actions.addGroup = function() {
        const survey = state.editingSurvey;

        if (!survey) return;

        survey.groups.push({
            id: App.utils.newId("g_"),
            name: `グループ${survey.groups.length + 1}`,
            description: "",
            questions: []
        });

        App.actions.renumberQuestions();
        render();
    };

    App.actions.deleteGroup = function(groupIndex) {
        const survey = state.editingSurvey;

        if (!survey) return;

        if (survey.groups.length <= 1) {
            showNotice(
                "グループは最低1つ必要です。",
                "warning"
            );
            return;
        }

        if (!window.confirm("このグループを削除しますか？")) {
            return;
        }

        survey.groups.splice(groupIndex, 1);

        App.actions.renumberQuestions();
        render();
    };

    App.actions.addQuestion = function(groupIndex) {
        const survey = state.editingSurvey;

        if (!survey) return;

        const group = survey.groups[groupIndex];

        group.questions.push({
            id: App.utils.newId("q_"),
            number: "",
            text: "",
            type: "text",
            required: false,
            choices: [],
            help: ""
        });

        App.actions.renumberQuestions();
        render();
    };

    App.actions.deleteQuestion = function(groupIndex, questionIndex) {
        const survey = state.editingSurvey;

        if (!survey) return;

        if (!window.confirm("この質問を削除しますか？")) {
            return;
        }

        survey.groups[groupIndex].questions.splice(
            questionIndex,
            1
        );

        App.actions.renumberQuestions();
        render();
    };

    App.actions.addChoice = function(groupIndex, questionIndex) {
        const question =
            state.editingSurvey.groups[groupIndex]
                .questions[questionIndex];

        question.choices.push({
            id: App.utils.newId("choice_"),
            label: "",
            branch_to: ""
        });

        render();
    };

    App.actions.deleteChoice = function(
        groupIndex,
        questionIndex,
        choiceIndex
    ) {
        const question =
            state.editingSurvey.groups[groupIndex]
                .questions[questionIndex];

        question.choices.splice(choiceIndex, 1);

        App.actions.renumberQuestions();
        render();
    };

    App.actions.renumberQuestions = function() {
        const survey = state.editingSurvey;

        if (!survey) return;

        let q = 0;

        survey.groups.forEach((group, gi) => {
            group.questions.forEach((question, qi) => {
                q++;

                question.number =
                    survey.numbering_mode === "group"
                        ? `Q${gi + 1}-${qi + 1}`
                        : `Q${q}`;

                if (question.type !== "single") {
                    question.choices.forEach(choice => {
                        choice.branch_to = "";
                    });
                }
            });
        });

        const flat = survey.groups.flatMap(
            group => group.questions
        );

        const positions = new Map();

        flat.forEach((question, index) => {
            positions.set(question.id, index);
        });

        flat.forEach((question, index) => {
            question.choices.forEach(choice => {
                if (
                    choice.branch_to &&
                    (
                        !positions.has(choice.branch_to) ||
                        positions.get(choice.branch_to) <= index
                    )
                ) {
                    choice.branch_to = "";
                }
            });
        });
    };

    App.actions.updateBranchVisibility = function() {
        render();
    };

    App.actions.saveSurvey = async function() {
        if (!state.editingSurvey) return;

        App.actions.renumberQuestions();

        const title = state.editingSurvey.title.trim();

        if (!title) {
            showNotice(
                "アンケートタイトルを入力してください。",
                "warning"
            );
            return;
        }

        try {
            const result = await api("save_survey", {
                method: "POST",
                data: {
                    survey: state.editingSurvey
                }
            });

            state.editingSurvey = result.survey;

            await refreshData();

            showNotice(
                "アンケートを保存しました。",
                "success"
            );

            state.screen = "surveys";
            render();
        } catch (error) {
            showNotice(
                error.message || "保存に失敗しました。",
                "error"
            );
        }
    };

    App.actions.changeSurveyStatus = function(status) {
        if (!state.editingSurvey) return;

        const oldStatus = state.editingSurvey.status;

        if (!App.utils.confirmStatusChange(oldStatus, status)) {
            return;
        }

        state.editingSurvey.status = status;
        render();
    };

    App.actions.previewSurvey = function() {
        App.actions.renumberQuestions();

        state.previewOpen = true;
        render();
    };

    App.actions.closePreview = function() {
        state.previewOpen = false;
        render();
    };

    App.actions.openResponse = function(surveyId) {
        state.responseSurveyId = surveyId;
        state.responseAnswers = {};
        state.responseRespondent = {};
        state.responseDraftId = "";
        state.screen = "response";

        render();
    };

    App.actions.validateResponse = async function() {
        const survey = state.data.surveys.find(
            item => item.id === state.responseSurveyId
        );

        if (!survey) return false;

        try {
            const result = await api("validate_response", {
                method: "POST",
                data: {
                    survey_id: survey.id,
                    answers: state.responseAnswers
                }
            });

            if (!result.valid) {
                const first = Object.values(result.errors)[0];

                showNotice(
                    first || "回答内容を確認してください。",
                    "warning"
                );

                return false;
            }

            return true;
        } catch (error) {
            showNotice(
                error.message || "回答確認に失敗しました。",
                "error"
            );

            return false;
        }
    };

    App.actions.saveResponseDraft = async function() {
        if (!state.responseSurveyId) return;

        try {
            const result = await api(
                "save_response_draft",
                {
                    method: "POST",
                    data: {
                        survey_id: state.responseSurveyId,
                        answers: state.responseAnswers,
                        respondent: state.responseRespondent,
                        draft_id: state.responseDraftId
                    }
                }
            );

            state.responseDraftId = result.draft_id;

            showNotice(
                "回答途中の状態を保存しました。",
                "success"
            );
        } catch (error) {
            showNotice(
                error.message || "回答途中状態の保存に失敗しました。",
                "error"
            );
        }
    };

    App.actions.submitResponse = async function() {
        const valid = await App.actions.validateResponse();

        if (!valid) return;

        if (
            !window.confirm(
                "回答を送信します。\n送信後は回答内容を変更できない場合があります。\nよろしいですか？"
            )
        ) {
            return;
        }

        try {
            const result = await api(
                "submit_response",
                {
                    method: "POST",
                    data: {
                        survey_id: state.responseSurveyId,
                        answers: state.responseAnswers,
                        respondent: state.responseRespondent,
                        draft_id: state.responseDraftId
                    }
                }
            );

            state.responseAnswers = {};
            state.responseRespondent = {};
            state.responseDraftId = "";
            state.responseModalOpen = false;

            state.screen = "complete";

            state.completedResponseId =
                result.response_id;

            render();
        } catch (error) {
            showNotice(
                error.message || "回答の送信に失敗しました。",
                "error"
            );
        }
    };

    App.actions.saveKintoneSettings = async function() {
        const form =
            document.getElementById("kintone_settings_form");

        if (!form) return;

        const settings = {
            subdomain: form.subdomain.value,
            login_name: form.login_name.value,
            password: form.password.value,
            app_id: Number(form.app_id.value || 0),
            ssl_verify: form.ssl_verify.checked,
            proxy: form.proxy.value,
            field_mapping: {}
        };

        const mappingRows =
            form.querySelectorAll("[data-mapping-local]");

        mappingRows.forEach(row => {
            const local =
                row.getAttribute("data-mapping-local");

            const select =
                row.querySelector("select");

            if (local && select && select.value) {
                settings.field_mapping[local] =
                    select.value;
            }
        });

        try {
            const result = await api(
                "save_kintone_settings",
                {
                    method: "POST",
                    data: { settings }
                }
            );

            await refreshData();

            showNotice(
                "kintone設定を保存しました。",
                "success"
            );

            render();
        } catch (error) {
            showNotice(
                error.message || "kintone設定の保存に失敗しました。",
                "error"
            );
        }
    };

    App.actions.saveSmtpSettings = async function() {
        const form =
            document.getElementById("smtp_settings_form");

        if (!form) return;

        const settings = {
            server: form.server.value,
            port: Number(form.port.value || 587),
            encryption: form.encryption.value,
            authentication: form.authentication.checked,
            username: form.username.value,
            password: form.password.value,
            from_email: form.from_email.value,
            from_name: form.from_name.value,
            timeout: Number(form.timeout.value || 10)
        };

        try {
            await api(
                "save_smtp_settings",
                {
                    method: "POST",
                    data: { settings }
                }
            );

            await refreshData();

            showNotice(
                "SMTP設定を保存しました。",
                "success"
            );

            render();
        } catch (error) {
            showNotice(
                error.message || "SMTP設定の保存に失敗しました。",
                "error"
            );
        }
    };

    App.actions.connectKintone = async function() {
        const box =
            document.getElementById(
                "kintone_connection_result"
            );

        if (!box) return;

        box.innerHTML = "接続確認中…";

        try {
            const result =
                await api("connect_kintone", {
                    method: "POST",
                    data: {}
                });

            box.innerHTML =
                `<div class="notice notice-success">
                    <strong>接続成功</strong><br>
                    接続先：${App.utils.escapeHtml(result.result.target)}<br>
                    HTTPステータス：${result.result.http_status}
                </div>`;
        } catch (error) {
            box.innerHTML =
                `<div class="notice notice-error">
                    <strong>接続失敗</strong><br>
                    エラー種別：
                    ${App.utils.escapeHtml(error.data?.error_type || error.type || "")}<br>
                    概要：
                    ${App.utils.escapeHtml(
                        error.data?.error_summary ||
                        error.message ||
                        ""
                    )}<br>
                    確認事項：
                    kintoneのサブドメイン、アプリID、認証情報、SSL、Proxyを確認してください。
                </div>`;
        }
    };

    App.actions.fetchKintoneFields = async function() {
        const box =
            document.getElementById("field_message");

        if (box) {
            box.innerHTML = "フィールド取得中…";
        }

        try {
            const result =
                await api("fetch_kintone_fields", {
                    method: "POST",
                    data: {}
                });

            state.kintoneFields = result.fields || [];

            if (box) {
                box.innerHTML =
                    `<div class="notice notice-success">
                        ${state.kintoneFields.length}件のフィールドを取得しました。
                    </div>`;
            }

            render();
        } catch (error) {
            if (box) {
                box.innerHTML =
                    `<div class="notice notice-error">
                        フィールド取得に失敗しました。<br>
                        ${App.utils.escapeHtml(
                            error.data?.error_summary ||
                            error.message ||
                            ""
                        )}
                    </div>`;
            }
        }
    };

    App.actions.syncCustomers = async function() {
        const box =
            document.getElementById(
                "kintone_connection_result"
            );

        if (box) {
            box.innerHTML = "顧客データ同期中…";
        }

        try {
            const result =
                await api("sync_customers", {
                    method: "POST",
                    data: {}
                });

            await refreshData();

            if (box) {
                box.innerHTML =
                    `<div class="notice notice-success">
                        <strong>同期完了</strong><br>
                        件数：${result.result.count}<br>
                        新規：${result.result.inserted}<br>
                        更新：${result.result.updated}<br>
                        スキップ：${result.result.skipped}<br>
                        エラー：${result.result.errors}
                    </div>`;
            }

            render();
        } catch (error) {
            if (box) {
                box.innerHTML =
                    `<div class="notice notice-error">
                        顧客同期に失敗しました。<br>
                        ${App.utils.escapeHtml(
                            error.data?.error_summary ||
                            error.message ||
                            ""
                        )}
                    </div>`;
            }
        }
    };

    App.actions.testSmtpConnection = async function() {
        const box =
            document.getElementById(
                "smtp_connection_result"
            );

        if (box) {
            box.innerHTML = "接続確認中…";
        }

        try {
            const result =
                await api("test_smtp_connection", {
                    method: "POST",
                    data: {}
                });

            if (box) {
                box.innerHTML =
                    `<div class="notice notice-success">
                        <strong>接続成功</strong><br>
                        接続先：
                        ${App.utils.escapeHtml(result.result.target)}<br>
                        ポート：
                        ${App.utils.escapeHtml(result.result.port)}<br>
                        SMTP応答コード：
                        ${App.utils.escapeHtml(result.result.smtp_code)}
                    </div>`;
            }
        } catch (error) {
            if (box) {
                box.innerHTML =
                    `<div class="notice notice-error">
                        <strong>接続失敗</strong><br>
                        エラー種別：
                        ${App.utils.escapeHtml(
                            error.data?.error_type ||
                            error.type ||
                            ""
                        )}<br>
                        概要：
                        ${App.utils.escapeHtml(
                            error.data?.error_summary ||
                            error.message ||
                            ""
                        )}<br>
                        SMTP応答コード：
                        ${App.utils.escapeHtml(
                            error.data?.smtp_code || ""
                        )}
                    </div>`;
            }
        }
    };

    App.actions.sendSmtpTest = async function() {
        const input =
            document.getElementById("smtp_test_to");

        if (!input || !input.value.trim()) {
            showNotice(
                "テストメールの宛先を入力してください。",
                "warning"
            );
            return;
        }

        try {
            await api("send_smtp_test", {
                method: "POST",
                data: {
                    to: input.value.trim()
                }
            });

            showNotice(
                "テストメールを送信しました。",
                "success"
            );
        } catch (error) {
            showNotice(
                error.data?.error_summary ||
                error.message ||
                "テストメール送信に失敗しました。",
                "error"
            );
        }
    };

    App.actions.deleteSurvey = async function(id) {
        if (!window.confirm(
            "このアンケートを論理削除しますか？"
        )) {
            return;
        }

        try {
            await api("delete_survey", {
                method: "POST",
                data: {
                    survey_id: id
                }
            });

            await refreshData();

            showNotice(
                "アンケートを削除しました。",
                "success"
            );

            render();
        } catch (error) {
            showNotice(
                error.message || "削除に失敗しました。",
                "error"
            );
        }
    };

    App.actions.duplicateSurvey = async function(id) {
        try {
            await api("duplicate_survey", {
                method: "POST",
                data: {
                    survey_id: id
                }
            });

            await refreshData();

            showNotice(
                "アンケートを複製しました。",
                "success"
            );

            render();
        } catch (error) {
            showNotice(
                error.message || "複製に失敗しました。",
                "error"
            );
        }
    };

    /* =====================================================
     * Notice
     * =================================================== */

    function showNotice(message, type = "info") {
        state.notice = {
            message,
            type
        };

        render();

        window.setTimeout(() => {
            state.notice = null;
            render();
        }, 5000);
    }

    /* =====================================================
     * Rendering
     * =================================================== */

    function render() {
        const root = document.getElementById("app");

        if (!root) return;

        if (state.initialization === "uninitialized" ||
            state.initialization === "initializing") {

            root.innerHTML = `
                <div class="loading-screen">
                    <div>
                        <div class="spinner" style="margin:auto"></div>
                        <p>初期データを取得しています…</p>
                    </div>
                </div>
            `;

            return;
        }

        if (state.initialization === "failed") {
            root.innerHTML = renderInitError();
            bindEvents();
            return;
        }

        if (state.screen === "login") {
            root.innerHTML = renderLogin();
            bindEvents();
            return;
        }

        if (!state.authenticated &&
            !["response", "complete"].includes(state.screen)) {
            state.screen = "login";
            root.innerHTML = renderLogin();
            bindEvents();
            return;
        }

        root.innerHTML = `
            <div class="app-shell">
                ${renderTopbar()}
                <div class="layout">
                    ${renderSidebar()}
                    <main class="main">
                        <div class="container">
                            ${renderNotice()}
                            ${renderScreen()}
                        </div>
                    </main>
                </div>
            </div>
            ${renderModals()}
        `;

        bindEvents();
        App.initSortable();
    }

    function renderNotice() {
        if (!state.notice) return "";

        const cls =
            state.notice.type === "error"
                ? "notice-error"
                : state.notice.type === "success"
                    ? "notice-success"
                    : state.notice.type === "warning"
                        ? "notice-warning"
                        : "";

        return `
            <div class="notice ${cls}">
                ${App.utils.escapeHtml(state.notice.message)}
            </div>
        `;
    }

    function renderTopbar() {
        return `
            <header class="topbar">
                <div class="topbar-title">${App.utils.escapeHtml(APP_NAME)}</div>
                <div class="topbar-user">
                    ${
                        state.authenticated
                            ? `
                                <span>
                                    ${App.utils.escapeHtml(
                                        "管理者"
                                    )}
                                </span>
                                <button
                                    class="btn btn-small"
                                    data-action="logout"
                                >
                                    ログアウト
                                </button>
                            `
                            : ""
                    }
                </div>
            </header>
        `;
    }

    const APP_NAME = "アンケート管理システム";

    function renderSidebar() {
        if (!state.authenticated) {
            return "";
        }

        return `
            <aside class="sidebar">
                <button
                    class="nav-button ${
                        state.screen === "surveys" ||
                        state.screen === "survey_edit"
                            ? "active"
                            : ""
                    }"
                    data-screen="surveys"
                >
                    アンケート一覧
                </button>

                <button
                    class="nav-button ${
                        state.screen === "send"
                            ? "active"
                            : ""
                    }"
                    data-screen="send"
                >
                    送信
                </button>

                <button
                    class="nav-button ${
                        state.screen === "aggregation"
                            ? "active"
                            : ""
                    }"
                    data-screen="aggregation"
                >
                    集計
                </button>

                <button
                    class="nav-button ${
                        state.screen === "settings"
                            ? "active"
                            : ""
                    }"
                    data-screen="settings"
                >
                    キントーン・メール設定
                </button>
            </aside>
        `;
    }

    function renderScreen() {
        switch (state.screen) {
            case "surveys":
                return renderSurveyList();

            case "survey_edit":
                return renderSurveyEditor();

            case "send":
                return renderSend();

            case "aggregation":
                return renderAggregation();

            case "settings":
                return renderSettings();

            case "response":
                return renderResponse();

            case "complete":
                return renderComplete();

            default:
                return renderSurveyList();
        }
    }

    function renderInitError() {
        const error = state.error || {};

        return `
            <div class="error-screen">
                <h1>初期データの取得に失敗しました。</h1>

                <p>
                    サーバーから正常なデータを取得できませんでした。
                </p>

                <div class="notice notice-error">
                    <strong>エラー種別：</strong>
                    ${App.utils.escapeHtml(error.type || "unknown")}
                    ${
                        error.status
                            ? `<br><strong>HTTPステータス：</strong>${error.status}`
                            : ""
                    }
                    <br>
                    <strong>概要：</strong>
                    ${App.utils.escapeHtml(
                        error.message ||
                        "サーバーとの通信に失敗しました。"
                    )}
                </div>

                <p class="muted">
                    サーバー設定、JSONデータファイル、通信状態などを確認してください。
                </p>

                <button
                    class="btn btn-primary"
                    data-action="retry-init"
                >
                    再試行
                </button>
            </div>
        `;
    }

    function renderLogin() {
        return `
            <div class="login-screen">
                <h1>${App.utils.escapeHtml(APP_NAME)}</h1>

                <p class="muted">
                    管理画面へログインしてください。
                </p>

                <form id="login_form">
                    <div class="form-group">
                        <label for="login_username">
                            ユーザー名
                        </label>
                        <input
                            id="login_username"
                            name="username"
                            type="text"
                            autocomplete="username"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="login_password">
                            パスワード
                        </label>
                        <input
                            id="login_password"
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            required
                        >
                    </div>

                    <button
                        class="btn btn-primary"
                        type="submit"
                    >
                        ログイン
                    </button>
                </form>
            </div>
        `;
    }

    function renderSurveyList() {
        const surveys = (state.data.surveys || [])
            .filter(survey => !survey.deleted);

        return `
            <div class="toolbar">
                <h1 style="margin:0; margin-right:auto;">
                    アンケート一覧
                </h1>

                <button
                    class="btn btn-primary"
                    data-action="new-survey"
                >
                    ＋ 新規作成
                </button>
            </div>

            <div class="card">
                ${
                    surveys.length === 0
                        ? `
                            <p class="muted">
                                アンケートがありません。
                            </p>
                        `
                        : `
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>タイトル</th>
                                            <th>ステータス</th>
                                            <th>開始日時</th>
                                            <th>終了日時</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${surveys.map(survey => `
                                            <tr>
                                                <td>
                                                    <strong>
                                                        ${App.utils.escapeHtml(
                                                            survey.title
                                                        )}
                                                    </strong>
                                                </td>
                                                <td>
                                                    ${App.utils.statusBadge(
                                                        survey.status
                                                    )}
                                                </td>
                                                <td>
                                                    ${App.utils.escapeHtml(
                                                        survey.start_at || "-"
                                                    )}
                                                </td>
                                                <td>
                                                    ${App.utils.escapeHtml(
                                                        survey.end_at || "-"
                                                    )}
                                                </td>
                                                <td>
                                                    <div class="toolbar" style="margin:0">
                                                        <button
                                                            class="btn btn-small"
                                                            data-edit-survey="${App.utils.escapeAttr(survey.id)}"
                                                        >
                                                            編集
                                                        </button>

                                                        ${
                                                            survey.status === "active"
                                                                ? `
                                                                    <button
                                                                        class="btn btn-small btn-success"
                                                                        data-answer-survey="${App.utils.escapeAttr(survey.id)}"
                                                                    >
                                                                        回答画面
                                                                    </button>
                                                                `
                                                                : ""
                                                        }

                                                        <button
                                                            class="btn btn-small"
                                                            data-duplicate-survey="${App.utils.escapeAttr(survey.id)}"
                                                        >
                                                            複製
                                                        </button>

                                                        <button
                                                            class="btn btn-small btn-danger"
                                                            data-delete-survey="${App.utils.escapeAttr(survey.id)}"
                                                        >
                                                            削除
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        `).join("")}
                                    </tbody>
                                </table>
                            </div>
                        `
                }
            </div>
        `;
    }

    function renderSurveyEditor() {
        const survey = state.editingSurvey;

        if (!survey) {
            return renderSurveyList();
        }

        return `
            <div class="toolbar">
                <h1 style="margin:0; margin-right:auto;">
                    アンケート作成・編集
                </h1>

                <button
                    class="btn"
                    data-action="back-surveys"
                >
                    一覧へ戻る
                </button>

                <button
                    class="btn"
                    data-action="preview-survey"
                >
                    プレビュー
                </button>

                <button
                    class="btn btn-primary"
                    data-action="save-survey"
                >
                    保存
                </button>
            </div>

            <div class="card">
                <div class="form-grid">

                    <div class="form-group full">
                        <label for="survey_title">
                            タイトル
                        </label>
                        <input
                            id="survey_title"
                            type="text"
                            value="${App.utils.escapeAttr(survey.title)}"
                            data-survey-field="title"
                        >
                    </div>

                    <div class="form-group">
                        <label for="survey_start_at">
                            開始日時
                        </label>
                        <input
                            id="survey_start_at"
                            type="datetime-local"
                            value="${App.utils.escapeAttr(
                                App.utils.toDateTimeLocal(survey.start_at)
                            )}"
                            data-survey-field="start_at"
                        >
                    </div>

                    <div class="form-group">
                        <label for="survey_end_at">
                            終了日時
                        </label>
                        <input
                            id="survey_end_at"
                            type="datetime-local"
                            value="${App.utils.escapeAttr(
                                App.utils.toDateTimeLocal(survey.end_at)
                            )}"
                            data-survey-field="end_at"
                        >
                    </div>

                    <div class="form-group">
                        <label for="survey_status">
                            ステータス
                        </label>

                        <select
                            id="survey_status"
                            data-survey-field="status"
                        >
                            <option value="draft"
                                ${survey.status === "draft" ? "selected" : ""}>
                                下書き
                            </option>
                            <option value="active"
                                ${survey.status === "active" ? "selected" : ""}>
                                公開中
                            </option>
                            <option value="ended"
                                ${survey.status === "ended" ? "selected" : ""}>
                                終了
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="survey_numbering_mode">
                            質問番号形式
                        </label>

                        <select
                            id="survey_numbering_mode"
                            data-survey-field="numbering_mode"
                        >
                            <option value="simple"
                                ${survey.numbering_mode === "simple" ? "selected" : ""}>
                                Q1 / Q2 / Q3
                            </option>

                            <option value="group"
                                ${survey.numbering_mode === "group" ? "selected" : ""}>
                                Q1-1 / Q1-2 / Q2-1
                            </option>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label>
                            <input
                                type="checkbox"
                                ${survey.allow_general_response ? "checked" : ""}
                                data-survey-field="allow_general_response"
                            >
                            一般回答を許可する
                        </label>

                        <label>
                            <input
                                type="checkbox"
                                ${survey.allow_reresponse ? "checked" : ""}
                                data-survey-field="allow_reresponse"
                            >
                            再回答を許可する
                        </label>
                    </div>
                </div>
            </div>

            <div id="question_editor">
                ${renderQuestionEditorGroups(survey)}
            </div>

            <div class="toolbar">
                <button
                    class="btn"
                    data-action="add-group"
                >
                    ＋ グループ追加
                </button>

                <button
                    class="btn btn-primary"
                    data-action="save-survey"
                >
                    保存
                </button>
            </div>
        `;
    }

    function renderQuestionEditorGroups(survey) {
        return survey.groups.map((group, gi) => `
            <section
                class="group-card"
                data-group-index="${gi}"
            >
                <div class="group-header">
                    <strong>
                        グループ ${gi + 1}
                    </strong>

                    <button
                        class="btn btn-small btn-danger"
                        data-delete-group="${gi}"
                    >
                        グループ削除
                    </button>
                </div>

                <div style="padding:15px">
                    <div class="form-group">
                        <label>
                            グループ名
                        </label>

                        <input
                            type="text"
                            value="${App.utils.escapeAttr(group.name)}"
                            data-group-field="name"
                            data-group-index="${gi}"
                        >
                    </div>

                    <div class="form-group">
                        <label>
                            説明
                        </label>

                        <textarea
                            data-group-field="description"
                            data-group-index="${gi}"
                        >${App.utils.escapeHtml(group.description)}</textarea>
                    </div>

                    <div
                        class="questions-container"
                        data-group-index="${gi}"
                    >
                        ${group.questions.map((question, qi) =>
                            renderQuestionEditor(question, gi, qi, survey)
                        ).join("")}
                    </div>

                    <button
                        class="btn"
                        data-add-question="${gi}"
                    >
                        ＋ 質問追加
                    </button>
                </div>
            </section>
        `).join("");
    }

    function renderQuestionEditor(
        question,
        gi,
        qi,
        survey
    ) {
        const flatQuestions =
            survey.groups.flatMap(
                group => group.questions
            );

        const laterQuestions =
            flatQuestions.filter(
                (_, index) =>
                    index >
                    flatQuestions.findIndex(
                        item => item.id === question.id
                    )
            );

        return `
            <div
                class="question-card"
                data-question-id="${App.utils.escapeAttr(question.id)}"
            >
                <div class="toolbar">
                    <strong style="margin-right:auto;">
                        ${App.utils.escapeHtml(question.number || "")}
                    </strong>

                    <button
                        class="btn btn-small"
                        data-move-up="${gi}:${qi}"
                    >
                        ↑
                    </button>

                    <button
                        class="btn btn-small"
                        data-move-down="${gi}:${qi}"
                    >
                        ↓
                    </button>

                    <button
                        class="btn btn-small btn-danger"
                        data-delete-question="${gi}:${qi}"
                    >
                        削除
                    </button>
                </div>

                <div class="form-group">
                    <label>
                        質問文
                    </label>

                    <textarea
                        data-question-field="text"
                        data-group-index="${gi}"
                        data-question-index="${qi}"
                    >${App.utils.escapeHtml(question.text)}</textarea>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>
                            回答形式
                        </label>

                        <select
                            data-question-field="type"
                            data-group-index="${gi}"
                            data-question-index="${qi}"
                        >
                            ${[
                                ["text","短文"],
                                ["textarea","長文"],
                                ["single","単一選択"],
                                ["multiple","複数選択"],
                                ["number","数値"],
                                ["email","メールアドレス"],
                                ["date","日付"]
                            ].map(([value,label]) => `
                                <option
                                    value="${value}"
                                    ${question.type === value ? "selected" : ""}
                                >
                                    ${label}
                                </option>
                            `).join("")}
                        </select>
                    </div>

                    <div class="form-group">
                        <label>
                            <input
                                type="checkbox"
                                data-question-field="required"
                                data-group-index="${gi}"
                                data-question-index="${qi}"
                                ${question.required ? "checked" : ""}
                            >
                            必須回答
                        </label>
                    </div>
                </div>

                ${
                    question.type === "single" ||
                    question.type === "multiple"
                        ? `
                            <div class="form-group">
                                <label>選択肢</label>

                                ${question.choices.map(
                                    (choice, ci) => `
                                        <div class="choice-row">
                                            <input
                                                type="text"
                                                placeholder="選択肢"
                                                value="${App.utils.escapeAttr(choice.label)}"
                                                data-choice-field="label"
                                                data-group-index="${gi}"
                                                data-question-index="${qi}"
                                                data-choice-index="${ci}"
                                            >

                                            ${
                                                question.type === "single"
                                                    ? `
                                                        <select
                                                            data-choice-field="branch_to"
                                                            data-group-index="${gi}"
                                                            data-question-index="${qi}"
                                                            data-choice-index="${ci}"
                                                        >
                                                            <option value="">
                                                                分岐なし
                                                            </option>

                                                            ${laterQuestions.map(
                                                                target => `
                                                                    <option
                                                                        value="${App.utils.escapeAttr(target.id)}"
                                                                        ${choice.branch_to === target.id ? "selected" : ""}
                                                                    >
                                                                        ${App.utils.escapeHtml(
                                                                            target.number
                                                                            + " "
                                                                            + target.text
                                                                        )}
                                                                    </option>
                                                                `
                                                            ).join("")}
                                                        </select>
                                                    `
                                                    : ""
                                            }

                                            <button
                                                class="btn btn-small btn-danger"
                                                data-delete-choice="${gi}:${qi}:${ci}"
                                            >
                                                削除
                                            </button>
                                        </div>
                                    `
                                ).join("")}

                                <button
                                    class="btn btn-small"
                                    data-add-choice="${gi}:${qi}"
                                >
                                    ＋ 選択肢追加
                                </button>
                            </div>
                        `
                        : ""
                }

                <div class="form-group">
                    <label>
                        補足説明
                    </label>

                    <textarea
                        data-question-field="help"
                        data-group-index="${gi}"
                        data-question-index="${qi}"
                    >${App.utils.escapeHtml(question.help)}</textarea>
                </div>
            </div>
        `;
    }

    function renderModals() {
        return `
            ${
                state.previewOpen
                    ? `
                        <div
                            class="modal open"
                            id="preview_modal"
                        >
                            <div class="modal-content">
                                <div class="toolbar">
                                    <h2 style="margin:0;margin-right:auto">
                                        プレビュー
                                    </h2>

                                    <button
                                        class="btn"
                                        data-action="close-preview"
                                    >
                                        閉じる
                                    </button>
                                </div>

                                <div id="preview_content">
                                    ${renderSurveyPreview(
                                        state.editingSurvey
                                    )}
                                </div>
                            </div>
                        </div>
                    `
                    : ""
            }

            ${
                state.responseModalOpen
                    ? `
                        <div
                            class="modal open"
                            id="response_modal"
                        >
                            <div class="modal-content">
                                <div
                                    id="response_detail"
                                >
                                    ${renderResponseDetail()}
                                </div>
                            </div>
                        </div>
                    `
                    : ""
            }
        `;
    }

    function renderSurveyPreview(survey) {
        if (!survey) return "";

        return `
            <article>
                <h2>
                    ${App.utils.escapeHtml(survey.title)}
                </h2>

                ${
                    survey.start_at || survey.end_at
                        ? `
                            <p class="muted">
                                ${
                                    survey.start_at
                                        ? "開始：" +
                                          App.utils.escapeHtml(survey.start_at)
                                        : ""
                                }
                                ${
                                    survey.end_at
                                        ? "　終了：" +
                                          App.utils.escapeHtml(survey.end_at)
                                        : ""
                                }
                            </p>
                        `
                        : ""
                }

                ${survey.groups.map(group => `
                    <section>
                        <h3>
                            ${App.utils.escapeHtml(group.name)}
                        </h3>

                        ${
                            group.description
                                ? `<p>${App.utils.escapeHtml(group.description)}</p>`
                                : ""
                        }

                        ${group.questions.map(
                            question =>
                                renderAnswerQuestion(
                                    question,
                                    false
                                )
                        ).join("")}
                    </section>
                `).join("")}
            </article>
        `;
    }

    function renderResponse() {
        const survey = state.data.surveys.find(
            item => item.id === state.responseSurveyId
        );

        if (!survey) {
            return `
                <div class="card">
                    <h1>アンケートが見つかりません。</h1>
                </div>
            `;
        }

        return `
            <div class="toolbar">
                <h1 style="margin:0;margin-right:auto">
                    ${App.utils.escapeHtml(survey.title)}
                </h1>

                <button
                    class="btn"
                    data-action="back-surveys"
                >
                    戻る
                </button>
            </div>

            <div class="card">
                <h2>回答者情報</h2>

                <div class="form-grid">
                    <div class="form-group">
                        <label>
                            氏名
                        </label>

                        <input
                            type="text"
                            value="${App.utils.escapeAttr(
                                state.responseRespondent.name || ""
                            )}"
                            data-respondent-field="name"
                        >
                    </div>

                    <div class="form
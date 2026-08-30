<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし
 * 単一エントリーポイント
 *
 * 永続化:
 *   _data/data.json
 *   _data/settings.json
 *
 * 機密情報:
 *   APP_ENCRYPTION_KEY をサーバー環境変数から取得
 *
 * 外部サービス:
 *   kintone REST API
 *   SMTP
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';

const DATA_DIR  = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SET_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const KINTONE_TIMEOUT = 30;
const SMTP_TIMEOUT    = 30;

const MAX_TITLE       = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION    = 1000;
const MAX_OPTION      = 500;


/* =========================================================
 * 基本ユーティリティ
 * ========================================================= */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function post_value(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function post_string(string $key): string
{
    $value = post_value($key, '');
    return is_scalar($value) ? trim((string)$value) : '';
}

function post_bool(string $key): bool
{
    return in_array(
        strtolower((string)post_value($key, '')),
        ['1', 'true', 'yes', 'on'],
        true
    );
}

function get_string(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
}

function uid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function app_url(array $params = []): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? 'index.php');

    if ($params === []) {
        return $script;
    }

    return $script . '?' . http_build_query(
        $params,
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}

function public_url(string $surveyId): string
{
    $https =
        (!empty($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

    $scheme = $https ? 'https' : 'http';

    $host = (string)(
        $_SERVER['HTTP_HOST'] ?? 'localhost'
    );

    return $scheme . '://' . $host .
        app_url([
            'screen' => 'answer',
            'id' => $surveyId,
        ]);
}

function safe_external_error(Throwable $e): string
{
    $message = trim($e->getMessage());

    $message = preg_replace(
        '/X-Cybozu-Authorization:\s*[^\s]+/i',
        'X-Cybozu-Authorization: [REDACTED]',
        $message
    ) ?? $message;

    $message = preg_replace(
        '/password\s*[=:]\s*[^\s]+/i',
        'password=[REDACTED]',
        $message
    ) ?? $message;

    return mb_substr($message, 0, 500);
}


/* =========================================================
 * セッション
 * ========================================================= */

function cookie_path(): string
{
    $script = str_replace(
        '\\',
        '/',
        (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')
    );

    $dir = dirname($script);

    if (
        $dir === '.' ||
        $dir === '/' ||
        $dir === '\\'
    ) {
        return '/';
    }

    return rtrim($dir, '/') . '/';
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure =
        (!empty($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

    session_name('survey_app_session');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => cookie_path(),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException(
            'セッションを開始できません。'
        );
    }
}


/* =========================================================
 * JSON永続化
 * ========================================================= */

function load_json(
    string $file,
    array $fallback
): array {
    if (!is_file($file)) {
        return $fallback;
    }

    $fp = @fopen($file, 'rb');

    if (!$fp) {
        return $fallback;
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            fclose($fp);
            return $fallback;
        }

        $raw = stream_get_contents($fp);

        flock($fp, LOCK_UN);
        fclose($fp);
    } catch (Throwable) {
        @fclose($fp);
        return $fallback;
    }

    if (
        $raw === false ||
        trim($raw) === ''
    ) {
        return $fallback;
    }

    $value = json_decode(
        $raw,
        true
    );

    return is_array($value)
        ? $value
        : $fallback;
}

function save_json(
    string $file,
    array $data
): void {
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException(
            'JSONデータを生成できません。'
        );
    }

    $tmp =
        $file .
        '.tmp.' .
        bin2hex(random_bytes(8));

    $fp = @fopen($tmp, 'wb');

    if (!$fp) {
        throw new RuntimeException(
            '一時ファイルを作成できません。'
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException(
                'データファイルをロックできません。'
            );
        }

        $written = fwrite($fp, $json);

        if ($written === false) {
            throw new RuntimeException(
                'データを書き込めません。'
            );
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!rename($tmp, $file)) {
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
 * デフォルトデータ
 * ========================================================= */

function default_data(): array
{
    $timestamp = now();

    return [
        'surveys' => [[
            'id' => 'survey-001',
            'title' => '顧客満足度アンケート',
            'description' =>
                'サービスについてのご意見をお聞かせください。',
            'startAt' => date('Y-m-d\TH:i'),
            'endAt' =>
                date(
                    'Y-m-d\TH:i',
                    strtotime('+30 days')
                ),
            'status' => 'draft',
            'numbering' => 'global',
            'createdAt' => $timestamp,
            'updatedAt' => $timestamp,
            'groups' => [[
                'id' => 'group-001',
                'title' => '基本アンケート',
                'questions' => [[
                    'id' => 'question-001',
                    'number' => 'Q1',
                    'text' =>
                        'サービスの満足度を教えてください。',
                    'type' => 'single',
                    'required' => true,
                    'options' => [
                        [
                            'id' => 'option-001',
                            'label' => '非常に満足',
                            'nextQuestionId' => '',
                        ],
                        [
                            'id' => 'option-002',
                            'label' => '満足',
                            'nextQuestionId' => '',
                        ],
                        [
                            'id' => 'option-003',
                            'label' => '普通',
                            'nextQuestionId' => '',
                        ],
                        [
                            'id' => 'option-004',
                            'label' => '不満',
                            'nextQuestionId' => '',
                        ],
                    ],
                ], [
                    'id' => 'question-002',
                    'number' => 'Q2',
                    'text' =>
                        'ご意見・ご要望があれば入力してください。',
                    'type' => 'text',
                    'required' => false,
                    'options' => [],
                ]],
            ]],
        ]],
        'answers' => [],
        'customers' => [],
        'send_history' => [],
    ];
}

function default_settings(): array
{
    return [
        'kintone' => [
            'subdomain' => '',
            'app_id' => '',
            'username' => '',
            'password' => '',
            'proxy' => '',
            'verify_ssl' => true,
            'mapping' => [
                'organization' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
            'fields' => [],
            'last_test' => null,
            'last_sync' => null,
        ],
        'mail' => [
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '',
            'reply_to' => '',
            'last_test' => null,
        ],
    ];
}

function load_data(): array
{
    $data = load_json(
        DATA_FILE,
        default_data()
    );

    foreach (
        [
            'surveys',
            'answers',
            'customers',
            'send_history',
        ] as $key
    ) {
        if (
            !isset($data[$key]) ||
            !is_array($data[$key])
        ) {
            $data[$key] = [];
        }
    }

    return $data;
}

function save_data(array $data): void
{
    save_json(DATA_FILE, $data);
}


/* =========================================================
 * APP_ENCRYPTION_KEY
 * ========================================================= */

function encryption_key(): string
{
    $value = getenv('APP_ENCRYPTION_KEY');

    if (
        $value === false ||
        trim($value) === ''
    ) {
        throw new RuntimeException(
            'APP_ENCRYPTION_KEY が設定されていません。'
        );
    }

    return hash(
        'sha256',
        trim($value),
        true
    );
}

function is_encrypted_secret(
    string $value
): bool {
    if ($value === '') {
        return false;
    }

    $decoded = base64_decode(
        $value,
        true
    );

    if ($decoded === false) {
        return false;
    }

    $payload = json_decode(
        $decoded,
        true
    );

    return is_array($payload)
        && (int)($payload['v'] ?? 0) === 1
        && isset(
            $payload['iv'],
            $payload['tag'],
            $payload['data']
        );
}

function encrypt_secret(
    string $plain
): string {
    if ($plain === '') {
        return '';
    }

    $key = encryption_key();
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
        throw new RuntimeException(
            '機密情報の暗号化に失敗しました。'
        );
    }

    $payload = [
        'v' => 1,
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'data' => base64_encode($cipher),
    ];

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new RuntimeException(
            '暗号化データを生成できません。'
        );
    }

    return base64_encode($json);
}

function decrypt_secret(
    string $encrypted
): string {
    if ($encrypted === '') {
        return '';
    }

    $decoded = base64_decode(
        $encrypted,
        true
    );

    if ($decoded === false) {
        throw new RuntimeException(
            '保存された機密情報の形式が不正です。'
        );
    }

    $payload = json_decode(
        $decoded,
        true
    );

    if (
        !is_array($payload) ||
        (int)($payload['v'] ?? 0) !== 1
    ) {
        throw new RuntimeException(
            '保存された機密情報の形式が不正です。'
        );
    }

    $iv = base64_decode(
        (string)($payload['iv'] ?? ''),
        true
    );

    $tag = base64_decode(
        (string)($payload['tag'] ?? ''),
        true
    );

    $cipher = base64_decode(
        (string)($payload['data'] ?? ''),
        true
    );

    if (
        $iv === false ||
        $tag === false ||
        $cipher === false
    ) {
        throw new RuntimeException(
            '保存された機密情報を復号できません。'
        );
    }

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        encryption_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($plain === false) {
        throw new RuntimeException(
            'APP_ENCRYPTION_KEY が正しくないか、保存データが破損しています。'
        );
    }

    return $plain;
}

function prepare_settings_for_runtime(
    array $settings
): array {
    foreach (
        ['kintone', 'mail'] as $service
    ) {
        if (
            isset($settings[$service]['password']) &&
            is_encrypted_secret(
                (string)$settings[$service]['password']
            )
        ) {
            $settings[$service]['password'] =
                decrypt_secret(
                    (string)$settings[$service]['password']
                );
        }
    }

    return $settings;
}

function prepare_settings_for_save(
    array $settings
): array {
    foreach (
        ['kintone', 'mail'] as $service
    ) {
        if (
            isset($settings[$service]['password'])
        ) {
            $password =
                (string)$settings[$service]['password'];

            if (
                $password !== '' &&
                !is_encrypted_secret($password)
            ) {
                $settings[$service]['password'] =
                    encrypt_secret($password);
            }
        }
    }

    return $settings;
}

function load_settings(): array
{
    $default = default_settings();

    $settings = load_json(
        SET_FILE,
        $default
    );

    foreach (
        ['kintone', 'mail'] as $service
    ) {
        $settings[$service] =
            array_replace_recursive(
                $default[$service],
                is_array(
                    $settings[$service] ?? null
                )
                    ? $settings[$service]
                    : []
            );
    }

    return $settings;
}

function save_settings(
    array $settings
): void {
    save_json(
        SET_FILE,
        prepare_settings_for_save($settings)
    );
}


/* =========================================================
 * Flash
 * ========================================================= */

function flash(
    string $type,
    string $message
): void {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function flash_get(): ?array
{
    $value = $_SESSION['flash'] ?? null;

    unset($_SESSION['flash']);

    return is_array($value)
        ? $value
        : null;
}


/* =========================================================
 * アンケート
 * ========================================================= */

function survey_index(
    array $surveys,
    string $id
): int {
    foreach ($surveys as $index => $survey) {
        if (
            (string)($survey['id'] ?? '') ===
            $id
        ) {
            return $index;
        }
    }

    return -1;
}

function survey_get(
    array $surveys,
    string $id
): ?array {
    $index = survey_index(
        $surveys,
        $id
    );

    return $index >= 0
        ? $surveys[$index]
        : null;
}

function all_questions(
    array $survey
): array {
    $result = [];

    foreach (
        $survey['groups'] ?? [] as $group
    ) {
        foreach (
            $group['questions'] ?? [] as $question
        ) {
            $result[] = $question;
        }
    }

    return $result;
}

function recalc_numbers(
    array &$survey
): void {
    $global = 1;
    $groupNumber = 1;

    foreach (
        $survey['groups'] as &$group
    ) {
        $questionNumber = 1;

        foreach (
            $group['questions'] as &$question
        ) {
            if (
                ($survey['numbering'] ?? 'global') ===
                'group'
            ) {
                $question['number'] =
                    'Q' .
                    $groupNumber .
                    '-' .
                    $questionNumber;
            } else {
                $question['number'] =
                    'Q' . $global;
            }

            $global++;
            $questionNumber++;
        }

        unset($question);

        $groupNumber++;
    }

    unset($group);
}

function refresh_status(
    array &$data
): void {
    $changed = false;

    foreach (
        $data['surveys'] as &$survey
    ) {
        if (
            ($survey['status'] ?? '') ===
                'published' &&
            !empty($survey['endAt'])
        ) {
            $timestamp = strtotime(
                (string)$survey['endAt']
            );

            if (
                $timestamp !== false &&
                $timestamp < time()
            ) {
                $survey['status'] = 'ended';
                $survey['updatedAt'] = now();
                $changed = true;
            }
        }
    }

    unset($survey);

    if ($changed) {
        save_data($data);
    }
}

function status_label(
    string $status
): string {
    return match ($status) {
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き',
    };
}

function status_class(
    string $status
): string {
    return match ($status) {
        'published' => 'success',
        'stopped' => 'warning',
        'ended' => 'gray',
        default => 'gray',
    };
}

function normalize_groups(
    mixed $input
): array {
    if (!is_array($input)) {
        return [];
    }

    $groups = [];

    foreach ($input as $group) {
        if (!is_array($group)) {
            continue;
        }

        $gid = trim(
            (string)($group['id'] ?? '')
        );

        if ($gid === '') {
            $gid = uid('group');
        }

        $title = mb_substr(
            trim(
                (string)(
                    $group['title'] ?? ''
                )
            ),
            0,
            MAX_TITLE
        );

        $questions = [];

        foreach (
            $group['questions'] ?? [] as $question
        ) {
            if (!is_array($question)) {
                continue;
            }

            $qid = trim(
                (string)(
                    $question['id'] ?? ''
                )
            );

            if ($qid === '') {
                $qid = uid('question');
            }

            $type = (string)(
                $question['type'] ?? 'text'
            );

            if (
                !in_array(
                    $type,
                    ['single', 'multiple', 'text'],
                    true
                )
            ) {
                $type = 'text';
            }

            $options = [];

            if (
                $type === 'single' ||
                $type === 'multiple'
            ) {
                foreach (
                    $question['options'] ?? [] as $option
                ) {
                    if (!is_array($option)) {
                        continue;
                    }

                    $oid = trim(
                        (string)(
                            $option['id'] ?? ''
                        )
                    );

                    if ($oid === '') {
                        $oid = uid('option');
                    }

                    $options[] = [
                        'id' => $oid,
                        'label' =>
                            mb_substr(
                                trim(
                                    (string)(
                                        $option['label'] ??
                                        ''
                                    )
                                ),
                                0,
                                MAX_OPTION
                            ),
                        'nextQuestionId' =>
                            $type === 'single'
                                ? trim(
                                    (string)(
                                        $option[
                                            'nextQuestionId'
                                        ] ?? ''
                                    )
                                )
                                : '',
                    ];
                }
            }

            $questions[] = [
                'id' => $qid,
                'number' => '',
                'text' =>
                    mb_substr(
                        trim(
                            (string)(
                                $question['text'] ??
                                ''
                            )
                        ),
                        0,
                        MAX_QUESTION
                    ),
                'type' => $type,
                'required' =>
                    !empty(
                        $question['required']
                    ),
                'options' => $options,
            ];
        }

        $groups[] = [
            'id' => $gid,
            'title' => $title,
            'questions' => $questions,
        ];
    }

    return $groups;
}


/* =========================================================
 * 回答
 * ========================================================= */

function visible_questions(
    array $survey,
    array $answers
): array {
    $questions = all_questions($survey);

    if ($questions === []) {
        return [];
    }

    $byId = [];

    foreach ($questions as $question) {
        $byId[(string)$question['id']] =
            $question;
    }

    $visible = [];

    $current = $questions[0];

    $visited = [];

    while (
        isset($current['id']) &&
        !isset(
            $visited[
                (string)$current['id']
            ]
        )
    ) {
        $currentId =
            (string)$current['id'];

        $visited[$currentId] = true;

        $visible[] = $current;

        $answer =
            $answers[$currentId] ?? '';

        $next = '';

        if (
            $current['type'] === 'single'
        ) {
            foreach (
                $current['options'] ?? [] as $option
            ) {
                if (
                    (string)(
                        $option['id'] ?? ''
                    ) ===
                    (string)$answer
                ) {
                    $next =
                        (string)(
                            $option[
                                'nextQuestionId'
                            ] ?? ''
                        );
                    break;
                }
            }
        }

        if (
            $next === '' ||
            !isset($byId[$next])
        ) {
            break;
        }

        $current = $byId[$next];
    }

    return $visible;
}

function validate_answers(
    array $survey,
    array $answers
): array {
    $errors = [];

    foreach (
        visible_questions(
            $survey,
            $answers
        ) as $question
    ) {
        $id =
            (string)$question['id'];

        $value =
            $answers[$id] ?? '';

        if (
            !empty($question['required'])
        ) {
            $empty =
                $value === '' ||
                $value === null ||
                (
                    is_array($value) &&
                    count($value) === 0
                );

            if ($empty) {
                $errors[] =
                    $question['number'] .
                    ' は必須です。';
            }
        }

        if (
            $question['type'] === 'multiple' &&
            $value !== '' &&
            !is_array($value)
        ) {
            $errors[] =
                $question['number'] .
                ' の回答形式が不正です。';
        }
    }

    return $errors;
}


/* =========================================================
 * kintone
 * ========================================================= */

function normalize_kintone_subdomain(
    string $value
): string {
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    ) ?? $value;

    $value = preg_replace(
        '#/.*$#',
        '',
        $value
    ) ?? $value;

    if (
        str_ends_with(
            strtolower($value),
            '.cybozu.com'
        )
    ) {
        $value = substr(
            $value,
            0,
            -strlen('.cybozu.com')
        );
    }

    return trim($value);
}

function validate_kintone(
    array $config,
    bool $requirePassword = true
): array {
    $errors = [];

    $subdomain =
        normalize_kintone_subdomain(
            (string)(
                $config['subdomain'] ?? ''
            )
        );

    if (
        $subdomain === '' ||
        !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
            $subdomain
        )
    ) {
        $errors[] =
            'kintoneサブドメインが不正です。';
    }

    $appId = trim(
        (string)(
            $config['app_id'] ?? ''
        )
    );

    if (
        !ctype_digit($appId) ||
        (int)$appId < 1
    ) {
        $errors[] =
            '顧客管理アプリIDが不正です。';
    }

    if (
        trim(
            (string)(
                $config['username'] ?? ''
            )
        ) === ''
    ) {
        $errors[] =
            'kintoneログイン名を入力してください。';
    }

    if (
        $requirePassword &&
        trim(
            (string)(
                $config['password'] ?? ''
            )
        ) === ''
    ) {
        $errors[] =
            'kintoneパスワードを入力してください。';
    }

    $proxy =
        trim(
            (string)(
                $config['proxy'] ?? ''
            )
        );

    if (
        $proxy !== '' &&
        !preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )
    ) {
        $errors[] =
            'Proxyはhost:port形式で入力してください。';
    }

    return $errors;
}

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $errors =
        validate_kintone(
            $config,
            true
        );

    if ($errors !== []) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    $subdomain =
        normalize_kintone_subdomain(
            (string)$config['subdomain']
        );

    $url =
        'https://' .
        $subdomain .
        '.cybozu.com' .
        $path;

    $authorization =
        base64_encode(
            (string)$config['username'] .
            ':' .
            (string)$config['password']
        );

    $headers = [
        'X-Cybozu-Authorization: ' .
            $authorization,
        'Accept: application/json',
        'Connection: close',
    ];

    $content = '';

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($content === false) {
            throw new RuntimeException(
                'kintoneリクエストを生成できません。'
            );
        }

        $headers[] =
            'Content-Type: application/json';
    }

    $verify =
        !empty($config['verify_ssl']);

    $options = [
        'http' => [
            'method' =>
                strtoupper($method),
            'header' =>
                implode(
                    "\r\n",
                    $headers
                ),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => KINTONE_TIMEOUT,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
            'peer_name' =>
                $subdomain .
                '.cybozu.com',
        ],
    ];

    $proxy =
        trim(
            (string)(
                $config['proxy'] ?? ''
            )
        );

    if ($proxy !== '') {
        [$host, $port] =
            explode(':', $proxy, 2);

        $options['http']['proxy'] =
            'tcp://' .
            $host .
            ':' .
            (int)$port;

        $options['http']['request_fulluri'] =
            true;
    }

    $context =
        stream_context_create(
            $options
        );

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    $status = 0;

    foreach (
        $http_response_header ?? [] as $header
    ) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})/',
                $header,
                $match
            )
        ) {
            $status =
                (int)$match[1];
        }
    }

    if ($response === false) {
        throw new RuntimeException(
            'kintoneへの通信に失敗しました。'
        );
    }

    if (
        $status === 302 ||
        $status === 303
    ) {
        throw new RuntimeException(
            'kintoneからリダイレクト応答（' .
            $status .
            '）が返されました。'
        );
    }

    if (
        $status < 200 ||
        $status >= 300
    ) {
        $json =
            json_decode(
                $response,
                true
            );

        $code = is_array($json)
            ? (string)(
                $json['code'] ?? ''
            )
            : '';

        $message = is_array($json)
            ? (string)(
                $json['message'] ?? ''
            )
            : '';

        $detail =
            'kintone APIエラー HTTP ' .
            $status;

        if ($code !== '') {
            $detail .=
                ' [' . $code . ']';
        }

        if ($message !== '') {
            $detail .=
                ' ' . $message;
        }

        throw new RuntimeException(
            $detail
        );
    }

    $json =
        json_decode(
            $response,
            true
        );

    if (!is_array($json)) {
        throw new RuntimeException(
            'kintoneから正常なJSON応答を取得できませんでした。'
        );
    }

    return [
        'status' => $status,
        'body' => $json,
    ];
}

function kintone_test(
    array $config
): array {
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?id=' .
        rawurlencode(
            (string)$config['app_id']
        )
    );
}

function kintone_fields(
    array $config
): array {
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode(
            (string)$config['app_id']
        )
    );
}

function kintone_records(
    array $config
): array {
    return kintone_request(
        $config,
        'GET',
        '/k/v1/records.json?app=' .
        rawurlencode(
            (string)$config['app_id']
        ) .
        '&totalCount=true'
    );
}

function kintone_field_list(
    array $response
): array {
    $properties =
        $response['body']['properties']
        ?? [];

    if (!is_array($properties)) {
        return [];
    }

    $fields = [];

    foreach (
        $properties as $code => $field
    ) {
        if (!is_array($field)) {
            continue;
        }

        $fields[] = [
            'code' => (string)$code,
            'label' =>
                (string)(
                    $field['label'] ??
                    $code
                ),
            'type' =>
                (string)(
                    $field['type'] ?? ''
                ),
        ];
    }

    usort(
        $fields,
        static fn(
            array $a,
            array $b
        ): int =>
            strnatcasecmp(
                $a['code'],
                $b['code']
            )
    );

    return $fields;
}

function krecord(
    array $record,
    string $code
): string {
    if (
        $code === '' ||
        !isset($record[$code]) ||
        !is_array($record[$code])
    ) {
        return '';
    }

    $value =
        $record[$code]['value'] ?? '';

    if (!is_array($value)) {
        return (string)$value;
    }

    $result = [];

    foreach ($value as $item) {
        if (!is_array($item)) {
            $result[] = (string)$item;
        } elseif (
            isset($item['name'])
        ) {
            $result[] =
                (string)$item['name'];
        } elseif (
            isset($item['value'])
        ) {
            $result[] =
                (string)$item['value'];
        }
    }

    return implode(
        ' ',
        array_filter(
            $result,
            static fn(
                string $value
            ): bool => $value !== ''
        )
    );
}

function sync_kintone_customers(
    array $config
): array {
    $response =
        kintone_records($config);

    $records =
        $response['body']['records']
        ?? null;

    if (!is_array($records)) {
        throw new RuntimeException(
            'kintoneレコードを取得できませんでした。'
        );
    }

    $mapping =
        is_array(
            $config['mapping'] ?? null
        )
            ? $config['mapping']
            : [];

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $customers[] = [
            'id' => uid('customer'),

            'organization' =>
                krecord(
                    $record,
                    (string)(
                        $mapping[
                            'organization'
                        ] ?? ''
                    )
                ),

            'name' =>
                krecord(
                    $record,
                    (string)(
                        $mapping['name']
                        ?? ''
                    )
                ),

            'email' =>
                krecord(
                    $record,
                    (string)(
                        $mapping['email']
                        ?? ''
                    )
                ),

            'department' =>
                krecord(
                    $record,
                    (string)(
                        $mapping[
                            'department'
                        ] ?? ''
                    )
                ),

            'phone' =>
                krecord(
                    $record,
                    (string)(
                        $mapping['phone']
                        ?? ''
                    )
                ),

            'address' =>
                implode(
                    ' ',
                    array_filter(
                        array_map(
                            static function (
                                mixed $code
                            ) use ($record): string {
                                return krecord(
                                    $record,
                                    (string)$code
                                );
                            },
                            is_array(
                                $mapping[
                                    'address'
                                ] ?? null
                            )
                                ? $mapping[
                                    'address'
                                ]
                                : []
                        )
                    )
                ),

            'syncedAt' => now(),
        ];
    }

    return $customers;
}


/* =========================================================
 * SMTP
 * ========================================================= */

function validate_mail(
    array $config
): array {
    $errors = [];

    if (
        trim(
            (string)(
                $config['host'] ?? ''
            )
        ) === ''
    ) {
        $errors[] =
            'SMTPサーバを入力してください。';
    }

    $port =
        (int)(
            $config['port'] ?? 0
        );

    if (
        $port < 1 ||
        $port > 65535
    ) {
        $errors[] =
            'SMTPポートが不正です。';
    }

    if (
        !in_array(
            (string)(
                $config['encryption'] ?? ''
            ),
            ['ssl', 'tls', 'none'],
            true
        )
    ) {
        $errors[] =
            'SMTP暗号化方式が不正です。';
    }

    if (
        !filter_var(
            (string)(
                $config['from_email'] ?? ''
            ),
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            '送信元メールアドレスが不正です。';
    }

    if (
        !empty($config['auth'])
    ) {
        if (
            trim(
                (string)(
                    $config['username'] ?? ''
                )
            ) === ''
        ) {
            $errors[] =
                'SMTPユーザー名を入力してください。';
        }

        if (
            trim(
                (string)(
                    $config['password'] ?? ''
                )
            ) === ''
        ) {
            $errors[] =
                'SMTPパスワードを入力してください。';
        }
    }

    return $errors;
}

function smtp_read(
    $socket
): string {
    $result = '';

    while (
        ($line = fgets($socket)) !== false
    ) {
        $result .= $line;

        if (
            preg_match(
                '/^\d{3} /',
                $line
            )
        ) {
            break;
        }
    }

    return $result;
}

function smtp_expect(
    $socket,
    array $codes
): void {
    $response =
        smtp_read($socket);

    if (
        !preg_match(
            '/^(\d{3})/',
            $response,
            $match
        )
    ) {
        throw new RuntimeException(
            'SMTPレスポンスを取得できませんでした。'
        );
    }

    $code =
        (int)$match[1];

    if (!in_array(
        $code,
        $codes,
        true
    )) {
        throw new RuntimeException(
            'SMTPエラー：' .
            $code
        );
    }
}

function smtp_command(
    $socket,
    string $command,
    array $codes
): void {
    fwrite(
        $socket,
        $command . "\r\n"
    );

    smtp_expect(
        $socket,
        $codes
    );
}

function smtp_connect(
    array $config
) {
    $errors =
        validate_mail($config);

    if ($errors !== []) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    $host =
        (string)$config['host'];

    $port =
        (int)$config['port'];

    $encryption =
        (string)$config['encryption'];

    $transport = 'tcp://';

    if ($encryption === 'ssl') {
        $transport = 'ssl://';
    }

    $socket = @stream_socket_client(
        $transport .
        $host .
        ':' .
        $port,
        $errno,
        $errstr,
        SMTP_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        throw new RuntimeException(
            'SMTP接続失敗：' .
            $errstr
        );
    }

    stream_set_timeout(
        $socket,
        SMTP_TIMEOUT
    );

    smtp_expect(
        $socket,
        [220]
    );

    smtp_command(
        $socket,
        'EHLO localhost',
        [250]
    );

    if ($encryption === 'tls') {
        smtp_command(
            $socket,
            'STARTTLS',
            [220]
        );

        $crypto = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);

            throw new RuntimeException(
                'SMTP TLSを開始できませんでした。'
            );
        }

        smtp_command(
            $socket,
            'EHLO localhost',
            [250]
        );
    }

    if (!empty($config['auth'])) {
        smtp_command(
            $socket,
            'AUTH LOGIN',
            [334]
        );

        smtp_command(
            $socket,
            base64_encode(
                (string)$config['username']
            ),
            [334]
        );

        smtp_command(
            $socket,
            base64_encode(
                (string)$config['password']
            ),
            [235]
        );
    }

    return $socket;
}

function smtp_send(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    if (!filter_var(
        $to,
        FILTER_VALIDATE_EMAIL
    )) {
        throw new RuntimeException(
            '宛先メールアドレスが不正です。'
        );
    }

    $socket =
        smtp_connect($config);

    try {
        smtp_command(
            $socket,
            'MAIL FROM:<' .
            $config['from_email'] .
            '>',
            [250]
        );

        smtp_command(
            $socket,
            'RCPT TO:<' .
            $to .
            '>',
            [250, 251]
        );

        smtp_command(
            $socket,
            'DATA',
            [354]
        );

        $headers = [];

        $headers[] =
            'From: ' .
            mb_encode_mimeheader(
                (string)(
                    $config['from_name'] ?? ''
                ) ?: 'アンケート',
                'UTF-8'
            ) .
            ' <' .
            $config['from_email'] .
            '>';

        $headers[] =
            'To: <' . $to . '>';

        $headers[] =
            'Subject: ' .
            mb_encode_mimeheader(
                $subject,
                'UTF-8'
            );

        $headers[] =
            'MIME-Version: 1.0';

        $headers[] =
            'Content-Type: text/plain; charset=UTF-8';

        $headers[] =
            'Content-Transfer-Encoding: 8bit';

        $reply =
            trim(
                (string)(
                    $config['reply_to'] ?? ''
                )
            );

        if ($reply !== '') {
            $headers[] =
                'Reply-To: ' . $reply;
        }

        $message =
            implode(
                "\r\n",
                $headers
            ) .
            "\r\n\r\n" .
            str_replace(
                ["\r\n", "\r"],
                "\n",
                $body
            );

        $message =
            str_replace(
                "\n",
                "\r\n",
                $message
            );

        $message =
            preg_replace(
                '/^\./m',
                '..',
                $message
            ) ?? $message;

        fwrite(
            $socket,
            $message .
            "\r\n.\r\n"
        );

        smtp_expect(
            $socket,
            [250]
        );

        smtp_command(
            $socket,
            'QUIT',
            [221]
        );
    } finally {
        fclose($socket);
    }
}


/* =========================================================
 * POST処理
 *
 * 重要:
 *   case はこのswitch内だけに存在する。
 *   外部サービス関数からheader()は実行しない。
 * ========================================================= */

function handle_post(
    array &$data,
    array &$settings
): array {
    $action =
        post_string('action');

    switch ($action) {

        /* -------------------------------------------------
         * アンケート保存
         * ------------------------------------------------- */

        case 'save_survey':
            $id =
                post_string('survey_id');

            $title =
                mb_substr(
                    post_string('title'),
                    0,
                    MAX_TITLE
                );

            if ($title === '') {
                flash(
                    'error',
                    'タイトルを入力してください。'
                );

                return [
                    'screen' => 'edit',
                    'id' => $id,
                ];
            }

            $survey = [
                'id' =>
                    $id !== ''
                        ? $id
                        : uid('survey'),

                'title' => $title,

                'description' =>
                    mb_substr(
                        (string)post_value(
                            'description',
                            ''
                        ),
                        0,
                        MAX_DESCRIPTION
                    ),

                'startAt' =>
                    post_string('startAt'),

                'endAt' =>
                    post_string('endAt'),

                'status' =>
                    'draft',

                'numbering' =>
                    in_array(
                        post_string('numbering'),
                        ['global', 'group'],
                        true
                    )
                        ? post_string(
                            'numbering'
                        )
                        : 'global',

                'createdAt' => now(),
                'updatedAt' => now(),

                'groups' =>
                    normalize_groups(
                        post_value(
                            'groups',
                            []
                        )
                    ),
            ];

            $index =
                survey_index(
                    $data['surveys'],
                    $id
                );

            if ($index >= 0) {
                $old =
                    $data['surveys'][$index];

                $survey['status'] =
                    (string)(
                        $old['status']
                        ?? 'draft'
                    );

                $survey['createdAt'] =
                    $old['createdAt']
                    ?? now();

                $data['surveys'][$index] =
                    $survey;
            } else {
                $data['surveys'][] =
                    $survey;
            }

            $index =
                survey_index(
                    $data['surveys'],
                    (string)$survey['id']
                );

            recalc_numbers(
                $data['surveys'][$index]
            );

            save_data($data);

            flash(
                'success',
                'アンケートを保存しました。'
            );

            return [
                'screen' => 'list',
            ];


        /* -------------------------------------------------
         * 公開 / 停止 / 再開
         * ------------------------------------------------- */

        case 'change_status':
            $id =
                post_string('survey_id');

            $to =
                post_string('status');

            $index =
                survey_index(
                    $data['surveys'],
                    $id
                );

            if ($index < 0) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                return [
                    'screen' => 'list',
                ];
            }

            $from =
                (string)(
                    $data['surveys'][$index]['status']
                    ?? 'draft'
                );

            $allowed =
                (
                    $from === 'draft' &&
                    $to === 'published'
                ) ||
                (
                    $from === 'published' &&
                    $to === 'stopped'
                ) ||
                (
                    $from === 'stopped' &&
                    $to === 'published'
                );

            if (
                $from === 'ended' ||
                !$allowed
            ) {
                flash(
                    'error',
                    '指定された状態変更はできません。'
                );

                return [
                    'screen' => 'list',
                ];
            }

            $data['surveys'][$index]['status'] =
                $to;

            $data['surveys'][$index]['updatedAt'] =
                now();

            save_data($data);

            flash(
                'success',
                '状態を変更しました。'
            );

            return [
                'screen' => 'list',
            ];


        /* -------------------------------------------------
         * アンケート削除
         * ------------------------------------------------- */

        case 'delete_survey':
            $id =
                post_string('survey_id');

            $index =
                survey_index(
                    $data['surveys'],
                    $id
                );

            if ($index < 0) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                return [
                    'screen' => 'list',
                ];
            }

            array_splice(
                $data['surveys'],
                $index,
                1
            );

            save_data($data);

            flash(
                'success',
                'アンケートを削除しました。'
            );

            return [
                'screen' => 'list',
            ];


        /* -------------------------------------------------
         * アンケート複製
         * ------------------------------------------------- */

        case 'duplicate_survey':
            $id =
                post_string('survey_id');

            $survey =
                survey_get(
                    $data['surveys'],
                    $id
                );

            if (!$survey) {
                flash(
                    'error',
                    '複製元アンケートが見つかりません。'
                );

                return [
                    'screen' => 'list',
                ];
            }

            $survey['id'] =
                uid('survey');

            $survey['title'] =
                (string)$survey['title'] .
                '（複製）';

            $survey['status'] =
                'draft';

            $survey['createdAt'] =
                now();

            $survey['updatedAt'] =
                now();

            foreach (
                $survey['groups'] as &$group
            ) {
                $group['id'] =
                    uid('group');

                foreach (
                    $group['questions']
                    as &$question
                ) {
                    $question['id'] =
                        uid('question');

                    foreach (
                        $question['options']
                        as &$option
                    ) {
                        $option['id'] =
                            uid('option');
                    }

                    unset($option);
                }

                unset($question);
            }

            unset($group);

            recalc_numbers($survey);

            $data['surveys'][] =
                $survey;

            save_data($data);

            flash(
                'success',
                'アンケートを複製しました。'
            );

            return [
                'screen' => 'list',
            ];


        /* -------------------------------------------------
         * kintone設定保存
         *
         * パスワード未入力時は既存値を復号して保持。
         * 保存時には再暗号化される。
         * ------------------------------------------------- */

        case 'save_kintone':
            $current =
                $settings['kintone'];

            $password =
                post_string('password');

            if ($password === '') {
                $password =
                    (string)(
                        $current['password']
                        ?? ''
                    );
            }

            $config = [
                'subdomain' =>
                    normalize_kintone_subdomain(
                        post_string(
                            'subdomain'
                        )
                    ),

                'app_id' =>
                    post_string('app_id'),

                'username' =>
                    post_string('username'),

                'password' =>
                    $password,

                'proxy' =>
                    post_string('proxy'),

                'verify_ssl' =>
                    post_bool(
                        'verify_ssl'
                    ),

                'mapping' =>
                    $current['mapping']
                    ?? [],

                'fields' =>
                    $current['fields']
                    ?? [],

                'last_test' =>
                    $current['last_test']
                    ?? null,

                'last_sync' =>
                    $current['last_sync']
                    ?? null,
            ];

            $errors =
                validate_kintone(
                    $config,
                    true
                );

            if ($errors !== []) {
                flash(
                    'error',
                    implode(
                        "\n",
                        $errors
                    )
                );

                return [
                    'screen' => 'kintone',
                ];
            }

            $settings['kintone'] =
                $config;

            save_settings(
                $settings
            );

            flash(
                'success',
                'kintone接続設定を保存しました。'
            );

            return [
                'screen' => 'kintone',
            ];


        /* -------------------------------------------------
         * kintone接続テスト
         * ------------------------------------------------- */

        case 'test_kintone':
            try {
                $response =
                    kintone_test(
                        $settings['kintone']
                    );

                if (
                    ($response['status'] ?? 0) !==
                    200
                ) {
                    throw new RuntimeException(
                        'kintone接続テストが正常終了しませんでした。'
                    );
                }

                $settings['kintone'][
                    'last_test'
                ] = now();

                save_settings(
                    $settings
                );

                flash(
                    'success',
                    'kintone接続テスト成功。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'kintone接続テスト失敗：' .
                    safe_external_error($e)
                );
            }

            return [
                'screen' => 'kintone',
            ];


        /* -------------------------------------------------
         * kintone項目取得
         * ------------------------------------------------- */

        case 'load_kintone_fields':
            try {
                $response =
                    kintone_fields(
                        $settings['kintone']
                    );

                $settings['kintone']['fields'] =
                    kintone_field_list(
                        $response
                    );

                save_settings(
                    $settings
                );

                flash(
                    'success',
                    'kintone項目一覧を取得しました。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'kintone項目取得失敗：' .
                    safe_external_error($e)
                );
            }

            return [
                'screen' => 'kintone',
            ];


        /* -------------------------------------------------
         * kintone顧客マッピング保存
         * ------------------------------------------------- */

        case 'save_kintone_mapping':
            $current =
                $settings['kintone'];

            $address =
                post_value(
                    'mapping_address',
                    []
                );

            if (!is_array($address)) {
                $address = [];
            }

            $current['mapping'] = [
                'organization' =>
                    post_string(
                        'mapping_organization'
                    ),

                'name' =>
                    post_string(
                        'mapping_name'
                    ),

                'email' =>
                    post_string(
                        'mapping_email'
                    ),

                'department' =>
                    post_string(
                        'mapping_department'
                    ),

                'phone' =>
                    post_string(
                        'mapping_phone'
                    ),

                'address' =>
                    array_values(
                        array_map(
                            'strval',
                            $address
                        )
                    ),
            ];

            $settings['kintone'] =
                $current;

            save_settings(
                $settings
            );

            flash(
                'success',
                '顧客項目マッピングを保存しました。'
            );

            return [
                'screen' => 'kintone',
            ];


        /* -------------------------------------------------
         * kintone顧客同期
         *
         * API通信完了
         * ↓
         * 同期結果保存
         * ↓
         * 顧客一覧画面へ
         *
         * API関数自身は画面遷移しない。
         * ------------------------------------------------- */

        case 'sync_kintone':
            try {
                $customers =
                    sync_kintone_customers(
                        $settings['kintone']
                    );

                $data['customers'] =
                    $customers;

                $settings['kintone'][
                    'last_sync'
                ] = now();

                save_data(
                    $data
                );

                save_settings(
                    $settings
                );

                flash(
                    'success',
                    count($customers) .
                    '件の顧客情報を同期しました。'
                );

                /*
                 * ここが今回の「同期後一覧」の重要箇所。
                 *
                 * kintone設定画面へ戻さず、
                 * 同期済みcustomersを表示する画面へ進む。
                 */
                return [
                    'screen' => 'customers',
                ];
            } catch (Throwable $e) {
                flash(
                    'error',
                    'kintone顧客同期失敗：' .
                    safe_external_error($e)
                );

                return [
                    'screen' => 'kintone',
                ];
            }


        /* -------------------------------------------------
         * SMTP設定保存
         * ------------------------------------------------- */

        case 'save_mail':
            $current =
                $settings['mail'];

            $password =
                post_string('password');

            if ($password === '') {
                $password =
                    (string)(
                        $current['password']
                        ?? ''
                    );
            }

            $config = [
                'host' =>
                    post_string('host'),

                'port' =>
                    (int)post_string('port'),

                'encryption' =>
                    post_string(
                        'encryption'
                    ),

                'auth' =>
                    post_bool('auth'),

                'username' =>
                    post_string('username'),

                'password' =>
                    $password,

                'from_email' =>
                    post_string(
                        'from_email'
                    ),

                'from_name' =>
                    post_string(
                        'from_name'
                    ),

                'reply_to' =>
                    post_string(
                        'reply_to'
                    ),

                'last_test' =>
                    $current['last_test']
                    ?? null,
            ];

            $errors =
                validate_mail(
                    $config
                );

            if ($errors !== []) {
                flash(
                    'error',
                    implode(
                        "\n",
                        $errors
                    )
                );

                return [
                    'screen' => 'mail',
                ];
            }

            $settings['mail'] =
                $config;

            save_settings(
                $settings
            );

            flash(
                'success',
                'メール設定を保存しました。'
            );

            return [
                'screen' => 'mail',
            ];


        /* -------------------------------------------------
         * SMTP接続テスト
         * ------------------------------------------------- */

        case 'test_mail':
            try {
                $socket =
                    smtp_connect(
                        $settings['mail']
                    );

                fwrite(
                    $socket,
                    "QUIT\r\n"
                );

                fclose($socket);

                $settings['mail'][
                    'last_test'
                ] = now();

                save_settings(
                    $settings
                );

                flash(
                    'success',
                    'SMTP接続テスト成功。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'SMTP接続テスト失敗：' .
                    safe_external_error($e)
                );
            }

            return [
                'screen' => 'mail',
            ];


        /* -------------------------------------------------
         * テストメール
         * ------------------------------------------------- */

        case 'send_test_mail':
            $to =
                post_string(
                    'test_email'
                );

            try {
                smtp_send(
                    $settings['mail'],
                    $to,
                    'アンケートアプリ テストメール',
                    'SMTP設定のテストメールです。'
                );

                flash(
                    'success',
                    'テストメールを送信しました。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'テストメール送信失敗：' .
                    safe_external_error($e)
                );
            }

            return [
                'screen' => 'mail',
            ];


        /* -------------------------------------------------
         * 回答途中
         * ------------------------------------------------- */

        case 'answer_next':
            $surveyId =
                post_string('survey_id');

            $survey =
                survey_get(
                    $data['surveys'],
                    $surveyId
                );

            if (!$survey) {
                return [
                    'screen' => 'answer_message',
                ];
            }

            $answers =
                post_value(
                    'answers',
                    []
                );

            if (!is_array($answers)) {
                $answers = [];
            }

            $_SESSION[
                'answer_' . $surveyId
            ] = $answers;

            return [
                'screen' => 'answer_confirm',
                'id' => $surveyId,
            ];


        /* -------------------------------------------------
         * 回答戻る
         * ------------------------------------------------- */

        case 'answer_back':
            $surveyId =
                post_string('survey_id');

            return [
                'screen' => 'answer',
                'id' => $surveyId,
            ];


        /* -------------------------------------------------
         * 回答送信
         * ------------------------------------------------- */

        case 'submit_answer':
            $surveyId =
                post_string('survey_id');

            $survey =
                survey_get(
                    $data['surveys'],
                    $surveyId
                );

            if (!$survey) {
                return [
                    'screen' =>
                        'answer_message',
                ];
            }

            $answers =
                $_SESSION[
                    'answer_' . $surveyId
                ] ?? [];

            if (!is_array($answers)) {
                $answers = [];
            }

            $errors =
                validate_answers(
                    $survey,
                    $answers
                );

            if ($errors !== []) {
                flash(
                    'error',
                    implode(
                        "\n",
                        $errors
                    )
                );

                return [
                    'screen' => 'answer',
                    'id' => $surveyId,
                ];
            }

            $data['answers'][] = [
                'id' => uid('answer'),
                'surveyId' => $surveyId,
                'answers' => $answers,
                'createdAt' => now(),
            ];

            save_data($data);

            unset(
                $_SESSION[
                    'answer_' . $surveyId
                ]
            );

            /*
             * 回答者フローは管理者画面へ戻さない。
             */
            return [
                'screen' => 'complete',
                'id' => $surveyId,
            ];


        default:
            flash(
                'error',
                '不明な操作です。'
            );

            return [
                'screen' => 'list',
            ];
    }
}


/* =========================================================
 * HTML共通
 * ========================================================= */

function admin_header(
    string $title,
    ?array $flash = null
): void {
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - <?= h(APP_TITLE) ?></title>
<style>
*{box-sizing:border-box}
body{
 margin:0;
 background:#f8fafc;
 color:#1e293b;
 font-family:
 -apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",
 "Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
}
header{
 background:#fff;
 border-bottom:1px solid #dbe2ea;
}
.nav{
 max-width:1400px;
 margin:auto;
 padding:16px 20px;
 display:flex;
 flex-wrap:wrap;
 gap:14px;
 align-items:center;
}
.nav strong{
 margin-right:auto;
}
.nav a{
 color:#2563eb;
 text-decoration:none;
}
.wrap{
 max-width:1400px;
 margin:0 auto;
 padding:24px 20px 60px;
}
.card{
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:14px;
 padding:22px;
 margin-bottom:18px;
 box-shadow:0 4px 18px rgba(15,23,42,.05);
}
.grid{
 display:grid;
 grid-template-columns:
 repeat(auto-fit,minmax(260px,1fr));
 gap:16px;
}
.field{
 margin-bottom:15px;
}
.field label{
 display:block;
 font-weight:600;
 margin-bottom:6px;
}
input[type=text],
input[type=email],
input[type=password],
input[type=number],
input[type=datetime-local],
select,
textarea{
 width:100%;
 padding:10px 12px;
 border:1px solid #cbd5e1;
 border-radius:8px;
 font:inherit;
 background:#fff;
}
textarea{
 min-height:130px;
 resize:vertical;
}
button,.btn{
 display:inline-block;
 border:1px solid #cbd5e1;
 background:#fff;
 color:#1e293b;
 border-radius:8px;
 padding:9px 14px;
 cursor:pointer;
 text-decoration:none;
 font:inherit;
}
button.primary,.btn.primary{
 background:#2563eb;
 border-color:#2563eb;
 color:#fff;
}
button.danger{
 color:#fff;
 background:#dc2626;
 border-color:#dc2626;
}
.actions{
 display:flex;
 flex-wrap:wrap;
 gap:8px;
 margin-top:15px;
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
 padding:10px;
 border-bottom:1px solid #e2e8f0;
 text-align:left;
 vertical-align:top;
}
th{
 background:#f8fafc;
}
.flash{
 padding:14px;
 border-radius:10px;
 margin-bottom:18px;
 white-space:pre-line;
}
.flash.success{
 background:#dcfce7;
 color:#166534;
}
.flash.error{
 background:#fee2e2;
 color:#991b1b;
}
.flash.warning{
 background:#fef3c7;
 color:#92400e;
}
.badge{
 display:inline-block;
 padding:4px 8px;
 border-radius:999px;
 font-size:12px;
}
.badge.success{
 background:#dcfce7;
 color:#166534;
}
.badge.warning{
 background:#fef3c7;
 color:#92400e;
}
.badge.gray{
 background:#e2e8f0;
 color:#475569;
}
.small{
 color:#64748b;
 font-size:13px;
}
.question{
 border:1px solid #dbe2ea;
 border-radius:10px;
 padding:16px;
 margin-bottom:12px;
}
.option{
 padding:6px 0;
}
@media(max-width:700px){
 .wrap{padding:16px 12px 40px}
 .card{padding:16px}
}
</style>
</head>
<body>
<header>
<div class="nav">
<strong><?= h(APP_TITLE) ?></strong>
<a href="<?= h(app_url(['screen'=>'list'])) ?>">
アンケート一覧
</a>
<a href="<?= h(app_url(['screen'=>'kintone'])) ?>">
kintone
</a>
<a href="<?= h(app_url(['screen'=>'mail'])) ?>">
メール設定
</a>
</div>
</header>
<main class="wrap">
<?php if ($flash): ?>
<div class="flash <?= h($flash['type'] ?? 'success') ?>">
<?= nl2br(h($flash['message'] ?? '')) ?>
</div>
<?php endif; ?>
<?php
}

function admin_footer(): void
{
?>
</main>
</body>
</html>
<?php
}


/* =========================================================
 * アンケート一覧
 * ========================================================= */

function render_list(
    array $data
): void {
    $search =
        get_string('q');

    $status =
        get_string('status');

    $sort =
        get_string('sort');

    $surveys =
        $data['surveys'];

    $surveys =
        array_values(
            array_filter(
                $surveys,
                static function (
                    array $survey
                ) use (
                    $search,
                    $status
                ): bool {
                    if (
                        $search !== '' &&
                        mb_stripos(
                            (string)(
                                $survey['title'] ?? ''
                            ),
                            $search
                        ) === false
                    ) {
                        return false;
                    }

                    if (
                        $status !== '' &&
                        $status !== 'all' &&
                        (
                            (string)(
                                $survey['status']
                                ?? ''
                            ) !== $status
                        )
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
            if ($sort === 'old') {
                return strcmp(
                    (string)(
                        $a['updatedAt'] ?? ''
                    ),
                    (string)(
                        $b['updatedAt'] ?? ''
                    )
                );
            }

            if ($sort === 'answers') {
                return 0;
            }

            return strcmp(
                (string)(
                    $b['updatedAt'] ?? ''
                ),
                (string)(
                    $a['updatedAt'] ?? ''
                )
            );
        }
    );

    $flash = flash_get();

    admin_header(
        'アンケート一覧',
        $flash
    );
?>
<div class="card">
<h1>アンケート一覧</h1>

<form method="get">
<input type="hidden"
       name="screen"
       value="list">

<div class="grid">
<div class="field">
<label>タイトル検索</label>
<input type="text"
       name="q"
       value="<?= h($search) ?>"
       placeholder="タイトル部分一致">
</div>

<div class="field">
<label>ステータス</label>
<select name="status">
<option value="all">すべて</option>
<option value="published"
 <?= $status==='published'?'selected':'' ?>>
公開中
</option>
<option value="draft"
 <?= $status==='draft'?'selected':'' ?>>
下書き
</option>
<option value="stopped"
 <?= $status==='stopped'?'selected':'' ?>>
停止
</option>
<option value="ended"
 <?= $status==='ended'?'selected':'' ?>>
終了
</option>
</select>
</div>

<div class="field">
<label>ソート</label>
<select name="sort">
<option value="new"
 <?= $sort!=='old'?'selected':'' ?>>
更新日：新しい順
</option>
<option value="old"
 <?= $sort==='old'?'selected':'' ?>>
更新日：古い順
</option>
</select>
</div>
</div>

<button class="primary">検索</button>
<a class="btn"
   href="<?= h(app_url(['screen'=>'edit'])) ?>">
新規作成
</a>
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
<th>作成日</th>
<th>更新日</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>
<tbody>
<?php foreach ($surveys as $survey): ?>
<?php
$count = 0;

foreach (
    $data['answers'] as $answer
) {
    if (
        (string)(
            $answer['surveyId'] ?? ''
        ) ===
        (string)(
            $survey['id'] ?? ''
        )
    ) {
        $count++;
    }
}
?>
<tr>
<td>
<strong>
<?= h($survey['title'] ?? '') ?>
</strong>
</td>

<td>
<?= h($survey['startAt'] ?? '') ?>
<br>
～
<br>
<?= h($survey['endAt'] ?? '') ?>
</td>

<td>
<span class="badge <?= h(
    status_class(
        (string)(
            $survey['status'] ?? ''
        )
    )
) ?>">
<?= h(
    status_label(
        (string)(
            $survey['status'] ?? ''
        )
    )
) ?>
</span>
</td>

<td><?= h($survey['createdAt'] ?? '') ?></td>
<td><?= h($survey['updatedAt'] ?? '') ?></td>
<td><?= h($count) ?></td>

<td>
<div class="actions">
<a class="btn"
 href="<?= h(app_url([
     'screen'=>'edit',
     'id'=>$survey['id']
 ])) ?>">
確認・編集
</a>

<a class="btn"
 href="<?= h(app_url([
     'screen'=>'analytics',
     'id'=>$survey['id']
 ])) ?>">
集計
</a>

<a class="btn"
 href="<?= h(app_url([
     'screen'=>'send',
     'id'=>$survey['id']
 ])) ?>">
送信
</a>

<form method="post"
      onsubmit="return confirm('複製しますか？')">
<input type="hidden"
       name="action"
       value="duplicate_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button>複製</button>
</form>

<?php if (
    ($survey['status'] ?? '') === 'draft'
): ?>
<form method="post"
      onsubmit="return confirm('公開しますか？')">
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden"
       name="status"
       value="published">
<button class="primary">公開</button>
</form>
<?php elseif (
    ($survey['status'] ?? '') === 'published'
): ?>
<form method="post"
      onsubmit="return confirm('停止しますか？')">
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden"
       name="status"
       value="stopped">
<button>停止</button>
</form>
<?php elseif (
    ($survey['status'] ?? '') === 'stopped'
): ?>
<form method="post"
      onsubmit="return confirm('再開しますか？')">
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden"
       name="status"
       value="published">
<button class="primary">再開</button>
</form>
<?php endif; ?>

<form method="post"
      onsubmit="return confirm('削除しますか？')">
<input type="hidden"
       name="action"
       value="delete_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="danger">削除</button>
</form>
</div>
</td>
</tr>
<?php endforeach; ?>

<?php if ($surveys === []): ?>
<tr>
<td colspan="7">
該当するアンケートがありません。
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
<?php
    admin_footer();
}


/* =========================================================
 * 顧客一覧
 *
 * kintone同期完了後にここを表示する。
 * ========================================================= */

function render_customers(
    array $data
): void {
    $flash = flash_get();

    admin_header(
        '顧客一覧',
        $flash
    );
?>
<div class="card">
<h1>顧客一覧</h1>

<p class="small">
kintoneから同期した顧客情報です。
</p>

<div class="actions">
<a class="btn"
 href="<?= h(app_url(['screen'=>'kintone'])) ?>">
kintone設定へ戻る
</a>
</div>
</div>

<div class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
<th>組織</th>
<th>氏名</th>
<th>メールアドレス</th>
<th>部署</th>
<th>電話</th>
<th>住所</th>
<th>同期日時</th>
</tr>
</thead>

<tbody>
<?php foreach (
    $data['customers'] as $customer
): ?>
<tr>
<td><?= h(
    $customer['organization'] ?? ''
) ?></td>
<td><?= h(
    $customer['name'] ?? ''
) ?></td>
<td><?= h(
    $customer['email'] ?? ''
) ?></td>
<td><?= h(
    $customer['department'] ?? ''
) ?></td>
<td><?= h(
    $customer['phone'] ?? ''
) ?></td>
<td><?= h(
    $customer['address'] ?? ''
) ?></td>
<td><?= h(
    $customer['syncedAt'] ?? ''
) ?></td>
</tr>
<?php endforeach; ?>

<?php if (
    empty($data['customers'])
): ?>
<tr>
<td colspan="7">
同期済み顧客がありません。
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
<?php
    admin_footer();
}


/* =========================================================
 * kintone設定画面
 * ========================================================= */

function render_kintone(
    array $settings
): void {
    $flash = flash_get();

    $config =
        $settings['kintone'];

    $display =
        $config;

    /*
     * パスワードはHTMLへ出力しない。
     */
    $display['password'] = '';

    admin_header(
        'kintone連携設定',
        $flash
    );
?>
<div class="card">
<h1>kintone連携設定</h1>

<form method="post">
<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid">
<div class="field">
<label>サブドメイン</label>
<input type="text"
       name="subdomain"
       value="<?= h(
           $display['subdomain']
       ) ?>"
       placeholder="example">
</div>

<div class="field">
<label>顧客管理アプリID</label>
<input type="number"
       name="app_id"
       value="<?= h(
           $display['app_id']
       ) ?>"
       min="1">
</div>

<div class="field">
<label>ログイン名</label>
<input type="text"
       name="username"
       value="<?= h(
           $display['username']
       ) ?>">
</div>

<div class="field">
<label>パスワード</label>
<input type="password"
       name="password"
       value=""
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</div>

<div class="field">
<label>Proxy</label>
<input type="text"
       name="proxy"
       value="<?= h(
           $display['proxy']
       ) ?>"
       placeholder="host:port">
</div>
</div>

<div class="field">
<label>
<input type="checkbox"
       name="verify_ssl"
       value="1"
 <?= !empty(
     $display['verify_ssl']
 ) ? 'checked' : '' ?>>
SSL証明書を検証する
</label>
</div>

<button class="primary">
設定保存
</button>
</form>
</div>

<div class="card">
<h2>接続確認</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="test_kintone">
<button class="primary">
接続テスト
</button>
</form>

<?php if (
    !empty($config['last_test'])
): ?>
<p class="small">
最終接続テスト：
<?= h($config['last_test']) ?>
</p>
<?php endif; ?>
</div>

<div class="card">
<h2>項目取得</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="load_kintone_fields">
<button>
項目一覧を再取得
</button>
</form>

<?php if (
    !empty($config['fields'])
): ?>
<div class="table-wrap">
<table>
<tr>
<th>コード</th>
<th>ラベル</th>
<th>タイプ</th>
</tr>

<?php foreach (
    $config['fields'] as $field
): ?>
<tr>
<td><?= h(
    $field['code'] ?? ''
) ?></td>
<td><?= h(
    $field['label'] ?? ''
) ?></td>
<td><?= h(
    $field['type'] ?? ''
) ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>
</div>

<div class="card">
<h2>顧客情報同期</h2>

<p>
kintoneの顧客管理アプリから顧客情報を取得し、
同期済み顧客一覧を表示します。
</p>

<form method="post"
      onsubmit="return confirm('顧客情報を同期しますか？')">
<input type="hidden"
       name="action"
       value="sync_kintone">
<button class="primary">
顧客情報を同期
</button>
</form>

<?php if (
    !empty($config['last_sync'])
): ?>
<p class="small">
最終同期：
<?= h($config['last_sync']) ?>
</p>
<?php endif; ?>
</div>

<div class="card">
<h2>顧客項目マッピング</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<?php
$mapping =
    $config['mapping'] ?? [];

$fields =
    $config['fields'] ?? [];

$mapFields = [
    'organization' => '組織',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署',
    'phone' => '電話',
];
?>

<?php foreach (
    $mapFields as $key => $label
): ?>
<div class="field">
<label><?= h($label) ?></label>

<select name="mapping_<?= h($key) ?>">
<option value="">未設定</option>

<?php foreach (
    $fields as $field
): ?>
<option value="<?= h(
    $field['code']
) ?>"
 <?= (
     ($mapping[$key] ?? '') ===
     $field['code']
 )
     ? 'selected'
     : '' ?>>
<?= h(
    $field['code'] .
    ' / ' .
    $field['label']
) ?>
</option>
<?php endforeach; ?>
</select>
</div>
<?php endforeach; ?>

<div class="field">
<label>住所（複数項目可）</label>

<?php foreach (
    $fields as $field
): ?>
<label>
<input type="checkbox"
       name="mapping_address[]"
       value="<?= h(
           $field['code']
       ) ?>"
 <?= in_array(
     $field['code'],
     $mapping['address'] ?? [],
     true
 )
     ? 'checked'
     : '' ?>>
<?= h(
    $field['code'] .
    ' / ' .
    $field['label']
) ?>
</label>
<?php endforeach; ?>
</div>

<button class="primary">
マッピング保存
</button>
</form>
</div>
<?php
    admin_footer();
}


/* =========================================================
 * メール設定
 * ========================================================= */

function render_mail(
    array $settings
): void {
    $flash = flash_get();

    $config =
        $settings['mail'];

    admin_header(
        'メールサーバ設定',
        $flash
    );
?>
<div class="card">
<h1>メールサーバ設定</h1>

<form method="post">
<input type="hidden"
       name="action"
       value="save_mail">

<div class="grid">
<div class="field">
<label>SMTPサーバ</label>
<input type="text"
       name="host"
       value="<?= h(
           $config['host']
       ) ?>">
</div>

<div class="field">
<label>ポート</label>
<input type="number"
       name="port"
       value="<?= h(
           $config['port']
       ) ?>"
       min="1"
       max="65535">
</div>

<div class="field">
<label>暗号化</label>
<select name="encryption">
<option value="tls"
 <?= $config['encryption']==='tls'
     ? 'selected'
     : '' ?>>
TLS
</option>
<option value="ssl"
 <?= $config['encryption']==='ssl'
     ? 'selected'
     : '' ?>>
SSL
</option>
<option value="none"
 <?= $config['encryption']==='none'
     ? 'selected'
     : '' ?>>
なし
</option>
</select>
</div>

<div class="field">
<label>SMTPユーザー名</label>
<input type="text"
       name="username"
       value="<?= h(
           $config['username']
       ) ?>">
</div>

<div class="field">
<label>SMTPパスワード</label>
<input type="password"
       name="password"
       value=""
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</div>

<div class="field">
<label>送信元メールアドレス</label>
<input type="email"
       name="from_email"
       value="<?= h(
           $config['from_email']
       ) ?>">
</div>

<div class="field">
<label>送信元名</label>
<input type="text"
       name="from_name"
       value="<?= h(
           $config['from_name']
       ) ?>">
</div>

<div class="field">
<label>返信先</label>
<input type="email"
       name="reply_to"
       value="<?= h(
           $config['reply_to']
       ) ?>">
</div>
</div>

<div class="field">
<label>
<input type="checkbox"
       name="auth"
       value="1"
 <?= !empty($config['auth'])
     ? 'checked'
     : '' ?>>
SMTP認証を使用する
</label>
</div>

<button class="primary">
設定保存
</button>
</form>
</div>

<div class="card">
<h2>SMTP接続テスト</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="test_mail">
<button class="primary">
接続テスト
</button>
</form>

<?php if (
    !empty($config['last_test'])
): ?>
<p class="small">
最終テスト：
<?= h($config['last_test']) ?>
</p>
<?php endif; ?>
</div>

<div class="card">
<h2>テストメール</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="send_test_mail">

<div class="field">
<label>宛先</label>
<input type="email"
       name="test_email"
       required>
</div>

<button class="primary">
テストメール送信
</button>
</form>
</div>
<?php
    admin_footer();
}


/* =========================================================
 * 編集画面
 * ========================================================= */

function render_edit(
    array $data,
    ?string $id
): void {
    $survey =
        $id !== null
            ? survey_get(
                $data['surveys'],
                $id
            )
            : null;

    if (!$survey) {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [],
        ];
    }

    $flash = flash_get();

    admin_header(
        'アンケート編集',
        $flash
    );
?>
<div class="card">
<h1>
<?= $survey['id'] !== ''
    ? 'アンケート編集'
    : 'アンケート作成' ?>
</h1>

<form method="post">
<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<div class="field">
<label>タイトル</label>
<input type="text"
       name="title"
       value="<?= h(
           $survey['title']
       ) ?>"
       maxlength="<?= MAX_TITLE ?>"
       required>
</div>

<div class="field">
<label>説明</label>
<textarea name="description"
          maxlength="<?= MAX_DESCRIPTION ?>"><?= h(
    $survey['description']
) ?></textarea>
</div>

<div class="grid">
<div class="field">
<label>開始日時</label>
<input type="datetime-local"
       name="startAt"
       value="<?= h(
           $survey['startAt']
       ) ?>">
</div>

<div class="field">
<label>終了日時</label>
<input type="datetime-local"
       name="endAt"
       value="<?= h(
           $survey['endAt']
       ) ?>">
</div>
</div>

<div class="field">
<label>質問番号</label>
<select name="numbering">
<option value="global"
 <?= ($survey['numbering'] ?? 'global')==='global'
     ? 'selected'
     : '' ?>>
全体通し番号
</option>
<option value="group"
 <?= ($survey['numbering'] ?? '')==='group'
     ? 'selected'
     : '' ?>>
グループ別番号
</option>
</select>
</div>

<?php foreach (
    $survey['groups'] as $group
): ?>
<div class="card">
<div class="field">
<label>グループ</label>
<input type="text"
       name="groups[<?= h(
           $group['id']
       ) ?>][title]"
       value="<?= h(
           $group['title']
       ) ?>">

<input type="hidden"
       name="groups[<?= h(
           $group['id']
       ) ?>][id]"
       value="<?= h(
           $group['id']
       ) ?>">
</div>

<?php foreach (
    $group['questions'] as $question
): ?>
<div class="question">
<input type="hidden"
       name="groups[<?= h(
           $group['id']
       ) ?>][questions][<?= h(
           $question['id']
       ) ?>][id]"
       value="<?= h(
           $question['id']
       ) ?>">

<div class="field">
<label>
<?= h($question['number']) ?>
質問
</label>

<input type="text"
       name="groups[<?= h(
           $group['id']
       ) ?>][questions][<?= h(
           $question['id']
       ) ?>][text]"
       value="<?= h(
           $question['text']
       ) ?>">
</div>

<div class="field">
<label>回答形式</label>
<select name="groups[<?= h(
    $group['id']
) ?>][questions][<?= h(
    $question['id']
) ?>][type]">
<option value="single"
 <?= $question['type']==='single'
     ? 'selected'
     : '' ?>>
単一選択
</option>
<option value="multiple"
 <?= $question['type']==='multiple'
     ? 'selected'
     : '' ?>>
複数選択
</option>
<option value="text"
 <?= $question['type']==='text'
     ? 'selected'
     : '' ?>>
テキスト
</option>
</select>
</div>

<div class="field">
<label>
<input type="checkbox"
       name="groups[<?= h(
           $group['id']
       ) ?>][questions][<?= h(
           $question['id']
       ) ?>][required]"
       value="1"
 <?= !empty(
     $question['required']
 )
     ? 'checked'
     : '' ?>>
必須
</label>
</div>

<?php if (
    $question['type'] !== 'text'
): ?>

<?php foreach (
    $question['options'] as $option
): ?>
<div class="option">
<input type="hidden"
       name="groups[<?= h(
           $group['id']
       ) ?>][questions][<?= h(
           $question['id']
       ) ?>][options][<?= h(
           $option['id']
       ) ?>][id]"
       value="<?= h(
           $option['id']
       ) ?>">

<input type="text"
       name="groups[<?= h(
           $group['id']
       ) ?>][questions][<?= h(
           $question['id']
       ) ?>][options][<?= h(
           $option['id']
       ) ?>][label]"
       value="<?= h(
           $option['label']
       ) ?>">
</div>
<?php endforeach; ?>

<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<button class="primary">
保存
</button>

<a class="btn"
   href="<?= h(
       app_url(['screen'=>'list'])
   ) ?>">
キャンセル
</a>

</form>
</div>
<?php
    admin_footer();
}


/* =========================================================
 * 送信画面
 * ========================================================= */

function render_send(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if (!$survey) {
        render_error(
            '対象アンケートが見つかりません。'
        );
        return;
    }

    $flash = flash_get();

    admin_header(
        '顧客選択・メール送信',
        $flash
    );
?>
<div class="card">
<h1>
<?= h($survey['title']) ?>
</h1>

<p>
顧客を選択してアンケートを送信します。
</p>

<form method="post">
<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">

<div class="field">
<label>件名</label>
<input type="text"
       name="subject"
       value="<?= h(
           $survey['title']
       ) ?>">
</div>

<div class="field">
<label>本文</label>
<textarea name="body">アンケートへのご協力をお願いいたします。

<?= h(public_url($id)) ?></textarea>
</div>

<div class="table-wrap">
<table>
<tr>
<th>選択</th>
<th>組織</th>
<th>氏名</th>
<th>メール</th>
</tr>

<?php foreach (
    $data['customers'] as $customer
): ?>
<tr>
<td>
<input type="checkbox"
       name="customer_ids[]"
       value="<?= h(
           $customer['id']
       ) ?>">
</td>
<td><?= h(
    $customer['organization'] ?? ''
) ?></td>
<td><?= h(
    $customer['name'] ?? ''
) ?></td>
<td><?= h(
    $customer['email'] ?? ''
) ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="actions">
<button class="primary"
        onclick="return confirm('選択した顧客へ一括送信しますか？')">
一括送信
</button>

<a class="btn"
   href="<?= h(
       app_url([
           'screen'=>'list'
       ])
   ) ?>">
一覧へ戻る
</a>
</div>
</form>
</div>
<?php
    admin_footer();
}


/* =========================================================
 * 集計
 * ========================================================= */

function render_analytics(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if (!$survey) {
        render_error(
            '対象アンケートが見つかりません。'
        );
        return;
    }

    $answers =
        array_values(
            array_filter(
                $data['answers'],
                static fn(array $answer): bool =>
                    (string)(
                        $answer['surveyId'] ?? ''
                    ) === $id
            )
        );

    $flash = flash_get();

    admin_header(
        '回答集計',
        $flash
    );
?>
<div class="card">
<h1>
<?= h($survey['title']) ?>
</h1>

<p>
回答数：
<strong><?= h(
    count($answers)
) ?></strong>
</p>
</div>

<?php foreach (
    all_questions($survey) as $question
): ?>
<div class="card">
<h2>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</h2>

<?php if (
    $question['type'] === 'text'
): ?>

<?php foreach (
    $answers as $answer
): ?>
<?php
$value =
    $answer['answers'][
        $question['id']
    ] ?? '';
?>
<?php if (
    $value !== '' &&
    $value !== null
): ?>
<p>
<?= nl2br(
    h(
        is_array($value)
            ? implode(', ', $value)
            : (string)$value
    )
) ?>
</p>
<?php endif; ?>
<?php endforeach; ?>

<?php else: ?>

<?php
$counts = [];

foreach (
    $question['options'] as $option
) {
    $counts[$option['id']] = 0;
}

foreach (
    $answers as $answer
) {
    $value =
        $answer['answers'][
            $question['id']
        ] ?? '';

    if (is_array($value)) {
        foreach ($value as $selected) {
            if (isset($counts[$selected])) {
                $counts[$selected]++;
            }
        }
    } elseif (
        isset($counts[$value])
    ) {
        $counts[$value]++;
    }
}
?>

<table>
<tr>
<th>選択肢</th>
<th>回答数</th>
</tr>

<?php foreach (
    $question['options'] as $option
): ?>
<tr>
<td><?= h(
    $option['label']
) ?></td>
<td><?= h(
    $counts[$option['id']] ?? 0
) ?></td>
</tr>
<?php endforeach; ?>
</table>

<?php endif; ?>
</div>
<?php endforeach; ?>

<?php
    admin_footer();
}


/* =========================================================
 * 回答者画面
 * ========================================================= */

function render_answer(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if (!$survey) {
        render_answer_message(
            'アンケートが見つかりません。'
        );
        return;
    }

    if (
        ($survey['status'] ?? '') !==
        'published'
    ) {
        render_answer_message(
            '現在、このアンケートは回答できません。'
        );
        return;
    }

    $answers =
        $_SESSION[
            'answer_' . $id
        ] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= h(
    $survey['title']
) ?></title>
<style>
body{
 margin:0;
 background:#f8fafc;
 color:#1e293b;
 font-family:
 -apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",Meiryo,sans-serif;
}
.wrap{
 max-width:700px;
 margin:0 auto;
 padding:20px;
}
.card{
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:14px;
 padding:22px;
 margin-bottom:18px;
}
.question{
 margin-bottom:22px;
}
input[type=text],
textarea{
 width:100%;
 box-sizing:border-box;
 padding:12px;
 border:1px solid #cbd5e1;
 border-radius:8px;
 font:inherit;
}
textarea{
 min-height:140px;
}
.choice{
 display:block;
 padding:12px;
 border:1px solid #dbe2ea;
 border-radius:8px;
 margin:8px 0;
}
button{
 width:100%;
 padding:13px;
 border:0;
 border-radius:8px;
 background:#2563eb;
 color:#fff;
 font:inherit;
 cursor:pointer;
}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
<h1><?= h(
    $survey['title']
) ?></h1>

<?php if (
    !empty($survey['description'])
): ?>
<p><?= nl2br(
    h($survey['description'])
) ?></p>
<?php endif; ?>
</div>

<form method="post">
<input type="hidden"
       name="action"
       value="answer_next">

<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">

<?php foreach (
    visible_questions(
        $survey,
        $answers
    ) as $question
): ?>
<div class="card question">
<h2>
<?= h($question['number']) ?>
</h2>

<p>
<?= nl2br(
    h($question['text'])
) ?>
<?php if (
    !empty($question['required'])
): ?>
<span>（必須）</span>
<?php endif; ?>
</p>

<?php if (
    $question['type'] === 'text'
): ?>

<textarea
 name="answers[<?= h(
     $question['id']
 ) ?>]"><?= h(
     $answers[
         $question['id']
     ] ?? ''
 ) ?></textarea>

<?php elseif (
    $question['type'] === 'single'
): ?>

<?php foreach (
    $question['options'] as $option
): ?>
<label class="choice">
<input type="radio"
       name="answers[<?= h(
           $question['id']
       ) ?>]"
       value="<?= h(
           $option['id']
       ) ?>"
 <?= (
     (string)(
         $answers[
             $question['id']
             ] ?? ''
     ) ===
     (string)$option['id']
 )
     ? 'checked'
     : '' ?>>
<?= h(
    $option['label']
) ?>
</label>
<?php endforeach; ?>

<?php else: ?>

<?php
$current =
    is_array(
        $answers[
            $question['id']
        ] ?? null
    )
        ? $answers[
            $question['id']
        ]
        : [];
?>

<?php foreach (
    $question['options'] as $option
): ?>
<label class="choice">
<input type="checkbox"
       name="answers[<?= h(
           $question['id']
       ) ?>][]"
       value="<?= h(
           $option['id']
       ) ?>"
 <?= in_array(
     $option['id'],
     $current,
     true
 )
     ? 'checked'
     : '' ?>>
<?= h(
    $option['label']
) ?>
</label>
<?php endforeach; ?>

<?php endif; ?>
</div>
<?php endforeach; ?>

<button>
回答内容を確認する
</button>
</form>
</div>
</body>
</html>
<?php
}

function render_answer_confirm(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if (!$survey) {
        render_answer_message(
            'アンケートが見つかりません。'
        );
        return;
    }

    $answers =
        $_SESSION[
            'answer_' . $id
        ] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>回答確認</title>
<style>
body{
 margin:0;
 background:#f8fafc;
 font-family:
 -apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",Meiryo,sans-serif;
 color:#1e293b;
}
.wrap{
 max-width:700px;
 margin:auto;
 padding:20px;
}
.card{
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:14px;
 padding:24px;
 margin-bottom:18px;
}
.actions{
 display:flex;
 gap:10px;
}
button{
 padding:12px 18px;
 border-radius:8px;
 border:1px solid #cbd5e1;
 background:#fff;
}
.primary{
 background:#2563eb;
 color:#fff;
 border-color:#2563eb;
}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
<h1>回答確認</h1>
<p>
内容を確認して送信してください。
</p>

<?php foreach (
    visible_questions(
        $survey,
        $answers
    ) as $question
): ?>
<div>
<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>
<br>

<?php
$value =
    $answers[
        $question['id']
    ] ?? '';

if (is_array($value)) {
    $labels = [];

    foreach (
        $question['options']
        as $option
    ) {
        if (in_array(
            $option['id'],
            $value,
            true
        )) {
            $labels[] =
                $option['label'];
        }
    }

    echo h(
        implode(
            ', ',
            $labels
        )
    );
} else {
    $label = $value;

    foreach (
        $question['options']
        as $option
    ) {
        if (
            (string)$option['id'] ===
            (string)$value
        ) {
            $label =
                $option['label'];
            break;
        }
    }

    echo nl2br(
        h((string)$label)
    );
}
?>
</div>
<hr>
<?php endforeach; ?>

<div class="actions">
<form method="post">
<input type="hidden"
       name="action"
       value="answer_back">
<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">
<button>
回答を修正
</button>
</form>

<form method="post"
      onsubmit="return confirm('回答を送信しますか？')">
<input type="hidden"
       name="action"
       value="submit_answer">
<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">
<button class="primary">
回答を送信する
</button>
</form>
</div>
</div>
</div>
</body>
</html>
<?php
}

function render_complete(
    array $data,
    string $id
): void {
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>回答完了</title>
<style>
body{
 margin:0;
 background:#f8fafc;
 color:#1e293b;
 font-family:
 -apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",Meiryo,sans-serif;
}
.wrap{
 max-width:650px;
 margin:60px auto;
 padding:20px;
}
.card{
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:14px;
 padding:35px;
 text-align:center;
}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
<h1>回答ありがとうございました</h1>
<p>
アンケートの回答を正常に受け付けました。
</p>
</div>
</div>
</body>
</html>
<?php
}

function render_answer_message(
    string $message
): void {
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>アンケート</title>
<style>
body{
 margin:0;
 background:#f8fafc;
 font-family:
 -apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",Meiryo,sans-serif;
}
.wrap{
 max-width:650px;
 margin:60px auto;
 padding:20px;
}
.card{
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:14px;
 padding:30px;
 text-align:center;
}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
<h1>アンケート</h1>
<p><?= nl2br(
    h($message)
) ?></p>
</div>
</div>
</body>
</html>
<?php
}


/* =========================================================
 * エラー画面
 * ========================================================= */

function render_error(
    string $message
): void {
    admin_header(
        'エラー'
    );
?>
<div class="card">
<h1>エラー</h1>
<p><?= nl2br(
    h($message)
) ?></p>

<a class="btn"
   href="<?= h(
       app_url(['screen'=>'list'])
   ) ?>">
アンケート一覧へ
</a>
</div>
<?php
    admin_footer();
}


/* =========================================================
 * 起動
 *
 * ここにはcaseを書かない。
 * caseはhandle_post()内のswitchだけ。
 * ========================================================= */

try {
    if (!is_dir(DATA_DIR)) {
        if (
            !mkdir(
                DATA_DIR,
                0775,
                true
            ) &&
            !is_dir(DATA_DIR)
        ) {
            throw new RuntimeException(
                'データ保存フォルダを作成できません。'
            );
        }
    }

    start_session();

    if (!is_file(DATA_FILE)) {
        save_json(
            DATA_FILE,
            default_data()
        );
    }

    if (!is_file(SET_FILE)) {
        save_json(
            SET_FILE,
            default_settings()
        );
    }

    $data =
        load_data();

    $settings =
        load_settings();

    /*
     * 設定ファイルの暗号化されたパスワードを
     * 実行時だけ復号する。
     */
    try {
        $settings =
            prepare_settings_for_runtime(
                $settings
            );
    } catch (Throwable $e) {
        /*
         * APP_ENCRYPTION_KEYがない場合、
         * kintone/SMTP以外の画面まで白画面にしない。
         *
         * 外部サービスを利用する操作時に
         * 明示的なエラーとして扱う。
         */
        $_SESSION[
            'settings_encryption_error'
        ] = safe_external_error($e);
    }

    refresh_status($data);

    $route = [
        'screen' =>
            get_string('screen') !== ''
                ? get_string('screen')
                : 'list',
        'id' =>
            get_string('id'),
    ];

    /*
     * POST処理はここで実行。
     *
     * handle_post()は結果としてscreen/idを返すだけ。
     * header("Location")は行わない。
     */
    if (
        $_SERVER['REQUEST_METHOD'] ===
        'POST'
    ) {
        $route =
            handle_post(
                $data,
                $settings
            );
    }

    $screen =
        (string)(
            $route['screen']
            ?? 'list'
        );

    $id =
        (string)(
            $route['id']
            ?? ''
        );

    /*
     * 回答者画面。
     *
     * 管理者画面へ自動遷移させない。
     */
    if (
        $screen === 'answer'
    ) {
        render_answer(
            $data,
            $id
        );
        exit;
    }

    if (
        $screen === 'answer_confirm'
    ) {
        render_answer_confirm(
            $data,
            $id
        );
        exit;
    }

    if (
        $screen === 'complete'
    ) {
        render_complete(
            $data,
            $id
        );
        exit;
    }

    if (
        $screen === 'answer_message'
    ) {
        render_answer_message(
            'アンケートを表示できません。'
        );
        exit;
    }

    /*
     * 管理者側の画面分岐。
     *
     * screenが不正でも白画面にしない。
     */
    switch ($screen) {

        case 'list':
            render_list(
                $data
            );
            break;

        case 'edit':
            render_edit(
                $data,
                $id !== ''
                    ? $id
                    : null
            );
            break;

        case 'send':
            if ($id === '') {
                render_error(
                    '送信対象のアンケートIDが指定されていません。'
                );
                break;
            }

            render_send(
                $data,
                $id
            );
            break;

        case 'analytics':
            if ($id === '') {
                render_error(
                    '集計対象のアンケートIDが指定されていません。'
                );
                break;
            }

            render_analytics(
                $data,
                $id
            );
            break;

        case 'customers':
            render_customers(
                $data
            );
            break;

        case 'kintone':
            render_kintone(
                $settings
            );
            break;

        case 'mail':
            render_mail(
                $settings
            );
            break;

        default:
            render_error(
                '指定された画面は存在しません。'
            );
            break;
    }
} catch (Throwable $e) {
    /*
     * 最終防衛線。
     *
     * Parse Errorはこのtryでは捕捉できないが、
     * 実行時例外で白画面にならないようにする。
     */
    http_response_code(500);

    $message =
        safe_external_error($e);

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>システムエラー</title>
<style>
body{
 margin:0;
 padding:30px;
 background:#f8fafc;
 color:#1e293b;
 font-family:
 -apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",Meiryo,sans-serif;
}
.card{
 max-width:800px;
 margin:auto;
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:14px;
 padding:25px;
}
.error{
 white-space:pre-line;
 color:#991b1b;
}
</style>
</head>
<body>
<div class="card">
<h1>システムエラー</h1>
<p class="error"><?= nl2br(
    h($message)
) ?></p>
<p>
設定値や認証情報そのものは表示していません。
</p>
</div>
</body>
</html>
<?php
}
